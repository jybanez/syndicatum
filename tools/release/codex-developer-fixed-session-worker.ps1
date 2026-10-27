# SOURCE ONLY: this unconditional gate precedes all import, native and stdin IO.
throw 'SOURCE_ONLY: persistent Windows session worker disabled.'

$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot 'codex-developer-fixed-storage.psm1') -Force
$module=Get-Module 'codex-developer-fixed-storage'
& $module {
    param($ProtocolPath)
    . $ProtocolPath
    $context=@{sequence=0;identity='';terminal=$false;openAttempted=$false;
        closeAttempted=$false;closeUncertain=$false;session=$null}
    $inputStream=[Console]::OpenStandardInput()
    $outputStream=[Console]::OpenStandardOutput()
    try {
        while (-not $context.terminal) {
            $line=Read-FixedProtocolFrame $inputStream
            $request=$line | ConvertFrom-Json -AsHashtable
            $response=Invoke-FixedProtocol $context $request {param($manifest) New-FixedWindowsApi $manifest}
            Write-FixedProtocolFrame $outputStream ($response | ConvertTo-Json -Depth 30 -Compress)
        }
    } finally { Close-FixedProtocol $context }
} (Join-Path $PSScriptRoot 'codex-developer-fixed-session-protocol.ps1')
