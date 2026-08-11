<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

/**
 * Plans and explicitly stores one revocable, platform-specific authorization.
 * It never runs collection or an OTA/external action.
 */
final class CanonicalOtaScheduledAnalysisAuthorizationProvisioningService
{
    /** @var Closure(int):mixed */
    private Closure $statusLoader;

    /** @var Closure(int,array<string,mixed>,int):bool */
    private Closure $statusWriter;

    /** @var Closure(int):int */
    private Closure $hotelTenantResolver;

    /** @var Closure():string */
    private Closure $clock;

    public function __construct(
        ?callable $statusLoader = null,
        ?callable $statusWriter = null,
        ?callable $hotelTenantResolver = null,
        ?callable $clock = null
    ) {
        $this->statusLoader = $statusLoader !== null
            ? Closure::fromCallable($statusLoader)
            : static fn(int $hotelId): mixed =>
                Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
        $this->statusWriter = $statusWriter !== null
            ? Closure::fromCallable($statusWriter)
            : static fn(int $hotelId, array $status, int $ttl): bool =>
                Cache::set("online_data_auto_fetch_status_{$hotelId}", $status, $ttl);
        $this->hotelTenantResolver = $hotelTenantResolver !== null
            ? Closure::fromCallable($hotelTenantResolver)
            : static fn(int $hotelId): int => (int)Db::name('hotels')
                ->where('id', $hotelId)
                ->value('tenant_id');
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): string => date('Y-m-d\TH:i:sP');
    }

    /** @return array<string,mixed> */
    public function preview(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $planId
    ): array {
        return $this->run($tenantId, $hotelId, $platform, $planId, false);
    }

    /** @return array<string,mixed> */
    public function execute(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $planId
    ): array {
        return $this->run($tenantId, $hotelId, $platform, $planId, true);
    }

    /** @return array<string,mixed> */
    private function run(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $planId,
        bool $execute
    ): array {
        $tenantId = $this->positiveInteger($tenantId, 'tenant_id');
        $hotelId = $this->positiveInteger($hotelId, 'hotel_id');
        $platform = strtolower(trim($platform));
        $planId = strtolower(trim($planId));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('canonical_scheduled_analysis_platform_invalid');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,119}$/D', $planId) !== 1) {
            throw new InvalidArgumentException('canonical_scheduled_analysis_plan_id_invalid');
        }
        if (($this->hotelTenantResolver)($hotelId) !== $tenantId) {
            throw new RuntimeException('canonical_scheduled_analysis_hotel_tenant_mismatch');
        }

        if (!$execute) {
            $status = ($this->statusLoader)($hotelId);
            $status = is_array($status) ? $status : [];
            [, $grant, $idempotent] = $this->planStatus(
                $status,
                $tenantId,
                $hotelId,
                $platform,
                $planId
            );
            return $this->receipt('ready', false, !$idempotent, $idempotent, $grant, false);
        }

        $grant = [];
        $idempotent = false;
        $store = new OnlineDataAutoFetchStatusStore($this->statusLoader, $this->statusWriter);
        try {
            $store->mutate($hotelId, function (array $status) use (
                $tenantId,
                $hotelId,
                $platform,
                $planId,
                &$grant,
                &$idempotent
            ): array {
                [$plannedStatus, $grant, $idempotent] = $this->planStatus(
                    $status,
                    $tenantId,
                    $hotelId,
                    $platform,
                    $planId
                );
                return $plannedStatus;
            });
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'online_data_auto_fetch_status_write_failed') {
                throw new RuntimeException(
                    'canonical_scheduled_analysis_status_write_failed',
                    0,
                    $exception
                );
            }
            throw $exception;
        }
        $resolver = new CanonicalOtaScheduledAnalysisAuthorizationService($this->statusLoader);
        $readback = $resolver->assertMatches($grant, $tenantId, $hotelId, $platform);
        if ($readback !== $grant) {
            throw new RuntimeException('canonical_scheduled_analysis_status_readback_failed');
        }
        return $this->receipt('saved', true, false, $idempotent, $grant, true);
    }

    /**
     * @param array<string,mixed> $status
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:bool}
     */
    private function planStatus(
        array $status,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $planId
    ): array {
        if (($status['enabled'] ?? false) !== true) {
            throw new RuntimeException('canonical_scheduled_analysis_status_not_enabled');
        }
        $map = is_array($status['canonical_daily_analysis_authorizations'] ?? null)
            ? $status['canonical_daily_analysis_authorizations']
            : [];
        $existing = is_array($map[$platform] ?? null) ? $map[$platform] : [];
        if ($existing === [] && $platform === 'ctrip'
            && is_array($status['canonical_daily_analysis_authorization'] ?? null)
        ) {
            $existing = $status['canonical_daily_analysis_authorization'];
        }

        $grant = $this->matchingExistingGrant(
            $existing,
            $tenantId,
            $hotelId,
            $platform,
            $planId
        );
        $idempotent = $grant !== [];
        if ($grant === []) {
            $grant = $this->newGrant($tenantId, $hotelId, $platform, $planId);
        }
        $map[$platform] = $grant;
        $status['canonical_daily_analysis_authorizations'] = $map;
        if ($platform === 'ctrip') {
            $status['canonical_daily_analysis_authorization'] = $grant;
        }

        return [$status, $grant, $idempotent];
    }

    /** @return array<string,mixed> */
    private function matchingExistingGrant(
        array $grant,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $planId
    ): array {
        if ($grant === [] || strtolower(trim((string)($grant['plan_id'] ?? ''))) !== $planId) {
            return [];
        }
        try {
            $resolver = new CanonicalOtaScheduledAnalysisAuthorizationService(
                static fn(int $ignoredHotelId): array => [
                    'enabled' => true,
                    'canonical_daily_analysis_authorizations' => [$platform => $grant],
                ]
            );
            return $resolver->assertMatches($grant, $tenantId, $hotelId, $platform);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function newGrant(int $tenantId, int $hotelId, string $platform, string $planId): array
    {
        $authorizedAt = ($this->clock)();
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $authorizedAt);
        if (!$time instanceof \DateTimeImmutable || $time->format('Y-m-d\TH:i:sP') !== $authorizedAt) {
            throw new RuntimeException('canonical_scheduled_analysis_clock_invalid');
        }
        $grant = [
            'schema_version' => CanonicalOtaInvestigationActionService::SCHEDULED_AUTHORIZATION_VERSION,
            'enabled' => true,
            'plan_id' => $planId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'trigger' => 'historical_daily_canonical_promotion',
            'authorized_at' => $authorizedAt,
            'authorized_by' => 'user_goal',
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
        ];
        $grant['content_digest'] = $this->digest($grant);
        return $grant;
    }

    /** @return array<string,mixed> */
    private function receipt(
        string $status,
        bool $execute,
        bool $wouldWrite,
        bool $idempotent,
        array $grant,
        bool $readbackVerified
    ): array {
        return [
            'status' => $status,
            'execute' => $execute,
            'would_write' => $wouldWrite,
            'idempotent' => $idempotent,
            'readback_verified' => $readbackVerified,
            'tenant_id' => (int)$grant['tenant_id'],
            'hotel_id' => (int)$grant['hotel_id'],
            'platform' => (string)$grant['platform'],
            'plan_id' => (string)$grant['plan_id'],
            'authorization_digest' => (string)$grant['content_digest'],
            'analysis_only' => true,
            'operation_count' => 4,
            'external_action_allowed' => false,
            'collection_triggered' => false,
            'external_action_triggered' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new InvalidArgumentException('canonical_scheduled_analysis_positive_integer_required:' . $field);
        }
        return (int)$validated;
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
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
