<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationManagementService;
use app\service\operation\OperationEffectReviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\facade\Config;
use think\facade\Db;

/** local_synthetic_fixture_only; original services with an entirely private SQLite database. */
final class OperationFrozenEffectRoiSourceTest extends TestCase
{
    private object $fixture;
    private ReflectionClass $fixtureClass;
    protected function setUp(): void
    {
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-frozen-roi-'.getmypid().'/cache/']]],'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-frozen-roi-'.getmypid().'/log/']]],'log');
        Config::set(['default'=>'sqlite','connections'=>['sqlite'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false]]],'database');
        Db::connect(null,true);require_once __DIR__.'/OperationEffectReviewServiceTest.php';
        $this->fixtureClass=new ReflectionClass(OperationEffectReviewServiceTest::class);
        $this->fixture=$this->fixtureClass->newInstanceWithoutConstructor();
        $this->fixtureClass->getMethod('createSchema')->invoke(null);
        $this->fixtureClass->getMethod('seedApprovedExecution')->invoke($this->fixture);
    }
    protected function tearDown(): void { Db::connect()->close(); }
    private function saveRevenueReview(): array
    {
        // This existing acceptance helper uses original create, duplicate save and exact readVerified.
        $this->fixtureClass->getMethod('testDecimalEffectBoundaryPersistsAndExactlyReadsBack')->invoke($this->fixture,1000.0,1300.0,300.0,'increase','delta','met');
        return (new OperationEffectReviewService())->readVerified(1,42,7,1,1);
    }
    private function rows(): array
    {
        $rows=[];foreach(['operation_execution_intents','operation_execution_tasks','operation_execution_evidence','operation_effect_reviews']as$t)$rows[$t]=Db::name($t)->order('id')->select()->toArray();return $rows;
    }
    private function read(array $frozen): array
    {
        $before=$this->rows();$service=new OperationManagementService();
        $flow=$service->executionFlow([7],7,['intent_id'=>1]);$detail=$service->readExecutionTask(1,[7]);
        self::assertSame($before,$this->rows());self::assertSame(1,$flow['returned_count']);
        $item=$flow['list'][0];self::assertSame(1,$item['id']);self::assertSame(1,$item['execution']['task_id']);
        self::assertSame(42,$item['tenant_id']);self::assertSame(7,$item['hotel_id']);
        self::assertSame($frozen,(new OperationEffectReviewService())->readVerified(1,42,7,1,1));
        self::assertSame($frozen,$detail['active_effect_review']);self::assertSame($frozen['outcome'],$item['outcome_truth']);
        self::assertSame($detail['outcome_truth'],$item['outcome_truth']);
        self::assertTrue($flow['statistics']['task_status_loaded']);self::assertTrue($flow['statistics']['evidence_loaded']);
        self::assertSame(1,$item['roi']['effect_review_binding']['effect_review_id']);
        self::assertSame(1,$item['roi']['effect_review_binding']['source_readback_evidence_id']);
        self::assertSame($frozen['content_digest'],$item['roi']['effect_review_binding']['effect_review_content_digest']);
        self::assertNotEmpty(json_encode([$flow,$detail],JSON_THROW_ON_ERROR));return [$item,$flow];
    }
    private function unavailable(array $item,string $reason): void
    {
        self::assertSame('unverified',$item['roi']['status']);self::assertSame($reason,$item['roi']['failure_reason']);
        self::assertSame($reason,$item['roi']['evidence_truth']['failure_reason']);
        foreach(['value','before_revenue','after_revenue','incremental_revenue','cost','profit']as$key)self::assertNull($item['roi'][$key]);
        self::assertFalse($item['roi']['effect_review_binding']['source_binding_verified']);
        self::assertContains($item['stage'],['review','evidence']);
        self::assertSame('refresh_readback',$item['next_action']['key']);
    }
    public function testCorrectionAndReadOnlyRestorationDoNotBlendFrozenAndCurrentRevenue(): void
    {
        $frozen=$this->saveRevenueReview();[$initial]=$this->read($frozen);self::assertSame(300.0,$initial['roi']['incremental_revenue']);
        $source=Db::name('operation_execution_evidence')->where('id',1)->find();
        Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1800],JSON_THROW_ON_ERROR)]);
        [$failed,$flow]=$this->read($frozen);$this->unavailable($failed,'frozen_effect_roi_source_changed');
        self::assertNull($flow['summary']['total_incremental_revenue']);self::assertSame(0,$flow['summary']['stage_counts']['reviewed']);self::assertSame(1,$failed['evidence']['count']);
        Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>$source['after_json']]);
        [$restored]=$this->read($frozen);self::assertSame('ready',$restored['roi']['status']);self::assertSame(300.0,$restored['roi']['incremental_revenue']);
        self::assertTrue($restored['roi']['effect_review_binding']['source_binding_verified']);
    }
    public function testNewerRevenueOrForeignTenantCannotReplaceFrozenSourceId(): void
    {
        $frozen=$this->saveRevenueReview();$source=Db::name('operation_execution_evidence')->where('id',1)->find();
        foreach([42,99]as$tenant){$source['id']=$tenant===42?2:3;$source['tenant_id']=$tenant;$source['after_json']=json_encode(['revenue'=>1800],JSON_THROW_ON_ERROR);Db::name('operation_execution_evidence')->insert($source);}
        [$item]=$this->read($frozen);self::assertSame('ready',$item['roi']['status']);self::assertSame(300.0,$item['roi']['incremental_revenue']);
        self::assertSame(2,$item['evidence']['count']);self::assertSame(2,$item['evidence']['latest']['id']);
        Db::name('operation_execution_evidence')->where('id',1)->delete();
        [$missing]=$this->read($frozen);$this->unavailable($missing,'frozen_effect_roi_source_missing');self::assertSame(1,$missing['evidence']['count']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceChanges')]
    public function testSourceDatesReferencesScopeAndValidationMustStillMatch(string $key,mixed $value): void
    {
        $frozen=$this->saveRevenueReview();$source=Db::name('operation_execution_evidence')->where('id',1)->find();
        $context=json_decode($source['platform_response_json'],true,512,JSON_THROW_ON_ERROR);$context[$key]=$value;
        Db::name('operation_execution_evidence')->where('id',1)->update(['platform_response_json'=>json_encode($context,JSON_THROW_ON_ERROR)]);
        [$item]=$this->read($frozen);$this->unavailable($item,'frozen_effect_roi_source_changed');
    }
    public static function sourceChanges(): array
    {
        return [['baseline_date','2026-08-09'],['review_date','2026-08-10'],['followup_source_ref','online_daily_data#999'],['baseline_source_ref','online_daily_data#999'],['platform','meituan'],['system_hotel_id',8],['tenant_id',99],['readback_verified',false]];
    }
    public function testOrderOnlyFrozenReviewCannotVerifyRevenueRoi(): void
    {
        Db::name('operation_execution_evidence')->where('id',1)->update(['before_json'=>json_encode(['orders'=>10,'revenue'=>1000],JSON_THROW_ON_ERROR),'after_json'=>json_encode(['orders'=>13,'revenue'=>1300],JSON_THROW_ON_ERROR)]);
        $input=$this->fixtureClass->getMethod('effectInput')->invoke($this->fixture);
        $frozen=(new OperationEffectReviewService())->create(42,7,1,1,$input,3)['review'];
        [$item]=$this->read($frozen);$this->unavailable($item,'frozen_effect_roi_revenue_not_reviewed');self::assertSame('3.000000',$item['outcome_truth']['actual_delta']);
    }
}
