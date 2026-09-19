#!/usr/bin/env bash

set -uo pipefail

MIN_DOCKER_VERSION="29.5.1"
MIN_RUNC_VERSION="1.3.6"
MIN_CONTAINERD_VERSION="2.2.0"

usage() {
  cat <<'EOF'
Usage: sudo ./scripts/external-host-preflight.sh [options]

Required evidence options:
  --expected-docker-admins LIST
  --host-patch-date YYYY-MM-DD
  --operator-network CIDR
  --encrypted-storage-evidence TEXT
  --disk-monitor-evidence TEXT
  --backup-target TEXT
  --backup-verified-at YYYY-MM-DD
  --acceptance-run-id TEXT

Optional:
  --evidence-dir DIR
  --self-test
  --help

Run this fail-closed collector as root on the candidate Ubuntu 24.04 amd64
host after Docker and the operator, firewall, monitoring, and backup controls
are configured. A pass is host evidence, not deployment authorization.
EOF
}

version_ge() {
  local actual="${1#v}"
  local required="${2#v}"
  [[ "$(printf '%s\n%s\n' "$required" "$actual" | sort -V | head -n 1)" == "$required" ]]
}

extract_version() {
  grep -Eo 'v?[0-9]+\.[0-9]+\.[0-9]+' | head -n 1 | sed 's/^v//'
}

if [[ "${1:-}" == "--self-test" ]]; then
  version_ge 29.5.1 29.5.1
  version_ge 29.6.0 29.5.1
  version_ge 30.0.0 29.5.1
  ! version_ge 29.5.0 29.5.1
  ! version_ge 1.3.5 1.3.6
  [[ "$(printf '%s\n' 'runc version 1.3.6' | extract_version)" == "1.3.6" ]]
  [[ "$(printf '%s\n' 'containerd github.com/containerd/containerd/v2 v2.2.1' | extract_version)" == "2.2.1" ]]
  echo "external host preflight self-test passed"
  exit 0
fi

evidence_dir=""
expected_docker_admins=""
host_patch_date=""
operator_network=""
encrypted_storage_evidence=""
disk_monitor_evidence=""
backup_target=""
backup_verified_at=""
acceptance_run_id=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --evidence-dir) evidence_dir="${2:-}"; shift 2 ;;
    --expected-docker-admins) expected_docker_admins="${2:-}"; shift 2 ;;
    --host-patch-date) host_patch_date="${2:-}"; shift 2 ;;
    --operator-network) operator_network="${2:-}"; shift 2 ;;
    --encrypted-storage-evidence) encrypted_storage_evidence="${2:-}"; shift 2 ;;
    --disk-monitor-evidence) disk_monitor_evidence="${2:-}"; shift 2 ;;
    --backup-target) backup_target="${2:-}"; shift 2 ;;
    --backup-verified-at) backup_verified_at="${2:-}"; shift 2 ;;
    --acceptance-run-id) acceptance_run_id="${2:-}"; shift 2 ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this preflight as root so firewall and package evidence is complete." >&2
  exit 2
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
evidence_dir="${evidence_dir:-./host-preflight-evidence-${timestamp}}"
mkdir -p "$evidence_dir/commands"
checks_file="$evidence_dir/checks.tsv"
facts_file="$evidence_dir/operator-facts.txt"
: > "$checks_file"
failures=0

pass() { printf 'PASS\t%s\t%s\n' "$1" "$2" | tee -a "$checks_file"; }
fail() {
  printf 'FAIL\t%s\t%s\n' "$1" "$2" | tee -a "$checks_file" >&2
  failures=$((failures + 1))
}
require_value() {
  if [[ -n "$2" ]]; then pass "$1" "operator evidence supplied"; else fail "$1" "required option missing"; fi
}
capture() {
  local file="$1"
  shift
  "$@" > "$evidence_dir/commands/$file" 2>&1
}

{
  printf 'captured_at_utc=%s\n' "$timestamp"
  printf 'minimum_docker=%s\nminimum_runc=%s\nminimum_containerd=%s\n' \
    "$MIN_DOCKER_VERSION" "$MIN_RUNC_VERSION" "$MIN_CONTAINERD_VERSION"
  printf 'docker_floor_basis=required_security_fixes\nrunc_floor_basis=required_security_fix\n'
  printf 'containerd_floor_basis=upstream_support_lifecycle\ncompose_requirement=maintained_v2_interface\n'
  printf 'expected_docker_admins=%s\nhost_patch_date=%s\noperator_network=%s\n' \
    "$expected_docker_admins" "$host_patch_date" "$operator_network"
  printf 'encrypted_storage_evidence=%s\ndisk_monitor_evidence=%s\nbackup_target=%s\n' \
    "$encrypted_storage_evidence" "$disk_monitor_evidence" "$backup_target"
  printf 'backup_verified_at=%s\nacceptance_run_id=%s\n' \
    "$backup_verified_at" "$acceptance_run_id"
} > "$facts_file"

capture uname.txt uname -a || true
capture os-release.txt cat /etc/os-release || true
capture docker-version.txt docker version || true
capture docker-info.txt docker info || true
capture compose-version.txt docker compose version || true
capture runc-version.txt runc --version || true
capture containerd-version.txt containerd --version || true
capture apt-policy.txt apt-cache policy docker-ce containerd.io containerd runc || true
capture listening-ports.txt ss -H -lntup || true
capture docker-user-chain.txt iptables -S DOCKER-USER || true
capture firewall-iptables.txt iptables-save || true
capture firewall-nft.txt nft list ruleset || true
capture docker-group.txt getent group docker || true
capture time-sync.txt timedatectl show -p NTPSynchronized --value || true
capture disk-layout.txt lsblk -o NAME,TYPE,FSTYPE,MOUNTPOINTS || true

arch="$(uname -m 2>/dev/null || true)"
if [[ "$arch" == "x86_64" || "$arch" == "amd64" ]]; then pass architecture "$arch"; else fail architecture "expected amd64/x86_64, got ${arch:-unavailable}"; fi

os_id="$(sed -n 's/^ID=//p' /etc/os-release 2>/dev/null | tr -d '"' | head -n 1)"
os_version="$(sed -n 's/^VERSION_ID=//p' /etc/os-release 2>/dev/null | tr -d '"' | head -n 1)"
if [[ "$os_id" == ubuntu && "$os_version" == 24.04 ]]; then pass operating_system "$os_id $os_version"; else fail operating_system "expected ubuntu 24.04, got ${os_id:-unknown} ${os_version:-unknown}"; fi

kernel="$(uname -r 2>/dev/null || true)"
if [[ "$kernel" =~ -(generic|azure|aws|gcp)$ ]]; then pass vendor_kernel "$kernel"; else fail vendor_kernel "Ubuntu vendor kernel flavor not recognized: ${kernel:-unavailable}"; fi

docker_version="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
if [[ -n "$docker_version" ]] && version_ge "$docker_version" "$MIN_DOCKER_VERSION"; then pass docker_engine "$docker_version"; else fail docker_engine "requires >=$MIN_DOCKER_VERSION; observed ${docker_version:-unavailable}"; fi

compose_version="$(docker compose version --short 2>/dev/null | extract_version || true)"
if [[ -n "$compose_version" && "${compose_version%%.*}" == 2 ]] && version_ge "$compose_version" 2.0.0; then pass docker_compose "$compose_version"; else fail docker_compose "maintained Compose v2 required; observed ${compose_version:-unavailable}"; fi

runc_version="$(runc --version 2>/dev/null | extract_version || true)"
if [[ -n "$runc_version" ]] && version_ge "$runc_version" "$MIN_RUNC_VERSION"; then pass runc "$runc_version"; else fail runc "requires >=$MIN_RUNC_VERSION; observed ${runc_version:-unavailable}"; fi

containerd_version="$(containerd --version 2>/dev/null | extract_version || true)"
if [[ -n "$containerd_version" && "${containerd_version%%.*}" == 2 ]] && version_ge "$containerd_version" "$MIN_CONTAINERD_VERSION"; then pass containerd "$containerd_version"; else fail containerd "requires maintained 2.x floor >=$MIN_CONTAINERD_VERSION; observed ${containerd_version:-unavailable}"; fi

official_packages=true
for package in docker-ce containerd.io; do
  if ! dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null | grep -q '^ii'; then
    official_packages=false
  fi
done
if $official_packages && grep -q 'download.docker.com' "$evidence_dir/commands/apt-policy.txt"; then pass docker_package_source "docker-ce and containerd.io are installed from the configured official repository"; else fail docker_package_source "docker-ce and containerd.io must both be installed from download.docker.com"; fi

conflicting_runtime=""
for package in containerd runc; do
  if dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null | grep -q '^ii'; then
    conflicting_runtime="${conflicting_runtime}${conflicting_runtime:+,}${package}"
  fi
done
if [[ -n "$conflicting_runtime" ]]; then fail conflicting_runtime_packages "standalone package(s) installed beside containerd.io: $conflicting_runtime"; else pass conflicting_runtime_packages "no standalone containerd/runc package detected"; fi

if grep -Eq '^-A DOCKER-USER .+-j (ACCEPT|DROP|REJECT)' "$evidence_dir/commands/docker-user-chain.txt"; then pass docker_user_policy "explicit ACCEPT/DROP/REJECT rule retained"; else fail docker_user_policy "DOCKER-USER needs an explicit operator/network policy, not only RETURN"; fi

if awk '$5 ~ /:(80|2375|2376|3306)$/ && $5 !~ /^(127\.0\.0\.1|\[::1\]):/ { found=1 } END { exit found ? 0 : 1 }' "$evidence_dir/commands/listening-ports.txt"; then fail exposed_ports "non-loopback HTTP, Docker API, or MySQL listener detected"; else pass exposed_ports "no non-loopback listeners on 80, 2375, 2376, or 3306"; fi

actual_admins="$(awk -F: '{print $4}' "$evidence_dir/commands/docker-group.txt" | tr ',' '\n' | sed '/^$/d' | sort -u | paste -sd, -)"
normalized_expected="$(printf '%s' "$expected_docker_admins" | tr ',' '\n' | sed 's/^ *//;s/ *$//;/^$/d' | sort -u | paste -sd, -)"
if [[ -n "$expected_docker_admins" && "$actual_admins" == "$normalized_expected" ]]; then pass docker_socket_admins "docker group matches declared administrators: ${actual_admins:-none}"; else fail docker_socket_admins "declared=${normalized_expected:-missing}; actual=${actual_admins:-none}"; fi

if grep -Eqi '^yes$' "$evidence_dir/commands/time-sync.txt"; then pass time_sync "NTP synchronized"; else fail time_sync "timedatectl did not report NTPSynchronized=yes"; fi

require_recent_date() {
  local check_name="$1" value="$2" max_age_days="$3" epoch now age
  if ! epoch="$(date -u -d "$value" +%s 2>/dev/null)"; then fail "$check_name" "missing or invalid date: ${value:-none}"; return; fi
  now="$(date -u +%s)"
  age=$(((now - epoch) / 86400))
  if (( age >= 0 && age <= max_age_days )); then pass "$check_name" "$value (${age} days old)"; else fail "$check_name" "$value is outside the ${max_age_days}-day evidence window"; fi
}

require_recent_date host_patch_date "$host_patch_date" 31
require_recent_date backup_restore_exercise "$backup_verified_at" 31
if [[ "$operator_network" =~ ^[0-9A-Fa-f:.]+/[0-9]{1,3}$ ]]; then pass operator_network "$operator_network"; else fail operator_network "a CIDR value is required"; fi
require_value encrypted_storage "$encrypted_storage_evidence"
require_value disk_monitoring "$disk_monitor_evidence"
require_value off_host_backup "$backup_target"
require_value acceptance_run "$acceptance_run_id"
if [[ -n "$backup_target" && "$backup_target" == /* ]]; then fail off_host_backup_scope "backup target appears local; an off-host encrypted target is required"; fi

printf 'result=%s\nfailures=%s\n' "$([[ $failures -eq 0 ]] && echo PASS || echo FAIL)" "$failures" > "$evidence_dir/result.txt"
manifest_tmp="$(mktemp)"
(cd "$evidence_dir" && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum) > "$manifest_tmp"
mv "$manifest_tmp" "$evidence_dir/SHA256SUMS"

if [[ $failures -ne 0 ]]; then echo "External host preflight FAILED with $failures finding(s). Evidence: $evidence_dir" >&2; exit 1; fi
echo "External host preflight PASSED. Evidence: $evidence_dir"
