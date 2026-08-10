<?php
declare(strict_types=1);

namespace Tests;

use app\service\P0OtaDownstreamGateService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;

final class P0OtaDownstreamGateServiceTest extends TestCase
{
    public function testBlockedGateKeepsMissingTargetDateEvidenceExplicit(): void
    {
        $gate = (new P0OtaDownstreamGateService())->blockedForDataset('2026-06-27', 7, [
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ]);

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertSame('not_verified', $gate['current_upstream_status']);
        self::assertSame('ready', $gate['required_upstream_status']);
        self::assertSame('npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27 --system-hotel-id=7', $gate['required_gate_command']);
        self::assertContains('p0_field_loop_verifier_ready', $gate['blocking_missing_inputs']);
        self::assertContains('target_date_ota_rows', $gate['blocking_missing_inputs']);
        self::assertContains('target_date_traffic_rows', $gate['blocking_missing_inputs']);
        self::assertContains('revenue_analysis', $gate['blocked_stage_keys']);
        self::assertContains('no_whole_hotel_or_downstream_closure_claim', $gate['allowed_claims']);
    }

    public function testReadyGateNormalizesToNoBlockingInputs(): void
    {
        $gate = (new P0OtaDownstreamGateService())->normalize([
            'status' => 'ready',
            ...$this->authorityMetadata('2026-06-27', 7, ['ctrip', 'meituan']),
        ], '2026-06-27', 7, ['ctrip', 'meituan']);

        self::assertSame('ready', $gate['status']);
        self::assertSame([], $gate['blocking_missing_inputs']);
        self::assertSame([], $gate['blocked_stage_keys']);
        self::assertSame(
            'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-27 --platform=ctrip,meituan --system-hotel-id=7',
            $gate['required_gate_command']
        );
        self::assertContains('p0_ota_field_loop_ready_for_downstream_claims', $gate['allowed_claims']);
    }

    public function testGateCommandCanStayScopedToCtripOnly(): void
    {
        $gate = (new P0OtaDownstreamGateService())->blockedForDataset('2026-06-28', null, [
            'fact_ota_daily' => [
                ['source' => 'ctrip'],
            ],
            'fact_ota_traffic' => [
                ['source' => 'ctrip'],
            ],
        ], ['ctrip']);

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertSame(
            'npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-28 --platform=ctrip',
            $gate['required_gate_command']
        );
        self::assertStringNotContainsString('meituan', $gate['required_gate_command']);
    }

    public function testP0GateProjectsCanonicalCollectionQualityWithoutCredentials(): void
    {
        $service = new P0OtaDownstreamGateService();
        $blocked = $service->blockedForDataset('2026-06-27', 7, [
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ]);

        $quality = $service->collectionQuality($blocked);
        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertSame('ota_channel', $quality['metric_scope']);
        self::assertContains('p0_field_loop_verifier_ready', $quality['quality_flags']);
        self::assertArrayNotHasKey('raw_payload', $quality);
        self::assertArrayNotHasKey('token', $quality);

        $readyQuality = $service->collectionQuality($service->normalize([
            'status' => 'ready',
            ...$this->authorityMetadata('2026-06-27', 7, ['ctrip', 'meituan']),
        ], '2026-06-27', 7, ['ctrip', 'meituan']));
        self::assertSame('available', $readyQuality['primary_quality_state']);
        self::assertSame([], $readyQuality['quality_flags']);
    }

    public function testP0QualityProjectionKeepsSpecificFailureClasses(): void
    {
        $service = new P0OtaDownstreamGateService();
        foreach ([
            'poi_missing' => 'binding_missing',
            'permission_denied' => 'permission_denied',
            'snapshot_not_saved' => 'collection_failed',
            'stale_target_date_data' => 'stale',
        ] as $flag => $expectedState) {
            $quality = $service->collectionQuality([
                'status' => 'blocked_by_p0_ota_gate',
                'blocking_missing_inputs' => [$flag],
            ]);

            self::assertSame($expectedState, $quality['primary_quality_state'], $flag);
            self::assertSame([$flag], $quality['quality_flags'], $flag);
        }
    }

    public function testRuntimeGateBecomesReadyOnlyForExactRequestedPlatformEvidence(): void
    {
        $service = new P0OtaDownstreamGateService();
        $gate = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $this->continuousTrust([
                $this->platformTrust('ctrip', 'verified', 'ready'),
                $this->platformTrust('meituan', 'partial', 'blocked', ['readback']),
            ]),
            ['ctrip'],
            $this->authorityReceipt(['ctrip'])
        );

        self::assertSame('ready', $gate['status']);
        self::assertSame('external_p0_verifier', $gate['verification_source']);
        self::assertSame('2026-07-22', $gate['target_date']);
        self::assertSame(7, $gate['hotel_id']);
        self::assertSame(['ctrip'], $gate['verified_platforms']);
        self::assertSame(
            'npm.cmd run verify:p0-ota-field-loop -- --date=2026-07-22 --platform=ctrip --system-hotel-id=7',
            $gate['required_gate_command']
        );
        self::assertFalse($gate['sensitive_values_exposed']);
        self::assertSame(str_repeat('a', 64), $gate['verifier_report_hash']);
    }

    public function testRuntimeGateRejectsCoreStatusTamperEvenWhenVerifierKeepsTheOldAnchor(): void
    {
        $receipt = $this->authorityReceipt(['ctrip']);
        $receipt['source_tasks'][0]['historical_core_contract_status'] = 'blocked';
        $gate = (new P0OtaDownstreamGateService())->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $this->continuousTrust([
                $this->platformTrust('ctrip', 'verified', 'ready'),
            ]),
            ['ctrip'],
            $receipt
        );

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertContains(
            'p0_authority_collection_anchor_mismatch',
            $gate['blocking_missing_inputs']
        );
    }

    public function testRuntimeGateFailsClosedWhenRequestedPlatformReadbackIsMissing(): void
    {
        $service = new P0OtaDownstreamGateService();
        $gate = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $this->continuousTrust([
                $this->platformTrust('ctrip', 'partial', 'blocked', ['readback', 'p0']),
            ]),
            ['ctrip']
        );

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertSame('partial', $gate['current_upstream_status']);
        self::assertContains('p0_field_loop_verifier_ready', $gate['blocking_missing_inputs']);
        self::assertContains('ctrip_readback_not_ready', $gate['blocking_missing_inputs']);
        self::assertContains('ctrip_p0_not_ready', $gate['blocking_missing_inputs']);
        self::assertContains('ctrip_p0_field_loop_not_ready', $gate['blocking_missing_inputs']);
        self::assertSame([], $gate['verified_platforms']);
    }

    public function testRuntimeGateRejectsStaleOrCrossHotelStandardFacts(): void
    {
        $service = new P0OtaDownstreamGateService();
        $dataset = $this->dataset(['ctrip']);
        $dataset['fact_ota_daily'][0]['date_key'] = '2026-07-21';
        $dataset['fact_ota_traffic'][0]['hotel_key'] = 'system:8';

        $gate = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $dataset,
            $this->continuousTrust([
                $this->platformTrust('ctrip', 'verified', 'ready'),
            ]),
            ['ctrip']
        );

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertContains('ctrip_target_date_ota_rows', $gate['blocking_missing_inputs']);
        self::assertContains('ctrip_target_date_traffic_rows', $gate['blocking_missing_inputs']);
        self::assertSame([], $gate['verified_platforms']);
    }

    public function testRuntimeGateRejectsPlatformTrustForAnotherTargetDate(): void
    {
        $service = new P0OtaDownstreamGateService();
        $platformTrust = $this->platformTrust('ctrip', 'verified', 'ready');
        $platformTrust['target_date'] = '2026-07-21';

        $gate = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $this->continuousTrust([$platformTrust]),
            ['ctrip']
        );

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertContains(
            'ctrip_continuous_trust_target_date_mismatch',
            $gate['blocking_missing_inputs']
        );
        self::assertSame([], $gate['verified_platforms']);
    }

    public function testRuntimeTrustCannotOpenGateWithoutExactExternalVerifierReceipt(): void
    {
        $service = new P0OtaDownstreamGateService();
        $continuous = $this->continuousTrust([
            $this->platformTrust('ctrip', 'verified', 'ready'),
        ]);

        $missingReceipt = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $continuous,
            ['ctrip']
        );
        self::assertSame('blocked_by_p0_ota_gate', $missingReceipt['status']);
        self::assertContains(
            'p0_authority_verifier_receipt_missing',
            $missingReceipt['blocking_missing_inputs']
        );

        $wrongDate = $this->authorityReceipt(['ctrip'], '2026-07-21', 7);
        $scopeMismatch = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $continuous,
            ['ctrip'],
            $wrongDate
        );
        self::assertSame('blocked_by_p0_ota_gate', $scopeMismatch['status']);
        self::assertContains(
            'p0_authority_verifier_scope_mismatch',
            $scopeMismatch['blocking_missing_inputs']
        );

        $bareVerifier = $this->authorityReceipt(['ctrip'])['authority_verifier'];
        $bareVerifierGate = $service->fromContinuousTrust(
            '2026-07-22',
            7,
            $this->dataset(['ctrip']),
            $continuous,
            ['ctrip'],
            $bareVerifier
        );
        self::assertSame('blocked_by_p0_ota_gate', $bareVerifierGate['status']);
        self::assertContains(
            'p0_authority_collection_receipt_invalid',
            $bareVerifierGate['blocking_missing_inputs']
        );
    }

    public function testRuntimeResolverUsesPersistedTrustAndStandardFactsWithoutShellExecution(): void
    {
        $trustCalls = 0;
        $datasetCalls = 0;
        $service = new P0OtaDownstreamGateService(
            function (int $hotelId, string $startDate, string $endDate) use (&$trustCalls): array {
                $trustCalls++;
                self::assertSame(7, $hotelId);
                self::assertSame('2026-07-22', $startDate);
                self::assertSame($startDate, $endDate);
                return $this->continuousTrust([
                    $this->platformTrust('ctrip', 'verified', 'ready'),
                ]);
            },
            function (string $businessDate, int $hotelId, array $platforms) use (&$datasetCalls): array {
                $datasetCalls++;
                self::assertSame('2026-07-22', $businessDate);
                self::assertSame(7, $hotelId);
                self::assertSame(['ctrip'], $platforms);
                return $this->dataset($platforms);
            },
            fn(int $hotelId, string $businessDate): array =>
                $this->authorityReceipt(['ctrip'], $businessDate, $hotelId)
        );

        $gate = $service->resolveRuntime('2026-07-22', 7, null, ['ctrip']);

        self::assertSame('ready', $gate['status']);
        self::assertSame(1, $trustCalls);
        self::assertSame(1, $datasetCalls);
    }

    public function testRuntimeResolverRejectsPortfolioScopeWithoutReadingData(): void
    {
        $called = false;
        $service = new P0OtaDownstreamGateService(
            function () use (&$called): array {
                $called = true;
                return [];
            },
            function () use (&$called): array {
                $called = true;
                return [];
            }
        );

        $gate = $service->resolveRuntime('2026-07-22', null, null, ['ctrip']);

        self::assertSame('blocked_by_p0_ota_gate', $gate['status']);
        self::assertContains('system_hotel_id_required', $gate['blocking_missing_inputs']);
        self::assertFalse($called);
    }

    public function testNormalOverviewServicesUseRuntimeGateInsteadOfHardcodedBlock(): void
    {
        $root = dirname(__DIR__);
        $revenue = (string)file_get_contents($root . '/app/service/RevenueAiOverviewService.php');
        $closure = (string)file_get_contents($root . '/app/service/BusinessClosureOverviewService.php');
        $controller = (string)file_get_contents($root . '/app/controller/OperationManagement.php');

        self::assertStringContainsString('$this->p0GateService->resolveRuntime(', $revenue);
        self::assertStringContainsString('$this->p0GateService->resolveRuntime(', $closure);
        self::assertStringNotContainsString("'status' => 'blocked_by_p0_ota_gate',\n            'current_upstream_status' => 'not_verified'", $closure);
        self::assertStringContainsString("\$this->request->param('business_date', '')", $controller);
    }

    /**
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function dataset(array $platforms): array
    {
        return [
            'fact_ota_daily' => array_map(
                static fn(string $platform): array => [
                    'date_key' => '2026-07-22',
                    'hotel_key' => 'system:7',
                    'platform_key' => $platform,
                    'metric_scope' => 'ota_channel',
                ],
                $platforms
            ),
            'fact_ota_traffic' => array_map(
                static fn(string $platform): array => [
                    'date_key' => '2026-07-22',
                    'hotel_key' => 'system:7',
                    'platform_key' => $platform,
                ],
                $platforms
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $platforms
     * @return array<string, mixed>
     */
    private function continuousTrust(array $platforms): array
    {
        return [
            'metric_scope' => 'ota_channel',
            'hotel_id' => 7,
            'tenant_scope_status' => 'verified',
            'status' => 'partial',
            'days' => [[
                'date' => '2026-07-22',
                'status' => 'partial',
                'platforms' => $platforms,
            ]],
        ];
    }

    /**
     * @param array<int, string> $missingSteps
     * @return array<string, mixed>
     */
    private function platformTrust(
        string $platform,
        string $status,
        string $p0Status,
        array $missingSteps = []
    ): array {
        return [
            'platform' => $platform,
            'status' => $status,
            'target_date' => '2026-07-22',
            'p0_status' => $p0Status,
            'missing_steps' => $missingSteps,
        ];
    }

    /**
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function authorityReceipt(
        array $platforms,
        string $targetDate = '2026-07-22',
        int $hotelId = 7
    ): array {
        $sourceTasks = array_map(
            static fn(string $platform, int $index): array => [
                'data_source_id' => 100 + $index,
                'sync_task_id' => 200 + $index,
                'platform' => $platform,
                'collection_status' => 'success',
                'p0_status' => 'ready',
                'historical_core_contract_status' => 'ready',
                'row_ids' => [300 + $index],
            ],
            $platforms,
            array_keys($platforms)
        );
        $collectionAnchorHash = OtaCollectionAnchorService::hash($sourceTasks);
        return [
            'schema_version' => 3,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'data_period' => 'historical_daily',
            'required_platforms' => $platforms,
            'collection_complete' => true,
            'exportable_snapshot_complete' => true,
            'dual_ota_p0_complete' => true,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $collectionAnchorHash,
            'source_tasks' => $sourceTasks,
            'authority_verifier' => [
                'verification_source' => 'external_p0_verifier',
                'status' => 'passed',
                'exit_code' => 0,
                'authority_ready' => true,
                'target_date' => $targetDate,
                'hotel_id' => $hotelId,
                'required_platforms' => $platforms,
                'verified_platforms' => $platforms,
                'collection_anchor_hash' => $collectionAnchorHash,
                'p0_platforms_ready' => count($platforms),
                'traffic_gates_ready' => count($platforms),
                'continuous_trust_status' => 'verified',
                'continuous_trust_missing_steps' => [],
                'issue_codes' => [],
                'verifier_report_hash' => str_repeat('a', 64),
                'checked_at' => $targetDate . ' 08:45:00',
                'sensitive_values_exposed' => false,
            ],
        ];
    }

    /**
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function authorityMetadata(string $targetDate, int $hotelId, array $platforms): array
    {
        return [
            'verification_source' => 'external_p0_verifier',
            'target_date' => $targetDate,
            'hotel_id' => $hotelId,
            'verified_platforms' => $platforms,
            'verifier_report_hash' => str_repeat('a', 64),
            'verifier_checked_at' => $targetDate . ' 08:45:00',
            'sensitive_values_exposed' => false,
        ];
    }
}
