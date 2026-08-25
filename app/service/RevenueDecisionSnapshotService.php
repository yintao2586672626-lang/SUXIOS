<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Freezes one exact revenue decision ViewModel and its strict source identity.
 *
 * The snapshot is append-only. It never approves, executes, prices, collects,
 * writes an OTA, or sends an external message.
 */
final class RevenueDecisionSnapshotService
{
    public const CONTRACT_VERSION = 'revenue_decision_snapshot.v1';
    public const VIEW_MODEL_VERSION = 'revenue_daily_cockpit.v2';

    /** @var list<string> */
    private const OPPORTUNITY_KEYS = [
        'traffic_entry_shortage',
        'detail_conversion_shortage',
        'submit_payment_conversion_shortage',
        'cancellation_anomaly',
        'price_competition_position',
        'bookability_gap',
        'service_promise_risk',
        'promotion_incrementality_evidence',
    ];

    /** @return array<string,mixed> */
    public function saveFromOverview(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        int $actorId,
        array $visibleModel,
        array $comparisonContexts = []
    ): array {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('revenue_decision_snapshot_actor_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = $this->platform($platform);
        $context = (new RevenueCockpitApprovalService())->evidenceContext(
            $overview,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform
        );
        $visibleModel = $this->validateVisibleModel(
            $visibleModel,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform,
            $overview,
            $comparisonContexts,
            $context
        );
        $sourceRefs = $this->canonicalRefs((array)($context['evidence_refs'] ?? []));
        $metricDefinitions = $this->metricDefinitions();
        $missingItems = $this->missingItems($visibleModel);
        $evidenceSummary = $this->evidenceSummary(
            $overview,
            $sourceRefs,
            $visibleModel,
            $tenantId,
            $hotelId,
            $businessDate,
            $platform
        );
        $visibleModelDigest = self::digest($visibleModel);
        $evidenceDigest = self::digest($sourceRefs);
        $content = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'source_refs' => $sourceRefs,
            'metric_definitions' => $metricDefinitions,
            'visible_model' => $visibleModel,
            'missing_items' => $missingItems,
            'evidence_summary' => $evidenceSummary,
            'visible_model_digest' => $visibleModelDigest,
            'evidence_digest' => $evidenceDigest,
            'created_by' => $actorId,
        ];
        $contentDigest = self::digest($content);
        $idempotencyKey = self::snapshotIdempotencyKey(
            $tenantId,
            $hotelId,
            $actorId,
            $contentDigest
        );
        $now = date('Y-m-d H:i:s');
        $insert = [
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'contract_version' => self::CONTRACT_VERSION,
            'source_refs_json' => self::encode($sourceRefs),
            'metric_definitions_json' => self::encode($metricDefinitions),
            'visible_model_json' => self::encode($visibleModel),
            'missing_items_json' => self::encode($missingItems),
            'evidence_summary_json' => self::encode($evidenceSummary),
            'visible_model_digest' => $visibleModelDigest,
            'evidence_digest' => $evidenceDigest,
            'content_digest' => $contentDigest,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $actorId,
            'created_at' => $now,
        ];

        $this->assertSchemaReady();
        try {
            $stored = Db::transaction(function () use (
                $tenantId,
                $hotelId,
                $actorId,
                $contentDigest,
                $insert
            ): array {
                $existing = $this->rowByContent($tenantId, $hotelId, $actorId, $contentDigest);
                if (is_array($existing)) {
                    return ['row' => $existing, 'created' => false];
                }
                $id = (int)Db::name('revenue_decision_snapshots')->insertGetId($insert);
                $row = $this->rowById($id, $tenantId, $hotelId);
                if (!is_array($row)) {
                    throw new RuntimeException('revenue_decision_snapshot_insert_readback_missing');
                }
                return ['row' => $row, 'created' => true];
            });
        } catch (Throwable $exception) {
            $row = $this->rowByContent($tenantId, $hotelId, $actorId, $contentDigest);
            if (!is_array($row)) {
                throw $exception;
            }
            $stored = ['row' => $row, 'created' => false];
        }

        $snapshot = $this->hydrateAndVerify((array)$stored['row']);
        if ((string)$snapshot['content_digest'] !== $contentDigest
            || (string)$snapshot['visible_model_digest'] !== $visibleModelDigest
            || (string)$snapshot['evidence_digest'] !== $evidenceDigest
        ) {
            throw new RuntimeException('revenue_decision_snapshot_exact_readback_drift', 409);
        }
        $snapshot['created'] = ($stored['created'] ?? false) === true;
        $snapshot['persistence_status'] = 'readback_verified';
        $snapshot['evidence_identity_status'] = 'matched_current';
        $snapshot['boundaries'] = $this->boundaries();
        return $snapshot;
    }

    /** @return array<string,mixed>|null */
    public function readExact(
        int $snapshotId,
        int $tenantId,
        int $hotelId,
        ?array $currentOverview = null
    ): ?array {
        if ($snapshotId <= 0 || $tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('revenue_decision_snapshot_scope_invalid');
        }
        $this->assertSchemaReady();
        $row = $this->rowById($snapshotId, $tenantId, $hotelId);
        if (!is_array($row)) {
            return null;
        }
        return $this->finishReadback($this->hydrateAndVerify($row), $currentOverview);
    }

    /** @return array<string,mixed>|null */
    public function readLatest(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        ?array $currentOverview = null
    ): ?array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('revenue_decision_snapshot_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = $this->platform($platform);
        $this->assertSchemaReady();
        $row = Db::name('revenue_decision_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('business_date', $businessDate)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return null;
        }
        return $this->finishReadback($this->hydrateAndVerify($row), $currentOverview);
    }

    /**
     * Revalidate the immutable decision lineage at the approval boundary.
     * The persisted action card is only a pointer; it cannot attest itself.
     *
     * @return array<string,mixed>
     */
    public function assertOpportunityCurrent(
        int $snapshotId,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        string $snapshotDigest,
        string $opportunityKey,
        string $opportunityDigest,
        array $currentOverview
    ): array {
        $businessDate = $this->date($businessDate);
        $platform = $this->platform($platform);
        $snapshotDigest = strtolower(trim($snapshotDigest));
        $opportunityDigest = strtolower(trim($opportunityDigest));
        $opportunityKey = trim($opportunityKey);
        if ($snapshotId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $opportunityDigest) !== 1
            || !in_array($opportunityKey, self::OPPORTUNITY_KEYS, true)
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_invalid', 409);
        }

        $snapshot = $this->readExact($snapshotId, $tenantId, $hotelId, $currentOverview);
        if (!is_array($snapshot)
            || (string)($snapshot['business_date'] ?? '') !== $businessDate
            || (string)($snapshot['platform'] ?? '') !== $platform
            || (string)($snapshot['evidence_identity_status'] ?? '') !== 'matched_current'
            || !hash_equals($snapshotDigest, strtolower(trim((string)($snapshot['content_digest'] ?? ''))))
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_lineage_stale', 409);
        }

        $opportunity = $this->findOpportunity((array)($snapshot['visible_model']['opportunities'] ?? []), $opportunityKey);
        if (!is_array($opportunity)
            || ($opportunity['canCreatePendingApproval'] ?? false) !== true
            || trim((string)($opportunity['recommendedCheckAction'] ?? '')) === ''
        ) {
            throw new RuntimeException('revenue_decision_snapshot_action_opportunity_stale', 409);
        }
        $recommendation = $this->opportunityRecommendation($snapshot, $opportunityKey, $opportunity);
        if (!hash_equals(
            $opportunityDigest,
            strtolower(trim((string)($recommendation['recommendation_digest'] ?? '')))
        )) {
            throw new RuntimeException('revenue_decision_snapshot_action_opportunity_drift', 409);
        }
        return $recommendation;
    }

    /** @return array<string,mixed> */
    public function createOpportunityPendingApproval(
        int $snapshotId,
        int $tenantId,
        int $hotelId,
        int $actorId,
        string $opportunityKey,
        array $currentOverview
    ): array {
        $snapshot = $this->readExact($snapshotId, $tenantId, $hotelId, $currentOverview);
        if (!is_array($snapshot)) {
            throw new RuntimeException('revenue_decision_snapshot_not_found', 404);
        }
        if ((string)($snapshot['evidence_identity_status'] ?? '') !== 'matched_current') {
            throw new RuntimeException('revenue_decision_snapshot_evidence_stale', 409);
        }
        $opportunityKey = trim($opportunityKey);
        $opportunity = $this->findOpportunity(
            (array)($snapshot['visible_model']['opportunities'] ?? []),
            $opportunityKey
        );
        if (!is_array($opportunity) || !in_array($opportunityKey, self::OPPORTUNITY_KEYS, true)) {
            throw new InvalidArgumentException('revenue_decision_snapshot_opportunity_invalid');
        }
        if (($opportunity['canCreatePendingApproval'] ?? false) !== true
            || trim((string)($opportunity['recommendedCheckAction'] ?? '')) === ''
        ) {
            throw new RuntimeException('revenue_decision_snapshot_opportunity_not_actionable', 422);
        }
        $definition = $this->opportunityDefinition($opportunityKey);
        $recommendation = $this->opportunityRecommendation($snapshot, $opportunityKey, $opportunity);
        $refs = $this->canonicalRefs((array)$snapshot['source_refs']);
        $refs[] = [
            'role' => 'decision_snapshot',
            'source_kind' => 'formal_record',
            'table' => 'revenue_decision_snapshots',
            'row_ids' => [(int)$snapshot['id']],
            'platform' => (string)$snapshot['platform'],
            'business_date' => (string)$snapshot['business_date'],
            'fact_scope' => 'revenue_decision_snapshot',
            'metric_definition_digest' => (string)$snapshot['visible_model_digest'],
            'readback_verified' => true,
            'verification_status' => 'readback_verified',
        ];
        $refs[] = [
            'role' => 'recommended_action',
            'source_kind' => 'formal_record',
            'table' => 'revenue_decision_snapshots',
            'row_ids' => [(int)$snapshot['id']],
            'platform' => (string)$snapshot['platform'],
            'business_date' => (string)$snapshot['business_date'],
            'fact_scope' => 'opportunity_' . $opportunityKey,
            'metric_definition_digest' => (string)$recommendation['recommendation_digest'],
            'readback_verified' => true,
            'verification_status' => 'readback_verified',
        ];
        $payload = (new RevenueCockpitApprovalService())->createFromOverview(
            $currentOverview,
            $tenantId,
            $hotelId,
            (string)$snapshot['business_date'],
            (string)$snapshot['platform'],
            $actorId,
            [
                'opportunity_key' => $opportunityKey,
                'action_title' => $definition['title'],
                'action_object' => 'revenue_cockpit_opportunity:' . $opportunityKey,
                'action_description' => $definition['action_text'],
                'reason' => implode('；', array_values(array_filter([
                    trim((string)($opportunity['factChange'] ?? '')),
                    trim((string)($opportunity['evidenceSupport'] ?? '')),
                    trim((string)($opportunity['possibleCause'] ?? '')),
                ]))),
                'decision_snapshot_id' => (int)$snapshot['id'],
                'decision_snapshot_digest' => (string)$snapshot['content_digest'],
                'opportunity_digest' => (string)$recommendation['recommendation_digest'],
            ]
        );
        $intent = is_array($payload['execution_intent'] ?? null)
            ? $payload['execution_intent']
            : [];
        $tasks = is_array($intent['tasks'] ?? null)
            ? array_values($intent['tasks'])
            : null;
        if ((string)($payload['persistence_status'] ?? '') !== 'readback_verified'
            || ($payload['external_action_triggered'] ?? true) !== false
            || (string)($payload['status'] ?? '') !== 'pending_approval'
            || (string)($intent['status'] ?? '') !== 'pending_approval'
            || $tasks === null
            || $tasks !== []
            || (int)($payload['execution_task_count'] ?? -1) !== 0
            || ($payload['execution_task_created'] ?? true) !== false
        ) {
            throw new RuntimeException('revenue_decision_opportunity_approval_readback_invalid', 409);
        }
        $payload['snapshot'] = [
            'id' => (int)$snapshot['id'],
            'content_digest' => (string)$snapshot['content_digest'],
            'evidence_digest' => (string)$snapshot['evidence_digest'],
        ];
        $payload['opportunity'] = $recommendation;
        $payload['boundaries'] = $this->boundaries();
        return $payload;
    }

    /** @return array<string,mixed> */
    private function finishReadback(array $snapshot, ?array $currentOverview): array
    {
        $status = 'not_checked';
        if (is_array($currentOverview)) {
            $context = (new RevenueCockpitApprovalService())->evidenceContext(
                $currentOverview,
                (int)$snapshot['tenant_id'],
                (int)$snapshot['system_hotel_id'],
                (string)$snapshot['business_date'],
                (string)$snapshot['platform']
            );
            $currentRefs = $this->canonicalRefs((array)($context['evidence_refs'] ?? []));
            $status = hash_equals((string)$snapshot['evidence_digest'], self::digest($currentRefs))
                ? 'matched_current'
                : 'stale_current_evidence';
        }
        $snapshot['persistence_status'] = 'readback_verified';
        $snapshot['evidence_identity_status'] = $status;
        $snapshot['boundaries'] = $this->boundaries();
        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function validateVisibleModel(
        array $model,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        array $overview = [],
        array $comparisonContexts = [],
        array $evidenceContext = []
    ): array {
        if ((string)($model['contractVersion'] ?? '') !== self::VIEW_MODEL_VERSION
            || (int)($model['tenantId'] ?? 0) !== $tenantId
            || (int)($model['hotelId'] ?? 0) !== $hotelId
            || (string)($model['businessDate'] ?? '') !== $businessDate
            || (string)($model['selectedPlatform'] ?? '') !== $platform
            || !is_array($model['visibleSections'] ?? null)
            || !array_is_list($model['visibleSections'])
            || !is_array($model['opportunities'] ?? null)
            || !array_is_list($model['opportunities'])
        ) {
            throw new InvalidArgumentException('revenue_decision_snapshot_view_model_invalid');
        }
        $keys = [];
        foreach ($model['opportunities'] as $opportunity) {
            if (!is_array($opportunity)) {
                throw new InvalidArgumentException('revenue_decision_snapshot_opportunity_invalid');
            }
            $key = trim((string)($opportunity['opportunityKey'] ?? ''));
            $state = trim((string)($opportunity['state'] ?? ''));
            if (!in_array($key, self::OPPORTUNITY_KEYS, true)
                || isset($keys[$key])
                || !in_array($state, ['actionable', 'no_signal', 'evidence_investigation', 'blocked', 'unknown'], true)
                || ($opportunity['causalityClaimed'] ?? null) !== false
                || trim((string)($opportunity['recommendedCheckAction'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException('revenue_decision_snapshot_opportunity_invalid');
            }
            $keys[$key] = true;
        }
        $expected = self::OPPORTUNITY_KEYS;
        $actual = array_keys($keys);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new InvalidArgumentException('revenue_decision_snapshot_opportunity_set_incomplete');
        }
        $encoded = self::encode($model);
        if (strlen($encoded) > 2_000_000) {
            throw new InvalidArgumentException('revenue_decision_snapshot_view_model_too_large');
        }
        if ($overview !== []) {
            return (new RevenueDecisionViewModelAttestationService())->attest(
                $model,
                $overview,
                $comparisonContexts,
                $evidenceContext,
                $tenantId,
                $hotelId,
                $businessDate,
                $platform
            );
        }
        return self::canonicalize($model);
    }

    /** @return list<array<string,mixed>> */
    private function missingItems(array $model): array
    {
        $items = [];
        foreach ((array)($model['visibleSections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach ((array)($section['cards'] ?? []) as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $status = strtolower(trim((string)($card['status'] ?? '')));
                $kind = (string)($card['kind'] ?? '');
                if (!in_array($kind, ['metric', 'comparison', 'gap', 'opportunity', 'anomaly'], true)
                    || in_array($status, ['readback_verified', 'derived_verified', 'verified', 'ready', 'ok'], true)
                ) {
                    continue;
                }
                $items[] = [
                    'card_key' => (string)($card['key'] ?? ''),
                    'label' => $this->boundedText((string)($card['label'] ?? '未命名缺失项'), 200),
                    'status' => $this->boundedToken($status !== '' ? $status : 'unknown', 40),
                    'reason_code' => $this->boundedToken((string)($card['reasonCode'] ?? 'unknown'), 100),
                    'source_key' => $this->boundedToken((string)($card['sourceKey'] ?? 'cockpit_rule'), 80),
                ];
            }
        }
        return array_slice($items, 0, 500);
    }

    /** @return array<string,mixed> */
    private function evidenceSummary(
        array $overview,
        array $sourceRefs,
        array $model,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        $strict = is_array($overview['cockpit_strict_evidence'] ?? null)
            ? $overview['cockpit_strict_evidence']
            : [];
        $hasTrustedPms = count(array_filter(
            $sourceRefs,
            static fn(array $ref): bool => (string)($ref['table'] ?? '') === 'dingdandao_operating_target_captures'
                && (string)($ref['fact_scope'] ?? '') === 'whole_hotel_accommodation'
        )) > 0;
        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platform' => $platform,
            'strict_gate' => (string)($strict['strict_gate'] ?? 'history_success+validation_verified+readback_verified'),
            'source_ref_count' => count($sourceRefs),
            'visible_section_count' => count((array)($model['visibleSections'] ?? [])),
            'visible_card_count' => array_sum(array_map(
                static fn(mixed $section): int => is_array($section) ? count((array)($section['cards'] ?? [])) : 0,
                (array)($model['visibleSections'] ?? [])
            )),
            'opportunity_count' => count((array)($model['opportunities'] ?? [])),
            'whole_hotel_conclusion_allowed' => $hasTrustedPms,
            'ota_platforms_separate' => true,
            'page_download_shared_view_model' => true,
            'causality_claimed' => false,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function metricDefinitions(): array
    {
        $perOta = 'tenant_id + hotel_id + platform + business_date';
        return [
            'revenue' => ['label' => 'OTA渠道订单金额', 'unit' => 'CNY', 'scope' => $perOta, 'kind' => 'platform_fact', 'source_meaning' => 'order_amount', 'missing_policy' => 'null'],
            'orders' => ['label' => 'OTA渠道订单', 'unit' => 'orders', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'null'],
            'room_nights' => ['label' => 'OTA渠道间夜', 'unit' => 'room_nights', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'null'],
            'adr' => ['label' => 'OTA订单金额 / 间夜', 'unit' => 'CNY', 'scope' => $perOta, 'kind' => 'formula_calculation', 'formula' => 'order_amount / room_nights', 'missing_policy' => 'null'],
            'list_exposure' => ['label' => '列表曝光', 'unit' => 'exposures', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'null'],
            'detail_exposure' => ['label' => '详情访问/曝光', 'unit' => 'exposures', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'null'],
            'flow_rate_percent' => ['label' => '列表到详情转化率', 'unit' => 'percent', 'scope' => $perOta, 'kind' => 'formula_or_platform_fact', 'missing_policy' => 'null'],
            'submit_rate_percent' => ['label' => '详情到提交转化率', 'unit' => 'percent', 'scope' => $perOta, 'kind' => 'formula_or_platform_fact', 'missing_policy' => 'null'],
            'payment_conversion_percent' => ['label' => '提交到支付转化率', 'unit' => 'percent', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'null'],
            'cancellation_rate_percent' => ['label' => '取消率', 'unit' => 'percent', 'scope' => $perOta, 'kind' => 'formula_or_platform_fact', 'missing_policy' => 'null'],
            'price_competition_position' => ['label' => '同房型同权益价格竞争位置', 'unit' => 'position', 'scope' => $perOta, 'kind' => 'formula_calculation', 'missing_policy' => 'unknown'],
            'bookability' => ['label' => '游客侧可订性', 'unit' => 'status', 'scope' => $perOta, 'kind' => 'platform_fact', 'missing_policy' => 'unknown'],
            'whole_hotel_revenue' => ['label' => '全酒店住宿收入', 'unit' => 'CNY', 'scope' => 'tenant_id + hotel_id + business_date + trusted_pms', 'kind' => 'pms_fact_only', 'missing_policy' => 'null'],
        ];
    }

    /** @param list<array<string,mixed>> $refs @return list<array<string,mixed>> */
    private function canonicalRefs(array $refs): array
    {
        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                throw new InvalidArgumentException('revenue_decision_snapshot_source_refs_invalid');
            }
            $ref = self::canonicalize($ref);
            $normalized[self::encode($ref)] = $ref;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('revenue_decision_snapshot_source_refs_invalid');
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private function hydrateAndVerify(array $row): array
    {
        foreach (['id', 'tenant_id', 'system_hotel_id', 'created_by'] as $field) {
            if ((int)($row[$field] ?? 0) <= 0) {
                throw new RuntimeException('revenue_decision_snapshot_readback_invalid:' . $field, 409);
            }
        }
        if ((string)($row['contract_version'] ?? '') !== self::CONTRACT_VERSION) {
            throw new RuntimeException('revenue_decision_snapshot_readback_invalid:contract_version', 409);
        }
        $sourceRefs = $this->decode((string)($row['source_refs_json'] ?? ''));
        $metricDefinitions = $this->decode((string)($row['metric_definitions_json'] ?? ''));
        $visibleModel = $this->decode((string)($row['visible_model_json'] ?? ''));
        $missingItems = $this->decode((string)($row['missing_items_json'] ?? ''));
        $evidenceSummary = $this->decode((string)($row['evidence_summary_json'] ?? ''));
        $visibleModelDigest = self::digest($visibleModel);
        $evidenceDigest = self::digest($sourceRefs);
        if (!hash_equals((string)($row['visible_model_digest'] ?? ''), $visibleModelDigest)
            || !hash_equals((string)($row['evidence_digest'] ?? ''), $evidenceDigest)
        ) {
            throw new RuntimeException('revenue_decision_snapshot_readback_digest_drift', 409);
        }
        $content = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => (int)$row['tenant_id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'platform' => (string)$row['platform'],
            'business_date' => (string)$row['business_date'],
            'source_refs' => $sourceRefs,
            'metric_definitions' => $metricDefinitions,
            'visible_model' => $visibleModel,
            'missing_items' => $missingItems,
            'evidence_summary' => $evidenceSummary,
            'visible_model_digest' => $visibleModelDigest,
            'evidence_digest' => $evidenceDigest,
            'created_by' => (int)$row['created_by'],
        ];
        $contentDigest = self::digest($content);
        if (!hash_equals((string)($row['content_digest'] ?? ''), $contentDigest)) {
            throw new RuntimeException('revenue_decision_snapshot_readback_content_drift', 409);
        }
        $idempotencyKey = self::snapshotIdempotencyKey(
            (int)$row['tenant_id'],
            (int)$row['system_hotel_id'],
            (int)$row['created_by'],
            $contentDigest
        );
        if (!hash_equals($idempotencyKey, (string)($row['idempotency_key'] ?? ''))) {
            throw new RuntimeException('revenue_decision_snapshot_readback_invalid:idempotency_key', 409);
        }
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $createdAtValue = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $createdAt);
        if (!$createdAtValue || $createdAtValue->format('Y-m-d H:i:s') !== $createdAt) {
            throw new RuntimeException('revenue_decision_snapshot_readback_invalid:created_at', 409);
        }
        return [
            'id' => (int)$row['id'],
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => (int)$row['tenant_id'],
            'system_hotel_id' => (int)$row['system_hotel_id'],
            'platform' => (string)$row['platform'],
            'business_date' => (string)$row['business_date'],
            'source_refs' => $sourceRefs,
            'metric_definitions' => $metricDefinitions,
            'visible_model' => $visibleModel,
            'missing_items' => $missingItems,
            'evidence_summary' => $evidenceSummary,
            'visible_model_digest' => $visibleModelDigest,
            'evidence_digest' => $evidenceDigest,
            'content_digest' => $contentDigest,
            'idempotency_key' => $idempotencyKey,
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    private static function snapshotIdempotencyKey(
        int $tenantId,
        int $hotelId,
        int $actorId,
        string $contentDigest
    ): string {
        return hash('sha256', self::encode([
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'created_by' => $actorId,
            'content_digest' => $contentDigest,
        ]));
    }

    private function assertSchemaReady(): void
    {
        try {
            Db::query('SELECT 1 FROM `revenue_decision_snapshots` LIMIT 1');
        } catch (Throwable $exception) {
            throw new RuntimeException('revenue_decision_snapshot_schema_missing', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    private function rowById(int $id, int $tenantId, int $hotelId): ?array
    {
        $row = Db::name('revenue_decision_snapshots')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function rowByContent(
        int $tenantId,
        int $hotelId,
        int $actorId,
        string $contentDigest
    ): ?array {
        $row = Db::name('revenue_decision_snapshots')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('created_by', $actorId)
            ->where('content_digest', $contentDigest)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function boundaries(): array
    {
        return [
            'append_only_snapshot' => true,
            'human_approval_required' => true,
            'automatic_approval' => false,
            'automatic_pricing' => false,
            'automatic_execution' => false,
            'ota_write' => false,
            'external_message' => false,
            'causality_claimed' => false,
        ];
    }

    /** @return array{title:string,action_text:string} */
    private function opportunityDefinition(string $key): array
    {
        return match ($key) {
            'traffic_entry_shortage' => [
                'title' => '流量进入不足',
                'action_text' => '核对同平台曝光来源、排名、投放、活动和可售库存，确认事实后再决定是否进入运营执行。',
            ],
            'detail_conversion_shortage' => [
                'title' => '详情页转化不足',
                'action_text' => '按同平台、同日期核对列表到详情路径、首图卖点和价格权益，先补齐可解释证据。',
            ],
            'submit_payment_conversion_shortage' => [
                'title' => '提交 / 支付转化不足',
                'action_text' => '分别核对详情到提交、提交到支付的分子分母及失败节点；缺少支付事实时不得把问题归到支付。',
            ],
            'cancellation_anomaly' => [
                'title' => '取消异常',
                'action_text' => '核对取消订单基数、取消原因、政策、客群与价格变化，确认是否需要人工干预。',
            ],
            'price_competition_position' => [
                'title' => '价格竞争位置',
                'action_text' => '补齐同平台、同房型、同权益、同取消政策和同入住日的本店与竞对价格样本后再判断。',
            ],
            'bookability_gap' => [
                'title' => '可订性缺口',
                'action_text' => '以同平台、同入住日、同住客条件完成游客侧搜索、详情和预订前检查，并保存断点证据。',
            ],
            'service_promise_risk' => [
                'title' => '服务承诺风险',
                'action_text' => '核对平台承诺、实际履约、影响订单和损失口径，缺少任一事实时不计算金额。',
            ],
            'promotion_incrementality_evidence' => [
                'title' => '促销增量证据不足',
                'action_text' => '补齐同活动阶段、对照组、前趋势、样本量和来源质量，再评估促销增量；当前不宣称因果。',
            ],
            default => throw new InvalidArgumentException('revenue_decision_snapshot_opportunity_invalid'),
        };
    }

    /** @param list<mixed> $opportunities @return array<string,mixed>|null */
    private function findOpportunity(array $opportunities, string $opportunityKey): ?array
    {
        foreach ($opportunities as $candidate) {
            if (is_array($candidate)
                && (string)($candidate['opportunityKey'] ?? '') === $opportunityKey
            ) {
                return $candidate;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $opportunity @return array<string,mixed> */
    private function opportunityRecommendation(array $snapshot, string $opportunityKey, array $opportunity): array
    {
        $definition = $this->opportunityDefinition($opportunityKey);
        $recommendation = [
            'snapshot_id' => (int)($snapshot['id'] ?? 0),
            'snapshot_digest' => (string)($snapshot['content_digest'] ?? ''),
            'opportunity_key' => $opportunityKey,
            'title' => $definition['title'],
            'action_text' => $definition['action_text'],
            'priority_band' => $this->boundedToken((string)($opportunity['priorityBand'] ?? 'evidence_first'), 40),
            'evidence_level' => $this->boundedToken((string)($opportunity['evidenceLevel'] ?? 'unknown'), 40),
            'platform' => (string)($snapshot['platform'] ?? ''),
        ];
        $recommendation['recommendation_digest'] = self::digest($recommendation);
        return $recommendation;
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

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('revenue_decision_snapshot_business_date_invalid');
        }
        return $value;
    }

    private function platform(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_decision_snapshot_platform_invalid');
        }
        return $value;
    }

    private function boundedText(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('revenue_decision_snapshot_text_invalid');
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private function boundedToken(string $value, int $limit): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > $limit || preg_match('/^[a-z0-9][a-z0-9_.:|\-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException('revenue_decision_snapshot_token_invalid');
        }
        return $value;
    }

    private static function encode(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function digest(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
