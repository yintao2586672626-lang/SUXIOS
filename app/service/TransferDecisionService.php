<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

class TransferDecisionService
{
    public function calculateAssetPricing(array $input): array
    {
        $hotelName = $this->text($input, ['hotel_name', '酒店名称'], '未命名酒店');
        $location = $this->text($input, ['location', 'city_area', '城市/商圈'], '');
        $roomCount = (int)$this->number($input, ['room_count', '房间数']);
        $monthlyRevenue = $this->number($input, ['monthly_revenue', '月营业额']);
        $monthlyRent = $this->number($input, ['monthly_rent', '月租金']);
        $laborCost = $this->number($input, ['labor_cost', '人工成本']);
        $utilityCost = $this->number($input, ['utility_cost', '水电能耗']);
        $otaCommission = $this->number($input, ['ota_commission', 'OTA佣金']);
        $otherFixedCost = $this->number($input, ['other_fixed_cost', '其他固定成本']);
        $decorationInvestment = $this->number($input, ['decoration_investment', '装修投入']);
        $remainingLeaseMonths = (int)$this->number($input, ['remaining_lease_months', '剩余租期（月）', '剩余租期']);
        $expectedTransferPrice = $this->number($input, ['expected_transfer_price', '业主预期转让价']);
        $occupancyRate = $this->percentNumber($this->number($input, ['occupancy_rate', '入住率']));
        $adr = $this->number($input, ['adr', 'ADR']);
        $rating = $this->number($input, ['rating', '评分']);
        $orderCount = (int)$this->number($input, ['order_count', '订单量']);
        $licensesComplete = $this->bool($input, ['licenses_complete', '证照是否齐全'], true);
        $hasDataAnomaly = $this->bool($input, ['has_data_anomaly', '是否存在数据异常'], false);

        if ($roomCount <= 0) {
            throw new InvalidArgumentException('房间数必须大于0');
        }

        $monthlyTotalCost = $monthlyRent + $laborCost + $utilityCost + $otaCommission + $otherFixedCost;
        $monthlyNetProfit = $monthlyRevenue - $monthlyTotalCost;
        $annualNetProfit = $monthlyNetProfit * 12;
        $paybackMonths = $monthlyNetProfit > 0 ? $expectedTransferPrice / $monthlyNetProfit : null;
        $valuationMultiple = $this->buildValuationMultiple($remainingLeaseMonths, $rating, $occupancyRate, $hasDataAnomaly, $licensesComplete);

        if ($monthlyNetProfit <= 0) {
            $conservativeValuation = max(0, $decorationInvestment * 0.15);
            $reasonableValuation = max(0, $decorationInvestment * 0.25);
            $optimisticValuation = max(0, $decorationInvestment * 0.35);
        } else {
            $conservativeValuation = $monthlyNetProfit * max(6, $valuationMultiple * 0.78);
            $reasonableValuation = $monthlyNetProfit * $valuationMultiple;
            $optimisticValuation = $monthlyNetProfit * min(42, $valuationMultiple * 1.16);
        }

        $quoteJudgement = $this->buildQuoteJudgement(
            $expectedTransferPrice,
            $conservativeValuation,
            $reasonableValuation,
            $optimisticValuation,
            $monthlyNetProfit
        );
        $risk = $this->buildPricingRisk(
            $monthlyNetProfit,
            $remainingLeaseMonths,
            $paybackMonths,
            $hasDataAnomaly,
            $licensesComplete,
            $occupancyRate,
            $rating
        );
        $suggestion = $this->buildPricingSuggestion($risk['risk_level'], $quoteJudgement, $hasDataAnomaly);

        return [
            'basic_info' => [
                'hotel_name' => $hotelName,
                'location' => $location,
                'room_count' => $roomCount,
                'adr' => round($adr, 2),
                'rating' => round($rating, 2),
                'order_count' => $orderCount,
            ],
            'costs' => [
                'monthly_total_cost' => round($monthlyTotalCost, 2),
                'monthly_rent' => round($monthlyRent, 2),
                'labor_cost' => round($laborCost, 2),
                'utility_cost' => round($utilityCost, 2),
                'ota_commission' => round($otaCommission, 2),
                'other_fixed_cost' => round($otherFixedCost, 2),
            ],
            'profit' => [
                'monthly_revenue' => round($monthlyRevenue, 2),
                'monthly_net_profit' => round($monthlyNetProfit, 2),
                'annual_net_profit' => round($annualNetProfit, 2),
                'payback_months' => $paybackMonths === null ? null : round($paybackMonths, 1),
            ],
            'valuation' => [
                'valuation_multiple' => round($valuationMultiple, 1),
                'conservative_valuation' => round($conservativeValuation, 2),
                'reasonable_valuation' => round($reasonableValuation, 2),
                'optimistic_valuation' => round($optimisticValuation, 2),
                'expected_transfer_price' => round($expectedTransferPrice, 2),
                'quote_judgement' => $quoteJudgement,
            ],
            'risk_level' => $risk['risk_level'],
            'risk_points' => $risk['risk_points'],
            'main_reasons' => $risk['main_reasons'],
            'suggestion' => $suggestion,
            'data_notice' => $hasDataAnomaly ? '存在数据异常，本次只能给出谨慎建议，需先复核原始经营数据。' : '',
            'unit' => '万元',
        ];
    }

    public function calculateTransferTiming(array $input): array
    {
        $score = 50;
        $reasons = ['基础分50分'];
        $suggestions = [];

        $revenueTrend = $this->trend($this->text($input, ['revenue_trend', '营业额趋势'], '持平'));
        $orderTrend = $this->trend($this->text($input, ['order_trend', '订单趋势'], '持平'));
        $adrTrend = $this->trend($this->text($input, ['adr_trend', 'ADR趋势'], '持平'));
        $occupancyTrend = $this->trend($this->text($input, ['occupancy_trend', '入住率趋势'], '持平'));
        $revenueTrend = $this->compareTrend($input, ['current_revenue', '近30天营业额'], ['previous_revenue', '上期营业额'], $revenueTrend);
        $orderTrend = $this->compareTrend($input, ['current_orders', '近30天订单量'], ['previous_orders', '上期订单量'], $orderTrend);
        $adrTrend = $this->compareTrend($input, ['current_adr', '近30天ADR'], ['previous_adr', '上期ADR'], $adrTrend);
        $occupancyTrend = $this->compareTrend($input, ['current_occupancy_rate', '近30天入住率'], ['previous_occupancy_rate', '上期入住率'], $occupancyTrend);
        $rating = $this->number($input, ['rating', '评分']);
        $holidayDays = (int)$this->number($input, ['holiday_days', '距离节假日天数']);
        $isPeakSeason = $this->bool($input, ['is_peak_season', '是否旺季'], false);
        $hasDataGap = $this->bool($input, ['has_data_gap', '是否数据断档'], false);
        $hasDataAnomaly = $this->bool($input, ['has_data_anomaly', '是否存在数据异常'], false);

        $exposure = $this->number($input, ['exposure', '曝光']);
        $visitors = $this->number($input, ['visitors', '访客']);
        $conversionRate = $this->number($input, ['conversion_rate', '转化率']);
        $orderCount = $this->number($input, ['order_count', '订单量']);
        $roomNights = $this->number($input, ['room_nights', '间夜']);
        $suspectedCollectionAnomaly = $exposure == 0.0 && $visitors == 0.0 && $conversionRate == 0.0 && ($orderCount > 0 || $roomNights > 0);

        if ($suspectedCollectionAnomaly) {
            $hasDataAnomaly = true;
            $reasons[] = '曝光、访客、转化率为0但订单或间夜大于0，标记为疑似采集异常，不按经营严重下滑处理';
            $suggestions[] = '先复核OTA采集口径，再判断真实流量变化';
        }

        $riskPoints = [];
        $score += $this->applyTrendScore($revenueTrend, 15, '营业额', $reasons);
        $score += $this->applyTrendScore($orderTrend, 15, '订单', $reasons);
        $score += $this->applyTrendScore($adrTrend, 10, 'ADR', $reasons);
        $score += $this->applyTrendScore($occupancyTrend, 10, '入住率', $reasons);
        foreach ([
            '营业额' => $revenueTrend,
            '订单' => $orderTrend,
            'ADR' => $adrTrend,
            '入住率' => $occupancyTrend,
        ] as $label => $trend) {
            if ($trend === '下滑' && !$suspectedCollectionAnomaly) {
                $riskPoints[] = $label . '下滑';
            }
        }

        if ($rating >= 4.8) {
            $score += 10;
            $reasons[] = '评分不低于4.8，加10分';
        } elseif ($rating > 0 && $rating < 4.6) {
            $score -= 10;
            $reasons[] = '评分低于4.6，减10分';
        }

        if ($holidayDays >= 15 && $holidayDays <= 45) {
            $score += 10;
            $reasons[] = '距离节假日15-45天，加10分';
        }

        if ($isPeakSeason) {
            $score += 5;
            $reasons[] = '当前处于旺季，加5分';
        }

        if ($hasDataAnomaly) {
            $score -= 20;
            $reasons[] = $suspectedCollectionAnomaly ? '疑似采集异常，减20分' : '存在数据异常，减20分';
            $riskPoints[] = $suspectedCollectionAnomaly ? '疑似采集异常' : '存在数据异常';
        }

        if ($hasDataGap) {
            $score -= 15;
            $reasons[] = '存在数据断档，减15分';
            $riskPoints[] = '存在数据断档';
            $suggestions[] = '补齐近7-30天连续经营数据后再判断挂牌窗口';
        }

        $score = $this->clamp((int)round($score), 0, 100);
        $decision = $score >= 80 ? '适合转让' : ($score >= 60 ? '谨慎转让' : '暂不建议转让');
        if ($hasDataAnomaly && $decision === '适合转让') {
            $decision = '谨慎转让';
            $reasons[] = '存在数据异常，只能给谨慎建议';
        }

        if ($decision === '适合转让') {
            $suggestions[] = '可准备挂牌材料，优先突出稳定利润、评分和旺季窗口';
        } elseif ($decision === '谨慎转让') {
            $suggestions[] = '建议先复核数据并设置议价底线，避免低质量买家压价';
        } else {
            $suggestions[] = '暂缓转让，优先修复经营指标或等待更强交易窗口';
        }

        return [
            'timing_score' => $score,
            'decision' => $decision,
            'main_reasons' => array_values(array_unique($reasons)),
            'risk_points' => array_values(array_unique($riskPoints)) ?: ['暂无明确时机风险'],
            'next_suggestions' => array_values(array_unique($suggestions)),
            'suggested_action' => $decision,
            'data_quality' => [
                'has_data_anomaly' => $hasDataAnomaly,
                'has_data_gap' => $hasDataGap,
                'suspected_collection_anomaly' => $suspectedCollectionAnomaly,
                'notice' => $suspectedCollectionAnomaly ? '疑似采集异常' : ($hasDataAnomaly ? '存在数据异常' : '数据口径正常'),
            ],
        ];
    }

    public function buildTransferDashboard(array $pricing, array $timing, array $metrics): array
    {
        $valuation = $pricing['valuation'] ?? [];
        $profit = $pricing['profit'] ?? [];
        $riskLevel = (string)($pricing['risk_level'] ?? '暂无');
        $timingScore = $timing['timing_score'] ?? null;
        $timingDecision = (string)($timing['decision'] ?? '暂无');
        $hasDataAnomaly = (bool)($timing['data_quality']['has_data_anomaly'] ?? false) || !empty($pricing['data_notice']);
        $riskPoints = array_values(array_unique(array_merge(
            (array)($pricing['risk_points'] ?? []),
            (array)($timing['risk_points'] ?? []),
            (array)($metrics['risk_points'] ?? [])
        )));
        $mainReasons = array_values(array_unique(array_merge(
            (array)($pricing['main_reasons'] ?? []),
            (array)($timing['main_reasons'] ?? [])
        )));
        $nextSuggestions = array_values(array_unique(array_merge(
            (array)($timing['next_suggestions'] ?? []),
            !empty($pricing['suggestion']) ? [(string)$pricing['suggestion']] : []
        )));
        $suggestedAction = $this->buildDashboardAction($riskLevel, $timingDecision, $hasDataAnomaly);

        return [
            'cards' => [
                [
                    'label' => '建议估值区间',
                    'value' => $this->valuationRange($valuation),
                ],
                [
                    'label' => '当前月净利润',
                    'value' => isset($profit['monthly_net_profit']) ? round((float)$profit['monthly_net_profit'], 2) . '万元' : '--',
                ],
                [
                    'label' => '投资回收周期',
                    'value' => isset($profit['payback_months']) && $profit['payback_months'] !== null ? round((float)$profit['payback_months'], 1) . '个月' : '不可回收',
                ],
                [
                    'label' => '转让时机评分',
                    'value' => $timingScore === null ? '--' : $timingScore . '分',
                ],
                [
                    'label' => '接盘风险等级',
                    'value' => $riskLevel,
                ],
                [
                    'label' => '建议动作',
                    'value' => $suggestedAction,
                ],
            ],
            'final_judgement' => $this->buildFinalJudgement($suggestedAction, $riskLevel, $timingDecision),
            'main_reasons' => $mainReasons ?: ['暂无完整测算结果'],
            'risk_points' => $riskPoints ?: ['暂无明确风险项'],
            'next_suggestions' => $nextSuggestions ?: ['先完成资产定价和时机推演'],
            'suggested_action' => $suggestedAction,
            'unit' => '万元',
        ];
    }

    private function buildValuationMultiple(int $remainingLeaseMonths, float $rating, float $occupancyRate, bool $hasDataAnomaly, bool $licensesComplete): float
    {
        $multiple = 24.0;

        if ($remainingLeaseMonths >= 60) {
            $multiple += 6;
        } elseif ($remainingLeaseMonths >= 36) {
            $multiple += 2;
        } elseif ($remainingLeaseMonths <= 12) {
            $multiple -= 10;
        } elseif ($remainingLeaseMonths <= 24) {
            $multiple -= 4;
        }

        if ($rating >= 4.8) {
            $multiple += 4;
        } elseif ($rating > 0 && $rating < 4.3) {
            $multiple -= 8;
        } elseif ($rating > 0 && $rating < 4.6) {
            $multiple -= 4;
        }

        if ($occupancyRate >= 80) {
            $multiple += 4;
        } elseif ($occupancyRate > 0 && $occupancyRate < 50) {
            $multiple -= 10;
        } elseif ($occupancyRate > 0 && $occupancyRate < 60) {
            $multiple -= 6;
        }

        if ($hasDataAnomaly) {
            $multiple -= 6;
        }
        if (!$licensesComplete) {
            $multiple -= 5;
        }

        return max(6, min(42, $multiple));
    }

    private function buildQuoteJudgement(float $expectedPrice, float $conservative, float $reasonable, float $optimistic, float $monthlyNetProfit): string
    {
        if ($monthlyNetProfit <= 0) {
            return $expectedPrice > 0 ? '报价偏高' : '报价需复核';
        }
        if ($expectedPrice <= $conservative) {
            return '报价偏低';
        }
        if ($expectedPrice <= $optimistic) {
            return '报价合理';
        }
        return '报价偏高';
    }

    private function buildPricingRisk(
        float $monthlyNetProfit,
        int $remainingLeaseMonths,
        ?float $paybackMonths,
        bool $hasDataAnomaly,
        bool $licensesComplete,
        float $occupancyRate,
        float $rating
    ): array {
        $level = '低风险';
        $riskPoints = [];
        $mainReasons = [];

        if ($monthlyNetProfit <= 0) {
            $level = '高风险';
            $riskPoints[] = '月净利润小于等于0，不按利润倍数激进估值';
            $mainReasons[] = '当前经营现金流不能覆盖成本';
        }

        if ($remainingLeaseMonths <= 12) {
            $level = $this->maxRisk($level, '中风险');
            $riskPoints[] = '剩余租期不超过12个月';
            $mainReasons[] = '剩余经营周期偏短，接盘安全边际不足';
        }

        if ($paybackMonths !== null && $remainingLeaseMonths > 0 && $paybackMonths > $remainingLeaseMonths) {
            $level = '高风险';
            $riskPoints[] = '投资回收周期超过剩余租期';
            $mainReasons[] = '按当前报价无法在租期内回收投资';
        }

        if ($hasDataAnomaly) {
            $level = $this->maxRisk($level, '中风险');
            $riskPoints[] = '存在数据异常';
            $mainReasons[] = '经营数据需复核，不能直接作为报价依据';
        }

        if (!$licensesComplete) {
            $level = '高风险';
            $riskPoints[] = '证照不齐全';
            $mainReasons[] = '证照风险会影响持续经营和转让交割';
        }

        if ($occupancyRate > 0 && $occupancyRate < 55) {
            $level = $this->maxRisk($level, '中风险');
            $riskPoints[] = '入住率偏低';
        }
        if ($rating > 0 && $rating < 4.5) {
            $level = $this->maxRisk($level, '中风险');
            $riskPoints[] = '评分偏低';
        }

        return [
            'risk_level' => $level,
            'risk_points' => $riskPoints ?: ['暂无明显硬性风险'],
            'main_reasons' => $mainReasons ?: ['利润、租期和基础经营指标处于可评估区间'],
        ];
    }

    private function buildPricingSuggestion(string $riskLevel, string $quoteJudgement, bool $hasDataAnomaly): string
    {
        if ($hasDataAnomaly) {
            return '谨慎建议：先复核数据，再进入报价谈判。';
        }
        if ($riskLevel === '高风险') {
            return '暂不建议按当前报价接盘，需重新谈价或补充安全条件。';
        }
        if ($quoteJudgement === '报价偏高') {
            return '建议压低报价，并以回收周期作为谈判底线。';
        }
        if ($quoteJudgement === '报价偏低') {
            return '可进入尽调，但需核验证照、租约和历史流水。';
        }
        return '报价处于可谈区间，建议进入经营数据和租约尽调。';
    }

    private function applyTrendScore(string $trend, int $points, string $label, array &$reasons): int
    {
        if ($trend === '上涨') {
            $reasons[] = $label . '上涨，加' . $points . '分';
            return $points;
        }
        if ($trend === '下滑') {
            $reasons[] = $label . '下滑，减' . $points . '分';
            return -$points;
        }
        $reasons[] = $label . '持平，不加减分';
        return 0;
    }

    private function buildDashboardAction(string $riskLevel, string $timingDecision, bool $hasDataAnomaly): string
    {
        if ($hasDataAnomaly) {
            return '先复核数据';
        }
        if ($riskLevel === '高风险' || $timingDecision === '暂不建议转让') {
            return '暂缓转让';
        }
        if ($timingDecision === '适合转让' && $riskLevel === '低风险') {
            return '启动挂牌';
        }
        return '谨慎推进';
    }

    private function buildFinalJudgement(string $action, string $riskLevel, string $timingDecision): string
    {
        if ($action === '启动挂牌') {
            return '当前估值、风险和时机条件相对匹配，可推进转让准备。';
        }
        if ($action === '先复核数据') {
            return '当前存在数据异常，不能直接形成最终交易判断。';
        }
        if ($action === '暂缓转让') {
            return '当前风险或时机不支持立即转让，建议先修复关键条件。';
        }
        return '当前可进入谨慎转让阶段，需以风险项和议价底线控制交易质量。';
    }

    private function valuationRange(array $valuation): string
    {
        if (!isset($valuation['conservative_valuation'], $valuation['optimistic_valuation'])) {
            return '--';
        }
        return round((float)$valuation['conservative_valuation'], 2) . '万元-' . round((float)$valuation['optimistic_valuation'], 2) . '万元';
    }

    private function maxRisk(string $current, string $candidate): string
    {
        $rank = ['低风险' => 1, '中风险' => 2, '高风险' => 3];
        return ($rank[$candidate] ?? 1) > ($rank[$current] ?? 1) ? $candidate : $current;
    }

    private function number(array $input, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== '' && $input[$key] !== null) {
                return round((float)$input[$key], 4);
            }
        }
        return $default;
    }

    private function text(array $input, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && trim((string)$input[$key]) !== '') {
                return trim((string)$input[$key]);
            }
        }
        return $default;
    }

    private function bool(array $input, array $keys, bool $default = false): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int)$value === 1;
            }
            return in_array(trim((string)$value), ['1', 'true', '是', '有', '齐全', '完整', 'yes', 'on'], true);
        }
        return $default;
    }

    private function percentNumber(float $value): float
    {
        if ($value > 0 && $value <= 1) {
            return $value * 100;
        }
        return $value;
    }

    private function trend(string $value): string
    {
        if (in_array($value, ['上涨', '上升', '增长', 'up', 'rise'], true)) {
            return '上涨';
        }
        if (in_array($value, ['下滑', '下降', '降低', 'down', 'fall'], true)) {
            return '下滑';
        }
        return '持平';
    }

    private function compareTrend(array $input, array $currentKeys, array $previousKeys, string $fallback): string
    {
        $hasCurrent = $this->hasAnyValue($input, $currentKeys);
        $hasPrevious = $this->hasAnyValue($input, $previousKeys);
        if (!$hasCurrent || !$hasPrevious) {
            return $fallback;
        }

        $current = $this->number($input, $currentKeys);
        $previous = $this->number($input, $previousKeys);
        if ($current > $previous) {
            return '上涨';
        }
        if ($current < $previous) {
            return '下滑';
        }
        return '持平';
    }

    private function hasAnyValue(array $input, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== '' && $input[$key] !== null) {
                return true;
            }
        }
        return false;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
