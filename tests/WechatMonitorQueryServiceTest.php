<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatMonitorQueryService;
use PHPUnit\Framework\TestCase;

final class WechatMonitorQueryServiceTest extends TestCase
{
    public function testRealtimeComparisonKeepsFactAndCaveatSeparate(): void
    {
        $service = new WechatMonitorQueryService(static fn(): array => [
            'hotel' => ['id' => 80, 'name' => '敦煌漠蓝新'],
            'insight' => [
                'past' => [
                    'status' => 'ready',
                    'metrics' => ['ota_orders' => ['latest_value' => 10]],
                    'data_gaps' => [],
                ],
                'present' => [
                    'status' => 'ready',
                    'as_of_time' => '2026-07-25 09:30:00',
                    'metrics' => ['ota_orders' => 12],
                    'comparison_to_latest_final' => [
                        'ota_orders' => [
                            'current_value' => 12,
                            'latest_final_value' => 10,
                            'latest_final_date' => '2026-07-24',
                            'change_percent' => 20.0,
                        ],
                    ],
                    'data_gaps' => [],
                ],
            ],
            'health' => ['issues' => []],
            'ai_daily' => ['report' => null, 'data_gaps' => []],
        ]);

        $answer = $service->answer(80, '今天比昨天怎么样？', '2026-07-25 09:35:00');

        self::assertSame('realtime_comparison', $answer['intent']);
        self::assertStringContainsString('【事实｜实时对比】', $answer['reply_text']);
        self::assertStringContainsString('订单 今日累计 12', $answer['reply_text']);
        self::assertStringContainsString('+20.0%', $answer['reply_text']);
        self::assertStringContainsString('【未验证】', $answer['reply_text']);
        self::assertStringContainsString('仅反映已授权 OTA 渠道', $answer['reply_text']);
    }

    public function testBlockedAiReportIsNotPresentedAsAiJudgment(): void
    {
        $service = new WechatMonitorQueryService(static fn(): array => [
            'hotel' => ['id' => 80, 'name' => '敦煌漠蓝新'],
            'insight' => [
                'past' => ['status' => 'partial', 'metrics' => [], 'data_gaps' => []],
                'present' => ['status' => 'empty', 'metrics' => [], 'data_gaps' => []],
            ],
            'health' => ['issues' => []],
            'ai_daily' => [
                'report' => [
                    'report_date' => '2026-07-23',
                    'model_status' => 'blocked_by_data_quality',
                    'summary' => '这段内容不能冒充可用AI结论',
                    'data_gaps' => [['message' => '核心经营指标缺失']],
                ],
                'data_gaps' => [],
            ],
        ]);

        $answer = $service->answer(80, '收益判断是什么？', '2026-07-25 09:35:00');

        self::assertSame('partial', $answer['status']);
        self::assertStringContainsString('【未验证/缺口】', $answer['reply_text']);
        self::assertStringContainsString('数据质量门禁阻塞', $answer['reply_text']);
        self::assertStringNotContainsString('blocked_by_data_quality', $answer['reply_text']);
        self::assertStringNotContainsString('**【AI研判｜日报', $answer['reply_text']);
        self::assertContains('核心经营指标缺失', $answer['data_gaps']);
    }
}
