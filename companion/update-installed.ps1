[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [string] $TargetDirectory,
    [string] $SourceDirectory
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($SourceDirectory)) {
    $SourceDirectory = Join-Path $PSScriptRoot 'extension'
}

if (-not ('SyndicatumCompanionDirectoryIdentity' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;

public static class SyndicatumCompanionDirectoryIdentity
{
    private const uint OpenExisting = 3;
    private const uint BackupSemantics = 0x02000000;
    private const uint ShareRead = 1;
    private const uint ShareWrite = 2;
    private const uint ShareDelete = 4;

    [StructLayout(LayoutKind.Sequential)]
    private struct FileTime { public uint Low; public uint High; }

    [StructLayout(LayoutKind.Sequential)]
    private struct FileInformation
    {
        public uint Attributes;
        public FileTime CreationTime;
        public FileTime LastAccessTime;
        public FileTime LastWriteTime;
        public uint VolumeSerialNumber;
        public uint FileSizeHigh;
        public uint FileSizeLow;
        public uint NumberOfLinks;
        public uint FileIndexHigh;
        public uint FileIndexLow;
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern SafeFileHandle CreateFile(string name, uint access, uint share, IntPtr security, uint creation, uint flags, IntPtr template);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool GetFileInformationByHandle(SafeFileHandle handle, out FileInformation information);

    public static string Get(string path)
    {
        using (SafeFileHandle handle = CreateFile(path, 0, ShareRead | ShareWrite | ShareDelete, IntPtr.Zero, OpenExisting, BackupSemantics, IntPtr.Zero))
        {
            if (handle.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error());
            FileInformation information;
            if (!GetFileInformationByHandle(handle, out information)) throw new Win32Exception(Marshal.GetLastWin32Error());
            return information.VolumeSerialNumber.ToString("X8") + ":" + information.FileIndexHigh.ToString("X8") + information.FileIndexLow.ToString("X8");
        }
    }
}
'@
}

function Get-CompanionManifest([string] $Directory) {
    $path = Join-Path $Directory 'manifest.json'
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return $null }
    try { $manifest = Get-Content -Raw -LiteralPath $path | ConvertFrom-Json } catch { return $null }
    $valid = ([string] $manifest.name -eq 'Syndicatum Companion' -and
        [int] $manifest.manifest_version -eq 3 -and
        [string] $manifest.version -match '^\d+\.\d+\.\d+(?:\.\d+)?$' -and
        -not [string]::IsNullOrWhiteSpace([string] $manifest.background.service_worker) -and
        -not [string]::IsNullOrWhiteSpace([string] $manifest.action.default_popup))
    if (-not $valid) { return $null }
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

function Get-FileInventory([string] $Root) {
    $inventory = @{}
    $canonicalRoot = (Get-Item -LiteralPath $Root).FullName.TrimEnd('\')
    foreach ($file in Get-ChildItem -LiteralPath $canonicalRoot -File -Recurse -Force) {
        $relative = $file.FullName.Substring($canonicalRoot.Length).TrimStart('\')
        $inventory[$relative] = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash
    }
    return $inventory
}

function Compare-FileTree([string] $ExpectedRoot, [string] $ActualRoot) {
    $canonicalExpectedRoot = (Get-Item -LiteralPath $ExpectedRoot).FullName.TrimEnd('\')
    $canonicalActualRoot = (Get-Item -LiteralPath $ActualRoot).FullName.TrimEnd('\')
    $expected = Get-FileInventory $canonicalExpectedRoot
    $actual = Get-FileInventory $canonicalActualRoot
    $mismatches = @()
    $paths = @($expected.Keys) + @($actual.Keys) | Sort-Object -Unique
    foreach ($path in $paths) {
        if (-not $expected.ContainsKey($path) -or -not $actual.ContainsKey($path) -or $expected[$path] -ne $actual[$path]) { $mismatches += $path }
    }
    return @($mismatches)
}

function Clear-DirectoryContents([string] $Directory) {
    Get-ChildItem -LiteralPath $Directory -Force | Remove-Item -Recurse -Force
}

$source = (Get-Item -LiteralPath (Resolve-Path -LiteralPath $SourceDirectory).Path).FullName
$sourceManifest = Get-CompanionManifest $source
if (-not $sourceManifest) { throw "The update source is not a Syndicatum Companion extension: $source" }

if ([string]::IsNullOrWhiteSpace($TargetDirectory)) { $TargetDirectory = Find-InstalledCompanion }
$target = (Get-Item -LiteralPath (Resolve-Path -LiteralPath $TargetDirectory).Path).FullName
$targetManifest = Get-CompanionManifest $target
if (-not $targetManifest) { throw "The update target is not a Syndicatum Companion extension: $target" }
if ([SyndicatumCompanionDirectoryIdentity]::Get($source) -eq [SyndicatumCompanionDirectoryIdentity]::Get($target)) { throw 'Source and target directories must be different.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = "$target.backup-$($targetManifest.version)-$stamp"
if (-not $PSCmdlet.ShouldProcess($target, "Back up version $($targetManifest.version) and install version $($sourceManifest.version)")) { return }

Copy-Item -LiteralPath $target -Destination $backup -Recurse -Force
$backupMismatches = @(Compare-FileTree $target $backup)
if ($backupMismatches.Count) { throw "Backup verification failed for $($backupMismatches.Count) file(s). The target was not modified." }

try {
    Clear-DirectoryContents $target
    Get-ChildItem -LiteralPath $source -Force | Copy-Item -Destination $target -Recurse -Force
    $deploymentMismatches = @(Compare-FileTree $source $target)
    if ($deploymentMismatches.Count) { throw "Deployment verification failed for $($deploymentMismatches.Count) file(s)." }
    $installedManifest = Get-CompanionManifest $target
    if ([string] $installedManifest.version -ne [string] $sourceManifest.version) { throw 'The installed manifest version does not match the update source.' }
} catch {
    $updateError = $_.Exception.Message
    try {
        Clear-DirectoryContents $target
        Get-ChildItem -LiteralPath $backup -Force | Copy-Item -Destination $target -Recurse -Force
        $rollbackMismatches = @(Compare-FileTree $backup $target)
        if ($rollbackMismatches.Count) { throw "Rollback verification failed for $($rollbackMismatches.Count) file(s)." }
    } catch {
        throw "Companion update failed, and automatic rollback could not be verified. The complete backup remains at $backup. Update error: $updateError Rollback error: $($_.Exception.Message)"
    }
    throw "Companion update failed and the exact backup tree was restored: $updateError"
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
