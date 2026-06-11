<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Cache;

class AutoFetchOnlineDataOnce extends Command
{
    protected function configure()
    {
        $this->setName('online-data:auto-fetch-once')
            ->addOption('task-id', null, Option::VALUE_REQUIRED, 'Auto-fetch task id')
            ->addOption('input', null, Option::VALUE_REQUIRED, 'Auto-fetch task input JSON')
            ->setDescription('Run one OTA auto-fetch task in the background');
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
        $dataDate = trim((string)($task['data_date'] ?? date('Y-m-d')));
        $dataPeriod = trim((string)($task['data_period'] ?? 'realtime_snapshot'));
        $body = is_array($task['body'] ?? null) ? $task['body'] : [];
        $apiUrl = trim((string)($task['api_url'] ?? ''));
        $authorization = trim((string)($task['authorization'] ?? ''));
        if ($hotelId <= 0 || $apiUrl === '' || $authorization === '') {
            $message = 'background auto-fetch task missing hotel, api_url or authorization';
            $this->markFailed($hotelId, $dataDate, $dataPeriod, $message, $body);
            $output->writeln($message);
            return 1;
        }

        $body['async'] = false;
        $body['background_task'] = true;
        $body['task_id'] = $taskId;

        $result = $this->postJson($apiUrl, $authorization, $body, (int)($task['timeout_seconds'] ?? 3600));
        if (!$result['success']) {
            $message = (string)($result['message'] ?? 'background auto-fetch request failed');
            $this->markFailed($hotelId, $dataDate, $dataPeriod, $message, $body);
            $output->writeln($message);
            return 1;
        }

        $output->writeln('Auto-fetch task finished.');
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

    private function markFailed(int $hotelId, string $dataDate, string $dataPeriod, string $message, array $body): void
    {
        if ($hotelId <= 0) {
            return;
        }

        $statusKey = "online_data_auto_fetch_status_{$hotelId}";
        $status = Cache::get($statusKey, []);
        $status = is_array($status) ? $status : [];
        $runAt = date('Y-m-d H:i:s');
        $runRecord = [
            'run_at' => $runAt,
            'data_date' => $dataDate,
            'success' => false,
            'status' => 'failed',
            'message' => $message,
            'data_period' => in_array($dataPeriod, ['historical_daily', 'realtime_snapshot'], true) ? $dataPeriod : 'realtime_snapshot',
            'saved_count' => 0,
        ];
        if (!empty($body['auto_fetch_mode'])) {
            $runRecord['auto_fetch_mode'] = (string)$body['auto_fetch_mode'];
        }
        if (!empty($body['ctrip_section_concurrency']) && is_numeric($body['ctrip_section_concurrency'])) {
            $runRecord['ctrip_section_concurrency'] = max(1, min(4, (int)$body['ctrip_section_concurrency']));
        }

        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        unset($status['running_task']);
        $status['last_result'] = [
            'success' => false,
            'status' => 'failed',
            'message' => $message,
            'data_period' => $runRecord['data_period'],
            'saved_count' => 0,
        ];
        $recentRuns = is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : [];
        array_unshift($recentRuns, $runRecord);
        $status['recent_runs'] = array_slice($recentRuns, 0, 10);
        Cache::set($statusKey, $status, 86400 * 30);
    }
}
