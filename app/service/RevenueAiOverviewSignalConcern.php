<?php
declare(strict_types=1);

namespace app\service;

trait RevenueAiOverviewSignalConcern
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

        $trust = is_array($metricsSummary['metric_trust']['booking_window_adr.buckets'] ?? null)
            ? $metricsSummary['metric_trust']['booking_window_adr.buckets'] : [];
        $truth = is_array($trust['truth'] ?? null) ? $trust['truth'] : [];
        $truthStatus = (string)($truth['status'] ?? 'unverified');
        $sourceFailureReasons = array_values(array_diff(
            (array)($trust['failure_reasons'] ?? []),
            ['booking_window_adr_fields_partial']
        ));
        $persistence = is_array($truth['persistence'] ?? null) ? $truth['persistence'] : [];
        $structuralGapOnly = $truthStatus === 'partial'
            && $sourceFailureReasons === []
            && (array)($truth['evidence_gap_codes'] ?? []) === []
            && ($persistence['stored'] ?? false) === true
            && ($persistence['readback_verified'] ?? false) === true;
        if (!$structuralGapOnly && (($trust['saved_success'] ?? false) !== true
            || $truthStatus !== 'verified'
            || $sourceFailureReasons !== [])) {
            $sourceStatus = in_array($truthStatus, ['partial', 'collection_failed'], true)
                ? $truthStatus : 'unverified';
            return [
                'label' => '提前期房费结构',
                'value' => '--',
                'status' => $sourceStatus === 'collection_failed' ? 'failed' : $sourceStatus,
                'reason' => 'booking_window_adr_source_' . $sourceStatus,
                'detail' => $sourceStatus === 'collection_failed'
                    ? '提前期房费来源采集失败，不能据此发布价格结构；请复核来源并重新保存回读。'
                    : '提前期房费结构已有数值，但来源尚未全部完成同酒店、同渠道、同业务日的保存回读，不能作为已核验结构。',
                'scope' => 'ota',
                'date_basis' => 'lead_time_days',
                'source_channels' => $sourceChannels,
                'detail_metrics' => [
                    'lead_time_row_count' => (int)($summary['lead_time_row_count'] ?? 0),
                    'aligned_row_count' => (int)($summary['aligned_row_count'] ?? 0),
                    'bucket_count' => count($buckets),
                    'buckets' => $buckets,
                    'truth_status' => $truthStatus,
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
                'status' => ($summary['aligned_row_count'] ?? 0) > 0 ? 'partial' : 'not_calculable',
                'reason' => $reason !== '' ? $reason : 'channel_booking_window_month_fields_missing',
                'detail' => ($summary['aligned_row_count'] ?? 0) > 0
                    ? '已有渠道、入住月和提前期交叉记录，但所有格子的订单量均低于最小样本门槛，暂不生成预售窗口信号。'
                    : '需要同一 OTA 事实具备真实入住日期、提前预订天数、渠道和正数订单量；缺失时不生成月份交叉结论。',
                'scope' => 'ota',
                'date_basis' => 'checkin_month',
                'source_channels' => $sourceChannels,
                'detail_metrics' => $summary,
            ];
        }

        $trust = is_array($metricsSummary['metric_trust']['channel_booking_window_month.cells'] ?? null)
            ? $metricsSummary['metric_trust']['channel_booking_window_month.cells'] : [];
        $truth = is_array($trust['truth'] ?? null) ? $trust['truth'] : [];
        $truthStatus = (string)($truth['status'] ?? 'unverified');
        $sourceFailureReasons = array_values(array_diff(
            (array)($trust['failure_reasons'] ?? []),
            ['channel_booking_window_month_fields_partial', 'channel_booking_window_month_sparse_cells']
        ));
        $persistence = is_array($truth['persistence'] ?? null) ? $truth['persistence'] : [];
        $structuralGapOnly = $truthStatus === 'partial'
            && $sourceFailureReasons === []
            && (array)($truth['evidence_gap_codes'] ?? []) === []
            && ($persistence['stored'] ?? false) === true
            && ($persistence['readback_verified'] ?? false) === true;
        if (!$structuralGapOnly && (($trust['saved_success'] ?? false) !== true
            || $truthStatus !== 'verified'
            || $sourceFailureReasons !== [])) {
            $sourceStatus = in_array($truthStatus, ['partial', 'collection_failed'], true)
                ? $truthStatus : 'unverified';
            return [
                'label' => '渠道预售窗口',
                'value' => '--',
                'status' => $sourceStatus === 'collection_failed' ? 'failed' : $sourceStatus,
                'reason' => 'channel_booking_window_month_source_' . $sourceStatus,
                'detail' => $sourceStatus === 'collection_failed'
                    ? '渠道预售窗口来源采集失败，不能据此发布月份订单结构；请复核来源并重新保存回读。'
                    : '月份订单结构已有数值，但来源尚未全部完成同酒店、同渠道、同业务日的保存回读，不能作为已核验结构。',
                'scope' => 'ota',
                'date_basis' => 'checkin_month',
                'source_channels' => $sourceChannels,
                'detail_metrics' => $summary + ['truth_status' => $truthStatus],
            ];
        }

        usort($cells, static function (array $left, array $right): int {
            return [(int)($right['order_count'] ?? 0), (float)($right['order_share'] ?? 0)]
                <=> [(int)($left['order_count'] ?? 0), (float)($left['order_share'] ?? 0)];
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

        $metricTrust = is_array($metricsSummary['metric_trust'] ?? null)
            ? $metricsSummary['metric_trust'] : [];
        $priceTruthStatuses = [];
        $untrustedPriceMetrics = [];
        foreach (['competitor_price.avg_our_price', 'competitor_price.avg_competitor_price'] as $trustKey) {
            $trust = is_array($metricTrust[$trustKey] ?? null) ? $metricTrust[$trustKey] : [];
            $truth = is_array($trust['truth'] ?? null) ? $trust['truth'] : [];
            $truthStatus = (string)($truth['status'] ?? 'unverified');
            $priceTruthStatuses[$trustKey] = $truthStatus;
            if (($trust['saved_success'] ?? false) !== true
                || $truthStatus !== 'verified'
                || (array)($trust['failure_reasons'] ?? []) !== []) {
                $untrustedPriceMetrics[] = $trustKey;
            }
        }
        if ($untrustedPriceMetrics !== []) {
            $sourceStatus = in_array('collection_failed', $priceTruthStatuses, true)
                ? 'collection_failed'
                : (in_array('partial', $priceTruthStatuses, true)
                    || in_array('verified', $priceTruthStatuses, true) ? 'partial' : 'unverified');
            $detail = match ($sourceStatus) {
                'collection_failed' => '目标日竞对价格来源采集失败，不能据此发布价格倒挂预警；请复核来源并重新保存回读。',
                'partial' => '目标日竞对价格只有部分来源完成保存回读，不能把当前均价当作完整价格倒挂证据。',
                default => '目标日竞对价格已记录，但来源尚未完成同酒店、同平台、同业务日的保存回读核验，不能据此发布价格倒挂预警。',
            };
            return [
                'label' => '竞对价格倒挂预警',
                'value' => '--',
                'status' => $sourceStatus === 'collection_failed' ? 'failed' : $sourceStatus,
                'reason' => 'competitor_price_source_' . $sourceStatus,
                'detail' => $detail,
                'scope' => 'ota',
                'source_channels' => $sourceChannels,
                'detail_metrics' => [
                    'sample_rows' => $rows,
                    'avg_our_price' => $avgOurPrice,
                    'avg_competitor_price' => $avgCompetitorPrice,
                    'avg_price_gap' => $avgPriceGap,
                    'untrusted_metric_keys' => $untrustedPriceMetrics,
                    'metric_truth_statuses' => $priceTruthStatuses,
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
}
