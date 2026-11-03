<?php
declare(strict_types=1);
namespace Tests;
use app\service\operation\ExecutionOutcomeService;
use app\service\operation\ExecutionFlowReadService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;

final class OperationExecutionOutcomeFiniteTest extends TestCase
{
    private function input(mixed $before=1000,mixed $after=1300,mixed $delta=300,array $target=[],string $metric='revenue'): array
    {
        $i=['id'=>4,'tenant_id'=>10,'hotel_id'=>20,'platform'=>'meituan','object_type'=>'campaign','status'=>'approved','source_kind'=>'local_synthetic_fixture_only',
            'date_start'=>'2026-08-29','date_end'=>'2026-08-29','expected_metric'=>$metric,'expected_delta'=>$delta,'target_value'=>['expected_delta_status'=>'quantified']+$target];
        $t=['id'=>1,'intent_id'=>4,'hotel_id'=>20,'tenant_id'=>10,'status'=>'executed','result_status'=>'success'];
        $e=['id'=>10,'task_id'=>1,'tenant_id'=>10,'created_by'=>0,'evidence_type'=>'source_verified_metric_readback','before'=>[$metric=>$before],
            'after'=>[$metric=>$after,'cost'=>200],'platform_response'=>['verification_authority'=>'system_readback','source'=>'local_synthetic_fixture_only','source_ref'=>'private_synthetic_metrics#4',
                'system_hotel_id'=>20,'platform'=>'meituan','object_type'=>'campaign','date_start'=>'2026-08-29','date_end'=>'2026-08-29','metric_key'=>$metric,
                'database_written'=>true,'readback_verified'=>true,'readback_count'=>1,'readback_at'=>'2026-08-29 23:59:59','validation_status'=>'verified']];
        return [$i,$t,$e];
    }
    private function outcome(array $input): array {[$i,$t,$e]=$input;return(new ExecutionOutcomeService())->buildExecutionOutcomeTruth($i,$t,[$e]);}
    private function item(array $input): array {[$i,$t,$e]=$input;return(new ExecutionFlowReadService(new ExecutionOutcomeService()))->buildItem($i,[$t],[$e]);}
    private function failed(array $r,string $reason): void
    {
        self::assertSame('unverified',$r['status']);self::assertFalse($r['outcome_verified']);self::assertFalse($r['positive_outcome_verified']);
        self::assertSame($reason,$r['failure_reason']);self::assertNull($r['progress_rate']);
        foreach(['success','near_success']as$status)self::assertFalse((new ExecutionOutcomeService())->executionPositiveOutcomeAllowsStatus($r,$status));
        self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testFiniteDifferenceOverflowClosesPositiveQualificationAndPreservesScope(): void
    {
        $r=$this->outcome($this->input(-1e308,1e308,1));$this->failed($r,'outcome_calculation_overflow');self::assertNull($r['actual_delta']);self::assertNull($r['favorable_delta']);
        self::assertSame(-1e308,$r['before_value']);self::assertSame(1e308,$r['after_value']);self::assertTrue($r['source_verified']);self::assertSame('private_synthetic_metrics#4',$r['source_ref']);
        self::assertSame('outcome_calculation_overflow',$r['metric_failures']['actual_delta']);
    }
    public function testNonFiniteBeforeAndAfterInputsDoNotBecomeAdverseOrSuccessful(): void
    {
        foreach([INF,-INF,NAN,'1e309']as$bad)foreach([[ $bad,1300],[1000,$bad]]as$index=>[$before,$after]){
            $r=$this->outcome($this->input($before,$after));$this->failed($r,'outcome_input_non_finite');self::assertNull($r['actual_delta']);
            self::assertNull($r[$index===0?'before_value':'after_value']);
        }
    }
    public function testNonFiniteUsedDeltaAndAbsoluteTargetsFailWithoutZeroProgress(): void
    {
        foreach([INF,NAN,'1e309']as$bad){$r=$this->outcome($this->input(1000,1300,$bad));$this->failed($r,'outcome_input_non_finite');self::assertNull($r['expected_delta']);self::assertSame(300.0,$r['actual_delta']);
            $r=$this->outcome($this->input(1000,1300,300,['expected_target'=>$bad]));$this->failed($r,'outcome_input_non_finite');self::assertNull($r['target_value']);}
    }
    public function testAbsoluteRequiredDifferenceOverflowIsUnknownRatherThanMissedAtZero(): void
    {
        $r=$this->outcome($this->input(-1e308,5e307,300,['expected_target'=>1e308]));$this->failed($r,'outcome_calculation_overflow');
        self::assertSame(1.5e308,$r['actual_delta']);self::assertSame(1e308,$r['target_value']);self::assertSame('outcome_calculation_overflow',$r['metric_failures']['target_delta']);
    }
    public function testAuxiliaryRateOverflowPreservesFiniteMetAndNearComparisons(): void
    {
        $r=$this->outcome($this->input(1e-308,1e308,1e308));self::assertSame('met',$r['status']);self::assertTrue($r['outcome_verified']);self::assertNull($r['actual_change_rate']);self::assertSame(100.0,$r['progress_rate']);
        self::assertSame('outcome_calculation_overflow',$r['metric_failures']['actual_change_rate']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
        $r=$this->outcome($this->input(0,1,1e-308));self::assertSame('met',$r['status']);self::assertNull($r['progress_rate']);self::assertTrue((new ExecutionOutcomeService())->executionPositiveOutcomeAllowsStatus($r,'success'));
        $r=$this->outcome($this->input(1e-308,1e308,1.4e308));self::assertSame('near',$r['status']);self::assertNull($r['actual_change_rate']);self::assertSame(71.4286,$r['progress_rate']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testUnusedDeltaCannotInvalidateFiniteAbsoluteConclusionOrBreakJson(): void
    {
        $r=$this->outcome($this->input(1000,1300,'1e309',['expected_target'=>1200]));self::assertSame('met',$r['status']);self::assertNull($r['expected_delta']);self::assertSame(1200.0,$r['target_value']);self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
    }
    public function testEarlyUnknownDirectionStillSanitizesUncalculableMetrics(): void
    {
        $r=$this->outcome($this->input(-1e308,1e308,300,[],'adr'));$this->failed($r,'outcome_calculation_overflow');self::assertNull($r['direction']);self::assertNull($r['actual_delta']);
        self::assertSame('expected_direction_unknown',$this->outcome($this->input(100,120,20,[],'adr'))['failure_reason']);
    }
    public function testFullItemAndSummaryEncodeAndRetainIndependentHealthyItemAndCounts(): void
    {
        foreach([$this->input(-1e308,1e308,1),$this->input(1000,1300,'1e309'),$this->input('1e309',1300)]as$input){$failed=$this->item($input);$healthy=$this->item($this->input());$healthy['id']=5;
            self::assertSame('unverified',$failed['review']['status']);self::assertSame('success',$failed['review']['reported_status']);self::assertSame('review',$failed['stage']);self::assertNull($failed['roi']['value']);
            self::assertSame(10,$failed['tenant_id']);self::assertSame(20,$failed['hotel_id']);self::assertSame('meituan',$failed['recommendation']['platform']);self::assertSame('2026-08-29',$failed['recommendation']['date_start']);
            $s=(new OperationManagementService())->buildExecutionFlowSummary([$failed,$healthy]);self::assertSame(2,$s['total']);self::assertSame(2,$s['approved']);self::assertSame(2,$s['executed']);self::assertSame(1,$s['roi_ready']);
            self::assertSame('ready',$healthy['roi']['status']);self::assertSame(50.0,$healthy['roi']['value']);self::assertNotEmpty(json_encode([$failed,$healthy,$s],JSON_THROW_ON_ERROR));}
    }
    public function testOriginalSourceScopeAndIdentityGatesRemainClosed(): void
    {
        foreach(['system_hotel_id'=>21,'platform'=>'ctrip','date_start'=>'2026-08-28','readback_verified'=>false,'validation_status'=>'failed']as$key=>$value){$in=$this->input();$in[2]['platform_response'][$key]=$value;
            $r=$this->outcome($in);self::assertFalse($r['source_verified']);self::assertSame('unverified',$r['status']);self::assertNull($r['actual_delta']);}
        foreach([['created_by',1],['evidence_type','manual_operation_execution']]as[$key,$value]){$in=$this->input();$in[2][$key]=$value;self::assertFalse($this->outcome($in)['source_verified']);}
        foreach([[1,'tenant_id',11],[1,'hotel_id',21],[1,'intent_id',5],[2,'tenant_id',11],[2,'task_id',2]]as[$row,$key,$value]){$in=$this->input();$in[$row][$key]=$value;$item=$this->item($in);self::assertSame('mismatch_excluded',$item['identity']['status']);self::assertFalse($item['outcome_truth']['source_verified']);self::assertNull($item['roi']['value']);}
    }
    public function testNormalZeroNearThresholdAndSameScopeCorrectionRetainOriginalSemantics(): void
    {
        self::assertSame('unverified',$this->item($this->input(-1e308,1e308,1))['review']['status']);$fixed=$this->item($this->input());self::assertSame('success',$fixed['review']['status']);self::assertSame(50.0,$fixed['roi']['value']);self::assertArrayNotHasKey('metric_failures',$fixed['outcome_truth']);
        self::assertSame('met',$this->outcome($this->input(0,0,0))['status']);self::assertSame(0.0,$this->outcome($this->input(0,0,0))['actual_delta']);
        foreach([[0.000007,0.00001,'near'],[0.000006,0.00001,'missed'],[0.000001,0.000001,'met'],[0.0000007,0.000001,'met']]as[$after,$target,$status])self::assertSame($status,$this->outcome($this->input(0,$after,$target))['status']);
        $missing=$this->outcome($this->input(null,1300));self::assertSame('source_verified_metric_readback_missing',$missing['failure_reason']);self::assertNull($missing['actual_delta']);self::assertNotEmpty(json_encode($missing,JSON_THROW_ON_ERROR));
    }
}
