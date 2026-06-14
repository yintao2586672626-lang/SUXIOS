<?php
declare(strict_types=1);

namespace app\service;

use app\model\CompetitorAnalysis;
use app\model\DemandForecast;
use think\facade\Db;

class RevenuePricingRecommendationService
{
    private const MODEL_VERSION = 'advisory_revenue_pricing_v1';
    private const MAX_CHANGE_RATE = 0.20;
    private const MIN_MATERIAL_CHANGE = 1.0;
    private const MIN_PRIMARY_SIGNAL_COUNT = 2;
    private const COMPETITOR_LOOKBACK_DAYS = 7;

    /** @var array<string, array<string, mixed>> */
    private array $hotelSignalCache = [];

    /**
     * @param array<string, mixed> $roomType
     * @return array<string, mixed>
     */
    public function recommend(int $hotelId, array $roomType, string $targetDate): array
    {
        $roomTypeId = (int)($roomType['id'] ?? 0);
        $hotelSignals = $this->hotelSignals($hotelId, $targetDate);
        $forecast = $this->forecastSignal($hotelId, $roomTypeId, $targetDate, $roomType);
        $competitor = $this->competitorSignal($hotelId, $roomTypeId, $targetDate, (float)($roomType['base_price'] ?? 0));
        $inventory = $this->inventorySignal($roomType, $forecast);

        $signals = array_merge($hotelSignals, [
            'demand_forecast' => $forecast,
            'competitor' => $competitor,
            'inventory' => $inventory,
        ]);
        $signals['data_gaps'] = $this->uniqueStrings(array_filter(array_merge(
            $hotelSignals['data_gaps'] ?? [],
            $forecast['data_gaps'] ?? [],
            $competitor['data_gaps'] ?? [],
            $inventory['data_gaps'] ?? []
        )));

        return $this->recommendFromSignals($roomType, $signals);
    }

    /**
     * @return array<string, mixed>
     */
    public function hotelPricingModelSummary(int $hotelId, string $targetDate): array
    {
        $signals = $this->hotelSignals($hotelId, $targetDate);

        return [
            'advisory_only' => true,
            'model' => self::MODEL_VERSION,
            'target_date' => $targetDate,
            'create_policy' => [
                'minimum_primary_signal_count' => self::MIN_PRIMARY_SIGNAL_COUNT,
                'minimum_price_change_amount' => self::MIN_MATERIAL_CHANGE,
                'max_single_change_rate' => self::MAX_CHANGE_RATE,
            ],
            'pickup_curve' => $signals['pickup'] ?? [],
            'price_elasticity' => $signals['elasticity'] ?? [],
            'backtest' => $signals['backtest'] ?? [],
            'holiday' => $signals['holiday'] ?? [],
            'data_gaps' => $signals['data_gaps'] ?? [],
        ];
    }

    /**
     * Pure recommendation step. Kept public so tests can cover model behavior without a database.
     *
     * @param array<string, mixed> $roomType
     * @param array<string, mixed> $signals
     * @return array<string, mixed>
     */
    public function recommendFromSignals(array $roomType, array $signals): array
    {
        $currentPrice = $this->toFloat($roomType['base_price'] ?? 0);
        $minPrice = $this->toFloat($roomType['min_price'] ?? 0);
        $maxPrice = $this->toFloat($roomType['max_price'] ?? 0);
        if ($currentPrice <= 0) {
            return $this->emptyRecommendation($roomType, $signals, 'current_price_missing');
        }

        $changeRate = 0.0;
        $factorNotes = [];
        $drivers = [];

        $forecast = $signals['demand_forecast'] ?? [];
        $occupancy = $this->toNullableFloat($forecast['predicted_occupancy'] ?? null);
        if ($occupancy !== null && $occupancy > 0) {
            if ($occupancy >= 90) {
                $changeRate += 0.14;
                $factorNotes[] = 'demand_forecast:occupancy>=90';
                $drivers[] = $this->driver('demand_forecast', 'occupancy>=90', 0.14, 'increase');
            } elseif ($occupancy >= 80) {
                $changeRate += 0.10;
                $factorNotes[] = 'demand_forecast:occupancy>=80';
                $drivers[] = $this->driver('demand_forecast', 'occupancy>=80', 0.10, 'increase');
            } elseif ($occupancy <= 45) {
                $changeRate -= 0.08;
                $factorNotes[] = 'demand_forecast:occupancy<=45';
                $drivers[] = $this->driver('demand_forecast', 'occupancy<=45', -0.08, 'decrease');
            } elseif ($occupancy <= 60) {
                $changeRate -= 0.04;
                $factorNotes[] = 'demand_forecast:occupancy<=60';
                $drivers[] = $this->driver('demand_forecast', 'occupancy<=60', -0.04, 'decrease');
            }
        }

        $pickup = $signals['pickup'] ?? [];
        $paceIndex = $this->toNullableFloat($pickup['pace_index'] ?? null);
        if ($paceIndex !== null && ($pickup['data_status'] ?? '') === 'ok') {
            if ($paceIndex >= 130) {
                $changeRate += 0.06;
                $factorNotes[] = 'pickup_curve:pace_index>=130';
                $drivers[] = $this->driver('pickup_curve', 'pace_index>=130', 0.06, 'increase');
            } elseif ($paceIndex >= 110) {
                $changeRate += 0.03;
                $factorNotes[] = 'pickup_curve:pace_index>=110';
                $drivers[] = $this->driver('pickup_curve', 'pace_index>=110', 0.03, 'increase');
            } elseif ($paceIndex <= 70) {
                $changeRate -= 0.06;
                $factorNotes[] = 'pickup_curve:pace_index<=70';
                $drivers[] = $this->driver('pickup_curve', 'pace_index<=70', -0.06, 'decrease');
            } elseif ($paceIndex <= 90) {
                $changeRate -= 0.03;
                $factorNotes[] = 'pickup_curve:pace_index<=90';
                $drivers[] = $this->driver('pickup_curve', 'pace_index<=90', -0.03, 'decrease');
            }
        }

        $competitor = $signals['competitor'] ?? [];
        $gapPercent = $this->toNullableFloat($competitor['gap_percent'] ?? null);
        if ($gapPercent !== null && ($competitor['data_status'] ?? '') === 'ok') {
            if ($gapPercent >= 10) {
                $changeRate += 0.05;
                $factorNotes[] = 'competitor_price:avg>=current+10%';
                $drivers[] = $this->driver('competitor_price', 'avg>=current+10%', 0.05, 'increase');
            } elseif ($gapPercent <= -10) {
                $changeRate -= 0.05;
                $factorNotes[] = 'competitor_price:avg<=current-10%';
                $drivers[] = $this->driver('competitor_price', 'avg<=current-10%', -0.05, 'decrease');
            }
        }

        $holiday = $signals['holiday'] ?? [];
        if (($holiday['data_status'] ?? '') === 'ok') {
            if (!empty($holiday['is_in_holiday'])) {
                $changeRate += 0.08;
                $factorNotes[] = 'holiday:in_holiday';
                $drivers[] = $this->driver('holiday', 'in_holiday', 0.08, 'increase');
            } elseif (!empty($holiday['is_holiday_window'])) {
                $changeRate += 0.04;
                $factorNotes[] = 'holiday:near_holiday';
                $drivers[] = $this->driver('holiday', 'near_holiday', 0.04, 'increase');
            } elseif (!empty($holiday['is_weekend'])) {
                $changeRate += 0.03;
                $factorNotes[] = 'holiday:weekend';
                $drivers[] = $this->driver('holiday', 'weekend', 0.03, 'increase');
            }
        }

        $inventory = $signals['inventory'] ?? [];
        $utilization = $this->toNullableFloat($inventory['utilization_percent'] ?? null);
        if ($utilization !== null && ($inventory['data_status'] ?? '') === 'ok') {
            if ($utilization >= 95) {
                $changeRate += 0.08;
                $factorNotes[] = 'inventory:utilization>=95';
                $drivers[] = $this->driver('inventory', 'utilization>=95', 0.08, 'increase');
            } elseif ($utilization >= 85) {
                $changeRate += 0.04;
                $factorNotes[] = 'inventory:utilization>=85';
                $drivers[] = $this->driver('inventory', 'utilization>=85', 0.04, 'increase');
            } elseif ($utilization <= 45) {
                $changeRate -= 0.06;
                $factorNotes[] = 'inventory:utilization<=45';
                $drivers[] = $this->driver('inventory', 'utilization<=45', -0.06, 'decrease');
            }
        }

        $elasticity = $signals['elasticity'] ?? [];
        $elasticityValue = $this->toNullableFloat($elasticity['elasticity'] ?? null);
        if ($elasticityValue !== null && ($elasticity['data_status'] ?? '') === 'ok') {
            if ($changeRate > 0 && $elasticityValue <= -1.5) {
                $changeRate -= 0.04;
                $factorNotes[] = 'price_elasticity:sensitive_cap_increase';
                $drivers[] = $this->driver('price_elasticity', 'sensitive_cap_increase', -0.04, 'decrease');
            } elseif ($changeRate < 0 && $elasticityValue <= -1.0) {
                $changeRate -= 0.03;
                $factorNotes[] = 'price_elasticity:sensitive_support_discount';
                $drivers[] = $this->driver('price_elasticity', 'sensitive_support_discount', -0.03, 'decrease');
            } elseif ($changeRate > 0 && $elasticityValue > -0.5 && $elasticityValue < 0) {
                $changeRate += 0.03;
                $factorNotes[] = 'price_elasticity:inelastic_support_increase';
                $drivers[] = $this->driver('price_elasticity', 'inelastic_support_increase', 0.03, 'increase');
            } elseif ($elasticityValue >= 0) {
                $factorNotes[] = 'price_elasticity:non_negative_manual_review';
            }
        }

        $changeRate = max(-self::MAX_CHANGE_RATE, min(self::MAX_CHANGE_RATE, $changeRate));
        $rawSuggested = round($currentPrice * (1 + $changeRate), 2);
        $suggested = $rawSuggested;
        $constraints = [
            'max_single_change_rate' => self::MAX_CHANGE_RATE,
            'min_material_change' => self::MIN_MATERIAL_CHANGE,
        ];
        if ($minPrice > 0 && $suggested < $minPrice) {
            $suggested = $minPrice;
            $constraints['applied_min_price'] = $minPrice;
        }
        if ($maxPrice > 0 && $suggested > $maxPrice) {
            $suggested = $maxPrice;
            $constraints['applied_max_price'] = $maxPrice;
        }

        $priceDelta = round($suggested - $currentPrice, 2);
        $primarySignalCount = $this->primaryDriverCount($drivers);
        $confidence = $this->confidenceScore($signals);
        $direction = $priceDelta > 0 ? 'increase' : ($priceDelta < 0 ? 'decrease' : 'hold');
        $skipReason = $this->skipReason($priceDelta, $factorNotes, $primarySignalCount);
        $shouldCreate = $skipReason === '';
        $riskLevel = $this->riskLevel($confidence, $signals, $primarySignalCount);
        $reviewChecklist = $this->reviewChecklist($signals, $drivers, $riskLevel);

        return [
            'should_create' => $shouldCreate,
            'skip_reason' => $skipReason,
            'advisory_only' => true,
            'action' => $direction,
            'current_price' => round($currentPrice, 2),
            'suggested_price' => round($suggested, 2),
            'raw_suggested_price' => $rawSuggested,
            'price_change_rate' => $currentPrice > 0 ? round($priceDelta / $currentPrice * 100, 2) : 0.0,
            'confidence_score' => $confidence,
            'risk_level' => $riskLevel,
            'reason' => $this->buildReason($direction, $factorNotes, $signals),
            'factor_notes' => $factorNotes,
            'drivers' => $drivers,
            'review_checklist' => $reviewChecklist,
            'primary_signal_count' => $primarySignalCount,
            'competitor_data' => $competitor,
            'factors' => [
                'model' => self::MODEL_VERSION,
                'advisory_only' => true,
                'target' => [
                    'action' => $direction,
                    'current_price' => round($currentPrice, 2),
                    'suggested_price' => round($suggested, 2),
                    'price_change_rate' => $currentPrice > 0 ? round($priceDelta / $currentPrice * 100, 2) : 0.0,
                ],
                'signals' => $signals,
                'factor_notes' => $factorNotes,
                'drivers' => $drivers,
                'confidence_score' => $confidence,
                'risk_level' => $riskLevel,
                'review_checklist' => $reviewChecklist,
                'primary_signal_count' => $primarySignalCount,
                'constraints' => $constraints,
                'create_policy' => [
                    'minimum_primary_signal_count' => self::MIN_PRIMARY_SIGNAL_COUNT,
                    'minimum_price_change_amount' => self::MIN_MATERIAL_CHANGE,
                    'max_single_change_rate' => self::MAX_CHANGE_RATE,
                ],
                'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $suggestion
     * @param array<string, mixed>|null $executionItem
     * @return array<string, mixed>
     */
    public function buildSuggestionReadiness(array $suggestion, ?array $executionItem = null): array
    {
        $factors = is_array($suggestion['factors'] ?? null) ? $suggestion['factors'] : [];
        $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
        $dataGaps = array_values(array_filter(array_map('strval', (array)($signals['data_gaps'] ?? []))));
        $status = (int)($suggestion['status'] ?? 0);
        $riskLevel = strtolower((string)($factors['risk_level'] ?? $suggestion['risk_level'] ?? ''));
        $confidence = $this->toNullableFloat($factors['confidence_score'] ?? $suggestion['confidence_score'] ?? null);
        $primarySignalCount = (int)($factors['primary_signal_count'] ?? $suggestion['primary_signal_count'] ?? 0);
        $advisoryBoundary = (string)($factors['decision_boundary'] ?? '') === 'manual_review_required_no_auto_rate_write';
        $sourceReady = $primarySignalCount >= self::MIN_PRIMARY_SIGNAL_COUNT && empty($dataGaps);
        $riskClear = !in_array($riskLevel, ['high', 'medium_high'], true)
            && ($confidence === null || $confidence >= 0.6);
        $approved = in_array($status, [2, 4], true);
        $appliedLocal = $status === 4;
        $executionLinked = is_array($executionItem) && !empty($executionItem);
        $executionStage = $executionLinked ? (string)($executionItem['stage'] ?? '') : '';
        $evidenceReady = (int)($executionItem['evidence']['count'] ?? 0) > 0;
        $roiReady = (string)($executionItem['roi']['status'] ?? '') === 'ready';

        $checks = [
            $this->readinessCheck('pricing_signal', '调价信号', $sourceReady, '已满足主信号数量且无阻断性数据缺口', '先补齐需求预测、拾取、竞价、库存或弹性样本。', 20),
            $this->readinessCheck('advisory_boundary', '人工边界', $advisoryBoundary, '已标记为仅建议、禁止自动写 OTA 房价', '保留 manual_review_required_no_auto_rate_write 边界。', 10),
            $this->readinessCheck('risk_recheck', '风险复核', $riskClear, '置信度和风险等级未触发阻断', '先复核高风险、低置信度或数据缺口后再审批。', 15),
            $this->readinessCheck('manual_approval', '人工审批', $approved, '建议已通过人工审批或进入应用状态', '先完成批准/拒绝，不把待审建议当作执行动作。', 15),
            $this->readinessCheck('execution_intent', '执行意图', $executionLinked, '已关联运营执行意图', '创建执行意图，进入审批、执行、证据、复盘链路。', 15),
            $this->readinessCheck('local_price_applied', '本地价格应用', $appliedLocal, '已更新本地房型基础价', '如确认执行，先应用到本地房型价；OTA 仍需人工执行证据。', 10),
            $this->readinessCheck('execution_evidence', '执行证据', $evidenceReady, '已记录执行证据', '补充 OTA 后台、房价日历或执行截图等证据。', 10),
            $this->readinessCheck('roi_review', '效果复盘', $roiReady, '已形成 ROI/效果复盘', '完成调价后效果复盘，确认收入、间夜、ADR 或转化变化。', 5),
        ];

        $missingEvidence = [];
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += (int)$check['weight'];
                continue;
            }
            $missingEvidence[] = [
                'code' => $check['key'],
                'label' => $check['label'],
                'next_action' => $check['next_action'],
            ];
        }

        $stage = $this->pricingReadinessStage(
            (int)($suggestion['id'] ?? 0),
            $status,
            $sourceReady,
            $riskClear,
            $approved,
            $executionLinked,
            $executionStage,
            $appliedLocal,
            $evidenceReady,
            $roiReady
        );

        return [
            'stage' => $stage,
            'status_label' => $this->pricingReadinessStageLabel($stage),
            'score' => $score,
            'ready_for_review' => in_array($stage, ['evidence_ready', 'pricing_ready'], true),
            'pricing_ready' => $stage === 'pricing_ready',
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => $missingEvidence[0]['next_action'] ?? '持续复盘调价效果，并保留执行证据。',
            'notice' => $this->pricingReadinessNotice($stage),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $executionItemsByRecordId
     * @return array<int, array<string, mixed>>
     */
    public function enrichSuggestionRows(array $rows, array $executionItemsByRecordId = []): array
    {
        return array_map(function (array $row) use ($executionItemsByRecordId): array {
            $row = $this->normalizeSuggestionDisplayFields($row);
            $id = (int)($row['id'] ?? 0);
            $row['pricing_readiness'] = $this->buildSuggestionReadiness($row, $executionItemsByRecordId[$id] ?? null);
            return $row;
        }, $rows);
    }

    public function buildEffectReviewReadiness(array $suggestion, array $before, array $after, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $status = (int)($suggestion['status'] ?? 0);
        $applied = $status === 4 || trim((string)($suggestion['applied_time'] ?? '')) !== '';
        $beforeStatus = (string)($before['data_status'] ?? 'unknown');
        $afterStatus = (string)($after['data_status'] ?? 'unknown');
        $beforeSamples = (int)($before['sample_count'] ?? 0);
        $afterSamples = (int)($after['sample_count'] ?? 0);
        $afterEnd = substr((string)($after['end_date'] ?? ''), 0, 10);
        $windowClosed = $afterEnd !== '' && $afterEnd <= $today;

        if (!$applied) {
            return $this->effectReviewReadiness('effect_review_not_started', '未应用', 30, false, '先应用或完成执行意图后再复盘', [
                $this->missingEvidence('local_price_applied', '本地价格应用', '先应用或完成执行意图后再复盘'),
            ]);
        }

        if ($beforeStatus === 'read_failed' || $afterStatus === 'read_failed') {
            return $this->effectReviewReadiness('effect_review_read_failed', '复盘读取失败', 35, false, '修复复盘数据读取错误后再判断效果', [
                $this->missingEvidence('review_source_readable', '复盘数据可读', '修复 online_daily_data 读取错误'),
            ]);
        }

        if (!$windowClosed) {
            return $this->effectReviewReadiness('effect_review_window_open', '等待周期', 55, false, '等待应用后7天窗口结束再复盘', [
                $this->missingEvidence('review_window', '完整复盘周期', '等待应用后7天窗口结束再复盘'),
            ]);
        }

        if ($beforeSamples <= 0 || $afterSamples <= 0) {
            return $this->effectReviewReadiness('effect_review_sample_missing', '样本不足', 60, false, '补齐应用前后线上经营样本后再判断效果', [
                $this->missingEvidence('before_after_samples', '应用前后样本', '补齐应用前后线上经营样本'),
            ]);
        }

        return $this->effectReviewReadiness('effect_review_ready', '复盘可用', 100, true, '将复盘结论沉淀到执行证据或 ROI 记录');
    }

    private function effectReviewReadiness(string $stage, string $label, int $score, bool $reviewReady, string $nextAction, array $missingEvidence = []): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'review_ready' => $reviewReady,
            'missing_evidence' => $missingEvidence,
            'next_action' => $nextAction,
            'notice' => $missingEvidence
                ? '仍缺：' . implode('、', array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missingEvidence))
                : '应用前后样本已满足复盘判断；需继续沉淀执行证据或 ROI 记录。',
        ];
    }

    private function missingEvidence(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function estimatePriceElasticity(array $rows): array
    {
        $points = [];
        foreach ($this->aggregateOnlineRowsByDate($rows) as $row) {
            $amount = $this->toFloat($row['amount'] ?? 0);
            $quantity = $this->toFloat($row['quantity'] ?? 0);
            if ($amount > 0 && $quantity > 0) {
                $points[] = [
                    'adr' => $amount / $quantity,
                    'quantity' => $quantity,
                ];
            }
        }

        if (count($points) < 10) {
            return [
                'data_status' => 'insufficient',
                'sample_count' => count($points),
                'elasticity' => null,
                'data_gaps' => ['elasticity_sample_lt_10'],
            ];
        }

        $logPrices = array_map(static fn(array $row): float => log($row['adr']), $points);
        $logDemand = array_map(static fn(array $row): float => log($row['quantity']), $points);
        $meanPrice = array_sum($logPrices) / count($logPrices);
        $meanDemand = array_sum($logDemand) / count($logDemand);
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($logPrices as $index => $price) {
            $dx = $price - $meanPrice;
            $dy = $logDemand[$index] - $meanDemand;
            $numerator += $dx * $dy;
            $denominator += $dx * $dx;
        }
        if ($denominator <= 0.0001) {
            return [
                'data_status' => 'insufficient',
                'sample_count' => count($points),
                'elasticity' => null,
                'data_gaps' => ['elasticity_price_variation_insufficient'],
            ];
        }

        $elasticity = round($numerator / $denominator, 3);
        $backtest = $this->medianSplitBacktest($points);

        return [
            'data_status' => 'ok',
            'sample_count' => count($points),
            'elasticity' => $elasticity,
            'interpretation' => $elasticity < -1 ? 'price_sensitive' : ($elasticity < 0 ? 'weak_negative' : 'non_negative'),
            'backtest' => $backtest,
            'data_gaps' => $elasticity >= 0 ? ['elasticity_non_negative_manual_review'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRecommendation(array $roomType, array $signals, string $reason): array
    {
        return [
            'should_create' => false,
            'skip_reason' => $reason,
            'advisory_only' => true,
            'action' => 'hold',
            'current_price' => $this->toFloat($roomType['base_price'] ?? 0),
            'suggested_price' => $this->toFloat($roomType['base_price'] ?? 0),
            'confidence_score' => 0.0,
            'risk_level' => 'high',
            'reason' => $reason,
            'factor_notes' => [],
            'drivers' => [],
            'review_checklist' => ['Fix blocking pricing input before manual review: ' . $reason],
            'primary_signal_count' => 0,
            'competitor_data' => $signals['competitor'] ?? [],
            'factors' => [
                'model' => self::MODEL_VERSION,
                'advisory_only' => true,
                'signals' => $signals,
                'factor_notes' => [],
                'drivers' => [],
                'confidence_score' => 0.0,
                'risk_level' => 'high',
                'review_checklist' => ['Fix blocking pricing input before manual review: ' . $reason],
                'primary_signal_count' => 0,
                'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            ],
        ];
    }

    private function readinessCheck(string $key, string $label, bool $passed, string $evidence, string $nextAction, int $weight): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'ok' : 'missing',
            'evidence' => $evidence,
            'next_action' => $nextAction,
            'weight' => $weight,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSuggestionDisplayFields(array $row): array
    {
        if (!isset($row['status_name']) || $row['status_name'] === '') {
            $row['status_name'] = $this->pricingSuggestionStatusName((int)($row['status'] ?? 0));
        }
        if (!isset($row['suggestion_type_name']) || $row['suggestion_type_name'] === '') {
            $row['suggestion_type_name'] = $this->pricingSuggestionTypeName((int)($row['suggestion_type'] ?? 0));
        }
        if (!array_key_exists('price_change_percent', $row)) {
            $currentPrice = $this->toFloat($row['current_price'] ?? 0);
            $suggestedPrice = $this->toFloat($row['suggested_price'] ?? 0);
            $row['price_change_percent'] = $currentPrice > 0
                ? round(($suggestedPrice - $currentPrice) / $currentPrice * 100, 2)
                : 0.0;
        }

        return $row;
    }

    private function pricingSuggestionStatusName(int $status): string
    {
        return [
            1 => '待审批',
            2 => '已批准',
            3 => '已拒绝',
            4 => '已应用',
            5 => '已过期',
        ][$status] ?? '未知';
    }

    private function pricingSuggestionTypeName(int $type): string
    {
        return [
            1 => '动态定价',
            2 => '竞对跟价',
            3 => '事件驱动',
            4 => '预测驱动',
        ][$type] ?? '未知';
    }

    private function pricingReadinessStage(
        int $id,
        int $status,
        bool $sourceReady,
        bool $riskClear,
        bool $approved,
        bool $executionLinked,
        string $executionStage,
        bool $appliedLocal,
        bool $evidenceReady,
        bool $roiReady
    ): string {
        if ($id <= 0) {
            return 'suggestion_missing';
        }
        if ($status === 3 || $executionStage === 'rejected') {
            return 'rejected';
        }
        if ($executionStage === 'blocked') {
            return 'blocked';
        }
        if (!$sourceReady || !$riskClear) {
            return 'data_recheck_required';
        }
        if (!$approved) {
            return 'pending_approval';
        }
        if (!$executionLinked) {
            return 'approved_pending_execution';
        }
        if ($executionStage === 'approval') {
            return 'execution_intent_pending_approval';
        }
        if (!$appliedLocal || !$evidenceReady) {
            return 'local_applied_pending_evidence';
        }
        if (!$roiReady) {
            return 'evidence_ready';
        }
        return 'pricing_ready';
    }

    private function pricingReadinessStageLabel(string $stage): string
    {
        return [
            'suggestion_missing' => '未形成建议',
            'data_recheck_required' => '需数据复核',
            'pending_approval' => '待人工审批',
            'approved_pending_execution' => '已批待转执行',
            'execution_intent_pending_approval' => '执行意图待审',
            'local_applied_pending_evidence' => '待执行证据',
            'evidence_ready' => '待效果复盘',
            'pricing_ready' => '调价闭环就绪',
            'rejected' => '已拒绝',
            'blocked' => '执行阻断',
        ][$stage] ?? $stage;
    }

    private function pricingReadinessNotice(string $stage): string
    {
        return [
            'suggestion_missing' => '当前没有可复核的调价建议。',
            'data_recheck_required' => '建议仍有数据缺口、低置信度或高风险信号，不能直接执行。',
            'pending_approval' => '建议只代表模型输出，需人工审批后才能进入执行。',
            'approved_pending_execution' => '建议已审批，但还没有进入运营执行意图。',
            'execution_intent_pending_approval' => '已转入执行意图，仍需执行流审批。',
            'local_applied_pending_evidence' => '本地价格应用或执行意图已形成，但缺 OTA/人工执行证据。',
            'evidence_ready' => '已有执行证据，下一步需要做效果复盘和 ROI 判断。',
            'pricing_ready' => '建议、审批、执行证据和效果复盘均已形成，可视为调价闭环就绪。',
            'rejected' => '建议已被拒绝，不进入执行闭环。',
            'blocked' => '执行链路被阻断，需先处理阻断原因。',
        ][$stage] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelSignals(int $hotelId, string $targetDate): array
    {
        $cacheKey = $hotelId . '|' . $targetDate;
        if (isset($this->hotelSignalCache[$cacheKey])) {
            return $this->hotelSignalCache[$cacheKey];
        }

        $asOfDate = min($targetDate, date('Y-m-d'));
        $historyStart = date('Y-m-d', strtotime($asOfDate . ' -60 days'));
        $historyRows = $this->onlineDailyRows($hotelId, $historyStart, $asOfDate);
        $elasticity = $this->estimatePriceElasticity($historyRows);
        $pickup = $this->pickupSignal($historyRows, $asOfDate);
        $holiday = $this->holidaySignal($targetDate);
        $backtest = $elasticity['backtest'] ?? [
            'data_status' => 'insufficient',
            'hit_rate' => null,
            'sample_count' => 0,
        ];

        $dataGaps = $this->uniqueStrings(array_filter(array_merge(
            empty($historyRows) ? ['online_daily_history_missing'] : [],
            $elasticity['data_gaps'] ?? [],
            $pickup['data_gaps'] ?? [],
            $holiday['data_gaps'] ?? []
        )));

        return $this->hotelSignalCache[$cacheKey] = [
            'pickup' => $pickup,
            'elasticity' => $elasticity,
            'backtest' => $backtest,
            'holiday' => $holiday,
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forecastSignal(int $hotelId, int $roomTypeId, string $targetDate, array $roomType): array
    {
        $forecast = DemandForecast::where('hotel_id', $hotelId)
            ->where('room_type_id', $roomTypeId)
            ->where('forecast_date', $targetDate)
            ->find();

        if ($forecast) {
            return [
                'data_status' => 'ok',
                'source' => 'demand_forecasts',
                'id' => (int)$forecast->id,
                'predicted_occupancy' => $this->toFloat($forecast->predicted_occupancy ?? 0),
                'predicted_demand' => (int)($forecast->predicted_demand ?? 0),
                'confidence_score' => $this->toFloat($forecast->confidence_score ?? 0),
                'event_type' => (int)($forecast->event_type ?? 0),
                'is_event_driven' => (int)($forecast->is_event_driven ?? 0),
                'data_gaps' => [],
            ];
        }

        return [
            'data_status' => 'missing',
            'source' => 'demand_forecasts',
            'id' => 0,
            'predicted_occupancy' => null,
            'predicted_demand' => null,
            'confidence_score' => null,
            'data_gaps' => ['demand_forecast_missing'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competitorSignal(int $hotelId, int $roomTypeId, string $targetDate, float $currentPrice): array
    {
        $lookups = [
            [
                'source_scope' => 'room_type',
                'lookup' => $this->latestCompetitorRows($hotelId, $roomTypeId, $targetDate),
            ],
            [
                'source_scope' => 'hotel',
                'lookup' => $this->latestCompetitorRows($hotelId, 0, $targetDate),
            ],
        ];

        $rows = [];
        $sourceScope = 'room_type';
        $sourceDate = null;
        $prices = [];
        foreach ($lookups as $candidate) {
            $candidateRows = (array)($candidate['lookup']['rows'] ?? []);
            $candidatePrices = $this->competitorPrices($candidateRows);
            if ($candidatePrices) {
                $rows = $candidateRows;
                $sourceScope = (string)$candidate['source_scope'];
                $sourceDate = (string)($candidate['lookup']['source_date'] ?? '');
                $prices = $candidatePrices;
                break;
            }
        }

        $stalenessDays = $sourceDate ? $this->daysBetween($sourceDate, $targetDate) : null;
        $dataGaps = [];
        if ($sourceScope === 'hotel') {
            $dataGaps[] = 'competitor_room_type_missing_using_hotel_scope';
        }
        if ($sourceDate && $sourceDate !== $targetDate) {
            $dataGaps[] = 'competitor_price_uses_recent_snapshot';
            if ($stalenessDays !== null && $stalenessDays > 3) {
                $dataGaps[] = 'competitor_price_stale_gt_3_days';
            }
        }

        if (!$prices) {
            return [
                'data_status' => 'missing',
                'source' => 'competitor_analysis',
                'source_scope' => $sourceScope,
                'source_date' => $sourceDate,
                'lookback_days' => self::COMPETITOR_LOOKBACK_DAYS,
                'staleness_days' => $stalenessDays,
                'avg_price' => null,
                'min_price' => null,
                'max_price' => null,
                'gap_percent' => null,
                'sample_count' => 0,
                'data_gaps' => $this->uniqueStrings(array_merge($dataGaps, ['competitor_price_missing'])),
            ];
        }

        $avgPrice = array_sum($prices) / count($prices);
        return [
            'data_status' => 'ok',
            'source' => 'competitor_analysis',
            'source_scope' => $sourceScope,
            'source_date' => $sourceDate,
            'lookback_days' => self::COMPETITOR_LOOKBACK_DAYS,
            'staleness_days' => $stalenessDays,
            'avg_price' => round($avgPrice, 2),
            'min_price' => round(min($prices), 2),
            'max_price' => round(max($prices), 2),
            'gap_percent' => $currentPrice > 0 ? round(($avgPrice - $currentPrice) / $currentPrice * 100, 2) : null,
            'sample_count' => count($prices),
            'data_gaps' => $this->uniqueStrings($dataGaps),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, source_date: string|null}
     */
    private function latestCompetitorRows(int $hotelId, int $roomTypeId, string $targetDate): array
    {
        $startDate = date('Y-m-d', strtotime($targetDate . ' -' . self::COMPETITOR_LOOKBACK_DAYS . ' days'));
        $query = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->whereBetween('analysis_date', [$startDate, $targetDate])
            ->order('analysis_date', 'desc')
            ->order('id', 'desc');

        if ($roomTypeId > 0) {
            $query->where('room_type_id', $roomTypeId);
        }

        $rows = $query->select()->toArray();
        if (!$rows) {
            return ['rows' => [], 'source_date' => null];
        }

        $sourceDate = (string)($rows[0]['analysis_date'] ?? '');
        $snapshotRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['analysis_date'] ?? '') === $sourceDate
        ));

        return [
            'rows' => $snapshotRows,
            'source_date' => $sourceDate !== '' ? $sourceDate : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, float>
     */
    private function competitorPrices(array $rows): array
    {
        $prices = [];
        foreach ($rows as $row) {
            $price = $this->toFloat($row['competitor_price'] ?? 0);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    private function daysBetween(string $fromDate, string $toDate): int
    {
        $from = strtotime($fromDate);
        $to = strtotime($toDate);
        if ($from === false || $to === false) {
            return 0;
        }

        return max(0, (int)floor(($to - $from) / 86400));
    }

    /**
     * @return array<string, mixed>
     */
    private function inventorySignal(array $roomType, array $forecast): array
    {
        $roomCount = (int)($roomType['room_count'] ?? 0);
        if ($roomCount <= 0) {
            return [
                'data_status' => 'missing',
                'capacity' => null,
                'predicted_demand' => $forecast['predicted_demand'] ?? null,
                'utilization_percent' => null,
                'data_gaps' => ['room_type_room_count_missing'],
            ];
        }

        $predictedDemand = $this->toNullableFloat($forecast['predicted_demand'] ?? null);
        $occupancy = $this->toNullableFloat($forecast['predicted_occupancy'] ?? null);
        if (($predictedDemand === null || $predictedDemand <= 0) && $occupancy !== null && $occupancy > 0) {
            $predictedDemand = $roomCount * $occupancy / 100;
        }

        if ($predictedDemand === null || $predictedDemand <= 0) {
            return [
                'data_status' => 'missing',
                'capacity' => $roomCount,
                'predicted_demand' => null,
                'utilization_percent' => null,
                'data_gaps' => ['inventory_demand_signal_missing'],
            ];
        }

        return [
            'data_status' => 'ok',
            'capacity' => $roomCount,
            'predicted_demand' => round($predictedDemand, 2),
            'utilization_percent' => round(min(150.0, $predictedDemand / $roomCount * 100), 2),
            'data_gaps' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function pickupSignal(array $rows, string $asOfDate): array
    {
        $byDate = $this->aggregateOnlineRowsByDate($rows);
        $recentStart = date('Y-m-d', strtotime($asOfDate . ' -6 days'));
        $previousStart = date('Y-m-d', strtotime($asOfDate . ' -13 days'));
        $previousEnd = date('Y-m-d', strtotime($asOfDate . ' -7 days'));
        $earlyStart = date('Y-m-d', strtotime($asOfDate . ' -27 days'));
        $earlyEnd = date('Y-m-d', strtotime($asOfDate . ' -14 days'));

        $recent = $this->sumQuantityBetween($byDate, $recentStart, $asOfDate);
        $previous = $this->sumQuantityBetween($byDate, $previousStart, $previousEnd);
        $early = $this->sumQuantityBetween($byDate, $earlyStart, $earlyEnd);
        $sampleDays = count($byDate);
        if ($sampleDays < 14 || ($recent <= 0 && $previous <= 0)) {
            return [
                'data_status' => 'insufficient',
                'source' => 'online_daily_data_quantity_proxy',
                'as_of_date' => $asOfDate,
                'sample_days' => $sampleDays,
                'curve' => [
                    ['window' => 'd-27_to_d-14', 'room_nights' => round($early, 2)],
                    ['window' => 'd-13_to_d-7', 'room_nights' => round($previous, 2)],
                    ['window' => 'd-6_to_d0', 'room_nights' => round($recent, 2)],
                ],
                'pace_index' => null,
                'data_gaps' => [
                    'pickup_curve_uses_actual_sales_proxy_not_on_books',
                    'pickup_curve_on_books_snapshot_missing_or_short_history',
                ],
            ];
        }

        $recentAvg = $recent / 7;
        $previousAvg = $previous / 7;
        $paceIndex = $previousAvg > 0 ? round($recentAvg / $previousAvg * 100, 2) : null;

        return [
            'data_status' => 'ok',
            'source' => 'online_daily_data_quantity_proxy',
            'as_of_date' => $asOfDate,
            'sample_days' => $sampleDays,
            'curve' => [
                ['window' => 'd-27_to_d-14', 'room_nights' => round($early, 2)],
                ['window' => 'd-13_to_d-7', 'room_nights' => round($previous, 2)],
                ['window' => 'd-6_to_d0', 'room_nights' => round($recent, 2)],
            ],
            'pace_index' => $paceIndex,
            'data_gaps' => ['pickup_curve_uses_actual_sales_proxy_not_on_books'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holidaySignal(string $targetDate): array
    {
        $year = (int)date('Y', strtotime($targetDate));
        $holidays = $this->holidays($year);
        $timestamp = strtotime($targetDate);
        if (!$timestamp || !$holidays) {
            return [
                'data_status' => 'missing',
                'target_date' => $targetDate,
                'is_weekend' => false,
                'is_holiday_window' => false,
                'is_in_holiday' => false,
                'data_gaps' => ['holiday_calendar_missing'],
            ];
        }

        $isWeekend = in_array((int)date('N', $timestamp), [6, 7], true);
        $nearest = null;
        foreach ($holidays as $holiday) {
            $start = strtotime($holiday['start_date']);
            $end = strtotime($holiday['end_date']);
            if ($start === false || $end === false) {
                continue;
            }
            if ($timestamp >= $start && $timestamp <= $end) {
                return [
                    'data_status' => 'ok',
                    'target_date' => $targetDate,
                    'name' => $holiday['name'],
                    'days_left' => 0,
                    'is_weekend' => $isWeekend,
                    'is_holiday_window' => true,
                    'is_in_holiday' => true,
                    'data_gaps' => [],
                ];
            }
            if ($timestamp < $start) {
                $daysLeft = (int)floor(($start - $timestamp) / 86400);
                $nearest = $nearest === null || $daysLeft < $nearest['days_left']
                    ? ['name' => $holiday['name'], 'days_left' => $daysLeft]
                    : $nearest;
            }
        }

        return [
            'data_status' => 'ok',
            'target_date' => $targetDate,
            'name' => $nearest['name'] ?? null,
            'days_left' => $nearest['days_left'] ?? null,
            'is_weekend' => $isWeekend,
            'is_holiday_window' => $nearest !== null && $nearest['days_left'] <= 14,
            'is_in_holiday' => false,
            'data_gaps' => [],
        ];
    }

    /**
     * @return array<int, array{name: string, start_date: string, end_date: string}>
     */
    private function holidays(int $year): array
    {
        return [
            2026 => [
                ['name' => 'new_year', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
                ['name' => 'spring_festival', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
                ['name' => 'qingming', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
                ['name' => 'labor_day', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
                ['name' => 'dragon_boat', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
                ['name' => 'mid_autumn', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
                ['name' => 'national_day', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            ],
        ][$year] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function onlineDailyRows(int $hotelId, string $startDate, string $endDate): array
    {
        return Db::name('online_daily_data')
            ->whereBetween('data_date', [$startDate, $endDate])
            ->where(function ($query) use ($hotelId): void {
                $query->where('system_hotel_id', $hotelId)
                    ->whereOr('hotel_id', (string)$hotelId);
            })
            ->field('data_date,amount,quantity,book_order_num')
            ->order('data_date', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, float>>
     */
    private function aggregateOnlineRowsByDate(array $rows): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $date = (string)($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['amount' => 0.0, 'quantity' => 0.0, 'orders' => 0.0];
            }
            $byDate[$date]['amount'] += $this->toFloat($row['amount'] ?? 0);
            $byDate[$date]['quantity'] += $this->toFloat($row['quantity'] ?? 0);
            $byDate[$date]['orders'] += $this->toFloat($row['book_order_num'] ?? 0);
        }
        ksort($byDate);

        return $byDate;
    }

    /**
     * @param array<string, array<string, float>> $byDate
     */
    private function sumQuantityBetween(array $byDate, string $startDate, string $endDate): float
    {
        $sum = 0.0;
        foreach ($byDate as $date => $row) {
            if ($date >= $startDate && $date <= $endDate) {
                $sum += (float)($row['quantity'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param array<int, array{adr: float, quantity: float}> $points
     * @return array<string, mixed>
     */
    private function medianSplitBacktest(array $points): array
    {
        $prices = array_column($points, 'adr');
        $quantities = array_column($points, 'quantity');
        sort($prices);
        sort($quantities);
        $medianPrice = $prices[(int)floor(count($prices) / 2)] ?? null;
        $medianQuantity = $quantities[(int)floor(count($quantities) / 2)] ?? null;
        if ($medianPrice === null || $medianQuantity === null) {
            return ['data_status' => 'insufficient', 'hit_rate' => null, 'sample_count' => 0];
        }

        $tested = 0;
        $hits = 0;
        foreach ($points as $point) {
            if ($point['adr'] === $medianPrice || $point['quantity'] === $medianQuantity) {
                continue;
            }
            $tested++;
            if (($point['adr'] > $medianPrice && $point['quantity'] < $medianQuantity)
                || ($point['adr'] < $medianPrice && $point['quantity'] > $medianQuantity)) {
                $hits++;
            }
        }

        return [
            'data_status' => $tested > 0 ? 'ok' : 'insufficient',
            'hit_rate' => $tested > 0 ? round($hits / $tested * 100, 2) : null,
            'sample_count' => $tested,
        ];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function confidenceScore(array $signals): float
    {
        $score = 0.45;
        foreach (['demand_forecast', 'pickup', 'elasticity', 'competitor', 'holiday', 'inventory'] as $key) {
            $status = (string)($signals[$key]['data_status'] ?? '');
            if ($status === 'ok') {
                $score += 0.07;
            } elseif ($status === 'insufficient') {
                $score += 0.02;
            }
        }

        $forecastConfidence = $this->toNullableFloat($signals['demand_forecast']['confidence_score'] ?? null);
        if ($forecastConfidence !== null && $forecastConfidence > 0) {
            $score = ($score + min(0.95, $forecastConfidence)) / 2;
        }

        $hitRate = $this->toNullableFloat($signals['backtest']['hit_rate'] ?? null);
        if ($hitRate !== null) {
            $score = ($score + min(0.9, max(0.3, $hitRate / 100))) / 2;
        }

        $gapCount = count((array)($signals['data_gaps'] ?? []));
        $score -= min(0.2, $gapCount * 0.03);

        return round(max(0.1, min(0.95, $score)), 2);
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function riskLevel(float $confidence, array $signals, int $primarySignalCount): string
    {
        $materialGaps = $this->materialDataGaps((array)($signals['data_gaps'] ?? []));
        if ($primarySignalCount < self::MIN_PRIMARY_SIGNAL_COUNT || $confidence < 0.55) {
            return 'high';
        }
        if ($confidence < 0.72 || $materialGaps) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $signals
     * @param array<int, array<string, mixed>> $drivers
     * @return array<int, string>
     */
    private function reviewChecklist(array $signals, array $drivers, string $riskLevel): array
    {
        $items = ['Confirm this is advisory-only before any OTA execution.'];
        $gaps = (array)($signals['data_gaps'] ?? []);

        if (in_array('pickup_curve_uses_actual_sales_proxy_not_on_books', $gaps, true)) {
            $items[] = 'Verify real on-books pickup before approving material changes.';
        }
        if (($signals['competitor']['data_status'] ?? '') !== 'ok'
            || in_array('competitor_room_type_missing_using_hotel_scope', $gaps, true)
            || in_array('competitor_price_uses_recent_snapshot', $gaps, true)
            || in_array('competitor_price_stale_gt_3_days', $gaps, true)) {
            $items[] = 'Check competitor snapshot date and price comparability.';
        }
        if (($signals['demand_forecast']['data_status'] ?? '') !== 'ok') {
            $items[] = 'Refresh demand forecast before relying on this recommendation.';
        }
        if (($signals['inventory']['data_status'] ?? '') !== 'ok') {
            $items[] = 'Confirm sellable inventory and room count before approval.';
        }
        if (($signals['elasticity']['data_status'] ?? '') !== 'ok'
            || (($signals['backtest']['hit_rate'] ?? null) !== null && (float)$signals['backtest']['hit_rate'] < 60)) {
            $items[] = 'Review elasticity and backtest evidence before changing price.';
        }
        if ($this->hasDriver($drivers, 'holiday')) {
            $items[] = 'Confirm holiday or event premium still applies to the target date.';
        }
        if ($riskLevel === 'high') {
            $items[] = 'Do not approve until blocking data gaps are resolved.';
        }

        return array_slice($this->uniqueStrings($items), 0, 8);
    }

    /**
     * @param array<int, string> $gaps
     * @return array<int, string>
     */
    private function materialDataGaps(array $gaps): array
    {
        $nonBlocking = [
            'pickup_curve_uses_actual_sales_proxy_not_on_books',
        ];

        return array_values(array_filter(
            $this->uniqueStrings($gaps),
            static fn(string $gap): bool => !in_array($gap, $nonBlocking, true)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     */
    private function hasDriver(array $drivers, string $signal): bool
    {
        foreach ($drivers as $driver) {
            if (($driver['signal'] ?? '') === $signal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $factorNotes
     * @param array<string, mixed> $signals
     */
    private function buildReason(string $direction, array $factorNotes, array $signals): string
    {
        if (!$factorNotes) {
            return 'No material pricing signal; keep manual review.';
        }

        $prefix = match ($direction) {
            'increase' => 'Suggest raising listed price after manual review',
            'decrease' => 'Suggest lowering listed price after manual review',
            default => 'Suggest holding price after manual review',
        };
        $gaps = (array)($signals['data_gaps'] ?? []);
        $gapText = $gaps ? ' Data gaps: ' . implode(', ', array_slice($gaps, 0, 5)) . '.' : '';

        return $prefix . '. Signals: ' . implode(', ', $factorNotes) . '.' . $gapText;
    }

    /**
     * @return array<string, mixed>
     */
    private function driver(string $signal, string $rule, float $changeRate, string $direction): array
    {
        return [
            'signal' => $signal,
            'rule' => $rule,
            'change_rate' => round($changeRate * 100, 2),
            'direction' => $direction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     */
    private function primaryDriverCount(array $drivers): int
    {
        $primarySignals = [];
        foreach ($drivers as $driver) {
            $signal = (string)($driver['signal'] ?? '');
            if (in_array($signal, ['demand_forecast', 'pickup_curve', 'competitor_price', 'inventory', 'price_elasticity'], true)) {
                $primarySignals[$signal] = true;
            }
        }

        return count($primarySignals);
    }

    /**
     * @param array<int, string> $factorNotes
     */
    private function skipReason(float $priceDelta, array $factorNotes, int $primarySignalCount): string
    {
        if (empty($factorNotes)) {
            return 'no_material_signal';
        }
        if (abs($priceDelta) < self::MIN_MATERIAL_CHANGE) {
            return 'price_delta_below_threshold';
        }
        if ($primarySignalCount < self::MIN_PRIMARY_SIGNAL_COUNT) {
            return 'primary_signal_count_insufficient';
        }

        return '';
    }

    /**
     * @param iterable<mixed> $values
     * @return array<int, string>
     */
    private function uniqueStrings(iterable $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                $result[$text] = true;
            }
        }

        return array_keys($result);
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }

        return 0.0;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }
}
