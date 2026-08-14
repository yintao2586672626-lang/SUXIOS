<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Issues a short-lived, opaque browser-viewer cookie and validates it for the
 * Nginx auth_request endpoint. The cache contains scope only: no OTA
 * credential, Cookie, gateway ticket, control token, or Profile path.
 */
final class CloudBrowserViewerAuthorizationService
{
    public const COOKIE_NAME = 'suxios_cloud_browser_viewer';
    public const MAX_TTL_SECONDS = 900;

    /**
     * @param array{tenant_id:int,hotel_id:int,owner_user_id:int,platform:string,profile_id:string,session_id:string} $scope
     * @return array{token:string,expires_at:string}
     */
    public function issue(array $scope, string $expiresAt): array
    {
        $scope = $this->normalizeScope($scope);
        $expiresAtTimestamp = strtotime(trim($expiresAt));
        $ttl = $expiresAtTimestamp === false
            ? 0
            : min(self::MAX_TTL_SECONDS, $expiresAtTimestamp - time());
        if ($ttl < 30) {
            throw new RuntimeException('cloud_browser_viewer_expiry_invalid');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $payload = $scope + [
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'issued_at' => date('Y-m-d H:i:s'),
        ];
        if (cache($this->cacheKey($token), $payload, $ttl) !== true) {
            throw new RuntimeException('cloud_browser_viewer_authorization_store_failed');
        }

        return [
            'token' => $token,
            'expires_at' => (string)$payload['expires_at'],
        ];
    }

    /**
     * @param array<string,mixed>|null $expectedScope
     * @return array<string,mixed>
     */
    public function authorize(string $token, ?array $expectedScope = null): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', trim($token)) !== 1) {
            throw new RuntimeException('cloud_browser_viewer_authorization_invalid');
        }
        $payload = cache($this->cacheKey($token));
        if (!is_array($payload)) {
            throw new RuntimeException('cloud_browser_viewer_authorization_missing');
        }
        $scope = $this->normalizeScope($payload);
        if (strtotime((string)($payload['expires_at'] ?? '')) < time()) {
            $this->revoke($token);
            throw new RuntimeException('cloud_browser_viewer_authorization_expired');
        }

        if ($expectedScope !== null) {
            $expected = $this->normalizeScope($expectedScope);
            foreach (array_keys($expected) as $field) {
                if ($scope[$field] !== $expected[$field]) {
                    throw new RuntimeException('cloud_browser_viewer_scope_mismatch');
                }
            }
        }

        $profile = Db::name('cloud_browser_profiles')
            ->where('profile_public_id', $scope['profile_id'])
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $scope['hotel_id'])
            ->where('owner_user_id', $scope['owner_user_id'])
            ->where('platform', $scope['platform'])
            ->find();
        if (!is_array($profile)) {
            throw new RuntimeException('cloud_browser_viewer_profile_scope_mismatch');
        }
        if (!in_array((string)($profile['authorization_status'] ?? ''), [
            CloudBrowserProfileService::AWAITING_LOGIN,
            CloudBrowserProfileService::AWAITING_RELOGIN,
        ], true)) {
            throw new RuntimeException('cloud_browser_viewer_profile_state_invalid');
        }

        $session = Db::name('cloud_browser_login_sessions')
            ->where('profile_id', (int)$profile['id'])
            ->where('session_public_id', $scope['session_id'])
            ->where('requested_by', $scope['owner_user_id'])
            ->where('session_status', 'issued')
            ->find();
        if (!is_array($session) || strtotime((string)($session['expires_at'] ?? '')) < time()) {
            throw new RuntimeException('cloud_browser_viewer_session_invalid');
        }

        return $scope + [
            'authorized' => true,
            'expires_at' => (string)$payload['expires_at'],
        ];
    }

    public function revoke(string $token): void
    {
        if ($token !== '') {
            cache($this->cacheKey($token), null);
        }
    }

    /** @param array<string,mixed> $scope @return array{tenant_id:int,hotel_id:int,owner_user_id:int,platform:string,profile_id:string,session_id:string} */
    private function normalizeScope(array $scope): array
    {
        $normalized = [
            'tenant_id' => (int)($scope['tenant_id'] ?? 0),
            'hotel_id' => (int)($scope['hotel_id'] ?? 0),
            'owner_user_id' => (int)($scope['owner_user_id'] ?? 0),
            'platform' => strtolower(trim((string)($scope['platform'] ?? ''))),
            'profile_id' => trim((string)($scope['profile_id'] ?? '')),
            'session_id' => trim((string)($scope['session_id'] ?? '')),
        ];
        if ($normalized['tenant_id'] <= 0 || $normalized['hotel_id'] <= 0 || $normalized['owner_user_id'] <= 0
            || !in_array($normalized['platform'], ['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'], true)
            || preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $normalized['profile_id']) !== 1
            || preg_match('/^cbls_[A-Za-z0-9_-]{16,64}$/D', $normalized['session_id']) !== 1
        ) {
            throw new RuntimeException('cloud_browser_viewer_scope_invalid');
        }
        return $normalized;
    }

    private function cacheKey(string $token): string
    {
        return 'cloud_browser_viewer:' . hash('sha256', trim($token));
    }
}
