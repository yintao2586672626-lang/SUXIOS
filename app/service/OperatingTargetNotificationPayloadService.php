<?php
declare(strict_types=1);

namespace app\service;

/**
 * Resolves the exact same-day operating-target snapshot for notification use.
 * It never collects PMS/OTA data and never falls back to another date.
 */
final class OperatingTargetNotificationPayloadService
{
    public const INTEGRATED_CONTRACT_VERSION = 'suxios.single_hotel_operating_digest.v1';

    /** @var callable|null */
    private $businessPreviewLoader;

    /** @var callable|null */
    private $pmsCaptureLoader;

    public function __construct(
        private readonly ?OperatingTargetService $targets = null,
        private readonly ?OperatingTargetReportGateService $gate = null,
        ?callable $businessPreviewLoader = null,
        ?callable $pmsCaptureLoader = null
    ) {
        $this->businessPreviewLoader = $businessPreviewLoader;
        $this->pmsCaptureLoader = $pmsCaptureLoader;
    }

    /** @return array<string, mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate
    ): array {
        [$current, $preview] = $this->integratedPreview(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate
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
        [$current, $preview] = $this->integratedPreview(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate
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

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function integratedPreview(
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

        try {
            $pmsCapture = $this->pmsCaptureLoader === null
                ? (new DingdandaoOperatingTargetCaptureService())
                    ->latest($tenantId, $hotelId, $businessDate)
                : call_user_func(
                    $this->pmsCaptureLoader,
                    $tenantId,
                    $hotelId,
                    $businessDate
                );
        } catch (\Throwable) {
            $pmsCapture = [
                'status' => 'read_failed',
                'quality_status' => 'read_failed',
                'gaps' => [[
                    'code' => 'dingdandao_capture_read_failed',
                    'message' => '订单来了 PMS 事实读取失败。',
                ]],
            ];
        }
        $pmsCapture = is_array($pmsCapture) ? $pmsCapture : [];

        try {
            $businessPreview = $this->businessPreviewLoader === null
                ? (new ManualNotificationBusinessPreviewService())
                    ->preview($hotelId, $businessDate)
                : call_user_func(
                    $this->businessPreviewLoader,
                    $tenantId,
                    $hotelId,
                    $businessDate
                );
        } catch (\Throwable) {
            $businessPreview = [
                'status' => 'read_failed',
                'hotel' => [
                    'id' => $hotelId,
                    'tenant_id' => $tenantId,
                    'name' => $hotelName,
                ],
                'business_date' => $businessDate,
                'sections' => [],
            ];
        }
        $businessPreview = is_array($businessPreview) ? $businessPreview : [];

        $preview['integrated_sources'] = $this->buildIntegratedSources(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $preview,
            $pmsCapture,
            $businessPreview
        );

        return [$current, $preview];
    }

    /**
     * @param array<string,mixed> $targetPreview
     * @param array<string,mixed> $pmsCapture
     * @param array<string,mixed> $businessPreview
     * @return array<string,mixed>
     */
    private function buildIntegratedSources(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        array $targetPreview,
        array $pmsCapture,
        array $businessPreview
    ): array {
        $pms = $this->normalizePms(
            $tenantId,
            $hotelId,
            $businessDate,
            $targetPreview,
            $pmsCapture
        );
        $ota = $this->normalizeOta(
            $tenantId,
            $hotelId,
            $businessDate,
            $businessPreview
        );
        $gaps = array_merge(
            is_array($pms['gaps'] ?? null) ? $pms['gaps'] : [],
            is_array($ota['gaps'] ?? null) ? $ota['gaps'] : []
        );
        $status = ($pms['status'] ?? '') === 'verified'
            && ($ota['status'] ?? '') === 'readback_verified'
            ? 'ready'
            : ($this->hasIdentityConflict($pms, $ota) ? 'blocked' : 'partial');

        return [
            'contract_version' => self::INTEGRATED_CONTRACT_VERSION,
            'required_for_delivery' => true,
            'hotel_id' => $hotelId,
            'hotel_name' => $this->safeText($hotelName, 120),
            'business_date' => $businessDate,
            'status' => $status,
            'pms' => $pms,
            'ota_channel' => $ota,
            'gaps' => array_values($gaps),
            'scope_note' => '订单来了仅代表 PMS 住宿房费事实；携程、美团仅代表各自 OTA 渠道事实。',
        ];
    }

    /**
     * @param array<string,mixed> $targetPreview
     * @param array<string,mixed> $capture
     * @return array<string,mixed>
     */
    private function normalizePms(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $targetPreview,
        array $capture
    ): array {
        $facts = is_array($targetPreview['facts'] ?? null)
            ? $targetPreview['facts']
            : [];
        $summary = is_array($capture['summary'] ?? null)
            ? $capture['summary']
            : [];
        $captureId = (int)($capture['id'] ?? 0);
        $sourceReference = trim((string)($facts['source_reference'] ?? ''));
        $referenceCaptureId = preg_match('/capture:(\d+)\s*$/', $sourceReference, $match) === 1
            ? (int)$match[1]
            : 0;
        $scopeMatches = (int)($capture['tenant_id'] ?? 0) === $tenantId
            && (int)($capture['hotel_id'] ?? 0) === $hotelId
            && (string)($capture['business_date'] ?? '') === $businessDate;
        $captureVerified = $scopeMatches
            && $captureId > 0
            && (string)($capture['provider'] ?? '') === DingdandaoOperatingTargetCaptureService::PROVIDER
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && (string)($capture['reconciliation_status'] ?? '') === 'matched'
            && (string)($capture['capture_status'] ?? '') === 'verified'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified';
        $targetLinked = $captureVerified
            && (string)($facts['fact_scope'] ?? '') === 'accommodation_room_fee'
            && (string)($facts['source_type'] ?? '') === 'pms'
            && $referenceCaptureId === $captureId
            && $this->sameNumber($facts['actual_revenue'] ?? null, $summary['total_room_fee'] ?? null)
            && $this->sameNumber($facts['sold_room_nights'] ?? null, $summary['sold_room_nights'] ?? null)
            && $this->sameNumber(
                $facts['sellable_room_nights'] ?? null,
                $summary['derived_sellable_room_nights'] ?? null
            );
        $metrics = [
            'total_room_fee' => $captureVerified
                ? $this->finiteNumber($summary['total_room_fee'] ?? null)
                : null,
            'adr' => $captureVerified
                ? $this->finiteNumber($summary['adr'] ?? null)
                : null,
            'occupancy_rate_percent' => $captureVerified
                ? $this->finiteNumber($summary['occupancy_rate_percent'] ?? null)
                : null,
            'revpar' => $captureVerified
                ? $this->finiteNumber($summary['revpar'] ?? null)
                : null,
            'sold_room_nights' => $captureVerified
                ? $this->finiteNumber($summary['sold_room_nights'] ?? null)
                : null,
            'average_daily_room_nights' => $captureVerified
                ? $this->finiteNumber($summary['average_daily_room_nights'] ?? null)
                : null,
            'sellable_room_nights' => $captureVerified
                ? $this->finiteNumber($summary['derived_sellable_room_nights'] ?? null)
                : null,
        ];
        $capturedAt = $captureVerified
            ? trim((string)($capture['captured_at'] ?? ''))
            : '';
        $requiredMetricsPresent = !in_array(null, $metrics, true) && $capturedAt !== '';
        $status = !$scopeMatches && $captureId > 0
            ? 'identity_mismatch'
            : (!$captureVerified
                ? $this->pmsFailureStatus($capture)
                : (!$targetLinked
                    ? 'target_fact_mismatch'
                    : ($requiredMetricsPresent ? 'verified' : 'metric_missing')));
        $gaps = [];
        if ($status !== 'verified') {
            $gaps[] = [
                'code' => 'pms_' . $status,
                'message' => match ($status) {
                    'identity_mismatch' => '订单来了 PMS 门店、租户或经营日期与当前报告不匹配。',
                    'target_fact_mismatch' => '经营目标记录与订单来了 PMS capture 不是同一份已核验事实。',
                    'metric_missing' => '订单来了 PMS 已回读，但经营日报所需指标仍有缺失。',
                    'collection_failed', 'read_failed' => '订单来了 PMS 当日事实读取或采集失败。',
                    default => '订单来了 PMS 当日已核验事实尚未取得。',
                },
            ];
        }

        return [
            'status' => $status,
            'provider' => 'dingdandao_pms',
            'provider_label' => '订单来了',
            'scope' => 'accommodation_room_fee',
            'business_date' => $businessDate,
            'capture_id' => $captureId > 0 ? $captureId : null,
            'source_reference' => $captureId > 0
                ? '订单来了住宿数据中心 / capture:' . $captureId
                : null,
            'captured_at' => $capturedAt !== '' ? $capturedAt : null,
            'quality_status' => (string)($capture['quality_status'] ?? 'missing'),
            'readback_status' => (string)($capture['readback_status'] ?? 'missing'),
            'identity_status' => (string)($capture['identity_status'] ?? 'unverified'),
            'reconciliation_status' => (string)($capture['reconciliation_status'] ?? 'unverified'),
            'metrics' => $metrics,
            'gaps' => $gaps,
        ];
    }

    /**
     * @param array<string,mixed> $businessPreview
     * @return array<string,mixed>
     */
    private function normalizeOta(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $businessPreview
    ): array {
        $hotel = is_array($businessPreview['hotel'] ?? null)
            ? $businessPreview['hotel']
            : [];
        $scopeMatches = (int)($hotel['id'] ?? 0) === $hotelId
            && (int)($hotel['tenant_id'] ?? 0) === $tenantId
            && (string)($businessPreview['business_date'] ?? '') === $businessDate;
        $today = is_array($businessPreview['sections']['today_revenue_management'] ?? null)
            ? $businessPreview['sections']['today_revenue_management']
            : [];
        $sourcePlatforms = is_array($today['ota_platforms'] ?? null)
            ? $today['ota_platforms']
            : [];
        $platforms = [];
        $gaps = [];
        foreach (['ctrip' => '携程', 'meituan' => '美团'] as $platform => $label) {
            $input = is_array($sourcePlatforms[$platform] ?? null)
                ? $sourcePlatforms[$platform]
                : [];
            $source = is_array($input['source'] ?? null) ? $input['source'] : [];
            $metricsInput = is_array($input['metrics'] ?? null) ? $input['metrics'] : [];
            $rawStatus = (string)($input['status'] ?? 'pending_collection');
            $sourceVerified = $scopeMatches
                && $rawStatus === 'readback_verified'
                && (int)($source['tenant_id'] ?? 0) === $tenantId
                && (int)($source['system_hotel_id'] ?? 0) === $hotelId
                && (string)($source['data_date'] ?? '') === $businessDate
                && (string)($source['platform'] ?? '') === $platform
                && ($source['readback_verified'] ?? false) === true;
            $metrics = [
                'revenue' => $sourceVerified
                    ? $this->finiteNumber($metricsInput['revenue'] ?? null)
                    : null,
                'orders' => $sourceVerified
                    ? $this->finiteNumber($metricsInput['orders'] ?? null)
                    : null,
                'room_nights' => $sourceVerified
                    ? $this->finiteNumber($metricsInput['room_nights'] ?? null)
                    : null,
            ];
            $collectedAt = $sourceVerified
                ? trim((string)($source['collected_at'] ?? '')) ?: null
                : null;
            $status = !$scopeMatches
                ? 'identity_mismatch'
                : ($sourceVerified
                    ? (!in_array(null, $metrics, true) && $collectedAt !== null
                        ? 'readback_verified'
                        : 'partial_readback_verified')
                    : $this->otaFailureStatus($rawStatus));
            $platforms[$platform] = [
                'platform' => $platform,
                'platform_label' => $label,
                'status' => $status,
                'scope' => 'ota_channel',
                'business_date' => $businessDate,
                'collected_at' => $collectedAt,
                'metrics' => $metrics,
                'source' => [
                    'table' => 'online_daily_data',
                    'system_hotel_id' => $hotelId,
                    'data_date' => $businessDate,
                    'readback_verified' => $status === 'readback_verified',
                ],
            ];
            if ($status !== 'readback_verified') {
                $gaps[] = [
                    'code' => $platform . '_' . $status,
                    'message' => $label . match ($status) {
                        'identity_mismatch' => '门店、租户或经营日期不匹配。',
                        'collection_failed', 'read_failed' => '当日采集或读取失败。',
                        'partial_readback_verified' => '当日回读事实仍缺少指标或采集时间。',
                        'pending_readback' => '当日结果尚未完成数据库回读验证。',
                        default => '当日可信经营事实尚未取得。',
                    },
                ];
            }
        }
        $statuses = array_column($platforms, 'status');

        return [
            'status' => count(array_filter(
                $statuses,
                static fn(string $status): bool => $status === 'readback_verified'
            )) === 2 ? 'readback_verified' : (
                in_array('identity_mismatch', $statuses, true) ? 'blocked' : 'partial'
            ),
            'scope' => 'ota_channel',
            'business_date' => $businessDate,
            'platforms' => $platforms,
            'gaps' => $gaps,
        ];
    }

    /** @param array<string,mixed> $capture */
    private function pmsFailureStatus(array $capture): string
    {
        $status = strtolower(trim((string)(
            $capture['quality_status']
                ?? $capture['capture_status']
                ?? $capture['status']
                ?? 'missing'
        )));
        return match ($status) {
            'collection_failed', 'readback_failed', 'failed' => 'collection_failed',
            'read_failed' => 'read_failed',
            'identity_mismatch' => 'identity_mismatch',
            default => 'missing',
        };
    }

    private function otaFailureStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'collection_failed', 'failed' => 'collection_failed',
            'pending_readback' => 'pending_readback',
            'read_failed' => 'read_failed',
            'identity_mismatch' => 'identity_mismatch',
            default => 'missing',
        };
    }

    /** @param array<string,mixed> $pms @param array<string,mixed> $ota */
    private function hasIdentityConflict(array $pms, array $ota): bool
    {
        if (in_array((string)($pms['status'] ?? ''), [
            'identity_mismatch',
            'target_fact_mismatch',
        ], true)) {
            return true;
        }
        foreach ((array)($ota['platforms'] ?? []) as $platform) {
            if (is_array($platform) && ($platform['status'] ?? '') === 'identity_mismatch') {
                return true;
            }
        }
        return false;
    }

    private function sameNumber(mixed $left, mixed $right): bool
    {
        $left = $this->finiteNumber($left);
        $right = $this->finiteNumber($right);
        return $left !== null && $right !== null && abs($left - $right) < 0.005;
    }

    private function finiteNumber(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        if (is_nan($number) || is_infinite($number)) {
            return null;
        }
        return abs($number - round($number)) < 0.000001
            ? (int)round($number)
            : round($number, 2);
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $limit);
    }
}
