[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$HealthUrl = 'http://127.0.0.1:8080/api/health'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function ConvertTo-SafeText {
    param([object]$Value, [int]$Limit = 120)

    $text = [string]$Value
    $text = $text -replace '(?i)(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+', '$1=<redacted>'
    if ($text.Length -gt $Limit) {
        return $text.Substring(0, $Limit)
    }
    return $text
}

function Get-SafeProperty {
    param([object]$Value, [string]$Name, [object]$Default = $null)

    if ($null -eq $Value) {
        return $Default
    }
    $property = $Value.PSObject.Properties[$Name]
    if ($null -eq $property) {
        return $Default
    }
    return $property.Value
}

function Get-SafeSourceSummary {
    param([object]$Value)

    if ($null -eq $Value) {
        return $null
    }
    return [ordered]@{
        status = ConvertTo-SafeText (Get-SafeProperty $Value 'status' '') 32
        reason_code = ConvertTo-SafeText (Get-SafeProperty $Value 'reason_code' '') 80
        target_date = ConvertTo-SafeText (Get-SafeProperty $Value 'target_date' '') 10
        saved_count = [Math]::Max(0, [int](Get-SafeProperty $Value 'saved_count' 0))
        readback_verified = [bool](Get-SafeProperty $Value 'readback_verified' $false)
        sync_task_id = [Math]::Max(0, [int](Get-SafeProperty $Value 'sync_task_id' 0))
        capture_id = [Math]::Max(0, [int](Get-SafeProperty $Value 'capture_id' 0))
    }
}

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $PSScriptRoot
}
$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$runId = [Guid]::NewGuid().ToString('N')
$startedAt = [DateTimeOffset]::Now
$logDirectory = Join-Path $resolvedRoot 'runtime\manual_notification_scheduler'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$logPath = Join-Path $logDirectory ("hotel80_three_source_wecom_{0}_{1}.log" -f $startedAt.ToString('yyyyMMdd_HHmmss'), $runId)

$receipt = [ordered]@{
    schema_version = 1
    run_id = $runId
    started_at = $startedAt.ToString('o')
    finished_at = $null
    timezone = 'Asia/Shanghai'
    scope = [ordered]@{
        tenant_id = 80
        hotel_id = 80
        robot_id = 1
        sources = @('dingdandao_pms', 'ctrip:25', 'meituan:68')
        business_date_rule = 'today'
        interval_minutes = 30
    }
    preflight_status = 'blocked'
    schedule_status = 'not_started'
    exit_code = 2
    summary = $null
    raw_output_persisted = $false
    sensitive_values_exposed = $false
}

try {
    $health = Invoke-RestMethod -Uri $HealthUrl -Method Get -TimeoutSec 8 -ErrorAction Stop
    if ([string]$health.status -ne 'ok') {
        throw 'local_health_not_ready'
    }
    & $resolvedPhp 'think' 'db:check' *> $null
    if ($LASTEXITCODE -ne 0) {
        throw 'database_schema_check_failed'
    }
    $receipt.preflight_status = 'ready'

    Push-Location $resolvedRoot
    try {
        $rawOutput = & $resolvedPhp 'think' 'manual-notification:schedule' '--dispatch' '--mode=formal' '--hotel-id=80' '--robot-id=1' '--limit=1' 2>&1 | Out-String
        $childExitCode = [int]$LASTEXITCODE
    } finally {
        Pop-Location
    }

    $parsed = $null
    $start = $rawOutput.IndexOf('{')
    $finish = $rawOutput.LastIndexOf('}')
    if ($start -ge 0 -and $finish -ge $start) {
        try {
            $parsed = $rawOutput.Substring($start, $finish - $start + 1) | ConvertFrom-Json -ErrorAction Stop
        } catch {
            $parsed = $null
        }
    }
    if ($null -eq $parsed) {
        $receipt.schedule_status = 'receipt_unavailable'
        $sha256 = [Security.Cryptography.SHA256]::Create()
        try {
            $outputHash = [BitConverter]::ToString(
                $sha256.ComputeHash([Text.Encoding]::UTF8.GetBytes($rawOutput))
            ).Replace('-', '').ToLowerInvariant()
        } finally {
            $sha256.Dispose()
        }
        $receipt.summary = [ordered]@{
            output_sha256 = $outputHash
        }
        $receipt.exit_code = if ($childExitCode -eq 0) { 2 } else { $childExitCode }
    } else {
        $safeResults = @()
        foreach ($result in @((Get-SafeProperty $parsed 'results' @()))) {
            $safeResults += [ordered]@{
                notification_id = [Math]::Max(0, [int](Get-SafeProperty $result 'notification_id' 0))
                hotel_id = [Math]::Max(0, [int](Get-SafeProperty $result 'hotel_id' 0))
                business_date = ConvertTo-SafeText (Get-SafeProperty $result 'business_date' '') 10
                dispatch_window = ConvertTo-SafeText (Get-SafeProperty $result 'dispatch_window' '') 32
                status = ConvertTo-SafeText (Get-SafeProperty $result 'status' '') 32
                reason_code = ConvertTo-SafeText (Get-SafeProperty $result 'reason_code' '') 80
                dispatch_id = [Math]::Max(0, [int](Get-SafeProperty $result 'dispatch_id' 0))
                delivery_attempted = [bool](Get-SafeProperty $result 'delivery_attempted' $false)
                pms = Get-SafeSourceSummary (Get-SafeProperty $result 'pms_source_preparation' $null)
                ctrip = Get-SafeSourceSummary (Get-SafeProperty $result 'ctrip_source_preparation' $null)
                meituan = Get-SafeSourceSummary (Get-SafeProperty $result 'source_preparation' $null)
            }
        }
        $receipt.schedule_status = ConvertTo-SafeText (Get-SafeProperty $parsed 'status' '') 40
        $receipt.summary = [ordered]@{
            observed_at = ConvertTo-SafeText (Get-SafeProperty $parsed 'observed_at' '') 24
            candidate_count = [Math]::Max(0, [int](Get-SafeProperty $parsed 'candidate_count' 0))
            due_count = [Math]::Max(0, [int](Get-SafeProperty $parsed 'due_count' 0))
            sent_count = [Math]::Max(0, [int](Get-SafeProperty $parsed 'sent_count' 0))
            failed_count = [Math]::Max(0, [int](Get-SafeProperty $parsed 'failed_count' 0))
            blocked_count = [Math]::Max(0, [int](Get-SafeProperty $parsed 'blocked_count' 0))
            schedule_run_id = [Math]::Max(0, [int](Get-SafeProperty $parsed 'schedule_run_id' 0))
            results = $safeResults
        }
        $receipt.exit_code = $childExitCode
    }
} catch {
    $receipt.schedule_status = ConvertTo-SafeText $_.Exception.Message 80
    $receipt.exit_code = 2
}

$receipt.finished_at = [DateTimeOffset]::Now.ToString('o')
$line = 'SUXIOS_THREE_SOURCE_WECOM=' + ($receipt | ConvertTo-Json -Depth 8 -Compress)
[IO.File]::WriteAllText($logPath, $line + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
Write-Output $line
exit ([int]$receipt.exit_code)
