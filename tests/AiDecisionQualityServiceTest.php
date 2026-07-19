<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\AiDecisionQualityService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AiDecisionQualityServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testVerifiedOtaRecommendationContainsFiveDecisionFieldsWithoutInventedLift(): void
    {
        $items = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '优化携程价格带',
            'action' => '人工复核未来7天同房型价盘后，调整高价日期的携程报价',
            'priority' => 'P1',
            'expected_metric' => 'ota_adr',
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-18',
            'basis_summary' => '依据携程目标日房价、订单与同房型竞品样本。',
            'evidence_sources' => [[
                'ref' => 'online_daily_data#2026-07-18#7#ctrip',
                'source' => 'online_daily_data',
                'quality_status' => 'readback_verified',
                'metric_keys' => ['amount', 'quantity', 'competitor_price'],
            ]],
        ]);

        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame('P1', $item['priority']);
        self::assertSame('ota_channel', $item['data_basis']['scope']);
        self::assertSame('verified', $item['data_basis']['status']);
        self::assertSame('人工复核未来7天同房型价盘后，调整高价日期的携程报价', $item['action']);
        self::assertSame('ota_adr', $item['expected_effect']['metric']);
        self::assertSame('', $item['expected_effect']['target']);
        self::assertFalse($item['expected_effect']['quantified']);
        self::assertNotSame('', $item['risk']['summary']);
        self::assertTrue($item['decision_quality']['complete']);
        self::assertTrue($item['decision_quality']['human_confirmation_required']);
    }

    public function testGenericManagementTalkIsRejectedAndCannotCreateExecutionIntent(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations('加强管理')[0];

        self::assertSame('P0', $item['priority']);
        self::assertSame('missing', $item['data_basis']['status']);
        self::assertSame('high', $item['risk']['level']);
        self::assertTrue($item['decision_quality']['generic_talk_rejected']);
        self::assertFalse($item['decision_quality']['complete']);
        self::assertContains('action_specificity', $item['decision_quality']['missing_fields']);
        self::assertFalse($item['can_create_execution_intent']);
        self::assertNotSame('', $item['blocked_reason']);

        $longGeneric = (new AiDecisionQualityService())->enrichRecommendations('建议持续关注市场变化并进一步优化整体经营策略')[0];
        self::assertTrue($longGeneric['decision_quality']['generic_talk_rejected']);
        self::assertFalse($longGeneric['can_create_execution_intent']);
    }

    public function testMeasuredDeltaIsPreservedAsQuantifiedExpectedEffect(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '优化详情页转化',
            'action' => '替换携程主图并按同一曝光口径进行A/B复核',
            'expected_metric' => 'detail_rate',
            'expected_delta' => 0.8,
            'risk' => [
                'level' => 'medium',
                'summary' => '素材变化可能降低点击，需要保留旧版本作为回滚基线。',
            ],
        ]], [
            'scope' => 'ota_channel',
            'basis_summary' => '依据已完成的同酒店同渠道A/B样本。',
            'evidence_sources' => [[
                'ref' => 'ab_test#detail_rate#2026-07-18',
                'source' => 'A/B复核记录',
                'quality_status' => 'verified',
            ]],
        ])[0];

        self::assertSame('quantified', $item['expected_effect']['status']);
        self::assertTrue($item['expected_effect']['quantified']);
        self::assertStringContainsString('0.8', $item['expected_effect']['target']);
        self::assertSame('provided', $item['risk']['status']);
    }

    public function testSingleAssociativeRecommendationAndLegacyDataBasisRemainReadable(): void
    {
        $items = (new AiDecisionQualityService())->enrichRecommendations([
            'title' => '补齐昨日数据',
            'detail' => '同步携程昨日订单并完成数据库回读',
            'data_basis' => '历史文本依据',
        ], [
            'scope' => 'ota_channel',
        ]);

        self::assertCount(1, $items);
        self::assertSame('补齐昨日数据', $items[0]['title']);
        self::assertSame('data_completeness', $items[0]['expected_effect']['metric']);
        self::assertSame('missing', $items[0]['data_basis']['status']);
    }

    public function testCapturedOtaReportConvertsMainAndHotelSuggestionsToStructuredActions(): void
    {
        $controller = (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $report = $this->invokeNonPublic($controller, 'attachCapturedOtaRecommendationQuality', [[
            'priority' => 'high',
            'recommended_actions' => ['复核携程低转化日期的房型价格与取消政策'],
            'problem_hotels' => [[
                'hotel_name' => '样板店',
                'problem' => '订单转化率低于本次同口径样本',
                'key_metrics' => ['order_rate'],
                'suggestion' => '核对主力房型价盘并保留调整前基线',
            ]],
            'data_quality' => ['is_reliable' => true, 'warning' => ''],
        ], [
            'scope' => [
                'platform' => 'ctrip',
                'start_date' => '2026-07-18',
                'end_date' => '2026-07-18',
            ],
            'hotel_count' => 2,
            'totals' => ['orders' => 12, 'views' => 240],
        ]]);

        self::assertCount(2, $report['decision_recommendations']);
        self::assertSame('P0', $report['decision_recommendations'][0]['priority']);
        self::assertSame('ota_channel_multi_hotel', $report['decision_recommendations'][0]['data_basis']['scope']);
        self::assertSame('verified', $report['decision_recommendations'][0]['data_basis']['status']);
        self::assertSame('样板店处置建议', $report['decision_recommendations'][1]['title']);
        self::assertArrayHasKey('recommendation_quality', $report);
        self::assertContains('problem_hotels[].suggestion', $report['legacy_recommendation_fields']);
    }
}
