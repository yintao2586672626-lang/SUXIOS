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

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health',

    [ValidateRange(1, 60)]
    [int]$IntervalMinutes = 1,

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}

$taskName = 'SUXIOS OTA Dispatcher'
$taskPath = '\'
$dispatcherCommand = 'online-data:auto-fetch'
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

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
$actionArguments = '"{0}" {1}' -f $thinkPath, $dispatcherCommand

$preflight = @()
$preflight += New-PreflightCheck -Name 'project_root' -Passed ($null -ne $resolvedProjectRoot) -Detail $effectiveProjectRoot
$projectIdentityPassed = ((Test-Path -LiteralPath (Join-Path $effectiveProjectRoot 'composer.json') -PathType Leaf) -and (Test-Path -LiteralPath $registrationScriptPath -PathType Leaf))
$preflight += New-PreflightCheck -Name 'project_identity' -Passed $projectIdentityPassed -Detail 'composer.json and the repository registration script must exist'
$preflight += New-PreflightCheck -Name 'php_binary' -Passed ($null -ne $resolvedPhpPath) -Detail $(if ($null -ne $resolvedPhpPath) { $resolvedPhpPath } else { $PhpPath })
$preflight += New-PreflightCheck -Name 'think_entry' -Passed (Test-Path -LiteralPath $thinkPath -PathType Leaf) -Detail $thinkPath

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
    if ($credentialFreeArguments) { 'task arguments contain only the think entry and dispatcher command' } else { 'credential-shaped task arguments are forbidden' }
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
        interval_minutes = $IntervalMinutes
        multiple_instances = 'IgnoreNew'
        execution_time_limit_minutes = 120
    }
    action = [ordered]@{
        execute = if ($null -ne $resolvedPhpPath) { $resolvedPhpPath } else { $PhpPath }
        arguments = $actionArguments
        working_directory = $effectiveProjectRoot
    }
    principal = [ordered]@{
        user = $RunAsUser
        logon_type = 'Interactive'
        run_level = 'Limited'
    }
    safety = [ordered]@{
        starts_task_immediately = $false
        credentials_in_arguments = $false
        enable_requires_switch = '-Enable'
        unregister_requires_switches = @('-Unregister', '-ConfirmUnregister')
        replace_existing_requires_switch = '-ReplaceExisting'
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
        -Execute $resolvedPhpPath `
        -Argument $actionArguments `
        -WorkingDirectory $effectiveProjectRoot
    $taskTrigger = New-ScheduledTaskTrigger `
        -Once `
        -At (Get-Date).AddMinutes(1) `
        -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)
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
        Trigger = $taskTrigger
        Principal = $taskPrincipal
        Settings = $taskSettings
        Description = 'Authorized local-profile OTA dispatcher. Task arguments contain no credentials and the task is not started by registration.'
    }
    if ($null -ne $existingTask) {
        $registrationParameters['Force'] = $true
    }

    Register-ScheduledTask @registrationParameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-DispatcherPlan -Plan $plan
}
