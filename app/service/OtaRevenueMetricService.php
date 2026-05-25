<?php
declare(strict_types=1);

namespace app\service;

class OtaRevenueMetricService
{
    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function summarizeDataset(array $dataset): array
    {
        $daily = $this->list($dataset['fact_ota_daily'] ?? []);
        $traffic = $this->list($dataset['fact_ota_traffic'] ?? []);
        $comments = $this->list($dataset['fact_ota_comment'] ?? []);
        $dataGaps = [];

        $revenue = $this->sum($daily, 'revenue');
        $roomNights = $this->sum($daily, 'room_nights');
        $orderCount = (int)round($this->sum($daily, 'order_count'));
        $cancelRows = array_values(array_filter($daily, static fn(array $row): bool => array_key_exists('cancel_order_num', $row) && $row['cancel_order_num'] !== null));
        $cancelOrders = $this->sum($cancelRows, 'cancel_order_num');
        $cancelOrderBase = (int)round($this->sum($cancelRows, 'order_count'));
        $cancellationRate = null;
        if ($cancelRows && $cancelOrderBase > 0) {
            $cancellationRate = round($cancelOrders / $cancelOrderBase * 100, 2);
            if (count($cancelRows) < count($daily)) {
                $dataGaps[] = [
                    'code' => 'cancellation_fields_partial',
                    'message' => 'Cancellation fields are present for only part of OTA daily facts.',
                ];
            }
        } elseif (!$cancelRows) {
            $dataGaps[] = [
                'code' => 'cancellation_fields_missing',
                'message' => 'Cancellation fields are not present in OTA daily facts.',
            ];
        } else {
            $dataGaps[] = [
                'code' => 'cancellation_order_base_missing',
                'message' => 'Cancellation fields are present, but matching order counts are zero or missing.',
            ];
        }

        $priceRows = array_values(array_filter($daily, static fn(array $row): bool => ($row['our_price'] ?? null) !== null && ($row['competitor_price'] ?? null) !== null));
        if (!$priceRows) {
            $dataGaps[] = [
                'code' => 'competitor_price_fields_missing',
                'message' => 'Competitor price fields are not present in OTA daily facts.',
            ];
        }

        $metricTrust = $this->buildMetricTrust(
            $daily,
            $traffic,
            $comments,
            $priceRows,
            $dataGaps,
            $roomNights
        );

        return [
            'status' => $daily || $traffic || $comments ? 'ready' : 'empty',
            'generated_at' => date('Y-m-d H:i:s'),
            'totals' => [
                'revenue' => round($revenue, 2),
                'room_nights' => round($roomNights, 2),
                'order_count' => $orderCount,
                'adr' => $roomNights > 0 ? round($revenue / $roomNights, 2) : null,
                'cancellation_rate' => $cancellationRate,
                'review_count' => count($comments),
                'avg_comment_score' => $this->average($comments, 'score'),
            ],
            'traffic' => [
                'rows' => count($traffic),
                'avg_flow_rate' => $this->average($traffic, 'flow_rate'),
                'avg_submit_rate' => $this->average($traffic, 'submit_rate'),
                'list_exposure' => (int)round($this->sum($traffic, 'list_exposure')),
                'detail_exposure' => (int)round($this->sum($traffic, 'detail_exposure')),
            ],
            'competitor_price' => [
                'rows' => count($priceRows),
                'avg_our_price' => $this->average($priceRows, 'our_price'),
                'avg_competitor_price' => $this->average($priceRows, 'competitor_price'),
                'avg_price_gap' => $this->average($priceRows, 'price_gap'),
            ],
            'by_platform' => $this->groupDailyBy($daily, 'platform_key'),
            'by_hotel' => $this->groupDailyBy($daily, 'hotel_key'),
            'data_gaps' => $dataGaps,
            'etl_quality' => $dataset['data_quality'] ?? [],
            'metric_trust' => $metricTrust,
        ];
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function sum(array $rows, string $key): float
    {
        return array_reduce($rows, static fn(float $carry, array $row): float => $carry + (float)($row[$key] ?? 0), 0.0);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function average(array $rows, string $key): ?float
    {
        $values = [];
        foreach ($rows as $row) {
            if (array_key_exists($key, $row) && $row[$key] !== null && is_numeric($row[$key])) {
                $values[] = (float)$row[$key];
            }
        }
        return $values ? round(array_sum($values) / count($values), 2) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupDailyBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groupKey = (string)($row[$key] ?? '');
            if ($groupKey === '') {
                continue;
            }
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = ['key' => $groupKey, 'revenue' => 0.0, 'room_nights' => 0.0, 'order_count' => 0];
            }
            $groups[$groupKey]['revenue'] += (float)($row['revenue'] ?? 0);
            $groups[$groupKey]['room_nights'] += (float)($row['room_nights'] ?? 0);
            $groups[$groupKey]['order_count'] += (int)($row['order_count'] ?? 0);
        }

        foreach ($groups as &$group) {
            $group['revenue'] = round((float)$group['revenue'], 2);
            $group['room_nights'] = round((float)$group['room_nights'], 2);
            $group['adr'] = $group['room_nights'] > 0 ? round($group['revenue'] / $group['room_nights'], 2) : null;
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     * @param array<int, array<string, mixed>> $traffic
     * @param array<int, array<string, mixed>> $comments
     * @param array<int, array<string, mixed>> $priceRows
     * @param array<int, array<string, string>> $dataGaps
     * @return array<string, array<string, mixed>>
     */
    private function buildMetricTrust(array $daily, array $traffic, array $comments, array $priceRows, array $dataGaps, float $roomNights): array
    {
        $cancellationFailures = $this->dataGapCodesByPrefix($dataGaps, 'cancellation_');
        $priceFailures = $this->dataGapCodesByPrefix($dataGaps, 'competitor_price_');
        $trust = [
            'totals.revenue' => $this->trust($daily, 'sum(fact_ota_daily.revenue)'),
            'totals.room_nights' => $this->trust($daily, 'sum(fact_ota_daily.room_nights)'),
            'totals.order_count' => $this->trust($daily, 'sum(fact_ota_daily.order_count)'),
            'totals.adr' => $this->trust(
                $daily,
                'sum(fact_ota_daily.revenue) / sum(fact_ota_daily.room_nights)',
                $roomNights > 0 ? [] : ['adr_denominator_zero']
            ),
            'totals.cancellation_rate' => $this->trust(
                $daily,
                'sum(fact_ota_daily.cancel_order_num) / sum(fact_ota_daily.order_count)',
                $cancellationFailures
            ),
            'totals.review_count' => $this->trust($comments, 'count(fact_ota_comment)'),
            'totals.avg_comment_score' => $this->trust($comments, 'avg(fact_ota_comment.score)'),
            'traffic.rows' => $this->trust($traffic, 'count(fact_ota_traffic)'),
            'traffic.avg_flow_rate' => $this->trust($traffic, 'avg(fact_ota_traffic.flow_rate)'),
            'traffic.avg_submit_rate' => $this->trust($traffic, 'avg(fact_ota_traffic.submit_rate)'),
            'traffic.list_exposure' => $this->trust($traffic, 'sum(fact_ota_traffic.list_exposure)'),
            'traffic.detail_exposure' => $this->trust($traffic, 'sum(fact_ota_traffic.detail_exposure)'),
            'competitor_price.rows' => $this->trust($priceRows, 'count(fact_ota_daily rows with our_price and competitor_price)', $priceFailures),
            'competitor_price.avg_our_price' => $this->trust($priceRows, 'avg(fact_ota_daily.our_price)', $priceFailures),
            'competitor_price.avg_competitor_price' => $this->trust($priceRows, 'avg(fact_ota_daily.competitor_price)', $priceFailures),
            'competitor_price.avg_price_gap' => $this->trust($priceRows, 'avg(fact_ota_daily.price_gap)', $priceFailures),
        ];

        foreach ($this->groupRowsBy($daily, 'platform_key') as $key => $rows) {
            foreach ($this->groupMetricTrust($rows, 'by_platform.' . $key) as $metricKey => $entry) {
                $trust[$metricKey] = $entry;
            }
        }

        foreach ($this->groupRowsBy($daily, 'hotel_key') as $key => $rows) {
            foreach ($this->groupMetricTrust($rows, 'by_hotel.' . $key) as $metricKey => $entry) {
                $trust[$metricKey] = $entry;
            }
        }

        return $trust;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function groupMetricTrust(array $rows, string $prefix): array
    {
        return [
            $prefix . '.revenue' => $this->trust($rows, 'sum(fact_ota_daily.revenue)'),
            $prefix . '.room_nights' => $this->trust($rows, 'sum(fact_ota_daily.room_nights)'),
            $prefix . '.order_count' => $this->trust($rows, 'sum(fact_ota_daily.order_count)'),
            $prefix . '.adr' => $this->trust(
                $rows,
                'sum(fact_ota_daily.revenue) / sum(fact_ota_daily.room_nights)',
                $this->sum($rows, 'room_nights') > 0 ? [] : ['adr_denominator_zero']
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $failureReasons
     * @return array<string, mixed>
     */
    private function trust(array $rows, string $caliber, array $failureReasons = []): array
    {
        $traces = $this->sourceTraces($rows);
        $updatedAt = $this->latestUpdatedAt($traces);
        if (!$traces) {
            $failureReasons[] = 'source_rows_missing';
        }
        if ($updatedAt === null) {
            $failureReasons[] = 'source_update_time_missing';
        }

        $allSaved = $traces !== [];
        foreach ($traces as $trace) {
            if (($trace['saved_success'] ?? false) !== true) {
                $allSaved = false;
                foreach ((array)($trace['failure_reasons'] ?? []) as $reason) {
                    $reasonText = trim((string)$reason);
                    if ($reasonText !== '') {
                        $failureReasons[] = $reasonText;
                    }
                }
                if (empty($trace['failure_reasons'])) {
                    $failureReasons[] = 'source_row_save_failed';
                }
            }
        }

        $failureReasons = array_values(array_unique(array_filter(
            $failureReasons,
            static fn(string $reason): bool => $reason !== ''
        )));

        return [
            'source' => $this->sourceSummary($traces),
            'caliber' => $caliber,
            'updated_at' => $updatedAt,
            'failure_reasons' => $failureReasons,
            'saved_success' => $allSaved && empty($failureReasons),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sourceTraces(array $rows): array
    {
        $traces = [];
        foreach ($rows as $row) {
            $trace = $row['source_trace'] ?? null;
            if (is_array($trace)) {
                $traces[] = $trace;
            }
        }
        return $traces;
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @return array<string, mixed>
     */
    private function sourceSummary(array $traces): array
    {
        $dates = $this->uniqueTraceValues($traces, 'date_key');
        sort($dates);

        return [
            'table' => 'online_daily_data',
            'row_ids' => $this->uniqueTraceValues($traces, 'row_id'),
            'platforms' => $this->uniqueTraceValues($traces, 'platform'),
            'data_types' => $this->uniqueTraceValues($traces, 'data_type'),
            'date_range' => [
                'start' => $dates[0] ?? null,
                'end' => $dates ? $dates[count($dates) - 1] : null,
            ],
            'row_count' => count($traces),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @return array<int, mixed>
     */
    private function uniqueTraceValues(array $traces, string $key): array
    {
        $values = [];
        foreach ($traces as $trace) {
            if (!array_key_exists($key, $trace) || $trace[$key] === null || $trace[$key] === '') {
                continue;
            }
            $values[] = $trace[$key];
        }
        return array_values(array_unique($values, SORT_REGULAR));
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     */
    private function latestUpdatedAt(array $traces): ?string
    {
        $times = [];
        foreach ($traces as $trace) {
            $updatedAt = trim((string)($trace['updated_at'] ?? ''));
            if ($updatedAt !== '') {
                $times[] = $updatedAt;
            }
        }
        if (!$times) {
            return null;
        }
        rsort($times);
        return $times[0];
    }

    /**
     * @param array<int, array<string, string>> $dataGaps
     * @return array<int, string>
     */
    private function dataGapCodesByPrefix(array $dataGaps, string $prefix): array
    {
        $codes = [];
        foreach ($dataGaps as $gap) {
            $code = (string)($gap['code'] ?? '');
            if ($code !== '' && str_starts_with($code, $prefix)) {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupRowsBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groupKey = (string)($row[$key] ?? '');
            if ($groupKey === '') {
                continue;
            }
            $groups[$groupKey][] = $row;
        }
        return $groups;
    }
}
