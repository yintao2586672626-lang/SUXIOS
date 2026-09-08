<?php
declare(strict_types=1);
namespace Tests\Support;

use think\facade\Config;
use think\facade\Db;

final class PromotionExperimentFixture
{
    public static function scope(): array
    {
        return ['tenant_id' => 700, 'system_hotel_id' => 701, 'platform' => 'ctrip', 'platform_store_id' => 'SYNTHETIC-701', 'period_start' => '2026-08-10', 'period_end' => '2026-08-11'];
    }
    public static function row(array $extra = []): array
    {
        return array_replace(self::scope(), ['campaign_id' => 'SYNTHETIC-AD', 'snapshot_kind' => 'cumulative_snapshot', 'currency' => 'CNY', 'amount_unit' => 'yuan', 'attribution_model' => 'single_touch',
            'collected_at' => '2026-08-18T10:00:00+08:00', 'attribution_window_days' => 7, 'source_ref' => 'synthetic-fixture-report', 'source_method' => 'test_fixture', 'source_quality' => 'verified', 'cost_source_ref' => 'synthetic-fixture-cost',
            'spend' => 100, 'attributed_revenue' => 1000, 'refunds' => 100, 'commission' => 90, 'fulfillment_cost' => 200, 'other_cost' => 10, 'attributed_orders' => 10, 'refunded_orders' => 1], $extra);
    }
    public static function input(): array
    {
        return ['scope' => self::scope(), 'as_of' => '2026-08-20', 'records' => [self::row()],
            'plan' => ['name' => 'SYNTHETIC 同期投放实验', 'hypothesis' => 'SYNTHETIC 单位可售间夜增长', 'primary_metric' => 'room_nights_per_available_room_night', 'treatment' => 'SYNTHETIC 随机处理组', 'control' => 'SYNTHETIC 随机留出组', 'stopping_rule' => 'SYNTHETIC 达到预定终点', 'design_quality' => 'randomized', 'before_start' => '2026-08-08', 'before_end' => '2026-08-09', 'attribution_window_days' => 7],
            'observation' => ['treated_before' => 100, 'treated_after' => 140, 'control_before' => 80, 'control_after' => 90, 'treated_before_exposure' => 200, 'treated_after_exposure' => 200, 'control_before_exposure' => 200, 'control_after_exposure' => 200, 'sample_size' => 80, 'discount_cost' => 400, 'contribution_per_incremental_room_night' => 50, 'pretrend_status' => 'passed', 'source_quality' => 'verified', 'source_method' => 'test_fixture', 'source_ref' => 'synthetic-fixture-observation', 'collected_at' => '2026-08-18T10:00:00+08:00'],
            'concurrent_changes' => array_fill_keys(['holiday', 'price', 'inventory', 'channel_mix'], ['status' => 'unchanged', 'note' => 'SYNTHETIC paired check'])];
    }
    public static function database(string $database = ':memory:'): array
    {
        $old = Config::get('database', []);
        if (!Config::get('cache.default')) Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        if (!Config::get('log.default')) Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => getenv('SUXIOS_CACHE_PATH') . '/logs', 'realtime_write' => false]]], 'log');
        $name = 'promotion_synthetic_' . bin2hex(random_bytes(6));
        Config::set(['default' => $name, 'connections' => [$name => ['type' => 'sqlite', 'database' => $database, 'prefix' => '', 'fields_strict' => false]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE IF NOT EXISTS promotion_experiment_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, platform_store_id TEXT NOT NULL, period_start TEXT NOT NULL, period_end TEXT NOT NULL, experiment_key TEXT NOT NULL, version_no INTEGER NOT NULL, idempotency_key TEXT NOT NULL, request_digest TEXT NOT NULL, payload_digest TEXT NOT NULL, payload_json TEXT NOT NULL, actor_id INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE(tenant_id, system_hotel_id, experiment_key, version_no), UNIQUE(tenant_id, system_hotel_id, idempotency_key))');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER)');
        if (!Db::name('hotels')->where('id', 701)->find()) Db::name('hotels')->insert(['id' => 701, 'tenant_id' => 700, 'status' => 1]);
        return $old;
    }
    public static function restoreDatabase(array $old): void { Db::connect()->close(); Config::set($old, 'database'); }
}
