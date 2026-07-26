<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Cloud-scheduler entry for saved notifications.
 *
 * Scheduled operating-target reports always resolve the exact current
 * Asia/Shanghai business date and must pass the same data gate as an immediate
 * test. A dispatch is claimed before any external side effect.
 */
final class ManualNotificationScheduleService
{
    public const MODE_TEST = 'test';
    public const MODE_FORMAL = 'formal';
    private const TIMEZONE = 'Asia/Shanghai';
    private const DUE_GRACE_SECONDS = 300;

    /** @var callable|null */
    private $sender;

    public function __construct(
        ?callable $sender = null,
        private readonly ?OperatingTargetNotificationPayloadService $operatingTargetPayloads = null,
        private readonly ?ManualNotificationDispatchLedgerService $ledger = null
    ) {
        $this->sender = $sender;
    }

    /** @return array<string, mixed> */
    public function runDue(
        DateTimeImmutable $observedAt,
        bool $dispatch = false,
        string $mode = self::MODE_TEST,
        int $limit = 100,
        int $scopeHotelId = 0
    ): array {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::MODE_TEST, self::MODE_FORMAL], true)) {
            throw new \InvalidArgumentException('manual_notification_schedule_mode_invalid');
        }
        $now = $observedAt->setTimezone(new DateTimeZone(self::TIMEZONE));
        $limit = max(1, min(500, $limit));
        $scopeHotelId = max(0, $scopeHotelId);
        $runId = $this->startRun($mode, $dispatch, $scopeHotelId, $now);

        try {
            $query = Db::name('manual_notifications')
                ->where('enabled', 1)
                ->where('schedule_status', 'schedule_enabled')
                ->whereIn('trigger_type', ['daily_fixed_time', 'hourly_on_the_hour'])
                ->order('id', 'asc')
                ->limit($limit);
            if ($scopeHotelId > 0) {
                $query->where('hotel_id', $scopeHotelId);
            }
            $rows = $query->select()->toArray();

            $results = [];
            $dueCount = 0;
            $sentCount = 0;
            $failedCount = 0;
            $blockedCount = 0;
            foreach ($rows as $row) {
                $window = $this->dueWindow($row, $now);
                if ($window === null) {
                    continue;
                }
                $dueCount++;
                $result = $this->processDueRecord($row, $window, $now, $dispatch, $mode);
                $status = (string)($result['status'] ?? '');
                if ($status === 'sent') {
                    $sentCount++;
                } elseif (in_array($status, ['failed', 'outcome_unknown'], true)) {
                    $failedCount++;
                } elseif ($status === 'blocked') {
                    $blockedCount++;
                }
                $results[] = $result;
            }

            $summary = [
                'status' => $dispatch ? 'dispatch_checked' : 'preview',
                'mode' => $mode,
                'dispatch_requested' => $dispatch,
                'timezone' => self::TIMEZONE,
                'observed_at' => $now->format('Y-m-d H:i:s'),
                'candidate_count' => count($rows),
                'due_count' => $dueCount,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'blocked_count' => $blockedCount,
                'schedule_run_id' => $runId,
                'results' => $results,
            ];
            $this->finishRun($runId, 'completed', $summary, $now);
            return $summary;
        } catch (\Throwable $exception) {
            $this->finishRun($runId, 'failed', [
                'error_code' => 'manual_notification_schedule_failed',
                'error_message' => $this->safeText($exception->getMessage(), 180),
            ], $now);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function processDueRecord(
        array $row,
        string $window,
        DateTimeImmutable $now,
        bool $dispatch,
        string $mode
    ): array {
        $notificationId = (int)($row['id'] ?? 0);
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $businessDate = $now->format('Y-m-d');
        $base = [
            'notification_id' => $notificationId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'trigger_type' => (string)($row['trigger_type'] ?? ''),
            'dispatch_window' => $window,
            'mode' => $mode,
        ];

        $identity = $this->resolveTargetIdentity($row, $mode);
        $candidate = $this->deliveryCandidate(
            $row,
            $businessDate,
            $mode === self::MODE_TEST ? 'scheduled_test' : 'scheduled_test'
        );
        if (!$dispatch) {
            if (($identity['eligible'] ?? false) !== true) {
                return $base + [
                    'status' => 'blocked',
                    'reason_code' => (string)($identity['reason_code'] ?? 'target_binding_missing'),
                    'payload' => null,
                ];
            }
            if (($candidate['status'] ?? '') !== 'ready') {
                return $base + [
                    'status' => 'blocked',
                    'reason_code' => (string)($candidate['reason_code'] ?? 'report_gate_blocked'),
                    'payload' => null,
                    'report_gate' => $candidate['formal_send_gate'] ?? null,
                ];
            }
            return $base + [
                'status' => 'preview',
                'reason_code' => 'dispatch_not_requested',
                'target_robot_id' => (int)$identity['robot_id'],
                'target_robot_name' => (string)$identity['robot_name'],
                'payload' => $candidate['payload'],
            ];
        }

        $blockedReason = null;
        $blockedMessage = null;
        if (($identity['eligible'] ?? false) !== true) {
            $blockedReason = (string)($identity['reason_code'] ?? 'target_binding_missing');
            $blockedMessage = '测试群机器人身份或酒店绑定未通过校验。';
        } elseif (($candidate['status'] ?? '') !== 'ready' || !is_array($candidate['payload'] ?? null)) {
            $blockedReason = (string)($candidate['reason_code'] ?? 'report_gate_blocked');
            $blockedMessage = $this->candidateBlockerMessage($candidate);
        } elseif ($this->sender === null) {
            $blockedReason = 'explicit_sender_missing';
            $blockedMessage = '云端调度未注入真实发送器。';
        }

        $robotId = (int)($identity['robot_id'] ?? ManualNotificationService::TEST_ROBOT_ID);
        $robotName = (string)($identity['robot_name'] ?? ManualNotificationService::TEST_ROBOT_NAME);
        $claim = $this->dispatchLedger()->claim(
            $notificationId,
            $tenantId,
            $hotelId,
            $window,
            $mode,
            (string)($row['trigger_type'] ?? ''),
            'scheduled',
            $robotId,
            $robotName,
            $businessDate,
            $candidate,
            $now,
            $blockedReason === null ? 'claimed' : 'blocked',
            $blockedReason ?? 'dispatch_claimed',
            $blockedMessage
        );
        $claimedDispatch = $claim['dispatch'];
        if ($claim['claimed'] === false) {
            return $base + [
                'status' => 'skipped',
                'reason_code' => 'dispatch_window_already_claimed',
                'dispatch_id' => (int)$claimedDispatch['id'],
                'existing_status' => (string)$claimedDispatch['status'],
            ];
        }
        if ($blockedReason !== null) {
            return $base + [
                'status' => 'blocked',
                'reason_code' => $blockedReason,
                'dispatch_id' => (int)$claimedDispatch['id'],
                'report_gate' => $candidate['formal_send_gate'] ?? null,
            ];
        }

        $attempt = $this->dispatchLedger()->beginAttempt(
            (int)$claimedDispatch['id'],
            $now
        );
        if (($attempt['allowed'] ?? false) !== true) {
            return $base + [
                'status' => 'blocked',
                'reason_code' => (string)$attempt['reason_code'],
                'dispatch_id' => (int)$claimedDispatch['id'],
            ];
        }

        $delivery = [];
        $exception = null;
        try {
            $delivery = call_user_func(
                $this->sender,
                $hotelId,
                $robotId,
                $candidate['payload'],
                [
                    'notification_id' => $notificationId,
                    'dispatch_id' => (int)$claimedDispatch['id'],
                    'dispatch_window' => $window,
                    'business_date' => $businessDate,
                    'mode' => $mode,
                    'request_kind' => 'scheduled',
                ]
            );
            $delivery = is_array($delivery) ? $delivery : [];
        } catch (\Throwable $error) {
            $exception = $error;
        }
        $finished = $this->dispatchLedger()->finishAttempt(
            (int)$claimedDispatch['id'],
            (int)$attempt['attempt_id'],
            $delivery,
            $now,
            $exception
        );
        return $base + [
            'dispatch_id' => (int)$finished['id'],
            'status' => (string)$finished['status'],
            'reason_code' => (string)$finished['result_code'],
            'target_robot_id' => $robotId,
            'target_robot_name' => $robotName,
            'payload_fingerprint' => $finished['payload_fingerprint'] ?? null,
            'operating_target_record_id' => $finished['operating_target_record_id'] ?? null,
            'snapshot_revision_no' => $finished['snapshot_revision_no'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row */
    private function dueWindow(array $row, DateTimeImmutable $now): ?string
    {
        $triggerType = (string)($row['trigger_type'] ?? '');
        if ($triggerType === 'hourly_on_the_hour') {
            $scheduled = $now->setTime((int)$now->format('H'), 0, 0);
        } elseif ($triggerType === 'daily_fixed_time') {
            $planned = trim((string)($row['planned_send_at'] ?? ''));
            if ($planned === '') {
                return null;
            }
            try {
                $plannedAt = new DateTimeImmutable($planned, new DateTimeZone(self::TIMEZONE));
            } catch (\Throwable) {
                return null;
            }
            $scheduled = $now->setTime(
                (int)$plannedAt->format('H'),
                (int)$plannedAt->format('i'),
                0
            );
        } else {
            return null;
        }
        $delta = $now->getTimestamp() - $scheduled->getTimestamp();
        if ($delta < 0 || $delta >= self::DUE_GRACE_SECONDS) {
            return null;
        }
        return $scheduled->format('Y-m-d H:i');
    }

    /**
     * @param array<string, mixed> $row
     * @return array{eligible:bool,reason_code?:string,robot_id?:int,robot_name?:string}
     */
    private function resolveTargetIdentity(array $row, string $mode): array
    {
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $sendMethod = trim((string)($row['send_method'] ?? ''));
        if ($mode === self::MODE_TEST) {
            if ($sendMethod !== 'wecom_test') {
                return ['eligible' => false, 'reason_code' => 'test_mode_send_method_mismatch'];
            }
            $robotId = (int)($row['test_robot_id'] ?? 0);
            $robotName = trim((string)($row['test_robot_name'] ?? ''));
            if ($hotelId !== ManualNotificationService::TEST_HOTEL_ID
                || $robotId !== ManualNotificationService::TEST_ROBOT_ID
                || $robotName !== ManualNotificationService::TEST_ROBOT_NAME
            ) {
                return ['eligible' => false, 'reason_code' => 'test_target_binding_missing'];
            }
        } else {
            return ['eligible' => false, 'reason_code' => 'formal_delivery_not_authorized'];
        }
        if (!$this->robotIdentityMatches($hotelId, $robotId, $robotName)) {
            return ['eligible' => false, 'reason_code' => 'target_robot_identity_mismatch'];
        }
        return [
            'eligible' => true,
            'robot_id' => $robotId,
            'robot_name' => $robotName,
        ];
    }

    private function robotIdentityMatches(int $hotelId, int $robotId, string $robotName): bool
    {
        if (!$this->tableExists('competitor_wechat_robot')) {
            return false;
        }
        $robot = Db::name('competitor_wechat_robot')
            ->where('id', $robotId)
            ->where('store_id', $hotelId)
            ->where('name', $robotName)
            ->where('status', 1)
            ->field('id')
            ->find();
        return is_array($robot) && (int)($robot['id'] ?? 0) === $robotId;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function deliveryCandidate(
        array $row,
        string $businessDate,
        string $deliveryMode
    ): array {
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $tenantId = (int)($row['tenant_id'] ?? 0);
        $hotelName = $this->hotelName($hotelId);
        if ((string)($row['template_type'] ?? '') === ManualNotificationService::DYNAMIC_REPORT_TYPE) {
            return $this->targetPayloads()->build(
                $tenantId,
                $hotelId,
                $hotelName,
                $businessDate,
                $deliveryMode
            );
        }
        $payload = $this->buildStaticPayload($row, $businessDate, $hotelName);
        return [
            'status' => 'ready',
            'reason_code' => 'static_notification_ready',
            'business_date' => $businessDate,
            'preview_fingerprint' => hash('sha256', $this->json($payload)),
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'formal_send_gate' => null,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{msgtype:string,markdown:array{content:string}}
     */
    private function buildStaticPayload(array $row, string $businessDate, string $hotelName): array
    {
        $variables = [
            '{酒店名称}' => $hotelName,
            '{经营日期}' => $businessDate,
            '{统计时间}' => trim((string)($row['planned_send_at'] ?? '')) ?: '待配置',
            '{数据状态}' => '定时发送已启用',
        ];
        $title = strtr(trim((string)($row['title'] ?? '')), $variables);
        $body = strtr(trim((string)($row['body'] ?? '')), $variables);
        return [
            'msgtype' => 'markdown',
            'markdown' => ['content' => implode("\n", [
                '# 宿析OS｜' . $this->safeText($title, 120),
                '> 调度模式：企业微信测试群定时真实投递',
                '> 酒店：' . $this->safeText($hotelName, 80) . '（ID ' . (int)$row['hotel_id'] . '）',
                '> 业务日期：' . $businessDate,
                '',
                $this->safeMultiline($body, 5000),
                '',
                '> 未取得的数据未使用0或旧日数据补齐；正式群未授权。',
            ])],
        ];
    }

    private function hotelName(int $hotelId): string
    {
        if (!$this->tableExists('hotels')) {
            return '未取得';
        }
        $name = trim((string)(Db::name('hotels')->where('id', $hotelId)->value('name') ?? ''));
        return $name !== '' ? $name : '未取得';
    }

    /** @param array<string, mixed> $candidate */
    private function candidateBlockerMessage(array $candidate): string
    {
        $messages = [];
        foreach ((array)($candidate['formal_send_gate']['blockers'] ?? []) as $blocker) {
            if (is_array($blocker) && trim((string)($blocker['message'] ?? '')) !== '') {
                $messages[] = trim((string)$blocker['message']);
            }
        }
        return $messages === []
            ? '经营目标报告门禁未通过。'
            : implode('；', array_slice($messages, 0, 3));
    }

    private function startRun(
        string $mode,
        bool $dispatch,
        int $scopeHotelId,
        DateTimeImmutable $now
    ): ?int {
        if (!$this->tableExists('manual_notification_schedule_runs')) {
            return null;
        }
        $timestamp = $now->format('Y-m-d H:i:s');
        $id = (int)Db::name('manual_notification_schedule_runs')->insertGetId([
            'runner_mode' => $mode,
            'dispatch_requested' => $dispatch ? 1 : 0,
            'scope_hotel_id' => $scopeHotelId > 0 ? $scopeHotelId : null,
            'observed_at' => $timestamp,
            'status' => 'running',
            'candidate_count' => 0,
            'due_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => null,
            'started_at' => $timestamp,
            'finished_at' => null,
            'create_time' => $timestamp,
            'update_time' => $timestamp,
        ]);
        return $id > 0 ? $id : null;
    }

    /** @param array<string, mixed> $summary */
    private function finishRun(
        ?int $runId,
        string $status,
        array $summary,
        DateTimeImmutable $now
    ): void {
        if ($runId === null || $runId <= 0) {
            return;
        }
        $timestamp = $now->format('Y-m-d H:i:s');
        $publicResults = [];
        foreach ((array)($summary['results'] ?? []) as $result) {
            if (!is_array($result)) {
                continue;
            }
            $publicResults[] = [
                'notification_id' => (int)($result['notification_id'] ?? 0),
                'dispatch_id' => (int)($result['dispatch_id'] ?? 0),
                'business_date' => (string)($result['business_date'] ?? ''),
                'dispatch_window' => (string)($result['dispatch_window'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'reason_code' => (string)($result['reason_code'] ?? ''),
            ];
        }
        $resultSummary = [
            'error_code' => (string)($summary['error_code'] ?? ''),
            'error_message' => (string)($summary['error_message'] ?? ''),
            'results' => $publicResults,
        ];
        Db::name('manual_notification_schedule_runs')->where('id', $runId)->update([
            'status' => $status,
            'candidate_count' => (int)($summary['candidate_count'] ?? 0),
            'due_count' => (int)($summary['due_count'] ?? 0),
            'sent_count' => (int)($summary['sent_count'] ?? 0),
            'failed_count' => (int)($summary['failed_count'] ?? 0),
            'blocked_count' => (int)($summary['blocked_count'] ?? 0),
            'result_summary_json' => $this->json($resultSummary),
            'finished_at' => $timestamp,
            'update_time' => $timestamp,
        ]);
    }

    private function targetPayloads(): OperatingTargetNotificationPayloadService
    {
        return $this->operatingTargetPayloads ?? new OperatingTargetNotificationPayloadService();
    }

    private function dispatchLedger(): ManualNotificationDispatchLedgerService
    {
        return $this->ledger ?? new ManualNotificationDispatchLedgerService();
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace(
            '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            trim($value)
        ) ?? '';
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function safeMultiline(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        return mb_substr(trim($value), 0, $limit, 'UTF-8');
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_schedule_json_failed');
        }
        return $json;
    }
}
