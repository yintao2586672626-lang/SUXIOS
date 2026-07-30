<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatCompetitionReportDeliveryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WechatCompetitionReportDeliveryServiceTest extends TestCase
{
    public function testAggregateKeepsValidatedHotelAndBothDeliveryParts(): void
    {
        $method = new ReflectionMethod(WechatCompetitionReportDeliveryService::class, 'aggregate');
        $service = new WechatCompetitionReportDeliveryService();
        $result = $method->invoke(
            $service,
            80,
            [
                'report_edition' => 'flagship',
                'status_only' => true,
                'source_fingerprint' => 'source-001',
                'bundle_id' => 'bundle-001',
            ],
            [
                'delivery_status' => 'sent',
                'robot_count' => 1,
                'sent_count' => 1,
                'failed_count' => 0,
            ],
            [
                'delivery_status' => 'sent',
                'robot_count' => 1,
                'sent_count' => 1,
                'failed_count' => 0,
            ],
            [
                'generated' => true,
                'image_bytes' => 400000,
            ]
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertSame(80, $result['hotel_id']);
        self::assertSame(1, $result['robot_count']);
        self::assertSame(2, $result['sent_count']);
        self::assertSame(0, $result['failed_count']);
        self::assertSame('sent', $result['delivery_parts']['summary_text']['delivery_status']);
        self::assertSame('sent', $result['delivery_parts']['visual_card']['delivery_status']);
        self::assertTrue($result['single_calculation']);
    }
}
