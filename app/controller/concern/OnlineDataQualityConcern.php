<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OnlineDataFieldFactService;
use think\Response;
use think\facade\Db;

trait OnlineDataQualityConcern
{
    /**
     * 保存线上数据到数据库
     */
    public function saveDailyData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $dataList = $this->request->post('data', []);
        $dataDate = $this->request->post('data_date', date('Y-m-d', strtotime('-1 day')));
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));

        if (empty($dataList)) {
            return $this->error('数据不能为空');
        }

        // 使用统一的解析和保存方法
        $savedCount = $this->parseAndSaveData(['data' => $dataList], $dataDate, $dataDate, $systemHotelId);

        OperationLog::record('online_data', 'save_daily', '保存线上数据: ' . $savedCount . '条', $this->currentUser->id, $systemHotelId);

        return $this->success(['saved_count' => $savedCount], '保存成功，共保存 ' . $savedCount . ' 条数据');
    }

    /**
     * 获取线上数据列表（支持门店隔离）
     */
    public function dailyDataList(): Response
    {
        try {
            // 从请求中获取当前用户（中间件已注入）
            $currentUser = $this->request->user ?? null;

            // 只检查登录，不强制要求酒店关联
            if (!$currentUser) {
                return $this->error('未登录', 401);
            }

            $startDate = $this->request->get('start_date', '');
            $endDate = $this->request->get('end_date', '');
            $source = $this->request->get('source', '');
            $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));  // 系统酒店筛选
            $otaHotelId = trim((string)$this->request->get('ota_hotel_id', '')); // OTA平台酒店ID筛选
            $dataType = $this->request->get('data_type', ''); // 数据类型筛选
            $createStart = $this->request->get('create_start', ''); // 获取开始时间
            $createEnd = $this->request->get('create_end', ''); // 获取结束时间
            $page = max(1, intval($this->request->get('page', 1)));
            $pageSizeInput = $this->request->get('page_size', 30);
            $fetchAllRequested = in_array(strtolower(trim((string)$pageSizeInput)), ['all', '全部'], true)
                || in_array(strtolower(trim((string)$this->request->get('all', ''))), ['1', 'true', 'yes'], true);
            $fetchAll = false;
            $pageSize = min(200, max(1, $fetchAllRequested ? 200 : intval($pageSizeInput))); // 默认30条，禁止全量拉取

            // 简化查询，先不添加复杂的权限过滤
            $query = Db::name('online_daily_data');

            // 按数据日期查询
            if (!empty($startDate) && !empty($endDate)) {
                $query->where('data_date', '>=', $startDate)
                      ->where('data_date', '<=', $endDate);
            }

            // 按来源筛选
            if (!empty($source)) {
                $query->where('source', $source);
            }

            if ($hotelId !== '') {
                $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
            }

            if ($otaHotelId !== '') {
                $query->where('hotel_id', $otaHotelId);
            }

            // 按数据类型筛选
            if (!empty($dataType)) {
                $query->where('data_type', $dataType);
            }

            // 按获取时间筛选（支持单日筛选）
            // 如果只填了一个日期，自动设置为同一天
            if (!empty($createStart) && empty($createEnd)) {
                $createEnd = $createStart; // 单日筛选
            } elseif (empty($createStart) && !empty($createEnd)) {
                $createStart = $createEnd; // 单日筛选
            }

            if (!empty($createStart) && !empty($createEnd)) {
                $query->where('create_time', '>=', $createStart . ' 00:00:00')
                      ->where('create_time', '<=', $createEnd . ' 23:59:59');
            }

            // 非超级管理员只能看自己酒店的数据
            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($permittedHotelIds)) {
                    return $this->success([
                        'list' => [],
                        'pagination' => ['total' => 0, 'page' => $page, 'page_size' => $pageSize],
                        'data_quality_summary' => $this->buildOnlineDataQualitySummary([], [
                            'calculation_scope' => 'current_page',
                            'scope_label' => '当前页样本',
                            'total_records' => 0,
                            'page' => $page,
                            'page_size' => $pageSize,
                            'limited' => false,
                            'all_requested' => $fetchAllRequested,
                        ]),
                    ]);
                }
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }

            $total = (int)(clone $query)->count();
            $listQuery = (clone $query)->order('data_date', 'desc')
                ->order('id', 'desc');
            $listQuery->page($page, $pageSize);
            $list = $listQuery->select()->toArray();

            // 解析 raw_data 添加排名等额外字段
            foreach ($list as &$item) {
                $bookOrderNum = intval($item['book_order_num'] ?? 0);
                $rawTotalOrderNum = 0;

                if (!empty($item['raw_data'])) {
                    $rawData = json_decode($item['raw_data'], true);
                    if ($rawData) {
                        $rawTotalOrderNum = intval($rawData['totalOrderNum'] ?? $rawData['total_order_num'] ?? 0);
                        // 添加排名字段
                        $item['amount_rank'] = $rawData['amountRank'] ?? null;
                        $item['quantity_rank'] = $rawData['quantityRank'] ?? null;
                        $item['book_order_num_rank'] = $rawData['bookOrderNumRank'] ?? null;
                        $item['comment_score_rank'] = $rawData['commentScoreRank'] ?? null;
                        $item['total_detail_num'] = $rawData['totalDetailNum'] ?? $item['total_detail_num'] ?? null;
                        $item['convertion_rate'] = $rawData['convertionRate'] ?? $item['convertion_rate'] ?? null;
                        $item['qunar_comment_score'] = $rawData['qunarCommentScore'] ?? $item['qunar_comment_score'] ?? null;
                        $item['qunar_detail_visitors'] = $rawData['qunarDetailVisitors'] ?? $item['qunar_detail_visitors'] ?? null;
                        $item['qunar_detail_cr'] = $rawData['qunarDetailCR'] ?? $item['qunar_detail_cr'] ?? null;
                    }
                }
                $item['total_order_num'] = $rawTotalOrderNum > 0 ? $rawTotalOrderNum : $bookOrderNum;
                $item['field_fact_status'] = $this->buildOnlineDataFieldFactStatus($item, is_array($rawData ?? null) ? $rawData : []);
                $item['data_quality'] = $this->buildOnlineDataQuality($item);
            }

            return $this->success([
                'list' => $list,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'all' => $fetchAll,
                    'all_requested' => $fetchAllRequested,
                    'limited' => $fetchAllRequested || $total > $pageSize,
                ],
                'data_quality_summary' => $this->buildOnlineDataQualitySummary($list, [
                    'calculation_scope' => 'current_page',
                    'scope_label' => '当前页样本',
                    'total_records' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'limited' => $fetchAllRequested || $total > $pageSize,
                    'all_requested' => $fetchAllRequested,
                ]),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取线上数据列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取数据列表失败', 500);
        }
    }

    private function buildOnlineDataQualitySummary(array $rows, array $scope = []): array
    {
        $checkedRecords = count($rows);
        $issueRecords = 0;
        $missingCount = 0;
        $abnormalCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        $prompts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quality = isset($row['data_quality']) && is_array($row['data_quality'])
                ? $row['data_quality']
                : $this->buildOnlineDataQuality($row);

            if (($quality['status'] ?? 'ok') !== 'ok') {
                $issueRecords++;
            }
            $missingCount += count($quality['missing_metrics'] ?? []);
            $abnormalCount += count($quality['abnormal_metrics'] ?? []);
            $errorCount += (int)($quality['error_count'] ?? 0);
            $warningCount += (int)($quality['warning_count'] ?? 0);
            foreach (($quality['prompts'] ?? []) as $prompt) {
                $prompt = trim((string)$prompt);
                if ($prompt !== '' && !in_array($prompt, $prompts, true)) {
                    $prompts[] = $prompt;
                }
            }
        }

        $status = 'ok';
        if ($errorCount > 0) {
            $status = 'error';
        } elseif ($issueRecords > 0) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'checked_records' => $checkedRecords,
            'sample_size' => $checkedRecords,
            'total_records' => (int)($scope['total_records'] ?? $checkedRecords),
            'calculation_scope' => (string)($scope['calculation_scope'] ?? 'selected_rows'),
            'scope_label' => (string)($scope['scope_label'] ?? '已加载样本'),
            'page' => (int)($scope['page'] ?? 1),
            'page_size' => (int)($scope['page_size'] ?? max(1, $checkedRecords)),
            'limited' => (bool)($scope['limited'] ?? false),
            'all_requested' => (bool)($scope['all_requested'] ?? false),
            'ok_records' => max(0, $checkedRecords - $issueRecords),
            'issue_records' => $issueRecords,
            'missing_count' => $missingCount,
            'abnormal_count' => $abnormalCount,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'top_prompts' => array_slice($prompts, 0, 6),
        ];
    }

    private function buildOnlineDataQuality(array $row): array
    {
        [$raw, $rawError] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if ($dataType === '') {
            $dataType = 'business';
        }
        $isNonNumericFact = $this->isOnlineDataNonNumericFactRow($raw);
        $isRankFact = $dataType === 'ranking'
            || in_array((string)($raw['metric_status'] ?? ''), ['rank_fact'], true);

        $missing = [];
        $abnormal = [];

        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'hotel_id', '酒店ID', ['hotel_id'], ['hotelId', 'hotel_id', 'poiId', 'poi_id']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'hotel_name', '酒店名称', ['hotel_name'], ['hotelName', 'hotel_name', 'poiName', 'poi_name']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'data_date', '数据日期', ['data_date'], ['dataDate', 'data_date', 'date', 'statDate']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'source', '数据来源', ['source'], []);

        if ($source === 'meituan') {
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'data_value', '指标值', ['data_value'], ['dataValue', 'data_value', 'monthRoomNights']);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'dimension', '榜单维度', ['dimension'], ['dimension', 'dimName', '_dimName']);
        } elseif ($isRankFact) {
            // 榜单名次只表示排名事实，不要求营业额/间夜/订单等具体经营值。
        } elseif ($dataType === 'traffic') {
            if (!$isNonNumericFact) {
                $this->addOnlineDataMissingMetric($missing, $row, $raw, 'exposure', '曝光', ['list_exposure', 'exposure_count', 'exposure', 'data_value'], ['listExposure', 'exposure', 'exposure_count']);
                $this->addOnlineDataMissingMetric($missing, $row, $raw, 'detail_visitors', '浏览/访客', ['detail_exposure', 'click_count', 'total_detail_num'], ['detailExposure', 'totalDetailNum', 'views', 'visitorCount']);
            }
        } elseif (!$isNonNumericFact) {
            $requireRaw = !empty($raw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'amount', '营业额', ['amount'], ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount'], $requireRaw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'quantity', '间夜', ['quantity'], ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity'], $requireRaw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'book_order_num', '订单数', ['book_order_num'], ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings'], $requireRaw);
        }

        if ($rawError !== null) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'raw_data_json', 'raw_data', '原始JSON', null, '原始数据无法解析');
        }

        foreach ([
            'amount' => '营业额',
            'quantity' => '间夜',
            'book_order_num' => '订单数',
            'data_value' => '指标值',
        ] as $key => $label) {
            $value = $this->onlineDataQualityNumber($row[$key] ?? null);
            if ($value !== null && $value < 0) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('error', $key . '_negative', $key, $label, $value, $label . '不能为负数');
            }
        }

        $amount = $this->onlineDataQualityFirstNumber($row, $raw, ['amount'], ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount']);
        $quantity = $this->onlineDataQualityFirstNumber($row, $raw, ['quantity'], ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity']);
        $orders = $this->onlineDataQualityFirstNumber($row, $raw, ['book_order_num'], ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings']);

        if ($source !== 'meituan' && $dataType !== 'traffic' && !$isNonNumericFact) {
            if ($amount !== null && $amount > 0 && ($quantity === null || $quantity <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'adr_denominator_zero', 'quantity', '间夜', $quantity, '营业额存在但间夜为0，ADR无法计算');
            }
            if ($quantity !== null && $quantity > 0 && ($amount === null || $amount <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'amount_missing_for_quantity', 'amount', '营业额', $amount, '间夜存在但营业额为0');
            }
            if ($orders !== null && $orders > 0 && ($quantity === null || $quantity <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'orders_without_room_nights', 'book_order_num', '订单数', $orders, '订单数存在但间夜为0');
            }
            if ($amount !== null && $quantity !== null && $quantity > 0) {
                $adr = round($amount / $quantity, 2);
                if ($adr > 5000) {
                    $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'adr_high', 'adr', 'ADR', $adr, 'ADR高于常规阈值');
                }
            }
        }

        foreach ([
            'comment_score' => '点评分',
            'qunar_comment_score' => '去哪儿评分',
        ] as $key => $label) {
            $score = $this->onlineDataQualityNumber($row[$key] ?? null);
            if ($score !== null && ($score < 0 || $score > 5)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'comment_score_range', $key, $label, $score, $label . '应在0到5之间');
            }
        }

        foreach ([
            ['convertion_rate', '浏览转化率', ['convertion_rate'], ['convertionRate', 'conversionRate']],
            ['qunar_detail_cr', '去哪儿转化率', ['qunar_detail_cr'], ['qunarDetailCR', 'qunarDetailConversionRate']],
        ] as [$key, $label, $rowKeys, $rawKeys]) {
            $rate = $this->onlineDataQualityFirstNumber($row, $raw, $rowKeys, $rawKeys);
            if ($rate !== null && ($rate < 0 || $rate > 100)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', $key . '_range', $key, $label, $rate, $label . '应在0到100之间');
            }
        }

        if (!$isNonNumericFact) {
            $this->appendOnlineDataTrafficAnomalies($abnormal, $row, $raw);
        }

        $errorCount = count(array_filter([...$missing, ...$abnormal], static fn($issue): bool => ($issue['level'] ?? '') === 'error'));
        $warningCount = count($missing) + count($abnormal) - $errorCount;
        $status = $errorCount > 0 ? 'error' : ($warningCount > 0 ? 'warning' : 'ok');
        $prompts = $this->buildOnlineDataQualityPrompts($missing, $abnormal);

        return [
            'status' => $status,
            'status_label' => $status === 'ok' ? '完整' : ($status === 'error' ? '异常' : '需复核'),
            'score' => max(0, 100 - count($missing) * 12 - count($abnormal) * 18),
            'missing_metrics' => $missing,
            'abnormal_metrics' => $abnormal,
            'missing_count' => count($missing),
            'abnormal_count' => count($abnormal),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'prompts' => $prompts,
            'summary' => empty($prompts) ? '数据完整' : implode('；', $prompts),
        ];
    }

    private function isOnlineDataNonNumericFactRow(array $raw): bool
    {
        return !empty($raw['fact_only'])
            || in_array((string)($raw['metric_status'] ?? ''), ['non_numeric_fact', 'fact_only'], true);
    }

    private function buildOnlineDataFieldFactStatus(array $row, array $raw): array
    {
        return OnlineDataFieldFactService::buildStatus($row, $raw);
    }

    private function decodeOnlineDataQualityRaw($rawData): array
    {
        if (is_array($rawData)) {
            return [$rawData, null];
        }
        if ($rawData === null || $rawData === '') {
            return [[], null];
        }
        if (!is_string($rawData)) {
            return [[], 'raw_data is not string'];
        }
        $decoded = json_decode($rawData, true);
        if (!is_array($decoded)) {
            return [[], json_last_error_msg()];
        }
        return [$decoded, null];
    }

    private function addOnlineDataMissingMetric(array &$missing, array $row, array $raw, string $key, string $label, array $rowKeys, array $rawKeys, bool $requireRaw = false): void
    {
        if ($this->onlineDataQualityMetricPresent($row, $raw, $rowKeys, $rawKeys, $requireRaw)) {
            return;
        }
        $missing[] = [
            'level' => 'warning',
            'key' => $key,
            'label' => $label,
            'message' => '缺失' . $label,
        ];
    }

    private function onlineDataQualityMetricPresent(array $row, array $raw, array $rowKeys, array $rawKeys, bool $requireRaw = false): bool
    {
        if (!$requireRaw) {
            foreach ($rowKeys as $key) {
                if (array_key_exists($key, $row) && !$this->onlineDataQualityBlank($row[$key])) {
                    return true;
                }
            }
        }
        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $raw) && !$this->onlineDataQualityBlank($raw[$key])) {
                return true;
            }
        }
        return false;
    }

    private function onlineDataQualityBlank($value): bool
    {
        return $value === null || $value === '';
    }

    private function onlineDataQualityNumber($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        if (is_string($value)) {
            $normalized = trim(str_replace([',', '%'], '', $value));
            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }
            return (float)$normalized;
        }
        return null;
    }

    private function onlineDataQualityFirstNumber(array $row, array $raw, array $rowKeys, array $rawKeys): ?float
    {
        foreach ($rowKeys as $key) {
            if (array_key_exists($key, $row)) {
                $value = $this->onlineDataQualityNumber($row[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $value = $this->onlineDataQualityNumber($raw[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function makeOnlineDataAbnormalIssue(string $level, string $code, string $key, string $label, $value, string $message): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'message' => $message,
        ];
    }

    private function appendOnlineDataTrafficAnomalies(array &$abnormal, array $row, array $raw): void
    {
        $exposure = $this->onlineDataQualityFirstNumber($row, $raw, ['list_exposure', 'exposure_count', 'exposure', 'data_value'], ['listExposure', 'exposure', 'exposure_count']);
        $views = $this->onlineDataQualityFirstNumber($row, $raw, ['detail_exposure', 'click_count', 'total_detail_num'], ['detailExposure', 'totalDetailNum', 'views', 'visitorCount']);
        $orderVisitors = $this->onlineDataQualityFirstNumber($row, $raw, ['order_filling_num', 'order_visitors'], ['orderFillingNum', 'order_visitors']);
        $submitUsers = $this->onlineDataQualityFirstNumber($row, $raw, ['order_submit_num', 'submit_users'], ['orderSubmitNum', 'submit_users']);

        if ($exposure !== null && $views !== null && $exposure > 0 && $views > $exposure) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'views_gt_exposure', 'detail_visitors', '浏览/访客', $views, '浏览/访客大于曝光');
        }
        if ($views !== null && $orderVisitors !== null && $views > 0 && $orderVisitors > $views) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'orders_gt_views', 'order_visitors', '订单页访客', $orderVisitors, '订单页访客大于浏览/访客');
        }
        if ($orderVisitors !== null && $submitUsers !== null && $orderVisitors > 0 && $submitUsers > $orderVisitors) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'submit_gt_orders', 'submit_users', '提交用户', $submitUsers, '提交用户大于订单页访客');
        }
    }

    private function buildOnlineDataQualityPrompts(array $missing, array $abnormal): array
    {
        $prompts = [];
        if (!empty($missing)) {
            $labels = array_values(array_unique(array_map(static fn($issue): string => (string)($issue['label'] ?? $issue['key'] ?? ''), $missing)));
            $labels = array_filter($labels, static fn($label): bool => $label !== '');
            $prompts[] = '缺失：' . implode('、', array_slice($labels, 0, 6));
        }
        if (!empty($abnormal)) {
            $messages = array_values(array_unique(array_map(static fn($issue): string => (string)($issue['message'] ?? $issue['label'] ?? ''), $abnormal)));
            $messages = array_filter($messages, static fn($message): bool => $message !== '');
            $prompts[] = '异常：' . implode('、', array_slice($messages, 0, 6));
        }
        return $prompts;
    }

    private function applyOnlineDailyDataHotelFilter($query, string $hotelId): void
    {
        $columns = $this->getOnlineDailyDataColumns();
        if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
            $query->where('system_hotel_id', (int)$hotelId);
            return;
        }

        if (isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelId);
        }
    }
}
