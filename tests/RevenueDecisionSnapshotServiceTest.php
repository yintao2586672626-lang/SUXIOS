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

    public function testExactReadbackRejectsWrongSnapshotIdempotencyKeyAndCreatedAt(): void
    {
        $service = new RevenueDecisionSnapshotService();
        $digest = new ReflectionMethod($service, 'digest');
        $idempotency = new ReflectionMethod($service, 'snapshotIdempotencyKey');
        $hydrate = new ReflectionMethod($service, 'hydrateAndVerify');
        $sourceRefs = [['source_key' => 'meituan_ota', 'row_ids' => [321]]];
        $metricDefinitions = ['list_exposure' => ['unit' => 'exposures']];
        $visibleModel = ['contractVersion' => 'revenue_daily_cockpit.v2'];
        $missingItems = [['card_key' => 'revenue']];
        $evidenceSummary = ['causality_claimed' => false];
        $visibleDigest = $digest->invoke(null, $visibleModel);
        $evidenceDigest = $digest->invoke(null, $sourceRefs);
        $content = [
            'contract_version' => 'revenue_decision_snapshot.v1',
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'business_date' => '2026-08-20',
            'source_refs' => $sourceRefs,
            'metric_definitions' => $metricDefinitions,
            'visible_model' => $visibleModel,
            'missing_items' => $missingItems,
            'evidence_summary' => $evidenceSummary,
            'visible_model_digest' => $visibleDigest,
            'evidence_digest' => $evidenceDigest,
            'created_by' => 7,
        ];
        $contentDigest = $digest->invoke(null, $content);
        $expectedKey = $idempotency->invoke(null, 9, 80, 7, $contentDigest);
        $encode = static fn(array $value): string => json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        $row = [
            'id' => 2,
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'business_date' => '2026-08-20',
            'contract_version' => 'revenue_decision_snapshot.v1',
            'source_refs_json' => $encode($sourceRefs),
            'metric_definitions_json' => $encode($metricDefinitions),
            'visible_model_json' => $encode($visibleModel),
            'missing_items_json' => $encode($missingItems),
            'evidence_summary_json' => $encode($evidenceSummary),
            'visible_model_digest' => $visibleDigest,
            'evidence_digest' => $evidenceDigest,
            'content_digest' => $contentDigest,
            'idempotency_key' => $expectedKey,
            'created_by' => 7,
            'created_at' => '2026-08-20 12:34:56',
        ];

        self::assertSame($expectedKey, $hydrate->invoke($service, $row)['idempotency_key']);
        foreach ([
            'idempotency_key' => array_replace($row, ['idempotency_key' => str_repeat('0', 64)]),
            'created_at' => array_replace($row, ['created_at' => 'not-a-time']),
        ] as $field => $invalidRow) {
            try {
                $hydrate->invoke($service, $invalidRow);
                self::fail($field . ' must fail closed');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($field, $error->getMessage());
            }
        }
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

    public function testAttestationRejectsKnownPresentationContainersUnlessTheyMatchServerValues(): void
    {
        $service = new RevenueDecisionViewModelAttestationService();
        $assertFields = new ReflectionMethod($service, 'assertFields');
        foreach (['statusClass', 'dateNotice', 'selectedPlatformLabel', 'title', 'subtitle'] as $field) {
            try {
                $assertFields->invoke(
                    $service,
                    [$field => ['wholeHotelRevenue' => 999999]],
                    [$field => '服务端固定展示值'],
                    'presentation'
                );
                self::fail($field . ' must reject a nested client payload');
            } catch (ReflectionException $error) {
                throw $error;
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('presentation:' . $field, $error->getMessage());
            }
        }

        try {
            (new ReflectionMethod($service, 'assertCardList'))->invoke(
                $service,
                [[
                    'key' => 'meituan_ota:list_exposure',
                    'statusClass' => ['clientActionText' => '立即自动降价并写入 OTA'],
                ]],
                [[
                    'key' => 'meituan_ota:list_exposure',
                    'statusClass' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                ]],
                'metric'
            );
            self::fail('card statusClass must reject a nested client payload');
        } catch (ReflectionException $error) {
            throw $error;
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString(
                'metric:meituan_ota:list_exposure:statusClass',
                $error->getMessage()
            );
        }
    }
}
