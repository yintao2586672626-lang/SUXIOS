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
        $roomRevenue = $this->sumWithFallback($daily, 'room_revenue', 'revenue');
        $roomNights = $this->sum($daily, 'room_nights');
        $availableRows = $this->rowsWithPositive($daily, 'available_room_nights');
        $availableRoomNights = $this->sum($availableRows, 'available_room_nights');
        $revparRoomRevenue = $this->sumWithFallback($availableRows, 'room_revenue', 'revenue');
        $occupancyRows = array_values(array_filter($daily, function (array $row): bool {
            return $this->hasNumericValue($row, 'available_room_nights')
                && (float)$row['available_room_nights'] > 0
                && $this->hasNumericValue($row, 'occupied_room_nights');
        }));
        $occupiedRoomNights = $this->sum($occupancyRows, 'occupied_room_nights');
        $occupancyAvailableRoomNights = $this->sum($occupancyRows, 'available_room_nights');
        if (!$availableRows) {
            $dataGaps[] = [
                'code' => 'available_room_nights_missing',
                'message' => 'Available room night fields are missing, so OCC, RevPAR, and Net RevPAR are not calculable.',
            ];
        } else {
            if (count($availableRows) < count($daily)) {
                $dataGaps[] = [
                    'code' => 'available_room_nights_partial',
                    'message' => 'Available room night fields are present for only part of OTA daily facts, so RevPAR uses aligned rows only.',
                ];
            }
            if (!$occupancyRows) {
                $dataGaps[] = [
                    'code' => 'occupied_room_nights_missing',
                    'message' => 'Occupied room night fields are missing, so OCC is not calculable.',
                ];
            } elseif (count($occupancyRows) < count($availableRows)) {
                $dataGaps[] = [
                    'code' => 'occupied_room_nights_partial',
                    'message' => 'Occupied room night fields are present for only part of rows with available room nights, so OCC uses aligned rows only.',
                ];
            }
        }

        $commissionRows = $this->rowsWithNumeric($daily, 'commission_amount');
        $commissionAmount = $this->sum($commissionRows, 'commission_amount');
        $commissionGrossRevenue = $this->sumWithFallback($commissionRows, 'gross_revenue', 'revenue');
        if (!$commissionRows) {
            $dataGaps[] = [
                'code' => 'commission_fields_missing',
                'message' => 'Commission amount or commission rate fields are missing, so commission-after revenue is not calculable.',
            ];
        } elseif (count($commissionRows) < count($daily)) {
            $dataGaps[] = [
                'code' => 'commission_fields_partial',
                'message' => 'Commission fields are present for only part of OTA daily facts, so commission rate uses aligned rows only.',
            ];
        }

        $netRows = $this->rowsWithNumeric($daily, 'net_revenue');
        $netRevenue = $this->sum($netRows, 'net_revenue');
        if (!$netRows) {
            $dataGaps[] = [
                'code' => 'net_revenue_fields_missing',
                'message' => 'Net revenue fields are missing and cannot be derived without commission data.',
            ];
        } elseif (count($netRows) < count($daily)) {
            $dataGaps[] = [
                'code' => 'net_revenue_fields_partial',
                'message' => 'Net revenue fields are present for only part of OTA daily facts, so Net RevPAR and net contribution use available net rows only.',
            ];
        }
        $netRevparRows = array_values(array_filter($daily, function (array $row): bool {
            return $this->hasNumericValue($row, 'net_revenue')
                && $this->hasNumericValue($row, 'available_room_nights')
                && (float)$row['available_room_nights'] > 0;
        }));
        $netRevparNetRevenue = $this->sum($netRevparRows, 'net_revenue');
        $netRevparAvailableRoomNights = $this->sum($netRevparRows, 'available_room_nights');

        $leadTimeRows = $this->rowsWithNumeric($daily, 'lead_time_days');
        if (!$leadTimeRows) {
            $dataGaps[] = [
                'code' => 'lead_time_fields_missing',
                'message' => 'Booking date and check-in date fields are missing, so lead time is not calculable.',
            ];
        }

        $orderCount = (int)round($this->sum($daily, 'order_count'));
        $cancelRows = array_values(array_filter($daily, static fn(array $row): bool => array_key_exists('cancel_order_num', $row) && $row['cancel_order_num'] !== null));
        $directCancelRateRows = array_values(array_filter($daily, fn(array $row): bool => $this->hasNumericValue($row, 'cancel_rate')));
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
        } elseif ($directCancelRateRows) {
            $cancellationRate = $this->average($directCancelRateRows, 'cancel_rate');
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

        $cancelRoomNightRows = array_values(array_filter($daily, fn(array $row): bool => $this->hasNumericValue($row, 'cancel_room_nights') && $this->hasNumericValue($row, 'room_nights')));
        $roomNightCancellationRate = null;
        if ($cancelRoomNightRows) {
            $cancelledRoomNights = $this->sum($cancelRoomNightRows, 'cancel_room_nights');
            $cancelRoomNightBase = $this->sum($cancelRoomNightRows, 'room_nights');
            if ($cancelRoomNightBase > 0) {
                $roomNightCancellationRate = round($cancelledRoomNights / $cancelRoomNightBase * 100, 2);
            }
        }
        if (!$cancelRoomNightRows) {
            $dataGaps[] = [
                'code' => 'cancel_room_nights_missing',
                'message' => 'Cancel room night fields are missing, so room-night cancellation rate is not calculable.',
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
            $roomNights,
            $availableRows,
            $occupancyRows,
            $commissionRows,
            $netRows,
            $netRevparRows,
            $leadTimeRows,
            $cancelRows ?: $directCancelRateRows,
            $cancelRoomNightRows
        );

        return [
            'status' => $daily || $traffic || $comments ? 'ready' : 'empty',
            'generated_at' => date('Y-m-d H:i:s'),
            'fact_table' => [
                'name' => 'fact_ota_daily',
                'grain' => 'date_key + hotel_key + platform_key + data_type + dimension',
                'source_table' => 'online_daily_data',
            ],
            'metric_definitions' => $this->metricDefinitions(),
            'totals' => [
                'revenue' => round($revenue, 2),
                'gross_revenue' => round($revenue, 2),
                'room_revenue' => round($roomRevenue, 2),
                'net_revenue' => $netRows ? round($netRevenue, 2) : null,
                'commission_amount' => $commissionRows ? round($commissionAmount, 2) : null,
                'commission_rate' => $commissionRows && $commissionGrossRevenue > 0 ? round($commissionAmount / $commissionGrossRevenue * 100, 2) : null,
                'room_nights' => round($roomNights, 2),
                'available_room_nights' => $availableRows ? round($availableRoomNights, 2) : null,
                'occupied_room_nights' => $occupancyRows ? round($occupiedRoomNights, 2) : null,
                'order_count' => $orderCount,
                'adr' => $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
                'occ' => $occupancyRows && $occupancyAvailableRoomNights > 0 ? round($occupiedRoomNights / $occupancyAvailableRoomNights * 100, 2) : null,
                'revpar' => $availableRows && $availableRoomNights > 0 ? round($revparRoomRevenue / $availableRoomNights, 2) : null,
                'net_revpar' => $netRevparRows && $netRevparAvailableRoomNights > 0 ? round($netRevparNetRevenue / $netRevparAvailableRoomNights, 2) : null,
                'avg_lead_time_days' => $this->average($leadTimeRows, 'lead_time_days'),
                'cancellation_rate' => $cancellationRate,
                'room_night_cancellation_rate' => $roomNightCancellationRate,
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
                'avg_price_gap_rate' => $this->average($priceRows, 'price_gap_rate'),
            ],
            'channel_contribution' => $this->channelContribution($daily, $revenue, $netRevenue),
            'by_platform' => $this->groupDailyBy($daily, 'platform_key', $revenue, $netRevenue),
            'by_hotel' => $this->groupDailyBy($daily, 'hotel_key', $revenue, $netRevenue),
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
    private function sumWithFallback(array $rows, string $key, string $fallbackKey): float
    {
        return array_reduce($rows, function (float $carry, array $row) use ($key, $fallbackKey): float {
            if ($this->hasNumericValue($row, $key)) {
                return $carry + (float)$row[$key];
            }
            return $carry + (float)($row[$fallbackKey] ?? 0);
        }, 0.0);
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
    private function rowsWithNumeric(array $rows, string $key): array
    {
        return array_values(array_filter($rows, fn(array $row): bool => $this->hasNumericValue($row, $key)));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rowsWithPositive(array $rows, string $key): array
    {
        return array_values(array_filter($rows, fn(array $row): bool => $this->hasNumericValue($row, $key) && (float)$row[$key] > 0));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hasNumericValue(array $row, string $key): bool
    {
        return array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '' && is_numeric($row[$key]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function groupDailyBy(array $rows, string $key, float $totalRevenue = 0.0, float $totalNetRevenue = 0.0): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groupKey = (string)($row[$key] ?? '');
            if ($groupKey === '') {
                continue;
            }
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'revenue' => 0.0,
                    'room_revenue' => 0.0,
                    'net_revenue' => 0.0,
                    'commission_amount' => 0.0,
                    'room_nights' => 0.0,
                    'available_room_nights' => 0.0,
                    'occupied_room_nights' => 0.0,
                    'order_count' => 0,
                    'has_net_revenue' => false,
                    'has_commission_amount' => false,
                    'has_available_room_nights' => false,
                    'has_occupied_room_nights' => false,
                    'revpar_room_revenue' => 0.0,
                    'occupancy_available_room_nights' => 0.0,
                    'net_revpar_net_revenue' => 0.0,
                    'net_revpar_available_room_nights' => 0.0,
                ];
            }
            $groups[$groupKey]['revenue'] += (float)($row['revenue'] ?? 0);
            $groups[$groupKey]['room_revenue'] += (float)($row['room_revenue'] ?? $row['revenue'] ?? 0);
            $groups[$groupKey]['room_nights'] += (float)($row['room_nights'] ?? 0);
            $groups[$groupKey]['order_count'] += (int)($row['order_count'] ?? 0);
            if ($this->hasNumericValue($row, 'net_revenue')) {
                $groups[$groupKey]['has_net_revenue'] = true;
                $groups[$groupKey]['net_revenue'] += (float)$row['net_revenue'];
            }
            if ($this->hasNumericValue($row, 'commission_amount')) {
                $groups[$groupKey]['has_commission_amount'] = true;
                $groups[$groupKey]['commission_amount'] += (float)$row['commission_amount'];
            }
            if ($this->hasNumericValue($row, 'available_room_nights') && (float)$row['available_room_nights'] > 0) {
                $availableRoomNights = (float)$row['available_room_nights'];
                $groups[$groupKey]['has_available_room_nights'] = true;
                $groups[$groupKey]['available_room_nights'] += $availableRoomNights;
                $groups[$groupKey]['revpar_room_revenue'] += (float)($row['room_revenue'] ?? $row['revenue'] ?? 0);
                if ($this->hasNumericValue($row, 'net_revenue')) {
                    $groups[$groupKey]['net_revpar_net_revenue'] += (float)$row['net_revenue'];
                    $groups[$groupKey]['net_revpar_available_room_nights'] += $availableRoomNights;
                }
            }
            if (
                $this->hasNumericValue($row, 'occupied_room_nights')
                && $this->hasNumericValue($row, 'available_room_nights')
                && (float)$row['available_room_nights'] > 0
            ) {
                $groups[$groupKey]['has_occupied_room_nights'] = true;
                $groups[$groupKey]['occupied_room_nights'] += (float)$row['occupied_room_nights'];
                $groups[$groupKey]['occupancy_available_room_nights'] += (float)$row['available_room_nights'];
            }
        }

        foreach ($groups as &$group) {
            $group['revenue'] = round((float)$group['revenue'], 2);
            $group['room_revenue'] = round((float)$group['room_revenue'], 2);
            $group['net_revenue'] = $group['has_net_revenue'] ? round((float)$group['net_revenue'], 2) : null;
            $group['commission_amount'] = $group['has_commission_amount'] ? round((float)$group['commission_amount'], 2) : null;
            $group['room_nights'] = round((float)$group['room_nights'], 2);
            $group['available_room_nights'] = $group['has_available_room_nights'] ? round((float)$group['available_room_nights'], 2) : null;
            $group['occupied_room_nights'] = $group['has_occupied_room_nights'] ? round((float)$group['occupied_room_nights'], 2) : null;
            $group['adr'] = $group['room_nights'] > 0 ? round($group['room_revenue'] / $group['room_nights'], 2) : null;
            $group['occ'] = $group['occupancy_available_room_nights'] > 0 && $group['occupied_room_nights'] !== null
                ? round($group['occupied_room_nights'] / $group['occupancy_available_room_nights'] * 100, 2)
                : null;
            $group['revpar'] = $group['available_room_nights'] !== null && $group['available_room_nights'] > 0
                ? round($group['revpar_room_revenue'] / $group['available_room_nights'], 2)
                : null;
            $group['net_revpar'] = $group['net_revpar_available_room_nights'] > 0
                ? round($group['net_revpar_net_revenue'] / $group['net_revpar_available_room_nights'], 2)
                : null;
            $group['channel_contribution_rate'] = $totalRevenue > 0 ? round($group['revenue'] / $totalRevenue * 100, 2) : null;
            $group['revenue_contribution_rate'] = $group['channel_contribution_rate'];
            $group['net_revenue_contribution_rate'] = $totalNetRevenue > 0 && $group['net_revenue'] !== null
                ? round($group['net_revenue'] / $totalNetRevenue * 100, 2)
                : null;
            unset(
                $group['has_net_revenue'],
                $group['has_commission_amount'],
                $group['has_available_room_nights'],
                $group['has_occupied_room_nights'],
                $group['revpar_room_revenue'],
                $group['occupancy_available_room_nights'],
                $group['net_revpar_net_revenue'],
                $group['net_revpar_available_room_nights']
            );
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     * @return array<int, array<string, mixed>>
     */
    private function channelContribution(array $daily, float $totalRevenue, float $totalNetRevenue): array
    {
        return array_map(
            static fn(array $group): array => [
                'platform_key' => $group['key'],
                'revenue' => $group['revenue'],
                'net_revenue' => $group['net_revenue'],
                'room_nights' => $group['room_nights'],
                'order_count' => $group['order_count'],
                'contribution_rate' => $group['channel_contribution_rate'],
                'net_contribution_rate' => $group['net_revenue_contribution_rate'],
            ],
            $this->groupDailyBy($daily, 'platform_key', $totalRevenue, $totalNetRevenue)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     * @param array<int, array<string, mixed>> $traffic
     * @param array<int, array<string, mixed>> $comments
     * @param array<int, array<string, mixed>> $priceRows
     * @param array<int, array<string, string>> $dataGaps
     * @param array<int, array<string, mixed>> $availableRows
     * @param array<int, array<string, mixed>> $occupancyRows
     * @param array<int, array<string, mixed>> $commissionRows
     * @param array<int, array<string, mixed>> $netRows
     * @param array<int, array<string, mixed>> $netRevparRows
     * @param array<int, array<string, mixed>> $leadTimeRows
     * @param array<int, array<string, mixed>> $cancellationRows
     * @param array<int, array<string, mixed>> $cancelRoomNightRows
     * @return array<string, array<string, mixed>>
     */
    private function buildMetricTrust(
        array $daily,
        array $traffic,
        array $comments,
        array $priceRows,
        array $dataGaps,
        float $roomNights,
        array $availableRows,
        array $occupancyRows,
        array $commissionRows,
        array $netRows,
        array $netRevparRows,
        array $leadTimeRows,
        array $cancellationRows,
        array $cancelRoomNightRows
    ): array
    {
        $cancellationFailures = $this->dataGapCodesByPrefix($dataGaps, 'cancellation_');
        $cancelRoomNightFailures = $this->dataGapCodesByPrefix($dataGaps, 'cancel_room_');
        $availabilityFailures = array_merge(
            $this->dataGapCodesByPrefix($dataGaps, 'available_'),
            $this->dataGapCodesByPrefix($dataGaps, 'occupied_')
        );
        $commissionFailures = $this->dataGapCodesByPrefix($dataGaps, 'commission_');
        $netRevenueFailures = $this->dataGapCodesByPrefix($dataGaps, 'net_');
        $leadTimeFailures = $this->dataGapCodesByPrefix($dataGaps, 'lead_time_');
        $priceFailures = $this->dataGapCodesByPrefix($dataGaps, 'competitor_price_');
        $trust = [
            'totals.revenue' => $this->trust($daily, 'sum(fact_ota_daily.revenue)'),
            'totals.gross_revenue' => $this->trust($daily, 'sum(fact_ota_daily.gross_revenue)'),
            'totals.room_revenue' => $this->trust($daily, 'sum(fact_ota_daily.room_revenue)'),
            'totals.net_revenue' => $this->trust($netRows, 'sum(fact_ota_daily.net_revenue)', $netRevenueFailures),
            'totals.commission_amount' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount)', $commissionFailures),
            'totals.commission_rate' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount) / sum(fact_ota_daily.gross_revenue)', $commissionFailures),
            'totals.room_nights' => $this->trust($daily, 'sum(fact_ota_daily.room_nights)'),
            'totals.available_room_nights' => $this->trust($availableRows, 'sum(fact_ota_daily.available_room_nights)', $availabilityFailures),
            'totals.occupied_room_nights' => $this->trust($occupancyRows, 'sum(fact_ota_daily.occupied_room_nights)', $availabilityFailures),
            'totals.order_count' => $this->trust($daily, 'sum(fact_ota_daily.order_count)'),
            'totals.adr' => $this->trust(
                $daily,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
                $roomNights > 0 ? [] : ['adr_denominator_zero']
            ),
            'totals.occ' => $this->trust(
                $occupancyRows,
                'sum(fact_ota_daily.occupied_room_nights) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            'totals.revpar' => $this->trust(
                $availableRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            'totals.net_revpar' => $this->trust(
                $netRevparRows,
                'sum(fact_ota_daily.net_revenue) / sum(fact_ota_daily.available_room_nights)',
                array_merge($availabilityFailures, $netRevenueFailures)
            ),
            'totals.avg_lead_time_days' => $this->trust($leadTimeRows, 'avg(fact_ota_daily.lead_time_days)', $leadTimeFailures),
            'totals.cancellation_rate' => $this->trust(
                $cancellationRows,
                'sum(fact_ota_daily.cancel_order_num) / sum(fact_ota_daily.order_count), or avg(fact_ota_daily.cancel_rate) when platform rate is supplied',
                $cancellationFailures
            ),
            'totals.room_night_cancellation_rate' => $this->trust(
                $cancelRoomNightRows,
                'sum(fact_ota_daily.cancel_room_nights) / sum(fact_ota_daily.room_nights)',
                $cancelRoomNightFailures
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
            'competitor_price.avg_price_gap_rate' => $this->trust($priceRows, 'avg(fact_ota_daily.price_gap_rate)', $priceFailures),
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
        $availableRows = $this->rowsWithPositive($rows, 'available_room_nights');
        $occupancyRows = array_values(array_filter($rows, function (array $row): bool {
            return $this->hasNumericValue($row, 'available_room_nights')
                && (float)$row['available_room_nights'] > 0
                && $this->hasNumericValue($row, 'occupied_room_nights');
        }));
        $commissionRows = $this->rowsWithNumeric($rows, 'commission_amount');
        $netRows = $this->rowsWithNumeric($rows, 'net_revenue');
        $netRevparRows = array_values(array_filter($rows, function (array $row): bool {
            return $this->hasNumericValue($row, 'net_revenue')
                && $this->hasNumericValue($row, 'available_room_nights')
                && (float)$row['available_room_nights'] > 0;
        }));
        $availabilityFailures = $availableRows ? [] : ['available_room_nights_missing'];
        if ($availableRows && count($availableRows) < count($rows)) {
            $availabilityFailures[] = 'available_room_nights_partial';
        }
        if ($availableRows && !$occupancyRows) {
            $availabilityFailures[] = 'occupied_room_nights_missing';
        } elseif ($availableRows && count($occupancyRows) < count($availableRows)) {
            $availabilityFailures[] = 'occupied_room_nights_partial';
        }
        $netRevenueFailures = [];
        if (!$netRows) {
            $netRevenueFailures[] = 'net_revenue_fields_missing';
        } elseif (count($netRows) < count($rows)) {
            $netRevenueFailures[] = 'net_revenue_fields_partial';
        }

        return [
            $prefix . '.revenue' => $this->trust($rows, 'sum(fact_ota_daily.revenue)'),
            $prefix . '.room_revenue' => $this->trust($rows, 'sum(fact_ota_daily.room_revenue)'),
            $prefix . '.net_revenue' => $this->trust($netRows, 'sum(fact_ota_daily.net_revenue)', $netRevenueFailures),
            $prefix . '.commission_amount' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount)', $commissionRows ? [] : ['commission_fields_missing']),
            $prefix . '.room_nights' => $this->trust($rows, 'sum(fact_ota_daily.room_nights)'),
            $prefix . '.available_room_nights' => $this->trust($availableRows, 'sum(fact_ota_daily.available_room_nights)', $availabilityFailures),
            $prefix . '.occupied_room_nights' => $this->trust($occupancyRows, 'sum(fact_ota_daily.occupied_room_nights)', $availabilityFailures),
            $prefix . '.order_count' => $this->trust($rows, 'sum(fact_ota_daily.order_count)'),
            $prefix . '.adr' => $this->trust(
                $rows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
                $this->sum($rows, 'room_nights') > 0 ? [] : ['adr_denominator_zero']
            ),
            $prefix . '.occ' => $this->trust(
                $occupancyRows,
                'sum(fact_ota_daily.occupied_room_nights) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            $prefix . '.revpar' => $this->trust(
                $availableRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            $prefix . '.net_revpar' => $this->trust(
                $netRevparRows,
                'sum(fact_ota_daily.net_revenue) / sum(fact_ota_daily.available_room_nights)',
                array_merge($availabilityFailures, $netRevenueFailures)
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metricDefinitions(): array
    {
        return [
            'grain' => 'One fact row per date, hotel, OTA platform, data_type, and dimension.',
            'metrics' => [
                'adr' => [
                    'formula' => 'sum(room_revenue) / sum(room_nights)',
                    'not_calculable_when' => 'room_nights is missing or zero',
                ],
                'occ' => [
                    'formula' => 'sum(occupied_room_nights) / sum(available_room_nights) * 100',
                    'not_calculable_when' => 'available_room_nights is missing or zero',
                ],
                'revpar' => [
                    'formula' => 'sum(room_revenue for rows with available_room_nights) / sum(available_room_nights)',
                    'not_calculable_when' => 'available_room_nights is missing or zero; partial rows are reported in data_gaps',
                ],
                'net_revpar' => [
                    'formula' => 'sum(net_revenue for rows with net_revenue and available_room_nights) / sum(available_room_nights for those rows)',
                    'not_calculable_when' => 'net_revenue or available_room_nights is missing; partial rows are reported in data_gaps',
                ],
                'channel_contribution' => [
                    'formula' => 'channel_revenue / total_revenue * 100',
                    'not_calculable_when' => 'total_revenue is missing or zero',
                ],
                'net_channel_contribution' => [
                    'formula' => 'channel_net_revenue / total_net_revenue * 100',
                    'not_calculable_when' => 'channel net revenue or total net revenue is missing',
                ],
                'commission_after_revenue' => [
                    'formula' => 'gross_revenue - commission_amount; commission_amount can be gross_revenue * commission_rate when the rate is supplied',
                    'not_calculable_when' => 'commission_amount and commission_rate are both missing',
                ],
                'commission_rate' => [
                    'formula' => 'sum(commission_amount) / sum(gross_revenue for rows with commission_amount) * 100',
                    'not_calculable_when' => 'commission_amount is missing or aligned gross_revenue is zero',
                ],
                'lead_time_days' => [
                    'formula' => 'checkin_date - booking_date',
                    'not_calculable_when' => 'booking_date or checkin_date is missing',
                ],
                'cancellation_rate' => [
                    'formula' => 'cancel_order_num / order_count * 100; uses platform cancel_rate only when supplied directly',
                    'not_calculable_when' => 'cancel_order_num/cancel_rate is missing, or order_count is zero',
                ],
                'room_night_cancellation_rate' => [
                    'formula' => 'cancel_room_nights / room_nights * 100',
                    'not_calculable_when' => 'cancel_room_nights is missing, or room_nights is zero',
                ],
                'competitor_price_gap' => [
                    'formula' => 'our_price - competitor_price',
                    'not_calculable_when' => 'our_price or competitor_price is missing',
                ],
                'competitor_price_gap_rate' => [
                    'formula' => 'price_gap / competitor_price * 100',
                    'not_calculable_when' => 'price_gap is missing, or competitor_price is zero',
                ],
            ],
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
