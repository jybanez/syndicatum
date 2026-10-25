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
    try {
        while (-not $context.terminal) {
            # Future host admission must supply bounded/deadline-aware framing;
            # ReadLine alone cannot hard-bound a blocked OS/pipe read. Gate closed.
            $line=[Console]::In.ReadLine()
            if ($null -eq $line -or $line.Length -gt 2097152) { throw 'STOP: protocol EOF/bound' }
            $request=$line | ConvertFrom-Json -AsHashtable
            $response=Invoke-FixedProtocol $context $request {param($manifest) New-FixedWindowsApi $manifest}
            [Console]::Out.WriteLine(($response | ConvertTo-Json -Depth 30 -Compress))
            [Console]::Out.Flush()
        }
    } finally { Close-FixedProtocol $context }
} (Join-Path $PSScriptRoot 'codex-developer-fixed-session-protocol.ps1')
