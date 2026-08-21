<?php
declare(strict_types=1);

namespace app\controller\concern;

trait AgentOtaDiagnosisSummaryGuardConcern
{
    private function otaDiagnosisPlatformLabel(mixed $platform): string
    {
        return match (strtolower((string)$platform)) {
            'ctrip' => '携程',
            'meituan' => '美团',
            default => 'OTA',
        };
    }

    private function otaDiagnosisNoActionPriorityRecommendation(array $result): string
    {
        $trafficGapLabels = [
            'metric_missing:list_exposure' => '列表曝光',
            'metric_missing:detail_visitors' => '详情访问',
            'metric_missing:flow_rate' => '流量转化率',
            'metric_missing:order_visitors' => '下单访问用户',
            'metric_missing:submit_users' => '提交用户',
        ];
        $missingTrafficLabels = [];
        foreach ((array)($result['optional_data_gaps'] ?? []) as $gap) {
            $code = is_array($gap) ? trim((string)($gap['code'] ?? '')) : trim((string)$gap);
            if (isset($trafficGapLabels[$code])) {
                $missingTrafficLabels[] = $trafficGapLabels[$code];
            }
        }
        $missingTrafficLabels = array_values(array_unique($missingTrafficLabels));
        return $missingTrafficLabels !== []
            ? sprintf(
                '最重要建议：暂不依据单日收入、间夜和ADR调整渠道价格或页面；先补齐本营业日的%s，再判断价格或转化优化方向。',
                implode('、', $missingTrafficLabels)
            )
            : '最重要建议：保持当前渠道策略，继续保存下一营业日同口径收入、间夜、订单和ADR，再用连续事实判断是否需要调整。';
    }

    private function otaDiagnosisCoreValueState(string $dataType, array $revenueValues, array $trafficValues): ?string
    {
        if (!in_array($dataType, ['business', 'order', 'traffic'], true)) {
            return null;
        }
        $knownValues = array_values(array_filter(
            array_merge($revenueValues, $trafficValues),
            static fn(?float $value): bool => $value !== null
        ));
        if ($knownValues === []) {
            return 'missing';
        }
        $complete = static fn(array $values): bool => count(array_filter(
            $values,
            static fn(?float $value): bool => $value !== null
        )) === count($values);
        return ($complete($revenueValues) || $complete($trafficValues))
            && array_filter($knownValues, static fn(float $value): bool => $value > 0) === []
                ? 'zero'
                : null;
    }
}
