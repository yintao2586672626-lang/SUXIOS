<?php
declare(strict_types=1);

namespace app\service;

use app\exception\OtaLocalCollectorLeaseConflict;
use app\model\User;
use app\service\concern\OtaLocalCollectorLeaseConcern;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;
use Throwable;

/**
 * Coordinates account-owned OTA collectors without taking custody of browser
 * sessions. The server stores device/account/task metadata and normalized
 * business facts only; Profile, Cookie and browser storage stay on the device.
 */
final class OtaLocalCollectorService
{
    use OtaLocalCollectorLeaseConcern;

    private const CONTRACT_VERSION = 'ota_local_collector.v1';
    private const PAIR_TTL_SECONDS = 600;
    private const DEVICE_ONLINE_SECONDS = 120;
    private const LEASE_SECONDS = 900;
    private const GAP_LOOKBACK_DAYS = 3;
    private const MAX_GAP_TASKS_PER_HEARTBEAT = 5;
    private const YESTERDAY_WINDOW_START = '08:30';
    private const YESTERDAY_WINDOW_CUTOFF = '09:00';
    private const MAX_ROWS_PER_RESULT = 2000;
    private const MAX_RESULT_BYTES = 3_145_728;
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const TASK_TYPES = ['login', 'session_probe', 'collect', 'backfill'];
    private const ACTIVE_TASK_STATUSES = [
        'queued',
        'leased',
        'running',
        'retry_wait',
        'waiting_user_login',
        'verification_required',
    ];
    private const MANUAL_RETRYABLE_TASK_STATUSES = [
        'failed',
        'cancelled',
    ];
    private const RETRYABLE_ERRORS = [
        'network_error',
        'platform_unavailable',
        'collection_failed',
        'zero_rows',
        'upload_failed',
        'resource_busy',
        'lease_expired',
        'browser_start_failed',
        'field_gap',
        'identity_unverified',
    ];
    private const LOGIN_ERRORS = [
        'login_required',
        'login_expired',
        'session_expired',
        'cookies_incomplete',
    ];
    private const VERIFICATION_ERRORS = [
        'verification_required',
        'captcha_required',
        'anti_bot',
        'human_verification_required',
    ];

    /** @var callable|null */
    private $collectionImporter;

    /** @var callable|null */
    private $trustResolver;

    /** @var callable|null */
    private $authorityVerifier;

    /** @var callable|null */
    private $downstreamGateResolver;

    public function __construct(
        ?callable $collectionImporter = null,
        private readonly ?OtaFailureNotificationService $failureNotifier = null,
        ?callable $trustResolver = null,
        ?callable $authorityVerifier = null,
        ?callable $downstreamGateResolver = null
    ) {
        $this->collectionImporter = $collectionImporter;
        $this->trustResolver = $trustResolver;
        $this->authorityVerifier = $authorityVerifier;
        $this->downstreamGateResolver = $downstreamGateResolver;
    }

    /** @return array<string, mixed> */
    public function createPairCode(mixed $user, array $input): array
    {
        $actor = $this->actorContext($user);
        $deviceName = $this->safeText((string)($input['device_name'] ?? ''), 120);
        if ($deviceName === '') {
            $deviceName = '我的 Windows 采集电脑';
        }

        $rawCode = strtoupper(bin2hex(random_bytes(6)));
        $displayCode = implode('-', str_split($rawCode, 4));
        $expiresAt = date('Y-m-d H:i:s', time() + self::PAIR_TTL_SECONDS);
        Cache::set($this->pairCacheKey($rawCode), [
            'tenant_id' => $actor['tenant_id'],
            'user_id' => $actor['user_id'],
            'device_name' => $deviceName,
            'expires_at' => $expiresAt,
        ], self::PAIR_TTL_SECONDS);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'pair_code' => $displayCode,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => self::PAIR_TTL_SECONDS,
            'device_name' => $deviceName,
            'command' => 'node scripts/ota_local_collector.mjs pair --server=<宿析地址> --code='
                . $displayCode . ' --name="' . str_replace('"', '', $deviceName) . '"',
            'boundary' => '配对码只登记设备；OTA Cookie、Profile、验证码和登录令牌不会上传。',
        ];
    }

    /** @return array<string, mixed> */
    public function pairDevice(array $input): array
    {
        $rawCode = strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', (string)($input['pair_code'] ?? $input['code'] ?? '')));
        if (strlen($rawCode) !== 12) {
            throw new RuntimeException('配对码格式不正确。', 422);
        }
        $cacheKey = $this->pairCacheKey($rawCode);
        $pairing = Cache::get($cacheKey);
        if (!is_array($pairing) || strtotime((string)($pairing['expires_at'] ?? '')) < time()) {
            Cache::delete($cacheKey);
            throw new RuntimeException('配对码已失效，请在宿析OS重新生成。', 410);
        }

        $userId = (int)($pairing['user_id'] ?? 0);
        $tenantId = (int)($pairing['tenant_id'] ?? 0);
        $deviceName = $this->safeText(
            (string)($input['device_name'] ?? $input['name'] ?? $pairing['device_name'] ?? ''),
            120
        );
        $devicePlatform = strtolower($this->safeIdentifier((string)($input['device_platform'] ?? 'windows'), 30));
        if ($devicePlatform !== 'windows') {
            throw new RuntimeException('首版本机采集器仅支持 Windows。', 422);
        }
        $collectorVersion = $this->safeIdentifier((string)($input['collector_version'] ?? ''), 40);
        $capabilities = $this->sanitizeCapabilities($input['capabilities'] ?? []);
        $publicId = 'dev_' . bin2hex(random_bytes(12));
        $deviceToken = 'lc_' . bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');

        $id = Db::transaction(function () use (
            $cacheKey,
            $userId,
            $tenantId,
            $publicId,
            $deviceToken,
            $deviceName,
            $devicePlatform,
            $collectorVersion,
            $capabilities,
            $now
        ): int {
            // Serialize all pair-code consumption for this account. The cache
            // value is re-read only after the owner row lock is held, so two
            // concurrent requests cannot both pass the one-time gate.
            $owner = Db::name('users')->where('id', $userId)->lock(true)->find();
            if (!is_array($owner)
                || (int)($owner['status'] ?? 0) !== 1
                || $tenantId <= 0
                || (int)($owner['tenant_id'] ?? 0) !== $tenantId
            ) {
                throw new RuntimeException('配对账号不可用，请联系管理员。', 403);
            }
            $current = Cache::get($cacheKey);
            if (!is_array($current)
                || strtotime((string)($current['expires_at'] ?? '')) < time()
                || (int)($current['user_id'] ?? 0) !== $userId
                || (int)($current['tenant_id'] ?? 0) !== $tenantId
            ) {
                Cache::delete($cacheKey);
                throw new RuntimeException('配对码已失效，请在宿析OS重新生成。', 410);
            }
            if (!Cache::delete($cacheKey)) {
                throw new RuntimeException('配对码已被使用，请在宿析OS重新生成。', 409);
            }

            $deviceId = (int)Db::name('ota_local_collector_devices')->insertGetId([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'device_public_id' => $publicId,
                'device_token_hash' => hash('sha256', $deviceToken),
                'device_name' => $deviceName !== '' ? $deviceName : 'Windows 本机采集器',
                'device_platform' => $devicePlatform,
                'collector_version' => $collectorVersion,
                'capabilities_json' => json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'online',
                'last_seen_at' => $now,
                'last_error_code' => '',
                'last_error_summary' => '',
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if ($deviceId <= 0) {
                throw new RuntimeException('本机采集器配对失败：未取得设备ID。', 500);
            }
            return $deviceId;
        });

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'device_id' => $id,
            'device_public_id' => $publicId,
            'device_token' => $deviceToken,
            'device_name' => $deviceName,
            'status' => 'online',
            'token_notice' => '设备令牌只返回本次，请保存在账号使用者电脑；服务器仅保存哈希。',
        ];
    }

    /** @return array<string, mixed> */
    public function heartbeat(string $publicId, string $token, array $input): array
    {
        $device = $this->authenticateDevice($publicId, $token);
        $now = date('Y-m-d H:i:s');
        $update = [
            'status' => 'online',
            'last_seen_at' => $now,
            'update_time' => $now,
        ];
        $version = $this->safeIdentifier((string)($input['collector_version'] ?? ''), 40);
        if ($version !== '') {
            $update['collector_version'] = $version;
        }
        if (array_key_exists('capabilities', $input)) {
            $update['capabilities_json'] = json_encode(
                $this->sanitizeCapabilities($input['capabilities']),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }
        $deviceReadback = $this->writeActiveDevice($device, $update);
        if (!is_array($deviceReadback)) {
            throw new RuntimeException('本机采集设备状态已变化或已撤销，请重新认证。', 409);
        }
        $scheduled = ($input['skip_gap_scan'] ?? false) === true
            ? 0
            : $this->scheduleGapBackfillsForDevice($deviceReadback);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'device_status' => 'online',
            'server_time' => $now,
            'scheduled_backfill_count' => $scheduled,
            'queued_task_count' => $this->queuedTaskCount($device),
        ];
    }

    /** @return array<string, mixed> */
    public function status(mixed $user): array
    {
        $actor = $this->actorContext($user);
        $targetDate = date('Y-m-d', strtotime('-1 day'));
        if (!$this->schemaReady()) {
            $profileOrderedCollection = $this->browserProfileOrderedCollectionSnapshot(
                $actor,
                $targetDate
            );
            if ($profileOrderedCollection !== null) {
                $profileOrderedCollection = $this->publicOrderedCollectionSnapshot(
                    $profileOrderedCollection,
                    $actor['is_super_admin']
                );
                $profileFailure = $this->browserProfileSnapshotReadFailure($profileOrderedCollection);
                $profileReadFailed = $profileFailure !== null;
                $response = [
                    'status' => $profileReadFailed ? 'partial' : 'ready',
                    'contract_version' => self::CONTRACT_VERSION,
                    'collection_mode' => 'browser_profile',
                    'local_collector_required' => false,
                    'local_collector_status' => $profileReadFailed
                        ? 'browser_profile_read_failed'
                        : 'migration_missing_optional',
                    'boundary' => [
                        'server_stores' => 'Profile 数据源元数据、任务状态、结构化业务结果和真实 verifier 状态',
                        'device_only' => 'Profile、Cookie、localStorage、sessionStorage、验证码和平台登录令牌',
                    ],
                    'summary' => [
                        'device_count' => 0,
                        'online_device_count' => 0,
                        'account_count' => 0,
                        'active_account_count' => 0,
                        'attention_task_count' => 0,
                        'browser_profile_source_count' => is_numeric($profileOrderedCollection['source_count'] ?? null)
                            ? (int)$profileOrderedCollection['source_count']
                            : null,
                    ],
                    'devices' => [],
                    'accounts' => [],
                    'tasks' => [],
                    'ordered_collection' => $profileOrderedCollection,
                ];
                if ($profileReadFailed) {
                    $response['reason_code'] = (string)$profileFailure['reason_code'];
                    $response['stage'] = (string)$profileFailure['stage'];
                }
                return $response;
            }
            return [
                'status' => 'migration_required',
                'message' => '账户级本机采集表尚未初始化，请先执行数据库迁移。',
                'devices' => [],
                'accounts' => [],
                'tasks' => [],
                'ordered_collection' => null,
            ];
        }

        $devices = Db::name('ota_local_collector_devices')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('user_id', $actor['user_id'])
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $deviceMap = [];
        foreach ($devices as &$device) {
            unset($device['device_token_hash']);
            $device['effective_status'] = $this->effectiveDeviceStatus($device);
            $device['capabilities'] = $this->decodeJson($device['capabilities_json'] ?? null);
            unset($device['capabilities_json']);
            $deviceMap[(int)$device['id']] = $device;
        }
        unset($device);

        $accounts = Db::name('ota_local_collector_accounts')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('user_id', $actor['user_id'])
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $accountIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $accounts
        )));
        $accountMap = [];
        foreach ($accounts as $accountRow) {
            $accountMap[(int)($accountRow['id'] ?? 0)] = $accountRow;
        }
        $mappings = $accountIds === [] || $actor['hotel_ids'] === []
            ? []
            : Db::name('ota_local_collector_account_hotels')
                ->where('tenant_id', $actor['tenant_id'])
                ->whereIn('account_id', $accountIds)
                ->whereIn('system_hotel_id', $actor['hotel_ids'])
                ->where('status', 'active')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        $mappingsByAccount = [];
        foreach ($mappings as $mapping) {
            $mappingAccount = $accountMap[(int)($mapping['account_id'] ?? 0)] ?? null;
            if (!is_array($mappingAccount)
                || (int)($mapping['tenant_id'] ?? 0) !== (int)$actor['tenant_id']
                || strtolower(trim((string)($mapping['platform'] ?? ''))) !== strtolower(trim((string)($mappingAccount['platform'] ?? '')))
            ) {
                continue;
            }
            $mappingsByAccount[(int)$mapping['account_id']][] = $mapping;
        }

        foreach ($accounts as &$account) {
            $device = $deviceMap[(int)($account['device_id'] ?? 0)] ?? [];
            $deviceStatus = (string)($device['effective_status'] ?? 'device_offline');
            $account['device_status'] = $deviceStatus;
            $account['device_name'] = (string)($device['device_name'] ?? '未绑定设备');
            $account['profile_key_fingerprint'] = substr((string)($account['profile_key_hash'] ?? ''), 0, 12);
            unset($account['profile_key_hash']);
            $account['hotels'] = $mappingsByAccount[(int)$account['id']] ?? [];
            $account['recovery'] = $this->recoveryGuide(
                (string)($account['last_error_code'] ?? $account['session_status'] ?? ''),
                (string)($account['platform'] ?? ''),
                $deviceStatus,
                (string)($account['next_retry_at'] ?? '')
            );
        }
        unset($account);

        $tasks = $actor['hotel_ids'] === []
            ? []
            : Db::name('ota_local_collector_tasks')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('user_id', $actor['user_id'])
                ->whereIn('account_id', $accountIds)
                ->whereIn('system_hotel_id', $actor['hotel_ids'])
                ->whereIn('platform', self::PLATFORMS)
                ->order('id', 'desc')
                ->limit(80)
                ->select()
                ->toArray();
        $tasks = array_values(array_filter($tasks, function (array $task) use ($actor, $accountMap, $deviceMap, $mappingsByAccount): bool {
            $identity = $this->taskIdentity($task);
            $account = $accountMap[(int)($task['account_id'] ?? 0)] ?? null;
            $device = $deviceMap[(int)($task['device_id'] ?? 0)] ?? null;
            $hasActiveMapping = false;
            foreach ($mappingsByAccount[(int)($task['account_id'] ?? 0)] ?? [] as $mapping) {
                if ((int)($mapping['tenant_id'] ?? 0) === (int)$actor['tenant_id']
                    && (int)($mapping['account_id'] ?? 0) === (int)($task['account_id'] ?? 0)
                    && (int)($mapping['system_hotel_id'] ?? 0) === (int)($task['system_hotel_id'] ?? 0)
                    && strtolower(trim((string)($mapping['platform'] ?? ''))) === strtolower(trim((string)($task['platform'] ?? '')))
                    && (string)($mapping['status'] ?? 'active') === 'active'
                ) {
                    $hasActiveMapping = true;
                    break;
                }
            }
            return $identity !== null
                && is_array($account)
                && is_array($device)
                && (int)($account['tenant_id'] ?? 0) === (int)$actor['tenant_id']
                && (int)($account['user_id'] ?? 0) === (int)$actor['user_id']
                && (int)($account['device_id'] ?? 0) === (int)($task['device_id'] ?? 0)
                && strtolower(trim((string)($account['platform'] ?? ''))) === strtolower(trim((string)($task['platform'] ?? '')))
                && (string)($account['status'] ?? '') !== 'revoked'
                && (string)($device['effective_status'] ?? '') !== 'revoked'
                && $hasActiveMapping;
        }));
        foreach ($tasks as &$task) {
            $taskRequest = $this->decodeJson($task['request_json'] ?? null);
            $task['_ordered_missing_field_count'] = $this->privateTaskMissingFieldCount($taskRequest);
            $task['request_summary'] = $this->publicTaskRequest($taskRequest);
            unset($task['lease_token_hash'], $task['request_json']);
            $task['result_summary'] = $this->publicTaskResultSummary(
                $this->decodeJson($task['result_summary_json'] ?? null),
                $actor['is_super_admin']
            );
            unset($task['result_summary_json']);
            $task['recovery'] = $this->recoveryGuide(
                (string)($task['error_code'] ?? ''),
                (string)($task['platform'] ?? ''),
                (string)($deviceMap[(int)($task['device_id'] ?? 0)]['effective_status'] ?? 'device_offline'),
                (string)($task['available_at'] ?? '')
            );
        }
        unset($task);
        $tasks = $this->sortOrderedTasks($tasks);
        foreach ($tasks as &$task) {
            unset($task['_ordered_missing_field_count']);
        }
        unset($task);
        $profileOrderedCollection = $mappings === []
            ? $this->browserProfileOrderedCollectionSnapshot($actor, $targetDate)
            : null;
        $orderedCollection = $profileOrderedCollection
            ?? $this->orderedCollectionSnapshot(
                $accounts,
                $mappings,
                $tasks,
                $deviceMap,
                $targetDate
            );
        $orderedCollection = $this->publicOrderedCollectionSnapshot(
            $orderedCollection,
            $actor['is_super_admin']
        );
        $collectionMode = $profileOrderedCollection !== null
            ? 'browser_profile'
            : 'local_collector';

        $profileFailure = is_array($profileOrderedCollection)
            ? $this->browserProfileSnapshotReadFailure($profileOrderedCollection)
            : null;
        $profileReadFailed = $profileFailure !== null;
        $response = [
            'status' => $profileReadFailed ? 'partial' : 'ready',
            'contract_version' => self::CONTRACT_VERSION,
            'collection_mode' => $collectionMode,
            'local_collector_required' => false,
            'local_collector_status' => $profileReadFailed
                ? 'browser_profile_read_failed'
                : ($devices === [] ? 'not_registered_optional' : 'registered'),
            'boundary' => [
                'server_stores' => '设备登记、账户别名、门店映射、任务状态、失败摘要和结构化业务结果',
                'device_only' => 'Profile、Cookie、localStorage、sessionStorage、验证码和平台登录令牌',
            ],
            'summary' => [
                'device_count' => count($devices),
                'online_device_count' => count(array_filter(
                    $devices,
                    static fn(array $row): bool => ($row['effective_status'] ?? '') === 'online'
                )),
                'account_count' => count($accounts),
                'active_account_count' => count(array_filter(
                    $accounts,
                    static fn(array $row): bool => ($row['status'] ?? '') === 'active'
                )),
                'attention_task_count' => count(array_filter(
                    $tasks,
                    static fn(array $row): bool => in_array(
                        (string)($row['status'] ?? ''),
                        ['failed', 'login_required', 'verification_required', 'retry_wait'],
                        true
                    )
                )),
                'browser_profile_source_count' => is_numeric($profileOrderedCollection['source_count'] ?? null)
                    ? (int)$profileOrderedCollection['source_count']
                    : ($profileOrderedCollection === null ? 0 : null),
            ],
            'devices' => array_values($devices),
            'accounts' => array_values($accounts),
            'tasks' => array_values($tasks),
            'ordered_collection' => $orderedCollection,
        ];
        if ($profileReadFailed) {
            $response['reason_code'] = (string)$profileFailure['reason_code'];
            $response['stage'] = (string)$profileFailure['stage'];
        }
        return $response;
    }

    /** @return array{reason_code:string,stage:string}|null */
    private function browserProfileSnapshotReadFailure(array $snapshot): ?array
    {
        if (($snapshot['data_status'] ?? '') === 'read_failed') {
            return [
                'reason_code' => (string)($snapshot['reason_code'] ?? 'source_read_failed'),
                'stage' => (string)($snapshot['stage'] ?? 'browser_profile'),
            ];
        }

        foreach ((array)($snapshot['read_failures'] ?? []) as $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $reasonCode = trim((string)($failure['reason_code'] ?? ''));
            $stage = trim((string)($failure['stage'] ?? ''));
            if ($reasonCode !== '') {
                return [
                    'reason_code' => $reasonCode,
                    'stage' => $stage !== '' ? $stage : 'browser_profile',
                ];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function createAccount(mixed $user, array $input): array
    {
        $actor = $this->actorContext($user);
        $device = $this->ownedDevice($actor, (int)($input['device_id'] ?? 0));
        $platform = $this->normalizePlatform($input['platform'] ?? '');
        $alias = $this->safeText((string)($input['account_alias'] ?? ''), 120);
        if ($alias === '') {
            throw new RuntimeException('请填写平台账户别名。', 422);
        }
        $hotelId = (int)($input['system_hotel_id'] ?? 0);
        $platformHotelId = $this->normalizePlatformHotelId($input['platform_hotel_id'] ?? '');
        $platformHotelName = $this->safeText((string)($input['platform_hotel_name'] ?? ''), 160);
        $this->assertHotelPermission($actor, $hotelId);
        if ($platformHotelId === '') {
            throw new RuntimeException('请填写该账户下的 OTA 平台门店标识。', 422);
        }

        $now = date('Y-m-d H:i:s');
        $profileKeyHash = hash('sha256', random_bytes(32));
        $accountResult = Db::transaction(function () use (
            $actor,
            $device,
            $platform,
            $alias,
            $hotelId,
            $platformHotelId,
            $platformHotelName,
            $profileKeyHash,
            $now
        ): array {
            $duplicate = Db::name('ota_local_collector_accounts')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('user_id', $actor['user_id'])
                ->where('platform', $platform)
                ->where('account_alias', $alias)
                ->lock(true)
                ->find();
            if (is_array($duplicate)) {
                if ((string)($duplicate['status'] ?? '') !== 'revoked') {
                    throw new RuntimeException('同平台账户别名已存在，请直接追加门店映射。', 409);
                }

                $accountId = (int)$duplicate['id'];
                Db::name('ota_local_collector_tasks')
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('user_id', $actor['user_id'])
                    ->where('account_id', $accountId)
                    ->where('system_hotel_id', '>', 0)
                    ->where('platform', $platform)
                    ->whereIn('status', self::ACTIVE_TASK_STATUSES)
                    ->update([
                        'status' => 'cancelled',
                        'error_code' => 'device_rebound',
                        'error_summary' => '账户已在新电脑恢复，旧设备上的未完成任务停止。',
                        'finished_at' => $now,
                        'update_time' => $now,
                    ]);
                Db::name('ota_local_collector_account_hotels')
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('account_id', $accountId)
                    ->where('platform', $platform)
                    ->whereNotIn('system_hotel_id', $actor['hotel_ids'])
                    ->update([
                        'status' => 'permission_removed',
                        'update_time' => $now,
                    ]);
                $accountUpdated = Db::name('ota_local_collector_accounts')
                    ->where('id', $accountId)
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('user_id', $actor['user_id'])
                    ->where('platform', $platform)
                    ->update([
                    'device_id' => (int)$device['id'],
                    'profile_key_hash' => $profileKeyHash,
                    'status' => 'login_required',
                    'session_status' => 'login_required',
                    'last_session_verified_at' => null,
                    'last_error_code' => 'login_required',
                    'last_error_summary' => '账户已转移到新电脑，请在本机重新登录平台。',
                    'retry_count' => 0,
                    'next_retry_at' => null,
                    'update_time' => $now,
                    ]);
                if ($accountUpdated !== 1
                    || !is_array(Db::name('ota_local_collector_accounts')
                        ->where('id', $accountId)
                        ->where('tenant_id', $actor['tenant_id'])
                        ->where('user_id', $actor['user_id'])
                        ->where('platform', $platform)
                        ->find())
                ) {
                    throw new RuntimeException('恢复本机账户后身份精确回读失败。', 409);
                }
                $this->upsertHotelMapping(
                    $actor,
                    $accountId,
                    $platform,
                    $hotelId,
                    $platformHotelId,
                    $platformHotelName,
                    $now
                );
                return ['account_id' => $accountId, 'restored' => true];
            }

            $accountId = (int)Db::name('ota_local_collector_accounts')->insertGetId([
                'tenant_id' => $actor['tenant_id'],
                'user_id' => $actor['user_id'],
                'device_id' => (int)$device['id'],
                'platform' => $platform,
                'account_alias' => $alias,
                'profile_key_hash' => $profileKeyHash,
                'status' => 'login_required',
                'session_status' => 'login_required',
                'last_error_code' => 'login_required',
                'last_error_summary' => '请在账号使用者电脑完成平台登录。',
                'retry_count' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $this->upsertHotelMapping(
                $actor,
                $accountId,
                $platform,
                $hotelId,
                $platformHotelId,
                $platformHotelName,
                $now
            );
            return ['account_id' => $accountId, 'restored' => false];
        });
        $accountId = (int)$accountResult['account_id'];
        $restored = ($accountResult['restored'] ?? false) === true;
        $mappingReadback = $this->mappingReadbackReceipt(
            $actor,
            $accountId,
            $hotelId,
            $platform,
            $platformHotelId,
            'active'
        );
        if (($mappingReadback['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('本机采集账户创建后门店映射精确回读失败。', 500);
        }

        return [
            'status' => $restored ? 'restored' : 'created',
            'tenant_id' => (int)$actor['tenant_id'],
            'account_id' => $accountId,
            'device_id' => (int)$device['id'],
            'platform' => $platform,
            'account_alias' => $alias,
            'system_hotel_id' => $hotelId,
            'platform_hotel_id' => $platformHotelId,
            'mapping_readback' => $mappingReadback,
            'profile_key_fingerprint' => substr($profileKeyHash, 0, 12),
            'next_action' => $restored
                ? '账户已转移到新电脑；请在本机重新登录平台，验证后继续采集。'
                : '创建登录任务，并在该账户使用者电脑完成平台验证。',
        ];
    }

    /** @return array<string, mixed> */
    public function bindHotel(mixed $user, int $accountId, array $input): array
    {
        $actor = $this->actorContext($user);
        $account = $this->ownedAccount($actor, $accountId);
        $hotelId = (int)($input['system_hotel_id'] ?? 0);
        $platformHotelId = $this->normalizePlatformHotelId($input['platform_hotel_id'] ?? '');
        $platformHotelName = $this->safeText((string)($input['platform_hotel_name'] ?? ''), 160);
        $this->assertHotelPermission($actor, $hotelId);
        if ($platformHotelId === '') {
            throw new RuntimeException('请填写 OTA 平台门店标识。', 422);
        }
        $mapping = Db::transaction(fn(): array => $this->upsertHotelMapping(
            $actor,
            (int)$account['id'],
            (string)$account['platform'],
            $hotelId,
            $platformHotelId,
            $platformHotelName,
            date('Y-m-d H:i:s')
        ));
        $mappingReadback = $this->mappingReadbackReceipt(
            $actor,
            (int)$account['id'],
            $hotelId,
            (string)$account['platform'],
            $platformHotelId,
            'active',
            (int)$mapping['mapping_id']
        );
        if (($mappingReadback['readback_verified'] ?? false) !== true) {
            throw new RuntimeException('本机采集门店绑定后身份精确回读失败。', 500);
        }

        return [
            'status' => 'bound',
            'tenant_id' => (int)$actor['tenant_id'],
            'write_action' => (string)$mapping['write_action'],
            'mapping_id' => (int)$mapping['mapping_id'],
            'account_id' => (int)$account['id'],
            'previous_mapping_id' => (int)$mapping['previous_mapping_id'],
            'previous_account_id' => (int)$mapping['previous_account_id'],
            'system_hotel_id' => $hotelId,
            'platform' => (string)$account['platform'],
            'platform_hotel_id' => $platformHotelId,
            'mapping_status' => (string)$mapping['mapping_status'],
            'data_source_id' => (int)$mapping['data_source_id'],
            'readback_verified' => (bool)$mapping['readback_verified'],
            'mapping_readback' => $mappingReadback,
        ];
    }

    /** @return array<string, mixed> */
    public function unbindHotel(mixed $user, int $accountId, int $hotelId): array
    {
        $actor = $this->actorContext($user);
        $account = $this->ownedAccount($actor, $accountId);
        $this->assertHotelPermission($actor, $hotelId);
        $now = date('Y-m-d H:i:s');

        $outcome = Db::transaction(function () use ($actor, $account, $hotelId, $now): array {
            $mapping = Db::name('ota_local_collector_account_hotels')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('account_id', (int)$account['id'])
                ->where('system_hotel_id', $hotelId)
                ->where('platform', (string)$account['platform'])
                ->lock(true)
                ->find();
            if (!is_array($mapping)) {
                throw new RuntimeException('该采集账号尚未绑定目标门店。', 404);
            }

            $alreadyUnbound = (string)($mapping['status'] ?? '') === 'unbound';
            if (!$alreadyUnbound) {
                Db::name('ota_local_collector_account_hotels')
                    ->where('id', (int)$mapping['id'])
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('account_id', (int)$account['id'])
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', (string)$account['platform'])
                    ->update([
                        'status' => 'unbound',
                        'update_time' => $now,
                    ]);
            }

            $cancelledTaskCount = (int)Db::name('ota_local_collector_tasks')
                ->where('tenant_id', $actor['tenant_id'])
                ->where('user_id', $actor['user_id'])
                ->where('account_id', (int)$account['id'])
                ->where('system_hotel_id', $hotelId)
                ->where('platform', (string)$account['platform'])
                ->whereIn('status', self::ACTIVE_TASK_STATUSES)
                ->whereNull('finished_at')
                ->update([
                    'status' => 'cancelled',
                    'error_code' => 'hotel_unbound',
                    'error_summary' => '门店已从本机采集账号解绑，绑定到该门店的未完成任务已取消。',
                    'finished_at' => $now,
                    'update_time' => $now,
                ]);

            $dataSourceId = (int)($mapping['data_source_id'] ?? 0);
            $dataSourceStatus = 'not_linked';
            if ($dataSourceId > 0) {
                $source = Db::name('platform_data_sources')
                    ->where('id', $dataSourceId)
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', (string)$account['platform'])
                    ->where('ingestion_method', 'local_collector')
                    ->lock(true)
                    ->find();
                if (!is_array($source)) {
                    $dataSourceStatus = 'scope_mismatch';
                } else {
                    Db::name('platform_data_sources')
                        ->where('id', $dataSourceId)
                        ->where('tenant_id', $actor['tenant_id'])
                        ->where('system_hotel_id', $hotelId)
                        ->where('platform', (string)$account['platform'])
                        ->where('ingestion_method', 'local_collector')
                        ->update([
                            'enabled' => 0,
                            'status' => 'disabled',
                            'last_sync_status' => 'disabled',
                            'last_error' => 'local_collector_hotel_unbound',
                        ]);
                    $dataSourceStatus = 'disabled';
                }
            }

            return [
                'mapping_id' => (int)$mapping['id'],
                'already_unbound' => $alreadyUnbound,
                'cancelled_task_count' => $cancelledTaskCount,
                'data_source_id' => $dataSourceId,
                'data_source_status' => $dataSourceStatus,
            ];
        });

        $mappingPlatformHotelId = (string)Db::name('ota_local_collector_account_hotels')
            ->where('id', (int)$outcome['mapping_id'])
            ->where('tenant_id', (int)$actor['tenant_id'])
            ->where('account_id', (int)$account['id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', (string)$account['platform'])
            ->where('status', 'unbound')
            ->value('platform_hotel_id');
        $mappingReadback = $this->mappingReadbackReceipt(
            $actor,
            (int)$account['id'],
            $hotelId,
            (string)$account['platform'],
            $mappingPlatformHotelId,
            'unbound',
            (int)$outcome['mapping_id']
        );
        $mappingStatus = (string)($mappingReadback['mapping_status'] ?? 'missing');
        $remainingActiveHotelCount = (int)Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('account_id', (int)$account['id'])
            ->where('platform', (string)$account['platform'])
            ->where('status', 'active')
            ->count();
        $remainingActiveTaskCount = (int)Db::name('ota_local_collector_tasks')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('user_id', $actor['user_id'])
            ->where('account_id', (int)$account['id'])
            ->where('system_hotel_id', $hotelId)
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->whereNull('finished_at')
            ->count();
        $dataSourceReadbackVerified = (int)$outcome['data_source_id'] <= 0
            || ((string)$outcome['data_source_status'] === 'disabled'
                && (int)Db::name('platform_data_sources')
                    ->where('id', (int)$outcome['data_source_id'])
                    ->where('tenant_id', $actor['tenant_id'])
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', (string)$account['platform'])
                    ->where('ingestion_method', 'local_collector')
                    ->where('enabled', 0)
                    ->where('status', 'disabled')
                    ->count() === 1);
        $readbackVerified = ($mappingReadback['readback_verified'] ?? false) === true
            && $remainingActiveTaskCount === 0
            && $dataSourceReadbackVerified;

        return [
            'status' => $readbackVerified ? 'unbound' : 'unbound_with_warning',
            'tenant_id' => (int)$actor['tenant_id'],
            'mapping_id' => (int)$outcome['mapping_id'],
            'mapping_status' => $mappingStatus,
            'account_id' => (int)$account['id'],
            'system_hotel_id' => $hotelId,
            'platform' => (string)$account['platform'],
            'already_unbound' => (bool)$outcome['already_unbound'],
            'cancelled_task_count' => (int)$outcome['cancelled_task_count'],
            'data_source_id' => (int)$outcome['data_source_id'],
            'data_source_status' => (string)$outcome['data_source_status'],
            'remaining_active_hotel_count' => $remainingActiveHotelCount,
            'readback_verified' => $readbackVerified,
            'mapping_readback' => $mappingReadback,
        ];
    }

    /** @return array<string, mixed> */
    public function createTask(mixed $user, array $input): array
    {
        $actor = $this->actorContext($user);
        $account = $this->ownedAccount($actor, (int)($input['account_id'] ?? 0));
        $hotelId = (int)($input['system_hotel_id'] ?? 0);
        $mapping = $this->mappingForAccountHotel(
            (int)$actor['tenant_id'],
            (int)$account['id'],
            $hotelId,
            (string)$account['platform']
        );
        $this->assertHotelPermission($actor, $hotelId);
        $taskType = strtolower(trim((string)($input['task_type'] ?? 'collect')));
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            throw new RuntimeException('不支持的本机采集任务类型。', 422);
        }

        $dataDate = in_array($taskType, ['login', 'session_probe'], true)
            ? null
            : $this->normalizeDate((string)($input['data_date'] ?? date('Y-m-d', strtotime('-1 day'))));
        if (!in_array($taskType, ['login', 'session_probe'], true) && $dataDate === '') {
            throw new RuntimeException('采集日期格式不正确。', 422);
        }
        $dataType = $this->safeIdentifier((string)($input['data_type'] ?? 'business'), 50) ?: 'business';
        $requestedAt = date('Y-m-d H:i:s');
        $reason = $this->safeText((string)($input['reason'] ?? ''), 180);
        $request = ['sections' => [], 'reason' => $reason, 'requested_at' => $requestedAt];
        if (in_array($taskType, ['collect', 'backfill'], true)) {
            $missingFieldKeys = $this->sanitizeFieldKeys($input['missing_field_keys'] ?? []);
            if ($taskType === 'backfill' && $missingFieldKeys === []) {
                $missingFieldKeys = $this->currentMissingFieldKeys(
                    (string)$account['platform'],
                    (int)$mapping['system_hotel_id'],
                    (string)$dataDate
                );
            }
            $plan = OtaOrderedCollectionPlanner::requestPlan(
                (string)$account['platform'],
                (string)$dataDate,
                $missingFieldKeys,
                $reason !== '' ? $reason : ($taskType === 'backfill' ? 'targeted_gap_recovery' : 'yesterday_core')
            );
            $request['sections'] = $plan['sections'];
            $request['ordered_collection'] = $plan;

            if ((string)($account['session_status'] ?? '') !== 'current_session_verified') {
                $resumeCollection = [
                    'task_type' => $taskType,
                    'system_hotel_id' => $hotelId,
                    'data_date' => $dataDate,
                    'data_type' => $dataType,
                    'priority' => (int)($input['priority'] ?? 50),
                    'request' => $request,
                ];
                $preflight = $this->enqueueTask(
                    $actor,
                    $account,
                    $mapping,
                    'session_probe',
                    null,
                    'session',
                    [
                        'sections' => [],
                        'reason' => 'account_session_preflight',
                        'requested_at' => $requestedAt,
                        'resume_collections' => [$resumeCollection],
                    ],
                    false,
                    95,
                    true
                );
                $this->appendResumeCollection($preflight, $resumeCollection);

                return [
                    'status' => (string)($preflight['status'] ?? 'queued'),
                    'task' => $this->publicTask($preflight),
                    'preflight_for_task_type' => $taskType,
                    'target_date' => $dataDate,
                    'device_status' => $this->effectiveDeviceStatus(
                        $this->ownedDevice($actor, (int)$account['device_id'])
                    ),
                    'next_action' => '先复用本机账户 Profile 检查登录态和目标门店；通过后自动进入目标日期采集，无需重复登录。',
                ];
            }
        }
        $force = ($input['force'] ?? false) === true;
        $task = $this->enqueueTask(
            $actor,
            $account,
            $mapping,
            $taskType,
            $dataDate,
            $dataType,
            $request,
            $force,
            (int)($input['priority'] ?? 50),
            true
        );

        return [
            'status' => (string)($task['status'] ?? 'queued'),
            'task' => $this->publicTask($task),
            'device_status' => $this->effectiveDeviceStatus(
                $this->ownedDevice($actor, (int)$account['device_id'])
            ),
            'next_action' => '保持账号使用者电脑上的本机采集器运行；任务将由该设备领取。',
        ];
    }

    /** @return array<string, mixed> */
    public function revokeDevice(mixed $user, int $deviceId): array
    {
        $actor = $this->actorContext($user);
        $device = $this->ownedDevice($actor, $deviceId);
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($device, $now): void {
            $deviceValues = [
                'status' => 'revoked',
                'device_token_hash' => hash('sha256', random_bytes(32)),
                'update_time' => $now,
            ];
            $deviceUpdated = Db::name('ota_local_collector_devices')
                ->where('id', (int)$device['id'])
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->update($deviceValues);
            $deviceReadback = Db::name('ota_local_collector_devices')
                ->where('id', (int)$device['id'])
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->find();
            if ($deviceUpdated !== 1
                || !is_array($deviceReadback)
                || !$this->exactWriteReadbackMatches($deviceReadback, $deviceValues)
            ) {
                throw new RuntimeException('撤销设备身份回写失败。', 409);
            }
            $accounts = Db::name('ota_local_collector_accounts')
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->where('device_id', (int)$device['id'])
                ->whereIn('platform', self::PLATFORMS)
                ->select()
                ->toArray();
            foreach ($accounts as $account) {
                $accountValues = [
                    'status' => 'revoked',
                    'session_status' => 'device_revoked',
                    'last_error_code' => 'device_revoked',
                    'last_error_summary' => '设备已撤销，需要在新电脑重新配对和登录。',
                    'update_time' => $now,
                ];
                $accountUpdated = Db::name('ota_local_collector_accounts')
                    ->where('id', (int)$account['id'])
                    ->where('tenant_id', (int)$device['tenant_id'])
                    ->where('user_id', (int)$device['user_id'])
                    ->where('device_id', (int)$device['id'])
                    ->where('platform', (string)$account['platform'])
                    ->update($accountValues);
                $accountReadback = Db::name('ota_local_collector_accounts')
                    ->where('id', (int)$account['id'])
                    ->where('tenant_id', (int)$device['tenant_id'])
                    ->where('user_id', (int)$device['user_id'])
                    ->where('device_id', (int)$device['id'])
                    ->where('platform', (string)$account['platform'])
                    ->find();
                if ($accountUpdated !== 1
                    || !is_array($accountReadback)
                    || !$this->exactWriteReadbackMatches($accountReadback, $accountValues)
                ) {
                    throw new RuntimeException('撤销设备账户身份回写后精确回读失败。', 409);
                }
            }
            $tasks = Db::name('ota_local_collector_tasks')
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->where('device_id', (int)$device['id'])
                ->where('account_id', '>', 0)
                ->where('system_hotel_id', '>', 0)
                ->whereIn('platform', self::PLATFORMS)
                ->whereIn('status', self::ACTIVE_TASK_STATUSES)
                ->select()
                ->toArray();
            foreach ($tasks as $task) {
                if ($this->taskIdentity($task) === null) {
                    throw new RuntimeException('鎾ら攢璁惧鏃犳硶楠岃瘉浠诲姟韬唤锛岄儴鍒嗕换鍔″凡鎷掔粷鎾ら攢銆?', 409);
                }
                $this->requireScopedTaskWrite($task, [
                    'status' => 'cancelled',
                    'error_code' => 'device_revoked',
                    'error_summary' => '设备已撤销，任务停止。',
                    'finished_at' => $now,
                    'update_time' => $now,
                ], true);
            }
        });

        return [
            'status' => 'revoked',
            'device_id' => (int)$device['id'],
            'recover' => '更换电脑时请在新设备重新生成配对并重新登录；服务器不会远程复制或删除旧 Profile。',
        ];
    }

    /** @return array<string, mixed> */
    public function nextTask(string $publicId, string $token): array
    {
        $device = $this->authenticateDevice($publicId, $token);
        if (!$this->touchDevice($device)) {
            throw new RuntimeException('本机采集设备状态已变化或已撤销，请重新认证。', 409);
        }
        $this->recoverExpiredLeases($device);
        $now = date('Y-m-d H:i:s');
        $permittedHotelIds = $this->devicePermittedHotelIds($device);
        if ($permittedHotelIds === []) {
            return [
                'status' => 'idle',
                'task' => null,
                'poll_after_seconds' => 15,
            ];
        }

        $leased = Db::transaction(function () use ($device, $now, $permittedHotelIds): ?array {
            $candidates = Db::name('ota_local_collector_tasks')
                ->where('device_id', (int)$device['id'])
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->where('account_id', '>', 0)
                ->whereIn('system_hotel_id', $permittedHotelIds)
                ->where('system_hotel_id', '>', 0)
                ->whereIn('platform', self::PLATFORMS)
                ->whereIn('status', ['queued', 'retry_wait'])
                ->where('available_at', '<=', $now)
                ->order('account_id', 'asc')
                ->order('system_hotel_id', 'asc')
                ->order('platform', 'asc')
                ->order('data_date', 'desc')
                ->order('id', 'asc')
                ->lock(true)
                ->limit(200)
                ->select()
                ->toArray();
            if ($candidates === []) {
                return null;
            }
            $candidates = array_values(array_filter($candidates, function (array $candidate) use ($device): bool {
                if ($this->taskIdentity($candidate) === null) {
                    return false;
                }
                try {
                    $account = $this->scopedAccountQuery($candidate)
                        ->where('device_id', (int)$device['id'])
                        ->find();
                    if (!is_array($account)
                        || (int)($account['device_id'] ?? 0) !== (int)$device['id']
                        || (string)($account['status'] ?? '') === 'revoked'
                    ) {
                        return false;
                    }
                    $mapping = $this->mappingForAccountHotel(
                        (int)$candidate['tenant_id'],
                        (int)$candidate['account_id'],
                        (int)$candidate['system_hotel_id'],
                        (string)$candidate['platform']
                    );
                    return (int)($mapping['tenant_id'] ?? 0) === (int)$candidate['tenant_id']
                        && (int)($mapping['account_id'] ?? 0) === (int)$candidate['account_id']
                        && (int)($mapping['system_hotel_id'] ?? 0) === (int)$candidate['system_hotel_id']
                        && strtolower(trim((string)($mapping['platform'] ?? ''))) === strtolower(trim((string)$candidate['platform']));
                } catch (Throwable) {
                    return false;
                }
            }));
            if ($candidates === []) {
                return null;
            }
            $candidates = $this->sortOrderedTasks($candidates);
            $task = $candidates[0];
            $leaseToken = 'lease_' . bin2hex(random_bytes(24));
            $leaseExpiresAt = date('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
            $leaseValues = [
                'status' => 'leased',
                'attempt' => (int)($task['attempt'] ?? 0) + 1,
                'lease_token_hash' => hash('sha256', $leaseToken),
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $task['started_at'] ?: $now,
                'update_time' => $now,
            ];
            $updated = $this->scopedTaskQuery($task, true)
                ->whereIn('status', ['queued', 'retry_wait'])
                ->update($leaseValues);
            if ($updated !== 1) {
                return null;
            }
            $leasedTask = $this->scopedTaskQuery($task, true)
                ->where('status', 'leased')
                ->find();
            if (!is_array($leasedTask)
                || !$this->exactWriteReadbackMatches($leasedTask, $leaseValues)
            ) {
                throw new RuntimeException('本机任务领用回写后精确回读失败。', 409);
            }
            $leasedTask['lease_token'] = $leaseToken;
            return $leasedTask;
        });

        if (!is_array($leased)) {
            return [
                'status' => 'idle',
                'task' => null,
                'poll_after_seconds' => 15,
            ];
        }

        $this->assertTaskIdentity($leased, $device);
        $this->assertDeviceTaskPermission($device, $leased);

        $account = Db::name('ota_local_collector_accounts')
            ->where('id', (int)$leased['account_id'])
            ->where('tenant_id', (int)$leased['tenant_id'])
            ->where('user_id', (int)$leased['user_id'])
            ->where('device_id', (int)$leased['device_id'])
            ->where('platform', (string)$leased['platform'])
            ->find();
        if (!is_array($account)
            || (int)($account['device_id'] ?? 0) !== (int)$device['id']
            || (int)($account['tenant_id'] ?? 0) !== (int)$device['tenant_id']
            || (string)($account['status'] ?? '') === 'revoked'
        ) {
            if (!$this->failLeasedTask($leased, 'permission_denied', '任务账户与设备范围不一致。')) {
                throw new RuntimeException('任务身份回写失败，已拒绝继续。', 409);
            }
            throw new RuntimeException('任务账户与设备范围不一致。', 403);
        }

        try {
            $mapping = $this->mappingForAccountHotel(
                (int)$leased['tenant_id'],
                (int)$leased['account_id'],
                (int)$leased['system_hotel_id'],
                (string)$leased['platform']
            );
        } catch (Throwable $e) {
            if (!$this->failLeasedTask($leased, 'permission_denied', 'task_mapping_invalid')) {
                throw new RuntimeException('task_mapping_writeback_failed', 409, $e);
            }
            throw new RuntimeException('task_mapping_invalid', 403, $e);
        }

        return [
            'status' => 'leased',
            'task' => [
                'contract_version' => self::CONTRACT_VERSION,
                'id' => (int)$leased['id'],
                'tenant_id' => (int)$leased['tenant_id'],
                'lease_token' => (string)$leased['lease_token'],
                'lease_expires_at' => (string)$leased['lease_expires_at'],
                'task_type' => (string)$leased['task_type'],
                'platform' => (string)$leased['platform'],
                'account_id' => (int)$account['id'],
                'account_alias' => (string)$account['account_alias'],
                'profile_key_hash' => (string)$account['profile_key_hash'],
                'system_hotel_id' => (int)$leased['system_hotel_id'],
                'platform_hotel_id' => (string)$mapping['platform_hotel_id'],
                'platform_hotel_name' => (string)($mapping['platform_hotel_name'] ?? ''),
                'data_date' => (string)($leased['data_date'] ?? ''),
                'data_type' => (string)$leased['data_type'],
                'attempt' => (int)$leased['attempt'],
                'max_attempts' => (int)$leased['max_attempts'],
                'request' => $this->leasedTaskRequest(
                    $this->decodeJson($leased['request_json'] ?? null)
                ),
                'privacy_boundary' => '只上传结构化业务行和脱敏状态，不上传 Cookie、Profile、Authorization 或浏览器存储。',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function updateTaskProgress(
        string $publicId,
        string $token,
        int $taskId,
        array $input
    ): array {
        $device = $this->authenticateDevice($publicId, $token);
        $task = $this->leasedTask($device, $taskId, (string)($input['lease_token'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? 'running')));
        if (!in_array($status, ['running', 'waiting_user_login', 'verification_required'], true)) {
            throw new RuntimeException('不支持的本机任务进度状态。', 422);
        }
        $message = $this->safeText((string)($input['message'] ?? ''), 300);
        $now = date('Y-m-d H:i:s');
        $leaseExpiresAt = date('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        $task = Db::transaction(function () use ($task, $device, $status, $message, $leaseExpiresAt, $now): array {
            $updatedTask = $this->requireLeasedTaskWrite($task, [
                'status' => $status,
                'error_summary' => $message,
                'lease_expires_at' => $leaseExpiresAt,
                'update_time' => $now,
            ], null, $now);
            if (in_array($status, ['waiting_user_login', 'verification_required'], true)) {
                $this->requireScopedAccountWrite(
                    $task,
                    (int)$device['id'],
                    [
                    'status' => $status,
                    'session_status' => $status,
                    'last_error_code' => $status,
                    'last_error_summary' => $message,
                    'update_time' => $now,
                    ],
                    '本机任务账号状态回写后精确回读失败。'
                );
            }
            return $updatedTask;
        });
        $this->touchDevice($device);

        return [
            'status' => $status,
            'task_id' => $taskId,
            'lease_expires_at' => $leaseExpiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function submitTaskResult(
        string $publicId,
        string $token,
        int $taskId,
        array $input,
        int $rawBytes = 0
    ): array {
        if ($rawBytes > self::MAX_RESULT_BYTES) {
            throw new RuntimeException('本机采集结果超过 3MB 上限，请缩小模块范围后重试。', 413);
        }
        $inspectableInput = $input;
        unset($inspectableInput['lease_token']);
        $this->assertNoSensitiveMaterial($inspectableInput);
        $device = $this->authenticateDevice($publicId, $token);
        $task = $this->leasedTask($device, $taskId, (string)($input['lease_token'] ?? ''));
        $account = $this->scopedAccountQuery($task)
            ->where('device_id', (int)$device['id'])
            ->find();
        $mapping = $this->mappingForAccountHotel(
            (int)$task['tenant_id'],
            (int)$task['account_id'],
            (int)$task['system_hotel_id'],
            (string)$task['platform']
        );
        if (!is_array($account)) {
            throw new RuntimeException('本机采集账户不存在。', 404);
        }

        $success = ($input['success'] ?? false) === true
            || in_array(strtolower(trim((string)($input['status'] ?? ''))), ['success', 'succeeded'], true);
        if (!$success) {
            return $this->handleTaskFailure(
                $task,
                $account,
                $device,
                $this->normalizeFailureCode($input['error_code'] ?? $input['status'] ?? 'collection_failed'),
                $this->safeText((string)($input['error_summary'] ?? $input['message'] ?? '本机采集失败'), 500)
            );
        }

        if (in_array((string)$task['task_type'], ['login', 'session_probe'], true)) {
            $sessionStatus = strtolower(trim((string)($input['session_status'] ?? '')));
            if (!in_array($sessionStatus, ['current_session_verified', 'logged_in', 'authorized'], true)) {
                return $this->handleTaskFailure(
                    $task,
                    $account,
                    $device,
                    $this->normalizeFailureCode($sessionStatus ?: 'login_required'),
                    $this->safeText((string)($input['message'] ?? '平台当前会话未通过验证。'), 500)
                );
            }
            return $this->finishLoginTask($task, $account, $device, $input);
        }

        $captureSummary = $this->sanitizeCaptureSummary($input['capture_summary'] ?? []);
        $identityError = $this->capturedIdentityError($captureSummary, $mapping);
        if ($identityError !== '') {
            $identity = is_array($captureSummary['platform_identity_validation'] ?? null)
                ? $captureSummary['platform_identity_validation']
                : [];
            $identityStatus = strtolower(trim((string)($identity['status'] ?? 'unverified')));
            $validatedIdentifier = strtolower(trim((string)($identity['validated_identifier'] ?? '')));
            $identityConflict = $identityStatus === 'mismatch'
                || ($identityStatus === 'matched'
                    && ($identity['source_validation'] ?? false) === true
                    && $validatedIdentifier !== ''
                    && !hash_equals(
                        strtolower((string)$mapping['platform_hotel_id']),
                        $validatedIdentifier
                    ));
            return $this->handleTaskFailure(
                $task,
                $account,
                $device,
                $identityConflict ? 'identity_mismatch' : 'identity_unverified',
                $identityError
            );
        }

        $rows = is_array($input['rows'] ?? null) ? array_values($input['rows']) : [];
        if ($rows === []) {
            return $this->handleTaskFailure($task, $account, $device, 'zero_rows', '目标日期未采集到业务行，未用空数据冒充成功。');
        }
        if (count($rows) > self::MAX_ROWS_PER_RESULT) {
            throw new RuntimeException('单次本机采集最多上传 2000 行，请拆分模块。', 413);
        }
        $rows = $this->normalizeCollectionRows($rows, $task, $mapping);
        $request = $this->decodeJson($task['request_json'] ?? null);
        $orderedPlan = is_array($request['ordered_collection'] ?? null)
            ? $request['ordered_collection']
            : OtaOrderedCollectionPlanner::requestPlan(
                (string)$task['platform'],
                (string)$task['data_date'],
                [],
                'legacy_ordered_collection_task'
            );
        $capturedFieldKeys = OtaOrderedCollectionPlanner::capturedFieldKeys((string)$task['platform'], $rows);
        $missingFieldKeys = OtaOrderedCollectionPlanner::missingFieldKeys((string)$task['platform'], $rows);

        Db::startTrans();
        try {
            $task = $this->lockLeasedTaskForImport($device, $task);
            $account = $this->scopedAccountQuery($task)
                ->where('device_id', (int)$device['id'])
                ->find();
            $mapping = $this->mappingForAccountHotel(
                (int)$task['tenant_id'],
                (int)$task['account_id'],
                (int)$task['system_hotel_id'],
                (string)$task['platform']
            );
            if (!is_array($account)) {
                throw new RuntimeException('本机采集账户在导入前已不可用。', 409);
            }
            $owner = User::find((int)$device['user_id']);
            if (!$owner || (int)($owner->status ?? 0) !== 1) {
                throw new RuntimeException('设备所属账号已停用。', 403);
            }
            $importResult = $this->collectionImporter !== null
                ? (array)call_user_func($this->collectionImporter, $owner, $task, $account, $mapping, $device, $rows)
                : $this->importCollectionRows(
                    $owner,
                    $task,
                    $account,
                    $mapping,
                    $device,
                    $rows,
                    $captureSummary,
                    $orderedPlan
                );

            $deterministicReadback = $this->sanitizeDeterministicReadbackSet(
            $importResult['deterministic_readback'] ?? []
        );
        $rawRunReadback = is_array($importResult['run_readback'] ?? null)
            ? $importResult['run_readback']
            : [];
        if ($this->collectionImporter === null && $rawRunReadback !== []) {
            $rawRunReadback['tenant_id'] = (int)($deterministicReadback['tenant_id'] ?? 0);
        }

        $savedCount = (int)($importResult['saved_count'] ?? 0);
        $readbackVerified = ($importResult['readback_verified'] ?? false) === true;
        if ($savedCount <= 0 || !$readbackVerified) {
            throw new RuntimeException('服务器未完成保存与数据库回读，任务不标记为成功。');
        }

        $now = date('Y-m-d H:i:s');
        $syncDiagnostics = $this->sanitizeSyncDiagnostics($importResult['sync_diagnostics'] ?? []);
        $syncStatus = strtolower(trim((string)($importResult['status'] ?? 'unknown')));
        $runReadback = $this->sanitizeRunReadbackReceipt($rawRunReadback);
        $mappingDataSourceId = $this->mappingDataSourceIdAfterImport($task, $mapping);
        $runReadbackScopeVerified = $this->runReadbackMatchesImporterResult(
            $task,
            $importResult,
            $runReadback,
            $deterministicReadback,
            $mappingDataSourceId
        );
        $strictReadbackRequired = $syncStatus === 'success'
            || $rawRunReadback !== []
            || $deterministicReadback !== [];
        if ($strictReadbackRequired && !$runReadbackScopeVerified) {
            throw new RuntimeException('服务器保存结果的租户、来源、同步任务、酒店、平台、日期或行集合回读凭据不一致。');
        }
        $syncP0Status = strtolower(trim((string)($syncDiagnostics['p0_status'] ?? 'unknown')));
        $missingFieldKeys = $this->currentMissingFieldKeys(
            (string)$task['platform'],
            (int)$task['system_hotel_id'],
            (string)$task['data_date'],
            $rows,
            [
                'tenant_id' => (int)$task['tenant_id'],
                'account_id' => (int)$task['account_id'],
                'system_hotel_id' => (int)$task['system_hotel_id'],
                'platform' => (string)$task['platform'],
                'data_source_id' => $mappingDataSourceId,
                'sync_task_id' => $this->importerSyncTaskId($importResult),
                'deterministic_row_ids' => $deterministicReadback['row_ids'] ?? [],
                'readback_verified' => $runReadbackScopeVerified,
            ]
        );
        $requiresTraffic = ($syncDiagnostics['requires_target_date_traffic'] ?? false) === true;
        $p0Satisfied = $requiresTraffic
            ? $syncP0Status === 'ready'
            : in_array($syncP0Status, ['ready', 'not_required'], true);
        $firstNormalizedRow = is_array($rows[0] ?? null) ? $rows[0] : [];
        $sourceCaptureId = (string)($captureSummary['capture_id'] ?? '');
        if ($sourceCaptureId === '') {
            $sourceCaptureId = $this->firstText($firstNormalizedRow, ['capture_id', 'captureId', 'source_trace_id']);
        }
        $sourceFetchedAt = (string)($captureSummary['fetched_at'] ?? '');
        if ($sourceFetchedAt === '') {
            $sourceFetchedAt = $this->firstText($firstNormalizedRow, ['fetched_at', 'fetchedAt', '_fetch_time']);
        }
        $summary = [
            'source_method' => 'local_account_profile',
            'scope_identity' => [
                'tenant_id' => (int)$task['tenant_id'],
                'account_id' => (int)$task['account_id'],
                'system_hotel_id' => (int)$task['system_hotel_id'],
                'platform' => (string)$task['platform'],
                'platform_hotel_id' => (string)$mapping['platform_hotel_id'],
                'business_date' => (string)$task['data_date'],
                'capture_task_id' => (int)$task['id'],
                'source_capture_id' => $sourceCaptureId !== '' ? $sourceCaptureId : null,
                'source_fetched_at' => $sourceFetchedAt !== '' ? $sourceFetchedAt : null,
                'receipt_saved_at' => $now,
            ],
            'data_date' => (string)$task['data_date'],
            'row_count' => count($rows),
            'normalized_count' => (int)($importResult['normalized_count'] ?? count($rows)),
            'saved_count' => $savedCount,
            'readback_count' => (int)($importResult['readback_count'] ?? $savedCount),
            'readback_verified' => true,
            'data_source_id' => (int)($importResult['data_source_id'] ?? 0),
            'sync_task_id' => (int)($importResult['task_id'] ?? $importResult['sync_task_id'] ?? 0),
            'sync_status' => $syncStatus,
            'sync_diagnostics' => $syncDiagnostics,
            'run_readback' => $runReadback,
            'deterministic_readback' => $deterministicReadback,
            'run_readback_scope_verified' => $runReadbackScopeVerified,
            'capture_summary' => $captureSummary,
            'ordered_collection' => [
                'contract_version' => OtaOrderedCollectionPlanner::CONTRACT_VERSION,
                'scope' => 'ota_yesterday_core',
                'target_date' => (string)$task['data_date'],
                'requested_sections' => array_values((array)($orderedPlan['sections'] ?? [])),
                'expected_interface_ids' => array_values((array)($orderedPlan['interface_ids'] ?? [])),
                'captured_field_keys' => $capturedFieldKeys,
                'missing_field_keys' => $missingFieldKeys,
                'field_completeness' => count($capturedFieldKeys) . '/'
                    . count(OtaOrderedCollectionPlanner::requiredFieldKeys((string)$task['platform'])),
                'p0_status' => $syncP0Status,
            ],
        ];
        $fieldGap = $missingFieldKeys !== []
            || $syncStatus !== 'success'
            || !$p0Satisfied;
        if ($fieldGap) {
            $this->requireLeasedTaskWrite($task, [
                'result_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => $now,
            ], null, $now);
            $gapParts = [];
            if ($missingFieldKeys !== []) {
                $gapParts[] = '缺少字段：' . implode(', ', $missingFieldKeys);
            }
            if (!$p0Satisfied) {
                $gapParts[] = '真实 P0 校验=' . ($syncP0Status !== '' ? $syncP0Status : 'unknown');
            }
            if ($syncStatus !== 'success') {
                $gapParts[] = '同步状态=' . ($syncStatus !== '' ? $syncStatus : 'unknown');
            }
            $failure = $this->handleTaskFailure(
                $task,
                $account,
                $device,
                'field_gap',
                '目标日期数据已保存并回读，但尚未达到正式门禁：' . implode('；', $gapParts)
            );
            Db::commit();
            return $failure;
        }
        Db::transaction(function () use ($task, $account, $summary, $now): void {
            $this->requireLeasedTaskWrite($task, [
                'status' => 'success',
                'result_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_code' => '',
                'error_summary' => '',
                'lease_token_hash' => '',
                'lease_expires_at' => null,
                'finished_at' => $now,
                'update_time' => $now,
            ], null, $now);
            $this->requireScopedAccountWrite(
                $task,
                (int)$task['device_id'],
                [
                'status' => 'active',
                'session_status' => 'current_session_verified',
                'last_success_at' => $now,
                'last_error_code' => '',
                'last_error_summary' => '',
                'retry_count' => 0,
                'next_retry_at' => null,
                'update_time' => $now,
                ],
                '采集成功后账号状态精确回读失败。'
            );
        });
        $summary['dual_ota_authority'] = $this->refreshDualOtaAuthorityReceipt(
            $task
        );
        $this->requireCompletedTaskWrite($task, 'success', $now, [
            'result_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
            Db::commit();
        } catch (OtaLocalCollectorLeaseConflict $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            return $this->handleTaskFailure(
                $task,
                $account,
                $device,
                'upload_failed',
                '结构化结果入库或回读失败：' . $this->safeText($e->getMessage(), 360)
            );
        }
        $this->touchDevice($device);
        $this->resolveFailureNotification($task);

        return [
            'status' => 'success',
            'task_id' => (int)$task['id'],
            'summary' => $summary,
            'next_action' => '服务器已保存并回读；本机可安全清理本次临时结果文件。',
        ];
    }

    /** @return array<string, mixed> */
    public function recoveryGuide(
        string $errorCode,
        string $platform = '',
        string $deviceStatus = 'online',
        string $nextRetryAt = ''
    ): array {
        $platformLabel = strtolower($platform) === 'meituan' ? '美团' : '携程';
        if ($deviceStatus !== 'online') {
            return [
                'status' => 'device_offline',
                'message' => '账号使用者电脑上的本机采集器当前离线。',
                'next_action' => '请在绑定电脑运行本机采集器；上线后任务会自动继续。',
                'action_code' => 'start_local_collector',
                'auto_retry' => true,
                'contact_admin' => false,
            ];
        }

        if (trim($errorCode) === '') {
            return [
                'status' => 'ready',
                'message' => '本机采集账户当前可用。',
                'next_action' => '可继续采集或补抓；登录态与 Cookie 仅保留在账户使用者电脑。',
                'action_code' => 'none',
                'auto_retry' => false,
                'contact_admin' => false,
            ];
        }

        $code = $this->normalizeFailureCode($errorCode);
        if (in_array($code, self::LOGIN_ERRORS, true)) {
            return [
                'status' => 'login_required',
                'message' => "{$platformLabel}登录态已失效或不完整，系统已停止无效重试。",
                'next_action' => "请在账号使用者电脑重新登录{$platformLabel}，验证成功后原日期会自动补抓。",
                'action_code' => 'create_login_task',
                'auto_retry' => false,
                'contact_admin' => false,
            ];
        }
        if (in_array($code, self::VERIFICATION_ERRORS, true)) {
            return [
                'status' => 'verification_required',
                'message' => "{$platformLabel}要求短信、验证码或人机验证。",
                'next_action' => '请账号使用者在本机浏览器完成人工验证；系统不会绕过验证。',
                'action_code' => 'complete_human_verification',
                'auto_retry' => false,
                'contact_admin' => false,
            ];
        }
        if (in_array($code, ['identity_mismatch', 'permission_denied', 'device_revoked'], true)) {
            return [
                'status' => $code,
                'message' => $code === 'identity_mismatch'
                    ? '平台门店身份与宿析门店映射不一致，已阻止写入。'
                    : '当前设备、账号或门店权限不允许继续采集。',
                'next_action' => '请检查账户门店映射；无法自行处理时点击“联系管理员”。',
                'action_code' => 'review_account_mapping',
                'auto_retry' => false,
                'contact_admin' => true,
            ];
        }
        if ($code === 'profile_corrupted') {
            return [
                'status' => 'profile_corrupted',
                'message' => '本机 Profile 无法继续使用。',
                'next_action' => '请在账号使用者电脑重新建立该账户 Profile；服务器不会远程删除旧 Profile。',
                'action_code' => 'rebuild_local_profile',
                'auto_retry' => false,
                'contact_admin' => true,
            ];
        }
        if (in_array($code, self::RETRYABLE_ERRORS, true)) {
            return [
                'status' => 'retry_wait',
                'message' => '采集或同步暂时失败，系统已安排有限自动重试。',
                'next_action' => $nextRetryAt !== ''
                    ? '下次自动重试：' . $nextRetryAt . '；无需重复点击。'
                    : '请保持本机采集器运行；重试仍失败时可手动补抓。',
                'action_code' => 'wait_or_backfill',
                'auto_retry' => true,
                'contact_admin' => false,
            ];
        }

        return [
            'status' => $code !== '' ? $code : 'ready',
            'message' => $code !== '' ? '本机采集任务需要处理。' : '本机采集账户状态正常。',
            'next_action' => $code !== '' ? '查看失败摘要并按提示处理；需要协助时联系管理员。' : '可执行登录验证、采集或补抓。',
            'action_code' => $code !== '' ? 'inspect_failure' : 'collect',
            'auto_retry' => false,
            'contact_admin' => $code !== '',
        ];
    }

    /** @return array<string, mixed> */
    private function finishLoginTask(array $task, array $account, array $device, array $input): array
    {
        $now = date('Y-m-d H:i:s');
        $verifiedAt = $this->normalizeDateTime((string)($input['session_verified_at'] ?? '')) ?: $now;
        $summary = [
            'source_method' => 'local_account_profile',
            'session_status' => 'current_session_verified',
            'session_verified_at' => $verifiedAt,
            'sensitive_values_received' => false,
        ];
        Db::transaction(function () use ($task, $account, $summary, $verifiedAt, $now): void {
            $this->requireLeasedTaskWrite($task, [
                'status' => 'success',
                'result_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_code' => '',
                'error_summary' => '',
                'lease_token_hash' => '',
                'lease_expires_at' => null,
                'finished_at' => $now,
                'update_time' => $now,
            ], null, $now);
            $this->requireScopedAccountWrite(
                $task,
                (int)$task['device_id'],
                [
                'status' => 'active',
                'session_status' => 'current_session_verified',
                'last_session_verified_at' => $verifiedAt,
                'last_error_code' => '',
                'last_error_summary' => '',
                'retry_count' => 0,
                'next_retry_at' => null,
                'update_time' => $now,
                ],
                '登录任务账号回写后精确回读失败。'
            );
        });
        $request = $this->decodeJson($task['request_json'] ?? null);
        $resumeCollections = is_array($request['resume_collections'] ?? null)
            ? array_values($request['resume_collections'])
            : (is_array($request['resume_collection'] ?? null) ? [$request['resume_collection']] : []);
        $resumedTaskIds = [];
        $resumeError = '';
        $verifiedAccount = array_merge($account, [
            'status' => 'active',
            'session_status' => 'current_session_verified',
            'last_session_verified_at' => $verifiedAt,
        ]);
        foreach ($resumeCollections as $resume) {
            if (!is_array($resume)) {
                continue;
            }
            try {
                $resumeDate = $this->normalizeDate((string)($resume['data_date'] ?? ''));
                $resumeRequest = is_array($resume['request'] ?? null) ? $resume['request'] : [];
                if ($resumeDate === '' || !is_array($resumeRequest['ordered_collection'] ?? null)) {
                    continue;
                }
                $resumeHotelId = $this->strictPositiveInt($resume['system_hotel_id'] ?? $task['system_hotel_id']);
                $resumeUser = User::find((int)$task['user_id']);
                if (!$resumeUser || (int)($resumeUser->tenant_id ?? 0) !== (int)$task['tenant_id']) {
                    throw new RuntimeException('resume_user_scope_invalid', 403);
                }
                $resumeActor = $this->actorContext($resumeUser);
                $this->assertHotelPermission($resumeActor, $resumeHotelId);
                $mapping = $this->mappingForAccountHotel(
                    (int)$task['tenant_id'],
                    (int)$account['id'],
                    $resumeHotelId,
                    (string)$account['platform']
                );
                $resumed = $this->enqueueTask(
                    $resumeActor,
                    $verifiedAccount,
                    $mapping,
                    in_array((string)($resume['task_type'] ?? ''), ['collect', 'backfill'], true)
                        ? (string)$resume['task_type']
                        : 'collect',
                    $resumeDate,
                    $this->safeIdentifier((string)($resume['data_type'] ?? 'business'), 50) ?: 'business',
                    $resumeRequest,
                    false,
                    (int)($resume['priority'] ?? 50),
                    false
                );
                if ((int)($resumed['id'] ?? 0) > 0) {
                    $resumedTaskIds[] = (int)$resumed['id'];
                }
            } catch (Throwable $e) {
                $resumeError = $this->safeText($e->getMessage(), 240);
            }
        }
        $resumedTaskIds = array_values(array_unique($resumedTaskIds));
        $summary['resumed_collection_task_ids'] = $resumedTaskIds;
        $summary['resume_status'] = $resumeError !== ''
            ? 'resume_failed'
            : ($resumedTaskIds !== [] ? 'queued' : 'not_requested');
        if ($resumeError !== '') {
            $summary['resume_error'] = $resumeError;
        }
        $this->requireCompletedTaskWrite($task, 'success', $now, [
            'result_summary_json' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $this->touchDevice($device);
        $this->resolveFailureNotification($task);

        return [
            'status' => 'success',
            'task_id' => (int)$task['id'],
            'summary' => $summary,
            'next_action' => $resumedTaskIds !== []
                ? '账户登录态与目标门店已验证，原目标日期采集已自动进入有序队列。'
                : '账户登录态已在本机验证，可选择门店执行昨日采集或补抓。',
        ];
    }

    /** @return array<string, mixed> */
    private function handleTaskFailure(
        array $task,
        array $account,
        array $device,
        string $errorCode,
        string $errorSummary
    ): array {
        $attempt = max(1, (int)($task['attempt'] ?? 1));
        $maxAttempts = max(1, (int)($task['max_attempts'] ?? 3));
        $retryable = in_array($errorCode, self::RETRYABLE_ERRORS, true) && $attempt < $maxAttempts;
        $now = date('Y-m-d H:i:s');
        $nextRetryAt = $retryable
            ? date('Y-m-d H:i:s', time() + min(900, 30 * (2 ** max(0, $attempt - 1))))
            : null;
        $terminalStatus = in_array($errorCode, self::LOGIN_ERRORS, true)
            ? 'login_required'
            : (in_array($errorCode, self::VERIFICATION_ERRORS, true) ? 'verification_required' : 'failed');
        $taskStatus = $retryable ? 'retry_wait' : $terminalStatus;

        Db::transaction(function () use (
            $task,
            $account,
            $errorCode,
            $errorSummary,
            $attempt,
            $nextRetryAt,
            $taskStatus,
            $now
        ): void {
            $this->requireLeasedTaskWrite($task, [
                'status' => $taskStatus,
                'available_at' => $nextRetryAt ?: $now,
                'lease_token_hash' => '',
                'lease_expires_at' => null,
                'error_code' => $errorCode,
                'error_summary' => $errorSummary,
                'finished_at' => $nextRetryAt === null ? $now : null,
                'update_time' => $now,
            ], null, $now);
            $accountStatus = $taskStatus === 'retry_wait' ? 'retry_wait' : $taskStatus;
            $accountSession = in_array($taskStatus, ['login_required', 'verification_required'], true)
                ? $taskStatus
                : (string)($account['session_status'] ?? 'unverified');
            $this->requireScopedAccountWrite(
                $task,
                (int)$task['device_id'],
                [
                'status' => $accountStatus,
                'session_status' => $accountSession,
                'last_error_code' => $errorCode,
                'last_error_summary' => $errorSummary,
                'retry_count' => $attempt,
                'next_retry_at' => $nextRetryAt,
                'update_time' => $now,
                ],
                '失败任务账号回写后精确回读失败。'
            );
        });
        $this->touchDevice($device);
        if (!$retryable) {
            $this->notifyTerminalFailure($task, $account, $errorCode, $errorSummary);
        }
        $recovery = $this->recoveryGuide(
            $errorCode,
            (string)$task['platform'],
            'online',
            $nextRetryAt ?: ''
        );

        return [
            'status' => $taskStatus,
            'task_id' => (int)$task['id'],
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'error_code' => $errorCode,
            'error_summary' => $errorSummary,
            'next_retry_at' => $nextRetryAt,
            'recovery' => $recovery,
        ];
    }

    /** @return array<string, mixed> */
    private function importCollectionRows(
        User $owner,
        array $task,
        array $account,
        array $mapping,
        array $device,
        array $rows,
        array $captureSummary,
        array $orderedPlan
    ): array {
        $sync = new PlatformDataSyncService();
        $dataSourceId = (int)($mapping['data_source_id'] ?? 0);
        $config = [
            'local_collector_account_id' => (int)$account['id'],
            'collector_device_id_hash' => hash('sha256', (string)$device['device_public_id']),
            'profile_key_hash' => (string)$account['profile_key_hash'],
            'platform_hotel_id' => (string)$mapping['platform_hotel_id'],
            'external_hotel_id' => (string)$mapping['platform_hotel_id'],
            'hotel_name' => (string)($mapping['platform_hotel_name'] ?? ''),
            'current_session_verified' => true,
            'last_login_verified_at' => (string)($account['last_session_verified_at'] ?? ''),
            'source_method' => 'local_account_profile',
        ];
        if ((string)$task['platform'] === 'ctrip') {
            $config['ctrip_hotel_id'] = (string)$mapping['platform_hotel_id'];
            $config['hotel_id'] = (string)$mapping['platform_hotel_id'];
        } else {
            $config['store_id'] = (string)$mapping['platform_hotel_id'];
            $config['poi_id'] = (string)$mapping['platform_hotel_id'];
        }

        $sourcePayload = [
            'name' => (string)$account['account_alias'] . ' · 本机账户采集',
            'platform' => (string)$task['platform'],
            'data_type' => (string)$task['data_type'],
            'system_hotel_id' => (int)$task['system_hotel_id'],
            'ingestion_method' => 'local_collector',
            'config' => $config,
        ];
        if ($dataSourceId <= 0) {
            $source = $sync->saveDataSource($owner, $sourcePayload);
            $dataSourceId = (int)($source['id'] ?? 0);
            if ($dataSourceId <= 0) {
                throw new RuntimeException('本机采集数据源未创建成功。');
            }
            Db::name('ota_local_collector_account_hotels')
                ->where('id', (int)$mapping['id'])
                ->where('tenant_id', (int)$task['tenant_id'])
                ->where('account_id', (int)$task['account_id'])
                ->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->where('platform', (string)$task['platform'])
                ->where('status', 'active')
                ->update([
                    'data_source_id' => $dataSourceId,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
        } elseif ($this->captureIdentityMatched($captureSummary, $rows, $mapping)) {
            $sourcePayload['id'] = $dataSourceId;
            $sync->saveDataSource($owner, $sourcePayload);
        }

        $sections = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($orderedPlan['sections'] ?? [])
        )));
        $identityValidation = is_array($captureSummary['platform_identity_validation'] ?? null)
            ? $captureSummary['platform_identity_validation']
            : [];
        $result = $sync->syncDataSource($owner, $dataSourceId, [
            'trigger_type' => 'local_collector_upload',
            'local_collector_verified' => true,
            'local_collector_task_id' => (int)$task['id'],
            'target_date' => (string)$task['data_date'],
            'capture_sections' => implode(',', $sections),
            'sections' => implode(',', $sections),
            'payload' => [
                'rows' => $rows,
                'data_date' => (string)$task['data_date'],
                'target_date' => (string)$task['data_date'],
                'source_method' => 'local_account_profile',
                'capture_summary' => $captureSummary,
                'ordered_collection' => $orderedPlan,
                'platform_identity_validation' => $identityValidation,
                'local_collector_evidence' => [
                    'contract_version' => self::CONTRACT_VERSION,
                    'task_id' => (int)$task['id'],
                    'account_id' => (int)$account['id'],
                    'device_public_id_hash' => hash('sha256', (string)$device['device_public_id']),
                    'profile_key_hash' => (string)$account['profile_key_hash'],
                    'session_verified_at' => (string)($account['last_session_verified_at'] ?? ''),
                    'sensitive_values_received' => false,
                ],
            ],
        ]);
        $syncTaskId = $this->importerSyncTaskId($result);
        $result['deterministic_readback'] = $this->databaseCollectionReadbackSet(
            $task,
            (int)($result['data_source_id'] ?? 0),
            $syncTaskId
        );
        return $result;
    }

    /** @return array<string, mixed> */
    private function databaseCollectionReadbackSet(array $task, int $dataSourceId, int $syncTaskId): array
    {
        $receipt = [
            'tenant_id' => max(0, (int)($task['tenant_id'] ?? 0)),
            'data_source_id' => max(0, $dataSourceId),
            'sync_task_id' => max(0, $syncTaskId),
            'system_hotel_id' => max(0, (int)($task['system_hotel_id'] ?? 0)),
            'target_date' => $this->normalizeDate((string)($task['data_date'] ?? '')),
            'platform' => $this->safeIdentifier((string)($task['platform'] ?? ''), 20),
            'readback_count' => 0,
            'readback_verified' => false,
            'row_ids' => [],
            'row_ids_well_formed' => false,
            'failure_reason' => '',
        ];
        if ($receipt['tenant_id'] <= 0
            || $receipt['data_source_id'] <= 0
            || $receipt['sync_task_id'] <= 0
            || $receipt['system_hotel_id'] <= 0
            || $receipt['target_date'] === ''
            || !in_array($receipt['platform'], self::PLATFORMS, true)
        ) {
            $receipt['failure_reason'] = 'deterministic_readback_identity_missing';
            return $receipt;
        }

        try {
            $fields = Db::getTableInfo('online_daily_data', 'fields');
            if (!is_array($fields)) {
                $receipt['failure_reason'] = 'deterministic_readback_schema_unavailable';
                return $receipt;
            }
            $columns = array_fill_keys(array_map('strval', array_values($fields)), true);
            foreach ([
                'id',
                'tenant_id',
                'data_source_id',
                'sync_task_id',
                'system_hotel_id',
                'data_date',
                'readback_verified',
            ] as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    $receipt['failure_reason'] = 'deterministic_readback_column_missing:' . $requiredColumn;
                    return $receipt;
                }
            }
            if (!isset($columns['platform']) && !isset($columns['source'])) {
                $receipt['failure_reason'] = 'deterministic_readback_platform_column_missing';
                return $receipt;
            }

            $query = Db::name('online_daily_data')
                ->field('id,readback_verified')
                ->where('tenant_id', $receipt['tenant_id'])
                ->where('data_source_id', $receipt['data_source_id'])
                ->where('sync_task_id', $receipt['sync_task_id'])
                ->where('system_hotel_id', $receipt['system_hotel_id'])
                ->where('data_date', $receipt['target_date'])
                ->limit(self::MAX_ROWS_PER_RESULT + 1);
            if (isset($columns['platform'])) {
                $query->where('platform', $receipt['platform']);
            }
            if (isset($columns['source'])) {
                $query->where('source', $receipt['platform']);
            }
            $rows = $query->order('id', 'asc')->select()->toArray();
        } catch (Throwable) {
            $receipt['failure_reason'] = 'deterministic_readback_query_failed';
            return $receipt;
        }

        if (count($rows) > self::MAX_ROWS_PER_RESULT) {
            $receipt['readback_count'] = count($rows);
            $receipt['failure_reason'] = 'deterministic_readback_row_limit_exceeded';
            return $receipt;
        }
        $rowSet = $this->sanitizeReadbackRowIds(array_column($rows, 'id'));
        $receipt['row_ids'] = $rowSet['row_ids'];
        $receipt['row_ids_well_formed'] = $rowSet['well_formed'];
        $receipt['readback_count'] = count($receipt['row_ids']);
        $allRowsVerified = $rows !== [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['readback_verified'] ?? 0) !== 1) {
                $allRowsVerified = false;
                break;
            }
        }
        $receipt['readback_verified'] = $rowSet['well_formed'] && $allRowsVerified;
        if (!$receipt['readback_verified']) {
            $receipt['failure_reason'] = $rows === []
                ? 'deterministic_readback_rows_missing'
                : 'deterministic_readback_rows_unverified';
        }
        return $receipt;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeCollectionRows(array $rows, array $task, array $mapping): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('第 ' . ($index + 1) . ' 行不是结构化对象。', 422);
            }
            $this->assertNoSensitiveMaterial($row);
            $rowHotelId = (int)($row['system_hotel_id'] ?? $task['system_hotel_id']);
            if ($rowHotelId !== (int)$task['system_hotel_id']) {
                throw new RuntimeException('本机结果包含其他门店数据，已拒绝整批写入。', 403);
            }
            $rowPlatform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? $task['platform'])));
            if ($rowPlatform !== (string)$task['platform']) {
                throw new RuntimeException('本机结果平台与任务不一致，已拒绝写入。', 422);
            }
            $rowDate = $this->normalizeDate((string)(
                $row['data_date']
                ?? $row['dataDate']
                ?? $row['date']
                ?? $row['statDate']
                ?? ''
            ));
            if ($rowDate === '') {
                throw new RuntimeException('本机结果缺少目标日期证据，已拒绝写入。', 422);
            }
            if ($rowDate !== (string)$task['data_date']) {
                throw new RuntimeException('本机结果日期与补抓任务不一致，已拒绝写入。', 422);
            }
            $observedPlatformHotelId = $this->firstText($row, [
                'platform_hotel_id',
                'external_hotel_id',
                'ctrip_hotel_id',
                'poi_id',
                'poiId',
                'store_id',
                'storeId',
                'hotel_id',
                'hotelId',
            ]);
            if ($observedPlatformHotelId !== ''
                && !hash_equals((string)$mapping['platform_hotel_id'], $observedPlatformHotelId)
            ) {
                throw new RuntimeException('本机结果平台门店身份与账户映射不一致，已拒绝写入。', 403);
            }
            $row['source'] = (string)$task['platform'];
            $row['platform'] = (string)$task['platform'];
            $row['system_hotel_id'] = (int)$task['system_hotel_id'];
            $row['data_date'] = (string)$task['data_date'];
            $row['data_type'] = trim((string)($row['data_type'] ?? $task['data_type'])) ?: (string)$task['data_type'];
            $row['acquisition_method'] = 'local_account_profile';
            $row['source_method'] = 'local_account_profile';
            if ($observedPlatformHotelId !== '') {
                $row['platform_hotel_id'] = $observedPlatformHotelId;
            } else {
                unset($row['platform_hotel_id']);
            }
            $result[] = $row;
        }
        return $result;
    }

    private function scheduleGapBackfillsForDevice(array $device): int
    {
        if (!$this->tableReadable('online_daily_data')) {
            return 0;
        }
        $deviceId = (int)$device['id'];
        $accounts = Db::name('ota_local_collector_accounts')
            ->where('device_id', $deviceId)
            ->where('status', 'active')
            ->where('session_status', 'current_session_verified')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        if ($accounts === []) {
            return 0;
        }
        $accounts = array_values(array_filter(
            $accounts,
            static fn(array $account): bool => (int)($account['tenant_id'] ?? 0) === (int)$device['tenant_id']
                && (int)($account['user_id'] ?? 0) === (int)$device['user_id']
                && in_array(strtolower(trim((string)($account['platform'] ?? ''))), self::PLATFORMS, true)
        ));
        $permittedHotelIds = $this->devicePermittedHotelIds($device);
        if ($permittedHotelIds === []) {
            return 0;
        }
        $actor = [
            'tenant_id' => (int)$device['tenant_id'],
            'user_id' => (int)$device['user_id'],
            'hotel_ids' => $permittedHotelIds,
        ];
        $scheduled = 0;
        foreach ($accounts as $account) {
            $mappings = Db::name('ota_local_collector_account_hotels')
                ->where('tenant_id', (int)$account['tenant_id'])
                ->where('account_id', (int)$account['id'])
                ->where('platform', (string)$account['platform'])
                ->where('status', 'active')
                ->whereIn('system_hotel_id', $permittedHotelIds)
                ->order('system_hotel_id', 'asc')
                ->order('platform', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
            foreach ($mappings as $mapping) {
                for ($daysAgo = 1; $daysAgo <= self::GAP_LOOKBACK_DAYS; $daysAgo++) {
                    if ($scheduled >= self::MAX_GAP_TASKS_PER_HEARTBEAT) {
                        return $scheduled;
                    }
                    $dataDate = date('Y-m-d', strtotime('-' . $daysAgo . ' day'));
                    $missingFieldKeys = $this->currentMissingFieldKeys(
                        (string)$account['platform'],
                        (int)$mapping['system_hotel_id'],
                        $dataDate
                    );
                    if ($missingFieldKeys === []) {
                        continue;
                    }
                    $reason = $daysAgo === 1
                        ? 'automatic_yesterday_gap_recovery'
                        : 'automatic_bounded_gap_recovery';
                    $plan = OtaOrderedCollectionPlanner::requestPlan(
                        (string)$account['platform'],
                        $dataDate,
                        $missingFieldKeys,
                        $reason
                    );
                    $task = $this->enqueueTask(
                        $actor,
                        $account,
                        $mapping,
                        'backfill',
                        $dataDate,
                        'business',
                        [
                            'reason' => $reason,
                            'sections' => $plan['sections'],
                            'requested_at' => date('Y-m-d H:i:s'),
                            'ordered_collection' => $plan,
                        ],
                        false,
                        $daysAgo === 1 ? 70 : 35,
                        false,
                        $device
                    );
                    if (($task['_created'] ?? false) === true) {
                        $scheduled++;
                    }
                }
            }
        }
        return $scheduled;
    }

    /** @return array<string, mixed> */
    private function enqueueTask(
        array $actor,
        array $account,
        array $mapping,
        string $taskType,
        ?string $dataDate,
        string $dataType,
        array $request,
        bool $force,
        int $priority,
        bool $manualRequest = false,
        ?array $deviceFence = null
    ): array {
        $sessionTask = in_array($taskType, ['login', 'session_probe'], true);
        $collectionTask = in_array($taskType, ['collect', 'backfill'], true);
        $taskFamily = $sessionTask ? 'session' : ($collectionTask ? 'collection' : $taskType);
        $scopeKey = implode('|', [
            (int)$account['id'],
            $sessionTask ? 'account' : (int)$mapping['system_hotel_id'],
            $taskFamily,
            $dataDate ?: 'session',
            $collectionTask ? 'ota_yesterday_core' : $dataType,
        ]);

        $activeQuery = Db::name('ota_local_collector_tasks')
            ->where('tenant_id', (int)$account['tenant_id'])
            ->where('user_id', (int)$account['user_id'])
            ->where('device_id', (int)$account['device_id'])
            ->where('account_id', (int)$account['id'])
            ->where('system_hotel_id', (int)$mapping['system_hotel_id'])
            ->where('platform', (string)$account['platform'])
            ->whereIn('status', self::ACTIVE_TASK_STATUSES)
            ->whereNull('finished_at');
        if ($sessionTask) {
            $activeQuery->whereIn('task_type', ['login', 'session_probe']);
        } elseif ($collectionTask) {
            $activeQuery
                ->where('system_hotel_id', (int)$mapping['system_hotel_id'])
                ->whereIn('task_type', ['collect', 'backfill'])
                ->where('data_date', (string)$dataDate);
        } else {
            $activeQuery->where('task_type', $taskType);
        }
        $active = $activeQuery->order('id', 'desc')->find();
        if (is_array($active)) {
            $active = $this->reusableTaskReadback($active, $account, $mapping, true);
            if (is_array($active)) {
                $active['_created'] = false;
                return $active;
            }
        }

        $manualRetryOfTaskId = 0;
        $latest = $this->latestTaskForScope(
            (int)$account['tenant_id'],
            (int)$account['user_id'],
            (int)$account['id'],
            (int)$account['device_id'],
            (int)$mapping['system_hotel_id'],
            (string)$account['platform'],
            $taskType,
            $dataDate,
            $dataType
        );
        if (is_array($latest)) {
            $latest = $this->reusableTaskReadback($latest, $account, $mapping, false);
        }
        if (is_array($latest)) {
            $latestStatus = strtolower(trim((string)($latest['status'] ?? '')));
            $newSessionAttempt = $sessionTask
                && ($manualRequest || $force);
            $newCollectionAttempt = $collectionTask
                && $manualRequest
                && in_array($latestStatus, self::MANUAL_RETRYABLE_TASK_STATUSES, true);
            if (!$newSessionAttempt && !$newCollectionAttempt) {
                $latest['_created'] = false;
                return $latest;
            }

            $manualRetryOfTaskId = (int)$latest['id'];
            $request['retry_trigger'] = 'manual';
            $request['retry_of_task_id'] = $manualRetryOfTaskId;
        }

        $randomizeKey = $manualRetryOfTaskId > 0 || ($sessionTask && ($force || $manualRequest));
        $idempotencyKey = hash(
            'sha256',
            $scopeKey
                . ($manualRetryOfTaskId > 0 ? '|manual_retry|' . $manualRetryOfTaskId : '')
                . ($randomizeKey ? '|' . bin2hex(random_bytes(8)) : '')
        );
        $now = date('Y-m-d H:i:s');
        try {
            $id = (int)Db::transaction(function () use (
                $account,
                $mapping,
                $taskType,
                $dataDate,
                $dataType,
                $priority,
                $idempotencyKey,
                $request,
                $actor,
                $now,
                $manualRetryOfTaskId,
                $deviceFence
            ): int {
                $deviceQuery = $deviceFence !== null
                    ? $this->activeDeviceQuery($deviceFence)
                    : Db::name('ota_local_collector_devices')
                        ->where('id', (int)$account['device_id'])
                        ->where('tenant_id', (int)$account['tenant_id'])
                        ->where('user_id', (int)$account['user_id'])
                        ->where('status', '<>', 'revoked');
                if (!is_array($deviceQuery->lock(true)->find())) {
                    throw new RuntimeException('本机采集设备已撤销，未创建任务。', 409);
                }
                $accountQuery = Db::name('ota_local_collector_accounts')
                    ->where('id', (int)$account['id'])
                    ->where('tenant_id', (int)$account['tenant_id'])
                    ->where('user_id', (int)$account['user_id'])
                    ->where('device_id', (int)$account['device_id'])
                    ->where('platform', (string)$account['platform'])
                    ->where('status', '<>', 'revoked');
                if ($deviceFence !== null) {
                    $accountQuery
                        ->where('status', 'active')
                        ->where('session_status', 'current_session_verified');
                }
                if (!is_array($accountQuery->lock(true)->find())) {
                    throw new RuntimeException('本机采集账号已撤销，未创建任务。', 409);
                }
                $id = (int)Db::name('ota_local_collector_tasks')->insertGetId([
                    'tenant_id' => (int)$account['tenant_id'],
                    'user_id' => (int)$account['user_id'],
                    'device_id' => (int)$account['device_id'],
                    'account_id' => (int)$account['id'],
                    'system_hotel_id' => (int)$mapping['system_hotel_id'],
                    'platform' => (string)$account['platform'],
                    'task_type' => $taskType,
                    'data_date' => $dataDate,
                    'data_type' => $dataType,
                    'status' => 'queued',
                    'priority' => max(1, min(100, $priority)),
                    'attempt' => 0,
                    'max_attempts' => in_array($taskType, ['login', 'session_probe'], true) ? 1 : 3,
                    'available_at' => $now,
                    'lease_token_hash' => '',
                    'idempotency_key' => $idempotencyKey,
                    'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by' => (int)($actor['user_id'] ?? 0),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                if ($manualRetryOfTaskId > 0) {
                    Db::name('ota_local_collector_accounts')
                        ->where('id', (int)$account['id'])
                        ->where('tenant_id', (int)$account['tenant_id'])
                        ->where('user_id', (int)$account['user_id'])
                        ->where('platform', (string)$account['platform'])
                        ->where('session_status', 'current_session_verified')
                        ->update([
                            'status' => 'active',
                            'last_error_code' => '',
                            'last_error_summary' => '',
                            'retry_count' => 0,
                            'next_retry_at' => null,
                            'update_time' => $now,
                        ]);
                }

                return $id;
            });
        } catch (Throwable $e) {
            $existing = Db::name('ota_local_collector_tasks')
                ->where('idempotency_key', $idempotencyKey)
                ->where('tenant_id', (int)$account['tenant_id'])
                ->where('user_id', (int)$account['user_id'])
                ->where('device_id', (int)$account['device_id'])
                ->where('account_id', (int)$account['id'])
                ->where('system_hotel_id', (int)$mapping['system_hotel_id'])
                ->where('platform', (string)$account['platform'])
                ->find();
            if (!is_array($existing)) {
                throw $e;
            }
            $existing['_created'] = false;
            return $existing;
        }
        $row = Db::name('ota_local_collector_tasks')
            ->where('id', $id)
            ->where('tenant_id', (int)$account['tenant_id'])
            ->where('user_id', (int)$account['user_id'])
            ->where('device_id', (int)$account['device_id'])
            ->where('account_id', (int)$account['id'])
            ->where('system_hotel_id', (int)$mapping['system_hotel_id'])
            ->where('platform', (string)$account['platform'])
            ->find();
        if (!is_array($row) || $this->taskIdentity($row) === null) {
            throw new RuntimeException('本机任务创建后身份精确回读失败。', 409);
        }
        $row['_created'] = true;
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function latestTaskForScope(
        int $tenantId,
        int $userId,
        int $accountId,
        int $deviceId,
        int $hotelId,
        string $platform,
        string $taskType,
        ?string $dataDate,
        string $dataType
    ): ?array {
        $query = Db::name('ota_local_collector_tasks')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->where('device_id', $deviceId)
            ->where('system_hotel_id', $hotelId);
        $query->where('platform', strtolower(trim($platform)));
        if (in_array($taskType, ['login', 'session_probe'], true)) {
            $query->whereIn('task_type', ['login', 'session_probe'])
                ->whereNull('data_date');
        } elseif (in_array($taskType, ['collect', 'backfill'], true)) {
            $query->whereIn('task_type', ['collect', 'backfill']);
            if ($dataDate === null) {
                $query->whereNull('data_date');
            } else {
                $query->where('data_date', $dataDate);
            }
        } else {
            $query->where('task_type', $taskType)
                ->where('data_type', $dataType);
        }
        if ($dataDate === null && !in_array($taskType, ['login', 'session_probe'], true)) {
            $query->whereNull('data_date');
        } elseif ($dataDate !== null && !in_array($taskType, ['collect', 'backfill'], true)) {
            $query->where('data_date', $dataDate);
        }
        $row = $query->order('id', 'desc')->find();
        return is_array($row) ? $row : null;
    }

    /**
     * Re-read every mutable identity edge before reusing an active/latest task.
     * The first SQL scope is only a candidate filter; this gate prevents a
     * revoked device/account/mapping or an unknown/partially written status
     * from being surfaced after a concurrent change.
     *
     * @return array<string, mixed>|null
     */
    private function reusableTaskReadback(
        array $task,
        array $account,
        array $mapping,
        bool $activeOnly
    ): ?array {
        $identity = $this->taskIdentity($task);
        if ($identity === null
            || $identity['tenant_id'] !== (int)($account['tenant_id'] ?? 0)
            || $identity['user_id'] !== (int)($account['user_id'] ?? 0)
            || $identity['account_id'] !== (int)($account['id'] ?? 0)
            || $identity['device_id'] !== (int)($account['device_id'] ?? 0)
            || $identity['system_hotel_id'] !== (int)($mapping['system_hotel_id'] ?? 0)
            || $identity['platform'] !== strtolower(trim((string)($account['platform'] ?? '')))
            || $identity['tenant_id'] !== (int)($mapping['tenant_id'] ?? 0)
            || $identity['account_id'] !== (int)($mapping['account_id'] ?? 0)
            || $identity['system_hotel_id'] !== (int)($mapping['system_hotel_id'] ?? 0)
            || $identity['platform'] !== strtolower(trim((string)($mapping['platform'] ?? '')))
        ) {
            return null;
        }

        $allowedStatuses = array_values(array_unique(array_merge(
            self::ACTIVE_TASK_STATUSES,
            self::MANUAL_RETRYABLE_TASK_STATUSES,
            [
                'success',
                'partial_success',
                'login_required',
                'permission_denied',
                'device_revoked',
                'field_gap',
                'profile_corrupted',
            ]
        )));
        $status = strtolower(trim((string)($task['status'] ?? '')));
        if (!in_array($status, $allowedStatuses, true)) {
            return null;
        }
        $finishedAt = trim((string)($task['finished_at'] ?? ''));
        if ($activeOnly) {
            if (!in_array($status, self::ACTIVE_TASK_STATUSES, true) || $finishedAt !== '') {
                return null;
            }
        } elseif (in_array($status, self::ACTIVE_TASK_STATUSES, true) ? $finishedAt !== '' : $finishedAt === '') {
            return null;
        }

        try {
            $device = Db::name('ota_local_collector_devices')
                ->where('id', $identity['device_id'])
                ->where('tenant_id', $identity['tenant_id'])
                ->where('user_id', $identity['user_id'])
                ->find();
            if (!is_array($device) || (string)($device['status'] ?? '') === 'revoked') {
                return null;
            }

            $accountReadback = $this->scopedAccountQuery($task)
                ->where('device_id', $identity['device_id'])
                ->find();
            if (!is_array($accountReadback)
                || (int)($accountReadback['tenant_id'] ?? 0) !== $identity['tenant_id']
                || (int)($accountReadback['user_id'] ?? 0) !== $identity['user_id']
                || (int)($accountReadback['device_id'] ?? 0) !== $identity['device_id']
                || strtolower(trim((string)($accountReadback['platform'] ?? ''))) !== $identity['platform']
                || (string)($accountReadback['status'] ?? '') === 'revoked'
            ) {
                return null;
            }

            $mappingReadback = $this->mappingForAccountHotel(
                $identity['tenant_id'],
                $identity['account_id'],
                $identity['system_hotel_id'],
                $identity['platform']
            );
            if ((int)($mappingReadback['tenant_id'] ?? 0) !== $identity['tenant_id']
                || (int)($mappingReadback['account_id'] ?? 0) !== $identity['account_id']
                || (int)($mappingReadback['system_hotel_id'] ?? 0) !== $identity['system_hotel_id']
                || strtolower(trim((string)($mappingReadback['platform'] ?? ''))) !== $identity['platform']
                || (string)($mappingReadback['status'] ?? '') !== 'active'
            ) {
                return null;
            }

            $rowReadback = $this->scopedTaskQuery($task, true)->find();
            if (!is_array($rowReadback) || $this->taskIdentity($rowReadback) !== $identity) {
                return null;
            }
            $rowStatus = strtolower(trim((string)($rowReadback['status'] ?? '')));
            $rowFinishedAt = trim((string)($rowReadback['finished_at'] ?? ''));
            if (!in_array($rowStatus, $allowedStatuses, true)
                || ($activeOnly
                    ? (!in_array($rowStatus, self::ACTIVE_TASK_STATUSES, true) || $rowFinishedAt !== '')
                    : (in_array($rowStatus, self::ACTIVE_TASK_STATUSES, true)
                        ? $rowFinishedAt !== ''
                        : $rowFinishedAt === ''))
            ) {
                return null;
            }
            return $rowReadback;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   mapping_id:int,
     *   previous_mapping_id:int,
     *   previous_account_id:int,
     *   write_action:string,
     *   mapping_status:string,
     *   data_source_id:int,
     *   readback_verified:bool
     * }
     */
    private function upsertHotelMapping(
        array $actor,
        int $accountId,
        string $platform,
        int $hotelId,
        string $platformHotelId,
        string $platformHotelName,
        string $now
    ): array {
        $ownedMapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->lock(true)
            ->find();
        // Keep the cross-platform conflict guard explicit and bounded to the
        // same tenant/account/hotel; it is not used as the update target.
        $crossPlatformMapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', '<>', $platform)
            ->lock(true)
            ->find();
        if (is_array($crossPlatformMapping)) {
            throw new RuntimeException('该本机账户已存在其他平台的门店映射，无法覆盖。', 409);
        }

        $activeScopeMapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('account_id', '<>', $accountId)
            ->where('status', 'active')
            ->lock(true)
            ->find();
        if (is_array($activeScopeMapping)
            && (!is_array($ownedMapping)
                || (int)$activeScopeMapping['id'] !== (int)$ownedMapping['id'])
        ) {
            throw new RuntimeException('该门店平台已绑定到另一个本机账户，请先解绑原映射。', 409);
        }

        $activeIdentityMapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('platform', $platform)
            ->where('platform_hotel_id', $platformHotelId)
            ->where('status', 'active')
            ->lock(true)
            ->find();
        if (is_array($activeIdentityMapping)
            && (!is_array($ownedMapping)
                || (int)$activeIdentityMapping['id'] !== (int)$ownedMapping['id'])
        ) {
            throw new RuntimeException('该 OTA 平台门店标识已映射到其他宿析门店，已阻止重复绑定。', 409);
        }

        $historicalIdentityConflict = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('platform', $platform)
            ->where('platform_hotel_id', $platformHotelId)
            ->where('system_hotel_id', '<>', $hotelId)
            ->lock(true)
            ->find();
        if (is_array($historicalIdentityConflict)) {
            throw new RuntimeException('该 OTA 平台门店标识曾映射到其他宿析门店，不能跨酒店重新认领。', 409);
        }

        if (is_array($ownedMapping)) {
            $mappingId = (int)$ownedMapping['id'];
            $previousPlatformHotelId = (string)($ownedMapping['platform_hotel_id'] ?? '');
            if ((int)($ownedMapping['data_source_id'] ?? 0) > 0
                && $previousPlatformHotelId !== $platformHotelId
            ) {
                throw new RuntimeException('该映射已有历史采集数据源，重新启用时必须保持原 OTA 平台门店标识。', 409);
            }
            $writeAction = (string)($ownedMapping['status'] ?? '') === 'active'
                ? 'updated'
                : 'reactivated';

            Db::name('ota_local_collector_account_hotels')
                ->where('id', $mappingId)
                ->where('tenant_id', $actor['tenant_id'])
                ->where('account_id', $accountId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->update([
                    'platform_hotel_id' => $platformHotelId,
                    'platform_hotel_name' => $platformHotelName,
                    'status' => 'active',
                    'update_time' => $now,
                ]);

            $readback = Db::name('ota_local_collector_account_hotels')
                ->where('id', $mappingId)
                ->where('tenant_id', $actor['tenant_id'])
                ->where('account_id', $accountId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('platform_hotel_id', $platformHotelId)
                ->where('status', 'active')
                ->find();
            if (!is_array($readback)) {
                throw new RuntimeException('本机采集门店映射恢复后精确回读失败。', 500);
            }

            return [
                'mapping_id' => $mappingId,
                'previous_mapping_id' => $mappingId,
                'previous_account_id' => $accountId,
                'write_action' => $writeAction,
                'mapping_status' => 'active',
                'data_source_id' => (int)($readback['data_source_id'] ?? 0),
                'readback_verified' => true,
            ];
        }

        $previousMapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', '<>', 'active')
            ->order('id', 'desc')
            ->lock(true)
            ->find();

        $historicalSourceIdentityConflict = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $actor['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', '<>', 'active')
            ->where('data_source_id', '>', 0)
            ->where('platform_hotel_id', '<>', $platformHotelId)
            ->lock(true)
            ->find();
        if (is_array($historicalSourceIdentityConflict)) {
            throw new RuntimeException(
                '该门店已有历史采集数据源，跨账户换绑时必须保持原 OTA 平台门店标识。',
                409
            );
        }

        try {
            $mappingId = (int)Db::name('ota_local_collector_account_hotels')->insertGetId([
                'tenant_id' => $actor['tenant_id'],
                'account_id' => $accountId,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'platform_hotel_id' => $platformHotelId,
                'platform_hotel_name' => $platformHotelName,
                'data_source_id' => 0,
                'status' => 'active',
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (Throwable $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new RuntimeException(
                    '该门店或 OTA 平台门店标识刚刚被其他账户绑定，请刷新后重试。',
                    409,
                    $exception
                );
            }
            throw $exception;
        }
        $readbackVerified = (int)Db::name('ota_local_collector_account_hotels')
            ->where('id', $mappingId)
            ->where('tenant_id', $actor['tenant_id'])
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('platform_hotel_id', $platformHotelId)
            ->where('status', 'active')
            ->count() === 1;
        if (!$readbackVerified) {
            throw new RuntimeException('本机采集门店绑定后精确回读失败。', 500);
        }

        return [
            'mapping_id' => $mappingId,
            'previous_mapping_id' => (int)($previousMapping['id'] ?? 0),
            'previous_account_id' => (int)($previousMapping['account_id'] ?? 0),
            'write_action' => is_array($previousMapping) ? 'reassigned' : 'created',
            'mapping_status' => 'active',
            'data_source_id' => 0,
            'readback_verified' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function mappingReadbackReceipt(
        array $actor,
        int $accountId,
        int $hotelId,
        string $platform,
        string $platformHotelId,
        string $expectedStatus,
        int $expectedMappingId = 0
    ): array {
        $query = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', (int)$actor['tenant_id'])
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('platform_hotel_id', $platformHotelId)
            ->where('status', $expectedStatus);
        if ($expectedMappingId > 0) {
            $query->where('id', $expectedMappingId);
        }
        $row = $query->find();

        return [
            'mapping_id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'account_id' => (int)($row['account_id'] ?? 0),
            'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'platform' => (string)($row['platform'] ?? ''),
            'platform_hotel_id' => (string)($row['platform_hotel_id'] ?? ''),
            'mapping_status' => (string)($row['status'] ?? 'missing'),
            'data_source_id' => (int)($row['data_source_id'] ?? 0),
            'readback_verified' => is_array($row),
        ];
    }

    private function isUniqueConstraintViolation(Throwable $exception): bool
    {
        $current = $exception;
        do {
            $message = strtolower($current->getMessage());
            if (str_contains($message, 'duplicate entry')
                || str_contains($message, 'unique constraint')
                || str_contains($message, 'unique violation')
                || str_contains($message, 'constraint failed')
            ) {
                return true;
            }
            $current = $current->getPrevious();
        } while ($current instanceof Throwable);

        return false;
    }

    /** @return array<string, mixed> */
    private function actorContext(mixed $user): array
    {
        $userId = (int)($user->id ?? 0);
        $tenantId = (int)($user->tenant_id ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('请先登录宿析OS。', 401);
        }
        $hotelIds = method_exists($user, 'getPermittedHotelIds')
            ? array_values(array_unique(array_filter(array_map('intval', $user->getPermittedHotelIds()))))
            : [];
        if ($tenantId <= 0 && $hotelIds !== []) {
            $tenantId = (int)Db::name('hotels')->where('id', $hotelIds[0])->value('tenant_id');
        }
        if ($tenantId <= 0) {
            throw new RuntimeException('当前账号缺少租户范围，请联系管理员。', 403);
        }
        return [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hotel_ids' => $hotelIds,
            'is_super_admin' => method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin(),
        ];
    }

    private function assertHotelPermission(array $actor, int $hotelId): void
    {
        if ($hotelId <= 0 || !in_array($hotelId, $actor['hotel_ids'], true)) {
            throw new RuntimeException('当前账号无权使用该门店执行本机采集。', 403);
        }
        $tenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
        if ($tenantId !== (int)$actor['tenant_id']) {
            throw new RuntimeException('门店租户范围不一致，禁止绑定。', 403);
        }
    }

    /** @return array<string, mixed> */
    private function ownedDevice(array $actor, int $deviceId): array
    {
        if ($deviceId <= 0) {
            throw new RuntimeException('请选择本机采集设备。', 422);
        }
        $device = Db::name('ota_local_collector_devices')
            ->where('id', $deviceId)
            ->where('tenant_id', $actor['tenant_id'])
            ->where('user_id', $actor['user_id'])
            ->find();
        if (!is_array($device)) {
            throw new RuntimeException('本机采集设备不存在或不属于当前账号。', 404);
        }
        if ((string)($device['status'] ?? '') === 'revoked') {
            throw new RuntimeException('本机采集设备已撤销，请重新配对。', 409);
        }
        return $device;
    }

    /** @return array<string, mixed> */
    private function ownedAccount(array $actor, int $accountId): array
    {
        if ($accountId <= 0) {
            throw new RuntimeException('请选择本机采集账户。', 422);
        }
        $account = Db::name('ota_local_collector_accounts')
            ->where('id', $accountId)
            ->where('tenant_id', $actor['tenant_id'])
            ->where('user_id', $actor['user_id'])
            ->find();
        if (!is_array($account)) {
            throw new RuntimeException('本机采集账户不存在或不属于当前账号。', 404);
        }
        if ((string)($account['status'] ?? '') === 'revoked') {
            throw new RuntimeException('本机采集账户已撤销。', 409);
        }
        return $account;
    }

    /** @return array<string, mixed> */
    private function mappingForAccountHotel(
        int $tenantId,
        int $accountId,
        int $hotelId,
        string $platform
    ): array
    {
        $platform = strtolower(trim($platform));
        if ($tenantId <= 0 || $accountId <= 0 || $hotelId <= 0 || !in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('Invalid OTA mapping scope.', 404);
        }
        $mapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->find();
        if (!is_array($mapping)) {
            throw new RuntimeException('该账户尚未映射到目标门店。', 404);
        }
        return $mapping;
    }

    /** @return array<string, mixed> */
    private function authenticateDevice(string $publicId, string $token): array
    {
        $publicId = trim($publicId);
        $token = trim($token);
        if ($publicId === '' || $token === '') {
            throw new RuntimeException('本机采集器设备认证缺失。', 401);
        }
        $device = Db::name('ota_local_collector_devices')->where('device_public_id', $publicId)->find();
        if (!is_array($device)
            || (string)($device['status'] ?? '') === 'revoked'
            || !hash_equals((string)($device['device_token_hash'] ?? ''), hash('sha256', $token))
        ) {
            throw new RuntimeException('本机采集器设备认证失败或已撤销。', 401);
        }
        return $device;
    }

    /** @return array{id:int,tenant_id:int,user_id:int,device_id:int,account_id:int,system_hotel_id:int,platform:string}|null */
    private function taskIdentity(array $task): ?array
    {
        $identity = [
            'id' => $this->strictPositiveInt($task['id'] ?? null),
            'tenant_id' => $this->strictPositiveInt($task['tenant_id'] ?? null),
            'user_id' => $this->strictPositiveInt($task['user_id'] ?? null),
            'device_id' => $this->strictPositiveInt($task['device_id'] ?? null),
            'account_id' => $this->strictPositiveInt($task['account_id'] ?? null),
            'system_hotel_id' => $this->strictPositiveInt($task['system_hotel_id'] ?? null),
            'platform' => strtolower(trim((string)($task['platform'] ?? ''))),
        ];
        if ($identity['id'] <= 0
            || $identity['tenant_id'] <= 0
            || $identity['user_id'] <= 0
            || $identity['device_id'] <= 0
            || $identity['account_id'] <= 0
            || $identity['system_hotel_id'] <= 0
            || !in_array($identity['platform'], self::PLATFORMS, true)
        ) {
            return null;
        }
        return $identity;
    }

    private function assertTaskIdentity(array $task, ?array $device = null): void
    {
        $identity = $this->taskIdentity($task);
        if ($identity === null) {
            throw new RuntimeException('本机任务缺少租户、账号、门店或平台身份，已拒绝继续。', 409);
        }
        if ($device !== null
            && ($identity['device_id'] !== (int)($device['id'] ?? 0)
                || $identity['tenant_id'] !== (int)($device['tenant_id'] ?? 0)
                || $identity['user_id'] !== (int)($device['user_id'] ?? 0))
        ) {
            throw new RuntimeException('本机任务与设备租户、账号范围不一致。', 403);
        }
    }

    /** @return mixed */
    private function scopedTaskQuery(array $task, bool $includeDevice = false)
    {
        $this->assertTaskIdentity($task);
        $query = Db::name('ota_local_collector_tasks')
            ->where('id', (int)$task['id'])
            ->where('tenant_id', (int)$task['tenant_id'])
            ->where('user_id', (int)$task['user_id'])
            ->where('account_id', (int)$task['account_id'])
            ->where('system_hotel_id', (int)$task['system_hotel_id'])
            ->where('platform', strtolower(trim((string)$task['platform'])));
        if ($includeDevice) {
            $query->where('device_id', (int)$task['device_id']);
        }
        return $query;
    }

    /** @return mixed */
    private function scopedAccountQuery(array $task)
    {
        $this->assertTaskIdentity($task);
        return Db::name('ota_local_collector_accounts')
            ->where('id', (int)$task['account_id'])
            ->where('tenant_id', (int)$task['tenant_id'])
            ->where('user_id', (int)$task['user_id'])
            ->where('platform', strtolower(trim((string)$task['platform'])));
    }

    private function requireScopedAccountWrite(
        array $task,
        int $deviceId,
        array $values,
        string $failureMessage
    ): array {
        $updated = $this->scopedAccountQuery($task)
            ->where('device_id', $deviceId)
            ->update($values);
        $readback = $this->scopedAccountQuery($task)
            ->where('device_id', $deviceId)
            ->find();
        if ($updated !== 1
            || !is_array($readback)
            || !$this->exactWriteReadbackMatches($readback, $values)
        ) {
            throw new RuntimeException($failureMessage, 409);
        }
        return $readback;
    }

    private function requireScopedTaskWrite(array $task, array $values, bool $includeDevice = false): array
    {
        $updated = $this->scopedTaskQuery($task, $includeDevice)->update($values);
        if ($updated !== 1) {
            throw new RuntimeException('本机任务身份回写范围不一致，未确认保存。', 409);
        }
        $readback = $this->scopedTaskQuery($task, $includeDevice)->find();
        if (!is_array($readback)
            || !$this->exactWriteReadbackMatches($readback, $values)
        ) {
            throw new RuntimeException('本机任务身份回写后精确回读失败。', 409);
        }
        return $readback;
    }

    /** @return array<string, mixed> */
    private function leasedTask(array $device, int $taskId, string $leaseToken): array
    {
        if ($taskId <= 0 || $leaseToken === '') {
            throw new RuntimeException('本机任务租约缺失。', 401);
        }
        $task = Db::name('ota_local_collector_tasks')
            ->where('id', $taskId)
            ->where('device_id', (int)$device['id'])
            ->where('tenant_id', (int)$device['tenant_id'])
            ->where('user_id', (int)$device['user_id'])
            ->where('account_id', '>', 0)
            ->where('system_hotel_id', '>', 0)
            ->whereIn('platform', self::PLATFORMS)
            ->find();
        if (!is_array($task)
            || !in_array((string)($task['status'] ?? ''), ['leased', 'running', 'waiting_user_login', 'verification_required'], true)
            || !hash_equals((string)($task['lease_token_hash'] ?? ''), hash('sha256', $leaseToken))
            || strtotime((string)($task['lease_expires_at'] ?? '1970-01-01')) < time()
        ) {
            throw new RuntimeException('本机任务租约无效或已过期，请重新领取任务。', 409);
        }
        $this->assertTaskIdentity($task, $device);
        $account = $this->scopedAccountQuery($task)->find();
        if (!is_array($account)
            || (int)($account['device_id'] ?? 0) !== (int)$device['id']
            || (int)($account['tenant_id'] ?? 0) !== (int)$device['tenant_id']
            || (int)($account['user_id'] ?? 0) !== (int)$device['user_id']
            || strtolower(trim((string)($account['platform'] ?? ''))) !== strtolower(trim((string)$task['platform']))
            || (string)($account['status'] ?? '') === 'revoked'
        ) {
            throw new RuntimeException('本机任务账号身份回读失败，已拒绝继续。', 403);
        }
        $mapping = $this->mappingForAccountHotel(
            (int)$task['tenant_id'],
            (int)$task['account_id'],
            (int)$task['system_hotel_id'],
            (string)$task['platform']
        );
        if ((int)($mapping['tenant_id'] ?? 0) !== (int)$task['tenant_id']
            || (int)($mapping['account_id'] ?? 0) !== (int)$task['account_id']
            || (int)($mapping['system_hotel_id'] ?? 0) !== (int)$task['system_hotel_id']
            || strtolower(trim((string)($mapping['platform'] ?? ''))) !== strtolower(trim((string)$task['platform']))
        ) {
            throw new RuntimeException('本机任务门店映射身份回读失败，已拒绝继续。', 403);
        }
        $this->assertDeviceTaskPermission($device, $task);
        return $task;
    }

    /** @return array<int, int> */
    private function devicePermittedHotelIds(array $device): array
    {
        $tenantId = (int)($device['tenant_id'] ?? 0);
        $userId = (int)($device['user_id'] ?? 0);
        if ($tenantId <= 0 || $userId <= 0) {
            return [];
        }

        // Once the permission table exists, use the same scope resolver as the
        // web application and fail closed. A removed/expired/inactive grant
        // must never fall back to every hotel in the tenant for a device.
        if ($this->tableReadable('user_hotel_permissions')) {
            $user = User::find($userId);
            if (!$user instanceof User || (int)($user->tenant_id ?? 0) !== $tenantId) {
                return [];
            }
            return array_values(array_map('intval', (new HotelScopeService())->accessibleHotelIds($user)));
        }

        return array_values(array_map('intval', Db::name('hotels')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->column('id')));
    }

    /** @param array<string, mixed> $task */
    private function assertDeviceTaskPermission(array $device, array $task): void
    {
        $hotelId = (int)($task['system_hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $this->devicePermittedHotelIds($device), true)) {
            throw new RuntimeException('当前设备所属账户已无目标门店采集权限，任务未执行。', 403);
        }
    }

    /** @return mixed */
    private function activeDeviceQuery(array $device)
    {
        $deviceId = (int)($device['id'] ?? 0);
        $tenantId = (int)($device['tenant_id'] ?? 0);
        $userId = (int)($device['user_id'] ?? 0);
        $publicId = trim((string)($device['device_public_id'] ?? ''));
        $tokenHash = trim((string)($device['device_token_hash'] ?? ''));
        if ($deviceId <= 0 || $tenantId <= 0 || $userId <= 0 || $publicId === '' || $tokenHash === '') {
            throw new RuntimeException('本机采集设备身份范围不完整，已拒绝状态回写。', 409);
        }
        return Db::name('ota_local_collector_devices')
            ->where('id', $deviceId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('device_public_id', $publicId)
            ->where('device_token_hash', $tokenHash)
            ->where('status', '<>', 'revoked');
    }

    /** @return array<string, mixed>|null */
    private function writeActiveDevice(array $device, array $values): ?array
    {
        $updated = $this->activeDeviceQuery($device)->update($values);
        if ($updated !== 0 && $updated !== 1) {
            return null;
        }
        $readback = $this->activeDeviceQuery($device)->find();
        if (!is_array($readback)
            || !$this->exactWriteReadbackMatches($readback, $values)
        ) {
            return null;
        }
        return $readback;
    }

    private function touchDevice(array $device): bool
    {
        $now = date('Y-m-d H:i:s');
        return is_array($this->writeActiveDevice($device, [
            'status' => 'online',
            'last_seen_at' => $now,
            'update_time' => $now,
        ]));
    }

    private function notifyTerminalFailure(array $task, array $account, string $errorCode, string $errorSummary): void
    {
        try {
            ($this->failureNotifier ?? new OtaFailureNotificationService())->recordCollectionOutcome([
                'hotel_id' => (int)$task['system_hotel_id'],
                'actor_user_id' => (int)$task['user_id'],
                'platform' => (string)$task['platform'],
                'data_date' => (string)($task['data_date'] ?? date('Y-m-d')),
                'reason_code' => $errorCode,
                'message' => $errorSummary,
                'error_summary' => $errorSummary,
                'next_action' => $this->recoveryGuide($errorCode, (string)$task['platform'])['next_action'],
                'local_collector_task_id' => (int)$task['id'],
                'local_account_alias' => (string)$account['account_alias'],
                'account_alias' => (string)$account['account_alias'],
                'ingestion_method' => 'local_collector',
                'notify_wecom' => true,
                'success' => false,
            ]);
        } catch (Throwable) {
            // The task failure itself remains persisted even if notification delivery fails.
        }
    }

    private function resolveFailureNotification(array $task): void
    {
        try {
            ($this->failureNotifier ?? new OtaFailureNotificationService())->recordCollectionOutcome([
                'hotel_id' => (int)$task['system_hotel_id'],
                'actor_user_id' => (int)$task['user_id'],
                'platform' => (string)$task['platform'],
                'data_date' => (string)($task['data_date'] ?? date('Y-m-d')),
                'success' => true,
                'saved_count' => 1,
                'session_verified' => true,
            ]);
        } catch (Throwable) {
            // Recovery notification cleanup is best-effort and cannot undo a verified result.
        }
    }

    private function effectiveDeviceStatus(array $device): string
    {
        if ((string)($device['status'] ?? '') === 'revoked') {
            return 'revoked';
        }
        $lastSeenAt = strtotime((string)($device['last_seen_at'] ?? ''));
        return $lastSeenAt !== false && $lastSeenAt >= time() - self::DEVICE_ONLINE_SECONDS
            ? 'online'
            : 'device_offline';
    }

    private function queuedTaskCount(array $device): int
    {
        return (int)Db::name('ota_local_collector_tasks')
            ->where('tenant_id', (int)$device['tenant_id'])
            ->where('user_id', (int)$device['user_id'])
            ->where('device_id', (int)$device['id'])
            ->where('account_id', '>', 0)
            ->where('system_hotel_id', '>', 0)
            ->whereIn('platform', self::PLATFORMS)
            ->whereIn('status', ['queued', 'retry_wait'])
            ->count();
    }

    private function pairCacheKey(string $rawCode): string
    {
        return 'ota_local_collector_pair_' . hash('sha256', $rawCode);
    }

    private function schemaReady(): bool
    {
        foreach ([
            'ota_local_collector_devices',
            'ota_local_collector_accounts',
            'ota_local_collector_account_hotels',
            'ota_local_collector_tasks',
        ] as $table) {
            if (!$this->tableReadable($table)) {
                return false;
            }
        }
        return true;
    }

    private function tableReadable(string $table): bool
    {
        try {
            Db::name($table)->field('id')->limit(1)->select();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
            return is_array($fields) && in_array($column, $fields, true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function publicTask(array $task): array
    {
        $task['request_summary'] = $this->publicTaskRequest(
            $this->decodeJson($task['request_json'] ?? null)
        );
        unset($task['lease_token_hash'], $task['request_json'], $task['result_summary_json']);
        unset($task['_created']);
        return $task;
    }

    /** @return array<string, mixed> */
    private function publicTaskRequest(array $request): array
    {
        $ordered = is_array($request['ordered_collection'] ?? null)
            ? $request['ordered_collection']
            : [];
        return [
            'reason' => $this->safeText((string)($request['reason'] ?? ''), 180),
            'sections' => $this->sanitizeSections($request['sections'] ?? []),
            'ordered_collection' => $ordered === [] ? null : [
                'contract_version' => (string)($ordered['contract_version'] ?? OtaOrderedCollectionPlanner::CONTRACT_VERSION),
                'scope' => (string)($ordered['scope'] ?? 'ota_yesterday_core'),
                'stage' => (string)($ordered['stage'] ?? ''),
                'target_date' => (string)($ordered['target_date'] ?? ''),
                'sections' => $this->sanitizeSections($ordered['sections'] ?? []),
            ],
            'retry_trigger' => (string)($request['retry_trigger'] ?? ''),
            'retry_of_task_id' => (int)($request['retry_of_task_id'] ?? 0),
            'resume_collection_count' => count(
                is_array($request['resume_collections'] ?? null) ? $request['resume_collections'] : []
            ),
        ];
    }

    /**
     * Device payloads contain only the current task scope. Interface catalogs,
     * field mappings, recovery plans and server-side reasons remain private.
     *
     * @return array<string, mixed>
     */
    private function leasedTaskRequest(array $request): array
    {
        return [
            'sections' => $this->sanitizeSections($request['sections'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function publicOrderedCollectionSnapshot(array $snapshot, bool $canViewImplementation): array
    {
        if ($canViewImplementation) {
            return $snapshot;
        }

        $sanitizeQueueItem = static function (mixed $row): mixed {
            if (!is_array($row)) {
                return $row;
            }
            unset($row['interface_ids'], $row['missing_field_keys'], $row['required_field_keys']);
            return $row;
        };

        foreach (['current', 'next'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                $snapshot[$key] = $sanitizeQueueItem($snapshot[$key]);
            }
        }
        if (is_array($snapshot['queue'] ?? null)) {
            $snapshot['queue'] = array_values(array_map($sanitizeQueueItem, $snapshot['queue']));
        }
        $snapshot['implementation_visibility'] = 'redacted';
        $snapshot['collection_contract'] = 'task_scoped';

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function publicTaskResultSummary(array $summary, bool $canViewImplementation): array
    {
        if ($canViewImplementation) {
            return $summary;
        }

        foreach (['capture_summary', 'ordered_collection'] as $key) {
            if (!is_array($summary[$key] ?? null)) {
                continue;
            }
            unset(
                $summary[$key]['expected_interface_ids'],
                $summary[$key]['captured_interface_ids'],
                $summary[$key]['missing_interface_ids'],
                $summary[$key]['required_field_keys'],
                $summary[$key]['captured_field_keys'],
                $summary[$key]['missing_field_keys']
            );
        }
        $summary['implementation_visibility'] = 'redacted';

        return $summary;
    }

    /** @return array<int, string> */
    private function sanitizeFieldKeys(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $key = $this->safeIdentifier((string)$item, 80);
            if ($key !== '') {
                $result[$key] = true;
            }
        }
        return array_slice(array_keys($result), 0, 100);
    }

    /** @return array<string, mixed> */
    private function sanitizeCaptureSummary(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $identity = is_array($value['platform_identity_validation'] ?? null)
            ? $value['platform_identity_validation']
            : [];
        $identityStatus = strtolower(trim((string)($identity['status'] ?? 'not_checked')));
        if (in_array($identityStatus, ['mismatched', 'hotel_mismatch', 'store_mismatch', 'poi_mismatch'], true)) {
            $identityStatus = 'mismatch';
        }
        if (!in_array($identityStatus, ['matched', 'mismatch', 'not_checked', 'unverified'], true)) {
            $identityStatus = 'unverified';
        }
        $validatedIdentifier = $this->normalizePlatformHotelId($identity['validated_identifier'] ?? '');
        $sectionStates = [];
        foreach (array_slice(is_array($value['section_states'] ?? null) ? $value['section_states'] : [], 0, 20, true) as $section => $state) {
            $sectionKey = $this->safeIdentifier((string)$section, 50);
            if ($sectionKey === '' || !is_array($state)) {
                continue;
            }
            $sectionStates[$sectionKey] = [
                'response_count' => max(0, (int)($state['response_count'] ?? 0)),
                'row_count' => max(0, (int)($state['row_count'] ?? 0)),
            ];
        }

        return [
            'contract_version' => $this->safeIdentifier((string)($value['contract_version'] ?? ''), 60),
            'scope' => $this->safeIdentifier((string)($value['scope'] ?? ''), 60),
            'capture_id' => $this->safeIdentifier((string)($value['capture_id'] ?? ''), 100),
            'fetched_at' => $this->safeText((string)($value['fetched_at'] ?? ''), 40),
            'platform' => $this->safeIdentifier((string)($value['platform'] ?? ''), 20),
            'target_date' => $this->normalizeDate((string)($value['target_date'] ?? '')),
            'requested_sections' => $this->sanitizeSections($value['requested_sections'] ?? []),
            'expected_interface_ids' => $this->sanitizeFieldKeys($value['expected_interface_ids'] ?? []),
            'captured_interface_ids' => $this->sanitizeFieldKeys($value['captured_interface_ids'] ?? []),
            'missing_interface_ids' => $this->sanitizeFieldKeys($value['missing_interface_ids'] ?? []),
            'required_field_keys' => $this->sanitizeFieldKeys($value['required_field_keys'] ?? []),
            'captured_field_keys' => $this->sanitizeFieldKeys($value['captured_field_keys'] ?? []),
            'missing_field_keys' => $this->sanitizeFieldKeys($value['missing_field_keys'] ?? []),
            'section_states' => $sectionStates,
            'capture_gate_status' => $this->safeIdentifier((string)($value['capture_gate_status'] ?? ''), 50),
            'platform_identity_validation' => [
                'status' => $identityStatus,
                'source_validation' => ($identity['source_validation'] ?? false) === true,
                'validated_identifier' => $validatedIdentifier,
            ],
            'readback_status' => 'pending_server_save',
            'excluded_example_capabilities' => [
                'comments',
                'realtime',
                'ads',
                'subchannels',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sanitizeSyncDiagnostics(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ([
            'target_date',
            'requires_target_date_traffic',
            'target_date_rows',
            'target_date_traffic_rows',
            'target_date_traffic_field_fact_ready_count',
            'target_date_traffic_field_fact_missing_count',
            'nonzero_required_metric_rows',
            'platform_hotel_identifier_status',
            'page_field_fact_status',
            'field_fact_status',
            'p0_status',
            'operator_message',
            'adapter_status',
            'confirmed_empty',
        ] as $key) {
            if (array_key_exists($key, $value) && (is_scalar($value[$key]) || $value[$key] === null)) {
                $result[$key] = $value[$key];
            }
        }
        foreach ([
            'target_date_data_types',
            'required_traffic_metric_keys',
            'complete_traffic_metric_keys',
            'missing_traffic_metric_keys',
            'missing_inputs',
        ] as $key) {
            if (array_key_exists($key, $value)) {
                $result[$key] = $this->sanitizeFieldKeys($value[$key]);
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function sanitizeRunReadbackReceipt(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rowSet = $this->sanitizeReadbackRowIds($value['row_ids'] ?? null);
        $readbackCount = $this->strictPositiveInt($value['readback_count'] ?? null);

        return [
            'tenant_id' => $this->strictPositiveInt($value['tenant_id'] ?? null),
            'data_source_id' => $this->strictPositiveInt($value['data_source_id'] ?? null),
            'sync_task_id' => $this->strictPositiveInt($value['sync_task_id'] ?? null),
            'system_hotel_id' => $this->strictPositiveInt($value['system_hotel_id'] ?? null),
            'target_date' => $this->normalizeDate((string)($value['target_date'] ?? '')),
            'platform' => strtolower($this->safeIdentifier((string)($value['platform'] ?? ''), 20)),
            'readback_count' => $readbackCount,
            'readback_verified' => ($value['readback_verified'] ?? false) === true
                && $rowSet['well_formed']
                && $readbackCount === count($rowSet['row_ids']),
            'p0_status' => $this->safeIdentifier((string)($value['p0_status'] ?? ''), 40),
            'row_ids' => $rowSet['row_ids'],
            'row_ids_well_formed' => $rowSet['well_formed'],
            'failure_reason' => $this->safeIdentifier((string)($value['failure_reason'] ?? ''), 100),
        ];
    }

    /** @return array<string, mixed> */
    private function sanitizeDeterministicReadbackSet(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rowSet = $this->sanitizeReadbackRowIds($value['row_ids'] ?? null);
        $readbackCount = $this->strictPositiveInt($value['readback_count'] ?? null);

        return [
            'tenant_id' => $this->strictPositiveInt($value['tenant_id'] ?? null),
            'data_source_id' => $this->strictPositiveInt($value['data_source_id'] ?? null),
            'sync_task_id' => $this->strictPositiveInt($value['sync_task_id'] ?? null),
            'system_hotel_id' => $this->strictPositiveInt($value['system_hotel_id'] ?? null),
            'target_date' => $this->normalizeDate((string)($value['target_date'] ?? '')),
            'platform' => strtolower($this->safeIdentifier((string)($value['platform'] ?? ''), 20)),
            'readback_count' => $readbackCount,
            'readback_verified' => ($value['readback_verified'] ?? false) === true
                && $rowSet['well_formed']
                && $readbackCount === count($rowSet['row_ids']),
            'row_ids' => $rowSet['row_ids'],
            'row_ids_well_formed' => $rowSet['well_formed'],
            'failure_reason' => $this->safeIdentifier((string)($value['failure_reason'] ?? ''), 100),
        ];
    }

    /** @return array{row_ids:array<int, int>,well_formed:bool} */
    private function sanitizeReadbackRowIds(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > self::MAX_ROWS_PER_RESULT) {
            return ['row_ids' => [], 'well_formed' => false];
        }

        $rowIds = [];
        $seen = [];
        $wellFormed = true;
        foreach ($value as $rawId) {
            $rowId = $this->strictPositiveInt($rawId);
            if ($rowId <= 0 || isset($seen[$rowId])) {
                $wellFormed = false;
                continue;
            }
            $seen[$rowId] = true;
            $rowIds[] = $rowId;
        }
        sort($rowIds, SORT_NUMERIC);
        if (count($rowIds) !== count($value)) {
            $wellFormed = false;
        }
        return ['row_ids' => $rowIds, 'well_formed' => $wellFormed && $rowIds !== []];
    }

    private function strictPositiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return 0;
        }
        $integer = (int)$value;
        return $integer > 0 && (string)$integer === $value ? $integer : 0;
    }

    private function importerSyncTaskId(array $importResult): int
    {
        $ids = [];
        foreach (['task_id', 'sync_task_id'] as $key) {
            if (!array_key_exists($key, $importResult)) {
                continue;
            }
            $id = $this->strictPositiveInt($importResult[$key]);
            if ($id <= 0) {
                return 0;
            }
            $ids[] = $id;
        }
        if ($ids === [] || count(array_unique($ids)) !== 1) {
            return 0;
        }
        return $ids[0];
    }

    private function runReadbackMatchesImporterResult(
        array $task,
        array $importResult,
        array $runReadback,
        array $deterministicReadback,
        ?int $mappingDataSourceId = null
    ): bool {
        $tenantId = $this->strictPositiveInt($task['tenant_id'] ?? null);
        $dataSourceId = $this->strictPositiveInt($importResult['data_source_id'] ?? null);
        $syncTaskId = $this->importerSyncTaskId($importResult);
        $hotelId = $this->strictPositiveInt($task['system_hotel_id'] ?? null);
        $targetDate = $this->normalizeDate((string)($task['data_date'] ?? ''));
        $platform = strtolower($this->safeIdentifier((string)($task['platform'] ?? ''), 20));
        $readbackCount = $this->strictPositiveInt($importResult['readback_count'] ?? null);
        $rowIds = (array)($runReadback['row_ids'] ?? []);
        $deterministicRowIds = (array)($deterministicReadback['row_ids'] ?? []);

        return $tenantId > 0
            && $dataSourceId > 0
            && $syncTaskId > 0
            && $hotelId > 0
            && $targetDate !== ''
            && in_array($platform, self::PLATFORMS, true)
            && ($importResult['readback_verified'] ?? false) === true
            && ($runReadback['readback_verified'] ?? false) === true
            && ($deterministicReadback['readback_verified'] ?? false) === true
            && ($runReadback['row_ids_well_formed'] ?? false) === true
            && ($deterministicReadback['row_ids_well_formed'] ?? false) === true
            && (int)($runReadback['tenant_id'] ?? 0) === $tenantId
            && (int)($deterministicReadback['tenant_id'] ?? 0) === $tenantId
            // A task's readback must remain anchored to the source selected by
            // its hotel mapping. A forged importer receipt may otherwise make
            // a different tenant/source look internally self-consistent.
            // Missing, zero, or otherwise non-positive mapping anchors are
            // not a trustworthy source binding. Fail closed even when the
            // importer receipts are internally self-consistent.
            && $mappingDataSourceId > 0
            && $mappingDataSourceId === $dataSourceId
            && (int)($runReadback['data_source_id'] ?? 0) === $dataSourceId
            && (int)($deterministicReadback['data_source_id'] ?? 0) === $dataSourceId
            && (int)($runReadback['sync_task_id'] ?? 0) === $syncTaskId
            && (int)($deterministicReadback['sync_task_id'] ?? 0) === $syncTaskId
            && (int)($runReadback['system_hotel_id'] ?? 0) === $hotelId
            && (int)($deterministicReadback['system_hotel_id'] ?? 0) === $hotelId
            && (string)($runReadback['target_date'] ?? '') === $targetDate
            && (string)($deterministicReadback['target_date'] ?? '') === $targetDate
            && (string)($runReadback['platform'] ?? '') === $platform
            && (string)($deterministicReadback['platform'] ?? '') === $platform
            && $rowIds !== []
            && $rowIds === $deterministicRowIds
            && $readbackCount === count($rowIds)
            && (int)($runReadback['readback_count'] ?? 0) === count($rowIds)
            && (int)($deterministicReadback['readback_count'] ?? 0) === count($rowIds);
    }

    /**
     * Re-read the task's hotel mapping after the importer has completed. The
     * first local collection may create the source and update the mapping, so
     * using only the pre-import mapping would miss that newly established
     * anchor. `null` means the mapping row could not be read back; callers must
     * fail closed instead of treating it as an unlinked source.
     */
    private function mappingDataSourceIdAfterImport(array $task, array $mapping): ?int
    {
        $mappingId = $this->strictPositiveInt($mapping['id'] ?? null);
        $tenantId = $this->strictPositiveInt($task['tenant_id'] ?? null);
        $accountId = $this->strictPositiveInt($task['account_id'] ?? null);
        $hotelId = $this->strictPositiveInt($task['system_hotel_id'] ?? null);
        $platform = strtolower($this->safeIdentifier((string)($task['platform'] ?? ''), 20));
        if ($mappingId <= 0 || $tenantId <= 0 || $accountId <= 0 || $hotelId <= 0 || $platform === '') {
            return null;
        }
        try {
            $row = Db::name('ota_local_collector_account_hotels')
                ->where('id', $mappingId)
                ->where('tenant_id', $tenantId)
                ->where('account_id', $accountId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('status', 'active')
                ->find();
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row) || strtolower(trim((string)($row['status'] ?? ''))) !== 'active') {
            return null;
        }
        return max(0, (int)($row['data_source_id'] ?? 0));
    }

    /** @return array<string, mixed> */
    private function refreshDualOtaAuthorityReceipt(array $scopeTask): array
    {
        $this->assertTaskIdentity($scopeTask);
        $tenantId = (int)$scopeTask['tenant_id'];
        $userId = (int)$scopeTask['user_id'];
        $hotelId = (int)$scopeTask['system_hotel_id'];
        $accountId = (int)$scopeTask['account_id'];
        $targetDate = $this->normalizeDate((string)($scopeTask['data_date'] ?? ''));
        if ($hotelId <= 0 || $targetDate === '') {
            return [
                'status' => 'scope_invalid',
                'ready' => false,
                'missing_platforms' => self::PLATFORMS,
                'blocking_inputs' => ['authority_scope_invalid'],
            ];
        }

        try {
            $taskRows = Db::name('ota_local_collector_tasks')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->where('account_id', '>', 0)
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $targetDate)
                ->where('status', 'success')
                ->whereIn('platform', self::PLATFORMS)
                ->order('id', 'desc')
                ->limit(50)
                ->select()
                ->toArray();
        } catch (Throwable $exception) {
            return [
                'status' => 'task_readback_unavailable',
                'ready' => false,
                'missing_platforms' => self::PLATFORMS,
                'blocking_inputs' => [
                    'local_collector_task_readback_unavailable',
                    $this->safeIdentifier($exception->getMessage(), 100),
                ],
            ];
        }

        $platformResults = [];
        $sourceIds = [];
        $readyPlatforms = [];
        $platformCandidates = [];
        $scopeIssues = [];
        foreach ($taskRows as $taskRow) {
            $platform = strtolower(trim((string)($taskRow['platform'] ?? '')));
            if (!in_array($platform, self::PLATFORMS, true)
                || $this->taskIdentity($taskRow) === null
                || (int)($taskRow['tenant_id'] ?? 0) !== $tenantId
                || (int)($taskRow['user_id'] ?? 0) !== $userId
                || (int)($taskRow['system_hotel_id'] ?? 0) !== $hotelId
                || (string)($taskRow['data_date'] ?? '') !== $targetDate
            ) {
                continue;
            }
            $account = $this->scopedAccountQuery($taskRow)
                ->where('device_id', (int)$taskRow['device_id'])
                ->find();
            try {
                $mapping = $this->mappingForAccountHotel(
                    $tenantId,
                    (int)$taskRow['account_id'],
                    $hotelId,
                    $platform
                );
            } catch (Throwable) {
                $mapping = null;
                $scopeIssues[] = 'authority_mapping_scope_' . $platform;
            }
            if (!is_array($account) || !is_array($mapping)
                || (int)($account['device_id'] ?? 0) !== (int)$taskRow['device_id']
                || (int)($mapping['tenant_id'] ?? 0) !== $tenantId
                || (int)($mapping['account_id'] ?? 0) !== (int)$taskRow['account_id']
                || (int)($mapping['system_hotel_id'] ?? 0) !== $hotelId
                || strtolower(trim((string)($mapping['platform'] ?? ''))) !== $platform
            ) {
                continue;
            }
            $summary = $this->decodeJson($taskRow['result_summary_json'] ?? null);
            $ordered = is_array($summary['ordered_collection'] ?? null)
                ? $summary['ordered_collection']
                : [];
            $runReadback = $this->sanitizeRunReadbackReceipt($summary['run_readback'] ?? []);
            if (($summary['readback_verified'] ?? false) !== true
                || strtolower(trim((string)($summary['sync_status'] ?? ''))) !== 'success'
                || strtolower(trim((string)($ordered['p0_status'] ?? ''))) !== 'ready'
                || $this->sanitizeFieldKeys($ordered['missing_field_keys'] ?? []) !== []
                || ($runReadback['readback_verified'] ?? false) !== true
                || strtolower(trim((string)($runReadback['p0_status'] ?? ''))) !== 'ready'
                || (int)($runReadback['data_source_id'] ?? 0) <= 0
                || (int)($runReadback['sync_task_id'] ?? 0) <= 0
                || (int)($runReadback['system_hotel_id'] ?? 0) !== $hotelId
                || (string)($runReadback['target_date'] ?? '') !== $targetDate
                || strtolower(trim((string)($runReadback['platform'] ?? ''))) !== $platform
                || (array)($runReadback['row_ids'] ?? []) === []
                || (int)($runReadback['tenant_id'] ?? 0) !== $tenantId
            ) {
                continue;
            }
            $summaryIdentity = is_array($summary['scope_identity'] ?? null)
                ? $summary['scope_identity']
                : [];
            if ($summaryIdentity === []
                || (int)($summaryIdentity['tenant_id'] ?? 0) !== $tenantId
                || (int)($summaryIdentity['account_id'] ?? 0) !== (int)$taskRow['account_id']
                || (int)($summaryIdentity['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($summaryIdentity['capture_task_id'] ?? 0) !== (int)$taskRow['id']
                || strtolower(trim((string)($summaryIdentity['platform'] ?? ''))) !== $platform
                || strtolower(trim((string)($summaryIdentity['platform_hotel_id'] ?? ''))) !== strtolower(trim((string)($mapping['platform_hotel_id'] ?? '')))
            ) {
                $scopeIssues[] = 'authority_summary_scope_' . $platform;
                continue;
            }
            $sourceId = (int)$runReadback['data_source_id'];
            $mappingSourceId = $this->mappingDataSourceIdAfterImport($taskRow, $mapping);
            if ($mappingSourceId === null || $mappingSourceId !== $sourceId) {
                $scopeIssues[] = 'authority_source_mapping_mismatch_' . $platform;
                continue;
            }
            $platformCandidates[$platform][] = [
                'platform' => $platform,
                'success' => true,
                'saved_count' => max(1, (int)($summary['saved_count'] ?? 0)),
                'data_source_id' => $sourceId,
                'account_id' => (int)$taskRow['account_id'],
                'run_readback' => $runReadback,
            ];
        }

        foreach ($platformCandidates as $platform => $candidates) {
            $candidateAccountIds = array_values(array_unique(array_map(
                static fn(array $candidate): int => (int)($candidate['account_id'] ?? 0),
                $candidates
            )));
            if (count($candidateAccountIds) !== 1) {
                $scopeIssues[] = 'mixed_account_authority_' . $platform;
                continue;
            }
            $candidate = $candidates[0];
            $sourceIds[] = (int)$candidate['data_source_id'];
            $readyPlatforms[$platform] = true;
            $platformResults[] = $candidate;
        }
        foreach ($platformResults as $index => $platformResult) {
            if ((string)($platformResult['platform'] ?? '') === strtolower((string)$scopeTask['platform'])
                && (int)($platformResult['account_id'] ?? 0) !== $accountId
            ) {
                $scopeIssues[] = 'authority_current_account_mismatch';
                unset($platformResults[$index], $readyPlatforms[strtolower((string)$scopeTask['platform'])]);
            }
        }
        $platformResults = array_values($platformResults);

        $readyPlatformList = array_keys($readyPlatforms);
        sort($readyPlatformList, SORT_STRING);
        $missingPlatforms = array_values(array_diff(self::PLATFORMS, $readyPlatformList));
        sort($missingPlatforms, SORT_STRING);
        $complete = $missingPlatforms === [];
        $result = [
            'success' => $complete,
            'saved_count' => array_sum(array_map(
                static fn(array $row): int => (int)($row['saved_count'] ?? 0),
                $platformResults
            )),
            'required_platforms' => self::PLATFORMS,
            'successful_platforms' => $readyPlatformList,
            'failed_platforms' => $missingPlatforms,
            'platform_results' => $platformResults,
        ];
        $outcome = [
            'complete' => $complete,
            'status' => $complete ? 'success' : ($platformResults !== [] ? 'partial_success' : 'failed'),
            'saved_count' => (int)$result['saved_count'],
            'required_platforms' => self::PLATFORMS,
            'successful_platforms' => $readyPlatformList,
            'failed_platforms' => $missingPlatforms,
        ];
        $policy = new ScheduledAutoFetchPolicy();
        $receipt = $policy->buildDailyTrustReceipt(
            $hotelId,
            $targetDate,
            $sourceIds,
            $outcome,
            $result,
            'historical_daily'
        );

        if ($complete && ($receipt['collection_complete'] ?? false) === true) {
            try {
                $verifier = $this->authorityVerifier !== null
                    ? (array)call_user_func(
                        $this->authorityVerifier,
                        $hotelId,
                        $targetDate,
                        self::PLATFORMS,
                        (string)($receipt['collection_anchor_hash'] ?? '')
                    )
                    : (new P0OtaFieldLoopVerifierRunner())->verify(
                        $hotelId,
                        $targetDate,
                        self::PLATFORMS,
                        (string)($receipt['collection_anchor_hash'] ?? '')
                    );
            } catch (Throwable $exception) {
                $verifier = [
                    'verification_source' => 'external_p0_verifier',
                    'status' => 'failed',
                    'exit_code' => 1,
                    'authority_ready' => false,
                    'target_date' => $targetDate,
                    'hotel_id' => $hotelId,
                    'required_platforms' => self::PLATFORMS,
                    'verified_platforms' => [],
                    'collection_anchor_hash' => (string)($receipt['collection_anchor_hash'] ?? ''),
                    'issue_codes' => [
                        'p0_verifier_process_failed',
                        $this->safeIdentifier(get_debug_type($exception), 80),
                    ],
                    'continuous_trust_status' => 'not_evaluated',
                    'continuous_trust_missing_steps' => [],
                    'sensitive_values_exposed' => false,
                ];
            }
            $receipt = $policy->attachAuthorityVerifier($receipt, $verifier);
        }

        $cacheStored = true;
        try {
            Cache::set(
                "online_data_p0_authority_receipt_{$hotelId}_{$targetDate}",
                $receipt['authority_verifier'] ?? [],
                86400 * 2
            );
            // Persist the latest attempt even when incomplete so an older
            // authority receipt cannot silently reopen the downstream gate.
            Cache::set(
                "online_data_historical_executed_{$hotelId}_{$targetDate}",
                $receipt,
                86400 * 2
            );
        } catch (Throwable) {
            $cacheStored = false;
        }

        $ready = $cacheStored
            && $policy->dailyTrustReceiptReady($receipt, $targetDate, $hotelId);
        $verifier = is_array($receipt['authority_verifier'] ?? null)
            ? $receipt['authority_verifier']
            : [];
        $blockingInputs = $this->sanitizeFieldKeys($verifier['issue_codes'] ?? []);
        $blockingInputs = array_merge($blockingInputs, $this->sanitizeFieldKeys($scopeIssues));
        if (!$cacheStored) {
            $blockingInputs[] = 'p0_authority_receipt_not_persisted';
        }
        if (!$complete) {
            $blockingInputs[] = 'dual_ota_source_task_anchor_incomplete';
        }
        $blockingInputs = array_values(array_unique($blockingInputs));

        return [
            'status' => $ready
                ? 'ready'
                : ($complete ? 'verifier_incomplete' : 'awaiting_other_platform'),
            'ready' => $ready,
            'verification_source' => (string)($verifier['verification_source'] ?? 'external_p0_verifier'),
            'verifier_status' => (string)($verifier['status'] ?? 'not_run'),
            'verified_platforms' => $this->sanitizeFieldKeys($verifier['verified_platforms'] ?? []),
            'missing_platforms' => $missingPlatforms,
            'blocking_inputs' => $blockingInputs,
            'checked_at' => $this->safeText((string)($verifier['checked_at'] ?? ''), 30),
            'sensitive_values_exposed' => false,
        ];
    }

    private function capturedIdentityError(array $captureSummary, array $mapping): string
    {
        $identity = is_array($captureSummary['platform_identity_validation'] ?? null)
            ? $captureSummary['platform_identity_validation']
            : [];
        $status = strtolower(trim((string)($identity['status'] ?? 'not_checked')));
        $validatedIdentifier = trim((string)($identity['validated_identifier'] ?? ''));
        if ($status === 'mismatch') {
            return '平台真实返回的门店身份与目标门店矛盾，已停止且未入库。';
        }
        if ($status !== 'matched'
            || ($identity['source_validation'] ?? false) !== true
            || $validatedIdentifier === ''
        ) {
            return '平台真实返回的门店身份证据不完整，已停止且未入库；保留本次缺口状态后再定向重试。';
        }
        if ($status === 'matched'
            && $validatedIdentifier !== ''
            && !hash_equals(strtolower((string)$mapping['platform_hotel_id']), strtolower($validatedIdentifier))
        ) {
            return '平台真实返回的门店标识与宿析门店映射不一致，已停止且未入库。';
        }
        return '';
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function captureIdentityMatched(array $captureSummary, array $rows, array $mapping): bool
    {
        $expected = strtolower(trim((string)($mapping['platform_hotel_id'] ?? '')));
        $identity = is_array($captureSummary['platform_identity_validation'] ?? null)
            ? $captureSummary['platform_identity_validation']
            : [];
        $validated = strtolower(trim((string)($identity['validated_identifier'] ?? '')));
        if (strtolower(trim((string)($identity['status'] ?? ''))) === 'matched'
            && ($identity['source_validation'] ?? false) === true
            && $validated !== ''
            && hash_equals($expected, $validated)
        ) {
            return true;
        }
        return false;
    }

    /** @return array<int, string> */
    private function currentMissingFieldKeys(
        string $platform,
        int $hotelId,
        string $dataDate,
        array $additionalRows = [],
        array $identity = []
    ): array
    {
        $required = OtaOrderedCollectionPlanner::requiredFieldKeys($platform);
        $tenantId = $this->strictPositiveInt($identity['tenant_id'] ?? null);
        $accountId = $this->strictPositiveInt($identity['account_id'] ?? null);
        $identityHotelId = $this->strictPositiveInt($identity['system_hotel_id'] ?? null);
        $sourceId = $this->strictPositiveInt($identity['data_source_id'] ?? null);
        $syncTaskId = $this->strictPositiveInt($identity['sync_task_id'] ?? null);
        $identityPlatform = strtolower(trim((string)($identity['platform'] ?? '')));
        $deterministicRowIds = array_values(array_filter(array_map(
            static fn(mixed $value): int => (int)$value,
            (array)($identity['deterministic_row_ids'] ?? [])
        ), static fn(int $value): bool => $value > 0));
        $readbackVerified = ($identity['readback_verified'] ?? false) === true;
        if ($tenantId <= 0
            || $accountId <= 0
            || $identityHotelId !== $hotelId
            || $sourceId <= 0
            || $syncTaskId <= 0
            || $identityPlatform !== strtolower(trim($platform))
            || !$readbackVerified
            || $deterministicRowIds === []
        ) {
            // Without a task/source/readback identity, existing rows are not
            // safe evidence for this account/date. Fail closed.
            return $required;
        }
        if (!$this->tableReadable('online_daily_data')
            || !$this->tableReadable('platform_data_sources')
        ) {
            return $required;
        }
        if ($this->tableReadable('platform_data_sources')) {
            try {
                $source = Db::name('platform_data_sources')
                    ->where('id', $sourceId)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', strtolower(trim($platform)))
                    ->find();
                if (!is_array($source)
                    || (int)($source['id'] ?? 0) !== $sourceId
                    || (int)($source['tenant_id'] ?? 0) !== $tenantId
                    || (int)($source['system_hotel_id'] ?? 0) !== $hotelId
                    || strtolower(trim((string)($source['platform'] ?? ''))) !== strtolower(trim($platform))
                ) {
                    return $required;
                }
            } catch (Throwable) {
                return $required;
            }
        }
        try {
            $columns = Db::getTableInfo('online_daily_data', 'fields');
            $hasSourceId = is_array($columns) && in_array('data_source_id', $columns, true);
            $hasSyncTaskId = is_array($columns) && in_array('sync_task_id', $columns, true);
            $hasTenantId = is_array($columns) && in_array('tenant_id', $columns, true);
            $hasReadbackVerified = is_array($columns) && in_array('readback_verified', $columns, true);
            if (!$hasSourceId || !$hasSyncTaskId || !$hasTenantId || !$hasReadbackVerified) {
                // Without tenant/source/task columns the stored rows cannot
                // prove the submitted task identity. Do not fall back to the
                // client-provided rows or an older date's data.
                return $required;
            }
            $query = Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $dataDate)
                ->where('data_source_id', $sourceId)
                ->where('sync_task_id', $syncTaskId)
                ->where('readback_verified', 1);
            $query->where('tenant_id', $tenantId);
            if ($deterministicRowIds !== []) {
                $query->whereIn('id', $deterministicRowIds);
            }
            $rows = $query->limit(500)->select()->toArray();
        } catch (Throwable) {
            return $required;
        }
        $rows = array_values(array_filter($rows, static function (array $row) use ($platform, $tenantId, $hotelId, $sourceId, $syncTaskId): bool {
            if (array_key_exists('tenant_id', $row) && (int)($row['tenant_id'] ?? 0) !== $tenantId) {
                return false;
            }
            if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($row['data_source_id'] ?? 0) !== $sourceId
                || (int)($row['sync_task_id'] ?? 0) !== $syncTaskId
                || (int)($row['readback_verified'] ?? 0) !== 1
            ) {
                return false;
            }
            $rowPlatform = strtolower(trim((string)($row['source'] ?? $row['platform'] ?? '')));
            return $rowPlatform === $platform;
        }));
        $actualRowIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0)));
        $expectedRowIds = array_values(array_unique($deterministicRowIds));
        sort($actualRowIds, SORT_NUMERIC);
        sort($expectedRowIds, SORT_NUMERIC);
        if ($actualRowIds !== $expectedRowIds) {
            return $required;
        }
        $rows = OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows);
        return OtaOrderedCollectionPlanner::missingFieldKeys(
            $platform,
            $rows
        );
    }

    /** @param array<string, mixed> $resume */
    private function appendResumeCollection(array $parentTask, array $resume): void
    {
        if ($this->taskIdentity($parentTask) === null) {
            return;
        }
        Db::transaction(function () use ($parentTask, $resume): void {
            $task = $this->scopedTaskQuery($parentTask, true)->lock(true)->find();
            if (!is_array($task) || !in_array((string)($task['status'] ?? ''), self::ACTIVE_TASK_STATUSES, true)) {
                return;
            }
            $request = $this->decodeJson($task['request_json'] ?? null);
            $items = is_array($request['resume_collections'] ?? null)
                ? array_values($request['resume_collections'])
                : [];
            $fingerprint = hash('sha256', json_encode([
                (int)($resume['system_hotel_id'] ?? 0),
                (string)($resume['task_type'] ?? ''),
                (string)($resume['data_date'] ?? ''),
                (string)($resume['data_type'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $existingFingerprint = hash('sha256', json_encode([
                    (int)($item['system_hotel_id'] ?? 0),
                    (string)($item['task_type'] ?? ''),
                    (string)($item['data_date'] ?? ''),
                    (string)($item['data_type'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
                if (hash_equals($fingerprint, $existingFingerprint)) {
                    return;
                }
            }
            $items[] = $resume;
            $request['resume_collections'] = array_slice($items, 0, 20);
            $updated = $this->scopedTaskQuery($task, true)->update([
                'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            if ($updated !== 1
                || !is_array($this->scopedTaskQuery($task, true)->find())
            ) {
                throw new RuntimeException('登录预检任务恢复集合回写后精确回读失败。', 409);
            }
        });
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function sortOrderedTasks(array $tasks): array
    {
        usort($tasks, function (array $left, array $right): int {
            foreach ([
                [(int)($left['account_id'] ?? 0), (int)($right['account_id'] ?? 0)],
                [(int)($left['system_hotel_id'] ?? 0), (int)($right['system_hotel_id'] ?? 0)],
            ] as [$leftValue, $rightValue]) {
                if ($leftValue !== $rightValue) {
                    return $leftValue <=> $rightValue;
                }
            }
            $platformOrder = ['ctrip' => 0, 'meituan' => 1];
            $leftPlatform = $platformOrder[strtolower((string)($left['platform'] ?? ''))] ?? 9;
            $rightPlatform = $platformOrder[strtolower((string)($right['platform'] ?? ''))] ?? 9;
            if ($leftPlatform !== $rightPlatform) {
                return $leftPlatform <=> $rightPlatform;
            }
            $leftSession = in_array((string)($left['task_type'] ?? ''), ['login', 'session_probe'], true) ? 0 : 1;
            $rightSession = in_array((string)($right['task_type'] ?? ''), ['login', 'session_probe'], true) ? 0 : 1;
            if ($leftSession !== $rightSession) {
                return $leftSession <=> $rightSession;
            }
            $dateOrder = strcmp((string)($right['data_date'] ?? ''), (string)($left['data_date'] ?? ''));
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            $leftMissing = $this->taskMissingFieldCount($left);
            $rightMissing = $this->taskMissingFieldCount($right);
            if ($leftMissing !== $rightMissing) {
                return $leftMissing <=> $rightMissing;
            }
            $priorityOrder = (int)($right['priority'] ?? 0) <=> (int)($left['priority'] ?? 0);
            return $priorityOrder !== 0
                ? $priorityOrder
                : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });
        return array_values($tasks);
    }

    private function taskMissingFieldCount(array $task): int
    {
        if (array_key_exists('_ordered_missing_field_count', $task)) {
            return max(0, (int)$task['_ordered_missing_field_count']);
        }
        $request = is_array($task['request_summary'] ?? null)
            ? $task['request_summary']
            : $this->publicTaskRequest($this->decodeJson($task['request_json'] ?? null));
        $ordered = is_array($request['ordered_collection'] ?? null) ? $request['ordered_collection'] : [];
        $missing = is_array($ordered['missing_field_keys'] ?? null) ? $ordered['missing_field_keys'] : [];
        if ($missing !== []) {
            return count($missing);
        }
        if ((string)($ordered['stage'] ?? '') === 'yesterday_core') {
            return count((array)($ordered['required_field_keys'] ?? []));
        }
        return 0;
    }

    private function privateTaskMissingFieldCount(array $request): int
    {
        $ordered = is_array($request['ordered_collection'] ?? null)
            ? $request['ordered_collection']
            : [];
        $missing = is_array($ordered['missing_field_keys'] ?? null)
            ? $ordered['missing_field_keys']
            : [];
        if ($missing !== []) {
            return count($missing);
        }
        if ((string)($ordered['stage'] ?? '') === 'yesterday_core') {
            return count((array)($ordered['required_field_keys'] ?? []));
        }

        return 0;
    }

    /**
     * Read-only ordered status for the existing browser Profile mainline.
     * The optional local collector registration must not hide or block this
     * already-authorized account -> hotel -> platform -> date path.
     *
     * @param array{tenant_id:int,user_id:int,hotel_ids:array<int,int>,is_super_admin:bool} $actor
     * @return array<string, mixed>|null
     */
    private function browserProfileOrderedCollectionSnapshot(
        array $actor,
        string $targetDate
    ): ?array {
        if ($actor['hotel_ids'] === []) {
            return null;
        }
        try {
            $sources = Db::name('platform_data_sources')
                ->field('id,tenant_id,system_hotel_id,platform,data_type,ingestion_method,status,enabled,config_json,last_sync_time,last_sync_status,last_error')
                ->where('tenant_id', (int)$actor['tenant_id'])
                ->whereIn('system_hotel_id', $actor['hotel_ids'])
                ->whereIn('platform', self::PLATFORMS)
                ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
                ->where('enabled', 1)
                ->whereIn('status', ['ready', 'success', 'partial_success', 'failed', 'waiting_config'])
                ->select()
                ->toArray();
        } catch (Throwable) {
            return $this->browserProfileReadFailureSnapshot(
                'source_read_failed',
                'platform_data_sources',
                $targetDate
            );
        }
        $sources = OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources);
        if ($sources === []) {
            return null;
        }

        $hotelNames = [];
        $readFailures = [];
        try {
            $hotelNames = Db::name('hotels')
                ->where('tenant_id', (int)$actor['tenant_id'])
                ->whereIn('id', $actor['hotel_ids'])
                ->column('name', 'id');
        } catch (Throwable) {
            $readFailures[] = [
                'reason_code' => 'hotel_names_read_failed',
                'stage' => 'hotels',
            ];
        }

        $sessionProof = new OtaProfileSessionProofService();
        $queue = [];
        $gateMappings = [];
        foreach ($sources as $source) {
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $hotelId = (int)($source['system_hotel_id'] ?? 0);
            $sourceId = (int)($source['id'] ?? 0);
            if (!in_array($platform, self::PLATFORMS, true)
                || $hotelId <= 0
                || $sourceId <= 0
            ) {
                continue;
            }

            $gateMappings[] = [
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'status' => 'active',
            ];
            $sourceStatus = strtolower(trim((string)($source['status'] ?? '')));
            $sourceRecoveryRequired = in_array($sourceStatus, ['failed', 'waiting_config'], true);
            try {
                $rows = $this->browserProfileStoredRows(
                    $hotelId,
                    $sourceId,
                    $platform,
                    $targetDate
                );
            } catch (Throwable) {
                return $this->browserProfileReadFailureSnapshot(
                    'stored_rows_read_failed',
                    'online_daily_data',
                    $targetDate,
                    count($sources)
                );
            }
            $plan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
                $platform,
                $targetDate,
                $rows,
                $sourceRecoveryRequired
            );
            try {
                $syncState = $this->browserProfileSyncTaskState(
                    $source,
                    $targetDate
                );
            } catch (Throwable) {
                return $this->browserProfileReadFailureSnapshot(
                    'task_state_read_failed',
                    'platform_data_sync_tasks',
                    $targetDate,
                    count($sources)
                );
            }
            if (($plan['sections'] ?? []) === []
                && ($syncState['verified_readback'] ?? false) !== true
            ) {
                $plan = OtaOrderedCollectionPlanner::requestPlan(
                    $platform,
                    $targetDate,
                    OtaOrderedCollectionPlanner::requiredFieldKeys($platform),
                    'verified_rows_without_bound_run_readback'
                );
                $plan['stage'] = 'conflict_recovery';
                $plan['source_recovery_required'] = true;
                $plan['eligible_row_count'] = count(
                    OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows)
                );
            }

            $blockingStatus = $sessionProof->currentSessionBlockingStatus($source);
            $responseIdentityProbeAllowed = $sessionProof
                ->canAttemptResponseIdentityValidation($source);
            $hardBlock = in_array($blockingStatus, [
                'anti_bot',
                'cookies_incomplete',
                'identity_mismatch',
                'login_required',
                'session_expired',
                'login_expired',
                'platform_contract_drift',
                'permission_denied',
                'capture_failed',
            ], true) && !$responseIdentityProbeAllowed;
            $activeTask = is_array($syncState['active_task'] ?? null)
                ? $syncState['active_task']
                : [];
            $missing = $this->sanitizeFieldKeys($plan['missing_field_keys'] ?? []);
            $stage = (string)($plan['stage'] ?? '');
            $status = $activeTask !== []
                ? (string)($activeTask['status'] ?? 'running')
                : ($hardBlock
                    ? (in_array($blockingStatus, ['anti_bot', 'platform_contract_drift'], true)
                        ? 'verification_required'
                        : 'login_required')
                    : ($stage === 'verified_complete' ? 'verified_complete' : 'not_queued'));
            $fieldLabel = $stage === 'verified_complete'
                ? '昨日核心事实已回读'
                : ($stage === 'targeted_gap' ? '昨日缺口字段' : '昨日核心事实');
            $reason = match (true) {
                $hardBlock => '当前 Profile 会话有明确阻塞：' . $blockingStatus,
                $responseIdentityProbeAllowed && $blockingStatus !== '' => '旧身份状态与当前已登录、同门店页面证据冲突，允许本次安全探测',
                $stage === 'conflict_recovery' => '来源状态或任务回读锚点冲突，需要安全探测后重抓',
                $stage === 'yesterday_core' => '目标日期尚无可验收的核心事实',
                $stage === 'targeted_gap' => '目标日期仅剩明确字段缺口',
                default => '目标日期核心事实已保存并绑定任务回读',
            };
            $nextAction = match (true) {
                $responseIdentityProbeAllowed => '复用当前已登录 Profile 做一次安全采集探测；本次真实返回身份不一致时立即停止且不入库。',
                $blockingStatus === 'identity_mismatch' => '停止采集；确认当前 Profile 的平台酒店与宿析门店一致后再继续。',
                in_array($blockingStatus, ['anti_bot', 'platform_contract_drift'], true) => '停止自动重试；处理平台验证或接口变化后再继续。',
                in_array($blockingStatus, ['login_required', 'session_expired', 'login_expired', 'cookies_incomplete'], true) => '打开现有 Profile 做一次会话检查；只有平台明确失效时才重新登录。',
                $activeTask !== [] => '等待当前 Profile 任务完成保存、数据库回读和真实 verifier 校验。',
                $stage === 'verified_complete' => '等待双平台真实 verifier 一致后再放行正式收益和日报。',
                default => '复用现有 Profile，按所列缺口和既有接口定向采集；不做无边界全量扫描。',
            };
            $accountScope = OtaOrderedCollectionPlanner::browserProfileAccountScopeKey($source);
            $queue[] = [
                'task_id' => max(0, (int)($activeTask['task_id'] ?? 0)),
                'data_source_id' => $sourceId,
                'source_mode' => 'browser_profile',
                'account_scope' => substr(hash('sha256', $accountScope), 0, 12),
                'account_alias' => strtoupper($platform) . ' Profile',
                'system_hotel_id' => $hotelId,
                'hotel_name' => (string)($hotelNames[$hotelId] ?? ''),
                'platform' => $platform,
                'target_date' => $targetDate,
                'task_type' => $hardBlock ? 'session_probe' : 'collect',
                'field_label' => $fieldLabel,
                'missing_field_keys' => $missing,
                'sections' => $this->sanitizeSections($plan['sections'] ?? []),
                'interface_ids' => $this->sanitizeFieldKeys($plan['interface_ids'] ?? []),
                'field_completeness' => $stage === 'verified_complete' ? 'complete' : 'gap_pending',
                'status' => $status,
                'reason' => $reason,
                'next_action' => $nextAction,
            ];
        }
        if ($queue === []) {
            return null;
        }

        usort($queue, static function (array $left, array $right): int {
            $platformOrder = ['ctrip' => 0, 'meituan' => 1];
            return [
                (string)($left['account_scope'] ?? ''),
                (int)($left['system_hotel_id'] ?? 0),
                $platformOrder[(string)($left['platform'] ?? '')] ?? 9,
                (string)($left['target_date'] ?? ''),
                count((array)($left['missing_field_keys'] ?? [])),
                (int)($left['data_source_id'] ?? 0),
            ] <=> [
                (string)($right['account_scope'] ?? ''),
                (int)($right['system_hotel_id'] ?? 0),
                $platformOrder[(string)($right['platform'] ?? '')] ?? 9,
                (string)($right['target_date'] ?? ''),
                count((array)($right['missing_field_keys'] ?? [])),
                (int)($right['data_source_id'] ?? 0),
            ];
        });

        $current = null;
        foreach ($queue as $row) {
            if (in_array((string)($row['status'] ?? ''), [
                'pending',
                'queued',
                'running',
                'browser_opened',
                'syncing',
                'syncing_after_login',
                'retry_wait',
            ], true)) {
                $current = $row;
                break;
            }
        }
        $next = null;
        foreach ($queue as $row) {
            if ($current !== null
                && (int)($row['task_id'] ?? 0) === (int)($current['task_id'] ?? 0)
                && (int)($row['data_source_id'] ?? 0) === (int)($current['data_source_id'] ?? 0)
            ) {
                continue;
            }
            if ((string)($row['field_completeness'] ?? '') !== 'complete'
                || in_array((string)($row['status'] ?? ''), ['login_required', 'verification_required'], true)
            ) {
                $next = $row;
                break;
            }
        }

        $gate = $this->orderedCollectionGate($gateMappings, $targetDate);
        $gapReport = $this->orderedYesterdayGapStatus($gate, $targetDate);
        if ($next === null && ($gate['ready'] ?? false) !== true) {
            $next = $queue[0];
        }
        $nextAction = $current !== null
            ? '等待当前 Profile 任务完成保存、数据库回读和真实 verifier 校验。'
            : ($next !== null
                ? (string)($next['next_action'] ?? '')
                : '双 OTA 目标日期已通过真实 verifier，可进入正式收益分析和日报。');

        return [
            'contract_version' => OtaOrderedCollectionPlanner::CONTRACT_VERSION,
            'source_mode' => 'browser_profile',
            'status' => $readFailures === [] ? 'ready' : 'partial',
            'data_status' => $readFailures === [] ? 'ok' : 'partial',
            'read_failures' => $readFailures,
            'local_collector_required' => false,
            'source_count' => count($queue),
            'target_date' => $targetDate,
            'order_by' => ['account', 'hotel', 'platform', 'target_date', 'field_completeness'],
            'current' => $current,
            'next' => $next,
            'queue' => $queue,
            'gate' => $gate,
            'gap_report' => $gapReport,
            'next_action' => $nextAction,
            'scope_boundary' => '仅昨日 OTA 核心事实；评论、实时、广告和子渠道范例不在本次交付范围。',
        ];
    }

    /** @return array<string, mixed> */
    private function browserProfileReadFailureSnapshot(
        string $reasonCode,
        string $stage,
        string $targetDate,
        ?int $sourceCount = null
    ): array {
        return [
            'contract_version' => OtaOrderedCollectionPlanner::CONTRACT_VERSION,
            'source_mode' => 'browser_profile',
            'status' => 'blocked',
            'data_status' => 'read_failed',
            'reason_code' => $reasonCode,
            'stage' => $stage,
            'read_failures' => [[
                'reason_code' => $reasonCode,
                'stage' => $stage,
            ]],
            'local_collector_required' => false,
            'source_count' => $sourceCount,
            'target_date' => $targetDate,
            'order_by' => ['account', 'hotel', 'platform', 'target_date', 'field_completeness'],
            'current' => null,
            'next' => null,
            'queue' => [],
            'gate' => [
                'ready' => false,
                'status' => 'blocked_by_source_read_failure',
                'reason_code' => $reasonCode,
                'stage' => $stage,
                'formal_revenue_ready' => false,
                'formal_report_ready' => false,
            ],
            'gap_report' => [
                'status' => 'blocked',
                'gap_codes' => [$reasonCode],
                'reason_code' => $reasonCode,
                'stage' => $stage,
            ],
            'next_action' => '数据读取失败，未按无来源、零行或无任务处理；请恢复数据库读取后重试。',
            'scope_boundary' => '仅昨日 OTA 核心事实；读取失败时不生成经营结论。',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function browserProfileStoredRows(
        int $hotelId,
        int $sourceId,
        string $platform,
        string $targetDate
    ): array {
        return Db::name('online_daily_data')
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('data_date', $targetDate)
            ->where(static function ($query) use ($platform): void {
                $query->where('platform', $platform)->whereOr('source', $platform);
            })
            ->limit(500)
            ->select()
            ->toArray();
    }

    /**
     * @param array<string, mixed> $source
     * @return array{active_task:?array<string,mixed>,verified_readback:bool}
     */
    private function browserProfileSyncTaskState(array $source, string $targetDate): array
    {
        $empty = ['active_task' => null, 'verified_readback' => false];
        $sourceId = (int)($source['id'] ?? 0);
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $tasks = Db::name('platform_data_sync_tasks')
            ->where('tenant_id', (int)($source['tenant_id'] ?? 0))
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->order('id', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        $activeTask = null;
        $verifiedReadback = false;
        foreach ($tasks as $task) {
            $stats = $this->decodeJson($task['stats_json'] ?? null);
            $ordered = is_array($stats['ordered_collection'] ?? null)
                ? $stats['ordered_collection']
                : [];
            $readback = is_array($stats['run_readback'] ?? null)
                ? $stats['run_readback']
                : [];
            $taskDate = substr(trim((string)(
                $ordered['target_date']
                ?? $readback['target_date']
                ?? ''
            )), 0, 10);
            if ($taskDate !== $targetDate) {
                continue;
            }
            $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
            if ($activeTask === null && in_array($taskStatus, [
                'pending',
                'queued',
                'running',
                'browser_opened',
                'syncing',
                'syncing_after_login',
                'retry_wait',
            ], true)) {
                $activeTask = [
                    'task_id' => (int)($task['id'] ?? 0),
                    'status' => $taskStatus,
                ];
            }
            $metricKeys = $this->sanitizeFieldKeys($readback['verified_metric_keys'] ?? []);
            if (in_array($taskStatus, ['success', 'partial_success'], true)
                && ($readback['readback_verified'] ?? false) === true
                && strtolower(trim((string)($readback['p0_status'] ?? ''))) === 'ready'
                && (int)($readback['sync_task_id'] ?? 0) > 0
                && (int)($readback['data_source_id'] ?? 0) === $sourceId
                && (int)($readback['system_hotel_id'] ?? 0) === $hotelId
                && strtolower(trim((string)($readback['platform'] ?? ''))) === $platform
                && trim((string)($readback['started_at'] ?? '')) !== ''
                && array_values(array_filter(
                    is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : [],
                    static fn($value): bool => (int)$value > 0
                )) !== []
                && array_values(array_filter(
                    is_array($readback['source_trace_ids'] ?? null) ? $readback['source_trace_ids'] : [],
                    static fn($value): bool => trim((string)$value) !== ''
                )) !== []
                && count(array_intersect(['revenue', 'room_nights', 'adr'], $metricKeys)) === 3
            ) {
                $verifiedReadback = true;
            }
        }
        return [
            'active_task' => $activeTask,
            'verified_readback' => $verifiedReadback,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, array<string, mixed>> $deviceMap
     * @return array<string, mixed>
     */
    private function orderedCollectionSnapshot(
        array $accounts,
        array $mappings,
        array $tasks,
        array $deviceMap,
        string $targetDate
    ): array {
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)($account['id'] ?? 0)] = $account;
        }
        $active = array_values(array_filter(
            $tasks,
            static fn(array $task): bool => in_array(
                (string)($task['status'] ?? ''),
                self::ACTIVE_TASK_STATUSES,
                true
            )
        ));
        $active = $this->sortOrderedTasks($active);
        $queue = array_map(
            fn(array $task): array => $this->orderedTaskSummary($task, $accountMap),
            $active
        );
        $current = null;
        foreach ($queue as $row) {
            if (in_array((string)($row['status'] ?? ''), ['leased', 'running', 'waiting_user_login', 'verification_required'], true)) {
                $current = $row;
                break;
            }
        }
        $next = null;
        foreach ($queue as $row) {
            if ($current !== null && (int)($row['task_id'] ?? 0) === (int)($current['task_id'] ?? 0)) {
                continue;
            }
            if (in_array((string)($row['status'] ?? ''), ['queued', 'retry_wait'], true)) {
                $next = $row;
                break;
            }
        }
        if ($current === null && $next === null && $queue !== []) {
            $next = $queue[0];
        }

        $gate = $this->orderedCollectionGate($mappings, $targetDate);
        $gapReport = $this->orderedYesterdayGapStatus($gate, $targetDate);
        if ($next === null && ($gate['ready'] ?? false) !== true) {
            $next = $this->virtualOrderedNext($accounts, $mappings, $deviceMap, $targetDate);
        }
        $nextAction = $current !== null
            ? '等待当前本机任务完成保存、回读和真实 P0 校验。'
            : ($next !== null
                ? (string)($next['next_action'] ?? '保持本机采集器在线，系统只按当前缺口执行下一项。')
                : (($gate['ready'] ?? false) === true
                    ? '双 OTA 目标日已验证，可进入收益分析和正式日报。'
                    : (string)$gapReport['next_action']));

        return [
            'contract_version' => OtaOrderedCollectionPlanner::CONTRACT_VERSION,
            'target_date' => $targetDate,
            'order_by' => ['account', 'hotel', 'platform', 'target_date', 'field_completeness'],
            'current' => $current,
            'next' => $next,
            'queue' => $queue,
            'gate' => $gate,
            'gap_report' => $gapReport,
            'next_action' => $nextAction,
            'scope_boundary' => '仅昨天 OTA 核心事实；评论、实时、广告和子渠道范例不在本次交付范围。',
        ];
    }

    /** @param array<int, array<string, mixed>> $accountMap @return array<string, mixed> */
    private function orderedTaskSummary(array $task, array $accountMap): array
    {
        $request = is_array($task['request_summary'] ?? null)
            ? $task['request_summary']
            : $this->publicTaskRequest($this->decodeJson($task['request_json'] ?? null));
        $ordered = is_array($request['ordered_collection'] ?? null) ? $request['ordered_collection'] : [];
        $taskType = (string)($task['task_type'] ?? '');
        $fieldLabel = in_array($taskType, ['login', 'session_probe'], true)
            ? '账户会话检查'
            : ((string)($ordered['stage'] ?? '') === 'targeted_gap' ? '缺口字段' : '昨日核心事实');
        $account = $accountMap[(int)($task['account_id'] ?? 0)] ?? [];
        $hotelName = '';
        foreach (is_array($account['hotels'] ?? null) ? $account['hotels'] : [] as $mapping) {
            if ((int)($mapping['system_hotel_id'] ?? 0) === (int)($task['system_hotel_id'] ?? 0)) {
                $hotelName = (string)($mapping['platform_hotel_name'] ?? '');
                break;
            }
        }
        $reason = (string)($request['reason'] ?? '');
        return [
            'task_id' => (int)($task['id'] ?? 0),
            'account_id' => (int)($task['account_id'] ?? 0),
            'account_alias' => (string)($account['account_alias'] ?? ''),
            'system_hotel_id' => (int)($task['system_hotel_id'] ?? 0),
            'hotel_name' => $hotelName,
            'platform' => (string)($task['platform'] ?? ''),
            'target_date' => (string)($task['data_date'] ?? $ordered['target_date'] ?? ''),
            'task_type' => $taskType,
            'field_label' => $fieldLabel,
            'missing_field_keys' => array_values((array)($ordered['missing_field_keys'] ?? [])),
            'field_completeness' => $this->taskMissingFieldCount($task) === 0 ? 'complete_or_session' : 'gap_pending',
            'status' => (string)($task['status'] ?? ''),
            'reason' => $this->orderedReasonText($reason, $fieldLabel),
            'next_action' => $this->recoveryGuide(
                (string)($task['error_code'] ?? ''),
                (string)($task['platform'] ?? ''),
                'online',
                (string)($task['available_at'] ?? '')
            )['next_action'],
        ];
    }

    private function orderedReasonText(string $reason, string $fallback): string
    {
        return [
            'account_session_preflight' => '先确认账户登录态与目标门店',
            'automatic_yesterday_gap_recovery' => '昨天核心字段仍有缺口',
            'automatic_bounded_gap_recovery' => '昨天完成后处理最近三天明确缺口',
            'targeted_gap_recovery' => '只补抓当前缺失字段',
            'yesterday_core' => '默认先采昨天核心事实',
            'manual_backfill' => '用户发起明确的手动补抓',
        ][$reason] ?? ($reason !== '' ? $reason : $fallback);
    }

    /**
     * @param array<int, array<string, mixed>> $mappings
     * @return array<string, mixed>
     */
    private function orderedCollectionGate(array $mappings, string $targetDate): array
    {
        $byHotel = [];
        foreach ($mappings as $mapping) {
            if ((string)($mapping['status'] ?? 'active') !== 'active') {
                continue;
            }
            $hotelId = (int)($mapping['system_hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                continue;
            }
            $platform = strtolower(trim((string)($mapping['platform'] ?? '')));
            if (in_array($platform, self::PLATFORMS, true)) {
                $byHotel[$hotelId][$platform] = true;
            }
        }
        $hotels = [];
        $allReady = $byHotel !== [];
        foreach ($byHotel as $hotelId => $platforms) {
            $mappingReady = count(array_intersect(self::PLATFORMS, array_keys($platforms))) === count(self::PLATFORMS);
            $trust = [];
            $downstreamGate = [];
            if ($mappingReady) {
                try {
                    $trust = $this->trustResolver !== null
                        ? (array)call_user_func($this->trustResolver, (int)$hotelId, $targetDate, $targetDate)
                        : (new DualOtaContinuousTrustService())->inspectHotel((int)$hotelId, $targetDate, $targetDate);
                } catch (Throwable $e) {
                    $trust = ['status' => 'partial', 'reason' => $this->safeIdentifier($e->getMessage(), 100)];
                }
                try {
                    $downstreamGate = $this->downstreamGateResolver !== null
                        ? (array)call_user_func(
                            $this->downstreamGateResolver,
                            $targetDate,
                            (int)$hotelId
                        )
                        : (new P0OtaDownstreamGateService())->resolveRuntime(
                            $targetDate,
                            (int)$hotelId,
                            null,
                            self::PLATFORMS
                        );
                } catch (Throwable $e) {
                    $downstreamGate = [
                        'status' => 'blocked_by_p0_ota_gate',
                        'blocking_missing_inputs' => [
                            'runtime_downstream_gate_unavailable',
                            $this->safeIdentifier($e->getMessage(), 100),
                        ],
                    ];
                }
            }
            $trustReady = $mappingReady && strtolower(trim((string)($trust['status'] ?? 'partial'))) === 'verified';
            $downstreamReady = $mappingReady
                && strtolower(trim((string)($downstreamGate['status'] ?? ''))) === 'ready';
            $hotelReady = $trustReady && $downstreamReady;
            $allReady = $allReady && $hotelReady;
            $hotels[] = [
                'system_hotel_id' => (int)$hotelId,
                'mapped_platforms' => array_values(array_intersect(self::PLATFORMS, array_keys($platforms))),
                'mapping_status' => $mappingReady ? 'ready' : 'missing_platform_binding',
                'continuous_trust_status' => (string)($trust['status'] ?? 'partial'),
                'p0_downstream_gate_status' => (string)($downstreamGate['status'] ?? 'not_evaluated'),
                'blocking_inputs' => $this->sanitizeFieldKeys(
                    $downstreamGate['blocking_missing_inputs'] ?? []
                ),
                'ready' => $hotelReady,
                'reason' => !$mappingReady
                    ? 'ctrip_or_meituan_binding_missing'
                    : (!$trustReady
                        ? ((string)($trust['reason'] ?? '') ?: 'continuous_trust_not_verified')
                        : (!$downstreamReady ? 'external_p0_verifier_receipt_not_ready' : '')),
            ];
        }

        return [
            'status' => $allReady ? 'ready' : 'blocked',
            'ready' => $allReady,
            'label' => $allReady ? '双 OTA 已验证' : '等待双 OTA 保存、回读与 P0 验证',
            'formal_revenue_ready' => $allReady,
            'formal_report_ready' => $allReady,
            'hotel_states' => $hotels,
        ];
    }

    /** @return array<string, mixed> */
    private function orderedYesterdayGapStatus(
        array $gate,
        string $targetDate,
        ?\DateTimeImmutable $now = null
    ): array {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
        $clock = $now->format('H:i');
        $ready = ($gate['ready'] ?? false) === true;
        $cutoffReached = $clock >= self::YESTERDAY_WINDOW_CUTOFF;
        $windowStarted = $clock >= self::YESTERDAY_WINDOW_START;
        $missingPlatforms = [];
        $gapCodes = [];
        $hotelStates = is_array($gate['hotel_states'] ?? null)
            ? $gate['hotel_states']
            : [];
        if ($hotelStates === []) {
            $missingPlatforms = self::PLATFORMS;
            $gapCodes[] = 'local_account_hotel_binding_missing';
        }
        foreach ($hotelStates as $hotelState) {
            if (!is_array($hotelState) || ($hotelState['ready'] ?? false) === true) {
                continue;
            }
            $mapped = array_values(array_intersect(
                self::PLATFORMS,
                $this->sanitizeFieldKeys($hotelState['mapped_platforms'] ?? [])
            ));
            foreach (array_diff(self::PLATFORMS, $mapped) as $platform) {
                $missingPlatforms[$platform] = $platform;
            }
            $platformGapFound = false;
            foreach ($this->sanitizeFieldKeys($hotelState['blocking_inputs'] ?? []) as $code) {
                $gapCodes[$code] = $code;
                foreach (self::PLATFORMS as $platform) {
                    if (str_starts_with($code, $platform . '_')) {
                        $missingPlatforms[$platform] = $platform;
                        $platformGapFound = true;
                    }
                }
            }
            $reason = $this->safeIdentifier((string)($hotelState['reason'] ?? ''), 100);
            if ($reason !== '') {
                $gapCodes[$reason] = $reason;
            }
            if ($mapped !== [] && !$platformGapFound) {
                foreach ($mapped as $platform) {
                    $missingPlatforms[$platform] = $platform;
                }
            }
        }
        $missingPlatforms = array_values(array_unique($missingPlatforms));
        sort($missingPlatforms, SORT_STRING);
        $gapCodes = array_values(array_unique($gapCodes));
        sort($gapCodes, SORT_STRING);

        $status = $ready
            ? 'ready'
            : ($cutoffReached
                ? 'gap'
                : ($windowStarted ? 'collecting_and_recovering' : 'awaiting_collection_window'));
        $nextAction = match ($status) {
            'ready' => '双 OTA 已通过保存、回读和权威 P0 verifier，可发布正式收益与日报。',
            'gap' => '09:00 截止后仍有缺口：正式收益与日报保持阻断；保持本机采集器在线并只补抓所列平台。',
            'collecting_and_recovering' => '08:30–09:00 数据齐全窗口内，保持本机采集器在线，系统按明确缺口自动补抓。',
            default => '保持本机采集器在线；08:30 起按昨天的明确缺口自动补抓，也可提前手动启动。',
        };

        return [
            'status' => $status,
            'report_kind' => $ready
                ? 'official_ready'
                : ($cutoffReached ? 'explicit_gap_report' : 'pending_status'),
            'formal_report_allowed' => $ready,
            'target_date' => $targetDate,
            'window_start' => self::YESTERDAY_WINDOW_START,
            'cutoff_time' => self::YESTERDAY_WINDOW_CUTOFF,
            'cutoff_reached' => $cutoffReached,
            'auto_recollection' => !$ready,
            'missing_platforms' => $ready ? [] : $missingPlatforms,
            'gap_codes' => $ready ? [] : $gapCodes,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, array<string, mixed>> $mappings
     * @param array<int, array<string, mixed>> $deviceMap
     * @return array<string, mixed>|null
     */
    private function virtualOrderedNext(
        array $accounts,
        array $mappings,
        array $deviceMap,
        string $targetDate
    ): ?array {
        usort($accounts, static fn(array $left, array $right): int =>
            (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
        );
        usort($mappings, static function (array $left, array $right): int {
            $accountOrder = (int)($left['account_id'] ?? 0) <=> (int)($right['account_id'] ?? 0);
            if ($accountOrder !== 0) {
                return $accountOrder;
            }
            $hotelOrder = (int)($left['system_hotel_id'] ?? 0) <=> (int)($right['system_hotel_id'] ?? 0);
            if ($hotelOrder !== 0) {
                return $hotelOrder;
            }
            return strcmp((string)($left['platform'] ?? ''), (string)($right['platform'] ?? ''));
        });
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)($account['id'] ?? 0)] = $account;
        }
        foreach ($mappings as $mapping) {
            $account = $accountMap[(int)($mapping['account_id'] ?? 0)] ?? null;
            if (!is_array($account) || (string)($mapping['status'] ?? 'active') !== 'active') {
                continue;
            }
            $platform = (string)($account['platform'] ?? $mapping['platform'] ?? '');
            $deviceStatus = (string)($deviceMap[(int)($account['device_id'] ?? 0)]['effective_status'] ?? 'device_offline');
            if ($deviceStatus !== 'online') {
                return [
                    'account_id' => (int)$account['id'],
                    'system_hotel_id' => (int)$mapping['system_hotel_id'],
                    'hotel_name' => (string)($mapping['platform_hotel_name'] ?? ''),
                    'platform' => $platform,
                    'target_date' => $targetDate,
                    'task_type' => 'session_probe',
                    'field_label' => '账户会话检查',
                    'status' => 'device_offline',
                    'reason' => '绑定电脑上的采集器离线',
                    'next_action' => '启动该账号绑定电脑上的本机采集器。',
                ];
            }
            if ((string)($account['session_status'] ?? '') !== 'current_session_verified') {
                return [
                    'account_id' => (int)$account['id'],
                    'system_hotel_id' => (int)$mapping['system_hotel_id'],
                    'hotel_name' => (string)($mapping['platform_hotel_name'] ?? ''),
                    'platform' => $platform,
                    'target_date' => $targetDate,
                    'task_type' => 'session_probe',
                    'field_label' => '账户会话检查',
                    'status' => 'login_required',
                    'reason' => '账户登录态尚未确认',
                    'next_action' => '发起一次本机会话检查；只有平台要求时才需重新登录。',
                ];
            }
            $missing = $this->currentMissingFieldKeys(
                $platform,
                (int)$mapping['system_hotel_id'],
                $targetDate
            );
            return [
                'account_id' => (int)$account['id'],
                'system_hotel_id' => (int)$mapping['system_hotel_id'],
                'hotel_name' => (string)($mapping['platform_hotel_name'] ?? ''),
                'platform' => $platform,
                'target_date' => $targetDate,
                'task_type' => 'backfill',
                'field_label' => $missing !== [] ? '缺口字段' : 'P0 验证缺口',
                'missing_field_keys' => $missing,
                'status' => 'not_queued',
                'reason' => $missing !== [] ? '目标日字段尚未齐全' : '字段已保存但真实门禁仍未通过',
                'next_action' => $missing !== []
                    ? '保持本机采集器在线，按缺口定向补抓。'
                    : '检查原始保存、数据库回读、门店身份与 P0 verifier 失败项。',
            ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function sanitizeCapabilities(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $this->assertNoSensitiveMaterial($value, 'capabilities');
        $result = [];
        foreach (array_slice($value, 0, 30, true) as $key => $item) {
            $safeKey = $this->safeIdentifier((string)$key, 50);
            if ($safeKey === '') {
                continue;
            }
            if (is_bool($item) || is_int($item) || is_float($item)) {
                $result[$safeKey] = $item;
            } elseif (is_string($item)) {
                $result[$safeKey] = $this->safeText($item, 100);
            }
        }
        return $result;
    }

    /** @return array<int, string> */
    private function sanitizeSections(mixed $sections): array
    {
        if (is_string($sections)) {
            $sections = preg_split('/[,\s]+/', $sections) ?: [];
        }
        if (!is_array($sections)) {
            return [];
        }
        $result = [];
        foreach ($sections as $section) {
            $safe = $this->safeIdentifier((string)$section, 50);
            if ($safe !== '') {
                $result[$safe] = true;
            }
        }
        return array_slice(array_keys($result), 0, 20);
    }

    private function assertNoSensitiveMaterial(mixed $value, string $path = 'result'): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                // Preserve casing here: isSensitiveKey() must split camelCase
                // before normalizing, otherwise rawSession can bypass the gate.
                $keyText = (string)$key;
                if ($this->isSensitiveKey($keyText) && $this->hasValue($item)) {
                    throw new RuntimeException('本机结果包含禁止上传的敏感字段：' . $path . '.' . $keyText, 422);
                }
                $this->assertNoSensitiveMaterial($item, $path . '.' . $keyText);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        if (preg_match('/\b(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key)\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1
        ) {
            throw new RuntimeException('本机结果文本包含疑似会话凭据，已拒绝上传。', 422);
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string)$key) ?? (string)$key;
        $key = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key) ?? $key;
        $key = trim((string)preg_replace('/[^a-z0-9]+/i', '_', strtolower($key)), '_');
        if (preg_match('/(?:^|_)(?:cookies?|tokens?|authorization|password|secret|api_key|headers?|profile_dir|profile_path|local_storage|session_storage|webhook|raw_(?:response|request|data|session))(?:_|$)/i', $key) === 1) {
            return true;
        }
        return preg_match('/(?:^|_)(?:cookie|token|auth|session|profile)_(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)(?:_|$)|^(?:cookie|token|auth|session|profile)(?:value|token|cookie|cookies|header|headers|path|dir|data|storage|raw)$/i', $key) === 1;
    }

    private function hasValue(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && trim((string)$value) !== '';
    }

    private function normalizeFailureCode(mixed $value): string
    {
        $code = strtolower(trim((string)$value));
        $aliases = [
            'logged_out' => 'login_required',
            'not_logged_in' => 'login_required',
            'unauthorized' => 'login_required',
            'forbidden' => 'permission_denied',
            'captcha' => 'captcha_required',
            'risk_control' => 'anti_bot',
            'resource_busy_login' => 'resource_busy',
            'browser_busy' => 'resource_busy',
            'profile_corrupt' => 'profile_corrupted',
            'failed' => 'collection_failed',
            'capture_failed' => 'collection_failed',
        ];
        $code = $aliases[$code] ?? $code;
        return $this->safeIdentifier($code, 80) ?: 'collection_failed';
    }

    private function normalizePlatform(mixed $value): string
    {
        $platform = strtolower(trim((string)$value));
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new RuntimeException('仅支持携程或美团本机采集账户。', 422);
        }
        return $platform;
    }

    private function normalizePlatformHotelId(mixed $value): string
    {
        $value = trim((string)$value);
        return preg_match('/^[A-Za-z0-9._:-]{1,120}$/D', $value) === 1 ? $value : '';
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return '';
        }
        $timestamp = strtotime($value . ' 00:00:00');
        return $timestamp !== false && date('Y-m-d', $timestamp) === $value ? $value : '';
    }

    private function normalizeDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d H:i:s', $timestamp);
    }

    private function safeIdentifier(string $value, int $maxLength): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?: '';
        return mb_substr(trim($value, '_'), 0, $maxLength, 'UTF-8');
    }

    private function safeText(string $value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?: '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';
        $value = str_replace(['<', '>'], ['＜', '＞'], $value);
        return mb_substr($value, 0, max(1, $maxLength), 'UTF-8');
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstText(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || !is_scalar($row[$key])) {
                continue;
            }
            $value = trim((string)$row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
