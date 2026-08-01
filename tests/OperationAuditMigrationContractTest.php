<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OperationAuditMigrationContractTest extends TestCase
{
    public function testAuditMigrationIsAdditiveAndRepairsOnlyEvidenceBackedScope(): void
    {
        $root = dirname(__DIR__);
        $migrationName = '20260719_harden_operation_audit_records.sql';
        $migration = (string)file_get_contents($root . '/database/migrations/' . $migrationName);
        $initializer = (string)file_get_contents($root . '/database/init_full.sql');

        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `tenant_id`', $migration);
        self::assertStringContainsString('idx_operation_logs_hotel_time', $migration);
        self::assertStringContainsString('idx_operation_logs_user_time', $migration);
        self::assertStringContainsString('INNER JOIN `hotels` hotel', $migration);
        self::assertStringContainsString("JSON_VALID(audit_row.`extra_data`) = 1", $migration);
        self::assertStringContainsString("JSON_EXTRACT(audit_row.`extra_data`, '$.store_id')", $migration);
        self::assertStringNotContainsString('DELETE FROM `OPERATION_LOGS`', strtoupper($migration));
        self::assertStringContainsString(
            'SOURCE ./database/migrations/' . $migrationName . ';',
            $initializer
        );
    }
}
