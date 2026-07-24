<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudDataHealthService;
use PHPUnit\Framework\TestCase;

final class CloudDataHealthServiceTest extends TestCase
{
    public function testVerifiedHotelDateAndReadbackCanGenerateReport(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip', 'meituan'],
            [
                $this->row(1, 'ctrip', 11),
                $this->row(2, 'meituan', 12),
            ],
            [
                ['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1],
                ['id' => 12, 'system_hotel_id' => 7, 'platform' => 'meituan', 'enabled' => 1],
            ],
            [],
            true
        );

        self::assertSame('verified', $result['status']);
        self::assertTrue($result['can_generate_report']);
        self::assertSame([], $result['issues']);
        self::assertSame(2, $result['readback']['target_row_count']);
    }

    public function testLoginExpiryAndWrongDateBlockWithoutInventingZero(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip', 'meituan'],
            [
                array_replace($this->row(2, 'meituan', 12), ['data_date' => '2026-07-20']),
            ],
            [
                ['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1, 'last_error' => 'login_expired'],
                ['id' => 12, 'system_hotel_id' => 7, 'platform' => 'meituan', 'enabled' => 1],
            ],
            [
                ['id' => 9, 'platform' => 'ctrip', 'status' => 'failed', 'message' => '请重新登录'],
            ],
            true
        );

        $codes = array_column($result['issues'], 'code');
        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('login_expired', $codes);
        self::assertContains('target_date_missing', $codes);
        self::assertContains('stale_before_target', $codes);
        self::assertStringNotContainsString('=0', (string)json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    public function testCrossHotelAndReadbackMismatchStayBlocked(): void
    {
        $row = $this->row(1, 'ctrip', 11);
        $row['system_hotel_id'] = 8;
        $row['readback_verified'] = 0;
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$row],
            [['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('hotel_scope_mismatch', array_column($result['issues'], 'code'));
        self::assertFalse($result['readback']['verified']);
    }

    public function testMissingFieldValidationFlagBlocksReportGeneration(): void
    {
        $row = $this->row(1, 'ctrip', 11);
        $row['validation_flags'] = '[{"level":"warning","code":"metric_value_missing"}]';
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$row],
            [['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains(
            'field_validation_metric_value_missing',
            array_column($result['issues'], 'code')
        );
        self::assertSame(1, $result['readback']['target_row_count']);
    }

    public function testDisabledSourceHistoryDoesNotMaskMissingActiveSourceData(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$this->row(1, 'ctrip', 10)],
            [
                ['id' => 10, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 0],
                ['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1],
            ],
            [],
            true
        );

        $codes = array_column($result['issues'], 'code');
        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('target_date_missing', $codes);
        self::assertNotContains('data_source_hotel_mismatch', $codes);
        self::assertSame(0, $result['readback']['target_row_count']);
    }

    public function testRetiredSnapshotRowsRemainAuditableButDoNotBlockCurrentSnapshot(): void
    {
        $retired = array_replace($this->row(3, 'ctrip', 11), [
            'validation_status' => 'unverified',
            'readback_verified' => 0,
            'validation_flags' => json_encode([[
                'level' => 'warning',
                'code' => 'cloud_bundle_row_absent_from_newer_verified_snapshot',
            ]], JSON_UNESCAPED_UNICODE),
        ]);
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip', 'meituan'],
            [
                $this->row(1, 'ctrip', 11),
                $retired,
                $this->row(2, 'meituan', 12),
            ],
            [
                ['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1],
                ['id' => 12, 'system_hotel_id' => 7, 'platform' => 'meituan', 'enabled' => 1],
            ],
            [],
            true
        );

        self::assertSame('verified', $result['status']);
        self::assertTrue($result['can_generate_report']);
        self::assertSame([], $result['issues']);
        self::assertSame(2, $result['readback']['target_row_count']);
        self::assertSame(1, $result['readback']['retired_target_row_count']);
        self::assertSame(1, $result['platforms'][0]['target_row_count']);
        self::assertSame(1, $result['platforms'][0]['retired_target_row_count']);
    }

    public function testOrdinaryUnverifiedRowsStillBlockCurrentSnapshot(): void
    {
        $unverified = array_replace($this->row(1, 'ctrip', 11), [
            'validation_status' => 'unverified',
            'readback_verified' => 0,
            'validation_flags' => '[{"level":"warning","code":"metric_value_unverified"}]',
        ]);
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$unverified],
            [['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('validation_failed', array_column($result['issues'], 'code'));
        self::assertSame(1, $result['readback']['target_row_count']);
        self::assertSame(0, $result['readback']['retired_target_row_count']);
    }

    public function testAuxiliaryOnlyPlatformBlocksReportUntilCoreMetricsExist(): void
    {
        $meituanTraffic = array_replace($this->row(2, 'meituan', 12), [
            'data_type' => 'traffic_forecast',
            'dimension' => 'traffic_forecast:detail_uv',
        ]);
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip', 'meituan'],
            [
                $this->row(1, 'ctrip', 11),
                $meituanTraffic,
            ],
            [
                ['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1],
                ['id' => 12, 'system_hotel_id' => 7, 'platform' => 'meituan', 'enabled' => 1],
            ],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('core_business_metrics_missing', array_column($result['issues'], 'code'));
        self::assertSame(1, $result['platforms'][0]['core_business_row_count']);
        self::assertSame(0, $result['platforms'][1]['core_business_row_count']);
        self::assertSame(1, $result['blocking_issue_count']);
    }

    public function testLatestPartialCollectionBlocksReportGeneration(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$this->row(1, 'ctrip', 11)],
            [['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [[
                'id' => 99,
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'status' => 'partial_success',
                'message' => 'cloud_bundle_rows_imported_and_read_back',
                'finished_at' => '2026-07-22 10:00:00',
            ]],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('latest_collection_partial', array_column($result['issues'], 'code'));
    }

    public function testRowsWithoutDataSourceBindingCannotBecomeTrustedByPlatformName(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$this->row(1, 'ctrip', 0)],
            [['id' => 11, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('data_source_binding_missing', array_column($result['issues'], 'code'));
        self::assertSame(0, $result['platforms'][0]['trusted_row_count']);
    }

    public function testWeakRawPayloadWithoutFieldFactsCannotGenerateReport(): void
    {
        $row = array_replace($this->row(1, 'ctrip', 11), [
            'raw_data' => '{"metric":1}',
        ]);
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$row],
            [['id' => 11, 'tenant_id' => 2, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('field_evidence_missing', array_column($result['issues'], 'code'));
        self::assertSame(0, $result['platforms'][0]['field_evidence_row_count']);
    }

    public function testMissingRowTenantScopeCannotGenerateReport(): void
    {
        $row = array_replace($this->row(1, 'ctrip', 11), ['tenant_id' => 0]);
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 2, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$row],
            [['id' => 11, 'tenant_id' => 2, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('tenant_scope_missing', array_column($result['issues'], 'code'));
        self::assertSame(0, $result['platforms'][0]['trusted_row_count']);
    }

    public function testMissingHotelTenantScopeCannotGenerateReport(): void
    {
        $result = CloudDataHealthService::evaluate(
            ['id' => 7, 'tenant_id' => 0, 'name' => '测试酒店'],
            '2026-07-21',
            ['ctrip'],
            [$this->row(1, 'ctrip', 11)],
            [['id' => 11, 'tenant_id' => 2, 'system_hotel_id' => 7, 'platform' => 'ctrip', 'enabled' => 1]],
            [],
            true
        );

        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['can_generate_report']);
        self::assertContains('hotel_tenant_scope_missing', array_column($result['issues'], 'code'));
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $platform, int $dataSourceId): array
    {
        return [
            'id' => $id,
            'tenant_id' => 2,
            'system_hotel_id' => 7,
            'data_date' => '2026-07-21',
            'source' => $platform,
            'platform' => $platform,
            'data_type' => 'business_overview',
            'validation_status' => 'normal',
            'validation_flags' => '[]',
            'data_source_id' => $dataSourceId,
            'readback_verified' => 1,
            'source_trace_id' => $platform . '-trace-' . $id,
            'raw_data' => json_encode([
                'source_trace_id' => $platform . '-trace-' . $id,
                'source_url_hash' => str_repeat('a', 64),
                'capture_evidence' => [
                    'source_trace_id' => $platform . '-trace-' . $id,
                    'source_url_hash' => str_repeat('a', 64),
                ],
                'field_facts' => [[
                    'metric_key' => 'order_amount',
                    'status' => 'captured',
                    'source_path' => '$.amount',
                    'storage_field' => 'online_daily_data.amount',
                    'stored_value_present' => true,
                    'capture_evidence' => [
                        'source_trace_id' => $platform . '-trace-' . $id,
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ]],
            ], JSON_UNESCAPED_SLASHES),
        ];
    }
}
