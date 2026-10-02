<?php
declare(strict_types=1);
namespace Tests;

use app\controller\OnlineData;
use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use app\service\RevenueOperatingLedgerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class MeituanBusinessSalesReadbackTest extends TestCase
{
    protected function setUp(): void
    {
        $path = (string)getenv('SUXIOS_CACHE_PATH');
        Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>$path]]], 'cache');
        Config::set(['default'=>'file','channels'=>['file'=>['type'=>'File','path'=>$path.'/logs/']]], 'log');
        Config::set(['default'=>'sales_cards','connections'=>['sales_cards'=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'','fields_strict'=>false]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('INSERT INTO hotels VALUES (81,7),(82,8)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT, data_type TEXT, dimension TEXT, compare_type TEXT, data_date TEXT,
            amount REAL, quantity REAL, book_order_num REAL, comment_score REAL, qunar_comment_score REAL,
            list_exposure REAL, detail_exposure REAL, order_filling_num REAL, order_submit_num REAL, data_value REAL, flow_rate REAL,
            raw_data TEXT, validation_status TEXT, validation_flags TEXT, readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT,
            ingestion_method TEXT, source_trace_id TEXT, data_period TEXT, is_final INTEGER, snapshot_time TEXT, snapshot_bucket TEXT,
            update_time TEXT, create_time TEXT)');
    }

    protected function tearDown(): void { Db::connect()->close(); }

    private function invoke(string $method, array $args): mixed
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller, $method))->invokeArgs($controller, $args);
    }

    public static function cases(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/fixtures/dual-ota/meituan-business-sales-cards.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixture['cases'] as $case) $cases[$case['name']] = [$case];
        return $cases;
    }

    private function rows(array $case): array
    {
        return $this->invoke('buildMeituanCapturedDailyRows', [$case['payload'], 81]);
    }

    private function saveRead(array $rows): array
    {
        self::assertSame(count($rows), $this->invoke('saveMeituanCapturedDailyRows', [$rows]));
        $exact = Db::name('online_daily_data')->where('system_hotel_id',81)->order('id')->select()->toArray();
        foreach ($exact as $row) {
            self::assertSame(1, (int)$row['readback_verified']);
            self::assertSame(7, (int)$row['tenant_id']);
            self::assertSame('2026-09-26', $row['data_date']);
            self::assertSame('synthetic-81', $row['hotel_id']);
        }
        return $exact;
    }

    #[DataProvider('cases')]
    public function testCapturedBusinessRowsKeepSourceSemanticsAfterActualSaveAndReadback(array $case): void
    {
        $rows = $this->rows($case);
        $gate = BrowserProfileCaptureRequestService::assessMeituanPersistenceGate($case['payload'], $rows, '2026-09-26', ['synthetic-81']);
        if (!$case['expected']['usable_business_payload']) {
            self::assertTrue(($gate['ok'] ?? false) !== true || $rows === []);
            self::assertSame(0, Db::name('online_daily_data')->count());
            return;
        }
        self::assertTrue($gate['ok']);
        $exact = $this->saveRead($rows);
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($exact);
        $fact = $dataset['fact_ota_daily'][0];
        $metrics = new OtaRevenueMetricService();
        $summary = $metrics->summarizeDataset($dataset);
        $valid = in_array($case['name'], ['positive','zero_positive_nights','zero_pair','zero_pair_missing_average'], true);
        if (!$valid) {
            self::assertNull($fact['room_revenue']);
            self::assertNull($fact['room_revenue_basis']);
            self::assertNull($summary['totals']['adr']);
            return;
        }
        self::assertSame('verified_meituan_business_sales_cards', $fact['room_revenue_basis']);
        self::assertSame('meituan_business_sales_daily', $fact['metric_semantic_scope']);
        self::assertSame((float)$case['expected']['metrics']['amount'], $fact['room_revenue']);
        self::assertSame((float)$case['expected']['metrics']['quantity'], $fact['room_nights']);
        self::assertSame($case['name'] === 'positive' ? 200.0 : ($case['name'] === 'zero_positive_nights' ? 0.0 : null), $summary['totals']['adr']);
        self::assertSame([], $metrics->ledgerEntries($dataset,7,82,'meituan'));
        self::assertSame([], $metrics->ledgerEntries($dataset,7,81,'ctrip'));
        $entries = $metrics->ledgerEntries($dataset,7,81,'meituan');
        foreach ($entries as &$entry) $entry['evidence_mode'] = 'synthetic';
        unset($entry);
        $ledger = (new RevenueOperatingLedgerService())->build(['tenant_id'=>7,'hotel_id'=>81,
            'start_date'=>'2026-09-26','end_date'=>'2026-09-26','platforms'=>['meituan'],'evidence_mode'=>'synthetic'],$entries);
        $room = array_column($ledger['metrics'],null,'metric_key')['room_revenue'];
        self::assertNull($room['value'], 'no fabricated provenance, currency or unit proof');
        self::assertSame($fact['room_revenue'], $room['days'][0]['entries'][0]['value']);
        self::assertContains('source_not_verified', $room['days'][0]['entries'][0]['reason_codes']);
    }

    public function testCorrectionToZeroAndRetryUseSameStoredIdentity(): void
    {
        $cases = self::cases();
        $first = $this->saveRead($this->rows($cases['positive'][0]));
        foreach (['correction','retry'] as $operation) {
            $exact = $this->saveRead($this->rows($cases['zero_pair'][0]));
            self::assertSame($first[0]['id'], $exact[0]['id'], $operation);
            self::assertCount(1, $exact);
            $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($exact);
            $summary = (new OtaRevenueMetricService())->summarizeDataset($dataset);
            self::assertSame(0.0, $summary['totals']['room_revenue']);
            self::assertSame(0.0, $summary['totals']['room_nights']);
            self::assertNull($summary['totals']['adr']);
        }
    }

    public function testSaveDoesNotOverwriteAnotherTenantRowWithSamePlatformIdentity(): void
    {
        $rows = $this->rows(self::cases()['positive'][0]);
        self::assertNotEmpty($rows);
        $source = $rows[0];
        self::assertSame(1, $this->invoke('saveMeituanCapturedDailyRows', [[$source]]));
        $foreignId = (int)Db::name('online_daily_data')->value('id');
        Db::name('online_daily_data')->where('id', $foreignId)->update([
            'tenant_id' => 8, 'amount' => 999,
        ]);

        self::assertSame(1, $this->invoke('saveMeituanCapturedDailyRows', [[$source]]));
        $foreign = Db::name('online_daily_data')->where('id', $foreignId)->find();
        self::assertSame(8, (int)$foreign['tenant_id']);
        self::assertSame(999.0, (float)$foreign['amount']);
        $own = Db::name('online_daily_data')->where('tenant_id', 7)->select()->toArray();
        self::assertCount(1, $own);
        self::assertNotSame($foreignId, (int)$own[0]['id']);
        self::assertSame(1, (int)$own[0]['readback_verified']);
    }

    public function testMissingOrMismatchedCapturedProofDoesNotPromoteZero(): void
    {
        $rows = $this->rows(self::cases()['zero_pair'][0]);
        foreach (['no_facts','amount_only','wrong_source','raw_mismatch','wrong_period','not_self'] as $case) {
            $changed = $rows;
            $raw = json_decode($changed[0]['raw_data'],true,512,JSON_THROW_ON_ERROR);
            if ($case === 'no_facts') unset($raw['field_facts']);
            elseif ($case === 'amount_only') $raw['field_facts'] = array_values(array_filter($raw['field_facts'],static fn($f)=>$f['metric_key']==='sales_amount'));
            elseif ($case === 'wrong_source') $raw['_meituan_business_metric_sources']['sales_amount']['source_kind'] = 'field';
            elseif ($case === 'raw_mismatch') $raw['sales_room_nights'] = 4;
            elseif ($case === 'wrong_period') $raw['date_scope_evidence'] = '';
            else $raw['is_self'] = false;
            $changed[0]['raw_data'] = json_encode($raw,JSON_THROW_ON_ERROR);
            $fact = (new OtaStandardEtlService())->buildDatasetFromRows($changed)['fact_ota_daily'][0] ?? [];
            self::assertNull($fact['room_revenue'] ?? null,$case);
        }
    }
}
