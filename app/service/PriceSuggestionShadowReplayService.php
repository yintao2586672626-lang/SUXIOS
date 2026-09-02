<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Replays one saved price suggestion against its original frozen inputs and a
 * finalized same-room Ctrip outcome. This is an observational, append-only
 * review: it never approves, executes, or writes a price to an OTA/PMS.
 */
final class PriceSuggestionShadowReplayService
{
    public const CONTRACT_VERSION = 'price_suggestion_shadow_replay.v1';
    public const TABLE = 'price_suggestion_shadow_replays';

    private const PLATFORM = 'ctrip';
    private const REQUIRED_GATE_CHECKS = [
        'pricing_signal',
        'advisory_boundary',
        'risk_recheck',
    ];
    private const VERDICTS = [
        'direction_aligned',
        'direction_opposed',
        'indeterminate',
    ];
    private const DECISION_ATTESTATION_FIELDS = [
        'platform',
        'decision_as_of_time',
        'model_version',
        'decision_input_digest',
        'decision_source_refs',
    ];

    /** @var null|callable(int,int,int,string,string):array<string,mixed> */
    private $actualReader;

    public function __construct(
        private ?RevenuePricingRecommendationService $pricing = null,
        private ?TrustedOtaFactRepository $trustedFacts = null,
        ?callable $actualReader = null
    ) {
        $this->pricing ??= new RevenuePricingRecommendationService();
        $this->trustedFacts ??= new TrustedOtaFactRepository();
        $this->actualReader = $actualReader;
    }

    /** @return array<string,mixed> */
    public function createFromSuggestion(int $suggestionId, int $hotelId, int $createdBy): array
    {
        if ($suggestionId <= 0 || $hotelId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('历史调价影子回放缺少建议、酒店或操作者身份');
        }
        $this->assertTableReady();
        $tenantId = $this->tenantIdForHotel($hotelId);
        $suggestion = Db::name('price_suggestions')
            ->where('id', $suggestionId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($suggestion)) {
            throw new InvalidArgumentException('未找到当前酒店的调价建议');
        }

        $roomTypeId = (int)($suggestion['room_type_id'] ?? 0);
        $room = Db::name('room_types')
            ->where('id', $roomTypeId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($room)) {
            throw new InvalidArgumentException('调价建议的系统房型身份已缺失或不属于当前酒店');
        }

        $targetDate = $this->date($suggestion['suggestion_date'] ?? null, '目标入住日');
        if ($targetDate >= date('Y-m-d')) {
            throw new InvalidArgumentException('影子回放只接受已经结束的历史入住日');
        }
        $createdAt = $this->dateTime($suggestion['create_time'] ?? null, '建议生成时间');
        $factors = $this->object($suggestion['factors'] ?? null);
        $attestation = $this->resolveDecisionAttestation($suggestion, $factors, $createdAt);
        $platform = (string)$attestation['platform'];
        $asOfAt = (string)$attestation['decision_as_of_time'];
        if (substr($asOfAt, 0, 10) >= $targetDate) {
            throw new InvalidArgumentException('建议不是在目标入住日前生成，不能作为无穿越的历史回放输入');
        }

        $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
        if ($signals === []) {
            throw new InvalidArgumentException('调价建议缺少保存时冻结的信号输入');
        }
        $sourceRefs = (array)$attestation['decision_source_refs'];
        if ($sourceRefs === []) {
            throw new InvalidArgumentException('调价建议缺少可追溯的保存时来源引用');
        }
        $observedTimeCheck = (array)$attestation['observed_time_check'];
        if ($observedTimeCheck['violations'] !== []) {
            throw new InvalidArgumentException(
                '调价建议包含晚于 as-of 时点的输入：'
                . implode(',', $observedTimeCheck['violations'])
            );
        }

        $gateSuggestion = $suggestion;
        $gateSuggestion['factors'] = $factors;
        $gate = $this->pricing->buildSuggestionReadiness($gateSuggestion);
        $gateChecks = [];
        foreach ((array)($gate['checks'] ?? []) as $check) {
            if (is_array($check) && trim((string)($check['key'] ?? '')) !== '') {
                $gateChecks[(string)$check['key']] = $check;
            }
        }
        $failedGateChecks = [];
        foreach (self::REQUIRED_GATE_CHECKS as $key) {
            if (($gateChecks[$key]['passed'] ?? false) !== true) {
                $failedGateChecks[] = $key;
            }
        }
        if ($failedGateChecks !== []) {
            throw new InvalidArgumentException(
                '调价建议未通过原有证据与风险门：' . implode(',', $failedGateChecks)
            );
        }

        $roomSnapshot = [
            'id' => $roomTypeId,
            'identity_source' => 'price_suggestions.room_type_id_with_current_hotel_ownership_check',
            'base_price' => $this->positiveMoney($suggestion['current_price'] ?? null, '建议原价'),
            'min_price' => $this->nonNegativeMoney($suggestion['min_price'] ?? null, '最低保护价'),
            'max_price' => $this->nonNegativeMoney($suggestion['max_price'] ?? null, '最高限制价'),
        ];
        $savedSuggestedPrice = $this->positiveMoney($suggestion['suggested_price'] ?? null, '建议目标价');
        $replayed = $this->pricing->recommendFromSignals($roomSnapshot, $signals);
        $storedModel = (string)$attestation['model_version'];
        $replayedModel = trim((string)($replayed['factors']['model'] ?? ''));
        $originalDirection = $this->priceDirection(
            (float)$roomSnapshot['base_price'],
            $savedSuggestedPrice
        );
        $mismatchFields = [];
        if ($storedModel === '' || $replayedModel === '' || $storedModel !== $replayedModel) {
            $mismatchFields[] = 'model_version';
        }
        if (($replayed['should_create'] ?? false) !== true) {
            $mismatchFields[] = 'should_create';
        }
        if ((string)($replayed['action'] ?? '') !== $originalDirection) {
            $mismatchFields[] = 'direction';
        }
        if (!$this->numbersEqual($replayed['current_price'] ?? null, $roomSnapshot['base_price'])) {
            $mismatchFields[] = 'current_price';
        }
        if (!$this->numbersEqual($replayed['suggested_price'] ?? null, $savedSuggestedPrice)) {
            $mismatchFields[] = 'suggested_price';
        }
        if (!$this->numbersEqual(
            $replayed['confidence_score'] ?? null,
            $factors['confidence_score'] ?? $suggestion['confidence_score'] ?? null
        )) {
            $mismatchFields[] = 'confidence_score';
        }
        $mismatchFields = array_values(array_unique($mismatchFields));

        $inputSnapshot = [
            'contract_version' => self::CONTRACT_VERSION,
            'as_of_policy' => (string)$attestation['as_of_policy'],
            'freeze_status' => (string)$attestation['freeze_status'],
            'replay_match_required_for_direction_verdict' => true,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'price_suggestion_id' => $suggestionId,
            'room_type_id' => $roomTypeId,
            'platform' => $platform,
            'target_stay_date' => $targetDate,
            'as_of_at' => $asOfAt,
            'room_snapshot' => $roomSnapshot,
            'signals' => $signals,
            'source_refs' => $sourceRefs,
            'observed_time_check' => $observedTimeCheck,
            'decision_attestation' => [
                'contract_version' => $attestation['contract_version'],
                'input_digest' => $attestation['decision_input_digest'],
                'digest_verified' => $attestation['digest_verified'],
                'source_refs_verified' => $attestation['source_refs_verified'],
                'model_version_verified' => $attestation['model_version_verified'],
            ],
            'gate' => [
                'required_checks' => self::REQUIRED_GATE_CHECKS,
                'passed' => true,
                'pricing_readiness_stage' => (string)($gate['stage'] ?? ''),
            ],
        ];
        $recommendationSnapshot = [
            'contract_version' => self::CONTRACT_VERSION,
            'model_version' => $replayedModel !== '' ? $replayedModel : $storedModel,
            'original' => [
                'direction' => $originalDirection,
                'current_price' => (float)$roomSnapshot['base_price'],
                'suggested_price' => $savedSuggestedPrice,
                'confidence_score' => is_numeric($suggestion['confidence_score'] ?? null)
                    ? round((float)$suggestion['confidence_score'], 4)
                    : null,
                'risk_level' => trim((string)($factors['risk_level'] ?? '')),
                'reason' => trim((string)($suggestion['reason'] ?? '')),
                'factors_digest' => $this->digest($factors),
            ],
            'replayed' => [
                'should_create' => ($replayed['should_create'] ?? false) === true,
                'skip_reason' => trim((string)($replayed['skip_reason'] ?? '')),
                'direction' => trim((string)($replayed['action'] ?? '')),
                'current_price' => is_numeric($replayed['current_price'] ?? null)
                    ? round((float)$replayed['current_price'], 2)
                    : null,
                'suggested_price' => is_numeric($replayed['suggested_price'] ?? null)
                    ? round((float)$replayed['suggested_price'], 2)
                    : null,
                'confidence_score' => is_numeric($replayed['confidence_score'] ?? null)
                    ? round((float)$replayed['confidence_score'], 4)
                    : null,
                'risk_level' => trim((string)($replayed['risk_level'] ?? '')),
                'factors_digest' => $this->digest((array)($replayed['factors'] ?? [])),
            ],
            'replay_match' => $mismatchFields === [],
            'mismatch_fields' => $mismatchFields,
            'direction_verdict_basis' => 'sign(ota_order_amount / ota_room_nights - frozen_current_price)',
            'advisory_only' => true,
            'manual_review_required' => true,
            'automatic_approval' => false,
            'automatic_price_write' => false,
        ];

        $actualRaw = $this->actualReader !== null
            ? call_user_func(
                $this->actualReader,
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate
            )
            : $this->readActualSnapshot($tenantId, $hotelId, $roomTypeId, $platform, $targetDate);
        $actualSnapshot = $this->normalizeActualSnapshot(
            is_array($actualRaw) ? $actualRaw : [],
            $tenantId,
            $hotelId,
            $roomTypeId,
            $platform,
            $targetDate
        );
        [$verdict, $verdictReason, $observedDirection] = $this->verdict(
            $originalDirection,
            (float)$roomSnapshot['base_price'],
            $recommendationSnapshot,
            $actualSnapshot
        );

        $inputDigest = $this->digest($inputSnapshot);
        $recommendationDigest = $this->digest($recommendationSnapshot);
        $actualDigest = $this->digest($actualSnapshot);
        $modelVersion = (string)$recommendationSnapshot['model_version'];
        if ($modelVersion === '') {
            $modelVersion = 'unknown';
        }
        $contentPayload = [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'price_suggestion_id' => $suggestionId,
            'room_type_id' => $roomTypeId,
            'platform' => $platform,
            'target_stay_date' => $targetDate,
            'as_of_at' => $asOfAt,
            'model_version' => $modelVersion,
            'input_digest' => $inputDigest,
            'recommendation_digest' => $recommendationDigest,
            'actual_digest' => $actualDigest,
            'recommendation_direction' => $originalDirection,
            'observed_direction' => $observedDirection,
            'verdict' => $verdict,
            'verdict_reason' => $verdictReason,
            'causality_claimed' => false,
            'external_write_count' => 0,
        ];
        $contentDigest = $this->digest($contentPayload);
        $row = $contentPayload + [
            'input_snapshot_json' => $this->json($inputSnapshot),
            'recommendation_snapshot_json' => $this->json($recommendationSnapshot),
            'actual_snapshot_json' => $this->json($actualSnapshot),
            'content_digest' => $contentDigest,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $row['causality_claimed'] = 0;

        $created = false;
        $replay = null;
        try {
            [$replay, $created] = Db::transaction(function () use (
                $tenantId,
                $hotelId,
                $suggestionId,
                $contentDigest,
                $row
            ): array {
                $existing = Db::name(self::TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('price_suggestion_id', $suggestionId)
                    ->where('content_digest', $contentDigest)
                    ->find();
                if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                    return [
                        $this->readVerified(
                            (int)$existing['id'],
                            $tenantId,
                            $hotelId,
                            $suggestionId
                        ),
                        false,
                    ];
                }
                $id = (int)Db::name(self::TABLE)->insertGetId($row);
                if ($id <= 0) {
                    throw new RuntimeException('历史调价影子回放保存失败');
                }
                return [
                    $this->readVerified($id, $tenantId, $hotelId, $suggestionId),
                    true,
                ];
            });
        } catch (Throwable $error) {
            $existing = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('price_suggestion_id', $suggestionId)
                ->where('content_digest', $contentDigest)
                ->find();
            if (!is_array($existing) || (int)($existing['id'] ?? 0) <= 0) {
                throw $error;
            }
            $replay = $this->readVerified(
                (int)$existing['id'],
                $tenantId,
                $hotelId,
                $suggestionId
            );
            $created = false;
        }

        return [
            'replay' => $replay,
            'created' => $created,
            'idempotent_replay' => !$created,
            'persistence_status' => 'readback_verified',
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string,mixed> */
    public function listForSuggestion(
        int $tenantId,
        int $hotelId,
        int $suggestionId,
        int $limit = 20
    ): array {
        $this->assertTableReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $suggestionId <= 0) {
            throw new InvalidArgumentException('影子回放列表范围无效');
        }
        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('price_suggestion_id', $suggestionId)
            ->order('id', 'desc')
            ->limit(max(1, min(100, $limit)))
            ->select()
            ->toArray();
        $list = [];
        foreach ($rows as $row) {
            $list[] = $this->readVerified(
                (int)$row['id'],
                $tenantId,
                $hotelId,
                $suggestionId
            );
        }
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'list' => $list,
            'count' => count($list),
            'boundaries' => $this->boundaries(),
        ];
    }

    /** @return array<string,mixed> */
    public function readVerified(
        int $id,
        int $tenantId,
        int $hotelId,
        int $suggestionId
    ): array {
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('price_suggestion_id', $suggestionId)
            ->find();
        if (!is_array($row)) {
            throw new InvalidArgumentException('未找到当前范围的影子回放记录');
        }
        $input = $this->requiredObject($row['input_snapshot_json'] ?? null, '回放输入');
        $recommendation = $this->requiredObject(
            $row['recommendation_snapshot_json'] ?? null,
            '回放建议'
        );
        $actual = $this->requiredObject($row['actual_snapshot_json'] ?? null, '回放实际结果');
        $inputDigest = strtolower(trim((string)($row['input_digest'] ?? '')));
        $recommendationDigest = strtolower(trim((string)($row['recommendation_digest'] ?? '')));
        $actualDigest = strtolower(trim((string)($row['actual_digest'] ?? '')));
        if (!$this->isDigest($inputDigest)
            || !$this->isDigest($recommendationDigest)
            || !$this->isDigest($actualDigest)
            || !hash_equals($inputDigest, $this->digest($input))
            || !hash_equals($recommendationDigest, $this->digest($recommendation))
            || !hash_equals($actualDigest, $this->digest($actual))
        ) {
            throw new RuntimeException('历史调价影子回放快照摘要校验失败');
        }
        $normalized = [
            'contract_version' => (string)($row['contract_version'] ?? ''),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'price_suggestion_id' => (int)($row['price_suggestion_id'] ?? 0),
            'room_type_id' => (int)($row['room_type_id'] ?? 0),
            'platform' => strtolower(trim((string)($row['platform'] ?? ''))),
            'target_stay_date' => substr(trim((string)($row['target_stay_date'] ?? '')), 0, 10),
            'as_of_at' => trim((string)($row['as_of_at'] ?? '')),
            'model_version' => trim((string)($row['model_version'] ?? '')),
            'input_digest' => $inputDigest,
            'recommendation_digest' => $recommendationDigest,
            'actual_digest' => $actualDigest,
            'recommendation_direction' => trim((string)($row['recommendation_direction'] ?? '')),
            'observed_direction' => trim((string)($row['observed_direction'] ?? '')),
            'verdict' => trim((string)($row['verdict'] ?? '')),
            'verdict_reason' => trim((string)($row['verdict_reason'] ?? '')),
            'causality_claimed' => (int)($row['causality_claimed'] ?? 1) === 1,
            'external_write_count' => (int)($row['external_write_count'] ?? -1),
        ];
        if ($normalized['contract_version'] !== self::CONTRACT_VERSION
            || $normalized['tenant_id'] !== $tenantId
            || $normalized['hotel_id'] !== $hotelId
            || $normalized['price_suggestion_id'] !== $suggestionId
            || $normalized['room_type_id'] <= 0
            || $normalized['platform'] !== self::PLATFORM
            || !in_array($normalized['recommendation_direction'], ['increase', 'decrease', 'hold'], true)
            || !in_array($normalized['observed_direction'], ['increase', 'decrease', 'hold', 'unknown'], true)
            || !in_array($normalized['verdict'], self::VERDICTS, true)
            || $normalized['causality_claimed'] !== false
            || $normalized['external_write_count'] !== 0
        ) {
            throw new RuntimeException('历史调价影子回放范围或边界校验失败');
        }
        $expectedContent = $this->digest($normalized);
        $contentDigest = strtolower(trim((string)($row['content_digest'] ?? '')));
        if (!$this->isDigest($contentDigest) || !hash_equals($contentDigest, $expectedContent)) {
            throw new RuntimeException('历史调价影子回放完整内容摘要校验失败');
        }

        return [
            'id' => (int)$row['id'],
            ...$normalized,
            'input_snapshot' => $input,
            'recommendation_snapshot' => $recommendation,
            'actual_snapshot' => $actual,
            'content_digest' => $contentDigest,
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'readback_verified' => true,
            'observational_only' => true,
            'boundaries' => $this->boundaries(),
        ];
    }

    /**
     * A row is either fully attested or fully legacy. Any partial/tampered
     * attestation fails closed and can never downgrade to legacy replay.
     *
     * @param array<string,mixed> $suggestion
     * @param array<string,mixed> $factors
     * @return array<string,mixed>
     */
    private function resolveDecisionAttestation(
        array $suggestion,
        array $factors,
        string $createdAt
    ): array {
        $present = [];
        foreach (self::DECISION_ATTESTATION_FIELDS as $field) {
            $value = $suggestion[$field] ?? null;
            $present[$field] = is_array($value)
                ? $value !== []
                : trim((string)($value ?? '')) !== '';
        }
        $presentCount = count(array_filter($present));
        if ($presentCount === 0) {
            $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
            return [
                'contract_version' => 'legacy_unattested',
                'freeze_status' => 'legacy_reconstructed',
                'as_of_policy' => 'price_suggestions.create_time_before_target_date_and_saved_factors_only',
                'platform' => self::PLATFORM,
                'decision_as_of_time' => $createdAt,
                'model_version' => trim((string)($factors['model'] ?? '')),
                'decision_input_digest' => null,
                'decision_source_refs' => $this->sourceRefs($signals),
                'observed_time_check' => $this->observedTimeCheck($signals, $createdAt),
                'digest_verified' => false,
                'source_refs_verified' => false,
                'model_version_verified' => false,
            ];
        }
        if ($presentCount !== count(self::DECISION_ATTESTATION_FIELDS)) {
            throw new InvalidArgumentException(
                '调价建议决策证明字段不完整，不得降级为旧建议回放'
            );
        }

        $platform = strtolower(trim((string)$suggestion['platform']));
        $decisionAsOf = $this->dateTime(
            $suggestion['decision_as_of_time'],
            '决策 as-of 时间'
        );
        $modelVersion = trim((string)$suggestion['model_version']);
        $inputDigest = strtolower(trim((string)$suggestion['decision_input_digest']));
        $storedSourceRefs = $this->sourceRefs(
            $this->object($suggestion['decision_source_refs'])
        );
        if ($platform !== self::PLATFORM
            || $decisionAsOf !== $createdAt
            || $modelVersion === ''
            || !$this->isDigest($inputDigest)
            || $storedSourceRefs === []
        ) {
            throw new InvalidArgumentException('调价建议决策证明范围或格式无效');
        }

        $candidate = $suggestion;
        $candidate['factors'] = $factors;
        try {
            $rebuilt = $this->pricing->buildPriceSuggestionDecisionAttestation($candidate);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException(
                '调价建议决策证明无法复算：' . $error->getMessage(),
                0,
                $error
            );
        }
        $rebuiltDigest = strtolower(trim((string)($rebuilt['decision_input_digest'] ?? '')));
        $rebuiltSourceRefs = array_values((array)($rebuilt['decision_source_refs'] ?? []));
        if (!hash_equals($inputDigest, $rebuiltDigest)
            || $storedSourceRefs !== $rebuiltSourceRefs
            || $modelVersion !== (string)($rebuilt['model_version'] ?? '')
            || $decisionAsOf !== (string)($rebuilt['decision_as_of_time'] ?? '')
        ) {
            throw new InvalidArgumentException('调价建议决策证明摘要或来源引用校验失败');
        }
        $inputSnapshot = is_array($rebuilt['input_snapshot'] ?? null)
            ? $rebuilt['input_snapshot'] : [];
        $observedTimeCheck = is_array($inputSnapshot['observed_time_check'] ?? null)
            ? $inputSnapshot['observed_time_check'] : [];
        if (($observedTimeCheck['all_at_or_before_as_of'] ?? false) !== true
            || (array)($observedTimeCheck['violations'] ?? []) !== []
        ) {
            throw new InvalidArgumentException('调价建议决策证明包含 as-of 之后的输入');
        }

        return [
            'contract_version' => RevenuePricingRecommendationService::PRICE_SUGGESTION_DECISION_ATTESTATION_VERSION,
            'freeze_status' => 'attested',
            'as_of_policy' => 'decision_as_of_time_equals_saved_create_time_and_digest_verified',
            'platform' => $platform,
            'decision_as_of_time' => $decisionAsOf,
            'model_version' => $modelVersion,
            'decision_input_digest' => $inputDigest,
            'decision_source_refs' => $storedSourceRefs,
            'observed_time_check' => $observedTimeCheck,
            'digest_verified' => true,
            'source_refs_verified' => true,
            'model_version_verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function readActualSnapshot(
        int $tenantId,
        int $hotelId,
        int $roomTypeId,
        string $platform,
        string $targetDate
    ): array {
        $history = $this->trustedFacts->pricingHistory($hotelId, $targetDate, $targetDate);
        $rows = array_values(array_filter(
            is_array($history['rows'] ?? null) ? $history['rows'] : [],
            static fn(mixed $row): bool => is_array($row)
        ));
        $rows = array_values(array_filter($rows, static fn(array $row): bool =>
            (int)($row['system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($row['source'] ?? ''))) === $platform
            && (string)($row['data_date'] ?? '') === $targetDate
            && ($row['readback_verified'] ?? false) === true
            && (int)($row['row_id'] ?? 0) > 0
        ));
        if ($rows === []) {
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                ['trusted_same_room_actual_missing']
            );
        }
        $columns = $this->tableColumns('online_daily_data');
        if (!isset($columns['id'], $columns['raw_data'])
            || (!isset($columns['is_final']) && !isset($columns['data_period']))
        ) {
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                ['room_type_actual_schema_incomplete']
            );
        }
        $rowIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['row_id'],
            $rows
        )));
        sort($rowIds, SORT_NUMERIC);
        $fields = array_values(array_intersect(
            ['id', 'raw_data', 'is_final', 'data_period'],
            array_keys($columns)
        ));
        $storedRows = Db::name('online_daily_data')
            ->whereIn('id', $rowIds)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
        $storedById = [];
        foreach ($storedRows as $stored) {
            $storedById[(int)($stored['id'] ?? 0)] = $stored;
        }

        $matched = [];
        foreach ($rows as $row) {
            $rowId = (int)$row['row_id'];
            $stored = $storedById[$rowId] ?? null;
            if (!is_array($stored)) {
                continue;
            }
            $final = in_array($stored['is_final'] ?? null, [1, '1', true, 'true'], true)
                || strtolower(trim((string)($stored['data_period'] ?? ''))) === 'historical_daily';
            if (!$final) {
                continue;
            }
            $raw = $this->object($stored['raw_data'] ?? null);
            if ($this->systemRoomTypeId($raw) !== $roomTypeId) {
                continue;
            }
            $matched[] = $row;
        }
        if ($matched === []) {
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                ['final_same_system_room_type_actual_missing']
            );
        }

        $sums = ['amount' => 0.0, 'room_nights' => 0.0, 'orders' => 0.0];
        $pairedCount = 0;
        $orderCount = 0;
        $refs = [];
        $readbackAt = '';
        foreach ($matched as $row) {
            $amount = $this->nullableNonNegative($row['amount'] ?? null);
            $roomNights = $this->nullableNonNegative($row['quantity'] ?? null);
            if ($amount === null || $roomNights === null || $roomNights <= 0) {
                continue;
            }
            $sums['amount'] += $amount;
            $sums['room_nights'] += $roomNights;
            $pairedCount++;
            $refs[] = 'online_daily_data#' . (int)$row['row_id'];
            $orders = $this->nullableNonNegative($row['book_order_num'] ?? null);
            if ($orders !== null) {
                $sums['orders'] += $orders;
                $orderCount++;
            }
            $collectedAt = trim((string)($row['collected_at'] ?? ''));
            if ($collectedAt !== '' && strtotime($collectedAt) !== false
                && ($readbackAt === '' || strtotime($collectedAt) > strtotime($readbackAt))
            ) {
                $readbackAt = date('Y-m-d H:i:s', (int)strtotime($collectedAt));
            }
        }
        if ($pairedCount <= 0 || $sums['room_nights'] <= 0 || $readbackAt === '') {
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                ['same_room_ota_sales_avg_price_not_calculable']
            );
        }

        return [
            'status' => 'available',
            'quality_status' => 'readback_verified_final_room_type',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'platform' => $platform,
            'target_stay_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'amount' => round($sums['amount'], 2),
            'room_nights' => round($sums['room_nights'], 4),
            'orders' => $orderCount > 0 ? round($sums['orders'], 4) : null,
            'source_refs' => array_values(array_unique($refs)),
            'source_readback_count' => $pairedCount,
            'readback_at' => $readbackAt,
            'readback_verified' => true,
            'finalized' => true,
            'source_policy' => 'trusted_ota_fact_repository_then_exact_system_room_type_final_row',
            'causality_claimed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeActualSnapshot(
        array $actual,
        int $tenantId,
        int $hotelId,
        int $roomTypeId,
        string $platform,
        string $targetDate
    ): array {
        if (strtolower(trim((string)($actual['status'] ?? ''))) !== 'available') {
            $reasons = array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                (array)($actual['reason_codes'] ?? [$actual['reason_code'] ?? 'actual_unavailable'])
            ))));
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                $reasons !== [] ? $reasons : ['actual_unavailable']
            );
        }
        $scopeMatches = (int)($actual['tenant_id'] ?? 0) === $tenantId
            && (int)($actual['hotel_id'] ?? 0) === $hotelId
            && (int)($actual['room_type_id'] ?? 0) === $roomTypeId
            && strtolower(trim((string)($actual['platform'] ?? ''))) === $platform
            && trim((string)($actual['target_stay_date'] ?? '')) === $targetDate
            && strtolower(trim((string)($actual['metric_scope'] ?? ''))) === 'ota_channel'
            && ($actual['readback_verified'] ?? false) === true
            && ($actual['finalized'] ?? false) === true;
        $amount = $this->nullableNonNegative($actual['amount'] ?? null);
        $roomNights = $this->nullableNonNegative($actual['room_nights'] ?? null);
        $orders = $this->nullableNonNegative($actual['orders'] ?? null);
        $refs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($actual['source_refs'] ?? [])
        ))));
        $readbackAt = trim((string)($actual['readback_at'] ?? ''));
        if (!$scopeMatches || $amount === null || $roomNights === null || $roomNights <= 0
            || $refs === [] || $readbackAt === '' || strtotime($readbackAt) === false
        ) {
            return $this->unavailableActual(
                $tenantId,
                $hotelId,
                $roomTypeId,
                $platform,
                $targetDate,
                ['actual_scope_or_quality_mismatch']
            );
        }
        return [
            'status' => 'available',
            'quality_status' => trim((string)($actual['quality_status'] ?? 'readback_verified')),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'platform' => $platform,
            'target_stay_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'amount' => round($amount, 2),
            'room_nights' => round($roomNights, 4),
            'orders' => $orders !== null ? round($orders, 4) : null,
            'ota_sales_avg_price' => round($amount / $roomNights, 2),
            'amount_semantics' => 'ota_order_amount_not_proven_room_revenue',
            'average_price_formula' => 'ota_order_amount / ota_room_nights',
            'source_refs' => $refs,
            'source_readback_count' => max(1, (int)($actual['source_readback_count'] ?? count($refs))),
            'readback_at' => date('Y-m-d H:i:s', (int)strtotime($readbackAt)),
            'readback_verified' => true,
            'finalized' => true,
            'source_policy' => trim((string)($actual['source_policy'] ?? 'same_scope_source_readback')),
            'reason_codes' => [],
            'causality_claimed' => false,
        ];
    }

    /** @return array{0:string,1:string,2:string} */
    private function verdict(
        string $recommendationDirection,
        float $frozenCurrentPrice,
        array $recommendation,
        array $actual
    ): array {
        if (($recommendation['replay_match'] ?? false) !== true) {
            return ['indeterminate', 'recommendation_replay_mismatch', 'unknown'];
        }
        if ((string)($actual['status'] ?? '') !== 'available'
            || !is_numeric($actual['ota_sales_avg_price'] ?? null)
        ) {
            return [
                'indeterminate',
                (string)($actual['reason_codes'][0] ?? 'same_room_actual_unavailable'),
                'unknown',
            ];
        }
        $actualDirection = $this->priceDirection(
            $frozenCurrentPrice,
            (float)$actual['ota_sales_avg_price']
        );
        if ($actualDirection === $recommendationDirection) {
            return [
                'direction_aligned',
                'observed_ota_sales_avg_price_direction_matches_recommendation',
                $actualDirection,
            ];
        }
        if (in_array($recommendationDirection . ':' . $actualDirection, [
            'increase:decrease',
            'decrease:increase',
        ], true)) {
            return [
                'direction_opposed',
                'observed_ota_sales_avg_price_direction_opposes_recommendation',
                $actualDirection,
            ];
        }
        return ['indeterminate', 'observed_direction_neutral_or_noncomparable', $actualDirection];
    }

    /** @return array<string,mixed> */
    private function unavailableActual(
        int $tenantId,
        int $hotelId,
        int $roomTypeId,
        string $platform,
        string $targetDate,
        array $reasonCodes
    ): array {
        return [
            'status' => 'unavailable',
            'quality_status' => 'insufficient_evidence',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'platform' => $platform,
            'target_stay_date' => $targetDate,
            'metric_scope' => 'ota_channel',
            'amount' => null,
            'room_nights' => null,
            'orders' => null,
            'ota_sales_avg_price' => null,
            'amount_semantics' => 'ota_order_amount_not_proven_room_revenue',
            'average_price_formula' => null,
            'source_refs' => [],
            'source_readback_count' => 0,
            'readback_at' => null,
            'readback_verified' => false,
            'finalized' => false,
            'source_policy' => 'exact_system_room_type_final_readback_required',
            'reason_codes' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => trim((string)$value),
                $reasonCodes
            )))),
            'causality_claimed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function boundaries(): array
    {
        return [
            'metric_scope' => 'ota_channel',
            'platform' => self::PLATFORM,
            'observational_only' => true,
            'causality_claimed' => false,
            'automatic_approval' => false,
            'execution_intent_created' => false,
            'automatic_price_write' => false,
            'ota_write' => false,
            'pms_write' => false,
            'external_write_count' => 0,
        ];
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        $tenantId = filter_var(
            Db::name('hotels')->where('id', $hotelId)->value('tenant_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($tenantId === false) {
            throw new InvalidArgumentException('酒店租户身份不可用');
        }
        return (int)$tenantId;
    }

    private function assertTableReady(): void
    {
        if (!$this->tableExists(self::TABLE)) {
            throw new RuntimeException('历史调价影子回放表尚未迁移', 503);
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->driverType() === 'sqlite'
                ? Db::query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]) !== []
                : Db::query('SHOW TABLES LIKE ?', [$table]) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,bool> */
    private function tableColumns(string $table): array
    {
        try {
            $rows = $this->driverType() === 'sqlite'
                ? Db::query('PRAGMA table_info(`' . $table . '`)')
                : Db::query('SHOW COLUMNS FROM `' . $table . '`');
        } catch (Throwable) {
            return [];
        }
        $columns = [];
        foreach ($rows as $row) {
            $field = trim((string)($row['Field'] ?? $row['name'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
        return $columns;
    }

    private function driverType(): string
    {
        return strtolower((string)Db::connect()->getConfig('type'));
    }

    private function date(mixed $value, string $label): string
    {
        $text = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($label . '必须为 YYYY-MM-DD');
        }
        return $text;
    }

    private function dateTime(mixed $value, string $label): string
    {
        $text = trim(str_replace('T', ' ', (string)$value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/D', $text) !== 1) {
            throw new InvalidArgumentException($label . '无效');
        }
        if (strlen($text) === 16) {
            $text .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date instanceof DateTimeImmutable
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s') !== $text
        ) {
            throw new InvalidArgumentException($label . '无效');
        }
        return $text;
    }

    private function positiveMoney(mixed $value, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
            throw new InvalidArgumentException($label . '必须大于0');
        }
        return round((float)$value, 2);
    }

    private function nonNegativeMoney(mixed $value, string $label): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            throw new InvalidArgumentException($label . '不能小于0');
        }
        return round((float)$value, 2);
    }

    private function nullableNonNegative(mixed $value): ?float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
            return null;
        }
        return (float)$value;
    }

    private function priceDirection(float $from, float $to): string
    {
        $delta = round($to - $from, 2);
        return $delta > 0 ? 'increase' : ($delta < 0 ? 'decrease' : 'hold');
    }

    private function numbersEqual(mixed $left, mixed $right): bool
    {
        return is_numeric($left) && is_numeric($right)
            && abs((float)$left - (float)$right) <= 0.0001;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function requiredObject(mixed $value, string $label): array
    {
        $decoded = $this->object($value);
        if ($decoded === []) {
            throw new RuntimeException($label . '不是有效对象');
        }
        return $decoded;
    }

    /** @return list<string> */
    private function sourceRefs(mixed $value): array
    {
        $refs = [];
        $walk = function (mixed $node) use (&$walk, &$refs): void {
            if (is_array($node)) {
                foreach ($node as $item) {
                    $walk($item);
                }
                return;
            }
            if (!is_scalar($node)) {
                return;
            }
            $text = trim((string)$node);
            if (preg_match('/^[a-z][a-z0-9_]*#[a-z0-9:_|,.-]+$/iD', $text) === 1) {
                $refs[] = $text;
            }
        };
        $walk($value);
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return $refs;
    }

    private function systemRoomTypeId(array $raw): int
    {
        $ids = [];
        $walk = function (mixed $node, int $depth = 0) use (&$walk, &$ids): void {
            if (!is_array($node) || $depth > 5) {
                return;
            }
            foreach (['system_room_type_id', 'systemRoomTypeId', 'suxi_room_type_id', 'suxiRoomTypeId'] as $key) {
                if (is_numeric($node[$key] ?? null) && (int)$node[$key] > 0) {
                    $ids[] = (int)$node[$key];
                }
            }
            $namespace = strtolower(trim((string)($node['room_type_id_namespace'] ?? $node['roomTypeIdNamespace'] ?? '')));
            if (in_array($namespace, ['suxios', 'system', 'system_room_type'], true)) {
                foreach (['room_type_id', 'roomTypeId'] as $key) {
                    if (is_numeric($node[$key] ?? null) && (int)$node[$key] > 0) {
                        $ids[] = (int)$node[$key];
                    }
                }
            }
            foreach ($node as $item) {
                if (is_array($item)) {
                    $walk($item, $depth + 1);
                }
            }
        };
        $walk($raw);
        $ids = array_values(array_unique($ids));
        return count($ids) === 1 ? $ids[0] : 0;
    }

    /** @return array{explicit_timestamp_count:int,all_at_or_before_as_of:bool,violations:list<string>} */
    private function observedTimeCheck(array $signals, string $asOfAt): array
    {
        $cutoff = strtotime($asOfAt);
        if ($cutoff === false) {
            throw new InvalidArgumentException('建议 as-of 时间无效');
        }
        $keys = [
            'observed_at' => true,
            'collected_at' => true,
            'captured_at' => true,
            'snapshot_time' => true,
            'source_observed_at' => true,
        ];
        $checked = 0;
        $violations = [];
        $walk = function (mixed $node, string $path = 'signals') use (
            &$walk,
            &$checked,
            &$violations,
            $keys,
            $cutoff
        ): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                $nextPath = $path . '.' . (string)$key;
                if (isset($keys[strtolower((string)$key)]) && is_scalar($value)) {
                    $text = trim(str_replace('T', ' ', (string)$value));
                    if ($text === '') {
                        continue;
                    }
                    $timestamp = strtotime($text);
                    $checked++;
                    if ($timestamp === false || $timestamp > $cutoff) {
                        $violations[] = $nextPath;
                    }
                }
                if (is_array($value)) {
                    $walk($value, $nextPath);
                }
            }
        };
        $walk($signals);
        $violations = array_values(array_unique($violations));
        sort($violations, SORT_STRING);
        return [
            'explicit_timestamp_count' => $checked,
            'all_at_or_before_as_of' => $violations === [],
            'violations' => $violations,
        ];
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function isDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
