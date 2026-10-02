<?php
declare(strict_types=1);
use app\service\CtripCompetitionCirclePersistenceService;
use app\service\CtripCompetitiveOperationsService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class CtripCompetitiveRoleBoundaryTest extends TestCase
{
    private function raw(string $id, float $amount = 900): array
    {
        return ['hotelId' => $id, 'hotelName' => '我的酒店', 'isSelf' => true, 'compareType' => 'self',
            'amount' => $amount, 'quantity' => 10, 'bookOrderNum' => 8, 'commentScore' => 4.7,
            'qunarCommentScore' => 4.8, 'amountRank' => 1, 'dataDate' => '2026-08-30'];
    }
    private function row(string $id, float $amount = 900): array
    {
        $raw = $this->raw($id, $amount);
        $semantics = CtripCompetitionCirclePersistenceService::normalizeRowSemantics($raw, ['self_hotel_ids' => ['1001']]);
        self::assertSame('normal', $semantics['validation_status']);
        return array_merge($semantics, ['id' => (int)$id, 'hotel_id' => $id, 'hotel_name' => $raw['hotelName'],
            'system_hotel_id' => 7, 'platform' => 'Ctrip', 'source' => 'ctrip',
            'data_date' => '2026-08-30', 'readback_verified' => 1, 'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE)]);
    }
    public function testNormalizedCompetitorCannotBecomeSelfThroughOldNamesOrMarkers(): void
    {
        $wrong = $this->row('2001');
        self::assertSame('competitor', $wrong['compare_type']);
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([$this->row('1001', 0), $wrong], [], [], '1001');
        self::assertSame(0.0, $result['business_comparison']['self']['amount']);
        self::assertSame(900.0, $result['business_comparison']['competitor_average']['amount']);
        self::assertSame('competitor', $result['business_comparison']['hotels'][1]['compare_type']);
        self::assertSame(1, $result['competitor_profiles']['competitor_count']);
    }
    public function testBoundHotelMismatchNeverInventsSelfEvenWithoutOwnRows(): void
    {
        $row = $this->row('2001'); $row['compare_type'] = 'self';
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '1001');
        self::assertNull($result['business_comparison']['self']);
        self::assertSame(900.0, $result['business_comparison']['competitor_average']['amount']);
        self::assertSame('partial', $result['business_comparison']['status']);
    }
    public function testTrafficMarkerCannotOverrideKnownOwnHotelButAverageSentinelSurvives(): void
    {
        $rows = [];
        foreach ([['1001', 0], ['2001', 900], ['-1', 300]] as [$id, $list]) {
            $rows[] = ['id' => count($rows) + 1, 'hotel_id' => $id, 'data_date' => '2026-08-30',
                'compare_type' => $id === '-1' ? 'competitor_avg' : 'self', 'validation_status' => 'normal', 'readback_verified' => 1,
                'list_exposure' => $list, 'detail_exposure' => 0, 'order_filling_num' => 0, 'order_submit_num' => 0];
        }
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], $rows, [], '1001');
        self::assertSame(0.0, $result['traffic_funnel_comparison']['self']['list_exposure']);
        self::assertNull($result['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertSame(300.0, $result['traffic_funnel_comparison']['competitor_average']['list_exposure']);
    }
    public function testLegacyUnboundFallbackAndBoundOwnIdentityRemainCompatible(): void
    {
        $row = $this->row('1001', 0);$row['compare_type'] = 'competitor';
        $bound = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '1001');
        $legacy = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '');
        self::assertSame(0.0, $bound['business_comparison']['self']['amount']);
        self::assertSame(0.0, $legacy['business_comparison']['self']['amount']);
        $row['readback_verified'] = 0;
        $missing = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '1001');
        self::assertNull($missing['business_comparison']['self']);
    }
    private function isolatedDatabase(): void
    {
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>(string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>(string)getenv('SUXIOS_CACHE_PATH').'/logs/']]], 'log');
        Config::set(['default'=>'competition_role_test','connections'=>['competition_role_test'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::name('hotels')->insertAll([['id'=>7,'tenant_id'=>2],['id'=>8,'tenant_id'=>3]]);
        Db::execute('CREATE TABLE system_configs (id INTEGER PRIMARY KEY, config_key TEXT, config_value TEXT)');
        $binding = ['7'=>['system_hotel_id'=>7,'tenant_id'=>2,'ota_hotel_id'=>'1001','updated_at'=>'2026-08-30 12:00:00']];
        Db::name('system_configs')->insert(['config_key'=>'ctrip_public_hotel_bindings','config_value'=>json_encode($binding)]);
        Db::execute('CREATE TABLE ota_ctrip_entity_snapshots (id INTEGER PRIMARY KEY, system_hotel_id INTEGER, source TEXT, entity_type TEXT, data_date TEXT, last_seen_at TEXT)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT, data_type TEXT, dimension TEXT, compare_type TEXT,
            data_date TEXT, amount REAL, quantity REAL, book_order_num REAL, comment_score REAL, qunar_comment_score REAL,
            list_exposure REAL, detail_exposure REAL, order_filling_num REAL, order_submit_num REAL,
            raw_data TEXT, validation_status TEXT, validation_flags TEXT, readback_verified INTEGER DEFAULT 0,
            readback_verified_at TEXT, ingestion_method TEXT, data_source_id INTEGER, sync_task_id INTEGER,
            source_trace_id TEXT, data_period TEXT, is_final INTEGER, snapshot_time TEXT, update_time TEXT, create_time TEXT)');
    }
    private function persist(string $date, int $hotel = 7): array
    {
        $rows = [$this->raw('1001', 0), $this->raw('2001', 900)];
        foreach ($rows as &$row) $row['dataDate'] = $date;
        unset($row);
        return (new CtripCompetitionCirclePersistenceService())->persistRows(
            $rows, $date, $hotel, [
                'self_hotel_ids'=>['1001'], 'fetched_at'=>$date.' 12:00:00',
                'data_source_id'=>11, 'sync_task_id'=>12, 'source_trace_id'=>'synthetic-role-'.$hotel.'-'.$date,
                'ingestion_method'=>'manual_cookie_api', 'requested_business_date'=>$date, 'source_business_date'=>$date,
                'date_verification_status'=>'verified', 'response_dates'=>[$date],
                'response_date_evidence'=>[['date'=>$date,'path'=>'data.dataDate']],
            ]);
    }
    public function testActualPersistenceReadbackAndBuildPreserveRolesAndDatabaseFacts(): void
    {
        $this->isolatedDatabase();
        try {
            $receipt = $this->persist('2026-08-30');
            self::assertTrue($receipt['readback_verified']);
            self::assertSame(2, $receipt['saved_count']);
            $exact = Db::name('online_daily_data')->whereIn('id', $receipt['row_ids'])->order('id')->select()->toArray();
            self::assertSame(['self','competitor'], array_column($exact, 'compare_type'));
            self::assertSame(['normal','normal'], array_column($exact, 'validation_status'));
            $this->persist('2026-08-29'); $this->persist('2026-08-30', 8);
            $result = (new CtripCompetitiveOperationsService())->build(7, '2026-08-30', '2026-08-30');
            self::assertSame('bound', $result['context']['binding_status']);
            self::assertSame(2, $result['data_coverage']['business_row_count']);
            self::assertSame(0.0, $result['business_comparison']['self']['amount']);
            self::assertSame(900.0, $result['business_comparison']['competitor_average']['amount']);
            self::assertSame($exact, Db::name('online_daily_data')->whereIn('id', $receipt['row_ids'])->order('id')->select()->toArray());
        } finally { Db::connect()->close(); }
    }
}
