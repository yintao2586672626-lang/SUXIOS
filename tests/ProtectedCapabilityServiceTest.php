<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\ProtectedCapabilityService;
use PHPUnit\Framework\TestCase;

final class ProtectedCapabilityServiceTest extends TestCase
{
    public function testNonSuperUserWithoutCapabilityPermissionIsDenied(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['ai_decision'],
        ]);
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);
        self::assertSame('ai_decision', $capability['key']);

        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_view_report']),
            $capability,
            ['hotel_id' => 7]
        );

        self::assertFalse($authorization['allowed']);
        self::assertSame('role_permission_denied', $authorization['reason']);
        self::assertSame('can_use_ai_decision', $authorization['required_permission']);
    }

    public function testMissingModuleEntitlementDeniesEvenWhenRoleAllows(): void
    {
        $service = new ProtectedCapabilityService();
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);

        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision']),
            $capability,
            ['hotel_id' => 7]
        );

        self::assertFalse($authorization['allowed']);
        self::assertSame('module_not_entitled', $authorization['reason']);
        self::assertSame('ai_decision', $authorization['required_module']);
    }

    public function testAuthorizedNonSuperPayloadIsRedactedAndTraceable(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['ai_decision'],
        ]);
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);

        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision']),
            $capability,
            ['hotel_id' => 7]
        );
        self::assertTrue($authorization['allowed']);

        $payload = $service->redactPayload([
            'code' => 200,
            'message' => 'ok',
            'data' => [
                'status' => 'available',
                'display_result' => ['score' => 91],
                'gaps' => ['missing_comp_set'],
                'prompt' => 'copyable prompt',
                'formula' => 'revpar = rooms * adr',
                'nested' => [
                    'source_path' => '$.payload.secret',
                    'request_url' => 'https://internal.example/api',
                    'raw_data' => ['secret' => true],
                    'headers' => ['Authorization' => 'Bearer token'],
                    'p3_evidence_drafts' => [['raw' => true]],
                    'safe_status' => 'kept',
                ],
            ],
        ], $capability, 'req-test-001');

        self::assertTrue($payload['redacted']);
        self::assertSame('req-test-001', $payload['reference_id']);
        self::assertSame('ai_decision', $payload['protected_capability']);
        self::assertSame('available', $payload['data']['status']);
        self::assertSame(['score' => 91], $payload['data']['display_result']);
        self::assertSame('kept', $payload['data']['nested']['safe_status']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        foreach (['prompt', 'formula', 'source_path', 'request_url', 'raw_data', 'headers', 'p3_evidence'] as $sensitiveKey) {
            self::assertStringNotContainsString('"' . $sensitiveKey . '"', $encoded);
        }
    }

    public function testSuperAdminKeepsFullResponseMode(): void
    {
        $service = new ProtectedCapabilityService();
        $capability = $service->classifyPath('GET', '/api/ai-governance/prompt-versions');

        self::assertIsArray($capability);

        $user = $this->userWithPermissions([], true);
        $authorization = $service->authorizeContext($user, $capability, ['hotel_id' => 7]);

        self::assertTrue($authorization['allowed']);
        self::assertFalse($service->shouldRedactForUser($user, $capability));
    }

    public function testKnowledgeReadManagementAndExecutionUseDistinctCapabilities(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['ai_governance', 'operation_decision'],
        ]);
        $list = $service->classifyPath('GET', '/api/knowledge/list?hotel_id=7');
        $detail = $service->classifyPath('GET', '/api/knowledge/9?hotel_id=7');
        $management = $service->classifyPath('POST', '/api/knowledge/add');
        $execution = $service->classifyPath('POST', '/api/knowledge/9/chunks/3/execution-intent');

        self::assertSame('knowledge_read', $list['key'] ?? null);
        self::assertSame('ai.view', $list['permission'] ?? null);
        self::assertSame('', $list['module'] ?? null);
        self::assertSame('knowledge_read', $detail['key'] ?? null);
        self::assertSame('ai_governance', $management['key'] ?? null);
        self::assertSame('can_manage_ai_governance', $management['permission'] ?? null);
        self::assertSame('operation_execution', $execution['key'] ?? null);
        self::assertSame('operation.execute', $execution['permission'] ?? null);

        $reader = $this->userWithPermissions(['ai.view']);
        self::assertTrue($service->authorizeContext($reader, $list, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($reader, $management, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($reader, $execution, ['hotel_id' => 7])['allowed']);

        $governanceManager = $this->userWithPermissions(['can_manage_ai_governance']);
        self::assertTrue($service->authorizeContext($governanceManager, $management, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($governanceManager, $execution, ['hotel_id' => 7])['allowed']);
        self::assertTrue($service->authorizeContext(
            $this->userWithPermissions(['operation.execute']),
            $execution,
            ['hotel_id' => 7]
        )['allowed']);
    }

    public function testRevenueAiReviewAndExecutionUseDistinctProtectedCapabilities(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['ai_decision', 'operation_decision'],
        ]);

        $overview = $service->classifyPath('GET', '/api/revenue-ai/overview');
        $review = $service->classifyPath('POST', '/api/revenue-ai/price-suggestions/88/review');
        $execution = $service->classifyPath('POST', '/api/revenue-ai/price-suggestions/88/execution-intent');

        self::assertIsArray($overview);
        self::assertSame('ai_decision', $overview['key']);
        self::assertSame('can_use_ai_decision', $overview['permission']);
        self::assertIsArray($review);
        self::assertSame('ai_decision', $review['key']);
        self::assertSame('can_use_ai_decision', $review['permission']);
        self::assertIsArray($execution);
        self::assertSame('operation_execution', $execution['key']);
        self::assertSame('operation.execute', $execution['permission']);
        self::assertSame('operation_decision', $execution['module']);

        $basicMember = $this->userWithPermissions(['can_view_report']);
        self::assertFalse($service->authorizeContext($basicMember, $review, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($basicMember, $execution, ['hotel_id' => 7])['allowed']);

        self::assertTrue($service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision']),
            $review,
            ['hotel_id' => 7]
        )['allowed']);
        self::assertTrue($service->authorizeContext(
            $this->userWithPermissions(['operation.execute']),
            $execution,
            ['hotel_id' => 7]
        )['allowed']);
    }

    public function testOperatingLoopApiIsAlwaysClassifiedAsProtectedOperationDecision(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);

        foreach ([
            ['GET', '/api/operating-loop/current?hotel_id=7&business_date=2026-08-10'],
            ['POST', '/api/operating-loop/reconcile'],
            ['POST', '/api/operating-loop/9/transitions'],
        ] as [$method, $path]) {
            $capability = $service->classifyPath($method, $path);
            self::assertIsArray($capability);
            self::assertSame('operation_decision', $capability['key']);
            self::assertSame('operation.view', $capability['permission']);
            self::assertSame('operation_decision', $capability['module']);
        }
    }

    public function testPublicPageTaskBridgeRequiresOperationExecuteBeforeWrite(): void
    {
        $service = new ProtectedCapabilityService();
        $capability = $service->classifyPath(
            'POST',
            '/api/online-data/public-page-diagnosis/execution-intent'
        );

        self::assertIsArray($capability);
        self::assertSame('operation_execution', $capability['key']);
        self::assertSame('operation.execute', $capability['permission']);
        $denied = $service->authorizeContext(
            $this->userWithPermissions(['operation.view']),
            $capability,
            ['system_hotel_id' => 7]
        );
        self::assertFalse($denied['allowed']);
        self::assertSame('role_permission_denied', $denied['reason']);

        $enabledService = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $enabledCapability = $enabledService->classifyPath(
            'POST',
            '/api/online-data/public-page-diagnosis/execution-intent'
        );
        self::assertIsArray($enabledCapability);
        $deniedByRole = $enabledService->authorizeContext(
            $this->userWithPermissions(['operation.view']),
            $enabledCapability,
            ['system_hotel_id' => 7]
        );
        self::assertFalse($deniedByRole['allowed']);
        self::assertSame('role_permission_denied', $deniedByRole['reason']);
        self::assertTrue($enabledService->authorizeContext(
            $this->userWithPermissions(['operation.execute']),
            $enabledCapability,
            ['system_hotel_id' => 7]
        )['allowed']);
    }

    public function testOnlineHistoryReadPathsRequireOnlineDataViewPermission(): void
    {
        $service = new ProtectedCapabilityService();

        self::assertNull($service->classifyPath('GET', '/api/daily-reports/123'));
        self::assertNull($service->classifyPath('GET', '/api/daily-reports?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/daily-data-list?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/daily-data-summary?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/data-sources?hotel_id=7'));

        foreach ([
            '/api/online-data/history?hotel_id=7',
            '/api/online-data/ctrip/history?hotel_id=7',
            '/api/online-data/history/9',
        ] as $path) {
            $capability = $service->classifyPath('GET', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('online_data_history', $capability['key'], $path);
            self::assertSame('can_view_online_data', $capability['permission'], $path);
            self::assertTrue($service->authorizeContext(
                $this->userWithPermissions(['can_view_online_data']),
                $capability,
                ['system_hotel_id' => 7]
            )['allowed'], $path);
            self::assertFalse($service->authorizeContext(
                $this->userWithPermissions(['can_view_diagnostics']),
                $capability,
                ['system_hotel_id' => 7]
            )['allowed'], $path);
        }
    }

    public function testOtaConfigReadPathsStayScopedByControllerPermissions(): void
    {
        $service = new ProtectedCapabilityService();

        self::assertNull($service->classifyPath('GET', '/api/online-data/get-ctrip-config-list'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/get-ctrip-config-detail?id=ctrip_1'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/get-meituan-config-list'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/get-meituan-config-detail?id=meituan_1'));
    }

    public function testMutatingDataSourceAndEvidenceDetailPathsRemainProtected(): void
    {
        $service = new ProtectedCapabilityService();

        $saveSource = $service->classifyPath('POST', '/api/online-data/data-sources');
        self::assertIsArray($saveSource);
        self::assertSame('online_data_core', $saveSource['key']);

        $syncSource = $service->classifyPath('POST', '/api/online-data/data-sources/9/sync');
        self::assertIsArray($syncSource);
        self::assertSame('online_data_core', $syncSource['key']);

        $deleteSource = $service->classifyPath('DELETE', '/api/online-data/data-sources/9');
        self::assertIsArray($deleteSource);
        self::assertSame('online_data_core', $deleteSource['key']);

        $historyDetail = $service->classifyPath('GET', '/api/online-data/history/9');
        self::assertIsArray($historyDetail);
        self::assertSame('online_data_history', $historyDetail['key']);
    }

    public function testOtaCollectPathRequiresCollectPermissionNotReadOnlyPermission(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['online_data'],
        ]);
        $capability = $service->classifyPath('POST', '/api/online-data/fetch-ctrip');

        self::assertIsArray($capability);
        self::assertSame('can_fetch_online_data', $capability['permission']);

        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_view_online_data']),
            $capability,
            ['hotel_id' => 7]
        );

        self::assertFalse($authorization['allowed']);
        self::assertSame('role_permission_denied', $authorization['reason']);
    }

    public function testLifecycleAndInvestmentOverviewRequireInvestmentCapability(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['investment'],
        ]);

        foreach (['/api/lifecycle/overview', '/api/investment-decision/overview'] as $path) {
            $capability = $service->classifyPath('GET', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('investment_decision', $capability['key'], $path);
            self::assertSame('can_use_investment', $capability['permission'], $path);

            $authorization = $service->authorizeContext(
                $this->userWithPermissions(['can_view_report']),
                $capability,
                ['hotel_id' => 7]
            );
            self::assertFalse($authorization['allowed'], $path);
            self::assertSame('role_permission_denied', $authorization['reason'], $path);
        }
    }

    public function testDefaultOtaModulesAllowRolePermittedBetaUserPaths(): void
    {
        $service = new ProtectedCapabilityService();

        $profileStatus = $service->classifyPath('GET', '/api/online-data/platform-profile-status?platform=meituan');
        self::assertIsArray($profileStatus);
        $profileAuthorization = $service->authorizeContext(
            $this->userWithPermissions(['can_view_diagnostics']),
            $profileStatus,
            ['hotel_id' => 7]
        );
        self::assertTrue($profileAuthorization['allowed']);

        $fetchCtrip = $service->classifyPath('POST', '/api/online-data/fetch-ctrip');
        self::assertIsArray($fetchCtrip);
        $fetchAuthorization = $service->authorizeContext(
            $this->userWithPermissions(['can_fetch_online_data']),
            $fetchCtrip,
            ['hotel_id' => 7]
        );
        self::assertTrue($fetchAuthorization['allowed']);

        $profileFields = $service->classifyPath('GET', '/api/online-data/ctrip-profile-fields');
        self::assertIsArray($profileFields);
        $fieldAuthorization = $service->authorizeContext(
            $this->userWithPermissions(['can_view_field_assets']),
            $profileFields,
            ['hotel_id' => 7]
        );
        self::assertTrue($fieldAuthorization['allowed']);
    }

    public function testClientTenantIdCannotBorrowAnotherTenantEntitlement(): void
    {
        $service = new ProtectedCapabilityService([
            'tenant_modules' => [
                '999' => ['ai_decision'],
            ],
        ]);
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);
        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision']),
            $capability,
            ['hotel_id' => 7, 'tenant_id' => 999]
        );

        self::assertFalse($authorization['allowed']);
        self::assertSame('tenant_context_mismatch', $authorization['reason']);
        self::assertSame(71, $authorization['tenant_id']);
    }

    public function testTenantEntitlementIsResolvedFromAuthenticatedUser(): void
    {
        $service = new ProtectedCapabilityService([
            'tenant_modules' => [
                '71' => ['ai_decision'],
            ],
        ]);
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);
        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision']),
            $capability,
            ['hotel_id' => 7]
        );

        self::assertTrue($authorization['allowed']);
        self::assertSame(71, $authorization['tenant_id']);
    }

    public function testVisibleHotelStillRequiresHotelLevelCapabilityPermission(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['ai_decision'],
        ]);
        $capability = $service->classifyPath('POST', '/api/agent/ota-diagnosis');

        self::assertIsArray($capability);
        $authorization = $service->authorizeContext(
            $this->userWithPermissions(['can_use_ai_decision'], false, false),
            $capability,
            ['hotel_id' => 7]
        );

        self::assertFalse($authorization['allowed']);
        self::assertSame('hotel_permission_denied', $authorization['reason']);
    }

    public function testTenantAndHotelResolversRemainIndependent(): void
    {
        $service = new ProtectedCapabilityService();
        $user = $this->userWithPermissions(['can_view_report']);

        self::assertSame(71, $service->resolveTenantId($user));
        self::assertSame(88, $service->resolveHotelId([
            'tenant_id' => 999,
            'system_hotel_id' => 88,
            'hotel_id' => 7,
        ], $user));
    }

    public function testEveryExecutionIntentWriteBridgeRequiresOperationExecute(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $paths = [
            '/api/revenue-ai/cockpit/decision-snapshots/9/pending-approval',
            '/api/agent/feasibility-report/9/execution-intent',
            '/api/agent/price-suggestions/9/execution-intent',
            '/api/ai-daily-reports/9/actions/0/execution-intent',
            '/api/revenue-research/execution-intent',
            '/api/strategy/records/9/execution-intent',
            '/api/simulation/records/9/execution-intent',
            '/api/opening/projects/9/execution-intent',
            '/api/expansion/records/9/execution-intent',
            '/api/transfer/records/9/execution-intent',
            '/api/temporal-insights/forecasts/9/execution-intent',
            '/api/temporal-insights/forecast-trials/9/execution-intent',
            '/api/online-data/public-page-diagnosis/execution-intent',
        ];

        foreach ($paths as $path) {
            $capability = $service->classifyPath('POST', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('operation_execution', $capability['key'], $path);
            self::assertSame('operation.execute', $capability['permission'], $path);
            self::assertTrue($capability['controller_hotel_scope'], $path);
        }
    }

    public function testEveryExecutionTaskWriteRequiresExecuteWhileDetailRemainsViewOnly(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $viewer = $this->userWithPermissions(['operation.view']);
        $executor = $this->userWithPermissions(['operation.execute']);

        foreach ([
            '/api/operation/execution-tasks/81/execute',
            '/api/operation/execution-tasks/81/evidence',
            '/api/operation/execution-tasks/81/intervention-assessments',
            '/api/operation/execution-tasks/81/reconcile-review',
            '/api/operation/execution-tasks/81/review',
            '/api/operation/execution-tasks/81/operating-memory',
        ] as $path) {
            $capability = $service->classifyPath('POST', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('operation_execution', $capability['key'], $path);
            self::assertSame('operation.execute', $capability['permission'], $path);
            self::assertFalse($service->authorizeContext($viewer, $capability, ['hotel_id' => 7])['allowed'], $path);
            self::assertTrue($service->authorizeContext($executor, $capability, ['hotel_id' => 7])['allowed'], $path);
        }

        $detail = $service->classifyPath('GET', '/api/operation/execution-tasks/81');
        self::assertIsArray($detail);
        self::assertSame('operation_decision', $detail['key']);
        self::assertSame('operation.view', $detail['permission']);
        self::assertTrue($service->authorizeContext($viewer, $detail, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($executor, $detail, ['hotel_id' => 7])['allowed']);
    }

    public function testManagerCapabilityCaseAndFollowupWritesRequireOperationExecute(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $viewer = $this->userWithPermissions(['operation.view']);
        $executor = $this->userWithPermissions(['operation.execute']);

        foreach ([
            '/api/operation/manager-capability/cases',
            '/api/operation/manager-capability/cases/81/followups',
            '/api/operation/manager-capability/cases/81/adjustments',
            '/api/operation/manager-capability/cases/81/score-reviews',
        ] as $path) {
            $capability = $service->classifyPath('POST', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('operation_execution', $capability['key'], $path);
            self::assertSame('operation.execute', $capability['permission'], $path);
            self::assertFalse($service->authorizeContext($viewer, $capability, ['hotel_id' => 7])['allowed'], $path);
            self::assertTrue($service->authorizeContext($executor, $capability, ['hotel_id' => 7])['allowed'], $path);
        }
    }

    public function testOperatingOpportunityReadsAndWritesUseSeparateOperationPermissions(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $viewer = $this->userWithPermissions(['operation.view']);
        $executor = $this->userWithPermissions(['operation.execute']);

        foreach ([
            '/api/operating-opportunities/overview',
            '/api/operating-opportunities/runs/81',
        ] as $path) {
            $capability = $service->classifyPath('GET', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('operation_decision', $capability['key'], $path);
            self::assertSame('operation.view', $capability['permission'], $path);
            self::assertSame('summary_only', $capability['response_mode'], $path);
            self::assertTrue($service->authorizeContext($viewer, $capability, ['hotel_id' => 7])['allowed'], $path);
            self::assertFalse($service->authorizeContext($executor, $capability, ['hotel_id' => 7])['allowed'], $path);
        }

        foreach ([
            '/api/operating-opportunities/evaluate',
            '/api/operating-opportunities/priority',
            '/api/operating-opportunities/runs/81/pending-approval',
        ] as $path) {
            $capability = $service->classifyPath('POST', $path);
            self::assertIsArray($capability, $path);
            self::assertSame('operation_execution', $capability['key'], $path);
            self::assertSame('operation.execute', $capability['permission'], $path);
            self::assertSame('summary_only', $capability['response_mode'], $path);
            self::assertTrue($capability['controller_hotel_scope'], $path);
            self::assertFalse($service->authorizeContext($viewer, $capability, ['hotel_id' => 7])['allowed'], $path);
            self::assertTrue($service->authorizeContext($executor, $capability, ['hotel_id' => 7])['allowed'], $path);
        }

        self::assertNull(
            $service->classifyPath('POST', '/api/operating-opportunities/runs/81/unknown-write'),
            'an undeclared write must not inherit operation.view from the read prefix'
        );
    }

    public function testOperatingFinanceReadsAndWritesUseSeparateProtectedCapabilities(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $viewer = $this->userWithPermissions(['operation.view']);
        $executor = $this->userWithPermissions(['operation.execute']);

        $read = $service->classifyPath('GET', '/api/operating-finance/overview');
        self::assertIsArray($read);
        self::assertSame('operation_decision', $read['key']);
        self::assertSame('operation.view', $read['permission']);
        self::assertTrue($service->authorizeContext($viewer, $read, ['hotel_id' => 7])['allowed']);
        self::assertFalse($service->authorizeContext($executor, $read, ['hotel_id' => 7])['allowed']);

        foreach ([
            '/api/operating-finance/settlements/import',
            '/api/operating-finance/settlements/import-file',
            '/api/operating-finance/on-books-snapshots',
            '/api/operating-finance/demand-events',
            '/api/operating-finance/monthly-finance',
        ] as $path) {
            $write = $service->classifyPath('POST', $path);
            self::assertIsArray($write, $path);
            self::assertSame('operation_execution', $write['key'], $path);
            self::assertSame('operation.execute', $write['permission'], $path);
            self::assertTrue($write['controller_hotel_scope'], $path);
            self::assertFalse($service->authorizeContext($viewer, $write, ['hotel_id' => 7])['allowed'], $path);
            self::assertTrue($service->authorizeContext($executor, $write, ['hotel_id' => 7])['allowed'], $path);
        }

        self::assertNull($service->classifyPath('POST', '/api/operating-finance/unknown-write'));
    }

    public function testOperatingOpportunitySummaryRedactionKeepsUsableMetricsAndRemovesSensitiveDetail(): void
    {
        $service = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $capability = $service->classifyPath('GET', '/api/operating-opportunities/overview');
        self::assertIsArray($capability);

        $payload = $service->redactPayload([
            'code' => 200,
            'message' => 'ok',
            'data' => [
                'business_date' => '2026-08-22',
                'result' => [
                    'calculation_status' => 'provisional_manual_estimate',
                    'decision_eligible' => false,
                    'can_execute' => false,
                    'provisional_metrics' => ['incremental_room_nights' => 12.5],
                    'raw_payload' => ['private' => 'must-not-return'],
                    'source_path' => '$.internal.evidence',
                ],
            ],
        ], $capability, 'req-opportunity-001');

        self::assertTrue($payload['redacted']);
        self::assertSame('operation_decision', $payload['protected_capability']);
        self::assertSame('req-opportunity-001', $payload['reference_id']);
        self::assertSame(
            12.5,
            $payload['data']['result']['provisional_metrics']['incremental_room_nights']
        );
        self::assertFalse($payload['data']['result']['decision_eligible']);
        self::assertFalse($payload['data']['result']['can_execute']);
        self::assertArrayNotHasKey('raw_payload', $payload['data']['result']);
        self::assertArrayNotHasKey('source_path', $payload['data']['result']);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function userWithPermissions(
        array $permissions,
        bool $superAdmin = false,
        bool $hotelPermissionAllowed = true
    ): User
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', '__get', '__isset'])
            ->getMock();
        $role->method('getPermissionList')->willReturn($permissions);
        $role->method('__isset')->willReturnCallback(
            static fn(string $key): bool => $key === 'status'
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => $key === 'status' ? Role::STATUS_ENABLED : null
        );

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', 'getPermittedHotelIds', 'hasHotelPermission', '__get', '__isset'])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn($superAdmin);
        $user->method('getPermittedHotelIds')->willReturn([7]);
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $permission): bool => $hotelPermissionAllowed
                && $hotelId === 7
                && (in_array('all', $permissions, true) || in_array($permission, $permissions, true))
        );
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'tenant_id', 'hotel_id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static function (string $key) use ($role) {
                return match ($key) {
                    'id' => 42,
                    'tenant_id' => 71,
                    'hotel_id' => 7,
                    'role' => $role,
                    default => null,
                };
            }
        );

        return $user;
    }
}
