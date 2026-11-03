<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\OnlineDataTrustStatusService;

/** OTA booking structure signals retain their own fact and readback evidence. */
trait RevenueAiOverviewOtaStructureSignalConcern
{
    /**
     * @param array<string, mixed> $metricsSummary
     * @param array<int, string> $sourceChannels
     * @return array<string, mixed>
     */
    private function otaStructureSignalWithEvidence(
        array $signal,
        array $metricsSummary,
        string $metricTrustKey,
        array $sourceChannels,
        string $businessDate,
        ?int $hotelId
    ): array {
        $trust = is_array($metricsSummary['metric_trust'][$metricTrustKey] ?? null)
            ? $metricsSummary['metric_trust'][$metricTrustKey] : [];
        $validatedTruth = OnlineDataTrustStatusService::metricTruthEnvelope($trust);
        $truth = is_array($trust['truth'] ?? null) ? $trust['truth'] : $validatedTruth;
        $signal['truth'] = $truth;
        // No calculated structure: retain the precise field/sample gap instead
        // of replacing it with a generic evidence warning.
        if (($signal['value'] ?? '--') === '--') return $signal;

        $reason = '';
        $status = strtolower(trim((string)($truth['status'] ?? 'unverified')));
        if (!in_array($status, ['verified', 'partial', 'unverified', 'collection_failed'], true)) $status = 'unverified';
        if ($validatedTruth['status'] === 'collection_failed' || $status === 'verified') {
            $status = $validatedTruth['status'];
        }
        if ($this->metricTruthScopeMismatch($truth, $businessDate, $hotelId, $sourceChannels, false)
            || $this->metricTruthScopeMismatch($validatedTruth, $businessDate, $hotelId, $sourceChannels, false)) {
            $status = 'unverified';
            $reason = 'metric_scope_mismatch';
        } else {
            // A supported subset may still describe its own verified rows.
            // Missing persistence/identity evidence must never become that subset.
            $allowedSubsetReasons = $metricTrustKey === 'booking_window_adr.buckets'
                ? ['booking_window_adr_fields_partial']
                : ['channel_booking_window_month_fields_partial', 'channel_booking_window_month_sparse_cells'];
            $failures = is_array($trust['failure_reasons'] ?? null) ? $trust['failure_reasons'] : [];
            $supportedSubset = $status === 'partial' && $validatedTruth['status'] === 'partial'
                && $validatedTruth['evidence_gap_codes'] === []
                && ($truth['evidence_gap_codes'] ?? []) === []
                && $failures !== [] && array_diff($failures, $allowedSubsetReasons) === [];
            if ($status === 'verified' || $supportedSubset) {
                $signal['source_channels'] = $this->enabledChannels($validatedTruth['platforms']);
                return $signal;
            }
            $reason = 'metric_truth_' . $status;
        }
        $meta = $this->issueReasonMeta($reason, '', 'signal');
        $signal['value'] = '--';
        $signal['status'] = $status;
        $signal['reason'] = $reason;
        $signal['detail'] = $meta['display_reason'] . ' ' . $meta['next_action'];
        $signal['next_action'] = $meta['next_action'];
        $signal['target_page'] = 'online-data';
        $signal['target_tab'] = 'data-health';
        return $signal;
    }

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

}
