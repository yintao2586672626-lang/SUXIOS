<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperatingTargetAutomationService;
use app\service\OperatingTargetService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\PromotionExperimentFixture;
use think\facade\Db;

require_once __DIR__ . '/OperatingTargetAutomationServiceTest.php';

final class OperatingTargetDraftReadbackTest extends TestCase
{
    private array $database;
    private OperatingTargetService $targets;
    private OperationManagementService $operations;
    private OperatingTargetAutomationService $automation;
    private const LEGACY_REASON = 'platform missing; object_type not supported';

    protected function setUp(): void
    {
        $this->database = PromotionExperimentFixture::database(':memory:');
        Db::execute('ALTER TABLE hotels RENAME TO synthetic_fixture_hotels_unused');
        // Reuse only schema definitions; never initialize the application's runtime.
        (new \ReflectionMethod(OperatingTargetAutomationServiceTest::class, 'createSchema'))->invoke(null);
        Db::execute('ALTER TABLE operation_execution_intents ADD COLUMN idempotency_key TEXT NULL');
        Db::execute('CREATE UNIQUE INDEX synthetic_intent_key ON operation_execution_intents (idempotency_key)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name) VALUES (90001,9001,'SYNTHETIC A'),(90002,9002,'SYNTHETIC B')");
        $this->targets = new OperatingTargetService();
        $this->operations = new OperationManagementService();
        $this->automation = new OperatingTargetAutomationService($this->targets, $this->operations);
        $this->targets->save(9001, 90001, 7, $this->input());
    }

    protected function tearDown(): void { PromotionExperimentFixture::restoreDatabase($this->database); }

    private function input(): array
    {
        return ['target_date' => '2026-09-20', 'target_revenue' => 10000, 'actual_revenue' => 0,
            'sold_room_nights' => 0, 'sellable_room_nights' => 10, 'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'manual', 'source_reference' => 'SYNTHETIC memory fixture', 'quality_status' => 'manual_confirmed'];
    }

    private function draft(): array { return $this->automation->createTaskDraft(9001, 90001, 7, '2026-09-20'); }

    private function legacy(array $changes = []): array
    {
        $draft = $this->draft();
        Db::name('operation_execution_intents')->where('id', $draft['execution_intent']['id'])->update(array_replace([
            'status' => 'blocked', 'blocked_reason' => self::LEGACY_REASON,
        ], $changes));
        return $draft;
    }

    public function testRealCreationAndReplayPreserveZeroScopeAndHumanApproval(): void
    {
        $first = $this->draft(); $again = $this->draft();
        $read = $this->operations->readExecutionIntent($first['execution_intent']['id'], [90001]);
        self::assertSame('pending_approval', $read['status']);
        self::assertSame('', $read['platform']); self::assertSame('', $read['blocked_reason']);
        self::assertSame(9001, $read['tenant_id']); self::assertSame(90001, $read['hotel_id']);
        self::assertSame('2026-09-20', $read['date_start']);
        self::assertSame(0, $read['current_value']['actual_revenue']);
        self::assertFalse($read['evidence']['auto_write_ota']);
        self::assertSame([], $read['tasks']);
        self::assertTrue($again['reused_existing_intent']); self::assertSame($read['id'], $again['execution_intent']['id']);
        self::assertSame(1, Db::name('operation_execution_intents')->count());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
    }

    public function testLegacyFalseBlockRecoversSameIdAndPreservesStoredFacts(): void
    {
        $first = $this->legacy();
        $before = Db::name('operation_execution_intents')->find($first['execution_intent']['id']);
        $recovered = $this->draft();
        self::assertSame($first['execution_intent']['id'], $recovered['execution_intent']['id']);
        self::assertSame('pending_approval', $recovered['execution_intent']['status']);
        self::assertSame('', $recovered['execution_intent']['blocked_reason']); self::assertTrue($recovered['reused_existing_intent']);
        $after = Db::name('operation_execution_intents')->find($first['execution_intent']['id']);
        foreach (['status', 'blocked_reason', 'updated_at'] as $field) { unset($before[$field], $after[$field]); }
        self::assertSame($before, $after); self::assertSame(1, Db::name('operation_execution_intents')->count());
    }

    public function testOtherBlockTerminalOrApprovalMarksAreNeverRecovered(): void
    {
        $first = $this->draft(); $id = $first['execution_intent']['id'];
        foreach ([['blocked_reason' => 'actual source missing'], ['status' => 'approved'], ['status' => 'rejected'],
            ['status' => 'cancelled'], ['approved_by' => 7], ['approved_at' => '2026-09-20 09:00:00'], ['review_remark' => 'operator review']] as $change) {
            $state = array_replace(['status' => 'blocked', 'blocked_reason' => self::LEGACY_REASON, 'approved_by' => 0, 'approved_at' => null, 'review_remark' => ''], $change);
            Db::name('operation_execution_intents')->where('id', $id)->update($state);
            $read = $this->draft()['execution_intent'];
            self::assertSame($state['status'], $read['status']); self::assertSame($state['blocked_reason'], $read['blocked_reason']);
        }
    }

    public function testExistingExecutionTaskPreventsLegacyRecovery(): void
    {
        $first = $this->legacy();
        Db::name('operation_execution_tasks')->insert(['tenant_id' => 9001, 'intent_id' => $first['execution_intent']['id'], 'hotel_id' => 90001, 'status' => 'pending']);
        self::assertSame('blocked', $this->draft()['execution_intent']['status']);
    }

    public function testMismatchedDateOrProvenanceCannotRecover(): void
    {
        $first = $this->legacy(); $id = $first['execution_intent']['id'];
        $original = Db::name('operation_execution_intents')->find($id);
        foreach (['date_start', 'date_end', 'digest', 'contract'] as $field) {
            $change = ['date_start' => $original['date_start'], 'date_end' => $original['date_end'], 'evidence_json' => $original['evidence_json']];
            if (str_starts_with($field, 'date')) $change[$field] = '2026-09-21';
            else { $evidence = json_decode($original['evidence_json'], true); $evidence[$field === 'digest' ? 'operating_target_source_digest' : 'operating_target_provenance_contract'] = $field === 'digest' ? str_repeat('a', 64) : 'invalid'; $change['evidence_json'] = json_encode($evidence); }
            Db::name('operation_execution_intents')->where('id', $id)->update($change);
            try { $this->draft(); } catch (\InvalidArgumentException|\RuntimeException $exception) { self::assertNotSame('', $exception->getMessage()); }
            self::assertSame('blocked', Db::name('operation_execution_intents')->where('id', $id)->value('status'));
        }
    }

    public function testRevisionChangeCreatesDistinctIntentAndDoesNotReviveOldSource(): void
    {
        $first = $this->legacy();
        $this->targets->save(9001, 90001, 7, array_replace($this->input(), ['target_revenue' => 12000]));
        $new = $this->draft(); self::assertNotSame($first['execution_intent']['id'], $new['execution_intent']['id']);
        self::assertSame('pending_approval', $new['execution_intent']['status']);
        self::assertSame('blocked', Db::name('operation_execution_intents')->where('id', $first['execution_intent']['id'])->value('status'));
    }

    public function testMissingQualityCannotCreateOrRecover(): void
    {
        $first = $this->legacy();
        $this->targets->save(9001, 90001, 7, array_replace($this->input(), ['quality_status' => 'unverified']));
        try { $this->draft(); self::fail('unverified facts accepted'); }
        catch (\RuntimeException $exception) { self::assertSame('operating_target_facts_not_actionable', $exception->getMessage()); }
        self::assertSame('blocked', Db::name('operation_execution_intents')->where('id', $first['execution_intent']['id'])->value('status'));
    }

    public function testTenantMismatchCannotRestoreLegacyIntent(): void
    {
        $first = $this->legacy(['tenant_id' => 9002]);
        try { $this->draft(); } catch (\InvalidArgumentException|\RuntimeException $exception) { self::assertNotSame('', $exception->getMessage()); }
        self::assertSame('blocked', Db::name('operation_execution_intents')->where('id', $first['execution_intent']['id'])->value('status'));
        self::assertSame(9002, (int)Db::name('operation_execution_intents')->where('id', $first['execution_intent']['id'])->value('tenant_id'));
    }

    public function testSourceChangeInsideRecoveryTransactionRollsBackWithoutRestoring(): void
    {
        $first = $this->legacy();
        $operations = new OperatingTargetDraftInterleavingService();
        $automation = new OperatingTargetAutomationService($this->targets, $operations);
        try { $automation->createTaskDraft(9001, 90001, 7, '2026-09-20'); self::fail('changed source recovered'); }
        catch (\InvalidArgumentException $exception) { self::assertStringContainsString('source changed', $exception->getMessage()); }
        self::assertTrue($operations->insideTransaction);
        self::assertSame('blocked', Db::name('operation_execution_intents')->where('id', $first['execution_intent']['id'])->value('status'));
        self::assertSame(0.0, $this->targets->current(9001, 90001, '2026-09-20')['record']['facts']['actual_revenue']);
    }

    public function testOrdinaryAndForgedObjectsCannotUseHotelScopedException(): void
    {
        $valid = $this->draft()['execution_intent'];
        foreach ([['source_module' => 'manual'], ['platform' => 'ctrip'], ['action_type' => 'change_price'], ['source_record_id' => 0],
            ['evidence' => []], ['target_value' => ['target_metric' => 'unknown']], ['object_type' => 'campaign', 'target_value' => ['campaign_type' => 'test', 'target_metric' => 'revenue']]] as $change) {
            $payload = $this->operations->buildExecutionIntentPayload([90001], 90001, array_replace($valid, $change), 7);
            self::assertSame('blocked', $payload['status'], json_encode($change));
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->operations->createExecutionIntent([90001], 90001, $valid, 7);
    }
}

final class OperatingTargetDraftInterleavingService extends OperationManagementService
{
    public bool $insideTransaction = false;
    protected function afterOperatingTargetSourceLockedForApproval(array $intent, array $sourceRow): void
    {
        $this->insideTransaction = Db::connect()->getPdo()->inTransaction();
        Db::name('operating_target_daily_records')->where('id', $sourceRow['id'])->update(['actual_revenue' => 1]);
    }
}
