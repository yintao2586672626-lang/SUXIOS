<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationManagementService;
use app\service\operation\ExecutionOutcomeService;
use app\service\operation\OperationEffectReviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\facade\Config;
use think\facade\Db;

/** local_synthetic_fixture_only: no application database or credentials loaded. */
final class OperationFrozenEffectRoiCostTest extends TestCase
{
    private object $fixture;
    private ReflectionClass $fixtureClass;
    protected function setUp(): void
    {
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-frozen-cost-'.getmypid().'/cache/']]],'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-frozen-cost-'.getmypid().'/log/']]],'log');
        Config::set(['default'=>'sqlite','connections'=>['sqlite'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false]]],'database');
        Db::connect(null,true); require_once __DIR__.'/OperationEffectReviewServiceTest.php';
        $this->fixtureClass = new ReflectionClass(OperationEffectReviewServiceTest::class);
        $this->fixture = $this->fixtureClass->newInstanceWithoutConstructor();
        $this->fixtureClass->getMethod('createSchema')->invoke(null);
        $this->fixtureClass->getMethod('seedApprovedExecution')->invoke($this->fixture);
    }
    protected function tearDown(): void { Db::connect()->close(); }
    private function input(): array
    {
        return array_replace($this->fixtureClass->getMethod('effectInput')->invoke($this->fixture), ['metric_key'=>'revenue']);
    }
    private function save(array $cost=['cost'=>200], array $contextCost=[]): array
    {
        $this->fixtureClass->getMethod('testDecimalEffectBoundaryPersistsAndExactlyReadsBack')->invoke($this->fixture,1000.0,1300.0,300.0,'increase','delta','met');
        // Disposable seed only; the following production save sees expenses before persistence.
        Db::name('operation_effect_reviews')->delete(true);
        $source = Db::name('operation_execution_evidence')->where('id',1)->find();
        $context = array_replace(json_decode($source['platform_response_json'],true,512,JSON_THROW_ON_ERROR),$contextCost);
        Db::name('operation_execution_evidence')->where('id',1)->update([
            'after_json'=>json_encode(['revenue'=>1300]+$cost,JSON_THROW_ON_ERROR),
            'platform_response_json'=>json_encode($context,JSON_THROW_ON_ERROR),
        ]);
        $service = new OperationEffectReviewService(); $first=$service->create(42,7,1,1,$this->input(),3);
        $again=$service->create(42,7,1,1,$this->input(),3);
        self::assertTrue($first['created']); self::assertFalse($again['created']);
        self::assertSame($first['review'],$again['review']);
        self::assertSame($first['review'],$service->readVerified($first['review']['id'],42,7,1,1));
        self::assertSame(1,(int)Db::name('operation_effect_reviews')->count());
        return $first['review'];
    }
    private function rows(): array
    {
        $rows=[]; foreach(['operation_execution_intents','operation_execution_tasks','operation_execution_evidence','operation_effect_reviews'] as $t) $rows[$t]=Db::name($t)->order('id')->select()->toArray(); return $rows;
    }
    private function read(array $frozen): array
    {
        $before=$this->rows();$service=new OperationManagementService();
        $flow=$service->executionFlow([7],7,['intent_id'=>1]);$detail=$service->readExecutionTask(1,[7]);
        self::assertSame($before,$this->rows());self::assertSame(1,$flow['returned_count']);
        self::assertSame($frozen,$detail['active_effect_review']);
        $item=$flow['list'][0]; self::assertSame($frozen['outcome'],$item['outcome_truth']);
        self::assertSame($frozen['outcome'],$detail['outcome_truth']);
        self::assertSame(42,$item['tenant_id']);self::assertSame(7,$item['hotel_id']);
        self::assertSame($frozen['id'],$item['roi']['effect_review_binding']['effect_review_id']);
        self::assertSame(1,$item['roi']['effect_review_binding']['source_readback_evidence_id']);
        self::assertSame(300.0,$item['roi']['incremental_revenue']);
        self::assertTrue($frozen['active_eligible']);self::assertTrue($item['roi']['effect_review_binding']['source_binding_verified']);
        self::assertNotEmpty(json_encode([$flow,$detail],JSON_THROW_ON_ERROR));return [$item,$flow];
    }
    private function costUnavailable(array $item,string $reason): void
    {
        self::assertSame('partial',$item['roi']['status']);self::assertSame($reason,$item['roi']['failure_reason']);
        foreach(['value','cost','profit','formula'] as $key) self::assertNull($item['roi'][$key]);
        self::assertFalse($item['roi']['effect_review_binding']['cost_binding_verified']);
        self::assertSame('refresh_readback',$item['next_action']['key']);self::assertSame('review',$item['stage']);
    }
    public function testSameSourceExpenseCorrectionAndReadOnlyRefreshRecovery(): void
    {
        $frozen=$this->save();[$initial]=$this->read($frozen);self::assertSame(50.0,$initial['roi']['value']);
        self::assertSame(200.0,$frozen['outcome']['roi_cost_basis']['value']);
        Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1300,'cost'=>400],JSON_THROW_ON_ERROR)]);
        [$failed,$flow]=$this->read($frozen);$this->costUnavailable($failed,'frozen_effect_roi_cost_changed');
        self::assertSame(300.0,$flow['summary']['total_incremental_revenue']);self::assertNull($flow['summary']['total_cost']);self::assertNull($flow['summary']['total_profit']);self::assertSame(0,$flow['summary']['roi_ready']);
        Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1300,'cost'=>200],JSON_THROW_ON_ERROR)]);
        [$restored]=$this->read($frozen);self::assertSame('ready',$restored['roi']['status']);self::assertSame(50.0,$restored['roi']['value']);self::assertSame(100.0,$restored['roi']['profit']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('expenseChanges')]
    public function testMissingAliasOrUncomputableExpenseCannotReplaceSavedBasis(array $cost): void
    {
        $frozen=$this->save();Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1300]+$cost,JSON_THROW_ON_ERROR)]);
        [$item]=$this->read($frozen);$this->costUnavailable($item,'frozen_effect_roi_cost_changed');
    }
    public static function expenseChanges(): array { return [[[]],[['ad_cost'=>200]],[['cost'=>'1e999']],[['cost'=>0]],[['cost'=>-1]]]; }
    public function testCostMissingAtSaveCannotBeFilledDuringReadback(): void
    {
        $frozen=$this->save([]);self::assertSame('missing',$frozen['roi_cost_validation_status']);
        [$before]=$this->read($frozen);self::assertNull($before['roi']['cost']);self::assertSame('amount',$before['roi']['unit']);self::assertFalse($before['roi']['effect_review_binding']['cost_binding_verified']);
        Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1300,'cost'=>200],JSON_THROW_ON_ERROR)]);
        [$item]=$this->read($frozen);$this->costUnavailable($item,'frozen_effect_roi_cost_changed');
    }
    public function testExplicitNewReviewFreezesCorrectedCostAndDuplicatesByItsNewDigest(): void
    {
        $old=$this->save();Db::name('operation_execution_evidence')->where('id',1)->update(['after_json'=>json_encode(['revenue'=>1300,'cost'=>400],JSON_THROW_ON_ERROR)]);
        $service=new OperationEffectReviewService();$new=$service->create(42,7,1,1,$this->input(),3);$again=$service->create(42,7,1,1,$this->input(),3);
        self::assertTrue($new['created']);self::assertFalse($again['created']);self::assertSame($new['review'],$again['review']);self::assertNotSame($old['content_digest'],$new['review']['content_digest']);
        self::assertSame($old,$service->readVerified($old['id'],42,7,1,1));self::assertSame(2,(int)Db::name('operation_effect_reviews')->count());
        [$item]=$this->read($new['review']);self::assertSame(-25.0,$item['roi']['value']);self::assertSame(-100.0,$item['roi']['profit']);
    }
    public function testNewSourceIdCannotReplaceTheFrozenExpense(): void
    {
        $frozen=$this->save();$source=Db::name('operation_execution_evidence')->where('id',1)->find();$source['id']=2;$source['after_json']=json_encode(['revenue'=>1300,'cost'=>400],JSON_THROW_ON_ERROR);Db::name('operation_execution_evidence')->insert($source);
        [$item]=$this->read($frozen);self::assertSame(50.0,$item['roi']['value']);self::assertSame(200.0,$item['roi']['cost']);self::assertSame(2,$item['evidence']['count']);
    }
    public function testPlatformRootExpenseWinsWithoutNestedContextSubstitution(): void
    {
        $frozen=$this->save([],['ad_cost'=>200,'source_context'=>['ad_cost'=>400]]);
        self::assertSame('platform_response.ad_cost',$frozen['outcome']['roi_cost_basis']['source_path']);
        [$item]=$this->read($frozen);self::assertSame(50.0,$item['roi']['value']);
        $source=Db::name('operation_execution_evidence')->where('id',1)->find();$context=json_decode($source['platform_response_json'],true);$context['ad_cost']=400;Db::name('operation_execution_evidence')->where('id',1)->update(['platform_response_json'=>json_encode($context,JSON_THROW_ON_ERROR)]);
        [$failed]=$this->read($frozen);$this->costUnavailable($failed,'frozen_effect_roi_cost_changed');
    }
    public function testFiniteExpensePrecisionIsPreservedWithoutSixDecimalTruncation(): void
    {
        $frozen=$this->save(['cost'=>0.0000004]);self::assertSame(0.0000004,$frozen['outcome']['roi_cost_basis']['value']);
        [$item]=$this->read($frozen);self::assertSame('%',$item['roi']['unit']);self::assertSame(74999999900.0,$item['roi']['value']);
    }
    public function testInvalidAndBudgetCostDoNotInvalidateHistoricalRevenue(): void
    {
        $frozen=$this->save(['budget'=>200]);self::assertSame('plan_reference',$frozen['outcome']['roi_cost_basis']['measurement_kind']);
        [$item]=$this->read($frozen);$this->costUnavailable($item,'frozen_effect_roi_cost_unverified');
    }
    public function testNonFiniteExpenseCanPersistRevenueWithoutUnsafeJson(): void
    {
        $frozen=$this->save(['cost'=>'1e999']);self::assertSame('non_finite',$frozen['roi_cost_validation_status']);self::assertNull($frozen['outcome']['roi_cost_basis']['value']);
        [$item]=$this->read($frozen);$this->costUnavailable($item,'frozen_effect_roi_cost_unverified');
    }
    public function testExpenseTamperingFailsOriginalStrictContentReadback(): void
    {
        $frozen=$this->save();$outcome=$frozen['outcome'];$outcome['roi_cost_basis']['value']=400;
        Db::name('operation_effect_reviews')->where('id',$frozen['id'])->update(['outcome_json'=>json_encode($outcome,JSON_THROW_ON_ERROR)]);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('严格回读');(new OperationEffectReviewService())->readVerified($frozen['id'],42,7,1,1);
    }
    public function testLegacySnapshotRemainsHistoricalButDoesNotBorrowCurrentCosts(): void
    {
        $frozen=$this->save();$outcome=$frozen['outcome'];unset($outcome['roi_cost_basis']);
        $payload=array_intersect_key($frozen,array_flip(['tenant_id','hotel_id','intent_id','task_id','platform','baseline_business_date','review_business_date','metric_key','metric_definition','metric_definition_digest','approval_target_digest','before_value','after_value','expected_direction','target_type','target_value','expected_delta','expected_delta_status','target_confirmed_by','target_confirmed_at','baseline_refs','followup_refs','source_readback_evidence_id','outcome_status','outcome','result_status','result_summary','causality_claimed','reviewed_by','reviewed_at']));$payload['outcome']=$outcome;
        $canonical=$this->fixtureClass->getMethod('canonicalJson');
        Db::name('operation_effect_reviews')->where('id',$frozen['id'])->update(['outcome_json'=>$canonical->invoke(null,$outcome),'content_digest'=>hash('sha256',$canonical->invoke(null,$payload))]);
        $legacy=(new OperationEffectReviewService())->readVerified($frozen['id'],42,7,1,1);self::assertSame('legacy_cost_not_frozen',$legacy['roi_cost_validation_status']);self::assertArrayNotHasKey('roi_cost_basis',$legacy['outcome']);
        [$item]=$this->read($legacy);$this->costUnavailable($item,'frozen_effect_roi_cost_not_frozen');
    }
    public function testOriginalTargetFallbackAndKeyPriorityArePreservedAndClassified(): void
    {
        $service=new ExecutionOutcomeService();
        $a=$service->executionRoiCostBasis(['target_value'=>['cost'=>600]],['target_value'=>['budget'=>500]],['after'=>['ad_cost'=>200,'cost'=>100],'platform_response'=>['cost'=>300]]);
        self::assertSame(100.0,$a['value']);self::assertSame('after.cost',$a['source_path']);
        $a=$service->executionRoiCostBasis(['target_value'=>['cost'=>600]],['target_value'=>['other'=>1]],[]);self::assertSame('missing',$a['status']);
        $a=$service->executionRoiCostBasis(['target_value'=>['cost'=>600]],['target_value'=>[]],[]);self::assertSame('intent.target_value.cost',$a['source_path']);self::assertSame('plan_reference',$a['measurement_kind']);
        self::assertTrue($service->validExecutionRoiCostBasis($a));
    }
    public function testOriginalApprovalDriftContractRemainsUnchanged(): void
    {
        $this->fixtureClass->getMethod('testCurrentApprovalTargetDriftKeepsOldReviewHistoricalButInactive')->invoke($this->fixture);
    }
    public function testLegacyNegativeNonFiniteCostPreservesIndependentAmountCalculation(): void
    {
        $roi=(new ExecutionOutcomeService())->buildExecutionRoi(
            ['object_type'=>'price'], ['result_status'=>'success'],
            ['before'=>['revenue'=>1000],'after'=>['revenue'=>1300,'cost'=>'-1e999']],
            ['source_verified'=>true],
            ['source_verified'=>true,'outcome_verified'=>true,'positive_outcome_verified'=>true,'status'=>'met']
        );
        self::assertSame('partial',$roi['status']);self::assertSame('roi_input_non_finite',$roi['failure_reason']);
        self::assertSame(300.0,$roi['incremental_revenue']);self::assertSame(300.0,$roi['value']);
        self::assertNull($roi['cost']);self::assertNull($roi['profit']);self::assertNotEmpty(json_encode($roi,JSON_THROW_ON_ERROR));
    }
}
