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
        $totalAmount = $this->sumNullableAggregateMetric($aggregated, 'amount');
        $totalQuantity = $this->sumNullableAggregateMetric($aggregated, 'quantity');
        $totalDataValue = $this->sumNullableAggregateMetric($aggregated, 'data_value');
        $totalOrders = $this->sumNullableAggregateMetric($aggregated, 'book_order_num');
        $periodCount = count($aggregated);

        $validScores = array_values(array_filter(
            array_column($data, 'comment_score'),
            static fn($score): bool => is_numeric($score) && (float)$score > 0
        ));
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
            'avg_score' => $validScores !== [] ? array_sum($validScores) / count($validScores) : null,
            'period_count' => $periodCount, // 维度周期数（天数/周数/月数）
            'hotel_count' => count(array_unique(array_filter(array_map([$this, 'onlineDataHotelKey'], $data), static fn($value): bool => $value !== ''))),
            'avg_amount' => $this->averageNullableAggregateMetric($aggregated, 'amount', $totalAmount),
            'avg_quantity' => $this->averageNullableAggregateMetric($aggregated, 'quantity', $totalQuantity),
            'avg_data_value' => $this->averageNullableAggregateMetric($aggregated, 'data_value', $totalDataValue),
            'latest_data_date' => $latestDataDate,
        ];
        $summary['data_gaps'] = array_keys(array_filter([
            'total_amount' => $totalAmount === null,
            'total_quantity' => $totalQuantity === null,
            'total_data_value' => $totalDataValue === null,
            'total_orders' => $totalOrders === null,
            'avg_score' => $validScores === [],
        ]));
        $summary['data_status'] = $summary['data_gaps'] === [] ? 'ok' : 'partial';

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
                    'amount' => null,
                    'quantity' => null,
                    'data_value' => null,
                    'book_order_num' => null,
                    'amount_seen_count' => 0,
                    'quantity_seen_count' => 0,
                    'data_value_seen_count' => 0,
                    'book_order_num_seen_count' => 0,
                    'comment_score_sum' => 0,
                    'comment_score_count' => 0,
                    'record_count' => 0,
                ];
            }

            $this->accumulateNullableAggregateMetric($result[$key], $item, 'amount');
            $this->accumulateNullableAggregateMetric($result[$key], $item, 'quantity', true);
            $this->accumulateNullableAggregateMetric($result[$key], $item, 'data_value');
            $this->accumulateNullableAggregateMetric($result[$key], $item, 'book_order_num', true);
            if (is_numeric($item['comment_score'] ?? null) && (float)$item['comment_score'] > 0) {
                $result[$key]['comment_score_sum'] += floatval($item['comment_score']);
                $result[$key]['comment_score_count']++;
            }
            $result[$key]['record_count']++;
        }

        // 计算平均评分
        foreach ($result as &$item) {
            $item['avg_comment_score'] = $item['comment_score_count'] > 0
                ? round($item['comment_score_sum'] / $item['comment_score_count'], 2)
                : null;
            $item['metric_observation_counts'] = [
                'amount' => $item['amount_seen_count'],
                'quantity' => $item['quantity_seen_count'],
                'data_value' => $item['data_value_seen_count'],
                'book_order_num' => $item['book_order_num_seen_count'],
                'comment_score' => $item['comment_score_count'],
            ];
            $item['data_gaps'] = array_keys(array_filter([
                'amount' => $item['amount_seen_count'] === 0,
                'quantity' => $item['quantity_seen_count'] === 0,
                'data_value' => $item['data_value_seen_count'] === 0,
                'book_order_num' => $item['book_order_num_seen_count'] === 0,
                'comment_score' => $item['comment_score_count'] === 0,
            ]));
            $item['data_status'] = $item['data_gaps'] === [] ? 'ok' : 'partial';
            unset(
                $item['amount_seen_count'],
                $item['quantity_seen_count'],
                $item['data_value_seen_count'],
                $item['book_order_num_seen_count']
            );
        }

        ksort($result);
        return array_values($result);
    }

    private function accumulateNullableAggregateMetric(array &$bucket, array $row, string $field, bool $integer = false): void
    {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }
        $seenField = $field . '_seen_count';
        if (($bucket[$seenField] ?? 0) === 0) {
            $bucket[$field] = $integer ? 0 : 0.0;
        }
        $bucket[$field] += $integer ? (int)$value : (float)$value;
        $bucket[$seenField]++;
    }

    private function sumNullableAggregateMetric(array $rows, string $field): int|float|null
    {
        $sum = 0.0;
        $seen = false;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }
            $sum += (float)$value;
            $seen = true;
        }
        return $seen ? $sum : null;
    }

    private function averageNullableAggregateMetric(array $rows, string $field, int|float|null $total): ?float
    {
        if ($total === null) {
            return null;
        }
        $observedPeriods = count(array_filter(
            $rows,
            static fn(array $row): bool => array_key_exists($field, $row)
                && $row[$field] !== null
                && is_numeric($row[$field])
        ));
        return $observedPeriods > 0 ? (float)$total / $observedPeriods : null;
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
                    'data' => array_map(
                        static fn($value): ?float => is_numeric($value) ? round((float)$value, 2) : null,
                        $amounts
                    ),
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
                    'amount' => null,
                    'quantity' => null,
                    'book_order_num' => null,
                    'amount_seen_count' => 0,
                    'quantity_seen_count' => 0,
                    'book_order_num_seen_count' => 0,
                    'record_count' => 0,
                ];
            }

            $this->accumulateNullableAggregateMetric($hotels[$key], $item, 'amount');
            $this->accumulateNullableAggregateMetric($hotels[$key], $item, 'quantity', true);
            $this->accumulateNullableAggregateMetric($hotels[$key], $item, 'book_order_num', true);
            $hotels[$key]['record_count']++;
        }

        foreach ($hotels as &$hotel) {
            $hotel['metric_observation_counts'] = [
                'amount' => $hotel['amount_seen_count'],
                'quantity' => $hotel['quantity_seen_count'],
                'book_order_num' => $hotel['book_order_num_seen_count'],
            ];
            $hotel['data_gaps'] = array_keys(array_filter([
                'amount' => $hotel['amount_seen_count'] === 0,
                'quantity' => $hotel['quantity_seen_count'] === 0,
                'book_order_num' => $hotel['book_order_num_seen_count'] === 0,
            ]));
            $hotel['data_status'] = $hotel['data_gaps'] === [] ? 'ok' : 'partial';
            unset($hotel['amount_seen_count'], $hotel['quantity_seen_count'], $hotel['book_order_num_seen_count']);
        }
        unset($hotel);

        // 按间夜数排序
        usort($hotels, static function (array $left, array $right): int {
            if ($left['quantity'] === null) {
                return $right['quantity'] === null ? 0 : 1;
            }
            if ($right['quantity'] === null) {
                return -1;
            }
            return $right['quantity'] <=> $left['quantity'];
        });

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
