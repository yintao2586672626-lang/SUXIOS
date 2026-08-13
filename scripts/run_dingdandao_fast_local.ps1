[CmdletBinding()]
param(
    [string]$ProjectRoot = '',

    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateRange(1, 2147483647)]
    [int]$HotelId = 80,

    [ValidateRange(1, 2147483647)]
    [int]$OwnerUserId = 1,

    [ValidatePattern('^\d{4}-\d{2}-\d{2}$')]
    [string]$TargetDate = (Get-Date -Format 'yyyy-MM-dd'),

    [ValidatePattern('^sbx_[A-Za-z0-9_-]{8,64}$')]
    [string]$SandboxId = 'sbx_dingdandao_h80_primary',

    [ValidatePattern('^http://127\.0\.0\.1:[1-9][0-9]{1,4}$')]
    [string]$CdpUrl = 'http://127.0.0.1:9223',

    [ValidateSet('operating_indicators', 'full_diagnostic')]
    [string]$CollectionMode = 'operating_indicators'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-JsonField {
    param(
        [AllowNull()][object]$Object,
        [string]$Name,
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

function ConvertTo-SafeReason {
    param([AllowNull()][object]$Value)

    $normalized = ([string]$Value).ToLowerInvariant() -replace '[^a-z0-9_-]+', '_'
    $normalized = $normalized.Trim('_')
    if ([string]::IsNullOrWhiteSpace($normalized)) {
        return 'dingdandao_fast_collection_failed'
    }
    return $normalized.Substring(0, [Math]::Min(100, $normalized.Length))
}

function Test-LocalCdp {
    param([string]$BaseUrl)

    try {
        $uri = [System.Uri]$BaseUrl
        $response = Invoke-RestMethod `
            -Uri "$BaseUrl/json/version" `
            -Method Get `
            -TimeoutSec 2 `
            -ErrorAction Stop
        $webSocketUrl = [string]$response.webSocketDebuggerUrl
        if ([string]::IsNullOrWhiteSpace($webSocketUrl)) {
            return $false
        }
        $webSocketUri = [System.Uri]$webSocketUrl
        return $webSocketUri.Scheme -eq 'ws' `
            -and $webSocketUri.Port -eq $uri.Port `
            -and $webSocketUri.Host -in @('127.0.0.1', 'localhost')
    } catch {
        return $false
    }
}

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$runnerPath = Join-Path $resolvedRoot 'scripts\run_dingdandao_local_collection.php'
if (-not (Test-Path -LiteralPath $runnerPath -PathType Leaf)) {
    throw 'dingdandao_fast_runner_missing'
}

$watch = [System.Diagnostics.Stopwatch]::StartNew()
$payload = $null
$exitCode = 1

if (Test-LocalCdp -BaseUrl $CdpUrl) {
    $runnerArguments = @(
        ('"{0}"' -f $runnerPath),
        "--hotel-id=$HotelId",
        "--owner-user-id=$OwnerUserId",
        "--target-date=$TargetDate",
        "--cdp-url=$CdpUrl",
        "--sandbox-id=$SandboxId",
        "--collection-mode=$CollectionMode",
        '--require-sandbox'
    )
    $temporaryDirectory = Join-Path $resolvedRoot 'runtime\dingdandao_fast'
    New-Item -ItemType Directory -Force -Path $temporaryDirectory | Out-Null
    $temporaryId = [Guid]::NewGuid().ToString('N')
    $stdoutPath = Join-Path $temporaryDirectory "$temporaryId.stdout.tmp"
    $stderrPath = Join-Path $temporaryDirectory "$temporaryId.stderr.tmp"
    try {
        $process = Start-Process `
            -FilePath $resolvedPhp `
            -ArgumentList $runnerArguments `
            -WorkingDirectory $resolvedRoot `
            -RedirectStandardOutput $stdoutPath `
            -RedirectStandardError $stderrPath `
            -Wait `
            -PassThru `
            -NoNewWindow
        $exitCode = [int]$process.ExitCode
        $output = @()
        foreach ($path in @($stdoutPath, $stderrPath)) {
            if (Test-Path -LiteralPath $path -PathType Leaf) {
                $output += @(Get-Content -LiteralPath $path)
            }
        }
        $payload = Get-LastJsonObject -Lines $output
    } finally {
        foreach ($path in @($stdoutPath, $stderrPath)) {
            if (Test-Path -LiteralPath $path -PathType Leaf) {
                Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
            }
        }
    }
} else {
    $payload = [pscustomobject]@{
        status = 'blocked'
        reason = 'dingdandao_local_cdp_unavailable'
    }
}

$watch.Stop()
$status = [string](Get-JsonField -Object $payload -Name 'status' -Fallback 'blocked')
$collectionMode = [string](
    Get-JsonField -Object $payload -Name 'collection_mode' -Fallback ''
)
$success = $exitCode -eq 0 `
    -and $status -eq 'saved_and_readback_verified' `
    -and $collectionMode -eq $CollectionMode

$result = [ordered]@{
    status = if ($success) { $status } else { 'blocked' }
    reason = if ($success) {
        ''
    } elseif ($exitCode -eq 0 -and $status -eq 'saved_and_readback_verified') {
        'dingdandao_fast_collection_mode_mismatch'
    } else {
        ConvertTo-SafeReason -Value (
            Get-JsonField -Object $payload -Name 'reason' -Fallback 'dingdandao_fast_collection_failed'
        )
    }
    execution_mode = 'loopback_cdp_structured_api'
    collection_mode = $CollectionMode
    fast_path = $CollectionMode -eq 'operating_indicators'
    duration_ms = [int]$watch.ElapsedMilliseconds
    hotel_id = $HotelId
    target_date = $TargetDate
    sandbox_id = $SandboxId
    sandbox_isolated = $true
    capture_id = [int](Get-JsonField -Object $payload -Name 'capture_id' -Fallback 0)
    provider = [string](Get-JsonField -Object $payload -Name 'provider' -Fallback 'dingdandao_pms')
    provider_hotel_name = [string](Get-JsonField -Object $payload -Name 'provider_hotel_name' -Fallback '')
    identity_status = [string](Get-JsonField -Object $payload -Name 'identity_status' -Fallback 'not_verified')
    reconciliation_status = [string](Get-JsonField -Object $payload -Name 'reconciliation_status' -Fallback 'not_verified')
    quality_status = [string](Get-JsonField -Object $payload -Name 'quality_status' -Fallback 'not_verified')
    readback_status = [string](Get-JsonField -Object $payload -Name 'readback_status' -Fallback 'not_verified')
    summary = Get-JsonField -Object $payload -Name 'summary' -Fallback $null
    detail_row_count = [int](Get-JsonField -Object $payload -Name 'detail_row_count' -Fallback 0)
    trend_point_counts = Get-JsonField -Object $payload -Name 'trend_point_counts' -Fallback $null
    regional_benchmark = Get-JsonField -Object $payload -Name 'regional_benchmark' -Fallback $null
    forward_room_status = Get-JsonField -Object $payload -Name 'forward_room_status' -Fallback $null
    operating_target_sync = Get-JsonField -Object $payload -Name 'operating_target_sync' -Fallback $null
    business_data_persisted = $success
    push_requested = $false
    message_sent = $false
    raw_response_exposed = $false
    session_material_exposed = $false
    sensitive_values_exposed = $false
}

$result | ConvertTo-Json -Depth 12
exit $(if ($success) { 0 } else { 1 })
