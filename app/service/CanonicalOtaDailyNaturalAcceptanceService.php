<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Read-only acceptance for one naturally scheduled historical OTA day.
 *
 * Dispatcher logs prove the Windows trigger. Database adapters prove that the
 * exact source tasks, rows and four analysis-only operation triplets still
 * read back. The service never starts collection or writes business state.
 */
final class CanonicalOtaDailyNaturalAcceptanceService
{
    public const SCHEMA_VERSION = 'suxios_ota_daily_natural_acceptance.v1';
    public const LINE_PREFIX = 'SUXIOS_OTA_DAILY_ACCEPTANCE=';
    public const REQUIRED_STABLE_DAYS = 3;

    private const DISPATCHER_RECEIPT_TYPE = 'suxios_ota_dispatcher_provenance';
    private const DATA_PERIOD = 'historical_daily';
    private const OPERATION_SCHEMA = 'canonical_ota_daily_operation_finalization.v2';
    private const ALLOWED_PLATFORMS = ['ctrip', 'meituan'];

    /** @var Closure(int):int */
    private Closure $tenantResolver;

    /** @var Closure(array<string,mixed>,string,int,array<int,int>,array<int,string>):bool */
    private Closure $dailyTrustValidator;

    /** @var Closure(int,string,string):array<string,mixed> */
    private Closure $continuousTrustInspector;

    /** @var Closure(int,int,string,string):array<string,mixed> */
    private Closure $ownerResolver;

    /** @var Closure(int,int,string,array<string,mixed>):array<string,mixed> */
    private Closure $taskFactResolver;

    /** @var Closure():DateTimeImmutable */
    private Closure $nowResolver;

    public function __construct(
        ?callable $tenantResolver = null,
        ?callable $dailyTrustValidator = null,
        ?callable $continuousTrustInspector = null,
        ?callable $ownerResolver = null,
        ?callable $taskFactResolver = null,
        ?callable $nowResolver = null
    ) {
        $this->tenantResolver = $tenantResolver !== null
            ? Closure::fromCallable($tenantResolver)
            : fn(int $hotelId): int => $this->resolveTenantId($hotelId);
        $this->dailyTrustValidator = $dailyTrustValidator !== null
            ? Closure::fromCallable($dailyTrustValidator)
            : static fn(
                array $receipt,
                string $date,
                int $hotelId,
                array $sourceIds,
                array $platforms
            ): bool => (new ScheduledAutoFetchPolicy())->dailyTrustReceiptReady(
                $receipt,
                $date,
                $hotelId,
                $sourceIds,
                $platforms
            );
        $this->continuousTrustInspector = $continuousTrustInspector !== null
            ? Closure::fromCallable($continuousTrustInspector)
            : static fn(int $hotelId, string $startDate, string $endDate): array =>
                (new DualOtaContinuousTrustService())->inspectHotel($hotelId, $startDate, $endDate);
        $this->ownerResolver = $ownerResolver !== null
            ? Closure::fromCallable($ownerResolver)
            : static fn(int $tenantId, int $hotelId, string $date, string $period): array =>
                (new CanonicalOtaDailyPlatformSelectionService())->resolve(
                    $tenantId,
                    $hotelId,
                    $date,
                    $period
                );
        $this->taskFactResolver = $taskFactResolver !== null
            ? Closure::fromCallable($taskFactResolver)
            : fn(int $tenantId, int $hotelId, string $date, array $task): array =>
                $this->loadTaskFact($tenantId, $hotelId, $date, $task);
        $this->nowResolver = $nowResolver !== null
            ? Closure::fromCallable($nowResolver)
            : static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Shanghai')
            );
    }

    /**
     * @param array<int,int> $expectedSourceIds
     * @param array<int,string> $expectedPlatforms
     * @return array<string,mixed>
     */
    public function inspect(
        int $hotelId,
        string $targetDate,
        array $expectedSourceIds,
        array $expectedPlatforms,
        string $dispatcherLogPath,
        string $dispatcherLogDirectory
    ): array {
        $scope = $this->normalizeScope(
            $hotelId,
            $targetDate,
            $expectedSourceIds,
            $expectedPlatforms
        );
        $tenantId = ($this->tenantResolver)($scope['hotel_id']);
        if ($tenantId <= 0) {
            return $this->blockedReceipt($scope, 0, 'daily_acceptance_tenant_scope_invalid');
        }
        $scope['tenant_id'] = $tenantId;

        try {
            $logDirectory = $this->resolvedLogDirectory($dispatcherLogDirectory);
            $parsed = $this->parseDispatcherLog(
                $this->resolvedLogPath($dispatcherLogPath, $logDirectory)
            );
        } catch (\Throwable $exception) {
            return $this->blockedReceipt(
                $scope,
                $tenantId,
                $this->safeReason($exception, 'dispatcher_log_scope_invalid')
            );
        }

        $reasons = [];
        $natural = $this->evaluateCurrentNaturalRun($parsed, $scope, $reasons);
        $child = is_array($parsed['child_receipt'] ?? null) ? $parsed['child_receipt'] : [];
        $origins = $this->loadNaturalOrigins($logDirectory, $scope);

        $collection = $this->evaluateCollection(
            $child,
            $scope,
            $origins,
            $reasons
        );
        $continuous = $this->evaluateContinuousTrust($scope, $collection, $reasons);
        $operations = $this->evaluateOperations(
            $child,
            $scope,
            $collection,
            $reasons
        );

        $verified = ($natural['status'] ?? '') === 'verified'
            && ($collection['status'] ?? '') === 'verified'
            && ($continuous['status'] ?? '') === 'verified'
            && ($operations['status'] ?? '') === 'verified';
        $reasons = array_values(array_unique(array_filter($reasons)));

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $verified ? 'verified' : 'blocked',
            'reason_codes' => $verified ? [] : $reasons,
            'tenant_id' => $tenantId,
            'hotel_id' => $scope['hotel_id'],
            'target_date' => $scope['target_date'],
            'service_date' => $this->serviceDate($parsed['finish']['finished_at'] ?? ''),
            'data_period' => self::DATA_PERIOD,
            'expected_source_ids' => $scope['source_ids'],
            'expected_platforms' => $scope['platforms'],
            'pipeline_contract_digest' => $this->pipelineContractDigest(),
            'natural_dispatch' => $natural,
            'collection' => $collection,
            'continuous_trust' => $continuous,
            'operations' => $operations,
            'collection_triggered_by_acceptance' => false,
            'business_data_written_by_acceptance' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'sensitive_values_exposed' => false,
        ];
        $receipt['stability'] = $this->buildStability($receipt, $logDirectory, $scope);
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /**
     * Return the newest digest-verified natural acceptance receipt for one
     * hotel. This is a read-only UI projection: it never starts collection,
     * mutates cache/DB state, or treats a prior collection result as current
     * natural evidence.
     *
     * @return array<string,mixed>
     */
    public function latestStoredStatus(int $hotelId, string $dispatcherLogDirectory): array
    {
        $expectedTargetDate = $this->expectedTargetDate();
        if ($hotelId <= 0) {
            return $this->emptyStoredStatus(0, 'natural_acceptance_hotel_scope_missing');
        }
        if ($expectedTargetDate === '') {
            return $this->emptyStoredStatus($hotelId, 'natural_acceptance_clock_unavailable');
        }

        try {
            $logDirectory = $this->resolvedLogDirectory($dispatcherLogDirectory);
        } catch (\Throwable) {
            return $this->emptyStoredStatus($hotelId, 'natural_acceptance_log_unavailable');
        }

        $paths = glob($logDirectory . DIRECTORY_SEPARATOR . 'ota_dispatcher_*.log') ?: [];
        rsort($paths, SORT_STRING);
        $orderedPaths = $this->storedStatusPathOrder(
            $paths,
            $hotelId
        );
        if (($orderedPaths['ambiguous'] ?? false) === true) {
            return $this->invalidStoredStatus(
                $hotelId,
                $expectedTargetDate,
                'dispatcher_latest_attempt_ambiguous',
                $expectedTargetDate
            );
        }
        foreach ($orderedPaths['paths'] ?? [] as $path) {
            if (!is_file($path)
                || is_link($path)
                || (int)filesize($path) > 16 * 1024 * 1024
                || preg_match(
                    '/^ota_dispatcher_[0-9]{8}_[0-9]{6}_[a-f0-9]{32}\.log$/D',
                    basename($path)
                ) !== 1
            ) {
                continue;
            }

            $starts = [];
            $finishes = [];
            $preflightOnly = false;
            $databasePreflightBlocked = false;
            $provenanceLineCount = 0;
            $invalidProvenanceLineCount = 0;
            $provenanceHotelIds = [];
            $scopeMarkerHotelId = 0;
            $targetDateMarker = '';
            $acceptanceLineCount = 0;
            $acceptanceReceipts = [];
            $readbackMarkers = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                    $provenanceLineCount++;
                    $provenance = json_decode(substr(
                        $line,
                        strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
                    ), true);
                    $provenanceScope = is_array($provenance['scope'] ?? null)
                        ? $provenance['scope']
                        : [];
                    $provenanceHotelId = (int)($provenanceScope['hotel_id'] ?? 0);
                    if ($provenanceHotelId > 0) {
                        $provenanceHotelIds[] = $provenanceHotelId;
                    }
                    $provenancePhase = is_array($provenance)
                        ? strtolower(trim((string)($provenance['phase'] ?? '')))
                        : '';
                    if (is_array($provenance) && $provenancePhase === 'start') {
                        $starts[] = $provenance;
                        $correlation = is_array($provenance['scheduler_correlation'] ?? null)
                            ? $provenance['scheduler_correlation']
                            : [];
                        $preflightOnly = $preflightOnly
                            || strtolower(trim((string)($correlation['reason'] ?? '')))
                                === 'preflight_only';
                    } elseif (is_array($provenance) && $provenancePhase === 'finish') {
                        $finishes[] = $provenance;
                    } else {
                        $invalidProvenanceLineCount++;
                    }
                }
                if (preg_match(
                    '/^dispatcher_preflight_result=blocked;reason=(?:database_runtime_unavailable|database_schema_upgrade_required);ota_collection_started=false$/D',
                    $line
                ) === 1) {
                    $databasePreflightBlocked = true;
                }
                if (preg_match('/^dispatcher_scope=hotel:([0-9]+);/D', $line, $match) === 1) {
                    $scopeMarkerHotelId = (int)$match[1];
                }
                if (preg_match(
                    '/^dispatcher_target_date=([0-9]{4}-[0-9]{2}-[0-9]{2});/D',
                    $line,
                    $match
                ) === 1) {
                    $targetDateMarker = $this->validDate($match[1]) ? $match[1] : '';
                }
                if (str_starts_with($line, 'dispatcher_run_mode=preflight_only;')) {
                    $preflightOnly = true;
                }
                if (!str_starts_with($line, self::LINE_PREFIX)) {
                    if (str_starts_with($line, 'dispatcher_daily_acceptance_readback_verified=')) {
                        $readbackMarkers[] = $line;
                    }
                    continue;
                }
                $acceptanceLineCount++;
                $decoded = json_decode(substr($line, strlen(self::LINE_PREFIX)), true);
                if (is_array($decoded)) {
                    $acceptanceReceipts[] = $decoded;
                }
            }

            $provenanceHotelIds = $this->positiveIds($provenanceHotelIds);
            $currentHotelAttempt = $scopeMarkerHotelId === $hotelId
                || in_array($hotelId, $provenanceHotelIds, true);
            if ($currentHotelAttempt
                && (count($starts) > 1
                    || count($finishes) > 1
                    || $provenanceLineCount > 2)
            ) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDateMarker,
                    'dispatcher_provenance_ambiguous'
                );
            }
            if ($currentHotelAttempt && count($starts) !== 1) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDateMarker,
                    'dispatcher_start_receipt_invalid'
                );
            }
            if ($currentHotelAttempt && count($finishes) !== 1) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDateMarker,
                    'dispatcher_finish_receipt_missing'
                );
            }
            if ($currentHotelAttempt
                && ($provenanceLineCount !== 2 || $invalidProvenanceLineCount !== 0)
            ) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDateMarker,
                    'dispatcher_provenance_ambiguous'
                );
            }
            if ($preflightOnly) {
                continue;
            }
            $start = count($starts) === 1 ? $starts[0] : [];
            $finish = count($finishes) === 1 ? $finishes[0] : [];
            $startScope = is_array($start['scope'] ?? null) ? $start['scope'] : [];
            if ($start === [] && $scopeMarkerHotelId === $hotelId) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDateMarker,
                    'dispatcher_start_receipt_invalid'
                );
            }
            $isHotelDailyAttempt = $start !== []
                && strtolower(trim((string)($start['mode'] ?? ''))) === 'daily'
                && (int)($startScope['hotel_id'] ?? 0) === $hotelId;
            if (!$isHotelDailyAttempt) {
                if (strtolower(trim((string)($start['mode'] ?? ''))) === 'daily'
                    && $scopeMarkerHotelId === $hotelId
                ) {
                    return $this->invalidStoredStatus(
                        $hotelId,
                        $targetDateMarker,
                        'dispatcher_start_scope_mismatch'
                    );
                }
                continue;
            }
            $attemptTargetDate = $this->validDate((string)($start['target_date'] ?? ''))
                ? (string)$start['target_date']
                : '';
            if ($attemptTargetDate !== $expectedTargetDate) {
                return $this->staleStoredStatus(
                    $hotelId,
                    $expectedTargetDate,
                    $attemptTargetDate
                );
            }
            $attemptScope = [
                'hotel_id' => $hotelId,
                'target_date' => $attemptTargetDate,
                'source_ids' => $this->positiveIds($startScope['source_ids'] ?? []),
                'platforms' => $this->platforms($startScope['platforms'] ?? []),
            ];
            if ($finish === []) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'dispatcher_finish_receipt_missing'
                );
            }
            $startRunId = strtolower(trim((string)($start['run_id'] ?? '')));
            $finishRunId = strtolower(trim((string)($finish['run_id'] ?? '')));
            if (count($attemptScope['source_ids']) !== count(self::ALLOWED_PLATFORMS)
                || $attemptScope['platforms'] !== self::ALLOWED_PLATFORMS
                || !$this->dispatcherReceiptScopeMatches($start, $attemptScope, 'start')
                || !$this->dispatcherReceiptScopeMatches($finish, $attemptScope, 'finish')
                || !$this->uuid($startRunId)
                || $finishRunId !== $startRunId
            ) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'dispatcher_run_scope_mismatch'
                );
            }
            if (!$this->dispatcherReceiptTimelineReady($start, $finish)) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'dispatcher_provenance_time_invalid',
                    $expectedTargetDate
                );
            }
            if ($databasePreflightBlocked) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'dispatcher_database_preflight_blocked'
                );
            }
            if ($acceptanceLineCount === 1 && $acceptanceReceipts === []) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_receipt_invalid'
                );
            }
            if ($acceptanceLineCount === 0) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_receipt_missing_for_latest_attempt'
                );
            }
            if ($acceptanceLineCount !== 1 || count($acceptanceReceipts) !== 1) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_receipt_ambiguous'
                );
            }

            $receipt = $acceptanceReceipts[0];
            $receiptNatural = is_array($receipt['natural_dispatch'] ?? null)
                ? $receipt['natural_dispatch']
                : [];
            if ((int)($receipt['hotel_id'] ?? 0) !== $hotelId
                || (string)($receipt['target_date'] ?? '') !== $attemptTargetDate
                || $this->positiveIds($receipt['expected_source_ids'] ?? [])
                    !== $attemptScope['source_ids']
                || $this->platforms($receipt['expected_platforms'] ?? [])
                    !== $attemptScope['platforms']
            ) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_receipt_scope_mismatch'
                );
            }
            if (strtolower(trim((string)($receiptNatural['run_id'] ?? ''))) !== $startRunId) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_dispatcher_run_mismatch'
                );
            }
            if (count($readbackMarkers) !== 1
                || $readbackMarkers[0]
                    !== 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
            ) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $attemptTargetDate,
                    'daily_acceptance_readback_unverified'
                );
            }

            $targetDate = $this->validDate((string)($receipt['target_date'] ?? ''))
                ? (string)$receipt['target_date']
                : '';
            if (!$this->storedAcceptanceDigestValid($receipt)) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDate,
                    'daily_acceptance_digest_invalid'
                );
            }
            if (!$this->digestEquals(
                (string)($receipt['pipeline_contract_digest'] ?? ''),
                $this->pipelineContractDigest()
            )) {
                return $this->invalidStoredStatus(
                    $hotelId,
                    $targetDate,
                    'pipeline_contract_changed'
                );
            }
            return $this->safeStoredStatus($receipt, $hotelId, $expectedTargetDate);
        }

        return $this->emptyStoredStatus(
            $hotelId,
            'natural_dispatch_receipt_missing',
            $expectedTargetDate
        );
    }

    /**
     * @param array<string,mixed> $parsed
     * @param array<string,mixed> $scope
     * @param array<int,string> $reasons
     * @return array<string,mixed>
     */
    private function evaluateCurrentNaturalRun(array $parsed, array $scope, array &$reasons): array
    {
        $start = is_array($parsed['start'] ?? null) ? $parsed['start'] : [];
        $finish = is_array($parsed['finish'] ?? null) ? $parsed['finish'] : [];
        $runId = strtolower(trim((string)($finish['run_id'] ?? '')));
        $childLine = (string)($parsed['child_line'] ?? '');
        $child = is_array($parsed['child_receipt'] ?? null) ? $parsed['child_receipt'] : [];
        $reason = '';

        if (!$this->dispatcherReceiptScopeMatches($start, $scope, 'start')
            || !$this->dispatcherReceiptScopeMatches($finish, $scope, 'finish')
            || (string)($start['run_id'] ?? '') !== $runId
        ) {
            $reason = 'dispatcher_scope_mismatch';
        } elseif (!$this->dispatcherReceiptTimelineReady($start, $finish)) {
            $reason = 'dispatcher_provenance_time_invalid';
        } elseif (!$this->uuid($runId)) {
            $reason = 'dispatcher_run_id_invalid';
        } elseif (strtolower(trim((string)($finish['provenance_status'] ?? ''))) !== 'verified'
            || ($finish['code_stable_during_run'] ?? false) !== true
        ) {
            $reason = 'dispatcher_code_or_task_drift';
        } elseif (!$this->schedulerCorrelationReady(
            $finish['scheduler_correlation'] ?? null,
            $scope['hotel_id']
        )) {
            $reason = 'natural_scheduler_not_correlated';
        } elseif (($finish['child_receipt_present'] ?? false) !== true
            || (int)($finish['child_receipt_count'] ?? 0) !== 1
            || $childLine === ''
        ) {
            $reason = 'child_receipt_ambiguous';
        } elseif ((int)($finish['child_exit_code'] ?? -1) !== 0
            || (int)($parsed['terminal_exit_code'] ?? -1) !== 0
        ) {
            $reason = 'dispatcher_child_exit_nonzero';
        } elseif (($finish['natural_run_ready'] ?? false) !== true
            || strtolower(trim((string)($finish['natural_run_reason'] ?? ''))) !== 'verified'
        ) {
            $reason = 'natural_dispatch_not_ready';
        } elseif (!$this->digestEquals(
            $this->childLineDigest($childLine),
            (string)($finish['child_receipt_sha256'] ?? '')
        )) {
            $reason = 'child_receipt_hash_mismatch';
        } elseif (strtolower(trim((string)($child['dispatcher_run_id'] ?? ''))) !== $runId) {
            $reason = 'child_dispatcher_run_id_mismatch';
        }

        if ($reason !== '') {
            $reasons[] = $reason;
        }
        $correlation = is_array($finish['scheduler_correlation'] ?? null)
            ? $finish['scheduler_correlation']
            : [];
        return [
            'status' => $reason === '' ? 'verified' : 'blocked',
            'reason' => $reason,
            'run_id' => $this->uuid($runId) ? $runId : '',
            'task_name' => $this->safeTaskName($correlation['task_name'] ?? ''),
            'task_instance_id' => $this->uuid((string)($correlation['task_instance_id'] ?? ''))
                ? strtolower((string)$correlation['task_instance_id'])
                : '',
            'provenance_status' => strtolower(trim((string)($finish['provenance_status'] ?? ''))),
            'natural_run_ready' => ($finish['natural_run_ready'] ?? false) === true,
            'child_receipt_sha256' => $this->safeDigest($finish['child_receipt_sha256'] ?? ''),
            'child_exit_code' => (int)($finish['child_exit_code'] ?? -1),
            'code_manifest_sha256' => $this->safeDigest($finish['code_manifest']['sha256'] ?? ''),
            'task_contract_sha256' => $this->safeDigest($finish['task_contract_sha256'] ?? ''),
            'started_at' => $this->safeDateTime($finish['started_at'] ?? ''),
            'finished_at' => $this->safeDateTime($finish['finished_at'] ?? ''),
            'manual_run_event_absent' => ($correlation['manual_run_event_absent'] ?? false) === true,
        ];
    }

    /**
     * @param array<string,mixed> $child
     * @param array<string,mixed> $scope
     * @param array<string,array<string,mixed>> $origins
     * @param array<int,string> $reasons
     * @return array<string,mixed>
     */
    private function evaluateCollection(
        array $child,
        array $scope,
        array $origins,
        array &$reasons
    ): array {
        $verifier = is_array($child['authority_verifier'] ?? null)
            ? $child['authority_verifier']
            : [];
        $normalizedAnchorTasks = OtaCollectionAnchorService::normalize(
            $child['source_tasks'] ?? []
        );
        $computedAnchorHash = OtaCollectionAnchorService::hash($normalizedAnchorTasks);
        $storedAnchorHash = $this->safeDigest($child['collection_anchor_hash'] ?? '');
        $verifierAnchorHash = $this->safeDigest($verifier['collection_anchor_hash'] ?? '');
        $anchorReady = count($normalizedAnchorTasks) === count($scope['platforms'])
            && (string)($child['collection_anchor_contract_version'] ?? '')
                === OtaCollectionAnchorService::CONTRACT_VERSION
            && $storedAnchorHash !== ''
            && $verifierAnchorHash !== ''
            && hash_equals($computedAnchorHash, $storedAnchorHash)
            && hash_equals($computedAnchorHash, $verifierAnchorHash);
        if (!$anchorReady) {
            $reasons[] = 'collection_anchor_mismatch';
        }
        $childScopeReady = (int)($child['schema_version'] ?? 0) === 3
            && (int)($child['hotel_id'] ?? 0) === $scope['hotel_id']
            && (string)($child['target_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($child['data_period'] ?? ''))) === self::DATA_PERIOD
            && $this->positiveIds($child['source_ids'] ?? []) === $scope['source_ids']
            && $this->platforms($child['required_platforms'] ?? []) === $scope['platforms']
            && ($child['authority_verifier_required'] ?? false) === true;
        $ready = $childScopeReady && $anchorReady && ($this->dailyTrustValidator)(
            $child,
            $scope['target_date'],
            $scope['hotel_id'],
            $scope['source_ids'],
            $scope['platforms']
        );
        if (!$ready) {
            $reasons[] = 'daily_trust_receipt_not_ready';
        }

        $tasks = [];
        $seenSources = [];
        $seenPlatforms = [];
        $rawSourceTasks = is_array($child['source_tasks'] ?? null) ? $child['source_tasks'] : [];
        $sourceTaskScopeAmbiguous = count($rawSourceTasks) !== count($scope['platforms']);
        foreach ($rawSourceTasks as $task) {
            if (!is_array($task)) {
                $sourceTaskScopeAmbiguous = true;
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            $sourceId = (int)($task['data_source_id'] ?? 0);
            if (!in_array($platform, $scope['platforms'], true)
                || !in_array($sourceId, $scope['source_ids'], true)
                || isset($seenSources[$sourceId])
                || isset($seenPlatforms[$platform])
            ) {
                $reasons[] = 'source_task_scope_ambiguous';
                $sourceTaskScopeAmbiguous = true;
                continue;
            }
            $seenSources[$sourceId] = true;
            $seenPlatforms[$platform] = true;
            try {
                $fact = ($this->taskFactResolver)(
                    $scope['tenant_id'],
                    $scope['hotel_id'],
                    $scope['target_date'],
                    $task
                );
                $safeFact = $this->assertTaskFact($task, $fact, $scope, $origins);
                $tasks[$platform] = $safeFact;
            } catch (\Throwable $exception) {
                $reasons[] = $platform . '_' . $this->safeReason(
                    $exception,
                    'source_task_readback_invalid'
                );
            }
        }
        ksort($tasks, SORT_STRING);
        $failedSourceTasks = $childScopeReady
            ? $this->safeFailedSourceTasks(
                $child['failed_source_tasks'] ?? [],
                $scope,
                $tasks,
                (string)($child['dispatcher_run_id'] ?? '')
            )
            : [];
        $complete = !$sourceTaskScopeAmbiguous
            && array_keys($tasks) === $scope['platforms'];
        if ($sourceTaskScopeAmbiguous) {
            $reasons[] = 'source_task_scope_ambiguous';
        }
        if (!$complete) {
            foreach ($scope['platforms'] as $platform) {
                if (!isset($tasks[$platform])) {
                    if (isset($failedSourceTasks[$platform])) {
                        $reasons[] = $platform . '_'
                            . $failedSourceTasks[$platform]['failure_reason'];
                    }
                    $reasons[] = $platform . '_source_task_missing';
                }
            }
        }

        return [
            'status' => $ready && $complete ? 'verified' : 'blocked',
            'daily_trust_receipt_ready' => $ready,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $storedAnchorHash,
            'source_tasks' => $tasks,
            'source_task_count' => count($tasks),
            'failed_source_tasks' => array_values($failedSourceTasks),
            'failed_source_task_count' => count($failedSourceTasks),
            'saved_and_readback_verified' => $complete,
            'p0' => [
                'status' => strtolower(trim((string)($verifier['status'] ?? ''))),
                'authority_ready' => ($verifier['authority_ready'] ?? false) === true,
                'verified_platforms' => $this->platforms($verifier['verified_platforms'] ?? []),
                'p0_platforms_ready' => max(0, (int)($verifier['p0_platforms_ready'] ?? 0)),
                'traffic_gates_ready' => max(0, (int)($verifier['traffic_gates_ready'] ?? 0)),
                'observed_provenance_status' => strtolower(trim((string)(
                    $verifier['observed_traffic_metric_provenance_status'] ?? ''
                ))),
                'collection_anchor_hash' => $this->safeDigest(
                    $verifier['collection_anchor_hash'] ?? ''
                ),
                'receipt_digest' => $verifier === [] ? '' : $this->digest($this->safeVerifier($verifier)),
            ],
        ];
    }

    /**
     * Failed task evidence is kept separate from the trusted row anchor. Only
     * the current dispatcher run's bounded codes may reach user-visible
     * acceptance reasons; free-form messages and stale/cross-scope tasks are
     * discarded.
     *
     * @param array<string,mixed> $scope
     * @param array<string,array<string,mixed>> $trustedTasks
     * @return array<string,array<string,mixed>>
     */
    private function safeFailedSourceTasks(
        mixed $values,
        array $scope,
        array $trustedTasks,
        string $dispatcherRunId
    ): array {
        if (!is_array($values) || !$this->uuid($dispatcherRunId)) {
            return [];
        }
        $dispatcherRunId = strtolower(trim($dispatcherRunId));
        $failures = [];
        $seenSources = [];
        foreach (array_slice($values, 0, count($scope['platforms'])) as $value) {
            if (!is_array($value)) {
                continue;
            }
            $platform = strtolower(trim((string)($value['platform'] ?? '')));
            $sourceId = (int)($value['data_source_id'] ?? 0);
            $taskId = (int)($value['sync_task_id'] ?? 0);
            $ingestionMethod = strtolower(trim((string)($value['ingestion_method'] ?? '')));
            $localCollectorTaskId = max(0, (int)($value['local_collector_task_id'] ?? 0));
            $executionOwnerUserId = max(0, (int)($value['execution_owner_user_id'] ?? 0));
            $targetDate = substr(trim((string)($value['target_date'] ?? '')), 0, 10);
            $failureRunId = strtolower(trim((string)($value['dispatcher_run_id'] ?? '')));
            $status = strtolower(trim((string)($value['status'] ?? '')));
            $reason = strtolower(trim((string)($value['failure_reason'] ?? '')));
            $historicalCoreStatus = strtolower(trim((string)(
                $value['historical_core_contract_status'] ?? ''
            )));
            $producerIdentityReady = $ingestionMethod === 'local_collector'
                ? $localCollectorTaskId > 0
                : ($ingestionMethod === 'browser_profile'
                    && $localCollectorTaskId === 0
                    && $taskId > 0);
            if (!in_array($platform, $scope['platforms'], true)
                || isset($trustedTasks[$platform])
                || $sourceId <= 0
                || !in_array($sourceId, $scope['source_ids'], true)
                || !$producerIdentityReady
                || $executionOwnerUserId <= 0
                || $targetDate !== $scope['target_date']
                || !$this->uuid($failureRunId)
                || !hash_equals($dispatcherRunId, $failureRunId)
                || !in_array($status, [
                    'failed',
                    'partial_success',
                    'capture_failed',
                    'permission_denied',
                    'cancelled',
                    'device_offline',
                    'waiting_user_login',
                    'verification_required',
                    'queued',
                    'in_progress',
                ], true)
                || preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $reason) !== 1
                || ($value['readback_verified'] ?? true) !== false
                || !in_array($historicalCoreStatus, ['blocked', 'not_ready'], true)
                || isset($failures[$platform])
                || isset($seenSources[$sourceId])
            ) {
                continue;
            }
            $seenSources[$sourceId] = true;
            $failures[$platform] = [
                'data_source_id' => $sourceId,
                'sync_task_id' => $taskId > 0 ? $taskId : null,
                'ingestion_method' => $ingestionMethod,
                'local_collector_task_id' => $localCollectorTaskId > 0
                    ? $localCollectorTaskId
                    : null,
                'execution_owner_user_id' => $executionOwnerUserId,
                'platform' => $platform,
                'target_date' => $targetDate,
                'status' => $status,
                'failure_reason' => $reason,
                'readback_count' => max(0, (int)($value['readback_count'] ?? 0)),
                'readback_verified' => false,
                'historical_core_contract_status' => $historicalCoreStatus,
                'dispatcher_run_id' => $failureRunId,
                'sensitive_values_exposed' => false,
            ];
        }
        ksort($failures, SORT_STRING);
        return $failures;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $collection
     * @param array<int,string> $reasons
     * @return array<string,mixed>
     */
    private function evaluateContinuousTrust(
        array $scope,
        array $collection,
        array &$reasons
    ): array
    {
        try {
            $result = ($this->continuousTrustInspector)(
                $scope['hotel_id'],
                $scope['target_date'],
                $scope['target_date']
            );
        } catch (\Throwable) {
            $reasons[] = 'dual_ota_continuous_trust_unavailable';
            return ['status' => 'blocked', 'acceptance_status' => 'blocked'];
        }
        $days = is_array($result['days'] ?? null) ? $result['days'] : [];
        $day = is_array($days[0] ?? null) ? $days[0] : [];
        $aggregateReady = strtolower(trim((string)($result['status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($result['acceptance_status'] ?? ''))) === 'verified'
            && (int)($result['verified_days'] ?? 0) === 1
            && (int)($result['accepted_days'] ?? 0) === 1
            && count($days) === 1
            && $this->platforms($result['required_platforms'] ?? []) === $scope['platforms']
            && (string)($day['date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($day['status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($day['acceptance_status'] ?? ''))) === 'verified';
        $exactRunClaimTaskMatch = $aggregateReady
            && $this->exactRunClaimTasksMatch($day, $collection, $scope);
        if (!$aggregateReady) {
            $reasons[] = 'dual_ota_continuous_trust_not_ready';
        } elseif (!$exactRunClaimTaskMatch) {
            $reasons[] = 'exact_run_claim_task_mismatch';
        }
        $ready = $aggregateReady && $exactRunClaimTaskMatch;
        return [
            'status' => $ready ? 'verified' : 'blocked',
            'acceptance_status' => strtolower(trim((string)($result['acceptance_status'] ?? ''))),
            'verified_days' => max(0, (int)($result['verified_days'] ?? 0)),
            'accepted_days' => max(0, (int)($result['accepted_days'] ?? 0)),
            'required_platforms' => $this->platforms($result['required_platforms'] ?? []),
            'exact_run_claim_task_match' => $exactRunClaimTaskMatch,
        ];
    }

    /**
     * A same-date continuous-trust claim is evidence for this natural run only
     * when each platform receipt names the exact source task selected by the
     * current child receipt. This still permits a bounded retry task because
     * evaluateCollection() exposes only the child's explicit canonical choice.
     *
     * @param array<string,mixed> $day
     * @param array<string,mixed> $collection
     * @param array<string,mixed> $scope
     */
    private function exactRunClaimTasksMatch(
        array $day,
        array $collection,
        array $scope
    ): bool {
        $tasks = is_array($collection['source_tasks'] ?? null)
            ? $collection['source_tasks']
            : [];
        $platformRows = is_array($day['platforms'] ?? null) ? $day['platforms'] : [];
        if (count($tasks) !== count($scope['platforms'])
            || count($platformRows) !== count($scope['platforms'])
        ) {
            return false;
        }

        $seenPlatforms = [];
        foreach ($platformRows as $platformRow) {
            if (!is_array($platformRow)) {
                return false;
            }
            $platform = strtolower(trim((string)($platformRow['platform'] ?? '')));
            $task = is_array($tasks[$platform] ?? null) ? $tasks[$platform] : [];
            $receipt = is_array($platformRow['acceptance_receipt'] ?? null)
                ? $platformRow['acceptance_receipt']
                : [];
            if (!in_array($platform, $scope['platforms'], true)
                || isset($seenPlatforms[$platform])
                || $task === []
                || strtolower(trim((string)($receipt['platform'] ?? ''))) !== $platform
                || ($receipt['claim_allowed'] ?? false) !== true
                || (int)($receipt['data_source_id'] ?? 0)
                    !== (int)($task['data_source_id'] ?? 0)
                || (int)($receipt['sync_task_id'] ?? 0)
                    !== (int)($task['sync_task_id'] ?? 0)
            ) {
                return false;
            }
            $seenPlatforms[$platform] = true;
        }

        foreach ($scope['platforms'] as $platform) {
            if (!isset($seenPlatforms[$platform])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $child
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $collection
     * @param array<int,string> $reasons
     * @return array<string,mixed>
     */
    private function evaluateOperations(
        array $child,
        array $scope,
        array $collection,
        array &$reasons
    ): array {
        $analysis = is_array($child['canonical_operation_finalization'] ?? null)
            ? $child['canonical_operation_finalization']
            : [];
        $selectedPlatform = strtolower(trim((string)($analysis['selected_platform'] ?? '')));
        $analysisScope = is_array($analysis['scope'] ?? null) ? $analysis['scope'] : [];
        $records = $this->operationTriplets($analysis['records'] ?? []);
        $recordIdsValid = count($records) === 4
            && count(array_unique(array_column($records, 'intent_id'))) === 4
            && count(array_unique(array_column($records, 'task_id'))) === 4
            && count(array_unique(array_column($records, 'evidence_id'))) === 4
            && !in_array(0, array_column($records, 'intent_id'), true)
            && !in_array(0, array_column($records, 'task_id'), true)
            && !in_array(0, array_column($records, 'evidence_id'), true);
        $recordActionTypes = array_values(array_map('strval', array_column($records, 'action_type')));
        $expectedActionTypes = in_array($selectedPlatform, self::ALLOWED_PLATFORMS, true)
            ? CanonicalOtaInvestigationActionService::actionTypesForPlatform($selectedPlatform)
            : [];
        $analysisReady = (string)($analysis['schema_version'] ?? '') === self::OPERATION_SCHEMA
            && strtolower(trim((string)($analysis['status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($analysis['analysis_status'] ?? ''))) === 'verified'
            && ($analysis['analysis_only'] ?? false) === true
            && (int)($analysis['draft_count'] ?? 0) === 4
            && (int)($analysis['trusted_operational_check_count'] ?? 0) === 4
            && (int)($analysis['trusted_external_operation_count'] ?? -1) === 0
            && ($analysis['draft_readback_verified'] ?? false) === true
            && ($analysis['db_readback_verified'] ?? false) === true
            && ($analysis['operation_flow_readback_verified'] ?? false) === true
            && ($analysis['external_action_triggered'] ?? true) === false
            && ($analysis['business_outcome_claimed'] ?? true) === false
            && ($analysis['causality_claimed'] ?? true) === false
            && ($analysis['sensitive_values_exposed'] ?? true) === false
            && in_array($selectedPlatform, $scope['platforms'], true)
            && $recordIdsValid
            && $recordActionTypes === $expectedActionTypes;
        if (!$analysisReady) {
            $reasons[] = 'daily_operation_finalization_not_verified';
        }

        try {
            $owner = ($this->ownerResolver)(
                $scope['tenant_id'],
                $scope['hotel_id'],
                $scope['target_date'],
                self::DATA_PERIOD
            );
        } catch (\Throwable) {
            $owner = [];
        }
        $ownerReceipt = is_array($owner['selection_receipt'] ?? null)
            ? $owner['selection_receipt']
            : [];
        $ownerTriplets = $this->operationTriplets($ownerReceipt['triplets'] ?? []);
        $ownerScope = is_array($owner['scope'] ?? null) ? $owner['scope'] : [];
        $ownerIntentIds = array_values(array_unique(array_map(
            'intval',
            is_array($ownerReceipt['intent_ids'] ?? null) ? $ownerReceipt['intent_ids'] : []
        )));
        sort($ownerIntentIds, SORT_NUMERIC);
        $recordIntentIds = array_values(array_unique(array_map('intval', array_column($records, 'intent_id'))));
        sort($recordIntentIds, SORT_NUMERIC);
        $ownerReady = strtolower(trim((string)($owner['status'] ?? ''))) === 'selected'
            && ($owner['selected'] ?? false) === true
            && strtolower(trim((string)($owner['platform'] ?? ''))) === $selectedPlatform
            && ($ownerReceipt['readback_verified'] ?? false) === true
            && $ownerIntentIds === $recordIntentIds
            && $ownerTriplets === $records
            && $ownerScope === $analysisScope
            && $this->exactOperationScope($analysisScope, $scope, $selectedPlatform)
            && $this->operationScopeBelongsToCollection($analysisScope, $collection, $selectedPlatform)
            && $this->digestEquals(
                (string)($analysis['action_set_digest'] ?? ''),
                (string)($ownerReceipt['action_set_digest'] ?? '')
            );
        if (!$ownerReady) {
            $reasons[] = 'daily_platform_owner_readback_invalid';
        }

        return [
            'status' => $analysisReady && $ownerReady ? 'verified' : 'blocked',
            'selected_platform' => $selectedPlatform,
            'scope' => $this->safeOperationScope($analysisScope),
            'trusted_analysis_check_count' => $analysisReady ? 4 : 0,
            'trusted_external_operation_count' => 0,
            'intent_ids' => $recordIntentIds,
            'task_ids' => array_values(array_map('intval', array_column($records, 'task_id'))),
            'evidence_ids' => array_values(array_map('intval', array_column($records, 'evidence_id'))),
            'triplets' => $records,
            'action_types' => $recordActionTypes,
            'action_set_digest' => $this->safeDigest($analysis['action_set_digest'] ?? ''),
            'owner_scope_digest' => $this->safeDigest($ownerReceipt['owner_scope_digest'] ?? ''),
            'selection_receipt_digest' => $this->safeDigest($ownerReceipt['content_digest'] ?? ''),
            'analysis_only' => ($analysis['analysis_only'] ?? false) === true,
            'draft_readback_verified' => ($analysis['draft_readback_verified'] ?? false) === true,
            'db_readback_verified' => ($analysis['db_readback_verified'] ?? false) === true,
            'operation_flow_readback_verified' =>
                ($analysis['operation_flow_readback_verified'] ?? false) === true,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
        ];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $fact
     * @param array<string,mixed> $scope
     * @param array<string,array<string,mixed>> $origins
     * @return array<string,mixed>
     */
    private function assertTaskFact(array $task, array $fact, array $scope, array $origins): array
    {
        $platform = strtolower(trim((string)($task['platform'] ?? '')));
        $sourceId = (int)($task['data_source_id'] ?? 0);
        $taskId = (int)($task['sync_task_id'] ?? 0);
        $dispatcherRunId = strtolower(trim((string)($task['dispatcher_run_id'] ?? '')));
        $ingestionMethod = strtolower(trim((string)($task['ingestion_method'] ?? '')));
        $localCollectorTaskId = max(0, (int)($task['local_collector_task_id'] ?? 0));
        $factIngestionMethod = strtolower(trim((string)($fact['ingestion_method'] ?? '')));
        $factLocalCollectorTaskId = max(0, (int)($fact['local_collector_task_id'] ?? 0));
        $executionOwnerUserId = max(0, (int)($task['execution_owner_user_id'] ?? 0));
        $factExecutionOwnerUserId = max(0, (int)($fact['execution_owner_user_id'] ?? 0));
        $expectedTrigger = $ingestionMethod === 'local_collector'
            ? 'local_collector_upload'
            : 'daily_profile_reuse';
        $producerMethodReady = $ingestionMethod === 'local_collector'
            ? $localCollectorTaskId > 0
            : ($ingestionMethod === 'browser_profile' && $localCollectorTaskId === 0);
        $rowIds = $this->positiveIds($task['row_ids'] ?? []);
        $factRowIds = $this->positiveIds($fact['row_ids'] ?? []);
        $requiredCoreKeys = in_array($platform, self::ALLOWED_PLATFORMS, true)
            ? OtaOrderedCollectionPlanner::requiredFieldKeys($platform)
            : [];
        if (strtolower(trim((string)(
                $task['historical_core_contract_status'] ?? ''
            ))) !== 'ready'
            || strtolower(trim((string)(
                $fact['historical_core_contract_status'] ?? ''
            ))) !== 'ready'
            || $requiredCoreKeys === []
            || $this->coreMetricKeys($fact['required_core_metric_keys'] ?? [], $platform)
                !== $requiredCoreKeys
            || $this->coreMetricKeys($fact['complete_core_metric_keys'] ?? [], $platform)
                !== $requiredCoreKeys
            || $this->coreMetricKeys($fact['missing_core_metric_keys'] ?? [], $platform) !== []
        ) {
            throw new RuntimeException('source_task_core_contract_incomplete');
        }
        if (($fact['readback_verified'] ?? false) !== true
            || (int)($fact['tenant_id'] ?? 0) !== $scope['tenant_id']
            || (int)($fact['hotel_id'] ?? 0) !== $scope['hotel_id']
            || (int)($fact['data_source_id'] ?? 0) !== $sourceId
            || (int)($fact['sync_task_id'] ?? 0) !== $taskId
            || strtolower(trim((string)($fact['platform'] ?? ''))) !== $platform
            || (string)($fact['target_date'] ?? '') !== $scope['target_date']
            || strtolower(trim((string)($fact['data_period'] ?? ''))) !== self::DATA_PERIOD
            || strtolower(trim((string)($fact['task_status'] ?? ''))) !== 'success'
            || !$producerMethodReady
            || $factIngestionMethod !== $ingestionMethod
            || $factLocalCollectorTaskId !== $localCollectorTaskId
            || $executionOwnerUserId <= 0
            || $factExecutionOwnerUserId !== $executionOwnerUserId
            || ($fact['producer_evidence_verified'] ?? false) !== true
            || strtolower(trim((string)($task['trigger_type'] ?? ''))) !== $expectedTrigger
            || strtolower(trim((string)($fact['trigger_type'] ?? ''))) !== $expectedTrigger
            || !$this->uuid($dispatcherRunId)
            || strtolower(trim((string)($fact['dispatcher_run_id'] ?? ''))) !== $dispatcherRunId
            || strtolower(trim((string)($fact['stats_dispatcher_run_id'] ?? ''))) !== $dispatcherRunId
            || strtolower(trim((string)($fact['run_readback_dispatcher_run_id'] ?? ''))) !== $dispatcherRunId
            || $rowIds === []
            || $factRowIds !== $rowIds
            || (int)($fact['saved_count'] ?? 0) <= 0
            || (int)($fact['readback_count'] ?? 0) !== count($rowIds)
            || $this->safeDigest($fact['readback_digest'] ?? '') === ''
        ) {
            throw new RuntimeException('source_task_scope_or_readback_invalid');
        }
        $origin = $origins[$dispatcherRunId] ?? null;
        if (!is_array($origin)
            || !$this->originContainsTask($origin, $task)
            || !$this->timeInsideOrigin((string)($fact['task_started_at'] ?? ''), $origin)
        ) {
            throw new RuntimeException('source_task_not_natural');
        }
        return [
            'platform' => $platform,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'dispatcher_run_id' => $dispatcherRunId,
            'ingestion_method' => $ingestionMethod,
            'local_collector_task_id' => $localCollectorTaskId > 0
                ? $localCollectorTaskId
                : null,
            'execution_owner_user_id' => $executionOwnerUserId,
            'trigger_type' => $expectedTrigger,
            'task_started_at' => $this->safeDateTime($fact['task_started_at'] ?? ''),
            'row_ids' => $rowIds,
            'saved_count' => max(0, (int)($fact['saved_count'] ?? 0)),
            'readback_count' => max(0, (int)($fact['readback_count'] ?? 0)),
            'readback_verified' => true,
            'readback_digest' => $this->safeDigest($fact['readback_digest'] ?? ''),
            'historical_core_contract_status' => 'ready',
            'required_core_metric_keys' => $requiredCoreKeys,
            'complete_core_metric_keys' => $requiredCoreKeys,
            'missing_core_metric_keys' => [],
        ];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $scope */
    private function buildStability(array $receipt, string $logDirectory, array $scope): array
    {
        $currentVerified = ($receipt['status'] ?? '') === 'verified';
        $candidates = [];
        foreach ($this->acceptanceReceiptsFromLogs($logDirectory) as $prior) {
            if (!$this->storedAcceptanceDigestValid($prior)
                || ($prior['status'] ?? '') !== 'verified'
                || (string)($prior['pipeline_contract_digest'] ?? '') !== $this->pipelineContractDigest()
                || (int)($prior['tenant_id'] ?? 0) !== $scope['tenant_id']
                || (int)($prior['hotel_id'] ?? 0) !== $scope['hotel_id']
                || $this->positiveIds($prior['expected_source_ids'] ?? []) !== $scope['source_ids']
                || $this->platforms($prior['expected_platforms'] ?? []) !== $scope['platforms']
            ) {
                continue;
            }
            $date = (string)($prior['target_date'] ?? '');
            if ($this->validDate($date) && $date < $scope['target_date']) {
                $candidates[$date][] = $prior;
            }
        }

        $dates = [];
        $cursor = new DateTimeImmutable($scope['target_date'] . ' 00:00:00');
        for ($index = 0; $index < 30; $index++) {
            $date = $cursor->format('Y-m-d');
            if ($index === 0) {
                $dateReady = $currentVerified;
            } else {
                $dateReady = false;
                $historicalScope = $scope;
                $historicalScope['target_date'] = $date;
                foreach ($candidates[$date] ?? [] as $prior) {
                    if ($this->storedAcceptanceStillCurrent($prior, $logDirectory, $historicalScope)) {
                        $dateReady = true;
                        break;
                    }
                }
            }
            if (!$dateReady) {
                break;
            }
            $dates[] = $date;
            $cursor = $cursor->modify('-1 day');
        }
        $dates = array_reverse($dates);
        $count = count($dates);
        return [
            'status' => $count >= self::REQUIRED_STABLE_DAYS ? 'verified' : 'collecting_evidence',
            'consecutive_verified_natural_days' => $count,
            'required_days' => self::REQUIRED_STABLE_DAYS,
            'stable' => $count >= self::REQUIRED_STABLE_DAYS,
            'dates' => $dates,
            'reason' => $count >= self::REQUIRED_STABLE_DAYS ? '' : 'streak_below_three',
        ];
    }

    /**
     * A historical day remains in the streak only while its exact natural
     * task rows and sticky four-check owner still read back. This prevents a
     * once-valid receipt from hiding later row ownership or evidence drift.
     *
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $scope
     */
    private function storedAcceptanceStillCurrent(
        array $receipt,
        string $logDirectory,
        array $scope
    ): bool {
        $natural = is_array($receipt['natural_dispatch'] ?? null)
            ? $receipt['natural_dispatch']
            : [];
        $collection = is_array($receipt['collection'] ?? null)
            ? $receipt['collection']
            : [];
        $operations = is_array($receipt['operations'] ?? null)
            ? $receipt['operations']
            : [];
        $runId = strtolower(trim((string)($natural['run_id'] ?? '')));
        if (($natural['status'] ?? '') !== 'verified'
            || !$this->uuid($runId)
            || ($collection['status'] ?? '') !== 'verified'
            || ($operations['status'] ?? '') !== 'verified'
        ) {
            return false;
        }

        $origins = $this->loadNaturalOrigins($logDirectory, $scope);
        if (!isset($origins[$runId])) {
            return false;
        }
        $currentTasks = [];
        foreach ($scope['platforms'] as $platform) {
            $storedTask = is_array($collection['source_tasks'][$platform] ?? null)
                ? $collection['source_tasks'][$platform]
                : [];
            if ($storedTask === []) {
                return false;
            }
            $task = $storedTask;
            $task['collection_status'] = 'success';
            $task['p0_status'] = 'ready';
            try {
                $fact = ($this->taskFactResolver)(
                    $scope['tenant_id'],
                    $scope['hotel_id'],
                    $scope['target_date'],
                    $task
                );
                $currentTasks[$platform] = $this->assertTaskFact(
                    $task,
                    $fact,
                    $scope,
                    $origins
                );
            } catch (\Throwable) {
                return false;
            }
        }
        ksort($currentTasks, SORT_STRING);
        if (array_keys($currentTasks) !== $scope['platforms']) {
            return false;
        }

        $currentCollection = ['source_tasks' => $currentTasks];
        $continuousReasons = [];
        if (($this->evaluateContinuousTrust(
            $scope,
            $currentCollection,
            $continuousReasons
        )['status'] ?? '') !== 'verified') {
            return false;
        }

        $selectedPlatform = strtolower(trim((string)($operations['selected_platform'] ?? '')));
        $operationScope = is_array($operations['scope'] ?? null) ? $operations['scope'] : [];
        $storedTriplets = $this->operationTriplets($operations['triplets'] ?? []);
        $intentIds = $this->positiveIds(array_column($storedTriplets, 'intent_id'));
        $taskIds = $this->positiveIds(array_column($storedTriplets, 'task_id'));
        $evidenceIds = $this->positiveIds(array_column($storedTriplets, 'evidence_id'));
        $actionTypes = array_values(array_map('strval', array_column($storedTriplets, 'action_type')));
        $expectedActionTypes = in_array($selectedPlatform, self::ALLOWED_PLATFORMS, true)
            ? CanonicalOtaInvestigationActionService::actionTypesForPlatform($selectedPlatform)
            : [];
        if (count($intentIds) !== 4
            || count($taskIds) !== 4
            || count($evidenceIds) !== 4
            || $actionTypes !== $expectedActionTypes
            || $this->positiveIds($operations['intent_ids'] ?? []) !== $intentIds
            || $this->positiveIds($operations['task_ids'] ?? []) !== $taskIds
            || $this->positiveIds($operations['evidence_ids'] ?? []) !== $evidenceIds
            || ($operations['analysis_only'] ?? false) !== true
            || ($operations['draft_readback_verified'] ?? false) !== true
            || ($operations['db_readback_verified'] ?? false) !== true
            || ($operations['operation_flow_readback_verified'] ?? false) !== true
            || ($operations['external_action_triggered'] ?? true) !== false
            || ($operations['business_outcome_claimed'] ?? true) !== false
            || ($operations['causality_claimed'] ?? true) !== false
            || !$this->exactOperationScope($operationScope, $scope, $selectedPlatform)
            || !$this->operationScopeBelongsToCollection(
                $operationScope,
                $currentCollection,
                $selectedPlatform
            )
        ) {
            return false;
        }

        try {
            $owner = ($this->ownerResolver)(
                $scope['tenant_id'],
                $scope['hotel_id'],
                $scope['target_date'],
                self::DATA_PERIOD
            );
        } catch (\Throwable) {
            return false;
        }
        $ownerReceipt = is_array($owner['selection_receipt'] ?? null)
            ? $owner['selection_receipt']
            : [];
        $ownerIntentIds = $this->positiveIds($ownerReceipt['intent_ids'] ?? []);
        $ownerTriplets = $this->operationTriplets($ownerReceipt['triplets'] ?? []);
        return strtolower(trim((string)($owner['status'] ?? ''))) === 'selected'
            && ($owner['selected'] ?? false) === true
            && strtolower(trim((string)($owner['platform'] ?? ''))) === $selectedPlatform
            && (is_array($owner['scope'] ?? null) ? $owner['scope'] : []) === $operationScope
            && ($ownerReceipt['readback_verified'] ?? false) === true
            && $ownerIntentIds === $intentIds
            && $ownerTriplets === $storedTriplets
            && $this->digestEquals(
                (string)($ownerReceipt['action_set_digest'] ?? ''),
                (string)($operations['action_set_digest'] ?? '')
            )
            && $this->digestEquals(
                (string)($ownerReceipt['owner_scope_digest'] ?? ''),
                (string)($operations['owner_scope_digest'] ?? '')
            )
            && $this->digestEquals(
                (string)($ownerReceipt['content_digest'] ?? ''),
                (string)($operations['selection_receipt_digest'] ?? '')
            );
    }

    /** @return array<int,array<string,mixed>> */
    private function acceptanceReceiptsFromLogs(string $logDirectory): array
    {
        $receipts = [];
        $paths = glob($logDirectory . DIRECTORY_SEPARATOR . 'ota_dispatcher_*.log') ?: [];
        rsort($paths, SORT_STRING);
        foreach (array_slice($paths, 0, 240) as $path) {
            if (!is_file($path) || is_link($path) || (int)filesize($path) > 16 * 1024 * 1024) {
                continue;
            }
            $logReceipts = [];
            $readbackMarkers = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'dispatcher_daily_acceptance_readback_verified=')) {
                    $readbackMarkers[] = $line;
                }
                if (!str_starts_with($line, self::LINE_PREFIX)) {
                    continue;
                }
                $decoded = json_decode(substr($line, strlen(self::LINE_PREFIX)), true);
                if (is_array($decoded)) {
                    $logReceipts[] = $decoded;
                }
            }
            if (count($logReceipts) === 1
                && count($readbackMarkers) === 1
                && $readbackMarkers[0]
                    === 'dispatcher_daily_acceptance_readback_verified=true;receipt_count=1'
            ) {
                $receipts[] = $logReceipts[0];
            }
        }
        return $receipts;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,array<string,mixed>>
     */
    private function loadNaturalOrigins(string $logDirectory, array $scope): array
    {
        $origins = [];
        $paths = glob($logDirectory . DIRECTORY_SEPARATOR . 'ota_dispatcher_*.log') ?: [];
        rsort($paths, SORT_STRING);
        foreach (array_slice($paths, 0, 240) as $path) {
            try {
                $parsed = $this->parseDispatcherLog($this->resolvedLogPath($path, $logDirectory));
                $start = $parsed['start'];
                $finish = $parsed['finish'];
                $runId = strtolower(trim((string)($finish['run_id'] ?? '')));
                if (!$this->uuid($runId)
                    || !$this->dispatcherReceiptScopeMatches($start, $scope, 'start')
                    || !$this->dispatcherReceiptScopeMatches($finish, $scope, 'finish')
                    || !$this->dispatcherReceiptTimelineReady($start, $finish)
                    || strtolower(trim((string)($start['run_id'] ?? ''))) !== $runId
                    || strtolower(trim((string)($finish['provenance_status'] ?? ''))) !== 'verified'
                    || ($finish['code_stable_during_run'] ?? false) !== true
                    || !$this->schedulerCorrelationReady(
                        $finish['scheduler_correlation'] ?? null,
                        $scope['hotel_id']
                    )
                    || ($finish['child_receipt_present'] ?? false) !== true
                    || (int)($finish['child_receipt_count'] ?? 0) !== 1
                    || !is_int($parsed['terminal_exit_code'] ?? null)
                    || (int)$parsed['terminal_exit_code'] !== (int)($finish['child_exit_code'] ?? -1)
                    || !$this->digestEquals(
                        $this->childLineDigest((string)$parsed['child_line']),
                        (string)($finish['child_receipt_sha256'] ?? '')
                    )
                    || strtolower(trim((string)($parsed['child_receipt']['dispatcher_run_id'] ?? ''))) !== $runId
                ) {
                    continue;
                }
                $origins[$runId] = [
                    'run_id' => $runId,
                    'started_at' => (string)($finish['started_at'] ?? ''),
                    'finished_at' => (string)($finish['finished_at'] ?? ''),
                    'child_receipt' => $parsed['child_receipt'],
                ];
            } catch (\Throwable) {
                continue;
            }
        }
        return $origins;
    }

    /** @return array<string,mixed> */
    private function parseDispatcherLog(string $path): array
    {
        if ((int)filesize($path) > 16 * 1024 * 1024) {
            throw new RuntimeException('dispatcher_log_too_large');
        }
        $start = null;
        $finish = null;
        $childLines = [];
        $terminalExitCode = null;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                $decoded = json_decode(
                    substr($line, strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')),
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($decoded)) {
                    throw new RuntimeException('dispatcher_provenance_invalid');
                }
                if (($decoded['phase'] ?? '') === 'start') {
                    if ($start !== null) {
                        throw new RuntimeException('dispatcher_start_provenance_ambiguous');
                    }
                    $start = $decoded;
                } elseif (($decoded['phase'] ?? '') === 'finish') {
                    if ($finish !== null) {
                        throw new RuntimeException('dispatcher_finish_provenance_ambiguous');
                    }
                    $finish = $decoded;
                }
            } elseif (str_starts_with($line, 'SUXIOS_AUTO_FETCH_RECEIPT=')
                || str_starts_with($line, 'SUXIOS_COLLECTION_RUN_RECEIPT=')) {
                $childLines[] = $line;
            } elseif (preg_match('/^dispatcher_terminal_status=finished;exit_code=(-?[0-9]+)$/D', $line, $match) === 1) {
                if ($terminalExitCode !== null) {
                    throw new RuntimeException('dispatcher_terminal_status_ambiguous');
                }
                $terminalExitCode = (int)$match[1];
            }
        }
        if (!is_array($start) || !is_array($finish)) {
            throw new RuntimeException('dispatcher_provenance_missing');
        }
        foreach ($childLines as $line) {
            $prefix = str_starts_with($line, 'SUXIOS_COLLECTION_RUN_RECEIPT=')
                ? 'SUXIOS_COLLECTION_RUN_RECEIPT='
                : 'SUXIOS_AUTO_FETCH_RECEIPT=';
            $candidate = json_decode(
                substr($line, strlen($prefix)),
                true,
                128,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($candidate)
                || !array_key_exists('sensitive_values_exposed', $candidate)
                || !is_bool($candidate['sensitive_values_exposed'])
                || $candidate['sensitive_values_exposed'] !== false
            ) {
                throw new RuntimeException('child_receipt_invalid');
            }
        }
        $selectedChildLines = $childLines;
        if (count($childLines) > 1) {
            $expectedDigest = (string)($finish['child_receipt_sha256'] ?? '');
            $selectedChildLines = array_values(array_filter(
                $childLines,
                fn(string $line): bool => $this->digestEquals(
                    $this->childLineDigest($line),
                    $expectedDigest
                )
            ));
        }
        if (count($selectedChildLines) !== 1) {
            throw new RuntimeException('child_receipt_ambiguous');
        }
        $childLine = $selectedChildLines[0];
        $childPrefix = str_starts_with($childLine, 'SUXIOS_COLLECTION_RUN_RECEIPT=')
            ? 'SUXIOS_COLLECTION_RUN_RECEIPT='
            : 'SUXIOS_AUTO_FETCH_RECEIPT=';
        $child = json_decode(
            substr($childLine, strlen($childPrefix)),
            true,
            128,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($child)) {
            throw new RuntimeException('child_receipt_invalid');
        }
        return [
            'start' => $start,
            'finish' => $finish,
            'child_line' => $childLine,
            'child_receipt' => $child,
            'terminal_exit_code' => $terminalExitCode,
        ];
    }

    /** @param array<string,mixed> $scope */
    private function dispatcherReceiptScopeMatches(array $receipt, array $scope, string $phase): bool
    {
        $receiptScope = is_array($receipt['scope'] ?? null) ? $receipt['scope'] : [];
        return (int)($receipt['schema_version'] ?? 0) === 1
            && (string)($receipt['receipt_type'] ?? '') === self::DISPATCHER_RECEIPT_TYPE
            && strtolower(trim((string)($receipt['phase'] ?? ''))) === $phase
            && strtolower(trim((string)($receipt['mode'] ?? ''))) === 'daily'
            && (string)($receipt['timezone'] ?? '') === 'Asia/Shanghai'
            && (string)($receipt['target_date'] ?? '') === $scope['target_date']
            && (int)($receiptScope['hotel_id'] ?? 0) === $scope['hotel_id']
            && $this->positiveIds($receiptScope['source_ids'] ?? []) === $scope['source_ids']
            && $this->platforms($receiptScope['platforms'] ?? []) === $scope['platforms']
            && ($receipt['sensitive_values_exposed'] ?? true) === false;
    }

    /** @param array<string,mixed> $start @param array<string,mixed> $finish */
    private function dispatcherReceiptTimelineReady(array $start, array $finish): bool
    {
        $startKey = $this->dispatcherReceiptStartedAtOrderKey($start['started_at'] ?? '');
        $finishStartKey = $this->dispatcherReceiptStartedAtOrderKey(
            $finish['started_at'] ?? ''
        );
        $finishedKey = $this->dispatcherReceiptStartedAtOrderKey(
            $finish['finished_at'] ?? ''
        );
        return $startKey !== ''
            && $finishStartKey === $startKey
            && $finishedKey !== ''
            && strcmp($finishedKey, $startKey) >= 0;
    }

    private function schedulerCorrelationReady(mixed $value, int $hotelId): bool
    {
        if (!is_array($value)) {
            return false;
        }
        $eventIds = $this->positiveIds($value['event_ids'] ?? []);
        return strtolower(trim((string)($value['status'] ?? ''))) === 'correlated'
            && (string)($value['task_name'] ?? '') === 'SUXIOS OTA Dispatcher H' . $hotelId
            && (string)($value['task_path'] ?? '') === '\\'
            && strtolower(trim((string)($value['state'] ?? ''))) === 'running'
            && strtolower(trim((string)($value['reason'] ?? ''))) === 'exact_task_instance_events'
            && $eventIds === [100, 107, 129, 200]
            && ($value['manual_run_event_absent'] ?? false) === true
            && $this->uuid((string)($value['task_instance_id'] ?? ''))
            && (int)($value['engine_process_id'] ?? 0) > 0;
    }

    /** @param array<string,mixed> $origin @param array<string,mixed> $task */
    private function originContainsTask(array $origin, array $task): bool
    {
        foreach (is_array($origin['child_receipt']['source_tasks'] ?? null)
            ? $origin['child_receipt']['source_tasks']
            : [] as $candidate
        ) {
            if (!is_array($candidate)) {
                continue;
            }
            if ((int)($candidate['data_source_id'] ?? 0) === (int)($task['data_source_id'] ?? 0)
                && (int)($candidate['sync_task_id'] ?? 0) === (int)($task['sync_task_id'] ?? 0)
                && strtolower(trim((string)($candidate['platform'] ?? '')))
                    === strtolower(trim((string)($task['platform'] ?? '')))
                && strtolower(trim((string)($candidate['dispatcher_run_id'] ?? '')))
                    === strtolower(trim((string)($task['dispatcher_run_id'] ?? '')))
                && strtolower(trim((string)($candidate['ingestion_method'] ?? '')))
                    === strtolower(trim((string)($task['ingestion_method'] ?? '')))
                && (int)($candidate['local_collector_task_id'] ?? 0)
                    === (int)($task['local_collector_task_id'] ?? 0)
                && (int)($candidate['execution_owner_user_id'] ?? 0)
                    === (int)($task['execution_owner_user_id'] ?? 0)
                && strtolower(trim((string)($candidate['trigger_type'] ?? '')))
                    === strtolower(trim((string)($task['trigger_type'] ?? '')))
                && $this->positiveIds($candidate['row_ids'] ?? [])
                    === $this->positiveIds($task['row_ids'] ?? [])
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $origin */
    private function timeInsideOrigin(string $taskStartedAt, array $origin): bool
    {
        try {
            $task = new DateTimeImmutable($taskStartedAt, new DateTimeZone('Asia/Shanghai'));
            $start = new DateTimeImmutable((string)$origin['started_at']);
            $finish = new DateTimeImmutable((string)$origin['finished_at']);
        } catch (\Throwable) {
            return false;
        }
        return $task->getTimestamp() >= $start->getTimestamp() - 5
            && $task->getTimestamp() <= $finish->getTimestamp() + 5;
    }

    /** @return array<string,mixed> */
    private function loadTaskFact(int $tenantId, int $hotelId, string $date, array $task): array
    {
        $sourceId = (int)($task['data_source_id'] ?? 0);
        $taskId = (int)($task['sync_task_id'] ?? 0);
        $platform = strtolower(trim((string)($task['platform'] ?? '')));
        $localCollectorTaskId = max(0, (int)($task['local_collector_task_id'] ?? 0));
        $executionOwnerUserId = max(0, (int)($task['execution_owner_user_id'] ?? 0));
        $rowIds = $this->positiveIds($task['row_ids'] ?? []);
        $taskRow = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('platform', $platform)
            ->find();
        if (!is_array($taskRow)) {
            throw new RuntimeException('source_task_db_row_missing');
        }
        $stats = json_decode((string)($taskRow['stats_json'] ?? ''), true);
        $stats = is_array($stats) ? $stats : [];
        $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        $storedRowIds = $this->positiveIds($readback['row_ids'] ?? []);
        $rows = $rowIds === [] ? [] : Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $taskId)
            ->where('data_date', $date)
            ->where('data_period', self::DATA_PERIOD)
            ->where('platform', $platform)
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $rows = array_values(array_filter($rows, 'is_array'));
        $dbRowIds = $this->positiveIds(array_column($rows, 'id'));
        $rowsVerified = $dbRowIds === $rowIds;
        foreach ($rows as $row) {
            if ((int)($row['readback_verified'] ?? 0) !== 1
                || trim((string)($row['source_trace_id'] ?? '')) === ''
            ) {
                $rowsVerified = false;
                break;
            }
        }
        $readbackScopeReady = ($readback['readback_verified'] ?? false) === true
            && (int)($readback['sync_task_id'] ?? 0) === $taskId
            && (int)($readback['data_source_id'] ?? 0) === $sourceId
            && (int)($readback['system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($readback['platform'] ?? ''))) === $platform
            && (string)($readback['target_date'] ?? '') === $date
            && strtolower(trim((string)($readback['data_period'] ?? ''))) === self::DATA_PERIOD
            && $storedRowIds === $rowIds;
        $statsDispatcherRunId = strtolower(trim((string)($stats['dispatcher_run_id'] ?? '')));
        $readbackDispatcherRunId = strtolower(trim((string)($readback['dispatcher_run_id'] ?? '')));
        $requiredCoreMetricKeys = in_array($platform, self::ALLOWED_PLATFORMS, true)
            ? OtaOrderedCollectionPlanner::requiredFieldKeys($platform)
            : [];
        $coreRows = $requiredCoreMetricKeys === []
            ? []
            : OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows);
        $completeCoreMetricKeys = $coreRows === []
            ? []
            : OtaOrderedCollectionPlanner::capturedFieldKeys($platform, $coreRows);
        $missingCoreMetricKeys = array_values(array_diff(
            $requiredCoreMetricKeys,
            $completeCoreMetricKeys
        ));
        $historicalCoreContractStatus = $requiredCoreMetricKeys !== []
            && $missingCoreMetricKeys === []
                ? 'ready'
                : 'blocked';
        $ingestionMethod = strtolower(trim((string)($taskRow['ingestion_method'] ?? '')));
        try {
            $producerEvidenceVerified = (new HotelCollectionRunReceiptService())->sourceEvidenceCurrent(
                (string)($task['dispatcher_run_id'] ?? ''),
                $hotelId,
                $date,
                $platform,
                $sourceId,
                $taskId,
                $localCollectorTaskId,
                $rowIds,
                $executionOwnerUserId
            );
        } catch (\Throwable) {
            // Missing or unreadable producer-ledger evidence blocks natural
            // acceptance, but must not turn a truthful data-gap response into
            // an unhandled request failure.
            $producerEvidenceVerified = false;
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'platform' => $platform,
            'target_date' => $date,
            'data_period' => self::DATA_PERIOD,
            'task_status' => strtolower(trim((string)($taskRow['status'] ?? ''))),
            'ingestion_method' => $ingestionMethod,
            'local_collector_task_id' => $localCollectorTaskId > 0
                ? $localCollectorTaskId
                : null,
            'execution_owner_user_id' => $executionOwnerUserId,
            'producer_evidence_verified' => $producerEvidenceVerified,
            'trigger_type' => strtolower(trim((string)($taskRow['trigger_type'] ?? ''))),
            'dispatcher_run_id' => $readbackDispatcherRunId,
            'stats_dispatcher_run_id' => $statsDispatcherRunId,
            'run_readback_dispatcher_run_id' => $readbackDispatcherRunId,
            'task_started_at' => trim((string)($taskRow['started_at'] ?? '')),
            'row_ids' => $dbRowIds,
            'saved_count' => max(0, (int)($stats['saved_count'] ?? 0)),
            'readback_count' => max(0, (int)($readback['readback_count'] ?? 0)),
            'readback_verified' => $rowsVerified && $readbackScopeReady,
            'readback_digest' => $this->digest([
                'task_id' => $taskId,
                'source_id' => $sourceId,
                'platform' => $platform,
                'target_date' => $date,
                'row_ids' => $dbRowIds,
            ]),
            'historical_core_contract_status' => $historicalCoreContractStatus,
            'required_core_metric_keys' => $requiredCoreMetricKeys,
            'complete_core_metric_keys' => $completeCoreMetricKeys,
            'missing_core_metric_keys' => $missingCoreMetricKeys,
        ];
    }

    private function resolveTenantId(int $hotelId): int
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id')->find();
        return is_array($hotel) && (int)($hotel['id'] ?? 0) === $hotelId
            ? max(0, (int)($hotel['tenant_id'] ?? 0))
            : 0;
    }

    /** @return array<string,mixed> */
    private function normalizeScope(int $hotelId, string $date, array $sourceIds, array $platforms): array
    {
        $sourceIds = $this->positiveIds($sourceIds);
        $platforms = $this->platforms($platforms);
        if ($hotelId <= 0
            || !$this->validDate($date)
            || $sourceIds === []
            || $platforms !== self::ALLOWED_PLATFORMS
            || count($sourceIds) !== count($platforms)
        ) {
            throw new InvalidArgumentException('daily_acceptance_scope_invalid');
        }
        return [
            'tenant_id' => 0,
            'hotel_id' => $hotelId,
            'target_date' => $date,
            'source_ids' => $sourceIds,
            'platforms' => $platforms,
        ];
    }

    private function resolvedLogDirectory(string $directory): string
    {
        $resolved = realpath($directory);
        if (!is_string($resolved) || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('dispatcher_log_directory_invalid');
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    private function resolvedLogPath(string $path, string $directory): string
    {
        $resolved = realpath($path);
        $prefix = $directory . DIRECTORY_SEPARATOR;
        if (!is_string($resolved)
            || !is_file($resolved)
            || is_link($resolved)
            || !str_starts_with(strtolower($resolved), strtolower($prefix))
            || preg_match(
                '/^ota_dispatcher_[0-9]{8}_[0-9]{6}_[a-f0-9]{32}\.log$/D',
                basename($resolved)
            ) !== 1
        ) {
            throw new RuntimeException('dispatcher_log_path_invalid');
        }
        return $resolved;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function blockedReceipt(array $scope, int $tenantId, string $reason): array
    {
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason_codes' => [$reason],
            'tenant_id' => max(0, $tenantId),
            'hotel_id' => max(0, (int)($scope['hotel_id'] ?? 0)),
            'target_date' => $this->validDate((string)($scope['target_date'] ?? ''))
                ? (string)$scope['target_date']
                : '',
            'service_date' => '',
            'data_period' => self::DATA_PERIOD,
            'expected_source_ids' => $this->positiveIds($scope['source_ids'] ?? []),
            'expected_platforms' => $this->platforms($scope['platforms'] ?? []),
            'pipeline_contract_digest' => $this->pipelineContractDigest(),
            'natural_dispatch' => ['status' => 'blocked'],
            'collection' => ['status' => 'blocked'],
            'continuous_trust' => ['status' => 'blocked'],
            'operations' => [
                'status' => 'blocked',
                'trusted_analysis_check_count' => 0,
                'trusted_external_operation_count' => 0,
            ],
            'stability' => [
                'status' => 'collecting_evidence',
                'consecutive_verified_natural_days' => 0,
                'required_days' => self::REQUIRED_STABLE_DAYS,
                'stable' => false,
                'dates' => [],
                'reason' => 'streak_below_three',
            ],
            'collection_triggered_by_acceptance' => false,
            'business_data_written_by_acceptance' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'sensitive_values_exposed' => false,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /** @param array<string,mixed> $scope */
    private function exactOperationScope(array $value, array $scope, string $platform): bool
    {
        return (int)($value['tenant_id'] ?? 0) === $scope['tenant_id']
            && (int)($value['hotel_id'] ?? 0) === $scope['hotel_id']
            && (int)($value['data_source_id'] ?? 0) > 0
            && (int)($value['task_id'] ?? 0) > 0
            && (int)($value['row_id'] ?? 0) > 0
            && strtolower(trim((string)($value['platform'] ?? ''))) === $platform
            && (string)($value['target_date'] ?? '') === $scope['target_date']
            && strtolower(trim((string)($value['data_period'] ?? ''))) === self::DATA_PERIOD;
    }

    /** @param array<string,mixed> $operationScope @param array<string,mixed> $collection */
    private function operationScopeBelongsToCollection(
        array $operationScope,
        array $collection,
        string $platform
    ): bool {
        $task = is_array($collection['source_tasks'][$platform] ?? null)
            ? $collection['source_tasks'][$platform]
            : [];
        return (int)($operationScope['data_source_id'] ?? 0) === (int)($task['data_source_id'] ?? 0)
            && (int)($operationScope['task_id'] ?? 0) === (int)($task['sync_task_id'] ?? 0)
            && in_array((int)($operationScope['row_id'] ?? 0), $this->positiveIds($task['row_ids'] ?? []), true);
    }

    /** @return array<string,mixed> */
    private function safeOperationScope(array $scope): array
    {
        return [
            'tenant_id' => max(0, (int)($scope['tenant_id'] ?? 0)),
            'hotel_id' => max(0, (int)($scope['hotel_id'] ?? 0)),
            'data_source_id' => max(0, (int)($scope['data_source_id'] ?? 0)),
            'task_id' => max(0, (int)($scope['task_id'] ?? 0)),
            'row_id' => max(0, (int)($scope['row_id'] ?? 0)),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'target_date' => (string)($scope['target_date'] ?? ''),
            'data_period' => strtolower(trim((string)($scope['data_period'] ?? ''))),
        ];
    }

    /** @return array<string,mixed> */
    private function safeVerifier(array $verifier): array
    {
        return [
            'status' => strtolower(trim((string)($verifier['status'] ?? ''))),
            'authority_ready' => ($verifier['authority_ready'] ?? false) === true,
            'target_date' => (string)($verifier['target_date'] ?? ''),
            'hotel_id' => max(0, (int)($verifier['hotel_id'] ?? 0)),
            'verified_platforms' => $this->platforms($verifier['verified_platforms'] ?? []),
            'p0_platforms_ready' => max(0, (int)($verifier['p0_platforms_ready'] ?? 0)),
            'traffic_gates_ready' => max(0, (int)($verifier['traffic_gates_ready'] ?? 0)),
            'collection_anchor_hash' => $this->safeDigest($verifier['collection_anchor_hash'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function safeStoredStatus(
        array $receipt,
        int $hotelId,
        string $expectedTargetDate
    ): array
    {
        $natural = is_array($receipt['natural_dispatch'] ?? null)
            ? $receipt['natural_dispatch']
            : [];
        $collection = is_array($receipt['collection'] ?? null)
            ? $receipt['collection']
            : [];
        $continuous = is_array($receipt['continuous_trust'] ?? null)
            ? $receipt['continuous_trust']
            : [];
        $operations = is_array($receipt['operations'] ?? null)
            ? $receipt['operations']
            : [];
        $stability = is_array($receipt['stability'] ?? null)
            ? $receipt['stability']
            : [];

        $componentStatuses = [
            'natural_dispatch' => $this->storedComponentStatus($natural['status'] ?? ''),
            'collection' => $this->storedComponentStatus($collection['status'] ?? ''),
            'continuous_trust' => $this->storedComponentStatus($continuous['status'] ?? ''),
            'operations' => $this->storedComponentStatus($operations['status'] ?? ''),
        ];
        $receiptStatus = $this->storedComponentStatus($receipt['status'] ?? '');
        $targetDate = $this->validDate((string)($receipt['target_date'] ?? ''))
            ? (string)$receipt['target_date']
            : '';
        $platforms = $this->platforms($receipt['expected_platforms'] ?? []);
        $sourceIds = $this->positiveIds($receipt['expected_source_ids'] ?? []);
        $selectedPlatform = strtolower(trim((string)($operations['selected_platform'] ?? '')));
        $analysisCount = max(0, (int)($operations['trusted_analysis_check_count'] ?? 0));
        $externalCount = max(0, (int)($operations['trusted_external_operation_count'] ?? 0));
        $operationScope = $this->safeOperationScope(
            is_array($operations['scope'] ?? null) ? $operations['scope'] : []
        );
        $actionTypes = array_values(array_map(
            'strval',
            is_array($operations['action_types'] ?? null) ? $operations['action_types'] : []
        ));
        $expectedActionTypes = in_array($selectedPlatform, self::ALLOWED_PLATFORMS, true)
            ? CanonicalOtaInvestigationActionService::actionTypesForPlatform($selectedPlatform)
            : [];
        $acceptanceScope = [
            'tenant_id' => max(0, (int)($receipt['tenant_id'] ?? 0)),
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
        ];
        $operationReadbackVerified = ($operations['draft_readback_verified'] ?? false) === true
            && ($operations['db_readback_verified'] ?? false) === true
            && ($operations['operation_flow_readback_verified'] ?? false) === true;
        $receiptScopeReady = (string)($receipt['schema_version'] ?? '') === self::SCHEMA_VERSION
            && (int)($receipt['tenant_id'] ?? 0) > 0
            && (int)($receipt['hotel_id'] ?? 0) === $hotelId
            && $targetDate !== ''
            && strtolower(trim((string)($receipt['data_period'] ?? ''))) === self::DATA_PERIOD
            && count($sourceIds) === count(self::ALLOWED_PLATFORMS)
            && $platforms === self::ALLOWED_PLATFORMS
            && ($receipt['collection_triggered_by_acceptance'] ?? true) === false
            && ($receipt['business_data_written_by_acceptance'] ?? true) === false
            && ($receipt['external_action_triggered'] ?? true) === false
            && ($receipt['business_outcome_claimed'] ?? true) === false
            && ($receipt['causality_claimed'] ?? true) === false
            && ($receipt['sensitive_values_exposed'] ?? true) === false;
        $dailyVerified = $receiptStatus === 'verified'
            && $receiptScopeReady
            && !in_array('blocked', $componentStatuses, true)
            && in_array($selectedPlatform, self::ALLOWED_PLATFORMS, true)
            && $this->exactOperationScope($operationScope, $acceptanceScope, $selectedPlatform)
            && $actionTypes === $expectedActionTypes
            && $analysisCount === 4
            && $externalCount === 0
            && ($operations['analysis_only'] ?? false) === true
            && $operationReadbackVerified
            && ($operations['external_action_triggered'] ?? true) === false
            && ($operations['business_outcome_claimed'] ?? true) === false
            && ($operations['causality_claimed'] ?? true) === false;

        $reasonCodes = $this->safeReasonCodes($receipt['reason_codes'] ?? []);
        if ($receiptStatus === 'verified' && !$dailyVerified) {
            $reasonCodes[] = 'daily_acceptance_receipt_consistency_invalid';
        } elseif ($receiptStatus !== 'verified' && $reasonCodes === []) {
            $reasonCodes[] = 'daily_acceptance_blocked';
        }
        $reasonCodes = array_values(array_unique($reasonCodes));

        $requiredDays = self::REQUIRED_STABLE_DAYS;
        $reportedConsecutiveDays = max(
            0,
            (int)($stability['consecutive_verified_natural_days'] ?? 0)
        );
        $dates = array_values(array_filter(
            is_array($stability['dates'] ?? null) ? $stability['dates'] : [],
            fn(mixed $date): bool => $this->validDate((string)$date)
        ));
        $dates = array_values(array_unique(array_map('strval', $dates)));
        sort($dates, SORT_STRING);
        $stabilityWindow = array_slice($dates, -$requiredDays);
        $derivedConsecutiveDays = 0;
        try {
            $cursor = new DateTimeImmutable($targetDate . ' 00:00:00');
            for ($offset = 0; $offset < $requiredDays; $offset++) {
                if (!in_array($cursor->format('Y-m-d'), $dates, true)) {
                    break;
                }
                $derivedConsecutiveDays++;
                $cursor = $cursor->modify('-1 day');
            }
        } catch (\Throwable) {
            $derivedConsecutiveDays = 0;
        }
        $consecutiveDays = min(
            $requiredDays,
            $reportedConsecutiveDays,
            $derivedConsecutiveDays
        );
        $stable = $dailyVerified
            && ($stability['stable'] ?? false) === true
            && $consecutiveDays >= $requiredDays
            && $this->storedDatesAreConsecutive($stabilityWindow, $targetDate, $requiredDays);
        $finishedAt = trim((string)($natural['finished_at'] ?? ''));
        $externalActionTriggered = ($receipt['external_action_triggered'] ?? true) === true
            || ($operations['external_action_triggered'] ?? true) === true;
        $businessOutcomeClaimed = ($receipt['business_outcome_claimed'] ?? true) === true
            || ($operations['business_outcome_claimed'] ?? true) === true;
        $causalityClaimed = ($receipt['causality_claimed'] ?? true) === true
            || ($operations['causality_claimed'] ?? true) === true;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_available' => true,
            'receipt_readback_verified' => true,
            'status' => $dailyVerified ? 'verified' : 'blocked',
            'stage' => $this->storedStatusStage($dailyVerified, $stable, $componentStatuses),
            'reason_codes' => $dailyVerified ? [] : $reasonCodes,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'expected_target_date' => $expectedTargetDate,
            'latest_observed_target_date' => $targetDate,
            'freshness_status' => $targetDate === $expectedTargetDate ? 'current' : 'stale',
            'service_date' => $this->validDate((string)($receipt['service_date'] ?? ''))
                ? (string)$receipt['service_date']
                : '',
            'data_period' => self::DATA_PERIOD,
            'expected_source_ids' => $sourceIds,
            'expected_platforms' => $platforms,
            'natural_dispatch_status' => $componentStatuses['natural_dispatch'],
            'collection_status' => $componentStatuses['collection'],
            'continuous_trust_status' => $componentStatuses['continuous_trust'],
            'operations_status' => $componentStatuses['operations'],
            'natural_run_id' => $this->uuid((string)($natural['run_id'] ?? ''))
                ? strtolower((string)$natural['run_id'])
                : '',
            'finished_at' => $finishedAt === '' ? '' : $this->safeDateTime($finishedAt),
            'selected_platform' => in_array($selectedPlatform, self::ALLOWED_PLATFORMS, true)
                ? $selectedPlatform
                : '',
            'operation_scope' => $operationScope,
            'action_types' => $actionTypes === $expectedActionTypes ? $actionTypes : [],
            'trusted_analysis_check_count' => $dailyVerified ? 4 : 0,
            'trusted_external_operation_count' => $externalCount,
            'analysis_only' => ($operations['analysis_only'] ?? false) === true,
            'operation_readback_verified' => $operationReadbackVerified,
            'external_action_triggered' => $externalActionTriggered,
            'business_outcome_claimed' => $businessOutcomeClaimed,
            'causality_claimed' => $causalityClaimed,
            'stability' => [
                'status' => $stable ? 'stable' : 'collecting_evidence',
                'consecutive_verified_natural_days' => $consecutiveDays,
                'required_days' => $requiredDays,
                'stable' => $stable,
                'dates' => $stabilityWindow,
                'reason' => $stable ? '' : 'streak_below_three',
            ],
            'content_digest' => $this->safeDigest($receipt['content_digest'] ?? ''),
            'sensitive_values_exposed' => ($receipt['sensitive_values_exposed'] ?? true) === true,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyStoredStatus(
        int $hotelId,
        string $reason,
        string $expectedTargetDate = ''
    ): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_available' => false,
            'receipt_readback_verified' => false,
            'status' => 'no_evidence',
            'stage' => 'natural_dispatch',
            'reason_codes' => [$reason],
            'hotel_id' => max(0, $hotelId),
            'target_date' => '',
            'expected_target_date' => $expectedTargetDate,
            'latest_observed_target_date' => '',
            'freshness_status' => $expectedTargetDate === '' ? 'unavailable' : 'missing',
            'service_date' => '',
            'data_period' => self::DATA_PERIOD,
            'expected_source_ids' => [],
            'expected_platforms' => [],
            'natural_dispatch_status' => 'blocked',
            'collection_status' => 'blocked',
            'continuous_trust_status' => 'blocked',
            'operations_status' => 'blocked',
            'natural_run_id' => '',
            'finished_at' => '',
            'selected_platform' => '',
            'operation_scope' => $this->safeOperationScope([]),
            'action_types' => [],
            'trusted_analysis_check_count' => 0,
            'trusted_external_operation_count' => 0,
            'analysis_only' => false,
            'operation_readback_verified' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'stability' => [
                'status' => 'collecting_evidence',
                'consecutive_verified_natural_days' => 0,
                'required_days' => self::REQUIRED_STABLE_DAYS,
                'stable' => false,
                'dates' => [],
                'reason' => 'streak_below_three',
            ],
            'content_digest' => '',
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function invalidStoredStatus(
        int $hotelId,
        string $targetDate,
        string $reason,
        string $expectedTargetDate = ''
    ): array
    {
        $status = $this->emptyStoredStatus($hotelId, $reason, $expectedTargetDate);
        $status['receipt_available'] = true;
        $status['status'] = 'blocked';
        $status['stage'] = 'receipt_validation';
        $status['target_date'] = $targetDate;
        $status['latest_observed_target_date'] = $this->validDate($targetDate)
            ? $targetDate
            : '';
        if ($expectedTargetDate !== '') {
            $status['freshness_status'] = $targetDate === $expectedTargetDate
                ? 'current'
                : 'stale';
        }
        return $status;
    }

    /**
     * The filename timestamp is only precise to one second while its suffix is
     * a random GUID. Order same-second runs by the validated provenance start
     * time; if two runs for this hotel remain indistinguishable, fail closed.
     *
     * @param array<int,string> $paths
     * @return array{paths:array<int,string>,ambiguous:bool}
     */
    private function storedStatusPathOrder(array $paths, int $hotelId): array
    {
        $entries = [];
        foreach ($paths as $path) {
            if (!is_string($path)
                || !is_file($path)
                || is_link($path)
                || (int)filesize($path) > 16 * 1024 * 1024
                || preg_match(
                    '/^ota_dispatcher_[0-9]{8}_[0-9]{6}_[a-f0-9]{32}\.log$/D',
                    basename($path)
                ) !== 1
            ) {
                continue;
            }
            $entries[] = $this->storedLogOrderEntry($path, $hotelId);
        }
        usort($entries, static function (array $left, array $right): int {
            $byFilenameTime = strcmp(
                (string)$right['filename_order_key'],
                (string)$left['filename_order_key']
            );
            if ($byFilenameTime !== 0) {
                return $byFilenameTime;
            }
            $byStart = strcmp(
                (string)$right['start_order_key'],
                (string)$left['start_order_key']
            );
            return $byStart !== 0
                ? $byStart
                : strcmp((string)$right['filename'], (string)$left['filename']);
        });

        $latestFilenameKey = '';
        $latestTargetEntries = [];
        foreach ($entries as $entry) {
            if (($entry['target_daily_attempt'] ?? false) !== true
                || ($entry['preflight_only'] ?? false) === true
            ) {
                continue;
            }
            $filenameKey = (string)($entry['filename_order_key'] ?? '');
            if ($latestFilenameKey === '') {
                $latestFilenameKey = $filenameKey;
            }
            if ($filenameKey !== $latestFilenameKey) {
                break;
            }
            $latestTargetEntries[] = $entry;
        }
        $ambiguous = false;
        if (count($latestTargetEntries) > 1) {
            $startKeys = array_map(
                static fn(array $entry): string => (string)($entry['start_order_key'] ?? ''),
                $latestTargetEntries
            );
            $ambiguous = in_array('', $startKeys, true)
                || count(array_unique($startKeys)) !== count($startKeys);
        }

        return [
            'paths' => array_values(array_map(
                static fn(array $entry): string => (string)$entry['path'],
                $entries
            )),
            'ambiguous' => $ambiguous,
        ];
    }

    /** @return array<string,mixed> */
    private function storedLogOrderEntry(string $path, int $hotelId): array
    {
        $starts = [];
        $scopeMarkerHotelId = 0;
        $provenanceHotelIds = [];
        $preflightOnly = false;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^dispatcher_scope=hotel:([0-9]+);/D', $line, $match) === 1) {
                $scopeMarkerHotelId = (int)$match[1];
            }
            if (str_starts_with($line, 'dispatcher_run_mode=preflight_only;')) {
                $preflightOnly = true;
            }
            if (!str_starts_with($line, 'SUXIOS_OTA_DISPATCHER_PROVENANCE=')) {
                continue;
            }
            $decoded = json_decode(substr(
                $line,
                strlen('SUXIOS_OTA_DISPATCHER_PROVENANCE=')
            ), true);
            if (!is_array($decoded)) {
                continue;
            }
            $decodedScope = is_array($decoded['scope'] ?? null) ? $decoded['scope'] : [];
            $decodedHotelId = (int)($decodedScope['hotel_id'] ?? 0);
            if ($decodedHotelId > 0) {
                $provenanceHotelIds[] = $decodedHotelId;
            }
            if (strtolower(trim((string)($decoded['phase'] ?? ''))) !== 'start') {
                continue;
            }
            $starts[] = $decoded;
            $correlation = is_array($decoded['scheduler_correlation'] ?? null)
                ? $decoded['scheduler_correlation']
                : [];
            $preflightOnly = $preflightOnly
                || strtolower(trim((string)($correlation['reason'] ?? ''))) === 'preflight_only';
        }

        $start = count($starts) === 1 ? $starts[0] : [];
        $startScope = is_array($start['scope'] ?? null) ? $start['scope'] : [];
        $startMode = strtolower(trim((string)($start['mode'] ?? '')));
        $targetHotel = $scopeMarkerHotelId === $hotelId
            || (int)($startScope['hotel_id'] ?? 0) === $hotelId
            || in_array($hotelId, $this->positiveIds($provenanceHotelIds), true);
        $startOrderKeys = array_values(array_unique(array_filter(array_map(
            fn(array $receipt): string => $this->dispatcherReceiptStartedAtOrderKey(
                $receipt['started_at'] ?? ''
            ),
            $starts
        ))));
        $startOrderKey = count($startOrderKeys) === 1 ? $startOrderKeys[0] : '';

        return [
            'path' => $path,
            'filename' => basename($path),
            'filename_order_key' => $this->dispatcherFilenameOrderKey(basename($path)),
            'start_order_key' => $startOrderKey,
            'target_daily_attempt' => $targetHotel
                && ($startMode === '' || $startMode === 'daily'),
            'preflight_only' => $preflightOnly,
        ];
    }

    private function dispatcherReceiptStartedAtOrderKey(mixed $value): string
    {
        $value = trim((string)$value);
        if (preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,7})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
            $value
        ) !== 1) {
            return '';
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors)
                && ((int)($errors['warning_count'] ?? 0) > 0
                    || (int)($errors['error_count'] ?? 0) > 0)
            ) {
                return '';
            }
            return $date
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('YmdHis.u');
        } catch (\Throwable) {
            return '';
        }
    }

    private function dispatcherFilenameOrderKey(string $filename): string
    {
        if (preg_match(
            '/^ota_dispatcher_([0-9]{8})_([0-9]{6})_[a-f0-9]{32}\.log$/D',
            $filename,
            $match
        ) !== 1) {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Ymd_His',
            $match[1] . '_' . $match[2],
            new DateTimeZone('Asia/Shanghai')
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || (is_array($errors)
                && ((int)($errors['warning_count'] ?? 0) > 0
                    || (int)($errors['error_count'] ?? 0) > 0))
        ) {
            return '';
        }
        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('YmdHis.u');
    }

    /** @return array<string,mixed> */
    private function staleStoredStatus(
        int $hotelId,
        string $expectedTargetDate,
        string $observedTargetDate
    ): array {
        $status = $this->emptyStoredStatus(
            $hotelId,
            'latest_natural_business_date_missing',
            $expectedTargetDate
        );
        $status['stage'] = 'freshness';
        $status['target_date'] = $expectedTargetDate;
        $status['latest_observed_target_date'] = $this->validDate($observedTargetDate)
            ? $observedTargetDate
            : '';
        $status['freshness_status'] = 'stale';
        return $status;
    }

    private function expectedTargetDate(): string
    {
        try {
            $now = ($this->nowResolver)();
            if (!$now instanceof DateTimeImmutable) {
                return '';
            }
            return $now
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                ->modify('-1 day')
                ->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function storedComponentStatus(mixed $value): string
    {
        return strtolower(trim((string)$value)) === 'verified' ? 'verified' : 'blocked';
    }

    /** @param array<string,string> $statuses */
    private function storedStatusStage(bool $dailyVerified, bool $stable, array $statuses): string
    {
        if ($dailyVerified) {
            return $stable ? 'completed' : 'stability';
        }
        foreach (['natural_dispatch', 'collection', 'continuous_trust', 'operations'] as $stage) {
            if (($statuses[$stage] ?? 'blocked') !== 'verified') {
                return $stage;
            }
        }
        return 'receipt_validation';
    }

    /** @param array<int,string> $dates */
    private function storedDatesAreConsecutive(array $dates, string $targetDate, int $requiredDays): bool
    {
        if (count($dates) !== $requiredDays
            || !$this->validDate($targetDate)
            || end($dates) !== $targetDate
        ) {
            return false;
        }
        try {
            $cursor = new DateTimeImmutable($dates[0] . ' 00:00:00');
            foreach ($dates as $index => $date) {
                if ($cursor->modify('+' . $index . ' day')->format('Y-m-d') !== $date) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,string> */
    private function safeReasonCodes(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $reasons = [];
        foreach (array_slice($values, 0, 12) as $value) {
            $reason = strtolower(trim((string)$value));
            if (preg_match('/^[a-z0-9_]{1,120}$/D', $reason) === 1) {
                $reasons[] = $reason;
            }
        }
        return array_values(array_unique($reasons));
    }

    private function storedAcceptanceDigestValid(array $receipt): bool
    {
        $stored = $this->safeDigest($receipt['content_digest'] ?? '');
        if ($stored === '' || (string)($receipt['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            return false;
        }
        unset($receipt['content_digest']);
        return hash_equals($stored, $this->digest($receipt));
    }

    private function serviceDate(mixed $value): string
    {
        try {
            return (new DateTimeImmutable((string)$value))
                ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function safeDateTime(mixed $value): string
    {
        try {
            return (new DateTimeImmutable((string)$value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return '';
        }
    }

    private function safeTaskName(mixed $value): string
    {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9 _.-]{1,120}$/D', $value) === 1 ? $value : '';
    }

    private function pipelineContractDigest(): string
    {
        return hash(
            'sha256',
            self::SCHEMA_VERSION
                . '|dual_ota|natural_task_exact_core|exact_producer_method_local_task_owner|'
                . 'exact_four|shanghai_d_minus_one_freshness|'
                . OtaCollectionAnchorService::CONTRACT_VERSION . '|'
                . self::REQUIRED_STABLE_DAYS
        );
    }

    private function childLineDigest(string $line): string
    {
        return hash('sha256', json_encode(
            [$line],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonical($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonical($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }

    /**
     * @return array<int,array{intent_id:int,task_id:int,evidence_id:int,action_type:string}>
     */
    private function operationTriplets(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $triplets = [];
        foreach ($value as $record) {
            if (!is_array($record)) {
                return [];
            }
            $triplet = [
                'intent_id' => (int)($record['intent_id'] ?? 0),
                'task_id' => (int)($record['task_id'] ?? 0),
                'evidence_id' => (int)($record['evidence_id'] ?? 0),
                'action_type' => trim((string)($record['action_type'] ?? '')),
            ];
            if ($triplet['intent_id'] <= 0
                || $triplet['task_id'] <= 0
                || $triplet['evidence_id'] <= 0
                || $triplet['action_type'] === ''
            ) {
                return [];
            }
            $triplets[] = $triplet;
        }
        usort($triplets, static fn(array $left, array $right): int =>
            $left['intent_id'] <=> $right['intent_id']);
        return $triplets;
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<int,string> */
    private function coreMetricKeys(mixed $value, string $platform): array
    {
        if (!in_array($platform, self::ALLOWED_PLATFORMS, true)) {
            return [];
        }
        $present = [];
        foreach (is_array($value) ? $value : [] as $metricKey) {
            $metricKey = strtolower(trim((string)$metricKey));
            if ($metricKey !== '') {
                $present[$metricKey] = true;
            }
        }
        return array_values(array_filter(
            OtaOrderedCollectionPlanner::requiredFieldKeys($platform),
            static fn(string $metricKey): bool => isset($present[$metricKey])
        ));
    }

    /** @return array<int,string> */
    private function platforms(mixed $value): array
    {
        $platforms = [];
        foreach (is_array($value) ? $value : [] as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (in_array($platform, self::ALLOWED_PLATFORMS, true)) {
                $platforms[$platform] = $platform;
            }
        }
        $platforms = array_values($platforms);
        sort($platforms, SORT_STRING);
        return $platforms;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function uuid(string $value): bool
    {
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            strtolower(trim($value))
        ) === 1;
    }

    private function safeDigest(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    private function digestEquals(string $left, string $right): bool
    {
        $left = $this->safeDigest($left);
        $right = $this->safeDigest($right);
        return $left !== '' && $right !== '' && hash_equals($left, $right);
    }

    private function safeReason(\Throwable $exception, string $fallback): string
    {
        $reason = strtolower(trim($exception->getMessage()));
        return preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $reason) === 1
            ? $reason
            : $fallback;
    }
}
