<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingGoalInterventionMonitorService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingGoalInterventionMonitorServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_goal_monitor_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove operating-goal monitor SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DELETE FROM operation_alerts');
        Db::execute('DELETE FROM operating_goal_monitor_runs');
    }

    public function testMissingGoalBecomesActionablePreviewWithoutAnyExternalWrite(): void
    {
        $service = $this->service($this->overview(null), []);
        $result = $service->monitor(7, 80, '2026-08-12', false);

        self::assertSame('preview', $result['status']);
        self::assertSame('inactive', $result['monitor_state']);
        self::assertSame('goal_contract_missing', $result['signals'][0]['code']);
        self::assertCount(1, $result['alert_candidates']);
        self::assertFalse($result['external_action_triggered']);
        self::assertFalse($result['auto_write_ota']);
        self::assertSame(0, Db::name('operation_alerts')->count());
    }

    public function testVerifiedGuardBreachProducesStopAndRollbackSignal(): void
    {
        $goal = $this->goal();
        $snapshots = [
            'room_revenue:2026-08-12:2026-08-12' => $this->readySnapshot('room_revenue', 1200, 'CNY', '2026-08-12'),
            'occupancy_rate:2026-08-12:2026-08-12' => $this->readySnapshot('occupancy_rate', 35, '%', '2026-08-12'),
        ];
        $service = $this->service($this->overview($goal), $snapshots);
        $result = $service->monitor(7, 80, '2026-08-12', false);

        self::assertSame('attention', $result['monitor_state']);
        self::assertSame('breached', $result['guard_results'][0]['status']);
        $breach = $this->signalByCode($result['signals'], 'guard_metric_breached');
        self::assertTrue($breach['alert']);
        self::assertSame('恢复上一个安全方案', $breach['context']['rollback_plan']);
        self::assertNotEmpty($breach['context']['evidence_refs']);
    }

    public function testDueInterventionIsAutomaticallyJudgedIndeterminateWhenInterferenceAndStopProofAreUnknown(): void
    {
        $goal = $this->goal();
        $intervention = $this->intervention();
        $overview = $this->overview($goal, [$intervention]);
        $snapshots = [
            'room_revenue:2026-08-12:2026-08-12' => $this->readySnapshot('room_revenue', 1200, 'CNY', '2026-08-12'),
            'occupancy_rate:2026-08-12:2026-08-12' => $this->readySnapshot('occupancy_rate', 62, '%', '2026-08-12'),
            'room_revenue:2026-08-04:2026-08-10' => $this->readySnapshot('room_revenue', 110, 'CNY', '2026-08-04', '2026-08-10'),
            'occupancy_rate:2026-08-04:2026-08-10' => $this->readySnapshot('occupancy_rate', 60, '%', '2026-08-04', '2026-08-10'),
        ];
        $service = $this->service($overview, $snapshots, $this->taskBundle());
        $result = $service->monitor(7, 80, '2026-08-12', false);

        self::assertSame('assessment_preview', $result['intervention_states'][0]['status']);
        $assessment = $result['intervention_states'][0]['assessment'];
        self::assertSame('indeterminate', $assessment['verdict']);
        self::assertContains('external_interference_unknown', $assessment['reason_codes']);
        self::assertContains('stop_trigger_status_missing', $assessment['reason_codes']);
        self::assertFalse($assessment['causality_claimed']);
    }

    public function testIndeterminateAssessmentIsRecheckedWhenNewEvidenceMayArrive(): void
    {
        $goal = $this->goal();
        $intervention = $this->intervention();
        $intervention['latest_assessment'] = [
            'id' => 71,
            'verdict' => 'indeterminate',
            'reason_codes' => ['followup_metric_readback_unavailable'],
            'causality_claimed' => false,
        ];
        $snapshots = [
            'room_revenue:2026-08-12:2026-08-12' => $this->readySnapshot('room_revenue', 1200, 'CNY', '2026-08-12'),
            'occupancy_rate:2026-08-12:2026-08-12' => $this->readySnapshot('occupancy_rate', 62, '%', '2026-08-12'),
            'room_revenue:2026-08-04:2026-08-10' => $this->readySnapshot('room_revenue', 110, 'CNY', '2026-08-04', '2026-08-10'),
            'occupancy_rate:2026-08-04:2026-08-10' => $this->readySnapshot('occupancy_rate', 60, '%', '2026-08-04', '2026-08-10'),
        ];

        $result = $this->service(
            $this->overview($goal, [$intervention]),
            $snapshots,
            $this->taskBundle()
        )->monitor(7, 80, '2026-08-12', false);

        self::assertSame('assessment_preview', $result['intervention_states'][0]['status']);
        self::assertSame(71, $result['intervention_states'][0]['previous_assessment']['id']);
        self::assertSame('indeterminate', $result['intervention_states'][0]['assessment']['verdict']);
    }

    public function testExecutePersistsAlertAndHeartbeatIdempotently(): void
    {
        $goal = $this->goal();
        $snapshots = [
            'room_revenue:2026-08-12:2026-08-12' => $this->readySnapshot('room_revenue', 1200, 'CNY', '2026-08-12'),
            'occupancy_rate:2026-08-12:2026-08-12' => $this->readySnapshot('occupancy_rate', 30, '%', '2026-08-12'),
        ];
        $service = $this->service($this->overview($goal), $snapshots);

        $first = $service->monitor(7, 80, '2026-08-12', true);
        $second = $service->monitor(7, 80, '2026-08-12', true);

        self::assertSame('completed', $first['status']);
        self::assertSame('completed', $second['status']);
        self::assertCount(1, $first['persisted_alert_ids']);
        self::assertSame($first['persisted_alert_ids'], $second['persisted_alert_ids']);
        self::assertSame(1, Db::name('operation_alerts')->count());
        self::assertSame(1, Db::name('operating_goal_monitor_runs')->count());
        self::assertSame(
            64,
            strlen((string)Db::name('operation_alerts')->value('monitor_dedupe_key'))
        );
        self::assertFalse($first['monitor_run_receipt']['idempotent']);
        self::assertTrue($second['monitor_run_receipt']['idempotent']);
        self::assertSame(2, $second['monitor_run_receipt']['run_count']);

        $alert = Db::name('operation_alerts')->order('id', 'asc')->find();
        $raw = json_decode((string)$alert['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('alert_only', $raw['execution_bridge_policy']);
        self::assertFalse($raw['auto_write_ota']);
    }

    /** @return array<string,mixed> */
    private function overview(?array $goal, array $interventions = []): array
    {
        return [
            'status' => 'ready',
            'migration_required' => false,
            'missing_tables' => [],
            'tenant_id' => 7,
            'hotel_id' => 80,
            'current_goal_contract' => $goal,
            'history' => $goal === null ? [] : [$goal],
            'interventions' => $interventions,
            'summary' => ['supported' => 0, 'contradicted' => 0, 'indeterminate' => 0, 'unassessed' => count($interventions)],
        ];
    }

    /** @return array<string,mixed> */
    private function goal(): array
    {
        return [
            'id' => 21,
            'tenant_id' => 7,
            'hotel_id' => 80,
            'version_no' => 1,
            'primary_objective' => 'revenue',
            'primary_metric_key' => 'room_revenue',
            'objective_direction' => 'increase',
            'guard_metrics' => [[
                'metric_key' => 'occupancy_rate',
                'operator' => '>=',
                'threshold' => 45,
                'unit' => 'percent',
            ]],
            'stop_conditions' => [[
                'metric_key' => 'occupancy_rate',
                'operator' => '<',
                'threshold' => 25,
            ]],
            'rollback_plan' => '恢复上一个安全方案',
            'effective_from' => '2026-08-01',
            'effective_to' => '2026-08-31',
        ];
    }

    /** @return array<string,mixed> */
    private function intervention(): array
    {
        return [
            'id' => 31,
            'tenant_id' => 7,
            'hotel_id' => 80,
            'intent_id' => 41,
            'goal_contract_id' => 21,
            'goal_contract_version_no' => 1,
            'design_timing' => 'prospective',
            'action_type' => 'price_review',
            'target_metric_key' => 'room_revenue',
            'expected_direction' => 'increase',
            'expected_delta' => 5,
            'expected_delta_unit' => 'absolute',
            'risk_metric_keys' => ['occupancy_rate'],
            'baseline_snapshot' => $this->identitySnapshot('room_revenue', 100, 'CNY', '2026-07-28', '2026-08-03'),
            'observation_window_start' => '2026-08-04',
            'observation_window_end' => '2026-08-10',
            'comparison_mode' => 'same_length_period',
            'minimum_sample_size' => 7,
            'stop_condition' => '保护指标越界时停止',
            'latest_assessment' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function taskBundle(): array
    {
        return [
            'intent' => [
                'id' => 41,
                'tenant_id' => 7,
                'hotel_id' => 80,
                'platform' => 'hotel',
                'object_type' => 'operation_checklist',
                'expected_metric' => 'room_revenue',
            ],
            'task' => [
                'id' => 51,
                'tenant_id' => 7,
                'hotel_id' => 80,
                'intent_id' => 41,
                'status' => 'executed',
                'executed_at' => '2026-08-03 12:00:00',
            ],
            'evidence_rows' => [[
                'id' => 61,
                'task_id' => 51,
                'evidence_type' => 'source_verified_metric_readback',
                'created_by' => 0,
            ]],
            'evidence_truth' => [
                'status' => 'verified',
                'source_verified' => true,
                'operator_attested' => false,
            ],
            'task_ambiguous' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function readySnapshot(
        string $metricKey,
        float $value,
        string $unit,
        string $start,
        ?string $end = null
    ): array {
        return [
            'status' => 'ready',
            'snapshot' => $this->identitySnapshot($metricKey, $value, $unit, $start, $end ?? $start),
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function identitySnapshot(
        string $metricKey,
        float $value,
        string $unit,
        string $start,
        string $end
    ): array {
        return [
            'tenant_id' => 7,
            'hotel_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'hotel',
            'platform_hotel_id' => 'system-80',
            'business_module' => 'operations',
            'subject' => 'hotel',
            'metric_key' => $metricKey,
            'unit' => $unit,
            'source_method' => 'pms_readback',
            'date_role' => 'business_date',
            'fact_scope' => 'whole_hotel_accommodation',
            'period_start' => $start,
            'period_end' => $end,
            'business_date' => $end,
            'captured_at' => $end . ' 23:59:59',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'readback_verified' => true,
            'value' => $value,
            'sample_size' => (int)((strtotime($end) - strtotime($start)) / 86400) + 1,
            'evidence_refs' => ['verified_fact#' . $metricKey . ':' . $start . ':' . $end],
        ];
    }

    private function service(array $overview, array $snapshots, array $taskBundle = []): OperatingGoalInterventionMonitorService
    {
        $goalService = new class($overview) {
            public function __construct(private array $overviewResult) {}
            public function overview(int $tenantId, array $hotelIds, int $hotelId): array
            {
                return $this->overviewResult;
            }
            public function createAutomatedAssessmentForTask(
                int $tenantId,
                array $hotelIds,
                int $hotelId,
                int $taskId,
                array $input
            ): array {
                return [
                    'id' => 91,
                    'verdict' => 'indeterminate',
                    'reason_codes' => ['external_interference_unknown'],
                    'stop_triggered' => false,
                    'causality_claimed' => false,
                    'content_digest' => str_repeat('a', 64),
                    'db_readback_verified' => true,
                ];
            }
        };
        $snapshotService = new class($snapshots) {
            public function __construct(private array $snapshots) {}
            public function snapshot(
                int $tenantId,
                int $hotelId,
                string $metricKey,
                string $periodStart,
                string $periodEnd,
                array $context = []
            ): array {
                return $this->snapshots[$metricKey . ':' . $periodStart . ':' . $periodEnd]
                    ?? [
                        'status' => 'unavailable',
                        'snapshot' => null,
                        'data_gaps' => ['fixture_metric_missing:' . $metricKey],
                    ];
            }
        };
        $loader = static fn(int $tenantId, int $hotelId, int $intentId): array => $taskBundle;

        return new OperatingGoalInterventionMonitorService(
            $goalService,
            $snapshotService,
            $loader,
            null,
            static fn(): string => '2026-08-12 09:00:00'
        );
    }

    /** @param array<int,array<string,mixed>> $signals @return array<string,mixed> */
    private function signalByCode(array $signals, string $code): array
    {
        foreach ($signals as $signal) {
            if (($signal['code'] ?? '') === $code) {
                return $signal;
            }
        }
        self::fail('Signal not found: ' . $code);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE operation_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            alert_type TEXT NOT NULL,
            monitor_dedupe_key TEXT UNIQUE,
            level TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            source TEXT NOT NULL,
            status TEXT NOT NULL,
            related_date TEXT,
            raw_data TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            deleted_at TEXT
        )');
        Db::execute('CREATE TABLE operating_goal_monitor_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            goal_contract_id INTEGER NOT NULL,
            goal_contract_version_no INTEGER NOT NULL,
            monitor_state TEXT NOT NULL,
            primary_snapshot_json TEXT NOT NULL,
            guard_results_json TEXT NOT NULL,
            intervention_states_json TEXT NOT NULL,
            signal_codes_json TEXT NOT NULL,
            data_gaps_json TEXT NOT NULL,
            content_digest TEXT NOT NULL,
            first_observed_at TEXT NOT NULL,
            last_observed_at TEXT NOT NULL,
            run_count INTEGER NOT NULL,
            alert_count INTEGER NOT NULL,
            assessment_count INTEGER NOT NULL,
            UNIQUE(tenant_id, hotel_id, business_date, content_digest)
        )');
    }
}
