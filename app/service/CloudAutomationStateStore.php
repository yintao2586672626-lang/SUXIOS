<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Env;

final class CloudAutomationStateStore
{
    private const MAX_DUE_SCAN = 500;

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $systemConfigured = getenv('CLOUD_AUTOMATION_STATE_DIR');
        $configured = trim((string)($baseDir
            ?? (is_string($systemConfigured) && trim($systemConfigured) !== ''
                ? $systemConfigured
                : Env::get('CLOUD_AUTOMATION_STATE_DIR', ''))));
        if ($configured === '') {
            $configured = rtrim((string)runtime_path(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'cloud_automation';
        }

        $this->baseDir = rtrim($configured, "\\/");
        if ($this->baseDir === '') {
            throw new \RuntimeException('Cloud automation state directory is invalid.');
        }

        $this->ensureDirectory($this->baseDir);
        $this->ensureDirectory($this->runsDir());
        $this->ensureDirectory($this->deliveriesDir());
    }

    /** @return resource|null */
    public function acquireLock()
    {
        $handle = @fopen($this->baseDir . DIRECTORY_SEPARATOR . 'automation.lock', 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Cloud automation lock file cannot be opened.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    public function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return array<string, mixed> */
    public function beginRun(string $mode, string $targetDate): array
    {
        $mode = $this->safeToken($mode, 'unknown');
        $run = [
            'schema_version' => 1,
            'run_id' => 'cloud_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)),
            'mode' => $mode,
            'target_date' => substr(trim($targetDate), 0, 10),
            'status' => 'running',
            'stage' => 'started',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => null,
            'summary' => [],
        ];
        $this->writeJson($this->runPath((string)$run['run_id']), $run);
        return $run;
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function finishRun(array $run, string $status, string $stage, array $summary): array
    {
        $run['status'] = $this->safeToken($status, 'failed');
        $run['stage'] = $this->safeToken($stage, 'finished');
        $run['finished_at'] = date('Y-m-d H:i:s');
        $run['summary'] = $summary;
        $this->writeJson($this->runPath((string)($run['run_id'] ?? '')), $run);
        return $run;
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function queueDelivery(
        string $kind,
        int $hotelId,
        array $identity,
        array $payload,
        array $context = []
    ): array {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('Cloud delivery hotel_id must be positive.');
        }
        if ($payload === []) {
            throw new \InvalidArgumentException('Cloud delivery payload cannot be empty.');
        }

        $kind = $this->safeToken($kind, 'message');
        $payloadHash = hash('sha256', $this->canonicalJson($payload));
        $deliveryKey = hash('sha256', $this->canonicalJson([
            'kind' => $kind,
            'hotel_id' => $hotelId,
            'identity' => $identity,
            'payload_hash' => $payloadHash,
        ]));
        $path = $this->deliveryPath($deliveryKey);
        $existing = $this->readJson($path);
        if (is_array($existing) && (string)($existing['status'] ?? '') === 'sent') {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $record = array_replace(is_array($existing) ? $existing : [], [
            'schema_version' => 1,
            'delivery_key' => $deliveryKey,
            'kind' => $kind,
            'hotel_id' => $hotelId,
            'identity' => $identity,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'context' => $context,
            'status' => (string)($existing['status'] ?? 'queued'),
            'attempts' => max(0, (int)($existing['attempts'] ?? 0)),
            'pending_robot_ids' => array_values(array_unique(array_filter(
                array_map('intval', (array)($existing['pending_robot_ids'] ?? [])),
                static fn(int $id): bool => $id > 0
            ))),
            'next_retry_at' => $existing['next_retry_at'] ?? null,
            'last_result' => is_array($existing['last_result'] ?? null) ? $existing['last_result'] : [],
            'created_at' => (string)($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ]);
        $this->writeJson($path, $record);
        return $record;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $delivery
     * @return array<string, mixed>
     */
    public function recordDeliveryAttempt(array $record, array $delivery, int $maxAttempts = 10): array
    {
        $attempts = max(0, (int)($record['attempts'] ?? 0)) + 1;
        $maxAttempts = max(1, min(50, $maxAttempts));
        $deliveryStatus = $this->safeToken((string)($delivery['delivery_status'] ?? 'failed'), 'failed');
        $failureIds = [];
        $outcomeAmbiguous = false;
        foreach ((array)($delivery['failures'] ?? []) as $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $robotId = (int)($failure['robot_id'] ?? 0);
            if ($robotId > 0) {
                $failureIds[] = $robotId;
            }
            $outcomeAmbiguous = $outcomeAmbiguous || (($failure['ambiguous'] ?? false) === true);
        }

        $sent = $deliveryStatus === 'sent';
        $retryable = !$sent && !$outcomeAmbiguous && $attempts < $maxAttempts;
        $delaySeconds = min(21600, 900 * (2 ** min(4, max(0, $attempts - 1))));
        $record['attempts'] = $attempts;
        $record['status'] = $sent
            ? 'sent'
            : ($outcomeAmbiguous ? 'delivery_outcome_unknown' : ($retryable ? $deliveryStatus : 'dead_letter'));
        $record['pending_robot_ids'] = $deliveryStatus === 'binding_missing'
            ? []
            : array_values(array_unique($failureIds));
        $record['next_retry_at'] = $retryable
            ? date('Y-m-d H:i:s', time() + $delaySeconds)
            : null;
        $record['last_result'] = [
            'delivery_status' => $deliveryStatus,
            'robot_count' => (int)($delivery['robot_count'] ?? 0),
            'sent_count' => (int)($delivery['sent_count'] ?? 0),
            'failed_count' => (int)($delivery['failed_count'] ?? 0),
            'outcome_ambiguous' => $outcomeAmbiguous,
            'attempted_at' => date('Y-m-d H:i:s'),
        ];
        $record['updated_at'] = date('Y-m-d H:i:s');
        $this->writeJson($this->deliveryPath((string)($record['delivery_key'] ?? '')), $record);
        return $record;
    }

    /**
     * Persist the ambiguous external-side-effect boundary before sending.
     * A process crash after this write is fail-closed: later requests see
     * `sending` and do not deliver the same payload again automatically.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function beginDeliveryAttempt(array $record): array
    {
        $deliveryKey = (string)($record['delivery_key'] ?? '');
        $path = $this->deliveryPath($deliveryKey);
        $current = $this->readJson($path);
        if (!is_array($current)) {
            throw new \RuntimeException('Cloud delivery record is missing before send.');
        }
        $status = (string)($current['status'] ?? '');
        if (in_array($status, ['sent', 'sending'], true)) {
            return $current;
        }
        $current['status'] = 'sending';
        $current['attempt_started_at'] = date('Y-m-d H:i:s');
        $current['updated_at'] = date('Y-m-d H:i:s');
        $this->writeJson($path, $current);
        return $current;
    }

    /** @return array<int, array<string, mixed>> */
    public function dueDeliveries(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $now = date('Y-m-d H:i:s');
        $due = [];
        foreach (array_slice($this->jsonFiles($this->deliveriesDir()), 0, self::MAX_DUE_SCAN) as $path) {
            $record = $this->readJson($path);
            if (!is_array($record)) {
                continue;
            }
            $status = (string)($record['status'] ?? '');
            if (!in_array($status, ['queued', 'failed', 'partial', 'binding_missing'], true)) {
                continue;
            }
            $nextRetryAt = trim((string)($record['next_retry_at'] ?? ''));
            if ($nextRetryAt !== '' && strcmp($nextRetryAt, $now) > 0) {
                continue;
            }
            $due[] = $record;
            if (count($due) >= $limit) {
                break;
            }
        }
        usort($due, static fn(array $left, array $right): int => strcmp(
            (string)($left['updated_at'] ?? ''),
            (string)($right['updated_at'] ?? '')
        ));
        return $due;
    }

    /** @return array<string, mixed> */
    public function statusSummary(): array
    {
        $deliveryCounts = [];
        $latestDeliveries = [];
        foreach ($this->jsonFiles($this->deliveriesDir()) as $path) {
            $record = $this->readJson($path);
            if (!is_array($record)) {
                continue;
            }
            $status = (string)($record['status'] ?? 'unknown');
            $deliveryCounts[$status] = ($deliveryCounts[$status] ?? 0) + 1;
            if (count($latestDeliveries) < 10) {
                $latestDeliveries[] = $this->publicDelivery($record);
            }
        }

        $latestRuns = [];
        foreach ($this->jsonFiles($this->runsDir()) as $path) {
            $run = $this->readJson($path);
            if (!is_array($run)) {
                continue;
            }
            $latestRuns[] = [
                'run_id' => (string)($run['run_id'] ?? ''),
                'mode' => (string)($run['mode'] ?? ''),
                'target_date' => (string)($run['target_date'] ?? ''),
                'status' => (string)($run['status'] ?? ''),
                'stage' => (string)($run['stage'] ?? ''),
                'started_at' => (string)($run['started_at'] ?? ''),
                'finished_at' => (string)($run['finished_at'] ?? ''),
            ];
            if (count($latestRuns) >= 10) {
                break;
            }
        }

        return [
            'state_dir' => $this->baseDir,
            'delivery_counts' => $deliveryCounts,
            'latest_deliveries' => $latestDeliveries,
            'latest_runs' => $latestRuns,
        ];
    }

    /** @param array<string, mixed> $record */
    private function publicDelivery(array $record): array
    {
        return [
            'delivery_key' => (string)($record['delivery_key'] ?? ''),
            'kind' => (string)($record['kind'] ?? ''),
            'hotel_id' => (int)($record['hotel_id'] ?? 0),
            'status' => (string)($record['status'] ?? ''),
            'attempts' => (int)($record['attempts'] ?? 0),
            'next_retry_at' => (string)($record['next_retry_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
        ];
    }

    private function runPath(string $runId): string
    {
        $runId = $this->safeToken($runId, 'invalid_run');
        return $this->runsDir() . DIRECTORY_SEPARATOR . $runId . '.json';
    }

    private function deliveryPath(string $deliveryKey): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $deliveryKey) !== 1) {
            throw new \InvalidArgumentException('Cloud delivery key is invalid.');
        }
        return $this->deliveriesDir() . DIRECTORY_SEPARATOR . $deliveryKey . '.json';
    }

    private function runsDir(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . 'runs';
    }

    private function deliveriesDir(): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . 'deliveries';
    }

    /** @return array<int, string> */
    private function jsonFiles(string $dir): array
    {
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        usort($files, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
        return $files;
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Cloud automation state cannot be written.');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cloud automation state cannot be committed atomically.');
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cloud automation state directory cannot be created.');
        }
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalized = $this->normalizeValue($value);
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }
        return $value;
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?? '';
        return substr($value !== '' ? $value : $fallback, 0, 80);
    }
}
