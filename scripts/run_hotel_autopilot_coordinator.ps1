[CmdletBinding()]
param(
    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$receiptPrefix = 'SUXIOS_HOTEL_AUTOPILOT_COORDINATOR='
$exitCode = 1
$mutex = $null
$mutexAcquired = $false

function Get-ProjectMutexName {
    param([Parameter(Mandatory = $true)][string]$ResolvedRoot)

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($ResolvedRoot.ToUpperInvariant())
        $digest = ([System.BitConverter]::ToString($sha256.ComputeHash($bytes)) -replace '-', '').ToLowerInvariant()
        return "Local\SUXIOS_HOTEL_AUTOPILOT_COORDINATOR_$($digest.Substring(0, 24))"
    } finally {
        $sha256.Dispose()
    }
}

function Write-CoordinatorReceipt {
    param(
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][string]$ReasonCode,
        [Parameter(Mandatory = $true)][int]$CommandExitCode
    )

    $payload = [ordered]@{
        schema_version = 'suxios_hotel_autopilot_coordinator.v1'
        status = $Status
        reason_code = $ReasonCode
        command_exit_code = $CommandExitCode
        provision_dispatchers = $true
        sensitive_values_exposed = $false
    }
    Write-Output ($receiptPrefix + ($payload | ConvertTo-Json -Depth 4 -Compress))
}

try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $ProjectRoot = Split-Path -Parent $PSScriptRoot
    }
    $resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    $resolvedPhpPath = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
    $thinkPath = Join-Path $resolvedProjectRoot 'think'
    $composerPath = Join-Path $resolvedProjectRoot 'composer.json'
    if (-not (Test-Path -LiteralPath $thinkPath -PathType Leaf) `
        -or -not (Test-Path -LiteralPath $composerPath -PathType Leaf)
    ) {
        throw 'project_identity_invalid'
    }

    $mutexName = Get-ProjectMutexName -ResolvedRoot $resolvedProjectRoot
    $mutex = [System.Threading.Mutex]::new($false, $mutexName)
    try {
        $mutexAcquired = $mutex.WaitOne(0)
    } catch [System.Threading.AbandonedMutexException] {
        $mutexAcquired = $true
    }
    if (-not $mutexAcquired) {
        Write-CoordinatorReceipt -Status 'skipped' -ReasonCode 'coordinator_already_running' -CommandExitCode 0
        exit 0
    }

    # The command owns its durable lifecycle receipts. Suppress its console
    # payload here so the Scheduled Task surface emits only this safe summary.
    $commandOutput = @(& $resolvedPhpPath $thinkPath 'hotel:autopilot-reconcile' '--all-pages' '--provision-dispatchers' 2>&1)
    $commandExitCode = [int]$LASTEXITCODE
    if ($commandExitCode -ne 0) {
        Write-CoordinatorReceipt -Status 'blocked' -ReasonCode 'reconcile_command_failed' -CommandExitCode $commandExitCode
        $exitCode = $commandExitCode
    } else {
        Write-CoordinatorReceipt -Status 'ready' -ReasonCode 'reconcile_completed' -CommandExitCode 0
        $exitCode = 0
    }
} catch {
    $safeReason = [string]$_.Exception.Message
    if ($safeReason -notmatch '^[a-z0-9_]+$') {
        $safeReason = 'coordinator_runner_failed'
    }
    Write-CoordinatorReceipt -Status 'blocked' -ReasonCode $safeReason -CommandExitCode 1
    $exitCode = 1
} finally {
    if ($mutexAcquired -and $null -ne $mutex) {
        $mutex.ReleaseMutex()
    }
    if ($null -ne $mutex) {
        $mutex.Dispose()
    }
}

exit $exitCode
