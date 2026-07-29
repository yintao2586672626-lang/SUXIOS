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
        self::assertSame('full', $review['capture_plan']);
        self::assertSame('historical_daily', $review['data_period']);

        $full = $service->applyFlowOptions(['collector_flow' => 'full']);
        self::assertSame('wide', $full['capture_sections']);
        self::assertSame('full', $full['capture_plan']);
        self::assertSame('historical_daily', $full['data_period']);

        $bounded = $service->applyFlowOptions([
            'collector_flow' => 'full',
            'bounded_capture_sections' => 'business_overview',
        ]);
        self::assertSame('business_overview', $bounded['capture_sections']);
        self::assertSame('business_overview', $bounded['profile_sections']);
        self::assertSame('historical_daily', $bounded['data_period']);

        $history = $service->applyFlowOptions(['collector_flow' => 'past_review']);
        self::assertSame('historical_review', $history['collector_flow']);
        self::assertSame('traffic_report', $history['capture_sections']);
        self::assertSame('historical_review', $history['capture_plan']);
        self::assertSame('historical_daily', $history['data_period']);

        $realtime = $service->applyFlowOptions(['collector_flow' => 'realtime']);
        self::assertSame('business_overview,traffic_report', $realtime['capture_sections']);
        self::assertSame('realtime_broadcast', $realtime['capture_plan']);
        self::assertSame('realtime_snapshot', $realtime['data_period']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $realtime['data_date']);

        $trend = $service->applyFlowOptions(['collector_flow' => 'intraday_trend']);
        self::assertSame('traffic_report', $trend['capture_sections']);
        self::assertSame('intraday_trend', $trend['capture_plan']);
        self::assertSame('realtime_hourly_trend', $trend['data_period']);

        $future = $service->applyFlowOptions(['collector_flow' => 'future_demand']);
        self::assertSame('traffic_report', $future['capture_sections']);
        self::assertSame('future_demand', $future['capture_plan']);
        self::assertSame('future_30_day_search_demand', $future['data_period']);
    }

    public function testTemporalPushContractKeepsPastPresentFutureAndTruthfulRenderingRules(): void
    {
        $contract = (new CtripCollectorWorkflowService())->buildContract([
            'platform' => 'ctrip',
            'config' => ['collect_ctrip' => true],
        ], ['collector_flow' => 'historical_review']);

        self::assertSame(
            ['past', 'present', 'future'],
            array_keys($contract['temporal_push_contract']['segments'])
        );
        self::assertSame(
            ['yesterday', 'last_7_days', 'last_30_days'],
            $contract['temporal_push_contract']['segments']['past']['windows']
        );
        self::assertContains(
            'intraday_visitor_trend',
            $contract['temporal_push_contract']['segments']['present']['push_items']
        );
        self::assertContains(
            'future_search_uv',
            $contract['temporal_push_contract']['segments']['future']['push_items']
        );
        self::assertSame(
            3600,
            $contract['temporal_push_contract']['rendering_rules']['stale_warning_after_seconds']
        );
        self::assertSame(
            'omit_from_external_message_keep_internal_gap',
            $contract['temporal_push_contract']['rendering_rules']['missing_value_policy']
        );
        self::assertContains(
            'competitor_circle_rank',
            $contract['temporal_push_contract']['rendering_rules']['excluded_items']
        );
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
