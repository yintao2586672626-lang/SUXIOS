<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\controller\Base;
use app\model\OperationLog;
use app\model\SystemConfig;
use think\facade\Db;
use think\Response;

class Compass extends Base
{
    private const LAYOUT_KEY = 'compass_layout';

    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->isHotelManager()) {
            abort(403, '无权限操作');
        }
    }

    public function index(): Response
    {
        $this->checkPermission();

        $hotelId = $this->resolveHotelId((string)$this->request->get('hotel_id', ''));
        $payload = $this->buildCompassData($hotelId);

        return view('compass/index', $payload);
    }

    public function apiIndex(): Response
    {
        $this->checkPermission();
        $hotelId = $this->resolveHotelId((string)$this->request->get('hotel_id', ''));
        return $this->success($this->buildCompassData($hotelId));
    }

    public function saveLayout(): Response
    {
        $this->checkPermission();

        $data = $this->request->post();
        $order = $data['order'] ?? [];
        $hidden = $data['hidden'] ?? [];
        if (!is_array($order) || !is_array($hidden)) {
            return json(['code' => 400, 'message' => '参数错误']);
        }

        $allowed = $this->getDefaultLayout()['order'];
        $order = array_values(array_filter($order, fn($key) => in_array($key, $allowed, true)));
        $hidden = array_values(array_filter($hidden, fn($key) => in_array($key, $allowed, true)));
        if (empty($order)) {
            $order = $allowed;
        }

        SystemConfig::setValue(self::LAYOUT_KEY, json_encode([
            'order' => $order,
            'hidden' => $hidden,
        ], JSON_UNESCAPED_UNICODE), '门店罗盘板块布局');

        OperationLog::record('compass', 'update_layout', '更新门店罗盘板块排序', $this->currentUser->id);

        return $this->success(null, '保存成功');
    }

    public function apiSaveLayout(): Response
    {
        return $this->saveLayout();
    }

    private function getLayoutConfig(): array
    {
        $default = $this->getDefaultLayout();
        $raw = SystemConfig::getValue(self::LAYOUT_KEY, '');
        if (!$raw) {
            return $default;
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return $default;
        }
        $order = isset($data['order']) && is_array($data['order']) ? $data['order'] : $default['order'];
        $hidden = isset($data['hidden']) && is_array($data['hidden']) ? $data['hidden'] : [];
        return [
            'order' => $order,
            'hidden' => $hidden,
        ];
    }

    private function getDefaultLayout(): array
    {
        return [
            'order' => ['weather', 'todo', 'metrics', 'alerts', 'holiday'],
            'hidden' => [],
        ];
    }

    private function buildCompassData(int $hotelId): array
    {
        return [
            'layout' => $this->getLayoutConfig(),
            'weather' => $this->getWeatherForecast(),
            'todos' => $this->getTodoList(),
            'metrics' => $this->getMetrics($hotelId),
            'alerts' => $this->getAlerts($hotelId),
            'holidays' => $this->getHolidaySales(),
        ];
    }

    private function resolveHotelId(string $hotelIdParam): int
    {
        if ($this->currentUser && $this->currentUser->isSuperAdmin()) {
            return $hotelIdParam !== '' ? (int)$hotelIdParam : 0;
        }
        $hotelId = (int)($this->currentUser->hotel_id ?? 0);
        if (!$hotelId) {
            abort(403, '您未关联酒店，请联系管理员');
        }
        return $hotelId;
    }

    private function getWeatherForecast(): array
    {
        $conditions = ['晴', '多云', '阴', '小雨', '阵雨', '中雨'];
        $winds = ['东风', '南风', '西风', '北风'];
        $result = [];
        for ($i = 0; $i < 7; $i++) {
            $date = strtotime('+' . $i . ' day');
            $result[] = [
                'date' => date('m-d', $date),
                'week' => ['日', '一', '二', '三', '四', '五', '六'][(int)date('w', $date)],
                'temp_high' => 26 + $i,
                'temp_low' => 18 + $i,
                'condition' => $conditions[$i % count($conditions)],
                'wind' => $winds[$i % count($winds)] . ' 2-3级',
            ];
        }
        return $result;
    }

    private function getTodoList(): array
    {
        return [
            ['title' => '检查今日入住预订确认', 'owner' => '前台', 'deadline' => '10:30', 'status' => '待处理'],
            ['title' => '完成客房巡检与补货', 'owner' => '客房部', 'deadline' => '12:00', 'status' => '进行中'],
            ['title' => '复盘昨日差评并回访', 'owner' => '店长', 'deadline' => '15:00', 'status' => '待处理'],
            ['title' => '核对今日营收与账单', 'owner' => '财务', 'deadline' => '17:00', 'status' => '待处理'],
        ];
    }

    private function getMetrics(int $hotelId): array
    {
        return [
            'day' => $this->getMetricRange('day', $hotelId),
            'week' => $this->getMetricRange('week', $hotelId),
            'month' => $this->getMetricRange('month', $hotelId),
        ];
    }

    private function getMetricRange(string $type, int $hotelId): array
    {
        [$start, $end] = $this->getDateRange($type);

        $dailyQuery = Db::name('daily_reports')
            ->whereBetween('report_date', [$start, $end]);
        if ($hotelId) {
            $dailyQuery->where('hotel_id', $hotelId);
        }

        $checkins = (int)$dailyQuery->sum('room_count');
        $revenue = (float)$dailyQuery->sum('revenue');

        $orderQuery = Db::name('online_daily_data')
            ->whereBetween('data_date', [$start, $end]);
        if ($hotelId) {
            $orderQuery->where('system_hotel_id', $hotelId);
        }
        $orders = (int)$orderQuery->sum('book_order_num');

        return [
            'orders' => $orders,
            'checkins' => $checkins,
            'revenue' => round($revenue, 2),
        ];
    }

    private function getAlerts(int $hotelId): array
    {
        $alerts = [];
        $today = date('Y-m-d');

        $reportQuery = Db::name('daily_reports')->where('report_date', $today);
        if ($hotelId) {
            $reportQuery->where('hotel_id', $hotelId);
        }
        $report = $reportQuery->order('id', 'desc')->find();
        if ($report && isset($report['occupancy_rate']) && (float)$report['occupancy_rate'] >= 95) {
            $alerts[] = [
                'type' => '满房预警',
                'level' => 'red',
                'message' => '今日入住率 ' . $report['occupancy_rate'] . '%，建议检查价格与房态',
            ];
        }

        $onlineQuery = Db::name('online_daily_data')->where('data_date', $today);
        if ($hotelId) {
            $onlineQuery->where('system_hotel_id', $hotelId);
        }
        $online = $onlineQuery->order('id', 'desc')->find();

        $avgPriceToday = 0.0;
        if ($online && (int)$online['quantity'] > 0) {
            $avgPriceToday = (float)$online['amount'] / (int)$online['quantity'];
        }

        $avgPrice7 = $this->getOnlineAvgPrice($hotelId, 7);
        if ($avgPriceToday > 0 && $avgPrice7 > 0 && $avgPriceToday < $avgPrice7 * 0.8) {
            $alerts[] = [
                'type' => '低价房预警',
                'level' => 'yellow',
                'message' => '今日均价低于近7日均价 20%，请关注价格策略',
            ];
        }

        if ($online && ((float)$online['comment_score'] < 4.5 || (float)$online['qunar_comment_score'] < 4.5)) {
            $alerts[] = [
                'type' => '差评预警',
                'level' => 'red',
                'message' => '线上评分偏低，请及时复盘改进',
            ];
        }

        if (empty($alerts)) {
            $alerts[] = [
                'type' => '预警状态',
                'level' => 'yellow',
                'message' => '暂无高优先级预警',
            ];
        }

        return $alerts;
    }

    private function getHolidaySales(): array
    {
        return [
            [
                'name' => '三月三',
                'days' => [
                    ['date' => '03-03', 'king' => 42, 'twin' => 36, 'total' => 78],
                    ['date' => '03-04', 'king' => 46, 'twin' => 40, 'total' => 86],
                    ['date' => '03-05', 'king' => 38, 'twin' => 33, 'total' => 71],
                ],
            ],
            [
                'name' => '五一',
                'days' => [
                    ['date' => '05-01', 'king' => 58, 'twin' => 52, 'total' => 110],
                    ['date' => '05-02', 'king' => 61, 'twin' => 54, 'total' => 115],
                    ['date' => '05-03', 'king' => 55, 'twin' => 50, 'total' => 105],
                ],
            ],
        ];
    }

    private function getOnlineAvgPrice(int $hotelId, int $days): float
    {
        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
        $end = date('Y-m-d');
        $query = Db::name('online_daily_data')->whereBetween('data_date', [$start, $end]);
        if ($hotelId) {
            $query->where('system_hotel_id', $hotelId);
        }
        $amount = (float)$query->sum('amount');
        $quantity = (int)$query->sum('quantity');
        if ($quantity <= 0) {
            return 0.0;
        }
        return $amount / $quantity;
    }

    private function getDateRange(string $type): array
    {
        $today = date('Y-m-d');
        if ($type === 'day') {
            return [$today, $today];
        }
        if ($type === 'week') {
            $start = date('Y-m-d', strtotime('monday this week'));
            $end = date('Y-m-d', strtotime('sunday this week'));
            return [$start, $end];
        }
        $start = date('Y-m-01');
        $end = date('Y-m-t');
        return [$start, $end];
    }
}
