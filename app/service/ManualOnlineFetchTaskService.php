<?php
declare(strict_types=1);

namespace app\service;

final class ManualOnlineFetchTaskService
{
    private const COMMAND_NAME = 'online-data:manual-fetch-once';
    private const STATUS_FILENAME = 'status.json';
    private const STATUS_STALE_SECONDS = 7500;

    public function createTask(string $platform, int $hotelId, string $startDate, string $endDate, array $requestData, array $context): array
    {
        $requestedTaskKind = $this->normalizeTaskKind($platform);
        $platform = $this->normalizePlatform($platform);
        $authorization = trim((string)($context['authorization'] ?? ''));
        $apiUrl = $this->normalizeTaskApiUrl((string)($context['api_url'] ?? ''));
        if ($platform === '' || $hotelId <= 0 || $authorization === '' || $apiUrl === '') {
            return [];
        }

        $projectRoot = $this->projectRoot();
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        if ($phpBinary === '' || !is_file($thinkPath)) {
            return [];
        }

        $taskId = 'manual_' . $platform . '_fetch_' . $hotelId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'manual_fetch_tasks' . DIRECTORY_SEPARATOR . $taskId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        $body = $requestData;
        $body['system_hotel_id'] = $hotelId;
        $body['start_date'] = $startDate;
        $body['end_date'] = $endDate;
        $body['async'] = false;
        $body['background_task'] = true;

        $inputPath = $dir . DIRECTORY_SEPARATOR . 'input.json';
        $authorizationEnv = $this->authorizationEnvName($taskId);
        $task = [
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'user_id' => (int)($context['user_id'] ?? 0),
            'platform' => $platform,
            'task_kind' => trim((string)($context['task_kind'] ?? '')) ?: $requestedTaskKind,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'api_url' => $apiUrl,
            'authorization' => $authorization,
            'authorization_env' => $authorizationEnv,
            'body' => $body,
            'input' => $inputPath,
            'status_file' => $dir . DIRECTORY_SEPARATOR . self::STATUS_FILENAME,
            'log' => $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $persistedTask = $task;
        unset($persistedTask['authorization']);
        $encodedTask = json_encode($persistedTask, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($encodedTask) || file_put_contents($inputPath, $encodedTask) === false) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return [];
        }

        if (!$this->persistTaskStatus($taskId, [
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'user_id' => (int)($context['user_id'] ?? 0),
            'platform' => $platform,
            'task_kind' => $task['task_kind'],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'queued',
            'stage' => 'created',
            'status_text' => '已提交',
            'message' => '后台任务已创建，等待启动',
            'progress_percent' => 5,
            'saved_count' => 0,
            'readback_count' => 0,
            'readback_verified' => false,
            'quality_status' => 'unverified',
            'done' => false,
            'created_at' => $task['created_at'],
            'updated_at' => $task['created_at'],
        ])) {
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return [];
        }

        return $task;
    }

    public function launchTask(array $task): bool
    {
        $projectRoot = $this->projectRoot();
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        $inputPath = (string)($task['input'] ?? '');
        $taskId = trim((string)($task['task_id'] ?? ''));
        $authorization = trim((string)($task['authorization'] ?? ''));
        $authorizationEnv = trim((string)($task['authorization_env'] ?? ''));
        if ($phpBinary === '' || !is_file($thinkPath) || !is_file($inputPath)) {
            $this->markLaunchFailure($task, '后台任务启动条件不完整');
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        $dir = dirname($inputPath);
        if (!$this->isValidTaskId($taskId)
            || $authorization === ''
            || preg_match('/^SUXI_MANUAL_FETCH_AUTH_[A-F0-9]{24}$/', $authorizationEnv) !== 1
        ) {
            $this->markLaunchFailure($task, '后台任务范围校验失败');
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        $previousAuthorization = getenv($authorizationEnv);
        if (!putenv($authorizationEnv . '=' . $authorization)) {
            $this->markLaunchFailure($task, '后台任务授权上下文创建失败');
            $this->cleanupLaunchArtifacts($inputPath, $taskId);
            return false;
        }

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                $batPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.bat';
                $inputFile = basename($inputPath);
                $lines = [
                    '@echo off',
                    'setlocal',
                    'set "TASK_DIR=%~dp0"',
                    'pushd "%TASK_DIR%..\..\.." || exit /b 1',
                    $this->quoteWindowsBatchArg($phpBinary)
                        . ' "%CD%\think"'
                        . ' "' . self::COMMAND_NAME . '"'
                        . ' "--task-id=' . $taskId . '"'
                    . ' "--input=%TASK_DIR%' . $inputFile . '"'
                    . ' >> "%TASK_DIR%launcher.log" 2>&1',
                    'set "EXIT_CODE=%ERRORLEVEL%"',
                    'if exist "%TASK_DIR%' . $inputFile . '" del /f /q "%TASK_DIR%' . $inputFile . '"',
                    'popd',
                    'exit /b %EXIT_CODE%',
                ];
                if (file_put_contents($batPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                    $this->markLaunchFailure($task, '后台任务启动脚本写入失败');
                    $this->cleanupLaunchArtifacts($inputPath, $taskId);
                    return false;
                }
                $this->updateTaskStatus($taskId, [
                    'stage' => 'launching',
                    'message' => '正在启动后台进程',
                    'progress_percent' => 10,
                ]);
                $launched = $this->launchWindowsBatchFile($batPath);
                if (!$launched) {
                    $this->markLaunchFailure($task, '后台任务进程未成功启动');
                    $this->cleanupLaunchArtifacts($inputPath, $taskId);
                }
                return $launched;
            }

            $shellPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.sh';
            $command = 'cd ' . escapeshellarg($projectRoot)
                . ' && ' . escapeshellarg($phpBinary)
                . ' ' . escapeshellarg($thinkPath)
                . ' ' . self::COMMAND_NAME
                . ' --task-id=' . escapeshellarg($taskId)
                . ' --input=' . escapeshellarg($inputPath)
                . ' >> ' . escapeshellarg((string)($task['log'] ?? '')) . ' 2>&1';
            $shellScript = "#!/bin/sh\n"
                . $command . "\n"
                . 'exit_code=$?' . "\n"
                . 'rm -f -- ' . escapeshellarg($inputPath) . "\n"
                . 'exit $exit_code' . "\n";
            if (file_put_contents($shellPath, $shellScript) === false) {
                $this->markLaunchFailure($task, '后台任务启动脚本写入失败');
                $this->cleanupLaunchArtifacts($inputPath, $taskId);
                return false;
            }
            @chmod($shellPath, 0755);
            $this->updateTaskStatus($taskId, [
                'stage' => 'launching',
                'message' => '正在启动后台进程',
                'progress_percent' => 10,
            ]);
            $handle = @popen('sh ' . escapeshellarg($shellPath) . ' >/dev/null 2>&1 &', 'r');
            if (!is_resource($handle)) {
                $this->markLaunchFailure($task, '后台任务进程未成功启动');
                $this->cleanupLaunchArtifacts($inputPath, $taskId);
                return false;
            }
            pclose($handle);
            return true;
        } finally {
            if ($previousAuthorization === false) {
                putenv($authorizationEnv);
            } else {
                putenv($authorizationEnv . '=' . $previousAuthorization);
            }
        }
    }

    public function markTaskRunning(string $taskId): array
    {
        return $this->updateTaskStatus($taskId, [
            'status' => 'running',
            'stage' => 'requesting',
            'status_text' => '获取中',
            'message' => '正在调用已授权的 OTA 数据接口',
            'progress_percent' => 30,
            'started_at' => date('Y-m-d H:i:s'),
            'done' => false,
        ]);
    }

    public function markTaskFailed(string $taskId, string $message, string $stage = 'failed'): array
    {
        return $this->updateTaskStatus($taskId, [
            'status' => 'failed',
            'stage' => $stage,
            'status_text' => '失败',
            'message' => $this->sanitizeStatusMessage($message ?: '后台手动获取失败'),
            'progress_percent' => 100,
            'quality_status' => 'collection_failed',
            'finished_at' => date('Y-m-d H:i:s'),
            'done' => true,
        ]);
    }

    public function completeTask(string $taskId, array $response, string $message = '', bool $transportSuccess = true): array
    {
        $payload = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $responseStatus = strtolower(trim((string)(
            $payload['ui_flow_status']
            ?? $payload['flow_status']
            ?? $payload['persistence_status']
            ?? $payload['status']
            ?? ''
        )));
        $savedCount = $this->firstNonNegativeNumber($payload, [
            'saved_count', 'savedCount', 'total_saved', 'totalSavedCount', 'inserted_count', 'upserted_count',
        ]);
        $readbackCount = $this->firstNonNegativeNumber($payload, [
            'readback_count', 'readbackCount', 'database_readback_count',
        ]);
        $databaseReadback = is_array($payload['database_readback'] ?? null) ? $payload['database_readback'] : [];
        if ($readbackCount === 0) {
            $readbackCount = $this->firstNonNegativeNumber($databaseReadback, [
                'readback_count', 'matched_count', 'verified_count', 'row_count',
            ]);
        }
        $persistenceStatus = strtolower(trim((string)($payload['persistence_status'] ?? '')));
        $readbackVerified = ($payload['readback_verified'] ?? $payload['readbackVerified'] ?? false) === true
            || ($databaseReadback['verified'] ?? false) === true
            || $persistenceStatus === 'readback_verified';
        $failureStatuses = ['failed', 'error', 'exception', 'business_failed', 'rejected', 'login_required'];
        $noDataStatuses = ['no_data', 'empty', 'no_saved'];

        if (!$transportSuccess || in_array($responseStatus, $failureStatuses, true)) {
            $status = $savedCount > 0 ? 'partial_success' : 'failed';
        } elseif ($readbackVerified && ($savedCount > 0 || in_array($responseStatus, ['success', 'ok', 'completed'], true))) {
            $status = 'success';
        } elseif ($savedCount > 0) {
            $status = 'partial_success';
        } elseif (in_array($responseStatus, $noDataStatuses, true)) {
            $status = 'no_data';
        } else {
            $status = 'unverified';
        }

        $statusText = match ($status) {
            'success' => '已入库',
            'partial_success' => '部分完成',
            'no_data' => '无可入库数据',
            'failed' => '失败',
            default => '待核验',
        };
        $qualityStatus = match ($status) {
            'success' => 'available',
            'partial_success' => 'partial',
            'failed' => 'collection_failed',
            default => 'unverified',
        };
        $safeMessage = $this->sanitizeStatusMessage($message);
        if ($safeMessage === '') {
            $safeMessage = match ($status) {
                'success' => '手动获取已完成并通过数据库回读',
                'partial_success' => '手动获取已完成，但尚未确认完整入库',
                'no_data' => '平台请求已完成，未返回可入库数据',
                'failed' => '后台手动获取失败',
                default => '平台请求已完成，入库结果待核验',
            };
        }

        return $this->updateTaskStatus($taskId, [
            'status' => $status,
            'stage' => 'completed',
            'status_text' => $statusText,
            'message' => $safeMessage,
            'progress_percent' => 100,
            'saved_count' => $savedCount,
            'readback_count' => $readbackCount,
            'readback_verified' => $readbackVerified,
            'quality_status' => $qualityStatus,
            'quality_summary' => $this->buildCtripQualitySummary($payload),
            'finished_at' => date('Y-m-d H:i:s'),
            'done' => true,
        ]);
    }

    public function readTaskStatus(string $taskId): array
    {
        if (!$this->isValidTaskId($taskId)) {
            return [];
        }
        $path = $this->taskStatusPath($taskId);
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded) || (string)($decoded['task_id'] ?? '') !== $taskId) {
            return [];
        }

        $status = strtolower(trim((string)($decoded['status'] ?? 'queued')));
        if (!in_array($status, ['success', 'partial_success', 'failed', 'no_data', 'unverified'], true)
            && $this->isTaskStatusStale($decoded)
        ) {
            $finishedAt = date('Y-m-d H:i:s');
            $decoded['status'] = 'failed';
            $decoded['stage'] = 'timeout';
            $decoded['status_text'] = '已超时';
            $decoded['message'] = '后台任务超过最长执行时间，请查看失败原因后重试';
            $decoded['progress_percent'] = 100;
            $decoded['quality_status'] = 'collection_failed';
            $decoded['finished_at'] = $finishedAt;
            $decoded['updated_at'] = $finishedAt;
            $decoded['done'] = true;
            $this->persistTaskStatus($taskId, $decoded);
        }
        return $decoded;
    }

    public function publicTaskStatus(array $task): array
    {
        $allowed = [
            'task_id', 'hotel_id', 'platform', 'task_kind', 'start_date', 'end_date',
            'status', 'stage', 'status_text', 'message', 'progress_percent', 'saved_count',
            'readback_count', 'readback_verified', 'quality_status', 'quality_summary', 'done',
            'created_at', 'started_at', 'finished_at', 'updated_at',
        ];
        return array_intersect_key($task, array_flip($allowed));
    }

    public function clearTaskStatus(string $taskId): void
    {
        if ($this->isValidTaskId($taskId)) {
            @unlink($this->taskStatusPath($taskId));
        }
    }

    private function authorizationEnvName(string $taskId): string
    {
        return 'SUXI_MANUAL_FETCH_AUTH_' . strtoupper(substr(hash('sha256', $taskId), 0, 24));
    }

    private function isValidTaskId(string $taskId): bool
    {
        return preg_match('/^manual_[a-z0-9_]+_fetch_\d+_\d{14}_[a-f0-9]{8}$/', $taskId) === 1;
    }

    private function cleanupLaunchArtifacts(string $inputPath, string $taskId): void
    {
        if ($inputPath === '' || basename($inputPath) !== 'input.json') {
            return;
        }

        $taskRoot = realpath($this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'manual_fetch_tasks');
        $dir = realpath(dirname($inputPath));
        if ($taskRoot === false
            || $dir === false
            || !str_starts_with($dir, rtrim($taskRoot, "\\/") . DIRECTORY_SEPARATOR)
        ) {
            return;
        }

        @unlink($dir . DIRECTORY_SEPARATOR . 'input.json');
        if ($this->isValidTaskId($taskId)) {
            @unlink($dir . DIRECTORY_SEPARATOR . $taskId . '.bat');
            @unlink($dir . DIRECTORY_SEPARATOR . $taskId . '.sh');
        }
        if ((glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
            @rmdir($dir);
        }
    }

    private function normalizePlatform(string $platform): string
    {
        $normalized = $this->normalizeTaskKind($platform);
        if ($normalized === 'ctrip' || str_starts_with($normalized, 'ctrip_') || $normalized === 'qunar' || str_starts_with($normalized, 'qunar_')) {
            return 'ctrip';
        }
        if ($normalized === 'meituan' || str_starts_with($normalized, 'meituan_')) {
            return 'meituan';
        }
        return '';
    }

    private function normalizeTaskKind(string $platform): string
    {
        return preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($platform))) ?: '';
    }

    private function normalizeTaskApiUrl(string $url): string
    {
        $url = trim($url);
        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower(trim((string)($parts['scheme'] ?? '')));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
        $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');
        $allowedPaths = [
            '/api/online-data/fetch-ctrip',
            '/api/online-data/fetch-meituan',
            '/api/online-data/fetch-ctrip-traffic',
            '/api/online-data/fetch-ctrip-ads',
            '/api/online-data/fetch-meituan-traffic',
            '/api/online-data/fetch-meituan-orders',
            '/api/online-data/fetch-meituan-ads',
        ];
        if (!in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || !in_array($path, $allowedPaths, true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return '';
        }

        $allowedHosts = ['127.0.0.1', 'localhost', '::1'];
        foreach ([
            (string)($_SERVER['SERVER_NAME'] ?? ''),
            (string)(getenv('APP_URL') ?: ''),
            (string)($_ENV['APP_URL'] ?? ''),
        ] as $candidate) {
            $candidateHost = str_contains($candidate, '://')
                ? (string)(parse_url($candidate, PHP_URL_HOST) ?: '')
                : $candidate;
            $candidateHost = strtolower(trim($candidateHost, " \t\n\r\0\x0B[]"));
            if ($candidateHost !== '' && preg_match('/^[a-z0-9.-]+$/', $candidateHost) === 1) {
                $allowedHosts[] = $candidateHost;
            }
        }
        if (!in_array($host, array_values(array_unique($allowedHosts)), true)) {
            return '';
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        if ($port !== null && ($port <= 0 || $port > 65535)) {
            return '';
        }
        $hostForUrl = str_contains($host, ':') ? '[' . $host . ']' : $host;
        return $scheme . '://' . $hostForUrl . ($port !== null ? ':' . $port : '') . $path;
    }

    private function updateTaskStatus(string $taskId, array $changes): array
    {
        $current = $this->readTaskStatus($taskId);
        if ($current === []) {
            return [];
        }
        $terminalStatuses = ['success', 'partial_success', 'failed', 'no_data', 'unverified'];
        if (in_array(strtolower(trim((string)($current['status'] ?? ''))), $terminalStatuses, true)) {
            return $current;
        }
        if (isset($changes['progress_percent'])) {
            $changes['progress_percent'] = max(
                (int)($current['progress_percent'] ?? 0),
                (int)$changes['progress_percent']
            );
        }
        $next = array_merge($current, $changes, ['updated_at' => date('Y-m-d H:i:s')]);
        return $this->persistTaskStatus($taskId, $next) ? $next : [];
    }

    private function persistTaskStatus(string $taskId, array $status): bool
    {
        if (!$this->isValidTaskId($taskId)) {
            return false;
        }
        $path = $this->taskStatusPath($taskId);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            return false;
        }
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $temporaryPath = $path . '.tmp.' . getmypid() . '.' . $suffix;
        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            return false;
        }
        return true;
    }

    private function taskStatusPath(string $taskId): string
    {
        return $this->projectRoot()
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'manual_fetch_tasks'
            . DIRECTORY_SEPARATOR . $taskId
            . DIRECTORY_SEPARATOR . self::STATUS_FILENAME;
    }

    private function markLaunchFailure(array $task, string $message): void
    {
        $taskId = trim((string)($task['task_id'] ?? ''));
        if (!$this->isValidTaskId($taskId)) {
            $statusPath = trim((string)($task['status_file'] ?? ''));
            if ($statusPath !== '' && is_file($statusPath)) {
                $decoded = json_decode((string)file_get_contents($statusPath), true);
                $taskId = is_array($decoded) ? trim((string)($decoded['task_id'] ?? '')) : '';
            }
        }
        if ($this->isValidTaskId($taskId)) {
            $this->markTaskFailed($taskId, $message, 'launch_failed');
        }
    }

    private function isTaskStatusStale(array $task): bool
    {
        $timeText = trim((string)($task['updated_at'] ?? $task['started_at'] ?? $task['created_at'] ?? ''));
        $timestamp = $timeText !== '' ? strtotime($timeText) : false;
        return $timestamp !== false && (time() - (int)$timestamp) > self::STATUS_STALE_SECONDS;
    }

    private function firstNonNegativeNumber(array $payload, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_numeric($value) && (float)$value >= 0) {
                return (int)$value;
            }
        }
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        foreach ($keys as $key) {
            $value = $summary[$key] ?? null;
            if (is_numeric($value) && (float)$value >= 0) {
                return (int)$value;
            }
        }
        return 0;
    }

    private function buildCtripQualitySummary(array $payload): ?array
    {
        $candidates = [
            $payload['display_hotels'] ?? null,
            $payload['hotels'] ?? null,
            is_array($payload['data'] ?? null) ? ($payload['data']['display_hotels'] ?? null) : null,
        ];
        $rows = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                $rows = array_values(array_filter($candidate, 'is_array'));
                if ($rows !== []) {
                    break;
                }
            }
        }
        if ($rows === []) {
            return null;
        }

        $total = 0.0;
        $selfHotelCount = 0;
        foreach ($rows as $row) {
            foreach (['qunarDetailVisitors', 'qunar_detail_visitors', 'views', 'uv', 'visitorCount', 'detailUv'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $total += max(0, (float)$row[$key]);
                    break;
                }
            }
            if (($row['isSelf'] ?? $row['is_self'] ?? false) === true || trim((string)($row['hotelName'] ?? $row['hotel_name'] ?? '')) === '我的酒店') {
                $selfHotelCount++;
            }
        }

        return [
            'rowCount' => count($rows),
            'total' => $total,
            'ready' => count($rows) > 0 && $total > 0,
            'selfHotelCount' => $selfHotelCount,
            'competitorHotelCount' => max(0, count($rows) - $selfHotelCount),
        ];
    }

    private function sanitizeStatusMessage(string $message): string
    {
        $message = strip_tags(trim($message));
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=\-]+/i', 'Bearer ****', $message) ?: $message;
        $message = preg_replace('/((?:cookie|token|authorization|spidertoken|session)[^:=]{0,24}[:=]\s*)[^\s,;]+/i', '$1****', $message) ?: $message;
        return mb_substr($message, 0, 500);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function resolvePhpCliBinary(): string
    {
        $configured = trim((string)(getenv('PHP_CLI_BINARY') ?: env('PHP_CLI_BINARY', '')));
        $candidates = array_filter([
            $configured,
            'C:\\xampp\\php\\php.exe',
            PHP_BINARY,
            'php',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function quoteWindowsBatchArg(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function launchWindowsBatchFile(string $batPath): bool
    {
        $launcherPath = $this->createWindowsBatchLauncher($batPath);
        if ($launcherPath !== '' && $this->launchWindowsScriptHost($launcherPath)) {
            return true;
        }

        if ($launcherPath !== '' && is_file($launcherPath)) {
            @unlink($launcherPath);
        }
        $this->appendWindowsLauncherDiagnostic($batPath, 'wscript launcher did not confirm execution; falling back to cmd start.');

        return $this->launchWindowsBatchFileWithStart($batPath);
    }

    private function launchWindowsScriptHost(string $launcherPath): bool
    {
        $wscript = $this->resolveWindowsScriptHost();
        if ($wscript === '') {
            return false;
        }

        $handle = @popen($this->quoteWindowsBatchArg($wscript) . ' //B //Nologo ' . $this->quoteWindowsBatchArg($launcherPath), 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);

        for ($i = 0; $i < 15; $i++) {
            if (!is_file($launcherPath)) {
                return true;
            }
            usleep(100000);
        }

        return false;
    }

    private function launchWindowsBatchFileWithStart(string $batPath): bool
    {
        $cmd = getenv('COMSPEC') ?: 'cmd.exe';
        $command = $this->quoteWindowsBatchArg($cmd)
            . ' /d /c start "" /D '
            . $this->quoteWindowsBatchArg(dirname($batPath))
            . ' '
            . $this->quoteWindowsBatchArg($batPath);

        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            $this->appendWindowsLauncherDiagnostic($batPath, 'cmd start launcher failed to start.');
            return false;
        }
        pclose($handle);
        return true;
    }

    private function resolveWindowsScriptHost(): string
    {
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), "\\/");
        $candidates = array_filter([
            $systemRoot !== '' ? $systemRoot . '\\System32\\wscript.exe' : '',
            'C:\\Windows\\System32\\wscript.exe',
            'wscript.exe',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'wscript.exe' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function appendWindowsLauncherDiagnostic(string $batPath, string $message): void
    {
        $dir = dirname($batPath);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private function createWindowsBatchLauncher(string $batPath): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), "\\/");
        if ($tempDir === '' || !is_dir($tempDir) || !is_writable($tempDir)) {
            return '';
        }

        $launcherPath = $tempDir . DIRECTORY_SEPARATOR . 'suxi-bg-launch-' . bin2hex(random_bytes(8)) . '.vbs';
        $command = 'cmd.exe /d /c call "' . $batPath . '"';
        $script = implode("\r\n", [
            'Set sh = CreateObject("WScript.Shell")',
            'sh.Run "' . str_replace('"', '""', $command) . '", 0, False',
            'On Error Resume Next',
            'CreateObject("Scripting.FileSystemObject").DeleteFile WScript.ScriptFullName, True',
            '',
        ]);
        $encoded = $this->encodeUtf16LeWithBom($script);
        if ($encoded === '' || file_put_contents($launcherPath, $encoded) === false) {
            return '';
        }

        return $launcherPath;
    }

    private function encodeUtf16LeWithBom(string $text): string
    {
        if (function_exists('mb_convert_encoding')) {
            return "\xFF\xFE" . (string)mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-16LE', $text);
            if (is_string($converted)) {
                return "\xFF\xFE" . $converted;
            }
        }

        return '';
    }
}
