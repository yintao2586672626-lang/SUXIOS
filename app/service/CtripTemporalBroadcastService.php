<?php
declare(strict_types=1);

namespace app\service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Turns verified Ctrip facts into a concise, deduplicated WeCom preview.
 *
 * This service never collects data, sends a message or enables a schedule.
 * Callers must pass same-hotel, read-back-verified segment snapshots.
 */
final class CtripTemporalBroadcastService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const STALE_AFTER_SECONDS = 3600;

    private const MODE_SEGMENTS = [
        'realtime' => ['present'],
        'daily' => ['past', 'present', 'future'],
        'review' => ['past'],
        'future' => ['future'],
    ];

    private const PRESENT_FIELDS = [
        'starting_price',
        'realtime_visitors',
        'last_week_visitors',
        'competitor_avg_visitor',
        'traffic_rank',
        'booking_orders',
        'in_house_room_nights',
        'list_exposure',
        'detail_exposure',
        'order_filling_num',
        'order_submit_num',
    ];

    private const PAST_FIELDS = [
        'list_exposure',
        'detail_exposure',
        'order_filling_num',
        'order_submit_num',
        'flow_rate',
    ];

    /** @return array<string, mixed> */
    public function build(array $input, ?DateTimeImmutable $now = null): array
    {
        $hotelId = (int)($input['system_hotel_id'] ?? 0);
        $hotelName = trim((string)($input['hotel_name'] ?? ''));
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('ctrip_temporal_system_hotel_id_required');
        }
        if ($hotelName === '') {
            throw new InvalidArgumentException('ctrip_temporal_hotel_name_required');
        }

        $clock = ($now ?? new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));
        $asOfDate = $this->date(
            (string)($input['as_of_date'] ?? $clock->format('Y-m-d')),
            'ctrip_temporal_as_of_date_invalid'
        );
        $mode = $this->mode((string)($input['message_mode'] ?? 'daily'));
        $requestedSegments = self::MODE_SEGMENTS[$mode];

        $segments = [
            'past' => $this->buildPast(
                $this->segment($input, 'past'),
                $hotelId,
                $asOfDate
            ),
            'present' => $this->buildPresent(
                $this->segment($input, 'present'),
                $hotelId,
                $asOfDate,
                $clock
            ),
            'future' => $this->buildFuture(
                $this->segment($input, 'future'),
                $hotelId,
                $asOfDate
            ),
        ];

        $selected = [];
        foreach ($requestedSegments as $segmentName) {
            $selected[$segmentName] = $segments[$segmentName];
        }

        $visibleSections = [];
        $internalGaps = [];
        foreach ($selected as $segmentName => $segment) {
            foreach ((array)($segment['gaps'] ?? []) as $gap) {
                $internalGaps[] = $segmentName . ':' . (string)$gap;
            }
            if (($segment['status'] ?? '') !== 'blocked'
                && (array)($segment['lines'] ?? []) !== []
            ) {
                $visibleSections[$segmentName] = $segment['lines'];
            }
        }

        $overallStatus = $this->overallStatus($selected, $visibleSections);
        $capturedAt = $this->latestCapturedAt($selected);
        $judgment = $this->judgment($selected);
        $content = $visibleSections === []
            ? ''
            : $this->message(
                $hotelName,
                $capturedAt,
                $visibleSections,
                $judgment,
                $this->containsStaleSegment($selected)
            );

        $fingerprint = $content === ''
            ? ''
            : hash('sha256', $this->json($this->canonical([
                'scope' => 'ctrip_ota_channel',
                'system_hotel_id' => $hotelId,
                'message_mode' => $mode,
                'content' => $content,
                'segments' => array_map(
                    static fn(array $segment): array => [
                        'status' => $segment['status'],
                        'batch_id' => $segment['batch_id'],
                        'captured_at' => $segment['captured_at'],
                        'facts' => $segment['fingerprint_facts'],
                    ],
                    $selected
                ),
            ])));

        $previousFingerprint = trim((string)($input['previous_fingerprint'] ?? ''));
        $baselineOnly = $this->boolean($input['baseline_only'] ?? false);
        $sendGate = $this->sendGate(
            $content,
            $fingerprint,
            $previousFingerprint,
            $baselineOnly,
            $overallStatus
        );

        return [
            'contract_version' => 'ctrip_temporal_broadcast.v1',
            'scope' => 'ctrip_ota_channel',
            'system_hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'as_of_date' => $asOfDate,
            'generated_at' => $clock->format('Y-m-d H:i:s'),
            'message_mode' => $mode,
            'cadence' => $this->cadence($mode),
            'status' => $overallStatus,
            'captured_at' => $capturedAt,
            'segments' => $segments,
            'selected_segments' => $requestedSegments,
            'visible_sections' => array_keys($visibleSections),
            'internal_gaps' => array_values(array_unique($internalGaps)),
            'judgment' => $judgment,
            'fingerprint' => $fingerprint,
            'send_gate' => $sendGate,
            'payload' => $content === '' ? null : [
                'msgtype' => 'text',
                'text' => ['content' => $content],
            ],
            'safety' => [
                'collects_data' => false,
                'sends_message' => false,
                'enables_timer' => false,
                'missing_values_are_omitted' => true,
                'captured_zero_is_preserved' => true,
                'competitor_circle_rank_is_excluded' => true,
                'whole_hotel_full_inference_is_forbidden' => true,
            ],
        ];
    }

    /**
     * Server-side entry point. Client-supplied facts are deliberately ignored;
     * only trusted online_daily_data rows may become a sendable preview.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildFromStoredRows(
        array $rows,
        int $hotelId,
        string $hotelName,
        string $asOfDate,
        string $messageMode = 'daily',
        string $previousFingerprint = '',
        bool $baselineOnly = false,
        ?DateTimeImmutable $now = null
    ): array {
        $trustedRows = array_values(array_filter(
            $rows,
            fn(mixed $row): bool => is_array($row)
                && $this->trustedStoredRow($row, $hotelId)
        ));
        $input = [
            'system_hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'as_of_date' => $asOfDate,
            'message_mode' => $messageMode,
            'previous_fingerprint' => $previousFingerprint,
            'baseline_only' => $baselineOnly,
            'past' => $this->storedPastSegment($trustedRows, $hotelId, $asOfDate),
            'present' => $this->storedPresentSegment($trustedRows, $hotelId, $asOfDate),
            'future' => $this->storedFutureSegment($trustedRows, $hotelId, $asOfDate),
        ];
        $result = $this->build($input, $now);
        $result['fact_source'] = [
            'table' => 'online_daily_data',
            'candidate_row_count' => count($rows),
            'trusted_row_count' => count($trustedRows),
            'input_policy' => 'server_rows_only_client_facts_ignored',
        ];
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function storedPresentSegment(array $rows, int $hotelId, string $asOfDate): array
    {
        $candidates = array_values(array_filter($rows, static fn(array $row): bool =>
            (string)($row['data_date'] ?? '') === $asOfDate
            && strtolower(trim((string)($row['data_period'] ?? ''))) === 'realtime_snapshot'
            && (int)($row['is_final'] ?? 0) === 0
        ));
        $batch = $this->latestStoredBatch($candidates);
        if ($batch === []) {
            return [];
        }

        $metrics = [];
        $visitor = $this->storedEndpointRow($batch, 'business_visitor_title');
        $this->putMetric(
            $metrics,
            'realtime_visitors',
            $this->storedFact($visitor, 'visitor_count', ['visitortotal'])
        );
        $this->putMetric(
            $metrics,
            'last_week_visitors',
            $this->storedFact($visitor, 'visitor_count_last_week', ['lastvisitortotal'])
        );
        $this->putMetric(
            $metrics,
            'competitor_avg_visitor',
            $this->storedFact($visitor, 'competitor_avg_visitor', ['competitoravgnumber'])
        );

        $order = $this->storedEndpointRow($batch, 'traffic_order_overview');
        $this->putMetric(
            $metrics,
            'booking_orders',
            $this->storedFact($order, 'order_count', ['data.orderquantity'])
        );

        $capacity = $this->storedEndpointRow($batch, 'business_capacity');
        $this->putMetric(
            $metrics,
            'in_house_room_nights',
            $this->storedFact($capacity, 'occupied_rooms', ['occupiedrooms'])
        );

        $rank = $this->storedEndpointRow($batch, 'traffic_hotel_seq');
        $rankValue = $this->storedFact($rank, 'traffic_rank', ['data.rank']);
        if ($rankValue === null) {
            $rank = $this->storedEndpointRow($batch, 'business_hotel_seq');
            $rankValue = $this->storedFact($rank, 'seq_rank', ['data.rank']);
        }
        $this->putMetric($metrics, 'traffic_rank', $rankValue);

        $flow = $this->storedOwnFlowRow($batch);
        $this->putMetric(
            $metrics,
            'list_exposure',
            $this->storedFact($flow, 'list_exposure', ['0.listexposure'])
        );
        $this->putMetric(
            $metrics,
            'detail_exposure',
            $this->storedFact($flow, 'detail_visitor', ['0.detailexposure'])
        );
        $this->putMetric(
            $metrics,
            'order_filling_num',
            $this->storedFact($flow, 'order_page_visitor', ['0.orderfillingnum'])
        );
        $this->putMetric(
            $metrics,
            'order_submit_num',
            $this->storedFact($flow, 'order_submit_user', ['0.ordersubmitnum'])
        );

        foreach ($batch as $row) {
            $price = $this->storedFact($row, 'starting_price');
            if ($price === null) {
                $price = $this->storedFact($row, 'lead_price');
            }
            if ($price !== null) {
                $metrics['starting_price'] = $price;
                break;
            }
        }

        return [
            'system_hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_date' => $asOfDate,
            'captured_at' => $this->storedCapturedAt($batch),
            'batch_id' => $this->storedBatchId($batch),
            'readback_verified' => true,
            'metrics' => $metrics,
            'intraday_visitor_trend' => $this->storedVisitorTrend($batch),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function storedPastSegment(array $rows, int $hotelId, string $asOfDate): array
    {
        $windowRows = array_values(array_filter($rows, fn(array $row): bool =>
            $this->validDate((string)($row['data_date'] ?? ''))
            && (string)($row['data_date'] ?? '') < $asOfDate
            && strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily'
            && (int)($row['is_final'] ?? 0) === 1
            && $this->storedEndpointId($row) === 'traffic_flow_transform'
            && $this->storedRowIsOwnFlow($row)
            && $this->storedPastWindow($row) !== ''
        ));
        $windowBatch = $this->latestStoredBatch($windowRows);
        if ($windowBatch !== []) {
            $windows = [];
            foreach (['yesterday', 'last_7_days', 'last_30_days'] as $windowName) {
                $matching = array_values(array_filter(
                    $windowBatch,
                    fn(array $row): bool => $this->storedPastWindow($row) === $windowName
                ));
                if ($matching === []) {
                    continue;
                }
                usort($matching, static fn(array $left, array $right): int =>
                    ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0))
                );
                $metrics = $this->aggregateStoredFlowMetrics([$matching[0]]);
                if ($metrics !== []) {
                    $windows[] = ['window' => $windowName, 'metrics' => $metrics];
                }
            }
            if ($windows !== []) {
                $asOf = new DateTimeImmutable($asOfDate, new DateTimeZone(self::TIMEZONE));
                return [
                    'system_hotel_id' => $hotelId,
                    'platform' => 'ctrip',
                    'data_date' => $asOf->sub(new DateInterval('P1D'))->format('Y-m-d'),
                    'captured_at' => $this->storedCapturedAt($windowBatch),
                    'batch_id' => $this->storedBatchId($windowBatch),
                    'readback_verified' => true,
                    'is_final' => true,
                    'windows' => $windows,
                ];
            }
        }

        // Backward-compatible fallback for older stores that saved one
        // finalized traffic row per calendar day instead of explicit
        // yesterday / 7-day / 30-day aggregate windows.
        $dailyRows = [];
        foreach ($rows as $row) {
            $dataDate = trim((string)($row['data_date'] ?? ''));
            if (!$this->validDate($dataDate)
                || $dataDate >= $asOfDate
                || strtolower(trim((string)($row['data_period'] ?? ''))) !== 'historical_daily'
                || (int)($row['is_final'] ?? 0) !== 1
                || $this->storedEndpointId($row) !== 'traffic_flow_transform'
                || !$this->storedRowIsOwnFlow($row)
            ) {
                continue;
            }
            $dailyRows[$dataDate][] = $row;
        }
        if ($dailyRows === []) {
            return [];
        }

        $selectedByDate = [];
        foreach ($dailyRows as $dataDate => $dateRows) {
            $selectedByDate[$dataDate] = $this->latestStoredBatch($dateRows)[0] ?? null;
        }
        $selectedByDate = array_filter($selectedByDate, 'is_array');
        $asOf = new DateTimeImmutable($asOfDate, new DateTimeZone(self::TIMEZONE));
        $windows = [];
        foreach ([
            'yesterday' => 1,
            'last_7_days' => 7,
            'last_30_days' => 30,
        ] as $window => $days) {
            $windowRows = [];
            for ($offset = 1; $offset <= $days; $offset++) {
                $date = $asOf->sub(new DateInterval('P' . $offset . 'D'))->format('Y-m-d');
                if (!isset($selectedByDate[$date])) {
                    $windowRows = [];
                    break;
                }
                $windowRows[] = $selectedByDate[$date];
            }
            if ($windowRows === []) {
                continue;
            }
            $metrics = $this->aggregateStoredFlowMetrics($windowRows);
            if ($metrics !== []) {
                $windows[] = ['window' => $window, 'metrics' => $metrics];
            }
        }
        if ($windows === []) {
            return [];
        }

        $usedRows = array_values($selectedByDate);
        return [
            'system_hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_date' => $asOf->sub(new DateInterval('P1D'))->format('Y-m-d'),
            'captured_at' => $this->storedCapturedAt($usedRows),
            'batch_id' => 'history:' . hash(
                'sha256',
                implode(',', array_map(
                    static fn(array $row): string => (string)($row['id'] ?? ''),
                    $usedRows
                ))
            ),
            'readback_verified' => true,
            'is_final' => true,
            'windows' => $windows,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function storedFutureSegment(array $rows, int $hotelId, string $asOfDate): array
    {
        $candidates = array_values(array_filter($rows, fn(array $row): bool =>
            (string)($row['data_date'] ?? '') === $asOfDate
            && $this->storedEndpointId($row) === 'traffic_search_details'
        ));
        $batch = $this->latestStoredBatch($candidates);
        if ($batch === []) {
            return [];
        }

        $dates = [];
        foreach ($batch as $row) {
            $raw = $this->storedRaw($row);
            $dimensions = is_array($raw['dimension_values'] ?? null)
                ? $raw['dimension_values']
                : [];
            $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
            $targetDate = trim((string)($dimensions['target_date'] ?? ''));
            $window = strtolower(trim((string)($dimensions['search_window'] ?? '')));
            $scope = strtolower(trim((string)($dimensions['compare_scope'] ?? '')));
            if (in_array($scope, ['competitor', 'peer'], true)) {
                $scope = 'competitor_avg';
            }
            if (!$this->validDate($targetDate)
                || !in_array($window, ['cumulative', 'yesterday'], true)
                || !in_array($scope, ['self', 'competitor_avg'], true)
            ) {
                continue;
            }
            $dates[$targetDate] ??= ['target_date' => $targetDate];
            $dates[$targetDate][$window] ??= [];
            $dates[$targetDate][$window][$scope] = [
                'pv' => $this->numeric($metrics['future_search_pv'] ?? null),
                'uv' => $this->numeric($metrics['future_search_uv'] ?? null),
                'order_count' => $this->numeric($metrics['future_search_order_count'] ?? null),
                'conversion_rate' => $this->numeric(
                    $metrics['future_search_conversion_rate'] ?? null
                ),
            ];
        }
        if ($dates === []) {
            return [];
        }
        ksort($dates);
        $batchId = $this->storedBatchId($batch);
        $futureRows = [];
        foreach ($dates as $row) {
            $row['batch_id'] = $batchId;
            $futureRows[] = $row;
        }
        return [
            'system_hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_date' => $asOfDate,
            'captured_at' => $this->storedCapturedAt($batch),
            'batch_id' => $batchId,
            'readback_verified' => true,
            'rows' => $futureRows,
        ];
    }

    /** @return array<string, mixed> */
    private function buildPresent(
        array $segment,
        int $hotelId,
        string $asOfDate,
        DateTimeImmutable $now
    ): array {
        $envelope = $this->verifiedEnvelope($segment, $hotelId, $asOfDate, 'equal');
        if (!$envelope['valid']) {
            return $this->blockedSegment($envelope);
        }

        $rawMetrics = $this->metrics($segment);
        $metrics = [];
        $gaps = [];
        foreach (self::PRESENT_FIELDS as $field) {
            $metrics[$field] = $this->metric($rawMetrics, $field);
            if ($metrics[$field]['status'] === 'missing') {
                $gaps[] = 'metric_missing:' . $field;
            }
        }
        $metrics['exposure_to_detail_rate'] = $this->rateMetric(
            $metrics['detail_exposure'],
            $metrics['list_exposure'],
            'detail_exposure',
            'list_exposure'
        );
        if ($metrics['exposure_to_detail_rate']['status'] === 'missing') {
            $gaps[] = 'derived_metric_unavailable:exposure_to_detail_rate';
        }

        $trend = $this->intradayTrend($segment['intraday_visitor_trend'] ?? []);
        foreach ($trend['gaps'] as $gap) {
            $gaps[] = $gap;
        }
        $hasFacts = $this->hasMetricValue($metrics);
        if (!$hasFacts && $trend['points'] === []) {
            return $this->blockedSegment([
                'reason_codes' => ['present_metrics_missing'],
                'captured_at' => $envelope['captured_at'],
                'batch_id' => $envelope['batch_id'],
            ]);
        }

        $captured = $this->dateTime($envelope['captured_at']);
        $ageSeconds = max(0, $now->getTimestamp() - $captured->getTimestamp());
        $stale = $ageSeconds > self::STALE_AFTER_SECONDS;
        $lines = ['如今｜房态与流量'];
        $startingPrice = $this->value($metrics, 'starting_price');
        if ($startingPrice !== null) {
            $lines[] = $startingPrice == 0.0
                ? '携程房态：疑似满房/无房可售｜实时起价 ¥0.00'
                : '携程房态：在售｜实时起价 ¥' . number_format((float)$startingPrice, 2, '.', '');
        }

        $visitorParts = [];
        $visitors = $this->value($metrics, 'realtime_visitors');
        if ($visitors !== null) {
            $visitorParts[] = 'APP访客 ' . $this->integer($visitors);
        }
        $lastWeek = $this->value($metrics, 'last_week_visitors');
        if ($lastWeek !== null) {
            $visitorParts[] = '上周同期 ' . $this->integer($lastWeek);
        }
        $peer = $this->value($metrics, 'competitor_avg_visitor');
        if ($peer !== null) {
            $visitorParts[] = '竞争圈平均 ' . $this->integer($peer);
        }
        if ($visitorParts !== []) {
            $lines[] = implode('｜', $visitorParts);
        }

        $rank = $this->value($metrics, 'traffic_rank');
        if ($rank !== null) {
            $lines[] = '本店流量排名 ' . $this->integer($rank);
        }

        $bookingParts = [];
        $orders = $this->value($metrics, 'booking_orders');
        if ($orders !== null) {
            $bookingParts[] = '预订 ' . $this->integer($orders);
        }
        $roomNights = $this->value($metrics, 'in_house_room_nights');
        if ($roomNights !== null) {
            $bookingParts[] = '在店间夜 ' . $this->integer($roomNights);
        }
        if ($bookingParts !== []) {
            $lines[] = implode('｜', $bookingParts);
        }

        $funnel = $this->funnelLine($metrics);
        if ($funnel !== '') {
            $lines[] = $funnel;
        }
        $exposureRate = $this->value($metrics, 'exposure_to_detail_rate');
        if ($exposureRate !== null) {
            $lines[] = '曝光→详情 ' . $this->percent($exposureRate);
        }
        if ($trend['summary'] !== '') {
            $lines[] = $trend['summary'];
        }

        return [
            'status' => $stale ? 'stale' : ($gaps === [] ? 'available' : 'partial'),
            'quality_status' => $gaps === [] ? 'available' : 'partial',
            'freshness_status' => $stale ? 'stale' : 'fresh',
            'age_seconds' => $ageSeconds,
            'captured_at' => $envelope['captured_at'],
            'batch_id' => $envelope['batch_id'],
            'reason_codes' => $stale ? ['snapshot_older_than_one_hour'] : [],
            'metrics' => $metrics,
            'trend' => $trend,
            'gaps' => array_values(array_unique($gaps)),
            'lines' => $lines,
            'fingerprint_facts' => [
                'metrics' => $metrics,
                'trend' => $trend['points'],
                'stale' => $stale,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function buildPast(array $segment, int $hotelId, string $asOfDate): array
    {
        $envelope = $this->verifiedEnvelope($segment, $hotelId, $asOfDate, 'before', true);
        if (!$envelope['valid']) {
            return $this->blockedSegment($envelope);
        }

        $windows = is_array($segment['windows'] ?? null) ? $segment['windows'] : [];
        if ($windows === [] && $this->metrics($segment) !== []) {
            $windows[] = [
                'window' => (string)($segment['window'] ?? 'yesterday'),
                'metrics' => $this->metrics($segment),
            ];
        }

        $normalized = [];
        $gaps = [];
        foreach ($windows as $index => $window) {
            if (!is_array($window)) {
                $gaps[] = 'window_invalid:' . $index;
                continue;
            }
            $windowName = $this->pastWindow((string)($window['window'] ?? ''));
            if ($windowName === '') {
                $gaps[] = 'window_invalid:' . $index;
                continue;
            }
            $windowMetrics = $this->metrics($window);
            $metricSet = [];
            foreach (self::PAST_FIELDS as $field) {
                $metricSet[$field] = $this->metric($windowMetrics, $field);
            }
            if ($metricSet['flow_rate']['status'] === 'missing') {
                $metricSet['flow_rate'] = $this->rateMetric(
                    $metricSet['detail_exposure'],
                    $metricSet['list_exposure'],
                    'detail_exposure',
                    'list_exposure'
                );
            }
            if (!$this->hasMetricValue($metricSet)) {
                $gaps[] = 'window_metrics_missing:' . $windowName;
                continue;
            }
            $normalized[$windowName] = [
                'window' => $windowName,
                'label' => $this->pastWindowLabel($windowName),
                'metrics' => $metricSet,
            ];
        }

        if ($normalized === []) {
            return $this->blockedSegment([
                'reason_codes' => ['past_metrics_missing'],
                'captured_at' => $envelope['captured_at'],
                'batch_id' => $envelope['batch_id'],
            ]);
        }

        $lines = ['过去｜流量复盘'];
        foreach (['yesterday', 'last_7_days', 'last_30_days'] as $windowName) {
            if (!isset($normalized[$windowName])) {
                $gaps[] = 'window_missing:' . $windowName;
                continue;
            }
            $window = $normalized[$windowName];
            $funnel = $this->funnelLine($window['metrics']);
            $rate = $this->value($window['metrics'], 'flow_rate');
            $parts = [];
            if ($funnel !== '') {
                $parts[] = preg_replace('/^漏斗：/u', '', $funnel) ?? $funnel;
            }
            if ($rate !== null) {
                $parts[] = '曝光→详情 ' . $this->percent($rate);
            }
            if ($parts !== []) {
                $lines[] = $window['label'] . '：' . implode('｜', $parts);
            }
        }

        return [
            'status' => $gaps === [] ? 'available' : 'partial',
            'quality_status' => $gaps === [] ? 'available' : 'partial',
            'freshness_status' => 'finalized',
            'age_seconds' => null,
            'captured_at' => $envelope['captured_at'],
            'batch_id' => $envelope['batch_id'],
            'reason_codes' => [],
            'windows' => $normalized,
            'metrics' => [],
            'gaps' => array_values(array_unique($gaps)),
            'lines' => $lines,
            'fingerprint_facts' => ['windows' => $normalized],
        ];
    }

    /** @return array<string, mixed> */
    private function buildFuture(array $segment, int $hotelId, string $asOfDate): array
    {
        $envelope = $this->verifiedEnvelope($segment, $hotelId, $asOfDate, 'equal');
        if (!$envelope['valid']) {
            return $this->blockedSegment($envelope);
        }

        $rows = is_array($segment['rows'] ?? null) ? $segment['rows'] : [];
        $endDate = (new DateTimeImmutable($asOfDate, new DateTimeZone(self::TIMEZONE)))
            ->add(new DateInterval('P30D'))
            ->format('Y-m-d');
        $normalized = [];
        $gaps = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $gaps[] = 'future_row_invalid:' . $index;
                continue;
            }
            $rowBatch = trim((string)($row['batch_id'] ?? $row['sync_task_id'] ?? ''));
            if ($rowBatch !== '' && $rowBatch !== $envelope['batch_id']) {
                $gaps[] = 'future_row_batch_mismatch:' . $index;
                continue;
            }
            $targetDate = trim((string)($row['target_date'] ?? ''));
            if (!$this->validDate($targetDate)
                || $targetDate < $asOfDate
                || $targetDate > $endDate
            ) {
                $gaps[] = 'future_target_date_out_of_range:' . $index;
                continue;
            }

            $metrics = $this->futureMetrics($row, 'cumulative');
            if (!$this->hasMetricValue($metrics)) {
                $gaps[] = 'future_metrics_missing:' . $targetDate;
                continue;
            }
            $yesterdayMetrics = $this->futureMetrics($row, 'yesterday');
            $normalized[] = [
                'target_date' => $targetDate,
                'metrics' => $metrics,
                'yesterday_metrics' => $yesterdayMetrics,
            ];
        }

        if ($normalized === []) {
            return $this->blockedSegment([
                'reason_codes' => ['future_metrics_missing'],
                'captured_at' => $envelope['captured_at'],
                'batch_id' => $envelope['batch_id'],
            ]);
        }

        usort($normalized, function (array $left, array $right): int {
            $leftDemand = $this->futureDemandValue($left['metrics']);
            $rightDemand = $this->futureDemandValue($right['metrics']);
            $order = $rightDemand <=> $leftDemand;
            return $order !== 0
                ? $order
                : strcmp($left['target_date'], $right['target_date']);
        });

        $lines = ['未来｜需求研判'];
        foreach (array_slice($normalized, 0, 3) as $row) {
            $metrics = $row['metrics'];
            $selfUv = $this->value($metrics, 'future_search_uv');
            $peerUv = $this->value($metrics, 'competitor_future_search_uv');
            $selfPv = $this->value($metrics, 'future_search_pv');
            $peerPv = $this->value($metrics, 'competitor_future_search_pv');
            $parts = [];
            if ($selfUv !== null) {
                $parts[] = '累计UV ' . $this->integer($selfUv)
                    . ($peerUv !== null ? '（竞争圈 ' . $this->integer($peerUv) . '）' : '');
            } elseif ($selfPv !== null) {
                $parts[] = '累计PV ' . $this->integer($selfPv)
                    . ($peerPv !== null ? '（竞争圈 ' . $this->integer($peerPv) . '）' : '');
            }
            $yesterday = (array)($row['yesterday_metrics'] ?? []);
            $yesterdayUv = $this->value($yesterday, 'future_search_uv');
            $yesterdayPv = $this->value($yesterday, 'future_search_pv');
            if ($yesterdayUv !== null) {
                $parts[] = '昨日新增UV ' . $this->integer($yesterdayUv);
            } elseif ($yesterdayPv !== null) {
                $parts[] = '昨日新增PV ' . $this->integer($yesterdayPv);
            }
            $conversion = $this->value($metrics, 'future_search_conversion_rate');
            $peerConversion = $this->value(
                $metrics,
                'competitor_future_search_conversion_rate'
            );
            if ($conversion !== null) {
                $parts[] = '转化 ' . $this->percent($conversion)
                    . ($peerConversion !== null && $peerConversion != 0.0
                        ? '（竞争圈 ' . $this->percent($peerConversion) . '）'
                        : '');
            }
            $orders = $this->value($metrics, 'future_search_order_count');
            if ($orders !== null) {
                $parts[] = '搜索订单 ' . $this->integer($orders);
            }
            if ($parts !== []) {
                $lines[] = substr($row['target_date'], 5) . '：' . implode('｜', $parts);
            }
        }

        return [
            'status' => $gaps === [] ? 'available' : 'partial',
            'quality_status' => $gaps === [] ? 'available' : 'partial',
            'freshness_status' => 'current_capture',
            'age_seconds' => null,
            'captured_at' => $envelope['captured_at'],
            'batch_id' => $envelope['batch_id'],
            'reason_codes' => [],
            'coverage' => [
                'start_date' => $asOfDate,
                'end_date' => $endDate,
                'target_date_count' => count($normalized),
            ],
            'rows' => $normalized,
            'metrics' => [],
            'gaps' => array_values(array_unique($gaps)),
            'lines' => $lines,
            'fingerprint_facts' => ['rows' => $normalized],
        ];
    }

    /** @param array<string, mixed> $row */
    private function trustedStoredRow(array $row, int $hotelId): bool
    {
        $status = strtolower(trim((string)($row['validation_status'] ?? '')));
        $platform = strtolower(trim((string)($row['platform'] ?? '')));
        return (int)($row['system_hotel_id'] ?? 0) === $hotelId
            && strtolower(trim((string)($row['source'] ?? ''))) === 'ctrip'
            && $platform === 'ctrip'
            && $this->boolean($row['readback_verified'] ?? false)
            && (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['sync_task_id'] ?? 0) > 0
            && trim((string)($row['source_trace_id'] ?? '')) !== ''
            && in_array(
                $status,
                ['normal', 'verified', 'available', 'ready', 'partial', 'partial_success'],
                true
            );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function latestStoredBatch(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $batchId = $this->storedBatchId([$row]);
            if ($batchId === '') {
                continue;
            }
            $capturedAt = $this->storedRowCapturedAt($row);
            $timestamp = $capturedAt === '' ? 0 : (int)strtotime($capturedAt);
            $groups[$batchId] ??= [
                'batch_id' => $batchId,
                'captured_timestamp' => 0,
                'max_id' => 0,
                'rows' => [],
            ];
            $groups[$batchId]['captured_timestamp'] = max(
                (int)$groups[$batchId]['captured_timestamp'],
                $timestamp
            );
            $groups[$batchId]['max_id'] = max(
                (int)$groups[$batchId]['max_id'],
                (int)($row['id'] ?? 0)
            );
            $groups[$batchId]['rows'][] = $row;
        }
        if ($groups === []) {
            return [];
        }
        uasort($groups, static function (array $left, array $right): int {
            $timeOrder = ((int)$right['captured_timestamp'])
                <=> ((int)$left['captured_timestamp']);
            return $timeOrder !== 0
                ? $timeOrder
                : ((int)$right['max_id']) <=> ((int)$left['max_id']);
        });
        $latest = reset($groups);
        $selected = is_array($latest['rows'] ?? null)
            ? array_values($latest['rows'])
            : [];
        usort($selected, static fn(array $left, array $right): int =>
            ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0))
        );
        return $selected;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function storedBatchId(array $rows): string
    {
        foreach ($rows as $row) {
            $taskId = (int)($row['sync_task_id'] ?? 0);
            if ($taskId > 0) {
                return (string)$taskId;
            }
            $traceId = trim((string)($row['source_trace_id'] ?? ''));
            if ($traceId !== '') {
                return 'trace:' . $traceId;
            }
        }
        $capturedAt = $this->storedCapturedAt($rows);
        return $capturedAt === '' ? '' : 'captured_at:' . $capturedAt;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function storedCapturedAt(array $rows): string
    {
        $values = [];
        foreach ($rows as $row) {
            $value = $this->storedRowCapturedAt($row);
            if ($value !== '') {
                $values[] = $value;
            }
        }
        if ($values === []) {
            return '';
        }
        rsort($values);
        return $values[0];
    }

    /** @param array<string, mixed> $row */
    private function storedRowCapturedAt(array $row): string
    {
        $raw = $this->storedRaw($row);
        foreach ([
            $raw['captured_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $row['update_time'] ?? null,
            $row['create_time'] ?? null,
        ] as $candidate) {
            $normalized = $this->normalizeStoredDateTime((string)$candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }
        return '';
    }

    private function normalizeStoredDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if ($this->validDateTime($value)) {
            return $value;
        }
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone(self::TIMEZONE))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function storedRaw(array $row): array
    {
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $nested = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $nestedRaw = $nested['raw_data'] ?? [];
        if (is_string($nestedRaw)) {
            $nestedRaw = json_decode($nestedRaw, true);
        }
        if (!is_array($nestedRaw)) {
            $nestedRaw = [];
        }
        return array_replace($raw, $nestedRaw, $nested);
    }

    /** @param array<string, mixed> $row */
    private function storedEndpointId(array $row): string
    {
        return strtolower(trim((string)($this->storedRaw($row)['endpoint_id'] ?? '')));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function storedEndpointRow(array $rows, string $endpointId): array
    {
        foreach ($rows as $row) {
            if ($this->storedEndpointId($row) === $endpointId) {
                return $row;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $allowedPathSuffixes
     */
    private function storedFact(
        array $row,
        string $metricKey,
        array $allowedPathSuffixes = []
    ): int|float|null {
        if ($row === []) {
            return null;
        }
        $metricKey = strtolower(trim($metricKey));
        $allowedPathSuffixes = array_map(
            static fn(string $path): string => strtolower(trim($path)),
            $allowedPathSuffixes
        );
        foreach ((array)($this->storedRaw($row)['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['metric_key'] ?? ''))) !== $metricKey
            ) {
                continue;
            }
            $status = strtolower(trim((string)(
                $fact['fact_status']
                ?? $fact['status']
                ?? ''
            )));
            if (!in_array($status, ['captured', 'verified', 'available'], true)) {
                continue;
            }
            $path = strtolower(trim((string)($fact['source_path'] ?? '')));
            if ($allowedPathSuffixes !== []) {
                $pathAllowed = false;
                foreach ($allowedPathSuffixes as $suffix) {
                    if ($path === $suffix || str_ends_with($path, $suffix)) {
                        $pathAllowed = true;
                        break;
                    }
                }
                if (!$pathAllowed) {
                    continue;
                }
            }
            $value = $this->numeric($fact['value'] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array<string, int|float> $metrics
     */
    private function putMetric(array &$metrics, string $key, int|float|null $value): void
    {
        if ($value !== null) {
            $metrics[$key] = $value;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function storedOwnFlowRow(array $rows): array
    {
        foreach (['business_flow_transform', 'traffic_flow_transform'] as $endpointId) {
            foreach ($rows as $row) {
                if ($this->storedEndpointId($row) === $endpointId
                    && $this->storedRowIsOwnFlow($row)
                ) {
                    return $row;
                }
            }
        }
        return [];
    }

    /** @param array<string, mixed> $row */
    private function storedPastWindow(array $row): string
    {
        $raw = $this->storedRaw($row);
        $dimensions = is_array($raw['dimension_values'] ?? null)
            ? $raw['dimension_values']
            : [];
        $window = strtolower(trim((string)(
            $dimensions['analysis_window']
            ?? $raw['analysis_window']
            ?? ''
        )));
        return in_array($window, ['yesterday', 'last_7_days', 'last_30_days'], true)
            ? $window
            : '';
    }

    /** @param array<string, mixed> $row */
    private function storedRowIsOwnFlow(array $row): bool
    {
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        if (str_contains($dimension, ':1.')) {
            return false;
        }
        if (str_contains($dimension, ':0.')) {
            return true;
        }
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        if (in_array($compareType, ['competitor', 'peer', 'competitor_avg'], true)) {
            return false;
        }
        $raw = $this->storedRaw($row);
        $scope = strtolower(trim((string)(
            $raw['dimension_values']['compare_scope']
            ?? $raw['compare_scope']
            ?? ''
        )));
        if (in_array($scope, ['competitor', 'peer', 'competitor_avg'], true)) {
            return false;
        }
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $path = strtolower(trim((string)($fact['source_path'] ?? '')));
            if (str_starts_with($path, '1.')) {
                return false;
            }
            if (str_starts_with($path, '0.')) {
                return true;
            }
        }
        return $dimension === '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int|float>
     */
    private function aggregateStoredFlowMetrics(array $rows): array
    {
        $definitions = [
            'list_exposure' => ['list_exposure', ['0.listexposure', 'listexposure']],
            'detail_exposure' => ['detail_visitor', ['0.detailexposure', 'detailexposure']],
            'order_filling_num' => ['order_page_visitor', ['0.orderfillingnum', 'orderfillingnum']],
            'order_submit_num' => ['order_submit_user', ['0.ordersubmitnum', 'ordersubmitnum']],
        ];
        $metrics = [];
        foreach ($definitions as $outputKey => [$metricKey, $paths]) {
            $sum = 0.0;
            $complete = true;
            foreach ($rows as $row) {
                $value = $this->storedFact($row, $metricKey, $paths);
                if ($value === null) {
                    $complete = false;
                    break;
                }
                $sum += (float)$value;
            }
            if ($complete) {
                $metrics[$outputKey] = floor($sum) === $sum ? (int)$sum : $sum;
            }
        }
        return $metrics;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, int|float|string|null>>
     */
    private function storedVisitorTrend(array $rows): array
    {
        $points = [];
        foreach ($rows as $row) {
            if ($this->storedEndpointId($row) !== 'traffic_realtime_visitor_trend') {
                continue;
            }
            $raw = $this->storedRaw($row);
            $dimensions = is_array($raw['dimension_values'] ?? null)
                ? $raw['dimension_values']
                : [];
            $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
            $channel = strtolower(trim((string)(
                $dimensions['intraday_channel']
                ?? $metrics['intraday_channel']
                ?? ''
            )));
            $channelCode = trim((string)(
                $dimensions['intraday_channel_code']
                ?? $metrics['intraday_channel_code']
                ?? ''
            ));
            if ($channel !== 'app' && $channelCode !== '0') {
                continue;
            }
            $time = trim((string)(
                $dimensions['intraday_time_point']
                ?? $metrics['intraday_time_point']
                ?? $dimensions['time']
                ?? $metrics['time']
                ?? ''
            ));
            $timestamp = $this->normalizeStoredDateTime((string)(
                $dimensions['intraday_timestamp']
                ?? $metrics['intraday_timestamp']
                ?? ''
            ));
            if ($time === '' && $timestamp !== '') {
                $time = substr($timestamp, 11, 5);
            }
            $visitors = $this->numeric(
                $metrics['intraday_visitor_count']
                ?? null
            );
            if ($time === '' || $visitors === null) {
                continue;
            }
            $points[] = [
                'time' => $time,
                'timestamp' => $timestamp,
                'visitors' => $visitors,
                'last_week_visitors' => $this->numeric(
                    $metrics['intraday_last_week_visitor_count'] ?? null
                ),
            ];
        }
        return $points;
    }

    /** @return array<string, array{value:int|float|null,status:string,inputs:array<int,string>}> */
    private function futureMetrics(array $row, string $window = 'cumulative'): array
    {
        $raw = $this->metrics($row);
        $windowData = is_array($row[$window] ?? null) ? $row[$window] : [];
        $self = is_array($windowData['self'] ?? null) ? $windowData['self'] : [];
        $peer = is_array($windowData['competitor_avg'] ?? null)
            ? $windowData['competitor_avg']
            : [];
        $directSources = $window === 'cumulative' ? [$raw, $self] : [$self];
        $peerSources = $window === 'cumulative' ? [$raw, $peer] : [$peer];

        $values = [
            'future_search_pv' => $this->firstNumeric($directSources, ['future_search_pv', 'pv']),
            'future_search_uv' => $this->firstNumeric($directSources, ['future_search_uv', 'uv']),
            'future_search_order_count' => $this->firstNumeric(
                $directSources,
                ['future_search_order_count', 'order_count']
            ),
            'future_search_conversion_rate' => $this->firstNumeric(
                $directSources,
                ['future_search_conversion_rate', 'conversion_rate']
            ),
            'competitor_future_search_pv' => $this->firstNumeric(
                $peerSources,
                ['competitor_future_search_pv', 'pv']
            ),
            'competitor_future_search_uv' => $this->firstNumeric(
                $peerSources,
                ['competitor_future_search_uv', 'uv']
            ),
            'competitor_future_search_conversion_rate' => $this->firstNumeric(
                $peerSources,
                ['competitor_future_search_conversion_rate', 'conversion_rate']
            ),
        ];
        $metrics = [];
        foreach ($values as $key => $value) {
            $metrics[$key] = $this->metricValue($value, $value === null ? 'missing' : 'captured');
        }
        return $metrics;
    }

    /** @return array<string, mixed> */
    private function verifiedEnvelope(
        array $segment,
        int $hotelId,
        string $asOfDate,
        string $dateRelation,
        bool $requiresFinal = false
    ): array {
        if ($segment === []) {
            return [
                'valid' => false,
                'reason_codes' => ['segment_not_collected'],
                'captured_at' => '',
                'batch_id' => '',
            ];
        }

        $reasons = [];
        if ((int)($segment['system_hotel_id'] ?? 0) !== $hotelId) {
            $reasons[] = 'system_hotel_id_mismatch';
        }
        $platform = strtolower(trim((string)($segment['platform'] ?? $segment['source'] ?? '')));
        if ($platform !== 'ctrip') {
            $reasons[] = 'platform_not_ctrip';
        }
        if (!$this->boolean($segment['readback_verified'] ?? false)) {
            $reasons[] = 'readback_not_verified';
        }
        $dataDate = trim((string)($segment['data_date'] ?? ''));
        if (!$this->validDate($dataDate)) {
            $reasons[] = 'data_date_invalid';
        } elseif ($dateRelation === 'equal' && $dataDate !== $asOfDate) {
            $reasons[] = 'data_date_mismatch';
        } elseif ($dateRelation === 'before' && $dataDate >= $asOfDate) {
            $reasons[] = 'historical_date_not_before_as_of';
        }
        if ($requiresFinal && !$this->boolean($segment['is_final'] ?? false)) {
            $reasons[] = 'historical_snapshot_not_final';
        }
        $capturedAt = trim((string)($segment['captured_at'] ?? ''));
        if (!$this->validDateTime($capturedAt)) {
            $reasons[] = 'captured_at_invalid';
        }
        $batchId = trim((string)(
            $segment['batch_id']
            ?? $segment['sync_task_id']
            ?? $segment['source_trace_id']
            ?? ''
        ));
        if ($batchId === '' && $capturedAt !== '') {
            $batchId = 'captured_at:' . $capturedAt;
        }

        return [
            'valid' => $reasons === [],
            'reason_codes' => $reasons,
            'captured_at' => $capturedAt,
            'batch_id' => $batchId,
        ];
    }

    /** @return array<string, mixed> */
    private function blockedSegment(array $envelope): array
    {
        return [
            'status' => 'blocked',
            'quality_status' => 'blocked',
            'freshness_status' => 'unknown',
            'age_seconds' => null,
            'captured_at' => (string)($envelope['captured_at'] ?? ''),
            'batch_id' => (string)($envelope['batch_id'] ?? ''),
            'reason_codes' => array_values(array_unique(array_map(
                'strval',
                (array)($envelope['reason_codes'] ?? ['segment_not_collected'])
            ))),
            'metrics' => [],
            'gaps' => array_values(array_unique(array_map(
                'strval',
                (array)($envelope['reason_codes'] ?? ['segment_not_collected'])
            ))),
            'lines' => [],
            'fingerprint_facts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function intradayTrend(mixed $rawPoints): array
    {
        if (!is_array($rawPoints)) {
            return ['points' => [], 'summary' => '', 'gaps' => ['intraday_trend_invalid']];
        }
        $points = [];
        foreach ($rawPoints as $index => $point) {
            if (!is_array($point)) {
                continue;
            }
            $time = trim((string)($point['time'] ?? $point['hour'] ?? ''));
            $visitors = $this->numeric($point['visitors'] ?? $point['visitor_count'] ?? null);
            if ($time === '' || $visitors === null) {
                continue;
            }
            $points[] = [
                'time' => $time,
                'timestamp' => $this->normalizeStoredDateTime((string)(
                    $point['timestamp']
                    ?? $point['intraday_timestamp']
                    ?? ''
                )),
                'visitors' => $visitors,
                'last_week_visitors' => $this->numeric(
                    $point['last_week_visitors'] ?? $point['peer_visitors'] ?? null
                ),
                'source_index' => $index,
            ];
        }
        if ($points === []) {
            return ['points' => [], 'summary' => '', 'gaps' => []];
        }
        usort($points, static function (array $left, array $right): int {
            $leftTimestamp = (string)($left['timestamp'] ?? '');
            $rightTimestamp = (string)($right['timestamp'] ?? '');
            if ($leftTimestamp !== '' && $rightTimestamp !== '') {
                return strcmp($leftTimestamp, $rightTimestamp);
            }
            return strcmp((string)$left['time'], (string)$right['time']);
        });
        $peak = $points[0];
        foreach ($points as $point) {
            if ((float)$point['visitors'] > (float)$peak['visitors']) {
                $peak = $point;
            }
        }
        $latest = $points[count($points) - 1];
        return [
            'points' => $points,
            'summary' => '当日走势：峰值 ' . $peak['time'] . ' '
                . $this->integer($peak['visitors'])
                . '｜最新 ' . $latest['time'] . ' '
                . $this->integer($latest['visitors']),
            'gaps' => [],
        ];
    }

    /** @param array<string, mixed> $metrics */
    private function funnelLine(array $metrics): string
    {
        $labels = [
            'list_exposure' => '曝光',
            'detail_exposure' => '详情',
            'order_filling_num' => '填写',
            'order_submit_num' => '提交',
        ];
        $parts = [];
        foreach ($labels as $field => $label) {
            $value = $this->value($metrics, $field);
            if ($value !== null) {
                $parts[] = $label . ' ' . $this->integer($value);
            }
        }
        return count($parts) >= 2 ? '漏斗：' . implode(' → ', $parts) : '';
    }

    /** @param array<string, array<string, mixed>> $selected */
    private function judgment(array $selected): string
    {
        $present = $selected['present'] ?? [];
        $presentMetrics = is_array($present['metrics'] ?? null) ? $present['metrics'] : [];
        if (($present['status'] ?? '') !== 'blocked') {
            $price = $this->value($presentMetrics, 'starting_price');
            if ($price !== null && $price == 0.0) {
                return '携程疑似满房或无房可售，优先核对渠道房态。';
            }
            $visitors = $this->value($presentMetrics, 'realtime_visitors');
            $peer = $this->value($presentMetrics, 'competitor_avg_visitor');
            if ($visitors !== null && $peer !== null && $visitors < $peer) {
                return '当前携程访客低于竞争圈平均，优先检查展示与流量入口。';
            }
            $details = $this->value($presentMetrics, 'detail_exposure');
            $submits = $this->value($presentMetrics, 'order_submit_num');
            if ($details !== null && $details > 0 && $submits !== null && $submits == 0.0) {
                return '已有详情访问但尚无提交，优先检查预订转化环节。';
            }
        }

        $futureRows = (array)($selected['future']['rows'] ?? []);
        if ($futureRows !== []) {
            $top = $futureRows[0];
            $self = $this->futureDemandValue((array)($top['metrics'] ?? []));
            $peer = $this->futurePeerDemandValue((array)($top['metrics'] ?? []));
            if ($self > 0 && $peer > 0 && $self < $peer) {
                return '未来高热日期的搜索需求低于竞争圈，建议提前检查渠道曝光。';
            }
        }
        return '';
    }

    /**
     * @param array<string, array<string, mixed>> $selected
     * @param array<string, array<int, string>> $visibleSections
     */
    private function overallStatus(array $selected, array $visibleSections): string
    {
        if ($visibleSections === []) {
            return 'blocked';
        }
        $statuses = array_column($selected, 'status');
        if (in_array('blocked', $statuses, true) || in_array('partial', $statuses, true)) {
            return 'partial';
        }
        if (in_array('stale', $statuses, true)) {
            return 'stale';
        }
        return 'available';
    }

    /** @param array<string, array<string, mixed>> $selected */
    private function containsStaleSegment(array $selected): bool
    {
        foreach ($selected as $segment) {
            if (($segment['status'] ?? '') === 'stale') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, array<int, string>> $visibleSections
     */
    private function message(
        string $hotelName,
        string $capturedAt,
        array $visibleSections,
        string $judgment,
        bool $stale
    ): string {
        $lines = ['携程经营播报', $hotelName];
        if ($capturedAt !== '') {
            $lines[] = '采集时间 ' . $this->displayDateTime($capturedAt);
        }
        if ($stale) {
            $lines[] = '⚠ 数据已超过1小时未更新';
        }
        foreach (['past', 'present', 'future'] as $segmentName) {
            if (!isset($visibleSections[$segmentName])) {
                continue;
            }
            $lines[] = '';
            foreach ($visibleSections[$segmentName] as $line) {
                $lines[] = $line;
            }
        }
        if ($judgment !== '') {
            $lines[] = '';
            $lines[] = '经营提示：' . $judgment;
        }
        return trim(implode("\n", $lines));
    }

    /** @return array<string, mixed> */
    private function sendGate(
        string $content,
        string $fingerprint,
        string $previousFingerprint,
        bool $baselineOnly,
        string $overallStatus
    ): array {
        if ($content === '' || $overallStatus === 'blocked') {
            return [
                'status' => 'blocked',
                'should_send' => false,
                'reason_code' => 'no_sendable_segments',
            ];
        }
        if ($baselineOnly && $previousFingerprint === '') {
            return [
                'status' => 'baseline_only',
                'should_send' => false,
                'reason_code' => 'baseline_saved_without_alert',
            ];
        }
        if ($previousFingerprint !== '' && hash_equals($previousFingerprint, $fingerprint)) {
            return [
                'status' => 'duplicate',
                'should_send' => false,
                'reason_code' => 'snapshot_unchanged',
            ];
        }
        return [
            'status' => $overallStatus === 'available' ? 'ready' : 'ready_partial',
            'should_send' => true,
            'reason_code' => 'new_sendable_snapshot',
        ];
    }

    /** @return array<string, mixed> */
    private function cadence(string $mode): array
    {
        return match ($mode) {
            'realtime' => [
                'recommended' => 'hourly_on_new_snapshot',
                'deduplicate' => true,
            ],
            'review' => [
                'recommended' => 'once_after_yesterday_finalized',
                'deduplicate' => true,
            ],
            'future' => [
                'recommended' => 'daily_or_new_capture',
                'deduplicate' => true,
            ],
            default => [
                'recommended' => 'once_daily_after_history_finalized',
                'deduplicate' => true,
            ],
        };
    }

    /** @param array<string, array<string, mixed>> $selected */
    private function latestCapturedAt(array $selected): string
    {
        $values = [];
        foreach ($selected as $segment) {
            $value = trim((string)($segment['captured_at'] ?? ''));
            if ($this->validDateTime($value)) {
                $values[] = $value;
            }
        }
        if ($values === []) {
            return '';
        }
        rsort($values);
        return $values[0];
    }

    /** @return array<string, mixed> */
    private function segment(array $input, string $key): array
    {
        return is_array($input[$key] ?? null) ? $input[$key] : [];
    }

    /** @return array<string, mixed> */
    private function metrics(array $container): array
    {
        return is_array($container['metrics'] ?? null)
            ? $container['metrics']
            : $container;
    }

    /** @return array{value:int|float|null,status:string,inputs:array<int,string>} */
    private function metric(array $metrics, string $key): array
    {
        if (!array_key_exists($key, $metrics)
            || $metrics[$key] === null
            || $metrics[$key] === ''
            || !is_numeric($metrics[$key])
        ) {
            return $this->metricValue(null, 'missing');
        }
        return $this->metricValue($this->numeric($metrics[$key]), 'captured');
    }

    /** @return array{value:int|float|null,status:string,inputs:array<int,string>} */
    private function metricValue(int|float|null $value, string $status, array $inputs = []): array
    {
        return [
            'value' => $value,
            'status' => $status,
            'inputs' => $inputs,
        ];
    }

    /**
     * @param array{value:int|float|null,status:string,inputs:array<int,string>} $numerator
     * @param array{value:int|float|null,status:string,inputs:array<int,string>} $denominator
     * @return array{value:int|float|null,status:string,inputs:array<int,string>}
     */
    private function rateMetric(
        array $numerator,
        array $denominator,
        string $numeratorKey,
        string $denominatorKey
    ): array {
        if ($numerator['value'] === null
            || $denominator['value'] === null
            || (float)$denominator['value'] <= 0
        ) {
            return $this->metricValue(null, 'missing', [$numeratorKey, $denominatorKey]);
        }
        return $this->metricValue(
            round(((float)$numerator['value'] / (float)$denominator['value']) * 100, 2),
            'derived',
            [$numeratorKey, $denominatorKey]
        );
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function value(array $metrics, string $key): int|float|null
    {
        $value = $metrics[$key]['value'] ?? null;
        return is_int($value) || is_float($value) ? $value : null;
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function hasMetricValue(array $metrics): bool
    {
        foreach ($metrics as $metric) {
            if (is_array($metric) && ($metric['value'] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, array<string, mixed>> $sources @param array<int, string> $keys */
    private function firstNumeric(array $sources, array $keys): int|float|null
    {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }
                $value = $this->numeric($source[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function numeric(mixed $value): int|float|null
    {
        if (is_string($value)) {
            $value = str_replace([',', '%', ' '], '', trim($value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return floor($number) === $number ? (int)$number : $number;
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function futureDemandValue(array $metrics): float
    {
        return (float)($this->value($metrics, 'future_search_uv')
            ?? $this->value($metrics, 'future_search_pv')
            ?? 0);
    }

    /** @param array<string, array<string, mixed>> $metrics */
    private function futurePeerDemandValue(array $metrics): float
    {
        return (float)($this->value($metrics, 'competitor_future_search_uv')
            ?? $this->value($metrics, 'competitor_future_search_pv')
            ?? 0);
    }

    private function mode(string $mode): string
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($mode)));
        return match ($normalized) {
            'realtime', 'today', 'live', 'hourly' => 'realtime',
            'review', 'past', 'historical' => 'review',
            'future', 'demand', 'forecast' => 'future',
            'daily', 'all', 'combined', '' => 'daily',
            default => throw new InvalidArgumentException('ctrip_temporal_message_mode_invalid'),
        };
    }

    private function pastWindow(string $window): string
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($window)));
        return match ($normalized) {
            'yesterday', 'last_day', 'day_1' => 'yesterday',
            'last_7_days', '7_days', 'week' => 'last_7_days',
            'last_30_days', '30_days', 'month' => 'last_30_days',
            default => '',
        };
    }

    private function pastWindowLabel(string $window): string
    {
        return match ($window) {
            'yesterday' => '昨日',
            'last_7_days' => '近7日',
            'last_30_days' => '近30日',
            default => $window,
        };
    }

    private function displayDateTime(string $value): string
    {
        return $this->dateTime($value)->format('m-d H:i');
    }

    private function integer(int|float $value): string
    {
        return (string)(int)round((float)$value);
    }

    private function percent(int|float $value): string
    {
        $number = round((float)$value, 2);
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.') . '%';
    }

    private function date(string $value, string $error): string
    {
        $value = trim($value);
        if (!$this->validDate($value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function validDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            trim($value),
            new DateTimeZone(self::TIMEZONE)
        );
        return $parsed instanceof DateTimeImmutable
            && $parsed->format('Y-m-d') === trim($value);
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            trim($value),
            new DateTimeZone(self::TIMEZONE)
        );
        if (!$parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d H:i:s') !== trim($value)
        ) {
            throw new InvalidArgumentException('ctrip_temporal_captured_at_invalid');
        }
        return $parsed;
    }

    private function validDateTime(string $value): bool
    {
        try {
            $this->dateTime($value);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['true', 'yes', 'on', 'verified'], true);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonical($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new InvalidArgumentException('ctrip_temporal_fingerprint_encode_failed');
        }
        return $json;
    }
}
