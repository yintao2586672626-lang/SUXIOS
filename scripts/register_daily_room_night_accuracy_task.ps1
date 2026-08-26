[CmdletBinding(SupportsShouldProcess = $true, DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'RegisterDisabled')]
    [switch]$RegisterDisabled,

    [Parameter(Mandatory = $true, ParameterSetName = 'Enable')]
    [switch]$Enable,

    [Parameter(ParameterSetName = 'RegisterDisabled')]
    [Parameter(ParameterSetName = 'Enable')]
    [switch]$ReplaceExisting,

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidateRange(1, 2147483647)]
    [int]$ActorUserId = 155,

    [ValidatePattern('^([01]\d|2[0-3]):[0-5]\d$')]
    [string]$DailyAt = '21:30',

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedRoot = if (Test-Path -LiteralPath $ProjectRoot -PathType Container) {
    (Resolve-Path -LiteralPath $ProjectRoot).Path
} else {
    $null
}
$resolvedPhp = if (Test-Path -LiteralPath $PhpPath -PathType Leaf) {
    (Resolve-Path -LiteralPath $PhpPath).Path
} else {
    $null
}
$phpWindowlessPath = if ($null -ne $resolvedPhp) {
    Join-Path (Split-Path -Parent $resolvedPhp) 'php-win.exe'
} else {
    ''
}
$resolvedPhpWindowless = if ($phpWindowlessPath -ne '' -and (Test-Path -LiteralPath $phpWindowlessPath -PathType Leaf)) {
    (Resolve-Path -LiteralPath $phpWindowlessPath).Path
} else {
    $null
}
$runnerPath = if ($null -ne $resolvedRoot) {
    Join-Path $resolvedRoot 'scripts\run_daily_room_night_accuracy.php'
} else {
    ''
}
$resolvedRunner = if ($runnerPath -ne '' -and (Test-Path -LiteralPath $runnerPath -PathType Leaf)) {
    (Resolve-Path -LiteralPath $runnerPath).Path
} else {
    $null
}
$taskName = "SUXIOS Daily T1 OTA Room Nights H$HotelId"
$taskPath = '\'
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$interactiveUser = [Environment]::UserInteractive `
    -and [System.Diagnostics.Process]::GetCurrentProcess().SessionId -gt 0 `
    -and $RunAsUser.Trim().ToUpperInvariant() -eq $currentUser.Trim().ToUpperInvariant() `
    -and $RunAsUser -notmatch '^(NT AUTHORITY|NT SERVICE|BUILTIN)\\'
$arguments = '"{0}" --hotel-id={1} --user-id={2}' -f $resolvedRunner, $HotelId, $ActorUserId
$existing = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedRoot; detail = $ProjectRoot },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhp; detail = $PhpPath },
    [pscustomobject]@{ name = 'php_windowless_binary'; passed = $null -ne $resolvedPhpWindowless; detail = $phpWindowlessPath },
    [pscustomobject]@{ name = 'runner'; passed = $null -ne $resolvedRunner; detail = $runnerPath },
    [pscustomobject]@{ name = 'interactive_profile_user'; passed = $interactiveUser; detail = $RunAsUser },
    [pscustomobject]@{ name = 'credential_free_arguments'; passed = $arguments -notmatch '(?i)(cookie|token|password|authorization|secret|session)'; detail = 'hotel and actor ids only' }
)
$failures = @($preflight | Where-Object { -not $_.passed })
$mutationRequested = [bool]($RegisterDisabled -or $Enable)
$mode = if ($Enable) { 'enable' } elseif ($RegisterDisabled) { 'register_disabled' } else { 'plan' }
$plan = [ordered]@{
    schema_version = 'daily_room_night_accuracy_task.v1'
    mode = $mode
    mutation_requested = $mutationRequested
    task = [ordered]@{
        name = $taskName
        exists = $null -ne $existing
        trigger_time = $DailyAt
        timezone = 'Asia/Shanghai'
        multiple_instances = 'IgnoreNew'
        enabled_after_registration = [bool]$Enable
    }
    action = [ordered]@{
        execute = $resolvedPhpWindowless
        arguments = $arguments
        working_directory = $resolvedRoot
        visible_window_expected = $false
    }
    scope = [ordered]@{
        system_hotel_id = $HotelId
        actor_user_id = $ActorUserId
        metric_scope = 'ota_channel'
        metric_key = 'ota_room_nights'
        horizon_days = 1
        external_action = $false
        automatic_price_write = $false
    }
    preflight = $preflight
    ready = $failures.Count -eq 0 -and ($null -eq $existing -or $ReplaceExisting)
}

if (-not $mutationRequested) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    return
}
if ($failures.Count -gt 0) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw ('Registration refused: ' + (($failures | ForEach-Object { $_.name }) -join ', '))
}
if ($null -ne $existing -and -not $ReplaceExisting) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw 'Task already exists. Use -ReplaceExisting after reviewing the plan.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", "Register daily T+1 OTA room-night accuracy task (enabled=$Enable)")) {
    $action = New-ScheduledTaskAction `
        -Execute $resolvedPhpWindowless `
        -Argument $arguments `
        -WorkingDirectory $resolvedRoot
    $trigger = New-ScheduledTaskTrigger -Daily -At $DailyAt
    $principal = New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -Hidden `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $parameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $action
        Trigger = $trigger
        Principal = $principal
        Settings = $settings
        Description = "Hotel $HotelId only: persist and read back one T+1 all-OTA room-night forecast, then observe the prior actual without price or OTA writes."
    }
    if ($null -ne $existing) {
        $parameters['Force'] = $true
    }
    Register-ScheduledTask @parameters | Out-Null
    if (-not $Enable) {
        Disable-ScheduledTask -TaskName $taskName -TaskPath $taskPath | Out-Null
    }

    $registered = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
    $registeredInfo = Get-ScheduledTaskInfo -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
    $actionReadback = @($registered.Actions)[0]
    $triggerReadback = @($registered.Triggers)[0]
    $enabledReadback = [bool]$registered.Settings.Enabled
    $readbackVerified = $null -ne $registered `
        -and [string]$actionReadback.Execute -eq [string]$resolvedPhpWindowless `
        -and [string]$actionReadback.Arguments -eq [string]$arguments `
        -and $enabledReadback -eq [bool]$Enable
    if (-not $readbackVerified) {
        throw 'Scheduled task registration readback mismatch.'
    }
    $plan['result'] = if ($Enable) { 'registered_enabled_not_started' } else { 'registered_disabled' }
    $plan['readback'] = [ordered]@{
        verified = $readbackVerified
        enabled = $enabledReadback
        state = [string]$registered.State
        next_run_time = $registeredInfo.NextRunTime
        trigger_start_boundary = [string]$triggerReadback.StartBoundary
        execute = [string]$actionReadback.Execute
        arguments_match = [string]$actionReadback.Arguments -eq [string]$arguments
    }
    Write-Output ($plan | ConvertTo-Json -Depth 8)
}
