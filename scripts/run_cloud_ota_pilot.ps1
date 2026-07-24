[CmdletBinding(DefaultParameterSetName = 'Plan')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Run')]
    [switch]$Run,

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidateNotNullOrEmpty()]
    [string]$SourceIds = '25,68',

    [string]$ProjectRoot = '',

    [string]$BindingPath = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health',

    [ValidateNotNullOrEmpty()]
    [string]$IdentityFile = 'C:\Users\Administrator\.ssh\suxios-lighthouse-shanghai.pem',

    [string]$TargetDate = '',

    [Parameter(ParameterSetName = 'Run')]
    [switch]$ForceRerun
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

$chinaTimeZone = [System.TimeZoneInfo]::FindSystemTimeZoneById('China Standard Time')
$chinaNow = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone)
$expectedTargetDate = $chinaNow.Date.AddDays(-1).ToString('yyyy-MM-dd')
if ([string]::IsNullOrWhiteSpace($TargetDate)) {
    $TargetDate = $expectedTargetDate
}
$targetDateSafe = $TargetDate -match '^\d{4}-\d{2}-\d{2}$' -and $TargetDate -eq $expectedTargetDate

$thinkPath = Join-Path $effectiveProjectRoot 'think'
$uploadScriptPath = Join-Path $effectiveProjectRoot 'scripts\upload_cloud_ota_bundle.ps1'
$resolvedPhpPath = if (Test-Path -LiteralPath $PhpPath -PathType Leaf) { (Resolve-Path -LiteralPath $PhpPath).Path } else { $null }
$resolvedIdentityFile = if (Test-Path -LiteralPath $IdentityFile -PathType Leaf) { (Resolve-Path -LiteralPath $IdentityFile).Path } else { $null }
$resolvedBindingPath = if (Test-Path -LiteralPath $BindingPath -PathType Leaf) { (Resolve-Path -LiteralPath $BindingPath).Path } else { $null }

$binding = $null
$bindingError = ''
$bindingSourceIds = @()
if ($null -ne $resolvedBindingPath) {
    try {
        $binding = Get-Content -LiteralPath $resolvedBindingPath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        $bindingError = 'binding_json_invalid'
    }
}
$bindingPlatforms = @()
$bindingReady = $false
if ($null -ne $binding) {
    $bindingPlatforms = @($binding.bindings | ForEach-Object { [string]$_.platform } | Sort-Object -Unique)
    $bindingSourceIds = @($binding.bindings | ForEach-Object { [int]$_.source_data_source_id } | Sort-Object -Unique)
    $bindingReady = [string]$binding.contract_version -eq 'suxios.cloud_ota_binding.v1' `
        -and [int]$binding.source_system_hotel_id -eq $HotelId `
        -and [int]$binding.destination_system_hotel_id -gt 0 `
        -and @($binding.bindings).Count -eq 2 `
        -and @($binding.bindings | Where-Object { [int]$_.source_data_source_id -le 0 -or [int]$_.destination_data_source_id -le 0 }).Count -eq 0 `
        -and $bindingPlatforms.Count -eq 2 `
        -and $bindingPlatforms -contains 'ctrip' `
        -and $bindingPlatforms -contains 'meituan'
}

$sourceIdsReady = $true
$sourceIdValues = @()
$sourceIdsDetail = ''
try {
    $sourceIdValues = @($SourceIds.Split(',') | ForEach-Object {
        $candidate = $_.Trim()
        if ($candidate -notmatch '^\d+$' -or [int]$candidate -le 0) {
            throw 'source_ids_invalid'
        }
        [int]$candidate
    } | Sort-Object -Unique)
    if ($sourceIdValues.Count -eq 0) {
        throw 'source_ids_empty'
    }
    $sourceIdsDetail = $sourceIdValues -join ','
} catch {
    $sourceIdsReady = $false
    $sourceIdsDetail = 'source IDs must be a comma-separated list of positive integers'
}
$sourceIdsMatchBinding = $false
if ($sourceIdsReady -and $bindingReady -and $sourceIdValues.Count -eq $bindingSourceIds.Count) {
    $sourceIdsMatchBinding = @(Compare-Object -ReferenceObject $sourceIdValues -DifferenceObject $bindingSourceIds).Count -eq 0
}
$normalizedSourceIds = if ($sourceIdsReady) { $sourceIdValues -join ',' } else { $SourceIds }

$healthSafe = $false
$healthPassed = $false
$healthDetail = 'health URL boundary failed'
try {
    $healthUri = [System.Uri]$HealthUrl
    $healthSafe = $healthUri.IsAbsoluteUri -and $healthUri.IsLoopback -and $healthUri.AbsolutePath.TrimEnd('/') -eq '/api/health'
    if ($healthSafe) {
        $healthResponse = Invoke-WebRequest -Uri $HealthUrl -Method Get -UseBasicParsing -TimeoutSec 5
        $healthPayload = $healthResponse.Content | ConvertFrom-Json
        $healthPassed = [int]$healthResponse.StatusCode -ge 200 `
            -and [int]$healthResponse.StatusCode -lt 300 `
            -and [string]$healthPayload.status -eq 'ok' `
            -and [string]$healthPayload.checks.application -eq 'ok' `
            -and [string]$healthPayload.checks.database -eq 'ok'
        $healthDetail = if ($healthPassed) { 'HTTP ' + [int]$healthResponse.StatusCode + ', application/database ready' } else { 'health payload is not ready' }
    }
} catch {
    $healthDetail = 'health check failed: ' + $_.Exception.GetType().Name
}

$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedProjectRoot; detail = $effectiveProjectRoot },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhpPath; detail = $PhpPath },
    [pscustomobject]@{ name = 'think_entry'; passed = (Test-Path -LiteralPath $thinkPath -PathType Leaf); detail = $thinkPath },
    [pscustomobject]@{ name = 'upload_script'; passed = (Test-Path -LiteralPath $uploadScriptPath -PathType Leaf); detail = $uploadScriptPath },
    [pscustomobject]@{ name = 'identity_file'; passed = $null -ne $resolvedIdentityFile; detail = $IdentityFile },
    [pscustomobject]@{ name = 'binding_file'; passed = $bindingReady; detail = $(if ($bindingReady) { $resolvedBindingPath } elseif ($bindingError -ne '') { $bindingError } else { $BindingPath }) },
    [pscustomobject]@{ name = 'source_ids'; passed = $sourceIdsReady; detail = $sourceIdsDetail },
    [pscustomobject]@{ name = 'source_binding_match'; passed = $sourceIdsMatchBinding; detail = $(if ($sourceIdsMatchBinding) { $normalizedSourceIds } else { 'collector source IDs must exactly match binding source_data_source_id values' }) },
    [pscustomobject]@{ name = 'application_health'; passed = $healthPassed; detail = $healthDetail },
    [pscustomobject]@{ name = 'target_date'; passed = $targetDateSafe; detail = $(if ($targetDateSafe) { $TargetDate } else { 'pilot runner only accepts yesterday in Asia/Shanghai' }) }
)
$preflightFailures = @($preflight | Where-Object { -not $_.passed })
$stateDirectory = Join-Path $effectiveProjectRoot "runtime\cloud_bridge\pilot-h$HotelId"
$outboxDirectory = Join-Path $stateDirectory 'outbox'
$bundlePath = Join-Path $outboxDirectory "ota-$TargetDate.json"
$statePath = Join-Path $stateDirectory 'publish-state.json'

function Write-PilotPublishState {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,

        [Parameter(Mandatory = $true)]
        [System.Collections.IDictionary]$State
    )

    $tempPath = "$Path.part-$PID"
    [System.IO.File]::WriteAllText($tempPath, (($State | ConvertTo-Json -Depth 6) + [Environment]::NewLine), (New-Object System.Text.UTF8Encoding($false)))
    Move-Item -LiteralPath $tempPath -Destination $Path -Force
}

$plan = [ordered]@{
    schema_version = 1
    mode = if ($Run) { 'run' } else { 'plan' }
    mutation_requested = [bool]$Run
    hotel_id = $HotelId
    target_date = $TargetDate
    binding_file = $resolvedBindingPath
    bundle_file = $bundlePath
    steps = @(
        "online-data:auto-fetch --hotel-id=$HotelId --target-date=$TargetDate --source-ids=$normalizedSourceIds$(if ($ForceRerun) { ' --force-rerun' } else { '' })",
        'cloud-data-bridge:run --mode=export',
        'credential-free bundle upload to the controlled cloud inbox'
    )
    safety = [ordered]@{
        hotel_scope_is_explicit = $true
        target_is_yesterday_only = $true
        other_hotels_scanned = $false
        browser_profiles_uploaded = $false
        credentials_written_to_bundle = $false
        duplicate_bundle_upload_skipped = $true
        force_rerun_requested = [bool]$ForceRerun
    }
    preflight = $preflight
    run_ready = $preflightFailures.Count -eq 0
}

if (-not $Run) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    return
}
if ($preflightFailures.Count -gt 0) {
    Write-Output ($plan | ConvertTo-Json -Depth 8)
    throw ('Pilot run refused because preflight checks failed: ' + (($preflightFailures | ForEach-Object { $_.name }) -join ', '))
}

New-Item -ItemType Directory -Path $outboxDirectory -Force | Out-Null

$collectorArguments = @(
    'online-data:auto-fetch',
    "--hotel-id=$HotelId",
    "--target-date=$TargetDate",
    "--source-ids=$normalizedSourceIds"
)
if ($ForceRerun) {
    $collectorArguments += '--force-rerun'
}
$collectorOutput = & $resolvedPhpPath $thinkPath @collectorArguments 2>&1
$collectorExitCode = $LASTEXITCODE
if ($collectorOutput) {
    $collectorOutput | ForEach-Object { Write-Output ("collector: " + [string]$_) }
}

$receiptPrefix = 'SUXIOS_AUTO_FETCH_RECEIPT='
$receiptLine = @($collectorOutput | ForEach-Object { [string]$_ } | Where-Object { $_.StartsWith($receiptPrefix) } | Select-Object -Last 1)
$collectionReceipt = $null
if ($receiptLine.Count -eq 1) {
    try {
        $collectionReceipt = $receiptLine[0].Substring($receiptPrefix.Length) | ConvertFrom-Json
    } catch {
        $collectionReceipt = $null
    }
}

$collectionReceiptValid = $false
$receiptCollectionComplete = $false
$receiptSnapshotExportable = $false
$receiptStatus = ''
$syncTaskIds = ''
try {
    if ($null -eq $collectionReceipt) {
        throw 'collection_receipt_missing'
    }
    $receiptSourceIds = @($collectionReceipt.source_ids | ForEach-Object { [int]$_ } | Sort-Object -Unique)
    $receiptSourceTasks = @($collectionReceipt.source_tasks)
    $taskSourceIds = @($receiptSourceTasks | ForEach-Object { [int]$_.data_source_id } | Sort-Object -Unique)
    $invalidSourceTasks = @($receiptSourceTasks | Where-Object {
        [int]$_.data_source_id -le 0 `
            -or [int]$_.sync_task_id -le 0 `
            -or [string]$_.platform -notin @('ctrip', 'meituan') `
            -or [string]$_.collection_status -notin @('success', 'partial') `
            -or [string]$_.p0_status -ne 'ready' `
            -or @($_.row_ids | Where-Object { [int]$_ -gt 0 }).Count -eq 0
    })
    $receiptRequiredPlatforms = @($collectionReceipt.required_platforms | ForEach-Object {
        [string]$_
    } | Sort-Object -Unique)
    $requiredPlatformsMatch = $receiptRequiredPlatforms.Count -eq 2 `
        -and @(Compare-Object -ReferenceObject @('ctrip', 'meituan') -DifferenceObject $receiptRequiredPlatforms).Count -eq 0
    $receiptScopeMatches = $receiptSourceIds.Count -eq $sourceIdValues.Count `
        -and @(Compare-Object -ReferenceObject $sourceIdValues -DifferenceObject $receiptSourceIds).Count -eq 0 `
        -and $taskSourceIds.Count -eq $sourceIdValues.Count `
        -and @(Compare-Object -ReferenceObject $sourceIdValues -DifferenceObject $taskSourceIds).Count -eq 0 `
        -and $requiredPlatformsMatch
    $receiptCollectionComplete = [bool]$collectionReceipt.collection_complete
    $receiptSnapshotExportable = [bool]$collectionReceipt.exportable_snapshot_complete
    $receiptStatus = [string]$collectionReceipt.status
    $collectionReceiptValid = $collectorExitCode -in @(0, 1) `
        -and $receiptSnapshotExportable `
        -and [int]$collectionReceipt.hotel_id -eq $HotelId `
        -and [string]$collectionReceipt.target_date -eq $TargetDate `
        -and $receiptScopeMatches `
        -and $invalidSourceTasks.Count -eq 0
    if ($collectionReceiptValid) {
        $syncTaskIds = @($receiptSourceTasks | Sort-Object { [int]$_.data_source_id } | ForEach-Object {
            '{0}:{1}' -f [int]$_.data_source_id, [int]$_.sync_task_id
        }) -join ','
    }
} catch {
    $collectionReceiptValid = $false
}

if (-not $collectionReceiptValid) {
    $state = [ordered]@{
        schema_version = 1
        status = 'collection_failed'
        hotel_id = $HotelId
        target_date = $TargetDate
        bundle_id = ''
        row_count = 0
        collector_exit_code = $collectorExitCode
        collection_complete = $false
        snapshot_exportable = $false
        collection_status = $receiptStatus
        collection_receipt_verified = $false
        upload_complete = $false
        upload_performed = $false
        last_attempt_at = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')
    }
    Write-PilotPublishState -Path $statePath -State $state
    Write-Output ($state | ConvertTo-Json -Depth 6)
    exit 2
}

$exportOutput = & $resolvedPhpPath $thinkPath 'cloud-data-bridge:run' '--mode=export' "--target-date=$TargetDate" '--platforms=ctrip,meituan' "--sync-task-ids=$syncTaskIds" "--binding-file=$resolvedBindingPath" "--output-file=$bundlePath" 2>&1
$exportExitCode = $LASTEXITCODE
if ($exportOutput) {
    $exportOutput | ForEach-Object { Write-Output ("export: " + [string]$_) }
}
if ($exportExitCode -ne 0 -or -not (Test-Path -LiteralPath $bundlePath -PathType Leaf)) {
    throw "Cloud bundle export failed with exit code $exportExitCode."
}

$bundleJson = Get-Content -LiteralPath $bundlePath -Raw -Encoding UTF8
if ($bundleJson -match '(?i)"(?:cookie|cookies|authorization|token|password|webhook|secret|secret_json)"\s*:') {
    throw 'Cloud bundle contains a forbidden credential-shaped field.'
}
$bundle = $bundleJson | ConvertFrom-Json
if ([string]$bundle.contract_version -ne 'suxios.cloud_ota_bundle.v1' `
    -or [int]$bundle.source_system_hotel_id -ne $HotelId `
    -or [string]$bundle.target_date -ne $TargetDate `
    -or [string]$bundle.bundle_id -notmatch '^[a-f0-9]{64}$'
) {
    throw 'Cloud bundle identity validation failed.'
}
$bundlePackages = @($bundle.packages)
$expectedSourceTaskPairs = @($receiptSourceTasks | Sort-Object { [int]$_.data_source_id } | ForEach-Object {
    '{0}:{1}' -f [int]$_.data_source_id, [int]$_.sync_task_id
})
$bundleSourceTaskPairs = @($bundlePackages | Sort-Object { [int]$_.source_data_source_id } | ForEach-Object {
    '{0}:{1}' -f [int]$_.source_data_source_id, [int]$_.source_sync_task_id
})
$invalidBundlePackages = @($bundlePackages | Where-Object {
    [int]$_.source_data_source_id -le 0 `
        -or [int]$_.source_sync_task_id -le 0 `
        -or [string]$_.collection.status -notin @('success', 'partial') `
        -or ([string]$_.collection.status -eq 'success' -and -not [bool]$_.snapshot_complete) `
        -or [int]$_.row_count -le 0 `
        -or [int]$_.source_row_count -lt [int]$_.row_count `
        -or @($_.rows).Count -ne [int]$_.row_count
})
$bundleReceiptMatches = $bundlePackages.Count -eq $receiptSourceTasks.Count `
    -and @(Compare-Object -ReferenceObject $expectedSourceTaskPairs -DifferenceObject $bundleSourceTaskPairs).Count -eq 0
if (-not $bundleReceiptMatches -or $invalidBundlePackages.Count -gt 0) {
    throw 'Cloud bundle package receipt validation failed.'
}

$previousState = $null
if (Test-Path -LiteralPath $statePath -PathType Leaf) {
    try {
        $previousState = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        $previousState = $null
    }
}
if ($null -ne $previousState `
    -and [string]$previousState.status -eq 'uploaded' `
    -and [string]$previousState.bundle_id -eq [string]$bundle.bundle_id
) {
    $previousUploadedAt = if ($previousState.PSObject.Properties.Name -contains 'uploaded_at') {
        [string]$previousState.uploaded_at
    } else {
        ''
    }
    $state = [ordered]@{
        schema_version = 1
        status = 'uploaded'
        hotel_id = $HotelId
        target_date = $TargetDate
        bundle_id = [string]$bundle.bundle_id
        row_count = @($bundle.packages | ForEach-Object { @($_.rows).Count } | Measure-Object -Sum).Sum
        collector_exit_code = $collectorExitCode
        collection_complete = $receiptCollectionComplete
        snapshot_exportable = $true
        collection_status = $receiptStatus
        collection_receipt_verified = $true
        upload_complete = $true
        upload_performed = $false
        uploaded_at = $previousUploadedAt
        last_attempt_at = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')
    }
    Write-PilotPublishState -Path $statePath -State $state
    $result = [ordered]@{
        status = 'already_uploaded'
        hotel_id = $HotelId
        target_date = $TargetDate
        bundle_id = [string]$bundle.bundle_id
        collector_exit_code = $collectorExitCode
        collection_complete = $receiptCollectionComplete
        snapshot_exportable = $true
        collection_status = $receiptStatus
        upload_complete = $true
        upload_performed = $false
    }
    Write-Output ($result | ConvertTo-Json -Depth 6)
    return
}

$uploadResult = & $uploadScriptPath -BundlePath $bundlePath -IdentityFile $resolvedIdentityFile
$uploadExitCode = $LASTEXITCODE
if ($uploadExitCode -ne 0) {
    throw "Cloud bundle upload failed with exit code $uploadExitCode."
}

$state = [ordered]@{
    schema_version = 1
    status = 'uploaded'
    hotel_id = $HotelId
    target_date = $TargetDate
    bundle_id = [string]$bundle.bundle_id
    row_count = @($bundle.packages | ForEach-Object { @($_.rows).Count } | Measure-Object -Sum).Sum
    collector_exit_code = $collectorExitCode
    collection_complete = $receiptCollectionComplete
    snapshot_exportable = $true
    collection_status = $receiptStatus
    collection_receipt_verified = $true
    upload_complete = $true
    upload_performed = $true
    uploaded_at = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')
    last_attempt_at = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')
}
Write-PilotPublishState -Path $statePath -State $state

$result = [ordered]@{
    status = 'uploaded'
    hotel_id = $HotelId
    target_date = $TargetDate
    bundle_id = [string]$bundle.bundle_id
    collector_exit_code = $collectorExitCode
    collection_complete = $receiptCollectionComplete
    snapshot_exportable = $true
    collection_status = $receiptStatus
    upload_complete = $true
    upload_performed = $true
    remote_file = [string]$uploadResult.RemoteFile
}
Write-Output ($result | ConvertTo-Json -Depth 6)
