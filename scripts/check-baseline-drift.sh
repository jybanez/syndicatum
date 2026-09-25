#!/usr/bin/env bash
set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
metadata_path="$repository_root/schema/mysql84/baseline.json"
schema_path="$repository_root/schema/mysql84/schema.sql"

for command_name in git php sha256sum awk sed wc tr; do
  command -v "$command_name" >/dev/null 2>&1 || {
    printf 'Required command is unavailable: %s\n' "$command_name" >&2
    exit 69
  }
done
test -f "$metadata_path"
test -f "$schema_path"

source_commit="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); if(!is_array($m)||empty($m["source_commit"])) exit(1); echo $m["source_commit"];' "$metadata_path")"
baseline_id="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["baseline_id"]??"";' "$metadata_path")"
application_version="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["application_version"]??"";' "$metadata_path")"
schema_head="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["schema_head"]??"";' "$metadata_path")"
cutover="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["migration_cutover"]??"";' "$metadata_path")"

git -C "$repository_root" cat-file -e "${source_commit}^{commit}"
declared_migrations="$(php -r '
$m=json_decode(file_get_contents($argv[1]),true);
foreach(($m["post_baseline_migrations"]??[]) as $entry){echo "migrations/".$entry["id"].".php\n";}
' "$metadata_path" | sort)"
changed_migrations="$(git -C "$repository_root" diff --name-only "$source_commit" -- migrations | sort)"
if [[ "$changed_migrations" != "$declared_migrations" ]]; then
  printf 'Migration changes since baseline source commit %s do not exactly match the declared post-baseline suffix.\n' "$source_commit" >&2
  printf 'Declared:\n%s\nObserved:\n%s\n' "$declared_migrations" "$changed_migrations" >&2
  exit 1
fi
php -r '
require $argv[2]."/src/PostBaselineMigrator.php";
$m=json_decode(file_get_contents($argv[1]),true);
foreach(($m["post_baseline_migrations"]??[]) as $entry){
  $path=$argv[2]."/migrations/".$entry["id"].".php";
  if(!is_file($path)||!hash_equals($entry["sha256"],PostBaselineMigrator::canonicalSha256($path))){
    fwrite(STDERR,"Declared post-baseline migration checksum mismatch: ".$entry["id"]."\n"); exit(1);
  }
}
' "$metadata_path" "$repository_root"

table_count="$(php -r '
$pdo=new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4",getenv("PBB_AGENTCHAT_DB_HOST"),getenv("PBB_AGENTCHAT_DB_NAME")),getenv("PBB_AGENTCHAT_DB_USER"),getenv("PBB_AGENTCHAT_DB_PASS"),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET GLOBAL log_bin_trust_function_creators = 1");
$pdo->exec("SET GLOBAL sql_mode = \"STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\"");
$pdo->exec("SET SESSION sql_mode = \"STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\"");
echo $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
')"
if [[ "$table_count" != "0" ]]; then
  printf 'Baseline drift check requires a proven-empty disposable database; found %s objects.\n' "$table_count" >&2
  exit 1
fi

schema_digest="$(sha256sum "$schema_path" | awk '{print $1}')"
metadata_schema_digest="$(php -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["schema_sha256"]??"";' "$metadata_path")"
if [[ "$schema_digest" != "$metadata_schema_digest" ]]; then
  printf 'Frozen baseline SQL digest does not match trusted metadata.\n' >&2
  exit 1
fi

printf 'baseline_source_commit=%s\n' "$source_commit"
printf 'schema_sha256=%s\n' "$schema_digest"
printf 'metadata_sha256=%s\n' "$(sha256sum "$metadata_path" | awk '{print $1}')"
printf 'immutable_cutover_schema_digest=matched\n'
printf 'declared_post_baseline_migrations=%s\n' "$(printf '%s\n' "$declared_migrations" | sed '/^$/d' | wc -l | tr -d ' ')"
