<?php
declare(strict_types=1);

use app\service\CtripCompetitiveOperationsService;
use app\service\OnlineDailyDataPersistenceService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class CtripCompetitiveFunnelBoundaryTest extends TestCase
{
    private function row(string $date, string $hotel, ?float $list, ?float $detail, ?float $orders = 0, ?float $submit = 0): array
    {
        return ['id'=>0, 'data_date'=>$date, 'hotel_id'=>$hotel, 'compare_type'=>$hotel==='1001'?'self':($hotel==='-1'?'competitor_avg':'competitor'),
            'validation_status'=>'normal','readback_verified'=>1,'list_exposure'=>$list,'detail_exposure'=>$detail,
            'order_filling_num'=>$orders,'order_submit_num'=>$submit];
    }

    public function testDifferentDaysCannotSupplyOneRateNumeratorAndDenominator(): void
    {
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], [
            $this->row('2026-08-29','1001',100,null), $this->row('2026-08-30','1001',null,50),
            $this->row('2026-08-29','-1',100,90), $this->row('2026-08-30','-1',100,90),
        ], [], '1001');
        self::assertNull($result['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertSame(100.0,$result['traffic_funnel_comparison']['self']['list_exposure']);
        self::assertSame(50.0,$result['traffic_funnel_comparison']['self']['detail_exposure']);
        self::assertNotContains('conversion_vs_circle_anomaly',array_column($result['anomaly_diagnosis']['items'],'type'));
        self::assertStringContainsString('字段覆盖不完整',$result['scope_notice']);
    }

    public function testDailyCompetitorMeansCannotMixDifferentHotelSupports(): void
    {
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], [
            $this->row('2026-08-30','2001',100,null),$this->row('2026-08-30','2002',null,50),
        ], [], '1001');
        self::assertNull($result['traffic_funnel_comparison']['rows'][0]['competitor_average']['detail_entry_rate']);
        self::assertNull($result['traffic_funnel_comparison']['competitor_average']['detail_entry_rate']);
    }

    public function testDisjointDatesKeepSideFactsButBlockComparisonAndAdvice(): void
    {
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], [
            $this->row('2026-08-29','1001',100,10),$this->row('2026-08-30','-1',100,90),
        ], [], '1001');
        $funnel = $result['traffic_funnel_comparison'];
        self::assertSame(10.0,$funnel['self']['detail_entry_rate']);
        self::assertSame(90.0,$funnel['competitor_average']['detail_entry_rate']);
        self::assertNull($funnel['gaps']['detail_entry_rate']['difference']);
        self::assertNull($funnel['gaps']['list_exposure']['difference_pct']);
        self::assertNotContains('conversion_vs_circle_anomaly',array_column($result['anomaly_diagnosis']['items'],'type'));
        self::assertStringContainsString('日期覆盖不同',$result['scope_notice']);
    }

    public function testCompleteZeroDayIsNotMissingAndUnrelatedMissingStageDoesNotBlockEarlierRate(): void
    {
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], [
            $this->row('2026-08-29','1001',0,0,0,null),$this->row('2026-08-30','1001',100,20,10,null),
        ], [], '1001');
        $funnel = $result['traffic_funnel_comparison'];
        self::assertNull($funnel['rows'][0]['self']['detail_entry_rate']);
        self::assertSame(20.0,$funnel['self']['detail_entry_rate']);
        self::assertSame(50.0,$funnel['self']['order_entry_rate']);
        self::assertNull($funnel['self']['submit_rate']);
    }

    private function isolatedDatabase(): void
    {
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>(string)getenv('SUXIOS_CACHE_PATH')]]],'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>(string)getenv('SUXIOS_CACHE_PATH').'/logs/']]],'log');
        Config::set(['default'=>'funnel_test','connections'=>['funnel_test'=>['type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false]]],'database');
        Db::connect(null,true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::name('hotels')->insertAll([['id'=>7,'tenant_id'=>2],['id'=>8,'tenant_id'=>3]]);
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY, config_key TEXT, config_value TEXT)');
        Db::name('system_configs')->insert(['config_key'=>'ctrip_public_hotel_bindings','config_value'=>json_encode(['7'=>['system_hotel_id'=>7,'tenant_id'=>2,'ota_hotel_id'=>'1001']])]);
        Db::execute('CREATE TABLE ota_ctrip_entity_snapshots (id INTEGER PRIMARY KEY, system_hotel_id INTEGER, source TEXT, entity_type TEXT, data_date TEXT, last_seen_at TEXT)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,system_hotel_id INTEGER,
            hotel_id TEXT,hotel_name TEXT,source TEXT,platform TEXT,data_type TEXT,dimension TEXT,compare_type TEXT,data_date TEXT,
            list_exposure REAL,detail_exposure REAL,order_filling_num REAL,order_submit_num REAL,data_value REAL,flow_rate REAL,
            raw_data TEXT,validation_status TEXT,validation_flags TEXT,readback_verified INTEGER DEFAULT 0,readback_verified_at TEXT,
            ingestion_method TEXT,source_trace_id TEXT,data_period TEXT,is_final INTEGER,snapshot_time TEXT,update_time TEXT,create_time TEXT)');
    }

    public function testPartialDateCannotBeHiddenByAnotherCompleteDateAndZeroIsStillObserved(): void
    {
        $service=new CtripCompetitiveOperationsService();
        $rows=[$this->row('2026-08-29','1001',100,0),$this->row('2026-08-30','1001',100,null),
            $this->row('2026-08-29','-1',100,90),$this->row('2026-08-30','-1',100,90)];
        $partial=$service->analyzeRows([],$rows,[],'1001');
        self::assertSame(0.0,$partial['traffic_funnel_comparison']['rows'][0]['self']['detail_entry_rate']);
        self::assertNull($partial['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertNull($partial['traffic_funnel_comparison']['gaps']['detail_exposure']['difference']);
        self::assertSame('partial',$partial['status']);
        $rows[1]['detail_exposure']=0;
        $recovered=$service->analyzeRows([],$rows,[],'1001');
        self::assertSame(0.0,$recovered['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertSame('available',$recovered['status']);
        self::assertContains('conversion_vs_circle_anomaly',array_column($recovered['anomaly_diagnosis']['items'],'type'));
        self::assertStringNotContainsString('字段覆盖不完整',$recovered['scope_notice']);
    }

    public function testExplicitAverageRemainsPreferredWithoutFillingItsMissingFields(): void
    {
        $result=(new CtripCompetitiveOperationsService())->analyzeRows([], [
            $this->row('2026-08-30','-1',100,null),$this->row('2026-08-30','2001',500,450),
        ],[],'1001');
        self::assertSame(100.0,$result['traffic_funnel_comparison']['competitor_average']['list_exposure']);
        self::assertNull($result['traffic_funnel_comparison']['competitor_average']['detail_exposure']);
        self::assertNull($result['traffic_funnel_comparison']['competitor_average']['detail_entry_rate']);
    }

    private function persist(array $rows, int $hotel = 7): array
    {
        $before=(int)Db::name('online_daily_data')->max('id');
        $saved=(new OnlineDailyDataPersistenceService())->parseAndSaveTrafficData(['data'=>['list'=>$rows]],'2026-08-29','2026-08-30','ctrip',$hotel,'Ctrip','1001','browser_profile_api');
        self::assertSame(count($rows),$saved);
        $ids=Db::name('online_daily_data')->where('id','>',$before)->order('id')->column('id');
        return Db::name('online_daily_data')->whereIn('id',$ids)->order('id')->select()->toArray();
    }

    private function persistedCase(): array
    {
        $rows=$this->persist([
            ['hotelId'=>'1001','date'=>'2026-08-29','listExposure'=>100],
            ['hotelId'=>'1001','date'=>'2026-08-30','detailExposure'=>50],
            ['hotelId'=>-1,'date'=>'2026-08-29','listExposure'=>100,'detailExposure'=>90],
            ['hotelId'=>-1,'date'=>'2026-08-30','listExposure'=>100,'detailExposure'=>90],
        ]);
        self::assertSame(['normal','normal','normal','normal'],array_column($rows,'validation_status'));
        self::assertSame([1,1,1,1],array_map('intval',array_column($rows,'readback_verified')));
        self::assertSame([2,2,2,2],array_map('intval',array_column($rows,'tenant_id')));
        self::assertNull($rows[0]['detail_exposure']);self::assertNull($rows[1]['list_exposure']);
        return ['synthetic'=>true,'readback_verified'=>true,'rows'=>$rows,'data'=>(new CtripCompetitiveOperationsService())->build(7,'2026-08-29','2026-08-30')];
    }

    private function recoverCase(): array
    {
        $rows=[];
        foreach(['2026-08-29','2026-08-30'] as $date){
            $rows[]=['hotelId'=>'1001','date'=>$date,'listExposure'=>100,'detailExposure'=>50,'orderFillingNum'=>0,'orderSubmitNum'=>0];
            $rows[]=['hotelId'=>-1,'date'=>$date,'listExposure'=>100,'detailExposure'=>90,'orderFillingNum'=>0,'orderSubmitNum'=>0];
        }
        self::assertSame(4,(new OnlineDailyDataPersistenceService())->parseAndSaveTrafficData(['data'=>['list'=>$rows]],'2026-08-29','2026-08-30','ctrip',7,'Ctrip','1001','browser_profile_api'));
        return ['synthetic'=>true,'readback_verified'=>true,'rows'=>Db::name('online_daily_data')->order('id')->select()->toArray(),
            'data'=>(new CtripCompetitiveOperationsService())->build(7,'2026-08-29','2026-08-30')];
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTrafficRetryDoesNotReassignForeignTenantRow(): void
    {
        $this->isolatedDatabase();
        $service = new OnlineDailyDataPersistenceService();
        $response = ['data' => ['list' => [[
            'hotelId' => '1001', 'date' => '2026-08-29', 'listExposure' => 100,
        ]]]];
        self::assertSame(1, $service->parseAndSaveTrafficData(
            $response, '2026-08-29', '2026-08-29', 'ctrip', 7,
            'ctrip', '1001', 'browser_profile_api'
        ));
        $first = Db::name('online_daily_data')->where('source', 'ctrip')->find();
        $foreignId = (int)$first['id'];
        Db::name('online_daily_data')->where('id', $foreignId)->update([
            'tenant_id' => 3, 'list_exposure' => 999, 'readback_verified' => 0,
        ]);

        self::assertSame(1, $service->parseAndSaveTrafficData(
            $response, '2026-08-29', '2026-08-29', 'ctrip', 7,
            'ctrip', '1001', 'browser_profile_api'
        ));
        $foreign = Db::name('online_daily_data')->where('id', $foreignId)->find();
        self::assertSame(3, (int)$foreign['tenant_id']);
        self::assertSame(999.0, (float)$foreign['list_exposure']);
        $trusted = Db::name('online_daily_data')->where('tenant_id', 2)->find();
        self::assertIsArray($trusted);
        self::assertNotSame($foreignId, (int)$trusted['id']);
        self::assertSame(1, (int)$trusted['readback_verified']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testActualTrafficSaveReadbackAndBuildCannotMintCrossDateConversion(): void
    {
        $this->isolatedDatabase();
        try {
            $case=$this->persistedCase();
            self::assertSame(4,$case['data']['data_coverage']['traffic_usable_count']);
            self::assertNull($case['data']['traffic_funnel_comparison']['self']['detail_entry_rate']);
            self::assertNotContains('conversion_vs_circle_anomaly',array_column($case['data']['anomaly_diagnosis']['items'],'type'));
            self::assertSame($case['rows'],Db::name('online_daily_data')->whereIn('id',array_column($case['rows'],'id'))->order('id')->select()->toArray());
            $recovered=$this->recoverCase();
            self::assertSame(array_column($case['rows'],'id'),array_column($recovered['rows'],'id'));
            self::assertSame([1,1,1,1],array_map('intval',array_column($recovered['rows'],'readback_verified')));
            self::assertSame(50.0,$recovered['data']['traffic_funnel_comparison']['self']['detail_entry_rate']);
            self::assertSame('available',$recovered['data']['traffic_funnel_comparison']['status']);
            self::assertContains('conversion_vs_circle_anomaly',array_column($recovered['data']['anomaly_diagnosis']['items'],'type'));
        } finally {Db::connect()->close();}
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testTrafficResponseOutsideRequestedDatesIsNotSavedAsVerified(): void
    {
        $this->isolatedDatabase();
        try {
            $response = ['data' => ['list' => [[
                'hotelId' => '1001', 'date' => '2026-08-31', 'listExposure' => 123,
            ]]]];
            $service = new OnlineDailyDataPersistenceService();
            self::assertSame(0, $service->parseAndSaveTrafficData(
                $response, '2026-08-29', '2026-08-30', 'ctrip', 7, 'ctrip', '1001', 'browser_profile_api'
            ));
            self::assertSame(0, Db::name('online_daily_data')->count());
            self::assertSame(1, $service->parseAndSaveTrafficData(
                $response, '2026-08-29', '2026-08-31', 'ctrip', 7, 'ctrip', '1001', 'browser_profile_api'
            ));
            self::assertSame(1, (int)Db::name('online_daily_data')
                ->where('data_date', '2026-08-31')->value('readback_verified'));
        } finally {Db::connect()->close();}
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testUndatedMultiDayTrafficIsNotAssignedToFirstDay(): void
    {
        $this->isolatedDatabase();
        try {
            $response = ['data' => ['list' => [[
                'hotelId' => '1001', 'listExposure' => 123,
            ]]]];
            $service = new OnlineDailyDataPersistenceService();
            self::assertSame(0, $service->parseAndSaveTrafficData(
                $response, '2026-08-29', '2026-08-30', 'ctrip', 7, 'ctrip', '1001', 'browser_profile_api'
            ));
            self::assertSame(0, Db::name('online_daily_data')->count());
            self::assertSame(1, $service->parseAndSaveTrafficData(
                $response, '2026-08-29', '2026-08-29', 'ctrip', 7, 'ctrip', '1001', 'browser_profile_api'
            ));
        } finally {Db::connect()->close();}
    }
}
