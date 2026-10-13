$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$helper = Join-Path $repositoryRoot 'scripts\codex-windows-lifecycle-backup.ps1'
$fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('syndicatum-backup-test-' + [guid]::NewGuid())

function Assert-True([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}

function Invoke-Helper([string] $HandoffRoot, [string] $WebRoot) {
    $arguments = @(
        '-NoProfile', '-File', $helper,
        '-HandoffRoot', $HandoffRoot,
        '-MarketplaceRoot', (Join-Path $fixtureRoot 'marketplace'),
        '-PluginCacheRoot', (Join-Path $fixtureRoot 'cache'),
        '-ProtectedStateRoot', (Join-Path $fixtureRoot 'protected'),
        '-CodexRoot', (Join-Path $fixtureRoot 'codex'),
        '-WebRoot', $WebRoot
    )
    $output = @(& pwsh @arguments 2>&1)
    [pscustomobject]@{ exit_code = $LASTEXITCODE; output = $output -join "`n" }
}

try {
    foreach ($directory in @('marketplace', 'cache', 'protected', 'codex', 'web', 'handoff')) {
        New-Item -ItemType Directory -Path (Join-Path $fixtureRoot $directory) -Force | Out-Null
    }
    New-Item -ItemType Directory -Path (Join-Path $fixtureRoot 'marketplace\empty') | Out-Null
    foreach ($pair in @(
        @('marketplace\release.txt', 'marketplace'),
        @('cache\plugin.txt', 'cache'),
        @('protected\profile.bin', 'protected'),
        @('codex\config.toml', 'config'),
        @('codex\.codex-global-state.json', '{}'),
        @('codex\.codex-global-state.json.bak', '{}')
    )) {
        [IO.File]::WriteAllText((Join-Path $fixtureRoot $pair[0]), $pair[1])
    }

    $success = Invoke-Helper (Join-Path $fixtureRoot 'handoff') (Join-Path $fixtureRoot 'web')
    Assert-True ($success.exit_code -eq 0) "Expected successful backup: $($success.output)"
    $backup = Join-Path $fixtureRoot 'handoff\backup-first-transition'
    $manifestPath = Join-Path $backup 'verified-manifest.json'
    $hashPath = Join-Path $backup 'verified-manifest.sha256'
    Assert-True (Test-Path -LiteralPath $manifestPath -PathType Leaf) 'Manifest was not created.'
    Assert-True (Test-Path -LiteralPath $hashPath -PathType Leaf) 'Detached hash was not created.'
    Assert-True ((Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash -ceq
        [IO.File]::ReadAllText($hashPath)) 'Persisted manifest hash did not verify.'
    $manifest = Get-Content -Raw -LiteralPath $manifestPath | ConvertFrom-Json
    Assert-True (@($manifest.roots.marketplace | Where-Object {
        $_.path -eq 'empty' -and $_.type -eq 'directory'
    }).Count -eq 1) 'Empty directory was not retained in the manifest.'

    $overlapHandoff = Join-Path $fixtureRoot 'marketplace\handoff'
    New-Item -ItemType Directory -Path $overlapHandoff | Out-Null
    $overlap = Invoke-Helper $overlapHandoff (Join-Path $fixtureRoot 'web')
    Assert-True ($overlap.exit_code -ne 0) 'Protected-root overlap was not rejected.'

    $missingWebHandoff = Join-Path $fixtureRoot 'handoff-missing-web'
    New-Item -ItemType Directory -Path $missingWebHandoff | Out-Null
    $missingWeb = Invoke-Helper $missingWebHandoff (Join-Path $fixtureRoot 'missing-web')
    Assert-True ($missingWeb.exit_code -ne 0) 'Missing webroot was not rejected.'
    Assert-True ($missingWeb.output -match "sanitized stage 'canonicalize_inputs'") 'Missing-webroot failure did not report its sanitized stage.'
    Assert-True (-not (Test-Path -LiteralPath (Join-Path $missingWebHandoff 'backup-first-transition'))) 'Missing-webroot rejection wrote backup data.'

    Write-Output 'Codex lifecycle backup tests: 3/3 passed.'
} finally {
    $resolvedFixture = [IO.Path]::GetFullPath($fixtureRoot)
    $expectedParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($resolvedFixture.StartsWith($expectedParent, [StringComparison]::OrdinalIgnoreCase) -and
        (Split-Path -Leaf $resolvedFixture).StartsWith('syndicatum-backup-test-')) {
        Remove-Item -LiteralPath $resolvedFixture -Recurse -Force -ErrorAction SilentlyContinue
    }
}
