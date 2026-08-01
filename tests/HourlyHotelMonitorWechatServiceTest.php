<?php
declare(strict_types=1);

namespace Tests;

use app\service\HourlyHotelMonitorWechatService;
use PHPUnit\Framework\TestCase;

final class HourlyHotelMonitorWechatServiceTest extends TestCase
{
    public function testPayloadKeepsMissingDataVisibleInsteadOfInventingZero(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => '敦煌漠蓝新'],
            [
                'past' => ['status' => 'partial', 'metrics' => ['ota_orders' => 12]],
                'present' => ['status' => 'empty', 'metrics' => [], 'data_gaps' => [['message' => '今天尚未取得实时快照']]],
            ],
            ['issues' => [['message' => '美团目标日期流量尚未回读', 'next_action' => '先补抓美团目标日期流量。']]],
            '2026-07-25 01:00:00'
        );
        $content = (string)$payload['markdown']['content'];
        self::assertStringContainsString('观察日：2026-07-25；OTA校验日：2026-07-24', $content);
        self::assertStringContainsString('敦煌漠蓝新', $content);
        self::assertStringContainsString('订单：12', $content);
        self::assertStringContainsString('今日进度｜事实', $content);
        self::assertStringContainsString('实时经营对比｜事实', $content);
        self::assertStringContainsString('数据未齐', $content);
        self::assertStringContainsString('暂未取得', $content);
        self::assertStringNotContainsString('状态：empty', $content);
        self::assertStringContainsString('先补齐数据，再判断经营问题。', $content);
        self::assertStringContainsString('online_daily_data', $content);
        self::assertStringContainsString('目标日报未生成', $content);
        self::assertStringNotContainsString('收益：0', $content);
    }

    public function testPayloadUsesLatestValueFromTemporalTrendSummary(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => 'Test hotel'],
            [
                'past' => ['status' => 'ready', 'metrics' => ['ota_revenue' => ['latest_value' => 14376.01]]],
                'present' => ['status' => 'empty', 'metrics' => []],
            ],
            ['issues' => []],
            '2026-07-25 02:00:00'
        );

        self::assertStringContainsString('14376.01', (string)$payload['markdown']['content']);
    }

    public function testPayloadDoesNotCallVerifiedCoreOtaFactsAnIncompletePlatform(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => '敦煌漠蓝新'],
            ['past' => ['status' => 'partial', 'metrics' => ['ota_revenue' => ['latest_value' => 6717.31]]], 'present' => ['status' => 'empty']],
            [
                'issues' => [['platform' => 'ctrip', 'message' => '携程辅助字段尚未取得']],
                'p0_downstream_gate' => ['status' => 'ready'],
            ],
            '2026-07-25 09:10:00'
        );

        $content = (string)$payload['markdown']['content'];
        self::assertStringContainsString('核心 OTA 事实已验证', $content);
        self::assertStringContainsString('辅助字段缺口', $content);
        self::assertStringNotContainsString('携程数据未齐', $content);
    }

    public function testComparisonNeverRendersZeroWhenRealtimeStatusIsEmpty(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => '测试门店'],
            [
                'past' => ['status' => 'ready', 'metrics' => ['ota_revenue' => ['latest_value' => 1200]]],
                'present' => ['status' => 'empty', 'metrics' => ['ota_revenue' => 0, 'ota_orders' => 0]],
            ],
            ['issues' => []],
            '2026-07-25 09:00:00'
        );
        $content = (string)$payload['markdown']['content'];
        self::assertStringNotContainsString('今日累计：收益：0', $content);
        self::assertStringNotContainsString('订单：0', $content);
        self::assertStringContainsString('当前尚无同一指标的完整实时对比', $content);
    }

    public function testPayloadSeparatesRealtimeFactsAndExactDateAiJudgment(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => '敦煌漠蓝新'],
            [
                'past' => ['status' => 'ready', 'metrics' => ['ota_orders' => ['latest_value' => 10]]],
                'present' => [
                    'status' => 'ready',
                    'as_of_time' => '2026-07-25 09:30:00',
                    'metrics' => ['ota_orders' => 12],
                    'comparison_to_latest_final' => [
                        'ota_orders' => [
                            'current_value' => 12,
                            'latest_final_value' => 10,
                            'latest_final_date' => '2026-07-24',
                            'change_percent' => 20,
                        ],
                    ],
                ],
            ],
            ['issues' => []],
            '2026-07-25 09:35:00',
            ['report' => [
                'report_date' => '2026-07-24',
                'model_status' => 'ok',
                'summary' => '订单进度高于最近定稿日，但仍需等待完整日回读。',
            ]]
        );

        $content = (string)$payload['markdown']['content'];
        self::assertStringContainsString('订单：今日累计 12，2026-07-24 10（+20.0%）', $content);
        self::assertStringContainsString('口径：今日累计 vs 最近完整日，仅用于观察。', $content);
        self::assertStringContainsString('AI研判｜日报 2026-07-24', $content);
        self::assertStringContainsString('模型状态可用', $content);
    }

    public function testPayloadDoesNotReuseStaleAiSummary(): void
    {
        $payload = HourlyHotelMonitorWechatService::buildPayload(
            ['name' => '敦煌漠蓝新'],
            ['past' => [], 'present' => []],
            ['issues' => []],
            '2026-07-25 09:35:00',
            ['report' => [
                'report_date' => '2026-07-23',
                'model_status' => 'ok',
                'summary' => '旧日报结论不能沿用',
            ]]
        );

        $content = (string)$payload['markdown']['content'];
        self::assertStringContainsString('与目标日 2026-07-24 不一致', $content);
        self::assertStringNotContainsString('旧日报结论不能沿用', $content);
        self::assertStringContainsString('未用于结论', $content);
    }
}
