<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use think\facade\Db;

/**
 * Promotes one already-proven Meituan store identity from legacy aliases to
 * the canonical platform_hotel_id field. The preflight path is read-only and
 * every public receipt is deliberately free of the raw platform/Profile IDs.
 */
final class OtaPlatformHotelIdentityClaimService
{
    public const CONTRACT_VERSION = 'canonical_ota_platform_hotel_identity_claim.v1';
    public const PLATFORM = 'meituan';

    private const TIMEZONE = 'Asia/Shanghai';
    private const IDENTITY_SOURCE = 'same_origin_profile_probe';
    private const PROFILE_LOGIN_CONTRACT_VERSION = '2026-07-19.1';
    private const COLLECTION_PREFLIGHT_CONTRACT_VERSION = 'collection-preflight-v1';
    private const PROFILE_LOGIN_EVIDENCE_TYPE = 'recognized_business_response_2xx_plus_session_cookie';
    private const COLLECTION_PREFLIGHT_EVIDENCE_TYPE = 'successful_collection_preflight_identity_matched';
    private const PROFILE_METHODS = ['browser_profile', 'profile_browser'];
    private const LEGACY_IDENTITY_KEYS = ['store_id', 'storeId', 'poi_id', 'poiId'];
    private const PROFILE_KEYS = [
        'profile_binding_key', 'stable_profile_id', 'store_id', 'storeId',
        'poi_id', 'poiId', 'profile_id', 'profileId',
    ];

    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    public static function executionConfirmation(int $tenantId, int $hotelId, int $sourceId): string
    {
        return sprintf(
            'CLAIM MEITUAN CANONICAL IDENTITY TENANT %d HOTEL %d SOURCE %d',
            $tenantId,
            $hotelId,
            $sourceId
        );
    }

    /** @return array<string,mixed> */
    public function preflight(int $tenantId, int $hotelId, int $sourceId): array
    {
        try {
            $inspection = $this->inspect($tenantId, $hotelId, $sourceId, false, 'preflight');
            return $inspection['receipt'];
        } catch (Throwable) {
            return $this->blocked(
                $this->baseReceipt($tenantId, $hotelId, $sourceId, 'preflight'),
                'canonical_identity_claim_read_failed'
            );
        }
    }

    /** @return array<string,mixed> */
    public function execute(int $tenantId, int $hotelId, int $sourceId): array
    {
        try {
            return Db::transaction(function () use ($tenantId, $hotelId, $sourceId): array {
                $inspection = $this->inspect($tenantId, $hotelId, $sourceId, true, 'execute');
                $receipt = $inspection['receipt'];
                if (($receipt['claim_ready'] ?? false) !== true) {
                    return $receipt;
                }

                /** @var array<string,mixed> $context */
                $context = $inspection['context'];
                /** @var array<string,mixed> $beforeConfig */
                $beforeConfig = $context['config'];
                $candidate = (string)$context['candidate'];
                $probeAt = (string)$context['probe_at'];
                $expectedConfig = $beforeConfig;
                $writeNeeded = ($receipt['write']['needed'] ?? false) === true;
                if ($writeNeeded) {
                    $expectedConfig['platform_hotel_id'] = $candidate;
                    $expectedConfig['platform_hotel_identity_source'] = self::IDENTITY_SOURCE;
                    $expectedConfig['platform_hotel_identity_checked_at'] = $probeAt;
                    $expectedConfig['current_session_probe_platform_hotel_id'] = $candidate;
                }
                $affectedRows = 0;
                if ($writeNeeded) {
                    $affectedRows = Db::name('platform_data_sources')
                        ->where('id', $sourceId)
                        ->where('tenant_id', $tenantId)
                        ->where('system_hotel_id', $hotelId)
                        ->where('platform', self::PLATFORM)
                        ->update([
                            'config_json' => json_encode(
                                $expectedConfig,
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                            ),
                        ]);
                    if ($affectedRows !== 1) {
                        throw new \RuntimeException('canonical_identity_claim_exact_write_failed');
                    }
                }

                $readbackQuery = Db::name('platform_data_sources')
                    ->field('id,tenant_id,system_hotel_id,platform,config_json')
                    ->where('id', $sourceId)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', self::PLATFORM)
                    ->lock(true);
                $readback = $readbackQuery->find();
                if (!is_array($readback)) {
                    throw new \RuntimeException('canonical_identity_claim_readback_missing');
                }
                $readbackConfig = $this->decodeConfig($readback['config_json'] ?? null);
                if ($readbackConfig === null || $readbackConfig !== $expectedConfig) {
                    throw new \RuntimeException('canonical_identity_claim_readback_mismatch');
                }

                $receipt['claimed'] = $writeNeeded;
                $receipt['already_canonical'] = !$writeNeeded;
                $receipt['write'] = [
                    'needed' => false,
                    'attempted' => $writeNeeded,
                    'affected_rows' => $affectedRows,
                    'idempotent' => !$writeNeeded,
                    'config_only' => true,
                    'claim_fields_verified' => true,
                    'preserved_fields_verified' => true,
                    'readback_verified' => true,
                ];
                $receipt['receipt_digest'] = $this->receiptDigest($receipt);
                return $receipt;
            });
        } catch (Throwable) {
            return $this->blocked(
                $this->baseReceipt($tenantId, $hotelId, $sourceId, 'execute'),
                'canonical_identity_claim_transaction_failed'
            );
        }
    }

    /**
     * @return array{receipt:array<string,mixed>,context:array<string,mixed>}
     */
    private function inspect(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        bool $lock,
        string $mode
    ): array {
        $receipt = $this->baseReceipt($tenantId, $hotelId, $sourceId, $mode);
        if ($tenantId <= 0 || $hotelId <= 0 || $sourceId <= 0) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_scope_invalid'), 'context' => []];
        }

        $sourceQuery = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', self::PLATFORM);
        if ($lock) {
            $sourceQuery->lock(true);
        }
        $source = $sourceQuery->find();
        if (!is_array($source)) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_source_not_found'), 'context' => []];
        }
        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        if ((int)($source['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
            || !in_array($method, self::PROFILE_METHODS, true)
        ) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_source_not_eligible'), 'context' => []];
        }
        $receipt['source_scope_verified'] = true;

        $config = $this->decodeConfig($source['config_json'] ?? null);
        if ($config === null) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_config_invalid'), 'context' => []];
        }
        $candidates = $this->legacyCandidates($config);
        $receipt['identity_candidate_count'] = count($candidates);
        if ($candidates === []) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_candidate_missing'), 'context' => []];
        }
        if (count($candidates) !== 1) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_candidate_conflict'), 'context' => []];
        }
        $candidate = $candidates[0];
        $candidateDigest = $this->identityDigest($candidate);
        $receipt['identity_candidate_digest'] = $candidateDigest;

        $profileKey = $this->profileKey($config);
        $safeProfileKey = $profileKey === '' ? '' : BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        if ($safeProfileKey === '' || $safeProfileKey === 'default') {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_profile_key_missing'), 'context' => []];
        }
        $profileHash = hash('sha256', $safeProfileKey);
        $bindingQuery = Db::name('ota_profile_bindings')
            ->field('id,tenant_id,system_hotel_id,platform,profile_key_hash,binding_status')
            ->where('platform', self::PLATFORM)
            ->where('binding_status', 'active');
        if ($lock) {
            $bindingQuery->lock(true);
        }
        $bindings = $bindingQuery->select()->toArray();
        $exactBindings = array_values(array_filter(
            $bindings,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && hash_equals($profileHash, strtolower(trim((string)($row['profile_key_hash'] ?? ''))))
        ));
        $exactPlatformBindings = array_values(array_filter(
            $bindings,
            static fn(array $row): bool => (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
        ));
        $foreignProfileBindings = array_values(array_filter(
            $bindings,
            static fn(array $row): bool => hash_equals(
                $profileHash,
                strtolower(trim((string)($row['profile_key_hash'] ?? '')))
            ) && ((int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId)
        ));
        if (count($exactBindings) !== 1
            || count($exactPlatformBindings) !== 1
            || $foreignProfileBindings !== []
        ) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_profile_binding_invalid'), 'context' => []];
        }
        $profileBindingDigest = hash(
            'sha256',
            self::PLATFORM . "\0" . $tenantId . "\0" . $hotelId . "\0" . $sourceId . "\0" . $profileHash
        );
        $receipt['profile_binding'] = [
            'status' => 'verified',
            'exact_active_count' => 1,
            'foreign_scope_count' => 0,
            'digest' => $profileBindingDigest,
        ];

        $proofStatus = $this->proofStatus($config, $tenantId, $hotelId, $sourceId, $profileHash, $candidate);
        if (($proofStatus['verified'] ?? false) !== true) {
            return [
                'receipt' => $this->blocked(
                    $receipt,
                    (string)($proofStatus['blocker'] ?? 'canonical_identity_claim_proof_invalid')
                ),
                'context' => [],
            ];
        }
        $probeAt = (string)$proofStatus['probe_at'];
        $receipt['current_session_proof'] = [
            'status' => 'verified',
            'evidence_level' => 'strong',
            'identity_status' => 'matched',
            'same_source_profile_scope' => true,
            'probe_at' => $probeAt,
            'digest' => hash(
                'sha256',
                $candidateDigest . "\0" . $profileBindingDigest . "\0" . $probeAt
            ),
        ];

        $ownerQuery = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,enabled,status,config_json')
            ->where('platform', self::PLATFORM)
            ->where('enabled', 1);
        if ($lock) {
            $ownerQuery->lock(true);
        }
        $ownerRows = $ownerQuery->select()->toArray();
        $owners = [];
        foreach ($ownerRows as $ownerRow) {
            if (strtolower(trim((string)($ownerRow['status'] ?? ''))) === 'disabled') {
                continue;
            }
            $ownerConfig = $this->decodeConfig($ownerRow['config_json'] ?? null);
            if ($ownerConfig === null || !$this->configOwnsCandidate($ownerConfig, $candidate)) {
                continue;
            }
            $owners[] = [
                'source_id' => (int)($ownerRow['id'] ?? 0),
                'tenant_id' => (int)($ownerRow['tenant_id'] ?? 0),
                'system_hotel_id' => (int)($ownerRow['system_hotel_id'] ?? 0),
            ];
        }
        $foreignOwners = array_values(array_filter(
            $owners,
            static fn(array $owner): bool => (int)$owner['tenant_id'] !== $tenantId
                || (int)$owner['system_hotel_id'] !== $hotelId
        ));
        if ($foreignOwners !== []) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_cross_hotel_conflict'), 'context' => []];
        }
        usort($owners, static fn(array $left, array $right): int =>
            [$left['tenant_id'], $left['system_hotel_id'], $left['source_id']]
                <=> [$right['tenant_id'], $right['system_hotel_id'], $right['source_id']]
        );
        $receipt['ownership'] = [
            'status' => 'verified',
            'same_scope_owner_count' => count($owners),
            'foreign_scope_owner_count' => 0,
            'digest' => $this->digest([
                'identity_candidate_digest' => $candidateDigest,
                'owners' => $owners,
            ]),
        ];

        $canonical = trim((string)($config['platform_hotel_id'] ?? ''));
        $identityCheckedAt = trim((string)($config['platform_hotel_identity_checked_at'] ?? ''));
        $identityCheckedTimestamp = $identityCheckedAt === '' ? false : strtotime($identityCheckedAt);
        $probeTimestamp = strtotime($probeAt);
        $alreadyCanonical = $canonical !== ''
            && hash_equals($canonical, $candidate)
            && (string)($config['platform_hotel_identity_source'] ?? '') === self::IDENTITY_SOURCE
            && $identityCheckedTimestamp !== false
            && $probeTimestamp !== false
            && $identityCheckedTimestamp <= $probeTimestamp
            && hash_equals(
                $candidate,
                trim((string)($config['current_session_probe_platform_hotel_id'] ?? ''))
            );
        if ($canonical !== '' && !$alreadyCanonical) {
            return ['receipt' => $this->blocked($receipt, 'canonical_identity_claim_existing_canonical_conflict'), 'context' => []];
        }

        $receipt['status'] = 'ready';
        $receipt['claim_ready'] = true;
        $receipt['already_canonical'] = $alreadyCanonical;
        $receipt['write'] = [
            'needed' => !$alreadyCanonical,
            'attempted' => false,
            'affected_rows' => 0,
            'idempotent' => $alreadyCanonical,
            'config_only' => true,
            'claim_fields_verified' => $alreadyCanonical,
            'preserved_fields_verified' => $alreadyCanonical,
            'readback_verified' => $alreadyCanonical,
        ];
        $receipt['receipt_digest'] = $this->receiptDigest($receipt);

        return [
            'receipt' => $receipt,
            'context' => [
                'config' => $config,
                'candidate' => $candidate,
                'probe_at' => $probeAt,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{verified:bool,blocker?:string,probe_at?:string}
     */
    private function proofStatus(
        array $config,
        int $tenantId,
        int $hotelId,
        int $sourceId,
        string $profileHash,
        string $candidate
    ): array {
        if (($config['current_session_probe_performed'] ?? null) !== true
            || ($config['current_session_verified'] ?? null) !== true
            || strtolower(trim((string)($config['current_session_status'] ?? ''))) !== 'verified'
        ) {
            return ['verified' => false, 'blocker' => 'canonical_identity_claim_current_session_proof_missing'];
        }
        if ((int)($config['current_session_probe_data_source_id'] ?? 0) !== $sourceId
            || (int)($config['current_session_probe_tenant_id'] ?? 0) !== $tenantId
            || (int)($config['current_session_probe_system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($config['current_session_probe_platform'] ?? ''))) !== self::PLATFORM
            || trim((string)($config['current_session_probe_scope'] ?? '')) !== 'same_data_source_profile_session'
            || trim((string)($config['current_session_probe_timezone'] ?? '')) !== self::TIMEZONE
            || !hash_equals(
                $profileHash,
                strtolower(trim((string)($config['current_session_probe_profile_key_hash'] ?? '')))
            )
        ) {
            return ['verified' => false, 'blocker' => 'canonical_identity_claim_proof_scope_drift'];
        }

        $producer = trim((string)($config['current_session_probe_producer'] ?? ''));
        $contract = trim((string)($config['current_session_probe_contract_version'] ?? ''));
        $evidenceType = strtolower(trim((string)($config['current_session_probe_evidence_type'] ?? '')));
        $contractReady = $producer === 'platform_profile_login_task'
            ? $contract === self::PROFILE_LOGIN_CONTRACT_VERSION
                && $evidenceType === self::PROFILE_LOGIN_EVIDENCE_TYPE
            : $producer === 'platform_data_sync_preflight'
                && $contract === self::COLLECTION_PREFLIGHT_CONTRACT_VERSION
                && $evidenceType === self::COLLECTION_PREFLIGHT_EVIDENCE_TYPE;
        if (!$contractReady
            || strtolower(trim((string)($config['current_session_probe_evidence_level'] ?? ''))) !== 'strong'
            || strtolower(trim((string)($config['current_session_probe_identity_status'] ?? ''))) !== 'matched'
        ) {
            return ['verified' => false, 'blocker' => 'canonical_identity_claim_proof_not_strong_matched'];
        }

        $proofCandidate = trim((string)($config['current_session_probe_platform_hotel_id'] ?? ''));
        if ($proofCandidate !== '' && !hash_equals(strtolower($candidate), strtolower($proofCandidate))) {
            return ['verified' => false, 'blocker' => 'canonical_identity_claim_proof_identity_mismatch'];
        }

        $probeAtText = trim((string)($config['current_session_probe_at'] ?? ''));
        $probeAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $probeAtText,
            new DateTimeZone(self::TIMEZONE)
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($probeAt === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $probeAt->format('Y-m-d H:i:s') !== $probeAtText
            || trim((string)($config['current_session_probe_date'] ?? '')) !== $probeAt->format('Y-m-d')
            || $probeAt->format('Y-m-d') !== $this->now()->format('Y-m-d')
            || $probeAt > $this->now()->modify('+5 minutes')
        ) {
            return ['verified' => false, 'blocker' => 'canonical_identity_claim_proof_not_current'];
        }

        return ['verified' => true, 'probe_at' => $probeAtText];
    }

    /** @param array<string,mixed> $config @return array<int,string> */
    private function legacyCandidates(array $config): array
    {
        $values = [];
        foreach (self::LEGACY_IDENTITY_KEYS as $key) {
            $value = is_scalar($config[$key] ?? null) ? trim((string)$config[$key]) : '';
            if ($value !== '') {
                $values[strtolower($value)] = $value;
            }
        }
        return array_values($values);
    }

    /** @param array<string,mixed> $config */
    private function profileKey(array $config): string
    {
        foreach (self::PROFILE_KEYS as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    /** @param array<string,mixed> $config */
    private function configOwnsCandidate(array $config, string $candidate): bool
    {
        $values = $this->legacyCandidates($config);
        $canonical = trim((string)($config['platform_hotel_id'] ?? ''));
        if ($canonical !== '') {
            $values[] = $canonical;
        }
        foreach ($values as $value) {
            if (hash_equals(strtolower($candidate), strtolower($value))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function decodeConfig(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function baseReceipt(int $tenantId, int $hotelId, int $sourceId, string $mode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => 'blocked',
            'claim_ready' => false,
            'claimed' => false,
            'already_canonical' => false,
            'tenant_id' => max(0, $tenantId),
            'system_hotel_id' => max(0, $hotelId),
            'data_source_id' => max(0, $sourceId),
            'platform' => self::PLATFORM,
            'source_scope_verified' => false,
            'identity_candidate_count' => 0,
            'identity_candidate_digest' => null,
            'profile_binding' => [
                'status' => 'unverified',
                'exact_active_count' => 0,
                'foreign_scope_count' => 0,
                'digest' => null,
            ],
            'current_session_proof' => [
                'status' => 'unverified',
                'evidence_level' => null,
                'identity_status' => null,
                'same_source_profile_scope' => false,
                'probe_at' => null,
                'digest' => null,
            ],
            'ownership' => [
                'status' => 'unverified',
                'same_scope_owner_count' => 0,
                'foreign_scope_owner_count' => 0,
                'digest' => null,
            ],
            'write' => [
                'needed' => false,
                'attempted' => false,
                'affected_rows' => 0,
                'idempotent' => false,
                'config_only' => true,
                'claim_fields_verified' => false,
                'preserved_fields_verified' => false,
                'readback_verified' => false,
            ],
            'blockers' => [],
            'sensitive_values_exposed' => false,
            'receipt_digest' => null,
        ];
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function blocked(array $receipt, string $code): array
    {
        $receipt['status'] = 'blocked';
        $receipt['claim_ready'] = false;
        $receipt['blockers'] = [[
            'code' => preg_match('/^[a-z0-9_]{1,120}$/D', $code) === 1
                ? $code
                : 'canonical_identity_claim_blocked',
        ]];
        $receipt['receipt_digest'] = $this->receiptDigest($receipt);
        return $receipt;
    }

    private function identityDigest(string $candidate): string
    {
        return hash('sha256', self::PLATFORM . "\0" . strtolower(trim($candidate)));
    }

    /** @param array<string,mixed> $receipt */
    private function receiptDigest(array $receipt): string
    {
        unset($receipt['receipt_digest']);
        return $this->digest($receipt);
    }

    private function now(): DateTimeImmutable
    {
        $value = ($this->clock)();
        return $value->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private function digest(mixed $value): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->canonicalize($value),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
