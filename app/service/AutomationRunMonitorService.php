<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Read-only hotel automation monitor.
 *
 * The monitor keeps OTA collection, the hotel's selected PMS source, schedule
 * evidence and delivery receipts as separate states. It never treats an
 * enabled plan or a successful timer process as a confirmed WeCom delivery.
 */
final class AutomationRunMonitorService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const REFRESH_INTERVAL_SECONDS = 60;

    /** @var callable|null */
    private $businessPreviewLoader;

    /** @var callable|null */
    private $pmsBindingLoader;

    /** @var callable|null */
    private $taskOverviewLoader;

    /** @var callable|null */
    private $wechatRobotHotelIdsLoader;

    /** @var callable|null */
    private $runtimeEvidenceLoader;

    public function __construct(
        ?callable $businessPreviewLoader = null,
        ?callable $pmsBindingLoader = null,
        ?callable $taskOverviewLoader = null,
        private readonly ?DateTimeImmutable $observedAt = null,
        ?callable $wechatRobotHotelIdsLoader = null,
        ?callable $runtimeEvidenceLoader = null
    ) {
        $this->businessPreviewLoader = $businessPreviewLoader;
        $this->pmsBindingLoader = $pmsBindingLoader;
        $this->taskOverviewLoader = $taskOverviewLoader;
        $this->wechatRobotHotelIdsLoader = $wechatRobotHotelIdsLoader;
        $this->runtimeEvidenceLoader = $runtimeEvidenceLoader;
    }

    /**
     * @param array<int, array<string, mixed>> $hotels
     * @return array<string, mixed>
     */
    public function overview(array $hotels, string $businessDate, int $userId): array
    {
        $businessDate = $this->date($businessDate);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('automation_monitor_user_invalid');
        }

        $wechatRobotHotelIds = array_fill_keys(
            $this->wechatRobotHotelIds($hotels),
            true
        );
        $runtimeEvidence = $this->runtimeEvidence($hotels, $businessDate);
        $rows = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = (int)($hotel['id'] ?? 0);
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            if ($hotelId <= 0 || $tenantId <= 0) {
                continue;
            }
            $rows[] = $this->hotelRow(
                $hotel,
                $businessDate,
                $userId,
                isset($wechatRobotHotelIds[$hotelId]),
                is_array($runtimeEvidence[$hotelId] ?? null)
                    ? $runtimeEvidence[$hotelId]
                    : []
            );
        }

        usort($rows, static fn(array $left, array $right): int => (
            strnatcasecmp((string)$left['hotel_name'], (string)$right['hotel_name'])
            ?: ((int)$left['hotel_id'] <=> (int)$right['hotel_id'])
        ));

        $summary = [
            'hotel_count' => count($rows),
            'data_ready_count' => $this->countRows($rows, 'data_status', 'ready'),
            'collecting_count' => $this->countRows($rows, 'data_status', 'collecting'),
            'blocked_count' => count(array_filter(
                $rows,
                static fn(array $row): bool => (array)($row['blockers'] ?? []) !== []
            )),
            'waiting_push_count' => count(array_filter(
                $rows,
                static fn(array $row): bool => (string)($row['push_status'] ?? '') === 'waiting'
            )),
            'push_succeeded_count' => $this->countRows($rows, 'push_status', 'sent'),
        ];

        return [
            'business_date' => $businessDate,
            'observed_at' => $this->now()->format('Y-m-d H:i:s'),
            'refresh_interval_seconds' => self::REFRESH_INTERVAL_SECONDS,
            'summary' => $summary,
            'rows' => $rows,
            'message' => $rows === []
                ? '当前账号没有可监控的营业门店。'
                : '展示当前账号有权限的营业门店；未绑定企业微信机器人或未启用计划会保留为明确阻断，携程、美团、主 PMS 与企业微信回执分开核验。',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $hotels
     * @return array<int, int>
     */
    private function wechatRobotHotelIds(array $hotels): array
    {
        $permittedHotelIds = array_values(array_unique(array_filter(
            array_map(
                static fn(mixed $hotel): int => is_array($hotel)
                    ? (int)($hotel['id'] ?? 0)
                    : 0,
                $hotels
            ),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        if ($permittedHotelIds === []) {
            return [];
        }

        try {
            $hotelIds = $this->wechatRobotHotelIdsLoader === null
                ? Db::name('competitor_wechat_robot')
                    ->whereIn('store_id', $permittedHotelIds)
                    ->where('status', 1)
                    ->group('store_id')
                    ->column('store_id')
                : call_user_func(
                    $this->wechatRobotHotelIdsLoader,
                    $permittedHotelIds
                );
        } catch (\Throwable $error) {
            throw new \RuntimeException(
                'automation_monitor_wechat_robot_scope_unavailable',
                0,
                $error
            );
        }

        if (!is_array($hotelIds)) {
            throw new \RuntimeException(
                'automation_monitor_wechat_robot_scope_invalid'
            );
        }

        $permittedHotelIdSet = array_fill_keys($permittedHotelIds, true);
        return array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $hotelId): bool => $hotelId > 0
                && isset($permittedHotelIdSet[$hotelId])
        )));
    }

    /**
     * @param array<string, mixed> $hotel
     * @return array<string, mixed>
     */
    private function hotelRow(
        array $hotel,
        string $businessDate,
        int $userId,
        bool $wechatRobotConfigured,
        array $runtimeEvidence
    ): array
    {
        $hotelId = (int)$hotel['id'];
        $tenantId = (int)$hotel['tenant_id'];
        $blockers = [];
        $otaBlockers = [];

        $preview = $this->loadBusinessPreview($hotelId, $businessDate);
        $collection = (array)($preview['sections']['today_revenue_management']['ota_collection'] ?? []);
        $sourceEvidence = is_array($runtimeEvidence['sources'] ?? null)
            ? $runtimeEvidence['sources']
            : [];
        $ctrip = $this->sourceWithRuntimeEvidence(
            $this->otaSourceState('ctrip', $collection),
            $sourceEvidence['ctrip'] ?? []
        );
        $meituan = $this->sourceWithRuntimeEvidence(
            $this->otaSourceState('meituan', $collection),
            $sourceEvidence['meituan'] ?? []
        );
        foreach ([$ctrip, $meituan] as $source) {
            if (($source['ready'] ?? false) !== true) {
                $otaBlockers[] = (string)$source['blocker'];
            }
        }

        $pmsBinding = $this->loadPmsBinding(
            $tenantId,
            $hotelId,
            $userId,
            $businessDate
        );
        $pms = $this->selectedPms($pmsBinding, $businessDate);
        if (($pms['ready'] ?? false) !== true) {
            $blockers[] = (string)$pms['blocker'];
        }

        $tasks = $this->loadTaskOverview($tenantId, $hotelId);
        $schedule = $this->scheduleState($tasks);
        $deliveryEvidence = is_array($runtimeEvidence['delivery'] ?? null)
            ? $runtimeEvidence['delivery']
            : [];
        $delivery = $this->deliveryState($tasks, $pms, $deliveryEvidence);
        if (!$wechatRobotConfigured) {
            $robotBlocker = '尚未为门店绑定并启用企业微信机器人。';
            $schedule = [
                'status' => 'missing',
                'next_push_at' => null,
                'label' => '企业微信机器人未绑定',
                'blocker' => $robotBlocker,
            ];
            if ($delivery['status'] === 'waiting') {
                $delivery = [
                    'status' => 'blocked',
                    'label' => '机器人未绑定',
                    'at' => null,
                    'blocker' => $robotBlocker,
                ];
            }
            $blockers[] = $robotBlocker;
        }
        if ($schedule['status'] !== 'scheduled') {
            $blockers[] = (string)$schedule['blocker'];
        }
        if ($delivery['status'] === 'failed') {
            $blockers[] = (string)$delivery['blocker'];
        }
        $blockers = array_merge($blockers, $otaBlockers);

        $readyCount = count(array_filter(
            [$ctrip, $meituan, $pms],
            static fn(array $source): bool => ($source['ready'] ?? false) === true
        ));
        $sourceStatuses = array_map(
            static fn(array $source): string => (string)($source['status'] ?? 'missing'),
            [$ctrip, $meituan, $pms]
        );
        $dataStatus = $this->dataStatus($readyCount, $sourceStatuses);

        return [
            'hotel_id' => $hotelId,
            'hotel_name' => $this->safeText((string)($hotel['name'] ?? ('酒店 ' . $hotelId)), 120),
            'business_date' => $businessDate,
            'ctrip' => $this->publicSource($ctrip),
            'meituan' => $this->publicSource($meituan),
            'pms' => $this->publicSource($pms),
            'wechat_robot_configured' => $wechatRobotConfigured,
            'data_ready_count' => $readyCount,
            'data_required_count' => 3,
            'data_status' => $dataStatus,
            'data_status_label' => $this->dataStatusLabel($dataStatus, $readyCount),
            'next_push_at' => $schedule['next_push_at'],
            'next_push_label' => $schedule['label'],
            'push_status' => $delivery['status'],
            'push_result' => $delivery['label'],
            'push_result_at' => $delivery['at'],
            'push_success_count' => isset($deliveryEvidence['success_count'])
                && is_numeric($deliveryEvidence['success_count'])
                    ? max(0, (int)$deliveryEvidence['success_count'])
                    : null,
            'push_success_count_status' => (string)($deliveryEvidence['status'] ?? 'unavailable'),
            'blockers' => $this->uniqueText($blockers),
            'blocker_reason' => $this->blockerSummary($blockers),
        ];
    }

    /** @return array<string, mixed> */
    private function loadBusinessPreview(int $hotelId, string $businessDate): array
    {
        try {
            $value = $this->businessPreviewLoader === null
                ? (new ManualNotificationBusinessPreviewService())->preview($hotelId, $businessDate)
                : call_user_func($this->businessPreviewLoader, $hotelId, $businessDate);
            return is_array($value) ? $value : [];
        } catch (\Throwable $error) {
            return [
                'monitor_error' => $this->safeText($error->getMessage(), 160),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function loadPmsBinding(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $businessDate
    ): array {
        try {
            $value = $this->pmsBindingLoader === null
                ? (new HotelPmsBindingService())->status(
                    $tenantId,
                    $hotelId,
                    $userId,
                    $businessDate
                )
                : call_user_func(
                    $this->pmsBindingLoader,
                    $tenantId,
                    $hotelId,
                    $userId,
                    $businessDate
                );
            return is_array($value) ? $value : [];
        } catch (\Throwable $error) {
            return [
                'binding_status' => 'read_failed',
                'monitor_error' => $this->safeText($error->getMessage(), 160),
                'selected_source' => null,
                'blockers' => [[
                    'code' => 'hotel_pms_binding_read_failed',
                    'message' => '门店主 PMS 绑定读取失败。',
                ]],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function loadTaskOverview(int $tenantId, int $hotelId): array
    {
        try {
            $value = $this->taskOverviewLoader === null
                ? (new CloudMessageTaskOverviewService())->overview($tenantId, $hotelId)
                : call_user_func($this->taskOverviewLoader, $tenantId, $hotelId);
            return is_array($value) ? $value : [];
        } catch (\Throwable $error) {
            return [
                'source_status' => 'read_failed',
                'tasks' => [],
                'monitor_error' => $this->safeText($error->getMessage(), 160),
            ];
        }
    }

    /**
     * Load read-only collection and delivery evidence once for all permitted
     * hotels, so the monitor does not add per-row ledger queries.
     *
     * @param array<int, array<string, mixed>> $hotels
     * @return array<int, array<string, mixed>>
     */
    private function runtimeEvidence(array $hotels, string $businessDate): array
    {
        try {
            $value = $this->runtimeEvidenceLoader === null
                ? $this->loadRuntimeEvidence($hotels, $businessDate)
                : call_user_func(
                    $this->runtimeEvidenceLoader,
                    $hotels,
                    $businessDate
                );
            return is_array($value) ? $value : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $hotels
     * @return array<int, array<string, mixed>>
     */
    private function loadRuntimeEvidence(array $hotels, string $businessDate): array
    {
        $tenantByHotel = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = (int)($hotel['id'] ?? 0);
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            if ($hotelId > 0 && $tenantId > 0) {
                $tenantByHotel[$hotelId] = $tenantId;
            }
        }
        if ($tenantByHotel === []) {
            return [];
        }

        $evidence = [];
        foreach ($tenantByHotel as $hotelId => $tenantId) {
            $evidence[$hotelId] = [
                'tenant_id' => $tenantId,
                'sources' => [
                    'ctrip' => ['last_success_at' => null],
                    'meituan' => ['last_success_at' => null],
                ],
                'delivery' => [
                    'success_count' => null,
                    'last_success_at' => null,
                    'status' => 'unavailable',
                ],
            ];
        }

        $this->attachCollectionEvidence(
            $evidence,
            $tenantByHotel,
            $businessDate
        );

        $availableLedgers = 0;
        foreach ([
            [
                'table' => 'manual_notification_schedule_dispatches',
                'status_field' => 'status',
                'success_statuses' => ['sent'],
                'time_fields' => ['dispatched_at', 'update_time', 'create_time'],
            ],
            [
                'table' => 'dingdandao_pms_push_dispatches',
                'status_field' => 'delivery_status',
                'success_statuses' => ['sent'],
                'time_fields' => ['delivered_at', 'update_time', 'create_time'],
            ],
        ] as $ledger) {
            $rows = $this->deliveryLedgerRows(
                (string)$ledger['table'],
                (string)$ledger['status_field'],
                (array)$ledger['success_statuses'],
                (array)$ledger['time_fields'],
                array_keys($tenantByHotel),
                $businessDate
            );
            if ($rows === null) {
                continue;
            }
            $availableLedgers++;
            foreach ($evidence as &$hotelEvidence) {
                $delivery = (array)($hotelEvidence['delivery'] ?? []);
                $delivery['success_count'] = max(0, (int)($delivery['success_count'] ?? 0));
                $hotelEvidence['delivery'] = $delivery;
            }
            unset($hotelEvidence);

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $hotelId = (int)($row['hotel_id'] ?? 0);
                $tenantId = (int)($row['tenant_id'] ?? 0);
                if (!isset($tenantByHotel[$hotelId])
                    || $tenantId !== $tenantByHotel[$hotelId]
                ) {
                    continue;
                }
                $delivery = (array)$evidence[$hotelId]['delivery'];
                $delivery['success_count'] = max(0, (int)($delivery['success_count'] ?? 0))
                    + max(0, (int)($row['success_count'] ?? 0));
                $delivery['last_success_at'] = $this->laterTimestamp(
                    $delivery['last_success_at'] ?? null,
                    $row['last_success_at'] ?? null
                );
                $evidence[$hotelId]['delivery'] = $delivery;
            }
        }

        foreach ($evidence as &$hotelEvidence) {
            $delivery = (array)($hotelEvidence['delivery'] ?? []);
            $delivery['status'] = match ($availableLedgers) {
                2 => 'verified',
                1 => 'partial',
                default => 'unavailable',
            };
            $hotelEvidence['delivery'] = $delivery;
        }
        unset($hotelEvidence);

        return $evidence;
    }

    /**
     * @param array<int, array<string, mixed>> $evidence
     * @param array<int, int> $tenantByHotel
     */
    private function attachCollectionEvidence(
        array &$evidence,
        array $tenantByHotel,
        string $businessDate
    ): void {
        $columns = $this->tableColumns('online_daily_data');
        if (!isset(
            $columns['system_hotel_id'],
            $columns['readback_verified']
        )) {
            return;
        }
        $platformField = isset($columns['source'])
            ? 'source'
            : (isset($columns['platform']) ? 'platform' : '');
        $timeFields = array_values(array_filter([
            'snapshot_time',
            'collected_at',
            'captured_at',
            'fetched_at',
            'readback_verified_at',
            'update_time',
            'updated_at',
            'create_time',
            'created_at',
        ], static fn(string $field): bool => isset($columns[$field])));
        if ($platformField === '' || $timeFields === []) {
            return;
        }
        $timeExpression = count($timeFields) === 1
            ? '`' . $timeFields[0] . '`'
            : 'COALESCE('
                . implode(',', array_map(
                    static fn(string $field): string => '`' . $field . '`',
                    $timeFields
                ))
                . ')';

        $fields = [
            'system_hotel_id',
            "`{$platformField}` AS platform_key",
            "MAX({$timeExpression}) AS last_success_at",
        ];
        if (isset($columns['data_date'])) {
            $fields[] = "MAX(CASE WHEN `data_date` = '{$businessDate}'"
                . " THEN {$timeExpression} ELSE NULL END)"
                . ' AS target_date_last_success_at';
        }
        $groups = ['system_hotel_id', $platformField];
        if (isset($columns['tenant_id'])) {
            $fields[] = 'tenant_id';
            $groups[] = 'tenant_id';
        }

        try {
            $query = Db::name('online_daily_data')
                ->whereIn('system_hotel_id', array_keys($tenantByHotel))
                ->where('readback_verified', 1);
            if (isset($columns['data_type'])) {
                $query->whereRaw(
                    "(`data_type` IS NULL OR `data_type` <> 'competitor')"
                );
            }
            $rows = $query
                ->field(implode(',', $fields))
                ->group(implode(',', $groups))
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hotelId = (int)($row['system_hotel_id'] ?? 0);
            if (!isset($tenantByHotel[$hotelId])) {
                continue;
            }
            if (isset($columns['tenant_id'])
                && (int)($row['tenant_id'] ?? 0) !== $tenantByHotel[$hotelId]
            ) {
                continue;
            }
            $platform = $this->monitorPlatform((string)($row['platform_key'] ?? ''));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            $source = (array)$evidence[$hotelId]['sources'][$platform];
            $lastSuccessAt = $this->firstTimestamp([
                $row['target_date_last_success_at'] ?? null,
                $row['last_success_at'] ?? null,
            ]);
            $source['last_success_at'] = $this->laterTimestamp(
                $source['last_success_at'] ?? null,
                $lastSuccessAt
            );
            $evidence[$hotelId]['sources'][$platform] = $source;
        }
    }

    /**
     * @param array<int, string> $successStatuses
     * @param array<int, string> $timeFields
     * @param array<int, int> $hotelIds
     * @return array<int, array<string, mixed>>|null
     */
    private function deliveryLedgerRows(
        string $table,
        string $statusField,
        array $successStatuses,
        array $timeFields,
        array $hotelIds,
        string $businessDate
    ): ?array {
        $columns = $this->tableColumns($table);
        if (!isset(
            $columns['tenant_id'],
            $columns['hotel_id'],
            $columns['business_date'],
            $columns[$statusField]
        )) {
            return null;
        }
        $timeField = $this->firstExistingColumn($columns, $timeFields);
        if ($timeField === '') {
            return null;
        }

        try {
            return Db::name($table)
                ->whereIn('hotel_id', $hotelIds)
                ->where('business_date', $businessDate)
                ->whereIn($statusField, $successStatuses)
                ->field(
                    "hotel_id,tenant_id,COUNT(*) AS success_count,"
                    . "MAX(`{$timeField}`) AS last_success_at"
                )
                ->group('hotel_id,tenant_id')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        try {
            $fields = Db::getTableInfo($table, 'fields');
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($fields)) {
            return [];
        }
        $columns = [];
        foreach ($fields as $key => $value) {
            $name = is_string($key) && !is_numeric($key)
                ? $key
                : (string)$value;
            $name = trim($name);
            if ($name !== '') {
                $columns[$name] = true;
            }
        }
        return $columns;
    }

    /**
     * @param array<string, bool> $columns
     * @param array<int, string> $candidates
     */
    private function firstExistingColumn(array $columns, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $source */
    private function sourceWithRuntimeEvidence(
        array $source,
        mixed $runtimeEvidence
    ): array {
        $runtimeEvidence = is_array($runtimeEvidence) ? $runtimeEvidence : [];
        $source['last_success_at'] = $this->laterTimestamp(
            $source['last_success_at'] ?? null,
            $runtimeEvidence['last_success_at'] ?? null
        );
        return $source;
    }

    private function monitorPlatform(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, 'ctrip') || str_contains($value, 'trip.com')) {
            return 'ctrip';
        }
        if (str_contains($value, 'meituan') || str_contains($value, 'dianping')) {
            return 'meituan';
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $collection
     * @return array<string, mixed>
     */
    private function otaSourceState(string $platform, array $collection): array
    {
        $row = is_array($collection['platforms'][$platform] ?? null)
            ? $collection['platforms'][$platform]
            : [];
        $status = (string)($row['status'] ?? 'pending_collection');
        $label = (string)($row['label'] ?? '');
        if ($label === '') {
            $label = match ($status) {
                'readback_verified' => '已保存并回读',
                'collecting' => '采集中',
                'pending_readback' => '等待保存回读',
                'collection_failed' => '采集失败',
                default => '等待采集',
            };
        }
        $platformLabel = $platform === 'ctrip' ? '携程' : '美团';

        return [
            'key' => $platform,
            'label' => $platformLabel,
            'status' => $status,
            'status_label' => $label,
            'ready' => $status === 'readback_verified',
            'last_success_at' => $status === 'readback_verified'
                ? $this->firstTimestamp([
                    $row['last_success_at'] ?? null,
                    $row['readback_verified_at'] ?? null,
                    $row['finished_at'] ?? null,
                    $row['collected_at'] ?? null,
                ])
                : null,
            'blocker' => $status === 'readback_verified'
                ? ''
                : $platformLabel . $label,
        ];
    }

    /**
     * @param array<string, mixed> $binding
     * @return array<string, mixed>
     */
    private function selectedPms(array $binding, string $businessDate): array
    {
        $bindingStatus = (string)($binding['binding_status'] ?? 'read_failed');
        $bindingBlocker = $this->firstGateBlocker((array)($binding['blockers'] ?? []));
        if ($bindingStatus === 'conflict') {
            return [
                'key' => 'conflict',
                'label' => 'PMS绑定冲突',
                'status' => 'binding_conflict',
                'status_label' => '两个 PMS 均已启用',
                'ready' => false,
                'blocker' => $bindingBlocker !== ''
                    ? $bindingBlocker
                    : '订单来了与美团云 PMS 同时启用，请在门店管理中保留一个主 PMS。',
            ];
        }
        if ($bindingStatus === 'unconfigured') {
            return [
                'key' => 'missing',
                'label' => '未绑定 PMS',
                'status' => 'binding_missing',
                'status_label' => '待绑定',
                'ready' => false,
                'blocker' => $bindingBlocker !== ''
                    ? $bindingBlocker
                    : '门店尚未在门店管理中选择订单来了或美团云 PMS。',
            ];
        }
        if ($bindingStatus !== 'configured') {
            return [
                'key' => 'read_failed',
                'label' => '主 PMS 未取得',
                'status' => 'blocked',
                'status_label' => '绑定读取失败',
                'ready' => false,
                'blocker' => $bindingBlocker !== ''
                    ? $bindingBlocker
                    : '门店主 PMS 绑定读取失败。',
            ];
        }

        $source = is_array($binding['selected_source'] ?? null)
            ? $binding['selected_source']
            : [];
        $provider = (string)($binding['selected_provider'] ?? $source['provider'] ?? '');
        $providerLabel = trim((string)(
            $binding['selected_provider_label']
            ?? $source['provider_label']
            ?? ''
        ));
        $capture = is_array($source['capture'] ?? null) ? $source['capture'] : [];
        $factGate = is_array($source['fact_gate'] ?? null) ? $source['fact_gate'] : [];
        $ready = ($factGate['allowed'] ?? false) === true
            && $this->captureReady($capture);
        $blocker = $this->firstGateBlocker((array)($factGate['blockers'] ?? []));

        return [
            'key' => $provider !== '' ? $provider : 'configured_unknown',
            'label' => $providerLabel !== '' ? $providerLabel : '已绑定 PMS',
            'status' => $ready ? 'readback_verified' : 'blocked',
            'status_label' => $ready ? '当天事实已回读' : '当天事实未就绪',
            'ready' => $ready,
            'business_date' => $capture['business_date'] ?? $businessDate,
            'last_success_at' => $ready
                ? $this->firstTimestamp([
                    $capture['readback_verified_at'] ?? null,
                    $capture['captured_at'] ?? null,
                    $capture['collected_at'] ?? null,
                    $capture['update_time'] ?? null,
                ])
                : null,
            'blocker' => $ready
                ? ''
                : ($blocker !== '' ? $blocker : '主 PMS 当天事实尚未通过验真与回读。'),
            'latest_dispatch' => is_array($source['latest_dispatch'] ?? null)
                ? $source['latest_dispatch']
                : null,
        ];
    }

    /** @param array<string, mixed> $capture */
    private function captureReady(array $capture): bool
    {
        return $capture !== []
            && (string)($capture['capture_status'] ?? '') === 'verified'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified';
    }

    /**
     * @param array<string, mixed> $overview
     * @return array{status:string,next_push_at:?string,label:string,blocker:string}
     */
    private function scheduleState(array $overview): array
    {
        $tasks = array_values(array_filter(
            (array)($overview['tasks'] ?? []),
            static fn(mixed $task): bool => is_array($task)
                && (string)($task['status'] ?? '') === 'active'
        ));
        if ($tasks === []) {
            return [
                'status' => 'missing',
                'next_push_at' => null,
                'label' => '未取得已启用计划',
                'blocker' => '未取得已启用的企业微信推送计划。',
            ];
        }

        $next = $this->earliestTaskTime($tasks, 'next_run_at');
        if ($next === null) {
            return [
                'status' => 'unverified',
                'next_push_at' => null,
                'label' => '预计时间未取得',
                'blocker' => '推送计划已启用，但云端尚未回读预计下次执行时间。',
            ];
        }
        return [
            'status' => 'scheduled',
            'next_push_at' => $next,
            'label' => $next,
            'blocker' => '',
        ];
    }

    /**
     * @param array<string, mixed> $overview
     * @param array<string, mixed> $pms
     * @param array<string, mixed> $deliveryEvidence
     * @return array{status:string,label:string,at:?string,blocker:string}
     */
    private function deliveryState(
        array $overview,
        array $pms,
        array $deliveryEvidence = []
    ): array
    {
        $successCount = isset($deliveryEvidence['success_count'])
            && is_numeric($deliveryEvidence['success_count'])
                ? max(0, (int)$deliveryEvidence['success_count'])
                : 0;
        if ($successCount > 0) {
            return [
                'status' => 'sent',
                'label' => '企业微信已送达',
                'at' => $this->firstTimestamp([
                    $deliveryEvidence['last_success_at'] ?? null,
                ]),
                'blocker' => '',
            ];
        }

        $dispatch = is_array($pms['latest_dispatch'] ?? null)
            ? $pms['latest_dispatch']
            : null;
        if ($dispatch !== null) {
            $status = (string)($dispatch['delivery_status'] ?? '');
            if (in_array($status, ['sent', 'already_sent'], true)) {
                return [
                    'status' => 'sent',
                    'label' => '企业微信已送达',
                    'at' => $dispatch['delivered_at'] ?? null,
                    'blocker' => '',
                ];
            }
            if (in_array($status, ['failed', 'partial', 'binding_missing'], true)) {
                $reason = trim((string)($dispatch['error_summary'] ?? ''));
                return [
                    'status' => 'failed',
                    'label' => '推送失败',
                    'at' => $dispatch['claimed_at'] ?? null,
                    'blocker' => $reason !== '' ? $reason : '最近一次企业微信推送明确失败。',
                ];
            }
        }

        $tasks = array_values(array_filter(
            (array)($overview['tasks'] ?? []),
            static fn(mixed $task): bool => is_array($task)
                && trim((string)($task['last_run_at'] ?? '')) !== ''
        ));
        $latest = $this->latestTask($tasks, 'last_run_at');
        if ($latest === null) {
            return [
                'status' => 'waiting',
                'label' => '尚无执行回执',
                'at' => null,
                'blocker' => '',
            ];
        }

        $result = trim((string)($latest['last_result'] ?? ''));
        $sent = str_contains($result, '已发送') || str_contains($result, '已送达');
        $failed = str_contains($result, '失败');
        return [
            'status' => $sent ? 'sent' : ($failed ? 'failed' : 'unverified'),
            'label' => $result !== '' ? $result : '执行结果待核验',
            'at' => (string)($latest['last_run_at'] ?? '') ?: null,
            'blocker' => $failed ? $result : '',
        ];
    }

    /** @param array<int, array<string, mixed>> $tasks */
    private function earliestTaskTime(array $tasks, string $field): ?string
    {
        $best = null;
        $bestTimestamp = null;
        foreach ($tasks as $task) {
            $value = trim((string)($task[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                $best ??= $value;
                continue;
            }
            if ($bestTimestamp === null || $timestamp < $bestTimestamp) {
                $best = $value;
                $bestTimestamp = $timestamp;
            }
        }
        return $best;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function latestTask(array $tasks, string $field): ?array
    {
        $latest = null;
        $latestTimestamp = null;
        foreach ($tasks as $task) {
            $value = trim((string)($task[$field] ?? ''));
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                continue;
            }
            if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
                $latest = $task;
                $latestTimestamp = $timestamp;
            }
        }
        return $latest;
    }

    /** @param array<int, mixed> $blockers */
    private function firstGateBlocker(array $blockers): string
    {
        foreach ($blockers as $blocker) {
            if (!is_array($blocker)) {
                continue;
            }
            $message = trim((string)($blocker['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function publicSource(array $source): array
    {
        return [
            'key' => (string)($source['key'] ?? ''),
            'label' => (string)($source['label'] ?? '未取得'),
            'status' => (string)($source['status'] ?? 'missing'),
            'status_label' => (string)($source['status_label'] ?? '未取得'),
            'ready' => ($source['ready'] ?? false) === true,
            'business_date' => $source['business_date'] ?? null,
            'last_success_at' => $this->firstTimestamp([
                $source['last_success_at'] ?? null,
            ]),
        ];
    }

    /** @param array<int, string> $statuses */
    private function dataStatus(int $readyCount, array $statuses): string
    {
        if ($readyCount === 3) {
            return 'ready';
        }
        if (in_array('binding_conflict', $statuses, true)
            || in_array('binding_missing', $statuses, true)
            || in_array('collection_failed', $statuses, true)
            || in_array('blocked', $statuses, true)
        ) {
            return 'blocked';
        }
        if ($readyCount === 0
            && count(array_filter(
                $statuses,
                static fn(string $status): bool => in_array(
                    $status,
                    ['collecting', 'pending_collection', 'pending_readback'],
                    true
                )
            )) > 0
        ) {
            return 'collecting';
        }
        return $readyCount > 0 ? 'partial' : 'missing';
    }

    private function dataStatusLabel(string $status, int $readyCount): string
    {
        return match ($status) {
            'ready' => '3/3 数据已就绪',
            'collecting' => '今日数据采集中',
            'blocked' => "已阻断（{$readyCount}/3 就绪）",
            'partial' => "部分就绪（{$readyCount}/3）",
            default => '今日数据未就绪',
        };
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function countRows(array $rows, string $field, string $expected): int
    {
        return count(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row[$field] ?? '') === $expected
        ));
    }

    /** @param array<int, string> $blockers @return array<int, string> */
    private function uniqueText(array $blockers): array
    {
        $result = [];
        foreach ($blockers as $blocker) {
            $blocker = trim($this->safeText((string)$blocker, 240));
            if ($blocker !== '') {
                $result[$blocker] = $blocker;
            }
        }
        return array_values($result);
    }

    /** @param array<int, string> $blockers */
    private function blockerSummary(array $blockers): string
    {
        $unique = $this->uniqueText($blockers);
        if ($unique === []) {
            return '无';
        }
        $visible = array_slice($unique, 0, 2);
        $summary = implode('；', $visible);
        if (count($unique) > count($visible)) {
            $summary .= '；另有 ' . (count($unique) - count($visible)) . ' 项';
        }
        return $summary;
    }

    /** @param array<int, mixed> $values */
    private function firstTimestamp(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if (preg_match(
                '/(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?)/',
                $value,
                $matches
            ) !== 1) {
                continue;
            }
            $timestamp = str_replace('T', ' ', (string)$matches[1]);
            return strlen($timestamp) === 16 ? $timestamp . ':00' : $timestamp;
        }
        return null;
    }

    private function laterTimestamp(mixed $left, mixed $right): ?string
    {
        $left = $this->firstTimestamp([$left]);
        $right = $this->firstTimestamp([$right]);
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }
        $leftTime = strtotime($left);
        $rightTime = strtotime($right);
        if ($leftTime === false || $rightTime === false) {
            return strcmp($right, $left) > 0 ? $right : $left;
        }
        return $rightTime > $leftTime ? $right : $left;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('automation_monitor_date_invalid');
        }
        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->observedAt ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        return mb_substr($value, 0, max(1, $limit), 'UTF-8');
    }
}
