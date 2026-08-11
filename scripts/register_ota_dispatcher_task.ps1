[CmdletBinding(SupportsShouldProcess = $true, DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Enable')]
    [switch]$Enable,

    [Parameter(ParameterSetName = 'Enable')]
    [switch]$ReplaceExisting,

    [Parameter(ParameterSetName = 'Enable')]
    [switch]$AllowScopeReduction,

    [switch]$Realtime,

    [ValidateRange(0, 2147483647)]
    [int]$HotelId = 0,

    [ValidatePattern('^(?:|[1-9][0-9]*(?:,[1-9][0-9]*)*)$')]
    [string]$SourceIds = '',

    [ValidateSet('', 'ctrip', 'meituan', 'ctrip,meituan', 'meituan,ctrip')]
    [string]$Platforms = '',

    [Parameter(Mandatory = $true, ParameterSetName = 'Unregister')]
    [switch]$Unregister,

    [Parameter(ParameterSetName = 'Unregister')]
    [switch]$ConfirmUnregister,

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health',

    [ValidatePattern('^(?:[01]\d|2[0-3]):[0-5]\d$')]
    [string]$DailyAt = '08:30',

    [ValidateRange(0, 59)]
    [int]$RealtimeMinute = 5,

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}

$taskBaseName = if ($Realtime) { 'SUXIOS OTA Realtime Dispatcher' } else { 'SUXIOS OTA Dispatcher' }
$taskName = if ($HotelId -gt 0) { "$taskBaseName H$HotelId" } else { $taskBaseName }
$taskPath = '\'
$dispatcherCommand = 'online-data:auto-fetch'
$dispatcherMode = if ($Realtime) { 'Realtime' } else { 'Daily' }
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$realtimePollIntervalMinutes = 15
$dailyRetryOffsetsMinutes = @(0, 14, 28, 42, 56, 70, 84)

function Resolve-ExecutablePath {
    param([Parameter(Mandatory = $true)][string]$Candidate)

    if ([System.IO.Path]::IsPathRooted($Candidate) -or $Candidate.Contains('\') -or $Candidate.Contains('/')) {
        if (-not (Test-Path -LiteralPath $Candidate -PathType Leaf)) {
            return $null
        }
        return (Resolve-Path -LiteralPath $Candidate).Path
    }

    $command = Get-Command -Name $Candidate -CommandType Application -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        return $null
    }
    return $command.Source
}

function New-DispatcherRepetitionPattern {
    param(
        [Parameter(Mandatory = $true)][string]$Interval,
        [Parameter(Mandatory = $true)][string]$Duration
    )

    return New-CimInstance `
        -Namespace Root/Microsoft/Windows/TaskScheduler `
        -ClassName MSFT_TaskRepetitionPattern `
        -ClientOnly `
        -Property @{
            Interval = $Interval
            Duration = $Duration
            StopAtDurationEnd = $false
        }
}

function Test-SafeInteractiveUser {
    param([Parameter(Mandatory = $true)][string]$Account)

    if (-not [Environment]::UserInteractive -or [System.Diagnostics.Process]::GetCurrentProcess().SessionId -le 0) {
        return $false
    }
    $normalized = $Account.Trim().ToUpperInvariant()
    if ($normalized -eq '') {
        return $false
    }
    if ($normalized -match '[\r\n"'']') {
        return $false
    }
    if ($normalized -match '^(NT AUTHORITY|NT SERVICE|BUILTIN)\\') {
        return $false
    }
    if ($normalized -match '(^|\\)(SYSTEM|LOCAL SYSTEM|LOCAL SERVICE|NETWORK SERVICE)$') {
        return $false
    }
    if ($normalized -match '(^|\\)(SVC[-_]|SERVICE[-_])' -or $normalized.EndsWith('$')) {
        return $false
    }
    return $normalized -eq $currentUser.Trim().ToUpperInvariant()
}

function Test-LoopbackHealthUri {
    param([Parameter(Mandatory = $true)][string]$Value)

    try {
        $uri = [System.Uri]$Value
    } catch {
        return $false
    }

    return ($uri.IsAbsoluteUri -and $uri.IsLoopback -and $uri.Scheme -in @('http', 'https') -and $uri.UserInfo -eq '' -and $uri.AbsolutePath.TrimEnd('/') -eq '/api/health')
}

function Test-CredentialFreeTaskArguments {
    param([Parameter(Mandatory = $true)][string]$Arguments)

    $credentialPattern = '(?i)(--?(cookie|token|password|authorization|spidertoken|secret|session|credential)\b|(?:cookie|token|password|authorization|spidertoken|secret|session|credential)\s*=)'
    return $Arguments -notmatch $credentialPattern
}

function Get-DispatcherTaskScopeFromArguments {
    param([Parameter(Mandatory = $true)][string]$Arguments)

    $hotelMatch = [regex]::Match($Arguments, '(?i)(?:^|\s)-HotelId\s+(?<value>[1-9][0-9]*)(?:\s|$)')
    $sourceMatch = [regex]::Match($Arguments, '(?i)(?:^|\s)-SourceIds\s+"(?<value>[1-9][0-9]*(?:,[1-9][0-9]*)*)"')
    $platformMatch = [regex]::Match($Arguments, '(?i)(?:^|\s)-Platforms\s+"(?<value>ctrip|meituan|ctrip,meituan|meituan,ctrip)"')
    if (-not ($hotelMatch.Success -and $sourceMatch.Success -and $platformMatch.Success)) {
        return $null
    }

    return [pscustomobject]@{
        hotel_id = [int]$hotelMatch.Groups['value'].Value
        source_ids = @($sourceMatch.Groups['value'].Value.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
        platforms = @($platformMatch.Groups['value'].Value.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ -ne '' })
    }
}

function New-PreflightCheck {
    param(
        [Parameter(Mandatory = $true)][string]$Name,
        [Parameter(Mandatory = $true)][bool]$Passed,
        [Parameter(Mandatory = $true)][string]$Detail
    )

    return [pscustomobject]@{
        name = $Name
        passed = $Passed
        detail = $Detail
    }
}

function Write-DispatcherPlan {
    param([Parameter(Mandatory = $true)]$Plan)

    Write-Output ($Plan | ConvertTo-Json -Depth 8)
}

$resolvedProjectRoot = $null
if (Test-Path -LiteralPath $ProjectRoot -PathType Container) {
    $resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
}
$effectiveProjectRoot = if ($null -ne $resolvedProjectRoot) { $resolvedProjectRoot } else { $ProjectRoot }
$resolvedPhpPath = Resolve-ExecutablePath -Candidate $PhpPath
$thinkPath = Join-Path $effectiveProjectRoot 'think'
$consoleConfigPath = Join-Path $effectiveProjectRoot 'config\console.php'
$registrationScriptPath = Join-Path $effectiveProjectRoot 'scripts\register_ota_dispatcher_task.ps1'
$dispatcherRunnerPath = Join-Path $effectiveProjectRoot 'scripts\run_ota_dispatcher.ps1'
$powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$explicitScopeRequested = $HotelId -gt 0 -or $SourceIds -ne '' -or $Platforms -ne ''
$explicitScopeComplete = $HotelId -gt 0 -and $SourceIds -ne '' -and $Platforms -ne ''
$scopeArguments = if ($explicitScopeComplete) {
    ' -HotelId {0} -SourceIds "{1}" -Platforms "{2}"' -f $HotelId, $SourceIds, $Platforms
} else {
    ''
}
$actionArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}" -Mode {3}{4}' -f $dispatcherRunnerPath, $effectiveProjectRoot, $resolvedPhpPath, $dispatcherMode, $scopeArguments

$preflight = @()
$preflight += New-PreflightCheck -Name 'project_root' -Passed ($null -ne $resolvedProjectRoot) -Detail $effectiveProjectRoot
$projectIdentityPassed = ((Test-Path -LiteralPath (Join-Path $effectiveProjectRoot 'composer.json') -PathType Leaf) -and (Test-Path -LiteralPath $registrationScriptPath -PathType Leaf))
$preflight += New-PreflightCheck -Name 'project_identity' -Passed $projectIdentityPassed -Detail 'composer.json and the repository registration script must exist'
$preflight += New-PreflightCheck -Name 'php_binary' -Passed ($null -ne $resolvedPhpPath) -Detail $(if ($null -ne $resolvedPhpPath) { $resolvedPhpPath } else { $PhpPath })
$preflight += New-PreflightCheck -Name 'think_entry' -Passed (Test-Path -LiteralPath $thinkPath -PathType Leaf) -Detail $thinkPath
$preflight += New-PreflightCheck -Name 'dispatcher_runner' -Passed (Test-Path -LiteralPath $dispatcherRunnerPath -PathType Leaf) -Detail $dispatcherRunnerPath
$preflight += New-PreflightCheck -Name 'powershell_binary' -Passed (Test-Path -LiteralPath $powershellPath -PathType Leaf) -Detail $powershellPath
$scopeBoundaryPassed = -not $explicitScopeRequested -or $explicitScopeComplete
$preflight += New-PreflightCheck -Name 'scope_boundary' -Passed $scopeBoundaryPassed -Detail $(
    if ($scopeBoundaryPassed -and $explicitScopeComplete) {
        "hotel $HotelId; sources fixed; platforms $Platforms"
    } elseif ($scopeBoundaryPassed) {
        'existing enabled-hotel scheduler scope'
    } else {
        'HotelId, SourceIds, and Platforms must be supplied together'
    }
)

$commandRegistered = $false
if (Test-Path -LiteralPath $consoleConfigPath -PathType Leaf) {
    $consoleConfig = Get-Content -Raw -Encoding UTF8 -LiteralPath $consoleConfigPath
    $commandRegistered = $consoleConfig.Contains("'online-data:auto-fetch' => 'app\command\AutoFetchOnlineData'")
}
$preflight += New-PreflightCheck -Name 'dispatcher_command' -Passed $commandRegistered -Detail $dispatcherCommand

$runAsUserSafe = Test-SafeInteractiveUser -Account $RunAsUser
$preflight += New-PreflightCheck -Name 'interactive_user' -Passed $runAsUserSafe -Detail $(
    if ($runAsUserSafe) { $RunAsUser } else { 'RunAsUser must be the current interactive user and cannot be SYSTEM or a service account' }
)

$healthUriSafe = Test-LoopbackHealthUri -Value $HealthUrl
$preflight += New-PreflightCheck -Name 'health_url_boundary' -Passed $healthUriSafe -Detail $(
    if ($healthUriSafe) { $HealthUrl } else { 'HealthUrl must be a loopback /api/health URL without user information' }
)

$healthPassed = $false
$healthDetail = 'not checked because the health URL boundary failed'
if ($healthUriSafe) {
    try {
        $healthResponse = Invoke-WebRequest -Uri $HealthUrl -Method Get -UseBasicParsing -TimeoutSec 5
        $healthStatusCode = [int]$healthResponse.StatusCode
        $healthPassed = $healthStatusCode -ge 200 -and $healthStatusCode -lt 300
        $healthDetail = "HTTP $healthStatusCode"
    } catch {
        $healthDetail = 'health check failed: ' + $_.Exception.GetType().Name
    }
}
$preflight += New-PreflightCheck -Name 'local_health' -Passed $healthPassed -Detail $healthDetail

$credentialFreeArguments = Test-CredentialFreeTaskArguments -Arguments $actionArguments
$preflight += New-PreflightCheck -Name 'credential_free_arguments' -Passed $credentialFreeArguments -Detail $(
    if ($credentialFreeArguments) { 'task arguments contain only the local runner, project root, and PHP path' } else { 'credential-shaped task arguments are forbidden' }
)

$realtimeRetryWindowReady = -not $Realtime -or $RealtimeMinute -le 14
$preflight += New-PreflightCheck -Name 'realtime_retry_window' -Passed $realtimeRetryWindowReady -Detail $(
    if (-not $Realtime) {
        'not applicable to the daily dispatcher'
    } elseif ($realtimeRetryWindowReady) {
        '15-minute polling gives bounded retry opportunities inside each hourly idempotency slot without colliding with the 08:30 daily run'
    } else {
        'RealtimeMinute must be 0-14 so the 15-minute retry cadence stays aligned to one hourly slot'
    }
)

$dailyTimeSpan = [timespan]::ParseExact($DailyAt, 'hh\:mm', $null)
$dailyRetryWindowReady = $Realtime -or $dailyTimeSpan.TotalMinutes -le (24 * 60 - 1 - $dailyRetryOffsetsMinutes[-1])
$preflight += New-PreflightCheck -Name 'daily_retry_window' -Passed $dailyRetryWindowReady -Detail $(
    if ($Realtime) {
        'not applicable to the realtime dispatcher'
    } elseif ($dailyRetryWindowReady) {
        'daily base attempt and +14/+28/+42/+56/+70/+84 minute opportunities stay on the same business-date window'
    } else {
        'DailyAt must leave 84 minutes before midnight so retries cannot change the target business date'
    }
)

$requiredScheduledTaskCommands = @(
    'Get-ScheduledTask',
    'New-ScheduledTaskAction',
    'New-ScheduledTaskTrigger',
    'New-ScheduledTaskPrincipal',
    'New-ScheduledTaskSettingsSet',
    'Register-ScheduledTask',
    'Unregister-ScheduledTask'
)
$missingScheduledTaskCommands = @(
    $requiredScheduledTaskCommands | Where-Object { $null -eq (Get-Command -Name $_ -ErrorAction SilentlyContinue) }
)
$scheduledTaskCommandsReady = $missingScheduledTaskCommands.Count -eq 0
$preflight += New-PreflightCheck -Name 'scheduled_tasks_module' -Passed $scheduledTaskCommandsReady -Detail $(
    if ($scheduledTaskCommandsReady) { 'required ScheduledTasks commands are available' } else { 'missing: ' + ($missingScheduledTaskCommands -join ', ') }
)

$existingTask = $null
if ($scheduledTaskCommandsReady) {
    $existingTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
}
$deferredDailyStartBoundary = $null
if (-not $Realtime -and $null -ne $existingTask -and $ReplaceExisting) {
    $existingTriggers = @($existingTask.Triggers)
    if ($existingTriggers.Count -eq 1) {
        [datetimeoffset]$candidateStartBoundary = [datetimeoffset]::MinValue
        $candidateText = [string]$existingTriggers[0].StartBoundary
        $candidateParsed = [datetimeoffset]::TryParse($candidateText, [ref]$candidateStartBoundary)
        if ($candidateParsed -and $candidateStartBoundary.Date -gt [datetimeoffset]::Now.Date) {
            # Preserve an explicitly deferred first run while replacing the
            # action/scope. Recomputing Today + DailyAt would otherwise turn a
            # safe next-day registration into an unintended same-day run.
            $deferredDailyStartBoundary = $candidateStartBoundary
        }
    }
}
$effectiveDailyStartBoundary = $deferredDailyStartBoundary
if (-not $Realtime -and $null -eq $effectiveDailyStartBoundary) {
    $nextDailyStart = [datetime]::Today.Add([timespan]::ParseExact($DailyAt, 'hh\:mm', $null))
    if ($nextDailyStart -le (Get-Date)) {
        $nextDailyStart = $nextDailyStart.AddDays(1)
    }
    $effectiveDailyStartBoundary = [datetimeoffset]$nextDailyStart
}

$scopeReductionDetected = $false
$removedSourceIds = @()
$removedPlatforms = @()
if ($null -ne $existingTask -and $ReplaceExisting -and $explicitScopeComplete) {
    $existingActions = @($existingTask.Actions)
    if ($existingActions.Count -eq 1) {
        $existingScope = Get-DispatcherTaskScopeFromArguments -Arguments ([string]$existingActions[0].Arguments)
        if ($null -ne $existingScope -and $existingScope.hotel_id -eq $HotelId) {
            $requestedSourceIds = @($SourceIds.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
            $requestedPlatforms = @($Platforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() } | Where-Object { $_ -ne '' })
            $removedSourceIds = @($existingScope.source_ids | Where-Object { $_ -notin $requestedSourceIds })
            $removedPlatforms = @($existingScope.platforms | Where-Object { $_ -notin $requestedPlatforms })
            $scopeReductionDetected = $removedSourceIds.Count -gt 0 -or $removedPlatforms.Count -gt 0
        }
    }
}
$scopeNonReductionPassed = -not $scopeReductionDetected -or $AllowScopeReduction
$preflight += New-PreflightCheck -Name 'scope_non_reduction' -Passed $scopeNonReductionPassed -Detail $(
    if (-not $scopeReductionDetected) {
        'requested scope does not remove an existing source or platform'
    } elseif ($AllowScopeReduction) {
        'explicit scope reduction allowed; removed sources=' + ($removedSourceIds -join ',') + '; removed platforms=' + ($removedPlatforms -join ',')
    } else {
        'scope reduction refused; removed sources=' + ($removedSourceIds -join ',') + '; removed platforms=' + ($removedPlatforms -join ',') + '; use -AllowScopeReduction only after explicit review'
    }
)

$preflightFailures = @($preflight | Where-Object { -not $_.passed })
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
        schedule = if ($Realtime) { "hourly base at :$('{0:d2}' -f $RealtimeMinute), polled every $($realtimePollIntervalMinutes)m for bounded retries Asia/Shanghai" } else { "daily $DailyAt with bounded retries +14m/+28m/+42m/+56m/+70m/+84m Asia/Shanghai" }
        trigger_count = 1
        start_boundary = if ($null -ne $effectiveDailyStartBoundary) { $effectiveDailyStartBoundary.ToString('o') } else { $null }
        effective_runs_per_day = if ($Realtime) { 96 } else { $dailyRetryOffsetsMinutes.Count }
        multiple_instances = 'IgnoreNew'
        wake_to_run = $true
        execution_time_limit_minutes = if ($Realtime) { 25 } else { 40 }
    }
    action = [ordered]@{
        execute = $powershellPath
        arguments = $actionArguments
        working_directory = $effectiveProjectRoot
    }
    scope = [ordered]@{
        hotel_id = if ($HotelId -gt 0) { $HotelId } else { $null }
        source_ids_fixed = $SourceIds -ne ''
        platforms = if ($Platforms -ne '') { @($Platforms.Split(',')) } else { @() }
        external_delivery = $false
    }
    principal = [ordered]@{
        user = $RunAsUser
        logon_type = 'Interactive'
        run_level = 'Limited'
    }
    safety = [ordered]@{
        starts_task_immediately = $false
        visible_window_expected = $false
        credentials_in_arguments = $false
        enable_requires_switch = '-Enable'
        unregister_requires_switches = @('-Unregister', '-ConfirmUnregister')
        replace_existing_requires_switch = '-ReplaceExisting'
        scope_reduction_requires_switch = '-AllowScopeReduction'
        preserves_deferred_daily_start = $true
    }
    preflight = $preflight
    enable_ready = $preflightFailures.Count -eq 0 -and ($null -eq $existingTask -or $ReplaceExisting)
}

if ($Unregister) {
    if (-not $ConfirmUnregister) {
        Write-DispatcherPlan -Plan $plan
        throw 'Unregistration refused. Use -Unregister -ConfirmUnregister for the fixed SUXIOS OTA Dispatcher task.'
    }
    if (-not $scheduledTaskCommandsReady) {
        Write-DispatcherPlan -Plan $plan
        throw 'Unregistration refused because the Windows ScheduledTasks module is unavailable.'
    }
    if ($null -eq $existingTask) {
        $plan['result'] = 'already_absent'
        Write-DispatcherPlan -Plan $plan
        return
    }
    if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Unregister scheduled task')) {
        Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
        $plan['result'] = 'unregistered'
        Write-DispatcherPlan -Plan $plan
    }
    return
}

if (-not $Enable) {
    Write-DispatcherPlan -Plan $plan
    return
}

if ($preflightFailures.Count -gt 0) {
    Write-DispatcherPlan -Plan $plan
    throw ('Registration refused because preflight checks failed: ' + (($preflightFailures | ForEach-Object { $_.name }) -join ', '))
}
if ($null -ne $existingTask -and -not $ReplaceExisting) {
    Write-DispatcherPlan -Plan $plan
    throw 'Registration refused because the task already exists. Review it and use -Enable -ReplaceExisting to replace it.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Register scheduled task without starting it')) {
    $taskAction = New-ScheduledTaskAction `
        -Execute $powershellPath `
        -Argument $actionArguments `
        -WorkingDirectory $effectiveProjectRoot
    if ($Realtime) {
        $taskTrigger = New-ScheduledTaskTrigger -Daily -At ([datetime]::Today.Date.AddMinutes($RealtimeMinute))
        $taskTrigger.Repetition = New-DispatcherRepetitionPattern -Interval 'PT15M' -Duration 'P1D'
    } else {
        $triggerTime = $effectiveDailyStartBoundary.LocalDateTime
        $taskTrigger = New-ScheduledTaskTrigger -Daily -At $triggerTime
        $taskTrigger.Repetition = New-DispatcherRepetitionPattern -Interval 'PT14M' -Duration 'PT85M'
    }
    $taskPrincipal = New-ScheduledTaskPrincipal `
        -UserId $RunAsUser `
        -LogonType Interactive `
        -RunLevel Limited
    $taskSettings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -WakeToRun `
        -Hidden `
        -ExecutionTimeLimit $(if ($Realtime) { New-TimeSpan -Minutes 25 } else { New-TimeSpan -Minutes 40 }) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries

    $registrationParameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $taskAction
        Trigger = $taskTrigger
        Principal = $taskPrincipal
        Settings = $taskSettings
        Description = $(if ($Realtime) { 'Authorized local-profile OTA realtime dispatcher. Polls every 15 minutes for bounded retries while avoiding the 08:30 daily trigger; fixed scope when provided, no external delivery, hidden window, and registration does not start it.' } else { 'Authorized local-profile OTA daily dispatcher. Runs yesterday final collection with bounded +14/+28/+42/+56/+70/+84 minute opportunities; the final slot remains after the fifth exponential retry cooldown and a 40-minute execution cap keeps later recovery reachable; fixed scope when provided, no external delivery, hidden window, and registration does not start it.' })
    }
    if ($null -ne $existingTask) {
        $registrationParameters['Force'] = $true
    }

    Register-ScheduledTask @registrationParameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-DispatcherPlan -Plan $plan
}
