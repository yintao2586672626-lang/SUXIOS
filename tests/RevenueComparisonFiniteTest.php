<?php
declare(strict_types=1);
namespace Tests;

use app\service\RevenueCockpitApprovalService;
use app\service\RevenueCockpitStrictEvidenceService;
use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__.'/RevenueSnapshotFiniteMetricTest.php';

/** local_synthetic_fixture_only: original issuer/save/readback, private SQLite. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueComparisonFiniteTest extends TestCase
{
    private RevenueSnapshotFiniteMetricTest $fixture;
    protected function setUp(): void
    {
        $this->fixture=new RevenueSnapshotFiniteMetricTest('testLargeFiniteValuesSurviveIssueJsonAndSelfAttestation');
        (new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture);
    }
    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
    }
    public static function inputs(string $mode): array
    {
        $fixture=new RevenueCockpitSnapshotGenerationTest('testNonBaselineSourceChangeCreatesCurrentVersionAndRetriesReuseIt');
        $overview=(new \ReflectionMethod($fixture,'overview'))->invoke($fixture,600);
        $overview['cockpit_strict_evidence']=(new RevenueCockpitStrictEvidenceService())->build($overview,10,20,'2026-08-20','ctrip',$overview['dual_ota_field_closure']);
        foreach($overview['cockpit_strict_evidence']['platforms']['ctrip']['metrics'] as $key=>$proof){
            if(($proof['strict_readback']??false)&&is_numeric($overview['three_source_fact_layer']['sources']['ctrip_ota']['facts'][$key]??null))$overview['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses'][$key]=['status'=>'readback_verified','reason'=>'local_synthetic_fixture_only'];
        }
        $baseline=json_decode(str_replace('2026-08-20','2026-08-19',json_encode($overview,JSON_THROW_ON_ERROR)),true,512,JSON_THROW_ON_ERROR);
        $overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=500;
        $baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=1000;
        if($mode==='percent_overflow'){$overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=1e308;$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=1;}
        if($mode==='tiny_baseline'){$overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=1;$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=1e-308;}
        if($mode==='delta_overflow'){$overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue']=1e308;$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue']=-1e308;}
        if($mode==='negative_delta_overflow'){$overview['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue']=-1e308;$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['revenue']=1e308;}
        if($mode==='zero_baseline')$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=0;
        if($mode==='missing_baseline'){$baseline['three_source_fact_layer']['sources']['ctrip_ota']['facts']['list_exposure']=null;$baseline['three_source_fact_layer']['sources']['ctrip_ota']['fact_statuses']['list_exposure']=['status'=>'missing','reason'=>'local_synthetic_fixture_only'];}
        return [$overview,['previous_date'=>'2026-08-19','previous_overview'=>$baseline,'same_weekday_date'=>'','same_weekday_overview'=>[],'selected_platform_label'=>'携程','latest_verified_date'=>'2026-08-20','scope_notice'=>'local_synthetic_fixture_only']];
    }
    private function model(array $overview,array $comparisons): array
    {
        $context=(new RevenueCockpitApprovalService())->evidenceContext($overview,10,20,'2026-08-20','ctrip');
        return (new RevenueDecisionViewModelAttestationService())->issue($overview,$comparisons,$context,10,20,'2026-08-20','ctrip');
    }
    private function save(array $overview,array $comparisons,?array $model=null): array
    {
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview,10,20,'2026-08-20','ctrip',7,$model??$this->model($overview,$comparisons),$comparisons);
    }
    private function card(array $model,string $key): array
    {
        foreach($model['visibleSections'] as $section)foreach($section['cards'] as $card)if($card['key']===$key)return $card;
        self::fail('Original card absent: '.$key);
    }
    private function rows(): array
    {
        return [Db::name('revenue_decision_snapshots')->order('id')->select()->toArray(),Db::name('operation_execution_intents')->order('id')->select()->toArray(),Db::name('operation_execution_tasks')->count(),Db::name('operation_execution_evidence')->count()];
    }
    public function testPercentOverflowPreservesFiniteDeltaAndBlocksDependentPendingCard(): void
    {
        foreach(['percent_overflow'=>1e308,'tiny_baseline'=>1] as $mode=>$delta){
            [$overview,$comparisons]=self::inputs($mode);$model=$this->model($overview,$comparisons);
            $card=$this->card($model,'compare:ctrip_ota:list_exposure');
            self::assertEquals($delta,$card['value']);self::assertSame('partial',$card['status']);
            self::assertSame('numeric_comparison_percent_nonfinite',$card['reasonCode']);
            self::assertStringContainsString('百分比不可计算',$card['display']);self::assertStringNotContainsString('inf',$card['display']);
            self::assertStringContainsString('携程',$card['reasonText']);
            self::assertSame(8,$this->card($model,'ctrip_ota:orders')['value']);
            self::assertSame(800,$this->card($model,'ctrip_ota:revenue')['value']);
            self::assertSame('blocked',$this->card($model,'opportunity:traffic_entry_shortage')['state']);
            self::assertFalse($this->card($model,'opportunity:traffic_entry_shortage')['canCreatePendingApproval']);
            self::assertIsArray(json_decode(json_encode($model,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
            $snapshot=$this->save($overview,$comparisons);$before=$this->rows();
            try{(new RevenueDecisionSnapshotService())->createOpportunityPendingApproval((int)$snapshot['id'],10,20,7,'traffic_entry_shortage',$overview,$comparisons);self::fail('Failed percentage suggested an action');}
            catch(\RuntimeException $e){self::assertSame('revenue_decision_snapshot_opportunity_not_actionable',$e->getMessage());}
            self::assertSame($before,$this->rows());
            self::assertSame($snapshot['visible_model'],(new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],10,20,$overview,$comparisons)['visible_model']);
        }
    }
    public function testPositiveAndNegativeFiniteSubtractionOverflowStayUnknownAndSave(): void
    {
        foreach(['delta_overflow','negative_delta_overflow'] as $mode){
            [$overview,$comparisons]=self::inputs($mode);$model=$this->model($overview,$comparisons);$card=$this->card($model,'compare:ctrip_ota:revenue');
            self::assertNull($card['value']);self::assertSame('not_calculable',$card['status']);self::assertSame('numeric_comparison_delta_nonfinite',$card['reasonCode']);
            self::assertStringContainsString('差值不可计算',$card['display']);self::assertStringNotContainsString('inf',$card['display']);
            self::assertSame(8,$this->card($model,'ctrip_ota:orders')['value']);
            self::assertSame(-500,$this->card($model,'compare:ctrip_ota:list_exposure')['value']);
            self::assertIsArray(json_decode(json_encode($model,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR));
            $snapshot=$this->save($overview,$comparisons);self::assertSame($snapshot['visible_model'],(new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],10,20,$overview,$comparisons)['visible_model']);
            self::assertSame(0,$this->rows()[2]);self::assertSame(0,$this->rows()[3]);
        }
    }
    public function testHealthyOpportunitySnapshotsSaveRepeatExactlyReadAndCreatePendingOnly(): void
    {
        [$overview,$comparisons]=self::inputs('normal');$snapshot=$this->save($overview,$comparisons);
        self::assertSame(-500,$this->card($snapshot['visible_model'],'compare:ctrip_ota:list_exposure')['value']);
        self::assertStringContainsString('-50.00%',$this->card($snapshot['visible_model'],'compare:ctrip_ota:list_exposure')['display']);
        foreach($snapshot['missing_items'] as $missing)self::assertNotContains($missing['status'],['actionable','no_signal']);
        $pending=(new RevenueDecisionSnapshotService())->createOpportunityPendingApproval((int)$snapshot['id'],10,20,7,'traffic_entry_shortage',$overview,$comparisons);
        self::assertSame('pending_approval',$pending['status']);self::assertSame(0,$pending['execution_task_count']);
        $before=$this->rows();$repeat=$this->save($overview,$comparisons);
        self::assertSame($snapshot['id'],$repeat['id']);self::assertSame($snapshot['content_digest'],$repeat['content_digest']);
        self::assertSame($snapshot['visible_model'],(new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],10,20,$overview,$comparisons)['visible_model']);
        $again=(new RevenueDecisionSnapshotService())->createOpportunityPendingApproval((int)$snapshot['id'],10,20,7,'traffic_entry_shortage',$overview,$comparisons);
        self::assertSame($pending['execution_intent']['id'],$again['execution_intent']['id']);self::assertTrue($again['reused_existing_intent']);self::assertSame($before,$this->rows());
    }
    public function testNormalFailureCorrectionAndLegacyZeroMissingBaselinesRecover(): void
    {
        [$overview,$comparisons]=self::inputs('normal');$saved=$this->save($overview,$comparisons);
        [$bad,$badComparison]=self::inputs('percent_overflow');$failure=$this->save($bad,$badComparison);
        self::assertNotSame($saved['id'],$failure['id']);self::assertSame('partial',$this->card($failure['visible_model'],'compare:ctrip_ota:list_exposure')['status']);
        $before=$this->rows();self::assertSame($failure['id'],$this->save($bad,$badComparison)['id']);self::assertSame($saved['id'],$this->save($overview,$comparisons)['id']);
        self::assertSame($saved['visible_model'],(new RevenueDecisionSnapshotService())->readExact((int)$saved['id'],10,20,$overview,$comparisons)['visible_model']);self::assertSame($before,$this->rows());
        foreach(['zero_baseline','missing_baseline'] as $mode){
            [$old,$oldComparison]=self::inputs($mode);$snapshot=$this->save($old,$oldComparison);$card=$this->card($snapshot['visible_model'],'compare:ctrip_ota:list_exposure');
            if($mode==='zero_baseline'){self::assertSame(500,$card['value']);self::assertSame('verified',$card['status']);self::assertStringContainsString('基期为 0',$card['display']);}
            else{self::assertNull($card['value']);self::assertSame('same_source_comparison_missing',$card['reasonCode']);}
            self::assertSame($snapshot['id'],$this->save($old,$oldComparison)['id']);
        }
    }
    public function testValueAndReasonTamperingRejectWithoutWritesAndOriginalRestores(): void
    {
        [$overview,$comparisons]=self::inputs('percent_overflow');$model=$this->model($overview,$comparisons);$saved=$this->save($overview,$comparisons,$model);$before=$this->rows();
        foreach(['value'=>0,'reasonCode'=>'same_source_comparison_missing','status'=>'verified','display'=>'0'] as $field=>$value){
            $tampered=$model;foreach(['sections','visibleSections'] as $name)foreach($tampered[$name] as &$section)foreach($section['cards'] as &$card)if($card['key']==='compare:ctrip_ota:list_exposure')$card[$field]=$value;unset($section,$card);
            try{$this->save($overview,$comparisons,$tampered);self::fail('Altered comparison accepted');}catch(\InvalidArgumentException $e){self::assertStringContainsString('compare:ctrip_ota:list_exposure:'.$field,$e->getMessage());}
            self::assertSame($before,$this->rows());
        }
        self::assertSame($saved['id'],$this->save($overview,$comparisons)['id']);self::assertSame($before,$this->rows());
    }
    public function testScopeRejectionPreservesSnapshotsAndCorrectionRestores(): void
    {
        [$overview,$comparisons]=self::inputs('normal');$snapshot=$this->save($overview,$comparisons);$before=$this->rows();
        self::assertNull((new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],11,20,$overview));
        self::assertNull((new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],10,21,$overview));
        foreach(['hotel_id'=>21,'business_date'=>'2026-08-18'] as $key=>$value){
            $wrong=$comparisons;$wrong['previous_overview'][$key]=$value;
            try{$this->save($overview,$wrong);self::fail('Wrong baseline accepted');}catch(\InvalidArgumentException $e){self::assertSame('revenue_decision_snapshot_view_model_unattested:comparison_previous_identity',$e->getMessage());}
            self::assertSame($before,$this->rows());
        }
        self::assertSame($snapshot['id'],$this->save($overview,$comparisons)['id']);self::assertSame($before,$this->rows());
    }
}
