# Source-only native collectors. Dot-sourcing defines functions but performs no
# collection, process control, registry/task mutation or installed-root writes.
Set-StrictMode -Version Latest

function Get-PilotSha256 {
    param([Parameter(Mandatory)][string]$LiteralPath)
    (Get-FileHash -LiteralPath $LiteralPath -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
}

function Read-PilotReviewedPlan {
    param([Parameter(Mandatory)][string]$LiteralPath,
          [Parameter(Mandatory)][ValidatePattern('^[0-9a-f]{64}$')][string]$ReviewedSha256)
    $taskPlanFile = Get-Item -LiteralPath $LiteralPath -Force -ErrorAction Stop
    if ($taskPlanFile.PSIsContainer -or ($taskPlanFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $taskPlanFile.Length -gt 1MB) {
        throw 'STOP: invalid reviewed plan file.'
    }
    # Hold one handle with no write/delete sharing; parse the same anchored bytes.
    $taskPlanStream = [IO.File]::Open($taskPlanFile.FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
    try {
        $taskPlanBytes = [byte[]]::new([int]$taskPlanStream.Length)
        $taskPlanStream.ReadExactly($taskPlanBytes)
        $taskHasher = [Security.Cryptography.SHA256]::Create()
        try { $taskActualHash = [Convert]::ToHexString($taskHasher.ComputeHash($taskPlanBytes)).ToLowerInvariant() }
        finally { $taskHasher.Dispose() }
        if ($taskActualHash -cne $ReviewedSha256) { throw 'STOP: reviewed plan drift.' }
        # Strict duplicate-property/schema validation remains in the Python
        # anchored contract. The future adapter must run that before collection.
        [Text.Encoding]::UTF8.GetString($taskPlanBytes) | ConvertFrom-Json -AsHashtable -ErrorAction Stop
    } finally { $taskPlanStream.Dispose() }
}

function Get-PilotRunSnapshot {
    param([Parameter(Mandatory)][ValidateSet('Registry32','Registry64')][string]$View)
    $taskRegistryView = [Microsoft.Win32.RegistryView]::$View
    $taskBase = [Microsoft.Win32.RegistryKey]::OpenBaseKey([Microsoft.Win32.RegistryHive]::CurrentUser, $taskRegistryView)
    try {
        $taskKey = $taskBase.OpenSubKey('Software\Microsoft\Windows\CurrentVersion\Run', $false)
        if ($null -eq $taskKey) { return @() }
        try {
            @($taskKey.GetValueNames() | Sort-Object -CaseSensitive | ForEach-Object {
                [pscustomobject]@{
                    hive = 'HKCU'; key = 'Software\Microsoft\Windows\CurrentVersion\Run'; view = $View
                    name = $_; kind = $taskKey.GetValueKind($_).ToString()
                    raw_command = $taskKey.GetValue($_, $null, [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames)
                }
            })
        } finally { $taskKey.Dispose() }
    } finally { $taskBase.Dispose() }
}

function Get-PilotTaskSnapshot {
    param([Parameter(Mandatory)][string]$ExactTaskName,
          [Parameter(Mandatory)][string]$ExactTaskPath)
    $taskMatches = @(Get-ScheduledTask -ErrorAction Stop | Where-Object {
        $_.TaskName -ceq $ExactTaskName -and $_.TaskPath -ceq $ExactTaskPath
    })
    if ($taskMatches.Count -gt 1) { throw 'STOP: duplicate exact task identity.' }
    if ($taskMatches.Count -eq 0) { return $null }
    $taskObject = $taskMatches[0]
    $taskXml = Export-ScheduledTask -TaskName $ExactTaskName -TaskPath $ExactTaskPath -ErrorAction Stop
    $taskPrincipalSid = ([Security.Principal.NTAccount]::new($taskObject.Principal.UserId)).Translate([Security.Principal.SecurityIdentifier]).Value
    $taskTriggerSids = @($taskObject.Triggers | ForEach-Object {
        if ($_.CimClass.CimClassName -ne 'MSFT_TaskLogonTrigger' -or -not $_.UserId) {
            throw 'STOP: unexpected task trigger.'
        }
        ([Security.Principal.NTAccount]::new($_.UserId)).Translate([Security.Principal.SecurityIdentifier]).Value
    })
    [pscustomobject]@{
        name = $taskObject.TaskName; path = $taskObject.TaskPath; xml = $taskXml
        state = $taskObject.State.ToString(); principal_sid = $taskPrincipalSid
        trigger_sids = $taskTriggerSids; current_sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        actions = @($taskObject.Actions | ForEach-Object {
            [pscustomobject]@{ executable = $_.Execute; arguments = $_.Arguments; working_directory = $_.WorkingDirectory }
        })
    }
}

function Get-PilotProcessSnapshot {
    # Complete private snapshot. Command lines are needed for exact entrypoint
    # comparison, NEVER send this result to console/timeline or public storage.
    @(Get-CimInstance Win32_Process -ErrorAction Stop | ForEach-Object {
        [pscustomobject]@{
            pid = [int]$_.ProcessId; parent_pid = [int]$_.ParentProcessId
            executable = [string]$_.ExecutablePath; command_line = [string]$_.CommandLine
            started = $(if ($_.CreationDate) { $_.CreationDate.ToUniversalTime().ToString('o') } else { $null })
        }
    })
}

function Get-PilotLockSnapshot {
    param([Parameter(Mandatory)][string]$LiteralPath)
    # Absence is distinct from sharing/access/format failure. No unlink/stale-lock
    # recovery and no broad catch that turns failure into apparent absence.
    try { $taskFile = Get-Item -LiteralPath $LiteralPath -Force -ErrorAction Stop }
    catch [System.Management.Automation.ItemNotFoundException] { return [pscustomobject]@{ present = $false } }
    if ($taskFile.PSIsContainer -or ($taskFile.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $taskFile.Length -gt 4096) {
        throw 'STOP: unexpected lock artifact.'
    }
    $taskStream = [IO.File]::Open($taskFile.FullName, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
    try {
        $taskReader = [IO.StreamReader]::new($taskStream)
        try { $taskText = $taskReader.ReadToEnd() }
        finally { $taskReader.Dispose() }
    } finally { $taskStream.Dispose() }
    [pscustomobject]@{ present = $true; raw = $taskText; size = $taskFile.Length; mtime = $taskFile.LastWriteTimeUtc.ToString('o') }
}

function Get-PilotExternalBoundary {
    param([Parameter(Mandatory)][string]$ExistingParent,
          [Parameter(Mandatory)][string[]]$ForbiddenRoots)
    $taskParent = Get-Item -LiteralPath $ExistingParent -Force -ErrorAction Stop
    if (-not $taskParent.PSIsContainer) { throw 'STOP: external parent is not a directory.' }
    $taskCursor = $taskParent
    while ($null -ne $taskCursor) {
        if ($taskCursor.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'STOP: reparse boundary.' }
        $taskCursor = $taskCursor.Parent
    }
    $taskAbsolute = [IO.Path]::GetFullPath($taskParent.FullName).TrimEnd('\')
    if ($taskAbsolute.StartsWith('\\')) { throw 'STOP: network evidence volume.' }
    foreach ($taskForbidden in $ForbiddenRoots) {
        $taskBoundary = [IO.Path]::GetFullPath((Get-Item -LiteralPath $taskForbidden -Force -ErrorAction Stop).FullName).TrimEnd('\')
        if ($taskAbsolute.Equals($taskBoundary, [StringComparison]::OrdinalIgnoreCase) -or
            $taskAbsolute.StartsWith($taskBoundary + '\', [StringComparison]::OrdinalIgnoreCase) -or
            $taskBoundary.StartsWith($taskAbsolute + '\', [StringComparison]::OrdinalIgnoreCase)) {
            throw 'STOP: evidence/installed-root overlap.'
        }
    }
    $taskDrive = [IO.DriveInfo]::new([IO.Path]::GetPathRoot($taskAbsolute))
    if ($taskDrive.DriveType -ne [IO.DriveType]::Fixed -or -not $taskDrive.IsReady) { throw 'STOP: evidence volume not fixed/ready.' }
    $taskAcl = Get-Acl -LiteralPath $taskParent.FullName -ErrorAction Stop
    [pscustomobject]@{
        parent = $taskAbsolute; drive = $taskDrive.Name; format = $taskDrive.DriveFormat
        available_bytes = $taskDrive.AvailableFreeSpace
        sddl = $taskAcl.Sddl; owner = $taskAcl.Owner
        # SID/ACL allow-list validation and post-reservation ACL revalidation are
        # mandatory adapter gates, not inferred from this raw observation.
    }
}
