param(
    [string]$TaskName = 'SUXIOS Operating Goal Monitor',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$ProjectPath = '',
    [int]$HotelId = 0,
    [int]$HotelLimit = 100,
    [int]$IntervalMinutes = 30
)

$ErrorActionPreference = 'Stop'
$ProjectPath = if ($ProjectPath -eq '') { Split-Path -Parent $PSScriptRoot } else { $ProjectPath }
$ProjectPath = (Resolve-Path -LiteralPath $ProjectPath).Path
$RunnerPath = Join-Path $ProjectPath 'scripts\run_operating_goal_monitor.ps1'

if ($TaskName.Trim() -eq '') {
    throw 'TaskName must not be empty.'
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP executable was not found: $PhpPath"
}
if (-not (Test-Path -LiteralPath $RunnerPath -PathType Leaf)) {
    throw "Operating goal monitor runner was not found: $RunnerPath"
}
if ($HotelId -lt 0 -or $HotelLimit -lt 1 -or $HotelLimit -gt 500) {
    throw 'HotelId must be zero or positive and HotelLimit must be 1..500.'
}
if ($IntervalMinutes -lt 5 -or $IntervalMinutes -gt 1440) {
    throw 'IntervalMinutes must be 5..1440.'
}

$PowerShellPath = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$TaskArguments = '-NoProfile -ExecutionPolicy Bypass -File "' + $RunnerPath + '"'
$TaskArguments += ' -PhpPath "' + $PhpPath + '"'
$TaskArguments += ' -ProjectPath "' + $ProjectPath + '"'
$TaskArguments += ' -HotelId ' + $HotelId
$TaskArguments += ' -HotelLimit ' + $HotelLimit
$StartTime = (Get-Date).AddMinutes(2)

# schtasks.exe truncates long /TR values on some Windows builds. The project
# path is intentionally explicit and may contain non-ASCII characters, so use
# the ScheduledTasks API and verify the exact action readback instead.
$Action = New-ScheduledTaskAction `
    -Execute $PowerShellPath `
    -Argument $TaskArguments `
    -WorkingDirectory $ProjectPath
$Trigger = New-ScheduledTaskTrigger `
    -Once `
    -At $StartTime `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)
$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $Action `
    -Trigger $Trigger `
    -Settings $Settings `
    -Description 'SUXIOS verified-data operating goal monitor; local alerts only, never writes OTA.' `
    -Force | Out-Null

$Task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
$Info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction Stop
$RegisteredAction = $Task.Actions | Select-Object -First 1
if ([string]$RegisteredAction.Execute -ne $PowerShellPath `
    -or [string]$RegisteredAction.Arguments -ne $TaskArguments `
    -or [string]$RegisteredAction.WorkingDirectory -ne $ProjectPath
) {
    throw 'Operating goal monitor task action readback did not match the requested command.'
}
[pscustomobject]@{
    TaskName = $Task.TaskName
    State = [string]$Task.State
    NextRunTime = $Info.NextRunTime
    LastRunTime = $Info.LastRunTime
    LastTaskResult = $Info.LastTaskResult
    IntervalMinutes = $IntervalMinutes
    HotelId = $HotelId
    HotelLimit = $HotelLimit
    AutoWriteOta = $false
    ActionReadbackVerified = $true
}
