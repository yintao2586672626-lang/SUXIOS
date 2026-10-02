<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentDecisionSupportService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class InvestmentDecisionSupportServiceTest extends TestCase
{
    public function testInvestmentOverviewRequiresAndForwardsAnExplicitBusinessDate(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/InvestmentDecision.php');
        $service = (string)file_get_contents(dirname(__DIR__) . '/app/service/InvestmentDecisionSupportService.php');

        self::assertStringContainsString("param('business_date', '')", $controller);
        self::assertStringContainsString('business_date 必须是明确的 YYYY-MM-DD 业务日期', $controller);
        self::assertMatchesRegularExpression(
            '/BusinessClosureOverviewService\(\)\)->overview\([\s\S]*?\$businessDate\s*\)/',
            $service
        );
    }

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
        self::assertSame('operating_loop_kernel.completed + operation_execution.roi_ready + decision_record.readiness_ready', $overview['business_closure_chain']['judgement_gate']);
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
        self::assertSame('p0_ota_field_loop.ready + operating_loop_kernel.completed + operation_execution.roi_ready', $overview['operating_data_gate']['required_gate']);
        self::assertContains('p0_ota_gate_not_ready', array_column($overview['operating_data_gate']['missing_evidence'], 'code'));
        self::assertSame('not_closed', $overview['business_closure_chain']['status']);
        self::assertSame('p0_ota_field_loop.ready + operating_loop_kernel.completed + operation_execution.roi_ready + decision_record.readiness_ready', $overview['business_closure_chain']['judgement_gate']);
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

    public function testLocalModuleRoiCannotAuthorizeInvestmentWithoutCompletedKernel(): void
    {
        $closure = $this->closureOverview(true);
        unset($closure['operating_loop']);

        $overview = (new InvestmentDecisionSupportService())->buildOverviewFromEvidence(
            $closure,
            ['status' => 'ok', 'records' => [$this->projectReadyRecord('expansion', 10)]],
            [],
            [],
            ['status' => 'ok', 'sample_count' => 2, 'decision_eligible_sample_count' => 2]
        );

        self::assertFalse($overview['summary']['decision_allowed']);
        self::assertSame('blocked_by_operating_loop_kernel', $overview['operating_data_gate']['status']);
        self::assertContains(
            'operating_loop_kernel_not_completed',
            array_column($overview['operating_data_gate']['missing_evidence'], 'code')
        );
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

    public function testExistingDecisionSourcesWithBrokenDependenciesReportReadFailureAndRecover(): void
    {
        $this->withSyntheticInvestmentDatabase(function (): void {
            $service = new InvestmentDecisionSupportService();
            foreach ([
                ['expansion_records', 'readExpansionRecords', [3, false]],
                ['transfer_records', 'readTransferRecords', [[7], 7]],
                ['feasibility_reports', 'readFeasibilityRecords', [3, false]],
            ] as [$table, $reader, $arguments]) {
                $method = new \ReflectionMethod($service, $reader);
                $missing = $method->invokeArgs($service, $arguments);
                self::assertSame('missing_table', $missing['status']);
                self::assertSame($table . '_missing', $missing['data_gaps'][0]['code']);

                Db::execute('CREATE VIEW "' . $table . '" AS SELECT * FROM "synthetic_missing_dependency"');
                $failed = $method->invokeArgs($service, $arguments);
                self::assertSame('read_failed', $failed['status'], $table . ' exists but cannot be read');
                self::assertSame($table . '_read_failed', $failed['data_gaps'][0]['code']);
                self::assertStringNotContainsString('synthetic_missing_dependency', json_encode($failed));

                $evidence = [[], [], []];
                $index = array_search($table, ['expansion_records', 'transfer_records', 'feasibility_reports'], true);
                $evidence[$index] = $failed;
                $overview = $service->buildOverviewFromEvidence($this->closureOverview(true), ...$evidence);
                self::assertFalse($overview['summary']['decision_allowed']);
                self::assertContains($table . '_read_failed', array_column($overview['sections']['investment_calculation']['missing_evidence'], 'code'));
                self::assertContains('investment_calculation_gap', array_column($overview['sections']['risk_alerts']['items'], 'code'));

                Db::execute('DROP VIEW "' . $table . '"');
                Db::execute('CREATE TABLE "' . $table . '" (id INTEGER PRIMARY KEY, hotel_id INTEGER, created_by INTEGER, deleted_at TEXT)');
                $empty = $method->invokeArgs($service, $arguments);
                self::assertSame('ok', $empty['status']);
                self::assertSame([], $empty['records'], 'An actually empty readable source is distinct from a failed read');
            }
        });
    }

    public function testBrokenCompetitorSourceStaysReadFailedWhileLegacyRowsStayScopedReferenceOnly(): void
    {
        $this->withSyntheticInvestmentDatabase(function (): void {
            $service = new InvestmentDecisionSupportService();
            $reader = new \ReflectionMethod($service, 'readCompetitorEvidence');
            Db::execute('CREATE VIEW competitor_analysis AS SELECT * FROM synthetic_missing_dependency');
            Db::execute('CREATE TABLE competitor_price_log (id INTEGER PRIMARY KEY, store_id INTEGER, hotel_id INTEGER, platform TEXT, price REAL, fetch_time TEXT)');
            Db::name('competitor_price_log')->insertAll([
                ['id' => 1, 'store_id' => 7, 'hotel_id' => 700, 'platform' => 'ctrip', 'price' => 300, 'fetch_time' => '2026-09-14 12:00:00'],
                ['id' => 2, 'store_id' => 8, 'hotel_id' => 800, 'platform' => 'ctrip', 'price' => 600, 'fetch_time' => '2026-09-15 12:00:00'],
            ]);
            $evidence = $reader->invoke($service, [7], 7);
            self::assertSame('read_failed', $evidence['table_statuses']['competitor_analysis']);
            self::assertSame('reference_only', $evidence['table_statuses']['competitor_price_log']);
            self::assertSame('missing_table', $evidence['table_statuses']['online_daily_data']);
            self::assertSame('reference_only', $evidence['status']);
            self::assertSame(0, $evidence['decision_eligible_sample_count']);
            self::assertSame(1, $evidence['reference_only_sample_count']);
            self::assertSame(1, $evidence['visible_sample_count']);
            self::assertSame('2026-09-14 12:00:00', $evidence['reference_latest_at']);
            self::assertContains('competitor_analysis_read_failed', array_column($evidence['data_gaps'], 'code'));
            self::assertStringNotContainsString('synthetic_missing_dependency', json_encode($evidence));
            $overview = $service->buildOverviewFromEvidence($this->closureOverview(true), [], [], [], $evidence);
            self::assertFalse($overview['sections']['competitor_comparison']['decision_allowed']);
            self::assertContains('competitor_analysis_read_failed', array_column($overview['sections']['competitor_comparison']['missing_evidence'], 'code'));
        });
    }

    private function withSyntheticInvestmentDatabase(callable $test): void
    {
        $original = ['database' => Config::get('database', []), 'cache' => Config::get('cache', []), 'log' => Config::get('log', [])];
        $connection = 'investment_synthetic_' . bin2hex(random_bytes(4));
        $testPath = sys_get_temp_dir() . '/suxios-investment-test/' . $connection . '/';
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $testPath . 'cache/']]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $testPath . 'log/']]], 'log');
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        try {
            $test();
        } finally {
            Db::connect()->close();
            foreach ($original as $key => $value) Config::set($value, $key);
        }
    }

    public function testLatestCompetitorDatePreservesTimeAndExistingQueryScope(): void
    {
        $this->withSyntheticInvestmentDatabase(function (): void {
            Db::execute('CREATE TABLE synthetic_observations (id INTEGER PRIMARY KEY, hotel_id INTEGER, collected_at TEXT, data_date TEXT)');
            Db::name('synthetic_observations')->insertAll([
                ['id' => 1, 'hotel_id' => 7, 'collected_at' => '2026-09-14 09:00:00', 'data_date' => '2026-09-14'],
                ['id' => 2, 'hotel_id' => 7, 'collected_at' => '2026-09-14 18:30:00', 'data_date' => '2026-09-14'],
                ['id' => 3, 'hotel_id' => 8, 'collected_at' => '2027-01-01 12:00:00', 'data_date' => '2027-01-01'],
            ]);
            $service = new InvestmentDecisionSupportService();
            $method = new \ReflectionMethod($service, 'latestQueryValue');
            $query = Db::name('synthetic_observations')->where('hotel_id', 7);
            $columns = ['collected_at' => true, 'data_date' => true];
            self::assertSame('2026-09-14 18:30:00', $method->invoke($service, $query, ['collected_at'], $columns));
            self::assertSame('2026-09-14', $method->invoke($service, $query, ['legacy_missing_column', 'data_date'], $columns));
            self::assertSame('', $method->invoke($service, Db::name('synthetic_observations')->where('hotel_id', 9), ['collected_at'], $columns));
            self::assertSame(2, (clone $query)->count());
        });
    }

    public function testMissingSourceClassificationRequiresTheNamedTableNotOnlySqlState(): void
    {
        $service = new InvestmentDecisionSupportService();
        $method = new \ReflectionMethod($service, 'isMissingTableException');
        $missing = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'synthetic.expansion_records' doesn't exist");
        self::assertTrue($method->invoke($service, $missing, 'expansion_records'));
        self::assertTrue($method->invoke($service, new \RuntimeException('Synthetic wrapped query error', 0, $missing), 'expansion_records'));
        self::assertFalse($method->invoke($service, new \PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'synthetic.dependency' doesn't exist"), 'expansion_records'));
        self::assertFalse($method->invoke($service, new \PDOException('SQLSTATE[42000]: SELECT command denied for table expansion_records'), 'expansion_records'));
        self::assertFalse($method->invoke($service, new \RuntimeException('Synthetic connection timeout'), 'expansion_records'));
    }

    public function testVerifiedCompetitorDateAndSourceReadFailureReachOverviewDisplayFields(): void
    {
        $this->withSyntheticInvestmentDatabase(function (): void {
            Db::execute('CREATE TABLE competitor_price_log (id INTEGER PRIMARY KEY, store_id INTEGER, hotel_id INTEGER, platform TEXT, price REAL,
                collected_at TEXT, source_method TEXT, source_ref TEXT, validation_status TEXT, readback_verified INTEGER,
                check_in_date TEXT, check_out_date TEXT, nights INTEGER, adults INTEGER, children INTEGER,
                room_type_key TEXT, rate_plan_key TEXT, breakfast TEXT, cancellation_policy TEXT, payment_mode TEXT,
                tax_fee_included INTEGER, price_basis TEXT, currency TEXT, availability TEXT, comparison_key TEXT)');
            $row = ['id' => 1, 'store_id' => 7, 'hotel_id' => 700, 'platform' => 'ctrip', 'price' => 300,
                'collected_at' => '2026-09-14 18:30:00', 'source_method' => 'synthetic', 'source_ref' => 'synthetic:rate-1',
                'validation_status' => 'verified', 'readback_verified' => 1, 'check_in_date' => '2026-09-15', 'check_out_date' => '2026-09-16',
                'nights' => 1, 'adults' => 2, 'children' => 0, 'room_type_key' => 'standard', 'rate_plan_key' => 'public',
                'breakfast' => 'none', 'cancellation_policy' => 'nonrefundable', 'payment_mode' => 'prepaid',
                'tax_fee_included' => 1, 'price_basis' => 'per_room_per_night', 'currency' => 'CNY', 'availability' => 'bookable', 'comparison_key' => 'synthetic:comparison-1'];
            Db::name('competitor_price_log')->insertAll([$row,
                array_replace($row, ['id' => 2, 'store_id' => 8, 'collected_at' => '2027-01-01 12:00:00']),
                array_replace($row, ['id' => 3, 'readback_verified' => 0, 'collected_at' => '2026-09-15 12:00:00']),
            ]);
            Db::execute('CREATE VIEW competitor_analysis AS SELECT * FROM synthetic_missing_dependency');
            $service = new InvestmentDecisionSupportService();
            $reader = new \ReflectionMethod($service, 'readCompetitorEvidence');
            $evidence = $reader->invoke($service, [7], 7);
            $overview = $service->buildOverviewFromEvidence($this->closureOverview(true), [], [], [], $evidence);
            $comparison = $overview['sections']['competitor_comparison'];
            self::assertSame('2026-09-14 18:30:00', $comparison['latest_at']);
            self::assertSame(1, $comparison['decision_eligible_sample_count']);
            self::assertSame(1, $comparison['reference_only_sample_count']);
            self::assertSame('read_failed', $comparison['table_statuses']['competitor_analysis']);
            self::assertContains('competitor_analysis_read_failed', array_column($overview['action_queue']['items'], 'evidence_code'));
            $risk = array_values(array_filter($overview['sections']['risk_alerts']['items'], static fn(array $item): bool => $item['title'] === 'competitor_analysis_read_failed'));
            self::assertSame('competitor_analysis summary read failed.', $risk[0]['next_action']);
        });
    }

    private function closureOverview(bool $roiReady): array
    {
        return [
            'summary' => [
                'operation_execution_total' => 3,
                'operation_roi_ready' => $roiReady ? 1 : 0,
            ],
            'operating_loop' => [
                'authoritative_state' => 'completed',
                'readback_verified' => true,
                'kernel_id' => 'cycle-1',
                'revision' => 8,
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
