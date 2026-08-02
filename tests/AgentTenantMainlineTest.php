<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\model\AgentConfig;
use app\model\DemandForecast;
use app\model\PriceSuggestion;
use app\service\RevenuePricingRecommendationService;
use app\service\TrustedOtaFactRepository;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\HttpException;
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
        foreach (['agent_logs', 'price_suggestions', 'demand_forecasts', 'competitor_analysis', 'agent_configs', 'room_types', 'user_hotel_permissions', 'users', 'hotels', 'roles'] as $table) {
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

    public function testScopedAiUserCanReadRevenueWorkbenchButCannotCrossHotelScope(): void
    {
        $roomTypes = $this->responseData($this->controller(['hotel_id' => 20], [], 2)->roomTypes());
        self::assertSame(100, (int)$roomTypes['list'][0]['id']);

        $suggestions = $this->responseData($this->controller([
            'hotel_id' => 20,
            'date' => date('Y-m-d'),
        ], [], 2)->priceSuggestions());
        self::assertSame(1, (int)$suggestions['pagination']['total']);

        $this->assertHttpStatus(403, fn() => $this->controller(['hotel_id' => 30], [], 2)->roomTypes());
    }

    public function testScopedAiUserWritesTenantAndCannotUseAnotherHotelsRoomType(): void
    {
        $roomType = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'name' => 'Tenant-safe room',
            'base_price' => 320,
            'min_price' => 260,
            'max_price' => 480,
            'room_count' => 8,
        ], 2)->saveRoomType());
        $roomTypeId = (int)$roomType['room_type']['id'];
        $storedRoomType = Db::name('room_types')->where('id', $roomTypeId)->find();
        self::assertSame(10, (int)$storedRoomType['tenant_id']);
        self::assertSame(20, (int)$storedRoomType['hotel_id']);

        $analysis = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'analysis_date' => date('Y-m-d'),
            'room_type_id' => 100,
            'competitor_hotel_id' => 0,
            'competitor_name' => 'Operator comparison hotel',
            'our_price' => 320,
            'competitor_price' => 300,
            'ota_platform' => 1,
        ], 2)->recordCompetitorPrice());
        $storedAnalysis = Db::name('competitor_analysis')->where('id', (int)$analysis['id'])->find();
        self::assertSame(10, (int)$storedAnalysis['tenant_id']);
        self::assertSame(20, (int)$storedAnalysis['hotel_id']);

        $forecastCount = (int)Db::name('demand_forecasts')->count();
        $this->assertHttpStatus(422, fn() => $this->controller([], [
            'hotel_id' => 20,
            'forecast_date' => date('Y-m-d', strtotime('+2 days')),
            'room_type_id' => 200,
            'forecast_method' => 3,
            'predicted_occupancy' => 75,
            'predicted_demand' => 7,
            'confidence_score' => 0.7,
        ], 2)->createForecast());
        self::assertSame($forecastCount, (int)Db::name('demand_forecasts')->count());

        $analysisCount = (int)Db::name('competitor_analysis')->count();
        $this->assertHttpStatus(422, fn() => $this->controller([], [
            'hotel_id' => 20,
            'analysis_date' => date('Y-m-d'),
            'room_type_id' => 200,
            'competitor_hotel_id' => 0,
            'competitor_name' => 'Cross-hotel attempt',
            'our_price' => 320,
            'competitor_price' => 300,
            'ota_platform' => 1,
        ], 2)->recordCompetitorPrice());
        self::assertSame($analysisCount, (int)Db::name('competitor_analysis')->count());
    }

    public function testManualDemandForecastCanBeCorrectedAndPricingReadsTheCorrection(): void
    {
        $forecastDate = date('Y-m-d', strtotime('+3 days'));
        $first = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'forecast_date' => $forecastDate,
            'room_type_id' => 100,
            'forecast_method' => DemandForecast::METHOD_HYBRID,
            'predicted_occupancy' => 92,
            'predicted_demand' => 10,
            'confidence_score' => 0.88,
        ], 2)->createForecast());

        self::assertSame('created', $first['write_action']);
        self::assertTrue($first['readback_verified']);

        $second = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'forecast_date' => $forecastDate,
            'room_type_id' => 100,
            'forecast_method' => DemandForecast::METHOD_HYBRID,
            'predicted_occupancy' => 38,
            'predicted_demand' => 3,
            'confidence_score' => 0.64,
        ], 2)->createForecast());

        self::assertSame('updated', $second['write_action']);
        self::assertTrue($second['readback_verified']);
        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(1, Db::name('demand_forecasts')
            ->where('hotel_id', 20)
            ->where('room_type_id', 100)
            ->where('forecast_date', $forecastDate)
            ->count());
        self::assertSame(38.0, (float)$second['forecast']['predicted_occupancy']);
        self::assertSame(3, (int)$second['forecast']['predicted_demand']);
        self::assertSame(0.64, (float)$second['forecast']['confidence_score']);
        self::assertSame(DemandForecast::MANUAL_INPUT_TYPE, $second['forecast']['historical_data']['input_type']);

        DemandForecast::createForecast(20, $forecastDate, [
            'room_type_id' => 100,
            'forecast_method' => DemandForecast::METHOD_ARIMA,
            'predicted_occupancy' => 96,
            'predicted_demand' => 12,
            'confidence_score' => 0.91,
            'historical_data' => ['input_type' => 'arima_model_forecast'],
        ]);

        $recommendation = $this->pricingServiceWithoutHistory()->recommend(20, [
            'id' => 100,
            'base_price' => 300,
            'min_price' => 250,
            'max_price' => 450,
            'room_count' => 10,
            'facilities' => [],
        ], $forecastDate);
        $signal = $recommendation['factors']['signals']['demand_forecast'];

        self::assertSame((int)$second['id'], (int)$signal['id']);
        self::assertSame(38.0, (float)$signal['predicted_occupancy']);
        self::assertSame(3, (int)$signal['predicted_demand']);
        self::assertSame(0.64, (float)$signal['confidence_score']);
        self::assertSame(DemandForecast::MANUAL_INPUT_TYPE, $signal['source_metadata']['input_type']);
        self::assertContains('demand_forecast:occupancy<=45', $recommendation['factor_notes']);
    }

    public function testLegacyDuplicateManualForecastUpdatesAndReadsTheNewestManualRecord(): void
    {
        $forecastDate = date('Y-m-d', strtotime('+4 days'));
        $manualMetadata = json_encode([
            'input_type' => DemandForecast::MANUAL_INPUT_TYPE,
            'input_scope' => 'manual_pricing_configuration',
            'source_scope' => 'ctrip_ota_channel',
        ], JSON_THROW_ON_ERROR);
        $olderId = 501;
        Db::name('demand_forecasts')->insert([
            'id' => $olderId,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'room_type_id' => 100,
            'forecast_date' => $forecastDate,
            'forecast_method' => DemandForecast::METHOD_HYBRID,
            'predicted_occupancy' => 81,
            'predicted_demand' => 8,
            'confidence_score' => 0.75,
            'is_event_driven' => 0,
            'event_factors' => '[]',
            'historical_data' => $manualMetadata,
            'remark' => 'legacy older manual input',
            'create_time' => '2026-07-01 10:00:00',
            'update_time' => '2026-07-01 10:00:00',
        ]);
        $newerId = 502;
        Db::name('demand_forecasts')->insert([
            'id' => $newerId,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'room_type_id' => 100,
            'forecast_date' => $forecastDate,
            'forecast_method' => DemandForecast::METHOD_HYBRID,
            'predicted_occupancy' => 87,
            'predicted_demand' => 9,
            'confidence_score' => 0.8,
            'is_event_driven' => 0,
            'event_factors' => '[]',
            'historical_data' => $manualMetadata,
            'remark' => 'legacy newer manual input',
            'create_time' => '2026-07-02 10:00:00',
            'update_time' => '2026-07-02 10:00:00',
        ]);
        $modelId = 503;
        Db::name('demand_forecasts')->insert([
            'id' => $modelId,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'room_type_id' => 100,
            'forecast_date' => $forecastDate,
            'forecast_method' => DemandForecast::METHOD_LLM,
            'predicted_occupancy' => 99,
            'predicted_demand' => 15,
            'confidence_score' => 0.95,
            'is_event_driven' => 0,
            'event_factors' => '[]',
            'historical_data' => json_encode(['input_type' => 'llm_model_forecast'], JSON_THROW_ON_ERROR),
            'remark' => 'model forecast must stay untouched',
            'create_time' => '2026-07-03 10:00:00',
            'update_time' => '2026-07-03 10:00:00',
        ]);

        $saved = $this->responseData($this->controller([], [
            'hotel_id' => 20,
            'forecast_date' => $forecastDate,
            'room_type_id' => 100,
            'forecast_method' => DemandForecast::METHOD_HYBRID,
            'predicted_occupancy' => 42,
            'predicted_demand' => 4,
            'confidence_score' => 0.68,
        ], 2)->createForecast());

        self::assertSame('updated', $saved['write_action']);
        self::assertSame((int)$newerId, (int)$saved['id']);
        self::assertSame(3, Db::name('demand_forecasts')
            ->where('hotel_id', 20)
            ->where('room_type_id', 100)
            ->where('forecast_date', $forecastDate)
            ->count());
        $storedById = [];
        foreach (Db::name('demand_forecasts')->select()->toArray() as $row) {
            $storedById[(int)$row['id']] = (float)$row['predicted_occupancy'];
        }
        self::assertSame(81.0, $storedById[$olderId], json_encode($storedById, JSON_THROW_ON_ERROR));
        self::assertSame(99.0, $storedById[$modelId], json_encode($storedById, JSON_THROW_ON_ERROR));

        $recommendation = $this->pricingServiceWithoutHistory()->recommend(20, [
            'id' => 100,
            'base_price' => 300,
            'min_price' => 250,
            'max_price' => 450,
            'room_count' => 10,
            'facilities' => [],
        ], $forecastDate);
        $signal = $recommendation['factors']['signals']['demand_forecast'];
        self::assertSame((int)$newerId, (int)$signal['id']);
        self::assertSame(42.0, (float)$signal['predicted_occupancy']);
        self::assertSame(DemandForecast::MANUAL_INPUT_TYPE, $signal['source_metadata']['input_type']);
    }

    private function controller(array $get = [], array $post = [], int $userId = 1): Agent
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
        $request->user = \app\model\User::find($userId);
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

    private function assertHttpStatus(int $expectedStatus, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected an HTTP exception with status ' . $expectedStatus);
        } catch (HttpException $exception) {
            self::assertSame($expectedStatus, $exception->getStatusCode());
        }
    }

    private function pricingServiceWithoutHistory(): RevenuePricingRecommendationService
    {
        $trustedFacts = new class extends TrustedOtaFactRepository {
            public function pricingHistory(int $systemHotelId, string $startDate, string $endDate): array
            {
                return [
                    'data_status' => 'missing',
                    'rows' => [],
                    'data_gaps' => ['test_history_missing'],
                    'source_policy' => [],
                    'data_quality' => [],
                ];
            }
        };

        return new RevenuePricingRecommendationService($trustedFacts);
    }

    private function seedFixture(): void
    {
        Db::name('roles')->insertAll([
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Super admin', 'level' => 1, 'permissions' => '["all"]', 'status' => 1],
            ['id' => 2, 'name' => 'beta_user', 'display_name' => 'Revenue operator', 'level' => 2, 'permissions' => '["can_use_ai_decision"]', 'status' => 1],
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 20, 'tenant_id' => 10, 'name' => 'Tenant 10 hotel', 'status' => 1],
            ['id' => 30, 'tenant_id' => 99, 'name' => 'Tenant 99 hotel', 'status' => 1],
        ]);
        Db::name('users')->insertAll([
            ['id' => 1, 'tenant_id' => 0, 'hotel_id' => null, 'username' => 'root', 'password' => 'fixture', 'role_id' => 1, 'status' => 1],
            ['id' => 2, 'tenant_id' => 10, 'hotel_id' => 20, 'username' => 'revenue_operator', 'password' => 'fixture', 'role_id' => 2, 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insert([
            'id' => 1,
            'tenant_id' => 10,
            'user_id' => 2,
            'hotel_id' => 20,
            'status' => 'active',
            'can_view' => 1,
            'can_ai' => 1,
        ]);
        Db::name('room_types')->insertAll([
            ['id' => 100, 'tenant_id' => 10, 'hotel_id' => 20, 'name' => 'Deluxe', 'base_price' => 300, 'min_price' => 250, 'max_price' => 450, 'room_count' => 10, 'sort_order' => 1, 'is_enabled' => 1, 'facilities' => '[]'],
            ['id' => 200, 'tenant_id' => 99, 'hotel_id' => 30, 'name' => 'Other tenant room', 'base_price' => 500, 'min_price' => 450, 'max_price' => 650, 'room_count' => 6, 'sort_order' => 1, 'is_enabled' => 1, 'facilities' => '[]'],
        ]);
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
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, username TEXT, password TEXT, role_id INTEGER, status INTEGER)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, status TEXT, can_view INTEGER, can_ai INTEGER)');
        Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER, name TEXT, base_price REAL, min_price REAL, max_price REAL, room_count INTEGER, sort_order INTEGER, is_enabled INTEGER, facilities TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE agent_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, agent_type INTEGER NOT NULL, is_enabled INTEGER, config_data TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE agent_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, agent_type INTEGER, action TEXT, message TEXT, log_level INTEGER, context_data TEXT, user_id INTEGER, create_time TEXT)');
        Db::execute('CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, room_type_id INTEGER, forecast_date TEXT, forecast_method INTEGER, predicted_occupancy REAL, predicted_demand INTEGER, confidence_score REAL, actual_occupancy REAL, is_event_driven INTEGER, event_factors TEXT, historical_data TEXT, remark TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE competitor_analysis (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, competitor_hotel_id INTEGER, analysis_date TEXT, room_type_id INTEGER, competitor_room_type_id INTEGER, our_price REAL, competitor_price REAL, price_difference REAL, price_index REAL, ota_platform INTEGER, competitor_data TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE price_suggestions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, room_type_id INTEGER, demand_forecast_id INTEGER, suggestion_date TEXT, suggestion_type INTEGER, current_price REAL, suggested_price REAL, min_price REAL, max_price REAL, confidence_score REAL, competitor_data TEXT, factors TEXT, status INTEGER, applied_by INTEGER, applied_time TEXT, remark TEXT, create_time TEXT, update_time TEXT)');
    }
}
