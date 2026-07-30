<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatCompetitionReportRendererService;
use PHPUnit\Framework\TestCase;

final class WechatCompetitionReportRendererServiceTest extends TestCase
{
    public function testLiteAndFlagshipRenderFromTheSameSavedBundle(): void
    {
        $service = new WechatCompetitionReportRendererService();
        $report = $this->availableReport();

        $lite = $service->render($report, '漠蓝酒店', 'lite');
        $flagship = $service->render($report, '漠蓝酒店', 'flagship');

        self::assertFalse($lite['status_only']);
        self::assertFalse($flagship['status_only']);
        self::assertSame('source-fingerprint-001', $lite['source_fingerprint']);
        self::assertSame($lite['source_fingerprint'], $flagship['source_fingerprint']);

        $liteContent = (string)($lite['payload']['markdown']['content'] ?? '');
        $flagshipContent = (string)($flagship['payload']['markdown']['content'] ?? '');
        self::assertStringContainsString('OTA竞争汇报（简版）', $liteContent);
        self::assertStringContainsString('漠蓝酒店', $liteContent);
        self::assertStringContainsString('重点竞品', $liteContent);
        self::assertStringContainsString('必须人工确认', $liteContent);
        self::assertStringNotContainsString('竞品分组', $liteContent);

        self::assertStringContainsString('OTA竞争汇报（旗舰版）', $flagshipContent);
        self::assertStringContainsString('渠道角色与第一矛盾', $flagshipContent);
        self::assertStringContainsString('竞品分组', $flagshipContent);
        self::assertStringContainsString('价格实验（人工确认后）', $flagshipContent);
        self::assertStringContainsString('观察：下一数据日', $flagshipContent);
        self::assertStringContainsString('回滚：订单未改善时停止', $flagshipContent);
        self::assertStringContainsString('auto_write_ota=false', $flagshipContent);
        self::assertLessThanOrEqual(3800, strlen($flagshipContent));
    }

    public function testPartialBundleCanOnlyProduceStatusReport(): void
    {
        $service = new WechatCompetitionReportRendererService();
        $report = $this->availableReport();
        $report['competition_circle_bundle']['quality'] = [
            'status' => 'partial',
            'decision_eligible' => true,
            'data_gaps' => [[
                'code' => 'meituan_source_missing',
                'message' => '美团目标日来源缺失。',
            ]],
        ];
        $report['competition_circle_bundle']['recommendations']['items'][0]['action'] = '立即调价20元';

        $rendered = $service->render($report, '漠蓝酒店', 'lite');
        $content = (string)($rendered['payload']['markdown']['content'] ?? '');

        self::assertTrue($rendered['status_only']);
        self::assertStringContainsString('部分可用，仅作情况汇报', $content);
        self::assertStringContainsString('本次不生成可执行调价、库存或投放建议', $content);
        self::assertStringContainsString('美团目标日来源缺失', $content);
        self::assertStringNotContainsString('立即调价20元', $content);
    }

    public function testCompactMessageLeavesDetailedTableForTheVisualCard(): void
    {
        $service = new WechatCompetitionReportRendererService();
        $report = $this->availableReport();
        $report['competition_circle_bundle']['quality']['status'] = 'blocked';
        $report['competition_circle_bundle']['quality']['decision_eligible'] = false;
        $report['competition_circle_bundle']['quality']['data_gaps'] = [
            ['code' => 'ctrip_readback_unverified', 'message' => '携程来源尚未通过数据库精确回读。'],
            ['code' => 'meituan_binding_missing', 'message' => '美团本店POI绑定未确认。'],
        ];

        $rendered = $service->renderCompact($report, '漠蓝酒店', 'flagship');
        $content = (string)($rendered['payload']['markdown']['content'] ?? '');

        self::assertTrue($rendered['status_only']);
        self::assertStringContainsString('宿析OS OTA竞争商圈', $content);
        self::assertStringContainsString('证据门槛尚未通过', $content);
        self::assertStringContainsString('随后图卡', $content);
        self::assertStringContainsString('当前缺口 2 项', $content);
        self::assertStringContainsString('来源指纹：source-fingerpri', $content);
        self::assertStringNotContainsString('直接竞品：', $content);
        self::assertLessThanOrEqual(1800, strlen($content));
    }

    public function testRendererRejectsUnknownEditionAndMissingBundle(): void
    {
        $service = new WechatCompetitionReportRendererService();

        $this->expectException(\InvalidArgumentException::class);
        $service->render($this->availableReport(), '漠蓝酒店', 'both');
    }

    public function testRendererRejectsReportWithoutCompetitionBundle(): void
    {
        $service = new WechatCompetitionReportRendererService();

        $this->expectException(\InvalidArgumentException::class);
        $service->render(['report_date' => '2026-07-24'], '漠蓝酒店', 'lite');
    }

    /** @return array<string, mixed> */
    private function availableReport(): array
    {
        return [
            'id' => 81,
            'report_date' => '2026-07-24',
            'summary' => '昨日OTA订单已完成数据库回读。',
            'competition_circle_bundle' => [
                'schema_version' => 'ota_competition_analysis_bundle.v1',
                'bundle_id' => 'ota-competition-81',
                'source_fingerprint' => 'source-fingerprint-001',
                'quality' => [
                    'status' => 'available',
                    'decision_eligible' => true,
                    'data_gaps' => [],
                ],
                'analysis' => [
                    'ctrip' => [
                        'status' => 'available',
                        'channel_role' => '守位渠道',
                        'first_conflict' => '转化率低于直接竞品。',
                        'price_experiment' => [
                            'hypothesis' => '复核主力房型与直接竞品价差。',
                            'observation_window' => '下一数据日',
                        ],
                    ],
                    'meituan' => [
                        'status' => 'available',
                        'channel_role' => '排名改善渠道',
                        'first_conflict' => '本店与TOP1存在排名差。',
                        'price_experiment' => null,
                    ],
                ],
                'candidate_competitors' => [
                    'ctrip' => [
                        'direct' => [['hotel_name' => '竞品甲']],
                        'attack_benchmark' => [['hotel_name' => '竞品乙']],
                        'traffic_benchmark' => [['hotel_name' => '竞品丙']],
                        'conversion_benchmark' => [['hotel_name' => '竞品丁']],
                    ],
                    'meituan' => [
                        'direct' => [],
                        'attack_benchmark' => [['hotel_name' => '竞品戊']],
                        'traffic_benchmark' => [],
                        'conversion_benchmark' => [],
                    ],
                ],
                'recommendations' => [
                    'status' => 'ready_for_manual_confirmation',
                    'auto_write_ota' => false,
                    'manual_confirmation_required' => true,
                    'items' => [[
                        'action' => '人工核对本店与直接竞品价差后再创建执行意图。',
                        'review_window' => '下一数据日',
                        'rollback_condition' => '订单未改善时停止',
                    ]],
                ],
                'render_contract' => [
                    'single_calculation' => true,
                    'lite_reads_same_bundle' => true,
                    'flagship_reads_same_bundle' => true,
                ],
            ],
        ];
    }
}
