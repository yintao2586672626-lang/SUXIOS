<?php
declare(strict_types=1);

namespace Tests;

use app\service\AutonomousMaintenanceService;
use PHPUnit\Framework\TestCase;

/** All observations, state and source files belong to this private fixture tree. */
final class AutonomousMaintenanceRestartRecoveryTest extends TestCase
{
    private const OWNER = 'restart_fixture_owner_1';
    private const OBSERVER = 'restart_fixture_observer_2';
    private const REQUIRED_SOURCES = [
        'app/service/AutonomousMaintenanceService.php',
        'scripts/autonomous_maintenance.php',
    ];

    private string $fixtureDir;
    private string $sourceRoot;
    private string $stateDir;
    private int $now;
    private int $bootEpoch;
    private AutonomousMaintenanceService $service;
    private mixed $guardHandle = null;

    protected function setUp(): void
    {
        $this->fixtureDir = rtrim(sys_get_temp_dir(), '\\/') . '/suxios-maintenance-restart-fixture-' . bin2hex(random_bytes(8));
        $this->sourceRoot = $this->fixtureDir . '/source';
        $this->stateDir = $this->fixtureDir . '/state';
        foreach (['/app/service', '/scripts'] as $relative) {
            self::assertTrue(mkdir($this->sourceRoot . $relative, 0750, true));
        }
        foreach (self::REQUIRED_SOURCES as $relative) {
            self::assertNotFalse(file_put_contents($this->sourceRoot . '/' . $relative,
                "<?php\n// local_synthetic_fixture_only: {$relative} v1\n"));
        }
        $this->now = strtotime('2026-10-03T14:00:00+08:00');
        $this->bootEpoch = $this->now - 3600;
        $this->service = $this->newService();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->guardHandle)) {
            flock($this->guardHandle, LOCK_UN);
            fclose($this->guardHandle);
        }
        $this->guardHandle = null;
        // Resolve both paths before recursive cleanup; only this test's tree is eligible.
        $allowedParent = realpath(sys_get_temp_dir());
        $target = realpath($this->fixtureDir);
        if ($allowedParent === false || $target === false
            || !str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', $allowedParent) . '/suxios-maintenance-restart-fixture-')) {
            throw new \RuntimeException('private_restart_fixture_cleanup_scope_mismatch');
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($target);
    }

    private function newService(): AutonomousMaintenanceService
    {
        return new AutonomousMaintenanceService(
            $this->stateDir,
            fn(): int => $this->now,
            null,
            static fn(array $paths): string => hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR)),
            fn(): int => $this->bootEpoch,
            $this->sourceRoot
        );
    }

    private function revision(): int
    {
        return $this->service->snapshot()['control']['revision'];
    }

    private function start(bool $configurePolicy = true, bool $enabled = true): void
    {
        $this->service->control('resume', $this->revision());
        $this->guardHandle = $this->service->acquireExecutor(self::OWNER);
        if ($configurePolicy) {
            $this->service->configureRestartRecovery(self::OWNER, $this->revision(), [
                'enabled' => $enabled,
                'authorization_reason' => 'local_synthetic_fixture_only: user opted into reviewed machine restart recovery of this local development queue',
            ]);
        }
    }

    private function task(): array
    {
        return $this->service->enqueue([
            'title' => 'local_synthetic_fixture_only: checkpoint continuation',
            'issue_key' => 'restart_checkpoint_continuation',
            'evidence_fingerprint' => 'private-fixture-source-v1',
            'evidence' => ['local_synthetic_fixture_only: bounded task in a private TEMP source tree'],
            'acceptance' => ['save_readback', 'refresh_failure'],
            'priority' => 'P1',
            'scope' => ['workspace' => 'private_restart_fixture_only'],
        ])['task'];
    }

    private function claimedCheckpoint(): array
    {
        $this->task();
        $task = $this->service->claim(self::OWNER);
        self::assertNotNull($task);
        return $this->service->updateTask(self::OWNER, $task['id'], $task['revision'], [
            'action' => 'checkpoint',
            'control_revision' => $this->revision(),
            'note' => 'local_synthetic_fixture_only: edit already persisted before machine loss',
            'next_step' => 'verify the persisted edit only; do not repeat the edit',
            'file_fingerprints' => $this->sourceFingerprints(),
            'processes' => [],
        ]);
    }

    private function sourceFingerprints(): array
    {
        $fingerprints = [];
        foreach (self::REQUIRED_SOURCES as $relative) {
            $fingerprints[$relative] = hash_file('sha256', $this->sourceRoot . '/' . $relative);
        }
        return $fingerprints;
    }

    private function loseGuard(bool $verifiedMachineRestart = true): void
    {
        self::assertTrue(is_resource($this->guardHandle));
        flock($this->guardHandle, LOCK_UN);
        fclose($this->guardHandle);
        $this->guardHandle = null;
        if ($verifiedMachineRestart) {
            // The boot observation is later than guard start and earlier than now.
            $this->bootEpoch = $this->now + 60;
            $this->now += 120;
        }
    }

    private function observeLoss(): array
    {
        $exception = null;
        try {
            $unexpectedHandle = $this->service->acquireExecutor(self::OBSERVER);
            if (is_resource($unexpectedHandle)) {
                flock($unexpectedHandle, LOCK_UN);
                fclose($unexpectedHandle);
            }
        } catch (\DomainException $error) {
            $exception = $error;
        }
        self::assertNotNull($exception, 'Orphan detection must never silently acquire a replacement executor.');
        self::assertSame('maintenance_orphan', $exception->getMessage());
        return $this->service->snapshot();
    }

    private function recover(): array
    {
        return $this->service->recoverRestart($this->revision());
    }

    private function assertNotRunning(array $result): void
    {
        self::assertContains($result['outcome'], [
            'control_preserved', 'checkpoint_mismatch', 'machine_restart_not_verified', 'review_required',
        ]);
        self::assertNotSame('running', $result['control']['mode']);
        self::assertNotSame('running', $this->service->snapshot()['control']['mode']);
    }

    private function assertUserControlPreserved(string $action, bool $beforeLoss): void
    {
        $this->start();
        if ($beforeLoss) $this->service->control($action, $this->revision(), null, 'explicit fixture user control');
        $this->loseGuard();
        $this->observeLoss();
        if (!$beforeLoss) $this->service->control($action, $this->revision(), null, 'explicit fixture user control');
        $before = $this->service->snapshot()['control'];
        $result = $this->recover();
        self::assertSame('control_preserved', $result['outcome']);
        self::assertSame($before, $result['control']);
        self::assertSame($before, $this->service->snapshot()['control']);
    }

    public function testReviewedMachineRestartResumesPreviouslyRunningEmptyQueueAndNewGuardCanAcquire(): void
    {
        $this->start();
        $this->loseGuard();
        $lost = $this->observeLoss();
        $result = $this->recover();
        self::assertSame('resumed', $result['outcome']);
        self::assertSame('running', $result['control']['mode']);
        self::assertGreaterThan($lost['control']['revision'], $result['control']['revision']);
        self::assertFalse($this->service->snapshot()['recovery_required']);
        $this->guardHandle = $this->service->acquireExecutor('restart_fixture_resumed_3');
        self::assertTrue(is_resource($this->guardHandle));
    }

    public function testReviewedInterruptedTaskPreservesCheckpointNextStepAndAcceptanceTiers(): void
    {
        $this->start();
        $saved = $this->claimedCheckpoint();
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('resumed', $result['outcome']);
        $task = $this->service->snapshot()['tasks'][$saved['id']];
        self::assertSame('queued', $task['state']);
        self::assertSame($saved['checkpoint'], $task['checkpoint']);
        self::assertSame($saved['required_tiers'], $task['required_tiers']);
        self::assertSame($saved['attempts'], $task['attempts'], 'Recovery must not execute or replay the task.');
        self::assertNull($task['result']);
        $this->guardHandle = $this->service->acquireExecutor('restart_fixture_resumed_3');
        $reclaimed = $this->service->claim('restart_fixture_resumed_3', $task['id']);
        self::assertSame($saved['checkpoint'], $reclaimed['checkpoint']);
        self::assertSame('verify the persisted edit only; do not repeat the edit', $reclaimed['checkpoint']['next_step']);
    }

    public function testExistingReviewedExecutorRecoveryKeepsItsManualControlSemantics(): void
    {
        $this->start();
        $this->loseGuard();
        $this->observeLoss();
        $before = $this->service->snapshot()['control'];
        $this->service->recoverExecutor([
            'execution_id' => self::OWNER,
            'writes_quiescent' => true,
            'process_evidence' => 'local_synthetic_fixture_only: guard closed; no child processes launched',
            'checkpoint_review' => 'local_synthetic_fixture_only: no task started; private queue unchanged',
        ]);
        self::assertFalse($this->service->snapshot()['recovery_required']);
        self::assertSame($before, $this->service->snapshot()['control']);
        self::assertSame('exception_stop', $before['mode']);
    }

    public function testPolicyDefaultsOffAndDoesNotRetroactivelyAuthorizeExistingExecution(): void
    {
        $this->start(false);
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('review_required', $result['outcome']);
        $this->assertNotRunning($result);
    }

    public function testLegacyStateWithoutNewPolicyOrReceiptRemainsFailClosed(): void
    {
        $this->start(false);
        $this->loseGuard();
        $state = $this->observeLoss();
        $legacy = array_intersect_key($state, array_flip([
            'schema_version', 'revision', 'control', 'executor', 'last_execution_id', 'recovery_required',
            'tasks', 'events', 'last_check', 'scheduler', 'daily_summaries', 'candidates', 'runs',
        ]));
        file_put_contents($this->stateDir . '/state.json', json_encode($legacy, JSON_THROW_ON_ERROR));
        $this->service = $this->newService();
        $result = $this->recover();
        self::assertSame('review_required', $result['outcome']);
        $this->assertNotRunning($result);
        self::assertSame($legacy['tasks'], $this->service->snapshot()['tasks']);
    }

    public function testExplicitlyDisabledPolicyDoesNotResumeAfterVerifiedBoot(): void
    {
        $this->start(true, false);
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('review_required', $result['outcome']);
        $this->assertNotRunning($result);
    }

    public function testGuardLossOnSameMachineBootRequiresReviewAndDoesNotResume(): void
    {
        $this->start();
        $this->loseGuard(false);
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('machine_restart_not_verified', $result['outcome']);
        $this->assertNotRunning($result);
    }

    public function testUnavailableBootObservationDoesNotBecomeVerifiedRestart(): void
    {
        $this->start();
        $this->loseGuard(false);
        $this->bootEpoch = 0;
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('machine_restart_not_verified', $result['outcome']);
        $this->assertNotRunning($result);
    }

    public function testRepeatedOrphanObservationPreservesOriginalLossRevisionAndEvent(): void
    {
        $this->start();
        $this->loseGuard();
        $first = $this->observeLoss();
        $second = $this->observeLoss();
        self::assertSame($first['control'], $second['control']);
        self::assertSame($first['events'], $second['events']);
        self::assertSame('resumed', $this->recover()['outcome']);
    }

    public function testLiveGuardLockCannotBeStolenByRecovery(): void
    {
        $this->start();
        $before = $this->service->snapshot();
        $exception = null;
        $result = null;
        try { $result = $this->recover(); }
        catch (\DomainException $error) { $exception = $error; }
        if ($exception !== null) self::assertSame('maintenance_executor_busy', $exception->getMessage());
        else self::assertSame('review_required', $result['outcome']);
        $after = $this->service->snapshot();
        self::assertSame($before['control'], $after['control']);
        self::assertSame($before['executor'], $after['executor']);
        self::assertTrue($after['executor_lock_held']);
    }

    public function testExpiredHeartbeatWithPhysicalLockStillHeldCannotBeReclaimed(): void
    {
        $this->start();
        $before = $this->service->snapshot()['control'];
        $this->now += 60;
        $this->bootEpoch = $this->now - 10;
        $exception = null;
        $result = null;
        try { $result = $this->recover(); }
        catch (\DomainException $error) { $exception = $error; }
        if ($exception !== null) self::assertSame('maintenance_executor_busy', $exception->getMessage());
        else self::assertSame('review_required', $result['outcome']);
        $state = $this->service->snapshot();
        self::assertSame($before, $state['control']);
        self::assertSame(self::OWNER, $state['executor']['execution_id']);
        self::assertTrue($state['executor_lock_held']);
    }

    public function testChangedRequiredPolicySourceFailsFingerprintGate(): void
    {
        $this->start();
        $this->loseGuard();
        $this->observeLoss();
        file_put_contents($this->sourceRoot . '/' . self::REQUIRED_SOURCES[0], "<?php\n// private fixture changed after authorization\n");
        $result = $this->recover();
        self::assertSame('checkpoint_mismatch', $result['outcome']);
        $this->assertNotRunning($result);
        self::assertTrue($this->service->snapshot()['recovery_required']);
    }

    public function testMissingRequiredPolicySourceIsNotAnEmptyFingerprintSuccess(): void
    {
        $this->start();
        $this->loseGuard();
        $this->observeLoss();
        unlink($this->sourceRoot . '/' . self::REQUIRED_SOURCES[1]);
        $result = $this->recover();
        self::assertSame('checkpoint_mismatch', $result['outcome']);
        $this->assertNotRunning($result);
    }

    public function testInterruptedTaskWithoutSavedCheckpointCannotBeAutomaticallyReplayed(): void
    {
        $this->start();
        $task = $this->task();
        $this->service->claim(self::OWNER, $task['id']);
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertContains($result['outcome'], ['review_required', 'checkpoint_mismatch']);
        $this->assertNotRunning($result);
        self::assertSame('interrupted', $this->service->snapshot()['tasks'][$task['id']]['state']);
    }

    public function testCheckpointFingerprintMismatchKeepsInterruptedTaskAndExactNextStep(): void
    {
        $this->start();
        $task = $this->task();
        $claimed = $this->service->claim(self::OWNER, $task['id']);
        $saved = $this->service->updateTask(self::OWNER, $task['id'], $claimed['revision'], [
            'action' => 'checkpoint', 'control_revision' => $this->revision(),
            'note' => 'local_synthetic_fixture_only: intentionally stale checkpoint',
            'next_step' => 'review drift before any retry',
            'file_fingerprints' => [self::REQUIRED_SOURCES[0] => str_repeat('0', 64)],
            'processes' => [],
        ]);
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertSame('checkpoint_mismatch', $result['outcome']);
        $this->assertNotRunning($result);
        $after = $this->service->snapshot()['tasks'][$task['id']];
        self::assertSame('interrupted', $after['state']);
        self::assertSame($saved['checkpoint'], $after['checkpoint']);
    }

    public function testPauseBeforeLossIsPreserved(): void { $this->assertUserControlPreserved('pause', true); }
    public function testStopBeforeLossIsPreserved(): void { $this->assertUserControlPreserved('stop', true); }
    public function testManualExceptionStopBeforeLossIsPreserved(): void { $this->assertUserControlPreserved('exception_stop', true); }
    public function testPauseAfterLossIsPreserved(): void { $this->assertUserControlPreserved('pause', false); }
    public function testStopAfterLossIsPreserved(): void { $this->assertUserControlPreserved('stop', false); }
    public function testManualExceptionStopAfterLossIsPreserved(): void { $this->assertUserControlPreserved('exception_stop', false); }

    public function testDeadlineReachedBeforeLossDoesNotGainAutomaticResumePermission(): void
    {
        $this->start();
        $deadline = date('Y-m-d\TH:i:sP', $this->now + 30);
        $this->service->control('deadline', $this->revision(), $deadline);
        $this->loseGuard();
        $this->observeLoss();
        $before = $this->service->snapshot()['control'];
        $result = $this->recover();
        self::assertSame('control_preserved', $result['outcome']);
        self::assertSame('stopped', $result['control']['mode']);
        self::assertSame($before, $result['control']);
    }

    public function testDeadlineExpiringWhileRestartReviewPendingCannotResumeOrExtendIt(): void
    {
        $this->start();
        $deadline = date('Y-m-d\TH:i:sP', $this->now + 300);
        $this->service->control('deadline', $this->revision(), $deadline);
        $this->loseGuard();
        $lost = $this->observeLoss();
        $this->now += 181;
        $result = $this->recover();
        self::assertSame('control_preserved', $result['outcome']);
        $this->assertNotRunning($result);
        self::assertSame($lost['control']['deadline_at'], $result['control']['deadline_at']);
    }

    public function testUnsafeExitEnforcesElapsedDeadlineBeforeClassifyingTheExit(): void
    {
        $this->start();
        $this->service->control('deadline', $this->revision(), date('Y-m-d\TH:i:sP', $this->now + 60));
        $this->now += 61;
        $this->service->releaseExecutor(self::OWNER, $this->guardHandle);
        $this->guardHandle = null;
        $state = $this->service->snapshot();
        self::assertSame('stopped', $state['control']['mode']);
        self::assertSame('deadline_reached', $state['control']['reason']);
        self::assertTrue($state['recovery_required']);
        self::assertNotSame('resumed', $this->service->recoverRestart($state['control']['revision'])['outcome']);
    }

    public function testCorruptReceiptCannotReplaceRequiredGuardSourcesWithOtherValidFiles(): void
    {
        $this->start();
        $this->loseGuard();
        $state = $this->observeLoss();
        $wrong = [];
        foreach (['scripts/unrelated-one.php', 'scripts/unrelated-two.php'] as $path) {
            file_put_contents($this->sourceRoot . '/' . $path, '<?php // local_synthetic_fixture_only');
            $wrong[$path] = hash_file('sha256', $this->sourceRoot . '/' . $path);
        }
        $state['interruption']['file_fingerprints'] = $wrong;
        file_put_contents($this->stateDir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $result = $this->recover();
        self::assertSame('checkpoint_mismatch', $result['outcome']);
        $this->assertNotRunning($result);
        self::assertSame($state['control'], $this->service->snapshot()['control']);
    }

    public function testChangingDeadlineAfterLossFencesTheOriginalResumeReceipt(): void
    {
        $this->start();
        $this->loseGuard();
        $this->observeLoss();
        $this->service->control('deadline', $this->revision(), date('Y-m-d\TH:i:sP', $this->now + 3600));
        $before = $this->service->snapshot()['control'];
        $result = $this->recover();
        self::assertSame('control_preserved', $result['outcome']);
        self::assertSame($before, $result['control']);
    }

    private function assertTerminalTaskNotRetried(string $terminalState): void
    {
        $this->start();
        $task = $this->task();
        $claimed = $this->service->claim(self::OWNER, $task['id']);
        $saved = $this->service->updateTask(self::OWNER, $task['id'], $claimed['revision'], [
            'action' => $terminalState, 'control_revision' => $this->revision(),
            'result' => ['reason' => 'local_synthetic_fixture_only: task needs new evidence or user input',
                'next_step' => 'do not automatically retry this terminal task'],
        ]);
        $this->loseGuard();
        $this->observeLoss();
        $result = $this->recover();
        self::assertContains($result['outcome'], ['resumed', 'review_required']);
        $after = $this->service->snapshot()['tasks'][$task['id']];
        self::assertSame($terminalState, $after['state']);
        self::assertSame($saved['result'], $after['result']);
        self::assertSame($saved['attempts'], $after['attempts']);
    }

    public function testFailedTaskIsNotAutomaticallyRetried(): void { $this->assertTerminalTaskNotRetried('failed'); }
    public function testWaitingUserTaskIsNotAutomaticallyRetried(): void { $this->assertTerminalTaskNotRetried('waiting_user'); }

    public function testStaleExpectedControlRevisionCannotAlterState(): void
    {
        $this->start();
        $this->loseGuard();
        $lost = $this->observeLoss();
        $this->service->control('pause', $lost['control']['revision']);
        $before = $this->service->snapshot();
        $exception = null;
        $result = null;
        try { $result = $this->service->recoverRestart($lost['control']['revision']); }
        catch (\DomainException $error) { $exception = $error; }
        if ($exception !== null) self::assertSame('maintenance_control_changed', $exception->getMessage());
        else self::assertSame('control_preserved', $result['outcome']);
        $after = $this->service->snapshot();
        self::assertSame($before['control'], $after['control']);
        self::assertSame($before['executor'], $after['executor']);
        self::assertSame($before['tasks'], $after['tasks']);
        self::assertSame($before['recovery_required'], $after['recovery_required']);
    }

    public function testCorruptStorageCannotFabricateAnEmptyRecoverableQueue(): void
    {
        $this->start();
        $this->loseGuard();
        $lost = $this->observeLoss();
        $corrupt = '{local_synthetic_fixture_only: deliberately corrupt state';
        file_put_contents($this->stateDir . '/state.json', $corrupt);
        $exception = null;
        try { $this->service->recoverRestart($lost['control']['revision']); }
        catch (\Throwable $error) { $exception = $error; }
        self::assertNotNull($exception, 'Corrupt storage must fail explicitly.');
        self::assertSame($corrupt, file_get_contents($this->stateDir . '/state.json'));
    }
}
