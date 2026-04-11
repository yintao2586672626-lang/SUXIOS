<?php
declare(strict_types=1);

namespace app\controller;

use app\model\DailyReport as DailyReportModel;
use app\model\MonthlyTask;
use app\model\Hotel;
use app\model\OperationLog;
use app\model\SystemConfig;
use think\exception\ValidateException;
use think\Response;
use think\facade\Db;

class DailyReport extends Base
{
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
        $reportData = $report->report_data ?? [];
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
        $result = $this->calculateReportDetail($hotel, $reportData, $taskData, $monthSum, $reportDate, $day, $monthDays);

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
        foreach ($reports as $report) {
            $data = $report->report_data ?? [];
            foreach ($data as $key => $value) {
                if (!isset($sum[$key])) {
                    $sum[$key] = 0;
                }
                $num = $this->normalizeNumber($value);
                if ($num !== null) {
                    $sum[$key] += $num;
                }
            }
        }
        return $sum;
    }
    
    /**
     * 计算报表详情数据
     */
    private function calculateReportDetail($hotel, $reportData, $taskData, $monthSum, $reportDate, $day, $monthDays): array
    {
        // 辅助函数：安全获取数值
        $getVal = function($data, $key, $default = 0) {
            if (!isset($data[$key])) {
                return $default;
            }
            $num = $this->normalizeNumber($data[$key]);
            return $num !== null ? $num : $default;
        };
        
        // 酒店房间数（需要从酒店信息或配置获取，这里先默认）
        $totalRooms = $getVal($taskData, 'salable_rooms_total', 59); // 可售房间总数
        
        // ==================== 一、销售业绩 ====================
        
        // OTA收入
        $xbRevenue = $getVal($reportData, 'xb_revenue');
        $mtRevenue = $getVal($reportData, 'mt_revenue');
        $fliggyRevenue = $getVal($reportData, 'fliggy_revenue');
        $dyRevenue = $getVal($reportData, 'dy_revenue');
        $tcRevenue = $getVal($reportData, 'tc_revenue');
        $qnRevenue = $getVal($reportData, 'qn_revenue');
        $zxRevenue = $getVal($reportData, 'zx_revenue');
        
        // 线下收入
        $walkinRevenue = $getVal($reportData, 'walkin_revenue');
        $memberExpRevenue = $getVal($reportData, 'member_exp_revenue');
        $webExpRevenue = $getVal($reportData, 'web_exp_revenue');
        $groupRevenue = $getVal($reportData, 'group_revenue');
        $protocolRevenue = $getVal($reportData, 'protocol_revenue');
        $wechatRevenue = $getVal($reportData, 'wechat_revenue');
        $freeRevenue = $getVal($reportData, 'free_revenue');
        $goldCardRevenue = $getVal($reportData, 'gold_card_revenue');
        $blackGoldRevenue = $getVal($reportData, 'black_gold_revenue');
        $hourlyRevenue = $getVal($reportData, 'hourly_revenue');
        
        // 其他收入
        $parkingRevenue = $getVal($reportData, 'parking_revenue');
        $diningRevenue = $getVal($reportData, 'dining_revenue');
        $meetingRevenue = $getVal($reportData, 'meeting_revenue');
        $goodsRevenue = $getVal($reportData, 'goods_revenue');
        $memberCardRevenue = $getVal($reportData, 'member_card_revenue');
        $otherRevenue = $getVal($reportData, 'other_revenue');
        
        // OTA间夜
        $xbRooms = $getVal($reportData, 'xb_rooms');
        $mtRooms = $getVal($reportData, 'mt_rooms');
        $fliggyRooms = $getVal($reportData, 'fliggy_rooms');
        $dyRooms = $getVal($reportData, 'dy_rooms');
        $tcRooms = $getVal($reportData, 'tc_rooms');
        $qnRooms = $getVal($reportData, 'qn_rooms');
        $zxRooms = $getVal($reportData, 'zx_rooms');
        
        // 线下间夜
        $walkinRooms = $getVal($reportData, 'walkin_rooms');
        $memberExpRooms = $getVal($reportData, 'member_exp_rooms');
        $webExpRooms = $getVal($reportData, 'web_exp_rooms');
        $groupRooms = $getVal($reportData, 'group_rooms');
        $protocolRooms = $getVal($reportData, 'protocol_rooms');
        $wechatRooms = $getVal($reportData, 'wechat_rooms');
        $freeRooms = $getVal($reportData, 'free_rooms');
        $goldCardRooms = $getVal($reportData, 'gold_card_rooms');
        $blackGoldRooms = $getVal($reportData, 'black_gold_rooms');
        $hourlyRooms = $getVal($reportData, 'hourly_rooms');
        
        // 基础数据
        $salableRooms = $getVal($reportData, 'salable_rooms', $totalRooms);
        $overnightRooms = $getVal($reportData, 'overnight_rooms', 0);
        
        // 私域数据
        $wechatAdd = $getVal($reportData, 'wechat_add');
        $memberCardSold = $getVal($reportData, 'member_card_sold');
        $privateRevenue = $getVal($reportData, 'private_revenue');
        $privateRooms = $getVal($reportData, 'private_rooms');
        $storedValue = $getVal($reportData, 'stored_value');
        
        // 大美卡
        $dameiCardCount = $getVal($reportData, 'damei_card_count');
        $cashIncome = $getVal($reportData, 'cash_income');
        
        // 明日预订
        $tomorrowBooking = $getVal($reportData, 'tomorrow_booking');
        
        // OTA点评
        $xbReviewable = $getVal($reportData, 'xb_reviewable');
        $xbGoodReview = $getVal($reportData, 'xb_good_review');
        $xbBadReview = $getVal($reportData, 'xb_bad_review');
        $mtReviewable = $getVal($reportData, 'mt_reviewable');
        $mtGoodReview = $getVal($reportData, 'mt_good_review');
        $mtBadReview = $getVal($reportData, 'mt_bad_review');
        $fliggyReviewable = $getVal($reportData, 'fliggy_reviewable');
        $fliggyGoodReview = $getVal($reportData, 'fliggy_good_review');
        $fliggyBadReview = $getVal($reportData, 'fliggy_bad_review');
        
        // ===== 日计算数据 =====
        
        // 日线上总收入 = 所有OTA收入
        $dayOnlineRevenue = $xbRevenue + $mtRevenue + $fliggyRevenue + $dyRevenue + $tcRevenue + $qnRevenue + $zxRevenue;
        
        // 日线下总收入
        $dayOfflineRevenue = $walkinRevenue + $memberExpRevenue + $webExpRevenue + $groupRevenue + 
                             $protocolRevenue + $wechatRevenue + $freeRevenue + $goldCardRevenue + 
                             $blackGoldRevenue + $hourlyRevenue;
        
        // 日其他收入
        $dayOtherRevenue = $parkingRevenue + $diningRevenue + $meetingRevenue + $goodsRevenue + $memberCardRevenue + $otherRevenue;
        
        // 日房费总收入 = 线上 + 线下
        $dayRoomRevenue = $dayOnlineRevenue + $dayOfflineRevenue;
        
        // 日总营收 = 房费 + 其他
        $dayTotalRevenue = $dayRoomRevenue + $dayOtherRevenue;
        
        // 日出租总间数
        $dayTotalRooms = $xbRooms + $mtRooms + $fliggyRooms + $dyRooms + $tcRooms + $qnRooms + $zxRooms +
                        $walkinRooms + $memberExpRooms + $webExpRooms + $groupRooms + 
                        $protocolRooms + $wechatRooms + $freeRooms + $goldCardRooms + $blackGoldRooms;
        
        // 日过夜房间数
        $dayOvernightRooms = $overnightRooms > 0 ? $overnightRooms : $dayTotalRooms;
        $dayNonOvernightRooms = max(0, $dayTotalRooms - $dayOvernightRooms - $hourlyRooms);
        
        // 日综合出租率 = (日出租总间数 / 可售房间数) * 100
        $dayOccRate = $salableRooms > 0 ? round(($dayTotalRooms / $salableRooms) * 100, 2) : 0;
        
        // 日过夜出租率
        $dayOvernightOccRate = $salableRooms > 0 ? round(($dayOvernightRooms / $salableRooms) * 100, 2) : 0;
        
        // 日均价 ADR = 日房费收入 / 日出租总间数
        $dayAdr = $dayTotalRooms > 0 ? round($dayRoomRevenue / $dayTotalRooms, 2) : 0;
        
        // 日Revpar = 日房费收入 / 可售房间数
        $dayRevpar = $salableRooms > 0 ? round($dayRoomRevenue / $salableRooms, 2) : 0;
        
        // OTA总量
        $otaTotalRooms = $xbRooms + $mtRooms + $fliggyRooms + $dyRooms + $tcRooms + $qnRooms + $zxRooms;
        
        // 日好评数
        $dayGoodReview = $xbGoodReview + $mtGoodReview + $fliggyGoodReview;
        $dayBadReview = $xbBadReview + $mtBadReview + $fliggyBadReview;
        
        // ===== 月累计计算 =====
        
        // 月线上收入
        $monthOnlineRevenue = $getVal($monthSum, 'xb_revenue') + $getVal($monthSum, 'mt_revenue') + 
                              $getVal($monthSum, 'fliggy_revenue') + $getVal($monthSum, 'dy_revenue') + 
                              $getVal($monthSum, 'tc_revenue') + $getVal($monthSum, 'qn_revenue') + 
                              $getVal($monthSum, 'zx_revenue');
        
        // 月线下收入
        $monthOfflineRevenue = $getVal($monthSum, 'walkin_revenue') + $getVal($monthSum, 'member_exp_revenue') + 
                               $getVal($monthSum, 'web_exp_revenue') + $getVal($monthSum, 'group_revenue') + 
                               $getVal($monthSum, 'protocol_revenue') + $getVal($monthSum, 'wechat_revenue') + 
                               $getVal($monthSum, 'free_revenue') + $getVal($monthSum, 'gold_card_revenue') + 
                               $getVal($monthSum, 'black_gold_revenue') + $getVal($monthSum, 'hourly_revenue');
        
        // 月其他收入
        $monthOtherRevenue = $getVal($monthSum, 'parking_revenue') + $getVal($monthSum, 'dining_revenue') + 
                             $getVal($monthSum, 'meeting_revenue') + $getVal($monthSum, 'goods_revenue') + 
                             $getVal($monthSum, 'member_card_revenue') + $getVal($monthSum, 'other_revenue');
        
        // 月房费收入
        $monthRoomRevenue = $monthOnlineRevenue + $monthOfflineRevenue;
        
        // 月总营收
        $monthTotalRevenue = $monthRoomRevenue + $monthOtherRevenue;
        
        // 月出租总间数
        $monthTotalRooms = $getVal($monthSum, 'xb_rooms') + $getVal($monthSum, 'mt_rooms') + 
                           $getVal($monthSum, 'fliggy_rooms') + $getVal($monthSum, 'dy_rooms') + 
                           $getVal($monthSum, 'tc_rooms') + $getVal($monthSum, 'qn_rooms') + 
                           $getVal($monthSum, 'zx_rooms') + $getVal($monthSum, 'walkin_rooms') + 
                           $getVal($monthSum, 'member_exp_rooms') + $getVal($monthSum, 'web_exp_rooms') + 
                           $getVal($monthSum, 'group_rooms') + $getVal($monthSum, 'protocol_rooms') + 
                           $getVal($monthSum, 'wechat_rooms') + $getVal($monthSum, 'free_rooms') + 
                           $getVal($monthSum, 'gold_card_rooms') + $getVal($monthSum, 'black_gold_rooms');
        
        // 月综合出租率
        $monthOccRate = $totalRooms > 0 && $day > 0 ? 
                        round(($monthTotalRooms / ($totalRooms * $day)) * 100, 2) : 0;
        
        // 月均价
        $monthAdr = $monthTotalRooms > 0 ? round($monthRoomRevenue / $monthTotalRooms, 2) : 0;
        
        // 月Revpar
        $monthRevpar = $totalRooms > 0 && $day > 0 ? 
                       round($monthRoomRevenue / ($totalRooms * $day), 2) : 0;
        
        // ===== 月任务目标 =====
        
        $monthRevenueTarget = $getVal($taskData, 'revenue_budget', 0);
        $monthOccTarget = $getVal($taskData, 'occupancy_rate_target', 0);
        $monthOtaTarget = $getVal($taskData, 'ota_total_orders', 0);
        $monthOnlineTarget = $getVal($taskData, 'online_revenue_target', 0);
        $monthOfflineTarget = $getVal($taskData, 'offline_revenue_target', 0);
        $monthNewMemberTarget = $getVal($taskData, 'new_members', 0);
        $monthWechatTarget = $getVal($taskData, 'wechat_new_friends', 0);
        
        // 月完成率
        $monthCompleteRate = $monthRevenueTarget > 0 ? 
                             round(($monthTotalRevenue / $monthRevenueTarget) * 100, 2) : 0;
        
        // 月营收差额
        $monthRevenueDiff = $monthTotalRevenue - $monthRevenueTarget;
        
        // 日目标（月目标/月天数）
        $dayRevenueTarget = $monthRevenueTarget > 0 ? round($monthRevenueTarget / $monthDays, 2) : 0;
        $dayRevenueDiff = $dayTotalRevenue - $dayRevenueTarget;
        
        // 月累计会员数
        $monthNewMembers = $getVal($monthSum, 'member_card_sold');
        $monthWechatAdd = $getVal($monthSum, 'wechat_add');
        
        // 月私域数据
        $monthPrivateRevenue = $getVal($monthSum, 'private_revenue');
        $monthPrivateRooms = $getVal($monthSum, 'private_rooms');
        $monthStoredValue = $getVal($monthSum, 'stored_value');
        
        // 私域占比
        $privateRate = $monthTotalRevenue > 0 ? 
                       round(($monthPrivateRevenue / $monthTotalRevenue) * 100, 2) : 0;
        
        // 月点评
        $monthGoodReview = $getVal($monthSum, 'xb_good_review') + $getVal($monthSum, 'mt_good_review') + 
                           $getVal($monthSum, 'fliggy_good_review');
        $monthBadReview = $getVal($monthSum, 'xb_bad_review') + $getVal($monthSum, 'mt_bad_review') + 
                          $getVal($monthSum, 'fliggy_bad_review');
        
        // 月免费房
        $monthFreeRooms = $getVal($monthSum, 'free_rooms');
        
        // 月现金收入
        $monthCashIncome = $getVal($monthSum, 'cash_income');
        
        // 构建返回数据
        return [
            'hotel_name' => $hotel->name,
            'report_date' => $reportDate,
            'total_rooms' => $totalRooms,
            'salable_rooms' => $salableRooms,
            'maintenance_rooms' => $totalRooms - $salableRooms,
            
            // 一、销售业绩
            'month_revenue_target' => $monthRevenueTarget,
            'month_revenue' => round($monthTotalRevenue, 2),
            'month_room_revenue' => round($monthRoomRevenue, 2),
            'month_other_revenue' => round($monthOtherRevenue, 2),
            'month_revenue_diff' => round($monthRevenueDiff, 2),
            'month_complete_rate' => $monthCompleteRate,
            
            'day_revenue_target' => $dayRevenueTarget,
            'day_revenue' => round($dayTotalRevenue, 2),
            'day_room_revenue' => round($dayRoomRevenue, 2),
            'day_other_revenue' => round($dayOtherRevenue, 2),
            'day_revenue_diff' => round($dayRevenueDiff, 2),
            
            'month_occ_rate' => $monthOccRate,
            'day_occ_rate' => $dayOccRate,
            'day_overnight_occ_rate' => $dayOvernightOccRate,
            
            'day_total_rooms' => (int)$dayTotalRooms,
            'day_overnight_rooms' => (int)$dayOvernightRooms,
            'day_non_overnight_rooms' => (int)$dayNonOvernightRooms,
            'day_hourly_rooms' => (int)$hourlyRooms,
            
            'month_adr' => $monthAdr,
            'day_adr' => $dayAdr,
            'overnight_adr' => $dayOvernightRooms > 0 ? 
                               round(($dayRoomRevenue - $hourlyRevenue) / $dayOvernightRooms, 2) : 0,
            
            'month_revpar' => $monthRevpar,
            'day_revpar' => $dayRevpar,
            
            'day_stored_value' => $storedValue,
            'month_stored_value' => $monthStoredValue,
            
            // 二、客源结构
            'member_count' => (int)$memberCardSold,
            'protocol_count' => (int)$protocolRooms,
            'walkin_count' => (int)$walkinRooms,
            'group_count' => (int)$groupRooms,
            'ota_total_rooms' => (int)$otaTotalRooms,
            'xb_rooms' => (int)$xbRooms,
            'mt_rooms' => (int)$mtRooms,
            'tc_rooms' => (int)$tcRooms,
            'qn_rooms' => (int)$qnRooms,
            'zx_rooms' => (int)$zxRooms,
            'fliggy_rooms' => (int)$fliggyRooms,
            'wechat_count' => (int)$wechatRooms,
            'dy_count' => (int)$dyRooms,
            'member_exp_rooms' => (int)$memberExpRooms,
            'web_exp_rooms' => (int)$webExpRooms,
            'free_rooms' => (int)$freeRooms,
            
            // 三、直销指标
            'day_new_member' => (int)$memberCardSold,
            'month_new_member_target' => $monthNewMemberTarget,
            'month_new_member' => (int)$monthNewMembers,
            'month_member_diff' => $monthNewMembers - $monthNewMemberTarget,
            
            'day_wechat_add' => (int)$wechatAdd,
            'month_wechat_target' => $monthWechatTarget,
            'month_wechat_add' => (int)$monthWechatAdd,
            'month_wechat_diff' => $monthWechatAdd - $monthWechatTarget,
            
            'day_private_rooms' => (int)$privateRooms,
            'day_private_revenue' => $privateRevenue,
            'month_private_rooms' => (int)$monthPrivateRooms,
            'month_private_revenue' => $monthPrivateRevenue,
            'private_rate' => $privateRate,
            
            // 四、OTA点评
            'day_good_review' => (int)$dayGoodReview,
            'day_bad_review' => (int)$dayBadReview,
            'month_good_review' => (int)$monthGoodReview,
            'month_bad_review' => (int)$monthBadReview,
            
            // 五、免费房
            'month_free_rooms' => (int)$monthFreeRooms,
            
            // 六、明日预订
            'tomorrow_booking' => (int)$tomorrowBooking,
            
            // 七、今日现金
            'day_cash_income' => $cashIncome,
            'month_cash_income' => $monthCashIncome,
            
            // 原始数据
            'raw_data' => $reportData,
        ];
    }

    /**
     * 创建日报表
     */
    public function create(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fill_daily_report');

        $data = $this->request->post();

        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'report_date' => 'require|date',
        ], [
            'hotel_id.require' => '请选择酒店',
            'report_date.require' => '请选择日期',
        ]);

        $hotelId = (int)$data['hotel_id'];
        $reportDate = $data['report_date'];

        // 权限检查：用户是否有该酒店的填写权限
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($hotelId, 'can_fill_daily_report')) {
            return $this->error('您没有该酒店的日报填写权限');
        }

        // 验证日期：只能填写昨天及之前的日期
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($reportDate > $yesterday) {
            return $this->error('只能填写昨天及之前的日报');
        }

        // 验证月任务：当月必须有月任务才能填写
        $dateParts = explode('-', $reportDate);
        $year = (int)$dateParts[0];
        $month = (int)$dateParts[1];

        $monthlyTask = MonthlyTask::where('hotel_id', $hotelId)
            ->where('year', $year)
            ->where('month', $month)
            ->find();
        
        if (!$monthlyTask) {
            return $this->error("请先添加 {$year}年{$month}月 的月任务，再填写日报");
        }

        // 检查是否已存在
        $exists = DailyReportModel::where('hotel_id', $hotelId)
            ->where('report_date', $reportDate)
            ->find();
        if ($exists) {
            return $this->error('该日期的报表已存在，请直接编辑');
        }

        // 提取报表数据（排除非报表字段）
        $reportData = $this->extractReportData($data);

        $report = new DailyReportModel();
        $report->hotel_id = $hotelId;
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
        $this->checkActionPermission('can_edit_report');

        $report = DailyReportModel::find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($report->hotel_id, 'can_edit_report')) {
            return $this->error('无权编辑此报表');
        }

        $data = $this->request->post();

        // 提取报表数据
        $reportData = $this->extractReportData($data);
        
        // 合并原有数据
        $existingData = $report->report_data ?? [];
        $report->report_data = array_merge($existingData, $reportData);
        
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
        $this->checkActionPermission('can_delete_report');

        $report = DailyReportModel::find($id);
        if (!$report) {
            return $this->error('报表不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($report->hotel_id, 'can_delete_report')) {
            return $this->error('无权删除此报表');
        }

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
            if (isset($data[$field])) {
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

        // 替换变量：report.xxx / task.xxx / month.xxx / calc.xxx
        $expr = preg_replace_callback('/[a-zA-Z_][a-zA-Z0-9_\.]*/', function ($matches) use ($context) {
            $token = $matches[0];
            if (strpos($token, '.') !== false) {
                [$source, $field] = explode('.', $token, 2);
                $value = $this->resolveContextValue($context, $source, $field);
                $num = $this->normalizeNumber($value);
                return $num !== null ? (string)$num : '0';
            }
            return $token;
        }, $expr);

        // 仅允许数字、运算符与括号
        if (preg_match('/[^0-9\.\+\-\*\/\(\)\s]/', $expr)) {
            $error = '公式包含非法字符';
            return null;
        }

        try {
            $result = @eval('return ' . $expr . ';');
            if (!is_numeric($result) || is_nan($result) || is_infinite($result)) {
                $error = '公式结果无效';
                return null;
            }
            return (float)$result;
        } catch (\Throwable $e) {
            $error = '公式计算失败';
            return null;
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
                return '0';
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
            'month' => $makeList(array_keys($monthSum ?? []), $dailyConfigs),
            'calc' => $makeList(array_keys($calc ?? []), $calcLabels),
        ];
    }

    /**
     * 导出日报表到Excel
     */
    public function export(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_report');

        $startDate = $this->request->get('start_date');
        $endDate = $this->request->get('end_date');
        $hotelId = $this->request->get('hotel_id');
        $reportId = $this->request->get('id');

        // 单个报表导出
        if ($reportId) {
            return $this->exportSingle((int)$reportId);
        }

        // 批量导出：验证用户对指定酒店的权限
        if ($hotelId && !$this->currentUser->isSuperAdmin()) {
            if (!$this->currentUser->hasHotelPermission((int)$hotelId, 'can_view_report')) {
                return $this->error('无权导出该酒店的报表');
            }
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
        
        // 权限检查：用户是否有权限查看该酒店的报表
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($report->hotel_id, 'can_view_report')) {
            return $this->error('无权导出该报表');
        }
        
        $hotel = $report->hotel;
        $reportData = $report->report_data ?? [];
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
            $hotelIds = $this->currentUser->hotelPermissions->column('hotel_id');
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

        // 获取月任务数据
        $monthTaskData = [];
        if ($reports->count() > 0) {
            $firstReport = $reports[0];
            $dateParts = explode('-', $firstReport->report_date);
            $year = (int)$dateParts[0];
            $month = (int)$dateParts[1];
            
            $monthlyTask = MonthlyTask::where('hotel_id', $firstReport->hotel_id)
                ->where('year', $year)
                ->where('month', $month)
                ->find();
            
            if ($monthlyTask) {
                $monthTaskData = $monthlyTask->task_data ?? [];
            }
        }

        $exportData = [
            'reports' => [],
            'month_task' => $monthTaskData,
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
            $reportData = $report->report_data ?? [];
            
            // 如果没有通过hotelId获取酒店名称，从第一条报表获取
            if (!$hotelName && $report->hotel) {
                $hotelName = $report->hotel->name;
            }
            
            $exportData['reports'][] = [
                'hotel_name' => $report->hotel->name ?? '',
                'report_date' => $report->report_date,
                'data' => $reportData,  // 直接传递原始数据，Excel公式会计算
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

    /**
     * 生成Excel文件（PHP原生HTML表格方式，模拟Python脚本样式）
     */
    private function generateExcelResponse(array $exportData, string $filename): Response
    {
        $html = $this->generateExcelHtml($exportData);
        
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
        
        // 月任务数据
        $monthRevenueTarget = $monthTask['revenue_budget'] ?? 0;
        $onlineTarget = $monthTask['online_revenue_target'] ?? 0;
        $offlineTarget = $monthTask['offline_revenue_target'] ?? 0;
        
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
        $html .= '<td colspan="19" style="background:#B2CFEA;font-weight:bold">线上客房收入</td>';
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
        // 线上客房收入子分类（19列：5+2+2+2+2+2+2+2）
        $html .= '<td colspan="5" style="background:#B2CFEA;font-weight:bold">线上合计</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">携程</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">美团</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">飞猪</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">同程</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">抖音</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">去哪儿</td>';
        $html .= '<td colspan="2" style="background:#EBF1DE;font-weight:bold">智行</td>';
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
        // 携程、美团、飞猪、同程、抖音、去哪儿、智行（7个渠道，各2列，共14列）
        for ($i = 0; $i < 7; $i++) {
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
        $cols = ['微信加粉率', '微信加粉人数', '新增会员人数'];
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
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['total_revenue'] / max($monthRevenueTarget, 1)) . '</td>';
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
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['online_revenue'] / max($onlineTarget, 1)) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_rooms'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['online_adr']) . '</td>';
        
        // 各OTA - 黄色背景（携程、美团、飞猪、同程、抖音、去哪儿、智行）
        foreach (['xb', 'mt', 'fliggy', 'tc', 'dy', 'qn', 'zx'] as $key) {
            $revKey = $key . '_revenue';
            $roomKey = $key . '_rooms';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$revKey] ?? 0) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$roomKey] ?? 0, 0) . '</td>';
        }
        
        // 线下合计 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($offlineTarget) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['offline_revenue'] / max($offlineTarget, 1)) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_rooms'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['offline_adr']) . '</td>';
        
        // 各线下渠道 - 黄色背景
        $offlineChannels = ['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold'];
        foreach ($offlineChannels as $channel) {
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$channel . '_revenue'] ?? 0) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$channel . '_rooms'] ?? 0, 0) . '</td>';
        }
        
        // 钟点房 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['hourly_revenue']) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['hourly_rooms'], 0) . '</td>';
        
        // 其他收入 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['other_revenue_total']) . '</td>';
        foreach (['parking', 'dining', 'meeting', 'goods', 'member_card', 'other'] as $key) {
            $valKey = $key . '_revenue';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$valKey] ?? 0) . '</td>';
        }
        
        // 好评 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['review_orders'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['good_reviews'], 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['good_review_rate']) . '</td>';
        
        // 各平台好评 - 黄色背景
        foreach (['xb', 'mt', 'fliggy'] as $platform) {
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$platform . '_reviewable'] ?? 0, 0) . '</td>';
            $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals[$platform . '_good_review'] ?? 0, 0) . '</td>';
        }
        
        // 流量 - 黄色背景
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['xb_exposure'] ?? 0, 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_exp_rate'] ?? 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_bk_rate'] ?? 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['xb_clinch_rate'] ?? 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtNum($totals['mt_exposure'] ?? 0, 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['mt_click_rate'] ?? 0) . '</td>';
        $html .= '<td style="background:yellow;text-align:right">' . $this->fmtPct($totals['mt_pay_rate'] ?? 0) . '</td>';
        
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
            $data = $report['data'] ?? [];
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
            $getVal = function($key, $default = 0) use ($data) {
                return isset($data[$key]) && is_numeric($data[$key]) ? floatval($data[$key]) : $default;
            };
            
            // 可售房数
            $salableRooms = $getVal('salable_rooms', 59);
            
            // 计算各项数据
            // 线上收入
            $xbRevenue = $getVal('xb_revenue');
            $mtRevenue = $getVal('mt_revenue');
            $fliggyRevenue = $getVal('fliggy_revenue');
            $dyRevenue = $getVal('dy_revenue');
            $tcRevenue = $getVal('tc_revenue');
            $qnRevenue = $getVal('qn_revenue');
            $zxRevenue = $getVal('zx_revenue');
            
            // 线上间夜
            $xbRooms = $getVal('xb_rooms');
            $mtRooms = $getVal('mt_rooms');
            $fliggyRooms = $getVal('fliggy_rooms');
            $dyRooms = $getVal('dy_rooms');
            $tcRooms = $getVal('tc_rooms');
            $qnRooms = $getVal('qn_rooms');
            $zxRooms = $getVal('zx_rooms');
            
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
            $onlineRevenue = $xbRevenue + $mtRevenue + $fliggyRevenue + $dyRevenue + $tcRevenue + $qnRevenue + $zxRevenue;
            $onlineRooms = $xbRooms + $mtRooms + $fliggyRooms + $dyRooms + $tcRooms + $qnRooms + $zxRooms;
            $offlineRevenue = $walkinRevenue + $memberExpRevenue + $webExpRevenue + $groupRevenue + $protocolRevenue + $wechatRevenue + $freeRevenue + $goldCardRevenue + $blackGoldRevenue + $hourlyRevenue;
            $offlineRooms = $walkinRooms + $memberExpRooms + $webExpRooms + $groupRooms + $protocolRooms + $wechatRooms + $freeRooms + $goldCardRooms + $blackGoldRooms + $hourlyRooms;
            $otherRevenueTotal = $parkingRevenue + $diningRevenue + $meetingRevenue + $goodsRevenue + $memberCardRevenue + $otherRevenue;
            $roomRevenue = $onlineRevenue + $offlineRevenue;
            $totalRevenue = $roomRevenue + $otherRevenueTotal;
            $totalRooms = $onlineRooms + $offlineRooms;
            $overnightRooms = $totalRooms - $hourlyRooms;
            
            // 合计区域 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($monthRevenueTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($totalRevenue / max($monthRevenueTarget, 1)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($roomRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($roomRevenue / max($salableRooms, 1)) . '</td>'; // Revpar
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($totalRooms / max($salableRooms, 1)) . '</td>'; // 出租率
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($roomRevenue / max($totalRooms, 1)) . '</td>'; // 平均房价
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($overnightRooms / max($salableRooms, 1)) . '</td>'; // 过夜出租率
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum(($roomRevenue - $hourlyRevenue) / max($overnightRooms, 1)) . '</td>'; // 过夜均价
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum(($roomRevenue - $hourlyRevenue) / max($salableRooms, 1)) . '</td>'; // 过夜Revpar
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($onlineRooms / max($totalRooms, 1)) . '</td>'; // OTA间夜占比
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalRooms, 0) . '</td>'; // 售房数
            $html .= '<td style="background:#EBF1DE;text-align:right">' . $this->fmtNum($salableRooms, 0) . '</td>'; // 可售房数量
            
            // 线上合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($onlineRevenue / max($onlineTarget, 1)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineRooms, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($onlineRevenue / max($onlineRooms, 1)) . '</td>';
            
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
            
            // 线下合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineTarget) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($offlineRevenue / max($offlineTarget, 1)) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineRevenue) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineRooms, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($offlineRevenue / max($offlineRooms, 1)) . '</td>';
            
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
            
            $totalReviewable = $xbReviewable + $mtReviewable + $fliggyReviewable;
            $totalGoodReview = $xbGoodReview + $mtGoodReview + $fliggyGoodReview;
            
            // 好评合计 - 蓝色背景
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalReviewable, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtNum($totalGoodReview, 0) . '</td>';
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct($totalGoodReview / max($totalReviewable, 1)) . '</td>';
            
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
            
            $html .= '<td style="background:#B2CFEA;text-align:right">' . $this->fmtPct(($wechatAdd - $hourlyRooms) / max($totalRooms, 1)) . '</td>'; // 微信加粉率
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
        
        $html .= '</table></body></html>';
        
        return $html;
    }
    
    /**
     * 计算合计数据
     */
    private function calculateTotals(array $reports, float $monthTarget, float $onlineTarget, float $offlineTarget): array
    {
        $totals = [
            'total_revenue' => 0,
            'room_revenue' => 0,
            'total_rooms' => 0,
            'salable_rooms' => 0,
            'hourly_revenue' => 0,
            'hourly_rooms' => 0,
            'online_revenue' => 0,
            'online_rooms' => 0,
            'offline_revenue' => 0,
            'offline_rooms' => 0,
            'xb_revenue' => 0, 'xb_rooms' => 0,
            'mt_revenue' => 0, 'mt_rooms' => 0,
            'fliggy_revenue' => 0, 'fliggy_rooms' => 0,
            'dy_revenue' => 0, 'dy_rooms' => 0,
            'tc_revenue' => 0, 'tc_rooms' => 0,
            'qn_revenue' => 0, 'qn_rooms' => 0,
            'zx_revenue' => 0, 'zx_rooms' => 0,
            'qn_zx_revenue' => 0, 'qn_zx_rooms' => 0,
            'booking_revenue' => 0, 'booking_rooms' => 0,
            'walkin_revenue' => 0, 'walkin_rooms' => 0,
            'member_exp_revenue' => 0, 'member_exp_rooms' => 0,
            'web_exp_revenue' => 0, 'web_exp_rooms' => 0,
            'group_revenue' => 0, 'group_rooms' => 0,
            'protocol_revenue' => 0, 'protocol_rooms' => 0,
            'wechat_revenue' => 0, 'wechat_rooms' => 0,
            'free_revenue' => 0, 'free_rooms' => 0,
            'gold_card_revenue' => 0, 'gold_card_rooms' => 0,
            'black_gold_revenue' => 0, 'black_gold_rooms' => 0,
            'parking_revenue' => 0, 'dining_revenue' => 0,
            'meeting_revenue' => 0, 'goods_revenue' => 0,
            'member_card_revenue' => 0, 'other_revenue' => 0,
            'other_revenue_total' => 0,
            'xb_reviewable' => 0, 'xb_good_review' => 0,
            'mt_reviewable' => 0, 'mt_good_review' => 0,
            'fliggy_reviewable' => 0, 'fliggy_good_review' => 0,
            'xb_exposure' => 0, 'xb_exp_rate' => 0,
            'xb_bk_rate' => 0, 'xb_clinch_rate' => 0,
            'mt_exposure' => 0, 'mt_click_rate' => 0, 'mt_pay_rate' => 0,
            'wechat_add' => 0, 'member_add' => 0,
            'private_revenue' => 0, 'private_rooms' => 0,
            'member_card_sold' => 0, 'stored_value' => 0,
        ];
        
        foreach ($reports as $report) {
            $data = $report['data'] ?? [];
            
            $getVal = function($key, $default = 0) use ($data) {
                return isset($data[$key]) && is_numeric($data[$key]) ? floatval($data[$key]) : $default;
            };
            
            // 累加各项
            $totals['salable_rooms'] += $getVal('salable_rooms', 59);
            $totals['hourly_revenue'] += $getVal('hourly_revenue');
            $totals['hourly_rooms'] += $getVal('hourly_rooms');
            
            // OTA
            $totals['xb_revenue'] += $getVal('xb_revenue');
            $totals['xb_rooms'] += $getVal('xb_rooms');
            $totals['mt_revenue'] += $getVal('mt_revenue');
            $totals['mt_rooms'] += $getVal('mt_rooms');
            $totals['fliggy_revenue'] += $getVal('fliggy_revenue');
            $totals['fliggy_rooms'] += $getVal('fliggy_rooms');
            $totals['dy_revenue'] += $getVal('dy_revenue');
            $totals['dy_rooms'] += $getVal('dy_rooms');
            $totals['tc_revenue'] += $getVal('tc_revenue');
            $totals['tc_rooms'] += $getVal('tc_rooms');
            $totals['qn_revenue'] += $getVal('qn_revenue');
            $totals['qn_rooms'] += $getVal('qn_rooms');
            $totals['zx_revenue'] += $getVal('zx_revenue');
            $totals['zx_rooms'] += $getVal('zx_rooms');
            $totals['qn_zx_revenue'] += $getVal('qn_revenue') + $getVal('zx_revenue');
            $totals['qn_zx_rooms'] += $getVal('qn_rooms') + $getVal('zx_rooms');
            
            // 线下
            $totals['walkin_revenue'] += $getVal('walkin_revenue');
            $totals['walkin_rooms'] += $getVal('walkin_rooms');
            $totals['member_exp_revenue'] += $getVal('member_exp_revenue');
            $totals['member_exp_rooms'] += $getVal('member_exp_rooms');
            $totals['web_exp_revenue'] += $getVal('web_exp_revenue');
            $totals['web_exp_rooms'] += $getVal('web_exp_rooms');
            $totals['group_revenue'] += $getVal('group_revenue');
            $totals['group_rooms'] += $getVal('group_rooms');
            $totals['protocol_revenue'] += $getVal('protocol_revenue');
            $totals['protocol_rooms'] += $getVal('protocol_rooms');
            $totals['wechat_revenue'] += $getVal('wechat_revenue');
            $totals['wechat_rooms'] += $getVal('wechat_rooms');
            $totals['free_revenue'] += $getVal('free_revenue');
            $totals['free_rooms'] += $getVal('free_rooms');
            $totals['gold_card_revenue'] += $getVal('gold_card_revenue');
            $totals['gold_card_rooms'] += $getVal('gold_card_rooms');
            $totals['black_gold_revenue'] += $getVal('black_gold_revenue');
            $totals['black_gold_rooms'] += $getVal('black_gold_rooms');
            
            // 其他收入
            $totals['parking_revenue'] += $getVal('parking_revenue');
            $totals['dining_revenue'] += $getVal('dining_revenue');
            $totals['meeting_revenue'] += $getVal('meeting_revenue');
            $totals['goods_revenue'] += $getVal('goods_revenue');
            $totals['member_card_revenue'] += $getVal('member_card_revenue');
            $totals['other_revenue'] += $getVal('other_revenue');
            
            // 好评
            $totals['xb_reviewable'] += $getVal('xb_reviewable');
            $totals['xb_good_review'] += $getVal('xb_good_review');
            $totals['mt_reviewable'] += $getVal('mt_reviewable');
            $totals['mt_good_review'] += $getVal('mt_good_review');
            $totals['fliggy_reviewable'] += $getVal('fliggy_reviewable');
            $totals['fliggy_good_review'] += $getVal('fliggy_good_review');
            
            // 流量
            $totals['xb_exposure'] += $getVal('xb_exposure');
            $totals['mt_exposure'] += $getVal('mt_exposure');
            
            // 私域
            $totals['wechat_add'] += $getVal('wechat_add');
            $totals['member_add'] += $getVal('member_add');
            $totals['private_revenue'] += $getVal('private_revenue');
            $totals['private_rooms'] += $getVal('private_rooms');
            $totals['member_card_sold'] += $getVal('member_card_sold');
            $totals['stored_value'] += $getVal('stored_value');
        }
        
        // 计算汇总
        $totals['online_revenue'] = $totals['xb_revenue'] + $totals['mt_revenue'] + $totals['fliggy_revenue'] + 
                                    $totals['dy_revenue'] + $totals['tc_revenue'] + $totals['qn_revenue'] + $totals['zx_revenue'];
        $totals['online_rooms'] = $totals['xb_rooms'] + $totals['mt_rooms'] + $totals['fliggy_rooms'] + 
                                  $totals['dy_rooms'] + $totals['tc_rooms'] + $totals['qn_rooms'] + $totals['zx_rooms'];
        $totals['offline_revenue'] = $totals['walkin_revenue'] + $totals['member_exp_revenue'] + $totals['web_exp_revenue'] +
                                     $totals['group_revenue'] + $totals['protocol_revenue'] + $totals['wechat_revenue'] +
                                     $totals['free_revenue'] + $totals['gold_card_revenue'] + $totals['black_gold_revenue'] + $totals['hourly_revenue'];
        $totals['offline_rooms'] = $totals['walkin_rooms'] + $totals['member_exp_rooms'] + $totals['web_exp_rooms'] +
                                   $totals['group_rooms'] + $totals['protocol_rooms'] + $totals['wechat_rooms'] +
                                   $totals['free_rooms'] + $totals['gold_card_rooms'] + $totals['black_gold_rooms'] + $totals['hourly_rooms'];
        $totals['other_revenue_total'] = $totals['parking_revenue'] + $totals['dining_revenue'] + $totals['meeting_revenue'] +
                                         $totals['goods_revenue'] + $totals['member_card_revenue'] + $totals['other_revenue'];
        $totals['room_revenue'] = $totals['online_revenue'] + $totals['offline_revenue'];
        $totals['total_revenue'] = $totals['room_revenue'] + $totals['other_revenue_total'];
        $totals['total_rooms'] = $totals['online_rooms'] + $totals['offline_rooms'];
        
        // 计算比率
        $reportCount = max(count($reports), 1);
        $totals['occ_rate'] = $totals['total_rooms'] / max($totals['salable_rooms'], 1);
        $totals['adr'] = $totals['room_revenue'] / max($totals['total_rooms'], 1);
        $totals['revpar'] = $totals['room_revenue'] / max($totals['salable_rooms'], 1);
        $totals['overnight_occ_rate'] = ($totals['total_rooms'] - $totals['hourly_rooms']) / max($totals['salable_rooms'], 1);
        $totals['overnight_adr'] = ($totals['room_revenue'] - $totals['hourly_revenue']) / max($totals['total_rooms'] - $totals['hourly_rooms'], 1);
        $totals['overnight_revpar'] = ($totals['room_revenue'] - $totals['hourly_revenue']) / max($totals['salable_rooms'], 1);
        $totals['ota_room_rate'] = $totals['online_rooms'] / max($totals['total_rooms'], 1);
        $totals['online_adr'] = $totals['online_revenue'] / max($totals['online_rooms'], 1);
        $totals['offline_adr'] = $totals['offline_revenue'] / max($totals['offline_rooms'], 1);
        $totals['good_review_rate'] = ($totals['xb_good_review'] + $totals['mt_good_review'] + $totals['fliggy_good_review']) / 
                                       max($totals['xb_reviewable'] + $totals['mt_reviewable'] + $totals['fliggy_reviewable'], 1);
        $totals['review_orders'] = $totals['xb_reviewable'] + $totals['mt_reviewable'] + $totals['fliggy_reviewable'];
        $totals['good_reviews'] = $totals['xb_good_review'] + $totals['mt_good_review'] + $totals['fliggy_good_review'];
        $totals['wechat_add_rate'] = ($totals['wechat_add'] - $totals['hourly_rooms']) / max($totals['total_rooms'], 1);
        
        return $totals;
    }
    
    /**
     * 格式化数字
     */
    private function fmtNum($value, int $decimals = 2): string
    {
        if (!is_numeric($value)) {
            return '0';
        }
        return number_format((float)$value, $decimals, '.', ',');
    }
    
    /**
     * 格式化百分比
     */
    private function fmtPct($value): string
    {
        if (!is_numeric($value)) {
            return '0.00%';
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
     * 检查操作权限
     */
    private function checkActionPermission(string $permission): void
    {
        if ($this->currentUser->isSuperAdmin()) {
            return;
        }
        
        if (!$this->currentUser->hasPermission($permission)) {
            abort(403, '无权限操作');
        }
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
        $mapping = $this->request->post('mapping');
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
        $this->checkActionPermission('can_create');

        $file = $this->request->file('file');
        $hotelId = $this->request->post('hotel_id');
        
        if (!$file) {
            return $this->error('请上传文件');
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

# 强制 stdout 使用 UTF-8，避免 Windows 默认 GBK 编码失败
try:
    sys.stdout.reconfigure(encoding='utf-8')
except Exception:
    pass

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
        $commands = [
            sprintf('python3 %s %s 2>&1', escapeshellarg($scriptPath), escapeshellarg($filePath)),
            sprintf('python %s %s 2>&1', escapeshellarg($scriptPath), escapeshellarg($filePath)),
        ];
        $lastOutput = '';
        foreach ($commands as $command) {
            $output = shell_exec($command);
            $lastOutput = $output ?? '';
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
                        $rawValue = $cells[$valueColumn] ?? '0';
                        // 根据目标字段的单位判断是否为百分比字段
                        $isPercentField = in_array($systemField, $percentFields);
                        $value = $isPercentField ? $this->parsePercent($rawValue) : $this->parseNumber($rawValue);
                        
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
            $rawValue = $cells['E'] ?? '0';
            $valueToday = $this->parseNumber($rawValue);
            if ($valueToday !== 0) {
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
            $result[$systemField] = !empty($values) ? end($values) : 0;
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
                    'result' => $filteredData[$systemField] ?? 0,
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
     * 递归删除目录
     */
    private function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                is_dir($path) ? $this->removeDir($path) : unlink($path);
            }
            rmdir($dir);
        }
    }

    /**
     * 从JY01报表提取数据并映射到日报表字段
     */
    private function extractJY01Data(array $rows, array $sharedStrings): array
    {
        $result = [
            'report_date' => '',
            'hotel_name' => '',
            'mapped_data' => [],
            'unmapped_data' => [],
            'field_mapping' => $this->getFieldMapping(),
        ];

        // 提取基本信息（通常在第1行或第3行）
        foreach ([1, 2, 3, 4, 5] as $rowNum) {
            if (isset($rows[$rowNum]['A'])) {
                $info = $rows[$rowNum]['A'];
                \think\facade\Log::write("第{$rowNum}行A列内容: " . $info, 'info');
                // 解析：门店：南宁市银田酒店    营业日：2026-02-24    统计类别：全部
                // 或：门店：测试酒店A    营业日：2026-02-24    统计类别：全部
                if (preg_match('/门店[：:]\s*([^\s\t]+)/u', $info, $matches)) {
                    $result['hotel_name'] = $matches[1];
                } elseif (preg_match('/门店[：:](.+?)(?:\s|\t|营业)/u', $info, $matches)) {
                    // 尝试另一种格式：门店：xxx 营业日
                    $result['hotel_name'] = trim($matches[1]);
                }
                // 尝试多种日期格式
                if (preg_match('/营业日[：:]\s*(\d{4}-\d{2}-\d{2})/', $info, $matches)) {
                    $result['report_date'] = $matches[1];
                } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $info, $matches)) {
                    $result['report_date'] = $matches[1];
                }
            }
            // 也检查B列
            if (isset($rows[$rowNum]['B'])) {
                $info = $rows[$rowNum]['B'];
                \think\facade\Log::write("第{$rowNum}行B列内容: " . $info, 'info');
            }
        }
        
        \think\facade\Log::write("提取的基本信息 - 酒店: {$result['hotel_name']}, 日期: {$result['report_date']}", 'info');

        // ===== 根据实际Excel格式解析 =====
        // 格式分析：
        // 第3行：{"A":"80","B":"总房数","C":"75"} → A=数值，B=项目名，C=另一个数值
        // 第5-7行（渠道）：A=渠道名，D=间夜，E=营业额
        // 第9-13行（门店收入）：A=项目名，E=数值
        // 第14-17行（指标）：A=项目名，B=数值

        // 1. 解析总营业指标区域（第3行）
        if (isset($rows[3])) {
            $row3 = $rows[3];
            // 格式：A=数值，B=项目名，C=数值
            // 第3行数据：A=80(总房数的值)，B="总房数"，C=75(可售房数)
            $colA = trim($row3['A'] ?? '');
            $colB = trim($row3['B'] ?? '');
            $colC = trim($row3['C'] ?? '');
            
            \think\facade\Log::write("第3行解析: A={$colA}, B={$colB}, C={$colC}", 'info');
            
            // 如果B列是"总房数"，则A列是总房数的值
            if (strpos($colB, '总房数') !== false) {
                $result['mapped_data']['total_rooms_count'] = $this->parseNumber($colA);
            }
            // 如果C列有值，可能是可售房数
            if (is_numeric($this->parseNumber($colC))) {
                $result['mapped_data']['salable_rooms'] = $this->parseNumber($colC);
            }
        }

        // 2. 解析门店收入区域（第9-13行）
        $revenueMapping = [
            '房费' => 'room_revenue',
            '会员卡' => 'member_card_revenue',
            '小商品' => 'goods_revenue',
            '餐饮' => 'dining_revenue',
            '其他消费' => 'other_revenue',
        ];
        
        for ($i = 9; $i <= 20; $i++) {
            if (isset($rows[$i])) {
                $row = $rows[$i];
                $colA = trim($row['A'] ?? '');
                $colE = trim($row['E'] ?? '');
                
                foreach ($revenueMapping as $itemName => $field) {
                    if (strpos($colA, $itemName) !== false && is_numeric($this->parseNumber($colE))) {
                        $result['mapped_data'][$field] = $this->parseNumber($colE);
                        \think\facade\Log::write("门店收入匹配: {$itemName} => {$field} = {$colE}", 'info');
                        break;
                    }
                }
            }
        }

        // 3. 解析指标区域（第14-20行）
        // 注意：更长的项目名要放在前面，避免被短项目名误匹配
        $indicatorMapping = [
            '过夜房出租率' => 'overnight_occ_rate',
            '出租率' => 'occ_rate',
            '平均房价' => 'adr',
            'RevPar' => 'revpar',
        ];
        
        for ($i = 14; $i <= 25; $i++) {
            if (isset($rows[$i])) {
                $row = $rows[$i];
                $colA = trim($row['A'] ?? '');
                $colB = trim($row['B'] ?? '');
                
                // 使用最长匹配原则（优先匹配更长的项目名）
                $matched = false;
                $matchedField = '';
                $matchedLength = 0;
                
                foreach ($indicatorMapping as $itemName => $field) {
                    if (strpos($colA, $itemName) !== false) {
                        $itemLen = strlen($itemName);
                        if ($itemLen > $matchedLength) {
                            $matchedField = $field;
                            $matchedLength = $itemLen;
                            $matched = true;
                        }
                    }
                }
                
                if ($matched) {
                    $value = $this->parseNumber($colB);
                    if ($value > 0) {
                        $result['mapped_data'][$matchedField] = $value;
                        \think\facade\Log::write("指标匹配: {$colA} => {$matchedField} = {$value}", 'info');
                    }
                }
            }
        }

        // 专门处理"按渠道统计"部分
        $this->parseChannelStats($rows, $result['mapped_data'], $result['unmapped_data']);

        // 计算派生字段
        $this->calculateDerivedFields($result['mapped_data']);

        // 过滤只保留配置中存在的字段
        $validFields = $this->getValidReportFields();
        $filteredData = [];
        $unmappedData = [];
        
        foreach ($result['mapped_data'] as $key => $value) {
            if (in_array($key, $validFields)) {
                $filteredData[$key] = $value;
            } else {
                $unmappedData[$key] = $value;
            }
        }
        
        $result['mapped_data'] = $filteredData;
        $result['unmapped_data'] = $unmappedData;

        // 记录最终结果
        \think\facade\Log::write('=== JY01报表解析结果 ===', 'info');
        \think\facade\Log::write('有效字段数量: ' . count($filteredData), 'info');
        \think\facade\Log::write('mapped_data: ' . json_encode($filteredData, JSON_UNESCAPED_UNICODE), 'info');
        \think\facade\Log::write('unmapped_data: ' . json_encode($unmappedData, JSON_UNESCAPED_UNICODE), 'info');
        \think\facade\Log::write('酒店名: ' . $result['hotel_name'] . ', 日期: ' . $result['report_date'], 'info');

        return $result;
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
     * 解析渠道统计部分
     */
    private function parseChannelStats(array $rows, array &$mappedData, array &$unmappedData): void
    {
        // 渠道映射（包含收入和间夜）
        $channels = [
            '携程' => ['revenue' => 'xb_revenue', 'rooms' => 'xb_rooms'],
            '美团' => ['revenue' => 'mt_revenue', 'rooms' => 'mt_rooms'],
            '飞猪' => ['revenue' => 'fliggy_revenue', 'rooms' => 'fliggy_rooms'],
            '同程' => ['revenue' => 'tc_revenue', 'rooms' => 'tc_rooms'],
            '抖音' => ['revenue' => 'dy_revenue', 'rooms' => 'dy_rooms'],
            '去哪儿' => ['revenue' => 'qn_revenue', 'rooms' => 'qn_rooms'],
            '智行' => ['revenue' => 'zx_revenue', 'rooms' => 'zx_rooms'],
            '会员体验' => ['revenue' => 'member_exp_revenue', 'rooms' => 'member_exp_rooms'],
            '网络体验' => ['revenue' => 'web_exp_revenue', 'rooms' => 'web_exp_rooms'],
            '门店' => ['revenue' => 'walkin_revenue', 'rooms' => 'walkin_rooms'],
            '京东' => ['revenue' => 'jd_revenue', 'rooms' => 'jd_rooms'],
        ];

        $foundChannelStart = false;
        $channelMatches = []; // 记录渠道匹配结果

        foreach ($rows as $rowNum => $cells) {
            $colA = trim($cells['A'] ?? '');
            $colB = $cells['B'] ?? '';
            $colC = $cells['C'] ?? '';
            $colD = $cells['D'] ?? '';
            $colE = $cells['E'] ?? '';
            $colF = trim($cells['F'] ?? '');
            $colG = trim($cells['G'] ?? '');

            // 检测渠道统计开始
            if (strpos($colA, '按渠道统计') !== false) {
                $foundChannelStart = true;
                \think\facade\Log::write("parseChannelStats: 进入渠道统计区域 (行{$rowNum})", 'info');
                continue;
            }

            // 检测渠道统计结束（按入住类型统计、门店收入等）
            if ($foundChannelStart && (
                strpos($colA, '门店收入') !== false || 
                strpos($colA, '按入住类型') !== false ||
                (strpos($colA, '按') !== false && strpos($colA, '统计') !== false && strpos($colA, '渠道') === false)
            )) {
                \think\facade\Log::write("parseChannelStats: 离开渠道统计区域 (行{$rowNum}): {$colA}", 'info');
                break;
            }

            // 如果还未进入渠道统计区域，跳过
            if (!$foundChannelStart || empty($colA)) {
                continue;
            }
            
            // 根据实际格式：A=渠道名，D=间夜，E=营业额
            // 例如：{"A":"携程","B":"间夜数","C":"营业额","D":"15","E":"3200"}
            foreach ($channels as $channelName => $fields) {
                if (strpos($colA, $channelName) !== false) {
                    // 跳过钟点房
                    if (strpos($colA, '钟点') !== false) {
                        continue 2;
                    }
                    
                    $rooms = 0;
                    $revenue = 0;
                    
                    // 解析数据：D=间夜，E=营业额
                    $dVal = $this->parseNumber($colD);
                    $eVal = $this->parseNumber($colE);
                    
                    // 判断哪个是间夜（较小的整数），哪个是收入（较大的数值）
                    if ($dVal > 0 && $eVal > 0) {
                        // D和E都有值
                        if ($dVal < 500 && floor($dVal) == $dVal) {
                            // D是整数且较小，可能是间夜
                            $rooms = (int)$dVal;
                            $revenue = $eVal;
                        } else {
                            // E是整数且较小，可能是间夜
                            $rooms = (int)$eVal;
                            $revenue = $dVal;
                        }
                    } elseif ($dVal > 0) {
                        // 只有D有值，根据大小判断
                        if ($dVal < 500 && floor($dVal) == $dVal) {
                            $rooms = (int)$dVal;
                        } else {
                            $revenue = $dVal;
                        }
                    } elseif ($eVal > 0) {
                        // 只有E有值，根据大小判断
                        if ($eVal < 500 && floor($eVal) == $eVal) {
                            $rooms = (int)$eVal;
                        } else {
                            $revenue = $eVal;
                        }
                    }
                    
                    // 记录数据
                    if ($rooms > 0 || $revenue > 0) {
                        $channelMatches[] = ['channel' => $channelName, 'row' => $rowNum, 'rooms' => $rooms, 'revenue' => $revenue];
                    }
                    
                    if ($rooms > 0) {
                        $mappedData[$fields['rooms']] = $rooms;
                    }
                    if ($revenue > 0) {
                        $mappedData[$fields['revenue']] = $revenue;
                    }
                    
                    \think\facade\Log::write("渠道匹配: {$channelName} => rooms={$rooms}, revenue={$revenue}", 'info');
                    
                    break;
                }
            }
        }
        
        \think\facade\Log::write('parseChannelStats匹配结果: ' . json_encode($channelMatches, JSON_UNESCAPED_UNICODE), 'info');
    }

    /**
     * 计算派生字段
     */
    private function calculateDerivedFields(array &$data): void
    {
        // 计算可售房数 = 总房数 - 维修房数
        if (isset($data['total_rooms_count']) && !isset($data['salable_rooms'])) {
            $maintenance = $data['maintenance_rooms'] ?? 0;
            $data['salable_rooms'] = $data['total_rooms_count'] - $maintenance;
        }
        
        // 如果有过夜房和钟点房，计算总间夜数
        if (!isset($data['total_rooms'])) {
            $overnight = $data['overnight_rooms'] ?? 0;
            $hourly = $data['hourly_rooms'] ?? 0;
            if ($overnight > 0 || $hourly > 0) {
                $data['total_rooms'] = $overnight + $hourly;
            }
        }

        // 计算线上合计
        $onlineRevenue = 0;
        $onlineRooms = 0;
        foreach (['xb', 'mt', 'fliggy', 'tc', 'dy', 'qn', 'zx'] as $channel) {
            $onlineRevenue += $data[$channel . '_revenue'] ?? 0;
            $onlineRooms += $data[$channel . '_rooms'] ?? 0;
        }
        $data['online_revenue'] = $onlineRevenue;
        $data['online_rooms'] = $onlineRooms;

        // 计算线下合计（不含钟点房）
        $offlineRevenue = 0;
        $offlineRooms = 0;
        foreach (['walkin', 'member_exp', 'web_exp', 'group', 'protocol', 'wechat', 'free', 'gold_card', 'black_gold'] as $channel) {
            $offlineRevenue += $data[$channel . '_revenue'] ?? 0;
            $offlineRooms += $data[$channel . '_rooms'] ?? 0;
        }
        $data['offline_revenue'] = $offlineRevenue;
        $data['offline_rooms'] = $offlineRooms;

        // 计算平均房价
        if (!isset($data['adr']) && isset($data['room_revenue']) && isset($data['total_rooms'])) {
            $totalRooms = max($data['total_rooms'], 1);
            $data['adr'] = $data['room_revenue'] / $totalRooms;
        }

        // 计算OTA间夜占比
        if (isset($data['online_rooms']) && isset($data['total_rooms'])) {
            $totalRooms = max($data['total_rooms'], 1);
            $data['ota_room_rate'] = $data['online_rooms'] / $totalRooms;
        }
    }

    /**
     * 获取字段映射说明
     */
    private function getFieldMapping(): array
    {
        return [
            [
                'source' => '携程',
                'target' => 'xb_revenue, xb_rooms',
                'description' => '携程渠道收入和间夜'
            ],
            [
                'source' => '美团',
                'target' => 'mt_revenue, mt_rooms',
                'description' => '美团渠道收入和间夜'
            ],
            [
                'source' => '飞猪',
                'target' => 'fliggy_revenue, fliggy_rooms',
                'description' => '飞猪渠道收入和间夜'
            ],
            [
                'source' => '同程',
                'target' => 'tc_revenue, tc_rooms',
                'description' => '同程渠道收入和间夜'
            ],
            [
                'source' => '抖音',
                'target' => 'dy_revenue, dy_rooms',
                'description' => '抖音渠道收入和间夜'
            ],
            [
                'source' => '去哪儿',
                'target' => 'qn_revenue, qn_rooms',
                'description' => '去哪儿渠道收入和间夜'
            ],
            [
                'source' => '智行',
                'target' => 'zx_revenue, zx_rooms',
                'description' => '智行渠道收入和间夜'
            ],
            [
                'source' => '会员体验',
                'target' => 'member_exp_revenue, member_exp_rooms',
                'description' => '会员体验收入和间夜'
            ],
            [
                'source' => '网络体验',
                'target' => 'web_exp_revenue, web_exp_rooms',
                'description' => '网络体验收入和间夜'
            ],
            [
                'source' => '门店',
                'target' => 'walkin_revenue, walkin_rooms',
                'description' => '门店散客收入和间夜'
            ],
            [
                'source' => '钟点房',
                'target' => 'hourly_revenue, hourly_rooms',
                'description' => '钟点房收入和间夜'
            ],
            [
                'source' => '免费房',
                'target' => 'free_rooms',
                'description' => '免费房间夜数'
            ],
            [
                'source' => '餐饮',
                'target' => 'dining_revenue',
                'description' => '餐饮收入'
            ],
            [
                'source' => '小商品',
                'target' => 'goods_revenue',
                'description' => '商品收入'
            ],
            [
                'source' => '会员卡',
                'target' => 'member_card_revenue',
                'description' => '会员卡收入'
            ],
            [
                'source' => '其他消费',
                'target' => 'other_revenue',
                'description' => '其他收入'
            ],
        ];
    }

    /**
     * 解析数字
     */
    private function parseNumber(string $value): float
    {
        // 移除百分号并转换
        if (strpos($value, '%') !== false) {
            return floatval(str_replace('%', '', $value)) / 100;
        }
        return floatval($value);
    }
    
    /**
     * 解析百分比为显示值
     */
    private function parsePercent(string $value): float
    {
        $value = trim($value);
        if (strpos($value, '%') !== false) {
            return floatval(str_replace('%', '', $value));
        }
        $num = floatval($value);
        // 如果小于100，认为是小数形式，乘以100
        return $num < 100 ? $num * 100 : $num;
    }
}
