<?php
declare(strict_types=1);
namespace Tests;

use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;
use PHPUnit\Framework\TestCase;

final class OperationExecutionTerminalReadbackTest extends TestCase
{
    private function input(): array
    {
        $i = ['id'=>4,'tenant_id'=>10,'hotel_id'=>20,'platform'=>'meituan','object_type'=>'campaign','status'=>'approved',
            'source_kind'=>'local_synthetic_fixture_only','date_start'=>'2026-08-29','date_end'=>'2026-08-29',
            'expected_metric'=>'revenue','expected_delta'=>300,'target_value'=>['expected_delta_status'=>'quantified']];
        $t = ['id'=>1,'intent_id'=>4,'hotel_id'=>20,'tenant_id'=>10,'status'=>'executed','result_status'=>'success','result_summary'=>'原已保存复盘'];
        $e = ['id'=>10,'task_id'=>1,'tenant_id'=>10,'created_by'=>0,'evidence_type'=>'source_verified_metric_readback',
            'before'=>['revenue'=>1000],'after'=>['revenue'=>1300,'cost'=>200],
            'platform_response'=>['verification_authority'=>'system_readback','source'=>'local_synthetic_fixture_only',
                'source_ref'=>'private_synthetic_metrics#4','system_hotel_id'=>20,'platform'=>'meituan','object_type'=>'campaign',
                'date_start'=>'2026-08-29','date_end'=>'2026-08-29','metric_key'=>'revenue','database_written'=>true,
                'readback_verified'=>true,'readback_count'=>1,'readback_at'=>'2026-08-29 23:59:59','validation_status'=>'verified']];
        return [$i,$t,$e];
    }
    private function item(array $in): array
    {
        [$i,$t,$e]=$in;
        return (new ExecutionFlowReadService(new ExecutionOutcomeService()))->buildItem($i,[$t],[$e]);
    }
    public function testTerminalPositiveFailureOffersReadOnlyRefreshAndKeepsSavedIdentity(): void
    {
        foreach (['success','near_success'] as $reported) {
            $in=$this->input();$in[1]['result_status']=$reported;$in[2]['before']['revenue']=-1e308;$in[2]['after']['revenue']=1e308;
            $snapshot=$in;$r=$this->item($in);
            self::assertSame($snapshot,$in);self::assertSame('unverified',$r['review']['status']);
            self::assertSame($reported,$r['review']['reported_status']);self::assertSame('原已保存复盘',$r['review']['summary']);
            self::assertSame(['key'=>'refresh_readback','label'=>'刷新核验结果','priority'=>'medium','target_id'=>1],$r['next_action']);
            self::assertNull($r['outcome_truth']['actual_delta']);self::assertSame('outcome_calculation_overflow',$r['review']['failure_reason']);
            self::assertSame(4,$r['id']);self::assertSame(20,$r['hotel_id']);self::assertSame(10,$r['tenant_id']);
            self::assertSame('meituan',$r['recommendation']['platform']);self::assertSame('2026-08-29',$r['recommendation']['date_start']);
            self::assertNotEmpty(json_encode($r,JSON_THROW_ON_ERROR));
        }
    }
    public function testMissingSourceStaysUnverifiedUntilSameScopeFactsAreAvailable(): void
    {
        foreach (['readback_verified'=>false,'validation_status'=>'failed','platform'=>'ctrip','system_hotel_id'=>21,'date_start'=>'2026-08-28'] as $key=>$v) {
            $in=$this->input();$in[2]['platform_response'][$key]=$v;$r=$this->item($in);
            self::assertSame('refresh_readback',$r['next_action']['key']);self::assertFalse($r['evidence_truth']['source_verified']);
            self::assertSame('unverified',$r['review']['status']);self::assertSame('success',$r['review']['reported_status']);
            self::assertNull($r['outcome_truth']['actual_delta']);self::assertSame($r,$this->item($in));
        }
        $r=$this->item($this->input());self::assertSame('success',$r['review']['status']);self::assertSame('none',$r['next_action']['key']);
        self::assertSame(300.0,$r['outcome_truth']['actual_delta']);
    }
    public function testNormalNearZeroFailedAndNonterminalRecommendationsRemainCompatible(): void
    {
        $in=$this->input();self::assertSame('none',$this->item($in)['next_action']['key']);
        $in[0]['expected_delta']=400;$in[1]['result_status']='near_success';self::assertSame('none',$this->item($in)['next_action']['key']);
        $in=$this->input();$in[0]['expected_delta']=0;$in[2]['before']['revenue']=0;$in[2]['after']['revenue']=0;
        self::assertSame('success',$this->item($in)['review']['status']);self::assertSame('none',$this->item($in)['next_action']['key']);
        foreach (['failed'=>'review_failure','observing'=>'review_effect'] as $status=>$key) {
            $in=$this->input();$in[1]['result_status']=$status;self::assertSame($key,$this->item($in)['next_action']['key']);
        }
        $in=$this->input();unset($in[1]['result_status']);self::assertSame('review_effect',$this->item($in)['next_action']['key']);
    }
    public function testIdentityMismatchCannotOfferARefreshForExcludedTask(): void
    {
        foreach (['hotel_id'=>21,'tenant_id'=>11,'intent_id'=>5] as $k=>$v) {
            $in=$this->input();$in[1][$k]=$v;$r=$this->item($in);
            self::assertSame('mismatch_excluded',$r['identity']['status']);self::assertSame(0,$r['execution']['task_id']);
            self::assertNotSame('refresh_readback',$r['next_action']['key']);
        }
    }
    public function testProtectedAnalysisRetainsItsOriginalReadOnlyCompletionInstruction(): void
    {
        $in=$this->input();$in[0]['source_module']='canonical_ota_investigation';$in[0]['status']='system_authorized_analysis';
        $in[2]['before']['revenue']=null;$r=$this->item($in);self::assertSame('none',$r['next_action']['key']);
        self::assertSame('核查已完成；经营效果未声明',$r['next_action']['label']);
    }
}
