<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Account-owned cloud delivery policy. It schedules already verified delivery
 * implementations; it never starts OTA collection or reads browser sessions.
 */
final class CloudWechatPushOrchestratorService
{
    private const POLICY_TABLE = 'account_wechat_push_policies';
    private const BINDING_SCOPE = 'account_onboarding';
    private const TEMPLATE_HOURLY_MONITOR = 'hourly_monitor';

    /** @var callable|null */
    private $hourlyDispatcher;

    /** @var callable|null */
    private $visualCardDispatcher;

    /** @var callable|null */
    private $failureAlertDispatcher;

    public function __construct(
        private readonly ?CloudAutomationStateStore $stateStore = null,
        private readonly ?WechatRobotDeliveryService $deliveryService = null,
        ?callable $hourlyDispatcher = null,
        ?callable $visualCardDispatcher = null,
        ?callable $failureAlertDispatcher = null,
    ) {
        $this->hourlyDispatcher = $hourlyDispatcher;
        $this->visualCardDispatcher = $visualCardDispatcher;
        $this->failureAlertDispatcher = $failureAlertDispatcher;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function savePolicy(int $hotelId, int $userId, array $input): array
    {
        $robotId = $this->positiveInt($input['robot_id'] ?? null);
        $failureRobotId = $this->positiveInt($input['failure_robot_id'] ?? null);
        $frequency = strtolower(trim((string)($input['frequency'] ?? 'hourly')));
        $templateKey = strtolower(trim((string)($input['template_key'] ?? self::TEMPLATE_HOURLY_MONITOR)));
        if ($hotelId <= 0 || $userId <= 0 || $robotId === null
            || !in_array($frequency, ['hourly', 'daily'], true)
            || $templateKey !== self::TEMPLATE_HOURLY_MONITOR
            || ($failureRobotId !== null && $failureRobotId === $robotId)) {
            throw new \InvalidArgumentException('wechat_push_policy_input_invalid');
        }

        $this->assertOwnedRobot($hotelId, $userId, $robotId);
        if ($failureRobotId !== null) {
            $this->assertOwnedRobot($hotelId, $userId, $failureRobotId);
        }

        $values = [
            'hotel_id' => $hotelId,
            'owner_user_id' => $userId,
            'robot_id' => $robotId,
            'failure_robot_id' => $failureRobotId,
            'frequency' => $frequency,
            'template_key' => $templateKey,
            'visual_card_enabled' => $this->truthy($input['visual_card_enabled'] ?? false) ? 1 : 0,
            'failure_alert_enabled' => $this->truthy($input['failure_alert_enabled'] ?? false) ? 1 : 0,
            'status' => $this->truthy($input['enabled'] ?? true) ? 1 : 0,
            'timezone' => 'Asia/Shanghai',
            'update_time' => date('Y-m-d H:i:s'),
        ];

        return Db::transaction(function () use ($hotelId, $userId, $templateKey, $values): array {
            $tenantId = $this->hotelTenantIdForPolicy($hotelId);
            if ($tenantId !== null) {
                $values['tenant_id'] = $tenantId;
            }
            $existing = Db::name(self::POLICY_TABLE)
                ->where('hotel_id', $hotelId)
                ->where('owner_user_id', $userId)
                ->where('template_key', $templateKey)
                ->find();
            if (is_array($existing)) {
                Db::name(self::POLICY_TABLE)->where('id', (int)$existing['id'])->update($values);
                $saved = Db::name(self::POLICY_TABLE)->where('id', (int)$existing['id'])->find();
            } else {
                $values['create_time'] = date('Y-m-d H:i:s');
                $id = (int)Db::name(self::POLICY_TABLE)->insertGetId($values);
                $saved = $id > 0 ? Db::name(self::POLICY_TABLE)->where('id', $id)->find() : null;
            }
            if (!is_array($saved)) {
                throw new \RuntimeException('wechat_push_policy_save_failed');
            }
            return $this->publicPolicy($saved);
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function policiesForAccount(int $hotelId, int $userId): array
    {
        if ($hotelId <= 0 || $userId <= 0) {
            return [];
        }
        return array_map(
            fn(array $policy): array => $this->publicPolicy($policy),
            Db::name(self::POLICY_TABLE)
                ->where('hotel_id', $hotelId)
                ->where('owner_user_id', $userId)
                ->order('id', 'desc')
                ->select()
                ->toArray()
        );
    }

    /**
     * @return array{observed_at:string,push:bool,policies:array<int,array<string,mixed>>}
     */
    public function runDue(?DateTimeImmutable $observedAt = null, bool $push = false, int $limit = 50): array
    {
        $now = ($observedAt ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $limit = max(1, min(100, $limit));
        $rows = Db::name(self::POLICY_TABLE)
            ->where('status', 1)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        $results = [];
        foreach ($rows as $policy) {
            $window = $this->deliveryWindow($policy, $now);
            if ($window === null) {
                $results[] = $this->result($policy, 'not_due', null, false);
                continue;
            }
            if ((string)($policy['last_dispatch_window'] ?? '') === $window) {
                $results[] = $this->result($policy, 'window_already_dispatched', $window, false);
                continue;
            }
            $results[] = $this->dispatch($policy, $window, $now, $push);
        }

        return [
            'observed_at' => $now->format('Y-m-d H:i:s'),
            'push' => $push,
            'policies' => $results,
        ];
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function dispatch(array $policy, string $window, DateTimeImmutable $now, bool $push): array
    {
        if (!$push) {
            return $this->result($policy, 'preview_ready', $window, false, [
                'visual_card_planned' => (int)($policy['visual_card_enabled'] ?? 0) === 1,
                'failure_alert_planned' => (int)($policy['failure_alert_enabled'] ?? 0) === 1,
            ]);
        }

        $hotelId = (int)$policy['hotel_id'];
        $robotId = (int)$policy['robot_id'];
        $ownerUserId = (int)($policy['owner_user_id'] ?? 0);
        $deliveryService = $this->deliveryService ?? new WechatRobotDeliveryService();
        $formalSender = static function (array $payload) use (
            $deliveryService,
            $policy,
            $hotelId,
            $robotId,
            $ownerUserId,
            $window
        ): array {
            return $deliveryService->deliverToAccountPolicyRobot(
                (int)($policy['id'] ?? 0),
                $hotelId,
                self::TEMPLATE_HOURLY_MONITOR,
                $robotId,
                $payload,
                [
                    'tenant_id' => (int)($policy['tenant_id'] ?? 0),
                    'owner_user_id' => $ownerUserId,
                    'frequency' => (string)($policy['frequency'] ?? ''),
                    'template_key' => (string)($policy['template_key'] ?? ''),
                    'dispatch_window' => $window,
                ]
            );
        };
        try {
            $message = $this->hourlyDispatcher !== null
                ? call_user_func(
                    $this->hourlyDispatcher,
                    $hotelId,
                    $robotId,
                    $now,
                    $ownerUserId,
                    $formalSender
                )
                : (new HourlyHotelMonitorWechatService())->run(
                    $hotelId,
                    $robotId,
                    true,
                    $now->format('Y-m-d H:i:s'),
                    false,
                    $ownerUserId,
                    $formalSender
                );
        } catch (\Throwable $exception) {
            $message = [
                'status' => 'failed',
                'delivery_status' => 'failed',
                'error_summary' => $this->safeError($exception->getMessage()),
            ];
        }

        $messageStatus = strtolower(trim((string)($message['delivery_status'] ?? $message['status'] ?? 'failed')));
        $sent = in_array($messageStatus, ['sent', 'partial'], true);
        $visual = ['status' => 'not_requested'];
        if ($sent && (int)($policy['visual_card_enabled'] ?? 0) === 1) {
            $visual = $this->dispatchVisualCard($policy, $now);
        }

        $failureAlert = ['status' => 'not_needed'];
        if (!$sent && (int)($policy['failure_alert_enabled'] ?? 0) === 1) {
            $failureAlert = $this->sendFailureAlert($policy, $window, $messageStatus, $message);
        }

        $policyUpdate = Db::name(self::POLICY_TABLE)
            ->where('id', (int)$policy['id'])
            ->where('tenant_id', (int)($policy['tenant_id'] ?? 0))
            ->where('hotel_id', $hotelId)
            ->where('owner_user_id', $ownerUserId)
            ->where('robot_id', $robotId)
            ->where('frequency', (string)($policy['frequency'] ?? ''))
            ->where('template_key', (string)($policy['template_key'] ?? ''))
            ->where('status', 1);
        $policyUpdate->update([
            'last_dispatch_window' => $window,
            'last_delivery_status' => $messageStatus,
            'last_failure_alert_status' => (string)($failureAlert['status'] ?? ''),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        return $this->result($policy, $sent ? 'dispatched' : 'delivery_failed', $window, true, [
            'message' => $message,
            'visual_card' => $visual,
            'failure_alert' => $failureAlert,
        ]);
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function dispatchVisualCard(array $policy, DateTimeImmutable $now): array
    {
        $policyId = (int)($policy['id'] ?? 0);
        $hotelId = (int)($policy['hotel_id'] ?? 0);
        $robotId = (int)($policy['robot_id'] ?? 0);
        if ($this->visualCardDispatcher !== null) {
            return (array)call_user_func($this->visualCardDispatcher, $hotelId, $robotId, $now);
        }

        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'send_test_wechat_visual_card.php';
        if (!is_file($script)) {
            return ['status' => 'visual_card_sender_missing'];
        }
        $process = proc_open(
            [PHP_BINARY, $script, '--hotel-id', (string)$hotelId, '--policy-id', (string)$policyId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['status' => 'visual_card_sender_start_failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = json_decode(is_string($stdout) ? $stdout : '', true);
        return [
            'status' => $exitCode === 0 ? 'sent' : 'not_sent',
            'delivery_status' => is_array($decoded) ? (string)($decoded['delivery']['delivery_status'] ?? '') : '',
            'error_summary' => $exitCode === 0 ? '' : $this->safeError((string)$stderr),
        ];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $message @return array<string,mixed> */
    private function sendFailureAlert(array $policy, string $window, string $messageStatus, array $message): array
    {
        $failureRobotId = $this->positiveInt($policy['failure_robot_id'] ?? null);
        if ($failureRobotId === null) {
            return ['status' => 'failure_alert_recipient_missing'];
        }
        $hotelId = (int)$policy['hotel_id'];
        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => '# 宿析OS 云端推送失败提醒' . "\n"
                    . '> 门店ID：' . $hotelId . "\n"
                    . '> 时间窗口：' . $window . "\n"
                    . '> 状态：' . $this->safeError($messageStatus) . "\n\n"
                    . '主推送未送达，系统未重新采集或篡改业务数据。请检查机器人绑定、网络和云端投递记录。',
            ],
        ];
        $state = $this->stateStore ?? new CloudAutomationStateStore();
        $lock = $state->acquireLock(5);
        if (!is_resource($lock)) {
            return ['status' => 'in_progress', 'delivery_key' => ''];
        }
        try {
            $record = $state->queueDelivery(
                'wechat_push_failure_alert',
                $hotelId,
                ['policy_id' => (int)$policy['id'], 'window' => $window, 'robot_id' => $failureRobotId],
                $payload,
                ['policy_id' => (int)$policy['id'], 'message_status' => $messageStatus],
                'wechat-push-failure-alert:' . (int)$policy['id'] . ':' . $window
            );
            if (in_array((string)($record['status'] ?? ''), ['sent', 'sending', 'delivery_outcome_unknown'], true)) {
                return ['status' => (string)$record['status'], 'delivery_key' => (string)($record['delivery_key'] ?? '')];
            }
            $attempt = $state->beginDeliveryAttempt($record);
            if ((string)($attempt['status'] ?? '') !== 'sending') {
                return [
                    'status' => (string)($attempt['status'] ?? 'in_progress'),
                    'delivery_key' => (string)($attempt['delivery_key'] ?? ''),
                ];
            }
        } finally {
            $state->releaseLock($lock);
        }

        $delivery = $this->failureAlertDispatcher !== null
            ? (array)call_user_func($this->failureAlertDispatcher, $hotelId, $payload, $failureRobotId)
            : ($this->deliveryService ?? new WechatRobotDeliveryService())
                ->deliverToAccountPolicyRobot(
                    (int)($policy['id'] ?? 0),
                    $hotelId,
                    'failure_alert',
                    $failureRobotId,
                    $payload
                );
        $record = $state->recordDeliveryAttempt($attempt, $delivery, 3);
        return [
            'status' => (string)($record['status'] ?? 'failed'),
            'delivery_status' => (string)($delivery['delivery_status'] ?? 'failed'),
            'delivery_key' => (string)($record['delivery_key'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $policy */
    private function deliveryWindow(array $policy, DateTimeImmutable $now): ?string
    {
        return match ((string)($policy['frequency'] ?? '')) {
            'hourly' => $now->format('Y-m-d-H'),
            // The cloud timer can wake a few minutes late.  The policy is
            // still once per business date because the window key is the date.
            'daily' => $now->format('H') === '09' ? $now->format('Y-m-d') : null,
            default => null,
        };
    }

    private function assertOwnedRobot(int $hotelId, int $userId, int $robotId): void
    {
        $binding = Db::name('competitor_wechat_robot')
            ->where('id', $robotId)
            ->where('store_id', $hotelId)
            ->where('owner_user_id', $userId)
            ->where('notification_scope', self::BINDING_SCOPE)
            ->where('status', 1)
            ->find();
        if (!is_array($binding)) {
            throw new \InvalidArgumentException('wechat_push_robot_binding_not_owned');
        }
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $extra @return array<string,mixed> */
    private function result(array $policy, string $status, ?string $window, bool $executed, array $extra = []): array
    {
        return array_merge([
            'policy_id' => (int)($policy['id'] ?? 0),
            'hotel_id' => (int)($policy['hotel_id'] ?? 0),
            'owner_user_id' => (int)($policy['owner_user_id'] ?? 0),
            'status' => $status,
            'window' => $window,
            'executed' => $executed,
        ], $extra);
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    private function publicPolicy(array $policy): array
    {
        return [
            'id' => (int)($policy['id'] ?? 0),
            'tenant_id' => $this->positiveInt($policy['tenant_id'] ?? null),
            'hotel_id' => (int)($policy['hotel_id'] ?? 0),
            'owner_user_id' => (int)($policy['owner_user_id'] ?? 0),
            'robot_id' => (int)($policy['robot_id'] ?? 0),
            'failure_robot_id' => $this->positiveInt($policy['failure_robot_id'] ?? null),
            'frequency' => (string)($policy['frequency'] ?? ''),
            'template_key' => (string)($policy['template_key'] ?? ''),
            'visual_card_enabled' => (int)($policy['visual_card_enabled'] ?? 0) === 1,
            'failure_alert_enabled' => (int)($policy['failure_alert_enabled'] ?? 0) === 1,
            'enabled' => (int)($policy['status'] ?? 0) === 1,
            'last_dispatch_window' => $policy['last_dispatch_window'] ?? null,
            'last_delivery_status' => $policy['last_delivery_status'] ?? null,
            'last_failure_alert_status' => $policy['last_failure_alert_status'] ?? null,
        ];
    }

    private function positiveInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $parsed === false ? null : (int)$parsed;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function hotelTenantIdForPolicy(int $hotelId): ?int
    {
        if (!$this->tableHasColumn(self::POLICY_TABLE, 'tenant_id')) {
            return null;
        }
        if (!$this->tableHasColumn('hotels', 'tenant_id')) {
            throw new \RuntimeException('wechat_push_policy_hotel_tenant_scope_unavailable');
        }
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new \RuntimeException('wechat_push_policy_hotel_tenant_scope_missing');
        }
        return $tenantId;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            return in_array($column, Db::getTableInfo($table, 'fields'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeError(string $value): string
    {
        $value = preg_replace('/(cookie|token|secret|webhook|key|authorization)\s*[=:]\s*[^\s,;]+/i', '$1=<redacted>', $value) ?? '';
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return mb_substr(trim($value), 0, 240, 'UTF-8');
    }
}
