<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\OnlineDataAnalysisReportService;
use think\Response;
use think\facade\Db;

trait OnlineDataRecordConcern
{
    public function updateData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能修改自己酒店的数据
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权修改该数据');
            }
        }

        // 获取更新字段
        $updateData = [];
        $fields = ['amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score'];
        foreach ($fields as $field) {
            if ($this->request->has($field)) {
                $updateData[$field] = $this->request->post($field);
            }
        }

        if (empty($updateData)) {
            return $this->error('没有要更新的数据');
        }

        $updateData['update_time'] = date('Y-m-d H:i:s');

        try {
            Db::name('online_daily_data')->where('id', $id)->update($updateData);
            OperationLog::record('online_data', 'update', '更新线上数据ID: ' . $id, $this->currentUser->id, $data['system_hotel_id']);
            return $this->success(['id' => $id], '更新成功');
        } catch (\Throwable $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除线上数据
     */
    public function deleteData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能删除自己酒店的数据
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权删除该数据');
            }
        }

        try {
            Db::name('online_daily_data')->where('id', $id)->delete();
            OperationLog::record('online_data', 'delete', '删除线上数据ID: ' . $id, $this->currentUser->id, $data['system_hotel_id']);
            return $this->success(['id' => $id], '删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量删除线上数据
     */
    public function batchDelete(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $ids = $this->request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择要删除的数据');
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (empty($ids)) {
            return $this->error('无效的数据ID');
        }

        // 权限检查
        $query = Db::name('online_daily_data')->whereIn('id', $ids);
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        try {
            $deletedCount = $query->delete();
            OperationLog::record('online_data', 'batch_delete', '批量删除线上数据: ' . $deletedCount . '条', $this->currentUser->id);
            return $this->success(['deleted_count' => $deletedCount], '删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * AI智能分析
     * 基于携程/美团数据进行智能分析，提供经营建议
     */
    public function aiAnalysis(): Response
    {
        $this->checkPermission();

        $hotels = $this->request->post('hotels', []);
        $analysisType = $this->request->post('analysis_type', 'business_overview');
        $includeSuggestions = $this->request->post('include_suggestions', true);

        if (empty($hotels) || !is_array($hotels)) {
            return $this->error('请提供要分析的酒店数据');
        }

        try {
            // 计算统计数据
            $totalRoomNights = 0;
            $totalRoomRevenue = 0;
            $totalSales = 0;
            $totalExposure = 0;
            $totalViews = 0;
            $totalViewConversion = 0;
            $totalPayConversion = 0;

            foreach ($hotels as $hotel) {
                $totalRoomNights += floatval($hotel['roomNights'] ?? 0);
                $totalRoomRevenue += floatval($hotel['roomRevenue'] ?? 0);
                $totalSales += floatval($hotel['sales'] ?? 0);
                $totalExposure += floatval($hotel['exposure'] ?? 0);
                $totalViews += floatval($hotel['views'] ?? 0);
                $totalViewConversion += floatval($hotel['viewConversion'] ?? 0);
                $totalPayConversion += floatval($hotel['payConversion'] ?? 0);
            }

            $hotelCount = count($hotels);
            $avgRoomNights = $hotelCount > 0 ? $totalRoomNights / $hotelCount : 0;
            $avgRoomRevenue = $hotelCount > 0 ? $totalRoomRevenue / $hotelCount : 0;
            $avgPricePerNight = $totalRoomNights > 0 ? $totalRoomRevenue / $totalRoomNights : 0;
            $avgViewConversion = $hotelCount > 0 ? $totalViewConversion / $hotelCount : 0;
            $avgPayConversion = $hotelCount > 0 ? $totalPayConversion / $hotelCount : 0;

            // 排序获取TOP酒店
            $sortByRoomNights = $hotels;
            usort($sortByRoomNights, function($a, $b) {
                return floatval($b['roomNights'] ?? 0) - floatval($a['roomNights'] ?? 0);
            });
            $top5ByRoomNights = array_slice($sortByRoomNights, 0, 5);

            $sortByRevenue = $hotels;
            usort($sortByRevenue, function($a, $b) {
                return floatval($b['roomRevenue'] ?? 0) - floatval($a['roomRevenue'] ?? 0);
            });
            $top5ByRevenue = array_slice($sortByRevenue, 0, 5);

            // 生成分析报告
            $report = $this->generateAnalysisReport([
                'hotel_count' => $hotelCount,
                'total_room_nights' => $totalRoomNights,
                'total_room_revenue' => $totalRoomRevenue,
                'total_sales' => $totalSales,
                'total_exposure' => $totalExposure,
                'total_views' => $totalViews,
                'avg_room_nights' => $avgRoomNights,
                'avg_room_revenue' => $avgRoomRevenue,
                'avg_price_per_night' => $avgPricePerNight,
                'avg_view_conversion' => $avgViewConversion,
                'avg_pay_conversion' => $avgPayConversion,
                'top5_by_room_nights' => $top5ByRoomNights,
                'top5_by_revenue' => $top5ByRevenue,
            ], $includeSuggestions);

            // 记录操作日志
            OperationLog::record('online_data', 'ai_analysis', 'AI智能分析: ' . $hotelCount . '家酒店', $this->currentUser->id);

            return $this->success([
                'report' => $report,
                'summary' => "分析了 {$hotelCount} 家酒店，总入住间夜 " . number_format($totalRoomNights) . "，总房费收入 ¥" . number_format($totalRoomRevenue),
                'data' => [
                    'hotel_count' => $hotelCount,
                    'total_room_nights' => $totalRoomNights,
                    'total_room_revenue' => $totalRoomRevenue,
                    'avg_price_per_night' => round($avgPricePerNight, 2),
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->error('分析失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成分析报告HTML
     */
    private function generateAnalysisReport(array $data, bool $includeSuggestions = true): string
    {
        return (new OnlineDataAnalysisReportService())->render($data, $includeSuggestions);
    }

}
