<?php
declare(strict_types=1);

namespace app\controller\concern;

use think\Response;
use think\facade\Db;

trait OnlineDataSummaryConcern
{
    /**
     * 获取数据统计汇总
     */
    public function dailyDataSummary(): Response
    {
        $this->checkPermission();

        $startDate = $this->request->get('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $this->request->get('end_date', date('Y-m-d'));
        $source = trim((string)$this->request->get('source', ''));
        $dataType = $this->request->get('data_type', '');
        $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));
        $permittedHotelIds = [];
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success([
                    'daily' => [],
                    'total' => [
                        'total_amount' => 0,
                        'total_quantity' => 0,
                        'total_book_order_num' => 0,
                        'avg_comment_score' => 0,
                    ],
                ]);
            }
        }

        // 按日期汇总
        $dailyQuery = Db::name('online_daily_data')
            ->field('data_date, SUM(amount) as total_amount, SUM(quantity) as total_quantity, SUM(book_order_num) as total_book_order_num, AVG(comment_score) as avg_comment_score')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($dailyQuery, $dataType);
        if ($source !== '') {
            $dailyQuery->where('source', $source);
        }
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($dailyQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $dailyQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $dailySummary = $dailyQuery->group('data_date')
            ->order('data_date', 'desc')
            ->select()
            ->toArray();

        // 总计
        $totalQuery = Db::name('online_daily_data')
            ->field('SUM(amount) as total_amount, SUM(quantity) as total_quantity, SUM(book_order_num) as total_book_order_num, AVG(comment_score) as avg_comment_score')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($totalQuery, $dataType);
        if ($source !== '') {
            $totalQuery->where('source', $source);
        }
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($totalQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $totalQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $totalSummary = $totalQuery->find();

        $supplementQuery = Db::name('online_daily_data')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($supplementQuery, $dataType);
        if ($source !== '') {
            $supplementQuery->where('source', $source);
        }
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($supplementQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $supplementQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $supplementRows = $supplementQuery->select()->toArray();

        return $this->success([
            'daily' => $dailySummary,
            'total' => $totalSummary,
            'ota_channel_supplement' => $this->buildDailyOtaSupplementSummary($supplementRows),
        ]);
    }

    private function buildDailyOtaSupplementSummary(array $rows): array
    {
        $advertising = $this->buildDailyOtaAdvertisingSummary($rows);
        $serviceQuality = $this->buildDailyOtaServiceQualitySummary($rows);
        $hasData = ($advertising['data_status'] ?? '') === 'ok' || ($serviceQuality['data_status'] ?? '') === 'ok';

        return [
            'scope' => 'ota_channel',
            'source_table' => 'online_daily_data',
            'data_status' => $hasData ? 'ok' : 'pending',
            'data_notice' => 'ota_channel_only_not_whole_hotel_scope',
            'advertising' => $advertising,
            'service_quality' => $serviceQuality,
        ];
    }

    private function buildDailyOtaAdvertisingSummary(array $rows): array
    {
        $summary = [
            'spend' => 0.0,
            'order_amount' => 0.0,
            'bookings' => 0,
            'room_nights' => 0.0,
            'impressions' => 0,
            'clicks' => 0,
            'ctr' => null,
            'cvr' => null,
            'roas' => null,
            'sample_count' => 0,
            'data_status' => 'pending',
        ];

        foreach ($rows as $row) {
            if ($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')) !== 'advertising') {
                continue;
            }

            [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $raw = $this->dailyOtaSupplementRawDetail($raw);
            $spend = $this->dailyOtaSupplementFirstNumber($row, $raw, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']) ?? 0.0;
            $orderAmount = $this->dailyOtaSupplementFirstNumber($row, $raw, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']) ?? 0.0;
            $impressions = (int)round($this->dailyOtaSupplementFirstNumber($row, $raw, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']) ?? 0.0);
            $clicks = (int)round($this->dailyOtaSupplementFirstNumber($row, $raw, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']) ?? 0.0);
            $bookings = (int)round($this->dailyOtaSupplementFirstNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']) ?? 0.0);
            $roomNights = $this->dailyOtaSupplementFirstNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'nights']) ?? 0.0;

            $summary['spend'] += $spend;
            $summary['order_amount'] += $orderAmount;
            $summary['impressions'] += $impressions;
            $summary['clicks'] += $clicks;
            $summary['bookings'] += $bookings;
            $summary['room_nights'] += $roomNights;
            if ($spend > 0 || $orderAmount > 0 || $impressions > 0 || $clicks > 0 || $bookings > 0 || $roomNights > 0) {
                $summary['sample_count']++;
            }
        }

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        $summary['spend'] = round($summary['spend'], 2);
        $summary['order_amount'] = round($summary['order_amount'], 2);
        $summary['room_nights'] = round($summary['room_nights'], 2);
        $summary['ctr'] = $summary['impressions'] > 0 ? round($summary['clicks'] / $summary['impressions'] * 100, 2) : null;
        $summary['cvr'] = $summary['clicks'] > 0 ? round($summary['bookings'] / $summary['clicks'] * 100, 2) : null;
        $summary['roas'] = $summary['spend'] > 0 ? round($summary['order_amount'] / $summary['spend'], 2) : null;
        $summary['data_status'] = 'ok';

        return $summary;
    }

    private function buildDailyOtaServiceQualitySummary(array $rows): array
    {
        $summary = [
            'avg_psi_score' => 0.0,
            'avg_service_score' => 0.0,
            'sample_count' => 0,
            'data_status' => 'pending',
        ];
        $psiScores = [];
        $serviceScores = [];

        foreach ($rows as $row) {
            if (!in_array($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')), ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }

            [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $raw = $this->dailyOtaSupplementRawDetail($raw);
            $psi = $this->dailyOtaSupplementFirstNumber($row, $raw, ['data_value', 'dataValue', 'psi_score', 'psiScore', 'psi', 'PSI', 'serviceQualityScore', 'qualityScore']);
            $serviceScore = $this->dailyOtaSupplementFirstNumber($row, $raw, ['service_score', 'serviceScore', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null && $psi > 0) {
                $psiScores[] = $psi;
            }
            if ($serviceScore !== null && $serviceScore > 0) {
                $serviceScores[] = $serviceScore;
            }
            if (($psi !== null && $psi > 0) || ($serviceScore !== null && $serviceScore > 0)) {
                $summary['sample_count']++;
            }
        }

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        $summary['avg_psi_score'] = $this->avgDailyOtaSupplementNumbers($psiScores);
        $summary['avg_service_score'] = $this->avgDailyOtaSupplementNumbers($serviceScores);
        $summary['data_status'] = 'ok';

        return $summary;
    }

    private function normalizeDailyOtaSupplementDataType(string $value): string
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

    private function dailyOtaSupplementRawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? array_merge($raw, $raw['row']) : $raw;
    }

    private function dailyOtaSupplementFirstNumber(array $row, array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $num = $this->onlineDataQualityNumber($source[$key]);
                if ($num !== null) {
                    return $num;
                }
            }
        }

        return null;
    }

    private function avgDailyOtaSupplementNumbers(array $values): float
    {
        $values = array_values(array_filter($values, static fn($value): bool => is_numeric($value)));
        if (empty($values)) {
            return 0.0;
        }

        return round(array_sum(array_map('floatval', $values)) / count($values), 2);
    }
}
