<?php
declare(strict_types=1);
namespace Tests;
use app\service\OperationManagementService;
use app\service\operation\ExecutionOutcomeService;
use app\service\operation\ExecutionFlowReadService;
use PHPUnit\Framework\TestCase;

final class OperationExecutionSummaryFiniteTest extends TestCase
{
    private function item(int $id, array $roi): array
    {
        return ['id'=>$id,'tenant_id'=>10,'hotel_id'=>20,'platform'=>'meituan','date_start'=>'2026-08-29','date_end'=>'2026-08-29','source_kind'=>'local_synthetic_fixture_only',
            'stage'=>'reviewed','approval'=>['status'=>'approved'],'execution'=>['status'=>'executed','task_id'=>$id],
            'evidence_truth'=>['source_verified'=>true,'assessments'=>[['source_ref'=>'private_metrics#'.$id]]],
            'roi'=>$roi+['status'=>'ready','unit'=>'%','value'=>50.0,'incremental_revenue'=>300.0,'cost'=>200.0,'profit'=>100.0]];
    }
    private function summary(array $items): array { return (new OperationManagementService())->buildExecutionFlowSummary($items); }
    public function testOriginalFiniteRoiProducerCanOverflowOnlyAtAggregateAndNowEncodes(): void
    {
        $roi=(new ExecutionOutcomeService())->buildExecutionRoi(['object_type'=>'campaign'],['result_status'=>'success'],
            ['before'=>['revenue'=>0],'after'=>['revenue'=>1e308,'cost'=>1e307],'platform_response'=>[]],['source_verified'=>true],['status'=>'met']);
        foreach (['value','incremental_revenue','cost','profit'] as $key) self::assertTrue(is_finite($roi[$key]));
        $items=[$this->item(4,$roi),$this->item(5,$roi)];$s=$this->summary($items);
        self::assertNotEmpty(json_encode(['list'=>$items,'summary'=>$s],JSON_THROW_ON_ERROR));
        foreach (['total_incremental_revenue','total_profit'] as $key) {
            self::assertNull($s[$key]);self::assertSame('overflow',$s['financial_metrics'][$key]['status']);
            self::assertSame('aggregate_overflow',$s['financial_metrics'][$key]['failure_reason']);
            self::assertSame([4,5],$s['financial_metrics'][$key]['item_ids']);
            self::assertSame(['private_metrics#4','private_metrics#5'],$s['financial_metrics'][$key]['source_refs']);
        }
        self::assertSame(2e307,$s['total_cost']);self::assertSame(900.0,$s['avg_roi']);self::assertSame('profit_unverified',$s['money_status']);
        foreach (['total','approved','executed','evidence_ready','roi_ready','profitable'] as $key) self::assertSame(2,$s[$key]);
        self::assertSame(2,$s['stage_counts']['reviewed']);self::assertSame('meituan',$s['financial_metrics']['total_profit']['scopes'][0]['platform']);
    }
    public function testFiniteMeanSurvivesIntermediateSumOverflow(): void
    {
        foreach (['%','amount'] as $unit) {
            $s=$this->summary([$this->item(4,['unit'=>$unit,'value'=>1e308]),$this->item(5,['unit'=>$unit,'value'=>1e308])]);
            $key=$unit==='%'?'avg_roi':'avg_revenue_lift';self::assertSame(1e308,$s[$key]);self::assertSame('ready',$s['financial_metrics'][$key]['status']);
            self::assertSame(200.0,$s['total_profit']);self::assertNotEmpty(json_encode($s,JSON_THROW_ON_ERROR));
        }
    }
    public function testMissingValueNeverBecomesZeroOrPartialAverage(): void
    {
        $s=$this->summary([$this->item(4,[]),$this->item(5,['value'=>null,'profit'=>null])]);
        self::assertNull($s['avg_roi']);self::assertNull($s['total_profit']);self::assertSame('aggregate_input_missing',$s['financial_metrics']['avg_roi']['failure_reason']);
        self::assertSame(2,$s['roi_ready']);self::assertSame(600.0,$s['total_incremental_revenue']);self::assertSame(400.0,$s['total_cost']);self::assertSame('profit_unverified',$s['money_status']);
    }
    public function testNonFiniteInputsAreUnknownWithoutErasingOtherFields(): void
    {
        foreach ([INF,-INF,NAN,'1e309'] as $bad) {
            $s=$this->summary([$this->item(4,['value'=>$bad,'cost'=>$bad])]);
            self::assertNull($s['avg_roi']);self::assertNull($s['total_cost']);self::assertSame('aggregate_input_non_finite',$s['financial_metrics']['total_cost']['failure_reason']);
            self::assertSame(100.0,$s['total_profit']);self::assertSame('profit_positive',$s['money_status']);self::assertSame(1,$s['executed']);self::assertNotEmpty(json_encode($s,JSON_THROW_ON_ERROR));
        }
    }
    public function testExplicitZeroNumericStringsAndUnitsRemainCompatible(): void
    {
        $s=$this->summary([$this->item(4,['value'=>'0','incremental_revenue'=>'0','profit'=>'0','cost'=>'0']),$this->item(5,['unit'=>'amount','value'=>0.0,'incremental_revenue'=>0.0,'profit'=>0.0,'cost'=>0.0])]);
        foreach (['avg_roi','avg_revenue_lift','total_incremental_revenue','total_profit','total_cost'] as $key) self::assertSame(0.0,$s[$key]);
        self::assertSame(1,$s['roi_percent_ready']);self::assertSame(1,$s['revenue_lift_ready']);self::assertSame('break_even',$s['money_status']);
        $small=$this->summary([$this->item(4,['profit'=>0.004])]);
        self::assertSame(0.0,$small['total_profit']);self::assertSame('profit_positive',$small['money_status']);
    }
    public function testSourceUnverifiedAndEmptyStayExcludedRatherThanZero(): void
    {
        $item=$this->item(4,['value'=>1e308]);$item['evidence_truth']['source_verified']=false;
        foreach ([[],[$item]] as $items) {
            $s=$this->summary($items);self::assertSame(0,$s['roi_ready']);self::assertNull($s['total_profit']);self::assertNull($s['avg_roi']);self::assertSame('no_roi',$s['money_status']);
        }
    }
    public function testSameScopeCorrectionRestoresExactValuesAndProvenance(): void
    {
        $s=$this->summary([$this->item(4,['profit'=>1e308]),$this->item(5,['profit'=>1e308])]);self::assertNull($s['total_profit']);
        $restored=$this->summary([$this->item(4,[]),$this->item(5,[])]);self::assertSame(200.0,$restored['total_profit']);self::assertSame('profit_positive',$restored['money_status']);
        self::assertSame('ready',$restored['financial_metrics']['total_profit']['status']);self::assertNull($restored['financial_metrics']['total_profit']['failure_reason']);
        self::assertSame($s['financial_metrics']['total_profit']['scopes'],$restored['financial_metrics']['total_profit']['scopes']);
    }
    public function testSignedCancellationDoesNotFailOnlyBecauseIntermediateSumOverflowed(): void
    {
        $s=$this->summary([$this->item(4,['profit'=>1e308]),$this->item(5,['profit'=>1e308]),$this->item(6,['profit'=>-1e308])]);
        self::assertSame(1e308,$s['total_profit']);self::assertSame('ready',$s['financial_metrics']['total_profit']['status']);self::assertSame(2,$s['profitable']);
    }
    public function testOriginalItemShapeRetainsActualScopeAndDoesNotInventAbsentSource(): void
    {
        $reader=new ExecutionFlowReadService(new ExecutionOutcomeService());
        $item=$reader->buildItem(['id'=>4,'tenant_id'=>10,'hotel_id'=>20,'platform'=>'meituan','date_start'=>'2026-08-29','date_end'=>'2026-08-29','source_kind'=>'local_synthetic_fixture_only','status'=>'pending_approval'],[],[]);
        self::assertSame(10,$item['tenant_id']);self::assertSame('local_synthetic_fixture_only',$item['source_kind']);
        // Only financial eligibility is controlled here; the item and scope projection are original production.
        $item['evidence_truth']=['source_verified'=>true,'source_ref'=>'private_metrics#4'];$item['roi']=$this->item(4,[])['roi'];
        $scope=$reader->buildSummary([$item])['financial_metrics']['total_profit']['scopes'][0];
        self::assertSame(10,$scope['tenant_id']);self::assertSame(20,$scope['hotel_id']);self::assertSame('meituan',$scope['platform']);self::assertSame('2026-08-29',$scope['date_start']);self::assertSame('2026-08-29',$scope['date_end']);
        $unknown=$reader->buildItem(['id'=>5,'hotel_id'=>20,'status'=>'pending_approval'],[],[]);self::assertNull($unknown['tenant_id']);self::assertNull($unknown['source_kind']);
    }
    public function testOverflowCancellationPreservesSmallResidualRatherThanReadyZero(): void
    {
        $items=[];foreach ([1e308,1e308,1.0,-1e308,-1e308] as $i=>$v) $items[]=$this->item($i+4,['profit'=>$v,'value'=>$v]);
        $s=$this->summary($items);self::assertSame(1.0,$s['total_profit']);self::assertSame(0.2,$s['avg_roi']);self::assertSame('profit_positive',$s['money_status']);
    }
    public function testUnrepresentableScaledResidualIsUnknownRatherThanInventedZero(): void
    {
        $items=[];foreach ([1e308,1e308,1e-100,-1e308,-1e308] as $i=>$v) $items[]=$this->item($i+4,['profit'=>$v]);
        $s=$this->summary($items);self::assertNull($s['total_profit']);self::assertSame('aggregate_precision_loss',$s['financial_metrics']['total_profit']['failure_reason']);self::assertSame('profit_unverified',$s['money_status']);self::assertNotEmpty(json_encode($s,JSON_THROW_ON_ERROR));
    }
}
