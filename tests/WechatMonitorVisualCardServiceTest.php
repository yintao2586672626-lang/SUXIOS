<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatMonitorVisualCardService;
use PHPUnit\Framework\TestCase;

final class WechatMonitorVisualCardServiceTest extends TestCase
{
    public function testBuildsSourceBackedTableAndTrendWithoutMixingScope(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '敦煌漠蓝新'],
            [
                'past' => [
                    'status' => 'ready',
                    'metrics' => [
                        'ota_revenue' => ['latest_value' => 1200],
                        'ota_orders' => ['latest_value' => 6],
                    ],
                    'series' => [
                        ['date' => '2026-07-22', 'ota_revenue' => 1000, 'ota_orders' => 5],
                        ['date' => '2026-07-23', 'ota_revenue' => 1200, 'ota_orders' => 6],
                    ],
                ],
                'present' => [
                    'status' => 'ready',
                    'as_of_time' => '2026-07-24 10:20:00',
                    'metrics' => ['ota_revenue' => 1500, 'ota_orders' => 8],
                    'comparison_to_latest_final' => [
                        'ota_revenue' => [
                            'current_value' => 1500,
                            'latest_final_value' => 1200,
                            'latest_final_date' => '2026-07-23',
                            'change_percent' => 25,
                        ],
                    ],
                ],
            ],
            ['status' => 'verified', 'issues' => []],
            ['report' => [
                'report_date' => '2026-07-23',
                'model_status' => 'ok',
                'summary' => '今日累计收益高于最近定稿日，但仍需等待完整日回读。',
            ]],
            '2026-07-24 10:30:00'
        );

        self::assertSame('suxi.wecom.monitor.visual-card.v1', $model['schema']);
        self::assertSame('facts', $model['card_type']);
        self::assertSame('ota_channel', $model['metric_scope']);
        self::assertSame('2026-07-23', $model['target_date']);
        self::assertSame('ready', $model['trend']['status']);
        self::assertSame('最近定稿', $model['latest_final']['column_label']);
        self::assertSame('ota_revenue', $model['trend']['metric_key']);
        self::assertCount(2, $model['trend']['points']);
        self::assertSame(1500.0, $model['metrics'][0]['today_value']);
        self::assertSame(1200.0, $model['metrics'][0]['latest_final_value']);
        self::assertSame(25.0, $model['metrics'][0]['change_percent']);
        self::assertSame('ai', $model['judgment']['status']);
        self::assertTrue($model['truth_rules']['missing_values_are_null']);
        self::assertTrue($model['truth_rules']['old_data_not_used_as_today']);
    }

    public function testEmptyStatusRejectsPlaceholderZeroAndBuildsGapCard(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '敦煌漠蓝新'],
            [
                'past' => [
                    'status' => 'empty',
                    'metrics' => ['ota_revenue' => ['latest_value' => 0]],
                    'series' => [],
                ],
                'present' => [
                    'status' => 'empty',
                    'metrics' => ['ota_revenue' => 0, 'ota_orders' => 0],
                    'data_gaps' => [['message' => '今天尚未取得实时快照']],
                ],
            ],
            ['status' => 'blocked', 'issues' => [
                [
                    'code' => 'field_evidence_missing',
                    'platform' => 'ctrip',
                    'message' => '目标日字段尚未回读',
                ],
                [
                    'code' => 'latest_collection_partial',
                    'platform' => 'meituan',
                    'message' => 'The latest collection completed only partially.',
                ],
            ]],
            ['report' => null],
            '2026-07-25 01:00:00'
        );

        self::assertSame('gap', $model['card_type']);
        self::assertSame([], $model['metrics']);
        self::assertSame('unavailable', $model['trend']['status']);
        self::assertStringContainsString('历史事实尚未取得', $model['trend']['reason']);
        self::assertContains('携程：目标日记录缺少可识别的字段或来源证据。', $model['gaps']);
        self::assertContains('美团：最近一次采集只完成部分数据，快照不足以生成报告。', $model['gaps']);
        self::assertContains('今天尚未取得实时快照', $model['gaps']);
        self::assertStringNotContainsString('The latest collection', implode(' ', $model['gaps']));
        self::assertSame('unverified', $model['judgment']['status']);
    }

    public function testEachMetricUsesItsOwnLatestFactDate(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '测试门店'],
            [
                'past' => [
                    'status' => 'partial',
                    'metrics' => [
                        'ota_revenue' => ['latest_value' => 1200],
                        'ota_list_exposure' => ['latest_value' => 300],
                    ],
                    'series' => [
                        ['date' => '2026-07-22', 'ota_revenue' => 1000, 'ota_list_exposure' => 300],
                        ['date' => '2026-07-23', 'ota_revenue' => 1200, 'ota_list_exposure' => null],
                    ],
                ],
                'present' => [
                    'status' => 'empty',
                    'metrics' => [],
                    'comparison_to_latest_final' => [
                        'ota_list_exposure' => ['latest_final_value' => null, 'latest_final_date' => '2026-07-23'],
                    ],
                ],
            ],
            ['issues' => []],
            ['report' => null],
            '2026-07-24 09:00:00'
        );

        $byKey = [];
        foreach ($model['metrics'] as $metric) $byKey[$metric['key']] = $metric;
        self::assertSame('2026-07-23', $byKey['ota_revenue']['latest_final_date']);
        self::assertSame('2026-07-22', $byKey['ota_list_exposure']['latest_final_date']);
    }

    public function testDoesNotReuseStaleAiJudgment(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '敦煌漠蓝新'],
            ['past' => [], 'present' => []],
            ['issues' => []],
            ['report' => [
                'report_date' => '2026-07-22',
                'model_status' => 'ok',
                'summary' => '这是不应沿用的旧结论。',
            ]],
            '2026-07-24 10:30:00'
        );

        self::assertSame('unverified', $model['judgment']['status']);
        self::assertStringContainsString('不沿用旧结论', $model['judgment']['text']);
        self::assertStringNotContainsString('这是不应沿用的旧结论', $model['judgment']['text']);
    }

    public function testDoesNotPlaceUnanchoredRuleSummaryBesideFacts(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '测试门店'],
            ['past' => ['status' => 'ready', 'metrics' => ['ota_orders' => ['latest_value' => 13]]], 'present' => []],
            ['issues' => []],
            ['report' => [
                'report_date' => '2026-07-24',
                'model_status' => 'not_requested',
                'summary' => '历史/日终结果：订单5，营收6717.31。',
            ]],
            '2026-07-25 09:00:00'
        );

        self::assertSame('unverified', $model['judgment']['status']);
        self::assertStringContainsString('一致性核对', $model['judgment']['text']);
        self::assertStringNotContainsString('订单5', $model['judgment']['text']);
    }

    public function testExactP0ReceiptKeepsAuxiliaryHealthCodesOutOfOwnerCard(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '敦煌漠蓝新'],
            [
                'past' => ['status' => 'partial', 'metrics' => ['ota_revenue' => ['latest_value' => 6717.31]]],
                'present' => ['status' => 'empty', 'data_gaps' => [['code' => 'etl_validation_rejected']]],
            ],
            [
                'issues' => [['code' => 'field_validation_field_missing_mt_exposure', 'platform' => 'ctrip']],
                'p0_downstream_gate' => ['status' => 'ready'],
            ],
            ['report' => null],
            '2026-07-25 09:00:00'
        );

        self::assertContains('今天尚未取得实时快照。', $model['gaps']);
        self::assertContains('核心 OTA 事实已验证；其余为辅助字段缺口，不以 0 或旧数据代替。', $model['gaps']);
        self::assertStringNotContainsString('etl_validation_rejected', implode(' ', $model['gaps']));
        self::assertStringContainsString('核心 OTA 事实已验证', (string)$model['next_action']);
        self::assertSame('最近已取得', $model['latest_final']['column_label']);
    }

    public function testBindingOrReadbackFailureBlocksFactLabels(): void
    {
        $model = (new WechatMonitorVisualCardService())->buildModel(
            ['id' => 80, 'name' => '测试门店'],
            ['past' => ['status' => 'ready', 'metrics' => ['ota_revenue' => ['latest_value' => 1200]], 'series' => [['date' => '2026-07-23', 'ota_revenue' => 1200]]], 'present' => []],
            ['issues' => [['code' => 'data_source_binding_missing']]],
            ['report' => null],
            '2026-07-24 09:00:00'
        );
        self::assertSame('gap', $model['card_type']);
        self::assertSame([], $model['metrics']);
        self::assertSame('blocked', $model['latest_final']['status']);
    }

    public function testBuildsEnterpriseWechatImagePayloadFromPng(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'suxi-visual-card-test-');
        self::assertIsString($path);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);
        file_put_contents($path, $png);
        try {
            $payload = (new WechatMonitorVisualCardService())->imagePayloadFromFile($path);
            self::assertSame('image', $payload['msgtype']);
            self::assertSame(md5($png), $payload['image']['md5']);
            self::assertSame(base64_encode($png), $payload['image']['base64']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonImagePayload(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'suxi-visual-card-invalid-');
        self::assertIsString($path);
        file_put_contents($path, 'not-an-image');
        try {
            $this->expectException(\InvalidArgumentException::class);
            (new WechatMonitorVisualCardService())->imagePayloadFromFile($path);
        } finally {
            @unlink($path);
        }
    }
}
