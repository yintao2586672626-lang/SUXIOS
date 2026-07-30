<?php
declare(strict_types=1);

namespace app\service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

final class MeituanTemporalService
{
    private const TIMEZONE = 'Asia/Shanghai';

    private const TODAY_METRICS = [
        'lead_price',
        'sales_room_nights',
        'sales_amount',
        'sales_avg_price',
        'exposure_users',
        'detail_visitors',
        'paid_order_count',
        'browse_to_pay_rate',
    ];

    private const YESTERDAY_METRICS = [
        'total_exposure',
        'organic_exposure',
        'ad_exposure',
        'sales_room_nights',
        'sales_amount',
        'sales_avg_price',
    ];

    private PlatformDataSyncService $syncService;

    public function __construct(?PlatformDataSyncService $syncService = null)
    {
        $this->syncService = $syncService ?? new PlatformDataSyncService();
    }

    /** @return array<string, mixed> */
    public function summary($user, int $systemHotelId, string $asOfDate): array
    {
        $asOf = $this->date($asOfDate);
        $this->assertHotelPermission($user, $systemHotelId, 'can_view_online_data');
        $sourceState = $this->sourceState($user, $systemHotelId);
        $from = $asOf->sub(new DateInterval('P31D'))->format('Y-m-d');
        $to = $asOf->add(new DateInterval('P30D'))->format('Y-m-d');
        $rows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'meituan')
            ->whereBetween('data_date', [$from, $to])
            ->whereIn('data_type', ['business', 'traffic', 'traffic_analysis', 'traffic_forecast'])
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return $this->buildSummaryFromRows($rows, $systemHotelId, $asOf->format('Y-m-d'), null, $sourceState);
    }

    /** @return array<string, mixed> */
    public function refresh($user, int $systemHotelId, string $asOfDate): array
    {
        $asOf = $this->date($asOfDate);
        $this->assertHotelPermission($user, $systemHotelId, 'can_fetch_online_data');
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        if (!$this->sameLocalDate($asOf, $now)) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_refresh_date_not_current',
                'message' => 'Meituan realtime refresh only accepts the current local business date.',
                'system_hotel_id' => $systemHotelId,
                'as_of_date' => $asOf->format('Y-m-d'),
                'tasks' => [],
            ];
        }
        $selection = $this->selectSource($user, $systemHotelId);
        if (($selection['status'] ?? '') !== 'ready') {
            return [
                'status' => 'blocked',
                'reason_code' => (string)($selection['reason_code'] ?? 'meituan_source_missing'),
                'message' => (string)($selection['message'] ?? 'Meituan Profile data source is not ready.'),
                'system_hotel_id' => $systemHotelId,
                'as_of_date' => $asOf->format('Y-m-d'),
                'tasks' => [],
            ];
        }

        $source = $selection['source'];
        $sourceId = (int)($source['id'] ?? 0);
        $hasFutureToday = $this->hasCompleteVerifiedFutureSnapshotCapturedOn(
            $systemHotelId,
            $asOf->format('Y-m-d'),
            $asOf->format('Y-m-d')
        );
        $tasks = [];
        $todayScope = $hasFutureToday ? 'today' : 'today_future';
        $tasks[] = $this->runRefreshTask($user, $sourceId, 'today', [
            'data_date' => $asOf->format('Y-m-d'),
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => $now->format('Y-m-d H:i:s'),
            'capture_sections' => 'traffic',
            'capture_mode' => 'temporal_summary',
            'temporal_scope' => $todayScope,
            'interactive_browser' => false,
            'trigger_type' => 'manual',
        ]);

        $yesterday = $asOf->sub(new DateInterval('P1D'))->format('Y-m-d');
        if ((int)$now->format('H') < 9) {
            $tasks[] = $this->skippedTask('yesterday', 'before_platform_update_window');
        } elseif ($this->hasCompleteVerifiedYesterdaySnapshotCapturedOn(
            $systemHotelId,
            $yesterday,
            $asOf->format('Y-m-d')
        )) {
            $tasks[] = $this->skippedTask('yesterday', 'verified_snapshot_already_collected_today');
        } else {
            $tasks[] = $this->runRefreshTask($user, $sourceId, 'yesterday', [
                'data_date' => $yesterday,
                'data_period' => 'historical_daily',
                'capture_sections' => 'traffic',
                'capture_mode' => 'temporal_summary',
                'temporal_scope' => 'yesterday',
                'interactive_browser' => false,
                'trigger_type' => 'manual',
            ]);
        }

        $blockedTask = null;
        $partialTask = null;
        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'blocked') {
                $blockedTask = $task;
                break;
            }
            if (($task['status'] ?? '') === 'partial') {
                $partialTask ??= $task;
            }
        }
        return [
            'status' => $blockedTask ? 'blocked' : ($partialTask ? 'partial' : 'completed'),
            'reason_code' => $blockedTask['reason_code'] ?? $partialTask['reason_code'] ?? 'refresh_completed',
            'message' => $blockedTask['message'] ?? $partialTask['message'] ?? 'Meituan temporal refresh completed.',
            'system_hotel_id' => $systemHotelId,
            'as_of_date' => $asOf->format('Y-m-d'),
            'data_scope' => 'ota_channel',
            'tasks' => $tasks,
        ];
    }

    /**
     * Pure summary builder used by the API and focused tests.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $sourceState
     * @return array<string, mixed>
     */
    public function buildSummaryFromRows(
        array $rows,
        int $systemHotelId,
        string $asOfDate,
        ?DateTimeImmutable $now = null,
        array $sourceState = []
    ): array {
        $asOf = $this->date($asOfDate);
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
        $asOfText = $asOf->format('Y-m-d');
        $yesterdayText = $asOf->sub(new DateInterval('P1D'))->format('Y-m-d');
        $futureEnd = $asOf->add(new DateInterval('P29D'))->format('Y-m-d');

        $rows = array_values(array_filter($rows, static function ($row) use ($systemHotelId): bool {
            if (!is_array($row)) {
                return false;
            }
            return (int)($row['system_hotel_id'] ?? 0) === $systemHotelId
                && strtolower(trim((string)($row['source'] ?? $row['platform'] ?? ''))) === 'meituan';
        }));
        $todayRows = array_values(array_filter($rows, static fn(array $row): bool =>
            (string)($row['data_date'] ?? '') === $asOfText
            && in_array(strtolower((string)($row['data_type'] ?? '')), ['business', 'traffic', 'traffic_analysis'], true)
            && self::isOwnHotelRow($row)
        ));
        $yesterdayRows = array_values(array_filter($rows, static fn(array $row): bool =>
            (string)($row['data_date'] ?? '') === $yesterdayText
            && in_array(strtolower((string)($row['data_type'] ?? '')), ['business', 'traffic', 'traffic_analysis'], true)
            && self::isOwnHotelRow($row)
        ));
        $futureRows = array_values(array_filter($rows, static fn(array $row): bool =>
            strtolower((string)($row['data_type'] ?? '')) === 'traffic_forecast'
            && (string)($row['data_date'] ?? '') >= $asOfText
            && (string)($row['data_date'] ?? '') <= $futureEnd
        ));

        $todaySnapshots = $this->buildSnapshotSeries($todayRows, self::TODAY_METRICS, 'today');
        $todayCurrent = $todaySnapshots[0] ?? $this->emptySnapshot(self::TODAY_METRICS, $asOfText);
        $todayReference = $this->latestReadyReference(array_slice($todaySnapshots, 1));

        $yesterdaySnapshots = $this->buildSnapshotSeries($yesterdayRows, self::YESTERDAY_METRICS, 'yesterday');
        $yesterdayCurrent = $yesterdaySnapshots[0] ?? $this->emptySnapshot(self::YESTERDAY_METRICS, $yesterdayText);
        $yesterdayReference = $this->latestReadyReference(array_slice($yesterdaySnapshots, 1));

        $future = $this->buildFutureSection($futureRows, $asOfText, $futureEnd);
        $sourceBlocked = ($sourceState['status'] ?? '') === 'blocked';
        if ($sourceBlocked) {
            $blockedReason = (string)($sourceState['reason_code'] ?? 'meituan_source_blocked');
            $todayCurrent['status'] = 'blocked';
            $todayCurrent['reason_code'] = $blockedReason;
            $yesterdayCurrent['status'] = 'blocked';
            $yesterdayCurrent['reason_code'] = $blockedReason;
            $future['status'] = 'blocked';
            $future['reason_code'] = $blockedReason;
        } elseif ($asOfText === $now->format('Y-m-d') && (int)$now->format('H') < 9) {
            $yesterdayCurrent['status'] = 'pending_source_update';
            $yesterdayCurrent['reason_code'] = 'before_platform_update_window';
            if (($future['status'] ?? '') === 'missing') {
                $future['status'] = 'pending_source_update';
                $future['reason_code'] = 'before_future_platform_update_window';
            }
        }

        return [
            'system_hotel_id' => $systemHotelId,
            'platform' => 'meituan',
            'data_scope' => 'ota_channel',
            'as_of_date' => $asOfText,
            'generated_at' => $now->format('Y-m-d H:i:s'),
            'source_state' => $sourceState,
            'today' => [
                'target_date' => $asOfText,
                'captured_at' => $todayCurrent['captured_at'],
                'status' => $todayCurrent['status'],
                'reason_code' => $todayCurrent['reason_code'],
                'metrics' => $todayCurrent['metrics'],
                'snapshots' => array_slice($todaySnapshots, 0, 24),
                'latest_verified_reference' => $todayReference,
            ],
            'yesterday' => [
                'target_date' => $yesterdayText,
                'captured_at' => $yesterdayCurrent['captured_at'],
                'status' => $yesterdayCurrent['status'],
                'reason_code' => $yesterdayCurrent['reason_code'],
                'metrics' => $yesterdayCurrent['metrics'],
                'latest_verified_reference' => $yesterdayReference,
            ],
            'future' => $future,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceState($user, int $systemHotelId): array
    {
        $selection = $this->selectSource($user, $systemHotelId);
        if (($selection['status'] ?? '') !== 'ready') {
            return $selection;
        }
        $source = $selection['source'];
        if (($source['current_session_verified'] ?? false) !== true) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_profile_login_required',
                'message' => '请打开当前门店绑定的美团 Profile 完成一次登录验证，然后再更新本页数据。',
                'data_source_id' => (int)($source['id'] ?? 0),
                'current_session_verified' => false,
            ];
        }
        return [
            'status' => 'ready',
            'reason_code' => 'source_bound',
            'data_source_id' => (int)($source['id'] ?? 0),
            'current_session_verified' => (bool)($source['current_session_verified'] ?? false),
            'last_sync_status' => (string)($source['last_sync_status'] ?? ''),
            'last_error' => (string)($source['last_error'] ?? ''),
        ];
    }

    private function assertHotelPermission($user, int $systemHotelId, string $permission): void
    {
        if (!$user) {
            throw new RuntimeException('Unauthenticated.', 401);
        }
        if (!method_exists($user, 'hasHotelPermission')
            || !$user->hasHotelPermission($systemHotelId, $permission)) {
            throw new RuntimeException('Forbidden.', 403);
        }
    }

    /** @return array<string, mixed> */
    private function selectSource($user, int $systemHotelId): array
    {
        $sources = $this->syncService->listDataSources($user, [
            'platform' => 'meituan',
            'system_hotel_id' => $systemHotelId,
        ]);
        $sources = array_values(array_filter($sources, static fn(array $source): bool =>
            (int)($source['enabled'] ?? 0) === 1
            && in_array(strtolower((string)($source['ingestion_method'] ?? '')), ['browser_profile', 'profile_browser'], true)
            && strtolower((string)($source['data_type'] ?? '')) !== 'peer_rank'
        ));
        if ($sources === []) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_profile_source_missing',
                'message' => '请为当前门店绑定并启用一个美团 browser_profile 数据源。',
            ];
        }

        $bindings = [];
        foreach ($sources as $source) {
            $config = is_array($source['config'] ?? null) ? $source['config'] : [];
            $binding = trim((string)(
                $config['platform_hotel_id']
                ?? $config['store_id']
                ?? $config['poi_id']
                ?? $source['external_hotel_id']
                ?? ''
            ));
            if ($binding !== '') {
                $bindings[$binding] = true;
            }
        }
        if (count($bindings) > 1) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_profile_binding_conflict',
                'message' => '当前门店存在多个不同的美团门店绑定，请先保留唯一绑定。',
            ];
        }
        if ($bindings === []) {
            return [
                'status' => 'blocked',
                'reason_code' => 'meituan_platform_hotel_binding_missing',
                'message' => '当前美团 Profile 数据源缺少 store_id/poi_id 门店绑定。',
            ];
        }

        return [
            'status' => 'ready',
            'reason_code' => 'source_bound',
            'source' => self::preferredProfileSource($sources),
        ];
    }

    /**
     * Legacy Meituan setup can expose one owning Profile source plus generated
     * resource projections for the same store. Execute the currently verified
     * or reusable source instead of selecting a failed projection by id/type.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    private static function preferredProfileSource(array $sources): array
    {
        usort($sources, static function (array $left, array $right): int {
            $rank = static function (array $source): array {
                $config = is_array($source['config'] ?? null) ? $source['config'] : [];
                $projectionIds = array_values(array_filter(
                    (array)($config['source_projection_ids'] ?? []),
                    static fn(mixed $id): bool => (int)$id > 0
                ));
                $statusRank = match (strtolower(trim((string)($source['status'] ?? '')))) {
                    'success' => 0,
                    'ready' => 1,
                    'partial_success' => 2,
                    'failed' => 3,
                    'waiting_config' => 4,
                    default => 5,
                };

                return [
                    ($source['current_session_verified'] ?? false) === true ? 0 : 1,
                    ($source['profile_reusable'] ?? false) === true ? 0 : 1,
                    $projectionIds === [] ? 0 : 1,
                    $statusRank,
                    (int)($source['id'] ?? 0),
                ];
            };

            return $rank($left) <=> $rank($right);
        });

        return $sources[0];
    }

    /** @return array<string, mixed> */
    private function runRefreshTask($user, int $sourceId, string $segment, array $options): array
    {
        try {
            $result = $this->syncService->syncDataSource($user, $sourceId, $options);
            $outcome = $this->refreshTaskOutcome($result);
            return [
                'segment' => $segment,
                'status' => $outcome['status'],
                'reason_code' => $outcome['reason_code'],
                'message' => (string)($result['message'] ?? ''),
                'data_source_id' => $sourceId,
                'sync_task_id' => (int)($result['id'] ?? $result['task_id'] ?? 0),
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'readback_verified' => (bool)($result['readback_verified'] ?? false),
            ];
        } catch (\Throwable $e) {
            return [
                'segment' => $segment,
                'status' => 'blocked',
                'reason_code' => $this->exceptionReason($e),
                'message' => $e->getMessage(),
                'data_source_id' => $sourceId,
                'sync_task_id' => 0,
                'saved_count' => 0,
                'readback_verified' => false,
            ];
        }
    }

    /** @return array{status:string,reason_code:string} */
    private function refreshTaskOutcome(array $result): array
    {
        $status = strtolower(trim((string)($result['status'] ?? 'failed')));
        $taskId = (int)($result['id'] ?? $result['task_id'] ?? 0);
        $savedCount = max(0, (int)($result['saved_count'] ?? 0));
        $readbackVerified = ($result['readback_verified'] ?? false) === true;
        $claimAllowed =
            ($result['collection_result']['claim']['allowed'] ?? false) === true;

        if (in_array($status, ['success', 'completed'], true)
            && $taskId > 0
            && $savedCount > 0
            && $readbackVerified
            && $claimAllowed) {
            return ['status' => 'completed', 'reason_code' => 'capture_saved_and_read_back'];
        }
        if (in_array($status, ['success', 'completed'], true)
            && $taskId > 0
            && $savedCount === 0
            && $readbackVerified
            && strtolower(trim((string)($result['message'] ?? ''))) === 'platform_returned_authoritative_empty') {
            return ['status' => 'partial', 'reason_code' => 'meituan_authoritative_empty_no_snapshot'];
        }
        if (in_array($status, ['success', 'completed'], true)
            && $taskId > 0
            && $savedCount > 0
            && $readbackVerified
            && !$claimAllowed
        ) {
            return ['status' => 'blocked', 'reason_code' => 'meituan_collection_claim_blocked'];
        }
        if (in_array($status, ['success', 'completed'], true)) {
            return ['status' => 'blocked', 'reason_code' => 'meituan_capture_readback_missing'];
        }
        if ($status === 'partial_success'
            && ($taskId <= 0 || ($savedCount > 0 && !$readbackVerified))) {
            return ['status' => 'blocked', 'reason_code' => 'meituan_capture_readback_missing'];
        }
        return [
            'status' => $status === 'partial_success' ? 'partial' : 'blocked',
            'reason_code' => $this->refreshReason($result),
        ];
    }

    /** @return array<string, mixed> */
    private function skippedTask(string $segment, string $reason): array
    {
        return [
            'segment' => $segment,
            'status' => 'skipped',
            'reason_code' => $reason,
            'saved_count' => 0,
            'readback_verified' => false,
        ];
    }

    private function hasCompleteVerifiedYesterdaySnapshotCapturedOn(
        int $systemHotelId,
        string $dataDate,
        string $capturedDate
    ): bool {
        $rows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'meituan')
            ->whereIn('data_type', ['business', 'traffic_analysis'])
            ->where('readback_verified', 1)
            ->where('data_date', $dataDate)
            ->order('id', 'desc')
            ->limit(200)
            ->select()
            ->toArray();

        return $this->hasCompleteVerifiedYesterdaySnapshotRows($rows, $dataDate, $capturedDate);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function hasCompleteVerifiedYesterdaySnapshotRows(
        array $rows,
        string $dataDate,
        string $capturedDate
    ): bool {
        $rows = array_values(array_filter($rows, function (array $row) use ($dataDate, $capturedDate): bool {
            return (string)($row['data_date'] ?? '') === $dataDate
                && in_array(strtolower((string)($row['data_type'] ?? '')), ['business', 'traffic_analysis'], true)
                && self::isOwnHotelRow($row)
                && str_starts_with($this->capturedAt($row), $capturedDate);
        }));
        $snapshots = $this->buildSnapshotSeries($rows, self::YESTERDAY_METRICS, 'yesterday');
        foreach ($snapshots as $snapshot) {
            if (($snapshot['status'] ?? '') === 'ready') {
                return true;
            }
        }
        return false;
    }

    private function hasCompleteVerifiedFutureSnapshotCapturedOn(
        int $systemHotelId,
        string $asOfDate,
        string $capturedDate
    ): bool {
        $start = $this->date($asOfDate)->format('Y-m-d');
        $end = $this->date($asOfDate)->add(new DateInterval('P29D'))->format('Y-m-d');
        $rows = Db::name('online_daily_data')
            ->where('system_hotel_id', $systemHotelId)
            ->where('source', 'meituan')
            ->where('data_type', 'traffic_forecast')
            ->where('readback_verified', 1)
            ->whereBetween('data_date', [$start, $end])
            ->order('id', 'desc')
            ->limit(500)
            ->select()
            ->toArray();

        return $this->hasCompleteVerifiedFutureSnapshotRows(
            $rows,
            $asOfDate,
            $capturedDate
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function hasCompleteVerifiedFutureSnapshotRows(
        array $rows,
        string $asOfDate,
        string $capturedDate
    ): bool {
        $futureEnd = $this->date($asOfDate)->add(new DateInterval('P29D'))->format('Y-m-d');
        $groups = [];
        foreach ($rows as $row) {
            if (!str_starts_with($this->capturedAt($row), $capturedDate)) {
                continue;
            }
            $type = $this->forecastType($row);
            if (!in_array($type, ['pv', 'uv', 'advance_orders'], true)) {
                continue;
            }
            $groups[$this->snapshotKey($row)][] = $row;
        }
        foreach ($groups as $snapshotKey => $snapshotRows) {
            $snapshot = $this->buildFutureSnapshot(
                $snapshotRows,
                $snapshotKey,
                $asOfDate,
                $futureEnd
            );
            if (($snapshot['status'] ?? '') === 'ready') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $metricKeys
     * @return array<int, array<string, mixed>>
     */
    private function buildSnapshotSeries(array $rows, array $metricKeys, string $segment): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $this->snapshotKey($row);
            $groups[$key][] = $row;
        }
        $snapshots = [];
        foreach ($groups as $groupRows) {
            $metrics = $segment === 'yesterday'
                ? $this->yesterdayMetrics($groupRows)
                : $this->todayMetrics($groupRows);
            $status = $this->sectionStatus($metrics, $metricKeys);
            $snapshots[] = [
                'snapshot_key' => $this->snapshotKey($groupRows[0]),
                'sync_task_id' => max(array_map(static fn(array $row): int => (int)($row['sync_task_id'] ?? 0), $groupRows)),
                'captured_at' => $this->latestCapturedAt($groupRows),
                'target_date' => (string)($groupRows[0]['data_date'] ?? ''),
                'status' => $status,
                'reason_code' => $this->sectionReason($status),
                'metrics' => $metrics,
            ];
        }
        usort($snapshots, static fn(array $a, array $b): int =>
            strcmp((string)$b['captured_at'], (string)$a['captured_at'])
            ?: ((int)$b['sync_task_id'] <=> (int)$a['sync_task_id'])
        );
        return $snapshots;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function todayMetrics(array $rows): array
    {
        $metrics = [];
        foreach (self::TODAY_METRICS as $key) {
            $metrics[$key] = $this->metricFromRows($rows, $key);
        }
        $metrics['sales_avg_price'] = $this->deriveIfMissing(
            $metrics['sales_avg_price'],
            $metrics['sales_amount'],
            $metrics['sales_room_nights'],
            'sales_amount/sales_room_nights'
        );
        $metrics['browse_to_pay_rate'] = $this->deriveIfMissing(
            $metrics['browse_to_pay_rate'],
            $metrics['paid_order_count'],
            $metrics['detail_visitors'],
            'paid_order_count/detail_visitors',
            100.0
        );
        return $metrics;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    private function yesterdayMetrics(array $rows): array
    {
        $metrics = [
            'total_exposure' => $this->namedExposureMetric($rows, 'total_exposure'),
            'organic_exposure' => $this->namedExposureMetric($rows, 'organic_exposure'),
            'ad_exposure' => $this->namedExposureMetric($rows, 'ad_exposure'),
            'sales_room_nights' => $this->metricFromRows($rows, 'sales_room_nights'),
            'sales_amount' => $this->metricFromRows($rows, 'sales_amount'),
            'sales_avg_price' => $this->metricFromRows($rows, 'sales_avg_price'),
        ];
        $metrics['sales_avg_price'] = $this->deriveIfMissing(
            $metrics['sales_avg_price'],
            $metrics['sales_amount'],
            $metrics['sales_room_nights'],
            'sales_amount/sales_room_nights'
        );
        return $metrics;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function buildFutureSection(array $rows, string $asOfDate, string $futureEnd): array
    {
        $snapshotGroups = [];
        foreach ($rows as $row) {
            $date = (string)($row['data_date'] ?? '');
            $type = $this->forecastType($row);
            if ($date < $asOfDate || $date > $futureEnd || !in_array($type, ['pv', 'uv', 'advance_orders'], true)) {
                continue;
            }
            $snapshotGroups[$this->snapshotKey($row)][] = $row;
        }

        $snapshots = [];
        foreach ($snapshotGroups as $snapshotKey => $snapshotRows) {
            $snapshots[] = $this->buildFutureSnapshot(
                $snapshotRows,
                $snapshotKey,
                $asOfDate,
                $futureEnd
            );
        }
        usort($snapshots, static fn(array $a, array $b): int =>
            strcmp((string)$b['captured_at'], (string)$a['captured_at'])
            ?: ((int)$b['sync_task_id'] <=> (int)$a['sync_task_id'])
        );

        $current = $snapshots[0] ?? [
            'target_date' => $asOfDate . '/' . $futureEnd,
            'captured_at' => null,
            'status' => 'missing',
            'reason_code' => 'current_snapshot_missing',
            'rows' => [],
            'sync_task_id' => 0,
        ];
        return [
            'target_date' => $current['target_date'],
            'captured_at' => $current['captured_at'],
            'status' => $current['status'],
            'reason_code' => $current['reason_code'],
            'rows' => $current['rows'],
            'latest_verified_reference' => $this->latestReadyReference(array_slice($snapshots, 1)),
            'snapshots' => array_slice($snapshots, 0, 3),
            'signal_scope' => 'future_ota_traffic_signal',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildFutureSnapshot(
        array $rows,
        string $snapshotKey,
        string $asOfDate,
        string $futureEnd
    ): array {
        $groups = [];
        foreach ($rows as $row) {
            $date = (string)($row['data_date'] ?? '');
            $type = $this->forecastType($row);
            if ($date < $asOfDate || $date > $futureEnd || !in_array($type, ['pv', 'uv', 'advance_orders'], true)) {
                continue;
            }
            $groups[$date][$type][] = $row;
        }
        ksort($groups);
        $items = [];
        foreach ($groups as $date => $types) {
            $metrics = [];
            foreach (['pv', 'uv', 'advance_orders'] as $type) {
                $typeRows = $types[$type] ?? [];
                $metrics[$type] = $this->forecastMetric($typeRows, false);
                $metrics[$type . '_peer_avg'] = $this->forecastMetric($typeRows, true);
            }
            $status = $this->sectionStatus($metrics, array_keys($metrics));
            $items[] = [
                'target_date' => $date,
                'captured_at' => $this->latestCapturedAt(array_merge(...array_values($types))),
                'status' => $status,
                'reason_code' => $this->sectionReason($status),
                'metrics' => $metrics,
            ];
        }
        $expectedDays = (int)$this->date($asOfDate)->diff($this->date($futureEnd))->format('%a') + 1;
        $readyDays = count(array_filter($items, static fn(array $row): bool => $row['status'] === 'ready'));
        $status = $items === []
            ? 'missing'
            : (($readyDays === count($items) && count($items) === $expectedDays) ? 'ready' : 'partial');
        return [
            'snapshot_key' => $snapshotKey,
            'sync_task_id' => max(array_map(static fn(array $row): int => (int)($row['sync_task_id'] ?? 0), $rows)),
            'target_date' => $asOfDate . '/' . $futureEnd,
            'captured_at' => $this->latestCapturedAt($rows),
            'status' => $status,
            'reason_code' => $this->sectionReason($status),
            'rows' => $items,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function metricFromRows(array $rows, string $metricKey): array
    {
        $spec = $this->metricSpec($metricKey);
        foreach ($spec['types'] as $type) {
            $candidates = [];
            foreach ($rows as $row) {
                if (strtolower((string)($row['data_type'] ?? '')) !== $type) {
                    continue;
                }
                $value = $this->rowMetricValue($row, $spec['field'], $spec['raw_keys']);
                if ($value === null) {
                    continue;
                }
                $fact = $this->fieldFact($row, $spec['fact_keys']);
                $verified = $this->rowVerified($row) && $this->factVerified($fact);
                $candidate = $this->metric(
                    $value,
                    $verified ? 'verified' : 'unverified',
                    $verified ? 'platform_direct' : 'field_or_readback_unverified',
                    $row,
                    $fact
                );
                $candidate['_route_priority'] = $this->metricRoutePriority($row, $metricKey);
                $candidates[] = $candidate;
            }
            if ($candidates !== []) {
                usort($candidates, static function (array $left, array $right): int {
                    $route = ((int)($right['_route_priority'] ?? 0))
                        <=> ((int)($left['_route_priority'] ?? 0));
                    if ($route !== 0) {
                        return $route;
                    }
                    return (($right['status'] ?? '') === 'verified' ? 1 : 0)
                        <=> (($left['status'] ?? '') === 'verified' ? 1 : 0);
                });
                unset($candidates[0]['_route_priority']);
                return $candidates[0];
            }
        }
        return $this->missingMetric();
    }

    private function metricRoutePriority(array $row, string $metricKey): int
    {
        if (!in_array($metricKey, [
            'exposure_users',
            'detail_visitors',
            'paid_order_count',
            'browse_to_pay_rate',
        ], true)) {
            return 0;
        }
        $raw = $this->rawRow($row);
        $dimension = strtolower(trim((string)($row['dimension'] ?? $raw['dimension'] ?? $raw['analysis_type'] ?? '')));
        $sourcePath = strtolower(trim((string)($raw['_source_path'] ?? $raw['source_path'] ?? '')));
        if ($dimension === 'flow_conversion' || str_starts_with($sourcePath, 'data.myhotel')) {
            return 100;
        }
        if (str_starts_with($sourcePath, 'dom.traffic.home_summary')) {
            return 10;
        }
        return 50;
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function namedExposureMetric(array $rows, string $metricKey): array
    {
        $patterns = match ($metricKey) {
            'organic_exposure' => ['/非广告曝光/u', '/organic.*exposure/i'],
            'ad_exposure' => ['/广告曝光/u', '/paid.*exposure/i', '/ad.*exposure/i'],
            default => ['/整体曝光/u', '/总曝光/u', '/overall.*exposure/i', '/total.*exposure/i'],
        };
        $unverifiedCandidate = null;
        foreach ($rows as $row) {
            if (strtolower((string)($row['data_type'] ?? '')) !== 'traffic_analysis') {
                continue;
            }
            $raw = $this->rawRow($row);
            $label = trim((string)($row['dimension'] ?? $raw['name'] ?? $raw['dimension'] ?? ''));
            if ($metricKey === 'ad_exposure' && preg_match('/非广告曝光/u', $label) === 1) {
                continue;
            }
            $matches = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $label) === 1) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $value = $this->rowMetricValue($row, 'data_value', ['value', 'dataValue', 'data_value']);
            if ($value === null) {
                continue;
            }
            $fact = $this->fieldFact($row, ['analysis_value']);
            $verified = $this->rowVerified($row) && $this->factVerified($fact);
            $candidate = $this->metric($value, $verified ? 'verified' : 'unverified', $verified ? 'platform_direct' : 'field_or_readback_unverified', $row, $fact);
            if ($verified) {
                return $candidate;
            }
            $unverifiedCandidate ??= $candidate;
        }
        return $unverifiedCandidate ?? $this->missingMetric();
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function forecastMetric(array $rows, bool $peer): array
    {
        $unverifiedCandidate = null;
        foreach ($rows as $row) {
            $value = $peer
                ? $this->rowMetricValue($row, '', ['peer_avg', 'peerAvg', 'peer_average'])
                : $this->rowMetricValue($row, 'data_value', ['current', 'value', 'data_value']);
            if ($value === null) {
                continue;
            }
            $fact = $this->fieldFact($row, [$peer ? 'forecast_peer_average' : 'forecast_current']);
            $verified = $this->rowVerified($row) && $this->factVerified($fact);
            $candidate = $this->metric($value, $verified ? 'verified' : 'unverified', $verified ? 'platform_direct' : 'field_or_readback_unverified', $row, $fact);
            if ($verified) {
                return $candidate;
            }
            $unverifiedCandidate ??= $candidate;
        }
        return $unverifiedCandidate ?? $this->missingMetric();
    }

    /** @return array{types:array<int,string>,field:string,raw_keys:array<int,string>,fact_keys:array<int,string>} */
    private function metricSpec(string $key): array
    {
        return match ($key) {
            'lead_price' => ['types' => ['business'], 'field' => '', 'raw_keys' => ['lead_price', 'leadPrice', 'startingPrice'], 'fact_keys' => ['lead_price']],
            'sales_room_nights' => ['types' => ['business'], 'field' => 'quantity', 'raw_keys' => ['sales_room_nights', 'salesRoomNights', 'room_nights'], 'fact_keys' => ['sales_room_nights', 'room_nights']],
            'sales_amount' => ['types' => ['business'], 'field' => 'amount', 'raw_keys' => ['sales_amount', 'salesAmount', 'amount'], 'fact_keys' => ['sales_amount', 'order_amount']],
            'sales_avg_price' => ['types' => ['business'], 'field' => 'data_value', 'raw_keys' => ['sales_avg_price', 'salesAvgPrice', 'avgPrice'], 'fact_keys' => ['sales_avg_price', 'data_value']],
            'exposure_users' => ['types' => ['traffic'], 'field' => 'list_exposure', 'raw_keys' => ['exposure_users', 'listExposure', 'exposureUV'], 'fact_keys' => ['exposure_users', 'list_exposure', 'mt_exposure']],
            'detail_visitors' => ['types' => ['traffic'], 'field' => 'detail_exposure', 'raw_keys' => ['detail_visitors', 'detailExposure', 'intentionUV'], 'fact_keys' => ['detail_visitors', 'detail_exposure', 'mt_intention_uv']],
            'paid_order_count' => ['types' => ['traffic'], 'field' => 'book_order_num', 'raw_keys' => ['paid_order_count', 'payOrderCnt', 'orderSubmitNum'], 'fact_keys' => ['paid_order_count', 'order_count', 'order_submit_num', 'mt_pay_orders']],
            'browse_to_pay_rate' => ['types' => ['traffic'], 'field' => 'flow_rate', 'raw_keys' => ['browse_to_pay_rate', 'browsePayRate', 'payOrderPerIntention'], 'fact_keys' => ['browse_to_pay_rate', 'flow_rate']],
            default => ['types' => [], 'field' => '', 'raw_keys' => [], 'fact_keys' => []],
        };
    }

    /** @return array<string, mixed> */
    private function deriveIfMissing(array $current, array $numerator, array $denominator, string $formula, float $multiplier = 1.0): array
    {
        if (($current['status'] ?? '') !== 'missing') {
            return $current;
        }
        if (($numerator['status'] ?? '') !== 'verified'
            || ($denominator['status'] ?? '') !== 'verified'
            || !is_numeric($numerator['value'] ?? null)
            || !is_numeric($denominator['value'] ?? null)
            || (float)$denominator['value'] <= 0) {
            return $current;
        }
        return [
            'value' => round(((float)$numerator['value'] / (float)$denominator['value']) * $multiplier, 2),
            'status' => 'derived',
            'reason_code' => 'same_verified_snapshot_formula',
            'source_path' => '',
            'formula' => $formula,
        ];
    }

    /** @return array<string, mixed> */
    private function metric(float|int $value, string $status, string $reason, array $row, ?array $fact): array
    {
        return [
            'value' => $value,
            'status' => $status,
            'reason_code' => $reason,
            'source_path' => (string)($fact['source_path'] ?? ''),
            'sync_task_id' => (int)($row['sync_task_id'] ?? 0),
            'data_source_id' => (int)($row['data_source_id'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function missingMetric(): array
    {
        return ['value' => null, 'status' => 'missing', 'reason_code' => 'platform_field_not_returned', 'source_path' => ''];
    }

    private function sectionStatus(array $metrics, array $requiredKeys): string
    {
        $statuses = array_map(static fn(string $key): string => (string)($metrics[$key]['status'] ?? 'missing'), $requiredKeys);
        if ($statuses !== [] && count(array_filter($statuses, static fn(string $status): bool => in_array($status, ['verified', 'derived'], true))) === count($statuses)) {
            return 'ready';
        }
        if (array_filter($statuses, static fn(string $status): bool => in_array($status, ['verified', 'derived'], true))) {
            return 'partial';
        }
        if (array_filter($statuses, static fn(string $status): bool => $status === 'unverified')) {
            return 'unverified';
        }
        return 'missing';
    }

    private function sectionReason(string $status): string
    {
        return match ($status) {
            'ready' => 'all_required_fields_verified',
            'partial' => 'required_fields_partial',
            'unverified' => 'current_values_not_verified',
            'pending_source_update' => 'before_platform_update_window',
            'blocked' => 'source_blocked',
            default => 'current_snapshot_missing',
        };
    }

    /** @param array<int, array<string, mixed>> $snapshots */
    private function latestReadyReference(array $snapshots): ?array
    {
        foreach ($snapshots as $snapshot) {
            if (($snapshot['status'] ?? '') === 'ready') {
                return $snapshot;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function emptySnapshot(array $metricKeys, string $targetDate): array
    {
        return [
            'captured_at' => null,
            'target_date' => $targetDate,
            'status' => 'missing',
            'reason_code' => 'current_snapshot_missing',
            'metrics' => array_fill_keys($metricKeys, $this->missingMetric()),
        ];
    }

    private function rowVerified(array $row): bool
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1
            || (int)($row['data_source_id'] ?? 0) <= 0
            || (int)($row['sync_task_id'] ?? 0) <= 0
            || trim((string)($row['source_trace_id'] ?? '')) === ''
            || $this->capturedAt($row) === '') {
            return false;
        }
        if (((new OtaStructuredCaptureEvidenceService())
                ->classifyRow($row)['allowed'] ?? false) !== true
        ) {
            return false;
        }
        $raw = $this->raw($row);
        $dateSource = strtolower(trim((string)($raw['date_source'] ?? $this->rawRow($row)['date_source'] ?? '')));
        if ($dateSource === ''
            || (!str_starts_with($dateSource, 'row.')
                && !str_starts_with($dateSource, 'request.')
                && !str_starts_with($dateSource, 'response.')
                && !str_starts_with($dateSource, 'page.')
                && $dateSource !== 'row')) {
            return false;
        }
        $identifierReady = ($raw['platform_hotel_identifier_present'] ?? null) === true
            && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
            && !in_array(
                strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? ''))),
                ['', 'missing', 'unverified'],
                true
            );
        if (!$identifierReady) {
            return false;
        }
        $binding = strtolower(trim((string)($raw['platform_hotel_binding_status'] ?? '')));
        if ($binding === '') {
            return true;
        }
        return $binding === 'matched'
            && !in_array(
                strtolower(trim((string)($raw['platform_hotel_binding_proof'] ?? ''))),
                ['', 'missing', 'unverified'],
                true
            );
    }

    /** @return array<string, mixed>|null */
    private function fieldFact(array $row, array $keys): ?array
    {
        $facts = $this->raw($row)['field_facts'] ?? [];
        if (!is_array($facts)) {
            return null;
        }
        foreach ($facts as $fact) {
            if (is_array($fact) && in_array((string)($fact['metric_key'] ?? ''), $keys, true)) {
                return $fact;
            }
        }
        return null;
    }

    private function factVerified(?array $fact): bool
    {
        if (!is_array($fact)) {
            return false;
        }
        $sourcePath = trim((string)($fact['source_path'] ?? ''));
        return strtolower((string)($fact['status'] ?? '')) === 'captured'
            && ($fact['stored_value_present'] ?? false) === true
            && $sourcePath !== ''
            && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
    }

    private function sameLocalDate(DateTimeImmutable $left, DateTimeImmutable $right): bool
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        return $left->setTimezone($timezone)->format('Y-m-d')
            === $right->setTimezone($timezone)->format('Y-m-d');
    }

    private function rowMetricValue(array $row, string $field, array $rawKeys): float|int|null
    {
        if ($field !== '' && array_key_exists($field, $row) && is_numeric($row[$field])) {
            return $this->numeric($row[$field]);
        }
        $raw = $this->rawRow($row);
        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $raw) && is_numeric($raw[$key])) {
                return $this->numeric($raw[$key]);
            }
        }
        return null;
    }

    private function numeric(mixed $value): float|int
    {
        $number = (float)$value;
        return floor($number) === $number ? (int)$number : $number;
    }

    /** @return array<string, mixed> */
    private function raw(array $row): array
    {
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, mixed> */
    private function rawRow(array $row): array
    {
        $raw = $this->raw($row);
        return is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
    }

    private function forecastType(array $row): string
    {
        $raw = $this->rawRow($row);
        $type = strtolower(trim((string)($raw['forecast_type'] ?? $raw['forecastType'] ?? '')));
        if (in_array($type, ['pv', 'uv', 'advance_orders'], true)) {
            return $type;
        }
        $dimension = strtolower((string)($row['dimension'] ?? ''));
        foreach (['advance_orders', 'pv', 'uv'] as $candidate) {
            if (preg_match('/(?:^|[_:])' . preg_quote($candidate, '/') . '$/', $dimension) === 1) {
                return $candidate;
            }
        }
        return '';
    }

    private function snapshotKey(array $row): string
    {
        $taskId = (int)($row['sync_task_id'] ?? 0);
        if ($taskId > 0) {
            return 'task:' . $taskId;
        }
        $trace = trim((string)($row['source_trace_id'] ?? ''));
        if ($trace !== '') {
            return 'trace:' . $trace;
        }
        return 'row:' . (string)($row['id'] ?? spl_object_id((object)$row));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function latestCapturedAt(array $rows): ?string
    {
        $values = array_values(array_filter(array_map(fn(array $row): string => $this->capturedAt($row), $rows)));
        rsort($values);
        return $values[0] ?? null;
    }

    private function capturedAt(array $row): string
    {
        $raw = $this->raw($row);
        return trim((string)(
            $row['snapshot_time']
            ?? $raw['captured_at']
            ?? $raw['snapshot_time']
            ?? $row['create_time']
            ?? ''
        ));
    }

    private static function isOwnHotelRow(array $row): bool
    {
        $compare = strtolower(trim((string)($row['compare_type'] ?? '')));
        return !in_array($compare, ['competitor', 'competitor_avg', 'peer'], true);
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone(self::TIMEZONE));
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            throw new RuntimeException('Invalid as_of_date.', 422);
        }
        return $date;
    }

    private function refreshReason(array $result): string
    {
        $statusCode = strtolower(trim((string)($result['status_code'] ?? '')));
        $text = strtolower(trim((string)($result['message'] ?? '')));
        if ($statusCode === 'profile_reused_no_target_date_traffic_rows'
            || $text === 'profile_reused_no_target_date_traffic_rows') {
            return 'meituan_target_date_traffic_missing';
        }
        if (str_contains($text, 'login') || str_contains($text, 'session') || str_contains($text, 'profile')) {
            return 'meituan_profile_login_required';
        }
        return $statusCode !== '' ? $statusCode : 'meituan_capture_failed';
    }

    private function exceptionReason(\Throwable $e): string
    {
        $text = strtolower($e->getMessage());
        if (str_contains($text, 'login') || str_contains($text, 'session') || str_contains($text, 'profile')) {
            return 'meituan_profile_login_required';
        }
        if (str_contains($text, 'binding')) {
            return 'meituan_profile_binding_invalid';
        }
        if (str_contains($text, 'already running') || str_contains($text, 'resource_busy')) {
            return 'meituan_profile_capture_busy';
        }
        return 'meituan_capture_failed';
    }
}
