<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\OnlineDataTrustStatusService;

/** Scope-bound pricing evidence gate; displayed numbers alone cannot authorize pricing. */
trait RevenueAiOverviewPricingEvidenceConcern
{
    /**
     * @param array<string, array<string, mixed>> $metrics Final overview metrics, including canonical replacements.
     * @param array<string, mixed> $context Requested hotel/date/channel scope, independent of supplied labels.
     */
    private function otaMetricsPricingGate(
        array $metrics,
        array $context,
        array $qualityIssues = []
    ): array
    {
        $roomRevenueMetric = is_array($metrics['ota_room_revenue'] ?? null) ? $metrics['ota_room_revenue'] : [];
        $roomNightsMetric = is_array($metrics['ota_room_nights'] ?? null) ? $metrics['ota_room_nights'] : [];
        $roomRevenue = $this->numeric($roomRevenueMetric['value'] ?? null);
        $roomNights = $this->numeric($roomNightsMetric['value'] ?? null);
        $businessDate = (string)($context['business_date'] ?? '');
        $hotelId = $this->hotelId($context['hotel_id'] ?? null);
        $expectedChannels = $this->enabledChannels($context['source_channels'] ?? null);
        if ($expectedChannels === []) {
            $expectedChannels = self::CHANNELS;
        }
        sort($expectedChannels);
        $reason = '';
        if (in_array($context['data_status'] ?? '', ['failed', 'unauthorized'], true)) {
            $reason = $this->pricingQualityIssueReason($qualityIssues);
            if ($reason === 'data_not_complete') {
                $reason = 'metric_truth_collection_failed';
            }
        }
        // Explicit foreign canonical evidence takes precedence over any fallback data.
        if ($reason === '' && (($roomRevenueMetric['reason'] ?? '') === 'metric_scope_mismatch'
            || ($roomNightsMetric['reason'] ?? '') === 'metric_scope_mismatch')) {
            $reason = 'metric_scope_mismatch';
        }
        // Preserve channel-specific blockers: another channel's total cannot fill a gap.
        if ($reason === '' && ($roomRevenue !== null || $roomNights !== null)
            && ($roomNights === null || $roomNights > 0)) {
            foreach (($context['channel_metric_statuses'] ?? []) as $channel => $channelStatus) {
                foreach (['room_revenue', 'room_nights'] as $metricKey) {
                    if (($channelStatus['metrics'][$metricKey]['status'] ?? 'missing') !== 'ready') {
                        $metricLabel = $metricKey === 'room_revenue' ? '房费收入' : '间夜';
                        return $this->pricingGate('ota_metrics', '目标日 OTA 收入和间夜', false, 'ok',
                            'ota_revenue_metrics_missing', $this->channelLabel((string)$channel)
                            . '目标日' . $metricLabel . '尚未通过同酒店、同渠道、同日期的指标核验；不能用其他渠道合计值补齐。');
                    }
                }
            }
        }
        if ($reason === '' && $roomNights !== null && $roomNights <= 0) {
            $reason = 'ota_room_nights_zero';
        }
        foreach ([$roomRevenueMetric, $roomNightsMetric] as $metric) {
            if ($reason !== '') {
                break;
            }
            $truth = is_array($metric['truth'] ?? null) ? $metric['truth'] : [];
            $metricChannels = $this->enabledChannels($metric['source_channels'] ?? null);
            sort($metricChannels);
            if (($metric['reason'] ?? '') === 'metric_scope_mismatch'
                || ($metric['scope'] ?? '') !== 'ota_channel'
                || ($metric['date_basis'] ?? '') !== 'data_date'
                || $metricChannels !== $expectedChannels
                || $this->metricTruthScopeMismatch($truth, $businessDate, $hotelId, $expectedChannels)) {
                $reason = 'metric_scope_mismatch';
            }
        }
        $hasFactRows = $roomRevenue !== null || $roomNights !== null
            || !in_array($roomRevenueMetric['status'] ?? 'empty', ['empty', 'empty_confirmed'], true)
            || !in_array($roomNightsMetric['status'] ?? 'empty', ['empty', 'empty_confirmed'], true);
        if ($reason === '' && !$hasFactRows) {
            $reason = 'online_daily_data_empty';
        } elseif ($reason === '' && ($roomRevenue === null || $roomNights === null)) {
            $reason = 'ota_revenue_metrics_missing';
        } elseif ($reason === '' && $roomNights <= 0) {
            $reason = 'ota_room_nights_zero';
        } elseif ($reason === '') {
            foreach ([$roomRevenueMetric, $roomNightsMetric] as $metric) {
                $truth = is_array($metric['truth'] ?? null) ? $metric['truth'] : [];
                if (($metric['status'] ?? '') !== 'ok' || ($truth['status'] ?? '') !== 'verified') {
                    $reason = (string)($metric['reason'] ?? '');
                    if ($reason === '') {
                        $reason = 'metric_truth_unverified';
                    }
                    break;
                }
                $source = is_array($truth['source'] ?? null) ? $truth['source'] : [];
                $persistence = is_array($truth['persistence'] ?? null) ? $truth['persistence'] : [];
                // Revalidate the persisted evidence using the existing metric truth contract.
                $readbackTruth = OnlineDataTrustStatusService::metricTruthEnvelope([
                    'source' => array_merge($source, [
                        'hotels' => $truth['hotels'] ?? [], 'platforms' => $truth['platforms'] ?? [],
                        'date_range' => $truth['date_range'] ?? [],
                        'source_methods' => $truth['source_methods'] ?? ($source['methods'] ?? []),
                        'collected_at_range' => $truth['collected_at_range'] ?? [],
                        'row_count' => $persistence['record_count'] ?? 0,
                        'stored_count' => $persistence['stored_count'] ?? 0,
                        'readback_verified_count' => $persistence['readback_verified_count'] ?? 0,
                    ]),
                    'saved_success' => ($persistence['stored'] ?? false) === true
                        && ($persistence['readback_verified'] ?? false) === true,
                    'failure_reasons' => trim((string)($truth['failure_reason'] ?? '')) === ''
                        ? [] : [(string)$truth['failure_reason']],
                ]);
                if ($readbackTruth['status'] !== 'verified') {
                    $reason = 'metric_truth_' . $readbackTruth['status'];
                    break;
                }
            }
        }
        $ready = $reason === '';
        $detail = $ready
            ? '已核对同酒店、渠道、经营日期的 OTA 房费收入和间夜，以及来源、保存和精确回读证据。'
            : $this->issueMessage($reason);

        return $this->pricingGate(
            'ota_metrics',
            '目标日 OTA 收入和间夜',
            $ready,
            'ok',
            $reason,
            $detail
        );
    }
}
