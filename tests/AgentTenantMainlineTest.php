<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\model\AgentConfig;
use app\model\PriceSuggestion;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class AgentTenantMainlineTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'agent_tenant_mainline_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['agent_logs', 'price_suggestions', 'demand_forecasts', 'agent_configs', 'room_types', 'users', 'hotels', 'roles'] as $table) {
            Db::name($table)->delete(true);
        }
        $this->seedFixture();
    }

    public function testSuperAdminAgentConfigForecastSuggestionAndLogMainlineKeepsHotelTenant(): void
    {
        $config = $this->responseData($this->controller(['hotel_id' => 20, 'agent_type' => 2])->getConfig());
        self::assertSame(1, (int)$config['id']);
        self::assertSame('existing', $config['config_data']['marker']);

        $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'agent_type' => AgentConfig::AGENT_TYPE_REVENUE,
            'is_enabled' => 1,
            'config_data' => ['marker' => 'updated'],
        ])->saveConfig());
        self::assertSame(1, Db::name('agent_configs')->where('hotel_id', 20)->where('agent_type', 2)->count());
        $storedConfig = Db::name('agent_configs')->where('id', 1)->find();
        self::assertSame(10, (int)$storedConfig['tenant_id']);
        self::assertSame('updated', json_decode((string)$storedConfig['config_data'], true, 512, JSON_THROW_ON_ERROR)['marker']);

        $forecastDate = date('Y-m-d', strtotime('+1 day'));
        $forecast = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'forecast_date' => $forecastDate,
            'room_type_id' => 100,
            'forecast_method' => 3,
            'predicted_occupancy' => 82,
            'predicted_demand' => 9,
            'confidence_score' => 0.85,
        ])->createForecast());
        $storedForecast = Db::name('demand_forecasts')->where('id', (int)$forecast['id'])->find();
        self::assertSame(10, (int)$storedForecast['tenant_id']);
        self::assertSame(20, (int)$storedForecast['hotel_id']);

        $today = date('Y-m-d');
        $suggestions = $this->responseData($this->controller([
            'hotel_id' => 20,
            'date' => $today,
            'page' => 1,
            'page_size' => 20,
        ])->priceSuggestions());
        self::assertSame(1, (int)$suggestions['pagination']['total']);
        self::assertSame(1, (int)$suggestions['list'][0]['id']);

        $this->responseData($this->controller([
            'id' => 1,
            'action' => 'approve',
            'remark' => 'tenant-safe approval',
        ])->approvePrice());
        $approved = Db::name('price_suggestions')->where('id', 1)->find();
        self::assertSame(10, (int)$approved['tenant_id']);
        self::assertSame(PriceSuggestion::STATUS_APPROVED, (int)$approved['status']);
        self::assertSame(1, (int)$approved['applied_by']);

        $overview = $this->responseData($this->controller(['hotel_id' => 20])->overview());
        self::assertTrue($overview['agents']['revenue']['enabled']);
        self::assertNotEmpty($overview['recent_logs']);

        $logs = $this->responseData($this->controller([
            'hotel_id' => 20,
            'agent_type' => 2,
            'page' => 1,
            'page_size' => 50,
        ])->logs());
        self::assertGreaterThanOrEqual(3, (int)$logs['pagination']['total']);
        self::assertSame([20], array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['hotel_id'],
            $logs['list']
        ))));
        self::assertSame([10], array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['tenant_id'],
            $logs['list']
        ))));
    }

    private function controller(array $get = [], array $post = []): Agent
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->setMethod($post === [] ? 'GET' : 'POST')
            ->setUrl('/api/agent/test')
            ->setBaseUrl('/api/agent/test')
            ->setPathinfo('api/agent/test')
            ->withGet($get)
            ->withPost($post)
            ->withHeader(['Accept' => 'application/json']);
        $request->user = \app\model\User::find(1);
        self::$app->instance('request', $request);
        return new Agent(self::$app);
    }

    /** @return array<string, mixed> */
    private function responseData(\think\Response $response): array
    {
        self::assertSame(200, $response->getCode(), (string)$response->getContent());
        $decoded = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $decoded['code'], (string)$response->getContent());
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private function seedFixture(): void
    {
        Db::name('roles')->insert(['id' => 1, 'name' => 'admin', 'display_name' => 'Super admin', 'level' => 1, 'permissions' => '["all"]', 'status' => 1]);
        Db::name('hotels')->insertAll([
            ['id' => 20, 'tenant_id' => 10, 'name' => 'Tenant 10 hotel', 'status' => 1],
            ['id' => 30, 'tenant_id' => 99, 'name' => 'Tenant 99 hotel', 'status' => 1],
        ]);
        Db::name('users')->insert(['id' => 1, 'tenant_id' => 0, 'username' => 'root', 'password' => 'fixture', 'role_id' => 1, 'status' => 1]);
        Db::name('room_types')->insert(['id' => 100, 'hotel_id' => 20, 'name' => 'Deluxe', 'base_price' => 300, 'min_price' => 250, 'max_price' => 450, 'room_count' => 10, 'sort_order' => 1, 'is_enabled' => 1, 'facilities' => '[]']);
        Db::name('agent_configs')->insertAll([
            ['id' => 1, 'tenant_id' => 10, 'hotel_id' => 20, 'agent_type' => 2, 'is_enabled' => 0, 'config_data' => '{"marker":"existing"}'],
            ['id' => 2, 'tenant_id' => 99, 'hotel_id' => 30, 'agent_type' => 2, 'is_enabled' => 1, 'config_data' => '{"marker":"other-tenant"}'],
        ]);
        Db::name('agent_logs')->insert(['tenant_id' => 99, 'hotel_id' => 30, 'agent_type' => 2, 'action' => 'other_tenant', 'message' => 'must stay outside hotel 20', 'log_level' => 2, 'context_data' => '{}', 'user_id' => 1]);
        Db::name('price_suggestions')->insertAll([
            $this->suggestionRow(1, 10, 20, 100),
            $this->suggestionRow(2, 99, 30, null),
        ]);
    }

    /** @return array<string, mixed> */
    private function suggestionRow(int $id, int $tenantId, int $hotelId, ?int $roomTypeId): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'room_type_id' => $roomTypeId,
            'suggestion_date' => date('Y-m-d'),
            'suggestion_type' => 1,
            'current_price' => 300,
            'suggested_price' => 330,
            'min_price' => 250,
            'max_price' => 450,
            'confidence_score' => 0.8,
            'competitor_data' => '{}',
            'factors' => '{}',
            'status' => 1,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, level INTEGER, permissions TEXT, status INTEGER)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, role_id INTEGER, status INTEGER)');
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, base_price REAL, min_price REAL, max_price REAL, room_count INTEGER, sort_order INTEGER, is_enabled INTEGER, facilities TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE agent_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, agent_type INTEGER NOT NULL, is_enabled INTEGER, config_data TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE agent_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, agent_type INTEGER, action TEXT, message TEXT, log_level INTEGER, context_data TEXT, user_id INTEGER, create_time TEXT)');
        Db::execute('CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, room_type_id INTEGER, forecast_date TEXT, forecast_method INTEGER, predicted_occupancy REAL, predicted_demand INTEGER, confidence_score REAL, actual_occupancy REAL, is_event_driven INTEGER, event_factors TEXT, historical_data TEXT, remark TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE price_suggestions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, room_type_id INTEGER, demand_forecast_id INTEGER, suggestion_date TEXT, suggestion_type INTEGER, current_price REAL, suggested_price REAL, min_price REAL, max_price REAL, confidence_score REAL, competitor_data TEXT, factors TEXT, status INTEGER, applied_by INTEGER, applied_time TEXT, remark TEXT, create_time TEXT, update_time TEXT)');
    }
}
