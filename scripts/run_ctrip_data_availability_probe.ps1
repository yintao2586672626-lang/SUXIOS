[CmdletBinding(DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Run')]
    [switch]$Run,

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidateRange(1, 2147483647)]
    [int]$CtripSourceId = 25,

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedProjectRoot = if (Test-Path -LiteralPath $ProjectRoot -PathType Container) { (Resolve-Path -LiteralPath $ProjectRoot).Path } else { $null }
$effectiveProjectRoot = if ($null -ne $resolvedProjectRoot) { $resolvedProjectRoot } else { $ProjectRoot }
$resolvedPhpPath = if (Test-Path -LiteralPath $PhpPath -PathType Leaf) { (Resolve-Path -LiteralPath $PhpPath).Path } else { $null }
$thinkPath = Join-Path $effectiveProjectRoot 'think'
$checkerPath = Join-Path $effectiveProjectRoot 'scripts\check_ctrip_data_availability.php'
$resolvedThinkPath = if (Test-Path -LiteralPath $thinkPath -PathType Leaf) { (Resolve-Path -LiteralPath $thinkPath).Path } else { $null }
$resolvedCheckerPath = if (Test-Path -LiteralPath $checkerPath -PathType Leaf) { (Resolve-Path -LiteralPath $checkerPath).Path } else { $null }

$chinaTimeZone = [System.TimeZoneInfo]::FindSystemTimeZoneById('China Standard Time')
$chinaNow = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone)
$targetDate = $chinaNow.Date.AddDays(-2).ToString('yyyy-MM-dd')
$observedAt = $chinaNow.ToString('yyyy-MM-dd HH:mm:ss')

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

$stateDirectory = Join-Path $effectiveProjectRoot "runtime\ctrip-availability\h$HotelId"
$statePath = Join-Path $stateDirectory 'probe-state.json'
$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedProjectRoot; detail = $effectiveProjectRoot },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhpPath; detail = $PhpPath },
    [pscustomobject]@{ name = 'think_entry'; passed = $null -ne $resolvedThinkPath; detail = $thinkPath },
    [pscustomobject]@{ name = 'availability_checker'; passed = $null -ne $resolvedCheckerPath; detail = $checkerPath },
    [pscustomobject]@{ name = 'health_url_boundary'; passed = $healthSafe; detail = $HealthUrl },
    [pscustomobject]@{ name = 'application_health'; passed = $healthPassed; detail = $healthDetail }
)
$preflightFailures = @($preflight | Where-Object { -not $_.passed })
$plan = [ordered]@{
    schema_version = 1
    mode = if ($Run) { 'run' } else { 'plan' }
    mutation_requested = [bool]$Run
    system_hotel_id = $HotelId
    data_source_id = $CtripSourceId
    source = 'ctrip'
    target_date = $targetDate
    target_date_rule = 'Asia/Shanghai today minus 2 days'
    success_criteria = @('ctrip_readback_present', 'qunar_traffic_positive')
    state_file = $statePath
    preflight = $preflight
    run_ready = $preflightFailures.Count -eq 0
}
if (-not $Run) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    return
}
if ($preflightFailures.Count -gt 0) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw ('Ctrip availability probe refused because preflight checks failed: ' + (($preflightFailures | ForEach-Object { $_.name }) -join ', '))
}

New-Item -ItemType Directory -Path $stateDirectory -Force | Out-Null
$collectorOutput = @(& $resolvedPhpPath $resolvedThinkPath 'online-data:auto-fetch' "--hotel-id=$HotelId" "--target-date=$targetDate" "--source-ids=$CtripSourceId" '--force-rerun' 2>&1)
$collectorExitCode = $LASTEXITCODE
$receiptPrefix = 'SUXIOS_AUTO_FETCH_RECEIPT='
$receiptLine = @($collectorOutput | ForEach-Object { [string]$_ } | Where-Object { $_.StartsWith($receiptPrefix) } | Select-Object -Last 1)
$receipt = $null
if ($receiptLine.Count -eq 1) {
    try {
        $receipt = $receiptLine[0].Substring($receiptPrefix.Length) | ConvertFrom-Json
    } catch {
        $receipt = $null
    }
}
$sourceTask = @()
if ($null -ne $receipt) {
    $sourceTask = @($receipt.source_tasks | Where-Object { [int]$_.data_source_id -eq $CtripSourceId } | Select-Object -First 1)
}
$receiptVerified = $null -ne $receipt `
    -and [bool]$receipt.collection_complete `
    -and [int]$receipt.hotel_id -eq $HotelId `
    -and [string]$receipt.target_date -eq $targetDate `
    -and $sourceTask.Count -eq 1 `
    -and [int]$sourceTask[0].sync_task_id -gt 0

$checkerOutput = @(& $resolvedPhpPath $resolvedCheckerPath "--system-hotel-id=$HotelId" "--data-source-id=$CtripSourceId" "--target-date=$targetDate" 2>&1)
$checkerExitCode = $LASTEXITCODE
$checkerJsonLine = @($checkerOutput | ForEach-Object { [string]$_ } | Where-Object { $_.TrimStart().StartsWith('{') } | Select-Object -Last 1)
$availability = $null
if ($checkerJsonLine.Count -eq 1) {
    try {
        $availability = $checkerJsonLine[0] | ConvertFrom-Json
    } catch {
        $availability = $null
    }
}
$isAvailable = $null -ne $availability -and [bool]$availability.available
$collectionTaskVerified = $null -ne $availability -and [bool]$availability.collection_task_verified
$observationValid = $null -ne $availability -and ($receiptVerified -or $collectionTaskVerified)
$status = if ($isAvailable) { 'available' } elseif ($observationValid) { 'waiting' } else { 'collection_failed' }
$completedAt = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')

$previous = $null
if (Test-Path -LiteralPath $statePath -PathType Leaf) {
    try {
        $previous = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        $previous = $null
    }
}
$sameTarget = $null -ne $previous -and [string]$previous.target_date -eq $targetDate
$firstAvailableAt = if ($sameTarget -and -not [string]::IsNullOrWhiteSpace([string]$previous.first_available_at)) {
    [string]$previous.first_available_at
} elseif ($isAvailable) {
    $completedAt
} else {
    $null
}
$attemptHistory = New-Object 'System.Collections.Generic.List[object]'
if ($sameTarget -and $null -ne $previous.attempts) {
    foreach ($previousAttempt in @($previous.attempts)) {
        $attemptHistory.Add($previousAttempt)
    }
}
$evidence = if ($null -ne $availability -and $null -ne $availability.evidence) { $availability.evidence } else { $null }
$gaps = if ($null -ne $availability -and $null -ne $availability.gaps) { @($availability.gaps) } else { @('availability_readback_failed') }
$attempt = [ordered]@{
    observed_at = $completedAt
    status = $status
    available = $isAvailable
    collector_exit_code = $collectorExitCode
    collection_receipt_verified = $receiptVerified
    collection_task_verified = $collectionTaskVerified
    checker_exit_code = $checkerExitCode
    ctrip_readback_rows = if ($null -ne $evidence) { [int]$evidence.ctrip_readback_rows } else { 0 }
    qunar_positive_traffic_rows = if ($null -ne $evidence) { [int]$evidence.qunar_positive_traffic_rows } else { 0 }
}
$attemptHistory.Add([pscustomobject]$attempt)
$attempts = @($attemptHistory.ToArray() | Select-Object -Last 20)
$state = [ordered]@{
    schema_version = 1
    status = $status
    source = 'ctrip_browser_profile'
    system_hotel_id = $HotelId
    data_source_id = $CtripSourceId
    target_date = $targetDate
    observed_at = $completedAt
    first_available_at = $firstAvailableAt
    criteria = [ordered]@{
        ctrip_readback_present = $isAvailable -or ($null -ne $availability -and [bool]$availability.criteria.ctrip_readback_present)
        qunar_traffic_positive = $isAvailable -or ($null -ne $availability -and [bool]$availability.criteria.qunar_traffic_positive)
    }
    evidence = $evidence
    collection = [ordered]@{
        exit_code = $collectorExitCode
        receipt_verified = $receiptVerified
        task_verified = $collectionTaskVerified
        sync_task_id = if ($sourceTask.Count -eq 1) { [int]$sourceTask[0].sync_task_id } elseif ($null -ne $evidence) { [int]$evidence.latest_sync_task_id } else { 0 }
    }
    available = $isAvailable
    claim_allowed = $isAvailable
    gaps = @($gaps)
    attempts = $attempts
}
$tempStatePath = "$statePath.part-$PID"
[System.IO.File]::WriteAllText($tempStatePath, (($state | ConvertTo-Json -Depth 8) + [Environment]::NewLine), (New-Object System.Text.UTF8Encoding($false)))
Move-Item -LiteralPath $tempStatePath -Destination $statePath -Force
Write-Output ($state | ConvertTo-Json -Depth 8)
if ($status -eq 'collection_failed') {
    exit 2
}
