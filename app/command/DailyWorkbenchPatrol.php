<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Env;

class DailyWorkbenchPatrol extends Command
{
    protected function configure()
    {
        $this->setName('online-data:daily-workbench-patrol')
            ->addOption('base-url', null, Option::VALUE_REQUIRED, 'SUXIOS base URL, default DAILY_WORKBENCH_BASE_URL/APP_URL/http://127.0.0.1:8080')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Patrol target date, YYYY-MM-DD; default today')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Max hotels to patrol, 1-30; default 30')
            ->addOption('timeout', null, Option::VALUE_REQUIRED, 'HTTP timeout seconds, 10-600; default 180')
            ->setDescription('Generate the OTA daily workbench patrol snapshot through the protected cron endpoint');
    }

    protected function execute(Input $input, Output $output)
    {
        $token = trim((string)Env::get('CRON_TOKEN', ''));
        if ($token === '') {
            $output->writeln('CRON_TOKEN not configured; daily workbench patrol cron cannot run.');
            return 1;
        }

        $targetDate = $this->resolveTargetDate($input->getOption('target-date'));
        if ($targetDate === '') {
            $output->writeln('target-date must use YYYY-MM-DD.');
            return 1;
        }

        $limit = $this->resolveLimit($input->getOption('limit'));
        $timeout = $this->resolveTimeout($input->getOption('timeout'));
        $baseUrl = $this->resolveBaseUrl($input->getOption('base-url'));
        $url = $this->buildCronUrl($baseUrl, $targetDate, $limit);

        $output->writeln('[' . date('Y-m-d H:i:s') . '] Start OTA daily workbench patrol.');
        $result = $this->getJson($url, $token, $timeout);
        if (!$result['success']) {
            $output->writeln('Daily workbench patrol failed: ' . (string)($result['message'] ?? 'unknown error'));
            return 1;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $snapshot = is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [];
        $health = is_array($data['health'] ?? null) ? $data['health'] : [];
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];

        $output->writeln('Run ID: ' . (string)($snapshot['run_id'] ?? '-'));
        $output->writeln('Target date: ' . (string)($snapshot['target_date'] ?? $targetDate));
        $output->writeln('Health: ' . (string)($health['status'] ?? 'unknown') . ' / next_action=' . (string)($health['next_action'] ?? '-'));
        $output->writeln('Hotels: complete=' . (int)($summary['complete_hotels'] ?? 0)
            . ', incomplete=' . (int)($summary['incomplete_hotels'] ?? 0)
            . ', failed=' . (int)($summary['request_failed_hotels'] ?? 0)
            . ', actions=' . (int)($summary['next_action_count'] ?? 0));
        $output->writeln('Boundary: read existing OTA evidence only; acquisition logic and fields are unchanged.');
        $output->writeln('[' . date('Y-m-d H:i:s') . '] OTA daily workbench patrol finished.');

        return 0;
    }

    private function resolveBaseUrl($value): string
    {
        $baseUrl = trim((string)$value);
        if ($baseUrl === '') {
            $baseUrl = trim((string)(Env::get('DAILY_WORKBENCH_BASE_URL', '') ?: Env::get('APP_URL', '')));
        }
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1:8080';
        }

        return rtrim($baseUrl, '/');
    }

    private function resolveTargetDate($value): string
    {
        $targetDate = trim((string)$value);
        if ($targetDate === '') {
            return date('Y-m-d');
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) ? $targetDate : '';
    }

    private function resolveLimit($value): int
    {
        $limit = is_numeric($value) ? (int)$value : 30;
        return max(1, min(30, $limit > 0 ? $limit : 30));
    }

    private function resolveTimeout($value): int
    {
        $timeout = is_numeric($value) ? (int)$value : 180;
        return max(10, min(600, $timeout > 0 ? $timeout : 180));
    }

    private function buildCronUrl(string $baseUrl, string $targetDate, int $limit): string
    {
        return $baseUrl . '/api/online-data/daily-workbench-patrol-cron?' . http_build_query([
            'target_date' => $targetDate,
            'limit' => $limit,
        ]);
    }

    private function getJson(string $url, string $token, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'message' => 'PHP curl extension is not available'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'failed to initialize curl'];
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-Cron-Token: ' . $token,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'message' => $error !== '' ? $error : 'curl request failed'];
        }

        $decoded = json_decode((string)$raw, true);
        $message = is_array($decoded) ? (string)($decoded['message'] ?? '') : '';
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'message' => $message !== '' ? $message : ('HTTP ' . $httpCode)];
        }
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'cron endpoint returned non-JSON response'];
        }

        return [
            'success' => (int)($decoded['code'] ?? 500) === 200,
            'message' => $message !== '' ? $message : 'ok',
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }
}
