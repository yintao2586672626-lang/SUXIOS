<?php
declare(strict_types=1);

namespace Tests;

use app\service\WechatCompetitionVisualCardService;
use PHPUnit\Framework\TestCase;

final class WechatCompetitionVisualCardServiceTest extends TestCase
{
    public function testBlockedBundleBuildsCandidateTableWithoutActions(): void
    {
        $model = (new WechatCompetitionVisualCardService())->buildModel(
            $this->report(false),
            '敦煌漠蓝新',
            'flagship'
        );

        self::assertSame('suxi.wecom.competition.visual-card.v1', $model['schema']);
        self::assertSame('敦煌漠蓝新', $model['hotel_name']);
        self::assertSame('旗舰版', $model['edition_label']);
        self::assertSame('blocked', $model['quality_status']);
        self::assertTrue($model['status_only']);
        self::assertCount(2, $model['platforms']);
        self::assertSame('暂不判断', $model['platforms'][0]['channel_role']);
        self::assertCount(4, $model['competitor_groups']);
        self::assertSame('直接竞品', $model['competitor_groups'][0]['label']);
        self::assertSame('竞品甲', $model['competitor_groups'][0]['items'][0]['hotel_name']);
        self::assertSame([], $model['actions']);
        self::assertSame(['携程来源尚未通过数据库精确回读。'], $model['gaps']);
        self::assertSame('source-fingerprint-001', $model['source_fingerprint']);
    }

    public function testAvailableBundleShowsSavedJudgmentAndManualAction(): void
    {
        $model = (new WechatCompetitionVisualCardService())->buildModel(
            $this->report(true),
            '敦煌漠蓝新',
            'lite'
        );

        self::assertFalse($model['status_only']);
        self::assertSame('守位渠道', $model['platforms'][0]['channel_role']);
        self::assertSame('转化率低于直接竞品。', $model['platforms'][0]['first_conflict']);
        self::assertSame(['人工核对本店与直接竞品价差。'], $model['actions']);
        self::assertSame([], $model['gaps']);
        self::assertSame('不自动改价、库存或投放；经营动作仍需人工批准。', $model['automation_note']);
    }

    public function testRepeatedCandidateGroupsAreExplainedInsteadOfDuplicated(): void
    {
        $report = $this->report(false);
        $report['competition_circle_bundle']['candidate_competitors']['ctrip']['traffic_benchmark']
            = $report['competition_circle_bundle']['candidate_competitors']['ctrip']['attack_benchmark'];

        $model = (new WechatCompetitionVisualCardService())->buildModel(
            $report,
            '敦煌漠蓝新',
            'flagship'
        );

        self::assertSame([], $model['competitor_groups'][2]['items']);
        self::assertStringContainsString(
            '与进攻标杆候选重合',
            $model['competitor_groups'][2]['overlap_note']
        );
    }

    /** @return array<string, mixed> */
    private function report(bool $available): array
    {
        return [
            'id' => 30,
            'report_date' => '2026-07-23',
            'competition_circle_bundle' => [
                'bundle_id' => 'ota-competition-80-20260723',
                'source_fingerprint' => 'source-fingerprint-001',
                'quality' => [
                    'status' => $available ? 'available' : 'blocked',
                    'decision_eligible' => $available,
                    'data_gaps' => $available ? [] : [[
                        'code' => 'ctrip_readback_unverified',
                        'message' => '携程来源尚未通过数据库精确回读。',
                    ]],
                ],
                'analysis' => [
                    'ctrip' => [
                        'status' => $available ? 'available' : 'blocked',
                        'channel_role' => $available ? '守位渠道' : null,
                        'first_conflict' => $available ? '转化率低于直接竞品。' : null,
                        'blocked_reason' => $available ? null : 'ctrip_readback_unverified',
                    ],
                    'meituan' => [
                        'status' => $available ? 'available' : 'blocked',
                        'channel_role' => $available ? '流量渠道' : null,
                        'first_conflict' => $available ? '曝光不足。' : null,
                        'blocked_reason' => $available ? null : 'meituan_binding_missing',
                    ],
                ],
                'candidate_competitors' => [
                    'ctrip' => [
                        'direct' => [[
                            'hotel_name' => '竞品甲',
                            'adr' => 1023.33,
                            'room_nights' => 3,
                            'candidate_only' => true,
                        ]],
                        'attack_benchmark' => [['hotel_name' => '竞品乙']],
                        'traffic_benchmark' => [['hotel_name' => '竞品丙']],
                        'conversion_benchmark' => [['hotel_name' => '竞品丁']],
                    ],
                    'meituan' => [
                        'direct' => [],
                        'attack_benchmark' => [],
                        'traffic_benchmark' => [],
                        'conversion_benchmark' => [],
                    ],
                ],
                'recommendations' => [
                    'status' => $available ? 'ready_for_manual_confirmation' : 'withheld',
                    'auto_write_ota' => false,
                    'items' => $available ? [[
                        'action' => '人工核对本店与直接竞品价差。',
                    ]] : [],
                ],
            ],
        ];
    }
}
