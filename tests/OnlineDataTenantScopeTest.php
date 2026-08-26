<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\exception\HttpException;

final class OnlineDataTenantScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testNonSuperUserDefaultsToOnlyPermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7]));

        self::assertSame(7, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [null]));
    }

    public function testNonSuperUserCanUseRequestedPermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7, 8]));

        self::assertSame(8, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [8]));
    }

    public function testNonSuperUserCannotUseUnpermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7]));

        $this->expectException(HttpException::class);

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [99]);
    }

    public function testNonSuperUserCannotFallbackFromExplicitUnpermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7], false, 7));

        $this->expectException(HttpException::class);

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [99]);
    }

    public function testNonSuperMultiHotelUserMustChooseHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7, 8]));

        $this->expectException(HttpException::class);

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [null]);
    }

    public function testSuperAdminCanUseRequestedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([], true));

        self::assertSame(99, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [99]));
    }

    public function testSuperAdminManualSaveScopeFailsClosedWhenHotelIsRequired(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([], true));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('请选择酒店');

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [null, true]);
    }

    public function testAnalyticsReadScopesRequireHotelViewPermission(): void
    {
        $withoutViewPermission = $this->controllerWithUser($this->tenantUser([7], false, 7));
        $withViewPermission = $this->controllerWithUser(
            $this->tenantUser([7, 8], false, 7, ['can_view_online_data'])
        );

        self::assertSame([], $this->invokeNonPublic(
            $withoutViewPermission,
            'permittedHotelIdsForAction',
            ['can_view_online_data']
        ));
        self::assertSame([7, 8], $this->invokeNonPublic(
            $withViewPermission,
            'permittedHotelIdsForAction',
            ['can_view_online_data']
        ));

        $analyticsSource = (string)file_get_contents(
            __DIR__ . '/../app/controller/concern/OnlineDataAnalyticsConcern.php'
        );
        self::assertGreaterThanOrEqual(2, substr_count(
            $analyticsSource,
            "permittedHotelIdsForAction('can_view_online_data')"
        ));
        self::assertStringContainsString("return \$this->error('无权查看该酒店线上数据', 403);", $analyticsSource);
    }

    public function testReleaseEvidenceStatusRejectsNonSuperUserEvenWithOnlineDataPermission(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7], false, 7, ['can_view_online_data']));

        $this->expectException(HttpException::class);

        $controller->releaseEvidenceStatus();
    }

    public function testReleaseEvidenceRequiredInputsRequireLiveReviewEvenWithHistoricalPrState(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([], true));

        $inputs = $this->invokeNonPublic($controller, 'releaseEvidenceRequiredInputs', [[
            'blockers' => [
                [
                    'id' => 'design-handoff-missing',
                    'status' => 'open',
                    'close_condition' => 'provide design handoff',
                ],
                [
                    'id' => 'ota-credential-rotation-attestation-missing',
                    'status' => 'open',
                    'close_condition' => 'provide OTA credential attestation',
                ],
            ],
            'external_state_check' => [
                'status' => 'passing_from_clean_verification_worktree',
            ],
        ]]);
        $byId = [];
        foreach ($inputs as $input) {
            $byId[(string)$input['id']] = $input;
        }

        self::assertSame('open', $byId['design_handoff_manifest']['status']);
        self::assertSame('open', $byId['ota_credential_rotation_attestation']['status']);
        self::assertSame('live_review_required', $byId['final_release_pr_and_local_state']['status']);
        self::assertSame('', $byId['final_release_pr_and_local_state']['success_evidence']);
        self::assertStringContainsString('review:release-external-state', $byId['final_release_pr_and_local_state']['next_action']);
    }

    public function testReleaseEvidenceRequiredInputsFollowClosedBlockers(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([], true));

        $inputs = $this->invokeNonPublic($controller, 'releaseEvidenceRequiredInputs', [[
            'blockers' => [
                [
                    'id' => 'design-handoff-missing',
                    'status' => 'closed',
                    'evidence' => 'controlled design manifest passed review:release-design',
                    'close_condition' => 'rerun on final head',
                ],
                [
                    'id' => 'ota-credential-rotation-attestation-missing',
                    'status' => 'open',
                    'close_condition' => 'provide credential-free attestation',
                ],
            ],
            'external_state_check' => [
                'status' => 'passing_from_clean_verification_worktree',
            ],
        ]]);
        $byId = [];
        foreach ($inputs as $input) {
            $byId[(string)$input['id']] = $input;
        }

        self::assertSame('closed', $byId['design_handoff_manifest']['status']);
        self::assertSame('', $byId['design_handoff_manifest']['success_evidence']);
        self::assertSame('open', $byId['ota_credential_rotation_attestation']['status']);
        self::assertSame('live_review_required', $byId['final_release_pr_and_local_state']['status']);
    }

    private function controllerWithUser(object $user): OnlineData
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getParentClass()->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);

        return $controller;
    }

    /**
     * @param array<int, int> $hotelIds
     */
    private function tenantUser(array $hotelIds, bool $superAdmin = false, ?int $hotelId = null, array $permissions = []): object
    {
        return new class($hotelIds, $superAdmin, $hotelId, $permissions) {
            public ?int $hotel_id = null;

            /**
             * @param array<int, int> $hotelIds
             * @param array<int, string> $permissions
             */
            public function __construct(private array $hotelIds, private bool $superAdmin, ?int $hotelId, private array $permissions)
            {
                $this->hotel_id = $hotelId;
            }

            public function isSuperAdmin(): bool
            {
                return $this->superAdmin;
            }

            /**
             * @return array<int, int>
             */
            public function getPermittedHotelIds(): array
            {
                return $this->hotelIds;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return in_array($hotelId, $this->hotelIds, true)
                    && in_array($permission, $this->permissions, true);
            }
        };
    }
}
