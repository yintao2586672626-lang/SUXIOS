<?php
declare(strict_types=1);

namespace Tests\Support;

use app\model\User;
use app\service\HotelScopeService;
use app\service\InvestmentPaybackService;
use app\service\InvestmentScenarioService;
use app\service\PermissionService;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Explicit synthetic identities and an isolated SQLite file, with no business database or login state. */
final class InvestmentScenarioFixture
{
    /** Only call in a fresh isolated test process to exercise missing optional modules. */
    public static function withoutOptionalModules(array $unavailable = [\app\service\ConsumablesProcurementReferenceService::class,
        \app\service\ActualConsumablesScenarioReferenceService::class]): void
    {
        foreach ($unavailable as $class) {
            if (class_exists($class, false)) {
                throw new RuntimeException('Missing-module fixture requires a fresh isolated process');
            }
        }
        $loaders = spl_autoload_functions() ?: [];
        foreach ($loaders as $loader) spl_autoload_unregister($loader);
        foreach ($loaders as $loader) {
            spl_autoload_register(static function (string $class) use ($loader, $unavailable): void {
                $identity = strtolower(ltrim($class, '\\'));
                foreach ($unavailable as $blocked) if ($identity === strtolower($blocked)) return;
                $loader($class);
            });
        }
    }

    public static function connect(string $path): void
    {
        if (!str_starts_with(basename($path), 'investment-scenario-test-') || pathinfo($path, PATHINFO_EXTENSION) !== 'sqlite') {
            throw new RuntimeException('Synthetic fixture path required');
        }
        (new App(dirname(__DIR__, 2)))->initialize();
        // ThinkPHP registers process error hooks; fixtures must not replace PHPUnit's handlers.
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            restore_error_handler();
            restore_exception_handler();
        }
        Config::set(['default' => 'investment_scenario_test', 'connections' => ['investment_scenario_test' => [
            'type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        Db::connect(null, true);
    }

    public static function schema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80,10,1),(81,10,1),(90,20,1)');
        Db::execute('CREATE TABLE investment_payback_projects ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NULL, '
            . 'project_name TEXT NOT NULL, investor_name TEXT NOT NULL, basis TEXT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, '
            . 'first_invested_on TEXT NULL, expected_monthly_amount TEXT NULL, expected_source TEXT NOT NULL, forecast_as_of TEXT NOT NULL, '
            . 'history_complete_through TEXT NULL, opening_as_of TEXT NULL, opening_invested TEXT NULL, opening_recovered TEXT NULL, opening_source TEXT NOT NULL, '
            . 'notes TEXT NOT NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, '
            . 'created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, archived_at TEXT NULL, archive_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_entries ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, kind TEXT NOT NULL, amount TEXT NOT NULL, '
            . 'business_date TEXT NOT NULL, precision TEXT NOT NULL, is_planned INTEGER NOT NULL, confirmed_zero INTEGER NOT NULL, category TEXT NOT NULL, source TEXT NOT NULL, notes TEXT NOT NULL, '
            . 'original_entry_id INTEGER NULL, client_request_id TEXT NOT NULL, input_digest TEXT NOT NULL, version INTEGER NOT NULL, created_by INTEGER NOT NULL, updated_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, voided_at TEXT NULL, voided_by INTEGER NULL, void_reason TEXT NOT NULL DEFAULT "", '
            . 'UNIQUE (tenant_id,project_id,created_by,client_request_id))');
        Db::execute('CREATE TABLE investment_payback_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, project_id INTEGER NOT NULL, entry_id INTEGER NULL, actor_id INTEGER NOT NULL, '
            . 'event_type TEXT NOT NULL, project_version INTEGER NOT NULL, payload_json TEXT NOT NULL, created_at TEXT NOT NULL)');
    }

    public static function user(int $tenantId = 10, int $actorId = 7): User
    {
        return new User(['id' => $actorId, 'tenant_id' => $tenantId, 'role_id' => 1]);
    }

    public static function ledger(int $tenantId = 10, int $actorId = 7, array $allowedHotels = [80]): InvestmentPaybackService
    {
        $permissions = new class($allowedHotels) extends PermissionService {
            public function __construct(private array $allowedHotels) {}
            public function authorize(User $user, string $capability, ?int $hotelId = null): array
            {
                return ['allowed' => $capability === 'investment.simulate' && ($hotelId === null || in_array($hotelId, $this->allowedHotels, true))];
            }
        };
        $scope = new class($allowedHotels) extends HotelScopeService {
            public function __construct(private array $allowedHotels) {}
            public function accessibleHotelIds(User $user, ?string $capability = null): array { return $this->allowedHotels; }
        };
        return new InvestmentPaybackService(self::user($tenantId, $actorId), $permissions, null, $scope);
    }

    public static function scenarios(int $tenantId = 10, int $actorId = 7, array $allowedHotels = [80]): InvestmentScenarioService
    {
        return new InvestmentScenarioService(self::user($tenantId, $actorId), self::ledger($tenantId, $actorId, $allowedHotels));
    }

    public static function project(array $changes = []): array
    {
        return array_replace(['project_name' => '合成验收投资项目', 'investor_name' => '合成投资人', 'hotel_id' => 80,
            'client_request_id' => 'scenario-fixture-project', 'forecast_as_of' => '2026-10-01'], $changes);
    }
}
