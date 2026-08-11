Set-StrictMode -Version Latest

function ConvertTo-SuxiosCanonicalJsonValue {
    param(
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [object]$Value
    )

    if ($null -eq $Value) {
        return 'null'
    }
    if ($Value -is [bool]) {
        return $(if ($Value) { 'true' } else { 'false' })
    }
    if ($Value -is [string] -or $Value -is [char] -or $Value -is [guid]) {
        return ([string]$Value | ConvertTo-Json -Compress)
    }
    if ($Value -is [datetimeoffset]) {
        return ($Value.ToString('o', [System.Globalization.CultureInfo]::InvariantCulture) | ConvertTo-Json -Compress)
    }
    if ($Value -is [datetime]) {
        return ($Value.ToString('o', [System.Globalization.CultureInfo]::InvariantCulture) | ConvertTo-Json -Compress)
    }
    if ($Value -is [double]) {
        if ([double]::IsNaN($Value) -or [double]::IsInfinity($Value)) {
            throw 'Canonical JSON does not accept non-finite numbers.'
        }
        return $Value.ToString('R', [System.Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [single]) {
        if ([single]::IsNaN($Value) -or [single]::IsInfinity($Value)) {
            throw 'Canonical JSON does not accept non-finite numbers.'
        }
        return $Value.ToString('R', [System.Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [byte] -or $Value -is [sbyte] -or
        $Value -is [int16] -or $Value -is [uint16] -or
        $Value -is [int32] -or $Value -is [uint32] -or
        $Value -is [int64] -or $Value -is [uint64] -or
        $Value -is [decimal]
    ) {
        return $Value.ToString([System.Globalization.CultureInfo]::InvariantCulture)
    }
    if ($Value -is [System.Collections.IDictionary]) {
        $keys = [string[]]@($Value.Keys | ForEach-Object { [string]$_ })
        [Array]::Sort($keys, [System.StringComparer]::Ordinal)
        $members = foreach ($key in $keys) {
            $encodedKey = $key | ConvertTo-Json -Compress
            $encodedValue = ConvertTo-SuxiosCanonicalJsonValue -Value $Value[$key]
            $encodedKey + ':' + $encodedValue
        }
        return '{' + ($members -join ',') + '}'
    }
    if ($Value -is [System.Management.Automation.PSCustomObject]) {
        $properties = @{}
        foreach ($property in $Value.PSObject.Properties) {
            $properties[[string]$property.Name] = $property.Value
        }
        return ConvertTo-SuxiosCanonicalJsonValue -Value $properties
    }
    if ($Value -is [System.Collections.IEnumerable]) {
        $items = foreach ($item in $Value) {
            ConvertTo-SuxiosCanonicalJsonValue -Value $item
        }
        return '[' + ($items -join ',') + ']'
    }

    return ([string]$Value | ConvertTo-Json -Compress)
}

function ConvertTo-SuxiosCanonicalJson {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [object]$Value
    )

    return ConvertTo-SuxiosCanonicalJsonValue -Value $Value
}

function Get-SuxiosSha256FromText {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$Text
    )

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [System.Text.UTF8Encoding]::new($false).GetBytes($Text)
        return ([System.BitConverter]::ToString($sha256.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally {
        $sha256.Dispose()
    }
}

function Get-SuxiosStableSha256 {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [AllowNull()]
        [object]$Value
    )

    return Get-SuxiosSha256FromText -Text (ConvertTo-SuxiosCanonicalJson -Value $Value)
}

function Get-SuxiosNormalizedSourceIds {
    param([Parameter(Mandatory = $true)][int[]]$SourceIds)

    $normalized = [int[]]@($SourceIds | Where-Object { $_ -gt 0 } | Sort-Object -Unique)
    if ($normalized.Count -eq 0) {
        throw 'Dispatcher provenance requires at least one positive source id.'
    }
    return $normalized
}

function Get-SuxiosNormalizedPlatforms {
    param([Parameter(Mandatory = $true)][string[]]$Platforms)

    $normalized = [string[]]@(
        $Platforms |
            ForEach-Object { ([string]$_).Trim().ToLowerInvariant() } |
            Where-Object { $_ -ne '' } |
            Sort-Object -Unique
    )
    if ($normalized.Count -eq 0 -or @($normalized | Where-Object { $_ -notin @('ctrip', 'meituan') }).Count -gt 0) {
        throw 'Dispatcher provenance platforms must contain only ctrip and/or meituan.'
    }
    return $normalized
}

function Assert-SuxiosBusinessDate {
    param([Parameter(Mandatory = $true)][string]$TargetDate)

    [datetime]$parsed = [datetime]::MinValue
    $valid = [datetime]::TryParseExact(
        $TargetDate,
        'yyyy-MM-dd',
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.DateTimeStyles]::None,
        [ref]$parsed
    )
    if (-not $valid) {
        throw 'Dispatcher provenance target date must use yyyy-MM-dd.'
    }
}

function ConvertTo-SuxiosPathIdentity {
    param([Parameter(Mandatory = $true)][ValidateNotNullOrEmpty()][string]$Path)

    $identity = $Path.Trim()
    try {
        $identity = [System.IO.Path]::GetFullPath($identity)
    } catch {
        throw 'Dispatcher provenance received an invalid executable path.'
    }
    return $identity.Replace('/', '\').TrimEnd('\').ToLowerInvariant()
}

function New-SuxiosDispatcherScopeDescriptor {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 2147483647)]
        [int]$HotelId,

        [Parameter(Mandatory = $true)]
        [int[]]$SourceIds,

        [Parameter(Mandatory = $true)]
        [string[]]$Platforms
    )

    return [ordered]@{
        hotel_id = $HotelId
        source_ids = @(Get-SuxiosNormalizedSourceIds -SourceIds $SourceIds)
        platforms = @(Get-SuxiosNormalizedPlatforms -Platforms $Platforms)
    }
}

function Get-SuxiosDispatcherScopeHash {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 2147483647)]
        [int]$HotelId,

        [Parameter(Mandatory = $true)]
        [int[]]$SourceIds,

        [Parameter(Mandatory = $true)]
        [string[]]$Platforms
    )

    return Get-SuxiosStableSha256 -Value (New-SuxiosDispatcherScopeDescriptor @PSBoundParameters)
}

function Get-SuxiosDispatcherEffectiveConfigHash {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('Daily', 'Realtime')]
        [string]$Mode,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$Timezone,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$TargetDate,

        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 2147483647)]
        [int]$HotelId,

        [Parameter(Mandatory = $true)]
        [int[]]$SourceIds,

        [Parameter(Mandatory = $true)]
        [string[]]$Platforms,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$PhpPath,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$ThinkPath
    )

    Assert-SuxiosBusinessDate -TargetDate $TargetDate
    $descriptor = [ordered]@{
        schema_version = 1
        mode = $Mode.ToLowerInvariant()
        timezone = $Timezone.Trim()
        target_date = $TargetDate
        scope = New-SuxiosDispatcherScopeDescriptor -HotelId $HotelId -SourceIds $SourceIds -Platforms $Platforms
        php_path_identity = ConvertTo-SuxiosPathIdentity -Path $PhpPath
        think_path_identity = ConvertTo-SuxiosPathIdentity -Path $ThinkPath
    }
    return Get-SuxiosStableSha256 -Value $descriptor
}

function Get-SuxiosContractValue {
    param(
        [Parameter(Mandatory = $true)][AllowNull()][object]$Contract,
        [Parameter(Mandatory = $true)][string]$Name
    )

    if ($null -eq $Contract) {
        return $null
    }
    if ($Contract -is [System.Collections.IDictionary]) {
        foreach ($key in $Contract.Keys) {
            if ([string]::Equals([string]$key, $Name, [System.StringComparison]::OrdinalIgnoreCase)) {
                return $Contract[$key]
            }
        }
        return $null
    }
    foreach ($property in $Contract.PSObject.Properties) {
        if ([string]::Equals([string]$property.Name, $Name, [System.StringComparison]::OrdinalIgnoreCase)) {
            return $property.Value
        }
    }
    return $null
}

function ConvertTo-SuxiosContractBoolean {
    param([AllowNull()][object]$Value)

    if ($null -eq $Value) {
        return $null
    }
    if ($Value -is [bool]) {
        return $Value
    }
    $normalized = ([string]$Value).Trim().ToLowerInvariant()
    if ($normalized -in @('true', '1')) {
        return $true
    }
    if ($normalized -in @('false', '0')) {
        return $false
    }
    throw 'Dispatcher task contract contains an invalid Boolean value.'
}

function Get-SuxiosDispatcherTaskContractHash {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateNotNull()]
        [object]$TaskContract
    )

    $actionArguments = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'action_arguments')
    $credentialPattern = '(?i)(--?(cookie|token|password|authorization|spidertoken|secret|session|credential)\b|(?:cookie|token|password|authorization|spidertoken|secret|session|credential)\s*=)'
    if ($actionArguments -match $credentialPattern) {
        throw 'Dispatcher task contract contains forbidden credential-shaped arguments.'
    }

    $descriptor = [ordered]@{
        schema_version = 1
        task_name = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'task_name')
        task_path = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'task_path')
        action_execute = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'action_execute')
        action_arguments = $actionArguments
        working_directory = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'working_directory')
        trigger_start_boundary = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'trigger_start_boundary')
        trigger_interval = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'trigger_interval')
        trigger_duration = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'trigger_duration')
        principal_logon_type = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'principal_logon_type')
        principal_run_level = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'principal_run_level')
        multiple_instances = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'multiple_instances')
        start_when_available = ConvertTo-SuxiosContractBoolean -Value (Get-SuxiosContractValue -Contract $TaskContract -Name 'start_when_available')
        wake_to_run = ConvertTo-SuxiosContractBoolean -Value (Get-SuxiosContractValue -Contract $TaskContract -Name 'wake_to_run')
        execution_time_limit = [string](Get-SuxiosContractValue -Contract $TaskContract -Name 'execution_time_limit')
    }
    return Get-SuxiosStableSha256 -Value $descriptor
}

function Get-SuxiosDispatcherCodeManifest {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$ProjectRoot,

        [string[]]$Extensions = @('.php', '.ps1', '.mjs', '.js'),

        [string[]]$ExcludedDirectories = @(
            'runtime', 'storage', 'output', 'reports', 'tests', 'vendor', 'node_modules', '.git'
        )
    )

    # Get-Item expands Windows 8.3 aliases (for example ADMINI~1) while
    # Resolve-Path preserves them. Use the expanded identity so child file
    # FullName values share the same prefix in temporary and real workspaces.
    $resolvedProjectPath = (Resolve-Path -LiteralPath $ProjectRoot -ErrorAction Stop).Path
    $resolvedRoot = (Get-Item -LiteralPath $resolvedProjectPath -Force -ErrorAction Stop).FullName.TrimEnd('\', '/')
    $normalizedExtensions = [string[]]@(
        $Extensions |
            ForEach-Object {
                $extension = ([string]$_).Trim().ToLowerInvariant()
                if ($extension -ne '' -and -not $extension.StartsWith('.')) {
                    $extension = '.' + $extension
                }
                $extension
            } |
            Where-Object { $_ -ne '' } |
            Sort-Object -Unique
    )
    if ($normalizedExtensions.Count -eq 0) {
        throw 'Dispatcher code manifest requires at least one file extension.'
    }
    $normalizedExclusions = [string[]]@(
        $ExcludedDirectories |
            ForEach-Object { ([string]$_).Trim().ToLowerInvariant() } |
            Where-Object { $_ -ne '' } |
            Sort-Object -Unique
    )
    $scanRoots = @('app', 'config', 'route', 'scripts')
    $missingRoots = @()
    $records = @{}

    foreach ($scanRoot in $scanRoots) {
        $scanPath = Join-Path $resolvedRoot $scanRoot
        if (-not (Test-Path -LiteralPath $scanPath -PathType Container)) {
            $missingRoots += $scanRoot
            continue
        }
        foreach ($file in Get-ChildItem -LiteralPath $scanPath -Recurse -File -Force -ErrorAction Stop) {
            # PathIntrinsics resolves Windows 8.3/long-path aliases before
            # calculating the relative name. String-length subtraction can
            # otherwise leak a suffix of the temporary/project root into the
            # manifest and make identical trees hash differently.
            $relativePath = $ExecutionContext.SessionState.Path.NormalizeRelativePath(
                $file.FullName,
                $resolvedRoot
            ).Replace('\', '/')
            if ($relativePath.StartsWith('./')) {
                $relativePath = $relativePath.Substring(2)
            }
            $segments = @($relativePath.Split('/') | ForEach-Object { $_.ToLowerInvariant() })
            if (@($segments | Where-Object { $_ -in $normalizedExclusions }).Count -gt 0) {
                continue
            }
            if ($file.Extension.ToLowerInvariant() -notin $normalizedExtensions) {
                continue
            }
            $records[$relativePath] = [ordered]@{
                path = $relativePath
                size_bytes = [int64]$file.Length
                sha256 = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
            }
        }
    }

    $thinkPath = Join-Path $resolvedRoot 'think'
    if (Test-Path -LiteralPath $thinkPath -PathType Leaf) {
        $thinkFile = Get-Item -LiteralPath $thinkPath -Force -ErrorAction Stop
        $records['think'] = [ordered]@{
            path = 'think'
            size_bytes = [int64]$thinkFile.Length
            sha256 = (Get-FileHash -LiteralPath $thinkFile.FullName -Algorithm SHA256 -ErrorAction Stop).Hash.ToLowerInvariant()
        }
    } else {
        $missingRoots += 'think'
    }

    $paths = [string[]]@($records.Keys)
    [Array]::Sort($paths, [System.StringComparer]::Ordinal)
    $entries = @($paths | ForEach-Object { $records[$_] })
    $descriptor = [ordered]@{
        schema_version = 1
        extensions = @($normalizedExtensions)
        excluded_directories = @($normalizedExclusions)
        scanned_roots = @($scanRoots) + @('think')
        missing_roots = @($missingRoots | Sort-Object -Unique)
        files = $entries
    }
    return [pscustomobject][ordered]@{
        schema_version = 1
        algorithm = 'sha256'
        sha256 = Get-SuxiosStableSha256 -Value $descriptor
        file_count = $entries.Count
        extensions = @($normalizedExtensions)
        excluded_directories = @($normalizedExclusions)
        scanned_roots = @($scanRoots) + @('think')
        missing_roots = @($missingRoots | Sort-Object -Unique)
        files = $entries
    }
}

function Assert-SuxiosSha256 {
    param(
        [Parameter(Mandatory = $true)][string]$Value,
        [Parameter(Mandatory = $true)][string]$FieldName
    )

    if ($Value -notmatch '^[a-fA-F0-9]{64}$') {
        throw "Dispatcher provenance $FieldName must be a SHA-256 digest."
    }
}

function ConvertTo-SuxiosReceiptTime {
    param(
        [Parameter(Mandatory = $true)][string]$Value,
        [Parameter(Mandatory = $true)][string]$FieldName
    )

    [datetimeoffset]$parsed = [datetimeoffset]::MinValue
    if (-not [datetimeoffset]::TryParse(
        $Value,
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.DateTimeStyles]::RoundtripKind,
        [ref]$parsed
    )) {
        throw "Dispatcher provenance $FieldName must be an ISO-8601 timestamp."
    }
    return $parsed.ToString('o', [System.Globalization.CultureInfo]::InvariantCulture)
}

function Resolve-SuxiosDispatcherSchedulerCorrelation {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$TaskName,

        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 2147483647)]
        [int]$EngineProcessId,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$ReferenceTime,

        [Parameter(Mandatory = $true)]
        [string]$TaskState,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$LastRunTime,

        [AllowNull()]
        [object[]]$EventRecords = @(),

        [bool]$EventLogAvailable = $true,

        [switch]$PreflightOnly
    )

    [datetimeoffset]$reference = [datetimeoffset]::MinValue
    [datetimeoffset]$lastRun = [datetimeoffset]::MinValue
    if (-not [datetimeoffset]::TryParse(
        $ReferenceTime,
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.DateTimeStyles]::RoundtripKind,
        [ref]$reference
    ) -or -not [datetimeoffset]::TryParse(
        $LastRunTime,
        [System.Globalization.CultureInfo]::InvariantCulture,
        [System.Globalization.DateTimeStyles]::RoundtripKind,
        [ref]$lastRun
    )) {
        throw 'Dispatcher scheduler correlation timestamps are invalid.'
    }

    $normalizedTaskName = $TaskName.Trim()
    $qualifiedTaskName = '\' + $normalizedTaskName.TrimStart('\')
    $lastRunDelta = [int][Math]::Round([Math]::Abs(($reference - $lastRun).TotalSeconds))
    $result = [ordered]@{
        task_name = $normalizedTaskName
        task_path = '\'
        state = $TaskState.Trim().ToLowerInvariant()
        last_run_time = $lastRun.ToString('o', [System.Globalization.CultureInfo]::InvariantCulture)
        last_run_delta_seconds = $lastRunDelta
        task_instance_id = $null
        engine_process_id = $null
        event_ids = @()
        manual_run_event_absent = $null
        reason = 'exact_task_instance_events_missing'
        status = 'not_correlated'
    }

    if ($PreflightOnly) {
        $result.reason = 'preflight_only'
        return [pscustomobject]$result
    }
    if (-not $EventLogAvailable) {
        $result.reason = 'operational_event_log_unavailable'
        $result.status = 'unavailable'
        return [pscustomobject]$result
    }

    $normalizedEvents = @()
    foreach ($record in @($EventRecords)) {
        $eventId = [int](Get-SuxiosContractValue -Contract $record -Name 'event_id')
        if ($eventId -notin @(100, 107, 110, 129, 200)) {
            continue
        }
        $eventTimeText = [string](Get-SuxiosContractValue -Contract $record -Name 'time_created')
        [datetimeoffset]$eventTime = [datetimeoffset]::MinValue
        if (-not [datetimeoffset]::TryParse(
            $eventTimeText,
            [System.Globalization.CultureInfo]::InvariantCulture,
            [System.Globalization.DateTimeStyles]::RoundtripKind,
            [ref]$eventTime
        )) {
            continue
        }
        $eventInstanceId = [string](Get-SuxiosContractValue -Contract $record -Name 'task_instance_id')
        if ($eventInstanceId -ne '') {
            try {
                $eventInstanceId = ([guid]$eventInstanceId).ToString('D').ToLowerInvariant()
            } catch {
                $eventInstanceId = ''
            }
        }
        $normalizedEvents += [pscustomobject]@{
            event_id = $eventId
            time_created = $eventTime
            task_name = [string](Get-SuxiosContractValue -Contract $record -Name 'task_name')
            task_instance_id = $eventInstanceId
            process_id = [int](Get-SuxiosContractValue -Contract $record -Name 'process_id')
        }
    }

    $actionEvent = @($normalizedEvents | Where-Object {
        $_.event_id -eq 200 -and
        [string]::Equals($_.task_name, $qualifiedTaskName, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.process_id -eq $EngineProcessId -and
        [Math]::Abs(($reference - $_.time_created).TotalSeconds) -le 180
    } | Sort-Object -Property time_created -Descending | Select-Object -First 1)
    if ($actionEvent.Count -ne 1) {
        return [pscustomobject]$result
    }

    try {
        $instanceId = ([guid]$actionEvent[0].task_instance_id).ToString('D').ToLowerInvariant()
    } catch {
        return [pscustomobject]$result
    }
    $actionTime = [datetimeoffset]$actionEvent[0].time_created
    $taskStartMatched = @($normalizedEvents | Where-Object {
        $_.event_id -eq 100 -and
        [string]::Equals($_.task_name, $qualifiedTaskName, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.task_instance_id -ne '' -and
        $_.task_instance_id -eq $instanceId -and
        [Math]::Abs(($actionTime - $_.time_created).TotalSeconds) -le 10
    }).Count -gt 0
    $timeTriggerMatched = @($normalizedEvents | Where-Object {
        $_.event_id -eq 107 -and
        [string]::Equals($_.task_name, $qualifiedTaskName, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.task_instance_id -eq $instanceId -and
        [Math]::Abs(($actionTime - $_.time_created).TotalSeconds) -le 10
    }).Count -gt 0
    $manualRunMatched = @($normalizedEvents | Where-Object {
        $_.event_id -eq 110 -and
        [string]::Equals($_.task_name, $qualifiedTaskName, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.task_instance_id -eq $instanceId -and
        [Math]::Abs(($actionTime - $_.time_created).TotalSeconds) -le 10
    }).Count -gt 0
    $processStartMatched = @($normalizedEvents | Where-Object {
        $_.event_id -eq 129 -and
        [string]::Equals($_.task_name, $qualifiedTaskName, [System.StringComparison]::OrdinalIgnoreCase) -and
        $_.process_id -eq $EngineProcessId -and
        [Math]::Abs(($actionTime - $_.time_created).TotalSeconds) -le 10
    }).Count -gt 0
    if ($manualRunMatched) {
        $result.reason = 'manual_task_run_event_detected'
        return [pscustomobject]$result
    }
    if (-not $timeTriggerMatched) {
        $result.reason = 'scheduled_time_trigger_missing'
        return [pscustomobject]$result
    }
    if (-not $taskStartMatched -or -not $processStartMatched) {
        return [pscustomobject]$result
    }
    if ($TaskState -ne 'Running' -or $lastRunDelta -gt 180) {
        $result.reason = 'task_state_or_last_run_mismatch'
        return [pscustomobject]$result
    }

    $result.task_instance_id = $instanceId
    $result.engine_process_id = $EngineProcessId
    $result.event_ids = @(100, 107, 129, 200)
    $result.manual_run_event_absent = $true
    $result.reason = 'exact_task_instance_events'
    $result.status = 'correlated'
    return [pscustomobject]$result
}

function Select-SuxiosDispatcherTerminalSchedulerCorrelation {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][AllowNull()][object]$StartCorrelation,
        [Parameter(Mandatory = $true)][AllowNull()][object]$FinishCorrelation
    )

    $finishStatus = ([string](Get-SuxiosContractValue -Contract $FinishCorrelation -Name 'status')).Trim().ToLowerInvariant()
    $startStatus = ([string](Get-SuxiosContractValue -Contract $StartCorrelation -Name 'status')).Trim().ToLowerInvariant()
    if ($finishStatus -eq 'correlated') {
        return $FinishCorrelation
    }
    if ($finishStatus -eq 'unavailable' -and $startStatus -eq 'correlated') {
        return $StartCorrelation
    }
    return $FinishCorrelation
}

function New-SuxiosDispatcherProvenanceReceipt {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('start', 'finish')]
        [string]$Phase,

        [Parameter(Mandatory = $true)]
        [guid]$RunId,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$StartedAt,

        [Parameter(Mandatory = $true)]
        [ValidateSet('Daily', 'Realtime')]
        [string]$Mode,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$Timezone,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$TargetDate,

        [Parameter(Mandatory = $true)]
        [ValidateRange(1, 2147483647)]
        [int]$HotelId,

        [Parameter(Mandatory = $true)]
        [int[]]$SourceIds,

        [Parameter(Mandatory = $true)]
        [string[]]$Platforms,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$RunnerSha256,

        [Parameter(Mandatory = $true)]
        [ValidateNotNull()]
        [object]$CodeManifest,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$EffectiveConfigSha256,

        [Parameter(Mandatory = $true)]
        [ValidateNotNullOrEmpty()]
        [string]$TaskContractSha256,

        [AllowNull()]
        [object]$SchedulerCorrelation = $null,

        [ValidateSet('started', 'verified', 'drifted', 'unavailable')]
        [string]$ProvenanceStatus = 'started',

        [string]$FinishedAt = '',

        [AllowNull()]
        [object]$ChildReceiptPresent = $null,

        [AllowNull()]
        [object]$ChildReceiptCount = $null,

        [string]$ChildReceiptSha256 = '',

        [AllowNull()]
        [object]$ChildExitCode = $null,

        [AllowNull()]
        [object]$CodeStableDuringRun = $null
    )

    Assert-SuxiosBusinessDate -TargetDate $TargetDate
    Assert-SuxiosSha256 -Value $RunnerSha256 -FieldName 'runner_sha256'
    Assert-SuxiosSha256 -Value $EffectiveConfigSha256 -FieldName 'effective_config_sha256'
    Assert-SuxiosSha256 -Value $TaskContractSha256 -FieldName 'task_contract_sha256'
    $manifestHash = [string](Get-SuxiosContractValue -Contract $CodeManifest -Name 'sha256')
    $manifestAlgorithm = [string](Get-SuxiosContractValue -Contract $CodeManifest -Name 'algorithm')
    $manifestFileCount = [int](Get-SuxiosContractValue -Contract $CodeManifest -Name 'file_count')
    Assert-SuxiosSha256 -Value $manifestHash -FieldName 'code_manifest.sha256'
    if ($manifestAlgorithm.ToLowerInvariant() -ne 'sha256' -or $manifestFileCount -lt 0) {
        throw 'Dispatcher provenance code manifest summary is invalid.'
    }
    $schedulerStatus = [string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'status')
    if ($schedulerStatus -eq '') {
        $schedulerStatus = 'unavailable'
    }
    $schedulerStatus = $schedulerStatus.Trim().ToLowerInvariant()
    if ($schedulerStatus -notin @('correlated', 'not_correlated', 'unavailable')) {
        throw 'Dispatcher provenance scheduler correlation status is invalid.'
    }
    $lastRunTime = [string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'last_run_time')
    if ($lastRunTime -ne '') {
        $lastRunTime = ConvertTo-SuxiosReceiptTime -Value $lastRunTime -FieldName 'scheduler_correlation.last_run_time'
    }
    $lastRunDelta = Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'last_run_delta_seconds'
    if ($null -ne $lastRunDelta) {
        $lastRunDelta = [int][Math]::Max(0, [int]$lastRunDelta)
    }
    $schedulerReason = ([string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'reason')).Trim().ToLowerInvariant()
    if ($schedulerReason -ne '' -and $schedulerReason -notmatch '^[a-z0-9_]+$') {
        throw 'Dispatcher provenance scheduler correlation reason is invalid.'
    }
    $taskInstanceId = $null
    $engineProcessId = $null
    $eventIds = @()
    $manualRunEventAbsent = $null
    if ($schedulerStatus -eq 'correlated') {
        try {
            $taskInstanceId = ([guid]([string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'task_instance_id'))).ToString('D').ToLowerInvariant()
        } catch {
            throw 'Correlated dispatcher provenance requires a valid task instance id.'
        }
        $engineProcessId = [int](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'engine_process_id')
        $eventIds = @((Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'event_ids') | ForEach-Object { [int]$_ } | Sort-Object -Unique)
        $manualRunEventAbsent = Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'manual_run_event_absent'
        if ($engineProcessId -le 0 -or ($eventIds -join ',') -ne '100,107,129,200' -or
            $manualRunEventAbsent -isnot [bool] -or -not [bool]$manualRunEventAbsent -or
            $schedulerReason -ne 'exact_task_instance_events'
        ) {
            throw 'Correlated dispatcher provenance requires exact Task Scheduler event evidence.'
        }
    }
    $scope = New-SuxiosDispatcherScopeDescriptor -HotelId $HotelId -SourceIds $SourceIds -Platforms $Platforms
    $receipt = [ordered]@{
        schema_version = 1
        receipt_type = 'suxios_ota_dispatcher_provenance'
        phase = $Phase
        run_id = $RunId.ToString('D').ToLowerInvariant()
        started_at = ConvertTo-SuxiosReceiptTime -Value $StartedAt -FieldName 'started_at'
        mode = $Mode.ToLowerInvariant()
        timezone = $Timezone.Trim()
        target_date = $TargetDate
        scope = $scope
        scope_sha256 = Get-SuxiosStableSha256 -Value $scope
        runner_sha256 = $RunnerSha256.ToLowerInvariant()
        code_manifest = [ordered]@{
            algorithm = 'sha256'
            sha256 = $manifestHash.ToLowerInvariant()
            file_count = $manifestFileCount
        }
        effective_config_sha256 = $EffectiveConfigSha256.ToLowerInvariant()
        task_contract_sha256 = $TaskContractSha256.ToLowerInvariant()
        scheduler_correlation = [ordered]@{
            task_name = [string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'task_name')
            task_path = [string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'task_path')
            state = ([string](Get-SuxiosContractValue -Contract $SchedulerCorrelation -Name 'state')).Trim().ToLowerInvariant()
            last_run_time = $(if ($lastRunTime -ne '') { $lastRunTime } else { $null })
            last_run_delta_seconds = $lastRunDelta
            task_instance_id = $taskInstanceId
            engine_process_id = $engineProcessId
            event_ids = @($eventIds)
            manual_run_event_absent = $manualRunEventAbsent
            reason = $(if ($schedulerReason -ne '') { $schedulerReason } else { $null })
            status = $schedulerStatus
        }
        provenance_status = $ProvenanceStatus
        sensitive_values_exposed = $false
    }

    if ($Phase -eq 'finish') {
        if ($FinishedAt -eq '' -or $null -eq $ChildReceiptPresent -or $null -eq $ChildReceiptCount -or
            $null -eq $ChildExitCode -or $null -eq $CodeStableDuringRun
        ) {
            throw 'Dispatcher finish provenance requires terminal fields.'
        }
        if ($ChildReceiptPresent -isnot [bool] -or $CodeStableDuringRun -isnot [bool]) {
            throw 'Dispatcher finish provenance terminal Boolean fields are invalid.'
        }
        if ($ChildReceiptCount -isnot [byte] -and $ChildReceiptCount -isnot [sbyte] -and
            $ChildReceiptCount -isnot [int16] -and $ChildReceiptCount -isnot [uint16] -and
            $ChildReceiptCount -isnot [int32] -and $ChildReceiptCount -isnot [uint32] -and
            $ChildReceiptCount -isnot [int64] -and $ChildReceiptCount -isnot [uint64]
        ) {
            throw 'Dispatcher finish provenance child receipt count is invalid.'
        }
        if ([int]$ChildReceiptCount -lt 0 -or
            ([bool]$ChildReceiptPresent -ne ([int]$ChildReceiptCount -gt 0))
        ) {
            throw 'Dispatcher finish provenance child receipt presence/count is inconsistent.'
        }
        if ($ChildExitCode -isnot [byte] -and $ChildExitCode -isnot [sbyte] -and
            $ChildExitCode -isnot [int16] -and $ChildExitCode -isnot [uint16] -and
            $ChildExitCode -isnot [int32] -and $ChildExitCode -isnot [uint32] -and
            $ChildExitCode -isnot [int64] -and $ChildExitCode -isnot [uint64]
        ) {
            throw 'Dispatcher finish provenance child exit code is invalid.'
        }
        if ([bool]$ChildReceiptPresent) {
            Assert-SuxiosSha256 -Value $ChildReceiptSha256 -FieldName 'child_receipt_sha256'
        } elseif ($ChildReceiptSha256 -ne '') {
            throw 'Dispatcher provenance cannot attach a child receipt hash when no receipt was observed.'
        }
        if ($ProvenanceStatus -eq 'started') {
            throw 'Dispatcher finish provenance requires a terminal provenance status.'
        }
        if ($ProvenanceStatus -eq 'verified' -and
            ($schedulerStatus -ne 'correlated' -or -not [bool]$CodeStableDuringRun)
        ) {
            throw 'Verified dispatcher provenance requires correlated scheduling and stable code.'
        }
        $receipt['finished_at'] = ConvertTo-SuxiosReceiptTime -Value $FinishedAt -FieldName 'finished_at'
        $receipt['child_receipt_present'] = [bool]$ChildReceiptPresent
        $receipt['child_receipt_count'] = [int]$ChildReceiptCount
        $receipt['child_receipt_sha256'] = $(if ([bool]$ChildReceiptPresent) {
            $ChildReceiptSha256.ToLowerInvariant()
        } else {
            $null
        })
        $receipt['child_exit_code'] = [int]$ChildExitCode
        $receipt['code_stable_during_run'] = [bool]$CodeStableDuringRun
        $naturalRunReady = $ProvenanceStatus -eq 'verified' -and
            $schedulerStatus -eq 'correlated' -and
            [bool]$ChildReceiptPresent -and
            [int]$ChildReceiptCount -eq 1 -and
            [int]$ChildExitCode -eq 0 -and
            [bool]$CodeStableDuringRun
        $naturalRunReason = if ($naturalRunReady) {
            'verified'
        } elseif ($ProvenanceStatus -ne 'verified') {
            'provenance_' + $ProvenanceStatus
        } elseif (-not [bool]$ChildReceiptPresent) {
            'child_receipt_missing'
        } elseif ([int]$ChildReceiptCount -ne 1) {
            'child_receipt_ambiguous'
        } elseif ([int]$ChildExitCode -ne 0) {
            'child_exit_nonzero'
        } elseif (-not [bool]$CodeStableDuringRun) {
            'code_drifted'
        } else {
            'scheduler_not_correlated'
        }
        $receipt['natural_run_ready'] = [bool]$naturalRunReady
        $receipt['natural_run_reason'] = $naturalRunReason
    } elseif ($ProvenanceStatus -ne 'started') {
        throw 'Dispatcher start provenance status must be started.'
    }

    return ConvertTo-SuxiosCanonicalJson -Value $receipt
}
