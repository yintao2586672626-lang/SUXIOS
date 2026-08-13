<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PhpunitBootstrapIsolationTest extends TestCase
{
    public function testDefaultRuntimeStateIsScopedToWorktreeAndTestRun(): void
    {
        $cachePath = trim((string)getenv('SUXIOS_CACHE_PATH'));
        $lockPath = trim((string)getenv('SUXIOS_LOCAL_LOCK_PATH'));
        $runId = trim((string)getenv('SUXIOS_PHPUNIT_RUN_ID'));
        $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
        $worktreeHash = substr(hash(
            'sha256',
            strtolower(str_replace('\\', '/', $projectRoot))
        ), 0, 12);

        self::assertNotSame('', $runId);
        self::assertStringContainsString($worktreeHash, str_replace('\\', '/', $cachePath));
        self::assertStringContainsString($runId, str_replace('\\', '/', $cachePath));
        self::assertSame(
            dirname(str_replace('\\', '/', $cachePath)) . '/locks',
            str_replace('\\', '/', $lockPath)
        );
    }
}
