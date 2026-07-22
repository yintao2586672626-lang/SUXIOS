<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiDailyReportTrustedInputTest extends TestCase
{
    public function testTrustedEvaluatorRequiresHotelDateValidationAndReadbackProof(): void
    {
        $service = new AiDailyReportService();
        $refs = [[
            'key' => 'online_daily_data#42',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_source_id' => 11,
            'data_date' => '2026-07-15',
        ]];
        $row = [
            'id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'data_date' => '2026-07-15',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => 'business_overview',
            'validation_status' => 'available',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-07-16 09:00:00',
        ];

        $trusted = $service->evaluateTrustedOtaRows($refs, [$row], 7, '2026-07-15');
        self::assertTrue($trusted['verified']);
        self::assertSame([], $trusted['gaps']);
        self::assertTrue($trusted['source_refs'][0]['readback_verified']);

        $row['readback_verified'] = 0;
        $untrusted = $service->evaluateTrustedOtaRows($refs, [$row], 7, '2026-07-15');
        self::assertFalse($untrusted['verified']);
        self::assertContains('ota_evidence_readback_unverified', array_column($untrusted['gaps'], 'code'));

        $row['readback_verified'] = 1;
        $crossHotel = $service->evaluateTrustedOtaRows($refs, [$row], 8, '2026-07-15');
        self::assertFalse($crossHotel['verified']);
        self::assertContains('ota_evidence_hotel_scope_mismatch', array_column($crossHotel['gaps'], 'code'));
    }

    public function testTrustedAuxiliaryOnlyEvidenceDoesNotUnlockReportGeneration(): void
    {
        $service = new AiDailyReportService();
        $trusted = $service->evaluateTrustedOtaRows([[
            'key' => 'online_daily_data#42',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_source_id' => 11,
            'data_date' => '2026-07-15',
        ]], [[
            'id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'data_date' => '2026-07-15',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => 'traffic',
            'validation_status' => 'available',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-07-16 09:00:00',
        ]], 7, '2026-07-15');

        self::assertFalse($trusted['verified']);
        self::assertContains('ota_core_business_metrics_missing', array_column($trusted['gaps'], 'code'));
    }

    public function testTrustedEvaluatorRejectsRowsWithoutDataSourceBinding(): void
    {
        $service = new AiDailyReportService();
        $trusted = $service->evaluateTrustedOtaRows([[
            'key' => 'online_daily_data#42',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_date' => '2026-07-15',
        ]], [[
            'id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 0,
            'data_date' => '2026-07-15',
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => 'traffic',
            'validation_status' => 'available',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-07-16 09:00:00',
        ]], 7, '2026-07-15');

        self::assertFalse($trusted['verified']);
        self::assertContains('ota_evidence_data_source_binding_missing', array_column($trusted['gaps'], 'code'));
        self::assertSame(0, $trusted['source_refs'][0]['data_source_id']);
        self::assertSame([], $trusted['rows']);
    }

    public function testUnverifiedInputStopsBeforeTheFakeLlm(): void
    {
        $fake = new TrustedInputCountingLlmClient();
        $service = new AiDailyReportService(null, $fake);
        $enhance = new ReflectionMethod($service, 'tryEnhanceWithLlm');
        $enhance->setAccessible(true);

        $result = $enhance->invoke($service, [
            'summary' => 'Rule report only.',
            'report_scope' => ['hotel_id' => 7, 'report_date' => '2026-07-15'],
        ], [
            'input_trust' => ['readback_verified' => false],
            'scope' => [
                'report_date' => '2026-07-15',
                'source_data_date' => '2026-07-15',
                'source_data_dates' => ['2026-07-15'],
                'source_freshness_status' => 'fresh',
            ],
            'operation' => [
                'summary' => ['data_status' => 'ok', 'revenue' => 120],
                'ota' => ['data_status' => 'ok', 'orders' => 3],
            ],
        ], 'local_fake');

        self::assertSame(0, $fake->callCount);
        self::assertSame('blocked_by_data_quality', $result['model_status']);
        self::assertNull($result['report']);
    }

    public function testUnverifiedInputIsMergedIntoRuleGapsAndBlocksEveryExecutionAction(): void
    {
        $service = new AiDailyReportService();
        $build = new ReflectionMethod($service, 'buildRuleReport');
        $build->setAccessible(true);

        $base = [
            'operation' => [],
            'root_cause' => [
                'root_causes' => [[
                    'code' => 'conversion_drop',
                    'title' => '曝光转化下降',
                    'suggestion' => '检查内容和活动入口',
                    'evidence' => 'conversion decreased',
                ]],
            ],
            'execution_flow' => [],
        ];
        $trusted = $build->invoke($service, $base + [
            'input_trust' => [
                'readback_verified' => true,
                'data_gaps' => [],
            ],
        ], '2026-07-15', 7);
        $report = $build->invoke($service, $base + [
            'input_trust' => [
                'readback_verified' => false,
                'data_gaps' => [[
                    'code' => 'ota_evidence_readback_unverified',
                    'message' => 'OTA evidence has not passed durable readback verification.',
                    'source_ref' => 'online_daily_data.readback_verified',
                ]],
            ],
        ], '2026-07-15', 7);

        self::assertContains('ota_evidence_readback_unverified', array_column($report['data_gaps'], 'code'));
        self::assertNotEmpty($report['recommended_actions']);
        foreach ($report['recommended_actions'] as $action) {
            self::assertFalse($action['can_create_execution_intent']);
            self::assertNotSame('', trim((string)$action['blocked_reason']));
        }
        $trustedByType = array_column($trusted['recommended_actions'], null, 'action_type');
        $untrustedByType = array_column($report['recommended_actions'], null, 'action_type');
        self::assertTrue($trustedByType['promotion']['can_create_execution_intent']);
        self::assertFalse($untrustedByType['promotion']['can_create_execution_intent']);
        self::assertNotSame('', trim((string)$untrustedByType['promotion']['blocked_reason']));

        self::assertFalse(AiDailyReportService::isTrustedSnapshotForExecution([]));
        self::assertFalse(AiDailyReportService::isTrustedSnapshotForExecution([
            'input_trust' => ['readback_verified' => false],
        ]));
        self::assertTrue(AiDailyReportService::isTrustedSnapshotForExecution([
            'input_trust' => ['readback_verified' => true],
        ]));
    }

    public function testFingerprintIgnoresKeyAndReadbackTimeChangesButNotMetricChanges(): void
    {
        $left = [
            'hotel_id' => 7,
            'updated_at' => '2026-07-16 09:00:00',
            'facts' => [
                'orders' => 3,
                'revenue' => 120.0,
                'readback_verified_at' => '2026-07-16 09:00:01',
                'request_id' => 'request-a',
            ],
        ];
        $right = [
            'facts' => [
                'request_id' => 'request-b',
                'readback_verified_at' => '2026-07-16 10:30:00',
                'revenue' => 120.0,
                'orders' => 3,
            ],
            'updated_at' => '2026-07-16 10:30:00',
            'hotel_id' => 7,
        ];

        self::assertSame(
            AiDailyReportService::canonicalInputFingerprint($left),
            AiDailyReportService::canonicalInputFingerprint($right)
        );
        $right['facts']['orders'] = 4;
        self::assertNotSame(
            AiDailyReportService::canonicalInputFingerprint($left),
            AiDailyReportService::canonicalInputFingerprint($right)
        );
    }

    public function testModelTextIsExplanationOnlyAndCannotReplaceRuleFacts(): void
    {
        $service = new AiDailyReportService();
        $merge = new ReflectionMethod($service, 'mergeLlmReport');
        $merge->setAccessible(true);
        $rule = [
            'summary' => 'Rule revenue is 120.',
            'abnormal_metrics' => [],
            'competitor_changes' => [],
            'recommended_actions' => [],
            'data_gaps' => [],
            'source_refs' => [],
        ];

        $merged = $merge->invoke($service, $rule, [
            'summary' => 'Revenue remained stable.',
            'abnormal_metrics' => [['key' => 'invented']],
        ]);
        self::assertSame('Rule revenue is 120.', $merged['summary']);
        self::assertSame([], $merged['abnormal_metrics']);
        self::assertSame('Revenue remained stable.', $merged['ai_explanation']);

        $rejected = $merge->invoke($service, $rule, ['summary' => 'Revenue rose to 999.']);
        self::assertArrayNotHasKey('ai_explanation', $rejected);
    }

    public function testOnlyValidModelOutcomesAreEligibleForTheIndependentInputCache(): void
    {
        self::assertTrue(AiDailyReportService::isCacheableModelResult(true, 'ok', 'Bounded explanation.'));
        self::assertTrue(AiDailyReportService::isCacheableModelResult(false, 'not_requested', ''));
        foreach (['failed', 'invalid_output', 'blocked_by_data_quality'] as $status) {
            self::assertFalse(AiDailyReportService::isCacheableModelResult(true, $status, 'Unsafe result.'));
        }
        self::assertFalse(AiDailyReportService::isCacheableModelResult(true, 'ok', ''));
    }

    public function testReusableCachePreservesTheCompleteValidatedInterpretationAndReadsLegacyRows(): void
    {
        $service = new AiDailyReportService();
        $readCache = new ReflectionMethod($service, 'cachedAiInterpretation');
        $readCache->setAccessible(true);

        $complete = $readCache->invoke($service, [
            'model_status' => 'ok',
            'ai_explanation' => 'Legacy mirror text.',
            'ai_interpretation_json' => json_encode([
                'status' => 'available',
                'possible_explanations' => ['Traffic quality may have changed.'],
                'conflicting_evidence' => ['Views are stable while orders declined.'],
                'missing_information' => ['Campaign placement detail is unavailable.'],
                'confidence' => 'medium',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        self::assertSame(['Traffic quality may have changed.'], $complete['possible_explanations']);
        self::assertSame(['Views are stable while orders declined.'], $complete['conflicting_evidence']);
        self::assertSame(['Campaign placement detail is unavailable.'], $complete['missing_information']);
        self::assertSame('medium', $complete['confidence']);

        $legacy = $readCache->invoke($service, [
            'model_status' => 'ok',
            'ai_explanation' => 'Legacy explanation remains readable.',
        ]);
        self::assertSame('legacy_cache_compatible', $legacy['status']);
        self::assertSame(['Legacy explanation remains readable.'], $legacy['possible_explanations']);
        self::assertSame([], $legacy['conflicting_evidence']);
        self::assertSame([], $legacy['missing_information']);
        self::assertSame('not_assessed', $legacy['confidence']);
    }

    public function testModelPayloadContainsOnlyVerifiedOtaFactsAndBlocksIncompleteEvidence(): void
    {
        $service = new AiDailyReportService();
        $buildPayload = new ReflectionMethod($service, 'buildTrustedLlmPayload');
        $buildPayload->setAccessible(true);
        $readiness = new ReflectionMethod($service, 'llmSnapshotReadinessBlock');
        $readiness->setAccessible(true);

        $ruleReport = [
            'summary' => 'UNSAFE_RULE_SUMMARY',
            'competitor_changes' => [['note' => 'UNSAFE_RULE_COMPETITOR']],
            'recommended_actions' => [['title' => 'UNSAFE_RULE_ACTION']],
            'data_gaps' => [
                ['code' => 'ota_missing_detail', 'message' => 'OTA detail is missing.', 'source_ref' => 'online_daily_data#42'],
                ['code' => 'whole_hotel_gap', 'message' => 'UNSAFE_RULE_GAP', 'source_ref' => 'hotel_daily_data'],
            ],
        ];
        $snapshot = [
            'scope' => ['hotel_id' => 7, 'report_date' => '2026-07-15'],
            'input_trust' => ['readback_verified' => true],
            'source_refs' => [[
                'key' => 'online_daily_data#42',
                'label' => 'Ctrip OTA fact',
                'scope' => 'Ctrip OTA channel fact',
                'source' => 'ctrip',
                'platform' => 'Ctrip',
                'data_source_id' => 11,
                'data_date' => '2026-07-15',
                'validation_status' => 'available',
                'readback_verified' => true,
                'metric_keys' => ['orders', 'views'],
            ]],
            'operation' => [
                'ota' => [
                    'data_status' => 'ok',
                    'orders' => 3,
                    'views' => 120,
                    'evidence_refs' => [['source_ref' => 'online_daily_data#42']],
                ],
                'summary' => ['data_status' => 'ok', 'revenue' => 999999, 'note' => 'UNSAFE_WHOLE_HOTEL'],
                'competitors' => [['name' => 'UNSAFE_COMPETITOR']],
            ],
            'root_cause' => ['summary' => 'UNSAFE_ROOT_CAUSE'],
            'execution_flow' => [['result' => 'UNSAFE_EXECUTION']],
        ];

        $payload = $buildPayload->invoke($service, $ruleReport, $snapshot);
        self::assertTrue($payload['evidence_complete']);
        self::assertSame(3, $payload['verified_ota_facts']['orders']);
        self::assertSame('online_daily_data#42', $payload['verified_source_refs'][0]['key']);
        self::assertSame('', $readiness->invoke($service, $snapshot, $payload));

        $encoded = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([
            'UNSAFE_RULE_SUMMARY', 'UNSAFE_RULE_COMPETITOR', 'UNSAFE_RULE_ACTION', 'UNSAFE_RULE_GAP',
            'UNSAFE_WHOLE_HOTEL', 'UNSAFE_COMPETITOR', 'UNSAFE_ROOT_CAUSE', 'UNSAFE_EXECUTION', '999999',
        ] as $unsafeValue) {
            self::assertStringNotContainsString($unsafeValue, $encoded);
        }

        $snapshot['source_refs'][0]['readback_verified'] = false;
        $untrustedPayload = $buildPayload->invoke($service, $ruleReport, $snapshot);
        self::assertFalse($untrustedPayload['evidence_complete']);
        self::assertNotSame('', $readiness->invoke($service, $snapshot, $untrustedPayload));
    }

    public function testModelAndModeVariantsKeepIndependentReusableFingerprints(): void
    {
        $base = ['hotel_id' => 7, 'report_date' => '2026-07-15', 'facts' => ['orders' => 3]];
        $modelA = AiDailyReportService::canonicalInputFingerprint($base + ['model_key' => 'model-a', 'use_llm' => true]);
        $modelB = AiDailyReportService::canonicalInputFingerprint($base + ['model_key' => 'model-b', 'use_llm' => true]);
        $ruleOnly = AiDailyReportService::canonicalInputFingerprint($base + ['model_key' => 'model-a', 'use_llm' => false]);
        $modelAAgain = AiDailyReportService::canonicalInputFingerprint($base + ['use_llm' => true, 'model_key' => 'model-a']);

        self::assertSame($modelA, $modelAAgain);
        self::assertNotSame($modelA, $modelB);
        self::assertNotSame($modelA, $ruleOnly);
    }

    public function testReportTenantIsResolvedFromTheHotelRecord(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../app/service/AiDailyReportService.php');
        self::assertStringContainsString("Db::name('hotels')->where('id', \$hotelId)->value('tenant_id')", $source);
        self::assertStringNotContainsString("\$data['tenant_id'] = \$hotelId", $source);
    }

    public function testTrustedOtaSnapshotDoesNotUpgradeAnUnverifiedDailyReportSource(): void
    {
        $service = new AiDailyReportService();
        $normalize = new ReflectionMethod($service, 'normalizeReportRow');
        $normalize->setAccessible(true);

        $result = $normalize->invoke($service, [
            'id' => 11,
            'hotel_id' => 7,
            'report_date' => '2026-07-15',
            'summary' => 'Fixture report with mixed source scopes.',
            'model_status' => 'not_requested',
            'yesterday_result_json' => '{}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => json_encode([[
                'title' => '复核携程标准房价',
                'action' => '复核携程标准房在目标日期的房价，并由人工确认是否调整。',
            ]], JSON_UNESCAPED_UNICODE),
            'source_refs_json' => json_encode([
                [
                    'key' => 'online_daily_data#42',
                    'source' => 'ctrip',
                    'system_hotel_id' => 7,
                    'platform' => 'Ctrip',
                    'scope' => 'ota_channel',
                    'data_date' => '2026-07-15',
                    'validation_status' => 'available',
                    'readback_verified' => true,
                    'readback_verified_at' => '2026-07-16 09:00:00',
                ],
                [
                    'key' => 'daily_reports#7',
                    'source' => 'daily_reports',
                    'scope' => 'whole_hotel_daily_report',
                    'data_date' => '2026-07-15',
                    'validation_status' => 'recorded',
                    'ingestion_method' => 'daily_report',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'snapshot_json' => json_encode([
                'input_trust' => ['readback_verified' => true],
                'report_scope' => ['hotel_id' => 7, 'report_date' => '2026-07-15'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $refsBySource = array_column(
            $result['recommended_actions'][0]['data_basis']['refs'],
            null,
            'source'
        );
        self::assertSame('ota_channel', $refsBySource['ctrip']['scope']);
        self::assertContains($refsBySource['ctrip']['quality_status'], ['verified', 'readback_verified']);
        self::assertArrayNotHasKey('daily_reports', $refsBySource);
        self::assertSame('verified', $result['recommended_actions'][0]['data_basis']['status']);
        self::assertStringContainsString('daily_reports#7', (string)($result['source_refs'][1]['key'] ?? ''));
    }

    public function testYesterdayMetricsPreserveUpstreamMetricScopes(): void
    {
        $service = new AiDailyReportService();
        $collect = new ReflectionMethod($service, 'collectYesterdayResult');
        $collect->setAccessible(true);

        $result = $collect->invoke($service, [
            'revenue' => 1200,
            'orders' => 8,
            'room_nights' => 6,
            'adr' => 200,
            'source_scope' => 'mixed_whole_hotel_and_ota_channel',
            'metric_scopes' => [
                'revenue' => ['whole_hotel_daily_report'],
                'orders' => ['whole_hotel_daily_report', 'ota_channel'],
                'room_nights' => ['whole_hotel_daily_report'],
            ],
        ], [
            'exposure' => 500,
            'visitors' => 80,
        ], '2026-07-15');

        self::assertSame('mixed_whole_hotel_and_ota_channel', $result['source_scope']);
        self::assertSame(
            ['whole_hotel_daily_report', 'ota_channel'],
            $result['metric_scopes']['orders']
        );
        $metrics = array_column($result['metrics'], null, 'key');
        self::assertSame('mixed_whole_hotel_and_ota_channel', $metrics['orders']['metric_scope']);
        self::assertSame(['whole_hotel_daily_report', 'ota_channel'], $metrics['orders']['metric_scopes']);
        self::assertSame('ota_channel', $metrics['exposure']['metric_scope']);
        self::assertSame(['ota_channel'], $metrics['exposure']['metric_scopes']);
    }
}

final class TrustedInputCountingLlmClient extends LlmClient
{
    public int $callCount = 0;

    public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
    {
        $this->callCount++;
        return ['summary' => 'This fake must never be called.'];
    }
}
