<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Read-only, hotel-scoped status projection for the cloud three-source loop.
 *
 * This service never starts collection, opens a browser, or sends a message.
 * A source is ready only when the current business date has a recent, formally
 * saved and read-back-verified snapshot for the exact tenant/hotel/platform.
 */
final class CloudThreeSourceHourlyStatusService
{
    public const CONTRACT_VERSION = 'cloud_three_source_hourly_status.v1';
    public const PROFILE_EXPIRING_SOON_SECONDS = 86400;

    private const TIMEZONE = 'Asia/Shanghai';
    private const PROFILE_METHODS = ['browser_profile', 'profile_browser'];
    private const READY_PROFILE_STATUS = 'ready_to_collect';
    private const SUCCESS_TASK_STATUSES = ['success', 'partial_success'];

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    /** @return array<string, mixed> */
    public function status(
        int $tenantId,
        int $hotelId,
        string $businessDate = ''
    ): array {
        $now = ($this->clock)()->setTimezone(new DateTimeZone(self::TIMEZONE));
        $businessDate = trim($businessDate) !== ''
            ? trim($businessDate)
            : $now->format('Y-m-d');

        if ($tenantId <= 0 || $hotelId <= 0 || !$this->validDate($businessDate)) {
            $sources = [
                'dingdandao_pms' => $this->source(
                    'dingdandao_pms',
                    'unknown',
                    '订单来了 PMS',
                    '未取得有效的租户、酒店或业务日期范围。',
                    null,
                    'collect_now'
                ),
                'ctrip' => $this->source(
                    'ctrip',
                    'unknown',
                    '携程',
                    '未取得有效的租户、酒店或业务日期范围。',
                    null,
                    'collect_now'
                ),
                'meituan' => $this->source(
                    'meituan',
                    'unknown',
                    '美团',
                    '未取得有效的租户、酒店或业务日期范围。',
                    null,
                    'collect_now'
                ),
            ];
            return $this->contract($tenantId, $hotelId, $businessDate, $now, $sources);
        }

        $sources = [
            'dingdandao_pms' => $this->safeSource(
                fn(): array => $this->dingdandaoStatus(
                    $tenantId,
                    $hotelId,
                    $businessDate,
                    $now
                ),
                'dingdandao_pms',
                '订单来了 PMS'
            ),
            'ctrip' => $this->safeSource(
                fn(): array => $this->otaStatus(
                    $tenantId,
                    $hotelId,
                    'ctrip',
                    $businessDate,
                    $now
                ),
                'ctrip',
                '携程'
            ),
            'meituan' => $this->safeSource(
                fn(): array => $this->otaStatus(
                    $tenantId,
                    $hotelId,
                    'meituan',
                    $businessDate,
                    $now
                ),
                'meituan',
                '美团'
            ),
        ];

        return $this->contract($tenantId, $hotelId, $businessDate, $now, $sources);
    }

    /** @return array<string, mixed> */
    private function contract(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        DateTimeImmutable $now,
        array $sources
    ): array {
        $statuses = array_map(
            static fn(array $source): string => (string)($source['status'] ?? 'unknown'),
            $sources
        );
        $overall = $this->overallStatus($statuses);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'business_date' => $businessDate,
            'observed_at' => $now->format('Y-m-d H:i:s'),
            'max_source_age_minutes' => (int)floor(
                CloudThreeSourceHourlyPayloadService::MAX_SOURCE_AGE_SECONDS / 60
            ),
            'status' => $overall,
            'ready' => $overall === 'ready',
            'sources' => $sources,
        ];
    }

    /** @return array<string, mixed> */
    private function dingdandaoStatus(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        DateTimeImmutable $now
    ): array {
        $table = 'dingdandao_operating_target_captures';
        if (!$this->tableHasColumns($table, [
            'tenant_id',
            'hotel_id',
            'business_date',
            'identity_status',
            'reconciliation_status',
            'capture_status',
            'quality_status',
            'readback_status',
            'captured_at',
        ])) {
            return $this->source(
                'dingdandao_pms',
                'unknown',
                '订单来了 PMS',
                '订单来了状态表未安装或字段不完整，当前不判定为就绪。',
                null,
                'collect_now'
            );
        }

        $lastSuccessAt = $this->lastVerifiedDingdandaoAt($tenantId, $hotelId);
        $row = Db::name($table)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->find();

        if (!is_array($row)) {
            return $this->source(
                'dingdandao_pms',
                $lastSuccessAt !== null ? 'stale' : 'unknown',
                '订单来了 PMS',
                $lastSuccessAt !== null
                    ? '当前业务日没有新快照；仅显示上次成功时间，不把历史数据当作就绪。'
                    : '当前业务日尚无可核验的订单来了快照。',
                $lastSuccessAt,
                'collect_now'
            );
        }

        $failureText = strtolower(implode('|', [
            (string)($row['capture_status'] ?? ''),
            (string)($row['quality_status'] ?? ''),
            (string)($row['quality_reason'] ?? ''),
            (string)($row['gap_codes_json'] ?? ''),
        ]));
        if ($this->containsLoginFailure($failureText)) {
            return $this->source(
                'dingdandao_pms',
                'login_required',
                '订单来了 PMS',
                '订单来了会话已失效或需要重新登录。',
                $lastSuccessAt,
                'request_login'
            );
        }
        if ((string)($row['identity_status'] ?? '') !== 'matched'
            || $this->containsBindingFailure($failureText)
        ) {
            return $this->source(
                'dingdandao_pms',
                'binding_missing',
                '订单来了 PMS',
                '订单来了页面门店与当前宿析酒店未完成一致性确认。',
                $lastSuccessAt,
                'check_binding'
            );
        }
        if ((string)($row['readback_status'] ?? '') !== 'readback_verified') {
            return $this->source(
                'dingdandao_pms',
                'readback_missing',
                '订单来了 PMS',
                '本轮快照未完成正式保存回读，不用于整点消息。',
                $lastSuccessAt,
                'collect_now'
            );
        }
        if ((string)($row['capture_status'] ?? '') !== 'verified'
            || (string)($row['quality_status'] ?? '') !== 'verified'
            || (string)($row['reconciliation_status'] ?? '') !== 'matched'
            || !$this->dingdandaoCoreMetricsPresent($row)
        ) {
            return $this->source(
                'dingdandao_pms',
                'partial',
                '订单来了 PMS',
                '本轮已有快照，但身份、对账或核心经营字段尚未全部验证。',
                $lastSuccessAt,
                'collect_now'
            );
        }

        $capturedAt = trim((string)($row['captured_at'] ?? ''));
        if (!$this->recentTimestamp($capturedAt, $now)) {
            return $this->source(
                'dingdandao_pms',
                'stale',
                '订单来了 PMS',
                '最新已验证快照超过45分钟，本轮不使用。',
                $capturedAt !== '' ? $capturedAt : $lastSuccessAt,
                'collect_now'
            );
        }

        return $this->source(
            'dingdandao_pms',
            'ready',
            '订单来了 PMS',
            '当前酒店、当天数据已在45分钟内完成保存回读。',
            $capturedAt,
            'collect_now'
        );
    }

    /** @return array<string, mixed> */
    private function otaStatus(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $businessDate,
        DateTimeImmutable $now
    ): array {
        $label = $platform === 'ctrip' ? '携程' : '美团';
        $profile = $this->emptyProfile();
        if (!$this->tableHasColumns('platform_data_sources', [
            'id',
            'tenant_id',
            'user_id',
            'system_hotel_id',
            'platform',
            'ingestion_method',
            'enabled',
        ])) {
            return $this->source(
                $platform,
                'unknown',
                $label,
                $label . '数据源状态表未安装或字段不完整。',
                null,
                'collect_now',
                $profile
            );
        }

        $scopedRows = Db::name('platform_data_sources')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        $source = null;
        foreach ($scopedRows as $candidate) {
            if (is_array($candidate)
                && (int)($candidate['enabled'] ?? 0) === 1
                && in_array(
                    strtolower(trim((string)($candidate['ingestion_method'] ?? ''))),
                    self::PROFILE_METHODS,
                    true
                )
            ) {
                $source = $candidate;
                break;
            }
        }

        $ownerUserId = is_array($source) ? (int)($source['user_id'] ?? 0) : 0;
        $profileState = $this->profileState(
            $tenantId,
            $hotelId,
            $ownerUserId,
            $platform,
            $now
        );
        $profile = $profileState['profile'];
        if (!is_array($source) || $ownerUserId <= 0) {
            return $this->source(
                $platform,
                'binding_missing',
                $label,
                $label . '云端 Profile 数据源未绑定到当前租户与酒店。',
                null,
                'check_binding',
                $profile
            );
        }

        $sourceId = (int)$source['id'];
        $taskSchemaAvailable = $this->tableHasColumns(
            'platform_data_sync_tasks',
            [
                'id',
                'tenant_id',
                'system_hotel_id',
                'data_source_id',
                'platform',
                'status',
                'finished_at',
            ]
        );
        $onlineSchemaAvailable = $this->tableHasColumns(
            'online_daily_data',
            [
                'tenant_id',
                'system_hotel_id',
                'data_source_id',
                'sync_task_id',
                'source',
                'platform',
                'data_date',
                'validation_status',
                'readback_verified',
                'source_trace_id',
                'snapshot_time',
            ]
        );
        $lastSuccessAt = $onlineSchemaAvailable
            ? $this->lastVerifiedOnlineAt(
                $tenantId,
                $hotelId,
                $sourceId,
                $platform
            )
            : null;

        if (($profileState['schema_available'] ?? false) !== true) {
            return $this->source(
                $platform,
                'unknown',
                $label,
                '云端 Profile 状态表未安装或字段不完整，不据此判定登录有效。',
                $lastSuccessAt,
                'request_login',
                $profile
            );
        }
        if (($profileState['found'] ?? false) !== true
            || ($profileState['ready'] ?? false) !== true
        ) {
            return $this->source(
                $platform,
                'login_required',
                $label,
                ($profileState['found'] ?? false) === true
                    ? $label . '云端会话已失效或尚未进入可采集状态。'
                    : $label . '尚未建立当前酒店的云端登录授权。',
                $lastSuccessAt,
                'request_login',
                $profile
            );
        }

        if (!$taskSchemaAvailable || !$onlineSchemaAvailable) {
            return $this->source(
                $platform,
                'unknown',
                $label,
                $label . '采集任务或正式回读表未安装，当前不判定为就绪。',
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }

        $tasks = Db::name('platform_data_sync_tasks')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('platform', $platform)
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        if ($tasks === []) {
            return $this->source(
                $platform,
                $lastSuccessAt !== null ? 'stale' : 'unknown',
                $label,
                $lastSuccessAt !== null
                    ? $label . '当前业务日尚无新任务；历史回读不作为本轮就绪依据。'
                    : $label . '尚无当前酒店的云端采集任务。',
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }

        $latestTask = is_array($tasks[0]) ? $tasks[0] : [];
        $currentEvidence = null;
        $currentReadbackMissing = false;
        foreach ($tasks as $task) {
            if (!is_array($task)
                || !in_array(
                    strtolower(trim((string)($task['status'] ?? ''))),
                    self::SUCCESS_TASK_STATUSES,
                    true
                )
            ) {
                continue;
            }
            $rows = $this->taskRows(
                $tenantId,
                $hotelId,
                $sourceId,
                (int)$task['id'],
                $platform,
                $businessDate
            );
            if ($rows === []) {
                $currentReadbackMissing = true;
                continue;
            }
            $evidence = $this->rowEvidence($rows, $now);
            if (($evidence['verified'] ?? false) !== true) {
                $currentReadbackMissing = true;
                continue;
            }
            $currentEvidence = [
                'task' => $task,
                'captured_at' => (string)$evidence['captured_at'],
                'fresh' => (bool)$evidence['fresh']
                    && $this->recentTimestamp((string)($task['finished_at'] ?? ''), $now),
                'partial' => (bool)($evidence['partial'] ?? false),
            ];
            $lastSuccessAt = (string)$evidence['captured_at'];
            break;
        }

        $latestTaskText = strtolower(implode('|', [
            (string)($latestTask['status'] ?? ''),
            (string)($latestTask['message'] ?? ''),
            (string)($latestTask['stats_json'] ?? ''),
        ]));
        $latestTaskIsNewerFailure = is_array($currentEvidence)
            && (int)($latestTask['id'] ?? 0)
                > (int)($currentEvidence['task']['id'] ?? 0)
            && !in_array(
                strtolower(trim((string)($latestTask['status'] ?? ''))),
                self::SUCCESS_TASK_STATUSES,
                true
            );

        if ($this->containsLoginFailure($latestTaskText)) {
            return $this->source(
                $platform,
                'login_required',
                $label,
                $label . '最新采集任务报告登录或会话失效。',
                $lastSuccessAt,
                'request_login',
                $profile
            );
        }
        if ($this->containsBindingFailure($latestTaskText)) {
            return $this->source(
                $platform,
                'binding_missing',
                $label,
                $label . '最新任务报告平台门店与宿析酒店绑定不完整。',
                $lastSuccessAt,
                'check_binding',
                $profile
            );
        }
        if ($this->containsReadbackFailure($latestTaskText)) {
            return $this->source(
                $platform,
                'readback_missing',
                $label,
                $label . '最新任务未完成正式保存回读。',
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }
        if ($latestTaskIsNewerFailure) {
            return $this->source(
                $platform,
                'partial',
                $label,
                $label . '存在较新的失败采集；上次回读仅作为历史记录。',
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }
        if (is_array($currentEvidence)) {
            if (($currentEvidence['fresh'] ?? false) !== true) {
                return $this->source(
                    $platform,
                    'stale',
                    $label,
                    $label . '最新已保存回读快照超过45分钟，本轮不使用。',
                    $lastSuccessAt,
                    'collect_now',
                    $profile
                );
            }
            if (strtolower((string)($currentEvidence['task']['status'] ?? ''))
                === 'partial_success'
                || ($currentEvidence['partial'] ?? false) === true
            ) {
                return $this->source(
                    $platform,
                    'partial',
                    $label,
                    $label . '本轮任务已保存回读，但平台仅返回部分可验证字段。',
                    $lastSuccessAt,
                    'collect_now',
                    $profile
                );
            }
            $profileNote = ($profile['expiring_soon'] ?? false) === true
                ? '会话即将到期，但当前快照仍已验证。'
                : '当前酒店、当天数据已在45分钟内完成保存回读。';
            return $this->source(
                $platform,
                'ready',
                $label,
                $profileNote,
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }
        if ($currentReadbackMissing) {
            return $this->source(
                $platform,
                'readback_missing',
                $label,
                $label . '当天任务没有可追溯的正式保存回读行。',
                $lastSuccessAt,
                'collect_now',
                $profile
            );
        }

        return $this->source(
            $platform,
            $lastSuccessAt !== null ? 'stale' : 'partial',
            $label,
            $lastSuccessAt !== null
                ? $label . '当前业务日未取得新回读，历史数据不作为就绪依据。'
                : $label . '最新任务未形成可验证的当天回读。',
            $lastSuccessAt,
            'collect_now',
            $profile
        );
    }

    /** @return array<string, mixed> */
    private function profileState(
        int $tenantId,
        int $hotelId,
        int $ownerUserId,
        string $platform,
        DateTimeImmutable $now
    ): array {
        if (!$this->tableHasColumns('cloud_browser_profiles', [
            'tenant_id',
            'system_hotel_id',
            'owner_user_id',
            'platform',
            'authorization_status',
            'ready_at',
            'session_expires_at',
        ])) {
            return [
                'schema_available' => false,
                'found' => false,
                'ready' => false,
                'profile' => $this->emptyProfile(),
            ];
        }
        $row = Db::name('cloud_browser_profiles')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('owner_user_id', $ownerUserId)
            ->where('platform', $platform)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return [
                'schema_available' => true,
                'found' => false,
                'ready' => false,
                'profile' => $this->emptyProfile(),
            ];
        }

        $authorizationStatus = strtolower(trim((string)(
            $row['authorization_status'] ?? ''
        )));
        $readyAt = trim((string)($row['ready_at'] ?? ''));
        $readyEvidenceValid = false;
        if ($readyAt !== '') {
            try {
                $readyEvidenceAt = new DateTimeImmutable(
                    $readyAt,
                    new DateTimeZone(self::TIMEZONE)
                );
                $readyEvidenceValid = $readyEvidenceAt->getTimestamp()
                    <= $now->getTimestamp();
            } catch (\Throwable) {
                $readyEvidenceValid = false;
            }
        }
        $sessionExpiresAt = trim((string)($row['session_expires_at'] ?? ''));
        $secondsRemaining = null;
        if ($sessionExpiresAt !== '') {
            try {
                $expiresAt = new DateTimeImmutable(
                    $sessionExpiresAt,
                    new DateTimeZone(self::TIMEZONE)
                );
                $secondsRemaining = $expiresAt->getTimestamp() - $now->getTimestamp();
            } catch (\Throwable) {
                $secondsRemaining = -1;
            }
        }
        $hoursRemaining = $secondsRemaining === null
            ? null
            : max(0, (int)floor($secondsRemaining / 3600));
        $expired = $secondsRemaining !== null && $secondsRemaining <= 0;

        return [
            'schema_available' => true,
            'found' => true,
            'ready' => $authorizationStatus === self::READY_PROFILE_STATUS
                && $readyEvidenceValid
                && $secondsRemaining !== null
                && !$expired,
            'profile' => [
                'authorization_status' => $authorizationStatus !== ''
                    ? $authorizationStatus
                    : null,
                'session_expires_at' => $sessionExpiresAt !== ''
                    ? $sessionExpiresAt
                    : null,
                'hours_remaining' => $hoursRemaining,
                'expiring_soon' => $secondsRemaining !== null
                    && $secondsRemaining > 0
                    && $secondsRemaining <= self::PROFILE_EXPIRING_SOON_SECONDS,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function taskRows(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        int $taskId,
        string $platform,
        string $businessDate
    ): array {
        return array_values(array_filter(
            Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('sync_task_id', $taskId)
                ->where('source', $platform)
                ->where('platform', $platform)
                ->where('data_date', $businessDate)
                ->order('id', 'asc')
                ->select()
                ->toArray(),
            'is_array'
        ));
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function rowEvidence(array $rows, DateTimeImmutable $now): array
    {
        $latestAt = '';
        $fresh = true;
        $partial = false;
        foreach ($rows as $row) {
            $capturedAt = $this->storedRowCapturedAt($row);
            if (!in_array(
                $row['readback_verified'] ?? null,
                [1, '1', true, 'true'],
                true
            ) || trim((string)($row['source_trace_id'] ?? '')) === ''
                || $capturedAt === ''
            ) {
                return ['verified' => false, 'fresh' => false, 'captured_at' => ''];
            }
            $validationStatus = strtolower(trim((string)(
                $row['validation_status'] ?? ''
            )));
            if ($validationStatus === 'stale') {
                $fresh = false;
            } elseif (in_array(
                $validationStatus,
                ['partial', 'warning', 'abnormal', 'quarantined', 'unverified'],
                true
            )) {
                $partial = true;
            }
            if ($latestAt === '' || strcmp($capturedAt, $latestAt) > 0) {
                $latestAt = $capturedAt;
            }
            if (!$this->recentTimestamp($capturedAt, $now)) {
                $fresh = false;
            }
        }
        return [
            'verified' => $latestAt !== '',
            'fresh' => $fresh,
            'partial' => $partial,
            'captured_at' => $latestAt,
        ];
    }

    private function lastVerifiedDingdandaoAt(int $tenantId, int $hotelId): ?string
    {
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('identity_status', 'matched')
            ->where('reconciliation_status', 'matched')
            ->where('capture_status', 'verified')
            ->where('quality_status', 'verified')
            ->where('readback_status', 'readback_verified')
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->find();
        $value = is_array($row) ? trim((string)($row['captured_at'] ?? '')) : '';
        return $value !== '' ? $value : null;
    }

    private function lastVerifiedOnlineAt(
        int $tenantId,
        int $hotelId,
        int $sourceId,
        string $platform
    ): ?string {
        $rows = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('source', $platform)
            ->where('platform', $platform)
            ->where('readback_verified', 1)
            ->order('id', 'desc')
            ->limit(20)
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (!is_array($row)
                || trim((string)($row['source_trace_id'] ?? '')) === ''
            ) {
                continue;
            }
            $capturedAt = $this->storedRowCapturedAt($row);
            if ($capturedAt !== '') {
                return $capturedAt;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function storedRowCapturedAt(array $row): string
    {
        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        foreach ([
            $row['snapshot_time'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['snapshot_time'] ?? null,
        ] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $row */
    private function dingdandaoCoreMetricsPresent(array $row): bool
    {
        foreach ([
            'total_room_fee',
            'adr',
            'occupancy_rate_percent',
            'revpar',
            'sold_room_nights',
            'derived_sellable_room_nights',
        ] as $field) {
            if (!is_numeric($row[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function safeSource(callable $reader, string $key, string $label): array
    {
        try {
            $source = $reader();
            return is_array($source) ? $source : $this->source(
                $key,
                'unknown',
                $label,
                $label . '状态未取得，不判定为就绪。',
                null,
                'collect_now'
            );
        } catch (\Throwable) {
            return $this->source(
                $key,
                'unknown',
                $label,
                $label . '状态暂时无法读取，不影响通知配置页其他内容。',
                null,
                'collect_now'
            );
        }
    }

    /** @return array<string, mixed> */
    private function source(
        string $key,
        string $status,
        string $label,
        string $message,
        ?string $lastSuccessAt,
        string $actionKey,
        ?array $profile = null
    ): array {
        return [
            'source' => $key,
            'status' => $status,
            'label' => $label,
            'message' => $message,
            'last_success_at' => $lastSuccessAt,
            'action_key' => $actionKey,
            'profile' => $profile ?? $this->emptyProfile(),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyProfile(): array
    {
        return [
            'authorization_status' => null,
            'session_expires_at' => null,
            'hours_remaining' => null,
            'expiring_soon' => false,
        ];
    }

    /** @param list<string> $statuses */
    private function overallStatus(array $statuses): string
    {
        if ($statuses !== [] && count(array_filter(
            $statuses,
            static fn(string $status): bool => $status === 'ready'
        )) === count($statuses)) {
            return 'ready';
        }
        foreach ([
            'login_required',
            'binding_missing',
            'readback_missing',
            'stale',
            'partial',
            'unknown',
        ] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }
        return 'unknown';
    }

    private function recentTimestamp(string $value, DateTimeImmutable $now): bool
    {
        try {
            $at = new DateTimeImmutable($value, $now->getTimezone());
        } catch (\Throwable) {
            return false;
        }
        $age = $now->getTimestamp() - $at->getTimestamp();
        return $age >= 0
            && $age <= CloudThreeSourceHourlyPayloadService::MAX_SOURCE_AGE_SECONDS;
    }

    private function containsLoginFailure(string $text): bool
    {
        foreach ([
            'login_required',
            'login_expired',
            'session_expired',
            'not_logged_in',
            'unauthorized',
            'verification_required',
            'captcha',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function containsBindingFailure(string $text): bool
    {
        foreach ([
            'binding_missing',
            'hotel_mismatch',
            'identity_mismatch',
            'store_mismatch',
            'poi_mismatch',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function containsReadbackFailure(string $text): bool
    {
        foreach([
            'readback_missing',
            'readback_failed',
            'readback_not_verified',
            'save_failed',
            'persistence_failed',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $columns */
    private function tableHasColumns(string $table, array $columns): bool
    {
        if (preg_match('/^[a-z0-9_]+$/D', $table) !== 1) {
            return false;
        }
        try {
            $fields = Db::getTableInfo($table, 'fields');
            if (!is_array($fields) || $fields === []) {
                return false;
            }
            foreach ($columns as $column) {
                if (!in_array($column, $fields, true)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone(self::TIMEZONE)
        );
        return $parsed instanceof DateTimeImmutable
            && $parsed->format('Y-m-d') === $value;
    }
}
