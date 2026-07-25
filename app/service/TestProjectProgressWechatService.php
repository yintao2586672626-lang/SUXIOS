<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Sends a concise implementation milestone to an explicitly selected test
 * group.  It deliberately cannot select a production robot by hotel alone.
 */
final class TestProjectProgressWechatService
{
    public function __construct(private readonly ?WechatRobotDeliveryService $delivery = null)
    {
    }

    /** @return array<string, mixed> */
    public function send(int $hotelId, int $testRobotId, string $title, string $message): array
    {
        if ($hotelId <= 0 || $testRobotId <= 0) {
            throw new \InvalidArgumentException('必须指定门店和测试群机器人。');
        }

        $hotel = Db::name('hotels')->where('id', $hotelId)->field('id,name,status')->find();
        if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('测试门店不存在或未启用。');
        }
        $robot = Db::name('competitor_wechat_robot')
            ->where('id', $testRobotId)
            ->where('store_id', $hotelId)
            ->where('status', 1)
            ->field('id,name')
            ->find();
        if (!is_array($robot) || !str_contains((string)($robot['name'] ?? ''), '测试')) {
            throw new \InvalidArgumentException('进度通知只允许发送到名称含“测试”的已绑定机器人。');
        }

        $payload = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => implode("\n", [
                    '# 宿析OS｜项目进度',
                    '> 门店：' . $this->safe((string)($hotel['name'] ?? '未命名门店'), 80),
                    '> 时间：' . date('Y-m-d H:i:s'),
                    '',
                    '**' . $this->safe($title, 80) . '**',
                    $this->safe($message, 1200),
                    '',
                    '> 此消息仅同步研发进度；不触发 OTA 采集、不生成正式日报、不修改经营数据。',
                ]),
            ],
        ];

        return ($this->delivery ?? new WechatRobotDeliveryService())->deliverToHotel($hotelId, $payload, [$testRobotId]);
    }

    private function safe(string $value, int $limit): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value));
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
}
