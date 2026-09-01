<?php
declare(strict_types=1);

namespace Tests;

use app\service\BrowserCaptureProcessRunner;
use app\service\BrowserProfileCaptureRequestService;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeBrowserCaptureProcessRuntime;

final class BrowserCaptureProcessRunnerTest extends TestCase
{
    public function testNaturalExitClosesEveryPipeAndBoundsBothOutputStreams(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 1,
            'stdout_chunks' => [str_repeat('o', 40)],
            'stderr_chunks' => [str_repeat('e', 24)],
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5,
            ['output_limit_bytes' => 16, 'spool_limit_bytes' => 64]
        );

        self::assertTrue($result['success']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertTrue($result['pipes_closed']);
        self::assertSame([0, 1, 2], $runtime->closedPipeIds);
        self::assertSame(16, strlen((string)$result['stdout']));
        self::assertSame(16, strlen((string)$result['stderr']));
        self::assertSame(40, $result['stdout_bytes']);
        self::assertSame(24, $result['stderr_bytes']);
        self::assertTrue($result['stdout_truncated']);
        self::assertTrue($result['stderr_truncated']);
        self::assertSame('normal_exit', $result['termination']['reason']);
        self::assertSame('natural_tree_exit', $result['termination']['confirmation_source']);
    }

    public function testWindowsTimeoutUsesBoundedGraceThenForcesAndConfirmsTheProcessTree(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'soft_stops' => false,
            'force_stops' => true,
            'os_family' => 'Windows',
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            1,
            [
                'poll_interval_ms' => 250,
                'grace_ms' => 500,
                'force_grace_ms' => 500,
            ]
        );

        self::assertFalse($result['success']);
        self::assertTrue($result['timed_out']);
        self::assertSame('process_timeout', $result['status_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame(
            [
                ['force' => false, 'pid' => 4242],
                ['force' => true, 'pid' => 4242],
            ],
            $runtime->terminationAttempts
        );
        self::assertSame('windows_taskkill_tracked_tree', $result['termination']['soft']['strategy']);
        self::assertSame('windows_taskkill_tracked_tree_force', $result['termination']['force']['strategy']);
        self::assertTrue($result['termination']['force']['attempted']);
        self::assertSame('post_force_tree_observation', $result['termination']['confirmation_source']);
        self::assertSame([0, 1, 2], $runtime->closedPipeIds);
    }

    public function testNaturalRootExitWithSurvivingChildIsTerminatedAndNeverReportedAsSuccess(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 1,
            'exit_code' => 0,
            'descendant_pids' => [5151],
            'soft_stops_root' => false,
            'soft_stops_descendants' => true,
            'os_family' => 'Windows',
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5
        );

        self::assertFalse($result['success']);
        self::assertSame('process_orphaned_descendants', $result['status_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame('orphaned_descendants_after_root_exit', $result['termination']['reason']);
        self::assertNotEmpty($result['termination']['tracked_descendants']);
        self::assertSame([], $result['termination']['surviving_descendants']);
        self::assertSame([['force' => false, 'pid' => 4242]], $runtime->terminationAttempts);
    }

    public function testWindowsNativeRunnerTracksAndTerminatesChildAfterNaturalRootExit(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows descendant identity tracking smoke only.');
        }
        $fixture = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR . 'browser_capture_tree_fixture.php';
        $result = (new BrowserCaptureProcessRunner())->run(
            [PHP_BINARY, $fixture, 'root-with-child'],
            dirname(__DIR__),
            20,
            [
                'poll_interval_ms' => 50,
                'tree_poll_interval_ms' => 100,
                'grace_ms' => 500,
                'force_grace_ms' => 2000,
            ]
        );

        self::assertFalse($result['success']);
        self::assertSame('process_orphaned_descendants', $result['status_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame('windows_descendant_identity_tracking', $result['process_tree']['strategy']);
        self::assertNotEmpty($result['process_tree']['tracked_members']);
        self::assertSame([], $result['process_tree']['survivors']);
        self::assertContains(
            $result['termination']['confirmation_source'],
            ['post_soft_tree_observation', 'post_force_tree_observation']
        );
    }

    public function testWindowsSpoolHardLimitTerminatesTreeAndReportsObservedDiskBytes(): void
    {
        $artifact = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxi-browser-fixture-output';
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'spool_usage_sequence' => [
                ['stdout_bytes' => 0, 'stderr_bytes' => 0],
                ['stdout_bytes' => 4097, 'stderr_bytes' => 17],
            ],
            'spool_artifacts' => [$artifact],
            'soft_stops' => true,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            30,
            [
                'output_limit_bytes' => 128,
                'spool_limit_bytes' => 4096,
                'poll_interval_ms' => 50,
            ]
        );

        self::assertFalse($result['success']);
        self::assertSame('process_output_limit_exceeded', $result['status_code']);
        self::assertSame('output_limit_exceeded', $result['termination']['reason']);
        self::assertSame(4097, $result['stdout_bytes']);
        self::assertSame(17, $result['stderr_bytes']);
        self::assertTrue($result['stdout_truncated']);
        self::assertSame([$artifact], $result['spool_artifacts']);
        self::assertTrue($result['process_tree_exit_confirmed']);
    }

    public function testWindowsNativeEightMegabyteBurstUsesBoundedPipesWithoutDiskSpool(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows bounded-output smoke only.');
        }
        $result = (new BrowserCaptureProcessRunner())->run(
            [
                PHP_BINARY,
                '-r',
                'for($i=0;$i<1024;$i++){fwrite(STDOUT,str_repeat("o",8192));fwrite(STDERR,str_repeat("e",8192));}usleep(30000000);',
            ],
            dirname(__DIR__),
            10,
            [
                'output_limit_bytes' => 512,
                'spool_limit_bytes' => 4096,
                'poll_interval_ms' => 50,
                'tree_poll_interval_ms' => 100,
            ]
        );

        self::assertFalse($result['success']);
        self::assertSame('process_output_limit_exceeded', $result['status_code']);
        self::assertGreaterThan(4096, $result['stdout_bytes'] + $result['stderr_bytes']);
        self::assertLessThan(16777216, $result['stdout_bytes'] + $result['stderr_bytes']);
        self::assertGreaterThan(0, strlen((string)$result['stdout']) + strlen((string)$result['stderr']));
        self::assertLessThanOrEqual(1024, strlen((string)$result['stdout']) + strlen((string)$result['stderr']));
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertTrue($result['writer_bounded']);
        self::assertSame('windows_bounded_relay', $result['output_transport']);
        self::assertLessThanOrEqual(4096, $result['spool_persisted_output_bytes']);
        self::assertLessThan(16384, $result['spool_metadata_bytes']);
        self::assertCount(4, $result['spool_artifacts']);
        foreach ($result['spool_artifacts'] as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
    }

    public function testCancellationUsesTheSameTerminationAndCleanupContract(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'soft_stops' => true,
            'force_stops' => true,
            'os_family' => 'Linux',
        ]);
        $polls = 0;
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            10,
            [
                'poll_interval_ms' => 50,
                'cancel_requested' => static function () use (&$polls): bool {
                    return ++$polls >= 2;
                },
            ]
        );

        self::assertFalse($result['success']);
        self::assertTrue($result['cancelled']);
        self::assertFalse($result['timed_out']);
        self::assertSame('process_cancelled', $result['status_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame('cancelled', $result['termination']['reason']);
        self::assertTrue($result['termination']['soft']['attempted']);
        self::assertFalse($result['termination']['force']['attempted']);
        self::assertSame([0, 1, 2], $runtime->closedPipeIds);
    }

    public function testLinuxProcessGroupHandshakeFailureIsBoundedAndNeverConfirmsTheTree(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'os_family' => 'Linux',
            'linux_handshake_confirmed' => false,
            'soft_stops' => true,
            'force_stops' => true,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            10,
            [
                'poll_interval_ms' => 50,
                'tree_poll_interval_ms' => 100,
                'tree_handshake_ms' => 100,
                'grace_ms' => 100,
                'force_grace_ms' => 100,
            ]
        );

        self::assertFalse($result['success']);
        self::assertSame('process_runner_failed', $result['status_code']);
        self::assertSame('process_group_handshake_failed', $result['termination']['reason']);
        self::assertFalse($result['process_tree_exit_confirmed']);
        self::assertFalse($result['process_tree']['handshake_confirmed']);
        self::assertSame('posix_identity_members_only_fail_closed', $result['termination']['soft']['strategy']);
        self::assertLessThanOrEqual(500, $runtime->nowMs);
    }

    public function testInjectedLinuxTableRequiresNewSessionAndTracksDescendantsThatEscapeTheGroup(): void
    {
        new BrowserCaptureProcessRunner();
        $runtimeClass = new \ReflectionClass('app\\service\\BrowserCaptureNativeProcessRuntime');
        $observe = $runtimeClass->getMethod('observeLinuxTree');
        $observe->setAccessible(true);
        $badRuntime = $runtimeClass->newInstanceArgs([null, static fn(): array => [
            100 => ['parent_pid' => 50, 'group_id' => 50, 'session_id' => 50, 'identity' => 'root-100'],
        ]]);
        $bad = $observe->invoke($badRuntime, 100, [], [
            'supported' => true,
            'root_identity' => '',
            'handshake_confirmed' => false,
        ]);
        self::assertFalse($bad['supported']);
        self::assertFalse($bad['handshake_confirmed']);
        self::assertSame(0, $bad['group_id']);

        $goodRuntime = $runtimeClass->newInstanceArgs([null, static fn(): array => [
            100 => ['parent_pid' => 50, 'group_id' => 100, 'session_id' => 100, 'identity' => 'root-100'],
            101 => ['parent_pid' => 100, 'group_id' => 100, 'session_id' => 100, 'identity' => 'child-101'],
            102 => ['parent_pid' => 101, 'group_id' => 102, 'session_id' => 102, 'identity' => 'escaped-102'],
        ]]);
        $good = $observe->invoke($goodRuntime, 100, [], [
            'supported' => true,
            'root_identity' => '',
            'handshake_confirmed' => false,
        ]);
        self::assertTrue($good['supported']);
        self::assertTrue($good['handshake_confirmed']);
        self::assertSame(100, $good['group_id']);
        self::assertSame([101, 102], array_column($good['members'], 'pid'));
    }

    public function testWindowsPidReuseDoesNotSeedOrTargetUnrecordedDescendants(): void
    {
        new BrowserCaptureProcessRunner();
        $runtimeClass = new \ReflectionClass('app\\service\\BrowserCaptureNativeProcessRuntime');
        $runtime = $runtimeClass->newInstanceArgs([static fn(): array => [
            4242 => ['parent_pid' => 1, 'group_id' => 0, 'session_id' => 0, 'identity' => 'reused-root'],
            5151 => ['parent_pid' => 4242, 'group_id' => 0, 'session_id' => 0, 'identity' => 'unrelated-child'],
        ], null]);
        $observe = $runtimeClass->getMethod('observeWindowsTree');
        $observe->setAccessible(true);
        $result = $observe->invoke($runtime, 4242, [], ['root_identity' => 'original-root']);

        self::assertTrue($result['supported']);
        self::assertSame([], $result['members']);
        self::assertTrue($result['exited']);
        $verified = $runtime->verifyRecordedTree([
            'supported' => true,
            'platform' => 'Windows',
            'strategy' => 'windows_descendant_identity_tracking',
            'root_pid' => 4242,
            'root_identity' => 'original-root',
            'tracked_members' => [],
        ]);
        self::assertSame('exited', $verified['status']);
        self::assertSame([], $verified['survivors']);
    }

    public function testArtifactPathComparisonIsWindowsInsensitiveAndLinuxSensitive(): void
    {
        new BrowserCaptureProcessRunner();
        $runtimeClass = new \ReflectionClass('app\\service\\BrowserCaptureNativeProcessRuntime');
        $runtime = $runtimeClass->newInstance();
        $compare = $runtimeClass->getMethod('artifactPathsEqual');
        $compare->setAccessible(true);

        self::assertTrue($compare->invoke($runtime, 'C:/Temp', 'c:/temp', 'Windows'));
        self::assertFalse($compare->invoke($runtime, '/tmp', '/TMP', 'Linux'));
        self::assertTrue($compare->invoke($runtime, '/tmp', '/tmp', 'Linux'));
    }

    public function testNonZeroExitKeepsAConfirmedNaturalExitReceipt(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 1,
            'exit_code' => 7,
            'close_exit_code' => 7,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5,
            ['label' => 'Fixture capture']
        );

        self::assertFalse($result['success']);
        self::assertSame(7, $result['exit_code']);
        self::assertSame('process_exit_nonzero', $result['status_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame('normal_exit', $result['termination']['reason']);
        self::assertSame('natural_tree_exit', $result['termination']['confirmation_source']);
        self::assertSame('Fixture capture exited with code 7.', $result['message']);
    }

    public function testUnknownMinusOneExitNeverBecomesSuccess(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 1,
            'exit_code' => -1,
            'close_exit_code' => -1,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5
        );

        self::assertFalse($result['success']);
        self::assertSame('process_exit_unknown', $result['status_code']);
        self::assertSame(-1, $result['exit_code']);
        self::assertTrue($result['process_tree_exit_confirmed']);
    }

    public function testRunnerFailureStillTerminatesAndClosesPipesInFinally(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'throw_status_at' => [1],
            'soft_stops' => true,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5
        );

        self::assertFalse($result['success']);
        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame('process_runner_failed', $result['status_code']);
        self::assertSame('runner_error', $result['termination']['reason']);
        self::assertContains('runner_error', $result['termination']['errors']);
        self::assertSame([0, 1, 2], $runtime->closedPipeIds);
    }

    public function testUnconfirmedTreeExitIsExplicitAndDefersProcessClose(): void
    {
        $runtime = new FakeBrowserCaptureProcessRuntime([
            'natural_exit_after_status_calls' => 0,
            'soft_stops' => false,
            'force_stops' => false,
            'soft_accepted' => false,
            'force_accepted' => false,
        ]);
        $result = (new BrowserCaptureProcessRunner($runtime))->run(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            1,
            [
                'poll_interval_ms' => 250,
                'grace_ms' => 250,
                'force_grace_ms' => 250,
            ]
        );

        self::assertFalse($result['success']);
        self::assertFalse($result['process_tree_exit_confirmed']);
        self::assertSame('process_timeout', $result['status_code']);
        self::assertTrue($result['termination']['close_deferred']);
        self::assertContains('process_tree_exit_unconfirmed', $result['termination']['errors']);
        self::assertSame(0, $runtime->closeProcessCalls);
        self::assertSame([0, 1, 2], $runtime->closedPipeIds);
    }

    public function testProfileLockIsReleasedOnlyAfterConfirmedExitAndOtherwiseQuarantined(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'browser_capture_lock_' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        try {
            $unconfirmed = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'ctrip',
                'profile-a'
            );
            self::assertIsResource($unconfirmed);
            self::assertFalse(BrowserProfileCaptureRequestService::finalizeProfileCaptureLock(
                $unconfirmed,
                [
                    'process_started' => true,
                    'process_pid' => 4242,
                    'process_tree_exit_confirmed' => false,
                    'termination' => [
                        'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
                        'reason' => 'timeout',
                        'platform' => 'Windows',
                        'confirmed_exited' => false,
                        'confirmation_source' => 'unconfirmed',
                        'errors' => ['process_tree_exit_unconfirmed'],
                    ],
                ]
            ));
            unset($unconfirmed);
            gc_collect_cycles();

            $lockPath = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks'
                . DIRECTORY_SEPARATOR . 'profile_capture_ctrip_profile-a.lock';
            $quarantine = json_decode((string)file_get_contents($lockPath), true);
            self::assertSame('termination_unconfirmed', $quarantine['state'] ?? null);
            self::assertArrayHasKey('process_tree', $quarantine);
            self::assertArrayHasKey('spool_artifacts', $quarantine);
            self::assertNull(BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'ctrip',
                'profile-a'
            ));

            $confirmed = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'meituan',
                'profile-b'
            );
            self::assertIsResource($confirmed);
            self::assertTrue(BrowserProfileCaptureRequestService::finalizeProfileCaptureLock(
                $confirmed,
                [
                    'process_started' => true,
                    'process_tree_exit_confirmed' => true,
                    'termination' => ['confirmed_exited' => true],
                ]
            ));
            $reacquired = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'meituan',
                'profile-b'
            );
            self::assertIsResource($reacquired);
            BrowserProfileCaptureRequestService::releaseProfileCaptureLock($reacquired);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testQuarantineCannotBeBypassedWhileTreeLivesAndRecoversOnlyAfterExitAndArtifactCleanup(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'browser_capture_recovery_' . bin2hex(random_bytes(8));
        mkdir($root, 0775, true);
        $artifact = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
            . 'suxi-browser-quarantine-' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($artifact, 'captured diagnostics');
        try {
            $lock = BrowserProfileCaptureRequestService::acquireProfileCaptureLock($root, 'ctrip', 'profile-recover');
            self::assertIsResource($lock);
            $tree = $this->recordedCurrentProcessTree();
            self::assertFalse(BrowserProfileCaptureRequestService::finalizeProfileCaptureLock($lock, [
                'process_started' => true,
                'process_pid' => getmypid(),
                'process_tree_exit_confirmed' => false,
                'process_tree' => $tree,
                'spool_artifacts' => [$artifact],
                'termination' => [
                    'contract' => BrowserCaptureProcessRunner::TERMINATION_CONTRACT,
                    'reason' => 'timeout',
                    'platform' => $tree['platform'],
                    'pid' => getmypid(),
                    'confirmed_exited' => false,
                    'confirmation_source' => 'unconfirmed',
                    'tracked_descendants' => $tree['tracked_members'],
                    'surviving_descendants' => $tree['survivors'],
                    'errors' => ['process_tree_exit_unconfirmed'],
                ],
            ]));
            unset($lock);
            gc_collect_cycles();

            $lockPath = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks'
                . DIRECTORY_SEPARATOR . 'profile_capture_ctrip_profile-recover.lock';
            $marker = json_decode((string)file_get_contents($lockPath), true);
            self::assertSame([$artifact], $marker['spool_artifacts']);
            self::assertSame(getmypid(), $marker['process_tree']['root_pid']);

            $blocked = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'ctrip',
                'profile-recover'
            );
            self::assertNull($blocked);
            self::assertFileExists($artifact);
            self::assertSame('termination_unconfirmed', json_decode((string)file_get_contents($lockPath), true)['state']);

            $marker['process_tree']['root_pid'] = 2147483000;
            $marker['process_tree']['root_identity'] = hash('sha256', 'exited-fixture');
            $marker['process_tree']['group_id'] = 2147483000;
            $marker['process_tree']['session_id'] = 2147483000;
            $marker['process_tree']['handshake_confirmed'] = true;
            $marker['process_tree']['tracked_members'] = [];
            $marker['process_tree']['survivors'] = [];
            file_put_contents($lockPath, (string)json_encode($marker, JSON_UNESCAPED_SLASHES));

            $recovery = BrowserProfileCaptureRequestService::recoverProfileCaptureLock(
                $root,
                'ctrip',
                'profile-recover'
            );
            self::assertTrue($recovery['recovered']);
            self::assertSame('profile_process_tree_exit_recovered', $recovery['status_code']);
            self::assertFileDoesNotExist($artifact);

            $reacquired = BrowserProfileCaptureRequestService::acquireProfileCaptureLock(
                $root,
                'ctrip',
                'profile-recover'
            );
            self::assertIsResource($reacquired);
            BrowserProfileCaptureRequestService::releaseProfileCaptureLock($reacquired);
        } finally {
            if (is_string($artifact) && is_file($artifact)) {
                unlink($artifact);
            }
            $this->removeDirectory($root);
        }
    }

    public function testCtripMultiSegmentTreeReceiptKeepsTheFirstUnconfirmedSegment(): void
    {
        $results = [
            [
                'success' => false,
                'status_code' => 'process_tree_exit_unconfirmed',
                'exit_code' => -1,
                'process_started' => true,
                'process_pid' => 4242,
                'process_tree_exit_confirmed' => false,
                'process_tree' => [
                    'supported' => true,
                    'platform' => 'Windows',
                    'strategy' => 'windows_descendant_identity_tracking',
                    'root_pid' => 4242,
                    'root_identity' => 'first-root',
                    'tracked_members' => [],
                    'survivors' => [],
                ],
                'termination' => ['confirmed_exited' => false, 'platform' => 'Windows'],
            ],
            [
                'success' => true,
                'status_code' => 'ok',
                'exit_code' => 0,
                'process_started' => true,
                'process_tree_exit_confirmed' => true,
                'termination' => ['confirmed_exited' => true],
            ],
        ];
        $adapter = new CtripBrowserProfileDataSourceAdapter(
            sys_get_temp_dir(),
            'node',
            static function () use (&$results): array {
                return array_shift($results);
            }
        );
        $runProcess = new \ReflectionMethod($adapter, 'runProcess');
        $runProcess->setAccessible(true);
        $runProcess->invoke($adapter, ['node', 'segment-a'], sys_get_temp_dir(), 5);
        $runProcess->invoke($adapter, ['node', 'segment-b'], sys_get_temp_dir(), 5);

        $confirmed = new \ReflectionProperty($adapter, 'captureProcessTreesConfirmed');
        $confirmed->setAccessible(true);
        self::assertFalse($confirmed->getValue($adapter));
        $first = new \ReflectionProperty($adapter, 'firstUnconfirmedCaptureProcessResult');
        $first->setAccessible(true);
        self::assertSame('process_tree_exit_unconfirmed', $first->getValue($adapter)['status_code']);
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/service/platform/CtripBrowserProfileDataSourceAdapter.php');
        self::assertStringContainsString(': $this->firstUnconfirmedCaptureProcessResult', $source);
    }

    public function testInjectedAdapterRunnerGetsAnExplicitTerminalReceipt(): void
    {
        $result = BrowserProfileCaptureRequestService::runCaptureProcess(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5,
            [],
            static fn(): array => ['success' => false, 'message' => 'fixture', 'stdout' => '', 'stderr' => '']
        );

        self::assertTrue($result['process_tree_exit_confirmed']);
        self::assertSame(
            'injected_runner_contract',
            $result['termination']['confirmation_source']
        );
    }

    public function testInjectedRunnerExceptionFailsClosedForProfileLockOwnership(): void
    {
        $result = BrowserProfileCaptureRequestService::runCaptureProcess(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5,
            [],
            static function (): array {
                throw new \RuntimeException('fixture failure');
            }
        );

        self::assertFalse($result['success']);
        self::assertFalse($result['process_tree_exit_confirmed']);
        self::assertSame('injected_runner_error', $result['termination']['reason']);
    }

    public function testInjectedMinusOneExitCannotBeNormalizedToSuccess(): void
    {
        $result = BrowserProfileCaptureRequestService::runCaptureProcess(
            ['node', 'fake-capture.mjs'],
            sys_get_temp_dir(),
            5,
            [],
            static fn(): array => [
                'success' => true,
                'exit_code' => -1,
                'message' => 'legacy success',
                'stdout' => '',
                'stderr' => '',
            ]
        );

        self::assertFalse($result['success']);
        self::assertSame('process_exit_unknown', $result['status_code']);
    }

    public function testAllBrowserCaptureEntrypointsDelegateToTheSharedRunner(): void
    {
        $root = dirname(__DIR__);
        $concern = (string)file_get_contents($root . '/app/controller/concern/CtripCaptureProcessConcern.php');
        $onlineData = (string)file_get_contents($root . '/app/controller/concern/OnlineDataRequestConcern.php');
        $review = (string)file_get_contents($root . '/app/controller/concern/CtripReviewOrderMatchConcern.php');
        $ctrip = (string)file_get_contents($root . '/app/service/platform/CtripBrowserProfileDataSourceAdapter.php');
        $meituan = (string)file_get_contents($root . '/app/service/platform/MeituanBrowserProfileDataSourceAdapter.php');
        $profileLogin = (string)file_get_contents($root . '/app/command/PlatformProfileLogin.php');
        $taskExecution = (string)file_get_contents($root . '/app/service/BrowserCaptureTaskExecutionService.php');

        self::assertStringContainsString('BrowserProfileCaptureRequestService::runCaptureProcess(', $concern);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::runCaptureProcess(', $ctrip);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::runCaptureProcess(', $meituan);
        self::assertStringNotContainsString('proc_open(', $concern);
        self::assertStringNotContainsString('proc_open(', $ctrip);
        self::assertStringNotContainsString('proc_open(', $meituan);
        self::assertStringNotContainsString('proc_open(', $profileLogin);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::runCaptureProcess(', $profileLogin);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::acquireProfileCaptureLock(', $profileLogin);
        self::assertStringContainsString('BrowserProfileCaptureRequestService::finalizeProfileCaptureLock(', $profileLogin);
        self::assertStringContainsString('runMeituanCaptureProcess(', $onlineData);
        self::assertStringContainsString('runMeituanCaptureProcess(', $review);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($onlineData, 'BrowserProfileCaptureRequestService::finalizeProfileCaptureLock(')
        );
        self::assertStringContainsString("'ctrip_browser_profile'", $onlineData);
        self::assertStringContainsString("'meituan_browser_profile'", $onlineData);
        self::assertStringContainsString('manual-fetch-task-status?task_id=', $onlineData);
        self::assertStringContainsString("'background_task'", $onlineData);
        self::assertStringContainsString("'status' => 'queued'", $onlineData);
        self::assertStringContainsString("'浏览器 Profile 采集已提交后台执行', 202", $onlineData);

        self::assertStringContainsString('BrowserCaptureTaskExecutionService::sanitizeBackgroundRequest(', $onlineData);
        $canonicalHotelAt = strpos($onlineData, "\$backgroundRequest['system_hotel_id'] = \$hotelId;");
        $queuedTaskAt = strpos($onlineData, '$task = $service->createTask(', (int)$canonicalHotelAt);
        self::assertIsInt($canonicalHotelAt);
        self::assertIsInt($queuedTaskAt);
        self::assertLessThan($queuedTaskAt, $canonicalHotelAt);
        $sanitizerStart = strpos($taskExecution, 'public static function sanitizeBackgroundRequest');
        $sanitizerEnd = strpos($taskExecution, '/**', $sanitizerStart + 20);
        self::assertIsInt($sanitizerStart);
        self::assertIsInt($sanitizerEnd);
        $sanitizer = substr($taskExecution, $sanitizerStart, $sanitizerEnd - $sanitizerStart);
        self::assertStringNotContainsString("'cookies'", $sanitizer);
        self::assertStringNotContainsString("'authorization'", $sanitizer);
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

    /** @return array<string,mixed> */
    private function recordedCurrentProcessTree(): array
    {
        new BrowserCaptureProcessRunner();
        $runtimeClass = new \ReflectionClass('app\\service\\BrowserCaptureNativeProcessRuntime');
        $runtime = $runtimeClass->newInstance();
        $methodName = PHP_OS_FAMILY === 'Windows' ? 'windowsProcessTable' : 'linuxProcessTable';
        if (!in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true)) {
            self::markTestSkipped('Native quarantine recovery requires Windows or Linux process identity support.');
        }
        $processTable = $runtimeClass->getMethod($methodName);
        $processTable->setAccessible(true);
        $table = $processTable->invoke($runtime);
        self::assertIsArray($table);
        self::assertArrayHasKey(getmypid(), $table);
        $row = $table[getmypid()];
        return [
            'supported' => true,
            'platform' => PHP_OS_FAMILY,
            'strategy' => PHP_OS_FAMILY === 'Windows'
                ? 'windows_descendant_identity_tracking'
                : 'posix_process_group',
            'root_pid' => getmypid(),
            'root_identity' => (string)$row['identity'],
            'group_id' => (int)$row['group_id'],
            'session_id' => (int)($row['session_id'] ?? 0),
            'handshake_confirmed' => PHP_OS_FAMILY === 'Windows'
                || ((int)$row['group_id'] === getmypid() && (int)($row['session_id'] ?? 0) === getmypid()),
            'tracked_members' => [],
            'survivors' => [[
                'pid' => getmypid(),
                'identity' => (string)$row['identity'],
                'parent_pid' => (int)$row['parent_pid'],
            ]],
            'exited' => false,
        ];
    }
}
