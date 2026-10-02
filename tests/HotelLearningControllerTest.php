<?php
declare(strict_types=1);
use app\service\OperatingEvidenceSnapshotStore;
use app\service\ActualConsumablesScenarioReferenceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;
require_once __DIR__.'/fixtures/hotel-learning/bootstrap.php';

final class HotelLearningControllerTest extends TestCase
{
    private static string $path; private static array $config;
    public static function setUpBeforeClass(): void { self::$path=sys_get_temp_dir().'/hotel-learning-controller-'.bin2hex(random_bytes(6)).'.sqlite';self::$config=HotelLearningSyntheticEnvironment::connect(self::$path); }
    public static function tearDownAfterClass(): void { HotelLearningSyntheticEnvironment::$app->request->user=null;Db::connect()->close();Config::set(self::$config,'database');Db::connect(null,true);if(is_file(self::$path))unlink(self::$path); }
    public static function modes(): array { return array_map(static fn($m)=>[$m],array_keys(\app\service\HotelLearningMechanismService::KINDS)); }
    private function request(string $mode): array { return HotelLearningSyntheticEnvironment::scope($mode)+['inputs'=>HotelLearningSyntheticEnvironment::inputs($mode),'idempotency_key'=>'synthetic-'.bin2hex(random_bytes(8))]; }
    private function call(string $action,array $payload,int $id=0,bool $authenticated=true):array { return HotelLearningSyntheticEnvironment::dispatch($action,$payload,$id,$authenticated); }

    #[DataProvider('modes')]
    public function testEachModePreviewsSavesAndReadsExactVersion(string $mode): void {
        $payload=$this->request($mode);$preview=$this->call('preview',$payload);self::assertSame(200,$preview['http_status'],json_encode($preview));self::assertFalse($preview['body']['data']['readback_verified']);
        $saved=$this->call('save',$payload);self::assertSame(200,$saved['http_status'],json_encode($saved));$data=$saved['body']['data'];self::assertTrue($data['readback_verified']);
        $read=$this->call('read',$payload,$data['snapshot_id']);self::assertSame(200,$read['http_status']);self::assertSame($data['content_digest'],$read['body']['data']['content_digest']);self::assertSame($data['inputs'],$read['body']['data']['inputs']);self::assertSame($mode,$read['body']['data']['scope']['mode']);
        $again=$this->call('save',$payload);self::assertSame($data['snapshot_id'],$again['body']['data']['snapshot_id']);self::assertTrue($again['body']['data']['idempotent']);
    }
    public function testUnauthenticatedUserCannotReadOrWrite():void { foreach(['overview','preview','save','read'] as $action)self::assertSame(401,$this->call($action,$this->request('profile'),1,false)['http_status']); }
    public function testPermittedSuperAdminStillCannotCrossTenant():void { $p=$this->request('profile');$p['hotel_id']=81;self::assertSame(403,$this->call('save',$p)['http_status']); }
    public function testSameTenantOtherHotelAndOtherMonthCannotReadSavedVersion():void { $p=$this->request('profile');$id=$this->call('save',$p)['body']['data']['snapshot_id'];$other=$p;$other['hotel_id']=82;self::assertSame(404,$this->call('read',$other,$id)['http_status']);$other=$p;$other['period_month']='2026-09';self::assertSame(404,$this->call('read',$other,$id)['http_status']); }
    public function testModeAndPlatformCannotReadAnotherScope():void { $p=$this->request('ota_scene');$id=$this->call('save',$p)['body']['data']['snapshot_id'];$p['platform']='meituan';self::assertSame(404,$this->call('read',$p,$id)['http_status']);$p['platform']='ctrip';$p['mode']='market_sample';self::assertSame(404,$this->call('read',$p,$id)['http_status']); }
    public function testDateMustMatchSelectedMonthAndInvalidTimeCannotNormalize():void { $p=$this->request('ota_scene');$p['inputs']['scene']['observed_at']='2026-09-30T12:00';self::assertSame(422,$this->call('save',$p)['http_status']);$p['inputs']['scene']['observed_at']='2026-10-02T12:99';self::assertSame(422,$this->call('save',$p)['http_status']); }
    public function testInvalidModeOrHotelDoesNotBecomeDefaultScope():void { $p=$this->request('profile');$p['mode']='unknown';self::assertSame(422,$this->call('preview',$p)['http_status']);$p=$this->request('profile');$p['hotel_id']='80abc';self::assertSame(422,$this->call('preview',$p)['http_status']); }
    public function testRetryCannotOverwriteDifferentInput():void { $p=$this->request('profile');self::assertSame(200,$this->call('save',$p)['http_status']);$p['inputs']['fields'][0]['value']='61';self::assertSame(409,$this->call('save',$p)['http_status']); }
    public function testUnknownRateUnitAndUnvalidatedCostReferenceAreRejected():void { $p=$this->request('ota_scene');unset($p['inputs']['rate_unit']);self::assertSame(422,$this->call('save',$p)['http_status']);$p=$this->request('investment_target');$p['inputs']['scenario']['cost_evidence_snapshot_id']=999;self::assertSame(422,$this->call('save',$p)['http_status']); }
    public function testNewConsumablesVersionCanUseExistingReadAndInvestmentAdoptionPath():void {
        $p=$this->request('consumables_reconciliation');$saved=$this->call('save',$p)['body']['data'];$store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual');$old=$store->read($scope,$saved['snapshot_id']);self::assertSame($saved['content_digest'],$old['content_digest']);self::assertSame(200,$old['result']['actual_consumed_cost']);self::assertSame(4,$old['result']['reconciliation']['inventory_balance_cost_per_cleaning']);
        $input=['cost_evidence_snapshot_id'=>$saved['snapshot_id'],'cost_evidence_digest'=>$saved['content_digest'],'cost_evidence_confirmed'=>true,'consumables_cost'=>['mode'=>'derived','items'=>[['id'=>'actual-evidence-'.$saved['snapshot_id'],'enabled'=>true,'unit'=>'piece','usage_basis'=>'occupied_room_night','package_price'=>2,'package_quantity'=>1,'usage_quantity'=>1,'occurrences_per_occupied_night'=>1]]]];
        $validated=(new ActualConsumablesScenarioReferenceService())->validate(7,80,$input);self::assertSame('2026-10-02',$validated['consumables_cost']['items'][0]['as_of']);self::assertStringContainsString((string)$saved['snapshot_id'],$validated['consumables_cost']['items'][0]['source_label']);
    }
    public function testLegacyActualCostVersionIsReadableWithoutInventingReconciliation():void {
        $input=HotelLearningSyntheticEnvironment::inputs('consumables_reconciliation');$result=(new \app\service\ConsumablesActualCostService())->calculate($input);$store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual');$saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']],'synthetic-legacy-'.bin2hex(random_bytes(4)),7);$read=$this->call('read',HotelLearningSyntheticEnvironment::scope('consumables_reconciliation'),$saved['snapshot_id']);self::assertSame(200,$read['http_status']);self::assertArrayNotHasKey('reconciliation',$read['body']['data']['result']);self::assertSame($saved['content_digest'],$read['body']['data']['content_digest']);
    }
    public function testOverviewReturnsPermissionAndMissingState():void { $p=HotelLearningSyntheticEnvironment::scope('profile');$p['period_month']='2025-01';$r=$this->call('overview',$p);self::assertSame(200,$r['http_status']);self::assertTrue($r['body']['data']['can_execute']);self::assertSame('missing',$r['body']['data']['latest']['status']);self::assertFalse($r['body']['data']['latest']['readback_verified']); }

    public function testMissingPriceSavesAsPartialAndExactReadbackNeverClaimsComparable(): void
    {
        $payload = $this->request('ota_scene'); unset($payload['inputs']['price']);
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id']);
        self::assertSame('partial', $read['body']['data']['status']);
        self::assertFalse($read['body']['data']['result']['comparison_ready']);
        self::assertNull($read['body']['data']['result']['comparison_key']);
        self::assertNull($read['body']['data']['inputs']['price']);
        self::assertSame($saved['body']['data']['content_digest'], $read['body']['data']['content_digest']);
    }

    public function testInvalidCountingOrNumericOverflowReturns422WithoutSaving(): void
    {
        $fractional = $this->request('ota_scene'); $fractional['inputs']['rank_min'] = 3.5;
        $ratio = $this->request('investment_target');
        $ratio['inputs']['scenario']['occupancy_mature'] = 1e-320;
        $ratio['inputs']['request'] = ['solve_for'=>'occupancy','occupancy_shape'=>'preserve_ratio','target_payback_months'=>12,'bounds'=>['lower'=>0,'upper'=>1]];
        $calendar = $this->request('contract_review'); $calendar['period_month']='9999-12';
        $calendar['inputs'] = ['as_of'=>'9999-12-31','payback_months'=>1,'constraints'=>['target_payback_months'=>null,'contract_start_on'=>'9999-01-01','contract_end_on'=>'9999-12-31','contract_source'=>'synthetic-lease','contract_confirmed'=>true]];
        $precision = $this->request('consumables_reconciliation');
        $precision['inputs']['items'][0] = array_replace($precision['inputs']['items'][0], ['opening_quantity'=>0,'purchased_quantity'=>0,'closing_quantity'=>1e-7,'book_closing_quantity'=>1e-7,'unit_price'=>1e12,'written_off_quantity'=>0,'issued_quantity'=>0,'budget_unit_price'=>null,'budget_usage_per_room_night'=>null]);
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach ([$fractional, $ratio, $calendar, $precision] as $payload) {
            self::assertSame(422, $this->call('save', $payload)['http_status']);
        }
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testBlankReviewBasisPersistsUnknownComparisonAndNullDifferences(): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs']['actual']['basis'] = $payload['inputs']['plan']['basis'] = '   ';
        $saved = $this->call('save', $payload); self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertSame('', $read['inputs']['actual']['basis']);
        self::assertSame('partial', $read['status']);
        self::assertFalse($read['result']['scope_aligned']);
        self::assertNull($read['result']['actual_cost_ratio']);
        foreach ($read['result']['rows'] as $row) self::assertNull($row['difference']);
    }
}
