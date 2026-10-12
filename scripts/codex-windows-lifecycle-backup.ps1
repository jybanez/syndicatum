param(
    [Parameter(Mandatory = $true)][string] $HandoffRoot,
    [Parameter(Mandatory = $true)][string] $MarketplaceRoot,
    [Parameter(Mandatory = $true)][string] $PluginCacheRoot,
    [Parameter(Mandatory = $true)][string] $PluginRegistryRoot,
    [Parameter(Mandatory = $true)][string] $ProtectedStateRoot,
    [Parameter(Mandatory = $true)][string] $CodexRoot,
    [Parameter(Mandatory = $true)][string] $WebRoot
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Get-CanonicalLocalDirectory([string] $Path, [bool] $MustExist) {
    if (-not [IO.Path]::IsPathFullyQualified($Path) -or $Path.StartsWith('\\') -or $Path.Contains('::')) {
        throw 'Expected a fully qualified local filesystem path.'
    }
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    if ($full -notmatch '^[A-Za-z]:\\') { throw 'Expected a local drive path.' }
    if ($MustExist -and -not (Test-Path -LiteralPath $full -PathType Container)) {
        throw 'Required directory is missing.'
    }
    $cursor = $full
    while ($cursor) {
        if (Test-Path -LiteralPath $cursor) {
            $item = Get-Item -LiteralPath $cursor -Force
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Reparse point in path chain.' }
            if (-not $item.PSIsContainer) { throw 'Non-directory in root path chain.' }
        }
        $parent = Split-Path -Parent $cursor
        if (-not $parent -or $parent -eq $cursor) { break }
        $cursor = $parent
    }
    $volume = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($full))
    if (-not $volume.IsReady -or $volume.DriveType -ne [IO.DriveType]::Fixed) {
        throw 'Expected a ready fixed local volume.'
    }
    return $full
}

function Test-PathOverlap([string] $Left, [string] $Right) {
    return $Left.Equals($Right, [StringComparison]::OrdinalIgnoreCase) -or
        $Left.StartsWith($Right + '\', [StringComparison]::OrdinalIgnoreCase) -or
        $Right.StartsWith($Left + '\', [StringComparison]::OrdinalIgnoreCase)
}

function Get-TreeInventory([string] $Root) {
    $members = @(Get-ChildItem -LiteralPath $Root -Recurse -Force -ErrorAction Stop)
    if (@($members | Where-Object { $_.Attributes -band [IO.FileAttributes]::ReparsePoint }).Count) {
        throw 'Reparse member found.'
    }
    return @($members | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($Root.Length).TrimStart('\')
        if ($_.PSIsContainer) {
            [ordered]@{ path = $relative; type = 'directory'; bytes = [long] 0; sha256 = $null }
        } else {
            [ordered]@{
                path = $relative
                type = 'file'
                bytes = [long] $_.Length
                sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
            }
        }
    })
}

function Test-EqualRecord($Left, $Right) {
    return (ConvertTo-Json -InputObject $Left -Depth 12 -Compress) -ceq
        (ConvertTo-Json -InputObject $Right -Depth 12 -Compress)
}

try {
    $base = Get-CanonicalLocalDirectory $HandoffRoot $true
    $backup = Get-CanonicalLocalDirectory (Join-Path $base 'backup-first-transition') $false
    $roots = [ordered]@{
        marketplace = Get-CanonicalLocalDirectory $MarketplaceRoot $true
        plugin_cache = Get-CanonicalLocalDirectory $PluginCacheRoot $true
        plugin_registry = Get-CanonicalLocalDirectory $PluginRegistryRoot $true
        protected_state = Get-CanonicalLocalDirectory $ProtectedStateRoot $true
    }
    $codex = Get-CanonicalLocalDirectory $CodexRoot $true
    $web = Get-CanonicalLocalDirectory $WebRoot $true
    foreach ($protected in @($roots.Values) + @($codex, $web)) {
        if (Test-PathOverlap $backup $protected) { throw 'Backup destination overlaps a protected root.' }
    }
    if (Test-Path -LiteralPath $backup) { throw 'Backup destination already exists; no replay.' }

    $record = [ordered]@{
        schema_version = 1
        timestamp = [DateTime]::UtcNow.ToString('o')
        roots = [ordered]@{}
        files = [ordered]@{}
    }
    [long] $required = 0
    foreach ($key in $roots.Keys) {
        $rows = @(Get-TreeInventory $roots[$key])
        $record.roots[$key] = $rows
        foreach ($row in $rows) {
            if ($row.type -eq 'file') { $required = [long] ([decimal] $required + [decimal] $row.bytes) }
        }
    }
    foreach ($name in @('config.toml', '.codex-global-state.json', '.codex-global-state.json.bak')) {
        $source = Join-Path $codex $name
        $item = Get-Item -LiteralPath $source -Force
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'Invalid Codex configuration source.'
        }
        $record.files[$name] = [ordered]@{
            bytes = [long] $item.Length
            sha256 = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
        }
        $required = [long] ([decimal] $required + [decimal] $item.Length)
    }

    [long] $margin = [Math]::Max(67108864, [Math]::Ceiling($required * 0.2))
    [long] $capacity = [long] ([decimal] $required + [decimal] $margin)
    $destinationVolume = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($backup))
    if ($destinationVolume.AvailableFreeSpace -lt $capacity) { throw 'Insufficient calculated backup capacity.' }

    if ((Get-CanonicalLocalDirectory $backup $false) -ne $backup) { throw 'Destination changed.' }
    foreach ($protected in @($roots.Values) + @($codex, $web)) {
        $resolved = Get-CanonicalLocalDirectory $protected $true
        if (Test-PathOverlap $backup $resolved) { throw 'Backup destination overlaps a protected root.' }
    }

    New-Item -ItemType Directory -Path $backup -ErrorAction Stop | Out-Null
    $null = Get-CanonicalLocalDirectory $backup $true
    foreach ($key in $roots.Keys) {
        $source = $roots[$key]
        $destination = Join-Path $backup $key
        Copy-Item -LiteralPath $source -Destination $destination -Recurse -ErrorAction Stop
        if (-not (Test-EqualRecord $record.roots[$key] @(Get-TreeInventory $destination))) {
            throw 'Tree copy verification failed.'
        }
    }
    foreach ($name in $record.files.Keys) {
        $source = Join-Path $codex $name
        $destination = Join-Path $backup $name
        Copy-Item -LiteralPath $source -Destination $destination -ErrorAction Stop
        $copied = Get-Item -LiteralPath $destination -Force
        if (($copied.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
            $copied.Length -ne $record.files[$name].bytes -or
            (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash -ne $record.files[$name].sha256) {
            throw 'Codex configuration copy verification failed.'
        }
    }

    $manifest = Join-Path $backup 'verified-manifest.json'
    $json = ConvertTo-Json -InputObject $record -Depth 12
    $bytes = [Text.UTF8Encoding]::new($false).GetBytes($json)
    $expectedHash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes))
    [IO.File]::WriteAllBytes($manifest, $bytes)
    if ((Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash -ne $expectedHash) {
        throw 'Manifest write-integrity mismatch.'
    }
    $detachedHash = Join-Path $backup 'verified-manifest.sha256'
    [IO.File]::WriteAllText($detachedHash, $expectedHash, [Text.UTF8Encoding]::new($false))
    if ([IO.File]::ReadAllText($detachedHash) -cne
        (Get-FileHash -LiteralPath $manifest -Algorithm SHA256).Hash) {
        throw 'Detached manifest hash mismatch.'
    }

    $persisted = Get-Content -Raw -LiteralPath $manifest | ConvertFrom-Json -AsHashtable
    if ($persisted.schema_version -ne 1 -or -not (Test-EqualRecord $record $persisted)) {
        throw 'Persisted manifest schema/content mismatch.'
    }
    foreach ($key in $persisted.roots.Keys) {
        if (-not (Test-EqualRecord $persisted.roots[$key] @(Get-TreeInventory (Join-Path $backup $key))) -or
            -not (Test-EqualRecord $persisted.roots[$key] @(Get-TreeInventory $roots[$key]))) {
            throw 'Persisted-manifest tree revalidation failed.'
        }
    }
    foreach ($name in $persisted.files.Keys) {
        foreach ($path in @((Join-Path $backup $name), (Join-Path $codex $name))) {
            $item = Get-Item -LiteralPath $path -Force
            if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
                $item.Length -ne $persisted.files[$name].bytes -or
                (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash -ne $persisted.files[$name].sha256) {
                throw 'Persisted-manifest Codex configuration revalidation failed.'
            }
        }
    }
    Write-Output "PASS: verified backup persisted and revalidated. Manifest SHA256=$expectedHash"
} catch {
    Write-Error 'STOP: lifecycle backup failed. Retain artifacts locally; no cleanup or replay.'
    exit 1
}
