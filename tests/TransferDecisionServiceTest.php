<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OnlineDataFieldFactService;
use app\service\TransferDecisionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class TransferDecisionServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testCalculateAssetPricingReturnsProfitValuationAndRiskEnvelope(): void
    {
        $result = $this->fallbackService()->calculateAssetPricing([
            'hotel_name' => '虹桥样板店',
            'location' => '上海虹桥',
            'room_count' => 80,
            'monthly_revenue' => 30,
            'monthly_rent' => 8,
            'labor_cost' => 5,
            'utility_cost' => 1,
            'ota_commission' => 2,
            'other_fixed_cost' => 1,
            'decoration_investment' => 200,
            'remaining_lease_months' => 72,
            'expected_transfer_price' => 180,
            'occupancy_rate' => 82,
            'adr' => 320,
            'rating' => 4.8,
            'order_count' => 900,
            'licenses_complete' => true,
        ]);

        self::assertSame(80, $result['basic_info']['room_count']);
        self::assertSame(17.0, $result['costs']['monthly_total_cost']);
        self::assertSame(13.0, $result['profit']['monthly_net_profit']);
        self::assertIsFloat($result['valuation']['reasonable_valuation']);
        self::assertNotSame('', $result['risk_level']);
        self::assertSame('万元', $result['unit']);
    }

    public function testSaveRecordUsesAuthoritativeHotelTenantWithoutNumericFallback(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/TransferDecisionService.php');

        self::assertStringContainsString("Db::name('hotels')->where('id', \$hotelId)->value('tenant_id')", $source);
        self::assertStringContainsString("'tenant_id' => \$tenantId", $source);
        self::assertStringNotContainsString("'tenant_id' => \$hotelId", $source);
    }

    public function testSourceReadsFailClosedInsteadOfMasqueradingAsEmptyBusinessData(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/TransferDecisionService.php');

        self::assertStringContainsString("transfer_source_schema_check_failed:' . \$table", $source);
        self::assertStringContainsString('transfer_source_read_failed:daily_reports', $source);
        self::assertStringContainsString('transfer_source_read_failed:online_daily_data', $source);
        self::assertStringContainsString('transfer_source_read_failed:hotels', $source);
        self::assertStringContainsString("'source_read_status' => \$this->sourceReadStatus", $source);
        self::assertStringContainsString("'source_table_missing:' . \$table", $source);
    }

    public function testCalculateAssetPricingAddsFallbackAiEvaluation(): void
    {
        $service = new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });

        $result = $service->calculateAssetPricing($this->pricingInput());

        self::assertSame('fallback', $result['ai_evaluation']['source']);
        self::assertNotEmpty($result['ai_evaluation']['summary']);
        self::assertNotEmpty($result['ai_evaluation']['recommendations']);
        self::assertNotEmpty($result['ai_evaluation']['watch_points']);
    }

    public function testCalculateAssetPricingCanRequireRealAiEvaluation(): void
    {
        $service = new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI模型调用失败');

        $service->calculateAssetPricing(array_merge($this->pricingInput(), [
            'require_ai_evaluation' => true,
            'model_key' => 'openai_fast',
        ]));
    }

    public function testCalculateAssetPricingUsesLlmAiEvaluationWhenAvailable(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'summary' => '报价可进入复核，但需先确认真实流水和租约。',
                    'decision' => '谨慎接盘，先完成尽调。',
                    'recommendations' => [
                        ['priority' => 'P0', 'title' => '核验流水', 'detail' => '核验近90天OTA订单、日报流水和银行收款。'],
                    ],
                    'watch_points' => [
                        ['metric' => '转让报价', 'threshold' => '不高于合理估值', 'action' => '超出区间则重新谈价。'],
                    ],
                    'assumptions' => ['未读取线下租约原件。'],
                ];
            }
        };

        $result = (new TransferDecisionService($client))->calculateAssetPricing(array_merge($this->pricingInput(), [
            'model_key' => 'deepseek_chat',
        ]));

        self::assertSame('llm', $result['ai_evaluation']['source']);
        self::assertSame('deepseek_chat', $result['ai_evaluation']['model_key']);
        self::assertSame('核验流水', $result['ai_evaluation']['recommendations'][0]['title']);
        self::assertStringContainsString('pricing_result', (string)($client->messages[1]['content'] ?? ''));
    }

    public function testCalculateAssetPricingUsesDecorationValuationWhenProfitIsNegative(): void
    {
        $result = $this->fallbackService()->calculateAssetPricing([
            'room_count' => 30,
            'monthly_revenue' => 8,
            'monthly_rent' => 10,
            'labor_cost' => 4,
            'utility_cost' => 1,
            'ota_commission' => 1,
            'other_fixed_cost' => 1,
            'decoration_investment' => 100,
            'remaining_lease_months' => 18,
            'expected_transfer_price' => 120,
            'occupancy_rate' => 45,
            'rating' => 4.3,
            'licenses_complete' => false,
        ]);

        self::assertSame(-9.0, $result['profit']['monthly_net_profit']);
        self::assertNull($result['profit']['payback_months']);
        self::assertSame(15.0, $result['valuation']['conservative_valuation']);
        self::assertSame(25.0, $result['valuation']['reasonable_valuation']);
        self::assertSame(35.0, $result['valuation']['optimistic_valuation']);
    }

    public function testCalculateAssetPricingRejectsInvalidRoomCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TransferDecisionService())->calculateAssetPricing(['room_count' => 0]);
    }

    public function testCalculateAssetPricingKeepsMissingCoreFieldNullAndSkipsValuation(): void
    {
        $input = $this->pricingInput();
        unset($input['monthly_revenue']);

        $result = $this->fallbackService()->calculateAssetPricing($input);

        self::assertSame('insufficient_data', $result['status']);
        self::assertContains('月营业额', $result['missing_fields']);
        self::assertNull($result['profit']['monthly_revenue']);
        self::assertNull($result['profit']['monthly_net_profit']);
        self::assertNull($result['valuation']['reasonable_valuation']);
        self::assertArrayNotHasKey('ai_evaluation', $result);
    }

    public function testCalculateTransferTimingDetectsCollectionAnomaly(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'exposure' => 0,
            'visitors' => 0,
            'conversion_rate' => 0,
            'order_count' => 20,
            'room_nights' => 30,
            'rating' => 4.7,
        ]);

        self::assertTrue($result['data_quality']['suspected_collection_anomaly']);
        self::assertTrue($result['data_quality']['has_data_anomaly']);
        self::assertContains('疑似采集异常', $result['risk_points']);
    }

    public function testCalculateTransferTimingDoesNotDefaultMissingTrendsToFlat(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'rating' => 4.8,
            'has_data_anomaly' => false,
            'has_data_gap' => false,
        ]);

        self::assertSame('insufficient_data', $result['status']);
        self::assertNull($result['timing_score']);
        self::assertSame('数据不足，暂无法判断转让时机', $result['decision']);
        self::assertCount(4, $result['missing_fields']);
    }

    public function testCalculateTransferTimingRewardsPositiveWindow(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'revenue_trend' => '上涨',
            'order_trend' => '上涨',
            'adr_trend' => '上涨',
            'occupancy_trend' => '上涨',
            'rating' => 4.9,
            'holiday_days' => 20,
            'is_peak_season' => true,
        ]);

        self::assertGreaterThanOrEqual(80, $result['timing_score']);
        self::assertSame('适合转让', $result['decision']);
        self::assertFalse($result['data_quality']['has_data_anomaly']);
    }

    public function testCalculateTransferTimingComparesCurrentWindowWithAnnualBenchmarkAliases(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'current_revenue' => 120,
            '年度30天营业额' => 100,
            'current_orders' => 620,
            '年度30天订单量' => 520,
            'current_adr' => 320,
            '年度ADR' => 300,
            'current_occupancy_rate' => 82,
            '年度入住率' => 76,
        ]);

        self::assertSame(100, $result['timing_score']);
        self::assertContains('营业额上涨，加15分', $result['main_reasons']);
        self::assertContains('订单上涨，加15分', $result['main_reasons']);
    }

    public function testAnnualBenchmarkScalesRevenueAndOrdersToThirtyDays(): void
    {
        $benchmark = $this->invokeNonPublic(new TransferDecisionService(), 'annualThirtyDayBenchmark', [[
            'actual_days' => 60,
            'revenue' => 600000,
            'orders' => 120,
            'adr' => 300,
            'occupancy_rate' => 75,
        ]]);

        self::assertSame(300000.0, $benchmark['revenue']);
        self::assertSame(60, $benchmark['orders']);
        self::assertSame(300.0, $benchmark['adr']);
        self::assertSame(75.0, $benchmark['occupancy_rate']);
    }

    public function testOtaChannelRevenueIsNotPromotedToWholeHotelRevenue(): void
    {
        $verifiedRow = $this->verifiedOtaRow([
            'amount' => 30000,
            'book_order_num' => 20,
            'quantity' => 25,
        ]);
        $verifiedRaw = json_decode((string)$verifiedRow['raw_data'], true);
        $fieldStatus = OnlineDataFieldFactService::buildStatus($verifiedRow, is_array($verifiedRaw) ? $verifiedRaw : []);
        self::assertSame('ready', $fieldStatus['status'], json_encode($fieldStatus, JSON_UNESCAPED_UNICODE));
        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [
                $verifiedRow,
                [
                    'system_hotel_id' => 7,
                    'platform' => 'ctrip',
                    'source' => 'ctrip',
                    'data_date' => '2026-07-15',
                    'amount' => 999999,
                    'book_order_num' => 999,
                    'quantity' => 999,
                ],
            ],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['revenue']);
        self::assertSame(30000.0, $metrics['ota_channel_revenue'], json_encode($metrics['truth_context'], JSON_UNESCAPED_UNICODE));
        self::assertSame(20, $metrics['ota_channel_orders']);
        self::assertSame(0.0, $metrics['room_nights']);
        self::assertSame(25.0, $metrics['ota_channel_room_nights']);
        self::assertSame('partial', $metrics['truth_context']['status']);
        self::assertSame('ota_channel', $metrics['truth_context']['metric_scope']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
        self::assertSame(1, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['unverified']);
        self::assertStringContainsString('不得互相替代', $metrics['scope_note']);
    }

    public function testVerifiedZeroOtaFactsRemainObservedInsteadOfBecomingMissing(): void
    {
        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$this->verifiedOtaRow([
                'amount' => 0,
                'book_order_num' => 0,
                'quantity' => 0,
            ])],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['ota_channel_revenue']);
        self::assertSame(0, $metrics['ota_channel_orders']);
        self::assertSame(0.0, $metrics['ota_channel_room_nights']);
        self::assertTrue($metrics['ota_channel_revenue_observed']);
        self::assertTrue($metrics['ota_channel_orders_observed']);
        self::assertTrue($metrics['ota_channel_room_nights_observed']);
        self::assertSame(1, $metrics['ota_channel_days']);
        self::assertSame('verified', $metrics['truth_context']['status']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
    }

    public function testOnlyVerifiedOtaRowsContributeAcrossFourTruthStates(): void
    {
        $verified = $this->verifiedOtaRow([
            'id' => 1,
            'amount' => 100,
            'book_order_num' => 2,
            'quantity' => 3,
        ]);
        $partial = $this->verifiedOtaRow([
            'id' => 2,
            'amount' => 200,
            'book_order_num' => 20,
            'quantity' => 30,
            'validation_status' => 'partial',
        ]);
        $manual = $this->verifiedOtaRow([
            'id' => 3,
            'amount' => 300,
            'book_order_num' => 30,
            'quantity' => 40,
            'ingestion_method' => 'manual',
        ]);
        $legacy = $this->verifiedOtaRow([
            'id' => 4,
            'amount' => 400,
            'book_order_num' => 40,
            'quantity' => 50,
            'ingestion_method' => 'legacy',
        ]);
        $failed = $this->verifiedOtaRow([
            'id' => 5,
            'amount' => 500,
            'book_order_num' => 50,
            'quantity' => 60,
            'validation_status' => 'failed',
        ]);

        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$verified, $partial, $manual, $legacy, $failed],
            $this->otaScope(),
        ]);

        self::assertSame(100.0, $metrics['ota_channel_revenue']);
        self::assertSame(2, $metrics['ota_channel_orders']);
        self::assertSame(3.0, $metrics['ota_channel_room_nights']);
        self::assertSame(1, $metrics['truth_context']['included_verified_count']);
        self::assertSame(4, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['verified']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['partial']);
        self::assertSame(2, $metrics['truth_context']['status_counts']['unverified']);
        self::assertSame(1, $metrics['truth_context']['status_counts']['collection_failed']);
        self::assertSame('partial', $metrics['truth_context']['status']);
        self::assertNotEmpty($metrics['truth_context']['failure_reasons']);
    }

    public function testVerifiedRowsOutsideHotelDateOrOtaPlatformScopeAreExcluded(): void
    {
        $wrongHotel = $this->verifiedOtaRow(['id' => 11, 'system_hotel_id' => 8, 'amount' => 100]);
        $wrongDate = $this->verifiedOtaRow(['id' => 12, 'data_date' => '2026-07-14', 'amount' => 200]);
        $wrongPlatform = $this->verifiedOtaRow([
            'id' => 13,
            'platform' => 'internal',
            'source' => 'internal',
            'amount' => 300,
        ]);

        $metrics = $this->invokeNonPublic(new TransferDecisionService(), 'aggregateTransferMetrics', [
            [],
            [$wrongHotel, $wrongDate, $wrongPlatform],
            $this->otaScope(),
        ]);

        self::assertSame(0.0, $metrics['ota_channel_revenue']);
        self::assertSame(0, $metrics['truth_context']['included_verified_count']);
        self::assertSame(3, $metrics['truth_context']['excluded_untrusted_count']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['hotel_scope_mismatch']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['date_scope_mismatch']);
        self::assertSame(1, $metrics['truth_context']['scope_exclusion_counts']['unsupported_ota_platform']);
        self::assertSame('unverified', $metrics['truth_context']['status']);
    }

    public function testBuildTransferDashboardMergesPricingTimingAndMetricRisks(): void
    {
        $result = (new TransferDecisionService())->buildTransferDashboard(
            [
                'valuation' => [
                    'conservative_valuation' => 100,
                    'optimistic_valuation' => 180,
                ],
                'profit' => [
                    'monthly_net_profit' => 12,
                    'payback_months' => 16,
                ],
                'risk_level' => '低风险',
                'risk_points' => ['租金可控'],
                'main_reasons' => ['利润稳定'],
                'suggestion' => '可进入议价',
            ],
            [
                'timing_score' => 86,
                'decision' => '适合转让',
                'risk_points' => ['窗口期较好'],
                'main_reasons' => ['评分较高'],
                'next_suggestions' => ['准备挂牌材料'],
                'data_quality' => ['has_data_anomaly' => false],
            ],
            ['risk_points' => ['需复核证照']]
        );

        self::assertCount(6, $result['cards']);
        self::assertSame('启动挂牌', $result['suggested_action']);
        self::assertContains('需复核证照', $result['risk_points']);
        self::assertNotEmpty($result['final_judgement']);
    }

    public function testDecisionReadinessKeepsManualPricingAsInputOnly(): void
    {
        $service = $this->fallbackService();
        $pricing = $service->calculateAssetPricing($this->pricingInput());

        $readiness = $service->buildDecisionReadiness('pricing', $this->pricingInput(), $pricing, [], 7);

        self::assertSame('manual_input_only', $readiness['stage']);
        self::assertFalse($readiness['decision_ready']);
        self::assertSame('manual_input_only', $readiness['source_scope']);
        self::assertContains('source_snapshot', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDecisionReadinessRequiresDiligenceEvidenceBeforeDecisionReady(): void
    {
        $service = $this->fallbackService();
        $pricingInput = $this->pricingInput();
        $timingInput = [
            'current_revenue' => 120,
            'previous_revenue' => 100,
            'current_orders' => 620,
            'previous_orders' => 520,
            'current_adr' => 320,
            'previous_adr' => 300,
            'current_occupancy_rate' => 82,
            'previous_occupancy_rate' => 76,
            'rating' => 4.8,
            'exposure' => 12000,
            'visitors' => 1800,
            'conversion_rate' => 6.5,
            'order_count' => 620,
            'room_nights' => 980,
        ];
        $pricing = $service->calculateAssetPricing($pricingInput);
        $timing = $service->calculateTransferTiming($timingInput);
        $dashboard = $service->buildTransferDashboard($pricing, $timing, []);
        $snapshot = [
            'hotel_id' => 7,
            'source_date' => '2026-06-14',
            'current' => ['actual_days' => 30, 'has_data_anomaly' => false],
            'source_counts' => ['daily_reports' => 30, 'online_daily_data' => 30],
            'data_status' => '已接入真实数据',
        ];
        $dashboardInput = [
            'pricing' => $pricing,
            'timing' => $timing,
            'pricing_input' => $pricingInput,
            'timing_input' => $timingInput,
        ];

        $readiness = $service->buildDecisionReadiness('dashboard', $dashboardInput, $dashboard, $snapshot, 7);

        self::assertSame('diligence_required', $readiness['stage']);
        self::assertFalse($readiness['decision_ready']);
        self::assertContains('diligence_document_evidence', array_column($readiness['missing_evidence'], 'code'));

        $approved = $service->buildDecisionReadiness('dashboard', array_merge($dashboardInput, [
            'diligence_evidence' => ['lease_contract' => 'checked'],
            'review_status' => 'approved',
            'operation_execution_intent_id' => 88,
        ]), $dashboard, $snapshot, 7);

        self::assertSame('decision_ready', $approved['stage']);
        self::assertTrue($approved['decision_ready']);
    }

    public function testBuildExecutionIntentInputRequiresTransferRecordHotel(): void
    {
        $service = $this->fallbackService();

        $this->expectException(\InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 12, 'hotel_id' => 0]);
    }

    public function testBuildExecutionIntentInputUsesTransferDecisionScope(): void
    {
        $service = $this->fallbackService();
        $pricingInput = $this->pricingInput();
        $pricing = $service->calculateAssetPricing($pricingInput);
        $timing = $service->calculateTransferTiming([
            'current_revenue' => 120,
            'previous_revenue' => 100,
            'current_orders' => 620,
            'previous_orders' => 520,
            'current_adr' => 320,
            'previous_adr' => 300,
            'current_occupancy_rate' => 82,
            'previous_occupancy_rate' => 76,
            'rating' => 4.8,
            'exposure' => 12000,
            'visitors' => 1800,
            'conversion_rate' => 6.5,
            'order_count' => 620,
            'room_nights' => 980,
        ]);
        $dashboard = $service->buildTransferDashboard($pricing, $timing, []);
        $snapshot = [
            'hotel_id' => 7,
            'hotel_name' => 'Hotel A',
            'source_date' => '2026-06-14',
            'current' => ['actual_days' => 30, 'has_data_anomaly' => false],
            'source_counts' => ['daily_reports' => 30, 'online_daily_data' => 30],
        ];
        $input = [
            'pricing' => $pricing,
            'timing' => $timing,
            'pricing_input' => $pricingInput,
            'diligence_evidence' => ['lease_contract' => 'checked'],
            'review_status' => 'approved',
        ];
        $readiness = $service->buildDecisionReadiness('dashboard', $input, $dashboard, $snapshot, 7);

        $intentInput = $service->buildExecutionIntentInput([
            'id' => 12,
            'record_type' => 'dashboard',
            'hotel_id' => 7,
            'hotel_name' => 'Hotel A',
            'source_date' => '2026-06-14',
            'decision' => (string)($dashboard['suggested_action'] ?? ''),
            'risk_level' => 'medium',
            'input' => $input,
            'result' => $dashboard,
            'snapshot' => $snapshot,
            'decision_readiness' => $readiness,
        ], ['date_start' => '2026-06-14']);

        self::assertSame('transfer_decision', $intentInput['source_module']);
        self::assertSame(12, $intentInput['source_record_id']);
        self::assertSame(7, $intentInput['hotel_id']);
        self::assertSame('investment', $intentInput['platform']);
        self::assertSame('investment', $intentInput['object_type']);
        self::assertSame('transfer_decision_closure', $intentInput['target_value']['target_metric']);
        self::assertSame($readiness['stage'], $intentInput['evidence']['readiness_stage']);
        self::assertSame('medium', $intentInput['risk_level']);
    }

    private function verifiedOtaRow(array $overrides = []): array
    {
        $sourceUrlHash = str_repeat('d', 64);
        $row = array_merge([
            'id' => 1,
            'system_hotel_id' => 7,
            'hotel_id' => 'ctrip-7001',
            'hotel_name' => 'Hotel A',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-07-15',
            'amount' => 30000,
            'book_order_num' => 20,
            'quantity' => 25,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-safe-1',
            'source_url_hash' => $sourceUrlHash,
            'snapshot_time' => '2026-07-15 09:00:00',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'create_time' => '2026-07-15 09:01:00',
            'update_time' => '2026-07-15 09:01:00',
            'raw_data' => '{}',
        ], $overrides);

        $raw = is_array($row['raw_data'])
            ? $row['raw_data']
            : json_decode((string)$row['raw_data'], true);
        $raw = is_array($raw) ? $raw : [];
        $raw['source_trace_id'] = (string)$row['source_trace_id'];
        $raw['source_url_hash'] = $sourceUrlHash;
        $raw['field_facts'] = [];
        foreach ([
            'order_amount' => 'amount',
            'order_count' => 'book_order_num',
            'room_nights' => 'quantity',
        ] as $metricKey => $storageField) {
            $raw['field_facts'][] = [
                'metric_key' => $metricKey,
                'source_path' => '$.payload.' . $metricKey,
                'storage_field' => 'online_daily_data.' . $storageField,
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => (string)$row['source_trace_id'],
                    'source_url_hash' => $sourceUrlHash,
                ],
            ];
        }
        $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $row;
    }

    private function otaScope(array $overrides = []): array
    {
        return array_merge([
            'target_hotel_id' => 7,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-15',
        ], $overrides);
    }

    private function pricingInput(): array
    {
        return [
            'hotel_name' => '虹桥样板店',
            'location' => '上海虹桥',
            'room_count' => 80,
            'monthly_revenue' => 30,
            'monthly_rent' => 8,
            'labor_cost' => 5,
            'utility_cost' => 1,
            'ota_commission' => 2,
            'other_fixed_cost' => 1,
            'decoration_investment' => 200,
            'remaining_lease_months' => 72,
            'expected_transfer_price' => 180,
            'occupancy_rate' => 82,
            'adr' => 320,
            'rating' => 4.8,
            'order_count' => 900,
            'licenses_complete' => true,
        ];
    }

    private function fallbackService(): TransferDecisionService
    {
        return new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });
    }
}
