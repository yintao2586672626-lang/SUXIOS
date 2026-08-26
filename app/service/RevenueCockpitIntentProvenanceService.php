<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Revalidates a revenue-cockpit execution intent from source facts without
 * calling either the revenue overview orchestrator or operation management.
 *
 * This is the lower-level provenance strategy at the operation approval
 * boundary. Keeping it independent prevents the former
 * OperationManagement -> RevenueCockpitApproval -> RevenueOverview/Operation
 * dependency cycle while preserving the same strict fact and current-receipt
 * gates.
 */
final class RevenueCockpitIntentProvenanceService
{
    private const ACTION_CARD_VERSION = 'operation_action_card.v1';

    /** @var Closure(int,int,string,string):array<string,mixed>|null */
    private ?Closure $overviewReader;

    public function __construct(?callable $overviewReader = null)
    {
        $this->overviewReader = $overviewReader !== null
            ? Closure::fromCallable($overviewReader)
            : null;
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    public function assertIntentCurrent(array $intent): array
    {
        if ((string)($intent['source_module'] ?? '') !== RevenueCockpitActionContract::SOURCE_MODULE) {
            throw new InvalidArgumentException('收益驾驶舱行动来源身份无效');
        }
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $businessDate = $this->date(substr(trim((string)($intent['date_start'] ?? '')), 0, 10));
        $dateEnd = substr(trim((string)($intent['date_end'] ?? '')), 0, 10);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        if ($tenantId <= 0 || $hotelId <= 0 || $dateEnd !== $businessDate) {
            throw new InvalidArgumentException('收益驾驶舱行动酒店、租户或营业日无效');
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('收益驾驶舱行动平台范围无效');
        }

        $card = $this->card($intent);
        $this->assertCardScope($card, $intent, $tenantId, $hotelId, $businessDate, $platform);
        $overview = $this->currentOverview($tenantId, $hotelId, $businessDate, $platform);
        $context = $this->evidenceContext($overview, $tenantId, $hotelId, $businessDate, $platform);
        $storedMetric = strtolower(trim((string)($card['metric_contract']['metric_key'] ?? '')));
        $currentMetric = $this->metricContext($overview, $context, $storedMetric);
        $storedDigest = strtolower(trim((string)($card['trace']['cockpit_identity_digest'] ?? '')));
        $currentDigest = strtolower(trim((string)($currentMetric['fact_snapshot_digest'] ?? '')));
        if (!$this->isDigest($storedDigest)
            || !$this->isDigest($currentDigest)
            || !hash_equals($storedDigest, $currentDigest)
        ) {
            throw new InvalidArgumentException('收益行动原始事实已漂移，请关闭当前写入并刷新核对');
        }

        $this->assertDecisionLineageCurrent($card, $context);
        $this->assertPendingCardCurrent($card, $intent);
        $intent['fact_integrity_status'] = 'verified';
        return $intent;
    }

    /** @param array<string,mixed> $intent */
    public function isIntentCurrent(array $intent): bool
    {
        try {
            $this->assertIntentCurrent($intent);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function currentOverview(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        if ($this->overviewReader !== null) {
            $overview = ($this->overviewReader)($tenantId, $hotelId, $businessDate, $platform);
            if (!is_array($overview)) {
                throw new RuntimeException('revenue_cockpit_current_overview_invalid', 422);
            }
            return $overview;
        }

        $platforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $etl = new OtaStandardEtlService();
        $datasets = [];
        foreach ($platforms as $selectedPlatform) {
            $datasets[$selectedPlatform] = $etl->buildDataset([
                'start_date' => $businessDate,
                'end_date' => $businessDate,
                'limit' => 5000,
                'strict_readback_only' => true,
                'permitted_hotel_ids' => [$hotelId],
                'system_hotel_id' => $hotelId,
                'source' => $selectedPlatform,
            ]);
        }
        $factLayer = (new RevenueFactLayerService())->build($hotelId, $businessDate, $datasets);
        $overview = [
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'three_source_fact_layer' => $factLayer,
        ];
        $closure = (new DualOtaFieldClosureService())->build($hotelId, $businessDate);
        $overview['dual_ota_field_closure'] = $closure;
        $overview['cockpit_strict_evidence'] = (new RevenueCockpitStrictEvidenceService())->build(
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform,
            $closure
        );
        return $overview;
    }

    /**
     * @return array{business_date:string,evidence_refs:list<array<string,mixed>>,cockpit_scope:array<string,mixed>}
     */
    private function evidenceContext(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
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
            || !$this->isDigest($closureDigest)
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
                    'fact_statuses' => is_array($source['fact_statuses'] ?? null)
                        ? $source['fact_statuses']
                        : [],
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

        $pmsSelection = (new RevenuePmsFactSelectorService())->select($factLayer);
        $pmsSourceKey = (string)$pmsSelection['source_key'];
        $pms = is_array($pmsSelection['source'] ?? null) ? $pmsSelection['source'] : [];
        $pmsProvenance = is_array($pms['source'] ?? null) ? $pms['source'] : [];
        $pmsRecordId = (int)($pmsProvenance['record_id'] ?? 0);
        $pmsProvider = trim((string)($pmsProvenance['provider'] ?? ''));
        $providerIdentityVerified = $pmsProvider === $pmsSourceKey
            || (($pmsSelection['legacy_fixture'] ?? false) === true && $pmsProvider === '');
        if ((string)$pmsSelection['data_status'] === 'readback_verified'
            && (string)($pms['business_date'] ?? '') === $businessDate
            && (string)($pms['actual_business_date'] ?? '') === $businessDate
            && (int)($pmsProvenance['tenant_id'] ?? 0) === $tenantId
            && (int)($pmsProvenance['system_hotel_id'] ?? 0) === $hotelId
            && (string)($pmsProvenance['table'] ?? '') === (string)($pmsSelection['expected_table'] ?? '')
            && $providerIdentityVerified
            && (string)($pmsProvenance['data_date'] ?? '') === $businessDate
            && (string)($pmsProvenance['readback_status'] ?? '') === 'readback_verified'
            && $pmsRecordId > 0
        ) {
            $refs[] = [
                'role' => 'supporting_fact',
                'source_kind' => 'formal_record',
                'table' => (string)$pmsSelection['expected_table'],
                'row_ids' => [$pmsRecordId],
                'platform' => $pmsSourceKey,
                'business_date' => $businessDate,
                'fact_scope' => 'whole_hotel_accommodation',
                'readback_verified' => true,
                'verification_status' => 'readback_verified',
                'fact_content_digest' => $this->digest([
                    'source_key' => $pmsSourceKey,
                    'provider' => $pmsProvider,
                    'pms_binding' => is_array($pmsSelection['binding'] ?? null)
                        ? $pmsSelection['binding']
                        : [],
                    'data_status' => (string)($pms['data_status'] ?? ''),
                    'business_date' => (string)($pms['business_date'] ?? ''),
                    'actual_business_date' => (string)($pms['actual_business_date'] ?? ''),
                    'source' => [
                        'table' => (string)($pmsProvenance['table'] ?? ''),
                        'record_id' => $pmsRecordId,
                        'tenant_id' => (int)($pmsProvenance['tenant_id'] ?? 0),
                        'system_hotel_id' => (int)($pmsProvenance['system_hotel_id'] ?? 0),
                        'data_date' => (string)($pmsProvenance['data_date'] ?? ''),
                        'readback_status' => (string)($pmsProvenance['readback_status'] ?? ''),
                    ],
                    'facts' => is_array($pms['facts'] ?? null) ? $pms['facts'] : [],
                    'fact_statuses' => is_array($pms['fact_statuses'] ?? null)
                        ? $pms['fact_statuses']
                        : [],
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

    /** @return array<string,mixed> */
    private function metricContext(array $overview, array $context, string $metricKey): array
    {
        if ($metricKey === '') {
            throw new RuntimeException('revenue_cockpit_no_same_criterion_metric_for_action', 422);
        }
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
        $rowIdsByPlatform = [];
        foreach ($platforms as $selectedPlatform) {
            $metricEvidence = is_array($strict[$selectedPlatform]['metrics'][$metricKey] ?? null)
                ? $strict[$selectedPlatform]['metrics'][$metricKey]
                : [];
            $rowIds = $this->positiveIds($metricEvidence['accepted_row_ids'] ?? []);
            if (($metricEvidence['strict_readback'] ?? false) !== true || $rowIds === []) {
                throw new RuntimeException('revenue_cockpit_no_same_criterion_metric_for_action', 422);
            }
            $rowIdsByPlatform[$selectedPlatform] = $rowIds;
        }
        $facts = $platform === 'all_ota'
            ? (is_array($factLayer['facts']['ota_channel']['combined'] ?? null)
                ? $factLayer['facts']['ota_channel']['combined'] : [])
            : (is_array($sources[$platform . '_ota']['facts'] ?? null)
                ? $sources[$platform . '_ota']['facts'] : []);
        $metricValue = $facts[$metricKey] ?? null;
        if (!is_numeric($metricValue)) {
            throw new RuntimeException('revenue_cockpit_no_same_criterion_metric_for_action', 422);
        }
        $unit = $this->metricUnit($metricKey, $platform);
        $refs = [];
        foreach ($rowIdsByPlatform as $selectedPlatform => $rowIds) {
            foreach ($rowIds as $rowId) {
                $refs[] = 'online_daily_data#' . $rowId;
            }
        }
        sort($refs, SORT_STRING);
        $snapshot = [
            'contract_version' => RevenueCockpitActionContract::VERSION,
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'platform' => $platform,
            'business_date' => $businessDate,
            'metric_key' => $metricKey,
            'metric_unit' => $unit,
            'metric_value' => round((float)$metricValue, 6),
            'fact_refs' => $refs,
        ];
        return [
            'metric_key' => $metricKey,
            'metric_unit' => $unit,
            'metric_value' => round((float)$metricValue, 6),
            'fact_refs' => $refs,
            'fact_snapshot_digest' => $this->digest($snapshot),
        ];
    }

    /** @param array<string,mixed> $card @param array<string,mixed> $intent */
    private function assertCardScope(
        array $card,
        array $intent,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): void {
        if ((string)($card['contract_version'] ?? '') !== self::ACTION_CARD_VERSION
            || (string)($card['source']['module'] ?? '') !== RevenueCockpitActionContract::SOURCE_MODULE
            || (int)($card['hotel']['tenant_id'] ?? 0) !== $tenantId
            || (int)($card['hotel']['hotel_id'] ?? 0) !== $hotelId
            || (int)($card['source']['record_id'] ?? 0) !== (int)($intent['source_record_id'] ?? 0)
            || strtolower(trim((string)($card['source']['platform'] ?? ''))) !== $platform
            || (string)($card['business_window']['date_start'] ?? '') !== $businessDate
            || (string)($card['business_window']['date_end'] ?? '') !== $businessDate
        ) {
            throw new InvalidArgumentException('收益驾驶舱行动来源范围已漂移');
        }
    }

    /** @param array<string,mixed> $card @param array<string,mixed> $intent */
    private function assertPendingCardCurrent(array $card, array $intent): void
    {
        $storedDigest = strtolower(trim((string)($card['content_digest'] ?? '')));
        $unsigned = $card;
        unset($unsigned['content_digest']);
        $expectedDigest = hash('sha256', $this->canonicalJson($unsigned));
        if (!$this->isDigest($storedDigest) || !hash_equals($storedDigest, $expectedDigest)) {
            throw new InvalidArgumentException('行动卡内容摘要不一致，请基于当前事实重新生成');
        }
        if (!in_array((string)($card['status'] ?? ''), ['draft', 'pending_approval', 'approved'], true)
            || trim((string)($card['action']['type'] ?? '')) === ''
            || trim((string)($card['action']['title'] ?? '')) === ''
            || trim((string)($card['action']['description'] ?? '')) === ''
            || trim((string)($card['action']['object'] ?? '')) === ''
            || trim((string)($card['reason'] ?? '')) === ''
            || !is_numeric($card['metric_contract']['baseline_window']['value'] ?? null)
            || !is_array($card['fact_refs'] ?? null)
            || $card['fact_refs'] === []
            || ($card['approval']['required'] ?? false) !== true
            || ($card['approval']['fact_reread_required'] ?? false) !== true
            || ($card['boundaries']['automatic_execution'] ?? true) !== false
            || ($card['boundaries']['automatic_ota_write'] ?? true) !== false
            || ($card['boundaries']['external_message'] ?? true) !== false
            || (string)($card['metric_contract']['metric_key'] ?? '') !== (string)($intent['expected_metric'] ?? '')
        ) {
            throw new InvalidArgumentException('行动卡字段不完整或越过外部操作授权边界');
        }
        if ((string)($card['metric_contract']['target_type'] ?? '') === 'observation'
            && ((string)($card['metric_contract']['expected_direction'] ?? '') !== 'observe'
                || ($card['metric_contract']['target_value'] ?? null) !== null
                || ($card['metric_contract']['expected_delta'] ?? null) !== null)
        ) {
            throw new InvalidArgumentException('观察型行动只能保存观察目标，不能编造数值目标');
        }
        $expiresAt = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            trim((string)($card['approval']['approval_expires_at'] ?? '')),
            new \DateTimeZone('Asia/Shanghai')
        );
        if (!$expiresAt
            || $expiresAt->format('Y-m-d H:i:s') !== (string)($card['approval']['approval_expires_at'] ?? '')
        ) {
            throw new InvalidArgumentException('行动卡审批有效期格式无效');
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        if (strtolower(trim((string)($intent['status'] ?? ''))) === 'pending_approval' && $now > $expiresAt) {
            throw new InvalidArgumentException('行动卡已过期，请基于最新事实重新生成');
        }
    }

    /** @param array<string,mixed> $card @param array<string,mixed> $context */
    private function assertDecisionLineageCurrent(array $card, array $context): void
    {
        $trace = is_array($card['trace'] ?? null) ? $card['trace'] : [];
        $snapshotId = max(0, (int)($trace['decision_snapshot_id'] ?? 0));
        $snapshotDigest = strtolower(trim((string)($trace['decision_snapshot_digest'] ?? '')));
        $opportunityKey = trim((string)($trace['opportunity_key'] ?? ''));
        $opportunityDigest = strtolower(trim((string)($trace['opportunity_digest'] ?? '')));
        $hasAny = $snapshotId > 0 || $snapshotDigest !== '' || $opportunityKey !== '' || $opportunityDigest !== '';
        if (!$hasAny) {
            return;
        }
        if ($snapshotId <= 0 || !$this->isDigest($snapshotDigest) || !$this->isDigest($opportunityDigest)) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409);
        }
        $scope = (array)$context['cockpit_scope'];
        $row = Db::name('revenue_decision_snapshots')
            ->where('id', $snapshotId)
            ->where('tenant_id', (int)($scope['tenant_id'] ?? 0))
            ->where('system_hotel_id', (int)($scope['hotel_id'] ?? 0))
            ->where('platform', (string)($scope['platform'] ?? ''))
            ->where('business_date', (string)($scope['business_date'] ?? ''))
            ->find();
        if (!is_array($row)
            || (string)($row['contract_version'] ?? '') !== 'revenue_decision_snapshot.v1'
            || !hash_equals($snapshotDigest, strtolower(trim((string)($row['content_digest'] ?? ''))))
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_stale', 409);
        }
        $sourceRefs = $this->decode((string)($row['source_refs_json'] ?? ''));
        $visibleModel = $this->decode((string)($row['visible_model_json'] ?? ''));
        $this->assertSnapshotAsOfDateCurrent($visibleModel);
        $storedEvidenceDigest = strtolower(trim((string)($row['evidence_digest'] ?? '')));
        $storedVisibleDigest = strtolower(trim((string)($row['visible_model_digest'] ?? '')));
        $currentRefs = $this->canonicalRefs((array)($context['evidence_refs'] ?? []));
        if (!hash_equals($storedEvidenceDigest, $this->digest($sourceRefs))
            || !hash_equals($storedEvidenceDigest, $this->digest($currentRefs))
            || !hash_equals($storedVisibleDigest, $this->digest($visibleModel))
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_stale', 409);
        }
        $opportunity = null;
        foreach ((array)($visibleModel['opportunities'] ?? []) as $candidate) {
            if (is_array($candidate) && (string)($candidate['opportunityKey'] ?? '') === $opportunityKey) {
                $opportunity = $candidate;
                break;
            }
        }
        if (!is_array($opportunity)
            || ($opportunity['canCreatePendingApproval'] ?? false) !== true
            || trim((string)($opportunity['recommendedCheckAction'] ?? '')) === ''
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_opportunity_stale', 409);
        }
        $definition = $this->opportunityDefinition($opportunityKey);
        $recommendation = [
            'snapshot_id' => $snapshotId,
            'snapshot_digest' => $snapshotDigest,
            'opportunity_key' => $opportunityKey,
            'title' => $definition['title'],
            'action_text' => $definition['action_text'],
            'priority_band' => $this->boundedToken((string)($opportunity['priorityBand'] ?? 'evidence_first'), 40),
            'evidence_level' => $this->boundedToken((string)($opportunity['evidenceLevel'] ?? 'unknown'), 40),
            'platform' => (string)($scope['platform'] ?? ''),
        ];
        if (!hash_equals($opportunityDigest, $this->digest($recommendation))) {
            throw new RuntimeException('revenue_decision_snapshot_action_opportunity_drift', 409);
        }
    }

    /** @param array<string,mixed> $visibleModel */
    private function assertSnapshotAsOfDateCurrent(array $visibleModel): void
    {
        if (!RevenueOverviewDateContract::isCurrentAsOfDate(
            $visibleModel['asOfDate'] ?? null,
            $visibleModel['asOfDateContractVersion'] ?? null
        )) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_stale_as_of_date', 409);
        }
    }

    /** @return array{title:string,action_text:string} */
    private function opportunityDefinition(string $key): array
    {
        return match ($key) {
            'traffic_entry_shortage' => ['title' => '流量进入不足', 'action_text' => '核对同平台曝光来源、排名、投放、活动和可售库存，确认事实后再决定是否进入运营执行。'],
            'detail_conversion_shortage' => ['title' => '详情页转化不足', 'action_text' => '按同平台、同日期核对列表到详情路径、首图卖点和价格权益，先补齐可解释证据。'],
            'submit_payment_conversion_shortage' => ['title' => '提交 / 支付转化不足', 'action_text' => '分别核对详情到提交、提交到支付的分子分母及失败节点；缺少支付事实时不得把问题归到支付。'],
            'cancellation_anomaly' => ['title' => '取消异常', 'action_text' => '核对取消订单基数、取消原因、政策、客群与价格变化，确认是否需要人工干预。'],
            'price_competition_position' => ['title' => '价格竞争位置', 'action_text' => '补齐同平台、同房型、同权益、同取消政策和同入住日的本店与竞对价格样本后再判断。'],
            'bookability_gap' => ['title' => '可订性缺口', 'action_text' => '以同平台、同入住日、同住客条件完成游客侧搜索、详情和预订前检查，并保存断点证据。'],
            'service_promise_risk' => ['title' => '服务承诺风险', 'action_text' => '核对平台承诺、实际履约、影响订单和损失口径，缺少任一事实时不计算金额。'],
            'promotion_incrementality_evidence' => ['title' => '促销增量证据不足', 'action_text' => '补齐同活动阶段、对照组、前趋势、样本量和来源质量，再评估促销增量；当前不宣称因果。'],
            default => throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409),
        };
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private function card(array $intent): array
    {
        $target = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $card = is_array($target['action_card'] ?? null)
            ? $target['action_card']
            : (is_array($evidence['action_card'] ?? null) ? $evidence['action_card'] : []);
        if ($card === []) {
            throw new InvalidArgumentException('收益驾驶舱行动卡缺失');
        }
        return $card;
    }

    /** @param list<array<string,mixed>> $refs @return list<array<string,mixed>> */
    private function canonicalRefs(array $refs): array
    {
        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409);
            }
            $ref = $this->canonicalize($ref);
            $normalized[$this->encode($ref)] = $ref;
        }
        if ($normalized === []) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409);
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('revenue_decision_snapshot_readback_json_invalid', 409, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('revenue_decision_snapshot_readback_json_invalid', 409);
        }
        return $decoded;
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

    private function boundedToken(string $value, int $limit): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $limit || preg_match('/^[a-z0-9][a-z0-9_.:|\-]*$/D', $value) !== 1) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409);
        }
        return $value;
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('收益驾驶舱行动酒店、租户或营业日无效');
        }
        return $value;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
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
}
