# DESIGN/REVIEW CANDIDATE. Do not run on a production host/profile.
# This script changes only a separately approved disposable guest's fixture data.
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$CliPath,
    [Parameter(Mandatory)][string]$GuestApprovalPath,
    [Parameter(Mandatory)][string]$IsolationEvidencePath,
    [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{64}$')][string]$GuestApprovalSha256,
    [Parameter(Mandatory)][string]$NewEvidenceDirectory
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$taskExpectedCliHash = 'f6dbf48cb70f62649c645f26c87dc9bf84cc36986202dfad8e12fb5b8ca70312'

function Stop-Test([string]$Code) { throw "STOP: $Code" }
function Get-Digest([string]$Path) { (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant() }
function Write-NewText([string]$Path, [string]$Text) {
    $taskStream = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $taskBytes = [Text.UTF8Encoding]::new($false).GetBytes($Text)
        $taskStream.Write($taskBytes, 0, $taskBytes.Length)
        $taskStream.Flush($true)
    } finally { $taskStream.Dispose() }
}
function Get-FixtureSnapshot([string]$Root) {
    @(Get-ChildItem -LiteralPath $Root -Recurse -Force -File | ForEach-Object {
        if ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-Test 'reparse fixture file' }
        [pscustomobject]@{ relative = [IO.Path]::GetRelativePath($Root, $_.FullName); size = $_.Length; sha256 = Get-Digest $_.FullName; mtime = $_.LastWriteTimeUtc.ToString('o') }
    })
}
function Assert-NetworkDenied {
    if (@(Get-NetAdapter -ErrorAction Stop | Where-Object Status -eq 'Up').Count) { Stop-Test 'active network adapter' }
    if (@(Get-NetRoute -ErrorAction Stop | Where-Object { $_.DestinationPrefix -in @('0.0.0.0/0','::/0') }).Count) { Stop-Test 'default route present' }
    # Absence of active adapters/routes complements, but never replaces, the
    # independently approved hypervisor/guest network-disable evidence.
}
function Invoke-FixtureCli([string]$Case, [string[]]$Arguments) {
    Assert-NetworkDenied
    Write-NewText (Join-Path $taskEvidence "$Case-before.json") ((Get-FixtureSnapshot $taskFixtureRoot) | ConvertTo-Json -Depth 8)
    $taskStart = [Diagnostics.ProcessStartInfo]::new()
    $taskStart.FileName = $taskCli.FullName
    foreach ($taskArgument in $Arguments) { $taskStart.ArgumentList.Add($taskArgument) }
    $taskStart.WorkingDirectory = $taskFixtureRoot
    $taskStart.UseShellExecute = $false
    $taskStart.CreateNoWindow = $true
    $taskStart.RedirectStandardOutput = $true
    $taskStart.RedirectStandardError = $true
    foreach ($taskName in @('CODEX_HOME','HOME','USERPROFILE','APPDATA','LOCALAPPDATA','XDG_CONFIG_HOME')) {
        $taskStart.Environment[$taskName] = $taskProfile
    }
    foreach ($taskName in @('OPENAI_API_KEY','CODEX_API_KEY','HTTP_PROXY','HTTPS_PROXY','ALL_PROXY','GIT_ASKPASS','SSH_AUTH_SOCK')) {
        $taskStart.Environment.Remove($taskName) | Out-Null
    }
    $taskProcess = [Diagnostics.Process]::new()
    $taskProcess.StartInfo = $taskStart
    if (-not $taskProcess.Start()) { Stop-Test 'fixture process start failed' }
    $taskOut = $taskProcess.StandardOutput.ReadToEndAsync()
    $taskErr = $taskProcess.StandardError.ReadToEndAsync()
    if (-not $taskProcess.WaitForExit(60000)) {
        # No broad kill/retry/cleanup. Preserve PID for explicit operator stop.
        Write-NewText (Join-Path $taskEvidence "$Case-uncertain.json") (@{ pid = $taskProcess.Id; outcome = 'timeout-no-retry' } | ConvertTo-Json)
        Stop-Test 'fixture command timeout; operator intervention required'
    }
    $taskOutput = $taskOut.GetAwaiter().GetResult()
    $taskError = $taskErr.GetAwaiter().GetResult()
    # Guest-only raw output is private. Sanitized summaries should be reviewed
    # before sharing. Do not clip the only raw evidence copy.
    Write-NewText (Join-Path $taskEvidence "$Case-stdout.private.txt") $taskOutput
    Write-NewText (Join-Path $taskEvidence "$Case-stderr.private.txt") $taskError
    Write-NewText (Join-Path $taskEvidence "$Case-result.json") (@{ exit_code = $taskProcess.ExitCode; stdout_sha256 = Get-Digest (Join-Path $taskEvidence "$Case-stdout.private.txt"); stderr_sha256 = Get-Digest (Join-Path $taskEvidence "$Case-stderr.private.txt") } | ConvertTo-Json)
    Write-NewText (Join-Path $taskEvidence "$Case-after.json") ((Get-FixtureSnapshot $taskFixtureRoot) | ConvertTo-Json -Depth 8)
    Assert-NetworkDenied
    return $taskProcess.ExitCode
}

# Admission occurs before fixture creation or CLI execution.
if ((Get-Digest $GuestApprovalPath) -cne $GuestApprovalSha256) { Stop-Test 'guest approval digest mismatch' }
$taskApproval = Get-Content -LiteralPath $GuestApprovalPath -Raw | ConvertFrom-Json -AsHashtable
if (@($taskApproval.Keys | Sort-Object) -join ',' -cne 'guest_uuid,isolation_evidence_sha256,network_disabled,no_production_access,owner_message_id') { Stop-Test 'guest approval schema' }
if ($taskApproval.network_disabled -isnot [bool] -or -not $taskApproval.network_disabled -or
    $taskApproval.no_production_access -isnot [bool] -or -not $taskApproval.no_production_access -or
    $taskApproval.isolation_evidence_sha256 -notmatch '^[0-9a-f]{64}$') { Stop-Test 'guest isolation not approved' }
if ((Get-Digest $IsolationEvidencePath) -cne $taskApproval.isolation_evidence_sha256) { Stop-Test 'independent isolation evidence mismatch' }
if ($taskApproval.owner_message_id -isnot [long] -and $taskApproval.owner_message_id -isnot [int]) { Stop-Test 'owner authority identity type' }
if ($taskApproval.owner_message_id -le 0) { Stop-Test 'owner authority identity missing' }
if ($taskApproval.guest_uuid -cne (Get-CimInstance Win32_ComputerSystemProduct).UUID) { Stop-Test 'wrong designated guest' }
$taskCli = Get-Item -LiteralPath $CliPath -Force
if ($taskCli.PSIsContainer -or ($taskCli.Attributes -band [IO.FileAttributes]::ReparsePoint) -or (Get-Digest $taskCli.FullName) -cne $taskExpectedCliHash) { Stop-Test 'CLI identity mismatch' }
Assert-NetworkDenied
if (Test-Path -LiteralPath $NewEvidenceDirectory) { Stop-Test 'attempt already exists' }
$taskEvidence = (New-Item -ItemType Directory -Path $NewEvidenceDirectory).FullName
Write-NewText (Join-Path $taskEvidence 'attempt.json') (@{ owner_message_id = $taskApproval.owner_message_id; guest_approval_sha256 = $GuestApprovalSha256; cli_sha256 = $taskExpectedCliHash; state = 'reserved-no-replay' } | ConvertTo-Json)
Copy-Item -LiteralPath $GuestApprovalPath -Destination (Join-Path $taskEvidence 'guest-approval.private.json')
Copy-Item -LiteralPath $IsolationEvidencePath -Destination (Join-Path $taskEvidence 'isolation.private.evidence')
$taskFixtureRoot = (New-Item -ItemType Directory -Path (Join-Path $taskEvidence 'fixture')).FullName
$taskProfile = (New-Item -ItemType Directory -Path (Join-Path $taskFixtureRoot 'profile')).FullName
$taskMarket = (New-Item -ItemType Directory -Path (Join-Path $taskFixtureRoot 'source')).FullName
$taskCatalog = (New-Item -ItemType Directory -Path (Join-Path $taskMarket '.agents/plugins') -Force).FullName
$taskPlugin = (New-Item -ItemType Directory -Path (Join-Path $taskMarket 'plugins/pilot-fixture/.codex-plugin') -Force).FullName
$taskPluginRoot = Split-Path $taskPlugin -Parent
Write-NewText (Join-Path $taskCatalog 'marketplace.json') '{"name":"pilot-fixture-market","plugins":[{"name":"pilot-fixture","source":{"source":"local","path":"./plugins/pilot-fixture"},"policy":{"installation":"AVAILABLE","authentication":"ON_USE"},"category":"Productivity"}]}'
Write-NewText (Join-Path $taskPlugin 'plugin.json') '{"name":"pilot-fixture","version":"1.0.0","description":"Inert disposable byte fixture"}'
Write-NewText (Join-Path $taskPluginRoot 'marker-one.txt') 'fixture-one'

if ((Invoke-FixtureCli '00-version' @('--version')) -ne 0) { Stop-Test 'version command failed' }
# Version text is separately retained; hash is the primary binary identity gate.
if ((Invoke-FixtureCli '01-local-add' @('plugin','marketplace','add',$taskMarket,'--json')) -ne 0) { Stop-Test 'initial marketplace add failed' }
if ((Invoke-FixtureCli '02-same-source' @('plugin','marketplace','add',$taskMarket,'--json')) -ne 0) { Stop-Test 'same-source add failed' }
Write-NewText (Join-Path $taskPluginRoot 'marker-two.txt') 'fixture-two'
if ((Invoke-FixtureCli '03-changed-source' @('plugin','marketplace','add',$taskMarket,'--json')) -ne 0) { Stop-Test 'changed-source add failed' }
if ((Invoke-FixtureCli '04-plugin-add' @('plugin','add','pilot-fixture@pilot-fixture-market','--json')) -ne 0) { Stop-Test 'plugin add failed' }
if ((Invoke-FixtureCli '05-plugin-repeat' @('plugin','add','pilot-fixture@pilot-fixture-market','--json')) -ne 0) { Stop-Test 'plugin repeat failed' }
Write-NewText (Join-Path $taskPluginRoot 'marker-three.txt') 'fixture-three'
if ((Invoke-FixtureCli '06-plugin-changed' @('plugin','add','pilot-fixture@pilot-fixture-market','--json')) -ne 0) { Stop-Test 'changed plugin add failed' }
Write-NewText (Join-Path $taskPluginRoot 'marker-four-no-update.txt') 'fixture-four'
Write-NewText (Join-Path $taskEvidence '07-source-change-no-update.json') ((Get-FixtureSnapshot $taskFixtureRoot) | ConvertTo-Json -Depth 8)
$taskOther = (New-Item -ItemType Directory -Path (Join-Path $taskFixtureRoot 'other-source')).FullName
Copy-Item -LiteralPath (Join-Path $taskMarket '.agents') -Destination $taskOther -Recurse
Copy-Item -LiteralPath (Join-Path $taskMarket 'plugins') -Destination $taskOther -Recurse
$taskConflictCode = Invoke-FixtureCli '08-different-source-conflict' @('plugin','marketplace','add',$taskOther,'--json')
if ($taskConflictCode -eq 0) { Stop-Test 'expected different-source conflict not observed' }
Write-NewText (Join-Path $taskEvidence 'complete.json') '{"scope":"disposable-semantics-only","cleanup_authorized":false,"live_migration_authorized":false}'
