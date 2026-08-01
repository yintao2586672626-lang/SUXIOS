<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Loads exact-hotel verified Ctrip rows and turns them into a notification
 * candidate. It never collects, sends or enables a schedule.
 */
final class CtripTemporalNotificationPayloadService
{
    public const CONTRACT_VERSION = 'ctrip_temporal_notification_payload.v1';
    public const RENDER_CONTRACT_VERSION = 'ctrip_temporal.daily.v1';
    public const TEMPLATE_TYPE = 'ctrip_temporal_report';

    private const TIMEZONE = 'Asia/Shanghai';

    /** @var callable|null */
    private $rowLoader;

    public function __construct(
        ?callable $rowLoader = null,
        private readonly ?CtripTemporalBroadcastService $broadcasts = null
    ) {
        $this->rowLoader = $rowLoader;
    }

    /** @return array<string, mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate
    ): array {
        return $this->build(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            'page_preview'
        );
    }

    /** @return array<string, mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $deliveryMode
    ): array {
        $this->assertScope($tenantId, $hotelId, $hotelName);
        $businessDate = $this->normalizeDate($businessDate);

        try {
            $preview = $this->broadcastPreview(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate
            );
        } catch (\Throwable $error) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                'ctrip_temporal_rows_unavailable',
                '携程可信回读数据读取失败，本轮未生成消息。',
                [
                    'source_error' => $this->safeText($error->getMessage(), 160),
                ],
                $deliveryMode
            );
        }

        $payload = is_array($preview['payload'] ?? null)
            ? $preview['payload']
            : null;
        $sendGate = is_array($preview['send_gate'] ?? null)
            ? $preview['send_gate']
            : [];
        $ready = $payload !== null && ($sendGate['should_send'] ?? false) === true;
        if (!$ready) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                (string)($sendGate['reason_code'] ?? 'ctrip_temporal_no_sendable_segments'),
                '携程当天没有通过来源、日期与回读门禁的可播报数据，本轮未生成消息。',
                $preview,
                $deliveryMode
            );
        }

        $fingerprint = trim((string)($preview['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $fingerprint = hash('sha256', $this->json($payload));
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'render_contract_version' => self::RENDER_CONTRACT_VERSION,
            'status' => 'ready',
            'reason_code' => 'ctrip_temporal_message_ready',
            'business_date' => $businessDate,
            'template_type' => self::TEMPLATE_TYPE,
            'payload_fingerprint' => $fingerprint,
            'preview_fingerprint' => $fingerprint,
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => [
                'allowed' => true,
                'status' => 'formal_send_allowed',
                'blockers' => [],
            ],
            'payload' => $payload,
            'business_preview' => $preview,
            'fact_envelope' => $this->factEnvelope(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $preview,
                $deliveryMode,
                'ready'
            ),
        ];
    }

    /**
     * Returns the raw temporal broadcast preview for API and notification use.
     *
     * @return array<string, mixed>
     */
    public function broadcastPreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $messageMode = 'daily',
        string $previousFingerprint = '',
        bool $baselineOnly = false
    ): array {
        $this->assertScope($tenantId, $hotelId, $hotelName);
        $businessDate = $this->normalizeDate($businessDate);
        $loadedRows = $this->loadRows($tenantId, $hotelId, $businessDate);
        $trustedTenantRows = array_values(array_filter(
            $loadedRows,
            static fn(mixed $row): bool => is_array($row)
                && (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($row['source'] ?? ''))) === 'ctrip'
                && strtolower(trim((string)($row['platform'] ?? ''))) === 'ctrip'
                && filter_var(
                    $row['readback_verified'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                )
        ));

        $preview = ($this->broadcasts ?? new CtripTemporalBroadcastService())
            ->buildFromStoredRows(
                $trustedTenantRows,
                $hotelId,
                $hotelName,
                $businessDate,
                $messageMode,
                $previousFingerprint,
                $baselineOnly
            );
        $preview['fact_source']['loader_row_count'] = count($loadedRows);
        $preview['fact_source']['tenant_trusted_row_count'] = count($trustedTenantRows);
        $preview['fact_source']['tenant_id'] = $tenantId;
        return $preview;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(int $tenantId, int $hotelId, string $businessDate): array
    {
        if ($this->rowLoader !== null) {
            $rows = call_user_func(
                $this->rowLoader,
                $tenantId,
                $hotelId,
                $businessDate
            );
            if (!is_array($rows)) {
                throw new \RuntimeException('ctrip_temporal_row_loader_invalid');
            }
            return array_values(array_filter($rows, 'is_array'));
        }

        $asOf = new DateTimeImmutable(
            $businessDate . ' 00:00:00',
            new DateTimeZone(self::TIMEZONE)
        );
        $from = $asOf->modify('-30 days')->format('Y-m-d');
        $yesterday = $asOf->modify('-1 day')->format('Y-m-d');
        $baseQuery = static function () use ($tenantId, $hotelId) {
            return Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->where('platform', 'ctrip')
                ->where('readback_verified', 1);
        };

        $latestPresentBatchId = (int)($baseQuery()
            ->where('data_date', $businessDate)
            ->where('data_period', 'realtime_snapshot')
            ->where('is_final', 0)
            ->where('sync_task_id', '>', 0)
            ->order('sync_task_id', 'desc')
            ->value('sync_task_id') ?? 0);
        $presentRows = $latestPresentBatchId > 0
            ? $baseQuery()
                ->where('data_date', $businessDate)
                ->where('data_period', 'realtime_snapshot')
                ->where('is_final', 0)
                ->where('sync_task_id', $latestPresentBatchId)
                ->order('id', 'desc')
                ->limit(500)
                ->select()
                ->toArray()
            : [];
        $latestFutureBatchId = (int)($baseQuery()
            ->where('data_date', $businessDate)
            ->where('data_period', 'next_30_days')
            ->where('is_final', 0)
            ->where('dimension', 'like', '%traffic_search_details%')
            ->where('sync_task_id', '>', 0)
            ->order('sync_task_id', 'desc')
            ->value('sync_task_id') ?? 0);
        $futureRows = $latestFutureBatchId > 0
            ? $baseQuery()
                ->where('data_date', $businessDate)
                ->where('data_period', 'next_30_days')
                ->where('is_final', 0)
                ->where('dimension', 'like', '%traffic_search_details%')
                ->where('sync_task_id', $latestFutureBatchId)
                ->order('id', 'desc')
                ->limit(500)
                ->select()
                ->toArray()
            : [];
        $pastRows = $baseQuery()
            ->whereBetween('data_date', [$from, $yesterday])
            ->where('data_period', 'historical_daily')
            ->where('is_final', 1)
            ->order('id', 'desc')
            ->limit(1500)
            ->select()
            ->toArray();

        $rowsById = [];
        foreach (array_merge($presentRows, $futureRows, $pastRows) as $row) {
            $rowsById[(int)($row['id'] ?? 0)] = $row;
        }
        return array_values($rowsById);
    }

    /** @return array<string, mixed> */
    private function factEnvelope(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        array $preview,
        string $deliveryMode,
        string $status
    ): array {
        return [
            'contract_version' => 'ctrip_temporal_notification_fact_envelope.v1',
            'status' => $status,
            'message_type' => self::TEMPLATE_TYPE,
            'hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'name' => $this->safeText($hotelName, 120),
            ],
            'business_date' => $businessDate,
            'source_scope' => 'ctrip_ota_channel',
            'captured_at' => (string)($preview['captured_at'] ?? ''),
            'visible_sections' => array_values(
                array_map('strval', (array)($preview['visible_sections'] ?? []))
            ),
            'quality_status' => (string)($preview['status'] ?? 'blocked'),
            'gaps' => array_values(
                array_map('strval', (array)($preview['internal_gaps'] ?? []))
            ),
            'delivery_mode' => trim($deliveryMode),
            'allowed_uses' => [
                'ctrip_ota_revenue_monitoring',
                'ctrip_ota_notification',
            ],
            'forbidden_uses' => [
                'whole_hotel_full_inference',
                'missing_value_substitution',
                'cross_hotel_reuse',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function blocked(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $code,
        string $message,
        array $preview,
        string $deliveryMode
    ): array {
        $fingerprint = hash(
            'sha256',
            implode('|', [$tenantId, $hotelId, $businessDate, $code])
        );
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'render_contract_version' => self::RENDER_CONTRACT_VERSION,
            'status' => 'blocked',
            'reason_code' => $code,
            'business_date' => $businessDate,
            'template_type' => self::TEMPLATE_TYPE,
            'payload_fingerprint' => $fingerprint,
            'preview_fingerprint' => $fingerprint,
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => [
                'allowed' => false,
                'status' => 'formal_send_blocked',
                'blockers' => [['code' => $code, 'message' => $message]],
            ],
            'payload' => null,
            'business_preview' => $preview,
            'fact_envelope' => $this->factEnvelope(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $preview,
                $deliveryMode,
                'blocked'
            ),
        ];
    }

    private function assertScope(
        int $tenantId,
        int $hotelId,
        string $hotelName
    ): void {
        if ($tenantId <= 0 || $hotelId <= 0 || trim($hotelName) === '') {
            throw new InvalidArgumentException('ctrip_temporal_notification_scope_invalid');
        }
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone(self::TIMEZONE)
        );
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('ctrip_temporal_notification_date_invalid');
        }
        return $value;
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return mb_substr($value, 0, $limit);
    }

    private function json(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
