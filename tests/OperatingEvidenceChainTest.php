<?php
declare(strict_types=1);
use app\service\ChannelEconomicsService;
use app\service\ConsumablesActualCostService;
use app\service\OperatingEvidenceSnapshotStore;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingEvidenceChainTest extends TestCase
{
    private static array $config; private static string $path;
    public static function setUpBeforeClass(): void
    {
        (new App())->initialize(); self::$config = Config::get('database'); self::$path = sys_get_temp_dir() . '/operating-evidence-test-' . bin2hex(random_bytes(5)) . '.sqlite';
        $config = self::$config; $config['default'] = 'sqlite'; $config['connections']['sqlite'] = ['type'=>'sqlite','database'=>self::$path,'prefix'=>'','fields_strict'=>false]; Config::set($config,'database'); Db::connect(null,true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('INSERT INTO hotels VALUES (80,7),(81,8)');
        Db::execute('CREATE TABLE hotel_operating_evidence_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,kind TEXT,period_month TEXT,platform TEXT,payload_json TEXT,content_digest TEXT,idempotency_key TEXT,created_by INTEGER,created_at TEXT,UNIQUE(tenant_id,hotel_id,kind,period_month,platform,idempotency_key))');
    }
    public static function tearDownAfterClass(): void { Db::connect()->close(); Config::set(self::$config,'database'); Db::connect(null,true); if (is_file(self::$path)) unlink(self::$path); }
    private function actual(): array { return ['occupied_room_nights'=>100,'occupied_room_nights_source_ref'=>'synthetic-pms-room-nights','denominator_scope'=>'whole_hotel','operator_attested'=>true,'items'=>[['id'=>'towel','name'=>'测试合成耗材','enabled'=>true,'unit'=>'piece','source_ref'=>'synthetic-count#1','source_date'=>'2026-10-02','opening_quantity'=>30,'purchased_quantity'=>100,'transfer_in_quantity'=>0,'closing_quantity'=>20,'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>10,'unit_price'=>2,'budget_unit_price'=>1.5,'budget_usage_per_room_night'=>0.8]]]; }
    private function channel(): array { return ['net_revenue'=>1000,'advertising_spend'=>100,'attributed_order_amount'=>400,'effective_order_amount'=>1200,'refund_amount'=>50,'attribution_basis'=>'same-day-platform-attribution','advertising_included_in_net_revenue'=>false,'advertising_in_direct_costs'=>false,'cost_coverage_complete'=>true,'operator_attested'=>true,'source_refs'=>['synthetic-monthly-order#1'],'costs'=>[['label'=>'履约','amount'=>200,'source_ref'=>'synthetic-cost#1','included_in_net_revenue'=>false],['label'=>'佣金已扣','amount'=>99,'source_ref'=>'synthetic-settlement#1','included_in_net_revenue'=>true]]]; }
    public function testActualInventoryAndBudgetVarianceAreNotPurchaseCash(): void { $r=(new ConsumablesActualCostService())->calculate($this->actual()); self::assertSame(100.0,$r['items'][0]['consumed_quantity']); self::assertSame(200.0,$r['actual_consumed_cost']); self::assertSame(20.0,$r['separate_loss_cost']); self::assertSame(2.0,$r['actual_consumables_cost_per_room_night']); self::assertSame(50.0,$r['items'][0]['price_variance']); self::assertSame(30.0,$r['items'][0]['usage_variance']); self::assertSame(80.0,$r['items'][0]['total_variance']); }
    public function testMissingInventoryAndRoomNightsNeverBecomeZero(): void { $i=$this->actual(); $i['items'][0]['closing_quantity']=''; $i['occupied_room_nights']=''; $r=(new ConsumablesActualCostService())->calculate($i); self::assertNull($r['actual_consumed_cost']); self::assertNull($r['actual_consumables_cost_per_room_night']); self::assertSame('partial',$r['status']); }
    public function testOtaRoomNightsCannotBecomeHotelDenominator(): void { $i=$this->actual(); $i['denominator_scope']='ota_channel'; $this->expectException(InvalidArgumentException::class); (new ConsumablesActualCostService())->calculate($i); }
    public function testNegativeInventoryBalanceIsExplicitGap(): void { $i=$this->actual(); $i['items'][0]['closing_quantity']=1000; $r=(new ConsumablesActualCostService())->calculate($i); self::assertNull($r['actual_consumed_cost']); self::assertContains('towel:inventory_balance_negative',$r['missing_items']); }
    public function testKnownZeroRemainsZeroAndDisabledItemsDoNotCount(): void { $i=$this->actual(); $i['items'][0]['closing_quantity']=120; $r=(new ConsumablesActualCostService())->calculate($i); self::assertSame(0.0,$r['actual_consumed_cost']); $i['items'][0]['enabled']=false; self::assertNull((new ConsumablesActualCostService())->calculate($i)['actual_consumed_cost']); }
    public function testChannelCostsDeductOnceAndAttributionStaysBounded(): void { $r=(new ChannelEconomicsService())->calculate($this->channel()); self::assertSame(700.0,$r['channel_net_contribution_amount']); self::assertSame(4.0,$r['attributed_roas']); self::assertFalse($r['boundaries']['whole_hotel_profit']); self::assertFalse($r['boundaries']['incremental_ad_effect_established']); }
    public function testBlankOnlyChannelSourceReferencesStayMissingThroughSaveAndReadback(): void {
        $input=$this->channel();$input['source_refs']=['', '   ', "\t\r\n"];
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame('partial',$result['status']);self::assertNull($result['channel_net_contribution_amount']);
        self::assertSame(700.0,$result['known_costs_contribution_amount']);self::assertSame([],$result['inputs']['source_refs']);
        self::assertContains('manual_source_refs_missing',$result['missing_items']);
        $store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']],'blank-source-reference-proof',1);
        $read=$store->read($scope,$saved['snapshot_id']);self::assertTrue($read['readback_verified']);self::assertSame($saved['result'],$read['result']);
        self::assertSame('partial',$read['result']['status']);self::assertNull($read['result']['channel_net_contribution_amount']);
        self::assertSame([],$read['result']['inputs']['source_refs']);self::assertContains('manual_source_refs_missing',$read['result']['missing_items']);
    }
    public function testMixedChannelSourceReferencesKeepOnlyDistinctNonblankEvidence(): void {
        $input=$this->channel();$input['source_refs']=[' ', ' synthetic-monthly-order#1 ', '', 'synthetic-monthly-order#1'];
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(['synthetic-monthly-order#1'],$result['inputs']['source_refs']);self::assertSame('calculated',$result['status']);
        self::assertSame(700.0,$result['channel_net_contribution_amount']);self::assertSame([],$result['missing_items']);
    }
    public function testIncompleteCostOnlyProducesPartialContribution(): void { $i=$this->channel(); $i['cost_coverage_complete']=false; $r=(new ChannelEconomicsService())->calculate($i); self::assertNull($r['channel_net_contribution_amount']); self::assertSame(700.0,$r['known_costs_contribution_amount']); }
    public function testAlreadyDeductedAdvertisingIsNotDeductedAgain(): void { $i=$this->channel(); $i['advertising_included_in_net_revenue']=true; self::assertSame(800.0,(new ChannelEconomicsService())->calculate($i)['channel_net_contribution_amount']); }
    public function testUntrustedClientSourcesCannotOverrideManualInput(): void { $i=$this->channel(); $r=(new ChannelEconomicsService())->calculate($i,['settlement'=>['readback_verified'=>false,'basis_ledger'=>['components'=>['net_revenue'=>['value'=>9999]]]]]); self::assertSame(1000.0,$r['net_revenue']); }
    public function testImmutableSaveExactReadbackAndIdempotency(): void { $store=new OperatingEvidenceSnapshotStore(); $scope=$store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual'); $result=(new ConsumablesActualCostService())->calculate($this->actual()); $r=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>'operator_attested'],'request-test-123',1); self::assertTrue($r['readback_verified']); self::assertSame(2,(int)$r['result']['actual_consumables_cost_per_room_night']); self::assertSame($r['snapshot_id'],$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>'operator_attested'],'request-test-123',1)['snapshot_id']); self::assertSame($r['content_digest'],$store->read($scope,$r['snapshot_id'])['content_digest']); }
    public function testIdempotencyDoesNotOverwriteChanges(): void { $s=new OperatingEvidenceSnapshotStore(); $scope=$s->scope(7,[80],80,'2026-10','ctrip','channel_economics'); $s->save($scope,['value'=>1],'request-conflict',1); $this->expectException(RuntimeException::class); $s->save($scope,['value'=>2],'request-conflict',1); }
    public function testCrossTenantHotelIsRejected(): void { $this->expectException(RuntimeException::class); (new OperatingEvidenceSnapshotStore())->scope(7,[80,81],81,'2026-10','whole_hotel','consumables_actual'); }
    public function testTamperedSavedPayloadFailsReadback(): void { $s=new OperatingEvidenceSnapshotStore(); $scope=$s->scope(7,[80],80,'2026-10','ctrip','channel_economics'); $r=$s->save($scope,['value'=>1],'request-integrity',1); Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$r['snapshot_id'])->update(['payload_json'=>'{}']); $this->expectException(RuntimeException::class); $s->read($scope,$r['snapshot_id']); }
    public function testStaleAndUnverifiedReceiptsNeverReplaceManualAmount(): void {
        $receipt=['readback_verified'=>true,'projection_status'=>'latest_non_invalid_with_newer_invalid_attempt','latest_attempt'=>['batch_status'=>'invalid'],'source'=>['source_quality_status'=>'operator_attested'],'basis_ledger'=>['components'=>['net_revenue'=>['value'=>9999]]]];
        $service=new ChannelEconomicsService(); self::assertSame(1000.0,$service->calculate($this->channel(),['settlement'=>$receipt])['net_revenue']);
        $receipt['projection_status']='latest_attempt';$receipt['latest_attempt']['batch_status']='available';$receipt['source']['source_quality_status']='unverified'; self::assertSame(1000.0,$service->calculate($this->channel(),['settlement'=>$receipt])['net_revenue']);
        $receipt['source']['source_quality_status']='operator_attested';self::assertSame(9999.0,$service->calculate($this->channel(),['settlement'=>$receipt])['net_revenue']);
    }
    public function testCompleteMarketingKeepsItsOwnAttributionBasis(): void {
        $input=$this->channel();$input['attribution_basis']='';$result=(new ChannelEconomicsService())->calculate($input,['marketing'=>['complete'=>true,'advertising_spend'=>100.0,'attributed_order_amount'=>400.0,'attribution_basis'=>'same-platform-window']]);self::assertSame(4.0,$result['attributed_roas']);self::assertSame('same-platform-window',$result['inputs']['attribution_basis']);
    }
    public function testCostProductOverflowIsRejectedBeforeSave(): void { $input=$this->actual();$input['items'][0]['unit_price']=1e12;$this->expectException(InvalidArgumentException::class);(new ConsumablesActualCostService())->calculate($input); }
    public function testAggregateChannelOverflowIsRejected(): void { $input=$this->channel();$input['costs'][0]['amount']=1e12;$this->expectException(InvalidArgumentException::class);(new ChannelEconomicsService())->calculate($input); }
    public function testMissingHotelDenominatorSourceKeepsTotalButNotPerNightReference(): void { $input=$this->actual();$input['occupied_room_nights_source_ref']='';$r=(new ConsumablesActualCostService())->calculate($input);self::assertSame(200.0,$r['actual_consumed_cost']);self::assertNull($r['actual_consumables_cost_per_room_night']);self::assertSame('partial',$r['status']); }
    public function testTinyAdvertisingDenominatorCannotCreateInfiniteRoas(): void { $input=$this->channel();$input['advertising_spend']=1e-320;$this->expectException(InvalidArgumentException::class);(new ChannelEconomicsService())->calculate($input); }
    public function testTinyRoomNightDenominatorCannotCreateInfiniteUnitCost(): void { $input=$this->actual();$input['occupied_room_nights']=1e-320;$this->expectException(InvalidArgumentException::class);(new ConsumablesActualCostService())->calculate($input); }
    public function testAuthorizedHotelRenamePreservesImmutableEvidenceAndExactReadback(): void {
        $store=new OperatingEvidenceSnapshotStore();$oldScope=$store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual');$result=(new ConsumablesActualCostService())->calculate($this->actual());$saved=$store->save($oldScope,['inputs'=>$result['inputs'],'result'=>$result],'request-rename-proof',1);
        $before=Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$saved['snapshot_id'])->find();
        Db::name('hotels')->where('id',80)->update(['id'=>880]);Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('hotel_id',80)->update(['hotel_id'=>880]);
        try { $newScope=$store->scope(7,[880],880,'2026-10','whole_hotel','consumables_actual');$read=$store->read($newScope,$saved['snapshot_id']);self::assertSame(880,$read['scope']['hotel_id']);self::assertSame(80,$read['source_scope']['hotel_id']);self::assertSame($saved['content_digest'],$read['content_digest']);self::assertTrue($read['readback_verified']);self::assertSame($before['payload_json'],Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$saved['snapshot_id'])->value('payload_json')); }
        finally { Db::name('hotels')->where('id',880)->update(['id'=>80]);Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('hotel_id',880)->update(['hotel_id'=>80]); }
    }
}
