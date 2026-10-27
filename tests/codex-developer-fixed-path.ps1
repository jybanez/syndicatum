# Actual path-validation body. Get-Item plus all filesystem metadata getters are
# stubbed. Typed path constructors and lexical normalization do not open files.
$ErrorActionPreference='Stop'
Import-Module (Join-Path $PSScriptRoot '../tools/release/codex-developer-fixed-storage.psm1') -Force
$module=Get-Module 'codex-developer-fixed-storage'
& $module {
    function New-InertPathNode($Name,[bool]$Directory,$Parent) {
        $node=if ($Directory) {[IO.DirectoryInfo]::new($Name)} else {[IO.FileInfo]::new($Name)}
        $node | Add-Member -MemberType NoteProperty -Name FullName -Value $Name -Force
        $node | Add-Member -MemberType NoteProperty -Name Attributes -Value ([IO.FileAttributes]0) -Force
        $parentKey=if ($Directory) {'Parent'} else {'Directory'}
        $node | Add-Member -MemberType NoteProperty -Name $parentKey -Value $Parent -Force
        foreach ($key in @('FullName','Attributes',$parentKey)) {
            if ($node.PSObject.Properties[$key].MemberType -ne 'NoteProperty') { throw 'Native metadata getter not isolated' }
        }
        ,$node
    }
    function New-InertPathTree {
        $volume=New-InertPathNode 'C:\' $true $null
        $root=New-InertPathNode 'C:\INERT-ROOT' $true $volume
        $ancestor=New-InertPathNode 'C:\INERT-ROOT\branch' $true $root
        $file=New-InertPathNode 'C:\INERT-ROOT\branch\file.bin' $false $ancestor
        $directory=New-InertPathNode 'C:\INERT-ROOT\branch\child' $true $ancestor
        @{volume=$volume;root=$root;ancestor=$ancestor;file=$file;directory=$directory}
    }
    $state=@{node=$null;fault='';calls=0}
    $getter={param($LiteralPath,[switch]$Force,$ErrorAction)
        $state.calls++
        if ($state.fault) {
            throw [Management.Automation.ErrorRecord]::new([IO.IOException]::new($state.fault),
                $state.fault,[Management.Automation.ErrorCategory]::ReadError,$null)
        }
        $state.node
    }.GetNewClosure()
    Set-Item -LiteralPath 'Function:script:Get-Item' -Value $getter
    try {
        foreach ($leaf in @('file','directory')) {
            $tree=New-InertPathTree; $state.node=$tree[$leaf]
            Assert-FixedPath $state.node.FullName 'C:\INERT-ROOT'
        }
        foreach ($level in @('file','directory','ancestor','root','volume')) {
            $tree=New-InertPathTree
            $state.node=if ($level -eq 'directory') {$tree.directory} else {$tree.file}
            $tree[$level].Attributes=[IO.FileAttributes]::ReparsePoint
            $rejected=$false
            try { Assert-FixedPath $state.node.FullName 'C:\INERT-ROOT' } catch {
                if ($_.Exception.Message -cne 'STOP: reparse ancestor') { throw }
                $rejected=$true
            }
            if (-not $rejected) { throw "Reparse level accepted: $level" }
        }
        foreach ($fault in @('FixtureMetadataMissing','FixtureMetadataDenied')) {
            $state.fault=$fault; $rejected=$false
            try { Assert-FixedPath 'C:\INERT-ROOT\branch\file.bin' 'C:\INERT-ROOT' } catch {
                if (($_.FullyQualifiedErrorId -split ',')[0] -cne $fault) { throw }
                $rejected=$true
            }
            if (-not $rejected) { throw 'Original metadata error swallowed' }
        }
        $state.fault=''; $state.node=$null
        $rejected=$false
        try { Assert-FixedPath 'C:\INERT-ROOT\branch\file.bin' 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -cne 'STOP: absent path metadata') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Null metadata accepted' }
        $tree=New-InertPathTree; $state.node=$tree.file
        $tree.file | Add-Member -MemberType ScriptProperty -Name Attributes -Value {throw [IO.IOException]::new('INERT_ATTRIBUTE_DENIED')} -Force
        $rejected=$false
        try { Assert-FixedPath $tree.file.FullName 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -notlike '*INERT_ATTRIBUTE_DENIED*') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Attribute metadata error swallowed' }
        foreach ($field in @('FullName','Directory','Parent')) {
            $tree=New-InertPathTree; $state.node=$tree.file
            $target=if ($field -eq 'Parent') {$tree.ancestor} else {$tree.file}
            $failureText='INERT_'+$field+'_DENIED'
            $propertyFailure={throw [IO.IOException]::new($failureText)}.GetNewClosure()
            $target | Add-Member -MemberType ScriptProperty -Name $field -Value $propertyFailure -Force
            $rejected=$false
            try { Assert-FixedPath 'C:\INERT-ROOT\branch\file.bin' 'C:\INERT-ROOT' } catch {
                if ($_.Exception.Message -notlike ('*'+$failureText+'*')) { throw }
                $rejected=$true
            }
            if (-not $rejected) { throw 'Original metadata getter error swallowed' }
        }
        $tree=New-InertPathTree; $state.node=$tree.file; $tree.file.Attributes=$null
        $rejected=$false
        try { Assert-FixedPath 'C:\INERT-ROOT\branch\file.bin' 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -cne 'STOP: invalid path metadata') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Null metadata attributes accepted' }
        foreach ($kind in @('file','ancestor')) {
            $tree=New-InertPathTree; $state.node=$tree.file
            if ($kind -eq 'file') {$tree.file.Directory=$null} else {$tree.ancestor.Parent=$null}
            $rejected=$false
            try { Assert-FixedPath $tree.file.FullName 'C:\INERT-ROOT' } catch {
                if ($_.Exception.Message -cne 'STOP: unexpected path termination') { throw }
                $rejected=$true
            }
            if (-not $rejected) { throw 'Premature parent termination accepted' }
        }
        $tree=New-InertPathTree; $state.node=$tree.file; $tree.volume.Parent=$tree.ancestor
        $rejected=$false
        try { Assert-FixedPath $tree.file.FullName 'C:\INERT-ROOT' } catch {
            if ($_.Exception.Message -cne 'STOP: cyclic/deep path metadata') { throw }
            $rejected=$true
        }
        if (-not $rejected) { throw 'Cyclic metadata accepted' }
        foreach ($path in @('', 'relative.bin','C:relative.bin','C:\INERT-ROOT\..\escape.bin',
            'C:\OTHER\file.bin','\\server\share\file.bin','\\.\C:\file.bin','\\?\C:\file.bin',
            'C:\INERT-ROOT\file.bin:stream','C:\INERT-ROOT\NUL','C:\INERT-ROOT\con.txt',
            'C:\INERT-ROOT\COM1','C:\INERT-ROOT\LPT¹','C:\INERT-ROOT\CONIN$',
            'C:\INERT-ROOT\file.','C:\INERT-ROOT\file ','C:\INERT-ROOT\wild?card')) {
            $state.calls=0; $rejected=$false
            try { Assert-FixedPath $path 'C:\INERT-ROOT' } catch { $rejected=$true }
            if (-not $rejected -or $state.calls) { throw 'Invalid path reached metadata seam' }
        }
        foreach ($path in @('C:\wamp64\www','C:\wamp64\www\file.bin',
            (Join-Path $env:USERPROFILE '.codex\file.bin'),(Join-Path $env:USERPROFILE '.syndicatum\file.bin'))) {
            $state.calls=0; $rejected=$false
            try { Assert-FixedPath $path ([IO.Path]::GetDirectoryName($path)) } catch {
                if ($_.Exception.Message -cne 'STOP: live storage overlap') { throw }
                $rejected=$true
            }
            if (-not $rejected -or $state.calls) { throw 'Live path reached metadata seam' }
        }
    } finally { Remove-Item -LiteralPath 'Function:script:Get-Item' }
}
'Actual path helper: file/directory through volume root, errors/null/reparse/cycles and forbidden paths PASS with inert metadata only.'
