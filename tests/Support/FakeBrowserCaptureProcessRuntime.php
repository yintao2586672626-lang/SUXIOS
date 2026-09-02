<?php
declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Deterministic fake process tree; never launches a real process or browser. */
final class FakeBrowserCaptureProcessRuntime
{
    public int $nowMs = 0;
    public int $statusCalls = 0;
    public int $treeObservationCalls = 0;
    public int $closeProcessCalls = 0;
    /** @var list<int> */
    public array $closedPipeIds = [];
    /** @var list<array{force:bool,pid:int}> */
    public array $terminationAttempts = [];
    /** @var list<int> */
    public array $sleeps = [];

    private object $process;
    /** @var array<int,object> */
    private array $pipes;
    /** @var array<int,list<string>> */
    private array $chunks;
    private bool $rootRunning;
    /** @var array<int,array{pid:int,identity:string,parent_pid:int}> */
    private array $descendants = [];
    private int $exitCode;
    private int $closeExitCode;
    private int $pid;
    private int $naturalExitAfterStatusCalls;
    private bool $softStopsRoot;
    private bool $softStopsDescendants;
    private bool $forceStopsRoot;
    private bool $forceStopsDescendants;
    private bool $softAccepted;
    private bool $forceAccepted;
    private string $family;
    private bool $linuxHandshakeConfirmed;
    /** @var array<int,true> */
    private array $throwStatusAt;
    /** @var list<array{stdout_bytes:int,stderr_bytes:int}> */
    private array $spoolUsageSequence;
    /** @var list<string> */
    private array $spoolArtifacts;

    /** @param array<string,mixed> $options */
    public function __construct(array $options = [])
    {
        $this->pid = (int)($options['pid'] ?? 4242);
        $this->rootRunning = (bool)($options['running'] ?? true);
        $this->exitCode = (int)($options['exit_code'] ?? 0);
        $this->closeExitCode = (int)($options['close_exit_code'] ?? $this->exitCode);
        $this->naturalExitAfterStatusCalls = (int)($options['natural_exit_after_status_calls'] ?? 2);
        $softStops = (bool)($options['soft_stops'] ?? true);
        $forceStops = (bool)($options['force_stops'] ?? true);
        $this->softStopsRoot = (bool)($options['soft_stops_root'] ?? $softStops);
        $this->softStopsDescendants = (bool)($options['soft_stops_descendants'] ?? $softStops);
        $this->forceStopsRoot = (bool)($options['force_stops_root'] ?? $forceStops);
        $this->forceStopsDescendants = (bool)($options['force_stops_descendants'] ?? $forceStops);
        $this->softAccepted = (bool)($options['soft_accepted'] ?? true);
        $this->forceAccepted = (bool)($options['force_accepted'] ?? true);
        $this->family = (string)($options['os_family'] ?? 'Windows');
        $this->linuxHandshakeConfirmed = (bool)($options['linux_handshake_confirmed'] ?? true);
        $this->throwStatusAt = array_fill_keys(array_map(
            'intval',
            is_array($options['throw_status_at'] ?? null) ? $options['throw_status_at'] : []
        ), true);
        foreach ((array)($options['descendant_pids'] ?? []) as $descendantPid) {
            $descendantPid = (int)$descendantPid;
            if ($descendantPid > 0) {
                $this->descendants[$descendantPid] = [
                    'pid' => $descendantPid,
                    'identity' => 'fake-descendant-' . $descendantPid,
                    'parent_pid' => $this->pid,
                ];
            }
        }
        $this->spoolUsageSequence = array_values(array_map(
            static fn(array $usage): array => [
                'stdout_bytes' => max(0, (int)($usage['stdout_bytes'] ?? 0)),
                'stderr_bytes' => max(0, (int)($usage['stderr_bytes'] ?? 0)),
            ],
            array_filter((array)($options['spool_usage_sequence'] ?? []), 'is_array')
        ));
        $this->spoolArtifacts = array_values(array_map('strval', (array)($options['spool_artifacts'] ?? [])));
        $this->process = (object)['type' => 'fake_process'];
        $this->pipes = [
            0 => (object)['id' => 0, 'closed' => false],
            1 => (object)['id' => 1, 'closed' => false],
            2 => (object)['id' => 2, 'closed' => false],
        ];
        $this->chunks = [
            1 => array_values(array_map('strval', (array)($options['stdout_chunks'] ?? []))),
            2 => array_values(array_map('strval', (array)($options['stderr_chunks'] ?? []))),
        ];
    }

    /** @param list<string> $args @return array<string,mixed> */
    public function open(array $args, string $cwd, array $options = []): array
    {
        return [
            'process' => $this->process,
            'pipes' => $this->pipes,
            'tree' => ['supported' => true, 'strategy' => $this->family === 'Linux' ? 'posix_process_group' : 'windows_descendant_identity_tracking'],
            'output_transport' => 'fake_bounded_transport',
            'writer_bounded' => true,
            'spool_artifacts' => $this->spoolArtifacts,
        ];
    }

    public function isProcess(mixed $process): bool
    {
        return $process === $this->process;
    }

    public function isPipe(mixed $pipe): bool
    {
        return is_object($pipe) && property_exists($pipe, 'id') && ($pipe->closed ?? true) === false;
    }

    public function closePipe(mixed $pipe): void
    {
        if ($this->isPipe($pipe)) {
            $pipe->closed = true;
            $this->closedPipeIds[] = (int)$pipe->id;
        }
    }

    public function setNonBlocking(mixed $pipe): void
    {
    }

    public function readPipe(mixed $pipe, int $length): string
    {
        if (!$this->isPipe($pipe)) {
            return '';
        }
        $pipeId = (int)$pipe->id;
        $chunk = array_shift($this->chunks[$pipeId]);
        if (!is_string($chunk) || $chunk === '') {
            return '';
        }
        if (strlen($chunk) <= $length) {
            return $chunk;
        }
        $head = substr($chunk, 0, $length);
        array_unshift($this->chunks[$pipeId], substr($chunk, $length));
        return $head;
    }

    /** @return array{running:bool,pid:int,exitcode:int} */
    public function status(mixed $process): array
    {
        $this->statusCalls++;
        if (isset($this->throwStatusAt[$this->statusCalls])) {
            throw new RuntimeException('fake_status_failure');
        }
        if ($this->rootRunning
            && $this->naturalExitAfterStatusCalls > 0
            && $this->statusCalls >= $this->naturalExitAfterStatusCalls
        ) {
            $this->rootRunning = false;
        }
        return [
            'running' => $this->rootRunning,
            'pid' => $this->pid,
            'exitcode' => $this->rootRunning ? -1 : $this->exitCode,
        ];
    }

    /** @return array<string,mixed> */
    public function observeTree(int $rootPid, mixed $process, array $knownMembers, array $context): array
    {
        $this->treeObservationCalls++;
        $members = array_values($this->descendants);
        return [
            'supported' => $this->family !== 'Linux' || $this->linuxHandshakeConfirmed,
            'strategy' => $this->family === 'Linux' ? 'posix_process_group' : 'windows_descendant_identity_tracking',
            'root_identity' => 'fake-root-' . $this->pid,
            'group_id' => $this->family === 'Linux' && $this->linuxHandshakeConfirmed ? $this->pid : 0,
            'session_id' => $this->family === 'Linux' && $this->linuxHandshakeConfirmed ? $this->pid : 0,
            'handshake_confirmed' => $this->family === 'Linux' && $this->linuxHandshakeConfirmed,
            'members' => $members,
            'survivors' => $members,
            'exited' => ($this->family !== 'Linux' || $this->linuxHandshakeConfirmed)
                && !$this->rootRunning && $members === [],
        ];
    }

    /** @return array<string,mixed> */
    public function terminateTree(int $pid, mixed $process, bool $force, array $tree, array $context): array
    {
        $this->terminationAttempts[] = ['force' => $force, 'pid' => $pid];
        $targetedPids = array_values(array_merge([$pid], array_keys($this->descendants)));
        $accepted = $force ? $this->forceAccepted : $this->softAccepted;
        if (($force && $this->forceStopsRoot) || (!$force && $this->softStopsRoot)) {
            $this->rootRunning = false;
            $this->exitCode = -1;
        }
        if (($force && $this->forceStopsDescendants) || (!$force && $this->softStopsDescendants)) {
            $this->descendants = [];
        }
        return [
            'accepted' => $accepted,
            'strategy' => $this->family === 'Linux'
                ? (($tree['handshake_confirmed'] ?? false) === true
                    ? ($force ? 'posix_process_group_and_members_kill' : 'posix_process_group_and_members_term')
                    : 'posix_identity_members_only_fail_closed')
                : ($force ? 'windows_taskkill_tracked_tree_force' : 'windows_taskkill_tracked_tree'),
            'exit_code' => $accepted ? 0 : 1,
            'targeted_pids' => $targetedPids,
        ];
    }

    /** @return array{stdout_bytes:int,stderr_bytes:int} */
    public function spoolUsage(mixed $process, array $opened): array
    {
        if ($this->spoolUsageSequence === []) {
            return ['stdout_bytes' => 0, 'stderr_bytes' => 0];
        }
        if (count($this->spoolUsageSequence) === 1) {
            return $this->spoolUsageSequence[0];
        }
        return array_shift($this->spoolUsageSequence);
    }

    public function closeProcess(mixed $process): int
    {
        $this->closeProcessCalls++;
        return $this->closeExitCode;
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function sleepMs(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->nowMs += max(0, $milliseconds);
    }

    public function osFamily(): string
    {
        return $this->family;
    }
}
