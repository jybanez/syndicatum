# Definitions only. Runs inside the existing storage module scope with its API.
# No process, native API, storage setup or IO happens by loading this file.
function Close-FixedProtocol($Context) {
    if ($Context.closeAttempted) { return }
    $Context.closeAttempted=$true
    $Context.terminal=$true
    $failures=[Collections.Generic.List[string]]::new()
    if ($null -ne $Context.session -and -not $Context.session.closed) {
        $Context.session.closed=$true
        foreach ($name in @($Context.session.handles.Keys)) {
            $handle=$Context.session.handles[$name]
            $Context.session.handles.Remove($name)
            try { & $Context.session.api.Close $handle } catch { $failures.Add('handle_close_uncertain') }
        }
    }
    if ($failures.Count) { throw 'STOP: protocol close uncertain; no retry' }
}
function Invoke-FixedProtocol($Context,[hashtable]$Request,[scriptblock]$ApiFactory) {
    try {
        if ($Context.terminal -or $Request.Count -ne 4 -or
            $Request.session -isnot [string] -or -not $Request.session -or
            $Request.sequence -isnot [long] -and $Request.sequence -isnot [int] -or
            $Request.sequence -ne ($Context.sequence+1) -or
            ($Context.sequence -gt 0 -and $Request.session -cne $Context.identity)) { throw 'STOP: protocol sequence/session' }
        $Context.sequence=$Request.sequence; $Context.identity=$Request.session
        $args=$Request.arguments
        if ($Request.operation -ceq 'open') {
            if ($Context.openAttempted) { throw 'STOP: protocol reopen forbidden' }
            $Context.openAttempted=$true
            $raw=[Convert]::FromBase64String($args.manifest_base64)
            if ($raw.Length -gt 65536 -or [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($raw)).ToLowerInvariant() -cne $args.sha256) {
                throw 'STOP: protocol manifest hash'
            }
            $manifest=[Text.Encoding]::UTF8.GetString($raw) | ConvertFrom-Json -AsHashtable
            $api=& $ApiFactory $manifest
            $Context.session=Open-FixedSession $manifest $api
            $value=$Context.session.manifest
        } elseif ($Request.operation -ceq 'close') {
            Close-FixedProtocol $Context
            $value=@{closed=$true}
        } else {
            if ($null -eq $Context.session) { throw 'STOP: protocol not opened' }
            Assert-FixedSession $Context.session
            $name=if ($args.ContainsKey('name')) { $args.name } else { $null }
            switch -CaseSensitive ($Request.operation) {
                'inspect' { $value=$Context.session.manifest }
                'read' {
                    if ($name -cnotin @('records','heads') -or
                        ($args.index -isnot [long] -and $args.index -isnot [int]) -or
                        $args.index -lt 0 -or $args.index -ge $Context.session.manifest.slots) { throw 'STOP: protocol read bound' }
                    $width=$Context.session.manifest.width
                    $raw=& $Context.session.api.Read $Context.session.handles[$name] ([long]$args.index*$width) $width
                    if ($raw.Length -ne $width) { throw 'STOP: protocol short read' }
                    $value=[Convert]::ToBase64String($raw)
                }
                'write' {
                    if ($args.index -isnot [long] -and $args.index -isnot [int]) { throw 'STOP: protocol write index' }
                    $raw=[Convert]::FromBase64String($args.base64)
                    Write-FixedSlot $Context.session $name $args.index $raw
                    $value=@{written=$true}
                }
                'flush' {
                    if ($name -cnotin @('records','heads')) { throw 'STOP: protocol flush scope' }
                    & $Context.session.api.Flush $Context.session.handles[$name]
                    $value=@{flushed=$true}
                }
                default { throw 'STOP: unknown protocol operation' }
            }
            Assert-FixedSession $Context.session
        }
        return @{session=$Request.session;sequence=$Request.sequence;ok=$true;value=$value}
    } catch {
        $primary=$_
        try { Close-FixedProtocol $Context } catch { $Context.closeUncertain=$true }
        throw $primary
    }
}
