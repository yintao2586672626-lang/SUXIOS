<?php
declare(strict_types=1);
namespace Tests;

use app\service\PromotionExperimentService;
use PHPUnit\Framework\TestCase;
use Tests\Support\PromotionExperimentFixture as F;
use think\facade\Db;

final class PromotionExperimentPersistenceTest extends TestCase
{
    private array $old;
    protected function setUp(): void { $this->old = F::database(); }
    protected function tearDown(): void { F::restoreDatabase($this->old); }
    private function request(): array { return ['experiment_key' => 'synthetic-1', 'idempotency_key' => 'synthetic-request-1', 'expected_version' => 0, 'input' => F::input()]; }

    public function testSaveExactReadbackDuplicateRetryAndImmutableVersionEditing(): void
    {
        $s = new PromotionExperimentService(); $request = $this->request();
        $v1 = $s->save(F::scope(), 7001, $request);
        self::assertSame(1, $v1['version_no']);
        self::assertSame('exact', $v1['readback_status']);
        self::assertEquals($request['input'], $v1['input']);
        $replay = $s->save(F::scope(), 7001, $request);
        self::assertSame($v1['id'], $replay['id']); self::assertTrue($replay['idempotent_replay']);
        $request['expected_version'] = 1; $request['idempotency_key'] = 'synthetic-request-2';
        $request['input']['observation']['notes'] = 'SYNTHETIC revised observation';
        $v2 = $s->save(F::scope(), 7001, $request);
        self::assertSame(2, $v2['version_no']);
        self::assertNotSame($v1['payload_digest'], $v2['payload_digest']);
        self::assertSame($v1['result'], $s->read(F::scope(), $v1['id'])['result']);
        self::assertSame($v2['result'], $s->read(F::scope(), $v2['id'])['result']);
        self::assertCount(2, $s->history(F::scope())['items']);
    }

    public function testChangedRetryAndStaleVersionNeverOverwrite(): void
    {
        $s = new PromotionExperimentService(); $request = $this->request();
        $s->save(F::scope(), 7001, $request);
        $request['input']['plan']['hypothesis'] = 'changed';
        foreach (['synthetic-request-1', 'another-request'] as $key) {
            $request['idempotency_key'] = $key;
            try { $s->save(F::scope(), 7001, $request); self::fail('Should conflict'); }
            catch (\RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        }
        self::assertSame(1, Db::name(PromotionExperimentService::TABLE)->count());
    }

    public function testExactReadRejectsEveryOtherScopeAndCorruption(): void
    {
        $s = new PromotionExperimentService(); $v = $s->save(F::scope(), 7001, $this->request());
        foreach (['tenant_id' => 800, 'system_hotel_id' => 801, 'platform' => 'meituan', 'platform_store_id' => 'OTHER', 'period_end' => '2026-08-12'] as $k => $value) {
            $scope = array_replace(F::scope(), [$k => $value]);
            self::assertSame([], $s->history($scope)['items']);
            try { $s->read($scope, $v['id']); self::fail('Should reject scope'); }
            catch (\RuntimeException $e) { self::assertSame(404, $e->getCode()); }
        }
        Db::name(PromotionExperimentService::TABLE)->where('id', $v['id'])->update(['payload_json' => '{}']);
        $this->expectException(\RuntimeException::class); $this->expectExceptionMessage('完整性');
        $s->read(F::scope(), $v['id']);
    }

    public function testInvalidDataDoesNotPersistAndCanRecoverWithSameRequestKey(): void
    {
        $s = new PromotionExperimentService(); $r = $this->request(); $r['input']['records'][0]['spend'] = -1;
        try { $s->save(F::scope(), 7001, $r); self::fail('Invalid data'); }
        catch (\InvalidArgumentException) { self::assertSame(0, Db::name(PromotionExperimentService::TABLE)->count()); }
        self::assertSame(1, $s->save(F::scope(), 7001, $this->request())['version_no']);
    }

    public function testPlanOnlyCanSaveThenRecoverWithObservedData(): void
    {
        $s = new PromotionExperimentService(); $r = $this->request(); unset($r['input']['observation'], $r['input']['concurrent_changes']); $r['input']['records'] = [];
        $plan = $s->save(F::scope(), 7001, $r);
        self::assertSame('unknown', $plan['result']['incrementality']['status']);
        $r = $this->request(); $r['expected_version'] = 1; $r['idempotency_key'] = 'observed';
        self::assertSame('directional_estimate', $s->save(F::scope(), 7001, $r)['result']['incrementality']['status']);
        self::assertSame('unknown', $s->read(F::scope(), $plan['id'])['result']['incrementality']['status']);
    }
}
