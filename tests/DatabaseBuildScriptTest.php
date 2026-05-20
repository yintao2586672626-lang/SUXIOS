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

        $outputPath = 'output/test_hotelx_dump_full_contract.sql';
        $command = sprintf(
            'powershell -NoProfile -ExecutionPolicy Bypass -File %s -OutputPath %s',
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
}
