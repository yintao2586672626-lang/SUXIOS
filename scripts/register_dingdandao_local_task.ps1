[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'Medium')]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$ProjectRoot,

    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [string]$NodePath = 'C:\Program Files\nodejs\node.exe',

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int]$HotelId,

    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int]$OwnerUserId,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^sbx_[A-Za-z0-9_-]{8,64}$')]
    [string]$SandboxId,

    [ValidatePattern('^http://127\.0\.0\.1:[1-9][0-9]{1,4}$')]
    [string]$CdpUrl = 'http://127.0.0.1:9223',

    [ValidateSet('operating_indicators', 'full_diagnostic')]
    [string]$CollectionMode = 'operating_indicators',

    [ValidateRange(0, 59)]
    [int]$Minute = 5,

    [ValidateRange(0, 23)]
    [int]$StartHour = 7,

    [ValidateRange(0, 23)]
    [int]$EndHour = 23,

    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health',

    [string]$RunAsUser = "$env:USERDOMAIN\$env:USERNAME",

    [switch]$Push,

    [switch]$Enable,

    [switch]$ReplaceExisting,

    [switch]$Unregister,

    [switch]$ConfirmUnregister
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$taskName = "Dingdandao H$HotelId"
$taskPath = '\SUXIOS\'

function Resolve-ExecutablePath {
    param([Parameter(Mandatory = $true)][string]$Candidate)

    if (Test-Path -LiteralPath $Candidate -PathType Leaf) {
        return (Resolve-Path -LiteralPath $Candidate).Path
    }
    $command = Get-Command -Name $Candidate -CommandType Application -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        return $command.Source
    }
    return $null
}

function New-Check {
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

function Write-Plan {
    param([Parameter(Mandatory = $true)]$Plan)

    Write-Output ($Plan | ConvertTo-Json -Depth 8)
}

if ($EndHour -lt $StartHour) {
    throw 'EndHour must be greater than or equal to StartHour.'
}

$resolvedRoot = $null
if (Test-Path -LiteralPath $ProjectRoot -PathType Container) {
    $resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
}
$effectiveRoot = if ($null -ne $resolvedRoot) { $resolvedRoot } else { $ProjectRoot }
$resolvedPhp = Resolve-ExecutablePath -Candidate $PhpPath
$resolvedNode = Resolve-ExecutablePath -Candidate $NodePath
$scheduledRunner = Join-Path $effectiveRoot 'scripts\run_dingdandao_local_scheduled.ps1'
$sandboxBinder = Join-Path $effectiveRoot 'scripts\bind_local_browser_sandbox.mjs'
$powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$actionArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}" -HotelId {3} -OwnerUserId {4} -SandboxId "{5}" -CdpUrl "{6}" -CollectionMode "{7}"{8}' -f `
    $scheduledRunner, `
    $effectiveRoot, `
    $resolvedPhp, `
    $HotelId, `
    $OwnerUserId, `
    $SandboxId, `
    $CdpUrl, `
    $CollectionMode, `
    $(if ($Push) { ' -Push' } else { '' })

$checks = @()
$checks += New-Check -Name 'project_root' -Passed ($null -ne $resolvedRoot) -Detail $effectiveRoot
$checks += New-Check -Name 'php_binary' -Passed ($null -ne $resolvedPhp) -Detail $(if ($null -ne $resolvedPhp) { $resolvedPhp } else { $PhpPath })
$checks += New-Check -Name 'node_binary' -Passed ($null -ne $resolvedNode) -Detail $(if ($null -ne $resolvedNode) { $resolvedNode } else { $NodePath })
$checks += New-Check -Name 'scheduled_runner' -Passed (Test-Path -LiteralPath $scheduledRunner -PathType Leaf) -Detail $scheduledRunner
$checks += New-Check -Name 'sandbox_binder' -Passed (Test-Path -LiteralPath $sandboxBinder -PathType Leaf) -Detail $sandboxBinder
$checks += New-Check -Name 'powershell_binary' -Passed (Test-Path -LiteralPath $powershellPath -PathType Leaf) -Detail $powershellPath

$credentialPattern = '(?i)(--?(cookie|token|password|authorization|spidertoken|secret|session|credential)\b|(?:cookie|token|password|authorization|spidertoken|secret|session|credential)\s*=)'
$checks += New-Check -Name 'credential_free_arguments' -Passed ($actionArguments -notmatch $credentialPattern) -Detail 'task arguments contain only paths, scope IDs, the stable sandbox ID, loopback CDP, and the push switch'

$healthPassed = $false
$healthDetail = 'health check failed'
try {
    $healthUri = [System.Uri]$HealthUrl
    if (-not $healthUri.IsLoopback -or $healthUri.AbsolutePath.TrimEnd('/') -ne '/api/health') {
        throw 'health_url_scope_invalid'
    }
    $healthResponse = Invoke-WebRequest -Uri $healthUri -Method Get -UseBasicParsing -TimeoutSec 5
    $healthPassed = [int]$healthResponse.StatusCode -ge 200 -and [int]$healthResponse.StatusCode -lt 300
    $healthDetail = "HTTP $([int]$healthResponse.StatusCode)"
} catch {
    $healthDetail = 'loopback /api/health unavailable'
}
$checks += New-Check -Name 'local_health' -Passed $healthPassed -Detail $healthDetail

$cdpPassed = $false
$cdpDetail = 'loopback CDP unavailable'
try {
    $cdpResponse = Invoke-WebRequest -Uri "$CdpUrl/json/version" -Method Get -UseBasicParsing -TimeoutSec 5
    $cdpPassed = [int]$cdpResponse.StatusCode -ge 200 -and [int]$cdpResponse.StatusCode -lt 300
    $cdpDetail = "HTTP $([int]$cdpResponse.StatusCode)"
} catch {
    $cdpDetail = 'loopback CDP unavailable'
}
$checks += New-Check -Name 'shared_browser_cdp' -Passed $cdpPassed -Detail $cdpDetail

$sandboxPassed = $false
$sandboxDetail = 'sandbox marker not inspected'
if ($cdpPassed -and $null -ne $resolvedNode -and (Test-Path -LiteralPath $sandboxBinder -PathType Leaf)) {
    $sandboxOutput = & $resolvedNode $sandboxBinder `
        "--cdp-url=$CdpUrl" `
        "--sandbox-id=$SandboxId" `
        '--platform=dingdandao' `
        '--mode=inspect' 2>$null
    $sandboxPassed = $LASTEXITCODE -eq 0
    $sandboxDetail = if ($sandboxPassed) {
        'dedicated process Profile marker found'
    } else {
        'dedicated process Profile marker missing or ambiguous'
    }
}
$checks += New-Check -Name 'sandbox_marker' -Passed $sandboxPassed -Detail $sandboxDetail

$interactiveUserPassed = -not [string]::IsNullOrWhiteSpace($RunAsUser) `
    -and $RunAsUser -notmatch '(?i)(^|\\)(SYSTEM|LOCAL SERVICE|NETWORK SERVICE)$'
$checks += New-Check -Name 'interactive_user' -Passed $interactiveUserPassed -Detail $(if ($interactiveUserPassed) { $RunAsUser } else { 'an interactive non-service account is required' })

$requiredTaskCommands = @(
    'Get-ScheduledTask',
    'New-ScheduledTaskAction',
    'New-ScheduledTaskTrigger',
    'New-ScheduledTaskPrincipal',
    'New-ScheduledTaskSettingsSet',
    'Register-ScheduledTask',
    'Unregister-ScheduledTask'
)
$missingTaskCommands = @($requiredTaskCommands | Where-Object {
    $null -eq (Get-Command -Name $_ -ErrorAction SilentlyContinue)
})
$taskCommandsReady = $missingTaskCommands.Count -eq 0
$checks += New-Check -Name 'scheduled_tasks_module' -Passed $taskCommandsReady -Detail $(if ($taskCommandsReady) { 'available' } else { 'missing: ' + ($missingTaskCommands -join ', ') })

$existingTask = $null
if ($taskCommandsReady) {
    $existingTask = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
}
$failures = @($checks | Where-Object { -not $_.passed })
$plan = [ordered]@{
    schema_version = 1
    mode = if ($Unregister) { 'unregister' } elseif ($Enable) { 'enable' } else { 'plan' }
    mutation_requested = [bool]($Enable -or $Unregister)
    source = 'dingdandao'
    hotel_id = $HotelId
    owner_user_id = $OwnerUserId
    sandbox_id = $SandboxId
    sandbox_selection = 'explicit_marker'
    task = [ordered]@{
        name = $taskName
        path = $taskPath
        exists = $null -ne $existingTask
        state = if ($null -ne $existingTask) { [string]$existingTask.State } else { 'absent' }
        schedule = "hourly $StartHour-$EndHour at :$('{0:d2}' -f $Minute) Asia/Shanghai"
        trigger_count = ($EndHour - $StartHour) + 1
        multiple_instances = 'IgnoreNew'
    }
    action = [ordered]@{
        execute = $powershellPath
        arguments = $actionArguments
        working_directory = $effectiveRoot
        collection_mode = $CollectionMode
        push_requested = [bool]$Push
    }
    safety = [ordered]@{
        starts_task_immediately = $false
        visible_window_expected = $false
        browser_host_auto_start = 'headless'
        credentials_in_arguments = $false
        explicit_sandbox_required = $true
        local_receipt = 'runtime/dingdandao_local_scheduler/latest.json'
        enable_requires_switch = '-Enable'
    }
    preflight = $checks
    enable_ready = $failures.Count -eq 0 -and ($null -eq $existingTask -or $ReplaceExisting)
}

if ($Unregister) {
    if (-not $ConfirmUnregister) {
        Write-Plan -Plan $plan
        throw 'Unregistration refused. Use -Unregister -ConfirmUnregister.'
    }
    if (-not $taskCommandsReady) {
        Write-Plan -Plan $plan
        throw 'ScheduledTasks module is unavailable.'
    }
    if ($null -eq $existingTask) {
        $plan['result'] = 'already_absent'
        Write-Plan -Plan $plan
        return
    }
    if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Unregister scheduled task')) {
        Unregister-ScheduledTask -TaskName $taskName -TaskPath $taskPath -Confirm:$false
        $plan['result'] = 'unregistered'
        Write-Plan -Plan $plan
    }
    return
}

if (-not $Enable) {
    Write-Plan -Plan $plan
    return
}
if ($failures.Count -gt 0) {
    Write-Plan -Plan $plan
    throw ('Registration refused because preflight checks failed: ' + (($failures | ForEach-Object { $_.name }) -join ', '))
}
if ($null -ne $existingTask -and -not $ReplaceExisting) {
    Write-Plan -Plan $plan
    throw 'Task already exists. Use -Enable -ReplaceExisting after review.'
}

if ($PSCmdlet.ShouldProcess("$taskPath$taskName", 'Register scheduled task without starting it')) {
    $action = New-ScheduledTaskAction `
        -Execute $powershellPath `
        -Argument $actionArguments `
        -WorkingDirectory $effectiveRoot
    $triggers = @($StartHour..$EndHour | ForEach-Object {
        New-ScheduledTaskTrigger -Daily -At ([datetime]::Today.Date.AddHours($_).AddMinutes($Minute))
    })
    $principal = New-ScheduledTaskPrincipal `
        -UserId $RunAsUser `
        -LogonType Interactive `
        -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet `
        -MultipleInstances IgnoreNew `
        -StartWhenAvailable `
        -Hidden `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 25) `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries
    $parameters = @{
        TaskName = $taskName
        TaskPath = $taskPath
        Action = $action
        Trigger = $triggers
        Principal = $principal
        Settings = $settings
        Description = 'SUXIOS Dingdandao local shared-browser sandbox collection. Explicit hotel and sandbox scope; writes a sanitized receipt and never starts during registration.'
    }
    if ($null -ne $existingTask) {
        $parameters['Force'] = $true
    }
    Register-ScheduledTask @parameters | Out-Null
    $plan['result'] = 'registered_not_started'
    Write-Plan -Plan $plan
}
