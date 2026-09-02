<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\RevenueAi;
use app\model\PriceSuggestion;
use app\model\User;
use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class RevenueAiControllerTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): RevenueAi
    {
        $reflection = new ReflectionClass(RevenueAi::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testRequestedEnabledChannelsKeepsCtripScopeExplicit(): void
    {
        self::assertSame(['ctrip'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'platform' => ' Ctrip ',
        ]]));
        self::assertSame(['ctrip'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'enabled_channels' => 'ctrip,ctrip',
        ]]));
        self::assertSame(['ctrip', 'meituan'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'enabled_channels' => ['ctrip', 'meituan'],
        ]]));
        self::assertSame(['ctrip', 'meituan'], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'platform' => 'all_ota',
        ]]));
        self::assertSame([], $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[]]));
    }

    public function testRequestedEnabledChannelsRejectsUnknownScope(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revenue_ai_channel_invalid');

        $this->invokeNonPublic($this->controller(), 'requestedEnabledChannels', [[
            'platform' => 'unknown',
        ]]);
    }

    public function testCockpitUsesSelectedHotelFactTenantForCrossTenantSuperAdmin(): void
    {
        self::assertSame(80, $this->invokeNonPublic($this->controller(), 'cockpitFactTenantId', [[
            'hotel_id' => 80,
            'three_source_fact_layer' => [
                'hotel' => [
                    'tenant_id' => 80,
                    'system_hotel_id' => 80,
                ],
            ],
        ], 80, 7, true]));
    }

    public function testCockpitRejectsCrossTenantFactForOrdinaryHotelUser(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage('revenue_cockpit_tenant_scope_mismatch');
        $this->invokeNonPublic($this->controller(), 'cockpitFactTenantId', [[
            'hotel_id' => 80,
            'three_source_fact_layer' => [
                'hotel' => [
                    'tenant_id' => 80,
                    'system_hotel_id' => 80,
                ],
            ],
        ], 80, 7, false]);
    }

    public function testOverviewFiltersDiscardClientSuppliedP0GateOverride(): void
    {
        $controller = $this->controllerWithPermissions([]);
        $request = new class {
            /** @return array<string, mixed> */
            public function post(): array
            {
                return [
                    'hotel_id' => '7',
                    'business_date' => '2026-07-23',
                    'as_of_date' => '2099-01-01',
                    'as_of_date_contract_version' => 'client_forged.v0',
                    'p0_downstream_gate' => [
                        'status' => 'ready',
                        'verification_source' => 'client_supplied',
                    ],
                ];
            }

            public function param(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function method(): string
            {
                return 'POST';
            }

            public function getContent(): string
            {
                return '';
            }
        };

        $requestProperty = new ReflectionProperty(Base::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $filters = $this->invokeNonPublic($controller, 'filters');

        self::assertArrayNotHasKey('p0_downstream_gate', $filters);
        self::assertArrayNotHasKey('as_of_date', $filters);
        self::assertArrayNotHasKey('as_of_date_contract_version', $filters);
        self::assertSame('7', $filters['hotel_id']);
        self::assertSame('2026-07-23', $filters['business_date']);
    }

    public function testReviewPayloadKeepsManualExecutionBoundary(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionReviewPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
        ], 'approve']);

        self::assertSame(88, $payload['suggestion_id']);
        self::assertSame('approved', $payload['status']);
        self::assertSame('已批准', $payload['status_label']);
        self::assertTrue($payload['manual_review_required']);
        self::assertTrue($payload['advisory_only']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('/api/revenue-ai/price-suggestions/88/execution-intent', $payload['allowed_endpoint']);
        self::assertSame('create_execution_intent', $payload['next_action']);
        self::assertContains('ota_write', $payload['forbidden_actions']);
        self::assertContains('update_room_type_base_price', $payload['forbidden_actions']);
    }

    public function testReviewPayloadSupportsApproveWithChangesWithoutOtaWrite(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionReviewPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
            'suggested_price' => 318,
            'factors' => [
                'manual_review' => [
                    'version' => 1,
                    'action' => 'approve_with_changes',
                    'original_suggested_price' => 318,
                    'approved_price' => 328,
                    'auto_write_ota' => true,
                    'local_price_updated' => true,
                ],
            ],
        ], 'approve_with_changes']);

        self::assertSame('approve_with_changes', $payload['action']);
        self::assertTrue($payload['modified_review']);
        self::assertSame(318.0, $payload['original_suggested_price']);
        self::assertSame(328.0, $payload['approved_price']);
        self::assertSame(1, $payload['review_version']);
        self::assertSame('price_suggestions.factors.manual_review_versions', $payload['review_storage']);
        self::assertFalse($payload['manual_review']['auto_write_ota']);
        self::assertFalse($payload['manual_review']['local_price_updated']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('/api/revenue-ai/price-suggestions/88/execution-intent', $payload['allowed_endpoint']);
    }

    public function testManualReviewStateVersionsPlainApproveWithoutOtaWrite(): void
    {
        $state = $this->invokeNonPublic($this->controller(), 'buildManualReviewState', [[
            'id' => 88,
            'hotel_id' => 7,
            'suggested_price' => 318,
            'factors' => [
                'manual_review_versions' => [
                    ['version' => 1, 'action' => 'approve_with_changes', 'approved_price' => 328],
                ],
            ],
        ], 'approve', 9, '同意执行原建议价', null]);

        $review = $state['review'];
        self::assertSame(2, $review['version']);
        self::assertSame('approve', $review['action']);
        self::assertSame('approved', $review['status_after']);
        self::assertSame(318.0, $review['original_suggested_price']);
        self::assertSame(318.0, $review['approved_price']);
        self::assertSame(0.0, $review['price_delta']);
        self::assertSame(9, $review['reviewed_by']);
        self::assertSame('同意执行原建议价', $review['remark']);
        self::assertFalse($review['auto_write_ota']);
        self::assertFalse($review['local_price_updated']);
        self::assertFalse($review['ota_write']);
        self::assertSame('price_suggestions.factors.manual_review_versions', $review['version_storage']);
        self::assertCount(2, $state['factors']['manual_review_versions']);
        self::assertSame($review, $state['factors']['manual_review']);
    }

    public function testManualReviewStateVersionsRejectWithoutApprovedPrice(): void
    {
        $state = $this->invokeNonPublic($this->controller(), 'buildManualReviewState', [[
            'id' => 88,
            'hotel_id' => 7,
            'suggested_price' => 318,
        ], 'reject', 9, '保护价依据不足', null]);

        $review = $state['review'];
        self::assertSame(1, $review['version']);
        self::assertSame('reject', $review['action']);
        self::assertSame('rejected', $review['status_after']);
        self::assertSame(318.0, $review['original_suggested_price']);
        self::assertNull($review['approved_price']);
        self::assertNull($review['price_delta']);
        self::assertSame('保护价依据不足', $review['remark']);
        self::assertFalse($review['auto_write_ota']);
        self::assertFalse($review['local_price_updated']);
        self::assertFalse($review['ota_write']);
        self::assertSame($review, $state['factors']['manual_review_versions'][0]);
    }

    public function testExecutionIntentPayloadDoesNotClaimPriceApplication(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionExecutionIntentPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
        ], [
            'id' => 99,
            'source_module' => 'price_suggestion',
            'source_record_id' => 88,
            'status' => 'pending_approval',
        ], true]);

        self::assertSame(88, $payload['suggestion_id']);
        self::assertSame(99, $payload['execution_intent']['id']);
        self::assertTrue($payload['execution_intent_existing']);
        self::assertSame('price_suggestion', $payload['source_module']);
        self::assertSame('ops-track', $payload['target_page']);
        self::assertSame('approve_intent', $payload['target_action']);
        self::assertSame(99, $payload['target_id']);
        self::assertSame('intent', $payload['target_kind']);
        self::assertTrue($payload['manual_review_required']);
        self::assertTrue($payload['advisory_only']);
        self::assertFalse($payload['auto_write_ota']);
        self::assertFalse($payload['local_price_updated']);
        self::assertFalse($payload['ota_write']);
        self::assertSame('operation_execution_manual_evidence', $payload['next_action']);
    }

    public function testExecutionIntentPayloadTargetsCreatedOperationTask(): void
    {
        $payload = $this->invokeNonPublic($this->controller(), 'priceSuggestionExecutionIntentPayload', [[
            'id' => 88,
            'hotel_id' => 7,
            'status' => PriceSuggestion::STATUS_APPROVED,
        ], [
            'id' => 99,
            'hotel_id' => 7,
            'status' => 'approved',
            'tasks' => [
                ['id' => 101, 'status' => 'pending_execute'],
                ['id' => 102, 'status' => 'pending_execute'],
            ],
        ], false]);

        self::assertTrue($payload['operation_task_created']);
        self::assertSame(102, $payload['operation_task']['id']);
        self::assertSame('record_execution', $payload['target_action']);
        self::assertSame(102, $payload['target_id']);
        self::assertSame('task', $payload['target_kind']);
        self::assertSame('operation_task_manual_execution_evidence', $payload['next_action']);
        self::assertFalse($payload['auto_write_ota']);
    }

    public function testTrustedDecisionConfirmationGateRequiresVerifiedSourceAndDenominator(): void
    {
        $trusted = [
            'contract_version' => RevenuePricingRecommendationService::TRUSTED_DECISION_CONTRACT_VERSION,
            'sources' => ['status' => 'verified', 'ref_count' => 1],
            'metric_formula' => ['status' => 'calculable', 'denominator' => 280],
            'data_quality' => ['status' => 'verified', 'decision_eligible' => true],
            'confidence' => ['status' => 'available', 'score' => 0.82],
            'human_confirmation' => ['can_confirm' => true],
        ];

        $this->invokeNonPublic($this->controller(), 'assertTrustedDecisionCanBeConfirmed', [$trusted]);
        self::assertTrue(true);

        $trusted['metric_formula'] = [
            'status' => 'not_calculable',
            'denominator' => null,
            'display' => '不可计算',
        ];
        try {
            $this->invokeNonPublic($this->controller(), 'assertTrustedDecisionCanBeConfirmed', [$trusted]);
            self::fail('Missing denominator unexpectedly passed the trusted decision gate.');
        } catch (RuntimeException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('metric_denominator', $e->getMessage());
        }
    }

    public function testApproveToTaskBooleanInputIsExplicit(): void
    {
        self::assertTrue($this->invokeNonPublic($this->controller(), 'booleanInput', [true]));
        self::assertTrue($this->invokeNonPublic($this->controller(), 'booleanInput', ['1']));
        self::assertFalse($this->invokeNonPublic($this->controller(), 'booleanInput', ['false']));
    }

    public function testBasicHotelMemberCannotReviewOrCreateExecutionIntent(): void
    {
        $controller = $this->controllerWithPermissions([]);
        $suggestion = $this->suggestionForHotel(7);

        foreach (['can_use_ai_decision', 'operation.execute'] as $permission) {
            try {
                $this->invokeNonPublic($controller, 'priceSuggestionHotelScope', [$suggestion, $permission]);
                self::fail('Basic hotel member unexpectedly received Revenue AI write capability: ' . $permission);
            } catch (RuntimeException $e) {
                self::assertSame(403, $e->getCode());
                self::assertSame('revenue_ai_permission_denied:' . $permission, $e->getMessage());
            }
        }
    }

    public function testAuthorizedRolesPassOnlyTheirRevenueAiWriteCapability(): void
    {
        $suggestion = $this->suggestionForHotel(7);
        $reviewController = $this->controllerWithPermissions(['can_use_ai_decision']);
        self::assertSame(
            [[7], 7],
            $this->invokeNonPublic($reviewController, 'priceSuggestionHotelScope', [$suggestion, 'can_use_ai_decision'])
        );

        try {
            $this->invokeNonPublic($reviewController, 'priceSuggestionHotelScope', [$suggestion, 'operation.execute']);
            self::fail('AI decision reviewer unexpectedly received operation.execute');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('revenue_ai_permission_denied:operation.execute', $e->getMessage());
        }

        $executionController = $this->controllerWithPermissions(['operation.execute']);
        self::assertSame(
            [[7], 7],
            $this->invokeNonPublic($executionController, 'priceSuggestionHotelScope', [$suggestion, 'operation.execute'])
        );
        $this->invokeNonPublic($executionController, 'assertRevenueAiHotelCapability', [7, 'operation.execute']);
    }

    public function testCockpitOpportunityPendingApprovalChecksExecutionPermissionBeforeReadingOverview(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/function createCockpitOpportunityPendingApproval\(int \$id = 0\): Response[\s\S]*?assertRevenueAiHotelCapability\(\$hotelId, self::EXECUTION_PERMISSION\);[\s\S]*?strictCockpitContext\(\$filters\)/',
            $source
        );

        $controller = $this->controllerWithPermissions(['can_use_ai_decision']);
        try {
            $this->invokeNonPublic($controller, 'assertRevenueAiHotelCapability', [7, 'operation.execute']);
            self::fail('AI-only reviewer unexpectedly received cockpit pending-approval permission');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('revenue_ai_permission_denied:operation.execute', $e->getMessage());
        }
    }

    public function testDecisionSnapshotChecksReviewPermissionBeforeStrictOverview(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertMatchesRegularExpression(
            '/function createCockpitDecisionSnapshot\(\): Response[\s\S]*?assertRevenueAiHotelCapability\(\$hotelId, self::REVIEW_PERMISSION\);[\s\S]*?strictCockpitContext\(\$filters\)/',
            $source
        );

        $controller = $this->controllerWithPermissions(['operation.execute']);
        try {
            $this->invokeNonPublic($controller, 'assertRevenueAiHotelCapability', [7, 'can_use_ai_decision']);
            self::fail('Execution-only user unexpectedly received decision snapshot permission');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('revenue_ai_permission_denied:can_use_ai_decision', $e->getMessage());
        }
    }

    public function testDecisionSnapshotReadChecksReviewPermissionBeforeStrictOverview(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertMatchesRegularExpression(
            '/function readCockpitDecisionSnapshot\(\): Response[\s\S]*?\$filters = \$this->filters\(\);[\s\S]*?assertRevenueAiHotelCapability\(\$hotelId, self::REVIEW_PERMISSION\);[\s\S]*?strictCockpitContext\(\$filters\)/',
            $source
        );

        $controller = $this->controllerWithPermissions(['operation.execute']);
        try {
            $this->invokeNonPublic($controller, 'assertRevenueAiHotelCapability', [7, 'can_use_ai_decision']);
            self::fail('Execution-only user unexpectedly received decision snapshot read permission');
        } catch (RuntimeException $e) {
            self::assertSame(403, $e->getCode());
            self::assertSame('revenue_ai_permission_denied:can_use_ai_decision', $e->getMessage());
        }
    }

    public function testOpportunitySubmissionChecksExecutionPermissionBeforeStrictOverview(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertMatchesRegularExpression(
            '/function createCockpitOpportunityPendingApproval\(int \$id = 0\): Response[\s\S]*?assertRevenueAiHotelCapability\(\$hotelId, self::EXECUTION_PERMISSION\);[\s\S]*?strictCockpitContext\(\$filters\)/',
            $source
        );
    }

    public function testCockpitOverviewAttachesAndDecisionWritesRecheckDualOtaCurrentReceipt(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertStringContainsString("(new DualOtaFieldClosureService())->build(", $source);
        self::assertStringContainsString("'dual_ota_field_closure'", $source);
        self::assertSame(2, substr_count($source, '$this->assertDualOtaCurrentReceiptReady('));
        self::assertStringContainsString('_current_receipt_not_ready', $source);
    }

    public function testCockpitStrictEvidenceReusesOneDualOtaClosureIdentity(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        self::assertSame(1, preg_match(
            '/private function withCockpitStrictEvidence\([\s\S]*?(?=\n    private function assertDualOtaCurrentReceiptReady)/',
            $source,
            $matches
        ));
        $method = (string)($matches[0] ?? '');

        self::assertSame(1, substr_count($method, '(new DualOtaFieldClosureService())->build('));
        self::assertStringContainsString("\$overview['dual_ota_field_closure'] = \$closure;", $method);
        self::assertMatchesRegularExpression(
            '/RevenueCockpitStrictEvidenceService\(\)\)->build\([\s\S]*?\$closure\s*\)/',
            $method
        );
    }

    public function testCockpitOverviewIssuesAndSnapshotSaveRebindsOneCanonicalServerModel(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/RevenueAi.php');
        $overviewSource = (string)file_get_contents(dirname(__DIR__) . '/app/service/RevenueAiOverviewService.php');

        self::assertStringContainsString('$overview = $this->withCanonicalCockpitViewModel($overview, $filters);', $source);
        self::assertStringContainsString("\$overview['canonical_view_model'] = \$model;", $source);
        self::assertStringContainsString("\$overview['canonical_view_model_digest'] = \$issuer->issuedDigest(\$model);", $source);
        self::assertStringContainsString("\$visibleModelDigest = strtolower(trim((string)(\$input['visible_model_digest']", $source);
        self::assertStringContainsString('!hash_equals($canonicalDigest, $issuer->issuedDigest($visibleModel))', $source);
        self::assertStringContainsString('$canonicalModel,', $source);
        self::assertStringContainsString("\$data['as_of_date']", $source);
        self::assertStringContainsString("\$data['as_of_date_contract_version']", $source);
        self::assertStringContainsString(
            "'as_of_date' => RevenueOverviewDateContract::serverAsOfDate()",
            $overviewSource
        );
        self::assertStringNotContainsString(
            "RevenueOverviewDateContract::asOfDate(\$filters['as_of_date'] ?? null)",
            $overviewSource
        );
    }

    /** @param array<int, string> $permissions */
    private function controllerWithPermissions(array $permissions): RevenueAi
    {
        $controller = $this->controller();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', 'getPermittedHotelIds', 'hasHotelPermission'])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('getPermittedHotelIds')->willReturn([7]);
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $permission): bool => $hotelId === 7
                && in_array($permission, $permissions, true)
        );

        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, $user);

        return $controller;
    }

    private function suggestionForHotel(int $hotelId): PriceSuggestion
    {
        $suggestion = $this->getMockBuilder(PriceSuggestion::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get'])
            ->getMock();
        $suggestion->method('__get')->willReturnCallback(
            static fn(string $name): mixed => $name === 'hotel_id' ? $hotelId : null
        );
        return $suggestion;
    }
}
