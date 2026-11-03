<?php
declare(strict_types=1);
namespace Tests;
use app\service\operation\ExecutionOutcomeService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;

final class OperationExecutionRoiFiniteTest extends TestCase
{
    private function roi(mixed $before, mixed $after, mixed $cost = 200, string $type = 'campaign', array $truth = ['source_verified'=>true]): array
    {
        $evidence=['before'=>['revenue'=>$before],'after'=>['revenue'=>$after],'platform_response'=>[]];
        if($cost!==null)$evidence['after']['cost']=$cost;
        return (new ExecutionOutcomeService())->buildExecutionRoi(['object_type'=>$type],['result_status'=>'success'],
            $evidence,$truth+['source_ref'=>'private_synthetic_metrics#4','source_kind'=>'local_synthetic_fixture_only'],['status'=>'met']);
    }
    public function testFinitePercentageOverflowIsUnknownWhileIndependentAmountsRemain(): void
    {
        $r=$this->roi(0,1e308,1e-10);self::assertSame('partial',$r['status']);self::assertNull($r['value']);
        self::assertSame('roi_calculation_overflow',$r['failure_reason']);self::assertSame('roi_calculation_overflow',$r['metric_failures']['value']);
        self::assertSame(1e308,$r['incremental_revenue']);self::assertSame(1e308,$r['profit']);
        self::assertSame(0.0,$r['cost']);self::assertSame('private_synthetic_metrics#4',$r['evidence_truth']['source_ref']);
        self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testProfitOverflowDoesNotDestroyFiniteRevenueAndCost(): void
    {
        $r=$this->roi(1e308,0,1e308);self::assertNull($r['profit']);self::assertNull($r['value']);
        self::assertSame(-1e308,$r['incremental_revenue']);self::assertSame(1e308,$r['cost']);
        self::assertSame('roi_calculation_overflow',$r['metric_failures']['profit']);self::assertSame('partial',$r['status']);
        self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testDifferenceOverflowEncodesForPriceCostMissingAndNonPositiveBranches(): void
    {
        foreach([['price',null],['price',0],['price',-1],['campaign',null],['campaign',0]]as[$type,$cost]){
            $r=$this->roi(-1e308,1e308,$cost,$type);self::assertSame('partial',$r['status']);self::assertNull($r['incremental_revenue']);
            self::assertNull($r['value']);self::assertSame(-1e308,$r['before_revenue']);self::assertSame(1e308,$r['after_revenue']);
            self::assertSame('roi_calculation_overflow',$r['metric_failures']['incremental_revenue']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
        }
    }
    public function testNonFiniteNumericInputAndAliasRemainExplicitlyFailed(): void
    {
        foreach([INF,NAN,'1e309']as$bad){$r=$this->roi($bad,1300,200);self::assertSame('partial',$r['status']);self::assertNull($r['before_revenue']);
            self::assertSame(1300.0,$r['after_revenue']);self::assertSame(200.0,$r['cost']);self::assertSame('roi_input_non_finite',$r['metric_failures']['before_revenue']);
            self::assertNull($r['profit']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));}
        $r=$this->roi(1000,1300,'1e309');self::assertNull($r['cost']);self::assertSame(300.0,$r['incremental_revenue']);self::assertSame('roi_input_non_finite',$r['failure_reason']);
    }
    public function testMissingRevenuePreservesKnownInputWithoutFabricatingCalculation(): void
    {
        $r=$this->roi(null,1300,200);self::assertSame('revenue_evidence_missing',$r['failure_reason']);self::assertNull($r['before_revenue']);
        self::assertSame(1300.0,$r['after_revenue']);self::assertSame(200.0,$r['cost']);self::assertNull($r['incremental_revenue']);self::assertNull($r['profit']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testPriceWithoutCostKeepsFiniteLiftAndUnknownProfit(): void
    {
        $r=$this->roi(1000,1300,null,'price');self::assertSame('ready',$r['status']);self::assertSame('amount',$r['unit']);self::assertSame(300.0,$r['value']);self::assertNull($r['profit']);self::assertNull($r['cost']);
    }
    public function testNormalZeroAndSameScopeCorrectionRecoverWithoutPreviousFailure(): void
    {
        $failed=$this->roi(0,1e308,1e-10);$fixed=$this->roi('1000','1300','200');self::assertNull($failed['value']);self::assertSame('ready',$fixed['status']);
        self::assertSame(50.0,$fixed['value']);self::assertSame(100.0,$fixed['profit']);self::assertArrayNotHasKey('metric_failures',$fixed);self::assertNull($fixed['failure_reason']);
        $zero=$this->roi(1000,1000,0,'price');self::assertSame(0.0,$zero['value']);self::assertSame(0.0,$zero['profit']);self::assertSame('ready',$zero['status']);
    }
    public function testSourceOutcomeAndReviewGatesStillExcludeUnverifiedMetrics(): void
    {
        $r=$this->roi(0,1e308,1e-10,'campaign',['source_verified'=>false]);self::assertSame('unverified',$r['status']);self::assertNull($r['after_revenue']);self::assertNull($r['value']);
        foreach([['observing','met'],['failed','met'],['success','unverified']]as[$review,$outcome]){
            $r=(new ExecutionOutcomeService())->buildExecutionRoi(['object_type'=>'campaign'],['result_status'=>$review],['before'=>['revenue'=>1000],'after'=>['revenue'=>1300,'cost'=>200]],['source_verified'=>true],['status'=>$outcome]);
            self::assertSame('partial',$r['status']);self::assertNull($r['value']);self::assertNull($r['profit']);}
    }
    public function testSummaryRetainsFailedItemInsteadOfPresentingHealthySubsetAsFullSuccess(): void
    {
        $make=static fn(int$id,array$r):array=>['id'=>$id,'tenant_id'=>10,'hotel_id'=>20,'recommendation'=>['platform'=>'meituan','date_start'=>'2026-08-29'],
            'source_kind'=>'local_synthetic_fixture_only','stage'=>'reviewed','approval'=>['status'=>'approved'],'execution'=>['status'=>'executed'],
            'evidence_truth'=>['source_verified'=>true,'source_ref'=>'private_synthetic_metrics#'.$id],'roi'=>$r];
        $s=(new OperationManagementService())->buildExecutionFlowSummary([$make(4,$this->roi(1e308,0,1e308)),$make(5,$this->roi(1000,1300,200))]);
        self::assertSame(2,$s['total']);self::assertSame(2,$s['executed']);self::assertSame(2,$s['evidence_ready']);self::assertSame(1,$s['roi_ready']);
        self::assertNull($s['total_profit']);self::assertNull($s['avg_roi']);self::assertSame('profit_unverified',$s['money_status']);
        self::assertSame('aggregate_item_calculation_failed',$s['financial_metrics']['total_profit']['failure_reason']);
        self::assertSame([4,5],$s['financial_metrics']['total_profit']['item_ids']);self::assertSame('roi_calculation_overflow',$s['financial_metrics']['total_profit']['item_failures']['4']);
        self::assertSame(1e308,$s['total_cost']);self::assertSame('meituan',$s['financial_metrics']['total_profit']['scopes'][0]['platform']);self::assertNotEmpty(json_encode($s,JSON_THROW_ON_ERROR));
    }
    public function testPartialPriceRevenueDoesNotContaminateCampaignPercentagePool(): void
    {
        $make=static fn(int$id,array$r):array=>['id'=>$id,'tenant_id'=>10,'hotel_id'=>20,'stage'=>'reviewed',
            'recommendation'=>['platform'=>'meituan','date_start'=>'2026-08-29'],
            'evidence_truth'=>['source_verified'=>true,'source_ref'=>'private_synthetic_metrics#'.$id],'roi'=>$r];
        $price=$this->roi(null,1300,null,'price');self::assertSame('amount',$price['unit']);
        $s=(new OperationManagementService())->buildExecutionFlowSummary([$make(4,$price),$make(5,$this->roi(1000,1300,200))]);
        self::assertSame(50.0,$s['avg_roi']);self::assertNull($s['avg_revenue_lift']);self::assertSame(1,$s['roi_ready']);
        self::assertSame([5],$s['financial_metrics']['avg_roi']['item_ids']);self::assertSame([4],$s['financial_metrics']['avg_revenue_lift']['item_ids']);
        self::assertSame('aggregate_input_missing',$s['financial_metrics']['avg_revenue_lift']['failure_reason']);
        foreach([0,-1]as$cost)self::assertSame('amount',$this->roi(null,1300,$cost,'price')['unit']);
        self::assertSame('%',$this->roi(null,1300,200,'price')['unit']);
        $unknown=$price;$unknown['unit']=null;
        $s=(new OperationManagementService())->buildExecutionFlowSummary([$make(4,$unknown),$make(5,$this->roi(1000,1300,200))]);
        self::assertSame(50.0,$s['avg_roi']);self::assertSame([5],$s['financial_metrics']['avg_roi']['item_ids']);self::assertNull($s['total_profit']);
    }
    public function testFinancialPartialOnlyDoesNotClaimMissingSourceEvidenceOrReadyRoi(): void
    {
        $s=(new OperationManagementService())->buildExecutionFlowSummary([['id'=>4,'evidence_truth'=>['source_verified'=>true],
            'roi'=>$this->roi(0,1e308,1e-10)]]);
        self::assertSame(1,$s['evidence_ready']);self::assertSame(0,$s['roi_ready']);self::assertSame(1e308,$s['total_profit']);
        self::assertSame('profit_unverified',$s['money_status']);self::assertSame('calculation_failed',$s['financial_metrics']['avg_roi']['status']);
    }
}
