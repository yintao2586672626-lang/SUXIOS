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

}
