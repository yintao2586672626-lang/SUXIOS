<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\DailyReport;
use app\model\DailyReport as DailyReportModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\App;
use think\exception\HttpException;
use think\facade\Config;
use think\facade\Db;

final class DailyReportPartialUpdateTest extends TestCase
{
    private static array $originalDatabaseConfig;
    private static string $databasePath;
    private static DailyReport $controller;
    private static object $request;

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        $connection = 'daily_report_edit_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, report_date TEXT,
            report_data TEXT, submitter_id INTEGER, status INTEGER, create_time TEXT, update_time TEXT
        )');
        Db::execute('CREATE TABLE report_configs (
            id INTEGER PRIMARY KEY, report_type TEXT, status INTEGER, field_name TEXT
        )');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER,
            module TEXT, action TEXT, description TEXT, error_info TEXT, extra_data TEXT,
            ip TEXT, user_agent TEXT, create_time TEXT
        )');
        foreach ([7, 8] as $hotelId) {
            Db::name('hotels')->insert(['id' => $hotelId, 'tenant_id' => 70, 'status' => 1]);
        }
        Db::name('users')->insert(['id' => 701, 'tenant_id' => 70]);
        foreach (['xb_revenue', 'mt_revenue'] as $index => $field) {
            Db::name('report_configs')->insert(['id' => $index + 1,
                'report_type' => 'daily', 'status' => 1, 'field_name' => $field]);
        }
        self::$controller = (new ReflectionClass(DailyReport::class))->newInstanceWithoutConstructor();
        self::$request = new class {
            public array $body = [];
            public function post(): array { return []; }
            public function method(): string { return 'PUT'; }
            public function put(): array { return $this->body; }
            public function getContent(): string { return ''; }
        };
        (new ReflectionProperty(Base::class, 'request'))->setValue(self::$controller, self::$request);
        $user = new class {
            public int $id = 701;
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [7, 8]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool
            {
                return $hotelId === 7 && $capability === 'can_view_report';
            }
            public function hasHotelPermissionOrFail(int $hotelId, string $capability, string $message): void
            {
                if ($hotelId !== 7 || $capability !== 'can_edit_report') {
                    throw new HttpException(403, $message);
                }
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

    protected function setUp(): void
    {
        Db::name('daily_reports')->delete(true);
        Db::name('operation_logs')->delete(true);
        foreach ([7, 8] as $hotelId) {
            Db::name('daily_reports')->insert(['id' => $hotelId * 100 + 1,
                'tenant_id' => 70, 'hotel_id' => $hotelId, 'report_date' => '2026-09-20',
                'report_data' => json_encode(['xb_revenue' => 100, 'mt_revenue' => 200,
                    'legacy_note' => '旧版保留'], JSON_UNESCAPED_UNICODE),
                'submitter_id' => 701, 'status' => DailyReportModel::STATUS_SUBMITTED]);
        }
    }

    private function edit(int $id, array $payload): void
    {
        self::$request->body = $payload;
        $response = self::$controller->update($id);
        self::assertSame(200, $response->getCode(), (string)$response->getContent());
    }

    private function exact(int $id): array
    {
        return (array)DailyReportModel::find($id)?->report_data;
    }

    public function testOneEditedMetricRetainsOtherConfiguredAndLegacyFieldsAfterSave(): void
    {
        $this->edit(701, ['xb_revenue' => '150']);
        self::assertSame(['xb_revenue' => 150, 'mt_revenue' => 200,
            'legacy_note' => '旧版保留'], $this->exact(701));
        $read = self::$controller->read(701);
        self::assertSame(200, $read->getCode());
        $payload = json_decode((string)$read->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->exact(701), $payload['data']['report_data']);
    }

    public function testStatusOnlyEditDoesNotEraseSavedMeasurements(): void
    {
        $this->edit(701, ['status' => DailyReportModel::STATUS_DRAFT]);
        self::assertSame(['xb_revenue' => 100, 'mt_revenue' => 200,
            'legacy_note' => '旧版保留'], $this->exact(701));
        self::assertSame(DailyReportModel::STATUS_DRAFT, (int)DailyReportModel::find(701)?->status);
    }

    public function testExplicitZeroAndBlankCanReplaceOneFieldWithoutClearingAnother(): void
    {
        $this->edit(701, ['xb_revenue' => '0', 'mt_revenue' => '']);
        self::assertSame(['xb_revenue' => 0, 'mt_revenue' => '',
            'legacy_note' => '旧版保留'], $this->exact(701));
    }

    public function testCrossHotelEditIsRejectedBeforeAnySave(): void
    {
        self::$request->body = ['xb_revenue' => '900'];
        try {
            self::$controller->update(801);
            self::fail('Expected hotel capability denial');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(100, $this->exact(801)['xb_revenue']);
    }
}
