<?php
declare(strict_types=1);

namespace app\service;

/**
 * Local development queue. It never executes commands, approves hotel actions
 * or sends messages. All state/events/summaries commit in one atomic document.
 */
final class AutonomousMaintenanceService
{
    private string $dir;
    private $clock;
    private $intentReader;
    private $sourceFingerprintReader;
    private $bootEpochReader;
    private string $sourceRoot;
    private const RESTART_FILES = ['app/service/AutonomousMaintenanceService.php', 'scripts/autonomous_maintenance.php'];
    private const DISCOVERY_VERSION = 'business-paths-v1';
    // Bounded paths are review questions, not presumed defects or operating facts.
    private const DISCOVERY_PATHS = [
        'ota_home_repair_scope' => ['area' => 'ota_source', 'title' => '首页来源补证酒店/日期跳转',
            'legacy_issue' => 'ota_missing_fact_same_scope_repair_entry', 'paths' => ['public/home-static.js', 'public/app-main.js']],
        'ota_revenue_health_navigation' => ['area' => 'ota_source', 'title' => '收益页来源异常补证的酒店/日期跳转',
            'paths' => ['public/revenue-cockpit-static.js', 'public/app-main.js']],
        'revenue_strict_metric_gate' => ['area' => 'revenue_analysis', 'title' => '可信收益逐指标门禁',
            'legacy_issue' => 'revenue_strict_gate_shared_metric_row_id',
            'paths' => ['app/service/RevenueCockpitStrictEvidenceService.php', 'app/service/DualOtaFieldClosureService.php']],
        'revenue_comparison_scope' => ['area' => 'revenue_analysis', 'title' => '收益对比日期与旧请求回显',
            'paths' => ['public/revenue-cockpit-static.js', 'app/service/RevenueDecisionViewModelAttestationService.php']],
        'operation_mixed_snapshot_restore' => ['area' => 'operation_intent', 'title' => '当前与旧快照运营建议混合恢复',
            'legacy_issue' => 'operation_restore_current_and_drifted_intent',
            'paths' => ['app/service/RevenueCockpitApprovalService.php', 'public/revenue-cockpit-static.js']],
        'operation_pending_save_readback' => ['area' => 'operation_intent', 'title' => '待确认运营建议重复保存与精确回读',
            'paths' => ['app/service/RevenueCockpitApprovalService.php', 'app/service/OperationManagementService.php']],
    ];
    private const TIERS = ['local_completed', 'automated_verified', 'actual_page_verified', 'github_saved', 'production_deployed', 'business_effect_verified'];

    public function __construct(?string $dir = null, ?callable $clock = null, ?callable $intentReader = null, ?callable $sourceFingerprintReader = null,
        ?callable $bootEpochReader = null, ?string $sourceRoot = null)
    {
        $configured = trim((string)getenv('SUXIOS_MAINTENANCE_STATE_DIR'));
        $this->dir = $dir ?? ($configured !== '' ? $configured : rtrim((string)runtime_path(), '\\/') . DIRECTORY_SEPARATOR . 'autonomous_maintenance');
        $this->clock = $clock ?? static fn(): int => time();
        $this->intentReader = $intentReader;
        $this->sourceFingerprintReader = $sourceFingerprintReader;
        $this->bootEpochReader = $bootEpochReader ?? fn(): ?int => $this->machineBootEpoch();
        $this->sourceRoot = $sourceRoot ?? dirname(__DIR__, 2);
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('maintenance_storage_unavailable');
        }
    }

    public function snapshot(): array
    {
        return $this->transaction(function (array &$state): array {
            $this->enforceDeadline($state);
            $copy = $state;
            $copy['execution_state'] = $state['executor'] === null ? ($state['recovery_required'] ? 'recovery_pending' : 'idle')
                : ($state['control']['mode'] === 'running' ? 'running' : 'stop_requested');
            $copy['executor_lock_held'] = $this->executorLocked();
            if ($state['executor'] !== null && (!$copy['executor_lock_held']
                || $this->now() - (int)($state['executor']['heartbeat_epoch'] ?? 0) > 30)) {
                $copy['execution_state'] = 'executor_lost';
            }
            $copy['observed_at'] = $this->iso();
            $copy['work_status'] = $this->workStatus($state);
            $copy['restart_recovery'] = ['enabled' => $state['restart_recovery_policy']['enabled'],
                'scope' => 'verified_machine_restart_only', 'policy_revision' => $state['restart_recovery_policy']['revision'],
                'last_review' => $state['restart_recovery_review'] ?? null];
            return $copy;
        });
    }

    /** Explicit, durable authorization. It never changes a user's stop or retries business failures. */
    public function configureRestartRecovery(string $owner, int $controlRevision, array $input): array
    {
        $this->validateMaterial($input);
        if (!is_bool($input['enabled'] ?? null)) throw new \InvalidArgumentException('restart_recovery_enabled_required');
        $reason = $this->required($input, 'authorization_reason', 2000);
        return $this->transaction(function (array &$s) use ($owner, $controlRevision, $input, $reason): array {
            $this->assertOwner($s, $owner, $controlRevision);
            $policy = ['enabled' => $input['enabled'], 'revision' => $s['restart_recovery_policy']['revision'] + 1,
                'scope' => 'verified_machine_restart_only', 'authorization_reason' => $reason, 'updated_at' => $this->iso()];
            $files = $input['enabled'] ? $this->restartFileFingerprints(self::RESTART_FILES) : [];
            if ($input['enabled'] && $files === null) throw new \DomainException('restart_recovery_source_unavailable', 409);
            $s['restart_recovery_policy'] = $policy;
            $s['executor']['restart_file_fingerprints'] = $files;
            $this->event($s, 'recovery_policy', null, $reason);
            return $policy;
        });
    }

    /** OS-observed restart review, holding the executor lock for the whole transition. No commands are replayed. */
    public function recoverRestart(int $expectedControlRevision): array
    {
        $handle = @fopen($this->dir . '/executor.lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \DomainException('maintenance_executor_busy', 409);
        }
        try {
            return $this->transaction(function (array &$s) use ($expectedControlRevision): array {
                $this->enforceDeadline($s);
                if ($expectedControlRevision !== $s['control']['revision']) {
                    return ['outcome' => 'control_preserved', 'control' => $s['control']];
                }
                if ($s['executor'] !== null) $this->markGuardLoss($s);
                $loss = $s['interruption']; $policy = $s['restart_recovery_policy'];
                $outcome = $policy['enabled'] === true && $loss !== null && $loss['prior_mode'] !== 'running'
                    ? 'control_preserved' : 'review_required';
                if ($loss !== null && ($loss['cause'] ?? null) === 'guard_loss' && $policy['enabled'] === true
                    && $loss['prior_mode'] === 'running' && $loss['policy_revision'] === $policy['revision']) {
                    $outcome = 'control_preserved';
                    if ($s['control']['mode'] === 'exception_stop' && $loss['control_revision'] === $s['control']['revision']
                        && ($s['control']['deadline_at'] === null || strtotime($s['control']['deadline_at']) > $this->now())) {
                        $boot = ($this->bootEpochReader)();
                        $outcome = 'machine_restart_not_verified';
                        if (is_int($boot) && $boot > strtotime($loss['started_at']) && $boot <= $this->now()) {
                            $outcome = 'checkpoint_mismatch';
                            $files = $loss['file_fingerprints'];
                            $tasks = array_filter($s['tasks'], static fn(array $t): bool => $t['state'] === 'interrupted');
                            $valid = is_array($files) && array_diff(self::RESTART_FILES, array_keys($files)) === [];
                            foreach ($tasks as $task) {
                                $checkpoint = $task['checkpoint'] ?? [];
                                if ($task['execution_id'] !== $loss['execution_id'] || empty($checkpoint['next_step'])
                                    || empty($checkpoint['file_fingerprints']) || !is_array($checkpoint['file_fingerprints'])) { $valid = false; break; }
                                foreach ($checkpoint['file_fingerprints'] as $path => $hash) {
                                    if (isset($files[$path]) && $files[$path] !== $hash) { $valid = false; break 2; }
                                    $files[$path] = $hash;
                                }
                            }
                            $current = $valid ? $this->restartFileFingerprints(array_keys($files)) : null;
                            if ($current !== null && $this->canonical($current) === $this->canonical($files)) {
                                $review = ['execution_id' => $loss['execution_id'], 'writes_quiescent' => true,
                                    'process_evidence' => ['machine_boot_at' => $this->epochIso($boot), 'old_guard_started_at' => $loss['started_at'],
                                        'previous_boot_processes_exited' => true, 'executor_lock_exclusively_held' => true],
                                    'checkpoint_review' => ['file_fingerprints' => $current, 'task_revisions' => array_map(static fn(array $t): int => $t['revision'], $tasks)],
                                    'reviewed_control_revision' => $s['control']['revision'], 'observed_at' => $this->iso()];
                                foreach ($tasks as $id => $task) {
                                    $task['recovery_review'] = $review + ['next_step' => $task['checkpoint']['next_step']];
                                    $task['state'] = 'queued'; $task['execution_id'] = null; $task['blocked_reason'] = null;
                                    $task['revision']++; $task['updated_at'] = $this->iso(); $s['tasks'][$id] = $task;
                                }
                                $s['executor'] = null; $s['last_execution_id'] = null; $s['recovery_required'] = false;
                                $s['control']['mode'] = 'running'; $s['control']['revision']++;
                                $s['control']['reason'] = 'authorized_machine_restart_review_passed'; $s['control']['updated_at'] = $this->iso();
                                $s['interruption'] = null;
                                $s['restart_recovery_review'] = $review + ['outcome' => 'resumed', 'resumed_control_revision' => $s['control']['revision']];
                                $this->event($s, 'restart_recovered', null, 'machine_restart_verified_checkpoint_preserved_continue_next_step');
                                return ['outcome' => 'resumed', 'control' => $s['control'], 'review' => $s['restart_recovery_review']];
                            }
                        }
                    }
                }
                return ['outcome' => $outcome, 'control' => $s['control']];
            });
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    public function control(string $action, ?int $expectedRevision, ?string $deadline = null, string $reason = ''): array
    {
        return $this->transaction(function (array &$s) use ($action, $expectedRevision, $deadline, $reason): array {
            if ($expectedRevision !== null && $expectedRevision !== $s['control']['revision']) {
                throw new \DomainException('maintenance_control_changed', 409);
            }
            if (!in_array($action, ['pause', 'resume', 'stop', 'deadline', 'exception_stop'], true)) {
                throw new \InvalidArgumentException('maintenance_control_action_invalid');
            }
            if ($action === 'resume' && $s['recovery_required']) {
                throw new \DomainException('maintenance_recovery_review_required', 409);
            }
            if ($action === 'deadline') {
                if ($deadline !== null) {
                    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $deadline);
                    if (!$parsed || $parsed->format('Y-m-d\TH:i:sP') !== $deadline) {
                        throw new \InvalidArgumentException('deadline_requires_absolute_time_with_timezone');
                    }
                    $deadline = $parsed->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d\TH:i:sP');
                }
                $s['control']['deadline_at'] = $deadline;
            } else {
                $s['control']['mode'] = ['pause' => 'paused', 'resume' => 'running', 'stop' => 'stopped',
                    'exception_stop' => 'exception_stop'][$action];
            }
            $s['control']['revision']++;
            $s['control']['reason'] = $this->text($reason ?: $action, 2000);
            $s['control']['updated_at'] = $this->iso();
            $this->enforceDeadline($s);
            $this->event($s, 'control', null, $s['control']['reason']);
            return $s['control'];
        });
    }

    public function enqueue(array $input, ?string $owner = null, ?int $controlRevision = null): array
    {
        $this->validateMaterial($input);
        return $this->transaction(function (array &$s) use ($input, $owner, $controlRevision): array {
            if ($owner !== null) $this->assertOwner($s, $owner, $controlRevision);
            $kind = (string)($input['kind'] ?? 'development');
            if (!in_array($kind, ['development', 'operation'], true)) {
                throw new \InvalidArgumentException('maintenance_task_kind_invalid');
            }
            $scope = (array)($input['scope'] ?? []);
            if ($kind === 'operation') {
                if ((int)($scope['tenant_id'] ?? 0) <= 0 || (int)($scope['hotel_id'] ?? 0) <= 0
                    || !in_array($scope['platform'] ?? '', ['ctrip', 'meituan'], true)
                    || !$this->validDate((string)($scope['business_date'] ?? ''))
                    || ($scope['quality'] ?? '') !== 'readback_verified'
                    || ($scope['source_kind'] ?? '') !== 'real'
                    || empty($scope['source_receipts'])) {
                    throw new \InvalidArgumentException('operation_requires_same_scope_verified_real_evidence');
                }
                $intentId = (int)($input['operation_intent_id'] ?? 0);
                if ($intentId <= 0) throw new \InvalidArgumentException('operation_requires_persisted_pending_intent_reference');
                $intent = $this->intentReader !== null ? ($this->intentReader)($intentId, $scope)
                    : (new RevenueCockpitApprovalService())->assertIntentCurrent(
                        (new OperationManagementService())->readExecutionIntent($intentId, [(int)$scope['hotel_id']]));
                if (!is_array($intent) || (int)($intent['id'] ?? 0) !== $intentId
                    || (int)($intent['tenant_id'] ?? 0) !== (int)$scope['tenant_id']
                    || (int)($intent['hotel_id'] ?? 0) !== (int)$scope['hotel_id']
                    || ($intent['platform'] ?? null) !== $scope['platform']
                    || substr((string)($intent['date_start'] ?? ''), 0, 10) !== $scope['business_date']
                    || substr((string)($intent['date_end'] ?? ''), 0, 10) !== $scope['business_date']
                    || ($intent['status'] ?? '') !== 'pending_approval'
                    || ($intent['fact_integrity_status'] ?? '') !== 'verified'
                    || !isset($intent['tasks']) || $intent['tasks'] !== []) {
                    throw new \InvalidArgumentException('operation_pending_intent_scope_or_provenance_invalid');
                }
                $scope['source_receipts'] = ['operation_intent:' . $intentId];
                $input['scope'] = $scope;
                $input['issue_key'] = 'pending_operation_intent:' . $intentId;
                $input['evidence_fingerprint'] = hash('sha256', $this->encode([$intentId, $scope,
                    $intent['evidence'] ?? $intent['updated_at'] ?? null]));
                $input['suggested_action'] = '人工核对运营任务 #' . $intentId . ' 已保存的行动卡、触发依据和验收条件';
            }
            $issue = $this->required($input, 'issue_key', 300);
            $fingerprint = $this->required($input, 'evidence_fingerprint', 200);
            $identity = hash('sha256', $this->encode([$kind, $issue, $this->canonical($scope), $fingerprint]));
            foreach ($s['tasks'] as $task) {
                if ($task['identity'] === $identity) {
                    return ['task' => $task, 'duplicate' => true];
                }
            }
            $acceptance = array_values(array_unique(array_map(
                fn($v): string => $this->text((string)$v, 1500), (array)($input['acceptance'] ?? [])
            )));
            $evidence = array_values(array_map(fn($v): string => $this->text((string)$v, 4000), (array)($input['evidence'] ?? [])));
            if ($acceptance === [] || in_array('', $acceptance, true) || $evidence === []) {
                throw new \InvalidArgumentException('task_requires_reproduction_evidence_and_acceptance');
            }
            $priority = (string)($input['priority'] ?? 'P2');
            $requiredTiers = $this->requiredTiers((array)($input['required_tiers'] ?? ['local_completed', 'automated_verified']));
            if (!in_array($priority, ['P0', 'P1', 'P2', 'P3'], true)) {
                throw new \InvalidArgumentException('maintenance_priority_invalid');
            }
            $task = [
                'id' => 'mt_' . substr($identity, 0, 24), 'identity' => $identity, 'revision' => 1,
                'kind' => $kind, 'title' => $this->required($input, 'title', 500),
                'issue_key' => $issue, 'evidence_fingerprint' => $fingerprint, 'scope' => $scope,
                'priority' => $priority, 'evidence' => $evidence, 'acceptance' => $acceptance,
                'required_tiers' => $requiredTiers,
                'suggested_action' => $this->text((string)($input['suggested_action'] ?? ''), 4000),
                'state' => $kind === 'operation' ? 'pending_confirmation' : 'queued',
                'execution_id' => null, 'attempts' => 0, 'checkpoint' => null, 'result' => null,
                'blocked_reason' => null, 'created_at' => $this->iso(), 'updated_at' => $this->iso(),
            ];
            $s['tasks'][$task['id']] = $task;
            $this->event($s, 'created', $task['id'], $task['title']);
            return ['task' => $task, 'duplicate' => false];
        });
    }

    /** The caller keeps this OS lock open for the entire execution. */
    public function acquireExecutor(string $owner)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $owner)) {
            throw new \InvalidArgumentException('execution_id_invalid');
        }
        $handle = @fopen($this->dir . '/executor.lock', 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new \DomainException('maintenance_executor_busy', 409);
        }
        try {
            $outcome = $this->transaction(function (array &$s) use ($owner): string {
                $this->enforceDeadline($s);
                if ($s['executor'] !== null) {
                    $this->markGuardLoss($s);
                    return 'orphan';
                }
                if ($s['control']['mode'] !== 'running') return 'paused';
                $s['executor'] = ['execution_id' => $owner, 'pid' => getmypid(),
                    'started_at' => $this->iso(), 'heartbeat_at' => $this->iso(),
                    'heartbeat_epoch' => $this->now(), 'release_requested' => false];
                if ($s['restart_recovery_policy']['enabled']) {
                    $s['executor']['restart_file_fingerprints'] = $this->restartFileFingerprints(self::RESTART_FILES);
                }
                return 'acquired';
            });
            if ($outcome !== 'acquired') {
                throw new \DomainException('maintenance_' . $outcome, 409);
            }
            return $handle;
        } catch (\Throwable $e) {
            flock($handle, LOCK_UN); fclose($handle); throw $e;
        }
    }

    public function heartbeat(string $owner): bool
    {
        return $this->transaction(function (array &$s) use ($owner): bool {
            $this->enforceDeadline($s);
            // A stop request fences actions but retains the physical lock until
            // the executor acknowledges that all writes have become quiescent.
            if (($s['executor']['execution_id'] ?? null) !== $owner
                || !empty($s['executor']['release_requested'])) return false;
            $s['executor']['heartbeat_epoch'] = $this->now();
            $s['executor']['heartbeat_at'] = $this->iso();
            return true;
        });
    }

    public function releaseRequested(string $owner, array $review): void
    {
        $this->requireQuiescence($review);
        $this->transaction(function (array &$s) use ($owner, $review): void {
            if (($s['executor']['execution_id'] ?? null) !== $owner) {
                throw new \DomainException('maintenance_execution_mismatch', 409);
            }
            $s['executor']['release_requested'] = true;
            $s['executor']['release_review'] = $review;
        });
    }

    public function releaseExecutor(string $owner, $handle, bool $knownQuiescent = false): void
    {
        try {
            $this->transaction(function (array &$s) use ($owner, $knownQuiescent): void {
                if (($s['executor']['execution_id'] ?? null) !== $owner) return;
                $this->enforceDeadline($s);
                $s['last_execution_id'] = $owner;
                $safe = $knownQuiescent || !empty($s['executor']['release_review']['writes_quiescent']);
                if (!$safe) {
                    $s['recovery_required'] = true;
                    if ($s['control']['mode'] === 'running') {
                        $s['control']['mode'] = 'exception_stop'; $s['control']['revision']++;
                        $s['control']['reason'] = 'executor_exit_requires_process_and_checkpoint_review';
                    }
                }
                foreach ($s['tasks'] as &$task) {
                    if ($task['state'] === 'running' && $task['execution_id'] === $owner) {
                        $task['state'] = 'interrupted';
                        $task['revision']++;
                        $task['blocked_reason'] = 'execution_ended_at_safe_checkpoint_review_before_reclaim';
                        $s['recovery_required'] = true;
                        $this->event($s, 'interrupted', $task['id'], $task['blocked_reason']);
                    }
                }
                unset($task);
                $s['executor'] = null;
            });
        } finally {
            if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
        }
    }

    public function assertExecution(string $owner, ?int $controlRevision = null): array
    {
        return $this->transaction(function (array &$s) use ($owner, $controlRevision): array {
            $this->assertOwner($s, $owner, $controlRevision);
            return ['execution_id' => $owner, 'control_revision' => $s['control']['revision'], 'allowed' => true];
        });
    }

    public function claim(string $owner, ?string $id = null): ?array
    {
        return $this->transaction(function (array &$s) use ($owner, $id): ?array {
            $this->assertOwner($s, $owner);
            foreach ($s['tasks'] as $task) {
                if ($task['state'] === 'running') throw new \DomainException('maintenance_task_already_running', 409);
            }
            $tasks = array_values($s['tasks']);
            usort($tasks, static fn(array $a, array $b): int => ($a['priority'] <=> $b['priority'])
                ?: ($a['created_at'] <=> $b['created_at']) ?: ($a['id'] <=> $b['id']));
            foreach ($tasks as $task) {
                if ($task['kind'] !== 'development' || $task['state'] !== 'queued' || ($id !== null && $task['id'] !== $id)) continue;
                $task['state'] = 'running'; $task['execution_id'] = $owner;
                $task['attempts']++; $task['revision']++; $task['updated_at'] = $this->iso();
                $s['tasks'][$task['id']] = $task;
                $this->event($s, 'started', $task['id'], $task['title']);
                return $task;
            }
            return null;
        });
    }

    public function updateTask(string $owner, string $id, int $revision, array $input): array
    {
        $this->validateMaterial($input);
        return $this->transaction(function (array &$s) use ($owner, $id, $revision, $input): array {
            $action = (string)($input['action'] ?? 'checkpoint');
            if ($action === 'checkpoint') {
                if (($s['executor']['execution_id'] ?? null) !== $owner || !$this->executorLocked()
                    || !empty($s['executor']['release_requested'])) {
                    throw new \DomainException('maintenance_execution_not_allowed', 409);
                }
            } else {
                if (!isset($input['control_revision'])) throw new \InvalidArgumentException('control_revision_required');
                $this->assertOwner($s, $owner, (int)$input['control_revision']);
            }
            $task = $s['tasks'][$id] ?? null;
            if (!$task || $task['revision'] !== $revision || $task['execution_id'] !== $owner || $task['state'] !== 'running') {
                throw new \DomainException('maintenance_task_changed', 409);
            }
            if ($action === 'checkpoint') {
                $task['checkpoint'] = ['saved_at' => $this->iso(),
                    'required_tiers' => $this->requiredTiers((array)($input['required_tiers'] ?? [] ?: $task['required_tiers'] ?? ['local_completed', 'automated_verified'])),
                    'note' => $this->required($input, 'note', 4000),
                    'next_step' => $this->required($input, 'next_step', 4000),
                    'file_fingerprints' => (array)($input['file_fingerprints'] ?? []),
                    'processes' => (array)($input['processes'] ?? [])];
                $task['required_tiers'] = array_values(array_unique(array_merge(
                    $task['required_tiers'] ?? ['local_completed', 'automated_verified'], $task['checkpoint']['required_tiers'])));
            } elseif (in_array($action, ['completed', 'failed', 'waiting_user'], true)) {
                $result = (array)($input['result'] ?? []);
                if ($action === 'completed') {
                    foreach ($task['acceptance'] as $check) {
                        $receipt = $result['acceptance_checks'][$check] ?? null;
                        if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'passed'
                            || !is_string($receipt['evidence'] ?? null) || trim($receipt['evidence']) === '') {
                            throw new \InvalidArgumentException('maintenance_acceptance_incomplete');
                        }
                    }
                    foreach ($task['required_tiers'] ?? ['local_completed', 'automated_verified'] as $tier) {
                        if (($result['tiers'][$tier] ?? false) !== true) {
                            throw new \InvalidArgumentException('maintenance_result_tier_missing_' . $tier);
                        }
                    }
                } elseif (empty($result['reason']) || empty($result['next_step'])) {
                    throw new \InvalidArgumentException('maintenance_blocker_requires_reason_and_recovery');
                }
                $task['state'] = $action; $task['result'] = $result;
                $task['blocked_reason'] = $action === 'completed' ? null : $result['reason'];
                $this->event($s, $action, $id, (string)($result['summary'] ?? $task['blocked_reason'] ?? $task['title']));
            } else throw new \InvalidArgumentException('maintenance_task_transition_invalid');
            $task['updated_at'] = $this->iso(); $task['revision']++;
            $s['tasks'][$id] = $task;
            $this->daily($s);
            return $task;
        });
    }

    public function recoverExecutor(array $review): void
    {
        $this->requireQuiescence($review);
        if ($this->executorLocked()) throw new \DomainException('maintenance_executor_busy', 409);
        $this->transaction(function (array &$s) use ($review): void {
            if ($this->executorLocked()) throw new \DomainException('maintenance_executor_busy', 409);
            $owner = $s['executor']['execution_id'] ?? $s['last_execution_id'] ?? null;
            if (!$s['recovery_required'] || $owner === null || ($review['execution_id'] ?? null) !== $owner
                || count(array_filter($s['tasks'], static fn(array $t): bool => $t['state'] === 'interrupted')) > 0) {
                throw new \DomainException('maintenance_recovery_execution_mismatch', 409);
            }
            $s['executor'] = null;
            $s['recovery_required'] = false;
            $s['last_execution_id'] = null;
            $s['executor_recovery_review'] = $review;
            $this->event($s, 'recovered', null, 'orphan_executor_reviewed_user_control_still_respected');
        });
    }

    /** Recovery is a reviewed continuation, never an automatic repeated write. */
    public function recover(string $id, int $revision, array $review): array
    {
        $this->validateMaterial($review);
        if ($this->executorLocked()) throw new \DomainException('maintenance_executor_busy', 409);
        return $this->transaction(function (array &$s) use ($id, $revision, $review): array {
            if ($this->executorLocked()) throw new \DomainException('maintenance_executor_busy', 409);
            $task = $s['tasks'][$id] ?? null;
            if (!$task || $task['revision'] !== $revision || !in_array($task['state'], ['interrupted', 'failed', 'waiting_user'], true)) {
                throw new \DomainException('maintenance_recovery_state_invalid', 409);
            }
            $this->requireQuiescence($review);
            $lostOwner = $s['executor']['execution_id'] ?? ($s['recovery_required'] ? ($s['last_execution_id'] ?? null) : null);
            if ($lostOwner !== null && ($task['execution_id'] !== $lostOwner
                || ($review['execution_id'] ?? null) !== $lostOwner)) {
                throw new \DomainException('maintenance_recovery_execution_mismatch', 409);
            }
            if ($s['recovery_required'] && $task['state'] !== 'interrupted') {
                throw new \DomainException('maintenance_executor_review_required_before_task_retry', 409);
            }
            $newFingerprint = trim((string)($review['new_evidence_fingerprint'] ?? ''));
            if (empty($review['next_step']) || ($task['state'] !== 'interrupted'
                && ($newFingerprint === '' || $newFingerprint === ($task['latest_attempt_fingerprint'] ?? $task['evidence_fingerprint'])))) {
                throw new \InvalidArgumentException('maintenance_recovery_requires_new_evidence_and_quiescent_writes');
            }
            $task['recovery_review'] = $review; $task['state'] = 'queued';
            if ($newFingerprint !== '') $task['latest_attempt_fingerprint'] = $newFingerprint;
            $task['execution_id'] = null; $task['blocked_reason'] = null; $task['revision']++;
            $task['updated_at'] = $this->iso();
            $s['tasks'][$id] = $task;
            if ($lostOwner !== null) $s['executor'] = null;
            $s['recovery_required'] = count(array_filter($s['tasks'], static fn(array $t): bool => $t['state'] === 'interrupted')) > 0;
            if (!$s['recovery_required']) $s['last_execution_id'] = null;
            $this->event($s, 'recovered', $id, (string)$review['next_step']);
            return $task;
        });
    }

    public function recordCheck(array $check): array
    {
        $this->validateMaterial($check);
        return $this->transaction(function (array &$s) use ($check): array {
            $fingerprint = $this->required($check, 'fingerprint', 200);
            $outcome = $this->required($check, 'outcome', 100);
            $inputFingerprint = isset($check['input_fingerprint'])
                ? $this->required($check, 'input_fingerprint', 200) : ($s['last_check']['input_fingerprint'] ?? null);
            $changed = ($s['last_check']['fingerprint'] ?? null) !== $fingerprint
                || ($s['last_check']['outcome'] ?? null) !== $outcome
                || ($s['last_check']['input_fingerprint'] ?? null) !== $inputFingerprint;
            $s['last_check'] = ['checked_at' => $this->iso(), 'fingerprint' => $fingerprint,
                'outcome' => $outcome, 'evidence' => $this->required($check, 'evidence', 4000), 'changed' => $changed];
            // Explicit metadata signal only; the selector never reads OTA payloads or credentials.
            $s['last_check']['input_fingerprint'] = $inputFingerprint;
            if ($changed) $this->event($s, 'check_changed', null, $s['last_check']['evidence']);
            $this->daily($s);
            return $s['last_check'];
        });
    }

    /** A candidate is a bounded review target, never an invented operational fact. */
    public function candidate(string $owner, int $revision, array $input): array
    {
        $this->validateMaterial($input);
        return $this->transaction(function (array &$s) use ($owner, $revision, $input): array {
            $this->assertOwner($s, $owner, $revision);
            $issue = $this->required($input, 'issue_key', 300);
            $scope = (array)($input['scope'] ?? []);
            $id = 'mc_' . substr(hash('sha256', $this->encode([$issue, $this->canonical($scope)])), 0, 24);
            $fingerprint = $this->required($input, 'evidence_fingerprint', 200);
            $state = $this->required($input, 'state', 100);
            if (!in_array($state, ['needs_review', 'ready', 'blocked', 'reviewed_no_issue'], true)) {
                throw new \InvalidArgumentException('maintenance_candidate_state_invalid');
            }
            $priority = $input['priority'] ?? 'P2';
            if (!in_array($priority, ['P0', 'P1', 'P2', 'P3'], true)) throw new \InvalidArgumentException('maintenance_priority_invalid');
            $paths = array_values(array_filter(array_map(fn($p): string => $this->text((string)$p, 500), (array)($input['target_paths'] ?? []))));
            $evidence = array_values(array_filter(array_map(fn($p): string => $this->text((string)$p, 4000), (array)($input['evidence'] ?? []))));
            if ($paths === [] || $evidence === []) throw new \InvalidArgumentException('maintenance_candidate_requires_bounded_evidence');
            $discoveryPath = $this->candidateDiscoveryPath($issue, $scope);
            if ($discoveryPath !== null && array_diff(self::DISCOVERY_PATHS[$discoveryPath]['paths'], $paths) !== []) {
                throw new \InvalidArgumentException('maintenance_candidate_discovery_paths_mismatch');
            }
            $taskInput = null;
            if ($state === 'ready') {
                $taskInput = (array)($input['task_input'] ?? []);
                if (($taskInput['kind'] ?? 'development') !== 'development' || empty($taskInput['acceptance'])
                    || empty($input['reproduction'])) throw new \InvalidArgumentException('maintenance_candidate_requires_development_reproduction');
                $taskInput = array_replace($taskInput, ['kind' => 'development', 'issue_key' => $issue,
                    'evidence_fingerprint' => $fingerprint, 'scope' => $scope, 'priority' => $priority, 'evidence' => $evidence]);
            }
            $entry = ['id' => $id, 'issue_key' => $issue, 'title' => $this->required($input, 'title', 500),
                'scope' => $scope, 'priority' => $priority, 'state' => $state, 'evidence_fingerprint' => $fingerprint,
                'target_paths' => $paths, 'evidence' => $evidence, 'next_step' => $this->required($input, 'next_step', 2000),
                'reproduction' => $this->text((string)($input['reproduction'] ?? ''), 4000), 'task_input' => $taskInput];
            if ($discoveryPath !== null) {
                $entry['discovery_path'] = $discoveryPath;
                $entry['discovery_catalog_version'] = self::DISCOVERY_VERSION;
                $entry['discovery_source_fingerprint'] = $this->discoverySourceFingerprint(self::DISCOVERY_PATHS[$discoveryPath]['paths']);
                $entry['discovery_input_fingerprint'] = $s['last_check']['input_fingerprint'] ?? null;
            }
            $old = $s['candidates'][$id] ?? null;
            // An unchanged ready candidate that already ran must not be reopened.
            if ($old && $old['evidence_fingerprint'] === $fingerprint && $old['state'] === 'linked' && $state === 'ready') {
                // One-time adoption of independently verified stable legacy evidence, retaining task identity.
                if (!isset($old['discovery_source_fingerprint']) && !empty($input['reuse_verified_baseline'])
                    && ($s['tasks'][$old['task_id'] ?? '']['state'] ?? null) === 'completed') {
                    foreach (['discovery_path', 'discovery_catalog_version', 'discovery_source_fingerprint', 'discovery_input_fingerprint'] as $field) {
                        if (array_key_exists($field, $entry)) $old[$field] = $entry[$field];
                    }
                    $s['candidates'][$id] = $old;
                }
                return $old;
            }
            if ($old && array_intersect_key($old, $entry) === $entry) return $old;
            $entry['reviewed_at'] = $this->iso();
            $s['candidates'][$id] = $entry;
            $this->event($s, 'candidate_review', null, $entry['title'] . '：' . $state);
            return $entry;
        });
    }

    /** Select one task/review. The caller still performs reproduction, claim and execution. */
    public function next(string $owner, int $revision): array
    {
        $plan = $this->transaction(function (array &$s) use ($owner, $revision): array {
            $this->assertOwner($s, $owner, $revision);
            return $this->nextAction($s);
        });
        if ($plan['action'] !== 'enqueue_candidate') return $plan;
        $entry = $plan['candidate'];
        $created = $this->enqueue($entry['task_input'], $owner, $revision);
        return $this->transaction(function (array &$s) use ($owner, $revision, $entry, $created): array {
            $this->assertOwner($s, $owner, $revision);
            $s['candidates'][$entry['id']]['state'] = 'linked';
            $s['candidates'][$entry['id']]['task_id'] = $created['task']['id'];
            return ['action' => $created['task']['state'] === 'queued' ? 'execute_task' : 'review_existing_task',
                'task_id' => $created['task']['id'], 'next_step' => $entry['next_step'], 'duplicate' => $created['duplicate']];
        });
    }

    /** Delayed concurrency receipts retain their observed time, not the registration time. */
    public function recordRun(string $owner, int $revision, array $input): array
    {
        $this->validateMaterial($input);
        return $this->transaction(function (array &$s) use ($owner, $revision, $input): array {
            $this->assertOwner($s, $owner, $revision);
            $id = $this->required($input, 'run_id', 100);
            $trigger = $this->required($input, 'trigger', 100);
            $outcome = $this->required($input, 'outcome', 100);
            if (!in_array($trigger, ['scheduled', 'manual'], true)
                || !in_array($outcome, ['checking', 'executing', 'completed', 'waiting_concurrency', 'blocked', 'no_action'], true)) {
                throw new \InvalidArgumentException('maintenance_run_state_invalid');
            }
            foreach (['triggered_at', 'observed_at'] as $field) {
                $value = $this->required($input, $field, 100);
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
                if (!$date || $date->format('Y-m-d\TH:i:sP') !== $value || $date->getTimestamp() > $this->now() + 60) {
                    throw new \InvalidArgumentException('maintenance_run_time_invalid');
                }
            }
            if (strtotime($input['observed_at']) < strtotime($input['triggered_at'])) throw new \InvalidArgumentException('maintenance_run_time_invalid');
            $taskId = $input['selected_task_id'] ?? null;
            if ($taskId !== null && !isset($s['tasks'][$taskId])) throw new \InvalidArgumentException('maintenance_run_task_unknown');
            if (in_array($outcome, ['executing', 'completed'], true)
                && ($taskId === null || $s['tasks'][$taskId]['state'] !== ($outcome === 'executing' ? 'running' : 'completed')
                    || $s['tasks'][$taskId]['execution_id'] !== $owner)) {
                throw new \InvalidArgumentException('maintenance_run_requires_matching_executed_task');
            }
            if ($outcome === 'no_action' && $this->nextAction($s)['action'] !== 'no_action') {
                throw new \InvalidArgumentException('maintenance_run_has_unfinished_task_or_discovery');
            }
            if ($outcome === 'waiting_concurrency' && empty($input['resume_condition'])) throw new \InvalidArgumentException('maintenance_run_resume_condition_required');
            $entry = ['run_id' => $id, 'trigger' => $trigger, 'triggered_at' => $input['triggered_at'],
                'observed_at' => $input['observed_at'], 'outcome' => $outcome, 'selected_task_id' => $taskId,
                'evidence' => $this->required($input, 'evidence', 4000), 'next_step' => $this->required($input, 'next_step', 2000),
                'resume_condition' => $this->text((string)($input['resume_condition'] ?? ''), 2000)];
            if (isset($s['runs'][$id]) && array_intersect_key($s['runs'][$id], $entry) === $entry) return $s['runs'][$id];
            $entry['registered_at'] = $this->iso();
            $entry['delayed_registration'] = $this->now() - strtotime($entry['observed_at']) > 60;
            $s['runs'][$id] = $entry;
            uasort($s['runs'], static fn(array $a, array $b): int => strtotime($a['triggered_at']) <=> strtotime($b['triggered_at']));
            $s['runs'] = array_slice($s['runs'], -200, null, true);
            $this->daily($s);
            return $entry;
        });
    }

    private function nextAction(array $s): array
    {
        if ($s['control']['mode'] !== 'running' || $s['recovery_required']) return ['action' => 'control_blocked', 'next_step' =>
            $s['restart_recovery_policy']['enabled'] ? '仅已授权且程序核实的本机重启可复核续跑；主动暂停/停止、到期、检查点不匹配保持阻断' : '先复核控制状态及中断检查点；未授权自动恢复'];
        $tasks = array_values(array_filter($s['tasks'], static fn(array $t): bool => $t['kind'] === 'development' && in_array($t['state'], ['running', 'queued'], true)));
        usort($tasks, static fn(array $a, array $b): int => [$a['state'] === 'running' ? 0 : 1, $a['priority'], $a['created_at']] <=> [$b['state'] === 'running' ? 0 : 1, $b['priority'], $b['created_at']]);
        if ($tasks !== []) return ['action' => $tasks[0]['state'] === 'running' ? 'continue_task' : 'execute_task', 'task_id' => $tasks[0]['id'],
            'next_step' => $tasks[0]['checkpoint']['next_step'] ?? $tasks[0]['suggested_action']];
        $entries = array_values(array_filter($s['candidates'], static fn(array $c): bool => in_array($c['state'], ['ready', 'needs_review'], true)));
        usort($entries, static fn(array $a, array $b): int => [$a['state'] === 'ready' ? 0 : 1, $a['priority'], $a['id']] <=> [$b['state'] === 'ready' ? 0 : 1, $b['priority'], $b['id']]);
        if ($entries !== []) return ['action' => $entries[0]['state'] === 'ready' ? 'enqueue_candidate' : 'review_candidate', 'candidate' => $entries[0], 'next_step' => $entries[0]['next_step']];
        $pending = []; $reviewed = 0;
        foreach (self::DISCOVERY_PATHS as $id => $path) {
            $source = $this->discoverySourceFingerprint($path['paths']);
            $covered = array_filter($s['candidates'], static fn(array $c): bool => ($c['discovery_path'] ?? null) === $id
                && ($c['discovery_catalog_version'] ?? null) === self::DISCOVERY_VERSION
                && ($c['discovery_source_fingerprint'] ?? null) === $source
                && ($c['discovery_input_fingerprint'] ?? null) === ($s['last_check']['input_fingerprint'] ?? null)
                && in_array($c['state'], ['linked', 'blocked', 'reviewed_no_issue'], true));
            if ($covered) { $reviewed++; continue; }
            $pending[] = ['action' => 'discover', 'discovery_area' => $path['area'], 'discovery_path' => $id,
                'target_paths' => $path['paths'], 'source_fingerprint' => $source,
                'input_fingerprint' => $s['last_check']['input_fingerprint'] ?? null,
                'next_step' => '限定复核：' . $path['title'] . '；保存当前源码/输入指纹及复现、无问题或具体阻塞依据'];
        }
        $coverage = ['catalog_version' => self::DISCOVERY_VERSION, 'reviewed_paths' => $reviewed, 'total_paths' => count(self::DISCOVERY_PATHS)];
        return array_replace($pending[0] ?? ['action' => 'no_action',
            'next_step' => '当前限定路径目录已复核耗尽；仅覆盖已登记路径，源码/同范围输入变化或用户新增目标时继续，不能推断全产品无缺陷'], ['discovery_coverage' => $coverage]);
    }

    private function candidateDiscoveryPath(string $issue, array $scope): ?string
    {
        $explicit = $scope['discovery_path'] ?? null;
        foreach (self::DISCOVERY_PATHS as $id => $path) {
            if (($explicit === $id || ($explicit === null && ($path['legacy_issue'] ?? null) === $issue))
                && ($scope['discovery_area'] ?? null) === $path['area']) return $id;
        }
        if ($explicit !== null) throw new \InvalidArgumentException('maintenance_candidate_discovery_scope_invalid');
        return null;
    }

    private function discoverySourceFingerprint(array $paths): string
    {
        if ($this->sourceFingerprintReader !== null) return (string)($this->sourceFingerprintReader)($paths);
        $hashes = [];
        foreach ($paths as $path) {
            $file = dirname(__DIR__, 2) . '/' . $path;
            $hashes[$path] = is_file($file) ? (hash_file('sha256', $file) ?: 'unreadable') : 'missing';
        }
        return hash('sha256', $this->encode($hashes));
    }

    private function workStatus(array $s): array
    {
        $next = $this->nextAction($s);
        $last = $s['runs'] === [] ? null : end($s['runs']);
        $counts = ['queued' => 0, 'running' => 0, 'ready' => 0, 'needs_review' => 0, 'blocked' => 0];
        foreach ($s['tasks'] as $task) if ($task['kind'] === 'development' && isset($counts[$task['state']])) $counts[$task['state']]++;
        foreach ($s['candidates'] as $candidate) if (isset($counts[$candidate['state']])) $counts[$candidate['state']]++;
        $state = match ($next['action']) { 'continue_task' => 'executing', 'execute_task', 'enqueue_candidate' => 'pending_execution',
            'review_candidate', 'discover' => 'discovery_pending', 'control_blocked' => 'control_blocked', default => 'reviewed_no_action' };
        if ($s['control']['mode'] === 'running' && $counts['running'] === 0 && $last && $last['outcome'] === 'waiting_concurrency') $state = 'waiting_concurrency';
        $lastAt = $last['observed_at'] ?? $s['last_check']['checked_at'] ?? null;
        $interval = max(1, (int)($s['scheduler']['interval_minutes'] ?? 15));
        return ['state' => $state, 'next_action' => $next['action'], 'next_step' => $next['next_step'], 'counts' => $counts,
            'latest_run' => $last, 'check_overdue' => $s['control']['mode'] === 'running' && ($lastAt === null || $this->now() - strtotime($lastAt) > $interval * 120),
            'overdue_after_minutes' => $interval * 2, 'discovery_coverage' => $next['discovery_coverage'] ?? null,
            'discovery_path' => $next['discovery_path'] ?? null, 'target_paths' => $next['target_paths'] ?? []];
    }

    public function scheduler(array $config): array
    {
        $this->validateMaterial($config);
        return $this->transaction(function (array &$s) use ($config): array {
            $s['scheduler'] = [
                'id' => $this->required($config, 'id', 100),
                'interval_minutes' => (int)($config['interval_minutes'] ?? 15),
                'status' => $this->required($config, 'status', 100),
                'thread_id' => $this->required($config, 'thread_id', 100),
                'config_verified_at' => $this->iso(),
                'evidence' => $this->required($config, 'evidence', 1500),
            ];
            return $s['scheduler'];
        });
    }

    private function assertOwner(array &$s, string $owner, ?int $revision = null): void
    {
        $this->enforceDeadline($s);
        if ($s['control']['mode'] !== 'running' || ($revision !== null && $revision !== $s['control']['revision'])
            || ($s['executor']['execution_id'] ?? null) !== $owner
            || !empty($s['executor']['release_requested'])
            || $this->now() - (int)($s['executor']['heartbeat_epoch'] ?? 0) > 30 || !$this->executorLocked()) {
            throw new \DomainException('maintenance_execution_not_allowed', 409);
        }
    }

    private function executorLocked(): bool
    {
        $h = @fopen($this->dir . '/executor.lock', 'c+');
        if (!$h) throw new \RuntimeException('maintenance_lock_unavailable');
        $free = flock($h, LOCK_EX | LOCK_NB);
        if ($free) flock($h, LOCK_UN);
        fclose($h);
        return !$free;
    }

    private function enforceDeadline(array &$s): void
    {
        $deadline = $s['control']['deadline_at'];
        if ($deadline !== null && strtotime($deadline) <= $this->now() && $s['control']['mode'] === 'running') {
            $s['control']['mode'] = 'stopped'; $s['control']['revision']++;
            $s['control']['reason'] = 'deadline_reached'; $s['control']['updated_at'] = $this->iso();
            $this->event($s, 'control', null, 'deadline_reached');
        }
    }

    private function event(array &$s, string $type, ?string $taskId, string $summary): void
    {
        $s['events'][] = ['event_id' => bin2hex(random_bytes(12)), 'type' => $type, 'task_id' => $taskId,
            'at' => $this->iso(), 'summary' => $this->text($summary, 4000)];
        $this->daily($s);
    }

    private function daily(array &$s): void
    {
        $day = (new \DateTimeImmutable('@' . $this->now()))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $events = array_values(array_filter($s['events'], static fn(array $e): bool => substr($e['at'], 0, 10) === $day));
        $completed = [];
        foreach ($events as $event) if ($event['type'] === 'completed') $completed[$event['task_id']] = $event['event_id'];
        $s['daily_summaries'][$day] = [
            'date' => $day, 'timezone' => 'Asia/Shanghai', 'completed_task_ids' => array_keys($completed),
            'event_ids' => array_column($events, 'event_id'),
            'waiting_task_ids' => array_keys(array_filter($s['tasks'], static fn(array $t): bool =>
                in_array($t['state'], ['waiting_user', 'pending_confirmation', 'interrupted', 'failed'], true))),
            'updated_at' => $this->iso(),
            'run_counts' => array_count_values(array_column(array_filter($s['runs'], static fn(array $r): bool => substr($r['triggered_at'], 0, 10) === $day), 'outcome')),
            'candidate_counts' => array_count_values(array_column($s['candidates'], 'state')),
        ];
    }

    private function transaction(callable $callback): mixed
    {
        $lock = @fopen($this->dir . '/queue.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('maintenance_storage_lock_failed');
        try {
            $path = $this->dir . '/state.json';
            if (is_file($path)) {
                $raw = file_get_contents($path);
                $state = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($state) || ($state['schema_version'] ?? null) !== 1
                    || !isset($state['control'], $state['tasks'], $state['events'])
                    || !is_array($state['tasks']) || !is_array($state['events'])) {
                    throw new \RuntimeException('maintenance_state_invalid_preserve_original');
                }
                if (!in_array($state['control']['mode'] ?? null, ['paused', 'running', 'stopped', 'exception_stop'], true)) {
                    throw new \RuntimeException('maintenance_control_invalid_preserve_original');
                }
                // Additive compatibility: no old task gets a fabricated result.
                $state += ['executor' => null, 'recovery_required' => false, 'last_check' => null,
                    'scheduler' => null, 'daily_summaries' => [], 'revision' => 0, 'candidates' => [], 'runs' => []];
                $state['control'] += ['deadline_at' => null, 'reason' => '', 'revision' => 0];
            } else {
                $state = ['schema_version' => 1, 'revision' => 0,
                    'control' => ['mode' => 'paused', 'revision' => 0, 'deadline_at' => null,
                        'reason' => 'initial_acceptance_pending', 'updated_at' => $this->iso()],
                    'executor' => null, 'recovery_required' => false, 'tasks' => [], 'events' => [],
                    'last_check' => null, 'scheduler' => null, 'daily_summaries' => [], 'candidates' => [], 'runs' => []];
                $raw = '';
            }
            $state += ['restart_recovery_policy' => ['enabled' => false, 'revision' => 0,
                'scope' => 'verified_machine_restart_only'], 'interruption' => null];
            $before = $this->encode($state);
            $result = $callback($state);
            if ($raw === '' || $before !== $this->encode($state)) {
                $state['revision']++;
                $encoded = $this->encode($state);
                $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
                $h = @fopen($tmp, 'xb');
                if (!$h) throw new \RuntimeException('maintenance_atomic_write_failed');
                try {
                    if (fwrite($h, $encoded) !== strlen($encoded) || !fflush($h)) {
                        throw new \RuntimeException('maintenance_atomic_write_incomplete');
                    }
                    if (function_exists('fsync')) fsync($h);
                } finally { fclose($h); }
                if (!@rename($tmp, $path)) {
                    @unlink($tmp);
                    throw new \RuntimeException('maintenance_atomic_replace_failed_original_preserved');
                }
                if (!hash_equals(hash('sha256', $encoded), (string)hash_file('sha256', $path))) {
                    throw new \RuntimeException('maintenance_exact_readback_failed');
                }
            }
            if (is_array($result) && isset($result['schema_version'])) $result['revision'] = $state['revision'];
            return $result;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private function required(array $input, string $key, int $limit): string
    {
        $value = $this->text((string)($input[$key] ?? ''), $limit);
        if ($value === '') throw new \InvalidArgumentException('maintenance_required_' . $key);
        return $value;
    }

    private function requireQuiescence(array $review): void
    {
        $this->validateMaterial($review);
        if (($review['writes_quiescent'] ?? false) !== true || empty($review['process_evidence']) || empty($review['checkpoint_review'])) {
            throw new \InvalidArgumentException('maintenance_recovery_requires_new_evidence_and_quiescent_writes');
        }
    }

    private function markGuardLoss(array &$s): void
    {
        $executor = $s['executor'];
        if (($s['interruption']['execution_id'] ?? null) === $executor['execution_id']) return;
        $priorMode = $s['control']['mode'];
        $s['recovery_required'] = true;
        if ($priorMode === 'running') {
            $s['control']['mode'] = 'exception_stop'; $s['control']['revision']++;
            $s['control']['reason'] = 'guard_disappeared_review_processes_and_checkpoint_before_recovery';
            $s['control']['updated_at'] = $this->iso();
        }
        $s['interruption'] = ['cause' => 'guard_loss', 'execution_id' => $executor['execution_id'],
            'started_at' => $executor['started_at'], 'prior_mode' => $priorMode,
            'control_revision' => $s['control']['revision'], 'policy_revision' => $s['restart_recovery_policy']['revision'],
            'file_fingerprints' => $executor['restart_file_fingerprints'] ?? null, 'observed_at' => $this->iso()];
        foreach ($s['tasks'] as &$task) {
            if ($task['state'] === 'running' && $task['execution_id'] === $executor['execution_id']) {
                $task['state'] = 'interrupted'; $task['revision']++;
                $task['blocked_reason'] = 'guard_disappeared_review_processes_and_checkpoint_before_recovery';
            }
        }
        unset($task);
        $this->event($s, 'interrupted', null, 'guard_disappeared_review_processes_and_checkpoint_before_recovery');
    }

    private function restartFileFingerprints(array $paths): ?array
    {
        $root = realpath($this->sourceRoot); $files = [];
        if ($root === false || $paths === [] || count($paths) > 100) return null;
        foreach ($paths as $path) {
            if (!is_string($path) || !preg_match('~^(app|public|scripts|tests)/[A-Za-z0-9_./-]+\.(php|js|mjs|ps1|css|html)$~D', $path)
                || str_contains($path, '..')) return null;
            $file = realpath($root . DIRECTORY_SEPARATOR . $path);
            if ($file === false || !is_file($file) || !str_starts_with(strtolower($file), strtolower($root . DIRECTORY_SEPARATOR))) return null;
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) return null;
            $files[$path] = $hash;
        }
        return $files;
    }

    private function epochIso(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d\TH:i:sP');
    }

    private function machineBootEpoch(): ?int
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $stat = @file_get_contents('/proc/stat');
            return is_string($stat) && preg_match('/^btime (\d+)$/m', $stat, $m) ? (int)$m[1] : null;
        }
        // Fixed, read-only OS query. Neither queue input nor credentials become shell text.
        $process = @proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
            '(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToUniversalTime().ToString("o")'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return null;
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $start = microtime(true);
        do {
            $output .= stream_get_contents($pipes[1]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $start >= 10) {
                // Do not kill a process. An unavailable OS observation cannot authorize recovery.
                fclose($pipes[1]); fclose($pipes[2]); return null;
            }
            usleep(20000);
        } while (true);
        $output .= stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        $epoch = strtotime(trim($output));
        return $status['exitcode'] === 0 && $epoch !== false ? $epoch : null;
    }

    private function requiredTiers(array $tiers): array
    {
        if ($tiers === [] || array_diff($tiers, self::TIERS) !== []) throw new \InvalidArgumentException('maintenance_required_tiers_invalid');
        return array_values(array_unique(array_merge(['local_completed'], $tiers)));
    }

    private function validateMaterial(mixed $value, int $depth = 0): void
    {
        if ($depth > 12) throw new \InvalidArgumentException('maintenance_input_too_deep');
        if (is_string($value)) { $this->text($value, 16000); return; }
        if (!is_array($value)) return;
        if (count($value) > 300) throw new \InvalidArgumentException('maintenance_input_too_large');
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), ['password', 'cookie', 'authorization', 'api_key', 'access_token', 'refresh_token', 'headers'], true)) {
                throw new \InvalidArgumentException('maintenance_sensitive_material_rejected');
            }
            $this->validateMaterial($item, $depth + 1);
        }
    }

    private function text(string $value, int $limit): string
    {
        $value = trim($value);
        if (mb_strlen($value) > $limit) throw new \InvalidArgumentException('maintenance_input_too_long');
        if (preg_match('/(?:Bearer\s+\S+|sk-[a-zA-Z0-9_-]{12,}|(?:password|cookie|authorization|api_key)\s*[:=]\s*\S+)/i', $value)) {
            throw new \InvalidArgumentException('maintenance_sensitive_material_rejected');
        }
        return $value;
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = $this->canonical($item);
        return $value;
    }
    private function validDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
    private function now(): int { return (int)($this->clock)(); }
    private function iso(): string
    {
        return (new \DateTimeImmutable('@' . $this->now()))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d\TH:i:sP');
    }
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
