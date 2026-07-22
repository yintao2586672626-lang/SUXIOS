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
            'raw_data' => '{"metric":1}',
        ];
    }
}
