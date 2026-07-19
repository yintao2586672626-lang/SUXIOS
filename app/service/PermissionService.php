<?php
declare(strict_types=1);

namespace app\service;

use app\model\Role;
use app\model\User;

class PermissionService
{
    private const PROTECTED_CAPABILITIES = [
        'hotel.delete',
        'ota.delete',
        'ota.collect_batch',
        'ota.export',
        'report.export',
        'ai.governance',
        'ai.execute',
        'operation.execute',
        'investment.simulate',
        'user.role_change',
        'system.config',
    ];

    private const NORMAL_EXTERNAL_DENIED_CAPABILITIES = [
        'all',
        'hotel.create',
        'hotel.update',
        'hotel.delete',
        'ota.collect',
        'ota.delete',
        'ota.collect_batch',
        'ota.export',
        'report.fill',
        'report.update',
        'report.delete',
        'report.export',
        'ai.governance',
        'ai.execute',
        'operation.execute',
        'investment.simulate',
        'user.role_change',
        'system.config',
    ];

    public function __construct(private ?HotelScopeService $hotelScopeService = null)
    {
        $this->hotelScopeService ??= new HotelScopeService();
    }

    /**
     * @return array{allowed: bool, reason: string, capability: string, hotel_id: int|null, protected: bool}
     */
    public function authorize(User $user, string $capability, ?int $hotelId = null): array
    {
        $capability = $this->normalizeCapability($capability);
        $protected = $this->isProtectedCapability($capability);

        if ($user->isSuperAdmin()) {
            if ($hotelId !== null && !$this->hotelScopeService->canAccessHotel($user, $hotelId, $capability)) {
                return [
                    'allowed' => false,
                    'reason' => 'hotel_scope_denied',
                    'capability' => $capability,
                    'hotel_id' => $hotelId,
                    'protected' => $protected,
                ];
            }

            return [
                'allowed' => true,
                'reason' => 'super_admin',
                'capability' => $capability,
                'hotel_id' => $hotelId,
                'protected' => $protected,
            ];
        }

        if (!$this->roleAllows($user, $capability)) {
            return [
                'allowed' => false,
                'reason' => 'role_permission_denied',
                'capability' => $capability,
                'hotel_id' => $hotelId,
                'protected' => $protected,
            ];
        }

        if ($hotelId !== null) {
            if (!$this->hotelScopeService->canAccessHotel($user, $hotelId, $capability)) {
                return [
                    'allowed' => false,
                    'reason' => 'hotel_scope_denied',
                    'capability' => $capability,
                    'hotel_id' => $hotelId,
                    'protected' => $protected,
                ];
            }

            if (!$this->hotelScopeService->hotelPermissionAllows($user, $hotelId, $capability)) {
                return [
                    'allowed' => false,
                    'reason' => 'hotel_permission_denied',
                    'capability' => $capability,
                    'hotel_id' => $hotelId,
                    'protected' => $protected,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => 'authorized',
            'capability' => $capability,
            'hotel_id' => $hotelId,
            'protected' => $protected,
        ];
    }

    public function roleAllows(User $user, string $capability): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $user->role;
        if (!$role instanceof Role || (int)$role->status !== Role::STATUS_ENABLED) {
            return false;
        }

        if ($this->isNormalExternalUser($user) && $this->isNormalExternalCapabilityDenied($capability)) {
            return false;
        }

        return Role::permissionListAllows($role->getPermissionList(), $capability);
    }

    /**
     * @return array<int, string>
     */
    public function roleCapabilities(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return ['all'];
        }

        $role = $user->role;
        if (!$role instanceof Role || (int)$role->status !== Role::STATUS_ENABLED) {
            return [];
        }

        $capabilities = $this->expandCapabilities($role->getPermissionList());
        if ($this->isNormalExternalUser($user)) {
            $capabilities = array_values(array_filter(
                $capabilities,
                fn(string $capability): bool => !$this->isNormalExternalCapabilityDenied($capability)
            ));
        }

        return $capabilities;
    }

    public function normalizeCapability(string $capability): string
    {
        return match ($capability) {
            'can_manage_own_hotels' => 'hotel.create',
            'can_manage_users' => 'user.role_change',
            'can_view_online_data' => 'ota.view',
            'can_fetch_online_data' => 'ota.collect',
            'can_delete_online_data' => 'ota.delete',
            'can_view_report' => 'report.view',
            'can_fill_daily_report', 'can_fill_monthly_task' => 'report.fill',
            'can_edit_report' => 'report.update',
            'can_delete_report' => 'report.delete',
            'can_export_data' => 'report.export',
            'can_use_ai_decision' => 'ai.execute',
            'can_manage_ai_governance' => 'ai.governance',
            'can_use_investment' => 'investment.simulate',
            default => $capability,
        };
    }

    public function isProtectedCapability(string $capability): bool
    {
        return in_array($this->normalizeCapability($capability), self::PROTECTED_CAPABILITIES, true);
    }

    public function isNormalExternalCapabilityDenied(string $capability): bool
    {
        return in_array($this->normalizeCapability($capability), self::NORMAL_EXTERNAL_DENIED_CAPABILITIES, true);
    }

    /**
     * @param array<int, string> $permissions
     * @return array<int, string>
     */
    public function normalExternalUnsafeCapabilities(array $permissions): array
    {
        $unsafe = [];
        foreach (self::NORMAL_EXTERNAL_DENIED_CAPABILITIES as $capability) {
            if (Role::permissionListAllows($permissions, $capability)) {
                $unsafe[] = $capability;
            }
        }

        return array_values(array_unique($unsafe));
    }

    /**
     * @param array<int, string> $permissions
     * @return array<int, string>
     */
    private function expandCapabilities(array $permissions): array
    {
        if (in_array('all', $permissions, true)) {
            return ['all'];
        }

        $expanded = [];
        foreach ($permissions as $permission) {
            $expanded[] = $permission;
            $expanded[] = $this->normalizeCapability($permission);
        }

        return array_values(array_unique(array_filter($expanded)));
    }

    private function isNormalExternalUser(User $user): bool
    {
        if ((int)($user->role_id ?? 0) === Role::NORMAL_USER) {
            return true;
        }

        $role = $user->role;
        if (!$role instanceof Role) {
            return false;
        }

        try {
            return (string)$role->getAttr('name') === 'normal_user'
                || (int)$role->getAttr('level') >= Role::HOTEL_STAFF;
        } catch (\Throwable) {
            return (string)($role->name ?? '') === 'normal_user'
                || (int)($role->level ?? 0) >= Role::HOTEL_STAFF;
        }
    }
}
