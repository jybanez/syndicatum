throw 'SOURCE_ONLY: A1 module/stdin probe disabled'
$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot 'codex-developer-fixed-storage.psm1') -Force
. (Join-Path $PSScriptRoot 'codex-developer-fixed-session-protocol.ps1')
$reader=[Console]::In
$chars=[Collections.Generic.List[char]]::new()
while($true){$c=$reader.Read();if($c -lt 0){throw 'frame_eof'};if($c -eq 10){break};$chars.Add([char]$c);if($chars.Count -gt 4095){throw 'frame_bound'}}
$v=(-join $chars) | ConvertFrom-Json -AsHashtable
if($v.Count -ne 1 -or -not $v.ContainsKey('nonce') -or $v.nonce -cnotmatch '^[a-f0-9]{48}$'){throw 'nonce_schema'}
@{nonce=$v.nonce;module=(Get-Module 'codex-developer-fixed-storage').Path} | ConvertTo-Json -Compress
