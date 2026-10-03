<?php
declare(strict_types=1);

namespace app\service;

final class RevenueForecastReadinessService
{
    public function assessReplay(array $stats, string $sourceKind): array
    {
        $enough = ($stats['complete_fold_count'] ?? 0) >= 3 && ($stats['unpaired_point_count'] ?? 1) === 0
            && ($stats['unavailable_training_fold_count'] ?? 0) === 0;
        $model = $stats['metrics']['model']['mae'] ?? null;
        $weekly = $stats['metrics']['weekly']['mae'] ?? null;
        $mean = $stats['metrics']['mean7']['mae'] ?? null;
        $better = $model !== null && $weekly !== null && $mean !== null && $model < min($weekly, $mean);
        return ['status' => !$enough ? 'insufficient_samples' : ($better ? 'better_on_this_sample' : 'not_better_than_baseline'),
            'comparison_supported' => $enough, 'beats_both_baselines' => $enough ? $better : null,
            'source_kind' => $sourceKind, 'execution_ready' => false, 'causal_effect_established' => false];
    }

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
        $forecastDate = $this->forecastDate($this->stringValue($row, 'forecast_date'));
        $occupancy = $this->floatValue($row, 'predicted_occupancy');
        $demand = $this->floatValue($row, 'predicted_demand');
        $confidence = $this->normalizedConfidence($this->floatValue($row, 'confidence_score'));
        $actualOccupancy = $row['actual_occupancy'] ?? null;
        $actualOccupancyWasProvided = array_key_exists('actual_occupancy', $row)
            && $actualOccupancy !== null
            && (!is_string($actualOccupancy) || trim($actualOccupancy) !== '');
        $hasActualOccupancy = $actualOccupancyWasProvided
            && is_numeric($actualOccupancy)
            && is_finite((float)$actualOccupancy)
            && (float)$actualOccupancy >= 0
            && (float)$actualOccupancy <= 100;
        $invalidActualOccupancy = $actualOccupancyWasProvided && !$hasActualOccupancy;
        $suggestionCount = $this->intValue($suggestionStats, 'suggestion_count');
        $approvedCount = $this->intValue($suggestionStats, 'approved_count');
        $appliedCount = $this->intValue($suggestionStats, 'applied_count');
        $latestSuggestionAt = $this->stringValue($suggestionStats, 'latest_suggestion_at');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        if ($forecastDate === '' || $occupancy === null || $occupancy < 0 || $occupancy > 100
            || ($demand !== null && $demand < 0)) {
            $readiness = $this->readiness('forecast_metric_missing', '预测值待核', 25, false, false, '补齐有效预测日期和入住率', [
                $this->missing('forecast_metric', '有效预测值', '补齐预测日期、入住率和需求量'),
            ]);
        } elseif ($confidence === null) {
            $readiness = $this->readiness('forecast_confidence_missing', '置信度待核', 25, false, false, '补齐有效置信度后重新核对预测', [
                $this->missing('confidence_score', '有效预测置信度', '补齐 0–1 或 0–100 范围内的有限置信度'),
            ]);
        } elseif ($confidence < 60) {
            $readiness = $this->readiness('forecast_low_confidence', '低置信预测', 40, false, false, '补充样本或人工复核后再用于调价', [
                $this->missing('confidence_score', '预测置信度', '补充样本或人工复核预测口径'),
            ]);
        } elseif ($forecastDate < $today && $invalidActualOccupancy) {
            $readiness = $this->readiness('forecast_backtest_missing', '实绩待核', 45, false, false, '核对已记录的实际入住率是否为 0–100 的有效来源事实');
        } elseif ($forecastDate < $today && !$hasActualOccupancy) {
            $readiness = $this->readiness('forecast_backtest_missing', '缺回测', 45, false, false, '回填实际入住率后复盘预测误差', [
                $this->missing('actual_occupancy', '实际入住率回测', '回填实际入住率并计算预测误差'),
            ]);
        } elseif ($suggestionCount <= 0) {
            $readiness = $this->readiness('forecast_not_priced', '未转定价', 65, false, false, '用该预测生成或关联定价建议', [
                $this->missing('price_suggestion', '定价建议引用', '生成预测驱动的定价建议并关联该预测'),
            ]);
        } elseif ($appliedCount > 0 && ($forecastDate >= $today || !$hasActualOccupancy)) {
            $readiness = $this->readiness('forecast_pricing_applied', '已转定价', 90, false, true, '等待入住结果回填后复盘定价效果', [
                $this->missing('actual_result', '入住结果复盘', '入住日后回填实际入住率并复盘调价效果'),
            ]);
        } elseif ($appliedCount > 0) {
            $readiness = $this->readiness('forecast_pricing_closed', '预测组件已复盘', 100, true, true, '保留预测、调价和实际结果证据，并由经营闭环内核判断全链状态');
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
        $readiness['invalid_evidence'] = $invalidActualOccupancy
            ? [$this->missing('actual_occupancy_invalid', '已记录实际入住率无效', '核对已记录的实际入住率是否为 0–100 的有效来源事实')]
            : [];

        return $this->withNotice($readiness);
    }

    private function readiness(string $stage, string $label, int $score, bool $closedLoop, bool $executionReady, string $nextAction, array $missingEvidence = []): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => false,
            'component_closed_loop' => $closedLoop,
            'authority_status' => 'diagnostic_only',
            'source_policy' => 'component_readiness_only_requires_hotel_operating_cycle_kernel',
            'execution_ready' => $executionReady,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
            'invalid_evidence' => [],
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
        $invalid = $readiness['invalid_evidence'] ?? [];
        if (!$missing && !$invalid) {
            $readiness['notice'] = '已具备预测、定价执行和结果复盘证据';
            return $readiness;
        }

        $labels = static fn(array $items): string => implode('、', array_slice(array_map(
            static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名证据'),
            $items
        ), 0, 4));
        if ($invalid && $missing) {
            $readiness['notice'] = '已记录数据待核：' . $labels($invalid) . '；仍缺：' . $labels($missing);
        } elseif ($invalid) {
            $readiness['notice'] = '已记录数据待核：' . $labels($invalid);
        } else {
            $readiness['notice'] = '仍缺：' . $labels($missing);
        }

        return $readiness;
    }

    private function normalizedConfidence(?float $value): ?float
    {
        if ($value === null || $value < 0 || $value > 100) {
            return null;
        }
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

    private function floatValue(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value)) {
            return null;
        }

        return (float)$value;
    }

    private function forecastDate(string $value): string
    {
        // Preserve DATE and legacy SQL/ISO datetime rows, but reject invalid dates.
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value) !== 1) {
            return '';
        }
        try {
            $parsed = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
            $errors = \DateTimeImmutable::getLastErrors();
            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)
                ? substr($value, 0, 10) : '';
        } catch (\Exception) {
            return '';
        }
    }

    private function stringValue(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_scalar($row[$key])) {
            return '';
        }

        return trim((string)$row[$key]);
    }
}
