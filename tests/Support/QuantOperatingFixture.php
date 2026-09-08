<?php
declare(strict_types=1);
namespace Tests\Support;

use app\service\QuantSimulationService;
use ReflectionMethod;
use think\facade\Config;
use think\facade\Db;

final class QuantOperatingFixture
{
    public static function input(string $type = 'existing_hotel'): array
    {
        return [
            'hotel_id' => 901, 'input_source_status' => 'synthetic',
            'roomCount' => 10, 'adr' => 100, 'occupancyRate' => 50,
            'decorationInvestment' => 30000, 'furnitureInvestment' => 0, 'openingCost' => 0, 'otherInvestment' => 0,
            'otherIncome' => 0, 'monthlyRent' => 5000, 'laborCost' => 1000, 'utilityCost' => 500,
            'consumableCost' => 0, 'maintenanceCost' => 0, 'otherFixedCost' => 0, 'otaCommissionRate' => 10,
            'operatingScenario' => [
                'case_type' => $type, 'case_name' => 'synthetic ' . $type, 'start_month' => '2024-02',
                'evidence_basis' => 'assumptions', 'source_note' => 'synthetic fixture; not a real hotel',
                'currency' => 'CNY', 'monetary_unit' => 'yuan', 'horizon_months' => 12, 'target_payback_months' => 6,
                'ramp_months' => 0, 'ramp_start_occupancy' => 0, 'loan_amount' => 0, 'annual_interest_rate' => 0,
                'loan_term_months' => 0, 'opening_cash' => 40000, 'minimum_monthly_cashflow' => 0,
            ],
        ];
    }

    public static function calculate(array $input): array
    {
        $service = new QuantSimulationService();
        $normalized = (new ReflectionMethod($service, 'normalizeInput'))->invoke($service, $input);
        $result = (new ReflectionMethod($service, 'calculateSimulation'))->invoke($service, $normalized);
        return [$normalized, $result];
    }

    public static function database(string $path): void
    {
        if (!Config::get('cache.default')) Config::set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        if (!Config::get('log.default')) Config::set(['default'=>'file','close'=>true,'channels'=>['file'=>['type'=>'File','close'=>true]]], 'log');
        Config::set(['default' => 'l09_synthetic', 'connections' => ['l09_synthetic' => [
            'type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER)');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('CREATE TABLE IF NOT EXISTS quant_simulation_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, project_name TEXT, input_json TEXT,
            result_json TEXT, scenarios_json TEXT, risk_hints_json TEXT, monthly_net_cashflow REAL,
            payback_months REAL, risk_level TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        if (Db::name('users')->count() === 0) {
            Db::name('users')->insertAll([['id' => 91, 'tenant_id' => 9, 'hotel_id' => 901], ['id' => 92, 'tenant_id' => 10, 'hotel_id' => 902], ['id' => 93, 'tenant_id' => 9, 'hotel_id' => 903]]);
            Db::name('hotels')->insertAll([['id' => 901, 'tenant_id' => 9], ['id' => 902, 'tenant_id' => 10], ['id' => 903, 'tenant_id' => 9]]);
        }
    }
}
