<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OperatingQuestionPreciseQueryService;
use app\service\PreciseQueryLexicon;
use app\service\SemanticGlossaryService;
use app\service\SemanticGlossarySyncService;
use app\service\SystemUsageAssistantService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SemanticGlossaryServiceTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function representativeCases(): iterable
    {
        $path = __DIR__ . '/fixtures/semantic_glossary_acceptance_cases.json';
        $cases = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($cases);
        self::assertGreaterThanOrEqual(50, count($cases));
        foreach ($cases as $index => $case) {
            self::assertIsArray($case);
            yield sprintf('%02d_%s', $index + 1, (string)($case['query'] ?? 'case')) => [$case];
        }
    }

    /** @param array<string,mixed> $case */
    #[DataProvider('representativeCases')]
    public function testRepresentativeTermResolution(array $case): void
    {
        $result = (new SemanticGlossaryService())->resolve(
            (string)$case['query'],
            (string)($case['platform'] ?? '')
        );

        self::assertSame($case['status'], $result['status']);
        self::assertFalse($result['decision_safe']);
        self::assertFalse($result['external_write_authorized']);
        if (isset($case['candidate_count'])) {
            self::assertCount((int)$case['candidate_count'], $result['candidates']);
            self::assertNull($result['primary']);
            return;
        }
        if ($case['status'] !== 'matched') {
            return;
        }
        self::assertIsArray($result['primary']);
        self::assertSame($case['canonical'], $result['primary']['canonical_term']);
        self::assertSame($case['category'], $result['primary']['category']);
        self::assertSame($case['personal'], $result['primary']['is_personal']);
        self::assertSame($case['metric_key'], $result['primary']['metric_key']);
        self::assertSame($case['route_key'], $result['primary']['route_key']);
        self::assertSame(
            'e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53',
            $result['primary']['source_fingerprint']
        );
        self::assertFalse($result['primary']['risk_boundary']['decision_safe']);
        self::assertFalse($result['primary']['risk_boundary']['external_write_authorized']);
        self::assertSame('data_only_never_execute', $result['primary']['risk_boundary']['content_execution_policy']);
    }

    public function testPackAndExportMetadataAreTraceableAndComplete(): void
    {
        $metadata = (new SemanticGlossaryService())->metadata();
        self::assertSame('available', $metadata['status']);
        self::assertSame('2026-08-26.3', $metadata['glossary_version']);
        self::assertSame(2990, $metadata['source_term_count']);
        self::assertSame(3002, $metadata['recognition_term_count']);
        self::assertSame(2927, $metadata['concept_count']);
        self::assertSame(
            'e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53',
            $metadata['source_sha256']
        );
        self::assertSame(
            ['personal_common', 'suxios_system', 'ota_ctrip', 'ota_meituan', 'hotel_industry', 'metric_alias', 'reference_only'],
            array_keys($metadata['category_counts'])
        );
        self::assertFalse($metadata['boundary']['decision_safe']);
        self::assertFalse($metadata['boundary']['external_write_authorized']);

        $validated = (new SemanticGlossarySyncService())->sync(false);
        self::assertSame('validated', $validated['status']);
        self::assertFalse($validated['persisted']);
        self::assertSame(3000, $validated['export_term_count']);
        self::assertSame(0, $validated['exact_duplicate_count']);
        self::assertSame(0, $validated['failed_entry_count']);
        self::assertSame(118, $validated['batch_count']);
        self::assertSame($metadata['pack_sha256'], $validated['pack_sha256']);
    }

    public function testPlatformScopedExposureUsesOnlyMappedStrictFacts(): void
    {
        $glossary = new SemanticGlossaryService();
        $resolution = $glossary->resolve('曝光是多少', 'meituan');
        $facts = [[
            'ref' => 'online_daily_data#101',
            'data_date' => '2026-08-25',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['list_exposure' => 1422],
            'metric_units' => ['list_exposure' => 'exposure_count'],
        ], [
            'ref' => 'online_daily_data#102',
            'data_date' => '2026-08-25',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['list_exposure' => 9999],
        ]];

        $result = $glossary->metricReadback($resolution, $facts);
        self::assertSame('readback_verified', $result['status']);
        self::assertSame(1422, $result['values'][0]['value']);
        self::assertSame(['online_daily_data#101'], $result['used_evidence_refs']);
        self::assertFalse($result['external_write_authorized']);
    }

    public function testCtripExposureDoesNotReuseSameNamedUnverifiedPlatformField(): void
    {
        $glossary = new SemanticGlossaryService();
        $resolution = $glossary->resolve('曝光量是多少', 'ctrip');
        $result = $glossary->metricReadback($resolution, [[
            'ref' => 'online_daily_data#201',
            'data_date' => '2026-08-25',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['list_exposure' => 8800],
        ]]);

        self::assertSame('blocked_by_source_contract', $result['status']);
        self::assertSame([], $result['values']);
        self::assertSame([], $result['used_evidence_refs']);
    }

    public function testAdrRequiresRoomRevenueAndRoomNightsInSameStrictFact(): void
    {
        $glossary = new SemanticGlossaryService();
        $resolution = $glossary->resolve('平均每日房价是多少', 'ctrip');
        $ready = $glossary->metricReadback($resolution, [[
            'ref' => 'online_daily_data#301',
            'data_date' => '2026-08-25',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['room_revenue' => 1200.0, 'quantity' => 8],
        ]]);
        self::assertSame('calculated_from_same_fact_scope', $ready['status']);
        self::assertSame(150.0, $ready['values'][0]['value']);
        self::assertSame(['room_revenue' => 1200.0, 'room_nights' => 8.0], $ready['values'][0]['inputs']);

        $amountOnly = $glossary->metricReadback($resolution, [[
            'ref' => 'online_daily_data#302',
            'data_date' => '2026-08-25',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['amount' => 1200.0, 'quantity' => 8],
        ]]);
        self::assertSame('not_computable', $amountOnly['status']);
        self::assertSame([], $amountOnly['values']);

        $zeroRoomNights = $glossary->metricReadback($resolution, [[
            'ref' => 'online_daily_data#303',
            'data_date' => '2026-08-25',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'metric_values' => ['room_revenue' => 1200.0, 'quantity' => 0],
        ]]);
        self::assertSame('not_computable', $zeroRoomNights['status']);
        self::assertSame([], $zeroRoomNights['values']);
    }

    public function testPreciseQuestionFinalizerReturnsServerOwnedRouteAndNoWriteAuthority(): void
    {
        $service = new OperatingQuestionPreciseQueryService();
        $result = $service->finalize([
            'question' => '美团曝光是多少',
            'scope' => [
                'tenant_id' => 1,
                'hotel_id' => 80,
                'platform' => 'meituan',
                'date_start' => '2026-08-25',
                'date_end' => '2026-08-25',
                'source_scope' => 'ota_channel',
            ],
            'facts' => [[
                'ref' => 'online_daily_data#401',
                'data_date' => '2026-08-25',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'history_status' => 'success',
                'readback_status' => 'readback_verified',
                'metric_values' => ['list_exposure' => 1422],
            ]],
            'fact_refs' => ['online_daily_data#401'],
        ]);

        self::assertTrue($result['applied']);
        self::assertSame('answered_by_precise_query', $result['status']);
        self::assertSame(OperatingQuestionPreciseQueryService::ROUTER_CONTRACT_VERSION, $result['query_router']['contract_version']);
        self::assertSame('meituan', $result['query_router']['platform']);
        self::assertSame('ota_exposure_volume', $result['query_router']['metric_key']);
        self::assertSame('online-data', $result['query_router']['target_page']);
        self::assertSame(['online_daily_data#401'], $result['used_evidence_refs']);
        self::assertFalse($result['query_router']['external_write_authorized']);

        $general = $service->finalize([
            'question' => 'Openness 是什么意思',
            'scope' => ['platform' => 'ctrip'],
            'facts' => [],
            'fact_refs' => [],
        ]);
        self::assertFalse($general['applied']);
    }

    public function testSystemUsageAssistantUsesSemanticPackForTypelessMaintenance(): void
    {
        $client = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                throw new RuntimeException('offline');
            }
        };
        $result = (new SystemUsageAssistantService($client))->guide([
            'query' => 'Typeless词库在哪里维护',
            'requested_mode' => 'guide',
        ]);

        self::assertSame('fallback', $result['mode']);
        self::assertSame('knowledge-center', $result['action']['target_page']);
        self::assertSame('matched', $result['semantic_resolution']['status']);
        self::assertSame('Typeless', $result['semantic_resolution']['primary']['canonical_term']);
        self::assertFalse($result['semantic_resolution']['primary']['is_business_metric']);
        self::assertFalse($result['semantic_resolution']['external_write_authorized']);
    }

    public function testLegacyPreciseQueryLexiconNowReadsTheFullPack(): void
    {
        $metadata = PreciseQueryLexicon::metadata();
        self::assertSame(2990, $metadata['source_total_terms']);
        self::assertGreaterThan(100, $metadata['runtime_extracted_term_count']);
        self::assertSame('adr', PreciseQueryLexicon::metric('平均每日房价是多少'));
        self::assertSame('typeless-dictionary', PreciseQueryLexicon::systemTopic('Typeless词典在哪里'));
        $definition = PreciseQueryLexicon::referenceDefinition('Openness');
        self::assertIsArray($definition);
        self::assertSame('Openness', $definition['term']);
        self::assertStringContainsString('不是宿析OS酒店经营指标', $definition['definition']);
    }
}
