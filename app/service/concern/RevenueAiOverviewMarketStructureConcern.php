<?php
declare(strict_types=1);

namespace app\service\concern;

/** Builds channel-scoped market signals from stored metric evidence. */
trait RevenueAiOverviewMarketStructureConcern
{
    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function bookingWindowAdrSignal(array $metricsSummary, array $sourceChannels): array
    {
        $summary = is_array($metricsSummary['booking_window_adr'] ?? null)
            ? $metricsSummary['booking_window_adr']
            : [];
        $buckets = array_values(array_filter(
            is_array($summary['buckets'] ?? null) ? $summary['buckets'] : [],
            static fn(mixed $item): bool => is_array($item) && is_numeric($item['adr'] ?? null)
        ));
        $reason = trim((string)($summary['reason'] ?? ''));
        if ($buckets === []) {
            return [
                'label' => '提前期房费结构',
                'value' => '--',
                'status' => 'not_calculable',
                'reason' => $reason !== '' ? $reason : 'lead_time_fields_missing',
                'detail' => '需要同一 OTA 事实同时具备提前预订天数、已验证房费收入和正数间夜；缺失时不生成价格结构。',
                'scope' => 'ota',
                'date_basis' => 'lead_time_days',
                'source_channels' => $sourceChannels,
                'detail_metrics' => [
                    'lead_time_row_count' => (int)($summary['lead_time_row_count'] ?? 0),
                    'aligned_row_count' => (int)($summary['aligned_row_count'] ?? 0),
                    'bucket_count' => 0,
                    'buckets' => [],
                ],
            ];
        }


        $parts = array_map(
            static fn(array $bucket): string => (string)($bucket['label'] ?? '') . ' ¥' . number_format((float)$bucket['adr'], 2),
            array_slice($buckets, 0, 3)
        );
        $bucketCount = count($buckets);
        $status = $bucketCount >= 2 && $reason === '' ? 'ok' : 'partial';
        $signalReason = $reason !== ''
            ? $reason
            : ($bucketCount >= 2 ? 'booking_window_adr_structure_available' : 'booking_window_adr_single_bucket');

        return [
            'label' => '提前期房费结构',
            'value' => implode(' · ', $parts),
            'status' => $status,
            'reason' => $signalReason,
            'detail' => '按提前预订天数分组，以已验证 OTA 房费收入 / 间夜计算加权 ADR；仅反映当前 OTA 渠道历史结构，不自动生成调价建议。',
            'scope' => 'ota',
            'date_basis' => 'lead_time_days',
            'source_channels' => $sourceChannels,
            'detail_metrics' => [
                'lead_time_row_count' => (int)($summary['lead_time_row_count'] ?? 0),
                'aligned_row_count' => (int)($summary['aligned_row_count'] ?? 0),
                'bucket_count' => $bucketCount,
                'buckets' => $buckets,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function channelBookingWindowMonthSignal(array $metricsSummary, array $sourceChannels): array
    {
        $summary = is_array($metricsSummary['channel_booking_window_month'] ?? null)
            ? $metricsSummary['channel_booking_window_month']
            : [];
        $cells = array_values(array_filter(
            is_array($summary['cells'] ?? null) ? $summary['cells'] : [],
            static fn(mixed $item): bool => is_array($item)
                && ($item['sample_status'] ?? '') === 'supported'
                && is_numeric($item['order_share'] ?? null)
        ));
        $reason = trim((string)($summary['reason'] ?? ''));
        if ($cells === []) {
            return [
                'label' => '渠道预售窗口',
                'value' => '--',
                'status' => ($summary['status'] ?? '') === 'not_calculable' ? 'not_calculable' : (($summary['aligned_row_count'] ?? 0) > 0 ? 'partial' : 'not_calculable'),
                'reason' => $reason !== '' ? $reason : 'channel_booking_window_month_fields_missing',
                'detail' => $reason === 'numeric_aggregate_nonfinite' ? '同范围预售计数或占比分母超出有限数值范围，相关信号不可计算；有效格子计数仍保留，不填零或沿用旧占比。'
                    : (($summary['aligned_row_count'] ?? 0) > 0 ? '已有渠道、入住月和提前期交叉记录，但所有格子的订单量均低于最小样本门槛，暂不生成预售窗口信号。'
                        : '需要同一 OTA 事实具备真实入住日期、提前预订天数、渠道和正数订单量；缺失时不生成月份交叉结论。'),
                'scope' => 'ota',
                'date_basis' => 'checkin_month',
                'source_channels' => $sourceChannels,
                'detail_metrics' => $summary,
            ];
        }


        usort($cells, static function (array $left, array $right): int {
            return [(float)($right['order_count'] ?? 0), (float)($right['order_share'] ?? 0)]
                <=> [(float)($left['order_count'] ?? 0), (float)($left['order_share'] ?? 0)];
        });
        $parts = array_map(function (array $cell): string {
            return (string)($cell['stay_month'] ?? '')
                . ' ' . $this->channelLabel((string)($cell['platform_key'] ?? ''))
                . ' ' . (string)($cell['booking_window_label'] ?? '')
                . ' ' . number_format((float)($cell['order_share'] ?? 0), 1) . '%';
        }, array_slice($cells, 0, 3));

        return [
            'label' => '渠道预售窗口',
            'value' => implode(' · ', $parts),
            'status' => $reason === '' ? 'ok' : 'partial',
            'reason' => $reason !== '' ? $reason : 'channel_booking_window_month_structure_available',
            'detail' => '按真实入住月、OTA渠道和提前期分组展示当前快照的订单结构；仅用于观察预售窗口，不证明价格、投放或促销因果。',
            'scope' => 'ota',
            'date_basis' => 'checkin_month',
            'source_channels' => $sourceChannels,
            'detail_metrics' => $summary,
        ];
    }

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
        $calculationFailed = in_array('numeric_aggregate_nonfinite',
            (array)($metricsSummary['metric_trust']['competitor_price.avg_price_gap']['failure_reasons'] ?? []), true);
        if ($rows <= 0 || $avgOurPrice === null || $avgCompetitorPrice === null || $avgPriceGap === null) {
            return [
                'label' => '竞对价格倒挂预警',
                'value' => '--',
                'status' => $calculationFailed ? 'not_calculable' : 'not_loaded',
                'reason' => $calculationFailed ? 'numeric_aggregate_nonfinite' : 'competitor_price_fields_missing',
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


        $avgPriceGapRate = $this->numeric($summary['avg_price_gap_rate'] ?? null);

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
}
