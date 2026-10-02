<?php
declare(strict_types=1);
namespace Tests;

use app\service\ManagerCapabilityScoringService;
use app\service\ManagerCoachingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Db;

/** Real SQLite projections plus deterministic pre-lock mutations, not a claim of MySQL row-lock concurrency. */
final class ManagerCoachingCaseLockRevalidationTest extends TestCase
{
    private string $path;
    private array $case;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-coaching-lock-review-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
        $this->case = CoachingKnowledgeFixture::createCase();
    }

    protected function tearDown(): void { Db::connect()->close(); @unlink($this->path); }

    private function serviceWithWindow(\Closure $mutation): ManagerCoachingService
    {
        $service = new class extends ManagerCoachingService {
            public int $reads = 0;
            public ?\Closure $afterFirstRead = null;
            protected function caseForScope(int $tenantId, int $hotelId, int $managerId, int $caseId): array
            {
                $case = parent::caseForScope($tenantId, $hotelId, $managerId, $caseId);
                if (++$this->reads === 1 && $this->afterFirstRead !== null) ($this->afterFirstRead)();
                return $case;
            }
        };
        $service->afterFirstRead = $mutation;
        return $service;
    }

    private function input(): array { return CoachingKnowledgeFixture::planInput((int)$this->case['id']); }

    public function testCommittedVoidAfterInitialReadCannotCreatePlanOrEventFromStaleCase(): void
    {
        $caseId = (int)$this->case['id'];
        $scoring = new ManagerCapabilityScoringService();
        self::assertFalse($scoring->readCase(10, 20, 7, $caseId)['is_voided']);
        $service = $this->serviceWithWindow(function () use ($scoring, $caseId): void {
            $scoring->createAdjustment(10, 20, 7, $caseId, ['adjustment_type' => 'voided',
                'reason' => 'Synthetic committed void before coaching acquires the case lock', 'idempotency_key' => 'coaching-void-window']);
            self::assertTrue($scoring->readCase(10, 20, 7, $caseId)['is_voided']);
        });
        $error = null;
        try { $service->create(10, 20, 7, 7, $this->input()); }
        catch (\InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(\InvalidArgumentException::class, $error, 'A post-read committed void must stop creation');
        self::assertStringContainsString('已作废案例不能发起带教计划', $error->getMessage());
        self::assertSame(2, $service->reads);
        self::assertTrue($scoring->readCase(10, 20, 7, $caseId)['is_voided']);
        self::assertSame(1, Db::name('manager_capability_case_adjustments')->count());
        self::assertSame(0, Db::name('manager_coaching_plans')->count());
        self::assertSame(0, Db::name('manager_coaching_events')->count());
    }

    public function testSuccessfulCreationSnapshotsFreshCaseFactsAndRetainsExactIdempotentReadback(): void
    {
        $caseId = (int)$this->case['id'];
        $freshFacts = 'Synthetic confirmed facts updated before acquiring the coaching lock';
        $service = $this->serviceWithWindow(function () use ($caseId, $freshFacts): void {
            Db::name('manager_capability_cases')->where('id', $caseId)->update(['problem_facts' => $freshFacts]);
        });
        $input = $this->input();
        $saved = $service->create(10, 20, 7, 7, $input);
        self::assertSame(2, $service->reads);
        self::assertSame($freshFacts, (new ManagerCapabilityScoringService())->readCase(10, 20, 7, $caseId)['problem_facts']);
        self::assertSame($freshFacts, $saved['plan']['content']['case_snapshot']['problem_facts']);
        self::assertSame($saved, (new ManagerCoachingService())->read(10, 20, 7, (int)$saved['plan']['id']));
        self::assertSame($saved, (new ManagerCoachingService())->create(10, 20, 7, 7, $input));
        self::assertSame(1, Db::name('manager_coaching_plans')->count());
        self::assertSame(1, Db::name('manager_coaching_events')->count());
        $input['title'] = 'Different plan with same retry key';
        try { (new ManagerCoachingService())->create(10, 20, 7, 7, $input); self::fail('Changed replay must conflict'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('重试标识', $e->getMessage()); }
    }

    public function testScopeChangesAndMissingCaseAfterInitialReadFailWithoutWritingPlan(): void
    {
        foreach (['tenant_id' => 11, 'hotel_id' => 21, 'manager_user_id' => 8, 'deleted' => 1] as $column => $value) {
            $caseId = (int)CoachingKnowledgeFixture::createCase()['id'];
            $service = $this->serviceWithWindow(function () use ($caseId, $column, $value): void {
                if ($column === 'deleted') Db::name('manager_capability_cases')->where('id', $caseId)->delete();
                else Db::name('manager_capability_cases')->where('id', $caseId)->update([$column => $value]);
            });
            $error = null;
            try { $service->create(10, 20, 7, 7, CoachingKnowledgeFixture::planInput($caseId)); }
            catch (\RuntimeException $failure) { $error = $failure; }
            self::assertInstanceOf(\RuntimeException::class, $error, 'Changed scope or removed base case must fail');
            self::assertStringContainsString('案例不存在', $error->getMessage());
            self::assertSame(0, Db::name('manager_coaching_plans')->count());
            self::assertSame(0, Db::name('manager_coaching_events')->count());
        }
    }

    public function testRefreshedBusinessDateCannotBypassPlanStartDateGuard(): void
    {
        $caseId = (int)$this->case['id'];
        $initialDate = date('Y-m-d', strtotime('-1 day'));
        Db::name('manager_capability_cases')->where('id', $caseId)->update(['business_date' => $initialDate]);
        $service = $this->serviceWithWindow(function () use ($caseId): void {
            Db::name('manager_capability_cases')->where('id', $caseId)->update(['business_date' => date('Y-m-d')]);
        });
        $input = array_replace($this->input(), ['business_date' => $initialDate]);
        $error = null;
        try { $service->create(10, 20, 7, 7, $input); }
        catch (\InvalidArgumentException $failure) { $error = $failure; }
        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertStringContainsString('不能早于来源案例', $error->getMessage());
        self::assertSame(0, Db::name('manager_coaching_plans')->count());
        self::assertSame(0, Db::name('manager_coaching_events')->count());
    }
}
