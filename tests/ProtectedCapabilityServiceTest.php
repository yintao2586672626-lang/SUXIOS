<?php
declare(strict_types=1);

namespace Tests;

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

    public function testPublicPageTaskBridgeRequiresOperationModuleBeforeWrite(): void
    {
        $service = new ProtectedCapabilityService();
        $capability = $service->classifyPath(
            'POST',
            '/api/online-data/public-page-diagnosis/execution-intent'
        );

        self::assertIsArray($capability);
        self::assertSame('operation_decision', $capability['key']);
        self::assertSame('operation.view', $capability['permission']);
        $denied = $service->authorizeContext(
            $this->userWithPermissions(['operation.view']),
            $capability,
            ['system_hotel_id' => 7]
        );
        self::assertFalse($denied['allowed']);
        self::assertSame('module_not_entitled', $denied['reason']);

        $enabledService = new ProtectedCapabilityService([
            'default_enabled_modules' => ['operation_decision'],
        ]);
        $enabledCapability = $enabledService->classifyPath(
            'POST',
            '/api/online-data/public-page-diagnosis/execution-intent'
        );
        self::assertIsArray($enabledCapability);
        $allowed = $enabledService->authorizeContext(
            $this->userWithPermissions(['operation.view']),
            $enabledCapability,
            ['system_hotel_id' => 7]
        );
        self::assertTrue($allowed['allowed']);
    }

    public function testBasicReadPathsStayOutsideProtectedCore(): void
    {
        $service = new ProtectedCapabilityService();

        self::assertNull($service->classifyPath('GET', '/api/daily-reports/123'));
        self::assertNull($service->classifyPath('GET', '/api/daily-reports?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/daily-data-list?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/daily-data-summary?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/data-sources?hotel_id=7'));
        self::assertNull($service->classifyPath('GET', '/api/online-data/history?hotel_id=7'));
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
        self::assertSame('collection_health', $historyDetail['key']);
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
        ], static fn(int $hotelId): int => $hotelId === 7 ? 71 : 0);
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

    public function testTenantEntitlementIsResolvedFromSelectedHotel(): void
    {
        $service = new ProtectedCapabilityService([
            'tenant_modules' => [
                '71' => ['ai_decision'],
            ],
        ], static fn(int $hotelId): int => $hotelId === 7 ? 71 : 0);
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
        ], static fn(int $hotelId): int => $hotelId);
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

    /**
     * @param array<int, string> $permissions
     */
    private function userWithPermissions(
        array $permissions,
        bool $superAdmin = false,
        bool $hotelPermissionAllowed = true
    ): User
    {
        $role = new class($permissions) {
            /** @var array<int, string> */
            private array $permissions;

            /**
             * @param array<int, string> $permissions
             */
            public function __construct(array $permissions)
            {
                $this->permissions = $permissions;
            }

            public function hasPermission(string $permission): bool
            {
                return in_array('all', $this->permissions, true) || in_array($permission, $this->permissions, true);
            }
        };

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
                    'tenant_id' => 7,
                    'hotel_id' => 7,
                    'role' => $role,
                    default => null,
                };
            }
        );

        return $user;
    }
}
