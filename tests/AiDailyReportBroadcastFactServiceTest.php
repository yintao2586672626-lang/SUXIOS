<?php
declare(strict_types=1);

use app\service\AiDailyReportBroadcastFactService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportBroadcastFactServiceTest extends TestCase
{
    public function testProductionProjectionCopiesOnlyTheCanonicalFieldClosure(): void
    {
        $closure = [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => '2026-08-23',
            'status' => 'partial',
            'page_identity' => 'dual_ota_field_closure#hotel80',
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
            ],
            'platforms' => [
                'ctrip' => [
                    'status' => 'partial',
                    'identity_status' => 'verified',
                    'current_receipt_all_record_refs' => ['online_daily_data#102231'],
                    'latest_collection' => [
                        'platform_status' => 'verified',
                        'target_date_status' => 'matched',
                        'exact_run_readback_status' => 'verified',
                    ],
                    'revenue_analysis' => ['status' => 'blocked'],
                    'fields' => [[
                        'key' => 'exposure',
                        'metric_key' => 'exposure',
                        'status' => 'field_unavailable',
                        'value' => null,
                        'source_record_refs' => [],
                        'revenue_analysis_consumable' => false,
                    ]],
                ],
                'meituan' => [
                    'status' => 'partial',
                    'identity_status' => 'verified',
                    'current_receipt_all_record_refs' => ['online_daily_data#102476'],
                    'latest_collection' => [
                        'platform_status' => 'verified',
                        'target_date_status' => 'matched',
                        'exact_run_readback_status' => 'verified',
                    ],
                    'revenue_analysis' => [
                        'status' => 'blocked',
                        'consumable_fields' => ['exposure', 'visits', 'conversion'],
                    ],
                    'fields' => [
                        [
                            'key' => 'exposure', 'metric_key' => 'exposure',
                            'status' => 'strict_readback', 'value' => 1422,
                            'source_record_refs' => ['online_daily_data#102476'],
                            'revenue_analysis_consumable' => true,
                        ],
                        [
                            'key' => 'visits', 'metric_key' => 'visits',
                            'status' => 'strict_readback', 'value' => 206,
                            'source_record_refs' => ['online_daily_data#102476'],
                            'revenue_analysis_consumable' => true,
                        ],
                        [
                            'key' => 'conversion', 'metric_key' => 'conversion',
                            'status' => 'verified_calculation', 'value' => 14.49,
                            'source_record_refs' => ['online_daily_data#102476'],
                            'revenue_analysis_consumable' => true,
                        ],
                    ],
                ],
            ],
        ];
        $unexpected = static function (): never {
            throw new RuntimeException('legacy fact reader must not run');
        };
        $service = new AiDailyReportBroadcastFactService(
            static fn(int $hotelId): array => [
                'id' => $hotelId,
                'tenant_id' => 80,
                'name' => '敦煌漠蓝新',
            ],
            $unexpected,
            $unexpected,
            static fn(int $hotelId, string $date): array => $closure
        );

        $contract = $service->build(80, '2026-08-23');

        self::assertSame('dual_ota_field_closure#hotel80', $contract['source_closure_identity']);
        self::assertSame('trusted_ota_daily_fact_consumer.v1', $contract['consumer_contract_version']);
        self::assertSame(1422, $contract['platforms']['meituan']['fields']['exposure']['value']);
        self::assertSame(206, $contract['platforms']['meituan']['fields']['visits']['value']);
        self::assertSame(14.49, $contract['platforms']['meituan']['fields']['conversion']['value']);
        self::assertSame(
            ['online_daily_data#102476'],
            $contract['platforms']['meituan']['accepted_record_refs']
        );
        self::assertSame('field_unavailable', $contract['platforms']['ctrip']['fields']['exposure']['status']);
        self::assertFalse($contract['metric_values_recalculated']);
    }

    public function testStrictFactReaderResultBecomesBroadcastFactsWithoutRecalculation(): void
    {
        $service = new AiDailyReportBroadcastFactService(
            static fn(int $hotelId): array => ['id' => $hotelId, 'tenant_id' => 80, 'name' => '敦煌漠蓝新'],
            static fn(int $tenantId, int $hotelId, string $platform, string $date): array =>
                $platform === 'meituan'
                    ? ['online_daily_data#102476']
                    : ['online_daily_data#102479'],
            static fn(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $dateStart,
                string $dateEnd,
                array $refs
            ): array => $platform === 'meituan' ? [[
                'ref' => 'online_daily_data#102476',
                'data_date' => '2026-08-23',
                'platform' => 'meituan',
                'quality_status' => 'verified',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'collected_at' => '2026-08-24 23:17:33',
                'metric_values' => [
                    'list_exposure' => 1422,
                    'detail_exposure' => 206,
                    'flow_rate' => 14.49,
                    'order_submit_num' => 8,
                ],
            ]] : []
        );

        $contract = $service->build(80, '2026-08-23');
        $meituan = $contract['platforms']['meituan'];

        self::assertSame('strict_readback', $meituan['fields']['exposure']['status']);
        self::assertSame(1422, $meituan['fields']['exposure']['value']);
        self::assertSame(206, $meituan['fields']['visits']['value']);
        self::assertSame(14.49, $meituan['fields']['conversion']['value']);
        self::assertSame(['online_daily_data#102476'], $meituan['fields']['conversion']['source_record_refs']);
        self::assertSame('2026-08-24 23:17:33', $meituan['fields']['collected_at']['value']);
        self::assertSame('missing', $contract['platforms']['ctrip']['fields']['exposure']['status']);
        self::assertSame('analysis_blocked', $contract['analysis_status']);
        self::assertFalse($contract['metric_values_recalculated']);
    }

    public function testMismatchedStrictFactIsRejectedInsteadOfBorrowed(): void
    {
        $service = new AiDailyReportBroadcastFactService(
            static fn(int $hotelId): array => ['id' => $hotelId, 'tenant_id' => 80, 'name' => '敦煌漠蓝新'],
            static fn(int $tenantId, int $hotelId, string $platform, string $date): array =>
                ['online_daily_data#102476'],
            static fn(): array => [[
                'ref' => 'online_daily_data#102476',
                'data_date' => '2026-08-22',
                'platform' => 'meituan',
                'quality_status' => 'verified',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'collected_at' => '2026-08-24 23:17:33',
                'metric_values' => ['list_exposure' => 1422],
            ]]
        );

        $contract = $service->build(80, '2026-08-23');

        self::assertSame('missing', $contract['platforms']['meituan']['fields']['exposure']['status']);
        self::assertSame([], $contract['platforms']['meituan']['accepted_record_refs']);
    }
}
