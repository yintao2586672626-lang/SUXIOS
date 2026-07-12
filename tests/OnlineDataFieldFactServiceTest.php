<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataFieldFactService;
use PHPUnit\Framework\TestCase;

final class OnlineDataFieldFactServiceTest extends TestCase
{
    public function testOrderIdRoomCountAndNightsDoNotPretendPlatformReturnedCounts(): void
    {
        $row = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'data_type' => 'order',
            'amount' => 500.0,
            'quantity' => null,
            'book_order_num' => null,
            'data_value' => null,
            'raw_data' => '{}',
        ], [
            'order_id' => 'ORDER-1',
            'total_amount' => 500,
            'room_count' => 2,
            'nights' => 3,
        ]);

        $raw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($raw);
        $metricKeys = array_column($raw['field_facts'] ?? [], 'metric_key');

        self::assertContains('order_amount', $metricKeys);
        self::assertNotContains('room_nights', $metricKeys);
        self::assertNotContains('order_count', $metricKeys);
    }

    public function testExplicitZeroOrderMetricsRemainCapturedFacts(): void
    {
        $row = OnlineDataFieldFactService::attachToOnlineDailyRow([
            'data_type' => 'order',
            'quantity' => 0,
            'book_order_num' => 0,
            'raw_data' => '{}',
        ], [
            'room_nights' => 0,
            'order_count' => 0,
        ]);

        $raw = json_decode((string)$row['raw_data'], true);
        self::assertIsArray($raw);
        $metricKeys = array_column($raw['field_facts'] ?? [], 'metric_key');

        self::assertContains('room_nights', $metricKeys);
        self::assertContains('order_count', $metricKeys);
    }
}
