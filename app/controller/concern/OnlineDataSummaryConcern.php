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
            'ota_channel_supplement' => $this->buildDailyOtaSupplementSummary($truthRows, [
                'requested_source' => $source,
                'tenant_id' => max(0, (int)($this->currentUser->tenant_id ?? 0)),
                'system_hotel_id' => $hotelId,
                'start_date' => (string)$startDate,
                'end_date' => (string)$endDate,
            ]),
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

    private function buildDailyOtaSupplementSummary(array $rows, array $context = []): array
    {
        $advertising = $this->buildDailyOtaAdvertisingSummary($rows);
        $serviceQuality = $this->buildDailyOtaServiceQualitySummary($rows);
        $reviews = $this->buildDailyOtaReviewSummary($rows, $context);
        $hasData = in_array((string)($advertising['data_status'] ?? ''), ['ok', 'partial'], true)
            || in_array((string)($serviceQuality['data_status'] ?? ''), ['ok', 'partial'], true)
            || in_array((string)($reviews['data_status'] ?? ''), ['ok', 'partial'], true);
        $truthEnvelopes = array_values(array_filter(array_map(
            static fn(array $row): mixed => $row['truth'] ?? null,
            array_values(array_filter($rows, function (array $row): bool {
                return in_array($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')), [
                    'advertising', 'quality', 'service', 'service_quality', 'psi', 'review',
                ], true);
            }))
        ), 'is_array'));
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'fallback_failure_reason' => $truthEnvelopes === [] ? '当前筛选范围没有广告、服务质量或点评 OTA 记录' : '',
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
            'reviews' => $reviews,
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

    /**
     * Build a reputation workbench projection without averaging incompatible
     * platform scores or promoting unverified review rows into visible facts.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildDailyOtaReviewSummary(array $rows, array $context = []): array
    {
        $requestedSource = $this->normalizeDailyOtaReviewSource((string)($context['requested_source'] ?? ''));
        $requestedHotelId = trim((string)($context['system_hotel_id'] ?? ''));
        $tenantId = max(0, (int)($context['tenant_id'] ?? 0));
        $expectedSources = in_array($requestedSource, ['ctrip', 'meituan'], true)
            ? [$requestedSource]
            : ['ctrip', 'meituan'];
        $candidatesBySource = [];
        $allTruthEnvelopes = [];
        $observedSystemHotelIds = [];

        foreach ($rows as $row) {
            if ($this->normalizeDailyOtaSupplementDataType((string)($row['data_type'] ?? '')) !== 'review') {
                continue;
            }

            $source = $this->normalizeDailyOtaReviewSource((string)($row['source'] ?? $row['platform'] ?? ''));
            if ($source === '') {
                $source = 'unknown';
            }
            if (!in_array($source, $expectedSources, true) && $requestedSource === '') {
                $expectedSources[] = $source;
            }
            if (is_array($row['truth'] ?? null)) {
                $allTruthEnvelopes[] = $row['truth'];
            }
            $rowSystemHotelId = max(0, (int)($row['system_hotel_id'] ?? 0));
            if ($rowSystemHotelId > 0) {
                $observedSystemHotelIds[$rowSystemHotelId] = true;
            }

            [$decodedRaw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
            $flatRaw = $this->dailyOtaReviewFlatRaw($decodedRaw);
            $channel = $this->dailyOtaReviewChannel($source, $row, $flatRaw);
            $candidate = [
                'id' => max(0, (int)($row['id'] ?? 0)),
                'source' => $source,
                'platform' => $this->dailyOtaReviewSourceLabel($source),
                'channel' => $channel,
                'tenant_id' => max(0, (int)($row['tenant_id'] ?? $tenantId)),
                'system_hotel_id' => $rowSystemHotelId > 0 ? $rowSystemHotelId : null,
                'platform_store_id' => trim((string)($row['hotel_id'] ?? '')) ?: null,
                'data_date' => trim((string)($row['data_date'] ?? '')),
                'collected_at' => trim((string)($row['snapshot_time'] ?? $row['update_time'] ?? $row['create_time'] ?? '')),
                'source_method' => trim((string)(
                    $row['ingestion_method']
                    ?? $row['source_method']
                    ?? ($row['truth']['source']['method'] ?? '')
                )),
                'score' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['comment_score', 'commentScore', 'review_score', 'reviewScore', 'rating', 'score', 'overallScore', 'totalScore']
                ),
                'review_count' => $this->dailyOtaReviewCount($source, $row, $flatRaw),
                'bad_review_count' => $this->dailyOtaReviewBadCount($source, $row, $flatRaw),
                'unreplied_count' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['comment_unreply_count', 'unReplyCount', 'unreplied_count', 'unrepliedCount']
                ),
                'good_rate' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['comment_good_rate', 'goodRate', 'positive_rate', 'positiveRate']
                ),
                'response_rate' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['comment_response_rate', 'responseRate', 'reply_rate', 'replyRate']
                ),
                'review_photo_count' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_photo_count', 'hasPicCount', 'photoCount', 'pictureCount']
                ),
                'review_photo_rate' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_photo_rate', 'photoRate', 'pictureRate']
                ),
                'review_environment_score' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_environment_score', 'ratingLocation', 'environmentScore', 'envScore']
                ),
                'review_facility_score' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_facility_score', 'ratingFacility', 'facilityScore', 'facilitiesScore']
                ),
                'review_service_score' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_service_score', 'ratingService', 'reviewServiceScore', 'commentServiceScore']
                ),
                'review_cleanliness_score' => $this->dailyOtaSupplementFirstNumber(
                    $row,
                    $flatRaw,
                    ['review_cleanliness_score', 'ratingRoom', 'cleanlinessScore', 'hygieneScore']
                ),
                'truth' => is_array($row['truth'] ?? null) ? $row['truth'] : null,
            ];
            if ($candidate['score'] !== null && $candidate['score'] <= 0) {
                $candidate['score'] = null;
            }
            foreach (['review_count', 'bad_review_count', 'unreplied_count', 'review_photo_count'] as $countKey) {
                if ($candidate[$countKey] !== null) {
                    $candidate[$countKey] = max(0, (int)round((float)$candidate[$countKey]));
                }
            }
            $candidate['metrics_available'] = $this->dailyOtaReviewCandidateHasMetrics($candidate);
            $candidate['usable'] = $this->dailySummaryTruthUsable(['truth' => $candidate['truth']]);
            $candidatesBySource[$source][] = $candidate;
        }

        $observedSystemHotelIds = array_keys($observedSystemHotelIds);
        $hotelScopeStatus = $requestedHotelId !== ''
            ? 'single_requested'
            : (count($observedSystemHotelIds) === 1
                ? 'single_observed'
                : (count($observedSystemHotelIds) > 1 ? 'mixed' : 'missing'));
        $resolvedSystemHotelId = $requestedHotelId !== ''
            ? $requestedHotelId
            : ($observedSystemHotelIds[0] ?? null);
        $scopeBlocked = $hotelScopeStatus === 'mixed';
        if ($scopeBlocked) {
            foreach ($candidatesBySource as &$sourceCandidates) {
                foreach ($sourceCandidates as &$candidate) {
                    $candidate['usable'] = false;
                }
                unset($candidate);
            }
            unset($sourceCandidates);
        }

        $platforms = [];
        foreach (array_values(array_unique($expectedSources)) as $source) {
            $platforms[] = $this->buildDailyOtaReviewPlatform(
                $source,
                is_array($candidatesBySource[$source] ?? null) ? $candidatesBySource[$source] : [],
                [
                    'schema_version' => 'ota_reputation_summary.v1',
                    'tenant_id' => $tenantId > 0 ? $tenantId : null,
                    'system_hotel_id' => $resolvedSystemHotelId,
                    'hotel_scope_status' => $hotelScopeStatus,
                    'start_date' => trim((string)($context['start_date'] ?? '')) ?: null,
                    'end_date' => trim((string)($context['end_date'] ?? '')) ?: null,
                ]
            );
        }

        $overallTruth = OnlineDataTrustStatusService::summarizeTruthEnvelopes($allTruthEnvelopes, [
            'fallback_failure_reason' => $allTruthEnvelopes === [] ? '当前筛选范围没有点评 OTA 记录' : '',
        ]);
        $availablePlatforms = $scopeBlocked ? 0 : count(array_filter(
            $platforms,
            static fn(array $platform): bool => in_array((string)($platform['data_status'] ?? ''), ['ok', 'partial'], true)
        ));
        $reviewRowCount = array_sum(array_map(
            static fn(array $platform): int => (int)($platform['observed_row_count'] ?? 0),
            $platforms
        ));
        $usableReviewRowCount = array_sum(array_map(
            static fn(array $platform): int => (int)($platform['usable_row_count'] ?? 0),
            $platforms
        ));
        $summaryDataStatus = $scopeBlocked
            ? 'unverified'
            : ($availablePlatforms > 0
                ? (($overallTruth['status'] ?? '') === 'verified' && $usableReviewRowCount === $reviewRowCount ? 'ok' : 'partial')
                : ($reviewRowCount > 0 ? 'unverified' : 'missing'));
        $displayOverallTruth = $this->dailyOtaReviewDisplayTruth(
            $overallTruth,
            $summaryDataStatus,
            $scopeBlocked ? '当前点评范围包含多个酒店，已阻止跨店汇总' : ''
        );

        return [
            'schema_version' => 'ota_reputation_summary.v1',
            'scope' => 'ota_channel_reputation',
            'source_table' => 'online_daily_data',
            'data_status' => $summaryDataStatus,
            'data_notice' => 'platform_scores_are_separate_no_cross_platform_average',
            'score_aggregation' => 'platform_separate_no_cross_platform_average',
            'identity' => [
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'system_hotel_id' => $resolvedSystemHotelId,
                'hotel_scope_status' => $hotelScopeStatus,
                'requested_source' => $requestedSource !== '' ? $requestedSource : null,
                'start_date' => trim((string)($context['start_date'] ?? '')) ?: null,
                'end_date' => trim((string)($context['end_date'] ?? '')) ?: null,
            ],
            'scope_blocker' => $scopeBlocked ? 'hotel_scope_mixed' : null,
            'platforms' => $platforms,
            'available_platform_count' => $availablePlatforms,
            'observed_row_count' => $reviewRowCount,
            'usable_row_count' => $usableReviewRowCount,
            'truth_context' => $displayOverallTruth,
        ];
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function buildDailyOtaReviewPlatform(string $source, array $candidates, array $identityContext = []): array
    {
        usort($candidates, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)($right['data_date'] ?? ''), (string)($left['data_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
        });

        $truthEnvelopes = array_values(array_filter(array_map(
            static fn(array $candidate): mixed => $candidate['truth'] ?? null,
            $candidates
        ), 'is_array'));
        $truthContext = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthEnvelopes, [
            'fallback_failure_reason' => $candidates === []
                ? sprintf('当前筛选范围没有%s点评记录', $this->dailyOtaReviewSourceLabel($source))
                : sprintf('%s点评记录尚未通过事实验证', $this->dailyOtaReviewSourceLabel($source)),
        ]);
        $usable = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => ($candidate['usable'] ?? false) === true
        ));
        $primary = $this->selectDailyOtaReviewPrimaryCandidate($source, $usable);
        $channels = $this->buildDailyOtaReviewChannels($source, $usable);
        $trend = $this->buildDailyOtaReviewTrend($source, $usable);
        $latestPoint = $trend !== [] ? $trend[count($trend) - 1] : null;
        $previousPoint = count($trend) > 1 ? $trend[count($trend) - 2] : null;
        $scoreChange = $this->dailyOtaReviewMetricChange($latestPoint['score'] ?? null, $previousPoint['score'] ?? null, false);
        $reviewCountChange = $this->dailyOtaReviewMetricChange($latestPoint['review_count'] ?? null, $previousPoint['review_count'] ?? null, true);
        $badReviewCountChange = $this->dailyOtaReviewMetricChange($latestPoint['bad_review_count'] ?? null, $previousPoint['bad_review_count'] ?? null, true);
        $reviewCountChangeBasis = $this->dailyOtaReviewChangeBasis($latestPoint, $previousPoint);
        $hasMetrics = $primary !== null && ($primary['metrics_available'] ?? false) === true;
        $platformDataStatus = $candidates === []
            ? 'missing'
            : ($usable === [] ? 'unverified' : ($hasMetrics
                ? (($truthContext['status'] ?? '') === 'verified' && count($usable) === count($candidates) ? 'ok' : 'partial')
                : 'partial'));
        $displayTruthContext = $this->dailyOtaReviewDisplayTruth(
            $truthContext,
            $platformDataStatus,
            ($identityContext['hotel_scope_status'] ?? '') === 'mixed'
                ? '当前点评范围包含多个酒店，已阻止跨店汇总'
                : ''
        );

        return [
            'source' => $source,
            'label' => $this->dailyOtaReviewSourceLabel($source),
            'data_status' => $platformDataStatus,
            'score' => $primary['score'] ?? null,
            'score_label' => '平台原始分',
            'review_count' => $primary['review_count'] ?? null,
            'bad_review_count' => $primary['bad_review_count'] ?? null,
            'unreplied_count' => $primary['unreplied_count'] ?? null,
            'good_rate' => $primary['good_rate'] ?? null,
            'response_rate' => $primary['response_rate'] ?? null,
            'score_change' => $scoreChange,
            'review_count_change' => $reviewCountChange,
            'bad_review_count_change' => $badReviewCountChange,
            'review_count_change_basis' => $reviewCountChangeBasis,
            'latest_day_new_review_count' => $reviewCountChangeBasis === 'adjacent_business_day'
                ? $reviewCountChange
                : null,
            'latest_day_new_review_date' => $reviewCountChangeBasis === 'adjacent_business_day'
                ? ($latestPoint['data_date'] ?? null)
                : null,
            'count_rebaseline_required' => $reviewCountChangeBasis === 'rebaseline_required',
            'latest_data_date' => $primary['data_date'] ?? null,
            'latest_collected_at' => $primary['collected_at'] ?? null,
            'primary_channel' => $primary['channel'] ?? $this->dailyOtaReviewSourceLabel($source),
            'review_photo_count' => $primary['review_photo_count'] ?? null,
            'review_photo_rate' => $primary['review_photo_rate'] ?? null,
            'quality_dimensions' => [
                'environment' => $primary['review_environment_score'] ?? null,
                'facility' => $primary['review_facility_score'] ?? null,
                'service' => $primary['review_service_score'] ?? null,
                'cleanliness' => $primary['review_cleanliness_score'] ?? null,
            ],
            'identity' => [
                'schema_version' => (string)($identityContext['schema_version'] ?? 'ota_reputation_summary.v1'),
                'tenant_id' => $primary['tenant_id'] ?? ($identityContext['tenant_id'] ?? null),
                'system_hotel_id' => $primary['system_hotel_id'] ?? ($identityContext['system_hotel_id'] ?? null),
                'platform' => $source,
                'platform_store_id' => $primary['platform_store_id'] ?? null,
                'business_date' => $primary['data_date'] ?? null,
                'date_range' => [
                    'start_date' => $identityContext['start_date'] ?? null,
                    'end_date' => $identityContext['end_date'] ?? null,
                ],
                'source_method' => $primary['source_method'] ?? null,
                'collected_at' => $primary['collected_at'] ?? null,
                'data_status' => $platformDataStatus,
                'hotel_scope_status' => $identityContext['hotel_scope_status'] ?? 'missing',
            ],
            'channels' => $channels,
            'trend' => $trend,
            'observed_row_count' => count($candidates),
            'usable_row_count' => count($usable),
            'truth_context' => $displayTruthContext,
        ];
    }

    /** @param array<string, mixed> $truth @return array<string, mixed> */
    private function dailyOtaReviewDisplayTruth(array $truth, string $dataStatus, string $failureReason = ''): array
    {
        if ($dataStatus === 'ok') {
            return $truth;
        }
        $truth['status'] = $dataStatus === 'partial' ? 'partial' : 'unverified';
        $truth['status_label'] = $dataStatus === 'partial' ? '部分数据' : '未验证';
        if ($failureReason !== '') {
            $truth['failure_reason'] = $failureReason;
        } elseif (trim((string)($truth['failure_reason'] ?? '')) === '') {
            $truth['failure_reason'] = $dataStatus === 'partial'
                ? '部分点评记录或指标尚未通过事实验证'
                : '当前筛选范围没有可验证点评事实';
        }
        return $truth;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function selectDailyOtaReviewPrimaryCandidate(string $source, array $candidates): ?array
    {
        $primaryLabels = $source === 'ctrip'
            ? ['ctrip', '携程']
            : ($source === 'meituan' ? ['meituan', '美团', '点评聚合'] : [$source]);
        $withMetrics = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => ($candidate['metrics_available'] ?? false) === true
        ));
        foreach ($withMetrics as $candidate) {
            $channel = strtolower(trim((string)($candidate['channel'] ?? '')));
            foreach ($primaryLabels as $label) {
                if ($channel === strtolower($label)) {
                    return $candidate;
                }
            }
        }

        return $withMetrics[0] ?? ($candidates[0] ?? null);
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function buildDailyOtaReviewChannels(string $source, array $candidates): array
    {
        $latestByChannel = [];
        foreach ($candidates as $candidate) {
            $label = trim((string)($candidate['channel'] ?? '')) ?: $this->dailyOtaReviewSourceLabel($source);
            $key = mb_strtolower($label, 'UTF-8');
            if (!isset($latestByChannel[$key]) || $this->dailyOtaReviewCandidateIsNewer($candidate, $latestByChannel[$key])) {
                $latestByChannel[$key] = $candidate;
            }
        }

        $channels = array_values(array_map(static function (array $candidate): array {
            return [
                'label' => $candidate['channel'],
                'data_date' => $candidate['data_date'],
                'score' => $candidate['score'],
                'review_count' => $candidate['review_count'],
                'bad_review_count' => $candidate['bad_review_count'],
            ];
        }, $latestByChannel));
        usort($channels, static fn(array $left, array $right): int => strcmp((string)$left['label'], (string)$right['label']));
        return $channels;
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private function buildDailyOtaReviewTrend(string $source, array $candidates): array
    {
        $byDate = [];
        foreach ($candidates as $candidate) {
            $date = trim((string)($candidate['data_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $current = $byDate[$date] ?? null;
            $candidatePrimary = $this->dailyOtaReviewChannelIsPrimary($source, (string)($candidate['channel'] ?? ''));
            $currentPrimary = is_array($current)
                ? $this->dailyOtaReviewChannelIsPrimary($source, (string)($current['channel'] ?? ''))
                : false;
            if ($current === null
                || ($candidatePrimary && !$currentPrimary)
                || ($candidatePrimary === $currentPrimary && $this->dailyOtaReviewCandidateIsNewer($candidate, $current))
            ) {
                $byDate[$date] = $candidate;
            }
        }
        ksort($byDate);
        if (count($byDate) > 7) {
            $byDate = array_slice($byDate, -7, 7, true);
        }

        return array_values(array_map(static function (array $candidate): array {
            return [
                'data_date' => $candidate['data_date'],
                'score' => $candidate['score'],
                'review_count' => $candidate['review_count'],
                'bad_review_count' => $candidate['bad_review_count'],
                'channel' => $candidate['channel'],
            ];
        }, $byDate));
    }

    private function dailyOtaReviewChannelIsPrimary(string $source, string $channel): bool
    {
        $channel = mb_strtolower(trim($channel), 'UTF-8');
        return $source === 'ctrip'
            ? in_array($channel, ['ctrip', '携程'], true)
            : ($source === 'meituan'
                ? in_array($channel, ['meituan', '美团', '点评聚合'], true)
                : $channel === mb_strtolower($source, 'UTF-8'));
    }

    /** @param array<string, mixed> $candidate */
    private function dailyOtaReviewCandidateHasMetrics(array $candidate): bool
    {
        foreach ([
            'score', 'review_count', 'bad_review_count', 'unreplied_count', 'good_rate', 'response_rate',
            'review_photo_count', 'review_photo_rate', 'review_environment_score', 'review_facility_score',
            'review_service_score', 'review_cleanliness_score',
        ] as $key) {
            if (($candidate[$key] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function dailyOtaReviewCandidateIsNewer(array $candidate, array $current): bool
    {
        $dateCompare = strcmp((string)($candidate['data_date'] ?? ''), (string)($current['data_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare > 0;
        }
        return (int)($candidate['id'] ?? 0) > (int)($current['id'] ?? 0);
    }

    private function dailyOtaReviewMetricChange(mixed $latest, mixed $previous, bool $rejectRegression): int|float|null
    {
        if (!is_numeric($latest) || !is_numeric($previous)) {
            return null;
        }
        $change = (float)$latest - (float)$previous;
        if ($rejectRegression && $change < 0) {
            return null;
        }
        $rounded = round($change, 2);
        return floor($rounded) === $rounded ? (int)$rounded : $rounded;
    }

    /** @param array<string, mixed>|null $latest @param array<string, mixed>|null $previous */
    private function dailyOtaReviewChangeBasis(?array $latest, ?array $previous): string
    {
        if ($latest === null || $previous === null) {
            return 'baseline_missing';
        }
        if (!is_numeric($latest['review_count'] ?? null) || !is_numeric($previous['review_count'] ?? null)) {
            return 'metric_missing';
        }
        if ((int)$latest['review_count'] < (int)$previous['review_count']) {
            return 'rebaseline_required';
        }

        $latestDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($latest['data_date'] ?? ''));
        $previousDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)($previous['data_date'] ?? ''));
        if (!$latestDate || !$previousDate) {
            return 'previous_available_snapshot';
        }
        $dayGap = (int)$previousDate->diff($latestDate)->format('%r%a');
        return $dayGap === 1 ? 'adjacent_business_day' : 'previous_available_snapshot';
    }

    /** @return array<string, mixed> */
    private function dailyOtaReviewFlatRaw(array $raw): array
    {
        $raw = $this->dailyOtaSupplementRawDetail($raw);
        foreach (['metrics', 'dimension_values', 'summary'] as $key) {
            if (is_array($raw[$key] ?? null)) {
                $raw = array_merge($raw, $raw[$key]);
            }
        }
        return $raw;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $raw */
    private function dailyOtaReviewChannel(string $source, array $row, array $raw): string
    {
        foreach (['comment_channel', 'review_channel', 'channelName', 'channel', 'platform'] as $key) {
            $value = trim((string)($raw[$key] ?? $row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        $dimension = trim((string)($row['dimension'] ?? $raw['dimension'] ?? ''));
        if (str_starts_with(strtolower($dimension), 'review:')) {
            $dimension = trim(substr($dimension, strlen('review:')));
        }
        if ($dimension !== '' && strtolower($dimension) !== 'review') {
            return $dimension;
        }
        return $this->dailyOtaReviewSourceLabel($source);
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $raw */
    private function dailyOtaReviewCount(string $source, array $row, array $raw): ?float
    {
        $count = $this->dailyOtaSupplementFirstNumber(
            $row,
            $raw,
            ['comment_count', 'commentCount', 'review_count', 'reviewCount', 'totalCommentCount', 'totalCount']
        );
        if ($count !== null) {
            return $count;
        }
        return $source === 'meituan'
            ? $this->dailyOtaSupplementFirstNumber($row, $raw, ['quantity'])
            : null;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $raw */
    private function dailyOtaReviewBadCount(string $source, array $row, array $raw): ?float
    {
        $count = $this->dailyOtaSupplementFirstNumber(
            $row,
            $raw,
            ['bad_review_count', 'badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount']
        );
        if ($count !== null) {
            return $count;
        }
        return $source === 'meituan'
            ? $this->dailyOtaSupplementFirstNumber($row, $raw, ['data_value', 'dataValue'])
            : null;
    }

    private function normalizeDailyOtaReviewSource(string $source): string
    {
        $source = strtolower(trim($source));
        return match ($source) {
            'xc', 'trip', 'ctrip' => 'ctrip',
            'mt', 'meituan' => 'meituan',
            default => $source,
        };
    }

    private function dailyOtaReviewSourceLabel(string $source): string
    {
        return match ($source) {
            'ctrip' => '携程',
            'meituan' => '美团',
            'unknown', '' => '未知平台',
            default => $source,
        };
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
