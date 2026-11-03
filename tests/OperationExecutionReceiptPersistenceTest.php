<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OperationExecutionReceiptFixture;
use think\facade\Config;
use think\facade\Db;

final class OperationExecutionReceiptPersistenceTest extends TestCase
{
    private array $originalConfig;

    protected function setUp(): void
    {
        $this->originalConfig = [];
        foreach (['database', 'cache', 'log'] as $key) $this->originalConfig[$key] = Config::get($key, []);
        OperationExecutionReceiptFixture::connect(':memory:');
        OperationExecutionReceiptFixture::schema();
        OperationExecutionReceiptFixture::seed();
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        foreach ($this->originalConfig as $key => $value) Config::set($value, $key);
    }

    public static function conflictingInputs(): array
    {
        return OperationExecutionReceiptConflictTest::contradictoryReceipts();
    }

    #[DataProvider('conflictingInputs')]
    public function testRepeatedConflictingRequestsLeaveDatabaseUnchanged(array $response, ?string $status): void
    {
        $input = $this->input($response, $status);
        $before = $this->snapshot();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $error = null;
            try { (new OperationManagementService())->executeExecutionTask(1, [7], $input, 3); }
            catch (InvalidArgumentException $caught) { $error = $caught; }
            self::assertNotNull($error, 'Conflicting execution must be rejected; task_status='
                . Db::name('operation_execution_tasks')->where('id', 1)->value('status')
                . '; evidence_count=' . Db::name('operation_execution_evidence')->count());
            self::assertStringContainsString('不能标记为已执行', $error->getMessage());
            self::assertSame($before, $this->snapshot(), 'Rejected request changed task/intent/evidence');
        }
    }

    public function testValidSuccessAndFailurePersistOnceAndCannotOverwriteTerminalResult(): void
    {
        foreach ([1 => 'executed', 2 => 'failed'] as $taskId => $status) {
            $response = $status === 'executed' ? ['execution_completed' => true]
                : ['execution_failed' => true, 'failure_reason' => 'synthetic_timeout'];
            $input = $this->input($response, $status === 'executed' ? null : $status);
            (new OperationManagementService())->executeExecutionTask($taskId, [7], $input, 3);
            self::assertSame($status, Db::name('operation_execution_tasks')->where('id', $taskId)->value('status'));
            $evidence = Db::name('operation_execution_evidence')->where('task_id', $taskId)->select()->toArray();
            self::assertCount(1, $evidence);
            self::assertSame($response, json_decode($evidence[0]['platform_response_json'], true));
            $before = $this->snapshot();
            try {
                (new OperationManagementService())->executeExecutionTask($taskId, [7], $input, 3);
                self::fail('Terminal replay unexpectedly succeeded');
            } catch (InvalidArgumentException $error) {
                self::assertSame('terminal execution task cannot transition', $error->getMessage());
            }
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testStopEvidenceStaysBlockedAndIsNotDuplicated(): void
    {
        $input = $this->input(['stop_condition_triggered' => true], 'blocked');
        $service = new OperationManagementService();
        $service->executeExecutionTask(1, [7], $input, 3);
        self::assertSame('blocked', Db::name('operation_execution_tasks')->where('id', 1)->value('status'));
        self::assertSame(1, (int)Db::name('operation_execution_evidence')->count());
        $before = $this->snapshot();
        try { $service->executeExecutionTask(1, [7], $input, 3); self::fail('Duplicate stop changed state'); }
        catch (InvalidArgumentException $error) { self::assertSame('execution task status must transition', $error->getMessage()); }
        self::assertSame($before, $this->snapshot());
    }

    public function testHotelScopeAndAssigneeGuardsRemainClosed(): void
    {
        $service = new OperationManagementService();
        $input = $this->input(['execution_completed' => true], 'executed');
        $before = $this->snapshot();
        try { $service->executeExecutionTask(1, [8], $input, 3); self::fail('Wrong hotel accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('not found', $error->getMessage()); }
        self::assertSame($before, $this->snapshot());
        Db::name('operation_execution_intents')->where('id', 1)->update([
            'target_value_json' => '{"workflow_schedule":{"assignee_id":3}}',
        ]);
        $before = $this->snapshot();
        try { $service->executeExecutionTask(1, [7], $input, 4); self::fail('Wrong assignee accepted'); }
        catch (InvalidArgumentException $error) { self::assertStringContainsString('assignee', $error->getMessage()); }
        self::assertSame($before, $this->snapshot());
    }

    public function testMismatchedTenantIsRejectedWithoutPersistence(): void
    {
        Db::name('operation_execution_tasks')->where('id', 1)->update(['tenant_id' => 43]);
        $before = $this->snapshot();
        try {
            (new OperationManagementService())->executeExecutionTask(1, [7], $this->input(['execution_completed' => true], 'executed'), 3);
            self::fail('Mismatched tenant accepted');
        } catch (\RuntimeException|InvalidArgumentException $error) {
            self::assertStringContainsString('tenant', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testEvidenceSaveFailureRollsBackTaskAndPreservesOldState(): void
    {
        $before = $this->snapshot();
        Db::execute("CREATE TRIGGER reject_synthetic_receipt BEFORE INSERT ON operation_execution_evidence BEGIN SELECT RAISE(ABORT, 'synthetic receipt save failure'); END");
        $error = null;
        try {
            (new OperationManagementService())->executeExecutionTask(1, [7], $this->input(['execution_completed' => true], 'executed'), 3);
        } catch (\Throwable $caught) { $error = $caught; }
        self::assertNotNull($error, 'Fixture must force an evidence insert failure');
        self::assertStringContainsString('synthetic receipt save failure', $error->getMessage());
        self::assertSame($before, $this->snapshot(), 'Failed receipt save left a completed task');
    }

    private function input(array $response, ?string $status): array
    {
        $input = ['evidence_type' => 'manual', 'evidence' => ['platform_response' => $response]];
        if ($status !== null) $input['status'] = $status;
        return $input;
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence'] as $table) {
            $rows[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        return $rows;
    }
}
