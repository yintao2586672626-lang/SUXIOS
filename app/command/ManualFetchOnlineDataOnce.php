<?php
declare(strict_types=1);

namespace app\command;

use app\model\SystemNotification;
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
        if ($taskId === '' || $inputPath === '' || !is_file($inputPath)) {
            $output->writeln('Missing task input.');
            return 1;
        }

        $task = json_decode((string)file_get_contents($inputPath), true);
        @unlink($inputPath);
        if (!is_array($task)) {
            $output->writeln('Task input is not valid JSON.');
            return 1;
        }

        $hotelId = (int)($task['hotel_id'] ?? 0);
        $apiUrl = trim((string)($task['api_url'] ?? ''));
        $authorization = trim((string)($task['authorization'] ?? ''));
        $body = is_array($task['body'] ?? null) ? $task['body'] : [];
        if ($hotelId <= 0 || $apiUrl === '' || $authorization === '') {
            $message = 'background manual fetch task missing hotel, api_url or authorization';
            $this->recordFailure($task, $message);
            $output->writeln($message);
            return 1;
        }

        $body['async'] = false;
        $body['background_task'] = true;
        $body['task_id'] = $taskId;

        $result = $this->postJson($apiUrl, $authorization, $body, (int)($task['timeout_seconds'] ?? 3600));
        if (!$result['success']) {
            $message = (string)($result['message'] ?? 'background manual fetch request failed');
            $this->recordFailure($task, $message);
            $output->writeln($message);
            return 1;
        }

        $output->writeln('Manual fetch task finished.');
        return 0;
    }

    private function postJson(string $url, string $authorization, array $body, int $timeoutSeconds): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'PHP curl extension is not available'];
        }

        $timeoutSeconds = max(60, min(7200, $timeoutSeconds));
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'failed to initialize curl'];
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
            return ['success' => false, 'message' => $error !== '' ? $error : 'curl request failed'];
        }

        $decoded = json_decode((string)$raw, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'message' => is_array($decoded) ? (string)($decoded['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode),
            ];
        }

        return [
            'success' => is_array($decoded) ? (int)($decoded['code'] ?? 500) === 200 : true,
            'message' => is_array($decoded) ? (string)($decoded['message'] ?? 'ok') : 'ok',
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
            SystemNotification::recordEvent([
                'hotel_id' => $hotelId,
                'user_id' => (int)($task['user_id'] ?? 0),
                'platform' => (string)($task['platform'] ?? 'ctrip'),
                'category' => 'capture_failed',
                'severity' => 'error',
                'title' => 'OTA 手动获取失败',
                'message' => "数据日期 {$dataDate}，{$message}",
                'action_type' => 'fetch',
                'action_payload' => [
                    'target_page' => 'online-data',
                    'target_tab' => 'data',
                    'action_label' => '重新获取',
                    'data_date' => $dataDate,
                ],
                'source_module' => 'online_data',
                'source_key' => implode(':', [
                    'online_data',
                    'manual_fetch',
                    $hotelId,
                    $dataDate,
                    'fail',
                    substr(sha1($message . '|' . ($task['task_id'] ?? '')), 0, 16),
                ]),
            ]);
        } catch (\Throwable $e) {
            // Notification failure must not hide the original background fetch failure.
        }
    }
}
