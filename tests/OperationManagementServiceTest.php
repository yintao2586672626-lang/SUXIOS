<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataFieldFactService;
use app\service\AiDecisionQualityService;
use app\service\OperationManagementService;
use app\service\PriceSuggestionOtaTargetMappingService;
use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OperationManagementServiceTest extends TestCase
{
    public function testSelectedHotelScopeNarrowsTwoHotelPermissionSetForAllAggregationEntrypoints(): void
    {
        $service = new OperationManagementService();

        self::assertSame(
            [8],
            $this->invokeNonPublic($service, 'scopeHotelIdsForSelection', [[7, 8], 8])
        );

        foreach (['fullData', 'rootCause', 'strategySimulation', 'createAction', 'alerts'] as $methodName) {
            $method = new \ReflectionMethod(OperationManagementService::class, $methodName);
            $lines = file($method->getFileName()) ?: [];
            $source = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            self::assertStringContainsString(
                'scopeHotelIdsForSelection',
                $source,
                $methodName . ' must narrow permitted hotel ids when one hotel is selected'
            );
        }
    }

    public function testExecutionDatesRejectCalendarOverflowRelativeAndEmptyValues(): void
    {
        $service = new OperationManagementService();
        foreach (['2026-02-30', 'tomorrow', '', null, ' 2026-08-13 '] as $invalid) {
            try {
                $service->buildExecutionIntentPayload([7], 7, [
                    'hotel_id' => 7,
                    'object_type' => 'inventory',
                    'action_type' => 'inventory_review',
                    'date_start' => $invalid,
                ], 9);
                self::fail('Invalid execution date must fail: ' . json_encode($invalid));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPriceSuggestionDefaultExecutionDateUsesShanghaiBusinessDayAcrossProcessTimezone(): void
    {
        $mapping = [
            'mapping_record_id' => 'fixture-ctrip-room-3', 'mapping_version' => 'v1',
            'status' => 'confirmed', 'tenant_id' => 7, 'hotel_id' => 7, 'platform' => 'ctrip',
            'room_type_id' => 3, 'room_type_key' => 'deluxe-king', 'rate_plan_key' => 'standard',
            'confirmed_by' => 3, 'confirmed_at' => '2026-08-13 08:00:00',
        ];
        $mapping['mapping_digest'] = PriceSuggestionOtaTargetMappingService::mappingDigest($mapping);
        $pricing = $this->createMock(RevenuePricingRecommendationService::class);
        $pricing->method('enrichSuggestionRows')->willReturnCallback(static function (array $rows): array {
            $rows[0]['decision_recommendation'] = [
                'can_create_execution_intent' => true,
                'blocked_reason' => '',
                'decision_quality' => [
                    'contract_version' => AiDecisionQualityService::CONTRACT_VERSION,
                    'execution_ready' => true,
                ],
            ];
            return $rows;
        });
        $service = new OperationManagementService($pricing);
        $shanghaiDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $originalTimezone = date_default_timezone_get();
        $differentTimezone = null;
        foreach (['Etc/GMT+12', 'Pacific/Kiritimati'] as $candidate) {
            $candidateDate = (new \DateTimeImmutable('now', new \DateTimeZone($candidate)))->format('Y-m-d');
            if ($candidateDate !== $shanghaiDate) {
                $differentTimezone = $candidate;
                break;
            }
        }
        self::assertNotNull($differentTimezone, 'Fixture must select a process timezone on a different calendar day.');

        date_default_timezone_set((string)$differentTimezone);
        try {
            self::assertNotSame($shanghaiDate, date('Y-m-d'));
            $input = $service->buildPriceSuggestionExecutionIntentInput([
                'id' => 88,
                'tenant_id' => 7,
                'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
                'hotel_id' => 7,
                'room_type_id' => 3,
                'suggestion_date' => $shanghaiDate,
                'current_price' => 300,
                'suggested_price' => 320,
                'min_price' => 260,
                'max_price' => 380,
                'reason' => 'approved source',
                'factors' => [
                    'manual_review_versions' => [['action' => 'approve']],
                    PriceSuggestionOtaTargetMappingService::FACTOR_KEY => $mapping,
                ],
                'competitor_data' => ['avg_price' => 330],
                'applied_by' => 3,
            ], [
                'platform' => 'ctrip',
                'room_type_key' => 'deluxe-king',
                'rate_plan_key' => 'standard',
            ]);

            self::assertSame($shanghaiDate, $input['date_start']);
            self::assertSame($shanghaiDate, $input['date_end']);
            self::assertSame($shanghaiDate, $input['evidence']['execution_date']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testFullDataReusesOneOnlineSnapshotAcrossDerivedModules(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'fullData');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertSame(1, substr_count($source, '$this->onlineRows('));
        self::assertStringContainsString(
            '$this->buildSummaryFromRows($daily, $online, $hotelIds, $hotelId, $date)',
            $source
        );
        self::assertStringContainsString('$this->buildOtaFromRows($online)', $source);
        self::assertStringContainsString('$this->buildServiceQualityFromRows($online)', $source);
    }

    public function testSelectedHotelScopeRejectsHotelOutsidePermissionSet(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeNonPublic($service, 'scopeHotelIdsForSelection', [[7, 8], 9]);
    }

    public function testHotelScopedOperationAnalysisRejectsImplicitPortfolioAggregation(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeNonPublic($service, 'scopeHotelIdsForSelection', [[7, 8], null]);
    }

    public function testMissingTrustedFunnelIsNullInsteadOfARealZero(): void
    {
        $service = new OperationManagementService();
        $summary = $this->invokeNonPublic($service, 'buildOtaFromRows', [[]]);

        self::assertNull($summary['exposure']);
        self::assertNull($summary['visitors']);
        self::assertSame('missing', $summary['funnel_status']);
        self::assertSame(['exposure', 'visitors'], $summary['missing_metrics']);
        self::assertNotSame('ok', $summary['data_status']);
    }

    use ReflectionHelper;

    public function testEffectValidationSummaryCalculatesProductLevelClosedLoopMetrics(): void
    {
        $service = new OperationManagementService();
        $effect = function (float $revenue, float $conversion): array {
            $revenueIdentity = $this->comparableEffectBaseline('revenue', 'ota_channel', 'ctrip', 'ctrip', $revenue);
            $conversionIdentity = $this->comparableEffectBaseline('conversion', 'ota_channel', 'ctrip', 'ctrip', $conversion);
            return array_replace($revenueIdentity, [
                'avg_conversion' => $conversion,
                'metric_sample_days' => ['revenue' => 7, 'conversion' => 7],
                'metric_identities' => [
                    'revenue' => $revenueIdentity['metric_identities']['revenue'],
                    'conversion' => $conversionIdentity['metric_identities']['conversion'],
                ],
            ]);
        };

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [
                [
                    'action_type' => 'price_adjust',
                    'target_metric' => 'revenue',
                    'before' => $effect(1000, 10),
                    'after' => $effect(1200, 12),
                    'result' => ['status' => 'success'],
                ],
                [
                    'action_type' => 'price_adjust',
                    'target_metric' => 'revenue',
                    'before' => $effect(1000, 9),
                    'after' => $effect(1050, 9),
                    'result' => ['status' => 'near_success'],
                ],
                [
                    'action_type' => 'promotion',
                    'target_metric' => 'revenue',
                    'before' => $effect(500, 8),
                    'after' => $effect(450, 7),
                    'result' => ['status' => 'failed'],
                ],
                [
                    'action_type' => 'room_inventory',
                    'target_metric' => 'revenue',
                    'before' => $effect(300, 5),
                    'after' => ['data_status' => '待接入真实数据'],
                    'result' => ['status' => 'observing'],
                ],
            ],
            ['total' => 5, 'adopted' => 3, 'data_status' => 'ok'],
            ['reviewed' => 4, 'accurate' => 3, 'data_status' => 'ok'],
            [],
        ]);

        self::assertSame('ready', $summary['status']);
        self::assertSame(4, $summary['action_counts']['total']);
        self::assertSame(3, $summary['action_counts']['reviewed']);
        self::assertSame(1, $summary['action_counts']['observing']);

        self::assertSame(8.0, $this->metricValue($summary, 'revenue_lift_rate'));
        self::assertSame(3.7, $this->metricValue($summary, 'conversion_lift_rate'));
        self::assertSame(100.0, $this->metricValue($summary, 'pricing_hit_rate'));
        self::assertSame(60.0, $this->metricValue($summary, 'suggestion_adoption_rate'));
        self::assertSame(75.0, $this->metricValue($summary, 'alert_accuracy_rate'));
    }

    public function testEffectValidationSummaryMarksUnavailableMetricsInsteadOfInventingValues(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [],
            ['total' => 0, 'adopted' => 0, 'data_status' => 'empty'],
            ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'unlabeled'],
            [['code' => 'operation_alerts_accuracy_label_missing', 'message' => '预警缺少准确/误报复盘标签']],
        ]);

        self::assertSame('data_gap', $summary['status']);
        self::assertNull($this->metricValue($summary, 'revenue_lift_rate'));
        self::assertNull($this->metricValue($summary, 'alert_accuracy_rate'));
        self::assertContains('operation_alerts_accuracy_label_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testActionEffectRejectsMissingOrDriftedMetricIdentityBeforeTerminalJudgment(): void
    {
        $service = new OperationManagementService();
        $valid = $this->comparableEffectBaseline('revenue', 'ota_channel', 'ctrip', 'ctrip', 1000.0);

        foreach ([
            'legacy_before_without_identity' => array_diff_key($valid, ['metric_identities' => true]),
            'whole_hotel_scope_drift' => $this->comparableEffectBaseline(
                'revenue',
                'whole_hotel_daily_report',
                '',
                'daily_reports',
                1000.0
            ),
            'ota_platform_drift' => $this->comparableEffectBaseline('revenue', 'ota_channel', 'meituan', 'meituan', 1000.0),
            'missing_source_scopes' => array_diff_key($valid, ['source_scopes' => true]),
            'partial_data' => array_replace($valid, ['data_status' => 'partial']),
        ] as $case => $before) {
            $assessment = $this->invokeNonPublic(
                $service,
                'assessComparableActionEffectEvidence',
                ['revenue', $before, array_replace($valid, ['avg_revenue' => 1200.0])]
            );
            self::assertFalse($assessment['comparable'], $case);
            self::assertNotSame('', $assessment['gap_code'], $case);
        }

        $assessment = $this->invokeNonPublic(
            $service,
            'assessComparableActionEffectEvidence',
            ['revenue', $valid, array_replace($valid, ['avg_revenue' => 1200.0])]
        );
        self::assertTrue($assessment['comparable']);
        self::assertSame('', $assessment['gap_code']);
    }

    public function testActionEffectRejectsUnequalOrUntraceableObservationWindows(): void
    {
        $service = new OperationManagementService();
        $before = $this->comparableEffectBaseline('revenue', 'ota_channel', 'ctrip', 'ctrip', 1000.0);
        $after = array_replace($before, [
            'days' => 4,
            'actual_days' => 4,
            'avg_revenue' => 1200.0,
            'metric_sample_days' => ['revenue' => 4],
            'window_start_date' => '2026-08-01',
            'window_end_date' => '2026-08-04',
        ]);

        $unequal = $this->invokeNonPublic(
            $service,
            'assessComparableActionEffectEvidence',
            ['revenue', $before, $after]
        );
        self::assertFalse($unequal['comparable']);
        self::assertSame('operation_action_effect_window_mismatch', $unequal['gap_code']);

        $missingCalendarRange = $this->invokeNonPublic(
            $service,
            'assessComparableActionEffectEvidence',
            ['revenue', array_diff_key($before, ['window_start_date' => true]), $before]
        );
        self::assertFalse($missingCalendarRange['comparable']);
        self::assertSame('operation_action_effect_window_metadata_missing', $missingCalendarRange['gap_code']);

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [[[
            'action_type' => 'price_adjust',
            'target_metric' => 'revenue',
            'before' => $before,
            'after' => $after,
            'result' => ['status' => 'success'],
        ]], ['total' => 0, 'adopted' => 0], ['reviewed' => 0, 'accurate' => 0], []]);
        self::assertSame(0, $summary['action_counts']['reviewed']);
        self::assertSame(1, $summary['action_counts']['observing']);
        self::assertNull($this->metricValue($summary, 'pricing_hit_rate'));
        self::assertContains('operation_action_effect_window_mismatch', array_column($summary['data_gaps'], 'code'));
    }

    public function testEffectValidationExcludesTerminalStatusWhenBeforeAfterEvidenceIsNotComparable(): void
    {
        $service = new OperationManagementService();
        $valid = $this->comparableEffectBaseline('revenue', 'ota_channel', 'ctrip', 'ctrip', 1000.0);
        $drifted = $this->comparableEffectBaseline('revenue', 'ota_channel', 'meituan', 'meituan', 1200.0);

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [[[
            'action_type' => 'price_adjust',
            'target_metric' => 'revenue',
            'before' => $valid,
            'after' => $drifted,
            'result' => ['status' => 'success'],
        ]], ['total' => 0, 'adopted' => 0], ['reviewed' => 0, 'accurate' => 0], []]);

        self::assertSame(0, $summary['action_counts']['reviewed']);
        self::assertSame(1, $summary['action_counts']['observing']);
        self::assertSame(0, $summary['metrics'][2]['sample_count']);
        self::assertContains('operation_action_effect_identity_drift', array_column($summary['data_gaps'], 'code'));
    }

    public function testActionEvaluationCannotBecomeTerminalAcrossOtaPlatformDrift(): void
    {
        $service = new OperationManagementService();
        $row = [
            'hotel_id' => 7,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'target_metric' => 'revenue',
            'target_change_rate' => 10,
        ];
        $before = $this->comparableEffectBaseline('revenue', 'ota_channel', 'ctrip', 'ctrip', 1000);
        $after = $this->comparableEffectBaseline('revenue', 'ota_channel', 'meituan', 'meituan', 1200);
        $now = new \DateTimeImmutable('2026-08-13 00:30:00', new \DateTimeZone('Asia/Shanghai'));

        $drifted = $this->invokeNonPublic($service, 'evaluateActionResult', [$row, $before, $after, $now]);
        self::assertSame('observing', $drifted['status']);
        self::assertSame('operation_action_effect_identity_drift', $drifted['gap_code']);

        $valid = $this->invokeNonPublic(
            $service,
            'evaluateActionResult',
            [$row, $before, array_replace($before, ['avg_revenue' => 1200]), $now]
        );
        self::assertSame('success', $valid['status']);
    }

    public function testShanghaiBusinessDateHelpersIgnoreProcessTimezoneAtUtcBoundary(): void
    {
        $service = new OperationManagementService();
        $utcBoundary = new \DateTimeImmutable('2026-08-12 16:30:00', new \DateTimeZone('UTC'));

        self::assertSame(
            '2026-08-13',
            $this->invokeNonPublic($service, 'operationShanghaiDateTime', [$utcBoundary])->format('Y-m-d')
        );
        self::assertSame(
            '2026-08-13',
            $this->invokeNonPublic($service, 'operationShanghaiBusinessDate', [$utcBoundary])
        );
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame('2026-08-13 00:30:00', $this->invokeNonPublic(
                $service,
                'executionReviewAvailableAt',
                [[
                    'source_module' => 'ota_diagnosis_saved',
                    'target_value' => ['workflow_schedule' => ['review_at' => '2026-08-13 00:30:00']],
                ], []]
            ));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testDailyWorkbenchPatrolDefaultUsesShanghaiBusinessDateAndRejectsOverflow(): void
    {
        $service = new OperationManagementService();
        $shanghaiToday = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Etc/GMT+12');
        try {
            $payload = $this->invokeNonPublic($service, 'buildDailyWorkbenchPatrolExecutionIntentInput', [[
                'hotel_id' => 7,
                'action_code' => 'collect_ota_data',
                'run_id' => 'patrol-default-date',
            ], 123]);
            self::assertSame($shanghaiToday, $payload['date_start']);
            self::assertSame($shanghaiToday, $payload['date_end']);
            self::assertSame($shanghaiToday, $payload['target_value']['target_date']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeNonPublic($service, 'buildDailyWorkbenchPatrolExecutionIntentInput', [[
            'hotel_id' => 7,
            'action_code' => 'collect_ota_data',
            'target_date' => '2026-02-30',
        ], 124]);
    }

    public function testExecutionIntentKeepsOtaDiagnosisEvidenceForDataCollectionAction(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([1], 1, [
            'hotel_id' => 1,
            'platform' => 'ctrip',
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 0,
            'object_type' => 'data_collection',
            'action_type' => 'collect_same_period_ota_data',
            'date_start' => '2026-06-12',
            'date_end' => '2026-06-12',
            'target_value' => [
                'collection_scope' => 'same_day_ota_channel',
                'target_date' => '2026-06-12',
            ],
            'evidence_refs' => ['ota_no_data_scope'],
            'data_gaps' => [[
                'code' => 'ota_same_period_source_rows_missing',
                'message' => '选定日期范围没有可用于 OTA 经营诊断的真实入库数据。',
            ]],
            'source_policy' => 'database_only_no_synthetic_conclusion',
            'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
        ], 9);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('data_collection', $payload['object_type']);
        self::assertSame(['ota_no_data_scope'], $payload['evidence']['evidence_refs']);
        self::assertSame('ota_same_period_source_rows_missing', $payload['evidence']['data_gaps'][0]['code']);
        self::assertSame('database_only_no_synthetic_conclusion', $payload['evidence']['source_policy']);
        self::assertStringContainsString('不改变采集字段', $payload['evidence']['protected_boundary']);
    }

    public function testExecutionIntentBlocksDataCollectionWithoutOtaEvidence(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([1], 1, [
            'hotel_id' => 1,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'collect_same_period_ota_data',
            'target_value' => [
                'collection_scope' => 'same_day_ota_channel',
            ],
        ], 9);

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('evidence missing', $payload['blocked_reason']);
        self::assertStringContainsString('ota evidence refs or data_gaps missing', $payload['blocked_reason']);
    }

    public function testDailyFinancialExtractorsUseFallbackFieldsWithoutInventingValues(): void
    {
        $service = new OperationManagementService();
        $reportData = [
            'xb_revenue' => '1,200',
            'mt_revenue' => 800,
            'parking_revenue' => 50,
            'xb_rooms' => 4,
            'mt_rooms' => 3,
            'salable_rooms' => 20,
        ];

        self::assertSame(2050.0, $this->invokeNonPublic($service, 'extractRevenue', [[], $reportData]));
        self::assertSame(7.0, $this->invokeNonPublic($service, 'extractRoomNights', [[], $reportData]));
        self::assertSame(20.0, $this->invokeNonPublic($service, 'extractSalableRoomCount', [[], $reportData]));
        self::assertSame(0.0, $this->invokeNonPublic($service, 'extractRevenue', [[], ['xb_revenue' => 'bad']]));
    }

    public function testDashboardSummaryAggregatesDailyAndOnlineRowsWithoutDoubleCountingRevenue(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [[
                'hotel_id' => 7,
                'report_date' => '2026-05-18',
                'status' => 2,
                'report_data' => json_encode([
                    'xb_revenue' => '1,200',
                    'mt_revenue' => 300,
                    'xb_rooms' => 4,
                    'mt_rooms' => 1,
                    'salable_rooms' => 10,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            [$this->trustedOtaOperatingRow([
                'id' => 4,
                'system_hotel_id' => 7,
                'hotel_id' => 130079194,
                'data_date' => '2026-05-18',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'snapshot_time' => '2026-05-18 09:00:00',
                'amount' => 999,
                'quantity' => 9,
                'book_order_num' => 8,
                'raw_data' => json_encode(['bookOrderNum' => 9], JSON_UNESCAPED_UNICODE),
            ])],
            [7],
            7,
            '2026-05-18',
        ]);

        self::assertSame(1500.0, $summary['revenue']);
        self::assertSame(5.0, $summary['room_nights']);
        self::assertSame(9, $summary['orders']);
        self::assertSame(300.0, $summary['adr']);
        self::assertSame(50.0, $summary['occ']);
        self::assertSame(150.0, $summary['revpar']);
        self::assertSame('partial', $summary['data_status']);
        self::assertSame('mixed_whole_hotel_and_ota_channel', $summary['source_scope']);
        self::assertSame(['whole_hotel_daily_report'], $summary['metric_scopes']['revenue']);
        self::assertSame(['ota_channel'], $summary['metric_scopes']['orders']);
        self::assertContains('operation_metric_scope_mixed', array_column($summary['data_gaps'], 'code'));
    }

    public function testDashboardSummaryDoesNotDoubleCountOtaOrdersCoveredByWholeHotelDailyReport(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [[
                'hotel_id' => 7,
                'report_date' => '2026-05-18',
                'status' => 2,
                'report_data' => json_encode([
                    'xb_revenue' => 1200,
                    'xb_rooms' => 4,
                    'salable_rooms' => 10,
                    'order_count' => 10,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            [$this->trustedOtaOperatingRow([
                'id' => 4,
                'system_hotel_id' => 7,
                'hotel_id' => 130079194,
                'data_date' => '2026-05-18',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'snapshot_time' => '2026-05-18 09:00:00',
                'amount' => 999,
                'quantity' => 9,
                'book_order_num' => 3,
            ])],
            [7],
            7,
            '2026-05-18',
        ]);

        self::assertSame(10, $summary['orders']);
        self::assertSame(['whole_hotel_daily_report'], $summary['metric_scopes']['orders']);
    }

    public function testDashboardSummaryKeepsMissingMetricsNullAndReportsGaps(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [[
                'id' => 5,
                'hotel_id' => 7,
                'report_date' => '2026-07-15',
                'status' => 2,
                'revenue' => 0,
                'report_data' => '{}',
            ]],
            [],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertSame(0.0, $summary['revenue'], 'An explicitly recorded zero must remain a real zero.');
        self::assertNull($summary['orders']);
        self::assertNull($summary['room_nights']);
        self::assertNull($summary['adr']);
        self::assertSame('partial', $summary['data_status']);
        self::assertContains('operation_orders_missing', array_column($summary['data_gaps'], 'code'));
        self::assertContains('operation_room_nights_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testDashboardSummaryRejectsUnverifiedDailyReportWithExplicitGap(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [[
                'id' => 9,
                'hotel_id' => 7,
                'report_date' => '2026-07-15',
                'status' => 1,
                'revenue' => 1200,
                'report_data' => json_encode([
                    'xb_revenue' => 1200,
                    'xb_rooms' => 4,
                    'order_count' => 6,
                    'salable_rooms' => 10,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            [],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertNull($summary['revenue']);
        self::assertNull($summary['orders']);
        self::assertNull($summary['room_nights']);
        self::assertSame('partial', $summary['data_status']);
        self::assertSame('partial', $summary['source_status']);
        self::assertSame(1, $summary['rejected_daily_report_count']);
        self::assertSame(['report_status_draft' => 1], $summary['rejected_daily_report_reasons']);
        self::assertContains(
            'operation_daily_report_validation_untrusted',
            array_column($summary['data_gaps'], 'code')
        );
    }

    public function testDashboardSummaryRejectsExplicitZeroesWithoutCompleteTrustEvidence(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [[
                'id' => 6,
                'system_hotel_id' => 7,
                'data_date' => '2026-07-15',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'raw_data' => '{}',
            ]],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertNull($summary['revenue']);
        self::assertNull($summary['orders']);
        self::assertNull($summary['room_nights']);
        self::assertSame('missing', $summary['data_status']);
        self::assertContains('operation_revenue_missing', array_column($summary['data_gaps'], 'code'));
        self::assertContains('operation_orders_missing', array_column($summary['data_gaps'], 'code'));
        self::assertContains('operation_room_nights_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testDashboardSummaryAcceptsVerifiedExplicitZeroesAsRealZeroes(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [$this->trustedOtaOperatingRow([
                'id' => 8,
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
            ])],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertSame(0.0, $summary['revenue']);
        self::assertSame(0, $summary['orders']);
        self::assertSame(0.0, $summary['room_nights']);
        self::assertNull($summary['adr'], 'A zero denominator must not produce a fake ADR zero.');
        self::assertSame('ok', $summary['data_status']);
        self::assertSame([], $summary['data_gaps']);
    }

    public function testDashboardSummaryAllowsOnlyRequestedMetricsFromPartialFieldFacts(): void
    {
        $service = new OperationManagementService();
        $traceId = 'ctrip:' . str_repeat('a', 64);
        $sourceUrlHash = str_repeat('b', 64);
        $capturedFact = static function (
            string $metricKey,
            string $storageField,
            string $sourceKey
        ) use ($traceId, $sourceUrlHash): array {
            return [
                'metric_key' => $metricKey,
                'storage_field' => $storageField,
                'source_key' => $sourceKey,
                'source_path' => 'data.' . $sourceKey,
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                ],
            ];
        };
        $partialCheckout = $this->trustedOtaOperatingRow([
            'id' => 68698,
            'system_hotel_id' => 80,
            'hotel_id' => 130079194,
            'data_date' => '2026-07-29',
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
            'validation_status' => 'partial',
            'dimension' => 'catalog:business_overview:business_market_overview:order_amount:data.amount',
            'amount' => 2168,
            'quantity' => 3,
            'book_order_num' => null,
            'raw_data' => json_encode([
                'row' => [
                    'endpoint_id' => 'business_market_overview',
                    'amount' => 2168,
                    'quantity' => 3,
                ],
                'field_facts' => [
                    $capturedFact('order_amount', 'online_daily_data.amount', 'amount'),
                    $capturedFact('room_nights', 'online_daily_data.quantity', 'quantity'),
                    [
                        'metric_key' => 'comment_score',
                        'source_path' => 'data.commentScore',
                        'storage_field' => 'online_daily_data.comment_score',
                        'status' => 'missing',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [$partialCheckout],
            [80],
            80,
            '2026-07-29',
        ]);
        $withoutMetricFacts = $partialCheckout;
        $withoutMetricFacts['raw_data'] = json_encode([
            'row' => [
                'endpoint_id' => 'business_market_overview',
                'amount' => 2168,
                'quantity' => 3,
            ],
        ], JSON_UNESCAPED_UNICODE);
        $blocked = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [$withoutMetricFacts],
            [80],
            80,
            '2026-07-29',
        ]);

        self::assertSame(2168.0, $summary['revenue']);
        self::assertSame(3.0, $summary['room_nights']);
        self::assertNull($summary['orders']);
        self::assertSame(['order_amount', 'room_nights'], $summary['evidence_refs'][0]['field_fact_metric_keys']);
        self::assertSame('ctrip_checkout_daily', $summary['evidence_refs'][0]['metric_semantic_scope']);
        self::assertNull($blocked['revenue']);
        self::assertNull($blocked['room_nights']);
    }

    public function testTrustedOtaFactRejectsUnverifiedPartialFailedAndIncompleteEvidence(): void
    {
        $service = new OperationManagementService();
        $cases = [
            'hotel identity missing' => ['system_hotel_id' => null],
            'data source binding missing' => ['data_source_id' => null],
            'platform identity missing' => ['source' => '', 'platform' => ''],
            'data date missing' => ['data_date' => ''],
            'data date invalid' => ['data_date' => '2026-02-30'],
            'validation status missing' => ['validation_status' => ''],
            'unverified validation' => ['validation_status' => 'unverified'],
            'partial validation' => ['validation_status' => 'partial'],
            'failed validation' => ['validation_status' => 'failed'],
            'readback missing' => ['readback_verified' => null],
            'readback failed' => ['readback_verified' => 0],
            'collection time missing' => ['snapshot_time' => ''],
            'manual source' => ['ingestion_method' => 'manual'],
            'legacy source' => ['ingestion_method' => 'legacy'],
            'manual import source' => ['ingestion_method' => 'manual_import'],
        ];

        foreach ($cases as $label => $overrides) {
            self::assertFalse(
                $this->invokeNonPublic($service, 'isTrustedSelfOtaFactRow', [
                    $this->trustedOtaOperatingRow($overrides),
                ]),
                $label
            );
        }

        self::assertTrue($this->invokeNonPublic($service, 'isTrustedSelfOtaFactRow', [
            $this->trustedOtaOperatingRow(),
        ]));
    }

    public function testMeituanRankRowsRequireTheTrustedOtaEvidenceEnvelope(): void
    {
        $service = new OperationManagementService();
        $valid = $this->trustedOtaOperatingRow([
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_type' => 'business',
        ]);

        self::assertTrue($this->invokeNonPublic($service, 'isMeituanBusinessRankRow', [$valid]));
        foreach ([
            'data source binding missing' => ['data_source_id' => 0],
            'validation untrusted' => ['validation_status' => 'unverified'],
            'readback unverified' => ['readback_verified' => 0],
            'ingestion untrusted' => ['ingestion_method' => 'manual'],
            'collection timestamp missing' => ['snapshot_time' => ''],
        ] as $label => $overrides) {
            self::assertFalse(
                $this->invokeNonPublic($service, 'isMeituanBusinessRankRow', [array_replace($valid, $overrides)]),
                $label
            );
        }
    }

    public function testDashboardSummaryRejectsUnidentifiedOnlineSource(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [$this->trustedOtaOperatingRow([
                'id' => 7,
                'source' => '',
                'platform' => '',
                'amount' => 100,
                'quantity' => 1,
                'book_order_num' => 1,
            ])],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertNull($summary['revenue']);
        self::assertNull($summary['orders']);
        self::assertNull($summary['room_nights']);
        self::assertSame('missing', $summary['data_status']);
        self::assertSame('unknown', $summary['source_scope']);
    }

    public function testDashboardSummaryExcludesCompetitorFactsAndDuplicateBusinessSnapshots(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [
                $this->trustedOtaOperatingRow([
                    'id' => 17652,
                    'system_hotel_id' => 80,
                    'hotel_id' => 130079194,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => '',
                    'validation_status' => 'normal',
                    'snapshot_time' => '2026-07-15 09:15:46',
                    'update_time' => '2026-07-15 09:15:46',
                    'amount' => 5939,
                    'quantity' => 7,
                    'book_order_num' => 11,
                    'raw_data' => '{}',
                ]),
                $this->trustedOtaOperatingRow([
                    'id' => 34952,
                    'system_hotel_id' => 80,
                    'hotel_id' => 130079194,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:business_flow_compete:order_count',
                    'validation_status' => 'normal',
                    'snapshot_time' => '2026-07-15 09:13:33',
                    'update_time' => '2026-07-15 09:13:33',
                    'amount' => 377223.9,
                    'quantity' => 0,
                    'book_order_num' => 288,
                    'raw_data' => '{}',
                ]),
                $this->trustedOtaOperatingRow([
                    'id' => 17670,
                    'system_hotel_id' => 80,
                    'hotel_id' => 130079194,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:business_realtime:visitor_count+order_count',
                    'validation_status' => 'normal',
                    'snapshot_time' => '2026-07-15 09:16:00',
                    'update_time' => '2026-07-15 09:16:00',
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 6,
                    'raw_data' => '{}',
                ]),
            ],
            [80],
            80,
            '2026-07-15',
        ]);

        self::assertSame(5939.0, $summary['revenue']);
        self::assertSame(7.0, $summary['room_nights']);
        self::assertSame(11, $summary['orders']);
        self::assertSame(848.43, $summary['adr']);
        self::assertNull($summary['occ']);
        self::assertNull($summary['revpar']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testCompetitorTrafficNeverBecomesSelfOtaFunnelEvidence(): void
    {
        $service = new OperationManagementService();
        $today = date('Y-m-d');
        $selfBusiness = $this->trustedOtaOperatingRow([
            'id' => 17652,
            'system_hotel_id' => 80,
            'hotel_id' => 130079194,
            'data_date' => $today,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => '',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'snapshot_time' => $today . ' 09:15:46',
            'update_time' => $today . ' 09:15:46',
            'amount' => 5939,
            'quantity' => 7,
            'book_order_num' => 11,
            'raw_data' => '{}',
        ]);
        $competitorTraffic = $this->trustedOtaOperatingRow([
            'id' => 43491,
            'system_hotel_id' => 80,
            'hotel_id' => -1,
            'data_date' => $today,
            'source' => 'ctrip',
            'platform' => 'Qunar',
            'compare_type' => 'competitor_avg',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+competitor_list_exposure+detail_visitor:50.listExposure',
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'snapshot_time' => $today . ' 09:15:46',
            'update_time' => $today . ' 09:15:46',
            'list_exposure' => 268,
            'detail_exposure' => 48,
            'order_filling_num' => 3,
            'order_submit_num' => 2,
            'raw_data' => '{}',
        ]);

        $ota = $this->invokeNonPublic($service, 'buildOtaFromRows', [[$selfBusiness, $competitorTraffic]]);
        self::assertNull($ota['exposure']);
        self::assertNull($ota['visitors']);
        self::assertSame('missing', $ota['funnel_status']);
        self::assertSame(['exposure', 'visitors'], $ota['missing_metrics']);
        self::assertNotContains('online_daily_data#43491', array_column($ota['evidence_refs'], 'source_ref'));

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromTenantScopedRows', [
            [],
            [$selfBusiness, $competitorTraffic],
            [80],
            80,
            $today,
        ]);
        self::assertSame(5939.0, $summary['revenue']);
        self::assertSame(11, $summary['orders']);
        self::assertSame(7.0, $summary['room_nights']);
        self::assertSame(['online_daily_data#17652'], array_column($summary['evidence_refs'], 'source_ref'));
        self::assertSame('ctrip', $summary['evidence_refs'][0]['platform']);
        self::assertNotContains('online_daily_data#43491', array_column($summary['evidence_refs'], 'source_ref'));
    }

    public function testOperatingSnapshotChannelUsesPlatformWhenSourceIsEmpty(): void
    {
        $service = new OperationManagementService();

        $channel = $this->invokeNonPublic($service, 'operatingSnapshotChannel', [[
            'evidence_refs' => [[
                'source' => '',
                'platform' => 'Qunar',
            ]],
        ]]);

        self::assertSame('qunar', $channel);
    }

    public function testRootCauseRulesFlagDataTrafficPriceServiceQualityAndHolidayBoundaries(): void
    {
        $service = new OperationManagementService();

        $result = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => ['orders' => 5, 'exposure' => 0, 'visitors' => 0, 'view_rate' => 2, 'order_rate' => 1, 'data_status' => 'ok'],
            'summary' => ['adr' => 330, 'data_status' => 'ok'],
            'competitors' => [
                'avg_price' => 250,
                'avg_our_public_price' => 330,
                'avg_score' => 4.8,
                'data_status' => 'ok',
                'comparability_status' => 'eligible',
                'comparison_key' => 'same-rate-context',
            ],
            'service_quality' => ['avg_psi_score' => 76.5, 'avg_service_score' => 79.0, 'data_status' => 'ok'],
            'holiday' => ['days_left' => 7, 'data_status' => 'ok'],
        ], ['exposure' => 100, 'data_status' => 'ok'], ['view_rate' => 20, 'order_rate' => 10, 'data_status' => 'ok'], 'conversion_low']);

        self::assertSame('high', $result['problem_level']);
        self::assertSame('data_abnormal', $result['root_causes'][0]['type']);
        self::assertContains('traffic_down', array_column($result['root_causes'], 'type'));
        self::assertContains('price_high', array_column($result['root_causes'], 'type'));
        self::assertContains('service_quality_low', array_column($result['root_causes'], 'type'));
        self::assertNotContains('score_low', array_column($result['root_causes'], 'type'));
        self::assertContains('holiday_near', array_column($result['root_causes'], 'type'));
        self::assertSame($result['candidate_factors'], $result['root_causes']);
        self::assertSame($result['candidate_factors'][0]['rule_match_weight'], $result['candidate_factors'][0]['confidence']);
        self::assertStringContainsString('不是统计置信度', $result['candidate_factors'][0]['confidence_basis']);
        foreach ($result['root_causes'] as $cause) {
            self::assertSame('available', $cause['reference_basis']['status']);
            self::assertNotSame('', $cause['reference_basis']['reference_version']);
            self::assertSame('operation_root_cause.v1', $cause['reference_basis']['rule_version']);
        }
        $causesByType = array_column($result['root_causes'], null, 'type');
        self::assertSame(7, $causesByType['traffic_down']['reference_basis']['history_window']);
        self::assertSame('competitor_average', $causesByType['price_high']['reference_basis']['type']);
        self::assertSame('ota_public_display_price', $causesByType['price_high']['reference_basis']['metric']);

        $incomparable = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => ['data_status' => 'ok'],
            'summary' => ['adr' => 999, 'data_status' => 'ok'],
            'competitors' => [
                'avg_price' => 100,
                'price_gap' => 899,
                'data_status' => 'data_gap',
                'comparability_status' => 'insufficient_evidence',
            ],
            'service_quality' => [],
            'holiday' => [],
        ], [], [], '']);
        self::assertNotContains('price_high', array_column($incomparable['root_causes'], 'type'));

        $legacyAssessment = $this->invokeNonPublic($service, 'competitorAnalysisComparability', [[
            'our_price' => 999,
            'competitor_price' => 100,
            'competitor_data' => [],
        ]]);
        self::assertFalse($legacyAssessment['eligible']);
        self::assertContains('check_in_date_missing', $legacyAssessment['reasons']);
        self::assertContains('readback_unverified', $legacyAssessment['reasons']);

        $empty = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => [],
            'summary' => [],
            'competitors' => [],
            'service_quality' => [],
            'holiday' => [],
        ], [], [], '']);

        self::assertSame('data_insufficient', $empty['problem_level']);
        self::assertSame('unknown', $empty['main_problem']);
        self::assertSame([], $empty['root_causes']);
        self::assertStringNotContainsString('点评', implode(' ', $empty['next_actions']));
    }

    public function testStrategySimulationContractNamesRuleScenarioAndTreatsUnknownRiskAsUnassessed(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'strategySimulation');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString("'rule_scenario'", $source);
        self::assertStringContainsString('forecast 为兼容旧客户端保留', $source);
        self::assertStringContainsString("'level' => 'unknown'", $source);
        self::assertStringNotContainsString('规则估算风险较低', $source);
        self::assertStringContainsString('不是经营预测', $source);
    }

    public function testServiceQualitySummaryUsesCapturedQualityRows(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildServiceQualityFromRows', [[
            $this->trustedOtaOperatingRow([
                'id' => 91,
                'data_type' => 'quality',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ]),
            $this->trustedOtaOperatingRow([
                'id' => 92,
                'data_type' => 'service_quality',
                'raw_data' => json_encode(['service_score' => 86, 'psi_score' => 82.2], JSON_UNESCAPED_UNICODE),
            ]),
            [
                'data_type' => 'traffic',
                'raw_data' => json_encode(['psiScore' => 10, 'serviceScore' => 10], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertSame(85.4, $summary['avg_psi_score']);
        self::assertSame(89.25, $summary['avg_service_score']);
        self::assertSame(2, $summary['sample_count']);
        self::assertSame(2, $summary['psi_sample_count']);
        self::assertSame(2, $summary['service_score_sample_count']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testMissingPsiRemainsNullAndCannotBecomeMetricReadbackZero(): void
    {
        $service = new OperationManagementService();
        $rows = [
            $this->trustedOtaOperatingRow([
                'id' => 93,
                'data_type' => 'business',
                'raw_data' => json_encode(['orders' => 5], JSON_UNESCAPED_UNICODE),
            ]),
            $this->trustedOtaOperatingRow([
                'id' => 94,
                'data_type' => 'quality',
                'raw_data' => json_encode(['serviceScore' => 91.2], JSON_UNESCAPED_UNICODE),
            ]),
        ];

        $quality = $this->invokeNonPublic($service, 'buildServiceQualityFromRows', [$rows]);

        self::assertNull($quality['avg_psi_score']);
        self::assertSame(0, $quality['psi_sample_count']);
        self::assertSame(1, $quality['service_score_sample_count']);
        self::assertNull($this->invokeNonPublic(
            $service,
            'executionReadbackMetricValue',
            ['avg_psi_score', $rows, 7, '2026-07-15']
        ));
    }

    public function testMeituanRankBatchChangesDetectTopSelfRankAndVipSignals(): void
    {
        $service = new OperationManagementService();
        $targetPoiId = 'self-poi';

        $currentHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            [
                'data_date' => '2026-06-06',
                'update_time' => '2026-06-06 09:00:00',
                'raw_data' => json_encode(['poiId' => 'top-new', 'poiName' => 'New Top Hotel', 'rank' => 1, 'platformTags' => ['VIP'], 'hasVipTag' => true], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-06-06',
                'update_time' => '2026-06-06 09:00:00',
                'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel', 'rank' => 4, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
        ], $targetPoiId]);
        $previousHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            [
                'data_date' => '2026-06-05',
                'update_time' => '2026-06-05 09:00:00',
                'raw_data' => json_encode(['poiId' => 'top-old', 'poiName' => 'Old Top Hotel', 'rank' => 1, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-06-05',
                'update_time' => '2026-06-05 09:00:00',
                'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel', 'rank' => 2, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
        ], $targetPoiId]);

        $current = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$currentHotels, '2026-06-06', '2026-06-06 09:00:00', 2]);
        $previous = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$previousHotels, '2026-06-05', '2026-06-05 09:00:00', 2]);
        $changes = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchChanges', [$current, $previous]);

        self::assertSame('changed', $changes['status']);
        $types = array_column($changes['alerts'], 'type');
        self::assertContains('top1_changed', $types);
        self::assertContains('self_rank_changed', $types);
        self::assertContains('vip_count_changed', $types);
    }

    public function testMeituanRankBatchChangesKeepMissingEvidenceExplicit(): void
    {
        $service = new OperationManagementService();
        $targetPoiId = 'self-poi';

        $currentHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            ['data_date' => '2026-06-06', 'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel'], JSON_UNESCAPED_UNICODE)],
        ], $targetPoiId]);
        $previousHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            ['data_date' => '2026-06-05', 'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel'], JSON_UNESCAPED_UNICODE)],
        ], $targetPoiId]);

        $current = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$currentHotels, '2026-06-06', '', 1]);
        $previous = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$previousHotels, '2026-06-05', '', 1]);
        $changes = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchChanges', [$current, $previous]);

        self::assertSame('missing', $changes['status']);
        self::assertSame([], $changes['alerts']);
        self::assertStringContainsString('no VIP inference', $changes['missing_reason']);
        self::assertStringContainsString('rank fields are not comparable', $changes['missing_reason']);
    }

    public function testExecutionIntentRejectsNestedReusableCredentialMaterial(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reusable credential material');

        $service->buildExecutionIntentPayload([7], 7, [
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'data_collection',
            'action_type' => 'collect_ota_data',
            'target_value' => ['collection_scope' => 'ota_channel'],
            'evidence' => [
                'evidence_refs' => ['operator_review'],
                'nested' => ['authorization' => 'Bearer reusable-secret'],
            ],
        ], 9);
    }

    public function testExecutionIntentAllowsCurrencyAndOpaqueBusinessIds(): void
    {
        $service = new OperationManagementService();
        $businessId = '5026028568383187252';

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'data_collection',
            'action_type' => 'collect_ota_data',
            'current_value' => [
                'currency' => 'CNY',
                'external_order_id' => $businessId,
                'cookiePricesDisplayed' => 'CNY',
            ],
            'target_value' => ['collection_scope' => 'ota_channel'],
            'evidence' => ['evidence_refs' => ['operator_review']],
        ], 9);

        self::assertSame('CNY', $payload['current_value']['currency']);
        self::assertSame($businessId, $payload['current_value']['external_order_id']);
        self::assertSame('CNY', $payload['current_value']['cookiePricesDisplayed']);
    }

    public function testExecutionTaskUpdateRejectsNestedEvidenceCredentialMaterial(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reusable credential material');

        $service->buildExecutionTaskUpdate(
            ['id' => 81],
            ['status' => 'approved'],
            [
                'status' => 'executed',
                'evidence' => [
                    'after' => ['auth_data' => ['token' => 'reusable-secret']],
                ],
            ],
            9
        );
    }

    public function testExecutedTaskWithRemarkOnlyEvidenceStaysBlocked(): void
    {
        $service = new OperationManagementService();

        $result = $service->buildExecutionTaskUpdate(
            ['id' => 81, 'status' => 'pending_execute'],
            ['status' => 'approved', 'source_module' => 'manual'],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_operation_execution',
                'evidence' => [
                    'platform_response' => ['mode' => 'manual'],
                    'remark' => 'operator says the action was done',
                ],
            ],
            9
        );

        self::assertSame('blocked', $result['task']['status']);
        self::assertStringContainsString('meaningful execution evidence', $result['task']['blocked_reason']);
        self::assertArrayNotHasKey('executed_at', $result['task']);
        self::assertNull($result['evidence']);
    }

    public function testMeaningfulExecutionReceiptContractRequiresAuditableContentAndOperator(): void
    {
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'platform_response' => ['mode' => 'manual', 'arbitrary' => 'non-empty'],
            'remark' => 'free-form text is not a receipt',
            'created_by' => 9,
        ], 9));
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'source_verified_metric_readback',
            'before' => ['conversion_rate' => 10],
            'after' => ['conversion_rate' => 12],
            'created_by' => 0,
        ]));
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'platform_response' => ['completed_action' => 'updated campaign image'],
            'created_by' => 8,
        ], 9));
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{"arbitrary":"same-placeholder"}',
            'after_json' => '{"arbitrary":"same-placeholder"}',
            'created_by' => 9,
        ], 9));
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{"arbitrary":"placeholder-a"}',
            'after_json' => '{"arbitrary":"placeholder-b"}',
            'created_by' => 9,
        ], 9));
        self::assertFalse(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{"hero_image":"same-image"}',
            'after_json' => '{"hero_image":"same-image"}',
            'created_by' => 9,
        ], 9));

        self::assertTrue(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'platform_response' => ['completed_action' => 'updated campaign image'],
            'created_by' => 9,
        ], 9));
        self::assertTrue(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{"hero_image":"baseline"}',
            'after_json' => '{"hero_image":"candidate_b"}',
            'created_by' => 9,
        ], 9));
        self::assertTrue(OperationManagementService::isMeaningfulExecutionReceipt([
            'evidence_type' => 'manual_screenshot',
            'attachment_path' => '/runtime/evidence/campaign-81.png',
            'created_by' => 9,
        ], 9));
    }

    public function testExecutedTaskWithPlaceholderBeforeAfterEvidenceStaysBlocked(): void
    {
        $service = new OperationManagementService();

        $result = $service->buildExecutionTaskUpdate(
            ['id' => 81, 'status' => 'pending_execute'],
            ['status' => 'approved', 'source_module' => 'manual'],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_operation_execution',
                'evidence' => [
                    'before' => ['arbitrary' => 'same-placeholder'],
                    'after' => ['arbitrary' => 'same-placeholder'],
                ],
            ],
            9
        );

        self::assertSame('blocked', $result['task']['status']);
        self::assertStringContainsString('meaningful execution evidence', $result['task']['blocked_reason']);
        self::assertArrayNotHasKey('executed_at', $result['task']);
        self::assertNull($result['evidence']);
    }

    public function testOperatingNetworkReadbackUsesTargetHotelSameScopeVerifiedRows(): void
    {
        $service = new OperationManagementService();
        $baselineDate = (new \DateTimeImmutable('today'))->modify('-2 days')->format('Y-m-d');
        $reviewDate = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
        $executedAt = $baselineDate . ' 18:00:00';
        $reviewAt = $reviewDate . ' 10:00:00';
        $readbackAt = $reviewDate . ' 12:00:00';
        $intent = [
            'tenant_id' => 10,
            'hotel_id' => 21,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'expected_metric' => 'conversion_rate',
            'date_start' => $baselineDate,
            'date_end' => $baselineDate,
            'current_value' => ['conversion_rate' => 10],
        ];
        $task = ['id' => 81, 'hotel_id' => 21, 'executed_at' => $executedAt];
        $scope = [
            'intent_platform' => 'ctrip',
            'readback_platform' => 'ctrip',
            'expected_metric' => 'conversion_rate',
            'object_type' => 'campaign',
            'date_start' => $baselineDate,
            'date_end' => $baselineDate,
            'baseline_date' => $baselineDate,
            'review_date' => $reviewDate,
            'review_timestamp' => strtotime($reviewAt),
            'executed_timestamp' => strtotime($executedAt),
            'replication_id' => 17,
            'replication_content_digest' => str_repeat('a', 64),
            'execution_contract_digest' => str_repeat('b', 64),
            'declared_target_fact_refs' => ['online_daily_data#701'],
            'declared_target_fact_ids' => [701],
        ];
        $row = static function (int $id, string $date, string $collectedAt, int $orders): array {
            return [
                'id' => $id,
                'tenant_id' => 10,
                'system_hotel_id' => 21,
                'data_source_id' => 25,
                'data_date' => $date,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'compare_type' => 'self',
                'data_type' => 'traffic',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'dimension' => 'catalog:ctrip:business_flow_transform:v1',
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'visitors' => 100,
                'book_order_num' => $orders,
                'validation_status' => 'verified',
                'readback_verified' => 1,
                'ingestion_method' => 'authorized_api_collection',
                'collected_at' => $collectedAt,
            ];
        };
        $baselineRow = $row(701, $baselineDate, $baselineDate . ' 12:00:00', 10);
        $reviewRow = $row(702, $reviewDate, $readbackAt, 12);

        $payload = $this->invokeNonPublic(
            $service,
            'buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows',
            [$task, $intent, $scope, [$baselineRow], [$reviewRow]]
        );
        self::assertIsArray($payload);
        self::assertSame('source_verified_metric_readback', $payload['evidence_type']);
        self::assertSame('online_daily_data#701', $payload['platform_response']['baseline_source_ref']);
        self::assertSame('online_daily_data#702', $payload['platform_response']['followup_source_ref']);
        self::assertSame(21, $payload['platform_response']['system_hotel_id']);
        self::assertSame('ctrip', $payload['platform_response']['platform']);
        self::assertSame('conversion_rate', $payload['platform_response']['metric_key']);
        self::assertTrue($payload['platform_response']['readback_verified']);
        self::assertFalse($payload['platform_response']['causality_claimed']);

        $truth = $this->invokeNonPublic($service, 'assessExecutionEvidenceTruth', [$intent, $task, $payload]);
        self::assertTrue($truth['source_verified']);

        $unverifiedReviewRow = array_replace($reviewRow, ['readback_verified' => 0]);
        self::assertNull($this->invokeNonPublic(
            $service,
            'buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows',
            [$task, $intent, $scope, [$baselineRow], [$unverifiedReviewRow]]
        ));
        $wrongHotelReviewRow = array_replace($reviewRow, ['system_hotel_id' => 22]);
        self::assertNull($this->invokeNonPublic(
            $service,
            'buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows',
            [$task, $intent, $scope, [$baselineRow], [$wrongHotelReviewRow]]
        ));
        $driftedBaselineScope = array_replace($scope, [
            'declared_target_fact_refs' => ['online_daily_data#700'],
            'declared_target_fact_ids' => [700],
        ]);
        self::assertNull($this->invokeNonPublic(
            $service,
            'buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows',
            [$task, $intent, $driftedBaselineScope, [$baselineRow], [$reviewRow]]
        ));

        $dispatcher = new \ReflectionMethod(OperationManagementService::class, 'buildSourceVerifiedMetricReadbackPayload');
        $lines = file($dispatcher->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $dispatcher->getStartLine() - 1,
            $dispatcher->getEndLine() - $dispatcher->getStartLine() + 1
        ));
        self::assertStringContainsString('OperatingNetworkService::EXECUTION_SOURCE_MODULE', $source);
        self::assertStringContainsString('buildOperatingNetworkSourceVerifiedReadbackPayload', $source);
    }

    public function testLegacyExecutionRowsRedactCredentialsWithoutAlteringCurrencyOrIds(): void
    {
        $service = new OperationManagementService();
        $businessId = '5026028568383187252';

        $intent = $this->invokeNonPublic($service, 'normalizeExecutionIntentRow', [[
            'id' => 1,
            'hotel_id' => 7,
            'source_record_id' => 9,
            'expected_delta' => 1,
            'current_value_json' => json_encode([
                'currency' => 'CNY',
                'external_order_id' => $businessId,
                'cookiePricesDisplayed' => 'CNY',
            ], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['nested' => ['cookies' => 'sid=legacy-secret']], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['note' => 'Authorization: Bearer legacy-auth'], JSON_UNESCAPED_UNICODE),
        ]]);
        $task = $this->invokeNonPublic($service, 'normalizeExecutionTaskRow', [[
            'id' => 2,
            'intent_id' => 1,
            'hotel_id' => 7,
            'current_value_json' => json_encode(['token' => 'legacy-token'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['currency' => 'CNY', 'external_order_id' => $businessId], JSON_UNESCAPED_UNICODE),
        ]]);
        $evidence = $this->invokeNonPublic($service, 'normalizeExecutionEvidenceRow', [[
            'id' => 3,
            'task_id' => 2,
            'before_json' => json_encode(['password' => 'legacy-password'], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['currency' => 'CNY', 'external_order_id' => $businessId], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode(['headers' => ['Cookie' => 'sid=legacy-cookie']], JSON_UNESCAPED_UNICODE),
            'remark' => 'mtgsig=legacy-signature',
        ]]);

        $encoded = json_encode([$intent, $task, $evidence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['legacy-secret', 'legacy-auth', 'legacy-token', 'legacy-password', 'legacy-cookie', 'legacy-signature'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$encoded);
        }
        self::assertSame('CNY', $intent['current_value']['currency']);
        self::assertSame('CNY', $intent['current_value']['cookiePricesDisplayed']);
        self::assertSame($businessId, $task['target_value']['external_order_id']);
        self::assertSame($businessId, $evidence['after']['external_order_id']);
    }

    public function testMeituanTargetIdentityResolverDoesNotDecodeDataSourceConfigJson(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'resolveMeituanTargetPoiId');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString('config_json', $source);
        self::assertStringNotContainsString('json_decode', $source);
        self::assertStringContainsString('tableHasColumn', $source);
    }

    public function testExecutionTaskReviewGuardsBothInputAndDerivedSummaryBeforeWrite(): void
    {
        $entryMethod = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTask');
        $method = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTaskAuthorized');
        $lines = file($method->getFileName()) ?: [];
        $entrySource = implode('', array_slice(
            $lines,
            $entryMethod->getStartLine() - 1,
            $entryMethod->getEndLine() - $entryMethod->getStartLine() + 1
        ));
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertGreaterThanOrEqual(
            2,
            substr_count($entrySource . $source, 'assertExecutionPayloadHasNoCredentialMaterial'),
            'Task review must guard both request input and any summary derived from legacy action tracking.'
        );
    }

    public function testExecutionTaskReviewUsesTransactionalCompareAndSwap(): void
    {
        $entryMethod = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTask');
        $method = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTaskAuthorized');
        $lines = file($method->getFileName()) ?: [];
        $entrySource = implode('', array_slice(
            $lines,
            $entryMethod->getStartLine() - 1,
            $entryMethod->getEndLine() - $entryMethod->getStartLine() + 1
        ));
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('withExecutionTaskMutationAuthorization', $entrySource);
        self::assertStringContainsString("->where('status', 'executed')", $source);
        self::assertStringContainsString("->where('result_status', \$expectedResultStatus)", $source);
        self::assertStringContainsString("->where('result_summary', \$expectedResultSummary)", $source);
        self::assertStringContainsString('if ($affected !== 1)', $source);
        self::assertStringContainsString('execution task state changed; refresh before review', $source);
        self::assertStringContainsString('$hasSourceVerifiedReviewEvidence', $source);
        self::assertStringContainsString('source-verified business metric readback is required before success review', $source);
        self::assertStringContainsString('executionPositiveOutcomeAllowsStatus', $source);
        self::assertStringContainsString('target-aligned source-verified metric outcome is required before success review', $source);
    }

    public function testSourceVerifiedExecutionEvidenceRequiresAllTruthDimensions(): void
    {
        $service = new OperationManagementService();
        $intent = [
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'expected_metric' => 'revenue',
        ];
        $task = ['id' => 88, 'status' => 'executed', 'result_status' => 'success'];
        $platformResponse = [
            'verification_authority' => 'system_readback',
            'source' => 'online_daily_data',
            'source_ref' => 'online_daily_data#verified-88',
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'metric_key' => 'revenue',
            'database_written' => true,
            'readback_verified' => true,
            'readback_count' => 1,
            'readback_at' => '2026-07-18 13:00:00',
            'validation_status' => 'verified',
        ];
        $evidence = [
            'id' => 99,
            'task_id' => 88,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => ['revenue' => 0],
            'after' => ['revenue' => 0, 'cost' => 0],
            'platform_response' => $platformResponse,
            'created_by' => 0,
        ];

        $verified = $this->invokeNonPublic($service, 'assessExecutionEvidenceTruth', [$intent, $task, $evidence]);
        self::assertTrue($verified['source_verified']);
        self::assertSame('verified', $verified['status']);

        foreach ([
            'source identity' => [['source_ref' => ''], 'source_identity_missing'],
            'hotel' => [['system_hotel_id' => 8], 'evidence_hotel_mismatch'],
            'platform' => [['platform' => 'meituan'], 'evidence_platform_or_object_mismatch'],
            'object' => [['object_type' => 'campaign'], 'evidence_platform_or_object_mismatch'],
            'date window' => [['date_end' => '2026-07-19'], 'evidence_date_window_mismatch'],
            'persistence' => [['database_written' => false], 'evidence_database_persistence_unverified'],
            'readback' => [['readback_count' => 0], 'evidence_database_readback_unverified'],
            'metric' => [['metric_key' => 'orders'], 'review_metric_alignment_missing'],
            'validation' => [['validation_status' => 'failed', 'failure_reason' => 'collection_failed'], 'source_validation_failed'],
        ] as $label => [$overrides, $expectedReason]) {
            $candidate = $evidence;
            $candidate['platform_response'] = array_replace($platformResponse, $overrides);
            $assessment = $this->invokeNonPublic($service, 'assessExecutionEvidenceTruth', [$intent, $task, $candidate]);
            self::assertFalse($assessment['source_verified'], $label);
            self::assertContains($expectedReason, $assessment['failure_reasons'], $label);
        }

        $clientAuthored = $evidence;
        $clientAuthored['created_by'] = 7;
        $assessment = $this->invokeNonPublic($service, 'assessExecutionEvidenceTruth', [$intent, $task, $clientAuthored]);
        self::assertFalse($assessment['source_verified']);
        self::assertContains('system_readback_authority_missing', $assessment['failure_reasons']);
    }

    public function testPositiveOutcomeTruthSeparatesProvenanceFromTargetAchievement(): void
    {
        $service = new OperationManagementService();
        $task = ['id' => 88, 'status' => 'executed', 'result_status' => 'observing'];
        $platformResponse = [
            'verification_authority' => 'system_readback',
            'source' => 'online_daily_data',
            'source_ref' => 'online_daily_data#outcome-88',
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'metric_key' => 'orders',
            'database_written' => true,
            'readback_verified' => true,
            'readback_count' => 1,
            'readback_at' => '2026-07-19 13:00:00',
            'validation_status' => 'verified',
        ];
        $intent = [
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'expected_metric' => 'orders',
            'expected_delta' => 10,
            'target_value' => [],
            'evidence' => [],
        ];
        $evidence = [[
            'id' => 99,
            'task_id' => 88,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => ['orders' => 100],
            'after' => ['orders' => 90],
            'platform_response' => $platformResponse,
            'created_by' => 0,
        ]];

        $adverse = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertTrue($adverse['source_verified'], 'The source remains verified even when the outcome is adverse.');
        self::assertSame('increase', $adverse['direction']);
        self::assertSame('adverse', $adverse['status']);
        self::assertFalse($adverse['positive_outcome_verified']);
        self::assertSame('metric_worsened', $adverse['failure_reason']);
        self::assertFalse($this->invokeNonPublic(
            $service,
            'executionPositiveOutcomeAllowsStatus',
            [$adverse, 'success']
        ));

        $evidence[0]['after'] = ['orders' => 108];
        $near = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertSame('near', $near['status']);
        self::assertFalse($this->invokeNonPublic(
            $service,
            'executionPositiveOutcomeAllowsStatus',
            [$near, 'success']
        ));
        self::assertTrue($this->invokeNonPublic(
            $service,
            'executionPositiveOutcomeAllowsStatus',
            [$near, 'near_success']
        ));

        $evidence[0]['after'] = ['orders' => 112];
        $met = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertSame('met', $met['status']);
        self::assertTrue($this->invokeNonPublic(
            $service,
            'executionPositiveOutcomeAllowsStatus',
            [$met, 'success']
        ));
    }

    public function testPositiveOutcomeTruthRejectsUnquantifiedTargetAndUnknownDirection(): void
    {
        $service = new OperationManagementService();
        $task = ['id' => 88, 'status' => 'executed', 'result_status' => 'observing'];
        $intent = [
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'expected_metric' => 'orders',
            'expected_delta' => 0,
            'target_value' => [],
            'evidence' => ['expected_delta_status' => 'not_quantified'],
        ];
        $platformResponse = [
            'verification_authority' => 'system_readback',
            'source' => 'online_daily_data',
            'source_ref' => 'online_daily_data#unquantified-88',
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'metric_key' => 'orders',
            'database_written' => true,
            'readback_verified' => true,
            'readback_count' => 1,
            'readback_at' => '2026-07-19 13:00:00',
            'validation_status' => 'verified',
        ];
        $evidence = [[
            'id' => 99,
            'task_id' => 88,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => ['orders' => 100],
            'after' => ['orders' => 120],
            'platform_response' => $platformResponse,
            'created_by' => 0,
        ]];

        $unquantified = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertTrue($unquantified['source_verified']);
        self::assertSame('unverified', $unquantified['status']);
        self::assertSame('target_not_quantified', $unquantified['failure_reason']);

        $intent['expected_metric'] = 'custom_quality_index';
        $intent['expected_delta'] = 10;
        $intent['evidence'] = [];
        $platformResponse['metric_key'] = 'custom_quality_index';
        $evidence[0]['before'] = ['custom_quality_index' => 50];
        $evidence[0]['after'] = ['custom_quality_index' => 60];
        $evidence[0]['platform_response'] = $platformResponse;
        $unknownDirection = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertTrue($unknownDirection['source_verified']);
        self::assertSame('unverified', $unknownDirection['status']);
        self::assertSame('expected_direction_unknown', $unknownDirection['failure_reason']);

        $intent['target_value'] = ['expected_direction' => 'decrease'];
        $evidence[0]['after'] = ['custom_quality_index' => 40];
        $explicitDecrease = $this->invokeNonPublic($service, 'buildExecutionOutcomeTruth', [$intent, $task, $evidence]);
        self::assertSame('met', $explicitDecrease['status']);
        self::assertSame('decrease', $explicitDecrease['direction']);
    }

    public function testOperatorAttestationRejectsLegacyAndClientClaimedSourceVerification(): void
    {
        $service = new OperationManagementService();
        $attested = $this->invokeNonPublic($service, 'executionEvidenceHasOperatorAttestation', [[[
            'task_id' => 88,
            'evidence_type' => 'operator_attested_platform_readback',
            'created_by' => 7,
            'created_at' => '2026-07-17 12:31:00',
            'platform_response_json' => json_encode([
                'mode' => 'operator_attested',
                'verification_status' => 'operator_attested',
                'operator_attested' => true,
                'operator_attested_at' => '2026-07-17 12:30:00',
                'source_verified' => false,
                'source_validation_status' => 'not_source_verified',
                'source_ref' => 'ota_receipt#123',
            ], JSON_UNESCAPED_UNICODE),
            'attachment_path' => '',
        ]], ['id' => 88, 'executed_at' => '2026-07-17 12:00:00']]);
        self::assertTrue($attested);

        $legacyClientClaim = $this->invokeNonPublic($service, 'executionEvidenceHasOperatorAttestation', [[[
            'task_id' => 88,
            'evidence_type' => 'manual_platform_readback',
            'created_by' => 7,
            'created_at' => '2026-07-17 12:31:00',
            'platform_response_json' => json_encode([
                'readback_verified' => true,
                'readback_verified_at' => '2026-07-17 12:30:00',
                'source_ref' => 'client-claim#1',
            ], JSON_UNESCAPED_UNICODE),
            'attachment_path' => '',
        ]], ['id' => 88, 'executed_at' => '2026-07-17 12:00:00']]);
        self::assertFalse($legacyClientClaim);

        $sourceVerifiedClaim = $this->invokeNonPublic($service, 'executionEvidenceHasOperatorAttestation', [[[
            'task_id' => 88,
            'evidence_type' => 'operator_attested_platform_readback',
            'created_by' => 7,
            'created_at' => '2026-07-17 12:31:00',
            'platform_response_json' => json_encode([
                'mode' => 'operator_attested',
                'verification_status' => 'operator_attested',
                'operator_attested' => true,
                'operator_attested_at' => '2026-07-17 12:30:00',
                'source_verified' => true,
                'source_validation_status' => 'not_source_verified',
                'source_ref' => 'client-claim#2',
            ], JSON_UNESCAPED_UNICODE),
            'attachment_path' => '',
        ]], ['id' => 88, 'executed_at' => '2026-07-17 12:00:00']]);
        self::assertFalse($sourceVerifiedClaim);
    }

    public function testReviewReadbackEvidenceNormalizesLegacyClientFieldAsOperatorAttestationWithoutOtaWrite(): void
    {
        $service = new OperationManagementService();
        $payload = $this->invokeNonPublic($service, 'normalizeExecutionReviewReadbackEvidence', [[
            'readback_evidence' => [
                'readback_verified' => 'true',
                'readback_verified_at' => '2026-07-17T12:30',
                'source_ref' => 'screenshot#review-123',
            ],
        ], ['id' => 88, 'executed_at' => '2026-07-17 12:00:00'], 7]);

        self::assertSame('operator_attested_platform_readback', $payload['evidence_type']);
        self::assertSame(88, $payload['task_id']);
        self::assertSame(7, $payload['created_by']);
        self::assertSame('operator_attested', $payload['platform_response']['verification_status']);
        self::assertTrue($payload['platform_response']['operator_attested']);
        self::assertFalse($payload['platform_response']['source_verified']);
        self::assertSame('not_source_verified', $payload['platform_response']['source_validation_status']);
        self::assertArrayNotHasKey('readback_verified', $payload['platform_response']);
        self::assertSame('operator_attested_platform_readback_no_ota_write', $payload['platform_response']['evidence_boundary']);
    }

    public function testReviewReadbackEvidenceRejectsClientClaimedSourceVerification(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source_verified cannot be submitted by the client');
        $this->invokeNonPublic($service, 'normalizeExecutionReviewReadbackEvidence', [[
            'readback_evidence' => [
                'operator_attested' => true,
                'operator_attested_at' => '2026-07-17T12:30',
                'source_ref' => 'screenshot#review-123',
                'source_verified' => true,
            ],
        ], ['id' => 88, 'executed_at' => '2026-07-17 12:00:00'], 7]);
    }

    public function testTemporalForecastDatabaseFailureIsNotReportedAsMissingReadback(): void
    {
        $targetDate = '2026-08-12';
        $service = new OperationManagementService(
            null,
            null,
            null,
            null,
            static function (): array {
                throw new \RuntimeException('simulated database outage');
            }
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('temporal_forecast_readback_failed');
        $this->invokeNonPublic($service, 'buildTemporalForecastSourceVerifiedReadbackPayload', [[
            'id' => 88,
            'executed_at' => $targetDate . ' 08:00:00',
        ], [
            'hotel_id' => 7,
            'source_record_id' => 123,
            'platform' => 'all_ota',
            'expected_metric' => 'ota_revenue',
            'date_start' => $targetDate,
            'date_end' => $targetDate,
            'object_type' => 'operation_checklist',
            'action_type' => 'manual_forecast_review',
            'current_value' => [
                'metric_key' => 'ota_revenue',
                'target_date' => $targetDate,
                'predicted_value' => 1200.0,
            ],
            'target_value' => [
                'target_metric' => 'ota_revenue',
                'automatic_price_write' => false,
            ],
            'evidence' => [
                'automatic_price_write' => false,
                'review_required' => true,
                'evidence_refs' => [[
                    'row_id' => 123,
                    'metric_key' => 'ota_revenue',
                    'target_date' => $targetDate,
                    'forecast_run_id' => 'forecast-run-123',
                ]],
            ],
        ], 'all_ota', new \DateTimeImmutable('2026-08-12 16:30:00', new \DateTimeZone('UTC'))]);
    }

    public function testSavedOtaDiagnosisApprovalFreezesTargetAndExactReadbackDigest(): void
    {
        $service = new OperationManagementService();
        $approvedAt = '2026-08-09 09:30:00';
        $intent = [
            'id' => 91,
            'hotel_id' => 80,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 251,
            'platform' => 'ctrip',
            'date_start' => '2026-08-08',
            'date_end' => '2026-08-08',
            'expected_metric' => 'order_rate',
            'target_value' => [
                'target_metric' => 'order_rate',
                'expected_direction' => 'increase',
                'due_at' => '2026-08-08 18:00:00',
                'review_at' => '2026-08-09 10:00:00',
                'workflow_schedule' => [
                    'assignee_id' => 9,
                    'due_at' => '2026-08-08 18:00:00',
                    'review_at' => '2026-08-09 10:00:00',
                ],
            ],
            'evidence' => ['decision_recommendation_digest' => str_repeat('a', 64)],
        ];

        $result = $this->invokeNonPublic($service, 'buildSavedOtaDiagnosisApprovalTarget', [
            $intent,
            [
                'expected_metric' => 'order_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1.5,
                'review_business_date' => '2026-08-09',
            ],
            3,
            $approvedAt,
        ]);

        self::assertSame('manual_confirmed', $result['target_value']['expected_delta_status']);
        self::assertSame('2026-08-09', $result['target_value']['review_business_date']);
        self::assertSame(1.5, $result['expected_delta']);
        self::assertSame('1.500000', $result['evidence']['approval_target']['expected_delta']);
        self::assertSame(
            $result['evidence']['approval_target']['metric_definition'],
            $result['target_value']['metric_definition']
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evidence']['approval_target']['content_digest']);
        self::assertSame(
            $result['evidence']['approval_target']['content_digest'],
            $result['target_value']['approval_target_digest']
        );

        $this->invokeNonPublic($service, 'assertSavedOtaDiagnosisApprovalTargetReadback', [[
            ...$intent,
            'approved_by' => 3,
            'approved_at' => $approvedAt,
            'target_value' => $result['target_value'],
            'evidence' => $result['evidence'],
            'expected_delta' => $result['expected_delta'],
            'tasks' => [['target_value' => $result['target_value']]],
        ]]);
        self::assertTrue(true);
    }

    public function testSavedOtaDiagnosisApprovalRejectsSameDayOrUnquantifiedTarget(): void
    {
        $service = new OperationManagementService();
        $intent = [
            'id' => 91,
            'hotel_id' => 80,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 251,
            'platform' => 'ctrip',
            'date_start' => '2026-08-08',
            'date_end' => '2026-08-08',
            'expected_metric' => 'order_rate',
            'target_value' => ['review_at' => '2026-08-09 10:00:00'],
            'evidence' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('review_business_date must be exactly the next calendar business date');
        $this->invokeNonPublic($service, 'buildSavedOtaDiagnosisApprovalTarget', [
            $intent,
            [
                'expected_metric' => 'order_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 0,
                'review_business_date' => '2026-08-08',
            ],
            3,
            '2026-08-09 09:30:00',
        ]);
    }

    public function testSavedOtaDiagnosisApprovalRejectsZeroDeltaOnValidNextDay(): void
    {
        $service = new OperationManagementService();
        $intent = [
            'id' => 91,
            'hotel_id' => 80,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 251,
            'platform' => 'ctrip',
            'date_start' => '2026-08-08',
            'date_end' => '2026-08-08',
            'expected_metric' => 'order_rate',
            'target_value' => ['review_at' => '2026-08-09 10:00:00'],
            'evidence' => [],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('approval expected_delta must be a positive number');
        $this->invokeNonPublic($service, 'buildSavedOtaDiagnosisApprovalTarget', [
            $intent,
            [
                'expected_metric' => 'order_rate',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 0,
                'review_business_date' => '2026-08-09',
            ],
            3,
            '2026-08-09 09:30:00',
        ]);
    }

    public function testCtripListExposureDefinitionAndApprovalFreezeUniqueUserIntegerSemantics(): void
    {
        $service = new OperationManagementService();
        $definition = $this->invokeNonPublic(
            $service,
            'savedOtaDiagnosisMetricDefinition',
            ['list_exposure', 'ctrip']
        );
        self::assertSame('ota_execution_metric_definition.v3', $definition['version']);
        self::assertSame('ctrip', $definition['platform']);
        self::assertSame('ctrip_query_flow_transform_new_v1', $definition['source_endpoint_family']);
        self::assertSame(
            ['business_flow_transform', 'traffic_flow_transform'],
            $definition['source_endpoint_ids']
        );
        self::assertSame('ctrip_datacenter_list_exposure_uv', $definition['semantic_key']);
        self::assertSame('unique_users', $definition['unit']);
        self::assertSame('non_negative_integer', $definition['value_type']);
        self::assertTrue($definition['field_fact_required']);

        $intent = [
            'id' => 93,
            'hotel_id' => 80,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 258,
            'platform' => 'ctrip',
            'date_start' => '2026-08-09',
            'date_end' => '2026-08-09',
            'current_value' => ['list_exposure' => 0],
            'expected_metric' => 'list_exposure',
            'target_value' => [
                'target_metric' => 'list_exposure',
                'review_at' => '2026-08-10 10:00:00',
                'workflow_schedule' => ['review_at' => '2026-08-10 10:00:00'],
            ],
            'evidence' => ['decision_recommendation_digest' => str_repeat('b', 64)],
        ];
        try {
            $this->invokeNonPublic($service, 'buildSavedOtaDiagnosisApprovalTarget', [
                $intent,
                [
                    'expected_metric' => 'list_exposure',
                    'expected_direction' => 'increase',
                    'target_type' => 'delta',
                    'expected_delta' => 0.5,
                    'review_business_date' => '2026-08-10',
                ],
                3,
                '2026-08-09 12:00:00',
            ]);
            self::fail('Exposure targets must not approve fractional people.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('whole-user', $exception->getMessage());
        }

        $approved = $this->invokeNonPublic($service, 'buildSavedOtaDiagnosisApprovalTarget', [
            $intent,
            [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1,
                'review_business_date' => '2026-08-10',
            ],
            3,
            '2026-08-09 12:00:00',
        ]);
        self::assertSame('1.000000', $approved['evidence']['approval_target']['expected_delta']);
        self::assertSame($definition, $approved['evidence']['approval_target']['metric_definition']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supported only for Ctrip');
        $this->invokeNonPublic($service, 'savedOtaDiagnosisMetricDefinition', ['list_exposure', 'meituan']);
    }

    public function testListExposureReadbackRejectsDefaultZeroButAcceptsCapturedZero(): void
    {
        $service = new OperationManagementService();
        $base = $this->trustedOtaOperatingRow([
            'id' => 81817,
            'system_hotel_id' => 80,
            'data_date' => '2026-08-09',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:business_flow_transform:list_exposure',
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => '2026-08-09 09:00:00',
            'create_time' => '2026-08-09 09:00:00',
            'update_time' => '2026-08-09 09:00:00',
            'source_trace_id' => 'trace-list-exposure-zero',
            'source_url_hash' => str_repeat('c', 64),
            'raw_data' => json_encode([
                'source_trace_id' => 'trace-list-exposure-zero',
                'source_url_hash' => str_repeat('c', 64),
                '_source_path' => '$.data',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $defaultZeroRows = $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$base], 'list_exposure']
        );
        self::assertSame([], $defaultZeroRows, 'A schema default zero must not become an observed source fact.');

        $captured = OnlineDataFieldFactService::attachToOnlineDailyRow($base, [
            'listExposure' => 0,
            '_source_path' => '$.data',
            '_source_trace_id' => 'trace-list-exposure-zero',
            '_source_url_hash' => str_repeat('c', 64),
        ]);
        $capturedRows = $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$captured], 'list_exposure']
        );
        self::assertCount(1, $capturedRows);
        self::assertSame(0.0, $this->invokeNonPublic(
            $service,
            'executionReadbackMetricValue',
            ['list_exposure', $capturedRows, 80, '2026-08-09']
        ));

        $trafficFlow = array_replace($captured, [
            'id' => 81818,
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
        ]);
        self::assertCount(1, $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$trafficFlow], 'list_exposure']
        ), 'The traffic-report alias of queryFlowTransforNewV1 must remain eligible for same-criterion readback.');

        $genericImpressions = OnlineDataFieldFactService::attachToOnlineDailyRow($base, [
            'impressions' => 0,
            '_source_path' => '$.data',
            '_source_trace_id' => 'trace-list-exposure-zero',
            '_source_url_hash' => str_repeat('c', 64),
        ]);
        self::assertSame([], $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$genericImpressions], 'list_exposure']
        ), 'Generic impressions must not satisfy the frozen Ctrip list-exposure user semantic.');

        $rankOnly = OnlineDataFieldFactService::attachToOnlineDailyRow(
            array_replace($base, [
                'dimension' => '',
                'raw_data' => json_encode([
                    'row' => ['endpoint_id' => 'traffic_hotel_seq', 'rank' => 604],
                    'source_trace_id' => 'trace-rank-only',
                    'source_url_hash' => str_repeat('d', 64),
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'source_trace_id' => 'trace-rank-only',
                'source_url_hash' => str_repeat('d', 64),
            ]),
            [
                'listExposure' => 0,
                '_source_path' => '$.data',
                '_source_trace_id' => 'trace-rank-only',
                '_source_url_hash' => str_repeat('d', 64),
            ]
        );
        $rankRaw = json_decode((string)$rankOnly['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $rankRaw['row']['endpoint_id'] = 'traffic_hotel_seq';
        $rankOnly['raw_data'] = json_encode($rankRaw, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertSame([], $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$rankOnly], 'list_exposure']
        ), 'A rank endpoint with normalized zero columns must never become a list-exposure fact.');
    }

    public function testSavedOtaDiagnosisOrderRateReadbackKeepsCtripAndQunarSeparate(): void
    {
        $service = new OperationManagementService();
        $baselineDate = date('Y-m-d', strtotime('-2 days'));
        $reviewDate = date('Y-m-d', strtotime('-1 day'));
        $flowRow = fn(int $id, string $platform, string $date, int $visitors, int $orders): array =>
            $this->trustedOtaOperatingRow([
                'id' => $id,
                'system_hotel_id' => 80,
                'data_date' => $date,
                'source' => 'ctrip',
                'platform' => $platform,
                'data_type' => 'traffic',
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:date',
                'detail_exposure' => $visitors,
                'order_filling_num' => $orders,
                'order_submit_num' => $orders,
                'snapshot_time' => $date . ' 09:00:00',
            ]);

        $ctripBaseline = $flowRow(81818, 'Ctrip', $baselineDate, 100, 10);
        $qunarBaseline = $flowRow(81926, 'Qunar', $baselineDate, 30, 0);
        $ctripReview = $flowRow(82018, 'Ctrip', $reviewDate, 80, 16);
        $qunarReview = $flowRow(82026, 'Qunar', $reviewDate, 100, 1);

        self::assertSame('qunar', $this->invokeNonPublic(
            $service,
            'executionReadbackRowPlatformIdentity',
            [$qunarBaseline]
        ));
        $canonicalMixed = $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$ctripBaseline, $qunarBaseline], 'order_rate']
        );
        $canonicalMixedIds = array_map('intval', array_column($canonicalMixed, 'id'));
        sort($canonicalMixedIds, SORT_NUMERIC);
        self::assertSame([81818, 81926], $canonicalMixedIds, 'Different OTA platforms must never compete for one canonical slot.');

        $baselineRows = $this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackRows',
            [[$ctripBaseline, $qunarBaseline], 'ctrip']
        );
        $reviewRows = $this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackRows',
            [[$ctripReview, $qunarReview], 'ctrip']
        );
        $baselineRows = $this->invokeNonPublic($service, 'canonicalExecutionReadbackRows', [$baselineRows, 'order_rate']);
        $reviewRows = $this->invokeNonPublic($service, 'canonicalExecutionReadbackRows', [$reviewRows, 'order_rate']);

        self::assertSame([81818], array_map('intval', array_column($baselineRows, 'id')));
        self::assertSame([82018], array_map('intval', array_column($reviewRows, 'id')));
        self::assertTrue($this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackPlatformCoverage',
            [$baselineRows, 'ctrip']
        ));
        self::assertSame(10.0, $this->invokeNonPublic(
            $service,
            'executionReadbackMetricValue',
            ['order_rate', $baselineRows, 80, $baselineDate]
        ));
        self::assertSame(20.0, $this->invokeNonPublic(
            $service,
            'executionReadbackMetricValue',
            ['order_rate', $reviewRows, 80, $reviewDate]
        ));

        $blankPlatform = array_replace($ctripBaseline, ['id' => 83018, 'platform' => '']);
        self::assertSame([], $this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackRows',
            [[$blankPlatform], 'ctrip']
        ));
        self::assertFalse($this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackPlatformCoverage',
            [[$blankPlatform], 'ctrip']
        ));
        self::assertSame([], $this->invokeNonPublic(
            $service,
            'canonicalExecutionReadbackRows',
            [[$blankPlatform], 'order_rate']
        ));

        $legacyWithoutPlatform = $ctripBaseline;
        unset($legacyWithoutPlatform['platform']);
        self::assertSame([81818], array_map('intval', array_column(
            $this->invokeNonPublic(
                $service,
                'trustedExecutionReadbackRows',
                [[$legacyWithoutPlatform], 'ctrip']
            ),
            'id'
        )));
        self::assertTrue($this->invokeNonPublic(
            $service,
            'trustedExecutionReadbackPlatformCoverage',
            [[$legacyWithoutPlatform], 'ctrip']
        ));
    }

    public function testExecutionEvidenceBoundaryRejectsOutcomeMetrics(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saved separately from execution evidence');
        $this->invokeNonPublic($service, 'assertOperatorExecutionEvidenceBoundary', [
            'manual_operation_execution',
            ['before' => ['revenue' => 1000], 'after' => ['revenue' => 1200]],
        ]);
    }

    public function testExecutionEvidenceBoundaryKeepsListExposureOutOfExecutionReceipts(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('saved separately from execution evidence');
        $this->invokeNonPublic($service, 'assertOperatorExecutionEvidenceBoundary', [
            'manual_operation_execution',
            ['before' => ['list_exposure' => 0], 'after' => ['list_exposure' => 10]],
        ]);
    }

    private function metricValue(array $summary, string $key): mixed
    {
        foreach ($summary['metrics'] as $metric) {
            if (($metric['key'] ?? '') === $key) {
                return $metric['value'];
            }
        }

        self::fail('Metric not found: ' . $key);
    }

    /** @return array<string,mixed> */
    private function comparableEffectBaseline(
        string $metric,
        string $scope,
        string $platform,
        string $source,
        float $value
    ): array {
        $valueKey = 'avg_' . $metric;
        return [
            'data_status' => 'ok',
            'days' => 7,
            'actual_days' => 7,
            'window_start_date' => '2026-07-25',
            'window_end_date' => '2026-07-31',
            $valueKey => $value,
            'metric_sample_days' => [$metric => 7],
            'source_scopes' => [$scope],
            'metric_identities' => [$metric => [[
                'metric' => $metric,
                'scope' => $scope,
                'platform' => $platform,
                'source' => $source,
                'measurement_grain' => 'daily_average',
            ]]],
            'data_gaps' => [],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function trustedOtaOperatingRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 6,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => 130079194,
            'data_date' => '2026-07-15',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => '2026-07-15 09:00:00',
            'raw_data' => '{}',
        ], $overrides);
    }
}
