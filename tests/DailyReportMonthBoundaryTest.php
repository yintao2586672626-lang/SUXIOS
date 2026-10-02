<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\DailyReport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DailyReportMonthBoundaryTest extends TestCase
{
    private static array $originalDatabaseConfig;
    private static string $databasePath;
    private static DailyReport $controller;

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        $connection = 'daily_report_month_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = $connection;
        $database['connections'][$connection] = [
            'type' => 'sqlite', 'database' => self::$databasePath,
            'prefix' => '', 'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_name TEXT, status INTEGER)');
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, report_date TEXT,
            report_data TEXT, submitter_id INTEGER, status INTEGER
        )');
        Db::execute('CREATE TABLE monthly_tasks (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, year INTEGER, month INTEGER, task_data TEXT
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT
        )');
        foreach ([7, 8] as $hotelId) {
            Db::name('hotels')->insert(['id' => $hotelId, 'tenant_id' => 70,
                'hotel_name' => 'synthetic-' . $hotelId, 'status' => 1]);
        }
        foreach ([
            [701, 7, '2026-09-01', 100],
            [702, 7, '2026-09-20', 200],
            [703, 7, '2026-08-31', 900],
            [704, 7, '2026-09-21', 500],
            [801, 8, '2026-09-20', 700],
            [710, 7, '2026-10-01', 40],
            [711, 7, '2026-10-20', 60],
            [720, 7, '2026-01-01', 5],
            [721, 7, '2026-01-02', null],
        ] as [$id, $hotelId, $reportDate, $revenue]) {
            Db::name('daily_reports')->insert(['id' => $id, 'tenant_id' => 70,
                'hotel_id' => $hotelId, 'report_date' => $reportDate,
                'report_data' => json_encode($revenue === null ? [] : ['revenue' => $revenue], JSON_THROW_ON_ERROR),
                'submitter_id' => null, 'status' => 2]);
        }
        foreach ([[731, '2026-11-01', 100, 2], [732, '2026-11-02', 900, 1],
            [733, '2026-11-03', 300, 2], [741, '2026-12-01', 500, 1]] as [$id, $date, $revenue, $status]) {
            Db::name('daily_reports')->insert(['id' => $id, 'tenant_id' => 70, 'hotel_id' => 7,
                'report_date' => $date, 'report_data' => json_encode(['revenue' => $revenue], JSON_THROW_ON_ERROR),
                'submitter_id' => null, 'status' => $status]);
        }

        self::$controller = (new ReflectionClass(DailyReport::class))->newInstanceWithoutConstructor();
        $user = new class {
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [7]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool
            {
                return $hotelId === 7 && $capability === 'can_view_report';
            }
        };
        (new ReflectionProperty(Base::class, 'currentUser'))->setValue(self::$controller, $user);
    }

    public static function tearDownAfterClass(): void
    {
        try { Db::connect()->close(); } catch (\Throwable) {}
        Config::set(self::$originalDatabaseConfig, 'database');
        if (is_file(self::$databasePath)) unlink(self::$databasePath);
    }

    private function detail(int $id): array
    {
        $response = self::$controller->detail($id);
        self::assertSame(200, $response->getCode(), (string)$response->getContent());
        $body = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        return $body['data'];
    }

    public function testSingleDigitMonthIncludesOnlySameHotelReportsThroughBusinessDate(): void
    {
        $first = $this->detail(701);
        self::assertEquals(100.0, $first['month_revenue']);
        self::assertSame('ready', $first['metric_status']['month_revenue']['status']);

        $twentieth = $this->detail(702);
        self::assertEquals(300.0, $twentieth['month_revenue']);
        self::assertEquals(200.0, $twentieth['day_revenue']);
    }

    public function testTwoDigitMonthStillExcludesPreviousAndFutureDates(): void
    {
        $detail = $this->detail(711);
        self::assertEquals(100.0, $detail['month_revenue']);
        self::assertEquals(60.0, $detail['day_revenue']);
    }

    public function testMissingDailyReportsKeepObservedMonthSumButMarkItPartial(): void
    {
        $detail = $this->detail(702);
        self::assertEquals(300.0, $detail['month_revenue']);
        self::assertSame('partial', $detail['metric_status']['month_revenue']['status']);
        self::assertSame('partial', $detail['month_coverage']['data_status']);
        self::assertSame('daily_reports', $detail['month_coverage']['source_table']);
        self::assertSame(7, $detail['month_coverage']['hotel_id']);
        self::assertSame(70, $detail['month_coverage']['tenant_id']);
        self::assertSame('2026-09-01', $detail['month_coverage']['start_date']);
        self::assertSame('2026-09-20', $detail['month_coverage']['end_date']);
        self::assertSame(20, $detail['month_coverage']['expected_days']);
        self::assertSame(2, $detail['month_coverage']['observed_days']);
        self::assertCount(18, $detail['month_coverage']['missing_dates']);
        self::assertContains('2026-09-02', $detail['month_coverage']['missing_dates']);
        self::assertContains('2026-09-19', $detail['month_coverage']['missing_dates']);
        self::assertContains('monthly_daily_reports_missing', array_column($detail['data_gaps'], 'code'));
        self::assertStringContainsString('仅代表已提交日报的观察值', $detail['data_notice']);
    }

    public function testFirstBusinessDayWithItsReportHasCompleteCoverage(): void
    {
        $detail = $this->detail(701);
        self::assertSame('ready', $detail['metric_status']['month_revenue']['status']);
        self::assertSame('complete', $detail['month_coverage']['data_status']);
        self::assertSame(1, $detail['month_coverage']['expected_days']);
        self::assertSame(1, $detail['month_coverage']['observed_days']);
        self::assertSame([], $detail['month_coverage']['missing_dates']);
    }

    public function testCompleteDateCoverageDoesNotHideMissingMetricEvidence(): void
    {
        $detail = $this->detail(721);
        self::assertSame('complete', $detail['month_coverage']['data_status']);
        self::assertSame(2, $detail['month_coverage']['observed_days']);
        self::assertNull($detail['month_revenue']);
        self::assertSame('data_gap', $detail['metric_status']['month_revenue']['status']);
    }

    public function testDraftCannotFillCoverageOrContributeToMonthRevenue(): void
    {
        $detail = $this->detail(733);
        self::assertEquals(400.0, $detail['month_revenue']);
        self::assertSame('partial', $detail['metric_status']['month_revenue']['status']);
        self::assertSame(2, $detail['month_coverage']['observed_days']);
        self::assertSame(['2026-11-02'], $detail['month_coverage']['missing_dates']);
        self::assertSame(2, $detail['month_coverage']['required_report_status']);
        self::assertSame('submitted_daily_reports_only', $detail['month_coverage']['source_policy']);
        self::assertStringContainsString('未提交日期', $detail['data_notice']);
    }

    public function testOnlyDraftReportsKeepMonthlyMetricsUnknown(): void
    {
        $detail = $this->detail(741);
        self::assertNull($detail['month_revenue']);
        self::assertSame('data_gap', $detail['metric_status']['month_revenue']['status']);
        self::assertSame(0, $detail['month_coverage']['observed_days']);
        self::assertSame(['2026-12-01'], $detail['month_coverage']['missing_dates']);
    }

    public function testSubmissionAddsTheSameDateToCoverageAndRevenue(): void
    {
        try {
            Db::name('daily_reports')->where('id', 732)->update(['status' => 2]);
            $detail = $this->detail(733);
            self::assertEquals(1300.0, $detail['month_revenue']);
            self::assertSame('ready', $detail['metric_status']['month_revenue']['status']);
            self::assertSame('complete', $detail['month_coverage']['data_status']);
            self::assertSame(3, $detail['month_coverage']['observed_days']);
            self::assertSame([], $detail['month_coverage']['missing_dates']);
        } finally {
            Db::name('daily_reports')->where('id', 732)->update(['status' => 1]);
        }
    }
}
