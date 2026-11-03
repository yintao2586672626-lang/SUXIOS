<?php
declare(strict_types=1);

namespace app\service\concern;

trait RevenueAiOverviewCompetitorSignalConcern
{
    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function competitorPriceSignal(array $metricsSummary, array $sourceChannels): array
    {
        $summary = is_array($metricsSummary['competitor_price'] ?? null) ? $metricsSummary['competitor_price'] : [];
        $rows = (int)($summary['rows'] ?? 0);
        $avgOurPrice = $this->numeric($summary['avg_our_price'] ?? null);
        $avgCompetitorPrice = $this->numeric($summary['avg_competitor_price'] ?? null);
        $avgPriceGap = $this->numeric($summary['avg_price_gap'] ?? null);
        if ($rows <= 0 || $avgOurPrice === null || $avgCompetitorPrice === null) {
            return [
                'label' => '竞对价格倒挂预警',
                'value' => '--',
                'status' => 'not_loaded',
                'reason' => 'competitor_price_fields_missing',
                'scope' => 'ota',
                'source_channels' => $sourceChannels,
                'detail_metrics' => [
                    'sample_rows' => $rows,
                    'avg_our_price' => $avgOurPrice,
                    'avg_competitor_price' => $avgCompetitorPrice,
                    'avg_price_gap' => $avgPriceGap,
                    'avg_price_gap_rate' => $this->numeric($summary['avg_price_gap_rate'] ?? null),
                ],
            ];
        }

        if ($avgPriceGap === null) {
            $avgPriceGap = round($avgOurPrice - $avgCompetitorPrice, 2);
        }
        $avgPriceGapRate = $this->numeric($summary['avg_price_gap_rate'] ?? null);
        if ($avgPriceGapRate === null && $avgCompetitorPrice > 0) {
            $avgPriceGapRate = round($avgPriceGap / $avgCompetitorPrice * 100, 2);
        }

        if (abs($avgPriceGap) < 0.01) {
            $value = '接近竞对均价';
            $status = 'ok';
            $reason = 'competitor_price_aligned';
        } elseif ($avgPriceGap > 0) {
            $value = '本店高于竞对 ¥' . number_format(abs($avgPriceGap), 2);
            $status = 'warning';
            $reason = 'competitor_price_above_competitor';
        } else {
            $value = '本店低于竞对 ¥' . number_format(abs($avgPriceGap), 2);
            $status = 'partial';
            $reason = 'competitor_price_below_competitor_review_required';
        }

        return [
            'label' => '竞对价格倒挂预警',
            'value' => $value,
            'status' => $status,
            'reason' => $reason,
            'scope' => 'ota',
            'source_channels' => $sourceChannels,
            'detail_metrics' => [
                'sample_rows' => $rows,
                'avg_our_price' => round($avgOurPrice, 2),
                'avg_competitor_price' => round($avgCompetitorPrice, 2),
                'avg_price_gap' => round($avgPriceGap, 2),
                'avg_price_gap_rate' => $avgPriceGapRate,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $competitorSignal
     * @return array<string, mixed>
     */
    private function competitorPriceGate(array $competitorSignal): array
    {
        $competitorPriceReady = ($competitorSignal['truth']['status'] ?? '') === 'verified'
            && ($competitorSignal['value'] ?? '--') !== '--'
            && in_array(($competitorSignal['reason'] ?? ''), [
                'competitor_price_above_competitor',
                'competitor_price_below_competitor_review_required',
                'competitor_price_aligned',
            ], true);
        $competitorPriceGate = $this->pricingGate(
            'competitor_price',
            '竞对价格位置',
            $competitorPriceReady,
            'ok',
            (string)($competitorSignal['reason'] ?? 'competitor_price_fields_missing'),
            $competitorPriceReady
                ? '已有通过来源、保存和精确回读的 OTA 价格样本，仅供对应渠道范围的人工复核。'
                : (string)($competitorSignal['detail'] ?? '竞对价格样本缺失，或来源、保存和精确回读尚未确认。')
        );
        if (!$competitorPriceReady && ($competitorSignal['detail'] ?? '') !== '') {
            $competitorPriceGate['display_reason'] = $competitorSignal['detail'];
        }
        return $competitorPriceGate;
    }
}
