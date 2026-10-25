param([string]$A1Nonce)
$ErrorActionPreference='Stop'
function ConvertFrom-A1Frame([byte[]]$Raw){
    if($Raw.Length -gt 4096){throw 'frame_bound'}
    $text=[Text.Encoding]::UTF8.GetString($Raw)
    if($Raw.Length -ne 61 -or $text -cnotmatch '\A\{"nonce":"[a-f0-9]{48}"\}\n\z'){throw 'frame_contract'}
    return ($text | ConvertFrom-Json -AsHashtable).nonce
}
if($A1Nonce -cnotmatch '\A[a-f0-9]{48}\z'){throw 'launch_context'}
$inputStream=[Console]::OpenStandardInput()
$bytes=[Collections.Generic.List[byte]]::new()
while($true){$c=$inputStream.ReadByte();if($c -lt 0){break};$bytes.Add([byte]$c);if($bytes.Count -gt 4096){throw 'frame_bound'}}
$nonce=ConvertFrom-A1Frame $bytes.ToArray()
if($nonce -cne $A1Nonce){throw 'nonce_context'}
Import-Module (Join-Path $PSScriptRoot 'codex-developer-fixed-storage.psm1') -Force
. (Join-Path $PSScriptRoot 'codex-developer-fixed-session-protocol.ps1')
@{nonce=$nonce;module=(Get-Module 'codex-developer-fixed-storage').Path} | ConvertTo-Json -Compress
