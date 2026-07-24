<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaDataCredibilityGateService;
use app\service\OtaRevenueMetricService;
use PHPUnit\Framework\TestCase;

final class OtaDataCredibilityGateServiceTest extends TestCase
{
    public function testPhaseOneReadinessRejectsReadyMetricsWhenCriticalEvidenceIsBlocked(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['credibility_gate']['status'] = 'blocked';
        $metrics['credibility_gate']['decision_use'] = [
            'revenue_analysis' => ['allowed' => false, 'status' => 'blocked'],
            'ai_decision_support' => ['allowed' => false, 'status' => 'blocked'],
        ];
        $metrics['credibility_gate']['evidence']['failed_critical_metrics'] = [
            'critical_metric_untrusted:totals.revenue',
            'critical_metric_untrusted:totals.room_nights',
            'critical_metric_untrusted:totals.adr',
        ];
        $metrics['metric_trust']['totals.revenue']['saved_success'] = false;
        $metrics['metric_trust']['totals.revenue']['failure_reasons'] = ['source_row_save_failed'];

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['ready']);
        self::assertFalse($readiness['revenue_ready']);
        self::assertFalse($readiness['ai_ready']);
        self::assertSame('ready', $readiness['metric_status']);
        self::assertSame('blocked', $readiness['credibility_gate_status']);
        self::assertContains('credibility_gate_blocked', $readiness['reason_codes']);
        self::assertContains('critical_metrics_untrusted', $readiness['reason_codes']);
        self::assertFalse($readiness['revenue_analysis_allowed']);
        self::assertFalse($readiness['ai_decision_support_allowed']);
    }

    public function testPhaseOneReadinessAllowsWarningGateWithTrustedCriticalEvidenceAndHumanReview(): void
    {
        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $this->phaseOneReadyMetrics(),
            $this->phaseOneScope()
        );

        self::assertTrue($readiness['ready']);
        self::assertTrue($readiness['revenue_ready']);
        self::assertTrue($readiness['ai_ready']);
        self::assertSame('warning', $readiness['credibility_gate_status']);
        self::assertSame([], $readiness['reason_codes']);
        self::assertTrue($readiness['revenue_analysis_allowed']);
        self::assertTrue($readiness['ai_decision_support_allowed']);
    }

    public function testPhaseOneReadinessKeepsRevenueUsableWhenOnlyAiDecisionUseIsBlocked(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['credibility_gate']['decision_use']['ai_decision_support'] = [
            'allowed' => false,
            'status' => 'blocked_by_governance',
        ];
        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['ready']);
        self::assertTrue($readiness['revenue_ready']);
        self::assertFalse($readiness['ai_ready']);
        self::assertSame([], $readiness['revenue_reason_codes']);
        self::assertContains('ai_decision_support_not_allowed', $readiness['ai_reason_codes']);
        self::assertNotContains('revenue_analysis_not_allowed', $readiness['reason_codes']);
    }

    public function testPhaseOneReadinessFailsClosedWhenExplicitScopeIsMissingOrInvalid(): void
    {
        $service = new OtaDataCredibilityGateService();
        $missingScope = $service->evaluateRevenueAiReadiness($this->phaseOneReadyMetrics());

        self::assertFalse($missingScope['revenue_ready']);
        self::assertFalse($missingScope['ai_ready']);
        self::assertContains('readiness_scope_system_hotel_id_missing', $missingScope['common_reason_codes']);
        self::assertContains('readiness_scope_target_date_missing', $missingScope['common_reason_codes']);
        self::assertContains('readiness_scope_platform_missing', $missingScope['common_reason_codes']);
        self::assertContains('readiness_scope_metric_scope_missing', $missingScope['common_reason_codes']);

        $invalidScope = $service->evaluateRevenueAiReadiness(
            $this->phaseOneReadyMetrics(),
            $this->phaseOneScope(['metric_scope' => 'whole_hotel'])
        );
        self::assertFalse($invalidScope['ready']);
        self::assertContains('readiness_scope_metric_scope_invalid', $invalidScope['common_reason_codes']);

        $wrongGateScopeMetrics = $this->phaseOneReadyMetrics();
        $wrongGateScopeMetrics['credibility_gate']['metric_scope'] = 'whole_hotel';
        $wrongGateScope = $service->evaluateRevenueAiReadiness(
            $wrongGateScopeMetrics,
            $this->phaseOneScope()
        );
        self::assertFalse($wrongGateScope['ready']);
        self::assertContains('credibility_gate_scope_invalid', $wrongGateScope['common_reason_codes']);
    }

    public function testPhaseOneReadinessRejectsMixedHotelEvidence(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['metric_trust']['totals.revenue']['source']['hotels'][] = ['system_hotel_id' => 8];

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['revenue_ready']);
        self::assertFalse($readiness['ai_ready']);
        self::assertContains(
            'critical_metric_hotel_scope_mismatch:totals.revenue',
            $readiness['common_reason_codes']
        );
    }

    public function testPhaseOneReadinessRejectsWrongTargetDateEvidence(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['metric_trust']['totals.room_nights']['source']['date_range'] = [
            'start' => '2026-07-17',
            'end' => '2026-07-17',
        ];

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['ready']);
        self::assertContains(
            'critical_metric_date_scope_mismatch:totals.room_nights',
            $readiness['common_reason_codes']
        );
    }

    public function testPhaseOneReadinessRejectsWrongPlatformEvidence(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['metric_trust']['totals.adr']['source']['platforms'] = ['meituan'];

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['ready']);
        self::assertContains(
            'critical_metric_platform_scope_mismatch:totals.adr',
            $readiness['common_reason_codes']
        );
    }

    public function testPhaseOneReadinessRejectsIncompleteCriticalGateEvidence(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        unset(
            $metrics['credibility_gate']['evidence']['critical_metrics'],
            $metrics['credibility_gate']['evidence']['failed_critical_metrics']
        );

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['revenue_ready']);
        self::assertFalse($readiness['ai_ready']);
        self::assertContains('critical_metrics_evidence_missing', $readiness['common_reason_codes']);
        self::assertContains('failed_critical_metrics_evidence_missing', $readiness['common_reason_codes']);
    }

    public function testPhaseOneReadinessRejectsIncompleteStorageReadbackProof(): void
    {
        $metrics = $this->phaseOneReadyMetrics();
        $metrics['metric_trust']['totals.revenue']['source']['row_count'] = 2;
        $metrics['metric_trust']['totals.revenue']['source']['stored_count'] = 2;
        $metrics['metric_trust']['totals.revenue']['source']['readback_verified_count'] = 1;

        $readiness = (new OtaDataCredibilityGateService())->evaluateRevenueAiReadiness(
            $metrics,
            $this->phaseOneScope()
        );

        self::assertFalse($readiness['ready']);
        self::assertContains(
            'critical_metric_storage_readback_unverified:totals.revenue',
            $readiness['common_reason_codes']
        );
    }

    public function testPhaseOneReadinessRejectsMissingOrNonNumericCriticalMetricValues(): void
    {
        $service = new OtaDataCredibilityGateService();
        $missingRevenue = $this->phaseOneReadyMetrics();
        unset($missingRevenue['totals']['revenue']);

        $missingReadiness = $service->evaluateRevenueAiReadiness(
            $missingRevenue,
            $this->phaseOneScope()
        );

        self::assertFalse($missingReadiness['revenue_ready']);
        self::assertFalse($missingReadiness['ai_ready']);
        self::assertContains(
            'critical_metric_value_missing_or_invalid:totals.revenue',
            $missingReadiness['common_reason_codes']
        );

        $nonNumericAdr = $this->phaseOneReadyMetrics();
        $nonNumericAdr['totals']['adr'] = '120.00';
        $invalidReadiness = $service->evaluateRevenueAiReadiness(
            $nonNumericAdr,
            $this->phaseOneScope()
        );

        self::assertFalse($invalidReadiness['ready']);
        self::assertContains(
            'critical_metric_value_missing_or_invalid:totals.adr',
            $invalidReadiness['common_reason_codes']
        );
    }

    public function testGateBlocksWhenDatasetOrCriticalMetricTrustIsMissing(): void
    {
        self::assertTrue(class_exists(OtaDataCredibilityGateService::class), 'OTA data credibility gate service must exist.');

        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'empty',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 0,
                'rejected_rows' => [
                    ['reason' => 'missing_required_fields', 'fields' => ['source', 'data_date']],
                    ['reason' => 'comment_collection_disabled', 'data_type' => 'review'],
                ],
            ],
        ], [
            'status' => 'empty',
            'metric_trust' => [
                'totals.revenue' => [
                    'saved_success' => false,
                    'failure_reasons' => ['source_missing'],
                ],
            ],
            'data_gaps' => [],
        ]);

        self::assertSame('blocked', $gate['status']);
        self::assertSame('ota_channel', $gate['metric_scope']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertFalse($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertContains('ota_dataset_empty', $gate['reason_codes']);
        self::assertContains('accepted_rows_missing', $gate['reason_codes']);
        self::assertContains('critical_metric_untrusted:totals.revenue', $gate['reason_codes']);
        self::assertSame(2, $gate['evidence']['input_rows']);
        self::assertSame(0, $gate['evidence']['accepted_rows']);
        self::assertSame(2, $gate['evidence']['rejected_rows']);
    }

    public function testRevenueMetricsExposeCredibilityGateWithoutPromotingOtaToInvestmentTruth(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 701,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 1,
                'cancel_room_nights' => 1,
                'lead_time_days' => 8,
                'our_price' => 200.0,
                'competitor_price' => 220.0,
                'price_gap' => -20.0,
                'price_gap_rate' => -9.09,
                'source_trace' => [
                    'row_id' => 701,
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'saved_success' => true,
                    'failure_reasons' => [],
                    'updated_at' => '2026-06-16 10:00:00',
                ],
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertArrayHasKey('credibility_gate', $metrics);
        self::assertSame('ready', $metrics['credibility_gate']['status']);
        self::assertSame('ota_channel', $metrics['credibility_gate']['metric_scope']);
        self::assertTrue($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
        self::assertTrue($metrics['credibility_gate']['decision_use']['ai_decision_support']['allowed']);
        self::assertFalse($metrics['credibility_gate']['decision_use']['investment_decision']['allowed']);
        self::assertContains('whole_hotel_scope_not_proved', $metrics['credibility_gate']['warnings']);
        self::assertSame(['totals.revenue', 'totals.room_nights', 'totals.adr'], $metrics['credibility_gate']['evidence']['critical_metrics']);
    }

    public function testGateDoesNotLetReadyMetricsHideFailedDatasetStatus(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'failed',
            'data_quality' => [
                'input_rows' => 3,
                'accepted_rows' => 3,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [
                ['id' => 1],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ]);

        self::assertSame('blocked', $gate['status']);
        self::assertContains('ota_dataset_failed', $gate['reason_codes']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertFalse($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertSame('failed', $gate['evidence']['dataset_status']);
        self::assertSame('ready', $gate['evidence']['metric_status']);
    }

    public function testGateMarksPartialDatasetAsWarningBeforeAiDecisionUse(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'partial',
            'data_quality' => [
                'input_rows' => 5,
                'accepted_rows' => 3,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ], [
            'whole_hotel_evidence' => true,
        ]);

        self::assertSame('warning', $gate['status']);
        self::assertContains('ota_dataset_partial', $gate['warnings']);
        self::assertTrue($gate['human_review_required']);
        self::assertTrue($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('allowed_with_data_warnings', $gate['decision_use']['revenue_analysis']['status']);
        self::assertTrue($gate['decision_use']['ai_decision_support']['allowed']);
        self::assertSame('allowed_with_human_review', $gate['decision_use']['ai_decision_support']['status']);
        self::assertSame('partial', $gate['evidence']['dataset_status']);
    }

    public function testGateDoesNotInferDataQualityAsReadyWhenQualityEvidenceIsMissing(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate([
            'status' => 'ready',
            'fact_ota_daily' => [
                ['id' => 1],
            ],
        ], [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ], [
            'whole_hotel_evidence' => true,
        ]);

        self::assertSame('warning', $gate['status']);
        self::assertContains('data_quality_missing', $gate['warnings']);
        self::assertTrue($gate['human_review_required']);
        self::assertFalse($gate['evidence']['data_quality_present']);
        self::assertSame(1, $gate['evidence']['fact_rows']);
        self::assertFalse($gate['evidence']['collection_quality']['provided']);
    }

    public function testP0DownstreamGateBlocksDownstreamDecisionUseEvenWhenMetricsLookReady(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'p0_downstream_gate' => [
                'status' => 'blocked_by_p0_ota_gate',
                'current_upstream_status' => 'incomplete',
                'required_upstream_status' => 'ready',
                'scope_policy' => 'ota_channel_gate_before_downstream_claims',
                'blocking_missing_inputs' => ['manual_login_state_verified', 'target_date_traffic_rows'],
                'blocked_stage_keys' => ['revenue_analysis', 'ai_decision_advice', 'operation_closure'],
                'allowed_claims' => ['structure_ready_or_reference_only', 'no_whole_hotel_or_downstream_closure_claim'],
            ],
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 802,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1600.0,
                'room_revenue' => 1600.0,
                'room_nights' => 8.0,
                'source_trace' => [
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ]],
        ]);

        $gate = $metrics['credibility_gate'];
        self::assertSame('blocked', $gate['status']);
        self::assertContains('p0_ota_gate_not_ready', $gate['reason_codes']);
        self::assertContains('p0_ota_gate_missing:manual_login_state_verified', $gate['reason_codes']);
        self::assertFalse($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['revenue_analysis']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['ai_decision_support']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['operation_management']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['decision_use']['investment_decision']['status']);
        self::assertSame('blocked_by_p0_ota_gate', $gate['evidence']['p0_downstream_gate']['status']);
        self::assertFalse($metrics['p1_revenue_closure']['calculation_allowed']);
        self::assertSame('blocked_by_p0_ota_gate', $metrics['p1_revenue_closure']['decision_use']['status']);
    }

    public function testExplicitBlockingCollectionQualityStatesCannotBeOverriddenByReadyRows(): void
    {
        foreach (['stale', 'unverified', 'binding_missing', 'permission_denied', 'collection_failed'] as $qualityState) {
            $gate = (new OtaDataCredibilityGateService())->evaluate(
                $this->readyDatasetWithCollectionQuality($qualityState),
                $this->readyMetrics()
            );

            self::assertSame('blocked', $gate['status'], $qualityState);
            self::assertContains('ota_collection_quality:' . $qualityState, $gate['reason_codes'], $qualityState);
            self::assertFalse($gate['decision_use']['revenue_analysis']['allowed'], $qualityState);
            self::assertSame('blocked_by_collection_quality', $gate['decision_use']['ai_decision_support']['status'], $qualityState);
            self::assertSame($qualityState, $gate['evidence']['collection_quality']['primary_quality_state'], $qualityState);
        }
    }

    public function testPartialCollectionQualityKeepsOnlyWarningLevelDecisionUse(): void
    {
        $gate = (new OtaDataCredibilityGateService())->evaluate(
            $this->readyDatasetWithCollectionQuality('partial', ['target_date_field_facts_partial']),
            $this->readyMetrics()
        );

        self::assertSame('warning', $gate['status']);
        self::assertContains('ota_collection_quality_partial', $gate['warnings']);
        self::assertTrue($gate['decision_use']['revenue_analysis']['allowed']);
        self::assertSame('allowed_with_human_review', $gate['decision_use']['ai_decision_support']['status']);
        self::assertSame(['target_date_field_facts_partial'], $gate['evidence']['collection_quality']['quality_flags']);
    }

    public function testRevenueMetricPathBlocksP1ClosureForExplicitCollectionFailure(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'collection_quality' => [
                'primary_quality_state' => 'collection_failed',
                'quality_flags' => ['snapshot_not_saved'],
                'metric_scope' => 'ota_channel',
            ],
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 901,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'room_nights' => 6.0,
                'source_trace' => [
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ]],
        ]);

        self::assertSame('blocked', $metrics['credibility_gate']['status']);
        self::assertContains('ota_collection_quality:collection_failed', $metrics['credibility_gate']['reason_codes']);
        self::assertSame('blocked_by_collection_quality', $metrics['p1_revenue_closure']['decision_use']['status']);
        self::assertFalse($metrics['p1_revenue_closure']['calculation_allowed']);
    }

    public function testUnknownOrNonOtaCollectionQualityCannotSilentlyPassTheGate(): void
    {
        $unknown = (new OtaDataCredibilityGateService())->evaluate(
            $this->readyDatasetWithCollectionQuality('not_a_quality_state'),
            $this->readyMetrics()
        );
        self::assertSame('blocked', $unknown['status']);
        self::assertContains('ota_collection_quality_state_unknown', $unknown['reason_codes']);
        self::assertSame('blocked_by_collection_quality', $unknown['decision_use']['revenue_analysis']['status']);

        $wrongScope = $this->readyDatasetWithCollectionQuality('available');
        $wrongScope['collection_quality']['metric_scope'] = 'whole_hotel';
        $scopeGate = (new OtaDataCredibilityGateService())->evaluate($wrongScope, $this->readyMetrics());
        self::assertSame('blocked', $scopeGate['status']);
        self::assertContains('ota_collection_quality_scope_invalid', $scopeGate['reason_codes']);
        self::assertSame('blocked_by_collection_quality', $scopeGate['decision_use']['revenue_analysis']['status']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function phaseOneScope(array $overrides = []): array
    {
        return array_replace([
            'system_hotel_id' => 7,
            'target_date' => '2026-07-18',
            'platform' => 'ctrip',
            'metric_scope' => 'ota_channel',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseOneReadyMetrics(): array
    {
        $source = [
            'hotels' => [['system_hotel_id' => 7]],
            'date_range' => [
                'start' => '2026-07-18',
                'end' => '2026-07-18',
            ],
            'platforms' => ['ctrip'],
            'row_count' => 1,
            'stored_count' => 1,
            'readback_verified_count' => 1,
        ];
        $metricTrust = [];
        foreach (['totals.revenue', 'totals.room_nights', 'totals.adr'] as $metricKey) {
            $metricTrust[$metricKey] = [
                'saved_success' => true,
                'failure_reasons' => [],
                'source' => $source,
            ];
        }

        return [
            'status' => 'ready',
            'totals' => [
                'revenue' => 1200.0,
                'room_nights' => 10.0,
                'adr' => 120.0,
            ],
            'metric_trust' => $metricTrust,
            'credibility_gate' => [
                'status' => 'warning',
                'metric_scope' => 'ota_channel',
                'decision_use' => [
                    'revenue_analysis' => ['allowed' => true, 'status' => 'allowed_with_data_warnings'],
                    'ai_decision_support' => ['allowed' => true, 'status' => 'allowed_with_human_review'],
                ],
                'evidence' => [
                    'critical_metrics' => ['totals.revenue', 'totals.room_nights', 'totals.adr'],
                    'failed_critical_metrics' => [],
                ],
            ],
        ];
    }

    /**
     * @param array<int, string> $qualityFlags
     * @return array<string, mixed>
     */
    private function readyDatasetWithCollectionQuality(string $qualityState, array $qualityFlags = []): array
    {
        return [
            'status' => 'ready',
            'collection_quality' => [
                'primary_quality_state' => $qualityState,
                'quality_flags' => $qualityFlags,
                'metric_scope' => 'ota_channel',
                'target_date' => '2026-07-09',
                'data_as_of' => '2026-07-09',
            ],
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [['id' => 1]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readyMetrics(): array
    {
        return [
            'status' => 'ready',
            'metric_trust' => [
                'totals.revenue' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.room_nights' => ['saved_success' => true, 'failure_reasons' => []],
                'totals.adr' => ['saved_success' => true, 'failure_reasons' => []],
            ],
            'data_gaps' => [],
        ];
    }
}
