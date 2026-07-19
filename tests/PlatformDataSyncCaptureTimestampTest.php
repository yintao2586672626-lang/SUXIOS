<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;

final class PlatformDataSyncCaptureTimestampTest extends TestCase
{
    public function testExplicitNonTrafficSectionOverridesProfileLoginTrafficGate(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'syncRequiresTargetDateTrafficEvidence');
        $method->setAccessible(true);
        $source = [
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
        ];
        $trigger = ['trigger_type' => 'profile_login_verified_sync'];

        self::assertFalse($method->invoke($service, $source, $trigger + ['capture_sections' => 'orders'], []));
        self::assertFalse($method->invoke($service, $source, $trigger + ['capture_sections' => 'reviews'], []));
        self::assertTrue($method->invoke($service, $source, $trigger + ['capture_sections' => 'traffic'], []));
    }

    public function testPayloadCaptureTimestampSurvivesOrderSanitizationAsTruthEvidence(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'captured_at' => '2026-07-20T01:31:14+08:00',
            'rows' => [[
                'data_date' => '2026-07-19',
                'data_type' => 'order',
                'hotel_id' => 'meituan-poi-80',
                'amount' => 2543.53,
                'quantity' => 3,
                'book_order_num' => 3,
                'data_value' => 847.84,
            ]],
        ], [
            'id' => 101,
            'name' => 'Meituan Profile',
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
            'external_hotel_id' => 'meituan-poi-80',
        ], 753);

        self::assertCount(1, $rows);
        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-07-20 01:31:14', $raw['captured_at'] ?? null);
    }
}
