<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Normalizes independently verified PMS captures and explains their deltas.
 *
 * This service never merges or overwrites source facts. Cross-source deltas
 * are diagnostic only and cannot upgrade or downgrade either source's own
 * identity, date, reconciliation, quality, or database-readback gate.
 */
final class PmsFactReconciliationService
{
    public const CONTRACT_VERSION = 'pms_independent_source_reconciliation.v1';

    private const MAX_CAPTURE_SKEW_SECONDS = 900;

    /**
     * Local SUXIOS diagnostic tolerances. These are not Meituan or Dingdandao
     * platform rules and must never be used to silently select a "true" source.
     *
     * @var array<string, array<string, mixed>>
     */
    private const METRIC_POLICIES = [
        'room_revenue' => [
            'label' => '客房收入',
            'kind' => 'money',
            'comparable' => false,
            'reason' => '订单来了为住宿数据中心总房费，美团云为工作台预计房费；结算阶段不同，不直接计算差值。',
        ],
        'sold_room_nights' => [
            'label' => '已售间夜',
            'kind' => 'count',
            'comparable' => true,
            'minimum_tolerance' => 2.0,
            'ratio_tolerance' => 0.05,
        ],
        'sellable_room_nights' => [
            'label' => '可售房量基数',
            'kind' => 'count',
            'comparable' => true,
            'minimum_tolerance' => 2.0,
            'ratio_tolerance' => 0.05,
        ],
        'occupancy_rate_percent' => [
            'label' => '入住率',
            'kind' => 'percent',
            'comparable' => true,
            'absolute_tolerance' => 2.0,
        ],
        'adr' => [
            'label' => 'ADR',
            'kind' => 'money',
            'comparable' => true,
            'minimum_tolerance' => 2.0,
            'ratio_tolerance' => 0.02,
        ],
        'revpar' => [
            'label' => 'RevPAR',
            'kind' => 'money',
            'comparable' => true,
            'minimum_tolerance' => 2.0,
            'ratio_tolerance' => 0.03,
        ],
    ];

    /**
     * @param array<string, array<string, mixed>> $captures
     * @param array<string, list<array<string, mixed>>> $histories
     * @return array<string, mixed>
     */
    public function summarize(
        int $hotelId,
        string $businessDate,
        array $captures,
        array $histories = []
    ): array {
        $businessDate = $this->date($businessDate);
        $sources = [
            DingdandaoOperatingTargetCaptureService::PROVIDER => $this->normalizeSource(
                DingdandaoOperatingTargetCaptureService::PROVIDER,
                $businessDate,
                $captures[DingdandaoOperatingTargetCaptureService::PROVIDER] ?? []
            ),
            MeituanCloudPmsCaptureService::PROVIDER => $this->normalizeSource(
                MeituanCloudPmsCaptureService::PROVIDER,
                $businessDate,
                $captures[MeituanCloudPmsCaptureService::PROVIDER] ?? []
            ),
        ];
        $sourceDeltas = [];
        foreach ([
            DingdandaoOperatingTargetCaptureService::PROVIDER,
            MeituanCloudPmsCaptureService::PROVIDER,
        ] as $provider) {
            $history = array_values(array_filter(
                $histories[$provider] ?? [],
                static fn(mixed $capture): bool => is_array($capture)
            ));
            $currentCapture = $captures[$provider] ?? ($history[0] ?? []);
            $previousCapture = $this->previousVerifiedCapture(
                $provider,
                $businessDate,
                $currentCapture,
                $history
            );
            $sourceDeltas[$provider] = $this->sourceDelta(
                $provider,
                $businessDate,
                $currentCapture,
                $previousCapture
            );
        }

        $verifiedSourceCount = count(array_filter(
            $sources,
            static fn(array $source): bool => ($source['usable'] ?? false) === true
        ));
        $captureSkewSeconds = $this->captureSkewSeconds($sources);
        $timeAligned = $verifiedSourceCount === 2
            && $captureSkewSeconds !== null
            && $captureSkewSeconds <= self::MAX_CAPTURE_SKEW_SECONDS;

        $metrics = [];
        foreach (self::METRIC_POLICIES as $key => $policy) {
            $metrics[] = $this->compareMetric(
                $key,
                $policy,
                $sources,
                $verifiedSourceCount,
                $timeAligned,
                $captureSkewSeconds
            );
        }

        $decision = $this->decision(
            $verifiedSourceCount,
            $timeAligned,
            $captureSkewSeconds,
            $metrics
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'result_scope' => 'diagnostic_only',
            'sources' => $sources,
            'source_deltas' => $sourceDeltas,
            'comparison' => [
                'direction' => 'meituan_cloud_pms_minus_dingdandao_pms',
                'capture_skew_seconds' => $captureSkewSeconds,
                'capture_skew_limit_seconds' => self::MAX_CAPTURE_SKEW_SECONDS,
                'time_aligned' => $timeAligned,
                'metrics' => $metrics,
                'notice' => '跨 PMS 差值只用于定位口径、时间或录入异常，不会让一个来源覆盖另一个来源，也不会自动选择“真值”。',
            ],
            'decision' => $decision,
            'policy' => [
                'source_gate' => '每个 PMS 独立通过门店、日期、字段、内部对账与数据库回读门禁。',
                'snapshot_delta_gate' => '每个 PMS 只与自身上一条同日、同范围、已验证快照比较；第一条快照只建立基线。',
                'cross_source_gate' => '只有两个来源都已验证且采集时间相差不超过15分钟时，才按宿析本地容差判断差值。',
                'selection_policy' => '预填和下游使用必须保留来源身份；出现超差时由人工核对采集时间和平台口径。',
                'revenue_policy' => '总房费与预计房费处于不同结算阶段，只并列展示，不直接相减。',
            ],
            'scope_note' => '本结果仅覆盖住宿客房经营事实，不代表全酒店总收入、利润、现金或已结算财务收入。',
        ];
    }

    /**
     * Failed or unverified captures stay in the audit trail but cannot become
     * the comparison baseline. "Adjacent" means adjacent in the verified
     * snapshot series for this same source and business date.
     *
     * @param array<string, mixed> $currentCapture
     * @param list<array<string, mixed>> $history
     * @return array<string, mixed>|null
     */
    private function previousVerifiedCapture(
        string $provider,
        string $businessDate,
        array $currentCapture,
        array $history
    ): ?array {
        $currentId = $currentCapture['id'] ?? null;
        $currentCapturedAt = (string)($currentCapture['captured_at'] ?? '');
        foreach ($history as $candidate) {
            $candidateId = $candidate['id'] ?? null;
            $sameCapture = $currentId !== null && $candidateId !== null
                ? (string)$candidateId === (string)$currentId
                : $currentCapturedAt !== ''
                    && (string)($candidate['captured_at'] ?? '') === $currentCapturedAt;
            if ($sameCapture) {
                continue;
            }
            if (($this->normalizeSource($provider, $businessDate, $candidate)['usable'] ?? false) === true) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $currentCapture
     * @param array<string, mixed>|null $previousCapture
     * @return array<string, mixed>
     */
    private function sourceDelta(
        string $provider,
        string $businessDate,
        array $currentCapture,
        ?array $previousCapture
    ): array {
        $providerLabel = $provider === DingdandaoOperatingTargetCaptureService::PROVIDER
            ? '订单来了 PMS'
            : '美团云 PMS';
        $current = $this->normalizeSource($provider, $businessDate, $currentCapture);
        $base = [
            'rule_version' => 'pms_snapshot_delta.v1',
            'provider' => $provider,
            'provider_label' => $providerLabel,
            'fact_scope' => 'accommodation_room_fee',
            'status' => 'blocked',
            'status_label' => '事实阻断',
            'rule_id' => 'PMS_DELTA_SOURCE_BLOCKED',
            'current_capture_id' => $currentCapture['id'] ?? null,
            'previous_capture_id' => $previousCapture['id'] ?? null,
            'captured_at' => $currentCapture['captured_at'] ?? null,
            'previous_captured_at' => $previousCapture['captured_at'] ?? null,
            'elapsed_hours' => null,
            'delta_vector' => $this->emptyDeltaVector(),
            'pace' => [
                'net_pickup_per_hour' => null,
                'room_revenue_per_hour' => null,
            ],
            'tolerance' => [
                'source' => 'cold_start_config',
                'room_nights' => null,
                'room_revenue' => null,
                'rate' => null,
            ],
            'judgment' => '',
            'confidence' => 'low',
            'recommended_manual_check' => '',
            'data_gaps' => [],
        ];

        if (($current['usable'] ?? false) !== true) {
            $base['judgment'] = '当前快照尚未通过来源自身的真实性与回读门禁，停止差值计算。';
            $base['recommended_manual_check'] = '先处理当前来源的门店、日期、字段、内部对账或数据库回读缺口。';
            $base['data_gaps'] = $current['gaps'];
            return $base;
        }
        if ($previousCapture === null) {
            return [
                ...$base,
                'status' => 'baseline_only',
                'status_label' => '已建立基线',
                'rule_id' => 'PMS_DELTA_BASELINE_ONLY',
                'judgment' => '这是该来源当天第一条合格快照，已建立基线，暂无上一条可比快照。',
                'recommended_manual_check' => '等待下一次同店、同日、同来源可信采集后再判断变化。',
                'data_gaps' => [[
                    'code' => 'previous_verified_capture_missing',
                    'message' => '尚无上一条同日已验证快照。',
                ]],
            ];
        }

        $previous = $this->normalizeSource($provider, $businessDate, $previousCapture);
        if (($previous['usable'] ?? false) !== true) {
            return [
                ...$base,
                'status' => 'not_comparable',
                'status_label' => '上一快照不可比',
                'rule_id' => 'PMS_DELTA_PREVIOUS_BLOCKED',
                'judgment' => '上一条快照未通过相同真实性门禁，不能用它计算趋势。',
                'recommended_manual_check' => '保留当前快照作为新基线，等待下一条合格快照。',
                'data_gaps' => $previous['gaps'],
            ];
        }
        if ((string)($current['source_scope'] ?? '') !== (string)($previous['source_scope'] ?? '')) {
            return [
                ...$base,
                'status' => 'rebaseline_required',
                'status_label' => '来源范围变化',
                'rule_id' => 'PMS_DELTA_SCOPE_CHANGED',
                'judgment' => '前后快照的来源范围不同，旧快照不能继续作为基线。',
                'recommended_manual_check' => '按当前来源范围重新建立当天基线。',
                'data_gaps' => [[
                    'code' => 'source_scope_mismatch',
                    'message' => '当前与上一快照的来源范围不同。',
                ]],
            ];
        }

        $currentTimestamp = $this->timestamp($currentCapture['captured_at'] ?? null);
        $previousTimestamp = $this->timestamp($previousCapture['captured_at'] ?? null);
        if ($currentTimestamp === null
            || $previousTimestamp === null
            || $currentTimestamp <= $previousTimestamp
        ) {
            return [
                ...$base,
                'status' => 'not_comparable',
                'status_label' => '采集时间不可比',
                'rule_id' => 'PMS_DELTA_TIME_INVALID',
                'judgment' => '当前快照时间必须晚于上一快照，且两个时间都必须可信。',
                'recommended_manual_check' => '核对设备时区、采集时间和业务日后重新建立基线。',
                'data_gaps' => [[
                    'code' => 'capture_time_invalid',
                    'message' => '采集时间缺失、倒序或无法解析。',
                ]],
            ];
        }

        $elapsedHours = round(($currentTimestamp - $previousTimestamp) / 3600, 4);
        $delta = [
            'room_revenue' => $this->factDelta($current, $previous, 'room_revenue'),
            'sold_room_nights' => $this->factDelta($current, $previous, 'sold_room_nights'),
            'sellable_room_nights' => $this->factDelta($current, $previous, 'sellable_room_nights'),
            'adr' => $this->factDelta($current, $previous, 'adr'),
            'occupancy_rate_points' => $this->factDelta($current, $previous, 'occupancy_rate_percent'),
            'revpar' => $this->factDelta($current, $previous, 'revpar'),
        ];
        $delta['net_pickup'] = $delta['sold_room_nights'];
        $sellableRoomNights = $current['facts']['sellable_room_nights']['value'] ?? null;
        $currentRevenue = $current['facts']['room_revenue']['value'] ?? null;
        $currentAdr = $current['facts']['adr']['value'] ?? null;
        $roomTolerance = $sellableRoomNights === null
            ? null
            : max(1, (int)ceil(abs((float)$sellableRoomNights) * 0.05));
        $revenueTolerance = $currentRevenue === null
            ? null
            : round(max(50, abs((float)$currentRevenue) * 0.005), 2);
        $rateTolerance = $currentAdr === null
            ? null
            : round(max(5, abs((float)$currentAdr) * 0.02), 2);

        $result = [
            ...$base,
            'status' => 'movement_observed',
            'status_label' => '检测到变化',
            'rule_id' => 'PMS_DELTA_MOVEMENT_OBSERVED',
            'elapsed_hours' => $elapsedHours,
            'delta_vector' => $delta,
            'pace' => [
                'net_pickup_per_hour' => $this->perHour($delta['net_pickup'], $elapsedHours),
                'room_revenue_per_hour' => $this->perHour($delta['room_revenue'], $elapsedHours),
            ],
            'tolerance' => [
                'source' => 'cold_start_config',
                'room_nights' => $roomTolerance,
                'room_revenue' => $revenueTolerance,
                'rate' => $rateTolerance,
            ],
            'judgment' => '观察到同一来源的相邻快照变化；这是相关性证据，不自动解释为价格或活动效果。',
            'confidence' => 'medium',
            'recommended_manual_check' => '结合退改、超售、补录、改价和入账时点复核。',
            'data_gaps' => [[
                'code' => 'cumulative_cancellations_missing',
                'message' => '尚无同口径累计取消间夜，只能把已售变化称为账面净拾取，不能计算毛预订。',
            ]],
        ];

        if ($roomTolerance !== null
            && $delta['sellable_room_nights'] !== null
            && abs((float)$delta['sellable_room_nights']) > $roomTolerance
        ) {
            return [
                ...$result,
                'status' => 'rebaseline_required',
                'status_label' => '房量基数变化',
                'rule_id' => 'PMS_DELTA_CAPACITY_CHANGED',
                'judgment' => '前后快照的可售房量基数变化超过动态容差，可能存在关房、维修房、超售或口径变化。',
                'confidence' => 'low',
                'recommended_manual_check' => '核对物理房量与可售口径，并按新基数重新建立基线。',
            ];
        }
        if (($revenueTolerance !== null
                && $delta['room_revenue'] !== null
                && (float)$delta['room_revenue'] < -$revenueTolerance)
            || ($roomTolerance !== null
                && $delta['sold_room_nights'] !== null
                && (float)$delta['sold_room_nights'] < -$roomTolerance)
        ) {
            return [
                ...$result,
                'status' => 'reversal_unknown',
                'status_label' => '累计值回落',
                'rule_id' => 'PMS_DELTA_REVERSAL_UNKNOWN',
                'judgment' => '累计房费或已售间夜出现超过容差的回落，优先视为待解释异常。',
                'confidence' => 'low',
                'recommended_manual_check' => '先核对取消、退款、冲账、改价、换房、补录或数据修订，再讨论运营动作。',
            ];
        }
        if ($elapsedHours < (5 / 60)) {
            return [
                ...$result,
                'status' => 'interval_too_short_noise_risk',
                'status_label' => '间隔过短',
                'rule_id' => 'PMS_DELTA_INTERVAL_SHORT',
                'judgment' => '相邻采集间隔少于5分钟，变化容易受到页面刷新与入账时点噪声影响。',
                'confidence' => 'low',
                'recommended_manual_check' => '保留原始差值，等待更稳定的下一次采集后再判断节奏。',
            ];
        }
        if ($elapsedHours > 6) {
            return [
                ...$result,
                'status' => 'interval_too_long_low_comparability',
                'status_label' => '间隔过长',
                'rule_id' => 'PMS_DELTA_INTERVAL_LONG',
                'judgment' => '相邻采集间隔超过6小时，期间事件过多，不适合直接解释短时经营节奏。',
                'confidence' => 'low',
                'recommended_manual_check' => '缩短采集间隔后重新比较；本轮仅保留累计变化事实。',
            ];
        }

        return $this->classifyMovement(
            $result,
            $delta,
            $roomTolerance,
            $revenueTolerance,
            $rateTolerance
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, int|float|null> $delta
     * @return array<string, mixed>
     */
    private function classifyMovement(
        array $result,
        array $delta,
        ?int $roomTolerance,
        ?float $revenueTolerance,
        ?float $rateTolerance
    ): array {
        if ($roomTolerance === null || $revenueTolerance === null || $rateTolerance === null) {
            return [
                ...$result,
                'status' => 'partial',
                'status_label' => '差值部分可用',
                'rule_id' => 'PMS_DELTA_TOLERANCE_INPUT_MISSING',
                'judgment' => '缺少生成动态容差所需的房量、房费或 ADR，保留已取得差值但不判定结构。',
                'confidence' => 'low',
            ];
        }

        $sold = (float)($delta['sold_room_nights'] ?? 0);
        $revenue = (float)($delta['room_revenue'] ?? 0);
        $adr = (float)($delta['adr'] ?? 0);
        $revpar = (float)($delta['revpar'] ?? 0);
        $soldMoved = abs($sold) > $roomTolerance;
        $revenueMoved = abs($revenue) > $revenueTolerance;
        $adrMoved = abs($adr) > $rateTolerance;
        $revparMoved = abs($revpar) > $rateTolerance;

        if (!$soldMoved && !$revenueMoved && !$adrMoved && !$revparMoved) {
            return [
                ...$result,
                'status' => 'no_movement',
                'status_label' => '容差内稳定',
                'rule_id' => 'PMS_DELTA_NO_MOVEMENT',
                'judgment' => '房量、房费、ADR 与 RevPAR 的变化都在冷启动动态容差内。',
                'recommended_manual_check' => '继续观察；只有绑定已执行动作后，才能评价动作是否有响应。',
            ];
        }
        if ($sold > $roomTolerance
            && $revenue > $revenueTolerance
            && $adr > $rateTolerance
            && $revpar > $rateTolerance
        ) {
            return [
                ...$result,
                'status' => 'volume_rate_up',
                'status_label' => '量价同步上升',
                'rule_id' => 'PMS_DELTA_VOLUME_RATE_UP',
                'judgment' => '净已售、房费、ADR 与 RevPAR 同时上升；这是量价结构改善的观察信号，不是动作因果结论。',
            ];
        }
        if ($sold > $roomTolerance
            && $revenue > $revenueTolerance
            && $adr < -$rateTolerance
            && $revpar > $rateTolerance
        ) {
            return [
                ...$result,
                'status' => 'volume_driven_improvement',
                'status_label' => '增量偏向以价换量',
                'rule_id' => 'PMS_DELTA_VOLUME_DRIVEN',
                'judgment' => '净已售与房费上升、ADR下降、RevPAR上升；需继续观察价格稀释是否可接受。',
            ];
        }
        if ($sold > $roomTolerance
            && $revenue > $revenueTolerance
            && $adr < -$rateTolerance
            && $revpar < -$rateTolerance
        ) {
            return [
                ...$result,
                'status' => 'low_yield_growth_review',
                'status_label' => '低收益增量待复核',
                'rule_id' => 'PMS_DELTA_LOW_YIELD_GROWTH',
                'judgment' => '净已售与房费上升，但 ADR 与 RevPAR 同时下降；先核对房量和累计/区间口径，再讨论低收益占房。',
            ];
        }
        if (!$soldMoved && $revenue > $revenueTolerance) {
            return [
                ...$result,
                'status' => 'posting_or_rate_adjustment',
                'status_label' => '入账或房价变化',
                'rule_id' => 'PMS_DELTA_POSTING_OR_RATE',
                'judgment' => '已售变化在容差内但房费明显上升，优先核对补录、改价或入账时点。',
            ];
        }
        if ($sold > $roomTolerance && !$revenueMoved) {
            return [
                ...$result,
                'status' => 'revenue_posting_lag_or_scope_mismatch',
                'status_label' => '收入入账待核对',
                'rule_id' => 'PMS_DELTA_REVENUE_LAG',
                'judgment' => '净已售明显上升但房费变化在容差内，优先核对收入入账时点或事实范围。',
            ];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $capture
     * @return array<string, mixed>
     */
    private function normalizeSource(string $provider, string $businessDate, array $capture): array
    {
        $isDingdandao = $provider === DingdandaoOperatingTargetCaptureService::PROVIDER;
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
        $captureDate = (string)($capture['business_date'] ?? '');
        $dateStatus = (string)($capture['date_status'] ?? '');
        if ($dateStatus === '') {
            $dateStatus = $isDingdandao && $captureDate === $businessDate
                ? 'matched'
                : 'unverified';
        }
        $usable = $captureDate === $businessDate
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && $dateStatus === 'matched'
            && (string)($capture['reconciliation_status'] ?? '') === 'matched'
            && (string)($capture['capture_status'] ?? '') === 'verified'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified';

        $gaps = [];
        foreach (($capture['gaps'] ?? []) as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $code = trim((string)($gap['code'] ?? ''));
            $message = trim((string)($gap['message'] ?? ''));
            if ($code !== '' || $message !== '') {
                $gaps[] = ['code' => $code, 'message' => $message];
            }
        }
        if (!$usable && $gaps === []) {
            $gaps[] = [
                'code' => $provider . '_fact_not_verified',
                'message' => '该来源尚未同时通过门店、日期、内部对账和数据库回读门禁。',
            ];
        }

        return [
            'provider' => $provider,
            'provider_label' => $isDingdandao ? '订单来了 PMS' : '美团云 PMS',
            'usable' => $usable,
            'status' => $usable
                ? 'verified'
                : (string)($capture['quality_status'] ?? $capture['status'] ?? 'missing'),
            'status_label' => $usable ? '事实可用' : $this->sourceStatusLabel($capture),
            'business_date' => $captureDate !== '' ? $captureDate : $businessDate,
            'provider_hotel_id' => $capture['provider_hotel_id'] ?? null,
            'provider_hotel_name' => $capture['provider_hotel_name'] ?? null,
            'identity_status' => (string)($capture['identity_status'] ?? 'unverified'),
            'date_status' => $dateStatus,
            'reconciliation_status' => (string)($capture['reconciliation_status'] ?? 'unverified'),
            'readback_status' => (string)($capture['readback_status'] ?? 'missing'),
            'captured_at' => $capture['captured_at'] ?? null,
            'source_scope' => (string)($capture['source_scope'] ?? ''),
            'scope_note' => $isDingdandao
                ? DingdandaoOperatingTargetCaptureService::RENDER_SCOPE_NOTE
                : MeituanCloudPmsCaptureService::RENDER_SCOPE_NOTE,
            'facts' => [
                'room_revenue' => [
                    'value' => $this->numberOrNull(
                        $isDingdandao
                            ? ($summary['total_room_fee'] ?? null)
                            : ($summary['estimated_room_revenue'] ?? null)
                    ),
                    'source_field' => $isDingdandao
                        ? 'summary.total_room_fee'
                        : 'summary.estimated_room_revenue',
                    'value_status' => 'source',
                ],
                'sold_room_nights' => [
                    'value' => $this->numberOrNull($summary['sold_room_nights'] ?? null),
                    'source_field' => 'summary.sold_room_nights',
                    'value_status' => 'source',
                ],
                'sellable_room_nights' => [
                    'value' => $this->numberOrNull(
                        $isDingdandao
                            ? ($summary['derived_sellable_room_nights'] ?? null)
                            : ($summary['total_rooms'] ?? null)
                    ),
                    'source_field' => $isDingdandao
                        ? 'summary.derived_sellable_room_nights'
                        : 'summary.total_rooms',
                    'value_status' => $isDingdandao ? 'derived_verified' : 'source',
                ],
                'occupancy_rate_percent' => [
                    'value' => $this->numberOrNull($summary['occupancy_rate_percent'] ?? null),
                    'source_field' => 'summary.occupancy_rate_percent',
                    'value_status' => 'source',
                ],
                'adr' => [
                    'value' => $this->numberOrNull($summary['adr'] ?? null),
                    'source_field' => 'summary.adr',
                    'value_status' => 'source',
                ],
                'revpar' => [
                    'value' => $this->numberOrNull($summary['revpar'] ?? null),
                    'source_field' => 'summary.revpar',
                    'value_status' => 'source',
                ],
            ],
            'gaps' => $gaps,
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    private function compareMetric(
        string $key,
        array $policy,
        array $sources,
        int $verifiedSourceCount,
        bool $timeAligned,
        ?int $captureSkewSeconds
    ): array {
        $dingdandao = $sources[DingdandaoOperatingTargetCaptureService::PROVIDER];
        $meituan = $sources[MeituanCloudPmsCaptureService::PROVIDER];
        $left = $dingdandao['facts'][$key]['value'] ?? null;
        $right = $meituan['facts'][$key]['value'] ?? null;
        $row = [
            'key' => $key,
            'label' => (string)$policy['label'],
            'kind' => (string)$policy['kind'],
            'dingdandao_value' => $left,
            'meituan_cloud_value' => $right,
            'difference' => null,
            'absolute_difference' => null,
            'tolerance' => null,
            'status' => 'not_comparable',
            'status_label' => '暂不比较',
            'message' => '',
        ];

        if (($policy['comparable'] ?? false) !== true) {
            $row['status'] = 'semantic_mismatch';
            $row['status_label'] = '口径不同';
            $row['message'] = (string)($policy['reason'] ?? '来源口径不同，不直接计算差值。');
            return $row;
        }
        if ($verifiedSourceCount !== 2) {
            $row['status'] = 'source_unverified';
            $row['status_label'] = '来源未齐';
            $row['message'] = '两个独立 PMS 尚未同时通过自身验真与数据库回读门禁。';
            return $row;
        }
        if ($left === null || $right === null) {
            $row['status'] = 'missing';
            $row['status_label'] = '字段缺失';
            $row['message'] = '同口径字段未同时取得，不用0或历史值补齐。';
            return $row;
        }

        $difference = round((float)$right - (float)$left, 2);
        $absoluteDifference = round(abs($difference), 2);
        $tolerance = $this->tolerance($policy, (float)$left, (float)$right);
        $row['difference'] = $difference;
        $row['absolute_difference'] = $absoluteDifference;
        $row['tolerance'] = $tolerance;

        if (!$timeAligned) {
            $row['status'] = 'time_misaligned';
            $row['status_label'] = '时间未对齐';
            $row['message'] = $captureSkewSeconds === null
                ? '缺少可信采集时间，保留数值但不判定差值异常。'
                : '两个来源采集时间相差超过15分钟，保留数值但不判定差值异常。';
            return $row;
        }

        if ($absoluteDifference <= $tolerance) {
            $row['status'] = 'aligned';
            $row['status_label'] = '容差内';
            $row['message'] = '差值在宿析本地同口径诊断容差内；两个来源仍保持独立身份。';
            return $row;
        }

        $row['status'] = 'needs_review';
        $row['status_label'] = '需复核';
        $row['message'] = '差值超过宿析本地诊断容差；先核对采集时点、退改/超售和平台字段口径，不自动认定任一来源错误。';
        return $row;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function tolerance(array $policy, float $left, float $right): float
    {
        if (isset($policy['absolute_tolerance'])) {
            return round((float)$policy['absolute_tolerance'], 2);
        }
        $base = max(abs($left), abs($right));
        $minimum = (float)($policy['minimum_tolerance'] ?? 0);
        $ratio = (float)($policy['ratio_tolerance'] ?? 0);
        $tolerance = max($minimum, $base * $ratio);
        if (($policy['kind'] ?? '') === 'count') {
            return (float)ceil($tolerance);
        }
        return round($tolerance, 2);
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     */
    private function captureSkewSeconds(array $sources): ?int
    {
        $timestamps = [];
        foreach ([
            DingdandaoOperatingTargetCaptureService::PROVIDER,
            MeituanCloudPmsCaptureService::PROVIDER,
        ] as $provider) {
            if (($sources[$provider]['usable'] ?? false) !== true) {
                return null;
            }
            $timestamp = $this->timestamp($sources[$provider]['captured_at'] ?? null);
            if ($timestamp === null) {
                return null;
            }
            $timestamps[] = $timestamp;
        }
        return abs($timestamps[1] - $timestamps[0]);
    }

    /**
     * @param list<array<string, mixed>> $metrics
     * @return array<string, mixed>
     */
    private function decision(
        int $verifiedSourceCount,
        bool $timeAligned,
        ?int $captureSkewSeconds,
        array $metrics
    ): array {
        if ($verifiedSourceCount === 0) {
            return [
                'status' => 'no_verified_source',
                'status_label' => '暂无可用 PMS 事实',
                'facts_available' => false,
                'requires_operator_review' => false,
                'preferred_source' => null,
                'summary' => '两个来源都尚未完成同店、同日、内部对账和数据库回读。',
                'next_action' => '分别完成任一 PMS 的可信采集；不能用另一来源、OTA、历史值或0补齐。',
            ];
        }
        if ($verifiedSourceCount === 1) {
            return [
                'status' => 'single_source_verified',
                'status_label' => '单一来源可用',
                'facts_available' => true,
                'requires_operator_review' => false,
                'preferred_source' => null,
                'summary' => '已有一个独立 PMS 来源通过验真；另一来源缺失不会否定它，也不会被自动补齐。',
                'next_action' => '可按已验证来源继续使用，并保留来源名称、采集时间和业务日期。',
            ];
        }
        if (!$timeAligned) {
            return [
                'status' => 'dual_source_time_misaligned',
                'status_label' => '双源可用，时间未对齐',
                'facts_available' => true,
                'requires_operator_review' => true,
                'preferred_source' => null,
                'summary' => $captureSkewSeconds === null
                    ? '两个来源都已验证，但缺少可比较的采集时间。'
                    : '两个来源都已验证，但采集时间相差超过15分钟，本轮不判断差值异常。',
                'next_action' => '在同一营业日期、相近采集时点重新采集后再比较。',
            ];
        }

        $needsReview = array_filter(
            $metrics,
            static fn(array $metric): bool => ($metric['status'] ?? '') === 'needs_review'
        );
        if ($needsReview !== []) {
            return [
                'status' => 'dual_source_needs_review',
                'status_label' => '双源差值需复核',
                'facts_available' => true,
                'requires_operator_review' => true,
                'preferred_source' => null,
                'summary' => '两个来源各自都已验证，但至少一个同口径指标超过宿析本地诊断容差。',
                'next_action' => '核对采集时点、退改/超售和字段定义后，由人工选择本次使用来源；系统不自动覆盖。',
            ];
        }

        return [
            'status' => 'dual_source_aligned',
            'status_label' => '双源同口径指标一致',
            'facts_available' => true,
            'requires_operator_review' => false,
            'preferred_source' => null,
            'summary' => '两个来源各自通过验真，且可比较指标均在宿析本地诊断容差内。',
            'next_action' => '可继续按任一已验证来源使用，但仍须保留来源身份；不同收入口径不得合并。',
        ];
    }

    /**
     * @return array<string, null>
     */
    private function emptyDeltaVector(): array
    {
        return [
            'room_revenue' => null,
            'sold_room_nights' => null,
            'sellable_room_nights' => null,
            'adr' => null,
            'occupancy_rate_points' => null,
            'revpar' => null,
            'net_pickup' => null,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     */
    private function factDelta(array $current, array $previous, string $key): int|float|null
    {
        $currentValue = $current['facts'][$key]['value'] ?? null;
        $previousValue = $previous['facts'][$key]['value'] ?? null;
        if ($currentValue === null || $previousValue === null) {
            return null;
        }
        $difference = round((float)$currentValue - (float)$previousValue, 2);
        return floor($difference) === $difference ? (int)$difference : $difference;
    }

    private function perHour(int|float|null $value, float $elapsedHours): ?float
    {
        if ($value === null || $elapsedHours <= 0) {
            return null;
        }
        return round((float)$value / $elapsedHours, 2);
    }

    /** @param array<string, mixed> $capture */
    private function sourceStatusLabel(array $capture): string
    {
        return match ((string)($capture['quality_status'] ?? $capture['status'] ?? 'missing')) {
            'missing' => '尚无当日事实',
            'collection_failed' => '采集或回读失败',
            'identity_mismatch' => '门店身份不匹配',
            'partial' => '事实不完整',
            default => '事实未验证',
        };
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            return null;
        }
        return floor($number) === $number ? (int)$number : round($number, 2);
    }

    private function timestamp(mixed $value): ?int
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('pms_reconciliation_date_invalid');
        }
        return $date->format('Y-m-d');
    }
}
