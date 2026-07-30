<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentDecisionSupportService;
use PHPUnit\Framework\TestCase;

final class InvestmentDecisionSupportServiceTest extends TestCase
{
    public function testInvestmentDecisionBlocksReadyRecordsWithoutClosedOperatingRoi(): void
    {
        $service = new InvestmentDecisionSupportService();

        $overview = $service->buildOverviewFromEvidence(
            $this->closureOverview(false),
            [
                'status' => 'ok',
                'records' => [
                    $this->projectReadyRecord('expansion', 10),
                ],
            ],
            [],
            [],
            ['status' => 'ok', 'sample_count' => 2, 'decision_eligible_sample_count' => 2, 'data_sources' => [['table' => 'competitor_analysis', 'count' => 2]]]
        );

        self::assertSame('not_ready', $overview['summary']['status']);
        self::assertFalse($overview['summary']['decision_allowed']);
        self::assertFalse($overview['operating_data_gate']['can_use_for_investment_judgement']);
        self::assertSame('blocked', $overview['sections']['single_store_quality']['status']);
        self::assertSame('blocked_by_operating_closure', $overview['sections']['investment_calculation']['status']);
        self::assertSame(1, $overview['sections']['decision_records']['record_count']);
        self::assertSame(0, $overview['sections']['decision_records']['eligible_count']);
        self::assertSame('closed_operating_data_missing', $overview['sections']['decision_records']['records'][0]['blocked_reason']);
        self::assertGreaterThanOrEqual(1, $overview['sections']['risk_alerts']['blocking_count']);
        self::assertSame('closed_operating_data_missing', $overview['sections']['risk_alerts']['items'][0]['code']);
        self::assertSame('not_closed', $overview['business_closure_chain']['status']);
        self::assertSame(
            ['ota_data', 'revenue_analysis', 'ai_decision', 'operation_management', 'investment_decision'],
            array_column($overview['business_closure_chain']['stages'], 'key')
        );
        self::assertTrue($overview['business_closure_chain']['stages'][3]['blocking']);
        self::assertSame('operation_execution.roi_ready + decision_record.readiness_ready', $overview['business_closure_chain']['judgement_gate']);
        self::assertSame('has_action', $overview['action_queue']['status']);
        self::assertGreaterThanOrEqual(1, $overview['action_queue']['blocking_count']);
        self::assertGreaterThanOrEqual(2, $overview['action_queue']['item_count']);
        self::assertSame(1, $overview['action_queue']['items'][0]['priority']);
        self::assertTrue($overview['action_queue']['items'][0]['blocking']);
    }

    public function testInvestmentDecisionAllowsJudgementOnlyAfterOperatingRoiAndReadiness(): void
    {
        $service = new InvestmentDecisionSupportService();

        $overview = $service->buildOverviewFromEvidence(
            $this->closureOverview(true),
            [
                'status' => 'ok',
                'records' => [
                    $this->projectReadyRecord('expansion', 10),
                ],
            ],
            [
                'status' => 'ok',
                'records' => [
                    $this->decisionReadyRecord('transfer', 20),
                ],
            ],
            [],
            ['status' => 'ok', 'sample_count' => 3, 'decision_eligible_sample_count' => 3, 'data_sources' => [['table' => 'competitor_analysis', 'count' => 3]]]
        );

        self::assertSame('decision_ready', $overview['summary']['status']);
        self::assertTrue($overview['summary']['decision_allowed']);
        self::assertSame('usable', $overview['sections']['single_store_quality']['status']);
        self::assertSame('usable', $overview['sections']['competitor_comparison']['status']);
        self::assertSame('calculation_ready', $overview['sections']['investment_calculation']['status']);
        self::assertSame(2, $overview['sections']['decision_records']['record_count']);
        self::assertSame(2, $overview['sections']['decision_records']['eligible_count']);
        self::assertSame(0, $overview['sections']['risk_alerts']['blocking_count']);
        self::assertSame('closed_operating_data_only', $overview['source_scope']);
        self::assertSame('closed', $overview['business_closure_chain']['status']);
        self::assertSame(5, $overview['business_closure_chain']['closed_stage_count']);
        self::assertSame(0, $overview['business_closure_chain']['blocking_count']);
        self::assertSame('业务闭环拆解', $overview['business_closure_chain']['title']);
        self::assertSame('clear', $overview['action_queue']['status']);
        self::assertSame(0, $overview['action_queue']['item_count']);
    }

    public function testP0GateBlocksInvestmentJudgementEvenWhenOperatingRoiAndRecordsLookReady(): void
    {
        $service = new InvestmentDecisionSupportService();
        $closure = $this->closureOverview(true);
        $closure['p0_downstream_gate'] = [
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => 'incomplete',
            'blocking_missing_inputs' => ['p0_field_loop_verifier_ready'],
        ];

        $overview = $service->buildOverviewFromEvidence(
            $closure,
            [
                'status' => 'ok',
                'records' => [
                    $this->projectReadyRecord('expansion', 10),
                ],
            ],
            [
                'status' => 'ok',
                'records' => [
                    $this->decisionReadyRecord('transfer', 20),
                ],
            ],
            [],
            ['status' => 'ok', 'sample_count' => 3, 'decision_eligible_sample_count' => 3, 'data_sources' => [['table' => 'competitor_analysis', 'count' => 3]]]
        );

        self::assertSame('not_ready', $overview['summary']['status']);
        self::assertFalse($overview['summary']['decision_allowed']);
        self::assertSame('blocked_by_p0_ota_gate', $overview['operating_data_gate']['status']);
        self::assertFalse($overview['operating_data_gate']['can_use_for_investment_judgement']);
        self::assertSame('p0_ota_field_loop.ready + operation_execution.roi_ready', $overview['operating_data_gate']['required_gate']);
        self::assertContains('p0_ota_gate_not_ready', array_column($overview['operating_data_gate']['missing_evidence'], 'code'));
        self::assertSame('not_closed', $overview['business_closure_chain']['status']);
        self::assertSame('p0_ota_field_loop.ready + operation_execution.roi_ready + decision_record.readiness_ready', $overview['business_closure_chain']['judgement_gate']);
        self::assertSame('blocked_by_p0_ota_gate', $overview['business_closure_chain']['stages'][1]['status']);
        self::assertSame('blocked_by_p0_ota_gate', $overview['business_closure_chain']['stages'][2]['status']);
        self::assertSame('blocked_by_p0_ota_gate', $overview['business_closure_chain']['stages'][3]['status']);
        self::assertTrue($overview['business_closure_chain']['stages'][3]['blocking']);
        self::assertSame('closed_operating_data_missing', $overview['sections']['decision_records']['records'][0]['blocked_reason']);
        self::assertContains('p0_ota_gate_not_ready', array_column($overview['action_queue']['items'], 'evidence_code'));
    }

    public function testManualOnlyCompetitorEvidenceRemainsSupportingOnly(): void
    {
        $service = new InvestmentDecisionSupportService();
        $closure = $this->closureOverview(true);
        foreach ($closure['modules'] as &$module) {
            if (($module['key'] ?? '') === 'revenue_pricing') {
                $module['roi_ready'] = false;
                $module['roi_ready_count'] = 0;
                $module['status'] = 'reviewed_no_roi';
            }
        }
        unset($module);

        $overview = $service->buildOverviewFromEvidence(
            $closure,
            [],
            [],
            [],
            ['status' => 'ok', 'sample_count' => 1, 'decision_eligible_sample_count' => 1, 'data_sources' => [['table' => 'competitor_analysis', 'count' => 1]]]
        );

        self::assertSame('supporting_only', $overview['sections']['competitor_comparison']['status']);
        self::assertFalse($overview['sections']['competitor_comparison']['decision_allowed']);
        self::assertContains('competitor_to_pricing_roi_missing', array_column($overview['sections']['competitor_comparison']['missing_evidence'], 'code'));
        self::assertContains('competitor_to_pricing_roi_missing', array_column($overview['action_queue']['items'], 'evidence_code'));
        self::assertSame('has_action', $overview['action_queue']['status']);
    }

    public function testLegacyRawCompetitorCountsRemainReferenceOnlyAtInvestmentBoundary(): void
    {
        $overview = (new InvestmentDecisionSupportService())->buildOverviewFromEvidence(
            $this->closureOverview(true),
            [],
            [],
            [],
            [
                'status' => 'reference_only',
                'sample_count' => 8,
                'decision_eligible_sample_count' => 0,
                'reference_only_sample_count' => 8,
                'visible_sample_count' => 8,
                'data_sources' => [[
                    'table' => 'competitor_price_log',
                    'visible_count' => 8,
                    'decision_eligible_count' => 0,
                    'reference_only_count' => 8,
                ]],
            ]
        );

        $comparison = $overview['sections']['competitor_comparison'];
        self::assertSame('reference_only', $comparison['status']);
        self::assertFalse($comparison['decision_allowed']);
        self::assertSame(0, $comparison['sample_count']);
        self::assertSame(0, $comparison['decision_eligible_sample_count']);
        self::assertSame(8, $comparison['reference_only_sample_count']);
        self::assertContains(
            'competitor_decision_eligible_sample_missing',
            array_column($comparison['missing_evidence'], 'code')
        );
        self::assertContains('competitor_reference_only', array_column($comparison['missing_evidence'], 'code'));
    }

    public function testDecisionEligibilityContractsRequireCompleteComparableVerifiedBookableRates(): void
    {
        $service = new InvestmentDecisionSupportService();
        $method = new \ReflectionMethod($service, 'competitorDecisionEligibilityContract');
        $method->setAccessible(true);

        $priceLog = $method->invoke($service, 'competitor_price_log');
        $analysis = $method->invoke($service, 'competitor_analysis');

        foreach ([$priceLog, $analysis] as $contract) {
            foreach ([
                'collected_at', 'source_method', 'source_ref', 'validation_status', 'readback_verified',
                'check_in_date', 'check_out_date', 'nights', 'adults', 'children', 'room_type_key',
                'rate_plan_key', 'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included',
                'price_basis', 'currency', 'availability', 'comparison_key',
            ] as $field) {
                self::assertContains($field, $contract['required_columns']);
            }
            self::assertSame(1, $contract['equals']['readback_verified']);
            self::assertSame(['available', 'bookable'], $contract['allowed']['availability']);
            self::assertContains('comparison_key', $contract['non_empty']);
            self::assertContains(['check_out_date', '>', 'check_in_date'], $contract['column_comparisons']);
        }

        self::assertContains('store_id', $priceLog['required_columns']);
        self::assertContains('price', $priceLog['positive']);
        self::assertContains('our_price', $analysis['positive']);
        self::assertContains('competitor_price', $analysis['positive']);
    }

    public function testEligibilityQueryFailsClosedForLegacyColumnsAndAppliesStrictGateForCompleteSchema(): void
    {
        $service = new InvestmentDecisionSupportService();
        $contractMethod = new \ReflectionMethod($service, 'competitorDecisionEligibilityContract');
        $contractMethod->setAccessible(true);
        $filterMethod = new \ReflectionMethod($service, 'applyCompetitorDecisionEligibilityFilters');
        $filterMethod->setAccessible(true);

        $legacyQuery = new InvestmentCompetitorEvidenceRecordingQuery();
        $legacyMissing = $filterMethod->invoke($service, $legacyQuery, 'competitor_price_log', [
            'store_id' => true,
            'hotel_id' => true,
            'platform' => true,
            'price' => true,
            'fetch_time' => true,
            'create_time' => true,
        ]);
        self::assertContains('comparison_key', $legacyMissing);
        self::assertContains('readback_verified', $legacyMissing);
        self::assertSame([], $legacyQuery->calls, 'Legacy/missing-column tables must remain reference-only.');

        $contract = $contractMethod->invoke($service, 'competitor_price_log');
        $completeColumns = array_fill_keys($contract['required_columns'], true);
        $completeQuery = new InvestmentCompetitorEvidenceRecordingQuery();
        $completeMissing = $filterMethod->invoke(
            $service,
            $completeQuery,
            'competitor_price_log',
            $completeColumns
        );

        self::assertSame([], $completeMissing);
        self::assertContains(['where', ['readback_verified', 1]], $completeQuery->calls);
        self::assertContains(['whereIn', ['availability', ['available', 'bookable']]], $completeQuery->calls);
        self::assertContains(['where', ['comparison_key', '<>', '']], $completeQuery->calls);
        self::assertContains(['where', ['price', '>', 0]], $completeQuery->calls);
        self::assertContains(
            ['whereColumn', ['check_out_date', '>', 'check_in_date']],
            $completeQuery->calls
        );
    }

    private function closureOverview(bool $roiReady): array
    {
        return [
            'summary' => [
                'operation_execution_total' => 3,
                'operation_roi_ready' => $roiReady ? 1 : 0,
            ],
            'modules' => [
                [
                    'key' => 'ai_daily_report',
                    'label' => 'AI经营日报 / AI决策',
                    'status' => 'reviewed_no_roi',
                    'status_label' => '已复盘缺效果证据',
                    'record_count' => 2,
                    'process_closed_loop' => true,
                    'roi_ready' => false,
                    'roi_ready_count' => 0,
                    'source_scope' => 'OTA and operation-report scope',
                ],
                [
                    'key' => 'revenue_pricing',
                    'label' => '收益调价建议',
                    'status' => $roiReady ? 'roi_ready' : 'reviewed_no_roi',
                    'status_label' => $roiReady ? '已闭环' : '已复盘缺效果证据',
                    'record_count' => 2,
                    'process_closed_loop' => true,
                    'roi_ready' => $roiReady,
                    'roi_ready_count' => $roiReady ? 1 : 0,
                    'source_scope' => 'local price suggestion records',
                ],
                [
                    'key' => 'operation_execution',
                    'label' => '运营执行闭环',
                    'status' => $roiReady ? 'roi_ready' : 'reviewed_no_roi',
                    'status_label' => $roiReady ? '已闭环' : '已复盘缺效果证据',
                    'record_count' => 3,
                    'linked_execution_count' => 3,
                    'process_closed_loop' => true,
                    'roi_ready' => $roiReady,
                    'roi_ready_count' => $roiReady ? 1 : 0,
                    'source_scope' => 'execution_intents_tasks_evidence_roi',
                ],
            ],
            'data_gaps' => [],
        ];
    }

    private function projectReadyRecord(string $sourceModule, int $id): array
    {
        return [
            'source_module' => $sourceModule,
            'id' => $id,
            'record_type' => 'collaboration',
            'title' => '虹桥扩张项目',
            'decision' => '可推进',
            'risk_level' => '中风险',
            'readiness' => [
                'stage' => 'project_ready',
                'status_label' => '可立项复核',
                'score' => 100,
                'project_ready' => true,
                'source_scope' => 'expansion_screening_and_project_decision',
                'missing_evidence' => [],
            ],
            'updated_at' => '2026-06-20 10:00:00',
        ];
    }

    private function decisionReadyRecord(string $sourceModule, int $id): array
    {
        return [
            'source_module' => $sourceModule,
            'id' => $id,
            'record_type' => 'dashboard',
            'title' => '虹桥样板店转让',
            'decision' => '谨慎挂牌',
            'risk_level' => 'medium',
            'readiness' => [
                'stage' => 'decision_ready',
                'status_label' => '可投决复核',
                'score' => 100,
                'decision_ready' => true,
                'source_scope' => 'transfer_decision_scope',
                'missing_evidence' => [],
            ],
            'updated_at' => '2026-06-21 10:00:00',
        ];
    }
}

final class InvestmentCompetitorEvidenceRecordingQuery
{
    /** @var array<int, array{0:string,1:array<int, mixed>}> */
    public array $calls = [];

    public function where(mixed ...$arguments): self
    {
        $this->calls[] = ['where', $arguments];
        return $this;
    }

    public function whereIn(mixed ...$arguments): self
    {
        $this->calls[] = ['whereIn', $arguments];
        return $this;
    }

    public function whereNotNull(mixed ...$arguments): self
    {
        $this->calls[] = ['whereNotNull', $arguments];
        return $this;
    }

    public function whereColumn(mixed ...$arguments): self
    {
        $this->calls[] = ['whereColumn', $arguments];
        return $this;
    }
}
