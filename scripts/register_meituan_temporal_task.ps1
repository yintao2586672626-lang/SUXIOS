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
$runnerPath = if ($null -ne $resolvedRoot) {
    Join-Path $resolvedRoot 'scripts\run_meituan_temporal_refresh.php'
} else {
    ''
}
$resolvedRunner = if ($runnerPath -ne '' -and (Test-Path -LiteralPath $runnerPath -PathType Leaf)) {
    (Resolve-Path -LiteralPath $runnerPath).Path
} else {
    $null
}
$taskName = "SUXIOS Meituan Temporal H$HotelId"
$taskPath = '\'
$triggerTimes = @('09:15', '13:00', '17:00', '21:00')
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
    [pscustomobject]@{ name = 'runner'; passed = $null -ne $resolvedRunner; detail = $runnerPath },
    [pscustomobject]@{ name = 'interactive_profile_user'; passed = $interactiveUser; detail = $RunAsUser },
    [pscustomobject]@{ name = 'credential_free_arguments'; passed = $arguments -notmatch '(?i)(cookie|token|password|authorization|secret|session)'; detail = 'hotel and actor ids only' }
)
$failures = @($preflight | Where-Object { -not $_.passed })
$mutationRequested = [bool]($RegisterDisabled -or $Enable)
$mode = if ($Enable) { 'enable' } elseif ($RegisterDisabled) { 'register_disabled' } else { 'plan' }
$plan = [ordered]@{
    schema_version = 1
    mode = $mode
    mutation_requested = $mutationRequested
    task = [ordered]@{
        name = $taskName
        exists = $null -ne $existing
        trigger_times = $triggerTimes
        timezone = 'Asia/Shanghai'
        multiple_instances = 'IgnoreNew'
        enabled_after_registration = [bool]$Enable
    }
    action = [ordered]@{
        execute = $resolvedPhp
        arguments = $arguments
        working_directory = $resolvedRoot
    }
    scope = [ordered]@{
        system_hotel_id = $HotelId
        actor_user_id = $ActorUserId
        platform = 'meituan'
        external_delivery = $false
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

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", "Register Meituan temporal task (enabled=$Enable)")) {
    $action = New-ScheduledTaskAction `
        -Execute $resolvedPhp `
        -Argument $arguments `
        -WorkingDirectory $resolvedRoot
    $triggers = @($triggerTimes | ForEach-Object { New-ScheduledTaskTrigger -Daily -At $_ })
    $principal = New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 15) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $parameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $action
        Trigger = $triggers
        Principal = $principal
        Settings = $settings
        Description = "Hotel $HotelId only: collect Meituan today, yesterday review, and future signals without external delivery."
    }
    if ($null -ne $existing) {
        $parameters['Force'] = $true
    }
    Register-ScheduledTask @parameters | Out-Null
    if (-not $Enable) {
        Disable-ScheduledTask -TaskName $taskName -TaskPath $taskPath | Out-Null
    }
    $plan['result'] = if ($Enable) { 'registered_enabled_not_started' } else { 'registered_disabled' }
    Write-Output ($plan | ConvertTo-Json -Depth 8)
}
