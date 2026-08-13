<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\CtripOperatingRadarDiagnosisService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class CtripOperatingRadarDiagnosisServiceTest extends TestCase
{
    use ReflectionHelper;

    private CtripOperatingRadarDiagnosisService $service;

    protected function setUp(): void
    {
        $this->service = new CtripOperatingRadarDiagnosisService();
    }

    public function testExactCtripFactsBuildFiveDimensionsWithoutOfficialScoreOrAutomaticWrites(): void
    {
        $radar = $this->service->build($this->exactDiagnosis());

        self::assertSame(2, $radar['schema_version']);
        self::assertSame('ctrip_operating_radar.v2', $radar['contract_version']);
        self::assertSame('2026-08-11.4', $radar['knowledge']['truth_profile_version']);
        self::assertSame('ctrip_ota_channel_only', $radar['scope']['source_scope']);
        self::assertSame(5, $radar['summary']['dimension_count']);
        self::assertSame(1, $radar['summary']['observed_count']);
        self::assertSame(3, $radar['summary']['partial_count']);
        self::assertSame(1, $radar['summary']['blocked_count']);
        self::assertSame([
            'information_score',
            'friendliness',
            'quality',
            'welcome',
            'platform_technical_service_fee',
        ], array_column($radar['dimensions'], 'key'));

        foreach ($radar['dimensions'] as $dimension) {
            self::assertArrayHasKey('official_score', $dimension);
            self::assertNull($dimension['official_score']);
        }
        self::assertFalse($radar['score_policy']['official_score_available']);
        self::assertFalse($radar['score_policy']['official_weights_available']);
        self::assertFalse($radar['score_policy']['official_formula_available']);
        self::assertNull($radar['score_policy']['composite_score']);
        foreach ([
            'decision_safe',
            'task_draft_safe',
            'external_write_authorized',
            'automatic_pricing',
            'automatic_inventory_change',
            'automatic_commission_change',
            'automatic_marketing',
            'automatic_ota_write',
            'automatic_pms_write',
        ] as $guard) {
            self::assertFalse($radar['guards'][$guard], $guard);
        }

        $welcome = $this->dimension($radar, 'welcome');
        self::assertSame('observed_channel_signal', $welcome['status']);
        self::assertSame('verified', $welcome['root_evidence_status']);
        self::assertSame(['online_daily_data#101'], $welcome['root_evidence_refs']);
        $orders = $this->metric($welcome, 'book_order_num');
        self::assertSame(0, $orders['value']);
        self::assertSame('0单', $orders['display_value']);
        self::assertContains('online_daily_data#101', $welcome['evidence_refs']);

        $fee = $this->dimension($radar, 'platform_technical_service_fee');
        self::assertSame('blocked_by_data', $fee['status']);
        self::assertSame([], $fee['metrics']);
        self::assertNotContains('commission_rate', array_column($fee['metrics'], 'key'));
    }

    public function testNoDataKeepsEveryDimensionBlockedAndBoundToScopeEvidence(): void
    {
        $radar = $this->service->build($this->noDataDiagnosis());

        self::assertSame('blocked_by_data', $radar['status']);
        self::assertSame(5, $radar['summary']['blocked_count']);
        foreach ($radar['dimensions'] as $dimension) {
            self::assertSame('blocked_by_data', $dimension['status']);
            self::assertSame([], $dimension['metrics']);
            self::assertSame(['ota_no_data_scope'], $dimension['evidence_refs']);
        }
    }

    public function testLatestAvailableHistoryNeverBecomesTargetDateRadarEvidence(): void
    {
        $diagnosis = $this->noDataDiagnosis();
        $diagnosis['data_summary'] = [
            'has_ota_data' => true,
            'used_latest_available_data' => true,
        ];
        $diagnosis['requested_date_range'] = ['start_date' => '2026-08-11', 'end_date' => '2026-08-11'];
        $diagnosis['date_range'] = $diagnosis['requested_date_range'];
        $diagnosis['effective_date_range'] = ['start_date' => '2026-08-09', 'end_date' => '2026-08-09'];
        $diagnosis['evidence_sources'] = [[
            'ref' => 'ota_latest_available_not_target_date',
            'table' => 'derived',
            'tags' => ['scope', 'latest_available', 'not_target_date'],
            'metrics' => [],
        ]];

        $radar = $this->service->build($diagnosis);

        self::assertTrue($radar['scope']['uses_latest_available_history']);
        self::assertSame('2026-08-11', $radar['scope']['requested_start_date']);
        self::assertSame('2026-08-09', $radar['scope']['effective_start_date']);
        self::assertSame(5, $radar['summary']['blocked_count']);
        self::assertStringContainsString('不能代表目标日期', $radar['message']);
        foreach ($radar['dimensions'] as $dimension) {
            self::assertSame(['ota_latest_available_not_target_date'], $dimension['evidence_refs']);
            self::assertSame('blocked_by_data', $dimension['status']);
        }
    }

    public function testRadarIsPersistedAndSchemaFourIdentityDetectsContentDrift(): void
    {
        $diagnosis = $this->exactDiagnosis();
        $diagnosis['operating_radar'] = $this->service->build($diagnosis);
        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();

        $snapshot = $this->invokeNonPublic($controller, 'buildOtaDiagnosisSnapshot', [$diagnosis]);
        self::assertSame($diagnosis['operating_radar'], $snapshot['operating_radar']);
        $originalAdr = $this->metric(
            $this->dimension($snapshot['operating_radar'], 'friendliness'),
            'adr'
        );
        self::assertSame(200.0, $originalAdr['value']);

        $identity = $this->invokeNonPublic(
            $controller,
            'otaDiagnosisReadbackIdentity',
            [$snapshot, 80, 'ctrip', 4]
        );
        self::assertArrayHasKey('operating_radar_digest', $identity);

        $roundTrippedSnapshot = json_decode(
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $roundTrippedIdentity = $this->invokeNonPublic(
            $controller,
            'otaDiagnosisReadbackIdentity',
            [$roundTrippedSnapshot, 80, 'ctrip', 4]
        );
        $roundTrippedAdr = $this->metric(
            $this->dimension($roundTrippedSnapshot['operating_radar'], 'friendliness'),
            'adr'
        );
        self::assertSame(200, $roundTrippedAdr['value']);
        self::assertSame(
            $identity['operating_radar_digest'],
            $roundTrippedIdentity['operating_radar_digest'],
            'Integer-valued floats must keep the same radar digest after JSON storage.'
        );

        $changed = $snapshot;
        $changed['operating_radar']['dimensions'][0]['interpretation'] = 'drifted';
        $changedIdentity = $this->invokeNonPublic(
            $controller,
            'otaDiagnosisReadbackIdentity',
            [$changed, 80, 'ctrip', 4]
        );
        self::assertNotSame($identity['operating_radar_digest'], $changedIdentity['operating_radar_digest']);
    }

    public function testPersistenceGuardRejectsCommissionAsTechnicalServiceFee(): void
    {
        $diagnosis = $this->noDataDiagnosis();
        $diagnosis['operating_radar'] = $this->service->build($diagnosis);
        $diagnosis['operating_radar']['dimensions'][4]['metrics'][] = [
            'key' => 'commission_rate',
            'value' => 15,
        ];
        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('commission rate');
        $this->invokeNonPublic(
            $controller,
            'assertOtaDiagnosisDecisionEvidenceScope',
            [$diagnosis, 80, 'ctrip', ['start_date' => '2026-08-10', 'end_date' => '2026-08-10']]
        );
    }

    public function testWholeHotelDailyReportCannotBecomeCtripChannelRadarEvidence(): void
    {
        $diagnosis = $this->exactDiagnosis();
        $diagnosis['evidence_sources'][] = [
            'ref' => 'daily_reports#88',
            'table' => 'daily_reports',
            'tags' => ['daily', 'revenue'],
            'metrics' => ['amount' => 9999],
            'decision_eligible' => true,
        ];
        $diagnosis['operating_radar'] = $this->service->build($diagnosis);

        foreach ($diagnosis['operating_radar']['dimensions'] as $dimension) {
            self::assertNotContains('daily_reports#88', $dimension['evidence_refs']);
        }

        $diagnosis['operating_radar']['dimensions'][3]['evidence_refs'][] = 'daily_reports#88';
        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('decision-eligible Ctrip channel rows');
        $this->invokeNonPublic(
            $controller,
            'assertOtaDiagnosisDecisionEvidenceScope',
            [$diagnosis, 80, 'ctrip', ['start_date' => '2026-08-10', 'end_date' => '2026-08-10']]
        );
    }

    public function testDerivedSummaryWithoutCtripRootKeepsEveryDimensionBlocked(): void
    {
        $diagnosis = $this->exactDiagnosis();
        $diagnosis['evidence_sources'] = [$diagnosis['evidence_sources'][0]];

        $radar = $this->service->build($diagnosis);

        self::assertSame('blocked_by_data', $radar['status']);
        self::assertSame(5, $radar['summary']['blocked_count']);
        foreach ($radar['dimensions'] as $dimension) {
            self::assertSame('blocked_by_data', $dimension['status']);
            self::assertSame('missing', $dimension['root_evidence_status']);
            self::assertSame([], $dimension['root_evidence_refs']);
        }
        $quality = $this->dimension($radar, 'quality');
        self::assertNotEmpty($quality['metrics']);
        foreach ($quality['metrics'] as $metric) {
            self::assertSame('unverified_derived_without_ctrip_channel_root', $metric['evidence_role']);
        }
    }

    public function testQualityRootCannotBeCrowdedOutByCompetitorEvidenceLimit(): void
    {
        $competitors = [];
        for ($index = 0; $index < 21; $index++) {
            $competitors[] = [
                'id' => 900 + $index,
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'system_hotel_id' => 80,
                'hotel_name' => '竞对酒店',
                'data_type' => 'traffic',
                'compare_type' => 'competitor_avg',
                'data_date' => '2026-08-10',
                'list_exposure' => 1000 + $index,
                'validation_status' => 'normal',
                'readback_verified' => 1,
            ];
        }
        $qualityRow = [
            'id' => 88,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'hotel_name' => '测试酒店',
            'data_type' => 'quality',
            'compare_type' => 'self',
            'data_date' => '2026-08-10',
            'avg_psi_score' => 4.8,
            'validation_status' => 'normal',
            'readback_verified' => 1,
        ];
        $allRows = array_merge($competitors, [$qualityRow]);
        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $sources = $this->invokeNonPublic($controller, 'buildOtaDiagnosisEvidenceSources', [[
            'hotel' => ['id' => 80, 'name' => '测试酒店'],
            'online_rows' => $allRows,
            'decision_eligible_online_rows' => $allRows,
            'decision_quality' => ['gate' => 'eligible_rows_only'],
        ], ['avg_psi_score' => 4.8]]);
        $sourcesByRef = array_column($sources, null, 'ref');

        self::assertArrayHasKey('online_daily_data#88', $sourcesByRef);
        self::assertTrue($sourcesByRef['online_daily_data#88']['decision_eligible']);
        self::assertSame(4.8, $sourcesByRef['online_daily_data#88']['metrics']['avg_psi_score']);

        $diagnosis = $this->exactDiagnosis();
        $diagnosis['metrics'] = ['avg_psi_score' => 4.8];
        $diagnosis['evidence_sources'] = $sources;
        $radar = $this->service->build($diagnosis);
        $quality = $this->dimension($radar, 'quality');
        self::assertSame('partial_evidence', $quality['status']);
        self::assertContains('online_daily_data#88', $quality['root_evidence_refs']);
    }

    public function testLegacySchemaThreeRadarWithoutRootContractIsNotReadbackVerified(): void
    {
        $diagnosis = $this->exactDiagnosis();
        $diagnosis['operating_radar'] = $this->service->build($diagnosis);
        $diagnosis['operating_radar']['schema_version'] = 1;
        $diagnosis['operating_radar']['contract_version'] = 'ctrip_operating_radar.v1';
        foreach ($diagnosis['operating_radar']['dimensions'] as &$dimension) {
            unset($dimension['root_evidence_status'], $dimension['root_evidence_refs']);
        }
        unset($dimension);

        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $snapshot = $this->invokeNonPublic($controller, 'buildOtaDiagnosisSnapshot', [$diagnosis]);
        $snapshot['saved_record'] = ['saved' => true, 'readback_verified' => true];
        $identity = $this->invokeNonPublic(
            $controller,
            'otaDiagnosisReadbackIdentity',
            [$snapshot, 80, 'ctrip', 3]
        );
        $context = [
            'schema_version' => 3,
            'record_status' => 'active',
            'platform' => 'ctrip',
            'requested_date_range' => ['start_date' => '2026-08-10', 'end_date' => '2026-08-10'],
            'readback_identity_digest' => $this->invokeNonPublic(
                $controller,
                'otaDiagnosisReadbackIdentityDigest',
                [$identity]
            ),
        ];

        self::assertFalse($this->invokeNonPublic(
            $controller,
            'isStoredOtaDiagnosisReadbackVerified',
            [$context, $snapshot, 80, 'ctrip', $context['requested_date_range']]
        ));
    }

    /** @return array<string,mixed> */
    private function exactDiagnosis(): array
    {
        return [
            'hotel' => ['id' => 80, 'name' => '测试酒店'],
            'platform' => 'ctrip',
            'date_range' => ['start_date' => '2026-08-10', 'end_date' => '2026-08-10'],
            'data_summary' => ['has_ota_data' => true],
            'metrics' => [
                'amount' => 1288.5,
                'quantity' => 6,
                'book_order_num' => 0,
                'adr' => 200.0,
                'list_exposure' => 1800,
                'detail_visitors' => 120,
                'detail_rate' => 6.67,
                'order_rate' => 5,
                'submit_rate' => 80,
                'avg_psi_score' => 92,
                'avg_service_score' => 4.8,
                'commission_rate' => 15,
            ],
            'evidence_sources' => [
                [
                    'ref' => 'source_summary',
                    'table' => 'derived',
                    'tags' => ['summary'],
                    'metrics' => ['amount' => 1288.5, 'book_order_num' => 0, 'detail_visitors' => 120],
                    'decision_eligible' => false,
                ],
                [
                    'ref' => 'online_daily_data#101',
                    'table' => 'online_daily_data',
                    'platform' => 'ctrip',
                    'date' => '2026-08-10',
                    'tags' => ['traffic', 'order', 'revenue', 'service_quality'],
                    'metrics' => [
                        'amount' => 1288.5,
                        'book_order_num' => 0,
                        'detail_visitors' => 120,
                        'avg_service_score' => 4.8,
                    ],
                    'decision_eligible' => true,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function noDataDiagnosis(): array
    {
        return [
            'hotel' => ['id' => 80, 'name' => '测试酒店'],
            'platform' => 'ctrip',
            'date_range' => ['start_date' => '2026-08-10', 'end_date' => '2026-08-10'],
            'data_summary' => ['has_ota_data' => false],
            'metrics' => [],
            'evidence_sources' => [[
                'ref' => 'ota_no_data_scope',
                'table' => 'derived',
                'tags' => ['scope', 'missing_data', 'ota_channel'],
                'metrics' => [],
            ]],
        ];
    }

    /** @param array<string,mixed> $radar @return array<string,mixed> */
    private function dimension(array $radar, string $key): array
    {
        foreach ($radar['dimensions'] as $dimension) {
            if (($dimension['key'] ?? '') === $key) {
                return $dimension;
            }
        }
        self::fail('Dimension not found: ' . $key);
    }

    /** @param array<string,mixed> $dimension @return array<string,mixed> */
    private function metric(array $dimension, string $key): array
    {
        foreach ($dimension['metrics'] as $metric) {
            if (($metric['key'] ?? '') === $key) {
                return $metric;
            }
        }
        self::fail('Metric not found: ' . $key);
    }
}
