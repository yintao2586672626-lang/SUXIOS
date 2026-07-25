[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$ProjectRoot,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$PhpPath,

    [ValidateSet('Daily', 'Realtime')]
    [string]$Mode = 'Daily'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$utf8 = [System.Text.UTF8Encoding]::new($false)

$resolvedRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
$thinkPath = Join-Path $resolvedRoot 'think'
if (-not (Test-Path -LiteralPath $thinkPath -PathType Leaf)) {
    throw "Think entry was not found: $thinkPath"
}

$logDirectory = Join-Path $resolvedRoot 'runtime\dispatcher'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$runId = Get-Date -Format 'yyyyMMdd_HHmmss'
$logPath = Join-Path $logDirectory "ota_dispatcher_$runId.log"

function ConvertTo-SafeDispatcherLine {
    param([AllowNull()][object]$Value)

    $line = [string]$Value
    # The scheduled command must never put authorization material in its
    # diagnostic log. Suppress a whole suspicious line instead of attempting
    # partial redaction that can leave a new platform key format exposed.
    if ($line -match '(?i)(?:cookie|spidertoken|authorization|password|secret|session|credential|profile)\s*(?:[:=]|[\"''])') {
        return '[sensitive dispatcher output suppressed]'
    }
    # Keep the task log machine-readable. Native PHP localized output has an
    # inconsistent legacy-console decode on this Windows host; the structured
    # receipt below is the authoritative diagnostic evidence and contains the
    # hotel/date/platform state without exposing a garbled display name.
    if ($line -notmatch '^SUXIOS_AUTO_FETCH_RECEIPT=' -and $line -match '[^\x00-\x7F]') {
        return '[localized dispatcher detail omitted; inspect the safe receipt]'
    }
    return $line
}

$startedAt = Get-Date -Format 'yyyy-MM-dd HH:mm:ss K'
$lines = @("[$startedAt] SUXIOS OTA dispatcher started.")
$exitCode = 1
$stdoutPath = Join-Path $logDirectory "ota_dispatcher_$runId.stdout.tmp"
$stderrPath = Join-Path $logDirectory "ota_dispatcher_$runId.stderr.tmp"
$scheduleArgument = if ($Mode -eq 'Realtime') { '--realtime-only' } else { '--daily-only' }
try {
    $process = Start-Process `
        -FilePath $resolvedPhp `
        -ArgumentList @('"' + $thinkPath + '"', 'online-data:auto-fetch', $scheduleArgument) `
        -WorkingDirectory $resolvedRoot `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath `
        -Wait `
        -PassThru `
        -NoNewWindow
    $exitCode = [int]$process.ExitCode
    $rawOutput = @()
    foreach ($capturePath in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $capturePath -PathType Leaf) {
            $rawOutput += [System.IO.File]::ReadAllLines($capturePath, $utf8)
        }
    }
    foreach ($entry in $rawOutput) {
        $lines += ConvertTo-SafeDispatcherLine -Value $entry
    }
} catch {
    $lines += ('dispatcher_exception=' + $_.Exception.GetType().FullName)
    $exitCode = 1
} finally {
    foreach ($capturePath in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $capturePath -PathType Leaf) {
            Remove-Item -LiteralPath $capturePath -Force -ErrorAction SilentlyContinue
        }
    }
}

$finishedAt = Get-Date -Format 'yyyy-MM-dd HH:mm:ss K'
$lines += "[$finishedAt] SUXIOS OTA dispatcher finished. exit_code=$exitCode"
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, [System.Text.UTF8Encoding]::new($false))

Write-Output "dispatcher_log=$logPath"
Write-Output "dispatcher_exit_code=$exitCode"
exit $exitCode
