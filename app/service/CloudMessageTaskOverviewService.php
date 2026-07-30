<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Read-only overview of hotel-scoped automatic WeCom tasks.
 *
 * Live Linux deployments are verified against allow-listed systemd units.
 * Development environments may read a timestamped, name-matched snapshot;
 * stale snapshots are never presented as currently active.
 */
final class CloudMessageTaskOverviewService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const SNAPSHOT_MAX_AGE_SECONDS = 21600;

    /** @var callable|null */
    private $unitReader;

    /** @var callable|null */
    private $stateSummaryReader;

    public function __construct(
        ?callable $unitReader = null,
        ?callable $stateSummaryReader = null,
        private readonly ?DateTimeImmutable $observedAt = null,
        private readonly ?string $snapshotPath = null,
        private readonly ?ManualNotificationScheduleRuleService $scheduleRuleService = null
    ) {
        $this->unitReader = $unitReader;
        $this->stateSummaryReader = $stateSummaryReader;
    }

    /** @return array<string, mixed> */
    public function overview(int $tenantId, int $hotelId): array
    {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new \InvalidArgumentException('cloud_message_task_scope_invalid');
        }

        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->field('id,tenant_id,name,status')
            ->find();
        if (!is_array($hotel)) {
            throw new \RuntimeException('cloud_message_task_hotel_unavailable');
        }

        $now = $this->now();
        $robot = $this->targetRobot($hotelId);
        $liveTasks = $this->liveTasks($hotel, $robot);
        $manualTasks = $this->manualScheduleTasks($tenantId, $hotelId, $robot, $now);

        $sourceStatus = 'live';
        $sourceLabel = '腾讯云 systemd 实时状态';
        $observedAt = $now->format('Y-m-d H:i:s');
        $identityNote = '';
        $isStale = false;

        if ($liveTasks === []) {
            $snapshot = $this->verifiedSnapshot((string)$hotel['name'], $hotelId, $now);
            if ($snapshot !== null) {
                $liveTasks = (array)$snapshot['tasks'];
                $sourceStatus = (string)$snapshot['source_status'];
                $sourceLabel = (string)$snapshot['source_label'];
                $observedAt = (string)$snapshot['observed_at'];
                $identityNote = (string)$snapshot['identity_note'];
                $isStale = (bool)$snapshot['is_stale'];
            } elseif ($manualTasks !== []) {
                $sourceStatus = 'database_only';
                $sourceLabel = '宿析OS数据库';
            } else {
                $sourceStatus = 'not_connected';
                $sourceLabel = '当前环境未连接云端调度状态';
            }
        }

        $tasks = array_values(array_merge($liveTasks, $manualTasks));
        $activeCount = count(array_filter(
            $tasks,
            static fn(array $task): bool => (string)($task['status'] ?? '') === 'active'
        ));
        $conditionalCount = count(array_filter(
            $tasks,
            static fn(array $task): bool => (string)($task['delivery_mode'] ?? '') !== 'scheduled_send'
        ));

        return [
            'source_status' => $sourceStatus,
            'source_label' => $sourceLabel,
            'observed_at' => $observedAt,
            'is_stale' => $isStale,
            'hotel' => [
                'id' => (int)$hotel['id'],
                'name' => (string)$hotel['name'],
            ],
            'identity_note' => $identityNote,
            'task_count' => count($tasks),
            'active_count' => $activeCount,
            'conditional_count' => $conditionalCount,
            'tasks' => $tasks,
            'message' => $tasks === []
                ? '当前酒店没有取得已启用的自动发送任务证据。'
                : '固定发送、数据门禁和条件提醒分开展示；任务成功不等于每轮都产生新消息。',
        ];
    }

    /**
     * @param array<string, mixed> $hotel
     * @param array<string, mixed>|null $robot
     * @return array<int, array<string, mixed>>
     */
    private function liveTasks(array $hotel, ?array $robot): array
    {
        $hotelId = (int)$hotel['id'];
        $hourlyTimer = $this->readUnit('suxios-cloud-hotel-monitor-formal.timer');
        $dailyTimer = $this->readUnit("suxios-cloud-hotel-daily@{$hotelId}.timer");
        $healthTimer = $this->readUnit("suxios-cloud-hotel-health@{$hotelId}.timer");
        $retryTimer = $this->readUnit('suxios-cloud-retry.timer');

        $hourlyAudit = $this->latestAudit($hotelId, 'wechat_monitor', 'hourly_formal_broadcast');
        $cloudDelivery = $this->latestAudit($hotelId, 'cloud_automation', 'deliver_message');
        $tasks = [];

        if ($this->unitActive($hourlyTimer) && $hourlyAudit !== null) {
            $hourlyService = $this->readUnit('suxios-cloud-hotel-monitor-formal.service');
            $deliveryStatus = (string)($hourlyAudit['extra']['delivery_status'] ?? '');
            $tasks[] = $this->task(
                'hourly_operating_monitor',
                '每小时经营监控',
                '每小时整点',
                'scheduled_send',
                '每小时发送一条文字经营监控；不发送图片。',
                $hourlyTimer,
                $hourlyService,
                $robot,
                $deliveryStatus === 'sent' ? '已发送' : '发送结果待核验',
                (string)($hourlyAudit['create_time'] ?? '')
            );
        }

        if ($this->unitActive($dailyTimer)) {
            $dailyService = $this->readUnit("suxios-cloud-hotel-daily@{$hotelId}.service");
            $deliveryLabel = '任务完成，发送结果未取得';
            if ($cloudDelivery !== null
                && $this->withinMinutes(
                    (string)($cloudDelivery['create_time'] ?? ''),
                    $this->timestamp((string)($dailyTimer['LastTriggerUSec'] ?? '')),
                    10
                )
            ) {
                $kind = (string)($cloudDelivery['extra']['kind'] ?? '');
                $sent = (string)($cloudDelivery['extra']['delivery_status'] ?? '') === 'sent';
                $deliveryLabel = $kind === 'data_health_alert' && $sent
                    ? '日报门禁阻断，健康提醒已发送'
                    : ($sent ? '经营日报已发送' : '发送结果未确认');
            }
            $tasks[] = $this->task(
                'daily_operating_report',
                '每日经营日报',
                '每日 09:00（最多随机延后 3 分钟）',
                'data_gate',
                '先校验同门店、同日期 OTA 事实；日报门禁不通过时只发送真实缺口提醒。',
                $dailyTimer,
                $dailyService,
                $robot,
                $deliveryLabel
            );
        }

        if ($this->unitActive($healthTimer)) {
            $healthService = $this->readUnit("suxios-cloud-hotel-health@{$hotelId}.service");
            $tasks[] = $this->task(
                'data_health_alert',
                '数据健康预警',
                '每日 09:10、14:10、20:10（最多随机延后 3 分钟）',
                'conditional_alert',
                '仅在数据缺口或健康异常时发送；相同提醒已送达时不重复发送。',
                $healthTimer,
                $healthService,
                $robot,
                $this->serviceSucceeded($healthService)
                    ? '巡检完成，本轮未记录新的重复发送'
                    : '最近巡检结果待核验'
            );
        }

        if ($tasks !== [] && $this->unitActive($retryTimer)) {
            $retryService = $this->readUnit('suxios-cloud-automation@retry.service');
            $pending = $this->pendingDeliveryCount();
            $tasks[] = $this->task(
                'failed_delivery_retry',
                '失败消息自动重试',
                '每 15 分钟检查一次',
                'failure_retry',
                '只重试已保存的失败消息，不重新采集 OTA 数据，也不重新生成报告。',
                $retryTimer,
                $retryService,
                $robot,
                $pending === 0 ? '当前没有待重试失败消息' : "当前有 {$pending} 条非成功记录待处理"
            );
        }

        return $tasks;
    }

    /**
     * @param array<string, string> $timer
     * @param array<string, string> $service
     * @param array<string, mixed>|null $robot
     * @return array<string, mixed>
     */
    private function task(
        string $key,
        string $name,
        string $schedule,
        string $deliveryMode,
        string $deliveryRule,
        array $timer,
        array $service,
        ?array $robot,
        string $lastResult,
        string $lastRunAt = ''
    ): array {
        $active = $this->unitActive($timer);
        return [
            'key' => $key,
            'name' => $name,
            'status' => $active ? 'active' : 'inactive',
            'status_label' => $active ? '运行中' : '未启用',
            'schedule' => $schedule,
            'delivery_mode' => $deliveryMode,
            'delivery_rule' => $deliveryRule,
            'target_robot_id' => $robot === null ? null : (int)$robot['id'],
            'target_robot_name' => $robot === null ? '机器人绑定未取得' : (string)$robot['name'],
            'last_run_at' => $lastRunAt !== ''
                ? $lastRunAt
                : $this->timestamp((string)($timer['LastTriggerUSec'] ?? '')),
            'next_run_at' => $this->timestamp((string)($timer['NextElapseUSecRealtime'] ?? '')),
            'last_result' => $lastResult,
            'service_result' => $this->serviceSucceeded($service) ? 'success' : 'unverified',
            'source' => 'systemd',
            'source_label' => '腾讯云 systemd + 宿析OS发送回执',
        ];
    }

    /**
     * @param array<string, mixed>|null $robot
     * @return array<int, array<string, mixed>>
     */
    private function manualScheduleTasks(
        int $tenantId,
        int $hotelId,
        ?array $robot,
        DateTimeImmutable $now
    ): array
    {
        try {
            $rows = Db::name('manual_notifications')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->order('id', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        return array_map(function (array $row) use ($robot, $now): array {
            $status = (string)($row['schedule_status'] ?? '');
            $enabled = (int)($row['enabled'] ?? 0) === 1;
            $active = $enabled && $status === 'schedule_enabled';
            $trigger = (string)($row['trigger_type'] ?? '');
            $sourceScope = (string)($row['source_scope'] ?? 'combined');
            $nextRunAt = $active
                ? $this->scheduleRules()->nextRunAt($row, $now)
                : null;
            return [
                'key' => 'manual_notification_' . (int)$row['id'],
                'notification_id' => (int)$row['id'],
                'notification_type' => (string)($row['notification_type'] ?? ''),
                'template_type' => (string)($row['template_type'] ?? $row['notification_type'] ?? ''),
                'source_scope' => $sourceScope,
                'source_scope_label' => $this->sourceScopeLabel($sourceScope),
                'content_sections' => $this->listValue($row['content_sections'] ?? ''),
                'business_date_rule' => (string)($row['business_date_rule'] ?? 'today'),
                'trigger_type' => $trigger,
                'name' => trim((string)($row['title'] ?? '')) ?: ('自定义定时消息 #' . (int)$row['id']),
                'status' => $active ? 'active' : ($enabled ? 'attention' : 'paused'),
                'status_label' => $active
                    ? '运行中'
                    : ($enabled ? '等待真实测试' : '已暂停'),
                'plan_status' => $status,
                'plan_status_label' => $active
                    ? '已启用'
                    : ($enabled ? '待测试' : '已暂停'),
                'schedule' => $this->manualScheduleLabel($row),
                'delivery_mode' => 'manual_schedule',
                'delivery_rule' => $this->sourceScopeLabel($sourceScope)
                    . ' · '
                    . ((string)($row['business_date_rule'] ?? 'today') === 'yesterday'
                        ? '发送昨日数据'
                        : '发送当天数据'),
                'target_robot_id' => (int)($row['test_robot_id'] ?? ($robot['id'] ?? 0)) ?: null,
                'target_robot_name' => trim((string)($row['test_robot_name'] ?? ''))
                    ?: (string)($robot['name'] ?? '机器人绑定未取得'),
                'last_run_at' => (string)($row['last_tested_at'] ?? ''),
                'next_run_at' => $nextRunAt,
                'last_result' => $active
                    ? '计划已启用；实际发送以回执为准'
                    : ($status === 'test_verified'
                        ? '测试发送已验证，计划当前暂停'
                        : ($status === 'awaiting_test'
                            ? '等待一次真实测试成功'
                            : '尚未取得成功发送回执')),
                'service_result' => $active ? 'configured' : 'unverified',
                'source' => 'database',
                'source_label' => '宿析OS通知配置',
                'editable' => true,
                'edit_label' => '编辑计划',
            ];
        }, $rows);
    }

    /** @param array<string, mixed> $row */
    private function manualScheduleLabel(array $row): string
    {
        $trigger = (string)($row['trigger_type'] ?? '');
        if ($trigger === 'daily_fixed_time') {
            return '每日 ' . ($this->shortTime($row['planned_send_at'] ?? '') ?: '时间待配置');
        }
        if ($trigger === 'hourly_on_the_hour') {
            return ($this->shortTime($row['hourly_start_time'] ?? '') ?: '09:00')
                . '－'
                . ($this->shortTime($row['hourly_end_time'] ?? '') ?: '22:00')
                . ' 每小时整点';
        }
        if ($trigger === 'interval_minutes') {
            return '从 '
                . ($this->shortTime($row['hourly_start_time'] ?? '') ?: '09:00')
                . ' 起，每 '
                . max(5, (int)($row['interval_minutes'] ?? 60))
                . ' 分钟';
        }
        return '手动测试';
    }

    private function sourceScopeLabel(string $scope): string
    {
        return match ($scope) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'dingdandao_pms', 'dingdandao', 'pms' => '订单来了 PMS',
            default => 'PMS＋OTA 三源',
        };
    }

    /** @return list<string> */
    private function listValue(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $parts
        )));
    }

    private function shortTime(mixed $value): string
    {
        return preg_match('/(\d{2}:\d{2})/', trim((string)$value), $matches) === 1
            ? $matches[1]
            : '';
    }

    private function scheduleRules(): ManualNotificationScheduleRuleService
    {
        return $this->scheduleRuleService ?? new ManualNotificationScheduleRuleService();
    }

    /** @return array<string, mixed>|null */
    private function targetRobot(int $hotelId): ?array
    {
        $hourly = $this->latestAudit($hotelId, 'wechat_monitor', 'hourly_formal_broadcast');
        $robotId = (int)($hourly['extra']['robot_id'] ?? 0);
        try {
            $query = Db::name('competitor_wechat_robot')
                ->where('store_id', $hotelId)
                ->where('status', 1);
            if ($robotId > 0) {
                $query->where('id', $robotId);
            }
            $robot = $query->field('id,store_id,name,status')->order('id')->find();
            return is_array($robot) ? $robot : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function latestAudit(int $hotelId, string $module, string $action): ?array
    {
        try {
            $row = Db::name('operation_logs')
                ->where('hotel_id', $hotelId)
                ->where('module', $module)
                ->where('action', $action)
                ->field('id,module,action,hotel_id,error_info,extra_data,create_time')
                ->order('id', 'desc')
                ->find();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $extra = json_decode((string)($row['extra_data'] ?? ''), true);
        $row['extra'] = is_array($extra) ? $extra : [];
        unset($row['extra_data'], $row['error_info']);
        return $row;
    }

    /** @return array<string, string> */
    private function readUnit(string $unit): array
    {
        if (preg_match('/^suxios-[A-Za-z0-9@_.-]+\.(timer|service)$/', $unit) !== 1) {
            return [];
        }
        if ($this->unitReader !== null) {
            $result = ($this->unitReader)($unit);
            return is_array($result) ? array_map('strval', $result) : [];
        }
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('proc_open')) {
            return [];
        }

        $properties = [
            'LoadState',
            'ActiveState',
            'UnitFileState',
            'LastTriggerUSec',
            'NextElapseUSecRealtime',
            'Result',
            'ExecMainStatus',
            'InactiveEnterTimestamp',
        ];
        $command = array_merge(
            ['systemctl', 'show', $unit, '--no-pager'],
            array_map(static fn(string $property): string => '--property=' . $property, $properties)
        );
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return [];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || trim((string)$stderr) !== '') {
            return [];
        }

        $result = [];
        foreach (preg_split('/\R/', (string)$stdout) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $result[trim($key)] = trim($value);
        }
        return $result;
    }

    /** @param array<string, string> $unit */
    private function unitActive(array $unit): bool
    {
        return ($unit['LoadState'] ?? '') === 'loaded'
            && ($unit['ActiveState'] ?? '') === 'active';
    }

    /** @param array<string, string> $service */
    private function serviceSucceeded(array $service): bool
    {
        return ($service['Result'] ?? '') === 'success'
            && in_array((string)($service['ExecMainStatus'] ?? '0'), ['', '0'], true);
    }

    private function pendingDeliveryCount(): int
    {
        $summary = $this->stateSummary();
        $pending = 0;
        foreach ((array)($summary['delivery_counts'] ?? []) as $status => $count) {
            if ((string)$status !== 'sent') {
                $pending += max(0, (int)$count);
            }
        }
        return $pending;
    }

    /** @return array<string, mixed> */
    private function stateSummary(): array
    {
        if ($this->stateSummaryReader !== null) {
            $summary = ($this->stateSummaryReader)();
            return is_array($summary) ? $summary : [];
        }
        $configured = trim((string)(getenv('CLOUD_AUTOMATION_STATE_DIR') ?: ''));
        $candidates = array_values(array_unique(array_filter([
            $configured,
            DIRECTORY_SEPARATOR === '/' ? '/var/lib/suxios-cloud-automation' : '',
        ])));
        foreach ($candidates as $path) {
            if (!is_dir($path . DIRECTORY_SEPARATOR . 'deliveries')
                || !is_dir($path . DIRECTORY_SEPARATOR . 'runs')) {
                continue;
            }
            try {
                return (new CloudAutomationStateStore($path))->statusSummary();
            } catch (\Throwable) {
                continue;
            }
        }
        return [];
    }

    /** @return array<string, mixed>|null */
    private function verifiedSnapshot(string $hotelName, int $selectedHotelId, DateTimeImmutable $now): ?array
    {
        $path = trim((string)($this->snapshotPath
            ?? (rtrim((string)runtime_path(), "\\/")
                . DIRECTORY_SEPARATOR . 'cloud_automation'
                . DIRECTORY_SEPARATOR . 'message_task_overview.json')));
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded) || !is_array($decoded['hotels'] ?? null)) {
            return null;
        }
        foreach ($decoded['hotels'] as $candidate) {
            if (!is_array($candidate)
                || trim((string)($candidate['name'] ?? '')) !== trim($hotelName)
                || !is_array($candidate['tasks'] ?? null)) {
                continue;
            }
            $observedAt = trim((string)($decoded['observed_at'] ?? ''));
            $observed = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $observedAt,
                new DateTimeZone(self::TIMEZONE)
            );
            if (!$observed instanceof DateTimeImmutable) {
                return null;
            }
            $isStale = ($now->getTimestamp() - $observed->getTimestamp()) > self::SNAPSHOT_MAX_AGE_SECONDS;
            $tasks = [];
            foreach ($candidate['tasks'] as $task) {
                if (!is_array($task)) {
                    continue;
                }
                $tasks[] = $this->sanitizeSnapshotTask($task, $isStale);
            }
            return [
                'source_status' => $isStale ? 'stale_snapshot' : 'verified_snapshot',
                'source_label' => $isStale ? '云端核验快照已过期' : '云端核验快照',
                'observed_at' => $observedAt,
                'is_stale' => $isStale,
                'identity_note' => '云端酒店 '
                    . (int)($candidate['source_hotel_id'] ?? 0)
                    . ' 与本地酒店 '
                    . $selectedHotelId
                    . ' 按名称“'
                    . $hotelName
                    . '”核验映射。',
                'tasks' => $tasks,
            ];
        }
        return null;
    }

    /** @param array<string, mixed> $task @return array<string, mixed> */
    private function sanitizeSnapshotTask(array $task, bool $isStale): array
    {
        $strings = [
            'key',
            'name',
            'status',
            'status_label',
            'schedule',
            'delivery_mode',
            'delivery_rule',
            'target_robot_name',
            'last_run_at',
            'next_run_at',
            'last_result',
            'service_result',
            'source',
            'source_label',
        ];
        $safe = [];
        foreach ($strings as $key) {
            $safe[$key] = mb_substr(trim((string)($task[$key] ?? '')), 0, 240, 'UTF-8');
        }
        $safe['target_robot_id'] = isset($task['target_robot_id'])
            ? max(0, (int)$task['target_robot_id'])
            : null;
        if ($isStale) {
            $safe['status'] = 'stale';
            $safe['status_label'] = '状态待复核';
        }
        return $safe;
    }

    private function timestamp(string $value): string
    {
        return preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $value, $matches) === 1
            ? $matches[1]
            : '';
    }

    private function withinMinutes(string $left, string $right, int $minutes): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }
        try {
            $timezone = new DateTimeZone(self::TIMEZONE);
            $leftAt = new DateTimeImmutable($left, $timezone);
            $rightAt = new DateTimeImmutable($right, $timezone);
            return abs($leftAt->getTimestamp() - $rightAt->getTimestamp()) <= ($minutes * 60);
        } catch (\Throwable) {
            return false;
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->observedAt ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
    }
}
