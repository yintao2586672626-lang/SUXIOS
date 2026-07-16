<?php
declare(strict_types=1);

namespace app\command;

use app\service\OtaFailureNotificationService;
use app\service\ManualOnlineFetchTaskService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

class ManualFetchOnlineDataOnce extends Command
{
    protected function configure()
    {
        $this->setName('online-data:manual-fetch-once')
            ->addOption('task-id', null, Option::VALUE_REQUIRED, 'Manual fetch task id')
            ->addOption('input', null, Option::VALUE_REQUIRED, 'Manual fetch task input JSON')
            ->setDescription('Run one manual OTA fetch task in the background');
    }

    protected function execute(Input $input, Output $output)
    {
        $taskId = trim((string)$input->getOption('task-id'));
        $inputPath = trim((string)$input->getOption('input'));
        $taskService = new ManualOnlineFetchTaskService();
        if ($taskId === '' || $inputPath === '' || !is_file($inputPath)) {
            if ($taskId !== '') {
                $taskService->markTaskFailed($taskId, 'background manual fetch task input is missing', 'input_missing');
            }
            $output->writeln('Missing task input.');
            return 1;
        }

        try {
            $task = json_decode((string)file_get_contents($inputPath), true);
        } finally {
            @unlink($inputPath);
        }
        if (!is_array($task)) {
            $taskService->markTaskFailed($taskId, 'background manual fetch task input is invalid', 'input_invalid');
            $output->writeln('Task input is not valid JSON.');
            return 1;
        }

        $taskService->markTaskRunning($taskId);

        $hotelId = (int)($task['hotel_id'] ?? 0);
        $apiUrl = trim((string)($task['api_url'] ?? ''));
        $authorization = $this->resolveAuthorization($task);
        $body = is_array($task['body'] ?? null) ? $task['body'] : [];
        if ($hotelId <= 0 || $apiUrl === '' || $authorization === '') {
            $message = 'background manual fetch task missing hotel, api_url or authorization';
            $taskService->markTaskFailed($taskId, $message, 'scope_invalid');
            $this->recordFailure($task, $message);
            $output->writeln($message);
            return 1;
        }

        $body['async'] = false;
        $body['background_task'] = true;
        $body['task_id'] = $taskId;

        $result = $this->postJson($apiUrl, $authorization, $body, (int)($task['timeout_seconds'] ?? 3600));
        $authorization = '';
        $completion = $taskService->completeTask(
            $taskId,
            is_array($result['response'] ?? null) ? $result['response'] : [],
            (string)($result['message'] ?? ''),
            ($result['success'] ?? false) === true
        );
        if (!$result['success']) {
            $message = (string)($result['message'] ?? 'background manual fetch request failed');
            $this->recordFailure($task, $message);
            $output->writeln($message);
            return ($completion['status'] ?? '') === 'partial_success' ? 2 : 1;
        }

        $status = (string)($completion['status'] ?? 'unverified');
        $output->writeln('Manual fetch task finished with status: ' . $status . '.');
        return $status === 'success' ? 0 : 2;
    }

    private function resolveAuthorization(array $task): string
    {
        $authorizationEnv = trim((string)($task['authorization_env'] ?? ''));
        if (preg_match('/^SUXI_MANUAL_FETCH_AUTH_[A-F0-9]{24}$/', $authorizationEnv) === 1) {
            $authorization = getenv($authorizationEnv);
            putenv($authorizationEnv);
            if (is_string($authorization) && trim($authorization) !== '') {
                return trim($authorization);
            }
        }

        // Compatibility for an already-created legacy task. New tasks never persist this field.
        return trim((string)($task['authorization'] ?? ''));
    }

    private function postJson(string $url, string $authorization, array $body, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'PHP curl extension is not available', 'response' => []];
        }

        $timeoutSeconds = max(60, min(7200, $timeoutSeconds));
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'failed to initialize curl', 'response' => []];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: ' . $authorization,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'message' => $error !== '' ? $error : 'curl request failed', 'response' => []];
        }

        $decoded = json_decode((string)$raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'message' => is_array($decoded) ? (string)($decoded['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode),
                'response' => is_array($decoded) ? $decoded : [],
            ];
        }

        return [
            'success' => is_array($decoded) ? (int)($decoded['code'] ?? 500) === 200 : true,
            'message' => is_array($decoded) ? (string)($decoded['message'] ?? 'ok') : 'ok',
            'response' => is_array($decoded) ? $decoded : [],
        ];
    }

    private function recordFailure(array $task, string $message): void
    {
        $hotelId = (int)($task['hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            return;
        }

        $startDate = trim((string)($task['start_date'] ?? date('Y-m-d')));
        $endDate = trim((string)($task['end_date'] ?? $startDate));
        $dataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;

        try {
            (new OtaFailureNotificationService())->recordCollectionOutcome([
                'hotel_id' => $hotelId,
                'actor_user_id' => (int)($task['user_id'] ?? 0),
                'platform' => (string)($task['platform'] ?? 'ctrip'),
                'message' => "数据日期 {$dataDate}，{$message}",
                'data_date' => $dataDate,
                'success' => false,
                'saved_count' => 0,
            ]);
        } catch (\Throwable $e) {
            // Notification failure must not hide the original background fetch failure.
        }
    }
}
