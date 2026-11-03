<?php
declare(strict_types=1);

use app\service\BusinessFeatureCatalog;
use app\service\BusinessWorkspaceService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class BusinessWorkspaceServiceTest extends TestCase
{
    private array $original;
    private string $database;
    private BusinessWorkspaceService $service;
    protected function setUp():void
    {
        (new App())->initialize();restore_error_handler();restore_exception_handler();
        $this->original=Config::get('database');$this->database=sys_get_temp_dir().'/business-workspace-'.bin2hex(random_bytes(5)).'.sqlite';
        Config::set(['default'=>'business_workspace_test','connections'=>['business_workspace_test'=>['type'=>'sqlite','database'=>$this->database,'prefix'=>'','fields_strict'=>true]]],'database');Db::connect(null,true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        Db::execute("INSERT INTO hotels VALUES(80,10,'synthetic A'),(81,10,'synthetic B'),(82,11,'synthetic C')");
        Db::execute('CREATE TABLE hotel_business_workspace_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,owner_user_id INTEGER,kind TEXT,previous_id INTEGER,idempotency_key TEXT,payload_json TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,owner_user_id,kind,idempotency_key))');
        $this->service=new BusinessWorkspaceService();
    }
    protected function tearDown():void
    {
        Db::connect()->close();Config::set($this->original,'database');Db::connect(null,true);if(is_file($this->database))unlink($this->database);
    }
    public function testAllThreePhasesAreCustomizableAndPreciselyVersionedPerHotelAndUser():void
    {
        $scope=$this->service->scope(10,[80,81],80,7,'configuration');
        $initial=$this->service->overview($scope);self::assertSame('missing',$initial['status']);self::assertNull($initial['latest']);
        self::assertCount(31,$initial['catalog']);self::assertCount(31,array_unique(array_column($initial['catalog'],'module_id')));
        $settings=BusinessFeatureCatalog::defaults();$settings['modules'][30]['enabled']=false;$settings['modules'][20]['phase']=3;
        $saved=$this->service->save($scope,$settings,'synthetic_config_a',7,0);
        self::assertTrue($saved['readback_verified']);self::assertSame('configuration_only',$saved['source_quality']);
        self::assertFalse($saved['inputs']['modules'][30]['enabled']);self::assertSame(3,$saved['inputs']['modules'][20]['phase']);
        $same=$this->service->save($scope,$settings,'synthetic_config_a',7,0);self::assertSame($saved['snapshot_id'],$same['snapshot_id']);self::assertTrue($same['idempotent']);
        $settings['booking_fixed_time']='10:30';$settings['repeat_window_days']=180;
        $updated=$this->service->save($scope,$settings,'synthetic_config_b',7,$saved['snapshot_id']);
        self::assertSame('10:30',$updated['inputs']['booking_fixed_time']);self::assertSame($saved['snapshot_id'],$updated['previous_id']);
        self::assertSame('09:00',$this->service->read($scope,$saved['snapshot_id'])['inputs']['booking_fixed_time']);
        self::assertCount(2,$this->service->overview($scope)['history']);
        self::assertNull($this->service->overview($this->service->scope(10,[80,81],81,7,'configuration'))['latest']);
        self::assertNull($this->service->overview($this->service->scope(10,[80],80,8,'configuration'))['latest']);
    }
    public function testStaleAndConflictingSavesDoNotOverwriteExistingSettings():void
    {
        $scope=$this->service->scope(10,[80],80,7,'configuration');$input=BusinessFeatureCatalog::defaults();
        $this->service->save($scope,$input,'synthetic_conflict_a',7,0);$input['preferred_platform']='meituan';
        foreach([['synthetic_conflict_a',0],['synthetic_conflict_b',0]]as[$key,$expected]){
            try{$this->service->save($scope,$input,$key,7,$expected);self::fail('Expected conflict');}catch(RuntimeException $error){self::assertSame(409,$error->getCode());}
        }
        self::assertSame(1,Db::name(BusinessWorkspaceService::TABLE)->count());
    }
    public function testControllerRejectsUnauthenticatedForeignHotelAndViewOnlyWrites():void
    {
        foreach([[null,80,401],[true,82,403],[false,80,403]]as[$writeAllowed,$hotel,$expected]){
            $controller=(new ReflectionClass(\app\controller\BusinessWorkspace::class))->newInstanceWithoutConstructor();
            $body=['hotel_id'=>$hotel,'kind'=>'configuration','inputs'=>BusinessFeatureCatalog::defaults(),'idempotency_key'=>'controller_denied','expected_snapshot_id'=>0];
            $request=new class($body){public function __construct(private array $body){} public function param($key=null,$default=null):mixed{return$key===null?$this->body:($this->body[$key]??$default);}public function post():array{return$this->body;}public function method():string{return'POST';}public function getContent():string{return'';}};
            $user=$writeAllowed===null?null:new class($writeAllowed){public int $id=7;public int $tenant_id=10;public function __construct(private bool $write){}public function getPermittedHotelIds():array{return[80,81];}public function hasHotelPermission(int $hotel,string $capability):bool{return$capability==='operation.view'||$this->write;}};
            (new ReflectionProperty(\app\controller\BusinessWorkspace::class,'request'))->setValue($controller,$request);
            (new ReflectionProperty(\app\controller\BusinessWorkspace::class,'currentUser'))->setValue($controller,$user);
            self::assertSame($expected,$controller->save()->getData()['code']);
        }
        self::assertSame(0,Db::name(BusinessWorkspaceService::TABLE)->count());
        self::assertStringContainsString('Auth::class',file_get_contents(dirname(__DIR__).'/route/domain/business_workspace.php'));
    }
    public function testCompletedReviewRequiresEvidenceAndPersistsManualJudgmentSeparately():void
    {
        $scope=$this->service->scope(10,[80],80,7,'weekly_review');$input=$this->note();
        $input['actions']=[['measure'=>'核对广告成本','owner'=>'合成负责人','due_date'=>'2026-10-08','status'=>'completed','evidence_ref'=>'']];
        try{$this->service->save($scope,$input,'synthetic_review_a',7,0);self::fail('Missing evidence');}catch(InvalidArgumentException $error){self::assertSame('business_workspace_completed_action_requires_evidence',$error->getMessage());}
        $input['actions'][0]['evidence_ref']='synthetic-receipt#1';
        $saved=$this->service->save($scope,$input,'synthetic_review_a',7,0);
        self::assertSame('pending',$saved['inputs']['human_review_status']);self::assertFalse($saved['inputs']['facts_independently_verified']);
        self::assertSame('manual_unverified',$saved['source_quality']);self::assertSame('synthetic-receipt#1',$saved['inputs']['actions'][0]['evidence_ref']);
        self::assertSame(0,$saved['external_write_count']);self::assertTrue($saved['readback_verified']);
    }
    public function testMappingPreviewPreservesMissingAndRejectsHotelDateDrift():void
    {
        $scope=$this->service->scope(10,[80],80,7,'source_mapping');$input=$this->note();
        $input['field_mapping']=['hotel_id'=>'酒店ID','platform'=>'来源平台','business_date'=>'业务日','room_revenue'=>'房费'];
        $saved=$this->service->save($scope,$input,'synthetic_mapping_a',7,0);
        $rows=[['酒店ID'=>80,'来源平台'=>'ctrip','业务日'=>'2026-10-02']];
        $preview=$this->service->mappingPreview($scope,$saved['snapshot_id'],$rows);
        self::assertNull($preview['rows'][0]['record']['room_revenue']);self::assertFalse($preview['ota_fact_created']);
        self::assertSame('reference_only',$preview['status']);self::assertSame(1,Db::name(BusinessWorkspaceService::TABLE)->count());
        $rows[0]['酒店ID']=81;
        try{$this->service->mappingPreview($scope,$saved['snapshot_id'],$rows);self::fail('Hotel drift');}catch(RuntimeException $error){self::assertSame(403,$error->getCode());}
        $rows[0]['酒店ID']=80;$rows[0]['业务日']='2026-11-02';
        $this->expectException(InvalidArgumentException::class);$this->service->mappingPreview($scope,$saved['snapshot_id'],$rows);
    }
    public function testUnauthorizedScopeAndTamperedRecordAreRejected():void
    {
        try{$this->service->scope(10,[82],82,7,'configuration');self::fail('Cross tenant');}catch(RuntimeException $error){self::assertSame(403,$error->getCode());}
        $scope=$this->service->scope(10,[80],80,7,'configuration');$saved=$this->service->save($scope,BusinessFeatureCatalog::defaults(),'synthetic_tamper_a',7,0);
        Db::name(BusinessWorkspaceService::TABLE)->where('id',$saved['snapshot_id'])->update(['payload_json'=>'{}']);
        $this->expectException(RuntimeException::class);$this->expectExceptionCode(409);$this->service->read($scope,$saved['snapshot_id']);
    }
    public function testDuplicateRankAndNumericBooleanCannotSilentlyEnableFeatures():void
    {
        $input=BusinessFeatureCatalog::defaults();$input['modules'][0]['enabled']=1;
        try{BusinessFeatureCatalog::normalize($input);self::fail('Boolean required');}catch(InvalidArgumentException){self::assertTrue(true);}
        $input=BusinessFeatureCatalog::defaults();$input['modules'][1]['rank']=1;
        $this->expectException(InvalidArgumentException::class);BusinessFeatureCatalog::normalize($input);
    }
    public function testHotelRenumberPreservesOriginalPayloadDigestAndConfiguration():void
    {
        $scope=$this->service->scope(10,[80],80,7,'configuration');$saved=$this->service->save($scope,BusinessFeatureCatalog::defaults(),'synthetic_renumber_a',7,0);
        $raw=Db::name(BusinessWorkspaceService::TABLE)->where('id',$saved['snapshot_id'])->find();
        Db::name('hotels')->where('id',80)->update(['id'=>180]);Db::name(BusinessWorkspaceService::TABLE)->where('hotel_id',80)->update(['hotel_id'=>180]);
        $newScope=$this->service->scope(10,[180],180,7,'configuration');$read=$this->service->read($newScope,$saved['snapshot_id']);
        self::assertSame(180,$read['scope']['hotel_id']);self::assertSame(80,$read['source_scope']['hotel_id']);
        self::assertSame($saved['content_digest'],$read['content_digest']);self::assertTrue($read['readback_verified']);
        self::assertSame($raw['payload_json'],Db::name(BusinessWorkspaceService::TABLE)->where('id',$saved['snapshot_id'])->value('payload_json'));
    }
    public function testSharedReviewIdempotencyCannotReplayAnotherActorsSave():void
    {
        $scope=$this->service->scope(10,[80],80,7,'weekly_review');
        $saved=$this->service->save($scope,$this->note(),'synthetic_shared_request',7,0);
        $failure=null;
        try{$this->service->save($scope,$this->note(),'synthetic_shared_request',8,0);}
        catch(RuntimeException $error){$failure=$error;}
        self::assertNotNull($failure,'Another actor must not receive an idempotent success');self::assertSame(409,$failure->getCode());
        self::assertSame(1,Db::name(BusinessWorkspaceService::TABLE)->count());
        self::assertSame(7,$this->service->read($scope,$saved['snapshot_id'])['created_by']);
        self::assertTrue($this->service->save($scope,$this->note(),'synthetic_shared_request',7,0)['idempotent']);
    }
    public function testMappingHotelIdentifierCannotBeSilentlyTruncatedIntoThisHotel():void
    {
        $scope=$this->service->scope(10,[80],80,7,'source_mapping');$input=$this->note();
        $input['field_mapping']=['hotel_id'=>'hotel','platform'=>'channel','business_date'=>'date'];
        $saved=$this->service->save($scope,$input,'synthetic_strict_hotel',7,0);
        foreach(['80-other-hotel',80.9,true,'8e1',null]as$hotel){
            $failure=null;
            try{$this->service->mappingPreview($scope,$saved['snapshot_id'],[['hotel'=>$hotel,'channel'=>'ctrip','date'=>'2026-10-02']]);}
            catch(RuntimeException|InvalidArgumentException $error){$failure=$error;}
            self::assertNotNull($failure,'Invalid source hotel must not pass the scope gate');
        }
        foreach([80,'80']as$hotel){
            self::assertSame('reference_only',$this->service->mappingPreview($scope,$saved['snapshot_id'],[['hotel'=>$hotel,'channel'=>'ctrip','date'=>'2026-10-02']])['status']);
        }
        self::assertSame(1,Db::name(BusinessWorkspaceService::TABLE)->count());
    }
    private function note():array{return['period_start'=>'2026-10-01','period_end'=>'2026-10-07','title'=>'合成周经营复盘',
        'review_note'=>'广告数据仍须核对，不以订单流水当利润。','source_ref'=>'synthetic-source#1','source_references'=>['synthetic-plan#1'],'actions'=>[]];}
}
