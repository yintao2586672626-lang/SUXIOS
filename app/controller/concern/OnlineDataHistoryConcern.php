<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
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

            $rows = (clone $query)->order('create_time', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();

            $hotelMap = $this->getConfiguredHotelNameMap();
            $historyGroups = $this->mergeOnlineHistoryRows($rows, $hotelMap);
            $total = count($historyGroups);
            $summary = $this->buildOnlineHistorySummary($historyGroups);
            $historyList = array_slice($historyGroups, ($page - 1) * $pageSize, $pageSize);

            return $this->success([
                'list' => $historyList,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'summary' => $summary,
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
     * 携程最近一次成功采集数据
     */
    public function ctripLatest(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $hotelId = trim((string)$this->request->get('hotel_id', ''));
            $sections = [
                'rank' => $this->buildCtripLatestSection('rank', $hotelId, $currentUser),
                'traffic' => $this->buildCtripLatestSection('traffic', $hotelId, $currentUser),
                'review' => $this->buildCtripLatestSection('review', $hotelId, $currentUser),
            ];

            return $this->success([
                'metadata' => $this->buildCtripLatestMetadata($sections, $hotelId),
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

    private function buildCtripLatestSection(string $section, string $hotelId, $currentUser): array
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

        $latest = $this->orderOnlineDataByFetchTime($query, $columns)->find();
        if (!$latest) {
            return $this->emptyCtripLatestSection($section, $labelMap[$section] ?? $section);
        }

        $rowsQuery = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($rowsQuery, $columns);
        $this->applyCtripSectionTypeFilter($rowsQuery, $section, $columns);
        $this->applyCtripHotelScope($rowsQuery, $hotelId, $currentUser, $columns);
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
        $displayTrafficRows = $section === 'traffic' ? CtripTrafficDisplayService::buildCtripTrafficDisplayRows($decodedRows) : [];

        return [
            'data_type' => $section,
            'data_type_label' => $labelMap[$section] ?? $section,
            'data_source' => '携程 ebooking',
            'status' => empty($rows) ? 'empty' : 'success',
            'status_label' => empty($rows) ? '暂无数据' : '成功',
            'data_date' => (string)($latest['data_date'] ?? ''),
            'fetched_at' => $fetchedAt !== '' ? $fetchedAt : $this->onlineRowFetchedAt($latest, $columns),
            'total' => count($rows),
            'rows' => $decodedRows,
            'display_hotels' => $displayHotels,
            'display_summary' => $section === 'rank' ? $this->buildCtripBusinessDisplaySummary($displayHotels) : $this->emptyCtripBusinessDisplaySummary(),
            'display_traffic_rows' => $displayTrafficRows,
            'display_traffic_summary' => $section === 'traffic' ? CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($displayTrafficRows) : CtripTrafficDisplayService::emptyCtripTrafficDisplaySummary(),
        ];
    }

    private function emptyCtripLatestSection(string $section, string $label): array
    {
        return [
            'data_type' => $section,
            'data_type_label' => $label,
            'data_source' => '携程 ebooking',
            'status' => 'empty',
            'status_label' => '暂无数据',
            'data_date' => '',
            'fetched_at' => '',
            'total' => 0,
            'rows' => [],
            'display_hotels' => [],
            'display_summary' => $this->emptyCtripBusinessDisplaySummary(),
            'display_traffic_rows' => [],
            'display_traffic_summary' => CtripTrafficDisplayService::emptyCtripTrafficDisplaySummary(),
        ];
    }

    private function buildCtripLatestMetadata(array $sections, string $hotelId): array
    {
        $fetchedAt = '';
        $dataDate = '';
        $total = 0;
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
        }

        $fetchStatus = $this->getCtripLatestFetchStatus($hotelId);
        if (!empty($fetchStatus['fetched_at']) && ($fetchedAt === '' || strcmp((string)$fetchStatus['fetched_at'], $fetchedAt) >= 0)) {
            $fetchedAt = (string)$fetchStatus['fetched_at'];
            $dataDate = (string)($fetchStatus['data_date'] ?? $dataDate);
            $total = max($total, (int)($fetchStatus['saved_count'] ?? 0));
        }

        return [
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_source' => '携程 ebooking',
            'status' => $total > 0 ? 'success' : 'empty',
            'status_label' => $total > 0 ? '成功' : '暂无成功采集',
            'data_date' => $dataDate,
            'fetched_at' => $fetchedAt,
            'total_records' => $total,
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
                $q->where('data_type', 'business')->whereOr('data_type', '');
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
            'amount' => (float)($row['amount'] ?? 0),
            'quantity' => (int)($row['quantity'] ?? 0),
            'bookOrderNum' => (int)($row['book_order_num'] ?? 0),
            'commentScore' => (float)($row['comment_score'] ?? 0),
            'qunarCommentScore' => (float)($row['qunar_comment_score'] ?? 0),
            'dataValue' => (float)($row['data_value'] ?? 0),
            'listExposure' => (int)($row['list_exposure'] ?? 0),
            'detailExposure' => (int)($row['detail_exposure'] ?? 0),
            'flowRate' => (float)($row['flow_rate'] ?? 0),
            'orderFillingNum' => (int)($row['order_filling_num'] ?? 0),
            'orderSubmitNum' => (int)($row['order_submit_num'] ?? 0),
        ];
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
            if (isset($columns['system_hotel_id'], $columns['compare_type'], $columns['hotel_name'])) {
                $query->where(function ($q) {
                    $q->whereNotNull('system_hotel_id')
                        ->whereOr('compare_type', 'self')
                        ->whereOr('hotel_name', '我的酒店');
                });
            } elseif (isset($columns['system_hotel_id'], $columns['hotel_name'])) {
                $query->where(function ($q) {
                    $q->whereNotNull('system_hotel_id')->whereOr('hotel_name', '我的酒店');
                });
            } elseif (isset($columns['system_hotel_id'])) {
                $query->whereNotNull('system_hotel_id');
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
            '竞对数据' => ['competitor', 'competitor_avg'],
            '竞争圈' => ['competitor', 'competitor_avg'],
            '点评数据' => ['review', 'comment'],
            '广告数据' => ['advertising', 'ad'],
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

        if (isset($columns['create_time'])) {
            $today = date('Y-m-d');
            $latestFetchTime = (string)((clone $query)->max('create_time') ?: '');
            $todayRecords = (int)(clone $query)
                ->where('create_time', '>=', $today . ' 00:00:00')
                ->where('create_time', '<=', $today . ' 23:59:59')
                ->count();
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
        $item['fetch_time'] = (string)($row['create_time'] ?? '');
        $item['data_date'] = (string)($row['data_date'] ?? '');
        $item['platform'] = $platformCode;
        $item['platform_label'] = $this->historyPlatformLabel($platformCode);
        $item['data_type'] = $dataType;
        $item['data_type_label'] = $this->historyDataTypeLabel($dataType);
        $item['hotel_name'] = $displayHotelName;
        $item['original_hotel_name'] = (string)($row['hotel_name'] ?? '');
        $item['hotel_id'] = $systemHotelId;
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
        return implode('|', [
            (string)($item['data_date'] ?? ''),
            (string)($item['platform'] ?? ''),
            (string)($item['data_type'] ?? ''),
            (string)($item['hotel_id'] ?? ''),
            (string)($item['dimension'] ?? ''),
            (string)($item['compare_type'] ?? ''),
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
        }

        $status = (string)($item['status'] ?? '');
        if ($status === 'failed') {
            $group['_failed_count']++;
        } elseif ($status === 'empty') {
            $group['_empty_count']++;
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

        $group['hotel_count'] = $hotelCount;
        $group['merged_hotel_names'] = $hotelNames;
        $group['merged_ota_hotel_ids'] = $otaHotelIds;
        $group['merged_batch_nos'] = $batchNos;

        if ($recordCount > 1 || $rawRecordCount > 1) {
            $group['hotel_name'] = $hotelCount > 1 ? '全部酒店（' . $hotelCount . '家）' : ($hotelNames[0] ?? $group['hotel_name'] ?? '-');
            $group['ota_hotel_id'] = count($otaHotelIds) > 1 ? '多个' : ($otaHotelIds[0] ?? $group['ota_hotel_id'] ?? '');
            $group['metrics_summary'] = $this->buildMergedHistoryMetricSummary($group);
            $group['raw_data'] = $this->buildMergedHistoryRawData($group);
        }

        if ($failedCount > 0) {
            $group['status'] = 'failed';
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
            $group['_success_count']
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
        $value = strtolower((string)$platform);
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
            'competitor' => '竞对数据',
            'review' => '点评数据',
            'advertising' => '广告数据',
        ][$dataType] ?? $dataType;
    }

    private function resolveHistoryStatus(array $row, string $rawData): string
    {
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['failed', 'fail', 'error'], true)) {
            return 'failed';
        }
        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded) && (isset($decoded['error']) || isset($decoded['errors']))) {
                return 'failed';
            }
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
        ][$status] ?? $status;
    }

    private function isMyHotelHistoryRow(array $row): bool
    {
        if (!empty($row['system_hotel_id'])) {
            return true;
        }
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

        $fetchTime = (string)($row['create_time'] ?? '');
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
            if (($item['status'] ?? '') !== 'success') {
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
