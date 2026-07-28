<?php
declare(strict_types=1);

namespace app\service;

/**
 * Stable approval-time fingerprint for one operating-target revision.
 *
 * The revision is intentionally included: changing either a goal, a fact, or
 * its evidence invalidates an older pending task draft.
 */
final class OperatingTargetExecutionProvenanceService
{
    public const CONTRACT_VERSION = 'operating_target_execution_v1';

    /** @param array<string,mixed> $record */
    public function digest(array $record): string
    {
        $facts = is_array($record['facts'] ?? null) ? $record['facts'] : [];
        $calculation = is_array($record['calculation'] ?? null) ? $record['calculation'] : [];
        $metrics = is_array($calculation['metrics'] ?? null) ? $calculation['metrics'] : [];
        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'record_id' => (int)($record['id'] ?? 0),
            'tenant_id' => (int)($record['tenant_id'] ?? 0),
            'hotel_id' => (int)($record['hotel_id'] ?? 0),
            'target_date' => (string)($record['target_date'] ?? ''),
            'revision_no' => (int)($record['revision_no'] ?? 0),
            'facts' => [
                'target_revenue' => $facts['target_revenue'] ?? null,
                'target_occupancy_rate_percent' => $facts['target_occupancy_rate_percent'] ?? null,
                'target_revpar' => $facts['target_revpar'] ?? null,
                'actual_revenue' => $facts['actual_revenue'] ?? null,
                'sold_room_nights' => $facts['sold_room_nights'] ?? null,
                'sellable_room_nights' => $facts['sellable_room_nights'] ?? null,
                'fact_scope' => (string)($facts['fact_scope'] ?? ''),
                'source_type' => (string)($facts['source_type'] ?? ''),
                'source_reference' => (string)($facts['source_reference'] ?? ''),
                'quality_status' => (string)($facts['quality_status'] ?? ''),
                'fact_captured_at' => (string)($facts['fact_captured_at'] ?? ''),
            ],
            'calculation' => [
                'status' => (string)($calculation['status'] ?? ''),
                'completion_rate_percent' => $metrics['completion_rate_percent'] ?? null,
                'remaining_revenue' => $metrics['remaining_revenue'] ?? null,
                'actual_occupancy_rate_percent' => $metrics['actual_occupancy_rate_percent'] ?? null,
                'occupancy_gap_points' => $metrics['occupancy_gap_points'] ?? null,
                'actual_revpar' => $metrics['actual_revpar'] ?? null,
                'revpar_gap' => $metrics['revpar_gap'] ?? null,
            ],
        ];
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('operating_target_execution_provenance_encode_failed');
        }
        return hash('sha256', $json);
    }
}
