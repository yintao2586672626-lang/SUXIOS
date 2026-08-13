<?php
declare(strict_types=1);

namespace app\service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only catalog of SUXIOS recurring Windows tasks.
 *
 * The catalog exposes scheduler facts only. An enabled task or exit code 0 is
 * never promoted to a collection, delivery, or operating-outcome success.
 */
final class WindowsScheduledLoopCatalogService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const CACHE_SECONDS = 600;

    /** @var callable|null */
    private $commandReader;

    /** @var array<string, array<string, string>> */
    private const TASK_CATALOG = [
        'SUXIOS Cloud Backup Pull' => [
            'purpose' => '拉取云端数据库备份到本机',
            'category' => 'backup',
        ],
        'SUXIOS Cloud OTA Pilot H80' => [
            'purpose' => '酒店 80 云端 OTA 限定采集试运行',
            'category' => 'data_collection',
        ],
        'SUXIOS Ctrip Data Availability H80' => [
            'purpose' => '核验酒店 80 携程数据可用性',
            'category' => 'monitoring',
        ],
        'SUXIOS Daily Workbench Patrol' => [
            'purpose' => '巡检 OTA 日工作台状态',
            'category' => 'monitoring',
        ],
        'SUXIOS Hotel Autopilot Coordinator' => [
            'purpose' => '扫描酒店自动驾驶生命周期并补齐调度器',
            'category' => 'coordinator',
            'desired_state' => 'disabled',
            'risk_note' => '可能重新启用 OTA Dispatcher；当前要求保持暂停',
        ],
        'SUXIOS Meituan Temporal H80' => [
            'purpose' => '采集酒店 80 美团当日、昨日及未来经营信号',
            'category' => 'data_collection',
        ],
        'SUXIOS Operating Goal Monitor' => [
            'purpose' => '观察酒店 80 经营目标与干预证据',
            'category' => 'monitoring',
        ],
        'SUXIOS OTA Dispatcher H80' => [
            'purpose' => '采集酒店 80 携程、美团昨日最终数据',
            'category' => 'data_collection',
            'desired_state' => 'disabled',
            'risk_note' => '原设备携程登录缺失期间必须保持暂停',
        ],
        'SUXIOS OTA Realtime Dispatcher H80' => [
            'purpose' => '采集酒店 80 携程实时数据',
            'category' => 'data_collection',
        ],
        'SUXIOS Public Origin Watchdog' => [
            'purpose' => '检查并维持本地公网源站',
            'category' => 'infrastructure',
        ],
        'SUXIOS Tencent Cloud External Monitor' => [
            'purpose' => '检查腾讯云外部服务健康状态',
            'category' => 'infrastructure',
        ],
        'SUXIOS-Hotel80-TestMonitor' => [
            'purpose' => '酒店 80 测试机器人小时监控',
            'category' => 'testing',
        ],
        'Dingdandao H80' => [
            'purpose' => '采集酒店 80 订单来了完整诊断数据',
            'category' => 'data_collection',
        ],
        'Dingdandao H80 Historical D1' => [
            'purpose' => '采集酒店 80 昨日经营指标',
            'category' => 'data_collection',
        ],
        'SUXIOS Three Source WeCom H80' => [
            'purpose' => '三源数据门禁通过后尝试企业微信投递',
            'category' => 'delivery',
        ],
    ];

    public function __construct(
        ?callable $commandReader = null,
        private readonly ?string $platformFamily = null,
        private readonly ?DateTimeImmutable $observedAt = null,
        private readonly ?string $snapshotPath = null
    ) {
        $this->commandReader = $commandReader;
    }

    /**
     * @param array<int, array<string, mixed>> $hotels
     * @return array<string, mixed>
     */
    public function overview(array $hotels, bool $isSuperAdmin): array
    {
        $applicationLoops = $this->applicationLoops();
        if (($this->platformFamily ?? PHP_OS_FAMILY) !== 'Windows') {
            return $this->baseResult(
                'unsupported',
                'windows_task_scheduler_unsupported_platform',
                [],
                false,
                null,
                $applicationLoops
            );
        }

        try {
            $raw = $this->rawSnapshot();
        } catch (\Throwable) {
            return $this->baseResult(
                'unavailable',
                'windows_task_scheduler_read_failed',
                [],
                false,
                null,
                $applicationLoops
            );
        }

        $rawItems = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        $hotelMap = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = (int)($hotel['id'] ?? 0);
            if ($hotelId > 0) {
                $hotelMap[$hotelId] = (int)($hotel['tenant_id'] ?? 0);
            }
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $taskName = $this->safeText((string)($rawItem['task_name'] ?? ''), 160);
            if ($taskName === '') {
                continue;
            }
            $hotelId = $this->hotelId($taskName);
            if ($hotelId !== null && !isset($hotelMap[$hotelId])) {
                continue;
            }
            if ($hotelId === null && !$isSuperAdmin) {
                continue;
            }
            $items[] = $this->publicItem(
                $rawItem,
                $taskName,
                $hotelId,
                $hotelId !== null ? ($hotelMap[$hotelId] ?? 0) : null
            );
        }

        usort($items, static fn(array $left, array $right): int => (
            ((int)($right['enabled'] ?? false) <=> (int)($left['enabled'] ?? false))
            ?: strnatcasecmp((string)$left['name'], (string)$right['name'])
        ));

        $status = in_array((string)($raw['status'] ?? ''), ['ready', 'partial'], true)
            ? (string)$raw['status']
            : 'unavailable';
        $reasonCode = $status === 'ready'
            ? null
            : $this->safeCode((string)($raw['reason_code'] ?? 'windows_task_scheduler_read_failed'));
        $observedAt = $this->timestamp($raw['observed_at'] ?? null);

        return $this->baseResult(
            $status,
            $reasonCode,
            $items,
            ($raw['readback_verified'] ?? false) === true,
            $observedAt,
            $applicationLoops
        );
    }

    /** @return array<string, mixed> */
    private function rawSnapshot(): array
    {
        if ($this->commandReader !== null) {
            return $this->normalizeReaderResult(call_user_func($this->commandReader));
        }

        $snapshotPath = $this->snapshotPath
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime'
                . DIRECTORY_SEPARATOR . 'scheduled_loop_catalog.json';
        if (is_file($snapshotPath)) {
            $modifiedAt = filemtime($snapshotPath);
            if (is_int($modifiedAt) && (time() - $modifiedAt) < self::CACHE_SECONDS) {
                $cached = json_decode((string)file_get_contents($snapshotPath), true);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $raw = $this->readWindowsTasks();
        $directory = dirname($snapshotPath);
        if ((is_dir($directory) || @mkdir($directory, 0775, true)) && is_writable($directory)) {
            @file_put_contents(
                $snapshotPath,
                (string)json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
        return $raw;
    }

    /** @return array<string, mixed> */
    private function readWindowsTasks(): array
    {
        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'inspect_suxios_scheduled_tasks.ps1';
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
        $powershell = $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($script) || !is_file($powershell)) {
            throw new \RuntimeException('windows_task_scheduler_reader_missing');
        }

        $command = [
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-WindowStyle',
            'Hidden',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-ProjectRoot',
            $root,
        ];
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $root,
            null,
            [
                'bypass_shell' => true,
                'create_new_console' => false,
                'blocking_pipes' => false,
            ]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('windows_task_scheduler_reader_start_failed');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $deadline = microtime(true) + 8.0;
        $exitCode = null;
        do {
            $stdout .= (string)stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                throw new \RuntimeException('windows_task_scheduler_reader_timeout');
            }
            usleep(50_000);
        } while (true);
        $stdout .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if (($exitCode ?? $closedExitCode) !== 0) {
            throw new \RuntimeException('windows_task_scheduler_reader_failed');
        }

        return $this->normalizeReaderResult($stdout);
    }

    /** @return array<string, mixed> */
    private function normalizeReaderResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }
        $text = trim((string)$result);
        $prefix = 'SUXIOS_SCHEDULED_LOOPS=';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, $prefix)) {
                continue;
            }
            $decoded = json_decode(substr($line, strlen($prefix)), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        throw new \RuntimeException('windows_task_scheduler_receipt_invalid');
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function publicItem(array $raw, string $taskName, ?int $hotelId, ?int $tenantId): array
    {
        $catalog = self::TASK_CATALOG[$taskName] ?? [];
        $enabled = ($raw['enabled'] ?? false) === true;
        $rawState = strtolower(trim((string)($raw['state'] ?? '')));
        $status = !$enabled
            ? 'disabled'
            : ($rawState === 'running' ? 'running' : 'enabled');
        $lastRunAt = $this->timestamp($raw['last_run_at'] ?? null);
        $nextRunAt = $this->timestamp($raw['next_run_at'] ?? null);
        $lastResult = is_numeric($raw['last_result'] ?? null)
            ? (int)$raw['last_result']
            : null;
        $resultStatus = $lastRunAt === null || $lastResult === null
            ? 'unknown'
            : ($lastResult === 0 ? 'scheduler_completed' : 'nonzero');

        return [
            'key' => sha1((string)($raw['task_path'] ?? '') . '|' . $taskName),
            'name' => $taskName,
            'purpose' => (string)($catalog['purpose'] ?? '宿析周期任务（用途尚未登记）'),
            'category' => (string)($catalog['category'] ?? 'unregistered'),
            'catalog_status' => $catalog === [] ? 'unregistered' : 'registered',
            'source_label' => 'Windows 计划任务',
            'task_path' => $this->safeText((string)($raw['task_path'] ?? '\\'), 120),
            'frequency_label' => $this->frequencyLabel((array)($raw['triggers'] ?? [])),
            'status' => $status,
            'status_label' => match ($status) {
                'running' => '正在运行',
                'enabled' => '已启用',
                default => '已暂停',
            },
            'enabled' => $enabled,
            'hotel_id' => $hotelId,
            'tenant_id' => $tenantId,
            'scope_label' => $hotelId !== null ? '酒店 #' . $hotelId : '宿主机全局',
            'last_run_at' => $lastRunAt,
            'next_run_at' => $nextRunAt,
            'next_run_is_theoretical' => !$enabled && $nextRunAt !== null,
            'last_result_code' => $lastResult,
            'last_result_status' => $resultStatus,
            'last_result_summary' => match ($resultStatus) {
                'scheduler_completed' => '退出码 0（仅代表调度进程）',
                'nonzero' => '非零退出码 ' . $lastResult . '（未正常完成）',
                default => '结果未取得',
            },
            'desired_state' => (string)($catalog['desired_state'] ?? ''),
            'risk_note' => (string)($catalog['risk_note'] ?? ''),
            'readback_verified' => ($raw['readback_verified'] ?? false) === true,
        ];
    }

    /** @param array<int, mixed> $triggers */
    private function frequencyLabel(array $triggers): string
    {
        $times = [];
        $repeatIntervals = [];
        $repeatDurations = [];
        $hasDaily = false;
        foreach ($triggers as $trigger) {
            if (!is_array($trigger)) {
                continue;
            }
            $type = (string)($trigger['type'] ?? '');
            $hasDaily = $hasDaily || str_contains($type, 'DailyTrigger');
            $start = trim((string)($trigger['start_boundary'] ?? ''));
            if ($start !== '') {
                try {
                    $times[] = (new DateTimeImmutable($start))
                        ->setTimezone(new DateTimeZone(self::TIMEZONE))
                        ->format('H:i');
                } catch (\Throwable) {
                    // Keep the frequency truthful when a boundary is malformed.
                }
            }
            $interval = trim((string)($trigger['repetition_interval'] ?? ''));
            if ($interval !== '') {
                $repeatIntervals[] = $interval;
            }
            $duration = trim((string)($trigger['repetition_duration'] ?? ''));
            if ($duration !== '') {
                $repeatDurations[] = $duration;
            }
        }
        $times = array_values(array_unique($times));
        sort($times);
        $repeatIntervals = array_values(array_unique($repeatIntervals));
        $repeatDurations = array_values(array_unique($repeatDurations));

        if ($repeatIntervals !== []) {
            $repeat = $this->durationLabel($repeatIntervals[0]);
            $prefix = $hasDaily && $times !== [] ? '每天 ' . $times[0] . ' 起，' : '';
            $duration = $repeatDurations !== []
                ? '（持续 ' . $this->durationLabel($repeatDurations[0]) . '）'
                : '';
            return $prefix . '每 ' . $repeat . $duration;
        }
        if ($hasDaily && $times !== []) {
            return '每天 ' . implode('、', $times);
        }
        return '周期已配置，具体时间未取得';
    }

    private function durationLabel(string $isoDuration): string
    {
        try {
            $interval = new DateInterval($isoDuration);
            $parts = [];
            $days = $interval->d + ($interval->m * 30) + ($interval->y * 365);
            if ($days > 0) {
                $parts[] = $days . '天';
            }
            if ($interval->h > 0) {
                $parts[] = $interval->h . '小时';
            }
            if ($interval->i > 0) {
                $parts[] = $interval->i . '分钟';
            }
            if ($interval->s > 0) {
                $parts[] = $interval->s . '秒';
            }
            return $parts !== [] ? implode('', $parts) : $isoDuration;
        } catch (\Throwable) {
            return $isoDuration;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function applicationLoops(): array
    {
        return [
            [
                'key' => 'automation_monitor_page_poll',
                'name' => '自动化运行页刷新',
                'purpose' => '刷新当前页的门店数据、推送回执和周期任务快照',
                'source_label' => '浏览器页面',
                'frequency_label' => '仅打开本页时，每 60 秒',
                'status' => 'conditional',
                'status_label' => '页面可见时运行',
                'creates_console_window' => false,
            ],
            [
                'key' => 'scheduled_loop_catalog_readback',
                'name' => 'Windows 周期任务只读回读',
                'purpose' => '只读查询任务名称、周期、状态及最近结果，不创建或启停任务',
                'source_label' => '宿析后端',
                'frequency_label' => '按需，缓存至少 10 分钟',
                'status' => 'conditional',
                'status_label' => '本页请求时按需运行',
                'creates_console_window' => false,
            ],
            [
                'key' => 'global_notification_poll',
                'name' => '系统通知刷新',
                'purpose' => '登录后读取宿析系统通知，不执行 OTA 或经营动作',
                'source_label' => '浏览器页面',
                'frequency_label' => '登录且非核心 OTA 页时，每 120 秒',
                'status' => 'conditional',
                'status_label' => '满足页面条件时运行',
                'creates_console_window' => false,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $applicationLoops
     * @return array<string, mixed>
     */
    private function baseResult(
        string $status,
        ?string $reasonCode,
        array $items,
        bool $readbackVerified,
        ?string $observedAt,
        array $applicationLoops
    ): array {
        $enabledCount = count(array_filter(
            $items,
            static fn(array $item): bool => ($item['enabled'] ?? false) === true
        ));
        $nonzeroCount = count(array_filter(
            $items,
            static fn(array $item): bool => (string)($item['last_result_status'] ?? '') === 'nonzero'
        ));
        return [
            'schema_version' => 'suxios_scheduled_loops.v1',
            'status' => $status,
            'reason_code' => $reasonCode,
            'source' => 'windows_task_scheduler',
            'observed_at' => $observedAt,
            'readback_verified' => $readbackVerified,
            'summary' => [
                'total_count' => count($items),
                'enabled_count' => $enabledCount,
                'disabled_count' => count($items) - $enabledCount,
                'nonzero_result_count' => $nonzeroCount,
            ],
            'items' => $items,
            'application_loops' => $applicationLoops,
            'message' => match ($status) {
                'ready' => '宿析周期任务已从当前 Windows 计划任务只读回读。',
                'partial' => '部分周期任务状态未能回读；未取得的字段保持未知。',
                'unsupported' => '当前服务器不是 Windows，无法读取 Windows 计划任务。',
                default => 'Windows 周期任务当前无法读取；不会用默认任务或旧状态冒充。',
            },
            'scope_note' => '仅列出明确按日、按周或固定间隔触发的宿析任务；登录/开机启动项和短期进度动画不计入。',
            'result_note' => '已启用不等于已执行；退出码 0 只代表调度进程结束，不代表采集、推送或经营结果成功。',
            'sensitive_values_exposed' => false,
        ];
    }

    private function hotelId(string $taskName): ?int
    {
        if (preg_match('/\bH(\d+)\b/i', $taskName, $matches) === 1
            || preg_match('/Hotel(\d+)/i', $taskName, $matches) === 1
        ) {
            $hotelId = (int)$matches[1];
            return $hotelId > 0 ? $hotelId : null;
        }
        return null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        if (preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value, $matches) !== 1) {
            return null;
        }
        return str_replace('T', ' ', (string)$matches[0]);
    }

    private function safeCode(string $value): string
    {
        return preg_match('/^[a-z0-9_]+$/', $value) === 1
            ? $value
            : 'windows_task_scheduler_read_failed';
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, max(1, $limit), 'UTF-8');
    }
}
