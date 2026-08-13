[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$RunAsUser = "$env:USERDOMAIN\$env:USERNAME",
    [switch]$Enable,
    [switch]$ReplaceExisting
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function New-RepetitionPattern {
    return New-CimInstance `
        -Namespace 'Root/Microsoft/Windows/TaskScheduler' `
        -ClassName 'MSFT_TaskRepetitionPattern' `
        -ClientOnly `
        -Property @{
            Interval = 'PT30M'
            Duration = 'P1D'
            StopAtDurationEnd = $false
        }
}

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$runner = Join-Path $resolvedRoot 'scripts\run_hotel80_three_source_wecom.ps1'
if (-not (Test-Path -LiteralPath $runner -PathType Leaf)) {
    throw 'three_source_wecom_runner_missing'
}
if ($RunAsUser -match '(?i)(?:^|\\)(SYSTEM|LOCAL SERVICE|NETWORK SERVICE)$') {
    throw 'three_source_wecom_interactive_user_required'
}

$taskName = 'SUXIOS Three Source WeCom H80'
$taskPath = '\SUXIOS\'
$powershellPath = (Get-Command powershell.exe -CommandType Application -ErrorAction Stop).Source
$arguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}"' -f $runner, $resolvedRoot, $resolvedPhp
if ($arguments -match '(?i)(cookie|token|secret|password|authorization|webhook)\s*[=:]') {
    throw 'three_source_wecom_task_arguments_sensitive'
}

$now = Get-Date
$nextBoundary = Get-Date -Date $now.Date.AddHours($now.Hour)
if ($now.Minute -lt 30) {
    $nextBoundary = $nextBoundary.AddMinutes(30)
} else {
    $nextBoundary = $nextBoundary.AddHours(1)
}
$existing = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
$plan = [ordered]@{
    schema_version = 1
    mode = if ($Enable) { 'enable' } else { 'plan' }
    mutation_requested = [bool]$Enable
    task = [ordered]@{
        name = $taskName
        path = $taskPath
        exists = $null -ne $existing
        start_boundary = $nextBoundary.ToString('o')
        interval = 'PT30M'
        duration = 'P1D'
        execution_time_limit = 'PT28M'
        multiple_instances = 'IgnoreNew'
        wake_to_run = $true
        start_when_available = $true
        logon_type = 'Interactive'
    }
    scope = [ordered]@{
        tenant_id = 80
        hotel_id = 80
        robot_id = 1
        sources = @('dingdandao_pms', 'ctrip:25', 'meituan:68')
        business_date_rule = 'today'
    }
    action = [ordered]@{
        execute = $powershellPath
        arguments = $arguments
        working_directory = $resolvedRoot
    }
    sensitive_values_exposed = $false
}

if (-not $Enable) {
    $plan | ConvertTo-Json -Depth 7
    exit 0
}
if ($null -ne $existing -and -not $ReplaceExisting) {
    throw 'three_source_wecom_task_exists_use_replace_existing'
}

$action = New-ScheduledTaskAction `
    -Execute $powershellPath `
    -Argument $arguments `
    -WorkingDirectory $resolvedRoot
$trigger = New-ScheduledTaskTrigger -Daily -At $nextBoundary
$trigger.Repetition = New-RepetitionPattern
$principal = New-ScheduledTaskPrincipal `
    -UserId $RunAsUser `
    -LogonType Interactive `
    -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -WakeToRun `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 28)
$task = New-ScheduledTask `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Hotel 80 same-day Dingdandao PMS + Ctrip 25 + Meituan 68 gated WeCom delivery every 30 minutes.'
Register-ScheduledTask `
    -TaskName $taskName `
    -TaskPath $taskPath `
    -InputObject $task `
    -Force | Out-Null

$registered = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
$info = Get-ScheduledTaskInfo -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
$plan.task.exists = $true
$plan.task.state = [string]$registered.State
$plan.task.enabled = [bool]$registered.Settings.Enabled
$plan.task.next_run_time = $info.NextRunTime.ToString('o')
$plan.task.last_run_time = $info.LastRunTime.ToString('o')
$plan.task.last_result = [int]$info.LastTaskResult
$plan | ConvertTo-Json -Depth 7
