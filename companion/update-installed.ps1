[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [string] $TargetDirectory,
    [string] $SourceDirectory = (Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) 'extension')
)

$ErrorActionPreference = 'Stop'

function Get-CompanionManifest([string] $Directory) {
    $path = Join-Path $Directory 'manifest.json'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return $null }
    try { $manifest = Get-Content -Raw -LiteralPath $path | ConvertFrom-Json } catch { return $null }
    if ([string] $manifest.name -ne 'Syndicatum Companion') { return $null }
    return $manifest
}

function Get-PhysicalAdminSharePath([string] $Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetPathRoot($full)
    if ($root -notmatch '^([A-Za-z]):\\$') { return $null }
    return "\\localhost\$($Matches[1])$\$($full.Substring($root.Length))"
}

function Find-InstalledCompanion {
    $root = Join-Path $env:LOCALAPPDATA 'Syndicatum\Companion'
    $searchRoots = @($root, (Get-PhysicalAdminSharePath $root)) | Where-Object { $_ }
    foreach ($searchRoot in $searchRoots) {
        if (-not (Test-Path -LiteralPath $searchRoot -PathType Container)) { continue }
        $matches = @(Get-ChildItem -LiteralPath $searchRoot -Directory | Where-Object { $_.Name -notmatch '\.backup-' -and (Get-CompanionManifest $_.FullName) })
        if ($matches.Count -eq 1) { return $matches[0].FullName }
        if ($matches.Count -gt 1) { throw "Multiple Companion installations were found under $searchRoot. Pass -TargetDirectory explicitly." }
    }
    throw 'No installed Syndicatum Companion directory was found. Pass -TargetDirectory explicitly.'
}

function Compare-FileTree([string] $ExpectedRoot, [string] $ActualRoot) {
    $mismatches = @()
    $canonicalExpectedRoot = (Get-Item -LiteralPath $ExpectedRoot).FullName.TrimEnd('\')
    $canonicalActualRoot = (Get-Item -LiteralPath $ActualRoot).FullName.TrimEnd('\')
    foreach ($file in Get-ChildItem -LiteralPath $canonicalExpectedRoot -File -Recurse) {
        $relative = $file.FullName.Substring($canonicalExpectedRoot.Length).TrimStart('\')
        $actual = Join-Path $canonicalActualRoot $relative
        if (-not (Test-Path -LiteralPath $actual -PathType Leaf)) { $mismatches += $relative; continue }
        if ((Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash -ne (Get-FileHash -Algorithm SHA256 -LiteralPath $actual).Hash) { $mismatches += $relative }
    }
    return @($mismatches)
}

$source = (Resolve-Path -LiteralPath $SourceDirectory).Path
$sourceManifest = Get-CompanionManifest $source
if (-not $sourceManifest) { throw "The update source is not a Syndicatum Companion extension: $source" }

if ([string]::IsNullOrWhiteSpace($TargetDirectory)) { $TargetDirectory = Find-InstalledCompanion }
$target = (Resolve-Path -LiteralPath $TargetDirectory).Path
$targetManifest = Get-CompanionManifest $target
if (-not $targetManifest) { throw "The update target is not a Syndicatum Companion extension: $target" }
if ([StringComparer]::OrdinalIgnoreCase.Equals($source, $target)) { throw 'Source and target directories must be different.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = "$target.backup-$($targetManifest.version)-$stamp"
if (-not $PSCmdlet.ShouldProcess($target, "Back up version $($targetManifest.version) and install version $($sourceManifest.version)")) { return }

Copy-Item -LiteralPath $target -Destination $backup -Recurse
$backupMismatches = @(Compare-FileTree $target $backup)
if ($backupMismatches.Count) { throw "Backup verification failed for $($backupMismatches.Count) file(s). The target was not modified." }

try {
    Get-ChildItem -LiteralPath $source -Force | Copy-Item -Destination $target -Recurse -Force
    $deploymentMismatches = @(Compare-FileTree $source $target)
    if ($deploymentMismatches.Count) { throw "Deployment verification failed for $($deploymentMismatches.Count) file(s)." }
    $installedManifest = Get-CompanionManifest $target
    if ([string] $installedManifest.version -ne [string] $sourceManifest.version) { throw 'The installed manifest version does not match the update source.' }
} catch {
    Get-ChildItem -LiteralPath $backup -Force | Copy-Item -Destination $target -Recurse -Force
    throw "Companion update failed and the backup was restored: $($_.Exception.Message)"
}

[pscustomobject]@{
    Target = $target
    Backup = $backup
    VersionBefore = [string] $targetManifest.version
    VersionAfter = [string] $sourceManifest.version
    BackupMismatchCount = $backupMismatches.Count
    DeploymentMismatchCount = $deploymentMismatches.Count
    ReloadRequired = $true
}
