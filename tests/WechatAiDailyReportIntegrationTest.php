<?php
declare(strict_types=1);

namespace Tests;

use app\controller\admin\CompetitorWechatRobotController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WechatAiDailyReportIntegrationTest extends TestCase
{
    public function testAiDailyReportPayloadKeepsMissingDataAndScopeVisible(): void
    {
        $controller = (new ReflectionClass(CompetitorWechatRobotController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(CompetitorWechatRobotController::class, 'buildAiDailyReportPayload');

        $payload = $method->invoke($controller, [
            'report_date' => '2026-07-21',
            'summary' => '昨日 OTA 订单已回读，整店总营收证据仍不完整。',
            'result_readiness' => [
                'status' => 'partial',
                'status_label' => '结果部分可用',
                'scope_note' => 'OTA 渠道事实与全酒店经营日报必须分开解读。',
            ],
            'yesterday_result' => [
                'metrics' => [
                    ['key' => 'revenue', 'label' => '营收', 'value' => null, 'unit' => '元'],
                    ['key' => 'orders', 'label' => '订单', 'value' => 12, 'unit' => '单'],
                ],
            ],
            'data_gaps' => [
                ['code' => 'whole_hotel_revenue_missing', 'message' => '全酒店总营收尚未回读。'],
            ],
            'recommended_actions' => [
                ['action' => '先补齐同酒店同日期经营日报。', 'blocked_reason' => '来源回读不足'],
            ],
        ], '测试门店');

        $content = (string)($payload['markdown']['content'] ?? '');
        self::assertSame('markdown', $payload['msgtype'] ?? null);
        self::assertStringContainsString('测试门店', $content);
        self::assertStringContainsString('结果部分可用', $content);
        self::assertStringContainsString('订单：12单', $content);
        self::assertStringNotContainsString('营收：0', $content);
        self::assertStringContainsString('全酒店总营收尚未回读', $content);
        self::assertStringContainsString('来源回读不足', $content);
        self::assertStringContainsString('OTA 渠道事实与全酒店经营日报必须分开解读', $content);
        self::assertStringContainsString('不触发 OTA 采集', $content);
        self::assertLessThanOrEqual(3800, strlen($content));
    }

    public function testWechatConfigurationAndDailyReportSendHaveRealUserEntries(): void
    {
        $root = dirname(__DIR__);
        $route = (string)file_get_contents($root . '/route/app.php');
        $dataConfig = (string)file_get_contents(
            $root . '/resources/frontend/templates/fragments/34-page-data-config.html'
        );
        $dailyReport = (string)file_get_contents(
            $root . '/resources/frontend/templates/fragments/16-page-ai-daily-report.html'
        );
        $frontend = (string)file_get_contents($root . '/public/app-main.js');

        self::assertStringContainsString("Route::post('/:id/send-wecom'", $route);
        self::assertStringContainsString('wecom-robot-management', $dataConfig);
        self::assertStringContainsString('binding_missing', $dataConfig);
        self::assertStringContainsString('ai-daily-report-send-wecom', $dailyReport);
        self::assertStringContainsString('/send-wecom', $frontend);
    }
}
