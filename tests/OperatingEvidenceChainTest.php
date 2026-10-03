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
    public function testKnownConsumablesCostDistinguishesMissingZeroAndPartialEvidence(): void
    {
        $base = $this->actual(); $row = $base['items'][0];
        $missing = array_replace($row, ['id' => 'missing', 'closing_quantity' => '']);
        $zero = array_replace($row, ['id' => 'zero', 'closing_quantity' => 120]);
        $disabled = array_replace($row, ['id' => 'disabled', 'enabled' => false]);
        $cases = [
            'all_missing' => [[$missing], null, null],
            'empty_rows' => [[], null, null],
            'disabled_only' => [[$disabled], null, null],
            'disabled_with_missing' => [[$disabled, $missing], null, null],
            'real_zero' => [[$zero], 0.0, 0.0],
            'partial_with_known_cost' => [[$row, $missing], 200.0, null],
            'partial_with_known_zero' => [[$zero, $missing], 0.0, null],
        ];
        $service = new ConsumablesActualCostService();
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(7, [80], 80, '2026-10', 'whole_hotel', 'consumables_actual');
        foreach ($cases as $name => [$rows, $known, $total]) {
            $result = $service->calculate(array_replace($base, ['items' => $rows]));
            self::assertSame($known, $result['known_consumed_cost'], $name);
            self::assertSame($total, $result['actual_consumed_cost'], $name);
            self::assertSame($total === null ? 'partial' : 'calculated', $result['status'], $name);
            self::assertSame($result, $service->calculate($result['inputs']), $name);
            $saved = $store->save($scope, ['inputs' => $result['inputs'], 'result' => $result,
                'source_quality' => $result['source_quality']], 'consumables-known-' . $name, 1);
            $read = $store->read($scope, $saved['snapshot_id']);
            self::assertTrue($read['readback_verified']);
            self::assertSame($saved['result'], $read['result']);
            self::assertSame($saved['content_digest'], $read['content_digest']);
            self::assertSame($known, $read['result']['known_consumed_cost'] === null ? null : (float)$read['result']['known_consumed_cost'], $name);
        }
    }
    public function testSubPrecisionPositiveConsumptionRetainsCostAndVarianceAfterNormalization(): void
    {
        $input = $this->actual();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity' => 0,
            'purchased_quantity' => '0.0000004', 'closing_quantity' => 0, 'written_off_quantity' => 0,
            'unit_price' => '1000000', 'budget_unit_price' => 500000, 'budget_usage_per_room_night' => 0]);
        $service = new ConsumablesActualCostService(); $result = $service->calculate($input);
        self::assertSame(0.0000004, $result['items'][0]['consumed_quantity']);
        self::assertSame(0.4, $result['actual_consumed_cost']);
        self::assertSame(0.4, $result['known_consumed_cost']);
        self::assertSame(0.004, $result['actual_consumables_cost_per_room_night']);
        self::assertSame(0.2, $result['items'][0]['price_variance']);
        self::assertSame(0.2, $result['items'][0]['usage_variance']);
        self::assertSame(0.4, $result['items'][0]['total_variance']);
        self::assertSame('calculated', $result['status']);
        self::assertSame($result, $service->calculate($result['inputs']));
    }
    public function testDecimalZeroInventoryBalancesRemainZeroAfterNormalization(): void
    {
        $base = $this->actual(); $service = new ConsumablesActualCostService();
        foreach ([[0.1, 0.2, 0.3, 0], [0.3, 0, 0.1, 0.2]] as [$opening, $purchased, $closing, $transferOut]) {
            $input = $base;
            $input['items'][0] = array_replace($input['items'][0], ['opening_quantity' => $opening,
                'purchased_quantity' => $purchased, 'closing_quantity' => $closing,
                'transfer_out_quantity' => $transferOut, 'written_off_quantity' => 0, 'unit_price' => 1000000]);
            $result = $service->calculate($input);
            self::assertSame(0.0, $result['items'][0]['consumed_quantity']);
            self::assertSame(0.0, $result['actual_consumed_cost']);
            self::assertSame(0.0, $result['known_consumed_cost']);
            self::assertSame(0.0, $result['actual_consumables_cost_per_room_night']);
            self::assertSame('calculated', $result['status']);
            self::assertSame($result, $service->calculate($result['inputs']));
        }
    }
    public function testTinyRealDeficitBesideDecimalCancellationDoesNotBecomeKnownZero(): void
    {
        $input = $this->actual();
        $input['items'][0] = array_replace($input['items'][0], ['opening_quantity' => 0.3,
            'purchased_quantity' => 0, 'closing_quantity' => 0.1, 'transfer_out_quantity' => 0.2,
            'returned_quantity' => 1e-20, 'written_off_quantity' => 0, 'unit_price' => 2]);
        $service = new ConsumablesActualCostService(); $result = $service->calculate($input);
        self::assertSame('partial', $result['status']);
        self::assertNull($result['items'][0]['consumed_quantity']);
        self::assertNull($result['actual_consumed_cost']);
        self::assertNull($result['known_consumed_cost']);
        self::assertContains('inventory_balance_negative', $result['items'][0]['missing_items']);
        self::assertSame($result, $service->calculate($result['inputs']));
    }
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
    public function testAdvertisingDirectCostClaimRequiresExplicitEqualAmountEvidence(): void
    {
        $invalid = [[], [['label'=>'普通成本','amount'=>100,'source_ref'=>'synthetic-cost','included_in_net_revenue'=>false]],
            [['label'=>'广告','cost_type'=>'advertising','amount'=>99,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>false]],
            [['label'=>'广告','cost_type'=>'advertising','amount'=>100.001,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>false]],
            [['label'=>'广告','cost_type'=>'advertising','amount'=>100,'source_ref'=>'','included_in_net_revenue'=>false]],
            [['label'=>'广告','cost_type'=>'advertising','amount'=>null,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>false]],
            [['label'=>'广告','cost_type'=>'advertising','amount'=>100,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>true]]];
        foreach ($invalid as $costs) {
            $input=$this->channel();$input['costs']=$costs;$input['advertising_in_direct_costs']=true;
            try { (new ChannelEconomicsService())->calculate($input); self::fail('An advertising inclusion claim without complete equal-cost evidence must be rejected'); }
            catch (InvalidArgumentException $e) { self::assertSame('channel_advertising_direct_cost_evidence_required',$e->getMessage()); }
        }
        $input=$this->channel();$input['advertising_in_direct_costs']=true;$input['advertising_spend']=null;
        $input['costs']=[['label'=>'广告','cost_type'=>'advertising','amount'=>100,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>false]];
        $this->expectExceptionMessage('channel_advertising_direct_cost_evidence_required');
        (new ChannelEconomicsService())->calculate($input);
    }
    public function testExplicitAdvertisingDirectCostsDeductExactlyOnceAndSurviveReadback(): void
    {
        $input=$this->channel();$input['advertising_in_direct_costs']=true;
        $input['costs'][]=['label'=>'广告A','cost_type'=>'advertising','amount'=>40,'source_ref'=>'synthetic-ad-A','included_in_net_revenue'=>false];
        $input['costs'][]=['label'=>'广告B','cost_type'=>'advertising','amount'=>60,'source_ref'=>'synthetic-ad-B','included_in_net_revenue'=>false];
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(300.0,$result['known_direct_cost']);self::assertSame(700.0,$result['channel_net_contribution_amount']);
        self::assertSame('direct',$result['inputs']['costs'][0]['cost_type']);
        $store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-02','meituan','channel_economics');
        $saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result],'explicit-advertising-direct-cost-proof',1);
        $read=$store->read($scope,$saved['snapshot_id']);
        self::assertTrue($read['readback_verified']);self::assertSame('advertising',$read['inputs']['costs'][2]['cost_type']);
        self::assertSame(700.0,(float)$read['result']['channel_net_contribution_amount']);
    }
    public function testExplicitAdvertisingRowsCannotBeDeductedAlongsideTheSeparateAdvertisingAmount(): void
    {
        $input=$this->channel();$input['costs'][]=['label'=>'广告','cost_type'=>'advertising','amount'=>100,'source_ref'=>'synthetic-ad','included_in_net_revenue'=>false];
        $this->expectExceptionMessage('channel_advertising_cost_classification_conflict');
        (new ChannelEconomicsService())->calculate($input);
    }
    public function testAdvertisingEqualAmountProofAllowsOnlyFloatingPointAdditionError(): void
    {
        $input=$this->channel();$input['advertising_spend']=0.3;$input['advertising_in_direct_costs']=true;
        $input['costs']=[['label'=>'广告A','cost_type'=>'advertising','amount'=>0.1,'source_ref'=>'synthetic-ad-A','included_in_net_revenue'=>false],
            ['label'=>'广告B','cost_type'=>'advertising','amount'=>0.2,'source_ref'=>'synthetic-ad-B','included_in_net_revenue'=>false]];
        self::assertSame(999.7,(new ChannelEconomicsService())->calculate($input)['channel_net_contribution_amount']);
        $input['advertising_spend']=1e-20;$input['costs'][0]['amount']=0;$input['costs'][1]['amount']=0;
        $this->expectExceptionMessage('channel_advertising_direct_cost_evidence_required');
        (new ChannelEconomicsService())->calculate($input);
    }
    public function testHighVolumeSettlementReceiptSavesAsAnExactCompactSnapshot(): void
    {
        $line=['source_line_no'=>1,'line_fingerprint'=>str_repeat('b',64),'order_reference_hash'=>str_repeat('c',64),
            'gross_amount'=>100.0,'net_revenue'=>90.0,'source_ref'=>'synthetic-large-month-settlement-line','quality_status'=>'available'];
        $receipt=['contract_version'=>'ota_settlement_reconciliation.v1','batch_id'=>77,'batch_fingerprint'=>str_repeat('a',64),
            'readback_verified'=>true,'projection_status'=>'latest_attempt','latest_attempt'=>['batch_id'=>77,'batch_status'=>'available'],
            'scope'=>['tenant_id'=>7,'hotel_id'=>80,'platform'=>'ctrip','period_start'=>'2026-02-01','period_end'=>'2026-02-28'],
            'source'=>['source_quality_status'=>'verified_export','source_method'=>'manual_verified_export','file_sha256'=>str_repeat('d',64)],
            'counts'=>['line_count'=>5000,'available'=>5000,'partial'=>0,'invalid'=>0],
            'totals'=>['net_revenue'=>['value'=>450000.0,'basis'=>'complete_source_direct']],
            'basis_ledger'=>['components'=>['net_revenue'=>['value'=>450000.0,'basis'=>'complete_source_direct']]],
            'lines'=>array_fill(0,5000,$line),'ranked_discrepancies'=>array_fill(0,5000,$line)];
        self::assertGreaterThan(200000,strlen(json_encode($receipt,JSON_THROW_ON_ERROR)));
        $result=(new ChannelEconomicsService())->calculate($this->channel(),['settlement'=>$receipt,'period_closed'=>true]);
        $compact=$result['source_receipts']['settlement'];
        self::assertArrayNotHasKey('lines',$compact);self::assertArrayNotHasKey('ranked_discrepancies',$compact);
        self::assertSame(77,$compact['batch_id']);self::assertSame(str_repeat('a',64),$compact['batch_fingerprint']);
        self::assertSame(5000,$compact['counts']['line_count']);self::assertSame($receipt['scope'],$compact['scope']);
        self::assertSame(['ota_settlement_import_batches#77'],$compact['evidence_refs']);self::assertSame(450000.0,$result['net_revenue']);
        $store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-02','ctrip','channel_economics');
        $saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result],'high-volume-settlement-compact-proof',1);
        $read=$store->read($scope,$saved['snapshot_id']);
        self::assertTrue($read['readback_verified']);self::assertSame($saved['content_digest'],$read['content_digest']);
        self::assertEquals($compact,$read['result']['source_receipts']['settlement']);
        self::assertSame(449700.0,(float)$read['result']['channel_net_contribution_amount']);
    }
    public function testUntrustedClientSourcesCannotOverrideManualInput(): void { $i=$this->channel(); $r=(new ChannelEconomicsService())->calculate($i,['settlement'=>['readback_verified'=>false,'basis_ledger'=>['components'=>['net_revenue'=>['value'=>9999]]]]]); self::assertSame(1000.0,$r['net_revenue']); }
    public function testImmutableSaveExactReadbackAndIdempotency(): void { $store=new OperatingEvidenceSnapshotStore(); $scope=$store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual'); $result=(new ConsumablesActualCostService())->calculate($this->actual()); $r=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>'operator_attested'],'request-test-123',1); self::assertTrue($r['readback_verified']); self::assertSame(2,(int)$r['result']['actual_consumables_cost_per_room_night']); self::assertSame($r['snapshot_id'],$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>'operator_attested'],'request-test-123',1)['snapshot_id']); self::assertSame($r['content_digest'],$store->read($scope,$r['snapshot_id'])['content_digest']); }
    private function legacySnapshot(array $scope, array $result, string $key, bool $compact = false): array
    {
        $payload = ['contract_version'=>'operating_evidence.v1','scope'=>$scope,'inputs'=>$result['inputs'],
            'result'=>$result,'status'=>$result['status'],'source_quality'=>$result['source_quality']];
        if ($compact) {
            $payload['result']['inputs'] = ['$ref'=>'inputs'];
            $payload['result']['items'] = ['$ref'=>'inputs.items'];
            $payload['_storage_encoding'] = 'consumables_inputs_once.v1';
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id = (int)Db::name(OperatingEvidenceSnapshotStore::TABLE)->insertGetId($scope + [
            'source_hotel_id'=>$scope['hotel_id'],'payload_json'=>$json,'content_digest'=>hash('sha256',$json),
            'idempotency_key'=>$key,'created_by'=>1,'created_at'=>'2026-10-02 12:00:00']);
        return ['id'=>$id,'json'=>$json,'digest'=>hash('sha256',$json)];
    }
    private function assertLegacyConflict(OperatingEvidenceSnapshotStore $store, array $scope, string $key, array $input): void
    {
        $conflict = false;
        try { $store->replayRequest($scope,$key,$input); }
        catch (RuntimeException $error) {
            $conflict = true;
            self::assertSame('operating_evidence_idempotency_conflict',$error->getMessage());
            self::assertSame(409,$error->getCode());
        }
        self::assertTrue($conflict,'Changed or unprovable legacy inputs must conflict');
    }
    public function testLegacyChannelLostResponseReplaysOriginalVersionDespiteNewEvidenceFieldsAndSourceDrift(): void
    {
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $input = $this->channel(); $result = (new ChannelEconomicsService())->calculate($input);
        unset($result['inputs']['evidence_refs_by_metric'],$result['evidence_chain']);
        foreach ($result['inputs']['costs'] as &$cost) unset($cost['cost_type']); unset($cost);
        $result['inputs']['source_refs'] = ['synthetic-monthly-order#1'];
        $result['source_receipts']['settlement'] = ['batch_id'=>55,'lines'=>[['net_revenue'=>1000]],'content_digest'=>'original-source'];
        $legacy = $this->legacySnapshot($scope,$result,'legacy-channel-response-loss');
        // Current calculation has new metric evidence/defaults and a newly collected amount.
        $fresh = (new ChannelEconomicsService())->calculate($input,['settlement'=>[
            'readback_verified'=>true,'projection_status'=>'latest_attempt','latest_attempt'=>['batch_status'=>'available'],
            'source'=>['source_quality_status'=>'operator_attested'],'basis_ledger'=>['components'=>['net_revenue'=>['value'=>9000]]]]]);
        self::assertSame(9000.0,$fresh['net_revenue']);
        $replay = $store->replayRequest($scope,'legacy-channel-response-loss',$input);
        self::assertNotNull($replay); self::assertSame($legacy['id'],$replay['snapshot_id']);
        self::assertSame($legacy['digest'],$replay['content_digest']); self::assertTrue($replay['idempotent']);
        self::assertSame(1000,$replay['result']['net_revenue']); self::assertArrayNotHasKey('evidence_chain',$replay['result']);
        self::assertArrayNotHasKey('request_digest',$replay);
        self::assertSame($legacy['json'],Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$legacy['id'])->value('payload_json'));
        // The store's transactional/recovery replay must use the original request too.
        $again = $store->save($scope,['inputs'=>$fresh['inputs'],'result'=>$fresh],'legacy-channel-response-loss',1,$input);
        self::assertSame($legacy['id'],$again['snapshot_id']); self::assertSame($legacy['digest'],$again['content_digest']);
    }
    public function testLegacyChannelRequestComparisonRejectsChangedMissingAndUnknownInputs(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','meituan','channel_economics');
        $input = $this->channel(); $input['refund_amount'] = 0;
        $result = (new ChannelEconomicsService())->calculate($input);
        unset($result['inputs']['evidence_refs_by_metric'],$result['evidence_chain']);
        foreach ($result['inputs']['costs'] as &$cost) unset($cost['cost_type']); unset($cost);
        $legacy = $this->legacySnapshot($scope,$result,'legacy-channel-conflict-proof');
        $equivalent = $input; $equivalent['net_revenue'] = '1000.00'; $equivalent['source_refs'] = [' synthetic-monthly-order#1 ','synthetic-monthly-order#1'];
        self::assertSame($legacy['id'],$store->replayRequest($scope,'legacy-channel-conflict-proof',$equivalent)['snapshot_id']);
        foreach (['net_revenue'=>0,'refund_amount'=>null,'operator_attested'=>false,'unknown_future_input'=>null,
            'evidence_refs_by_metric'=>['net_revenue'=>['new-source']]] as $field=>$value) {
            $changed = $input; $changed[$field] = $value; $this->assertLegacyConflict($store,$scope,'legacy-channel-conflict-proof',$changed);
        }
        foreach (['source_ref'=>'changed-source','amount'=>201,'cost_type'=>'advertising','included_in_net_revenue'=>true,'unknown_cost_field'=>null] as $field=>$value) {
            $changed = $input; $changed['costs'][0][$field] = $value; $this->assertLegacyConflict($store,$scope,'legacy-channel-conflict-proof',$changed);
        }
        self::assertSame($legacy['json'],Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$legacy['id'])->value('payload_json'));
    }
    public function testLegacyFullAndCompactConsumablesRequestsReplayWithoutRecalculatingStoredFacts(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','whole_hotel','consumables_actual');
        $input = $this->actual(); $input['items'][0]['budget_unit_price'] = 0;
        $result = (new ConsumablesActualCostService())->calculate($input);
        // Historical arithmetic is immutable even if a later calculator changes rounding.
        $result['actual_consumed_cost'] = 199.99;
        foreach ([false,true] as $compact) {
            $key = 'legacy-consumables-'.($compact ? 'compact' : 'full'); $legacy = $this->legacySnapshot($scope,$result,$key,$compact);
            $equivalent = $input; $equivalent['occupied_room_nights'] = '100'; $equivalent['items'][0]['unit_price'] = '2.00';
            $read = $store->replayRequest($scope,$key,$equivalent);
            self::assertNotNull($read); self::assertSame($legacy['id'],$read['snapshot_id']); self::assertSame($legacy['digest'],$read['content_digest']);
            self::assertSame(199.99,$read['result']['actual_consumed_cost']); self::assertSame($read['inputs'],$read['result']['inputs']);
            self::assertSame($legacy['id'],$store->replayRequest($scope,$key,$read['inputs'])['snapshot_id']);
            foreach (['budget_unit_price'=>null,'source_ref'=>'changed-inventory','enabled'=>false,'unknown_item_field'=>null,'consumed_cost'=>999] as $field=>$value) {
                $changed = $input; $changed['items'][0][$field] = $value; $this->assertLegacyConflict($store,$scope,$key,$changed);
            }
            $changed = $input; $changed['operator_attested'] = false; $this->assertLegacyConflict($store,$scope,$key,$changed);
            self::assertSame($legacy['json'],Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$legacy['id'])->value('payload_json'));
        }
    }
    public function testLegacyAutomaticallyReplacedClientValuesAreNotGuessedFromLaterSources(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $input = $this->channel(); $result = (new ChannelEconomicsService())->calculate($input);
        $result['inputs']['net_revenue'] = 3210; $result['net_revenue'] = 3210;
        $legacy = $this->legacySnapshot($scope,$result,'legacy-unrecoverable-client-input');
        $this->assertLegacyConflict($store,$scope,'legacy-unrecoverable-client-input',$input);
        self::assertSame($legacy['digest'],$store->read($scope,$legacy['id'])['content_digest']);
    }
    public function testLegacyReplayDoesNotRecalculatePreviouslyAcceptedAdvertisingRules(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $input = $this->channel(); $result = (new ChannelEconomicsService())->calculate($input);
        $input['advertising_in_direct_costs'] = true; $input['costs'] = [];
        $result['inputs']['advertising_in_direct_costs'] = true; $result['inputs']['costs'] = [];
        unset($result['inputs']['evidence_refs_by_metric'],$result['evidence_chain']);
        $result['channel_net_contribution_amount'] = 1000;
        $legacy = $this->legacySnapshot($scope,$result,'legacy-old-advertising-rules');
        try { (new ChannelEconomicsService())->calculate($input); self::fail('Current rules require advertising evidence'); }
        catch (InvalidArgumentException $error) { self::assertSame('channel_advertising_direct_cost_evidence_required',$error->getMessage()); }
        $replay = $store->replayRequest($scope,'legacy-old-advertising-rules',$input);
        self::assertSame($legacy['id'],$replay['snapshot_id']); self::assertSame($legacy['digest'],$replay['content_digest']);
        self::assertSame(1000,$replay['result']['channel_net_contribution_amount']);
    }
    public function testRequestDigestReplayRetainsExactRawRequestContractAndOriginalFacts(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $input = $this->channel(); $result = (new ChannelEconomicsService())->calculate($input);
        $saved = $store->save($scope,['inputs'=>$result['inputs'],'result'=>$result],'modern-request-digest-proof',1,$input);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$saved['request_digest']);
        $replay = $store->replayRequest($scope,'modern-request-digest-proof',array_reverse($input,true));
        self::assertSame($saved['snapshot_id'],$replay['snapshot_id']); self::assertSame($saved['content_digest'],$replay['content_digest']);
        $drifted = $result; $drifted['net_revenue'] = 9000; $drifted['inputs']['net_revenue'] = 9000;
        self::assertSame($saved['content_digest'],$store->save($scope,['inputs'=>$drifted['inputs'],'result'=>$drifted],'modern-request-digest-proof',1,$input)['content_digest']);
        foreach (['net_revenue'=>'1000.00','operator_attested'=>false,'unknown_future_input'=>null] as $field=>$value) {
            $changed = $input; $changed[$field] = $value; $this->assertLegacyConflict($store,$scope,'modern-request-digest-proof',$changed);
        }
    }
    public function testLegacyReplayChecksStoredIntegrityAndScopeBeforeComparingInputs(): void
    {
        $store = new OperatingEvidenceSnapshotStore(); $scope = $store->scope(7,[80],80,'2026-10','ctrip','channel_economics');
        $input = $this->channel(); $result = (new ChannelEconomicsService())->calculate($input);
        foreach (['integrity','scope'] as $case) {
            $key = 'legacy-gate-'.$case; $legacy = $this->legacySnapshot($scope,$result,$key);
            $wrongScope = $scope; $wrongScope['tenant_id'] = 8;
            self::assertNull($store->replayRequest($wrongScope,$key,$input));
            if ($case === 'integrity') $changes = ['payload_json'=>'{}'];
            else {
                $payload = json_decode($legacy['json'],true,512,JSON_THROW_ON_ERROR); $payload['scope']['hotel_id'] = 81;
                $json = json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $changes = ['payload_json'=>$json,'content_digest'=>hash('sha256',$json)];
            }
            Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',$legacy['id'])->update($changes);
            $rejected = false;
            try { $store->replayRequest($scope,$key,$input); }
            catch (RuntimeException $error) {
                $rejected = true; self::assertSame(409,$error->getCode());
                self::assertSame($case === 'integrity' ? 'operating_evidence_integrity_failed' : 'operating_evidence_scope_mismatch',$error->getMessage());
            }
            self::assertTrue($rejected);
        }
    }
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
    public function testCalculationDoesNotPromoteGenericReferencesToCompleteOperatingEvidence(): void {
        $result=(new ChannelEconomicsService())->calculate($this->channel(),['period_closed'=>true]);
        self::assertSame('calculated',$result['status']);
        self::assertSame('incomplete',$result['evidence_chain']['status']);
        self::assertFalse($result['evidence_chain']['independently_verified']);
        self::assertSame('source_missing',$result['evidence_chain']['checks'][0]['status']);
        self::assertSame([], $result['inputs']['evidence_refs_by_metric']['refund_amount']);
    }
    public function testMetricEvidenceClosedPeriodAndExactSnapshotReadback(): void {
        $input=$this->channel();
        $input['source_refs']=[];
        foreach(['effective_order_amount','refund_amount','net_revenue','advertising_spend','attributed_order_amount'] as $metric) $input['evidence_refs_by_metric'][$metric]=[' ', ' synthetic-'.$metric, 'synthetic-'.$metric];
        $sources=['period_closed'=>true,'scope'=>['tenant_id'=>7,'hotel_id'=>80,'platform'=>'ctrip','period_month'=>'2026-09']];
        $result=(new ChannelEconomicsService())->calculate($input,$sources);
        self::assertSame('ready_for_same_scope_review',$result['evidence_chain']['status']);
        self::assertSame('calculated',$result['status']);
        self::assertSame(['synthetic-refund_amount'],$result['inputs']['evidence_refs_by_metric']['refund_amount']);
        self::assertFalse($result['evidence_chain']['independently_verified']);
        $store=new OperatingEvidenceSnapshotStore(); $scope=$store->scope(7,[80],80,'2026-09','ctrip','channel_economics');
        $saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result,'source_quality'=>$result['source_quality']],'metric-source-readback-proof',1);
        $read=$store->read($scope,$saved['snapshot_id']); self::assertTrue($read['readback_verified']);
        self::assertSame($result['evidence_chain'],$read['result']['evidence_chain']);
        self::assertSame($result['inputs']['evidence_refs_by_metric'],$read['inputs']['evidence_refs_by_metric']);
        $sources['period_closed']=false;
        self::assertSame('incomplete',(new ChannelEconomicsService())->calculate($input,$sources)['evidence_chain']['status']);
        $input['operator_attested']=false;
        self::assertSame('manual_unverified',(new ChannelEconomicsService())->calculate($input)['evidence_chain']['checks'][0]['status']);
        $input['attribution_basis']='';
        self::assertContains('basis_missing',array_column((new ChannelEconomicsService())->calculate($input)['evidence_chain']['checks'],'status'));
    }
    public function testMalformedMetricReferencesAreRejected(): void {
        $input=$this->channel();$input['evidence_refs_by_metric']=['refund_amount'=>'not-an-array'];
        $this->expectExceptionMessage('channel_metric_source_refs_invalid');(new ChannelEconomicsService())->calculate($input);
    }
    public function testMissingAllDeductibleAmountsNeverBecomeZeroCostOrNetContribution(): void {
        $input=$this->channel();$input['costs']=[];$input['advertising_spend']=null;$input['cost_coverage_complete']=false;
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertNull($result['known_direct_cost']);self::assertNull($result['known_costs_contribution_amount']);
        self::assertNull($result['channel_net_contribution_amount']);self::assertSame('partial',$result['status']);
        $input['cost_coverage_complete']=true;
        self::assertNull((new ChannelEconomicsService())->calculate($input)['known_direct_cost']);
    }
    public function testExplicitZeroAndKnownPartialCostsRemainAvailable(): void {
        $input=$this->channel();$input['advertising_spend']=null;$input['cost_coverage_complete']=false;
        $input['costs']=[['label'=>'已核对零履约成本','amount'=>0,'source_ref'=>'synthetic-zero-cost','included_in_net_revenue'=>false]];
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(0.0,$result['known_direct_cost']);self::assertSame(1000.0,$result['known_costs_contribution_amount']);
        $input['costs'][0]['amount']=200;
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(200.0,$result['known_direct_cost']);self::assertSame(800.0,$result['known_costs_contribution_amount']);
        $input['costs'][0]['source_ref']='';
        self::assertNull((new ChannelEconomicsService())->calculate($input)['known_direct_cost']);
    }
    public function testExplicitZeroAdvertisingAndCompleteAlreadyIncludedCostsKeepRealZero(): void {
        $input=$this->channel();$input['costs']=[];$input['advertising_spend']=0;$input['cost_coverage_complete']=false;
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(0.0,$result['known_direct_cost']);self::assertSame(1000.0,$result['known_costs_contribution_amount']);
        $input['advertising_spend']=null;$input['advertising_included_in_net_revenue']=true;$input['cost_coverage_complete']=true;
        $result=(new ChannelEconomicsService())->calculate($input);
        self::assertSame(0.0,$result['known_direct_cost']);self::assertSame(1000.0,$result['channel_net_contribution_amount']);
        $input['cost_coverage_complete']=false;
        self::assertNull((new ChannelEconomicsService())->calculate($input)['known_direct_cost']);
    }
    public function testUnknownCostAmountsSurviveExactSnapshotReadback(): void {
        $input=$this->channel();$input['costs']=[];$input['advertising_spend']=null;$input['cost_coverage_complete']=false;
        $result=(new ChannelEconomicsService())->calculate($input);
        $store=new OperatingEvidenceSnapshotStore();$scope=$store->scope(7,[80],80,'2026-02','meituan','channel_economics');
        $saved=$store->save($scope,['inputs'=>$result['inputs'],'result'=>$result],'missing-channel-cost-proof',1);
        $read=$store->read($scope,$saved['snapshot_id']);
        self::assertTrue($read['readback_verified']);self::assertNull($read['result']['known_direct_cost']);
        self::assertNull($read['result']['known_costs_contribution_amount']);
    }

    public function testFutureActualSourceIsRejectedBeforeSnapshotSave(): void
    {
        $input = $this->actual();
        $input['items'][0]['source_date'] = '2099-01-31';
        $before = Db::name(OperatingEvidenceSnapshotStore::TABLE)->count();
        try {
            $result = (new ConsumablesActualCostService())->calculate($input);
            $store = new OperatingEvidenceSnapshotStore();
            $scope = $store->scope(7, [80], 80, '2099-01', 'whole_hotel', 'consumables_actual');
            $store->save($scope, ['inputs' => $result['inputs'], 'result' => $result, 'source_quality' => $result['source_quality']], 'future-source-proof', 1);
            self::fail('A future inventory source must not be saved as actual consumption');
        } catch (InvalidArgumentException $error) {
            self::assertSame('consumables_source_date_in_future', $error->getMessage());
        }
        self::assertSame($before, Db::name(OperatingEvidenceSnapshotStore::TABLE)->count());
    }

    public function testShanghaiTodayAndExcludedFutureReferenceRemainCompatible(): void
    {
        $input = $this->actual();
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $input['items'][0]['source_date'] = $today;
        $input['items'][] = array_replace($input['items'][0], ['id' => 'excluded-future-reference', 'enabled' => false, 'source_date' => '2099-01-31']);
        $result = (new ConsumablesActualCostService())->calculate($input);
        self::assertSame('calculated', $result['status']);
        self::assertSame(200.0, $result['actual_consumed_cost']);
        self::assertSame(2.0, $result['actual_consumables_cost_per_room_night']);
        self::assertSame($today, $result['items'][0]['source_date']);
        self::assertSame('excluded', $result['items'][1]['status']);
        self::assertSame('2099-01-31', $result['items'][1]['source_date']);
    }
}
