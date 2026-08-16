<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only preflight for the cloud owner's single-user local browser mode.
 *
 * This service never reads or stores cookies, tokens, passwords or Profile
 * contents. It only projects already persisted scope, permission and
 * authoritative same-day session proof into one collection gate.
 */
final class CloudOtaCollectionScopeService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const ALLOWED_AUTHORIZATION_MODES = [
        'same_tenant_explicit_hotel_grant',
        'cross_tenant_super_admin_explicit_hotel_grant',
    ];

    private Closure $currentSessionVerifier;
    private Closure $clock;

    public function __construct(?callable $currentSessionVerifier = null, ?callable $clock = null)
    {
        $this->currentSessionVerifier = $currentSessionVerifier !== null
            ? Closure::fromCallable($currentSessionVerifier)
            : static function (array $source): bool {
                try {
                    return (new OtaProfileSessionProofService())->isCurrentVerified($source);
                } catch (\Throwable) {
                    return false;
                }
            };
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone(self::TIMEZONE)
            );
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function evaluate(array $sources, array $scope): array
    {
        $rows = [];
        foreach ($sources as $source) {
            $rows[] = $this->evaluateSource($source, $scope);
        }

        $hardBlocked = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['status'] ?? '') === 'blocked'
        ));
        $pendingLogin = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['status'] ?? '') === 'pending_user_login'
        ));
        $collectionAllowed = $rows !== [] && $hardBlocked === [] && $pendingLogin === [];
        $status = $collectionAllowed
            ? 'ready_to_collect'
            : ($hardBlocked !== [] ? 'blocked' : 'pending_user_login');

        return [
            'status' => $status,
            'collection_allowed' => $collectionAllowed,
            'message' => match ($status) {
                'ready_to_collect' => '当前授权、采集范围、平台酒店身份和当天会话均已验证，可执行今日实时采集。',
                'pending_user_login' => '采集范围已绑定；请在同一云端浏览器登录并完成平台酒店身份验证。',
                default => '采集范围校验未通过，已在发起OTA请求前阻止采集。',
            },
            'mode' => (string)($scope['mode'] ?? ''),
            'authorization_mode' => (string)($scope['authorization_mode'] ?? ''),
            'tenant_id' => (int)($scope['tenant_id'] ?? 0),
            'user_id' => (int)($scope['user_id'] ?? 0),
            'hotel_id' => (int)($scope['hotel_id'] ?? 0),
            'source_ids' => array_values(array_map(
                static fn(array $row): int => (int)($row['data_source_id'] ?? 0),
                $rows
            )),
            'platforms' => array_values(array_unique(array_map(
                static fn(array $row): string => (string)($row['platform'] ?? ''),
                $rows
            ))),
            'sources' => $rows,
            'current_session_probe_performed' => false,
            'collection_performed' => false,
            'persistence_performed' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function evaluateSource(array $source, array $scope): array
    {
        $sourceId = (int)($source['id'] ?? 0);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $base = [
            'data_source_id' => $sourceId,
            'platform' => $platform,
            'tenant_id' => (int)($source['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0),
            'platform_hotel_identity_anchor_present' => false,
            'authorization_verified' => false,
            'collector_binding_verified' => false,
            'current_session_verified' => false,
            'session_verified_at' => null,
        ];

        if (!$this->sourceMatchesScope($source, $scope)) {
            return $this->blocked(
                $base,
                'scope_mismatch',
                '采集源不属于当前租户、用户、酒店或平台范围，已阻止采集。'
            );
        }

        if (!in_array(
            (string)($scope['authorization_mode'] ?? ''),
            self::ALLOWED_AUTHORIZATION_MODES,
            true
        )) {
            return $this->blocked(
                $base,
                'authorization_missing',
                '当前用户缺少该酒店有效且未过期的OTA采集授权，已阻止采集。'
            );
        }
        $base['authorization_verified'] = true;

        if (!$this->collectorBindingMatches($source, $scope, $config)) {
            return $this->blocked(
                $base,
                'binding_missing',
                '采集源尚未完整绑定当前云端单用户设备，已阻止采集。'
            );
        }
        $base['collector_binding_verified'] = true;

        $identityAnchor = $this->platformHotelIdentityAnchor($platform, $config);
        if ($identityAnchor === '') {
            return $this->blocked(
                $base,
                'platform_hotel_identity_missing',
                '未配置平台酒店身份锚点，无法确认是否为目标酒店，已阻止采集。'
            );
        }
        $base['platform_hotel_identity_anchor_present'] = true;

        $sessionStatus = strtolower(trim((string)($config['current_session_status'] ?? '')));
        $identityStatus = strtolower(trim((string)($config['current_session_probe_identity_status'] ?? '')));
        if ($sessionStatus === 'identity_mismatch' || $identityStatus === 'mismatch') {
            return $this->blocked(
                $base,
                'identity_mismatch',
                '当前页面的平台酒店身份与目标酒店绑定不一致，已阻止采集。'
            );
        }
        if ($sessionStatus === 'permission_denied') {
            return $this->blocked(
                $base,
                'permission_denied',
                '当前OTA账号无权访问目标平台酒店，已阻止采集。'
            );
        }

        $proofScopeMismatch = $this->sessionProofScopeMismatch(
            $source,
            $scope,
            $config,
            $identityAnchor
        );
        if ($proofScopeMismatch !== null) {
            return $this->blocked(
                $base,
                $proofScopeMismatch === 'platform_hotel_identity_mismatch'
                    ? 'identity_mismatch'
                    : 'session_scope_mismatch',
                $proofScopeMismatch === 'platform_hotel_identity_mismatch'
                    ? '当天会话证明的平台酒店身份锚点已被篡改或与绑定不一致，已阻止采集。'
                    : '当天会话证明不属于当前数据源、租户、酒店或平台，已阻止采集。'
            );
        }

        $verifiedAt = $this->verifiedAt($config);
        $currentSessionVerifier = $this->currentSessionVerifier;
        $currentSessionVerified = (bool)$currentSessionVerifier($source);
        if (!$currentSessionVerified
            || $verifiedAt === null
            || !$this->sessionProofMatchesScope($source, $scope, $config, $identityAnchor)
        ) {
            $hasOldProbe = trim((string)($config['current_session_probe_at'] ?? '')) !== '';
            $code = $hasOldProbe ? 'session_expired' : 'login_required';
            $message = $hasOldProbe
                ? '该平台会话验证已过期；请在同一云端浏览器重新登录并验证目标酒店身份。'
                : '请在同一云端浏览器登录该平台，并完成目标酒店身份验证。';
            return array_replace($base, [
                'status' => 'pending_user_login',
                'status_code' => $code,
                'message' => $message,
            ]);
        }

        $base['current_session_verified'] = true;
        $base['session_verified_at'] = $verifiedAt->format('Y-m-d H:i:s');
        return array_replace($base, [
            'status' => 'ready_to_collect',
            'status_code' => 'ready',
            'message' => '当前会话、平台酒店身份与授权已验证，可执行今日实时采集。',
        ]);
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $scope */
    private function sourceMatchesScope(array $source, array $scope): bool
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        return (string)($scope['mode'] ?? '') === 'single_user_local'
            && (int)($source['id'] ?? 0) > 0
            && in_array((int)$source['id'], (array)($scope['source_ids'] ?? []), true)
            && (int)($source['tenant_id'] ?? 0) === (int)($scope['tenant_id'] ?? 0)
            && (int)($source['user_id'] ?? 0) === (int)($scope['user_id'] ?? 0)
            && (int)($source['system_hotel_id'] ?? 0) === (int)($scope['hotel_id'] ?? 0)
            && in_array($platform, (array)($scope['platforms'] ?? []), true)
            && in_array($platform, ['ctrip', 'meituan'], true)
            && strtolower(trim((string)($source['ingestion_method'] ?? ''))) === 'browser_profile'
            && (int)($source['enabled'] ?? 0) === 1
            && strtolower(trim((string)($source['status'] ?? ''))) !== 'disabled';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $config
     */
    private function collectorBindingMatches(array $source, array $scope, array $config): bool
    {
        $deviceId = trim((string)($config['collector_device_id'] ?? ''));
        $deviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        $scopeDeviceId = trim((string)($scope['device_id'] ?? ''));
        $scopeDeviceHash = strtolower(trim((string)($scope['device_id_hash'] ?? '')));

        return strtolower(trim((string)($config['source_method'] ?? ''))) === 'single_user_local'
            && strtolower(trim((string)($config['collector_binding_mode'] ?? ''))) === 'single_user_local'
            && $deviceId !== ''
            && $scopeDeviceId !== ''
            && hash_equals($scopeDeviceId, $deviceId)
            && preg_match('/^[a-f0-9]{64}$/D', $deviceHash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $scopeDeviceHash) === 1
            && hash_equals($scopeDeviceHash, $deviceHash)
            && hash_equals(hash('sha256', $deviceId), $deviceHash)
            && (int)($config['collector_user_id'] ?? 0) === (int)($scope['user_id'] ?? 0)
            && (int)($config['collector_tenant_id'] ?? 0) === (int)($scope['tenant_id'] ?? 0)
            && (int)($config['collector_hotel_id'] ?? 0) === (int)($scope['hotel_id'] ?? 0)
            && strtolower(trim((string)($config['collector_platform'] ?? '')))
                === strtolower(trim((string)($source['platform'] ?? '')))
            && trim((string)($config['collector_bound_at'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $config */
    private function platformHotelIdentityAnchor(string $platform, array $config): string
    {
        return in_array($platform, ['ctrip', 'meituan'], true)
            ? trim((string)($config['platform_hotel_id'] ?? ''))
            : '';
    }

    /** @param array<string, mixed> $config */
    private function verifiedAt(array $config): ?DateTimeImmutable
    {
        $raw = trim((string)($config['current_session_probe_at'] ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $verifiedAt = new DateTimeImmutable($raw, new DateTimeZone(self::TIMEZONE));
            $now = ($this->clock)();
            if ($now->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d')
                !== $verifiedAt->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d')
            ) {
                return null;
            }
            return $verifiedAt;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $config
     */
    private function sessionProofMatchesScope(
        array $source,
        array $scope,
        array $config,
        string $platformHotelId
    ): bool {
        $probePlatformHotelId = trim((string)($config['current_session_probe_platform_hotel_id'] ?? ''));
        $now = ($this->clock)()->setTimezone(new DateTimeZone(self::TIMEZONE));
        return ($config['current_session_probe_performed'] ?? null) === true
            && ($config['current_session_verified'] ?? null) === true
            && strtolower(trim((string)($config['current_session_status'] ?? ''))) === 'verified'
            && (int)($config['current_session_probe_data_source_id'] ?? 0) === (int)($source['id'] ?? 0)
            && (int)($config['current_session_probe_tenant_id'] ?? 0) === (int)($scope['tenant_id'] ?? 0)
            && (int)($config['current_session_probe_system_hotel_id'] ?? 0) === (int)($scope['hotel_id'] ?? 0)
            && strtolower(trim((string)($config['current_session_probe_platform'] ?? '')))
                === strtolower(trim((string)($source['platform'] ?? '')))
            && (string)($config['current_session_probe_timezone'] ?? '') === self::TIMEZONE
            && (string)($config['current_session_probe_date'] ?? '') === $now->format('Y-m-d')
            && (string)($config['current_session_probe_scope'] ?? '') === 'same_data_source_profile_session'
            && strtolower(trim((string)($config['current_session_probe_identity_status'] ?? ''))) === 'matched'
            && $probePlatformHotelId !== ''
            && hash_equals($platformHotelId, $probePlatformHotelId);
    }

    /**
     * Missing proof is a login-required state. Contradictory proof is a hard
     * scope failure because it may indicate stale or cross-hotel metadata.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $config
     */
    private function sessionProofScopeMismatch(
        array $source,
        array $scope,
        array $config,
        string $platformHotelId
    ): ?string {
        if (($config['current_session_probe_performed'] ?? null) !== true) {
            return null;
        }
        $probePlatformHotelId = trim((string)($config['current_session_probe_platform_hotel_id'] ?? ''));
        if ($probePlatformHotelId !== '' && !hash_equals($platformHotelId, $probePlatformHotelId)) {
            return 'platform_hotel_identity_mismatch';
        }
        $scopeFieldsPresent = (int)($config['current_session_probe_data_source_id'] ?? 0) > 0
            || (int)($config['current_session_probe_tenant_id'] ?? 0) > 0
            || (int)($config['current_session_probe_system_hotel_id'] ?? 0) > 0
            || trim((string)($config['current_session_probe_platform'] ?? '')) !== '';
        if (!$scopeFieldsPresent) {
            return null;
        }
        if ((int)($config['current_session_probe_data_source_id'] ?? 0) !== (int)($source['id'] ?? 0)
            || (int)($config['current_session_probe_tenant_id'] ?? 0) !== (int)($scope['tenant_id'] ?? 0)
            || (int)($config['current_session_probe_system_hotel_id'] ?? 0) !== (int)($scope['hotel_id'] ?? 0)
            || strtolower(trim((string)($config['current_session_probe_platform'] ?? '')))
                !== strtolower(trim((string)($source['platform'] ?? '')))
        ) {
            return 'probe_scope_mismatch';
        }
        return null;
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private function blocked(array $base, string $code, string $message): array
    {
        return array_replace($base, [
            'status' => 'blocked',
            'status_code' => $code,
            'message' => $message,
        ]);
    }
}
