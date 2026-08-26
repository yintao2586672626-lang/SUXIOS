<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueCockpitStrictEvidenceService;
use PHPUnit\Framework\TestCase;

final class RevenueCockpitStrictEvidenceServiceTest extends TestCase
{
    public function testMetricAndSourceFailClosedWhenCanonicalRowsMissTheStrictGate(): void
    {
        $service = new RevenueCockpitStrictEvidenceService();
        $closure = $this->closure(false);

        $evidence = $service->build(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'meituan',
            $closure
        );

        self::assertSame('blocked', $evidence['status']);
        self::assertFalse($evidence['all_selected_ota_sources_strict']);
        self::assertFalse($evidence['platforms']['meituan']['source_strict_readback']);
        self::assertSame([103], $evidence['platforms']['meituan']['accepted_row_ids']);
        self::assertSame([101, 102], $evidence['platforms']['meituan']['rejected_row_ids']);
        self::assertFalse($evidence['platforms']['meituan']['metrics']['revenue']['strict_readback']);
        self::assertSame([101, 102], $evidence['platforms']['meituan']['metrics']['revenue']['rejected_row_ids']);
        self::assertTrue($evidence['platforms']['meituan']['metrics']['list_exposure']['strict_readback']);
        self::assertFalse($evidence['metric_values_recalculated']);
        self::assertSame('dual_ota_field_closure', $evidence['field_source']);
        self::assertSame($closure['page_identity'], $evidence['closure_identity']);
    }

    public function testSourceIsReadyOnlyWhenEveryDisplayedMetricRowPassesExactReadback(): void
    {
        $service = new RevenueCockpitStrictEvidenceService();
        $closure = $this->closure(true);
        $evidence = $service->build(
            $this->overview(),
            10,
            20,
            '2026-08-20',
            'meituan',
            $closure
        );

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
                'sources' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function closure(bool $revenueReady): array
    {
        $field = static function (
            string $key,
            array $consumerKeys,
            array $ids,
            bool $ready,
            string $status = 'strict_readback'
        ): array {
            return [
                'key' => $key,
                'metric_key' => $key,
                'consumer_metric_keys' => $consumerKeys,
                'status' => $ready ? $status : 'caliber_uncertain',
                'source_record_ids' => $ids,
                'readback_status' => 'readback_verified',
                'strict_final_gate' => $ready,
                'revenue_analysis_consumable' => $ready,
            ];
        };
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => 20,
            'business_date' => '2026-08-20',
            'page_identity' => 'dual_ota_field_closure#strict-test',
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
            ],
            'platforms' => [
                'ctrip' => ['status' => 'partial', 'fields' => [], 'revenue_analysis' => ['status' => 'blocked']],
                'meituan' => [
                    'status' => $revenueReady ? 'ready' : 'partial',
                    'revenue_analysis' => ['status' => $revenueReady ? 'ready' : 'blocked'],
                    'fields' => [
                        $field('revenue', ['revenue'], [101, 102], $revenueReady),
                        $field('exposure', ['list_exposure'], [103], true),
                    ],
                ],
            ],
        ];
    }
}
