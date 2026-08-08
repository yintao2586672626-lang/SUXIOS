<?php
declare(strict_types=1);

use app\service\OperatingMemoryService;
use app\service\OperationManagementService;
use app\service\OtaLocalCollectorService;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @return positive-int */
function requiredPositiveIntegerEnvironment(string $name): int
{
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT);
    if ($value === false || $value <= 0) {
        throw new RuntimeException($name . ' must be a positive integer');
    }
    return $value;
}

function waitAtBarrier(int $worker): void
{
    if (hash_equals('1', trim((string)getenv('SUXI_CI_SKIP_BARRIER')))) {
        return;
    }

    $barrierDir = trim((string)getenv('SUXI_CI_BARRIER_DIR'));
    if ($barrierDir === '' || !is_dir($barrierDir)) {
        throw new RuntimeException('Worker barrier directory is unavailable');
    }
    $readyPath = $barrierDir . DIRECTORY_SEPARATOR . 'ready_' . $worker;
    if (file_put_contents($readyPath, (string)getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Worker could not signal readiness');
    }

    $goPath = $barrierDir . DIRECTORY_SEPARATOR . 'go';
    $deadline = microtime(true) + 30;
    while (!is_file($goPath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker timed out waiting at the concurrency barrier');
        }
        usleep(10000);
    }
}

function signalCaseBarrier(string $name): void
{
    $barrierDir = trim((string)getenv('SUXI_CI_BARRIER_DIR'));
    if ($barrierDir === '' || !is_dir($barrierDir)) {
        throw new RuntimeException('Worker case barrier directory is unavailable');
    }
    if (file_put_contents($barrierDir . DIRECTORY_SEPARATOR . $name, (string)getmypid(), LOCK_EX) === false) {
        throw new RuntimeException('Worker could not signal case barrier: ' . $name);
    }
}

function waitForCaseBarrier(string $name): void
{
    $barrierDir = trim((string)getenv('SUXI_CI_BARRIER_DIR'));
    $path = $barrierDir . DIRECTORY_SEPARATOR . $name;
    $deadline = microtime(true) + 30;
    while (!is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker timed out waiting for case barrier: ' . $name);
        }
        usleep(10000);
    }
}

try {
    if (!hash_equals('1', trim((string)getenv('SUXI_CI_MYSQL_VERIFY')))) {
        throw new RuntimeException('SUXI_CI_MYSQL_VERIFY=1 is required');
    }
    if (!hash_equals('1', trim((string)getenv('SUXI_E2E_DB_OVERRIDE')))) {
        throw new RuntimeException('SUXI_E2E_DB_OVERRIDE=1 is required');
    }

    $expectedDatabase = trim((string)getenv('SUXI_E2E_DB_NAME'));
    if (preg_match('/(?:^|[_-])(?:test(?:ing)?|e2e)(?:$|[_-])/iD', $expectedDatabase) !== 1) {
        throw new RuntimeException('Worker requires a dedicated *_test/*_testing/*_e2e database');
    }
    $databaseHost = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1')));
    if (!in_array($databaseHost, ['127.0.0.1', 'localhost', '::1', '[::1]'], true)
        && !hash_equals('1', trim((string)getenv('SUXI_E2E_ALLOW_REMOTE_TEST_DB')))
    ) {
        throw new RuntimeException('Worker refused a non-loopback database host');
    }

    (new App(dirname(__DIR__)))->initialize();
    $databaseRow = Db::query('SELECT DATABASE() AS database_name');
    $activeDatabase = trim((string)($databaseRow[0]['database_name'] ?? ''));
    if ($activeDatabase === '' || !hash_equals($expectedDatabase, $activeDatabase)) {
        throw new RuntimeException('Worker database does not match the dedicated E2E database');
    }

    $case = strtolower(trim((string)getenv('SUXI_CI_ATOMIC_CASE')));
    $worker = requiredPositiveIntegerEnvironment('SUXI_CI_WORKER_INDEX');
    $hotelId = requiredPositiveIntegerEnvironment('SUXI_CI_HOTEL_ID');
    $tenantId = requiredPositiveIntegerEnvironment('SUXI_CI_TENANT_ID');
    $userId = requiredPositiveIntegerEnvironment('SUXI_CI_USER_ID');

    if ($case === 'pair_prepare') {
        $actor = new class($userId, $tenantId, $hotelId) {
            public function __construct(
                public int $id,
                public int $tenant_id,
                private readonly int $hotelId
            ) {
            }

            /** @return list<int> */
            public function getPermittedHotelIds(): array
            {
                return [$this->hotelId];
            }

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };
        $pair = (new OtaLocalCollectorService())->createPairCode($actor, [
            'device_name' => 'Atomic pair-code verifier',
        ]);
        fwrite(STDOUT, (string)json_encode([
            'atomic_case' => $case,
            'worker' => $worker,
            'succeeded' => true,
            'pair_code' => (string)($pair['pair_code'] ?? ''),
            'database' => $activeDatabase,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    waitAtBarrier($worker);

    if ($case === 'collector_lease_fence') {
        $taskId = requiredPositiveIntegerEnvironment('SUXI_CI_TASK_ID');
        $task = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) {
            throw new RuntimeException('Collector lease fixture task is unavailable');
        }
        if ($worker === 1) {
            $device = Db::name('ota_local_collector_devices')
                ->where('id', (int)$task['device_id'])
                ->find();
            if (!is_array($device)) {
                throw new RuntimeException('Collector lease fixture device is unavailable');
            }
            $service = new OtaLocalCollectorService();
            $lock = new ReflectionMethod(OtaLocalCollectorService::class, 'lockLeasedTaskForImport');
            $lock->setAccessible(true);
            $write = new ReflectionMethod(OtaLocalCollectorService::class, 'requireLeasedTaskWrite');
            $write->setAccessible(true);
            Db::startTrans();
            try {
                $locked = $lock->invoke($service, $device, $task);
                signalCaseBarrier('collector_lease_locked');
                waitForCaseBarrier('collector_lease_write_attempted');
                usleep(250000);
                $now = date('Y-m-d H:i:s');
                $write->invoke($service, $locked, [
                    'status' => 'success',
                    'lease_token_hash' => '',
                    'lease_expires_at' => null,
                    'finished_at' => $now,
                    'update_time' => $now,
                ], null, $now);
                Db::commit();
            } catch (Throwable $throwable) {
                Db::rollback();
                throw $throwable;
            }
            $final = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => is_array($final)
                    && (string)$final['status'] === 'success'
                    && (string)$final['lease_token_hash'] === '',
                'role' => 'lease_owner',
                'final_status' => (string)($final['status'] ?? ''),
                'database' => $activeDatabase,
            ];
        } else {
            $replacementHash = strtolower(trim((string)getenv('SUXI_CI_REPLACEMENT_LEASE_HASH')));
            if (preg_match('/^[a-f0-9]{64}$/D', $replacementHash) !== 1) {
                throw new RuntimeException('SUXI_CI_REPLACEMENT_LEASE_HASH is invalid');
            }
            waitForCaseBarrier('collector_lease_locked');
            signalCaseBarrier('collector_lease_write_attempted');
            $startedAt = microtime(true);
            $updated = Db::name('ota_local_collector_tasks')
                ->where('id', $taskId)
                ->where('status', (string)$task['status'])
                ->where('attempt', (int)$task['attempt'])
                ->where('lease_token_hash', (string)$task['lease_token_hash'])
                ->update([
                    'status' => 'leased',
                    'attempt' => (int)$task['attempt'] + 1,
                    'lease_token_hash' => $replacementHash,
                    'lease_expires_at' => date('Y-m-d H:i:s', time() + 900),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
            $final = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => $updated === 0
                    && is_array($final)
                    && (string)$final['status'] === 'success'
                    && (string)$final['lease_token_hash'] === '',
                'role' => 'stale_replacement',
                'replacement_write_count' => $updated,
                'blocked_ms' => $elapsedMs,
                'final_status' => (string)($final['status'] ?? ''),
                'database' => $activeDatabase,
            ];
        }
    } elseif ($case === 'collector_successor_wins') {
        $taskId = requiredPositiveIntegerEnvironment('SUXI_CI_TASK_ID');
        $task = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
        if (!is_array($task)) {
            throw new RuntimeException('Collector successor fixture task is unavailable');
        }
        $replacementHash = strtolower(trim((string)getenv('SUXI_CI_REPLACEMENT_LEASE_HASH')));
        if (preg_match('/^[a-f0-9]{64}$/D', $replacementHash) !== 1) {
            throw new RuntimeException('SUXI_CI_REPLACEMENT_LEASE_HASH is invalid');
        }

        if ($worker === 1) {
            $ownerToken = trim((string)getenv('SUXI_CI_OWNER_TOKEN'));
            $device = Db::name('ota_local_collector_devices')
                ->where('id', (int)$task['device_id'])
                ->find();
            if (!is_array($device)
                || $ownerToken === ''
                || !hash_equals((string)$task['lease_token_hash'], hash('sha256', $ownerToken))
                || !hash_equals((string)$device['device_token_hash'], hash('sha256', $ownerToken))
            ) {
                throw new RuntimeException('Collector stale-owner credentials do not match the fixture');
            }
            $mapping = Db::name('ota_local_collector_account_hotels')
                ->where('tenant_id', (int)$task['tenant_id'])
                ->where('account_id', (int)$task['account_id'])
                ->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->where('platform', (string)$task['platform'])
                ->find();
            if (!is_array($mapping)) {
                throw new RuntimeException('Collector stale-owner mapping is unavailable');
            }
            $businessScope = static fn(): int => (int)Db::name('online_daily_data')
                ->where('tenant_id', (int)$task['tenant_id'])
                ->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->where('data_date', (string)$task['data_date'])
                ->where('platform', (string)$task['platform'])
                ->count();
            $businessRowsBefore = $businessScope();
            signalCaseBarrier('collector_stale_snapshot_loaded');
            waitForCaseBarrier('collector_successor_installed');

            $service = new OtaLocalCollectorService();
            $progressRejected = false;
            try {
                $service->updateTaskProgress(
                    (string)$device['device_public_id'],
                    $ownerToken,
                    $taskId,
                    ['lease_token' => $ownerToken, 'status' => 'running']
                );
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }
                $progressRejected = true;
            }

            $resultRejected = false;
            try {
                $service->submitTaskResult(
                    (string)$device['device_public_id'],
                    $ownerToken,
                    $taskId,
                    [
                        'lease_token' => $ownerToken,
                        'success' => true,
                        'capture_summary' => [
                            'platform_identity_validation' => [
                                'status' => 'matched',
                                'source_validation' => true,
                                'validated_identifier' => (string)$mapping['platform_hotel_id'],
                            ],
                        ],
                        'rows' => [[
                            'data_date' => (string)$task['data_date'],
                            'platform_hotel_id' => (string)$mapping['platform_hotel_id'],
                            'data_type' => 'business',
                            'order_amount' => 13,
                            'room_nights' => 1,
                            'order_count' => 1,
                        ]],
                    ]
                );
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }
                $resultRejected = true;
            }

            $terminalRejected = false;
            $write = new ReflectionMethod(OtaLocalCollectorService::class, 'requireLeasedTaskWrite');
            $write->setAccessible(true);
            try {
                $now = date('Y-m-d H:i:s');
                $write->invoke($service, $task, [
                    'status' => 'failed',
                    'error_code' => 'stale_owner_probe',
                    'lease_token_hash' => '',
                    'lease_expires_at' => null,
                    'finished_at' => $now,
                    'update_time' => $now,
                ], null, $now);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }
                $terminalRejected = true;
            }

            $businessRowsAfter = $businessScope();
            $final = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
            $successorIntact = is_array($final)
                && (string)$final['status'] === 'leased'
                && (int)$final['attempt'] === (int)$task['attempt'] + 1
                && hash_equals($replacementHash, (string)$final['lease_token_hash']);
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => $progressRejected
                    && $resultRejected
                    && $terminalRejected
                    && $businessRowsAfter === $businessRowsBefore
                    && $successorIntact,
                'role' => 'stale_owner',
                'progress_rejected' => $progressRejected,
                'result_rejected' => $resultRejected,
                'terminal_rejected' => $terminalRejected,
                'business_rows_before' => $businessRowsBefore,
                'business_rows_after' => $businessRowsAfter,
                'final_status' => (string)($final['status'] ?? ''),
                'final_attempt' => (int)($final['attempt'] ?? 0),
                'database' => $activeDatabase,
            ];
        } else {
            waitForCaseBarrier('collector_stale_snapshot_loaded');
            $now = date('Y-m-d H:i:s');
            Db::startTrans();
            try {
                $recovered = Db::name('ota_local_collector_tasks')
                    ->where('id', $taskId)
                    ->where('status', (string)$task['status'])
                    ->where('attempt', (int)$task['attempt'])
                    ->where('lease_token_hash', (string)$task['lease_token_hash'])
                    ->where('lease_expires_at', (string)$task['lease_expires_at'])
                    ->where('lease_expires_at', '<', $now)
                    ->update([
                        'status' => 'retry_wait',
                        'available_at' => $now,
                        'lease_token_hash' => '',
                        'lease_expires_at' => null,
                        'error_code' => 'lease_expired',
                        'error_summary' => 'successor-wins concurrency verifier',
                        'finished_at' => null,
                        'update_time' => $now,
                    ]);
                $successor = Db::name('ota_local_collector_tasks')
                    ->where('id', $taskId)
                    ->where('status', 'retry_wait')
                    ->where('attempt', (int)$task['attempt'])
                    ->where('lease_token_hash', '')
                    ->whereNull('lease_expires_at')
                    ->update([
                        'status' => 'leased',
                        'attempt' => (int)$task['attempt'] + 1,
                        'lease_token_hash' => $replacementHash,
                        'lease_expires_at' => date('Y-m-d H:i:s', time() + 900),
                        'error_code' => '',
                        'error_summary' => '',
                        'started_at' => $now,
                        'update_time' => $now,
                    ]);
                if ($recovered !== 1 || $successor !== 1) {
                    throw new RuntimeException("Collector successor transition failed: recovered={$recovered}, successor={$successor}");
                }
                Db::commit();
            } catch (Throwable $throwable) {
                Db::rollback();
                signalCaseBarrier('collector_successor_installed');
                throw $throwable;
            }
            signalCaseBarrier('collector_successor_installed');
            $final = Db::name('ota_local_collector_tasks')->where('id', $taskId)->find();
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => is_array($final)
                    && (string)$final['status'] === 'leased'
                    && (int)$final['attempt'] === (int)$task['attempt'] + 1
                    && hash_equals($replacementHash, (string)$final['lease_token_hash']),
                'role' => 'successor',
                'recovered_write_count' => $recovered,
                'successor_write_count' => $successor,
                'final_status' => (string)($final['status'] ?? ''),
                'final_attempt' => (int)($final['attempt'] ?? 0),
                'database' => $activeDatabase,
            ];
        }
    } elseif ($case === 'collector_device_revoke') {
        $deviceId = requiredPositiveIntegerEnvironment('SUXI_CI_DEVICE_ID');
        $service = new OtaLocalCollectorService();
        if ($worker === 1) {
            $staleDevice = Db::name('ota_local_collector_devices')->where('id', $deviceId)->find();
            if (!is_array($staleDevice)) {
                throw new RuntimeException('Collector revoke fixture device is unavailable');
            }
            $staleAccount = Db::name('ota_local_collector_accounts')
                ->where('device_id', $deviceId)
                ->where('status', 'active')
                ->find();
            $staleMapping = is_array($staleAccount)
                ? Db::name('ota_local_collector_account_hotels')
                    ->where('account_id', (int)$staleAccount['id'])
                    ->where('status', 'active')
                    ->find()
                : null;
            if (!is_array($staleAccount) || !is_array($staleMapping)) {
                throw new RuntimeException('Collector revoke fixture account or mapping is unavailable');
            }
            $originalTokenHash = (string)$staleDevice['device_token_hash'];
            signalCaseBarrier('collector_device_authenticated');
            waitForCaseBarrier('collector_device_revoked');
            $touch = new ReflectionMethod(OtaLocalCollectorService::class, 'touchDevice');
            $touch->setAccessible(true);
            $touchAccepted = $touch->invoke($service, $staleDevice) === true;
            $enqueue = new ReflectionMethod(OtaLocalCollectorService::class, 'enqueueTask');
            $enqueue->setAccessible(true);
            $staleEnqueueRejected = false;
            try {
                $enqueue->invoke($service, [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'hotel_ids' => [$hotelId],
                ], $staleAccount, $staleMapping, 'backfill', '2001-01-01', 'business', [
                    'reason' => 'atomic_stale_heartbeat_after_revoke',
                ], false, 35, false, $staleDevice);
            } catch (RuntimeException $exception) {
                if ($exception->getCode() !== 409) {
                    throw $exception;
                }
                $staleEnqueueRejected = true;
            }
            $postRevokeTaskCount = (int)Db::name('ota_local_collector_tasks')
                ->where('device_id', $deviceId)
                ->where('data_date', '2001-01-01')
                ->count();
            signalCaseBarrier('collector_device_stale_touch_done');
            $final = Db::name('ota_local_collector_devices')->where('id', $deviceId)->find();
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => !$touchAccepted
                    && $staleEnqueueRejected
                    && $postRevokeTaskCount === 0
                    && is_array($final)
                    && (string)$final['status'] === 'revoked'
                    && !hash_equals($originalTokenHash, (string)$final['device_token_hash']),
                'role' => 'stale_authenticated_request',
                'touch_accepted' => $touchAccepted,
                'stale_enqueue_rejected' => $staleEnqueueRejected,
                'post_revoke_task_count' => $postRevokeTaskCount,
                'final_status' => (string)($final['status'] ?? ''),
                'token_rotated' => is_array($final)
                    && !hash_equals($originalTokenHash, (string)$final['device_token_hash']),
                'database' => $activeDatabase,
            ];
        } else {
            $actor = new class($userId, $tenantId, $hotelId) {
                public function __construct(
                    public int $id,
                    public int $tenant_id,
                    private readonly int $hotelId
                ) {
                }

                /** @return list<int> */
                public function getPermittedHotelIds(): array
                {
                    return [$this->hotelId];
                }

                public function isSuperAdmin(): bool
                {
                    return false;
                }
            };
            waitForCaseBarrier('collector_device_authenticated');
            $revoked = $service->revokeDevice($actor, $deviceId);
            signalCaseBarrier('collector_device_revoked');
            waitForCaseBarrier('collector_device_stale_touch_done');
            $final = Db::name('ota_local_collector_devices')->where('id', $deviceId)->find();
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => (string)($revoked['status'] ?? '') === 'revoked'
                    && is_array($final)
                    && (string)$final['status'] === 'revoked',
                'role' => 'revoker',
                'revoke_status' => (string)($revoked['status'] ?? ''),
                'final_status' => (string)($final['status'] ?? ''),
                'database' => $activeDatabase,
            ];
        }
    } elseif ($case === 'operation_evidence') {
        $taskId = requiredPositiveIntegerEnvironment('SUXI_CI_TASK_ID');
        $result = (new OperationManagementService())->addExecutionEvidence(
            $taskId,
            [$hotelId],
            [
                'evidence_type' => 'manual_followup',
                'evidence' => [
                    'after' => ['status' => 'checked', 'count' => 0],
                    'remark' => 'same concurrent normalized follow-up evidence',
                ],
            ],
            $userId
        );
        $write = is_array($result['evidence_write'] ?? null) ? $result['evidence_write'] : [];
        $output = [
            'atomic_case' => $case,
            'worker' => $worker,
            'succeeded' => true,
            'evidence_id' => (int)($write['evidence_id'] ?? 0),
            'created' => (bool)($write['created'] ?? false),
            'fingerprint' => (string)($write['fingerprint'] ?? ''),
            'database' => $activeDatabase,
        ];
    } elseif ($case === 'operating_memory') {
        $clientRequestId = trim((string)getenv('SUXI_CI_MEMORY_REQUEST_ID'));
        if (preg_match('/^[A-Za-z0-9_.:-]{8,100}$/D', $clientRequestId) !== 1) {
            throw new RuntimeException('SUXI_CI_MEMORY_REQUEST_ID is invalid');
        }
        $result = (new OperatingMemoryService())->createManualGrowthEvent(
            $tenantId,
            [$hotelId],
            $hotelId,
            [
                'event_kind' => 'manual_background',
                'title' => 'Atomic operating-memory verifier',
                'summary' => 'Two concurrent processes must persist one memory identity.',
                'occurred_at' => date('Y-m-d') . ' 08:00:00',
                'business_date' => date('Y-m-d'),
                'platform' => 'manual',
                'source_scope' => 'manual_background',
                'client_request_id' => $clientRequestId,
            ],
            $userId
        );
        $memory = is_array($result['memory'] ?? null) ? $result['memory'] : [];
        $output = [
            'atomic_case' => $case,
            'worker' => $worker,
            'succeeded' => true,
            'memory_id' => (int)($memory['id'] ?? 0),
            'memory_key' => (string)($memory['memory_key'] ?? ''),
            'created' => (bool)($result['created'] ?? false),
            'database' => $activeDatabase,
        ];
    } elseif ($case === 'pair_code') {
        $pairCode = trim((string)getenv('SUXI_CI_PAIR_CODE'));
        try {
            $result = (new OtaLocalCollectorService())->pairDevice([
                'pair_code' => $pairCode,
                'device_platform' => 'windows',
            ]);
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => true,
                'device_id' => (int)($result['device_id'] ?? 0),
                'error_code' => 0,
                'database' => $activeDatabase,
            ];
        } catch (RuntimeException $exception) {
            if (!in_array($exception->getCode(), [409, 410], true)) {
                throw $exception;
            }
            $output = [
                'atomic_case' => $case,
                'worker' => $worker,
                'succeeded' => false,
                'device_id' => 0,
                'error_code' => $exception->getCode(),
                'database' => $activeDatabase,
            ];
        }
    } else {
        throw new RuntimeException('Unsupported atomic concurrency case');
    }

    fwrite(STDOUT, (string)json_encode(
        $output,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, (string)json_encode([
        'atomic_case' => strtolower(trim((string)getenv('SUXI_CI_ATOMIC_CASE'))),
        'error' => $throwable->getMessage(),
        'type' => get_class($throwable),
        'code' => $throwable->getCode(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
