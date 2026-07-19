<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CompetitorRateScopeContractTest extends TestCase
{
    public function testSystemHotelScopeUsesStoreIdAcrossCriticalReadAndDeletePaths(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $agent = (string)file_get_contents($root . '/app/controller/Agent.php');
        $investment = (string)file_get_contents($root . '/app/service/InvestmentDecisionSupportService.php');
        $lifecycle = (string)file_get_contents($root . '/app/controller/Lifecycle.php');
        $deletion = (string)file_get_contents($root . '/app/service/HotelCascadeDeletionService.php');

        self::assertMatchesRegularExpression("/'competitor_price_log'[\\s\\S]{0,1800}'store_id'/", $agent);
        self::assertStringContainsString("['table' => 'competitor_price_log', 'field' => 'store_id'", $investment);
        self::assertStringContainsString("Db::name('competitor_price_log'), \$hotelIds, 'store_id'", $lifecycle);
        self::assertStringContainsString("['competitor_price_log', 'store_id']", $deletion);
        self::assertStringNotContainsString("['competitor_price_log', 'hotel_id']", $deletion);
    }

    public function testComparabilityMigrationKeepsLegacyRowsUnverifiedAndAddsRequiredDimensions(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $path = $root . '/database/migrations/20260717_add_competitor_rate_comparability.sql';
        self::assertFileExists($path);
        $sql = (string)file_get_contents($path);

        foreach ([
            'check_in_date', 'check_out_date', 'adults', 'children', 'room_type_key',
            'rate_plan_key', 'breakfast', 'cancellation_policy', 'payment_mode',
            'tax_fee_included', 'price_basis', 'currency', 'availability',
            'source_method', 'source_ref', 'validation_status', 'readback_verified',
            'comparison_key', 'content_hash',
        ] as $field) {
            self::assertStringContainsString('`' . $field . '`', $sql, $field);
        }
        self::assertStringContainsString("DEFAULT 'unverified'", $sql);
        self::assertStringContainsString('DEFAULT 0 COMMENT \'保存后回读校验\'', $sql);

        $init = (string)file_get_contents($root . '/database/init_full.sql');
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260717_add_competitor_rate_comparability.sql;',
            $init
        );
        $availabilityMigration = (string)file_get_contents(
            $root . '/database/migrations/20260719_allow_competitor_availability_events.sql'
        );
        self::assertStringContainsString('MODIFY COLUMN `price` DECIMAL(10,2) NULL DEFAULT NULL', $availabilityMigration);
        self::assertStringContainsString('`availability_scope_key`', $availabilityMigration);
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260719_allow_competitor_availability_events.sql;',
            $init
        );
    }
}
