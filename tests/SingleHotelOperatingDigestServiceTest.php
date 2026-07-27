<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelOperatingDigestService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SingleHotelOperatingDigestServiceTest extends TestCase
{
    private const SCOPE = [
        'tenant_id' => 1,
        'hotel_id' => 5,
        'realtime_max_age_minutes' => 180,
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
        self::assertTrue($digest['base_delivery_allowed']);
        self::assertTrue($digest['target_delivery_allowed']);
        self::assertSame('partial', $digest['status']);
        self::assertSame(8275.67, $digest['sources']['pms']['facts']['room_fee_revenue']);
        self::assertSame(13.0, $digest['sources']['pms']['facts']['average_daily_room_nights']);
        self::assertSame(1318.0, $digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertSame(5.0, $digest['sources']['meituan']['facts']['paid_orders']);
        self::assertSame([701], $digest['sources']['ctrip']['lineage']['row_ids']);
        self::assertSame([5], $digest['sources']['ctrip']['lineage']['data_source_ids']);
        self::assertSame(['ctrip-trace-701'], $digest['sources']['ctrip']['lineage']['source_trace_ids']);
        self::assertSame(823, $digest['sources']['meituan']['lineage']['traffic_row_id']);
        self::assertSame(824, $digest['sources']['meituan']['lineage']['order_row_id']);
        self::assertSame(6, $digest['sources']['meituan']['lineage']['data_source_id']);
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

    public function testCtripPlatformHotelIdMismatchDoesNotBlockPmsBaseDelivery(): void
    {
        $digest = $this->service(
            '999999999',
            $this->pmsSummary()
        )->build(1, 5, '2026-07-27', $this->targetPreview());

        self::assertFalse($digest['delivery_allowed']);
        self::assertTrue($digest['base_delivery_allowed']);
        self::assertTrue($digest['target_delivery_allowed']);
        self::assertSame('partial', $digest['status']);
        self::assertSame('mismatched', $digest['sources']['ctrip']['identity_status']);
        self::assertSame('identity_mismatch', $digest['optional_source_status']['ctrip']);
        self::assertNull($digest['sources']['ctrip']['facts']['channel_revenue']);
        self::assertSame([], $digest['blockers']);
        self::assertContains(
            'ctrip_delivery_evidence_missing',
            array_column($digest['integrated_blockers'], 'code')
        );
        self::assertContains(
            'ctrip_optional_source_unavailable',
            array_column($digest['gaps'], 'code')
        );
        self::assertSame(
            'ctrip_platform_hotel_identity_mismatch',
            $digest['sources']['ctrip']['gaps'][0]['code']
        );
    }

    public function testMissingOptionalOtaSourcesDoNotBlockVerifiedPmsBaseDelivery(): void
    {
        $digest = (new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '敦煌漠蓝新',
                'status' => 1,
            ],
            fn(): array => [
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
                'summary' => $this->pmsSummary(),
            ],
            static fn(): array => ['rows' => []],
            static fn(): array => [],
            self::SCOPE
        ))->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['target_delivery_allowed']);
        self::assertSame('partial', $digest['status']);
        self::assertSame('ready', $digest['sources']['pms']['status']);
        self::assertFalse($digest['sources']['ctrip']['delivery_evidence_ready']);
        self::assertFalse($digest['sources']['meituan']['delivery_evidence_ready']);
        self::assertSame([], $digest['blockers']);
        self::assertSame(
            ['ctrip_delivery_evidence_missing', 'meituan_delivery_evidence_missing'],
            array_column($digest['integrated_blockers'], 'code')
        );
        self::assertSame(
            ['missing', 'blocked'],
            array_values($digest['optional_source_status'])
        );
        self::assertContains(
            'ctrip_optional_source_unavailable',
            array_column($digest['gaps'], 'code')
        );
        self::assertContains(
            'meituan_optional_source_unavailable',
            array_column($digest['gaps'], 'code')
        );
        self::assertContains(
            'operating_target_not_set',
            array_column($digest['gaps'], 'code')
        );
    }

    public function testOptionalSourceReadFailuresDoNotBlockVerifiedPmsBaseDelivery(): void
    {
        $digest = (new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => '敦煌漠蓝新',
                'status' => 1,
            ],
            fn(): array => [
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
                'summary' => $this->pmsSummary(),
            ],
            static function (): array {
                throw new \RuntimeException('ctrip_fixture_failed');
            },
            static function (): array {
                throw new \RuntimeException('meituan_fixture_failed');
            },
            self::SCOPE
        ))->build(1, 5, '2026-07-27', []);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['target_delivery_allowed']);
        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('failed', $digest['sources']['ctrip']['status']);
        self::assertSame('failed', $digest['sources']['meituan']['status']);
        self::assertSame([], $digest['blockers']);
        self::assertContains(
            'ctrip_source_read_failed',
            array_column($digest['sources']['ctrip']['gaps'], 'code')
        );
        self::assertContains(
            'meituan_source_read_failed',
            array_column($digest['sources']['meituan']['gaps'], 'code')
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

    public function testCtripRepositoryPartialStatusCannotBecomeReady(): void
    {
        $trusted = $this->trustedFacts();
        $trusted['data_status'] = 'partial';
        $trusted['data_gaps'] = ['pricing_history_required_metric_missing'];

        $digest = $this->serviceFromFacts($trusted, $this->meituanFacts())
            ->build(1, 5, '2026-07-27', []);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('partial', $digest['sources']['ctrip']['status']);
        self::assertSame(
            'ctrip_trusted_repository_not_ready',
            $digest['sources']['ctrip']['gaps'][0]['code']
        );
        self::assertSame(
            ['pricing_history_required_metric_missing'],
            $digest['sources']['ctrip']['repository_data_gaps']
        );
    }

    public function testCtripObservedHotelIdMustMatchConfiguredHotelId(): void
    {
        $trusted = $this->trustedFacts();
        $trusted['rows'][0]['observed_platform_hotel_id'] = 'wrong-ctrip-hotel';

        $digest = $this->serviceFromFacts($trusted, $this->meituanFacts())
            ->build(1, 5, '2026-07-27', []);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('mismatched', $digest['sources']['ctrip']['identity_status']);
        self::assertSame(
            'ctrip_platform_hotel_identity_mismatch',
            $digest['sources']['ctrip']['gaps'][0]['code']
        );
    }

    public function testMeituanUnsafeSourceGateCannotBecomeReady(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['source_enabled'] = false;
        $meituan['source_status'] = 'disabled';
        $meituan['source_gate_verified'] = false;
        $meituan['identity_matched'] = false;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('blocked', $digest['sources']['meituan']['status']);
        self::assertSame(
            'meituan_source_gate_unverified',
            $digest['sources']['meituan']['gaps'][0]['code']
        );
    }

    public function testMeituanMissingOrderRowCannotBecomeReady(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['order_row_id'] = 0;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertFalse($digest['sources']['meituan']['delivery_evidence_ready']);
        self::assertSame('blocked', $digest['optional_source_status']['meituan']);
        self::assertNull($digest['sources']['meituan']['facts']['paid_orders']);
        self::assertNull($digest['sources']['meituan']['facts']['target_date_order_count']);
    }

    public function testMeituanUnverifiedOrderFactCannotBecomeReady(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['order_fact_verified'] = false;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertFalse($digest['sources']['meituan']['delivery_evidence_ready']);
        self::assertSame(
            'meituan_order_fact_unverified',
            $digest['sources']['meituan']['gaps'][0]['code']
        );
        self::assertNull($digest['sources']['meituan']['facts']['paid_orders']);
    }

    public function testMeituanMissingOrderTimestampCannotBecomeReady(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['order_collected_at'] = null;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertFalse($digest['sources']['meituan']['delivery_evidence_ready']);
        self::assertSame('missing', $digest['sources']['meituan']['order_freshness_status']);
        self::assertNull($digest['sources']['meituan']['facts']['target_date_order_count']);
    }

    public function testMeituanObservedPoiMustMatchConfiguredPoi(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['observed_platform_hotel_id'] = 'wrong-meituan-poi';
        $meituan['identity_matched'] = false;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('mismatched', $digest['sources']['meituan']['identity_status']);
        self::assertSame('identity_mismatch', $digest['optional_source_status']['meituan']);
        self::assertSame(
            'meituan_platform_hotel_identity_mismatch',
            $digest['sources']['meituan']['gaps'][0]['code']
        );
    }

    public function testMeituanConflictingConfiguredStoreAndPoiCannotPass(): void
    {
        $meituan = $this->meituanFacts();
        $meituan['configured_platform_hotel_ids'] = [
            '1029642156589279',
            'wrong-store-id',
        ];
        $meituan['identity_matched'] = true;

        $digest = $this->serviceFromFacts($this->trustedFacts(), $meituan)
            ->build(1, 5, '2026-07-27', []);

        self::assertFalse($digest['delivery_allowed']);
        self::assertSame('mismatched', $digest['sources']['meituan']['identity_status']);
        self::assertSame(
            'meituan_platform_hotel_identity_mismatch',
            $digest['sources']['meituan']['gaps'][0]['code']
        );
    }

    public function testCurrentDateFactsBecomeStaleButHistoricalFactsDoNot(): void
    {
        $clock = static fn(): DateTimeImmutable =>
            new DateTimeImmutable('2026-07-28 14:00:00', new \DateTimeZone('Asia/Shanghai'));
        $currentTrusted = $this->trustedFacts('2026-07-28', '2026-07-28 10:00:00');
        $currentMeituan = $this->meituanFacts('2026-07-28', '2026-07-28 10:00:00');
        $current = $this->serviceFromFacts(
            $currentTrusted,
            $currentMeituan,
            '2026-07-28',
            '2026-07-28 10:00:00',
            $clock
        )->build(1, 5, '2026-07-28', []);

        self::assertFalse($current['base_delivery_allowed']);
        self::assertSame('stale', $current['sources']['pms']['freshness_status']);
        self::assertSame('stale', $current['sources']['ctrip']['freshness_status']);
        self::assertSame('stale', $current['sources']['meituan']['freshness_status']);

        $historical = $this->serviceFromFacts(
            $this->trustedFacts('2026-07-27', '2026-07-27 10:00:00'),
            $this->meituanFacts('2026-07-27', '2026-07-27 10:00:00'),
            '2026-07-27',
            '2026-07-27 10:00:00',
            $clock
        )->build(1, 5, '2026-07-27', []);

        self::assertTrue($historical['base_delivery_allowed']);
        self::assertTrue($historical['delivery_allowed']);
        self::assertSame('historical', $historical['sources']['pms']['freshness_status']);
    }

    public function testTargetDeliveryRequiresReadyMatchedTarget(): void
    {
        $preview = $this->targetPreview();
        $preview['status'] = 'partial';

        $digest = $this->service()->build(1, 5, '2026-07-27', $preview);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertTrue($digest['delivery_allowed']);
        self::assertFalse($digest['target_delivery_allowed']);
        self::assertSame('not_ready', $digest['operating_target_status']);
        self::assertContains(
            'operating_target_not_ready',
            array_column($digest['gaps'], 'code')
        );
    }

    public function testCurrentMeituanOrderFactMustAlsoBeFresh(): void
    {
        $clock = static fn(): DateTimeImmutable =>
            new DateTimeImmutable('2026-07-28 14:00:00', new \DateTimeZone('Asia/Shanghai'));
        $meituan = $this->meituanFacts(
            '2026-07-28',
            '2026-07-28 13:30:00'
        );
        $meituan['order_collected_at'] = '2026-07-28 10:00:00';
        $digest = $this->serviceFromFacts(
            $this->trustedFacts('2026-07-28', '2026-07-28 13:30:00'),
            $meituan,
            '2026-07-28',
            '2026-07-28 13:30:00',
            $clock
        )->build(1, 5, '2026-07-28', []);

        self::assertTrue($digest['base_delivery_allowed']);
        self::assertFalse($digest['delivery_allowed']);
        self::assertSame(
            'stale',
            $digest['sources']['meituan']['order_freshness_status']
        );
        self::assertFalse(
            $digest['sources']['meituan']['delivery_evidence_ready']
        );
        self::assertSame('stale', $digest['optional_source_status']['meituan']);
    }

    /**
     * @param array<string,mixed> $trusted
     * @param array<string,mixed> $meituan
     */
    private function serviceFromFacts(
        array $trusted,
        array $meituan,
        string $businessDate = '2026-07-27',
        string $pmsCapturedAt = '2026-07-27 22:10:00',
        ?callable $clock = null
    ): SingleHotelOperatingDigestService {
        return new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => 5,
                'tenant_id' => 1,
                'name' => self::SCOPE['hotel_name'],
                'status' => 1,
            ],
            fn(): array => [
                'id' => 1,
                'tenant_id' => 1,
                'hotel_id' => 5,
                'business_date' => $businessDate,
                'identity_status' => 'matched',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'reconciliation_status' => 'matched',
                'readback_status' => 'readback_verified',
                'captured_at' => $pmsCapturedAt,
                'detail_room_fee_total' => 8275.67,
                'detail_row_count' => 25,
                'summary' => $this->pmsSummary(),
            ],
            static fn(): array => $trusted,
            static fn(): array => $meituan,
            self::SCOPE,
            $clock
        );
    }

    /** @return array<string,mixed> */
    private function trustedFacts(
        string $businessDate = '2026-07-27',
        string $collectedAt = '2026-07-27 22:30:00'
    ): array {
        return [
            'data_status' => 'ready',
            'data_gaps' => [],
            'source_policy' => [
                'hotel_scope' => 'system_hotel_id_strict_exact_only',
                'readback_policy' => 'readback_verified_required_equals_1',
                'platform_hotel_identity_policy' => 'platform_data_source_config_exact_required',
                'metric_scope' => 'ota_channel',
            ],
            'rows' => [[
                'row_id' => 701,
                'data_date' => $businessDate,
                'source' => 'ctrip',
                'data_source_id' => 5,
                'platform_hotel_id' => '130079194',
                'observed_platform_hotel_id' => '130079194',
                'source_trace_id' => 'ctrip-trace-701',
                'amount' => 1318,
                'book_order_num' => 2,
                'quantity' => 2,
                'collected_at' => $collectedAt,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function meituanFacts(
        string $businessDate = '2026-07-27',
        string $collectedAt = '2026-07-27 23:34:00'
    ): array {
        return [
            'business_date' => $businessDate,
            'row_id' => 823,
            'order_row_id' => 824,
            'data_source_id' => 6,
            'source_trace_ids' => ['meituan-traffic-823', 'meituan-order-824'],
            'source_enabled' => true,
            'source_status' => 'success',
            'source_gate_verified' => true,
            'profile_binding_active' => true,
            'configured_platform_hotel_id' => '1029642156589279',
            'observed_platform_hotel_id' => '1029642156589279',
            'identity_matched' => true,
            'readback_verified' => true,
            'field_facts_verified' => true,
            'order_fact_verified' => true,
            'collected_at' => $collectedAt,
            'order_collected_at' => $collectedAt,
            'facts' => [
                'list_exposure' => 1071,
                'detail_exposure' => 174,
                'flow_rate_percent' => 16.25,
                'paid_orders' => 5,
                'target_date_order_count' => 0,
            ],
        ];
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
                'data_status' => 'ready',
                'data_gaps' => [],
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                    'platform_hotel_identity_policy' => 'platform_data_source_config_exact_required',
                    'metric_scope' => 'ota_channel',
                ],
                'rows' => [[
                    'row_id' => 701,
                    'data_date' => '2026-07-27',
                    'source' => 'ctrip',
                    'data_source_id' => 5,
                    'platform_hotel_id' => $ctripPlatformHotelId,
                    'observed_platform_hotel_id' => $ctripPlatformHotelId,
                    'source_trace_id' => 'ctrip-trace-701',
                    'amount' => 1318,
                    'book_order_num' => 2,
                    'quantity' => 2,
                    'collected_at' => '2026-07-27 22:30:00',
                ]],
            ],
            static fn(): array => [
                'business_date' => '2026-07-27',
                'row_id' => 823,
                'order_row_id' => 824,
                'data_source_id' => 6,
                'source_trace_ids' => ['meituan-traffic-823', 'meituan-order-824'],
                'source_enabled' => true,
                'source_status' => 'success',
                'source_gate_verified' => true,
                'profile_binding_active' => true,
                'configured_platform_hotel_id' => '1029642156589279',
                'observed_platform_hotel_id' => '1029642156589279',
                'identity_matched' => true,
                'readback_verified' => true,
                'field_facts_verified' => true,
                'order_fact_verified' => true,
                'collected_at' => '2026-07-27 23:34:00',
                'order_collected_at' => '2026-07-27 23:34:00',
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
