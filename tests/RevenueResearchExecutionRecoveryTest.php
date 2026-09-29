<?php
declare(strict_types=1);
namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\RevenueResearchExecutionArtifactService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RevenueResearchExecutionRecoveryTest extends TestCase
{
    private function fixture(): object
    {
        $state = (object)['cache'=>[], 'record'=>null, 'created'=>0, 'time'=>1721430000, 'cleanup'=>'', 'fail'=>''];
        $service = new RevenueResearchExecutionArtifactService(
            fn(string $key): mixed => $state->cache[$key] ?? null,
            function (string $key, array $value, int $ttl) use ($state): bool { $state->cache[$key]=$value; return $ttl===1800; },
            function (string $key) use ($state): bool { if ($state->cleanup==='throw') throw new RuntimeException('synthetic cleanup failure'); if ($state->cleanup==='false') return false; $exists=isset($state->cache[$key]); unset($state->cache[$key]); return $exists; },
            fn(): int => $state->time,
            fn(): string => '0123456789abcdef0123456789abcdef',
            fn(callable $callback): mixed => $callback()
        );
        $research=['status'=>'done','product_key'=>'demand-forecast','hotel_scope'=>['hotel_id'=>7],
            'readiness'=>['stage'=>'research_ready_for_execution','execution_ready'=>true], 'gaps'=>[],
            'result'=>['data_gaps'=>[],'decision_recommendations'=>[['can_create_execution_intent'=>true,
                'decision_quality'=>['contract_version'=>AiDecisionQualityService::CONTRACT_VERSION,'execution_ready'=>true]]]]];
        $artifact=$service->issue($research,42,7);
        $read=function (string $key) use ($state): ?array {
            self::assertMatchesRegularExpression('/^source_intent_[a-f0-9]{32}$/D',$key);
            if ($state->fail==='read' && $state->record!==null) throw new RuntimeException('synthetic read failed',503);
            return $state->record;
        };
        $create=function (array $source, string $key, array $binding) use ($state,$research): array {
            self::assertSame($research,$source);
            if ($state->fail==='before') throw new RuntimeException('synthetic rejected before insert',422);
            $state->created++;
            $state->record=['id'=>701,'source_module'=>'revenue_research','object_type'=>'revenue_research','action_type'=>'demand-forecast',
                'hotel_id'=>7,'created_by'=>42,'status'=>'pending_approval','evidence'=>['revenue_research_artifact'=>$binding],'tasks'=>[]];
            if ($state->fail==='after') throw new RuntimeException('synthetic inserted but response lost',503);
            return $state->record;
        };
        $resolve=fn(): array => $service->resolveExecutionIntent($artifact['id'],42,7,$read,$create);
        return (object)compact('state','service','research','artifact','read','create','resolve');
    }

    public function testConfirmedReceiptReplaysAfterCacheDeletionAndPreservesCurrentStatus(): void
    {
        $f=$this->fixture(); $a=($f->resolve)(); self::assertSame(701,$a['id']); self::assertSame([],$f->state->cache);
        $f->state->record['status']='approved'; $f->state->time+=1900;
        $b=($f->resolve)(); self::assertSame(701,$b['id']); self::assertSame('approved',$b['status']);
        self::assertSame(true,$b['idempotent_replay']); self::assertSame(1,$f->state->created); self::assertSame([],$b['tasks']);
    }

    public static function failures(): array { return [['before',422,0],['after',503,1],['read',503,1]]; }

    #[DataProvider('failures')]
    public function testFailureKeepsArtifactAndExplicitConfirmationDoesNotRepeatPersistedInsert(string $failure,int $code,int $created): void
    {
        $f=$this->fixture(); $f->state->fail=$failure;
        try { ($f->resolve)(); self::fail('Expected synthetic failure.'); } catch (RuntimeException $e) { self::assertSame($code,$e->getCode()); }
        self::assertSame($created,$f->state->created); self::assertCount(1,$f->state->cache);
        $f->state->fail=''; $r=($f->resolve)(); self::assertSame(701,$r['id']); self::assertSame(1,$f->state->created);
    }

    public static function cleanupFailures(): array { return [['false'],['throw']]; }

    #[DataProvider('cleanupFailures')]
    public function testCleanupFailureCannotUndoConfirmedReceiptOrCreateAgain(string $failure): void
    {
        $f=$this->fixture(); $f->state->cleanup=$failure; $a=($f->resolve)(); self::assertCount(1,$f->state->cache);
        $b=($f->resolve)(); self::assertSame($a['id'],$b['id']); self::assertSame(1,$f->state->created);
    }

    public static function badReceipts(): array { return [['artifact'],['actor'],['hotel'],['source'],['creator'],['digest'],['legacy']]; }

    #[DataProvider('badReceipts')]
    public function testUnboundPersistedReceiptNeverBecomesAConfirmedSave(string $field): void
    {
        $f=$this->fixture(); ($f->resolve)();
        if ($field==='artifact') $f->state->record['evidence']['revenue_research_artifact']['artifact_id']=str_repeat('f',32);
        if ($field==='actor') $f->state->record['evidence']['revenue_research_artifact']['actor_id']=43;
        if ($field==='hotel') $f->state->record['hotel_id']=8;
        if ($field==='source') $f->state->record['source_module']='manual';
        if ($field==='creator') $f->state->record['created_by']=43;
        if ($field==='digest') $f->state->record['evidence']['revenue_research_artifact']['research_digest']='';
        if ($field==='legacy') unset($f->state->record['evidence']['revenue_research_artifact']);
        try { ($f->resolve)(); self::fail('Expected invalid receipt.'); } catch (RuntimeException $e) { self::assertSame(503,$e->getCode()); }
        self::assertSame(1,$f->state->created);
    }

    public function testDifferentCreatedAndReadbackIDsRemainUnconfirmedWithArtifactRetained(): void
    {
        $f=$this->fixture();
        $read=function (string $key) use ($f): ?array { $row=($f->read)($key); if ($row!==null) $row['id']=702; return $row; };
        try { $f->service->resolveExecutionIntent($f->artifact['id'],42,7,$read,$f->create); self::fail('Expected mismatched readback.'); }
        catch (RuntimeException $e) { self::assertSame(503,$e->getCode()); }
        self::assertSame(1,$f->state->created); self::assertCount(1,$f->state->cache);
    }
}
