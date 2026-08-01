<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripTemporalNotificationPayloadService;
use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use PHPUnit\Framework\TestCase;

final class CtripTemporalNotificationPayloadServiceTest extends TestCase
{
    public function testTrustedTenantRowsBecomeAReadyConcisePayload(): void
    {
        $date = date('Y-m-d');
        $capturedAt = date('Y-m-d H:i:s');
        $rows = [
            $this->row(1, 9, 80, 'business_visitor_title', [
                $this->fact('visitor_count', 6, 'visitortotal'),
                $this->fact('visitor_count_last_week', 122, 'lastvisitortotal'),
                $this->fact('competitor_avg_visitor', 13, 'competitoravgnumber'),
            ], $date, $capturedAt),
            $this->row(2, 8, 80, 'business_visitor_title', [
                $this->fact('visitor_count', 999, 'visitortotal'),
            ], $date, $capturedAt),
        ];
        $service = new CtripTemporalNotificationPayloadService(
            static fn(): array => $rows
        );

        $result = $service->pagePreview(
            9,
            80,
            '敦煌漠蓝新',
            $date
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(
            CtripTemporalNotificationPayloadService::RENDER_CONTRACT_VERSION,
            $result['render_contract_version']
        );
        self::assertTrue($result['formal_send_gate']['allowed']);
        self::assertSame(
            1,
            $result['business_preview']['fact_source']['tenant_trusted_row_count']
        );
        self::assertSame(
            'ctrip_ota_channel',
            $result['fact_envelope']['source_scope']
        );
        $content = (string)$result['payload']['text']['content'];
        self::assertStringContainsString('APP访客 6', $content);
        self::assertStringContainsString('上周同期 122', $content);
        self::assertStringContainsString('竞争圈平均 13', $content);
        self::assertStringNotContainsString('999', $content);
        self::assertStringNotContainsString('业务日', $content);
    }

    public function testNoVerifiedRowsBlockInsteadOfInventingZero(): void
    {
        $service = new CtripTemporalNotificationPayloadService(
            static fn(): array => []
        );
        $result = $service->build(
            9,
            80,
            '敦煌漠蓝新',
            date('Y-m-d'),
            'scheduled_test'
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['formal_send_gate']['allowed']);
        self::assertNull($result['payload']);
        self::assertSame(
            'no_sendable_segments',
            $result['reason_code']
        );
    }

    public function testDatabaseLoaderKeepsRealtimeAndFuturePeriodsSeparate(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__)
                . '/app/service/CtripTemporalNotificationPayloadService.php'
        );

        self::assertStringContainsString(
            "->where('data_period', 'realtime_snapshot')",
            $source
        );
        self::assertStringContainsString(
            "->where('data_period', 'next_30_days')",
            $source
        );
        self::assertStringContainsString(
            "->where('is_final', 0)",
            $source
        );
        self::assertStringContainsString(
            '$latestPresentBatchId',
            $source
        );
        self::assertStringContainsString(
            '$latestFutureBatchId',
            $source
        );
        self::assertStringContainsString(
            "->where('sync_task_id', \$latestPresentBatchId)",
            $source
        );
        self::assertStringContainsString(
            "->where('sync_task_id', \$latestFutureBatchId)",
            $source
        );
    }

    public function testManualNotificationTemplateForcesCtripScopeAndUsesDynamicPayload(): void
    {
        $date = date('Y-m-d');
        $capturedAt = date('Y-m-d H:i:s');
        $payloads = new CtripTemporalNotificationPayloadService(
            fn(): array => [
                $this->row(3, 9, 80, 'business_visitor_title', [
                    $this->fact('visitor_count', 6, 'visitortotal'),
                ], $date, $capturedAt),
            ]
        );
        $notifications = new ManualNotificationService(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $payloads
        );

        $preview = $notifications->preview(
            80,
            '敦煌漠蓝新',
            [
                'template_type' => ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE,
                'business_date' => $date,
                'title' => '携程经营播报',
                'body' => '动态生成',
                'send_method' => 'wecom_test',
                'trigger_type' => 'manual_test',
                'source_scope' => 'combined',
                'enabled' => false,
            ],
            9
        );

        self::assertSame('ctrip', $preview['source_scope']);
        self::assertSame('preview_only', $preview['delivery_status']);
        self::assertSame(
            ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE,
            $preview['template_type']
        );
        self::assertStringContainsString(
            'APP访客 6',
            (string)$preview['payload']['text']['content']
        );
        self::assertTrue(ManualNotificationService::requiresTestedRenderContract(
            ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE
        ));
    }

    public function testFormalSchedulerKeepsCtripTextPayloadIntact(): void
    {
        $candidate = [
            'status' => 'ready',
            'payload_fingerprint' => 'original',
            'payload' => [
                'msgtype' => 'text',
                'text' => ['content' => "携程经营播报\nAPP访客 6"],
            ],
        ];
        $method = new \ReflectionMethod(
            ManualNotificationScheduleService::class,
            'formalizeDynamicCandidate'
        );
        $result = $method->invoke(
            new ManualNotificationScheduleService(),
            $candidate
        );

        self::assertSame($candidate, $result);
        self::assertArrayNotHasKey('markdown', $result['payload']);
    }

    public function testSchedulePreviewDoesNotStartCtripCapture(): void
    {
        $calls = 0;
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            static function () use (&$calls): array {
                $calls++;
                return [];
            }
        );
        $method = new \ReflectionMethod(
            ManualNotificationScheduleService::class,
            'prepareCtripSource'
        );
        $result = $method->invoke(
            $service,
            [
                'template_type' =>
                    ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE,
                'hotel_id' => 80,
                'business_date_rule' => 'today',
            ],
            date('Y-m-d'),
            new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Asia/Shanghai')
            ),
            false
        );

        self::assertSame('preview_only', $result['status']);
        self::assertSame(0, $calls);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<string, mixed>
     */
    private function row(
        int $id,
        int $tenantId,
        int $hotelId,
        string $endpointId,
        array $facts,
        string $date,
        string $capturedAt
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => $date,
            'data_type' => 'traffic',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => $capturedAt,
            'is_final' => 0,
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'data_source_id' => 25,
            'sync_task_id' => 1984,
            'source_trace_id' => 'trace-' . $id,
            'raw_data' => [
                'endpoint_id' => $endpointId,
                'captured_at' => $capturedAt,
                'field_facts' => $facts,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fact(string $key, int|float $value, string $path): array
    {
        return [
            'metric_key' => $key,
            'value' => $value,
            'source_path' => $path,
            'fact_status' => 'captured',
        ];
    }
}
