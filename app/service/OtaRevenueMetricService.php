<?php
declare(strict_types=1);

namespace app\service;

class OtaRevenueMetricService
{
    private const CHANNEL_BOOKING_WINDOW_MIN_ORDERS = 10;

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    public function summarizeDataset(array $dataset): array
    {
        $daily = $this->list($dataset['fact_ota_daily'] ?? []);
        $traffic = $this->list($dataset['fact_ota_traffic'] ?? []);
        $advertising = $this->list($dataset['fact_ota_advertising'] ?? []);
        $quality = $this->list($dataset['fact_ota_quality'] ?? []);
        $searchKeywords = $this->list($dataset['fact_ota_search_keyword'] ?? []);
        $peerRanks = $this->list($dataset['fact_ota_peer_rank'] ?? []);
        $trafficAnalysis = $this->list($dataset['fact_ota_traffic_analysis'] ?? []);
        $trafficForecast = $this->list($dataset['fact_ota_traffic_forecast'] ?? []);
        $comments = $this->list($dataset['fact_ota_comment'] ?? []);
        $dataGaps = [];
        $trafficFlowRows = $this->canonicalTrafficMetricRows(
            $traffic,
            'flow_rate'
        );
        $trafficSubmitRows = $this->canonicalTrafficMetricRows(
            $traffic,
            'submit_rate'
        );
        $trafficListExposureRows = $this->canonicalTrafficMetricRows(
            $traffic,
            'list_exposure'
        );
        $trafficDetailExposureRows = $this->canonicalTrafficMetricRows(
            $traffic,
            'detail_exposure'
        );
        $trafficProjectionOverlapGroups =
            $this->trafficProjectionOverlapGroupCount($traffic);

        $revenueRows = $this->rowsWithNumeric($daily, 'revenue');
        $grossRevenueRows = $this->rowsWithNumeric($daily, 'gross_revenue');
        $roomRevenueRows = $this->rowsWithNumeric($daily, 'room_revenue');
        $genericRevenueWithoutRoomRevenueRows = array_values(array_filter(
            $daily,
            fn(array $row): bool => $this->hasNumericValue($row, 'revenue')
                && !$this->hasNumericValue($row, 'room_revenue')
        ));
        $revenue = $this->sum($revenueRows, 'revenue');
        $roomRevenue = $this->sum($roomRevenueRows, 'room_revenue');
        $roomNightRows = $this->rowsWithNumeric($daily, 'room_nights');
        $roomNights = $this->sum($roomNightRows, 'room_nights');
        if (!$roomNightRows) {
            $dataGaps[] = [
                'code' => 'room_nights_missing',
                'message' => 'Verified room nights are missing. Order counts, physical room counts, and defaults cannot replace room nights.',
            ];
        }
        $availableRows = $this->rowsWithPositive($daily, 'available_room_nights');
        $availableRoomNights = $this->sum($availableRows, 'available_room_nights');
        $revparRows = array_values(array_filter(
            $availableRows,
            fn(array $row): bool => $this->hasNumericValue($row, 'room_revenue')
        ));
        $revparRoomRevenue = $this->sum($revparRows, 'room_revenue');
        $revparAvailableRoomNights = $this->sum($revparRows, 'available_room_nights');
        if (!$roomRevenueRows) {
            $dataGaps[] = [
                'code' => 'room_revenue_missing',
                'message' => 'Verified room revenue is missing. Order GMV, paid amount, settlement amount, and generic revenue cannot replace room revenue.',
            ];
        } elseif ($genericRevenueWithoutRoomRevenueRows !== []) {
            $dataGaps[] = [
                'code' => 'room_revenue_partial',
                'message' => 'Some revenue-bearing OTA facts lack verified room revenue, so ADR and RevPAR exclude those unverified numerator rows.',
            ];
        }
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
        $bookingWindowAdrRows = array_values(array_filter($leadTimeRows, function (array $row): bool {
            return (float)$row['lead_time_days'] >= 0
                && $this->hasNumericValue($row, 'room_revenue')
                && $this->hasNumericValue($row, 'room_nights')
                && (float)$row['room_nights'] > 0;
        }));
        if ($leadTimeRows && !$bookingWindowAdrRows) {
            $dataGaps[] = [
                'code' => 'booking_window_adr_fields_missing',
                'message' => 'Lead time exists, but aligned verified room revenue and positive room nights are missing, so booking-window ADR is not calculable.',
            ];
        } elseif (count($bookingWindowAdrRows) < count($leadTimeRows)) {
            $dataGaps[] = [
                'code' => 'booking_window_adr_fields_partial',
                'message' => 'Only part of the lead-time facts have aligned verified room revenue and positive room nights, so booking-window ADR uses aligned rows only.',
            ];
        }
        $bookingWindowAdr = $this->bookingWindowAdrSummary($bookingWindowAdrRows, count($leadTimeRows));

        $channelBookingWindowMonthRows = array_values(array_filter($leadTimeRows, function (array $row): bool {
            return (float)$row['lead_time_days'] >= 0
                && trim((string)($row['platform_key'] ?? '')) !== ''
                && $this->hasNumericValue($row, 'order_count')
                && $this->orderCountSemanticAllowed($row)
                && (float)$row['order_count'] > 0
                && $this->stayMonth((string)($row['checkin_date'] ?? '')) !== '';
        }));
        $channelBookingWindowMonth = $this->channelBookingWindowMonthSummary(
            $channelBookingWindowMonthRows,
            count($leadTimeRows)
        );

        $orderCountRows = $this->verifiedOrderCountRows($daily);
        $orderCount = $orderCountRows ? (int)round($this->sum($orderCountRows, 'order_count')) : null;
        if (!$orderCountRows) {
            $dataGaps[] = [
                'code' => 'order_count_missing',
                'message' => 'Verified order count is missing. Ambiguous booking, capacity, or room-night counts cannot replace orders.',
            ];
        }
        $reviewCountRows = $this->rowsWithNumeric($comments, 'comment_count');
        $reviewCount = $reviewCountRows ? $this->sum($reviewCountRows, 'comment_count') : null;
        $cancellationScopeRows = array_values(array_filter(
            $daily,
            fn(array $row): bool => $this->orderCountSemanticAllowed($row)
                && (
                    $this->hasNumericValue($row, 'order_count')
                    || $this->hasNumericValue($row, 'gross_order_count')
                    || $this->hasNumericValue($row, 'cancel_order_num')
                    || $this->hasNumericValue($row, 'cancel_rate')
                    || $this->hasNumericValue(
                        $row,
                        'unknown_status_order_count'
                    )
                )
        ));
        $cancelRows = array_values(array_filter(
            $cancellationScopeRows,
            fn(array $row): bool => $this->hasNumericValue(
                $row,
                'cancel_order_num'
            )
        ));
        $directCancelRateRows = array_values(array_filter(
            $cancellationScopeRows,
            fn(array $row): bool => !$this->hasNumericValue(
                $row,
                'cancel_order_num'
            ) && $this->hasNumericValue($row, 'cancel_rate')
        ));
        $completeCancellationRows = array_values(array_filter(
            $cancelRows,
            fn(array $row): bool => $this->hasNumericValue(
                $row,
                'gross_order_count'
            )
                && (float)$row['gross_order_count'] >= 0
                && $this->hasNumericValue(
                    $row,
                    'unknown_status_order_count'
                )
                && (float)$row['unknown_status_order_count'] === 0.0
                && (float)$row['cancel_order_num'] >= 0
                && (float)$row['cancel_order_num']
                    <= (float)$row['gross_order_count']
                && (string)($row['cancel_rate_basis'] ?? '')
                    === 'cancelled_orders_over_gross_orders_complete_classification'
        ));
        $summaryCancellationScopeKeys = $this->cancellationSummaryScopeKeys(
            $daily
        );
        $cancelOrders = null;
        $cancelOrderBase = null;
        $cancellationRateBasis = null;
        $cancellationRate = null;
        $cancellationEvidenceRows = [];
        $grossOrderEvidenceRows = [];
        $cancelOrderEvidenceRows = [];
        if ($cancelRows && $directCancelRateRows) {
            $cancellationEvidenceRows = array_merge(
                $cancelRows,
                $directCancelRateRows
            );
            $dataGaps[] = [
                'code' => 'cancellation_evidence_mixed',
                'message' => 'Count-based and direct-rate cancellation evidence coexist in the same summary scope and cannot be silently merged.',
            ];
        } elseif ($cancelRows) {
            $coverageComplete = count($cancelRows)
                === count($cancellationScopeRows)
                && $this->cancellationSummaryScopeKeys($cancelRows)
                    === $summaryCancellationScopeKeys;
            $classificationComplete = count($completeCancellationRows)
                === count($cancelRows);
            if (!$coverageComplete) {
                $dataGaps[] = [
                    'code' => 'cancellation_fields_partial',
                    'message' => 'Cancellation counts do not cover every order-bearing OTA daily fact in the same summary scope.',
                ];
            }
            if (!$classificationComplete) {
                $hasUnknownStatuses = count(array_filter(
                    $cancelRows,
                    fn(array $row): bool => $this->hasNumericValue(
                        $row,
                        'unknown_status_order_count'
                    ) && (float)$row['unknown_status_order_count'] > 0
                )) > 0;
                $hasClassificationMismatch = count(array_filter(
                    $cancelRows,
                    fn(array $row): bool => (
                        $this->hasNumericValue($row, 'gross_order_count')
                        && (
                            (float)$row['gross_order_count'] < 0
                            || (float)$row['cancel_order_num']
                                > (float)$row['gross_order_count']
                        )
                    ) || (float)$row['cancel_order_num'] < 0
                )) > 0;
                if ($hasUnknownStatuses) {
                    $dataGaps[] = [
                        'code' => 'cancellation_status_classification_incomplete',
                        'message' => 'Cancellation counts include unknown order statuses, so the gross-order denominator is incomplete.',
                    ];
                }
                if ($hasClassificationMismatch) {
                    $dataGaps[] = [
                        'code' => 'cancellation_order_classification_mismatch',
                        'message' => 'Cancellation counts or the aligned gross-order base are outside the valid classification range.',
                    ];
                }
                if (!$hasUnknownStatuses && !$hasClassificationMismatch) {
                    $dataGaps[] = [
                        'code' => 'cancellation_gross_order_base_missing',
                        'message' => 'Cancellation counts are present, but an aligned gross-order base with complete status classification is missing.',
                    ];
                }
            }
            if ($coverageComplete && $classificationComplete) {
                $cancelOrders = $this->sum(
                    $completeCancellationRows,
                    'cancel_order_num'
                );
                $cancelOrderBase = (int)round($this->sum(
                    $completeCancellationRows,
                    'gross_order_count'
                ));
                $cancellationRateBasis =
                    'cancelled_orders_over_gross_orders_complete_classification';
                $cancellationEvidenceRows = $completeCancellationRows;
                $grossOrderEvidenceRows = $completeCancellationRows;
                $cancelOrderEvidenceRows = $completeCancellationRows;
                if ($cancelOrderBase > 0) {
                    $cancellationRate = round(
                        $cancelOrders / $cancelOrderBase * 100,
                        2
                    );
                } else {
                    $dataGaps[] = [
                        'code' => 'cancellation_gross_order_base_zero',
                        'message' => 'The gross-order denominator is verified as zero, so a cancellation rate is not calculable.',
                    ];
                }
            }
        } elseif ($directCancelRateRows) {
            $validDirectCancelRateRows = array_values(array_filter(
                $directCancelRateRows,
                fn(array $row): bool => (float)$row['cancel_rate'] >= 0
                    && (float)$row['cancel_rate'] <= 100
                    && (!$this->hasNumericValue(
                        $row,
                        'unknown_status_order_count'
                    ) || (float)$row['unknown_status_order_count'] === 0.0)
            ));
            $hasUnknownStatuses = count(array_filter(
                $directCancelRateRows,
                fn(array $row): bool => $this->hasNumericValue(
                    $row,
                    'unknown_status_order_count'
                ) && (float)$row['unknown_status_order_count'] > 0
            )) > 0;
            $hasInvalidRates = count(array_filter(
                $directCancelRateRows,
                static fn(array $row): bool => (float)$row['cancel_rate'] < 0
                    || (float)$row['cancel_rate'] > 100
            )) > 0;
            if ($hasUnknownStatuses) {
                $dataGaps[] = [
                    'code' => 'cancellation_status_classification_incomplete',
                    'message' => 'A direct cancellation rate cannot override explicitly unknown order statuses in the same fact.',
                ];
            }
            if ($hasInvalidRates) {
                $dataGaps[] = [
                    'code' => 'cancellation_rate_invalid',
                    'message' => 'A platform-supplied cancellation rate is outside the valid 0-100 percent range.',
                ];
            }
            $coverageComplete = count($validDirectCancelRateRows)
                === count($cancellationScopeRows)
                && $this->cancellationSummaryScopeKeys(
                    $validDirectCancelRateRows
                ) === $summaryCancellationScopeKeys;
            if (!$coverageComplete && !$hasUnknownStatuses && !$hasInvalidRates) {
                $dataGaps[] = [
                    'code' => 'cancellation_fields_partial',
                    'message' => 'Direct cancellation-rate evidence does not cover every order-bearing OTA daily fact in the same summary scope.',
                ];
            }
            if ($coverageComplete) {
                $cancellationEvidenceRows = $validDirectCancelRateRows;
                $cancellationRateBasis = 'platform_supplied_direct_rate';
                $directGrossRows = array_values(array_filter(
                    $validDirectCancelRateRows,
                    fn(array $row): bool => $this->hasNumericValue(
                        $row,
                        'gross_order_count'
                    ) && (float)$row['gross_order_count'] >= 0
                ));
                if (count($directGrossRows)
                    === count($validDirectCancelRateRows)
                ) {
                    $cancelOrderBase = (int)round($this->sum(
                        $directGrossRows,
                        'gross_order_count'
                    ));
                    $grossOrderEvidenceRows = $directGrossRows;
                    if ($cancelOrderBase > 0) {
                        $weightedRate = 0.0;
                        foreach ($directGrossRows as $row) {
                            $weightedRate += (float)$row['cancel_rate']
                                * (float)$row['gross_order_count'];
                        }
                        $cancellationRate = round(
                            $weightedRate / $cancelOrderBase,
                            2
                        );
                    } else {
                        $dataGaps[] = [
                            'code' => 'cancellation_gross_order_base_zero',
                            'message' => 'The gross-order denominator is verified as zero, so a cancellation rate is not calculable.',
                        ];
                    }
                } else {
                    $cancellationRate = $this->average(
                        $validDirectCancelRateRows,
                        'cancel_rate'
                    );
                }
            }
        } else {
            $dataGaps[] = [
                'code' => 'cancellation_fields_missing',
                'message' => 'Cancellation fields are not present in OTA daily facts.',
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

        $cancellationGapCodes = $this->dataGapCodesByPrefix(
            $dataGaps,
            'cancellation_'
        );
        $grossOrderTrustFailures = [];
        if ($grossOrderEvidenceRows === []) {
            $grossOrderTrustFailures = $cancellationGapCodes !== []
                ? $cancellationGapCodes
                : [
                    $cancellationRateBasis === 'platform_supplied_direct_rate'
                        ? 'cancellation_gross_order_base_missing_for_combined_rate'
                        : 'cancellation_gross_order_base_missing',
                ];
        }
        $cancelOrderTrustFailures = [];
        if ($cancelOrderEvidenceRows === []) {
            $cancelOrderTrustFailures = $cancellationGapCodes !== []
                ? $cancellationGapCodes
                : ['cancellation_count_not_supplied_by_direct_rate'];
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
            $cancellationEvidenceRows !== []
                ? $cancellationEvidenceRows
                : ($cancelRows ?: $directCancelRateRows),
            $cancelRoomNightRows
        );
        $metricTrust['totals.gross_order_count'] = $this->trust(
            $grossOrderEvidenceRows,
            'sum(fact_ota_daily.gross_order_count) from one complete cancellation-classification scope',
            $grossOrderTrustFailures
        );
        $metricTrust['totals.cancel_order_count'] = $this->trust(
            $cancelOrderEvidenceRows,
            'sum(fact_ota_daily.cancel_order_num) from one complete cancellation-classification scope',
            $cancelOrderTrustFailures
        );
        $metricTrust['advertising.spend'] = $this->trust($this->rowsWithNumeric($advertising, 'spend'), 'sum(fact_ota_advertising.spend)');
        $metricTrust['advertising.order_amount'] = $this->trust($this->rowsWithNumeric($advertising, 'order_amount'), 'sum(fact_ota_advertising.order_amount)');
        $metricTrust['advertising.roas'] = $this->trust($this->rowsWithNumeric($advertising, 'roas'), 'sum(fact_ota_advertising.order_amount) / sum(fact_ota_advertising.spend)');
        $metricTrust['quality.avg_psi_score'] = $this->trust($quality, 'avg(fact_ota_quality.psi_score)');
        $metricTrust['quality.avg_service_score'] = $this->trust($quality, 'avg(fact_ota_quality.service_score)');
        $metricTrust['peer_rank.rows'] = $this->trust($peerRanks, 'count(fact_ota_peer_rank)');
        $metricTrust['traffic_analysis.rows'] = $this->trust($trafficAnalysis, 'count(fact_ota_traffic_analysis)');
        $metricTrust['traffic_forecast.rows'] = $this->trust($trafficForecast, 'count(fact_ota_traffic_forecast)');
        $metricTrust['booking_window_adr.buckets'] = $this->trust(
            $bookingWindowAdrRows,
            'group by lead_time_days bucket; sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
            $this->dataGapCodesByPrefix($dataGaps, 'booking_window_adr_')
        );
        $metricTrust['channel_booking_window_month.cells'] = $this->trust(
            $channelBookingWindowMonthRows,
            'group by checkin month, platform_key, and lead_time_days bucket; sum(order_count) / channel-month sum(order_count)',
            ($channelBookingWindowMonth['reason'] ?? '') !== '' ? [(string)$channelBookingWindowMonth['reason']] : []
        );

        $result = [
            'status' => $daily || $traffic || $advertising || $quality || $searchKeywords || $peerRanks || $trafficAnalysis || $trafficForecast || $comments ? 'ready' : 'empty',
            'generated_at' => date('Y-m-d H:i:s'),
            'fact_table' => [
                'name' => 'fact_ota_daily',
                'grain' => 'date_key + hotel_key + platform_key + data_type + dimension',
                'source_table' => 'online_daily_data',
            ],
            'metric_definitions' => $this->metricDefinitions(),
            'totals' => [
                'revenue' => $revenueRows ? round($revenue, 2) : null,
                'gross_revenue' => $grossRevenueRows ? round($this->sum($grossRevenueRows, 'gross_revenue'), 2) : null,
                'room_revenue' => $roomRevenueRows ? round($roomRevenue, 2) : null,
                'net_revenue' => $netRows ? round($netRevenue, 2) : null,
                'commission_amount' => $commissionRows ? round($commissionAmount, 2) : null,
                'commission_rate' => $commissionRows && $commissionGrossRevenue > 0 ? round($commissionAmount / $commissionGrossRevenue * 100, 2) : null,
                'room_nights' => $roomNightRows ? round($roomNights, 2) : null,
                'available_room_nights' => $availableRows ? round($availableRoomNights, 2) : null,
                'occupied_room_nights' => $occupancyRows ? round($occupiedRoomNights, 2) : null,
                'order_count' => $orderCount,
                'gross_order_count' => $cancelOrderBase,
                'cancel_order_count' => $cancelOrders !== null
                    ? (int)round($cancelOrders)
                    : null,
                'cancellation_rate_basis' => $cancellationRateBasis,
                'adr' => $roomRevenueRows && $roomNightRows && $roomNights > 0
                    ? round($roomRevenue / $roomNights, 2)
                    : null,
                'occ' => $occupancyRows && $occupancyAvailableRoomNights > 0 ? round($occupiedRoomNights / $occupancyAvailableRoomNights * 100, 2) : null,
                'revpar' => $revparRows && $revparAvailableRoomNights > 0 ? round($revparRoomRevenue / $revparAvailableRoomNights, 2) : null,
                'net_revpar' => $netRevparRows && $netRevparAvailableRoomNights > 0 ? round($netRevparNetRevenue / $netRevparAvailableRoomNights, 2) : null,
                'avg_lead_time_days' => $this->average($leadTimeRows, 'lead_time_days'),
                'cancellation_rate' => $cancellationRate,
                'room_night_cancellation_rate' => $roomNightCancellationRate,
                'review_count' => $reviewCountRows ? $reviewCount : null,
                'avg_comment_score' => $this->average($comments, 'comment_score'),
            ],
            'traffic' => [
                'rows' => count($traffic),
                'avg_flow_rate' => $this->average(
                    $trafficFlowRows,
                    'flow_rate'
                ),
                'avg_submit_rate' => $this->average(
                    $trafficSubmitRows,
                    'submit_rate'
                ),
                'list_exposure' => $trafficListExposureRows
                    ? (int)round($this->sum(
                        $trafficListExposureRows,
                        'list_exposure'
                    ))
                    : null,
                'detail_exposure' => $trafficDetailExposureRows
                    ? (int)round($this->sum(
                        $trafficDetailExposureRows,
                        'detail_exposure'
                    ))
                    : null,
                'metric_source_rows' => [
                    'flow_rate' => count($trafficFlowRows),
                    'submit_rate' => count($trafficSubmitRows),
                    'list_exposure' => count($trafficListExposureRows),
                    'detail_exposure' => count($trafficDetailExposureRows),
                ],
                'projection_policy' =>
                    'same_business_date_latest_final_meituan_total_funnel_prefers_structured_xhr_over_dom',
                'canonicalized_projection_groups' =>
                    $trafficProjectionOverlapGroups,
            ],
            'advertising' => $this->advertisingSummary($advertising),
            'quality' => $this->qualitySummary($quality),
            'competitor_price' => [
                'rows' => count($priceRows),
                'avg_our_price' => $this->average($priceRows, 'our_price'),
                'avg_competitor_price' => $this->average($priceRows, 'competitor_price'),
                'avg_price_gap' => $this->average($priceRows, 'price_gap'),
                'avg_price_gap_rate' => $this->average($priceRows, 'price_gap_rate'),
            ],
            'booking_window_adr' => $bookingWindowAdr,
            'channel_booking_window_month' => $channelBookingWindowMonth,
            'channel_contribution' => $this->channelContribution($daily, $revenue, $netRevenue),
            'by_platform' => $this->groupDailyBy($daily, 'platform_key', $revenue, $netRevenue),
            'by_hotel' => $this->groupDailyBy($daily, 'hotel_key', $revenue, $netRevenue),
            'channel_metrics' => $this->channelMetrics($daily, $traffic, $advertising, $quality, $searchKeywords, $peerRanks, $trafficAnalysis, $trafficForecast, $comments),
            'data_gaps' => $dataGaps,
            'etl_quality' => $dataset['data_quality'] ?? [],
            'metric_trust' => $metricTrust,
        ];

        $result['credibility_gate'] = (new OtaDataCredibilityGateService())->evaluate($dataset, $result);
        $result['p1_revenue_closure'] = $this->p1RevenueClosure($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function p1RevenueClosure(array $metrics): array
    {
        $gate = is_array($metrics['credibility_gate'] ?? null) ? $metrics['credibility_gate'] : [];
        $revenueUse = is_array($gate['decision_use']['revenue_analysis'] ?? null) ? $gate['decision_use']['revenue_analysis'] : [];
        $calculationAllowed = ($revenueUse['allowed'] ?? false) === true;

        $sections = [
            'revenue' => $this->closureMetric($metrics, 'revenue', 'OTA room revenue', 'totals.room_revenue', $metrics['totals']['room_revenue'] ?? null, 'CNY', $calculationAllowed),
            'orders' => $this->closureMetric($metrics, 'orders', 'OTA orders', 'totals.order_count', $metrics['totals']['order_count'] ?? null, 'orders', $calculationAllowed),
            'room_nights' => $this->closureMetric($metrics, 'room_nights', 'OTA room nights', 'totals.room_nights', $metrics['totals']['room_nights'] ?? null, 'room_nights', $calculationAllowed),
            'adr_conversion' => [
                'key' => 'adr_conversion',
                'label' => 'ADR and conversion',
                'scope' => 'ota_channel',
                'metrics' => [
                    'adr' => $this->closureMetric($metrics, 'adr', 'OTA ADR', 'totals.adr', $metrics['totals']['adr'] ?? null, 'CNY', $calculationAllowed),
                    'flow_rate' => $this->closureMetric($metrics, 'flow_rate', 'OTA flow conversion', 'traffic.avg_flow_rate', $metrics['traffic']['avg_flow_rate'] ?? null, '%', $calculationAllowed),
                    'submit_rate' => $this->closureMetric($metrics, 'submit_rate', 'OTA submit conversion', 'traffic.avg_submit_rate', $metrics['traffic']['avg_submit_rate'] ?? null, '%', $calculationAllowed),
                ],
            ],
        ];
        $sections['adr_conversion']['status'] = $this->combinedSectionStatus($sections['adr_conversion']['metrics']);

        $missingItems = $this->p1MissingItems($metrics);
        $anomalyItems = $this->p1AnomalyItems($metrics, $sections, $calculationAllowed);
        $status = $this->p1ClosureStatus($gate, $sections, $missingItems, $anomalyItems);

        return [
            'status' => $status,
            'scope' => 'ota_channel',
            'scope_statement' => 'P1 uses verified OTA-channel facts only; it is not whole-hotel operating truth.',
            'date_basis' => 'data_date',
            'calculation_allowed' => $calculationAllowed,
            'decision_use' => $revenueUse,
            'sections' => $sections,
            'missing_items' => [
                'status' => $missingItems === [] ? 'ok' : 'warning',
                'items' => $missingItems,
            ],
            'anomaly_judgment' => [
                'status' => $anomalyItems === [] ? 'ok' : ($status === 'blocked' ? 'blocked' : 'warning'),
                'items' => $anomalyItems,
            ],
            'whole_hotel_guard' => [
                'allowed' => false,
                'reason' => 'whole_hotel_scope_not_proved',
                'blocked_metrics' => ['whole_hotel_revenue', 'whole_hotel_adr', 'whole_hotel_occ', 'whole_hotel_revpar'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, mixed>
     */
    private function closureMetric(array $metrics, string $key, string $label, string $metricTrustKey, mixed $value, string $unit, bool $calculationAllowed): array
    {
        $trust = is_array($metrics['metric_trust'][$metricTrustKey] ?? null) ? $metrics['metric_trust'][$metricTrustKey] : [];
        $failureReasons = $this->stringList($trust['failure_reasons'] ?? []);
        if (!$calculationAllowed) {
            $failureReasons[] = 'blocked_by_data_credibility';
        }
        $failureReasons = array_values(array_unique($failureReasons));
        $trusted = $calculationAllowed && ($trust['saved_success'] ?? false) === true && $failureReasons === [];
        $numericValue = $this->numericValue($value);

        return [
            'key' => $key,
            'label' => $label,
            'metric_key' => $metricTrustKey,
            'value' => $trusted ? $numericValue : null,
            'unit' => $unit,
            'status' => $this->closureMetricStatus($trusted, $calculationAllowed, $numericValue, $failureReasons),
            'reason' => $failureReasons[0] ?? ($numericValue === null ? 'metric_value_missing' : ''),
            'scope' => 'ota_channel',
            'caliber' => (string)($trust['caliber'] ?? ''),
            'source' => is_array($trust['source'] ?? null) ? $trust['source'] : [],
            'updated_at' => $trust['updated_at'] ?? null,
            'failure_reasons' => $failureReasons,
            'truth' => is_array($trust['truth'] ?? null)
                ? $trust['truth']
                : OnlineDataTrustStatusService::metricTruthEnvelope($trust),
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $metrics
     */
    private function combinedSectionStatus(array $metrics): string
    {
        $statuses = array_values(array_map(static fn(array $metric): string => (string)($metric['status'] ?? 'unknown'), $metrics));
        if (in_array('blocked', $statuses, true)) {
            return 'blocked';
        }
        if (in_array('unverified', $statuses, true) || in_array('not_calculable', $statuses, true)) {
            return in_array('ok', $statuses, true) ? 'partial' : 'warning';
        }
        return $statuses === [] ? 'unknown' : 'ok';
    }

    /**
     * @param array<int, string> $failureReasons
     */
    private function closureMetricStatus(bool $trusted, bool $calculationAllowed, ?float $value, array $failureReasons): string
    {
        if (!$calculationAllowed || in_array('blocked_by_data_credibility', $failureReasons, true)) {
            return 'blocked';
        }
        if ($value === null) {
            return 'not_calculable';
        }
        if ($failureReasons !== []) {
            return 'unverified';
        }
        return $trusted ? 'ok' : 'unverified';
    }

    private function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<int, array<string, mixed>>
     */
    private function p1MissingItems(array $metrics): array
    {
        $items = [];
        foreach ($this->list($metrics['data_gaps'] ?? []) as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $affectedMetrics = $this->affectedP1Metrics($code);
            if ($affectedMetrics === []) {
                continue;
            }
            $items[] = [
                'type' => 'data_gap',
                'code' => $code,
                'message' => (string)($gap['message'] ?? ''),
                'affected_metrics' => $affectedMetrics,
            ];
        }

        foreach ([
            'totals.room_revenue' => ['revenue'],
            'totals.order_count' => ['orders'],
            'totals.room_nights' => ['room_nights', 'adr'],
            'totals.adr' => ['adr'],
            'traffic.avg_flow_rate' => ['flow_rate'],
            'traffic.avg_submit_rate' => ['submit_rate'],
        ] as $metricKey => $affectedMetrics) {
            $trust = is_array($metrics['metric_trust'][$metricKey] ?? null) ? $metrics['metric_trust'][$metricKey] : [];
            foreach ($this->stringList($trust['failure_reasons'] ?? []) as $reason) {
                $items[] = [
                    'type' => 'metric_trust',
                    'code' => $metricKey . ':' . $reason,
                    'message' => $reason,
                    'affected_metrics' => $affectedMetrics,
                ];
            }
            $metricValue = $metrics;
            foreach (explode('.', $metricKey) as $segment) {
                if (!is_array($metricValue) || !array_key_exists($segment, $metricValue)) {
                    $metricValue = null;
                    break;
                }
                $metricValue = $metricValue[$segment];
            }
            if ($this->numericValue($metricValue) === null) {
                $items[] = [
                    'type' => 'metric_value',
                    'code' => $metricKey . ':metric_value_missing',
                    'message' => 'metric_value_missing',
                    'affected_metrics' => $affectedMetrics,
                ];
            }
        }

        return $this->uniqueItemsByCode($items);
    }

    /**
     * @return array<int, string>
     */
    private function affectedP1Metrics(string $code): array
    {
        if (str_starts_with($code, 'room_revenue_')) {
            return ['revenue', 'adr'];
        }
        if (str_starts_with($code, 'available_') || str_starts_with($code, 'occupied_')) {
            return ['whole_hotel_guard'];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $metrics
     * @param array<string, mixed> $sections
     * @return array<int, array<string, mixed>>
     */
    private function p1AnomalyItems(array $metrics, array $sections, bool $calculationAllowed): array
    {
        $items = [];
        $gate = is_array($metrics['credibility_gate'] ?? null) ? $metrics['credibility_gate'] : [];
        foreach ($this->stringList($gate['reason_codes'] ?? []) as $code) {
            $items[] = [
                'type' => 'credibility_gate',
                'code' => $code,
                'severity' => 'high',
                'message' => 'Revenue analysis is blocked until OTA evidence is trusted.',
            ];
        }
        foreach ($this->stringList($gate['warnings'] ?? []) as $code) {
            if ($code === 'whole_hotel_scope_not_proved') {
                continue;
            }
            $items[] = [
                'type' => 'credibility_gate_warning',
                'code' => $code,
                'severity' => 'medium',
                'message' => 'Revenue analysis is allowed only with the warning visible.',
            ];
        }

        $revenue = $sections['revenue']['value'] ?? null;
        $orders = $sections['orders']['value'] ?? null;
        $roomNights = $sections['room_nights']['value'] ?? null;
        if ($calculationAllowed && is_numeric($revenue) && (float)$revenue > 0 && is_numeric($orders) && (float)$orders <= 0) {
            $items[] = [
                'type' => 'metric_consistency',
                'code' => 'revenue_positive_orders_zero',
                'severity' => 'medium',
                'message' => 'OTA revenue is positive but verified OTA order count is zero.',
            ];
        }
        if ($calculationAllowed && is_numeric($revenue) && (float)$revenue > 0 && is_numeric($roomNights) && (float)$roomNights <= 0) {
            $items[] = [
                'type' => 'metric_consistency',
                'code' => 'revenue_positive_room_nights_zero',
                'severity' => 'medium',
                'message' => 'OTA revenue is positive but verified OTA room nights are zero.',
            ];
        }

        return $this->uniqueItemsByCode($items);
    }

    /**
     * @param array<string, mixed> $gate
     * @param array<string, mixed> $sections
     * @param array<int, array<string, mixed>> $missingItems
     * @param array<int, array<string, mixed>> $anomalyItems
     */
    private function p1ClosureStatus(array $gate, array $sections, array $missingItems, array $anomalyItems): string
    {
        if (($gate['status'] ?? '') === 'blocked') {
            return 'blocked';
        }
        $sectionStatuses = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (isset($section['metrics']) && is_array($section['metrics'])) {
                foreach ($section['metrics'] as $metric) {
                    if (is_array($metric)) {
                        $sectionStatuses[] = (string)($metric['status'] ?? 'unknown');
                    }
                }
                continue;
            }
            $sectionStatuses[] = (string)($section['status'] ?? 'unknown');
        }
        $allSectionsReady = $sectionStatuses !== []
            && array_values(array_filter($sectionStatuses, static fn(string $status): bool => $status !== 'ok')) === [];
        return $allSectionsReady && $missingItems === [] && $anomalyItems === [] ? 'ready' : 'warning';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function uniqueItemsByCode(array $items): array
    {
        $seen = [];
        $unique = [];
        foreach ($items as $item) {
            $code = trim((string)($item['code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $unique[] = $item;
        }
        return $unique;
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = trim((string)$item);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return array_values(array_unique($items));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function advertisingSummary(array $rows): array
    {
        $spendRows = $this->rowsWithNumeric($rows, 'spend');
        $orderAmountRows = $this->rowsWithNumeric($rows, 'order_amount');
        $bookingRows = $this->rowsWithNumeric($rows, 'bookings');
        $roomNightRows = $this->rowsWithNumeric($rows, 'room_nights');
        $impressionRows = $this->rowsWithNumeric($rows, 'impressions');
        $clickRows = $this->rowsWithNumeric($rows, 'clicks');
        $spend = $this->sum($spendRows, 'spend');
        $orderAmount = $this->sum($orderAmountRows, 'order_amount');

        return [
            'rows' => count($rows),
            'spend' => $spendRows ? round($spend, 2) : null,
            'order_amount' => $orderAmountRows ? round($orderAmount, 2) : null,
            'bookings' => $bookingRows ? (int)round($this->sum($bookingRows, 'bookings')) : null,
            'room_nights' => $roomNightRows ? round($this->sum($roomNightRows, 'room_nights'), 2) : null,
            'impressions' => $impressionRows ? (int)round($this->sum($impressionRows, 'impressions')) : null,
            'clicks' => $clickRows ? (int)round($this->sum($clickRows, 'clicks')) : null,
            'avg_ctr' => $this->average($rows, 'ctr'),
            'avg_cvr' => $this->average($rows, 'cvr'),
            'roas' => $spendRows && $orderAmountRows && $spend > 0
                ? round($orderAmount / $spend, 2)
                : $this->average($rows, 'roas'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function qualitySummary(array $rows): array
    {
        return [
            'rows' => count($rows),
            'avg_psi_score' => $this->average($rows, 'psi_score'),
            'avg_service_score' => $this->average($rows, 'service_score'),
            'avg_im_score' => $this->average($rows, 'im_score'),
            'avg_reply_rate' => $this->average($rows, 'reply_rate'),
            'hotel_collect' => ($collectRows = $this->rowsWithNumeric($rows, 'hotel_collect'))
                ? (int)round($this->sum($collectRows, 'hotel_collect'))
                : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function bookingWindowAdrSummary(array $rows, int $leadTimeRowCount): array
    {
        $definitions = $this->bookingWindowDefinitions();
        $groups = [];
        foreach ($definitions as $definition) {
            $groups[$definition['key']] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'row_count' => 0,
                'room_revenue' => 0.0,
                'room_nights' => 0.0,
                'order_count' => 0,
                'has_order_count' => false,
            ];
        }

        foreach ($rows as $row) {
            $days = (int)round((float)$row['lead_time_days']);
            foreach ($definitions as $definition) {
                if ($days < $definition['min']) {
                    continue;
                }
                if ($definition['max'] !== null && $days > $definition['max']) {
                    continue;
                }
                $key = $definition['key'];
                $groups[$key]['row_count']++;
                $groups[$key]['room_revenue'] += (float)$row['room_revenue'];
                $groups[$key]['room_nights'] += (float)$row['room_nights'];
                if ($this->hasNumericValue($row, 'order_count')
                    && $this->orderCountSemanticAllowed($row)
                ) {
                    $groups[$key]['order_count'] += (int)round((float)$row['order_count']);
                    $groups[$key]['has_order_count'] = true;
                }
                break;
            }
        }

        $buckets = [];
        foreach ($definitions as $definition) {
            $group = $groups[$definition['key']];
            if ($group['row_count'] <= 0 || $group['room_nights'] <= 0) {
                continue;
            }
            $buckets[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'row_count' => $group['row_count'],
                'room_revenue' => round($group['room_revenue'], 2),
                'room_nights' => round($group['room_nights'], 2),
                'order_count' => $group['has_order_count'] ? $group['order_count'] : null,
                'adr' => round($group['room_revenue'] / $group['room_nights'], 2),
            ];
        }

        $alignedRowCount = count($rows);
        return [
            'status' => $alignedRowCount === 0
                ? 'not_calculable'
                : ($alignedRowCount < $leadTimeRowCount ? 'partial' : 'ready'),
            'reason' => $alignedRowCount === 0
                ? ($leadTimeRowCount > 0 ? 'booking_window_adr_fields_missing' : 'lead_time_fields_missing')
                : ($alignedRowCount < $leadTimeRowCount ? 'booking_window_adr_fields_partial' : ''),
            'scope' => 'ota_channel',
            'date_basis' => 'lead_time_days',
            'lead_time_row_count' => $leadTimeRowCount,
            'aligned_row_count' => $alignedRowCount,
            'bucket_count' => count($buckets),
            'buckets' => $buckets,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function channelBookingWindowMonthSummary(array $rows, int $leadTimeRowCount): array
    {
        $definitions = $this->bookingWindowDefinitions();
        $groups = [];
        $channelMonthTotals = [];
        foreach ($rows as $row) {
            $stayMonth = $this->stayMonth((string)($row['checkin_date'] ?? ''));
            $platform = strtolower(trim((string)($row['platform_key'] ?? '')));
            $orderCount = (int)round((float)($row['order_count'] ?? 0));
            if ($stayMonth === '' || $platform === '' || $orderCount <= 0) {
                continue;
            }
            $days = (int)round((float)$row['lead_time_days']);
            foreach ($definitions as $index => $definition) {
                if ($days < $definition['min'] || ($definition['max'] !== null && $days > $definition['max'])) {
                    continue;
                }
                $channelMonthKey = $stayMonth . '|' . $platform;
                $groupKey = $channelMonthKey . '|' . $definition['key'];
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = [
                        'stay_month' => $stayMonth,
                        'platform_key' => $platform,
                        'booking_window_key' => $definition['key'],
                        'booking_window_label' => $definition['label'],
                        'booking_window_order' => $index,
                        'row_count' => 0,
                        'order_count' => 0,
                    ];
                }
                $groups[$groupKey]['row_count']++;
                $groups[$groupKey]['order_count'] += $orderCount;
                $channelMonthTotals[$channelMonthKey] = ($channelMonthTotals[$channelMonthKey] ?? 0) + $orderCount;
                break;
            }
        }

        $cells = [];
        $months = [];
        $channels = [];
        $supportedCellCount = 0;
        $sparseCellCount = 0;
        foreach ($groups as $group) {
            $channelMonthKey = $group['stay_month'] . '|' . $group['platform_key'];
            $totalOrders = (int)($channelMonthTotals[$channelMonthKey] ?? 0);
            $supported = $group['order_count'] >= self::CHANNEL_BOOKING_WINDOW_MIN_ORDERS;
            $supported ? $supportedCellCount++ : $sparseCellCount++;
            $months[$group['stay_month']] = true;
            $channels[$group['platform_key']] = true;
            $cells[] = [
                'stay_month' => $group['stay_month'],
                'platform_key' => $group['platform_key'],
                'booking_window_key' => $group['booking_window_key'],
                'booking_window_label' => $group['booking_window_label'],
                'row_count' => $group['row_count'],
                'order_count' => $group['order_count'],
                'channel_month_order_count' => $totalOrders,
                'order_share' => $totalOrders > 0 ? round($group['order_count'] / $totalOrders * 100, 2) : null,
                'sample_status' => $supported ? 'supported' : 'sparse',
                '_booking_window_order' => $group['booking_window_order'],
            ];
        }
        usort($cells, static function (array $left, array $right): int {
            return [$left['stay_month'], $left['platform_key'], $left['_booking_window_order']]
                <=> [$right['stay_month'], $right['platform_key'], $right['_booking_window_order']];
        });
        foreach ($cells as &$cell) {
            unset($cell['_booking_window_order']);
        }
        unset($cell);

        $alignedRowCount = count($rows);
        $reason = '';
        if ($alignedRowCount === 0) {
            $reason = $leadTimeRowCount > 0 ? 'channel_booking_window_month_fields_missing' : 'lead_time_fields_missing';
        } elseif ($alignedRowCount < $leadTimeRowCount) {
            $reason = 'channel_booking_window_month_fields_partial';
        } elseif ($sparseCellCount > 0) {
            $reason = 'channel_booking_window_month_sparse_cells';
        }

        return [
            'status' => $alignedRowCount === 0 ? 'not_calculable' : ($reason === '' ? 'ready' : 'partial'),
            'reason' => $reason,
            'scope' => 'ota_channel',
            'date_basis' => 'checkin_month',
            'lead_time_row_count' => $leadTimeRowCount,
            'aligned_row_count' => $alignedRowCount,
            'month_count' => count($months),
            'channel_count' => count($channels),
            'cell_count' => count($cells),
            'supported_cell_count' => $supportedCellCount,
            'sparse_cell_count' => $sparseCellCount,
            'minimum_order_count' => self::CHANNEL_BOOKING_WINDOW_MIN_ORDERS,
            'cells' => $cells,
        ];
    }

    /** @return array<int, array{key: string, label: string, min: int, max: ?int}> */
    private function bookingWindowDefinitions(): array
    {
        return [
            ['key' => 'same_day', 'label' => '当天', 'min' => 0, 'max' => 0],
            ['key' => 'days_1_3', 'label' => '1-3天', 'min' => 1, 'max' => 3],
            ['key' => 'days_4_7', 'label' => '4-7天', 'min' => 4, 'max' => 7],
            ['key' => 'days_8_14', 'label' => '8-14天', 'min' => 8, 'max' => 14],
            ['key' => 'days_15_30', 'label' => '15-30天', 'min' => 15, 'max' => 30],
            ['key' => 'days_31_plus', 'label' => '31天以上', 'min' => 31, 'max' => null],
        ];
    }

    private function stayMonth(string $date): string
    {
        $date = trim($date);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date
            ? $parsed->format('Y-m')
            : '';
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
    private function verifiedOrderCountRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn(array $row): bool =>
                $this->hasNumericValue($row, 'order_count')
                && $this->orderCountSemanticAllowed($row)
        ));
    }

    /** @param array<string, mixed> $row */
    private function orderCountSemanticAllowed(array $row): bool
    {
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        if (str_contains($dimension, 'business_capacity')
            || str_contains($dimension, 'occupied_room')
            || str_contains($dimension, 'occupiedrooms')
        ) {
            return false;
        }
        return true;
    }

    /**
     * Canonicalize only explicit whole-property funnel projections. Meituan
     * chooses the newest daily snapshot across collection tasks, then prefers
     * its structured XHR projection over the DOM fallback. Ctrip headline
     * traffic uses only the self total funnel and excludes competitor, source
     * breakdown, and business-visitor rows from the additive headline.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function canonicalTrafficMetricRows(
        array $rows,
        string $metricKey
    ): array {
        $metricRows = $this->rowsWithNumeric($rows, $metricKey);
        $metricRows = $this->canonicalMeituanTrafficMetricRows($metricRows);
        return $this->canonicalCtripTrafficMetricRows($metricRows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function canonicalMeituanTrafficMetricRows(array $rows): array
    {
        $selected = [];
        $groups = [];
        foreach ($rows as $row) {
            $projection = $this->meituanTrafficProjection($row);
            if ($projection === 'non_total') {
                continue;
            }
            if ($projection === null) {
                $selected[] = $row;
                continue;
            }
            $compareType = strtolower(trim((string)(
                $row['compare_type'] ?? 'self'
            ))) ?: 'self';
            $key = implode('|', [
                (string)($row['hotel_key'] ?? ''),
                (string)($row['platform_key'] ?? ''),
                (string)($row['date_key'] ?? ''),
                $compareType,
            ]);
            $groups[$key][] = [
                'projection' => $projection,
                'row' => $row,
            ];
        }

        foreach ($groups as $items) {
            $runs = [];
            foreach ($items as $item) {
                $trace = is_array($item['row']['source_trace'] ?? null)
                    ? $item['row']['source_trace']
                    : [];
                $syncTaskId = (int)($trace['sync_task_id'] ?? 0);
                $runKey = $syncTaskId > 0
                    ? 'task:' . $syncTaskId
                    : 'row:' . $this->trafficMetricRowOrder($item['row']);
                $runs[$runKey][] = $item;
            }
            uasort(
                $runs,
                fn(array $left, array $right): int =>
                    $this->trafficMetricRowCompare(
                        $this->latestTrafficMetricItem($left)['row'],
                        $this->latestTrafficMetricItem($right)['row']
                    )
            );
            $latestRun = end($runs);
            $structured = array_values(array_filter(
                $latestRun,
                static fn(array $item): bool =>
                    $item['projection'] === 'structured_xhr'
            ));
            $pool = $structured !== [] ? $structured : $latestRun;
            usort(
                $pool,
                fn(array $left, array $right): int =>
                    $this->trafficMetricRowCompare(
                        $left['row'],
                        $right['row']
                    )
            );
            $selected[] = $pool[count($pool) - 1]['row'];
        }
        return array_values($selected);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function canonicalCtripTrafficMetricRows(array $rows): array
    {
        $selected = [];
        $groups = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string)($row['platform_key'] ?? '')))
                !== 'ctrip'
            ) {
                $selected[] = $row;
                continue;
            }
            $key = implode('|', [
                (string)($row['hotel_key'] ?? ''),
                (string)($row['platform_key'] ?? ''),
                (string)($row['date_key'] ?? ''),
            ]);
            $groups[$key][] = $row;
        }

        foreach ($groups as $items) {
            $totalRows = array_values(array_filter(
                $items,
                fn(array $row): bool =>
                    $this->ctripTrafficProjection($row) === 'total_self'
            ));
            if ($totalRows !== []) {
                usort(
                    $totalRows,
                    fn(array $left, array $right): int =>
                        $this->trafficMetricRowCompare($left, $right)
                );
                $selected[] = $totalRows[count($totalRows) - 1];
                continue;
            }
            foreach ($items as $row) {
                if ($this->ctripTrafficProjection($row) === null) {
                    $selected[] = $row;
                }
            }
        }
        return array_values($selected);
    }

    /** @param array<string, mixed> $row */
    private function meituanTrafficProjection(array $row): ?string
    {
        if (strtolower(trim((string)($row['platform_key'] ?? '')))
            !== 'meituan'
        ) {
            return null;
        }
        $raw = is_array($row['raw_data'] ?? null)
            ? $row['raw_data']
            : [];
        $rawRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $captureEvidence = is_array($raw['capture_evidence'] ?? null)
            ? $raw['capture_evidence']
            : [];
        $rowCaptureEvidence = is_array(
            $rawRow['capture_evidence'] ?? null
        ) ? $rawRow['capture_evidence'] : [];
        $captureSources = [
            $raw['_capture_source'] ?? null,
            $captureEvidence['capture_source'] ?? null,
            $rawRow['_capture_source'] ?? null,
            $rowCaptureEvidence['capture_source'] ?? null,
        ];
        $capturePaths = [
            $raw['_source_path'] ?? null,
            $captureEvidence['source_path'] ?? null,
            $rawRow['_source_path'] ?? null,
            $rowCaptureEvidence['source_path'] ?? null,
        ];
        foreach ($captureSources as $source) {
            $source = strtolower(trim((string)($source ?? '')));
            if ($source === 'dom:traffic:flow_funnel') {
                return 'dom_fallback';
            }
            if ($source === 'xhr:traffic:source_breakdown') {
                return 'non_total';
            }
            if ($source === 'xhr:traffic:traffic') {
                foreach ($capturePaths as $path) {
                    $path = strtolower(trim((string)($path ?? '')));
                    if (str_starts_with($path, 'data.myhotel')) {
                        return 'structured_xhr';
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function ctripTrafficProjection(array $row): ?string
    {
        if (strtolower(trim((string)($row['platform_key'] ?? '')))
            !== 'ctrip'
        ) {
            return null;
        }
        $compareType = strtolower(trim((string)(
            $row['compare_type'] ?? 'self'
        ))) ?: 'self';
        if ($compareType !== 'self') {
            return 'non_self';
        }
        $raw = is_array($row['raw_data'] ?? null)
            ? $row['raw_data']
            : [];
        $rawRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $dimension = strtolower(trim((string)(
            $row['dimension']
                ?? $rawRow['dimension']
                ?? ''
        )));
        if (str_contains($dimension, 'business_visitor_title')) {
            return 'other_semantic';
        }
        if (str_contains($dimension, 'traffic_flow_transform')
            && preg_match('/:\s*0\.date$/', $dimension) === 1
        ) {
            return 'total_self';
        }

        $paths = [
            $raw['_source_path'] ?? null,
            $rawRow['_source_path'] ?? null,
        ];
        foreach ($paths as $path) {
            $path = strtolower(trim((string)($path ?? '')));
            if (str_contains($path, 'flowsourcedetails')) {
                return 'source_breakdown';
            }
            if ($path === '$[0]') {
                return 'total_self';
            }
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function trafficMetricRowOrder(array $row): int
    {
        $trace = is_array($row['source_trace'] ?? null)
            ? $row['source_trace']
            : [];
        foreach (['collected_at', 'updated_at'] as $key) {
            $value = trim((string)($trace[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $time = strtotime($value);
            if ($time !== false) {
                return $time * 1000000
                    + max(0, (int)($trace['row_id'] ?? 0));
            }
        }
        return max(0, (int)($trace['row_id'] ?? 0));
    }

    /**
     * @param array<int,array{projection:string,row:array<string,mixed>}> $items
     * @return array{projection:string,row:array<string,mixed>}
     */
    private function latestTrafficMetricItem(array $items): array
    {
        usort(
            $items,
            fn(array $left, array $right): int =>
                $this->trafficMetricRowCompare($left['row'], $right['row'])
        );
        return $items[count($items) - 1];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function trafficMetricRowCompare(array $left, array $right): int
    {
        $leftFinal = $this->trafficMetricRowFinal($left);
        $rightFinal = $this->trafficMetricRowFinal($right);
        if ($leftFinal !== $rightFinal) {
            return $leftFinal <=> $rightFinal;
        }
        return $this->trafficMetricRowOrder($left)
            <=> $this->trafficMetricRowOrder($right);
    }

    /** @param array<string,mixed> $row */
    private function trafficMetricRowFinal(array $row): bool
    {
        $trace = is_array($row['source_trace'] ?? null)
            ? $row['source_trace']
            : [];
        if (($trace['is_final'] ?? false) === true
            || in_array($trace['is_final'] ?? null, [1, '1', 'true'], true)
        ) {
            return true;
        }
        return strtolower(trim((string)($trace['data_period'] ?? '')))
            === 'historical_daily';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function trafficProjectionOverlapGroupCount(array $rows): int
    {
        $groups = [];
        foreach ($rows as $row) {
            $projection = $this->meituanTrafficProjection($row);
            if ($projection === null || $projection === 'non_total') {
                continue;
            }
            $compareType = strtolower(trim((string)(
                $row['compare_type'] ?? 'self'
            )));
            if ($compareType === '') {
                $compareType = 'self';
            }
            $key = implode('|', [
                (string)($row['hotel_key'] ?? ''),
                (string)($row['platform_key'] ?? ''),
                (string)($row['date_key'] ?? ''),
                $compareType,
            ]);
            $groups[$key][] = $projection;
        }

        return count(array_filter(
            $groups,
            static fn(array $projections): bool => count($projections) > 1
        ));
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
                    'has_revenue' => false,
                    'has_room_revenue' => false,
                    'has_room_nights' => false,
                    'has_order_count' => false,
                    'has_net_revenue' => false,
                    'has_commission_amount' => false,
                    'has_available_room_nights' => false,
                    'has_occupied_room_nights' => false,
                    'revpar_room_revenue' => 0.0,
                    'revpar_available_room_nights' => 0.0,
                    'has_revpar_room_revenue' => false,
                    'occupancy_available_room_nights' => 0.0,
                    'net_revpar_net_revenue' => 0.0,
                    'net_revpar_available_room_nights' => 0.0,
                ];
            }
            if ($this->hasNumericValue($row, 'revenue')) {
                $groups[$groupKey]['has_revenue'] = true;
                $groups[$groupKey]['revenue'] += (float)$row['revenue'];
            }
            if ($this->hasNumericValue($row, 'room_revenue')) {
                $groups[$groupKey]['has_room_revenue'] = true;
                $groups[$groupKey]['room_revenue'] += (float)$row['room_revenue'];
            }
            if ($this->hasNumericValue($row, 'room_nights')) {
                $groups[$groupKey]['has_room_nights'] = true;
                $groups[$groupKey]['room_nights'] += (float)$row['room_nights'];
            }
            if ($this->hasNumericValue($row, 'order_count')
                && $this->orderCountSemanticAllowed($row)
            ) {
                $groups[$groupKey]['has_order_count'] = true;
                $groups[$groupKey]['order_count'] += (int)$row['order_count'];
            }
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
                if ($this->hasNumericValue($row, 'room_revenue')) {
                    $groups[$groupKey]['has_revpar_room_revenue'] = true;
                    $groups[$groupKey]['revpar_room_revenue'] += (float)$row['room_revenue'];
                    $groups[$groupKey]['revpar_available_room_nights'] += $availableRoomNights;
                }
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
            $group['revenue'] = $group['has_revenue'] ? round((float)$group['revenue'], 2) : null;
            $group['room_revenue'] = $group['has_room_revenue'] ? round((float)$group['room_revenue'], 2) : null;
            $group['net_revenue'] = $group['has_net_revenue'] ? round((float)$group['net_revenue'], 2) : null;
            $group['commission_amount'] = $group['has_commission_amount'] ? round((float)$group['commission_amount'], 2) : null;
            $group['room_nights'] = $group['has_room_nights'] ? round((float)$group['room_nights'], 2) : null;
            $group['order_count'] = $group['has_order_count'] ? (int)$group['order_count'] : null;
            $group['available_room_nights'] = $group['has_available_room_nights'] ? round((float)$group['available_room_nights'], 2) : null;
            $group['occupied_room_nights'] = $group['has_occupied_room_nights'] ? round((float)$group['occupied_room_nights'], 2) : null;
            $group['adr'] = $group['room_revenue'] !== null && $group['room_nights'] !== null && $group['room_nights'] > 0
                ? round($group['room_revenue'] / $group['room_nights'], 2)
                : null;
            $group['occ'] = $group['occupancy_available_room_nights'] > 0 && $group['occupied_room_nights'] !== null
                ? round($group['occupied_room_nights'] / $group['occupancy_available_room_nights'] * 100, 2)
                : null;
            $group['revpar'] = $group['has_revpar_room_revenue'] && $group['revpar_available_room_nights'] > 0
                ? round($group['revpar_room_revenue'] / $group['revpar_available_room_nights'], 2)
                : null;
            $group['net_revpar'] = $group['net_revpar_available_room_nights'] > 0
                ? round($group['net_revpar_net_revenue'] / $group['net_revpar_available_room_nights'], 2)
                : null;
            $group['channel_contribution_rate'] = $totalRevenue > 0 && $group['revenue'] !== null
                ? round($group['revenue'] / $totalRevenue * 100, 2)
                : null;
            $group['revenue_contribution_rate'] = $group['channel_contribution_rate'];
            $group['net_revenue_contribution_rate'] = $totalNetRevenue > 0 && $group['net_revenue'] !== null
                ? round($group['net_revenue'] / $totalNetRevenue * 100, 2)
                : null;
            unset(
                $group['has_net_revenue'],
                $group['has_commission_amount'],
                $group['has_available_room_nights'],
                $group['has_occupied_room_nights'],
                $group['has_revenue'],
                $group['has_room_revenue'],
                $group['has_room_nights'],
                $group['has_order_count'],
                $group['revpar_room_revenue'],
                $group['revpar_available_room_nights'],
                $group['has_revpar_room_revenue'],
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
     * @param array<int, array<string, mixed>> $advertising
     * @param array<int, array<string, mixed>> $quality
     * @param array<int, array<string, mixed>> $searchKeywords
     * @param array<int, array<string, mixed>> $peerRanks
     * @param array<int, array<string, mixed>> $trafficAnalysis
     * @param array<int, array<string, mixed>> $trafficForecast
     * @param array<int, array<string, mixed>> $comments
     * @return array<int, array<string, mixed>>
     */
    private function channelMetrics(array $daily, array $traffic, array $advertising, array $quality, array $searchKeywords, array $peerRanks, array $trafficAnalysis, array $trafficForecast, array $comments): array
    {
        $metrics = [];

        foreach ($daily as $row) {
            $resource = $this->channelResource($row, (string)($row['data_type'] ?? 'business'));
            $this->appendChannelMetric($metrics, $row, $resource, 'revenue', $row['revenue'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'room_nights', $row['room_nights'] ?? null);
            $this->appendChannelMetric(
                $metrics,
                $row,
                $resource,
                'order_count',
                $this->orderCountSemanticAllowed($row)
                    ? ($row['order_count'] ?? null)
                    : null
            );
            $this->appendChannelMetric($metrics, $row, $resource, 'adr', $row['adr'] ?? null, $row['room_nights'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'competitor_price', 'our_price', $row['our_price'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'competitor_price', 'competitor_price', $row['competitor_price'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'competitor_price', 'price_gap', $row['price_gap'] ?? null);
        }

        foreach ($this->canonicalTrafficMetricRows(
            $traffic,
            'list_exposure'
        ) as $row) {
            $this->appendChannelMetric($metrics, $row, 'traffic', 'list_exposure', $row['list_exposure'] ?? null);
        }
        foreach ($this->canonicalTrafficMetricRows(
            $traffic,
            'detail_exposure'
        ) as $row) {
            $this->appendChannelMetric($metrics, $row, 'traffic', 'detail_exposure', $row['detail_exposure'] ?? null);
        }
        foreach ($this->canonicalTrafficMetricRows(
            $traffic,
            'flow_rate'
        ) as $row) {
            $this->appendChannelMetric($metrics, $row, 'traffic', 'flow_rate', $row['flow_rate'] ?? null, $row['list_exposure'] ?? null);
        }
        foreach ($this->canonicalTrafficMetricRows(
            $traffic,
            'order_filling_num'
        ) as $row) {
            $this->appendChannelMetric($metrics, $row, 'traffic', 'order_filling_num', $row['order_filling_num'] ?? null);
        }
        foreach ($this->canonicalTrafficMetricRows(
            $traffic,
            'order_submit_num'
        ) as $row) {
            $this->appendChannelMetric($metrics, $row, 'traffic', 'order_submit_num', $row['order_submit_num'] ?? null, $row['order_filling_num'] ?? null);
        }

        foreach ($advertising as $row) {
            $this->appendChannelMetric($metrics, $row, 'advertising', 'amount', $row['spend'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'advertising', 'impressions', $row['impressions'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'advertising', 'clicks', $row['clicks'] ?? null, $row['impressions'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'advertising', 'bookings', $row['bookings'] ?? null, $row['clicks'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'advertising', 'roi', $row['roas'] ?? null, $row['spend'] ?? null);
        }

        foreach ($quality as $row) {
            $this->appendChannelMetric($metrics, $row, 'quality', 'psi_score', $row['psi_score'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'quality', 'service_score', $row['service_score'] ?? null);
            $this->appendChannelMetric($metrics, $row, 'quality', 'reply_rate', $row['reply_rate'] ?? null);
        }

        foreach ($searchKeywords as $row) {
            $resource = $this->channelResource($row, 'search_keyword');
            $this->appendChannelMetric($metrics, $row, $resource, 'rank', $row['rank'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'impressions', $row['impressions'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'clicks', $row['clicks'] ?? null, $row['impressions'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'order_contribution', $row['order_contribution'] ?? null, $row['clicks'] ?? null);
        }

        foreach ($peerRanks as $row) {
            $resource = $this->channelResource($row, 'peer_rank');
            $this->appendChannelMetric($metrics, $row, $resource, 'rank', $row['rank'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'rank_percent', $row['rank_percent'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'metric_value', $row['metric_value'] ?? null);
        }

        foreach ($trafficAnalysis as $row) {
            $resource = $this->channelResource($row, 'traffic_analysis');
            $this->appendChannelMetric($metrics, $row, $resource, 'list_exposure', $row['list_exposure'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'detail_exposure', $row['detail_exposure'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'flow_rate', $row['flow_rate'] ?? null, $row['list_exposure'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'order_filling_num', $row['order_filling_num'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'order_submit_num', $row['order_submit_num'] ?? null, $row['order_filling_num'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'submit_rate', $row['submit_rate'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'metric_value', $row['metric_value'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'peer_rank', $row['peer_rank'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'week_over_week', $row['week_over_week'] ?? null);
        }

        foreach ($trafficForecast as $row) {
            $resource = $this->channelResource($row, 'traffic_forecast');
            $this->appendChannelMetric($metrics, $row, $resource, 'forecast_value', $row['forecast_value'] ?? null);
            $this->appendChannelMetric($metrics, $row, $resource, 'peer_avg', $row['peer_avg'] ?? null);
        }

        foreach ($comments as $row) {
            $this->appendChannelMetric($metrics, $row, 'review', 'score', $row['score'] ?? null);
        }

        return $metrics;
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @param array<string, mixed> $row
     */
    private function appendChannelMetric(array &$metrics, array $row, string $resource, string $metricKey, mixed $value, mixed $denominator = null): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }

        $trace = is_array($row['source_trace'] ?? null) ? $row['source_trace'] : [];
        $sourceTraceId = trim((string)($trace['source_trace_id'] ?? ''));
        if ($sourceTraceId === '' && array_key_exists('row_id', $trace) && $trace['row_id'] !== null && $trace['row_id'] !== '') {
            $sourceTraceId = 'online_daily_data#' . (string)$trace['row_id'];
        }

        $denominatorValue = $denominator !== null && $denominator !== '' && is_numeric($denominator)
            ? (float)$denominator
            : null;

        $metrics[] = [
            'scope' => 'ota_channel',
            'platform' => (string)($trace['platform'] ?? $row['platform_key'] ?? ''),
            'resource' => $resource,
            'metric_key' => $metricKey,
            'value' => (float)$value,
            'denominator' => $denominatorValue,
            'data_status' => $this->channelMetricDataStatus($trace),
            'source_trace_id' => $sourceTraceId,
            'updated_at' => (string)($trace['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function channelResource(array $row, string $default): string
    {
        $default = $default !== '' ? $default : 'business';
        if ($default === 'search_keyword') {
            $keyword = trim((string)($row['keyword'] ?? ''));
            return $keyword !== '' ? 'search_keyword:' . $keyword : 'search_keyword';
        }

        $dimension = trim((string)($row['dimension'] ?? ''));
        if ($dimension === '') {
            return $default;
        }
        return $dimension === $default || str_starts_with($dimension, $default . ':')
            ? $dimension
            : $default . ':' . $dimension;
    }

    /**
     * @param array<string, mixed> $trace
     */
    private function channelMetricDataStatus(array $trace): string
    {
        if (!$trace) {
            return 'unknown';
        }
        if (($trace['saved_success'] ?? false) !== true) {
            return 'failed';
        }
        if (!empty($trace['failure_reasons'])) {
            return 'failed';
        }
        if (trim((string)($trace['updated_at'] ?? '')) === '') {
            return 'warning';
        }
        return 'ok';
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
        $roomRevenueFailures = $this->dataGapCodesByPrefix($dataGaps, 'room_revenue_');
        $revenueRows = $this->rowsWithNumeric($daily, 'revenue');
        $grossRevenueRows = $this->rowsWithNumeric($daily, 'gross_revenue');
        $roomRevenueRows = $this->rowsWithNumeric($daily, 'room_revenue');
        $roomNightRows = $this->rowsWithNumeric($daily, 'room_nights');
        $orderCountRows = $this->verifiedOrderCountRows($daily);
        $adrRows = $this->mergeMetricRows($roomRevenueRows, $roomNightRows);
        $revparRows = array_values(array_filter(
            $availableRows,
            fn(array $row): bool => $this->hasNumericValue($row, 'room_revenue')
        ));
        $trust = [
            'totals.revenue' => $this->trust($revenueRows, 'sum(fact_ota_daily.revenue)'),
            'totals.gross_revenue' => $this->trust($grossRevenueRows, 'sum(fact_ota_daily.gross_revenue)'),
            'totals.room_revenue' => $this->trust(
                $roomRevenueRows,
                'sum(fact_ota_daily.room_revenue)',
                $roomRevenueFailures
            ),
            'totals.net_revenue' => $this->trust($netRows, 'sum(fact_ota_daily.net_revenue)', $netRevenueFailures),
            'totals.commission_amount' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount)', $commissionFailures),
            'totals.commission_rate' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount) / sum(fact_ota_daily.gross_revenue)', $commissionFailures),
            'totals.room_nights' => $this->trust($roomNightRows, 'sum(fact_ota_daily.room_nights)'),
            'totals.available_room_nights' => $this->trust($availableRows, 'sum(fact_ota_daily.available_room_nights)', $availabilityFailures),
            'totals.occupied_room_nights' => $this->trust($occupancyRows, 'sum(fact_ota_daily.occupied_room_nights)', $availabilityFailures),
            'totals.order_count' => $this->trust($orderCountRows, 'sum(fact_ota_daily.order_count)'),
            'totals.adr' => $this->trust(
                $adrRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
                array_merge(
                    $roomRevenueFailures,
                    $roomNights > 0 ? [] : ['adr_denominator_zero']
                )
            ),
            'totals.occ' => $this->trust(
                $occupancyRows,
                'sum(fact_ota_daily.occupied_room_nights) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            'totals.revpar' => $this->trust(
                $revparRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)',
                array_merge($availabilityFailures, $roomRevenueFailures)
            ),
            'totals.net_revpar' => $this->trust(
                $netRevparRows,
                'sum(fact_ota_daily.net_revenue) / sum(fact_ota_daily.available_room_nights)',
                array_merge($availabilityFailures, $netRevenueFailures)
            ),
            'totals.avg_lead_time_days' => $this->trust($leadTimeRows, 'avg(fact_ota_daily.lead_time_days)', $leadTimeFailures),
            'totals.cancellation_rate' => $this->trust(
                $cancellationRows,
                'sum(fact_ota_daily.cancel_order_num) / sum(fact_ota_daily.gross_order_count) for complete status classification, or avg(fact_ota_daily.cancel_rate) when platform rate is supplied directly',
                $cancellationFailures
            ),
            'totals.room_night_cancellation_rate' => $this->trust(
                $cancelRoomNightRows,
                'sum(fact_ota_daily.cancel_room_nights) / sum(fact_ota_daily.room_nights)',
                $cancelRoomNightFailures
            ),
            'totals.review_count' => $this->trust($this->rowsWithNumeric($comments, 'comment_count'), 'sum(fact_ota_comment.comment_count)'),
            'totals.avg_comment_score' => $this->trust($this->rowsWithNumeric($comments, 'comment_score'), 'avg(fact_ota_comment.comment_score)'),
            'traffic.rows' => $this->trust($traffic, 'count(fact_ota_traffic)'),
            'traffic.avg_flow_rate' => $this->trust(
                $this->canonicalTrafficMetricRows($traffic, 'flow_rate'),
                'avg(canonical fact_ota_traffic.flow_rate)'
            ),
            'traffic.avg_submit_rate' => $this->trust(
                $this->canonicalTrafficMetricRows($traffic, 'submit_rate'),
                'avg(canonical fact_ota_traffic.submit_rate)'
            ),
            'traffic.list_exposure' => $this->trust(
                $this->canonicalTrafficMetricRows($traffic, 'list_exposure'),
                'sum(canonical fact_ota_traffic.list_exposure)'
            ),
            'traffic.detail_exposure' => $this->trust(
                $this->canonicalTrafficMetricRows($traffic, 'detail_exposure'),
                'sum(canonical fact_ota_traffic.detail_exposure)'
            ),
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
        $revenueRows = $this->rowsWithNumeric($rows, 'revenue');
        $roomRevenueRows = $this->rowsWithNumeric($rows, 'room_revenue');
        $roomNightRows = $this->rowsWithNumeric($rows, 'room_nights');
        $orderCountRows = $this->verifiedOrderCountRows($rows);
        $adrRows = $this->mergeMetricRows($roomRevenueRows, $roomNightRows);
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
        $revparRows = array_values(array_filter(
            $availableRows,
            fn(array $row): bool => $this->hasNumericValue($row, 'room_revenue')
        ));
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
        $roomRevenueFailures = [];
        if (!$roomRevenueRows) {
            $roomRevenueFailures[] = 'room_revenue_missing';
        } elseif (array_filter(
            $rows,
            fn(array $row): bool => $this->hasNumericValue($row, 'revenue')
                && !$this->hasNumericValue($row, 'room_revenue')
        ) !== []) {
            $roomRevenueFailures[] = 'room_revenue_partial';
        }
        if (!$netRows) {
            $netRevenueFailures[] = 'net_revenue_fields_missing';
        } elseif (count($netRows) < count($rows)) {
            $netRevenueFailures[] = 'net_revenue_fields_partial';
        }

        return [
            $prefix . '.revenue' => $this->trust($revenueRows, 'sum(fact_ota_daily.revenue)'),
            $prefix . '.room_revenue' => $this->trust(
                $roomRevenueRows,
                'sum(fact_ota_daily.room_revenue)',
                $roomRevenueFailures
            ),
            $prefix . '.net_revenue' => $this->trust($netRows, 'sum(fact_ota_daily.net_revenue)', $netRevenueFailures),
            $prefix . '.commission_amount' => $this->trust($commissionRows, 'sum(fact_ota_daily.commission_amount)', $commissionRows ? [] : ['commission_fields_missing']),
            $prefix . '.room_nights' => $this->trust($roomNightRows, 'sum(fact_ota_daily.room_nights)'),
            $prefix . '.available_room_nights' => $this->trust($availableRows, 'sum(fact_ota_daily.available_room_nights)', $availabilityFailures),
            $prefix . '.occupied_room_nights' => $this->trust($occupancyRows, 'sum(fact_ota_daily.occupied_room_nights)', $availabilityFailures),
            $prefix . '.order_count' => $this->trust($orderCountRows, 'sum(fact_ota_daily.order_count)'),
            $prefix . '.adr' => $this->trust(
                $adrRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
                array_merge(
                    $roomRevenueFailures,
                    $this->sum($rows, 'room_nights') > 0 ? [] : ['adr_denominator_zero']
                )
            ),
            $prefix . '.occ' => $this->trust(
                $occupancyRows,
                'sum(fact_ota_daily.occupied_room_nights) / sum(fact_ota_daily.available_room_nights)',
                $availabilityFailures
            ),
            $prefix . '.revpar' => $this->trust(
                $revparRows,
                'sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)',
                array_merge($availabilityFailures, $roomRevenueFailures)
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
                    'not_calculable_when' => 'verified room_revenue is missing, or room_nights is missing or zero',
                ],
                'occ' => [
                    'formula' => 'sum(occupied_room_nights) / sum(available_room_nights) * 100',
                    'not_calculable_when' => 'available_room_nights is missing or zero',
                ],
                'revpar' => [
                    'formula' => 'sum(room_revenue for rows with available_room_nights) / sum(available_room_nights)',
                    'not_calculable_when' => 'verified room_revenue is missing, or available_room_nights is missing or zero; partial rows are reported in data_gaps',
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
                'booking_window_adr' => [
                    'formula' => 'group by lead_time_days bucket; sum(room_revenue) / sum(room_nights)',
                    'not_calculable_when' => 'lead_time_days, verified room_revenue, or positive room_nights is missing; partial aligned rows are reported in data_gaps',
                ],
                'channel_booking_window_month' => [
                    'formula' => 'group by checkin month, OTA platform, and lead-time bucket; sum(order_count) / channel-month sum(order_count)',
                    'not_calculable_when' => 'checkin_date, lead_time_days, platform_key, or positive order_count is missing; cells below the minimum order count remain visible as sparse and are not promoted as decision signals',
                ],
                'gross_order_count' => [
                    'formula' => 'sum(gross_order_count) only when the same cancellation scope has complete status classification',
                    'not_calculable_when' => 'gross_order_count is missing, order statuses are unknown, or cancellation evidence is partial or mixed',
                ],
                'cancellation_rate' => [
                    'formula' => 'cancel_order_num / gross_order_count * 100 after complete order-status classification; uses platform cancel_rate only when supplied directly for the full scope',
                    'not_calculable_when' => 'evidence is partial or mixed, cancel_order_num/cancel_rate is missing, gross_order_count is zero for count evidence, or unknown order statuses remain',
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
                'peer_rank_signal' => [
                    'formula' => 'peer rank rows from OTA supplemental capture, exposed only as channel_metrics',
                    'not_calculable_when' => 'peer_rank rows are missing; does not participate in revenue, ADR, OCC, or RevPAR',
                ],
                'traffic_analysis_signal' => [
                    'formula' => 'traffic analysis rows from OTA supplemental capture, exposed only as channel_metrics',
                    'not_calculable_when' => 'traffic_analysis rows are missing; does not participate in revenue, ADR, OCC, or RevPAR',
                ],
                'traffic_forecast_signal' => [
                    'formula' => 'traffic forecast rows from OTA supplemental capture, exposed only as channel_metrics',
                    'not_calculable_when' => 'traffic_forecast rows are missing; forecast is not actual revenue evidence',
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

        $result = [
            'source' => $this->sourceSummary($traces),
            'caliber' => $caliber,
            'updated_at' => $updatedAt,
            'failure_reasons' => $failureReasons,
            'saved_success' => $allSaved && empty($failureReasons),
        ];
        $result['truth'] = OnlineDataTrustStatusService::metricTruthEnvelope($result);
        return $result;
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
     * A ratio may use independently persisted numerator and denominator facts.
     * Preserve every contributing source row once in its trust envelope.
     *
     * @param array<int, array<string, mixed>> ...$rowSets
     * @return array<int, array<string, mixed>>
     */
    private function mergeMetricRows(array ...$rowSets): array
    {
        $merged = [];
        foreach ($rowSets as $rows) {
            foreach ($rows as $row) {
                if (!in_array($row, $merged, true)) {
                    $merged[] = $row;
                }
            }
        }
        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @return array<string, mixed>
     */
    private function sourceSummary(array $traces): array
    {
        $dates = $this->uniqueTraceValues($traces, 'date_key');
        sort($dates);
        $collectedTimes = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $this->uniqueTraceValues($traces, 'collected_at')
        ), static fn(string $value): bool => $value !== ''));
        sort($collectedTimes);
        $storedCount = count(array_filter($traces, static function (array $trace): bool {
            if (array_key_exists('stored', $trace)) {
                return ($trace['stored'] ?? false) === true;
            }
            return isset($trace['row_id']) && trim((string)$trace['row_id']) !== '';
        }));
        $readbackVerifiedCount = count(array_filter(
            $traces,
            static fn(array $trace): bool => ($trace['readback_verified'] ?? false) === true
        ));
        $finalRowCount = count(array_filter(
            $traces,
            static fn(array $trace): bool => in_array(
                $trace['is_final'] ?? null,
                [true, 1, '1', 'true'],
                true
            )
        ));
        $dataPeriods = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $this->uniqueTraceValues($traces, 'data_period')
        ), static fn(string $value): bool => $value !== ''));
        sort($dataPeriods);
        $rowCount = count($traces);
        $finality = $rowCount === 0
            ? 'unknown'
            : (
                $finalRowCount === $rowCount
                    ? 'final'
                    : ($finalRowCount === 0 ? 'provisional' : 'mixed')
            );

        return [
            'table' => 'online_daily_data',
            'row_ids' => $this->uniqueTraceValues($traces, 'row_id'),
            'trace_ids' => $this->uniqueTraceValues($traces, 'source_trace_id'),
            'data_source_ids' => $this->uniqueTraceValues($traces, 'data_source_id'),
            'sync_task_ids' => $this->uniqueTraceValues($traces, 'sync_task_id'),
            'hotels' => $this->sourceHotels($traces),
            'platforms' => $this->uniqueTraceValues($traces, 'platform'),
            'data_types' => $this->uniqueTraceValues($traces, 'data_type'),
            'source_methods' => $this->uniqueTraceValues($traces, 'ingestion_method'),
            'data_periods' => $dataPeriods,
            'finality' => $finality,
            'final_row_count' => $finalRowCount,
            'date_range' => [
                'start' => $dates[0] ?? null,
                'end' => $dates ? $dates[count($dates) - 1] : null,
            ],
            'collected_at_range' => [
                'start' => $collectedTimes[0] ?? null,
                'end' => $collectedTimes !== [] ? $collectedTimes[count($collectedTimes) - 1] : null,
            ],
            'row_count' => $rowCount,
            'stored_count' => $storedCount,
            'readback_verified_count' => $readbackVerifiedCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $traces
     * @return array<int, array<string, mixed>>
     */
    private function sourceHotels(array $traces): array
    {
        $hotels = [];
        foreach ($traces as $trace) {
            $systemHotelId = max(0, (int)($trace['system_hotel_id'] ?? 0));
            $hotelKey = trim((string)($trace['hotel_key'] ?? ''));
            if ($systemHotelId <= 0 && preg_match('/^system:(\d+)$/D', $hotelKey, $match) === 1) {
                $systemHotelId = (int)$match[1];
            }
            $platformHotelId = trim((string)($trace['platform_hotel_id'] ?? ''));
            $name = trim((string)($trace['hotel_name'] ?? ''));
            if ($systemHotelId <= 0 && $platformHotelId === '' && $name === '' && $hotelKey === '') {
                continue;
            }
            $key = $systemHotelId > 0
                ? 'system:' . $systemHotelId
                : ($platformHotelId !== '' ? 'platform:' . $platformHotelId : ($name !== '' ? 'name:' . $name : 'key:' . $hotelKey));
            $hotels[$key] = [
                'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                'platform_hotel_id' => $platformHotelId,
                'name' => $name,
            ];
        }
        return array_values($hotels);
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
     * Cancellation coverage is evaluated once per hotel, platform, and
     * business date. Revenue-only rows in another platform/date scope cannot
     * disappear merely because they do not expose an order field.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function cancellationSummaryScopeKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!$this->orderCountSemanticAllowed($row)) {
                continue;
            }
            $key = implode('|', [
                strtolower(trim((string)($row['hotel_key'] ?? ''))),
                strtolower(trim((string)($row['platform_key'] ?? ''))),
                trim((string)($row['date_key'] ?? '')),
            ]);
            $keys[$key] = true;
        }
        $scopeKeys = array_keys($keys);
        sort($scopeKeys);
        return $scopeKeys;
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
