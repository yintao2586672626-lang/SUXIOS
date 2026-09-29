<?php
declare(strict_types=1);

namespace Tests;

use app\model\DemandForecast;
use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\DbManager;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RevenuePricingForecastZeroTest extends TestCase
{
    private DbManager $database;

    protected function setUp(): void
    {
        parent::setUp();
        // Bind ORM construction to an isolated connection without App/Env/config loading.
        $this->database = new DbManager();
        $this->database->setConfig(['default' => 'pricing_fixture', 'connections' => [
            'pricing_fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->database->connect()->close();
        parent::tearDown();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(RevenuePricingRecommendationService::class, $method))
            ->invoke(new RevenuePricingRecommendationService(), ...$args);
    }

    public function testStoredZeroForecastReachesInventoryAndLowDemandRecommendation(): void
    {
        $forecast = $this->call('forecastSignalFromModel', new DemandForecast([
            'id' => 81, 'hotel_id' => 7, 'room_type_id' => 501, 'forecast_date' => '2026-09-27',
            'predicted_occupancy' => 0, 'predicted_demand' => 0, 'confidence_score' => 0.8,
            'historical_data' => ['input_type' => 'manual_demand_forecast', 'source_scope' => 'ctrip_ota_channel'],
        ]));
        $room = ['base_price' => 200, 'min_price' => 160, 'max_price' => 260, 'room_count' => 10];
        $inventory = $this->call('inventorySignal', $room, $forecast);
        self::assertSame('ok', $inventory['data_status']);
        self::assertSame(0.0, $inventory['utilization_percent']);
        self::assertSame(0.0, $inventory['predicted_demand']);
        $result = (new RevenuePricingRecommendationService())->recommendFromSignals($room, [
            'demand_forecast' => $forecast, 'inventory' => $inventory,
            'competitor' => ['data_status' => 'ok', 'gap_percent' => -20], 'data_gaps' => [],
        ]);
        self::assertContains('demand_forecast:occupancy<=45', $result['factor_notes']);
        self::assertContains('inventory:utilization<=45', $result['factor_notes']);
        self::assertSame('decrease', $result['action']);
        self::assertGreaterThanOrEqual(160, $result['suggested_price']);
        self::assertTrue($result['advisory_only']);
        self::assertSame('ctrip_ota_channel', $forecast['source_metadata']['source_scope']);
    }

    public function testKnownZeroDemandCannotBeOverwrittenByNonzeroOccupancyFallback(): void
    {
        $inventory = $this->call('inventorySignal', ['room_count' => 10], [
            'data_status' => 'ok', 'predicted_demand' => 0, 'predicted_occupancy' => 80,
        ]);
        self::assertSame(0.0, $inventory['predicted_demand']);
        self::assertSame(0.0, $inventory['utilization_percent']);
    }

    public function testMissingStoredMetricsStayMissingAndDoNotBecomeZeroPriceDrivers(): void
    {
        $forecast = $this->call('forecastSignalFromModel', new DemandForecast([
            'id' => 82, 'room_type_id' => 501, 'forecast_date' => '2026-09-27',
            'predicted_occupancy' => null, 'predicted_demand' => null, 'confidence_score' => null,
        ]));
        self::assertNull($forecast['predicted_occupancy']);
        self::assertNull($forecast['predicted_demand']);
        self::assertNull($forecast['confidence_score']);
        self::assertSame('missing', $forecast['data_status']);
        $inventory = $this->call('inventorySignal', ['room_count' => 10], $forecast);
        self::assertSame('missing', $inventory['data_status']);
        self::assertNull($inventory['utilization_percent']);
        $result = (new RevenuePricingRecommendationService())->recommendFromSignals(['base_price' => 200], [
            'demand_forecast' => $forecast, 'inventory' => $inventory, 'data_gaps' => $forecast['data_gaps'],
        ]);
        self::assertNotContains('demand_forecast:occupancy<=45', $result['factor_notes']);
        self::assertFalse($result['should_create']);
    }

    public function testMissingDemandCanDeriveKnownZeroOccupancyWhileUnknownOccupancyStaysMissing(): void
    {
        $zero = $this->call('inventorySignal', ['room_count' => 10], ['predicted_demand' => null, 'predicted_occupancy' => 0]);
        self::assertSame('ok', $zero['data_status']);
        self::assertSame(0.0, $zero['predicted_demand']);
        foreach ([null, -1, 101, INF] as $occupancy) {
            $missing = $this->call('inventorySignal', ['room_count' => 10], ['predicted_demand' => null, 'predicted_occupancy' => $occupancy]);
            self::assertSame('missing', $missing['data_status']);
            self::assertNull($missing['predicted_demand']);
        }
    }

    public function testZeroForecastConfidenceLowersRecommendationConfidence(): void
    {
        $signals = array_fill_keys(['demand_forecast', 'pickup', 'elasticity', 'competitor', 'holiday', 'inventory'], ['data_status' => 'ok']);
        $signals['demand_forecast']['confidence_score'] = 0;
        self::assertSame(0.44, $this->call('confidenceScore', $signals));
        $signals['demand_forecast']['confidence_score'] = 0.8;
        self::assertSame(0.84, $this->call('confidenceScore', $signals));
    }

    public function testStoredZeroAndLegacyNullRemainDistinctAfterDatabaseAndFactorReadback(): void
    {
        $connection = $this->database->connect();
        $connection->execute('CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, room_type_id INTEGER, forecast_date TEXT, predicted_occupancy REAL, predicted_demand INTEGER, confidence_score REAL, historical_data TEXT)');
        $identity = ['tenant_id' => 3, 'hotel_id' => 7, 'room_type_id' => 501, 'forecast_date' => '2026-09-27'];
        $connection->name('demand_forecasts')->insert(array_merge($identity, [
            'id' => 81, 'predicted_occupancy' => 0, 'predicted_demand' => 0, 'confidence_score' => 0,
            'historical_data' => json_encode(['input_type' => 'manual_demand_forecast', 'source_scope' => 'ctrip_ota_channel'], JSON_THROW_ON_ERROR),
        ]));
        $connection->name('demand_forecasts')->insert(array_merge($identity, [
            'id' => 82, 'predicted_occupancy' => null, 'predicted_demand' => null, 'confidence_score' => null,
        ]));
        $zero = DemandForecast::runInTenantScope(3, fn() => DemandForecast::where('id', 81)->find());
        $missing = DemandForecast::runInTenantScope(3, fn() => DemandForecast::where('id', 82)->find());
        self::assertSame(7, (int)$zero->hotel_id);
        $zeroSignal = $this->call('forecastSignalFromModel', $zero);
        $missingSignal = $this->call('forecastSignalFromModel', $missing);
        self::assertSame(0.0, $zeroSignal['predicted_demand']);
        self::assertSame(0.0, $zeroSignal['confidence_score']);
        self::assertNull($missingSignal['predicted_demand']);
        self::assertNull($missingSignal['confidence_score']);
        self::assertSame('missing', $missingSignal['data_status']);

        $room = ['base_price' => 200, 'min_price' => 160, 'room_count' => 10];
        $recommendation = (new RevenuePricingRecommendationService())->recommendFromSignals($room, [
            'demand_forecast' => $zeroSignal, 'inventory' => $this->call('inventorySignal', $room, $zeroSignal),
            'data_gaps' => [],
        ]);
        $connection->execute('CREATE TABLE suggestion_fixture (id INTEGER PRIMARY KEY, factors TEXT)');
        $connection->name('suggestion_fixture')->insert(['id' => 1, 'factors' => json_encode($recommendation['factors'], JSON_THROW_ON_ERROR)]);
        $factors = json_decode($connection->name('suggestion_fixture')->where('id', 1)->value('factors'), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals($recommendation['factors'], $factors);
        self::assertSame(0, $factors['signals']['demand_forecast']['predicted_occupancy']);
        self::assertSame(0, $factors['signals']['inventory']['predicted_demand']);
        self::assertSame('2026-09-27', $factors['signals']['demand_forecast']['forecast_date']);
        self::assertSame(501, $factors['signals']['demand_forecast']['room_type_id']);
        self::assertSame('ctrip_ota_channel', $factors['signals']['demand_forecast']['source_metadata']['source_scope']);
    }
}
