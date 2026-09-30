<?php
declare(strict_types=1);
namespace Tests;

use app\service\ManagerCoachingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\facade\Config;
use think\facade\Db;

final class ManagerCoachingPersistenceTest extends TestCase
{
    private array $database;
    private string $connection;
    private ManagerCoachingService $service;

    protected function setUp(): void
    {
        $this->database = Config::get('database', []);
        $this->connection = 'coaching_synthetic_' . bin2hex(random_bytes(6));
        Config::set(['default' => $this->connection, 'connections' => [$this->connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE manager_capability_cases (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, manager_user_id INTEGER)');
        Db::execute('INSERT INTO manager_capability_cases VALUES (1, 1, 80, 7)');
        Db::execute('CREATE TABLE manager_coaching_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, manager_user_id INTEGER, case_id INTEGER, created_by INTEGER, idempotency_key TEXT, input_digest TEXT, revision INTEGER, status TEXT, plan_json TEXT, content_digest TEXT, due_on TEXT, review_on TEXT, created_at TEXT, updated_at TEXT, UNIQUE(tenant_id,hotel_id,created_by,idempotency_key))');
        Db::execute('CREATE TABLE manager_coaching_events (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, actor_id INTEGER, event_type TEXT, revision INTEGER, idempotency_key TEXT, input_digest TEXT, payload_json TEXT, created_at TEXT, UNIQUE(plan_id,actor_id,idempotency_key))');
        $this->service = new class extends ManagerCoachingService {
            public string $testDate = '2026-09-10';
            protected function today(): string { return $this->testDate; }
            protected function caseForScope(int $tenantId, int $hotelId, int $managerId, int $caseId): array
            {
                if ([$tenantId, $hotelId, $managerId, $caseId] !== [1, 80, 7, 1]) throw new RuntimeException('Synthetic case outside scope');
                return ['id' => 1, 'is_voided' => false, 'business_date' => '2026-09-01', 'problem_facts' => 'Synthetic manual case'];
            }
        };
    }

    protected function tearDown(): void
    {
        Db::connect($this->connection)->close();
        Config::set($this->database, 'database');
        Db::connect(null, true);
    }

    public function testCreateReplayAndExactReadbackRetainManualEvidence(): void
    {
        $saved = $this->create();
        self::assertSame($saved, $this->create());
        self::assertSame($saved, $this->service->read(1, 80, 7, $saved['plan']['id']));
        self::assertSame($saved['plan'], $this->service->listing(1, 80, 7)['list'][0]);
        self::assertSame('manual_declared', $saved['boundaries']['source_quality']);
        self::assertFalse($saved['boundaries']['operating_effect_verified']);
        self::assertFalse($saved['boundaries']['updates_capability_score']);
        self::assertSame(1, Db::name('manager_coaching_plans')->count());
        self::assertSame(1, Db::name('manager_coaching_events')->count());
    }

    public function testReviewRequiresIndependentEvidenceAndKeepsManualCompletionBoundary(): void
    {
        $saved = $this->create(); $id = $saved['plan']['id'];
        $evidence = ['observed_on' => '2026-09-03', 'sample_count' => 2, 'evidence_ref' => 'synthetic://case-evidence', 'note' => 'Synthetic manual observation'];
        $progress = $this->service->mutate(1, 80, 7, 9, $id, 'evidence', $evidence + [
            'idempotency_key' => 'evidence-one', 'expected_revision' => 1, 'stage' => 'independent']);
        self::assertSame('awaiting_review', $progress['plan']['status']);
        $input = $evidence + ['idempotency_key' => 'review-one', 'expected_revision' => 2, 'conclusion' => 'target_met', 'criteria_confirmed' => true];
        $complete = $this->service->mutate(1, 80, 7, 9, $id, 'review', $input);
        self::assertSame('completed', $complete['plan']['status']);
        self::assertSame(3, (int)$complete['plan']['revision']);
        self::assertSame($complete, $this->service->mutate(1, 80, 7, 9, $id, 'review', $input));
        self::assertFalse($complete['boundaries']['operating_effect_verified']);
        self::assertSame(3, Db::name('manager_coaching_events')->count());
    }

    public function testStaleRevisionAndForeignHotelCannotWriteOrRead(): void
    {
        $id = $this->create()['plan']['id'];
        foreach ([
            fn() => $this->service->mutate(1, 80, 7, 9, $id, 'cancel', ['idempotency_key' => 'cancel-stale', 'expected_revision' => 0, 'note' => 'Synthetic']),
            fn() => $this->service->read(2, 80, 7, $id),
            fn() => $this->service->read(1, 81, 7, $id),
            fn() => $this->service->read(1, 80, 8, $id),
        ] as $operation) {
            $error = null;
            try { $operation(); } catch (RuntimeException $failure) { $error = $failure; }
            self::assertInstanceOf(RuntimeException::class, $error);
        }
        self::assertSame(1, Db::name('manager_coaching_events')->count());
        self::assertSame('planned', Db::name('manager_coaching_plans')->where('id', $id)->value('status'));
    }

    public function testCreateAndEditRejectPlanDateBeforeSourceCase(): void
    {
        $error = null;
        try { $this->create(['business_date' => '2026-08-31']); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('不能早于来源案例', $error->getMessage());
        self::assertSame(0, Db::name('manager_coaching_plans')->count());
        $saved = $this->create();
        $request = $saved['plan']['content'] + ['idempotency_key' => 'edit-date', 'expected_revision' => 1];
        $request['business_date'] = '2026-08-31';
        $error = null;
        try { $this->service->mutate(1, 80, 7, 9, $saved['plan']['id'], 'edit', $request); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('不能早于来源案例', $error->getMessage());
        self::assertSame(1, Db::name('manager_coaching_events')->count());
    }

    public function testUnknownCauseCannotReceiveConclusiveReviewButCanRecordInsufficientEvidence(): void
    {
        $saved = $this->create(['cause' => 'unknown']);
        $request = ['idempotency_key' => 'unknown-review', 'expected_revision' => 1, 'conclusion' => 'improved',
            'observed_on' => '2026-09-03', 'sample_count' => 2, 'evidence_ref' => 'synthetic://observation',
            'note' => 'Synthetic review', 'next_review_on' => '2026-09-11'];
        $error = null;
        try { $this->service->mutate(1, 80, 7, 9, $saved['plan']['id'], 'review', $request); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('原因尚未核实', $error->getMessage());
        self::assertSame(1, Db::name('manager_coaching_events')->count());
        $result = $this->service->mutate(1, 80, 7, 9, $saved['plan']['id'], 'review', array_replace($request, ['conclusion' => 'insufficient']));
        self::assertSame('awaiting_evidence', $result['plan']['status']);
    }

    public function testReviewCannotUseIndependentEvidenceObservedAfterReviewDate(): void
    {
        $id = $this->create()['plan']['id'];
        $this->service->mutate(1, 80, 7, 9, $id, 'evidence', $this->evidenceRequest('later-evidence', 1, '2026-09-04'));
        $error = null;
        try { $this->service->mutate(1, 80, 7, 9, $id, 'review', $this->reviewRequest('earlier-review', 2, '2026-09-03')); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('达到目标需要独立完成证据', $error->getMessage());
        self::assertSame('awaiting_review', $this->service->read(1, 80, 7, $id)['plan']['status']);
        self::assertSame(2, Db::name('manager_coaching_events')->count());
    }

    public function testRecurrenceRequiresFreshIndependentEvidenceBeforeCompletingAgain(): void
    {
        $id = $this->create()['plan']['id'];
        $this->service->mutate(1, 80, 7, 9, $id, 'evidence', $this->evidenceRequest('first-evidence', 1, '2026-09-03'));
        $this->service->mutate(1, 80, 7, 9, $id, 'review', $this->reviewRequest('first-review', 2, '2026-09-03'));
        $this->service->mutate(1, 80, 7, 9, $id, 'recur', $this->evidenceRequest('recurrence', 3, '2026-09-09') + ['next_review_on' => '2026-09-11']);
        $this->service->testDate = '2026-09-12';
        $error = null;
        try { $this->service->mutate(1, 80, 7, 9, $id, 'review', $this->reviewRequest('stale-cycle-review', 4, '2026-09-11')); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('达到目标需要独立完成证据', $error->getMessage());
        self::assertSame(4, Db::name('manager_coaching_events')->count());
        $this->service->mutate(1, 80, 7, 9, $id, 'evidence', $this->evidenceRequest('fresh-evidence', 4, '2026-09-11'));
        $complete = $this->service->mutate(1, 80, 7, 9, $id, 'review', $this->reviewRequest('fresh-review', 5, '2026-09-12'));
        self::assertSame('completed', $complete['plan']['status']);
        self::assertSame(6, (int)$complete['plan']['revision']);
        self::assertFalse($complete['boundaries']['operating_effect_verified']);
    }

    public function testKnowledgeAdaptationRequiresExplicitAnonymizedAcceptanceCriteria(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY)');
        Db::execute('INSERT INTO hotels VALUES (80)');
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, created_by INTEGER, name TEXT, source TEXT, status TEXT, description TEXT, tags TEXT, stable_key TEXT, current_chunk_id INTEGER, lifecycle_status TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER, type TEXT, content TEXT, content_digest TEXT, lifecycle_status TEXT, created_by INTEGER, created_at TEXT)');
        $id = $this->create(['acceptance_criteria' => 'Private synthetic person and case criteria'])['plan']['id'];
        $this->service->mutate(1, 80, 7, 9, $id, 'evidence', $this->evidenceRequest('knowledge-evidence', 1, '2026-09-03'));
        $this->service->mutate(1, 80, 7, 9, $id, 'review', $this->reviewRequest('knowledge-review', 2, '2026-09-03'));
        $request = ['idempotency_key' => 'knowledge-adaptation', 'expected_revision' => 3,
            'title' => 'Synthetic anonymized practice', 'summary' => 'Public summary', 'steps' => 'Public steps',
            'applicability' => 'Applicable situation', 'stop_conditions' => 'Stop when evidence is absent'];
        $error = null;
        try { $this->service->mutate(1, 80, 7, 9, $id, 'knowledge', $request); } catch (InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(InvalidArgumentException::class, $error);
        self::assertStringContainsString('脱敏验收方法', $error->getMessage());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
        $saved = $this->service->mutate(1, 80, 7, 9, $id, 'knowledge', $request + ['acceptance_criteria' => 'Two anonymous examples']);
        $content = json_decode(Db::name('knowledge_chunks')->value('content'), true);
        self::assertSame('Two anonymous examples', $content['reference_fields']['acceptance_criteria']);
        self::assertStringNotContainsString('Private synthetic', json_encode($content));
        self::assertSame(4, (int)$saved['plan']['revision']);
    }

    private function evidenceRequest(string $key, int $revision, string $date): array
    {
        return ['idempotency_key' => $key, 'expected_revision' => $revision, 'stage' => 'independent',
            'observed_on' => $date, 'sample_count' => 2, 'evidence_ref' => 'synthetic://evidence', 'note' => 'Synthetic observation'];
    }

    private function reviewRequest(string $key, int $revision, string $date): array
    {
        return $this->evidenceRequest($key, $revision, $date) + ['conclusion' => 'target_met', 'criteria_confirmed' => true];
    }

    private function create(array $overrides = []): array
    {
        return $this->service->create(1, 80, 7, 9, array_replace(['case_id' => 1, 'idempotency_key' => 'synthetic-create',
            'cause' => 'skill', 'title' => 'Synthetic coaching', 'cause_basis' => 'Observed skill gap',
            'objective' => 'Demonstrate the documented procedure', 'steps' => 'Demonstrate and repeat',
            'acceptance_criteria' => 'Two independently completed samples', 'responsible_name' => 'Synthetic trainer',
            'business_date' => '2026-09-01', 'due_on' => '2026-09-02', 'review_on' => '2026-09-03', 'minimum_samples' => 2], $overrides));
    }
}
