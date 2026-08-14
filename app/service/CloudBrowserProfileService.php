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

    private const PLATFORMS = ['ctrip', 'meituan', 'dingdandao', 'meituan_cloud_pms'];
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
     * Ensure the exact hotel/user/platform Profile exists without issuing a
     * login ticket or starting any browser work.
     *
     * @return array<string,mixed>
     */
    public function ensureProfile(int $hotelId, int $ownerUserId, string $platform): array
    {
        if ($ownerUserId <= 0) {
            throw new RuntimeException('cloud_browser_owner_scope_missing');
        }
        $scope = $this->hotelScope($hotelId);
        $platform = $this->platform($platform);

        return Db::transaction(function () use ($scope, $hotelId, $ownerUserId, $platform): array {
            return $this->publicProfile($this->ensureProfileLocked(
                $scope['tenant_id'],
                $hotelId,
                $ownerUserId,
                $platform
            ));
        });
    }

    /**
     * Returns an opaque, one-time entry ticket. A browser gateway may consume
     * it later; issuing this ticket does not start a browser or login session.
     *
     * @return array<string,mixed>
     */
    public function requestLoginEntry(int $hotelId, int $ownerUserId, string $platform): array
    {
        if ($ownerUserId <= 0) {
            throw new RuntimeException('cloud_browser_owner_scope_missing');
        }
        $scope = $this->hotelScope($hotelId);
        $platform = $this->platform($platform);

        return Db::transaction(function () use ($scope, $hotelId, $ownerUserId, $platform): array {
            $profile = $this->ensureProfileLocked(
                $scope['tenant_id'],
                $hotelId,
                $ownerUserId,
                $platform
            );
            $now = date('Y-m-d H:i:s');

            $issuedSessions = Db::name('cloud_browser_login_sessions')
                ->where('profile_id', (int)$profile['id'])
                ->where('session_status', 'issued')
                ->lock(true)
                ->select()
                ->toArray();
            foreach ($issuedSessions as $issuedSession) {
                if (strtotime((string)($issuedSession['expires_at'] ?? '')) >= time()) {
                    throw new RuntimeException('cloud_browser_login_session_active');
                }
                Db::name('cloud_browser_login_sessions')->where('id', (int)$issuedSession['id'])->update([
                    'session_status' => 'expired',
                    'update_time' => $now,
                ]);
            }

            $from = strtolower((string)($profile['authorization_status'] ?? self::UNAUTHORIZED));
            $next = in_array($from, [self::SESSION_EXPIRED, self::READY_TO_COLLECT, self::LOGIN_VERIFIED, self::AWAITING_RELOGIN], true)
                ? self::AWAITING_RELOGIN
                : self::AWAITING_LOGIN;
            $profile = $this->transitionLocked($profile, $next, 'login_requested_from_' . $from);

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
     * Compensates an exact gateway-open failure. It never cancels a verified
     * session and restores the Profile state that existed before this ticket.
     *
     * @return array<string,mixed>
     */
    public function cancelLoginEntry(
        string $profilePublicId,
        string $sessionPublicId,
        string $reason = 'gateway_open_failed'
    ): array {
        return Db::transaction(function () use ($profilePublicId, $sessionPublicId, $reason): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = Db::name('cloud_browser_login_sessions')
                ->where('profile_id', (int)$profile['id'])
                ->where('session_public_id', trim($sessionPublicId))
                ->lock(true)
                ->find();
            if (!is_array($session) || (string)($session['session_status'] ?? '') !== 'issued') {
                throw new RuntimeException('cloud_browser_login_entry_not_cancellable');
            }

            $statusReason = strtolower(trim((string)($profile['status_reason'] ?? '')));
            if (preg_match('/^login_requested_from_([a-z_]+)$/D', $statusReason, $match) !== 1) {
                throw new RuntimeException('cloud_browser_login_rollback_state_missing');
            }
            $previous = (string)$match[1];
            if (!in_array($previous, [
                self::UNAUTHORIZED,
                self::AWAITING_LOGIN,
                self::LOGIN_VERIFIED,
                self::READY_TO_COLLECT,
                self::SESSION_EXPIRED,
                self::AWAITING_RELOGIN,
            ], true)) {
                throw new RuntimeException('cloud_browser_login_rollback_state_invalid');
            }
            $expectedCurrent = in_array($previous, [self::UNAUTHORIZED, self::AWAITING_LOGIN], true)
                ? self::AWAITING_LOGIN
                : self::AWAITING_RELOGIN;
            if ((string)($profile['authorization_status'] ?? '') !== $expectedCurrent) {
                throw new RuntimeException('cloud_browser_login_rollback_state_changed');
            }

            $now = date('Y-m-d H:i:s');
            Db::name('cloud_browser_login_sessions')->where('id', (int)$session['id'])->update([
                'session_status' => 'cancelled',
                'update_time' => $now,
            ]);
            $update = [
                'authorization_status' => $previous,
                'status_reason' => $this->reason($reason),
                'last_state_change_at' => $now,
                'update_time' => $now,
            ];
            Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update($update);
            return $this->publicProfile(array_merge($profile, $update));
        });
    }

    /**
     * Reconciles an ambiguous /login/complete response only after the caller
     * has independently verified that no exact gateway session remains.
     *
     * @return array{outcome:string,profile:array<string,mixed>}
     */
    public function reconcileLoginCompletionFailure(
        string $profilePublicId,
        string $sessionPublicId,
        string $reason = 'gateway_complete_failed'
    ): array {
        return Db::transaction(function () use ($profilePublicId, $sessionPublicId, $reason): array {
            $profile = $this->profileByPublicId($profilePublicId, true);
            $session = Db::name('cloud_browser_login_sessions')
                ->where('profile_id', (int)$profile['id'])
                ->where('session_public_id', trim($sessionPublicId))
                ->lock(true)
                ->find();
            if (!is_array($session)) {
                throw new RuntimeException('cloud_browser_login_completion_session_missing');
            }
            $sessionStatus = strtolower(trim((string)($session['session_status'] ?? '')));
            $profileStatus = strtolower(trim((string)($profile['authorization_status'] ?? '')));
            if ($sessionStatus === 'verified' && $profileStatus === self::READY_TO_COLLECT) {
                return ['outcome' => 'committed', 'profile' => $this->publicProfile($profile)];
            }
            if ($sessionStatus === 'cancelled') {
                return ['outcome' => 'cancelled', 'profile' => $this->publicProfile($profile)];
            }
            if ($sessionStatus !== 'issued') {
                throw new RuntimeException('cloud_browser_login_completion_state_ambiguous');
            }

            $statusReason = strtolower(trim((string)($profile['status_reason'] ?? '')));
            if (preg_match('/^login_requested_from_([a-z_]+)$/D', $statusReason, $match) !== 1) {
                throw new RuntimeException('cloud_browser_login_rollback_state_missing');
            }
            $previous = (string)$match[1];
            if (!in_array($previous, [
                self::UNAUTHORIZED,
                self::AWAITING_LOGIN,
                self::LOGIN_VERIFIED,
                self::READY_TO_COLLECT,
                self::SESSION_EXPIRED,
                self::AWAITING_RELOGIN,
            ], true)) {
                throw new RuntimeException('cloud_browser_login_rollback_state_invalid');
            }
            $expectedCurrent = in_array($previous, [self::UNAUTHORIZED, self::AWAITING_LOGIN], true)
                ? self::AWAITING_LOGIN
                : self::AWAITING_RELOGIN;
            if ($profileStatus !== $expectedCurrent) {
                throw new RuntimeException('cloud_browser_login_rollback_state_changed');
            }

            $now = date('Y-m-d H:i:s');
            Db::name('cloud_browser_login_sessions')->where('id', (int)$session['id'])->update([
                'session_status' => 'cancelled',
                'update_time' => $now,
            ]);
            $update = [
                'authorization_status' => $previous,
                'status_reason' => $this->reason($reason),
                'last_state_change_at' => $now,
                'update_time' => $now,
            ];
            Db::name('cloud_browser_profiles')->where('id', (int)$profile['id'])->update($update);
            return [
                'outcome' => 'cancelled',
                'profile' => $this->publicProfile(array_merge($profile, $update)),
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
        return $this->validatePmsCollectionProfile(
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $targetDate,
            'dingdandao'
        );
    }

    /**
     * Shared read-only preflight for independent PMS providers. Provider
     * differences stop at the profile/source boundary; hotel, user and date
     * ownership gates remain identical.
     *
     * @return array<string,mixed>
     */
    public function validatePmsCollectionProfile(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $platform
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('cloud_browser_collection_scope_invalid');
        }
        $platform = $this->platform($platform);
        if (!in_array($platform, ['dingdandao', 'meituan_cloud_pms'], true)) {
            throw new RuntimeException('cloud_browser_collection_platform_unsupported');
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
            || strtolower((string)$profile['platform']) !== $platform
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

        $source = $platform === 'dingdandao'
            ? [
                'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
                'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            ]
            : [
                'provider' => MeituanCloudPmsCaptureService::PROVIDER,
                'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
                'source_url' => MeituanCloudPmsCaptureService::SOURCE_URL,
            ];

        return [
            'validated' => true,
            'collection_kind' => 'operating_target_today',
            'access_mode' => 'read_only',
            'platform' => $platform,
            'provider' => $source['provider'],
            'source_scope' => $source['source_scope'],
            'source_url' => $source['source_url'],
            'target_date' => $date->format('Y-m-d'),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'expected_hotel_name' => (string)$hotel['name'],
            'profile' => $this->publicProfile($profile),
        ];
    }

    /**
     * Read-only preflight for a same-day OTA channel collection. This proves
     * the encrypted Profile, data source and registered Profile binding all
     * belong to the exact tenant/user/hotel/platform tuple. It does not prove
     * the current page session or authorize persistence; the collector must
     * establish those from fresh structured responses in the same run.
     *
     * @return array<string,mixed>
     */
    public function validateOtaCollectionProfile(
        string $profilePublicId,
        int $dataSourceId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $platform
    ): array {
        if ($dataSourceId <= 0 || $tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('cloud_browser_collection_scope_invalid');
        }
        $platform = $this->platform($platform);
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new RuntimeException('cloud_browser_collection_platform_unsupported');
        }

        $timezone = new DateTimeZone('Asia/Shanghai');
        $now = new DateTimeImmutable('now', $timezone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($targetDate), $timezone);
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
            || strtolower((string)$profile['platform']) !== $platform
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

        $source = Db::name('platform_data_sources')
            ->field('id,tenant_id,user_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->where('id', $dataSourceId)
            ->find();
        if (!is_array($source)
            || (int)($source['tenant_id'] ?? 0) !== $tenantId
            || (int)($source['user_id'] ?? 0) !== $ownerUserId
            || (int)($source['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($source['platform'] ?? ''))) !== $platform
            || !in_array(
                strtolower(trim((string)($source['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser'],
                true
            )
            || (int)($source['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
        ) {
            throw new RuntimeException('cloud_browser_ota_data_source_scope_mismatch');
        }

        try {
            $config = json_decode((string)($source['config_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('cloud_browser_ota_data_source_config_invalid', 0, $error);
        }
        if (!is_array($config)) {
            throw new RuntimeException('cloud_browser_ota_data_source_config_invalid');
        }
        $platformHotelId = trim((string)($config['platform_hotel_id'] ?? ''));
        if ($platformHotelId === '') {
            throw new RuntimeException('cloud_browser_ota_platform_hotel_id_missing');
        }
        $profileBindingKey = $this->otaProfileBindingKey($platform, $config);
        if ($profileBindingKey === '') {
            throw new RuntimeException('cloud_browser_ota_profile_binding_key_missing');
        }
        if (!hash_equals(trim($profilePublicId), $profileBindingKey)) {
            throw new RuntimeException('cloud_browser_ota_profile_id_mismatch');
        }
        (new OtaProfileBindingService())->assertBound($hotelId, $platform, $profileBindingKey);

        $sourceUrl = $platform === 'ctrip'
            ? 'https://ebooking.ctrip.com/home/mainland'
            : 'https://me.meituan.com/ebooking/';

        return [
            'validated' => true,
            'collection_kind' => 'ota_channel_profile',
            'access_mode' => 'read_only',
            'data_source_id' => $dataSourceId,
            'platform' => $platform,
            'source_scope' => 'ota_channel',
            'source_url' => $sourceUrl,
            'platform_hotel_id' => $platformHotelId,
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

    /** @param array<string,mixed> $config */
    private function otaProfileBindingKey(string $platform, array $config): string
    {
        $keys = [
            'profile_binding_key',
            'profileBindingKey',
            'stable_profile_id',
            'stableProfileId',
            'profile_id',
            'profileId',
            'browser_profile_id',
            'browserProfileId',
        ];
        $values = [];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                $value = trim((string)$config[$key]);
                $values[$value] = true;
            }
        }
        if (count($values) > 1) {
            throw new RuntimeException('cloud_browser_ota_profile_binding_key_conflict');
        }
        return count($values) === 1 ? (string)array_key_first($values) : '';
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
    private function ensureProfileLocked(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform
    ): array {
        $profile = $this->findProfile($tenantId, $hotelId, $ownerUserId, $platform, true);
        if (is_array($profile)) {
            return $profile;
        }

        $now = date('Y-m-d H:i:s');
        try {
            $profileId = (int)Db::name('cloud_browser_profiles')->insertGetId([
                'tenant_id' => $tenantId,
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
        } catch (\Throwable $error) {
            $profile = $this->findProfile($tenantId, $hotelId, $ownerUserId, $platform, true);
            if (!is_array($profile)) {
                throw new RuntimeException('cloud_browser_profile_create_failed', 0, $error);
            }
        }
        if (!is_array($profile)) {
            throw new RuntimeException('cloud_browser_profile_create_failed');
        }
        return $profile;
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
