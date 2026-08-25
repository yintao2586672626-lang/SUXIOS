<?php
declare(strict_types=1);

use app\service\RevenueDecisionSnapshotService;
use app\service\RevenueDecisionViewModelAttestationService;
use PHPUnit\Framework\TestCase;

final class RevenueDecisionSnapshotServiceTest extends TestCase
{
    /** @return list<string> */
    private function opportunityKeys(): array
    {
        return [
            'traffic_entry_shortage',
            'detail_conversion_shortage',
            'submit_payment_conversion_shortage',
            'cancellation_anomaly',
            'price_competition_position',
            'bookability_gap',
            'service_promise_risk',
            'promotion_incrementality_evidence',
        ];
    }

    /** @return array<string,mixed> */
    private function visibleModel(): array
    {
        return [
            'contractVersion' => 'revenue_daily_cockpit.v2',
            'tenantId' => 9,
            'hotelId' => 80,
            'businessDate' => '2026-08-20',
            'selectedPlatform' => 'all_ota',
            'visibleSections' => [[
                'key' => 'opportunity_ranking',
                'cards' => [[
                    'key' => 'opportunity:promotion_incrementality_evidence',
                    'kind' => 'opportunity',
                    'label' => '促销增量证据不足',
                    'status' => 'evidence_investigation',
                    'reasonCode' => 'promotion_causal_design_missing|campaign_stage_missing',
                    'sourceKey' => 'cockpit_rule',
                ]],
            ]],
            'opportunities' => array_map(
                static fn(string $key): array => [
                    'opportunityKey' => $key,
                    'title' => $key,
                    'state' => 'evidence_investigation',
                    'recommendedCheckAction' => '由用户核对事实后决定是否送审。',
                    'causalityClaimed' => false,
                ],
                $this->opportunityKeys()
            ),
        ];
    }

    public function testExactEightOpportunityModelPassesAndPipeReasonCodesStayExplicit(): void
    {
        $service = new RevenueDecisionSnapshotService();
        $validate = new ReflectionMethod($service, 'validateVisibleModel');
        $model = $validate->invoke($service, $this->visibleModel(), 9, 80, '2026-08-20', 'all_ota');

        self::assertSame('revenue_daily_cockpit.v2', $model['contractVersion']);
        self::assertCount(8, $model['opportunities']);

        $missingItems = (new ReflectionMethod($service, 'missingItems'))->invoke($service, $model);
        self::assertSame(
            'promotion_causal_design_missing|campaign_stage_missing',
            $missingItems[0]['reason_code']
        );
    }

    public function testIncompleteOpportunitySetIsRejected(): void
    {
        $model = $this->visibleModel();
        array_pop($model['opportunities']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revenue_decision_snapshot_opportunity_set_incomplete');
        (new ReflectionMethod(RevenueDecisionSnapshotService::class, 'validateVisibleModel'))->invoke(
            new RevenueDecisionSnapshotService(),
            $model,
            9,
            80,
            '2026-08-20',
            'all_ota'
        );
    }

    public function testCausalClaimCannotBeSmuggledIntoSnapshot(): void
    {
        $model = $this->visibleModel();
        $model['opportunities'][0]['causalityClaimed'] = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revenue_decision_snapshot_opportunity_invalid');
        (new ReflectionMethod(RevenueDecisionSnapshotService::class, 'validateVisibleModel'))->invoke(
            new RevenueDecisionSnapshotService(),
            $model,
            9,
            80,
            '2026-08-20',
            'all_ota'
        );
    }

    public function testMetricAndActionBoundariesRemainTruthful(): void
    {
        $service = new RevenueDecisionSnapshotService();
        $definitions = (new ReflectionMethod($service, 'metricDefinitions'))->invoke($service);
        $boundaries = (new ReflectionMethod($service, 'boundaries'))->invoke($service);

        self::assertSame('order_amount', $definitions['revenue']['source_meaning']);
        self::assertSame('order_amount / room_nights', $definitions['adr']['formula']);
        self::assertFalse($boundaries['automatic_approval']);
        self::assertFalse($boundaries['automatic_pricing']);
        self::assertFalse($boundaries['ota_write']);
        self::assertFalse($boundaries['causality_claimed']);
    }

    public function testPendingRecommendationTextComesFromServerDefinition(): void
    {
        $definition = (new ReflectionMethod(
            RevenueDecisionSnapshotService::class,
            'opportunityDefinition'
        ))->invoke(new RevenueDecisionSnapshotService(), 'promotion_incrementality_evidence');

        self::assertSame('促销增量证据不足', $definition['title']);
        self::assertStringContainsString('当前不宣称因果', $definition['action_text']);
    }

    public function testMigrationMakesSnapshotsAppendOnly(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260823_zzzz_create_revenue_decision_snapshots.sql'
        );

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `revenue_decision_snapshots`', $sql);
        self::assertStringContainsString('visible_model_digest', $sql);
        self::assertStringContainsString('evidence_digest', $sql);
        self::assertStringContainsString('content_digest', $sql);
        self::assertStringContainsString('BEFORE UPDATE ON `revenue_decision_snapshots`', $sql);
        self::assertStringContainsString('BEFORE DELETE ON `revenue_decision_snapshots`', $sql);
    }

    public function testStrictMetricAttestationWithholdsCanonicalValueUntilTheSourceRowPasses(): void
    {
        $service = new RevenueDecisionViewModelAttestationService();
        $metricCard = new ReflectionMethod($service, 'metricCard');
        $source = [
            'business_date' => '2026-08-20',
            'facts' => ['revenue' => 1288.5],
            'fact_statuses' => [
                'revenue' => ['status' => 'readback_verified'],
            ],
            'source' => [
                'table' => 'online_daily_data',
                'data_date' => '2026-08-20',
                'row_ids' => [321],
                'readback_status' => 'readback_verified',
            ],
        ];
        $strict = [
            'meituan' => [
                'accepted_row_ids' => [321],
                'metrics' => [
                    'revenue' => [
                        'strict_readback' => true,
                        'accepted_row_ids' => [321],
                    ],
                ],
            ],
        ];

        $verified = $metricCard->invoke(
            $service,
            $source,
            'meituan_ota',
            'revenue',
            '美团渠道订单金额',
            'CNY',
            '2026-08-20',
            $strict,
            false
        );
        self::assertSame(1288.5, $verified['value']);
        self::assertSame('readback_verified', $verified['status']);
        self::assertSame('ota_channel', $verified['scope']);
        self::assertStringContainsString('emerald', $verified['statusClass']);

        $strict['meituan']['metrics']['revenue']['strict_readback'] = false;
        $withheld = $metricCard->invoke(
            $service,
            $source,
            'meituan_ota',
            'revenue',
            '美团渠道订单金额',
            'CNY',
            '2026-08-20',
            $strict,
            false
        );
        self::assertNull($withheld['value']);
        self::assertSame('—', $withheld['display']);
        self::assertSame('not_verified', $withheld['status']);
        self::assertStringContainsString('amber', $withheld['statusClass']);
        self::assertSame('cockpit_strict_metric_readback_missing', $withheld['reasonCode']);
    }

    public function testAttestationRejectsNestedCausalClaims(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden_claim:causalityclaimed');

        (new ReflectionMethod(
            RevenueDecisionViewModelAttestationService::class,
            'assertNoForbiddenClaims'
        ))->invoke(new RevenueDecisionViewModelAttestationService(), [
            'opportunity' => ['causalityClaimed' => true],
        ]);
    }

    public function testAttestationRejectsClientInjectedRecommendationText(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'revenue_decision_snapshot_view_model_unattested:opportunity:opportunity:traffic_entry_shortage:recommendedCheckAction'
        );

        (new ReflectionMethod(
            RevenueDecisionViewModelAttestationService::class,
            'assertCardList'
        ))->invoke(
            new RevenueDecisionViewModelAttestationService(),
            [[
                'key' => 'opportunity:traffic_entry_shortage',
                'recommendedCheckAction' => '立即自动降价并写入 OTA',
            ]],
            [[
                'key' => 'opportunity:traffic_entry_shortage',
                'recommendedCheckAction' => '先核对同平台事实，再由用户决定是否送审。',
            ]],
            'opportunity'
        );
    }

    public function testAttestationRejectsUnknownSemanticCardFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'revenue_decision_snapshot_view_model_unattested:metric:meituan_ota:list_exposure:unexpected_field'
        );

        (new ReflectionMethod(
            RevenueDecisionViewModelAttestationService::class,
            'assertCardList'
        ))->invoke(
            new RevenueDecisionViewModelAttestationService(),
            [[
                'key' => 'meituan_ota:list_exposure',
                'value' => 1110,
                'wholeHotelRevenue' => 999999,
                'clientActionText' => '立即自动降价并写入 OTA',
                'causalConclusion' => '促销导致收入上升',
            ]],
            [[
                'key' => 'meituan_ota:list_exposure',
                'value' => 1110,
            ]],
            'metric'
        );
    }

    public function testAttestationStrictlyBindsSectionAndTopLevelPresentationText(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/RevenueDecisionViewModelAttestationService.php'
        );

        self::assertStringContainsString("'title' => \$sectionMeta['title']", $source);
        self::assertStringContainsString("'subtitle' => \$sectionMeta['subtitle']", $source);
        self::assertStringContainsString("'dateNotice' => \$scopeNotice", $source);
        self::assertStringContainsString("'selectedPlatformLabel' => (string)(\$comparisons['selected_platform_label']", $source);
        self::assertStringContainsString("\$this->assertFields(\$model, \$expectedTop, 'top_semantics')", $source);
    }

    public function testAttestationCanonicalizesNestedObjectKeysBeforePersistence(): void
    {
        $canonicalize = new ReflectionMethod(
            RevenueDecisionViewModelAttestationService::class,
            'canonicalize'
        );
        $normalized = $canonicalize->invoke(
            new RevenueDecisionViewModelAttestationService(),
            ['z' => 1, 'a' => ['y' => 2, 'b' => 3]]
        );

        self::assertSame(['a', 'z'], array_keys($normalized));
        self::assertSame(['b', 'y'], array_keys($normalized['a']));
    }
}
