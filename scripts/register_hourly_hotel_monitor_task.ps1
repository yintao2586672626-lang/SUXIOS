param(
    [int]$HotelId = 80,
    [int]$TestRobotId = 1,
    [string]$TaskName = 'SUXIOS-Hotel80-TestMonitor',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$ProjectPath = ''
)

$ErrorActionPreference = 'Stop'
$ProjectPath = if ($ProjectPath -eq '') { Split-Path -Parent $PSScriptRoot } else { $ProjectPath }
if ($HotelId -le 0 -or $TestRobotId -le 0) {
    throw 'HotelId and TestRobotId must be positive integers.'
}
if (-not (Test-Path -LiteralPath $PhpPath)) {
    throw "PHP executable was not found: $PhpPath"
}
if (-not (Test-Path -LiteralPath (Join-Path $ProjectPath 'think'))) {
    throw "SUXIOS project entry was not found: $ProjectPath"
}

# The command itself rejects a robot unless its stored name contains “测试”.
# This task therefore cannot silently switch to a formal group.
$startTime = (Get-Date).AddHours(1).ToString('HH:mm')
$RunnerPath = Join-Path $ProjectPath 'scripts\run_hourly_hotel_monitor_test.ps1'
if (-not (Test-Path -LiteralPath $RunnerPath)) {
    throw "Hourly monitor runner was not found: $RunnerPath"
}
$taskCommand = '"C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "' + $RunnerPath + '" -HotelId ' + $HotelId + ' -TestRobotId ' + $TestRobotId + ' -PhpPath "' + $PhpPath + '"'

# schtasks' HOURLY schedule is continuous; unlike a one-time trigger it does
# not stop after one calendar day. The task runs only while this user is logged
# in, which matches the current local test-group boundary.
& schtasks.exe /Create /TN $TaskName /TR $taskCommand /SC HOURLY /MO 1 /ST $startTime /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Failed to create the hourly SUXIOS test monitor task.'
}
Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName,State
