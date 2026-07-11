<?php
declare(strict_types=1);

namespace app\controller\concern;

use think\facade\Db;
use think\Response;

trait CtripSearchOpportunityConcern
{
    public function ctripSearchOpportunity(): Response
    {
        $this->checkPermission();

        $systemHotelId = (int)$this->request->get('system_hotel_id', $this->request->get('hotel_id', 0));
        $permittedHotelIds = array_values(array_unique(array_map('intval', $this->currentUser->getPermittedHotelIds())));
        if (!$this->currentUser->isSuperAdmin()) {
            if ($systemHotelId <= 0 && $permittedHotelIds !== []) {
                $systemHotelId = $permittedHotelIds[0];
            }
            if ($systemHotelId <= 0 || !in_array($systemHotelId, $permittedHotelIds, true)) {
                return $this->error('无权查看该酒店的携程搜索数据', 403);
            }
        }
        if ($systemHotelId <= 0) {
            return $this->error('请选择目标酒店', 422);
        }

        $requestedDate = trim((string)$this->request->get('data_date', ''));
        if ($requestedDate !== '' && !$this->isCtripSearchOpportunityDate($requestedDate)) {
            return $this->error('data_date 格式必须为 YYYY-MM-DD', 422);
        }

        try {
            $columns = $this->getOnlineDailyDataColumns();
            $query = Db::name('online_daily_data')
                ->where('source', 'ctrip')
                ->where('data_type', 'traffic')
                ->where('system_hotel_id', $systemHotelId)
                ->where('dimension', 'like', 'catalog:traffic_report:traffic_search_details:%');
            if (isset($columns['endpoint_id'])) {
                $query->where('endpoint_id', 'traffic_search_details');
            }

            $captureDate = $requestedDate;
            if ($captureDate === '') {
                $latestDate = $this->resolveLatestCtripSearchOpportunityDate(clone $query);
                $captureDate = $this->isCtripSearchOpportunityDate($latestDate) ? $latestDate : '';
            }
            if ($captureDate === '') {
                return $this->success($this->buildCtripSearchOpportunityPayload([], ''));
            }

            $fields = array_values(array_filter([
                'data_date', 'compare_type', 'ingestion_method', 'raw_data', 'update_time', 'create_time',
            ], static fn(string $field): bool => isset($columns[$field])));
            $rows = (clone $query)
                ->field($fields)
                ->where('data_date', $captureDate)
                ->order('id', 'asc')
                ->select()
                ->toArray();

            $referenceCaptureDate = $this->resolvePreviousCtripSearchOpportunityDate(clone $query, $captureDate);
            $referenceRows = [];
            if ($this->isCtripSearchOpportunityDate($referenceCaptureDate)) {
                $referenceRows = (clone $query)
                    ->field($fields)
                    ->where('data_date', '<', $captureDate)
                    ->order('data_date', 'desc')
                    ->order('id', 'desc')
                    ->select()
                    ->toArray();
            }

            return $this->success($this->buildCtripSearchOpportunityPayload(
                $rows,
                $captureDate,
                $referenceRows,
                $referenceCaptureDate
            ));
        } catch (\Throwable $e) {
            return $this->error('读取携程未来搜索数据失败: ' . $e->getMessage());
        }
    }

    private function resolveLatestCtripSearchOpportunityDate(object $query): string
    {
        return trim((string)$query->order('data_date', 'desc')->value('data_date'));
    }

    private function resolvePreviousCtripSearchOpportunityDate(object $query, string $captureDate): string
    {
        return trim((string)$query
            ->where('data_date', '<', $captureDate)
            ->order('data_date', 'desc')
            ->value('data_date'));
    }

    private function buildCtripSearchOpportunityPayload(
        array $rows,
        string $captureDate,
        array $referenceRows = [],
        string $referenceCaptureDate = ''
    ): array
    {
        $expectedScopes = [
            'cumulative:self',
            'cumulative:competitor_avg',
            'yesterday:self',
            'yesterday:competitor_avg',
        ];
        $dates = [];
        $scopeSet = [];
        $ingestionMethods = [];
        $capturedAt = '';
        $orderMissing = false;
        $hasCurrentData = false;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $row['raw_data'] ?? null;
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (!is_array($raw) || (string)($raw['endpoint_id'] ?? '') !== 'traffic_search_details') {
                continue;
            }
            $dimensions = is_array($raw['dimension_values'] ?? null) ? $raw['dimension_values'] : [];
            $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
            $targetDate = trim((string)($dimensions['target_date'] ?? ''));
            $window = trim((string)($dimensions['search_window'] ?? ''));
            $scope = trim((string)($dimensions['compare_scope'] ?? ''));
            if ($scope === 'competitor' || $scope === 'peer') {
                $scope = 'competitor_avg';
            }
            if (!$this->isCtripSearchOpportunityDate($targetDate)
                || !in_array($window, ['cumulative', 'yesterday'], true)
                || !in_array($scope, ['self', 'competitor_avg'], true)
            ) {
                continue;
            }

            $dates[$targetDate] ??= ['target_date' => $targetDate];
            $hasCurrentData = true;
            $scopeKey = $window . ':' . $scope;
            $scopeSet[$scopeKey] = true;
            $ingestionMethod = trim((string)($row['ingestion_method'] ?? ''));
            if ($ingestionMethod !== '' && !in_array($ingestionMethod, $ingestionMethods, true)) {
                $ingestionMethods[] = $ingestionMethod;
            }
            $rowCapturedAt = trim((string)($raw['captured_at'] ?? $row['update_time'] ?? $row['create_time'] ?? ''));
            if ($rowCapturedAt !== '' && ($capturedAt === '' || strcmp($rowCapturedAt, $capturedAt) > 0)) {
                $capturedAt = $rowCapturedAt;
            }

            $missingFields = array_values(array_filter(array_map('strval', (array)($raw['missing_fields'] ?? []))));
            $orderCount = $this->ctripSearchOpportunityMetric($metrics, 'future_search_order_count', true);
            if ($orderCount === null || in_array('future_search_order_count', $missingFields, true)) {
                $orderMissing = true;
            }
            $dates[$targetDate][$window] ??= [];
            $dates[$targetDate][$window][$scope] = [
                'pv' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_pv', true),
                'uv' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_uv', true),
                'conversion_rate' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_conversion_rate'),
                'order_count' => $orderCount,
                'metric_status' => (string)($raw['metric_status'] ?? 'captured'),
                'missing_fields' => $missingFields,
            ];
        }

        $referenceCoveredGaps = [];
        $referenceCumulativeByTargetScope = [];
        usort($referenceRows, static function (array $left, array $right): int {
            $dateOrder = strcmp((string)($right['data_date'] ?? ''), (string)($left['data_date'] ?? ''));
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            return ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
        });
        foreach ($referenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = $row['raw_data'] ?? null;
            if (is_string($raw)) {
                $raw = json_decode($raw, true);
            }
            if (!is_array($raw) || (string)($raw['endpoint_id'] ?? '') !== 'traffic_search_details') {
                continue;
            }
            $dimensions = is_array($raw['dimension_values'] ?? null) ? $raw['dimension_values'] : [];
            $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
            $targetDate = trim((string)($dimensions['target_date'] ?? ''));
            $window = trim((string)($dimensions['search_window'] ?? ''));
            $scope = trim((string)($dimensions['compare_scope'] ?? ''));
            if ($scope === 'competitor' || $scope === 'peer') {
                $scope = 'competitor_avg';
            }
            if (!in_array($scope, ['self', 'competitor_avg'], true)
                || !$this->isCtripSearchOpportunityDate($targetDate)
                || !isset($dates[$targetDate])
                || !in_array($window, ['cumulative', 'yesterday'], true)
            ) {
                continue;
            }

            $missingFields = array_values(array_filter(array_map('strval', (array)($raw['missing_fields'] ?? []))));
            $rowReferenceCaptureDate = trim((string)($row['data_date'] ?? $referenceCaptureDate));
            $referenceData = [
                'pv' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_pv', true),
                'uv' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_uv', true),
                'conversion_rate' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_conversion_rate'),
                'order_count' => $this->ctripSearchOpportunityMetric($metrics, 'future_search_order_count', true),
                'metric_status' => 'historical_reference',
                'missing_fields' => $missingFields,
                'reference_capture_date' => $rowReferenceCaptureDate,
            ];
            $dates[$targetDate][$window] ??= [];
            if ($scope === 'self' && !isset($dates[$targetDate][$window]['self_reference'])) {
                $dates[$targetDate][$window]['self_reference'] = $referenceData;
            }
            if ($window === 'cumulative' && !isset($referenceCumulativeByTargetScope[$targetDate][$scope])) {
                $referenceCumulativeByTargetScope[$targetDate][$scope] = $referenceData;
            }
            if (!isset($dates[$targetDate][$window][$scope])) {
                $dates[$targetDate][$window][$scope] = $referenceData;
                $referenceCoveredGaps[$targetDate . ':' . $window . ':' . $scope] = true;
            }
        }

        foreach ($dates as $targetDate => &$dateRow) {
            foreach (['self', 'competitor_avg'] as $scope) {
                if (isset($dateRow['yesterday'][$scope])) {
                    continue;
                }
                $currentCumulative = $dateRow['cumulative'][$scope] ?? null;
                $referenceCumulative = $referenceCumulativeByTargetScope[$targetDate][$scope] ?? null;
                if (!is_array($currentCumulative) || !is_array($referenceCumulative)) {
                    continue;
                }
                $pv = $this->ctripSearchOpportunityNonNegativeDelta($currentCumulative['pv'] ?? null, $referenceCumulative['pv'] ?? null);
                $uv = $this->ctripSearchOpportunityNonNegativeDelta($currentCumulative['uv'] ?? null, $referenceCumulative['uv'] ?? null);
                $orderCount = $this->ctripSearchOpportunityNonNegativeDelta($currentCumulative['order_count'] ?? null, $referenceCumulative['order_count'] ?? null);
                if ($pv === null && $uv === null && $orderCount === null) {
                    continue;
                }
                if (($pv === null || $pv == 0.0)
                    && ($uv === null || $uv == 0.0)
                    && ($orderCount === null || $orderCount == 0.0)) {
                    continue;
                }
                $dateRow['yesterday'] ??= [];
                $dateRow['yesterday'][$scope] = [
                    'pv' => $pv,
                    'uv' => $uv,
                    'conversion_rate' => null,
                    'order_count' => $orderCount,
                    'metric_status' => 'derived_from_cumulative_delta',
                    'missing_fields' => ['future_search_conversion_rate', 'future_search_order_count'],
                    'reference_capture_date' => $referenceCaptureDate,
                ];
                $referenceCoveredGaps[$targetDate . ':yesterday:' . $scope . ':cumulative_delta'] = true;
            }
        }
        unset($dateRow);

        ksort($dates);
        $missingScopes = array_values(array_filter(
            $expectedScopes,
            static fn(string $scope): bool => !isset($scopeSet[$scope])
        ));
        $dateGaps = [];
        foreach ($dates as $targetDate => $dateRow) {
            foreach ($expectedScopes as $scopeKey) {
                [$window, $scope] = explode(':', $scopeKey, 2);
                if (!isset($dateRow[$window][$scope])) {
                    $dateGaps[] = $targetDate . ':' . $scopeKey;
                }
            }
        }
        $status = !$hasCurrentData
            ? 'not_collected'
            : (($missingScopes === [] && $dateGaps === []) ? 'ready' : 'partial');

        $windowStartDate = $dates === [] ? '' : (string)array_key_first($dates);
        $windowEndDate = $dates === [] ? '' : (string)array_key_last($dates);

        return [
            'status' => $status,
            'source_scope' => 'ctrip_ota_channel',
            'capture_date' => $captureDate,
            'captured_at' => $capturedAt,
            'window_start_date' => $windowStartDate,
            'window_end_date' => $windowEndDate,
            'reference_capture_date' => $referenceCaptureDate,
            'reference_covered_gap_count' => count($referenceCoveredGaps),
            'scope_count' => count($scopeSet),
            'expected_scope_count' => count($expectedScopes),
            'missing_scopes' => $missingScopes,
            'date_gaps' => $dateGaps,
            'target_date_count' => count($dates),
            'order_data_status' => !$hasCurrentData ? 'not_collected' : ($orderMissing ? 'field_missing' : 'available'),
            'ingestion_methods' => $ingestionMethods,
            'dates' => array_values($dates),
        ];
    }

    private function ctripSearchOpportunityMetric(array $metrics, string $key, bool $integer = false): int|float|null
    {
        if (!array_key_exists($key, $metrics) || $metrics[$key] === null || $metrics[$key] === '' || !is_numeric($metrics[$key])) {
            return null;
        }
        return $integer ? (int)round((float)$metrics[$key]) : (float)$metrics[$key];
    }

    private function ctripSearchOpportunityNonNegativeDelta(mixed $current, mixed $reference): int|float|null
    {
        if (!is_numeric($current) || !is_numeric($reference)) {
            return null;
        }
        $delta = (float)$current - (float)$reference;
        if ($delta <= 0) {
            return null;
        }
        return floor($delta) === $delta ? (int)$delta : $delta;
    }

    private function isCtripSearchOpportunityDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
