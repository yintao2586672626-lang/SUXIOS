<?php
declare(strict_types=1);

namespace app\service;

final class CtripTrafficDisplayService
{
    public static function buildAppTrafficDerivedAnalysis(array $rows): array
    {
        if (empty($rows)) {
            return self::emptyAppTrafficDerivedAnalysis();
        }

        $daily = [];
        foreach ($rows as $row) {
            $normalized = self::normalizeAppTrafficRow(is_array($row) ? $row : []);
            if ($normalized === null) {
                continue;
            }
            $date = $normalized['date'];
            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'self' => self::emptyAppTrafficMetrics(),
                    'competitor' => self::emptyAppTrafficMetrics(),
                ];
            }
            $daily[$date][$normalized['compare_type']] = $normalized['metrics'];
        }

        if (empty($daily)) {
            return self::emptyAppTrafficDerivedAnalysis();
        }

        ksort($daily);
        $summaryBase = [
            'date' => '',
            'self' => self::emptyAppTrafficMetrics(),
            'competitor' => self::emptyAppTrafficMetrics(),
        ];
        foreach ($daily as $item) {
            foreach (['self', 'competitor'] as $type) {
                foreach (['exposure', 'detail_visitors', 'order_visitors', 'submit_users'] as $key) {
                    $summaryBase[$type][$key] += $item[$type][$key];
                }
            }
        }

        $summary = self::calculateAppTrafficDerivedMetrics($summaryBase);
        $derivedRows = [];
        foreach ($daily as $item) {
            $derivedRows[] = self::calculateAppTrafficDerivedMetrics($item);
        }

        return [
            'summary' => $summary,
            'rows' => $derivedRows,
            'diagnosis' => $summary['diagnosis'],
            'main_problem_stage' => $summary['main_problem_stage'],
            'recommendations' => $summary['recommendations'],
        ];
    }

    public static function buildCtripTrafficDisplayRows(array $rows): array
    {
        $displayRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = self::normalizeAppTrafficRow($row);
            if ($normalized === null) {
                continue;
            }

            $metrics = $normalized['metrics'];
            $hotelId = self::readTrafficNumber($row, ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'nodeId', 'node_id'], null);
            $compareType = $normalized['compare_type'] === 'self' ? 'self' : 'competitor_avg';
            $displayRows[] = [
                'date' => $normalized['date'],
                'hotelId' => $hotelId !== null ? (int)$hotelId : ($compareType === 'competitor_avg' ? -1 : null),
                'compareType' => $compareType,
                'listExposure' => (float)$metrics['exposure'],
                'detailExposure' => (float)$metrics['detail_visitors'],
                'flowRate' => (float)$metrics['exposure_rate'],
                'orderFillingNum' => (float)$metrics['order_visitors'],
                'orderSubmitNum' => (float)$metrics['submit_users'],
                'orderFillRate' => (float)$metrics['order_rate'],
                'submitRate' => (float)$metrics['deal_rate'],
            ];
        }

        usort($displayRows, function (array $left, array $right): int {
            $dateCompare = strcmp((string)$left['date'], (string)$right['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            if ($left['compareType'] === $right['compareType']) {
                return 0;
            }
            return $left['compareType'] === 'self' ? -1 : 1;
        });

        return $displayRows;
    }

    public static function buildCtripTrafficDisplaySummary(array $rows): array
    {
        $summary = self::emptyCtripTrafficDisplaySummary();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targetKey = ($row['compareType'] ?? '') === 'self' ? 'self' : 'avg';
            $summary[$targetKey]['listExposure'] += (float)($row['listExposure'] ?? 0);
            $summary[$targetKey]['detailExposure'] += (float)($row['detailExposure'] ?? 0);
            $summary[$targetKey]['orderFillingNum'] += (float)($row['orderFillingNum'] ?? 0);
            $summary[$targetKey]['orderSubmitNum'] += (float)($row['orderSubmitNum'] ?? 0);
        }

        foreach (['self', 'avg'] as $targetKey) {
            $item = $summary[$targetKey];
            $summary[$targetKey]['flowRate'] = self::trafficRate($item['detailExposure'], $item['listExposure']);
            $summary[$targetKey]['orderFillRate'] = self::trafficRate($item['orderFillingNum'], $item['detailExposure']);
            $summary[$targetKey]['submitRate'] = self::trafficRate($item['orderSubmitNum'], $item['orderFillingNum']);
        }

        return $summary;
    }

    public static function emptyCtripTrafficDisplaySummary(): array
    {
        return [
            'self' => self::emptyCtripTrafficDisplayMetrics(),
            'avg' => self::emptyCtripTrafficDisplayMetrics(),
        ];
    }

    public static function emptyCtripTrafficDisplayMetrics(): array
    {
        return [
            'listExposure' => 0.0,
            'detailExposure' => 0.0,
            'flowRate' => 0.0,
            'orderFillingNum' => 0.0,
            'orderFillRate' => 0.0,
            'orderSubmitNum' => 0.0,
            'submitRate' => 0.0,
        ];
    }

    public static function emptyAppTrafficDerivedAnalysis(): array
    {
        $base = self::calculateAppTrafficDerivedMetrics([
            'date' => '',
            'self' => self::emptyAppTrafficMetrics(),
            'competitor' => self::emptyAppTrafficMetrics(),
        ]);
        return [
            'summary' => $base,
            'rows' => [],
            'diagnosis' => $base['diagnosis'],
            'main_problem_stage' => $base['main_problem_stage'],
            'recommendations' => $base['recommendations'],
        ];
    }

    public static function emptyAppTrafficMetrics(): array
    {
        return [
            'exposure' => 0.0,
            'detail_visitors' => 0.0,
            'order_visitors' => 0.0,
            'submit_users' => 0.0,
            'exposure_rate' => 0.0,
            'order_rate' => 0.0,
            'deal_rate' => 0.0,
        ];
    }

    public static function normalizeAppTrafficRow(array $row): ?array
    {
        $date = $row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? $row['data_date'] ?? $row['reportDate'] ?? $row['day'] ?? '';
        if ($date === '' || strtotime((string)$date) === false) {
            return null;
        }

        $compareType = $row['compareType'] ?? $row['compare_type'] ?? null;
        if ($compareType === null) {
            $hotelId = $row['hotelId'] ?? $row['hotel_id'] ?? $row['HotelId'] ?? $row['hotelID'] ?? $row['nodeId'] ?? $row['node_id'] ?? null;
            $compareText = strtolower((string)($row['type'] ?? $row['rankType'] ?? $row['name'] ?? $row['hotelName'] ?? ''));
            $compareType = (str_contains($compareText, 'competitor') || str_contains($compareText, 'peer') || str_contains($compareText, 'avg') || str_contains($compareText, 'average'))
                ? 'competitor'
                : (is_numeric($hotelId) && (int)$hotelId > 0 ? 'self' : 'competitor');
        }
        $compareType = in_array($compareType, ['self', 'my'], true) ? 'self' : 'competitor';
        $prefix = $compareType === 'self' ? 'self' : 'competitor';

        $exposure = self::readTrafficNumber($row, ['listExposure', 'list_exposure', "{$prefix}_exposure", 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view', 'data_value']);
        $detailVisitors = self::readTrafficNumber($row, ['detailExposure', 'detail_exposure', "{$prefix}_detail_visitors", 'detail_visitors', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views']);
        $orderVisitors = self::readTrafficNumber($row, ['orderFillingNum', 'order_filling_num', "{$prefix}_order_visitors", 'order_visitors', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks']);
        $submitUsers = self::readTrafficNumber($row, ['orderSubmitNum', 'order_submit_num', "{$prefix}_submit_users", 'submit_users', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders']);

        $exposureRate = self::normalizeTrafficPercent(self::readTrafficNumber($row, ['flowRate', 'flow_rate', "{$prefix}_exposure_rate", 'exposure_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'], null));
        $orderRate = self::normalizeTrafficPercent(self::readTrafficNumber($row, ['orderFillRate', 'order_rate', "{$prefix}_order_rate", 'orderConversionRate'], null));
        $dealRate = self::normalizeTrafficPercent(self::readTrafficNumber($row, ['submitRate', 'deal_rate', "{$prefix}_deal_rate", 'submitConversionRate', 'dealRate'], null));

        return [
            'date' => date('Y-m-d', strtotime((string)$date)),
            'compare_type' => $compareType,
            'metrics' => [
                'exposure' => $exposure,
                'detail_visitors' => $detailVisitors,
                'order_visitors' => $orderVisitors,
                'submit_users' => $submitUsers,
                'exposure_rate' => $exposureRate > 0 ? $exposureRate : self::trafficRate($detailVisitors, $exposure),
                'order_rate' => $orderRate > 0 ? $orderRate : self::trafficRate($orderVisitors, $detailVisitors),
                'deal_rate' => $dealRate > 0 ? $dealRate : self::trafficRate($submitUsers, $orderVisitors),
            ],
        ];
    }

    public static function calculateAppTrafficDerivedMetrics(array $base): array
    {
        $self = $base['self'];
        $competitor = $base['competitor'];

        $self['exposure_rate'] = self::trafficRate($self['detail_visitors'], $self['exposure']);
        $self['order_rate'] = self::trafficRate($self['order_visitors'], $self['detail_visitors']);
        $self['deal_rate'] = self::trafficRate($self['submit_users'], $self['order_visitors']);
        $competitor['exposure_rate'] = self::trafficRate($competitor['detail_visitors'], $competitor['exposure']);
        $competitor['order_rate'] = self::trafficRate($competitor['order_visitors'], $competitor['detail_visitors']);
        $competitor['deal_rate'] = self::trafficRate($competitor['submit_users'], $competitor['order_visitors']);

        $detailLoss = $self['exposure'] - $self['detail_visitors'];
        $orderLoss = $self['detail_visitors'] - $self['order_visitors'];
        $submitLoss = $self['order_visitors'] - $self['submit_users'];
        $lossMap = [
            '曝光到详情' => $detailLoss,
            '详情到订单页' => $orderLoss,
            '订单页到提交' => $submitLoss,
        ];
        arsort($lossMap);
        $maxLossStage = (float)reset($lossMap) > 0 ? (string)key($lossMap) : '无明显流失';

        $mainProblemStage = self::diagnoseAppTrafficStage($self, $competitor);
        $recommendations = self::buildAppTrafficRecommendations($mainProblemStage);

        $derived = [
            'date' => $base['date'],
            'self' => $self,
            'competitor' => $competitor,
            'exposure_gap' => $competitor['exposure'] - $self['exposure'],
            'detail_gap' => $competitor['detail_visitors'] - $self['detail_visitors'],
            'order_gap' => $competitor['order_visitors'] - $self['order_visitors'],
            'submit_gap' => $competitor['submit_users'] - $self['submit_users'],
            'exposure_achieve_rate' => self::trafficRate($self['exposure'], $competitor['exposure']),
            'detail_achieve_rate' => self::trafficRate($self['detail_visitors'], $competitor['detail_visitors']),
            'order_achieve_rate' => self::trafficRate($self['order_visitors'], $competitor['order_visitors']),
            'submit_achieve_rate' => self::trafficRate($self['submit_users'], $competitor['submit_users']),
            'detail_loss' => $detailLoss,
            'order_loss' => $orderLoss,
            'submit_loss' => $submitLoss,
            'exposure_rate_gap' => $self['exposure_rate'] - $competitor['exposure_rate'],
            'order_rate_gap' => $self['order_rate'] - $competitor['order_rate'],
            'deal_rate_gap' => $self['deal_rate'] - $competitor['deal_rate'],
            'potential_detail_visitors_by_competitor_rate' => $self['exposure'] * ($competitor['exposure_rate'] / 100),
            'potential_submit_users_by_competitor_exposure' => $competitor['exposure'] * ($self['exposure_rate'] / 100) * ($self['order_rate'] / 100) * ($self['deal_rate'] / 100),
            'max_loss_stage' => $maxLossStage,
            'main_problem_stage' => $mainProblemStage,
            'recommendations' => $recommendations,
        ];
        $derived['potential_submit_gap'] = $derived['potential_submit_users_by_competitor_exposure'] - $self['submit_users'];
        $derived['diagnosis'] = self::buildAppTrafficDiagnosis($derived);
        return $derived;
    }

    public static function diagnoseAppTrafficStage(array $self, array $competitor): string
    {
        $stage = '整体接近竞争圈';
        if ($self['exposure'] < $competitor['exposure'] * 0.5) {
            $stage = '曝光不足';
        }
        if ($self['exposure_rate'] < $competitor['exposure_rate'] - 3) {
            $stage = '列表点击弱';
        }
        if ($self['order_rate'] < $competitor['order_rate'] - 2) {
            $stage = '详情承接弱';
        }
        if ($self['deal_rate'] < $competitor['deal_rate'] - 5) {
            $stage = '成交转化弱';
        }
        if ($self['exposure_rate'] < $competitor['exposure_rate'] && $self['order_rate'] > $competitor['order_rate'] && $self['deal_rate'] > $competitor['deal_rate']) {
            $stage = '前端流量弱，后端转化强';
        }
        return $stage;
    }

    public static function buildAppTrafficRecommendations(string $stage): array
    {
        return match ($stage) {
            '曝光不足' => ['检查排名', '价格竞争力', '指定入住日携程渠道可售状态（需另有证据）', '活动', '商圈标签', '广告投放'],
            '列表点击弱' => ['优化首图', '标题', '点评分', '价格展示', '促销标签', '地理位置卖点'],
            '详情承接弱' => ['优化详情页卖点', '房型结构', '取消政策', '早餐', '接送', '设施图片'],
            '成交转化弱' => ['核验指定入住日携程渠道可售状态（需另有证据）', '支付门槛', '担保规则', '价格跳变', '不可订房型'],
            '前端流量弱，后端转化强' => ['优先扩大曝光', '提升列表点击', '暂不优先改订单页'],
            default => ['持续监控曝光规模', '维护详情页转化', '观察竞争圈变化'],
        };
    }

    public static function buildAppTrafficDiagnosis(array $derived): string
    {
        if (($derived['self']['exposure'] ?? 0) <= 0 && ($derived['competitor']['exposure'] ?? 0) <= 0) {
            return '当前日期范围暂无可分析的 APP 流量转化数据。';
        }

        $stage = $derived['main_problem_stage'];
        if ($stage === '前端流量弱，后端转化强') {
            return '当前酒店曝光转化率低于竞争圈，但下单转化率和成交转化率高于竞争圈，说明后端成交承接能力较好，核心短板在前端曝光规模和列表点击吸引力。';
        }
        return "当前酒店 APP 流量转化主要问题为{$stage}，最大流失阶段在{$derived['max_loss_stage']}，建议优先处理对应运营动作。";
    }

    public static function readTrafficNumber(array $row, array $keys, ?float $default = 0.0): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $number = self::coerceTrafficNumber($row[$key]);
            if ($number !== null) {
                return $number;
            }
        }
        return $default;
    }

    public static function coerceTrafficNumber($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', '%', ' '], '', trim($value));
        if ($normalized === '') {
            return null;
        }
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    public static function normalizeTrafficPercent(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        return abs($value) > 0 && abs($value) <= 1 ? $value * 100 : $value;
    }

    public static function trafficRate(float $num, float $denom): float
    {
        return $denom > 0 ? round($num / $denom * 100, 2) : 0.0;
    }
}
