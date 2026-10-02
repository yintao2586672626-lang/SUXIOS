<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

$serviceSource = getenv('SUXIOS_REVENUE_BASIS_SERVICE_SOURCE');
if (is_string($serviceSource) && $serviceSource !== '') {
    require_once $serviceSource;
}

final class RevenueAiDecisionBasisNavigationScopeTest extends TestCase
{
    private function overview(array $context = [], array $facts = []): array
    {
        $dataset = ['status' => $facts === [] ? 'empty' : 'ready', 'fact_ota_daily' => $facts];
        $channels = [
            'ctrip' => ['status' => 'empty', 'fact_ota_daily' => []],
            'meituan' => ['status' => 'empty', 'fact_ota_daily' => []],
        ];
        foreach ($facts as $fact) {
            $channels[$fact['platform_key']]['fact_ota_daily'][] = $fact;
            $channels[$fact['platform_key']]['status'] = 'ready';
        }
        return (new RevenueAiOverviewService())->buildOverviewFromDataset($dataset, $channels, [], array_merge([
            'hotel_id' => 81, 'business_date' => '2026-09-12', 'as_of_date' => '2026-09-26',
            'enabled_channels' => ['ctrip'],
        ], $context));
    }

    private function fact(string $platform = 'ctrip', int $hotelId = 81, string $date = '2026-09-12'): array
    {
        return [
            'date_key' => $date, 'hotel_key' => 'system:' . $hotelId, 'platform_key' => $platform,
            'data_type' => 'business', 'metric_scope' => 'ota_channel', 'calculation_basis' => 'ota_daily_standard_fact',
            'revenue' => 0.0, 'room_revenue' => 0.0, 'gross_revenue' => 0.0,
            'room_nights' => 0.0, 'occupied_room_nights' => 0.0, 'order_count' => 0,
            'available_room_nights' => null,
            'source_trace' => [
                'row_id' => 'synthetic-' . $platform . '-81', 'hotel_key' => 'system:' . $hotelId,
                'system_hotel_id' => $hotelId, 'platform' => $platform, 'data_type' => 'business', 'date_key' => $date,
                'source_trace_id' => $platform . ':' . $date . ':synthetic', 'stored' => true,
                'readback_verified' => true, 'saved_success' => true, 'failure_reasons' => [],
                'collected_at' => $date . ' 08:00:00', 'updated_at' => $date . ' 08:00:00',
            ],
        ];
    }

    private function gate(array $overview, string $key = 'ota_metrics'): array
    {
        return array_column($overview['pricing_readiness']['gates'], null, 'key')[$key];
    }

    private function assertNavigationChain(array $overview, array $expected, string $key = 'ota_metrics'): void
    {
        $gate = $this->gate($overview, $key);
        $action = $overview['actions'][0];
        $summary = array_column($action['decision_basis_summary']['items'], null, 'key')[$key];
        $plan = array_column($action['ai_decision_resolution_plan']['items'], null, 'code')[$key];
        foreach ([$gate, $summary, $plan] as $item) {
            self::assertSame('online-data', $item['target_page']);
            self::assertSame('data-health', $item['target_tab']);
            self::assertSame($expected, $item['target_filter'] ?? null);
        }
        self::assertSame($action['ai_decision_resolution_plan'], $overview['pricing_readiness']['ai_decision_resolution_plan']);
        self::assertSame($action['ai_decision_resolution_plan'], $action['ai_decision_review_contract']['resolution_plan']);
    }

    public function testHistoricalCtripGapCarriesExactParentScopeThroughBothVisibleProjections(): void
    {
        $overview = $this->overview();
        self::assertSame('目标日 OTA 收入和间夜', $this->gate($overview)['label']);
        self::assertSame('online_daily_data_empty', $this->gate($overview)['reason']);
        $this->assertNavigationChain($overview, ['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => 'ctrip']);
    }

    public function testAnotherHotelDateAndMeituanScopeAreNotBorrowedFromPriorOverview(): void
    {
        $overview = $this->overview(['hotel_id' => 82, 'business_date' => '2026-09-20', 'enabled_channels' => ['meituan']]);
        $this->assertNavigationChain($overview, ['hotel_id' => 82, 'business_date' => '2026-09-20', 'source' => 'meituan']);
    }

    public function testCombinedRequestedScopeIsNotNarrowedToTheOnlyPlatformWithFacts(): void
    {
        $overview = $this->overview(['enabled_channels' => ['ctrip', 'meituan']], [$this->fact()]);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        $this->assertNavigationChain($overview, ['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => 'all']);
    }

    public function testDefaultCombinedRequestDoesNotUseTheDisplayChannelsAsItsNavigationIdentity(): void
    {
        $overview = $this->overview(['enabled_channels' => []], [$this->fact()]);
        self::assertSame(['ctrip'], $overview['source_channels'], 'existing display evidence remains unchanged');
        $this->assertNavigationChain($overview, ['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => 'all']);
    }

    public function testMissingHotelRemainsExplicitlyMissingInsteadOfBorrowingTheFactHotel(): void
    {
        $overview = $this->overview(['hotel_id' => null], [$this->fact()]);
        self::assertNull($overview['hotel_id']);
        $this->assertNavigationChain($overview, ['hotel_id' => null, 'business_date' => '2026-09-12', 'source' => 'ctrip']);
    }

    public function testTargetDateComesFromTheScopedRequestEvenWhenSuppliedFactsBelongToAnotherDay(): void
    {
        $overview = $this->overview(['business_date' => '2026-09-20'], [$this->fact()]);
        self::assertSame('online_daily_data_empty', $this->gate($overview)['reason']);
        $this->assertNavigationChain($overview, ['hotel_id' => 81, 'business_date' => '2026-09-20', 'source' => 'ctrip']);
    }

    public function testTrueZeroStillBlocksAdrWithoutChangingMetricValuesOrPromotingReadiness(): void
    {
        $overview = $this->overview([], [$this->fact()]);
        self::assertSame(0.0, $overview['metrics']['ota_room_revenue']['value']);
        self::assertSame(0.0, $overview['metrics']['ota_room_nights']['value']);
        self::assertNull($overview['metrics']['ota_adr']['value']);
        self::assertSame('ota_room_nights_zero', $this->gate($overview)['reason']);
        self::assertSame('blocked', $this->gate($overview)['status']);
        $this->assertNavigationChain($overview, ['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => 'ctrip']);
    }

    public function testEveryExistingHealthGateUsesTheSameScopeWhileOperationRoutesStayUnfiltered(): void
    {
        $overview = $this->overview();
        $expected = ['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => 'ctrip'];
        $healthGates = 0;
        foreach ($overview['pricing_readiness']['gates'] as $gate) {
            if ($gate['target_page'] === 'online-data' && $gate['target_tab'] === 'data-health') {
                $healthGates++;
                self::assertSame($expected, $gate['target_filter'] ?? null, $gate['key']);
            } else {
                self::assertArrayNotHasKey('target_filter', $gate, $gate['key']);
            }
        }
        self::assertGreaterThan(1, $healthGates);
        $floorPrice = $this->gate($overview, 'floor_price');
        self::assertSame('floor_price_missing', $floorPrice['reason']);
        self::assertSame('blocked', $floorPrice['status']);
        $this->assertNavigationChain($overview, $expected, 'floor_price');
        self::assertSame('ops-track', $this->gate($overview, 'operation_feedback_input')['target_page']);
    }

    public function testAgentPreflightKeepsItsSuggestionDateStatusAndOriginalTargetFilter(): void
    {
        $filter = ['hotel_id' => 81, 'date' => '2026-09-12', 'status' => 1];
        $preflight = [
            'status' => 'pending_review_exists', 'reason' => 'price_suggestions_pending_review',
            'hotel_id' => 81, 'business_date' => '2026-09-12', 'target_page' => 'agent-center',
            'target_tab' => 'suggestions', 'target_agent_tab' => 'revenue', 'target_revenue_tab' => 'suggestions',
            'target_filter' => $filter,
        ];
        $overview = $this->overview(['pricing_generation_preflight' => $preflight]);
        self::assertSame($filter, $overview['pricing_generation_preflight']['target_filter']);
        self::assertSame($filter, $overview['actions'][0]['pricing_generation_preflight']['target_filter']);
        $gate = $this->gate($overview, 'pricing_generation_preflight');
        self::assertSame('agent-center', $gate['target_page']);
        self::assertArrayNotHasKey('target_filter', $gate, 'the new health scope must not replace agent navigation');
    }

    public function testExistingPrivateCallWithoutOptionalScopeKeepsItsUnscopedContract(): void
    {
        $method = new ReflectionMethod(RevenueAiOverviewService::class, 'pricingReadiness');
        $readiness = $method->invoke(new RevenueAiOverviewService(), ['status' => 'empty', 'totals' => []], [], [], []);
        self::assertSame('blocked', $readiness['overall_status']);
        foreach ($readiness['gates'] as $gate) self::assertArrayNotHasKey('target_filter', $gate);
        foreach ($readiness['ai_decision_resolution_plan']['items'] as $item) self::assertArrayNotHasKey('target_filter', $item);
    }

    public function testExplicitScopeWithNoKnownChannelDoesNotDefaultToAllOrCtrip(): void
    {
        $method = new ReflectionMethod(RevenueAiOverviewService::class, 'pricingReadiness');
        $readiness = $method->invoke(new RevenueAiOverviewService(), ['status' => 'empty', 'totals' => []], [], [], [], [], [], [], [], [], [
            'hotel_id' => 81, 'business_date' => '2026-09-12', 'source_channels' => [],
        ]);
        $gate = array_column($readiness['gates'], null, 'key')['ota_metrics'];
        self::assertSame(['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => null], $gate['target_filter'] ?? null);
    }

    public function testSummaryAndResolutionPreserveAnExplicitMissingScopeWithoutInventingFields(): void
    {
        $service = new RevenueAiOverviewService();
        $filter = ['hotel_id' => null, 'business_date' => '2026-09-12', 'source' => null];
        $gate = [
            'key' => 'ota_metrics', 'label' => '目标日 OTA 收入和间夜', 'status' => 'blocked',
            'reason' => 'online_daily_data_empty', 'target_page' => 'online-data', 'target_tab' => 'data-health',
            'target_platform' => '', 'target_filter' => $filter,
        ];
        $summary = (new ReflectionMethod($service, 'pricingDecisionBasisSummary'))->invoke($service, ['gates' => [$gate]]);
        $plan = (new ReflectionMethod($service, 'pricingAiDecisionResolutionPlan'))->invoke($service, $summary['items']);
        self::assertSame($filter, $summary['items'][0]['target_filter'] ?? null);
        self::assertSame($filter, $plan['items'][0]['target_filter'] ?? null);
    }

    public function testSpecificGatePlatformMustBelongToTheRequestScope(): void
    {
        $method = new ReflectionMethod(RevenueAiOverviewService::class, 'pricingDecisionTargetFilter');
        $service = new RevenueAiOverviewService();
        foreach ([
            ['ctrip', ['ctrip', 'meituan'], 'ctrip'],
            ['meituan', ['ctrip', 'meituan'], 'meituan'],
            ['ctrip', ['meituan'], null],
            ['meituan', ['ctrip'], null],
            ['qunar', ['ctrip', 'meituan'], null],
            ['hotel', ['meituan'], 'meituan'],
            ['ota', ['ctrip', 'meituan'], 'all'],
        ] as [$platform, $channels, $expectedSource]) {
            $filter = $method->invoke($service, ['target_platform' => $platform], [
                'hotel_id' => 81, 'business_date' => '2026-09-12', 'source_channels' => $channels,
            ]);
            self::assertSame(['hotel_id' => 81, 'business_date' => '2026-09-12', 'source' => $expectedSource], $filter);
        }
    }

    public function testIncompleteInternalScopeCannotFillHotelDateOrChannelFromUnrelatedInputs(): void
    {
        $method = new ReflectionMethod(RevenueAiOverviewService::class, 'pricingDecisionTargetFilter');
        $filter = $method->invoke(new RevenueAiOverviewService(), ['target_platform' => 'ctrip'], [
            'hotel_id' => null, 'source_channels' => ['qunar', 'pms'],
        ]);
        self::assertSame(['hotel_id' => null, 'business_date' => null, 'source' => null], $filter);
    }

    public function testInvalidZeroHotelStillUsesTheExistingOverviewRejection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid hotel_id');
        $this->overview(['hotel_id' => 0]);
    }
}
