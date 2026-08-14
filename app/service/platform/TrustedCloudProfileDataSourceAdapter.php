<?php
declare(strict_types=1);

namespace app\service\platform;

use app\contract\DataSourceAdapter;
use RuntimeException;

/**
 * One-shot adapter for a payload captured from the currently open, validated
 * cloud Profile. Persistence still runs through PlatformDataSyncService and
 * all of its identity, freshness and exact-readback gates.
 */
final class TrustedCloudProfileDataSourceAdapter implements DataSourceAdapter
{
    private bool $consumed = false;

    /** @param array<string,mixed> $result */
    public function __construct(
        private readonly int $dataSourceId,
        private readonly string $platform,
        private readonly string $expectedPlatformHotelId,
        private readonly array $result
    ) {
        if ($dataSourceId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || trim($expectedPlatformHotelId) === ''
        ) {
            throw new RuntimeException('trusted_cloud_profile_adapter_scope_invalid');
        }
        $this->assertCaptureResult($result);
    }

    public function supports(array $source): bool
    {
        return (int)($source['id'] ?? 0) === $this->dataSourceId
            && strtolower(trim((string)($source['platform'] ?? ''))) === $this->platform
            && in_array(
                strtolower(trim((string)($source['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser'],
                true
            );
    }

    public function fetch(array $source, array $options = []): array
    {
        if (!$this->supports($source) || $this->consumed) {
            throw new RuntimeException('trusted_cloud_profile_adapter_reuse_blocked');
        }
        $this->consumed = true;
        return $this->result;
    }

    /** @param array<string,mixed> $result */
    private function assertCaptureResult(array $result): void
    {
        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $freshness = is_array($payload['network_freshness'] ?? null)
            ? $payload['network_freshness']
            : [];
        $auth = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $identity = is_array($payload['platform_identity_validation'] ?? null)
            ? $payload['platform_identity_validation']
            : [];
        $identityEvidence = strtolower(trim((string)($identity['evidence_source'] ?? '')));
        $validatedIdentifier = trim((string)($identity['validated_identifier'] ?? ''));

        if (($result['status'] ?? '') !== 'success'
            || strtolower(trim((string)($freshness['status'] ?? ''))) !== 'ready'
            || ($freshness['http_cache_disabled'] ?? null) !== true
            || ($freshness['service_worker_bypassed'] ?? null) !== true
            || ($freshness['sensitive_values_exposed'] ?? null) !== false
            || ($auth['ok'] ?? null) !== true
            || !in_array(strtolower(trim((string)($auth['status'] ?? ''))), ['logged_in', 'authorized'], true)
            || (int)($identity['schema_version'] ?? 0) !== 1
            || strtolower(trim((string)($identity['status'] ?? ''))) !== 'matched'
            || !in_array(
                $identityEvidence,
                ['ota_request', 'ota_request_or_own_response', 'trusted_ota_page_state'],
                true
            )
            || ($identity['sensitive_values_exposed'] ?? false) === true
            || $validatedIdentifier === ''
            || !hash_equals($this->expectedPlatformHotelId, $validatedIdentifier)
        ) {
            throw new RuntimeException('trusted_cloud_profile_capture_evidence_invalid');
        }
    }
}
