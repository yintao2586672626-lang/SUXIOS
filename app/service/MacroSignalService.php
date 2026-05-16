<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;
use Throwable;

class MacroSignalService
{
    private const SIGNALS = ['cycle', 'weather', 'channel', 'price', 'demand'];

    public function overview(array $hotelIds = []): array
    {
        return array_map(fn (string $type): array => $this->buildSignal($type, $hotelIds), self::SIGNALS);
    }

    public function detail(string $type, array $hotelIds = []): array
    {
        if (!in_array($type, self::SIGNALS, true)) {
            throw new InvalidArgumentException('未知信号类型');
        }

        $signal = $this->buildSignal($type, $hotelIds);
        $signal['type'] = $type;
        $signal['reasons'] = $signal['reasons'] ?? ['数据依据不足，暂不生成原因判断'];
        $signal['actions'] = $signal['suggestions'];

        return $signal;
    }

    private function buildSignal(string $type, array $hotelIds): array
    {
        return match ($type) {
            'cycle' => $this->cycle($hotelIds),
            'weather' => $this->weather(),
            'channel' => $this->channel($hotelIds),
            'price' => $this->price($hotelIds),
            'demand' => $this->demand($hotelIds),
            default => $this->pending($type, '未知信号', '信号类型暂不支持'),
        };
    }

    private function cycle(array $hotelIds): array
    {
        $online = $this->readOnlineRows($hotelIds, 30);
        $daily = $this->readDailyRows($hotelIds, 30);
        if (empty($online) && empty($daily)) {
            return $this->pending('cycle', '周期信号', '等待节假日、周末与订单节奏数据同步');
        }

        $orders3 = $this->sumOnlineOrders($online, 3);
        $orders7 = $this->sumOnlineOrders($online, 7);
        $orders30 = $this->sumOnlineOrders($online, 30);
        $pace3 = $orders3 / 3;
        $pace30 = $orders30 > 0 ? $orders30 / 30 : 0.0;
        $holiday = $this->nearestHoliday();
        $isWeekendWindow = $this->isWeekendWindow();

        $metrics = [
            $this->metric('近3天订单', $orders3, '单'),
            $this->metric('近7天订单', $orders7, '单'),
            $this->metric('近30天订单', $orders30, '单'),
            $this->metric('周期窗口', $holiday ? $holiday['name'] . ' T-' . $holiday['days_left'] : ($isWeekendWindow ? '周末窗口' : '平日')),
        ];

        $reasons = [];
        $suggestions = [];
        $status = 'stable';
        $statusText = '平稳';
        $level = 'green';
        $summary = '订单节奏处于常规经营区间';

        if ($holiday && $holiday['days_left'] <= 14) {
            $status = 'attention';
            $statusText = '关注';
            $level = 'yellow';
            $summary = "距离{$holiday['name']}还有{$holiday['days_left']}天，进入节假日策略观察期";
            $reasons[] = '近期存在节假日窗口';
            $suggestions[] = '检查节假日底价、库存和连住限制';
        } elseif ($isWeekendWindow) {
            $status = 'attention';
            $statusText = '关注';
            $level = 'yellow';
            $summary = '临近周末，需关注短周期订单变化';
            $reasons[] = '当前处于周末或周末前窗口';
            $suggestions[] = '复核周末房态和低价房库存';
        }

        if ($pace30 > 0 && $pace3 < $pace30 * 0.6) {
            $status = 'risk';
            $statusText = '承压';
            $level = 'red';
            $summary = '近3天订单节奏低于近30天均值';
            $reasons[] = '短期订单节奏弱于月均水平';
            $suggestions[] = '检查渠道曝光、价格和促销状态';
        } elseif ($pace30 > 0 && $pace3 > $pace30 * 1.3) {
            $status = 'opportunity';
            $statusText = '机会';
            $level = 'blue';
            $summary = '近3天订单节奏高于近30天均值';
            $reasons[] = '短期订单节奏走强';
            $suggestions[] = '上调高需求日期价格并保留高价值库存';
        }

        if (empty($suggestions)) {
            $suggestions[] = '保持每日订单节奏复盘';
        }

        return $this->card('cycle', '周期信号', $status, $statusText, $level, $summary, $metrics, $suggestions, '查看详情', $reasons);
    }

    private function weather(): array
    {
        return $this->card(
            'weather',
            '天气信号',
            'pending',
            '待同步',
            'gray',
            '当前未接入天气数据源，暂不生成天气经营判断',
            [$this->metric('天气数据源', '未接入')],
            ['接入天气接口后，联动取消率、到店率和周边游需求判断'],
            '查看详情',
            ['天气接口尚未接入，已预留 weather 信号方法']
        );
    }

    private function channel(array $hotelIds): array
    {
        $rows = $this->readOnlineRows($hotelIds, 30);
        if (empty($rows)) {
            return $this->pending('channel', '渠道信号', '等待 OTA 曝光、点击、访客、转化和订单数据同步');
        }

        $traffic = $this->aggregateTraffic($rows);
        if ($traffic['exposure'] <= 0 && $traffic['visitors'] <= 0 && $traffic['clicks'] <= 0) {
            return $this->pending('channel', '渠道信号', '已同步订单数据，仍缺少曝光、点击或访客指标');
        }

        $metrics = [
            $this->metric('曝光', $this->formatNumber($traffic['exposure'])),
            $this->metric('点击/访客', $this->formatNumber(max($traffic['clicks'], $traffic['visitors']))),
            $this->metric('订单', $this->formatNumber($traffic['orders']), '单'),
            $this->metric('转化率', $traffic['conversion'] !== null ? round($traffic['conversion'], 2) . '%' : '待同步'),
        ];

        $status = 'stable';
        $statusText = '平稳';
        $level = 'green';
        $summary = 'OTA 渠道流量与订单已有可用样本';
        $reasons = ['近30天存在 OTA 流量或订单样本'];
        $suggestions = ['持续跟踪曝光到订单的转化变化'];

        if ($traffic['orders'] <= 0) {
            $status = 'risk';
            $statusText = '承压';
            $level = 'red';
            $summary = '渠道有流量但订单转化不足';
            $reasons[] = '曝光或访客存在，但订单为 0';
            $suggestions[] = '检查价格、房态、图片和促销入口';
        } elseif ($traffic['conversion'] !== null && $traffic['conversion'] < 3) {
            $status = 'attention';
            $statusText = '关注';
            $level = 'yellow';
            $summary = 'OTA 转化率偏低，需要排查渠道承接';
            $reasons[] = '转化率低于 3%';
            $suggestions[] = '优先检查低转化渠道的价格和详情页卖点';
        }

        return $this->card('channel', '渠道信号', $status, $statusText, $level, $summary, $metrics, $suggestions, '去分析', $reasons);
    }

    private function price(array $hotelIds): array
    {
        $online = $this->readOnlineRows($hotelIds, 30);
        $daily = $this->readDailyRows($hotelIds, 30);
        $competitors = $this->readCompetitorPrices($hotelIds, 30);
        $adr = $this->avgAdr($online, $daily);
        $competitorAvg = $this->avgField($competitors, 'price');
        $occupancy = $this->avgOccupancy($daily);
        $conversion = $this->aggregateTraffic($online)['conversion'];

        if ($adr <= 0 && $competitorAvg <= 0) {
            return $this->pending('price', '价格信号', '等待 ADR、竞对均价、价差、入住率和转化数据同步');
        }

        $gap = ($adr > 0 && $competitorAvg > 0) ? $adr - $competitorAvg : null;
        $metrics = [
            $this->metric('ADR', $adr > 0 ? '¥' . round($adr, 0) : '待同步'),
            $this->metric('竞对均价', $competitorAvg > 0 ? '¥' . round($competitorAvg, 0) : '待同步'),
            $this->metric('价差', $gap !== null ? '¥' . round($gap, 0) : '待同步'),
            $this->metric('入住率', $occupancy !== null ? round($occupancy, 1) . '%' : '待同步'),
        ];

        $status = 'stable';
        $statusText = '平稳';
        $level = 'green';
        $summary = '价格指标处于可观察状态';
        $reasons = ['已有价格或竞对样本'];
        $suggestions = ['结合入住率和转化率小步调整价格'];

        if ($gap !== null && $competitorAvg > 0) {
            $gapRate = $gap / $competitorAvg;
            if ($gapRate > 0.15 && (($occupancy !== null && $occupancy < 70) || ($conversion !== null && $conversion < 3))) {
                $status = 'risk';
                $statusText = '承压';
                $level = 'red';
                $summary = '价格高于竞对且入住或转化承压';
                $reasons[] = '价差超过竞对均价 15%，且入住率或转化率偏弱';
                $suggestions[] = '检查高价日期是否需要补促销或调低底价';
            } elseif ($gapRate < -0.15 && $occupancy !== null && $occupancy > 85) {
                $status = 'opportunity';
                $statusText = '机会';
                $level = 'blue';
                $summary = '价格低于竞对且入住率较高，存在提价空间';
                $reasons[] = '价差低于竞对 15% 且入住率较高';
                $suggestions[] = '对高需求日期做阶梯提价';
            }
        }

        return $this->card('price', '价格信号', $status, $statusText, $level, $summary, $metrics, $suggestions, '去分析', $reasons);
    }

    private function demand(array $hotelIds): array
    {
        $online = $this->readOnlineRows($hotelIds, 30);
        $forecasts = $this->readDemandForecasts($hotelIds);
        $orders3 = $this->sumOnlineOrders($online, 3);
        $orders7 = $this->sumOnlineOrders($online, 7);
        $cancelOrders = $this->sumCancelOrders($online, 7);
        $futureOccupancy = $this->avgField($forecasts, 'predicted_occupancy');
        $futureDemand = (int)$this->sumField($forecasts, 'predicted_demand');

        if (empty($online) && empty($forecasts)) {
            return $this->pending('demand', '需求信号', '等待未来入住、近3天新增订单、近7天新增订单和取消订单数据同步');
        }

        $metrics = [
            $this->metric('未来入住', $futureOccupancy > 0 ? round($futureOccupancy, 1) . '%' : '待同步'),
            $this->metric('未来需求', $futureDemand > 0 ? $futureDemand : '待同步', $futureDemand > 0 ? '间夜' : ''),
            $this->metric('近3天订单', $orders3, '单'),
            $this->metric('近7天订单', $orders7, '单'),
            $this->metric('近7天取消', $cancelOrders, '单'),
        ];

        $status = 'stable';
        $statusText = '平稳';
        $level = 'green';
        $summary = '需求指标已有样本，暂未出现明显异常';
        $reasons = ['存在近期订单或未来需求样本'];
        $suggestions = ['持续观察未来 30 天入住和新增订单节奏'];

        if ($cancelOrders > 0 && $orders7 > 0 && $cancelOrders / max($orders7, 1) >= 0.25) {
            $status = 'risk';
            $statusText = '承压';
            $level = 'red';
            $summary = '取消订单占比偏高，需求质量需要复核';
            $reasons[] = '近7天取消订单占新增订单比例较高';
            $suggestions[] = '检查取消来源并做二次售卖价格策略';
        } elseif (($futureOccupancy >= 85) || ($orders7 > 0 && $orders3 / 3 > ($orders7 / 7) * 1.25)) {
            $status = 'opportunity';
            $statusText = '机会';
            $level = 'blue';
            $summary = '未来入住或短期订单节奏走强';
            $reasons[] = '未来入住率高或近3天订单节奏快于近7天';
            $suggestions[] = '保留高价值库存并逐步上调高需求日期价格';
        }

        return $this->card('demand', '需求信号', $status, $statusText, $level, $summary, $metrics, $suggestions, '查看详情', $reasons);
    }

    private function readDailyRows(array $hotelIds, int $days): array
    {
        $start = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));

        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('daily_reports')
                ->field('hotel_id,report_date,report_data,occupancy_rate,room_count,revenue')
                ->where('report_date', '>=', $start),
            'hotel_id',
            $hotelIds
        )->order('report_date', 'desc')->select()->toArray());
    }

    private function readOnlineRows(array $hotelIds, int $days): array
    {
        $start = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));

        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('online_daily_data')
                ->field('system_hotel_id,data_date,amount,quantity,book_order_num,raw_data,source,dimension,data_type,data_value')
                ->where('data_date', '>=', $start),
            'system_hotel_id',
            $hotelIds
        )->order('data_date', 'desc')->select()->toArray());
    }

    private function readCompetitorPrices(array $hotelIds, int $days): array
    {
        $start = date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' days'));

        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('competitor_price_log')
                ->field('store_id,hotel_id,price,fetch_time,create_time')
                ->where('price', '>', 0)
                ->where(function ($query) use ($start) {
                    $query->where('fetch_time', '>=', $start)->whereOr('create_time', '>=', $start);
                }),
            'store_id',
            $hotelIds
        )->order('id', 'desc')->limit(200)->select()->toArray());
    }

    private function readDemandForecasts(array $hotelIds): array
    {
        $today = date('Y-m-d');
        $end = date('Y-m-d', strtotime('+30 days'));

        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('demand_forecasts')
                ->field('hotel_id,forecast_date,predicted_occupancy,predicted_demand,confidence_score,event_type')
                ->whereBetween('forecast_date', [$today, $end]),
            'hotel_id',
            $hotelIds
        )->order('forecast_date', 'asc')->select()->toArray());
    }

    private function withHotelIds($query, string $field, array $hotelIds)
    {
        $hotelIds = array_values(array_map('intval', $hotelIds));
        if ($hotelIds === [0]) {
            return $query->where($field, 0);
        }

        $hotelIds = array_values(array_filter($hotelIds, fn (int $id): bool => $id > 0));
        if (!empty($hotelIds)) {
            $query->whereIn($field, $hotelIds);
        }

        return $query;
    }

    private function safeRows(callable $reader): array
    {
        try {
            $rows = $reader();
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function aggregateTraffic(array $rows): array
    {
        $exposure = 0.0;
        $clicks = 0.0;
        $visitors = 0.0;
        $orders = 0.0;
        $conversionSamples = [];

        foreach ($rows as $row) {
            $raw = $this->decodeJson($row['raw_data'] ?? null);
            $dimension = (string)($row['dimension'] ?? '');
            $dataValue = $this->toFloat($row['data_value'] ?? null) ?? 0.0;

            $exposure += $this->firstNumber($raw, ['exposure', 'exposureNum', 'showCount', 'impression', 'displayNum']) ?? 0.0;
            $clicks += $this->firstNumber($raw, ['clicks', 'clickNum', 'detailClickNum']) ?? 0.0;
            $visitors += $this->firstNumber($raw, ['visitors', 'visitorNum', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors', 'uv']) ?? 0.0;
            $orders += $this->toFloat($row['book_order_num'] ?? null) ?? 0.0;

            $conversion = $this->firstNumber($raw, ['conversionRate', 'convertionRate', 'detailCR', 'qunarDetailCR', 'orderRate']);
            if ($conversion !== null && $conversion > 0) {
                $conversionSamples[] = $conversion <= 1 ? $conversion * 100 : $conversion;
            }

            if ($dataValue > 0) {
                if (str_contains($dimension, '曝光')) {
                    $exposure += $dataValue;
                } elseif (str_contains($dimension, '点击')) {
                    $clicks += $dataValue;
                } elseif (str_contains($dimension, '浏览') || str_contains($dimension, '访客')) {
                    $visitors += $dataValue;
                } elseif (str_contains($dimension, '转化')) {
                    $conversionSamples[] = $dataValue <= 1 ? $dataValue * 100 : $dataValue;
                }
            }
        }

        $base = max($clicks, $visitors);
        $conversion = !empty($conversionSamples)
            ? array_sum($conversionSamples) / count($conversionSamples)
            : ($base > 0 ? $orders / $base * 100 : null);

        return [
            'exposure' => $exposure,
            'clicks' => $clicks,
            'visitors' => $visitors,
            'orders' => $orders,
            'conversion' => $conversion,
        ];
    }

    private function avgAdr(array $online, array $daily): float
    {
        $amount = 0.0;
        $quantity = 0.0;
        foreach ($online as $row) {
            $amount += $this->toFloat($row['amount'] ?? null) ?? 0.0;
            $quantity += $this->toFloat($row['quantity'] ?? null) ?? 0.0;
        }
        if ($amount > 0 && $quantity > 0) {
            return $amount / $quantity;
        }

        $samples = [];
        foreach ($daily as $row) {
            $data = $this->decodeJson($row['report_data'] ?? null);
            $adr = $this->firstNumber($data, ['adr', 'ADR', 'avg_room_price', 'day_adr']);
            if ($adr !== null && $adr > 0) {
                $samples[] = $adr;
            }
        }

        return empty($samples) ? 0.0 : array_sum($samples) / count($samples);
    }

    private function avgOccupancy(array $daily): ?float
    {
        $samples = [];
        foreach ($daily as $row) {
            $data = $this->decodeJson($row['report_data'] ?? null);
            $value = $this->toFloat($row['occupancy_rate'] ?? null)
                ?? $this->firstNumber($data, ['occupancy_rate', 'occ', 'day_occ_rate']);
            if ($value !== null && $value > 0) {
                $samples[] = $value <= 1 ? $value * 100 : $value;
            }
        }

        return empty($samples) ? null : array_sum($samples) / count($samples);
    }

    private function sumOnlineOrders(array $rows, int $days): int
    {
        $start = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
        $total = 0.0;
        foreach ($rows as $row) {
            if ((string)($row['data_date'] ?? '') < $start) {
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? null);
            $total += $this->toFloat($row['book_order_num'] ?? null)
                ?? $this->firstNumber($raw, ['bookOrderNum', 'orderCount', 'orders', 'order_submit_num'])
                ?? 0.0;
        }

        return (int)round($total);
    }

    private function sumCancelOrders(array $rows, int $days): int
    {
        $start = date('Y-m-d', strtotime('-' . max(1, $days - 1) . ' days'));
        $total = 0.0;
        foreach ($rows as $row) {
            if ((string)($row['data_date'] ?? '') < $start) {
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? null);
            $total += $this->firstNumber($raw, ['cancel_order_num', 'cancelOrderNum', 'cancelOrders', 'cancel_count']) ?? 0.0;
        }

        return (int)round($total);
    }

    private function avgField(array $rows, string $field): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->toFloat($row[$field] ?? null);
            if ($value !== null && $value > 0) {
                $values[] = $value;
            }
        }

        return empty($values) ? 0.0 : array_sum($values) / count($values);
    }

    private function sumField(array $rows, string $field): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->toFloat($row[$field] ?? null) ?? 0.0;
        }

        return $total;
    }

    private function nearestHoliday(): ?array
    {
        $today = date('Y-m-d');
        foreach ($this->holidays((int)date('Y')) as $holiday) {
            if ($holiday['end_date'] < $today) {
                continue;
            }
            $holiday['days_left'] = max(0, (int)floor((strtotime($holiday['start_date']) - strtotime($today)) / 86400));
            return $holiday;
        }

        return null;
    }

    private function holidays(int $year): array
    {
        $map = [
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

        return $map[$year] ?? [];
    }

    private function isWeekendWindow(): bool
    {
        $weekday = (int)date('N');
        return $weekday >= 5;
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstNumber(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->toFloat($data[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        if (is_string($value)) {
            $normalized = str_replace([',', '¥', '￥', '%', ' '], '', $value);
            return is_numeric($normalized) ? (float)$normalized : null;
        }

        return null;
    }

    private function formatNumber(float $value): string
    {
        if ($value >= 10000) {
            return round($value / 10000, 1) . '万';
        }

        return (string)(int)round($value);
    }

    private function metric(string $label, $value, string $unit = ''): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit];
    }

    private function pending(string $key, string $title, string $summary): array
    {
        return $this->card(
            $key,
            $title,
            'pending',
            '待同步',
            'gray',
            $summary,
            [$this->metric('数据状态', '待同步')],
            ['同步对应经营数据后自动生成判断'],
            '查看详情',
            ['当前数据样本不足']
        );
    }

    private function card(
        string $key,
        string $title,
        string $status,
        string $statusText,
        string $level,
        string $summary,
        array $metrics,
        array $suggestions,
        string $actionText,
        array $reasons = []
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'status_text' => $statusText,
            'level' => $level,
            'summary' => $summary,
            'metrics' => $metrics,
            'suggestions' => array_values(array_unique($suggestions)),
            'action_text' => $actionText,
            'updated_at' => date('Y-m-d H:i:s'),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}
