[CmdletBinding(SupportsShouldProcess = $true, DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Enable')]
    [switch]$Enable,

    [Parameter(ParameterSetName = 'Enable')]
    [switch]$ReplaceExisting,

    [Parameter(Mandatory = $true, ParameterSetName = 'Unregister')]
    [switch]$Unregister,

    [Parameter(ParameterSetName = 'Unregister')]
    [switch]$ConfirmUnregister,

    [string]$KeyPath = 'C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem',
    [string]$Destination = '',
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$hotelRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$runnerPath = (Resolve-Path (Join-Path $PSScriptRoot 'pull_tencent_cloud_backup.ps1')).Path
if ([string]::IsNullOrWhiteSpace($Destination)) {
    $Destination = Join-Path (Split-Path -Parent $hotelRoot) '.private\cloud-backups'
}

$taskName = 'SUXIOS Cloud Backup Pull'
$taskPath = '\'
$powershellPath = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$runAsUserSafe = [Environment]::UserInteractive `
    -and $RunAsUser.Trim().ToUpperInvariant() -eq $currentUser.Trim().ToUpperInvariant() `
    -and $RunAsUser -notmatch '^(NT AUTHORITY|NT SERVICE|BUILTIN)\\'

$preflight = @(
    [pscustomobject]@{ name = 'runner'; passed = (Test-Path -LiteralPath $runnerPath -PathType Leaf); detail = $runnerPath },
    [pscustomobject]@{ name = 'private_key'; passed = (Test-Path -LiteralPath $KeyPath -PathType Leaf); detail = $KeyPath },
    [pscustomobject]@{ name = 'powershell'; passed = (Test-Path -LiteralPath $powershellPath -PathType Leaf); detail = $powershellPath },
    [pscustomobject]@{ name = 'interactive_user'; passed = $runAsUserSafe; detail = $RunAsUser }
)
$failures = @($preflight | Where-Object { -not $_.passed })
$existingTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
$actionArguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -KeyPath "{1}" -Destination "{2}"' -f $runnerPath, $KeyPath, $Destination
$mode = if ($Unregister) { 'unregister' } elseif ($Enable) { 'enable' } else { 'plan' }
$plan = [ordered]@{
    mode = $mode
    task_name = $taskName
    exists = $null -ne $existingTask
    state = if ($null -ne $existingTask) { [string]$existingTask.State } else { 'absent' }
    schedule = 'daily 04:30, start when available'
    destination = $Destination
    starts_immediately = $false
    preflight = $preflight
}

if ($Unregister) {
    if (-not $ConfirmUnregister) {
        Write-Output ($plan | ConvertTo-Json -Depth 6)
        throw 'Unregistration refused. Add -ConfirmUnregister.'
    }
    if ($null -ne $existingTask -and $PSCmdlet.ShouldProcess("$taskPath$taskName", 'Unregister task')) {
        Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
    }
    return
}

if (-not $Enable) {
    Write-Output ($plan | ConvertTo-Json -Depth 6)
    return
}
if ($failures.Count -gt 0) {
    Write-Output ($plan | ConvertTo-Json -Depth 6)
    throw ('Registration refused: ' + (($failures | ForEach-Object { $_.name }) -join ', '))
}
if ($null -ne $existingTask -and -not $ReplaceExisting) {
    Write-Output ($plan | ConvertTo-Json -Depth 6)
    throw 'Task already exists. Use -Enable -ReplaceExisting after review.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Register daily backup pull task without starting it')) {
    $action = New-ScheduledTaskAction -Execute $powershellPath -Argument $actionArguments -WorkingDirectory $hotelRoot
    $trigger = New-ScheduledTaskTrigger -Daily -At '04:30'
    $principal = New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $parameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $action
        Trigger = $trigger
        Principal = $principal
        Settings = $settings
        Description = 'Downloads the newest verified SUXIOS cloud database backup to a private folder outside the Git checkout.'
    }
    if ($null -ne $existingTask) {
        $parameters['Force'] = $true
    }
    Register-ScheduledTask @parameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-Output ($plan | ConvertTo-Json -Depth 6)
}
