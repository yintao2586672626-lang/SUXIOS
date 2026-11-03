<?php
declare(strict_types=1);
namespace Tests;
use app\service\OperationManagementService;
use app\service\operation\OperationEffectReviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\facade\Db;

/** local_synthetic_fixture_only: original services, disposable SQLite, no application configuration. */
final class OperationTerminalCostReassessmentTest extends TestCase
{
    private object $fixture;
    private ReflectionClass $reflection;
    private array $old;
    protected function setUp(): void
    {
        require_once __DIR__.'/OperationFrozenEffectRoiCostTest.php';
        $this->reflection=new ReflectionClass(OperationFrozenEffectRoiCostTest::class);
        $this->fixture=$this->reflection->newInstanceWithoutConstructor();
        $this->reflection->getMethod('setUp')->invoke($this->fixture);
        $this->old=$this->reflection->getMethod('save')->invoke($this->fixture);
        $this->source(['revenue'=>1300,'cost'=>400]);
    }
    protected function tearDown(): void { $this->reflection->getMethod('tearDown')->invoke($this->fixture); }
    private function source(array $after): void { Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode($after,JSON_THROW_ON_ERROR)]); }
    private function item(): array { return (new OperationManagementService())->executionFlow([7],7,['intent_id'=>1])['list'][0]; }
    private function input(): array
    {
        return ['refresh_roi_cost_review'=>true,'effect_review_id'=>$this->old['id'],
            'effect_review_content_digest'=>$this->old['content_digest'],
            'roi_cost_basis'=>$this->item()['roi']['effect_review_binding']['cost_reassessment']['roi_cost_basis']];
    }
    private function unchangedRows(): array
    {
        $r=[];foreach(['operation_execution_intents','operation_execution_tasks','operation_execution_evidence','operation_effect_reviews'] as $t)$r[$t]=Db::name($t)->order('id')->select()->toArray();return $r;
    }
    private function reject(array $input, array $hotels=[7], int $user=3): void
    {
        $before=$this->unchangedRows();$failure=null;
        try { (new OperationManagementService())->reviewExecutionTask(1,$hotels,$input,$user); } catch (\Throwable $e) { $failure=$e; }
        self::assertNotNull($failure);self::assertSame($before,$this->unchangedRows());self::assertSame(1,Db::name('operation_effect_reviews')->count());
    }
    public function testExplicitPublicSaveDuplicateExactReadAndRefreshKeepTerminalAndHistory(): void
    {
        $input=$this->input();$before=$this->unchangedRows();$item=$this->item();
        self::assertTrue($item['roi']['effect_review_binding']['cost_reassessment']['enabled']);
        self::assertNull($item['roi']['value']);
        $s=new OperationManagementService();$saved=$s->reviewExecutionTask(1,[7],$input,3);
        $again=$s->reviewExecutionTask(1,[7],$input,3);
        self::assertTrue($saved['roi_cost_review_save']['created']);self::assertFalse($again['roi_cost_review_save']['created']);
        self::assertSame($saved['active_effect_review'],$again['active_effect_review']);self::assertSame(2,Db::name('operation_effect_reviews')->count());
        self::assertSame($saved['active_effect_review'],$s->readExecutionTask(1,[7])['active_effect_review']);
        $review=$saved['active_effect_review'];self::assertNotSame($this->old['id'],$review['id']);
        self::assertSame($this->old,(new OperationEffectReviewService())->readVerified($this->old['id'],42,7,1,1));
        foreach(['operation_execution_intents','operation_execution_tasks','operation_execution_evidence'] as $t)self::assertSame($before[$t],$this->unchangedRows()[$t]);
        $i=$this->item();self::assertSame('ready',$i['roi']['status']);self::assertSame(-25.0,$i['roi']['value']);
        self::assertSame(400.0,$i['roi']['cost']);self::assertSame(-100.0,$i['roi']['profit']);self::assertSame(300.0,$i['roi']['incremental_revenue']);
        self::assertSame($review['id'],$i['roi']['effect_review_binding']['effect_review_id']);
        self::assertFalse($i['roi']['effect_review_binding']['cost_reassessment']['enabled']);
        self::assertSame($i,$this->item());self::assertNotEmpty(json_encode([$saved,$again,$i],JSON_THROW_ON_ERROR));
    }
    public function testExistingTerminalReviewIsStillIdempotentWithoutExpenseSave(): void
    {
        $task=Db::name('operation_execution_tasks')->where('id',1)->find();$before=$this->unchangedRows();
        $r=(new OperationManagementService())->reviewExecutionTask(1,[7],['result_status'=>$task['result_status'],'result_summary'=>$task['result_summary']],3);
        self::assertSame($this->old['id'],$r['active_effect_review']['id']);self::assertSame($before,$this->unchangedRows());
        $this->reject(['result_status'=>'success','result_summary'=>'replace terminal']);
    }
    public function testReturningToHistoricalCostCreatesAnExplicitVersionAndOldRequestsFailClosed(): void
    {
        $s=new OperationManagementService();$firstInput=$this->input();$first=$s->reviewExecutionTask(1,[7],$firstInput,3)['active_effect_review'];
        $this->source(['revenue'=>1300,'cost'=>600]);$i=$this->item();$binding=$i['roi']['effect_review_binding'];
        $secondInput=['refresh_roi_cost_review'=>true,'effect_review_id'=>$binding['effect_review_id'],
            'effect_review_content_digest'=>$binding['effect_review_content_digest'],'roi_cost_basis'=>$binding['cost_reassessment']['roi_cost_basis']];
        $second=$s->reviewExecutionTask(1,[7],$secondInput,3)['active_effect_review'];
        $this->source(['revenue'=>1300,'cost'=>400]);$i=$this->item();$binding=$i['roi']['effect_review_binding'];
        $input=['refresh_roi_cost_review'=>true,'effect_review_id'=>$binding['effect_review_id'],
            'effect_review_content_digest'=>$binding['effect_review_content_digest'],'roi_cost_basis'=>$binding['cost_reassessment']['roi_cost_basis']];
        $third=$s->reviewExecutionTask(1,[7],$input,3)['active_effect_review'];
        self::assertNotSame($first['id'],$third['id']);self::assertSame($second['id'],$third['outcome']['roi_cost_reassessment_of']);
        self::assertSame(4,Db::name('operation_effect_reviews')->count());
        self::assertSame($third['id'],$s->readExecutionTask(1,[7])['active_effect_review']['id']);
        $before=$this->unchangedRows();try{$s->reviewExecutionTask(1,[7],$firstInput,3);self::fail('stale review save accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('latest expense review changed',$e->getMessage());}
        self::assertSame($before,$this->unchangedRows());
        // Delayed retries still reuse the exact child even though reviewed_at participates in the original digest.
        usleep(1100000);$again=$s->reviewExecutionTask(1,[7],$input,3);self::assertFalse($again['roi_cost_review_save']['created']);self::assertSame($third,$again['active_effect_review']);
    }
    public function testDisplayedExpenseDriftFailsAndSameScopeCorrectionRecovers(): void
    {
        $input=$this->input();$this->source(['revenue'=>1300,'cost'=>450]);$this->reject($input);
        $this->source(['revenue'=>1300,'cost'=>400]);$r=(new OperationManagementService())->reviewExecutionTask(1,[7],$input,3);
        self::assertTrue($r['roi_cost_review_save']['created']);self::assertSame(400.0,$r['active_effect_review']['outcome']['roi_cost_basis']['value']);
    }
    public function testMissingNonfiniteAndBudgetExpenseCannotBeSaved(): void
    {
        foreach([['revenue'=>1300],['revenue'=>1300,'cost'=>'1e999'],['revenue'=>1300,'budget'=>400]] as $a){$this->source($a);self::assertFalse($this->item()['roi']['effect_review_binding']['cost_reassessment']['enabled']);$this->reject($this->input());}
    }
    public function testTamperedInputReviewIdentityAndWrongHotelOrReviewerFailClosed(): void
    {
        $input=$this->input();foreach(['effect_review_id'=>999,'effect_review_content_digest'=>str_repeat('a',64),'roi_cost_basis'=>['value'=>400]] as $k=>$v)$this->reject(array_replace($input,[$k=>$v]));
        $this->reject($input,[8]);$this->reject($input,[7],0);
    }
    public function testRevenueSourceDriftAndSourceTenantCannotFreezeNewCost(): void
    {
        $input=$this->input();$this->source(['revenue'=>1400,'cost'=>400]);$this->reject($input);
        $this->source(['revenue'=>1300,'cost'=>400]);Db::name('operation_execution_evidence')->where('id',1)->update(['tenant_id'=>43]);$this->reject($input);
    }
    public function testOriginalInsertFailureRollsBackWithoutTaskOrEvidenceChanges(): void
    {
        $input=$this->input();Db::execute("CREATE TRIGGER reject_cost_review BEFORE INSERT ON operation_effect_reviews BEGIN SELECT RAISE(ABORT, 'private_cost_insert_failure'); END");$this->reject($input);
        Db::execute('DROP TRIGGER reject_cost_review');$r=(new OperationManagementService())->reviewExecutionTask(1,[7],$input,3);self::assertTrue($r['roi_cost_review_save']['created']);
    }
    public function testDirectEffectServiceCostLineageCannotReplaceFrozenRevenueOrUseBudget(): void
    {
        $fields=['tenant_id','hotel_id','intent_id','task_id','platform','metric_key','baseline_business_date',
            'review_business_date','source_readback_evidence_id','result_status','result_summary','reviewed_at'];
        $input=array_intersect_key($this->old,array_flip($fields));$input['roi_cost_reassessment_of']=$this->old['id'];$input['causality_claimed']=false;
        foreach([['revenue'=>1400,'cost'=>400],['revenue'=>1300,'budget'=>400]] as $after){
            $this->source($after);$before=$this->unchangedRows();$failed=false;
            try{(new OperationEffectReviewService())->create(42,7,1,1,$input,3);}catch(\InvalidArgumentException){$failed=true;}
            self::assertTrue($failed);self::assertSame($before,$this->unchangedRows());
        }
    }
}
