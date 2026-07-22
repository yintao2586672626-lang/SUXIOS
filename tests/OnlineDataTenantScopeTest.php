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

    public function testReleaseEvidenceStatusRejectsNonSuperUserEvenWithOnlineDataPermission(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7], false, 7, ['can_view_online_data']));

        $this->expectException(HttpException::class);

        $controller->releaseEvidenceStatus();
    }

    public function testReleaseEvidenceRequiredInputsExposePassedPrState(): void
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

        self::assertSame('missing', $byId['design_handoff_manifest']['status']);
        self::assertSame('missing', $byId['ota_credential_rotation_attestation']['status']);
        self::assertSame('passed', $byId['final_release_pr_and_local_state']['status']);
        self::assertStringContainsString('review:release-external-state passed', $byId['final_release_pr_and_local_state']['success_evidence']);
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

        self::assertSame('passed', $byId['design_handoff_manifest']['status']);
        self::assertStringContainsString('controlled design manifest', $byId['design_handoff_manifest']['success_evidence']);
        self::assertSame('missing', $byId['ota_credential_rotation_attestation']['status']);
        self::assertSame('passed', $byId['final_release_pr_and_local_state']['status']);
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
