[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int]$HotelId,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[1-9][0-9]*(?:,[1-9][0-9]*)*$')]
    [string]$SourceIds,

    [Parameter(Mandatory = $true)]
    [ValidateSet('ctrip', 'meituan', 'ctrip,meituan', 'meituan,ctrip')]
    [string]$Platforms,

    [ValidatePattern('^(?:[01]\d|2[0-3]):[0-5]\d$')]
    [string]$DailyAt = '08:30',

    [switch]$ReplaceExisting,

    [switch]$StartNow,

    [string]$ProjectRoot = '',

    [ValidateNotNullOrEmpty()]
    [string]$PhpPath = 'C:\xampp\php\php.exe',

    [ValidateNotNullOrEmpty()]
    [string]$RunAsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$receiptPrefix = 'SUXIOS_HOTEL_AUTOPILOT_DISPATCHER='
$taskName = "SUXIOS OTA Dispatcher H$HotelId"
$taskPath = '\'
$receipt = [ordered]@{
    schema_version = 'suxios_hotel_autopilot_dispatcher.v1'
    status = 'blocked'
    reason_code = 'provision_not_started'
    hotel_id = $HotelId
    task_name = $taskName
    task_exists = $false
    enabled = $false
    task_started = $false
    scope = [ordered]@{
        hotel_id = $HotelId
        source_ids = @($SourceIds.Split(',') | ForEach-Object { [int]$_.Trim() })
        platforms = @($Platforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() })
        mode = 'daily'
    }
    scope_verified = $false
    action_verified = $false
    trigger_verified = $false
    principal_verified = $false
    enabled_verified = $false
    readback_verified = $false
    sensitive_values_exposed = $false
}

function Write-AutopilotDispatcherReceipt {
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Value)

    Write-Output ($receiptPrefix + ($Value | ConvertTo-Json -Depth 8 -Compress))
}

function Test-OrdinalPathEquals {
    param(
        [Parameter(Mandatory = $true)][string]$Left,
        [Parameter(Mandatory = $true)][string]$Right
    )

    return [string]::Equals(
        $Left.TrimEnd('\', '/'),
        $Right.TrimEnd('\', '/'),
        [System.StringComparison]::OrdinalIgnoreCase
    )
}

function Test-LocalPrincipalEquals {
    param(
        [Parameter(Mandatory = $true)][string]$Actual,
        [Parameter(Mandatory = $true)][string]$Expected
    )

    if ([string]::Equals($Actual, $Expected, [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }
    $parts = @($Expected.Split('\'))
    return $parts.Count -eq 2 `
        -and [string]::Equals($parts[0], [string]$env:COMPUTERNAME, [System.StringComparison]::OrdinalIgnoreCase) `
        -and [string]::Equals($Actual, $parts[1], [System.StringComparison]::OrdinalIgnoreCase)
}

function Test-Iso8601DurationEquals {
    param(
        [Parameter(Mandatory = $true)][string]$Actual,
        [Parameter(Mandatory = $true)][string]$Expected
    )

    try {
        $actualDuration = [System.Xml.XmlConvert]::ToTimeSpan($Actual)
        $expectedDuration = [System.Xml.XmlConvert]::ToTimeSpan($Expected)
        return $actualDuration -eq $expectedDuration
    } catch {
        return $false
    }
}

function Get-ExactTaskReadback {
    param(
        [Parameter(Mandatory = $true)][string]$ExpectedPowerShell,
        [Parameter(Mandatory = $true)][string]$ExpectedArguments,
        [Parameter(Mandatory = $true)][string]$ExpectedWorkingDirectory,
        [Parameter(Mandatory = $true)][string]$ExpectedUser
    )

    $task = Get-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction SilentlyContinue
    if ($null -eq $task) {
        return [pscustomobject]@{
            task = $null
            scope_verified = $false
            action_verified = $false
            trigger_verified = $false
            principal_verified = $false
            enabled_verified = $false
            automatic_repair_allowed = $false
            readback_verified = $false
        }
    }

    $actions = @($task.Actions)
    $actionVerified = $actions.Count -eq 1 `
        -and (Test-OrdinalPathEquals -Left ([string]$actions[0].Execute) -Right $ExpectedPowerShell) `
        -and [string]::Equals([string]$actions[0].Arguments, $ExpectedArguments, [System.StringComparison]::Ordinal) `
        -and (Test-OrdinalPathEquals -Left ([string]$actions[0].WorkingDirectory) -Right $ExpectedWorkingDirectory)

    # Scope is verified independently from the exact action path so an
    # explicitly requested repair can update binaries or paths, but it can
    # never replace another hotel/source/platform scope.
    $scopeVerified = $actions.Count -eq 1 `
        -and [string]$actions[0].Arguments -match ('(?i)(?:^|\s)-HotelId\s+' + [regex]::Escape([string]$HotelId) + '(?:\s|$)') `
        -and [string]$actions[0].Arguments -match ('(?i)(?:^|\s)-SourceIds\s+"' + [regex]::Escape($SourceIds) + '"(?:\s|$)') `
        -and [string]$actions[0].Arguments -match ('(?i)(?:^|\s)-Platforms\s+"' + [regex]::Escape($Platforms) + '"(?:\s|$)') `
        -and [string]$actions[0].Arguments -match '(?i)(?:^|\s)-Mode\s+Daily(?:\s|$)'

    $triggers = @($task.Triggers)
    $triggerVerified = $false
    if ($triggers.Count -eq 1) {
        [datetimeoffset]$startBoundary = [datetimeoffset]::MinValue
        $parsed = [datetimeoffset]::TryParse([string]$triggers[0].StartBoundary, [ref]$startBoundary)
        $localTime = if ($parsed) { $startBoundary.LocalDateTime.ToString('HH:mm') } else { '' }
        $repetitionInterval = [string]$triggers[0].Repetition.Interval
        $repetitionDuration = [string]$triggers[0].Repetition.Duration
        $triggerVerified = $parsed `
            -and $localTime -eq $DailyAt `
            -and (Test-Iso8601DurationEquals -Actual $repetitionInterval -Expected 'PT14M') `
            -and (Test-Iso8601DurationEquals -Actual $repetitionDuration -Expected 'PT85M')
    }

    $principal = $task.Principal
    $principalVerified = $null -ne $principal `
        -and (Test-LocalPrincipalEquals -Actual ([string]$principal.UserId) -Expected $ExpectedUser) `
        -and [string]$principal.LogonType -eq 'Interactive' `
        -and [string]$principal.RunLevel -eq 'Limited'

    $settings = $task.Settings
    $enabledVerified = $null -ne $settings -and [bool]$settings.Enabled
    $automaticRepairAllowed = $actionVerified `
        -and $scopeVerified `
        -and $triggerVerified `
        -and $principalVerified `
        -and -not $enabledVerified

    return [pscustomobject]@{
        task = $task
        scope_verified = [bool]$scopeVerified
        action_verified = [bool]$actionVerified
        trigger_verified = [bool]$triggerVerified
        principal_verified = [bool]$principalVerified
        enabled_verified = [bool]$enabledVerified
        automatic_repair_allowed = [bool]$automaticRepairAllowed
        readback_verified = [bool]($scopeVerified `
            -and $actionVerified `
            -and $triggerVerified `
            -and $principalVerified `
            -and $enabledVerified)
    }
}

$exitCode = 1
try {
    if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
        $ProjectRoot = Split-Path -Parent $PSScriptRoot
    }

    $resolvedProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    $resolvedPhpPath = (Resolve-Path -LiteralPath $PhpPath -ErrorAction Stop).Path
    $registrationScript = Join-Path $resolvedProjectRoot 'scripts\register_ota_dispatcher_task.ps1'
    $dispatcherRunner = Join-Path $resolvedProjectRoot 'scripts\run_ota_dispatcher.ps1'
    $composerPath = Join-Path $resolvedProjectRoot 'composer.json'
    if (-not (Test-Path -LiteralPath $registrationScript -PathType Leaf) `
        -or -not (Test-Path -LiteralPath $dispatcherRunner -PathType Leaf) `
        -or -not (Test-Path -LiteralPath $composerPath -PathType Leaf)
    ) {
        throw 'project_identity_invalid'
    }

    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    if (-not [Environment]::UserInteractive `
        -or [System.Diagnostics.Process]::GetCurrentProcess().SessionId -le 0 `
        -or -not [string]::Equals($RunAsUser, $currentUser, [System.StringComparison]::OrdinalIgnoreCase)
    ) {
        throw 'interactive_user_required'
    }

    $sourceParts = @($SourceIds.Split(',') | ForEach-Object { $_.Trim() })
    $platformParts = @($Platforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() })
    if ($sourceParts.Count -ne $platformParts.Count `
        -or @($sourceParts | Sort-Object -Unique).Count -ne $sourceParts.Count `
        -or @($platformParts | Sort-Object -Unique).Count -ne $platformParts.Count
    ) {
        throw 'collection_scope_invalid'
    }

    $powershellPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    if (-not (Test-Path -LiteralPath $powershellPath -PathType Leaf)) {
        throw 'powershell_binary_missing'
    }

    $expectedArguments = '-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}" -ProjectRoot "{1}" -PhpPath "{2}" -Mode Daily -HotelId {3} -SourceIds "{4}" -Platforms "{5}"' -f `
        $dispatcherRunner, $resolvedProjectRoot, $resolvedPhpPath, $HotelId, $SourceIds, $Platforms

    $initialReadback = Get-ExactTaskReadback `
        -ExpectedPowerShell $powershellPath `
        -ExpectedArguments $expectedArguments `
        -ExpectedWorkingDirectory $resolvedProjectRoot `
        -ExpectedUser $RunAsUser
    $receipt.task_exists = $null -ne $initialReadback.task

    if ($null -eq $initialReadback.task -or -not $initialReadback.readback_verified) {
        $automaticRepairAllowed = $null -ne $initialReadback.task `
            -and $initialReadback.automatic_repair_allowed
        if ($null -ne $initialReadback.task) {
            if (-not $initialReadback.scope_verified) {
                throw 'existing_task_scope_mismatch'
            }
            if (-not $automaticRepairAllowed -and -not $ReplaceExisting) {
                throw 'existing_task_mismatch_requires_replace'
            }
        }

        $registrationParameters = @{
            Enable = $true
            HotelId = $HotelId
            SourceIds = $SourceIds
            Platforms = $Platforms
            DailyAt = $DailyAt
            ProjectRoot = $resolvedProjectRoot
            PhpPath = $resolvedPhpPath
            RunAsUser = $RunAsUser
        }
        if ($null -ne $initialReadback.task -and ($automaticRepairAllowed -or $ReplaceExisting)) {
            $registrationParameters['ReplaceExisting'] = $true
        }

        # Capture and suppress the lower-level plan so this wrapper exposes one
        # stable machine receipt only. The lower-level script remains the sole
        # authority for preflight and scope-reduction refusal.
        $registrationOutput = @(& $registrationScript @registrationParameters 2>&1)
        if ($registrationOutput.Count -eq 0) {
            throw 'registration_receipt_missing'
        }
    }

    $readback = Get-ExactTaskReadback `
        -ExpectedPowerShell $powershellPath `
        -ExpectedArguments $expectedArguments `
        -ExpectedWorkingDirectory $resolvedProjectRoot `
        -ExpectedUser $RunAsUser
    $receipt.task_exists = $null -ne $readback.task
    $receipt.scope_verified = $readback.scope_verified
    $receipt.action_verified = $readback.action_verified
    $receipt.trigger_verified = $readback.trigger_verified
    $receipt.principal_verified = $readback.principal_verified
    $receipt.enabled_verified = $readback.enabled_verified
    $receipt.readback_verified = $readback.readback_verified
    $receipt.enabled = $readback.enabled_verified
    if (-not $readback.readback_verified) {
        throw 'scheduled_task_readback_mismatch'
    }

    if ($StartNow) {
        Start-ScheduledTask -TaskName $taskName -TaskPath $taskPath -ErrorAction Stop
        $receipt.task_started = $true
    }

    $receipt.status = 'ready'
    $receipt.reason_code = if ($StartNow) { 'task_enabled_and_started' } else { 'task_enabled' }
    $exitCode = 0
} catch {
    $safeReason = [string]$_.Exception.Message
    if ($safeReason -notmatch '^[a-z0-9_]+$') {
        $safeReason = 'dispatcher_provision_failed'
    }
    $receipt.status = 'blocked'
    $receipt.reason_code = $safeReason
    $receipt.enabled = $receipt.enabled_verified
}

Write-AutopilotDispatcherReceipt -Value $receipt
exit $exitCode
