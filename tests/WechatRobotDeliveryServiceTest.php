<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatRobotDeliveryService;
use PHPUnit\Framework\TestCase;

final class WechatRobotDeliveryServiceTest extends TestCase
{
    public function testDailyPayloadShowsMissingValuesInsteadOfZeroFallback(): void
    {
        $service = new WechatRobotDeliveryService();
        $payload = $service->buildDailyReportPayload([
            'report_date' => '2026-07-21',
            'summary' => 'OTA 订单已回读，整店收入仍缺失。',
            'yesterday_result' => [
                'metrics' => [
                    ['label' => '营收', 'value' => null, 'unit' => '元'],
                    ['label' => '订单', 'value' => 12, 'unit' => '单'],
                ],
            ],
            'data_gaps' => [['code' => 'revenue_missing', 'message' => '整店收入尚未回读。']],
            'recommended_actions' => [],
        ], '测试酒店');

        $content = (string)($payload['markdown']['content'] ?? '');
        self::assertStringContainsString('订单：12单', $content);
        self::assertStringContainsString('整店收入尚未回读', $content);
        self::assertStringNotContainsString('营收：0', $content);
        self::assertStringContainsString('不触发 OTA 采集', $content);
    }

    public function testWebhookResponseRequiresTencentSuccessCode(): void
    {
        self::assertTrue(WechatRobotDeliveryService::interpretWebhookResponse('{"errcode":0,"errmsg":"ok"}')['success']);
        self::assertFalse(WechatRobotDeliveryService::interpretWebhookResponse('{"errcode":93000,"errmsg":"invalid webhook"}')['success']);
        self::assertFalse(WechatRobotDeliveryService::interpretWebhookResponse('<html>bad gateway</html>', 502)['success']);
    }

    public function testHealthAndWeeklyPayloadKeepScopeAndRetryBoundaryVisible(): void
    {
        $service = new WechatRobotDeliveryService();
        $health = $service->buildHealthAlertPayload([
            'target_date' => '2026-07-21',
            'status' => 'blocked',
            'issues' => [[
                'code' => 'login_expired',
                'platform' => 'ctrip',
                'message' => '平台登录已过期。',
                'next_action' => '本地重新登录。',
            ]],
        ], '测试酒店');
        self::assertStringContainsString('不会自动登录携程/美团', (string)$health['markdown']['content']);

        $weekly = $service->buildWeeklyDigestPayload([], '测试酒店', '2026-07-15', '2026-07-21');
        $weeklyContent = (string)$weekly['markdown']['content'];
        self::assertStringContainsString('没有可回读', $weeklyContent);
        self::assertStringContainsString('不重新采集或重新生成报告', $weeklyContent);
    }
}
