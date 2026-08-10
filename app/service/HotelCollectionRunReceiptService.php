<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Durable, secret-free evidence for one exact hotel collection-plan attempt.
 *
 * The parent owns the aggregate trust state and PMS/page sidecars. Ctrip and
 * Meituan each own one immutable source slot. A failure receipt is deliberately
 * separate from the collection anchor and can never make a run successful.
 */
final class HotelCollectionRunReceiptService
{
    public const RUN_TABLE = 'hotel_collection_plan_runs';
    public const SOURCE_TABLE = 'hotel_collection_plan_run_sources';
    public const SCHEMA_VERSION = 2;

    /** @var array<int,string> */
    private const PLATFORMS = ['ctrip', 'meituan'];

    /** @var array<int,string> */
    private const ACTIVE_SOURCE_STATUSES = [
        'authorized',
        'declared',
        'queued',
        'leased',
        'running',
        'retry_wait',
        'waiting_user_login',
        'verification_required',
        'in_progress',
    ];

    /**
     * Persist a parent plus both OTA source slots before an application gate
     * can return. The same dispatcher UUID is idempotent only for the exact
     * same hotel/date/plan/source scope.
     *
     * @param array<string,mixed> $gate
     * @return array<string,mixed>
     */
    public function begin(array $gate): array
    {
        $this->assertTablesReady();
        $dispatcherRunId = $this->uuid((string)($gate['dispatcher_run_id'] ?? ''));
        $hotelId = max(0, (int)($gate['system_hotel_id'] ?? 0));
        $businessDate = $this->date((string)($gate['business_date'] ?? ''));
        $runMode = $this->code((string)($gate['run_mode'] ?? ''));
        if ($dispatcherRunId === '' || $hotelId <= 0 || $businessDate === ''
            || !in_array($runMode, ['daily', 'realtime'], true)
        ) {
            throw new RuntimeException('hotel_collection_run_receipt_scope_invalid');
        }

        $tenantId = max(0, (int)($gate['tenant_id'] ?? 0));
        if ($tenantId <= 0) {
            $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        }
        if ($tenantId <= 0) {
            throw new RuntimeException('hotel_collection_run_receipt_tenant_missing');
        }

        $planId = max(0, (int)($gate['plan_id'] ?? 0));
        $planVersion = max(0, (int)($gate['plan_version'] ?? 0));
        $planHash = $this->digest((string)($gate['plan_hash'] ?? ''));
        $scopeHash = $this->digest((string)($gate['scope_hash'] ?? ''));
        if ($scopeHash === '') {
            $scopeHash = hash('sha256', $this->json([
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'run_mode' => $runMode,
                'plan_id' => $planId,
                'plan_version' => $planVersion,
            ]));
        }

        $sources = is_array($gate['sources'] ?? null) ? $gate['sources'] : [];
        $sourceSnapshots = [];
        foreach (self::PLATFORMS as $platform) {
            $source = is_array($sources[$platform] ?? null) ? $sources[$platform] : [];
            $declaredPlatform = $this->code((string)($source['platform'] ?? $platform));
            $sourceSnapshots[$platform] = [
                'platform' => $platform,
                'data_source_id' => $declaredPlatform === $platform
                    ? max(0, (int)($source['data_source_id'] ?? 0))
                    : 0,
                'ingestion_method' => $this->code((string)($source['ingestion_method'] ?? '')),
            ];
        }
        $expectedSourceIds = $this->positiveIds($gate['expected_source_ids'] ?? []);
        $snapshotSourceIds = $this->positiveIds([
            $sourceSnapshots['ctrip']['data_source_id'],
            $sourceSnapshots['meituan']['data_source_id'],
        ]);
        $sourceScopeReady = count($snapshotSourceIds) === 2
            && count(array_unique($snapshotSourceIds)) === 2
            && ($expectedSourceIds === [] || $expectedSourceIds === $snapshotSourceIds)
            && $sourceSnapshots['ctrip']['ingestion_method'] !== ''
            && $sourceSnapshots['meituan']['ingestion_method'] !== '';

        $issues = $this->issues($gate['failure_reasons'] ?? []);
        $allowed = ($gate['collection_allowed'] ?? false) === true && $sourceScopeReady;
        if (($gate['collection_allowed'] ?? false) === true && !$sourceScopeReady) {
            $issues[] = ['code' => 'hotel_collection_execution_source_scope_mismatch', 'platform' => ''];
        }
        $issues = $this->uniqueIssues($issues);
        $executionOwnerUserId = max(0, (int)($gate['execution_owner_user_id'] ?? 0));
        if ($allowed && $executionOwnerUserId <= 0) {
            $allowed = false;
            $issues[] = ['code' => 'hotel_collection_execution_owner_missing', 'platform' => ''];
        }
        $pms = is_array($sources['pms'] ?? null) ? $sources['pms'] : [];
        $pmsProvider = $this->code((string)($pms['provider'] ?? ''));
        $failureCode = $allowed ? '' : $this->firstIssueCode($issues, 'collection_plan_blocked');
        $now = date('Y-m-d H:i:s');

        Db::transaction(function () use (
            $dispatcherRunId,
            $tenantId,
            $hotelId,
            $businessDate,
            $runMode,
            $planId,
            $planVersion,
            $planHash,
            $scopeHash,
            $executionOwnerUserId,
            $pmsProvider,
            $sourceSnapshots,
            $issues,
            $allowed,
            $failureCode,
            $now
        ): void {
            $runValues = [
                'dispatcher_run_id' => $dispatcherRunId,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'run_mode' => $runMode,
                'trigger_type' => 'scheduler',
                'plan_id' => $planId > 0 ? $planId : null,
                'plan_version' => $planVersion,
                'plan_hash' => $planHash,
                'scope_hash' => $scopeHash,
                'execution_owner_user_id' => $executionOwnerUserId > 0
                    ? $executionOwnerUserId
                    : null,
                'status' => $allowed ? 'started' : 'blocked',
                'failure_stage' => $allowed ? '' : 'plan_gate',
                'failure_code' => $failureCode,
                'collection_anchor_contract_version' => null,
                'collection_anchor_hash' => null,
                'trust_receipt_digest' => null,
                'page_status' => 'not_evaluated',
                'page_receipt_id' => null,
                'page_contract_hash' => null,
                'pms_status' => 'not_run',
                'pms_provider' => $pmsProvider !== '' ? $pmsProvider : null,
                'pms_capture_id' => null,
                'pms_readback_verified' => null,
                'receipt_json' => $this->json([
                    'schema_version' => self::SCHEMA_VERSION,
                    'scope_hash' => $scopeHash,
                    'failure_codes' => array_values(array_unique(array_column($issues, 'code'))),
                    'resume_scope' => 'same_account_same_device_same_hotel_same_platform',
                    'automatic_device_substitution' => false,
                    'sensitive_values_exposed' => false,
                ]),
                'started_at' => $now,
                'finished_at' => $allowed ? null : $now,
                'create_time' => $now,
                'update_time' => $now,
            ];

            $existing = Db::name(self::RUN_TABLE)
                ->where('dispatcher_run_id', $dispatcherRunId)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                $this->assertSameRunScope($existing, $runValues);
                $runId = (int)($existing['id'] ?? 0);
            } else {
                $runId = (int)Db::name(self::RUN_TABLE)->insertGetId($runValues);
                if ($runId <= 0) {
                    throw new RuntimeException('hotel_collection_run_receipt_write_failed');
                }
            }

            foreach (self::PLATFORMS as $platform) {
                $source = $sourceSnapshots[$platform];
                $platformIssue = $this->issueForPlatform($issues, $platform);
                $childFailureCode = $allowed
                    ? ''
                    : (string)($platformIssue['code'] ?? $failureCode);
                $childValues = [
                    'run_id' => $runId,
                    'platform' => $platform,
                    'data_source_id' => (int)$source['data_source_id'] > 0
                        ? (int)$source['data_source_id']
                        : null,
                    'ingestion_method' => (string)$source['ingestion_method'],
                    'status' => $allowed ? 'declared' : 'blocked',
                    'platform_sync_task_id' => null,
                    'local_collector_task_id' => null,
                    'saved_row_count' => 0,
                    'readback_row_count' => 0,
                    'readback_verified' => 0,
                    'evidence_digest' => null,
                    'failure_stage' => $allowed ? '' : 'plan_gate',
                    'failure_code' => $childFailureCode,
                    'page_acceptance_status' => 'not_evaluated',
                    'page_acceptance_log_id' => null,
                    'receipt_json' => $this->json([
                        'schema_version' => self::SCHEMA_VERSION,
                        'platform' => $platform,
                        'failure_code' => $childFailureCode,
                        'automatic_device_substitution' => false,
                        'sensitive_values_exposed' => false,
                    ]),
                    'started_at' => $now,
                    'finished_at' => $allowed ? null : $now,
                    'create_time' => $now,
                    'update_time' => $now,
                ];
                $this->insertOrAssertSameSourceScope($childValues);
            }
        });

        return $this->readGroup($dispatcherRunId, $tenantId, $hotelId, $businessDate);
    }

    /**
     * Link exact task/save/readback results to the two declared OTA sources.
     * This stage never writes a collection anchor.
     *
     * @param array<int,mixed> $platformResults
     * @return array<string,mixed>
     */
    public function recordPlatformResults(
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate,
        array $platformResults
    ): array {
        $this->assertTablesReady();
        $dispatcherRunId = $this->uuid($dispatcherRunId);
        $businessDate = $this->date($businessDate);
        if ($dispatcherRunId === '' || $hotelId <= 0 || $businessDate === '') {
            throw new RuntimeException('hotel_collection_run_result_scope_invalid');
        }
        $context = Db::transaction(function () use (
            $dispatcherRunId,
            $hotelId,
            $businessDate,
            $platformResults
        ): array {
            [$run, $children] = $this->loadExactRun(
                $dispatcherRunId,
                $hotelId,
                $businessDate,
                true
            );
            if (in_array((string)($run['status'] ?? ''), ['blocked', 'succeeded'], true)) {
                throw new RuntimeException('hotel_collection_run_not_collectable');
            }
            $runId = (int)$run['id'];
            $seenPlatforms = [];
            foreach ($platformResults as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $platform = $this->code((string)($result['platform'] ?? ''));
                if (!in_array($platform, self::PLATFORMS, true)) {
                    continue;
                }
                if (isset($seenPlatforms[$platform])) {
                    throw new RuntimeException('hotel_collection_run_result_platform_duplicated');
                }
                $seenPlatforms[$platform] = true;
                $stored = is_array($children[$platform] ?? null) ? $children[$platform] : null;
                if (!is_array($stored)) {
                    throw new RuntimeException('hotel_collection_run_platform_receipt_missing');
                }
                $readback = is_array($result['run_readback'] ?? null)
                    ? $result['run_readback']
                    : [];
                $resultRunId = $this->uuid((string)(
                    $readback['dispatcher_run_id']
                    ?? $result['dispatcher_run_id']
                    ?? ''
                ));
                $resultHotelId = (int)(
                    $readback['system_hotel_id']
                    ?? $result['system_hotel_id']
                    ?? 0
                );
                $resultDate = $this->date((string)(
                    $readback['target_date']
                    ?? $result['target_date']
                    ?? ''
                ));
                $sourceId = max(0, (int)(
                    $readback['data_source_id']
                    ?? $result['data_source_id']
                    ?? 0
                ));
                if ($resultRunId !== $dispatcherRunId
                    || $resultHotelId !== $hotelId
                    || $resultDate !== $businessDate
                    || $sourceId <= 0
                    || $sourceId !== (int)($stored['data_source_id'] ?? 0)
                ) {
                    throw new RuntimeException('hotel_collection_run_result_scope_mismatch');
                }

                $ingestionMethod = $this->code((string)($stored['ingestion_method'] ?? ''));
                $platformSyncTaskId = max(0, (int)($readback['sync_task_id'] ?? 0));
                $localCollectorTaskId = max(0, (int)(
                    $result['local_collector_task_id']
                    ?? $result['collector_task_id']
                    ?? 0
                ));
                if ($ingestionMethod === 'local_collector') {
                    if ($localCollectorTaskId <= 0) {
                        $localCollectorTaskId = max(0, (int)($result['task_id'] ?? 0));
                    }
                } else {
                    if ($localCollectorTaskId > 0) {
                        throw new RuntimeException('hotel_collection_run_local_task_method_mismatch');
                    }
                    if ($platformSyncTaskId <= 0) {
                        $platformSyncTaskId = max(0, (int)($result['task_id'] ?? 0));
                    }
                }

                $rowIds = $this->positiveIds($readback['row_ids'] ?? []);
                $savedCount = max(0, (int)($result['saved_count'] ?? 0));
                $readbackCount = max(0, (int)($result['readback_count'] ?? count($rowIds)));
                $readbackVerified = ($readback['readback_verified'] ?? false) === true
                    && ($result['readback_verified'] ?? false) === true;
                $persistenceVerified = $readbackVerified
                    && $this->persistedSourceEvidenceMatches(
                        $run,
                        $stored,
                        $platformSyncTaskId,
                        $localCollectorTaskId,
                        $rowIds
                    );
                $strictSuccess = ($result['success'] ?? false) === true
                    && $persistenceVerified
                    && $platformSyncTaskId > 0
                    && $rowIds !== []
                    && $readbackCount > 0;
                $rawStatus = $this->code((string)(
                    $result['status']
                    ?? $result['source_task_status']
                    ?? ''
                ));
                if ($strictSuccess) {
                    $status = 'success';
                } elseif (in_array($rawStatus, self::ACTIVE_SOURCE_STATUSES, true)
                    || ($ingestionMethod === 'local_collector'
                        && $localCollectorTaskId > 0
                        && $platformSyncTaskId <= 0)
                ) {
                    $status = 'in_progress';
                } elseif ($savedCount > 0 || $readbackCount > 0) {
                    $status = 'partial';
                } else {
                    $status = 'failed';
                }
                $failureCode = $strictSuccess ? '' : $this->code((string)(
                    $result['failure_reason']
                    ?? $result['message']
                    ?? ''
                ));
                if (($result['success'] ?? false) === true && !$persistenceVerified) {
                    $failureCode = 'collection_persistence_evidence_mismatch';
                }
                if (!$strictSuccess && $failureCode === '') {
                    $failureCode = $status === 'in_progress'
                        ? 'collection_in_progress'
                        : 'collection_not_verified';
                }
                $evidenceDigest = $rowIds === [] || $platformSyncTaskId <= 0
                    ? null
                    : $this->sourceEvidenceDigest(
                        $platform,
                        $sourceId,
                        $platformSyncTaskId,
                        $rowIds
                    );
                $now = date('Y-m-d H:i:s');
                Db::name(self::SOURCE_TABLE)
                    ->where('id', (int)$stored['id'])
                    ->where('run_id', $runId)
                    ->where('platform', $platform)
                    ->where('data_source_id', $sourceId)
                    ->update([
                        'status' => $status,
                        'platform_sync_task_id' => $platformSyncTaskId > 0
                            ? $platformSyncTaskId
                            : null,
                        'local_collector_task_id' => $localCollectorTaskId > 0
                            ? $localCollectorTaskId
                            : null,
                        'saved_row_count' => $savedCount,
                        'readback_row_count' => $readbackCount,
                        'readback_verified' => $persistenceVerified ? 1 : 0,
                        'evidence_digest' => $evidenceDigest,
                        'failure_stage' => $strictSuccess ? '' : 'collection',
                        'failure_code' => $failureCode,
                        'receipt_json' => $this->json([
                            'schema_version' => self::SCHEMA_VERSION,
                            'dispatcher_run_id' => $dispatcherRunId,
                            'source_task_status' => $rawStatus,
                            'row_count' => count($rowIds),
                            'row_ids_hash' => $rowIds === []
                                ? null
                                : hash('sha256', implode(',', $rowIds)),
                            'readback_verified' => $persistenceVerified,
                            'failure_code' => $failureCode,
                            'automatic_device_substitution' => false,
                            'sensitive_values_exposed' => false,
                        ]),
                        'finished_at' => in_array($status, ['success', 'partial', 'failed'], true)
                            ? $now
                            : null,
                        'update_time' => $now,
                    ]);
                $written = Db::name(self::SOURCE_TABLE)->where('id', (int)$stored['id'])->find();
                if (!is_array($written)
                    || (string)($written['status'] ?? '') !== $status
                    || (int)($written['platform_sync_task_id'] ?? 0) !== $platformSyncTaskId
                    || (int)($written['local_collector_task_id'] ?? 0) !== $localCollectorTaskId
                    || (int)($written['readback_verified'] ?? 0) !== ($persistenceVerified ? 1 : 0)
                ) {
                    throw new RuntimeException('hotel_collection_run_result_readback_mismatch');
                }
            }
            $this->refreshAggregateBeforeFinalization($run);
            $parent = Db::name(self::RUN_TABLE)->where('id', $runId)->find();
            if (!is_array($parent)
                || trim((string)($parent['collection_anchor_hash'] ?? '')) !== ''
            ) {
                throw new RuntimeException('hotel_collection_run_pre_finalization_anchor_present');
            }
            return [
                'tenant_id' => (int)$run['tenant_id'],
                'dispatcher_run_id' => (string)$run['dispatcher_run_id'],
                'business_date' => (string)$run['business_date'],
            ];
        });

        return $this->readGroup(
            (string)$context['dispatcher_run_id'],
            (int)$context['tenant_id'],
            $hotelId,
            (string)$context['business_date']
        );
    }

    /**
     * Promote the exact two-source receipt to a success anchor. An incomplete
     * or untrusted receipt is durably terminal but keeps every anchor field
     * NULL. The optional trust verdict is supplied by the existing authority
     * and canonical-history gate.
     *
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function finalizeCollection(
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate,
        array $receipt,
        bool $trustedReady
    ): array {
        $this->assertTablesReady();
        $dispatcherRunId = $this->uuid($dispatcherRunId);
        $businessDate = $this->date($businessDate);
        if ($dispatcherRunId === '' || $hotelId <= 0 || $businessDate === '') {
            throw new RuntimeException('hotel_collection_run_final_receipt_scope_invalid');
        }
        $context = Db::transaction(function () use (
            $dispatcherRunId,
            $hotelId,
            $businessDate,
            $receipt,
            $trustedReady
        ): array {
            [$run, $children] = $this->loadExactRun(
                $dispatcherRunId,
                $hotelId,
                $businessDate,
                true
            );
            if ($this->uuid((string)($receipt['dispatcher_run_id'] ?? '')) !== $dispatcherRunId
                || (int)($receipt['hotel_id'] ?? 0) !== $hotelId
                || $this->date((string)($receipt['target_date'] ?? '')) !== $businessDate
            ) {
                throw new RuntimeException('hotel_collection_run_final_receipt_scope_mismatch');
            }

            $sourceTasks = is_array($receipt['source_tasks'] ?? null)
                ? array_values($receipt['source_tasks'])
                : [];
            $normalizedTasks = OtaCollectionAnchorService::normalize($sourceTasks);
            $contractVersion = trim((string)($receipt['collection_anchor_contract_version'] ?? ''));
            $anchorHash = $this->digest((string)($receipt['collection_anchor_hash'] ?? ''));
            if ((string)($run['status'] ?? '') === 'succeeded') {
                if (!$trustedReady
                    || $anchorHash === ''
                    || !hash_equals((string)($run['collection_anchor_hash'] ?? ''), $anchorHash)
                ) {
                    throw new RuntimeException('hotel_collection_run_success_is_immutable');
                }
                return [
                    'tenant_id' => (int)$run['tenant_id'],
                    'dispatcher_run_id' => $dispatcherRunId,
                    'business_date' => $businessDate,
                ];
            }

            $expectedSourceIds = $this->positiveIds(array_map(
                static fn(array $child): int => (int)($child['data_source_id'] ?? 0),
                array_values($children)
            ));
            $receiptSourceIds = $this->positiveIds($receipt['source_ids'] ?? []);
            $requiredPlatforms = array_values(array_unique(array_filter(array_map(
                fn(mixed $value): string => $this->code((string)$value),
                is_array($receipt['required_platforms'] ?? null)
                    ? $receipt['required_platforms']
                    : []
            ))));
            sort($requiredPlatforms, SORT_STRING);
            $exactSources = count($sourceTasks) === 2
                && count($normalizedTasks) === 2
                && $expectedSourceIds === $receiptSourceIds
                && $requiredPlatforms === self::PLATFORMS;
            if ($exactSources) {
                $normalizedByPlatform = array_column($normalizedTasks, null, 'platform');
                foreach (self::PLATFORMS as $platform) {
                    $child = $children[$platform] ?? null;
                    $task = $normalizedByPlatform[$platform] ?? null;
                    $rawTask = null;
                    foreach ($sourceTasks as $candidate) {
                        if (is_array($candidate)
                            && $this->code((string)($candidate['platform'] ?? '')) === $platform
                        ) {
                            $rawTask = $candidate;
                            break;
                        }
                    }
                    $expectedTrigger = (string)($child['ingestion_method'] ?? '') === 'local_collector'
                        ? 'local_collector_upload'
                        : 'daily_profile_reuse';
                    if (!is_array($child)
                        || !is_array($task)
                        || !is_array($rawTask)
                        || (string)($child['status'] ?? '') !== 'success'
                        || (int)($child['readback_verified'] ?? 0) !== 1
                        || (int)($child['data_source_id'] ?? 0) !== (int)$task['data_source_id']
                        || (int)($child['platform_sync_task_id'] ?? 0) !== (int)$task['sync_task_id']
                        || $this->code((string)($task['collection_status'] ?? '')) !== 'success'
                        || $this->code((string)($task['p0_status'] ?? '')) !== 'ready'
                        || $this->code((string)($task['historical_core_contract_status'] ?? '')) !== 'ready'
                        || $this->uuid((string)($rawTask['dispatcher_run_id'] ?? '')) !== $dispatcherRunId
                        || $this->code((string)($rawTask['trigger_type'] ?? '')) !== $expectedTrigger
                        || !hash_equals(
                            (string)($child['evidence_digest'] ?? ''),
                            $this->sourceEvidenceDigest(
                                $platform,
                                (int)$task['data_source_id'],
                                (int)$task['sync_task_id'],
                                (array)$task['row_ids']
                            )
                        )
                        || !$this->persistedSourceEvidenceMatches(
                            $run,
                            $child,
                            (int)$task['sync_task_id'],
                            (int)($child['local_collector_task_id'] ?? 0),
                            (array)$task['row_ids']
                        )
                    ) {
                        $exactSources = false;
                        break;
                    }
                }
            }

            $authority = is_array($receipt['authority_verifier'] ?? null)
                ? $receipt['authority_verifier']
                : [];
            $verified = $trustedReady
                && ($receipt['collection_complete'] ?? false) === true
                && ($receipt['authority_scope_complete'] ?? false) === true
                && ($receipt['dual_ota_p0_complete'] ?? false) === true
                && ($receipt['canonical_history_complete'] ?? false) === true
                && ($authority['authority_ready'] ?? false) === true
                && $contractVersion === OtaCollectionAnchorService::CONTRACT_VERSION
                && $anchorHash !== ''
                && $exactSources
                && OtaCollectionAnchorService::matches($sourceTasks, $anchorHash);
            $now = date('Y-m-d H:i:s');
            $failureCode = $verified ? '' : ($trustedReady
                ? 'collection_receipt_contract_mismatch'
                : 'collection_trust_not_ready');
            $status = $verified
                ? 'succeeded'
                : $this->terminalIncompleteStatus(array_values($children));
            $trustDigest = $verified ? hash('sha256', $this->json([
                'dispatcher_run_id' => $dispatcherRunId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'collection_anchor_contract_version' => $contractVersion,
                'collection_anchor_hash' => $anchorHash,
                'source_ids' => $receiptSourceIds,
            ])) : null;

            Db::name(self::RUN_TABLE)
                ->where('id', (int)$run['id'])
                ->where('status', '<>', 'succeeded')
                ->update([
                    'status' => $status,
                    'failure_stage' => $verified ? '' : 'trust_finalization',
                    'failure_code' => $failureCode,
                    'collection_anchor_contract_version' => $verified ? $contractVersion : null,
                    'collection_anchor_hash' => $verified ? $anchorHash : null,
                    'trust_receipt_digest' => $trustDigest,
                    'receipt_json' => $this->json([
                        'schema_version' => self::SCHEMA_VERSION,
                        'scope_hash' => (string)$run['scope_hash'],
                        'collection_verified' => $verified,
                        'failure_code' => $failureCode,
                        'resume_scope' => 'same_account_same_device_same_hotel_same_platform',
                        'automatic_device_substitution' => false,
                        'sensitive_values_exposed' => false,
                    ]),
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);
            $written = Db::name(self::RUN_TABLE)->where('id', (int)$run['id'])->find();
            if (!is_array($written)
                || (string)($written['status'] ?? '') !== $status
                || (string)($written['collection_anchor_hash'] ?? '') !== ($verified ? $anchorHash : '')
            ) {
                throw new RuntimeException('hotel_collection_run_final_receipt_readback_mismatch');
            }
            return [
                'tenant_id' => (int)$run['tenant_id'],
                'dispatcher_run_id' => $dispatcherRunId,
                'business_date' => $businessDate,
            ];
        });
        return $this->readGroup(
            (string)$context['dispatcher_run_id'],
            (int)$context['tenant_id'],
            $hotelId,
            (string)$context['business_date']
        );
    }

    /** @return array<string,mixed> */
    public function readExact(
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate
    ): array {
        [$run] = $this->loadExactRun($dispatcherRunId, $hotelId, $businessDate, false);
        return $this->readGroup(
            (string)$run['dispatcher_run_id'],
            (int)$run['tenant_id'],
            (int)$run['system_hotel_id'],
            (string)$run['business_date']
        );
    }

    /** @return array<string,mixed> */
    public function readGroup(
        string $dispatcherRunId,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $dispatcherRunId = $this->uuid($dispatcherRunId);
        $businessDate = $this->date($businessDate);
        $run = Db::name(self::RUN_TABLE)
            ->where('dispatcher_run_id', $dispatcherRunId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->find();
        if (!is_array($run)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'dispatcher_run_id' => $dispatcherRunId,
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'status' => 'missing',
                'source_receipts' => [],
                'readback_verified' => false,
                'automatic_device_substitution' => false,
                'sensitive_values_exposed' => false,
            ];
        }
        $children = Db::name(self::SOURCE_TABLE)
            ->where('run_id', (int)$run['id'])
            ->order('platform', 'asc')
            ->select()
            ->toArray();
        $platforms = array_values(array_unique(array_map(
            static fn(array $row): string => (string)($row['platform'] ?? ''),
            $children
        )));
        sort($platforms, SORT_STRING);
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => (int)$run['id'],
            'dispatcher_run_id' => (string)$run['dispatcher_run_id'],
            'tenant_id' => (int)$run['tenant_id'],
            'system_hotel_id' => (int)$run['system_hotel_id'],
            'business_date' => (string)$run['business_date'],
            'run_mode' => (string)$run['run_mode'],
            'plan_id' => (int)($run['plan_id'] ?? 0) ?: null,
            'plan_version' => (int)($run['plan_version'] ?? 0),
            'scope_hash' => (string)$run['scope_hash'],
            'status' => (string)$run['status'],
            'failure_stage' => (string)($run['failure_stage'] ?? ''),
            'failure_code' => (string)($run['failure_code'] ?? ''),
            'collection_anchor_contract_version' => trim((string)(
                $run['collection_anchor_contract_version'] ?? ''
            )) ?: null,
            'collection_anchor_hash' => trim((string)($run['collection_anchor_hash'] ?? '')) ?: null,
            'trust_receipt_digest' => trim((string)($run['trust_receipt_digest'] ?? '')) ?: null,
            'page_acceptance' => [
                'status' => (string)($run['page_status'] ?? 'not_evaluated'),
                'receipt_id' => (int)($run['page_receipt_id'] ?? 0) ?: null,
                'contract_hash' => trim((string)($run['page_contract_hash'] ?? '')) ?: null,
            ],
            'pms_receipt' => [
                'provider' => trim((string)($run['pms_provider'] ?? '')) ?: null,
                'status' => (string)($run['pms_status'] ?? 'not_run'),
                'capture_id' => trim((string)($run['pms_capture_id'] ?? '')) ?: null,
                'readback_verified' => (int)($run['pms_readback_verified'] ?? 0) === 1,
            ],
            'source_receipts' => array_map(
                fn(array $row): array => $this->publicSourceRow($row),
                $children
            ),
            'readback_verified' => count($children) === 2
                && $platforms === self::PLATFORMS,
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
            'started_at' => (string)($run['started_at'] ?? ''),
            'finished_at' => trim((string)($run['finished_at'] ?? '')) ?: null,
        ];
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $values */
    private function assertSameRunScope(array $existing, array $values): void
    {
        foreach ([
            'tenant_id',
            'system_hotel_id',
            'business_date',
            'run_mode',
            'plan_id',
            'plan_version',
            'plan_hash',
            'scope_hash',
            'execution_owner_user_id',
            'pms_provider',
        ] as $field) {
            if ((string)($existing[$field] ?? '') !== (string)($values[$field] ?? '')) {
                throw new RuntimeException('hotel_collection_run_receipt_scope_mismatch');
            }
        }
    }

    /** @param array<string,mixed> $values */
    private function insertOrAssertSameSourceScope(array $values): void
    {
        $existing = Db::name(self::SOURCE_TABLE)
            ->where('run_id', (int)$values['run_id'])
            ->where('platform', (string)$values['platform'])
            ->lock(true)
            ->find();
        if (is_array($existing)) {
            foreach (['data_source_id', 'ingestion_method'] as $field) {
                if ((string)($existing[$field] ?? '') !== (string)($values[$field] ?? '')) {
                    throw new RuntimeException('hotel_collection_run_receipt_source_scope_mismatch');
                }
            }
            return;
        }
        $id = (int)Db::name(self::SOURCE_TABLE)->insertGetId($values);
        if ($id <= 0) {
            throw new RuntimeException('hotel_collection_run_source_receipt_write_failed');
        }
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>}
     */
    private function loadExactRun(
        string $dispatcherRunId,
        int $hotelId,
        string $businessDate,
        bool $lock
    ): array {
        $dispatcherRunId = $this->uuid($dispatcherRunId);
        $businessDate = $this->date($businessDate);
        if ($dispatcherRunId === '' || $hotelId <= 0 || $businessDate === '') {
            throw new RuntimeException('hotel_collection_run_result_scope_invalid');
        }
        $query = Db::name(self::RUN_TABLE)
            ->where('dispatcher_run_id', $dispatcherRunId)
            ->where('system_hotel_id', $hotelId)
            ->where('business_date', $businessDate);
        if ($lock) {
            $query->lock(true);
        }
        $run = $query->find();
        if (!is_array($run)) {
            throw new RuntimeException('hotel_collection_run_receipt_missing');
        }
        $childQuery = Db::name(self::SOURCE_TABLE)->where('run_id', (int)$run['id']);
        if ($lock) {
            $childQuery->lock(true);
        }
        $rows = $childQuery->select()->toArray();
        $children = [];
        foreach ($rows as $row) {
            $platform = $this->code((string)($row['platform'] ?? ''));
            if (!in_array($platform, self::PLATFORMS, true) || isset($children[$platform])) {
                throw new RuntimeException('hotel_collection_run_source_cardinality_invalid');
            }
            $children[$platform] = $row;
        }
        ksort($children, SORT_STRING);
        if (array_keys($children) !== self::PLATFORMS) {
            throw new RuntimeException('hotel_collection_run_source_cardinality_invalid');
        }
        return [$run, $children];
    }

    /** @param array<string,mixed> $run */
    private function refreshAggregateBeforeFinalization(array $run): void
    {
        $children = Db::name(self::SOURCE_TABLE)
            ->where('run_id', (int)$run['id'])
            ->select()
            ->toArray();
        $statuses = array_map(
            static fn(array $row): string => (string)($row['status'] ?? ''),
            $children
        );
        $successCount = count(array_filter(
            $statuses,
            static fn(string $status): bool => $status === 'success'
        ));
        if (count($children) === 2 && $successCount === 2) {
            $status = 'collected';
            $failureCode = '';
        } elseif (array_intersect($statuses, self::ACTIVE_SOURCE_STATUSES) !== []) {
            $status = 'in_progress';
            $failureCode = 'collection_in_progress';
        } elseif ($successCount > 0 || in_array('partial', $statuses, true)) {
            $status = 'partial';
            $failureCode = 'dual_ota_collection_incomplete';
        } else {
            $status = 'failed';
            $failureCode = 'dual_ota_collection_failed';
        }
        $now = date('Y-m-d H:i:s');
        Db::name(self::RUN_TABLE)->where('id', (int)$run['id'])->update([
            'status' => $status,
            'failure_stage' => $failureCode === '' ? '' : 'collection',
            'failure_code' => $failureCode,
            'collection_anchor_contract_version' => null,
            'collection_anchor_hash' => null,
            'trust_receipt_digest' => null,
            'finished_at' => in_array($status, ['partial', 'failed'], true) ? $now : null,
            'update_time' => $now,
        ]);
    }

    /** @param array<int,array<string,mixed>> $children */
    private function terminalIncompleteStatus(array $children): string
    {
        $statuses = array_map(
            static fn(array $row): string => (string)($row['status'] ?? ''),
            $children
        );
        if (array_intersect($statuses, self::ACTIVE_SOURCE_STATUSES) !== []) {
            return 'in_progress';
        }
        return in_array('success', $statuses, true) || in_array('partial', $statuses, true)
            ? 'partial'
            : 'failed';
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicSourceRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'platform' => (string)($row['platform'] ?? ''),
            'data_source_id' => (int)($row['data_source_id'] ?? 0) ?: null,
            'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
            'platform_sync_task_id' => (int)($row['platform_sync_task_id'] ?? 0) ?: null,
            'local_collector_task_id' => (int)($row['local_collector_task_id'] ?? 0) ?: null,
            'status' => (string)($row['status'] ?? ''),
            'failure_stage' => (string)($row['failure_stage'] ?? ''),
            'failure_code' => (string)($row['failure_code'] ?? ''),
            'saved_row_count' => max(0, (int)($row['saved_row_count'] ?? 0)),
            'readback_row_count' => max(0, (int)($row['readback_row_count'] ?? 0)),
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1,
            'page_acceptance_status' => (string)(
                $row['page_acceptance_status'] ?? 'not_evaluated'
            ),
            'page_acceptance_log_id' => (int)($row['page_acceptance_log_id'] ?? 0) ?: null,
            'started_at' => (string)($row['started_at'] ?? ''),
            'finished_at' => trim((string)($row['finished_at'] ?? '')) ?: null,
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param mixed $values @return array<int,array{code:string,platform:string}> */
    private function issues(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            $code = $this->code((string)($value['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $platform = $this->code((string)($value['platform'] ?? ''));
            $result[] = [
                'code' => $code,
                'platform' => in_array($platform, self::PLATFORMS, true) ? $platform : '',
            ];
        }
        return $result;
    }

    /** @param array<int,array{code:string,platform:string}> $issues */
    private function uniqueIssues(array $issues): array
    {
        $result = [];
        foreach ($issues as $issue) {
            $result[(string)$issue['platform'] . ':' . (string)$issue['code']] = $issue;
        }
        return array_values($result);
    }

    /** @param array<int,array{code:string,platform:string}> $issues */
    private function issueForPlatform(array $issues, string $platform): ?array
    {
        foreach ($issues as $issue) {
            if (($issue['platform'] ?? '') === $platform) {
                return $issue;
            }
        }
        foreach ($issues as $issue) {
            if (($issue['platform'] ?? '') === '') {
                return $issue;
            }
        }
        return $issues[0] ?? null;
    }

    /** @param array<int,array{code:string,platform:string}> $issues */
    private function firstIssueCode(array $issues, string $fallback): string
    {
        return (string)($issues[0]['code'] ?? $fallback);
    }

    /** @param mixed $values @return array<int,int> */
    private function positiveIds(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<int,mixed> $rowIds */
    private function sourceEvidenceDigest(
        string $platform,
        int $sourceId,
        int $syncTaskId,
        array $rowIds
    ): string {
        return hash('sha256', $this->json([
            'platform' => $platform,
            'data_source_id' => $sourceId,
            'sync_task_id' => $syncTaskId,
            'row_ids' => $this->positiveIds($rowIds),
        ]));
    }

    /**
     * Re-read the producer task, raw capture and exact persisted business rows.
     * The receipt payload is never its own evidence.
     *
     * @param array<string,mixed> $run
     * @param array<string,mixed> $child
     * @param array<int,mixed> $rowIds
     */
    private function persistedSourceEvidenceMatches(
        array $run,
        array $child,
        int $syncTaskId,
        int $localCollectorTaskId,
        array $rowIds
    ): bool {
        $tenantId = (int)($run['tenant_id'] ?? 0);
        $hotelId = (int)($run['system_hotel_id'] ?? 0);
        $ownerUserId = (int)($run['execution_owner_user_id'] ?? 0);
        $businessDate = $this->date((string)($run['business_date'] ?? ''));
        $dispatcherRunId = $this->uuid((string)($run['dispatcher_run_id'] ?? ''));
        $platform = $this->code((string)($child['platform'] ?? ''));
        $sourceId = (int)($child['data_source_id'] ?? 0);
        $ingestionMethod = $this->code((string)($child['ingestion_method'] ?? ''));
        $rowIds = $this->positiveIds($rowIds);
        if ($tenantId <= 0 || $hotelId <= 0 || $ownerUserId <= 0
            || $businessDate === '' || $dispatcherRunId === ''
            || !in_array($platform, self::PLATFORMS, true)
            || $sourceId <= 0 || $syncTaskId <= 0 || $rowIds === []
            || !in_array($ingestionMethod, ['browser_profile', 'local_collector'], true)
        ) {
            return false;
        }
        if ($ingestionMethod === 'local_collector' && $localCollectorTaskId <= 0) {
            return false;
        }
        if ($ingestionMethod !== 'local_collector' && $localCollectorTaskId > 0) {
            return false;
        }

        $sourceColumns = $this->tableColumns('platform_data_sources');
        if (array_diff([
            'id',
            'tenant_id',
            'user_id',
            'system_hotel_id',
            'platform',
            'ingestion_method',
        ], array_keys($sourceColumns)) !== []) {
            return false;
        }
        $source = Db::name('platform_data_sources')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerUserId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('ingestion_method', $ingestionMethod)
            ->find();
        if (!is_array($source)) {
            return false;
        }

        $syncColumns = $this->tableColumns('platform_data_sync_tasks');
        if (array_diff([
            'id',
            'tenant_id',
            'data_source_id',
            'system_hotel_id',
            'platform',
            'ingestion_method',
            'trigger_type',
            'status',
            'stats_json',
        ], array_keys($syncColumns)) !== []) {
            return false;
        }
        $expectedTrigger = $ingestionMethod === 'local_collector'
            ? 'local_collector_upload'
            : 'daily_profile_reuse';
        $syncTask = Db::name('platform_data_sync_tasks')
            ->where('id', $syncTaskId)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('ingestion_method', $ingestionMethod)
            ->where('trigger_type', $expectedTrigger)
            ->where('status', 'success')
            ->find();
        $syncStats = is_array($syncTask)
            ? $this->decodeJson((string)($syncTask['stats_json'] ?? ''))
            : [];
        if (!is_array($syncTask)
            || $this->uuid((string)($syncStats['dispatcher_run_id'] ?? '')) !== $dispatcherRunId
        ) {
            return false;
        }

        $rawColumns = $this->tableColumns('platform_data_raw_records');
        if (array_diff([
            'id',
            'tenant_id',
            'data_source_id',
            'sync_task_id',
            'system_hotel_id',
            'platform',
            'ingestion_method',
        ], array_keys($rawColumns)) !== []) {
            return false;
        }
        $rawCount = (int)Db::name('platform_data_raw_records')
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $syncTaskId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('ingestion_method', $ingestionMethod)
            ->count();
        if ($rawCount <= 0) {
            return false;
        }

        $dailyColumns = $this->tableColumns('online_daily_data');
        $requiredDailyColumns = [
            'id',
            'tenant_id',
            'data_source_id',
            'sync_task_id',
            'system_hotel_id',
            'data_date',
            'data_period',
            'readback_verified',
        ];
        if (array_diff($requiredDailyColumns, array_keys($dailyColumns)) !== []
            || (!isset($dailyColumns['platform']) && !isset($dailyColumns['source']))
        ) {
            return false;
        }
        $dailyQuery = Db::name('online_daily_data')
            ->whereIn('id', $rowIds)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $syncTaskId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_date', $businessDate)
            ->where('data_period', 'historical_daily')
            ->where('readback_verified', 1);
        if (isset($dailyColumns['platform'])) {
            $dailyQuery->where('platform', $platform);
        } else {
            $dailyQuery->where('source', $platform);
        }
        $persistedRowIds = $this->positiveIds(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $dailyQuery->field('id')->select()->toArray()
        ));
        if ($persistedRowIds !== $rowIds) {
            return false;
        }

        if ($ingestionMethod !== 'local_collector') {
            return true;
        }
        return $this->localCollectorProducerMatches(
            $run,
            $source,
            $syncTaskId,
            $localCollectorTaskId,
            $rowIds
        );
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $source
     * @param array<int,int> $rowIds
     */
    private function localCollectorProducerMatches(
        array $run,
        array $source,
        int $syncTaskId,
        int $localCollectorTaskId,
        array $rowIds
    ): bool {
        $tenantId = (int)$run['tenant_id'];
        $ownerUserId = (int)$run['execution_owner_user_id'];
        $hotelId = (int)$run['system_hotel_id'];
        $businessDate = (string)$run['business_date'];
        $dispatcherRunId = (string)$run['dispatcher_run_id'];
        $platform = (string)$source['platform'];
        $sourceId = (int)$source['id'];
        $config = $this->decodeJson((string)($source['config_json'] ?? ''));
        $accountId = (int)($config['local_collector_account_id'] ?? 0);
        $profileHash = strtolower(trim((string)($config['profile_key_hash'] ?? '')));
        $deviceHash = strtolower(trim((string)($config['collector_device_id_hash'] ?? '')));
        if ($accountId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $profileHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $deviceHash) !== 1
        ) {
            return false;
        }

        $account = Db::name('ota_local_collector_accounts')
            ->where('id', $accountId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerUserId)
            ->where('platform', $platform)
            ->where('status', '<>', 'revoked')
            ->find();
        if (!is_array($account)
            || !hash_equals($profileHash, strtolower((string)($account['profile_key_hash'] ?? '')))
        ) {
            return false;
        }
        $deviceId = (int)($account['device_id'] ?? 0);
        $device = Db::name('ota_local_collector_devices')
            ->where('id', $deviceId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerUserId)
            ->where('status', '<>', 'revoked')
            ->find();
        if (!is_array($device)
            || !hash_equals(
                $deviceHash,
                hash('sha256', (string)($device['device_public_id'] ?? ''))
            )
        ) {
            return false;
        }
        $mapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_source_id', $sourceId)
            ->where('status', 'active')
            ->find();
        if (!is_array($mapping)) {
            return false;
        }

        $localTask = Db::name('ota_local_collector_tasks')
            ->where('id', $localCollectorTaskId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerUserId)
            ->where('device_id', $deviceId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('data_date', $businessDate)
            ->where('status', 'success')
            ->find();
        if (!is_array($localTask)) {
            return false;
        }
        $request = $this->decodeJson((string)($localTask['request_json'] ?? ''));
        $summary = $this->decodeJson((string)($localTask['result_summary_json'] ?? ''));
        $runReadback = is_array($summary['run_readback'] ?? null)
            ? $summary['run_readback']
            : [];
        $summaryRowIds = $this->positiveIds($runReadback['row_ids'] ?? []);
        $scopeIdentity = is_array($summary['scope_identity'] ?? null)
            ? $summary['scope_identity']
            : [];
        return $this->uuid((string)($request['dispatcher_run_id'] ?? '')) === $dispatcherRunId
            && $this->uuid((string)($runReadback['dispatcher_run_id'] ?? '')) === $dispatcherRunId
            && $this->code((string)($runReadback['trigger_type'] ?? '')) === 'local_collector_upload'
            && ($summary['readback_verified'] ?? false) === true
            && ($runReadback['readback_verified'] ?? false) === true
            && (int)($summary['data_source_id'] ?? 0) === $sourceId
            && (int)($summary['sync_task_id'] ?? 0) === $syncTaskId
            && (int)($runReadback['data_source_id'] ?? 0) === $sourceId
            && (int)($runReadback['sync_task_id'] ?? 0) === $syncTaskId
            && (int)($scopeIdentity['capture_task_id'] ?? 0) === $localCollectorTaskId
            && $summaryRowIds === $rowIds;
    }

    /** @return array<string,bool> */
    private function tableColumns(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
        } catch (\Throwable) {
            return [];
        }
        return is_array($fields)
            ? array_fill_keys(array_map('strval', array_values($fields)), true)
            : [];
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function uuid(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
            $value
        ) === 1 ? $value : '';
    }

    private function date(string $value): string
    {
        $value = substr(trim($value), 0, 10);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value
            ? $value
            : '';
    }

    private function code(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function digest(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    private function assertTablesReady(): void
    {
        foreach ([
            self::RUN_TABLE => ['dispatcher_run_id', 'collection_anchor_hash', 'receipt_json'],
            self::SOURCE_TABLE => ['run_id', 'platform', 'receipt_json'],
        ] as $table => $requiredFields) {
            try {
                $fields = Db::getTableInfo($table, 'fields');
            } catch (\Throwable) {
                $fields = [];
            }
            if (!is_array($fields) || array_diff($requiredFields, $fields) !== []) {
                throw new RuntimeException('hotel_collection_run_receipt_schema_missing');
            }
        }
    }
}
