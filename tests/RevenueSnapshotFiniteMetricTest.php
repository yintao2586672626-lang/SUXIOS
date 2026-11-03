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
use think\App;
use think\facade\Config;
use think\facade\Db;

require_once __DIR__.'/RevenueCockpitSnapshotGenerationTest.php';
require_once __DIR__.'/RevenueCockpitApprovalServiceTest.php';

/** local_synthetic_fixture_only: original services, private SQLite, no operating execution. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenueSnapshotFiniteMetricTest extends TestCase
{
    private string $database;
    protected function setUp(): void
    {
        new App(dirname(__DIR__)); // Do not initialize or load live runtime/configuration.
        $this->database=sys_get_temp_dir().'/suxios-finite-snapshot-'.getmypid().'.sqlite';
        Config::set(['default'=>'finite_fixture','connections'=>['finite_fixture'=>[
            'type'=>'sqlite','database'=>$this->database,'prefix'=>'','fields_strict'=>false,
        ]]],'database');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-finite-snapshot-'.getmypid().'/log/']]],'log');
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/suxios-finite-snapshot-'.getmypid().'/cache/']]],'cache');
        Db::connect(null,true);
        self::assertSame($this->database,Db::connect()->getConfig('database'));
        foreach([
            'hotels (id INTEGER PRIMARY KEY,tenant_id INTEGER)',
            'operation_execution_intents (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,idempotency_key TEXT UNIQUE,source_module TEXT,source_record_id INTEGER,hotel_id INTEGER,platform TEXT,object_type TEXT,action_type TEXT,date_start TEXT,date_end TEXT,current_value_json TEXT,target_value_json TEXT,evidence_json TEXT,expected_metric TEXT,expected_delta REAL,risk_level TEXT,blocked_reason TEXT,status TEXT,created_by INTEGER,approved_by INTEGER DEFAULT 0,approved_at TEXT,review_remark TEXT,created_at TEXT,updated_at TEXT,deleted_at TEXT)',
            'operation_execution_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,intent_id INTEGER,hotel_id INTEGER,status TEXT,deleted_at TEXT)',
            'operation_execution_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,task_id INTEGER,deleted_at TEXT)',
            'operation_action_lifecycle_events (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,intent_id INTEGER,task_id INTEGER,sequence_no INTEGER,event_type TEXT,from_status TEXT,to_status TEXT,actor_id INTEGER,event_payload_json TEXT,previous_digest TEXT,content_digest TEXT,created_at TEXT,UNIQUE(tenant_id,hotel_id,intent_id,sequence_no))',
            'operation_action_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,intent_id INTEGER,task_id INTEGER)',
            'revenue_decision_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,system_hotel_id INTEGER,platform TEXT,business_date TEXT,contract_version TEXT,source_refs_json TEXT,metric_definitions_json TEXT,visible_model_json TEXT,missing_items_json TEXT,evidence_summary_json TEXT,visible_model_digest TEXT,evidence_digest TEXT,content_digest TEXT,idempotency_key TEXT UNIQUE,created_by INTEGER,created_at TEXT)',
        ] as $schema)Db::execute('CREATE TABLE '.$schema);
        Db::name('hotels')->insert(['id'=>20,'tenant_id'=>10]);
    }
    protected function tearDown(): void
    {
        Db::connect()->close();if(is_file($this->database))unlink($this->database);
    }
    private function overview(float $ctrip=1e20,float $meituan=2e20): array
    {
        $fixture=new RevenueCockpitSnapshotGenerationTest('testNonBaselineSourceChangeCreatesCurrentVersionAndRetriesReuseIt');
        $overview=(new \ReflectionMethod($fixture,'overview'))->invoke($fixture,600);
        $overview['cockpit_strict_evidence']=(new RevenueCockpitStrictEvidenceService())->build($overview,10,20,'2026-08-20','all_ota',$overview['dual_ota_field_closure']);
        foreach(['ctrip'=>$ctrip,'meituan'=>$meituan] as $platform=>$revenue){
            $overview['three_source_fact_layer']['sources'][$platform.'_ota']['facts']['revenue']=$revenue;
            foreach($overview['cockpit_strict_evidence']['platforms'][$platform]['metrics'] as $key=>$metric){
                if(($metric['strict_readback']??false)&&is_numeric($overview['three_source_fact_layer']['sources'][$platform.'_ota']['facts'][$key]??null))$overview['three_source_fact_layer']['sources'][$platform.'_ota']['fact_statuses'][$key]=['status'=>'readback_verified','reason'=>'local_synthetic_fixture_only'];
            }
        }
        $sum=$ctrip+$meituan;
        $overview['three_source_fact_layer']['facts']['ota_channel']['combined']['revenue']=is_finite($sum)?$sum:null;
        return $overview;
    }
    private function comparisons(): array
    {
        return ['previous_date'=>'','same_weekday_date'=>'','previous_overview'=>[],'same_weekday_overview'=>[],'selected_platform_label'=>'双OTA','latest_verified_date'=>'2026-08-20','scope_notice'=>'local_synthetic_fixture_only'];
    }
    private function model(array $overview): array
    {
        $context=(new RevenueCockpitApprovalService())->evidenceContext($overview,10,20,'2026-08-20','all_ota');
        return (new RevenueDecisionViewModelAttestationService())->issue($overview,$this->comparisons(),$context,10,20,'2026-08-20','all_ota');
    }
    private function snapshot(array $overview,?array $model=null): array
    {
        return (new RevenueDecisionSnapshotService())->saveFromOverview($overview,10,20,'2026-08-20','all_ota',7,$model??$this->model($overview),$this->comparisons());
    }
    private function metric(array $model,string $key): array
    {
        foreach($model['visibleSections'] as $section)foreach($section['cards'] as $card)if(($card['key']??'')===$key)return $card;
        self::fail('Metric missing: '.$key);
    }
    private function pending(array $overview,array $snapshot): array
    {
        return (new RevenueDecisionSnapshotService())->createOpportunityPendingApproval((int)$snapshot['id'],10,20,7,'promotion_incrementality_evidence',$overview);
    }
    private function rows(): array
    {
        return [Db::name('revenue_decision_snapshots')->order('id')->select()->toArray(),Db::name('operation_execution_intents')->order('id')->select()->toArray(),Db::name('operation_execution_tasks')->count(),Db::name('operation_execution_evidence')->count()];
    }
    public function testLargeFiniteValuesSurviveIssueJsonAndSelfAttestation(): void
    {
        foreach([1e20,1e308,(float)PHP_INT_MAX] as $value){
            $overview=$this->overview($value,$value);$model=$this->model($overview);
            self::assertSame($value,$this->metric($model,'ctrip_ota:revenue')['value']);
            self::assertSame($value,$this->metric($model,'meituan_ota:revenue')['value']);
            $roundTrip=json_decode(json_encode($model,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            $context=(new RevenueCockpitApprovalService())->evidenceContext($overview,10,20,'2026-08-20','all_ota');
            self::assertSame($model,(new RevenueDecisionViewModelAttestationService())->attest($roundTrip,$overview,$this->comparisons(),$context,10,20,'2026-08-20','all_ota'));
            self::assertSame(8,$this->metric($model,'ctrip_ota:orders')['value']);
        }
    }
    public function testFiniteIncomeSnapshotAndPendingCardPersistRepeatAndExactlyRead(): void
    {
        $overview=$this->overview();$snapshot=$this->snapshot($overview);$pending=$this->pending($overview,$snapshot);$before=$this->rows();
        self::assertSame(1e20,$this->metric($snapshot['visible_model'],'ctrip_ota:revenue')['value']);
        self::assertSame('revenue',$pending['execution_intent']['expected_metric']);
        self::assertSame(3e20,(float)$pending['execution_intent']['target_value']['action_card']['metric_contract']['baseline_window']['value']);
        self::assertSame('pending_approval',$pending['status']);self::assertSame(0,$pending['execution_task_count']);
        $repeat=$this->snapshot($overview);self::assertSame($snapshot['id'],$repeat['id']);
        $repeatPending=$this->pending($overview,$repeat);self::assertSame($pending['execution_intent']['id'],$repeatPending['execution_intent']['id']);self::assertTrue($repeatPending['reused_existing_intent']);
        $exact=(new RevenueDecisionSnapshotService())->readExact((int)$snapshot['id'],10,20,$overview);
        self::assertSame($snapshot['visible_model'],$exact['visible_model']);self::assertSame($snapshot['content_digest'],$exact['content_digest']);
        $read=(new RevenueCockpitApprovalService())->readFromOverview($overview,10,20,'2026-08-20','all_ota');self::assertFalse($read['approval_blocked']);self::assertSame($pending['execution_intent']['id'],$read['execution_intent']['id']);
        self::assertSame($before,$this->rows());self::assertSame(0,$this->rows()[2]);self::assertSame(0,$this->rows()[3]);
    }
    public function testCombinedOverflowRetainsChannelValuesOrdersAndNonCausalChecklist(): void
    {
        $overview=$this->overview(1e308,1e308);self::assertNull($overview['three_source_fact_layer']['facts']['ota_channel']['combined']['revenue']);
        $snapshot=$this->snapshot($overview);$pending=$this->pending($overview,$snapshot);
        self::assertSame(1e308,$this->metric($snapshot['visible_model'],'ctrip_ota:revenue')['value']);
        self::assertSame('orders',$pending['execution_intent']['expected_metric']);
        self::assertSame(14,(int)$pending['execution_intent']['target_value']['action_card']['metric_contract']['baseline_window']['value']);
        $opportunity=array_values(array_filter($snapshot['visible_model']['opportunities'],static fn($v)=>$v['opportunityKey']==='promotion_incrementality_evidence'))[0];
        self::assertFalse($opportunity['causalityClaimed']);self::assertSame('not_claimed',$opportunity['causalityStatus']);
        self::assertStringContainsString('当前不宣称因果',$pending['execution_intent']['target_value']['action_card']['action']['description']);
        self::assertSame(0,$this->rows()[2]);
    }
    public function testTamperedValueFailsWithoutWritingAndOriginalRefreshRestores(): void
    {
        $overview=$this->overview();$model=$this->model($overview);$saved=$this->snapshot($overview,$model);$before=$this->rows();
        foreach(['visibleSections','sections'] as $sectionKey)foreach($model[$sectionKey] as &$section)foreach($section['cards'] as &$card)if(($card['key']??'')==='ctrip_ota:revenue')$card['value']=0;
        unset($section,$card);
        try{$this->snapshot($overview,$model);self::fail('Tampered income saved');}catch(\InvalidArgumentException $e){self::assertStringContainsString('core_metric:ctrip_ota:revenue:value',$e->getMessage());}
        self::assertSame($before,$this->rows());self::assertSame($saved['id'],$this->snapshot($overview)['id']);self::assertSame($before,$this->rows());
    }
    public function testReceiptFailureAndScopeMismatchRejectWithoutChangingSavedIdentity(): void
    {
        $overview=$this->overview();$saved=$this->snapshot($overview);$this->pending($overview,$saved);$before=$this->rows();$bad=$overview;
        $bad['dual_ota_field_closure']['platforms']['meituan']['revenue_analysis']['status']='blocked';
        try{$this->snapshot($bad);self::fail('Failed receipt saved');}catch(\RuntimeException $e){self::assertSame('revenue_cockpit_meituan_current_receipt_not_ready',$e->getMessage());}
        self::assertNull((new RevenueDecisionSnapshotService())->readExact((int)$saved['id'],11,20,$overview));
        self::assertNull((new RevenueDecisionSnapshotService())->readExact((int)$saved['id'],10,21,$overview));
        foreach([['business_date'=>'2026-08-21'],['hotel_id'=>21]] as $mutation){
            try{$this->snapshot(array_replace($overview,$mutation));self::fail('Wrong scope saved');}catch(\RuntimeException $e){self::assertSame('revenue_cockpit_approval_overview_scope_mismatch',$e->getMessage());}
        }
        self::assertSame($before,$this->rows());self::assertSame($saved['id'],$this->snapshot($overview)['id']);self::assertSame($before,$this->rows());
    }
    public function testOldSmallIntegralCanonicalizationAndDigestStayCompatible(): void
    {
        $service=new RevenueDecisionViewModelAttestationService();$method=new \ReflectionMethod($service,'canonicalize');
        $old=['zero'=>0.0,'positive'=>600.0,'negative'=>-600.0,'fraction'=>0.125];
        self::assertSame(['fraction'=>0.125,'negative'=>-600,'positive'=>600,'zero'=>0],$method->invoke($service,$old));
        foreach([1e20,-1e20,1e308,-1e308,(float)PHP_INT_MAX] as $value)self::assertSame($value,$method->invoke($service,$value));
        self::assertSame(PHP_INT_MIN,$method->invoke($service,(float)PHP_INT_MIN));
        $overview=$this->overview(800.0,600.0);$model=$this->model($overview);
        self::assertSame(800,$this->metric($model,'ctrip_ota:revenue')['value']);
        // Recorded by the original issuer before the integer-range repair.
        self::assertSame('3593fde1df162708bd0c59a9abd29de76b4943e6337b2b1e257589b3662d284b',$service->issuedDigest($model));
    }
}
