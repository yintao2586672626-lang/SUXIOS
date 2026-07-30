<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DatabaseBuildScriptTest extends TestCase
{
    public function testFullDumpBuildScriptUsesFrozenBaselineAndEveryMigration(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $initFull = file_get_contents($root . '/database/init_full.sql');
        self::assertIsString($initFull);
        preg_match_all('/^\s*SOURCE\s+(.+?);/mi', $initFull, $matches);

        $sources = array_map(
            static fn(string $source): string => ltrim(str_replace('\\', '/', trim($source)), './'),
            $matches[1] ?? []
        );
        self::assertNotEmpty($sources, 'database/init_full.sql should declare SOURCE entries');

        foreach ($sources as $source) {
            self::assertFileExists($root . '/' . $source, "database/init_full.sql references missing source {$source}");
        }

        $migrationPaths = glob($root . '/database/migrations/*.sql');
        self::assertIsArray($migrationPaths);
        $migrations = array_map(
            static fn(string $path): string => 'database/migrations/' . basename($path),
            $migrationPaths
        );
        sort($migrations);

        $powerShell = $this->resolvePowerShellBinary();
        if ($powerShell === null) {
            $script = file_get_contents($root . '/scripts/build_hotelx_full_dump.ps1');
            self::assertIsString($script);
            self::assertStringContainsString('database/init_full.sql', $script);
            self::assertStringContainsString('-- SOURCE: $source', $script);
            return;
        }

        $outputPath = 'output/test_hotelx_dump_full_contract.sql';
        $command = sprintf(
            '%s -NoProfile -ExecutionPolicy Bypass -File %s -OutputPath %s',
            escapeshellarg($powerShell),
            escapeshellarg($root . '/scripts/build_hotelx_full_dump.ps1'),
            escapeshellarg($outputPath)
        );
        $absoluteOutput = $root . '/' . $outputPath;
        try {
            exec($command . ' 2>&1', $output, $code);
            self::assertSame(0, $code, implode(PHP_EOL, $output));

            $dump = file_get_contents($absoluteOutput);
            self::assertIsString($dump);

            foreach (array_values(array_unique(array_merge($sources, $migrations))) as $source) {
                self::assertStringContainsString("-- SOURCE: {$source}", $dump, "Full dump is missing {$source}");
            }
            foreach ([
                'database/hotel_admin_mysql.sql',
                'database/login_logs.sql',
                'database/complaint_tables.sql',
                'database/update_system_config.sql',
            ] as $baselineSource) {
                self::assertStringContainsString(
                    "-- REGISTER BASELINE: {$baselineSource}",
                    $dump,
                    "Full dump does not checksum {$baselineSource}"
                );
            }
        } finally {
            if (is_file($absoluteOutput)) {
                unlink($absoluteOutput);
            }
        }
    }

    public function testInitFullIsFrozenAndDoesNotNeedNewMigrationEntries(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $initFull = (string)file_get_contents($root . '/database/init_full.sql');
        self::assertStringContainsString('FROZEN BASELINE', $initFull);
        self::assertStringNotContainsString('20260722_add_hotels_city.sql', $initFull);
        self::assertStringNotContainsString('20260722_create_schema_versions.sql', $initFull);
        self::assertStringNotContainsString('20260722_create_tenants_and_decouple_hotel_scope.sql', $initFull);
        self::assertStringNotContainsString('20260722_harden_schema_version_governance.sql', $initFull);
        self::assertStringNotContainsString('20260722_track_frozen_baseline_sources.sql', $initFull);

        $builder = (string)file_get_contents($root . '/scripts/build_hotelx_full_dump.ps1');
        self::assertStringContainsString('database/migrations', $builder);
        self::assertStringContainsString('Get-ChildItem', $builder);
    }

    public function testSystemConfigsValueColumnCanStoreLargeProfileFieldCatalog(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);

        $migration = file_get_contents($root . '/database/migrations/20260530_create_system_configs_table.sql');
        self::assertIsString($migration);

        self::assertMatchesRegularExpression('/`config_value`\s+LONGTEXT\b/i', $migration);
        self::assertMatchesRegularExpression('/MODIFY\s+COLUMN\s+`config_value`\s+LONGTEXT\b/i', $migration);
    }

    private function resolvePowerShellBinary(): ?string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['pwsh.exe', 'powershell.exe', 'pwsh', 'powershell']
            : ['pwsh', 'powershell'];

        foreach ($candidates as $candidate) {
            $command = PHP_OS_FAMILY === 'Windows'
                ? 'where ' . escapeshellarg($candidate)
                : 'command -v ' . escapeshellarg($candidate);
            $redirect = PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null';
            $output = [];
            exec($command . $redirect, $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }
}
