<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelOperatingDigestService;
use PHPUnit\Framework\TestCase;

final class SingleHotelOperatingDigestServiceTest extends TestCase
{
    private const SCOPE = [
        'tenant_id' => 1,
        'hotel_id' => 5,
        'hotel_name' => '敦煌漠蓝新',
        'platforms' => [
            'ctrip' => ['platform_hotel_id' => '130079194'],
            'meituan' => ['platform_hotel_id' => '1029642156589279'],
        ],
    ];

    public function testBuildsExactDateDigestAndKeepsMissingMeituanMetricsNull(): void
    {
        $service = $this->service();

        $digest = $service->build(1, 5, '2026-07-27', $this->targetPreview());

        self::assertTrue($digest['applies']);
        self::assertTrue($digest['delivery_allowed']);
        self::assertSame('partial', $digest['status']);
        self::assertSame(8275.67, $digest['sources']['pms']['facts']['room_fee_revenue']);
        self::assertSame(13.0, $digest['sources']['pms']['facts']['average_daily_room_nights']);
        self::assertSame(1318.0, $digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertSame(5.0, $digest['sources']['meituan']['facts']['paid_orders']);
        self::assertSame(0.0, $digest['sources']['meituan']['facts']['target_date_order_count']);
        self::assertNull($digest['sources']['meituan']['facts']['channel_revenue']);
        self::assertNull($digest['sources']['meituan']['facts']['room_nights']);
        self::assertSame(
            ['meituan_room_revenue_missing', 'meituan_room_nights_missing'],
            array_column($digest['gaps'], 'code')
        );
    }

    public function testIdentityMismatchBlocksDeliveryWithoutFallbackValues(): void
    {
        $service = new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '其他酒店',
                'status' => 1,
            ],
            static fn(): array => [],
            static fn(): array => [],
            static fn(): array => [],
            self::SCOPE
        );

        $digest = $service->build(1, 5, '2026-07-27', $this->targetPreview());

        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('blocked', $digest['status']);
        self::assertContains(
            'single_hotel_identity_mismatch',
            array_column($digest['blockers'], 'code')
        );
        self::assertNull($digest['sources']['pms']['facts']['room_fee_revenue']);
        self::assertNull($digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertNull($digest['sources']['meituan']['facts']['paid_orders']);
    }

    public function testCtripPlatformHotelIdMismatchBlocksDelivery(): void
    {
        $digest = $this->service(
            '999999999',
            $this->pmsSummary()
        )->build(1, 5, '2026-07-27', $this->targetPreview());

        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('mismatched', $digest['sources']['ctrip']['identity_status']);
        self::assertNull($digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertContains(
            'ctrip_delivery_evidence_missing',
            array_column($digest['blockers'], 'code')
        );
        self::assertSame(
            'ctrip_platform_hotel_identity_mismatch',
            $digest['sources']['ctrip']['gaps'][0]['code']
        );
    }

    public function testMissingPmsCoreOperatingIndicatorBlocksDelivery(): void
    {
        $summary = $this->pmsSummary();
        unset($summary['adr']);

        $digest = $this->service(
            '130079194',
            $summary
        )->build(1, 5, '2026-07-27', $this->targetPreview());

        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('blocked', $digest['sources']['pms']['status']);
        self::assertNull($digest['sources']['pms']['facts']['adr']);
        self::assertContains(
            'pms_delivery_evidence_missing',
            array_column($digest['blockers'], 'code')
        );
    }

    public function testMissingTargetDoesNotBlockThreeSourceDigestPreview(): void
    {
        $digest = $this->service()->build(1, 5, '2026-07-27', []);

        self::assertTrue($digest['delivery_allowed']);
        self::assertSame('partial', $digest['status']);
        self::assertSame('not_set', $digest['operating_target_status']);
        self::assertSame([], $digest['blockers']);
        self::assertContains(
            'operating_target_not_set',
            array_column($digest['gaps'], 'code')
        );
        self::assertSame(8275.67, $digest['sources']['pms']['facts']['room_fee_revenue']);
        self::assertSame(1318.0, $digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertSame(5.0, $digest['sources']['meituan']['facts']['paid_orders']);
    }

    /** @param array<string,mixed>|null $pmsSummary */
    private function service(
        string $ctripPlatformHotelId = '130079194',
        ?array $pmsSummary = null
    ): SingleHotelOperatingDigestService
    {
        $pmsSummary ??= $this->pmsSummary();

        return new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '敦煌漠蓝新',
                'status' => 1,
            ],
            static fn(): array => [
                'id' => 1,
                'tenant_id' => 1,
                'hotel_id' => 5,
                'business_date' => '2026-07-27',
                'identity_status' => 'matched',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
                'captured_at' => '2026-07-27 22:10:00',
                'detail_room_fee_total' => 8275.67,
                'detail_row_count' => 25,
                'summary' => $pmsSummary,
            ],
            static fn(): array => [
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                    'platform_hotel_identity_policy' => 'platform_data_source_config_exact_required',
                    'metric_scope' => 'ota_channel',
                ],
                'rows' => [[
                    'data_date' => '2026-07-27',
                    'source' => 'ctrip',
                    'data_source_id' => 5,
                    'platform_hotel_id' => $ctripPlatformHotelId,
                    'amount' => 1318,
                    'book_order_num' => 2,
                    'quantity' => 2,
                    'collected_at' => '2026-07-27 22:30:00',
                ]],
            ],
            static fn(): array => [
                'business_date' => '2026-07-27',
                'row_id' => 823,
                'identity_matched' => true,
                'readback_verified' => true,
                'field_facts_verified' => true,
                'collected_at' => '2026-07-27 23:34:00',
                'facts' => [
                    'list_exposure' => 1071,
                    'detail_exposure' => 174,
                    'flow_rate_percent' => 16.25,
                    'paid_orders' => 5,
                    'target_date_order_count' => 0,
                ],
            ],
            self::SCOPE
        );
    }

    /** @return array<string,mixed> */
    private function pmsSummary(): array
    {
        return [
            'total_room_fee' => 8275.67,
            'adr' => 636.59,
            'occupancy_rate_percent' => 86.67,
            'revpar' => 551.71,
            'sold_room_nights' => 13,
            'average_daily_room_nights' => 13,
            'derived_sellable_room_nights' => 15,
        ];
    }

    /** @return array<string,mixed> */
    private function targetPreview(): array
    {
        return [
            'status' => 'ready',
            'hotel_id' => 5,
            'target_date' => '2026-07-27',
            'facts' => [
                'target_revenue' => 20000,
                'actual_revenue' => 8275.67,
                'sold_room_nights' => 13,
                'sellable_room_nights' => 15,
            ],
        ];
    }
}
