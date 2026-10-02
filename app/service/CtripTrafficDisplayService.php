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
        $coverage = [];
        $strictRateGaps = [];
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
            $coverage[$date][$normalized['compare_type']] = $normalized['observed'];
            if (!empty($normalized['strict_snapshot'])) {
                foreach (['exposure_rate', 'order_rate', 'deal_rate'] as $metric) {
                    if (!$normalized['observed'][$metric]) {
                        $strictRateGaps[] = $date . ':' . $normalized['compare_type'] . ':' . $metric;
                    }
                }
            }
        }

        if (empty($daily)) {
            return self::emptyAppTrafficDerivedAnalysis();
        }

        ksort($daily);
        $dataGaps = $strictRateGaps;
        foreach ($daily as $date => $item) {
            foreach (['self', 'competitor'] as $role) {
                if (!isset($coverage[$date][$role])) {
                    $dataGaps[] = "{$date}:{$role}";
                    continue;
                }
                foreach (['exposure', 'detail_visitors', 'order_visitors', 'submit_users'] as $metric) {
                    if (($coverage[$date][$role][$metric] ?? false) !== true) {
                        $dataGaps[] = "{$date}:{$role}:{$metric}";
                    }
                }
            }
        }
        if ($dataGaps !== []) {
            return [
                'status' => 'partial',
                'data_gaps' => $dataGaps,
                'summary' => null,
                'rows' => [],
                'diagnosis' => $strictRateGaps !== []
                    ? '本店或竞争圈的同日流量转化率不可计算，暂不生成衍生分析和运营建议。'
                    : '本店或竞争圈的同日流量指标缺失，暂不生成衍生分析和运营建议。',
                'main_problem_stage' => '证据不足',
                'recommendations' => [],
            ];
        }
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
            'status' => 'ready',
            'data_gaps' => [],
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
            $observed = $normalized['observed'];
            $hotelId = self::readTrafficNumber($row, ['hotelId', 'hotel_id', 'HotelId', 'hotelID', 'nodeId', 'node_id'], null);
            $compareType = $normalized['compare_type'] === 'self' ? 'self' : 'competitor_avg';
            $displayRow = [
                'date' => $normalized['date'],
                'hotelId' => $hotelId !== null ? (int)$hotelId : ($compareType === 'competitor_avg' ? -1 : null),
                'compareType' => $compareType,
                'listExposure' => $observed['exposure'] ? (float)$metrics['exposure'] : null,
                'detailExposure' => $observed['detail_visitors'] ? (float)$metrics['detail_visitors'] : null,
                'flowRate' => $observed['exposure_rate'] ? (float)$metrics['exposure_rate'] : null,
                'orderFillingNum' => $observed['order_visitors'] ? (float)$metrics['order_visitors'] : null,
                'orderSubmitNum' => $observed['submit_users'] ? (float)$metrics['submit_users'] : null,
                'orderFillRate' => $observed['order_rate'] ? (float)$metrics['order_rate'] : null,
                'submitRate' => $observed['deal_rate'] ? (float)$metrics['deal_rate'] : null,
            ];
            if (!empty($normalized['strict_snapshot'])) $displayRow['request_source'] = 'flow_overview';
            $displayRows[] = $displayRow;
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
        $coverage = [];
        $roleRows = [];
        $roleRowCounts = [];
        $strictRoles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targetKey = ($row['compareType'] ?? '') === 'self' ? 'self' : 'avg';
            if (($row['request_source'] ?? '') === 'flow_overview') $strictRoles[$targetKey] = true;
            $roleRows[$targetKey] = $row;
            $roleRowCounts[$targetKey] = ($roleRowCounts[$targetKey] ?? 0) + 1;
            foreach (['listExposure', 'detailExposure', 'orderFillingNum', 'orderSubmitNum'] as $key) {
                if (!isset($row[$key]) || !is_numeric($row[$key])) {
                    $coverage[$targetKey][$key] = false;
                    continue;
                }
                $coverage[$targetKey][$key] ??= true;
                $summary[$targetKey][$key] += (float)$row[$key];
            }
        }

        foreach (['self', 'avg'] as $targetKey) {
            foreach (['listExposure', 'detailExposure', 'orderFillingNum', 'orderSubmitNum'] as $key) {
                if (($coverage[$targetKey][$key] ?? null) === false) {
                    $summary[$targetKey][$key] = null;
                }
            }
            $item = $summary[$targetKey];
            $singleRow = ($roleRowCounts[$targetKey] ?? 0) === 1 ? $roleRows[$targetKey] : null;
            $summary[$targetKey]['flowRate'] = $singleRow !== null && is_numeric($singleRow['flowRate'] ?? null)
                ? (float)$singleRow['flowRate']
                : ($item['detailExposure'] !== null && $item['listExposure'] !== null
                    ? self::trafficRate($item['detailExposure'], $item['listExposure']) : null);
            $summary[$targetKey]['orderFillRate'] = $singleRow !== null && is_numeric($singleRow['orderFillRate'] ?? null)
                ? (float)$singleRow['orderFillRate']
                : ($item['orderFillingNum'] !== null && $item['detailExposure'] !== null
                    ? self::trafficRate($item['orderFillingNum'], $item['detailExposure']) : null);
            $summary[$targetKey]['submitRate'] = $singleRow !== null && is_numeric($singleRow['submitRate'] ?? null)
                ? (float)$singleRow['submitRate']
                : ($item['orderSubmitNum'] !== null && $item['orderFillingNum'] !== null
                    ? self::trafficRate($item['orderSubmitNum'], $item['orderFillingNum']) : null);
            if (!empty($strictRoles[$targetKey])) {
                foreach (['flowRate' => ['detailExposure', 'listExposure'],
                    'orderFillRate' => ['orderFillingNum', 'detailExposure'],
                    'submitRate' => ['orderSubmitNum', 'orderFillingNum']] as $rate => [$numerator, $denominator]) {
                    $summary[$targetKey][$rate] = $item[$numerator] !== null && $item[$denominator] !== null && $item[$denominator] > 0
                        ? self::trafficRate($item[$numerator], $item[$denominator]) : null;
                }
            }
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
            'listExposure' => null,
            'detailExposure' => null,
            'flowRate' => null,
            'orderFillingNum' => null,
            'orderFillRate' => null,
            'orderSubmitNum' => null,
            'submitRate' => null,
        ];
    }

    public static function emptyAppTrafficDerivedAnalysis(): array
    {
        return [
            'status' => 'missing',
            'data_gaps' => ['traffic_rows'],
            'summary' => null,
            'rows' => [],
            'diagnosis' => '未返回可分析的携程 APP 流量记录。',
            'main_problem_stage' => '证据不足',
            'recommendations' => [],
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

        $sourceExposure = self::readTrafficNumber($row, ['listExposure', 'list_exposure', "{$prefix}_exposure", 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view', 'data_value'], null);
        $sourceDetailVisitors = self::readTrafficNumber($row, ['detailExposure', 'detail_exposure', "{$prefix}_detail_visitors", 'detail_visitors', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views'], null);
        $sourceOrderVisitors = self::readTrafficNumber($row, ['orderFillingNum', 'order_filling_num', "{$prefix}_order_visitors", 'order_visitors', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks'], null);
        $sourceSubmitUsers = self::readTrafficNumber($row, ['orderSubmitNum', 'order_submit_num', "{$prefix}_submit_users", 'submit_users', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'], null);
        $exposure = $sourceExposure ?? 0.0;
        $detailVisitors = $sourceDetailVisitors ?? 0.0;
        $orderVisitors = $sourceOrderVisitors ?? 0.0;
        $submitUsers = $sourceSubmitUsers ?? 0.0;

        $sourceExposureRate = self::readTrafficNumber($row, ['flowRate', 'flow_rate', "{$prefix}_exposure_rate", 'exposure_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'], null);
        $sourceOrderRate = self::readTrafficNumber($row, ['orderFillRate', 'order_rate', "{$prefix}_order_rate", 'orderConversionRate'], null);
        $sourceDealRate = self::readTrafficNumber($row, ['submitRate', 'deal_rate', "{$prefix}_deal_rate", 'submitConversionRate', 'dealRate'], null);
        $exposureRate = self::normalizeTrafficPercent($sourceExposureRate);
        $orderRate = self::normalizeTrafficPercent($sourceOrderRate);
        $dealRate = self::normalizeTrafficPercent($sourceDealRate);

        $normalized = [
            'date' => date('Y-m-d', strtotime((string)$date)),
            'compare_type' => $compareType,
            'observed' => [
                'exposure' => $sourceExposure !== null,
                'detail_visitors' => $sourceDetailVisitors !== null,
                'order_visitors' => $sourceOrderVisitors !== null,
                'submit_users' => $sourceSubmitUsers !== null,
                'exposure_rate' => $sourceExposureRate !== null || ($sourceExposure !== null && $sourceDetailVisitors !== null),
                'order_rate' => $sourceOrderRate !== null || ($sourceDetailVisitors !== null && $sourceOrderVisitors !== null),
                'deal_rate' => $sourceDealRate !== null || ($sourceOrderVisitors !== null && $sourceSubmitUsers !== null),
            ],
            'metrics' => [
                'exposure' => $exposure,
                'detail_visitors' => $detailVisitors,
                'order_visitors' => $orderVisitors,
                'submit_users' => $submitUsers,
                'exposure_rate' => $sourceExposureRate !== null ? $exposureRate : self::trafficRate($detailVisitors, $exposure),
                'order_rate' => $sourceOrderRate !== null ? $orderRate : self::trafficRate($orderVisitors, $detailVisitors),
                'deal_rate' => $sourceDealRate !== null ? $dealRate : self::trafficRate($submitUsers, $orderVisitors),
            ],
        ];
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
        if (($row['request_source'] ?? (is_array($raw) ? ($raw['request_source'] ?? '') : '')) === 'flow_overview') {
            $normalized['strict_snapshot'] = true;
            foreach (['exposure_rate' => [$sourceDetailVisitors, $sourceExposure],
                'order_rate' => [$sourceOrderVisitors, $sourceDetailVisitors],
                'deal_rate' => [$sourceSubmitUsers, $sourceOrderVisitors]] as $rate => [$numerator, $denominator]) {
                $available = $numerator !== null && $denominator !== null && $denominator > 0;
                $normalized['observed'][$rate] = $available;
                $normalized['metrics'][$rate] = $available ? self::trafficRate($numerator, $denominator) : null;
            }
        }
        return $normalized;
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
