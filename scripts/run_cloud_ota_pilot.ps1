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

    [string]$TargetDate = ''
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
    $bindingReady = [string]$binding.contract_version -eq 'suxios.cloud_ota_binding.v1' `
        -and [int]$binding.source_system_hotel_id -eq $HotelId `
        -and [int]$binding.destination_system_hotel_id -gt 0 `
        -and @($binding.bindings).Count -eq 2 `
        -and @($binding.bindings | Where-Object { [int]$_.source_data_source_id -le 0 -or [int]$_.destination_data_source_id -le 0 }).Count -eq 0 `
        -and $bindingPlatforms.Count -eq 2 `
        -and $bindingPlatforms -contains 'ctrip' `
        -and $bindingPlatforms -contains 'meituan'
}

$preflight = @(
    [pscustomobject]@{ name = 'project_root'; passed = $null -ne $resolvedProjectRoot; detail = $effectiveProjectRoot },
    [pscustomobject]@{ name = 'php_binary'; passed = $null -ne $resolvedPhpPath; detail = $PhpPath },
    [pscustomobject]@{ name = 'think_entry'; passed = (Test-Path -LiteralPath $thinkPath -PathType Leaf); detail = $thinkPath },
    [pscustomobject]@{ name = 'upload_script'; passed = (Test-Path -LiteralPath $uploadScriptPath -PathType Leaf); detail = $uploadScriptPath },
    [pscustomobject]@{ name = 'identity_file'; passed = $null -ne $resolvedIdentityFile; detail = $IdentityFile },
    [pscustomobject]@{ name = 'binding_file'; passed = $bindingReady; detail = $(if ($bindingReady) { $resolvedBindingPath } elseif ($bindingError -ne '') { $bindingError } else { $BindingPath }) },
    [pscustomobject]@{ name = 'target_date'; passed = $targetDateSafe; detail = $(if ($targetDateSafe) { $TargetDate } else { 'pilot runner only accepts yesterday in Asia/Shanghai' }) }
)
$preflightFailures = @($preflight | Where-Object { -not $_.passed })
$stateDirectory = Join-Path $effectiveProjectRoot "runtime\cloud_bridge\pilot-h$HotelId"
$outboxDirectory = Join-Path $stateDirectory 'outbox'
$bundlePath = Join-Path $outboxDirectory "ota-$TargetDate.json"
$statePath = Join-Path $stateDirectory 'publish-state.json'

$plan = [ordered]@{
    schema_version = 1
    mode = if ($Run) { 'run' } else { 'plan' }
    mutation_requested = [bool]$Run
    hotel_id = $HotelId
    target_date = $TargetDate
    binding_file = $resolvedBindingPath
    bundle_file = $bundlePath
    steps = @(
        "online-data:auto-fetch --hotel-id=$HotelId --target-date=$TargetDate",
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

$healthUri = [System.Uri]$HealthUrl
if (-not $healthUri.IsAbsoluteUri -or -not $healthUri.IsLoopback -or $healthUri.AbsolutePath.TrimEnd('/') -ne '/api/health') {
    throw 'HealthUrl must be a loopback /api/health URL.'
}
$healthResponse = Invoke-WebRequest -Uri $HealthUrl -Method Get -UseBasicParsing -TimeoutSec 10
if ([int]$healthResponse.StatusCode -lt 200 -or [int]$healthResponse.StatusCode -ge 300) {
    throw ('Local application health check failed with HTTP ' + [int]$healthResponse.StatusCode)
}

New-Item -ItemType Directory -Path $outboxDirectory -Force | Out-Null

$collectorOutput = & $resolvedPhpPath $thinkPath 'online-data:auto-fetch' "--hotel-id=$HotelId" "--target-date=$TargetDate" "--source-ids=$SourceIds" 2>&1
$collectorExitCode = $LASTEXITCODE
if ($collectorOutput) {
    $collectorOutput | ForEach-Object { Write-Output ("collector: " + [string]$_) }
}

$exportOutput = & $resolvedPhpPath $thinkPath 'cloud-data-bridge:run' '--mode=export' "--target-date=$TargetDate" '--platforms=ctrip,meituan' "--binding-file=$resolvedBindingPath" "--output-file=$bundlePath" 2>&1
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
    $result = [ordered]@{
        status = 'already_uploaded'
        hotel_id = $HotelId
        target_date = $TargetDate
        bundle_id = [string]$bundle.bundle_id
        collector_exit_code = $collectorExitCode
        collection_complete = $collectorExitCode -eq 0
        upload_performed = $false
    }
    Write-Output ($result | ConvertTo-Json -Depth 6)
    if ($collectorExitCode -ne 0) { exit 2 }
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
    collection_complete = $collectorExitCode -eq 0
    uploaded_at = [System.TimeZoneInfo]::ConvertTime((Get-Date), $chinaTimeZone).ToString('yyyy-MM-dd HH:mm:ss')
}
$stateTempPath = "$statePath.part-$PID"
[System.IO.File]::WriteAllText($stateTempPath, (($state | ConvertTo-Json -Depth 6) + [Environment]::NewLine), (New-Object System.Text.UTF8Encoding($false)))
Move-Item -LiteralPath $stateTempPath -Destination $statePath -Force

$result = [ordered]@{
    status = if ($collectorExitCode -eq 0) { 'uploaded' } else { 'uploaded_with_collection_failure' }
    hotel_id = $HotelId
    target_date = $TargetDate
    bundle_id = [string]$bundle.bundle_id
    collector_exit_code = $collectorExitCode
    collection_complete = $collectorExitCode -eq 0
    upload_performed = $true
    remote_file = [string]$uploadResult.RemoteFile
}
Write-Output ($result | ConvertTo-Json -Depth 6)
if ($collectorExitCode -ne 0) { exit 2 }
