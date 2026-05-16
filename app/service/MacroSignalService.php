<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;
use Throwable;

class MacroSignalService
{
    private const SIGNALS = ['cycle', 'weather', 'channel', 'price', 'demand'];

    private ExternalSignalService $external;

    public function __construct(?ExternalSignalService $external = null)
    {
        $this->external = $external ?: new ExternalSignalService();
    }

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

    public function trendOverview(array $hotelIds = [], string $range = '30', string $startDate = '', string $endDate = ''): array
    {
        [$startDate, $endDate, $rangeKey, $rangeLabel] = $this->resolveTrendRange($range, $startDate, $endDate);

        $dailyRows = $this->readDailyRowsBetween($hotelIds, $startDate, $endDate);
        $onlineRows = $this->readOnlineRowsBetween($hotelIds, $startDate, $endDate);
        $competitorRows = $this->readCompetitorPricesBetween($hotelIds, $startDate, $endDate);
        $forecasts = $this->readDemandForecasts($hotelIds);
        $series = $this->buildTrendSeries($dailyRows, $onlineRows, $competitorRows, $startDate, $endDate);
        $sampleDays = count(array_filter($series['rows'], static fn (array $row): bool => (bool)$row['has_sample']));
        $hasSamples = $sampleDays >= 2;

        $cards = [
            $this->buildRevenueTrendCard($series['rows'], $rangeLabel, $hasSamples),
            $this->buildDemandTrendCard($series['rows'], $forecasts, $rangeLabel, $hasSamples),
            $this->buildPriceTrendCard($series['rows'], $series['competitor_avg'], $rangeLabel, $hasSamples),
            $this->buildChannelTrendCard($series['rows'], $rangeLabel, $hasSamples),
        ];

        return [
            'range' => [
                'key' => $rangeKey,
                'label' => $rangeLabel,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'data_status' => $hasSamples ? 'ok' : 'insufficient',
            'sample_days' => $sampleDays,
            'updated_at' => date('Y-m-d H:i:s'),
            'cards' => $cards,
            'chart' => [
                'labels' => array_column($series['rows'], 'label'),
                'metrics' => [
                    'revenue' => ['label' => '营收', 'unit' => '¥', 'data' => array_column($series['rows'], 'revenue')],
                    'occupancy' => ['label' => '入住率', 'unit' => '%', 'data' => array_column($series['rows'], 'occupancy')],
                    'adr' => ['label' => 'ADR', 'unit' => '¥', 'data' => array_column($series['rows'], 'adr')],
                    'revpar' => ['label' => 'RevPAR', 'unit' => '¥', 'data' => array_column($series['rows'], 'revpar')],
                    'room_nights' => ['label' => '间夜', 'unit' => '间', 'data' => array_column($series['rows'], 'room_nights')],
                ],
            ],
            'interpretation' => $this->buildTrendInterpretation($cards, $sampleDays, $rangeLabel),
        ];
    }

    private function buildSignal(string $type, array $hotelIds): array
    {
        return match ($type) {
            'cycle' => $this->cycle($hotelIds),
            'weather' => $this->weather($hotelIds),
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

    private function weather(array $hotelIds): array
    {
        $forecast = $this->buildWeatherForecast($hotelIds);
        if (empty($forecast)) {
            return $this->pending('weather', '天气信号', '等待门店城市天气数据同步');
        }

        $today = $forecast[0];
        $rainDays = count(array_filter($forecast, fn (array $item): bool => str_contains((string)($item['condition'] ?? ''), '雨')));
        $highTempDays = count(array_filter($forecast, fn (array $item): bool => (float)($item['temp_high'] ?? 0) >= 30));
        $highs = array_map(fn (array $item): float => (float)($item['temp_high'] ?? 0), $forecast);
        $lows = array_map(fn (array $item): float => (float)($item['temp_low'] ?? 0), $forecast);
        $location = (string)($today['location'] ?? '本地');

        $status = 'stable';
        $statusText = '平稳';
        $level = 'green';
        $summary = "未来7天{$location}天气整体平稳，可作为需求判断辅助因子";
        $reasons = ['已读取门店罗盘7日天气预测'];
        $suggestions = ['保持常规房态与价格观察，结合订单节奏判断需求变化'];

        if ($rainDays >= 3) {
            $status = 'attention';
            $statusText = '关注';
            $level = 'yellow';
            $summary = "未来7天{$location}有{$rainDays}天降雨，需关注取消率、到店率与交通接驳";
            $reasons[] = '连续或多日降雨会影响出行确定性';
            $suggestions = ['提前检查可取消订单、到店提醒和停车/接驳信息', '关注临近入住订单的退订与改期变化'];
        } elseif ($rainDays > 0) {
            $status = 'attention';
            $statusText = '关注';
            $level = 'yellow';
            $summary = "未来7天{$location}有{$rainDays}天降雨，关注短途出行与临近订单波动";
            $reasons[] = '存在降雨天气窗口';
            $suggestions = ['对降雨日期加强到店提醒', '复核当日低价房库存和取消订单二次售卖'];
        } elseif ($highTempDays >= 3) {
            $status = 'opportunity';
            $statusText = '机会';
            $level = 'blue';
            $summary = "未来7天{$location}高温天较多，关注避暑、亲子和夜间消费需求";
            $reasons[] = '高温天气可能带动避暑及本地休闲需求';
            $suggestions = ['突出清凉、亲子、停车等卖点', '关注夜间入住与周边游套餐机会'];
        }

        $card = $this->card(
            'weather',
            '天气信号',
            $status,
            $statusText,
            $level,
            $summary,
            [
                $this->metric('城市', $location),
                $this->metric('今日天气', ($today['condition'] ?? '--') . ' ' . ($today['temp_low'] ?? '--') . '°~' . ($today['temp_high'] ?? '--') . '°'),
                $this->metric('7日雨天', $rainDays, '天'),
                $this->metric('温度区间', (int)min($lows) . '°~' . (int)max($highs) . '°'),
            ],
            $suggestions,
            '查看影响',
            $reasons
        );
        $card['forecast'] = $forecast;
        $card['source_text'] = 'AMap weather';

        return $card;
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

    private function resolveTrendRange(string $range, string $startDate, string $endDate): array
    {
        $today = date('Y-m-d');
        $range = trim($range) !== '' ? trim($range) : '30';

        if (in_array($range, ['7', 'last_7_days'], true)) {
            return [date('Y-m-d', strtotime('-6 days')), $today, '7', '近7日'];
        }
        if (in_array($range, ['month', 'this_month'], true)) {
            return [date('Y-m-01'), $today, 'month', '本月'];
        }
        if ($range === 'custom' && $this->isValidDate($startDate) && $this->isValidDate($endDate)) {
            if (strtotime($startDate) > strtotime($endDate)) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }
            return [$startDate, $endDate, 'custom', '自定义'];
        }

        return [date('Y-m-d', strtotime('-29 days')), $today, '30', '近30日'];
    }

    private function isValidDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }

        $time = strtotime($date);
        return $time !== false && date('Y-m-d', $time) === $date;
    }

    private function buildTrendSeries(array $dailyRows, array $onlineRows, array $competitorRows, string $startDate, string $endDate): array
    {
        $rows = [];
        for ($cursor = strtotime($startDate), $end = strtotime($endDate); $cursor <= $end; $cursor = strtotime('+1 day', $cursor)) {
            $date = date('Y-m-d', $cursor);
            $rows[$date] = [
                'date' => $date,
                'label' => date('m-d', $cursor),
                'daily_revenue' => 0.0,
                'online_revenue' => 0.0,
                'daily_room_nights' => 0.0,
                'online_room_nights' => 0.0,
                'orders' => 0.0,
                'salable_rooms' => 0.0,
                'occupancy_sum' => 0.0,
                'occupancy_count' => 0,
                'adr_sum' => 0.0,
                'adr_count' => 0,
                'revpar_sum' => 0.0,
                'revpar_count' => 0,
                'exposure' => 0.0,
                'visitors' => 0.0,
                'clicks' => 0.0,
                'conversion_sum' => 0.0,
                'conversion_count' => 0,
                'revenue' => null,
                'room_nights' => null,
                'occupancy' => null,
                'adr' => null,
                'revpar' => null,
                'channel_conversion' => null,
                'has_sample' => false,
            ];
        }

        foreach ($dailyRows as $row) {
            $date = (string)($row['report_date'] ?? '');
            if (!isset($rows[$date])) {
                continue;
            }
            $data = $this->decodeJson($row['report_data'] ?? null);
            $revenue = $this->dailyReportRevenue($row, $data);
            $roomNights = $this->dailyReportRoomNights($data);
            $salableRooms = $this->firstPositiveNumber($data, ['salable_rooms', 'total_rooms_count', 'room_count'])
                ?? $this->toPositiveFloat($row['room_count'] ?? null)
                ?? 0.0;
            $occupancy = $this->toPositiveFloat($row['occupancy_rate'] ?? null)
                ?? $this->firstPositiveNumber($data, ['day_occ_rate', 'occ_rate', 'occupancy_rate', 'occ']);
            $adr = $this->firstPositiveNumber($data, ['day_adr', 'adr', 'ADR', 'avg_room_price', 'day_avg_price']);
            $revpar = $this->firstPositiveNumber($data, ['day_revpar', 'revpar', 'RevPAR']);

            $rows[$date]['daily_revenue'] += $revenue;
            $rows[$date]['daily_room_nights'] += $roomNights;
            $rows[$date]['salable_rooms'] += $salableRooms;
            if ($occupancy !== null) {
                $rows[$date]['occupancy_sum'] += $occupancy <= 1 ? $occupancy * 100 : $occupancy;
                $rows[$date]['occupancy_count']++;
            }
            if ($adr !== null) {
                $rows[$date]['adr_sum'] += $adr;
                $rows[$date]['adr_count']++;
            }
            if ($revpar !== null) {
                $rows[$date]['revpar_sum'] += $revpar;
                $rows[$date]['revpar_count']++;
            }
        }

        foreach ($onlineRows as $row) {
            $date = (string)($row['data_date'] ?? '');
            if (!isset($rows[$date])) {
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? null);
            $amount = $this->toPositiveFloat($row['amount'] ?? null) ?? 0.0;
            $quantity = $this->toPositiveFloat($row['quantity'] ?? null) ?? 0.0;
            $orders = $this->toPositiveFloat($row['book_order_num'] ?? null)
                ?? $this->firstPositiveNumber($raw, ['bookOrderNum', 'orderCount', 'orders', 'order_submit_num'])
                ?? 0.0;
            $dataValue = $this->toPositiveFloat($row['data_value'] ?? null) ?? 0.0;
            $dimension = (string)($row['dimension'] ?? '');

            $rows[$date]['online_revenue'] += $amount;
            $rows[$date]['online_room_nights'] += $quantity;
            $rows[$date]['orders'] += $orders;
            $rows[$date]['exposure'] += $this->firstPositiveNumber($raw, ['exposure', 'exposureNum', 'showCount', 'impression', 'displayNum']) ?? 0.0;
            $rows[$date]['clicks'] += $this->firstPositiveNumber($raw, ['clicks', 'clickNum', 'detailClickNum']) ?? 0.0;
            $rows[$date]['visitors'] += $this->firstPositiveNumber($raw, ['visitors', 'visitorNum', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors', 'uv']) ?? 0.0;

            $conversion = $this->firstPositiveNumber($raw, ['conversionRate', 'convertionRate', 'detailCR', 'qunarDetailCR', 'orderRate']);
            if ($conversion !== null) {
                $rows[$date]['conversion_sum'] += $conversion <= 1 ? $conversion * 100 : $conversion;
                $rows[$date]['conversion_count']++;
            }
            if ($dataValue > 0) {
                if (str_contains($dimension, '曝光')) {
                    $rows[$date]['exposure'] += $dataValue;
                } elseif (str_contains($dimension, '点击')) {
                    $rows[$date]['clicks'] += $dataValue;
                } elseif (str_contains($dimension, '浏览') || str_contains($dimension, '访客')) {
                    $rows[$date]['visitors'] += $dataValue;
                } elseif (str_contains($dimension, '转化')) {
                    $rows[$date]['conversion_sum'] += $dataValue <= 1 ? $dataValue * 100 : $dataValue;
                    $rows[$date]['conversion_count']++;
                }
            }
        }

        foreach ($rows as &$row) {
            $revenue = $row['daily_revenue'] > 0 ? $row['daily_revenue'] : $row['online_revenue'];
            $roomNights = $row['daily_room_nights'] > 0 ? $row['daily_room_nights'] : $row['online_room_nights'];
            $row['revenue'] = $revenue > 0 ? round($revenue, 2) : null;
            $row['room_nights'] = $roomNights > 0 ? round($roomNights, 2) : null;
            $row['occupancy'] = $row['salable_rooms'] > 0 && $roomNights > 0
                ? round(min(100, $roomNights / $row['salable_rooms'] * 100), 2)
                : ($row['occupancy_count'] > 0 ? round($row['occupancy_sum'] / $row['occupancy_count'], 2) : null);
            $row['adr'] = $roomNights > 0 && $revenue > 0
                ? round($revenue / $roomNights, 2)
                : ($row['adr_count'] > 0 ? round($row['adr_sum'] / $row['adr_count'], 2) : null);
            $row['revpar'] = $row['salable_rooms'] > 0 && $revenue > 0
                ? round($revenue / $row['salable_rooms'], 2)
                : ($row['revpar_count'] > 0 ? round($row['revpar_sum'] / $row['revpar_count'], 2) : null);

            $trafficBase = max($row['clicks'], $row['visitors']);
            $row['channel_conversion'] = $row['conversion_count'] > 0
                ? round($row['conversion_sum'] / $row['conversion_count'], 2)
                : ($trafficBase > 0 && $row['orders'] > 0 ? round($row['orders'] / $trafficBase * 100, 2) : null);
            $row['has_sample'] = $row['revenue'] !== null
                || $row['room_nights'] !== null
                || $row['orders'] > 0
                || $row['occupancy'] !== null
                || $row['adr'] !== null;
        }
        unset($row);

        return [
            'rows' => array_values($rows),
            'competitor_avg' => $this->avgField($competitorRows, 'price'),
        ];
    }

    private function dailyReportRevenue(array $row, array $data): float
    {
        $revenue = $this->toPositiveFloat($row['revenue'] ?? null)
            ?? $this->firstPositiveNumber($data, ['day_total_revenue', 'total_revenue', 'room_revenue', 'day_room_revenue']);
        if ($revenue !== null) {
            return $revenue;
        }

        return $this->sumReportFields($data, [
            'xb_revenue', 'mt_revenue', 'fliggy_revenue', 'dy_revenue', 'tc_revenue', 'qn_revenue', 'zx_revenue',
            'walkin_revenue', 'member_exp_revenue', 'web_exp_revenue', 'group_revenue', 'protocol_revenue', 'wechat_revenue',
            'free_revenue', 'gold_card_revenue', 'black_gold_revenue', 'hourly_revenue',
            'parking_revenue', 'dining_revenue', 'meeting_revenue', 'goods_revenue', 'member_card_revenue', 'other_revenue',
        ]);
    }

    private function dailyReportRoomNights(array $data): float
    {
        $rooms = $this->firstPositiveNumber($data, ['day_total_rooms', 'total_rooms', 'room_nights']);
        if ($rooms !== null) {
            return $rooms;
        }

        return $this->sumReportFields($data, [
            'xb_rooms', 'mt_rooms', 'fliggy_rooms', 'dy_rooms', 'tc_rooms', 'qn_rooms', 'zx_rooms',
            'walkin_rooms', 'member_exp_rooms', 'web_exp_rooms', 'group_rooms', 'protocol_rooms', 'wechat_rooms',
            'free_rooms', 'gold_card_rooms', 'black_gold_rooms',
        ]);
    }

    private function sumReportFields(array $data, array $fields): float
    {
        $total = 0.0;
        foreach ($fields as $field) {
            $total += $this->toPositiveFloat($data[$field] ?? null) ?? 0.0;
        }

        return $total;
    }

    private function buildRevenueTrendCard(array $rows, string $rangeLabel, bool $hasSamples): array
    {
        if (!$hasSamples) {
            return $this->trendPendingCard('revenue', '收益趋势', '等待线上数据或经营日报同步后生成收益趋势');
        }

        $values = array_column($rows, 'revenue');
        $trend = $this->compareSeries($values);
        $total = array_sum(array_map(static fn ($value): float => (float)($value ?? 0), $values));
        $direction = $this->trendDirectionText($trend);
        return [
            'key' => 'revenue',
            'name' => '收益趋势',
            'value' => $this->formatMoneyShort($total),
            'direction' => $direction,
            'level' => $trend['level'],
            'note' => "{$rangeLabel}营收{$direction}，较前段" . $this->formatChangeRate($trend['change_rate']),
            'spark' => $this->sparkline($values),
            'change_rate' => $trend['change_rate'],
        ];
    }

    private function buildDemandTrendCard(array $rows, array $forecasts, string $rangeLabel, bool $hasSamples): array
    {
        $orderValues = array_map(static fn (array $row): ?float => $row['orders'] > 0 ? (float)$row['orders'] : null, $rows);
        $forecastDemand = (int)$this->sumField($forecasts, 'predicted_demand');
        if (!$hasSamples && $forecastDemand <= 0) {
            return $this->trendPendingCard('demand', '市场需求', '数据依据不足，暂不生成趋势判断');
        }

        $trend = $this->compareSeries($orderValues);
        $orders = array_sum(array_map(static fn ($value): float => (float)($value ?? 0), $orderValues));
        $direction = $this->trendDirectionText($trend);
        $value = $orders > 0 ? (int)round($orders) . '单' : $forecastDemand . '间夜';
        $note = $orders > 0
            ? "{$rangeLabel}订单{$direction}，较前段" . $this->formatChangeRate($trend['change_rate'])
            : '已读取未来需求预测，等待订单样本校准';

        return [
            'key' => 'demand',
            'name' => '市场需求',
            'value' => $value,
            'direction' => $orders > 0 ? $direction : '预测可用',
            'level' => $orders > 0 ? $trend['level'] : 'blue',
            'note' => $note,
            'spark' => $this->sparkline($orderValues),
            'change_rate' => $trend['change_rate'],
        ];
    }

    private function buildPriceTrendCard(array $rows, float $competitorAvg, string $rangeLabel, bool $hasSamples): array
    {
        $values = array_column($rows, 'adr');
        $avgAdr = $this->avgNumeric($values);
        if (!$hasSamples || $avgAdr <= 0) {
            return $this->trendPendingCard('price', '价格竞争', '等待 ADR 或竞对价格同步后判断价格走势');
        }

        $trend = $this->compareSeries($values);
        $direction = $this->trendDirectionText($trend);
        $level = $trend['level'];
        $note = "{$rangeLabel}ADR{$direction}，较前段" . $this->formatChangeRate($trend['change_rate']);
        $badge = $direction;

        if ($competitorAvg > 0) {
            $gap = $avgAdr - $competitorAvg;
            $gapRate = $gap / $competitorAvg;
            if ($gapRate > 0.15) {
                $badge = '高于竞对';
                $level = 'yellow';
            } elseif ($gapRate < -0.15) {
                $badge = '低于竞对';
                $level = 'blue';
            } else {
                $badge = '接近竞对';
                $level = 'green';
            }
            $note = '本店ADR较竞对均价' . ($gap >= 0 ? '高' : '低') . '¥' . abs((int)round($gap)) . '，需结合入住和转化判断';
        }

        return [
            'key' => 'price',
            'name' => '价格竞争',
            'value' => '¥' . (int)round($avgAdr),
            'direction' => $badge,
            'level' => $level,
            'note' => $note,
            'spark' => $this->sparkline($values),
            'change_rate' => $trend['change_rate'],
        ];
    }

    private function buildChannelTrendCard(array $rows, string $rangeLabel, bool $hasSamples): array
    {
        $conversionValues = array_column($rows, 'channel_conversion');
        $avgConversion = $this->avgNumeric($conversionValues);
        $orders = array_sum(array_map(static fn (array $row): float => (float)$row['orders'], $rows));
        $exposure = array_sum(array_map(static fn (array $row): float => (float)$row['exposure'], $rows));
        if (!$hasSamples || ($avgConversion <= 0 && $orders <= 0 && $exposure <= 0)) {
            return $this->trendPendingCard('channel', '渠道表现', '等待 OTA 曝光、访客、转化和订单数据同步');
        }

        $trend = $this->compareSeries($avgConversion > 0 ? $conversionValues : array_map(static fn (array $row): ?float => $row['orders'] > 0 ? (float)$row['orders'] : null, $rows));
        $direction = $this->trendDirectionText($trend);
        $level = $trend['level'];
        $value = $avgConversion > 0 ? round($avgConversion, 1) . '%' : (int)round($orders) . '单';
        if ($avgConversion > 0 && $avgConversion < 3) {
            $level = 'yellow';
            $direction = '转化偏低';
        }

        return [
            'key' => 'channel',
            'name' => '渠道表现',
            'value' => $value,
            'direction' => $direction,
            'level' => $level,
            'note' => $avgConversion > 0
                ? "{$rangeLabel}OTA平均转化率{$value}，持续跟踪曝光到订单效率"
                : "{$rangeLabel}OTA订单{$direction}，较前段" . $this->formatChangeRate($trend['change_rate']),
            'spark' => $this->sparkline($avgConversion > 0 ? $conversionValues : array_column($rows, 'orders')),
            'change_rate' => $trend['change_rate'],
        ];
    }

    private function buildTrendInterpretation(array $cards, int $sampleDays, string $rangeLabel): array
    {
        if ($sampleDays < 2) {
            return [
                'level' => 'gray',
                'judgement' => '等待数据形成判断',
                'change' => '完成经营数据同步后生成趋势判断',
                'action' => '进入酒店AI工具箱或数据中心，基于最新经营数据生成分析。',
            ];
        }

        $priority = ['red' => 4, 'yellow' => 3, 'blue' => 2, 'green' => 1, 'gray' => 0];
        usort($cards, static fn (array $a, array $b): int => ($priority[$b['level'] ?? 'gray'] ?? 0) <=> ($priority[$a['level'] ?? 'gray'] ?? 0));
        $main = $cards[0];
        $weak = array_values(array_filter($cards, static fn (array $card): bool => in_array($card['level'] ?? '', ['red', 'yellow'], true)));
        $opportunity = array_values(array_filter($cards, static fn (array $card): bool => ($card['level'] ?? '') === 'blue'));

        if (!empty($weak)) {
            $action = '优先复核' . implode('、', array_column($weak, 'name')) . '，从价格、房态、渠道转化和数据完整性逐项排查。';
        } elseif (!empty($opportunity)) {
            $action = '保留高价值库存，结合高需求日期做小步提价或促销收口。';
        } else {
            $action = '维持当前经营节奏，继续每日同步 OTA、日报和竞对价格用于滚动判断。';
        }

        return [
            'level' => $main['level'] ?? 'green',
            'judgement' => ($main['name'] ?? '经营趋势') . '：' . ($main['direction'] ?? '平稳'),
            'change' => "已读取{$rangeLabel}{$sampleDays}个有效样本；" . ($main['note'] ?? '趋势样本已形成。'),
            'action' => $action,
        ];
    }

    private function trendPendingCard(string $key, string $name, string $note): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'value' => '--',
            'direction' => $key === 'demand' ? '数据不足' : '待同步',
            'level' => 'gray',
            'note' => $note,
            'spark' => [30, 30, 30, 30, 30, 30, 30, 30, 30],
            'change_rate' => null,
        ];
    }

    private function compareSeries(array $values): array
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value) && (float)$value > 0));
        if (count($values) < 2) {
            return ['change_rate' => null, 'direction' => 'pending', 'level' => 'gray'];
        }

        $half = max(1, intdiv(count($values), 2));
        $previous = $this->avgNumeric(array_slice($values, 0, $half));
        $current = $this->avgNumeric(array_slice($values, -$half));
        if ($previous <= 0) {
            return ['change_rate' => null, 'direction' => 'stable', 'level' => 'green'];
        }

        $changeRate = ($current - $previous) / $previous * 100;
        if ($changeRate > 8) {
            return ['change_rate' => round($changeRate, 1), 'direction' => 'up', 'level' => 'blue'];
        }
        if ($changeRate < -8) {
            return ['change_rate' => round($changeRate, 1), 'direction' => 'down', 'level' => 'yellow'];
        }

        return ['change_rate' => round($changeRate, 1), 'direction' => 'stable', 'level' => 'green'];
    }

    private function trendDirectionText(array $trend): string
    {
        return match ($trend['direction'] ?? 'pending') {
            'up' => '上升',
            'down' => '下降',
            'stable' => '平稳',
            default => '数据不足',
        };
    }

    private function formatChangeRate(?float $rate): string
    {
        if ($rate === null) {
            return '暂无可比变化';
        }

        return ($rate >= 0 ? '+' : '') . $rate . '%';
    }

    private function sparkline(array $values): array
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value) && (float)$value > 0));
        if (empty($values)) {
            return [30, 30, 30, 30, 30, 30, 30, 30, 30];
        }

        $target = 9;
        if (count($values) > $target) {
            $step = count($values) / $target;
            $sampled = [];
            for ($i = 0; $i < $target; $i++) {
                $sampled[] = (float)$values[(int)floor($i * $step)];
            }
            $values = $sampled;
        }
        while (count($values) < $target) {
            array_unshift($values, $values[0]);
        }

        $min = min($values);
        $max = max($values);
        if ($max <= $min) {
            return array_fill(0, $target, 46);
        }

        return array_map(static fn ($value): int => (int)round(24 + (((float)$value - $min) / ($max - $min)) * 52), $values);
    }

    private function avgNumeric(array $values): float
    {
        $values = array_values(array_filter($values, static fn ($value): bool => is_numeric($value) && (float)$value > 0));
        return empty($values) ? 0.0 : array_sum($values) / count($values);
    }

    private function formatMoneyShort(float $value): string
    {
        if ($value >= 10000) {
            return '¥' . round($value / 10000, 1) . '万';
        }

        return '¥' . (int)round($value);
    }

    private function buildWeatherForecast(array $hotelIds): array
    {
        $location = $this->resolveWeatherLocation($hotelIds);
        if ($location === '') {
            return [];
        }

        $result = $this->external->amapWeather($location);
        if (($result['ok'] ?? false) !== true) {
            return [];
        }

        $forecast = $result['forecast'] ?? [];
        return is_array($forecast) ? $forecast : [];
    }

    private function resolveWeatherLocation(array $hotelIds): string
    {
        $ids = array_values(array_filter(array_map('intval', $hotelIds), fn (int $id): bool => $id > 0));
        try {
            $query = Db::name('hotels')->field('id,address,status');
            if (!empty($ids)) {
                $query->whereIn('id', $ids);
            }
            $hotel = $query->where('status', 1)->order('id', 'asc')->find();
            if (!$hotel && !empty($ids)) {
                $hotel = Db::name('hotels')->field('id,address,status')->whereIn('id', $ids)->order('id', 'asc')->find();
            }
            $location = $this->extractCityFromAddress((string)($hotel['address'] ?? ''));
            return $location !== '' ? $location : '本地';
        } catch (Throwable $e) {
            return '本地';
        }
    }

    private function extractCityFromAddress(string $address): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }
        if (preg_match('/(北京市|上海市|天津市|重庆市|[^省自治区市]+市|[^省自治区市]+地区|[^省自治区市]+盟|[^省自治区市]+州)/u', $address, $matches)) {
            return $matches[1];
        }

        return mb_substr($address, 0, 6);
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

    private function readDailyRowsBetween(array $hotelIds, string $startDate, string $endDate): array
    {
        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('daily_reports')
                ->field('hotel_id,report_date,report_data,occupancy_rate,room_count,revenue')
                ->whereBetween('report_date', [$startDate, $endDate]),
            'hotel_id',
            $hotelIds
        )->order('report_date', 'asc')->select()->toArray());
    }

    private function readOnlineRowsBetween(array $hotelIds, string $startDate, string $endDate): array
    {
        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('online_daily_data')
                ->field('system_hotel_id,data_date,amount,quantity,book_order_num,raw_data,source,dimension,data_type,data_value')
                ->whereBetween('data_date', [$startDate, $endDate]),
            'system_hotel_id',
            $hotelIds
        )->order('data_date', 'asc')->select()->toArray());
    }

    private function readCompetitorPricesBetween(array $hotelIds, string $startDate, string $endDate): array
    {
        $start = $startDate . ' 00:00:00';
        $end = $endDate . ' 23:59:59';

        return $this->safeRows(fn () => $this->withHotelIds(
            Db::name('competitor_price_log')
                ->field('store_id,hotel_id,price,fetch_time,create_time')
                ->where('price', '>', 0)
                ->where(function ($query) use ($start, $end) {
                    $query->whereBetween('fetch_time', [$start, $end])->whereOr(function ($subQuery) use ($start, $end) {
                        $subQuery->whereNull('fetch_time')->whereBetween('create_time', [$start, $end]);
                    });
                }),
            'store_id',
            $hotelIds
        )->order('id', 'desc')->limit(500)->select()->toArray());
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

    private function firstPositiveNumber(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->toPositiveFloat($data[$key] ?? null);
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

    private function toPositiveFloat($value): ?float
    {
        $value = $this->toFloat($value);
        return $value !== null && $value > 0 ? $value : null;
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
