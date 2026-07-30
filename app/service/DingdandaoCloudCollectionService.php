<?php
declare(strict_types=1);

namespace app\service;

use app\model\OperationLog;
use app\model\Role;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Config;
use think\facade\Db;

/**
 * Server-side lifecycle for one read-only Dingdandao collection window.
 *
 * cloud_collection_tasks doubles as the durable claim record. The gateway may
 * claim and close a window, but only the trusted PHP runner can persist facts
 * and create a receipt from database readback.
 */
final class DingdandaoCloudCollectionService
{
    private const PLATFORM = 'dingdandao';
    private const COLLECTION_KIND = 'operating_target_today';
    private const ACCESS_MODE = 'read_only';
    private const BINDING_CONFIG_KEY = 'dingdandao_hotel_bindings';
    private const ALIAS_REGISTRY_CONFIG = 'dingdandao_hotel_alias_registry';
    private const ALIAS_REGISTRY_SCHEMA = 'suxios_hotel_provider_alias_registry.v1';
    private const MAX_WINDOW_SECONDS = 1800;
    private const REQUIRED_FIELDS = [
        'total_room_fee',
        'adr',
        'occupancy_rate_percent',
        'revpar',
        'sold_room_nights',
        'average_daily_room_nights',
        'room_fee_details',
        'provider_hotel_identity',
    ];
    private const CLOSE_OUTCOMES = [
        'completed',
        'cancelled',
        'failed',
        'policy_blocked',
        'report_blocked',
        'session_expired',
        'window_expired',
    ];

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    /** @return array<string,mixed> */
    public function bindingBootstrapScope(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId
    ): array {
        $profilePublicId = $this->opaqueId(
            $profilePublicId,
            'cbp_',
            'dingdandao_profile_id_invalid'
        );
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('dingdandao_binding_scope_invalid');
        }
        $now = $this->now();
        $requiredUntil = $now->modify('+5 minutes');
        return Db::transaction(function () use (
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $requiredUntil,
            $now
        ): array {
            $scope = $this->validatedBindingBootstrapScope(
                $profilePublicId,
                $tenantId,
                $hotelId,
                $ownerUserId,
                $requiredUntil,
                $now
            );
            return [
                'status' => 'ready_for_identity_probe',
                'profile_id' => $profilePublicId,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'owner_user_id' => $ownerUserId,
                'provider' => self::PLATFORM,
                'expected_provider_hotel_name' => (string)$scope['alias']['provider_name'],
                'alias_registry_version' => (string)$scope['alias']['registry_version'],
                'alias_fingerprint' => (string)$scope['alias']['alias_fingerprint'],
                'source_scope' => 'current_authenticated_session_identity_only',
                'binding_persisted' => false,
            ];
        });
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public function registerVerifiedBinding(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        array $identity,
        string $confirmation
    ): array {
        $profilePublicId = $this->opaqueId(
            $profilePublicId,
            'cbp_',
            'dingdandao_profile_id_invalid'
        );
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('dingdandao_binding_scope_invalid');
        }
        if (!hash_equals(
            'BIND DINGDANDAO HOTEL ' . $hotelId,
            trim($confirmation)
        )) {
            throw new RuntimeException('dingdandao_binding_confirmation_required');
        }
        $this->assertNoSensitiveMaterial($identity);
        $expectedKeys = [
            'capture_method',
            'captured_at',
            'identity_status',
            'provider_hotel_id',
            'provider_hotel_name',
            'request_count',
            'source_api_path',
        ];
        $actualKeys = array_keys($identity);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('dingdandao_binding_identity_invalid');
        }
        $providerHotelId = trim((string)$identity['provider_hotel_id']);
        $providerHotelName = trim((string)$identity['provider_hotel_name']);
        $capturedAt = trim((string)$identity['captured_at']);
        try {
            $capturedDate = new DateTimeImmutable($capturedAt);
        } catch (\Throwable) {
            $capturedDate = null;
        }
        $capturedTimestamp = $capturedDate?->getTimestamp();
        $now = $this->now();
        $captureAge = $capturedTimestamp === null
            ? PHP_INT_MAX
            : $now->getTimestamp() - $capturedTimestamp;
        if ($providerHotelId === ''
            || strlen($providerHotelId) > 120
            || preg_match('/^[A-Za-z0-9_-]+$/D', $providerHotelId) !== 1
            || $providerHotelName === ''
            || mb_strlen($providerHotelName) > 160
            || (string)$identity['identity_status'] !== 'matched'
            || (string)$identity['source_api_path'] !== '/v2/ntw/web/ntw/get'
            || (string)$identity['capture_method'] !== 'existing_session_direct_post'
            || (int)$identity['request_count'] !== 1
            || $capturedTimestamp === null
            || $captureAge < -300
            || $captureAge > 300
            || $capturedDate?->setTimezone(new DateTimeZone('Asia/Shanghai'))
                ->format('Y-m-d') !== $now->format('Y-m-d')
        ) {
            throw new RuntimeException('dingdandao_binding_identity_invalid');
        }

        $result = Db::transaction(function () use (
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $identity,
            $providerHotelId,
            $providerHotelName,
            $now
        ): array {
            $scope = $this->validatedBindingBootstrapScope(
                $profilePublicId,
                $tenantId,
                $hotelId,
                $ownerUserId,
                $now->modify('+5 minutes'),
                $now
            );
            if (!hash_equals(
                (string)$scope['alias']['provider_name'],
                $providerHotelName
            )) {
                throw new RuntimeException('dingdandao_binding_identity_mismatch');
            }
            return $this->persistVerifiedBinding(
                $tenantId,
                $hotelId,
                $ownerUserId,
                $profilePublicId,
                $providerHotelId,
                $providerHotelName,
                (string)$identity['captured_at'],
                $scope['alias'],
                $now
            );
        });
        $postCommitAlias = $this->aliasRegistryEntry($tenantId, $hotelId);
        $postCommitReadback = $this->binding($tenantId, $hotelId, $postCommitAlias);
        if (!hash_equals(
            (string)($result['binding_id'] ?? ''),
            (string)$postCommitReadback['binding_id']
        ) || !hash_equals(
            (string)($result['provider_hotel_id_fingerprint'] ?? ''),
            hash(
                'sha256',
                self::PLATFORM . ':' . $tenantId . ':' . $hotelId . ':'
                    . (string)$postCommitReadback['provider_hotel_id']
            )
        )) {
            throw new RuntimeException('dingdandao_collection_binding_post_commit_readback_failed');
        }
        $result['post_commit_readback_status'] = 'readback_verified';
        return $result;
    }

    /**
     * Atomically validates the caller-supplied scope against server state and
     * issues one durable claim per collection session.
     *
     * @return array<string,mixed>
     */
    public function claim(
        string $profilePublicId,
        string $collectionSessionId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $collectionKind,
        string $accessMode,
        string $windowExpiresAt
    ): array {
        $profilePublicId = $this->opaqueId($profilePublicId, 'cbp_', 'dingdandao_profile_id_invalid');
        $collectionSessionId = $this->opaqueId(
            $collectionSessionId,
            'cbcs_',
            'dingdandao_collection_session_id_invalid'
        );
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0) {
            throw new RuntimeException('dingdandao_collection_scope_invalid');
        }
        if ($collectionKind !== self::COLLECTION_KIND || $accessMode !== self::ACCESS_MODE) {
            throw new RuntimeException('dingdandao_collection_policy_invalid');
        }

        $now = $this->now();
        $targetDate = $this->today($targetDate, $now);
        $windowExpiresAt = $this->windowExpiry($windowExpiresAt, $now);
        if ($this->expireProfileBeforeClaimIfNeeded(
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $windowExpiresAt,
            $now
        )) {
            throw new RuntimeException('dingdandao_collection_profile_session_expired');
        }
        $idempotencyKey = hash('sha256', 'dingdandao-claim|' . $collectionSessionId);

        return Db::transaction(function () use (
            $profilePublicId,
            $collectionSessionId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $targetDate,
            $collectionKind,
            $accessMode,
            $windowExpiresAt,
            $idempotencyKey,
            $now
        ): array {
            $profile = $this->lockProfile($profilePublicId);
            $existing = Db::name('cloud_collection_tasks')
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();
            if (!is_array($existing)) {
                $activeTasks = Db::name('cloud_collection_tasks')
                    ->where('profile_id', (int)$profile['id'])
                    ->where('platform', self::PLATFORM)
                    ->where('collection_mode', self::COLLECTION_KIND)
                    ->where('target_date', $targetDate)
                    ->whereIn('task_status', ['collecting', 'capture_verified'])
                    ->lock(true)
                    ->select()
                    ->toArray();
                foreach ($activeTasks as $activeTask) {
                    if (!is_array($activeTask)
                        || !$this->reclaimExpiredActiveTask($activeTask, $now)
                    ) {
                        throw new RuntimeException('dingdandao_collection_claim_already_active');
                    }
                }
            }
            $scope = $this->validatedScope(
                $profilePublicId,
                $tenantId,
                $hotelId,
                $ownerUserId,
                $targetDate,
                $windowExpiresAt,
                $now
            );
            $claim = $this->claimEvidence(
                $profilePublicId,
                $collectionSessionId,
                $tenantId,
                $hotelId,
                $ownerUserId,
                $targetDate,
                $collectionKind,
                $accessMode,
                $windowExpiresAt,
                $scope['binding']
            );
            if (is_array($existing)) {
                $evidence = $this->decodeJson($existing['receipt_evidence_json'] ?? null);
                if (!$this->same($evidence['claim'] ?? null, $claim)) {
                    throw new RuntimeException('dingdandao_collection_claim_conflict');
                }
                if ((string)($existing['task_status'] ?? '') !== 'collecting') {
                    throw new RuntimeException('dingdandao_collection_claim_not_open');
                }
                return $this->claimResult($existing, $claim, 'reused');
            }

            $timestamp = $now->format('Y-m-d H:i:s');
            $row = [
                'task_public_id' => $this->publicId('cct'),
                'profile_id' => (int)$scope['profile']['id'],
                'profile_public_id' => $profilePublicId,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'owner_user_id' => $ownerUserId,
                'platform' => self::PLATFORM,
                'collection_mode' => self::COLLECTION_KIND,
                'target_date' => $targetDate,
                'window_key' => substr(hash('sha256', $collectionSessionId), 0, 32),
                'field_priority_json' => $this->json(self::REQUIRED_FIELDS),
                'task_status' => 'collecting',
                'truth_gate_status' => 'waiting_for_trusted_capture_readback',
                'gap_codes_json' => null,
                'receipt_evidence_json' => $this->json([
                    'contract_version' => 'dingdandao_cloud_collection.v1',
                    'claim' => $claim,
                ]),
                'receipt_fingerprint' => null,
                'formal_message_allowed' => 0,
                'idempotency_key' => $idempotencyKey,
                'started_at' => $timestamp,
                'finished_at' => null,
                'create_time' => $timestamp,
                'update_time' => $timestamp,
            ];
            try {
                $row['id'] = (int)Db::name('cloud_collection_tasks')->insertGetId($row);
            } catch (\Throwable $error) {
                $raced = Db::name('cloud_collection_tasks')
                    ->where('idempotency_key', $idempotencyKey)
                    ->lock(true)
                    ->find();
                if (!is_array($raced)) {
                    throw $error;
                }
                $evidence = $this->decodeJson($raced['receipt_evidence_json'] ?? null);
                if (!$this->same($evidence['claim'] ?? null, $claim)) {
                    throw new RuntimeException('dingdandao_collection_claim_conflict');
                }
                return $this->claimResult($raced, $claim, 'reused');
            }

            return $this->claimResult($row, $claim, 'recorded');
        });
    }

    /**
     * Closes the browser lifecycle only. This boundary deliberately reports
     * data_status=unverified and accepts no save/readback/capture assertions.
     *
     * @return array<string,mixed>
     */
    public function completeLifecycle(
        string $claimId,
        string $collectionSessionId,
        string $profilePublicId,
        string $outcome
    ): array {
        $claimId = $this->opaqueId($claimId, 'cct_', 'dingdandao_claim_id_invalid');
        $collectionSessionId = $this->opaqueId(
            $collectionSessionId,
            'cbcs_',
            'dingdandao_collection_session_id_invalid'
        );
        $profilePublicId = $this->opaqueId($profilePublicId, 'cbp_', 'dingdandao_profile_id_invalid');
        $outcome = strtolower(trim($outcome));
        if (!in_array($outcome, self::CLOSE_OUTCOMES, true)) {
            throw new RuntimeException('dingdandao_collection_outcome_invalid');
        }

        return Db::transaction(function () use (
            $claimId,
            $collectionSessionId,
            $profilePublicId,
            $outcome
        ): array {
            $profile = $this->lockProfile($profilePublicId);
            $task = $this->taskByClaim($claimId);
            $evidence = $this->decodeJson($task['receipt_evidence_json'] ?? null);
            $claim = $this->assertClaimIdentity(
                $task,
                $evidence['claim'] ?? null,
                $collectionSessionId,
                $profilePublicId
            );
            $storedLifecycle = $evidence['lifecycle'] ?? null;
            if (is_array($storedLifecycle)) {
                if ((string)($storedLifecycle['outcome'] ?? '') !== $outcome) {
                    throw new RuntimeException('dingdandao_collection_outcome_conflict');
                }
                return $this->lifecycleResult($task, $claim, $outcome, 'reused');
            }

            $now = $this->now()->format('Y-m-d H:i:s');
            $lifecycleFacts = [
                'outcome' => $outcome,
                'closed_at' => $now,
            ];
            $lifecycleFacts['fingerprint'] = hash(
                'sha256',
                $this->json([
                    'claim_id' => $claimId,
                    'collection_session_id' => $collectionSessionId,
                    'profile_id' => $profilePublicId,
                    'outcome' => $outcome,
                ])
            );
            $evidence['lifecycle'] = $lifecycleFacts;
            $hasTrustedReceipt = is_array($evidence['trusted_receipt'] ?? null);
            $pipelineCompleted = $outcome === 'completed' && $hasTrustedReceipt;
            $gaps = $pipelineCompleted
                ? []
                : ($hasTrustedReceipt
                    ? [$outcome === 'report_blocked'
                        ? 'operating_target_report_gate_blocked'
                        : 'operating_target_sync_or_pipeline_completion_missing']
                    : ['trusted_capture_readback_missing']);
            if ($outcome === 'session_expired') {
                $profileStatus = strtolower(trim((string)($profile['authorization_status'] ?? '')));
                if (in_array($profileStatus, [
                    CloudBrowserProfileService::LOGIN_VERIFIED,
                    CloudBrowserProfileService::READY_TO_COLLECT,
                ], true)) {
                    (new CloudBrowserProfileService())->markSessionExpired(
                        $profilePublicId,
                        'dingdandao_session_expired'
                    );
                }
            }
            $changes = [
                'task_status' => $pipelineCompleted ? 'truth_ready' : 'closed_unverified',
                'truth_gate_status' => $pipelineCompleted ? 'passed' : 'blocked_by_data_gap',
                'gap_codes_json' => $this->json($gaps),
                'receipt_evidence_json' => $this->json($evidence),
                'formal_message_allowed' => $pipelineCompleted ? 1 : 0,
                'finished_at' => $now,
                'update_time' => $now,
            ];
            Db::name('cloud_collection_tasks')->where('id', (int)$task['id'])->update($changes);
            $task = array_merge($task, $changes);

            return $this->lifecycleResult($task, $claim, $outcome, 'recorded');
        });
    }

    /**
     * Re-reads the locked claim and binding for the trusted runner. The
     * collector's exact expected platform name never comes from CLI input or
     * from the browser payload.
     *
     * @return array<string,mixed>
     */
    public function trustedCollectorScope(
        string $claimId,
        string $collectionSessionId,
        string $profilePublicId
    ): array {
        $claimId = $this->opaqueId($claimId, 'cct_', 'dingdandao_claim_id_invalid');
        $collectionSessionId = $this->opaqueId(
            $collectionSessionId,
            'cbcs_',
            'dingdandao_collection_session_id_invalid'
        );
        $profilePublicId = $this->opaqueId($profilePublicId, 'cbp_', 'dingdandao_profile_id_invalid');

        return Db::transaction(function () use (
            $claimId,
            $collectionSessionId,
            $profilePublicId
        ): array {
            $this->lockProfile($profilePublicId);
            $task = $this->taskByClaim($claimId);
            $evidence = $this->decodeJson($task['receipt_evidence_json'] ?? null);
            $claim = $this->assertClaimIdentity(
                $task,
                $evidence['claim'] ?? null,
                $collectionSessionId,
                $profilePublicId
            );
            $scope = $this->validatedScope(
                (string)$task['profile_public_id'],
                (int)$task['tenant_id'],
                (int)$task['system_hotel_id'],
                (int)$task['owner_user_id'],
                (string)$task['target_date'],
                (string)$claim['window_expires_at'],
                $this->now()
            );
            if (!hash_equals(
                (string)$claim['binding_fingerprint'],
                (string)$scope['binding']['binding_fingerprint']
            )) {
                throw new RuntimeException('dingdandao_collection_binding_changed');
            }
            return [
                'claim_id' => $claimId,
                'profile_id' => (string)$task['profile_public_id'],
                'tenant_id' => (int)$task['tenant_id'],
                'hotel_id' => (int)$task['system_hotel_id'],
                'owner_user_id' => (int)$task['owner_user_id'],
                'target_date' => (string)$task['target_date'],
                'provider_hotel_name' => (string)$scope['binding']['provider_hotel_name'],
                'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            ];
        });
    }

    /**
     * Trusted runner-only business completion. Scope and provider identity are
     * re-derived from the locked task and server binding; the returned receipt
     * is built exclusively from a fresh database capture readback.
     *
     * @param array<string,mixed> $captureInput
     * @return array<string,mixed>
     */
    public function completeTrustedCapture(
        string $claimId,
        string $collectionSessionId,
        string $profilePublicId,
        array $captureInput
    ): array {
        $claimId = $this->opaqueId($claimId, 'cct_', 'dingdandao_claim_id_invalid');
        $collectionSessionId = $this->opaqueId(
            $collectionSessionId,
            'cbcs_',
            'dingdandao_collection_session_id_invalid'
        );
        $profilePublicId = $this->opaqueId($profilePublicId, 'cbp_', 'dingdandao_profile_id_invalid');
        $this->assertNoSensitiveMaterial($captureInput);

        return Db::transaction(function () use (
            $claimId,
            $collectionSessionId,
            $profilePublicId,
            $captureInput
        ): array {
            $this->lockProfile($profilePublicId);
            $task = $this->taskByClaim($claimId);
            $evidence = $this->decodeJson($task['receipt_evidence_json'] ?? null);
            $claim = $this->assertClaimIdentity(
                $task,
                $evidence['claim'] ?? null,
                $collectionSessionId,
                $profilePublicId
            );
            $lifecycle = $evidence['lifecycle'] ?? null;
            if (is_array($lifecycle)) {
                throw new RuntimeException('dingdandao_collection_claim_closed');
            }
            if (!in_array((string)($task['task_status'] ?? ''), ['collecting', 'capture_verified'], true)) {
                throw new RuntimeException('dingdandao_collection_claim_not_open');
            }

            $now = $this->now();
            $scope = $this->validatedScope(
                (string)$task['profile_public_id'],
                (int)$task['tenant_id'],
                (int)$task['system_hotel_id'],
                (int)$task['owner_user_id'],
                (string)$task['target_date'],
                (string)$claim['window_expires_at'],
                $now
            );
            if (!hash_equals(
                (string)$claim['binding_fingerprint'],
                (string)$scope['binding']['binding_fingerprint']
            )) {
                throw new RuntimeException('dingdandao_collection_binding_changed');
            }
            if (!hash_equals(
                (string)$scope['binding']['provider_hotel_id'],
                trim((string)($captureInput['provider_hotel_id'] ?? ''))
            ) || !hash_equals(
                (string)$scope['binding']['provider_hotel_name'],
                trim((string)($captureInput['provider_hotel_name'] ?? ''))
            )) {
                throw new \InvalidArgumentException('dingdandao_capture_not_verified');
            }

            $captureService = new DingdandaoOperatingTargetCaptureService($this->clock);
            $saved = $captureService->save(
                (int)$task['tenant_id'],
                (int)$task['system_hotel_id'],
                (int)$task['owner_user_id'],
                (string)$scope['binding']['provider_hotel_name'],
                $captureInput,
                true,
                (string)$scope['binding']['provider_hotel_id']
            );
            $capture = $captureService->read(
                (int)$task['tenant_id'],
                (int)$task['system_hotel_id'],
                (int)$saved['id']
            );
            $this->assertVerifiedCapture($task, $scope['binding'], $capture);

            $receiptFacts = [
                'profile_id' => (string)$task['profile_public_id'],
                'collection_session_id' => $collectionSessionId,
                'tenant_id' => (int)$task['tenant_id'],
                'hotel_id' => (int)$task['system_hotel_id'],
                'owner_user_id' => (int)$task['owner_user_id'],
                'platform' => self::PLATFORM,
                'binding_id' => (string)$scope['binding']['binding_id'],
                'binding_fingerprint' => (string)$scope['binding']['binding_fingerprint'],
                'alias_registry_version' => (string)$scope['binding']['alias_registry_version'],
                'alias_fingerprint' => (string)$scope['binding']['alias_fingerprint'],
                'system_hotel_name' => (string)$scope['binding']['system_hotel_name'],
                'provider_hotel_id' => (string)$capture['provider_hotel_id'],
                'provider_hotel_name' => (string)$capture['provider_hotel_name'],
                'capture_id' => (int)$capture['id'],
                'capture_fingerprint' => (string)$capture['source_fingerprint'],
                'target_date' => (string)$capture['business_date'],
                'capture_status' => (string)$capture['capture_status'],
                'quality_status' => (string)$capture['quality_status'],
                'identity_status' => (string)$capture['identity_status'],
                'reconciliation_status' => (string)$capture['reconciliation_status'],
                'readback_status' => (string)$capture['readback_status'],
                'detail_row_count' => (int)$capture['detail_row_count'],
                'captured_at' => (string)$capture['captured_at'],
                'readback_verified_at' => (string)($capture['readback_verified_at'] ?? ''),
                'saved_count' => 1,
                'readback_count' => 1,
            ];
            $receiptFingerprint = hash('sha256', $this->json($receiptFacts));
            $storedReceipt = $evidence['trusted_receipt'] ?? null;
            if (is_array($storedReceipt)) {
                if (!hash_equals(
                    (string)($storedReceipt['receipt_fingerprint'] ?? ''),
                    $receiptFingerprint
                )) {
                    throw new RuntimeException('dingdandao_collection_receipt_conflict');
                }
                return $this->trustedResult($task, $capture, $storedReceipt, 'reused');
            }

            $receipt = $receiptFacts + [
                'receipt_fingerprint' => $receiptFingerprint,
                'generated_at' => $now->format('Y-m-d H:i:s'),
            ];
            $evidence['trusted_receipt'] = $receipt;
            $changes = [
                'task_status' => 'capture_verified',
                'truth_gate_status' => 'waiting_for_operating_target_sync',
                'gap_codes_json' => $this->json(['operating_target_sync_pending']),
                'receipt_evidence_json' => $this->json($evidence),
                'receipt_fingerprint' => $receiptFingerprint,
                'formal_message_allowed' => 0,
                'update_time' => $now->format('Y-m-d H:i:s'),
            ];
            Db::name('cloud_collection_tasks')->where('id', (int)$task['id'])->update($changes);
            $task = array_merge($task, $changes);

            return $this->trustedResult($task, $capture, $receipt, 'recorded');
        });
    }

    private function expireProfileBeforeClaimIfNeeded(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $windowExpiresAt,
        DateTimeImmutable $now
    ): bool {
        return Db::transaction(function () use (
            $profilePublicId,
            $tenantId,
            $hotelId,
            $ownerUserId,
            $windowExpiresAt,
            $now
        ): bool {
            $profile = $this->lockProfile($profilePublicId);
            if ((int)($profile['tenant_id'] ?? 0) !== $tenantId
                || (int)($profile['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($profile['owner_user_id'] ?? 0) !== $ownerUserId
                || strtolower((string)($profile['platform'] ?? '')) !== self::PLATFORM
                || (string)($profile['authorization_status'] ?? '')
                    !== CloudBrowserProfileService::READY_TO_COLLECT
            ) {
                return false;
            }
            $windowTimestamp = strtotime($windowExpiresAt);
            $sessionExpiry = strtotime(trim((string)($profile['session_expires_at'] ?? '')));
            if ($windowTimestamp !== false
                && $sessionExpiry !== false
                && $sessionExpiry > $now->getTimestamp()
                && $sessionExpiry >= $windowTimestamp
            ) {
                return false;
            }
            (new CloudBrowserProfileService())->markSessionExpired(
                $profilePublicId,
                'dingdandao_session_expired'
            );
            return true;
        });
    }

    /** @param array<string,mixed> $task */
    private function reclaimExpiredActiveTask(array $task, DateTimeImmutable $now): bool
    {
        $evidence = $this->decodeJson($task['receipt_evidence_json'] ?? null);
        $claim = $evidence['claim'] ?? null;
        $windowExpiresAt = is_array($claim)
            ? trim((string)($claim['window_expires_at'] ?? ''))
            : '';
        $windowTimestamp = strtotime($windowExpiresAt);
        if ($windowTimestamp === false || $windowTimestamp > $now->getTimestamp()) {
            return false;
        }
        if (is_array($evidence['lifecycle'] ?? null)) {
            return false;
        }
        $closedAt = $now->format('Y-m-d H:i:s');
        $lifecycle = [
            'outcome' => 'window_expired',
            'closed_at' => $closedAt,
            'recovery_reason' => 'orphan_claim_reclaimed',
        ];
        $lifecycle['fingerprint'] = hash('sha256', $this->json([
            'claim_id' => (string)($task['task_public_id'] ?? ''),
            'collection_session_id' => (string)($claim['collection_session_id'] ?? ''),
            'profile_id' => (string)($claim['profile_id'] ?? ''),
            'outcome' => 'window_expired',
            'recovery_reason' => 'orphan_claim_reclaimed',
        ]));
        $evidence['lifecycle'] = $lifecycle;
        Db::name('cloud_collection_tasks')->where('id', (int)$task['id'])->update([
            'task_status' => 'closed_unverified',
            'truth_gate_status' => 'blocked_by_data_gap',
            'gap_codes_json' => $this->json(['collection_window_orphan_expired']),
            'receipt_evidence_json' => $this->json($evidence),
            'formal_message_allowed' => 0,
            'finished_at' => $closedAt,
            'update_time' => $closedAt,
        ]);
        return true;
    }

    /**
     * @return array{
     *   profile:array<string,mixed>,
     *   hotel:array<string,mixed>,
     *   user:array<string,mixed>,
     *   binding:array<string,string>
     * }
     */
    private function validatedScope(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $windowExpiresAt,
        DateTimeImmutable $now
    ): array {
        $this->today($targetDate, $now);
        $windowTimestamp = strtotime($windowExpiresAt);
        if ($windowTimestamp === false || $windowTimestamp <= $now->getTimestamp()) {
            throw new RuntimeException('dingdandao_collection_window_invalid');
        }

        $profile = Db::name('cloud_browser_profiles')
            ->where('profile_public_id', $profilePublicId)
            ->lock(true)
            ->find();
        if (!is_array($profile)
            || (int)($profile['tenant_id'] ?? 0) !== $tenantId
            || (int)($profile['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($profile['owner_user_id'] ?? 0) !== $ownerUserId
            || strtolower((string)($profile['platform'] ?? '')) !== self::PLATFORM
        ) {
            throw new RuntimeException('dingdandao_collection_profile_scope_mismatch');
        }
        if ((string)($profile['authorization_status'] ?? '')
            !== CloudBrowserProfileService::READY_TO_COLLECT
        ) {
            throw new RuntimeException('dingdandao_collection_profile_not_ready');
        }
        $readyAt = strtotime(trim((string)($profile['ready_at'] ?? '')));
        $sessionExpiry = strtotime(trim((string)($profile['session_expires_at'] ?? '')));
        if ($readyAt === false || $readyAt > $now->getTimestamp()) {
            throw new RuntimeException('dingdandao_collection_ready_evidence_missing');
        }
        if ($sessionExpiry === false
            || $sessionExpiry <= $now->getTimestamp()
            || $sessionExpiry < $windowTimestamp
        ) {
            throw new RuntimeException('dingdandao_collection_profile_session_expired');
        }

        $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
            || trim((string)($hotel['name'] ?? '')) === ''
        ) {
            throw new RuntimeException('dingdandao_collection_hotel_scope_invalid');
        }
        $alias = $this->aliasRegistryEntry($tenantId, $hotelId);
        if (!hash_equals(
            (string)$alias['system_name'],
            trim((string)($hotel['name'] ?? ''))
        )) {
            throw new RuntimeException('dingdandao_collection_alias_system_name_mismatch');
        }
        $user = Db::name('users')->where('id', $ownerUserId)->lock(true)->find();
        if (!is_array($user)
            || !$this->userCanOperateTenant($user, $tenantId)
        ) {
            throw new RuntimeException('dingdandao_collection_user_scope_invalid');
        }
        $this->assertCollectionPermission($user, $hotel, $tenantId, $hotelId);

        return [
            'profile' => $profile,
            'hotel' => $hotel,
            'user' => $user,
            'binding' => $this->binding($tenantId, $hotelId, $alias),
        ];
    }

    /**
     * @return array{
     *   profile:array<string,mixed>,
     *   hotel:array<string,mixed>,
     *   user:array<string,mixed>,
     *   alias:array<string,string>
     * }
     */
    private function validatedBindingBootstrapScope(
        string $profilePublicId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        DateTimeImmutable $requiredUntil,
        DateTimeImmutable $now
    ): array {
        if ($requiredUntil->getTimestamp() <= $now->getTimestamp()
            || $requiredUntil->getTimestamp() > $now->getTimestamp() + 600
        ) {
            throw new RuntimeException('dingdandao_binding_scope_invalid');
        }
        $profile = $this->lockProfile($profilePublicId);
        if ((int)($profile['tenant_id'] ?? 0) !== $tenantId
            || (int)($profile['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($profile['owner_user_id'] ?? 0) !== $ownerUserId
            || strtolower((string)($profile['platform'] ?? '')) !== self::PLATFORM
        ) {
            throw new RuntimeException('dingdandao_collection_profile_scope_mismatch');
        }
        if ((string)($profile['authorization_status'] ?? '')
            !== CloudBrowserProfileService::READY_TO_COLLECT
        ) {
            throw new RuntimeException('dingdandao_collection_profile_not_ready');
        }
        $readyAt = strtotime(trim((string)($profile['ready_at'] ?? '')));
        $sessionExpiry = strtotime(trim((string)($profile['session_expires_at'] ?? '')));
        if ($readyAt === false || $readyAt > $now->getTimestamp()) {
            throw new RuntimeException('dingdandao_collection_ready_evidence_missing');
        }
        if ($sessionExpiry === false
            || $sessionExpiry <= $now->getTimestamp()
            || $sessionExpiry < $requiredUntil->getTimestamp()
        ) {
            throw new RuntimeException('dingdandao_collection_profile_session_expired');
        }

        $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
        if (!is_array($hotel)
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['status'] ?? 0) !== 1
            || trim((string)($hotel['name'] ?? '')) === ''
        ) {
            throw new RuntimeException('dingdandao_collection_hotel_scope_invalid');
        }
        $alias = $this->aliasRegistryEntry($tenantId, $hotelId);
        if (!hash_equals(
            (string)$alias['system_name'],
            trim((string)($hotel['name'] ?? ''))
        )) {
            throw new RuntimeException('dingdandao_collection_alias_system_name_mismatch');
        }
        $user = Db::name('users')->where('id', $ownerUserId)->lock(true)->find();
        if (!is_array($user)
            || !$this->userCanOperateTenant($user, $tenantId)
        ) {
            throw new RuntimeException('dingdandao_collection_user_scope_invalid');
        }
        $this->assertCollectionPermission($user, $hotel, $tenantId, $hotelId);

        return [
            'profile' => $profile,
            'hotel' => $hotel,
            'user' => $user,
            'alias' => $alias,
        ];
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $hotel */
    private function assertCollectionPermission(
        array $user,
        array $hotel,
        int $tenantId,
        int $hotelId
    ): void {
        $userId = (int)$user['id'];
        $ownerAllowed = (int)($hotel['owner_user_id'] ?? 0) === $userId
            || (int)($hotel['created_by'] ?? 0) === $userId;
        $superAdminAllowed = (int)($user['role_id'] ?? 0) === Role::SUPER_ADMIN;
        if ($ownerAllowed || $superAdminAllowed) {
            return;
        }

        $fields = $this->tableFields('user_hotel_permissions');
        if ($fields === [] || !in_array('user_id', $fields, true) || !in_array('hotel_id', $fields, true)) {
            throw new RuntimeException('dingdandao_collection_permission_denied');
        }
        $permission = Db::name('user_hotel_permissions')
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->lock(true)
            ->find();
        if (!is_array($permission)
            || (in_array('tenant_id', $fields, true)
                && (int)($permission['tenant_id'] ?? 0) !== $tenantId)
            || (in_array('status', $fields, true)
                && strtolower((string)($permission['status'] ?? '')) !== 'active')
        ) {
            throw new RuntimeException('dingdandao_collection_permission_denied');
        }
        if (in_array('expires_at', $fields, true)) {
            $expiry = trim((string)($permission['expires_at'] ?? ''));
            if ($expiry !== '' && (strtotime($expiry) ?: 0) <= $this->now()->getTimestamp()) {
                throw new RuntimeException('dingdandao_collection_permission_denied');
            }
        }
        $capabilityColumns = array_values(array_intersect(
            ['can_fetch_ota', 'can_fetch_online_data'],
            $fields
        ));
        foreach ($capabilityColumns as $column) {
            if ((int)($permission[$column] ?? 0) === 1) {
                return;
            }
        }
        throw new RuntimeException('dingdandao_collection_permission_denied');
    }

    /** @param array<string,mixed> $user */
    private function userCanOperateTenant(array $user, int $tenantId): bool
    {
        if ((int)($user['status'] ?? 0) !== 1) {
            return false;
        }
        if ((int)($user['role_id'] ?? 0) === Role::SUPER_ADMIN) {
            return true;
        }
        return (int)($user['tenant_id'] ?? 0) === $tenantId;
    }

    /** @return array<string,string> */
    private function aliasRegistryEntry(int $tenantId, int $hotelId): array
    {
        $registry = Config::get(self::ALIAS_REGISTRY_CONFIG, []);
        if (!is_array($registry)
            || trim((string)($registry['schema_version'] ?? '')) !== self::ALIAS_REGISTRY_SCHEMA
            || trim((string)($registry['version'] ?? '')) === ''
            || !is_array($registry['aliases'] ?? null)
        ) {
            throw new RuntimeException('dingdandao_collection_alias_registry_invalid');
        }

        $matches = [];
        foreach ($registry['aliases'] as $row) {
            if (!is_array($row)
                || (int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($row['provider'] ?? ''))) !== self::PLATFORM
            ) {
                continue;
            }
            $systemName = trim((string)($row['system_name'] ?? ''));
            $providerName = trim((string)($row['provider_name'] ?? ''));
            $confirmedDate = trim((string)($row['confirmed_date'] ?? ''));
            $parsedConfirmedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $confirmedDate);
            if ($systemName === ''
                || $providerName === ''
                || mb_strlen($systemName) > 160
                || mb_strlen($providerName) > 160
                || strtolower(trim((string)($row['status'] ?? ''))) !== 'user_confirmed'
                || $confirmedDate === ''
                || !$parsedConfirmedDate instanceof DateTimeImmutable
                || $parsedConfirmedDate->format('Y-m-d') !== $confirmedDate
                || trim((string)($row['source_reference'] ?? ''))
                    !== 'user_explicit_confirmation'
            ) {
                throw new RuntimeException('dingdandao_collection_alias_registry_invalid');
            }
            $normalized = [
                'tenant_id' => (string)$tenantId,
                'hotel_id' => (string)$hotelId,
                'system_name' => $systemName,
                'provider' => self::PLATFORM,
                'provider_name' => $providerName,
                'status' => 'user_confirmed',
                'confirmed_date' => $confirmedDate,
                'source_reference' => 'user_explicit_confirmation',
                'registry_version' => trim((string)$registry['version']),
            ];
            $normalized['alias_fingerprint'] = hash('sha256', $this->json($normalized));
            $matches[] = $normalized;
        }
        if (count($matches) !== 1) {
            throw new RuntimeException(
                $matches === []
                    ? 'dingdandao_collection_alias_missing'
                    : 'dingdandao_collection_alias_ambiguous'
            );
        }
        return $matches[0];
    }

    /**
     * @param array<string,string> $alias
     * @return array<string,string>
     */
    private function binding(int $tenantId, int $hotelId, array $alias): array
    {
        if ($this->tableFields('system_configs') === []) {
            throw new RuntimeException('dingdandao_collection_binding_missing');
        }
        $raw = Db::name('system_configs')
            ->where('config_key', self::BINDING_CONFIG_KEY)
            ->lock(true)
            ->value('config_value');
        $decoded = $this->decodeJson($raw);
        $version = trim((string)($decoded['version'] ?? '1'));
        $rows = is_array($decoded['bindings'] ?? null)
            ? $decoded['bindings']
            : (array_is_list($decoded) ? $decoded : []);
        $targetBindings = [];
        $providerOwners = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || strtolower(trim((string)($row['status'] ?? ''))) !== 'verified'
                || (int)($row['tenant_id'] ?? 0) !== $tenantId
            ) {
                continue;
            }
            $providerHotelId = trim((string)($row['provider_hotel_id'] ?? ''));
            $providerHotelName = trim((string)($row['provider_hotel_name'] ?? ''));
            $rowHotelId = (int)($row['hotel_id'] ?? 0);
            if ($providerHotelId === ''
                || $providerHotelName === ''
                || $rowHotelId <= 0
                || strlen($providerHotelId) > 120
                || mb_strlen($providerHotelName) > 160
            ) {
                continue;
            }
            $providerOwners[$providerHotelId][$rowHotelId] = true;
            if ($rowHotelId !== $hotelId) {
                continue;
            }
            $normalized = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'status' => 'verified',
                'version' => trim((string)($row['version'] ?? $version)) ?: '1',
                'system_hotel_name' => (string)$alias['system_name'],
                'alias_registry_version' => (string)$alias['registry_version'],
                'alias_fingerprint' => (string)$alias['alias_fingerprint'],
            ];
            $normalized['binding_id'] = trim((string)($row['binding_id'] ?? ''))
                ?: 'ddb_' . substr(hash('sha256', $this->json($normalized)), 0, 24);
            $targetBindings[] = $normalized;
        }
        if (count($targetBindings) !== 1) {
            throw new RuntimeException(
                $targetBindings === []
                    ? 'dingdandao_collection_binding_missing'
                    : 'dingdandao_collection_binding_ambiguous'
            );
        }
        $binding = $targetBindings[0];
        if (!hash_equals(
            (string)$alias['provider_name'],
            (string)$binding['provider_hotel_name']
        )) {
            throw new RuntimeException('dingdandao_collection_binding_alias_mismatch');
        }
        if (count($providerOwners[$binding['provider_hotel_id']] ?? []) !== 1) {
            throw new RuntimeException('dingdandao_collection_binding_ambiguous');
        }
        $binding['binding_fingerprint'] = hash('sha256', $this->json($binding));
        return array_map('strval', $binding);
    }

    /**
     * @param array<string,string> $alias
     * @return array<string,mixed>
     */
    private function persistVerifiedBinding(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $profilePublicId,
        string $providerHotelId,
        string $providerHotelName,
        string $capturedAt,
        array $alias,
        DateTimeImmutable $now
    ): array {
        $fields = $this->tableFields('system_configs');
        if (!in_array('config_key', $fields, true)
            || !in_array('config_value', $fields, true)
        ) {
            throw new RuntimeException('dingdandao_collection_binding_storage_unavailable');
        }
        $configRow = Db::name('system_configs')
            ->where('config_key', self::BINDING_CONFIG_KEY)
            ->lock(true)
            ->find();
        $raw = is_array($configRow)
            ? trim((string)($configRow['config_value'] ?? ''))
            : '';
        $version = $now->format('Y-m-d');
        $rows = [];
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new RuntimeException('dingdandao_collection_binding_config_invalid');
            }
            if (!is_array($decoded)) {
                throw new RuntimeException('dingdandao_collection_binding_config_invalid');
            }
            if (array_key_exists('bindings', $decoded)) {
                if (!is_array($decoded['bindings']) || !array_is_list($decoded['bindings'])) {
                    throw new RuntimeException('dingdandao_collection_binding_config_invalid');
                }
                $version = trim((string)($decoded['version'] ?? ''));
                if ($version === '' || strlen($version) > 80) {
                    throw new RuntimeException('dingdandao_collection_binding_config_invalid');
                }
                $rows = $decoded['bindings'];
            } elseif (array_is_list($decoded)) {
                $version = '1';
                $rows = $decoded;
            } else {
                throw new RuntimeException('dingdandao_collection_binding_config_invalid');
            }
        }

        $targetIndexes = [];
        $providerOwners = [];
        $bindingIds = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('dingdandao_collection_binding_config_invalid');
            }
            $rowTenantId = (int)($row['tenant_id'] ?? 0);
            $rowHotelId = (int)($row['hotel_id'] ?? 0);
            $rowProviderId = trim((string)($row['provider_hotel_id'] ?? ''));
            $rowProviderName = trim((string)($row['provider_hotel_name'] ?? ''));
            $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
            $rowBindingId = trim((string)($row['binding_id'] ?? ''));
            if ($rowTenantId <= 0
                || $rowHotelId <= 0
                || $rowProviderId === ''
                || strlen($rowProviderId) > 120
                || preg_match('/^[A-Za-z0-9_-]+$/D', $rowProviderId) !== 1
                || $rowProviderName === ''
                || mb_strlen($rowProviderName) > 160
                || $rowStatus !== 'verified'
                || ($rowBindingId !== ''
                    && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $rowBindingId) !== 1)
            ) {
                throw new RuntimeException('dingdandao_collection_binding_config_invalid');
            }
            if ($rowBindingId !== '') {
                if (isset($bindingIds[$rowBindingId])) {
                    throw new RuntimeException('dingdandao_collection_binding_config_invalid');
                }
                $bindingIds[$rowBindingId] = true;
            }
            $ownerKey = $rowTenantId . ':' . $rowHotelId;
            $providerOwners[$rowProviderId][$ownerKey] = true;
            if ($rowTenantId === $tenantId) {
                if ($rowHotelId === $hotelId) {
                    $targetIndexes[] = $index;
                }
            }
        }
        $expectedOwnerKey = $tenantId . ':' . $hotelId;
        if (count($targetIndexes) > 1
            || count($providerOwners[$providerHotelId] ?? []) > 1
            || (count($providerOwners[$providerHotelId] ?? []) === 1
                && !isset($providerOwners[$providerHotelId][$expectedOwnerKey]))
        ) {
            throw new RuntimeException('dingdandao_collection_binding_conflict');
        }

        $status = 'bound';
        $changed = true;
        if ($targetIndexes !== []) {
            $existing = $rows[$targetIndexes[0]];
            if (!hash_equals(
                trim((string)$existing['provider_hotel_id']),
                $providerHotelId
            ) || !hash_equals(
                trim((string)$existing['provider_hotel_name']),
                $providerHotelName
            )) {
                throw new RuntimeException('dingdandao_collection_binding_conflict');
            }
            $status = 'reused';
            $changed = false;
        } else {
            $bindingSeed = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'status' => 'verified',
                'version' => $version,
            ];
            $rows[] = $bindingSeed + [
                'binding_id' => 'ddb_' . substr(
                    hash('sha256', $this->json($bindingSeed)),
                    0,
                    24
                ),
                'system_hotel_name' => (string)$alias['system_name'],
                'source_reference' => 'verified_live_identity_probe',
                'verified_at' => $capturedAt,
                'verified_by_user_id' => $ownerUserId,
                'profile_fingerprint' => hash('sha256', $profilePublicId),
                'alias_registry_version' => (string)$alias['registry_version'],
                'alias_fingerprint' => (string)$alias['alias_fingerprint'],
            ];
        }

        if ($changed) {
            $payload = [
                'config_value' => $this->json([
                    'version' => $version,
                    'bindings' => $rows,
                ]),
            ];
            if (in_array('description', $fields, true)) {
                $payload['description'] = '订单来了门店身份绑定（不含登录态或凭证）';
            }
            if (in_array('update_time', $fields, true)) {
                $payload['update_time'] = $now->format('Y-m-d H:i:s');
            }
            if (is_array($configRow)) {
                Db::name('system_configs')
                    ->where('config_key', self::BINDING_CONFIG_KEY)
                    ->update($payload);
            } else {
                $payload['config_key'] = self::BINDING_CONFIG_KEY;
                if (in_array('create_time', $fields, true)) {
                    $payload['create_time'] = $now->format('Y-m-d H:i:s');
                }
                Db::name('system_configs')->insert($payload);
            }
        }

        $readback = $this->binding($tenantId, $hotelId, $alias);
        if (!hash_equals(
            (string)$readback['provider_hotel_id'],
            $providerHotelId
        ) || !hash_equals(
            (string)$readback['provider_hotel_name'],
            $providerHotelName
        )) {
            throw new RuntimeException('dingdandao_collection_binding_readback_failed');
        }
        $audit = OperationLog::record(
            'operating_target',
            'bootstrap_dingdandao_binding',
            $status === 'bound'
                ? '创建订单来了门店身份绑定'
                : '复用已验证的订单来了门店身份绑定',
            $ownerUserId,
            $hotelId,
            null,
            [
                'outcome' => 'success',
                'provider' => self::PLATFORM,
                'binding_status' => $status,
                'binding_id' => (string)$readback['binding_id'],
                'provider_hotel_name' => $providerHotelName,
                'provider_hotel_id_fingerprint' => hash(
                    'sha256',
                    self::PLATFORM . ':' . $tenantId . ':' . $hotelId . ':' . $providerHotelId
                ),
                'alias_registry_version' => (string)$alias['registry_version'],
                'alias_fingerprint' => (string)$alias['alias_fingerprint'],
                'source_api_path' => '/v2/ntw/web/ntw/get',
                'capture_method' => 'existing_session_direct_post',
            ]
        );
        return [
            'status' => $status,
            'binding_id' => (string)$readback['binding_id'],
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'provider' => self::PLATFORM,
            'provider_hotel_name' => $providerHotelName,
            'provider_hotel_id_fingerprint' => hash(
                'sha256',
                self::PLATFORM . ':' . $tenantId . ':' . $hotelId . ':' . $providerHotelId
            ),
            'alias_registry_version' => (string)$alias['registry_version'],
            'alias_fingerprint' => (string)$alias['alias_fingerprint'],
            'readback_status' => 'readback_verified',
            'audit_id' => (int)($audit->id ?? 0),
            'binding_persisted' => true,
            'business_data_persisted' => false,
            'message_sent' => false,
        ];
    }

    /** @param array<string,mixed> $task @param array<string,string> $binding */
    private function assertVerifiedCapture(array $task, array $binding, array $capture): void
    {
        if ((int)($capture['tenant_id'] ?? 0) !== (int)$task['tenant_id']
            || (int)($capture['hotel_id'] ?? 0) !== (int)$task['system_hotel_id']
            || (string)($capture['business_date'] ?? '') !== (string)$task['target_date']
            || (string)($capture['provider'] ?? '') !== DingdandaoOperatingTargetCaptureService::PROVIDER
            || !hash_equals(
                (string)$binding['provider_hotel_id'],
                (string)($capture['provider_hotel_id'] ?? '')
            )
            || !hash_equals(
                (string)$binding['provider_hotel_name'],
                (string)($capture['provider_hotel_name'] ?? '')
            )
            || (string)($capture['source_scope'] ?? '')
                !== DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE
            || (string)($capture['capture_status'] ?? '') !== 'verified'
            || (string)($capture['quality_status'] ?? '') !== 'verified'
            || (string)($capture['identity_status'] ?? '') !== 'matched'
            || (string)($capture['reconciliation_status'] ?? '') !== 'matched'
            || (string)($capture['readback_status'] ?? '') !== 'readback_verified'
            || (int)($capture['detail_row_count'] ?? 0) <= 0
            || count((array)($capture['room_fee_details'] ?? []))
                !== (int)($capture['detail_row_count'] ?? 0)
            || preg_match('/^[a-f0-9]{64}$/D', (string)($capture['source_fingerprint'] ?? '')) !== 1
        ) {
            throw new RuntimeException('dingdandao_collection_readback_not_verified');
        }
    }

    /** @return array<string,mixed> */
    private function taskByClaim(string $claimId): array
    {
        $task = Db::name('cloud_collection_tasks')
            ->where('task_public_id', $claimId)
            ->lock(true)
            ->find();
        if (!is_array($task)
            || strtolower((string)($task['platform'] ?? '')) !== self::PLATFORM
            || (string)($task['collection_mode'] ?? '') !== self::COLLECTION_KIND
        ) {
            throw new RuntimeException('dingdandao_collection_claim_not_found');
        }
        return $task;
    }

    /** @return array<string,mixed> */
    private function lockProfile(string $profilePublicId): array
    {
        $profile = Db::name('cloud_browser_profiles')
            ->where('profile_public_id', $profilePublicId)
            ->lock(true)
            ->find();
        if (!is_array($profile)) {
            throw new RuntimeException('dingdandao_collection_profile_scope_mismatch');
        }
        return $profile;
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function assertClaimIdentity(
        array $task,
        mixed $claim,
        string $collectionSessionId,
        string $profilePublicId
    ): array {
        if (!is_array($claim)
            || (string)($claim['collection_session_id'] ?? '') !== $collectionSessionId
            || (string)($claim['profile_id'] ?? '') !== $profilePublicId
            || (string)$task['profile_public_id'] !== $profilePublicId
            || (int)$task['tenant_id'] !== (int)($claim['tenant_id'] ?? 0)
            || (int)$task['system_hotel_id'] !== (int)($claim['hotel_id'] ?? 0)
            || (int)$task['owner_user_id'] !== (int)($claim['owner_user_id'] ?? 0)
            || (string)$task['target_date'] !== (string)($claim['target_date'] ?? '')
            || (string)($claim['collection_kind'] ?? '') !== self::COLLECTION_KIND
            || (string)($claim['access_mode'] ?? '') !== self::ACCESS_MODE
        ) {
            throw new RuntimeException('dingdandao_collection_claim_scope_mismatch');
        }
        return $claim;
    }

    /** @param array<string,string> $binding @return array<string,mixed> */
    private function claimEvidence(
        string $profilePublicId,
        string $collectionSessionId,
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $targetDate,
        string $collectionKind,
        string $accessMode,
        string $windowExpiresAt,
        array $binding
    ): array {
        return [
            'profile_id' => $profilePublicId,
            'collection_session_id' => $collectionSessionId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'owner_user_id' => $ownerUserId,
            'platform' => self::PLATFORM,
            'target_date' => $targetDate,
            'collection_kind' => $collectionKind,
            'access_mode' => $accessMode,
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'window_expires_at' => $windowExpiresAt,
            'binding_id' => (string)$binding['binding_id'],
            'binding_fingerprint' => (string)$binding['binding_fingerprint'],
            'alias_registry_version' => (string)$binding['alias_registry_version'],
            'alias_fingerprint' => (string)$binding['alias_fingerprint'],
            'system_hotel_name' => (string)$binding['system_hotel_name'],
            'provider_hotel_name' => (string)$binding['provider_hotel_name'],
        ];
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $claim */
    private function claimResult(array $task, array $claim, string $claimStatus): array
    {
        return [
            'claimed' => true,
            'claim_status' => $claimStatus,
            'claim_id' => (string)$task['task_public_id'],
            'collection_session_id' => (string)$claim['collection_session_id'],
            'profile_id' => (string)$claim['profile_id'],
            'tenant_id' => (int)$claim['tenant_id'],
            'hotel_id' => (int)$claim['hotel_id'],
            'owner_user_id' => (int)$claim['owner_user_id'],
            'platform' => self::PLATFORM,
            'target_date' => (string)$claim['target_date'],
            'collection_kind' => self::COLLECTION_KIND,
            'access_mode' => self::ACCESS_MODE,
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'window_expires_at' => (string)$claim['window_expires_at'],
            'provider_hotel_name' => (string)$claim['provider_hotel_name'],
            'lifecycle_status' => 'open',
            'data_status' => 'unverified',
        ];
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $claim */
    private function lifecycleResult(
        array $task,
        array $claim,
        string $outcome,
        string $completionStatus
    ): array {
        return [
            'completed' => true,
            'completion_status' => $completionStatus,
            'claim_id' => (string)$task['task_public_id'],
            'collection_session_id' => (string)$claim['collection_session_id'],
            'profile_id' => (string)$claim['profile_id'],
            'outcome' => $outcome,
            'lifecycle_status' => 'closed',
            'data_status' => 'unverified',
        ];
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $capture */
    private function trustedResult(
        array $task,
        array $capture,
        array $receipt,
        string $receiptStatus
    ): array {
        return [
            'completed' => true,
            'receipt_status' => $receiptStatus,
            'claim_id' => (string)$task['task_public_id'],
            'data_status' => 'verified',
            'truth_gate_status' => (string)($task['truth_gate_status'] ?? 'blocked_by_data_gap'),
            'formal_message_allowed' => (int)($task['formal_message_allowed'] ?? 0) === 1,
            'receipt' => $receipt,
            'capture' => $capture,
        ];
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'));
    }

    private function today(string $value, DateTimeImmutable $now): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || $value !== $now->format('Y-m-d')
        ) {
            throw new RuntimeException('dingdandao_collection_target_date_invalid');
        }
        return $value;
    }

    private function windowExpiry(string $value, DateTimeImmutable $now): string
    {
        $value = trim($value);
        $timestamp = strtotime($value);
        if ($value === ''
            || $timestamp === false
            || $timestamp <= $now->getTimestamp()
            || $timestamp > $now->getTimestamp() + self::MAX_WINDOW_SECONDS
        ) {
            throw new RuntimeException('dingdandao_collection_window_invalid');
        }
        return $value;
    }

    private function opaqueId(string $value, string $prefix, string $error): string
    {
        $value = trim($value);
        if (preg_match('/^' . preg_quote($prefix, '/') . '[A-Za-z0-9_-]{16,64}$/D', $value) !== 1) {
            throw new RuntimeException($error);
        }
        return $value;
    }

    private function assertNoSensitiveMaterial(mixed $value, int $depth = 0): void
    {
        if ($depth > 12) {
            throw new RuntimeException('dingdandao_capture_structure_invalid');
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key)
                && preg_match(
                    '/(?:cookie|token|authorization|password|secret|headers?|raw_response|session_material)/i',
                    $key
                ) === 1
            ) {
                throw new RuntimeException('dingdandao_capture_sensitive_material_rejected');
            }
            $this->assertNoSensitiveMaterial($item, $depth + 1);
        }
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    /** @return array<int,string> */
    private function tableFields(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
            return is_array($fields) ? array_values(array_map('strval', $fields)) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function same(mixed $left, mixed $right): bool
    {
        return $this->json($left) === $this->json($right);
    }

    private function json(mixed $value): string
    {
        return (string)json_encode(
            $this->canonical($value),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
        );
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
