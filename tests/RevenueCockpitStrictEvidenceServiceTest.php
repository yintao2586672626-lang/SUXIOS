<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueCockpitStrictEvidenceService;
use PHPUnit\Framework\TestCase;

final class RevenueCockpitStrictEvidenceServiceTest extends TestCase
{
    public function testMetricAndSourceFailClosedWhenCanonicalRowsMissTheStrictGate(): void
    {
        $capturedRefs = [];
        $service = new RevenueCockpitStrictEvidenceService(
            static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $businessDate,
                array $refs
            ) use (&$capturedRefs): array {
                $capturedRefs = $refs;
                self::assertSame(10, $tenantId);
                self::assertSame(20, $hotelId);
                self::assertSame('meituan', $platform);
                self::assertSame('2026-08-20', $businessDate);
                return [
                    ['ref' => 'online_daily_data#101'],
                    ['ref' => 'online_daily_data#103'],
                ];
            }
        );

        $evidence = $service->build($this->overview(), 10, 20, '2026-08-20', 'meituan');

        self::assertSame(
            ['online_daily_data#101', 'online_daily_data#102', 'online_daily_data#103'],
            $capturedRefs
        );
        self::assertSame('blocked', $evidence['status']);
        self::assertFalse($evidence['all_selected_ota_sources_strict']);
        self::assertFalse($evidence['platforms']['meituan']['source_strict_readback']);
        self::assertSame([101, 103], $evidence['platforms']['meituan']['accepted_row_ids']);
        self::assertSame([102], $evidence['platforms']['meituan']['rejected_row_ids']);
        self::assertFalse($evidence['platforms']['meituan']['metrics']['revenue']['strict_readback']);
        self::assertSame([102], $evidence['platforms']['meituan']['metrics']['revenue']['rejected_row_ids']);
        self::assertTrue($evidence['platforms']['meituan']['metrics']['list_exposure']['strict_readback']);
        self::assertFalse($evidence['metric_values_recalculated']);
    }

    public function testSourceIsReadyOnlyWhenEveryDisplayedMetricRowPassesExactReadback(): void
    {
        $service = new RevenueCockpitStrictEvidenceService(
            static fn(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $businessDate,
                array $refs
            ): array => array_map(
                static fn(string $ref): array => ['ref' => $ref],
                $refs
            )
        );

        $evidence = $service->build($this->overview(), 10, 20, '2026-08-20', 'meituan');

        self::assertSame('ready', $evidence['status']);
        self::assertTrue($evidence['all_selected_ota_sources_strict']);
        self::assertTrue($evidence['platforms']['meituan']['source_strict_readback']);
        self::assertSame([101, 102, 103], $evidence['platforms']['meituan']['accepted_row_ids']);
        self::assertSame([], $evidence['platforms']['meituan']['rejected_row_ids']);
        self::assertTrue($evidence['platforms']['meituan']['metrics']['revenue']['strict_readback']);
        self::assertTrue($evidence['platforms']['meituan']['metrics']['list_exposure']['strict_readback']);
    }

    /** @return array<string,mixed> */
    private function overview(): array
    {
        $status = static fn(string $state, array $rowIds): array => [
            'status' => $state,
            'source_provenance' => ['row_ids' => $rowIds],
        ];
        return [
            'hotel_id' => 20,
            'business_date' => '2026-08-20',
            'three_source_fact_layer' => [
                'business_date' => '2026-08-20',
                'hotel' => [
                    'tenant_id' => 10,
                    'system_hotel_id' => 20,
                    'name' => '测试酒店',
                ],
                'sources' => [
                    'meituan_ota' => [
                        'data_status' => 'readback_verified',
                        'business_date' => '2026-08-20',
                        'actual_business_date' => '2026-08-20',
                        'fact_statuses' => [
                            'revenue' => $status('readback_verified', [102, 101]),
                            'list_exposure' => $status('readback_verified', [103]),
                            'cancellation_rate_percent' => $status('missing', []),
                        ],
                    ],
                ],
            ],
        ];
    }
}
