<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatMonitorInboundAdapter;
use PHPUnit\Framework\TestCase;

final class WechatMonitorInboundAdapterTest extends TestCase
{
    public function testRobotWebhookIsExplicitlyRejectedAsInboundTransport(): void
    {
        $result = (new WechatMonitorInboundAdapter())->handleNormalizedEvent([
            'transport' => 'wecom_robot_webhook',
            'message_type' => 'text',
            'hotel_id' => 80,
            'content' => '今天怎么样',
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame('send_only_robot_cannot_receive', $result['code']);
        self::assertSame('not_sent', $result['delivery_status']);
    }

    public function testVerifiedNormalizedCallbackBuildsReplyWithoutClaimingItWasSent(): void
    {
        $adapter = new WechatMonitorInboundAdapter(static fn(int $hotelId, string $content): array => [
            'intent' => 'today_progress',
            'metric_scope' => 'ota_channel',
            'reply_text' => '真实数据答复',
            'data_gaps' => ['今日快照缺失'],
            'sources' => ['online_daily_data'],
        ]);
        $result = $adapter->handleNormalizedEvent([
            'transport' => 'wecom_app_callback',
            'signature_verified' => true,
            'hotel_binding_verified' => true,
            'message_type' => 'text',
            'hotel_id' => 80,
            'content' => '今天进度',
        ]);

        self::assertSame('reply_ready', $result['status']);
        self::assertSame('not_sent', $result['delivery_status']);
        self::assertTrue($result['outbound_transport_required']);
        self::assertSame('真实数据答复', $result['reply_text']);
        self::assertSame(80, $result['hotel_id']);
    }
}
