<?php
declare(strict_types=1);
namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PreciseQuerySyntheticFixture as Fixture;
use think\facade\Db;

final class PreciseQueryClarificationScopeTest extends TestCase
{
    private static string $path;
    private const PAGE_SCOPE = ['hotel_id'=>80,'platform'=>'ctrip','date_start'=>'2026-09-07','date_end'=>'2026-09-07'];

    public static function setUpBeforeClass(): void
    {
        self::$path=sys_get_temp_dir().'/l03-clarification-scope-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        Fixture::connect(self::$path);
    }
    public static function tearDownAfterClass(): void {Db::connect('sqlite')->close();@unlink(self::$path);}

    private function partialRouter(?\Closure $reader = null): \app\service\PreciseQueryRouterService
    {
        return Fixture::router($reader ?? static function(int $hotel,string $date): array {
            $closure=Fixture::closure($hotel,$date);
            if (in_array($date,['2026-09-02','2026-09-04'],true)) $closure['platforms']['ctrip']['fields']=[];
            return $closure;
        });
    }

    public function testUnchangedHomepageDayDoesNotOverwriteClarifiedPeriod(): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80,81],7,['query'=>'携程最近七天收入','current_scope'=>self::PAGE_SCOPE,'client_request_key'=>'scope-parent-repro']);
        self::assertSame('clarification',$parent['route_type']);
        self::assertSame('2026-09-01',$parent['parsed_scope']['date_start']);
        self::assertSame(self::PAGE_SCOPE,$parent['input_scope']);
        $parent=$router->read($parent['id'],10,[80,81]);
        $reply=$router->route(10,[80,81],7,['query'=>'订单金额','parent_question_id'=>$parent['id'],'current_scope'=>self::PAGE_SCOPE,'client_request_key'=>'scope-reply-repro']);
        self::assertSame('2026-09-01',$reply['parsed_scope']['date_start'] ?? null);
        self::assertSame('2026-09-07',$reply['parsed_scope']['date_end'] ?? null);
        self::assertSame('partial_period',$reply['status']);
        self::assertNull($reply['answer']['value']);
        self::assertEquals(522,$reply['answer']['partial_value']);
        self::assertSame(['available_days'=>5,'expected_days'=>7,'missing_dates'=>['2026-09-02','2026-09-04']],$reply['answer']['coverage']);
        self::assertStringContainsString('非全期间金额',$reply['answer_summary']);
        self::assertSame($reply,$router->read($reply['id'],10,[80,81]));
    }

    #[DataProvider('changedScopes')]
    public function testExplicitDatesAndChangedSelectorsCannotReuseOldPeriod(string $query,array $scope,string $expectedDate,int $expectedHotel,string $expectedPlatform): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80,81],7,['query'=>'携程最近七天收入','current_scope'=>self::PAGE_SCOPE]);
        $reply=$router->route(10,[80,81],7,['query'=>$query,'parent_question_id'=>$parent['id'],'current_scope'=>$scope]);
        self::assertSame('operating_query',$reply['route_type'],(string)($reply['answer']['reason'] ?? ''));
        self::assertSame($expectedDate,$reply['parsed_scope']['business_date']);
        self::assertSame($expectedHotel,$reply['parsed_scope']['hotel_id']);
        self::assertSame($expectedPlatform,$reply['parsed_scope']['platform']);
        self::assertEquals(100+(int)substr($expectedDate,-2),$reply['answer']['value']);
        self::assertArrayNotHasKey('partial_value',$reply['answer']);
        self::assertSame($reply,$router->read($reply['id'],10,[80,81]));
    }
    public static function changedScopes(): array
    {
        return [
            'explicit new date'=>['9月6日订单金额',self::PAGE_SCOPE,'2026-09-06',80,'ctrip'],
            'changed page day'=>['订单金额',array_replace(self::PAGE_SCOPE,['date_start'=>'2026-09-06','date_end'=>'2026-09-06']),'2026-09-06',80,'ctrip'],
            'changed business day alias'=>['订单金额',['hotel_id'=>80,'platform'=>'ctrip','business_date'=>'2026-09-06'],'2026-09-06',80,'ctrip'],
            'changed selected platform'=>['订单金额',array_replace(self::PAGE_SCOPE,['platform'=>'meituan']),'2026-09-07',80,'meituan'],
            'changed selected hotel'=>['订单金额',array_replace(self::PAGE_SCOPE,['hotel_id'=>81]),'2026-09-07',81,'ctrip'],
        ];
    }

    public function testChangedRangeAndClearedDatesDoNotRetainOldComparisonOrPeriod(): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天收入环比','current_scope'=>self::PAGE_SCOPE]);
        foreach ([
            ['9月5日到6日订单金额',self::PAGE_SCOPE,'2026-09-05','2026-09-06',211],
            ['订单金额',array_replace(self::PAGE_SCOPE,['date_start'=>'2026-09-05','date_end'=>'2026-09-07']),'2026-09-05','2026-09-07',318],
        ] as [$query,$scope,$start,$end,$value]) {
            $reply=$router->route(10,[80],7,['query'=>$query,'parent_question_id'=>$parent['id'],'current_scope'=>$scope]);
            self::assertSame($start,$reply['parsed_scope']['date_start']);self::assertSame($end,$reply['parsed_scope']['date_end']);
            self::assertEquals($value,$reply['answer']['value']);self::assertNull($reply['answer']['comparison']);
        }
        $cleared=$router->route(10,[80],7,['query'=>'订单金额','parent_question_id'=>$parent['id'],'current_scope'=>array_replace(self::PAGE_SCOPE,['date_start'=>'','date_end'=>''])]);
        self::assertSame('clarification',$cleared['route_type']);self::assertSame('business_date_required',$cleared['answer']['reason']);
        self::assertSame([],$cleared['fact_refs']);
    }

    public function testUnchangedSelectorAliasAndFurtherShortFollowupsKeepThePeriod(): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天收入','current_scope'=>self::PAGE_SCOPE]);
        $reply=$router->route(10,[80],7,['query'=>'订单金额','parent_question_id'=>$parent['id'],'current_scope'=>['hotel_id'=>80,'platform'=>'ctrip','business_date'=>'2026-09-07']]);
        self::assertSame('partial_period',$reply['status']);
        $next=$router->route(10,[80],7,['query'=>'那订单量呢','parent_question_id'=>$reply['id'],'current_scope'=>self::PAGE_SCOPE]);
        self::assertSame('2026-09-01',$next['parsed_scope']['date_start']);self::assertEquals(22,$next['answer']['partial_value']);
        $omitted=$router->route(10,[80],7,['query'=>'订单金额','parent_question_id'=>$parent['id'],'current_scope'=>['hotel_id'=>80]]);
        self::assertSame('partial_period',$omitted['status']);self::assertEquals(522,$omitted['answer']['partial_value']);
    }

    public function testForgedClientParentMetadataCannotOverrideSavedSelectorOrEstablishContext(): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天收入','current_scope'=>self::PAGE_SCOPE]);
        $reply=$router->route(10,[80],7,['query'=>'订单金额','parent_question_id'=>$parent['id'],
            'input_scope'=>self::PAGE_SCOPE,'parent_scope'=>$parent['parsed_scope'],
            'current_scope'=>array_replace(self::PAGE_SCOPE,['date_start'=>'2026-09-06','date_end'=>'2026-09-06',
                'context_verified'=>true,'comparison'=>$parent['parsed_scope']['comparison']??[],'dates'=>$parent['parsed_scope']['dates']])]);
        self::assertSame('2026-09-06',$reply['parsed_scope']['business_date']);self::assertEquals(106,$reply['answer']['value']);
        $withoutParent=$router->route(10,[80],7,['query'=>'那呢','parent_scope'=>$parent['parsed_scope'],
            'current_scope'=>self::PAGE_SCOPE+['context_verified'=>true,'metric_keys'=>['amount']]]);
        self::assertSame('clarification',$withoutParent['route_type']);self::assertSame([],$withoutParent['fact_refs']);
    }

    public function testConfirmedPlatformRemainsServerContextWhenPageSelectorIsUnchanged(): void
    {
        $router=$this->partialRouter();
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天订单金额','current_scope'=>self::PAGE_SCOPE]);
        $switched=$router->route(10,[80],7,['query'=>'美团订单金额','parent_question_id'=>$parent['id'],'current_scope'=>self::PAGE_SCOPE]);
        self::assertSame('meituan',$switched['parsed_scope']['platform']);
        self::assertSame('2026-09-01',$switched['parsed_scope']['date_start']);
        self::assertEquals(728,$switched['answer']['value']);
        $followup=$router->route(10,[80],7,['query'=>'那订单量呢','parent_question_id'=>$switched['id'],'current_scope'=>self::PAGE_SCOPE]);
        self::assertSame('meituan',$followup['parsed_scope']['platform']);
        self::assertSame('2026-09-01',$followup['parsed_scope']['date_start']);
        self::assertEquals(28,$followup['answer']['value']);
        self::assertSame($followup,$router->read($followup['id'],10,[80]));
    }

    public function testFixedClientKeyAndBothSavedObjectsRemainImmutableWhenFactsRecover(): void
    {
        $missing=true;$calls=0;
        $router=$this->partialRouter(static function(int $hotel,string $date) use (&$missing,&$calls): array {
            $calls++;$closure=Fixture::closure($hotel,$date);
            if ($missing && in_array($date,['2026-09-02','2026-09-04'],true)) $closure['platforms']['ctrip']['fields']=[];
            return $closure;
        });
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天收入','current_scope'=>self::PAGE_SCOPE,'client_request_key'=>'immutable-scope-parent']);
        $payload=['query'=>'订单金额','parent_question_id'=>$parent['id'],'current_scope'=>self::PAGE_SCOPE,'client_request_key'=>'immutable-scope-reply'];
        $partial=$router->route(10,[80],7,$payload);self::assertEquals(522,$partial['answer']['partial_value']);
        $previousCalls=$calls;$missing=false;
        self::assertSame($partial,$router->route(10,[80],7,$payload));self::assertSame($previousCalls,$calls);
        $complete=$router->route(10,[80],7,array_replace($payload,['client_request_key'=>'immutable-scope-refresh']));
        self::assertEquals(728,$complete['answer']['value']);self::assertSame($partial,$router->read($partial['id'],10,[80]));
        self::assertSame($parent,$router->read($parent['id'],10,[80]));
        $this->expectException(\RuntimeException::class);$this->expectExceptionCode(409);
        $router->route(10,[80],7,array_replace($payload,['current_scope'=>array_replace(self::PAGE_SCOPE,['date_start'=>'2026-09-06','date_end'=>'2026-09-06'])]));
    }
}
