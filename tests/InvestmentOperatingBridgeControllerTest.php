<?php
declare(strict_types=1);

use app\controller\OperatingFinance;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class InvestmentOperatingBridgeControllerTest extends TestCase
{
    public function testOperatingViewDoesNotGrantInvestmentCashAccess(): void
    {
        (new App())->initialize();
        restore_error_handler();
        restore_exception_handler();
        $original = Config::get('database');
        $path = sys_get_temp_dir() . '/investment-bridge-controller-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            Config::set(['default' => 'bridge_controller_test', 'connections' => ['bridge_controller_test' => ['type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => true]]], 'database');
            Db::connect(null, true);
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER)');
            Db::execute("INSERT INTO hotels VALUES (80,10,'synthetic-scope-only',1)");
            $controller = (new ReflectionClass(OperatingFinance::class))->newInstanceWithoutConstructor();
            $request = new class {
                public function param($key = null, $default = null): mixed { $values = ['hotel_id' => 80, 'platform' => 'manual_all_channels', 'business_date' => '2026-09-30', 'period_month' => '2026-09', 'stay_date' => '2026-10-01']; return $values[$key] ?? $default; }
            };
            $user = new class {
                public int $id = 7;
                public function getPermittedHotelIds(): array { return [80]; }
                public function hasHotelPermission(int $id, string $capability): bool { return $id === 80 && $capability === 'operation.view'; }
            };
            (new ReflectionProperty(OperatingFinance::class, 'request'))->setValue($controller, $request);
            (new ReflectionProperty(OperatingFinance::class, 'currentUser'))->setValue($controller, $user);
            $response = $controller->overview()->getData();
            self::assertSame(200, $response['code']);
            self::assertSame(80, $response['data']['hotel_id']);
            self::assertSame('blocked', $response['data']['investment_bridge']['status']);
            self::assertSame('investment_view_permission_required', $response['data']['investment_bridge']['reason_code']);
            self::assertNull($response['data']['investment_bridge']['projects']);
            self::assertNull($response['data']['investment_bridge']['totals']);
            self::assertSame(0, $response['data']['boundaries']['external_write_count']);
        } finally {
            Db::connect()->close();
            Config::set($original, 'database');
            Db::connect(null, true);
            if (is_file($path)) unlink($path);
        }
    }
}
