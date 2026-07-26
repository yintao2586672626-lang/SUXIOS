<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/** Customer-owned, hotel-scoped WeCom robot binding with encrypted storage. */
final class WechatNotificationBindingService
{
    private const SCOPE = 'account_onboarding';

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
        if ($this->normalizeWebhook($webhook) === null) {
            throw new \InvalidArgumentException('webhook_invalid');
        }

        return Db::transaction(function () use ($hotelId, $userId, $name, $webhook): array {
            $existing = $this->find($hotelId, $userId, true);
            if ($existing === null) {
                $id = (int)Db::name('competitor_wechat_robot')->insertGetId([
                    'store_id' => $hotelId,
                    'owner_user_id' => $userId,
                    'notification_scope' => self::SCOPE,
                    'name' => $name,
                    'webhook' => '',
                    'status' => 1,
                    'last_test_status' => 'pending',
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
                if ($id <= 0) {
                    throw new \RuntimeException('binding_create_failed');
                }
                $stored = (new WechatRobotWebhookSecret())->protect($webhook, $id);
                Db::name('competitor_wechat_robot')->where('id', $id)->update(['webhook' => $stored]);
                $existing = Db::name('competitor_wechat_robot')->where('id', $id)->find();
            } else {
                $id = (int)$existing['id'];
                $stored = (new WechatRobotWebhookSecret())->protect($webhook, $id);
                Db::name('competitor_wechat_robot')->where('id', $id)->update([
                    'name' => $name,
                    'webhook' => $stored,
                    'status' => 1,
                    'last_tested_at' => null,
                    'last_test_status' => 'pending',
                ]);
                $existing = Db::name('competitor_wechat_robot')->where('id', $id)->find();
            }
            return $this->publicBinding((array)$existing);
        });
    }

    public function test(int $hotelId, int $userId): array
    {
        $binding = $this->find($hotelId, $userId, true);
        if ($binding === null || (int)($binding['status'] ?? 0) !== 1) {
            throw new \RuntimeException('binding_missing');
        }
        $delivery = (new WechatRobotDeliveryService())->deliverToHotel($hotelId, [
            'msgtype' => 'markdown',
            'markdown' => ['content' => "# 宿析OS 已连接\n> 当前账户的企业微信通知链路可用。"],
        ], [(int)$binding['id']]);
        $status = (string)($delivery['delivery_status'] ?? 'failed');
        Db::name('competitor_wechat_robot')->where('id', (int)$binding['id'])->update([
            'last_tested_at' => date('Y-m-d H:i:s'),
            'last_test_status' => $status,
        ]);
        return ['binding' => $this->publicBinding(array_merge($binding, [
            'last_tested_at' => date('Y-m-d H:i:s'),
            'last_test_status' => $status,
        ])), 'delivery' => $delivery];
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
}
