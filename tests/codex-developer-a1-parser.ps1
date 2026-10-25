$ErrorActionPreference='Stop'
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '../tools/release/codex-developer-a1-module-probe.ps1'),[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'parse_errors'}
$fn=$ast.Find({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq 'ConvertFrom-A1Frame'},$true)
# Execute extracted pure parser only, never the payload or its stdin/import body.
. ([scriptblock]::Create($fn.Extent.Text))
$good='{"nonce":"'+('a'*48)+'"}'+"`n"
if((ConvertFrom-A1Frame ([Text.Encoding]::UTF8.GetBytes($good))) -cne ('a'*48)){throw 'valid_frame'}
$bad=@($good+$good,$good+'x','{','{"nonce":42}'+"`n",'{"nonce":null}'+"`n",'{"nonce":"'+('a'*48)+'","nonce":"'+('a'*48)+'"}'+"`n",('x'*4097),'',$good.TrimEnd("`n"))
foreach($raw in $bad){$stopped=$false;try{[void](ConvertFrom-A1Frame ([Text.Encoding]::UTF8.GetBytes($raw)))}catch{$stopped=$true;if($_.Exception.Message -notin @('frame_contract','frame_bound')){throw}};if(-not $stopped){throw 'invalid_frame_accepted'}}
'PASS: extracted inert PowerShell parser'
