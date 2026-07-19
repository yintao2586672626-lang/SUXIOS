<?php
declare(strict_types=1);

namespace app\service;

use app\model\CompetitorDevice;
use app\model\CompetitorHotel;
use app\model\Hotel;
use app\model\User;

final class CompetitorDeviceAuthService
{
    public function __construct(private ?PermissionService $permissionService = null)
    {
        $this->permissionService ??= new PermissionService();
    }

    /**
     * @return array{token: string, hash: string, hint: string}
     */
    public function issueCredential(): array
    {
        $token = bin2hex(random_bytes(32));
        $hash = password_hash($token, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('competitor_device_token_hash_failed');
        }

        return [
            'token' => $token,
            'hash' => $hash,
            'hint' => '…' . substr($token, -8),
        ];
    }

    public function verifyTokenHash(string $providedToken, string $storedHash): bool
    {
        return $providedToken !== ''
            && $storedHash !== ''
            && password_verify($providedToken, $storedHash);
    }

    /**
     * @return array{tenant_id: int, user_id: int, store_id: int}|null
     */
    public function resolveActiveScope(int $userId, int $storeId): ?array
    {
        if ($userId <= 0 || $storeId <= 0) {
            return null;
        }

        $hotel = Hotel::where('id', $storeId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->find();
        $user = User::where('id', $userId)
            ->where('status', User::STATUS_ENABLED)
            ->find();
        if (!$hotel || !$user) {
            return null;
        }
        $tenantId = (int)($hotel->tenant_id ?? 0);
        $userTenantId = (int)($user->tenant_id ?? 0);
        if ($tenantId <= 0 || $userTenantId <= 0 || $userTenantId !== $tenantId) {
            return null;
        }
        $authorization = $this->permissionService->authorize($user, 'ota.collect', $storeId);
        if (($authorization['allowed'] ?? false) !== true) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'store_id' => $storeId,
        ];
    }

    public function findAuthorizedBinding(
        string $deviceId,
        string $platform,
        int $storeId,
        string $providedToken
    ): ?CompetitorDevice {
        if ($deviceId === ''
            || $storeId <= 0
            || !in_array($platform, CompetitorHotel::platformCodes(), true)
            || $providedToken === ''
        ) {
            return null;
        }

        $binding = CompetitorDevice::where('device_id', $deviceId)
            ->where('platform', $platform)
            ->where('store_id', $storeId)
            ->where('status', 1)
            ->whereNull('revoked_at')
            ->find();
        if (!$binding || !$this->verifyTokenHash($providedToken, (string)($binding->token_hash ?? ''))) {
            return null;
        }
        if (!$this->bindingScopeIsActive($binding)) {
            return null;
        }

        return $binding;
    }

    public function bindingScopeIsActive(CompetitorDevice $binding): bool
    {
        $tenantId = (int)($binding->tenant_id ?? 0);
        $userId = (int)($binding->user_id ?? 0);
        $storeId = (int)($binding->store_id ?? 0);
        $platform = trim((string)($binding->platform ?? ''));
        if ($tenantId <= 0
            || $userId <= 0
            || $storeId <= 0
            || !in_array($platform, CompetitorHotel::platformCodes(), true)
            || (int)($binding->status ?? 0) !== 1
            || trim((string)($binding->revoked_at ?? '')) !== ''
        ) {
            return false;
        }

        $scope = $this->resolveActiveScope($userId, $storeId);
        return $scope !== null && $scope['tenant_id'] === $tenantId;
    }

    public function bindingSessionIsCurrent(CompetitorDevice $authenticatedBinding): bool
    {
        $bindingId = (int)($authenticatedBinding->id ?? 0);
        if ($bindingId <= 0) {
            return false;
        }

        $currentBinding = CompetitorDevice::where('id', $bindingId)->find();

        return $currentBinding !== null
            && $this->bindingSessionMatches($authenticatedBinding, $currentBinding);
    }

    public function bindingSessionMatches(
        CompetitorDevice $authenticatedBinding,
        CompetitorDevice $currentBinding
    ): bool {
        $authenticatedHash = (string)($authenticatedBinding->token_hash ?? '');
        $currentHash = (string)($currentBinding->token_hash ?? '');
        if ($authenticatedHash === '' || $currentHash === '') {
            return false;
        }

        return (int)$currentBinding->id === (int)$authenticatedBinding->id
            && (int)$currentBinding->token_version === (int)$authenticatedBinding->token_version
            && hash_equals($currentHash, $authenticatedHash)
            && (int)$currentBinding->tenant_id === (int)$authenticatedBinding->tenant_id
            && (int)$currentBinding->user_id === (int)$authenticatedBinding->user_id
            && (int)$currentBinding->store_id === (int)$authenticatedBinding->store_id
            && hash_equals((string)$currentBinding->platform, (string)$authenticatedBinding->platform)
            && $this->bindingScopeIsActive($currentBinding);
    }

    public function bindingMatchesTarget(
        CompetitorDevice $binding,
        int $tenantId,
        int $storeId,
        string $platform
    ): bool {
        return (int)($binding->tenant_id ?? 0) === $tenantId
            && (int)($binding->store_id ?? 0) === $storeId
            && hash_equals((string)($binding->platform ?? ''), $platform);
    }
}
