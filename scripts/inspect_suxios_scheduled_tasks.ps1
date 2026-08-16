[CmdletBinding()]
param(
    [string]$ProjectRoot = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)

$receiptPrefix = 'SUXIOS_SCHEDULED_LOOPS='
$receipt = [ordered]@{
    schema_version = 'suxios_scheduled_loops_raw.v1'
    status = 'unavailable'
    reason_code = 'read_not_started'
    source = 'windows_task_scheduler'
    observed_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    readback_verified = $false
    failed_count = 0
    items = @()
    sensitive_values_exposed = $false
}

function Write-ScheduledLoopReceipt {
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Value)

    Write-Output ($receiptPrefix + ($Value | ConvertTo-Json -Depth 8 -Compress))
}

function Convert-TaskDateTime {
    param([AllowNull()][object]$Value)

    if ($null -eq $Value) {
        return $null
    }
    $date = [datetime]$Value
    if ($date.Year -le 1900) {
        return $null
    }
    return $date.ToString('yyyy-MM-dd HH:mm:ss')
}

function Get-TaskPropertyValue {
    param(
        [AllowNull()][object]$InputObject,
        [Parameter(Mandatory = $true)][string]$Name
    )

    if ($null -eq $InputObject) {
        return $null
    }
    $property = $InputObject.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $null
    }
    return $property.Value
}

try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $ProjectRoot = Split-Path -Parent $PSScriptRoot
    }
    $resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    $projectPattern = [regex]::Escape($resolvedProjectRoot)
    $rows = [System.Collections.Generic.List[object]]::new()

    foreach ($task in @(Get-ScheduledTask -ErrorAction Stop)) {
        $actionText = @($task.Actions | ForEach-Object {
            '{0} {1} {2}' -f `
                ([string](Get-TaskPropertyValue -InputObject $_ -Name 'Execute')), `
                ([string](Get-TaskPropertyValue -InputObject $_ -Name 'Arguments')), `
                ([string](Get-TaskPropertyValue -InputObject $_ -Name 'WorkingDirectory'))
        }) -join ' '
        $isSuxiosTask = ([string]$task.TaskName) -like 'SUXIOS*' `
            -or ([string]$task.TaskName) -like 'Dingdandao H*' `
            -or ([string]$task.TaskPath) -eq '\SUXIOS\' `
            -or $actionText -match $projectPattern
        if (-not $isSuxiosTask) {
            continue
        }

        $periodicTriggers = @($task.Triggers | Where-Object {
            $triggerType = [string]$_.CimClass.CimClassName
            $repetition = Get-TaskPropertyValue -InputObject $_ -Name 'Repetition'
            $repetitionInterval = Get-TaskPropertyValue -InputObject $repetition -Name 'Interval'
            -not [string]::IsNullOrWhiteSpace([string]$repetitionInterval) `
                -or $triggerType -match 'DailyTrigger|WeeklyTrigger|MonthlyTrigger'
        })
        if ($periodicTriggers.Count -eq 0) {
            continue
        }

        $triggerRows = @($periodicTriggers | ForEach-Object {
            $repetition = Get-TaskPropertyValue -InputObject $_ -Name 'Repetition'
            [ordered]@{
                type = [string]$_.CimClass.CimClassName
                start_boundary = [string](Get-TaskPropertyValue -InputObject $_ -Name 'StartBoundary')
                days_interval = [string](Get-TaskPropertyValue -InputObject $_ -Name 'DaysInterval')
                weeks_interval = [string](Get-TaskPropertyValue -InputObject $_ -Name 'WeeksInterval')
                repetition_interval = [string](Get-TaskPropertyValue -InputObject $repetition -Name 'Interval')
                repetition_duration = [string](Get-TaskPropertyValue -InputObject $repetition -Name 'Duration')
            }
        })
        $actionFiles = @($task.Actions | ForEach-Object {
            $scriptFile = ''
            $arguments = [string](Get-TaskPropertyValue -InputObject $_ -Name 'Arguments')
            $execute = [string](Get-TaskPropertyValue -InputObject $_ -Name 'Execute')
            if ($arguments -match '(?i)-File\s+"?([^"\s]+\.(?:ps1|php|mjs|js))') {
                $scriptFile = [System.IO.Path]::GetFileName([string]$matches[1])
            }
            [ordered]@{
                executable = if ($execute -ne '') { [System.IO.Path]::GetFileName($execute) } else { '' }
                script = $scriptFile
            }
        })

        try {
            $info = Get-ScheduledTaskInfo -TaskName $task.TaskName -TaskPath $task.TaskPath -ErrorAction Stop
            $rows.Add([ordered]@{
                task_path = [string]$task.TaskPath
                task_name = [string]$task.TaskName
                enabled = [bool](Get-TaskPropertyValue -InputObject $task.Settings -Name 'Enabled')
                state = [string]$task.State
                logon_type = [string](Get-TaskPropertyValue -InputObject $task.Principal -Name 'LogonType')
                last_run_at = Convert-TaskDateTime -Value $info.LastRunTime
                next_run_at = Convert-TaskDateTime -Value $info.NextRunTime
                last_result = [int64]$info.LastTaskResult
                triggers = $triggerRows
                actions = $actionFiles
                readback_verified = $true
            })
        } catch {
            $receipt.failed_count = [int]$receipt.failed_count + 1
            $rows.Add([ordered]@{
                task_path = [string]$task.TaskPath
                task_name = [string]$task.TaskName
                enabled = [bool](Get-TaskPropertyValue -InputObject $task.Settings -Name 'Enabled')
                state = [string]$task.State
                logon_type = [string](Get-TaskPropertyValue -InputObject $task.Principal -Name 'LogonType')
                last_run_at = $null
                next_run_at = $null
                last_result = $null
                triggers = $triggerRows
                actions = $actionFiles
                readback_verified = $false
            })
        }
    }

    $receipt.items = @($rows | Sort-Object task_path, task_name)
    $receipt.status = if ([int]$receipt.failed_count -gt 0) { 'partial' } else { 'ready' }
    $receipt.reason_code = if ([int]$receipt.failed_count -gt 0) { 'scheduled_task_info_partial' } else { $null }
    $receipt.readback_verified = [int]$receipt.failed_count -eq 0
} catch {
    $receipt.status = 'unavailable'
    $receipt.reason_code = 'windows_task_scheduler_read_failed'
    $receipt.readback_verified = $false
    $receipt.items = @()
}

Write-ScheduledLoopReceipt -Value $receipt
exit 0
