<?php
declare(strict_types=1);

namespace app\controller;

use app\model\DailyReport as DailyReportModel;
use app\model\MonthlyTask;
use app\model\Hotel;
use app\model\OperationLog;
use app\model\SystemConfig;
use think\Response;
use think\facade\Db;

class DailyReport extends Base
{
    private const EXPORT_BATCH_LIMIT = 31;
    private const IMPORT_XLSX_MAX_BYTES = 5 * 1024 * 1024;
    private const IMPORT_XLSX_MAX_ZIP_ENTRIES = 256;
    private const IMPORT_XLSX_MAX_ENTRY_BYTES = 8 * 1024 * 1024;
    private const IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES = 20 * 1024 * 1024;
    private const OTA_CHANNEL_KEYS = ['xb', 'mt', 'fliggy', 'tc', 'dy', 'qn', 'zx', 'booking', 'agoda', 'expedia'];
    private const OTA_EXCEL_CHANNELS = [
        ['key' => 'xb', 'label' => '携程'],
        ['key' => 'mt', 'label' => '美团'],
        ['key' => 'fliggy', 'label' => '飞猪'],
        ['key' => 'tc', 'label' => '同程'],
        ['key' => 'dy', 'label' => '抖音'],
        ['key' => 'qn', 'label' => '去哪儿'],
        ['key' => 'zx', 'label' => '智行'],
        ['key' => 'booking', 'label' => 'Booking.com'],
        ['key' => 'agoda', 'label' => 'Agoda'],
        ['key' => 'expedia', 'label' => 'Expedia'],
    ];

    /**
     * 获取报表配置
     */
    public function config(): Response
    {
        $this->checkPermission();
        
        // 获取日报表配置，按分类分组
        $configs = Db::table('report_configs')
            ->where('report_type', 'daily')
            ->where('status', 1)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
        
        // 按分类分组
        $groupedConfigs = [];
        foreach ($configs as $config) {
            $category = $config['category'];
            if (!isset($groupedConfigs[$category])) {
                $groupedConfigs[$category] = [
                    'category' => $category,
                    'items' => []
                ];
            }
            $groupedConfigs[$category]['items'][] = [
                'id' => $config['id'],
                'field_name' => $config['field_name'],
                'display_name' => $config['display_name'],
                'field_type' => $config['field_type'],
                'unit' => $config['unit'],
                'sort_order' => $config['sort_order'],
                'is_required' => $config['is_required'],
            ];
        }
        
        // 转为索引数组
        $result = array_values($groupedConfigs);
        
        return $this->success($result);
    }

    /**
     * 日报表列表
     */
    public function index(): Response
    {
        $this->checkPermission();

        $pagination = $this->getPagination();
        $hotelId = $this->request->param('hotel_id', '');
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');

        $query = DailyReportModel::with(['hotel', 'submitter'])->order('id', 'desc');

        // 根据权限过滤酒店
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->paginate([], 0, $pagination['page'], $pagination['page_size']);
            }
            if ($hotelId && in_array($hotelId, $permittedHotelIds)) {
                $query->where('hotel_id', $hotelId);
            } else {
                $query->whereIn('hotel_id', $permittedHotelIds);
            }
        } elseif ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('report_date', [$startDate, $endDate]);
        } elseif ($startDate) {
            $query->where('report_date', '>=', $startDate);
        } elseif ($endDate) {
            $query->where('report_date', '<=', $endDate);
        }

        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 日报表详情（基础）
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $report = DailyReportModel::with(['hotel', 'submitter'])->find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($report->hotel_id, 'can_view_report')) {
            return $this->error('无权查看此报表');
        }

        return $this->success($report);
    }

    /**
     * 日报表查看详情（带计算数据）
     */
    public function detail(int $id): Response
    {
        $this->checkPermission();

        $report = DailyReportModel::with(['hotel'])->find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($report->hotel_id, 'can_view_report')) {
            return $this->error('无权查看此报表');
        }

        $hotel = $report->hotel;
        $reportData = $this->normalizeReportData($report->report_data ?? []);
        $reportDate = $report->report_date;
        
        // 获取年月
        $dateParts = explode('-', $reportDate);
        $year = (int)$dateParts[0];
        $month = (int)$dateParts[1];
        $day = (int)$dateParts[2];
        
        // 获取当月天数
        $monthDays = (int)date('t', strtotime($reportDate));
        
        // 获取月任务
        $monthlyTask = MonthlyTask::where('hotel_id', $report->hotel_id)
            ->where('year', $year)
            ->where('month', $month)
            ->find();
        $taskData = $monthlyTask ? ($monthlyTask->task_data ?? []) : [];
        
        // 获取当月所有日报表数据（用于计算月累计）
        $monthReports = DailyReportModel::where('hotel_id', $report->hotel_id)
            ->where('report_date', '>=', "{$year}-{$month}-01")
            ->where('report_date', '<=', $reportDate)
            ->select();
        
        // 计算月累计数据
        $monthSum = $this->calculateMonthSum($monthReports);
        
        // 计算各项指标
        $onlineRowsStatus = null;
        $onlineRows = $this->dailyOtaRows((int)$report->hotel_id, (string)$reportDate, $onlineRowsStatus);
        $result = $this->calculateReportDetail($hotel, $reportData, $taskData, $monthSum, $reportDate, $day, $monthDays, $onlineRows);
        if ($onlineRowsStatus === 'read_failed') {
            $result['ota_channel_supplement']['data_status'] = 'read_failed';
            $result['ota_channel_supplement']['data_error_code'] = 'online_daily_data_read_failed';
        }

        // 超级管理员可查看映射与公式计算结果
        if ($this->currentUser->isSuperAdmin()) {
            $mappingConfig = $this->getViewMappingConfig();
            $mappingValues = $this->calculateViewMappingValues($mappingConfig, $reportData, $taskData, $monthSum, $result);
            $result['view_mapping'] = [
                'config' => $mappingConfig,
                'values' => $mappingValues,
            ];
            $result['view_mapping_sources'] = $this->buildViewMappingSources($reportData, $taskData, $monthSum, $result);
        }
        
        return $this->success($result);
    }
    
    /**
     * 计算月累计数据
     */
    private function calculateMonthSum($reports): array
    {
        $sum = [];
        $fieldCounts = [];
        $reportCount = 0;
        foreach ($reports as $report) {
            $reportCount++;
            $data = $this->normalizeReportData($report->report_data ?? []);
            foreach ($data as $key => $value) {
                $num = $this->normalizeNumber($value);
                if ($num !== null) {
                    if (!array_key_exists($key, $sum)) {
                        $sum[$key] = 0.0;
                    }
                    $sum[$key] += $num;
                    $fieldCounts[$key] = ($fieldCounts[$key] ?? 0) + 1;
                }
            }
        }
        $sum['__evidence'] = [
            'report_count' => $reportCount,
            'field_counts' => $fieldCounts,
        ];
        return $sum;
    }
    
    /**
     * 计算报表详情数据
     */
    private function normalizeReportData($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                try {
                    $array = $value->toArray();
                    return is_array($array) ? $array : [];
                } catch (\Throwable $e) {
                    return [];
                }
            }

            return get_object_vars($value);
        }

        return [];
    }

    private function calculateReportDetail($hotel, $reportData, $taskData, $monthSum, $reportDate, $day, $monthDays, array $onlineRows = []): array
    {
        $reportData = is_array($reportData) ? $reportData : $this->normalizeReportData($reportData);
        $taskData = is_array($taskData) ? $taskData : $this->normalizeReportData($taskData);
        $monthSum = is_array($monthSum) ? $monthSum : $this->normalizeReportData($monthSum);

        $getVal = fn(array $data, string $key): ?float => $this->readNumericValue($data, [$key]);
        $sumRequired = static function (array $values): ?float {
            if ($values === [] || in_array(null, $values, true)) {
                return null;
            }
            return array_sum($values);
        };
        $roundNullable = static fn(?float $value, int $precision = 2): ?float => $value === null ? null : round($value, $precision);
        $intNullable = static fn(?float $value): ?int => $value === null ? null : (int)$value;

        $otaRevenueKeys = array_map(static fn(string $key): string => $key . '_revenue', self::OTA_CHANNEL_KEYS);
        $otaRoomKeys = array_map(static fn(string $key): string => $key . '_rooms', self::OTA_CHANNEL_KEYS);
        $offlineRevenueKeys = ['walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue', 'wechat_revenue', 'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue'];
        $offlineRoomKeys = ['walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms', 'free_rooms', 'gold_card_rooms', 'black_gold_rooms', 'hourly_rooms'];
        $otherRevenueKeys = ['parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue'];
        $valuesFor = static function (array $data, array $keys) use ($getVal): array {
            return array_map(static fn(string $key): ?float => $getVal($data, $key), $keys);
        };
        $monthEvidence = is_array($monthSum['__evidence'] ?? null) ? $monthSum['__evidence'] : null;
        $monthReportCount = (int)($monthEvidence['report_count'] ?? 0);
        $monthFieldCounts = is_array($monthEvidence['field_counts'] ?? null) ? $monthEvidence['field_counts'] : [];
        $monthGetVal = function (string $key) use ($monthEvidence, $monthReportCount, $monthFieldCounts, $getVal, $monthSum): ?float {
            if ($monthEvidence !== null && ($monthReportCount <= 0 || (int)($monthFieldCounts[$key] ?? 0) !== $monthReportCount)) {
                return null;
            }
            return $getVal($monthSum, $key);
        };
        $monthValuesFor = static function (array $keys) use ($monthGetVal): array {
            return array_map(static fn(string $key): ?float => $monthGetVal($key), $keys);
        };

        $totalRooms = $this->resolveSalableRoomsTotal($hotel, $taskData, $reportData);
        $salableRooms = $getVal($reportData, 'salable_rooms') ?? $totalRooms;
        $hourlyRevenue = $getVal($reportData, 'hourly_revenue');
        $hourlyRooms = $getVal($reportData, 'hourly_rooms');
        $overnightRooms = $getVal($reportData, 'overnight_rooms');

        $dayOnlineRevenue = $getVal($reportData, 'online_revenue') ?? $sumRequired($valuesFor($reportData, $otaRevenueKeys));
        $dayOfflineRevenue = $getVal($reportData, 'offline_revenue') ?? $sumRequired($valuesFor($reportData, $offlineRevenueKeys));
        $dayOtherRevenue = $getVal($reportData, 'other_revenue_total') ?? $sumRequired($valuesFor($reportData, $otherRevenueKeys));
        $dayRoomRevenue = $getVal($reportData, 'room_revenue') ?? $sumRequired([$dayOnlineRevenue, $dayOfflineRevenue]);
        $dayTotalRevenue = $this->readNumericValue($reportData, ['revenue', 'day_revenue'])
            ?? $sumRequired([$dayRoomRevenue, $dayOtherRevenue]);

        $dayOnlineRooms = $getVal($reportData, 'online_rooms') ?? $sumRequired($valuesFor($reportData, $otaRoomKeys));
        $dayOfflineRooms = $getVal($reportData, 'offline_rooms') ?? $sumRequired($valuesFor($reportData, $offlineRoomKeys));
        $dayTotalRooms = $this->readNumericValue($reportData, ['total_rooms', 'day_total_rooms'])
            ?? $sumRequired([$dayOnlineRooms, $dayOfflineRooms]);

        $dayOvernightRooms = $overnightRooms;
        $dayNonOvernightRooms = $sumRequired([$dayTotalRooms, $dayOvernightRooms, $hourlyRooms]);
        if ($dayNonOvernightRooms !== null) {
            $dayNonOvernightRooms = max(0.0, $dayTotalRooms - $dayOvernightRooms - $hourlyRooms);
        }

        $dayOccRate = $dayTotalRooms !== null && $salableRooms !== null && $salableRooms > 0
            ? round(($dayTotalRooms / $salableRooms) * 100, 2)
            : null;
        $dayOvernightOccRate = $dayOvernightRooms !== null && $salableRooms !== null && $salableRooms > 0
            ? round(($dayOvernightRooms / $salableRooms) * 100, 2)
            : null;
        $dayAdr = $dayRoomRevenue !== null && $dayTotalRooms !== null && $dayTotalRooms > 0
            ? round($dayRoomRevenue / $dayTotalRooms, 2)
            : null;
        $dayRevpar = $dayRoomRevenue !== null && $salableRooms !== null && $salableRooms > 0
            ? round($dayRoomRevenue / $salableRooms, 2)
            : null;
        $overnightAdr = $dayRoomRevenue !== null && $hourlyRevenue !== null && $dayOvernightRooms !== null && $dayOvernightRooms > 0
            ? round(($dayRoomRevenue - $hourlyRevenue) / $dayOvernightRooms, 2)
            : null;

        $otaRoomValues = $valuesFor($reportData, $otaRoomKeys);
        $otaTotalRooms = $sumRequired($otaRoomValues);
        $dayGoodReview = $sumRequired($valuesFor($reportData, ['xb_good_review', 'mt_good_review', 'fliggy_good_review']));
        $dayBadReview = $sumRequired($valuesFor($reportData, ['xb_bad_review', 'mt_bad_review', 'fliggy_bad_review']));

        $monthOnlineRevenue = $monthGetVal('online_revenue') ?? $sumRequired($monthValuesFor($otaRevenueKeys));
        $monthOfflineRevenue = $monthGetVal('offline_revenue') ?? $sumRequired($monthValuesFor($offlineRevenueKeys));
        $monthOtherRevenue = $monthGetVal('other_revenue_total') ?? $sumRequired($monthValuesFor($otherRevenueKeys));
        $monthRoomRevenue = $monthGetVal('room_revenue') ?? $sumRequired([$monthOnlineRevenue, $monthOfflineRevenue]);
        $monthTotalRevenue = $monthGetVal('revenue') ?? $monthGetVal('day_revenue')
            ?? $sumRequired([$monthRoomRevenue, $monthOtherRevenue]);

        $monthOnlineRooms = $monthGetVal('online_rooms') ?? $sumRequired($monthValuesFor($otaRoomKeys));
        $monthOfflineRooms = $monthGetVal('offline_rooms') ?? $sumRequired($monthValuesFor($offlineRoomKeys));
        $monthTotalRooms = $monthGetVal('total_rooms') ?? $sumRequired([$monthOnlineRooms, $monthOfflineRooms]);
        $monthSalableRoomNights = $monthGetVal('salable_rooms');

        $monthOccRate = $monthTotalRooms !== null && $monthSalableRoomNights !== null && $monthSalableRoomNights > 0
            ? round(($monthTotalRooms / $monthSalableRoomNights) * 100, 2)
            : null;
        $monthAdr = $monthRoomRevenue !== null && $monthTotalRooms !== null && $monthTotalRooms > 0
            ? round($monthRoomRevenue / $monthTotalRooms, 2)
            : null;
        $monthRevpar = $monthRoomRevenue !== null && $monthSalableRoomNights !== null && $monthSalableRoomNights > 0
            ? round($monthRoomRevenue / $monthSalableRoomNights, 2)
            : null;

        $monthRevenueTarget = $this->readNumericValue($taskData, ['revenue_budget', 'revenue_target']);
        $monthNewMemberTarget = $getVal($taskData, 'new_members');
        $monthWechatTarget = $getVal($taskData, 'wechat_new_friends');
        $monthCompleteRate = $monthTotalRevenue !== null && $monthRevenueTarget !== null && $monthRevenueTarget > 0
            ? round(($monthTotalRevenue / $monthRevenueTarget) * 100, 2)
            : null;
        $monthRevenueDiff = $monthTotalRevenue !== null && $monthRevenueTarget !== null
            ? $monthTotalRevenue - $monthRevenueTarget
            : null;
        $dayRevenueTarget = $monthRevenueTarget !== null && $monthDays > 0
            ? round($monthRevenueTarget / $monthDays, 2)
            : null;
        $dayRevenueDiff = $dayTotalRevenue !== null && $dayRevenueTarget !== null
            ? $dayTotalRevenue - $dayRevenueTarget
            : null;

        $wechatAdd = $getVal($reportData, 'wechat_add');
        $memberCardSold = $getVal($reportData, 'member_card_sold');
        $privateRevenue = $getVal($reportData, 'private_revenue');
        $privateRooms = $getVal($reportData, 'private_rooms');
        $storedValue = $getVal($reportData, 'stored_value');
        $cashIncome = $getVal($reportData, 'cash_income');
        $tomorrowBooking = $getVal($reportData, 'tomorrow_booking');
        $protocolRooms = $getVal($reportData, 'protocol_rooms');
        $walkinRooms = $getVal($reportData, 'walkin_rooms');
        $groupRooms = $getVal($reportData, 'group_rooms');
        $wechatRooms = $getVal($reportData, 'wechat_rooms');
        $dyRooms = $getVal($reportData, 'dy_rooms');
        $memberExpRooms = $getVal($reportData, 'member_exp_rooms');
        $webExpRooms = $getVal($reportData, 'web_exp_rooms');
        $freeRooms = $getVal($reportData, 'free_rooms');
        $monthNewMembers = $monthGetVal('member_card_sold');
        $monthWechatAdd = $monthGetVal('wechat_add');
        $monthPrivateRevenue = $monthGetVal('private_revenue');
        $monthPrivateRooms = $monthGetVal('private_rooms');
        $monthStoredValue = $monthGetVal('stored_value');
        $privateRate = $monthPrivateRevenue !== null && $monthTotalRevenue !== null && $monthTotalRevenue > 0
            ? round(($monthPrivateRevenue / $monthTotalRevenue) * 100, 2)
            : null;
        $monthGoodReview = $sumRequired($monthValuesFor(['xb_good_review', 'mt_good_review', 'fliggy_good_review']));
        $monthBadReview = $sumRequired($monthValuesFor(['xb_bad_review', 'mt_bad_review', 'fliggy_bad_review']));
        $monthFreeRooms = $monthGetVal('free_rooms');
        $monthCashIncome = $monthGetVal('cash_income');

        $dataGaps = [];
        $addGap = static function (string $code, string $message, array $metrics) use (&$dataGaps): void {
            $dataGaps[] = ['code' => $code, 'message' => $message, 'metrics' => $metrics];
        };
        if ($dayTotalRevenue === null) {
            $addGap('daily_total_revenue_missing', '日报未提供全酒店总收入，且房费与其他收入证据不完整，未计算当日营收。', ['day_revenue', 'day_revenue_diff']);
        }
        if ($dayRoomRevenue === null) {
            $addGap('daily_room_revenue_missing', '日报未提供房费收入合计，且线上、线下房费证据不完整。', ['day_room_revenue', 'day_adr', 'day_revpar']);
        }
        if ($dayTotalRooms === null) {
            $addGap('daily_sold_room_nights_missing', '日报未提供总出租间夜，且线上、线下间夜证据不完整。', ['day_total_rooms', 'day_occ_rate', 'day_adr']);
        } elseif ($dayTotalRooms <= 0) {
            $addGap('daily_sold_room_nights_not_positive', '当日总出租间夜不大于 0，ADR 无定义，不以 0 代替。', ['day_adr']);
        }
        if ($salableRooms === null) {
            $addGap('daily_salable_rooms_missing', '缺少大于 0 的当日可售房间数，OCC 和 RevPAR 不可计算。', ['day_occ_rate', 'day_overnight_occ_rate', 'day_revpar']);
        } elseif ($salableRooms <= 0) {
            $addGap('daily_salable_rooms_not_positive', '当日可售房间数不大于 0，OCC 和 RevPAR 无定义。', ['day_occ_rate', 'day_overnight_occ_rate', 'day_revpar']);
        }
        if ($monthSalableRoomNights === null) {
            $addGap('monthly_salable_room_nights_missing', '缺少月累计可售房夜证据，不使用固定房量乘天数代替。', ['month_occ_rate', 'month_revpar']);
        } elseif ($monthSalableRoomNights <= 0) {
            $addGap('monthly_salable_room_nights_not_positive', '月累计可售房夜不大于 0，月 OCC 和 RevPAR 无定义。', ['month_occ_rate', 'month_revpar']);
        }
        if ($monthTotalRevenue === null) {
            $addGap('monthly_total_revenue_incomplete', '纳入月累计的日报未逐日提供完整营收证据，未生成月营收及目标完成结论。', ['month_revenue', 'month_complete_rate', 'month_revenue_diff']);
        }
        if ($monthRoomRevenue === null) {
            $addGap('monthly_room_revenue_incomplete', '月累计房费收入证据不完整，未计算月 ADR 和 RevPAR。', ['month_room_revenue', 'month_adr', 'month_revpar']);
        }
        if ($monthTotalRooms === null) {
            $addGap('monthly_sold_room_nights_incomplete', '月累计出租间夜证据不完整，未计算月 OCC 和 ADR。', ['month_occ_rate', 'month_adr']);
        } elseif ($monthTotalRooms <= 0) {
            $addGap('monthly_sold_room_nights_not_positive', '月累计出租间夜不大于 0，月 ADR 无定义，不以 0 代替。', ['month_adr']);
        }
        if ($monthRevenueTarget === null) {
            $addGap('monthly_revenue_target_missing', '未设置月营收目标，不生成完成率或目标差额。', ['month_complete_rate', 'month_revenue_diff', 'day_revenue_target', 'day_revenue_diff']);
        } elseif ($monthRevenueTarget <= 0) {
            $addGap('monthly_revenue_target_not_positive', '月营收目标不大于 0，完成率无定义，不以 0% 代替。', ['month_complete_rate']);
        }

        $coreMetricsReady = $dayTotalRevenue !== null
            && $dayRoomRevenue !== null
            && $dayTotalRooms !== null
            && $dayOccRate !== null
            && $dayAdr !== null
            && $dayRevpar !== null;
        $metricStatus = [];
        foreach ([
            'day_revenue' => $dayTotalRevenue,
            'day_room_revenue' => $dayRoomRevenue,
            'day_total_rooms' => $dayTotalRooms,
            'day_occ_rate' => $dayOccRate,
            'day_adr' => $dayAdr,
            'day_revpar' => $dayRevpar,
            'month_revenue' => $monthTotalRevenue,
            'month_occ_rate' => $monthOccRate,
            'month_adr' => $monthAdr,
            'month_revpar' => $monthRevpar,
            'month_complete_rate' => $monthCompleteRate,
        ] as $metric => $value) {
            $metricStatus[$metric] = [
                'status' => $value === null ? 'data_gap' : 'ready',
                'scope' => 'whole_hotel_daily_report',
            ];
        }

        $maintenanceRooms = $totalRooms !== null && $salableRooms !== null && $totalRooms >= $salableRooms
            ? $totalRooms - $salableRooms
            : null;

        return [
            'hotel_name' => is_object($hotel) ? ($hotel->name ?? null) : null,
            'report_date' => $reportDate,
            'total_rooms' => $totalRooms,
            'salable_rooms' => $salableRooms,
            'maintenance_rooms' => $maintenanceRooms,
            
            // 一、销售业绩
            'month_revenue_target' => $monthRevenueTarget,
            'month_revenue' => $roundNullable($monthTotalRevenue),
            'month_room_revenue' => $roundNullable($monthRoomRevenue),
            'month_other_revenue' => $roundNullable($monthOtherRevenue),
            'month_revenue_diff' => $roundNullable($monthRevenueDiff),
            'month_complete_rate' => $monthCompleteRate,
            
            'day_revenue_target' => $dayRevenueTarget,
            'day_revenue' => $roundNullable($dayTotalRevenue),
            'day_room_revenue' => $roundNullable($dayRoomRevenue),
            'day_other_revenue' => $roundNullable($dayOtherRevenue),
            'day_revenue_diff' => $roundNullable($dayRevenueDiff),
            
            'month_occ_rate' => $monthOccRate,
            'day_occ_rate' => $dayOccRate,
            'day_overnight_occ_rate' => $dayOvernightOccRate,
            
            'day_total_rooms' => $intNullable($dayTotalRooms),
            'day_overnight_rooms' => $intNullable($dayOvernightRooms),
            'day_non_overnight_rooms' => $intNullable($dayNonOvernightRooms),
            'day_hourly_rooms' => $intNullable($hourlyRooms),
            
            'month_adr' => $monthAdr,
            'day_adr' => $dayAdr,
            'overnight_adr' => $overnightAdr,
            
            'month_revpar' => $monthRevpar,
            'day_revpar' => $dayRevpar,
            
            'day_stored_value' => $storedValue,
            'month_stored_value' => $monthStoredValue,
            
            // 二、客源结构
            'member_count' => $intNullable($memberCardSold),
            'protocol_count' => $intNullable($protocolRooms),
            'walkin_count' => $intNullable($walkinRooms),
            'group_count' => $intNullable($groupRooms),
            'ota_total_rooms' => $intNullable($otaTotalRooms),
            'xb_rooms' => $intNullable($getVal($reportData, 'xb_rooms')),
            'mt_rooms' => $intNullable($getVal($reportData, 'mt_rooms')),
            'tc_rooms' => $intNullable($getVal($reportData, 'tc_rooms')),
            'qn_rooms' => $intNullable($getVal($reportData, 'qn_rooms')),
            'zx_rooms' => $intNullable($getVal($reportData, 'zx_rooms')),
            'fliggy_rooms' => $intNullable($getVal($reportData, 'fliggy_rooms')),
            'booking_rooms' => $intNullable($getVal($reportData, 'booking_rooms')),
            'agoda_rooms' => $intNullable($getVal($reportData, 'agoda_rooms')),
            'expedia_rooms' => $intNullable($getVal($reportData, 'expedia_rooms')),
            'wechat_count' => $intNullable($wechatRooms),
            'dy_count' => $intNullable($dyRooms),
            'member_exp_rooms' => $intNullable($memberExpRooms),
            'web_exp_rooms' => $intNullable($webExpRooms),
            'free_rooms' => $intNullable($freeRooms),
            
            // 三、直销指标
            'day_new_member' => $intNullable($memberCardSold),
            'month_new_member_target' => $monthNewMemberTarget,
            'month_new_member' => $intNullable($monthNewMembers),
            'month_member_diff' => $monthNewMembers !== null && $monthNewMemberTarget !== null ? $monthNewMembers - $monthNewMemberTarget : null,
            
            'day_wechat_add' => $intNullable($wechatAdd),
            'month_wechat_target' => $monthWechatTarget,
            'month_wechat_add' => $intNullable($monthWechatAdd),
            'month_wechat_diff' => $monthWechatAdd !== null && $monthWechatTarget !== null ? $monthWechatAdd - $monthWechatTarget : null,
            
            'day_private_rooms' => $intNullable($privateRooms),
            'day_private_revenue' => $privateRevenue,
            'month_private_rooms' => $intNullable($monthPrivateRooms),
            'month_private_revenue' => $monthPrivateRevenue,
            'private_rate' => $privateRate,
            
            // 四、OTA点评
            'day_good_review' => $intNullable($dayGoodReview),
            'day_bad_review' => $intNullable($dayBadReview),
            'month_good_review' => $intNullable($monthGoodReview),
            'month_bad_review' => $intNullable($monthBadReview),
            
            // 五、免费房
            'month_free_rooms' => $intNullable($monthFreeRooms),
            
            // 六、明日预订
            'tomorrow_booking' => $intNullable($tomorrowBooking),
            
            // 七、今日现金
            'day_cash_income' => $cashIncome,
            'month_cash_income' => $monthCashIncome,

            'metric_scope' => 'whole_hotel_daily_report',
            'data_status' => $coreMetricsReady ? 'ready' : ($reportData === [] ? 'missing' : 'partial'),
            'core_metrics_ready' => $coreMetricsReady,
            'data_notice' => '经营指标仅基于 daily_reports 中已填报的全酒店经营字段；OTA 补充摘要保持 ota_channel 口径，不参与全酒店营收、OCC、ADR 或 RevPAR 推导。',
            'metric_status' => $metricStatus,
            'data_gaps' => $dataGaps,
            
            // 原始数据
            'raw_data' => $reportData,
            'ota_channel_supplement' => $this->buildDailyOtaSupplementSummary($onlineRows),
        ];
    }

    private function dailyOtaRows(int $hotelId, string $reportDate, ?string &$readStatus = null): array
    {
        $readStatus = 'pending';
        if ($hotelId <= 0 || $reportDate === '') {
            return [];
        }

        $tenantId = $this->tenantIdForHotel($hotelId);
        if ($tenantId <= 0) {
            $readStatus = 'scope_missing';
            return [];
        }

        try {
            $rows = Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $reportDate)
                ->select()
                ->toArray();
            $readStatus = 'ok';
            return $rows;
        } catch (\Throwable $e) {
            $readStatus = 'read_failed';
            return [];
        }
    }

    private function buildDailyOtaSupplementSummary(array $onlineRows): array
    {
        $advertising = $this->buildDailyOtaAdvertisingSummary($onlineRows);
        $serviceQuality = $this->buildDailyOtaServiceQualitySummary($onlineRows);
        $statuses = [(string)($advertising['data_status'] ?? ''), (string)($serviceQuality['data_status'] ?? '')];
        $readyCount = count(array_filter($statuses, static fn(string $status): bool => $status === 'ok'));
        $hasPartialData = in_array('partial', $statuses, true);
        $dataStatus = $readyCount === count($statuses)
            ? 'ok'
            : (($readyCount > 0 || $hasPartialData) ? 'partial' : 'pending');

        return [
            'scope' => 'ota_channel',
            'source_table' => 'online_daily_data',
            'data_status' => $dataStatus,
            'data_notice' => 'ota_channel_only_not_whole_hotel_scope',
            'advertising' => $advertising,
            'service_quality' => $serviceQuality,
        ];
    }

    private function buildDailyOtaAdvertisingSummary(array $onlineRows): array
    {
        $summary = [
            'spend' => null,
            'order_amount' => null,
            'bookings' => null,
            'room_nights' => null,
            'impressions' => null,
            'clicks' => null,
            'ctr' => null,
            'cvr' => null,
            'roas' => null,
            'sample_count' => 0,
            'data_status' => 'pending',
            'data_gaps' => [],
        ];
        $totals = ['spend' => 0.0, 'order_amount' => 0.0, 'bookings' => 0.0, 'room_nights' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0];
        $evidenceCounts = array_fill_keys(array_keys($totals), 0);

        foreach ($onlineRows as $row) {
            if ($this->normalizeDailyOtaDataType((string)($row['data_type'] ?? '')) !== 'advertising') {
                continue;
            }

            $raw = $this->dailyOtaRawDetail($this->decodeDailyOtaRawData($row['raw_data'] ?? []));
            $values = [
                'spend' => $this->firstDailyOtaNumber($row, $raw, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']),
                'order_amount' => $this->firstDailyOtaNumber($row, $raw, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']),
                'impressions' => $this->firstDailyOtaNumber($row, $raw, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']),
                'clicks' => $this->firstDailyOtaNumber($row, $raw, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']),
                'bookings' => $this->firstDailyOtaNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']),
                'room_nights' => $this->firstDailyOtaNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'nights']),
            ];
            $hasRowEvidence = false;
            foreach ($values as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $hasRowEvidence = true;
                $totals[$key] += $value;
                $evidenceCounts[$key]++;
            }
            if ($hasRowEvidence) {
                $summary['sample_count']++;
            }
        }

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        foreach ($totals as $key => $value) {
            if ($evidenceCounts[$key] <= 0) {
                $summary['data_gaps'][] = 'advertising_' . $key . '_missing';
                continue;
            }
            $summary[$key] = in_array($key, ['bookings', 'impressions', 'clicks'], true)
                ? (int)round($value)
                : round($value, 2);
        }
        $summary['ctr'] = $summary['impressions'] !== null && $summary['clicks'] !== null && $summary['impressions'] > 0
            ? round($summary['clicks'] / $summary['impressions'] * 100, 2)
            : null;
        $summary['cvr'] = $summary['clicks'] !== null && $summary['bookings'] !== null && $summary['clicks'] > 0
            ? round($summary['bookings'] / $summary['clicks'] * 100, 2)
            : null;
        $summary['roas'] = $summary['spend'] !== null && $summary['order_amount'] !== null && $summary['spend'] > 0
            ? round($summary['order_amount'] / $summary['spend'], 2)
            : null;
        $summary['data_status'] = $summary['data_gaps'] === [] ? 'ok' : 'partial';

        return $summary;
    }

    private function buildDailyOtaServiceQualitySummary(array $onlineRows): array
    {
        $summary = [
            'avg_psi_score' => null,
            'avg_service_score' => null,
            'sample_count' => 0,
            'data_status' => 'pending',
            'data_gaps' => [],
        ];
        $psiScores = [];
        $serviceScores = [];

        foreach ($onlineRows as $row) {
            if (!in_array($this->normalizeDailyOtaDataType((string)($row['data_type'] ?? '')), ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }

            $raw = $this->dailyOtaRawDetail($this->decodeDailyOtaRawData($row['raw_data'] ?? []));
            $psi = $this->firstDailyOtaNumber($row, $raw, ['data_value', 'dataValue', 'psi_score', 'psiScore', 'psi', 'PSI', 'serviceQualityScore', 'qualityScore']);
            $serviceScore = $this->firstDailyOtaNumber($row, $raw, ['service_score', 'serviceScore', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null) {
                $psiScores[] = $psi;
            }
            if ($serviceScore !== null) {
                $serviceScores[] = $serviceScore;
            }
            if ($psi !== null || $serviceScore !== null) {
                $summary['sample_count']++;
            }
        }

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        $summary['avg_psi_score'] = $this->avgDailyOtaNumbers($psiScores);
        $summary['avg_service_score'] = $this->avgDailyOtaNumbers($serviceScores);
        if ($summary['avg_psi_score'] === null) {
            $summary['data_gaps'][] = 'quality_psi_score_missing';
        }
        if ($summary['avg_service_score'] === null) {
            $summary['data_gaps'][] = 'quality_service_score_missing';
        }
        $summary['data_status'] = $summary['data_gaps'] === [] ? 'ok' : 'partial';

        return $summary;
    }

    private function normalizeDailyOtaDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return $value;
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        return $value;
    }

    private function decodeDailyOtaRawData($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return get_object_vars($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function dailyOtaRawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? array_merge($raw, $raw['row']) : $raw;
    }

    private function firstDailyOtaNumber(array $row, array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                if (!array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
                    continue;
                }
                $num = $this->normalizeNumber($source[$key]);
                if ($num !== null) {
                    return $num;
                }
            }
        }

        return null;
    }

    private function avgDailyOtaNumbers(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn($value): bool => is_numeric($value)));
        if (empty($values)) {
            return null;
        }

        return round(array_sum(array_map('floatval', $values)) / count($values), 2);
    }

    /**
     * 创建日报表
     */
    public function create(): Response
    {
        $this->checkPermission();

        $data = $this->requestData();

        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'report_date' => 'require|date',
        ], [
            'hotel_id.require' => '请选择酒店',
            'report_date.require' => '请选择日期',
        ]);

        $hotelId = (int)$data['hotel_id'];
        $reportDate = $data['report_date'];

        $this->currentUser->hasHotelPermissionOrFail(
            $hotelId,
            'can_fill_daily_report',
            '您没有该酒店的日报填写权限'
        );

        // 验证日期：只能填写昨天及之前的日期
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($reportDate > $yesterday) {
            return $this->error('只能填写昨天及之前的日报');
        }

        // 验证月任务：当月必须有月任务才能填写
        $dateParts = explode('-', $reportDate);
        $year = (int)$dateParts[0];
        $month = (int)$dateParts[1];
        $tenantId = $this->tenantIdForHotel($hotelId);
        if ($tenantId <= 0) {
            return $this->error('选择的门店缺少有效租户归属');
        }

        $monthlyTask = MonthlyTask::runInTenantScope(
            $tenantId,
            static fn() => MonthlyTask::where('hotel_id', $hotelId)
                ->where('year', $year)
                ->where('month', $month)
                ->find()
        );
        
        if (!$monthlyTask) {
            return $this->error("请先添加 {$year}年{$month}月 的月任务，再填写日报");
        }

        // 检查是否已存在
        $exists = DailyReportModel::runInTenantScope(
            $tenantId,
            static fn() => DailyReportModel::where('hotel_id', $hotelId)
                ->where('report_date', $reportDate)
                ->find()
        );
        if ($exists) {
            return $this->error('该日期的报表已存在，请直接编辑');
        }

        // 提取报表数据（排除非报表字段）
        $reportData = $this->extractReportData($data);

        $report = new DailyReportModel();
        $report->hotel_id = $hotelId;
        if ($this->dailyReportsHasColumn('tenant_id')) {
            $report->tenant_id = $tenantId;
        }
        $report->report_date = $reportDate;
        $report->report_data = $reportData;
        $report->submitter_id = $this->currentUser->id;
        $report->status = $data['status'] ?? DailyReportModel::STATUS_SUBMITTED;
        $report->save();

        OperationLog::record('daily_report', 'create', '创建日报表: ' . $report->report_date, $this->currentUser->id, $hotelId);

        return $this->success($report, '创建成功');
    }

    /**
     * 更新日报表
     */
    public function update(int $id): Response
    {
        $this->checkPermission();

        $report = DailyReportModel::find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        $this->currentUser->hasHotelPermissionOrFail((int)$report->hotel_id, 'can_edit_report', '无权编辑此报表');

        $data = $this->requestData();

        // 提取报表数据
        $reportData = $this->extractReportData($data);
        
        $report->report_data = $reportData;
        
        if (isset($data['status'])) {
            $report->status = $data['status'];
        }
        $report->save();

        OperationLog::record('daily_report', 'update', '更新日报表: ' . $report->report_date, $this->currentUser->id, $report->hotel_id);

        return $this->success($report, '更新成功');
    }

    /**
     * 删除日报表
     */
    public function delete(int $id): Response
    {
        $this->checkPermission();

        $report = DailyReportModel::find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        $this->currentUser->hasHotelPermissionOrFail((int)$report->hotel_id, 'can_delete_report', '无权删除此报表');

        $reportDate = $report->report_date;
        $hotelId = $report->hotel_id;
        $report->delete();

        OperationLog::record('daily_report', 'delete', '删除日报表: ' . $reportDate, $this->currentUser->id, $hotelId);

        return $this->success(null, '删除成功');
    }

    /**
     * 提取报表数据（只保留配置中定义的字段）
     */
    private function extractReportData(array $data): array
    {
        // 获取所有配置的字段名
        $fieldNames = Db::table('report_configs')
            ->where('report_type', 'daily')
            ->where('status', 1)
            ->column('field_name');
        
        $reportData = [];
        foreach ($fieldNames as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $num = $this->normalizeNumber($value);
                $reportData[$field] = $num !== null ? $num : $value;
            }
        }
        
        return $reportData;
    }

    /**
     * 规范化数值（支持逗号、空格、百分号、非断空格）
     */
    private function normalizeNumber($value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return null;
        }
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }
        // 去掉逗号和非断空格
        $clean = str_replace([',', "\u{00A0}", ' '], '', $clean);
        // 处理百分号（保持为百分数值，如 12% => 12）
        if (strpos($clean, '%') !== false) {
            $clean = str_replace('%', '', $clean);
        }
        if (!is_numeric($clean)) {
            return null;
        }
        return (float)$clean;
    }

    private function resolveSalableRoomsTotal($hotel, array $taskData, array $reportData): ?float
    {
        foreach ([
            [$taskData, ['salable_rooms_total']],
            [$reportData, ['salable_rooms', 'total_rooms_count', 'room_count']],
            [$hotel, ['salable_rooms_total', 'salable_rooms', 'room_count', 'rooms_total']],
        ] as [$source, $keys]) {
            $value = $this->readNumericValue($source, $keys);
            if ($value !== null && $value > 0) {
                return $value;
            }
        }

        return null;
    }

    private function resolveReportSalableRooms(array $data): ?float
    {
        return $this->readNumericValue($data, ['salable_rooms', 'total_rooms_count', 'room_count']);
    }

    private function readNumericValue($source, array $keys): ?float
    {
        foreach ($keys as $key) {
            $raw = null;
            $hasValue = false;

            if (is_array($source) && array_key_exists($key, $source)) {
                $raw = $source[$key];
                $hasValue = true;
            } elseif (is_object($source)) {
                if (isset($source->{$key})) {
                    $raw = $source->{$key};
                    $hasValue = true;
                } elseif (method_exists($source, 'getAttr')) {
                    try {
                        $raw = $source->getAttr($key);
                        $hasValue = true;
                    } catch (\Throwable $e) {
                        $hasValue = false;
                    }
                }
            }

            if (!$hasValue) {
                continue;
            }

            $num = $this->normalizeNumber($raw);
            if ($num !== null) {
                return $num;
            }
        }

        return null;
    }

    /**
     * 获取日报查看映射配置
     */
    private function getViewMappingConfig(): array
    {
        $raw = SystemConfig::getValue('daily_report_view_mapping', '[]');
        $config = json_decode((string)$raw, true);
        return is_array($config) ? $config : [];
    }

    /**
     * 计算映射值
     */
    private function calculateViewMappingValues(array $config, array $reportData, array $taskData, array $monthSum, array $calc): array
    {
        $context = [
            'report' => $reportData,
            'task' => $taskData,
            'month' => $monthSum,
            'calc' => $calc,
        ];
        $values = [];

        foreach ($config as $item) {
            $formula = trim((string)($item['formula'] ?? ''));
            $template = trim((string)($item['template'] ?? ''));
            $source = trim((string)($item['source'] ?? 'report'));
            $field = trim((string)($item['field'] ?? ''));
            $error = null;
            $value = null;

            if ($template !== '') {
                $value = $this->renderTemplate($template, $context);
            } elseif ($formula !== '') {
                $value = $this->evaluateFormula($formula, $context, $error);
            } else {
                $value = $this->resolveContextValue($context, $source, $field);
                if ($value === null) {
                    $error = '字段不存在';
                }
            }

            $values[] = [
                'value' => $value,
                'error' => $error,
            ];
        }

        return $values;
    }

    /**
     * 从上下文读取值（支持点路径）
     */
    private function resolveContextValue(array $context, string $source, string $field)
    {
        if (!isset($context[$source])) {
            return null;
        }
        $data = $context[$source];
        if ($field === '') {
            return null;
        }
        $parts = explode('.', $field);
        $value = $data;
        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return null;
            }
        }
        return $value;
    }

    /**
     * 计算公式（仅支持四则运算与括号）
     */
    private function evaluateFormula(string $formula, array $context, ?string &$error = null): ?float
    {
        $expr = $formula;
        $error = null;
        $missingTokens = [];

        // 替换变量：report.xxx / task.xxx / month.xxx / calc.xxx
        $expr = preg_replace_callback('/[a-zA-Z_][a-zA-Z0-9_\.]*/', function ($matches) use ($context, &$missingTokens) {
            $token = $matches[0];
            if (strpos($token, '.') !== false) {
                [$source, $field] = explode('.', $token, 2);
                $value = $this->resolveContextValue($context, $source, $field);
                $num = $this->normalizeNumber($value);
                if ($num === null) {
                    $missingTokens[] = $token;
                    return '0';
                }
                return (string)$num;
            }
            return $token;
        }, $expr);

        if ($missingTokens !== []) {
            $error = '公式缺少字段: ' . implode(', ', array_values(array_unique($missingTokens)));
            return null;
        }

        // 仅允许数字、运算符与括号
        if (preg_match('/[^0-9\.\+\-\*\/\(\)\s]/', $expr)) {
            $error = '公式包含非法字符';
            return null;
        }

        $result = $this->evaluateArithmeticExpression($expr);
        if ($result === null || is_nan($result) || is_infinite($result)) {
            $error = '公式结果无效';
            return null;
        }

        return $result;
    }

    private function evaluateArithmeticExpression(string $expr): ?float
    {
        $pos = 0;
        $value = $this->parseFormulaExpression($expr, $pos);
        $this->skipFormulaWhitespace($expr, $pos);

        return $value !== null && $pos === strlen($expr) ? $value : null;
    }

    private function parseFormulaExpression(string $expr, int &$pos): ?float
    {
        $value = $this->parseFormulaTerm($expr, $pos);
        if ($value === null) {
            return null;
        }

        while (true) {
            $this->skipFormulaWhitespace($expr, $pos);
            $operator = $expr[$pos] ?? '';
            if ($operator !== '+' && $operator !== '-') {
                return $value;
            }
            $pos++;

            $right = $this->parseFormulaTerm($expr, $pos);
            if ($right === null) {
                return null;
            }

            $value = $operator === '+' ? $value + $right : $value - $right;
        }
    }

    private function parseFormulaTerm(string $expr, int &$pos): ?float
    {
        $value = $this->parseFormulaFactor($expr, $pos);
        if ($value === null) {
            return null;
        }

        while (true) {
            $this->skipFormulaWhitespace($expr, $pos);
            $operator = $expr[$pos] ?? '';
            if ($operator !== '*' && $operator !== '/') {
                return $value;
            }
            $pos++;

            $right = $this->parseFormulaFactor($expr, $pos);
            if ($right === null) {
                return null;
            }

            if ($operator === '/') {
                if (abs($right) < 0.0000000001) {
                    return null;
                }
                $value /= $right;
            } else {
                $value *= $right;
            }
        }
    }

    private function parseFormulaFactor(string $expr, int &$pos): ?float
    {
        $this->skipFormulaWhitespace($expr, $pos);
        $char = $expr[$pos] ?? '';

        if ($char === '+' || $char === '-') {
            $pos++;
            $value = $this->parseFormulaFactor($expr, $pos);
            if ($value === null) {
                return null;
            }
            return $char === '-' ? -$value : $value;
        }

        if ($char === '(') {
            $pos++;
            $value = $this->parseFormulaExpression($expr, $pos);
            $this->skipFormulaWhitespace($expr, $pos);
            if (($expr[$pos] ?? '') !== ')') {
                return null;
            }
            $pos++;
            return $value;
        }

        $remaining = substr($expr, $pos);
        if (!preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)/', $remaining, $match)) {
            return null;
        }

        $pos += strlen($match[0]);
        return (float)$match[0];
    }

    private function skipFormulaWhitespace(string $expr, int &$pos): void
    {
        $length = strlen($expr);
        while ($pos < $length && ctype_space($expr[$pos])) {
            $pos++;
        }
    }

    /**
     * 渲染模板字符串，支持 {report.xxx}/{task.xxx}/{month.xxx}/{calc.xxx}
     */
    private function renderTemplate(string $template, array $context): string
    {
        return preg_replace_callback('/\{([a-zA-Z_]+)\.([a-zA-Z0-9_\.]+)\}/', function ($matches) use ($context) {
            $source = $matches[1];
            $field = $matches[2];
            $value = $this->resolveContextValue($context, $source, $field);
            if ($value === null) {
                return '—';
            }
            return (string)$value;
        }, $template);
    }

    /**
     * 构建映射字段来源及中文名
     */
    private function buildViewMappingSources(array $reportData, array $taskData, array $monthSum, array $calc): array
    {
        $dailyConfigs = Db::table('report_configs')
            ->where('report_type', 'daily')
            ->where('status', 1)
            ->column('display_name', 'field_name');
        $monthlyConfigs = Db::table('report_configs')
            ->where('report_type', 'monthly')
            ->where('status', 1)
            ->column('display_name', 'field_name');

        $makeList = function (array $keys, array $labelMap) {
            $list = [];
            foreach ($keys as $key) {
                $label = $labelMap[$key] ?? $key;
                $list[] = ['key' => $key, 'label' => $label];
            }
            usort($list, fn($a, $b) => strcmp($a['label'], $b['label']));
            return $list;
        };

        $calcLabels = [
            'month_revenue_target' => '月营收总目标',
            'month_revenue' => '月累计完成营收',
            'month_complete_rate' => '月当期完成率',
            'day_revenue_target' => '日营收当期目标',
            'day_revenue' => '日实际完成营收',
            'month_occ_rate' => '月综合出租率',
            'day_occ_rate' => '日综合出租率',
            'day_overnight_occ_rate' => '日过夜出租率',
            'day_total_rooms' => '日出租总数',
            'day_overnight_rooms' => '过夜房',
            'day_non_overnight_rooms' => '非过夜房',
            'day_hourly_rooms' => '钟点房',
            'month_adr' => '月均价',
            'day_adr' => '日均价',
            'overnight_adr' => '过夜均价',
            'month_revpar' => '月Revpar',
            'day_revpar' => '日Revpar',
            'day_stored_value' => '当日储值金额',
            'month_stored_value' => '当月储值金额',
        ];

        return [
            'report' => $makeList(array_keys($reportData ?? []), $dailyConfigs),
            'task' => $makeList(array_keys($taskData ?? []), $monthlyConfigs),
            'month' => $makeList(array_values(array_filter(
                array_keys($monthSum ?? []),
                static fn(string $key): bool => !str_starts_with($key, '__')
            )), $dailyConfigs),
            'calc' => $makeList(array_keys($calc ?? []), $calcLabels),
        ];
    }

    /**
     * 导出日报表到Excel
     */
    public function export(): Response
    {
        $this->checkPermission();

        $startDate = $this->request->get('start_date');
        $endDate = $this->request->get('end_date');
        $hotelId = $this->request->get('hotel_id');
        $reportId = $this->request->get('id');

        // 单个报表导出
        if ($reportId) {
            return $this->exportSingle((int)$reportId);
        }

        if ($hotelId !== null && $hotelId !== '') {
            if (!is_numeric($hotelId) || (int)$hotelId <= 0) {
                throw new \think\exception\HttpException(403, '无权导出该酒店的报表');
            }
            $this->currentUser->hasHotelPermissionOrFail((int)$hotelId, 'can_view_report', '无权导出该酒店的报表');
        }

        // 批量导出
        return $this->exportBatch($hotelId, $startDate, $endDate);
    }

    /**
     * 导出单个日报表
     */
    private function exportSingle(int $id): Response
    {
        $report = DailyReportModel::with(['hotel'])->find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }
        
        $this->currentUser->hasHotelPermissionOrFail((int)$report->hotel_id, 'can_view_report', '无权导出该报表');
        
        $hotel = $report->hotel;
        $reportData = $this->normalizeReportData($report->report_data ?? []);
        $reportDate = $report->report_date;
        
        // 获取年月
        $dateParts = explode('-', $reportDate);
        $year = (int)$dateParts[0];
        $month = (int)$dateParts[1];
        $day = (int)$dateParts[2];
        $monthDays = (int)date('t', strtotime($reportDate));
        
        // 获取月任务
        $monthlyTask = MonthlyTask::where('hotel_id', $report->hotel_id)
            ->where('year', $year)
            ->where('month', $month)
            ->find();
        $taskData = $monthlyTask ? ($monthlyTask->task_data ?? []) : [];
        
        // 获取当月所有日报表数据
        $monthReports = DailyReportModel::where('hotel_id', $report->hotel_id)
            ->where('report_date', '>=', "{$year}-{$month}-01")
            ->where('report_date', '<=', $reportDate)
            ->select();
        
        // 计算月累计数据
        $monthSum = $this->calculateMonthSum($monthReports);
        
        // 准备导出数据 - 使用原始数据，与批量导出保持一致
        $exportData = [
            'mode' => 'single',
            'report' => [
                'hotel_name' => $hotel->name ?? '',
                'report_date' => $reportDate,
                'data' => $reportData,  // 直接传递原始数据
            ],
            'month_task' => $taskData,
            'watermark' => $this->buildExportWatermark((int)$report->hotel_id, 1),
        ];

        return $this->generateExcelResponse($exportData, "日报表_{$hotel->name}_{$reportDate}.xlsx");
    }

    /**
     * 批量导出日报表
     */
    private function exportBatch($hotelId, $startDate, $endDate): Response
    {
        $query = DailyReportModel::with('hotel');

        // 权限过滤
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelIds = array_values(array_filter(
                array_map('intval', $this->currentUser->getPermittedHotelIds()),
                fn(int $candidateHotelId): bool => $this->currentUser->hasHotelPermission(
                    $candidateHotelId,
                    'can_view_report'
                )
            ));
            if ($hotelIds === []) {
                throw new \think\exception\HttpException(403, '无权导出酒店报表');
            }
            $query->whereIn('hotel_id', $hotelIds);
        }

        if ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }

        if ($startDate) {
            $query->where('report_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('report_date', '<=', $endDate);
        }

        $reports = $query->order('report_date', 'asc')->select();
        $reportCount = count($reports);
        if (!$this->isExportBatchAllowed($reportCount)) {
            OperationLog::record(
                'daily_report',
                'export_blocked',
                '批量导出超出限制: ' . $reportCount . '条',
                $this->currentUser->id,
                $hotelId ? (int)$hotelId : null,
                'export_limit_exceeded',
                [
                    'audit_type' => 'operation',
                    'limit' => self::EXPORT_BATCH_LIMIT,
                    'requested_count' => $reportCount,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]
            );
            return $this->error('批量导出最多支持' . self::EXPORT_BATCH_LIMIT . '条日报，请缩小日期范围或选择单日报导出', 429, [
                'limit' => self::EXPORT_BATCH_LIMIT,
                'requested_count' => $reportCount,
            ]);
        }

        $monthTaskData = [];
        $reportHotelIds = [];
        foreach ($reports as $report) {
            $reportHotelId = (int)($report->hotel_id ?? 0);
            if ($reportHotelId > 0) {
                $reportHotelIds[] = $reportHotelId;
            }
        }
        $reportHotelIds = array_values(array_unique($reportHotelIds));
        if ($reportHotelIds === [] && is_numeric($hotelId) && (int)$hotelId > 0) {
            $reportHotelIds[] = (int)$hotelId;
        }
        $watermarkHotelId = count($reportHotelIds) === 1 ? $reportHotelIds[0] : null;

        $exportData = [
            'reports' => [],
            'month_task' => $monthTaskData,
            'watermark' => $this->buildExportWatermark($watermarkHotelId, $reportCount, $reportHotelIds),
        ];
        
        // 获取酒店名称用于文件名
        $hotelName = '';
        if ($hotelId) {
            $hotel = Hotel::find($hotelId);
            if ($hotel) {
                $hotelName = $hotel->name;
            }
        }

        foreach ($reports as $report) {
            $reportData = $this->normalizeReportData($report->report_data ?? []);
            $taskContext = $this->loadMonthlyTaskContext((int)$report->hotel_id, (string)$report->report_date);

            if (!$hotelName && $report->hotel) {
                $hotelName = $report->hotel->name;
            }

            $exportData['reports'][] = [
                'hotel_name' => $report->hotel->name ?? '',
                'report_date' => $report->report_date,
                'hotel_id' => (int)$report->hotel_id,
                'month_task_key' => $taskContext['key'],
                'month_task' => $taskContext['data'],
                'data' => $reportData,
            ];
        }

        // 生成文件名，包含酒店名称
        $filename = '日报表';
        if ($hotelName) {
            $filename .= "_{$hotelName}";
        }
        if ($startDate && $endDate) {
            $filename .= "_{$startDate}_{$endDate}";
        }
        $filename .= '.xlsx';

        return $this->generateExcelResponse($exportData, $filename);
    }

    private function isExportBatchAllowed(int $reportCount): bool
    {
        return $reportCount <= self::EXPORT_BATCH_LIMIT;
    }

    private function buildExportWatermark(?int $hotelId, int $reportCount, array $reportHotelIds = []): array
    {
        $userId = (int)($this->currentUser->id ?? 0);
        $username = trim((string)($this->currentUser->realname ?? $this->currentUser->username ?? 'unknown'));
        $exportedAt = date('Y-m-d H:i:s');
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $reportHotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelIds === [] && $hotelId !== null && $hotelId > 0) {
            $hotelIds[] = $hotelId;
        }
        $tenantRows = $hotelIds === []
            ? []
            : Db::name('hotels')->whereIn('id', $hotelIds)->column('tenant_id', 'id');
        $tenantIds = [];
        $unresolvedHotelIds = [];
        foreach ($hotelIds as $targetHotelId) {
            $targetTenantId = (int)($tenantRows[$targetHotelId] ?? 0);
            if ($targetTenantId <= 0) {
                $unresolvedHotelIds[] = $targetHotelId;
                continue;
            }
            $tenantIds[] = $targetTenantId;
        }
        $tenantIds = array_values(array_unique($tenantIds));
        sort($tenantIds);
        $tenantId = $unresolvedHotelIds === [] && count($tenantIds) === 1 ? $tenantIds[0] : null;
        $tenantScope = $hotelIds === []
            ? 'none'
            : ($unresolvedHotelIds !== [] ? 'unresolved' : (count($tenantIds) === 1 ? 'single' : 'mixed'));
        $tenantLabel = $tenantId !== null
            ? (string)$tenantId
            : ($tenantIds !== [] ? 'mixed[' . implode(',', $tenantIds) . ']' : 'unknown');
        $requestId = trim((string)($this->request->request_id ?? $this->request->header('X-Request-ID', '')));
        if ($requestId === '') {
            $requestId = 'missing_request_id';
        }

        return [
            'tenant_id' => $tenantId,
            'tenant_ids' => $tenantIds,
            'tenant_scope' => $tenantScope,
            'unresolved_hotel_ids' => $unresolvedHotelIds,
            'user_id' => $userId,
            'username' => $username,
            'hotel_id' => $hotelId,
            'report_count' => $reportCount,
            'exported_at' => $exportedAt,
            'generated_at' => $exportedAt,
            'request_id' => $requestId,
            'text' => sprintf('SUXIOS Export Watermark | tenant=%s | user=%s#%d | hotel=%s | request=%s | count=%d | generated=%s', $tenantLabel, $username, $userId, $hotelId ?? 'mixed', $requestId, $reportCount, $exportedAt),
        ];
    }

    private function formatExportWatermark(array $watermark): string
    {
        $text = trim((string)($watermark['text'] ?? ''));
        return $text === '' ? '' : $this->escapeHtml($text);
    }

    private function loadMonthlyTaskContext(int $hotelId, string $reportDate): array
    {
        $dateParts = explode('-', $reportDate);
        $year = (int)($dateParts[0] ?? 0);
        $month = (int)($dateParts[1] ?? 0);
        $key = "{$hotelId}-{$year}-{$month}";

        if ($hotelId <= 0 || $year <= 0 || $month <= 0) {
            return ['key' => $key, 'data' => []];
        }

        $monthlyTask = MonthlyTask::where('hotel_id', $hotelId)
            ->where('year', $year)
            ->where('month', $month)
            ->find();

        return [
            'key' => $key,
            'data' => $monthlyTask ? ($monthlyTask->task_data ?? []) : [],
        ];
    }

    private function sumBatchMonthTaskValue(array $reports, string $field, ?float $fallback = null): ?float
    {
        $sum = 0.0;
        $seen = [];
        $hasValue = false;

        foreach ($reports as $report) {
            if (!array_key_exists('month_task', $report)) {
                continue;
            }
            $key = (string)($report['month_task_key'] ?? (($report['hotel_id'] ?? '') . '-' . substr((string)($report['report_date'] ?? ''), 0, 7)));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $taskData = $report['month_task'] ?? [];
            if (!is_array($taskData)) {
                return null;
            }

            $value = $this->readNumericValue($taskData, [$field]);
            if ($value === null) {
                return null;
            }
            $sum += $value;
            $hasValue = true;
        }

        return $hasValue ? $sum : $fallback;
    }

    /**
     * 生成Excel文件（PHP原生HTML表格方式，模拟Python脚本样式）
     */
    private function generateExcelResponse(array $exportData, string $filename): Response
    {
        if (!isset($exportData['watermark'])) {
            $mode = (string)($exportData['mode'] ?? '');
            $reportCount = $mode === 'single' ? 1 : count($exportData['reports'] ?? []);
            $exportData['watermark'] = $this->buildExportWatermark(null, $reportCount);
        }
        $html = $this->generateExcelHtml($exportData);
        OperationLog::record(
            'daily_report',
            'export',
            '导出日报表: ' . $filename,
            (int)($this->currentUser->id ?? 0) ?: null,
            isset($exportData['watermark']['hotel_id']) ? (int)$exportData['watermark']['hotel_id'] : null,
            null,
            [
                'audit_type' => 'operation',
                'filename' => $filename,
                'tenant_id' => $exportData['watermark']['tenant_id'] ?? null,
                'request_id' => $exportData['watermark']['request_id'] ?? null,
                'generated_at' => $exportData['watermark']['generated_at'] ?? null,
                'report_count' => $exportData['watermark']['report_count'] ?? null,
                'watermark' => $exportData['watermark']['text'] ?? '',
            ]
        );
        
        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . urlencode($filename) . '"',
            'Content-Length' => strlen($html),
            'Cache-Control' => 'max-age=0',
        ]);
    }
    
    /**
     * 生成Excel HTML内容（严格按照模板结构）
     * 模板结构分析：
     * - 第1行：大类合并（日期、星期、合计、线上客房收入、线下客房收入、钟点房、其他收入合计、好评、流量、私域流量、私域订单、会员）
     * - 第2行：子类合并（线上合计、各OTA渠道；线下合计、各线下渠道；好评合计、各平台好评；携程流量、美团流量）
     * - 第3行：具体列名
     * - 第4行：合计数据
     * - 第5行起：每日数据
     */
    private function generateExcelHtml(array $exportData): string
    {
        $mode = $exportData['mode'] ?? 'batch';
        $monthTask = $exportData['month_task'] ?? [];
        
        if ($mode === 'single') {
            $reports = [[
                'hotel_name' => $exportData['report']['hotel_name'] ?? '',
                'report_date' => $exportData['report']['report_date'] ?? '',
                'data' => $exportData['report']['data'] ?? [],
            ]];
        } else {
            $reports = $exportData['reports'] ?? [];
        }
        foreach ($reports as &$report) {
            $report['data'] = $this->normalizeReportData($report['data'] ?? []);
        }
        unset($report);
        
        // 月任务数据
        $monthRevenueTarget = $this->readNumericValue($monthTask, ['revenue_budget', 'revenue_target']);
        $onlineTarget = $this->readNumericValue($monthTask, ['online_revenue_target']);
        $offlineTarget = $this->readNumericValue($monthTask, ['offline_revenue_target']);
        if ($mode === 'batch') {
            $monthRevenueTarget = $this->sumBatchMonthTaskValue($reports, 'revenue_budget', $monthRevenueTarget);
            $onlineTarget = $this->sumBatchMonthTaskValue($reports, 'online_revenue_target', $onlineTarget);
            $offlineTarget = $this->sumBatchMonthTaskValue($reports, 'offline_revenue_target', $offlineTarget);
        }
        $ratio = static fn($numerator, $denominator): ?float => is_numeric($numerator) && is_numeric($denominator) && (float)$denominator > 0
            ? (float)$numerator / (float)$denominator
            : null;
        
        // 星期数组
        $weekArray = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
        
        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>日报表</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
table { border-collapse: collapse; mso-display-decimal-separator:"\002E"; mso-display-thousand-separator:"\002C"; }
td, th { border: .5pt solid black; padding: 2px 3px; font-family: Arial; font-size: 11px; text-align: center; vertical-align: center; }
</style>
</head><body>
<table border="1">';
        
        // ==================== 第一行表头（严格按照模板） ====================
        $html .= '<tr style="height:25pt">';
        $html .= '<td rowspan="3" style="background:#B2CFEA;font-weight:bold">日期</td>';
        $html .= '<td rowspan="3" style="background:#B2CFEA;font-weight:bold">星期</td>';
        $html .= '<td colspan="13" rowspan="2" style="background:#B2CFEA;font-weight:bold">合计</td>';
        $html .= '<td colspan="25" style="background:#B2CFEA;font-weight:bold">线上客房收入</td>';
        $html .= '<td colspan="23" style="background:#B2CFEA;font-weight:bold">线下客房收入</td>';
        $html .= '<td colspan="2" rowspan="2" style="background:#B2CFEA;font-weight:bold">钟点房</td>';
        $html .= '<td colspan="7" style="background:#B2CFEA;font-weight:bold">其他收入合计</td>';
        $html .= '<td colspan="9" style="background:#B2CFEA;font-weight:bold">好评</td>';
        $html .= '<td colspan="7" style="background:#B2CFEA;font-weight:bold">流量</td>';
        $html .= '<td colspan="3" rowspan="2" style="background:#B2CFEA;font-weight:bold">私域流量</td>';
        $html .= '<td colspan="2" rowspan="2" style="background:#B2CFEA;font-weight:bold">私域订单</td>';
        $html .= '<td colspan="3" rowspan="2" style="background:#B2CFEA;font-weight:bold">会员</td>';
        $html .= '</tr>';
        
        // ==================== 第二行表头 ====================
        $html .= '<tr style="height:25pt">';
        // 线上客房收入子分类（25列：线上合计5列 + 10个OTA渠道各2列）
        $html .= '<td colspan="5" style="background:#B2CFEA;font-weight:bold">线上合计</td>';
        foreach (self::OTA_EXCEL_CHANNELS as $channel) {
            $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">' . $channel['label'] . '</td>';
        }
        // 线下客房收入子分类（17列：5+2*9=5+18=23？不对，模板是17列）
        // 根据模板：线下合计(5) + 散客(2)+会员体验(2)+网络体验(2)+团队(2)+协议客户(2)+微信(2)+免费房(2)+集团金卡(2)+集团黑金卡(2) = 5+18=23
        // 但模板显示AI1:BE1是17列...让我重新计算
        // AI(35)到BE(57)，共57-35+1=23列，所以线下是23列，不是17列
        // 修正：线下客房收入是23列
        $html .= '<td colspan="5" style="background:#B2CFEA;font-weight:bold">线下合计</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">散客</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">会员体验</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">网络体验</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">团队</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">协议客户</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">微信</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">免费房</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">集团金卡</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">集团黑金卡</td>';
        // 其他收入合计（7列，第2行不需要额外内容，因为第1行已合并）
        // 第2行需要占位单元格
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">停车费</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">餐饮</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">会议活动</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">商品</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">会员卡费收入</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">其他</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold" rowspan="2">合计</td>';
        // 好评（9列：3+2+2+2）
        $html .= '<td colspan="3" style="background:#B2CFEA;font-weight:bold">好评合计</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">携程、艺龙、去哪儿</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">美团</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">飞猪</td>';
        // 流量（7列：4+3）
        $html .= '<td colspan="4" style="background:#B2CFEA;font-weight:bold">携程</td>';
        $html .= '<td colspan="3" style="background:#B2CFEA;font-weight:bold">美团</td>';
        $html .= '</tr>';
        
        // ==================== 第三行表头 ====================
        $html .= '<tr style="height:25pt">';
        // 合计区域 C-O 详细列名（13列）
        $cols = ['月目标', '总目标完成率', '总收入', '客房收入', '客房Revpar', '出租率', '平均房价', '过夜出租率', '过夜均价', '过夜Revpar', 'OTA间夜占比', '售房数', '可售房数量'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 线上合计 P-T（5列）
        $cols = ['线上目标', '线上目标完成率', '收入', '间夜', '平均房价'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 各OTA渠道（每个渠道：收入、间夜）
        foreach (self::OTA_EXCEL_CHANNELS as $channel) {
            $html .= '<td style="background:#EBF1DE;font-weight:bold">收入</td>';
            $html .= '<td style="background:#EBF1DE;font-weight:bold">间夜</td>';
        }
        // 线下合计（5列）
        $cols = ['线下目标', '线下目标完成率', '收入', '间夜', '平均房价'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 散客到集团黑金卡（9个渠道，各2列，共18列）
        for ($i = 0; $i < 9; $i++) {
            $html .= '<td style="background:#EBF1DE;font-weight:bold">收入</td>';
            $html .= '<td style="background:#EBF1DE;font-weight:bold">间夜</td>';
        }
        // 钟点房（2列，已在第1-2行合并）
        $html .= '<td style="background:#EBF1DE;font-weight:bold">收入</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold">间夜</td>';
        // 其他收入合计（7列，已在第2行用rowspan=2定义，第3行不需要额外单元格）
        // 好评合计（3列）
        $cols = ['订单数', '5分点评数', '好转化评率'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 携程、艺龙、去哪儿；美团；飞猪（3个平台，各2列）
        for ($i = 0; $i < 3; $i++) {
            $html .= '<td style="background:#EBF1DE;font-weight:bold">可评价订单数</td>';
            $html .= '<td style="background:#EBF1DE;font-weight:bold">5分点评数量</td>';
        }
        // 携程流量（4列）
        $cols = ['列表页曝光量', '曝光转化率', '下单转化率', '成交转化率'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 美团流量（3列）
        $cols = ['曝光人数', '浏览人数', '线上支付单数'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 私域流量（3列）
        $cols = ['微信加粉率（口径待确认）', '微信加粉人数', '新增会员人数'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#D9E1F2;font-weight:bold">' . $col . '</td>';
        }
        // 私域订单（2列）
        $html .= '<td style="background:#EBF1DE;font-weight:bold">收入</td>';
        $html .= '<td style="background:#EBF1DE;font-weight:bold">间夜</td>';
        // 会员（3列）
        $cols = ['售卡量', '售卡收入', '储值'];
        foreach ($cols as $col) {
            $html .= '<td style="background:#EBF1DE;font-weight:bold">' . $col . '</td>';
        }
        $html .= '</tr>';
        
        // ==================== 合计行 ====================
        $totals = $this->calculateTotals($reports, $monthRevenueTarget, $onlineTarget, $offlineTarget);
        
        $html .= '<tr style="height:25pt">';
        $html .= '<td colspan="2" style="background:yellow;font-weight:bold;font-size:12px">合计</td>';
        
        // 合计区域数据 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($monthRevenueTarget) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($ratio($totals['total_revenue'], $monthRevenueTarget)) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['total_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['room_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['revpar']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['occ_rate']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['adr']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['overnight_occ_rate']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['overnight_adr']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['overnight_revpar']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['ota_room_rate']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['total_rooms'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['salable_rooms'], 0) . '</td>';
        
        // 线上合计 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($onlineTarget) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($ratio($totals['online_revenue'], $onlineTarget)) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_rooms'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_adr']) . '</td>';
        
        // 各OTA - 黄色背景
        foreach (self::OTA_CHANNEL_KEYS as $key) {
            $revKey = $key . '_revenue';
            $roomKey = $key . '_rooms';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$revKey] ?? null) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$roomKey] ?? null, 0) . '</td>';
        }
        
        // 线下合计 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($offlineTarget) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($ratio($totals['offline_revenue'], $offlineTarget)) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_rooms'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_adr']) . '</td>';
        
        // 各线下渠道 - 黄色背景
        $offlineChannels = ['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold'];
        foreach ($offlineChannels as $channel) {
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$channel . '_revenue'] ?? null) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$channel . '_rooms'] ?? null, 0) . '</td>';
        }
        
        // 钟点房 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['hourly_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['hourly_rooms'], 0) . '</td>';
        
        // 其他收入 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['other_revenue_total']) . '</td>';
        foreach (['parking', 'dining', 'meeting', 'goods', 'member_card', 'other'] as $key) {
            $valKey = $key . '_revenue';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$valKey] ?? null) . '</td>';
        }
        
        // 好评 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['review_orders'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['good_reviews'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['good_review_rate']) . '</td>';
        
        // 各平台好评 - 黄色背景
        foreach (['xb', 'mt', 'fliggy'] as $platform) {
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$platform . '_reviewable'] ?? null, 0) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$platform . '_good_review'] ?? null, 0) . '</td>';
        }
        
        // 流量 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['xb_exposure'] ?? null, 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_exp_rate'] ?? null) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_bk_rate'] ?? null) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_clinch_rate'] ?? null) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['mt_exposure'] ?? null, 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['mt_click_rate'] ?? null) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['mt_pay_rate'] ?? null) . '</td>';
        
        // 私域流量 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['wechat_add_rate']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['wechat_add'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['member_add'], 0) . '</td>';
        
        // 私域订单 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['private_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['private_rooms'], 0) . '</td>';
        
        // 会员 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['member_card_sold'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['member_card_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['stored_value']) . '</td>';
        
        $html .= '</tr>';
        
        // ==================== 数据行 ====================
        foreach ($reports as $report) {
            $data = $this->normalizeReportData($report['data'] ?? []);
            $reportDate = $report['report_date'] ?? '';
            
            $html .= '<tr>';
            
            // 日期
            $html .= '<td>' . $reportDate . '</td>';
            
            // 星期
            $weekday = '';
            $isWeekend = false;
            if ($reportDate) {
                $timestamp = strtotime($reportDate);
                $weekday = $weekArray[date('w', $timestamp)];
                $isWeekend = in_array($weekday, ['星期五', '星期六']);
            }
            $html .= '<td' . ($isWeekend ? ' style="background:#FFC7CE;text-align:center"' : '') . '>' . $weekday . '</td>';
            
            // 获取数据
            $getVal = fn(string $key): ?float => $this->readNumericValue($data, [$key]);
            $sumRequired = static fn(array $values): ?float => $values !== [] && !in_array(null, $values, true)
                ? array_sum($values)
                : null;
            $rowMonthTask = is_array($report['month_task'] ?? null) ? $report['month_task'] : $monthTask;
            $rowMonthRevenueTarget = $this->readNumericValue($rowMonthTask, ['revenue_budget', 'revenue_target']) ?? $monthRevenueTarget;
            $rowOnlineTarget = $this->readNumericValue($rowMonthTask, ['online_revenue_target']) ?? $onlineTarget;
            $rowOfflineTarget = $this->readNumericValue($rowMonthTask, ['offline_revenue_target']) ?? $offlineTarget;
            
            // 可售房数
            $salableRooms = $this->resolveReportSalableRooms($data);
            
            // 计算各项数据
            // 线上收入
            $xbRevenue = $getVal('xb_revenue');
            $mtRevenue = $getVal('mt_revenue');
            $fliggyRevenue = $getVal('fliggy_revenue');
            $dyRevenue = $getVal('dy_revenue');
            $tcRevenue = $getVal('tc_revenue');
            $qnRevenue = $getVal('qn_revenue');
            $zxRevenue = $getVal('zx_revenue');
            $bookingRevenue = $getVal('booking_revenue');
            $agodaRevenue = $getVal('agoda_revenue');
            $expediaRevenue = $getVal('expedia_revenue');
            
            // 线上间夜
            $xbRooms = $getVal('xb_rooms');
            $mtRooms = $getVal('mt_rooms');
            $fliggyRooms = $getVal('fliggy_rooms');
            $dyRooms = $getVal('dy_rooms');
            $tcRooms = $getVal('tc_rooms');
            $qnRooms = $getVal('qn_rooms');
            $zxRooms = $getVal('zx_rooms');
            $bookingRooms = $getVal('booking_rooms');
            $agodaRooms = $getVal('agoda_rooms');
            $expediaRooms = $getVal('expedia_rooms');
            
            // 线下收入
            $walkinRevenue = $getVal('walkin_revenue');
            $memberExpRevenue = $getVal('member_exp_revenue');
            $webExpRevenue = $getVal('web_exp_revenue');
            $groupRevenue = $getVal('group_revenue');
            $protocolRevenue = $getVal('protocol_revenue');
            $wechatRevenue = $getVal('wechat_revenue');
            $freeRevenue = $getVal('free_revenue');
            $goldCardRevenue = $getVal('gold_card_revenue');
            $blackGoldRevenue = $getVal('black_gold_revenue');
            $hourlyRevenue = $getVal('hourly_revenue');
            
            // 线下间夜
            $walkinRooms = $getVal('walkin_rooms');
            $memberExpRooms = $getVal('member_exp_rooms');
            $webExpRooms = $getVal('web_exp_rooms');
            $groupRooms = $getVal('group_rooms');
            $protocolRooms = $getVal('protocol_rooms');
            $wechatRooms = $getVal('wechat_rooms');
            $freeRooms = $getVal('free_rooms');
            $goldCardRooms = $getVal('gold_card_rooms');
            $blackGoldRooms = $getVal('black_gold_rooms');
            $hourlyRooms = $getVal('hourly_rooms');
            
            // 其他收入
            $parkingRevenue = $getVal('parking_revenue');
            $diningRevenue = $getVal('dining_revenue');
            $meetingRevenue = $getVal('meeting_revenue');
            $goodsRevenue = $getVal('goods_revenue');
            $memberCardRevenue = $getVal('member_card_revenue');
            $otherRevenue = $getVal('other_revenue');
            
            // 计算汇总
            $onlineRevenue = $getVal('online_revenue') ?? $sumRequired([$xbRevenue, $mtRevenue, $fliggyRevenue, $dyRevenue, $tcRevenue, $qnRevenue, $zxRevenue, $bookingRevenue, $agodaRevenue, $expediaRevenue]);
            $onlineRooms = $getVal('online_rooms') ?? $sumRequired([$xbRooms, $mtRooms, $fliggyRooms, $dyRooms, $tcRooms, $qnRooms, $zxRooms, $bookingRooms, $agodaRooms, $expediaRooms]);
            $offlineRevenue = $getVal('offline_revenue') ?? $sumRequired([$walkinRevenue, $memberExpRevenue, $webExpRevenue, $groupRevenue, $protocolRevenue, $wechatRevenue, $freeRevenue, $goldCardRevenue, $blackGoldRevenue, $hourlyRevenue]);
            $offlineRooms = $getVal('offline_rooms') ?? $sumRequired([$walkinRooms, $memberExpRooms, $webExpRooms, $groupRooms, $protocolRooms, $wechatRooms, $freeRooms, $goldCardRooms, $blackGoldRooms, $hourlyRooms]);
            $otherRevenueTotal = $getVal('other_revenue_total') ?? $sumRequired([$parkingRevenue, $diningRevenue, $meetingRevenue, $goodsRevenue, $memberCardRevenue, $otherRevenue]);
            $roomRevenue = $getVal('room_revenue') ?? $sumRequired([$onlineRevenue, $offlineRevenue]);
            $totalRevenue = $this->readNumericValue($data, ['revenue', 'day_revenue']) ?? $sumRequired([$roomRevenue, $otherRevenueTotal]);
            $totalRooms = $this->readNumericValue($data, ['total_rooms', 'day_total_rooms']) ?? $sumRequired([$onlineRooms, $offlineRooms]);
            $overnightRooms = $getVal('overnight_rooms');
            $overnightRevenue = $sumRequired([$roomRevenue, $hourlyRevenue]);
            if ($overnightRevenue !== null) {
                $overnightRevenue = $roomRevenue - $hourlyRevenue;
            }
            
            // 合计区域 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($rowMonthRevenueTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($totalRevenue, $rowMonthRevenueTarget)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($roomRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($roomRevenue, $salableRooms)) . '</td>'; // Revpar
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($totalRooms, $salableRooms)) . '</td>'; // 出租率
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($roomRevenue, $totalRooms)) . '</td>'; // 平均房价
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($overnightRooms, $salableRooms)) . '</td>'; // 过夜出租率
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($overnightRevenue, $overnightRooms)) . '</td>'; // 过夜均价
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($overnightRevenue, $salableRooms)) . '</td>'; // 过夜Revpar
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($onlineRooms, $totalRooms)) . '</td>'; // OTA间夜占比
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalRooms, 0) . '</td>'; // 售房数
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($salableRooms, 0) . '</td>'; // 可售房数量
            
            // 线上合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($rowOnlineTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($onlineRevenue, $rowOnlineTarget)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineRooms, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($onlineRevenue, $onlineRooms)) . '</td>';
            
            // 携程 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($xbRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($xbRooms, 0) . '</td>';
            // 美团
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($mtRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($mtRooms, 0) . '</td>';
            // 飞猪
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($fliggyRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($fliggyRooms, 0) . '</td>';
            // 同程
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($tcRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($tcRooms, 0) . '</td>';
            // 抖音
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($dyRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($dyRooms, 0) . '</td>';
            // 去哪儿
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($qnRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($qnRooms, 0) . '</td>';
            // 智行
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($zxRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($zxRooms, 0) . '</td>';
            // Booking.com
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($bookingRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($bookingRooms, 0) . '</td>';
            // Agoda
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($agodaRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($agodaRooms, 0) . '</td>';
            // Expedia
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($expediaRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($expediaRooms, 0) . '</td>';
            
            // 线下合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($rowOfflineTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($offlineRevenue, $rowOfflineTarget)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineRooms, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($ratio($offlineRevenue, $offlineRooms)) . '</td>';
            
            // 各线下渠道 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($walkinRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($walkinRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberExpRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberExpRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($webExpRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($webExpRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($groupRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($groupRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($protocolRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($protocolRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($wechatRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($wechatRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($freeRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($freeRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($goldCardRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($goldCardRooms, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($blackGoldRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($blackGoldRooms, 0) . '</td>';
            
            // 钟点房 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($hourlyRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($hourlyRooms, 0) . '</td>';
            
            // 其他收入 - 蓝色+绿色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($otherRevenueTotal) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($parkingRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($diningRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($meetingRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($goodsRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberCardRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($otherRevenue) . '</td>';
            
            // 好评
            $xbReviewable = $getVal('xb_reviewable');
            $xbGoodReview = $getVal('xb_good_review');
            $mtReviewable = $getVal('mt_reviewable');
            $mtGoodReview = $getVal('mt_good_review');
            $fliggyReviewable = $getVal('fliggy_reviewable');
            $fliggyGoodReview = $getVal('fliggy_good_review');
            
            $totalReviewable = $sumRequired([$xbReviewable, $mtReviewable, $fliggyReviewable]);
            $totalGoodReview = $sumRequired([$xbGoodReview, $mtGoodReview, $fliggyGoodReview]);
            
            // 好评合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalReviewable, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalGoodReview, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($ratio($totalGoodReview, $totalReviewable)) . '</td>';
            
            // 各平台好评 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($xbReviewable, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($xbGoodReview, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($mtReviewable, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($mtGoodReview, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($fliggyReviewable, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($fliggyGoodReview, 0) . '</td>';
            
            // 流量 - 蓝色+绿色背景
            $xbExposure = $getVal('xb_exposure');
            $xbExpRate = $getVal('xb_exp_rate');
            $xbBkRate = $getVal('xb_bk_rate');
            $xbClinchRate = $getVal('xb_clinch_rate');
            $mtExposure = $getVal('mt_exposure');
            $mtClickRate = $getVal('mt_click_rate');
            $mtPayRate = $getVal('mt_pay_rate');
            
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($xbExposure, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtPct($xbExpRate) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtPct($xbBkRate) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtPct($xbClinchRate) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($mtExposure, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtPct($mtClickRate) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtPct($mtPayRate) . '</td>';
            
            // 私域流量 - 蓝色+绿色背景
            $wechatAdd = $getVal('wechat_add');
            $memberAdd = $getVal('member_add');
            $privateRevenue = $getVal('private_revenue');
            $privateRooms = $getVal('private_rooms');
            $memberCardSold = $getVal('member_card_sold');
            $storedValue = $getVal('stored_value');
            
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct(null) . '</td>'; // 暂无已验证的微信加粉率口径
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($wechatAdd, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberAdd, 0) . '</td>';
            
            // 私域订单 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($privateRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($privateRooms, 0) . '</td>';
            
            // 会员 - 绿色背景
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberCardSold, 0) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($memberCardRevenue) . '</td>';
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($storedValue) . '</td>';
            
            $html .= '</tr>';
        }
        
        $watermark = $this->formatExportWatermark($exportData['watermark'] ?? []);
        if ($watermark !== '') {
            $html .= '<tr><td colspan="114" style="color:#666666;font-size:10px;text-align:left;background:#F2F2F2">' . $watermark . '</td></tr>';
        }

        $html .= '</table></body></html>';
        
        return $html;
    }
    
    /**
     * 计算合计数据
     */
    private function calculateTotals(array $reports, ?float $monthTarget, ?float $onlineTarget, ?float $offlineTarget): array
    {
        $rows = array_map(
            fn(array $report): array => $this->normalizeReportData($report['data'] ?? []),
            $reports
        );
        $read = fn(array $data, array $keys): ?float => $this->readNumericValue($data, $keys);
        $completeSum = static function (array $values): ?float {
            return $values !== [] && !in_array(null, $values, true) ? array_sum($values) : null;
        };
        $sumMetric = static function (callable $resolver) use ($rows): ?float {
            if ($rows === []) {
                return null;
            }
            $sum = 0.0;
            foreach ($rows as $data) {
                $value = $resolver($data);
                if ($value === null) {
                    return null;
                }
                $sum += $value;
            }
            return $sum;
        };
        $sumField = fn(string $key): ?float => $sumMetric(fn(array $data): ?float => $read($data, [$key]));
        $sumFieldsForRow = fn(array $data, array $keys): ?float => $completeSum(array_map(
            fn(string $key): ?float => $read($data, [$key]),
            $keys
        ));
        $ratio = static fn(?float $numerator, ?float $denominator): ?float => $numerator !== null && $denominator !== null && $denominator > 0
            ? $numerator / $denominator
            : null;

        $otaRevenueKeys = array_map(static fn(string $key): string => $key . '_revenue', self::OTA_CHANNEL_KEYS);
        $otaRoomKeys = array_map(static fn(string $key): string => $key . '_rooms', self::OTA_CHANNEL_KEYS);
        $offlineChannels = ['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold', 'hourly'];
        $offlineRevenueKeys = array_map(static fn(string $key): string => $key . '_revenue', $offlineChannels);
        $offlineRoomKeys = array_map(static fn(string $key): string => $key . '_rooms', $offlineChannels);
        $otherRevenueKeys = ['parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue'];

        $onlineRevenueForRow = fn(array $data): ?float => $read($data, ['online_revenue']) ?? $sumFieldsForRow($data, $otaRevenueKeys);
        $onlineRoomsForRow = fn(array $data): ?float => $read($data, ['online_rooms']) ?? $sumFieldsForRow($data, $otaRoomKeys);
        $offlineRevenueForRow = fn(array $data): ?float => $read($data, ['offline_revenue']) ?? $sumFieldsForRow($data, $offlineRevenueKeys);
        $offlineRoomsForRow = fn(array $data): ?float => $read($data, ['offline_rooms']) ?? $sumFieldsForRow($data, $offlineRoomKeys);
        $otherRevenueForRow = fn(array $data): ?float => $read($data, ['other_revenue_total']) ?? $sumFieldsForRow($data, $otherRevenueKeys);
        $roomRevenueForRow = fn(array $data): ?float => $read($data, ['room_revenue'])
            ?? $completeSum([$onlineRevenueForRow($data), $offlineRevenueForRow($data)]);
        $totalRevenueForRow = fn(array $data): ?float => $read($data, ['revenue', 'day_revenue'])
            ?? $completeSum([$roomRevenueForRow($data), $otherRevenueForRow($data)]);
        $totalRoomsForRow = fn(array $data): ?float => $read($data, ['total_rooms', 'day_total_rooms'])
            ?? $completeSum([$onlineRoomsForRow($data), $offlineRoomsForRow($data)]);

        $totals = [];
        foreach (array_merge(
            $otaRevenueKeys,
            $otaRoomKeys,
            $offlineRevenueKeys,
            $offlineRoomKeys,
            $otherRevenueKeys,
            ['xb_reviewable', 'xb_good_review', 'mt_reviewable', 'mt_good_review', 'fliggy_reviewable', 'fliggy_good_review', 'xb_exposure', 'mt_exposure', 'wechat_add', 'member_add', 'private_revenue', 'private_rooms', 'member_card_sold', 'stored_value']
        ) as $key) {
            $totals[$key] = $sumField($key);
        }

        $totals['online_revenue'] = $sumMetric($onlineRevenueForRow);
        $totals['online_rooms'] = $sumMetric($onlineRoomsForRow);
        $totals['offline_revenue'] = $sumMetric($offlineRevenueForRow);
        $totals['offline_rooms'] = $sumMetric($offlineRoomsForRow);
        $totals['other_revenue_total'] = $sumMetric($otherRevenueForRow);
        $totals['room_revenue'] = $sumMetric($roomRevenueForRow);
        $totals['total_revenue'] = $sumMetric($totalRevenueForRow);
        $totals['total_rooms'] = $sumMetric($totalRoomsForRow);
        $totals['salable_rooms'] = $sumMetric(fn(array $data): ?float => $read($data, ['salable_rooms', 'total_rooms_count', 'room_count']));
        $totals['overnight_rooms'] = $sumField('overnight_rooms');
        $totals['qn_zx_revenue'] = $completeSum([$totals['qn_revenue'], $totals['zx_revenue']]);
        $totals['qn_zx_rooms'] = $completeSum([$totals['qn_rooms'], $totals['zx_rooms']]);

        $totals['occ_rate'] = $ratio($totals['total_rooms'], $totals['salable_rooms']);
        $totals['adr'] = $ratio($totals['room_revenue'], $totals['total_rooms']);
        $totals['revpar'] = $ratio($totals['room_revenue'], $totals['salable_rooms']);
        $totals['overnight_occ_rate'] = $ratio($totals['overnight_rooms'], $totals['salable_rooms']);
        $overnightRevenue = $completeSum([$totals['room_revenue'], $totals['hourly_revenue']]);
        if ($overnightRevenue !== null) {
            $overnightRevenue = $totals['room_revenue'] - $totals['hourly_revenue'];
        }
        $totals['overnight_adr'] = $ratio($overnightRevenue, $totals['overnight_rooms']);
        $totals['overnight_revpar'] = $ratio($overnightRevenue, $totals['salable_rooms']);
        $totals['ota_room_rate'] = $ratio($totals['online_rooms'], $totals['total_rooms']);
        $totals['online_adr'] = $ratio($totals['online_revenue'], $totals['online_rooms']);
        $totals['offline_adr'] = $ratio($totals['offline_revenue'], $totals['offline_rooms']);
        $totals['review_orders'] = $completeSum([$totals['xb_reviewable'], $totals['mt_reviewable'], $totals['fliggy_reviewable']]);
        $totals['good_reviews'] = $completeSum([$totals['xb_good_review'], $totals['mt_good_review'], $totals['fliggy_good_review']]);
        $totals['good_review_rate'] = $ratio($totals['good_reviews'], $totals['review_orders']);

        // 当前项目没有已验证的微信加粉率分母，不再用钟点房冲减加粉数伪造比率。
        $totals['wechat_add_rate'] = null;
        foreach (['xb_exp_rate', 'xb_bk_rate', 'xb_clinch_rate', 'mt_click_rate', 'mt_pay_rate'] as $rateKey) {
            $totals[$rateKey] = null;
        }
        $totals['data_status'] = $totals['total_revenue'] !== null
            && $totals['room_revenue'] !== null
            && $totals['total_rooms'] !== null
            && $totals['salable_rooms'] !== null
            ? 'ready'
            : ($rows === [] ? 'missing' : 'partial');
        $totals['data_gaps'] = [];
        if ($totals['total_revenue'] === null) {
            $totals['data_gaps'][] = 'export_total_revenue_incomplete';
        }
        if ($totals['total_rooms'] === null || $totals['salable_rooms'] === null) {
            $totals['data_gaps'][] = 'export_occupancy_denominator_incomplete';
        }

        return $totals;
    }
    
    /**
     * 格式化数字
     */
    private function fmtNum($value, int $decimals = 2): string
    {
        if (!is_numeric($value)) {
            return '—';
        }
        return number_format((float)$value, $decimals, '.', ',');
    }
    
    /**
     * 格式化百分比
     */
    private function fmtPct($value): string
    {
        if (!is_numeric($value)) {
            return '—';
        }
        return number_format((float)$value * 100, 2, '.', ',') . '%';
    }
    
    /**
     * 格式化数字（旧方法兼容）
     */
    private function formatNumber($value): string
    {
        return $this->fmtNum($value);
    }
    
    /**
     * HTML转义
     */
    private function escapeHtml(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    private function dailyReportsHasColumn(string $column): bool
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $rows = Db::query('SHOW COLUMNS FROM daily_reports');
                $columns = array_fill_keys(array_column($rows, 'Field'), true);
            } catch (\Throwable $e) {
                $columns = [];
            }
        }

        return isset($columns[$column]);
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            return 0;
        }

        $query = $this->currentUser && $this->currentUser->isSuperAdmin()
            ? Hotel::withoutTenantScope()
            : Hotel::where([]);

        return max(0, (int)$query->where('id', $hotelId)->value('tenant_id'));
    }

    /**
     * 检查权限
     */
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        // 非超级管理员必须有酒店关联
        $this->requireHotel();
    }

    /**
     * 仅超级管理员可用
     */
    private function checkSuperAdmin(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '无权限操作');
        }
    }

    /**
     * 获取日报查看映射配置（仅超级管理员）
     */
    public function getViewMapping(): Response
    {
        $this->checkSuperAdmin();
        return $this->success($this->getViewMappingConfig());
    }

    /**
     * 保存日报查看映射配置（仅超级管理员）
     */
    public function saveViewMapping(): Response
    {
        $this->checkSuperAdmin();
        $data = $this->requestData();
        $mapping = $data['mapping'] ?? null;
        if (!is_array($mapping)) {
            return $this->error('映射配置格式错误');
        }

        $allowedSources = ['report', 'task', 'month', 'calc'];
        $clean = [];
        foreach ($mapping as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            $source = trim((string)($item['source'] ?? 'report'));
            $field = trim((string)($item['field'] ?? ''));
            $formula = trim((string)($item['formula'] ?? ''));
            $template = trim((string)($item['template'] ?? ''));

            if ($name === '' && $field === '' && $formula === '' && $template === '') {
                continue;
            }
            if (!in_array($source, $allowedSources, true)) {
                $source = 'report';
            }
            $clean[] = [
                'name' => $name,
                'source' => $source,
                'field' => $field,
                'formula' => $formula,
                'template' => $template,
            ];
        }

        SystemConfig::setValue('daily_report_view_mapping', json_encode($clean, JSON_UNESCAPED_UNICODE), '日报表查看映射配置');
        OperationLog::record('daily_report', 'save_view_mapping', '保存日报查看映射配置', $this->currentUser->id);

        return $this->success($clean, '保存成功');
    }

    /**
     * 解析导入的Excel文件（JY01经理报表）
     */
    /**
     * 解析Excel文件 - 返回原始结构化数据供前端处理
     */
    public function parseImport(): Response
    {
        $this->checkPermission();

        $file = $this->request->file('file');
        $hotelId = $this->request->post('hotel_id');
        if (!$hotelId && !$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
        }
        if (!is_numeric($hotelId) || (int)$hotelId <= 0) {
            throw new \think\exception\HttpException(403, '请选择有权限的门店');
        }
        $this->currentUser->hasHotelPermissionOrFail(
            (int)$hotelId,
            'can_fill_daily_report',
            '无权导入该酒店日报'
        );
        
        if (!$file) {
            return $this->error('请上传文件');
        }

        $sourcePath = method_exists($file, 'getPathname') ? (string)$file->getPathname() : '';
        $originalName = method_exists($file, 'getOriginalName') ? (string)$file->getOriginalName() : '';
        $fileSize = method_exists($file, 'getSize') ? (int)$file->getSize() : (is_file($sourcePath) ? (int)filesize($sourcePath) : 0);
        $validationError = $this->validateDailyImportUpload($sourcePath, $originalName, $fileSize);
        if ($validationError !== null) {
            $status = str_contains($validationError, '超过') ? 413 : 422;
            return $this->error($validationError, $status);
        }

        // 保存临时文件
        $tempPath = runtime_path() . 'upload/' . uniqid() . '.xlsx';
        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }
        $file->move(dirname($tempPath), basename($tempPath));

        try {
            // 使用Python解析Excel（更可靠）
            $data = $this->parseExcelWithPython($tempPath);
            
            // 删除临时文件
            @unlink($tempPath);
            
            // 应用字段映射（传入hotel_id）
            $hotelIdInt = $hotelId ? (int)$hotelId : null;
            $mappingResult = $this->applyFieldMapping($data['structured_data'] ?? [], $hotelIdInt);
            
            // 获取该门店的映射配置（供前端使用）
            $allMappings = \app\model\FieldMapping::getActiveMappings($hotelIdInt);
            $mappingList = [];
            foreach ($allMappings as $mapping) {
                $mappingList[] = [
                    'id' => $mapping['id'],
                    'hotel_id' => $mapping['hotel_id'],
                    'excel_item_name' => $mapping['excel_item_name'],
                    'system_field' => $mapping['system_field'],
                    'field_type' => $mapping['field_type'],
                    'value_column' => $mapping['value_column'] ?? 'E',
                    'row_num' => $mapping['row_num'],
                    'category' => $mapping['category'] ?? '',
                ];
            }
            
            return $this->success([
                'hotel_name' => $data['hotel_name'] ?? '',
                'report_date' => $data['report_date'] ?? '',
                'raw_data' => $data['raw_data'] ?? [],
                'structured_data' => $data['structured_data'] ?? [],
                'mapped_data' => $mappingResult['mapped'] ?? [],
                'merge_details' => $mappingResult['merge_details'] ?? [],
                'unmatched_items' => $mappingResult['unmatched'] ?? [],
                'match_suggestions' => $mappingResult['suggestions'] ?? [],
                'template' => $mappingResult['template'] ?? null,
                'total_rows' => $data['total_rows'] ?? 0,
                'total_items' => $data['total_items'] ?? 0,
                'matched_count' => count($mappingResult['mapped'] ?? []),
                'unmatched_count' => count($mappingResult['unmatched'] ?? []),
            ]);
        } catch (\Exception $e) {
            @unlink($tempPath);
            return $this->error('解析文件失败：' . $e->getMessage());
        }
    }

    private function validateDailyImportUpload(string $path, string $originalName, int $size): ?string
    {
        if ($path === '' || !is_file($path)) {
            return '上传的Excel文件不存在';
        }

        $actualSize = $size > 0 ? $size : (int)filesize($path);
        if ($actualSize <= 0) {
            return '上传的Excel文件为空';
        }
        if ($actualSize > self::IMPORT_XLSX_MAX_BYTES) {
            return 'Excel文件超过5MB';
        }

        $extension = strtolower(pathinfo($originalName !== '' ? $originalName : $path, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            return '仅支持.xlsx格式的日报Excel文件';
        }

        return $this->validateDailyImportZipArchive($path);
    }

    private function validateDailyImportZipArchive(string $path): ?string
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($path, \ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            return 'Excel文件结构异常，请上传有效的.xlsx文件';
        }

        if ($zip->numFiles <= 0) {
            $zip->close();
            return 'Excel文件内容为空';
        }
        if ($zip->numFiles > self::IMPORT_XLSX_MAX_ZIP_ENTRIES) {
            $zip->close();
            return 'Excel文件内容项过多';
        }

        $totalUncompressedBytes = 0;
        $hasWorkbook = false;
        $hasWorksheet = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                return 'Excel文件结构异常，请重新导出后上传';
            }

            $entryName = str_replace('\\', '/', (string)($stat['name'] ?? ''));
            if ($entryName === '' || str_starts_with($entryName, '/') || str_contains($entryName, '../')) {
                $zip->close();
                return 'Excel文件包含非法路径';
            }

            if ($entryName === 'xl/workbook.xml') {
                $hasWorkbook = true;
            }
            if (str_starts_with($entryName, 'xl/worksheets/') && str_ends_with($entryName, '.xml')) {
                $hasWorksheet = true;
            }

            $entrySize = (int)($stat['size'] ?? 0);
            if ($entrySize > self::IMPORT_XLSX_MAX_ENTRY_BYTES) {
                $zip->close();
                return 'Excel文件单个内容项超过8MB';
            }
            $totalUncompressedBytes += max(0, $entrySize);
            if ($totalUncompressedBytes > self::IMPORT_XLSX_MAX_UNCOMPRESSED_BYTES) {
                $zip->close();
                return 'Excel文件解压后内容超过20MB';
            }
        }

        $zip->close();
        if (!$hasWorkbook || !$hasWorksheet) {
            return 'Excel文件缺少工作簿或工作表数据';
        }

        return null;
    }

    /**
     * 使用Python解析Excel（更可靠）
     */
    private function parseExcelWithPython(string $filePath): array
    {
        $pythonScript = <<<'PYTHON'
import sys
import zipfile
import re
import json
import io

MAX_ZIP_ENTRIES = 256
MAX_ZIP_ENTRY_BYTES = 8 * 1024 * 1024
MAX_ZIP_UNCOMPRESSED_BYTES = 20 * 1024 * 1024

# 强制 stdout 使用 UTF-8，避免 Windows 默认 GBK 编码失败
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

def enforce_zip_limits(zf):
    infos = zf.infolist()
    if not infos:
        raise ValueError('Excel文件内容为空')
    if len(infos) > MAX_ZIP_ENTRIES:
        raise ValueError('Excel文件内容项过多')
    total_size = 0
    for info in infos:
        name = info.filename.replace('\\', '/')
        if not name or name.startswith('/') or '../' in name:
            raise ValueError('Excel文件包含非法路径')
        if info.file_size > MAX_ZIP_ENTRY_BYTES:
            raise ValueError('Excel文件单个内容项超过8MB')
        total_size += max(0, info.file_size)
        if total_size > MAX_ZIP_UNCOMPRESSED_BYTES:
            raise ValueError('Excel文件解压后内容超过20MB')

def parse_xlsx(file_path):
    result = {
        'file_info': {},
        'raw_data': [],
        'structured_data': [],
        'hotel_name': '',
        'report_date': '',
    }
    
    try:
        with zipfile.ZipFile(file_path, 'r') as zf:
            enforce_zip_limits(zf)
            # 读取sharedStrings
            shared_strings = []
            try:
                ss_content = zf.read('xl/sharedStrings.xml').decode('utf-8')
                # 提取所有 <si> 块
                si_matches = re.findall(r'<si[^>]*>(.*?)</si>', ss_content, re.DOTALL)
                for si_content in si_matches:
                    # 提取该 si 块中的所有 <t> 内容并拼接
                    t_matches = re.findall(r'<t[^>]*>([^<]*)</t>', si_content)
                    text = ''.join(t_matches).strip()
                    shared_strings.append(text)
            except Exception as e:
                pass
            
            # 读取sheet1
            sheet_content = zf.read('xl/worksheets/sheet1.xml').decode('utf-8')
            
            # 解析行
            rows = {}
            row_matches = re.findall(r'<row\s+r="(\d+)"[^>]*>(.*?)</row>', sheet_content, re.DOTALL)
            for row_num_str, row_content in row_matches:
                row_num = int(row_num_str)
                cells = {}
                
                # 移除自闭合标签（无值的单元格），避免干扰正则匹配
                row_content = re.sub(r'<c\s+[^>]*/>', '', row_content)
                
                # 提取单元格
                cell_matches = re.findall(r'<c\s+([^>]+)>(.*?)</c>', row_content, re.DOTALL)
                for attrs, cell_content in cell_matches:
                    # 提取列名
                    r_match = re.search(r'r="([A-Z]+)\d+"', attrs)
                    if not r_match:
                        continue
                    col = r_match.group(1)
                    
                    # 检查是否是字符串类型
                    is_string = 't="s"' in attrs
                    
                    # 提取值
                    v_match = re.search(r'<v>([^<]*)</v>', cell_content)
                    value = v_match.group(1) if v_match else ''
                    
                    # 如果是字符串类型，从共享字符串中获取
                    if is_string and value.isdigit():
                        idx = int(value)
                        if idx < len(shared_strings):
                            value = shared_strings[idx]
                    
                    cells[col] = value
                
                if cells:
                    rows[row_num] = cells
            
            # 提取基本信息（第1-3行）
            for i in [1, 2, 3]:
                if i in rows:
                    a_val = rows[i].get('A', '')
                    # 提取酒店名
                    if '门店' in a_val:
                        match = re.search(r'门店[：:]\s*([^\s\t]+)', a_val)
                        if match:
                            result['hotel_name'] = match.group(1)
                    # 提取日期
                    if '营业日' in a_val:
                        match = re.search(r'(\d{4}-\d{2}-\d{2})', a_val)
                        if match:
                            result['report_date'] = match.group(1)
            
            # 构建结构化数据，大类继承（合并单元格效果）
            # 格式：A=类别，B=项目名，返回所有列数据供PHP根据配置取值
            structured = []
            current_category = ''
            for row_num in sorted(rows.keys()):
                row = rows[row_num]
                category = row.get('A', '').strip()
                item_name = row.get('B', '').strip()
                
                # 如果大类不为空，更新当前大类
                if category:
                    current_category = category
                
                if item_name:  # 只要有项目名就算有效行
                    structured.append({
                        'row': row_num,
                        'category': current_category,  # 使用继承的大类
                        'item_name': item_name,
                        'cells': row,  # 返回所有列数据
                    })
            
            result['raw_data'] = [{k: v for k, v in rows.items() if int(k) <= 50}]  # 只返回前50行原始数据
            result['structured_data'] = structured
            result['shared_strings_sample'] = shared_strings[:50]
            result['total_rows'] = len(rows)
            result['total_items'] = len(structured)
            
    except Exception as e:
        result['error'] = str(e)
    
    return result

# 执行解析
file_path = sys.argv[1]
result = parse_xlsx(file_path)
output = json.dumps(result, ensure_ascii=False)
# 避免 GBK 编码错误，直接写入 utf-8 字节
sys.stdout.buffer.write(output.encode('utf-8'))
PYTHON;

        // 保存Python脚本
        $scriptPath = runtime_path() . 'parse_excel.py';
        file_put_contents($scriptPath, $pythonScript);
        
        // 执行Python脚本（兼容 Windows 上仅有 python 命令的情况）
        $commands = ['python3', 'python'];
        $lastOutput = '';
        foreach ($commands as $command) {
            $run = $this->runExcelParserCommand($command, $scriptPath, $filePath);
            $lastOutput = $run['stdout'] !== '' ? $run['stdout'] : $run['stderr'];
            if (empty($lastOutput)) {
                continue;
            }
            $data = json_decode($lastOutput, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($data['error'])) {
                    throw new \Exception('解析Excel失败：' . $data['error']);
                }
                return $data;
            }
        }
        
        throw new \Exception('解析Excel失败：' . ($lastOutput ?: '未获取到解析结果，请检查Python环境'));
    }

    private function runExcelParserCommand(string $pythonBinary, string $scriptPath, string $filePath): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([$pythonBinary, $scriptPath, $filePath], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => '无法启动Python解析进程', 'exit_code' => 1];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
            'exit_code' => $exitCode,
        ];
    }

    /**
     * 应用字段映射（使用门店模板，支持项目合并）
     */
    private function applyFieldMapping(array $structuredData, ?int $hotelId = null): array
    {
        // 从 field_mappings 表获取映射配置（优先门店专属，其次全局）
        $mappings = \app\model\FieldMapping::getActiveMappings($hotelId);
        
        // 获取所有百分比字段（单位为%的字段）
        $percentFields = \think\facade\Db::name('report_configs')
            ->where('unit', '%')
            ->column('field_name');
        
        // 构建映射查找表：按项目名分组（支持同名项目）
        $mappingByItem = [];
        foreach ($mappings as $mapping) {
            $name = $mapping['excel_item_name'];
            if (!isset($mappingByItem[$name])) {
                $mappingByItem[$name] = [];
            }
            $mappingByItem[$name][] = $mapping;
        }
        
        // 用于合并的数据：[system_field => ['values'=>[], 'merge_rule'=>'sum']]
        $mergedData = [];
        $result = [];
        $unmatched = [];
        $dataGaps = [];
        
        foreach ($structuredData as $item) {
            $itemName = $item['item_name'] ?? '';
            $rowNum = $item['row'] ?? 0;
            $cells = $item['cells'] ?? [];
            $category = $item['category'] ?? '';
            
            // 跳过表头
            if (empty($itemName) || $itemName === '项目') {
                continue;
            }
            
            // 精确匹配项目名
            $matched = false;
            if (isset($mappingByItem[$itemName])) {
                // 遍历所有同名配置，找到匹配的（优先按行号匹配）
                foreach ($mappingByItem[$itemName] as $mapping) {
                    $mappingRowNum = $mapping['row_num'] ?? null;
                    $valueColumn = $mapping['value_column'] ?? 'E';
                    
                    // 检查行号是否匹配（如果映射配置了行号，则必须精确匹配）
                    $rowMatch = ($mappingRowNum === null) || ($mappingRowNum == $rowNum);
                    
                    if ($rowMatch) {
                        $systemField = $mapping['system_field'];
                        $fieldType = $mapping['field_type'];
                        
                        // 从cells中根据列号取值
                        $rawValue = $cells[$valueColumn] ?? null;
                        // 根据目标字段的单位判断是否为百分比字段
                        $isPercentField = in_array($systemField, $percentFields);
                        $value = is_scalar($rawValue)
                            ? ($isPercentField ? $this->parsePercent((string)$rawValue) : $this->parseNumber((string)$rawValue))
                            : null;
                        if ($value === null) {
                            $dataGaps[] = [
                                'code' => 'import_mapped_value_missing',
                                'message' => "第 {$rowNum} 行 {$valueColumn} 列未提供有效数值，未写入 {$systemField}。",
                                'row' => $rowNum,
                                'column' => $valueColumn,
                                'system_field' => $systemField,
                            ];
                            $matched = true;
                            break;
                        }
                        
                        // 存储数据（直接存储，同名项目自动覆盖）
                        if (!isset($mergedData[$systemField])) {
                            $mergedData[$systemField] = ['values' => [], 'field_type' => $fieldType];
                        }
                        $mergedData[$systemField]['values'][] = [
                            'value' => $value,
                            'item_name' => $itemName,
                            'category' => $category,
                            'row' => $rowNum,
                            'column' => $valueColumn,
                            'mapping_id' => $mapping['id'],
                        ];
                        $matched = true;
                        break;
                    }
                }
            }
            
            if ($matched) {
                continue;
            }
            
            // 未匹配的项目（取E列作为默认值显示）
            $rawValue = $cells['E'] ?? null;
            $valueToday = is_scalar($rawValue) ? $this->parseNumber((string)$rawValue) : null;
            if ($valueToday !== null) {
                $unmatched[] = [
                    'row' => $rowNum,
                    'item_name' => $itemName,
                    'category' => $category,
                    'cells' => $cells,
                    'value_today' => (string)$rawValue,  // 兼容 generateMatchSuggestions
                ];
            }
        }
        
        // 执行合并计算（同名系统字段取最后一个值）
        foreach ($mergedData as $systemField => $data) {
            $values = array_column($data['values'], 'value');
            // 对于同名系统字段，取最后一个有效值（或者可以改为求和）
            $result[$systemField] = $values !== [] ? end($values) : null;
        }
        
        // 计算派生字段
        $this->calculateDerivedFields($result);
        
        // 过滤只保留有效字段
        $validFields = $this->getValidReportFields();
        $filteredData = [];
        foreach ($result as $key => $value) {
            if (in_array($key, $validFields)) {
                $filteredData[$key] = $value;
            }
        }
        
        // 生成映射详情
        $mappingDetails = [];
        foreach ($mergedData as $systemField => $data) {
            if (!empty($data['values'])) {
                $lastItem = end($data['values']);
                $mappingDetails[] = [
                    'system_field' => $systemField,
                    'item_count' => count($data['values']),
                    'items' => $data['values'],
                    'result' => $filteredData[$systemField] ?? null,
                ];
            }
        }
        
        // 为未匹配项生成智能匹配建议
        $suggestions = $this->generateMatchSuggestions($unmatched, $validFields);
        
        return [
            'mapped' => $filteredData,
            'merge_details' => $mappingDetails,
            'unmatched' => $unmatched,
            'suggestions' => $suggestions,
            'mapping_count' => count($mappings),
            'data_status' => $dataGaps === [] ? 'ready' : 'partial',
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * 为未匹配项生成智能匹配建议
     */
    private function generateMatchSuggestions(array $unmatched, array $validFields): array
    {
        // 获取系统字段显示名称映射
        $fieldLabels = [];
        $configs = \app\model\ReportConfig::where('report_type', 'daily')
            ->where('status', 1)
            ->select();
        foreach ($configs as $config) {
            $fieldLabels[$config->field_name] = $config->display_name;
        }
        
        $suggestions = [];
        foreach ($unmatched as $item) {
            $itemName = $item['item_name'];
            $itemLower = mb_strtolower($itemName);
            $bestMatches = [];
            
            foreach ($validFields as $field) {
                $label = $fieldLabels[$field] ?? $field;
                $labelLower = mb_strtolower($label);
                
                $score = 0;
                
                // 精确包含
                if (strpos($labelLower, $itemLower) !== false || strpos($itemLower, $labelLower) !== false) {
                    $score = 80;
                }
                
                // 相似度计算
                similar_text($itemLower, $labelLower, $percent);
                $score = max($score, (int)$percent);
                
                // 特殊匹配规则
                if (strpos($itemLower, '间夜') !== false && strpos($field, 'rooms') !== false) {
                    $score = max($score, 75);
                }
                if (strpos($itemLower, '收入') !== false && strpos($field, 'revenue') !== false) {
                    $score = max($score, 70);
                }
                if (strpos($itemLower, '出租率') !== false && strpos($field, 'occ') !== false) {
                    $score = max($score, 75);
                }
                
                if ($score >= 50) {
                    $bestMatches[] = [
                        'field' => $field,
                        'label' => $label,
                        'score' => $score,
                    ];
                }
            }
            
            // 按分数排序，取前3个
            usort($bestMatches, function($a, $b) {
                return $b['score'] - $a['score'];
            });
            
            $suggestions[] = [
                'item_name' => $itemName,
                'value_today' => $item['value_today'] ?? ($item['cells']['E'] ?? ''),
                'suggestions' => array_slice($bestMatches, 0, 3),
            ];
        }
        
        return $suggestions;
    }



    /**
     * 获取有效的报表字段列表（从配置中）
     */
    private function getValidReportFields(): array
    {
        $fields = Db::table('report_configs')
            ->where('report_type', 'daily')
            ->where('status', 1)
            ->column('field_name');
        return $fields;
    }


    /**
     * 计算派生字段
     */
    private function calculateDerivedFields(array &$data): void
    {
        $deriveCompleteSum = function (array $keys) use (&$data): ?float {
            $sum = 0.0;
            foreach ($keys as $key) {
                $value = $this->readNumericValue($data, [$key]);
                if ($value === null) {
                    return null;
                }
                $sum += $value;
            }
            return $sum;
        };

        // 计算可售房数 = 总房数 - 维修房数
        if (!array_key_exists('salable_rooms', $data)) {
            $totalRoomCount = $this->readNumericValue($data, ['total_rooms_count']);
            $maintenanceRooms = $this->readNumericValue($data, ['maintenance_rooms']);
            if ($totalRoomCount !== null && $maintenanceRooms !== null && $totalRoomCount >= $maintenanceRooms) {
                $data['salable_rooms'] = $totalRoomCount - $maintenanceRooms;
            }
        }
        
        // 如果有过夜房和钟点房，计算总间夜数
        if (!array_key_exists('total_rooms', $data)) {
            $overnight = $this->readNumericValue($data, ['overnight_rooms']);
            $hourly = $this->readNumericValue($data, ['hourly_rooms']);
            if ($overnight !== null && $hourly !== null) {
                $data['total_rooms'] = $overnight + $hourly;
            }
        }

        // 计算线上合计
        $onlineRevenueKeys = array_map(static fn(string $channel): string => $channel . '_revenue', self::OTA_CHANNEL_KEYS);
        $onlineRoomKeys = array_map(static fn(string $channel): string => $channel . '_rooms', self::OTA_CHANNEL_KEYS);
        if (!array_key_exists('online_revenue', $data)) {
            $onlineRevenue = $deriveCompleteSum($onlineRevenueKeys);
            if ($onlineRevenue !== null) {
                $data['online_revenue'] = $onlineRevenue;
            }
        }
        if (!array_key_exists('online_rooms', $data)) {
            $onlineRooms = $deriveCompleteSum($onlineRoomKeys);
            if ($onlineRooms !== null) {
                $data['online_rooms'] = $onlineRooms;
            }
        }

        // 计算线下合计（含钟点房，与日报详情口径一致）
        $offlineChannels = ['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold', 'hourly'];
        $offlineRevenueKeys = array_map(static fn(string $channel): string => $channel . '_revenue', $offlineChannels);
        $offlineRoomKeys = array_map(static fn(string $channel): string => $channel . '_rooms', $offlineChannels);
        if (!array_key_exists('offline_revenue', $data)) {
            $offlineRevenue = $deriveCompleteSum($offlineRevenueKeys);
            if ($offlineRevenue !== null) {
                $data['offline_revenue'] = $offlineRevenue;
            }
        }
        if (!array_key_exists('offline_rooms', $data)) {
            $offlineRooms = $deriveCompleteSum($offlineRoomKeys);
            if ($offlineRooms !== null) {
                $data['offline_rooms'] = $offlineRooms;
            }
        }

        // 计算平均房价
        if (!array_key_exists('adr', $data)) {
            $roomRevenue = $this->readNumericValue($data, ['room_revenue']);
            $totalRooms = $this->readNumericValue($data, ['total_rooms']);
            if ($roomRevenue !== null && $totalRooms !== null && $totalRooms > 0) {
                $data['adr'] = $roomRevenue / $totalRooms;
            }
        }

        // 计算OTA间夜占比
        if (!array_key_exists('ota_room_rate', $data)) {
            $onlineRooms = $this->readNumericValue($data, ['online_rooms']);
            $totalRooms = $this->readNumericValue($data, ['total_rooms']);
            if ($onlineRooms !== null && $totalRooms !== null && $totalRooms > 0) {
                $data['ota_room_rate'] = $onlineRooms / $totalRooms;
            }
        }
    }


    /**
     * 解析数字
     */
    private function parseNumber(string $value): ?float
    {
        $value = trim(str_replace([',', "\u{00A0}"], '', $value));
        if ($value === '') {
            return null;
        }
        $isPercent = strpos($value, '%') !== false;
        $numeric = trim(str_replace('%', '', $value));
        if ($numeric === '' || !is_numeric($numeric)) {
            return null;
        }
        $number = (float)$numeric;
        return $isPercent ? $number / 100 : $number;
    }
    
    /**
     * 解析百分比为显示值
     */
    private function parsePercent(string $value): ?float
    {
        $value = trim(str_replace([',', "\u{00A0}"], '', $value));
        if ($value === '') {
            return null;
        }
        $numeric = trim(str_replace('%', '', $value));
        if ($numeric === '' || !is_numeric($numeric)) {
            return null;
        }
        $num = (float)$numeric;
        // 0~1 视为小数比例；其他数值按百分数原值保留。
        return strpos($value, '%') === false && $num >= 0 && $num <= 1 ? $num * 100 : $num;
    }
}
