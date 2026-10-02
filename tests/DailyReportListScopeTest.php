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

final class DailyReportListScopeTest extends TestCase
{
    private static array $originalDatabaseConfig;
    private static string $databasePath;
    private static DailyReport $controller;
    private static object $request;

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        $connection = 'daily_report_scope_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
        Db::execute('CREATE TABLE daily_reports (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, report_date TEXT,
            report_data TEXT, submitter_id INTEGER, status INTEGER, deleted_at TEXT
        )');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_name TEXT, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        foreach ([7, 8, 9] as $hotelId) {
            Db::name('hotels')->insert(['id' => $hotelId, 'tenant_id' => 70,
                'hotel_name' => 'synthetic-' . $hotelId, 'status' => 1]);
            Db::name('daily_reports')->insert(['id' => $hotelId * 100 + 1,
                'tenant_id' => 70, 'hotel_id' => $hotelId, 'report_date' => '2026-09-20',
                'report_data' => '{}', 'submitter_id' => null, 'status' => 2]);
        }

        self::$controller = (new ReflectionClass(DailyReport::class))->newInstanceWithoutConstructor();
        self::$request = new class {
            public array $params = [];
            public function param(string $name, mixed $default = null): mixed
            {
                return $this->params[$name] ?? $default;
            }
        };
        (new ReflectionProperty(Base::class, 'request'))->setValue(self::$controller, self::$request);
    }

    public static function tearDownAfterClass(): void
    {
        try { Db::connect()->close(); } catch (\Throwable) {}
        Config::set(self::$originalDatabaseConfig, 'database');
        if (is_file(self::$databasePath)) unlink(self::$databasePath);
    }

    private function listFor(mixed $hotelId, bool $superAdmin = false, ?int $viewableHotelId = 7): array
    {
        self::$request->params = $hotelId === null ? [] : ['hotel_id' => $hotelId];
        $user = new class($superAdmin, $viewableHotelId) {
            public function __construct(private bool $superAdmin, private ?int $viewableHotelId) {}
            public function isSuperAdmin(): bool { return $this->superAdmin; }
            public function getPermittedHotelIds(): array { return [7, 8]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool
            {
                return $hotelId === $this->viewableHotelId && $capability === 'can_view_report';
            }
        };
        (new ReflectionProperty(Base::class, 'currentUser'))->setValue(self::$controller, $user);
        $response = self::$controller->index();
        return ['http_status' => $response->getCode(),
            'body' => json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR)];
    }

    public function testUnfilteredListContainsOnlyHotelsWithReportViewPermission(): void
    {
        $result = $this->listFor(null);
        self::assertSame(200, $result['http_status']);
        self::assertSame([7], array_map('intval', array_column($result['body']['data']['list'], 'hotel_id')));
    }

    public function testExplicitHotelWithoutReportViewPermissionIsRejected(): void
    {
        foreach (['8', '9'] as $hotelId) {
            $result = $this->listFor($hotelId);
            self::assertSame(403, $result['http_status']);
            self::assertSame(403, $result['body']['code']);
        }
    }

    public function testAllowedHotelAndSuperAdminKeepExactListSelection(): void
    {
        $allowed = $this->listFor('7');
        self::assertSame(200, $allowed['http_status']);
        self::assertSame([7], array_map('intval', array_column($allowed['body']['data']['list'], 'hotel_id')));
        $super = $this->listFor('9', true);
        self::assertSame(200, $super['http_status']);
        self::assertSame([9], array_map('intval', array_column($super['body']['data']['list'], 'hotel_id')));
    }

    public function testAllHotelsSentinelAndMalformedHotelFilterStayDistinct(): void
    {
        $all = $this->listFor('0');
        self::assertSame(200, $all['http_status']);
        self::assertSame([7], array_map('intval', array_column($all['body']['data']['list'], 'hotel_id')));
        $malformed = $this->listFor('not-a-hotel');
        self::assertSame(400, $malformed['http_status']);
        $arrayFilter = $this->listFor(['7']);
        self::assertSame(400, $arrayFilter['http_status']);
    }

    public function testAssociatedUserWithoutReportViewPermissionIsNotShownAFalseEmptyList(): void
    {
        $result = $this->listFor(null, false, null);
        self::assertSame(403, $result['http_status']);
        self::assertSame(403, $result['body']['code']);
    }
}
