<?php
declare(strict_types=1);

use app\service\ChannelEconomicsService;
use app\service\MeituanMarketingFactProjectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ChannelEconomicsSourceReceiptsTest extends TestCase
{
    private static array $config;
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$config = Config::get('database');
        self::$path = sys_get_temp_dir() . '/channel-marketing-source-' . bin2hex(random_bytes(5)) . '.sqlite';
        $config = self::$config;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$path, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE ota_settlement_import_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, platform TEXT, period_start TEXT, period_end TEXT, imported_at TEXT)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, hotel_id TEXT, data_date TEXT, source TEXT, platform TEXT, data_type TEXT, dimension TEXT, amount REAL, list_exposure INTEGER, detail_exposure INTEGER, raw_data TEXT, history_status TEXT, validation_status TEXT, readback_verified INTEGER, source_trace_id TEXT, snapshot_time TEXT, data_period TEXT, ingestion_method TEXT)');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$config, 'database');
        Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    protected function setUp(): void
    {
        Db::name('online_daily_data')->delete(true);
        for ($day = 1; $day <= 28; $day++) Db::name('online_daily_data')->insert($this->row($day));
    }

    private function row(int $day, float $spend = 10, string $basis = 'basis-B', string $campaign = 'synthetic-campaign'): array
    {
        $date = sprintf('2026-02-%02d', $day);
        return ['id' => $day, 'tenant_id' => 7, 'system_hotel_id' => 80, 'hotel_id' => 'synthetic-poi80',
            'data_date' => $date, 'source' => 'meituan', 'platform' => 'meituan', 'data_type' => 'advertising',
            'dimension' => 'advertising', 'amount' => $spend, 'list_exposure' => 100, 'detail_exposure' => 10,
            'raw_data' => json_encode(['campaign_id' => $campaign, 'spend' => $spend, 'attributed_order_amount' => $spend * 4, 'attribution_basis' => $basis], JSON_THROW_ON_ERROR),
            'history_status' => 'success', 'validation_status' => 'verified', 'readback_verified' => 1,
            'source_trace_id' => 'synthetic-marketing-' . $day, 'snapshot_time' => $date . ' 23:00:00',
            'data_period' => 'same_day_cumulative', 'ingestion_method' => 'browser_profile'];
    }

    private function marketing(): array
    {
        return (new ChannelEconomicsService())->sourceReceipts(7, 80, 'meituan', '2026-02')['marketing'];
    }

    public function testVerifiedZeroSpendDayIsCompleteMonetaryEvidence(): void
    {
        Db::name('online_daily_data')->where('id', 1)->update($this->row(1, 0));
        $projection = (new MeituanMarketingFactProjectionService())->project(7, 80, '2026-02-01');
        self::assertSame('partial', $projection['status']);
        self::assertSame('spend_not_positive', $projection['projections'][0]['metrics']['roas_status']);
        $marketing = $this->marketing();
        self::assertTrue($marketing['complete']);
        self::assertSame([], $marketing['missing_days']);
        self::assertSame(270.0, $marketing['advertising_spend']);
        self::assertSame(1080.0, $marketing['attributed_order_amount']);
        self::assertCount(28, $marketing['evidence_refs']);
    }

    public function testAllZeroMonthHasRealZeroAmountsAndUndefinedRoas(): void
    {
        for ($day = 1; $day <= 28; $day++) Db::name('online_daily_data')->where('id', $day)->update($this->row($day, 0));
        $sources = (new ChannelEconomicsService())->sourceReceipts(7, 80, 'meituan', '2026-02');
        self::assertTrue($sources['marketing']['complete']);
        self::assertSame(0.0, $sources['marketing']['advertising_spend']);
        $result = (new ChannelEconomicsService())->calculate([
            'advertising_included_in_net_revenue' => false, 'advertising_in_direct_costs' => false,
        ], $sources);
        self::assertSame(0.0, $result['inputs']['advertising_spend']);
        self::assertSame(0.0, $result['inputs']['attributed_order_amount']);
        self::assertNull($result['attributed_roas']);
        self::assertSame('unverified', $result['source_quality']);
        self::assertFalse($result['evidence_chain']['independently_verified']);
    }

    public static function missingMonthCases(): array
    {
        return ['all facts missing' => [false], 'all facts invalid' => [true]];
    }

    #[DataProvider('missingMonthCases')]
    public function testMissingOrInvalidWholeMonthNeverProducesKnownZeroTotals(bool $invalid): void
    {
        if ($invalid) Db::name('online_daily_data')->where('id', '>', 0)->update(['detail_exposure' => 101]);
        else Db::name('online_daily_data')->delete(true);
        $marketing = $this->marketing();
        self::assertFalse($marketing['complete']);
        self::assertSame([], $marketing['covered_days']);
        self::assertCount(28, $marketing['missing_days']);
        self::assertNull($marketing['known_advertising_spend']);
        self::assertNull($marketing['known_attributed_order_amount']);
        self::assertSame([], $marketing['evidence_refs']);
        self::assertNull($marketing['attribution_basis']);
    }

    public function testOneVerifiedZeroDayWithOtherDaysMissingKeepsKnownRealZero(): void
    {
        Db::name('online_daily_data')->where('id', '>', 1)->delete();
        Db::name('online_daily_data')->where('id', 1)->update($this->row(1, 0));
        $marketing = $this->marketing();
        self::assertFalse($marketing['complete']);
        self::assertSame(['2026-02-01'], $marketing['covered_days']);
        self::assertSame(0.0, $marketing['known_advertising_spend']);
        self::assertSame(0.0, $marketing['known_attributed_order_amount']);
        self::assertNull($marketing['advertising_spend']);
        self::assertNull($marketing['attributed_order_amount']);
    }

    public function testInvalidFirstDayCannotPoisonFollowingBasisAndSources(): void
    {
        $row = $this->row(1, 10, 'basis-A');
        $row['detail_exposure'] = 101;
        Db::name('online_daily_data')->where('id', 1)->update($row);
        $marketing = $this->marketing();
        self::assertFalse($marketing['complete']);
        self::assertSame(['2026-02-01'], $marketing['missing_days']);
        self::assertCount(27, $marketing['covered_days']);
        self::assertSame(270.0, $marketing['known_advertising_spend']);
        self::assertSame(1080.0, $marketing['known_attributed_order_amount']);
        self::assertSame('basis-B', $marketing['attribution_basis']);
        self::assertNotContains('online_daily_data#1', $marketing['evidence_refs']);
    }

    public function testRejectedDayDoesNotLeakItsFirstValidCampaignReference(): void
    {
        $row = $this->row(1, 5, 'basis-B', 'second-campaign');
        $row['id'] = 100;
        $row['raw_data'] = json_encode(['campaign_id' => 'second-campaign', 'spend' => 5, 'attributed_order_amount' => 20, 'spend_basis' => 'basis-B', 'attributed_order_amount_basis' => 'different-basis'], JSON_THROW_ON_ERROR);
        Db::name('online_daily_data')->insert($row);
        $projection = (new MeituanMarketingFactProjectionService())->project(7, 80, '2026-02-01');
        self::assertSame(['online_daily_data#1'], $projection['projections'][0]['evidence_refs']);
        $marketing = $this->marketing();
        self::assertSame(['2026-02-01'], $marketing['missing_days']);
        self::assertNotContains('online_daily_data#1', $marketing['evidence_refs']);
        self::assertNotContains('online_daily_data#100', $marketing['evidence_refs']);
        self::assertSame(270.0, $marketing['known_advertising_spend']);
    }

    public function testInternallyAlignedCampaignsWithDifferentDayBasesDoNotPoisonTheMonth(): void
    {
        Db::name('online_daily_data')->where('id', 1)->update($this->row(1, 10, 'basis-A'));
        $row = $this->row(1, 5, 'basis-C', 'second-campaign');
        $row['id'] = 100;
        Db::name('online_daily_data')->insert($row);
        self::assertSame('ready', (new MeituanMarketingFactProjectionService())->project(7, 80, '2026-02-01')['status']);
        $marketing = $this->marketing();
        self::assertSame(['2026-02-01'], $marketing['missing_days']);
        self::assertSame('basis-B', $marketing['attribution_basis']);
        self::assertSame(270.0, $marketing['known_advertising_spend']);
        self::assertNotContains('online_daily_data#1', $marketing['evidence_refs']);
        self::assertNotContains('online_daily_data#100', $marketing['evidence_refs']);
    }

    public static function invalidSourceCases(): array
    {
        return [
            'no exact readback' => [['readback_verified' => 0]],
            'validation failed' => [['validation_status' => 'failed']],
            'wrong tenant' => [['tenant_id' => 8]],
            'wrong hotel' => [['system_hotel_id' => 81]],
            'wrong date' => [['data_date' => '2026-03-01']],
            'wrong platform' => [['platform' => 'ctrip', 'source' => 'ctrip']],
            'missing source trace' => [['source_trace_id' => '']],
            'invalid traffic' => [['detail_exposure' => 101]],
            'zero amount with invalid traffic' => [['amount' => 0, 'detail_exposure' => 101, 'raw_data' => '{"campaign_id":"synthetic-campaign","spend":0,"attributed_order_amount":0,"attribution_basis":"basis-B"}']],
            'missing attribution basis' => [['raw_data' => '{"campaign_id":"synthetic-campaign","spend":10,"attributed_order_amount":40}']],
            'negative spend' => [['amount' => -10, 'raw_data' => '{"campaign_id":"synthetic-campaign","spend":-10,"attributed_order_amount":40,"attribution_basis":"basis-B"}']],
            'non-finite attributed amount' => [['raw_data' => '{"campaign_id":"synthetic-campaign","spend":10,"attributed_order_amount":1e309,"attribution_basis":"basis-B"}']],
            'amount out of range' => [['amount' => 1000000000001, 'raw_data' => '{"campaign_id":"synthetic-campaign","spend":1000000000001,"attributed_order_amount":40,"attribution_basis":"basis-B"}']],
        ];
    }

    #[DataProvider('invalidSourceCases')]
    public function testIncompleteOrInvalidSourceNeverBecomesCompleteMonthlyEvidence(array $changes): void
    {
        Db::name('online_daily_data')->where('id', 1)->update($changes);
        $marketing = $this->marketing();
        self::assertFalse($marketing['complete']);
        self::assertContains('2026-02-01', $marketing['missing_days']);
        self::assertNull($marketing['advertising_spend']);
        self::assertNull($marketing['attributed_order_amount']);
        self::assertSame(270.0, $marketing['known_advertising_spend']);
        self::assertNotContains('online_daily_data#1', $marketing['evidence_refs']);
    }
}
