<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;
use think\facade\Db;

/**
 * Converts one independently verified OTA historical promotion into four
 * local, analysis-only operational checks. This is a scheduler sidecar: its
 * failure must never rewrite collection truth or cause another OTA request.
 */
final class CanonicalOtaDailyOperationFinalizer
{
    public const SCHEMA_VERSION = 'canonical_ota_daily_operation_finalization.v2';
    public const SCHEDULED_AUTHORITY = 'system_scheduled_analysis';

    private const PLATFORM_PRIORITY = ['ctrip', 'meituan'];
    private const SELECTION_POLICY = CanonicalOtaDailyPlatformSelectionService::POLICY;
    private const SELECTION_POLICY_VERSION = CanonicalOtaDailyPlatformSelectionService::POLICY_VERSION;
    private const OPERATION_ROW_SELECTION_VERSION = 'ota_operation_row_selection.v1';
    private const OPERATION_ROW_SELECTION_POLICY =
        'singleton_or_equivalent_required_metrics_min_row_id.v1';
    private const PROMOTION_RECEIPT_VERSION = 'ota_canonical_history_promotion.v3';

    /** @var Closure(array<string,mixed>):array<string,mixed> */
    private Closure $draftRunner;

    /** @var Closure(array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private Closure $actionRunner;

    /** @var Closure(array<string,mixed>,int,int,string):array<string,mixed> */
    private Closure $authorizationResolver;

    /** @var Closure(int,int,string,string):array<string,mixed> */
    private Closure $selectionResolver;

    /** @var Closure(int,int,int,int,string,string):array<string,mixed> */
    private Closure $promotionReceiptResolver;

    public function __construct(
        ?callable $draftRunner = null,
        ?callable $actionRunner = null,
        ?callable $authorizationResolver = null,
        ?callable $selectionResolver = null,
        ?callable $promotionReceiptResolver = null
    ) {
        $this->draftRunner = $draftRunner !== null
            ? Closure::fromCallable($draftRunner)
            : static fn(array $scope): array =>
                (new CanonicalOtaInvestigationDraftService())->execute($scope);
        $this->actionRunner = $actionRunner !== null
            ? Closure::fromCallable($actionRunner)
            : static fn(array $scope, array $authorization): array =>
                (new CanonicalOtaInvestigationActionService())->executeScheduled($scope, $authorization);
        $this->authorizationResolver = $authorizationResolver !== null
            ? Closure::fromCallable($authorizationResolver)
            : static fn(array $authorization, int $tenantId, int $hotelId, string $platform): array =>
                (new CanonicalOtaScheduledAnalysisAuthorizationService())->assertMatches(
                    $authorization,
                    $tenantId,
                    $hotelId,
                    $platform
                );
        $this->selectionResolver = $selectionResolver !== null
            ? Closure::fromCallable($selectionResolver)
            : static fn(int $tenantId, int $hotelId, string $targetDate, string $period): array =>
                (new CanonicalOtaDailyPlatformSelectionService())->resolve(
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $period
                );
        $this->promotionReceiptResolver = $promotionReceiptResolver !== null
            ? Closure::fromCallable($promotionReceiptResolver)
            : Closure::fromCallable([$this, 'loadPersistedPromotionReceipt']);
    }

    /**
     * @param array<string,mixed> $collectionReceipt
     * @param array<string,mixed> $canonicalFinalization
     * @return array<string,mixed>
     */
    public function finalize(
        array $collectionReceipt,
        array $canonicalFinalization,
        int $expectedTenantId,
        int $expectedHotelId,
        array $scheduledAuthorization
    ): array {
        $period = strtolower(trim((string)($collectionReceipt['data_period'] ?? '')));
        if ($period !== 'historical_daily') {
            return $this->notApplicable('canonical_daily_operation_non_historical_period');
        }

        $scope = [];
        $authorization = [];
        $draftState = [];
        $strictReadyPlatforms = [];
        $authorizedReadyPlatforms = [];
        $scopeFailures = [];
        $authorizationFailures = [];
        $selection = [];
        try {
            $targetDate = substr(trim((string)($collectionReceipt['target_date'] ?? '')), 0, 10);
            if (!$this->validDate($targetDate)) {
                throw new RuntimeException('canonical_daily_operation_collection_scope_invalid');
            }
            $strictScopes = [];
            foreach (self::PLATFORM_PRIORITY as $platform) {
                try {
                    $strictScopes[$platform] = $this->exactPlatformScope(
                        $collectionReceipt,
                        $canonicalFinalization,
                        $expectedTenantId,
                        $expectedHotelId,
                        $platform
                    );
                    $strictReadyPlatforms[] = $platform;
                } catch (\Throwable $exception) {
                    // A platform-local failure must not hide the other platform.
                    $scopeFailures[$platform] = $this->safeScopeReason($exception);
                }
            }
            $authorizationCandidates = $this->authorizationCandidates($scheduledAuthorization);
            $resolvedAuthorizations = [];
            foreach ($strictReadyPlatforms as $platform) {
                if (!is_array($authorizationCandidates[$platform] ?? null)) {
                    $authorizationFailures[$platform] =
                        'canonical_daily_operation_authorization_missing';
                    continue;
                }
                try {
                    $candidate = $this->normalizeAuthorization(
                        $authorizationCandidates[$platform],
                        $expectedTenantId,
                        $expectedHotelId,
                        $platform
                    );
                } catch (\Throwable) {
                    $authorizationFailures[$platform] =
                        'canonical_daily_operation_authorization_missing';
                    continue;
                }
                try {
                    $resolved = ($this->authorizationResolver)(
                        $candidate,
                        $expectedTenantId,
                        $expectedHotelId,
                        $platform
                    );
                    if ($resolved !== $candidate) {
                        $authorizationFailures[$platform] =
                            'canonical_daily_operation_authorization_not_granted';
                        continue;
                    }
                    $resolvedAuthorizations[$platform] = $candidate;
                    $authorizedReadyPlatforms[] = $platform;
                } catch (\Throwable) {
                    $authorizationFailures[$platform] =
                        'canonical_daily_operation_authorization_not_granted';
                    continue;
                }
            }

            $existing = ($this->selectionResolver)(
                $expectedTenantId,
                $expectedHotelId,
                $targetDate,
                'historical_daily'
            );
            $existingStatus = strtolower(trim((string)($existing['status'] ?? 'none')));
            if (!in_array($existingStatus, ['none', 'selected'], true)) {
                throw new RuntimeException('canonical_daily_operation_owner_invalid');
            }
            if ($existingStatus === 'selected') {
                $ownerScope = is_array($existing['scope'] ?? null) ? $existing['scope'] : [];
                $ownerPlatform = strtolower(trim((string)($ownerScope['platform'] ?? '')));
                if (!isset($strictScopes[$ownerPlatform])
                    || $strictScopes[$ownerPlatform] !== $this->safeScope($ownerScope)
                    || !isset($resolvedAuthorizations[$ownerPlatform])
                ) {
                    throw new RuntimeException('canonical_daily_operation_owner_evidence_drift');
                }
                $scope = $strictScopes[$ownerPlatform];
                $authorization = $resolvedAuthorizations[$ownerPlatform];
                $selection = is_array($existing['selection_receipt'] ?? null)
                    ? $existing['selection_receipt']
                    : $existing;
            } else {
                foreach (self::PLATFORM_PRIORITY as $platform) {
                    if (isset($strictScopes[$platform], $resolvedAuthorizations[$platform])) {
                        $scope = $strictScopes[$platform];
                        $authorization = $resolvedAuthorizations[$platform];
                        break;
                    }
                }
                if ($scope === [] || $authorization === []) {
                    $reason = 'canonical_daily_operation_no_authorized_ready_platform';
                    if ($strictReadyPlatforms === []) {
                        foreach (self::PLATFORM_PRIORITY as $platform) {
                            if (isset($scopeFailures[$platform])) {
                                $reason = $scopeFailures[$platform];
                                break;
                            }
                        }
                    } else {
                        foreach ($strictReadyPlatforms as $platform) {
                            if (isset($authorizationFailures[$platform])) {
                                $reason = $authorizationFailures[$platform];
                                break;
                            }
                        }
                    }
                    throw new RuntimeException($reason);
                }
                $selection = $this->candidateSelection($scope, $strictReadyPlatforms, $authorizedReadyPlatforms);
            }
        } catch (\Throwable $exception) {
            return $this->blocked(
                $this->safeScopeReason($exception),
                'scope_validation',
                $scope,
                [],
                [
                    'strict_ready_platforms' => $strictReadyPlatforms,
                    'authorized_ready_platforms' => $authorizedReadyPlatforms,
                    'selection' => $selection,
                ]
            );
        }

        try {
            $draft = ($this->draftRunner)($scope);
            $draftState = $this->assertDraftSaved($draft, $scope);
        } catch (\Throwable) {
            return $this->blocked(
                'canonical_daily_operation_draft_save_blocked',
                'draft_save',
                $scope,
                [],
                [
                    'strict_ready_platforms' => $strictReadyPlatforms,
                    'authorized_ready_platforms' => $authorizedReadyPlatforms,
                    'selection' => $selection,
                ]
            );
        }

        try {
            $action = ($this->actionRunner)($scope, $authorization);
            $actionState = $this->assertActionsCompleted($action, $scope);
        } catch (\Throwable) {
            return $this->blocked(
                'canonical_daily_operation_draft_saved_action_blocked',
                'action_persist',
                $scope,
                $draftState,
                [
                    'strict_ready_platforms' => $strictReadyPlatforms,
                    'authorized_ready_platforms' => $authorizedReadyPlatforms,
                    'selection' => $selection,
                ]
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'verified',
            'analysis_status' => 'verified',
            'reason' => '',
            'stage' => 'completed',
            'scheduled_authority' => self::SCHEDULED_AUTHORITY,
            'authorization_plan_id' => $authorization['plan_id'],
            'authorization_digest' => $authorization['content_digest'],
            'authorized_at' => $authorization['authorized_at'],
            'scope' => $scope,
            'metric_scope' => 'ota_channel',
            'platform_scope' => $scope['platform'],
            'selected_platform' => $scope['platform'],
            'strict_ready_platforms' => $strictReadyPlatforms,
            'authorized_ready_platforms' => $authorizedReadyPlatforms,
            'selection_policy' => self::SELECTION_POLICY,
            'selection_policy_version' => self::SELECTION_POLICY_VERSION,
            'daily_platform_selection' => $actionState['daily_selection'] ?? $selection,
            'whole_hotel_conclusion_claimed' => false,
            'analysis_only' => true,
            'draft_count' => 4,
            'trusted_operational_check_count' => 4,
            'trusted_external_operation_count' => 0,
            'draft_idempotent' => $draftState['idempotent'],
            'action_idempotent' => $actionState['idempotent'],
            'idempotent' => $draftState['idempotent'] && $actionState['idempotent'],
            'draft_content_digest' => $draftState['content_digest'],
            'action_set_digest' => $actionState['action_set_digest'],
            'records' => $actionState['records'],
            'draft_readback_verified' => true,
            'db_readback_verified' => true,
            'operation_flow_readback_verified' => true,
            'effect_review_written' => false,
            'action_track_written' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<string,mixed> $collection
     * @param array<string,mixed> $finalization
     * @return array<string,mixed>
     */
    private function exactPlatformScope(
        array $collection,
        array $finalization,
        int $expectedTenantId,
        int $expectedHotelId,
        string $platform
    ): array {
        if (!in_array($platform, self::PLATFORM_PRIORITY, true)) {
            throw new RuntimeException('canonical_daily_operation_platform_invalid');
        }
        $hotelId = (int)($collection['hotel_id'] ?? 0);
        $targetDate = substr(trim((string)($collection['target_date'] ?? '')), 0, 10);
        $anchorHash = strtolower(trim((string)($collection['collection_anchor_hash'] ?? '')));
        if ($expectedTenantId <= 0
            || $expectedHotelId <= 0
            || $hotelId !== $expectedHotelId
            || !$this->validDate($targetDate)
            || (string)($collection['collection_anchor_contract_version'] ?? '')
                !== OtaCollectionAnchorService::CONTRACT_VERSION
            || !OtaCollectionAnchorService::matches(
                $collection['source_tasks'] ?? [],
                $anchorHash
            )
            || !in_array($platform, $this->platforms($collection['required_platforms'] ?? []), true)
        ) {
            throw new RuntimeException('canonical_daily_operation_collection_scope_invalid');
        }
        if ((int)($finalization['tenant_id'] ?? 0) !== $expectedTenantId
            || (int)($finalization['hotel_id'] ?? 0) !== $expectedHotelId
            || substr(trim((string)($finalization['target_date'] ?? '')), 0, 10) !== $targetDate
            || (string)($finalization['collection_anchor_contract_version'] ?? '')
                !== OtaCollectionAnchorService::CONTRACT_VERSION
            || !hash_equals(
                $anchorHash,
                strtolower(trim((string)($finalization['collection_anchor_hash'] ?? '')))
            )
            || !in_array($platform, $this->platforms($finalization['promoted_platforms'] ?? []), true)
        ) {
            throw new RuntimeException('canonical_daily_operation_finalization_scope_invalid');
        }

        $platformResult = is_array($finalization['platform_results'][$platform] ?? null)
            ? $finalization['platform_results'][$platform]
            : [];
        $promotion = is_array($platformResult['promotion'] ?? null)
            ? $platformResult['promotion']
            : [];
        $rowIds = $this->positiveIds($promotion['row_ids'] ?? []);
        if (strtolower(trim((string)($platformResult['status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($promotion['status'] ?? ''))) !== 'verified'
            || ($promotion['readback_verified'] ?? false) !== true
            || strtolower(trim((string)($promotion['history_status'] ?? ''))) !== 'success'
            || (int)($promotion['tenant_id'] ?? 0) !== $expectedTenantId
            || (int)($promotion['system_hotel_id'] ?? 0) !== $expectedHotelId
            || strtolower(trim((string)($promotion['platform'] ?? ''))) !== $platform
            || substr(trim((string)($promotion['target_date'] ?? '')), 0, 10) !== $targetDate
            || (int)($promotion['data_source_id'] ?? 0) <= 0
            || (int)($promotion['sync_task_id'] ?? 0) <= 0
            || $rowIds === []
            || !$this->isDigest((string)($promotion['promotion_receipt_digest'] ?? ''))
            || ($promotion['sensitive_values_exposed'] ?? true) !== false
        ) {
            throw new RuntimeException('canonical_daily_operation_' . $platform . '_promotion_invalid');
        }

        $selectedRowId = (int)($promotion['selected_operation_row_id'] ?? 0);
        $selectionVersion = trim((string)(
            $promotion['operation_row_selection_version'] ?? ''
        ));
        $selectionStatus = strtolower(trim((string)(
            $promotion['operation_row_selection_status'] ?? ''
        )));
        $selectionPolicy = trim((string)(
            $promotion['operation_row_selection_policy'] ?? ''
        ));
        $selectionDigest = strtolower(trim((string)(
            $promotion['operation_row_selection_digest'] ?? ''
        )));
        $candidateRowIds = $this->positiveIds($promotion['operation_row_candidate_ids'] ?? []);
        $metricDigests = $this->rowDigestMap(
            $promotion['operation_row_metric_digests'] ?? null,
            $rowIds
        );
        if (!$this->hasCompleteOperationRowSelection($promotion)
            || $selectionVersion !== self::OPERATION_ROW_SELECTION_VERSION
            || $selectionStatus !== 'ready'
            || $selectionPolicy !== self::OPERATION_ROW_SELECTION_POLICY
            || !$this->isDigest($selectionDigest)
            || $candidateRowIds !== $rowIds
            || $metricDigests === []
            || count(array_unique(array_values($metricDigests))) !== 1
            || !in_array($selectedRowId, $rowIds, true)
            || $selectedRowId !== min($rowIds)
            || !hash_equals($selectionDigest, $this->operationRowSelectionDigest([
                'version' => $selectionVersion,
                'status' => $selectionStatus,
                'policy' => $selectionPolicy,
                'platform' => $platform,
                'tenant_id' => $expectedTenantId,
                'system_hotel_id' => $expectedHotelId,
                'data_source_id' => (int)$promotion['data_source_id'],
                'sync_task_id' => (int)$promotion['sync_task_id'],
                'target_date' => $targetDate,
                'data_period' => 'historical_daily',
                'candidate_row_ids' => $candidateRowIds,
                'selected_row_id' => $selectedRowId,
                'row_metric_digests' => $metricDigests,
            ]))
        ) {
            throw new RuntimeException(
                'canonical_daily_operation_' . $platform . '_operation_row_ambiguous'
            );
        }

        $platformTasks = [];
        foreach (is_array($collection['source_tasks'] ?? null) ? $collection['source_tasks'] : [] as $task) {
            if (is_array($task) && strtolower(trim((string)($task['platform'] ?? ''))) === $platform) {
                $platformTasks[] = $task;
            }
        }
        if (count($platformTasks) !== 1) {
            throw new RuntimeException('canonical_daily_operation_' . $platform . '_task_ambiguous');
        }
        $task = $platformTasks[0];
        $taskRowIds = $this->positiveIds($task['row_ids'] ?? []);
        if ((int)($task['data_source_id'] ?? 0) !== (int)$promotion['data_source_id']
            || (int)($task['sync_task_id'] ?? 0) !== (int)$promotion['sync_task_id']
            || strtolower(trim((string)($task['historical_core_contract_status'] ?? ''))) !== 'ready'
            || array_diff($rowIds, $taskRowIds) !== []
            || !in_array($selectedRowId, $taskRowIds, true)
        ) {
            throw new RuntimeException('canonical_daily_operation_' . $platform . '_task_scope_mismatch');
        }

        $persistedPromotion = ($this->promotionReceiptResolver)(
            $expectedTenantId,
            $expectedHotelId,
            (int)$task['data_source_id'],
            (int)$task['sync_task_id'],
            $platform,
            $targetDate
        );
        if (!$this->trustedPromotionReceiptMatches(
            is_array($persistedPromotion) ? $persistedPromotion : [],
            $promotion,
            $expectedTenantId,
            $expectedHotelId,
            $platform,
            $targetDate,
            $anchorHash
        )) {
            throw new RuntimeException(
                'canonical_daily_operation_' . $platform . '_promotion_receipt_untrusted'
            );
        }

        return [
            'tenant_id' => $expectedTenantId,
            'hotel_id' => $expectedHotelId,
            'data_source_id' => (int)$promotion['data_source_id'],
            'task_id' => (int)$promotion['sync_task_id'],
            'row_id' => $selectedRowId,
            'platform' => $platform,
            'target_date' => $targetDate,
            'data_period' => 'historical_daily',
        ];
    }

    /** @return array<string,mixed> */
    private function loadPersistedPromotionReceipt(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        int $taskId,
        string $platform,
        string $targetDate
    ): array {
        $task = Db::name('platform_data_sync_tasks')
            ->field('stats_json')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', 'success')
            ->find();
        if (!is_array($task)) {
            return [];
        }
        try {
            $stats = json_decode(
                (string)($task['stats_json'] ?? ''),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($stats)
            || !is_array($stats['canonical_history_promotion'] ?? null)
        ) {
            return [];
        }
        $receipt = $stats['canonical_history_promotion'];
        return (int)($receipt['tenant_id'] ?? 0) === $tenantId
            && (int)($receipt['system_hotel_id'] ?? 0) === $hotelId
            && (int)($receipt['data_source_id'] ?? 0) === $sourceId
            && (int)($receipt['sync_task_id'] ?? 0) === $taskId
            && strtolower(trim((string)($receipt['platform'] ?? ''))) === $platform
            && substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) === $targetDate
                ? $receipt
                : [];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $promotion */
    private function trustedPromotionReceiptMatches(
        array $receipt,
        array $promotion,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $targetDate,
        string $anchorHash
    ): bool {
        $rowIds = $this->positiveIds($promotion['row_ids'] ?? []);
        $storedRowIds = $this->positiveIds($receipt['row_ids'] ?? []);
        $contentDigest = strtolower(trim((string)($receipt['content_digest'] ?? '')));
        $promotionDigest = strtolower(trim((string)(
            $promotion['promotion_receipt_digest'] ?? ''
        )));
        $storedMetricDigests = $this->rowDigestMap(
            $receipt['operation_row_metric_digests'] ?? null,
            $storedRowIds
        );
        $storedFactDigests = $this->rowDigestMap(
            $receipt['authoritative_row_fact_digests'] ?? null,
            $storedRowIds
        );
        $storedIdentityDigests = $this->rowDigestMap(
            $receipt['authoritative_row_platform_hotel_identity_digests'] ?? null,
            $storedRowIds
        );
        $selection = [
            'version' => trim((string)($receipt['operation_row_selection_version'] ?? '')),
            'status' => strtolower(trim((string)($receipt['operation_row_selection_status'] ?? ''))),
            'policy' => trim((string)($receipt['operation_row_selection_policy'] ?? '')),
            'platform' => $platform,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_source_id' => (int)($receipt['data_source_id'] ?? 0),
            'sync_task_id' => (int)($receipt['sync_task_id'] ?? 0),
            'target_date' => $targetDate,
            'data_period' => 'historical_daily',
            'candidate_row_ids' => $this->positiveIds(
                $receipt['operation_row_candidate_ids'] ?? []
            ),
            'selected_row_id' => (int)($receipt['selected_operation_row_id'] ?? 0),
            'row_metric_digests' => $storedMetricDigests,
        ];
        $selectionDigest = strtolower(trim((string)(
            $receipt['operation_row_selection_digest'] ?? ''
        )));
        $nonzeroRows = (int)($receipt['nonzero_required_metric_rows'] ?? -1);
        $explicitZeroRows = (int)($receipt['explicit_zero_confirmed_rows'] ?? -1);

        if ($receipt === []
            || !$this->hasCompleteOperationRowSelection($receipt)
            || (string)($receipt['version'] ?? '') !== self::PROMOTION_RECEIPT_VERSION
            || (int)($receipt['tenant_id'] ?? 0) !== $tenantId
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($receipt['platform'] ?? ''))) !== $platform
            || (string)($receipt['target_date'] ?? '') !== $targetDate
            || (string)($receipt['data_period'] ?? '') !== 'historical_daily'
            || (int)($receipt['data_source_id'] ?? 0) !== (int)($promotion['data_source_id'] ?? 0)
            || (int)($receipt['sync_task_id'] ?? 0) !== (int)($promotion['sync_task_id'] ?? 0)
            || $storedRowIds === []
            || ($receipt['row_ids'] ?? null) !== $storedRowIds
            || $storedRowIds !== $rowIds
            || (string)($receipt['collection_anchor_contract_version'] ?? '')
                !== OtaCollectionAnchorService::CONTRACT_VERSION
            || !hash_equals(
                $anchorHash,
                strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')))
            )
            || !$this->isDigest((string)($receipt['verifier_report_hash'] ?? ''))
            || !$this->isDigest((string)($receipt['authoritative_fact_digest'] ?? ''))
            || !$this->isDigest((string)($receipt['platform_hotel_identity_digest'] ?? ''))
            || $storedFactDigests === []
            || $storedIdentityDigests === []
            || $storedMetricDigests === []
            || $selection['version'] !== self::OPERATION_ROW_SELECTION_VERSION
            || $selection['status'] !== 'ready'
            || $selection['policy'] !== self::OPERATION_ROW_SELECTION_POLICY
            || $selection['candidate_row_ids'] !== $storedRowIds
            || !in_array($selection['selected_row_id'], $storedRowIds, true)
            || $selection['selected_row_id'] !== min($storedRowIds)
            || count(array_unique(array_values($storedMetricDigests))) !== 1
            || !$this->isDigest($selectionDigest)
            || !hash_equals($selectionDigest, $this->operationRowSelectionDigest($selection))
            || $nonzeroRows < 0
            || $explicitZeroRows < 0
            || $nonzeroRows + $explicitZeroRows !== count($storedRowIds)
            || strtolower(trim((string)(
                $receipt['observed_traffic_metric_provenance_status'] ?? ''
            ))) !== 'ready'
            || (int)($receipt['synthetic_normalization_provenance_missing_rows'] ?? -1) !== 0
            || ($receipt['sensitive_values_exposed'] ?? true) !== false
            || !$this->isDigest($contentDigest)
            || !hash_equals($contentDigest, $this->promotionReceiptDigest($receipt))
            || !hash_equals($contentDigest, $promotionDigest)
        ) {
            return false;
        }
        if (($promotion['row_ids'] ?? null) !== ($receipt['row_ids'] ?? null)
            || (string)($promotion['promotion_receipt_digest'] ?? '') !== $contentDigest
        ) {
            return false;
        }
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $field) {
            if (($promotion[$field] ?? null) !== ($receipt[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $value */
    private function hasCompleteOperationRowSelection(array $value): bool
    {
        foreach ([
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ] as $field) {
            if (!array_key_exists($field, $value)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $value */
    private function promotionReceiptDigest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $scope @return array<string,mixed> */
    private function assertDraftSaved(array $result, array $scope): array
    {
        $digest = strtolower(trim((string)($result['content_digest'] ?? '')));
        if (($result['status'] ?? '') !== 'saved'
            || ($result['execute'] ?? false) !== true
            || ($result['readback_verified'] ?? false) !== true
            || (int)($result['draft_count'] ?? 0) !== 4
            || !is_array($result['scope'] ?? null)
            || $result['scope'] !== $scope
            || !array_key_exists('idempotent', $result)
            || !is_bool($result['idempotent'])
            || !$this->isDigest($digest)
        ) {
            throw new RuntimeException('canonical_daily_operation_draft_receipt_invalid');
        }
        return [
            'idempotent' => $result['idempotent'],
            'content_digest' => $digest,
            'draft_count' => 4,
            'draft_readback_verified' => true,
        ];
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $scope @return array<string,mixed> */
    private function assertActionsCompleted(array $result, array $scope): array
    {
        $expectedActionTypes = CanonicalOtaInvestigationActionService::actionTypesForPlatform(
            (string)$scope['platform']
        );
        $digest = strtolower(trim((string)($result['action_set_digest'] ?? '')));
        $dailySelection = is_array($result['daily_platform_selection'] ?? null)
            ? $result['daily_platform_selection']
            : [];
        $records = [];
        $actionTypes = [];
        foreach (is_array($result['records'] ?? null) ? $result['records'] : [] as $record) {
            if (!is_array($record)) {
                throw new RuntimeException('canonical_daily_operation_action_receipt_invalid');
            }
            $normalized = [
                'intent_id' => (int)($record['intent_id'] ?? 0),
                'task_id' => (int)($record['task_id'] ?? 0),
                'evidence_id' => (int)($record['evidence_id'] ?? 0),
                'action_type' => trim((string)($record['action_type'] ?? '')),
            ];
            if ($normalized['intent_id'] <= 0
                || $normalized['task_id'] <= 0
                || $normalized['evidence_id'] <= 0
                || $normalized['action_type'] === ''
            ) {
                throw new RuntimeException('canonical_daily_operation_action_receipt_invalid');
            }
            $records[] = $normalized;
            $actionTypes[] = $normalized['action_type'];
        }
        if (($result['status'] ?? '') !== 'completed'
            || ($result['execute'] ?? false) !== true
            || !is_array($result['scope'] ?? null)
            || $result['scope'] !== $scope
            || !array_key_exists('idempotent', $result)
            || !is_bool($result['idempotent'])
            || (int)($result['trusted_operational_check_count'] ?? 0) !== 4
            || (int)($result['trusted_external_operation_count'] ?? -1) !== 0
            || ($result['db_readback_verified'] ?? false) !== true
            || ($result['operation_flow_readback_verified'] ?? false) !== true
            || ($result['effect_review_written'] ?? true) !== false
            || ($result['action_track_written'] ?? true) !== false
            || ($result['external_action_triggered'] ?? true) !== false
            || ($result['business_outcome_claimed'] ?? true) !== false
            || ($result['causality_claimed'] ?? true) !== false
            || !$this->isDigest($digest)
            || $actionTypes !== $expectedActionTypes
            || count(array_unique(array_column($records, 'intent_id'))) !== 4
            || count(array_unique(array_column($records, 'task_id'))) !== 4
            || count(array_unique(array_column($records, 'evidence_id'))) !== 4
            || !$this->dailySelectionReceiptMatches(
                $dailySelection,
                $scope,
                $records,
                $digest
            )
        ) {
            throw new RuntimeException('canonical_daily_operation_action_receipt_invalid');
        }
        return [
            'idempotent' => $result['idempotent'],
            'action_set_digest' => $digest,
            'records' => $records,
            'daily_selection' => $dailySelection,
        ];
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $scope
     * @param array<int,array<string,mixed>> $records
     */
    private function dailySelectionReceiptMatches(
        array $receipt,
        array $scope,
        array $records,
        string $actionSetDigest
    ): bool {
        $ownerScope = [
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'target_date' => (string)$scope['target_date'],
            'data_period' => (string)$scope['data_period'],
        ];
        $policy = [
            'name' => self::SELECTION_POLICY,
            'version' => self::SELECTION_POLICY_VERSION,
            'preference' => self::PLATFORM_PRIORITY,
            'sticky_after_claim' => true,
        ];
        $intentIds = array_map('intval', array_column($records, 'intent_id'));
        sort($intentIds, SORT_NUMERIC);
        $receiptIntentIds = array_map('intval', is_array($receipt['intent_ids'] ?? null)
            ? $receipt['intent_ids']
            : []);
        sort($receiptIntentIds, SORT_NUMERIC);
        $contentDigest = strtolower(trim((string)($receipt['content_digest'] ?? '')));
        return ($receipt['schema_version'] ?? '')
                === CanonicalOtaDailyPlatformSelectionService::SCHEMA_VERSION
            && ($receipt['status'] ?? '') === 'selected'
            && ($receipt['selection_policy'] ?? '') === self::SELECTION_POLICY
            && ($receipt['selection_policy_version'] ?? '') === self::SELECTION_POLICY_VERSION
            && hash_equals(
                $this->digest($policy),
                (string)($receipt['selection_policy_digest'] ?? '')
            )
            && ($receipt['owner_scope'] ?? null) === $ownerScope
            && hash_equals(
                $this->digest($ownerScope),
                (string)($receipt['owner_scope_digest'] ?? '')
            )
            && ($receipt['selected_platform'] ?? '') === $scope['platform']
            && ($receipt['scope'] ?? null) === $scope
            && $receiptIntentIds === $intentIds
            && count($intentIds) === 4
            && hash_equals($actionSetDigest, (string)($receipt['action_set_digest'] ?? ''))
            && in_array(
                (string)($receipt['owner_source'] ?? ''),
                ['intent_evidence', 'legacy_four_intent_inference'],
                true
            )
            && ($receipt['readback_verified'] ?? false) === true
            && $this->isDigest($contentDigest)
            && hash_equals($contentDigest, $this->digest($receipt));
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function normalizeAuthorization(
        array $authorization,
        int $expectedTenantId,
        int $expectedHotelId,
        string $expectedPlatform
    ): array {
        $normalized = [
            'schema_version' => trim((string)($authorization['schema_version'] ?? '')),
            'enabled' => ($authorization['enabled'] ?? false) === true,
            'plan_id' => strtolower(trim((string)($authorization['plan_id'] ?? ''))),
            'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
            'hotel_id' => (int)($authorization['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($authorization['platform'] ?? ''))),
            'trigger' => strtolower(trim((string)($authorization['trigger'] ?? ''))),
            'authorized_at' => trim((string)($authorization['authorized_at'] ?? '')),
            'authorized_by' => strtolower(trim((string)($authorization['authorized_by'] ?? ''))),
            'analysis_only' => ($authorization['analysis_only'] ?? false) === true,
            'operation_count' => (int)($authorization['operation_count'] ?? 0),
            'external_action_allowed' => ($authorization['external_action_allowed'] ?? true) === true,
        ];
        $digest = strtolower(trim((string)($authorization['content_digest'] ?? '')));
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized['authorized_at']);
        if ($normalized['schema_version'] !== CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION
            || $normalized['enabled'] !== true
            || preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $normalized['plan_id']) !== 1
            || $normalized['tenant_id'] !== $expectedTenantId
            || $normalized['hotel_id'] !== $expectedHotelId
            || !in_array($expectedPlatform, self::PLATFORM_PRIORITY, true)
            || $normalized['platform'] !== $expectedPlatform
            || $normalized['trigger'] !== 'historical_daily_canonical_promotion'
            || !($time instanceof \DateTimeImmutable)
            || $time->format('Y-m-d\TH:i:sP') !== $normalized['authorized_at']
            || $normalized['authorized_by'] !== 'user_goal'
            || $normalized['analysis_only'] !== true
            || $normalized['operation_count'] !== 4
            || $normalized['external_action_allowed'] !== false
            || !$this->isDigest($digest)
            || !hash_equals($digest, $this->digest($normalized))
        ) {
            throw new RuntimeException('canonical_daily_operation_authorization_missing');
        }
        $normalized['content_digest'] = $digest;
        return $normalized;
    }

    /** @param array<string,mixed> $value @return array<string,array<string,mixed>> */
    private function authorizationCandidates(array $value): array
    {
        $candidates = [];
        $singlePlatform = strtolower(trim((string)($value['platform'] ?? '')));
        if (in_array($singlePlatform, self::PLATFORM_PRIORITY, true)) {
            $candidates[$singlePlatform] = $value;
        }
        foreach (self::PLATFORM_PRIORITY as $platform) {
            if (is_array($value[$platform] ?? null)) {
                $candidates[$platform] = $value[$platform];
            }
        }
        return $candidates;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<int,string> $strictReadyPlatforms
     * @param array<int,string> $authorizedReadyPlatforms
     * @return array<string,mixed>
     */
    private function candidateSelection(
        array $scope,
        array $strictReadyPlatforms,
        array $authorizedReadyPlatforms
    ): array {
        $selection = [
            'schema_version' => 'canonical_ota_daily_platform_selection.v1',
            'status' => 'candidate',
            'selection_policy' => self::SELECTION_POLICY,
            'selection_policy_version' => self::SELECTION_POLICY_VERSION,
            'owner_scope' => $scope,
            'selected_platform' => (string)$scope['platform'],
            'strict_ready_platforms' => array_values($strictReadyPlatforms),
            'authorized_ready_platforms' => array_values($authorizedReadyPlatforms),
            'owner_recovered' => false,
        ];
        $selection['selection_digest'] = $this->digest($selection);
        return $selection;
    }

    /** @param array<string,mixed> $scope @param array<string,mixed> $draftState @return array<string,mixed> */
    private function blocked(
        string $reason,
        string $stage,
        array $scope = [],
        array $draftState = [],
        array $context = []
    ): array {
        $selection = is_array($context['selection'] ?? null) ? $context['selection'] : [];
        $selectedPlatform = strtolower(trim((string)(
            $scope['platform'] ?? $selection['selected_platform'] ?? ''
        )));
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'analysis_status' => 'blocked',
            'reason' => $reason,
            'stage' => $stage,
            'scheduled_authority' => self::SCHEDULED_AUTHORITY,
            'scope' => $this->safeScope($scope),
            'metric_scope' => 'ota_channel',
            'platform_scope' => $selectedPlatform,
            'selected_platform' => $selectedPlatform,
            'strict_ready_platforms' => $this->platforms(
                $context['strict_ready_platforms'] ?? []
            ),
            'authorized_ready_platforms' => $this->platforms(
                $context['authorized_ready_platforms'] ?? []
            ),
            'selection_policy' => self::SELECTION_POLICY,
            'selection_policy_version' => self::SELECTION_POLICY_VERSION,
            'daily_platform_selection' => $selection,
            'whole_hotel_conclusion_claimed' => false,
            'analysis_only' => true,
            'draft_count' => (int)($draftState['draft_count'] ?? 0),
            'trusted_operational_check_count' => 0,
            'trusted_external_operation_count' => 0,
            'draft_idempotent' => $draftState['idempotent'] ?? null,
            'action_idempotent' => null,
            'idempotent' => false,
            'draft_content_digest' => (string)($draftState['content_digest'] ?? ''),
            'action_set_digest' => '',
            'records' => [],
            'draft_readback_verified' => ($draftState['draft_readback_verified'] ?? false) === true,
            'db_readback_verified' => false,
            'operation_flow_readback_verified' => false,
            'effect_review_written' => false,
            'action_track_written' => false,
            'external_action_triggered' => false,
            'business_outcome_claimed' => false,
            'causality_claimed' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function notApplicable(string $reason): array
    {
        $receipt = $this->blocked($reason, 'not_applicable');
        $receipt['status'] = 'not_applicable';
        $receipt['analysis_status'] = 'not_applicable';
        return $receipt;
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function safeScope(array $scope): array
    {
        $safe = [];
        foreach (['tenant_id', 'hotel_id', 'data_source_id', 'task_id', 'row_id'] as $field) {
            if ((int)($scope[$field] ?? 0) > 0) {
                $safe[$field] = (int)$scope[$field];
            }
        }
        foreach (['platform', 'target_date', 'data_period'] as $field) {
            if (trim((string)($scope[$field] ?? '')) !== '') {
                $safe[$field] = trim((string)$scope[$field]);
            }
        }
        return $safe;
    }

    private function safeScopeReason(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if (preg_match('/^canonical_daily_operation_[a-z0-9_]{1,96}$/D', $message) === 1) {
            return $message;
        }
        return 'canonical_daily_operation_scope_validation_failed';
    }

    /** @return array<int,string> */
    private function platforms(mixed $value): array
    {
        $platforms = [];
        foreach (is_array($value) ? $value : [] as $platform) {
            $normalized = strtolower(trim((string)$platform));
            if (in_array($normalized, ['ctrip', 'meituan'], true)) {
                $platforms[$normalized] = true;
            }
        }
        $result = array_keys($platforms);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = [];
        foreach (is_array($value) ? $value : [] as $id) {
            $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($normalized !== false) {
                $ids[(int)$normalized] = true;
            }
        }
        $result = array_keys($ids);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @param array<int,int> $expectedRowIds @return array<int,string> */
    private function rowDigestMap(mixed $value, array $expectedRowIds): array
    {
        if (!is_array($value)) {
            return [];
        }
        $digests = [];
        foreach ($value as $rowId => $digest) {
            $normalizedRowId = filter_var($rowId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $normalizedDigest = strtolower(trim((string)$digest));
            if ($normalizedRowId === false
                || !$this->isDigest($normalizedDigest)
                || isset($digests[(int)$normalizedRowId])
            ) {
                return [];
            }
            $digests[(int)$normalizedRowId] = $normalizedDigest;
        }
        ksort($digests, SORT_NUMERIC);
        return array_keys($digests) === $expectedRowIds ? $digests : [];
    }

    /** @param array<string,mixed> $value */
    private function operationRowSelectionDigest(array $value): string
    {
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
