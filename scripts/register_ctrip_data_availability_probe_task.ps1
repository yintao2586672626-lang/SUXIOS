[CmdletBinding(SupportsShouldProcess = $true, DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Enable')]
    [switch]$Enable,

    [Parameter(ParameterSetName = 'Enable')]
    [switch]$ReplaceExisting,

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidateRange(1, 2147483647)]
    [int]$CtripSourceId = 25,

    [string]$ProjectRoot = '',

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
$resolvedProjectRoot = if (Test-Path -LiteralPath $ProjectRoot -PathType Container) { (Resolve-Path -LiteralPath $ProjectRoot).Path } else { $null }
$effectiveProjectRoot = if ($null -ne $resolvedProjectRoot) { $resolvedProjectRoot } else { $ProjectRoot }
$runnerPath = Join-Path $effectiveProjectRoot 'scripts\run_ctrip_data_availability_probe.ps1'
$resolvedRunnerPath = if (Test-Path -LiteralPath $runnerPath -PathType Leaf) { (Resolve-Path -LiteralPath $runnerPath).Path } else { $null }
$resolvedPhpPath = if (Test-Path -LiteralPath $PhpPath -PathType Leaf) { (Resolve-Path -LiteralPath $PhpPath).Path } else { $null }
$powershellPath = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe'
$taskName = "SUXIOS Ctrip Data Availability H$HotelId"
$taskPath = '\'
$triggerTimes = @('06:05', '07:05')
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name

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

$actionArguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -Run -HotelId {1} -CtripSourceId {2} -ProjectRoot "{3}" -PhpPath "{4}" -HealthUrl "{5}"' -f `
    $resolvedRunnerPath, $HotelId, $CtripSourceId, $effectiveProjectRoot, $resolvedPhpPath, $HealthUrl
$credentialPattern = '(?i)(--?(cookie|token|password|authorization|spidertoken|secret|session|credential)\b|(?:cookie|token|password|authorization|spidertoken|secret|session|credential)\s*=)'
$credentialFreeArguments = $actionArguments -notmatch $credentialPattern
$existingTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedProjectRoot; detail = $effectiveProjectRoot },
    [pscustomobject]@{ name = 'runner_script'; passed = $null -ne $resolvedRunnerPath; detail = $runnerPath },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhpPath; detail = $PhpPath },
    [pscustomobject]@{ name = 'powershell_binary'; passed = (Test-Path -LiteralPath $powershellPath -PathType Leaf); detail = $powershellPath },
    [pscustomobject]@{ name = 'interactive_user'; passed = $runAsUserSafe; detail = $(if ($runAsUserSafe) { $RunAsUser } else { 'must be the current interactive non-service user' }) },
    [pscustomobject]@{ name = 'health_url_boundary'; passed = $healthSafe; detail = $HealthUrl },
    [pscustomobject]@{ name = 'local_health'; passed = $healthPassed; detail = $healthDetail },
    [pscustomobject]@{ name = 'credential_free_arguments'; passed = $credentialFreeArguments; detail = 'task arguments contain only paths and fixed hotel/source scope' }
)
$preflightFailures = @($preflight | Where-Object { -not $_.passed })
$plan = [ordered]@{
    schema_version = 1
    mode = if ($Enable) { 'enable' } else { 'plan' }
    mutation_requested = [bool]$Enable
    task = [ordered]@{
        name = $taskName
        exists = $null -ne $existingTask
        trigger_times = $triggerTimes
        target_date_rule = 'Asia/Shanghai today minus 2 days'
        multiple_instances = 'IgnoreNew'
        execution_time_limit_minutes = 50
    }
    action = [ordered]@{
        execute = $powershellPath
        arguments = $actionArguments
        working_directory = $effectiveProjectRoot
    }
    safety = [ordered]@{
        fixed_hotel_id = $HotelId
        fixed_ctrip_source_id = $CtripSourceId
        starts_task_immediately = $false
        credentials_in_arguments = $false
    }
    preflight = $preflight
    enable_ready = $preflightFailures.Count -eq 0 -and ($null -eq $existingTask -or $ReplaceExisting)
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
    throw 'Registration refused because the task already exists. Use -Enable -ReplaceExisting after review.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Register Ctrip data availability probe without starting it')) {
    $taskAction = New-ScheduledTaskAction -Execute $powershellPath -Argument $actionArguments -WorkingDirectory $effectiveProjectRoot
    $taskTriggers = @($triggerTimes | ForEach-Object { New-ScheduledTaskTrigger -Daily -At $_ })
    $taskPrincipal = New-ScheduledTaskPrincipal -UserId $RunAsUser -LogonType Interactive -RunLevel Limited
    $taskSettings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 50) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $registrationParameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $taskAction
        Trigger = $taskTriggers
        Principal = $taskPrincipal
        Settings = $taskSettings
        Description = "Hotel $HotelId only: observe when Ctrip day-minus-2 readback exists and Qunar traffic becomes positive."
    }
    if ($null -ne $existingTask) {
        $registrationParameters['Force'] = $true
    }
    Register-ScheduledTask @registrationParameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-Output ($plan | ConvertTo-Json -Depth 8)
}
