[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Inspect', 'RejectFixture', 'Apply')]
    [string] $Mode,

    [Parameter(Mandatory = $true)]
    [ValidateSet('0.2.6', '0.2.7', '0.2.8', '0.2.9')]
    [string] $TargetVersion,

    [Parameter(Mandatory = $true)]
    [string] $BackupManifestPath,

    [Parameter(Mandatory = $true)]
    [string] $ReportPath,

    [string] $CodexRoot = (Join-Path $env:USERPROFILE '.codex')
)

$ErrorActionPreference = 'Stop'

$release = @{
    '0.2.6' = @{
        Ref = 'codex-v0.2.6'
        Revision = 'c2ed0247fd61dc51bfa8515b1b3882f6a440aa1c'
    }
    '0.2.7' = @{
        Ref = 'codex-v0.2.7'
        Revision = '68c586b17628b387f08e42da3f17199f0f8824e7'
    }
    '0.2.8' = @{
        Ref = 'codex-v0.2.8'
        Revision = '4e6e981be031e61c4f52fb87e0ead64bf43b90d9'
    }
    '0.2.9' = @{
        Ref = 'codex-v0.2.9'
        Revision = '4e9364fc8df4368e52a8b3607bd257d0f36219f9'
    }
}

$marketplaceRoot = Join-Path $CodexRoot '.tmp\marketplaces\syndicatum'
$managedMetadata = Join-Path $marketplaceRoot '.codex-marketplace-install.json'
$pluginCacheRoot = Join-Path $CodexRoot 'plugins\cache\syndicatum\codex'
$pluginDataRoot = Join-Path $env:USERPROFILE '.syndicatum\codex-plugin'
$globalState = Join-Path $CodexRoot '.codex-global-state.json'
$globalStateBackup = Join-Path $CodexRoot '.codex-global-state.json.bak'
$expected = $release[$TargetVersion]

function Get-TreeFingerprint([string] $Root) {
    if (-not (Test-Path -LiteralPath $Root)) {
        return [pscustomobject]@{ exists = $false; files = 0; sha256 = $null }
    }

    $rows = Get-ChildItem -LiteralPath $Root -File -Recurse -Force |
        Sort-Object FullName |
        ForEach-Object {
            $relative = $_.FullName.Substring($Root.Length).TrimStart('\').Replace('\', '/')
            "$relative $($_.Length) $((Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant())"
        }
    $joined = [Text.Encoding]::UTF8.GetBytes(($rows -join "`n"))
    $hash = [Security.Cryptography.SHA256]::HashData($joined)
    [pscustomobject]@{
        exists = $true
        files = @($rows).Count
        sha256 = [Convert]::ToHexString($hash).ToLowerInvariant()
    }
}

function Get-FileFingerprint([string] $Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return [pscustomobject]@{ exists = $false; bytes = 0; sha256 = $null }
    }
    $item = Get-Item -LiteralPath $Path
    [pscustomobject]@{
        exists = $true
        bytes = $item.Length
        sha256 = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
    }
}

function Get-StablePluginDataFingerprint {
    $paths = @(
        (Join-Path $pluginDataRoot 'agent-identities'),
        (Join-Path $pluginDataRoot 'agent-profile-aliases.json'),
        (Join-Path $pluginDataRoot 'connector.config.json'),
        (Join-Path $pluginDataRoot 'credential')
    )
    $rows = @()
    foreach ($path in $paths) {
        if (-not (Test-Path -LiteralPath $path)) { continue }
        if (Test-Path -LiteralPath $path -PathType Container) {
            $rows += Get-ChildItem -LiteralPath $path -File -Recurse -Force |
                Sort-Object FullName |
                ForEach-Object {
                    $relative = $_.FullName.Substring($pluginDataRoot.Length).TrimStart('\').Replace('\', '/')
                    "$relative $($_.Length) $((Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant())"
                }
        } else {
            $item = Get-Item -LiteralPath $path
            $relative = $item.FullName.Substring($pluginDataRoot.Length).TrimStart('\').Replace('\', '/')
            $rows += "$relative $($item.Length) $((Get-FileHash -LiteralPath $item.FullName -Algorithm SHA256).Hash.ToLowerInvariant())"
        }
    }
    $rows = @($rows | Sort-Object)
    $joined = [Text.Encoding]::UTF8.GetBytes(($rows -join "`n"))
    $hash = [Security.Cryptography.SHA256]::HashData($joined)
    [pscustomobject]@{
        exists = (Test-Path -LiteralPath $pluginDataRoot -PathType Container)
        files = $rows.Count
        sha256 = [Convert]::ToHexString($hash).ToLowerInvariant()
    }
}

function Get-IntegritySnapshot {
    [ordered]@{
        marketplace = Get-TreeFingerprint $marketplaceRoot
        plugin_cache = Get-TreeFingerprint $pluginCacheRoot
        plugin_data_stable = Get-StablePluginDataFingerprint
        global_state = Get-FileFingerprint $globalState
        global_state_backup = Get-FileFingerprint $globalStateBackup
    }
}

function Assert-BackupManifest {
    $manifest = [IO.Path]::GetFullPath($BackupManifestPath)
    $report = [IO.Path]::GetFullPath($ReportPath)
    foreach ($managedRoot in @($marketplaceRoot, $pluginCacheRoot, $pluginDataRoot)) {
        if ($manifest.StartsWith([IO.Path]::GetFullPath($managedRoot), [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Backup manifest must be outside the marketplace, plugin cache, and plugin data roots.'
        }
    }
    if (-not (Test-Path -LiteralPath $manifest -PathType Leaf)) {
        throw 'Verified backup manifest is missing.'
    }
    if ($report.StartsWith([IO.Path]::GetFullPath($marketplaceRoot), [StringComparison]::OrdinalIgnoreCase) -or
        $report.StartsWith([IO.Path]::GetFullPath($pluginCacheRoot), [StringComparison]::OrdinalIgnoreCase) -or
        $report.StartsWith([IO.Path]::GetFullPath($pluginDataRoot), [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Report path must be outside the managed roots.'
    }
    return (Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Assert-ManagedStateQuiescent {
    $desktop = @(Get-Process -Name Codex -ErrorAction SilentlyContinue)
    if ($desktop.Count -gt 0) {
        throw 'Codex Desktop is still running. Fully exit it before RejectFixture or Apply.'
    }

    $managedRoots = @($marketplaceRoot, $pluginCacheRoot) |
        ForEach-Object { [IO.Path]::GetFullPath($_).TrimEnd('\').ToLowerInvariant() }
    try {
        $referencingProcesses = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object {
            if ($_.ProcessId -eq $PID -or [string]::IsNullOrWhiteSpace($_.CommandLine)) {
                return $false
            }
            $commandLine = $_.CommandLine.Replace('/', '\').ToLowerInvariant()
            foreach ($root in $managedRoots) {
                if ($commandLine.Contains($root)) { return $true }
            }
            return $false
        })
    } catch {
        throw "Unable to prove managed-state process quiescence: $($_.Exception.Message)"
    }
    if ($referencingProcesses.Count -gt 0) {
        $summary = @($referencingProcesses | ForEach-Object { "$($_.Name) (PID $($_.ProcessId))" }) -join ', '
        throw "A process still references the managed marketplace or plugin cache: $summary"
    }

    $locked = @()
    foreach ($root in @($marketplaceRoot, $pluginCacheRoot)) {
        if (-not (Test-Path -LiteralPath $root -PathType Container)) { continue }
        foreach ($file in Get-ChildItem -LiteralPath $root -File -Recurse -Force -ErrorAction Stop) {
            $stream = $null
            try {
                $stream = [IO.File]::Open($file.FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::None)
            } catch {
                $locked += $file.FullName
            } finally {
                if ($null -ne $stream) { $stream.Dispose() }
            }
        }
    }
    if ($locked.Count -gt 0) {
        $sample = @($locked | Select-Object -First 3) -join '; '
        throw "Managed marketplace or plugin-cache files are not quiescent (locked files: $sample)."
    }
}

function Read-ManagedMetadata {
    if (-not (Test-Path -LiteralPath $managedMetadata -PathType Leaf)) {
        throw 'Managed marketplace metadata is missing.'
    }
    $metadata = Get-Content -Raw -LiteralPath $managedMetadata | ConvertFrom-Json
    if ($metadata.source_type -ne 'git' -or $metadata.source -ne 'https://github.com/jybanez/syndicatum.git') {
        throw 'Managed marketplace metadata has an unexpected source.'
    }
    $sparse = @($metadata.sparse_paths)
    $isFullCheckout = $sparse.Count -eq 0
    $isCanonicalSparseCheckout = $sparse.Count -eq 2 -and
        $sparse[0] -eq '.agents/plugins' -and $sparse[1] -eq 'plugins/codex'
    if (-not $isFullCheckout -and -not $isCanonicalSparseCheckout) {
        throw 'Managed marketplace metadata has an unexpected sparse-path set.'
    }
    $metadata | Add-Member -NotePropertyName validated_checkout_mode -NotePropertyValue $(
        if ($isFullCheckout) { 'full' } else { 'canonical_sparse' }
    ) -Force
    return $metadata
}

function Assert-MarketplaceState([bool] $RequireTarget) {
    $status = @(& git -C $marketplaceRoot status --porcelain=v1 --untracked-files=all)
    if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the marketplace checkout.' }
    $unexpected = @($status | Where-Object { $_ -ne '?? .codex-marketplace-install.json' })
    if ($unexpected.Count -gt 0) {
        throw 'Marketplace checkout has tracked changes or unexpected untracked files.'
    }
    $metadata = Read-ManagedMetadata
    $head = (& git -C $marketplaceRoot rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0) { throw 'Unable to resolve marketplace HEAD.' }
    if ($metadata.revision -ne $head) {
        throw 'Managed metadata revision does not match marketplace HEAD.'
    }
    $knownVersion = @($release.Keys | Where-Object {
        $release[$_].Ref -eq $metadata.ref_name -and $release[$_].Revision -eq $head
    })
    if ($knownVersion.Count -ne 1) {
        throw 'Managed marketplace metadata does not identify a reviewed immutable release.'
    }
    if ($RequireTarget -and ($head -ne $expected.Revision -or $metadata.ref_name -ne $expected.Ref)) {
        throw "Marketplace is not at the requested immutable target $($expected.Ref)."
    }
    [pscustomobject]@{
        head = $head
        ref = $metadata.ref_name
        checkout_mode = $metadata.validated_checkout_mode
        managed_metadata_only = ($status.Count -eq 1)
    }
}

function Assert-RemoteCandidate {
    $peeled = @(& git ls-remote 'https://github.com/jybanez/syndicatum.git' "refs/tags/$($expected.Ref)^{}")
    if ($LASTEXITCODE -ne 0 -or $peeled.Count -ne 1) {
        throw "Unable to resolve immutable tag $($expected.Ref)."
    }
    $revision = ($peeled[0] -split '\s+')[0]
    if ($revision -ne $expected.Revision) {
        throw "Candidate revision mismatch for $($expected.Ref)."
    }
}

function Write-Report([hashtable] $Report) {
    $parent = Split-Path -Parent ([IO.Path]::GetFullPath($ReportPath))
    if (-not (Test-Path -LiteralPath $parent)) {
        New-Item -ItemType Directory -Path $parent -Force | Out-Null
    }
    $Report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $ReportPath -Encoding utf8NoBOM
}

$backupManifestHash = Assert-BackupManifest
$initialMarketplace = Assert-MarketplaceState $false
$before = Get-IntegritySnapshot
$startedAt = (Get-Date).ToUniversalTime().ToString('o')

if ($Mode -eq 'Inspect') {
    Write-Report ([ordered]@{
        schema_version = 1
        mode = $Mode
        result = 'inspection_passed'
        target_version = $TargetVersion
        expected_ref = $expected.Ref
        expected_revision = $expected.Revision
        current_marketplace = $initialMarketplace
        backup_manifest_sha256 = $backupManifestHash
        started_at = $startedAt
        ended_at = (Get-Date).ToUniversalTime().ToString('o')
        mutation_occurred = $false
        integrity = $before
    })
    exit 0
}

Assert-ManagedStateQuiescent

if ($Mode -eq 'RejectFixture') {
    $rejected = $false
    try {
        $invalidRevision = '0000000000000000000000000000000000000000'
        if ($invalidRevision -ne $expected.Revision) {
            throw 'Deterministic candidate-revision mismatch.'
        }
    } catch {
        if ($_.Exception.Message -ne 'Deterministic candidate-revision mismatch.') { throw }
        $rejected = $true
    }
    $after = Get-IntegritySnapshot
    if (-not $rejected -or (($before | ConvertTo-Json -Depth 8) -ne ($after | ConvertTo-Json -Depth 8))) {
        throw 'Rejected-candidate fixture did not fail closed without mutation.'
    }
    Write-Report ([ordered]@{
        schema_version = 1
        mode = $Mode
        result = 'rejected_before_mutation'
        target_version = $TargetVersion
        expected_ref = $expected.Ref
        expected_revision = $expected.Revision
        backup_manifest_sha256 = $backupManifestHash
        started_at = $startedAt
        ended_at = (Get-Date).ToUniversalTime().ToString('o')
        mutation_occurred = $false
        integrity_before = $before
        integrity_after = $after
    })
    exit 0
}

Assert-RemoteCandidate

& codex plugin marketplace remove syndicatum
if ($LASTEXITCODE -ne 0) { throw 'Marketplace removal failed; stop without replay.' }
& codex plugin marketplace add jybanez/syndicatum --ref $expected.Ref
if ($LASTEXITCODE -ne 0) { throw 'Marketplace add failed; stop without replay and use verified preimages.' }
& codex plugin add codex@syndicatum
if ($LASTEXITCODE -ne 0) { throw 'Plugin install failed; stop without replay and use verified preimages.' }

$finalMarketplace = Assert-MarketplaceState $true
$manifestPath = Join-Path $pluginCacheRoot "$TargetVersion\.codex-plugin\plugin.json"
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Installed plugin manifest is missing after transition.'
}
$installed = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
if ($installed.version -ne $TargetVersion) {
    throw 'Installed plugin manifest version does not match the requested target.'
}
$after = Get-IntegritySnapshot
if (($before.plugin_data_stable | ConvertTo-Json -Depth 4) -ne
    ($after.plugin_data_stable | ConvertTo-Json -Depth 4)) {
    throw 'Protected plugin data changed during transition; stop without restart or replay.'
}
Write-Report ([ordered]@{
    schema_version = 1
    mode = $Mode
    result = 'transition_completed_restart_required'
    target_version = $TargetVersion
    expected_ref = $expected.Ref
    expected_revision = $expected.Revision
    final_marketplace = $finalMarketplace
    installed_manifest_version = $installed.version
    backup_manifest_sha256 = $backupManifestHash
    started_at = $startedAt
    ended_at = (Get-Date).ToUniversalTime().ToString('o')
    mutation_occurred = $true
    integrity_before = $before
    integrity_after = $after
})
