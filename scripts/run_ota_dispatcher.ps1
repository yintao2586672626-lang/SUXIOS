[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$ProjectRoot,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$PhpPath,

    [ValidateSet('Daily', 'Realtime')]
    [string]$Mode = 'Daily',

    [ValidateRange(0, 2147483647)]
    [int]$HotelId = 0,

    [ValidatePattern('^(?:|[1-9][0-9]*(?:,[1-9][0-9]*)*)$')]
    [string]$SourceIds = '',

    [ValidateSet('', 'ctrip', 'meituan', 'ctrip,meituan', 'meituan,ctrip')]
    [string]$Platforms = '',

    # Zero selects the bounded production default for the selected mode. A
    # small explicit value exists for isolated operational verification; the
    # registered task never supplies it.
    [ValidateRange(0, 2100)]
    [int]$CollectionTimeoutSeconds = 0,

    [switch]$PreflightOnly
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
$explicitScopeRequested = $HotelId -gt 0 -or $SourceIds -ne '' -or $Platforms -ne ''
$explicitScopeComplete = $HotelId -gt 0 -and $SourceIds -ne '' -and $Platforms -ne ''
if ($explicitScopeRequested -and -not $explicitScopeComplete) {
    throw 'Scoped OTA dispatcher requires HotelId, SourceIds, and Platforms together.'
}
$maximumCollectionTimeoutSeconds = if ($Mode -eq 'Realtime') { 1200 } else { 2100 }
if ($CollectionTimeoutSeconds -gt $maximumCollectionTimeoutSeconds) {
    throw "CollectionTimeoutSeconds exceeds the bounded $Mode task window."
}

$logDirectory = Join-Path $resolvedRoot 'runtime\dispatcher'
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
$executionGuid = [guid]::NewGuid()
$runId = (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + $executionGuid.ToString('N').ToLowerInvariant()
$logPath = Join-Path $logDirectory "ota_dispatcher_$runId.log"

$shanghaiTimeZone = [System.TimeZoneInfo]::FindSystemTimeZoneById('China Standard Time')
$shanghaiNow = [System.TimeZoneInfo]::ConvertTime([System.DateTimeOffset]::UtcNow, $shanghaiTimeZone)
$dailyTargetDate = ''
if ($Mode -eq 'Daily') {
    # Windows Task Scheduler runs in the host timezone, but the OTA business
    # date contract is explicitly Asia/Shanghai. Pin one exact previous-day
    # date so stale historical retry records cannot expand a daily run into
    # older business dates.
    $dailyTargetDate = $shanghaiNow.Date.AddDays(-1).ToString(
        'yyyy-MM-dd',
        [System.Globalization.CultureInfo]::InvariantCulture
    )
}
$provenanceTargetDate = if ($Mode -eq 'Daily') {
    $dailyTargetDate
} else {
    $shanghaiNow.Date.ToString('yyyy-MM-dd', [System.Globalization.CultureInfo]::InvariantCulture)
}

$collectionActiveStatuses = @('in_progress', 'started', 'collected')
$collectionTerminalStatuses = @(
    'succeeded',
    'partial',
    'failed',
    'blocked',
    'skipped',
    'deferred'
)

function Get-DispatcherTextSha256 {
    param([Parameter(Mandatory = $true)][string]$Value)

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = $utf8.GetBytes($Value)
        return ([System.BitConverter]::ToString($sha256.ComputeHash($bytes)) -replace '-', '').ToLowerInvariant()
    } finally {
        $sha256.Dispose()
    }
}

function Get-DispatcherCollectionScope {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('Daily', 'Realtime')][string]$DispatcherMode,
        [Parameter(Mandatory = $true)][ValidateRange(1, 2147483647)][int]$SystemHotelId,
        [Parameter(Mandatory = $true)][string]$BusinessDate,
        [Parameter(Mandatory = $true)][string]$ScopedSourceIds,
        [Parameter(Mandatory = $true)][string]$ScopedPlatforms
    )

    $inputSourceIds = [int[]]@(
        $ScopedSourceIds.Split(',') | ForEach-Object { [int]$_.Trim() }
    )
    $inputPlatforms = [string[]]@(
        $ScopedPlatforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() }
    )
    $normalizedSourceIds = [int[]]@($inputSourceIds | Sort-Object -Unique)
    $normalizedPlatforms = [string[]]@($inputPlatforms | Sort-Object -Unique)
    if ($inputSourceIds.Count -eq 0 `
        -or $inputSourceIds.Count -ne $inputPlatforms.Count `
        -or $normalizedSourceIds.Count -ne $inputSourceIds.Count `
        -or $normalizedPlatforms.Count -ne $inputPlatforms.Count
    ) {
        throw 'dispatcher_collection_scope_invalid'
    }

    $platformSourceBindings = [string[]]@(
        for ($bindingIndex = 0; $bindingIndex -lt $inputPlatforms.Count; $bindingIndex++) {
            '{0}:{1}' -f $inputPlatforms[$bindingIndex], $inputSourceIds[$bindingIndex]
        }
    ) | Sort-Object
    $platformSourceBindingsText = [string]::Join(',', $platformSourceBindings)

    # A plan fingerprint is optional because the current scheduled invocation
    # has no pre-dispatch plan-read command. If an operator-owned launcher can
    # provide the signed plan hash, it participates in the exact scope key.
    $planFingerprint = [string][Environment]::GetEnvironmentVariable(
        'SUXIOS_OTA_COLLECTION_PLAN_FINGERPRINT',
        'Process'
    )
    $planFingerprint = $planFingerprint.Trim().ToLowerInvariant()
    if ($planFingerprint -ne '' -and $planFingerprint -notmatch '^[a-f0-9]{64}$') {
        throw 'dispatcher_collection_plan_fingerprint_invalid'
    }

    $sourceIdsText = [string]::Join(',', [string[]]@($normalizedSourceIds | ForEach-Object { [string]$_ }))
    $platformsText = [string]::Join(',', $normalizedPlatforms)
    $scopeMaterial = [string]::Join("`n", [string[]]@(
        'schema_version=1',
        "mode=$($DispatcherMode.ToLowerInvariant())",
        "hotel_id=$SystemHotelId",
        "business_date=$BusinessDate",
        "source_ids=$sourceIdsText",
        "platforms=$platformsText",
        "platform_source_bindings=$platformSourceBindingsText",
        "plan_fingerprint=$planFingerprint"
    ))
    $scopeKey = Get-DispatcherTextSha256 -Value $scopeMaterial
    return [pscustomobject]@{
        schema_version = 1
        mode = $DispatcherMode.ToLowerInvariant()
        hotel_id = $SystemHotelId
        business_date = $BusinessDate
        source_ids = $normalizedSourceIds
        source_ids_text = $sourceIdsText
        platforms = $normalizedPlatforms
        platforms_text = $platformsText
        platform_source_bindings = $platformSourceBindings
        platform_source_bindings_text = $platformSourceBindingsText
        plan_fingerprint = $planFingerprint
        scope_key = $scopeKey
        state_path = Join-Path $logDirectory "ota_collection_run_$scopeKey.json"
    }
}

function Get-DispatcherCollectionStateIntegrity {
    param(
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][string]$CollectionRunId,
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][string]$StatusSource
    )

    $material = [string]::Join("`n", [string[]]@(
        'schema_version=1',
        "scope_key=$([string]$Scope.scope_key)",
        "mode=$([string]$Scope.mode)",
        "hotel_id=$([int]$Scope.hotel_id)",
        "business_date=$([string]$Scope.business_date)",
        "source_ids=$([string]$Scope.source_ids_text)",
        "platforms=$([string]$Scope.platforms_text)",
        "plan_fingerprint=$([string]$Scope.plan_fingerprint)",
        "collection_run_id=$CollectionRunId",
        "status=$Status",
        "status_source=$StatusSource"
    ))
    return Get-DispatcherTextSha256 -Value $material
}

function Read-TrustedDispatcherCollectionState {
    param([Parameter(Mandatory = $true)][object]$Scope)

    if (-not (Test-Path -LiteralPath $Scope.state_path -PathType Leaf)) {
        return [pscustomobject]@{ valid = $false; reason = 'state_missing'; state = $null }
    }
    try {
        $raw = [System.IO.File]::ReadAllText([string]$Scope.state_path, $utf8)
        $state = $raw | ConvertFrom-Json -ErrorAction Stop
        $requiredProperties = @(
            'schema_version',
            'scope_key',
            'mode',
            'hotel_id',
            'business_date',
            'source_ids',
            'platforms',
            'plan_fingerprint',
            'collection_run_id',
            'status',
            'status_source',
            'integrity_sha256'
        )
        foreach ($propertyName in $requiredProperties) {
            if ($state.PSObject.Properties.Name -notcontains $propertyName) {
                throw "dispatcher_collection_state_property_missing_$propertyName"
            }
        }
        $stateSourceIds = [int[]]@(
            @($state.source_ids) | ForEach-Object { [int]$_ } | Sort-Object -Unique
        )
        $statePlatforms = [string[]]@(
            @($state.platforms) |
                ForEach-Object { ([string]$_).Trim().ToLowerInvariant() } |
                Sort-Object -Unique
        )
        $stateSourceIdsText = [string]::Join(
            ',',
            [string[]]@($stateSourceIds | ForEach-Object { [string]$_ })
        )
        $statePlatformsText = [string]::Join(',', $statePlatforms)
        $collectionRunGuid = [guid]::Empty
        $collectionRunId = ([string]$state.collection_run_id).Trim().ToLowerInvariant()
        if (-not [guid]::TryParse($collectionRunId, [ref]$collectionRunGuid) `
            -or $collectionRunGuid.ToString('D').ToLowerInvariant() -cne $collectionRunId
        ) {
            throw 'dispatcher_collection_state_run_id_invalid'
        }
        $status = ([string]$state.status).Trim().ToLowerInvariant()
        if ($status -notin @($collectionActiveStatuses + $collectionTerminalStatuses)) {
            throw 'dispatcher_collection_state_status_invalid'
        }
        $statusSource = ([string]$state.status_source).Trim().ToLowerInvariant()
        if ($statusSource -notmatch '^[a-z0-9_]{1,80}$') {
            throw 'dispatcher_collection_state_status_source_invalid'
        }
        if ([int]$state.schema_version -ne 1 `
            -or ([string]$state.scope_key).ToLowerInvariant() -cne [string]$Scope.scope_key `
            -or ([string]$state.mode).ToLowerInvariant() -cne [string]$Scope.mode `
            -or [int]$state.hotel_id -ne [int]$Scope.hotel_id `
            -or ([string]$state.business_date) -cne [string]$Scope.business_date `
            -or $stateSourceIdsText -cne [string]$Scope.source_ids_text `
            -or $statePlatformsText -cne [string]$Scope.platforms_text `
            -or ([string]$state.plan_fingerprint).ToLowerInvariant() -cne [string]$Scope.plan_fingerprint
        ) {
            throw 'dispatcher_collection_state_scope_mismatch'
        }
        $expectedIntegrity = Get-DispatcherCollectionStateIntegrity `
            -Scope $Scope `
            -CollectionRunId $collectionRunId `
            -Status $status `
            -StatusSource $statusSource
        $storedIntegrity = ([string]$state.integrity_sha256).Trim().ToLowerInvariant()
        if ($storedIntegrity -notmatch '^[a-f0-9]{64}$' `
            -or $storedIntegrity -cne $expectedIntegrity
        ) {
            throw 'dispatcher_collection_state_integrity_mismatch'
        }
        return [pscustomobject]@{
            valid = $true
            reason = 'state_verified'
            state = $state
        }
    } catch {
        return [pscustomobject]@{
            valid = $false
            reason = 'state_invalid_' + ($_.Exception.Message -replace '[^A-Za-z0-9_]', '')
            state = $null
        }
    }
}

function Write-TrustedDispatcherCollectionState {
    param(
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CollectionRunGuid,
        [Parameter(Mandatory = $true)][string]$Status,
        [Parameter(Mandatory = $true)][string]$StatusSource,
        [Parameter(Mandatory = $true)][guid]$CurrentExecutionGuid
    )

    $normalizedStatus = $Status.Trim().ToLowerInvariant()
    if ($normalizedStatus -notin @($collectionActiveStatuses + $collectionTerminalStatuses)) {
        throw 'dispatcher_collection_state_write_status_invalid'
    }
    $normalizedStatusSource = $StatusSource.Trim().ToLowerInvariant()
    if ($normalizedStatusSource -notmatch '^[a-z0-9_]{1,80}$') {
        throw 'dispatcher_collection_state_write_source_invalid'
    }
    $collectionRunId = $CollectionRunGuid.ToString('D').ToLowerInvariant()
    $integrity = Get-DispatcherCollectionStateIntegrity `
        -Scope $Scope `
        -CollectionRunId $collectionRunId `
        -Status $normalizedStatus `
        -StatusSource $normalizedStatusSource
    $payload = [ordered]@{
        schema_version = 1
        scope_key = [string]$Scope.scope_key
        mode = [string]$Scope.mode
        hotel_id = [int]$Scope.hotel_id
        business_date = [string]$Scope.business_date
        source_ids = [int[]]$Scope.source_ids
        platforms = [string[]]$Scope.platforms
        plan_fingerprint = [string]$Scope.plan_fingerprint
        collection_run_id = $collectionRunId
        status = $normalizedStatus
        status_source = $normalizedStatusSource
        updated_at = [datetimeoffset]::Now.ToString('o')
        integrity_sha256 = $integrity
    }
    $json = $payload | ConvertTo-Json -Compress -Depth 6
    $temporaryPath = [string]$Scope.state_path + '.' + $CurrentExecutionGuid.ToString('N') + '.tmp'
    try {
        [System.IO.File]::WriteAllText($temporaryPath, $json, $utf8)
        if (Test-Path -LiteralPath $Scope.state_path -PathType Leaf) {
            Move-Item `
                -LiteralPath $temporaryPath `
                -Destination ([string]$Scope.state_path) `
                -Force
        } else {
            [System.IO.File]::Move($temporaryPath, [string]$Scope.state_path)
        }
        $readback = Read-TrustedDispatcherCollectionState -Scope $Scope
        if (-not [bool]$readback.valid `
            -or ([string]$readback.state.collection_run_id) -cne $collectionRunId `
            -or ([string]$readback.state.status) -cne $normalizedStatus
        ) {
            throw 'dispatcher_collection_state_readback_failed'
        }
        return $readback.state
    } finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
        }
    }
}

function Get-DispatcherPlatformSourceBindingsText {
    param(
        [object[]]$Rows = @(),
        [Parameter(Mandatory = $true)][string]$PlatformProperty,
        [Parameter(Mandatory = $true)][string]$SourceIdProperty
    )

    $bindings = @()
    $platforms = @()
    $sourceIds = @()
    foreach ($row in @($Rows)) {
        if ($null -eq $row `
            -or $row.PSObject.Properties.Name -notcontains $PlatformProperty `
            -or $row.PSObject.Properties.Name -notcontains $SourceIdProperty
        ) {
            return ''
        }
        $platform = ([string]$row.PSObject.Properties[$PlatformProperty].Value).Trim().ToLowerInvariant()
        $sourceId = [int]$row.PSObject.Properties[$SourceIdProperty].Value
        if ($platform -notmatch '^[a-z0-9_]{1,40}$' -or $sourceId -le 0) {
            return ''
        }
        $platforms += $platform
        $sourceIds += $sourceId
        $bindings += ('{0}:{1}' -f $platform, $sourceId)
    }
    if (@($bindings).Count -eq 0 `
        -or @($bindings | Sort-Object -Unique).Count -ne @($bindings).Count `
        -or @($platforms | Sort-Object -Unique).Count -ne @($platforms).Count `
        -or @($sourceIds | Sort-Object -Unique).Count -ne @($sourceIds).Count
    ) {
        return ''
    }
    return [string]::Join(',', [string[]]@($bindings | Sort-Object))
}

function Get-DispatcherSourceExecutionBindingsText {
    param(
        [object[]]$Rows = @(),
        [Parameter(Mandatory = $true)][string]$SyncTaskIdProperty
    )

    $bindings = @()
    foreach ($row in @($Rows)) {
        foreach ($requiredProperty in @(
            'platform',
            'data_source_id',
            'ingestion_method',
            $SyncTaskIdProperty
        )) {
            if ($null -eq $row -or $row.PSObject.Properties.Name -notcontains $requiredProperty) {
                return ''
            }
        }
        $platform = ([string]$row.platform).Trim().ToLowerInvariant()
        $sourceId = [int]$row.data_source_id
        $ingestionMethod = ([string]$row.ingestion_method).Trim().ToLowerInvariant()
        $syncTaskId = [int]$row.PSObject.Properties[$SyncTaskIdProperty].Value
        $localCollectorTaskId = if ($row.PSObject.Properties.Name -contains 'local_collector_task_id') {
            [int]$row.local_collector_task_id
        } else {
            0
        }
        if ($platform -notmatch '^[a-z0-9_]{1,40}$' `
            -or $sourceId -le 0 `
            -or $syncTaskId -le 0 `
            -or ($ingestionMethod -eq 'local_collector' -and $localCollectorTaskId -le 0) `
            -or ($ingestionMethod -eq 'browser_profile' -and $localCollectorTaskId -ne 0) `
            -or $ingestionMethod -notin @('browser_profile', 'local_collector')
        ) {
            return ''
        }
        $bindings += [string]::Join(':', [string[]]@(
            $platform,
            [string]$sourceId,
            $ingestionMethod,
            [string]$syncTaskId,
            [string]$localCollectorTaskId
        ))
    }
    if ($bindings.Count -eq 0 `
        -or @($bindings | Sort-Object -Unique).Count -ne $bindings.Count
    ) {
        return ''
    }
    return [string]::Join(',', [string[]]@($bindings | Sort-Object))
}

function Test-DispatcherCollectionPlanGate {
    param(
        [Parameter(Mandatory = $true)][object]$Gate,
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CollectionRunGuid
    )

    try {
        $expectedRunId = $CollectionRunGuid.ToString('D').ToLowerInvariant()
        if ([int]$Gate.schema_version -ne 1 `
            -or ([string]$Gate.status).Trim().ToLowerInvariant() -cne 'ready' `
            -or $Gate.collection_allowed -isnot [bool] `
            -or $Gate.collection_allowed -ne $true `
            -or $Gate.plan_readback_verified -isnot [bool] `
            -or $Gate.plan_readback_verified -ne $true `
            -or $Gate.binding_digest_matches -isnot [bool] `
            -or $Gate.binding_digest_matches -ne $true `
            -or $Gate.execution_owner_bound -isnot [bool] `
            -or $Gate.execution_owner_bound -ne $true `
            -or $Gate.automatic_device_substitution -isnot [bool] `
            -or $Gate.automatic_device_substitution -ne $false `
            -or $Gate.sensitive_values_exposed -isnot [bool] `
            -or $Gate.sensitive_values_exposed -ne $false `
            -or ([string]$Gate.dispatcher_run_id).Trim().ToLowerInvariant() -cne $expectedRunId `
            -or [int]$Gate.tenant_id -le 0 `
            -or [int]$Gate.system_hotel_id -ne [int]$Scope.hotel_id `
            -or ([string]$Gate.business_date).Trim() -cne [string]$Scope.business_date `
            -or ([string]$Gate.run_mode).Trim().ToLowerInvariant() -cne 'daily' `
            -or [int]$Gate.plan_id -le 0 `
            -or [int]$Gate.plan_version -le 0 `
            -or ([string]$Gate.plan_hash).Trim().ToLowerInvariant() -notmatch '^[a-f0-9]{64}$' `
            -or ([string]$Gate.scope_hash).Trim().ToLowerInvariant() -notmatch '^[a-f0-9]{64}$'
        ) {
            return $false
        }
        foreach ($requiredListProperty in @(
            'expected_source_ids',
            'actual_source_ids',
            'expected_platforms',
            'actual_platforms'
        )) {
            if ($Gate.PSObject.Properties.Name -notcontains $requiredListProperty) {
                return $false
            }
        }
        foreach ($sourceProperty in @('expected_source_ids', 'actual_source_ids')) {
            $ids = [int[]]@(
                @($Gate.PSObject.Properties[$sourceProperty].Value) |
                    ForEach-Object { [int]$_ } |
                    Sort-Object -Unique
            )
            $idsText = [string]::Join(',', [string[]]@($ids | ForEach-Object { [string]$_ }))
            if ($idsText -cne [string]$Scope.source_ids_text) {
                return $false
            }
        }
        foreach ($platformProperty in @('expected_platforms', 'actual_platforms')) {
            $platforms = [string[]]@(
                @($Gate.PSObject.Properties[$platformProperty].Value) |
                    ForEach-Object { ([string]$_).Trim().ToLowerInvariant() } |
                    Sort-Object -Unique
            )
            if ([string]::Join(',', $platforms) -cne [string]$Scope.platforms_text) {
                return $false
            }
        }
        if ($null -eq $Gate.sources) {
            return $false
        }
        $sourceRows = @()
        foreach ($platform in @($Scope.platforms)) {
            if ($Gate.sources.PSObject.Properties.Name -notcontains [string]$platform) {
                return $false
            }
            $source = $Gate.sources.PSObject.Properties[[string]$platform].Value
            $sourceRows += [pscustomobject]@{
                platform = [string]$platform
                data_source_id = [int]$source.data_source_id
            }
        }
        $sourceBindingsText = Get-DispatcherPlatformSourceBindingsText `
            -Rows $sourceRows `
            -PlatformProperty 'platform' `
            -SourceIdProperty 'data_source_id'
        return $sourceBindingsText -ceq [string]$Scope.platform_source_bindings_text
    } catch {
        return $false
    }
}

function Test-DispatcherAutoFetchSuccessReceipt {
    param(
        [Parameter(Mandatory = $true)][object]$Receipt,
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][int]$ChildExitCode
    )

    if ($ChildExitCode -ne 0) {
        return $false
    }
    if ($Receipt.PSObject.Properties.Name -notcontains 'sensitive_values_exposed' `
        -or $Receipt.sensitive_values_exposed -isnot [bool] `
        -or $Receipt.sensitive_values_exposed -ne $false
    ) {
        return $false
    }
    foreach ($requiredFlag in @(
        $Receipt.collection_complete,
        $Receipt.authority_scope_complete,
        $Receipt.dual_ota_p0_complete,
        $Receipt.canonical_history_complete,
        $Receipt.collection_run_readback_verified
    )) {
        if ($requiredFlag -isnot [bool] -or $requiredFlag -ne $true) {
            return $false
        }
    }

    $anchorHash = ([string]$Receipt.collection_anchor_hash).Trim().ToLowerInvariant()
    $trustReceiptDigest = ([string]$Receipt.trust_receipt_digest).Trim().ToLowerInvariant()
    if ($anchorHash -notmatch '^[a-f0-9]{64}$' `
        -or $trustReceiptDigest -notmatch '^[a-f0-9]{64}$'
    ) {
        return $false
    }

    $sourceTasks = @($Receipt.source_tasks)
    if ($sourceTasks.Count -ne 2 `
        -or @($Scope.source_ids).Count -ne 2 `
        -or @($Scope.platforms).Count -ne 2
    ) {
        return $false
    }

    $taskSourceIds = @()
    $taskPlatforms = @()
    $taskSyncIds = @()
    $localCollectorTaskIds = @()
    $localCollectorTaskCount = 0
    foreach ($sourceTask in $sourceTasks) {
        if ($null -eq $sourceTask `
            -or [int]$sourceTask.data_source_id -le 0 `
            -or [int]$sourceTask.sync_task_id -le 0 `
            -or $sourceTask.readback_verified -isnot [bool] `
            -or $sourceTask.readback_verified -ne $true
        ) {
            return $false
        }
        $taskPlatform = ([string]$sourceTask.platform).Trim().ToLowerInvariant()
        if ($taskPlatform -eq '') {
            return $false
        }
        $ingestionMethod = ([string]$sourceTask.ingestion_method).Trim().ToLowerInvariant()
        $triggerType = ([string]$sourceTask.trigger_type).Trim().ToLowerInvariant()
        $localCollectorTaskId = 0
        if ($sourceTask.PSObject.Properties.Name -contains 'local_collector_task_id') {
            $localCollectorTaskId = [int]$sourceTask.local_collector_task_id
        }
        if ($ingestionMethod -eq 'local_collector') {
            if ($localCollectorTaskId -le 0 -or $triggerType -cne 'local_collector_upload') {
                return $false
            }
            $localCollectorTaskIds += $localCollectorTaskId
            $localCollectorTaskCount++
        } elseif ($ingestionMethod -eq 'browser_profile') {
            if ($localCollectorTaskId -ne 0 -or $triggerType -cne 'daily_profile_reuse') {
                return $false
            }
        } else {
            return $false
        }
        $taskSourceIds += [int]$sourceTask.data_source_id
        $taskPlatforms += $taskPlatform
        $taskSyncIds += [int]$sourceTask.sync_task_id
    }

    $taskSourceIds = [int[]]@($taskSourceIds | Sort-Object -Unique)
    $taskPlatforms = [string[]]@($taskPlatforms | Sort-Object -Unique)
    $taskSyncIds = [int[]]@($taskSyncIds | Sort-Object -Unique)
    $localCollectorTaskIds = [int[]]@($localCollectorTaskIds | Sort-Object -Unique)
    $taskSourceIdsText = [string]::Join(
        ',',
        [string[]]@($taskSourceIds | ForEach-Object { [string]$_ })
    )
    $taskPlatformsText = [string]::Join(',', $taskPlatforms)
    $taskBindingsText = Get-DispatcherPlatformSourceBindingsText `
        -Rows $sourceTasks `
        -PlatformProperty 'platform' `
        -SourceIdProperty 'data_source_id'
    return $taskSourceIds.Count -eq 2 `
        -and $taskPlatforms.Count -eq 2 `
        -and $taskSyncIds.Count -eq 2 `
        -and $localCollectorTaskIds.Count -eq $localCollectorTaskCount `
        -and $taskSourceIdsText -ceq [string]$Scope.source_ids_text `
        -and $taskPlatformsText -ceq [string]$Scope.platforms_text `
        -and $taskBindingsText -ceq [string]$Scope.platform_source_bindings_text
}

function Test-DispatcherCollectionRunSuccessReceipt {
    param(
        [Parameter(Mandatory = $true)][object]$Receipt,
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CollectionRunGuid,
        [Parameter(Mandatory = $true)][int]$ChildExitCode
    )

    if ($ChildExitCode -ne 0) {
        return $false
    }
    try {
        $expectedRunId = $CollectionRunGuid.ToString('D').ToLowerInvariant()
        if ($Receipt.PSObject.Properties.Name -notcontains 'sensitive_values_exposed' `
            -or $Receipt.sensitive_values_exposed -isnot [bool] `
            -or $Receipt.sensitive_values_exposed -ne $false `
            -or ([string]$Receipt.dispatcher_run_id).Trim().ToLowerInvariant() -cne $expectedRunId `
            -or [int]$Receipt.system_hotel_id -ne [int]$Scope.hotel_id `
            -or ([string]$Receipt.business_date).Trim() -cne [string]$Scope.business_date `
            -or ([string]$Receipt.status).Trim().ToLowerInvariant() -cne 'succeeded'
        ) {
            return $false
        }
        foreach ($requiredFlag in @(
            $Receipt.ledger_structure_verified,
            $Receipt.readback_verified
        )) {
            if ($requiredFlag -isnot [bool] -or $requiredFlag -ne $true) {
                return $false
            }
        }

        $anchorHash = ([string]$Receipt.collection_anchor_hash).Trim().ToLowerInvariant()
        $trustReceiptDigest = ([string]$Receipt.trust_receipt_digest).Trim().ToLowerInvariant()
        if ($anchorHash -notmatch '^[a-f0-9]{64}$' `
            -or $trustReceiptDigest -notmatch '^[a-f0-9]{64}$' `
            -or ([string]$Receipt.finished_at).Trim() -eq ''
        ) {
            return $false
        }

        $sourceReceipts = @($Receipt.source_receipts)
        if ($sourceReceipts.Count -ne 2 `
            -or @($Scope.source_ids).Count -ne 2 `
            -or @($Scope.platforms).Count -ne 2
        ) {
            return $false
        }

        $sourceIds = @()
        $platforms = @()
        $syncTaskIds = @()
        $localCollectorTaskIds = @()
        $localCollectorTaskCount = 0
        foreach ($sourceReceipt in $sourceReceipts) {
            if ($null -eq $sourceReceipt `
                -or [int]$sourceReceipt.data_source_id -le 0 `
                -or [int]$sourceReceipt.platform_sync_task_id -le 0 `
                -or ([string]$sourceReceipt.status).Trim().ToLowerInvariant() -cne 'success' `
                -or $sourceReceipt.readback_verified -isnot [bool] `
                -or $sourceReceipt.readback_verified -ne $true `
                -or [int]$sourceReceipt.saved_row_count -le 0 `
                -or [int]$sourceReceipt.readback_row_count -le 0 `
                -or [int]$sourceReceipt.saved_row_count -ne [int]$sourceReceipt.readback_row_count `
                -or ([string]$sourceReceipt.finished_at).Trim() -eq ''
            ) {
                return $false
            }

            $platform = ([string]$sourceReceipt.platform).Trim().ToLowerInvariant()
            $ingestionMethod = ([string]$sourceReceipt.ingestion_method).Trim().ToLowerInvariant()
            if ($platform -eq '') {
                return $false
            }
            $localCollectorTaskId = 0
            if ($sourceReceipt.PSObject.Properties.Name -contains 'local_collector_task_id') {
                $localCollectorTaskId = [int]$sourceReceipt.local_collector_task_id
            }
            if ($ingestionMethod -eq 'local_collector') {
                if ($localCollectorTaskId -le 0) {
                    return $false
                }
                $localCollectorTaskIds += $localCollectorTaskId
                $localCollectorTaskCount++
            } elseif ($ingestionMethod -eq 'browser_profile') {
                if ($localCollectorTaskId -ne 0) {
                    return $false
                }
            } else {
                return $false
            }

            $sourceIds += [int]$sourceReceipt.data_source_id
            $platforms += $platform
            $syncTaskIds += [int]$sourceReceipt.platform_sync_task_id
        }

        $sourceIds = [int[]]@($sourceIds | Sort-Object -Unique)
        $platforms = [string[]]@($platforms | Sort-Object -Unique)
        $syncTaskIds = [int[]]@($syncTaskIds | Sort-Object -Unique)
        $localCollectorTaskIds = [int[]]@($localCollectorTaskIds | Sort-Object -Unique)
        $sourceIdsText = [string]::Join(
            ',',
            [string[]]@($sourceIds | ForEach-Object { [string]$_ })
        )
        $platformsText = [string]::Join(',', $platforms)
        $sourceBindingsText = Get-DispatcherPlatformSourceBindingsText `
            -Rows $sourceReceipts `
            -PlatformProperty 'platform' `
            -SourceIdProperty 'data_source_id'
        return $sourceIds.Count -eq 2 `
            -and $platforms.Count -eq 2 `
            -and $syncTaskIds.Count -eq 2 `
            -and $localCollectorTaskIds.Count -eq $localCollectorTaskCount `
            -and $sourceIdsText -ceq [string]$Scope.source_ids_text `
            -and $platformsText -ceq [string]$Scope.platforms_text `
            -and $sourceBindingsText -ceq [string]$Scope.platform_source_bindings_text
    } catch {
        return $false
    }
}

function Test-DispatcherCollectionRunTerminalReceipt {
    param(
        [Parameter(Mandatory = $true)][object]$Receipt,
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CollectionRunGuid
    )

    $invalid = [pscustomobject]@{
        valid = $false
        status = 'started'
        source = 'runner_child_start'
    }
    try {
        $status = ([string]$Receipt.status).Trim().ToLowerInvariant()
        if ($status -notin @('partial', 'failed', 'blocked', 'skipped', 'deferred') `
            -or ([string]$Receipt.dispatcher_run_id).Trim().ToLowerInvariant() `
                -cne $CollectionRunGuid.ToString('D').ToLowerInvariant() `
            -or [int]$Receipt.system_hotel_id -ne [int]$Scope.hotel_id `
            -or ([string]$Receipt.business_date).Trim() -cne [string]$Scope.business_date `
            -or $Receipt.ledger_structure_verified -isnot [bool] `
            -or $Receipt.ledger_structure_verified -ne $true `
            -or $Receipt.readback_verified -isnot [bool] `
            -or $Receipt.readback_verified -ne $true `
            -or ([string]$Receipt.finished_at).Trim() -eq '' `
            -or ([string]$Receipt.scope_hash).Trim().ToLowerInvariant() -notmatch '^[a-f0-9]{64}$'
        ) {
            return $invalid
        }

        $anchorHash = if ($Receipt.PSObject.Properties.Name -contains 'collection_anchor_hash') {
            ([string]$Receipt.collection_anchor_hash).Trim()
        } else { '' }
        $trustDigest = if ($Receipt.PSObject.Properties.Name -contains 'trust_receipt_digest') {
            ([string]$Receipt.trust_receipt_digest).Trim()
        } else { '' }
        $failureStage = ([string]$Receipt.failure_stage).Trim().ToLowerInvariant()
        $failureCode = ([string]$Receipt.failure_code).Trim().ToLowerInvariant()
        if ($anchorHash -ne '' -or $trustDigest -ne '' `
            -or $failureStage -notmatch '^[a-z0-9_]{1,80}$' `
            -or $failureCode -notmatch '^[a-z0-9_]{1,120}$'
        ) {
            return $invalid
        }

        $sourceReceipts = @($Receipt.source_receipts)
        if ($sourceReceipts.Count -ne 2 `
            -or @($Scope.source_ids).Count -ne 2 `
            -or @($Scope.platforms).Count -ne 2
        ) {
            return $invalid
        }

        $sourceIds = @()
        $platforms = @()
        $syncTaskIds = @()
        $localTaskIds = @()
        $sourceStatuses = @()
        $sourceFailureStages = @()
        foreach ($sourceReceipt in $sourceReceipts) {
            if ($null -eq $sourceReceipt `
                -or $sourceReceipt.sensitive_values_exposed -isnot [bool] `
                -or $sourceReceipt.sensitive_values_exposed -ne $false `
                -or $sourceReceipt.automatic_device_substitution -isnot [bool] `
                -or $sourceReceipt.automatic_device_substitution -ne $false `
                -or [int]$sourceReceipt.data_source_id -le 0 `
                -or ([string]$sourceReceipt.finished_at).Trim() -eq ''
            ) {
                return $invalid
            }

            $platform = ([string]$sourceReceipt.platform).Trim().ToLowerInvariant()
            $sourceStatus = ([string]$sourceReceipt.status).Trim().ToLowerInvariant()
            $sourceFailureStage = ([string]$sourceReceipt.failure_stage).Trim().ToLowerInvariant()
            $sourceFailureCode = ([string]$sourceReceipt.failure_code).Trim().ToLowerInvariant()
            $ingestionMethod = ([string]$sourceReceipt.ingestion_method).Trim().ToLowerInvariant()
            $syncTaskId = [int]$sourceReceipt.platform_sync_task_id
            $localTaskId = [int]$sourceReceipt.local_collector_task_id
            $savedCount = [int]$sourceReceipt.saved_row_count
            $readbackCount = [int]$sourceReceipt.readback_row_count
            $readbackVerified = $sourceReceipt.readback_verified
            if ($platform -eq '' `
                -or $sourceStatus -notin @('success', 'partial', 'failed', 'blocked', 'skipped', 'deferred') `
                -or $readbackVerified -isnot [bool] `
                -or $savedCount -lt 0 `
                -or $readbackCount -lt 0
            ) {
                return $invalid
            }

            $noCollectionChild = $sourceStatus -in @('blocked', 'skipped', 'deferred') `
                -and $syncTaskId -eq 0 `
                -and $localTaskId -eq 0 `
                -and $savedCount -eq 0 `
                -and $readbackCount -eq 0 `
                -and $readbackVerified -eq $false
            if ($ingestionMethod -eq 'local_collector') {
                if ((-not $noCollectionChild) -and ($syncTaskId -le 0 -or $localTaskId -le 0)) {
                    return $invalid
                }
            } elseif ($ingestionMethod -eq 'browser_profile') {
                if ($localTaskId -ne 0 -or ((-not $noCollectionChild) -and $syncTaskId -le 0)) {
                    return $invalid
                }
            } else {
                return $invalid
            }

            if ($sourceStatus -eq 'success') {
                if ($sourceFailureStage -ne '' `
                    -or $sourceFailureCode -ne '' `
                    -or $readbackVerified -ne $true `
                    -or $savedCount -le 0 `
                    -or $readbackCount -le 0 `
                    -or $savedCount -ne $readbackCount
                ) {
                    return $invalid
                }
            } else {
                if ($sourceFailureStage -notmatch '^[a-z0-9_]{1,80}$' `
                    -or $sourceFailureCode -notmatch '^[a-z0-9_]{1,120}$'
                ) {
                    return $invalid
                }
                if ($readbackVerified -eq $true `
                    -and ($savedCount -le 0 `
                        -or $readbackCount -le 0 `
                        -or $savedCount -ne $readbackCount `
                        -or $syncTaskId -le 0)
                ) {
                    return $invalid
                }
                if ($readbackVerified -eq $false `
                    -and ($savedCount -gt 0 -or $readbackCount -gt 0) `
                    -and $syncTaskId -le 0
                ) {
                    return $invalid
                }
            }

            $sourceIds += [int]$sourceReceipt.data_source_id
            $platforms += $platform
            if ($syncTaskId -gt 0) { $syncTaskIds += $syncTaskId }
            if ($localTaskId -gt 0) { $localTaskIds += $localTaskId }
            $sourceStatuses += $sourceStatus
            $sourceFailureStages += $sourceFailureStage
        }

        $sourceIds = [int[]]@($sourceIds | Sort-Object -Unique)
        $platforms = [string[]]@($platforms | Sort-Object -Unique)
        $uniqueSyncTaskIds = [int[]]@($syncTaskIds | Sort-Object -Unique)
        $uniqueLocalTaskIds = [int[]]@($localTaskIds | Sort-Object -Unique)
        $sourceIdsText = [string]::Join(',', [string[]]@($sourceIds | ForEach-Object { [string]$_ }))
        $platformsText = [string]::Join(',', $platforms)
        $sourceBindingsText = Get-DispatcherPlatformSourceBindingsText `
            -Rows $sourceReceipts `
            -PlatformProperty 'platform' `
            -SourceIdProperty 'data_source_id'
        if ($sourceIds.Count -ne 2 `
            -or $platforms.Count -ne 2 `
            -or $uniqueSyncTaskIds.Count -ne @($syncTaskIds).Count `
            -or $uniqueLocalTaskIds.Count -ne @($localTaskIds).Count `
            -or $sourceIdsText -cne [string]$Scope.source_ids_text `
            -or $platformsText -cne [string]$Scope.platforms_text `
            -or $sourceBindingsText -cne [string]$Scope.platform_source_bindings_text
        ) {
            return $invalid
        }

        $allSourcesSucceeded = @($sourceStatuses | Where-Object { $_ -ne 'success' }).Count -eq 0
        if ($failureStage -eq 'trust_finalization' `
            -and $status -in @('partial', 'failed') `
            -and $allSourcesSucceeded
        ) {
            # Older producers could mark fully persisted dual-source data as a
            # terminal trust-finalization failure. The collection is durable,
            # but trust is recoverable only on this exact UUID.
            return [pscustomobject]@{
                valid = $true
                status = 'collected'
                source = 'collection_run_recoverable_trust_finalization'
            }
        }

        if ($status -in @('partial', 'failed')) {
            if (@($sourceStatuses | Where-Object { $_ -notin @('success', 'partial', 'failed') }).Count -gt 0) {
                return $invalid
            }
            $derivedStatus = if (@($sourceStatuses | Where-Object { $_ -in @('success', 'partial') }).Count -gt 0) {
                'partial'
            } else { 'failed' }
            if ($derivedStatus -cne $status) {
                return $invalid
            }
        } else {
            if (@($sourceStatuses | Where-Object { $_ -cne $status }).Count -gt 0 `
                -or @($sourceFailureStages | Where-Object { $_ -cne $failureStage }).Count -gt 0
            ) {
                return $invalid
            }
        }

        return [pscustomobject]@{
            valid = $true
            status = $status
            source = 'collection_run_authoritative'
        }
    } catch {
        return $invalid
    }
}

function Resolve-DispatcherCollectionOutputStatus {
    param(
        [string[]]$OutputLines = @(),
        [Parameter(Mandatory = $true)][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CollectionRunGuid,
        [Parameter(Mandatory = $true)][int]$ChildExitCode
    )

    $expectedRunId = $CollectionRunGuid.ToString('D').ToLowerInvariant()
    $outputInvalid = $false
    $autoFetchReceiptSeen = $false
    $autoFetchReceiptStatus = ''
    $autoFetchSuccessReceiptSeen = $false
    $autoFetchSuccessReceiptsValid = $true
    $autoFetchSuccessEvidenceKey = ''
    $collectionRunReceiptSeen = $false
    $collectionRunReceiptStatus = ''
    $collectionRunSuccessReceiptSeen = $false
    $collectionRunSuccessReceiptsValid = $true
    $collectionRunSuccessEvidenceKey = ''
    $collectionRunSuccessScopeHash = ''
    $collectionRunTerminalReceiptSeen = $false
    $collectionRunTerminalReceiptValid = $false
    $collectionRunTerminalTrustedStatus = ''
    $collectionRunTerminalTrustedSource = ''
    $planGateSeen = $false
    $planGateValid = $false
    $planGateScopeHash = ''
    foreach ($outputLine in @($OutputLines)) {
        $line = [string]$outputLine
        $receiptType = ''
        $jsonText = ''
        if ($line -match '^SUXIOS_COLLECTION_PLAN_GATE=(.*)$') {
            if ($planGateSeen) {
                $outputInvalid = $true
                continue
            }
            $planGateSeen = $true
            try {
                if ((ConvertTo-SafeDispatcherLine -Value $line) -ceq '[sensitive dispatcher output suppressed]') {
                    throw 'dispatcher_collection_plan_gate_sensitive_value_detected'
                }
                $planGate = ([string]$Matches[1]) | ConvertFrom-Json -ErrorAction Stop
                $planGateValid = Test-DispatcherCollectionPlanGate `
                    -Gate $planGate `
                    -Scope $Scope `
                    -CollectionRunGuid $CollectionRunGuid
                $planGateScopeHash = ([string]$planGate.scope_hash).Trim().ToLowerInvariant()
                if (-not $planGateValid) {
                    throw 'dispatcher_collection_plan_gate_invalid'
                }
            } catch {
                $planGateValid = $false
                $outputInvalid = $true
            }
            continue
        } elseif ($line -match '^SUXIOS_COLLECTION_RUN_RECEIPT=(.*)$') {
            $receiptType = 'collection_run'
            $jsonText = [string]$Matches[1]
        } elseif ($line -match '^SUXIOS_AUTO_FETCH_RECEIPT=(.*)$') {
            $receiptType = 'auto_fetch'
            $jsonText = [string]$Matches[1]
        } else {
            continue
        }
        try {
            if ((ConvertTo-SafeDispatcherLine -Value $line) -ceq '[sensitive dispatcher output suppressed]') {
                throw 'dispatcher_collection_output_sensitive_value_detected'
            }
            $receipt = $jsonText | ConvertFrom-Json -ErrorAction Stop
            if ($receipt.PSObject.Properties.Name -notcontains 'sensitive_values_exposed' `
                -or $receipt.sensitive_values_exposed -isnot [bool] `
                -or $receipt.sensitive_values_exposed -ne $false
            ) {
                throw 'dispatcher_collection_output_sensitive_contract_invalid'
            }
            $receiptRunId = ([string]$receipt.dispatcher_run_id).Trim().ToLowerInvariant()
            $receiptHotelId = if ($receiptType -eq 'collection_run') {
                [int]$receipt.system_hotel_id
            } else {
                [int]$receipt.hotel_id
            }
            $receiptDate = if ($receiptType -eq 'collection_run') {
                [string]$receipt.business_date
            } else {
                [string]$receipt.target_date
            }
            if ($receiptRunId -cne $expectedRunId `
                -or $receiptHotelId -ne [int]$Scope.hotel_id `
                -or $receiptDate -cne [string]$Scope.business_date
            ) {
                throw 'dispatcher_collection_output_scope_mismatch'
            }
            if ($receiptType -eq 'collection_run') {
                $sourceReceipts = @($receipt.source_receipts)
                $receiptSourceIds = [int[]]@(
                    $sourceReceipts |
                        ForEach-Object { [int]$_.data_source_id } |
                        Sort-Object -Unique
                )
                $receiptPlatforms = [string[]]@(
                    $sourceReceipts |
                        ForEach-Object { ([string]$_.platform).Trim().ToLowerInvariant() } |
                        Sort-Object -Unique
                )
            } else {
                $receiptSourceIds = [int[]]@(
                    @($receipt.source_ids) | ForEach-Object { [int]$_ } | Sort-Object -Unique
                )
                $receiptPlatforms = [string[]]@(
                    @($receipt.required_platforms) |
                        ForEach-Object { ([string]$_).Trim().ToLowerInvariant() } |
                        Sort-Object -Unique
                )
            }
            $receiptSourceIdsText = [string]::Join(
                ',',
                [string[]]@($receiptSourceIds | ForEach-Object { [string]$_ })
            )
            $receiptPlatformsText = [string]::Join(',', $receiptPlatforms)
            if ($receiptSourceIdsText -cne [string]$Scope.source_ids_text `
                -or $receiptPlatformsText -cne [string]$Scope.platforms_text
            ) {
                throw 'dispatcher_collection_output_source_scope_mismatch'
            }
            $receiptStatus = ([string]$receipt.status).Trim().ToLowerInvariant()
            if ($receiptType -eq 'auto_fetch') {
                $receiptStatus = switch ($receiptStatus) {
                    'success' { 'succeeded' }
                    'partial_success' { 'partial' }
                    default { $receiptStatus }
                }
            }
            if ($receiptStatus -notin @($collectionActiveStatuses + $collectionTerminalStatuses)) {
                throw 'dispatcher_collection_output_status_invalid'
            }
            if ($receiptType -eq 'auto_fetch') {
                if ($autoFetchReceiptSeen) {
                    throw 'dispatcher_collection_output_duplicate_auto_receipt'
                }
                $autoFetchReceiptSeen = $true
                $autoFetchReceiptStatus = $receiptStatus
            } else {
                if ($collectionRunReceiptSeen `
                    -and $collectionRunReceiptStatus -in $collectionTerminalStatuses
                ) {
                    throw 'dispatcher_collection_output_duplicate_collection_receipt'
                }
                $collectionRunReceiptSeen = $true
                $collectionRunReceiptStatus = $receiptStatus
            }
            if ($receiptType -eq 'auto_fetch' -and $receiptStatus -eq 'succeeded') {
                $autoFetchSuccessReceiptSeen = $true
                if (-not (Test-DispatcherAutoFetchSuccessReceipt `
                    -Receipt $receipt `
                    -Scope $Scope `
                    -ChildExitCode $ChildExitCode
                )) {
                    $autoFetchSuccessReceiptsValid = $false
                } else {
                    $autoExecutionBindingsText = Get-DispatcherSourceExecutionBindingsText `
                        -Rows @($receipt.source_tasks) `
                        -SyncTaskIdProperty 'sync_task_id'
                    $autoFetchSuccessEvidenceKey = [string]::Join('|', [string[]]@(
                        [string]$Scope.platform_source_bindings_text,
                        $autoExecutionBindingsText,
                        ([string]$receipt.collection_anchor_hash).Trim().ToLowerInvariant(),
                        ([string]$receipt.trust_receipt_digest).Trim().ToLowerInvariant()
                    ))
                }
            } elseif ($receiptType -eq 'collection_run' -and $receiptStatus -eq 'succeeded') {
                $collectionRunSuccessReceiptSeen = $true
                if (-not (Test-DispatcherCollectionRunSuccessReceipt `
                    -Receipt $receipt `
                    -Scope $Scope `
                    -CollectionRunGuid $CollectionRunGuid `
                    -ChildExitCode $ChildExitCode
                )) {
                    $collectionRunSuccessReceiptsValid = $false
                } else {
                    $collectionRunSuccessScopeHash = (
                        [string]$receipt.scope_hash
                    ).Trim().ToLowerInvariant()
                    $collectionExecutionBindingsText = Get-DispatcherSourceExecutionBindingsText `
                        -Rows @($receipt.source_receipts) `
                        -SyncTaskIdProperty 'platform_sync_task_id'
                    $collectionRunSuccessEvidenceKey = [string]::Join('|', [string[]]@(
                        [string]$Scope.platform_source_bindings_text,
                        $collectionExecutionBindingsText,
                        ([string]$receipt.collection_anchor_hash).Trim().ToLowerInvariant(),
                        ([string]$receipt.trust_receipt_digest).Trim().ToLowerInvariant()
                    ))
                }
            } elseif ($receiptType -eq 'collection_run' `
                -and $receiptStatus -in @('partial', 'failed', 'blocked', 'skipped', 'deferred')
            ) {
                $collectionRunTerminalReceiptSeen = $true
                $terminalReceiptContract = Test-DispatcherCollectionRunTerminalReceipt `
                    -Receipt $receipt `
                    -Scope $Scope `
                    -CollectionRunGuid $CollectionRunGuid
                $collectionRunTerminalReceiptValid = [bool]$terminalReceiptContract.valid
                if ($collectionRunTerminalReceiptValid) {
                    $collectionRunTerminalTrustedStatus = [string]$terminalReceiptContract.status
                    $collectionRunTerminalTrustedSource = [string]$terminalReceiptContract.source
                }
            }
        } catch {
            $outputInvalid = $true
        }
    }
    if ($autoFetchReceiptSeen -and $collectionRunReceiptSeen `
        -and ($autoFetchReceiptStatus -cne $collectionRunReceiptStatus `
            -or ($collectionRunReceiptStatus -eq 'succeeded' `
                -and $autoFetchSuccessEvidenceKey -cne $collectionRunSuccessEvidenceKey))
    ) {
        # AUTO is supplementary when both receipts exist. The durable
        # COLLECTION ledger is authoritative, while any disagreement makes the
        # pair unusable rather than allowing output order to choose a winner.
        $outputInvalid = $true # dispatcher_collection_output_receipt_disagreement
    }
    if (($autoFetchSuccessReceiptSeen -or $collectionRunSuccessReceiptSeen) `
        -and (-not $planGateSeen `
            -or -not $planGateValid `
            -or -not $collectionRunSuccessReceiptSeen `
            -or $collectionRunSuccessScopeHash -cne $planGateScopeHash)
    ) {
        $outputInvalid = $true # dispatcher_collection_output_plan_gate_unbound
    }
    if ($outputInvalid) {
        return [pscustomobject]@{
            trusted = $false
            status = 'started'
            source = 'runner_child_start'
            reason = 'child_output_untrusted'
        }
    }
    $authoritativeStatus = if ($collectionRunReceiptSeen) {
        $collectionRunReceiptStatus
    } elseif ($autoFetchReceiptSeen) {
        $autoFetchReceiptStatus
    } else {
        ''
    }
    $authoritativeSource = if ($collectionRunReceiptSeen) {
        'collection_run_authoritative'
    } else {
        'auto_fetch_fallback'
    }
    if ($authoritativeStatus -in $collectionTerminalStatuses) {
        $successReceiptVerified = if ($collectionRunReceiptSeen) {
            $collectionRunSuccessReceiptSeen `
                -and $collectionRunSuccessReceiptsValid `
                -and (-not $autoFetchReceiptSeen `
                    -or ($autoFetchSuccessReceiptSeen -and $autoFetchSuccessReceiptsValid))
        } else {
            $autoFetchSuccessReceiptSeen -and $autoFetchSuccessReceiptsValid
        }
        if ($authoritativeStatus -eq 'succeeded' `
            -and ($ChildExitCode -ne 0 `
                -or -not $successReceiptVerified)
        ) {
            return [pscustomobject]@{
                trusted = $false
                status = 'started'
                source = 'runner_child_start'
                reason = 'child_output_untrusted'
            }
        }
        if ($authoritativeStatus -ne 'succeeded') {
            # AUTO may summarize a failure but cannot prove a durable terminal
            # ledger. Only the exact strict COLLECTION receipt may rotate the
            # UUID; malformed or incomplete evidence keeps the run recoverable.
            if (-not $collectionRunReceiptSeen `
                -or -not $collectionRunTerminalReceiptSeen `
                -or -not $collectionRunTerminalReceiptValid
            ) {
                return [pscustomobject]@{
                    trusted = $false
                    status = 'started'
                    source = 'runner_child_start'
                    reason = 'child_output_untrusted'
                }
            }
            return [pscustomobject]@{
                trusted = $true
                status = $collectionRunTerminalTrustedStatus
                source = $collectionRunTerminalTrustedSource
                reason = ''
            }
        }
        return [pscustomobject]@{
            trusted = $true
            status = $authoritativeStatus
            source = $authoritativeSource
            reason = ''
        }
    }
    if ($authoritativeStatus -in $collectionActiveStatuses) {
        return [pscustomobject]@{
            trusted = $true
            status = $authoritativeStatus
            source = $authoritativeSource
            reason = ''
        }
    }
    return [pscustomobject]@{
        trusted = $false
        status = 'started'
        source = 'runner_child_start'
        reason = 'child_output_untrusted'
    }
}

function Resolve-DispatcherZeroExitReceiptContract {
    param(
        [string[]]$OutputLines = @(),
        [Parameter(Mandatory = $true)][int]$ChildExitCode
    )

    if ($ChildExitCode -ne 0) {
        return [pscustomobject]@{
            allowed = $true
            status = ''
            fail_exit_code = $ChildExitCode
        }
    }

    $statuses = @()
    $seenTypes = @{}
    foreach ($outputLine in @($OutputLines)) {
        $line = [string]$outputLine
        $receiptType = ''
        $jsonText = ''
        if ($line -match '^SUXIOS_COLLECTION_RUN_RECEIPT=(.*)$') {
            $receiptType = 'collection_run'
            $jsonText = [string]$Matches[1]
        } elseif ($line -match '^SUXIOS_AUTO_FETCH_RECEIPT=(.*)$') {
            $receiptType = 'auto_fetch'
            $jsonText = [string]$Matches[1]
        } else {
            continue
        }
        try {
            if ($seenTypes.ContainsKey($receiptType) `
                -or (ConvertTo-SafeDispatcherLine -Value $line) -ceq '[sensitive dispatcher output suppressed]'
            ) {
                throw 'dispatcher_zero_exit_receipt_invalid'
            }
            $seenTypes[$receiptType] = $true
            $receipt = $jsonText | ConvertFrom-Json -ErrorAction Stop
            if ($receipt.PSObject.Properties.Name -notcontains 'sensitive_values_exposed' `
                -or $receipt.sensitive_values_exposed -isnot [bool] `
                -or $receipt.sensitive_values_exposed -ne $false
            ) {
                throw 'dispatcher_zero_exit_receipt_sensitive_contract_invalid'
            }
            $status = ([string]$receipt.status).Trim().ToLowerInvariant()
            if ($receiptType -eq 'auto_fetch') {
                $status = switch ($status) {
                    'success' { 'succeeded' }
                    'partial_success' { 'partial' }
                    default { $status }
                }
            }
            if ($status -notin @($collectionActiveStatuses + $collectionTerminalStatuses)) {
                throw 'dispatcher_zero_exit_receipt_status_invalid'
            }
            $statuses += $status
        } catch {
            return [pscustomobject]@{
                allowed = $false
                status = 'invalid_receipt'
                fail_exit_code = 125
            }
        }
    }

    if ($statuses.Count -eq 0) {
        return [pscustomobject]@{
            allowed = $true
            status = ''
            fail_exit_code = 0
        }
    }
    $uniqueStatuses = @($statuses | Sort-Object -Unique)
    if ($uniqueStatuses.Count -ne 1) {
        return [pscustomobject]@{
            allowed = $false
            status = 'receipt_disagreement'
            fail_exit_code = 125
        }
    }
    $status = [string]$uniqueStatuses[0]
    if ($status -eq 'succeeded') {
        return [pscustomobject]@{
            allowed = $true
            status = $status
            fail_exit_code = 0
        }
    }
    return [pscustomobject]@{
        allowed = $false
        status = $status
        fail_exit_code = if ($status -in $collectionActiveStatuses) { 125 } else { 1 }
    }
}

function Enter-DispatcherScopeLock {
    param(
        [Parameter(Mandatory = $true)][int]$SystemHotelId,
        [AllowNull()][object]$Scope,
        [Parameter(Mandatory = $true)][guid]$CurrentExecutionGuid
    )

    $scopeLabel = if ($SystemHotelId -gt 0) { "hotel:$SystemHotelId" } else { 'hotel:global' }
    $globalGuardPath = Join-Path $logDirectory 'ota_dispatcher_all_hotels.guard.lock'
    $lockPath = if ($SystemHotelId -gt 0) {
        Join-Path $logDirectory "ota_dispatcher_hotel_$SystemHotelId.lock"
    } else {
        $globalGuardPath
    }
    $guardStream = $null
    $lockStream = $null
    try {
        if ($SystemHotelId -gt 0) {
            # Hotel-scoped runs share the global guard, then take an exclusive
            # per-hotel lease. This preserves cross-hotel parallelism while an
            # unscoped legacy run can still exclude every hotel-scoped run.
            $guardStream = [System.IO.File]::Open(
                $globalGuardPath,
                [System.IO.FileMode]::OpenOrCreate,
                [System.IO.FileAccess]::ReadWrite,
                [System.IO.FileShare]::ReadWrite
            )
            $lockStream = [System.IO.File]::Open(
                $lockPath,
                [System.IO.FileMode]::OpenOrCreate,
                [System.IO.FileAccess]::ReadWrite,
                [System.IO.FileShare]::None
            )
        } else {
            # The global runner exclusively owns the shared guard, preventing
            # overlap with both other global runs and every hotel-scoped run.
            $lockStream = [System.IO.File]::Open(
                $globalGuardPath,
                [System.IO.FileMode]::OpenOrCreate,
                [System.IO.FileAccess]::ReadWrite,
                [System.IO.FileShare]::None
            )
        }
        # These handles are OS-owned process-lifetime leases. There is no TTL
        # that can expire during a legitimate retry window, and the persistent
        # lock files are never deleted, so owner A cannot unlink owner B's lock.
        $metadata = [ordered]@{
            schema_version = 1
            owner_execution_id = $CurrentExecutionGuid.ToString('D').ToLowerInvariant()
            owner_process_id = $PID
            scope = $scopeLabel
            scope_key = if ($null -ne $Scope) { [string]$Scope.scope_key } else { '' }
            acquired_at = [datetimeoffset]::Now.ToString('o')
            lease = 'process_lifetime'
        } | ConvertTo-Json -Compress
        $lockStream.SetLength(0)
        $writer = [System.IO.StreamWriter]::new($lockStream, $utf8, 1024, $true)
        try {
            $writer.Write($metadata)
            $writer.Flush()
            $lockStream.Flush($true)
        } finally {
            $writer.Dispose()
        }
        return [pscustomobject]@{
            acquired = $true
            handle = $lockStream
            guard_handle = $guardStream
            scope = $scopeLabel
            path = $lockPath
            reason = ''
        }
    } catch {
        if ($null -ne $lockStream) {
            $lockStream.Dispose()
        }
        if ($null -ne $guardStream) {
            $guardStream.Dispose()
        }
        return [pscustomobject]@{
            acquired = $false
            handle = $null
            guard_handle = $null
            scope = $scopeLabel
            path = $lockPath
            reason = $_.Exception.GetType().Name
        }
    }
}

function Exit-DispatcherScopeLock {
    param([AllowNull()][object]$Lock)

    if ($null -ne $Lock `
        -and $Lock.PSObject.Properties.Name -contains 'handle' `
        -and $null -ne $Lock.handle
    ) {
        $Lock.handle.Dispose()
    }
    if ($null -ne $Lock `
        -and $Lock.PSObject.Properties.Name -contains 'guard_handle' `
        -and $null -ne $Lock.guard_handle
    ) {
        $Lock.guard_handle.Dispose()
    }
}

function Stop-DispatcherProcessTree {
    param([AllowNull()][System.Diagnostics.Process]$Process)

    if ($null -eq $Process) {
        return $true
    }
    try {
        if ($Process.HasExited) {
            return $true
        }
    } catch {
        return $true
    }

    $taskKillPath = Join-Path $env:SystemRoot 'System32\taskkill.exe'
    $treeKillCompleted = $false
    if (Test-Path -LiteralPath $taskKillPath -PathType Leaf) {
        try {
            $treeKiller = Start-Process `
                -FilePath $taskKillPath `
                -ArgumentList @('/PID', [string]$Process.Id, '/T', '/F') `
                -WindowStyle Hidden `
                -PassThru
            # Process-tree cleanup must not consume the collection timeout a
            # second time. taskkill continues independently if Windows needs
            # longer, while the direct parent fallback keeps this runner
            # bounded and the returned flag remains fail-closed.
            $treeKillCompleted = $treeKiller.WaitForExit(750)
            if (-not $treeKillCompleted) {
                # Do not allow a lingering taskkill process to inherit the
                # scheduled runner's output handles and keep its caller open.
                $treeKiller.Kill()
                $null = $treeKiller.WaitForExit(250)
            }
        } catch {
            # The direct-process fallback below is still required.
        }
    }
    try {
        if (-not $Process.HasExited) {
            $Process.Kill()
        }
        $parentStopped = $Process.WaitForExit(250)
        return $parentStopped -and $treeKillCompleted
    } catch {
        return $false
    }
}

$collectionStateEnabled = $Mode -eq 'Daily' -and $explicitScopeComplete
$collectionScope = $null
$collectionStateRead = [pscustomobject]@{ valid = $false; reason = 'state_not_applicable'; state = $null }
$collectionStateDecision = 'new'
$priorCollectionStatus = ''
$dispatcherRunGuid = [guid]::NewGuid()
if ($collectionStateEnabled) {
    $collectionScope = Get-DispatcherCollectionScope `
        -DispatcherMode $Mode `
        -SystemHotelId $HotelId `
        -BusinessDate $provenanceTargetDate `
        -ScopedSourceIds $SourceIds `
        -ScopedPlatforms $Platforms
    $collectionStateRead = Read-TrustedDispatcherCollectionState -Scope $collectionScope
    if ([bool]$collectionStateRead.valid) {
        $priorCollectionStatus = ([string]$collectionStateRead.state.status).ToLowerInvariant()
        if ($priorCollectionStatus -in $collectionActiveStatuses) {
            $dispatcherRunGuid = [guid]::Parse([string]$collectionStateRead.state.collection_run_id)
            $collectionStateDecision = 'reused_active'
        } else {
            $collectionStateDecision = 'rotated_terminal'
        }
    } elseif ((Test-Path -LiteralPath $collectionScope.state_path -PathType Leaf)) {
        $collectionStateDecision = 'rotated_invalid_state'
    }
}
if ($dispatcherRunGuid -eq $executionGuid) {
    $executionGuid = [guid]::NewGuid()
    $runId = (Get-Date -Format 'yyyyMMdd_HHmmss') + '_' + $executionGuid.ToString('N').ToLowerInvariant()
    $logPath = Join-Path $logDirectory "ota_dispatcher_$runId.log"
}

function ConvertTo-SafeDispatcherLine {
    param([AllowNull()][object]$Value)

    $line = [string]$Value
    $unicodeEscapePattern = [System.Text.RegularExpressions.Regex]::new(
        '[\\]+u([0-9a-f]{4})',
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )
    $unicodeEscapeEvaluator = [System.Text.RegularExpressions.MatchEvaluator]{
        param([System.Text.RegularExpressions.Match]$unicodeMatch)

        $codePoint = [Convert]::ToInt32($unicodeMatch.Groups[1].Value, 16)
        if ($codePoint -ge 0x20 -and $codePoint -le 0x7e) {
            return [string][char]$codePoint
        }
        return $unicodeMatch.Value
    }
    $detectionLine = $line
    do {
        $previousDetectionLine = $detectionLine
        $detectionLine = $unicodeEscapePattern.Replace(
            $detectionLine,
            $unicodeEscapeEvaluator
        )
        $detectionLine = $detectionLine -replace '[\\]+(?=["/])', ''
    } while ($detectionLine -cne $previousDetectionLine)
    # The scheduled command must never put authorization material in its
    # diagnostic log. Suppress a whole suspicious line instead of attempting
    # partial redaction that can leave a new platform key format exposed.
    $sensitiveKeyPattern = '(?i)(?:^|[^a-z0-9_])["'']?(?:access[_-]?token|refresh[_-]?token|api[_-]?key|spidertoken|token|set[_-]?cookies?|cookies?(?:[_-]?(?:file|jar|store))?|authorization|auth|password|passwd|secret|session(?:[_-]?id)?|signature|mtgsig|spiderkey|ticket|credential|profile(?:[_-]?key)?)["'']?\s*[:=]'
    $sensitiveCliPattern = '(?i)(?:^|\s)--(?:access[_-]?token|refresh[_-]?token|api[_-]?key|spidertoken|token|set[_-]?cookies?|cookies?(?:[_-]?(?:file|jar|store))?|authorization|auth|password|passwd|secret|session(?:[_-]?id)?|signature|mtgsig|spiderkey|ticket|credential|profile(?:[_-]?key)?)(?:=|\s+)'
    $bearerPattern = '(?i)\bbearer(?:\s+|%20)[a-z0-9._~+/%=-]+'
    $urlQueryPattern = '(?i)(?:https?://|(?:^|\s)/)[^\s?#]*\?[^\s#]*'
    $urlUserInfoPattern = '(?i)\bhttps?://[^\s/@]+@'
    if ($detectionLine -match $sensitiveKeyPattern `
        -or $detectionLine -match $sensitiveCliPattern `
        -or $detectionLine -match $bearerPattern `
        -or $detectionLine -match $urlQueryPattern `
        -or $detectionLine -match $urlUserInfoPattern
    ) {
        return '[sensitive dispatcher output suppressed]'
    }
    # Keep the task log machine-readable. Native PHP localized output has an
    # inconsistent legacy-console decode on this Windows host; the structured
    # receipt below is the authoritative diagnostic evidence and contains the
    # hotel/date/platform state without exposing a garbled display name.
    if ($line -notmatch '^SUXIOS_(?:AUTO_FETCH|COLLECTION_RUN)_RECEIPT=' `
        -and $line -notmatch '^SUXIOS_COLLECTION_PLAN_GATE=' `
        -and $line -match '[^\x00-\x7F]'
    ) {
        return '[localized dispatcher detail omitted; inspect the safe receipt]'
    }
    return $line
}

function Initialize-DispatcherPrivateCaptureFile {
    param([Parameter(Mandatory = $true)][string]$Path)

    [System.IO.File]::WriteAllText($Path, '', $utf8)
    try {
        $currentSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
        if ($null -eq $currentSid) {
            throw 'dispatcher_capture_file_identity_unavailable'
        }
        $security = [System.Security.AccessControl.FileSecurity]::new()
        $security.SetOwner($currentSid)
        $security.SetAccessRuleProtection($true, $false)
        $security.AddAccessRule(
            [System.Security.AccessControl.FileSystemAccessRule]::new(
                $currentSid,
                [System.Security.AccessControl.FileSystemRights]::FullControl,
                [System.Security.AccessControl.AccessControlType]::Allow
            )
        )
        [System.IO.File]::SetAccessControl($Path, $security)
        $readback = [System.IO.File]::GetAccessControl($Path)
        if (-not $readback.AreAccessRulesProtected) {
            throw 'dispatcher_capture_file_acl_readback_failed'
        }
    } catch {
        try {
            [System.IO.File]::Delete($Path)
        } catch {
            # Preserve the original ACL/setup failure.
        }
        throw
    }
}

function Invoke-SafeDispatcherProcess {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $true)][string[]]$ArgumentList,
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $true)][ValidatePattern('^[a-z0-9_]+$')][string]$Label,
        [Parameter(Mandatory = $true)][ValidateRange(1, 300)][int]$TimeoutSeconds
    )

    $safeStdoutPath = Join-Path $logDirectory "ota_dispatcher_$runId.$Label.stdout.tmp"
    $safeStderrPath = Join-Path $logDirectory "ota_dispatcher_$runId.$Label.stderr.tmp"
    $childProcess = $null
    try {
        Initialize-DispatcherPrivateCaptureFile -Path $safeStdoutPath
        Initialize-DispatcherPrivateCaptureFile -Path $safeStderrPath
        $childProcess = Start-Process `
            -FilePath $FilePath `
            -ArgumentList $ArgumentList `
            -WorkingDirectory $WorkingDirectory `
            -RedirectStandardOutput $safeStdoutPath `
            -RedirectStandardError $safeStderrPath `
            -PassThru `
            -NoNewWindow
        # Windows PowerShell 5.1 can drop the native process handle when a
        # short-lived redirected child exits. In that case ExitCode becomes
        # $null and an integer cast would incorrectly report success (0).
        # Materialize the handle while the child is live and fail closed if an
        # exit code still cannot be obtained.
        $null = $childProcess.Handle
        $completed = $childProcess.WaitForExit($TimeoutSeconds * 1000)
        if (-not $completed) {
            $treeStopped = Stop-DispatcherProcessTree -Process $childProcess
            return [pscustomobject]@{
                exit_code = 124
                timed_out = $true
                exception_type = if ($treeStopped) { '' } else { 'child_tree_stop_unverified' }
                stdout_lines = @()
            }
        }
        # Flush redirected streams before their temporary files are removed.
        $childProcess.WaitForExit()
        $capturedExitCode = $childProcess.ExitCode
        if ($null -eq $capturedExitCode) {
            return [pscustomobject]@{
                exit_code = 125
                timed_out = $false
                exception_type = 'child_exit_code_unavailable'
                stdout_lines = @()
            }
        }
        $safeStdoutLines = if (Test-Path -LiteralPath $safeStdoutPath -PathType Leaf) {
            @([System.IO.File]::ReadAllLines($safeStdoutPath, $utf8))
        } else {
            @()
        }
        return [pscustomobject]@{
            exit_code = [int]$capturedExitCode
            timed_out = $false
            exception_type = ''
            stdout_lines = $safeStdoutLines
        }
    } catch {
        return [pscustomobject]@{
            exit_code = 125
            timed_out = $false
            exception_type = $_.Exception.GetType().FullName
            stdout_lines = @()
        }
    } finally {
        if ($null -ne $childProcess) {
            $childProcess.Dispose()
        }
        foreach ($temporaryPath in @($safeStdoutPath, $safeStderrPath)) {
            if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
                try {
                    [System.IO.File]::Delete($temporaryPath)
                } catch {
                    # The file remains ACL-restricted if the OS still owns it.
                }
            }
        }
    }
}

$provenanceHelperPath = Join-Path $resolvedRoot 'scripts\lib\ota_dispatcher_provenance.ps1'
$provenanceHelperLoaded = $false
$provenanceHelperFailure = ''
try {
    if (-not (Test-Path -LiteralPath $provenanceHelperPath -PathType Leaf)) {
        throw 'dispatcher_provenance_helper_missing'
    }
    . $provenanceHelperPath
    $provenanceHelperLoaded = $true
} catch {
    $provenanceHelperFailure = $_.Exception.GetType().Name
}

function Get-DispatcherTaskEventBatch {
    param(
        [Parameter(Mandatory = $true)][datetimeoffset]$ReferenceTime
    )

    try {
        $eventLog = Get-WinEvent -ListLog 'Microsoft-Windows-TaskScheduler/Operational' -ErrorAction Stop
        if (-not [bool]$eventLog.IsEnabled) {
            return [pscustomobject]@{ available = $false; records = @() }
        }
        $filter = @{
            LogName = 'Microsoft-Windows-TaskScheduler/Operational'
            Id = @(100, 107, 110, 129, 200)
            StartTime = $ReferenceTime.LocalDateTime.AddMinutes(-5)
        }
        $events = @(Get-WinEvent -FilterHashtable $filter -MaxEvents 512 -ErrorAction SilentlyContinue)
        $records = @()
        foreach ($event in $events) {
            $xml = [xml]$event.ToXml()
            $values = @{}
            foreach ($dataNode in @($xml.Event.EventData.Data)) {
                $values[[string]$dataNode.Name] = [string]$dataNode.'#text'
            }
            $instanceId = if ($values.ContainsKey('TaskInstanceId')) {
                [string]$values.TaskInstanceId
            } elseif ($values.ContainsKey('InstanceId')) {
                [string]$values.InstanceId
            } else {
                ''
            }
            $processId = if ($values.ContainsKey('EnginePID')) {
                [int]$values.EnginePID
            } elseif ($values.ContainsKey('ProcessID')) {
                [int]$values.ProcessID
            } else {
                0
            }
            $records += [pscustomobject]@{
                event_id = [int]$event.Id
                time_created = $event.TimeCreated.ToString('o')
                task_name = [string]$values.TaskName
                task_instance_id = $instanceId
                process_id = $processId
            }
        }
        return [pscustomobject]@{ available = $true; records = @($records) }
    } catch {
        return [pscustomobject]@{ available = $false; records = @() }
    }
}

function Get-DispatcherScheduledTaskEvidence {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('Daily', 'Realtime')][string]$DispatcherMode,
        [Parameter(Mandatory = $true)][ValidateRange(1, 2147483647)][int]$SystemHotelId,
        [Parameter(Mandatory = $true)][datetimeoffset]$ReferenceTime
    )

    $taskName = if ($DispatcherMode -eq 'Realtime') {
        "SUXIOS OTA Realtime Dispatcher H$SystemHotelId"
    } else {
        "SUXIOS OTA Dispatcher H$SystemHotelId"
    }
    try {
        $task = Get-ScheduledTask -TaskName $taskName -TaskPath '\' -ErrorAction Stop
        $taskInfo = $task | Get-ScheduledTaskInfo -ErrorAction Stop
        $action = @($task.Actions)[0]
        $trigger = @($task.Triggers)[0]
        $lastRunOffset = [datetimeoffset]$taskInfo.LastRunTime
        $state = [string]$task.State
        $eventBatch = Get-DispatcherTaskEventBatch -ReferenceTime $ReferenceTime
        $correlation = Resolve-SuxiosDispatcherSchedulerCorrelation `
            -TaskName $taskName `
            -EngineProcessId $PID `
            -ReferenceTime $ReferenceTime.ToString('o') `
            -TaskState $state `
            -LastRunTime $lastRunOffset.ToString('o') `
            -EventRecords $eventBatch.records `
            -EventLogAvailable ([bool]$eventBatch.available) `
            -PreflightOnly:$([bool]$PreflightOnly)
        $contract = [ordered]@{
            task_name = $taskName
            task_path = '\'
            action_execute = [string]$action.Execute
            action_arguments = [string]$action.Arguments
            working_directory = [string]$action.WorkingDirectory
            trigger_start_boundary = [string]$trigger.StartBoundary
            trigger_interval = [string]$trigger.Repetition.Interval
            trigger_duration = [string]$trigger.Repetition.Duration
            principal_logon_type = [string]$task.Principal.LogonType
            principal_run_level = [string]$task.Principal.RunLevel
            multiple_instances = [string]$task.Settings.MultipleInstances
            start_when_available = [bool]$task.Settings.StartWhenAvailable
            wake_to_run = [bool]$task.Settings.WakeToRun
            execution_time_limit = [string]$task.Settings.ExecutionTimeLimit
        }
        return [pscustomobject]@{
            hash = Get-SuxiosDispatcherTaskContractHash -TaskContract $contract
            available = $true
            correlation = $correlation
        }
    } catch {
        return [pscustomobject]@{
            hash = Get-SuxiosStableSha256 -Value ([ordered]@{
                schema_version = 1
                task_name = $taskName
                status = 'unavailable'
            })
            available = $false
            correlation = [ordered]@{
                task_name = $taskName
                task_path = '\'
                state = ''
                last_run_time = ''
                last_run_delta_seconds = $null
                task_instance_id = $null
                engine_process_id = $null
                event_ids = @()
                reason = 'task_evidence_unavailable'
                status = 'unavailable'
            }
        }
    }
}

function Get-DispatcherFinishProvenance {
    param(
        [Parameter(Mandatory = $true)][object]$StartState,
        [Parameter(Mandatory = $true)][datetimeoffset]$FinishedAt,
        [Parameter(Mandatory = $true)][int]$ChildExitCode,
        [string[]]$ChildOutputLines = @()
    )

    if ([bool]$StartState.ready -ne $true) {
        return [pscustomobject]@{
            line = 'dispatcher_execution_provenance=unavailable;phase=finish'
            code_drifted = $false
            status = 'unavailable'
        }
    }
    try {
        $finishManifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot $resolvedRoot
        $finishTaskEvidence = Get-DispatcherScheduledTaskEvidence `
            -DispatcherMode $Mode `
            -SystemHotelId $HotelId `
            -ReferenceTime ([datetimeoffset]$StartState.started_at)
        $codeStable = [string]$finishManifest.sha256 -eq [string]$StartState.code_manifest.sha256
        $taskStable = [string]$finishTaskEvidence.hash -eq [string]$StartState.task_evidence.hash
        $manifestComplete = @($finishManifest.missing_roots).Count -eq 0
        $selectedSchedulerCorrelation = Select-SuxiosDispatcherTerminalSchedulerCorrelation `
            -StartCorrelation $StartState.task_evidence.correlation `
            -FinishCorrelation $finishTaskEvidence.correlation
        $schedulerCorrelated = [string]$selectedSchedulerCorrelation.status -eq 'correlated'
        $provenanceStatus = if (-not $codeStable) {
            'drifted'
        } elseif ($manifestComplete -and $taskStable -and $schedulerCorrelated) {
            'verified'
        } else {
            'unavailable'
        }
        $autoFetchReceiptLines = @(
            $ChildOutputLines | Where-Object { [string]$_ -match '^SUXIOS_AUTO_FETCH_RECEIPT=' }
        )
        $collectionRunReceiptLines = @(
            $ChildOutputLines | Where-Object { [string]$_ -match '^SUXIOS_COLLECTION_RUN_RECEIPT=' }
        )
        $terminalCollectionRunReceiptLines = @()
        foreach ($collectionRunReceiptLine in $collectionRunReceiptLines) {
            try {
                $collectionRunReceipt = (
                    [string]$collectionRunReceiptLine -replace '^SUXIOS_COLLECTION_RUN_RECEIPT=', ''
                ) | ConvertFrom-Json -ErrorAction Stop
                $collectionRunStatus = ([string]$collectionRunReceipt.status).Trim().ToLowerInvariant()
                if ($collectionRunReceipt.PSObject.Properties.Name -notcontains 'sensitive_values_exposed' `
                    -or $collectionRunReceipt.sensitive_values_exposed -isnot [bool] `
                    -or $collectionRunReceipt.sensitive_values_exposed -ne $false
                ) {
                    throw 'dispatcher_collection_receipt_sensitive_contract_invalid'
                }
                if ($collectionRunStatus -in $collectionTerminalStatuses) {
                    $terminalCollectionRunReceiptLines += [string]$collectionRunReceiptLine
                }
            } catch {
                # The main child-output validator already forces a non-zero
                # result. Do not let malformed lifecycle output become the
                # canonical provenance line as a fallback.
                $terminalCollectionRunReceiptLines = @()
                break
            }
        }
        # AUTO remains the compatibility receipt when present. Early plan-gate
        # failures and collection-run-only children emit only the durable
        # COLLECTION receipt, which must still be represented in provenance.
        $machineReceiptLines = @(
            if ($autoFetchReceiptLines.Count -gt 0) {
                $autoFetchReceiptLines
            } else {
                $terminalCollectionRunReceiptLines
            }
        )
        $childReceiptPresent = $machineReceiptLines.Count -gt 0
        $childReceiptHash = if ($childReceiptPresent) {
            Get-SuxiosStableSha256 -Value $machineReceiptLines
        } else {
            ''
        }
        $finishReceipt = New-SuxiosDispatcherProvenanceReceipt `
            -Phase finish `
            -RunId $dispatcherRunGuid `
            -StartedAt $StartState.started_at `
            -FinishedAt $FinishedAt.ToString('o') `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $StartState.source_ids `
            -Platforms $StartState.platforms `
            -RunnerSha256 $StartState.runner_sha256 `
            -CodeManifest $finishManifest `
            -EffectiveConfigSha256 $StartState.effective_config_sha256 `
            -TaskContractSha256 $finishTaskEvidence.hash `
            -SchedulerCorrelation $selectedSchedulerCorrelation `
            -ChildReceiptPresent $childReceiptPresent `
            -ChildReceiptCount $machineReceiptLines.Count `
            -ChildReceiptSha256 $childReceiptHash `
            -ChildExitCode $ChildExitCode `
            -CodeStableDuringRun $codeStable `
            -ProvenanceStatus $provenanceStatus
        return [pscustomobject]@{
            line = 'SUXIOS_OTA_DISPATCHER_PROVENANCE=' + $finishReceipt
            code_drifted = -not $codeStable
            status = $provenanceStatus
        }
    } catch {
        return [pscustomobject]@{
            line = 'dispatcher_execution_provenance=unavailable;phase=finish;reason=' + $_.Exception.GetType().Name
            code_drifted = $false
            status = 'unavailable'
        }
    }
}

$startedAtOffset = [datetimeoffset]::Now
$startedAt = $startedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
$lines = @("[$startedAt] SUXIOS OTA dispatcher started.")
$exitCode = 1
$stdoutPath = Join-Path $logDirectory "ota_dispatcher_$runId.stdout.tmp"
$stderrPath = Join-Path $logDirectory "ota_dispatcher_$runId.stderr.tmp"
$effectiveSourceIds = if ($null -ne $collectionScope) {
    [string]$collectionScope.source_ids_text
} else {
    $SourceIds
}
$effectivePlatforms = if ($null -ne $collectionScope) {
    [string]$collectionScope.platforms_text
} else {
    $Platforms
}
$scheduleArgument = if ($Mode -eq 'Realtime') { '--realtime-only' } else { '--daily-only' }
$dispatcherArguments = @(
    ('"{0}"' -f $thinkPath),
    'online-data:auto-fetch',
    $scheduleArgument
)
if ($Mode -eq 'Daily') {
    $dispatcherArguments += "--target-date=$dailyTargetDate"
    $dispatcherArguments += "--dispatcher-run-id=$($dispatcherRunGuid.ToString('D').ToLowerInvariant())"
    $lines += "dispatcher_target_date=$dailyTargetDate;timezone=Asia/Shanghai"
}
if ($explicitScopeComplete) {
    $dispatcherArguments += @(
        "--hotel-id=$HotelId",
        "--source-ids=$effectiveSourceIds",
        "--platforms=$effectivePlatforms"
    )
    $lines += "dispatcher_scope=hotel:$HotelId;platforms:$effectivePlatforms;source_count:$(@($effectiveSourceIds.Split(',')).Count)"
}
if ($PreflightOnly) {
    $lines += 'dispatcher_run_mode=preflight_only;ota_collection_started=false'
}
$effectiveCollectionTimeoutSeconds = if ($CollectionTimeoutSeconds -gt 0) {
    $CollectionTimeoutSeconds
} else {
    $maximumCollectionTimeoutSeconds
}
$scopeLock = Enter-DispatcherScopeLock `
    -SystemHotelId $HotelId `
    -Scope $collectionScope `
    -CurrentExecutionGuid $executionGuid
if (-not [bool]$scopeLock.acquired) {
    $lines += "dispatcher_scope_lock=blocked;scope=$([string]$scopeLock.scope);lease=process_lifetime;ota_collection_started=false"
    $lines += 'dispatcher_terminal_status=finished;exit_code=125'
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    Exit-DispatcherScopeLock -Lock $scopeLock
    Write-Output "dispatcher_log=$logPath"
    Write-Output 'dispatcher_exit_code=125'
    exit 125
}
$lines += "dispatcher_scope_lock=acquired;scope=$([string]$scopeLock.scope);lease=process_lifetime"
$lines += 'dispatcher_terminal_status=started_without_terminal_receipt'
# Persist the safe scope/start receipt before the child process begins. If the
# Windows execution limit terminates this runner, the missing terminal status
# remains explicit instead of looking like a task that never started.
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

$lines += "dispatcher_execution_id=$($executionGuid.ToString('D').ToLowerInvariant());schema_version=1"
$lines += "dispatcher_run_id=$($dispatcherRunGuid.ToString('D').ToLowerInvariant());schema_version=1"
if ($collectionStateEnabled) {
    $planFingerprintLabel = if ([string]$collectionScope.plan_fingerprint -eq '') {
        'unavailable'
    } else {
        [string]$collectionScope.plan_fingerprint
    }
    $priorStatusLabel = if ($priorCollectionStatus -eq '') { 'none' } else { $priorCollectionStatus }
    $lines += [string]::Join(';', [string[]]@(
        'dispatcher_collection_state=selected',
        "decision=$collectionStateDecision",
        "prior_status=$priorStatusLabel",
        "collection_run_id=$($dispatcherRunGuid.ToString('D').ToLowerInvariant())",
        "scope_key=$([string]$collectionScope.scope_key)",
        "plan_fingerprint=$planFingerprintLabel"
    ))
}
$provenanceState = [pscustomobject]@{
    ready = $false
    started_at = $startedAtOffset.ToString('o')
    source_ids = @()
    platforms = @()
    runner_sha256 = ''
    code_manifest = $null
    effective_config_sha256 = ''
    task_evidence = $null
}
if ($provenanceHelperLoaded -and $explicitScopeComplete) {
    try {
        $sourceIdValues = [int[]]@($effectiveSourceIds.Split(',') | ForEach-Object { [int]$_ })
        $platformValues = [string[]]@($effectivePlatforms.Split(',') | ForEach-Object { $_.Trim().ToLowerInvariant() })
        $startManifest = Get-SuxiosDispatcherCodeManifest -ProjectRoot $resolvedRoot
        $runnerSha256 = Get-SuxiosFileSha256 -Path $PSCommandPath
        $effectiveConfigSha256 = Get-SuxiosDispatcherEffectiveConfigHash `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $sourceIdValues `
            -Platforms $platformValues `
            -PhpPath $resolvedPhp `
            -ThinkPath $thinkPath
        $startTaskEvidence = Get-DispatcherScheduledTaskEvidence `
            -DispatcherMode $Mode `
            -SystemHotelId $HotelId `
            -ReferenceTime $startedAtOffset
        $startReceipt = New-SuxiosDispatcherProvenanceReceipt `
            -Phase start `
            -RunId $dispatcherRunGuid `
            -StartedAt $startedAtOffset.ToString('o') `
            -Mode $Mode `
            -Timezone 'Asia/Shanghai' `
            -TargetDate $provenanceTargetDate `
            -HotelId $HotelId `
            -SourceIds $sourceIdValues `
            -Platforms $platformValues `
            -RunnerSha256 $runnerSha256 `
            -CodeManifest $startManifest `
            -EffectiveConfigSha256 $effectiveConfigSha256 `
            -TaskContractSha256 $startTaskEvidence.hash `
            -SchedulerCorrelation $startTaskEvidence.correlation `
            -ProvenanceStatus started
        $provenanceState = [pscustomobject]@{
            ready = $true
            started_at = $startedAtOffset.ToString('o')
            source_ids = $sourceIdValues
            platforms = $platformValues
            runner_sha256 = $runnerSha256
            code_manifest = $startManifest
            effective_config_sha256 = $effectiveConfigSha256
            task_evidence = $startTaskEvidence
        }
        $lines += 'SUXIOS_OTA_DISPATCHER_PROVENANCE=' + $startReceipt
    } catch {
        $lines += 'dispatcher_execution_provenance=unavailable;phase=start;reason=' + $_.Exception.GetType().Name
    }
} else {
    $provenanceReason = if (-not $provenanceHelperLoaded) {
        'helper_' + ($provenanceHelperFailure -replace '[^A-Za-z0-9_]', '')
    } else {
        'fixed_scope_required'
    }
    $lines += "dispatcher_execution_provenance=unavailable;phase=start;reason=$provenanceReason"
}
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

$databaseCheckArguments = @(
    ('"{0}"' -f $thinkPath),
    'db:check'
)
$initialDatabaseCheck = Invoke-SafeDispatcherProcess `
    -FilePath $resolvedPhp `
    -ArgumentList $databaseCheckArguments `
    -WorkingDirectory $resolvedRoot `
    -Label 'db_check_initial' `
    -TimeoutSeconds 30
$verifiedDatabaseCheck = $initialDatabaseCheck
$databasePreflightBlocked = $false
$databasePreflightReason = ''
$databasePreflightExitCode = 1

if ($initialDatabaseCheck.exit_code -eq 0) {
    $lines += 'dispatcher_database_preflight=ready;recovery_attempted=false;exit_code=0'
} elseif ($initialDatabaseCheck.exit_code -eq 2) {
    # Exit 2 is the schema-governance signal. Starting MySQL cannot repair it,
    # and automatic migration is intentionally outside this dispatcher.
    $databasePreflightBlocked = $true
    $databasePreflightReason = 'database_schema_upgrade_required'
    $databasePreflightExitCode = 2
    $lines += 'dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required;exit_code=2;recovery_attempted=false'
} elseif ($initialDatabaseCheck.exit_code -ne 0) {
    $databaseRecoveryScriptPath = Join-Path $resolvedRoot 'scripts\start_local_stack.ps1'
    if (Test-Path -LiteralPath $databaseRecoveryScriptPath -PathType Leaf) {
        $databaseRecoveryResult = Invoke-SafeDispatcherProcess `
            -FilePath (Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe') `
            -ArgumentList @(
                '-NoProfile',
                '-NonInteractive',
                '-WindowStyle',
                'Hidden',
                '-ExecutionPolicy',
                'Bypass',
                '-File',
                ('"{0}"' -f $databaseRecoveryScriptPath),
                '-DatabaseOnly',
                '-NoBrowser'
            ) `
            -WorkingDirectory $resolvedRoot `
            -Label 'database_recovery' `
            -TimeoutSeconds 60
    } else {
        $databaseRecoveryResult = [pscustomobject]@{
            exit_code = 127
            timed_out = $false
            exception_type = 'startup_script_missing'
        }
    }
    $lines += "dispatcher_database_recovery=attempted;exit_code=$($databaseRecoveryResult.exit_code);timed_out=$([bool]$databaseRecoveryResult.timed_out)"

    # Always re-check with ThinkPHP's authoritative schema probe. A startup
    # helper failure can still leave MySQL recovered, while a helper success
    # must not conceal a connection or schema mismatch.
    $verifiedDatabaseCheck = Invoke-SafeDispatcherProcess `
        -FilePath $resolvedPhp `
        -ArgumentList $databaseCheckArguments `
        -WorkingDirectory $resolvedRoot `
        -Label 'db_check_verified' `
        -TimeoutSeconds 30
    if ($verifiedDatabaseCheck.exit_code -eq 0) {
        $lines += 'dispatcher_database_preflight=ready;recovery_attempted=true;verified_exit_code=0'
    } elseif ($verifiedDatabaseCheck.exit_code -eq 2) {
        $databasePreflightBlocked = $true
        $databasePreflightReason = 'database_schema_upgrade_required'
        $databasePreflightExitCode = 2
        $lines += 'dispatcher_database_preflight=blocked;reason=database_schema_upgrade_required;exit_code=2;recovery_attempted=true'
    } else {
        $databasePreflightBlocked = $true
        $databasePreflightReason = 'database_runtime_unavailable'
        $databasePreflightExitCode = 1
        $lines += "dispatcher_database_preflight=blocked;reason=database_runtime_unavailable;initial_exit_code=$($initialDatabaseCheck.exit_code);verified_exit_code=$($verifiedDatabaseCheck.exit_code)"
    }
}

if ($PreflightOnly -or $databasePreflightBlocked) {
    if ($databasePreflightBlocked) {
        $exitCode = $databasePreflightExitCode
        $lines += "dispatcher_preflight_result=blocked;reason=$databasePreflightReason;ota_collection_started=false"
    } else {
        $exitCode = 0
        $lines += 'dispatcher_preflight_result=ready;ota_collection_started=false'
    }
    $preflightExitCode = $exitCode
    $finishedAtOffset = [datetimeoffset]::Now
    $finishProvenance = Get-DispatcherFinishProvenance `
        -StartState $provenanceState `
        -FinishedAt $finishedAtOffset `
        -ChildExitCode $preflightExitCode `
        -ChildOutputLines @()
    $lines += $finishProvenance.line
    if ([bool]$finishProvenance.code_drifted) {
        $exitCode = 1
        $lines += "dispatcher_execution_provenance=drifted;preflight_exit_code=$preflightExitCode;final_exit_code=1"
    }
    $finishedAt = $finishedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
    $lines += "dispatcher_terminal_status=finished;exit_code=$exitCode"
    $lines += "[$finishedAt] SUXIOS OTA dispatcher preflight finished. exit_code=$exitCode"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    Exit-DispatcherScopeLock -Lock $scopeLock
    Write-Output "dispatcher_log=$logPath"
    Write-Output "dispatcher_exit_code=$exitCode"
    exit $exitCode
}

$collectionStartStateReady = $true
if ($collectionStateEnabled) {
    try {
        $null = Write-TrustedDispatcherCollectionState `
            -Scope $collectionScope `
            -CollectionRunGuid $dispatcherRunGuid `
            -Status 'started' `
            -StatusSource 'runner_child_start' `
            -CurrentExecutionGuid $executionGuid
        $lines += 'dispatcher_collection_state=stored;status=started;ota_collection_started=pending'
    } catch {
        $collectionStartStateReady = $false
        $lines += 'dispatcher_collection_state=blocked;reason=trusted_state_write_failed;ota_collection_started=false'
    }
}
if (-not $collectionStartStateReady) {
    $exitCode = 125
    $finishedAtOffset = [datetimeoffset]::Now
    $finishProvenance = Get-DispatcherFinishProvenance `
        -StartState $provenanceState `
        -FinishedAt $finishedAtOffset `
        -ChildExitCode $exitCode `
        -ChildOutputLines @()
    $lines += $finishProvenance.line
    $finishedAt = $finishedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
    $lines += 'dispatcher_terminal_status=finished;exit_code=125'
    $lines += "[$finishedAt] SUXIOS OTA dispatcher blocked before collection. exit_code=125"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    Exit-DispatcherScopeLock -Lock $scopeLock
    Write-Output "dispatcher_log=$logPath"
    Write-Output 'dispatcher_exit_code=125'
    exit 125
}

$lines += 'dispatcher_preflight_result=ready;ota_collection_started=pending'
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
$rawStdout = @()
$rawStderr = @()
$process = $null
try {
    Initialize-DispatcherPrivateCaptureFile -Path $stdoutPath
    Initialize-DispatcherPrivateCaptureFile -Path $stderrPath
    $process = Start-Process `
        -FilePath $resolvedPhp `
        -ArgumentList $dispatcherArguments `
        -WorkingDirectory $resolvedRoot `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath `
        -PassThru `
        -NoNewWindow
    # Materialize the native handle before waiting. Without this, Windows
    # PowerShell 5.1 can expose ExitCode=$null for a short redirected process;
    # casting that value to int would create a false success (0).
    $null = $process.Handle
    $dispatcherCompleted = $process.WaitForExit($effectiveCollectionTimeoutSeconds * 1000)
    if (-not $dispatcherCompleted) {
        $treeStopped = Stop-DispatcherProcessTree -Process $process
        $exitCode = 124
        $lines += "dispatcher_child=timed_out;timeout_seconds=$effectiveCollectionTimeoutSeconds;exit_code=124;process_tree_stopped=$($treeStopped.ToString().ToLowerInvariant())"
    } else {
        # Flush redirected streams after the bounded wait completed.
        $process.WaitForExit()
        $capturedDispatcherExitCode = $process.ExitCode
        if ($null -eq $capturedDispatcherExitCode) {
            $exitCode = 125
            $lines += 'dispatcher_child_exit_code=unavailable;fail_closed_exit_code=125'
        } else {
            $exitCode = [int]$capturedDispatcherExitCode
        }
    }
    # A timed-out process tree may still be completing taskkill asynchronously
    # and therefore retain redirected-file handles briefly. Its partial output
    # is not eligible as a terminal receipt, so do not reopen those captures or
    # let a sharing violation replace the authoritative timeout exit code.
    if ($exitCode -ne 124) {
        if (Test-Path -LiteralPath $stdoutPath -PathType Leaf) {
            $rawStdout = @([System.IO.File]::ReadAllLines($stdoutPath, $utf8))
        }
        if (Test-Path -LiteralPath $stderrPath -PathType Leaf) {
            $rawStderr = @([System.IO.File]::ReadAllLines($stderrPath, $utf8))
        }
    }
    foreach ($entry in @($rawStdout)) {
        $lines += ConvertTo-SafeDispatcherLine -Value $entry
    }
    foreach ($entry in @($rawStderr)) {
        $lines += ConvertTo-SafeDispatcherLine -Value $entry
    }
} catch {
    $lines += ('dispatcher_exception=' + $_.Exception.GetType().FullName)
    $exitCode = 1
} finally {
    if ($null -ne $process) {
        $process.Dispose()
    }
    foreach ($capturePath in @($stdoutPath, $stderrPath)) {
        if (Test-Path -LiteralPath $capturePath -PathType Leaf) {
            try {
                [System.IO.File]::Delete($capturePath)
            } catch {
                # The file remains ACL-restricted if the OS still owns it.
            }
        }
    }
}

$childExitCode = $exitCode
if ($collectionStateEnabled) {
    $collectionOutputStatus = Resolve-DispatcherCollectionOutputStatus `
        -OutputLines $rawStdout `
        -Scope $collectionScope `
        -CollectionRunGuid $dispatcherRunGuid `
        -ChildExitCode $childExitCode
    if (-not [bool]$collectionOutputStatus.trusted) {
        # A missing, damaged or cross-scope receipt cannot prove that a local
        # task is terminal. Preserve the pre-child started state so the next
        # invocation polls the exact same dispatcher UUID instead of orphaning
        # work that PHP may already have queued on the operator device.
        $lines += 'dispatcher_collection_state=preserved;status=started;reason=child_output_untrusted'
        if ($exitCode -eq 0) {
            $exitCode = 125
        }
    } else {
        try {
            $null = Write-TrustedDispatcherCollectionState `
                -Scope $collectionScope `
                -CollectionRunGuid $dispatcherRunGuid `
                -Status ([string]$collectionOutputStatus.status) `
                -StatusSource ([string]$collectionOutputStatus.source) `
                -CurrentExecutionGuid $executionGuid
            $lines += "dispatcher_collection_state=stored;status=$([string]$collectionOutputStatus.status);source=$([string]$collectionOutputStatus.source)"
            if ($childExitCode -eq 0 -and [string]$collectionOutputStatus.status -ne 'succeeded') {
                $exitCode = if ([string]$collectionOutputStatus.status -in $collectionActiveStatuses) { 125 } else { 1 }
                $lines += "dispatcher_child_zero_exit_rejected=status:$([string]$collectionOutputStatus.status);final_exit_code=$exitCode"
            }
        } catch {
            # Keep the prior started state so the next invocation reuses the same
            # collection UUID instead of orphaning an exact local collector task.
            $lines += 'dispatcher_collection_state=write_failed;prior_started_state_preserved=true;exception_type=' + $_.Exception.GetType().Name
            $exitCode = 125
        }
    }
} elseif ($childExitCode -eq 0) {
    # Non-daily or unscoped runs do not own the durable collection ledger. They
    # may still emit a machine receipt; a declared failure/active state must not
    # be converted into scheduler success merely because the child returned 0.
    $zeroExitReceiptContract = Resolve-DispatcherZeroExitReceiptContract `
        -OutputLines $rawStdout `
        -ChildExitCode $childExitCode
    if (-not [bool]$zeroExitReceiptContract.allowed) {
        $exitCode = [int]$zeroExitReceiptContract.fail_exit_code
        $lines += "dispatcher_child_zero_exit_rejected=status:$([string]$zeroExitReceiptContract.status);final_exit_code=$exitCode"
    }
}
$finishedAtOffset = [datetimeoffset]::Now
$finishProvenance = Get-DispatcherFinishProvenance `
    -StartState $provenanceState `
    -FinishedAt $finishedAtOffset `
    -ChildExitCode $childExitCode `
    -ChildOutputLines $rawStdout
$lines += $finishProvenance.line
if ([bool]$finishProvenance.code_drifted) {
    $exitCode = 1
    $lines += "dispatcher_execution_provenance=drifted;child_exit_code=$childExitCode;final_exit_code=1"
}
$finishedAt = $finishedAtOffset.ToString('yyyy-MM-dd HH:mm:ss K')
$lines += "dispatcher_terminal_status=finished;exit_code=$exitCode"
$lines += "[$finishedAt] SUXIOS OTA dispatcher finished. exit_code=$exitCode"
[System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)

# A Daily-only, non-blocking sidecar now joins the natural Task Scheduler proof
# with the child receipt's exact save/readback/P0/four-check facts. It never
# changes the collection exit code and never starts another OTA request.
if ($Mode -eq 'Daily' -and $explicitScopeComplete) {
    $acceptanceScriptPath = Join-Path $resolvedRoot 'scripts\verify_canonical_ota_daily_natural_acceptance.php'
    $acceptanceLine = ''
    $acceptanceExitCode = 125
    $acceptanceUnavailableReason = if ($childExitCode -eq 124) {
        'collection_child_timed_out'
    } else {
        'daily_acceptance_sidecar_unavailable'
    }
    # A timed-out collection cannot satisfy natural acceptance. Emit the
    # fail-closed fallback directly instead of starting another PHP process
    # after the authoritative collection deadline has already elapsed.
    if ($childExitCode -ne 124 `
        -and (Test-Path -LiteralPath $acceptanceScriptPath -PathType Leaf)
    ) {
        $acceptanceResult = Invoke-SafeDispatcherProcess `
            -FilePath $resolvedPhp `
            -ArgumentList @(
                ('"{0}"' -f $acceptanceScriptPath),
                "--hotel-id=$HotelId",
                "--target-date=$dailyTargetDate",
                "--source-ids=$effectiveSourceIds",
                "--platforms=$effectivePlatforms",
                ('--dispatcher-log="{0}"' -f $logPath)
            ) `
            -WorkingDirectory $resolvedRoot `
            -Label 'daily_acceptance' `
            -TimeoutSeconds 60
        $acceptanceExitCode = [int]$acceptanceResult.exit_code
        $acceptanceLines = @(
            $acceptanceResult.stdout_lines |
                Where-Object { [string]$_ -match '^SUXIOS_OTA_DAILY_ACCEPTANCE=\{.*\}$' }
        )
        if ($acceptanceLines.Count -eq 1) {
            try {
                $acceptanceJson = ([string]$acceptanceLines[0]).Substring(
                    'SUXIOS_OTA_DAILY_ACCEPTANCE='.Length
                ) | ConvertFrom-Json -ErrorAction Stop
                if ($acceptanceJson.sensitive_values_exposed -eq $false -and
                    [string]$acceptanceJson.status -in @('verified', 'blocked')) {
                    $acceptanceLine = [string]$acceptanceLines[0]
                }
            } catch {
                $acceptanceLine = ''
            }
        }
    }
    if ($acceptanceLine -eq '') {
        $fallbackAcceptance = [ordered]@{
            schema_version = 'suxios_ota_daily_natural_acceptance.v1'
            status = 'blocked'
            reason_codes = @($acceptanceUnavailableReason)
            hotel_id = $HotelId
            target_date = $dailyTargetDate
            collection_triggered_by_acceptance = $false
            business_data_written_by_acceptance = $false
            external_action_triggered = $false
            business_outcome_claimed = $false
            causality_claimed = $false
            sensitive_values_exposed = $false
        }
        $acceptanceLine = 'SUXIOS_OTA_DAILY_ACCEPTANCE=' + (
            $fallbackAcceptance | ConvertTo-Json -Compress -Depth 8
        )
    }
    $lines += $acceptanceLine
    $lines += "dispatcher_daily_acceptance_sidecar=recorded;sidecar_exit_code=$acceptanceExitCode;non_blocking=true"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
    $acceptanceReadbackLines = @(
        [System.IO.File]::ReadAllLines($logPath, $utf8) |
            Where-Object { [string]$_ -match '^SUXIOS_OTA_DAILY_ACCEPTANCE=' }
    )
    $acceptanceReadbackVerified = $acceptanceReadbackLines.Count -eq 1 -and
        [string]$acceptanceReadbackLines[0] -ceq $acceptanceLine
    $lines += "dispatcher_daily_acceptance_readback_verified=$($acceptanceReadbackVerified.ToString().ToLowerInvariant());receipt_count=$($acceptanceReadbackLines.Count)"
    [System.IO.File]::WriteAllLines($logPath, [string[]]$lines, $utf8)
}

Exit-DispatcherScopeLock -Lock $scopeLock
Write-Output "dispatcher_log=$logPath"
Write-Output "dispatcher_exit_code=$exitCode"
exit $exitCode
