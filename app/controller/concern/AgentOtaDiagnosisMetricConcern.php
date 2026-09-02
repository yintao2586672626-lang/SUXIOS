<?php
declare(strict_types=1);

namespace app\controller\concern;

trait AgentOtaDiagnosisMetricConcern
{
    private function applyOtaDiagnosisRuleEvidenceGuard(array $candidate, array $ruleDiagnosis): array
    {
        $candidate['abnormal_metrics'] = array_values(array_filter(array_map(
            'strval',
            (array)($ruleDiagnosis['abnormal_metrics'] ?? [])
        )));
        $candidate['actions'] = array_values(array_filter(array_map(
            'strval',
            (array)($ruleDiagnosis['actions'] ?? [])
        )));
        return $candidate;
    }

    private function normalizeOtaDiagnosisDataGaps(mixed $dataGaps): array
    {
        $items = is_array($dataGaps) && (empty($dataGaps) || array_is_list($dataGaps))
            ? $dataGaps
            : [$dataGaps];
        $normalized = [];
        foreach ($items as $index => $gap) {
            if (is_array($gap)) {
                $code = trim((string)($gap['code'] ?? $gap['key'] ?? ''));
                if ($code === '') {
                    $code = 'ota_data_gap_' . ($index + 1);
                }
                $gap['code'] = $code;
                $gap['message'] = trim((string)($gap['message'] ?? $gap['label'] ?? $code));
                $gap['scope'] = trim((string)($gap['scope'] ?? 'ota_channel'));
                $normalized[] = $gap;
                continue;
            }

            $code = trim((string)$gap);
            if ($code === '') {
                continue;
            }
            $normalized[] = [
                'code' => $code,
                'message' => str_starts_with($code, 'metric_missing:')
                    ? '指标未返回：' . substr($code, strlen('metric_missing:'))
                    : $code,
                'scope' => 'ota_channel',
                'next_action' => '补齐目标日期对应的真实 OTA 数据后重新生成诊断。',
            ];
        }

        $seen = [];
        return array_values(array_filter($normalized, static function (array $gap) use (&$seen): bool {
            $key = (string)($gap['code'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    private function addNullableOtaDiagnosisMetric(array &$bucket, string $field, mixed $value): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }
        $bucket[$field] = ($bucket[$field] ?? 0) + (float)$value;
    }

    private function hasKnownOtaDiagnosisMetric(array $metrics, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $metrics) && $metrics[$field] !== null && $metrics[$field] !== '') {
                return true;
            }
        }
        return false;
    }

    private function nullablePercentRate(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator * 100, 2);
    }

    private function nullableSafeAverage(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator, 2);
    }

    private function formatOtaDiagnosisMetric(mixed $value, string $suffix = ''): string
    {
        return $value === null || $value === '' || !is_numeric($value)
            ? '未返回'
            : (string)$value . $suffix;
    }

    private function topDimensionStats(array $dimensions): array
    {
        uasort($dimensions, static function (array $a, array $b): int {
            $left = $a['data_value'] ?? null;
            $right = $b['data_value'] ?? null;
            if ($left === null) {
                return $right === null ? 0 : 1;
            }
            return $right === null ? -1 : (float)$right <=> (float)$left;
        });
        return array_slice($dimensions, 0, 10, true);
    }

    private function average(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 2);
    }

    private function nullableAverage(array $values): ?float
    {
        return $values === [] ? null : $this->average($values);
    }
}
