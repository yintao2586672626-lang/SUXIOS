<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class TenantFoundationMigrationTest extends TestCase
{
    public function testMigrationCreatesTenantFoundationAndBackfillsThroughHotelBinding(): void
    {
        $path = __DIR__ . '/../database/migrations/20260722_create_tenants_and_decouple_hotel_scope.sql';
        self::assertFileExists($path);

        $migration = file_get_contents($path);
        self::assertIsString($migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `tenants`', $migration);
        foreach (['`id`', '`name`', '`status`', '`plan_id`', '`created_at`', '`updated_at`'] as $column) {
            self::assertStringContainsString($column, $migration);
        }

        self::assertStringContainsString('legacy tenant keys are preserved', strtolower($migration));
        self::assertStringContainsString('UPDATE `hotels` hotel', $migration);
        self::assertStringContainsString('UPDATE `users` user_row', $migration);
        self::assertStringContainsString('UPDATE `user_hotel_permissions` permission_row', $migration);
        self::assertStringContainsString('MODIFY COLUMN `tenant_id` int unsigned NOT NULL', $migration);
        self::assertStringContainsString('FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)', $migration);

        self::assertStringNotContainsString('SET `tenant_id` = `id`', $migration);
        self::assertStringNotContainsString('SET `tenant_id` = `hotel_id`', $migration);
    }

    public function testMigrationRemapsCoreHistoricalRowsOnlyThroughExplicitHotelTenantBinding(): void
    {
        $migration = (string)file_get_contents(
            __DIR__ . '/../database/migrations/20260722_create_tenants_and_decouple_hotel_scope.sql'
        );
        $scopeColumns = [
            'daily_reports' => 'hotel_id',
            'monthly_tasks' => 'hotel_id',
            'online_daily_data' => 'system_hotel_id',
            'operation_logs' => 'hotel_id',
            'platform_data_sources' => 'system_hotel_id',
            'platform_data_sync_tasks' => 'system_hotel_id',
            'platform_data_raw_records' => 'system_hotel_id',
            'platform_data_sync_logs' => 'system_hotel_id',
            'agent_configs' => 'hotel_id',
            'agent_logs' => 'hotel_id',
            'demand_forecasts' => 'hotel_id',
            'price_suggestions' => 'hotel_id',
        ];

        foreach ($scopeColumns as $table => $scopeColumn) {
            self::assertStringContainsString(
                "UPDATE `{$table}` business_row\n"
                . "INNER JOIN `hotels` hotel ON hotel.`id` = business_row.`{$scopeColumn}`\n"
                . 'SET business_row.`tenant_id` = hotel.`tenant_id`',
                $migration,
                "{$table} must be remapped through hotels.tenant_id"
            );
            self::assertStringContainsString(
                "WHERE business_row.`{$scopeColumn}` > 0",
                $migration
            );
            self::assertStringNotContainsString("DELETE FROM `{$table}`", $migration);
        }

        self::assertStringContainsString('INNER JOIN deliberately preserves', $migration);
        self::assertStringNotContainsString('SET business_row.`tenant_id` = business_row.`hotel_id`', $migration);
        self::assertStringNotContainsString('SET business_row.`tenant_id` = business_row.`system_hotel_id`', $migration);
    }

    public function testMariaDb1011HistoricalUpgradeVerifierIsWiredToCi(): void
    {
        $root = dirname(__DIR__);
        $verifier = (string)file_get_contents(
            $root . '/scripts/verify_tenant_history_migration_mariadb.mjs'
        );
        $workflow = (string)file_get_contents($root . '/.github/workflows/php.yml');

        self::assertStringContainsString('requires MariaDB 10.11', $verifier);
        self::assertStringContainsString('suxios_tenant_upgrade_test_', $verifier);
        self::assertStringContainsString('Tenant A hotel 1', $verifier);
        self::assertStringContainsString('Tenant A hotel 2', $verifier);
        self::assertStringContainsString('unmatched_rows_preserved: true', $verifier);
        self::assertStringContainsString('primary_keys_preserved: true', $verifier);
        self::assertStringContainsString('migration_runs: 2', $verifier);
        self::assertStringContainsString("compatibilitySmokeVersion === '10.4'", $verifier);
        self::assertStringContainsString("'mariadb-10.4-compatibility-smoke'", $verifier);
        self::assertStringContainsString('mariadb_10_11_strict: isMariaDb1011', $verifier);
        foreach ([
            'daily_reports',
            'monthly_tasks',
            'online_daily_data',
            'operation_logs',
            'platform_data_sources',
            'platform_data_sync_tasks',
            'platform_data_raw_records',
            'platform_data_sync_logs',
            'agent_configs',
            'agent_logs',
            'demand_forecasts',
            'price_suggestions',
        ] as $table) {
            self::assertStringContainsString("{$table}:", $verifier);
        }

        self::assertStringContainsString('image: mariadb:10.11', $workflow);
        self::assertStringContainsString(
            'node scripts/verify_tenant_history_migration_mariadb.mjs',
            $workflow
        );
    }
}
