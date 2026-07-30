[CmdletBinding()]
param(
    [string]$HealthUrl = 'https://122.51.64.165/api/health',
    [string]$StateDirectory = '',
    [string]$WebhookSecretPath = '',
    [ValidateRange(1, 12)]
    [int]$FailureThreshold = 3
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$hotelRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$privateRoot = Join-Path (Split-Path -Parent $hotelRoot) '.private'
if ([string]::IsNullOrWhiteSpace($StateDirectory)) {
    $StateDirectory = Join-Path $privateRoot 'monitor'
}
if ([string]::IsNullOrWhiteSpace($WebhookSecretPath)) {
    $WebhookSecretPath = Join-Path $StateDirectory 'wecom-webhook.dpapi'
}

$healthUri = [Uri]$HealthUrl
if (-not $healthUri.IsAbsoluteUri `
    -or $healthUri.Scheme -ne 'https' `
    -or $healthUri.Host -ne '122.51.64.165' `
    -or $healthUri.AbsolutePath -ne '/api/health') {
    throw 'HealthUrl must be the fixed SUXIOS HTTPS health endpoint.'
}
if (-not (Test-Path -LiteralPath $WebhookSecretPath -PathType Leaf)) {
    throw "Encrypted WeCom webhook is missing: $WebhookSecretPath"
}

$curl = 'C:\Windows\System32\curl.exe'
if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) {
    throw 'Windows curl.exe is unavailable.'
}

New-Item -ItemType Directory -Path $StateDirectory -Force | Out-Null
$statePath = Join-Path $StateDirectory 'cloud-health-state.json'
$responsePath = Join-Path $StateDirectory ('.health-' + [Guid]::NewGuid().ToString('N') + '.json')
$now = Get-Date

$state = [ordered]@{
    consecutive_failures = 0
    alert_open = $false
    last_status = 'unknown'
    last_check_at = $null
    last_alert_at = $null
    last_recovery_at = $null
    last_error = $null
}
if (Test-Path -LiteralPath $statePath -PathType Leaf) {
    try {
        $saved = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
        foreach ($name in @('consecutive_failures','alert_open','last_status','last_check_at','last_alert_at','last_recovery_at','last_error')) {
            if ($null -ne $saved.PSObject.Properties[$name]) {
                $state[$name] = $saved.$name
            }
        }
    } catch {
        $state.last_error = 'previous_state_invalid'
    }
}

function Get-WebhookUrl {
    $encrypted = (Get-Content -LiteralPath $WebhookSecretPath -Raw -Encoding UTF8).Trim()
    if ($encrypted -eq '') {
        throw 'Encrypted WeCom webhook is empty.'
    }
    $secure = ConvertTo-SecureString $encrypted
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try {
        $webhook = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
    $uri = [Uri]$webhook
    if ($uri.Scheme -ne 'https' `
        -or $uri.Host -ne 'qyapi.weixin.qq.com' `
        -or $uri.AbsolutePath -ne '/cgi-bin/webhook/send' `
        -or $uri.Query -notmatch '^\?key=[A-Za-z0-9-]{16,128}$') {
        throw 'Decrypted WeCom webhook failed the fixed endpoint boundary.'
    }
    return $webhook
}

function Send-WeComAlert([string]$Title, [string]$Body) {
    $webhook = Get-WebhookUrl
    $payload = [ordered]@{
        msgtype = 'markdown'
        markdown = [ordered]@{ content = "## $Title`n$Body" }
    } | ConvertTo-Json -Depth 5 -Compress
    $response = Invoke-RestMethod -Uri $webhook -Method Post -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($payload)) -TimeoutSec 15
    if ([int]$response.errcode -ne 0) {
        throw ('WeCom delivery failed with errcode ' + [int]$response.errcode)
    }
}

function ConvertFrom-Utf8Base64([string]$Value) {
    return [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($Value))
}

$healthy = $false
$httpCode = ''
$failureReason = ''
try {
    $httpCode = [string](& $curl '-kfsS' '--connect-timeout' '10' '--max-time' '20' '--output' $responsePath '--write-out' '%{http_code}' $HealthUrl)
    if ($LASTEXITCODE -ne 0) {
        throw "curl_exit_$LASTEXITCODE"
    }
    if ($httpCode -ne '200') {
        throw "http_$httpCode"
    }
    $health = Get-Content -LiteralPath $responsePath -Raw -Encoding UTF8 | ConvertFrom-Json
    $healthy = [string]$health.status -eq 'ok' `
        -and [string]$health.checks.application -eq 'ok' `
        -and [string]$health.checks.database -eq 'ok'
    if (-not $healthy) {
        throw 'health_payload_not_ok'
    }
} catch {
    $healthy = $false
    $failureReason = $_.Exception.Message
} finally {
    if (Test-Path -LiteralPath $responsePath) {
        Remove-Item -LiteralPath $responsePath -Force
    }
}

$delivery = 'none'
if ($healthy) {
    if ([bool]$state.alert_open) {
        $recoveryTitle = ConvertFrom-Utf8Base64 '5a6/5p6QT1Mg5LqR5pyN5Yqh5Zmo5bey5oGi5aSN'
        $recoveryBody = (ConvertFrom-Utf8Base64 'PiDmo4DmtYvlnLDlnYDvvJoxMjIuNTEuNjQuMTY1Cj4g5oGi5aSN5pe26Ze077yaezB9Cj4g5bqU55So5LiO5pWw5o2u5bqT5YGl5bq35qOA5p+l5Z2H5bey5oGi5aSN44CCCj4g5pys6YCa55+l5p2l6Ieq5pyN5Yqh5Zmo5aSW6YOo55uR5o6n77yM5LiN6Kem5Y+RIE9UQSDph4fpm4bmiJblubPlj7DlhpnlhaXjgII=') -f $now.ToString('yyyy-MM-dd HH:mm:ss')
        Send-WeComAlert $recoveryTitle $recoveryBody
        $state.last_recovery_at = $now.ToString('o')
        $delivery = 'recovery_sent'
    }
    $state.consecutive_failures = 0
    $state.alert_open = $false
    $state.last_status = 'healthy'
    $state.last_error = $null
} else {
    $state.consecutive_failures = [int]$state.consecutive_failures + 1
    $state.last_status = 'unhealthy'
    $state.last_error = if ($failureReason.Length -gt 180) { $failureReason.Substring(0, 180) } else { $failureReason }
    if ([int]$state.consecutive_failures -ge $FailureThreshold -and -not [bool]$state.alert_open) {
        $outageTitle = ConvertFrom-Utf8Base64 '5a6/5p6QT1Mg5LqR5pyN5Yqh5Zmo5pWF6Zqc6aKE6K2m'
        $outageBody = (ConvertFrom-Utf8Base64 'PiDmo4DmtYvlnLDlnYDvvJoxMjIuNTEuNjQuMTY1Cj4g6L+e57ut5aSx6LSl77yaezB9IOasoQo+IOaXtumXtO+8mnsxfQo+IOWOn+WboO+8mnsyfQo+IOivt+ajgOafpeiFvuiur+S6keWunuS+i+OAgU5naW5444CBUEhQLUZQTSDkuI4gTWFyaWFEQuOAggo+IOacrOmAmuefpeadpeiHquacjeWKoeWZqOWklumDqOebkeaOp++8jOS4jeinpuWPkSBPVEEg6YeH6ZuG5oiW5bmz5Y+w5YaZ5YWl44CC') -f $state.consecutive_failures, $now.ToString('yyyy-MM-dd HH:mm:ss'), $state.last_error
        Send-WeComAlert $outageTitle $outageBody
        $state.alert_open = $true
        $state.last_alert_at = $now.ToString('o')
        $delivery = 'outage_sent'
    }
}

$state.last_check_at = $now.ToString('o')
$stateJson = $state | ConvertTo-Json -Depth 4
[IO.File]::WriteAllText($statePath, $stateJson, [Text.UTF8Encoding]::new($false))

[pscustomobject]@{
    Status = [string]$state.last_status
    CheckedAt = $now
    HttpCode = $httpCode
    ConsecutiveFailures = [int]$state.consecutive_failures
    AlertOpen = [bool]$state.alert_open
    Delivery = $delivery
} | ConvertTo-Json -Compress

if (-not $healthy) {
    exit 2
}
