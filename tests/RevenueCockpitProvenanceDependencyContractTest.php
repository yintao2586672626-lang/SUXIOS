<?php
declare(strict_types=1);

use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitActionContract;
use app\service\RevenueCockpitIntentProvenanceService;
use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceAggregate;

final class RevenueCockpitProvenanceDependencyContractTest extends TestCase
{
    public function testOperationCurrentnessUsesAcyclicRevenueProvenanceStrategy(): void
    {
        $root = dirname(__DIR__);
        $operation = SourceAggregate::read($root, 'app/service/OperationManagementService.php');
        $strategy = (string)file_get_contents($root . '/app/service/RevenueCockpitIntentProvenanceService.php');
        $lifecycle = (string)file_get_contents($root . '/app/service/OperationActionLifecycleService.php');

        self::assertStringContainsString('RevenueCockpitIntentProvenanceService', $operation);
        self::assertStringContainsString('RevenueCockpitActionContract::SOURCE_MODULE', $operation);
        self::assertStringNotContainsString('RevenueCockpitApprovalService', $operation);
        self::assertStringNotContainsString('OperationManagementService', $strategy);
        self::assertStringNotContainsString('OperationActionLifecycleService', $strategy);
        self::assertStringNotContainsString('RevenueAiOverviewService', $strategy);
        self::assertStringNotContainsString('RevenueCockpitApprovalService', $strategy);
        self::assertStringNotContainsString('RevenueCockpitApprovalService', $lifecycle);
        self::assertStringContainsString('RevenueCockpitActionContract::SOURCE_MODULE', $lifecycle);
    }

    public function testStrategyAcceptsTheExactCurrentFactAndRejectsFactDrift(): void
    {
        $overview = $this->overview(600.0);
        $service = new RevenueCockpitIntentProvenanceService(
            static fn(int $tenantId, int $hotelId, string $date, string $platform): array => $overview
        );
        $contextMethod = new ReflectionMethod($service, 'evidenceContext');
        $contextMethod->setAccessible(true);
        $context = $contextMethod->invoke($service, $overview, 10, 20, '2026-08-20', 'meituan');
        $metricMethod = new ReflectionMethod($service, 'metricContext');
        $metricMethod->setAccessible(true);
        $metric = $metricMethod->invoke($service, $overview, $context, 'revenue');

        $card = (new OperationActionLifecycleService())->buildRevenueCockpitObservationCard([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_record_id' => 201,
            'source_module' => RevenueCockpitActionContract::SOURCE_MODULE,
            'platform' => 'meituan',
            'business_date' => '2026-08-20',
            'metric_key' => 'revenue',
            'metric_unit' => 'CNY',
            'metric_value' => 600.0,
            'metric_rows' => [[
                'ref' => 'online_daily_data#201',
                'platform' => 'meituan',
                'business_date' => '2026-08-20',
                'metric' => 'revenue',
                'value' => null,
                'unit' => 'CNY',
            ]],
            'fact_refs' => ['online_daily_data#201'],
            'fact_snapshot_digest' => $metric['fact_snapshot_digest'],
        ], 7);
        $intent = [
            'id' => 91,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_module' => RevenueCockpitActionContract::SOURCE_MODULE,
            'source_record_id' => 201,
            'platform' => 'meituan',
            'date_start' => '2026-08-20',
            'date_end' => '2026-08-20',
            'expected_metric' => 'revenue',
            'status' => 'pending_approval',
            'target_value' => ['action_card' => $card],
            'evidence' => ['action_card' => $card],
        ];

        $current = $service->assertIntentCurrent($intent);
        self::assertSame('verified', $current['fact_integrity_status']);
        self::assertTrue($service->isIntentCurrent($intent));

        $driftedOverview = $this->overview(601.0);
        $drifted = new RevenueCockpitIntentProvenanceService(
            static fn(int $tenantId, int $hotelId, string $date, string $platform): array => $driftedOverview
        );
        self::assertFalse($drifted->isIntentCurrent($intent));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('收益行动原始事实已漂移');
        $drifted->assertIntentCurrent($intent);
    }

    public function testSnapshotActionLineageRequiresTheCurrentServerAsOfDate(): void
    {
        $service = new RevenueCockpitIntentProvenanceService();
        $assertCurrent = new ReflectionMethod($service, 'assertSnapshotAsOfDateCurrent');
        $current = RevenueOverviewDateContract::serverAsOfDate();

        $assertCurrent->invoke($service, [
            'asOfDate' => $current,
            'asOfDateContractVersion' => RevenueOverviewDateContract::VERSION,
        ]);
        self::assertTrue(true);

        $stale = (new DateTimeImmutable($current, new DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')
            ->format('Y-m-d');
        try {
            $assertCurrent->invoke($service, [
                'asOfDate' => $stale,
                'asOfDateContractVersion' => RevenueOverviewDateContract::VERSION,
            ]);
            self::fail('A stale decision snapshot must not cross the action boundary.');
        } catch (RuntimeException $error) {
            self::assertSame('revenue_decision_snapshot_action_lineage_stale_as_of_date', $error->getMessage());
            self::assertSame(409, $error->getCode());
        }
    }

    /** @return array<string,mixed> */
    private function overview(float $revenue): array
    {
        $source = [
            'data_status' => 'readback_verified',
            'business_date' => '2026-08-20',
            'actual_business_date' => '2026-08-20',
            'source' => [
                'table' => 'online_daily_data',
                'data_date' => '2026-08-20',
                'platform' => 'meituan',
                'row_ids' => [201],
                'readback_status' => 'readback_verified',
            ],
            'facts' => ['revenue' => $revenue],
            'fact_statuses' => [
                'revenue' => [
                    'status' => 'readback_verified',
                    'source_provenance' => ['row_ids' => [201]],
                ],
            ],
        ];
        $strictPlatform = [
            'source_key' => 'meituan_ota',
            'source_status' => 'readback_verified',
            'business_date' => '2026-08-20',
            'requested_row_ids' => [201],
            'accepted_row_ids' => [201],
            'rejected_row_ids' => [],
            'accepted_fact_refs' => ['online_daily_data#201'],
            'source_strict_readback' => true,
            'metrics' => [
                'revenue' => [
                    'source_status' => 'readback_verified',
                    'requested_row_ids' => [201],
                    'accepted_row_ids' => [201],
                    'rejected_row_ids' => [],
                    'strict_readback' => true,
                ],
            ],
        ];
        return [
            'hotel_id' => 20,
            'business_date' => '2026-08-20',
            'three_source_fact_layer' => [
                'business_date' => '2026-08-20',
                'hotel' => ['tenant_id' => 10, 'system_hotel_id' => 20],
                'sources' => ['meituan_ota' => $source],
            ],
            'cockpit_strict_evidence' => [
                'contract_version' => 'revenue_cockpit_strict_evidence.v1',
                'tenant_id' => 10,
                'hotel_id' => 20,
                'business_date' => '2026-08-20',
                'platform' => 'meituan',
                'platforms' => ['meituan' => $strictPlatform],
            ],
            'dual_ota_field_closure' => [
                'contract_version' => 'dual_ota_field_closure.v1',
                'tenant_id' => 10,
                'hotel_id' => 20,
                'business_date' => '2026-08-20',
                'closure_digest' => str_repeat('a', 64),
                'platforms' => [
                    'meituan' => [
                        'status' => 'ready',
                        'revenue_analysis' => ['status' => 'ready'],
                        'current_collection_blocker_status' => null,
                        'current_receipt_record_ids' => [201],
                        'latest_collection' => ['status' => 'success'],
                    ],
                ],
            ],
        ];
    }
}
