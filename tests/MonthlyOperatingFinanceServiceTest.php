<?php
declare(strict_types=1);

use app\service\MonthlyOperatingFinanceService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MonthlyOperatingFinanceServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'monthly_operating_finance_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DELETE FROM hotel_monthly_operating_finance_snapshots');
        Db::execute('DELETE FROM hotels');
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 7, 'name' => '酒店80'],
            ['id' => 82, 'tenant_id' => 7, 'name' => '酒店82'],
            ['id' => 83, 'tenant_id' => 7, 'name' => '酒店83'],
            ['id' => 81, 'tenant_id' => 8, 'name' => '酒店81'],
        ]);
    }

    public function testWholeHotelFormulaKeepsGopAndOwnerCashProxyBoundariesExplicit(): void
    {
        $result = (new MonthlyOperatingFinanceService())->calculate('whole_hotel', $this->completeInputs());

        self::assertSame('ready', $result['status']);
        self::assertSame(12000.0, $result['recognized_revenue']);
        self::assertSame(12000.0, $result['total_operating_revenue']);
        self::assertSame(7000.0, $result['gop']);
        self::assertSame(58.33, $result['gop_margin_percent']);
        self::assertSame(5500.0, $result['owner_cash_proxy_before_tax_capex_and_financing']);
        self::assertSame(1000.0, $result['budget_total_operating_revenue_variance']);
        self::assertSame(500.0, $result['budget_gop_variance']);
        self::assertTrue($result['boundaries']['owner_cash_proxy_is_not_accounting_cash_flow']);
        self::assertSame(0, $result['boundaries']['external_write_count']);
    }

    public function testOtaNetRevenueNeverBecomesWholeHotelGop(): void
    {
        $result = (new MonthlyOperatingFinanceService())->calculate('ota_channel', [
            'ota_net_revenue' => 5200,
            'budget_total_operating_revenue' => 6000,
            'budget_gop' => 2000,
        ]);

        self::assertSame('partial', $result['status']);
        self::assertSame(5200.0, $result['recognized_revenue']);
        self::assertNull($result['total_operating_revenue']);
        self::assertNull($result['gop']);
        self::assertContains('gop_not_calculable_from_ota_channel_scope', $result['missing_items']);
        self::assertNotContains('budget_total_operating_revenue_missing', $result['missing_items']);
        self::assertNotContains('budget_gop_missing', $result['missing_items']);
        self::assertTrue($result['boundaries']['ota_settlement_is_not_whole_hotel_revenue']);
    }

    public function testNegativeBudgetGopIsValidForAPlannedLoss(): void
    {
        $inputs = $this->completeInputs();
        $inputs['budget_gop'] = -100;

        $result = (new MonthlyOperatingFinanceService())->calculate('whole_hotel', $inputs);

        self::assertSame(-100.0, $result['inputs']['budget_gop']);
        self::assertSame(7100.0, $result['budget_gop_variance']);
    }

    public function testSaveReplayVersionAndExactReadback(): void
    {
        $service = new MonthlyOperatingFinanceService();
        $saved = $service->saveSnapshot(
            7,
            [80],
            80,
            '2026-08',
            'whole_hotel',
            $this->completeInputs(),
            ['pms_capture#101', 'manual_cost_ledger#202608'],
            $this->sourceMeta(),
            'monthly-finance-80-202608-v1',
            11
        );
        $replay = $service->saveSnapshot(
            7,
            [80],
            80,
            '2026-08',
            'whole_hotel',
            $this->completeInputs(),
            ['manual_cost_ledger#202608', 'pms_capture#101'],
            $this->sourceMeta(),
            'monthly-finance-80-202608-v1',
            11
        );
        self::assertTrue($saved['readback_verified']);
        self::assertSame(1, $saved['version_no']);
        self::assertSame($saved['id'], $replay['id']);
        self::assertTrue($replay['idempotent']);

        $changed = $this->completeInputs();
        $changed['room_operating_revenue'] = 10100;
        $v2 = $service->saveSnapshot(
            7,
            [80],
            80,
            '2026-08',
            'whole_hotel',
            $changed,
            ['pms_capture#102', 'manual_cost_ledger#202608'],
            $this->sourceMeta(),
            'monthly-finance-80-202608-v2',
            11
        );
        self::assertSame(2, $v2['version_no']);
        self::assertSame(2, Db::name('hotel_monthly_operating_finance_snapshots')->count());
        self::assertSame($v2['id'], $service->latestForHotel(7, [80], 80, '2026-08')['id']);

        Db::name('hotel_monthly_operating_finance_snapshots')->where('id', $v2['id'])->update(['hotel_id' => 90]);
        $migrated = $service->readSnapshot(7, 90, (int)$v2['id']);
        self::assertSame(90, $migrated['hotel_id']);
        self::assertSame(80, $migrated['source_hotel_id']);
    }

    public function testPortfolioRanksOnlyWhenEveryPermittedHotelHasComparableWholeHotelFacts(): void
    {
        $service = new MonthlyOperatingFinanceService();
        $service->saveSnapshot(7, [80, 82], 80, '2026-08', 'whole_hotel', $this->completeInputs(), ['pms#80', 'cost#80'], $this->sourceMeta(), 'p80', 11);
        $inputs82 = $this->completeInputs();
        $inputs82['departmental_expense'] = 4500;
        $service->saveSnapshot(7, [80, 82], 82, '2026-08', 'whole_hotel', $inputs82, ['pms#82', 'cost#82'], $this->sourceMeta(), 'p82', 11);

        $ready = $service->portfolioOverview(7, [80, 82], '2026-08');
        self::assertSame('ready', $ready['status']);
        self::assertSame('same_scope_manual_snapshot_comparable', $ready['ranking_status']);
        self::assertSame(1, $ready['items'][0]['rank']);
        self::assertSame(2, $ready['items'][1]['rank']);
        self::assertFalse($ready['employee_evaluation_authorized']);

        $partial = $service->portfolioOverview(7, [80, 82, 83], '2026-08');
        self::assertSame('partial', $partial['status']);
        self::assertSame('blocked_incomplete_or_mixed_scope', $partial['ranking_status']);
        self::assertNull($partial['items'][0]['rank']);
        self::assertSame('missing', $partial['items'][2]['status']);
    }

    public function testPortfolioBlocksRankingWhenSourceOrTaxBasisIsNotAttestedAndEmptyPermissionsFailClosed(): void
    {
        $service = new MonthlyOperatingFinanceService();
        $unverified = $this->sourceMeta();
        $unverified['source_quality_status'] = 'unverified';
        $service->saveSnapshot(7, [80, 82], 80, '2026-08', 'whole_hotel', $this->completeInputs(), ['pms#80', 'cost#80'], $unverified, 'u80', 11);
        $unknownTax = $this->sourceMeta();
        $unknownTax['tax_basis'] = 'unknown';
        $service->saveSnapshot(7, [80, 82], 82, '2026-08', 'whole_hotel', $this->completeInputs(), ['pms#82', 'cost#82'], $unknownTax, 'u82', 11);

        $portfolio = $service->portfolioOverview(7, [80, 82], '2026-08');

        self::assertSame('partial', $portfolio['status']);
        self::assertSame('blocked_incomplete_or_mixed_scope', $portfolio['ranking_status']);
        self::assertNull($portfolio['items'][0]['rank']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_outside_permitted_scope');
        $service->latestForHotel(7, [], 80, '2026-08');
    }

    /** @return array<string,float> */
    private function completeInputs(): array
    {
        return [
            'room_operating_revenue' => 10000,
            'non_room_operating_revenue' => 2000,
            'departmental_expense' => 3000,
            'undistributed_operating_expense' => 2000,
            'rent_expense' => 1000,
            'other_fixed_cash_cost' => 500,
            'budget_total_operating_revenue' => 11000,
            'budget_gop' => 6500,
        ];
    }

    /** @return array<string,string> */
    private function sourceMeta(): array
    {
        return [
            'source_method' => 'manual_entry',
            'source_quality_status' => 'operator_attested',
            'currency' => 'CNY',
            'tax_basis' => 'tax_inclusive',
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::execute('CREATE TABLE hotel_monthly_operating_finance_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_version TEXT NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            source_hotel_id INTEGER NOT NULL,
            period_month TEXT NOT NULL,
            version_no INTEGER NOT NULL,
            fact_scope TEXT NOT NULL,
            source_method TEXT NOT NULL,
            source_quality_status TEXT NOT NULL,
            currency TEXT NOT NULL,
            tax_basis TEXT NOT NULL,
            metric_definition_version TEXT NOT NULL,
            source_refs_json TEXT NOT NULL,
            inputs_json TEXT NOT NULL,
            results_json TEXT NOT NULL,
            missing_items_json TEXT NOT NULL,
            idempotency_key TEXT NOT NULL,
            content_digest TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE (tenant_id, hotel_id, period_month, version_no),
            UNIQUE (tenant_id, hotel_id, period_month, idempotency_key)
        )');
    }
}
