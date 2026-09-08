<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationTaskWorkflowService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OperationTaskWorkflowFixture as Fixture;
use think\facade\Db;
use think\facade\Config;

final class OperationTaskWorkflowServiceTest extends TestCase
{
    private string $path;
    private OperationTaskWorkflowService $service;
    private int $sequence = 0;
    private array $originalConfig;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'l06-workflow-');
        $this->originalConfig = ['database' => Config::get('database', []), 'cache' => Config::get('cache', []), 'log' => Config::get('log', [])];
        Fixture::connect($this->path); Fixture::schema(); Fixture::seed();
        $this->service = Fixture::service();
    }
    protected function tearDown(): void { Db::connect()->close(); unlink($this->path); foreach ($this->originalConfig as $key => $value) Config::set($value, $key); }

    private function act(string $action, array $extra = [], int $id = 1): array
    {
        return $this->service->mutate($id, [7], ['request_id' => 'synthetic:' . ++$this->sequence,
            'expected_version' => $this->service->read($id, [7])['version'], 'action' => $action] + $extra, 3)['workflow'];
    }
    private function complete(int $id = 1, string $kind = 'manual_check'): array
    {
        $this->act('configure', Fixture::configure(), $id); $this->act('start', [], $id);
        $this->act('record', ['record' => Fixture::record($kind)], $id);
        return $this->act('complete', ['completed_criteria' => Fixture::configure()['completion_criteria']], $id);
    }
    private function verified(int $id = 1): array
    {
        $this->complete($id);
        return $this->act('verify', ['human_confirmed' => true, 'reason' => 'synthetic人工核实'], $id);
    }

    public static function types(): array { return array_map(static fn($type) => [$type], array_keys(OperationTaskWorkflowService::TYPES)); }
    #[DataProvider('types')]
    public function testAllWorkflowsPersistExactHistoryAndDistinctStates(string $type): void
    {
        $configured = $this->act('configure', Fixture::configure($type));
        self::assertSame($type, $configured['workflow_type']);
        $this->act('start'); $this->act('record', ['record' => Fixture::record()]);
        $completed = $this->act('complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]);
        self::assertSame('completed', $completed['task_status']);
        self::assertSame('pending', $completed['verification']['status']);
        self::assertSame('pending_execute', Db::name('operation_execution_tasks')->where('id', 1)->value('status'));
        $verified = $this->act('verify', ['human_confirmed' => true, 'reason' => 'synthetic：人工逐项核实']);
        self::assertSame('manual_verified', $verified['verification']['status']);
        $review = $this->act('review', ['review' => ['note' => 'synthetic：无后窗事实，记录缺失']]);
        self::assertSame('reviewed', $review['review']['status']);
        self::assertSame('unestablished', $review['review']['effect_status']);
        self::assertNull($review['review']['delta']);
        $historical = $this->service->read(1, [7], 1);
        self::assertSame('pending', $historical['task_status']);
        self::assertSame($configured['scope'], $historical['scope']);
        self::assertSame('history', $historical['next_step']['key']);
    }

    public function testCompletionAcceptsReorderedExactCriteriaAndPreservesHistory(): void
    {
        $configured = $this->act('configure', Fixture::configure());
        $this->act('start');
        $recorded = $this->act('record', ['record' => Fixture::record()]);
        $completed = $this->act('complete', ['completed_criteria' => array_reverse($configured['completion_criteria'])]);
        self::assertSame('completed', $completed['task_status']);
        self::assertSame($configured['completion_criteria'], $completed['completion_criteria']);
        self::assertSame($recorded['version'] + 1, $completed['version']);
        self::assertSame('pending', $completed['verification']['status']);
        self::assertSame('unestablished', $completed['review']['effect_status']);
        self::assertSame('pending_execute', Db::name('operation_execution_tasks')->where('id', 1)->value('status'));
        $history = $this->service->read(1, [7], $recorded['version']);
        self::assertSame('in_progress', $history['task_status']);
        self::assertSame($recorded['execution_records'], $history['execution_records']);
        self::assertSame('pending', $this->service->read(1, [7], 1)['task_status']);
    }

    public static function invalidCompletionSets(): array
    {
        [$first, $second] = Fixture::configure()['completion_criteria'];
        return [
            'missing' => [[$first]],
            'empty' => [[]],
            'extra' => [[$first, $second, 'unconfigured criterion']],
            'duplicate extra' => [[$first, $second, $first]],
            'duplicate replacement' => [[$first, $first]],
            'boolean item' => [[true, $second]],
            'integer item' => [[3, $second]],
            'nested item' => [[[$first], $second]],
            'non-list' => [['first' => $first, 'second' => $second]],
            'scalar' => [$first],
            'null' => [null],
            'non-exact text' => [[$first . ' ', $second]],
        ];
    }

    #[DataProvider('invalidCompletionSets')]
    public function testInvalidCompletionSetCannotChangeSavedWorkflow(mixed $criteria): void
    {
        $this->act('configure', Fixture::configure());
        $this->act('start');
        $before = $this->act('record', ['record' => Fixture::record()]);
        try {
            $this->act('complete', ['completed_criteria' => $criteria]);
            self::fail('An invalid completion set must not save a completed workflow');
        } catch (\InvalidArgumentException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame($before, $this->service->read(1, [7]));
        self::assertSame($before['version'], (int)Db::name(OperationTaskWorkflowService::EVENTS)->count());
    }

    public function testTimeoutReplayAndStaleVersionAreDifferent(): void
    {
        $input = ['request_id' => 'retry-after-timeout', 'expected_version' => 0, 'action' => 'configure'] + Fixture::configure();
        $saved = $this->service->mutate(1, [7], $input, 3);
        $recovered = $this->service->mutate(1, [7], $input, 3);
        self::assertTrue($recovered['replayed']);
        self::assertSame($saved['workflow'], $recovered['workflow']);
        self::assertSame(1, (int)Db::name(OperationTaskWorkflowService::EVENTS)->count());
        $this->expectExceptionMessage('版本冲突');
        $this->service->mutate(1, [7], ['request_id' => 'different', 'expected_version' => 0, 'action' => 'start'], 3);
    }
    public function testRequestKeyCannotHideDifferentContents(): void
    {
        $input = ['request_id' => 'same-key', 'expected_version' => 0, 'action' => 'configure'] + Fixture::configure();
        $this->service->mutate(1, [7], $input, 3);
        $input['due_date'] = '2026-09-10';
        $this->expectExceptionMessage('重复提交内容不同'); $this->service->mutate(1, [7], $input, 3);
    }
    public function testScreenshotRemainsWeakAndReturnReopenPreserveOldEvidence(): void
    {
        $this->complete(1, 'screenshot');
        $weak = $this->act('verify', ['human_confirmed' => true, 'reason' => 'synthetic截图']);
        self::assertSame('pending', $weak['verification']['status']);
        $returned = $this->act('return', ['reason' => '补核查']);
        self::assertSame('returned', $returned['task_status']); self::assertSame([], $returned['execution_records']);
        $delayed = $this->act('postpone', ['due_date' => '2026-09-17', 'reason' => '等待负责人补充']);
        self::assertSame('2026-09-17', $delayed['due_date']);
        $this->act('start'); $this->act('record', ['record' => Fixture::record()]);
        $this->act('complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]);
        $this->act('verify', ['human_confirmed' => true, 'reason' => '核实通过']);
        $reopened = $this->act('reopen', ['reason' => '出现新的服务反馈']);
        self::assertSame('reopened', $reopened['task_status']); self::assertSame(3, $reopened['cycle']);
        self::assertSame('pending', $reopened['verification']['status']);
        self::assertSame('screenshot', $this->service->read(1, [7], 4)['execution_records'][0]['kind']);
    }

    public static function wrongScope(): array { return [['hotel_id', 8], ['tenant_id', 43], ['platform', 'meituan'], ['object_ref', 'room:wrong'], ['date_end', '2026-09-09']]; }
    #[DataProvider('wrongScope')]
    public function testWrongEvidenceScopeCannotCloseTask(string $field, mixed $value): void
    {
        $this->act('configure', Fixture::configure()); $this->act('start');
        $record = Fixture::record(); $record['scope'][$field] = $value;
        $this->expectExceptionMessage('证据酒店、平台、日期或对象不匹配');
        try { $this->act('record', ['record' => $record]); }
        finally { self::assertSame(2, $this->service->read(1, [7])['version']); }
    }
    public function testCrossHotelReadFailsAndTenantReassignmentHidesTask(): void
    {
        self::assertCount(3, $this->service->listing([7], 7)['items']);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 99]);
        $this->expectExceptionMessage('not found'); $this->service->read(1, [7]);
    }
    public function testDependencyBlocksThenResolvesAndReopenBlocksAgain(): void
    {
        $this->act('configure', ['dependencies' => [2]] + Fixture::configure());
        self::assertSame('dependency_blocked', $this->service->read(1, [7])['next_step']['key']);
        try { $this->act('start'); self::fail('unverified dependency must block'); } catch (\InvalidArgumentException $e) { self::assertStringContainsString('前置任务', $e->getMessage()); }
        $this->verified(2); self::assertSame('start', $this->service->read(1, [7])['next_step']['key']);
        $this->act('start'); $this->act('reopen', ['reason' => '依赖需返工'], 2);
        self::assertSame('dependency_blocked', $this->service->read(1, [7])['next_step']['key']);
    }
    public function testDependencyCycleIsRejected(): void
    {
        $this->act('configure', ['dependencies' => [2]] + Fixture::configure());
        $this->expectExceptionMessage('循环'); $this->act('configure', ['dependencies' => [1]] + Fixture::configure(), 2);
    }
    public function testCrossPlatformDependencyIsRejected(): void
    {
        $this->expectExceptionMessage('平台不匹配'); $this->act('configure', ['dependencies' => [3]] + Fixture::configure());
    }
    public function testReviewUsesUnitsAndNeverClaimsCausality(): void
    {
        $this->verified();
        $before = ['scope' => Fixture::scope(), 'metric' => '浏览转化率', 'unit' => 'percent', 'value' => 0, 'reference' => 'synthetic:before'];
        $after = $before; $after['scope']['date_start'] = $after['scope']['date_end'] = '2026-09-09'; $after['value'] = 3.5; $after['reference'] = 'synthetic:after';
        $review = $this->act('review', ['review' => ['note' => 'synthetic：可能受活动影响', 'before' => $before, 'after' => $after]])['review'];
        self::assertSame(3.5, $review['delta']); self::assertSame('percentage_point', $review['delta_unit']);
        self::assertFalse($review['causality_claimed']); self::assertSame('unestablished', $review['effect_status']);
        $after['unit'] = 'ratio';
        $this->expectException(\InvalidArgumentException::class);
        $this->act('review', ['review' => ['note' => '单位不一致', 'before' => $before, 'after' => $after]]);
    }
    public function testReviewCannotRunBeforeWindowEnds(): void
    {
        $this->verified(); $this->service = Fixture::service('2026-09-09 23:59:59');
        self::assertSame('wait_window', $this->service->read(1, [7])['next_step']['key']);
        $this->expectExceptionMessage('尚未完整结束'); $this->act('review', ['review' => ['note' => '不应提前']]);
    }
    public function testTamperedHistoryFailsClosed(): void
    {
        $this->act('configure', Fixture::configure());
        Db::name(OperationTaskWorkflowService::EVENTS)->where('task_id', 1)->update(['content_digest' => str_repeat('0', 64)]);
        $this->expectExceptionMessage('完整性'); $this->service->read(1, [7]);
    }
    public function testL04ProposalCreatesOnePendingIntentWithoutTaskOrApproval(): void
    {
        $a = $this->service->propose([7], 7, Fixture::recommendation(), 3);
        $b = $this->service->propose([7], 7, Fixture::recommendation(), 3);
        self::assertTrue($a['created']); self::assertTrue($b['replayed']);
        self::assertSame($a['intent']['id'], $b['intent']['id']); self::assertSame('pending_approval', $a['intent']['status']);
        self::assertSame([], $a['intent']['tasks']); self::assertNull($a['intent']['approved_by']);
        self::assertSame(5, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(4, (int)Db::name('operation_execution_tasks')->count());
        $changed = Fixture::recommendation(); $changed['problem'] = 'changed';
        $this->expectExceptionCode(409); $this->service->propose([7], 7, $changed, 3);
    }

    public function testExistingHumanApprovalCreatesExactlyOneOriginalTask(): void
    {
        $proposal = $this->service->propose([7], 7, Fixture::recommendation(), 3);
        $operations = new \app\service\OperationManagementService();
        $approved = $operations->approveExecutionIntent($proposal['intent']['id'], true, 'synthetic：测试人工主动审批', 3, [7]);
        self::assertCount(1, $approved['tasks']);
        $taskId = $approved['tasks'][0]['id'];
        self::assertSame($proposal['intent']['id'], $this->service->read($taskId, [7])['intent_id']);
        self::assertSame('synthetic:diagnosis-1', $this->service->read($taskId, [7])['source']['proposal']['recommendation_id']);
        try { $operations->approveExecutionIntent($proposal['intent']['id'], true, 'duplicate', 3, [7]); self::fail('duplicate approval should not create'); }
        catch (\InvalidArgumentException) { self::assertSame(1, (int)Db::name('operation_execution_tasks')->where('intent_id', $proposal['intent']['id'])->count()); }
    }

    public function testActualExecutionAfterBusinessDateRequiresNewReviewWindow(): void
    {
        $this->act('configure', Fixture::configure()); $this->act('start');
        $record = Fixture::record(); $record['performed_on'] = '2026-09-15';
        $this->act('record', ['record' => $record]); $this->act('complete', ['completed_criteria' => Fixture::configure()['completion_criteria']]);
        $s = $this->act('verify', ['human_confirmed' => true, 'reason' => '核实实际执行']);
        self::assertSame('adjust_review_window', $s['next_step']['key']);
        try { $this->act('review', ['review' => ['note' => '旧观察窗']]); self::fail('pre-execution observations cannot close'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('实际执行日期', $e->getMessage()); }
        $window = Fixture::window(); $window['baseline_start'] = $window['baseline_end'] = '2026-09-14';
        $window['followup_start'] = $window['followup_end'] = '2026-09-16';
        $rescheduled = $this->act('reschedule_review', ['reason' => '实际执行晚于业务日', 'review_window' => $window]);
        self::assertSame('wait_window', $rescheduled['next_step']['key']);
        self::assertSame('2026-09-08', $rescheduled['scope']['date_start']);
    }

    public function testTruncatedListStillSupportsExactOlderTaskAndRejectsOtherHotel(): void
    {
        $intent = Db::name('operation_execution_intents')->where('id', 1)->find();
        $task = Db::name('operation_execution_tasks')->where('id', 1)->find();
        for ($id = 5; $id <= 110; $id++) {
            Db::name('operation_execution_intents')->insert(array_replace($intent, ['id' => $id]));
            Db::name('operation_execution_tasks')->insert(array_replace($task, ['id' => $id, 'intent_id' => $id]));
        }
        $list = $this->service->listing([7], 7);
        self::assertTrue($list['truncated']); self::assertCount(100, $list['items']);
        self::assertNotContains(1, array_column($list['items'], 'task_id'));
        self::assertSame(1, $this->service->read(1, [7])['task_id']);
        $this->expectExceptionCode(404); $this->service->read(4, [7]);
    }

    public function testWeeklySnapshotPersistsIndependentWorkflowCountsAndExactVersions(): void
    {
        $this->complete();
        $summary = fn(): array => $this->service->weeklySummary(42, 7, [['id' => 1]]);
        self::assertSame(1, $summary()['task_completed']); self::assertSame(0, $summary()['execution_verified']);
        $snapshots = [];
        $weekly = new \app\service\WeeklyOperatingPlanSnapshotService(
            fn(): array => ['hotel_name' => 'Synthetic hotel', 'daily_runs' => [], 'broadcasts' => [], 'intents' => [], 'tasks' => [], 'source_errors' => [], 'task_workflow' => $summary()],
            static function (string $action, array $scope) use (&$snapshots): mixed {
                if ($action === 'next_version') return count($snapshots) + 1;
                foreach (array_reverse($snapshots) as $row) {
                    if ($action === 'exact' && $row['id'] === $scope['id']) return $row;
                    if ($action === 'by_source' && $row['source_digest'] === $scope['source_digest']) return $row;
                }
                return null;
            },
            static function (array $row) use (&$snapshots): int { $id = count($snapshots) + 1; $snapshots[] = ['id' => $id] + $row; return $id; },
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-09-16 12:00:00'), static fn(): bool => true
        );
        $first = $weekly->generateAndReadback(42, 7, '2026-09-13');
        self::assertSame('verify', $first['selected_focus']['key']);
        self::assertSame($first['lifecycle_summary'], $weekly->readExact(42, 7, $first['snapshot_id'])['lifecycle_summary']);
        $this->act('verify', ['human_confirmed' => true, 'reason' => '人工核实']);
        $this->act('review', ['review' => ['note' => '没有效果事实']]);
        $second = $weekly->generateAndReadback(42, 7, '2026-09-13');
        self::assertNotSame($first['source_digest'], $second['source_digest']);
        self::assertSame(1, $second['lifecycle_summary']['task_workflow']['execution_verified']);
        self::assertSame(1, $second['lifecycle_summary']['task_workflow']['reviewed']);
        self::assertSame(0, $second['lifecycle_summary']['task_workflow']['effect_established']);
        self::assertSame(0, $weekly->readExact(42, 7, $first['snapshot_id'])['lifecycle_summary']['task_workflow']['execution_verified']);
    }

    public function testWeakReceiptDoesNotAllowEffectReview(): void
    {
        $this->complete(1, 'receipt');
        $this->act('verify', ['human_confirmed' => true, 'reason' => '仅回执']);
        $this->expectExceptionMessage('先完成人工执行核实'); $this->act('review', ['review' => ['note' => '不能结案']]);
    }

    public function testMismatchedWindowLengthsRetainObservationsWithoutDelta(): void
    {
        $this->verified(); $window = Fixture::window(); $window['followup_end'] = '2026-09-15';
        $this->act('reschedule_review', ['reason' => '采用七日观察计划', 'review_window' => $window]);
        $before = ['scope' => Fixture::scope(), 'metric' => '订单数', 'unit' => 'count', 'value' => 2, 'reference' => 'synthetic:before'];
        $after = $before; $after['scope']['date_start'] = '2026-09-09'; $after['scope']['date_end'] = '2026-09-15'; $after['value'] = 14;
        $review = $this->act('review', ['review' => ['note' => '周期不同不能比较总量', 'before' => $before, 'after' => $after]])['review'];
        self::assertSame('incomparable', $review['comparison_status']); self::assertNull($review['delta']);
    }

    public function testUnapprovedOrWrongAssigneeCannotExecuteWorkflow(): void
    {
        $this->act('configure', Fixture::configure());
        try { $this->service->mutate(1, [7], ['request_id' => 'wrong-actor', 'expected_version' => 1, 'action' => 'start'], 9); self::fail(); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('负责人', $e->getMessage()); }
        Db::name('operation_execution_intents')->where('id', 1)->update(['status' => 'pending_approval']);
        self::assertSame('approval', $this->service->read(1, [7])['next_step']['key']);
        self::assertSame('approved', $this->service->read(1, [7], 1)['approval_status']);
        $this->expectExceptionMessage('尚未获人工批准'); $this->act('start');
    }

    public function testControllerUsesExplicitHotelPermissionAndConflictHttpStatus(): void
    {
        $this->act('configure', Fixture::configure());
        $reflection = new \ReflectionClass(\app\controller\OperationManagement::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $user = new class extends \app\model\User {
            public int $id = 3;
            public bool $writeAllowed = true;
            public function __construct() {}
            public function getPermittedHotelIds(): array { return [7, 8]; }
            public function hasHotelPermission(int $hotelId, string $permission): bool { return $hotelId === 7 && ($permission === 'operation.view' || $this->writeAllowed); }
        };
        $reflection->getProperty('currentUser')->setValue($controller, $user);
        $request = $reflection->getProperty('request');
        $request->setValue($controller, (new \think\Request())->withGet(['hotel_id' => 7]));
        $response = $controller->readTaskWorkflow(1);
        self::assertSame(200, $response->getCode());
        self::assertSame(1, json_decode($response->getContent(), true)['data']['version']);
        $request->setValue($controller, (new \think\Request())->withPost(['action' => 'start', 'request_id' => 'controller-stale', 'expected_version' => 0]));
        self::assertSame(422, $controller->mutateTaskWorkflow(1)->getCode());
        $request->setValue($controller, (new \think\Request())->withPost(['hotel_id' => 7, 'action' => 'start', 'request_id' => 'controller-stale', 'expected_version' => 0]));
        $conflict = $controller->mutateTaskWorkflow(1);
        self::assertSame(409, $conflict->getCode()); self::assertSame(409, json_decode($conflict->getContent(), true)['code']);
        $user->writeAllowed = false;
        self::assertSame(403, $controller->mutateTaskWorkflow(1)->getCode());
        $request->setValue($controller, (new \think\Request())->withGet(['hotel_id' => 8]));
        self::assertSame(403, $controller->readTaskWorkflow(4)->getCode());
        $reflection->getProperty('currentUser')->setValue($controller, null);
        self::assertSame(401, $controller->readTaskWorkflow(1)->getCode());
    }

    public function testL04ScopeMismatchCannotBeConfiguredAsAnotherObject(): void
    {
        $proposal = Fixture::recommendation(); $proposal['evidence_snapshot']['scope']['object_ref'] = 'different:object';
        Db::name('operation_execution_intents')->where('id', 1)->update(['evidence_json' => json_encode(['evidence_recommendation' => $proposal])]);
        self::assertSame('scope_mismatch', $this->service->read(1, [7])['source']['link_status']);
        $this->expectExceptionMessage('原建议与任务范围不匹配'); $this->act('configure', Fixture::configure());
    }

    public function testMissingReviewValueIsRejectedWithoutChangingVersion(): void
    {
        $this->verified();
        $before = ['scope' => Fixture::scope(), 'metric' => '订单数', 'unit' => 'count', 'value' => null, 'reference' => 'synthetic:missing'];
        $after = $before; $after['value'] = 0; $after['scope']['date_start'] = $after['scope']['date_end'] = '2026-09-09';
        $this->expectExceptionMessage('缺失或无效指标不能当作零');
        try { $this->act('review', ['review' => ['note' => 'missing baseline', 'before' => $before, 'after' => $after]]); }
        finally { self::assertSame(5, $this->service->read(1, [7])['version']); }
    }

    public function testMissingWorkflowStorageNeverBecomesEmptySuccess(): void
    {
        Db::execute('DROP TABLE operation_task_workflow_events');
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('migration_required');
        $this->service->listing([7], 7);
    }
}
