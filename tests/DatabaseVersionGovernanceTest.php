<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DatabaseVersionGovernanceTest extends TestCase
{
    public function testSchemaVersionsMigrationRecordsRequiredFacts(): void
    {
        $root = dirname(__DIR__);
        $sql = (string)file_get_contents(
            $root . '/database/migrations/20260722_create_schema_versions.sql'
        );

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `schema_versions`', $sql);
        self::assertMatchesRegularExpression('/`migration`\s+varchar\(191\)\s+NOT NULL/i', $sql);
        self::assertMatchesRegularExpression('/`version`\s+varchar\(191\)\s+NOT NULL/i', $sql);
        self::assertMatchesRegularExpression('/`executed_at`\s+datetime\(6\)\s+NOT NULL/i', $sql);
        self::assertStringContainsString('uk_schema_versions_migration', $sql);
        self::assertStringContainsString('uk_schema_versions_version', $sql);

        $hardening = (string)file_get_contents(
            $root . '/database/migrations/20260722_harden_schema_version_governance.sql'
        );
        self::assertStringContainsString('`checksum` CHAR(64)', $hardening);
        self::assertStringContainsString('schema_migration_failures', $hardening);

        $baselineTracking = (string)file_get_contents(
            $root . '/database/migrations/20260722_track_frozen_baseline_sources.sql'
        );
        self::assertStringContainsString('schema_baseline_sources', $baselineTracking);
        self::assertStringContainsString('execution_kind', $baselineTracking);
    }

    public function testHotelsCityIsDeliveredAsAnIndependentIdempotentMigration(): void
    {
        $root = dirname(__DIR__);
        $migrationName = '20260722_add_hotels_city.sql';
        $sql = (string)file_get_contents($root . '/database/migrations/' . $migrationName);
        $initFull = (string)file_get_contents($root . '/database/init_full.sql');
        $strategy = (string)file_get_contents($root . '/app/controller/StrategySimulation.php');

        self::assertMatchesRegularExpression(
            '/ALTER TABLE\s+`hotels`\s+ADD COLUMN IF NOT EXISTS\s+`city`\s+VARCHAR\(80\)\s+NOT NULL\s+DEFAULT\s+\'\'/i',
            $sql
        );
        self::assertStringNotContainsString($migrationName, $initFull);
        self::assertStringContainsString("->where('city', \$city)", $strategy);
        self::assertStringNotContainsString('UPDATE `hotels`', $sql);
    }

    public function testWideSqlContractRecognizesPreparedAlterEvidence(): void
    {
        $root = dirname(__DIR__);
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($root . '/scripts/verify_sql_schema_contract.php')
            . ' --json';
        $output = [];
        exec($command . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode(PHP_EOL, $output));
        $summary = json_decode(implode(PHP_EOL, $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $summary['source_missing_tables']);
        self::assertSame([], $summary['source_missing_columns']);
        self::assertContains(
            'database/migrations/20260722_add_hotels_city.sql',
            $summary['source_sql_files']
        );
    }

    public function testCommandsAndEnvironmentChecksAreWired(): void
    {
        $root = dirname(__DIR__);
        $console = (string)file_get_contents($root . '/config/console.php');
        self::assertStringContainsString("'db:init' => 'app\\command\\InitializeDatabaseSchema'", $console);
        self::assertStringContainsString("'db:check' => 'app\\command\\CheckDatabaseSchema'", $console);
        self::assertStringContainsString("'db:migrate' => 'app\\command\\MigrateDatabaseSchema'", $console);

        $middleware = (string)file_get_contents($root . '/app/middleware.php');
        self::assertStringContainsString('\\app\\middleware\\DatabaseSchemaGuard::class', $middleware);

        $powerShell = (string)file_get_contents($root . '/scripts/start_local_stack.ps1');
        self::assertStringContainsString('Assert-DatabaseVersion', $powerShell);
        self::assertStringContainsString('scripts\\check_database_version.php', $powerShell);
        self::assertMatchesRegularExpression(
            '/Assert-DatabaseReady\s*\RAssert-DatabaseVersion\s*\RInvoke-OtaRetentionMaintenance/',
            $powerShell
        );

        $batch = (string)file_get_contents($root . '/start-hotel.bat');
        self::assertStringContainsString('scripts\\check_database_version.php', $batch);
        self::assertStringContainsString('数据库版本不足', $batch);
    }

    public function testFreshInitAndUpgradeInstructionsAreExplicit(): void
    {
        $root = dirname(__DIR__);
        $readme = (string)file_get_contents($root . '/database/README_INIT.md');
        self::assertStringContainsString('php scripts/init_database.php', $readme);
        self::assertStringContainsString('php think db:migrate --baseline', $readme);
        self::assertStringContainsString('registers a migration only after all statements', $readme);

        $check = (string)file_get_contents($root . '/scripts/check_database_version.php');
        self::assertStringContainsString('[UPGRADE REQUIRED]', $check);
        self::assertStringContainsString('php think db:migrate', $check);
        self::assertStringContainsString('baseline_checksum_mismatches', $check);
        self::assertStringContainsString('missing_checksums', $check);
        self::assertStringContainsString('unresolved_failures', $check);
        self::assertStringContainsString('schema_baseline_sources', $check);
    }

    public function testRuntimeCodeCannotMutateSchemaOutsideTheGovernedRunner(): void
    {
        $root = dirname(__DIR__);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );
        $violations = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '/app/service/SchemaVersionService.php')
                || str_ends_with($path, '/app/service/SqlSchemaResourceInspector.php')
            ) {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match('/\b(?:CREATE|ALTER|DROP|RENAME)\s+TABLE\b/i', $source) === 1) {
                $violations[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
            }
        }

        self::assertSame([], $violations, 'Runtime schema DDL must be delivered by database/migrations only.');
    }

    public function testLegacyCommandAliasesDelegateToTheGovernedCatalog(): void
    {
        $root = dirname(__DIR__);
        foreach ([
            'InitDatabase.php',
            'MigrateOnlineData.php',
            'MigrateLoginLogs.php',
            'MigrateNotificationRecipients.php',
        ] as $file) {
            $source = (string)file_get_contents($root . '/app/command/' . $file);
            self::assertStringContainsString('SchemaVersionService', $source, $file);
            self::assertDoesNotMatchRegularExpression('/\b(?:CREATE|ALTER|DROP|RENAME)\s+TABLE\b/i', $source, $file);
        }
    }
}
