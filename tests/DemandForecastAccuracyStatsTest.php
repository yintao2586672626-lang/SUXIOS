<?php
declare(strict_types=1);

namespace Tests;

use app\model\DemandForecast;
use app\service\RevenueForecastReadinessService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\DbManager;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DemandForecastAccuracyStatsTest extends TestCase
{
    private DbManager $database;

    protected function setUp(): void
    {
        parent::setUp();
        // Use the real ThinkORM query/compiler with a dedicated in-memory
        // connection. Never initialize App/Env or read a configured database.
        $this->database = new DbManager();
        $this->database->setConfig(['default' => 'accuracy_fixture', 'connections' => [
            'accuracy_fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false],
        ]]);
        $this->database->connect()->execute('CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, forecast_date TEXT NOT NULL, predicted_occupancy REAL NOT NULL DEFAULT 0, actual_occupancy REAL DEFAULT NULL, confidence_score REAL DEFAULT 0.8)');
    }

    protected function tearDown(): void
    {
        $this->database->connect()->close();
        parent::tearDown();
    }

    public function testKnownZeroAndUpperBoundCountWhileUnknownAndInvalidResultsDoNot(): void
    {
        $this->insert(0, 0);
        $this->insert(100, 100);
        $this->insert(50, null);
        $this->insert(50, -0.01);
        $this->insert(50, 100.01);
        $stats = $this->stats();
        self::assertSame(2, (int)$stats['total_forecasts']);
        self::assertSame(0.0, (float)$stats['avg_error']);
        self::assertSame(100.0, (float)$stats['accuracy_rate']);
    }

    public function testZeroResultMissChangesAccuracyAndPreservesHotelTenantAndInclusiveDates(): void
    {
        $this->insert(65, 65, ['forecast_date' => date('Y-m-d')]);
        $this->insert(80, 0, ['forecast_date' => date('Y-m-d', strtotime('-30 days'))]);
        $this->insert(100, 100, ['hotel_id' => 81]);
        $this->insert(100, 100, ['tenant_id' => 8]);
        $this->insert(100, 100, ['forecast_date' => date('Y-m-d', strtotime('-31 days'))]);
        $this->insert(100, 100, ['forecast_date' => date('Y-m-d', strtotime('+1 day'))]);
        $stats = $this->stats();
        self::assertSame(2, (int)$stats['total_forecasts']);
        self::assertSame(40.0, (float)$stats['avg_error']);
        self::assertSame(50.0, (float)$stats['accuracy_rate']);

        $readiness = (new RevenueForecastReadinessService())->buildForecastReadiness([
            'forecast_date' => date('Y-m-d', strtotime('-30 days')), 'predicted_occupancy' => 80,
            'actual_occupancy' => 0, 'confidence_score' => 0.8,
        ], ['suggestion_count' => 1, 'approved_count' => 1, 'applied_count' => 1]);
        self::assertSame('forecast_pricing_closed', $readiness['stage']);
        self::assertTrue($readiness['component_closed_loop']);
        self::assertSame('diagnostic_only', $readiness['authority_status']);
        self::assertFalse($readiness['closed_loop']);
    }

    public function testNoObservedResultsAndRealZeroAccuracyRemainDistinguishable(): void
    {
        $this->database->connect()->name('demand_forecasts')->insert([
            'tenant_id' => 7, 'hotel_id' => 80, 'forecast_date' => date('Y-m-d'),
            'predicted_occupancy' => 80, 'confidence_score' => 0.99,
        ]);
        self::assertNull($this->database->connect()->name('demand_forecasts')->value('actual_occupancy'));
        $empty = $this->stats();
        self::assertSame(0, (int)$empty['total_forecasts']);
        self::assertSame(0.0, (float)$empty['accuracy_rate']);
        $this->insert(80, 50, ['confidence_score' => 0.01]);
        $actualMiss = $this->stats();
        self::assertSame(1, (int)$actualMiss['total_forecasts']);
        self::assertSame(30.0, (float)$actualMiss['avg_error']);
        self::assertSame(0.0, (float)$actualMiss['accuracy_rate']);
    }

    public function testOutOfRangePredictionsDoNotPolluteAccuracyStats(): void
    {
        $this->insert(80, 78);
        $this->insert(150, 50);
        $this->insert(-10, 50);

        $stats = $this->stats();

        self::assertSame(1, (int)$stats['total_forecasts']);
        self::assertSame(2.0, (float)$stats['avg_error']);
        self::assertSame(100.0, (float)$stats['accuracy_rate']);
    }

    private function insert(float $predicted, ?float $actual, array $overrides = []): void
    {
        $this->database->connect()->name('demand_forecasts')->insert(array_merge([
            'tenant_id' => 7, 'hotel_id' => 80, 'forecast_date' => date('Y-m-d', strtotime('-1 day')),
            'predicted_occupancy' => $predicted, 'actual_occupancy' => $actual, 'confidence_score' => 0.8,
        ], $overrides));
    }

    private function stats(): array
    {
        return DemandForecast::runInTenantScope(7, static fn(): array => DemandForecast::getAccuracyStats(80, 30));
    }
}
