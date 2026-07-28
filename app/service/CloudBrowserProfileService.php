<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
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

    private const PLATFORMS = ['ctrip', 'meituan', 'dingdandao'];
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
     * Atomic trusted-gateway completion: consume the ticket and record the
     * verified encrypted session. Dingdandao still requires an exact provider
     * hotel-ID binding/readback before it may become collectable.
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
            if (strtolower((string)$profile['platform']) !== 'dingdandao') {
                $profile = $this->transitionLocked(
                    $profile,
                    self::READY_TO_COLLECT,
                    'gateway_profile_encrypted'
                );
            }
            $normalizedExpiry = date('Y-m-d H:i:s', $expiresAt);
            Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update([
                'session_expires_at' => $normalizedExpiry,
                'update_time' => $now,
            ]);
            $profile['session_expires_at'] = $normalizedExpiry;

            return $this->publicProfile($profile);
        });
    }

    /**
     * Durable, sanitized login state used when the gateway no longer has the
     * in-memory session (for example after a successful close or restart).
     *
     * @return array<string,mixed>
     */
    public function loginSessionStatus(
        string $profilePublicId,
        string $sessionPublicId,
        string $platform
    ): array {
        $platform = $this->platform($platform);
        $profile = $this->profileByPublicId($profilePublicId, false);
        if (strtolower((string)$profile['platform']) !== $platform) {
            throw new RuntimeException('cloud_browser_login_status_scope_mismatch');
        }
        $session = Db::name('cloud_browser_login_sessions')
            ->where('profile_id', (int)$profile['id'])
            ->where('session_public_id', trim($sessionPublicId))
            ->find();
        if (!is_array($session)) {
            throw new RuntimeException('cloud_browser_login_status_not_found');
        }

        $sessionStatus = strtolower(trim((string)($session['session_status'] ?? '')));
        $authorizationStatus = strtolower(trim((string)(
            $profile['authorization_status'] ?? self::UNAUTHORIZED
        )));
        $ticketExpiresAt = strtotime((string)($session['expires_at'] ?? ''));
        if ($sessionStatus === 'issued'
            && $ticketExpiresAt !== false
            && $ticketExpiresAt <= time()
        ) {
            $sessionStatus = 'expired';
        }
        $status = match ($sessionStatus) {
            'verified' => $authorizationStatus,
            'expired' => self::SESSION_EXPIRED,
            'superseded' => 'superseded',
            'issued' => 'session_not_active',
            default => 'unverified',
        };
        $terminal = in_array($sessionStatus, ['verified', 'expired', 'superseded'], true);
        $profileEncryptedAtRest = $terminal
            && !in_array($authorizationStatus, [
                self::AWAITING_LOGIN,
                self::AWAITING_RELOGIN,
            ], true);

        return [
            'profile_id' => (string)$profile['profile_public_id'],
            'session_id' => (string)$session['session_public_id'],
            'platform' => $platform,
            'status' => $status,
            'login_session_status' => $sessionStatus,
            'authorization_status' => $authorizationStatus,
            'expires_at' => $sessionStatus === 'issued'
                ? ($session['expires_at'] ?? null)
                : ($profile['session_expires_at'] ?? null),
            'identity_verified' => $sessionStatus === 'verified'
                && !empty($profile['login_verified_at']),
            'profile_encrypted_at_rest' => $profileEncryptedAtRest,
            'terminal' => $terminal,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Trusted-gateway timeout/failure closure. The issued ticket is consumed
     * as expired so database state cannot keep claiming that a browser login
     * window is still active after the gateway has sealed the Profile.
     *
     * @return array<string,mixed>
     */
    public function expireGatewayLogin(
        string $profilePublicId,
        string $sessionPublicId,
        string $ticket,
        string $reason = 'gateway_login_timeout'
    ): array {
        return Db::transaction(function () use (
            $profilePublicId,
            $sessionPublicId,
            $ticket,
            $reason
        ): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = Db::name('cloud_browser_login_sessions')
                ->where('profile_id', (int)$profile['id'])
                ->where('session_public_id', trim($sessionPublicId))
                ->lock(true)
                ->find();
            if (!is_array($session)
                || (string)($session['session_status'] ?? '') !== 'issued'
            ) {
                throw new RuntimeException(
                    'cloud_browser_login_entry_not_available'
                );
            }
            if (!hash_equals(
                (string)$session['ticket_hash'],
                hash('sha256', trim($ticket))
            )) {
                throw new RuntimeException(
                    'cloud_browser_login_entry_invalid'
                );
            }
            $now = date('Y-m-d H:i:s');
            Db::name('cloud_browser_login_sessions')
                ->where('id', (int)$session['id'])
                ->update([
                    'session_status' => 'expired',
                    'update_time' => $now,
                ]);

            return $this->publicProfile($this->transitionLocked(
                $profile,
                self::SESSION_EXPIRED,
                $this->reason($reason)
            ));
        });
    }

    /**
     * Read-only preflight for the trusted loopback gateway.  It proves the
     * Profile still belongs to the exact hotel/user scope and is eligible for
     * a same-day Dingdandao report read before any browser is started.
     *
     * @return array<string,mixed>
     */
    public function validateDingdandaoCollectionProfile(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('cloud_browser_collection_scope_invalid');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($targetDate), new DateTimeZone('Asia/Shanghai'));
        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== trim($targetDate)
            || $date->format('Y-m-d') !== $now->format('Y-m-d')
        ) {
            throw new RuntimeException('cloud_browser_collection_target_date_not_today');
        }

        $profile = $this->profileByPublicId($profilePublicId, false);
        if ((int)$profile['tenant_id'] !== $tenantId
            || (int)$profile['system_hotel_id'] !== $hotelId
            || (int)$profile['owner_user_id'] !== $ownerUserId
            || strtolower((string)$profile['platform']) !== 'dingdandao'
        ) {
            throw new RuntimeException('cloud_browser_collection_scope_mismatch');
        }
        if (strtolower((string)$profile['authorization_status']) !== self::READY_TO_COLLECT) {
            throw new RuntimeException('cloud_browser_collection_profile_not_ready');
        }
        $readyAt = $this->timestamp(
            (string)($profile['ready_at'] ?? ''),
            'cloud_browser_collection_ready_evidence_missing'
        );
        if ($readyAt > $now->getTimestamp()) {
            throw new RuntimeException('cloud_browser_collection_ready_evidence_invalid');
        }
        $sessionExpiresAt = $this->timestamp(
            (string)($profile['session_expires_at'] ?? ''),
            'cloud_browser_collection_session_expiry_missing'
        );
        if ($sessionExpiresAt <= $now->getTimestamp()) {
            throw new RuntimeException('cloud_browser_collection_session_expired');
        }

        $hotel = Db::name('hotels')
            ->field('id,tenant_id,name,status')
            ->where('id', $hotelId)
            ->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
            || trim((string)($hotel['name'] ?? '')) === ''
        ) {
            throw new RuntimeException('cloud_browser_collection_hotel_scope_invalid');
        }

        return [
            'validated' => true,
            'collection_kind' => 'operating_target_today',
            'access_mode' => 'read_only',
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'target_date' => $date->format('Y-m-d'),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'expected_hotel_name' => (string)$hotel['name'],
            'profile' => $this->publicProfile($profile),
        ];
    }

    /** @return array<string,mixed> */
    public function markReadyToCollect(string $profilePublicId, ?string $sessionExpiresAt = null): array
    {
        return Db::transaction(function () use ($profilePublicId, $sessionExpiresAt): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            if ((string)$profile['authorization_status'] !== self::LOGIN_VERIFIED) {
                throw new RuntimeException('cloud_browser_login_verification_required');
            }
            if (strtolower((string)$profile['platform']) === 'dingdandao') {
                throw new RuntimeException(
                    'cloud_browser_provider_binding_required'
                );
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

    private function timestamp(string $value, string $reason): int
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('Asia/Shanghai')
        );
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException($reason);
        }
        return $date->getTimestamp();
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
            self::AWAITING_LOGIN => [self::LOGIN_VERIFIED, self::SESSION_EXPIRED],
            self::LOGIN_VERIFIED => [self::READY_TO_COLLECT, self::SESSION_EXPIRED, self::AWAITING_RELOGIN],
            self::READY_TO_COLLECT => [self::SESSION_EXPIRED, self::AWAITING_RELOGIN],
            self::SESSION_EXPIRED => [self::AWAITING_RELOGIN],
            self::AWAITING_RELOGIN => [self::LOGIN_VERIFIED, self::SESSION_EXPIRED],
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
        if ($next === self::SESSION_EXPIRED) {
            $update['session_expires_at'] = null;
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
