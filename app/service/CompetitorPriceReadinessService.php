<?php
declare(strict_types=1);

namespace app\service;

final class CompetitorPriceReadinessService
{
    private const MATERIAL_GAP_PERCENT = 5.0;
    private const MATERIAL_GAP_AMOUNT = 20.0;
    private const STALE_DAYS = 3;

    public function enrichPriceMatrix(array $priceMatrix, array $suggestionStatsByRoomTypeId = []): array
    {
        $result = [];
        foreach ($priceMatrix as $roomTypeName => $competitorRows) {
            if (!is_iterable($competitorRows)) {
                continue;
            }

            $result[$roomTypeName] = [];
            foreach ($competitorRows as $competitorName => $row) {
                $data = $this->rowToArray($row);
                $roomTypeId = $this->intValue($data, 'room_type_id');
                $stats = $suggestionStatsByRoomTypeId[$roomTypeId] ?? [];
                $data['room_type_name'] = $this->stringValue($data, 'room_type_name') ?: (string)$roomTypeName;
                $data['competitor_name'] = $this->stringValue($data, 'competitor_name') ?: (string)$competitorName;
                $data['price_signal_readiness'] = $this->buildPriceSignalReadiness(
                    $data,
                    is_array($stats) ? $stats : []
                );
                $result[$roomTypeName][$competitorName] = $data;
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    public function roomTypeIdsFromPriceMatrix(array $priceMatrix): array
    {
        $ids = [];
        foreach ($priceMatrix as $competitorRows) {
            if (!is_iterable($competitorRows)) {
                continue;
            }
            foreach ($competitorRows as $row) {
                $data = $this->rowToArray($row);
                $roomTypeId = $this->intValue($data, 'room_type_id');
                if ($roomTypeId > 0) {
                    $ids[$roomTypeId] = $roomTypeId;
                }
            }
        }

        return array_values($ids);
    }

    public function buildPriceSignalReadiness(array $row, array $suggestionStats = []): array
    {
        $analysisDate = substr($this->stringValue($row, 'analysis_date'), 0, 10);
        $roomTypeId = $this->intValue($row, 'room_type_id');
        $ourPrice = $this->floatValue($row, 'our_price');
        $competitorPrice = $this->floatValue($row, 'competitor_price');
        $priceGapAmount = round($ourPrice - $competitorPrice, 2);
        $priceGapPercent = $competitorPrice > 0 ? round($priceGapAmount / $competitorPrice * 100, 2) : null;
        $materialGap = abs($priceGapAmount) >= self::MATERIAL_GAP_AMOUNT
            || ($priceGapPercent !== null && abs($priceGapPercent) >= self::MATERIAL_GAP_PERCENT);

        $suggestionCount = $this->intValue($suggestionStats, 'suggestion_count');
        $pendingCount = $this->intValue($suggestionStats, 'pending_count');
        $approvedCount = $this->intValue($suggestionStats, 'approved_count');
        $rejectedCount = $this->intValue($suggestionStats, 'rejected_count');
        $appliedCount = $this->intValue($suggestionStats, 'applied_count');
        $latestSuggestionAt = $this->stringValue($suggestionStats, 'latest_suggestion_at');
        $stalenessDays = $analysisDate !== '' ? $this->daysBetween($analysisDate, date('Y-m-d')) : null;

        if ($analysisDate === '' || $ourPrice <= 0 || $competitorPrice <= 0) {
            $readiness = $this->readiness('competitor_price_missing', '价格待核', 25, false, false, '补齐有效本店价、竞对价和采样日期', [
                $this->missing('price_sample', '有效竞对价格样本', '补齐本店价、竞对价和采样日期'),
            ]);
        } elseif ($roomTypeId <= 0) {
            $readiness = $this->readiness('competitor_room_type_missing', '缺房型映射', 45, false, false, '补齐本店房型映射后再转定价建议', [
                $this->missing('room_type_mapping', '本店房型映射', '补齐本店房型与竞对房型映射'),
            ]);
        } elseif ($stalenessDays !== null && $stalenessDays > self::STALE_DAYS) {
            $readiness = $this->readiness('competitor_price_stale', '历史样本', 50, false, false, '刷新竞对价格样本后再判断调价', [
                $this->missing('fresh_competitor_snapshot', '近期竞对价格样本', '刷新近3天竞对价格样本'),
            ]);
        } elseif ($suggestionCount <= 0 && $materialGap) {
            $readiness = $this->readiness('competitor_not_priced', '未转定价', 60, false, false, '用该竞对价差信号生成或关联定价建议', [
                $this->missing('price_suggestion', '定价建议引用', '生成或关联同日同房型定价建议'),
            ]);
        } elseif ($suggestionCount <= 0) {
            $readiness = $this->readiness('competitor_signal_observed', '已采样待判断', 65, false, false, '记录不调价判断或持续观察阈值', [
                $this->missing('pricing_decision_record', '定价判断记录', '记录不调价原因或继续观察阈值'),
            ]);
        } elseif ($appliedCount > 0) {
            $readiness = $this->readiness('competitor_pricing_applied', '已转定价', 90, false, true, '复盘调价后的订单、ADR或RevPAR结果', [
                $this->missing('pricing_outcome', '调价结果复盘', '补充调价后订单、ADR或RevPAR复盘'),
            ]);
        } elseif ($approvedCount > 0) {
            $readiness = $this->readiness('competitor_pricing_approved', '定价已批', 82, false, true, '执行已批准调价并保留执行证据', [
                $this->missing('pricing_execution', '调价执行证据', '执行已批准调价并记录执行证据'),
            ]);
        } elseif ($rejectedCount > 0 && $pendingCount <= 0) {
            $readiness = $this->readiness('competitor_pricing_rejected', '定价已拒', 55, false, false, '保留拒绝原因或重新评估竞对价差', [
                $this->missing('pricing_decision_record', '拒绝原因记录', '补充拒绝原因或重新评估价差信号'),
            ]);
        } else {
            $readiness = $this->readiness('competitor_pricing_linked', '已关联定价', 75, false, true, '审批或调整关联定价建议', [
                $this->missing('pricing_approval', '定价审批', '审批或调整关联定价建议'),
            ]);
        }

        $readiness['suggestion_count'] = $suggestionCount;
        $readiness['pending_count'] = $pendingCount;
        $readiness['approved_count'] = $approvedCount;
        $readiness['rejected_count'] = $rejectedCount;
        $readiness['applied_count'] = $appliedCount;
        $readiness['latest_suggestion_at'] = $latestSuggestionAt;
        $readiness['price_gap_amount'] = $priceGapAmount;
        $readiness['price_gap_percent'] = $priceGapPercent;
        $readiness['material_gap'] = $materialGap;
        $readiness['staleness_days'] = $stalenessDays;

        return $this->withNotice($readiness);
    }

    private function readiness(string $stage, string $label, int $score, bool $closedLoop, bool $executionReady, string $nextAction, array $missingEvidence = []): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => $closedLoop,
            'execution_ready' => $executionReady,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
        ];
    }

    private function missing(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    private function withNotice(array $readiness): array
    {
        $missing = $readiness['missing_evidence'] ?? [];
        if (!$missing) {
            $readiness['notice'] = '已形成竞对价格、定价承接和执行证据';
            return $readiness;
        }

        $labels = array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
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

    private function rowToArray($row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }

        return (array)$row;
    }

    private function intValue(array $row, string $key): int
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            return 0;
        }

        return (int)$row[$key];
    }

    private function floatValue(array $row, string $key): float
    {
        if (!isset($row[$key]) || $row[$key] === '') {
            return 0.0;
        }

        return (float)$row[$key];
    }

    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || $row[$key] === null) {
            return '';
        }

        return trim((string)$row[$key]);
    }
}
