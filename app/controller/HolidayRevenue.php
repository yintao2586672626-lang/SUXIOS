<?php
declare(strict_types=1);

namespace app\controller;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;
use think\Response;
use Throwable;

class HolidayRevenue extends Base
{
    private const DEFAULT_TARGET_ADR = 300;
    private const DEFAULT_ROOM_COUNT = 50;
    private const DEFAULT_TARGET_OCC = 0.85;

    public function countdown(): Response
    {
        if (!$this->currentUser) {
            return $this->error('未登录', 401);
        }

        $year = (int) $this->request->param('year', (int) date('Y'));
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $hotelIds = $this->resolveHotelIds($hotelId);

        if ($hotelIds === null) {
            return $this->error('无权查看该酒店数据', 403);
        }

        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = new DateTimeImmutable('today', $timezone);
        $holiday = $this->findNearestHoliday($year, $today);

        if (!$holiday) {
            return $this->error('未找到节假日数据', 404);
        }

        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['start_date'], $timezone);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['end_date'], $timezone);
        $holidayDays = ((int) $startDate->diff($endDate)->format('%a')) + 1;
        $daysLeft = $today < $startDate ? (int) $today->diff($startDate)->format('%a') : 0;

        $targetRevenue = $this->getTargetRevenue($hotelIds, $year, $holiday['name'], $holidayDays);
        $actualRevenue = $this->getActualRevenue($hotelIds, $holiday['start_date'], $holiday['end_date']);
        $gapRevenue = $targetRevenue - $actualRevenue;
        $completionRate = $targetRevenue > 0 ? round(($actualRevenue / $targetRevenue) * 100, 2) : 0;

        return $this->success([
            'holiday_name' => $holiday['name'],
            'start_date' => $holiday['start_date'],
            'end_date' => $holiday['end_date'],
            'days_left' => $daysLeft,
            'holiday_days' => $holidayDays,
            'stage' => $this->getStage($today, $startDate, $endDate, $daysLeft),
            'target_revenue' => round($targetRevenue, 2),
            'actual_revenue' => round($actualRevenue, 2),
            'gap_revenue' => round($gapRevenue, 2),
            'completion_rate' => $completionRate,
            'suggestions' => $this->getSuggestions($today, $startDate, $endDate, $daysLeft, $holiday['name']),
            'holidays' => $this->getHolidays()[$year] ?? [],
        ]);
    }

    private function getHolidays(): array
    {
        return [
            2026 => [
                ['name' => '元旦', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
                ['name' => '春节', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
                ['name' => '清明节', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
                ['name' => '劳动节', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
                ['name' => '端午节', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
                ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
                ['name' => '国庆节', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            ],
        ];
    }

    private function findNearestHoliday(int $year, DateTimeImmutable $today): ?array
    {
        $holidays = $this->getHolidays()[$year] ?? [];
        if (empty($holidays)) {
            return null;
        }

        $timezone = $today->getTimezone();
        foreach ($holidays as $holiday) {
            $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $holiday['end_date'], $timezone);
            if ($endDate >= $today) {
                return $holiday;
            }
        }

        return $holidays[count($holidays) - 1];
    }

    private function resolveHotelIds(int $hotelId): ?array
    {
        $permittedHotelIds = array_map('intval', $this->currentUser->getPermittedHotelIds());

        if ($hotelId > 0) {
            return in_array($hotelId, $permittedHotelIds, true) ? [$hotelId] : null;
        }

        return $permittedHotelIds;
    }

    private function getTargetRevenue(array $hotelIds, int $year, string $holidayName, int $holidayDays): float
    {
        $configured = $this->getConfiguredTargetRevenue($hotelIds, $year, $holidayName);
        if ($configured !== null) {
            return $configured;
        }

        $roomCount = $this->getRoomCount($hotelIds);
        return $holidayDays * self::DEFAULT_TARGET_ADR * $roomCount * self::DEFAULT_TARGET_OCC;
    }

    private function getConfiguredTargetRevenue(array $hotelIds, int $year, string $holidayName): ?float
    {
        if (count($hotelIds) > 1) {
            $sum = 0.0;
            $matched = false;
            foreach ($hotelIds as $hotelId) {
                $value = $this->getConfiguredTargetRevenueForHotel($hotelId, $year, $holidayName);
                if ($value !== null) {
                    $matched = true;
                    $sum += $value;
                }
            }
            return $matched ? $sum : null;
        }

        return $this->getConfiguredTargetRevenueForHotel($hotelIds[0] ?? 0, $year, $holidayName);
    }

    private function getConfiguredTargetRevenueForHotel(int $hotelId, int $year, string $holidayName): ?float
    {
        foreach (['holiday_revenue_targets', 'holiday_revenue_target'] as $configKey) {
            $rawValue = $this->readConfigValue($configKey);
            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            $directValue = $this->toFloat($rawValue);
            if ($directValue !== null && $directValue > 0) {
                return $directValue;
            }

            $decoded = json_decode((string) $rawValue, true);
            if (!is_array($decoded)) {
                continue;
            }

            $target = $this->matchConfiguredTarget($decoded, $hotelId, $year, $holidayName);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function readConfigValue(string $configKey): ?string
    {
        foreach (['system_config', 'system_configs'] as $table) {
            try {
                $value = Db::name($table)->where('config_key', $configKey)->value('config_value');
                if ($value !== null && $value !== '') {
                    return (string) $value;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function matchConfiguredTarget(array $config, int $hotelId, int $year, string $holidayName): ?float
    {
        $hotelKey = (string) $hotelId;
        $yearKey = (string) $year;
        $paths = [
            [$hotelKey, $yearKey, $holidayName],
            [$yearKey, $hotelKey, $holidayName],
            [$yearKey, $holidayName, $hotelKey],
            [$yearKey, $holidayName],
            [$hotelKey, $holidayName],
            ['hotels', $hotelKey, $yearKey, $holidayName],
            ['years', $yearKey, $holidayName],
            ['holidays', $holidayName],
        ];

        foreach ($paths as $path) {
            $value = $this->valueByPath($config, $path);
            $target = $this->extractTargetValue($value);
            if ($target !== null) {
                return $target;
            }
        }

        foreach ([
            "{$hotelKey}_{$yearKey}_{$holidayName}",
            "{$hotelKey}:{$yearKey}:{$holidayName}",
            "{$yearKey}_{$holidayName}",
            "{$yearKey}:{$holidayName}",
            $holidayName,
        ] as $key) {
            $target = $this->extractTargetValue($config[$key] ?? null);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private function valueByPath(array $config, array $path)
    {
        $value = $config;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function extractTargetValue($value): ?float
    {
        if (is_array($value)) {
            foreach (['target_revenue', 'target', 'revenue'] as $key) {
                $target = $this->toFloat($value[$key] ?? null);
                if ($target !== null && $target > 0) {
                    return $target;
                }
            }
            return null;
        }

        $target = $this->toFloat($value);
        return $target !== null && $target > 0 ? $target : null;
    }

    private function getRoomCount(array $hotelIds): int
    {
        if (empty($hotelIds)) {
            return self::DEFAULT_ROOM_COUNT;
        }

        try {
            $rows = Db::name('hotels')->whereIn('id', $hotelIds)->select()->toArray();
        } catch (Throwable $e) {
            return count($hotelIds) * self::DEFAULT_ROOM_COUNT;
        }

        if (empty($rows)) {
            return self::DEFAULT_ROOM_COUNT;
        }

        $total = 0;
        foreach ($rows as $row) {
            $roomCount = null;
            foreach (['room_count', 'rooms', 'total_rooms', 'total_rooms_count', 'salable_rooms', 'salable_rooms_total'] as $field) {
                $value = $this->toFloat($row[$field] ?? null);
                if ($value !== null && $value > 0) {
                    $roomCount = (int) $value;
                    break;
                }
            }
            $total += $roomCount ?: self::DEFAULT_ROOM_COUNT;
        }

        return max($total, self::DEFAULT_ROOM_COUNT);
    }

    private function getActualRevenue(array $hotelIds, string $startDate, string $endDate): float
    {
        if (empty($hotelIds)) {
            return 0.0;
        }

        try {
            $query = Db::name('daily_reports')
                ->field('revenue,report_data')
                ->whereBetween('report_date', [$startDate, $endDate])
                ->whereIn('hotel_id', $hotelIds);

            $rows = $query->select()->toArray();
        } catch (Throwable $e) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->extractReportRevenue($row);
        }

        return $total;
    }

    private function extractReportRevenue(array $row): float
    {
        $revenue = $this->toFloat($row['revenue'] ?? null);
        if ($revenue !== null && $revenue > 0) {
            return $revenue;
        }

        $reportData = json_decode((string) ($row['report_data'] ?? ''), true);
        if (!is_array($reportData)) {
            return 0.0;
        }

        foreach (['day_revenue', 'total_revenue', 'revenue', 'room_revenue'] as $key) {
            $value = $this->toFloat($reportData[$key] ?? null);
            if ($value !== null && $value > 0) {
                return $value;
            }
        }

        $sum = 0.0;
        foreach ([
            'xb_revenue', 'mt_revenue', 'fliggy_revenue', 'dy_revenue', 'tc_revenue', 'qn_revenue', 'zx_revenue',
            'walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue',
            'wechat_revenue', 'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue',
            'parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue',
        ] as $key) {
            $sum += $this->toFloat($reportData[$key] ?? null) ?? 0;
        }

        return $sum;
    }

    private function getStage(DateTimeImmutable $today, DateTimeImmutable $startDate, DateTimeImmutable $endDate, int $daysLeft): string
    {
        if ($today > $endDate) {
            return '已结束复盘期';
        }
        if ($today >= $startDate && $today <= $endDate) {
            return $today == $startDate ? '当天执行期' : '假期中动态调价期';
        }
        if ($daysLeft >= 15) {
            return '提前30天备战期';
        }
        if ($daysLeft >= 8) {
            return '提前14天拉升期';
        }
        if ($daysLeft >= 4) {
            return '提前7天冲刺期';
        }
        if ($daysLeft >= 1) {
            return '提前3天尾房期';
        }

        return '当天执行期';
    }

    private function getSuggestions(DateTimeImmutable $today, DateTimeImmutable $startDate, DateTimeImmutable $endDate, int $daysLeft, string $holidayName): array
    {
        if ($today > $endDate) {
            return [
                "复盘{$holidayName}收益、ADR与入住率表现",
                '整理渠道价格与库存执行偏差',
                '沉淀下次节假日底价和预售策略',
            ];
        }

        if ($today >= $startDate && $today <= $endDate) {
            return [
                '按实时入住率动态调价',
                '避免低价房型过早售满',
                '保留高价值房型与连住库存',
            ];
        }

        if ($daysLeft > 30) {
            return [
                "设置{$holidayName}节假日底价",
                '检查竞品未来价格与库存',
                '建立节假日收益目标与每日跟进节奏',
            ];
        }

        if ($daysLeft >= 15) {
            return [
                '检查各渠道库存是否充足',
                '优化节假日相关标题与图片',
                '开启预售策略并锁定基础价格',
            ];
        }

        if ($daysLeft >= 8) {
            return [
                '观察竞品价格变化',
                '逐步拉升ADR并保留高价值库存',
                '检查携程/美团价格是否高于平日价',
            ];
        }

        if ($daysLeft >= 4) {
            return [
                '根据订单量调整价格',
                '控制低价房库存',
                '重点检查高需求日期是否已提价',
            ];
        }

        return [
            '重点处理尾房销售',
            '保留高价值房型',
            '检查取消订单后的二次售卖价格',
        ];
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace([',', '¥', '￥', ' '], '', $value);
            return is_numeric($normalized) ? (float) $normalized : null;
        }

        return null;
    }
}
