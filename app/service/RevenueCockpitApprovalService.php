<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Binds the exact visible revenue-cockpit scope to one operating lifecycle.
 * Creation stops at pending approval; restoration is strictly read-only.
 */
final class RevenueCockpitApprovalService
{
    public const CONTRACT_VERSION = 'revenue_cockpit_operation_action.v1';
    public const SOURCE_MODULE = 'revenue_cockpit_action';

    /** @var Closure(int,int,string,int,array<int,array<string,mixed>>):array<string,mixed> */
    private ?Closure $creator;

    /** @var Closure(int,int,string,array<int,array<string,mixed>>):(?array) */
    private ?Closure $reader;

    public function __construct(?callable $creator = null, ?callable $reader = null)
    {
        $this->creator = $creator !== null ? Closure::fromCallable($creator) : null;
        $this->reader = $reader !== null ? Closure::fromCallable($reader) : null;
    }

    /** @return array<string,mixed> */
    public function createFromOverview(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        int $actorId,
        array $actionContext = []
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('revenue_cockpit_approval_scope_invalid');
        }
        $context = $this->evidenceContext(
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform
        );
        $context['action_context'] = $this->normalizeActionContext($actionContext);
        $payload = $this->creator !== null
            ? ($this->creator)(
                $tenantId,
                $hotelId,
                $context['business_date'],
                $actorId,
                $context['evidence_refs']
            )
            : $this->createManagedAction($overview, $context, $actorId);
        if ((string)($payload['persistence_status'] ?? '') !== 'readback_verified'
            || ($payload['external_action_triggered'] ?? true) !== false
        ) {
            throw new RuntimeException('revenue_cockpit_approval_readback_invalid');
        }
        $intent = is_array($payload['execution_intent'] ?? null)
            ? $payload['execution_intent']
            : [];
        $tasks = is_array($intent['tasks'] ?? null)
            ? array_values($intent['tasks'])
            : null;
        $taskCount = (int)($payload['execution_task_count'] ?? ($tasks === null ? -1 : count($tasks)));
        if ((string)($payload['status'] ?? '') !== 'pending_approval'
            || (string)($intent['status'] ?? '') !== 'pending_approval'
            || $tasks === null
            || $tasks !== []
            || $taskCount !== 0
            || ($payload['execution_task_created'] ?? true) !== false
        ) {
            throw new RuntimeException('revenue_cockpit_action_lifecycle_already_progressed', 409);
        }
        $payload['found'] = true;
        $payload['cockpit_scope'] = $context['cockpit_scope'];
        $payload['boundaries'] = $this->boundaries(false, $taskCount);
        return $payload;
    }

    /**
     * Returns the current lifecycle for the exact current cockpit facts.
     * A missing identity is a truthful not-saved result, not an empty intent.
     *
     * @return array<string,mixed>
     */
    public function readFromOverview(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        $context = $this->evidenceContext(
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform
        );
        if ($this->reader !== null) {
            $intent = ($this->reader)(
                $tenantId,
                $hotelId,
                $context['business_date'],
                $context['evidence_refs']
            );
            if ($intent === null) {
                return [
                    'found' => false,
                    'status' => 'not_saved',
                    'execution_intent' => null,
                    'execution_intents' => [],
                    'persistence_status' => 'not_saved',
                    'execution_task_count' => 0,
                    'execution_intent_count' => 0,
                    'cockpit_scope' => $context['cockpit_scope'],
                    'boundaries' => $this->boundaries(true, 0),
                ];
            }
            $intent = $this->assertRestoredIntentScope(
                $intent,
                $tenantId,
                $hotelId,
                $context['business_date']
            );
            $taskCount = count($intent['tasks']);
            return [
                'found' => true,
                'status' => (string)$intent['status'],
                'execution_intent' => $intent,
                'execution_intents' => [$intent],
                'persistence_status' => 'readback_verified',
                'execution_task_count' => $taskCount,
                'execution_intent_count' => 1,
                'cockpit_scope' => $context['cockpit_scope'],
                'boundaries' => $this->boundaries(true, $taskCount),
            ];
        }

        $intents = $this->readManagedActions($overview, $context);
        if ($intents === []) {
            return [
                'found' => false,
                'status' => 'not_saved',
                'execution_intent' => null,
                'execution_intents' => [],
                'persistence_status' => 'not_saved',
                'execution_task_count' => 0,
                'execution_intent_count' => 0,
                'cockpit_scope' => $context['cockpit_scope'],
                'boundaries' => $this->boundaries(true, 0),
            ];
        }

        $intent = $intents[0];
        $taskCount = count($intent['tasks']);
        $factIntegrityStatus = (string)($intent['fact_integrity_status'] ?? 'verified');
        return [
            'found' => true,
            'status' => (string)$intent['status'],
            'execution_intent' => $intent,
            'execution_intents' => $intents,
            'persistence_status' => 'readback_verified',
            'execution_task_count' => $taskCount,
            'execution_intent_count' => count($intents),
            'fact_integrity_status' => $factIntegrityStatus,
            'approval_blocked' => $factIntegrityStatus !== 'verified',
            'cockpit_scope' => $context['cockpit_scope'],
            'boundaries' => $this->boundaries(true, $taskCount),
        ];
    }

    /**
     * @return array{business_date:string,evidence_refs:list<array<string,mixed>>,cockpit_scope:array<string,mixed>}
     */
    public function evidenceContext(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('revenue_cockpit_approval_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_cockpit_approval_platform_invalid');
        }

        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $hotel = is_array($factLayer['hotel'] ?? null) ? $factLayer['hotel'] : [];
        if ((int)($overview['hotel_id'] ?? $hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($overview['business_date'] ?? '') !== $businessDate
            || (string)($factLayer['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('revenue_cockpit_approval_overview_scope_mismatch', 422);
        }
        $sources = is_array($factLayer['sources'] ?? null) ? $factLayer['sources'] : [];
        $strictEvidence = is_array($overview['cockpit_strict_evidence'] ?? null)
            ? $overview['cockpit_strict_evidence']
            : [];
        if ((string)($strictEvidence['contract_version'] ?? '') !== 'revenue_cockpit_strict_evidence.v1'
            || (int)($strictEvidence['tenant_id'] ?? 0) !== $tenantId
            || (int)($strictEvidence['hotel_id'] ?? 0) !== $hotelId
            || (string)($strictEvidence['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('revenue_cockpit_strict_evidence_missing', 422);
        }

        $dualClosure = is_array($overview['dual_ota_field_closure'] ?? null)
            ? $overview['dual_ota_field_closure']
            : [];
        $closureDigest = strtolower(trim((string)($dualClosure['closure_digest'] ?? '')));
        if ((string)($dualClosure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (int)($dualClosure['tenant_id'] ?? 0) !== $tenantId
            || (int)($dualClosure['hotel_id'] ?? 0) !== $hotelId
            || (string)($dualClosure['business_date'] ?? '') !== $businessDate
            || preg_match('/^[a-f0-9]{64}$/D', $closureDigest) !== 1
        ) {
            throw new RuntimeException('revenue_cockpit_dual_ota_current_receipt_scope_invalid', 422);
        }

        $strictPlatforms = is_array($strictEvidence['platforms'] ?? null)
            ? $strictEvidence['platforms']
            : [];
        $selectedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $refs = [];
        foreach ($selectedPlatforms as $selectedPlatform) {
            $sourceKey = $selectedPlatform . '_ota';
            $source = is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [];
            $provenance = is_array($source['source'] ?? null) ? $source['source'] : [];
            $strictPlatform = is_array($strictPlatforms[$selectedPlatform] ?? null)
                ? $strictPlatforms[$selectedPlatform]
                : [];
            $closurePlatform = is_array($dualClosure['platforms'][$selectedPlatform] ?? null)
                ? $dualClosure['platforms'][$selectedPlatform]
                : [];
            $rowIds = $this->positiveIds($strictPlatform['accepted_row_ids'] ?? []);
            $provenanceRowIds = $this->positiveIds($provenance['row_ids'] ?? []);
            $currentReceiptRowIds = $this->positiveIds(
                $closurePlatform['current_receipt_record_ids'] ?? []
            );
            if ((string)($source['data_status'] ?? '') !== 'readback_verified'
                || (string)($source['business_date'] ?? '') !== $businessDate
                || (string)($source['actual_business_date'] ?? '') !== $businessDate
                || (string)($provenance['table'] ?? '') !== 'online_daily_data'
                || (string)($provenance['data_date'] ?? '') !== $businessDate
                || (string)($provenance['platform'] ?? '') !== $selectedPlatform
                || (string)($provenance['readback_status'] ?? '') !== 'readback_verified'
                || ($strictPlatform['source_strict_readback'] ?? false) !== true
                || $rowIds === []
            ) {
                throw new RuntimeException(
                    'revenue_cockpit_' . $selectedPlatform . '_evidence_not_readback_verified',
                    422
                );
            }
            if ((string)($closurePlatform['status'] ?? '') !== 'ready'
                || (string)($closurePlatform['revenue_analysis']['status'] ?? '') !== 'ready'
                || ($closurePlatform['current_collection_blocker_status'] ?? null) !== null
                || $currentReceiptRowIds === []
                || array_diff($rowIds, $currentReceiptRowIds) !== []
            ) {
                throw new RuntimeException(
                    'revenue_cockpit_' . $selectedPlatform . '_current_receipt_not_ready',
                    422
                );
            }
            $refs[] = [
                'role' => 'supporting_fact',
                'source_kind' => 'formal_record',
                'table' => 'online_daily_data',
                'row_ids' => $rowIds,
                'platform' => $selectedPlatform,
                'business_date' => $businessDate,
                'fact_scope' => 'ota_channel',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
                'fact_content_digest' => $this->digest([
                    'source_key' => $sourceKey,
                    'data_status' => (string)($source['data_status'] ?? ''),
                    'business_date' => (string)($source['business_date'] ?? ''),
                    'actual_business_date' => (string)($source['actual_business_date'] ?? ''),
                    'source' => [
                        'table' => (string)($provenance['table'] ?? ''),
                        'data_date' => (string)($provenance['data_date'] ?? ''),
                        'platform' => (string)($provenance['platform'] ?? ''),
                        'row_ids' => $provenanceRowIds,
                        'readback_status' => (string)($provenance['readback_status'] ?? ''),
                    ],
                    'facts' => is_array($source['facts'] ?? null) ? $source['facts'] : [],
                    'fact_statuses' => is_array($source['fact_statuses'] ?? null) ? $source['fact_statuses'] : [],
                    'strict_evidence' => $strictPlatform,
                ]),
            ];
            $refs[] = [
                'role' => 'current_collection_receipt',
                'source_kind' => 'formal_record',
                'table' => 'online_daily_data',
                'row_ids' => $currentReceiptRowIds,
                'platform' => $selectedPlatform,
                'business_date' => $businessDate,
                'fact_scope' => 'ota_current_collection_receipt',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
                'fact_content_digest' => $this->digest([
                    'closure_digest' => $closureDigest,
                    'platform' => $selectedPlatform,
                    'business_date' => $businessDate,
                    'current_receipt_record_ids' => $currentReceiptRowIds,
                    'latest_collection' => is_array($closurePlatform['latest_collection'] ?? null)
                        ? $closurePlatform['latest_collection']
                        : [],
                    'revenue_analysis' => is_array($closurePlatform['revenue_analysis'] ?? null)
                        ? $closurePlatform['revenue_analysis']
                        : [],
                ]),
            ];
        }

        $pms = is_array($sources['dingdandao_pms'] ?? null) ? $sources['dingdandao_pms'] : [];
        $pmsProvenance = is_array($pms['source'] ?? null) ? $pms['source'] : [];
        $pmsRecordId = (int)($pmsProvenance['record_id'] ?? 0);
        if ((string)($pms['data_status'] ?? '') === 'readback_verified'
            && (string)($pms['business_date'] ?? '') === $businessDate
            && (string)($pms['actual_business_date'] ?? '') === $businessDate
            && (string)($pmsProvenance['table'] ?? '') === 'dingdandao_operating_target_captures'
            && (string)($pmsProvenance['data_date'] ?? '') === $businessDate
            && (string)($pmsProvenance['readback_status'] ?? '') === 'readback_verified'
            && $pmsRecordId > 0
        ) {
            $refs[] = [
                'role' => 'supporting_fact',
                'source_kind' => 'formal_record',
                'table' => 'dingdandao_operating_target_captures',
                'row_ids' => [$pmsRecordId],
                'platform' => 'dingdandao_pms',
                'business_date' => $businessDate,
                'fact_scope' => 'whole_hotel_accommodation',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
                'fact_content_digest' => $this->digest([
                    'source_key' => 'dingdandao_pms',
                    'data_status' => (string)($pms['data_status'] ?? ''),
                    'business_date' => (string)($pms['business_date'] ?? ''),
                    'actual_business_date' => (string)($pms['actual_business_date'] ?? ''),
                    'source' => [
                        'table' => (string)($pmsProvenance['table'] ?? ''),
                        'record_id' => $pmsRecordId,
                        'data_date' => (string)($pmsProvenance['data_date'] ?? ''),
                        'readback_status' => (string)($pmsProvenance['readback_status'] ?? ''),
                    ],
                    'facts' => is_array($pms['facts'] ?? null) ? $pms['facts'] : [],
                    'fact_statuses' => is_array($pms['fact_statuses'] ?? null) ? $pms['fact_statuses'] : [],
                ]),
            ];
        }

        $hasWholeHotelPmsRef = count(array_filter(
            $refs,
            static fn(array $ref): bool => (string)($ref['fact_scope'] ?? '') === 'whole_hotel_accommodation'
        )) > 0;
        return [
            'business_date' => $businessDate,
            'evidence_refs' => $refs,
            'cockpit_scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'platform' => $platform,
                'source_scope' => $hasWholeHotelPmsRef
                    ? 'pms_whole_hotel_accommodation_plus_selected_ota_channels'
                    : 'selected_ota_channels_only',
                'evidence_ref_count' => count($refs),
            ],
        ];
    }

    /** @param array<string,mixed> $overview @param array<string,mixed> $context @return array<string,mixed> */
    private function createManagedAction(array $overview, array $context, int $actorId): array
    {
        $card = $this->managedActionCard($overview, $context, $actorId);
        $lifecycle = new OperationActionLifecycleService();
        $operations = new OperationManagementService();
        $hotelId = (int)($context['cockpit_scope']['hotel_id'] ?? 0);
        $equivalentId = $lifecycle->findEquivalentIntentId($card);
        if ($equivalentId !== null) {
            $intent = $operations->readExecutionIntent($equivalentId, [$hotelId]);
            $intent = $this->assertManagedIntentReadback($intent, $overview, $context, false);
            return $this->managedEnvelope($intent, true);
        }

        $metric = (string)$card['metric_contract']['metric_key'];
        $baselineValue = (float)$card['metric_contract']['baseline_window']['value'];
        $target = $lifecycle->alignManualTaskProjection([], $card);
        $evidence = [
            'contract_version' => self::CONTRACT_VERSION,
            'source_policy' => 'strict_revenue_fact_then_explicit_human_confirmation',
            'fact_snapshot_digest' => (string)($card['trace']['cockpit_identity_digest'] ?? ''),
            'evidence_refs' => (array)($context['metric_evidence_refs'] ?? []),
            'source_refs' => (array)($card['fact_refs'] ?? []),
            'action_card' => $card,
            'workflow_schedule' => (array)($target['workflow_schedule'] ?? []),
            'boundaries' => [
                'human_approval_required' => true,
                'automatic_collection' => false,
                'automatic_approval' => false,
                'automatic_execution' => false,
                'automatic_ota_write' => false,
                'external_message' => false,
                'causality_claimed' => false,
            ],
        ];
        $input = [
            'source_module' => self::SOURCE_MODULE,
            'source_record_id' => (int)$card['source']['record_id'],
            'hotel_id' => $hotelId,
            'platform' => (string)$card['source']['platform'],
            'object_type' => 'operation_checklist',
            'action_type' => 'human_reviewed_operating_check',
            'date_start' => (string)$card['business_window']['date_start'],
            'date_end' => (string)$card['business_window']['date_end'],
            'current_value' => [
                'fact_snapshot_digest' => (string)$evidence['fact_snapshot_digest'],
                'metric_baseline' => (array)$card['metric_contract']['baseline_window'],
                $metric => $baselineValue,
            ],
            'target_value' => $target,
            'evidence' => $evidence,
            'expected_metric' => $metric,
            'expected_delta' => null,
            'risk_level' => (string)$card['risk']['level'],
            'status' => 'pending_approval',
        ];
        $idempotencyKey = 'operation_action_' . substr($lifecycle->actionIdentityDigest($card), 0, 32);
        try {
            $intent = $operations->createExecutionIntent(
                [$hotelId],
                $hotelId,
                $input,
                $actorId,
                false,
                $idempotencyKey,
                true
            );
        } catch (\Throwable $exception) {
            // A concurrent cross-entry create can win the shared semantic
            // identity. Re-read that exact lifecycle instead of creating a copy.
            $equivalentId = $lifecycle->findEquivalentIntentId($card);
            if ($equivalentId === null) {
                throw $exception;
            }
            $intent = $operations->readExecutionIntent($equivalentId, [$hotelId]);
        }
        $intent = $this->assertManagedIntentReadback($intent, $overview, $context, true);
        return $this->managedEnvelope($intent, (bool)($intent['idempotent_replay'] ?? false));
    }

    /** @param array<string,mixed> $overview @param array<string,mixed> $context */
    private function readManagedAction(array $overview, array $context): ?array
    {
        $card = $this->managedActionCard($overview, $context, 1);
        $intentId = (new OperationActionLifecycleService())->findEquivalentIntentId($card);
        if ($intentId === null) {
            return null;
        }
        return (new OperationManagementService())->readExecutionIntent(
            $intentId,
            [(int)$context['cockpit_scope']['hotel_id']]
        );
    }

    /** @return list<array<string,mixed>> */
    private function readManagedActions(array $overview, array $context): array
    {
        $scope = (array)$context['cockpit_scope'];
        $hotelId = (int)($scope['hotel_id'] ?? 0);
        $intentIds = (new OperationActionLifecycleService())->findManagedIntentIdsForScope(
            (int)($scope['tenant_id'] ?? 0),
            $hotelId,
            (string)($scope['platform'] ?? ''),
            (string)($scope['business_date'] ?? ''),
            (string)($scope['business_date'] ?? '')
        );
        $operations = new OperationManagementService();
        $intents = [];
        foreach ($intentIds as $intentId) {
            $intent = $operations->readExecutionIntent($intentId, [$hotelId]);
            $this->assertIntentMatchesCockpitScope($intent, $context);
            try {
                $intent = $this->assertManagedIntentReadback($intent, $overview, $context, false);
            } catch (\Throwable $exception) {
                $intent['fact_integrity_status'] = 'drifted';
                $intent['fact_integrity_failure_reason'] = $exception->getMessage();
            }
            $intents[] = $intent;
        }
        return $intents;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function managedEnvelope(array $intent, bool $reused): array
    {
        $tasks = is_array($intent['tasks'] ?? null) ? array_values($intent['tasks']) : [];
        return [
            'status' => (string)($intent['status'] ?? ''),
            'execution_intent' => $intent,
            'reused_existing_intent' => $reused,
            'persistence_status' => 'readback_verified',
            'execution_task_created' => count($tasks) > 0,
            'execution_task_count' => count($tasks),
            'external_action_triggered' => false,
            'source_policy' => 'managed_action_lifecycle_no_automatic_external_action',
        ];
    }

    /**
     * @param array<string,mixed> $overview
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function managedActionCard(array $overview, array &$context, int $ownerId): array
    {
        $metric = $this->observationMetricContext($overview, $context);
        $context['metric_evidence_refs'] = $metric['metric_evidence_refs'];
        $scope = (array)$context['cockpit_scope'];
        $actionContext = is_array($context['action_context'] ?? null)
            ? $context['action_context']
            : [];
        return (new OperationActionLifecycleService())->buildRevenueCockpitObservationCard([
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'source_record_id' => (int)$metric['source_record_id'],
            'source_module' => self::SOURCE_MODULE,
            'platform' => (string)$scope['platform'],
            'business_date' => (string)$scope['business_date'],
            'metric_key' => (string)$metric['metric_key'],
            'metric_unit' => (string)$metric['metric_unit'],
            'metric_value' => $metric['metric_value'],
            'metric_rows' => (array)$metric['metric_rows'],
            'fact_refs' => (array)$metric['fact_refs'],
            'fact_snapshot_digest' => (string)$metric['fact_snapshot_digest'],
            'action_title' => (string)($actionContext['action_title']
                ?? ('复核并跟进' . (string)$metric['metric_label'])),
            'action_object' => (string)($actionContext['action_object']
                ?? ((string)$scope['platform'] . ':' . (string)$metric['metric_key'])),
            'action_description' => (string)($actionContext['action_description']
                ?? '由负责人核对该收益事实，记录实际人工处理与回执，并在复盘日重新读取同酒店、同平台、同指标事实。'),
            'reason' => (string)($actionContext['reason']
                ?? '当前严格回读收益事实需要进入人工运营跟进与同口径复盘。'),
            'opportunity_key' => (string)($actionContext['opportunity_key'] ?? ''),
            'opportunity_digest' => (string)($actionContext['opportunity_digest'] ?? ''),
            'decision_snapshot_id' => (int)($actionContext['decision_snapshot_id'] ?? 0),
            'decision_snapshot_digest' => (string)($actionContext['decision_snapshot_digest'] ?? ''),
        ], max(1, $ownerId));
    }

    /** @param array<string,mixed> $overview @param array<string,mixed> $context @return array<string,mixed> */
    private function observationMetricContext(array $overview, array $context): array
    {
        $scope = (array)$context['cockpit_scope'];
        $platform = (string)$scope['platform'];
        $businessDate = (string)$scope['business_date'];
        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $sources = is_array($factLayer['sources'] ?? null) ? $factLayer['sources'] : [];
        $strict = is_array($overview['cockpit_strict_evidence']['platforms'] ?? null)
            ? $overview['cockpit_strict_evidence']['platforms']
            : [];
        $platforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $actionContext = is_array($context['action_context'] ?? null)
            ? $context['action_context']
            : [];
        $priorities = $this->metricPrioritiesForOpportunity(
            (string)($actionContext['opportunity_key'] ?? ''),
            $platform
        );
        $forcedMetric = strtolower(trim((string)($context['forced_metric_key'] ?? '')));
        if ($forcedMetric !== '') {
            array_unshift($priorities, $forcedMetric);
            $priorities = array_values(array_unique($priorities));
        }
        foreach ($priorities as $metricKey) {
            $rowIdsByPlatform = [];
            $ready = true;
            foreach ($platforms as $selectedPlatform) {
                $metricEvidence = is_array($strict[$selectedPlatform]['metrics'][$metricKey] ?? null)
                    ? $strict[$selectedPlatform]['metrics'][$metricKey]
                    : [];
                $rowIds = $this->positiveIds($metricEvidence['accepted_row_ids'] ?? []);
                if (($metricEvidence['strict_readback'] ?? false) !== true || $rowIds === []) {
                    $ready = false;
                    break;
                }
                $rowIdsByPlatform[$selectedPlatform] = $rowIds;
            }
            if (!$ready) {
                continue;
            }
            $facts = $platform === 'all_ota'
                ? (is_array($factLayer['facts']['ota_channel']['combined'] ?? null)
                    ? $factLayer['facts']['ota_channel']['combined'] : [])
                : (is_array($sources[$platform . '_ota']['facts'] ?? null)
                    ? $sources[$platform . '_ota']['facts'] : []);
            $metricValue = $facts[$metricKey] ?? null;
            if (!is_numeric($metricValue)) {
                continue;
            }
            $unit = $this->metricUnit($metricKey, $platform);
            $rows = [];
            $refs = [];
            $metricEvidenceRefs = [];
            foreach ($rowIdsByPlatform as $selectedPlatform => $rowIds) {
                $metricEvidenceRefs[] = [
                    'role' => 'baseline_metric_fact',
                    'source_kind' => 'formal_record',
                    'table' => 'online_daily_data',
                    'row_ids' => $rowIds,
                    'platform' => $selectedPlatform,
                    'business_date' => $businessDate,
                    'metric_key' => $metricKey,
                    'fact_scope' => 'ota_channel',
                    'readback_verified' => true,
                    'verification_status' => 'readback_verified',
                ];
                foreach ($rowIds as $rowId) {
                    $ref = 'online_daily_data#' . $rowId;
                    $refs[] = $ref;
                    $rows[] = [
                        'ref' => $ref,
                        'platform' => $selectedPlatform,
                        'business_date' => $businessDate,
                        'metric' => $metricKey,
                        'value' => null,
                        'unit' => $unit,
                    ];
                }
            }
            sort($refs, SORT_STRING);
            $snapshot = [
                'contract_version' => self::CONTRACT_VERSION,
                'tenant_id' => (int)$scope['tenant_id'],
                'hotel_id' => (int)$scope['hotel_id'],
                'platform' => $platform,
                'business_date' => $businessDate,
                'metric_key' => $metricKey,
                'metric_unit' => $unit,
                'metric_value' => round((float)$metricValue, 6),
                'fact_refs' => $refs,
            ];
            $identitySeed = $snapshot;
            unset($identitySeed['metric_value'], $identitySeed['fact_refs']);
            return [
                'metric_key' => $metricKey,
                'metric_label' => $this->metricLabel($metricKey),
                'metric_unit' => $unit,
                'metric_value' => round((float)$metricValue, 6),
                'metric_rows' => $rows,
                'metric_evidence_refs' => $metricEvidenceRefs,
                'fact_refs' => $refs,
                'fact_snapshot_digest' => $this->digest($snapshot),
                'source_record_id' => (hexdec(substr($this->digest($identitySeed), 0, 7)) % 2147483646) + 1,
            ];
        }
        throw new RuntimeException('revenue_cockpit_no_same_criterion_metric_for_action', 422);
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $overview @param array<string,mixed> $context @return array<string,mixed> */
    private function assertManagedIntentReadback(
        array $intent,
        array $overview,
        array $context,
        bool $requireCurrent
    ): array {
        if ((int)($intent['id'] ?? 0) <= 0 || !is_array($intent['tasks'] ?? null)) {
            throw new RuntimeException('revenue_cockpit_restore_lifecycle_invalid', 409);
        }
        $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $storedCard = is_array($target['action_card'] ?? null)
            ? $target['action_card']
            : (is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : []);
        $context['forced_metric_key'] = (string)($storedCard['metric_contract']['metric_key'] ?? '');
        $context['action_context'] = $this->storedActionContext($storedCard);
        $expectedCard = $this->managedActionCard($overview, $context, max(1, (int)($intent['created_by'] ?? 1)));
        $lifecycle = new OperationActionLifecycleService();
        $lifecycle->assertEquivalentActionIdentity($expectedCard, $storedCard);
        if ($requireCurrent) {
            $this->assertDecisionSnapshotLineageCurrent($storedCard, $overview, $context);
        }
        $storedSnapshot = strtolower(trim((string)($storedCard['trace']['cockpit_identity_digest'] ?? '')));
        $expectedSnapshot = strtolower(trim((string)($expectedCard['trace']['cockpit_identity_digest'] ?? '')));
        $sameSnapshot = preg_match('/^[a-f0-9]{64}$/D', $storedSnapshot) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $expectedSnapshot) === 1
            && hash_equals($storedSnapshot, $expectedSnapshot);
        if (!$sameSnapshot && (string)($intent['source_module'] ?? '') === OperatingQuestionExecutionBridgeService::SOURCE_MODULE) {
            $sameSnapshot = (new OperatingQuestionExecutionBridgeService())->isIntentCurrent($intent)
                && $this->sameBaselineFact($storedCard, $expectedCard);
        }
        if ($requireCurrent && !$sameSnapshot) {
            throw new InvalidArgumentException('收益行动原始事实已漂移，请关闭当前写入并刷新核对');
        }
        if ($requireCurrent) {
            $lifecycle->assertPendingCardCurrent($intent);
        }
        $intent['fact_integrity_status'] = $sameSnapshot ? 'verified' : 'drifted';
        return $intent;
    }

    /** @param array<string,mixed> $card @param array<string,mixed> $overview @param array<string,mixed> $context */
    private function assertDecisionSnapshotLineageCurrent(array $card, array $overview, array $context): void
    {
        $trace = is_array($card['trace'] ?? null) ? $card['trace'] : [];
        $snapshotId = max(0, (int)($trace['decision_snapshot_id'] ?? 0));
        $snapshotDigest = strtolower(trim((string)($trace['decision_snapshot_digest'] ?? '')));
        $opportunityKey = trim((string)($trace['opportunity_key'] ?? ''));
        $opportunityDigest = strtolower(trim((string)($trace['opportunity_digest'] ?? '')));
        $hasAnyLineage = $snapshotId > 0
            || $snapshotDigest !== ''
            || $opportunityKey !== ''
            || $opportunityDigest !== '';
        if (!$hasAnyLineage) {
            return;
        }
        $scope = is_array($context['cockpit_scope'] ?? null) ? $context['cockpit_scope'] : [];
        (new RevenueDecisionSnapshotService())->assertOpportunityCurrent(
            $snapshotId,
            (int)($scope['tenant_id'] ?? 0),
            (int)($scope['hotel_id'] ?? 0),
            (string)($scope['business_date'] ?? ''),
            (string)($scope['platform'] ?? ''),
            $snapshotDigest,
            $opportunityKey,
            $opportunityDigest,
            $overview
        );
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $context */
    private function assertIntentMatchesCockpitScope(array $intent, array $context): void
    {
        $scope = (array)$context['cockpit_scope'];
        $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $card = is_array($target['action_card'] ?? null)
            ? $target['action_card']
            : (is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : []);
        if ((int)($intent['tenant_id'] ?? 0) !== (int)($scope['tenant_id'] ?? 0)
            || (int)($intent['hotel_id'] ?? 0) !== (int)($scope['hotel_id'] ?? 0)
            || strtolower(trim((string)($intent['platform'] ?? ''))) !== (string)($scope['platform'] ?? '')
            || substr(trim((string)($intent['date_start'] ?? '')), 0, 10) !== (string)($scope['business_date'] ?? '')
            || substr(trim((string)($intent['date_end'] ?? '')), 0, 10) !== (string)($scope['business_date'] ?? '')
            || (int)($card['hotel']['tenant_id'] ?? 0) !== (int)($scope['tenant_id'] ?? 0)
            || (int)($card['hotel']['hotel_id'] ?? 0) !== (int)($scope['hotel_id'] ?? 0)
            || strtolower(trim((string)($card['source']['platform'] ?? ''))) !== (string)($scope['platform'] ?? '')
            || substr(trim((string)($card['business_window']['date_start'] ?? '')), 0, 10) !== (string)($scope['business_date'] ?? '')
            || substr(trim((string)($card['business_window']['date_end'] ?? '')), 0, 10) !== (string)($scope['business_date'] ?? '')
            || !is_array($intent['tasks'] ?? null)
        ) {
            throw new RuntimeException('revenue_cockpit_restore_scope_drift', 409);
        }
    }

    /** @param array<string,mixed> $card @return array<string,mixed> */
    private function storedActionContext(array $card): array
    {
        $trace = is_array($card['trace'] ?? null) ? $card['trace'] : [];
        $action = is_array($card['action'] ?? null) ? $card['action'] : [];
        return array_filter([
            'opportunity_key' => trim((string)($trace['opportunity_key'] ?? '')),
            'action_title' => trim((string)($action['title'] ?? '')),
            'action_object' => trim((string)($action['object'] ?? '')),
            'action_description' => trim((string)($action['description'] ?? '')),
            'reason' => trim((string)($card['reason'] ?? '')),
            'decision_snapshot_id' => max(0, (int)($trace['decision_snapshot_id'] ?? 0)),
            'decision_snapshot_digest' => strtolower(trim((string)($trace['decision_snapshot_digest'] ?? ''))),
            'opportunity_digest' => strtolower(trim((string)($trace['opportunity_digest'] ?? ''))),
        ], static fn(mixed $value): bool => $value !== '' && $value !== 0);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameBaselineFact(array $left, array $right): bool
    {
        $normalize = static function (array $card): array {
            $refs = array_values(array_unique(array_map('strval', (array)($card['fact_refs'] ?? []))));
            sort($refs, SORT_STRING);
            return [
                'value' => is_numeric($card['metric_contract']['baseline_window']['value'] ?? null)
                    ? sprintf('%.6F', (float)$card['metric_contract']['baseline_window']['value']) : null,
                'refs' => $refs,
            ];
        };
        return $normalize($left) === $normalize($right);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function assertIntentCurrent(array $intent): array
    {
        if ((string)($intent['source_module'] ?? '') !== self::SOURCE_MODULE) {
            throw new InvalidArgumentException('收益驾驶舱行动来源身份无效');
        }
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $businessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        if ($tenantId <= 0 || $hotelId <= 0 || $businessDate === '') {
            throw new InvalidArgumentException('收益驾驶舱行动酒店、租户或营业日无效');
        }
        $enabledChannels = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $filters = [
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platform' => $platform,
            'enabled_channels' => $enabledChannels,
            'strict_readback_only' => true,
            'permitted_hotel_ids' => [$hotelId],
            'is_super_admin' => true,
        ];
        $overview = (new RevenueAiOverviewService())->overview($filters);
        $overview['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build(
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform
        );
        $context = $this->evidenceContext($overview, $tenantId, $hotelId, $businessDate, $platform);
        return $this->assertManagedIntentReadback($intent, $overview, $context, true);
    }

    /** @param array<string,mixed> $intent */
    public function isIntentCurrent(array $intent): bool
    {
        try {
            $this->assertIntentCurrent($intent);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function metricUnit(string $metric, string $platform): string
    {
        return match ($metric) {
            'revenue' => 'CNY',
            'adr', 'avg_adr' => 'CNY',
            'orders' => 'orders',
            'room_nights' => 'room_nights',
            'detail_rate', 'view_rate', 'flow_rate', 'conversion', 'conversion_rate', 'order_rate' => 'percent',
            'list_exposure' => $platform === 'ctrip' ? 'unique_users' : 'exposure_count',
            'detail_exposure' => 'exposure_count',
            default => 'count',
        };
    }

    private function metricLabel(string $metric): string
    {
        return match ($metric) {
            'revenue' => 'OTA 收入',
            'orders' => 'OTA 订单量',
            'room_nights' => 'OTA 间夜量',
            'adr', 'avg_adr' => 'OTA 平均房价',
            'detail_rate', 'view_rate', 'flow_rate' => '详情转化率',
            'conversion', 'conversion_rate', 'order_rate' => '下单转化率',
            'detail_exposure' => '详情曝光',
            'list_exposure' => '列表曝光',
            default => $metric,
        };
    }

    /** @return list<string> */
    private function metricPrioritiesForOpportunity(string $opportunityKey, string $platform): array
    {
        $preferred = match (trim($opportunityKey)) {
            'traffic_entry_shortage' => ['list_exposure', 'detail_exposure', 'orders'],
            'detail_conversion_shortage' => ['detail_exposure', 'detail_rate', 'view_rate', 'orders'],
            'submit_payment_conversion_shortage' => ['conversion_rate', 'order_rate', 'orders'],
            'cancellation_anomaly' => ['orders', 'room_nights', 'revenue'],
            'price_competition_position' => ['adr', 'avg_adr', 'revenue'],
            'bookability_gap' => ['room_nights', 'orders', 'revenue'],
            'service_promise_risk' => ['orders', 'revenue', 'room_nights'],
            'promotion_incrementality_evidence' => ['revenue', 'orders', 'room_nights'],
            default => [],
        };
        if ($platform !== 'ctrip') {
            $preferred = array_values(array_filter(
                $preferred,
                static fn(string $metric): bool => $metric !== 'list_exposure'
            ));
        }
        return array_values(array_unique(array_merge(
            $preferred,
            ['revenue', 'orders', 'room_nights', 'detail_exposure'],
            $platform === 'ctrip' ? ['list_exposure'] : []
        )));
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function normalizeActionContext(array $context): array
    {
        $opportunityKey = trim((string)($context['opportunity_key'] ?? ''));
        $allowedKeys = [
            'traffic_entry_shortage',
            'detail_conversion_shortage',
            'submit_payment_conversion_shortage',
            'cancellation_anomaly',
            'price_competition_position',
            'bookability_gap',
            'service_promise_risk',
            'promotion_incrementality_evidence',
        ];
        if ($opportunityKey !== '' && !in_array($opportunityKey, $allowedKeys, true)) {
            throw new InvalidArgumentException('revenue_cockpit_action_opportunity_invalid');
        }
        $normalized = [
            'opportunity_key' => $opportunityKey,
            'action_title' => mb_substr(trim((string)($context['action_title'] ?? '')), 0, 200),
            'action_object' => mb_substr(trim((string)($context['action_object'] ?? '')), 0, 200),
            'action_description' => mb_substr(trim((string)($context['action_description'] ?? '')), 0, 2000),
            'reason' => mb_substr(trim((string)($context['reason'] ?? '')), 0, 2000),
            'decision_snapshot_id' => max(0, (int)($context['decision_snapshot_id'] ?? 0)),
            'decision_snapshot_digest' => strtolower(trim((string)($context['decision_snapshot_digest'] ?? ''))),
            'opportunity_digest' => strtolower(trim((string)($context['opportunity_digest'] ?? ''))),
        ];
        foreach (['decision_snapshot_digest', 'opportunity_digest'] as $field) {
            if ($normalized[$field] !== '' && preg_match('/^[a-f0-9]{64}$/D', $normalized[$field]) !== 1) {
                throw new InvalidArgumentException('revenue_cockpit_action_digest_invalid');
            }
        }
        $hasLineage = $normalized['decision_snapshot_id'] > 0
            || $normalized['decision_snapshot_digest'] !== ''
            || $normalized['opportunity_digest'] !== '';
        if (($opportunityKey !== ''
                && ($normalized['action_title'] === ''
                    || $normalized['action_object'] === ''
                    || $normalized['action_description'] === ''
                    || $normalized['decision_snapshot_id'] <= 0
                    || preg_match('/^[a-f0-9]{64}$/D', $normalized['decision_snapshot_digest']) !== 1
                    || preg_match('/^[a-f0-9]{64}$/D', $normalized['opportunity_digest']) !== 1))
            || ($opportunityKey === '' && $hasLineage)
        ) {
            throw new InvalidArgumentException('revenue_cockpit_action_context_incomplete');
        }
        return array_filter(
            $normalized,
            static fn(mixed $value): bool => $value !== '' && $value !== 0
        );
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function assertRestoredIntentScope(
        array $intent,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $expected = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_module' => OperatingApprovalIntentService::SOURCE_MODULE,
            'platform' => 'hotel_operation',
            'object_type' => 'operation_checklist',
            'action_type' => 'human_review_operating_cycle',
            'date_start' => $businessDate,
            'date_end' => $businessDate,
        ];
        foreach ($expected as $field => $value) {
            if ((string)($intent[$field] ?? '') !== (string)$value) {
                throw new RuntimeException('revenue_cockpit_restore_identity_drift:' . $field, 409);
            }
        }
        if ((int)($intent['id'] ?? 0) <= 0
            || trim((string)($intent['status'] ?? '')) === ''
            || !is_array($intent['tasks'] ?? null)
        ) {
            throw new RuntimeException('revenue_cockpit_restore_lifecycle_invalid', 409);
        }
        return $intent;
    }

    /** @return array<string,mixed> */
    private function boundaries(bool $readOnly, int $taskCount): array
    {
        return [
            'read_only' => $readOnly,
            'human_approval_required' => true,
            'automatic_collection' => false,
            'automatic_approval' => false,
            'automatic_execution' => false,
            'operation_task_created' => $taskCount > 0,
            'ota_write' => false,
            'external_message' => false,
        ];
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('revenue_cockpit_approval_business_date_invalid');
        }
        return $value;
    }
}
