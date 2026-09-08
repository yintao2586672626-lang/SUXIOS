<?php
declare(strict_types=1);
namespace Tests;

use app\service\PreciseQueryPeriodService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PreciseQuerySyntheticFixture as Fixture;
use think\facade\Db;

final class PreciseQueryAcceptanceTest extends TestCase
{
    private static string $path;
    public static function setUpBeforeClass(): void
    {
        self::$path = sys_get_temp_dir().'/l03-synthetic-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        Fixture::connect(self::$path);
    }
    public static function tearDownAfterClass(): void { Db::connect('sqlite')->close(); @unlink(self::$path); }

    public static function chineseDayCounts(): array
    {
        $names = ['一','二','三','四','五','六','七','八','九','十','十一','十二','十三','十四','十五','十六','十七','十八','十九','二十','二十一','二十二','二十三','二十四','二十五','二十六','二十七','二十八','二十九','三十','三十一'];
        $cases = [];
        foreach ($names as $index => $name) $cases[$name] = [$name, $index + 1];
        $cases['两'] = ['两', 2];
        return $cases;
    }

    #[DataProvider('chineseDayCounts')]
    public function testEverySupportedChineseDayCountUsesExactPeriodAndReadback(string $number, int $days): void
    {
        $router = Fixture::router();
        $query = '携程最近' . $number . '天订单额';
        $result = $router->route(10, [80], 7, ['query' => $query, 'current_scope' => ['hotel_id' => 80, 'business_date' => '2026-09-03']]);
        self::assertSame('answered_from_period_facts', $result['status'], $query);
        self::assertSame($days, $result['answer']['coverage']['expected_days']);
        self::assertSame((new DateTimeImmutable('2026-09-08'))->modify('-' . $days . ' days')->format('Y-m-d'), $result['parsed_scope']['date_start']);
        self::assertSame('2026-09-07', $result['parsed_scope']['date_end']);
        self::assertSame($result, $router->read($result['id'], 10, [80]));
    }

    public function testOutOfRangeChineseDayCountsCannotFallBackToSelectedDay(): void
    {
        foreach (['零', '三十二', '九十九', '一百'] as $number) {
            $result = Fixture::router()->route(10, [80], 7, ['query' => '携程过去的' . $number . '天订单额',
                'current_scope' => ['hotel_id' => 80, 'business_date' => '2026-09-03']]);
            self::assertSame('clarification_required', $result['status'], $number);
            self::assertSame('period_limit', $result['answer']['reason'], $number);
        }
    }

    #[DataProvider('questions')]
    public function testDailyUsageQuestion(string $query, string $route, string $status, array $scope = [], array $expected = []): void
    {
        $router = Fixture::router();
        $result = $router->route(10, [80,81,82], 7, ['query'=>$query, 'current_scope'=>$scope + ['hotel_id'=>80],
            'visible_topic_keys'=>['data-health','auto-collect','revenue-report','ai-daily-report','knowledge-search','operations','daily-workbench','automation-monitor','typeless-dictionary','notifications']]);
        self::assertSame($route,$result['route_type'], $query.' '.json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame($status,$result['status'], $query.' '.json_encode($result['answer'], JSON_UNESCAPED_UNICODE));
        self::assertGreaterThan(0,$result['id']);
        self::assertSame($result,$router->read($result['id'],10,[80,81,82]));
        self::assertFalse($result['boundaries']['external_llm_called']);
        foreach ($expected as $path=>$value) {
            $actual = $result;
            foreach (explode('.',$path) as $part) $actual = $actual[$part] ?? null;
            self::assertEquals($value,$actual,$query.' '.$path);
        }
    }

    public static function questions(): array
    {
        $day = 'answered_from_canonical_closure'; $period = 'answered_from_period_facts'; $clarify='clarification_required';
        return [
            '01 order amount yesterday'=>['携程昨天订单额多少','operating_query',$day,[],['answer.value'=>107,'parsed_scope.business_date'=>'2026-09-07']],
            '02 orders explicit'=>['携程2026-09-06订单量','operating_query',$day,[],['answer.value'=>6]],
            '03 room nights'=>['美团前天间夜多少','operating_query',$day,[],['answer.value'=>7]],
            '04 exposure people'=>['美团昨天曝光人数','operating_query',$day,[],['answer.value'=>700]],
            '05 visitor count'=>['携程昨天详情访客人数','operating_query',$day,[],['answer.value'=>70]],
            '06 conversion'=>['携程昨天曝光到访率','operating_query',$day,[],['answer.value'=>10]],
            '07 UTC business day'=>['携程今天订单量','operating_query',$day,[],['parsed_scope.business_date'=>'2026-09-08']],
            '08 old year'=>['美团2025年9月1日订单量','operating_query',$day,[],['parsed_scope.business_date'=>'2025-09-01']],
            '09 accessible cross tenant hotel'=>['江南客栈携程昨天订单额','operating_query',$day,[],['parsed_scope.hotel_id'=>82]],
            '10 explicit hotel overrides'=>['酒店81携程昨天订单量','operating_query',$day,[],['parsed_scope.hotel_id'=>81]],
            '11 selected date'=>['美团订单量','operating_query',$day,['business_date'=>'2026-09-03'],['answer.value'=>3]],
            '12 same day selected'=>['美团当天间夜','operating_query',$day,['business_date'=>'2026-09-03'],['answer.value'=>4]],
            '13 canonical room revenue unavailable'=>['携程昨天房费收入','operating_query','blocked_by_canonical_fact_status'],
            '14 settlement unavailable'=>['美团昨天结算金额','operating_query','blocked_by_canonical_fact_status'],
            '15 whole hotel denominator'=>['携程昨天入住率','operating_query','blocked_by_canonical_fact_status'],
            '16 revenue per available room'=>['美团昨天RevPAR','operating_query','blocked_by_canonical_fact_status'],
            '17 ADR semantic gate'=>['携程昨天ADR','operating_query','blocked_by_canonical_fact_status'],
            '18 impressions are not people'=>['携程昨天曝光量','operating_query','blocked_by_canonical_fact_status'],
            '19 recent seven'=>['携程最近七天订单额','operating_query',$period,[],['answer.value'=>728,'answer.coverage.expected_days'=>7]],
            '20 cross month'=>['携程2026-08-30到2026-09-02订单额','operating_query',$period,[],['answer.value'=>464,'answer.coverage.expected_days'=>4]],
            '21 explicit month'=>['美团2026年8月订单量','operating_query',$period,[],['answer.value'=>496]],
            '22 previous month'=>['携程上个月间夜','operating_query',$period,[],['answer.value'=>527]],
            '23 current completed month'=>['携程本月订单额','operating_query',$period,[],['parsed_scope.date_end'=>'2026-09-07']],
            '24 previous week'=>['美团上周订单量','operating_query',$period,[],['answer.value'=>52]],
            '25 recent thirty'=>['携程近30天间夜','operating_query',$period,[],['answer.coverage.expected_days'=>30]],
            '26 Chinese abbreviated range'=>['美团9月1日到3日订单额','operating_query',$period,[],['answer.value'=>306]],
            '27 slash range'=>['携程9/2至9/4订单量','operating_query',$period,[],['answer.value'=>9]],
            '28 relative range'=>['携程前天到昨天订单量','operating_query',$period,[],['answer.value'=>13]],
            '29 selected range'=>['美团订单额','operating_query',$period,['date_start'=>'2026-09-01','date_end'=>'2026-09-03'],['answer.value'=>306]],
            '30 equal period change'=>['携程最近七天订单额环比','operating_query',$period,[],['answer.comparison.comparable'=>true]],
            '31 day difference'=>['携程昨天订单额比前天','operating_query',$period,[],['answer.comparison.difference'=>1]],
            '32 explicit date comparison'=>['美团2026-09-06对比2026-09-07订单量','operating_query',$period,[],['answer.comparison.difference'=>-1]],
            '33 unequal windows'=>['携程本月订单额对比上月','operating_query','blocked_by_incomparable_scope'],
            '34 prior weekday compare'=>['美团昨天曝光人数比前天','operating_query',$period,[],['answer.comparison.difference'=>100]],
            '35 platform winner refused'=>['昨天哪个平台表现更好','operating_query','blocked_by_cross_platform_comparison'],
            '36 differing platforms refused'=>['比较携程和美团昨天订单额','operating_query','blocked_by_cross_platform_comparison'],
            '37 revenue meaning first'=>['最近七天携程收入','clarification',$clarify,[],['answer.reason'=>'revenue_definition_ambiguous']],
            '38 ambiguous hotel'=>['湖畔酒店携程昨天订单量','clarification',$clarify,[],['answer.reason'=>'hotel_name_ambiguous']],
            '39 missing hotel'=>['携程昨天订单量','clarification',$clarify,['hotel_id'=>0],['answer.reason'=>'hotel_required']],
            '40 missing platform'=>['昨天订单量','clarification',$clarify,[],['answer.reason'=>'platform_required']],
            '41 missing date'=>['携程订单额','clarification',$clarify,[],['answer.reason'=>'business_date_required']],
            '42 missing metric'=>['携程昨天查数','clarification',$clarify,[],['answer.reason'=>'metric_required']],
            '43 reversed interval'=>['携程2026-09-07至2026-09-01订单量','clarification',$clarify,[],['answer.reason'=>'business_date_invalid']],
            '44 invalid leap day'=>['携程2026-02-29至2026-03-01订单量','clarification',$clarify,[],['answer.reason'=>'business_date_invalid']],
            '45 excessive interval'=>['携程最近90天订单额','clarification',$clarify,[],['answer.reason'=>'period_limit']],
            '46 distinct dates ambiguous operator'=>['携程9月1日和9月3日订单量','clarification',$clarify,[],['answer.reason'=>'distinct_dates_need_operator']],
            '47 visitor union missing'=>['美团最近七天访客人数','clarification',$clarify,[],['answer.reason'=>'period_metric_not_additive']],
            '48 rate aggregation missing'=>['携程上周曝光到访率','clarification',$clarify,[],['answer.reason'=>'period_metric_not_additive']],
            '49 year ambiguity'=>['携程12月1日至12月3日订单额','clarification',$clarify,[],['answer.reason'=>'month_day_year_ambiguous']],
            '50 unspecified comparison'=>['携程订单额同比','clarification',$clarify,[],['answer.reason'=>'comparison_period_required']],
            '51 navigation data health'=>['数据健康在哪里','system_navigation','navigation_ready'],
            '52 navigation collection'=>['自动采集怎么用','system_navigation','navigation_ready'],
            '53 navigation revenue'=>['收益报表在哪里','system_navigation','navigation_ready'],
            '54 navigation knowledge'=>['知识中心怎么打开','system_navigation','navigation_ready'],
            '55 navigation reports'=>['AI日报在哪里','system_navigation','navigation_ready'],
            '56 navigation task'=>['运营任务在哪里','system_navigation','navigation_ready'],
            '57 glossary ADR'=>['ADR是什么意思','term_definition','reference_only'],
            '58 personal term'=>['Openness是酒店指标吗','term_definition','reference_only'],
            '59 glossary tool'=>['Codex是什么','term_definition','reference_only'],
            '60 platform scope conflict'=>['携程昨天订单量','clarification',$clarify,['platform'=>'meituan'],['answer.reason'=>'platform_scope_conflict']],
        ];
    }

    public function testPartialIncomeClarifiesThenSavesExactFiveOfSevenAndRecovers(): void
    {
        $missing = ['2026-09-02','2026-09-04']; $calls=0;
        $router=Fixture::router(static function(int $hotel,string $date) use (&$missing,&$calls): array {
            $calls++; $closure=Fixture::closure($hotel,$date);
            if (in_array($date,$missing,true)) $closure['platforms']['ctrip']['fields']=[];
            return $closure;
        });
        $first=$router->route(10,[80],7,['query'=>'最近七天携程收入','current_scope'=>['hotel_id'=>80]]);
        self::assertSame('revenue_definition_ambiguous',$first['answer']['reason']); self::assertSame(0,$calls);
        $payload=['query'=>'订单金额','parent_question_id'=>$first['id'],'current_scope'=>['hotel_id'=>80],'client_request_key'=>'partial-unique-01'];
        $partial=$router->route(10,[80],7,$payload);
        self::assertSame('partial_period',$partial['status']); self::assertNull($partial['answer']['value']);
        self::assertEquals(522,$partial['answer']['partial_value']); self::assertSame($missing,$partial['answer']['coverage']['missing_dates']);
        self::assertSame(5,$partial['answer']['coverage']['available_days']); self::assertStringContainsString('非全期间金额',$partial['answer_summary']);
        self::assertSame($partial,$router->read($partial['id'],10,[80]));
        $missing=[]; $previousCalls=$calls;
        self::assertSame($partial,$router->route(10,[80],7,$payload)); self::assertSame($previousCalls,$calls,'retry must not re-read changed facts');
        $payload['client_request_key']='partial-unique-02';
        $complete=$router->route(10,[80],7,$payload); self::assertEquals(728,$complete['answer']['value']);
        self::assertSame($partial,$router->read($partial['id'],10,[80]),'historical partial record is immutable');
    }

    public function testSameClientKeyRejectsDifferentQuestionOrHotel(): void
    {
        $router=Fixture::router(); $payload=['query'=>'携程最近七天订单量','current_scope'=>['hotel_id'=>80],'client_request_key'=>'conflict-case-01'];
        $router->route(10,[80,81],7,$payload);
        foreach ([['query'=>'携程昨天订单额'],['current_scope'=>['hotel_id'=>81]]] as $change) {
            try {$router->route(10,[80,81],7,array_replace($payload,$change));self::fail('must reject changed request');}
            catch (\RuntimeException $e) {self::assertSame(409,$e->getCode());}
        }
    }

    public function testHotelSwitchDropsOldMetricDateAndPlatformContext(): void
    {
        $router=Fixture::router();
        $parent=$router->route(10,[80,81],7,['query'=>'携程昨天订单量','current_scope'=>['hotel_id'=>80]]);
        $next=$router->route(10,[80,81],7,['query'=>'那前天呢','parent_question_id'=>$parent['id'],'current_scope'=>['hotel_id'=>80]]);
        self::assertSame('2026-09-06',$next['parsed_scope']['business_date']);
        $switched=$router->route(10,[80,81],7,['query'=>'那前天呢','parent_question_id'=>$parent['id'],'current_scope'=>['hotel_id'=>81]]);
        self::assertSame('clarification',$switched['route_type']); self::assertNull($switched['parsed_scope']['platform'] ?? null);
        self::assertSame([], $switched['fact_refs']);
    }

    public function testUnauthorizedHotelAndParentCannotBeUsed(): void
    {
        $router=Fixture::router(); $parent=$router->route(10,[81],7,['query'=>'携程昨天订单量','current_scope'=>['hotel_id'=>81]]);
        foreach ([['query'=>'酒店90携程昨天订单量'], ['query'=>'那前天呢','parent_question_id'=>$parent['id']]] as $payload) {
            try {$router->route(10,[80],7,$payload+['current_scope'=>['hotel_id'=>80]]);self::fail('unauthorized');}
            catch (\RuntimeException $e) { self::assertStringContainsString('无权',$e->getMessage()); }
        }
    }

    public function testZeroDenominatorAndAllMissingAreNotZeroFilled(): void
    {
        $router=Fixture::router(static function(int $hotel,string $date): array {
            $c=Fixture::closure($hotel,$date);
            foreach ($c['platforms']['ctrip']['fields'] as &$f) if ($date==='2026-09-06') $f['value']=0;
            return $c;
        });
        $r=$router->route(10,[80],7,['query'=>'携程昨天订单额比前天','current_scope'=>['hotel_id'=>80]]);
        self::assertEquals(107,$r['answer']['comparison']['difference']); self::assertNull($r['answer']['comparison']['change_percent']);
        self::assertSame('zero_denominator',$r['answer']['comparison']['change_status']);
        $router=Fixture::router(static function(int $hotel,string $date): array {$c=Fixture::closure($hotel,$date);$c['platforms']['ctrip']['fields']=[];return $c;});
        $r=$router->route(10,[80],7,['query'=>'携程最近七天订单额','current_scope'=>['hotel_id'=>80]]);
        self::assertSame('blocked_by_period_facts',$r['status']);self::assertNull($r['answer']['partial_value']);self::assertNull($r['answer']['value']);
    }

    #[DataProvider('badFacts')]
    public function testUnusableDailyFactNeverBecomesFullPeriod(string $field, mixed $value, string $expectedStatus): void
    {
        $router=Fixture::router(static function(int $hotel,string $date) use ($field,$value): array {
            $c=Fixture::closure($hotel,$date);
            if ($date==='2026-09-03') $c['platforms']['ctrip']['fields'][0][$field]=$value;
            return $c;
        });
        $r=$router->route(10,[80],7,['query'=>'携程最近七天订单额','current_scope'=>['hotel_id'=>80]]);
        self::assertSame($expectedStatus,$r['status']); self::assertNull($r['answer']['value']);
        if ($expectedStatus==='partial_period') self::assertContains('2026-09-03',$r['answer']['coverage']['missing_dates']);
        self::assertSame($r,$router->read($r['id'],10,[80]));
    }
    public static function badFacts(): array
    {
        return [
            'wrong tenant'=>['tenant_id',99,'partial_period'],
            'wrong hotel'=>['system_hotel_id',81,'partial_period'],
            'wrong platform'=>['platform','meituan','partial_period'],
            'wrong date'=>['business_date','2026-09-02','partial_period'],
            'missing collected time'=>['collected_at',null,'partial_period'],
            'unverified readback'=>['readback_status','not_attempted','partial_period'],
            'failed strict gate'=>['strict_final_gate',false,'partial_period'],
            'unknown currency'=>['unit','USD','partial_period'],
            'settlement field'=>['field_fact_identities',[['normalized_metric_key'=>'settlement_amount']],'partial_period'],
            'untrusted ref'=>['source_record_refs',['model_claim#123'],'partial_period'],
            'missing store'=>['platform_store_id',null,'partial_period'],
            'different store'=>['platform_store_id','other-store','blocked_by_period_facts'],
            'semantic drift'=>['semantic_metric_key','different_semantic','blocked_by_period_facts'],
            'reused daily row'=>['source_record_refs',['online_daily_data#20260902801'],'blocked_by_period_facts'],
        ];
    }

    public function testRequestLockReturns429AndCanRecover(): void
    {
        $key='lock-recovery-case';
        $requestKey='precise-client:v1:'.substr(hash('sha256',json_encode([10,7,$key])),0,48);
        $dir=\app\service\LocalStatePathPolicy::scopedLockDirectory('precise-query');
        if (!is_dir($dir)) mkdir($dir,0770,true);
        $handle=fopen($dir.'/'.hash('sha256',$requestKey).'.lock','c');flock($handle,LOCK_EX);
        $router=Fixture::router();$payload=['query'=>'携程昨天订单额','current_scope'=>['hotel_id'=>80],'client_request_key'=>$key];
        try {$router->route(10,[80],7,$payload);self::fail('must reject in-flight duplicate');}
        catch (\RuntimeException $e) {
            self::assertSame(429,$e->getCode());
            $errors=(new \ReflectionClass(\app\controller\PreciseQuery::class))->getConstant('BUSINESS_ERRORS');
            self::assertSame(429,\app\service\ApiExceptionMapper::response($e,'failed',$errors)->getCode());
        } finally {flock($handle,LOCK_UN);fclose($handle);}
        $record=$router->route(10,[80],7,$payload); self::assertEquals(107,$record['answer']['value']);
        self::assertSame($record,$router->route(10,[80],7,$payload));
    }

    public function testReadbackStillVerifiesLegacyV1AndRejectsTampering(): void
    {
        $router=Fixture::router();
        $record=$router->route(10,[80],7,['query'=>'携程昨天订单量','current_scope'=>['hotel_id'=>80]]);
        $row=Db::name('hotel_operating_questions')->where('id',$record['id'])->find();
        $answer=json_decode($row['answer_json'],true);
        unset($answer['query_router']['client_request_digest']);
        $canonicalize=function($item) use (&$canonicalize) {if(!is_array($item))return $item;if(!array_is_list($item))ksort($item);return array_map($canonicalize,$item);};
        $digest=hash('sha256',json_encode($canonicalize(['question'=>$row['question_text'],'answer'=>$answer,
            'fact_refs'=>json_decode($row['fact_refs_json'],true),'memory_refs'=>json_decode($row['memory_refs_json'],true),
            'knowledge_refs'=>json_decode($row['knowledge_refs_json'],true),'execution_refs'=>json_decode($row['execution_refs_json'],true)]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        Db::name('hotel_operating_questions')->where('id',$record['id'])->update(['answer_json'=>json_encode($answer,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'content_digest'=>$digest]);
        self::assertEquals(7,$router->read($record['id'],10,[80])['answer']['value']);
        Db::name('hotel_operating_questions')->where('id',$record['id'])->update(['answer_json'=>'{}']);
        $this->expectException(\RuntimeException::class);$router->read($record['id'],10,[80]);
    }

    public function testClarificationAnswersKeepAlreadyKnownConditions(): void
    {
        $router=Fixture::router();
        foreach ([
            ['湖畔酒店携程昨天订单量','酒店80',['hotel_id'=>0]],
            ['昨天订单量','携程',['hotel_id'=>80]],
            ['携程昨天订单量','酒店80',['hotel_id'=>0]],
            ['携程订单量','昨天',['hotel_id'=>80]],
        ] as [$question,$reply,$scope]) {
            $parent=$router->route(10,[80,81],7,['query'=>$question,'current_scope'=>$scope]);
            self::assertSame('clarification',$parent['route_type']);
            $record=$router->route(10,[80,81],7,['query'=>$reply,'parent_question_id'=>$parent['id'],'current_scope'=>$scope]);
            self::assertSame('operating_query',$record['route_type'],$question.' -> '.$reply);
            self::assertEquals(7,$record['answer']['value']);self::assertSame('2026-09-07',$record['parsed_scope']['business_date']);
        }
        $parent=$router->route(10,[80],7,['query'=>'携程最近七天收入','current_scope'=>['hotel_id'=>80]]);
        $record=$router->route(10,[80],7,['query'=>'结算金额','parent_question_id'=>$parent['id'],'current_scope'=>['hotel_id'=>80]]);
        self::assertSame('blocked_by_period_facts',$record['status']);self::assertSame('settlement_amount',$record['answer']['metric']['key']);
        self::assertNull($record['answer']['value']);self::assertNull($record['answer']['partial_value']);
    }

    public function testPeriodQualityReceiptTracksCoverageAndDetectsCorruptCalculations(): void
    {
        $router=Fixture::router();$record=$router->route(10,[80],7,['query'=>'携程最近七天订单额','current_scope'=>['hotel_id'=>80]]);
        self::assertSame('passed',$record['analysis_quality_receipt']['quality_status']);
        self::assertSame('ready',$record['analysis_quality_receipt']['status']);
        $answer=$record['operating_question'];$answer['answer']['precise_result']['value']=999;
        $receipt=(new \app\service\HotelDataAnalystQualityReceiptService())->evaluate($answer);
        self::assertContains('precise_period_recalculation_mismatch',$receipt['reason_codes']);
        self::assertSame('failed',$receipt['quality_status']);
    }
}
