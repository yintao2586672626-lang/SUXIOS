<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DatabaseBuildScriptTest extends TestCase
{
    public function testFullDumpBuildScriptUsesEveryInitFullSource(): void
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
        exec($command . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode(PHP_EOL, $output));

        $dump = file_get_contents($root . '/' . $outputPath);
        self::assertIsString($dump);

        foreach ($sources as $source) {
            self::assertStringContainsString("-- SOURCE: {$source}", $dump, "Full dump is missing {$source}");
        }
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
