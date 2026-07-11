<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

final class OtaProfileSessionProofService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const VERIFIED_STATUSES = ['logged_in', 'authorized'];
    private const PROFILE_METHODS = ['browser_profile', 'profile_browser'];
    private const PROFILE_REUSE_WARNING_DAYS = 7;
    private const PROFILE_REUSE_EXPIRY_DAYS = 10;

    private OtaProfileBindingService $bindingService;
    private Closure $clock;

    public function __construct(?OtaProfileBindingService $bindingService = null, ?Closure $clock = null)
    {
        $this->bindingService = $bindingService ?? new OtaProfileBindingService();
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    /**
     * @param array<string, mixed> $authStatus
     * @param array<string, mixed> $metadataPatch
     * @return array<string, mixed>
     */
    public function recordVerified(
        int $dataSourceId,
        int $systemHotelId,
        string $platform,
        string $profileKey,
        bool $processSucceeded,
        array $authStatus,
        array $metadataPatch = []
    ): array {
        $platform = $this->normalizePlatform($platform);
        $profileKeyHash = $this->profileKeyHash($profileKey);
        $authStatusCode = strtolower(trim((string)($authStatus['status'] ?? '')));
        if (!$processSucceeded
            || ($authStatus['ok'] ?? null) !== true
            || !in_array($authStatusCode, self::VERIFIED_STATUSES, true)
        ) {
            throw new RuntimeException('Profile login evidence is not verified.');
        }
        if ($dataSourceId <= 0 || $systemHotelId <= 0) {
            throw new RuntimeException('Profile session proof source scope is missing.');
        }
        $this->assertMetadataPatchSafe($metadataPatch);

        $now = $this->now();

        return Db::transaction(function () use (
            $dataSourceId,
            $systemHotelId,
            $platform,
            $profileKey,
            $profileKeyHash,
            $metadataPatch,
            $now
        ): array {
            $binding = $this->bindingService->assertBound($systemHotelId, $platform, $profileKey);
            $source = Db::name('platform_data_sources')
                ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
                ->where('id', $dataSourceId)
                ->lock(true)
                ->find();
            if (!is_array($source)) {
                throw new RuntimeException('Profile session proof data source was not found.');
            }

            $this->assertSourceScope($source, $binding, $dataSourceId, $systemHotelId, $platform);
            $config = $this->decodeConfig((string)($source['config_json'] ?? ''));
            $sourceProfileKey = $this->sourceProfileKey($platform, $config);
            if ($sourceProfileKey === '' || $this->profileKeyHash($sourceProfileKey) !== $profileKeyHash) {
                throw new RuntimeException('Profile session proof Profile scope mismatch.');
            }

            $config = array_replace($config, $metadataPatch);
            $proof = [
                'current_session_probe_performed' => true,
                'current_session_verified' => true,
                'current_session_status' => 'verified',
                'current_session_probe_at' => $now->format('Y-m-d H:i:s'),
                'current_session_probe_data_source_id' => $dataSourceId,
                'current_session_probe_date' => $now->format('Y-m-d'),
                'current_session_probe_timezone' => self::TIMEZONE,
                'current_session_probe_platform' => $platform,
                'current_session_probe_tenant_id' => (int)$binding['tenant_id'],
                'current_session_probe_system_hotel_id' => $systemHotelId,
                'current_session_probe_profile_key_hash' => $profileKeyHash,
                'current_session_probe_scope' => 'same_data_source_profile_session',
                'current_session_probe_producer' => 'platform_profile_login_task',
            ];
            $config = array_replace($config, $proof);

            Db::name('platform_data_sources')->where('id', $dataSourceId)->update([
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'update_time' => $now->format('Y-m-d H:i:s'),
            ]);

            return $proof;
        });
    }

    /** @param array<string, mixed> $source */
    public function isCurrentVerified(array $source): bool
    {
        $proof = $this->validatedAuthoritativeProof($source);
        if ($proof === null) {
            return false;
        }

        $today = $this->now()->format('Y-m-d');
        return $proof['probe_at']->format('Y-m-d') === $today
            && $proof['probe_date'] === $today;
    }

    /**
     * Collection-only Profile reuse decision. This deliberately does not replace
     * the same-day current-session proof used by P0 and downstream truth gates.
     *
     * @param array<string, mixed> $source
     * @return array{status:string,is_reusable:bool,age_days:?int,days_until_forced_login:int,warning:bool,reason:string}
     */
    public function profileReuseState(array $source): array
    {
        $proof = $this->validatedAuthoritativeProof($source);
        if ($proof === null) {
            return $this->unverifiedReuseState();
        }

        $today = $this->now()->setTime(0, 0);
        $probeDay = $proof['probe_at']->setTime(0, 0);
        if ($probeDay > $today) {
            return $this->unverifiedReuseState();
        }
        $ageDays = (int)$probeDay->diff($today)->format('%a');
        $daysUntilForcedLogin = max(0, self::PROFILE_REUSE_EXPIRY_DAYS - $ageDays);

        if ($this->hasExplicitAuthenticationFailure($source, $proof['config'])) {
            return [
                'status' => 'expired',
                'is_reusable' => false,
                'age_days' => $ageDays,
                'days_until_forced_login' => $daysUntilForcedLogin,
                'warning' => false,
                'reason' => 'profile_session_explicitly_expired',
            ];
        }
        if ($ageDays >= self::PROFILE_REUSE_EXPIRY_DAYS) {
            return [
                'status' => 'expired',
                'is_reusable' => false,
                'age_days' => $ageDays,
                'days_until_forced_login' => 0,
                'warning' => false,
                'reason' => 'profile_reauthentication_required',
            ];
        }
        if ($ageDays >= self::PROFILE_REUSE_WARNING_DAYS) {
            return [
                'status' => 'renewal_warning',
                'is_reusable' => true,
                'age_days' => $ageDays,
                'days_until_forced_login' => $daysUntilForcedLogin,
                'warning' => true,
                'reason' => 'profile_reauthentication_recommended',
            ];
        }

        return [
            'status' => 'reusable',
            'is_reusable' => true,
            'age_days' => $ageDays,
            'days_until_forced_login' => $daysUntilForcedLogin,
            'warning' => false,
            'reason' => 'profile_proof_reusable',
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{config:array<string,mixed>,probe_at:DateTimeImmutable,probe_date:string}|null
     */
    private function validatedAuthoritativeProof(array $source): ?array
    {
        try {
            $dataSourceId = (int)($source['id'] ?? 0);
            $systemHotelId = (int)($source['system_hotel_id'] ?? 0);
            $tenantId = (int)($source['tenant_id'] ?? 0);
            $platform = $this->normalizePlatform((string)($source['platform'] ?? ''));
            $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
            if ($dataSourceId <= 0
                || $systemHotelId <= 0
                || $tenantId <= 0
                || !in_array($method, self::PROFILE_METHODS, true)
                || (int)($source['enabled'] ?? 0) !== 1
                || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
            ) {
                return null;
            }

            $config = $this->decodeConfig((string)($source['config_json'] ?? ''));
            if (($config['current_session_probe_performed'] ?? null) !== true
                || ($config['current_session_verified'] ?? null) !== true
                || strtolower(trim((string)($config['current_session_status'] ?? ''))) !== 'verified'
                || (int)($config['current_session_probe_data_source_id'] ?? 0) !== $dataSourceId
                || (int)($config['current_session_probe_tenant_id'] ?? 0) !== $tenantId
                || (int)($config['current_session_probe_system_hotel_id'] ?? 0) !== $systemHotelId
                || strtolower(trim((string)($config['current_session_probe_platform'] ?? ''))) !== $platform
                || (string)($config['current_session_probe_timezone'] ?? '') !== self::TIMEZONE
                || (string)($config['current_session_probe_scope'] ?? '') !== 'same_data_source_profile_session'
                || (string)($config['current_session_probe_producer'] ?? '') !== 'platform_profile_login_task'
            ) {
                return null;
            }

            $probeAt = $this->parseProbeAt((string)($config['current_session_probe_at'] ?? ''));
            $probeDate = trim((string)($config['current_session_probe_date'] ?? ''));
            if ($probeAt === null || $probeDate === '' || $probeAt->format('Y-m-d') !== $probeDate) {
                return null;
            }

            $profileKey = $this->sourceProfileKey($platform, $config);
            if ($profileKey === '') {
                return null;
            }
            $profileKeyHash = $this->profileKeyHash($profileKey);
            if (!hash_equals($profileKeyHash, (string)($config['current_session_probe_profile_key_hash'] ?? ''))) {
                return null;
            }

            $binding = $this->bindingService->assertBound($systemHotelId, $platform, $profileKey);
            if ((int)($binding['tenant_id'] ?? 0) !== $tenantId
                || (int)($binding['system_hotel_id'] ?? 0) !== $systemHotelId
                || strtolower((string)($binding['platform'] ?? '')) !== $platform
                || !hash_equals($profileKeyHash, (string)($binding['profile_key_hash'] ?? ''))
            ) {
                return null;
            }

            return [
                'config' => $config,
                'probe_at' => $probeAt,
                'probe_date' => $probeDate,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $config
     */
    private function hasExplicitAuthenticationFailure(array $source, array $config): bool
    {
        $statuses = [
            $source['last_sync_status'] ?? null,
            $config['last_sync_status'] ?? null,
            $config['login_status'] ?? null,
            $config['profile_status'] ?? null,
            $config['auth_status'] ?? null,
        ];
        foreach ($statuses as $status) {
            $normalized = strtolower(trim(is_scalar($status) ? (string)$status : ''));
            if (in_array($normalized, [
                'login_required', 'session_expired', 'login_expired', 'auth_failed',
                'unauthorized', 'forbidden', 'not_logged_in',
            ], true)) {
                return true;
            }
        }

        $messages = [
            $source['last_error'] ?? null,
            $config['last_error'] ?? null,
            $config['login_error'] ?? null,
            $config['auth_error'] ?? null,
        ];
        foreach ($messages as $message) {
            $text = trim(is_scalar($message) ? (string)$message : '');
            if ($text !== '' && preg_match(
                '/(?:login[_\s-]?required|session[_\s-]?expired|login[_\s-]?expired|auth(?:entication)?[_\s-]?failed|not[_\s-]?logged[_\s-]?in|unauthori[sz]ed|forbidden|\b401\b|\b403\b|登录(?:态|状态|会话)?(?:已)?(?:失效|过期)|请重新登录|需要重新登录)/iu',
                $text
            ) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return array{status:string,is_reusable:bool,age_days:null,days_until_forced_login:int,warning:bool,reason:string} */
    private function unverifiedReuseState(): array
    {
        return [
            'status' => 'unverified',
            'is_reusable' => false,
            'age_days' => null,
            'days_until_forced_login' => 0,
            'warning' => false,
            'reason' => 'profile_proof_unverified',
        ];
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $binding */
    private function assertSourceScope(
        array $source,
        array $binding,
        int $dataSourceId,
        int $systemHotelId,
        string $platform
    ): void {
        if ((int)($source['id'] ?? 0) !== $dataSourceId) {
            throw new RuntimeException('Profile session proof data source mismatch.');
        }
        if ((int)($source['tenant_id'] ?? 0) !== (int)($binding['tenant_id'] ?? 0)) {
            throw new RuntimeException('Profile session proof tenant scope mismatch.');
        }
        if ((int)($source['system_hotel_id'] ?? 0) !== $systemHotelId
            || (int)($binding['system_hotel_id'] ?? 0) !== $systemHotelId
        ) {
            throw new RuntimeException('Profile session proof hotel scope mismatch.');
        }
        if (strtolower(trim((string)($source['platform'] ?? ''))) !== $platform
            || strtolower(trim((string)($binding['platform'] ?? ''))) !== $platform
        ) {
            throw new RuntimeException('Profile session proof platform scope mismatch.');
        }
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        if (!in_array($method, self::PROFILE_METHODS, true)) {
            throw new RuntimeException('Profile session proof requires a browser Profile source.');
        }
        if ((int)($source['enabled'] ?? 0) !== 1 || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled') {
            throw new RuntimeException('Profile session proof data source is disabled.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeConfig(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Profile session proof source config is invalid.', 0, $e);
        }
        if (!is_array($config)) {
            throw new RuntimeException('Profile session proof source config is invalid.');
        }
        return $config;
    }

    /** @param array<string, mixed> $config */
    private function sourceProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['profile_binding_key', 'stable_profile_id', 'store_id', 'storeId', 'poi_id', 'poiId', 'profile_id', 'profileId']
            : ['profile_binding_key', 'stable_profile_id', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @param array<string, mixed> $metadataPatch */
    private function assertMetadataPatchSafe(array $metadataPatch): void
    {
        foreach ($metadataPatch as $key => $value) {
            $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim((string)$key)));
            $normalized = trim($normalized, '_');
            if (str_starts_with($normalized, 'current_session_')) {
                throw new RuntimeException('Current-session proof fields are owned by the proof service.');
            }
            if (in_array($normalized, [
                'cookies', 'cookie', 'auth_data', 'authorization', 'authorization_header',
                'token', 'password', 'secret', 'api_key', 'headers', 'headers_json',
                'set_cookie', 'access_token', 'refresh_token', 'encrypted_payload', 'ciphertext',
            ], true)) {
                throw new RuntimeException('Profile session proof metadata contains credential material.');
            }
            if (is_array($value)) {
                $this->assertMetadataPatchSafe($value);
            } elseif (!is_scalar($value) && $value !== null) {
                throw new RuntimeException('Profile session proof metadata contains an unsupported value.');
            }
        }
    }

    private function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new RuntimeException('Unsupported Profile session proof platform.');
        }
        return $platform;
    }

    private function profileKeyHash(string $profileKey): string
    {
        $canonical = BrowserProfileCaptureRequestService::safeFilePart(trim($profileKey));
        if ($canonical === '' || $canonical === 'default') {
            throw new RuntimeException('Profile session proof binding key is invalid.');
        }
        return hash('sha256', $canonical);
    }

    private function now(): DateTimeImmutable
    {
        $value = ($this->clock)();
        if (!$value instanceof DateTimeImmutable) {
            throw new RuntimeException('Profile session proof clock is invalid.');
        }
        return $value->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private function parseProbeAt(string $value): ?DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d H:i:s') !== trim($value)
        ) {
            return null;
        }
        return $parsed;
    }
}
