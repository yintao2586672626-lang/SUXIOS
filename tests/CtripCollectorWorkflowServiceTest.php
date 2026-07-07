<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripCollectorWorkflowService;
use PHPUnit\Framework\TestCase;

final class CtripCollectorWorkflowServiceTest extends TestCase
{
    public function testCollectorFlowOptionsMapSkillFlowsToSuxiosCaptureSections(): void
    {
        $service = new CtripCollectorWorkflowService();

        $review = $service->applyFlowOptions(['collector_flow' => 'review_only']);
        self::assertSame('comment_review', $review['capture_sections']);
        self::assertSame('historical_daily', $review['data_period']);

        $full = $service->applyFlowOptions(['collector_flow' => 'full']);
        self::assertSame('wide', $full['capture_sections']);
        self::assertSame('historical_daily', $full['data_period']);

        $realtime = $service->applyFlowOptions(['collector_flow' => 'realtime']);
        self::assertSame('homepage,traffic_report', $realtime['capture_sections']);
        self::assertSame('realtime_snapshot', $realtime['data_period']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $realtime['data_date']);
    }

    public function testCollectCtripFalseBlocksCollectionGate(): void
    {
        $service = new CtripCollectorWorkflowService();

        $gate = $service->collectionGate([
            'platform' => 'ctrip',
            'config' => ['collect_ctrip' => false],
        ], ['collector_flow' => 'full']);

        self::assertFalse($gate['allowed']);
        self::assertSame('collect_ctrip_disabled', $gate['reason']);
        self::assertSame('full', $gate['collector_flow']);
    }

    public function testRealtimeValidationAcceptsAnyCoreRealtimeField(): void
    {
        $service = new CtripCollectorWorkflowService();

        $result = $service->validateRealtimeRows([
            [
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => 'realtime:ctrip',
                'raw_data' => [
                    'metrics' => ['realtime_visitors' => 128],
                ],
            ],
        ]);

        self::assertSame('ready', $result['status']);
        self::assertContains('ctrip_visitor', $result['found_fields']);
    }

    public function testCtripFamilySubChannelsStayInCtripScopeAndWarnOnAllZeroRoomNights(): void
    {
        $service = new CtripCollectorWorkflowService();

        $audit = $service->auditSubChannels([
            [
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'dimension' => 'realtime:tongcheng',
                'quantity' => 0,
                'raw_data' => ['channel' => 'tongcheng'],
            ],
            [
                'source' => 'qunar',
                'platform' => 'qunar',
                'dimension' => 'realtime:qunar',
                'quantity' => 1,
                'raw_data' => ['channel' => 'qunar'],
            ],
        ]);

        self::assertSame('warning', $audit['status']);
        self::assertArrayHasKey('tongcheng', $audit['channels']);
        self::assertContains('do_not_fill_ota_room_nights_from_pms', $audit);
        $codes = array_column($audit['warnings'], 'code');
        self::assertContains('ctrip_family_room_nights_all_zero_suspicious', $codes);
        self::assertContains('ctrip_family_channel_source_not_ctrip', $codes);
    }
}
