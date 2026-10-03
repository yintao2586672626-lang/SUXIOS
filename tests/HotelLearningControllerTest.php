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

    public static function malformedNestedInputs(): array
    {
        return [
            'profile list' => ['profile', ['fields'], 'invalid-list'],
            'profile row' => ['profile', ['fields'], ['invalid-row']],
            'profile date' => ['profile', ['fields', 0, 'as_of'], ['invalid-date']],
            'stock list' => ['consumables_reconciliation', ['items'], 'invalid-list'],
            'stock row' => ['consumables_reconciliation', ['items'], ['invalid-row']],
            'stock date' => ['consumables_reconciliation', ['items', 0, 'source_date'], ['invalid-date']],
            'OTA time' => ['ota_scene', ['scene', 'observed_at'], ['invalid-time']],
            'OTA rate unit' => ['ota_scene', ['rate_unit'], ['invalid-unit']],
            'OTA sale terms' => ['ota_scene', ['price_terms'], 'invalid-object'],
            'sample rate unit' => ['market_sample', ['hotels', 0, 'rate_unit'], ['invalid-unit']],
            'actual review object' => ['operating_review', ['actual'], 'invalid-object'],
            'plan review object' => ['operating_review', ['plan'], 'invalid-object'],
            'contract object' => ['contract_review', ['constraints'], 'invalid-object'],
            'investment date' => ['investment_target', ['scenario', 'as_of'], ['invalid-date']],
            'investment NUL date' => ['investment_target', ['scenario', 'as_of'], "2026-10-\0" . '3'],
            'profile NUL date' => ['profile', ['fields', 0, 'as_of'], "2026-10-\0" . '3'],
            'target request object' => ['investment_target', ['request'], 'invalid-object'],
        ];
    }

    #[DataProvider('malformedNestedInputs')]
    public function testMalformedNestedInputsReturn422WithoutWriting(string $mode, array $path, mixed $value): void
    {
        $payload = $this->request($mode);
        $target =& $payload['inputs'];
        foreach ($path as $key) $target =& $target[$key];
        $target = $value;
        unset($target);
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach (['preview', 'save'] as $action) {
            $reply = $this->call($action, $payload);
            self::assertSame(422, $reply['http_status']);
            self::assertSame(422, $reply['body']['code']);
        }
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public static function invalidSaveKeys(): array { return [[['unexpected']], [true], [12345678]]; }

    #[DataProvider('invalidSaveKeys')]
    public function testSaveKeyMustBeTextWithoutWriting(mixed $value): void
    {
        $payload = $this->request('profile');
        $payload['idempotency_key'] = $value;
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        self::assertSame(422, $this->call('save', $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public static function underflowingInputNumbers(): array
    {
        return [
            ['ota_scene', ['conversion_rate']], ['ota_scene', ['price']],
            ['market_sample', ['hotels', 0, 'traffic']],
            ['market_sample', ['hotels', 0, 'conversion']],
            ['operating_review', ['actual', 'revenue']],
            ['operating_review', ['plan', 'sold_room_nights']],
            ['contract_review', ['payback_months']],
        ];
    }

    #[DataProvider('underflowingInputNumbers')]
    public function testNonzeroNumericTextMustNotBecomeActualZero(string $mode, array $path): void
    {
        $payload = $this->request($mode);
        $target =& $payload['inputs'];
        foreach ($path as $key) $target =& $target[$key];
        $target = '1e-999';
        unset($target);
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach (['preview', 'save'] as $action) self::assertSame(422, $this->call($action, $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testMalformedInputCanBeCorrectedAndSavedWithSameRequestKey(): void
    {
        $payload = $this->request('profile');
        $payload['inputs']['fields'][0]['as_of'] = ['invalid-date'];
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        self::assertSame(422, $this->call('save', $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
        $payload['inputs']['fields'][0]['as_of'] = '2026-10-02';
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertSame('2026-10-02', $read['inputs']['fields'][0]['as_of']);
        self::assertTrue($read['readback_verified']);
        self::assertSame($saved['body']['data']['content_digest'], $read['content_digest']);
    }

    public function testScientificTextZeroRemainsRealZero(): void
    {
        $payload = $this->request('ota_scene');
        $payload['inputs']['conversion_rate'] = '0e-999';
        $payload['inputs']['price'] = '-0.000e-999';
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertEquals(0, $read['inputs']['conversion_rate']);
        self::assertEquals(0, $read['inputs']['price']);
        self::assertTrue($read['result']['comparison_ready']);
        self::assertTrue($read['readback_verified']);
    }

    public static function invalidReviewCounting(): array
    {
        return [['actual', 'sold_room_nights'], ['actual', 'available_room_nights'], ['plan', 'sold_room_nights'], ['plan', 'available_room_nights']];
    }

    #[DataProvider('invalidReviewCounting')]
    public function testFractionalReviewCountsReturn422WithoutWriting(string $side, string $field): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs'][$side][$field] = 0.5;
        if ($field === 'available_room_nights') $payload['inputs'][$side]['sold_room_nights'] = 0;
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach (['preview', 'save'] as $action) self::assertSame(422, $this->call($action, $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testOverflowingReviewRatioIsAnInputErrorWithoutWriting(): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs']['actual']['revenue'] = 1e-320;
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach (['preview', 'save'] as $action) self::assertSame(422, $this->call($action, $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testMarketHumanConfirmationSurvivesExactSavedReadback(): void
    {
        $payload = $this->request('market_sample');
        $payload['inputs']['comparison_attested'] = true;
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id']);
        self::assertTrue($read['body']['data']['inputs']['comparison_attested']);
        self::assertSame($saved['body']['data']['content_digest'], $read['body']['data']['content_digest']);
    }

    public static function malformedScopeFields(): array { return [['mode'], ['period_month'], ['platform']]; }

    #[DataProvider('malformedScopeFields')]
    public function testMalformedScopeIsAnInputErrorWithoutWriting(string $field): void
    {
        $payload = $this->request('profile');
        $payload[$field] = ['unexpected'];
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        foreach (['overview', 'preview', 'save', 'read'] as $action) self::assertSame(422, $this->call($action, $payload, 1)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testMarketConfirmationCannotBeGuessedFromText(): void
    {
        $payload = $this->request('market_sample');
        $payload['inputs']['comparison_attested'] = 'false';
        $before = Db::name('hotel_operating_evidence_snapshots')->count();
        self::assertSame(422, $this->call('save', $payload)['http_status']);
        self::assertSame($before, Db::name('hotel_operating_evidence_snapshots')->count());
    }

    public function testZeroReviewRevenuePreservesUnknownRatioAndActualZero(): void
    {
        $payload = $this->request('operating_review');
        $payload['inputs']['actual']['revenue'] = 0;
        $payload['inputs']['actual']['sold_room_nights'] = 0;
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertSame(0, $read['inputs']['actual']['revenue']);
        self::assertNull($read['result']['actual_cost_ratio']);
        self::assertTrue($read['readback_verified']);
    }

    public function testSubmicroConsumptionSurvivesPreviewSaveAndExactReadback(): void
    {
        $payload = $this->request('consumables_reconciliation');
        $payload['inputs']['items'][0] = array_replace($payload['inputs']['items'][0], [
            'opening_quantity'=>0,'purchased_quantity'=>1e-7,'transfer_in_quantity'=>0,'closing_quantity'=>0,
            'transfer_out_quantity'=>0,'returned_quantity'=>0,'written_off_quantity'=>0,'unit_price'=>1e12,
            'issued_quantity'=>1e-7,'book_closing_quantity'=>0,
        ]);
        $preview = $this->call('preview', $payload);
        self::assertSame(200, $preview['http_status']);
        self::assertEquals(100000, $preview['body']['data']['result']['actual_consumed_cost']);
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertSame(1e-7, $read['inputs']['items'][0]['purchased_quantity']);
        self::assertSame(1e-7, $read['result']['items'][0]['consumed_quantity']);
        self::assertEquals(100000, $read['result']['actual_consumed_cost']);
        self::assertSame($saved['body']['data']['content_digest'], $read['content_digest']);
        self::assertTrue($read['readback_verified']);
    }

    public function testUnpricedStockKeepsKnownQuantityAndUnknownCostAfterReadback(): void
    {
        $payload = $this->request('consumables_reconciliation');
        $payload['inputs']['items'][0]['unit_price'] = null;
        $saved = $this->call('save', $payload);
        self::assertSame(200, $saved['http_status']);
        $read = $this->call('read', $payload, $saved['body']['data']['snapshot_id'])['body']['data'];
        self::assertSame('partial', $read['status']);
        self::assertEquals(100, $read['result']['items'][0]['consumed_quantity']);
        self::assertNull($read['result']['items'][0]['consumed_cost']);
        self::assertNull($read['result']['actual_consumed_cost']);
        self::assertNull($read['result']['known_consumed_cost']);
        self::assertTrue($read['readback_verified']);
    }

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
