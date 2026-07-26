<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Owns the cloud browser authorization state only.
 *
 * A future cloud-browser gateway is responsible for mapping profile_public_id
 * to a protected browser runtime. This service never creates a browser,
 * imports/exports a Cookie, or persists a browser profile path.
 */
final class CloudBrowserProfileService
{
    public const UNAUTHORIZED = 'unauthorized';
    public const AWAITING_LOGIN = 'awaiting_login';
    public const LOGIN_VERIFIED = 'login_verified';
    public const READY_TO_COLLECT = 'ready_to_collect';
    public const SESSION_EXPIRED = 'session_expired';
    public const AWAITING_RELOGIN = 'awaiting_relogin';

    private const PLATFORMS = ['ctrip', 'meituan'];
    private const LOGIN_TTL_SECONDS = 900;

    /** @return array<string,mixed> */
    public function status(int $hotelId, int $ownerUserId, ?string $platform = null): array
    {
        $scope = $this->hotelScope($hotelId);
        $query = Db::name('cloud_browser_profiles')
            ->where('tenant_id', $scope['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->where('owner_user_id', $ownerUserId)
            ->order('id', 'asc');
        if ($platform !== null && trim($platform) !== '') {
            $query->where('platform', $this->platform($platform));
        }
        $rows = $query->select()->toArray();
        return [
            'hotel_id' => $hotelId,
            'profiles' => array_map(fn(array $row): array => $this->publicProfile($row), $rows),
        ];
    }

    /**
     * Returns an opaque, one-time entry ticket. A browser gateway may consume
     * it later; issuing this ticket does not start a browser or login session.
     *
     * @return array<string,mixed>
     */
    public function requestLoginEntry(int $hotelId, int $ownerUserId, string $platform): array
    {
        $scope = $this->hotelScope($hotelId);
        $platform = $this->platform($platform);

        return Db::transaction(function () use ($scope, $hotelId, $ownerUserId, $platform): array {
            $profile = $this->findProfile($scope['tenant_id'], $hotelId, $ownerUserId, $platform, true);
            $now = date('Y-m-d H:i:s');
            if ($profile === null) {
                $profileId = (int)Db::name('cloud_browser_profiles')->insertGetId([
                    'tenant_id' => $scope['tenant_id'],
                    'system_hotel_id' => $hotelId,
                    'owner_user_id' => $ownerUserId,
                    'platform' => $platform,
                    'profile_public_id' => $this->publicId('cbp'),
                    'authorization_status' => self::UNAUTHORIZED,
                    'status_reason' => '',
                    'last_state_change_at' => $now,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                $profile = Db::name('cloud_browser_profiles')->where('id', $profileId)->lock(true)->find();
            }
            if (!is_array($profile)) {
                throw new RuntimeException('cloud_browser_profile_create_failed');
            }

            $from = strtolower((string)($profile['authorization_status'] ?? self::UNAUTHORIZED));
            $next = in_array($from, [self::SESSION_EXPIRED, self::READY_TO_COLLECT, self::LOGIN_VERIFIED, self::AWAITING_RELOGIN], true)
                ? self::AWAITING_RELOGIN
                : self::AWAITING_LOGIN;
            $profile = $this->transitionLocked($profile, $next, 'login_requested');

            Db::name('cloud_browser_login_sessions')
                ->where('profile_id', (int)$profile['id'])
                ->where('session_status', 'issued')
                ->update(['session_status' => 'superseded', 'update_time' => $now]);

            $ticket = $this->ticket();
            $sessionId = $this->publicId('cbls');
            $expiresAt = date('Y-m-d H:i:s', time() + self::LOGIN_TTL_SECONDS);
            Db::name('cloud_browser_login_sessions')->insert([
                'profile_id' => (int)$profile['id'],
                'session_public_id' => $sessionId,
                'ticket_hash' => hash('sha256', $ticket),
                'session_status' => 'issued',
                'requested_by' => $ownerUserId,
                'expires_at' => $expiresAt,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            return [
                'profile' => $this->publicProfile($profile),
                'login_entry' => [
                    'session_id' => $sessionId,
                    'ticket' => $ticket,
                    'expires_at' => $expiresAt,
                    'entry_mode' => 'cloud_browser_gateway_loopback',
                    'gateway_method' => 'POST',
                    'gateway_path' => '/v1/login/open',
                    'browser_started' => false,
                    'message' => '云端授权入口已创建；请在15分钟内通过受保护的本机网关完成登录。',
                ],
            ];
        });
    }

    /**
     * Intended for a future trusted cloud browser gateway, never a browser
     * client. The one-time ticket is checked and immediately consumed.
     *
     * @return array<string,mixed>
     */
    public function markLoginVerified(string $profilePublicId, string $sessionPublicId, string $ticket): array
    {
        return Db::transaction(function () use ($profilePublicId, $sessionPublicId, $ticket): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = $this->validatedLoginSession($profile, $sessionPublicId, $ticket);

            $now = date('Y-m-d H:i:s');
            Db::name('cloud_browser_login_sessions')->where('id', (int)$session['id'])->update([
                'session_status' => 'verified',
                'verified_at' => $now,
                'update_time' => $now,
            ]);
            return $this->publicProfile($this->transitionLocked($profile, self::LOGIN_VERIFIED, 'gateway_login_verified'));
        });
    }

    /**
     * Read-only gateway preflight. It validates an issued login entry without
     * consuming the one-time ticket or changing the Profile state.
     *
     * @return array<string,mixed>
     */
    public function validateLoginEntry(string $profilePublicId, string $sessionPublicId, string $ticket): array
    {
        return Db::transaction(function () use ($profilePublicId, $sessionPublicId, $ticket): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = $this->validatedLoginSession($profile, $sessionPublicId, $ticket);

            return [
                'profile' => $this->publicProfile($profile),
                'login_entry' => [
                    'session_id' => (string)$session['session_public_id'],
                    'expires_at' => (string)$session['expires_at'],
                    'validated' => true,
                    'consumed' => false,
                ],
            ];
        });
    }

    /**
     * Atomic trusted-gateway completion: consume the ticket, record verified
     * login, and make the encrypted Profile eligible for collection.
     *
     * @return array<string,mixed>
     */
    public function completeGatewayLogin(
        string $profilePublicId,
        string $sessionPublicId,
        string $ticket,
        string $sessionExpiresAt
    ): array {
        return Db::transaction(function () use ($profilePublicId, $sessionPublicId, $ticket, $sessionExpiresAt): array {
            $expiresAt = strtotime(trim($sessionExpiresAt));
            if ($expiresAt === false || $expiresAt <= time() || $expiresAt > time() + (30 * 86400)) {
                throw new RuntimeException('cloud_browser_session_expiry_invalid');
            }

            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = $this->validatedLoginSession($profile, $sessionPublicId, $ticket);
            $now = date('Y-m-d H:i:s');
            Db::name('cloud_browser_login_sessions')->where('id', (int)$session['id'])->update([
                'session_status' => 'verified',
                'verified_at' => $now,
                'update_time' => $now,
            ]);

            $profile = $this->transitionLocked($profile, self::LOGIN_VERIFIED, 'gateway_login_verified');
            $profile = $this->transitionLocked($profile, self::READY_TO_COLLECT, 'gateway_profile_encrypted');
            $normalizedExpiry = date('Y-m-d H:i:s', $expiresAt);
            Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update([
                'session_expires_at' => $normalizedExpiry,
                'update_time' => $now,
            ]);
            $profile['session_expires_at'] = $normalizedExpiry;

            return $this->publicProfile($profile);
        });
    }

    /** @return array<string,mixed> */
    public function markReadyToCollect(string $profilePublicId, ?string $sessionExpiresAt = null): array
    {
        return Db::transaction(function () use ($profilePublicId, $sessionExpiresAt): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            if ((string)$profile['authorization_status'] !== self::LOGIN_VERIFIED) {
                throw new RuntimeException('cloud_browser_login_verification_required');
            }
            $sessionExpiresAt = $this->sessionExpiry($sessionExpiresAt);
            $profile = $this->transitionLocked($profile, self::READY_TO_COLLECT, 'gateway_collection_ready');
            if ($sessionExpiresAt !== null) {
                Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update([
                    'session_expires_at' => $sessionExpiresAt,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
                $profile['session_expires_at'] = $sessionExpiresAt;
            }
            return $this->publicProfile($profile);
        });
    }

    /** @return array<string,mixed> */
    public function markSessionExpired(string $profilePublicId, string $reason = 'session_expired'): array
    {
        return Db::transaction(function () use ($profilePublicId, $reason): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            if (!in_array((string)$profile['authorization_status'], [self::LOGIN_VERIFIED, self::READY_TO_COLLECT], true)) {
                throw new RuntimeException('cloud_browser_session_not_active');
            }
            return $this->publicProfile($this->transitionLocked($profile, self::SESSION_EXPIRED, $this->reason($reason)));
        });
    }

    /** @return array{tenant_id:int} */
    private function hotelScope(int $hotelId): array
    {
        if ($hotelId <= 0) {
            throw new RuntimeException('cloud_browser_hotel_scope_missing');
        }
        $hotel = Db::name('hotels')->field('id,tenant_id')->where('id', $hotelId)->find();
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('cloud_browser_hotel_scope_missing');
        }
        return ['tenant_id' => (int)$hotel['tenant_id']];
    }

    private function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('cloud_browser_platform_unsupported');
        }
        return $platform;
    }

    private function reason(string $reason): string
    {
        $reason = strtolower(trim($reason));
        $reason = preg_replace('/[^a-z0-9_\-]+/', '_', $reason) ?: '';
        return substr(trim($reason, '_'), 0, 80) ?: 'state_updated';
    }

    private function sessionExpiry(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        if (!$expiry || $expiry->format('Y-m-d H:i:s') !== $value || $expiry->getTimestamp() <= time()) {
            throw new RuntimeException('cloud_browser_session_expiry_invalid');
        }
        return $value;
    }

    /** @return array<string,mixed>|null */
    private function findProfile(int $tenantId, int $hotelId, int $ownerUserId, string $platform, bool $lock): ?array
    {
        $query = Db::name('cloud_browser_profiles')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('owner_user_id', $ownerUserId)
            ->where('platform', $platform);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function profileByPublicId(string $profilePublicId, bool $lock): array
    {
        $query = Db::name('cloud_browser_profiles')->where('profile_public_id', trim($profilePublicId));
        if ($lock) {
            $query->lock(true);
        }
        $profile = $query->find();
        if (!is_array($profile)) {
            throw new RuntimeException('cloud_browser_profile_not_found');
        }
        return $profile;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function validatedLoginSession(array $profile, string $sessionPublicId, string $ticket): array
    {
        $session = Db::name('cloud_browser_login_sessions')
            ->where('profile_id', (int)$profile['id'])
            ->where('session_public_id', trim($sessionPublicId))
            ->lock(true)
            ->find();
        if (!is_array($session) || (string)($session['session_status'] ?? '') !== 'issued') {
            throw new RuntimeException('cloud_browser_login_entry_not_available');
        }
        if (strtotime((string)$session['expires_at']) < time()) {
            Db::name('cloud_browser_login_sessions')->where('id', (int)$session['id'])->update([
                'session_status' => 'expired',
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            throw new RuntimeException('cloud_browser_login_entry_expired');
        }
        if (!hash_equals((string)$session['ticket_hash'], hash('sha256', trim($ticket)))) {
            throw new RuntimeException('cloud_browser_login_entry_invalid');
        }
        return $session;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function transitionLocked(array $profile, string $next, string $reason): array
    {
        $from = strtolower(trim((string)($profile['authorization_status'] ?? self::UNAUTHORIZED)));
        $allowed = [
            self::UNAUTHORIZED => [self::AWAITING_LOGIN],
            self::AWAITING_LOGIN => [self::LOGIN_VERIFIED],
            self::LOGIN_VERIFIED => [self::READY_TO_COLLECT, self::SESSION_EXPIRED, self::AWAITING_RELOGIN],
            self::READY_TO_COLLECT => [self::SESSION_EXPIRED, self::AWAITING_RELOGIN],
            self::SESSION_EXPIRED => [self::AWAITING_RELOGIN],
            self::AWAITING_RELOGIN => [self::LOGIN_VERIFIED],
        ];
        if ($from !== $next && !in_array($next, $allowed[$from] ?? [], true)) {
            throw new RuntimeException('cloud_browser_invalid_state_transition');
        }
        $now = date('Y-m-d H:i:s');
        $update = [
            'authorization_status' => $next,
            'status_reason' => $this->reason($reason),
            'last_state_change_at' => $now,
            'update_time' => $now,
        ];
        if ($next === self::LOGIN_VERIFIED) {
            $update['login_verified_at'] = $now;
        }
        if ($next === self::READY_TO_COLLECT) {
            $update['ready_at'] = $now;
        }
        Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update($update);
        return array_merge($profile, $update);
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function publicProfile(array $profile): array
    {
        return [
            'profile_id' => (string)($profile['profile_public_id'] ?? ''),
            'hotel_id' => (int)($profile['system_hotel_id'] ?? 0),
            'platform' => strtolower((string)($profile['platform'] ?? '')),
            'authorization_status' => strtolower((string)($profile['authorization_status'] ?? self::UNAUTHORIZED)),
            'status_reason' => (string)($profile['status_reason'] ?? ''),
            'login_verified_at' => $profile['login_verified_at'] ?? null,
            'ready_at' => $profile['ready_at'] ?? null,
            'session_expires_at' => $profile['session_expires_at'] ?? null,
            'last_state_change_at' => $profile['last_state_change_at'] ?? null,
            'browser_started' => false,
        ];
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    private function ticket(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
