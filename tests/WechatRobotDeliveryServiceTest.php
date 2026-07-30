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
        $rejected = WechatRobotDeliveryService::interpretWebhookResponse('{"errcode":93000,"errmsg":"invalid webhook"}');
        self::assertFalse($rejected['success']);
        self::assertFalse($rejected['ambiguous']);
        $gatewayFailure = WechatRobotDeliveryService::interpretWebhookResponse('<html>bad gateway</html>', 502);
        self::assertFalse($gatewayFailure['success']);
        self::assertTrue($gatewayFailure['ambiguous']);
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

    public function testLocalCollectorFailurePayloadShowsRecoveryWithoutSessionMaterial(): void
    {
        $service = new WechatRobotDeliveryService();
        $payload = $service->buildOtaCollectionFailurePayload([
            'platform' => 'ctrip',
            'reason_code' => 'login_expired',
            'data_date' => '2026-07-23',
            'error_summary' => '登录状态已失效。',
            'next_action' => '请在账户使用者电脑重新登录，成功后系统补抓原日期。',
            'task_id' => 31,
            'account_alias' => '华东携程账户',
        ], '测试酒店');
        $content = (string)($payload['markdown']['content'] ?? '');

        self::assertStringContainsString('本机采集异常', $content);
        self::assertStringContainsString('华东携程账户', $content);
        self::assertStringContainsString('重新登录', $content);
        self::assertStringContainsString('Cookie', $content);
        self::assertStringContainsString('均保留在账户使用者电脑', $content);
        self::assertStringNotContainsString('Authorization:', $content);
    }
}
