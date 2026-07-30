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

function Read-LastJsonObject {
    param([Parameter(Mandatory = $true)][string[]]$Paths)

    foreach ($path in $Paths) {
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
            continue
        }
        $lines = [System.IO.File]::ReadAllLines($path, $utf8)
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

$receiptDirectory = Join-Path $resolvedRoot 'runtime\dingdandao_local_scheduler'
New-Item -ItemType Directory -Force -Path $receiptDirectory | Out-Null
$runId = Get-Date -Format 'yyyyMMdd_HHmmss_fff'
$stdoutPath = Join-Path $receiptDirectory "run_$runId.stdout.tmp"
$stderrPath = Join-Path $receiptDirectory "run_$runId.stderr.tmp"
$startedAt = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ssK')
$exitCode = 1
$payload = $null
$browserHost = $null

$processArguments = @(
    ('"{0}"' -f $runnerPath),
    "--hotel-id=$HotelId",
    "--owner-user-id=$OwnerUserId",
    "--cdp-url=$CdpUrl",
    "--sandbox-id=$SandboxId",
    "--collection-mode=$CollectionMode",
    '--require-sandbox'
)
if ($Push) {
    $processArguments += '--push'
}

try {
    $launcherOutput = @(
        & $browserLauncherPath `
            -ProjectRoot $resolvedRoot `
            -Port $cdpUri.Port `
            -Platform 'dingdandao' `
            -SandboxId $SandboxId 2>&1
    )
    $browserHost = ($launcherOutput -join [Environment]::NewLine) |
        ConvertFrom-Json -ErrorAction Stop
    if ($null -eq $browserHost -or
        ([string](Get-JsonField -Object $browserHost -Name 'cdp_status' -Fallback '') -ne 'ready') -or
        ([bool](Get-JsonField -Object $browserHost -Name 'headless' -Fallback $false) -ne $true) -or
        ([string](Get-JsonField -Object $browserHost -Name 'isolation' -Fallback '') -ne 'process_profile')
    ) {
        throw 'local_browser_sandbox_headless_start_failed'
    }

    $process = Start-Process `
        -FilePath $resolvedPhp `
        -ArgumentList $processArguments `
        -WorkingDirectory $resolvedRoot `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath `
        -Wait `
        -PassThru `
        -NoNewWindow
    $exitCode = [int]$process.ExitCode
    $payload = Read-LastJsonObject -Paths @($stdoutPath, $stderrPath)
} catch {
    $payload = [pscustomobject]@{
        status = 'blocked'
        reason = ConvertTo-SafeReason -Value $_.Exception.Message
        business_data_persisted = $false
        message_sent = $false
    }
    $exitCode = 1
} finally {
    foreach ($path in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
        }
    }
}

$payloadStatus = [string](Get-JsonField -Object $payload -Name 'status' -Fallback 'blocked')
$payloadCollectionMode = [string](
    Get-JsonField -Object $payload -Name 'collection_mode' -Fallback ''
)
$success = $exitCode -eq 0 `
    -and $payloadStatus -eq 'saved_and_readback_verified' `
    -and $payloadCollectionMode -eq $CollectionMode
$pushPayload = Get-JsonField -Object $payload -Name 'push' -Fallback $null
$reason = if ($success) {
    ''
} elseif ($exitCode -eq 0 -and $payloadStatus -eq 'saved_and_readback_verified') {
    'dingdandao_scheduled_collection_mode_mismatch'
} else {
    ConvertTo-SafeReason -Value (Get-JsonField -Object $payload -Name 'reason' -Fallback 'dingdandao_scheduled_runner_failed')
}

$receipt = [ordered]@{
    schema_version = 1
    run_id = $runId
    status = if ($success) { 'success' } else { 'blocked' }
    source = 'dingdandao'
    execution_mode = 'local_shared_browser_sandbox'
    collection_mode = $CollectionMode
    hotel_id = $HotelId
    owner_user_id = $OwnerUserId
    target_date = (Get-Date).ToString('yyyy-MM-dd')
    sandbox_id = $SandboxId
    sandbox_selection = 'explicit_marker'
    cdp_scope = 'loopback'
    browser_host_status = [string](Get-JsonField -Object $browserHost -Name 'cdp_status' -Fallback 'not_ready')
    browser_host_started = [bool](Get-JsonField -Object $browserHost -Name 'browser_started' -Fallback $false)
    browser_headless = [bool](Get-JsonField -Object $browserHost -Name 'headless' -Fallback $false)
    push_requested = [bool]$Push
    message_sent = [bool](Get-JsonField -Object $pushPayload -Name 'message_sent' -Fallback $false)
    delivery_status = [string](Get-JsonField -Object $pushPayload -Name 'delivery_status' -Fallback 'not_requested')
    capture_id = [int](Get-JsonField -Object $payload -Name 'capture_id' -Fallback 0)
    quality_status = [string](Get-JsonField -Object $payload -Name 'quality_status' -Fallback 'not_verified')
    readback_status = [string](Get-JsonField -Object $payload -Name 'readback_status' -Fallback 'not_verified')
    business_data_persisted = [bool](Get-JsonField -Object $payload -Name 'business_data_persisted' -Fallback $success)
    reason = $reason
    exit_code = $exitCode
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
exit $exitCode
