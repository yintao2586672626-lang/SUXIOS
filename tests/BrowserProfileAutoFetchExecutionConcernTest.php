<?php
declare(strict_types=1);

namespace Tests;

use app\service\BrowserCaptureProcessRunner;
use app\service\BrowserProfileCaptureRequestService;
use PHPUnit\Framework\TestCase;

final class BrowserProfileAutoFetchExecutionConcernTest extends TestCase
{
    public function testAutoFetchUsesSharedProfileLockAndUnconfirmedTreeBlocksConcurrentRun(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'auto_profile_lock_' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $artifact = BrowserProfileCaptureRequestService::createEphemeralCaptureFile('auto-lock-fixture', 'json');
        $harness = $this->harness([
            'success' => false,
            'status_code' => 'process_tree_exit_unconfirmed',
            'message' => 'unconfirmed fixture',
            'process_started' => true,
            'process_pid' => 4242,
            'process_tree_exit_confirmed' => false,
            'process_tree' => [
                'supported' => false,
                'platform' => 'Windows',
                'strategy' => 'windows_descendant_identity_tracking',
                'root_pid' => 4242,
                'root_identity' => '',
                'tracked_members' => [],
                'survivors' => [],
            ],
            'termination' => [
                'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
                'platform' => 'Windows',
                'reason' => 'timeout',
                'confirmed_exited' => false,
                'confirmation_source' => 'unconfirmed',
            ],
        ]);
        try {
            $first = $harness->run('ctrip', 'profile-a', ['node', '--profile-id=profile-a'], $root, [$artifact]);
            self::assertTrue($first['lock_acquired']);
            self::assertFalse($first['lock_released']);
            $second = $harness->run('ctrip', 'profile-a', ['node', '--profile-id=profile-a'], $root, []);
            self::assertFalse($second['lock_acquired']);
            self::assertSame('resource_busy_login', $second['run_result']['status_code']);
            self::assertFileExists($artifact);

            $markerPath = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks'
                . DIRECTORY_SEPARATOR . 'profile_capture_ctrip_profile-a.lock';
            $marker = json_decode((string)file_get_contents($markerPath), true);
            self::assertSame('termination_unconfirmed', $marker['state']);
            self::assertSame([$artifact], $marker['spool_artifacts']);
        } finally {
            if (is_file($artifact)) {
                unlink($artifact);
            }
            $this->removeDirectory($root);
        }
    }

    public function testConfirmedAutoFetchReleasesSharedLockAndRunTokensNeverCollide(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'auto_profile_confirmed_' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $harness = $this->harness([
            'success' => true,
            'status_code' => 'ok',
            'exit_code' => 0,
            'process_started' => true,
            'process_tree_exit_confirmed' => true,
            'termination' => ['confirmed_exited' => true],
        ]);
        try {
            self::assertTrue($harness->run('meituan', 'store-a', ['node', '--store-id=store-a'], $root, [])['lock_released']);
            self::assertTrue($harness->run('meituan', 'store-a', ['node', '--store-id=store-a'], $root, [])['lock_acquired']);
            $tokens = [];
            for ($index = 0; $index < 1000; $index++) {
                $token = BrowserProfileCaptureRequestService::uniqueCaptureRunToken();
                self::assertMatchesRegularExpression('/^\d{14}_\d{6}_[a-f0-9]{16}$/D', $token);
                $tokens[$token] = true;
            }
            self::assertCount(1000, $tokens);
            $ctrip = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/CtripAutoFetchExecutionConcern.php');
            $meituan = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/MeituanAutoFetchExecutionConcern.php');
            self::assertStringContainsString("runLockedBrowserProfileAutoFetch('ctrip', \$profileId", $ctrip);
            self::assertStringContainsString("runLockedBrowserProfileAutoFetch('meituan', \$storeId", $meituan);
            self::assertContains('--profile-id=profile-a', BrowserProfileCaptureRequestService::buildCtripAutoArgs(
                'node', 'capture.mjs', 'profile-a', 7, '2026-08-27', 'output.json', ['business_overview'], 1, false
            ));
            self::assertContains('--store-id=store-a', BrowserProfileCaptureRequestService::buildMeituanAutoArgs(
                [], 'node', 'capture.mjs', 7, 'store-a', 'output.json', false
            ));
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function harness(array $result): object
    {
        return new class($result) {
            use \app\controller\concern\BrowserProfileAutoFetchExecutionConcern;

            public function __construct(private array $result)
            {
            }

            public function run(string $platform, string $key, array $args, string $root, array $artifacts): array
            {
                return $this->runLockedBrowserProfileAutoFetch($platform, $key, $args, $root, 5, $artifacts);
            }

            private function runMeituanCaptureProcess(array $args, string $root, int $timeout): array
            {
                return $this->result;
            }
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
