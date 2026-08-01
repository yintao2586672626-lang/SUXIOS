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
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-18',
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
        self::assertTrue($item['decision_quality']['format_complete']);
        self::assertFalse($item['decision_quality']['complete']);
        self::assertFalse($item['decision_quality']['effect_ready']);
        self::assertFalse($item['can_create_execution_intent']);
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
        self::assertNotSame('加强管理', $item['action']);
        self::assertNotSame('加强管理', $item['title']);
        self::assertStringContainsString('不得执行', $item['action']);
        self::assertSame($item['action'], $item['detail']);

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
        self::assertFalse($items[0]['can_create_execution_intent']);
        self::assertStringContainsString('数据依据', $items[0]['blocked_reason']);
    }

    public function testUnverifiedEvidenceIsFormatCompleteButNeverExecutionReady(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '复核携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_effect' => [
                'metric' => 'ota_adr',
                'direction' => 'verify',
                'summary' => '验证主力房型价格调整是否改善携程渠道ADR。',
                'review_window' => '执行后7天按同房型、同价盘口径复核',
            ],
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'online_daily_data#7#ctrip#2026-07-19',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'unverified',
            ]],
        ])[0];

        self::assertSame('unverified', $item['data_basis']['status']);
        self::assertTrue($item['decision_quality']['format_complete']);
        self::assertFalse($item['decision_quality']['complete']);
        self::assertFalse($item['decision_quality']['execution_ready']);
        self::assertFalse($item['can_create_execution_intent']);
        self::assertContains('data_basis_verification', $item['decision_quality']['missing_fields']);
    }

    public function testRecommendationCannotSelfAssertVerifiedEvidence(): void
    {
        $service = new AiDecisionQualityService();
        $item = $service->enrichRecommendations([[
            'title' => '调整携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_effect' => '验证携程主力房型调价后ADR是否改善',
            'data_basis' => ['refs' => [[
                'ref' => 'model_claimed#1',
                'source' => '模型自报来源',
                'quality_status' => 'verified',
                'readback_verified' => true,
            ]]],
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
        ])[0];

        self::assertSame('unverified', $item['data_basis']['status']);
        self::assertSame('untrusted_recommendation', $item['data_basis']['refs'][0]['authority']);
        self::assertFalse($item['can_create_execution_intent']);

        $matched = $service->enrichRecommendations([[
            'title' => '调整携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_effect' => '验证携程主力房型调价后ADR是否改善',
            'evidence_refs' => ['server#1'],
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'server#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];

        self::assertSame('verified', $matched['data_basis']['status']);
        self::assertSame('server_context', $matched['data_basis']['refs'][0]['authority']);
    }

    public function testEvidenceMustMatchHotelPlatformAndTargetDate(): void
    {
        $service = new AiDecisionQualityService();
        $recommendation = [[
            'title' => '调整携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_effect' => '验证携程主力房型调价后ADR是否改善',
        ]];

        $stale = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'old#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2025-01-01',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('stale', $stale['data_basis']['status']);
        self::assertFalse($stale['can_create_execution_intent']);

        $wrongBinding = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'wrong#1',
                'source' => 'online_daily_data',
                'hotel_id' => 8,
                'platform' => 'meituan',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $wrongBinding['data_basis']['status']);
        self::assertFalse($wrongBinding['can_create_execution_intent']);

        $unbound = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 0,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'unbound#1',
                'source' => 'online_daily_data',
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $unbound['data_basis']['status']);
        self::assertFalse($unbound['can_create_execution_intent']);

        $missingIdentity = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'identity-missing#1',
                'source' => 'online_daily_data',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $missingIdentity['data_basis']['status']);
        self::assertSame('hotel_missing', $missingIdentity['data_basis']['refs'][0]['binding_status']);
        self::assertFalse($missingIdentity['data_basis']['refs'][0]['date_inherited']);

        $missingPlatform = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'platform-missing#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $missingPlatform['data_basis']['status']);
        self::assertSame('platform_missing', $missingPlatform['data_basis']['refs'][0]['binding_status']);
        self::assertFalse($missingPlatform['can_create_execution_intent']);

        $missingDate = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'date-missing#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $missingDate['data_basis']['status']);
        self::assertSame('date_missing', $missingDate['data_basis']['refs'][0]['binding_status']);
        self::assertFalse($missingDate['can_create_execution_intent']);

        $rangeStale = $service->enrichRecommendations($recommendation, [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'date_range' => ['start_date' => '2026-07-19', 'end_date' => '2026-07-19'],
            'evidence_sources' => [[
                'ref' => 'range-stale#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2025-01-01',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('stale', $rangeStale['data_basis']['status']);

        $itemPlatformMismatch = $service->enrichRecommendations([array_merge($recommendation[0], [
            'platform' => 'ctrip',
        ])], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'date_range' => ['start' => '2026-07-19', 'end' => '2026-07-19'],
            'evidence_sources' => [[
                'ref' => 'meituan-only#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'meituan',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];
        self::assertSame('binding_missing', $itemPlatformMismatch['data_basis']['status']);
        self::assertSame('platform_mismatch', $itemPlatformMismatch['data_basis']['refs'][0]['binding_status']);
    }

    public function testRecommendationCannotSelfAuthorizeExpectedEffect(): void
    {
        $context = [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'online_daily_data#7#ctrip#2026-07-19',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ];
        $recommendation = [[
            'title' => '调整携程主力房型',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_metric' => 'ota_adr',
            'expected_effect' => 'AI判断调整后携程ADR应当改善',
        ]];

        $untrusted = (new AiDecisionQualityService())->enrichRecommendations($recommendation, $context)[0];
        self::assertSame('recommendation_provided_unverified', $untrusted['expected_effect']['origin']);
        self::assertFalse($untrusted['decision_quality']['effect_ready']);
        self::assertFalse($untrusted['can_create_execution_intent']);
        self::assertSame('limited', $untrusted['priority_basis']['status']);
        self::assertSame('not_quantified', $untrusted['priority_basis']['factors']['impact']);

        $policy = $context;
        $policy['expected_effect_policy'] = [
            'metric' => 'ota_adr',
            'direction' => 'verify',
            'summary' => '预期验证该动作对携程ADR的影响；复盘前不承诺改善幅度。',
            'review_window' => '执行后7天按同酒店、同房型、同价盘口径复核',
        ];
        $serverControlled = (new AiDecisionQualityService())->enrichRecommendations($recommendation, $policy)[0];
        self::assertSame('server_policy_verification_target', $serverControlled['expected_effect']['origin']);
        self::assertTrue($serverControlled['decision_quality']['effect_ready']);
        self::assertTrue($serverControlled['can_create_execution_intent']);
    }

    public function testCollectionSuccessAloneIsNotDecisionEvidence(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '调整携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_effect' => '验证携程主力房型调价后ADR是否改善',
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'collector_task#1',
                'source' => 'collector_task',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'success',
            ]],
        ])[0];

        self::assertSame('unverified', $item['data_basis']['status']);
        self::assertSame('success', $item['data_basis']['refs'][0]['source_status']);
        self::assertFalse($item['can_create_execution_intent']);
    }

    public function testPlatformCueAloneDoesNotMakeGenericActionSpecific(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '渠道优化',
            'action' => '优化携程运营',
            'expected_effect' => '改善携程渠道表现',
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'server#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];

        self::assertTrue($item['decision_quality']['generic_talk_rejected']);
        self::assertFalse($item['decision_quality']['action_specificity']['specific']);
        self::assertFalse($item['can_create_execution_intent']);
    }

    public function testRecommendationPlatformCannotOverrideExactServerBinding(): void
    {
        $context = [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'online_daily_data#7#ctrip#2026-07-19',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
            'expected_effect_policy' => [
                'metric' => 'ota_adr',
                'direction' => 'verify',
                'summary' => '执行后核验携程ADR，未完成回读前不承诺提升幅度。',
                'review_window' => '执行后7天按同酒店、同房型、同价盘口径复核',
            ],
        ];
        $base = [
            'title' => '复核携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_metric' => 'ota_adr',
        ];

        $conflict = (new AiDecisionQualityService())->enrichRecommendations([
            array_merge($base, ['platform' => 'meituan']),
        ], $context)[0];
        self::assertSame('binding_missing', $conflict['data_basis']['status']);
        self::assertTrue($conflict['data_basis']['platform_conflict']);
        self::assertSame('recommendation_platform_conflict', $conflict['data_basis']['refs'][0]['binding_status']);
        self::assertFalse($conflict['can_create_execution_intent']);

        $genericPlatform = (new AiDecisionQualityService())->enrichRecommendations([
            array_merge($base, ['platform' => 'ota']),
        ], $context)[0];
        self::assertSame('ctrip', $genericPlatform['data_basis']['platform']);
        self::assertFalse($genericPlatform['data_basis']['platform_conflict']);
        self::assertSame('verified', $genericPlatform['data_basis']['status']);
        self::assertTrue($genericPlatform['can_create_execution_intent']);
    }

    public function testInferredEffectAndPriorityExposeTheirEvidenceLimits(): void
    {
        $item = (new AiDecisionQualityService())->enrichRecommendations([[
            'title' => '复核携程价格',
            'action' => '复核未来7天携程主力房型价盘，并按ADR记录调整前后结果',
            'expected_metric' => 'ota_adr',
        ]], [
            'scope' => 'ota_channel',
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'data_date' => '2026-07-19',
            'evidence_sources' => [[
                'ref' => 'server#1',
                'source' => 'online_daily_data',
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'data_date' => '2026-07-19',
                'quality_status' => 'readback_verified',
            ]],
        ])[0];

        self::assertSame('inferred_directional', $item['expected_effect']['origin']);
        self::assertFalse($item['decision_quality']['effect_ready']);
        self::assertFalse($item['can_create_execution_intent']);
        self::assertArrayHasKey('score', $item['priority_basis']);
        self::assertArrayHasKey('confidence', $item['priority_basis']['factors']);
        self::assertStringContainsString('证据', $item['priority_reason']);
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
