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
        private readonly ?OperatingTargetReportGateService $gate = null,
        private readonly ?SingleHotelOperatingDigestService $singleHotelDigest = null,
        private readonly ?SingleHotelOperatingBriefService $singleHotelBrief = null
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
        $preview = $this->withSingleHotelDigest(
            $tenantId,
            $hotelId,
            $businessDate,
            $preview
        );
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
        $preview = $this->withSingleHotelDigest(
            $tenantId,
            $hotelId,
            $businessDate,
            $preview
        );
        $candidate = ($this->gate ?? new OperatingTargetReportGateService())
            ->deliveryCandidate($preview, $hotelName, $deliveryMode);

        return $candidate + [
            'operating_target_status' => (string)($current['status'] ?? 'missing'),
            'operating_target_record_id' => (int)($current['record']['id'] ?? 0),
            'snapshot_revision_no' => (int)($current['record']['revision_no'] ?? 0),
            'report_preview' => $preview,
        ];
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function withSingleHotelDigest(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $preview
    ): array {
        $service = $this->singleHotelDigest ?? new SingleHotelOperatingDigestService();
        if (!$service->appliesTo($tenantId, $hotelId)) {
            return $preview;
        }
        try {
            $preview['integrated_sources'] = $service->build(
                $tenantId,
                $hotelId,
                $businessDate,
                $preview
            );
        } catch (\Throwable) {
            $preview['integrated_sources'] = [
                'contract_version' => SingleHotelOperatingDigestService::CONTRACT_VERSION,
                'applies' => true,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => 'blocked',
                'delivery_allowed' => false,
                'sources' => [],
                'gaps' => [[
                    'code' => 'single_hotel_digest_read_failed',
                    'message' => '单店PMS、携程或美团事实读取失败，未使用默认值代替。',
                ]],
                'blockers' => [[
                    'code' => 'single_hotel_digest_read_failed',
                    'message' => '单店综合来源读取失败，已阻断发送。',
                ]],
            ];
        }
        $preview['integrated_message_preview'] = (
            $this->singleHotelBrief ?? new SingleHotelOperatingBriefService()
        )->preview($preview['integrated_sources']);

        return $preview;
    }
}
