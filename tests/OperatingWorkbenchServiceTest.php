<?php
declare(strict_types=1);

use app\service\BookingMonitoringContextService;
use app\service\BookingMonitoringService;
use app\service\OperatingTargetService;
use app\service\OperatingWorkbenchMetricsService;
use app\service\OperatingWorkbenchService;
use app\service\OperatingWorkbenchSnapshotService;
use PHPUnit\Framework\TestCase;
use Tests\Support\OperatingWorkbenchSqliteFixture;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingWorkbenchServiceTest extends TestCase
{
    private array $original;
    private string $database;
    private OperatingWorkbenchService $service;
    protected function setUp(): void
    {
        (new App())->initialize(); restore_error_handler(); restore_exception_handler();
        $this->original = Config::get('database'); $this->database = sys_get_temp_dir() . '/workbench-' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set(['default' => 'workbench_test', 'connections' => ['workbench_test' => ['type' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'fields_strict' => true]]], 'database');
        Db::connect(null, true); OperatingWorkbenchSqliteFixture::create(); $this->service = new OperatingWorkbenchService();
    }
    protected function tearDown(): void
    {
        Db::connect()->close(); Config::set($this->original, 'database'); Db::connect(null, true); if (is_file($this->database)) unlink($this->database);
    }
    private function budget(): array
    {
        return ['period_month' => '2026-10', 'revenue_budget' => 1000, 'online_target' => 600, 'offline_target' => 300, 'other_revenue_target' => 100,
            'break_even_revenue' => 800, 'break_even_basis' => 'synthetic approved scenario', 'weekly_target' => 200, 'source_ref' => 'synthetic-budget', 'tax_basis' => 'tax_included'];
    }
    private function save(string $type, array $body, int $expected = 0, string $key = 'synthetic-key-01', string $case = ''): array
    {
        return $this->service->save(10, [80,81], 80, 7, ['type' => $type, 'inputs' => $body, 'expected_snapshot_id' => $expected, 'idempotency_key' => $key, 'case_key' => $case]);
    }
    public function testBudgetPersistenceEditingReadbackAndConflictDoNotOverwriteOtherRecords(): void
    {
        $saved = $this->save('budget', $this->budget()); self::assertTrue($saved['readback_verified']); self::assertSame('scenario_assumption', $saved['inputs']['source_quality']);
        $same = $this->save('budget', $this->budget()); self::assertTrue($same['idempotent']); self::assertSame($saved['snapshot_id'], $same['snapshot_id']);
        $body = $this->budget(); $body['weekly_target'] = 300;
        $updated = $this->save('budget', $body, $saved['snapshot_id'], 'synthetic-key-02'); self::assertSame($saved['snapshot_id'], $updated['previous_id']);
        $store = new OperatingWorkbenchSnapshotService(); $scope = $store->scope(10,[80],80,'budget_2026-10');
        self::assertSame(200.0,$store->read($scope,$saved['snapshot_id'])['inputs']['weekly_target']);
        try { $this->save('budget',$body,0,'synthetic-key-stale'); self::fail('stale'); } catch (RuntimeException $e) { self::assertSame(409,$e->getCode()); }
        self::assertSame(2,Db::name('hotel_business_workspace_snapshots')->count());
        self::assertNull($store->latest($store->scope(10,[81],81,'budget_2026-10')));
    }
    public function testLegacyBudgetIsVisibleButNotPretendedToBeVerified(): void
    {
        Db::name('monthly_tasks')->insert(['id'=>1,'tenant_id'=>10,'hotel_id'=>80,'year'=>2026,'month'=>10,'status'=>1,'task_data'=>json_encode(['revenue_budget'=>1000])]);
        $row=$this->service->overview(10,[80],[80],'2026-10','2026-10-01')['items'][0];
        self::assertSame(1000.0,$row['budget']['inputs']['revenue_budget']); self::assertFalse($row['budget']['readback_verified']);
        self::assertNull($row['budget']['inputs']['break_even_revenue']); self::assertNull($row['month']['reported_revenue']);
    }
    public function testBudgetSplitsTasksAndNonFiniteInputsAreValidated(): void
    {
        foreach ([['online_target'=>800],['revenue_budget'=>INF],['break_even_basis'=>''],['tasks'=>[['measure'=>'task','owner'=>'x','due_date'=>'2026-09-30','status'=>'completed','evidence_ref'=>'']]]] as $change) {
            try { $this->save('budget',array_replace($this->budget(),$change)); self::fail('invalid budget'); } catch (InvalidArgumentException) { self::assertSame(0,Db::name('hotel_business_workspace_snapshots')->count()); }
        }
        $body=$this->budget();$body['tasks']=[['measure'=>'task','owner'=>'x','due_date'=>'2026-10-30','status'=>'completed','evidence_ref'=>'synthetic-task-proof']];
        self::assertSame('completed',$this->save('budget',$body)['inputs']['tasks'][0]['status']);
    }
    public function testReportedDataAndMissingDatesCannotBecomeAdmittedHotelRevenue(): void
    {
        $this->daily('2026-10-01',100,10,20);
        $row=$this->service->overview(10,[80,81],[80,81],'2026-10','2026-10-02')['items'][0];
        self::assertNull($row['month']['reported_revenue']); self::assertSame(100.0,$row['month']['observed_reported_revenue']); self::assertNull($row['month']['admitted_revenue']);
        self::assertNull($row['month']['combined_occupancy_percent']); self::assertSame(1,$row['month']['reported_days']); self::assertSame(2,$row['month']['expected_days']);
        $this->daily('2026-10-02',300,30,60);
        $row=$this->service->overview(10,[80],[80],'2026-10','2026-10-02')['items'][0];
        self::assertSame(50.0,$row['month']['combined_occupancy_percent']); self::assertSame(25.0,$row['month']['overnight_occupancy_percent']);
        self::assertSame('manual_unverified',$row['month']['series'][0]['reported_quality']);
    }
    public function testWholeHotelConfirmedFactsProduceBudgetMatrixWithoutUsingChannelScope(): void
    {
        $this->save('budget',$this->budget()); $this->target('2026-10-01',100,'whole_hotel'); $this->target('2026-10-02',200,'accommodation_room_fee');
        $row=$this->service->overview(10,[80],[80],'2026-10','2026-10-02')['items'][0]; self::assertNull($row['month']['admitted_revenue']);
        $this->target('2026-10-02',200,'whole_hotel');
        $row=$this->service->overview(10,[80],[80],'2026-10','2026-10-02')['items'][0];
        self::assertSame(30.0,$row['comparison']['matrix']['revenue_budget']['completion_percent']); self::assertSame(-500.0,$row['comparison']['break_even_gap']);
        self::assertStringContainsString('不是GOP',$row['comparison']['break_even_definition']);
    }
    public function testReportAutoPrefillIsServerGeneratedSavedAndImmutableOnSourceChange(): void
    {
        $this->save('budget',$this->budget());
        for($i=1;$i<=7;$i++) $this->target('2026-09-'.sprintf('%02d',23+$i),100,'whole_hotel');
        $body=['period_end'=>'2026-09-30','human_judgment'=>'synthetic analysis','manager_note'=>'synthetic coaching'];
        $saved=$this->save('report',$body,0,'synthetic-report-key'); self::assertSame(700.0,$saved['inputs']['facts']['admitted_revenue']);
        self::assertSame('pending',$saved['inputs']['human_review_status']); self::assertSame(7,$saved['inputs']['facts']['admitted_days']);
        $this->target('2026-09-30',900,'whole_hotel');
        $replay=$this->save('report',$body,0,'synthetic-report-key'); self::assertTrue($replay['idempotent']); self::assertSame(700.0,$replay['inputs']['facts']['admitted_revenue']);
        $body['human_judgment']='changed';
        try{$this->save('report',$body,0,'synthetic-report-key');self::fail('changed idempotency');}catch(RuntimeException $e){self::assertSame(409,$e->getCode());}
    }
    public function testAppealEvidenceReviewSubmissionAndResultAreSeparateManualStages(): void
    {
        $body=['platform'=>'ctrip','review_date'=>'2026-10-01','review_reference'=>'synthetic-review-1','factual_description'=>'synthetic facts','appeal_reason'=>'synthetic correction','status'=>'draft'];
        $saved=$this->save('appeal',$body,0,'synthetic-appeal-1','case0001');
        $body['evidence']=[['description'=>'source detail','source_ref'=>'synthetic-proof','business_date'=>'2026-10-01']];$body['status']='evidence_ready';
        $saved=$this->save('appeal',$body,$saved['snapshot_id'],'synthetic-appeal-2','case0001');
        $body['status']='submitted';$body['platform_receipt']='synthetic-platform';
        try{$this->save('appeal',$body,$saved['snapshot_id'],'synthetic-appeal-skip','case0001');self::fail('review skipped');}catch(RuntimeException $e){self::assertSame(409,$e->getCode());}
        $body['status']='reviewed';$body['human_review_confirmed']=true;$saved=$this->save('appeal',$body,$saved['snapshot_id'],'synthetic-appeal-3','case0001');
        $body['status']='submitted';$saved=$this->save('appeal',$body,$saved['snapshot_id'],'synthetic-appeal-4','case0001');
        $body['status']='accepted';$body['result_reference']='synthetic-result';$body['case_note']='template lesson';$body['reusable_case']=true;
        $saved=$this->save('appeal',$body,$saved['snapshot_id'],'synthetic-appeal-5','case0001');
        self::assertTrue($saved['inputs']['reusable_case']);self::assertFalse($saved['inputs']['automatic_appeal']);self::assertFalse($saved['inputs']['platform_result_verified']);
        self::assertSame(7,$saved['inputs']['reviewed_by']);self::assertCount(1,(new OperatingWorkbenchSnapshotService())->cases(10,80));
    }
    public function testScopesAndInvalidDatesRejectBeforeWrites(): void
    {
        $store=new OperatingWorkbenchSnapshotService();
        foreach([[10,[80],82],[11,[80],80]]as[$tenant,$ids,$hotel]){try{$store->scope($tenant,$ids,$hotel,'budget_2026-10');self::fail('foreign');}catch(RuntimeException $e){self::assertSame(403,$e->getCode());}}
        foreach(['2026-02-30','2026-1-01']as$date){try{OperatingWorkbenchMetricsService::date($date);self::fail('date');}catch(InvalidArgumentException){self::assertTrue(true);}}
        self::assertSame(0,Db::name('hotel_business_workspace_snapshots')->count());
    }
    public function testAppealLibraryCountsDistinctCasesInsteadOfRevisionHistory(): void
    {
        $body=['platform'=>'ctrip','review_date'=>'2026-10-01','review_reference'=>'synthetic-long-history','factual_description'=>'synthetic facts','appeal_reason'=>'synthetic correction','status'=>'draft'];
        $first=$this->save('appeal',$body,0,'synthetic-case-original','case0001');
        $olderCase=$this->save('appeal',$body,0,'synthetic-case-second','case0002');
        $row=Db::name('hotel_business_workspace_snapshots')->where('id',$first['snapshot_id'])->find();
        unset($row['id']); $revisions=[];
        for($i=0;$i<501;$i++) $revisions[]=array_replace($row,['idempotency_key'=>'synthetic-history-'.$i]);
        foreach(array_chunk($revisions,200) as $batch) Db::name('hotel_business_workspace_snapshots')->insertAll($batch);
        $store=new OperatingWorkbenchSnapshotService();
        $store->save($store->scope(11,[82],82,'appeal_case0003'),$first['inputs'],7,'synthetic-foreign-case',0);
        $cases=$store->cases(10,80);
        self::assertCount(2,$cases);
        self::assertGreaterThan($olderCase['snapshot_id'],$cases[0]['snapshot_id']);
        self::assertSame('appeal_case0001',$cases[0]['scope']['kind']);
        self::assertSame($olderCase['snapshot_id'],$cases[1]['snapshot_id']);
    }
    public function testBookingPriorYearAndAreaRollupNeverDoubleCountRoomTypes(): void
    {
        $monitor=new BookingMonitoringService();
        $store = new OperatingWorkbenchSnapshotService();
        foreach ([80,81] as $hotel) $store->save($store->scope(10,[80,81],$hotel,'booking_2026-10-01'), ['business_date'=>'2026-10-01','region'=>'合成区域','area_manager'=>'合成负责人','prices'=>[]], 7, 'synthetic-booking-context-'.$hotel, 0);
        foreach([['2026-10-01','2026-10-02',12],['2025-10-01','2025-10-02',8]]as[$date,$stay,$rooms]){
            $monitor->saveSnapshots(10,[80],[[ 'hotel_id'=>80,'platform'=>'ctrip','fact_scope'=>'ota_channel','stay_date'=>$stay,'captured_at'=>$date.' 09:00:00','on_books_room_nights'=>$rooms,'source_ref'=>'synthetic monitor','source_method'=>'manual_entry','operator_attested'=>true]],7);
        }
        $service=new BookingMonitoringContextService();$result=$service->overview(10,[80],[80],['platform'=>'ctrip','business_date'=>'2026-10-01','fixed_time'=>'09:00','horizon_days'=>1]);
        $aggregate=array_values(array_filter($result['cells'],fn($c)=>$c['room_type_id']===0))[0];
        self::assertSame(8.0,$aggregate['prior_year']['on_books_room_nights']);self::assertSame(4.0,$aggregate['prior_year']['difference']);
        self::assertSame(12.0,$result['group_rollup'][0]['room_nights']);self::assertSame(1,$result['group_rollup'][0]['expected_hotels']);
        $result=$service->overview(10,[80,81],[80,81],['platform'=>'ctrip','business_date'=>'2026-10-01','fixed_time'=>'09:00','horizon_days'=>1]);
        self::assertNull($result['group_rollup'][0]['room_nights']);self::assertSame('partial',$result['group_rollup'][0]['status']);
    }
    public function testBookingMissingAssignmentsKeepHotelFactsButNeverFormReadyRegionalTotals(): void
    {
        $monitor = new BookingMonitoringService(); $store = new OperatingWorkbenchSnapshotService();
        foreach ([80 => 12, 81 => 9] as $hotel => $rooms) $monitor->saveSnapshots(10, [80,81], [['hotel_id'=>$hotel,'platform'=>'ctrip','fact_scope'=>'ota_channel','stay_date'=>'2026-10-02','captured_at'=>'2026-10-01 09:00:00','on_books_room_nights'=>$rooms,'source_ref'=>'synthetic monitor','source_method'=>'manual_entry','operator_attested'=>true]], 7);
        $service = new BookingMonitoringContextService(); $query = ['platform'=>'ctrip','business_date'=>'2026-10-01','fixed_time'=>'09:00','horizon_days'=>1];
        $cases = [[], ['region'=>'区域'], ['area_manager'=>'负责人'], ['region'=>'  ','area_manager'=>'负责人'], ['prices'=>[]]];
        foreach ($cases as $index => $context) {
            if ($index > 0) foreach ([80,81] as $hotel) {
                $scope = $store->scope(10,[80,81],$hotel,'booking_2026-10-01');
                $store->save($scope, $context, 7, 'synthetic-missing-'.$index.'-'.$hotel, $store->latest($scope)['snapshot_id'] ?? 0);
            }
            $result = $service->overview(10,[80,81],[80,81],$query);
            self::assertCount(2, $result['group_rollup']);
            foreach ($result['group_rollup'] as $group) {
                self::assertCount(1, $group['hotels']); self::assertSame('partial', $group['status']);
                self::assertSame('missing', $group['assignment_status']); self::assertNull($group['room_nights']);
                self::assertSame($group['hotels'][0] === 80 ? 12.0 : 9.0, $group['observed_room_nights']);
            }
            foreach ($result['cells'] as $cell) if ($cell['room_type_id'] === 0) {
                self::assertSame('ready', $cell['current']['status']); self::assertSame($cell['hotel_id'] === 80 ? 12.0 : 9.0, $cell['current']['on_books_room_nights']);
            }
        }
        foreach ([80,81] as $hotel) {
            $scope = $store->scope(10,[80,81],$hotel,'booking_2026-10-01');
            $store->save($scope, ['region'=>'区域','area_manager'=>'负责人','prices'=>[]], 7, 'synthetic-configured-'.$hotel, $store->latest($scope)['snapshot_id']);
        }
        $result = $service->overview(10,[80,81],[80,81],$query);
        self::assertCount(1, $result['group_rollup']); self::assertSame('ready', $result['group_rollup'][0]['status']);
        self::assertSame(21.0, $result['group_rollup'][0]['room_nights']); self::assertSame('configured', $result['group_rollup'][0]['assignment_status']);
    }
    public function testBookingPriceHasRealSourceDateAndForeignRoomRejection(): void
    {
        $service=new BookingMonitoringContextService();$input=['business_date'=>'2026-10-01','region'=>'synthetic region','area_manager'=>'synthetic owner','prices'=>[['stay_date'=>'2026-10-02','room_type_id'=>1,'starting_price'=>0,'captured_at'=>'2026-10-01 08:00:00','source_ref'=>'synthetic price','operator_attested'=>true]]];
        $body=$service->normalize($input,80);self::assertSame(0.0,$body['prices'][0]['starting_price']);self::assertSame('manual_confirmed',$body['prices'][0]['quality_status']);
        $input['prices'][0]['room_type_id']=2;$this->expectException(RuntimeException::class);$service->normalize($input,80);
    }
    private function daily(string $date,float $revenue,float $sold,float $salable):void
    {
        Db::name('daily_reports')->insert(['tenant_id'=>10,'hotel_id'=>80,'report_date'=>$date,'status'=>2,'report_data'=>json_encode(['revenue'=>$revenue,'online_revenue'=>$revenue*.6,'offline_revenue'=>$revenue*.4,'total_rooms'=>$sold,'overnight_rooms'=>$sold*.5,'salable_rooms'=>$salable])]);
    }
    private function target(string $date,float $revenue,string $scope):void
    {
        (new OperatingTargetService())->save(10,80,7,['target_date'=>$date,'target_revenue'=>1000,'actual_revenue'=>$revenue,'fact_scope'=>$scope,'source_type'=>'manual','source_reference'=>'synthetic-confirmed','quality_status'=>'manual_confirmed','change_reason'=>'synthetic test']);
    }
}
