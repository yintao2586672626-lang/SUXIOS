<?php
declare(strict_types=1);

namespace app\service;

final class RevenueForecastReadinessService
{
    public function enrichForecastRows(iterable $rows, array $suggestionStatsByForecastId = []): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = $this->rowToArray($row);
            $forecastId = $this->intValue($data, 'id');
            $stats = $suggestionStatsByForecastId[$forecastId] ?? [];
            $data['forecast_readiness'] = $this->buildForecastReadiness($data, is_array($stats) ? $stats : []);
            $result[] = $data;
        }

        return $result;
    }

    public function buildForecastReadiness(array $row, array $suggestionStats = []): array
    {
        $forecastDate = substr($this->stringValue($row, 'forecast_date'), 0, 10);
        $occupancy = $this->floatValue($row, 'predicted_occupancy');
        $demand = $this->floatValue($row, 'predicted_demand');
        $confidence = $this->normalizedConfidence($this->floatValue($row, 'confidence_score'));
        $actualOccupancy = $this->floatValue($row, 'actual_occupancy');
        $suggestionCount = $this->intValue($suggestionStats, 'suggestion_count');
        $approvedCount = $this->intValue($suggestionStats, 'approved_count');
        $appliedCount = $this->intValue($suggestionStats, 'applied_count');
        $latestSuggestionAt = $this->stringValue($suggestionStats, 'latest_suggestion_at');
        $today = date('Y-m-d');

        if ($forecastDate === '' || $occupancy <= 0 || $occupancy > 100) {
            $readiness = $this->readiness('forecast_metric_missing', '预测值待核', 25, false, false, '补齐有效预测日期和入住率', [
                $this->missing('forecast_metric', '有效预测值', '补齐预测日期、入住率和需求量'),
            ]);
        } elseif ($confidence > 0 && $confidence < 60) {
            $readiness = $this->readiness('forecast_low_confidence', '低置信预测', 40, false, false, '补充样本或人工复核后再用于调价', [
                $this->missing('confidence_score', '预测置信度', '补充样本或人工复核预测口径'),
            ]);
        } elseif ($forecastDate < $today && $actualOccupancy <= 0) {
            $readiness = $this->readiness('forecast_backtest_missing', '缺回测', 45, false, false, '回填实际入住率后复盘预测误差', [
                $this->missing('actual_occupancy', '实际入住率回测', '回填实际入住率并计算预测误差'),
            ]);
        } elseif ($suggestionCount <= 0) {
            $readiness = $this->readiness('forecast_not_priced', '未转定价', 65, false, false, '用该预测生成或关联定价建议', [
                $this->missing('price_suggestion', '定价建议引用', '生成预测驱动的定价建议并关联该预测'),
            ]);
        } elseif ($appliedCount > 0 && ($forecastDate >= $today || $actualOccupancy <= 0)) {
            $readiness = $this->readiness('forecast_pricing_applied', '已转定价', 90, false, true, '等待入住结果回填后复盘定价效果', [
                $this->missing('actual_result', '入住结果复盘', '入住日后回填实际入住率并复盘调价效果'),
            ]);
        } elseif ($appliedCount > 0) {
            $readiness = $this->readiness('forecast_pricing_closed', '预测已闭环', 100, true, true, '保留预测、调价和实际结果证据');
        } elseif ($approvedCount > 0) {
            $readiness = $this->readiness('forecast_pricing_approved', '定价已批', 80, false, true, '执行已批准调价并跟踪结果', [
                $this->missing('pricing_execution', '调价执行', '执行已批准调价并记录结果'),
            ]);
        } else {
            $readiness = $this->readiness('forecast_pricing_linked', '已关联定价', 75, false, true, '审批或调整关联定价建议', [
                $this->missing('pricing_approval', '定价审批', '审批或调整关联定价建议'),
            ]);
        }

        $readiness['suggestion_count'] = $suggestionCount;
        $readiness['approved_count'] = $approvedCount;
        $readiness['applied_count'] = $appliedCount;
        $readiness['latest_suggestion_at'] = $latestSuggestionAt;
        $readiness['confidence_percent'] = $confidence;
        $readiness['predicted_demand'] = $demand;

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
            $readiness['notice'] = '已具备预测、定价执行和结果复盘证据';
            return $readiness;
        }

        $labels = array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }

    private function normalizedConfidence(float $value): float
    {
        if ($value > 0 && $value <= 1) {
            return round($value * 100, 2);
        }

        return round($value, 2);
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
