<?php
declare(strict_types=1);

namespace app\service;

/**
 * Resolves the exact same-day operating-target snapshot for notification use.
 * It never collects PMS/OTA data and never falls back to another date.
 */
final class OperatingTargetNotificationPayloadService
{
    public function __construct(
        private readonly ?OperatingTargetService $targets = null,
        private readonly ?OperatingTargetReportGateService $gate = null
    ) {
    }

    /** @return array<string, mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate
    ): array {
        $current = ($this->targets ?? new OperatingTargetService())
            ->current($tenantId, $hotelId, $businessDate);
        $preview = is_array($current['report_preview'] ?? null)
            ? $current['report_preview']
            : [];
        $page = ($this->gate ?? new OperatingTargetReportGateService())
            ->pagePreview($preview, $hotelName);

        return $page + [
            'operating_target_status' => (string)($current['status'] ?? 'missing'),
            'operating_target_record_id' => (int)($current['record']['id'] ?? 0),
            'snapshot_revision_no' => (int)($current['record']['revision_no'] ?? 0),
            'business_date' => $businessDate,
            'report_preview' => $preview,
        ];
    }

    /** @return array<string, mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $deliveryMode
    ): array {
        $current = ($this->targets ?? new OperatingTargetService())
            ->current($tenantId, $hotelId, $businessDate);
        $preview = is_array($current['report_preview'] ?? null)
            ? $current['report_preview']
            : [];
        $candidate = ($this->gate ?? new OperatingTargetReportGateService())
            ->deliveryCandidate($preview, $hotelName, $deliveryMode);

        return $candidate + [
            'operating_target_status' => (string)($current['status'] ?? 'missing'),
            'operating_target_record_id' => (int)($current['record']['id'] ?? 0),
            'snapshot_revision_no' => (int)($current['record']['revision_no'] ?? 0),
            'report_preview' => $preview,
        ];
    }
}
