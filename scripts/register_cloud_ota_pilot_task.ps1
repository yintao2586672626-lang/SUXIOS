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

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [string]$ProjectRoot = '',

    [string]$BindingPath = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health',

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedProjectRoot = if (Test-Path -LiteralPath $ProjectRoot -PathType Container) {
    (Resolve-Path -LiteralPath $ProjectRoot).Path
} else {
    $null
}
$effectiveProjectRoot = if ($null -ne $resolvedProjectRoot) { $resolvedProjectRoot } else { $ProjectRoot }
if ([string]::IsNullOrWhiteSpace($BindingPath)) {
    $BindingPath = Join-Path $effectiveProjectRoot 'deploy\cloud-data-bridge-binding.pilot-h80.json'
}

$taskName = "SUXIOS Cloud OTA Pilot H$HotelId"
$taskPath = '\'
$runnerPath = Join-Path $effectiveProjectRoot 'scripts\run_cloud_ota_pilot.ps1'
$resolvedRunnerPath = if (Test-Path -LiteralPath $runnerPath -PathType Leaf) { (Resolve-Path -LiteralPath $runnerPath).Path } else { $null }
$resolvedBindingPath = if (Test-Path -LiteralPath $BindingPath -PathType Leaf) { (Resolve-Path -LiteralPath $BindingPath).Path } else { $null }
$resolvedPhpPath = if (Test-Path -LiteralPath $PhpPath -PathType Leaf) { (Resolve-Path -LiteralPath $PhpPath).Path } else { $null }
$powershellPath = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$triggerTimes = @('06:00', '06:15', '06:30')
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

$bindingReady = $false
$bindingSourceIds = @()
if ($null -ne $resolvedBindingPath) {
    try {
        $binding = Get-Content -LiteralPath $resolvedBindingPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $platforms = @($binding.bindings | ForEach-Object { [string]$_.platform } | Sort-Object -Unique)
        $bindingSourceIds = @($binding.bindings | ForEach-Object { [int]$_.source_data_source_id } | Sort-Object -Unique)
        $bindingReady = [string]$binding.contract_version -eq 'suxios.cloud_ota_binding.v1' `
            -and [int]$binding.source_system_hotel_id -eq $HotelId `
            -and [int]$binding.destination_system_hotel_id -gt 0 `
            -and @($binding.bindings).Count -eq 2 `
            -and $bindingSourceIds.Count -eq 2 `
            -and @($bindingSourceIds | Where-Object { $_ -le 0 }).Count -eq 0 `
            -and $platforms.Count -eq 2 `
            -and $platforms -contains 'ctrip' `
            -and $platforms -contains 'meituan'
    } catch {
        $bindingReady = $false
    }
}
$sourceIdsArgument = $bindingSourceIds -join ','

$runAsUserSafe = [Environment]::UserInteractive `
    -and [System.Diagnostics.Process]::GetCurrentProcess().SessionId -gt 0 `
    -and $RunAsUser.Trim().ToUpperInvariant() -eq $currentUser.Trim().ToUpperInvariant() `
    -and $RunAsUser -notmatch '^(NT AUTHORITY|NT SERVICE|BUILTIN)\\' `
    -and $RunAsUser -notmatch '(^|\\)(SYSTEM|LOCAL SYSTEM|LOCAL SERVICE|NETWORK SERVICE)$'

$healthSafe = $false
$healthPassed = $false
$healthDetail = 'health URL boundary failed'
try {
    $healthUri = [System.Uri]$HealthUrl
    $healthSafe = $healthUri.IsAbsoluteUri -and $healthUri.IsLoopback -and $healthUri.AbsolutePath.TrimEnd('/') -eq '/api/health'
    if ($healthSafe) {
        $healthResponse = Invoke-WebRequest -Uri $HealthUrl -Method Get -UseBasicParsing -TimeoutSec 5
        $healthPassed = [int]$healthResponse.StatusCode -ge 200 -and [int]$healthResponse.StatusCode -lt 300
        $healthDetail = 'HTTP ' + [int]$healthResponse.StatusCode
    }
} catch {
    $healthDetail = 'health check failed: ' + $_.Exception.GetType().Name
}

$actionArguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -Run -HotelId {1} -SourceIds "{2}" -BindingPath "{3}" -ProjectRoot "{4}"' -f `
    $resolvedRunnerPath, $HotelId, $sourceIdsArgument, $resolvedBindingPath, $effectiveProjectRoot
$credentialPattern = '(?i)(--?(cookie|token|password|authorization|spidertoken|secret|session|credential)\b|(?:cookie|token|password|authorization|spidertoken|secret|session|credential)\s*=)'
$credentialFreeArguments = $actionArguments -notmatch $credentialPattern

$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedProjectRoot; detail = $effectiveProjectRoot },
    [pscustomobject]@{ name = 'runner_script'; passed = $null -ne $resolvedRunnerPath; detail = $runnerPath },
    [pscustomobject]@{ name = 'binding_file'; passed = $bindingReady; detail = $BindingPath },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhpPath; detail = $PhpPath },
    [pscustomobject]@{ name = 'powershell_binary'; passed = (Test-Path -LiteralPath $powershellPath -PathType Leaf); detail = $powershellPath },
    [pscustomobject]@{ name = 'interactive_user'; passed = $runAsUserSafe; detail = $(if ($runAsUserSafe) { $RunAsUser } else { 'must be the current interactive non-service user' }) },
    [pscustomobject]@{ name = 'health_url_boundary'; passed = $healthSafe; detail = $HealthUrl },
    [pscustomobject]@{ name = 'local_health'; passed = $healthPassed; detail = $healthDetail },
    [pscustomobject]@{ name = 'credential_free_arguments'; passed = $credentialFreeArguments; detail = 'task arguments contain only paths, hotel scope, and the explicit run switch' }
)
$preflightFailures = @($preflight | Where-Object { -not $_.passed })
$existingTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
$mode = if ($Unregister) { 'unregister' } elseif ($Enable) { 'enable' } else { 'plan' }
$plan = [ordered]@{
    schema_version = 1
    mode = $mode
    mutation_requested = [bool]($Enable -or $Unregister)
    task = [ordered]@{
        name = $taskName
        path = $taskPath
        exists = $null -ne $existingTask
        state = if ($null -ne $existingTask) { [string]$existingTask.State } else { 'absent' }
        trigger_times = $triggerTimes
        multiple_instances = 'IgnoreNew'
        execution_time_limit_minutes = 120
    }
    action = [ordered]@{
        execute = $powershellPath
        arguments = $actionArguments
        working_directory = $effectiveProjectRoot
    }
    safety = [ordered]@{
        fixed_hotel_id = $HotelId
        starts_task_immediately = $false
        credentials_in_arguments = $false
        retries_are_separate_daily_triggers = $true
        cloud_daily_has_more_than_90_minutes_after_last_trigger = $true
    }
    preflight = $preflight
    enable_ready = $preflightFailures.Count -eq 0 -and ($null -eq $existingTask -or $ReplaceExisting)
}

if ($Unregister) {
    if (-not $ConfirmUnregister) {
        Write-Output ($plan | ConvertTo-Json -Depth 8)
        throw 'Unregistration refused. Use -Unregister -ConfirmUnregister for the fixed pilot task.'
    }
    if ($null -eq $existingTask) {
        $plan['result'] = 'already_absent'
        Write-Output ($plan | ConvertTo-Json -Depth 8)
        return
    }
    if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Unregister cloud OTA pilot scheduled task')) {
        Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
        $plan['result'] = 'unregistered'
        Write-Output ($plan | ConvertTo-Json -Depth 8)
    }
    return
}

if (-not $Enable) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    return
}
if ($preflightFailures.Count -gt 0) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw ('Registration refused because preflight checks failed: ' + (($preflightFailures | ForEach-Object { $_.name }) -join ', '))
}
if ($null -ne $existingTask -and -not $ReplaceExisting) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw 'Registration refused because the task already exists. Review it and use -Enable -ReplaceExisting to replace it.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Register cloud OTA pilot scheduled task without starting it')) {
    $taskAction = New-ScheduledTaskAction `
        -Execute $powershellPath `
        -Argument $actionArguments `
        -WorkingDirectory $effectiveProjectRoot
    $taskTriggers = @($triggerTimes | ForEach-Object { New-ScheduledTaskTrigger -Daily -At $_ })
    $taskPrincipal = New-ScheduledTaskPrincipal `
        -UserId $RunAsUser `
        -LogonType Interactive `
        -RunLevel Limited
    $taskSettings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $registrationParameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $taskAction
        Trigger = $taskTriggers
        Principal = $taskPrincipal
        Settings = $taskSettings
        Description = "Hotel $HotelId only: local Profile collection, verified credential-free bundle export, and controlled cloud upload."
    }
    if ($null -ne $existingTask) {
        $registrationParameters['Force'] = $true
    }
    Register-ScheduledTask @registrationParameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-Output ($plan | ConvertTo-Json -Depth 8)
}
