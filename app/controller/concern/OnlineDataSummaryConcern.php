<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OnlineDataFieldFactService;
use app\service\OnlineDataTrustStatusService;
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
        $requestedSystemHotelId = trim((string)$this->request->get('system_hotel_id', ''));
        $hotelId = trim((string)($requestedSystemHotelId !== ''
            ? $requestedSystemHotelId
            : $this->request->get('hotel_id', '')));
        $permittedHotelIds = [];
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_unique(array_filter(
                array_map('intval', $this->currentUser->getPermittedHotelIds()),
                static fn(int $id): bool => $id > 0
            )));
            if (empty($permittedHotelIds)) {
                return $this->error('No permitted hotel scope.', 403, [
                    'status_code' => 'hotel_scope_forbidden',
                ]);
            }
            if ($requestedSystemHotelId !== ''
                && ctype_digit($requestedSystemHotelId)
                && !in_array((int)$requestedSystemHotelId, $permittedHotelIds, true)
            ) {
                return $this->error('Requested hotel is outside permitted scope.', 403, [
                    'status_code' => 'hotel_scope_forbidden',
                    'system_hotel_id' => (int)$requestedSystemHotelId,
                ]);
            }
        }

        $rowsQuery = Db::name('online_daily_data')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($rowsQuery, $dataType);
        if ($source !== '') {
            $rowsQuery->where('source', $source);
        }
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($rowsQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $rowsQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $rows = $rowsQuery->order('data_date', 'desc')->order('id', 'desc')->select()->toArray();
        $truthRows = $this->buildDailySummaryTruthRows($rows);
        $usableRows = array_values(array_filter(
            $truthRows,
            fn(array $row): bool => $this->dailySummaryTruthUsable($row)
        ));
        $truthEnvelopes = array_values(array_filter(array_map(
            static fn(array $row): mixed => $row['truth'] ?? null,
            $truthRows
        ), 'is_array'));
        $excludedUntrustedCount = max(0, count($truthRows) - count($usableRows));
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'excluded_untrusted_count' => $excludedUntrustedCount,
            'fallback_failure_reason' => $excludedUntrustedCount > 0
                ? '未验证或采集失败记录已从汇总数字中排除'
                : ($truthRows === [] ? '当前筛选范围没有 OTA 入库记录' : ''),
        ]);
        $operatingSummary = $this->buildDailyOperatingSummary($usableRows);

        return $this->success([
            'daily' => $operatingSummary['daily'],
            'total' => $operatingSummary['total'],
            'ota_channel_supplement' => $this->buildDailyOtaSupplementSummary($truthRows),
            'truth_context' => $truthContext,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildDailySummaryTruthRows(array $rows): array
    {
        $systemHotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['system_hotel_id'] ?? 0)),
            $rows
        ))));
        $hotelNames = $systemHotelIds !== []
            ? Db::name('hotels')->whereIn('id', $systemHotelIds)->column('name', 'id')
            : [];

        foreach ($rows as &$row) {
            [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $systemHotelId = max(0, (int)($row['system_hotel_id'] ?? 0));
            if ($systemHotelId > 0 && trim((string)($hotelNames[$systemHotelId] ?? '')) !== '') {
                $row['system_hotel_name'] = (string)$hotelNames[$systemHotelId];
            }
            $row['truth'] = OnlineDataTrustStatusService::truthEnvelope(
                $row,
                OnlineDataFieldFactService::buildStatus($row, $raw)
            );
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function dailySummaryTruthUsable(array $row): bool
    {
        $status = strtolower(trim((string)($row['truth']['status'] ?? 'unverified')));
        return in_array($status, ['verified', 'partial'], true);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function buildDailyOperatingSummary(array $rows): array
    {
        $byGrain = [];
        foreach ($rows as $row) {
            if (!$this->isDailyOperatingRow($row)) {
                continue;
            }
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            $grain = implode('|', [
                (string)($row['data_date'] ?? ''),
                (string)($row['system_hotel_id'] ?? ''),
                strtolower(trim((string)($row['source'] ?? $row['platform'] ?? ''))),
            ]);
            $byGrain[$grain][$dataType][] = $row;
        }

        $selected = [];
        foreach ($byGrain as $typedRows) {
            $businessRows = is_array($typedRows['business'] ?? null) ? $typedRows['business'] : [];
            $orderRows = is_array($typedRows['order'] ?? null) ? $typedRows['order'] : [];
            array_push($selected, ...($businessRows !== [] ? $businessRows : $orderRows));
        }

        $dailyBuckets = [];
        foreach ($selected as $row) {
            $date = trim((string)($row['data_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $dailyBuckets[$date] ??= $this->emptyDailyOperatingBucket($date);
            $this->accumulateDailyOperatingRow($dailyBuckets[$date], $row);
        }

        krsort($dailyBuckets);
        $daily = [];
        foreach ($dailyBuckets as $bucket) {
            $daily[] = $this->finalizeDailyOperatingBucket($bucket, false);
        }

        $totalBucket = $this->emptyDailyOperatingBucket('');
        foreach ($selected as $row) {
            $this->accumulateDailyOperatingRow($totalBucket, $row);
        }
        $total = $this->finalizeDailyOperatingBucket($totalBucket, true);
        $total['scope'] = 'ota_channel';
        $total['source_table'] = 'online_daily_data';
        $total['data_notice'] = 'self_operating_facts_only_excludes_peer_rank_traffic_advertising';

        return ['daily' => $daily, 'total' => $total];
    }

    /** @param array<string, mixed> $row */
    private function isDailyOperatingRow(array $row): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if (!in_array($dataType, ['business', 'order'], true)) {
            return false;
        }
        if ((int)($row['system_hotel_id'] ?? 0) <= 0 || trim((string)($row['data_date'] ?? '')) === '') {
            return false;
        }
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        if (!in_array($compareType, ['', 'self'], true)) {
            return false;
        }
        return $dataType !== 'business' || !$this->isRankShapedDailyBusinessRow($row);
    }

    /** @param array<string, mixed> $row */
    private function isRankShapedDailyBusinessRow(array $row): bool
    {
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        if ($dimension !== '' && (str_contains($dimension, 'rank') || str_contains($dimension, '榜'))) {
            return true;
        }
        [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
        $raw = $this->dailyOtaSupplementRawDetail($raw);
        $hasRank = array_key_exists('rank', $raw)
            || array_key_exists('rankType', $raw)
            || array_key_exists('rank_type', $raw)
            || array_key_exists('aiMetricName', $raw);
        $hasPeerIdentity = trim((string)($raw['poiName'] ?? $raw['peerPoiId'] ?? $raw['peer_poi_id'] ?? '')) !== '';
        return $hasRank && $hasPeerIdentity;
    }

    private function emptyDailyOperatingBucket(string $date): array
    {
        return [
            'data_date' => $date,
            'total_amount' => 0.0,
            'total_quantity' => 0,
            'total_book_order_num' => 0,
            'comment_score_sum' => 0.0,
            'comment_score_count' => 0,
            'amount_seen' => false,
            'quantity_seen' => false,
            'orders_seen' => false,
            'sample_count' => 0,
            'truth_envelopes' => [],
        ];
    }

    /** @param array<string, mixed> $bucket @param array<string, mixed> $row */
    private function accumulateDailyOperatingRow(array &$bucket, array $row): void
    {
        foreach ([
            ['amount', 'total_amount', 'amount_seen', false],
            ['quantity', 'total_quantity', 'quantity_seen', true],
            ['book_order_num', 'total_book_order_num', 'orders_seen', true],
        ] as [$sourceKey, $targetKey, $seenKey, $integer]) {
            if (($row[$sourceKey] ?? null) !== null && $row[$sourceKey] !== '' && is_numeric($row[$sourceKey])) {
                $bucket[$targetKey] += $integer ? (int)$row[$sourceKey] : (float)$row[$sourceKey];
                $bucket[$seenKey] = true;
            }
        }
        if (($row['comment_score'] ?? null) !== null && is_numeric($row['comment_score'])) {
            $bucket['comment_score_sum'] += (float)$row['comment_score'];
            $bucket['comment_score_count']++;
        }
        if (is_array($row['truth'] ?? null)) {
            $bucket['truth_envelopes'][] = $row['truth'];
        }
        $bucket['sample_count']++;
    }

    /** @param array<string, mixed> $bucket */
    private function finalizeDailyOperatingBucket(array $bucket, bool $total): array
    {
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes(
            is_array($bucket['truth_envelopes'] ?? null) ? $bucket['truth_envelopes'] : [],
            [
                'start_date' => (string)($bucket['data_date'] ?? ''),
                'end_date' => (string)($bucket['data_date'] ?? ''),
                'fallback_failure_reason' => '当前汇总没有可核验的 OTA 经营记录',
            ]
        );
        $hasMetrics = $bucket['sample_count'] > 0
            && ($bucket['amount_seen'] || $bucket['quantity_seen'] || $bucket['orders_seen']);
        $result = [
            'total_amount' => $bucket['amount_seen'] ? round((float)$bucket['total_amount'], 2) : null,
            'total_quantity' => $bucket['quantity_seen'] ? (int)$bucket['total_quantity'] : null,
            'total_book_order_num' => $bucket['orders_seen'] ? (int)$bucket['total_book_order_num'] : null,
            'avg_comment_score' => $bucket['comment_score_count'] > 0
                ? round($bucket['comment_score_sum'] / $bucket['comment_score_count'], 2)
                : null,
            'sample_count' => (int)$bucket['sample_count'],
            'data_status' => $hasMetrics
                ? (($truthContext['status'] ?? '') === 'verified' ? 'ok' : 'partial')
                : 'pending',
            'truth_context' => $truthContext,
        ];
        if (!$total) {
            $result = ['data_date' => $bucket['data_date']] + $result;
        }
        return $result;
    }

    private function buildDailyOtaSupplementSummary(array $rows): array
    {
        $advertising = $this->buildDailyOtaAdvertisingSummary($rows);
        $serviceQuality = $this->buildDailyOtaServiceQualitySummary($rows);
        $hasData = in_array((string)($advertising['data_status'] ?? ''), ['ok', 'partial'], true)
            || in_array((string)($serviceQuality['data_status'] ?? ''), ['ok', 'partial'], true);
        $truthEnvelopes = array_values(array_filter(array_map(
            static fn(array $row): mixed => $row['truth'] ?? null,
            array_values(array_filter($rows, function (array $row): bool {
                return in_array($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')), [
                    'advertising', 'quality', 'service', 'service_quality', 'psi',
                ], true);
            }))
        ), 'is_array'));
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'fallback_failure_reason' => $truthEnvelopes === [] ? '当前筛选范围没有广告或服务质量 OTA 记录' : '',
        ]);

        return [
            'scope' => 'ota_channel',
            'source_table' => 'online_daily_data',
            'data_status' => $hasData
                ? (($truthContext['status'] ?? '') === 'verified' ? 'ok' : 'partial')
                : 'pending',
            'data_notice' => 'ota_channel_only_not_whole_hotel_scope',
            'advertising' => $advertising,
            'service_quality' => $serviceQuality,
            'truth_context' => $truthContext,
        ];
    }

    private function buildDailyOtaAdvertisingSummary(array $rows): array
    {
        $summary = [
            'spend' => null,
            'order_amount' => null,
            'bookings' => null,
            'room_nights' => null,
            'impressions' => null,
            'clicks' => null,
            'ctr' => null,
            'cvr' => null,
            'roas' => null,
            'sample_count' => 0,
            'data_status' => 'pending',
        ];
        $truthEnvelopes = [];

        foreach ($rows as $row) {
            if ($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')) !== 'advertising') {
                continue;
            }
            if (is_array($row['truth'] ?? null)) {
                $truthEnvelopes[] = $row['truth'];
            }
            if (!$this->dailySummaryTruthUsable($row)) {
                continue;
            }

            [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $raw = $this->dailyOtaSupplementRawDetail($raw);
            $values = [
                'spend' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']),
                'order_amount' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']),
                'impressions' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']),
                'clicks' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']),
                'bookings' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']),
                'room_nights' => $this->dailyOtaSupplementFirstNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'nights']),
            ];

            foreach ($values as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $summary[$key] = ($summary[$key] ?? 0) + (in_array($key, ['impressions', 'clicks', 'bookings'], true)
                    ? (int)round($value)
                    : $value);
            }
            if (array_filter($values, static fn(?float $value): bool => $value !== null) !== []) {
                $summary['sample_count']++;
            }
        }

        $summary['truth_context'] = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'fallback_failure_reason' => $truthEnvelopes === [] ? '当前筛选范围没有广告 OTA 记录' : '',
        ]);

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        foreach (['spend', 'order_amount', 'room_nights'] as $key) {
            if ($summary[$key] !== null) {
                $summary[$key] = round((float)$summary[$key], 2);
            }
        }
        $summary['ctr'] = ($summary['impressions'] ?? 0) > 0 && $summary['clicks'] !== null
            ? round($summary['clicks'] / $summary['impressions'] * 100, 2)
            : null;
        $summary['cvr'] = ($summary['clicks'] ?? 0) > 0 && $summary['bookings'] !== null
            ? round($summary['bookings'] / $summary['clicks'] * 100, 2)
            : null;
        $summary['roas'] = ($summary['spend'] ?? 0) > 0 && $summary['order_amount'] !== null
            ? round($summary['order_amount'] / $summary['spend'], 2)
            : null;
        $summary['data_status'] = ($summary['truth_context']['status'] ?? '') === 'verified' ? 'ok' : 'partial';

        return $summary;
    }

    private function buildDailyOtaServiceQualitySummary(array $rows): array
    {
        $summary = [
            'avg_psi_score' => null,
            'avg_service_score' => null,
            'sample_count' => 0,
            'data_status' => 'pending',
        ];
        $psiScores = [];
        $serviceScores = [];
        $truthEnvelopes = [];

        foreach ($rows as $row) {
            if (!in_array($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')), ['quality', 'service', 'service_quality', 'psi'], true)) {
                continue;
            }
            if (is_array($row['truth'] ?? null)) {
                $truthEnvelopes[] = $row['truth'];
            }
            if (!$this->dailySummaryTruthUsable($row)) {
                continue;
            }

            [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $raw = $this->dailyOtaSupplementRawDetail($raw);
            $psi = $this->dailyOtaSupplementFirstNumber($row, $raw, ['data_value', 'dataValue', 'psi_score', 'psiScore', 'psi', 'PSI', 'serviceQualityScore', 'qualityScore']);
            $serviceScore = $this->dailyOtaSupplementFirstNumber($row, $raw, ['service_score', 'serviceScore', 'dayReportServiceScore', 'service_score_value']);

            if ($psi !== null) {
                $psiScores[] = $psi;
            }
            if ($serviceScore !== null) {
                $serviceScores[] = $serviceScore;
            }
            if ($psi !== null || $serviceScore !== null) {
                $summary['sample_count']++;
            }
        }

        $summary['truth_context'] = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'fallback_failure_reason' => $truthEnvelopes === [] ? '当前筛选范围没有服务质量 OTA 记录' : '',
        ]);

        if ($summary['sample_count'] <= 0) {
            return $summary;
        }

        $summary['avg_psi_score'] = $this->avgDailyOtaSupplementNumbers($psiScores);
        $summary['avg_service_score'] = $this->avgDailyOtaSupplementNumbers($serviceScores);
        $summary['data_status'] = ($summary['truth_context']['status'] ?? '') === 'verified' ? 'ok' : 'partial';

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

    private function avgDailyOtaSupplementNumbers(array $values): ?float
    {
        $values = array_values(array_filter($values, static fn($value): bool => is_numeric($value)));
        if (empty($values)) {
            return null;
        }

        return round(array_sum(array_map('floatval', $values)) / count($values), 2);
    }
}
