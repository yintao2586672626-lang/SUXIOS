<?php

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
use think\facade\Db;

trait CtripAdsConcern
{
    private function normalizeCtripAdsApiType(string $value = ''): string
    {
        return 'effect_report';
    }

    private function defaultCtripAdsEffectReportUrl(): string
    {
        return 'https://ebooking.ctrip.com/toolcenter/api/cpc/queryCampaignReportList?hostType=HE';
    }

    private function buildCtripAdsDirectPayload(array $payload, string $startDate, string $endDate, string $apiType): array
    {
        $apiType = $this->normalizeCtripAdsApiType($apiType);
        foreach ([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'beginDate' => $startDate,
            'statStartDate' => $startDate,
            'statEndDate' => $endDate,
        ] as $key => $value) {
            if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $payload[$key] = $value;
            }
        }
        $payload['apiType'] = $apiType;
        return $payload;
    }

    private function buildCtripAdsDateRange(string $dateRange, string $startDate, string $endDate, ?int $now = null): array
    {
        $today = $this->ctripAdsClock($now)->format('Y-m-d');
        $reportEndDate = $this->ctripAdsReportEndDate($now);
        switch ($dateRange) {
            case 'today_realtime':
            case 'today':
            case '0':
                return [$today, $today];
            case 'last_7_days':
            case '7':
                return [date('Y-m-d', strtotime($reportEndDate . ' -6 days')), $reportEndDate];
            case 'last_30_days':
            case '30':
                return [date('Y-m-d', strtotime($reportEndDate . ' -29 days')), $reportEndDate];
            case 'custom':
                if ($startDate === '' || $endDate === '') {
                    throw new \InvalidArgumentException('请选择自定义开始日期和结束日期');
                }
                break;
            case 'yesterday':
            case '1':
            default:
                $startDate = $reportEndDate;
                $endDate = $reportEndDate;
                break;
        }

        if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$startDate, $endDate];
    }

    private function ctripAdsReportEndDate(?int $now = null): string
    {
        $clock = $this->ctripAdsClock($now);
        $offsetDays = (int)$clock->format('G') < 7 ? 2 : 1;
        return $clock->modify('-' . $offsetDays . ' days')->format('Y-m-d');
    }

    private function ctripAdsClock(?int $now = null): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        if ($now === null) {
            return new \DateTimeImmutable('now', $timezone);
        }
        return (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
    }

    private function isCtripAdsApiUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));
        return str_contains($normalized, 'pyramidad')
            || str_contains($normalized, 'promotion')
            || str_contains($normalized, '/toolcenter/api/cpc/')
            || str_contains($normalized, 'querycampaignreportlist');
    }

    private function extractCtripCapturedAds(array $payload): array
    {
        $rows = [];
        foreach (['ads', 'advertising', 'adData'] as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedAdList($payload[$key]));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $url = strtolower((string)($response['url'] ?? ''));
                $section = strtolower((string)($response['section'] ?? $response['type'] ?? ''));
                if ($section !== 'ads' && !$this->isCtripAdsApiUrl($url)) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedAdList($data));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = json_encode([
                $this->firstMeituanValue($row, ['campaignId', 'campaign_id', 'planId', 'plan_id', 'adId', 'id'], ''),
                $this->firstMeituanValue($row, ['campaign_name', 'campaignName', 'promotionName', 'planName', 'adName', 'name'], ''),
                $this->firstMeituanValue($row, ['stat_date', 'statDate', 'dataDate', 'date'], ''),
                $this->firstMeituanValue($row, ['exposure_count', 'exposureCount', 'exposure', 'impression'], ''),
                $this->firstMeituanValue($row, ['click_count', 'clickCount', 'clicks', 'click'], ''),
                $this->firstMeituanValue($row, ['cost_amount', 'costAmount', 'cost', 'consume', 'spend'], ''),
                $row['_dom_text'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function normalizeCtripCapturedAdList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            return array_values(array_filter($value, static fn($item): bool => is_array($item)));
        }

        $paths = [
            ['data', 'list'],
            ['data', 'rows'],
            ['data', 'items'],
            ['data', 'details'],
            ['data', 'campaignList'],
            ['data', 'promotionList'],
            ['data', 'adList'],
            ['data', 'records'],
            ['result', 'list'],
            ['result', 'rows'],
            ['result', 'items'],
            ['list'],
            ['rows'],
            ['items'],
            ['campaignList'],
            ['promotionList'],
            ['adList'],
            ['records'],
            ['data'],
        ];
        foreach ($paths as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedAdList($nested);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        return $this->looksLikeCtripCapturedAdRow($value) ? [$value] : [];
    }

    private function looksLikeCtripCapturedAdRow(array $value): bool
    {
        foreach (['exposure', 'exposureCount', 'impression', 'impressions', 'click', 'clickCount', 'clicks', 'cost', 'consume', 'spend', 'todayCost', 'cashCost', 'bonusCost', 'orderNum', 'bookingNum', 'bookings', 'campaignName', 'promotionName', 'planName', '曝光', '曝光量', '展现量', '点击', '点击量', '消耗', '费用', '花费', '预订量', '成交数', '推广名称', '计划名称'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function buildCtripCapturedAdRows(array $ads, array $payload, ?int $systemHotelId = null): array
    {
        $context = [
            'system_hotel_id' => $systemHotelId,
            'hotel_id' => (string)$this->firstMeituanValue($payload, ['hotel_id', 'hotelId', 'masterHotelId', 'master_hotel_id', 'hotelID', 'ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id', 'otaHotelId', 'node_id', 'nodeId'], ''),
            'hotel_name' => (string)$this->firstMeituanValue($payload, ['hotel_name', 'hotelName'], ''),
            'captured_at' => (string)$this->firstMeituanValue($payload, ['captured_at', 'capturedAt'], date('Y-m-d H:i:s')),
            'request_start_date' => (string)$this->firstMeituanValue($payload, ['request_start_date', 'requestStartDate'], ''),
            'request_end_date' => (string)$this->firstMeituanValue($payload, ['request_end_date', 'requestEndDate'], ''),
        ];
        $rows = [];
        foreach ($ads as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->normalizeCtripCapturedAdRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function normalizeCtripCapturedAdRow(array $item, array $context): ?array
    {
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'exposure', 'impression', 'impressions', 'showNum', 'showCount', 'displayCount', 'pv', '曝光', '曝光量', '展现量', '展示量'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click', '点击', '点击量'], 0);
        $orders = (int)$this->meituanNumber($item, ['booking_count', 'bookingCount', 'bookingNum', 'bookings', 'orderNum', 'order_count', 'orderCount', 'dealNum', 'transactionNum', 'conversionNum', '预订量', '预订数', '成交数', '成交量', '成交订单数'], 0);
        $nights = (int)$this->meituanNumber($item, ['nights', 'nightNum', 'roomNights', 'room_nights', 'quantity', '间夜', '成交间夜'], 0);
        $cost = $this->meituanNumber($item, ['cost_amount', 'costAmount', 'cost', 'todayCost', 'cashCost', 'consume', 'consumption', 'spend', 'fee', 'expense', 'amount', 'totalCost', '消耗', '费用', '花费', '广告费', '消耗金额', '消费金额'], 0.0);
        if ($exposure <= 0 && $clicks <= 0 && $orders <= 0 && $cost <= 0 && empty($item['_dom_text'])) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['effectTime', 'effect_time', 'stat_date', 'statDate', 'data_date', 'dataDate', 'date', 'reportDate', 'day', '日期', '统计日期'], ''))
            ?: ($this->normalizeOnlineDataDate($context['request_end_date'] ?? '') ?: date('Y-m-d'));
        $identity = (string)$this->firstMeituanValue($item, ['campaignId', 'campaign_id', 'planId', 'plan_id', 'adId', 'id', 'campaign_name', 'campaignName', 'promotionName', 'planName', 'adName', 'name', '推广名称', '计划名称', '广告名称', '广告计划'], '');
        if ($identity === '') {
            $identity = substr(md5(json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);
        }

        $raw = $item;
        $raw['_capture_context'] = array_filter([
            'hotel_id' => $context['hotel_id'] ?? '',
            'captured_at' => $context['captured_at'] ?? '',
            'request_start_date' => $context['request_start_date'] ?? '',
            'request_end_date' => $context['request_end_date'] ?? '',
        ], static fn($value): bool => $value !== null && $value !== '');

        return [
            'hotel_id' => (string)$this->firstMeituanValue($item, ['hotel_id', 'hotelId', 'masterHotelId', 'master_hotel_id', 'hotelID', 'ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id', 'otaHotelId', 'node_id', 'nodeId'], $context['hotel_id'] ?? ''),
            'hotel_name' => (string)$this->firstMeituanValue($item, ['hotel_name', 'hotelName'], $context['hotel_name'] ?? ''),
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'data_date' => $dataDate,
            'amount' => round($cost, 2),
            'quantity' => $nights,
            'book_order_num' => $orders,
            'comment_score' => 0,
            'qunar_comment_score' => 0,
            'data_value' => $exposure,
            'source' => 'ctrip',
            'data_type' => 'advertising',
            'dimension' => 'ads:' . $identity,
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'list_exposure' => $exposure,
            'detail_exposure' => $clicks,
            'flow_rate' => round(CtripTrafficDisplayService::trafficRate((float)$clicks, (float)$exposure), 2),
            'order_filling_num' => $clicks,
            'order_submit_num' => $orders,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    private function summarizeCtripAdRows(array $rows): array
    {
        $summary = [
            'exposure' => 0,
            'clicks' => 0,
            'orders' => 0,
            'cost' => 0.0,
            'click_rate' => 0.0,
            'cost_per_click' => 0.0,
            'cost_per_order' => 0.0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $summary['exposure'] += (int)($row['list_exposure'] ?? 0);
            $summary['clicks'] += (int)($row['detail_exposure'] ?? 0);
            $summary['orders'] += (int)($row['book_order_num'] ?? $row['order_submit_num'] ?? 0);
            $summary['cost'] += (float)($row['amount'] ?? 0);
        }
        $summary['cost'] = round($summary['cost'], 2);
        $summary['click_rate'] = round(CtripTrafficDisplayService::trafficRate((float)$summary['clicks'], (float)$summary['exposure']), 2);
        $summary['cost_per_click'] = $summary['clicks'] > 0 ? round($summary['cost'] / $summary['clicks'], 2) : 0.0;
        $summary['cost_per_order'] = $summary['orders'] > 0 ? round($summary['cost'] / $summary['orders'], 2) : 0.0;
        return $summary;
    }

    private function saveCtripCapturedAdRows(array $rows): int
    {
        $columns = $this->getOnlineDailyDataColumns();
        $savedCount = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['data_date']) || empty($row['data_type'])) {
                continue;
            }
            if (isset($columns['update_time'])) {
                $row['update_time'] = $now;
            }
            $query = Db::name('online_daily_data')
                ->where('source', (string)($row['source'] ?? 'ctrip'))
                ->where('data_type', 'advertising')
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));
            if (!empty($row['hotel_id'])) {
                $query->where('hotel_id', (string)$row['hotel_id']);
            } else {
                $query->where('hotel_name', (string)($row['hotel_name'] ?? ''));
            }
            if (array_key_exists('system_hotel_id', $row) && $row['system_hotel_id'] !== null) {
                $query->where('system_hotel_id', (int)$row['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }
            $exists = $query->find();
            if (!$exists && isset($columns['create_time'])) {
                $row['create_time'] = $now;
            }
            $data = array_intersect_key($this->applyOnlineDailyDataValidationFields($row, $columns), $columns);
            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }
        return $savedCount;
    }

    /**
     * Read back the exact normalized ad identities written for this request.
     * A write counter alone is not evidence that the rows are queryable.
     */
    private function countCtripCapturedAdRowsReadback(array $rows): int
    {
        $matched = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['data_date']) || empty($row['data_type'])) {
                continue;
            }
            $identity = implode('|', [
                (string)($row['source'] ?? 'ctrip'),
                (string)$row['data_date'],
                (string)($row['dimension'] ?? ''),
                (string)($row['hotel_id'] ?? ''),
                (string)($row['hotel_name'] ?? ''),
                (string)($row['system_hotel_id'] ?? ''),
            ]);
            if (isset($matched[$identity])) {
                continue;
            }

            $query = Db::name('online_daily_data')
                ->where('source', (string)($row['source'] ?? 'ctrip'))
                ->where('data_type', 'advertising')
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));
            if (!empty($row['hotel_id'])) {
                $query->where('hotel_id', (string)$row['hotel_id']);
            } else {
                $query->where('hotel_name', (string)($row['hotel_name'] ?? ''));
            }
            if (array_key_exists('system_hotel_id', $row) && $row['system_hotel_id'] !== null) {
                $query->where('system_hotel_id', (int)$row['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }

            $stored = $query->field('id,raw_data')->find();
            if (!is_array($stored) || empty($stored['id'])) {
                continue;
            }
            $expectedRaw = (string)($row['raw_data'] ?? '');
            if ($expectedRaw !== '' && (string)($stored['raw_data'] ?? '') !== $expectedRaw) {
                continue;
            }
            $matched[$identity] = true;
        }

        return count($matched);
    }
}
