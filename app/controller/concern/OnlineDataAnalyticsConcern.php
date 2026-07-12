<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineTrafficDataExtractionService;
use think\Response;
use think\facade\Db;

trait OnlineDataAnalyticsConcern
{
    /**
     * 获取酒店列表（用于筛选）- 根据用户权限过滤
     */
    public function hotelList(): Response
    {
        // 从请求中获取当前用户（中间件已注入）
        $currentUser = $this->request->user ?? null;

        // 只检查登录，不强制要求酒店关联
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $dataType = $this->request->get('data_type', '');

            $query = Db::name('online_daily_data')
                ->field('hotel_id, MAX(hotel_name) as hotel_name, MAX(system_hotel_id) as system_hotel_id')
                ->group('hotel_id');

            $this->applyDataTypeFilter($query, $dataType);

            // 非超级管理员只能看自己酒店的数据
            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($permittedHotelIds)) {
                    // 没有酒店关联则返回空列表
                    return $this->success([]);
                }
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }

            $hotels = $this->mergeOnlineDataHotelList($query->select()->toArray());

            // 添加 id 字段用于前端筛选
            foreach ($hotels as &$hotel) {
                $hotel['id'] = $hotel['system_hotel_id'] ?? $hotel['hotel_id'];
            }

            return $this->success($hotels);
        } catch (\Exception $e) {
            return $this->error('获取酒店列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 自动获取并保存数据（每个门店独立运行，每天只获取一次）
     */
    private function mergeOnlineDataHotelList(array $hotels): array
    {
        $merged = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $key = $this->onlineDataHotelKey($hotel);
            if ($key === '') {
                continue;
            }

            $mapKey = is_int($key) ? 'system:' . $key : 'ota:' . $key;
            if (!isset($merged[$mapKey])) {
                $hotel['id'] = $key;
                if (!isset($hotel['ota_hotel_id'])) {
                    $hotel['ota_hotel_id'] = $hotel['hotel_id'] ?? '';
                }
                $merged[$mapKey] = $hotel;
                continue;
            }

            if (empty($merged[$mapKey]['hotel_name']) && !empty($hotel['hotel_name'])) {
                $merged[$mapKey]['hotel_name'] = $hotel['hotel_name'];
            }
        }

        return array_values($merged);
    }

    public function dataAnalysis(): Response
    {
        $this->checkPermission();

        $dimension = $this->request->get('dimension', 'day'); // day, week, month
        $startDate = $this->request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $this->request->get('end_date', date('Y-m-d'));
        $source = trim((string)$this->request->get('source', ''));
        $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));
        $dataType = $this->request->get('data_type', '');

        $query = Db::name('online_daily_data')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);

        // 非超级管理员只能看自己酒店的数据
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
        }
        if ($source !== '') {
            $query->where('source', $source);
        }

        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success(['aggregated' => [], 'summary' => [], 'chart_data' => [], 'hotel_ranking' => []]);
            }
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        $this->applyDataTypeFilter($query, $dataType);

        $data = $query->order('data_date', 'asc')->select()->toArray();

        // 按维度聚合数据
        $aggregated = $this->aggregateByDimension($data, $dimension);

        // 计算汇总统计 - 基于聚合数据
        $totalAmount = array_sum(array_column($aggregated, 'amount'));
        $totalQuantity = array_sum(array_column($aggregated, 'quantity'));
        $totalDataValue = array_sum(array_column($aggregated, 'data_value'));
        $totalOrders = array_sum(array_column($aggregated, 'book_order_num'));
        $periodCount = count($aggregated);

        $validScores = array_filter(array_column($data, 'comment_score'), fn($s) => $s > 0);
        $latestDataDate = '';
        foreach ($data as $row) {
            $rowDate = (string)($row['data_date'] ?? '');
            if ($rowDate !== '' && ($latestDataDate === '' || strcmp($rowDate, $latestDataDate) > 0)) {
                $latestDataDate = $rowDate;
            }
        }
        $summary = [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'total_data_value' => $totalDataValue,
            'total_orders' => $totalOrders,
            'total_record_count' => count($data),
            'avg_score' => count($validScores) > 0 ? array_sum($validScores) / count($validScores) : 0,
            'period_count' => $periodCount, // 维度周期数（天数/周数/月数）
            'hotel_count' => count(array_unique(array_filter(array_map([$this, 'onlineDataHotelKey'], $data), static fn($value): bool => $value !== ''))),
            'avg_amount' => $periodCount > 0 ? $totalAmount / $periodCount : 0, // 平均每周期销售额
            'avg_quantity' => $periodCount > 0 ? $totalQuantity / $periodCount : 0, // 平均每周期房晚数
            'avg_data_value' => $periodCount > 0 ? $totalDataValue / $periodCount : 0, // 平均每周期月间夜
            'latest_data_date' => $latestDataDate,
        ];

        // 图表数据
        $chartData = $this->buildChartData($aggregated, $dimension);

        // 酒店排名 - 按维度聚合
        $hotelRanking = $this->buildHotelRanking($data, $dimension);

        return $this->success([
            'aggregated' => $aggregated,
            'summary' => $summary,
            'chart_data' => $chartData,
            'hotel_ranking' => $hotelRanking,
        ]);
    }

    /**
     * 按维度聚合数据
     */
    private function aggregateByDimension(array $data, string $dimension): array
    {
        $result = [];

        foreach ($data as $item) {
            $date = $item['data_date'];
            $key = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date,
            };

            if (!isset($result[$key])) {
                $result[$key] = [
                    'period' => $key,
                    'amount' => 0,
                    'quantity' => 0,
                    'data_value' => 0,
                    'book_order_num' => 0,
                    'comment_score_sum' => 0,
                    'comment_score_count' => 0,
                    'record_count' => 0,
                ];
            }

            $result[$key]['amount'] += floatval($item['amount']);
            $result[$key]['quantity'] += intval($item['quantity']);
            $result[$key]['data_value'] += floatval($item['data_value'] ?? 0);
            $result[$key]['book_order_num'] += intval($item['book_order_num']);
            if (floatval($item['comment_score']) > 0) {
                $result[$key]['comment_score_sum'] += floatval($item['comment_score']);
                $result[$key]['comment_score_count']++;
            }
            $result[$key]['record_count']++;
        }

        // 计算平均评分
        foreach ($result as &$item) {
            $item['avg_comment_score'] = $item['comment_score_count'] > 0
                ? round($item['comment_score_sum'] / $item['comment_score_count'], 2)
                : 0;
        }

        ksort($result);
        return array_values($result);
    }

    /**
     * 构建图表数据
     */
    private function buildChartData(array $aggregated, string $dimension): array
    {
        $labels = array_column($aggregated, 'period');
        $amounts = array_column($aggregated, 'amount');
        $quantities = array_column($aggregated, 'quantity');
        $orders = array_column($aggregated, 'book_order_num');
        $scores = array_column($aggregated, 'avg_comment_score');

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => '销售额',
                    'data' => array_map('round', $amounts, array_fill(0, count($amounts), 2)),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'yAxisID' => 'y',
                ],
                [
                    'label' => '房晚数',
                    'data' => $quantities,
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => '订单数',
                    'data' => $orders,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'yAxisID' => 'y1',
                ],
            ],
        ];
    }

    /**
     * 构建酒店排名（按维度聚合）
     */
    private function buildHotelRanking(array $data, string $dimension = 'day'): array
    {
        $hotels = [];

        // 仅使用最新周期的数据进行排序（day: 最新日期；week/month: 最新周期）
        $latestKey = '';
        foreach ($data as $item) {
            $date = $item['data_date'] ?? '';
            if (!$date) {
                continue;
            }
            $key = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date,
            };
            if ($key > $latestKey) {
                $latestKey = $key;
            }
        }
        if ($latestKey) {
            $data = array_filter($data, function ($item) use ($latestKey, $dimension) {
                $date = $item['data_date'] ?? '';
                if (!$date) {
                    return false;
                }
                $key = match ($dimension) {
                    'week' => date('Y-W', strtotime($date)),
                    'month' => date('Y-m', strtotime($date)),
                    default => $date,
                };
                return $key === $latestKey;
            });
        }

        foreach ($data as $item) {
            $hotelId = $this->onlineDataHotelKey($item);
            if ($hotelId === '') {
                continue;
            }
            $date = $item['data_date'];

            // 根据维度生成周期key
            $periodKey = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date, // 日维度
            };

            // 使用酒店ID+周期作为唯一key
            $key = $hotelId . '_' . $periodKey;

            if (!isset($hotels[$key])) {
                $hotels[$key] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $item['hotel_name'] ?: '未知酒店',
                    'period' => $periodKey,
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                    'record_count' => 0,
                ];
            }

            $hotels[$key]['amount'] += floatval($item['amount']);
            $hotels[$key]['quantity'] += intval($item['quantity']);
            $hotels[$key]['book_order_num'] += intval($item['book_order_num']);
            $hotels[$key]['record_count']++;
        }

        // 按间夜数排序
        usort($hotels, fn($a, $b) => $b['quantity'] <=> $a['quantity']);

        return array_slice($hotels, 0, 10);
    }

    private function onlineDataHotelKey(array $item)
    {
        $systemHotelId = $item['system_hotel_id'] ?? null;
        if ($systemHotelId !== null && $systemHotelId !== '' && is_numeric($systemHotelId) && (int)$systemHotelId > 0) {
            return (int)$systemHotelId;
        }

        return (string)($item['hotel_id'] ?? '');
    }

    /**
     * 解析并保存流量数据
     */
    private function parseAndSaveTrafficData($responseData, $startDate, $endDate, string $source, ?int $systemHotelId = null, ?string $platform = null, ?string $expectedPlatformHotelId = null): int
    {
        return (new OnlineDailyDataPersistenceService())->parseAndSaveTrafficData(
            $responseData,
            $startDate,
            $endDate,
            $source,
            $systemHotelId,
            $platform,
            $expectedPlatformHotelId
        );
    }
    /**
     * 提取流量数值
     */
    private function extractTrafficValue(array $item): ?float
    {
        return OnlineTrafficDataExtractionService::extractTrafficValue($item);
    }

    /**
     * 解析JSON参数
     */
    private function parseJsonParams(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('额外参数JSON格式不正确');
        }
        return $data;
    }

    /**
     * 递归提取流量数据
     */
    private function extractTrafficData($data): array
    {
        return OnlineTrafficDataExtractionService::extractGenericTrafficRows($data);
    }

    /**
     * 应用数据类型筛选
     */
    private function applyDataTypeFilter($query, ?string $dataType): void
    {
        if (empty($dataType)) {
            return;
        }
        if ($dataType === 'business') {
            $query->where(function ($q) {
                $q->whereNull('data_type')
                    ->whereOr('data_type', '')
                    ->whereOr('data_type', 'business');
            });
            return;
        }
        $query->where('data_type', $dataType);
    }
}
