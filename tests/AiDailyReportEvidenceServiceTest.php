<?php
declare(strict_types=1);
namespace Tests;

use app\service\AiDailyReportEvidenceService;
use app\service\RevenueAnalysisDiagnosticsService;
use app\service\AiDailyReportService;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiEvidenceFixture;

final class AiDailyReportEvidenceServiceTest extends TestCase
{
    private function pack(?array $closure = null): array
    {
        $closure ??= AiEvidenceFixture::closure();
        $service = new AiDailyReportEvidenceService();
        return $service->factPack($closure, $service->scope(9004, 904, $closure['business_date']));
    }

    public function testCompleteFactsKeepUnitsZeroAndRoundTripExactly(): void
    {
        $service = new AiDailyReportEvidenceService();
        $pack = $this->pack();
        self::assertSame('ready', $pack['status']);
        self::assertCount(20, $pack['facts']);
        self::assertSame(0, array_column($pack['facts'], 'value', 'fact_id')['ctrip.cancellation']);
        $snapshot = $service->seal($pack, $service->diagnose($pack), ['status' => 'not_requested']);
        $roundtrip = json_decode(json_encode($snapshot), true);
        $service->verify($roundtrip, $pack['scope']);
        self::assertSame($snapshot, $roundtrip);
        self::assertSame(hash('sha256', $snapshot['final_text']), $snapshot['final_text_sha256']);
    }

    public function testFactMappingRejectsRevenueReplacedByAdr(): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['fields']['revenue'] = $closure['platforms']['ctrip']['fields']['adr'];
        $this->expectExceptionMessage('diagnosis_field_metric_identity_mismatch');
        $this->pack($closure);
    }

    public function testProjectionRejectsDisplayLabelAndSingularReferenceTampering(): void
    {
        $pack = $this->pack(); $service = new AiDailyReportEvidenceService(); $diagnosis = $service->diagnose($pack);
        $snapshot = $service->seal($pack, $diagnosis, ['status' => 'not_requested']);
        $method = new \ReflectionMethod(AiDailyReportService::class, 'projectEvidenceReport');
        $projection = $method->invoke(new AiDailyReportService(), [], $pack, $diagnosis);
        $projection['yesterday_result']['metrics'][0]['label'] = 'WHOLE HOTEL NET PROFIT';
        $projection['yesterday_result']['metrics'][0]['source_ref'] = 'online_daily_data#999999';
        $this->expectExceptionMessage('diagnosis_projection_metric_binding_mismatch');
        $service->assertProjection($projection, $snapshot);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('semanticIdentityMutations')]
    public function testSemanticAliasesCannotChangeMetricPlatformOrUnit(string $field, string $value): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['fields']['exposure'][$field] = $value;
        $this->expectExceptionMessage('diagnosis_field_metric_identity_mismatch');
        $this->pack($closure);
    }
    public static function semanticIdentityMutations(): array
    {
        return [['key', 'revenue'], ['semantic_key', 'adr'], ['semantic_metric_key', 'adr'],
            ['semantic_metric_key', 'meituan_exposure_users'], ['semantic_metric_key', 'ota_exposure_volume'], ['semantic_unit', 'CNY']];
    }

    public function testCanonicalPreciseTrafficSemanticsArePreserved(): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['fields']['exposure']['semantic_metric_key'] = 'ctrip_exposure_users';
        $closure['platforms']['ctrip']['fields']['conversion']['semantic_metric_key'] = 'exposure_to_visit_rate';
        $pack = $this->pack($closure);
        $facts = array_column($pack['facts'], null, 'fact_id');
        self::assertSame('ctrip_exposure_users', $facts['ctrip.exposure']['semantic_key']);
        self::assertSame('exposure_to_visit_rate', $facts['ctrip.conversion']['semantic_key']);
    }

    public function testKnowledgeReferenceCannotMasqueradeAsReadbackFact(): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['accepted_record_refs'][] = 'knowledge_chunks#1';
        $closure['platforms']['ctrip']['fields']['exposure']['source_record_refs'] = ['knowledge_chunks#1'];
        $this->expectExceptionMessage('diagnosis_fact_reference_invalid');
        $this->pack($closure);
    }

    public function testReadbackFailureFlagCannotBeHiddenByConsumableTrue(): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['fields']['revenue']['status'] = 'readback_failed';
        $pack = $this->pack($closure);
        self::assertNotContains('ctrip.revenue', array_column($pack['facts'], 'fact_id'));
        self::assertSame('readback_failed', $pack['gaps'][0]['status']);
        self::assertNull($pack['gaps'][0]['value']);
    }

    public function testExposureDropWithoutDownstreamFactsDoesNotExplainPriceOrHotelPerformance(): void
    {
        $prior = AiEvidenceFixture::closure('2026-09-07', false);
        $prior['platforms']['ctrip']['fields']['exposure']['value'] = 200;
        $pack = $this->pack(AiEvidenceFixture::closure('2026-09-08', false));
        $diagnosis = (new AiDailyReportEvidenceService())->diagnose($pack, $this->pack($prior));
        $hypotheses = array_column($diagnosis['hypotheses'], null, 'hypothesis_id');
        self::assertSame('下降', $diagnosis['observations'][0]['direction']);
        self::assertSame('plausible', $hypotheses['ctrip.visibility']['evidence_strength']);
        self::assertSame('insufficient', $hypotheses['ctrip.price']['evidence_strength']);
        self::assertSame([], $hypotheses['ctrip.price']['supporting_fact_ids']);
        foreach ($diagnosis['hypotheses'] as $item) self::assertSame('not_established', $item['causality']);
        self::assertContains('订单转化、入住和价格变动时间线', $hypotheses['ctrip.price']['unknowns']);
        self::assertSame('ready_for_task_proposal', $diagnosis['recommendations'][0]['handoff_status']);
        self::assertFalse($diagnosis['recommendations'][0]['auto_execute']);
        self::assertSame('2026-09-09', $diagnosis['recommendations'][0]['review_window']['followup_start']);
    }

    public function testStableConversionAndOrdersAreExplicitCounterEvidence(): void
    {
        $prior = AiEvidenceFixture::closure('2026-09-07');
        $prior['platforms']['ctrip']['fields']['exposure']['value'] = 200;
        $diagnosis = (new AiDailyReportEvidenceService())->diagnose($this->pack(), $this->pack($prior));
        $funnel = array_column($diagnosis['hypotheses'], null, 'hypothesis_id')['ctrip.funnel'];
        self::assertSame(['ctrip.conversion', 'ctrip.order_count'], $funnel['counter_fact_ids']);
        self::assertSame('ctrip.demand', $diagnosis['hypotheses'][0]['hypothesis_id']);
    }

    public function testConflictingAndInvalidUnitsStayMissingInsteadOfZero(): void
    {
        $closure = AiEvidenceFixture::closure();
        $closure['platforms']['ctrip']['fields']['exposure']['status'] = 'conflict';
        $closure['platforms']['ctrip']['fields']['conversion']['unit'] = 'ratio';
        $closure['platforms']['meituan']['fields']['conversion']['value'] = 101;
        $pack = $this->pack($closure);
        self::assertCount(17, $pack['facts']);
        self::assertSame(['conflict', 'unit_unverified', 'invalid_value'], array_column($pack['gaps'], 'status'));
        self::assertSame([null, null, null], array_column($pack['gaps'], 'value'));
        $diagnosis = (new AiDailyReportEvidenceService())->diagnose($pack);
        self::assertSame('resolve_evidence_conflict', $diagnosis['recommendations'][0]['action_type']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identityMutations')]
    public function testScopeAndInvalidReferencesAreRejectedBeforeSave(string $case): void
    {
        $closure = AiEvidenceFixture::closure();
        match ($case) {
            'hotel' => $closure['hotel_id'] = 905,
            'tenant' => $closure['tenant_id'] = 1,
            'date' => $closure['business_date'] = '2026-09-09',
            'platform' => $closure['platforms']['ctrip']['platform'] = 'meituan',
            'field_date' => $closure['platforms']['ctrip']['fields']['exposure']['data_date'] = '2026-09-07',
            'reference' => $closure['platforms']['ctrip']['fields']['exposure']['source_record_refs'] = ['online_daily_data#999'],
        };
        $service = new AiDailyReportEvidenceService();
        $this->expectException(\RuntimeException::class);
        $service->factPack($closure, $service->scope(9004, 904, '2026-09-08'));
    }
    public static function identityMutations(): array { return array_map(static fn($x) => [$x], ['hotel', 'tenant', 'date', 'platform', 'field_date', 'reference']); }

    #[\PHPUnit\Framework\Attributes\DataProvider('modelMutations')]
    public function testModelCannotSwapNumbersUnitsReferencesOrScope(string $case): void
    {
        $service = new AiDailyReportEvidenceService(); $pack = $this->pack(); $diagnosis = $service->diagnose($pack);
        $claim = $pack['facts'][0];
        $output = ['scope' => $pack['scope'], 'facts_fingerprint' => $pack['fingerprint'], 'claims' => [$claim], 'hypothesis_ids' => ['ctrip.visibility']];
        match ($case) {
            'value' => $output['claims'][0]['value'] = 100, // Valid exposure/ADR number, wrong revenue binding.
            'unit' => $output['claims'][0]['unit'] = 'people',
            'ref' => $output['claims'][0]['source_refs'] = ['online_daily_data#90402'],
            'metric' => $output['claims'][0]['metric_key'] = 'exposure',
            'scope' => $output['scope']['hotel_id'] = 905,
            'fingerprint' => $output['facts_fingerprint'] = str_repeat('f', 64),
            'hypothesis' => $output['hypothesis_ids'] = ['price_is_cause'],
        };
        $this->expectException(\RuntimeException::class);
        $service->validateModel($output, $pack, $diagnosis);
    }
    public static function modelMutations(): array { return array_map(static fn($x) => [$x], ['value', 'unit', 'ref', 'metric', 'scope', 'fingerprint', 'hypothesis']); }

    public function testModelTextNeverEntersStoredFactsAndKnowledgeStaysReferenceOnly(): void
    {
        $service = new AiDailyReportEvidenceService(); $pack = $this->pack(); $diagnosis = $service->diagnose($pack);
        $model = $service->validateModel(['scope' => $pack['scope'], 'facts_fingerprint' => $pack['fingerprint'],
            'claims' => [$pack['facts'][0]], 'hypothesis_ids' => ['ctrip.visibility'], 'summary' => '伪造收入999999'], $pack, $diagnosis);
        $snapshot = $service->seal($pack, $diagnosis, $model, ['hotel_id' => 904, 'entries' => [
            ['chunk_id' => 1, 'unit_hotel_id' => 904, 'truth_profile_version' => 'v1', 'content' => ['revenue' => 999999]],
            ['chunk_id' => 2, 'unit_hotel_id' => 905, 'truth_profile_version' => 'v1'],
            ['chunk_id' => 3, 'unit_hotel_id' => 904, 'truth_profile_version' => 'v1', 'applicability' => ['reference_safe' => false]],
        ]]);
        self::assertCount(1, $snapshot['knowledge_references']);
        self::assertFalse($snapshot['knowledge_references'][0]['hotel_fact']);
        self::assertStringNotContainsString('999999', $snapshot['final_text']);
    }

    public function testTamperedSnapshotAndCrossScopeRevenueConsumptionFail(): void
    {
        $service = new AiDailyReportEvidenceService(); $pack = $this->pack();
        $snapshot = $service->seal($pack, $service->diagnose($pack), ['status' => 'not_requested']);
        $snapshot['diagnosis']['hypotheses'][0]['title'] = 'tampered';
        try { $service->verify($snapshot, $pack['scope']); self::fail('tamper accepted'); }
        catch (\RuntimeException $e) { self::assertSame('diagnosis_snapshot_integrity_failed', $e->getMessage()); }
        $this->expectExceptionMessage('revenue_diagnosis_scope_mismatch');
        (new RevenueAnalysisDiagnosticsService())->evidenceReasoning(['hotel' => ['tenant_id' => 9004, 'system_hotel_id' => 905],
            'business_date' => '2026-09-08', 'evidence_fact_pack' => $pack]);
    }

    public function testOverviewRejectsReturnedPackOutsideRequestedHotelOrPlatform(): void
    {
        foreach ([['hotel_id' => 905], ['hotel_id' => 904, 'enabled_channels' => ['ctrip']]] as $request) {
            try {
                (new \app\service\RevenueAiOverviewService())->buildOverviewFromDataset([], [], [], array_merge($request,
                    ['business_date' => '2026-09-08', 'revenue_fact_layer' => ['hotel' => ['tenant_id' => 9004, 'system_hotel_id' => 904],
                        'business_date' => '2026-09-08', 'evidence_fact_pack' => $this->pack()]]));
                self::fail('returned pack was not request scoped');
            } catch (\RuntimeException $error) { self::assertSame('revenue_diagnosis_request_scope_mismatch', $error->getMessage()); }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modelFailures')]
    public function testModelFailureLeavesExplicitState(string $error, string $expected): void
    {
        $client = $this->createMock(LlmClient::class);
        $client->method('createJsonResponse')->willThrowException(new \RuntimeException($error));
        $service = new AiDailyReportService(llmClient: $client);
        $pack = $this->pack();
        $method = new \ReflectionMethod($service, 'tryEvidenceModel');
        $result = $method->invoke($service, ['evidence_fact_pack' => $pack,
            'evidence_diagnosis' => (new AiDailyReportEvidenceService())->diagnose($pack)], 'synthetic-model');
        self::assertSame($expected, $result['model_status']);
        self::assertNull($result['report']);
        self::assertStringNotContainsString('secret', $result['model_message']);
    }
    public static function modelFailures(): array { return [['timeout secret', 'timeout'], ['API key not configured secret', 'not_configured'], ['provider unavailable secret', 'failed']]; }
}
