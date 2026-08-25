<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use app\service\OperatingMemoryService;
use app\service\operation\OperationEffectReviewService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperationEffectReviewServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operation_effect_review_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove operation effect review SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_effect_reviews')->delete(true);
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('agent_logs')->delete(true);
        $this->seedApprovedExecution();
    }

    public function testCreatePersistsSeparateEffectReviewAndStrictlyReadsItBack(): void
    {
        $service = new OperationEffectReviewService();
        $input = $this->effectInput();

        $first = $service->create(42, 7, 1, 1, $input, 3);
        $second = $service->create(42, 7, 1, 1, $input, 3);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertTrue($first['review']['readback_verified']);
        self::assertSame('met', $first['review']['outcome_status']);
        self::assertSame('success', $first['review']['result_status']);
        self::assertFalse($first['review']['causality_claimed']);
        self::assertTrue($first['review']['approval_contract_verified']);
        self::assertTrue($first['review']['active_eligible']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['review']['approval_target_digest']);
        self::assertSame(
            $first['review']['approval_target_digest'],
            $first['review']['outcome']['approval_target_digest']
        );
        self::assertSame('10.000000', $first['review']['before_value']);
        self::assertSame('13.000000', $first['review']['after_value']);
        self::assertSame(1, (int)Db::name('operation_effect_reviews')->count());
        self::assertSame(1, (int)Db::name('operation_execution_evidence')->count());

        $list = $service->listForTask(42, 7, 1, 1);
        self::assertSame(1, $list['count']);
        self::assertSame($first['review']['content_digest'], $list['list'][0]['content_digest']);
    }

    public function testFrozenApprovalBaselineWinsForMultiDayIntent(): void
    {
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-07',
        ]);
        $evidence = Db::name('operation_execution_evidence')->where('id', 1)->find();
        $sourceContext = json_decode(
            (string)$evidence['platform_response_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $sourceContext['date_start'] = '2026-08-01';
        $sourceContext['date_end'] = '2026-08-07';
        Db::name('operation_execution_evidence')->where('id', 1)->update([
            'platform_response_json' => json_encode(
                $sourceContext,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        $created = (new OperationEffectReviewService())->create(
            42,
            7,
            1,
            1,
            $this->effectInput(),
            3
        );

        self::assertTrue($created['created']);
        self::assertSame('2026-08-07', $created['review']['baseline_business_date']);
        self::assertTrue($created['review']['approval_contract_verified']);
        self::assertSame('readback_verified', $created['persistence_status']);
    }

    public function testCurrentApprovalTargetDriftKeepsOldReviewHistoricalButInactive(): void
    {
        $service = new OperationEffectReviewService();
        $created = $service->create(42, 7, 1, 1, $this->effectInput(), 3);
        $oldApprovalDigest = $created['review']['approval_target_digest'];
        $newApprovalDigest = $this->driftApprovedIntentTarget(3.0);

        self::assertNotSame($oldApprovalDigest, $newApprovalDigest);
        $list = $service->listForTask(42, 7, 1, 1);
        self::assertSame('approval_target_drifted', $list['persistence_status']);
        self::assertSame(0, $list['approval_contract_verified_count']);
        self::assertFalse($list['list'][0]['approval_contract_verified']);
        self::assertFalse($list['list'][0]['active_eligible']);
        self::assertSame('approval_target_digest_mismatch', $list['list'][0]['approval_contract_validation_status']);

        try {
            $service->readVerified((int)$created['review']['id'], 42, 7, 1, 1);
            self::fail('A review bound to an old approval contract must not pass verified readback.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('审批冻结目标已漂移', $exception->getMessage());
        }

        $task = (new OperationManagementService())->readExecutionTask(1, [7]);
        self::assertNull($task['active_effect_review']);
        self::assertSame('approval_target_drifted', $task['effect_review_summary']['persistence_status']);
        self::assertSame('approval_target_contract_drifted', $task['outcome_truth']['failure_reason']);
        self::assertSame('not_ready', $task['sop_candidate']['status']);

        $intent = (new OperationManagementService())->readExecutionIntent(1, [7]);
        $memoryBuilder = new \ReflectionMethod(OperatingMemoryService::class, 'buildExecutionReviewRecord');
        $memory = $memoryBuilder->invoke(new OperatingMemoryService(), $task, $intent, 3);
        self::assertSame('partial', $memory['quality_status']);
        self::assertFalse(json_decode($memory['context_json'], true)['separate_effect_review_verified']);
    }

    public function testApprovalTargetDigestIsCoveredByEffectReviewContentDigest(): void
    {
        $service = new OperationEffectReviewService();
        $created = $service->create(42, 7, 1, 1, $this->effectInput(), 3);
        $row = Db::name('operation_effect_reviews')->where('id', (int)$created['review']['id'])->find();
        $outcome = json_decode((string)$row['outcome_json'], true, 512, JSON_THROW_ON_ERROR);
        $outcome['approval_target_digest'] = str_repeat('a', 64);
        Db::name('operation_effect_reviews')
            ->where('id', (int)$created['review']['id'])
            ->update([
                'approval_target_digest' => str_repeat('a', 64),
                'outcome_json' => json_encode(
                    $outcome,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('严格回读校验失败');
        $service->listForTask(42, 7, 1, 1);
    }

    public function testCreateRejectsClientEffectValueThatDiffersFromSourceReadback(): void
    {
        $input = $this->effectInput();
        $input['after_value'] = 99;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('来源回读复盘值断言不匹配');
        (new OperationEffectReviewService())->create(42, 7, 1, 1, $input, 3);
    }

    public function testCreateRejectsApprovalContractTampering(): void
    {
        $evidence = json_decode((string)Db::name('operation_execution_intents')
            ->where('id', 1)->value('evidence_json'), true);
        $evidence['approval_target']['expected_direction'] = 'decrease';
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('人工审批冻结契约');
        (new OperationEffectReviewService())->create(42, 7, 1, 1, $this->effectInput(), 3);
    }

    public function testEffectFailureRollsBackTerminalTaskReview(): void
    {
        Db::name('operation_execution_tasks')->where('id', 1)->update([
            'result_status' => 'observing',
            'result_summary' => '',
        ]);
        $this->insertManualExecutionEvidence();
        $intentEvidence = json_decode((string)Db::name('operation_execution_intents')
            ->where('id', 1)->value('evidence_json'), true);
        $intentEvidence['approval_target']['source_policy'] = 'tampered_after_approval';
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'evidence_json' => json_encode($intentEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            (new OperationManagementService())->reviewExecutionTask(1, [7], [
                'result_status' => 'success',
                'result_summary' => '同酒店、同携程、同订单口径次日回读达到人工冻结目标。',
            ], 3);
            self::fail('Tampered approval target must roll the terminal review back.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('冻结', $exception->getMessage());
        }

        $task = Db::name('operation_execution_tasks')->where('id', 1)->find();
        self::assertSame('observing', $task['result_status']);
        self::assertSame('', $task['result_summary']);
        self::assertSame(0, (int)Db::name('operation_effect_reviews')->count());
    }

    public function testTerminalReviewAtomicallyCreatesSeparateEffectReviewAndReturnsReadback(): void
    {
        Db::name('operation_execution_tasks')->where('id', 1)->update([
            'result_status' => 'observing',
            'result_summary' => '',
        ]);
        $this->insertManualExecutionEvidence();

        $task = (new OperationManagementService())->reviewExecutionTask(1, [7], [
            'result_status' => 'success',
            'result_summary' => '同酒店、同携程、同订单口径次日回读达到人工冻结目标。',
        ], 3);

        self::assertSame('success', $task['result_status']);
        self::assertCount(1, $task['execution_evidence']);
        self::assertCount(1, $task['effect_source_evidence']);
        self::assertCount(1, $task['effect_reviews']);
        self::assertSame(1, $task['effect_review_summary']['verified_count']);
        self::assertTrue($task['effect_review_summary']['current_result_bound']);
        self::assertSame('readback_verified', $task['effect_review_summary']['persistence_status']);
        self::assertSame($task['active_effect_review']['outcome'], $task['outcome_truth']);
        self::assertSame('candidate', $task['sop_candidate']['status']);
        self::assertSame(1, (int)Db::name('operation_effect_reviews')->count());

        Db::name('operation_execution_tasks')->where('id', 1)->update([
            'result_summary' => '人工纠正后的另一版结论，尚无对应独立效果记录。',
        ]);
        $corrected = (new OperationManagementService())->readExecutionTask(1, [7]);
        self::assertNull($corrected['active_effect_review']);
        self::assertFalse($corrected['effect_review_summary']['current_result_bound']);
        self::assertSame('current_result_mismatch', $corrected['effect_review_summary']['persistence_status']);
        self::assertSame('current_separate_effect_review_missing', $corrected['outcome_truth']['failure_reason']);
        self::assertSame('not_ready', $corrected['sop_candidate']['status']);

        $intent = (new OperationManagementService())->readExecutionIntent(1, [7]);
        $memoryBuilder = new \ReflectionMethod(OperatingMemoryService::class, 'buildExecutionReviewRecord');
        $memory = $memoryBuilder->invoke(new OperatingMemoryService(), $corrected, $intent, 3);
        self::assertSame('partial', $memory['quality_status']);
        self::assertFalse(json_decode($memory['context_json'], true)['separate_effect_review_verified']);
    }

    public function testApprovalPersistsExactTargetAndTaskInOneStrictReadback(): void
    {
        $intentId = $this->seedPendingApprovalIntent();
        $approved = (new OperationManagementService())->approveExecutionIntent(
            $intentId,
            true,
            'human approved exact next-day target',
            3,
            [7],
            [
                'approved' => true,
                'expected_metric' => 'orders',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1.234567,
                'review_business_date' => '2026-08-09',
            ]
        );

        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame(1.234567, $approved['expected_delta']);
        self::assertSame('1.234567', $approved['evidence']['approval_target']['expected_delta']);
        self::assertSame('2026-08-09', $approved['evidence']['approval_target']['review_business_date']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $approved['evidence']['approval_target']['content_digest']);
        self::assertSame(
            $approved['target_value']['approval_target_digest'],
            $approved['tasks'][0]['target_value']['approval_target_digest']
        );
        self::assertSame(1.234567, (float)Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('expected_delta'));
    }

    public function testCtripListExposureApprovalPersistsFrozenSemanticContractAfterFullProvenanceCheck(): void
    {
        $intentId = $this->seedPendingApprovalIntent();
        $service = new OperationManagementService();
        $semantic = (new \ReflectionMethod(
            OperationManagementService::class,
            'ctripListExposureSemanticBinding'
        ))->invoke($service);
        $actionText = '人工核验携程可售与列表入口并恢复列表曝光去重浏览人数';
        $sourceRef = 'online_daily_data#701';
        $evidenceSource = [
            'ref' => $sourceRef,
            'table' => 'online_daily_data',
            'record_id' => 701,
            'platform' => 'ctrip',
            'source_endpoint_id' => 'business_flow_transform',
            'metrics' => ['list_exposure' => 0],
            'metric_fact_statuses' => [
                'list_exposure' => [
                    'status' => 'ready',
                    'captured_metric_keys' => ['list_exposure'],
                    'missing_requested_metric_keys' => [],
                    'source_endpoint_id' => 'business_flow_transform',
                    'source_key' => 'listExposure',
                    'source_path' => '$.data.listExposure',
                ],
            ],
        ];
        $recommendation = [
            'id' => 'approval-action-1',
            'action' => $actionText,
            'action_type' => 'listing_exposure_recovery',
            'expected_metric' => 'list_exposure',
            'metric_semantic' => $semantic,
            'execution_ready' => true,
            'can_request_execution_intent' => true,
            'can_create_execution_intent' => true,
            'evidence_refs' => [$sourceRef],
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $recommendationDigest = (new \ReflectionMethod(
            OperationManagementService::class,
            'decisionRecommendationDigest'
        ))->invoke($service, $recommendation);
        $intent = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        $targetValue = json_decode((string)$intent['target_value_json'], true, 512, JSON_THROW_ON_ERROR);
        $targetValue['target_metric'] = 'list_exposure';
        $targetValue['action_text'] = $actionText;
        $targetValue['metric_semantic'] = $semantic;
        $intentEvidence = json_decode((string)$intent['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $intentEvidence['evidence_refs'] = [$sourceRef];
        $intentEvidence['evidence_sources'] = [$evidenceSource];
        $intentEvidence['metric_semantic'] = $semantic;
        $intentEvidence['decision_recommendation'] = $recommendation;
        $intentEvidence['decision_recommendation_digest'] = $recommendationDigest;
        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'action_type' => 'listing_exposure_recovery',
            'current_value_json' => json_encode(['list_exposure' => 0], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode($targetValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode($intentEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expected_metric' => 'list_exposure',
            'expected_delta' => null,
        ]);

        $sourceId = (int)$intent['source_record_id'];
        $context = json_decode(
            (string)Db::name('agent_logs')->where('id', $sourceId)->value('context_data'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $context['diagnosis_result']['metrics'] = ['list_exposure' => 0];
        $context['diagnosis_result']['evidence_sources'] = [$evidenceSource];
        $context['diagnosis_result']['action_items'] = [[
            ...$recommendation,
            'execution_intent_id' => $intentId,
            'execution_idempotency_key' => 'approval-idempotency-1',
        ]];
        Db::name('agent_logs')->where('id', $sourceId)->update([
            'context_data' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $approved = $service->approveExecutionIntent(
            $intentId,
            true,
            'human approved one whole unique user as next-day verification target',
            3,
            [7],
            [
                'approved' => true,
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 1,
                'review_business_date' => '2026-08-09',
            ]
        );

        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame('ctrip_datacenter_list_exposure_uv', $approved['evidence']['approval_target']['metric_definition']['semantic_key']);
        self::assertSame('unique_users', $approved['evidence']['approval_target']['metric_definition']['unit']);
        self::assertSame('1.000000', $approved['evidence']['approval_target']['expected_delta']);
    }

    public function testApprovalRejectsDeltaThatRoundsToZeroWithoutWritingIntentOrTask(): void
    {
        $intentId = $this->seedPendingApprovalIntent();

        try {
            (new OperationManagementService())->approveExecutionIntent(
                $intentId,
                true,
                'must remain pending because normalized delta is zero',
                3,
                [7],
                [
                    'approved' => true,
                    'expected_metric' => 'orders',
                    'expected_direction' => 'increase',
                    'target_type' => 'delta',
                    'expected_delta' => 0.0000004,
                    'review_business_date' => '2026-08-09',
                ]
            );
            self::fail('A delta that rounds to zero must not approve an execution intent.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('remain positive after 6-decimal normalization', $exception->getMessage());
        }

        $intent = Db::name('operation_execution_intents')->where('id', $intentId)->find();
        self::assertSame('pending_approval', $intent['status']);
        self::assertNull($intent['expected_delta']);
        self::assertSame(0, (int)($intent['approved_by'] ?? 0));
        self::assertSame('', (string)($intent['approved_at'] ?? ''));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());
        self::assertSame(0, (int)Db::name('operation_effect_reviews')->count());
    }

    public function testApprovalRejectsOlderPendingDiagnosisWhenNewerSameScopeReadbackExists(): void
    {
        $intentId = $this->seedPendingApprovalIntent();
        $readbackDigest = str_repeat('f', 64);
        $newLogId = (int)Db::name('agent_logs')->insertGetId([
            'tenant_id' => 42,
            'hotel_id' => 7,
            'agent_type' => 2,
            'action' => 'ota_diagnosis',
            'context_data' => '{}',
        ]);
        Db::name('agent_logs')->where('id', $newLogId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackDigest,
                'diagnosis_result' => [
                    'record_status' => 'active',
                    'decision_status' => 'blocked_by_missing_facts',
                    'hotel' => ['id' => 7],
                    'platform' => 'ctrip',
                    'date_range' => ['start_date' => '2026-08-08', 'end_date' => '2026-08-08'],
                    'requested_date_range' => ['start_date' => '2026-08-08', 'end_date' => '2026-08-08'],
                    'saved_record' => [
                        'saved' => true,
                        'readback_verified' => true,
                        'id' => $newLogId,
                        'readback_identity_digest' => $readbackDigest,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            (new OperationManagementService())->approveExecutionIntent(
                $intentId,
                true,
                'stale diagnosis must remain pending',
                3,
                [7],
                [
                    'approved' => true,
                    'expected_metric' => 'orders',
                    'expected_direction' => 'increase',
                    'target_type' => 'delta',
                    'expected_delta' => 1,
                    'review_business_date' => '2026-08-09',
                ]
            );
            self::fail('A newer same-scope diagnosis must invalidate approval of the older pending intent.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('provenance', strtolower($exception->getMessage()));
        }

        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());
    }

    public function testApprovalRejectsSourceActionDigestDriftWithoutWritingTask(): void
    {
        $intentId = $this->seedPendingApprovalIntent();
        $sourceId = (int)Db::name('operation_execution_intents')->where('id', $intentId)->value('source_record_id');
        $context = json_decode(
            (string)Db::name('agent_logs')->where('id', $sourceId)->value('context_data'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $context['diagnosis_result']['action_items'][0]['action'] = 'drifted action text after intent creation';
        Db::name('agent_logs')->where('id', $sourceId)->update([
            'context_data' => json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        try {
            (new OperationManagementService())->approveExecutionIntent(
                $intentId,
                true,
                'drifted source must remain pending',
                3,
                [7],
                [
                    'approved' => true,
                    'expected_metric' => 'orders',
                    'expected_direction' => 'increase',
                    'target_type' => 'delta',
                    'expected_delta' => 1,
                    'review_business_date' => '2026-08-09',
                ]
            );
            self::fail('Source action digest drift must block approval.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('provenance', strtolower($exception->getMessage()));
        }

        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $intentId)->count());
    }

    public function testApprovalRejectsPreExistingTaskAndLeavesIntentPending(): void
    {
        $intentId = $this->seedPendingApprovalIntent();
        Db::name('operation_execution_tasks')->insert([
            'tenant_id' => 42,
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'target_value_json' => '{}',
            'current_value_json' => '{}',
            'result_status' => 'observing',
            'result_summary' => '',
            'status' => 'pending_execute',
            'created_at' => '2026-08-08 12:00:00',
            'updated_at' => '2026-08-08 12:00:00',
        ]);

        try {
            (new OperationManagementService())->approveExecutionIntent(
                $intentId,
                true,
                'must roll back',
                3,
                [7],
                [
                    'approved' => true,
                    'expected_metric' => 'orders',
                    'expected_direction' => 'increase',
                    'target_type' => 'delta',
                    'expected_delta' => 1.234567,
                    'review_business_date' => '2026-08-09',
                ]
            );
            self::fail('A pending intent with an existing task must fail closed before approval.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('zero tasks', $exception->getMessage());
        }

        self::assertSame('pending_approval', Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('status'));
        self::assertSame('{}', Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)->value('target_value_json'));
        self::assertSame(1, (int)Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)->count());
    }

    private function insertManualExecutionEvidence(): void
    {
        Db::name('operation_execution_evidence')->insert([
            'id' => 2,
            'tenant_id' => 42,
            'task_id' => 1,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'attachment_path' => 'local-test-receipt',
            'platform_response_json' => json_encode([
                'mode' => 'operator_attested',
                'operator_attested' => true,
                'operator_attested_at' => '2026-08-07 18:10:00',
            ], JSON_THROW_ON_ERROR),
            'remark' => 'synthetic test fixture: human execution receipt',
            'created_by' => 3,
            'created_at' => '2026-08-07 18:10:00',
        ]);
    }

    private function seedPendingApprovalIntent(): int
    {
        Db::name('operation_effect_reviews')->delete(true);
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('agent_logs')->delete(true);

        $service = new OperationManagementService();
        $recommendation = [
            'id' => 'approval-action-1',
            'action' => '人工优化携程订单转化并观察同口径订单变化',
            'action_type' => 'conversion_optimization',
            'expected_metric' => 'orders',
            'execution_ready' => true,
            'can_request_execution_intent' => true,
            'can_create_execution_intent' => true,
            'evidence_refs' => ['online_daily_data#701'],
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $recommendationDigest = (new \ReflectionMethod(
            OperationManagementService::class,
            'decisionRecommendationDigest'
        ))->invoke($service, $recommendation);
        $logId = (int)Db::name('agent_logs')->insertGetId([
            'tenant_id' => 42,
            'hotel_id' => 7,
            'agent_type' => 2,
            'action' => 'ota_diagnosis',
            'context_data' => '{}',
        ]);
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 42,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $logId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'conversion_optimization',
            'date_start' => '2026-08-08',
            'date_end' => '2026-08-08',
            'current_value_json' => json_encode(['orders' => 10], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode([
                'target_metric' => 'orders',
                'action_text' => $recommendation['action'],
                'due_at' => '2026-08-08 20:00:00',
                'review_at' => '2026-08-09 10:00:00',
                'workflow_schedule' => [
                    'due_at' => '2026-08-08 20:00:00',
                    'review_at' => '2026-08-09 10:00:00',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode([
                'action_index' => 0,
                'action_item_id' => 'approval-action-1',
                'action_idempotency_key' => 'approval-idempotency-1',
                'evidence_refs' => ['online_daily_data#701'],
                'decision_recommendation' => $recommendation,
                'decision_recommendation_digest' => $recommendationDigest,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expected_metric' => 'orders',
            'expected_delta' => null,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'pending_approval',
            'created_by' => 3,
            'created_at' => '2026-08-08 12:00:00',
            'updated_at' => '2026-08-08 12:00:00',
        ]);
        $sourceAction = $recommendation + [
            'execution_intent_id' => $intentId,
            'execution_idempotency_key' => 'approval-idempotency-1',
        ];
        $readbackIdentityDigest = str_repeat('d', 64);
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => [
                    'record_status' => 'active',
                    'decision_status' => 'action_required',
                    'hotel' => ['id' => 7],
                    'platform' => 'ctrip',
                    'date_range' => ['start_date' => '2026-08-08', 'end_date' => '2026-08-08'],
                    'requested_date_range' => ['start_date' => '2026-08-08', 'end_date' => '2026-08-08'],
                    'metrics' => ['orders' => 10],
                    'saved_record' => [
                        'saved' => true,
                        'readback_verified' => true,
                        'id' => $logId,
                        'readback_identity_digest' => $readbackIdentityDigest,
                    ],
                    'action_items' => [$sourceAction],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        return $intentId;
    }

    /** @return array<string,mixed> */
    private function effectInput(): array
    {
        return [
            'tenant_id' => 42,
            'hotel_id' => 7,
            'intent_id' => 1,
            'task_id' => 1,
            'platform' => 'ctrip',
            'metric_key' => 'orders',
            'baseline_business_date' => '2026-08-07',
            'review_business_date' => '2026-08-08',
            'source_readback_evidence_id' => 1,
            'result_status' => 'success',
            'result_summary' => '同酒店、同携程、同订单口径次日回读达到人工冻结目标。',
            'reviewed_at' => '2026-08-09 09:00:00',
            'causality_claimed' => false,
        ];
    }

    private function driftApprovedIntentTarget(float $expectedDelta): string
    {
        $intent = Db::name('operation_execution_intents')->where('id', 1)->find();
        $targetValue = json_decode((string)$intent['target_value_json'], true, 512, JSON_THROW_ON_ERROR);
        $evidence = json_decode((string)$intent['evidence_json'], true, 512, JSON_THROW_ON_ERROR);
        $contract = $evidence['approval_target'];
        $contract['expected_delta'] = number_format($expectedDelta, 6, '.', '');
        unset($contract['content_digest']);
        $contract['content_digest'] = hash('sha256', self::canonicalJson($contract));

        $targetValue['expected_delta'] = round($expectedDelta, 6);
        $targetValue['approval_target_digest'] = $contract['content_digest'];
        $evidence['expected_delta'] = round($expectedDelta, 6);
        $evidence['approval_target'] = $contract;
        $evidence['approval_target_digest'] = $contract['content_digest'];
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'expected_delta' => round($expectedDelta, 6),
            'target_value_json' => json_encode(
                $targetValue,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'evidence_json' => json_encode(
                $evidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'updated_at' => '2026-08-09 12:00:00',
        ]);

        return $contract['content_digest'];
    }

    private function seedApprovedExecution(): void
    {
        $definition = [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => 'orders',
            'source_table' => 'online_daily_data',
            'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
            'source_policy' => 'trusted_persisted_source_rows_with_strict_readback',
            'calculation' => 'trusted_daily_order_count',
            'comparison_policy' => 'same_hotel_same_platform_same_metric_baseline_vs_approved_review_business_date',
        ];
        $definitionDigest = hash('sha256', self::canonicalJson([
            'metric_key' => 'orders',
            'definition' => $definition,
        ]));
        $approvalContract = [
            'version' => 'ota_execution_approval_target.v1',
            'intent_id' => 1,
            'tenant_id' => 42,
            'hotel_id' => 7,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 51,
            'platform' => 'ctrip',
            'baseline_business_date' => '2026-08-07',
            'review_business_date' => '2026-08-08',
            'expected_metric' => 'orders',
            'metric_definition' => $definition,
            'metric_definition_digest' => $definitionDigest,
            'expected_direction' => 'increase',
            'target_type' => 'delta',
            'target_value' => null,
            'expected_delta' => '2.000000',
            'expected_delta_status' => 'manual_confirmed',
            'approved_by' => 3,
            'approved_at' => '2026-08-07 12:00:00',
            'diagnosis_recommendation_digest' => '',
            'source_policy' => 'saved_diagnosis_metric_and_human_target_frozen_before_task_creation',
        ];
        $approvalContract['content_digest'] = hash('sha256', self::canonicalJson($approvalContract));
        Db::name('operation_execution_intents')->insert([
            'id' => 1,
            'tenant_id' => 42,
            'hotel_id' => 7,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 51,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'manual_price_review',
            'date_start' => '2026-08-07',
            'date_end' => '2026-08-07',
            'current_value_json' => json_encode(['orders' => 10], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode([
                'target_metric' => 'orders',
                'target_type' => 'delta',
                'expected_direction' => 'increase',
                'expected_delta_status' => 'manual_confirmed',
                'expected_delta' => 2,
                'review_business_date' => '2026-08-08',
                'review_at' => '2026-08-08 10:00:00',
                'workflow_schedule' => ['review_at' => '2026-08-08 10:00:00'],
                'metric_definition' => $definition,
                'metric_definition_digest' => $definitionDigest,
                'approval_target_digest' => $approvalContract['content_digest'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode([
                'expected_delta_status' => 'manual_confirmed',
                'target_type' => 'delta',
                'expected_direction' => 'increase',
                'expected_delta' => 2,
                'target_value' => null,
                'review_business_date' => '2026-08-08',
                'metric_definition' => $definition,
                'metric_definition_digest' => $definitionDigest,
                'approval_target' => $approvalContract,
                'approval_target_digest' => $approvalContract['content_digest'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expected_metric' => 'orders',
            'expected_delta' => 2,
            'status' => 'approved',
            'approved_by' => 3,
            'approved_at' => '2026-08-07 12:00:00',
            'created_at' => '2026-08-07 11:00:00',
            'updated_at' => '2026-08-07 12:00:00',
        ]);
        Db::name('operation_execution_tasks')->insert([
            'id' => 1,
            'tenant_id' => 42,
            'intent_id' => 1,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'operator_id' => 3,
            'target_value_json' => '{}',
            'current_value_json' => '{}',
            'result_status' => 'success',
            'result_summary' => '同酒店、同携程、同订单口径次日回读达到人工冻结目标。',
            'status' => 'executed',
            'executed_at' => '2026-08-07 18:00:00',
            'created_at' => '2026-08-07 12:00:00',
            'updated_at' => '2026-08-07 18:00:00',
        ]);
        Db::name('operation_execution_evidence')->insert([
            'id' => 1,
            'tenant_id' => 42,
            'task_id' => 1,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['orders' => 10], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['orders' => 13], JSON_THROW_ON_ERROR),
            'attachment_path' => '',
            'platform_response_json' => json_encode([
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#101,102',
                'system_hotel_id' => 7,
                'tenant_id' => 42,
                'platform' => 'ctrip',
                'object_type' => 'price',
                'date_start' => '2026-08-07',
                'date_end' => '2026-08-07',
                'baseline_date' => '2026-08-07',
                'review_date' => '2026-08-08',
                'metric_key' => 'orders',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 1,
                'readback_at' => '2026-08-08 10:00:00',
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'baseline_source_ref' => 'online_daily_data#101',
                'followup_source_ref' => 'online_daily_data#102',
                'causality_claimed' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'remark' => 'synthetic test fixture: persisted source readback',
            'created_by' => 0,
            'created_at' => '2026-08-08 10:00:00',
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
        Db::execute(<<<'SQL'
CREATE TABLE agent_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL DEFAULT 42,
    hotel_id INTEGER NOT NULL, agent_type INTEGER NOT NULL, action TEXT NOT NULL,
    context_data TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL, platform TEXT NOT NULL,
    object_type TEXT, action_type TEXT, date_start TEXT, date_end TEXT,
    current_value_json TEXT, target_value_json TEXT, evidence_json TEXT,
    expected_metric TEXT, expected_delta REAL, risk_level TEXT DEFAULT 'medium',
    blocked_reason TEXT DEFAULT '', status TEXT NOT NULL, created_by INTEGER DEFAULT 0,
    approved_by INTEGER, approved_at TEXT, review_remark TEXT DEFAULT '',
    created_at TEXT, updated_at TEXT, deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_tasks (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL DEFAULT 42, intent_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    execution_mode TEXT, operator_id INTEGER, target_value_json TEXT, current_value_json TEXT,
    result_status TEXT, result_summary TEXT, status TEXT NOT NULL, executed_at TEXT,
    created_at TEXT, updated_at TEXT, deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
    evidence_type TEXT NOT NULL, before_json TEXT, after_json TEXT, attachment_path TEXT,
    platform_response_json TEXT, remark TEXT, created_by INTEGER, created_at TEXT,
    updated_at TEXT, deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_effect_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL,
    intent_id INTEGER NOT NULL, task_id INTEGER NOT NULL, platform TEXT NOT NULL,
    baseline_business_date TEXT NOT NULL, review_business_date TEXT NOT NULL, metric_key TEXT NOT NULL,
    metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL,
    approval_target_digest TEXT NOT NULL,
    before_value REAL NOT NULL, after_value REAL NOT NULL, expected_direction TEXT NOT NULL,
    target_type TEXT NOT NULL, target_value REAL, expected_delta REAL, expected_delta_status TEXT NOT NULL,
    target_confirmed_by INTEGER NOT NULL, target_confirmed_at TEXT NOT NULL,
    baseline_refs_json TEXT NOT NULL, followup_refs_json TEXT NOT NULL,
    source_readback_evidence_id INTEGER NOT NULL, outcome_status TEXT NOT NULL,
    outcome_json TEXT NOT NULL, result_status TEXT NOT NULL, result_summary TEXT NOT NULL,
    causality_claimed INTEGER NOT NULL, reviewed_by INTEGER NOT NULL, reviewed_at TEXT NOT NULL,
    content_digest TEXT NOT NULL, created_at TEXT NOT NULL,
    UNIQUE (tenant_id, hotel_id, task_id, content_digest)
)
SQL);
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
