<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;
use think\facade\Cache;

/**
 * Resolves the revocable, server-side grant for scheduled OTA analysis.
 *
 * The caller-provided receipt is only a candidate. Execution is authorized
 * only when the same normalized grant still exists in the protected hotel
 * auto-fetch status cache at the moment of use.
 */
final class CanonicalOtaScheduledAnalysisAuthorizationService
{
    /** @var Closure(int):mixed */
    private Closure $statusLoader;

    public function __construct(?callable $statusLoader = null)
    {
        $this->statusLoader = $statusLoader !== null
            ? Closure::fromCallable($statusLoader)
            : static fn(int $hotelId): mixed =>
                Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
    }

    /**
     * @param array<string,mixed> $suppliedAuthorization
     * @return array<string,mixed>
     */
    public function assertMatches(
        array $suppliedAuthorization,
        int $expectedTenantId,
        int $expectedHotelId,
        string $expectedPlatform
    ): array {
        $expectedPlatform = strtolower(trim($expectedPlatform));
        $supplied = $this->normalize(
            $suppliedAuthorization,
            $expectedTenantId,
            $expectedHotelId,
            $expectedPlatform
        );

        $status = ($this->statusLoader)($expectedHotelId);
        if (!is_array($status) || ($status['enabled'] ?? false) !== true) {
            throw new RuntimeException('canonical_scheduled_analysis_grant_unavailable');
        }
        $authorizationMap = is_array($status['canonical_daily_analysis_authorizations'] ?? null)
            ? $status['canonical_daily_analysis_authorizations']
            : [];
        $storedAuthorization = $authorizationMap[$expectedPlatform] ?? null;
        if (!is_array($storedAuthorization) && $expectedPlatform === 'ctrip') {
            // Backward-compatible read of the original single-platform grant.
            $storedAuthorization = $status['canonical_daily_analysis_authorization'] ?? null;
        }
        if (!is_array($storedAuthorization)) {
            throw new RuntimeException('canonical_scheduled_analysis_grant_unavailable');
        }
        $stored = $this->normalize(
            $storedAuthorization,
            $expectedTenantId,
            $expectedHotelId,
            $expectedPlatform
        );
        if ($stored !== $supplied) {
            throw new RuntimeException('canonical_scheduled_analysis_grant_mismatch');
        }

        return $stored;
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function normalize(
        array $authorization,
        int $expectedTenantId,
        int $expectedHotelId,
        string $expectedPlatform
    ): array {
        $normalized = [
            'schema_version' => trim((string)($authorization['schema_version'] ?? '')),
            'enabled' => ($authorization['enabled'] ?? false) === true,
            'plan_id' => strtolower(trim((string)($authorization['plan_id'] ?? ''))),
            'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
            'hotel_id' => (int)($authorization['hotel_id'] ?? 0),
            'platform' => strtolower(trim((string)($authorization['platform'] ?? ''))),
            'trigger' => strtolower(trim((string)($authorization['trigger'] ?? ''))),
            'authorized_at' => trim((string)($authorization['authorized_at'] ?? '')),
            'authorized_by' => strtolower(trim((string)($authorization['authorized_by'] ?? ''))),
            'analysis_only' => ($authorization['analysis_only'] ?? false) === true,
            'operation_count' => (int)($authorization['operation_count'] ?? 0),
            'external_action_allowed' => ($authorization['external_action_allowed'] ?? true) === true,
        ];
        $digest = strtolower(trim((string)($authorization['content_digest'] ?? '')));
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized['authorized_at']);
        if ($expectedTenantId <= 0
            || $expectedHotelId <= 0
            || !in_array($expectedPlatform, ['ctrip', 'meituan'], true)
            || $normalized['schema_version']
                !== CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION
            || $normalized['enabled'] !== true
            || preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $normalized['plan_id']) !== 1
            || $normalized['tenant_id'] !== $expectedTenantId
            || $normalized['hotel_id'] !== $expectedHotelId
            || $normalized['platform'] !== $expectedPlatform
            || $normalized['trigger'] !== 'historical_daily_canonical_promotion'
            || !($time instanceof \DateTimeImmutable)
            || $time->format('Y-m-d\TH:i:sP') !== $normalized['authorized_at']
            || $normalized['authorized_by'] !== 'user_goal'
            || $normalized['analysis_only'] !== true
            || $normalized['operation_count'] !== 4
            || $normalized['external_action_allowed'] !== false
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, $this->digest($normalized))
        ) {
            throw new RuntimeException('canonical_scheduled_analysis_grant_invalid');
        }
        $normalized['content_digest'] = $digest;
        return $normalized;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException) {
            return '';
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
