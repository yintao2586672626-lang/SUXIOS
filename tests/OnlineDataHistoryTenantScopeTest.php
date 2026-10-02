<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\BusinessDisplayConcern;
use app\controller\concern\OnlineDataAnalyticsConcern;
use app\controller\concern\OnlineDataHistoryConcern;
use app\controller\concern\OnlineDataQualityConcern;
use app\controller\concern\OnlineDataSummaryConcern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataHistoryTenantScopeTest extends TestCase
{
    private static array $originalDatabaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        $connection = 'online_history_tenant_test_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, hotel_id TEXT,
            hotel_name TEXT, data_date TEXT, source TEXT, platform TEXT, data_type TEXT,
            dimension TEXT, compare_type TEXT, status TEXT, validation_status TEXT,
            readback_verified INTEGER, raw_data TEXT, amount REAL, quantity INTEGER,
            book_order_num INTEGER, data_value REAL, list_exposure INTEGER,
            detail_exposure INTEGER, order_submit_num INTEGER, create_time TEXT, update_time TEXT
        )');
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 70, 'name' => 'synthetic-7', 'status' => 1],
            ['id' => 8, 'tenant_id' => 80, 'name' => 'synthetic-8', 'status' => 1],
            ['id' => 9, 'tenant_id' => 90, 'name' => 'synthetic-9', 'status' => 1],
        ]);
        foreach ([
            [1, 70, 7, '2026-09-01', 'ctrip'],
            [2, 80, 7, '2026-09-02', 'ctrip'],
            [3, 80, 8, '2026-09-03', 'ctrip'],
            [4, 70, 7, '2026-09-04', 'meituan'],
            [5, 80, 7, '2026-09-05', 'meituan'],
            [6, 0, 7, '2026-09-06', 'meituan'],
            [7, 80, 9, '2026-09-07', 'ctrip'],
        ] as [$id, $tenantId, $hotelId, $date, $source]) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'system_hotel_id' => $hotelId,
                'hotel_id' => 'synthetic-platform-' . $hotelId, 'hotel_name' => 'synthetic-' . $hotelId,
                'data_date' => $date, 'source' => $source, 'platform' => ucfirst($source),
                'data_type' => 'traffic', 'dimension' => 'traffic', 'compare_type' => 'self',
                'status' => '', 'validation_status' => 'normal', 'readback_verified' => 1,
                'raw_data' => '{}', 'amount' => 1, 'quantity' => 1,
                'book_order_num' => 1, 'data_value' => 1, 'list_exposure' => 1,
                'detail_exposure' => 1, 'order_submit_num' => 1,
                'create_time' => '2026-09-04 09:00:00', 'update_time' => '2026-09-04 09:00:00',
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        try { Db::connect()->close(); } catch (\Throwable) {}
        Config::set(self::$originalDatabaseConfig, 'database');
        if (is_file(self::$databasePath)) unlink(self::$databasePath);
    }

    private function controller(array $parameters = [], bool $canViewOnlineData = true, array $permittedHotelIds = [7]): object
    {
        $user = new class($canViewOnlineData, $permittedHotelIds) {
            public int $hotel_id;
            public function __construct(private bool $canViewOnlineData, private array $permittedHotelIds)
            {
                $this->hotel_id = (int)($permittedHotelIds[0] ?? 0);
            }
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return $this->permittedHotelIds; }
            public function hasHotelPermission(int $hotelId, string $capability): bool
            {
                return $this->canViewOnlineData
                    && in_array($hotelId, $this->permittedHotelIds, true)
                    && $capability === 'can_view_online_data';
            }
        };
        return new class($user, $parameters) {
            use BusinessDisplayConcern;
            use OnlineDataAnalyticsConcern;
            use OnlineDataHistoryConcern;
            use OnlineDataQualityConcern;
            use OnlineDataSummaryConcern;

            public object $currentUser;
            public object $request;

            public function __construct(object $user, array $parameters)
            {
                $this->currentUser = $user;
                $this->request = new class($user, $parameters) {
                    public function __construct(public object $user, private array $parameters) {}
                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->parameters[$key] ?? $default;
                    }
                };
            }

            private function getOnlineDailyDataColumns(): array
            {
                $columns = [];
                foreach (Db::query('PRAGMA table_info(online_daily_data)') as $column) {
                    $columns[(string)$column['name']] = true;
                }
                return $columns;
            }

            private function getSystemHotelName(int $hotelId): string
            {
                return trim((string)Db::name('hotels')->where('id', $hotelId)->value('name'));
            }

            private function checkPermission(): void
            {
                if (!$this->currentUser) {
                    throw new \RuntimeException('Unauthenticated synthetic controller');
                }
            }

            private function permittedHotelIdsForAction(string $capability): ?array
            {
                return array_values(array_filter(
                    $this->currentUser->getPermittedHotelIds(),
                    fn(int $hotelId): bool => $this->currentUser->hasHotelPermission($hotelId, $capability)
                ));
            }

            protected function success(mixed $data = null, string $message = 'ok'): Response
            {
                return json(['code' => 200, 'message' => $message, 'data' => $data], 200);
            }

            protected function error(string $message = 'error', int $code = 400, mixed $data = null): Response
            {
                return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
            }
        };
    }

    private function body(Response $response): array
    {
        return json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testListAndPaginationCountExcludeWrongTenantRowsEvenAtViewableHotel(): void
    {
        $body = $this->body($this->controller(['hotel_id' => '7', 'platform' => 'ctrip'])->history());
        self::assertSame(200, $body['code']);
        self::assertSame(1, $body['data']['total']);
        self::assertSame([1], array_column($body['data']['list'], 'id'));

        $ctrip = $this->body($this->controller(['hotel_id' => '7'])->ctripHistory());
        self::assertSame(200, $ctrip['code']);
        self::assertSame(1, $ctrip['data']['total']);
        self::assertSame([1], array_column($ctrip['data']['list'], 'id'));

        $meituan = $this->body($this->controller(['hotel_id' => '7', 'platform' => 'meituan'])->history());
        self::assertSame(200, $meituan['code']);
        self::assertSame(1, $meituan['data']['total']);
        self::assertSame([4], array_column($meituan['data']['list'], 'id'));

        $date = $this->body($this->controller([
            'hotel_id' => '7', 'start_date' => '2026-09-02', 'end_date' => '2026-09-02',
        ])->history());
        self::assertSame(200, $date['code']);
        self::assertSame(0, $date['data']['total']);
        self::assertSame([], $date['data']['list']);

        $latest = $this->body($this->controller([
            'hotel_id' => '7', 'range' => '2026-09-02',
        ])->ctripLatest());
        self::assertSame(200, $latest['code'], (string)($latest['message'] ?? ''));
        self::assertSame('empty', $latest['data']['traffic']['status']);
        self::assertSame(0, $latest['data']['traffic']['total']);

        $validLatest = $this->body($this->controller([
            'hotel_id' => '7', 'range' => '2026-09-01',
        ])->ctripLatest());
        self::assertSame(200, $validLatest['code'], (string)($validLatest['message'] ?? ''));
        self::assertSame(1, $validLatest['data']['traffic']['total']);
    }

    public function testExactIdRejectsWrongTenantAndOtherHotelWhileKeepingValidRow(): void
    {
        $controller = $this->controller();
        $valid = $this->body($controller->historyDetail(1));
        self::assertSame(200, $valid['code']);
        self::assertSame(1, $valid['data']['id']);

        foreach ([2, 3, 5, 6] as $id) {
            $response = $controller->historyDetail($id);
            self::assertSame(403, $response->getCode());
            self::assertSame(403, $this->body($response)['code']);
        }
    }

    public function testMeituanTrafficHistoryKeepsReadbackButDoesNotCertifyDefaultBusinessDate(): void
    {
        foreach ([150 => 'page.business_date', 151 => 'capture_context.default_data_date'] as $id => $dateSource) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => 70, 'system_hotel_id' => 7,
                'hotel_id' => 'synthetic-platform-7', 'data_date' => '2026-09-14',
                'source' => 'meituan', 'platform' => 'Meituan', 'data_type' => 'traffic',
                'compare_type' => 'self', 'validation_status' => 'verified',
                'readback_verified' => 1, 'list_exposure' => 120,
                'raw_data' => json_encode([
                    'date_source' => $dateSource,
                    'source_trace_id' => 'synthetic-history-' . $id,
                    'ingestion_method' => 'authorized_api_collection',
                    'captured_at' => '2026-09-14 12:00:00',
                ], JSON_THROW_ON_ERROR),
                'create_time' => '2026-09-14 12:00:00',
                'update_time' => '2026-09-14 12:00:00',
            ]);
        }
        try {
            $controller = $this->controller();
            $valid = $this->body($controller->historyDetail(150));
            $defaulted = $this->body($controller->historyDetail(151));
            self::assertSame(200, $valid['code']);
            self::assertSame('success', $valid['data']['status']);
            self::assertSame(200, $defaulted['code']);
            self::assertSame(1, (int)$defaulted['data']['readback_verified']);
            self::assertSame('partial', $defaulted['data']['status']);
            self::assertSame('capture_context.default_data_date', $defaulted['data']['raw_data_json']['date_source']);
            $list = $this->body($this->controller([
                'platform' => 'meituan', 'hotel_id' => '7',
                'start_date' => '2026-09-14', 'end_date' => '2026-09-14',
            ])->history());
            self::assertSame(200, $list['code']);
            self::assertSame('partial', $list['data']['list'][0]['status']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [150, 151])->delete();
        }
    }

    public function testMeituanOrderHistoryKeepsDefaultDatedReadbackButMarksItPartial(): void
    {
        foreach ([152 => 'order_time', 153 => 'capture_context.default_data_date'] as $id => $dateSource) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => 70, 'system_hotel_id' => 7,
                'hotel_id' => 'synthetic-platform-7', 'data_date' => '2026-09-15',
                'source' => 'meituan', 'platform' => 'Meituan', 'data_type' => 'order',
                'compare_type' => 'self', 'validation_status' => 'verified',
                'readback_verified' => 1, 'book_order_num' => 2,
                'raw_data' => json_encode([
                    'date_basis' => $id === 152 ? 'order_date' : 'unknown',
                    'date_source' => $dateSource,
                    'source_trace_id' => 'synthetic-history-' . $id,
                    'ingestion_method' => 'authorized_api_collection',
                    'captured_at' => '2026-09-15 12:00:00',
                ], JSON_THROW_ON_ERROR),
                'create_time' => '2026-09-15 12:00:00',
                'update_time' => '2026-09-15 12:00:00',
            ]);
        }
        try {
            $controller = $this->controller();
            $valid = $this->body($controller->historyDetail(152));
            $defaulted = $this->body($controller->historyDetail(153));
            self::assertSame('success', $valid['data']['status']);
            self::assertSame(1, (int)$defaulted['data']['readback_verified']);
            self::assertSame('partial', $defaulted['data']['status']);
            self::assertSame('capture_context.default_data_date', $defaulted['data']['raw_data_json']['date_source']);
            $list = $this->body($this->controller([
                'platform' => 'meituan', 'hotel_id' => '7',
                'start_date' => '2026-09-15', 'end_date' => '2026-09-15',
            ])->history());
            self::assertSame('partial', $list['data']['list'][0]['status']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [152, 153])->delete();
        }
    }

    public function testMeituanBusinessHistoryKeepsDefaultDatedReadbackButMarksItPartial(): void
    {
        foreach ([154 => 'page.business_period_selection.readback', 155 => 'capture_context.default_data_date'] as $id => $dateSource) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => 70, 'system_hotel_id' => 7,
                'hotel_id' => 'synthetic-platform-7', 'data_date' => '2026-09-16',
                'source' => 'meituan', 'platform' => 'Meituan', 'data_type' => 'business',
                'compare_type' => 'self', 'validation_status' => 'verified',
                'readback_verified' => 1, 'amount' => 700,
                'raw_data' => json_encode([
                    'date_source' => $dateSource,
                    'source_trace_id' => 'synthetic-history-' . $id,
                    'ingestion_method' => 'authorized_api_collection',
                    'captured_at' => '2026-09-16 12:00:00',
                ], JSON_THROW_ON_ERROR),
                'create_time' => '2026-09-16 12:00:00',
                'update_time' => '2026-09-16 12:00:00',
            ]);
        }
        try {
            $controller = $this->controller();
            $valid = $this->body($controller->historyDetail(154));
            $defaulted = $this->body($controller->historyDetail(155));
            self::assertSame('success', $valid['data']['status']);
            self::assertSame(1, (int)$defaulted['data']['readback_verified']);
            self::assertSame('partial', $defaulted['data']['status']);
            self::assertSame('capture_context.default_data_date', $defaulted['data']['raw_data_json']['date_source']);
            $list = $this->body($this->controller([
                'platform' => 'meituan', 'hotel_id' => '7',
                'start_date' => '2026-09-16', 'end_date' => '2026-09-16',
            ])->history());
            self::assertSame('partial', $list['data']['list'][0]['status']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [154, 155])->delete();
        }
    }

    public function testHistorySummarySqlDoesNotCountDefaultDatedTrafficAsSuccess(): void
    {
        Db::execute('CREATE TEMP TABLE history_status_probe (
            id INTEGER PRIMARY KEY, history_status TEXT, platform TEXT,
            source TEXT, data_type TEXT, raw_data TEXT
        )');
        try {
            Db::name('history_status_probe')->insertAll([
                ['id' => 1, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'traffic',
                    'raw_data' => json_encode(['date_source' => 'page.business_date'], JSON_THROW_ON_ERROR)],
                ['id' => 2, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'traffic',
                    'raw_data' => json_encode(['date_source' => 'capture_context.default_data_date'], JSON_THROW_ON_ERROR)],
                ['id' => 3, 'history_status' => 'failed', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'traffic',
                    'raw_data' => json_encode(['date_source' => 'capture_context.default_data_date'], JSON_THROW_ON_ERROR)],
                ['id' => 4, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'order',
                    'raw_data' => json_encode(['date_source' => 'order_time'], JSON_THROW_ON_ERROR)],
                ['id' => 5, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'order',
                    'raw_data' => json_encode(['date_source' => 'capture_context.default_data_date'], JSON_THROW_ON_ERROR)],
                ['id' => 6, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'business',
                    'raw_data' => json_encode(['date_source' => 'page.business_period_selection.readback'], JSON_THROW_ON_ERROR)],
                ['id' => 7, 'history_status' => 'success', 'platform' => 'Meituan',
                    'source' => 'meituan', 'data_type' => 'business',
                    'raw_data' => json_encode(['date_source' => 'capture_context.default_data_date'], JSON_THROW_ON_ERROR)],
            ]);
            $method = new \ReflectionMethod($this->controller(), 'onlineHistoryLightweightStatusExpression');
            $expression = $method->invoke($this->controller(), [
                'history_status' => true, 'platform' => true, 'source' => true,
                'data_type' => true, 'raw_data' => true,
            ]);
            $rows = Db::query('SELECT id, ' . $expression . ' AS derived_status FROM history_status_probe ORDER BY id');
            self::assertSame(['success', 'partial', 'failed', 'success', 'partial', 'success', 'partial'], array_column($rows, 'derived_status'));
        } finally {
            Db::execute('DROP TABLE history_status_probe');
        }
    }

    public function testPlatformHistoryExcludesConflictingPersistedIdentitiesAndKeepsLegacySingleFieldRows(): void
    {
        foreach ([
            ['id' => 90, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-08',
                'source' => 'ctrip', 'platform' => 'Meituan', 'data_type' => 'traffic',
                'readback_verified' => 1, 'validation_status' => 'normal', 'raw_data' => '{}', 'amount' => 1,
                'create_time' => '2026-09-08 09:00:00'],
            ['id' => 91, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-09',
                'source' => 'ctrip', 'platform' => null, 'data_type' => 'traffic',
                'create_time' => '2026-09-09 09:00:00'],
            ['id' => 92, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-10',
                'source' => null, 'platform' => 'Ctrip', 'data_type' => 'traffic',
                'create_time' => '2026-09-10 09:00:00'],
            ['id' => 93, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-11',
                'source' => 'ctrip', 'platform' => '携程', 'data_type' => 'traffic',
                'create_time' => '2026-09-11 09:00:00'],
            ['id' => 94, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-12',
                'source' => 'ctrip', 'platform' => '美团', 'data_type' => 'traffic',
                'readback_verified' => 1, 'validation_status' => 'normal', 'raw_data' => '{}', 'amount' => 1,
                'create_time' => '2026-09-12 09:00:00'],
            ['id' => 95, 'tenant_id' => 70, 'system_hotel_id' => 7, 'data_date' => '2026-09-13',
                'source' => 'ctrip', 'platform' => 'qunar', 'data_type' => 'traffic',
                'create_time' => '2026-09-13 09:00:00'],
        ] as $row) {
            Db::name('online_daily_data')->insert($row);
        }
        try {
            $ctrip = $this->body($this->controller(['hotel_id' => '7', 'platform' => 'ctrip'])->history());
            $meituan = $this->body($this->controller(['hotel_id' => '7', 'platform' => 'meituan'])->history());
            self::assertSame(200, $ctrip['code']);
            self::assertSame(200, $meituan['code']);
            self::assertSame(4, $ctrip['data']['total']);
            self::assertEqualsCanonicalizing([1, 91, 92, 93], array_column($ctrip['data']['list'], 'id'));
            self::assertSame(1, $meituan['data']['total']);
            self::assertSame([4], array_column($meituan['data']['list'], 'id'));

            $qunar = $this->body($this->controller(['hotel_id' => '7', 'platform' => 'qunar'])->history());
            self::assertSame(200, $qunar['code']);
            self::assertSame([95], array_column($qunar['data']['list'], 'id'));
            self::assertSame('去哪儿', $qunar['data']['list'][0]['platform_label']);

            $ctripOnly = $this->body($this->controller(['hotel_id' => '7'])->ctripHistory());
            self::assertSame(200, $ctripOnly['code']);
            self::assertSame(4, $ctripOnly['data']['total']);
            self::assertEqualsCanonicalizing([1, 91, 92, 93], array_column($ctripOnly['data']['list'], 'id'));

            $exact = $this->body($this->controller(['hotel_id' => '7', 'range' => '2026-09-08'])->ctripLatest());
            self::assertSame(200, $exact['code']);
            self::assertSame('empty', $exact['data']['traffic']['status']);

            $qunarDate = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-09-13',
            ])->ctripLatest());
            self::assertSame('empty', $qunarDate['data']['traffic']['status']);

            $all = $this->body($this->controller([
                'hotel_id' => '7', 'start_date' => '2026-09-08', 'end_date' => '2026-09-08',
            ])->history());
            self::assertSame(200, $all['code']);
            self::assertSame(1, $all['data']['total']);
            self::assertSame('渠道身份冲突', $all['data']['list'][0]['platform_label']);
            self::assertSame('unverified', $all['data']['list'][0]['status']);
            $detail = $this->body($this->controller()->historyDetail(90));
            self::assertSame('渠道身份冲突', $detail['data']['platform_label']);
            self::assertSame('unverified', $detail['data']['status']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [90, 91, 92, 93, 94, 95])->delete();
        }
    }

    public function testCtripExactDateReadbackPreservesMissingMetricsFromOwnedStoredRow(): void
    {
        Db::name('online_daily_data')->insert([
            'id' => 8, 'tenant_id' => 70, 'system_hotel_id' => 7,
            'hotel_id' => 'synthetic-platform-7', 'hotel_name' => 'synthetic-7',
            'data_date' => '2026-07-29', 'source' => 'ctrip', 'platform' => 'Ctrip',
            'data_type' => 'traffic', 'compare_type' => 'self', 'dimension' => 'traffic',
            'readback_verified' => 1, 'raw_data' => '{}',
            'create_time' => '2026-07-30 09:00:00', 'update_time' => '2026-07-30 09:00:00',
        ]);
        Db::name('online_daily_data')->insert([
            'id' => 9, 'tenant_id' => 70, 'system_hotel_id' => 7,
            'hotel_id' => 'synthetic-platform-7', 'hotel_name' => 'synthetic-7',
            'data_date' => '2026-07-28', 'source' => 'ctrip', 'platform' => 'Ctrip',
            'data_type' => 'traffic', 'compare_type' => 'self', 'dimension' => 'traffic',
            'readback_verified' => 1, 'raw_data' => '{}',
            'list_exposure' => 0, 'detail_exposure' => 0, 'order_submit_num' => 0,
            'create_time' => '2026-07-29 09:00:00', 'update_time' => '2026-07-29 09:00:00',
        ]);
        try {
            $body = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-07-29',
            ])->ctripLatest());
            self::assertSame(200, $body['code'], (string)($body['message'] ?? ''));
            self::assertSame('7', $body['data']['metadata']['hotel_id']);
            self::assertSame('2026-07-29', $body['data']['traffic']['data_date']);
            self::assertSame(1, $body['data']['traffic']['total']);
            self::assertSame('partial', $body['data']['traffic']['status']);
            self::assertSame('role_partial', $body['data']['traffic']['verification_status']);
            self::assertSame(['competitor_avg'], $body['data']['traffic']['missing_traffic_roles']);
            self::assertSame('self', $body['data']['traffic']['display_traffic_rows'][0]['compareType']);
            self::assertNull($body['data']['traffic']['display_traffic_rows'][0]['listExposure']);
            self::assertNull($body['data']['traffic']['display_traffic_summary']['self']['listExposure']);
            self::assertNull($body['data']['traffic']['display_traffic_summary']['avg']['listExposure']);

            $storedZero = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-07-28',
            ])->ctripLatest());
            self::assertSame(200, $storedZero['code']);
            self::assertSame(0, $storedZero['data']['traffic']['display_traffic_rows'][0]['listExposure']);
            self::assertSame(0, $storedZero['data']['traffic']['display_traffic_summary']['self']['listExposure']);
            self::assertNull($storedZero['data']['traffic']['display_traffic_summary']['avg']['listExposure']);
            self::assertNull($storedZero['data']['traffic']['display_traffic_rows'][0]['orderFillingNum']);

            $otherHotel = $this->body($this->controller([
                'hotel_id' => '8', 'range' => '2026-07-29',
            ], true, [7, 8])->ctripLatest());
            self::assertSame(0, $otherHotel['data']['traffic']['total']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [8, 9])->delete();
        }
    }

    public function testCtripExactDateTrafficRejectsStoredRowsWithAnotherSourceBusinessDate(): void
    {
        foreach ([
            [10, '2026-08-12', '2026-08-11', 'self', 999, '2026-08-13 11:00:00'],
            [11, '2026-08-12', '2026-08-12', 'self', 12, '2026-08-13 10:00:00'],
            [12, '2026-08-12', '2026-08-11', 'competitor_avg', 888, '2026-08-13 11:00:00'],
            [13, '2026-08-12', '2026-08-12', 'competitor_avg', 15, '2026-08-13 10:00:00'],
            [14, '2026-08-13', '2026-08-12', 'self', 777, '2026-08-14 10:00:00'],
            [15, '2026-08-13', 'invalid-date', 'competitor_avg', 666, '2026-08-14 10:00:00'],
            [16, '2026-08-16', '2026-08-16', 'self', 0, '2026-08-17 10:00:00'],
            [17, '2026-08-16', '2026-08-16', 'competitor_avg', 0, '2026-08-17 10:00:00'],
            [18, '2026-08-18', '2026-08-18', 'competitor_avg', 7, '2026-08-19 10:00:00'],
        ] as [$id, $storedDate, $sourceDate, $role, $exposure, $updatedAt]) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => 70, 'system_hotel_id' => 7,
                'hotel_id' => 'synthetic-platform-7', 'hotel_name' => 'synthetic-7',
                'data_date' => $storedDate, 'source' => 'ctrip', 'platform' => 'Ctrip',
                'data_type' => 'traffic', 'compare_type' => $role, 'dimension' => 'traffic',
                'readback_verified' => 1,
                'raw_data' => json_encode(['dataDate' => $sourceDate, 'listExposure' => $exposure], JSON_THROW_ON_ERROR),
                'create_time' => $updatedAt, 'update_time' => $updatedAt,
            ]);
        }
        try {
            $mixed = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-08-12',
            ])->ctripLatest());
            self::assertSame(200, $mixed['code'], (string)($mixed['message'] ?? ''));
            self::assertSame('partial', $mixed['data']['traffic']['status']);
            self::assertSame('target_date_partial', $mixed['data']['traffic']['verification_status']);
            self::assertSame('target_date_partial', $mixed['data']['traffic']['response_date_status']);
            self::assertSame(2, $mixed['data']['traffic']['total']);
            self::assertSame(2, $mixed['data']['traffic']['mismatched_record_count']);
            self::assertSame([11, 13], array_column($mixed['data']['traffic']['rows'], '_record_id'));
            self::assertSame(['2026-08-12', '2026-08-12'], array_column($mixed['data']['traffic']['display_traffic_rows'], 'date'));
            self::assertEquals(12, $mixed['data']['traffic']['display_traffic_summary']['self']['listExposure']);
            self::assertEquals(15, $mixed['data']['traffic']['display_traffic_summary']['avg']['listExposure']);

            $wrongDate = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-08-13',
            ])->ctripLatest());
            self::assertSame(200, $wrongDate['code'], (string)($wrongDate['message'] ?? ''));
            self::assertSame('date_mismatch', $wrongDate['data']['traffic']['status']);
            self::assertSame('date_mismatch', $wrongDate['data']['metadata']['status']);
            self::assertSame('target_date_mismatch', $wrongDate['data']['metadata']['verification_status']);
            self::assertSame(0, $wrongDate['data']['traffic']['total']);
            self::assertSame(2, $wrongDate['data']['traffic']['mismatched_record_count']);
            self::assertSame([], $wrongDate['data']['traffic']['rows']);
            self::assertSame([], $wrongDate['data']['traffic']['display_traffic_rows']);
            self::assertSame(['2026-08-12'], $wrongDate['data']['traffic']['source_business_dates_excluded']);

            $completeZero = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-08-16',
            ])->ctripLatest());
            self::assertSame('success', $completeZero['data']['traffic']['status']);
            self::assertSame([], $completeZero['data']['traffic']['missing_traffic_roles']);
            self::assertSame(0, $completeZero['data']['traffic']['display_traffic_summary']['self']['listExposure']);
            self::assertSame(0, $completeZero['data']['traffic']['display_traffic_summary']['avg']['listExposure']);

            $competitorOnly = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-08-18',
            ])->ctripLatest());
            self::assertSame('partial', $competitorOnly['data']['traffic']['status']);
            self::assertSame(['self'], $competitorOnly['data']['traffic']['missing_traffic_roles']);
            self::assertNull($competitorOnly['data']['traffic']['display_traffic_summary']['self']['listExposure']);
            self::assertEquals(7, $competitorOnly['data']['traffic']['display_traffic_summary']['avg']['listExposure']);
        } finally {
            Db::name('online_daily_data')->whereIn('id', [10, 11, 12, 13, 14, 15, 16, 17, 18])->delete();
        }
    }

    public function testCtripExactDateTrafficReadbackKeepsObservedZeroSourceRates(): void
    {
        foreach ([
            [19, 'self', 100, 20],
            [20, 'competitor_avg', 80, 10],
        ] as [$id, $role, $exposure, $visitors]) {
            Db::name('online_daily_data')->insert([
                'id' => $id, 'tenant_id' => 70, 'system_hotel_id' => 7,
                'hotel_id' => 'synthetic-platform-7', 'hotel_name' => 'synthetic-7',
                'data_date' => '2026-08-20', 'source' => 'ctrip', 'platform' => 'Ctrip',
                'data_type' => 'traffic', 'compare_type' => $role, 'dimension' => 'traffic',
                'readback_verified' => 1,
                'raw_data' => json_encode([
                    'dataDate' => '2026-08-20', 'listExposure' => $exposure,
                    'detailExposure' => $visitors, 'orderFillingNum' => 5,
                    'orderSubmitNum' => 1, 'flowRate' => 0,
                    'orderFillRate' => 0, 'submitRate' => 0,
                ], JSON_THROW_ON_ERROR),
                'create_time' => '2026-08-21 10:00:00', 'update_time' => '2026-08-21 10:00:00',
            ]);
        }
        try {
            $body = $this->body($this->controller([
                'hotel_id' => '7', 'range' => '2026-08-20',
            ])->ctripLatest());
            self::assertSame(200, $body['code'], (string)($body['message'] ?? ''));
            self::assertSame('success', $body['data']['traffic']['status']);
            self::assertSame([19, 20], array_column($body['data']['traffic']['rows'], '_record_id'));
            foreach ($body['data']['traffic']['display_traffic_rows'] as $row) {
                self::assertSame(0, $row['flowRate']);
                self::assertSame(0, $row['orderFillRate']);
                self::assertSame(0, $row['submitRate']);
            }
            foreach (['self', 'avg'] as $role) {
                self::assertSame(0, $body['data']['traffic']['display_traffic_summary'][$role]['flowRate']);
                self::assertSame(0, $body['data']['traffic']['display_traffic_summary'][$role]['orderFillRate']);
                self::assertSame(0, $body['data']['traffic']['display_traffic_summary'][$role]['submitRate']);
            }
        } finally {
            Db::name('online_daily_data')->whereIn('id', [19, 20])->delete();
        }
    }

    public function testDailyDataListDoesNotDecodeOrCountWrongTenantRows(): void
    {
        $ctrip = $this->body($this->controller([
            'system_hotel_id' => '7', 'source' => 'ctrip', 'page_size' => 1,
        ])->dailyDataList());
        self::assertSame(200, $ctrip['code'], (string)($ctrip['message'] ?? ''));
        self::assertSame(1, $ctrip['data']['pagination']['total']);
        self::assertSame([1], array_column($ctrip['data']['list'], 'id'));

        $meituan = $this->body($this->controller([
            'system_hotel_id' => '7', 'source' => 'meituan',
        ])->dailyDataList());
        self::assertSame(200, $meituan['code'], (string)($meituan['message'] ?? ''));
        self::assertSame(1, $meituan['data']['pagination']['total']);
        self::assertSame([4], array_column($meituan['data']['list'], 'id'));

        $wrongDate = $this->body($this->controller([
            'system_hotel_id' => '7', 'start_date' => '2026-09-02', 'end_date' => '2026-09-02',
        ])->dailyDataList());
        self::assertSame(200, $wrongDate['code'], (string)($wrongDate['message'] ?? ''));
        self::assertSame(0, $wrongDate['data']['pagination']['total']);
        self::assertSame([], $wrongDate['data']['list']);
    }

    public function testDailySummaryExcludesWrongTenantRowsBeforeTruthProjection(): void
    {
        $wrongDate = $this->body($this->controller([
            'system_hotel_id' => '7', 'source' => 'ctrip',
            'start_date' => '2026-09-02', 'end_date' => '2026-09-02',
        ])->dailyDataSummary());
        self::assertSame(200, $wrongDate['code'], (string)($wrongDate['message'] ?? ''));
        self::assertSame(0, $wrongDate['data']['truth_context']['persistence']['record_count']);
        self::assertNull($wrongDate['data']['total']['total_amount']);
        self::assertSame('pending', $wrongDate['data']['total']['data_status']);

        $validDate = $this->body($this->controller([
            'system_hotel_id' => '7', 'source' => 'ctrip',
            'start_date' => '2026-09-01', 'end_date' => '2026-09-01',
        ])->dailyDataSummary());
        self::assertSame(200, $validDate['code'], (string)($validDate['message'] ?? ''));
        self::assertSame(1, $validDate['data']['truth_context']['persistence']['record_count']);

        foreach (['2026-09-05', '2026-09-06'] as $unownedDate) {
            $meituan = $this->body($this->controller([
                'system_hotel_id' => '7', 'source' => 'meituan',
                'start_date' => $unownedDate, 'end_date' => $unownedDate,
            ])->dailyDataSummary());
            self::assertSame(200, $meituan['code'], (string)($meituan['message'] ?? ''));
            self::assertSame(0, $meituan['data']['truth_context']['persistence']['record_count']);
        }

        $validMeituan = $this->body($this->controller([
            'system_hotel_id' => '7', 'source' => 'meituan',
            'start_date' => '2026-09-04', 'end_date' => '2026-09-04',
        ])->dailyDataSummary());
        self::assertSame(200, $validMeituan['code'], (string)($validMeituan['message'] ?? ''));
        self::assertSame(1, $validMeituan['data']['truth_context']['persistence']['record_count']);
    }

    public function testDailySummaryRequiresOnlineDataViewPermission(): void
    {
        $withoutCapability = $this->controller(['system_hotel_id' => '7'], false)->dailyDataSummary();
        self::assertSame(403, $withoutCapability->getCode());
    }

    public function testHotelPickerExcludesUnownedOnlyHotel(): void
    {
        $controller = $this->controller([], true, [9]);
        $hotels = $this->body($controller->hotelList());
        self::assertSame(200, $hotels['code'], (string)($hotels['message'] ?? ''));
        self::assertSame([], $hotels['data']);
    }

    public function testAnalysisExcludesUnownedOnlyHotel(): void
    {
        $analysis = $this->body($this->controller([
            'system_hotel_id' => '9', 'source' => 'ctrip', 'data_type' => 'traffic',
            'start_date' => '2026-09-07', 'end_date' => '2026-09-07',
        ], true, [9])->dataAnalysis());
        self::assertSame(200, $analysis['code'], (string)($analysis['message'] ?? ''));
        self::assertSame(0, $analysis['data']['summary']['scoped_record_count']);
        self::assertNull($analysis['data']['summary']['total_amount']);
        self::assertSame([], $analysis['data']['aggregated']);
    }

    public function testAnalysisKeepsSameTenantPlatformAndBusinessDateScope(): void
    {
        foreach ([['ctrip', '2026-09-01'], ['meituan', '2026-09-04']] as [$source, $date]) {
            $analysis = $this->body($this->controller([
                'system_hotel_id' => '7', 'source' => $source, 'data_type' => 'traffic',
                'start_date' => $date, 'end_date' => $date,
            ])->dataAnalysis());
            self::assertSame(200, $analysis['code'], (string)($analysis['message'] ?? ''));
            self::assertSame(1, $analysis['data']['summary']['scoped_record_count']);
            self::assertNotSame('ok', $analysis['data']['summary']['data_status']);
        }
    }

    #[DataProvider('invalidSummaryAndAnalysisDateRanges')]
    public function testSummaryAndAnalysisRejectInvalidBusinessDates(string $method, array $dates): void
    {
        $response = $this->controller($dates + ['system_hotel_id' => '7'])->{$method}();
        self::assertSame(422, $response->getCode());
        self::assertSame(422, $this->body($response)['code']);
    }

    public static function invalidSummaryAndAnalysisDateRanges(): array
    {
        $cases = [
            'invalid day' => ['start_date' => '2026-02-31', 'end_date' => '2026-09-04'],
            'reversed' => ['start_date' => '2026-09-05', 'end_date' => '2026-09-04'],
            'timestamp' => ['start_date' => '2026-09-01T00:00:00+08:00'],
            'array' => ['start_date' => ['2026-09-01']],
        ];
        $result = [];
        foreach (['dailyDataSummary', 'dataAnalysis'] as $method) {
            foreach ($cases as $name => $dates) {
                $result[$method . ': ' . $name] = [$method, $dates];
            }
        }
        return $result;
    }
}
