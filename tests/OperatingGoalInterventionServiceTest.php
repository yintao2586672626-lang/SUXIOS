<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingGoalInterventionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingGoalInterventionServiceTest extends TestCase
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
            . 'operating_goal_intervention_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove operating-goal SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'operation_intervention_assessments',
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_intervention_contracts',
            'operation_execution_intents',
            'hotel_operating_goal_contracts',
            'hotels',
        ] as $table) {
            Db::execute('DELETE FROM `' . $table . '`');
        }
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7],
            ['id' => 81, 'tenant_id' => 8],
        ]);
    }

    public function testGoalVersionsAreAppendOnlyAndSameContentIsIdempotent(): void
    {
        $service = $this->service();
        $v1 = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        $replayInput = $this->goalInput();
        $replayInput['version_note'] = 'same contract, retried';
        $replay = $service->createGoalContract(7, [80], 80, $replayInput, 12);
        $v2Input = $this->goalInput();
        $v2Input['risk_preference'] = 'aggressive';
        $v2Input['phase_note'] = '第二阶段扩大验证样本';
        $v2 = $service->createGoalContract(7, [80], 80, $v2Input, 11);

        self::assertSame(1, $v1['version_no']);
        self::assertFalse($v1['idempotent']);
        self::assertTrue($v1['db_readback_verified']);
        self::assertSame($v1['id'], $replay['id']);
        self::assertSame($v1['content_digest'], $replay['content_digest']);
        self::assertTrue($replay['idempotent']);
        self::assertSame(2, $v2['version_no']);
        self::assertNotSame($v1['content_digest'], $v2['content_digest']);
        self::assertSame(2, Db::name('hotel_operating_goal_contracts')->count());

        $overview = $service->overview(0, [], 80);
        self::assertSame('ready', $overview['status']);
        self::assertSame(7, $overview['tenant_id']);
        self::assertSame($v2['id'], $overview['current_goal_contract']['id']);
        self::assertSame(2, count($overview['history']));
        self::assertSame(['channels', 'room_types'], array_column(
            $v1['operating_constraints'],
            'constraint_key'
        ));
        self::assertSame(['ctrip', 'meituan'], $v1['operating_constraints'][0]['value']);
    }

    public function testGoalWriteRejectsHotelTransferredAfterScopeResolution(): void
    {
        $service = $this->service();
        $input = $this->goalInput();
        $tenantId = (int)$this->invokePrivate($service, 'resolveScope', [7, [80], 80]);
        $content = $this->invokePrivate($service, 'normalizeGoalContent', [$tenantId, 80, $input]);
        self::assertIsArray($content);
        $digest = (string)$this->invokePrivate($service, 'digest', [$content]);

        // Controlled TOCTOU: the initial scope read completed, then another DB
        // connection moves the hotel before the persistence transaction begins.
        $this->transferHotelWithSeparateConnection(80, 8);
        try {
            $this->invokePrivate($service, 'persistGoalContractAfterScope', [
                $tenantId,
                80,
                11,
                $input,
                $content,
                $digest,
            ]);
            self::fail('A goal contract must not be written for a transferred hotel.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }

        self::assertSame(0, (int)Db::name('hotel_operating_goal_contracts')->count());
    }

    public function testInterventionWriteRejectsHotelTransferredAfterScopeResolution(): void
    {
        $service = $this->service();
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId] = $this->seedIntentAndTask('pending_execute');
        $input = $this->interventionInput((int)$goal['id']);
        $tenantId = (int)$this->invokePrivate($service, 'resolveScope', [7, [80], 80]);
        $goalBytes = serialize(Db::name('hotel_operating_goal_contracts')->where('id', (int)$goal['id'])->find());
        $intentBytes = serialize(Db::name('operation_execution_intents')->where('id', $intentId)->find());

        $this->transferHotelWithSeparateConnection(80, 8);
        try {
            $this->invokePrivate($service, 'persistIntervention', [$tenantId, 80, $intentId, $input, 11]);
            self::fail('An intervention must not be written for a transferred hotel.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }

        self::assertSame(0, (int)Db::name('operation_intervention_contracts')->count());
        self::assertSame(
            $goalBytes,
            serialize(Db::name('hotel_operating_goal_contracts')->where('id', (int)$goal['id'])->find())
        );
        self::assertSame(
            $intentBytes,
            serialize(Db::name('operation_execution_intents')->where('id', $intentId)->find())
        );
    }

    public function testInterventionAcceptsNestedUiPayloadAndReadsBackFrozenContract(): void
    {
        $service = $this->service();
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId] = $this->seedIntentAndTask('pending_execute');
        $input = $this->interventionInput((int)$goal['id']);
        $input['baseline']['quality_status'] = 'unverified';
        $input['baseline']['readback_status'] = 'unverified';

        $contract = $service->createInterventionForIntent(7, [80], 80, $intentId, $input, 11);
        $replay = $service->createInterventionForIntent(7, [80], 80, $intentId, $input, 12);

        self::assertSame(1, $contract['version_no']);
        self::assertSame('prospective', $contract['design_timing']);
        self::assertSame('prospective', $contract['contract_status']);
        self::assertSame($goal['id'], $contract['goal_contract_id']);
        self::assertSame($goal['version_no'], $contract['goal_contract_version_no']);
        self::assertSame('2026-08-04', $contract['observation_window']['start']);
        self::assertSame('same_length_period', $contract['comparison']['mode']);
        self::assertSame('room_revenue', $contract['baseline']['metric_key']);
        self::assertSame(['pms_daily_fact#100'], $contract['baseline']['evidence_refs']);
        self::assertSame('unverified', $contract['baseline']['quality_status']);
        self::assertSame('unverified', $contract['baseline']['readback_status']);
        self::assertTrue($contract['db_readback_verified']);
        self::assertSame($contract['id'], $replay['id']);
        self::assertTrue($replay['idempotent']);
        self::assertSame(1, Db::name('operation_intervention_contracts')->count());
    }

    public function testInterventionAutomaticallyFreezesVerifiedBaselineWithoutManualMetricEntry(): void
    {
        $snapshotService = new class {
            /** @var array<int,array<string,mixed>> */
            public array $calls = [];

            public function snapshot(
                int $tenantId,
                int $hotelId,
                string $metricKey,
                string $periodStart,
                string $periodEnd,
                array $context = []
            ): array {
                $this->calls[] = compact(
                    'tenantId',
                    'hotelId',
                    'metricKey',
                    'periodStart',
                    'periodEnd',
                    'context'
                );
                return [
                    'status' => 'ready',
                    'snapshot' => [
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'system_hotel_id' => $hotelId,
                        'platform' => 'dingdandao_pms',
                        'platform_hotel_id' => '5206408',
                        'business_module' => 'accommodation_operating',
                        'subject' => 'whole_hotel_accommodation',
                        'metric_key' => $metricKey,
                        'value' => 321.5,
                        'unit' => 'CNY',
                        'source_method' => 'revenue_fact_layer',
                        'date_role' => 'business_date',
                        'fact_scope' => 'whole_hotel_accommodation',
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'captured_at' => $periodEnd . ' 23:00:00',
                        'evidence_refs' => ['dingdandao_operating_target_captures#501'],
                        'quality_status' => 'verified',
                        'readback_status' => 'readback_verified',
                        'sample_size' => 3,
                    ],
                    'data_gaps' => [],
                    'reason_codes' => [],
                ];
            }
        };
        $service = $this->service($snapshotService);
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId] = $this->seedIntentAndTask('pending_execute');
        $input = $this->interventionInput((int)$goal['id']);
        unset($input['baseline']);
        $input['baseline_mode'] = 'automatic';
        $input['comparison']['reference'] = '';

        $contract = $service->createInterventionForIntent(7, [80], 80, $intentId, $input, 11);

        self::assertCount(1, $snapshotService->calls);
        self::assertSame('2026-08-01', $snapshotService->calls[0]['periodStart']);
        self::assertSame('2026-08-03', $snapshotService->calls[0]['periodEnd']);
        self::assertSame(321.5, $contract['baseline']['value']);
        self::assertSame('system_verified_snapshot', $contract['baseline']['baseline_origin']);
        self::assertTrue($contract['baseline']['automatic_readback']);
        self::assertSame('5206408', $contract['baseline']['platform_hotel_id']);
        self::assertSame('2026-08-01..2026-08-03', $contract['comparison']['reference']);
        self::assertTrue($contract['db_readback_verified']);
    }

    public function testAutomaticBaselineKeepsTheGoalSelectedBeforeAConcurrentNewVersion(): void
    {
        $service = $this->service();
        $goalV1 = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId] = $this->seedIntentAndTask('pending_execute');
        $goalV2Input = $this->goalInput();
        $goalV2Input['risk_preference'] = 'aggressive';
        $goalV2Input['version_note'] = 'concurrent v2';
        $goalV2Content = $this->invokePrivate($service, 'normalizeGoalContent', [7, 80, $goalV2Input]);
        $goalV2Digest = (string)$this->invokePrivate($service, 'digest', [$goalV2Content]);
        $snapshotService = new class(self::$sqlitePath, $goalV2Digest) {
            public function __construct(
                private readonly string $sqlitePath,
                private readonly string $goalV2Digest
            )
            {
            }

            public function snapshot(
                int $tenantId,
                int $hotelId,
                string $metricKey,
                string $periodStart,
                string $periodEnd,
                array $context = []
            ): array {
                $connection = new \PDO('sqlite:' . $this->sqlitePath);
                $statement = $connection->prepare(<<<'SQL'
INSERT INTO hotel_operating_goal_contracts (
    tenant_id, hotel_id, version_no, contract_schema, primary_objective,
    primary_metric_key, objective_direction, guard_metrics_json,
    operating_constraints_json, risk_preference, operating_phase, phase_note,
    stop_conditions_json, rollback_plan, effective_from, effective_to,
    version_note, content_digest, created_by, created_at
)
SELECT
    tenant_id, hotel_id, 2, contract_schema, primary_objective,
    primary_metric_key, objective_direction, guard_metrics_json,
    operating_constraints_json, 'aggressive', operating_phase, phase_note,
    stop_conditions_json, rollback_plan, effective_from, effective_to,
    'concurrent v2', :content_digest, 12, '2026-08-13 09:00:00'
FROM hotel_operating_goal_contracts
WHERE tenant_id = 7 AND hotel_id = 80 AND version_no = 1
SQL);
                $statement->execute(['content_digest' => $this->goalV2Digest]);
                return [
                    'status' => 'ready',
                    'snapshot' => [
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'system_hotel_id' => $hotelId,
                        'platform' => 'dingdandao_pms',
                        'platform_hotel_id' => '5206408',
                        'business_module' => 'accommodation_operating',
                        'subject' => 'whole_hotel_accommodation',
                        'metric_key' => $metricKey,
                        'value' => 321.5,
                        'unit' => 'CNY',
                        'source_method' => 'revenue_fact_layer',
                        'date_role' => 'business_date',
                        'fact_scope' => 'whole_hotel_accommodation',
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'captured_at' => $periodEnd . ' 23:00:00',
                        'evidence_refs' => ['dingdandao_operating_target_captures#501'],
                        'quality_status' => 'verified',
                        'readback_status' => 'readback_verified',
                        'sample_size' => 3,
                    ],
                    'data_gaps' => [],
                    'reason_codes' => [],
                ];
            }
        };
        $service = $this->service($snapshotService);
        $input = $this->interventionInput(0);
        unset($input['baseline']);
        $input['baseline_mode'] = 'automatic';

        $contract = $service->createInterventionForIntent(7, [80], 80, $intentId, $input, 11);

        self::assertSame(2, (int)Db::name('hotel_operating_goal_contracts')->count());
        self::assertSame($goalV1['id'], $contract['goal_contract_id']);
        self::assertSame($goalV1['version_no'], $contract['goal_contract_version_no']);
    }

    public function testThreeStateAssessmentsPersistInputEvidenceAndReplayIdempotently(): void
    {
        $service = $this->service();
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        $assessmentIds = [];

        foreach (['supported', 'contradicted', 'indeterminate'] as $index => $verdict) {
            [$intentId, $taskId] = $this->seedIntentAndTask('pending_execute');
            $intervention = $service->createInterventionForIntent(
                7,
                [80],
                80,
                $intentId,
                $this->interventionInput((int)$goal['id']),
                11
            );
            Db::name('operation_execution_tasks')->where('id', $taskId)->update([
                'status' => 'executed',
                'executed_at' => '2026-08-04 09:00:00',
            ]);
            Db::name('operation_execution_evidence')->insert([
                'tenant_id' => 7,
                'task_id' => $taskId,
                'evidence_type' => 'manual',
                'before_json' => '{}',
                'after_json' => '{"readback_verified":true}',
                'platform_response_json' => '{"automatic_ota_write":false}',
                'attachment_path' => '',
                'remark' => 'local manual evidence',
                'created_by' => 11,
                'created_at' => '2026-08-06 18:00:00',
                'updated_at' => '2026-08-06 18:00:00',
                'deleted_at' => null,
            ]);

            $input = $this->assessmentInput($verdict, $index + 1);
            $assessment = $service->createAssessmentForTask(7, [80], 80, $taskId, $input, 11);
            $replay = $service->createAssessmentForTask(7, [80], 80, $taskId, $input, 12);

            self::assertSame($verdict, $assessment['verdict']);
            self::assertFalse($assessment['causality_claimed']);
            self::assertSame(110.0 + $index, $assessment['followup']['value']);
            self::assertSame('unverified', $assessment['followup']['quality_status']);
            self::assertSame('unverified', $assessment['followup']['readback_status']);
            self::assertSame('user_provided', $assessment['followup']['evidence_origin']);
            self::assertSame('occupancy_rate', $assessment['guard_observations'][0]['metric_key']);
            self::assertSame('unverified', $assessment['guard_observations'][0]['quality_status']);
            self::assertSame('unverified', $assessment['guard_observations'][0]['readback_status']);
            self::assertSame('fixture_guard_result', $assessment['comparison']['guard_results'][0]['status']);
            self::assertSame('fixture assessment', $assessment['notes']);
            self::assertSame([], $assessment['external_interferences']);
            self::assertSame($intervention['id'], $assessment['intervention_contract_id']);
            self::assertTrue($assessment['db_readback_verified']);
            self::assertSame($assessment['id'], $replay['id']);
            self::assertTrue($replay['idempotent']);
            $assessmentIds[] = $assessment['id'];
        }

        self::assertCount(3, array_unique($assessmentIds));
        self::assertSame(3, Db::name('operation_intervention_assessments')->count());
        $overview = $service->overview(7, [80], 80);
        self::assertSame([
            'supported' => 1,
            'contradicted' => 1,
            'indeterminate' => 1,
            'unassessed' => 0,
        ], $overview['summary']);
        self::assertCount(3, $overview['interventions']);
        self::assertNotNull($overview['interventions'][0]['latest_assessment']);
    }

    public function testAssessmentWriteReusesTheOuterTaskAuthorizationTransaction(): void
    {
        $method = new \ReflectionMethod(OperatingGoalInterventionService::class, 'createAssessmentForTask');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('withExecutionTaskMutationAuthorization', $source);
        self::assertStringNotContainsString('Db::transaction', $source);
    }

    public function testAssessmentIncludesEvidenceCommittedBeforeItsTaskAuthorizationLock(): void
    {
        $setup = $this->service();
        $goal = $setup->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId, $taskId] = $this->seedIntentAndTask('executed');
        $setup->createInterventionForIntent(
            7,
            [80],
            80,
            $intentId,
            $this->interventionInput((int)$goal['id']),
            11
        );
        Db::name('operation_execution_tasks')->where('id', $taskId)->update([
            'executed_at' => '2026-08-04 09:00:00',
        ]);
        Db::name('operation_execution_evidence')->insert([
            'tenant_id' => 7,
            'task_id' => $taskId,
            'evidence_type' => 'manual',
            'before_json' => '{}',
            'after_json' => '{"sequence":"E1"}',
            'attachment_path' => '',
            'platform_response_json' => '{}',
            'remark' => 'E1',
            'created_by' => 11,
            'created_at' => '2026-08-06 17:00:00',
            'updated_at' => '2026-08-06 17:00:00',
            'deleted_at' => null,
        ]);

        $operation = new class extends \app\service\OperationManagementService {
            private bool $inserted = false;

            public function withExecutionTaskMutationAuthorization(
                int $taskId,
                array $hotelIds,
                callable $mutation
            ): mixed {
                if (!$this->inserted) {
                    $this->inserted = true;
                    Db::name('operation_execution_evidence')->insert([
                        'tenant_id' => 7,
                        'task_id' => $taskId,
                        'evidence_type' => 'manual',
                        'before_json' => '{}',
                        'after_json' => '{"sequence":"E2"}',
                        'attachment_path' => '',
                        'platform_response_json' => '{}',
                        'remark' => 'E2 committed before assessment lock',
                        'created_by' => 11,
                        'created_at' => '2026-08-06 18:00:00',
                        'updated_at' => '2026-08-06 18:00:00',
                        'deleted_at' => null,
                    ]);
                }

                return parent::withExecutionTaskMutationAuthorization($taskId, $hotelIds, $mutation);
            }
        };
        $judgment = new class {
            /** @var list<int> */
            public array $evidenceCounts = [];

            public function judge(array $goal, array $intervention, array $task, array $evidence, array $input): array
            {
                $this->evidenceCounts[] = count($evidence);
                return [
                    'verdict' => 'indeterminate',
                    'reason_codes' => ['controlled_interleave'],
                    'comparison' => ['evidence_count' => count($evidence)],
                    'guard_results' => [],
                    'result_summary' => 'controlled interleave assessment',
                    'causality_claimed' => false,
                ];
            }
        };
        $service = new OperatingGoalInterventionService($operation, $judgment);
        $assessment = $service->createAssessmentForTask(
            7,
            [80],
            80,
            $taskId,
            $this->assessmentInput('indeterminate', 9),
            11
        );

        self::assertSame([2], $judgment->evidenceCounts);
        self::assertSame(2, $assessment['comparison']['evidence_count']);
        self::assertSame(2, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());
    }

    public function testTransferredHotelCannotAssessAnOldTenantTask(): void
    {
        $service = $this->service();
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId, $taskId] = $this->seedIntentAndTask('executed');
        $service->createInterventionForIntent(
            7,
            [80],
            80,
            $intentId,
            $this->interventionInput((int)$goal['id']),
            11
        );
        Db::name('operation_execution_evidence')->insert([
            'tenant_id' => 7,
            'task_id' => $taskId,
            'evidence_type' => 'manual',
            'before_json' => '{}',
            'after_json' => '{"sequence":"old-tenant"}',
            'attachment_path' => '',
            'platform_response_json' => '{}',
            'remark' => 'must remain untouched',
            'created_by' => 11,
            'created_at' => '2026-08-06 17:00:00',
            'updated_at' => '2026-08-06 17:00:00',
            'deleted_at' => null,
        ]);
        Db::name('hotels')->where('id', 80)->update(['tenant_id' => 8]);

        try {
            $service->createAssessmentForTask(
                8,
                [80],
                80,
                $taskId,
                $this->assessmentInput('indeterminate', 10),
                12
            );
            self::fail('The new tenant must not assess the previous tenant task.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('current tenant scope', $exception->getMessage());
        }

        self::assertSame(0, (int)Db::name('operation_intervention_assessments')->count());
        self::assertSame(1, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());
        self::assertSame(7, (int)Db::name('operation_execution_tasks')->where('id', $taskId)->value('tenant_id'));
    }

    public function testSystemMonitorAssessmentUsesExplicitSystemActorAndOrigin(): void
    {
        $service = $this->service();
        $goal = $service->createGoalContract(7, [80], 80, $this->goalInput(), 11);
        [$intentId, $taskId] = $this->seedIntentAndTask('pending_execute');
        $service->createInterventionForIntent(
            7,
            [80],
            80,
            $intentId,
            $this->interventionInput((int)$goal['id']),
            11
        );
        Db::name('operation_execution_tasks')->where('id', $taskId)->update([
            'status' => 'executed',
            'executed_at' => '2026-08-04 09:00:00',
        ]);
        Db::name('operation_execution_evidence')->insert([
            'tenant_id' => 7,
            'task_id' => $taskId,
            'evidence_type' => 'manual',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => '{"automatic_ota_write":false}',
            'attachment_path' => '',
            'remark' => 'system monitor fixture',
            'created_by' => 11,
            'created_at' => '2026-08-06 18:00:00',
            'updated_at' => '2026-08-06 18:00:00',
            'deleted_at' => null,
        ]);

        $assessment = $service->createAutomatedAssessmentForTask(
            7,
            [80],
            80,
            $taskId,
            $this->assessmentInput('indeterminate', 0)
        );

        self::assertSame('indeterminate', $assessment['verdict']);
        self::assertSame(0, (int)Db::name('operation_intervention_assessments')
            ->where('id', (int)$assessment['id'])
            ->value('assessed_by'));
        self::assertSame('system_monitor', $assessment['comparison']['assessment_origin']);
        self::assertFalse($assessment['causality_claimed']);
    }

    public function testMissingLearningTableReportsMigrationRequiredAndCrossHotelIsRejected(): void
    {
        $service = $this->service();
        Db::execute('DROP TABLE operation_intervention_assessments');
        try {
            $overview = $service->overview(7, [80], 80);
            self::assertSame('migration_required', $overview['status']);
            self::assertTrue($overview['migration_required']);
            self::assertSame(['operation_intervention_assessments'], $overview['missing_tables']);
        } finally {
            self::createAssessmentTable();
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hotel_id is not permitted');
        $service->createGoalContract(7, [80], 81, $this->goalInput(81), 11);
    }

    private function service(?object $snapshotService = null): OperatingGoalInterventionService
    {
        $judgment = new class {
            /** @return array<string,mixed> */
            public function judge(
                array $goalContract,
                array $intervention,
                array $task,
                array $executionEvidenceRows,
                array $input
            ): array {
                $verdict = (string)($input['fixture_verdict'] ?? 'indeterminate');
                return [
                    'verdict' => $verdict,
                    'reason_codes' => ['fixture_' . $verdict],
                    'comparison' => ['fixture_verdict' => $verdict],
                    'guard_results' => [[
                        'metric_key' => 'occupancy_rate',
                        'status' => 'fixture_guard_result',
                    ]],
                    'result_summary' => 'fixture ' . $verdict,
                    'causality_claimed' => false,
                ];
            }
        };
        return new OperatingGoalInterventionService(null, $judgment, $snapshotService);
    }

    /** @return array<string,mixed> */
    private function goalInput(int $hotelId = 80): array
    {
        return [
            'hotel_id' => $hotelId,
            'primary_objective' => 'revenue',
            'primary_metric_key' => 'room_revenue',
            'objective_direction' => 'increase',
            'guard_metrics' => [
                ['metric_key' => 'occupancy_rate', 'operator' => '>=', 'threshold' => 45, 'unit' => 'percent'],
                ['metric_key' => 'cancellation_rate', 'operator' => '<=', 'threshold' => 20, 'unit' => 'percent'],
            ],
            'operating_constraints' => [
                ['constraint_key' => 'room_types', 'operator' => 'in', 'value' => ['deluxe', 'standard']],
                ['constraint_key' => 'channels', 'operator' => 'in', 'value' => ['meituan', 'ctrip']],
            ],
            'risk_preference' => 'balanced',
            'operating_phase' => 'revenue_recovery',
            'phase_note' => '先验证同口径收益改善',
            'stop_conditions' => [
                ['metric_key' => 'cancellation_rate', 'operator' => '>', 'threshold' => 20],
            ],
            'rollback_plan' => '停止新增动作并恢复人工检查清单上一版本。',
            'effective_from' => '2026-08-01',
            'effective_to' => '2026-08-31',
            'version_note' => 'initial contract',
        ];
    }

    /** @return array<string,mixed> */
    private function interventionInput(int $goalId): array
    {
        return [
            'hotel_id' => 80,
            'goal_contract_id' => $goalId,
            'platform' => '',
            'action_type' => 'daily_revenue_checklist',
            'action_text' => '核对同口径收益并人工调整运营清单',
            'rationale' => '以冻结基线验证收入改善，保护入住率。',
            'target_metric_key' => 'room_revenue',
            'expected_direction' => 'increase',
            'expected_delta' => 5,
            'expected_delta_unit' => 'absolute',
            'risk_metric_keys' => ['occupancy_rate'],
            'baseline' => [
                'value' => 100,
                'unit' => 'CNY',
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-03',
                'captured_at' => '2026-08-03 23:00:00',
                'source_method' => 'pms_readback',
                'fact_scope' => 'whole_hotel_accommodation',
                'evidence_refs' => ['pms_daily_fact#100'],
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'sample_size' => 30,
            ],
            'observation_window' => ['start' => '2026-08-04', 'end' => '2026-08-06'],
            'comparison' => ['mode' => 'same_length_period', 'reference' => '2026-08-01..2026-08-03'],
            'minimum_sample_size' => 10,
            'stop_condition' => '入住率低于45%时停止并回滚。',
        ];
    }

    /** @return array<string,mixed> */
    private function assessmentInput(string $verdict, int $offset): array
    {
        return [
            'hotel_id' => 80,
            'fixture_verdict' => $verdict,
            'assessed_at' => '2026-08-07 09:00:00',
            'followup' => [
                'metric_key' => 'room_revenue',
                'value' => 109.0 + $offset,
                'unit' => 'CNY',
                'period_start' => '2026-08-04',
                'period_end' => '2026-08-06',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'evidence_refs' => ['pms_daily_fact#200'],
                'sample_size' => 30,
            ],
            'guard_observations' => [[
                'metric_key' => 'occupancy_rate',
                'value' => 60,
                'period_start' => '2026-08-04',
                'period_end' => '2026-08-06',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'evidence_refs' => ['pms_daily_fact#201'],
                'sample_size' => 30,
            ]],
            'external_interferences' => [],
            'stop_triggered' => false,
            'stop_evidence_refs' => [],
            'notes' => 'fixture assessment',
        ];
    }

    /** @return array{0:int,1:int} */
    private function seedIntentAndTask(string $taskStatus): array
    {
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 7,
            'hotel_id' => 80,
            'source_module' => 'manual',
            'source_record_id' => 0,
            'platform' => '',
            'object_type' => 'operation_checklist',
            'action_type' => 'daily_revenue_checklist',
            'date_start' => '2026-08-04',
            'date_end' => '2026-08-06',
            'target_value_json' => '{"target_metric":"room_revenue","expected_direction":"increase"}',
            'current_value_json' => '{}',
            'evidence_json' => '{"auto_write_ota":false}',
            'expected_metric' => 'room_revenue',
            'expected_delta' => 5,
            'status' => 'approved',
            'deleted_at' => null,
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => 7,
            'intent_id' => $intentId,
            'hotel_id' => 80,
            'status' => $taskStatus,
            'executed_at' => null,
            'target_value_json' => '{}',
            'current_value_json' => '{}',
            'deleted_at' => null,
        ]);
        return [$intentId, $taskId];
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(object $service, string $methodName, array $arguments): mixed
    {
        $method = new \ReflectionMethod($service, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($service, $arguments);
    }

    private function transferHotelWithSeparateConnection(int $hotelId, int $tenantId): void
    {
        $connection = new \PDO('sqlite:' . self::$sqlitePath);
        $statement = $connection->prepare('UPDATE hotels SET tenant_id = :tenant_id WHERE id = :hotel_id');
        $statement->execute(['tenant_id' => $tenantId, 'hotel_id' => $hotelId]);
    }

    private static function createSchema(): void
    {
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)',
            'CREATE TABLE operation_execution_intents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, idempotency_key TEXT,
                hotel_id INTEGER NOT NULL,
                source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL, platform TEXT NOT NULL,
                object_type TEXT NOT NULL, action_type TEXT NOT NULL, date_start TEXT, date_end TEXT,
                current_value_json TEXT, target_value_json TEXT, evidence_json TEXT, expected_metric TEXT,
                expected_delta NUMERIC, risk_level TEXT, status TEXT, blocked_reason TEXT, review_remark TEXT,
                created_by INTEGER, approved_by INTEGER, approved_at TEXT, created_at TEXT, updated_at TEXT,
                deleted_at TEXT
            )',
            'CREATE TABLE operation_execution_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, intent_id INTEGER NOT NULL,
                hotel_id INTEGER NOT NULL, status TEXT NOT NULL, executed_at TEXT,
                current_value_json TEXT, target_value_json TEXT, deleted_at TEXT
            )',
            'CREATE TABLE operation_execution_evidence (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
                evidence_type TEXT, before_json TEXT, after_json TEXT, attachment_path TEXT,
                platform_response_json TEXT, remark TEXT, created_by INTEGER, created_at TEXT,
                updated_at TEXT, deleted_at TEXT
            )',
            'CREATE TABLE hotel_operating_goal_contracts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
                version_no INTEGER NOT NULL, contract_schema TEXT NOT NULL, primary_objective TEXT NOT NULL,
                primary_metric_key TEXT NOT NULL, objective_direction TEXT NOT NULL, guard_metrics_json TEXT NOT NULL,
                operating_constraints_json TEXT NOT NULL, risk_preference TEXT NOT NULL, operating_phase TEXT NOT NULL,
                phase_note TEXT NOT NULL, stop_conditions_json TEXT NOT NULL, rollback_plan TEXT NOT NULL,
                effective_from TEXT NOT NULL, effective_to TEXT NOT NULL, version_note TEXT NOT NULL,
                content_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (tenant_id, hotel_id, version_no), UNIQUE (tenant_id, hotel_id, content_digest)
            )',
            'CREATE TABLE operation_intervention_contracts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
                intent_id INTEGER NOT NULL, version_no INTEGER NOT NULL, goal_contract_id INTEGER NOT NULL,
                goal_contract_version_no INTEGER NOT NULL, contract_schema TEXT NOT NULL, design_timing TEXT NOT NULL,
                action_type TEXT NOT NULL, rationale TEXT NOT NULL, target_metric_key TEXT NOT NULL,
                expected_direction TEXT NOT NULL, expected_delta NUMERIC NOT NULL, expected_delta_unit TEXT NOT NULL,
                risk_metric_keys_json TEXT NOT NULL, baseline_snapshot_json TEXT NOT NULL,
                observation_window_start TEXT NOT NULL, observation_window_end TEXT NOT NULL,
                comparison_mode TEXT NOT NULL, comparison_reference TEXT NOT NULL,
                minimum_sample_size INTEGER NOT NULL, stop_condition TEXT NOT NULL, content_digest TEXT NOT NULL,
                created_by INTEGER NOT NULL, created_at TEXT NOT NULL,
                UNIQUE (tenant_id, hotel_id, intent_id, version_no),
                UNIQUE (tenant_id, hotel_id, intent_id, content_digest)
            )',
        ] as $sql) {
            Db::execute($sql);
        }
        self::createAssessmentTable();
    }

    private static function createAssessmentTable(): void
    {
        Db::execute('CREATE TABLE operation_intervention_assessments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
            intent_id INTEGER NOT NULL, task_id INTEGER NOT NULL, intervention_contract_id INTEGER NOT NULL,
            assessment_schema TEXT NOT NULL, verdict TEXT NOT NULL, reason_codes_json TEXT NOT NULL,
            followup_snapshot_json TEXT NOT NULL, guard_observations_json TEXT NOT NULL,
            external_interferences_json TEXT NOT NULL, stop_triggered INTEGER NOT NULL,
            stop_evidence_refs_json TEXT NOT NULL, comparison_json TEXT NOT NULL, result_summary TEXT NOT NULL,
            causality_claimed INTEGER NOT NULL, content_digest TEXT NOT NULL, assessed_by INTEGER NOT NULL,
            assessed_at TEXT NOT NULL, created_at TEXT NOT NULL,
            UNIQUE (tenant_id, hotel_id, task_id, content_digest)
        )');
    }
}
