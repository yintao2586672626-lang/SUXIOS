[CmdletBinding()]
param(
    [string]$ProjectRoot = '',

    [string]$BrowserPath = '',

    [string]$NodePath = '',

    [ValidateRange(1024, 65535)]
    [int]$Port = 9223,

    [ValidateSet('ctrip', 'meituan', 'dingdandao')]
    [string]$Platform = 'dingdandao',

    [ValidatePattern('^sbx_[A-Za-z0-9_-]{8,64}$')]
    [string]$SandboxId = 'sbx_dingdandao_h80_primary',

    [switch]$InteractiveLogin,

    [switch]$SwitchMode,

    [ValidateRange(3000, 60000)]
    [int]$StartupTimeoutMs = 15000
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-Application {
    param(
        [string]$ExplicitPath,
        [string[]]$CommandNames,
        [string[]]$Candidates,
        [string]$FailureReason
    )

    if (-not [string]::IsNullOrWhiteSpace($ExplicitPath)) {
        if (Test-Path -LiteralPath $ExplicitPath -PathType Leaf) {
            return (Resolve-Path -LiteralPath $ExplicitPath).Path
        }
        throw $FailureReason
    }

    foreach ($commandName in $CommandNames) {
        $command = Get-Command -Name $commandName -CommandType Application -ErrorAction SilentlyContinue
        if ($null -ne $command) {
            return $command.Source
        }
    }
    foreach ($candidate in $Candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }
    throw $FailureReason
}

function Get-LocalCdpState {
    param(
        [string]$BaseUrl,
        [int]$ExpectedPort
    )

    try {
        $response = Invoke-RestMethod `
            -Uri "$BaseUrl/json/version" `
            -Method Get `
            -TimeoutSec 2 `
            -ErrorAction Stop
        $webSocketUrl = [string]$response.webSocketDebuggerUrl
        if ([string]::IsNullOrWhiteSpace($webSocketUrl)) {
            return $null
        }
        $webSocketUri = [System.Uri]$webSocketUrl
        if ($webSocketUri.Scheme -ne 'ws' -or
            $webSocketUri.Port -ne $ExpectedPort -or
            $webSocketUri.Host -notin @('127.0.0.1', 'localhost')
        ) {
            return $null
        }
        return [pscustomobject]@{
            Ready = $true
            Product = [string]$response.Browser
        }
    } catch {
        return $null
    }
}

function Get-DedicatedBrowserHost {
    param(
        [int]$ExpectedPort,
        [string]$ExpectedProfilePath
    )

    $listener = Get-NetTCPConnection `
        -State Listen `
        -LocalPort $ExpectedPort `
        -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -eq $listener) {
        return $null
    }

    $process = Get-CimInstance `
        -ClassName Win32_Process `
        -Filter "ProcessId = $($listener.OwningProcess)" `
        -ErrorAction SilentlyContinue
    if ($null -eq $process) {
        return [pscustomobject]@{
            Trusted = $false
            ProcessId = [int]$listener.OwningProcess
            Headless = $false
        }
    }

    $commandLine = [string]$process.CommandLine
    $profileMarker = [System.IO.Path]::GetFullPath($ExpectedProfilePath)
    $portPattern = '(?i)(?:^|\s)--remote-debugging-port(?:=|\s+)"?' `
        + [regex]::Escape([string]$ExpectedPort) `
        + '(?:"|\s|$)'
    $trusted = $commandLine -match $portPattern `
        -and $commandLine.IndexOf(
            $profileMarker,
            [System.StringComparison]::OrdinalIgnoreCase
        ) -ge 0

    return [pscustomobject]@{
        Trusted = $trusted
        ProcessId = [int]$listener.OwningProcess
        Headless = $commandLine -match '(?i)(?:^|\s)--headless(?:=new)?(?:\s|$)'
    }
}

function Get-LastJsonObject {
    param([object[]]$Lines)

    for ($index = $Lines.Count - 1; $index -ge 0; $index--) {
        $line = [string]$Lines[$index]
        if (-not $line.TrimStart().StartsWith('{')) {
            continue
        }
        try {
            return $line | ConvertFrom-Json -ErrorAction Stop
        } catch {
            continue
        }
    }
    return $null
}

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$binderPath = Join-Path $resolvedRoot 'scripts\bind_local_browser_sandbox.mjs'
if (-not (Test-Path -LiteralPath $binderPath -PathType Leaf)) {
    throw 'local_browser_sandbox_binder_missing'
}

$resolvedNode = Resolve-Application `
    -ExplicitPath $NodePath `
    -CommandNames @('node.exe', 'node') `
    -Candidates @('C:\Program Files\nodejs\node.exe') `
    -FailureReason 'local_browser_sandbox_node_missing'

$resolvedBrowser = Resolve-Application `
    -ExplicitPath $BrowserPath `
    -CommandNames @('chrome.exe', 'msedge.exe') `
    -Candidates @(
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
        'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
    ) `
    -FailureReason 'local_browser_sandbox_browser_missing'

$cdpUrl = "http://127.0.0.1:$Port"
$profilePath = Join-Path $resolvedRoot 'runtime\local_browser_host'
$browserStdoutPath = Join-Path $profilePath 'browser.stdout.log'
$browserStderrPath = Join-Path $profilePath 'browser.stderr.log'
$browserStarted = $false
$modeSwitchPerformed = $false
$cdpState = Get-LocalCdpState -BaseUrl $cdpUrl -ExpectedPort $Port

if ($null -ne $cdpState) {
    $browserHost = Get-DedicatedBrowserHost `
        -ExpectedPort $Port `
        -ExpectedProfilePath $profilePath
    if ($null -eq $browserHost -or -not [bool]$browserHost.Trusted) {
        throw 'local_browser_sandbox_foreign_cdp_process'
    }

    $requestedHeadless = -not [bool]$InteractiveLogin
    if ([bool]$browserHost.Headless -ne $requestedHeadless) {
        if (-not $SwitchMode) {
            throw 'local_browser_sandbox_mode_switch_required'
        }

        $closeArguments = @(
            $binderPath,
            "--cdp-url=$cdpUrl",
            "--sandbox-id=$SandboxId",
            "--platform=$Platform",
            '--mode=close-process-profile'
        )
        $closeOutput = @(& $resolvedNode @closeArguments 2>&1)
        $closeExitCode = $LASTEXITCODE
        $shutdownWatch = [System.Diagnostics.Stopwatch]::StartNew()
        while ($shutdownWatch.ElapsedMilliseconds -lt $StartupTimeoutMs) {
            $remainingListener = Get-NetTCPConnection `
                -State Listen `
                -LocalPort $Port `
                -ErrorAction SilentlyContinue |
                Select-Object -First 1
            if ($null -eq $remainingListener) {
                break
            }
            Start-Sleep -Milliseconds 200
        }
        while ($shutdownWatch.ElapsedMilliseconds -lt $StartupTimeoutMs) {
            $remainingProcess = Get-Process `
                -Id ([int]$browserHost.ProcessId) `
                -ErrorAction SilentlyContinue
            if ($null -eq $remainingProcess) {
                break
            }
            Start-Sleep -Milliseconds 200
        }
        $shutdownWatch.Stop()
        $remainingListener = Get-NetTCPConnection `
            -State Listen `
            -LocalPort $Port `
            -ErrorAction SilentlyContinue |
            Select-Object -First 1
        if ($null -ne $remainingListener) {
            if ($closeExitCode -ne 0) {
                throw 'local_browser_sandbox_graceful_close_failed'
            }
            throw 'local_browser_sandbox_mode_switch_shutdown_failed'
        }
        $remainingProcess = Get-Process `
            -Id ([int]$browserHost.ProcessId) `
            -ErrorAction SilentlyContinue
        if ($null -ne $remainingProcess) {
            throw 'local_browser_sandbox_profile_process_shutdown_failed'
        }
        Start-Sleep -Milliseconds 500

        $cdpState = $null
        $modeSwitchPerformed = $true
    }
}

if ($null -eq $cdpState) {
    $listener = Get-NetTCPConnection `
        -State Listen `
        -LocalPort $Port `
        -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -ne $listener) {
        throw 'local_browser_sandbox_port_in_use_by_non_cdp_process'
    }

    New-Item -ItemType Directory -Force -Path $profilePath | Out-Null
    $browserArguments = @(
        '--remote-debugging-address=127.0.0.1',
        "--remote-debugging-port=$Port",
        ('--user-data-dir="{0}"' -f $profilePath),
        '--no-first-run',
        '--no-default-browser-check'
    )
    if ($InteractiveLogin) {
        $browserArguments += '--new-window'
    } else {
        $browserArguments += '--headless=new'
    }
    $browserArguments += 'about:blank'
    $startParameters = @{
        FilePath = $resolvedBrowser
        ArgumentList = $browserArguments
        WorkingDirectory = (Split-Path -Parent $resolvedBrowser)
        RedirectStandardOutput = $browserStdoutPath
        RedirectStandardError = $browserStderrPath
    }
    if (-not $InteractiveLogin) {
        $startParameters['WindowStyle'] = 'Hidden'
    }
    Start-Process @startParameters | Out-Null
    $browserStarted = $true

    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    while ($watch.ElapsedMilliseconds -lt $StartupTimeoutMs) {
        $cdpState = Get-LocalCdpState -BaseUrl $cdpUrl -ExpectedPort $Port
        if ($null -ne $cdpState) {
            break
        }
        Start-Sleep -Milliseconds 200
    }
    $watch.Stop()
}

if ($null -eq $cdpState) {
    throw 'local_browser_sandbox_cdp_start_failed'
}

$bindArguments = @(
    $binderPath,
    "--cdp-url=$cdpUrl",
    "--sandbox-id=$SandboxId",
    "--platform=$Platform",
    '--mode=bind-process-profile'
)
$bindOutput = @(& $resolvedNode @bindArguments 2>&1)
$bindExitCode = $LASTEXITCODE
$bindResult = Get-LastJsonObject -Lines $bindOutput
if ($bindExitCode -ne 0 -or $null -eq $bindResult) {
    $reason = if ($null -ne $bindResult -and $bindResult.PSObject.Properties['reason']) {
        [string]$bindResult.reason
    } else {
        'local_browser_sandbox_bind_failed'
    }
    throw $reason
}

$status = [string]$bindResult.status
$windowTargetActivated = $false
$windowTargetReused = $null
$windowForegroundRequested = $false
$activatedTargetScope = ''
if ($InteractiveLogin) {
    $handoffArguments = @(
        $binderPath,
        "--cdp-url=$cdpUrl",
        "--sandbox-id=$SandboxId",
        "--platform=$Platform",
        '--mode=handoff'
    )
    $handoffOutput = @(& $resolvedNode @handoffArguments 2>&1)
    $handoffExitCode = $LASTEXITCODE
    $handoffResult = Get-LastJsonObject -Lines $handoffOutput
    if ($handoffExitCode -ne 0 -or $null -eq $handoffResult) {
        $reason = if ($null -ne $handoffResult -and $handoffResult.PSObject.Properties['reason']) {
            [string]$handoffResult.reason
        } else {
            'local_browser_sandbox_handoff_failed'
        }
        throw $reason
    }
    $activatedTargetScope = [string]$handoffResult.activated_target_scope
    if ([string]$handoffResult.status -ne 'handoff_ready' -or
        [string]$handoffResult.platform -ne $Platform -or
        [string]$handoffResult.sandbox_id -ne $SandboxId -or
        [string]$handoffResult.isolation -ne 'process_profile' -or
        [bool]$handoffResult.target_activated -ne $true -or
        @('exact_start', 'pms_manage', 'login_entry') -notcontains $activatedTargetScope -or
        [bool]$handoffResult.session_material_exposed -ne $false -or
        [bool]$handoffResult.sensitive_values_exposed -ne $false
    ) {
        throw 'local_browser_sandbox_handoff_receipt_invalid'
    }
    $status = [string]$handoffResult.status
    $windowTargetActivated = $true
    $windowTargetReused = [bool]$handoffResult.target_reused

    $browserHost = Get-DedicatedBrowserHost `
        -ExpectedPort $Port `
        -ExpectedProfilePath $profilePath
    if ($null -eq $browserHost -or
        -not [bool]$browserHost.Trusted -or
        [bool]$browserHost.Headless
    ) {
        throw 'local_browser_sandbox_handoff_host_invalid'
    }
    try {
        $windowShell = New-Object -ComObject WScript.Shell
        $windowForegroundRequested = [bool]$windowShell.AppActivate(
            [int]$browserHost.ProcessId
        )
        [void][System.Runtime.InteropServices.Marshal]::FinalReleaseComObject(
            $windowShell
        )
    } catch {
        $windowForegroundRequested = $false
    }
}

$result = [ordered]@{
    status = $status
    cdp_status = 'ready'
    cdp_scope = 'loopback_only'
    cdp_port = $Port
    browser_started = $browserStarted
    headless = -not [bool]$InteractiveLogin
    mode_switch_performed = $modeSwitchPerformed
    platform = $Platform
    sandbox_id = $SandboxId
    isolation = [string]$bindResult.isolation
    start_url = [string]$bindResult.start_url
    session_status = if ($InteractiveLogin) {
        'login_required'
    } else {
        'unverified'
    }
    login_required = if ($InteractiveLogin) { $true } else { $null }
    window_target_activated = $windowTargetActivated
    window_target_reused = $windowTargetReused
    activated_target_scope = if ($InteractiveLogin) { $activatedTargetScope } else { $null }
    window_foreground_requested = $windowForegroundRequested
    next_action = if ($InteractiveLogin) {
        'complete_login_in_bound_browser_then_retry'
    } elseif ($status -eq 'awaiting_login') {
        'rerun_launcher_with_interactive_login'
    } else {
        'run_fast_collection_to_verify_session'
    }
    automatic_device_substitution = $false
    profile_material_copied = $false
    browser_process_exposed = $false
    raw_response_exposed = $false
    session_material_exposed = $false
    sensitive_values_exposed = $false
}

$result | ConvertTo-Json -Depth 5 -Compress
