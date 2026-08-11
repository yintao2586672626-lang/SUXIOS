[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$ProjectRoot,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$PhpPath,

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

    [ValidateRange(-7, 0)]
    [int]$TargetDateOffsetDays = 0,

    [ValidateRange(30, 1200)]
    [int]$TimeoutSeconds = 300,

    [switch]$Push
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$utf8 = [System.Text.UTF8Encoding]::new($false)

function Get-JsonField {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Fallback = $null
    )

    if ($null -eq $Object) {
        return $Fallback
    }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $Fallback
    }
    return $property.Value
}

function Read-LastJsonText {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string[]]$Texts)

    foreach ($text in $Texts) {
        if ([string]::IsNullOrWhiteSpace($text)) {
            continue
        }
        $lines = $text -split "`r?`n"
        for ($index = $lines.Length - 1; $index -ge 0; $index--) {
            $line = $lines[$index].Trim()
            if (-not $line.StartsWith('{')) {
                continue
            }
            try {
                return $line | ConvertFrom-Json
            } catch {
                continue
            }
        }
    }
    return $null
}

function ConvertTo-SafeReason {
    param([AllowNull()][object]$Value)

    $normalized = ([string]$Value).ToLowerInvariant() -replace '[^a-z0-9_-]+', '_'
    $normalized = $normalized.Trim('_')
    if ([string]::IsNullOrWhiteSpace($normalized)) {
        return 'dingdandao_scheduled_runner_failed'
    }
    return $normalized.Substring(0, [Math]::Min(80, $normalized.Length))
}

$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$runnerPath = Join-Path $resolvedRoot 'scripts\run_dingdandao_local_collection.php'
$browserLauncherPath = Join-Path $resolvedRoot 'scripts\open_local_browser_sandbox.ps1'
if (-not (Test-Path -LiteralPath $runnerPath -PathType Leaf)) {
    throw "Dingdandao local runner was not found: $runnerPath"
}
if (-not (Test-Path -LiteralPath $browserLauncherPath -PathType Leaf)) {
    throw "Dingdandao local browser launcher was not found: $browserLauncherPath"
}
if ([System.Uri]$CdpUrl -as [System.Uri]) {
    $cdpUri = [System.Uri]$CdpUrl
} else {
    throw 'CDP URL is invalid.'
}
if (-not $cdpUri.IsLoopback -or $cdpUri.Scheme -ne 'http' -or $cdpUri.Port -gt 65535) {
    throw 'CDP URL must be a loopback HTTP endpoint.'
}
if ($TargetDateOffsetDays -lt 0 -and $CollectionMode -ne 'operating_indicators') {
    throw 'Historical collection requires operating_indicators mode.'
}

$receiptRoot = Join-Path $resolvedRoot 'runtime\dingdandao_local_scheduler'
$receiptScope = if ($TargetDateOffsetDays -lt 0) {
    Join-Path $CollectionMode "historical_offset_$([Math]::Abs($TargetDateOffsetDays))"
} else {
    $CollectionMode
}
$receiptDirectory = Join-Path `
    (Join-Path (Join-Path $receiptRoot "hotel_$HotelId") "user_$OwnerUserId") `
    $receiptScope
New-Item -ItemType Directory -Force -Path $receiptDirectory | Out-Null
$runId = Get-Date -Format 'yyyyMMdd_HHmmss_fff'
$startedAt = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ssK')
$targetDate = (Get-Date).Date.AddDays($TargetDateOffsetDays).ToString('yyyy-MM-dd')
$exitCode = 1
$payload = $null
$browserHost = $null
$browserVisibleReused = $false

$processArguments = @(
    ('"{0}"' -f $runnerPath),
    "--hotel-id=$HotelId",
    "--owner-user-id=$OwnerUserId",
    "--target-date=$targetDate",
    "--cdp-url=$CdpUrl",
    "--sandbox-id=$SandboxId",
    "--collection-mode=$CollectionMode",
    '--require-sandbox'
)
if ($Push) {
    $processArguments += '--push'
}

try {
    try {
        $launcherOutput = @(
            & $browserLauncherPath `
                -ProjectRoot $resolvedRoot `
                -Port $cdpUri.Port `
                -Platform 'dingdandao' `
                -SandboxId $SandboxId 2>&1
        )
    } catch {
        $launcherReason = ConvertTo-SafeReason -Value $_.Exception.Message
        if ($launcherReason -ne 'local_browser_sandbox_mode_switch_required') {
            throw
        }
        $launcherOutput = @(
            & $browserLauncherPath `
                -ProjectRoot $resolvedRoot `
                -Port $cdpUri.Port `
                -Platform 'dingdandao' `
                -SandboxId $SandboxId `
                -InteractiveLogin 2>&1
        )
        $browserVisibleReused = $true
    }
    $browserHost = ($launcherOutput -join [Environment]::NewLine) |
        ConvertFrom-Json -ErrorAction Stop
    if ($null -eq $browserHost -or
        ([string](Get-JsonField -Object $browserHost -Name 'cdp_status' -Fallback '') -ne 'ready') -or
        ([string](Get-JsonField -Object $browserHost -Name 'isolation' -Fallback '') -ne 'process_profile')
    ) {
        throw 'local_browser_sandbox_start_failed'
    }

    $processStartInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $processStartInfo.FileName = $resolvedPhp
    $processStartInfo.Arguments = $processArguments -join ' '
    $processStartInfo.WorkingDirectory = $resolvedRoot
    $processStartInfo.UseShellExecute = $false
    $processStartInfo.RedirectStandardOutput = $true
    $processStartInfo.RedirectStandardError = $true
    $processStartInfo.CreateNoWindow = $true
    $processStartInfo.StandardOutputEncoding = $utf8
    $processStartInfo.StandardErrorEncoding = $utf8
    $process = [System.Diagnostics.Process]::new()
    $process.StartInfo = $processStartInfo
    try {
        if (-not $process.Start()) {
            throw 'dingdandao_scheduled_collector_start_failed'
        }
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        if (-not $process.WaitForExit($TimeoutSeconds * 1000)) {
            Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
            throw 'dingdandao_scheduled_collector_timeout'
        }
        $process.WaitForExit()
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        $exitCode = [int]$process.ExitCode
        $payload = Read-LastJsonText -Texts @($stdout, $stderr)
        if ($null -eq $payload) {
            throw 'dingdandao_scheduled_collector_output_invalid'
        }
    } finally {
        $process.Dispose()
    }
} catch {
    $payload = [pscustomobject]@{
        status = 'blocked'
        reason = ConvertTo-SafeReason -Value $_.Exception.Message
        collection_success = $false
        business_data_persisted = $false
        hotel_id = $HotelId
        target_date = $targetDate
        sandbox_id = $SandboxId
        sandbox_selection = 'explicit_marker'
        collection_mode = $CollectionMode
        message_sent = $false
    }
    $exitCode = 1
}

$payloadStatus = [string](Get-JsonField -Object $payload -Name 'status' -Fallback 'blocked')
$payloadCollectionMode = [string](
    Get-JsonField -Object $payload -Name 'collection_mode' -Fallback ''
)
$payloadHotelId = [int](Get-JsonField -Object $payload -Name 'hotel_id' -Fallback 0)
$payloadTargetDate = [string](
    Get-JsonField -Object $payload -Name 'target_date' -Fallback ''
)
$payloadSandboxId = [string](
    Get-JsonField -Object $payload -Name 'sandbox_id' -Fallback ''
)
$payloadSandboxSelection = [string](
    Get-JsonField -Object $payload -Name 'sandbox_selection' -Fallback ''
)
$payloadCaptureId = [int](
    Get-JsonField -Object $payload -Name 'capture_id' -Fallback 0
)
$payloadIdentityStatus = [string](
    Get-JsonField -Object $payload -Name 'identity_status' -Fallback ''
)
$payloadReconciliationStatus = [string](
    Get-JsonField -Object $payload -Name 'reconciliation_status' -Fallback ''
)
$payloadQualityStatus = [string](
    Get-JsonField -Object $payload -Name 'quality_status' -Fallback ''
)
$payloadReadbackStatus = [string](
    Get-JsonField -Object $payload -Name 'readback_status' -Fallback ''
)
$payloadBusinessDataPersisted = [bool](
    Get-JsonField -Object $payload -Name 'business_data_persisted' -Fallback $false
)
$payloadCollectionSuccess = [bool](
    Get-JsonField -Object $payload -Name 'collection_success' -Fallback $false
)
$scopeMismatchCodes = @()
if ($payloadHotelId -ne $HotelId) {
    $scopeMismatchCodes += 'hotel_scope_mismatch'
}
if ($payloadTargetDate -notmatch '^\d{4}-\d{2}-\d{2}$' -or
    $payloadTargetDate -ne $targetDate
) {
    $scopeMismatchCodes += 'target_date_scope_mismatch'
}
if ($payloadSandboxId -ne $SandboxId -or
    $payloadSandboxSelection -ne 'explicit_marker'
) {
    $scopeMismatchCodes += 'sandbox_scope_mismatch'
}
if ($payloadCollectionMode -ne $CollectionMode) {
    $scopeMismatchCodes += 'collection_mode_scope_mismatch'
}
$collectionSuccess = $payloadStatus -in @(
        'saved_and_readback_verified',
        'saved_downstream_blocked'
    ) `
    -and $payloadCollectionSuccess `
    -and $payloadBusinessDataPersisted `
    -and $payloadCaptureId -gt 0 `
    -and $payloadIdentityStatus -eq 'matched' `
    -and $payloadReconciliationStatus -eq 'matched' `
    -and $payloadQualityStatus -eq 'verified' `
    -and $payloadReadbackStatus -eq 'readback_verified' `
    -and $scopeMismatchCodes.Count -eq 0
$downstreamSatisfied = $exitCode -eq 0 `
    -and $payloadStatus -eq 'saved_and_readback_verified'
$componentCoverage = Get-JsonField -Object $payload -Name 'component_coverage' -Fallback $null
$componentOverallStatus = [string](
    Get-JsonField -Object $componentCoverage -Name 'overall_status' -Fallback 'partial'
)
$diagnosticSatisfied = $CollectionMode -ne 'full_diagnostic' -or
    $componentOverallStatus -eq 'verified'
$pushPayload = Get-JsonField -Object $payload -Name 'push' -Fallback $null
$deliveryStatus = [string](
    Get-JsonField -Object $pushPayload -Name 'delivery_status' -Fallback 'not_requested'
)
$messageSent = [bool](
    Get-JsonField -Object $pushPayload -Name 'message_sent' -Fallback $false
)
$deliverySatisfied = (-not [bool]$Push) -or $messageSent
$success = $collectionSuccess `
    -and $downstreamSatisfied `
    -and $deliverySatisfied `
    -and $diagnosticSatisfied
$partial = $collectionSuccess -and (
    -not $downstreamSatisfied `
    -or -not $deliverySatisfied `
    -or -not $diagnosticSatisfied
)
$finalExitCode = if ($success) { 0 } elseif ($partial) { 3 } elseif ($exitCode -ne 0) {
    $exitCode
} else {
    1
}
$reason = if ($success) {
    ''
} elseif ($partial) {
    if (-not $downstreamSatisfied) {
        'dingdandao_scheduled_downstream_not_completed'
    } elseif (-not $diagnosticSatisfied) {
        'dingdandao_scheduled_diagnostic_partial'
    } else {
        'dingdandao_scheduled_delivery_not_completed'
    }
} elseif ($scopeMismatchCodes.Count -gt 0) {
    'dingdandao_scheduled_' + ($scopeMismatchCodes -join '_')
} elseif ($exitCode -eq 0 -and $payloadStatus -eq 'saved_and_readback_verified') {
    'dingdandao_scheduled_collection_mode_mismatch'
} else {
    ConvertTo-SafeReason -Value (Get-JsonField -Object $payload -Name 'reason' -Fallback 'dingdandao_scheduled_runner_failed')
}

$receipt = [ordered]@{
    schema_version = 1
    run_id = $runId
    status = if ($success) { 'success' } elseif ($partial) { 'partial' } else { 'blocked' }
    source = 'dingdandao'
    execution_mode = 'local_shared_browser_sandbox'
    collection_mode = $CollectionMode
    target_date_offset_days = $TargetDateOffsetDays
    target_date_role = if ($TargetDateOffsetDays -lt 0) {
        'historical_business_date'
    } else {
        'capture_date'
    }
    hotel_id = $HotelId
    owner_user_id = $OwnerUserId
    target_date = if ($payloadTargetDate -match '^\d{4}-\d{2}-\d{2}$') {
        $payloadTargetDate
    } else {
        $null
    }
    sandbox_id = $SandboxId
    sandbox_selection = 'explicit_marker'
    cdp_scope = 'loopback'
    browser_host_status = [string](Get-JsonField -Object $browserHost -Name 'cdp_status' -Fallback 'not_ready')
    browser_host_started = [bool](Get-JsonField -Object $browserHost -Name 'browser_started' -Fallback $false)
    browser_headless = [bool](Get-JsonField -Object $browserHost -Name 'headless' -Fallback $false)
    browser_visible_reused = $browserVisibleReused
    collection_success = $collectionSuccess
    downstream_satisfied = $downstreamSatisfied
    diagnostic_satisfied = $diagnosticSatisfied
    component_coverage = $componentCoverage
    scope_mismatch_codes = $scopeMismatchCodes
    push_requested = [bool]$Push
    message_sent = $messageSent
    delivery_status = $deliveryStatus
    delivery_satisfied = $deliverySatisfied
    delivery_result_code = [string](
        Get-JsonField -Object $pushPayload -Name 'result_code' -Fallback ''
    )
    response_reference = Get-JsonField -Object $pushPayload -Name 'response_reference' -Fallback $null
    payload_bytes = Get-JsonField -Object $pushPayload -Name 'payload_bytes' -Fallback $null
    delivery_blocker_codes = @(
        Get-JsonField -Object $pushPayload -Name 'blocker_codes' -Fallback @()
    )
    capture_id = $payloadCaptureId
    identity_status = $payloadIdentityStatus
    reconciliation_status = $payloadReconciliationStatus
    quality_status = $payloadQualityStatus
    readback_status = $payloadReadbackStatus
    business_data_persisted = $payloadBusinessDataPersisted
    reason = $reason
    collector_exit_code = $exitCode
    exit_code = $finalExitCode
    started_at = $startedAt
    finished_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ssK')
    raw_response_exposed = $false
    session_material_exposed = $false
    sensitive_values_exposed = $false
}

$receiptJson = $receipt | ConvertTo-Json -Depth 6
$historyPath = Join-Path $receiptDirectory "run_$runId.json"
$latestPath = Join-Path $receiptDirectory 'latest.json'
$latestTempPath = Join-Path $receiptDirectory "latest_$runId.tmp"
[System.IO.File]::WriteAllText($historyPath, $receiptJson + [Environment]::NewLine, $utf8)
[System.IO.File]::WriteAllText($latestTempPath, $receiptJson + [Environment]::NewLine, $utf8)
Move-Item -LiteralPath $latestTempPath -Destination $latestPath -Force

Write-Output $receiptJson
exit $finalExitCode
