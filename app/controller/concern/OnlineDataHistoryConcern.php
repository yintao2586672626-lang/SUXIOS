<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
use app\service\OnlineDataTrustStatusService;
use think\Response;
use think\facade\Db;

trait OnlineDataHistoryConcern
{
    /**
     * OTA历史快照查询中心
     */
    public function history(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $page = max(1, intval($this->request->get('page', 1)));
            $pageSizeInput = $this->request->get('page_size', null);
            if ($pageSizeInput === null || $pageSizeInput === '') {
                $pageSizeInput = $this->request->get('limit', 20);
            }
            $pageSize = min(100, max(1, intval($pageSizeInput)));
            $keyword = trim((string)$this->request->get('keyword', $this->request->get('search', '')));

            $query = Db::name('online_daily_data');
            $this->applyOnlineHistoryFilters($query, $currentUser);
            $this->applyOnlineHistoryKeywordFilter($query, $keyword);

            $columns = $this->getOnlineDailyDataColumns();
            $paginationPlan = $this->buildOnlineHistoryDatabasePagination(
                clone $query,
                $columns,
                $page,
                $pageSize
            );

            $historyList = [];
            if ($paginationPlan['group_keys'] !== []) {
                $pageQuery = $this->applyOnlineHistoryGroupKeyScope(
                    clone $query,
                    $paginationPlan['group_key_expression'],
                    $paginationPlan['group_keys']
                );
                $rows = $this->orderOnlineDataByFetchTime(
                    $pageQuery,
                    $columns
                )->select()->toArray();
                $historyList = $this->mergeOnlineHistoryRows($rows, $this->getConfiguredHotelNameMap());
                $historyList = $this->orderOnlineHistoryMergedGroups(
                    $historyList,
                    $paginationPlan['group_keys']
                );
            }

            return $this->success([
                'list' => $historyList,
                'total' => $paginationPlan['total'],
                'page' => $page,
                'page_size' => $pageSize,
                'summary' => $paginationPlan['summary'],
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取历史记录失败: ' . $e->getMessage());
        }
    }

    /**
     * OTA历史快照详情
     */
    public function historyDetail(int $id): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $row = Db::name('online_daily_data')->where('id', $id)->find();
            if (!$row) {
                return $this->error('历史记录不存在', 404);
            }

            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($row['system_hotel_id']) || !in_array((int)$row['system_hotel_id'], $permittedHotelIds, true)) {
                    return $this->error('无权查看该历史记录', 403);
                }
            }

            $item = $this->normalizeOnlineHistoryRow($row, $this->getConfiguredHotelNameMap());
            $rawData = $item['raw_data'] ?? '';
            $decoded = is_string($rawData) && $rawData !== '' ? json_decode($rawData, true) : null;
            $item['raw_data_json'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            return $this->success($item);
        } catch (\Throwable $e) {
            return $this->error('获取历史详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 携程目标日期数据；未指定范围时读取最近入库数据。
     */
    public function ctripLatest(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $hotelId = trim((string)$this->request->get('hotel_id', ''));
            $range = $this->normalizeCtripLatestRange((string)$this->request->get('range', ''));
            $sections = [
                'rank' => $this->buildCtripLatestSection('rank', $hotelId, $currentUser, $range),
                'traffic' => $this->buildCtripLatestSection('traffic', $hotelId, $currentUser, $range),
                'review' => $this->buildCtripLatestSection('review', $hotelId, $currentUser, $range),
            ];

            return $this->success([
                'metadata' => $this->buildCtripLatestMetadata($sections, $hotelId, $range),
                'rank' => $sections['rank'],
                'traffic' => $sections['traffic'],
                'review' => $sections['review'],
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取携程最近采集数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 携程采集历史
     */
    public function ctripHistory(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $page = max(1, intval($this->request->get('page', 1)));
            $pageSize = min(100, max(1, intval($this->request->get('page_size', $this->request->get('limit', 20)))));
            $hotelId = trim((string)$this->request->get('hotel_id', ''));
            $dataType = trim((string)$this->request->get('data_type', ''));
            $columns = $this->getOnlineDailyDataColumns();

            $query = Db::name('online_daily_data');
            $this->applyCtripStorageFilter($query, $columns);
            $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);
            if ($dataType !== '' && $dataType !== 'all') {
                $this->applyCtripSectionTypeFilter($query, $dataType, $columns);
            }

            $total = (int)(clone $query)->count();
            $summary = $this->buildOnlineHistorySummaryFromQuery(clone $query, $total);
            $rows = $this->orderOnlineDataByFetchTime(clone $query, $columns)
                ->limit(($page - 1) * $pageSize, $pageSize)
                ->select()
                ->toArray();

            $hotelMap = $this->getConfiguredHotelNameMap();
            $list = [];
            foreach ($rows as $row) {
                $list[] = $this->normalizeOnlineHistoryRow($row, $hotelMap);
            }

            return $this->success([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取携程采集历史失败: ' . $e->getMessage());
        }
    }

    private function buildCtripLatestSection(string $section, string $hotelId, $currentUser, string $range = ''): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $labelMap = [
            'rank' => '榜单数据',
            'traffic' => '流量数据',
            'review' => '点评数据',
        ];

        $query = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($query, $columns);
        $this->applyCtripSectionTypeFilter($query, $section, $columns);
        $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);
        $this->applyCtripLatestPeriodScope($query, $columns, $range);
        $targetDate = $this->resolveCtripLatestTargetDate($range);
        if ($targetDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', $targetDate);
        }

        $latest = $this->orderOnlineDataByFetchTime($query, $columns)->find();
        if (!$latest) {
            return $this->emptyCtripLatestSection($section, $labelMap[$section] ?? $section, $targetDate);
        }

        $rowsQuery = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($rowsQuery, $columns);
        $this->applyCtripSectionTypeFilter($rowsQuery, $section, $columns);
        $this->applyCtripHotelScope($rowsQuery, $hotelId, $currentUser, $columns);
        $this->applyCtripLatestPeriodScope($rowsQuery, $columns, $range);
        if (isset($columns['data_date']) && !empty($latest['data_date'])) {
            $rowsQuery->where('data_date', $latest['data_date']);
        }
        $this->applyCtripLatestBatchScope($rowsQuery, $latest, $hotelId, $columns);

        $rows = $this->orderOnlineDataByFetchTime($rowsQuery, $columns, 'asc')
            ->select()
            ->toArray();

        $fetchedAt = $this->maxOnlineRowsFetchedAt($rows, $columns);

        $decodedRows = $this->decodeOnlineRawRows($rows);
        $displayHotels = $section === 'rank' ? $this->buildCtripBusinessDisplayHotels($decodedRows) : [];
        $trafficFallback = null;
        if ($range === '' && $section === 'rank' && (empty($displayHotels) || !$this->ctripBusinessDisplayHotelsHaveTraffic($displayHotels))) {
            $fallback = $this->findLatestCtripRankRowsWithTraffic($latest, $hotelId, $currentUser, $columns);
            if ($fallback !== null) {
                $latest = $fallback['latest'];
                $rows = $fallback['rows'];
                $decodedRows = $fallback['decoded_rows'];
                $displayHotels = $fallback['display_hotels'];
                $fetchedAt = $fallback['fetched_at'];
                $trafficFallback = [
                    'reason' => 'latest_rank_without_traffic',
                    'source' => 'latest_rank_batch_with_traffic',
                    'replaced_data_date' => (string)($fallback['replaced_data_date'] ?? ''),
                    'replaced_fetched_at' => (string)($fallback['replaced_fetched_at'] ?? ''),
                    'fallback_data_date' => (string)($latest['data_date'] ?? ''),
                    'fallback_fetched_at' => $fetchedAt,
                ];
            }
        }
        $displayTrafficRows = $section === 'traffic' ? CtripTrafficDisplayService::buildCtripTrafficDisplayRows($decodedRows) : [];
        $displaySummary = $section === 'rank' ? $this->buildCtripBusinessDisplaySummary($displayHotels) : $this->emptyCtripBusinessDisplaySummary();
        if ($trafficFallback !== null) {
            $displaySummary['source_notice'] = '当前最新批次未返回流量字段，已展示最近一组有流量的携程竞争圈数据。';
        }

        $comparison = $section === 'rank'
            ? $this->buildCtripLatestRankComparison($latest, $hotelId, $currentUser, $columns, $range)
            : null;

        return [
            'data_type' => $section,
            'data_type_label' => $labelMap[$section] ?? $section,
            'data_source' => '携程 ebooking',
            'status' => empty($rows) ? 'empty' : 'success',
            'status_label' => empty($rows) ? '暂无入库记录' : '有入库记录',
            'verification_status' => empty($rows) ? 'not_available' : 'record_present_source_not_proven',
            'data_date' => (string)($latest['data_date'] ?? ''),
            'target_data_date' => $targetDate,
            'fetched_at' => $fetchedAt !== '' ? $fetchedAt : $this->onlineRowFetchedAt($latest, $columns),
            'total' => count($rows),
            'rows' => $decodedRows,
            'display_hotels' => $displayHotels,
            'display_summary' => $displaySummary,
            'display_traffic_rows' => $displayTrafficRows,
            'display_traffic_summary' => $section === 'traffic' ? CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($displayTrafficRows) : CtripTrafficDisplayService::emptyCtripTrafficDisplaySummary(),
            'traffic_fallback' => $trafficFallback,
            'early_morning_fallback' => null,
            'comparison' => $comparison,
        ];
    }

    private function normalizeCtripLatestRange(string $range): string
    {
        $range = strtolower(trim($range));
        return match ($range) {
            'yesterday', 'last_day', '1' => 'yesterday',
            'realtime', 'real_time', 'today_realtime', 'today', '0' => 'realtime',
            default => '',
        };
    }

    private function resolveCtripLatestTargetDate(string $range): string
    {
        return match ($range) {
            'yesterday' => date('Y-m-d', strtotime('-1 day')),
            'realtime' => date('Y-m-d'),
            default => '',
        };
    }

    private function applyCtripLatestPeriodScope($query, array $columns, string $range): void
    {
        if ($range === 'yesterday') {
            if (isset($columns['data_period'])) {
                $query->where('data_period', 'historical_daily');
            }
            if (isset($columns['is_final'])) {
                $query->where('is_final', 1);
            }
            return;
        }

        if ($range === 'realtime') {
            if (isset($columns['data_period'])) {
                $query->where('data_period', 'realtime_snapshot');
            }
            if (isset($columns['is_final'])) {
                $query->where('is_final', 0);
            }
        }
    }

    private function buildCtripLatestRankComparison(array $latest, string $hotelId, $currentUser, array $columns, string $range): ?array
    {
        if (!in_array($range, ['realtime', 'yesterday'], true) || empty($latest['data_date'])) {
            return null;
        }
        $previousDate = date('Y-m-d', strtotime((string)$latest['data_date'] . ' -1 day'));
        return $this->fetchCtripRankSnapshotForDate($previousDate, $hotelId, $currentUser, $columns, $range);
    }

    private function fetchCtripRankSnapshotForDate(string $dataDate, string $hotelId, $currentUser, array $columns, string $range = ''): ?array
    {
        if ($dataDate === '' || !isset($columns['data_date'])) {
            return null;
        }

        $query = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($query, $columns);
        $this->applyCtripSectionTypeFilter($query, 'rank', $columns);
        $this->applyCtripCompetitionCircleFilter($query, $columns);
        $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);
        $this->applyCtripLatestPeriodScope($query, $columns, $range);
        $query->where('data_date', $dataDate);

        $latest = $this->orderOnlineDataByFetchTime($query, $columns)->find();
        if (!$latest) {
            return null;
        }

        $rowsQuery = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($rowsQuery, $columns);
        $this->applyCtripSectionTypeFilter($rowsQuery, 'rank', $columns);
        $this->applyCtripCompetitionCircleFilter($rowsQuery, $columns);
        $this->applyCtripHotelScope($rowsQuery, $hotelId, $currentUser, $columns);
        $this->applyCtripLatestPeriodScope($rowsQuery, $columns, $range);
        $rowsQuery->where('data_date', $dataDate);
        $this->applyCtripLatestBatchScope($rowsQuery, $latest, $hotelId, $columns);

        $rows = $this->orderOnlineDataByFetchTime($rowsQuery, $columns, 'asc')->select()->toArray();
        if (empty($rows)) {
            return null;
        }

        $decodedRows = $this->decodeOnlineRawRows($rows);
        $displayHotels = $this->buildCtripBusinessDisplayHotels($decodedRows);
        return [
            'data_date' => $dataDate,
            'fetched_at' => $this->maxOnlineRowsFetchedAt($rows, $columns),
            'total' => count($rows),
            'display_hotels' => $displayHotels,
            'display_summary' => $this->buildCtripBusinessDisplaySummary($displayHotels),
        ];
    }

    private function ctripBusinessDisplayHotelsHaveTraffic(array $displayHotels): bool
    {
        foreach ($displayHotels as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['totalDetailNum', 'qunarDetailVisitors', 'convertionRate', 'qunarDetailCR'] as $field) {
                if ((float)($row[$field] ?? 0) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function findLatestCtripRankRowsWithTraffic(array $currentLatest, string $hotelId, $currentUser, array $columns, string $range = ''): ?array
    {
        $query = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($query, $columns);
        $this->applyCtripSectionTypeFilter($query, 'rank', $columns);
        $this->applyCtripCompetitionCircleFilter($query, $columns);
        $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);
        $this->applyCtripLatestPeriodScope($query, $columns, $range);

        $candidateRows = $this->orderOnlineDataByFetchTime($query, $columns)
            ->limit(1000)
            ->select()
            ->toArray();
        if (empty($candidateRows)) {
            return null;
        }

        $currentBatchKey = $this->ctripLatestBatchKey($currentLatest, $columns, $hotelId === '');
        $groups = [];
        foreach ($candidateRows as $row) {
            $key = $this->ctripLatestBatchKey($row, $columns, $hotelId === '');
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        foreach ($groups as $key => $rows) {
            if ($key === $currentBatchKey) {
                continue;
            }
            $decodedRows = $this->decodeOnlineRawRows($rows);
            $displayHotels = $this->buildCtripBusinessDisplayHotels($decodedRows);
            if (!$this->ctripBusinessDisplayHotelsHaveTraffic($displayHotels)) {
                continue;
            }

            $latest = $rows[0] ?? [];
            return [
                'latest' => is_array($latest) ? $latest : [],
                'rows' => $rows,
                'decoded_rows' => $decodedRows,
                'display_hotels' => $displayHotels,
                'fetched_at' => $this->maxOnlineRowsFetchedAt($rows, $columns),
                'replaced_data_date' => (string)($currentLatest['data_date'] ?? ''),
                'replaced_fetched_at' => $this->onlineRowFetchedAt($currentLatest, $columns),
            ];
        }

        return null;
    }

    private function ctripLatestBatchKey(array $row, array $columns, bool $includeSystemHotel): string
    {
        $isCompetitionCircle = strtolower(trim((string)($row['data_type'] ?? ''))) === 'competitor'
            && trim((string)($row['dimension'] ?? '')) === 'competition_circle_hotel';
        if (!$isCompetitionCircle && isset($columns['sync_task_id']) && (int)($row['sync_task_id'] ?? 0) > 0) {
            return 'task:' . (int)$row['sync_task_id'];
        }
        if (!$isCompetitionCircle && isset($columns['batch_no']) && trim((string)($row['batch_no'] ?? '')) !== '') {
            return 'batch:' . trim((string)$row['batch_no']);
        }

        $snapshotTime = $isCompetitionCircle && isset($columns['snapshot_time'])
            ? trim((string)($row['snapshot_time'] ?? ''))
            : '';
        $parts = [
            'date:' . (string)($row['data_date'] ?? ''),
            'time:' . ($snapshotTime !== '' ? $snapshotTime : $this->onlineRowFetchedAt($row, $columns)),
        ];
        if ($includeSystemHotel && isset($columns['system_hotel_id'])) {
            $parts[] = 'hotel:' . (string)($row['system_hotel_id'] ?? '');
        }
        return implode('|', $parts);
    }

    private function emptyCtripLatestSection(string $section, string $label, string $targetDate = ''): array
    {
        return [
            'data_type' => $section,
            'data_type_label' => $label,
            'data_source' => '携程 ebooking',
            'status' => 'empty',
            'status_label' => $targetDate !== '' ? '目标日期未采集' : '暂无数据',
            'data_date' => '',
            'target_data_date' => $targetDate,
            'fetched_at' => '',
            'total' => 0,
            'rows' => [],
            'display_hotels' => [],
            'display_summary' => $this->emptyCtripBusinessDisplaySummary(),
            'display_traffic_rows' => [],
            'display_traffic_summary' => CtripTrafficDisplayService::emptyCtripTrafficDisplaySummary(),
        ];
    }

    private function buildCtripLatestMetadata(array $sections, string $hotelId, string $range = ''): array
    {
        $fetchedAt = '';
        $dataDate = '';
        $targetDataDate = '';
        $total = 0;
        $earlyFallbacks = [];
        foreach ($sections as $section) {
            $total += (int)($section['total'] ?? 0);
            $sectionFetchedAt = (string)($section['fetched_at'] ?? '');
            if ($sectionFetchedAt !== '' && ($fetchedAt === '' || strcmp($sectionFetchedAt, $fetchedAt) > 0)) {
                $fetchedAt = $sectionFetchedAt;
            }
            $sectionDataDate = (string)($section['data_date'] ?? '');
            if ($sectionDataDate !== '' && ($dataDate === '' || strcmp($sectionDataDate, $dataDate) > 0)) {
                $dataDate = $sectionDataDate;
            }
            $sectionTargetDate = (string)($section['target_data_date'] ?? '');
            if ($sectionTargetDate !== '' && ($targetDataDate === '' || strcmp($sectionTargetDate, $targetDataDate) > 0)) {
                $targetDataDate = $sectionTargetDate;
            }
            if (is_array($section['early_morning_fallback'] ?? null)) {
                $earlyFallbacks[] = $section['early_morning_fallback'];
            }
        }

        if ($range === '') {
            $fetchStatus = $this->getCtripLatestFetchStatus($hotelId);
            if (!empty($fetchStatus['fetched_at']) && ($fetchedAt === '' || strcmp((string)$fetchStatus['fetched_at'], $fetchedAt) >= 0)) {
                $fetchedAt = (string)$fetchStatus['fetched_at'];
                $dataDate = (string)($fetchStatus['data_date'] ?? $dataDate);
                $total = max($total, (int)($fetchStatus['saved_count'] ?? 0));
            }
        }

        return [
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_source' => '携程 ebooking',
            'status' => $total > 0 ? 'success' : 'empty',
            'status_label' => $total > 0 ? '有入库记录' : ($targetDataDate !== '' ? '目标日期未采集' : '暂无入库记录'),
            'verification_status' => $total > 0 ? 'record_present_source_not_proven' : 'not_available',
            'data_date' => $dataDate,
            'target_data_date' => $targetDataDate,
            'fetched_at' => $fetchedAt,
            'total_records' => $total,
            'early_morning_fallback' => !empty($earlyFallbacks),
            'early_morning_fallbacks' => $earlyFallbacks,
        ];
    }

    private function applyCtripStorageFilter($query, array $columns): void
    {
        if (isset($columns['source'], $columns['platform'])) {
            $query->where(function ($q) {
                $q->where('source', 'ctrip')->whereOr('platform', 'Ctrip');
            });
            return;
        }
        if (isset($columns['source'])) {
            $query->where('source', 'ctrip');
            return;
        }
        if (isset($columns['platform'])) {
            $query->where('platform', 'Ctrip');
        }
    }

    private function applyCtripSectionTypeFilter($query, string $section, array $columns): void
    {
        if (!isset($columns['data_type'])) {
            return;
        }

        $section = strtolower($section);
        if (in_array($section, ['rank', 'business'], true)) {
            $query->where(function ($q) {
                $q->where('data_type', 'business')
                    ->whereOr('data_type', '')
                    ->whereOr('data_type', 'competitor')
                    ->whereOr('data_type', 'ranking');
            });
            return;
        }
        if ($section === 'review') {
            $query->where(function ($q) {
                $q->where('data_type', 'review')->whereOr('data_type', 'comment')->whereOr('data_type', 'comments');
            });
            return;
        }
        $query->where('data_type', $section);
    }

    private function applyCtripCompetitionCircleFilter($query, array $columns): void
    {
        if (isset($columns['data_type'])) {
            $query->where('data_type', 'competitor');
        }
        if (isset($columns['dimension'])) {
            $query->where('dimension', 'competition_circle_hotel');
        }
    }

    private function applyCtripHotelScope($query, string $hotelId, $currentUser, array $columns): void
    {
        if ($hotelId !== '') {
            if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
                $query->where('system_hotel_id', (int)$hotelId);
            } elseif (isset($columns['hotel_id'])) {
                $query->where('hotel_id', $hotelId);
            }
        }

        if ($currentUser && !$currentUser->isSuperAdmin()) {
            $permittedHotelIds = $currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds) || !isset($columns['system_hotel_id'])) {
                $query->where('id', 0);
            } else {
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
        }
    }

    private function applyCtripLatestBatchScope($query, array $latest, string $hotelId, array $columns): void
    {
        if ($hotelId === '' && isset($columns['system_hotel_id'])) {
            if (isset($latest['system_hotel_id']) && $latest['system_hotel_id'] !== null && $latest['system_hotel_id'] !== '') {
                $query->where('system_hotel_id', (int)$latest['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }
        }

        $snapshotBucket = trim((string)($latest['snapshot_bucket'] ?? ''));
        if (isset($columns['snapshot_bucket']) && $snapshotBucket !== '') {
            $query->where('snapshot_bucket', $snapshotBucket);
            return;
        }

        $this->applyOnlineLatestFetchTimeScope($query, $latest, $columns);
    }

    private function applyOnlineLatestFetchTimeScope($query, array $latest, array $columns): void
    {
        foreach (['update_time', 'create_time'] as $column) {
            if (isset($columns[$column]) && !empty($latest[$column])) {
                $query->where($column, (string)$latest[$column]);
                return;
            }
        }
    }

    private function orderOnlineDataByFetchTime($query, array $columns, string $direction = 'desc')
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        if (isset($columns['update_time'])) {
            $query->order('update_time', $direction);
        }
        if (isset($columns['create_time'])) {
            $query->order('create_time', $direction);
        }
        return $query->order('id', $direction);
    }

    /**
     * Scan only grouping/status metadata first so history pagination never loads
     * every matching raw_data payload before applying the requested page.
     */
    private function selectOnlineHistoryLightweightFields($query, array $columns)
    {
        $wantedFields = [
            'id',
            'data_date',
            'source',
            'platform',
            'data_type',
            'system_hotel_id',
            'dimension',
            'compare_type',
            'create_time',
            'update_time',
        ];
        $fields = array_values(array_filter(
            $wantedFields,
            static fn (string $field): bool => isset($columns[$field])
        ));
        $query->field($fields);
        $query->fieldRaw($this->onlineHistoryLightweightStatusExpression($columns) . ' AS history_row_status');
        return $query;
    }

    private function onlineHistoryLightweightStatusExpression(array $columns): string
    {
        if (isset($columns['history_status'])) {
            return "COALESCE(`history_status`, 'unverified')";
        }

        $conditions = [];
        $failedRowStatuses = "'" . implode("','", OnlineDataTrustStatusService::FAILED_ROW_STATUSES) . "'";
        $unverifiedRowStatuses = "'" . implode("','", OnlineDataTrustStatusService::UNVERIFIED_ROW_STATUSES) . "'";
        $failedValidationStatuses = "'" . implode("','", OnlineDataTrustStatusService::FAILED_VALIDATION_STATUSES) . "'";
        $unverifiedValidationStatuses = "'" . implode("','", OnlineDataTrustStatusService::UNVERIFIED_VALIDATION_STATUSES) . "'";
        $partialValidationStatuses = "'" . implode("','", OnlineDataTrustStatusService::PARTIAL_VALIDATION_STATUSES) . "'";
        if (isset($columns['status'])) {
            $conditions[] = "WHEN LOWER(TRIM(COALESCE(`status`, ''))) IN ({$failedRowStatuses}) THEN 'failed'";
            $conditions[] = "WHEN LOWER(TRIM(COALESCE(`status`, ''))) IN ({$unverifiedRowStatuses}) THEN 'unverified'";
        }
        if (isset($columns['validation_status'])) {
            $conditions[] = "WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ({$failedValidationStatuses}) THEN 'failed'";
            $conditions[] = "WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ({$unverifiedValidationStatuses}) THEN 'unverified'";
            $conditions[] = "WHEN LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ({$partialValidationStatuses}) THEN 'partial'";
        }

        if (isset($columns['readback_verified'])) {
            $conditions[] = "WHEN COALESCE(`readback_verified`, 0) <> 1 THEN 'unverified'";
            $conditions[] = "WHEN COALESCE(`readback_verified`, 0) = 1 THEN 'success'";
        }

        $hasStructuredStatus = isset($columns['status'])
            || isset($columns['validation_status'])
            || isset($columns['readback_verified']);
        if (isset($columns['raw_data']) && !$hasStructuredStatus) {
            $driver = strtolower((string)Db::connect()->getConfig('type'));
            if ($driver === 'sqlite') {
                $rawErrorCondition = "json_valid(`raw_data`) = 1 AND ("
                    . "(json_type(`raw_data`, '$.error') IS NOT NULL AND json_type(`raw_data`, '$.error') <> 'null') OR "
                    . "(json_type(`raw_data`, '$.errors') IS NOT NULL AND json_type(`raw_data`, '$.errors') <> 'null'))";
            } else {
                $rawErrorCondition = "JSON_VALID(`raw_data`) = 1 AND ("
                    . "(JSON_CONTAINS_PATH(`raw_data`, 'one', '$.error') = 1 AND JSON_TYPE(JSON_EXTRACT(`raw_data`, '$.error')) <> 'NULL') OR "
                    . "(JSON_CONTAINS_PATH(`raw_data`, 'one', '$.errors') = 1 AND JSON_TYPE(JSON_EXTRACT(`raw_data`, '$.errors')) <> 'NULL'))";
            }
            $conditions[] = "WHEN `raw_data` IS NOT NULL AND `raw_data` <> '' AND {$rawErrorCondition} THEN 'failed'";
        }

        $metricFields = array_values(array_filter([
            'amount',
            'quantity',
            'book_order_num',
            'data_value',
            'list_exposure',
            'detail_exposure',
            'order_submit_num',
        ], static fn (string $field): bool => isset($columns[$field])));
        if ($metricFields !== []) {
            $metricCondition = implode(' OR ', array_map(
                static fn (string $field): string => "COALESCE(`{$field}`, 0) > 0",
                $metricFields
            ));
            $conditions[] = "WHEN {$metricCondition} THEN 'success'";
        }

        if (isset($columns['raw_data']) && !isset($columns['readback_verified'])) {
            $conditions[] = "WHEN `raw_data` IS NOT NULL AND `raw_data` <> '' THEN 'success'";
        }

        return "CASE\n" . implode("\n", $conditions) . "\nELSE 'empty' END";
    }

    /**
     * Page merged history groups in SQL before loading any raw_data payloads.
     * The second query in history() only hydrates records belonging to the
     * selected group keys, preserving the existing merged-list contract.
     */
    private function buildOnlineHistoryDatabasePagination(
        $query,
        array $columns,
        int $page,
        int $pageSize
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $groupKeyExpression = $this->onlineHistorySqlGroupKeyExpression($columns);
        $fetchTimeExpression = $this->onlineHistorySqlFetchTimeExpression($columns);
        $orderKeyExpression = $this->onlineHistorySqlOrderKeyExpression($columns);
        $driver = $this->onlineHistoryDatabaseDriver();
        $usesFoundRows = in_array($driver, ['mysql', 'mariadb'], true);
        $groupFields = [
            ($usesFoundRows ? 'SQL_CALC_FOUND_ROWS ' : '') . "{$groupKeyExpression} AS history_group_key",
            "MAX({$orderKeyExpression}) AS history_order_key",
            "MAX({$fetchTimeExpression}) AS group_fetch_time",
        ];
        if ($driver === 'sqlite') {
            $groupFields[] = 'COUNT(*) OVER () AS total_records';
        }

        $groupRows = (clone $query)
            ->fieldRaw(implode(', ', $groupFields))
            ->group('history_group_key')
            ->order('history_order_key', 'desc')
            ->limit(($page - 1) * $pageSize, $pageSize)
            ->select()
            ->toArray();

        if ($usesFoundRows) {
            $foundRows = Db::query('SELECT FOUND_ROWS() AS total_records');
            $total = (int)($foundRows[0]['total_records'] ?? 0);
        } elseif ($groupRows !== []) {
            $total = (int)($groupRows[0]['total_records'] ?? 0);
        } elseif ($page > 1) {
            $firstPage = $this->buildOnlineHistoryDatabasePagination(clone $query, $columns, 1, 1);
            $firstPage['group_keys'] = [];
            return $firstPage;
        } else {
            $total = 0;
        }

        $summary = $this->buildOnlineHistoryDatabaseSummary(
            clone $query,
            $columns,
            $groupKeyExpression,
            $fetchTimeExpression,
            $total
        );

        $groupKeys = [];
        foreach ($groupRows as $row) {
            $groupKey = (string)($row['history_group_key'] ?? '');
            if ($groupKey !== '') {
                $groupKeys[] = $groupKey;
            }
        }

        return [
            'total' => $total,
            'group_keys' => array_values(array_unique($groupKeys)),
            'group_key_expression' => $groupKeyExpression,
            'summary' => $summary,
        ];
    }

    private function buildOnlineHistoryDatabaseSummary(
        $query,
        array $columns,
        string $groupKeyExpression,
        string $fetchTimeExpression,
        int $total
    ): array {
        $latestRows = (clone $query)
            ->fieldRaw("MAX({$fetchTimeExpression}) AS latest_fetch_time")
            ->select()
            ->toArray();

        $todayRecords = 0;
        if (isset($columns['history_fetch_time'])
            || isset($columns['update_time'])
            || isset($columns['create_time'])) {
            $today = date('Y-m-d');
            $todayQuery = (clone $query)->whereRaw(
                "({$fetchTimeExpression}) BETWEEN :history_today_start AND :history_today_end",
                [
                    'history_today_start' => $today . ' 00:00:00',
                    'history_today_end' => $today . ' 23:59:59',
                ]
            );
            $todayRecords = $this->countOnlineHistoryDistinctGroups($todayQuery, $groupKeyExpression);
        }

        $failedRecords = 0;
        $failedConditions = [];
        if (isset($columns['history_status'])) {
            $failedQuery = (clone $query)->where('history_status', 'failed');
            $failedRecords = $this->countOnlineHistoryDistinctGroups($failedQuery, $groupKeyExpression);
        } else {
            if (isset($columns['status'])) {
                $failedRowStatuses = "'" . implode("','", OnlineDataTrustStatusService::FAILED_ROW_STATUSES) . "'";
                $failedConditions[] = "LOWER(TRIM(COALESCE(`status`, ''))) IN ({$failedRowStatuses})";
            }
            if (isset($columns['validation_status'])) {
                $failedValidationStatuses = "'" . implode("','", OnlineDataTrustStatusService::FAILED_VALIDATION_STATUSES) . "'";
                $failedConditions[] = "LOWER(TRIM(COALESCE(`validation_status`, ''))) IN ({$failedValidationStatuses})";
            }
            if ($failedConditions !== []) {
                $failedQuery = (clone $query)->whereRaw('(' . implode(' OR ', $failedConditions) . ')');
                $failedRecords = $this->countOnlineHistoryDistinctGroups($failedQuery, $groupKeyExpression);
            }
        }

        return [
            'total_records' => $total,
            'latest_fetch_time' => (string)($latestRows[0]['latest_fetch_time'] ?? ''),
            'today_records' => $todayRecords,
            'failed_records' => $failedRecords,
        ];
    }

    private function countOnlineHistoryDistinctGroups($query, string $groupKeyExpression): int
    {
        $rows = $query
            ->fieldRaw("COUNT(DISTINCT {$groupKeyExpression}) AS total_records")
            ->select()
            ->toArray();
        return (int)($rows[0]['total_records'] ?? 0);
    }

    private function applyOnlineHistoryGroupKeyScope($query, string $groupKeyExpression, array $groupKeys)
    {
        $groupKeys = array_values(array_filter(array_map('strval', $groupKeys), static fn (string $key): bool => $key !== ''));
        if ($groupKeys === []) {
            return $query->whereRaw('1 = 0');
        }

        $placeholders = [];
        $bind = [];
        foreach ($groupKeys as $index => $groupKey) {
            $name = 'history_group_' . $index;
            $placeholders[] = ':' . $name;
            $bind[$name] = $groupKey;
        }

        return $query->whereRaw(
            '(' . $groupKeyExpression . ') IN (' . implode(', ', $placeholders) . ')',
            $bind
        );
    }

    private function onlineHistorySqlGroupKeyExpression(array $columns): string
    {
        if (isset($columns['history_group_key'])) {
            // Keep the generated column bare so the page hydration IN query
            // can use idx_online_daily_history_group_fetch. Wrapping it in a
            // CAST/COALESCE forces MariaDB to scan the whole filtered scope.
            return '`history_group_key`';
        }

        $dataDate = $this->onlineHistorySqlColumnText($columns, 'data_date');
        $platform = $this->onlineHistorySqlPlatformExpression($columns);
        $dataType = $this->onlineHistorySqlDataTypeExpression($columns);
        $systemHotelId = $this->onlineHistorySqlColumnText($columns, 'system_hotel_id');
        $dimension = $this->onlineHistorySqlColumnText($columns, 'dimension');
        $compareType = $this->onlineHistorySqlColumnText($columns, 'compare_type');
        $fetchTime = $this->onlineHistorySqlFetchTimeExpression($columns);
        $competitionCircle = "({$dataType} = 'competitor' AND {$dimension} = 'competition_circle_hotel')";
        $compareGroup = "CASE WHEN {$competitionCircle} THEN 'competition_circle' ELSE {$compareType} END";
        $batchFetchTime = "CASE WHEN {$competitionCircle} THEN {$fetchTime} ELSE '' END";
        $parts = [
            $dataDate,
            $platform,
            $dataType,
            $systemHotelId,
            $dimension,
            $compareGroup,
            $batchFetchTime,
        ];

        if ($this->onlineHistoryDatabaseDriver() === 'sqlite') {
            return '(' . implode(" || '|' || ", $parts) . ')';
        }

        return 'CONCAT(' . implode(", '|', ", $parts) . ')';
    }

    private function onlineHistorySqlPlatformExpression(array $columns): string
    {
        if (isset($columns['platform'], $columns['source'])) {
            $raw = 'LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), CAST(`source` AS CHAR), \'' . '\')))';
        } elseif (isset($columns['platform'])) {
            $raw = 'LOWER(TRIM(COALESCE(CAST(`platform` AS CHAR), \'' . '\')))';
        } elseif (isset($columns['source'])) {
            $raw = 'LOWER(TRIM(COALESCE(CAST(`source` AS CHAR), \'' . '\')))';
        } else {
            $raw = "''";
        }

        if ($this->onlineHistoryDatabaseDriver() === 'sqlite') {
            $raw = str_replace(' AS CHAR)', ' AS TEXT)', $raw);
        }

        return "CASE "
            . "WHEN {$raw} IN ('ctrip', '携程') THEN 'ctrip' "
            . "WHEN {$raw} IN ('meituan', '美团') THEN 'meituan' "
            . "WHEN {$raw} IN ('qunar', '去哪儿') THEN 'qunar' "
            . "WHEN {$raw} <> '' THEN {$raw} ELSE 'unknown' END";
    }

    private function onlineHistorySqlDataTypeExpression(array $columns): string
    {
        $dataType = isset($columns['data_type'])
            ? 'LOWER(TRIM(' . $this->onlineHistorySqlColumnText($columns, 'data_type') . '))'
            : "''";
        $compareType = $this->onlineHistorySqlColumnText($columns, 'compare_type');

        return "CASE "
            . "WHEN {$compareType} = 'competitor_avg' THEN 'competitor' "
            . "WHEN {$dataType} = '' THEN 'business' "
            . "WHEN {$dataType} IN ('comment', 'comments') THEN 'review' "
            . "WHEN {$dataType} IN ('ad', 'ads') THEN 'advertising' "
            . "ELSE {$dataType} END";
    }

    private function onlineHistorySqlFetchTimeExpression(array $columns): string
    {
        if (isset($columns['history_fetch_time'])) {
            return '`history_fetch_time`';
        }

        $updateTime = $this->onlineHistorySqlColumnText($columns, 'update_time');
        $createTime = $this->onlineHistorySqlColumnText($columns, 'create_time');
        return "CASE WHEN {$updateTime} > {$createTime} THEN {$updateTime} ELSE {$createTime} END";
    }

    private function onlineHistorySqlOrderKeyExpression(array $columns): string
    {
        $updateTime = $this->onlineHistorySqlColumnText($columns, 'update_time');
        $createTime = $this->onlineHistorySqlColumnText($columns, 'create_time');
        if ($this->onlineHistoryDatabaseDriver() === 'sqlite') {
            $id = isset($columns['id']) ? "printf('%020d', COALESCE(`id`, 0))" : "'00000000000000000000'";
            return "({$updateTime} || '|' || {$createTime} || '|' || {$id})";
        }

        $id = isset($columns['id'])
            ? "LPAD(CAST(COALESCE(`id`, 0) AS CHAR), 20, '0')"
            : "'00000000000000000000'";
        return "CONCAT({$updateTime}, '|', {$createTime}, '|', {$id})";
    }

    private function onlineHistorySqlColumnText(array $columns, string $column): string
    {
        if (!isset($columns[$column])) {
            return "''";
        }

        $type = $this->onlineHistoryDatabaseDriver() === 'sqlite' ? 'TEXT' : 'CHAR';
        return "COALESCE(CAST(`{$column}` AS {$type}), '')";
    }

    private function onlineHistoryDatabaseDriver(): string
    {
        return strtolower((string)Db::connect()->getConfig('type'));
    }

    private function orderOnlineHistoryMergedGroups(array $groups, array $groupKeys): array
    {
        $positions = array_flip(array_values($groupKeys));
        $hashedKeys = isset($groupKeys[0]) && preg_match('/^[a-f0-9]{64}$/i', (string)$groupKeys[0]) === 1;
        usort($groups, function (array $left, array $right) use ($positions, $hashedKeys): int {
            $leftKey = $this->buildOnlineHistoryMergeKey($left);
            $rightKey = $this->buildOnlineHistoryMergeKey($right);
            if ($hashedKeys) {
                $leftKey = hash('sha256', $leftKey);
                $rightKey = hash('sha256', $rightKey);
            }
            return ($positions[$leftKey] ?? PHP_INT_MAX) <=> ($positions[$rightKey] ?? PHP_INT_MAX);
        });
        return $groups;
    }

    private function buildOnlineHistoryLightweightPagination(array $rows, int $page, int $pageSize): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $fetchTime = $this->onlineHistoryLightweightFetchTime($row);
            $groupKey = $this->buildOnlineHistoryStorageMergeKey($row, $fetchTime);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'record_ids' => [],
                    'fetch_time' => $fetchTime,
                    'order_update_time' => (string)($row['update_time'] ?? ''),
                    'order_create_time' => (string)($row['create_time'] ?? ''),
                    'order_id' => (int)($row['id'] ?? 0),
                    'failed' => 0,
                    'empty' => 0,
                    'unverified' => 0,
                    'partial' => 0,
                    'success' => 0,
                ];
            }

            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $groups[$groupKey]['record_ids'][] = $id;
            }
            if ($fetchTime !== '' && strcmp($fetchTime, (string)$groups[$groupKey]['fetch_time']) > 0) {
                $groups[$groupKey]['fetch_time'] = $fetchTime;
            }
            $this->updateOnlineHistoryLightweightGroupOrder($groups[$groupKey], $row);
            $status = strtolower(trim((string)($row['history_row_status'] ?? 'empty')));
            $status = isset($groups[$groupKey][$status]) ? $status : 'empty';
            $groups[$groupKey][$status]++;
        }

        uasort($groups, static function (array $left, array $right): int {
            $updateOrder = strcmp((string)$right['order_update_time'], (string)$left['order_update_time']);
            if ($updateOrder !== 0) {
                return $updateOrder;
            }
            $createOrder = strcmp((string)$right['order_create_time'], (string)$left['order_create_time']);
            if ($createOrder !== 0) {
                return $createOrder;
            }
            return (int)$right['order_id'] <=> (int)$left['order_id'];
        });

        $summary = [
            'total_records' => count($groups),
            'latest_fetch_time' => '',
            'today_records' => 0,
            'failed_records' => 0,
        ];
        $today = date('Y-m-d');
        foreach ($groups as &$group) {
            $group['status'] = $this->resolveOnlineHistoryLightweightGroupStatus($group);
            $fetchTime = (string)$group['fetch_time'];
            if ($fetchTime !== '' && ($summary['latest_fetch_time'] === '' || strcmp($fetchTime, $summary['latest_fetch_time']) > 0)) {
                $summary['latest_fetch_time'] = $fetchTime;
            }
            if ($fetchTime !== '' && substr($fetchTime, 0, 10) === $today) {
                $summary['today_records']++;
            }
            if ($group['status'] === 'failed') {
                $summary['failed_records']++;
            }
        }
        unset($group);

        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $pageGroups = array_slice(array_values($groups), ($page - 1) * $pageSize, $pageSize);
        $recordIds = [];
        foreach ($pageGroups as $group) {
            array_push($recordIds, ...$group['record_ids']);
        }

        return [
            'total' => count($groups),
            'record_ids' => array_values(array_unique($recordIds)),
            'summary' => $summary,
        ];
    }

    private function updateOnlineHistoryLightweightGroupOrder(array &$group, array $row): void
    {
        $rowUpdateTime = (string)($row['update_time'] ?? '');
        $rowCreateTime = (string)($row['create_time'] ?? '');
        $rowId = (int)($row['id'] ?? 0);
        $updateComparison = strcmp($rowUpdateTime, (string)$group['order_update_time']);
        $createComparison = strcmp($rowCreateTime, (string)$group['order_create_time']);
        if ($updateComparison > 0
            || ($updateComparison === 0 && $createComparison > 0)
            || ($updateComparison === 0 && $createComparison === 0 && $rowId > (int)$group['order_id'])) {
            $group['order_update_time'] = $rowUpdateTime;
            $group['order_create_time'] = $rowCreateTime;
            $group['order_id'] = $rowId;
        }
    }

    private function buildOnlineHistoryStorageMergeKey(array $row, string $fetchTime): string
    {
        $source = strtolower((string)($row['source'] ?? ''));
        $platform = $this->normalizeHistoryPlatformCode($row['platform'] ?? $source);
        $compareType = (string)($row['compare_type'] ?? '');
        $dataType = $this->normalizeHistoryDataType((string)($row['data_type'] ?? ''), $compareType);
        $isCompetitionCircle = $dataType === 'competitor'
            && (string)($row['dimension'] ?? '') === 'competition_circle_hotel';

        return implode('|', [
            (string)($row['data_date'] ?? ''),
            $platform,
            $dataType,
            (string)($row['system_hotel_id'] ?? ''),
            (string)($row['dimension'] ?? ''),
            $isCompetitionCircle ? 'competition_circle' : $compareType,
            $isCompetitionCircle ? $fetchTime : '',
        ]);
    }

    private function onlineHistoryLightweightFetchTime(array $row): string
    {
        $createTime = (string)($row['create_time'] ?? '');
        $updateTime = (string)($row['update_time'] ?? '');
        return strcmp($updateTime, $createTime) > 0 ? $updateTime : $createTime;
    }

    private function resolveOnlineHistoryLightweightGroupStatus(array $group): string
    {
        if ((int)($group['failed'] ?? 0) > 0) {
            return 'failed';
        }
        if ((int)($group['unverified'] ?? 0) > 0) {
            return 'unverified';
        }
        if ((int)($group['partial'] ?? 0) > 0) {
            return 'partial';
        }
        if ((int)($group['success'] ?? 0) > 0) {
            return 'success';
        }
        return 'empty';
    }

    private function onlineRowFetchedAt(array $row, array $columns): string
    {
        if (isset($columns['update_time']) && !empty($row['update_time'])) {
            return (string)$row['update_time'];
        }
        if (isset($columns['create_time']) && !empty($row['create_time'])) {
            return (string)$row['create_time'];
        }
        return '';
    }

    private function maxOnlineRowsFetchedAt(array $rows, array $columns): string
    {
        $max = '';
        foreach ($rows as $row) {
            $time = $this->onlineRowFetchedAt($row, $columns);
            if ($time !== '' && ($max === '' || strcmp($time, $max) > 0)) {
                $max = $time;
            }
        }
        return $max;
    }

    private function decodeOnlineRawRows(array $rows): array
    {
        $payload = [];
        foreach ($rows as $row) {
            $raw = (string)($row['raw_data'] ?? '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                $decoded = $this->buildOnlineRowPayload($row);
            }
            $decoded['_record_id'] = (int)($row['id'] ?? 0);
            $decoded['_data_date'] = (string)($row['data_date'] ?? '');
            $decoded['_fetch_time'] = (string)($row['update_time'] ?? $row['create_time'] ?? '');
            $decoded['compareType'] = (string)($row['compare_type'] ?? $decoded['compareType'] ?? '');
            $decoded['isSelf'] = ($row['compare_type'] ?? '') === 'self';
            $decoded['systemHotelId'] = (int)($row['system_hotel_id'] ?? 0);
            if ($decoded['isSelf'] && $decoded['systemHotelId'] > 0) {
                $decoded['systemHotelName'] = $this->getSystemHotelName($decoded['systemHotelId']);
            }
            $payload[] = $decoded;
        }
        return $payload;
    }

    private function buildOnlineRowPayload(array $row): array
    {
        return [
            'hotelId' => $row['hotel_id'] ?? '',
            'hotelName' => $row['hotel_name'] ?? '',
            'date' => $row['data_date'] ?? '',
            'amount' => $this->nullableOnlineRowMetric($row, 'amount'),
            'quantity' => $this->nullableOnlineRowMetric($row, 'quantity', true),
            'bookOrderNum' => $this->nullableOnlineRowMetric($row, 'book_order_num', true),
            'commentScore' => $this->nullableOnlineRowMetric($row, 'comment_score'),
            'qunarCommentScore' => $this->nullableOnlineRowMetric($row, 'qunar_comment_score'),
            'dataValue' => $this->nullableOnlineRowMetric($row, 'data_value'),
            'listExposure' => $this->nullableOnlineRowMetric($row, 'list_exposure', true),
            'detailExposure' => $this->nullableOnlineRowMetric($row, 'detail_exposure', true),
            'flowRate' => $this->nullableOnlineRowMetric($row, 'flow_rate'),
            'orderFillingNum' => $this->nullableOnlineRowMetric($row, 'order_filling_num', true),
            'orderSubmitNum' => $this->nullableOnlineRowMetric($row, 'order_submit_num', true),
        ];
    }

    private function nullableOnlineRowMetric(array $row, string $field, bool $integer = false): int|float|null
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '' || !is_numeric($row[$field])) {
            return null;
        }
        return $integer ? (int)$row[$field] : (float)$row[$field];
    }

    private function applyOnlineHistoryFilters($query, $currentUser): void
    {
        $columns = $this->getOnlineDailyDataColumns();
        $platform = strtolower((string)$this->request->get('platform', $this->request->get('source', '')));
        $dataType = (string)$this->request->get('data_type', '');
        $hotelScope = (string)$this->request->get('hotel_scope', 'all');
        $hotelId = (string)$this->request->get('hotel_id', '');
        $otaHotelId = (string)$this->request->get('ota_hotel_id', '');
        $startDate = (string)$this->request->get('start_date', '');
        $endDate = (string)$this->request->get('end_date', '');

        if ($platform !== '' && $platform !== 'all') {
            if ($platform === 'ctrip') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'ctrip')->whereOr('platform', 'Ctrip');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'ctrip');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Ctrip');
                }
            } elseif ($platform === 'meituan') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'meituan')->whereOr('platform', 'Meituan');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'meituan');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Meituan');
                }
            } elseif ($platform === 'qunar') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'qunar')->whereOr('platform', 'Qunar');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'qunar');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Qunar');
                }
            }
        }

        if ($dataType !== '' && $dataType !== 'all' && isset($columns['data_type'])) {
            if ($dataType === 'business') {
                $this->applyDataTypeFilter($query, 'business');
            } elseif ($dataType === 'competitor') {
                if (isset($columns['compare_type'])) {
                    $query->where(function ($q) {
                        $q->where('data_type', 'competitor')
                            ->whereOr('compare_type', 'competitor_avg')
                            ->whereOr('hotel_name', 'like', '%竞争圈平均%');
                    });
                } else {
                    $query->where(function ($q) {
                        $q->where('data_type', 'competitor')->whereOr('hotel_name', 'like', '%竞争圈平均%');
                    });
                }
            } elseif ($dataType === 'review') {
                $query->where(function ($q) {
                    $q->where('data_type', 'review')->whereOr('data_type', 'comment');
                });
            } elseif ($dataType === 'advertising') {
                $query->where(function ($q) {
                    $q->where('data_type', 'advertising')->whereOr('data_type', 'ad');
                });
            } else {
                $query->where('data_type', $dataType);
            }
        }

        if ($startDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', '>=', $startDate);
        }
        if ($endDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', '<=', $endDate);
        }

        if (!in_array($hotelScope, ['all', 'mine', 'competitor_avg', 'hotel'], true) && $hotelScope !== '') {
            $hotelId = $hotelScope;
            $hotelScope = 'hotel';
        }

        if ($hotelScope === 'mine') {
            if (isset($columns['compare_type'], $columns['hotel_name'])) {
                $query->where(function ($q) {
                    $q->where('compare_type', 'self')
                        ->whereOr('hotel_name', '我的酒店');
                });
            } elseif (isset($columns['hotel_name'])) {
                $query->where('hotel_name', '我的酒店');
            }
        } elseif ($hotelScope === 'competitor_avg') {
            if (isset($columns['compare_type'])) {
                $query->where(function ($q) {
                    $q->where('compare_type', 'competitor_avg')
                        ->whereOr('hotel_id', '-1')
                        ->whereOr('hotel_name', '竞争圈平均');
                });
            } else {
                $query->where(function ($q) {
                    $q->where('hotel_id', '-1')->whereOr('hotel_name', '竞争圈平均');
                });
            }
        } elseif ($hotelScope === 'hotel' && $hotelId !== '' && $hotelId !== 'all') {
            $this->applyOnlineHistoryHotelIdFilter($query, $columns, $hotelId);
        } elseif ($hotelScope === 'all' && $hotelId !== '' && $hotelId !== 'all') {
            $this->applyOnlineHistoryHotelIdFilter($query, $columns, $hotelId);
        }

        if ($otaHotelId !== '' && isset($columns['hotel_id'])) {
            $query->where('hotel_id', $otaHotelId);
        }

        if (!$currentUser->isSuperAdmin()) {
            $permittedHotelIds = $currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds) || !isset($columns['system_hotel_id'])) {
                $query->where('id', 0);
            } else {
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
        }
    }

    private function applyOnlineHistoryHotelIdFilter($query, array $columns, string $hotelId): void
    {
        if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
            $query->where('system_hotel_id', (int)$hotelId);
            return;
        }

        if (isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelId);
        }
    }

    private function applyOnlineHistoryKeywordFilter($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }

        $columns = $this->getOnlineDailyDataColumns();
        $searchableColumns = array_values(array_filter([
            'id',
            'hotel_name',
            'hotel_id',
            'source',
            'platform',
            'data_type',
            'compare_type',
            'batch_no',
            'create_time',
            'data_date',
        ], static fn (string $column): bool => isset($columns[$column])));

        if (empty($searchableColumns)) {
            return;
        }

        $terms = $this->expandOnlineHistoryKeywordTerms($keyword);
        $query->where(function ($q) use ($searchableColumns, $terms) {
            $hasCondition = false;
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                foreach ($searchableColumns as $column) {
                    if ($hasCondition) {
                        $q->whereOr($column, 'like', $like);
                    } else {
                        $q->where($column, 'like', $like);
                        $hasCondition = true;
                    }
                }
            }
        });
    }

    private function expandOnlineHistoryKeywordTerms(string $keyword): array
    {
        $keyword = trim($keyword);
        $lowerKeyword = mb_strtolower($keyword);
        $terms = [$keyword];
        $labelMap = [
            '携程' => ['ctrip', 'Ctrip'],
            '美团' => ['meituan', 'Meituan'],
            '去哪儿' => ['qunar', 'Qunar'],
            '经营数据' => ['business'],
            '流量数据' => ['traffic'],
            '排名数据' => ['ranking', 'rank'],
            '竞对榜单' => ['peer_rank'],
            '搜索词' => ['search_keyword'],
            '流量分析' => ['traffic_analysis'],
            '未来预测' => ['traffic_forecast'],
            '预测数据' => ['traffic_forecast'],
            '竞对数据' => ['competitor', 'competitor_avg'],
            '竞争圈' => ['competitor', 'competitor_avg'],
            '点评数据' => ['review', 'comment'],
            '广告数据' => ['advertising', 'ad'],
            '服务质量' => ['quality', 'service', 'service_quality'],
            '订单数据' => ['order'],
            '订单流转' => ['order_flow'],
        ];

        foreach ($labelMap as $label => $values) {
            if (mb_strpos(mb_strtolower($label), $lowerKeyword) !== false || mb_strpos($lowerKeyword, mb_strtolower($label)) !== false) {
                array_push($terms, ...$values);
            }
        }

        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    private function buildOnlineHistorySummaryFromQuery($query, int $total): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $latestFetchTime = '';
        $todayRecords = 0;
        $failedRecords = 0;

        if (isset($columns['create_time']) || isset($columns['update_time'])) {
            $today = date('Y-m-d');
            $createMax = isset($columns['create_time']) ? (string)((clone $query)->max('create_time') ?: '') : '';
            $updateMax = isset($columns['update_time']) ? (string)((clone $query)->max('update_time') ?: '') : '';
            $latestFetchTime = strcmp($updateMax, $createMax) > 0 ? $updateMax : $createMax;
            $todayRecords = (int)(clone $query)->where(function ($q) use ($columns, $today) {
                $hasCondition = false;
                foreach (['update_time', 'create_time'] as $column) {
                    if (!isset($columns[$column])) {
                        continue;
                    }
                    $method = $hasCondition ? 'whereOrBetween' : 'whereBetween';
                    $q->{$method}($column, [$today . ' 00:00:00', $today . ' 23:59:59']);
                    $hasCondition = true;
                }
            })->count();
        }

        if (isset($columns['status'])) {
            $failedRecords = (int)(clone $query)->whereIn('status', ['failed', 'fail', 'error'])->count();
        } elseif (isset($columns['raw_data'])) {
            $failedRecords = (int)(clone $query)->where(function ($q) {
                $q->where('raw_data', 'like', '%"error"%')->whereOr('raw_data', 'like', '%"errors"%');
            })->count();
        }

        return [
            'total_records' => $total,
            'latest_fetch_time' => $latestFetchTime,
            'today_records' => $todayRecords,
            'failed_records' => $failedRecords,
        ];
    }

    private function getConfiguredHotelNameMap(): array
    {
        try {
            return Db::name('hotels')->where('status', 1)->column('name', 'id');
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function normalizeOnlineHistoryRow(array $row, array $hotelMap): array
    {
        $rawData = (string)($row['raw_data'] ?? $row['response_json'] ?? $row['data'] ?? '');
        $source = strtolower((string)($row['source'] ?? ''));
        $platformCode = $this->normalizeHistoryPlatformCode($row['platform'] ?? $source);
        $compareType = (string)($row['compare_type'] ?? '');
        $otaHotelId = (string)($row['ota_hotel_id'] ?? $row['hotel_id'] ?? '');
        $systemHotelId = $row['system_hotel_id'] ?? null;
        $displayHotelName = $this->buildHistoryHotelDisplayName($row, $hotelMap);
        $dataType = $this->normalizeHistoryDataType((string)($row['data_type'] ?? ''), $compareType);
        $status = $this->resolveHistoryStatus($row, $rawData);

        $item = $row;
        $item['id'] = (int)$row['id'];
        $createTime = (string)($row['create_time'] ?? '');
        $updateTime = (string)($row['update_time'] ?? '');
        $item['fetch_time'] = strcmp($updateTime, $createTime) > 0 ? $updateTime : $createTime;
        $item['data_date'] = (string)($row['data_date'] ?? '');
        $item['platform'] = $platformCode;
        $item['platform_label'] = $this->historyPlatformLabel($platformCode);
        $item['data_type'] = $dataType;
        $item['data_type_label'] = $this->historyDataTypeLabel($dataType);
        $item['hotel_name'] = $displayHotelName;
        $item['original_hotel_name'] = (string)($row['hotel_name'] ?? '');
        $item['hotel_id'] = $systemHotelId;
        $item['system_hotel_name'] = $systemHotelId && isset($hotelMap[(int)$systemHotelId])
            ? (string)$hotelMap[(int)$systemHotelId]
            : '';
        $item['ota_hotel_id'] = $otaHotelId;
        $item['is_my_hotel'] = $this->isMyHotelHistoryRow($row);
        $item['batch_no'] = $this->buildHistoryBatchNo($row, $rawData, $platformCode, $dataType);
        $item['status'] = $status;
        $item['status_label'] = $this->historyStatusLabel($status);
        $item['raw_data'] = $rawData;
        $item['metrics_summary'] = $this->buildHistoryMetricSummary($row, $rawData);

        return $item;
    }

    private function mergeOnlineHistoryRows(array $rows, array $hotelMap): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $item = $this->normalizeOnlineHistoryRow($row, $hotelMap);
            $groupKey = $this->buildOnlineHistoryMergeKey($item);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $item;
                $groups[$groupKey]['record_count'] = 0;
                $groups[$groupKey]['raw_record_count'] = 0;
                $groups[$groupKey]['hotel_count'] = 0;
                $groups[$groupKey]['record_ids'] = [];
                $groups[$groupKey]['merged_hotel_names'] = [];
                $groups[$groupKey]['merged_ota_hotel_ids'] = [];
                $groups[$groupKey]['merged_batch_nos'] = [];
                $groups[$groupKey]['_dedupe_key_map'] = [];
                $groups[$groupKey]['_hotel_name_map'] = [];
                $groups[$groupKey]['_ota_hotel_id_map'] = [];
                $groups[$groupKey]['_batch_no_map'] = [];
                $groups[$groupKey]['_raw_samples'] = [];
                $groups[$groupKey]['_amount_total'] = 0.0;
                $groups[$groupKey]['_quantity_total'] = 0.0;
                $groups[$groupKey]['_order_total'] = 0.0;
                $groups[$groupKey]['_data_value_total'] = 0.0;
                $groups[$groupKey]['_failed_count'] = 0;
                $groups[$groupKey]['_empty_count'] = 0;
                $groups[$groupKey]['_success_count'] = 0;
                $groups[$groupKey]['_partial_count'] = 0;
                $groups[$groupKey]['_unverified_count'] = 0;
                $groups[$groupKey]['_self_count'] = 0;
                $groups[$groupKey]['_competitor_count'] = 0;
            }

            $this->appendOnlineHistoryGroupRow($groups[$groupKey], $item);
        }

        $historyGroups = [];
        foreach ($groups as $group) {
            $historyGroups[] = $this->finalizeOnlineHistoryGroup($group);
        }
        return $historyGroups;
    }

    private function buildOnlineHistoryMergeKey(array $item): string
    {
        $isCompetitionCircle = (string)($item['data_type'] ?? '') === 'competitor'
            && (string)($item['dimension'] ?? '') === 'competition_circle_hotel';
        return implode('|', [
            (string)($item['data_date'] ?? ''),
            (string)($item['platform'] ?? ''),
            (string)($item['data_type'] ?? ''),
            (string)($item['hotel_id'] ?? ''),
            (string)($item['dimension'] ?? ''),
            $isCompetitionCircle ? 'competition_circle' : (string)($item['compare_type'] ?? ''),
            $isCompetitionCircle ? (string)($item['fetch_time'] ?? '') : '',
        ]);
    }

    private function appendOnlineHistoryGroupRow(array &$group, array $item): void
    {
        $group['raw_record_count']++;
        $group['record_ids'][] = (int)($item['id'] ?? 0);

        $hotelName = trim((string)($item['hotel_name'] ?? ''));
        $otaHotelId = trim((string)($item['ota_hotel_id'] ?? ''));
        $hotelKey = $otaHotelId !== '' ? $otaHotelId : $hotelName;
        $dedupeKey = $hotelKey !== '' ? $hotelKey : 'record-' . (string)($item['id'] ?? '');
        $batchNo = trim((string)($item['batch_no'] ?? ''));
        if ($batchNo !== '' && !isset($group['_batch_no_map'][$batchNo])) {
            $group['_batch_no_map'][$batchNo] = $batchNo;
        }

        if (isset($group['_dedupe_key_map'][$dedupeKey])) {
            return;
        }
        $group['_dedupe_key_map'][$dedupeKey] = true;
        $group['record_count']++;

        if ($hotelKey !== '' && !isset($group['_hotel_name_map'][$hotelKey])) {
            $group['_hotel_name_map'][$hotelKey] = $hotelName !== '' ? $hotelName : $hotelKey;
        }
        if ($otaHotelId !== '' && !isset($group['_ota_hotel_id_map'][$otaHotelId])) {
            $group['_ota_hotel_id_map'][$otaHotelId] = $otaHotelId;
        }
        if (!empty($item['is_my_hotel'])) {
            $group['is_my_hotel'] = true;
            $group['_self_count']++;
        } elseif (
            (string)($item['data_type'] ?? '') === 'competitor'
            && (string)($item['dimension'] ?? '') === 'competition_circle_hotel'
        ) {
            $group['_competitor_count']++;
        }

        $fetchTime = (string)($item['fetch_time'] ?? '');
        if ($fetchTime !== '' && strcmp($fetchTime, (string)($group['fetch_time'] ?? '')) > 0) {
            $group['fetch_time'] = $fetchTime;
        }

        $status = (string)($item['status'] ?? '');
        if ($status === 'failed') {
            $group['_failed_count']++;
        } elseif ($status === 'empty') {
            $group['_empty_count']++;
        } elseif ($status === 'unverified') {
            $group['_unverified_count']++;
        } elseif ($status === 'partial') {
            $group['_partial_count']++;
        } else {
            $group['_success_count']++;
        }

        $group['_amount_total'] += (float)($item['amount'] ?? 0);
        $group['_quantity_total'] += (float)($item['quantity'] ?? 0);
        $group['_order_total'] += (float)($item['book_order_num'] ?? $item['order_submit_num'] ?? 0);
        $group['_data_value_total'] += (float)($item['data_value'] ?? 0);

        if (count($group['_raw_samples']) < 5) {
            $rawData = (string)($item['raw_data'] ?? '');
            $decodedRaw = $rawData !== '' ? json_decode($rawData, true) : null;
            $group['_raw_samples'][] = [
                'id' => (int)($item['id'] ?? 0),
                'hotel_name' => $hotelName,
                'ota_hotel_id' => $otaHotelId,
                'metrics_summary' => (string)($item['metrics_summary'] ?? ''),
                'raw_data' => is_array($decodedRaw) ? $decodedRaw : $rawData,
            ];
        }
    }

    private function finalizeOnlineHistoryGroup(array $group): array
    {
        $hotelNames = array_values($group['_hotel_name_map'] ?? []);
        $otaHotelIds = array_values($group['_ota_hotel_id_map'] ?? []);
        $batchNos = array_values($group['_batch_no_map'] ?? []);
        $recordCount = (int)($group['record_count'] ?? 1);
        $rawRecordCount = (int)($group['raw_record_count'] ?? $recordCount);
        $hotelCount = count($hotelNames);
        $failedCount = (int)($group['_failed_count'] ?? 0);
        $emptyCount = (int)($group['_empty_count'] ?? 0);
        $successCount = (int)($group['_success_count'] ?? 0);
        $partialCount = (int)($group['_partial_count'] ?? 0);
        $unverifiedCount = (int)($group['_unverified_count'] ?? 0);
        $selfCount = (int)($group['_self_count'] ?? 0);
        $competitorCount = (int)($group['_competitor_count'] ?? 0);
        $isCompetitionCircle = (string)($group['data_type'] ?? '') === 'competitor'
            && (string)($group['dimension'] ?? '') === 'competition_circle_hotel';

        $group['hotel_count'] = $hotelCount;
        $group['merged_hotel_names'] = $hotelNames;
        $group['merged_ota_hotel_ids'] = $otaHotelIds;
        $group['merged_batch_nos'] = $batchNos;
        $group['is_competition_circle'] = $isCompetitionCircle;
        $group['self_hotel_count'] = $selfCount;
        $group['competitor_hotel_count'] = $competitorCount;
        $group['role_summary'] = $isCompetitionCircle
            ? ('本店 ' . $selfCount . ' / 竞品 ' . $competitorCount)
            : (!empty($group['is_my_hotel']) ? '本店' : '非本店');
        if ($isCompetitionCircle) {
            $group['is_my_hotel'] = $selfCount > 0 && $competitorCount === 0;
            $systemHotelName = trim((string)($group['system_hotel_name'] ?? ''));
            $group['hotel_name'] = ($systemHotelName !== '' ? $systemHotelName : '门店')
                . '竞争圈（' . number_format($hotelCount) . '家）';
        }

        if ($recordCount > 1 || $rawRecordCount > 1) {
            if (!$isCompetitionCircle) {
                $group['hotel_name'] = $hotelCount > 1 ? '全部酒店（' . $hotelCount . '家）' : ($hotelNames[0] ?? $group['hotel_name'] ?? '-');
            }
            $group['ota_hotel_id'] = count($otaHotelIds) > 1 ? '多个' : ($otaHotelIds[0] ?? $group['ota_hotel_id'] ?? '');
            $group['metrics_summary'] = $this->buildMergedHistoryMetricSummary($group);
            $group['raw_data'] = $this->buildMergedHistoryRawData($group);
        }

        if ($failedCount > 0) {
            $group['status'] = 'failed';
        } elseif ($unverifiedCount > 0) {
            $group['status'] = 'unverified';
        } elseif ($partialCount > 0) {
            $group['status'] = 'partial';
        } elseif ($successCount > 0) {
            $group['status'] = 'success';
        } elseif ($emptyCount > 0) {
            $group['status'] = 'empty';
        }
        $group['status_label'] = $this->historyStatusLabel((string)($group['status'] ?? ''));

        unset(
            $group['_dedupe_key_map'],
            $group['_hotel_name_map'],
            $group['_ota_hotel_id_map'],
            $group['_batch_no_map'],
            $group['_raw_samples'],
            $group['_amount_total'],
            $group['_quantity_total'],
            $group['_order_total'],
            $group['_data_value_total'],
            $group['_failed_count'],
            $group['_empty_count'],
            $group['_success_count'],
            $group['_partial_count'],
            $group['_unverified_count'],
            $group['_self_count'],
            $group['_competitor_count']
        );

        return $group;
    }

    private function buildMergedHistoryMetricSummary(array $group): string
    {
        $recordCount = (int)($group['record_count'] ?? 0);
        $rawRecordCount = (int)($group['raw_record_count'] ?? $recordCount);
        $hotelCount = (int)($group['hotel_count'] ?? 0);
        $amountTotal = (float)($group['_amount_total'] ?? 0);
        $quantityTotal = (float)($group['_quantity_total'] ?? 0);
        $orderTotal = (float)($group['_order_total'] ?? 0);
        $dataValueTotal = (float)($group['_data_value_total'] ?? 0);
        $failedCount = (int)($group['_failed_count'] ?? 0);

        $metrics = [];
        if ($rawRecordCount > $recordCount && $recordCount > 0) {
            $metrics[] = '合并 ' . number_format($rawRecordCount) . ' 条为 ' . number_format($recordCount) . ' 条';
        } elseif ($recordCount > 0) {
            $metrics[] = '合并 ' . number_format($recordCount) . ' 条';
        }
        if ($hotelCount > 0) {
            $metrics[] = number_format($hotelCount) . ' 家酒店';
        }
        if ($quantityTotal > 0) {
            $metrics[] = '间夜 ' . number_format($quantityTotal);
        }
        if ($orderTotal > 0) {
            $metrics[] = '订单 ' . number_format($orderTotal);
        }
        if ($amountTotal > 0 && $quantityTotal > 0) {
            $metrics[] = '均房价 ¥' . number_format($amountTotal / $quantityTotal, 2);
        } elseif ($amountTotal > 0) {
            $metrics[] = '金额 ¥' . number_format($amountTotal, 2);
        }
        if ($dataValueTotal > 0 && $amountTotal <= 0 && $quantityTotal <= 0 && $orderTotal <= 0) {
            $metrics[] = '指标值 ' . number_format($dataValueTotal, 2);
        }
        if ($failedCount > 0) {
            $metrics[] = '异常 ' . number_format($failedCount) . ' 条';
        }

        return empty($metrics) ? '-' : implode(' / ', $metrics);
    }

    private function buildMergedHistoryRawData(array $group): string
    {
        $payload = [
            'merged' => true,
            'batch_no' => (string)($group['batch_no'] ?? ''),
            'data_date' => (string)($group['data_date'] ?? ''),
            'platform' => (string)($group['platform_label'] ?? $group['platform'] ?? ''),
            'data_type' => (string)($group['data_type_label'] ?? $group['data_type'] ?? ''),
            'record_count' => (int)($group['record_count'] ?? 0),
            'raw_record_count' => (int)($group['raw_record_count'] ?? $group['record_count'] ?? 0),
            'hotel_count' => (int)($group['hotel_count'] ?? 0),
            'record_ids' => $group['record_ids'] ?? [],
            'batch_nos' => $group['merged_batch_nos'] ?? [],
            'hotel_names' => $group['merged_hotel_names'] ?? [],
            'ota_hotel_ids' => $group['merged_ota_hotel_ids'] ?? [],
            'sample_records' => $group['_raw_samples'] ?? [],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function buildHistoryHotelDisplayName(array $row, array $hotelMap): string
    {
        $compareType = (string)($row['compare_type'] ?? '');
        if ($compareType === 'competitor_avg' || (string)($row['hotel_id'] ?? '') === '-1') {
            return '竞争圈平均';
        }

        $name = trim((string)($row['hotel_name'] ?? ''));
        if ($name !== '' && !$this->isDirtyQuestionMarkName($name)) {
            return $name;
        }

        $systemHotelId = $row['system_hotel_id'] ?? null;
        if ($systemHotelId && isset($hotelMap[(int)$systemHotelId])) {
            return $hotelMap[(int)$systemHotelId];
        }

        $otaHotelId = (string)($row['hotel_id'] ?? '');
        return $otaHotelId !== '' ? 'OTA酒店ID ' . $otaHotelId : '未知酒店';
    }

    private function isDirtyQuestionMarkName(string $name): bool
    {
        $text = preg_replace('/\s+/u', '', $name) ?? '';
        if ($text === '') {
            return false;
        }
        $questionCount = substr_count($text, '?');
        if ($questionCount === 0) {
            return false;
        }
        return $questionCount >= 4 && ($questionCount / max(1, strlen($text))) >= 0.35;
    }

    private function normalizeHistoryPlatformCode($platform): string
    {
        $value = strtolower(trim((string)$platform));
        if (in_array($value, ['ctrip', '携程'], true)) {
            return 'ctrip';
        }
        if (in_array($value, ['meituan', '美团'], true)) {
            return 'meituan';
        }
        if (in_array($value, ['qunar', '去哪儿'], true)) {
            return 'qunar';
        }
        return $value !== '' ? $value : 'unknown';
    }

    private function historyPlatformLabel(string $platform): string
    {
        return [
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            'unknown' => '未知',
        ][$platform] ?? $platform;
    }

    private function normalizeHistoryDataType(string $dataType, string $compareType): string
    {
        if ($compareType === 'competitor_avg') {
            return 'competitor';
        }
        $value = strtolower(trim($dataType));
        if ($value === '') {
            return 'business';
        }
        if (in_array($value, ['comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['ad', 'ads'], true)) {
            return 'advertising';
        }
        return $value;
    }

    private function historyDataTypeLabel(string $dataType): string
    {
        return [
            'business' => '经营数据',
            'traffic' => '流量数据',
            'ranking' => '排名数据',
            'rank' => '排名数据',
            'peer_rank' => '竞对榜单',
            'search_keyword' => '搜索词',
            'traffic_analysis' => '流量分析',
            'traffic_forecast' => '未来预测',
            'competitor' => '竞对数据',
            'review' => '点评数据',
            'advertising' => '广告数据',
            'quality' => '服务质量',
            'service' => '服务质量',
            'service_quality' => '服务质量',
            'order' => '订单数据',
            'order_flow' => '订单流转',
        ][$dataType] ?? $dataType;
    }

    private function resolveHistoryStatus(array $row, string $rawData): string
    {
        $rowClass = OnlineDataTrustStatusService::classifyRowStatus($row['status'] ?? '');
        $validationClass = OnlineDataTrustStatusService::classifyValidationStatus($row['validation_status'] ?? '');
        if ($rowClass !== 'usable') {
            return $rowClass;
        }
        if ($validationClass !== 'usable') {
            return $validationClass;
        }
        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded) && (isset($decoded['error']) || isset($decoded['errors']))) {
                return 'failed';
            }
        }
        if (array_key_exists('readback_verified', $row) && (int)$row['readback_verified'] !== 1) {
            return 'unverified';
        }
        if ($rawData !== '') {
            return 'success';
        }

        $metrics = [
            $row['amount'] ?? 0,
            $row['quantity'] ?? 0,
            $row['book_order_num'] ?? 0,
            $row['data_value'] ?? 0,
            $row['list_exposure'] ?? 0,
            $row['detail_exposure'] ?? 0,
            $row['order_submit_num'] ?? 0,
        ];
        foreach ($metrics as $metric) {
            if ((float)$metric > 0) {
                return 'success';
            }
        }
        return 'empty';
    }

    private function historyStatusLabel(string $status): string
    {
        return [
            'success' => '成功',
            'failed' => '失败',
            'empty' => '数据为空',
            'partial' => '部分数据',
            'unverified' => '未验证',
        ][$status] ?? $status;
    }

    private function isMyHotelHistoryRow(array $row): bool
    {
        if (($row['compare_type'] ?? '') === 'self') {
            return true;
        }
        return trim((string)($row['hotel_name'] ?? '')) === '我的酒店';
    }

    private function buildHistoryBatchNo(array $row, string $rawData, string $platformCode, string $dataType): string
    {
        if (!empty($row['batch_no'])) {
            return (string)$row['batch_no'];
        }

        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded)) {
                foreach (['batch_no', 'batchNo', 'fetch_batch_no', 'fetchBatchNo'] as $key) {
                    if (!empty($decoded[$key])) {
                        return (string)$decoded[$key];
                    }
                }
            }
        }

        $createTime = (string)($row['create_time'] ?? '');
        $updateTime = (string)($row['update_time'] ?? '');
        $fetchTime = strcmp($updateTime, $createTime) > 0 ? $updateTime : $createTime;
        $batchTime = $fetchTime !== '' && strtotime($fetchTime) !== false
            ? date('YmdHis', strtotime($fetchTime))
            : 'unknown';
        return 'B' . $batchTime . '-' . $platformCode . '-' . $dataType;
    }

    private function buildHistoryMetricSummary(array $row, string $rawData): string
    {
        $raw = $rawData !== '' ? json_decode($rawData, true) : [];
        $raw = is_array($raw) ? $raw : [];
        $metrics = [];

        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if ($dataType === 'traffic_forecast') {
            $dataValue = $row['data_value'] ?? null;
            if ($dataValue !== null && $dataValue !== '' && is_numeric($dataValue)) {
                $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
                $label = [
                    'flow_forecast_1' => 'T+1预测',
                    'flow_forecast_2' => 'T+2预测',
                    'flow_forecast_3' => 'T+3预测',
                ][$dimension] ?? '预测值';
                return $label . ' ' . number_format((float)$dataValue, 2);
            }
        }

        if (in_array($dataType, ['review', 'comment', 'comments'], true)) {
            $commentCount = $row['quantity'] ?? $raw['comment_count'] ?? $raw['commentCount'] ?? null;
            if ($commentCount !== null && $commentCount !== '') {
                $metrics[] = '点评 ' . max(0, (int)$commentCount);
            }
            $score = (float)($row['comment_score'] ?? $raw['comment_score'] ?? $raw['commentScore'] ?? 0);
            $metrics[] = $score > 0 ? '评分 ' . number_format($score, 1) : '评分未返回';
            $badReviewCount = $row['data_value'] ?? $raw['bad_review_count'] ?? $raw['badReviewCount'] ?? null;
            if ($badReviewCount !== null && $badReviewCount !== '') {
                $metrics[] = '差评 ' . max(0, (int)$badReviewCount);
            }
            return implode(' / ', $metrics);
        }

        $exposure = (int)($row['list_exposure'] ?? $raw['listExposure'] ?? $raw['exposure'] ?? $raw['exposure_count'] ?? 0);
        if ($exposure > 0) {
            $metrics[] = '曝光 ' . $exposure;
        }

        $views = (int)($row['detail_exposure'] ?? $raw['totalDetailNum'] ?? $raw['detailExposure'] ?? $raw['views'] ?? 0);
        if ($views > 0) {
            $metrics[] = '浏览 ' . $views;
        }

        $orders = (int)($row['book_order_num'] ?? $row['order_submit_num'] ?? $raw['bookOrderNum'] ?? $raw['orderCount'] ?? 0);
        if ($orders > 0) {
            $metrics[] = '订单 ' . $orders;
        }

        $amount = (float)($row['amount'] ?? 0);
        $quantity = (float)($row['quantity'] ?? 0);
        if ($amount > 0 && $quantity > 0) {
            $metrics[] = '均房价 ¥' . number_format($amount / $quantity, 2);
        }

        $rank = $raw['amountRank'] ?? $raw['quantityRank'] ?? $raw['bookOrderNumRank'] ?? $raw['rank'] ?? null;
        if ($rank !== null && $rank !== '') {
            $metrics[] = '排名 ' . $rank;
        }

        if (empty($metrics)) {
            $dataValue = (float)($row['data_value'] ?? 0);
            if ($dataValue > 0) {
                $metrics[] = '指标值 ' . number_format($dataValue, 2);
            }
        }

        return empty($metrics) ? '-' : implode(' / ', $metrics);
    }


    private function buildOnlineHistorySummary(array $historyList): array
    {
        $latestFetchTime = '';
        $today = date('Y-m-d');
        $todayRecords = 0;
        $failedRecords = 0;

        foreach ($historyList as $item) {
            $fetchTime = (string)($item['fetch_time'] ?? '');
            if ($fetchTime !== '' && ($latestFetchTime === '' || strcmp($fetchTime, $latestFetchTime) > 0)) {
                $latestFetchTime = $fetchTime;
            }
            if ($fetchTime !== '' && substr($fetchTime, 0, 10) === $today) {
                $todayRecords++;
            }
            if (($item['status'] ?? '') === 'failed') {
                $failedRecords++;
            }
        }

        return [
            'total_records' => count($historyList),
            'latest_fetch_time' => $latestFetchTime,
            'today_records' => $todayRecords,
            'failed_records' => $failedRecords,
        ];
    }
}
