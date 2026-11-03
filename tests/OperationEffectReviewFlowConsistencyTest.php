<?php
declare(strict_types=1);
namespace Tests;

use app\service\OperationManagementService;
use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;
use app\service\operation\OperationEffectReviewService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\facade\Config;
use think\facade\Db;

/** local_synthetic_fixture_only: no application configuration or real database is loaded. */
final class OperationEffectReviewFlowConsistencyTest extends TestCase
{
    private object $fixture;
    private ReflectionClass $fixtureClass;

    protected function setUp(): void
    {
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-effect-flow-'.getmypid().'/cache/']]], 'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-effect-flow-'.getmypid().'/log/']]], 'log');
        Config::set(['default'=>'sqlite','connections'=>['sqlite'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false,
        ]]], 'database');
        Db::connect(null, true);
        require_once __DIR__.'/OperationEffectReviewServiceTest.php';
        $this->fixtureClass = new ReflectionClass(OperationEffectReviewServiceTest::class);
        $this->fixture = $this->fixtureClass->newInstanceWithoutConstructor();
        $this->fixtureClass->getMethod('createSchema')->invoke(null);
        $this->fixtureClass->getMethod('seedApprovedExecution')->invoke($this->fixture);
    }

    protected function tearDown(): void { Db::connect()->close(); }

    private function input(): array { return $this->fixtureClass->getMethod('effectInput')->invoke($this->fixture); }
    private function save(): array { return (new OperationEffectReviewService())->create(42,7,1,1,$this->input(),3); }
    private function snapshot(): array
    {
        $rows=[];
        foreach (['operation_execution_intents','operation_execution_tasks','operation_execution_evidence','operation_effect_reviews'] as $table) {
            $rows[$table]=Db::name($table)->order('id')->select()->toArray();
        }
        return $rows;
    }
    private function read(): array
    {
        $before=$this->snapshot();$service=new OperationManagementService();
        $flow=$service->executionFlow([7],7,['intent_id'=>1]);$detail=$service->readExecutionTask(1,[7]);
        self::assertSame($before,$this->snapshot(), 'Readback must not change any fixture business row.');
        self::assertSame(1,$flow['returned_count']);self::assertSame(1,$flow['matched_total']);
        $item=$flow['list'][0];self::assertSame(1,$item['id']);self::assertSame(1,$item['execution']['task_id']);
        self::assertSame(7,$item['hotel_id']);self::assertSame(42,$item['tenant_id']);
        self::assertSame('ctrip',$item['recommendation']['platform']);
        self::assertSame('2026-08-07',$item['recommendation']['date_start']);
        self::assertSame($detail['outcome_truth'],$item['outcome_truth']);
        if ($item['effect_review_summary'] !== null) self::assertSame($detail['effect_review_summary'],$item['effect_review_summary']);
        self::assertSame($detail['sop_candidate'],$item['sop_candidate']);
        self::assertSame($detail['result_status'],$item['review']['reported_status']);
        self::assertSame($detail['result_summary'],$item['review']['summary']);
        self::assertNotEmpty(json_encode([$flow,$detail],JSON_THROW_ON_ERROR));
        return [$item,$detail,$flow];
    }
    private function assertUnavailable(array $item, string $reason): void
    {
        self::assertSame('unverified',$item['review']['status']);
        self::assertSame('unverified',$item['outcome_truth']['status']);
        self::assertSame($reason,$item['outcome_truth']['failure_reason']);
        foreach (['before_value','after_value','actual_delta','progress_rate'] as $field) self::assertNull($item['outcome_truth'][$field]);
        self::assertSame('not_ready',$item['sop_candidate']['status']);self::assertNull($item['sop_candidate']['candidate_id']);
        self::assertSame('refresh_readback',$item['next_action']['key']);
        self::assertNull($item['roi']['incremental_revenue']);
        self::assertSame(1,$item['evidence']['source_verified_count']);
    }

    public function testMissingSavedReviewCannotMasqueradeAsSuccessAndSaveDuplicateExactRefreshRecovers(): void
    {
        [$item]=$this->read();$this->assertUnavailable($item,'current_separate_effect_review_missing');
        self::assertSame('missing',$item['effect_review_summary']['persistence_status']);
        $saved=$this->save();$duplicate=$this->save();self::assertTrue($saved['created']);self::assertFalse($duplicate['created']);
        self::assertSame($saved['review'], $duplicate['review']);
        $exact=(new OperationEffectReviewService())->readVerified((int)$saved['review']['id'],42,7,1,1);
        self::assertSame($saved['review'],$exact);
        [$restored]=$this->read();self::assertSame('success',$restored['review']['status']);self::assertSame('met',$restored['outcome_truth']['status']);
        self::assertSame((int)$exact['id'],$restored['effect_review_summary']['active_id']);
        self::assertSame($exact['content_digest'],$restored['effect_review_summary']['active_content_digest']);
        self::assertSame(1,(int)Db::name('operation_effect_reviews')->count());
    }
    public function testApprovalDriftAndCurrentSummaryMismatchPreserveHistoricalReviewAndRecover(): void
    {
        $saved=$this->save();$summary=$this->input()['result_summary'];
        Db::name('operation_execution_tasks')->where('id',1)->update(['result_summary'=>'local_synthetic_fixture_only changed current result']);
        [$item]=$this->read();$this->assertUnavailable($item,'current_separate_effect_review_missing');
        self::assertSame('current_result_mismatch',$item['effect_review_summary']['persistence_status']);
        Db::name('operation_execution_tasks')->where('id',1)->update(['result_summary'=>$summary]);
        [$restored]=$this->read();self::assertSame((int)$saved['review']['id'],$restored['effect_review_summary']['active_id']);
        $intent=Db::name('operation_execution_intents')->where('id',1)->find();
        $this->fixtureClass->getMethod('driftApprovedIntentTarget')->invoke($this->fixture,4.0);
        [$item]=$this->read();$this->assertUnavailable($item,'approval_target_contract_drifted');
        self::assertSame('approval_target_drifted',$item['effect_review_summary']['persistence_status']);
        self::assertSame('reapprove_target_and_repeat_effect_review',$item['sop_candidate']['boundaries']['next_stage']);
        Db::name('operation_execution_intents')->where('id',1)->update($intent);
        [$restored]=$this->read();self::assertSame('success',$restored['review']['status']);
    }
    public function testCorruptReviewIsExplicitPartialWithoutClearingIndependentLegacyItem(): void
    {
        $saved=$this->save();$intent=Db::name('operation_execution_intents')->where('id',1)->find();$intent['id']=2;$intent['source_module']='manual';
        Db::name('operation_execution_intents')->insert($intent);
        $task=Db::name('operation_execution_tasks')->where('id',1)->find();$task['id']=2;$task['intent_id']=2;Db::name('operation_execution_tasks')->insert($task);
        $evidence=Db::name('operation_execution_evidence')->where('id',1)->find();$evidence['id']=2;$evidence['task_id']=2;Db::name('operation_execution_evidence')->insert($evidence);
        Db::name('operation_effect_reviews')->where('id',(int)$saved['review']['id'])->update(['content_digest'=>str_repeat('0',64)]);
        $before=$this->snapshot();$s=new OperationManagementService();$flow=$s->executionFlow([7],7);$items=array_column($flow['list'],null,'id');
        $this->assertUnavailable($items[1],'separate_effect_review_readback_failed');self::assertSame('readback_failed',$items[1]['effect_review_summary']['persistence_status']);
        self::assertSame('success',$items[2]['review']['status']);self::assertSame('met',$items[2]['outcome_truth']['status']);
        self::assertSame(2,$flow['summary']['executed']);self::assertSame(2,$flow['summary']['source_verified']);
        self::assertSame('partial',$flow['data_status']);self::assertTrue($flow['statistics']['roi_loaded']);
        self::assertTrue($flow['statistics']['task_status_loaded']);self::assertTrue($flow['statistics']['evidence_loaded']);
        self::assertFalse($flow['statistics']['effect_review_loaded']);
        self::assertContains('operation_effect_review_readback_failed',array_column($flow['data_gaps'],'code'));
        try{$s->readExecutionTask(1,[7]);self::fail('Corrupt strict detail must fail.');}catch(\RuntimeException $e){self::assertStringContainsString('严格回读',$e->getMessage());}
        self::assertSame($before,$this->snapshot());
        Db::name('operation_effect_reviews')->where('id',(int)$saved['review']['id'])->update(['content_digest'=>$saved['review']['content_digest']]);
        [$restored]=$this->read();self::assertSame('success',$restored['review']['status']);
    }
    public function testMissingTableAndWrongTenantCannotProduceVerifiedEffect(): void
    {
        Db::execute('DROP TABLE operation_effect_reviews');$s=new OperationManagementService();$flow=$s->executionFlow([7],7,['intent_id'=>1]);$detail=$s->readExecutionTask(1,[7]);
        $this->assertUnavailable($flow['list'][0],'current_separate_effect_review_missing');
        self::assertSame($detail['effect_review_summary'],$flow['list'][0]['effect_review_summary']);self::assertSame('table_missing',$detail['effect_review_summary']['persistence_status']);
        self::assertSame('partial',$flow['data_status']);
        Db::name('operation_execution_tasks')->where('id',1)->update(['tenant_id'=>99]);$flow=$s->executionFlow([7],7,['intent_id'=>1]);
        self::assertSame(0,$flow['list'][0]['execution']['task_id']);self::assertSame('mismatch_excluded',$flow['list'][0]['identity']['status']);
        $this->expectException(\InvalidArgumentException::class);$s->readExecutionTask(1,[7]);
    }
    public function testStoredNearAndFailedEffectsRetainTheirExactCurrentStatus(): void
    {
        $this->fixtureClass->getMethod('testDecimalEffectBoundaryPersistsAndExactlyReadsBack')->invoke($this->fixture,10.0,11.5,2.0,'increase','delta','near');
        [$item]=$this->read();self::assertSame('near_success',$item['review']['status']);self::assertSame('near',$item['outcome_truth']['status']);
    }
    public function testStoredFailedEffectAndLegacyAndNonterminalRemainCompatible(): void
    {
        $this->fixtureClass->getMethod('testDecimalEffectBoundaryPersistsAndExactlyReadsBack')->invoke($this->fixture,10.0,10.0,2.0,'increase','delta','missed');
        [$item]=$this->read();self::assertSame('failed',$item['review']['status']);self::assertSame('missed',$item['outcome_truth']['status']);self::assertSame('review_failure',$item['next_action']['key']);
        Db::name('operation_execution_tasks')->where('id',1)->update(['result_status'=>'observing']);
        [$item]=$this->read();self::assertSame('observing',$item['review']['status']);self::assertSame('review_effect',$item['next_action']['key']);
        Db::name('operation_execution_intents')->where('id',1)->update(['source_module'=>'manual']);
        [$item]=$this->read();self::assertSame('observing',$item['review']['status']);
    }
    public function testSelectorKeepsMatchingOlderReviewAndExcludesForeignIdentities(): void
    {
        $this->save();$s=new OperationManagementService();$task=$s->readExecutionTask(1,[7]);
        $intent=['id'=>1,'tenant_id'=>42,'hotel_id'=>7,'source_module'=>'ota_diagnosis_saved','expected_metric'=>'orders'];
        $review=$task['effect_reviews'][0];$newer=$review;$newer['id']=2;$newer['result_summary']='another result';
        $foreign=$review;$foreign['id']=3;$foreign['hotel_id']=8;
        $projection=(new ExecutionFlowReadService(new ExecutionOutcomeService()))->projectEffectReviews($intent,$task,[$foreign,$newer,$review]);
        self::assertSame(2,$projection['effect_review_summary']['count']);self::assertSame(2,$projection['effect_review_summary']['latest_id']);
        self::assertSame(1,$projection['effect_review_summary']['active_id']);self::assertSame($review['outcome'],$projection['outcome_truth']);
    }
}
