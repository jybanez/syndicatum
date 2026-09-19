[CmdletBinding()]
param(
    [string] $OutputDirectory = (Join-Path $env:TEMP 'syndicatum-companion-releases')
)

$ErrorActionPreference = 'Stop'
if ($PSVersionTable.PSEdition -ne 'Desktop' -or $PSVersionTable.PSVersion.Major -ne 5) {
    throw 'Canonical Companion release builds require Windows PowerShell 5.1 (powershell.exe). PowerShell 7 uses different ZIP container metadata and would produce a different checksum.'
}
$companionRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$extensionRoot = Join-Path $companionRoot 'extension'
$manifestPath = Join-Path $extensionRoot 'manifest.json'

if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw "Extension manifest was not found at $manifestPath"
}

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string] $manifest.version)) {
    throw 'Extension manifest version is required.'
}

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$resolvedOutput = (Resolve-Path -LiteralPath $OutputDirectory).Path
$archiveName = "syndicatum-companion-v$($manifest.version).zip"
$archivePath = Join-Path $resolvedOutput $archiveName
$checksumPath = "$archivePath.sha256"

if (Test-Path -LiteralPath $archivePath) {
    Remove-Item -LiteralPath $archivePath -Force
}
if (Test-Path -LiteralPath $checksumPath) {
    Remove-Item -LiteralPath $checksumPath -Force
}

Add-Type -AssemblyName System.IO.Compression
$fixedTimestamp = [DateTimeOffset]::Parse('2000-01-01T00:00:00Z')
$utf8WithoutBom = [Text.UTF8Encoding]::new($false)
$normalizedTextExtensions = @('.css', '.html', '.js', '.json', '.md', '.mjs', '.txt')
$files = @(Get-ChildItem -LiteralPath $extensionRoot -File -Recurse | ForEach-Object {
    [pscustomobject]@{
        FullName = $_.FullName
        RelativePath = $_.FullName.Substring($extensionRoot.Length).TrimStart('\').Replace('\', '/')
    }
} | Sort-Object RelativePath)

$stream = [IO.File]::Open($archivePath, [IO.FileMode]::CreateNew, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
try {
    $archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create, $false)
    try {
        foreach ($file in $files) {
            $entry = $archive.CreateEntry($file.RelativePath, [IO.Compression.CompressionLevel]::NoCompression)
            $entry.LastWriteTime = $fixedTimestamp
            $output = $entry.Open()
            try {
                $extension = [IO.Path]::GetExtension($file.FullName).ToLowerInvariant()
                if ($normalizedTextExtensions -contains $extension) {
                    $text = [IO.File]::ReadAllText($file.FullName).Replace("`r`n", "`n").Replace("`r", "`n")
                    $bytes = $utf8WithoutBom.GetBytes($text)
                    $output.Write($bytes, 0, $bytes.Length)
                } else {
                    $input = [IO.File]::OpenRead($file.FullName)
                    try { $input.CopyTo($output) } finally { $input.Dispose() }
                }
            } finally { $output.Dispose() }
        }
    } finally { $archive.Dispose() }
} finally { $stream.Dispose() }

$hash = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant()
Set-Content -LiteralPath $checksumPath -Value "$hash  $archiveName" -Encoding ascii

[pscustomobject]@{
    Version = [string] $manifest.version
    Archive = $archivePath
    Checksum = $checksumPath
    SHA256 = $hash
}
