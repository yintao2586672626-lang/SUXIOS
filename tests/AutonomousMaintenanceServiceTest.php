<?php
declare(strict_types=1);
namespace Tests;
use app\service\AutonomousMaintenanceService;
use PHPUnit\Framework\TestCase;

final class AutonomousMaintenanceServiceTest extends TestCase
{
    private string $dir;
    private int $now;
    private AutonomousMaintenanceService $service;
    private string $sourceVersion = 'fixture-source-v1';
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/suxios-maintenance-' . bin2hex(random_bytes(8));
        $this->now = strtotime('2026-10-02T09:00:00+08:00');
        $this->service = new AutonomousMaintenanceService($this->dir, fn(): int => $this->now, null,
            fn(array $paths): string => hash('sha256', $this->sourceVersion . json_encode($paths)));
    }
    private function task(string $issue = 'one', array $extra = []): array
    {
        return $this->service->enqueue(array_replace([
            'title' => '隔离测试研发问题', 'issue_key' => $issue, 'evidence_fingerprint' => 'source-sha256-one',
            'evidence' => ['local_synthetic_fixture_only: reproducible missing function'],
            'acceptance' => ['save_readback', 'refresh_failure'], 'priority' => 'P1',
            'scope' => ['workspace' => 'isolated_test_only'],
        ], $extra))['task'];
    }
    private function start(): array
    {
        $this->service->control('resume', 0);
        $handle = $this->service->acquireExecutor('isolated_owner_1');
        return [$handle, $this->service->claim('isolated_owner_1')];
    }
    private function acceptedResult(): array
    {
        return ['action' => 'completed', 'control_revision' => 1, 'result' => [
            'summary' => 'fixture completed', 'tiers' => ['local_completed' => true, 'automated_verified' => true,
                'actual_page_verified' => false, 'github_saved' => false, 'production_deployed' => false, 'business_effect_verified' => false],
            'acceptance_checks' => ['save_readback' => ['status' => 'passed', 'evidence' => 'isolated save exact readback'],
                'refresh_failure' => ['status' => 'passed', 'evidence' => 'isolated page error recovery']],
        ]];
    }
    private function candidateInput(string $state = 'needs_review', string $issue = 'next-path'): array
    {
        return ['issue_key' => $issue, 'title' => 'local_synthetic_fixture_only: next path', 'state' => $state,
            'scope' => ['workspace' => 'isolated_test_only'], 'priority' => 'P1', 'evidence_fingerprint' => 'stable-source-one',
            'target_paths' => ['fixture/path.php'], 'evidence' => ['local_synthetic_fixture_only: bounded observation'],
            'next_step' => 'reproduce save readback; do not invent real OTA facts', 'reproduction' => $state === 'ready' ? 'fixture deterministically fails' : '',
            'task_input' => ['title' => 'fixture reproduced issue', 'acceptance' => ['save_readback', 'refresh_failure']]];
    }
    public function testThreeHistoricalAreaReviewsDoNotExhaustOtherBusinessPaths(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            foreach (['ota_source', 'revenue_analysis', 'operation_intent'] as $area) {
                $review = $this->candidateInput('reviewed_no_issue', $area);
                $review['scope']['discovery_area'] = $area;
                $this->service->candidate('isolated_owner_1', 1, $review);
            }
            $plan = $this->service->next('isolated_owner_1', 1);
            self::assertSame('discover', $plan['action'], 'one reviewed path cannot exhaust a whole business area');
            self::assertNotEmpty($plan['discovery_path']);
            self::assertNotEmpty($plan['target_paths']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testEmptyQueueDiscoversThenReadyCandidateBecomesOneTaskAndDoesNotReopen(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            self::assertSame('discover', $this->service->next('isolated_owner_1', 1)['action']);
            self::assertSame('discovery_pending', $this->service->snapshot()['work_status']['state']);
            $run = ['run_id' => 'fixture-run', 'trigger' => 'manual', 'triggered_at' => '2026-10-02T09:00:00+08:00',
                'observed_at' => '2026-10-02T09:00:00+08:00', 'outcome' => 'no_action', 'evidence' => 'fixture empty queue', 'next_step' => 'fixture'];
            foreach (['no_action', 'executing'] as $outcome) {
                $run['outcome'] = $outcome;
                try { $this->service->recordRun('isolated_owner_1', 1, $run); self::fail('fabricated idle/execution accepted'); }
                catch (\InvalidArgumentException $e) { self::assertStringStartsWith('maintenance_run_', $e->getMessage()); }
            }
            $review = $this->candidateInput();
            $candidate = $this->service->candidate('isolated_owner_1', 1, $review);
            self::assertSame($candidate, $this->service->candidate('isolated_owner_1', 1, $review));
            self::assertSame('review_candidate', $this->service->next('isolated_owner_1', 1)['action']);
            $this->service->candidate('isolated_owner_1', 1, $this->candidateInput('ready'));
            $plan = $this->service->next('isolated_owner_1', 1);
            self::assertSame('execute_task', $plan['action']);
            self::assertSame($plan['task_id'], $this->service->next('isolated_owner_1', 1)['task_id']);
            self::assertCount(1, $this->service->snapshot()['tasks']);
            $task = $this->service->claim('isolated_owner_1', $plan['task_id']);
            self::assertSame('executing', $this->service->snapshot()['work_status']['state']);
            $this->service->updateTask('isolated_owner_1', $task['id'], $task['revision'], $this->acceptedResult());
            self::assertSame('linked', $this->service->candidate('isolated_owner_1', 1, $this->candidateInput('ready'))['state']);
            self::assertSame('discover', $this->service->next('isolated_owner_1', 1)['action']);
            foreach (['ota_source', 'revenue_analysis', 'operation_intent'] as $area) {
                $review = $this->candidateInput('reviewed_no_issue', $area);
                $review['scope']['discovery_area'] = $area;
                $this->service->candidate('isolated_owner_1', 1, $review);
            }
            self::assertSame('discover', $this->service->next('isolated_owner_1', 1)['action']);
            $this->reviewRemainingPaths();
            self::assertSame('no_action', $this->service->next('isolated_owner_1', 1)['action']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    private function reviewRemainingPaths(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $plan = $this->service->next('isolated_owner_1', 1);
            if ($plan['action'] === 'no_action') return;
            self::assertSame('discover', $plan['action']);
            $review = $this->candidateInput('reviewed_no_issue', 'review-' . $plan['discovery_path']);
            $review['scope']['discovery_area'] = $plan['discovery_area'];
            $review['scope']['discovery_path'] = $plan['discovery_path'];
            $review['target_paths'] = $plan['target_paths'];
            $this->service->candidate('isolated_owner_1', 1, $review);
        }
    }
    public function testPathCoveragePersistsAndChangedSourceOrInputReopensReview(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            $plan = $this->service->next('isolated_owner_1', 1);
            $blocked = $this->candidateInput('blocked', 'review-' . $plan['discovery_path']);
            $blocked['scope']['discovery_area'] = $plan['discovery_area'];
            $blocked['scope']['discovery_path'] = $plan['discovery_path'];
            $blocked['target_paths'] = $plan['target_paths'];
            $this->service->candidate('isolated_owner_1', 1, $blocked);
            self::assertNotSame($plan['discovery_path'], $this->service->next('isolated_owner_1', 1)['discovery_path']);
            $this->reviewRemainingPaths();
            $fresh = new AutonomousMaintenanceService($this->dir, fn(): int => $this->now, null,
                fn(array $paths): string => hash('sha256', $this->sourceVersion . json_encode($paths)));
            self::assertSame('no_action', $fresh->next('isolated_owner_1', 1)['action']);
            self::assertSame(6, $fresh->snapshot()['work_status']['discovery_coverage']['reviewed_paths']);
            $this->sourceVersion = 'fixture-source-v2';
            self::assertSame('discover', $fresh->next('isolated_owner_1', 1)['action']);
            $this->sourceVersion = 'fixture-source-v1';
            $fresh->recordCheck(['fingerprint' => 'unchanged-source', 'input_fingerprint' => 'new-authorized-metadata',
                'outcome' => 'checking', 'evidence' => 'local_synthetic_fixture_only: metadata signal changed']);
            self::assertSame('discover', $fresh->next('isolated_owner_1', 1)['action']);
            $fresh->recordCheck(['fingerprint' => 'unchanged-source', 'outcome' => 'checking', 'evidence' => 'legacy check']);
            self::assertSame('new-authorized-metadata', $fresh->snapshot()['last_check']['input_fingerprint']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testStableCompletedLegacyCandidateAdoptsPathWithoutNewTaskOrCandidate(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            $input = $this->candidateInput('ready', 'ota_missing_fact_same_scope_repair_entry');
            $input['scope']['discovery_area'] = 'ota_source';
            $input['target_paths'] = ['public/home-static.js', 'public/app-main.js'];
            $candidate = $this->service->candidate('isolated_owner_1', 1, $input);
            $plan = $this->service->next('isolated_owner_1', 1);
            $task = $this->service->claim('isolated_owner_1', $plan['task_id']);
            $this->service->updateTask('isolated_owner_1', $task['id'], $task['revision'], $this->acceptedResult());
            $state = json_decode(file_get_contents($this->dir . '/state.json'), true);
            unset($state['candidates'][$candidate['id']]['discovery_source_fingerprint']);
            file_put_contents($this->dir . '/state.json', json_encode($state));
            $input['reuse_verified_baseline'] = true;
            $adopted = $this->service->candidate('isolated_owner_1', 1, $input);
            self::assertSame('linked', $adopted['state']);
            self::assertSame($task['id'], $adopted['task_id']);
            self::assertSame('ota_revenue_health_navigation', $this->service->next('isolated_owner_1', 1)['discovery_path']);
            self::assertCount(1, $this->service->snapshot()['tasks']);
            self::assertCount(1, $this->service->snapshot()['candidates']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testBlockedOtaCandidateDoesNotStopIndependentReviewAndPauseFencesIt(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            $ota = $this->candidateInput('blocked', 'ota-real-facts-missing');
            $ota['next_step'] = 'waiting real same-scope receipt';
            $this->service->candidate('isolated_owner_1', 1, $ota);
            $local = $this->service->candidate('isolated_owner_1', 1, $this->candidateInput());
            self::assertSame($local['id'], $this->service->next('isolated_owner_1', 1)['candidate']['id']);
            self::assertCount(0, $this->service->snapshot()['tasks']);
            $this->service->control('pause', 1);
            self::assertSame('control_blocked', $this->service->snapshot()['work_status']['state']);
            try { $this->service->next('isolated_owner_1', 1); self::fail('paused plan accepted'); }
            catch (\DomainException $e) { self::assertSame('maintenance_execution_not_allowed', $e->getMessage()); }
            self::assertTrue($this->service->snapshot()['executor_lock_held']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testDelayedWaitReceiptRetainsOriginalTimeAndCannotReplaceNewerRun(): void
    {
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            $current = ['run_id' => 'manual-current', 'trigger' => 'manual', 'triggered_at' => '2026-10-02T09:00:00+08:00',
                'observed_at' => '2026-10-02T09:00:00+08:00', 'outcome' => 'checking', 'evidence' => 'fixture current owner', 'next_step' => 'review one path'];
            $this->service->recordRun('isolated_owner_1', 1, $current);
            $wait = array_replace($current, ['run_id' => 'scheduled-old', 'trigger' => 'scheduled',
                'triggered_at' => '2026-10-02T08:15:00+08:00', 'observed_at' => '2026-10-02T08:16:00+08:00',
                'outcome' => 'waiting_concurrency', 'resume_condition' => 'fixture writer must exit']);
            $saved = $this->service->recordRun('isolated_owner_1', 1, $wait);
            self::assertTrue($saved['delayed_registration']);
            self::assertSame('2026-10-02T08:16:00+08:00', $saved['observed_at']);
            self::assertSame($saved, $this->service->recordRun('isolated_owner_1', 1, $wait));
            $fresh = (new AutonomousMaintenanceService($this->dir, fn(): int => $this->now))->snapshot();
            self::assertSame($saved, $fresh['runs']['scheduled-old']);
            self::assertSame('manual-current', $fresh['work_status']['latest_run']['run_id']);
            self::assertFalse($fresh['work_status']['check_overdue']);
            self::assertSame(1, $fresh['daily_summaries']['2026-10-02']['run_counts']['waiting_concurrency']);
            $this->now += 1801;
            self::assertTrue($this->service->snapshot()['work_status']['check_overdue']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testOldStateGetsAdditiveDiscoveryWithoutFabricatingRunAndInvalidCandidateIsRejected(): void
    {
        $this->task(); $path = $this->dir . '/state.json';
        $state = json_decode(file_get_contents($path), true); unset($state['candidates'], $state['runs']);
        file_put_contents($path, json_encode($state));
        $fresh = $this->service->snapshot(); self::assertSame([], $fresh['runs']); self::assertSame([], $fresh['candidates']);
        self::assertNull($fresh['last_check']); self::assertCount(1, $fresh['tasks']);
        $this->service->control('resume', 0); $handle = $this->service->acquireExecutor('isolated_owner_1');
        try {
            $candidate = $this->candidateInput('ready'); $candidate['task_input']['kind'] = 'operation';
            try { $this->service->candidate('isolated_owner_1', 1, $candidate); self::fail('operation candidate accepted'); }
            catch (\InvalidArgumentException $e) { self::assertSame('maintenance_candidate_requires_development_reproduction', $e->getMessage()); }
            self::assertCount(0, $this->service->snapshot()['candidates']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }
    public function testFullLifecyclePersistsExactResultAndDeduplicatesAfterRestart(): void
    {
        $created = $this->task();
        [$handle, $claimed] = $this->start();
        try {
            $saved = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], $this->acceptedResult());
            self::assertSame('completed', $saved['state']);
            $fresh = new AutonomousMaintenanceService($this->dir, fn(): int => $this->now);
            self::assertSame($saved, $fresh->snapshot()['tasks'][$saved['id']]);
            self::assertTrue($fresh->enqueue(['title' => $created['title'], 'issue_key' => 'one',
                'evidence_fingerprint' => 'source-sha256-one', 'scope' => ['workspace' => 'isolated_test_only']])['duplicate']);
            self::assertCount(1, $fresh->snapshot()['tasks']);
            $day = $fresh->snapshot()['daily_summaries']['2026-10-02'];
            self::assertSame([$saved['id']], $day['completed_task_ids']);
            self::assertFalse($saved['result']['tiers']['actual_page_verified']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle); }
    }
    public function testLockBlocksSecondExecutorAndPausedLateCompletion(): void
    {
        $this->task();
        [$handle, $claimed] = $this->start();
        try {
            try { $this->service->acquireExecutor('isolated_owner_2'); self::fail('second executor accepted'); }
            catch (\DomainException $e) { self::assertSame('maintenance_executor_busy', $e->getMessage()); }
            $this->service->control('pause', 1);
            self::assertSame('stop_requested', $this->service->snapshot()['execution_state']);
            try {
                $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], $this->acceptedResult());
                self::fail('late completion accepted after pause');
            } catch (\DomainException $e) { self::assertSame('maintenance_execution_not_allowed', $e->getMessage()); }
            self::assertTrue($this->service->heartbeat('isolated_owner_1'));
            self::assertTrue($this->service->snapshot()['executor_lock_held']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle); }
        self::assertSame('interrupted', $this->service->snapshot()['tasks'][$claimed['id']]['state']);
        self::assertSame('recovery_pending', $this->service->snapshot()['execution_state']);
    }
    public function testRecoveryNeedsQuiescenceCheckpointReviewAndPreservesNextStep(): void
    {
        $this->task();
        [$handle, $claimed] = $this->start();
        $checkpoint = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
            'action' => 'checkpoint', 'control_revision' => 1, 'note' => 'one step persisted',
            'next_step' => 'verify only; do not repeat edit', 'file_fingerprints' => ['test.php' => 'sha256-example'],
        ]);
        $this->service->releaseExecutor('isolated_owner_1', $handle);
        $task = $this->service->snapshot()['tasks'][$claimed['id']];
        try { $this->service->recover($task['id'], $task['revision'], []); self::fail('unreviewed retry accepted'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('quiescent', $e->getMessage()); }
        $recovered = $this->service->recover($task['id'], $task['revision'], [
            'execution_id' => 'isolated_owner_1',
            'writes_quiescent' => true, 'process_evidence' => 'isolated guard released; no child processes',
            'checkpoint_review' => 'exact fingerprints match; edit already complete', 'next_step' => 'verify only',
        ]);
        self::assertSame($checkpoint['checkpoint'], $recovered['checkpoint']);
        self::assertSame('queued', $recovered['state']);
        self::assertSame('verify only', $recovered['recovery_review']['next_step']);
    }
    public function testKilledGuardFailStopsInsteadOfStartingAnotherTask(): void
    {
        $this->task();
        [$handle, $claimed] = $this->start();
        flock($handle, LOCK_UN); fclose($handle); // Simulates abrupt process death.
        try { $this->service->acquireExecutor('isolated_owner_2'); self::fail('orphan execution repeated'); }
        catch (\DomainException $e) { self::assertSame('maintenance_orphan', $e->getMessage()); }
        $state = $this->service->snapshot();
        self::assertSame('exception_stop', $state['control']['mode']);
        self::assertTrue($state['recovery_required']);
        self::assertSame('interrupted', $state['tasks'][$claimed['id']]['state']);
    }
    public function testDeadlineStopsAndOldControlRevisionCannotOverwriteNewControl(): void
    {
        $this->service->control('deadline', 0, '2026-10-02T09:01:00+08:00');
        $this->service->control('resume', 1);
        $this->now += 61;
        self::assertSame('stopped', $this->service->snapshot()['control']['mode']);
        try { $this->service->control('resume', 1); self::fail('stale control accepted'); }
        catch (\DomainException $e) { self::assertSame('maintenance_control_changed', $e->getMessage()); }
    }
    public function testAcceptanceGateRejectsMissingChecksAndStaleTaskRevision(): void
    {
        $this->task();
        [$handle, $claimed] = $this->start();
        try {
            try { $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], ['action' => 'completed', 'control_revision' => 1]); self::fail(); }
            catch (\InvalidArgumentException $e) { self::assertSame('maintenance_acceptance_incomplete', $e->getMessage()); }
            $saved = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
                'note' => 'saved', 'next_step' => 'verify', 'control_revision' => 1]);
            try { $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], $this->acceptedResult()); self::fail(); }
            catch (\DomainException $e) { self::assertSame('maintenance_task_changed', $e->getMessage()); }
            self::assertSame('running', $saved['state']);
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle); }
    }
    public function testOperationalSuggestionsRequireRealScopedEvidenceAndCannotBeClaimed(): void
    {
        try { $this->task('op', ['kind' => 'operation']); self::fail('unscoped operational fact accepted'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('same_scope', $e->getMessage()); }
        $scope = ['tenant_id' => 1, 'hotel_id' => 80, 'platform' => 'ctrip', 'business_date' => '2026-10-01',
            'quality' => 'readback_verified', 'source_kind' => 'real', 'source_receipts' => ['test-fixture-receipt-only']];
        try { $this->task('op', ['kind' => 'operation', 'scope' => $scope]); self::fail('self-declared real evidence accepted'); }
        catch (\InvalidArgumentException $e) { self::assertSame('operation_requires_persisted_pending_intent_reference', $e->getMessage()); }
        $this->service = new AutonomousMaintenanceService($this->dir, fn(): int => $this->now, static fn(): array => [
            'id' => 9, 'tenant_id' => 1, 'hotel_id' => 80, 'platform' => 'ctrip', 'date_start' => '2026-10-01', 'date_end' => '2026-10-01',
            'status' => 'pending_approval', 'fact_integrity_status' => 'verified', 'tasks' => [], 'evidence' => ['fixture_only' => true]]);
        $op = $this->task('op', ['kind' => 'operation', 'scope' => $scope, 'operation_intent_id' => 9, 'suggested_action' => '人工复核证据']);
        self::assertSame('pending_confirmation', $op['state']);
        [$handle, $claimed] = $this->start();
        try { self::assertNull($claimed); self::assertNull($this->service->claim('isolated_owner_1', $op['id'])); }
        finally { $this->service->releaseExecutor('isolated_owner_1', $handle); }
    }
    public function testUnchangedChecksDoNotRepeatEventsAndNextShanghaiDayGetsSummary(): void
    {
        $check = ['fingerprint' => 'stable-source', 'outcome' => 'no_change', 'evidence' => 'isolated check'];
        self::assertTrue($this->service->recordCheck($check)['changed']);
        $events = $this->service->snapshot()['events'];
        $this->now += 900;
        self::assertFalse($this->service->recordCheck($check)['changed']);
        self::assertSame($events, $this->service->snapshot()['events']);
        $this->now += 86400;
        $this->service->recordCheck($check);
        self::assertArrayHasKey('2026-10-03', $this->service->snapshot()['daily_summaries']);
    }
    public function testCorruptStorageDoesNotTurnIntoEmptySuccess(): void
    {
        $this->service->snapshot();
        file_put_contents($this->dir . '/state.json', '{corrupt');
        try { $this->service->snapshot(); self::fail('corrupt state became empty success'); }
        catch (\JsonException $e) { self::assertSame('{corrupt', file_get_contents($this->dir . '/state.json')); }
    }
    public function testOlderAdditiveStateIsReadableWithoutInventingTaskResult(): void
    {
        $task = $this->task();
        $state = json_decode(file_get_contents($this->dir . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($state['scheduler'], $state['daily_summaries'], $state['last_check']);
        file_put_contents($this->dir . '/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $snapshot = $this->service->snapshot();
        self::assertNull($snapshot['tasks'][$task['id']]['result']);
        self::assertNull($snapshot['scheduler']);
    }

    public function testRequiredPageTierCannotBeDroppedByCheckpointOrCompletion(): void
    {
        $this->task();
        [$handle, $claimed] = $this->start();
        try {
            $saved = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
                'note' => 'page requires login', 'next_step' => 'actual page after login', 'required_tiers' => ['actual_page_verified']]);
            self::assertContains('actual_page_verified', $saved['required_tiers']);
            $saved = $this->service->updateTask('isolated_owner_1', $saved['id'], $saved['revision'], [
                'note' => 'keep acceptance', 'next_step' => 'actual page', 'required_tiers' => ['local_completed']]);
            self::assertContains('actual_page_verified', $saved['required_tiers']);
            try { $this->service->updateTask('isolated_owner_1', $saved['id'], $saved['revision'], $this->acceptedResult()); self::fail(); }
            catch (\InvalidArgumentException $e) { self::assertSame('maintenance_result_tier_missing_actual_page_verified', $e->getMessage()); }
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }

    public function testNestedMaterialAndBooleanAcceptanceEvidenceAreRejected(): void
    {
        try { $this->task('nested', ['scope' => ['headers' => []]]); self::fail(); }
        catch (\InvalidArgumentException $e) { self::assertSame('maintenance_sensitive_material_rejected', $e->getMessage()); }
        $this->task(); [$handle, $claimed] = $this->start();
        try {
            $input = $this->acceptedResult();
            $input['result']['acceptance_checks']['save_readback']['evidence'] = true;
            try { $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], $input); self::fail(); }
            catch (\InvalidArgumentException $e) { self::assertSame('maintenance_acceptance_incomplete', $e->getMessage()); }
        } finally { $this->service->releaseExecutor('isolated_owner_1', $handle, true); }
    }

    public function testFailedRetryRequiresChangedLatestEvidenceAndDailyWaitingIsAccurate(): void
    {
        $this->task(); [$handle, $claimed] = $this->start();
        $failed = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
            'action' => 'failed', 'control_revision' => 1, 'result' => ['reason' => 'fixture failure', 'next_step' => 'changed source only']]);
        $this->service->releaseExecutor('isolated_owner_1', $handle, true);
        self::assertContains($failed['id'], $this->service->snapshot()['daily_summaries']['2026-10-02']['waiting_task_ids']);
        $review = ['writes_quiescent' => true, 'process_evidence' => 'all isolated calls ended',
            'checkpoint_review' => 'source checked', 'next_step' => 'verify changed source'];
        try { $this->service->recover($failed['id'], $failed['revision'], $review); self::fail(); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('new_evidence', $e->getMessage()); }
        $review['new_evidence_fingerprint'] = 'source-sha256-two';
        $recovered = $this->service->recover($failed['id'], $failed['revision'], $review);
        $handle = $this->service->acquireExecutor('isolated_owner_1');
        $claimed = $this->service->claim('isolated_owner_1');
        $failed = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
            'action' => 'failed', 'control_revision' => 1, 'result' => ['reason' => 'still failed', 'next_step' => 'need different source']]);
        $this->service->releaseExecutor('isolated_owner_1', $handle, true);
        self::assertSame('source-sha256-two', $recovered['latest_attempt_fingerprint']);
        try { $this->service->recover($failed['id'], $failed['revision'], $review); self::fail(); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('new_evidence', $e->getMessage()); }
    }

    public function testOrphanBeforeClaimHasReviewedRecoveryEntryAndLostPageState(): void
    {
        $this->service->control('resume', 0);
        $handle = $this->service->acquireExecutor('isolated_owner_1');
        flock($handle, LOCK_UN); fclose($handle);
        self::assertSame('executor_lost', $this->service->snapshot()['execution_state']);
        try { $this->service->acquireExecutor('isolated_owner_2'); self::fail(); } catch (\DomainException $e) {}
        $this->service->recoverExecutor(['execution_id' => 'isolated_owner_1', 'writes_quiescent' => true,
            'process_evidence' => 'no task or child was launched', 'checkpoint_review' => 'queue unchanged']);
        self::assertFalse($this->service->snapshot()['recovery_required']);
        self::assertSame('exception_stop', $this->service->snapshot()['control']['mode']);
    }

    public function testHistoricalFailureCannotClearAnotherUnsafeExecution(): void
    {
        $this->task(); [$handle, $claimed] = $this->start();
        $failed = $this->service->updateTask('isolated_owner_1', $claimed['id'], $claimed['revision'], [
            'action' => 'failed', 'control_revision' => 1, 'result' => ['reason' => 'old failure', 'next_step' => 'changed evidence']]);
        $this->service->releaseExecutor('isolated_owner_1', $handle, true);
        $handle = $this->service->acquireExecutor('isolated_owner_2');
        $this->service->releaseExecutor('isolated_owner_2', $handle);
        try { $this->service->recover($failed['id'], $failed['revision'], [
            'execution_id' => 'isolated_owner_2', 'writes_quiescent' => true, 'process_evidence' => 'review only current guard',
            'checkpoint_review' => 'not old task', 'next_step' => 'retry', 'new_evidence_fingerprint' => 'new-source']); self::fail(); }
        catch (\DomainException $e) { self::assertSame('maintenance_recovery_execution_mismatch', $e->getMessage()); }
        self::assertTrue($this->service->snapshot()['recovery_required']);
    }

    public function testReviewedOrphanDoesNotBlockLaterSafeTaskInterruptionRecovery(): void
    {
        $this->service->control('resume', 0);
        $handle = $this->service->acquireExecutor('isolated_owner_old');
        $this->service->releaseExecutor('isolated_owner_old', $handle);
        $review = ['execution_id' => 'isolated_owner_old', 'writes_quiescent' => true,
            'process_evidence' => 'isolated guard ended', 'checkpoint_review' => 'no task started', 'next_step' => 'continue'];
        $this->service->recoverExecutor($review);
        $this->service->control('resume', $this->service->snapshot()['control']['revision']);
        $this->task();
        $handle = $this->service->acquireExecutor('isolated_owner_new');
        $claimed = $this->service->claim('isolated_owner_new');
        $this->service->releaseExecutor('isolated_owner_new', $handle, true);
        $state = $this->service->snapshot();
        self::assertSame('isolated_owner_new', $state['last_execution_id']);
        $review['execution_id'] = 'isolated_owner_new';
        $recovered = $this->service->recover($claimed['id'], $state['tasks'][$claimed['id']]['revision'], $review);
        self::assertSame('queued', $recovered['state']);
        self::assertFalse($this->service->snapshot()['recovery_required']);
        self::assertNull($this->service->snapshot()['last_execution_id']);
    }
}
