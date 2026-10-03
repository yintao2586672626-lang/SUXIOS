<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\model\DemandForecast;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Temporary SQLite only; no live database is used. */
final class ForecastManualPersistenceRefinementTest extends TestCase
{
    public function testZeroCreateEditRetryAndHotelTenantIsolationReadBackExactly(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forecast-refine-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        touch($path);
        (new App(dirname(__DIR__)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        Config::set(['default' => 'forecast_refine_test', 'connections' => [
            'forecast_refine_test' => ['type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => true],
        ]], 'database');
        Db::connect(null, true);
        try {
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
            Db::execute('INSERT INTO hotels VALUES (9001,10,1),(9002,10,1),(9003,20,1)');
            Db::execute('CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, forecast_date TEXT NOT NULL, room_type_id INTEGER NOT NULL, forecast_method INTEGER NOT NULL, predicted_occupancy NUMERIC NOT NULL CHECK(predicted_occupancy = ROUND(predicted_occupancy,2)), actual_occupancy NUMERIC NULL, predicted_demand INTEGER NOT NULL, confidence_score NUMERIC NOT NULL, is_event_driven INTEGER NOT NULL, event_factors TEXT NOT NULL, historical_data TEXT NOT NULL, remark TEXT NOT NULL, create_time TEXT, update_time TEXT)');
            $controller = (new \ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod($controller, 'normalizeDemandForecastPayload');
            $input = ['hotel_id' => 9001, 'room_type_id' => 100, 'forecast_date' => '2026-10-03',
                'forecast_method' => 3, 'predicted_occupancy' => 0, 'predicted_demand' => 0, 'confidence_score' => .8];
            $payload = $method->invoke($controller, $input);
            $save = static fn(array $data): array => DemandForecast::runInTenantScope(10,
                static fn(): array => DemandForecast::saveManualForecast($data['hotel_id'], $data['forecast_date'], $data));
            $created = $save($payload);
            self::assertTrue($created['readback_verified']);
            self::assertSame(0.0, $created['forecast']->predicted_occupancy);
            self::assertSame(0, $created['forecast']->predicted_demand);
            $id = (int)$created['forecast']->id;
            $foreign = $method->invoke($controller, array_replace($input, ['hotel_id' => 9002, 'predicted_occupancy' => 90]));
            $save($foreign);
            $updatedPayload = $method->invoke($controller, array_replace($input, ['predicted_occupancy' => 75.5555]));
            $updated = $save($updatedPayload);
            self::assertTrue($updated['readback_verified']);
            self::assertSame($id, (int)$updated['forecast']->id);
            self::assertSame(75.56, $updated['forecast']->predicted_occupancy);
            self::assertSame('updated', $updated['write_action']);
            self::assertTrue($save($updatedPayload)['readback_verified']);
            self::assertSame(2, (int)Db::name('demand_forecasts')->count());
            self::assertEquals(90, Db::name('demand_forecasts')->where('hotel_id',9002)->value('predicted_occupancy'));
            self::assertSame(0, DemandForecast::runInTenantScope(20, static fn(): int => (int)DemandForecast::where('id',$id)->count()));
            self::assertSame('operator_provided', $updated['forecast']->historical_data['evidence_status']);
            $date = date('Y-m-d');
            Db::name('demand_forecasts')->where('id',$id)->update(['forecast_date'=>$date,'actual_occupancy'=>0]);
            $stats = DemandForecast::runInTenantScope(10, static fn(): array => DemandForecast::getAccuracyStats(9001));
            self::assertSame(1, $stats['total_forecasts']);
            self::assertSame(75.56, $stats['avg_error']);
            self::assertSame(0.0, $stats['accuracy_rate']);
            $none = DemandForecast::runInTenantScope(10, static fn(): array => DemandForecast::getAccuracyStats(9002));
            self::assertNull($none['avg_error']);
            self::assertNull($none['accuracy_rate']);
            $new = $save($method->invoke($controller, array_replace($input, ['room_type_id'=>101,'forecast_date'=>$date])));
            Db::name('demand_forecasts')->where('id',(int)$new['forecast']->id)->update(['actual_occupancy'=>0]);
            $invalid = $save($method->invoke($controller, array_replace($input, ['room_type_id'=>102,'forecast_date'=>$date])));
            Db::name('demand_forecasts')->where('id',(int)$invalid['forecast']->id)->update(['actual_occupancy'=>101]);
            $stats = DemandForecast::runInTenantScope(10, static fn(): array => DemandForecast::getAccuracyStats(9001));
            self::assertSame(2, $stats['total_forecasts']);
            self::assertSame(37.78, $stats['avg_error']);
            self::assertSame(50.0, $stats['accuracy_rate']);
        } finally {
            Db::connect()->close();
            if (is_file($path)) unlink($path);
        }
    }
}
