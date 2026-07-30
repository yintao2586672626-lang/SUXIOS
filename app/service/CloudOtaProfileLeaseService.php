<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use think\facade\Db;

final class CloudOtaProfileLeaseService
{
    private const GATEWAY_URL = 'http://127.0.0.1:8787';
    private const CDP_URL = 'http://127.0.0.1:9223';
    private const CONTROL_TOKEN_FILE =
        '/run/credentials/suxios-molanxin-three-source-collection.service/control-token';

    /** @var callable */
    private $gatewayRequester;

    /** @var callable */
    private $tokenLoader;

    /** @var callable */
    private $profileResolver;

    public function __construct(
        ?callable $gatewayRequester = null,
        ?callable $tokenLoader = null,
        ?callable $profileResolver = null
    ) {
        $this->gatewayRequester = $gatewayRequester
            ?? fn(string $path, string $token, array $body): array =>
                $this->gatewayRequest($path, $token, $body);
        $this->tokenLoader = $tokenLoader ?? fn(): string => $this->loadControlToken();
        $this->profileResolver = $profileResolver
            ?? static fn(int $tenantId, int $hotelId, int $ownerUserId, string $platform): ?array =>
                Db::name('cloud_browser_profiles')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('owner_user_id', $ownerUserId)
                    ->where('platform', $platform)
                    ->find();
    }

    public function withReadOnlyLease(
        array $source,
        string $targetDate,
        callable $collector
    ): mixed {
        $tenantId = (int)($source['tenant_id'] ?? 0);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $ownerUserId = (int)($source['user_id'] ?? 0);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($tenantId <= 0
            || $hotelId <= 0
            || $ownerUserId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !$this->isToday($targetDate)
        ) {
            throw new RuntimeException('cloud_ota_profile_lease_scope_invalid');
        }

        $profile = ($this->profileResolver)(
            $tenantId,
            $hotelId,
            $ownerUserId,
            $platform
        );
        if (!is_array($profile)) {
            throw new RuntimeException('cloud_ota_profile_not_ready');
        }
        $profileId = trim((string)($profile['profile_public_id'] ?? ''));
        if (preg_match('/^cbp_[A-Za-z0-9_-]{16,64}$/D', $profileId) !== 1
            || strtolower(trim((string)($profile['authorization_status'] ?? '')))
                !== CloudBrowserProfileService::READY_TO_COLLECT
        ) {
            throw new RuntimeException('cloud_ota_profile_not_ready');
        }

        $token = ($this->tokenLoader)();
        if (!is_string($token) || strlen(trim($token)) < 32) {
            throw new RuntimeException('cloud_ota_profile_control_token_unavailable');
        }
        $token = trim($token);
        $leaseId = '';
        $mainError = null;
        $result = null;
        try {
            $opened = ($this->gatewayRequester)(
                '/v1/profile-lease/open',
                $token,
                [
                    'profile_id' => $profileId,
                    'platform' => $platform,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'owner_user_id' => $ownerUserId,
                    'target_date' => $targetDate,
                    'lease_kind' => 'daily_collection',
                    'access_mode' => 'read_only',
                ]
            );
            if (($opened['status'] ?? '') !== 'profile_lease_open'
                || ($opened['browser_started'] ?? null) !== true
                || ($opened['profile_restored'] ?? null) !== true
                || ($opened['read_only_enforced'] ?? null) !== true
                || ($opened['session_owner'] ?? '') !== 'gateway_profile_lease'
                || ($opened['external_browser_required'] ?? null) !== false
                || ($opened['user_browser_closed'] ?? null) !== false
                || (string)($opened['profile_id'] ?? '') !== $profileId
                || (string)($opened['platform'] ?? '') !== $platform
                || (int)($opened['tenant_id'] ?? 0) !== $tenantId
                || (int)($opened['hotel_id'] ?? 0) !== $hotelId
                || (int)($opened['owner_user_id'] ?? 0) !== $ownerUserId
                || (string)($opened['target_date'] ?? '') !== $targetDate
            ) {
                throw new RuntimeException('cloud_ota_profile_lease_open_unverified');
            }
            $leaseId = trim((string)($opened['profile_lease_id'] ?? ''));
            if (preg_match('/^cbpl_[A-Za-z0-9_-]{16,64}$/D', $leaseId) !== 1) {
                throw new RuntimeException('cloud_ota_profile_lease_id_invalid');
            }
            $result = $collector(self::CDP_URL);
        } catch (Throwable $error) {
            $mainError = $error;
        } finally {
            if ($leaseId !== '') {
                try {
                    $closed = ($this->gatewayRequester)(
                        '/v1/profile-lease/close',
                        $token,
                        [
                            'profile_lease_id' => $leaseId,
                            'profile_id' => $profileId,
                            'platform' => $platform,
                            'outcome' => $mainError === null ? 'completed' : 'failed',
                        ]
                    );
                    if (($closed['status'] ?? '') !== 'profile_lease_closed'
                        || ($closed['owned_browser_closed'] ?? null) !== true
                        || ($closed['profile_encrypted_at_rest'] ?? null) !== true
                        || ($closed['user_browser_closed'] ?? null) !== false
                        || ($closed['sensitive_values_exposed'] ?? null) !== false
                    ) {
                        throw new RuntimeException('cloud_ota_profile_lease_close_unverified');
                    }
                } catch (Throwable $closeError) {
                    $token = str_repeat("\0", strlen($token));
                    throw new RuntimeException(
                        'cloud_ota_profile_lease_close_unverified',
                        0,
                        $mainError ?? $closeError
                    );
                }
            }
            $token = str_repeat("\0", strlen($token));
        }

        if ($mainError !== null) {
            throw new RuntimeException(
                'cloud_ota_profile_collection_failed',
                0,
                $mainError
            );
        }
        return $result;
    }

    private function isToday(string $value): bool
    {
        $zone = new DateTimeZone('Asia/Shanghai');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $zone);
        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === trim($value)
            && $date->format('Y-m-d') === (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    private function loadControlToken(): string
    {
        $configured = trim((string)getenv('SUXIOS_CLOUD_BROWSER_CONTROL_TOKEN_FILE'));
        $credentialsDirectory = rtrim(trim((string)getenv('CREDENTIALS_DIRECTORY')), '/');
        $fromCredentials = $credentialsDirectory !== ''
            ? $credentialsDirectory . '/control-token'
            : '';
        $path = $configured !== '' ? $configured : $fromCredentials;
        if ($path !== self::CONTROL_TOKEN_FILE) {
            throw new RuntimeException('cloud_ota_profile_control_token_file_invalid');
        }
        $token = @file_get_contents($path);
        return is_string($token) ? trim($token) : '';
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function gatewayRequest(string $path, string $token, array $body): array
    {
        if (!in_array($path, ['/v1/profile-lease/open', '/v1/profile-lease/close'], true)) {
            throw new RuntimeException('cloud_ota_profile_gateway_path_invalid');
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                'content' => json_encode(
                    $body,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents(self::GATEWAY_URL . $path, false, $context);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)
            || (($decoded['status'] ?? '') !== 'profile_lease_open'
                && ($decoded['status'] ?? '') !== 'profile_lease_closed')
        ) {
            $reason = preg_replace(
                '/[^a-zA-Z0-9_-]+/',
                '_',
                (string)($decoded['reason'] ?? 'cloud_ota_profile_gateway_failed')
            );
            throw new RuntimeException($reason ?: 'cloud_ota_profile_gateway_failed');
        }
        return $decoded;
    }
}
