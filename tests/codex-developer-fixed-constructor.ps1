# Actual constructor, but EVERY native/filesystem primitive is replaced before
# construction. Returned callbacks run outside the original module invocation.
$ErrorActionPreference='Stop'
$path=Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1'
Import-Module $path -Force
$module=Get-Module 'codex-developer-fixed-storage'
$fixture=$null
try {
    $fixture=& $module {
        $state=@{calls=[Collections.Generic.List[string]]::new();fault='';finalPath='\\?\INERT-PATH'}
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
                    'Get-FixedNativePath' { $state.finalPath }
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
    $pin=@{path='INERT-PATH';final_path='\\?\INERT-PATH'}
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
    # Input aliases need not be text-equal to the retained normalized handle name.
    $pin.final_path='\\?\CANONICAL-LONG-PATH'
    $fixture.state.finalPath=$pin.final_path
    $null=& $fixture.api.Inspect $handle $pin
    $fixture.state.finalPath='\\?\CHANGED-PATH'
    $rejected=$false
    try { & $fixture.api.Inspect $handle $pin } catch {
        if ($_.Exception.Message -cne 'STOP: final handle path') { throw }
        $rejected=$true
    }
    if (-not $rejected) { throw 'Changed retained normalized name accepted' }
    $pin.final_path='\\?\INERT-PATH'; $fixture.state.finalPath=$pin.final_path
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

# Exercise the REAL path walker. Get-Item is stubbed; typed .NET path objects
# have metadata/parents overridden with note properties, so no filesystem
# metadata getter is reached. Only the first node has provider PSIsContainer.
& $module {
    $root=[IO.DirectoryInfo]::new('C:\INERT-ROOT')
    $file=[IO.FileInfo]::new('C:\INERT-ROOT\file.bin')
    $root | Add-Member -MemberType NoteProperty -Name Attributes -Value ([IO.FileAttributes]0) -Force
    $root | Add-Member -MemberType NoteProperty -Name Parent -Value $null -Force
    $file | Add-Member -MemberType NoteProperty -Name Attributes -Value ([IO.FileAttributes]0) -Force
    $file | Add-Member -MemberType NoteProperty -Name Directory -Value $root -Force
    $file | Add-Member -MemberType NoteProperty -Name PSIsContainer -Value $false -Force
    if ($null -ne $root.PSObject.Properties['PSIsContainer'] -or
        $root.PSObject.Properties['Attributes'].MemberType -ne 'NoteProperty' -or
        $root.PSObject.Properties['Parent'].MemberType -ne 'NoteProperty' -or
        $file.PSObject.Properties['Directory'].MemberType -ne 'NoteProperty') {
        throw 'Inert metadata fixture would not isolate native getters'
    }
    $state=@{node=$file;calls=0}
    $stub={param($LiteralPath,[switch]$Force,$ErrorAction)
        $state.calls++; $state.node
    }.GetNewClosure()
    Set-Item -LiteralPath 'Function:script:Get-Item' -Value $stub
    try {
        Assert-FixedPath 'C:\INERT-ROOT\file.bin' 'C:\INERT-ROOT'
        if ($state.calls -ne 1) { throw 'Real walker bypassed Get-Item stub' }
        $legacyFailed=$false
        try {
            $cursor=$file
            while ($null -ne $cursor) {
                $cursor=if ($cursor.PSIsContainer) {$cursor.Parent} else {$cursor.Directory}
            }
        } catch { $legacyFailed=$true }
        if (-not $legacyFailed) { throw 'Legacy provider-property defect not reproduced' }
        $root.Attributes=[IO.FileAttributes]::ReparsePoint
        $rejected=$false
        try { Assert-FixedPath 'C:\INERT-ROOT\file.bin' 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -cne 'STOP: reparse ancestor') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Typed parent reparse point accepted' }
        $state.node=@{Attributes=0;Directory=$null}
        $rejected=$false
        try { Assert-FixedPath 'C:\INERT-ROOT\file.bin' 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -cne 'STOP: non-filesystem path object') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Untyped path object accepted' }
    } finally { Remove-Item -LiteralPath 'Function:script:Get-Item' }
}
'Actual path walker passes typed parent fixture, rejects reparse/untyped nodes; legacy provider-property failure reproduced; no filesystem metadata read.'
