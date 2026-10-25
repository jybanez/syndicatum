# Actual constructor, but EVERY native/filesystem primitive is replaced before
# construction. Returned callbacks run outside the original module invocation.
$ErrorActionPreference='Stop'
$path=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1'
Import-Module $path -Force
$module=Get-Module 'codex-developer-fixed-storage'
$fixture=$null
try {
    $fixture=& $module {
        $state=@{calls=[Collections.Generic.List[string]]::new();fault=''}
        $originals=@{}
        $names=@('Initialize-FixedHandleApi','Assert-FixedPath','Open-FixedNativeStream',
            'Get-FixedNativePath','Get-FixedNativeIdentity','Get-FixedNativeLength','Get-FixedAclPin',
            'Read-FixedNativeBytes','Write-FixedNativeBytes','Flush-FixedNativeStream','Close-FixedNativeStream')
        foreach ($name in $names) {
            $originals[$name]=(Get-Command -Name $name -CommandType Function).ScriptBlock
            $stub={param($One,$Two,$Three)
                $state.calls.Add($name)
                if ($state.fault -ceq $name) {
                    throw [Management.Automation.ErrorRecord]::new([InvalidOperationException]::new('inert helper fault'),
                        ('FixtureHelperFault-'+$name),[Management.Automation.ErrorCategory]::InvalidOperation,$null)
                }
                switch -CaseSensitive ($name) {
                    'Assert-FixedPath' { if ($One -cne 'INERT-PATH' -or $Two -cne 'INERT-ROOT') { throw 'inert path rejected' } }
                    'Open-FixedNativeStream' { @{fake=$true;path=$One.path} }
                    'Get-FixedNativePath' { '\\?\INERT-PATH' }
                    'Get-FixedNativeIdentity' { 'inert-identity' }
                    'Get-FixedNativeLength' { 512L }
                    'Get-FixedAclPin' { 'inert-private-acl' }
                    'Read-FixedNativeBytes' { ,[byte[]]@(1,2,3) }
                }
            }.GetNewClosure()
            Set-Item -LiteralPath ('Function:script:'+$name) -Value $stub
            if ((Get-Command -Name $name -CommandType Function).ScriptBlock.ToString() -cne $stub.ToString()) {
                throw 'Primitive was not replaced; constructor must not run'
            }
        }
        # New-FixedWindowsApi itself is NOT replaced.
        $api=New-FixedWindowsApi @{root='INERT-ROOT'}
        @{api=$api;state=$state;originals=$originals;names=$names}
    }
    $pin=@{path='INERT-PATH'}
    $handle=& $fixture.api.Open $pin
    $observed=& $fixture.api.Inspect $handle $pin
    $read=& $fixture.api.Read $handle 0 3
    & $fixture.api.Write $handle 0 ([byte[]]@(7,8,9))
    & $fixture.api.Flush $handle
    & $fixture.api.Close $handle
    if (-not $handle.fake -or $observed.identity -cne 'inert-identity' -or
        $observed.length -ne 512 -or $observed.acl -cne 'inert-private-acl' -or
        [Convert]::ToBase64String($read) -cne 'AQID') { throw 'Actual factory did not reach inert primitives' }
    foreach ($name in $fixture.names) {
        if (-not $fixture.state.calls.Contains($name)) { throw "Primitive not reached: $name" }
    }
    # Each captured dependency must propagate its original error classification.
    foreach ($name in $fixture.names) {
        $fixture.state.fault=$name
        $caught=$false
        try {
            switch -CaseSensitive ($name) {
                'Initialize-FixedHandleApi' { & $module { New-FixedWindowsApi @{root='INERT-ROOT'} } }
                'Assert-FixedPath' { & $fixture.api.Open $pin }
                'Open-FixedNativeStream' { & $fixture.api.Open $pin }
                'Read-FixedNativeBytes' { & $fixture.api.Read $handle 0 3 }
                'Write-FixedNativeBytes' { & $fixture.api.Write $handle 0 ([byte[]]@(1)) }
                'Flush-FixedNativeStream' { & $fixture.api.Flush $handle }
                'Close-FixedNativeStream' { & $fixture.api.Close $handle }
                default { & $fixture.api.Inspect $handle $pin }
            }
        } catch {
            if (($_.FullyQualifiedErrorId -split ',')[0] -cne ('FixtureHelperFault-'+$name)) { throw }
            $caught=$true
        }
        if (-not $caught) { throw "Original helper failure swallowed: $name" }
    }
    $fixture.state.fault='Assert-FixedPath'
    $caught=$false
    try { & $fixture.api.Inspect $handle $pin } catch {
        if (($_.FullyQualifiedErrorId -split ',')[0] -cne 'FixtureHelperFault-Assert-FixedPath') { throw }
        $caught=$true
    }
    if (-not $caught) { throw 'Inspect bypassed original path-helper error' }
} finally {
    if ($null -ne $fixture) {
        & $module {param($Originals)
            foreach ($name in $Originals.Keys) { Set-Item -LiteralPath ('Function:script:'+$name) -Value $Originals[$name] }
        } $fixture.originals
    }
}
'Actual Windows callback constructor: all11 primitive/helper stubs reached outside module scope; exact errors retained; no native/file API invoked.'
