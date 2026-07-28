<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Customer-owned, hotel-scoped WeCom robot binding with encrypted storage. */
final class WechatNotificationBindingService
{
    private const SCOPE = 'account_onboarding';

    private readonly \Closure $testDeliveryTransport;

    public function __construct(?callable $testDeliveryTransport = null)
    {
        $this->testDeliveryTransport = $testDeliveryTransport === null
            ? static fn(int $hotelId, array $payload, array $robotIds, array $binding): array =>
                (new WechatRobotDeliveryService())->deliverToHotel($hotelId, $payload, $robotIds)
            : \Closure::fromCallable($testDeliveryTransport);
    }

    public function status(int $hotelId, int $userId): array
    {
        $binding = $this->find($hotelId, $userId);
        return [
            'hotel_id' => $hotelId,
            'binding_status' => $binding === null ? 'binding_missing' : 'configured',
            'binding' => $binding === null ? null : $this->publicBinding($binding),
        ];
    }

    public function bind(int $hotelId, int $userId, string $name, string $webhook): array
    {
        $name = trim($name);
        if ($hotelId <= 0 || $userId <= 0 || $name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('binding_input_invalid');
        }
        $webhook = $this->normalizeWebhook($webhook);
        if ($webhook === null) {
            throw new \InvalidArgumentException('webhook_invalid');
        }

        return Db::transaction(function () use ($hotelId, $userId, $name, $webhook): array {
            $existing = $this->find($hotelId, $userId, true);
            $tenantId = $this->hotelTenantIdForRobot($hotelId);
            if ($existing === null) {
                $values = [
                    'store_id' => $hotelId,
                    'owner_user_id' => $userId,
                    'notification_scope' => self::SCOPE,
                    'name' => $name,
                    'webhook' => '',
                    'status' => 1,
                    'last_test_status' => 'pending',
                    'create_time' => date('Y-m-d H:i:s'),
                ];
                if ($tenantId !== null) {
                    $values['tenant_id'] = $tenantId;
                }
                $id = (int)Db::name('competitor_wechat_robot')->insertGetId($values);
                if ($id <= 0) {
                    throw new \RuntimeException('binding_create_failed');
                }
                $stored = (new WechatRobotWebhookSecret())->protect($webhook, $id);
                Db::name('competitor_wechat_robot')->where('id', $id)->update(['webhook' => $stored]);
                $existing = Db::name('competitor_wechat_robot')->where('id', $id)->find();
            } else {
                $id = (int)$existing['id'];
                $webhookChanged = $this->webhookChanged($existing, $webhook);
                $stored = (new WechatRobotWebhookSecret())->protect($webhook, $id);
                $values = [
                    'name' => $name,
                    'webhook' => $stored,
                    'status' => 1,
                ];
                if ($webhookChanged) {
                    $values['last_tested_at'] = null;
                    $values['last_test_status'] = 'pending';
                }
                if ($tenantId !== null) {
                    $values['tenant_id'] = $tenantId;
                }
                Db::name('competitor_wechat_robot')->where('id', $id)->update($values);
                if ($webhookChanged) {
                    $this->invalidateNotificationPlans($id);
                }
                $existing = Db::name('competitor_wechat_robot')->where('id', $id)->find();
            }
            return $this->publicBinding((array)$existing);
        });
    }

    public function test(int $hotelId, int $userId): array
    {
        return Db::transaction(function () use ($hotelId, $userId): array {
            $binding = $this->find($hotelId, $userId, true);
            if ($binding === null || (int)($binding['status'] ?? 0) !== 1) {
                throw new \RuntimeException('binding_missing');
            }

            $payload = [
                'msgtype' => 'markdown',
                'markdown' => ['content' => "# 宿析OS 已连接\n> 当前账户的企业微信通知链路可用。"],
            ];
            try {
                $delivery = ($this->testDeliveryTransport)(
                    $hotelId,
                    $payload,
                    [(int)$binding['id']],
                    $binding
                );
                if (!is_array($delivery)) {
                    $delivery = ['delivery_status' => 'failed'];
                }
            } catch (\Throwable) {
                $delivery = ['delivery_status' => 'failed'];
            }

            $deliveryStatus = (string)($delivery['delivery_status'] ?? 'failed');
            $testStatus = $deliveryStatus === 'sent' ? 'sent' : 'failed';
            $testedAt = date('Y-m-d H:i:s');
            $updated = Db::name('competitor_wechat_robot')
                ->where('id', (int)$binding['id'])
                ->where('store_id', $hotelId)
                ->where('owner_user_id', $userId)
                ->where('notification_scope', self::SCOPE)
                ->where('webhook', (string)($binding['webhook'] ?? ''))
                ->update([
                    'last_tested_at' => $testedAt,
                    'last_test_status' => $testStatus,
                ]);

            if ($updated !== 1) {
                $delivery['message_delivery_status'] = $deliveryStatus;
                $delivery['delivery_status'] = 'binding_changed';
                $latest = $this->find($hotelId, $userId, true);
                return [
                    'binding' => $latest === null ? null : $this->publicBinding($latest),
                    'delivery' => $delivery,
                ];
            }

            $binding['last_tested_at'] = $testedAt;
            $binding['last_test_status'] = $testStatus;
            return [
                'binding' => $this->publicBinding($binding),
                'delivery' => $delivery,
            ];
        });
    }

    private function find(int $hotelId, int $userId, bool $lock = false): ?array
    {
        $query = Db::name('competitor_wechat_robot')
            ->where('store_id', $hotelId)
            ->where('owner_user_id', $userId)
            ->where('notification_scope', self::SCOPE)
            ->order('id', 'desc');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    private function publicBinding(array $binding): array
    {
        return [
            'id' => (int)($binding['id'] ?? 0),
            'hotel_id' => (int)($binding['store_id'] ?? 0),
            'name' => (string)($binding['name'] ?? ''),
            'webhook_configured' => trim((string)($binding['webhook'] ?? '')) !== '',
            'webhook_masked' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=******',
            'status' => (int)($binding['status'] ?? 0),
            'last_tested_at' => $binding['last_tested_at'] ?? null,
            'last_test_status' => $binding['last_test_status'] ?? null,
        ];
    }

    private function normalizeWebhook(string $webhook): ?string
    {
        $webhook = trim($webhook);
        $parts = parse_url($webhook);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'qyapi.weixin.qq.com'
            || (string)($parts['path'] ?? '') !== '/cgi-bin/webhook/send'
        ) {
            return null;
        }
        parse_str((string)($parts['query'] ?? ''), $query);
        return trim((string)($query['key'] ?? '')) !== '' ? $webhook : null;
    }

    private function hotelTenantIdForRobot(int $hotelId): ?int
    {
        if (!$this->tableHasColumn('competitor_wechat_robot', 'tenant_id')) {
            return null;
        }
        if (!$this->tableHasColumn('hotels', 'tenant_id')) {
            throw new \RuntimeException('hotel_tenant_scope_unavailable');
        }
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId <= 0) {
            throw new \RuntimeException('hotel_tenant_scope_missing');
        }
        return $tenantId;
    }

    private function webhookChanged(array $binding, string $webhook): bool
    {
        $stored = trim((string)($binding['webhook'] ?? ''));
        if ($stored === '') {
            return true;
        }
        try {
            $current = (new WechatRobotWebhookSecret())->reveal(
                $stored,
                (int)($binding['id'] ?? 0)
            );
        } catch (\RuntimeException) {
            return true;
        }
        return !hash_equals(trim($current), $webhook);
    }

    private function invalidateNotificationPlans(int $robotId): void
    {
        $fields = $this->tableFields('manual_notifications');
        if (!in_array('test_robot_id', $fields, true)
            || !in_array('schedule_status', $fields, true)
        ) {
            return;
        }
        $values = ['schedule_status' => 'awaiting_test'];
        foreach ([
            'last_test_status' => 'never_tested',
            'last_test_message' => null,
            'last_tested_at' => null,
            'last_tested_by' => null,
        ] as $field => $value) {
            if (in_array($field, $fields, true)) {
                $values[$field] = $value;
            }
        }
        if (in_array('update_time', $fields, true)) {
            $values['update_time'] = date('Y-m-d H:i:s');
        }
        $query = Db::name('manual_notifications')->where('test_robot_id', $robotId);
        if (in_array('enabled', $fields, true)) {
            $query->where('enabled', 1);
        }
        $query->update($values);
    }

    /** @return array<int, string> */
    private function tableFields(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
            return is_array($fields) ? array_values($fields) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tableFields($table), true);
    }
}
