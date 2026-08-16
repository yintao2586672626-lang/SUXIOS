<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingLoopKernelService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingLoopKernelServiceTest extends TestCase
{
    private const TENANT_ID = 10;
    private const HOTEL_ID = 80;
    private const BUSINESS_DATE = '2026-08-10';
    private const COLLECTION_PLAN_ID = 3;
    private const COLLECTION_PLAN_VERSION = 3;
    private const CTRIP_DATA_SOURCE_ID = 11;
    private const MEITUAN_DATA_SOURCE_ID = 12;
    private const PMS_INTEGRATION_ID = 21;
    private const METRIC_DEFINITION = [
        'metric_key' => 'ota_room_nights',
        'scope' => 'ota_channel',
    ];

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_loop_kernel_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        foreach ([
            'hotel_operating_cycle_evidence',
            'hotel_operating_cycle_events',
            'hotel_operating_cycles',
            'hotel_operating_memories',
            'operation_effect_reviews',
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_execution_intents',
            'price_suggestions',
            'online_daily_data',
            'dingdandao_operating_target_captures',
            'dingdandao_pms_integrations',
            'hotel_collection_plan_run_sources',
            'hotel_collection_plan_runs',
            'hotel_collection_plans',
            'platform_data_sources',
            'ota_local_collector_account_hotels',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        $this->createSchema();
        $this->seedEvidenceChain();
    }

    public function testCompletesOneAuthoritativeCycleWithExactEventAndEvidenceReadback(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);

        self::assertTrue($opened['created']);
        self::assertSame('readback_verified', $opened['persistence_status']);
        self::assertSame(1, $opened['cycle']['revision']);
        self::assertSame('trusted_collection', $opened['cycle']['next_required_stage']);
        self::assertSame('CTRIP-80', $opened['cycle']['source_identities'][0]['external_hotel_id']);

        $cycle = $this->completeCycle($service, $opened['cycle']);

        self::assertSame('completed', $cycle['cycle_status']);
        self::assertSame(8, $cycle['revision']);
        self::assertTrue($cycle['readback_verified']);
        self::assertCount(8, $cycle['events']);
        self::assertCount(23, $cycle['evidence_refs']);
        self::assertSame('supported', $cycle['summary']['yesterday_result']['status']);
        self::assertSame('not_reusable', $cycle['summary']['experience']['status']);
        self::assertSame('cycle-' . $cycle['id'], $cycle['summary']['kernel_id']);
        self::assertSame('ota_channel', $this->evidenceByRole($cycle, 'ota_fact_rows')['fact_scope']);

        foreach ($cycle['events'] as $offset => $event) {
            self::assertSame($offset, $event['from_version']);
            self::assertSame($offset + 1, $event['to_version']);
            self::assertSame($event['to_stage'], $event['stage_key']);
            self::assertSame(self::TENANT_ID, $event['tenant_id']);
            self::assertSame(self::HOTEL_ID, $event['hotel_id']);
        }
        foreach ($cycle['evidence_refs'] as $ref) {
            self::assertSame(self::TENANT_ID, $ref['tenant_id']);
            self::assertSame(self::HOTEL_ID, $ref['hotel_id']);
            self::assertSame($cycle['metric_definition_digest'], $ref['metric_definition_digest']);
            self::assertSame('readback_verified', $ref['verification_status']);
        }
    }

    public function testCommandReplayIsIdempotentButPayloadReuseAndStageSkippingAreRejected(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycleId = (int)$opened['cycle']['id'];

        $skip = $this->transitionInput(
            'formal_save_exact_readback',
            1,
            'skip-stage',
            ['saved_rows' => true],
            [$this->ref('saved_rows', 'ota', 'online_daily_data', 1, 'ctrip')]
        );
        try {
            $service->transition($cycleId, self::TENANT_ID, [self::HOTEL_ID], $skip, 7);
            self::fail('Stage skipping should be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('禁止跳级', $error->getMessage());
        }
        self::assertSame(1, Db::name('hotel_operating_cycle_events')->count());

        $trusted = $this->trustedCollectionInput(1, 'trusted-once');
        $first = $service->transition($cycleId, self::TENANT_ID, [self::HOTEL_ID], $trusted, 7);
        $replayed = $service->transition($cycleId, self::TENANT_ID, [self::HOTEL_ID], $trusted, 7);
        self::assertTrue($first['created']);
        self::assertFalse($replayed['created']);
        self::assertSame(2, $replayed['cycle']['revision']);
        self::assertSame(2, Db::name('hotel_operating_cycle_events')->count());

        $changed = $trusted;
        $changed['payload']['truth_summary'] = '同一个 command_key 的另一份载荷';
        try {
            $service->transition($cycleId, self::TENANT_ID, [self::HOTEL_ID], $changed, 7);
            self::fail('A reused command key with a changed payload should be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('另一份迁移载荷', $error->getMessage());
        }

        $stale = $this->formalSaveInput(1, 'stale-version');
        try {
            $service->transition($cycleId, self::TENANT_ID, [self::HOTEL_ID], $stale, 7);
            self::fail('A stale revision should be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('版本冲突', $error->getMessage());
        }
        self::assertSame(2, Db::name('hotel_operating_cycle_events')->count());
        self::assertSame(7, Db::name('hotel_operating_cycle_evidence')->count());
    }

    public function testTenantScopeAndTamperedEvidenceCannotBeReadAsSuccess(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycleId = (int)$opened['cycle']['id'];

        $this->expectException(RuntimeException::class);
        $service->readVerified($cycleId, 99, [self::HOTEL_ID]);
    }

    public function testEvidenceScopeDriftBreaksExactReadback(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycleId = (int)$opened['cycle']['id'];
        Db::name('hotel_operating_cycle_evidence')
            ->where('cycle_id', $cycleId)
            ->where('evidence_role', 'source_identity')
            ->update(['tenant_id' => 99]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('事件证据摘要校验失败');
        $service->readVerified($cycleId, self::TENANT_ID, [self::HOTEL_ID]);
    }

    public function testOpenReplayRequiresTheSameCommandAndFrozenConfirmationPayload(): void
    {
        $service = new OperatingLoopKernelService();
        $first = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $replayed = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        self::assertTrue($first['created']);
        self::assertFalse($replayed['created']);
        self::assertSame(1, Db::name('hotel_operating_cycles')->count());
        self::assertSame(1, Db::name('hotel_operating_cycle_events')->count());

        $changed = $this->openInput();
        $changed['truth_summary'] = '另一份身份确认说明';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('另一份确认载荷');
        $service->open(self::TENANT_ID, self::HOTEL_ID, $changed, 7);
    }

    public function testCallerCannotRelabelAnUnrelatedRowAsCollectionEvidence(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $spoofed = $this->transitionInput(
            'trusted_collection',
            1,
            'spoofed-role',
            ['truth_summary' => '不应成立'],
            [$this->ref('collection_source', 'ota', 'hotels', self::HOTEL_ID, 'ctrip')]
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不属于当前闭环阶段合同');
        $service->transition(
            (int)$opened['cycle']['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $spoofed,
            7
        );
    }

    public function testRejectedOrSystemJudgementCannotAdvanceToExecution(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycle = $this->advanceToFacts($service, $opened['cycle']);
        $rejected = $this->approvedDecisionInput((int)$cycle['revision'], 'rejected-completed');
        $rejected['payload']['decision_status'] = 'rejected';
        unset($rejected['payload']['approved_by']);
        try {
            $service->transition((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID], $rejected, 7);
            self::fail('A rejected judgement cannot complete the decision stage.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('必须记录为 blocked', $error->getMessage());
        }

        $system = $this->approvedDecisionInput((int)$cycle['revision'], 'system-judgement');
        $system['actor_kind'] = 'system';
        try {
            $service->transition((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID], $system, 7);
            self::fail('A system actor cannot approve a human judgement.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('人工操作者', $error->getMessage());
        }

        Db::name('price_suggestions')->where('id', 1)->update([
            'status' => 3,
            'factors' => json_encode(['manual_review' => [
                'action' => 'reject',
                'status_after' => 'rejected',
                'reviewed_by' => 7,
                'auto_write_ota' => false,
                'ota_write' => false,
            ]]),
        ]);
        $blocked = $rejected;
        $blocked['stage_status'] = 'blocked';
        $blocked['command_key'] = 'rejected-blocked';
        $blocked['payload']['block_code'] = 'decision_rejected';
        $blocked['payload']['block_detail'] = '人工已拒绝本次建议，不得进入执行。';
        $blocked['payload']['next_action'] = '保留事实并等待下一次人工判断。';
        $result = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $blocked,
            7
        )['cycle'];
        self::assertSame('blocked', $result['cycle_status']);
        self::assertSame('recommendation_human_decision', $result['next_required_stage']);

        Db::name('price_suggestions')->where('id', 1)->update([
            'status' => 2,
            'factors' => json_encode(['manual_review' => [
                'action' => 'approve',
                'status_after' => 'approved',
                'reviewed_by' => 7,
                'auto_write_ota' => false,
                'ota_write' => false,
            ]]),
        ]);
        $recovered = $service->transition(
            (int)$result['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$result['revision'], 'approved-after-rejected-block'),
            7
        )['cycle'];
        self::assertSame('active', $recovered['cycle_status']);
        self::assertSame('recommendation_human_decision', $recovered['last_completed_stage']);
        self::assertSame('real_execution_receipt', $recovered['next_required_stage']);
    }

    public function testOpenRejectsSinglePmsSourceWithNoPlanOrNoOtaSource(): void
    {
        $input = $this->openInput();
        $input['source_identities'] = [[
            'source_kind' => 'pms',
            'platform' => 'dingdandao_pms',
            'provider_hotel_id' => 'DD-80',
            'evidence_ref' => [
                'table' => 'dingdandao_pms_integrations',
                'row_id' => self::PMS_INTEGRATION_ID,
            ],
        ]];
        $service = new OperatingLoopKernelService();
        try {
            $service->open(self::TENANT_ID, self::HOTEL_ID, $input, 7);
            self::fail('A source without an active collection-plan version must be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('绑定同一个生效采集计划', $error->getMessage());
        }

        $input['source_identities'][0] += $this->collectionPlanIdentityFields();
        $input['command_key'] = 'open-pms-only-cycle-80-20260810';
        try {
            $service->open(self::TENANT_ID, self::HOTEL_ID, $input, 7);
            self::fail('A PMS-only source set must not create an authoritative operating cycle.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('同时冻结一个 PMS', $error->getMessage());
        }
        self::assertSame(0, Db::name('hotel_operating_cycles')->count());
    }

    public function testRejectedIntentUsesTheRecordedReviewerInsteadOfItsCreator(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'status' => 'rejected',
            'created_by' => 6,
            'approved_by' => 9,
            'approved_at' => '2026-08-11 09:00:00',
        ]);
        $rejectedInput = $this->transitionInput(
            'recommendation_human_decision',
            (int)$cycle['revision'],
            'intent-rejected-by-reviewer',
            [
                'recommendation' => '调整携程价格。',
                'judgement' => '审批人认为当前证据不足，拒绝执行。',
                'judged_by' => 9,
                'decision_status' => 'rejected',
                'block_code' => 'decision_rejected',
                'block_detail' => '正式审批记录已拒绝。',
                'next_action' => '补充事实后重新判断。',
            ],
            [
                $this->ref('recommendation', 'decision', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('human_decision', 'approval', 'operation_execution_intents', 1, 'ctrip'),
            ],
            '2026-08-11 09:00:00'
        );
        $rejectedInput['stage_status'] = 'blocked';
        $result = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $rejectedInput,
            9
        )['cycle'];

        self::assertSame('blocked', $result['cycle_status']);
        self::assertSame(9, $result['summary']['actors']['judged_by']);
    }

    public function testOutcomeUsesTheMetricFrozenByTheHumanDecisionWithinTheDailyBundle(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $definition = [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => 'orders',
            'source_table' => 'online_daily_data',
            'comparison_policy' => 'same_hotel_same_platform_same_metric',
        ];
        $approvalBundle = $this->freezeApprovedIntent($definition, 'orders');
        $memberDigest = $approvalBundle['metric_definition_digest'];
        $target = $approvalBundle['target_value'];
        Db::name('operation_execution_intents')->where('id', 1)->update(['created_by' => 6]);
        Db::name('operation_effect_reviews')->where('id', 1)->delete();
        Db::name('operation_execution_evidence')->where('id', 2)->delete();
        $this->saveEffectReview($definition, 'orders');

        $cycle = $service->transition(
            (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
            $this->transitionInput('recommendation_human_decision', (int)$cycle['revision'], 'intent-approved-member-metric', [
                'recommendation' => '调整携程价格并观察订单量。',
                'judgement' => '批准，并冻结 orders 同口径指标。',
                'judged_by' => 7,
                'approved_by' => 7,
                'decision_status' => 'approved',
                'outcome_metric_definition_digest' => $memberDigest,
                'priority_issue' => '携程订单量需要改善。',
                'next_action' => '由值班经理执行并保存回执。',
                'next_owner' => ['user_id' => 8],
                'review_due_at' => '2026-08-11 10:00:00',
            ], [
                $this->ref('recommendation', 'decision', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('human_decision', 'approval', 'operation_execution_intents', 1, 'ctrip'),
            ], '2026-08-11 09:00:00'), 7
        )['cycle'];
        $cycle = $service->transition(
            (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
            $this->transitionInput('real_execution_receipt', (int)$cycle['revision'], 'execution-member-metric', [
                'executed_by' => 8,
                'intent_id' => 1,
                'task_id' => 1,
                'object_type' => 'price',
                'action_type' => 'adjust_price',
                'target_value_digest' => $this->valueDigest($target),
                'executed_action' => '已执行携程价格调整。',
                'executed_at' => '2026-08-11 09:30:00',
                'next_action' => '等待同口径结果回读。',
            ], [
                $this->ref('execution_intent', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('execution_task', 'execution', 'operation_execution_tasks', 1, 'ctrip'),
                $this->ref('execution_receipt', 'execution', 'operation_execution_evidence', 1, 'ctrip'),
            ], '2026-08-11 09:31:00'), 8
        )['cycle'];
        $cycle = $service->transition(
            (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
            $this->transitionInput('comparable_outcome_readback', (int)$cycle['revision'], 'outcome-member-metric', [
                'outcome_status' => 'supported',
                'reviewed_by' => 7,
                'result_summary' => '同口径携程订单量达到人工冻结目标；仅记录相关性，不宣称因果。',
                'metric_definition_digest' => $memberDigest,
            ], [$this->ref('outcome_readback', 'outcome', 'operation_effect_reviews', 1, 'ctrip')], '2026-08-11 10:05:00'), 7
        )['cycle'];

        self::assertNotSame($cycle['metric_definition_digest'], $memberDigest);
        self::assertSame($memberDigest, $cycle['summary']['decision']['outcome_metric_definition_digest']);
        self::assertSame('supported', $cycle['summary']['yesterday_result']['status']);
    }

    public function testExecutionStageRejectsEmptyPlaceholderEvidence(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$cycle['revision'], 'placeholder-decision'),
            7
        )['cycle'];
        Db::name('operation_execution_evidence')->where('id', 1)->update([
            'before_json' => null,
            'after_json' => null,
            'platform_response_json' => null,
            'attachment_path' => '',
            'remark' => '',
            'created_by' => 0,
        ]);

        try {
            $service->transition(
                (int)$cycle['id'],
                self::TENANT_ID,
                [self::HOTEL_ID],
                $this->executionInput((int)$cycle['revision'], 'placeholder-execution'),
                8
            );
            self::fail('An empty execution-evidence placeholder must not complete the execution stage.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('执行回执不属于当前执行任务', $error->getMessage());
        }
        $readback = $service->readVerified((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID]);
        self::assertSame('real_execution_receipt', $readback['next_required_stage']);
        self::assertSame((int)$cycle['revision'], $readback['revision']);
    }

    public function testExecutionStageRejectsRemarkOnlyEvidence(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$cycle['revision'], 'remark-only-decision'),
            7
        )['cycle'];
        Db::name('operation_execution_evidence')->where('id', 1)->update([
            'before_json' => null,
            'after_json' => null,
            'platform_response_json' => null,
            'attachment_path' => '',
            'remark' => '执行人只写了备注，没有状态变化、附件或平台回执。',
            'created_by' => 8,
        ]);

        try {
            $service->transition(
                (int)$cycle['id'],
                self::TENANT_ID,
                [self::HOTEL_ID],
                $this->executionInput((int)$cycle['revision'], 'remark-only-execution'),
                8
            );
            self::fail('A remark-only execution receipt must not complete the execution stage.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('执行回执不属于当前执行任务', $error->getMessage());
        }
        $readback = $service->readVerified((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID]);
        self::assertSame('real_execution_receipt', $readback['next_required_stage']);
        self::assertSame((int)$cycle['revision'], $readback['revision']);
    }

    public function testExecutionStageRejectsUnchangedArbitraryBeforeAfterEvidence(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$cycle['revision'], 'placeholder-state-decision'),
            7
        )['cycle'];
        Db::name('operation_execution_evidence')->where('id', 1)->update([
            'before_json' => '{"arbitrary":"same-placeholder"}',
            'after_json' => '{"arbitrary":"same-placeholder"}',
            'platform_response_json' => null,
            'attachment_path' => '',
            'remark' => 'placeholder before and after only',
            'created_by' => 8,
        ]);

        try {
            $service->transition(
                (int)$cycle['id'],
                self::TENANT_ID,
                [self::HOTEL_ID],
                $this->executionInput((int)$cycle['revision'], 'placeholder-state-execution'),
                8
            );
            self::fail('Unchanged arbitrary before/after placeholders must not complete the execution stage.');
        } catch (InvalidArgumentException $error) {
            self::assertNotSame('', trim($error->getMessage()));
        }
        $readback = $service->readVerified((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID]);
        self::assertSame('real_execution_receipt', $readback['next_required_stage']);
        self::assertSame((int)$cycle['revision'], $readback['revision']);
    }

    public function testDecisionEvidenceUsesFrozenBaselineForMultiDayIntent(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $definition = [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => 'orders',
            'source_table' => 'online_daily_data',
            'comparison_policy' => 'same_hotel_same_platform_same_metric',
        ];
        $approvalBundle = $this->freezeApprovedIntent(
            $definition,
            'orders',
            '2026-08-01',
            self::BUSINESS_DATE
        );
        $metricDigest = $approvalBundle['metric_definition_digest'];

        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->transitionInput(
                'recommendation_human_decision',
                (int)$cycle['revision'],
                'multi-day-frozen-baseline-decision',
                [
                    'recommendation' => '按多日观察窗口形成携程渠道动作。',
                    'judgement' => '批准，并以冻结基准日做同口径复盘。',
                    'judged_by' => 7,
                    'approved_by' => 7,
                    'decision_status' => 'approved',
                    'outcome_metric_definition_digest' => $metricDigest,
                    'priority_issue' => '多日观察窗口需要统一结果基准。',
                    'next_action' => '由值班经理执行并保存正式回执。',
                    'next_owner' => ['user_id' => 8],
                    'review_due_at' => '2026-08-11 10:00:00',
                ],
                [
                    $this->ref('recommendation', 'decision', 'operation_execution_intents', 1, 'ctrip'),
                    $this->ref('human_decision', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                ],
                '2026-08-11 09:00:00'
            ),
            7
        )['cycle'];

        self::assertSame('recommendation_human_decision', $cycle['last_completed_stage']);
        self::assertSame('real_execution_receipt', $cycle['next_required_stage']);
        self::assertSame($metricDigest, $cycle['summary']['decision']['outcome_metric_definition_digest']);
    }

    public function testOutcomeStageRejectsAReviewWithArbitraryContentDigest(): void
    {
        $service = new OperatingLoopKernelService();
        $cycle = $this->advanceToFacts($service, $service->open(
            self::TENANT_ID,
            self::HOTEL_ID,
            $this->openInput(),
            7
        )['cycle']);
        $definition = [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => 'orders',
            'source_table' => 'online_daily_data',
            'comparison_policy' => 'same_hotel_same_platform_same_metric',
        ];
        $approvalBundle = $this->freezeApprovedIntent($definition, 'orders');
        $memberDigest = $approvalBundle['metric_definition_digest'];
        $target = $approvalBundle['target_value'];
        Db::name('operation_effect_reviews')->where('id', 1)->delete();
        Db::name('operation_execution_evidence')->where('id', 2)->delete();
        $this->saveEffectReview($definition, 'orders');

        $cycle = $service->transition(
            (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
            $this->transitionInput('recommendation_human_decision', (int)$cycle['revision'], 'digest-decision', [
                'recommendation' => '执行动作并观察同口径订单量。',
                'judgement' => '批准并冻结 orders 指标。',
                'judged_by' => 7,
                'approved_by' => 7,
                'decision_status' => 'approved',
                'outcome_metric_definition_digest' => $memberDigest,
                'priority_issue' => '同口径订单量需要验证。',
                'next_action' => '由执行人完成动作并保存正式回执。',
                'next_owner' => ['user_id' => 8],
                'review_due_at' => '2026-08-11 10:00:00',
            ], [
                $this->ref('recommendation', 'decision', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('human_decision', 'approval', 'operation_execution_intents', 1, 'ctrip'),
            ], '2026-08-11 09:00:00'), 7
        )['cycle'];
        $cycle = $service->transition(
            (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
            $this->transitionInput('real_execution_receipt', (int)$cycle['revision'], 'digest-execution', [
                'executed_by' => 8,
                'intent_id' => 1,
                'task_id' => 1,
                'object_type' => 'price',
                'action_type' => 'adjust_price',
                'target_value_digest' => $this->valueDigest($target),
                'executed_action' => '已执行正式动作。',
                'executed_at' => '2026-08-11 09:30:00',
            ], [
                $this->ref('execution_intent', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('execution_task', 'execution', 'operation_execution_tasks', 1, 'ctrip'),
                $this->ref('execution_receipt', 'execution', 'operation_execution_evidence', 1, 'ctrip'),
            ], '2026-08-11 09:31:00'), 8
        )['cycle'];
        Db::name('operation_effect_reviews')->where('id', 1)->update([
            'result_summary' => '摘要已被篡改，但旧摘要哈希仍被保留。',
        ]);

        try {
            $service->transition(
                (int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID],
                $this->transitionInput('comparable_outcome_readback', (int)$cycle['revision'], 'digest-outcome', [
                    'outcome_status' => 'supported',
                    'reviewed_by' => 7,
                    'result_summary' => '摘要已被篡改，但旧摘要哈希仍被保留。',
                    'metric_definition_digest' => $memberDigest,
                ], [$this->ref('outcome_readback', 'outcome', 'operation_effect_reviews', 1, 'ctrip')], '2026-08-11 10:05:00'), 7
            );
            self::fail('A drifted effect review must not be admitted by the authoritative kernel.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('严格保存回读', $error->getMessage());
        }
        $readback = $service->readVerified((int)$cycle['id'], self::TENANT_ID, [self::HOTEL_ID]);
        self::assertSame('comparable_outcome_readback', $readback['next_required_stage']);
    }

    public function testSourceIdentitiesCannotMixCollectionPlanVersions(): void
    {
        $input = $this->openInput();
        $input['source_identities'] = [
            [
                'source_kind' => 'ota',
                'platform' => 'ctrip',
                'platform_hotel_id' => 'CTRIP-80',
                'data_source_id' => 1,
                'collection_plan_id' => 3,
                'collection_plan_version' => 3,
                'collection_plan_hash' => str_repeat('a', 64),
                'evidence_ref' => ['table' => 'ota_local_collector_account_hotels', 'row_id' => 1],
            ],
            [
                'source_kind' => 'pms',
                'platform' => 'dingdandao_pms',
                'provider_hotel_id' => 'DD-80',
                'collection_plan_id' => 2,
                'collection_plan_version' => 2,
                'collection_plan_hash' => str_repeat('b', 64),
                'evidence_ref' => ['table' => 'dingdandao_pms_integrations', 'row_id' => 2],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('同一个生效采集计划版本');
        (new OperatingLoopKernelService())->open(self::TENANT_ID, self::HOTEL_ID, $input, 7);
    }

    public function testRootJsonDriftFailsClosed(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycleId = (int)$opened['cycle']['id'];
        Db::name('hotel_operating_cycles')->where('id', $cycleId)->update([
            'metric_definition_json' => json_encode(['metric_key' => 'tampered']),
        ]);
        try {
            $service->readVerified($cycleId, self::TENANT_ID, [self::HOTEL_ID]);
            self::fail('Drifted metric JSON must fail exact readback.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('冻结摘要不一致', $error->getMessage());
        }
    }

    public function testCrossTenantExecutionReceiptFailsClosed(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycle = $this->advanceToFacts($service, $opened['cycle']);
        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$cycle['revision'], 'approved-for-execution'),
            7
        )['cycle'];
        Db::name('operation_execution_evidence')->where('id', 1)->update(['tenant_id' => 99]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('执行证据与闭环租户或酒店不一致');
        $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->executionInput((int)$cycle['revision'], 'cross-tenant-receipt'),
            8
        );
    }

    public function testExecutionMustUseTheApprovedSuggestionAndFrozenTarget(): void
    {
        $service = new OperatingLoopKernelService();
        $opened = $service->open(self::TENANT_ID, self::HOTEL_ID, $this->openInput(), 7);
        $cycle = $this->advanceToFacts($service, $opened['cycle']);
        $cycle = $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->approvedDecisionInput((int)$cycle['revision'], 'decision-for-link-test'),
            7
        )['cycle'];

        Db::name('operation_execution_intents')->where('id', 1)->update(['source_record_id' => 999]);
        try {
            $service->transition(
                (int)$cycle['id'],
                self::TENANT_ID,
                [self::HOTEL_ID],
                $this->executionInput((int)$cycle['revision'], 'wrong-source-link'),
                8
            );
            self::fail('An unrelated approved intent must not satisfy this cycle.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('获批的建议记录', $error->getMessage());
        }

        Db::name('operation_execution_intents')->where('id', 1)->update(['source_record_id' => 1]);
        $wrongTarget = $this->executionInput((int)$cycle['revision'], 'wrong-target');
        $wrongTarget['payload']['target_value_digest'] = str_repeat('f', 64);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('人工批准目标不一致');
        $service->transition(
            (int)$cycle['id'],
            self::TENANT_ID,
            [self::HOTEL_ID],
            $wrongTarget,
            8
        );
    }

    public function testMigrationDeclaresScopedVersionedRestrictedKernelTables(): void
    {
        $migrationDir = dirname(__DIR__) . '/database/migrations/';
        $sql = (string)file_get_contents(
            $migrationDir . '20260812_z_create_hotel_operating_cycle_kernel.sql'
        ) . "\n" . (string)file_get_contents(
            $migrationDir . '20260812_za_harden_hotel_operating_cycle_kernel.sql'
        );
        foreach ([
            'UNIQUE KEY `uniq_hotel_operating_cycle_authority` (`tenant_id`, `hotel_id`, `business_date`)',
            '`command_digest` CHAR(64) NOT NULL',
            '`from_version` INT UNSIGNED NOT NULL',
            '`to_version` INT UNSIGNED NOT NULL',
            '`verification_status` VARCHAR(24) NOT NULL',
            'FOREIGN KEY (`cycle_id`) REFERENCES `hotel_operating_cycles` (`id`)',
            'ON UPDATE RESTRICT ON DELETE RESTRICT',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
    }

    /** @return array<string,mixed> */
    private function completeCycle(OperatingLoopKernelService $service, array $cycle): array
    {
        $cycleId = (int)$cycle['id'];
        $approvalBundle = $this->freezeApprovedIntent(self::METRIC_DEFINITION, 'ota_room_nights');
        $decisionMetricDigest = $approvalBundle['metric_definition_digest'];
        $approvedTarget = $approvalBundle['target_value'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->trustedCollectionInput(1, 'trusted'),
            7
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->formalSaveInput((int)$cycle['revision'], 'saved'),
            7
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->factsInput((int)$cycle['revision'], 'facts'),
            7
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->transitionInput(
                'recommendation_human_decision',
                (int)$cycle['revision'],
                'decision',
                [
                    'recommendation' => '调整携程价格并保持其他渠道不变。',
                    'judgement' => '批准该渠道动作，观察同口径订单量。',
                    'judged_by' => 7,
                    'approved_by' => 7,
                    'decision_status' => 'approved',
                    'outcome_metric_definition_digest' => $decisionMetricDigest,
                    'priority_issue' => '携程渠道转化不足是当前最重要问题。',
                    'next_action' => '由值班经理执行携程价格调整并保存回执。',
                    'next_owner' => ['user_id' => 8, 'name' => '值班经理'],
                    'review_due_at' => '2026-08-11 10:00:00',
                ],
                [
                    $this->ref('recommendation', 'decision', 'operation_execution_intents', 1, 'ctrip'),
                    $this->ref('human_decision', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                ],
                '2026-08-11 09:00:00'
            ),
            7
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->transitionInput(
                'real_execution_receipt',
                (int)$cycle['revision'],
                'execution',
                [
                    'executed_by' => 8,
                    'intent_id' => 1,
                    'task_id' => 1,
                    'object_type' => 'price',
                    'action_type' => 'adjust_price',
                    'target_value_digest' => $this->valueDigest($approvedTarget),
                    'executed_action' => '携程基础价从 300 元调整为 320 元。',
                    'executed_at' => '2026-08-11 09:30:00',
                    'next_action' => '等待 10:00 后回读同口径订单量。',
                ],
                [
                    $this->ref('execution_intent', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                    $this->ref('execution_task', 'execution', 'operation_execution_tasks', 1, 'ctrip'),
                    $this->ref('execution_receipt', 'execution', 'operation_execution_evidence', 1, 'ctrip'),
                ],
                '2026-08-11 09:31:00'
            ),
            8
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->transitionInput(
                'comparable_outcome_readback',
                (int)$cycle['revision'],
                'outcome',
                [
                    'outcome_status' => 'supported',
                    'reviewed_by' => 7,
                    'result_summary' => '同口径携程订单量达到人工冻结目标；仅记录相关性，不宣称因果。',
                    'metric_definition_digest' => $decisionMetricDigest,
                ],
                [$this->ref('outcome_readback', 'outcome', 'operation_effect_reviews', 1, 'ctrip')],
                '2026-08-11 10:05:00'
            ),
            7
        )['cycle'];
        return $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->transitionInput(
                'review_experience_promotion',
                (int)$cycle['revision'],
                'experience',
                [
                    'reviewed_by' => 7,
                    'experience_status' => 'not_reusable',
                ],
                [$this->ref('operating_memory', 'knowledge', 'hotel_operating_memories', 1, 'ctrip')],
                '2026-08-11 10:10:00'
            ),
            7
        )['cycle'];
    }

    /** @return array<string,mixed> */
    private function advanceToFacts(OperatingLoopKernelService $service, array $cycle): array
    {
        $cycleId = (int)$cycle['id'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->trustedCollectionInput((int)$cycle['revision'], 'trusted-to-facts'),
            7
        )['cycle'];
        $cycle = $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->formalSaveInput((int)$cycle['revision'], 'saved-to-facts'),
            7
        )['cycle'];
        return $service->transition(
            $cycleId,
            self::TENANT_ID,
            [self::HOTEL_ID],
            $this->factsInput((int)$cycle['revision'], 'facts-to-decision'),
            7
        )['cycle'];
    }

    /** @return array<string,mixed> */
    private function approvedDecisionInput(int $version, string $commandKey): array
    {
        return $this->transitionInput(
            'recommendation_human_decision',
            $version,
            $commandKey,
            [
                'recommendation' => '调整携程价格并保持其他渠道不变。',
                'judgement' => '批准该渠道动作，观察同口径订单量。',
                'judged_by' => 7,
                'approved_by' => 7,
                'decision_status' => 'approved',
                'priority_issue' => '携程渠道转化不足是当前最重要问题。',
                'next_action' => '由值班经理执行携程价格调整并保存回执。',
                'next_owner' => ['user_id' => 8, 'name' => '值班经理'],
                'review_due_at' => '2026-08-11 10:00:00',
            ],
            [
                $this->ref('recommendation', 'decision', 'price_suggestions', 1, 'ctrip'),
                $this->ref('human_decision', 'approval', 'price_suggestions', 1, 'ctrip'),
            ],
            '2026-08-11 09:00:00'
        );
    }

    /** @return array<string,mixed> */
    private function executionInput(int $version, string $commandKey): array
    {
        return $this->transitionInput(
            'real_execution_receipt',
            $version,
            $commandKey,
            [
                'executed_by' => 8,
                'intent_id' => 1,
                'task_id' => 1,
                'object_type' => 'price',
                'action_type' => 'adjust_price',
                'target_value_digest' => $this->valueDigest(
                    $this->approvalBundle(self::METRIC_DEFINITION, 'ota_room_nights')['target_value']
                ),
                'executed_action' => '携程基础价从 300 元调整为 320 元。',
                'executed_at' => '2026-08-11 09:30:00',
                'next_action' => '等待 10:00 后回读同口径订单量。',
            ],
            [
                $this->ref('execution_intent', 'approval', 'operation_execution_intents', 1, 'ctrip'),
                $this->ref('execution_task', 'execution', 'operation_execution_tasks', 1, 'ctrip'),
                $this->ref('execution_receipt', 'execution', 'operation_execution_evidence', 1, 'ctrip'),
            ],
            '2026-08-11 09:31:00'
        );
    }

    /** @return array<string,mixed> */
    private function openInput(): array
    {
        return [
            'business_date' => self::BUSINESS_DATE,
            'metric_version' => 'ota-room-nights.v1',
            'metric_definition' => self::METRIC_DEFINITION,
            'source_identities' => [
                [
                    'source_kind' => 'ota',
                    'platform' => 'ctrip',
                    'platform_hotel_id' => 'CTRIP-80',
                    'data_source_id' => self::CTRIP_DATA_SOURCE_ID,
                    'evidence_ref' => [
                        'table' => 'platform_data_sources',
                        'row_id' => self::CTRIP_DATA_SOURCE_ID,
                    ],
                ] + $this->collectionPlanIdentityFields(),
                [
                    'source_kind' => 'ota',
                    'platform' => 'meituan',
                    'platform_hotel_id' => 'MEITUAN-80',
                    'data_source_id' => self::MEITUAN_DATA_SOURCE_ID,
                    'evidence_ref' => [
                        'table' => 'platform_data_sources',
                        'row_id' => self::MEITUAN_DATA_SOURCE_ID,
                    ],
                ] + $this->collectionPlanIdentityFields(),
                [
                    'source_kind' => 'pms',
                    'platform' => 'dingdandao_pms',
                    'provider_hotel_id' => 'DD-80',
                    'evidence_ref' => [
                        'table' => 'dingdandao_pms_integrations',
                        'row_id' => self::PMS_INTEGRATION_ID,
                    ],
                ] + $this->collectionPlanIdentityFields(),
            ],
            'source_module' => 'operating_loop_test',
            'command_key' => 'open-cycle-80-20260810',
            'truth_summary' => '宿析酒店 80、携程/美团门店、订单来了 PMS、业务日期和指标版本已确认。',
        ];
    }

    /** @return array<string,mixed> */
    private function trustedCollectionInput(int $version, string $commandKey): array
    {
        return $this->transitionInput(
            'trusted_collection',
            $version,
            $commandKey,
            ['truth_summary' => '携程、美团与订单来了 PMS 的同计划采集回执已完成且可回读。'],
            [
                $this->ref('collection_source', 'pms', 'hotel_collection_plan_runs', 1, 'dingdandao_pms'),
                $this->ref('collection_source', 'ota', 'hotel_collection_plan_run_sources', 1, 'ctrip'),
                $this->ref('collection_source', 'ota', 'hotel_collection_plan_run_sources', 2, 'meituan'),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function formalSaveInput(int $version, string $commandKey): array
    {
        return $this->transitionInput(
            'formal_save_exact_readback',
            $version,
            $commandKey,
            [
                'saved_rows' => true,
                'truth_summary' => '三个冻结来源的保存行与采集回执已逐一精确回读。',
            ],
            [
                $this->ref('collection_source', 'pms', 'hotel_collection_plan_runs', 1, 'dingdandao_pms'),
                $this->ref('collection_source', 'ota', 'hotel_collection_plan_run_sources', 1, 'ctrip'),
                $this->ref('collection_source', 'ota', 'hotel_collection_plan_run_sources', 2, 'meituan'),
                $this->ref('saved_rows', 'pms', 'dingdandao_operating_target_captures', 1, 'dingdandao_pms'),
                $this->ref('saved_rows', 'ota', 'online_daily_data', 1, 'ctrip'),
                $this->ref('saved_rows', 'ota', 'online_daily_data', 2, 'meituan'),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function factsInput(int $version, string $commandKey): array
    {
        return $this->transitionInput(
            'operating_facts_established',
            $version,
            $commandKey,
            [
                'metric_definition_digest' => $this->metricDigest(),
                'truth_summary' => '订单来了 PMS 全酒店住宿事实与携程/美团 OTA 渠道事实已分域核验；未将不同口径收入相加。',
                'priority_issue' => '携程渠道转化不足是当前最重要问题。',
                'fact_scope' => ['pms_plus_ota_revenue_addition_allowed' => false],
            ],
            [
                $this->ref('pms_fact_rows', 'pms', 'dingdandao_operating_target_captures', 1, 'dingdandao_pms'),
                $this->ref('ota_fact_rows', 'ota', 'online_daily_data', 1, 'ctrip'),
                $this->ref('ota_fact_rows', 'ota', 'online_daily_data', 2, 'meituan'),
            ]
        );
    }

    /** @return array<string,int|string> */
    private function collectionPlanIdentityFields(): array
    {
        return [
            'collection_plan_id' => self::COLLECTION_PLAN_ID,
            'collection_plan_version' => self::COLLECTION_PLAN_VERSION,
            'collection_plan_hash' => $this->collectionPlanHash(),
        ];
    }

    /** @return array<string,mixed> */
    private function transitionInput(
        string $stage,
        int $version,
        string $commandKey,
        array $payload,
        array $refs,
        string $occurredAt = '2026-08-11 08:00:00'
    ): array {
        return [
            'target_stage' => $stage,
            'stage_status' => 'completed',
            'expected_version' => $version,
            'command_key' => $commandKey,
            'source_module' => 'operating_loop_test',
            'actor_kind' => 'human',
            'occurred_at' => $occurredAt,
            'payload' => $payload,
            'evidence_refs' => $refs,
        ];
    }

    /** @return array<string,mixed> */
    private function ref(string $role, string $kind, string $table, int $id, string $platform = ''): array
    {
        return [
            'role' => $role,
            'source_kind' => $kind,
            'table' => $table,
            'row_ids' => [$id],
            'platform' => $platform,
            'business_date' => self::BUSINESS_DATE,
        ];
    }

    /** @return array<string,mixed> */
    private function evidenceByRole(array $cycle, string $role): array
    {
        foreach ($cycle['evidence_refs'] as $ref) {
            if ($ref['role'] === $role) {
                return $ref;
            }
        }
        self::fail('Missing evidence role ' . $role);
    }

    private function metricDigest(): string
    {
        return hash('sha256', (string)json_encode(
            self::METRIC_DEFINITION,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function valueDigest(array $value): string
    {
        $value = $this->canonicalizeValue($value);
        return hash('sha256', (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    private function canonicalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeValue($item);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function collectionPlanSourcePlan(): array
    {
        return [
            'pms' => [
                'provider' => 'dingdandao_pms',
                'integration_id' => self::PMS_INTEGRATION_ID,
            ],
            'ctrip' => ['data_source_id' => self::CTRIP_DATA_SOURCE_ID],
            'meituan' => ['data_source_id' => self::MEITUAN_DATA_SOURCE_ID],
        ];
    }

    private function collectionPlanHash(): string
    {
        return $this->valueDigest($this->collectionPlanSourcePlan());
    }

    /** @return array{metric_definition_digest:string,approval_target:array<string,mixed>,target_value:array<string,mixed>,evidence:array<string,mixed>} */
    private function approvalBundle(
        array $definition,
        string $metricKey,
        string $baselineDate = self::BUSINESS_DATE,
        string $reviewDate = '2026-08-11'
    ): array {
        $metricDefinitionDigest = $this->valueDigest([
            'metric_key' => $metricKey,
            'definition' => $definition,
        ]);
        $approvalTarget = [
            'version' => 'ota_execution_approval_target.v1',
            'intent_id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'source_module' => 'price_suggestion',
            'source_record_id' => 1,
            'platform' => 'ctrip',
            'baseline_business_date' => $baselineDate,
            'review_business_date' => $reviewDate,
            'expected_metric' => $metricKey,
            'metric_definition' => $definition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'expected_direction' => 'increase',
            'target_type' => 'absolute',
            'target_value' => '8.000000',
            'expected_delta' => null,
            'expected_delta_status' => 'manual_confirmed',
            'approved_by' => 7,
            'approved_at' => '2026-08-11 09:00:00',
            'diagnosis_recommendation_digest' => '',
            'source_policy' => 'saved_diagnosis_metric_and_human_target_frozen_before_task_creation',
        ];
        $approvalTarget['content_digest'] = $this->valueDigest($approvalTarget);
        $targetValue = [
            'price' => 320,
            'target_metric' => $metricKey,
            'target_type' => 'absolute',
            'expected_direction' => 'increase',
            'expected_delta_status' => 'manual_confirmed',
            'expected_target' => 8,
            'review_business_date' => $reviewDate,
            'review_at' => $reviewDate . ' 10:00:00',
            'workflow_schedule' => ['review_at' => $reviewDate . ' 10:00:00'],
            'metric_definition' => $definition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'approval_target_digest' => $approvalTarget['content_digest'],
        ];
        $evidence = [
            'expected_delta_status' => 'manual_confirmed',
            'target_type' => 'absolute',
            'expected_direction' => 'increase',
            'target_value' => 8,
            'expected_delta' => null,
            'review_business_date' => $reviewDate,
            'metric_definition' => $definition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'approval_target' => $approvalTarget,
            'approval_target_digest' => $approvalTarget['content_digest'],
        ];

        return [
            'metric_definition_digest' => $metricDefinitionDigest,
            'approval_target' => $approvalTarget,
            'target_value' => $targetValue,
            'evidence' => $evidence,
        ];
    }

    /** @return array{metric_definition_digest:string,approval_target:array<string,mixed>,target_value:array<string,mixed>,evidence:array<string,mixed>} */
    private function freezeApprovedIntent(
        array $definition,
        string $metricKey,
        string $dateStart = self::BUSINESS_DATE,
        ?string $dateEnd = null
    ): array {
        $bundle = $this->approvalBundle($definition, $metricKey);
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'expected_metric' => $metricKey,
            'target_value_json' => json_encode(
                $bundle['target_value'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'evidence_json' => json_encode(
                $bundle['evidence'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'approved_at' => '2026-08-11 09:00:00',
        ]);
        Db::name('operation_execution_tasks')->where('id', 1)->update([
            'target_value_json' => json_encode(
                $bundle['target_value'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
        return $bundle;
    }

    private function seedEvidenceChain(): void
    {
        $now = '2026-08-11 08:00:00';
        Db::name('hotels')->insert([
            'id' => self::HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
            'name' => '宿析测试酒店',
            'status' => 1,
        ]);
        Db::name('ota_local_collector_account_hotels')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'platform' => 'ctrip',
            'platform_hotel_id' => 'CTRIP-80',
        ]);
        Db::name('platform_data_sources')->insertAll([
            [
                'id' => self::CTRIP_DATA_SOURCE_ID,
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => 'ctrip',
                'platform_hotel_id' => 'CTRIP-80',
                'config_json' => json_encode(['platform_hotel_id' => 'CTRIP-80']),
            ],
            [
                'id' => self::MEITUAN_DATA_SOURCE_ID,
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => 'meituan',
                'platform_hotel_id' => 'MEITUAN-80',
                'config_json' => json_encode(['platform_hotel_id' => 'MEITUAN-80']),
            ],
        ]);
        Db::name('dingdandao_pms_integrations')->insert([
            'id' => self::PMS_INTEGRATION_ID,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => 'DD-80',
        ]);
        Db::name('hotel_collection_plans')->insert([
            'id' => self::COLLECTION_PLAN_ID,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'plan_version' => self::COLLECTION_PLAN_VERSION,
            'plan_hash' => $this->collectionPlanHash(),
            'plan_status' => 'active',
            'enabled' => 1,
            'active_slot' => 1,
            'source_plan_json' => json_encode(
                $this->collectionPlanSourcePlan(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'provider' => 'dingdandao_pms',
            'quality_status' => 'verified',
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'readback_status' => 'readback_verified',
        ]);
        Db::name('hotel_collection_plan_runs')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'plan_id' => self::COLLECTION_PLAN_ID,
            'plan_version' => self::COLLECTION_PLAN_VERSION,
            'plan_hash' => $this->collectionPlanHash(),
            'pms_provider' => 'dingdandao_pms',
            'pms_status' => 'verified',
            'pms_capture_id' => 1,
            'pms_readback_verified' => 1,
        ]);
        Db::name('hotel_collection_plan_run_sources')->insertAll([
            [
                'id' => 1,
                'run_id' => 1,
                'platform' => 'ctrip',
                'data_source_id' => self::CTRIP_DATA_SOURCE_ID,
                'platform_sync_task_id' => 12,
                'status' => 'success',
                'saved_row_count' => 1,
                'readback_row_count' => 1,
                'readback_verified' => 1,
                'evidence_digest' => hash('sha256', (string)json_encode([
                    'platform' => 'ctrip',
                    'data_source_id' => self::CTRIP_DATA_SOURCE_ID,
                    'sync_task_id' => 12,
                    'row_ids' => [1],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ],
            [
                'id' => 2,
                'run_id' => 1,
                'platform' => 'meituan',
                'data_source_id' => self::MEITUAN_DATA_SOURCE_ID,
                'platform_sync_task_id' => 13,
                'status' => 'success',
                'saved_row_count' => 1,
                'readback_row_count' => 1,
                'readback_verified' => 1,
                'evidence_digest' => hash('sha256', (string)json_encode([
                    'platform' => 'meituan',
                    'data_source_id' => self::MEITUAN_DATA_SOURCE_ID,
                    'sync_task_id' => 13,
                    'row_ids' => [2],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ],
        ]);
        Db::name('online_daily_data')->insertAll([
            [
                'id' => 1,
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'data_date' => self::BUSINESS_DATE,
                'source' => 'ctrip',
                'history_status' => 'success',
                'validation_status' => 'verified',
                'readback_verified' => 1,
                'room_nights' => 6,
            ],
            [
                'id' => 2,
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'data_date' => self::BUSINESS_DATE,
                'source' => 'meituan',
                'history_status' => 'success',
                'validation_status' => 'verified',
                'readback_verified' => 1,
                'room_nights' => 4,
            ],
        ]);
        Db::name('price_suggestions')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'suggestion_date' => self::BUSINESS_DATE,
            'status' => 2,
            'applied_by' => 7,
            'factors' => json_encode(['manual_review' => [
                'action' => 'approve',
                'status_after' => 'approved',
                'reviewed_by' => 7,
                'auto_write_ota' => false,
                'ota_write' => false,
            ]]),
        ]);
        $approvalBundle = $this->approvalBundle(self::METRIC_DEFINITION, 'ota_room_nights');
        Db::name('operation_execution_intents')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'platform' => 'ctrip',
            'date_start' => self::BUSINESS_DATE,
            'date_end' => null,
            'source_module' => 'price_suggestion',
            'source_record_id' => 1,
            'object_type' => 'price',
            'action_type' => 'adjust_price',
            'expected_metric' => 'ota_room_nights',
            'target_value_json' => json_encode($approvalBundle['target_value']),
            'evidence_json' => json_encode($approvalBundle['evidence']),
            'status' => 'approved',
            'approved_by' => 7,
            'approved_at' => '2026-08-11 09:00:00',
        ]);
        Db::name('operation_execution_tasks')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'intent_id' => 1,
            'operator_id' => 8,
            'target_value_json' => json_encode($approvalBundle['target_value']),
            'status' => 'executed',
            'executed_at' => '2026-08-11 09:30:00',
        ]);
        Db::name('operation_execution_evidence')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'task_id' => 1,
            'evidence_type' => 'api_response',
            'before_json' => json_encode(['price_status' => 'old']),
            'after_json' => json_encode(['price_status' => 'updated']),
            'attachment_path' => '',
            'platform_response_json' => json_encode(['receipt_status' => 'accepted']),
            'remark' => '执行人已保存平台动作回执。',
            'created_by' => 8,
        ]);
        $this->saveEffectReview(self::METRIC_DEFINITION, 'ota_room_nights');
        Db::name('hotel_operating_memories')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'platform' => 'ctrip',
            'quality_status' => 'verified',
            'lifecycle_status' => 'active',
            'source_record_type' => 'operation_effect_review',
            'source_record_id' => 1,
            'content_digest' => str_repeat('b', 64),
            'recorded_by' => 7,
            'created_at' => $now,
        ]);
    }

    private function createSchema(): void
    {
        Db::execute('PRAGMA foreign_keys = ON');
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL)',
            'CREATE TABLE ota_local_collector_account_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, platform_hotel_id TEXT NOT NULL)',
            'CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, platform_hotel_id TEXT NOT NULL, config_json TEXT NOT NULL)',
            'CREATE TABLE dingdandao_pms_integrations (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, provider TEXT NOT NULL, provider_hotel_id TEXT NOT NULL)',
            'CREATE TABLE hotel_collection_plans (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, plan_version INTEGER NOT NULL, plan_hash TEXT NOT NULL, plan_status TEXT NOT NULL, enabled INTEGER NOT NULL, active_slot INTEGER NOT NULL, source_plan_json TEXT NOT NULL)',
            'CREATE TABLE hotel_collection_plan_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, plan_id INTEGER NOT NULL, plan_version INTEGER NOT NULL, plan_hash TEXT NOT NULL, pms_provider TEXT NULL, pms_status TEXT NULL, pms_capture_id INTEGER NULL, pms_readback_verified INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE hotel_collection_plan_run_sources (id INTEGER PRIMARY KEY, run_id INTEGER NOT NULL, platform TEXT NOT NULL, data_source_id INTEGER NOT NULL, platform_sync_task_id INTEGER NOT NULL, status TEXT NOT NULL, saved_row_count INTEGER NOT NULL, readback_row_count INTEGER NOT NULL, readback_verified INTEGER NOT NULL, evidence_digest TEXT NOT NULL)',
            'CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, data_date TEXT NOT NULL, source TEXT NOT NULL, history_status TEXT NOT NULL, validation_status TEXT NOT NULL, readback_verified INTEGER NOT NULL, room_nights INTEGER NOT NULL)',
            'CREATE TABLE dingdandao_operating_target_captures (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, provider TEXT NOT NULL, quality_status TEXT NOT NULL, identity_status TEXT NOT NULL, capture_status TEXT NOT NULL, readback_status TEXT NOT NULL)',
            'CREATE TABLE price_suggestions (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, suggestion_date TEXT NOT NULL, status INTEGER NOT NULL, applied_by INTEGER NOT NULL, factors TEXT NOT NULL)',
            'CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, date_start TEXT NOT NULL, date_end TEXT NULL, source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL, object_type TEXT NOT NULL, action_type TEXT NOT NULL, expected_metric TEXT NULL, target_value_json TEXT NOT NULL, evidence_json TEXT NOT NULL DEFAULT \'{}\', status TEXT NOT NULL, created_by INTEGER NOT NULL DEFAULT 0, approved_by INTEGER NOT NULL, approved_at TEXT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, operator_id INTEGER NOT NULL, target_value_json TEXT NOT NULL, status TEXT NOT NULL, executed_at TEXT NOT NULL, deleted_at TEXT NULL)',
            'CREATE TABLE operation_execution_evidence (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, task_id INTEGER NOT NULL, evidence_type TEXT NOT NULL, before_json TEXT NULL, after_json TEXT NULL, attachment_path TEXT NOT NULL DEFAULT \'\', platform_response_json TEXT NULL, remark TEXT NOT NULL DEFAULT \'\', created_by INTEGER NOT NULL DEFAULT 0, deleted_at TEXT NULL)',
            'CREATE TABLE operation_effect_reviews (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, task_id INTEGER NOT NULL, platform TEXT NOT NULL, baseline_business_date TEXT NOT NULL, review_business_date TEXT NOT NULL, metric_key TEXT NOT NULL, metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, approval_target_digest TEXT NULL, before_value TEXT NOT NULL, after_value TEXT NOT NULL, expected_direction TEXT NOT NULL, target_type TEXT NOT NULL, target_value TEXT NULL, expected_delta TEXT NULL, expected_delta_status TEXT NOT NULL, target_confirmed_by INTEGER NOT NULL, target_confirmed_at TEXT NOT NULL, baseline_refs_json TEXT NOT NULL, followup_refs_json TEXT NOT NULL, source_readback_evidence_id INTEGER NOT NULL, outcome_status TEXT NOT NULL, outcome_json TEXT NOT NULL, result_status TEXT NOT NULL, result_summary TEXT NOT NULL, causality_claimed INTEGER NOT NULL, reviewed_by INTEGER NOT NULL, reviewed_at TEXT NOT NULL, content_digest TEXT NOT NULL)',
            'CREATE TABLE hotel_operating_memories (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, platform TEXT NOT NULL, quality_status TEXT NOT NULL, lifecycle_status TEXT NOT NULL, source_record_type TEXT NOT NULL, source_record_id INTEGER NOT NULL, content_digest TEXT NOT NULL, recorded_by INTEGER NOT NULL, created_at TEXT NOT NULL)',
            'CREATE TABLE hotel_operating_cycles (id INTEGER PRIMARY KEY AUTOINCREMENT, authority_key TEXT NOT NULL UNIQUE, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, hotel_name_snapshot TEXT NOT NULL, business_date TEXT NOT NULL, metric_version TEXT NOT NULL, metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, source_identities_json TEXT NOT NULL, source_identity_digest TEXT NOT NULL, last_completed_stage TEXT NOT NULL DEFAULT \'\', last_completed_stage_index INTEGER NOT NULL DEFAULT -1, next_required_stage TEXT NOT NULL, cycle_status TEXT NOT NULL, block_code TEXT NOT NULL DEFAULT \'\', block_detail TEXT NOT NULL DEFAULT \'\', truth_summary TEXT NOT NULL, priority_issue TEXT NOT NULL DEFAULT \'\', next_action TEXT NOT NULL DEFAULT \'\', next_owner_json TEXT NULL, review_due_at TEXT NULL, outcome_status TEXT NOT NULL, experience_status TEXT NOT NULL, state_version INTEGER NOT NULL, last_event_id INTEGER NULL, last_event_digest TEXT NOT NULL, projection_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (tenant_id, hotel_id, business_date))',
            'CREATE TABLE hotel_operating_cycle_events (id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, sequence_no INTEGER NOT NULL, command_key TEXT NOT NULL, command_digest TEXT NOT NULL, from_stage TEXT NOT NULL, to_stage TEXT NOT NULL, from_version INTEGER NOT NULL, to_version INTEGER NOT NULL, stage_key TEXT NOT NULL, stage_status TEXT NOT NULL, actor_kind TEXT NOT NULL, actor_id INTEGER NOT NULL, source_module TEXT NOT NULL, payload_json TEXT NOT NULL, evidence_digest TEXT NOT NULL, previous_event_digest TEXT NOT NULL, event_digest TEXT NOT NULL, occurred_at TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (cycle_id, sequence_no), UNIQUE (cycle_id, command_key), FOREIGN KEY (cycle_id) REFERENCES hotel_operating_cycles(id) ON DELETE RESTRICT)',
            'CREATE TABLE hotel_operating_cycle_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT, cycle_id INTEGER NOT NULL, event_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, stage_key TEXT NOT NULL, evidence_role TEXT NOT NULL, source_kind TEXT NOT NULL, fact_scope TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, platform TEXT NOT NULL, business_date TEXT NULL, source_table TEXT NOT NULL, source_row_id INTEGER NOT NULL, source_row_ids_json TEXT NOT NULL, source_row_count INTEGER NOT NULL, source_rows_digest TEXT NOT NULL, verification_status TEXT NOT NULL, readback_verified INTEGER NOT NULL, created_at TEXT NOT NULL, FOREIGN KEY (cycle_id) REFERENCES hotel_operating_cycles(id) ON DELETE RESTRICT, FOREIGN KEY (event_id) REFERENCES hotel_operating_cycle_events(id) ON DELETE RESTRICT)',
        ] as $sql) {
            Db::execute($sql);
        }
    }

    /** @param array<string,mixed> $definition */
    private function saveEffectReview(array $definition, string $metricKey): void
    {
        $metricPayload = ['metric_key' => $metricKey, 'definition' => $definition];
        $metricDigest = $this->valueDigest($metricPayload);
        $approvalBundle = $this->approvalBundle($definition, $metricKey);
        $approvalTargetDigest = $approvalBundle['approval_target']['content_digest'];
        $outcome = [
            'source_verified' => true,
            'outcome_verified' => true,
            'status' => 'met',
            'approval_target_digest' => $approvalTargetDigest,
        ];
        $row = [
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'intent_id' => 1,
            'task_id' => 1,
            'platform' => 'ctrip',
            'baseline_business_date' => self::BUSINESS_DATE,
            'review_business_date' => '2026-08-11',
            'metric_key' => $metricKey,
            'metric_definition_json' => json_encode($metricPayload),
            'metric_definition_digest' => $metricDigest,
            'approval_target_digest' => $approvalTargetDigest,
            'before_value' => '6.000000',
            'after_value' => '8.000000',
            'expected_direction' => 'increase',
            'target_type' => 'absolute',
            'target_value' => '8.000000',
            'expected_delta' => null,
            'expected_delta_status' => 'manual_confirmed',
            'target_confirmed_by' => 7,
            'target_confirmed_at' => '2026-08-11 09:00:00',
            'baseline_refs_json' => json_encode(['online_daily_data#1']),
            'followup_refs_json' => json_encode(['online_daily_data#2']),
            'source_readback_evidence_id' => 2,
            'outcome_status' => 'met',
            'outcome_json' => json_encode($outcome),
            'result_status' => 'success',
            'result_summary' => '同口径携程订单量达到人工冻结目标；仅记录相关性，不宣称因果。',
            'causality_claimed' => 0,
            'reviewed_by' => 7,
            'reviewed_at' => '2026-08-11 10:05:00',
        ];
        $digestPayload = [
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'intent_id' => 1,
            'task_id' => 1,
            'platform' => 'ctrip',
            'baseline_business_date' => self::BUSINESS_DATE,
            'review_business_date' => '2026-08-11',
            'metric_key' => $metricKey,
            'metric_definition' => $metricPayload,
            'metric_definition_digest' => $metricDigest,
            'before_value' => '6.000000',
            'after_value' => '8.000000',
            'expected_direction' => 'increase',
            'target_type' => 'absolute',
            'target_value' => '8.000000',
            'expected_delta' => null,
            'expected_delta_status' => 'manual_confirmed',
            'target_confirmed_by' => 7,
            'target_confirmed_at' => '2026-08-11 09:00:00',
            'baseline_refs' => ['online_daily_data#1'],
            'followup_refs' => ['online_daily_data#2'],
            'source_readback_evidence_id' => 2,
            'outcome_status' => 'met',
            'outcome' => $outcome,
            'result_status' => 'success',
            'result_summary' => $row['result_summary'],
            'causality_claimed' => false,
            'reviewed_by' => 7,
            'reviewed_at' => '2026-08-11 10:05:00',
            'approval_target_digest' => $approvalTargetDigest,
        ];
        $row['content_digest'] = $this->valueDigest($digestPayload);
        Db::name('operation_effect_reviews')->insert(['id' => 1] + $row);
        Db::name('operation_execution_evidence')->insert([
            'id' => 2,
            'tenant_id' => self::TENANT_ID,
            'task_id' => 1,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode([$metricKey => 6]),
            'after_json' => json_encode([$metricKey => 8]),
            'attachment_path' => '',
            'platform_response_json' => json_encode([
                'verification_authority' => 'system_readback',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 2,
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => 'ctrip',
                'metric_key' => $metricKey,
                'baseline_date' => self::BUSINESS_DATE,
                'review_date' => '2026-08-11',
                'date_start' => self::BUSINESS_DATE,
                'date_end' => self::BUSINESS_DATE,
                'readback_at' => '2026-08-11 10:02:00',
                'baseline_source_refs' => ['online_daily_data#1'],
                'followup_source_refs' => ['online_daily_data#2'],
            ]),
            'remark' => '系统精确回读结果。',
            'created_by' => 0,
        ]);
    }
}
