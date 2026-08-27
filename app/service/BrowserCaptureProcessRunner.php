<?php
declare(strict_types=1);

namespace app\service;

use Throwable;

/**
 * Bounded browser-capture process runner with process-tree ownership.
 */
final class BrowserCaptureProcessRunner
{
    public const TERMINATION_CONTRACT = 'suxios.browser_capture_process_termination.v2';
    public const DEFAULT_OUTPUT_LIMIT_BYTES = 1048576;
    public const DEFAULT_POLL_INTERVAL_MS = 100;
    public const DEFAULT_TREE_POLL_INTERVAL_MS = 2000;
    public const DEFAULT_TREE_HANDSHAKE_MS = 2000;
    public const DEFAULT_GRACE_MS = 750;
    public const DEFAULT_FORCE_GRACE_MS = 2000;

    private object $runtime;

    public function __construct(?object $runtime = null)
    {
        $this->runtime = $runtime ?? new BrowserCaptureNativeProcessRuntime();
    }

    /**
     * @param list<string> $args
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(array $args, string $cwd, int $timeoutSeconds, array $options = []): array
    {
        $label = trim((string)($options['label'] ?? 'Browser capture')) ?: 'Browser capture';
        $timeoutSeconds = max(1, min(3600, $timeoutSeconds));
        $outputLimit = max(1, min(16777216, (int)($options['output_limit_bytes'] ?? self::DEFAULT_OUTPUT_LIMIT_BYTES)));
        $spoolLimit = max(1, min(67108864, (int)($options['spool_limit_bytes'] ?? $outputLimit)));
        $pollIntervalMs = max(10, min(1000, (int)($options['poll_interval_ms'] ?? self::DEFAULT_POLL_INTERVAL_MS)));
        $treePollIntervalMs = max(100, min(10000, (int)($options['tree_poll_interval_ms'] ?? self::DEFAULT_TREE_POLL_INTERVAL_MS)));
        $treeHandshakeMs = max(100, min(5000, (int)($options['tree_handshake_ms'] ?? self::DEFAULT_TREE_HANDSHAKE_MS)));
        $graceMs = max(0, min(5000, (int)($options['grace_ms'] ?? self::DEFAULT_GRACE_MS)));
        $forceGraceMs = max(100, min(5000, (int)($options['force_grace_ms'] ?? self::DEFAULT_FORCE_GRACE_MS)));
        $cancelRequested = is_callable($options['cancel_requested'] ?? null)
            ? $options['cancel_requested']
            : static fn(): bool => function_exists('connection_aborted') && connection_aborted() === 1;

        $process = null;
        $pipes = [];
        $opened = [];
        $processStarted = false;
        $pid = 0;
        $output = $this->emptyOutputState();
        $tree = $this->emptyTreeState(false);
        $lastStatus = ['running' => false, 'pid' => 0, 'exitcode' => -1];
        $termination = $this->baseTermination('not_started', 0, $graceMs, $forceGraceMs, true, 'not_started');
        $timedOut = false;
        $cancelled = false;
        $runnerFailed = false;
        $outputLimitExceeded = false;
        $orphanedDescendants = false;
        $pipesClosed = true;
        $closedPipeCount = 0;
        $closeDeferred = false;
        $exitCode = -1;
        $startedAtMs = $this->runtime->nowMs();
        $previousIgnoreUserAbort = null;

        if (function_exists('ignore_user_abort')) {
            $previousIgnoreUserAbort = ignore_user_abort(true);
        }

        try {
            if ($args === [] || trim((string)($args[0] ?? '')) === '') {
                throw new \InvalidArgumentException('browser_capture_command_missing');
            }
            $opened = $this->runtime->open(array_values(array_map('strval', $args)), $cwd, [
                'spool_limit_bytes' => $spoolLimit,
            ]);
            $process = $opened['process'] ?? null;
            $pipes = is_array($opened['pipes'] ?? null) ? $opened['pipes'] : [];
            if (!$this->runtime->isProcess($process)) {
                $runnerFailed = true;
                $tree = $this->emptyTreeState(true);
                $termination = $this->baseTermination(
                    'start_failed', 0, $graceMs, $forceGraceMs, true, 'not_started', ['process_start_failed']
                );
            } else {
                $processStarted = true;
                if (isset($pipes[0])) {
                    $this->runtime->closePipe($pipes[0]);
                    unset($pipes[0]);
                    $closedPipeCount++;
                }
                foreach ([1, 2] as $pipeIndex) {
                    if (isset($pipes[$pipeIndex])) {
                        $this->runtime->setNonBlocking($pipes[$pipeIndex]);
                    }
                }

                $lastStatus = $this->runtime->status($process);
                $pid = max(0, (int)($lastStatus['pid'] ?? 0));
                $tree = $this->refreshTree($pid, $process, $tree, (array)($opened['tree'] ?? []));
                $deadlineMs = $startedAtMs + ($timeoutSeconds * 1000);
                $treeHandshakeDeadlineMs = $startedAtMs + $treeHandshakeMs;
                $nextTreePollMs = $this->runtime->nowMs() + $treePollIntervalMs;

                while (true) {
                    $this->drainOutput($pipes, $output, $outputLimit);
                    $this->refreshSpoolUsage($process, $output, $opened);
                    if ($this->outputExceeded($output, $spoolLimit)) {
                        $outputLimitExceeded = true;
                        [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                            $process,
                            $pipes,
                            $pid,
                            'output_limit_exceeded',
                            $tree,
                            (array)($opened['tree'] ?? []),
                            $graceMs,
                            $forceGraceMs,
                            $pollIntervalMs,
                            $output,
                            $outputLimit,
                            $opened
                        );
                        break;
                    }

                    $lastStatus = $this->runtime->status($process);
                    $pid = max($pid, (int)($lastStatus['pid'] ?? 0));
                    $rootRunning = ($lastStatus['running'] ?? false) === true;
                    $nowMs = $this->runtime->nowMs();
                    if (!$rootRunning || $nowMs >= $nextTreePollMs) {
                        $tree = $this->refreshTree($pid, $process, $tree, (array)($opened['tree'] ?? []));
                        $nextTreePollMs = $nowMs + $treePollIntervalMs;
                    }

                    if ($this->runtime->osFamily() === 'Linux'
                        && (($opened['tree']['strategy'] ?? '') === 'posix_process_group')
                        && ($tree['handshake_confirmed'] ?? false) !== true
                        && $nowMs >= $treeHandshakeDeadlineMs
                    ) {
                        $runnerFailed = true;
                        [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                            $process,
                            $pipes,
                            $pid,
                            'process_group_handshake_failed',
                            $tree,
                            (array)($opened['tree'] ?? []),
                            $graceMs,
                            $forceGraceMs,
                            $pollIntervalMs,
                            $output,
                            $outputLimit,
                            $opened
                        );
                        break;
                    }

                    if (!$rootRunning) {
                        $tree = $this->refreshTree($pid, $process, $tree, (array)($opened['tree'] ?? []));
                        if (($tree['supported'] ?? false) === true
                            && ($tree['exited'] ?? false) === true
                            && ($this->runtime->osFamily() !== 'Linux' || ($tree['handshake_confirmed'] ?? false) === true)
                        ) {
                            $termination = $this->baseTermination(
                                'normal_exit',
                                $pid,
                                $graceMs,
                                $forceGraceMs,
                                true,
                                'natural_tree_exit'
                            );
                            break;
                        }
                        if (($tree['supported'] ?? false) === true) {
                            $orphanedDescendants = true;
                            [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                                $process,
                                $pipes,
                                $pid,
                                'orphaned_descendants_after_root_exit',
                                $tree,
                                (array)($opened['tree'] ?? []),
                                $graceMs,
                                $forceGraceMs,
                                $pollIntervalMs,
                                $output,
                                $outputLimit,
                                $opened
                            );
                        } else {
                            $termination = $this->baseTermination(
                                'tree_tracking_unavailable',
                                $pid,
                                $graceMs,
                                $forceGraceMs,
                                false,
                                'unconfirmed',
                                ['process_tree_tracking_unavailable']
                            );
                        }
                        break;
                    }

                    if ((bool)call_user_func($cancelRequested)) {
                        $cancelled = true;
                        [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                            $process,
                            $pipes,
                            $pid,
                            'cancelled',
                            $tree,
                            (array)($opened['tree'] ?? []),
                            $graceMs,
                            $forceGraceMs,
                            $pollIntervalMs,
                            $output,
                            $outputLimit,
                            $opened
                        );
                        break;
                    }
                    if ($this->runtime->nowMs() >= $deadlineMs) {
                        $timedOut = true;
                        [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                            $process,
                            $pipes,
                            $pid,
                            'timeout',
                            $tree,
                            (array)($opened['tree'] ?? []),
                            $graceMs,
                            $forceGraceMs,
                            $pollIntervalMs,
                            $output,
                            $outputLimit,
                            $opened
                        );
                        break;
                    }
                    $this->runtime->sleepMs($pollIntervalMs);
                }
            }
        } catch (Throwable) {
            $runnerFailed = true;
            if ($processStarted && $this->runtime->isProcess($process)) {
                try {
                    [$lastStatus, $tree, $termination] = $this->terminateAndConfirm(
                        $process,
                        $pipes,
                        $pid,
                        'runner_error',
                        $tree,
                        (array)($opened['tree'] ?? []),
                        $graceMs,
                        $forceGraceMs,
                        $pollIntervalMs,
                        $output,
                        $outputLimit,
                        $opened
                    );
                    $termination['errors'][] = 'runner_error';
                } catch (Throwable) {
                    $tree['exited'] = false;
                    $termination = $this->baseTermination(
                        'runner_error', $pid, $graceMs, $forceGraceMs, false, 'unconfirmed',
                        ['runner_error', 'termination_sequence_failed']
                    );
                }
            } else {
                $tree = $this->emptyTreeState(true);
                $termination = $this->baseTermination(
                    'runner_error_before_start', 0, $graceMs, $forceGraceMs, true, 'not_started', ['runner_error']
                );
            }
        } finally {
            try {
                $this->drainOutput($pipes, $output, $outputLimit);
                if ($processStarted) {
                    $this->refreshSpoolUsage($process, $output, $opened);
                }
            } catch (Throwable) {
                $termination['errors'][] = 'final_output_drain_failed';
            }
            foreach ($pipes as $pipe) {
                try {
                    if ($this->runtime->isPipe($pipe)) {
                        $this->runtime->closePipe($pipe);
                        $closedPipeCount++;
                    }
                } catch (Throwable) {
                    $pipesClosed = false;
                    $termination['errors'][] = 'pipe_close_failed';
                }
            }
            $pipes = [];

            if ($processStarted && $this->runtime->isProcess($process)) {
                $statusExitCode = (int)($lastStatus['exitcode'] ?? -1);
                if ($statusExitCode >= 0) {
                    $exitCode = $statusExitCode;
                }
                if (($tree['supported'] ?? false) === true && ($tree['exited'] ?? false) === true) {
                    try {
                        $closeExitCode = (int)$this->runtime->closeProcess($process);
                        if ($exitCode < 0 && $closeExitCode >= 0) {
                            $exitCode = $closeExitCode;
                        }
                    } catch (Throwable) {
                        $termination['errors'][] = 'process_close_failed';
                    }
                } else {
                    $closeDeferred = true;
                }
            }
            if ($previousIgnoreUserAbort !== null && function_exists('ignore_user_abort')) {
                ignore_user_abort((bool)$previousIgnoreUserAbort);
            }
        }

        $treeConfirmed = !$processStarted
            || (($tree['supported'] ?? false) === true
                && ($tree['exited'] ?? false) === true
                && ($this->runtime->osFamily() !== 'Linux' || ($tree['handshake_confirmed'] ?? false) === true));
        $termination['confirmed_exited'] = $treeConfirmed;
        $termination['observed_exit_code'] = $exitCode;
        $termination['close_deferred'] = $closeDeferred;
        $termination['tracked_descendants'] = array_values($tree['tracked_members'] ?? []);
        $termination['surviving_descendants'] = array_values($tree['survivors'] ?? []);
        $termination['errors'] = array_values(array_unique(array_map('strval', $termination['errors'] ?? [])));

        $success = $processStarted
            && !$timedOut
            && !$cancelled
            && !$runnerFailed
            && !$outputLimitExceeded
            && !$orphanedDescendants
            && $treeConfirmed
            && $exitCode === 0;
        $statusCode = match (true) {
            $success => 'ok',
            !$processStarted => 'process_start_failed',
            $outputLimitExceeded => 'process_output_limit_exceeded',
            $orphanedDescendants => 'process_orphaned_descendants',
            $timedOut => 'process_timeout',
            $cancelled => 'process_cancelled',
            $runnerFailed => 'process_runner_failed',
            $exitCode === -1 => 'process_exit_unknown',
            $exitCode !== 0 => 'process_exit_nonzero',
            !$treeConfirmed => 'process_tree_exit_unconfirmed',
            default => 'process_failed',
        };
        $message = match ($statusCode) {
            'ok' => 'ok',
            'process_start_failed' => $label . ' process could not start.',
            'process_tree_exit_unconfirmed' => $label . ' process-tree termination could not be confirmed.',
            'process_output_limit_exceeded' => $label . ' process output exceeded the hard limit.',
            'process_orphaned_descendants' => $label . ' root exited while descendant processes were still alive.',
            'process_timeout' => $label . ' timed out.',
            'process_cancelled' => $label . ' was cancelled.',
            'process_runner_failed' => $label . ' process runner failed.',
            'process_exit_unknown' => $label . ' exit code could not be verified.',
            'process_exit_nonzero' => $label . ' exited with code ' . $exitCode . '.',
            default => $label . ' process failed.',
        };

        return [
            'success' => $success,
            'status_code' => $statusCode,
            'message' => $message,
            'stdout' => $output['stdout'],
            'stderr' => $output['stderr'],
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'cancelled' => $cancelled,
            'process_started' => $processStarted,
            'process_pid' => $pid,
            'process_tree_exit_confirmed' => $treeConfirmed,
            'process_tree' => [
                'supported' => (bool)($tree['supported'] ?? false),
                'strategy' => (string)($tree['strategy'] ?? ''),
                'platform' => (string)$this->runtime->osFamily(),
                'root_pid' => $pid,
                'root_identity' => (string)($tree['root_identity'] ?? ''),
                'group_id' => (int)($tree['group_id'] ?? 0),
                'session_id' => (int)($tree['session_id'] ?? 0),
                'handshake_confirmed' => (bool)($tree['handshake_confirmed'] ?? false),
                'tracked_members' => array_values($tree['tracked_members'] ?? []),
                'survivors' => array_values($tree['survivors'] ?? []),
                'exited' => $treeConfirmed,
            ],
            'pipes_closed' => $pipesClosed,
            'closed_pipe_count' => $closedPipeCount,
            'stdout_bytes' => (int)$output['stdout_bytes'],
            'stderr_bytes' => (int)$output['stderr_bytes'],
            'stdout_truncated' => (bool)$output['stdout_truncated'],
            'stderr_truncated' => (bool)$output['stderr_truncated'],
            'spool_persisted_output_bytes' => (int)$output['spool_persisted_output_bytes'],
            'spool_metadata_bytes' => (int)$output['spool_metadata_bytes'],
            'output_limit_bytes' => $outputLimit,
            'spool_limit_bytes' => $spoolLimit,
            'output_transport' => (string)($opened['output_transport'] ?? 'runtime_defined'),
            'writer_bounded' => ($opened['writer_bounded'] ?? false) === true,
            'spool_artifacts' => array_values(array_map('strval', (array)($opened['spool_artifacts'] ?? []))),
            'termination' => $termination,
        ];
    }

    /** @return array{status:string,alive:bool,survivors:list<array<string,mixed>>,supported:bool} */
    public static function inspectRecordedProcessTree(array $processTree): array
    {
        return (new BrowserCaptureNativeProcessRuntime())->verifyRecordedTree($processTree);
    }

    /** @param list<string> $paths @return array{removed:int,rejected:int,failed:int} */
    public static function cleanupRecordedSpoolArtifacts(array $paths): array
    {
        return (new BrowserCaptureNativeProcessRuntime())->cleanupRecordedArtifacts($paths);
    }

    /**
     * @param array<int,mixed> $pipes
     * @param array<string,mixed> $tree
     * @param array<string,mixed> $treeContext
     * @param array<string,mixed> $output
     * @param array<string,mixed> $opened
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function terminateAndConfirm(
        mixed $process,
        array $pipes,
        int $pid,
        string $reason,
        array $tree,
        array $treeContext,
        int $graceMs,
        int $forceGraceMs,
        int $pollIntervalMs,
        array &$output,
        int $outputLimit,
        array $opened
    ): array {
        $soft = $this->runtime->terminateTree($pid, $process, false, $tree, $treeContext);
        [$status, $tree] = $this->waitForTreeExit(
            $process, $pipes, $pid, $tree, $treeContext,
            $this->runtime->nowMs() + $graceMs, $pollIntervalMs, $output, $outputLimit, $opened
        );
        $force = ['attempted' => false, 'accepted' => false, 'strategy' => '', 'exit_code' => null, 'targeted_pids' => []];
        $confirmationSource = 'post_soft_tree_observation';
        if (($tree['exited'] ?? false) !== true) {
            $force = $this->runtime->terminateTree($pid, $process, true, $tree, $treeContext);
            $force['attempted'] = true;
            [$status, $tree] = $this->waitForTreeExit(
                $process, $pipes, $pid, $tree, $treeContext,
                $this->runtime->nowMs() + $forceGraceMs, $pollIntervalMs, $output, $outputLimit, $opened
            );
            $confirmationSource = 'post_force_tree_observation';
        }
        $confirmed = ($tree['supported'] ?? false) === true
            && ($tree['exited'] ?? false) === true
            && ($this->runtime->osFamily() !== 'Linux' || ($tree['handshake_confirmed'] ?? false) === true);
        return [$status, $tree, [
            'contract' => self::TERMINATION_CONTRACT,
            'requested' => true,
            'reason' => $reason,
            'platform' => (string)$this->runtime->osFamily(),
            'pid' => $pid,
            'grace_ms' => $graceMs,
            'force_grace_ms' => $forceGraceMs,
            'soft' => $this->normalizeTerminationAttempt($soft, true),
            'force' => $this->normalizeTerminationAttempt($force, (bool)($force['attempted'] ?? false)),
            'confirmed_exited' => $confirmed,
            'confirmation_source' => $confirmed ? $confirmationSource : 'unconfirmed',
            'observed_exit_code' => (int)($status['exitcode'] ?? -1),
            'close_deferred' => false,
            'tracked_descendants' => array_values($tree['tracked_members'] ?? []),
            'surviving_descendants' => array_values($tree['survivors'] ?? []),
            'errors' => $confirmed ? [] : ['process_tree_exit_unconfirmed'],
        ]];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function waitForTreeExit(
        mixed $process,
        array $pipes,
        int $pid,
        array $tree,
        array $treeContext,
        int $deadlineMs,
        int $pollIntervalMs,
        array &$output,
        int $outputLimit,
        array $opened
    ): array {
        do {
            $this->drainOutput($pipes, $output, $outputLimit);
            $this->refreshSpoolUsage($process, $output, $opened);
            $status = $this->runtime->status($process);
            $tree = $this->refreshTree($pid, $process, $tree, $treeContext);
            if (($tree['supported'] ?? false) === true
                && ($tree['exited'] ?? false) === true
                && ($this->runtime->osFamily() !== 'Linux' || ($tree['handshake_confirmed'] ?? false) === true)
            ) {
                return [$status, $tree];
            }
            if ($this->runtime->nowMs() >= $deadlineMs) {
                return [$status, $tree];
            }
            $remaining = max(1, $deadlineMs - $this->runtime->nowMs());
            $this->runtime->sleepMs(min($pollIntervalMs, $remaining));
        } while (true);
    }

    /** @param array<string,mixed> $tree @return array<string,mixed> */
    private function refreshTree(int $pid, mixed $process, array $tree, array $treeContext): array
    {
        $observation = $this->runtime->observeTree(
            $pid,
            $process,
            array_values($tree['tracked_members'] ?? []),
            array_merge($treeContext, [
                'root_identity' => (string)($tree['root_identity'] ?? ''),
                'group_id' => (int)($tree['group_id'] ?? 0),
                'session_id' => (int)($tree['session_id'] ?? 0),
                'handshake_confirmed' => (bool)($tree['handshake_confirmed'] ?? false),
            ])
        );
        if (($observation['supported'] ?? false) !== true) {
            $tree['supported'] = false;
            $tree['exited'] = false;
            $tree['strategy'] = (string)($observation['strategy'] ?? $tree['strategy'] ?? 'unsupported');
            $tree['root_identity'] = (string)($observation['root_identity'] ?? $tree['root_identity'] ?? '');
            $tree['group_id'] = 0;
            $tree['session_id'] = 0;
            $tree['handshake_confirmed'] = false;
            $tracked = [];
            foreach (array_merge(
                (array)($tree['tracked_members'] ?? []),
                (array)($observation['members'] ?? [])
            ) as $member) {
                if (!is_array($member) || (int)($member['pid'] ?? 0) <= 0 || trim((string)($member['identity'] ?? '')) === '') {
                    continue;
                }
                $tracked[(int)$member['pid'] . ':' . (string)$member['identity']] = [
                    'pid' => (int)$member['pid'],
                    'identity' => (string)$member['identity'],
                    'parent_pid' => max(0, (int)($member['parent_pid'] ?? 0)),
                ];
            }
            $tree['tracked_members'] = array_values($tracked);
            $tree['survivors'] = array_values(array_filter(
                (array)($observation['survivors'] ?? []),
                static fn(mixed $member): bool => is_array($member)
            ));
            return $tree;
        }
        $tracked = [];
        foreach (array_merge(
            (array)($tree['tracked_members'] ?? []),
            (array)($observation['members'] ?? [])
        ) as $member) {
            if (!is_array($member) || (int)($member['pid'] ?? 0) <= 0 || trim((string)($member['identity'] ?? '')) === '') {
                continue;
            }
            $tracked[(int)$member['pid'] . ':' . (string)$member['identity']] = [
                'pid' => (int)$member['pid'],
                'identity' => (string)$member['identity'],
                'parent_pid' => max(0, (int)($member['parent_pid'] ?? 0)),
            ];
        }
        return [
            'supported' => true,
            'strategy' => (string)($observation['strategy'] ?? ''),
            'root_identity' => (string)($observation['root_identity'] ?? $tree['root_identity'] ?? ''),
            'group_id' => max(0, (int)($observation['group_id'] ?? $tree['group_id'] ?? 0)),
            'session_id' => max(0, (int)($observation['session_id'] ?? $tree['session_id'] ?? 0)),
            'handshake_confirmed' => ($observation['handshake_confirmed'] ?? $tree['handshake_confirmed'] ?? false) === true,
            'tracked_members' => array_values($tracked),
            'survivors' => array_values(array_filter(
                (array)($observation['survivors'] ?? []),
                static fn(mixed $member): bool => is_array($member)
            )),
            'exited' => ($observation['exited'] ?? false) === true,
        ];
    }

    /** @param array<int,mixed> $pipes @param array<string,mixed> $output */
    private function drainOutput(array $pipes, array &$output, int $limit): void
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $pipeIndex => $name) {
            if (!isset($pipes[$pipeIndex]) || !$this->runtime->isPipe($pipes[$pipeIndex])) {
                continue;
            }
            for ($read = 0; $read < 64; $read++) {
                $chunk = (string)$this->runtime->readPipe($pipes[$pipeIndex], 8192);
                if ($chunk === '') {
                    break;
                }
                $bytesKey = $name . '_bytes';
                $truncatedKey = $name . '_truncated';
                $output[$bytesKey] += strlen($chunk);
                $remaining = max(0, $limit - strlen((string)$output[$name]));
                if ($remaining > 0) {
                    $output[$name] .= substr($chunk, 0, $remaining);
                }
                if (strlen($chunk) > $remaining || $output[$bytesKey] > $limit || $read === 63) {
                    $output[$truncatedKey] = true;
                }
            }
        }
    }

    /** @param array<string,mixed> $output @param array<string,mixed> $opened */
    private function refreshSpoolUsage(mixed $process, array &$output, array $opened): void
    {
        $usage = $this->runtime->spoolUsage($process, $opened);
        foreach (['stdout', 'stderr'] as $name) {
            $bytesKey = $name . '_bytes';
            $spoolBytes = max(0, (int)($usage[$bytesKey] ?? 0));
            if ($spoolBytes > $output[$bytesKey]) {
                $output[$bytesKey] = $spoolBytes;
            }
        }
        $output['spool_persisted_output_bytes'] = max(
            (int)$output['spool_persisted_output_bytes'],
            max(0, (int)($usage['persisted_output_bytes'] ?? 0))
        );
        $output['spool_metadata_bytes'] = max(
            (int)$output['spool_metadata_bytes'],
            max(0, (int)($usage['metadata_bytes'] ?? 0))
        );
    }

    /** @param array<string,mixed> $output */
    private function outputExceeded(array &$output, int $limit): bool
    {
        $stdoutBytes = (int)$output['stdout_bytes'];
        $stderrBytes = (int)$output['stderr_bytes'];
        $exceeded = $stdoutBytes > $limit
            || $stderrBytes > $limit
            || ($stdoutBytes + $stderrBytes) > $limit;
        if ($exceeded) {
            $output['stdout_truncated'] = $stdoutBytes > $limit || $output['stdout_truncated'];
            $output['stderr_truncated'] = $stderrBytes > $limit || $output['stderr_truncated'];
        }
        return $exceeded;
    }

    /** @return array<string,mixed> */
    private function emptyOutputState(): array
    {
        return [
            'stdout' => '', 'stderr' => '', 'stdout_bytes' => 0, 'stderr_bytes' => 0,
            'stdout_truncated' => false, 'stderr_truncated' => false,
            'spool_persisted_output_bytes' => 0, 'spool_metadata_bytes' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyTreeState(bool $exited): array
    {
        return [
            'supported' => false, 'strategy' => '', 'root_identity' => '', 'group_id' => 0,
            'session_id' => 0, 'handshake_confirmed' => false,
            'tracked_members' => [], 'survivors' => [], 'exited' => $exited,
        ];
    }

    /** @param array<string,mixed> $attempt @return array<string,mixed> */
    private function normalizeTerminationAttempt(array $attempt, bool $attempted): array
    {
        return [
            'attempted' => $attempted,
            'accepted' => (bool)($attempt['accepted'] ?? false),
            'strategy' => trim((string)($attempt['strategy'] ?? '')),
            'exit_code' => isset($attempt['exit_code']) ? (int)$attempt['exit_code'] : null,
            'targeted_pids' => array_values(array_map('intval', (array)($attempt['targeted_pids'] ?? []))),
        ];
    }

    /** @param list<string> $errors @return array<string,mixed> */
    private function baseTermination(
        string $reason,
        int $pid,
        int $graceMs,
        int $forceGraceMs,
        bool $confirmed,
        string $confirmationSource,
        array $errors = []
    ): array {
        return [
            'contract' => self::TERMINATION_CONTRACT,
            'requested' => false,
            'reason' => $reason,
            'platform' => (string)$this->runtime->osFamily(),
            'pid' => $pid,
            'grace_ms' => $graceMs,
            'force_grace_ms' => $forceGraceMs,
            'soft' => ['attempted' => false, 'accepted' => false, 'strategy' => '', 'exit_code' => null, 'targeted_pids' => []],
            'force' => ['attempted' => false, 'accepted' => false, 'strategy' => '', 'exit_code' => null, 'targeted_pids' => []],
            'confirmed_exited' => $confirmed,
            'confirmation_source' => $confirmationSource,
            'observed_exit_code' => -1,
            'close_deferred' => false,
            'tracked_descendants' => [],
            'surviving_descendants' => [],
            'errors' => $errors,
        ];
    }
}

/** @internal Native process and process-tree primitives. */
final class BrowserCaptureNativeProcessRuntime
{
    /** @var array<int,string> */
    private array $filePipePaths = [];
    /** @var array<int,array<string,mixed>> */
    private array $processArtifacts = [];
    /** @var callable|null */
    private $windowsProcessTableProvider;
    /** @var callable|null */
    private $linuxProcessTableProvider;

    public function __construct(?callable $windowsProcessTableProvider = null, ?callable $linuxProcessTableProvider = null)
    {
        $this->windowsProcessTableProvider = $windowsProcessTableProvider;
        $this->linuxProcessTableProvider = $linuxProcessTableProvider;
    }

    /** @param list<string> $args @return array<string,mixed> */
    public function open(array $args, string $cwd, array $options = []): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->openWithBoundedWindowsRelay(
                $args,
                $cwd,
                max(1, (int)($options['spool_limit_bytes'] ?? BrowserCaptureProcessRunner::DEFAULT_OUTPUT_LIMIT_BYTES))
            );
        }
        $setsid = PHP_OS_FAMILY === 'Linux' ? $this->setsidPath() : '';
        $launchArgs = $setsid !== '' ? [$setsid, '--wait', ...$args] : $args;
        $pipes = [];
        $process = @proc_open(
            $launchArgs,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        return [
            'process' => $process,
            'pipes' => $pipes,
            'tree' => [
                'supported' => $setsid !== '',
                'strategy' => $setsid !== '' ? 'posix_process_group' : 'unsupported',
                'group_id' => 0,
                'session_id' => 0,
                'handshake_confirmed' => false,
            ],
            'output_transport' => 'bounded_os_pipes',
            'writer_bounded' => true,
            'spool_artifacts' => [],
        ];
    }

    public function isProcess(mixed $process): bool
    {
        return is_resource($process);
    }

    public function isPipe(mixed $pipe): bool
    {
        return is_resource($pipe);
    }

    public function closePipe(mixed $pipe): void
    {
        if (is_resource($pipe)) {
            unset($this->filePipePaths[(int)$pipe]);
            @fclose($pipe);
        }
    }

    public function setNonBlocking(mixed $pipe): void
    {
        if (is_resource($pipe) && !isset($this->filePipePaths[(int)$pipe])) {
            @stream_set_blocking($pipe, false);
        }
    }

    public function readPipe(mixed $pipe, int $length): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        $filePath = $this->filePipePaths[(int)$pipe] ?? '';
        if ($filePath !== '') {
            $position = @ftell($pipe);
            clearstatcache(true, $filePath);
            $size = @filesize($filePath);
            $available = is_int($position) && is_int($size) ? max(0, $size - $position) : 0;
            if ($available <= 0) {
                return '';
            }
            $length = min($length, $available);
        }
        $chunk = @fread($pipe, max(1, $length));
        return is_string($chunk) ? $chunk : '';
    }

    /** @return array<string,mixed> */
    public function status(mixed $process): array
    {
        if (!is_resource($process)) {
            return ['running' => false, 'pid' => 0, 'exitcode' => -1];
        }
        $status = @proc_get_status($process);
        return is_array($status) ? $status : ['running' => false, 'pid' => 0, 'exitcode' => -1];
    }

    /** @param list<array<string,mixed>> $knownMembers @return array<string,mixed> */
    public function observeTree(int $rootPid, mixed $process, array $knownMembers, array $context): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->observeWindowsTree($rootPid, $knownMembers, $context);
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return $this->observeLinuxTree($rootPid, $knownMembers, $context);
        }
        return ['supported' => false, 'strategy' => 'unsupported', 'members' => [], 'survivors' => [], 'exited' => false];
    }

    /** @param array<string,mixed> $tree @param array<string,mixed> $context @return array<string,mixed> */
    public function terminateTree(int $rootPid, mixed $process, bool $force, array $tree, array $context): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $targets = [];
            $status = $this->status($process);
            $table = $this->windowsProcessTable();
            $rootIdentity = trim((string)($tree['root_identity'] ?? ''));
            if (($status['running'] ?? false) === true
                && $rootPid > 0
                && $rootIdentity !== ''
                && is_array($table)
                && isset($table[$rootPid])
                && hash_equals($rootIdentity, (string)$table[$rootPid]['identity'])
            ) {
                $targets[] = $rootPid;
            }
            foreach ((array)($tree['survivors'] ?? []) as $member) {
                $memberPid = (int)($member['pid'] ?? 0);
                $identity = trim((string)($member['identity'] ?? ''));
                if ($memberPid > 0
                    && $identity !== ''
                    && is_array($table)
                    && isset($table[$memberPid])
                    && hash_equals($identity, (string)$table[$memberPid]['identity'])
                ) {
                    $targets[] = $memberPid;
                }
            }
            $targets = array_values(array_unique(array_filter($targets, static fn(int $pid): bool => $pid > 0)));
            $accepted = false;
            $lastExit = 0;
            foreach ($targets as $targetPid) {
                $exit = $this->windowsTaskkill($targetPid, $force);
                $lastExit = $exit;
                $accepted = $accepted || $exit === 0;
            }
            return [
                'accepted' => $accepted,
                'strategy' => $force ? 'windows_taskkill_tracked_tree_force' : 'windows_taskkill_tracked_tree',
                'exit_code' => $lastExit,
                'targeted_pids' => $targets,
            ];
        }
        if (PHP_OS_FAMILY === 'Linux') {
            $groupId = max(0, (int)($tree['group_id'] ?? $context['group_id'] ?? 0));
            $sessionId = max(0, (int)($tree['session_id'] ?? $context['session_id'] ?? 0));
            $signal = $force ? 9 : 15;
            $table = $this->linuxProcessTable();
            $handshakeConfirmed = ($tree['supported'] ?? false) === true
                && ($tree['handshake_confirmed'] ?? false) === true
                && $rootPid > 0
                && $groupId === $rootPid
                && $sessionId === $rootPid;
            $groupAccepted = $handshakeConfirmed
                && function_exists('posix_kill')
                && @posix_kill(-$groupId, $signal);
            $targetedPids = [];
            $memberAccepted = false;
            foreach (array_merge([[
                'pid' => $rootPid,
                'identity' => (string)($tree['root_identity'] ?? ''),
            ]], (array)($tree['survivors'] ?? [])) as $member) {
                $memberPid = (int)($member['pid'] ?? 0);
                $identity = trim((string)($member['identity'] ?? ''));
                if ($memberPid <= 0
                    || $identity === ''
                    || !is_array($table)
                    || !isset($table[$memberPid])
                    || !hash_equals($identity, (string)$table[$memberPid]['identity'])
                ) {
                    continue;
                }
                $targetedPids[] = $memberPid;
                if (function_exists('posix_kill') && @posix_kill($memberPid, $signal)) {
                    $memberAccepted = true;
                }
            }
            $accepted = $groupAccepted || $memberAccepted;
            return [
                'accepted' => $accepted,
                'strategy' => $handshakeConfirmed
                    ? ($force ? 'posix_process_group_and_members_kill' : 'posix_process_group_and_members_term')
                    : 'posix_identity_members_only_fail_closed',
                'exit_code' => $accepted ? 0 : 1,
                'targeted_pids' => array_values(array_unique($targetedPids)),
            ];
        }
        $accepted = is_resource($process) && @proc_terminate($process, $force ? 9 : 15);
        return [
            'accepted' => $accepted,
            'strategy' => $force ? 'root_signal_kill_unverified' : 'root_signal_term_unverified',
            'exit_code' => $accepted ? 0 : 1,
            'targeted_pids' => [$rootPid],
        ];
    }

    /** @return array<string,mixed> */
    public function spoolUsage(mixed $process, array $opened): array
    {
        $artifacts = is_resource($process) ? ($this->processArtifacts[(int)$process] ?? []) : [];
        $persistedOutputBytes = 0;
        foreach (['stdout', 'stderr'] as $stream) {
            $path = (string)($artifacts[$stream] ?? '');
            clearstatcache(true, $path);
            $size = $path !== '' ? @filesize($path) : false;
            $persistedOutputBytes += is_int($size) ? max(0, $size) : 0;
        }
        $metadataBytes = 0;
        foreach (['metadata', 'config'] as $kind) {
            $path = (string)($artifacts[$kind] ?? '');
            clearstatcache(true, $path);
            $size = $path !== '' ? @filesize($path) : false;
            $metadataBytes += is_int($size) ? max(0, $size) : 0;
        }
        $metadataPath = (string)($artifacts['metadata'] ?? '');
        if ($metadataPath !== '' && is_file($metadataPath)) {
            $metadata = trim((string)@file_get_contents($metadataPath));
            if (preg_match('/^(\d+)\|(\d+)\|[01]$/D', $metadata, $matches) === 1) {
                return [
                    'stdout_bytes' => (int)$matches[1],
                    'stderr_bytes' => (int)$matches[2],
                    'persisted_output_bytes' => $persistedOutputBytes,
                    'metadata_bytes' => $metadataBytes,
                ];
            }
        }
        return [
            'stdout_bytes' => 0,
            'stderr_bytes' => 0,
            'persisted_output_bytes' => $persistedOutputBytes,
            'metadata_bytes' => $metadataBytes,
        ];
    }

    public function closeProcess(mixed $process): int
    {
        if (!is_resource($process)) {
            return -1;
        }
        $key = (int)$process;
        try {
            return (int)@proc_close($process);
        } finally {
            $this->cleanupRecordedArtifacts(array_values(array_map(
                'strval',
                (array)($this->processArtifacts[$key]['all'] ?? [])
            )));
            unset($this->processArtifacts[$key]);
        }
    }

    public function nowMs(): int
    {
        return (int)floor(hrtime(true) / 1000000);
    }

    public function sleepMs(int $milliseconds): void
    {
        usleep(max(0, $milliseconds) * 1000);
    }

    public function osFamily(): string
    {
        return PHP_OS_FAMILY;
    }

    /** @return array{status:string,alive:bool,survivors:list<array<string,mixed>>,supported:bool} */
    public function verifyRecordedTree(array $processTree): array
    {
        $platform = (string)($processTree['platform'] ?? '');
        $strategy = trim((string)($processTree['strategy'] ?? ''));
        if (($processTree['supported'] ?? false) !== true
            || !in_array($platform, ['Windows', 'Linux'], true)
            || ($platform === 'Windows' && $strategy !== 'windows_descendant_identity_tracking')
            || ($platform === 'Linux' && $strategy !== 'posix_process_group')
        ) {
            return ['status' => 'unknown', 'alive' => true, 'survivors' => [], 'supported' => false];
        }
        $root = [
            'pid' => max(0, (int)($processTree['root_pid'] ?? 0)),
            'identity' => trim((string)($processTree['root_identity'] ?? '')),
        ];
        $members = array_values(array_filter(
            (array)($processTree['tracked_members'] ?? []),
            static fn(mixed $member): bool => is_array($member)
        ));
        $groupId = max(0, (int)($processTree['group_id'] ?? 0));
        $sessionId = max(0, (int)($processTree['session_id'] ?? 0));
        $hasRecordedIdentity = ($root['pid'] > 0 && $root['identity'] !== '');
        foreach ($members as $member) {
            if ((int)($member['pid'] ?? 0) > 0 && trim((string)($member['identity'] ?? '')) !== '') {
                $hasRecordedIdentity = true;
                break;
            }
        }
        if (!$hasRecordedIdentity
            || ($platform === 'Linux' && (
                ($processTree['handshake_confirmed'] ?? false) !== true
                || $root['pid'] <= 0
                || $groupId !== $root['pid']
                || $sessionId !== $root['pid']
            ))
        ) {
            return ['status' => 'unknown', 'alive' => true, 'survivors' => [], 'supported' => false];
        }

        $table = $platform === 'Windows'
            ? $this->windowsProcessTable()
            : ($platform === 'Linux' ? $this->linuxProcessTable() : null);
        if (!is_array($table)) {
            return ['status' => 'unknown', 'alive' => true, 'survivors' => [], 'supported' => false];
        }
        $survivors = [];
        foreach (array_merge([$root], $members) as $member) {
            $pid = (int)($member['pid'] ?? 0);
            $identity = trim((string)($member['identity'] ?? ''));
            if ($pid > 0 && $identity !== '' && isset($table[$pid]) && hash_equals($identity, (string)$table[$pid]['identity'])) {
                $survivors[] = ['pid' => $pid, 'identity' => $identity, 'parent_pid' => (int)$table[$pid]['parent_pid']];
            }
        }
        if ($platform === 'Windows' && $root['pid'] > 0) {
            $rootMatches = $root['identity'] !== ''
                && isset($table[$root['pid']])
                && hash_equals($root['identity'], (string)$table[$root['pid']]['identity']);
            $descendantSeeds = $rootMatches ? [$root['pid'] => true] : [];
            foreach ($survivors as $member) {
                $descendantSeeds[(int)$member['pid']] = true;
            }
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($table as $pid => $row) {
                    $parentPid = (int)$row['parent_pid'];
                    if (isset($descendantSeeds[$pid]) || !isset($descendantSeeds[$parentPid])) {
                        continue;
                    }
                    $descendantSeeds[$pid] = true;
                    $survivors[] = [
                        'pid' => (int)$pid,
                        'identity' => (string)$row['identity'],
                        'parent_pid' => $parentPid,
                    ];
                    $changed = true;
                }
            }
        }
        if ($platform === 'Linux') {
            foreach ($table as $pid => $row) {
                if ((int)$row['group_id'] !== $groupId) {
                    continue;
                }
                $survivors[] = [
                    'pid' => (int)$pid,
                    'identity' => (string)$row['identity'],
                    'parent_pid' => (int)$row['parent_pid'],
                ];
            }
        }
        $uniqueSurvivors = [];
        foreach ($survivors as $member) {
            $uniqueSurvivors[(int)$member['pid'] . ':' . (string)$member['identity']] = $member;
        }
        $survivors = array_values($uniqueSurvivors);
        return [
            'status' => $survivors === [] ? 'exited' : 'alive',
            'alive' => $survivors !== [],
            'survivors' => $survivors,
            'supported' => true,
        ];
    }

    /** @param list<string> $paths @return array{removed:int,rejected:int,failed:int} */
    public function cleanupRecordedArtifacts(array $paths): array
    {
        $result = ['removed' => 0, 'rejected' => 0, 'failed' => 0];
        $tempRoot = realpath(sys_get_temp_dir());
        $tempRoot = is_string($tempRoot) ? rtrim(str_replace('\\', '/', $tempRoot), '/') : '';
        foreach (array_values(array_unique(array_map('strval', $paths))) as $path) {
            $parent = $path !== '' ? realpath(dirname($path)) : false;
            $normalizedParent = is_string($parent) ? rtrim(str_replace('\\', '/', $parent), '/') : '';
            $parentMatches = $this->artifactPathsEqual($tempRoot, $normalizedParent, PHP_OS_FAMILY);
            $artifactName = basename($path);
            $nameMatches = PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($artifactName), 'suxi-browser-')
                : str_starts_with($artifactName, 'suxi-browser-');
            if ($path === ''
                || $tempRoot === ''
                || $normalizedParent === ''
                || !$parentMatches
                || !$nameMatches
                || is_link($path)
            ) {
                $result['rejected']++;
                continue;
            }
            if (!is_file($path)) {
                continue;
            }
            if (@unlink($path)) {
                $result['removed']++;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }

    private function artifactPathsEqual(string $expected, string $actual, string $platform): bool
    {
        if ($expected === '' || $actual === '') {
            return false;
        }
        return $platform === 'Windows'
            ? hash_equals(strtolower($expected), strtolower($actual))
            : hash_equals($expected, $actual);
    }

    /** @param list<string> $args @return array<string,mixed> */
    private function openWithBoundedWindowsRelay(array $args, string $cwd, int $limitBytes): array
    {
        $relayPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR
            . 'lib' . DIRECTORY_SEPARATOR . 'browser_capture_bounded_relay.ps1';
        if ($args === [] || !is_file($relayPath)) {
            return ['process' => false, 'pipes' => [], 'tree' => ['supported' => false], 'writer_bounded' => false, 'spool_artifacts' => []];
        }
        $stdoutPath = $this->createBoundedRelayArtifactPath('stdout', 'tmp');
        $stderrPath = $this->createBoundedRelayArtifactPath('stderr', 'tmp');
        $metadataPath = $this->createBoundedRelayArtifactPath('metadata', 'txt');
        $configPath = $this->createBoundedRelayArtifactPath('config', 'json');
        $artifacts = array_values(array_filter([$stdoutPath, $stderrPath, $metadataPath, $configPath], 'is_string'));
        if (count($artifacts) !== 4) {
            $this->cleanupRecordedArtifacts($artifacts);
            return ['process' => false, 'pipes' => [], 'tree' => ['supported' => false], 'writer_bounded' => false, 'spool_artifacts' => []];
        }
        $config = json_encode([
            'executable' => (string)array_shift($args),
            'args' => array_values($args),
            'cwd' => $cwd,
            'stdout_path' => $stdoutPath,
            'stderr_path' => $stderrPath,
            'metadata_path' => $metadataPath,
            'limit_bytes' => max(1, $limitBytes),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($config)
            || @file_put_contents($configPath, $config, LOCK_EX) === false
            || @file_put_contents($metadataPath, '0|0|0', LOCK_EX) === false
        ) {
            $this->cleanupRecordedArtifacts($artifacts);
            return ['process' => false, 'pipes' => [], 'tree' => ['supported' => false], 'writer_bounded' => false, 'spool_artifacts' => []];
        }
        $powershell = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/')
            . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $launchPipes = [];
        $process = @proc_open(
            [$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $relayPath, '-ConfigPath', $configPath],
            [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
            $launchPipes,
            $cwd,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            $this->cleanupRecordedArtifacts($artifacts);
            return ['process' => false, 'pipes' => [], 'tree' => ['supported' => false], 'writer_bounded' => false, 'spool_artifacts' => []];
        }
        $stdout = @fopen($stdoutPath, 'rb');
        $stderr = @fopen($stderrPath, 'rb');
        if (!is_resource($stdout) || !is_resource($stderr)) {
            foreach ([$stdout, $stderr, $launchPipes[0] ?? null] as $resource) {
                if (is_resource($resource)) @fclose($resource);
            }
            @proc_terminate($process, 9);
            @proc_close($process);
            $this->cleanupRecordedArtifacts($artifacts);
            return ['process' => false, 'pipes' => [], 'tree' => ['supported' => false], 'writer_bounded' => false, 'spool_artifacts' => []];
        }
        $this->filePipePaths[(int)$stdout] = $stdoutPath;
        $this->filePipePaths[(int)$stderr] = $stderrPath;
        $this->processArtifacts[(int)$process] = [
            'stdout' => $stdoutPath,
            'stderr' => $stderrPath,
            'metadata' => $metadataPath,
            'config' => $configPath,
            'all' => $artifacts,
        ];
        return [
            'process' => $process,
            'pipes' => [0 => $launchPipes[0], 1 => $stdout, 2 => $stderr],
            'tree' => ['supported' => true, 'strategy' => 'windows_descendant_identity_tracking'],
            'output_transport' => 'windows_bounded_relay',
            'writer_bounded' => true,
            'spool_artifacts' => $artifacts,
        ];
    }

    private function createBoundedRelayArtifactPath(string $kind, string $extension): string|false
    {
        try {
            $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'suxi-browser-relay-'
                . preg_replace('/[^a-z0-9_-]+/i', '-', $kind) . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
        } catch (Throwable) {
            return false;
        }
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) return false;
        fclose($handle);
        if (!@chmod($path, 0600)) {
            @unlink($path);
            return false;
        }
        return $path;
    }

    /** @param list<array<string,mixed>> $knownMembers @return array<string,mixed> */
    private function observeWindowsTree(int $rootPid, array $knownMembers, array $context): array
    {
        $table = $this->windowsProcessTable();
        if (!is_array($table)) {
            return ['supported' => false, 'strategy' => 'windows_descendant_identity_tracking', 'members' => [], 'survivors' => [], 'exited' => false];
        }
        $rootIdentity = trim((string)($context['root_identity'] ?? ''));
        if ($rootIdentity === '' && isset($table[$rootPid])) {
            $rootIdentity = (string)$table[$rootPid]['identity'];
        }
        if ($rootIdentity === '') {
            return ['supported' => false, 'strategy' => 'windows_descendant_identity_tracking', 'members' => [], 'survivors' => [], 'exited' => false];
        }
        $rootAlive = isset($table[$rootPid])
            && hash_equals($rootIdentity, (string)$table[$rootPid]['identity']);
        $knownAlivePids = $rootAlive ? [$rootPid => true] : [];
        foreach ($knownMembers as $member) {
            $pid = (int)($member['pid'] ?? 0);
            $identity = trim((string)($member['identity'] ?? ''));
            if ($pid > 0 && $identity !== '' && isset($table[$pid]) && hash_equals($identity, (string)$table[$pid]['identity'])) {
                $knownAlivePids[$pid] = true;
            }
        }
        $members = [];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($table as $pid => $row) {
                $parentPid = (int)$row['parent_pid'];
                if ($pid === $rootPid || isset($knownAlivePids[$pid]) || !isset($knownAlivePids[$parentPid])) {
                    continue;
                }
                $knownAlivePids[$pid] = true;
                $members[$pid] = ['pid' => $pid, 'identity' => (string)$row['identity'], 'parent_pid' => $parentPid];
                $changed = true;
            }
        }
        foreach ($knownMembers as $member) {
            $pid = (int)($member['pid'] ?? 0);
            $identity = trim((string)($member['identity'] ?? ''));
            if ($pid > 0 && $identity !== '' && isset($table[$pid]) && hash_equals($identity, (string)$table[$pid]['identity'])) {
                $members[$pid] = ['pid' => $pid, 'identity' => $identity, 'parent_pid' => (int)$table[$pid]['parent_pid']];
            }
        }
        return [
            'supported' => true,
            'strategy' => 'windows_descendant_identity_tracking',
            'root_identity' => $rootIdentity,
            'group_id' => 0,
            'members' => array_values($members),
            'survivors' => array_values($members),
            'exited' => !$rootAlive && $members === [],
        ];
    }

    /** @param list<array<string,mixed>> $knownMembers @return array<string,mixed> */
    private function observeLinuxTree(int $rootPid, array $knownMembers, array $context): array
    {
        $table = $this->linuxProcessTable();
        if (!is_array($table)) {
            return ['supported' => false, 'strategy' => 'posix_process_group', 'members' => [], 'survivors' => [], 'exited' => false];
        }
        $rootIdentity = trim((string)($context['root_identity'] ?? ''));
        if ($rootIdentity === '' && isset($table[$rootPid])) {
            $rootIdentity = (string)$table[$rootPid]['identity'];
        }
        $rootMatches = $rootIdentity !== ''
            && isset($table[$rootPid])
            && hash_equals($rootIdentity, (string)$table[$rootPid]['identity']);
        $rootIdentityConflict = $rootIdentity !== '' && isset($table[$rootPid]) && !$rootMatches;
        $handshakeConfirmed = ($context['handshake_confirmed'] ?? false) === true;
        if ($rootIdentityConflict) {
            $handshakeConfirmed = false;
        }
        if (!$handshakeConfirmed) {
            $handshakeConfirmed = ($context['supported'] ?? false) === true
                && $rootMatches
                && (int)$table[$rootPid]['group_id'] === $rootPid
                && (int)$table[$rootPid]['session_id'] === $rootPid;
        }
        $groupId = $handshakeConfirmed ? $rootPid : 0;
        $sessionId = $handshakeConfirmed ? $rootPid : 0;
        $members = [];
        $seeds = $rootMatches ? [$rootPid => true] : [];
        foreach ($knownMembers as $member) {
            $pid = (int)($member['pid'] ?? 0);
            $identity = trim((string)($member['identity'] ?? ''));
            if ($pid > 0 && $identity !== '' && isset($table[$pid]) && hash_equals($identity, (string)$table[$pid]['identity'])) {
                $seeds[$pid] = true;
                $members[$pid] = ['pid' => $pid, 'identity' => $identity, 'parent_pid' => (int)$table[$pid]['parent_pid']];
            }
        }
        if ($handshakeConfirmed) {
            foreach ($table as $pid => $row) {
                if ($pid !== $rootPid && (int)$row['group_id'] === $groupId) {
                    $seeds[$pid] = true;
                    $members[$pid] = ['pid' => $pid, 'identity' => (string)$row['identity'], 'parent_pid' => (int)$row['parent_pid']];
                }
            }
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($table as $pid => $row) {
                $parentPid = (int)$row['parent_pid'];
                if ($pid === $rootPid || isset($seeds[$pid]) || !isset($seeds[$parentPid])) {
                    continue;
                }
                $seeds[$pid] = true;
                $members[$pid] = ['pid' => $pid, 'identity' => (string)$row['identity'], 'parent_pid' => $parentPid];
                $changed = true;
            }
        }
        return [
            'supported' => $handshakeConfirmed,
            'strategy' => 'posix_process_group',
            'root_identity' => $rootIdentity,
            'group_id' => $groupId,
            'session_id' => $sessionId,
            'handshake_confirmed' => $handshakeConfirmed,
            'members' => array_values($members),
            'survivors' => array_values($members),
            'exited' => $handshakeConfirmed && !$rootMatches && $members === [],
        ];
    }

    /** @return array<int,array{parent_pid:int,group_id:int,session_id:int,identity:string}>|null */
    private function windowsProcessTable(): ?array
    {
        if ($this->windowsProcessTableProvider !== null) {
            $table = call_user_func($this->windowsProcessTableProvider);
            return is_array($table) ? $table : null;
        }
        $powershell = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/')
            . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($powershell)) {
            $powershell = 'powershell.exe';
        }
        $script = '$ErrorActionPreference="Stop";Get-CimInstance Win32_Process|ForEach-Object{' .
            '"{0}|{1}|{2}" -f $_.ProcessId,$_.ParentProcessId,([string]$_.CreationDate)}';
        $utf16 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')
            : (function_exists('iconv') ? iconv('UTF-8', 'UTF-16LE', $script) : false);
        if (!is_string($utf16)) {
            return null;
        }
        $lines = [];
        $exitCode = -1;
        @exec('"' . str_replace('"', '""', $powershell) . '" -NoProfile -NonInteractive -EncodedCommand '
            . base64_encode($utf16) . ' 2>NUL', $lines, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }
        $table = [];
        foreach ($lines as $line) {
            $parts = explode('|', trim((string)$line), 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
                continue;
            }
            $pid = (int)$parts[0];
            $table[$pid] = [
                'parent_pid' => (int)$parts[1],
                'group_id' => 0,
                'session_id' => 0,
                'identity' => hash('sha256', $pid . '|' . $parts[2]),
            ];
        }
        return $table;
    }

    /** @return array<int,array{parent_pid:int,group_id:int,session_id:int,identity:string}>|null */
    private function linuxProcessTable(): ?array
    {
        if ($this->linuxProcessTableProvider !== null) {
            $table = call_user_func($this->linuxProcessTableProvider);
            return is_array($table) ? $table : null;
        }
        if (!is_dir('/proc')) {
            return null;
        }
        $table = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $content = @file_get_contents($path);
            if (!is_string($content)) {
                continue;
            }
            $close = strrpos($content, ') ');
            if ($close === false) {
                continue;
            }
            $pidText = strstr($content, ' ', true);
            $fields = preg_split('/\s+/', substr($content, $close + 2)) ?: [];
            if (!is_string($pidText) || !ctype_digit($pidText) || count($fields) < 20) {
                continue;
            }
            $pid = (int)$pidText;
            $startTime = (string)$fields[19];
            $table[$pid] = [
                'parent_pid' => (int)$fields[1],
                'group_id' => (int)$fields[2],
                'session_id' => (int)$fields[3],
                'identity' => hash('sha256', $pid . '|' . $startTime),
            ];
        }
        return $table;
    }

    private function windowsTaskkill(int $pid, bool $force): int
    {
        if ($pid <= 0) {
            return 1;
        }
        $output = [];
        $exitCode = -1;
        @exec('taskkill.exe /PID ' . $pid . ' /T' . ($force ? ' /F' : '') . ' 2>NUL', $output, $exitCode);
        return $exitCode;
    }

    private function setsidPath(): string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }
        return '';
    }
}
