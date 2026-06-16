<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemConfig;
use app\model\SystemNotification;
use app\service\OtaOperatingScope;
use think\Response;
use think\exception\HttpException;
use think\facade\Db;

trait CollectionReliabilityConcern
{
    private function loadCollectionQualityRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $fields = array_values(array_filter([
            'id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'source', 'data_type', 'data_date',
            'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value',
            'dimension', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            'raw_data', 'validation_status', 'validation_flags', 'create_time', 'update_time',
        ], static fn(string $field): bool => isset($columns[$field])));

        if (empty($fields)) {
            return [];
        }

        $query = Db::name('online_daily_data')
            ->field($fields)
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);

        if (!$this->applyCollectionHotelScope($query, $hotelId, $columns)) {
            return [];
        }

        $requestedLimit = max(1, $limit);
        $fetchLimit = $hotelId === null ? $requestedLimit : min(2000, max($requestedLimit, $requestedLimit * 20));

        $rows = $query
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit($fetchLimit)
            ->select()
            ->toArray();

        $filteredRows = $this->filterAmbiguousCtripHotelRows($rows, $hotelId);
        $ownHotelNames = array_values(array_filter(
            array_map(static fn (array $hotel): string => (string)($hotel['name'] ?? ''), $this->loadDashboardHotels($hotelId)),
            static fn (string $name): bool => trim($name) !== ''
        ));
        return array_slice(OtaOperatingScope::filterOwnOperatingRows($filteredRows, $ownHotelNames), 0, $requestedLimit);
    }

    private function buildCtripHotelIdentityFilterReport(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $empty = [
            'status' => 'ok',
            'reason' => '',
            'target_system_hotel_id' => $hotelId,
            'target_hotel_name' => $hotelId !== null ? $this->getSystemHotelName($hotelId) : '',
            'raw_count' => 0,
            'safe_count' => 0,
            'filtered_count' => 0,
            'ambiguous_hotel_ids' => [],
            'conflicts' => [],
            'message' => '',
            'next_action' => '',
        ];
        if ($hotelId === null) {
            return $empty;
        }

        $columns = $this->getOnlineDailyDataColumns();
        $fields = array_values(array_filter([
            'id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'source', 'data_type', 'data_date',
            'dimension', 'compare_type', 'raw_data', 'create_time', 'update_time',
        ], static fn(string $field): bool => isset($columns[$field])));
        if (empty($fields)) {
            return $empty;
        }

        $query = Db::name('online_daily_data')
            ->field($fields)
            ->where('source', 'ctrip')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        if (!$this->applyCollectionHotelScope($query, $hotelId, $columns)) {
            return $empty;
        }

        $rows = $query
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
        $rawCount = count($rows);
        if ($rawCount === 0) {
            return $empty;
        }

        $expectedIds = $this->getCtripExpectedPlatformHotelIdsForSystemHotel((int)$hotelId);
        $nodeIds = $this->getCtripNodeResourceIdsForSystemHotel((int)$hotelId);
        $ids = $this->buildCtripCurrentHotelIdentityIdSet($rows, (int)$hotelId, $expectedIds, $nodeIds);
        $safeRows = $this->filterAmbiguousCtripHotelRows($rows, $hotelId);
        $safeCount = count($safeRows);
        $filteredCount = max(0, $rawCount - $safeCount);
        if ($ids === []) {
            return array_merge($empty, [
                'status' => 'blocked',
                'reason' => 'current_hotel_identity_missing',
                'raw_count' => $rawCount,
                'safe_count' => $safeCount,
                'filtered_count' => $filteredCount,
                'message' => '宸叉姄鍒版惡绋嬫暟鎹紝浣嗘湭鍛戒腑褰撳墠闂ㄥ簵韬唤锛岀郴缁熷凡闃绘灞曠ず閿欏簵椋庨櫓鏁版嵁銆?',
                'next_action' => '璇锋鏌ュ綋鍓嶆惡绋?Cookie 鏄惁涓哄綋鍓嶉棬搴楋紝鎴栧湪鎼虹▼閰嶇疆涓ˉ鍏呯湡瀹?hotelId/nodeId 鍚庨噸鏂版姄鍙栥€?',
            ]);
        }

        $conflicts = $this->findCtripPlatformHotelIdConflicts(array_keys($ids), $hotelId);
        $blockingConflicts = array_values(array_filter($conflicts, function (array $conflict) use ($ids): bool {
            return $this->shouldBlockCtripCurrentHotelIdConflict((string)($conflict['hotel_id'] ?? ''), array_keys($ids));
        }));
        if ($blockingConflicts === []) {
            return array_merge($empty, [
                'raw_count' => $rawCount,
                'safe_count' => $safeCount,
                'filtered_count' => $filteredCount,
            ]);
        }

        $ambiguousHotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['hotel_id'] ?? '')),
            $blockingConflicts
        ))));
        $targetHotelName = $empty['target_hotel_name'] !== '' ? $empty['target_hotel_name'] : ('门店ID ' . $hotelId);
        $idPreview = array_slice($ambiguousHotelIds, 0, 5);
        $idPreviewText = implode('、', $idPreview);
        if (count($ambiguousHotelIds) > count($idPreview)) {
            $idPreviewText .= ' 等 ' . count($ambiguousHotelIds) . ' 个';
        }

        return array_merge($empty, [
            'status' => $filteredCount > 0 ? 'blocked' : 'warning',
            'reason' => 'platform_hotel_conflict',
            'raw_count' => $rawCount,
            'safe_count' => $safeCount,
            'filtered_count' => $filteredCount,
            'ambiguous_hotel_ids' => $ambiguousHotelIds,
            'conflicts' => $blockingConflicts,
            'message' => '已抓到携程数据，但返回的酒店标识已在其他门店出现，系统已阻止展示错店风险数据。当前门店：' . $targetHotelName . '；冲突 hotelId：' . $idPreviewText,
            'next_action' => '请为当前门店更新专属携程 Cookie，或在携程配置中补充真实 hotelId/nodeId 后重新抓取。',
        ]);
    }

    private function buildCtripCurrentHotelIdentityIdSet(array $rows, int $systemHotelId, array $expectedIds, array $nodeIds = []): array
    {
        $ids = [];
        $nodeSet = array_fill_keys(array_map(static fn($value): string => trim((string)$value), $nodeIds), true);
        foreach ($expectedIds as $id) {
            $id = trim((string)$id);
            if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId) && !isset($nodeSet[$id])) {
                $ids[$id] = true;
            }
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isCtripCurrentHotelIdentityRow($row, $systemHotelId, $expectedIds, $nodeIds)) {
                continue;
            }
            $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
            if ($this->isMeaningfulCtripPlatformHotelId($platformHotelId, $systemHotelId) && !isset($nodeSet[$platformHotelId])) {
                $ids[$platformHotelId] = true;
            }
        }

        return $ids;
    }

    private function isCtripCurrentHotelIdentityRow(array $row, int $systemHotelId, array $expectedIds = [], array $nodeIds = []): bool
    {
        if (strtolower((string)($row['source'] ?? '')) !== 'ctrip') {
            return false;
        }

        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        if (!$this->isMeaningfulCtripPlatformHotelId($platformHotelId, $systemHotelId)) {
            return false;
        }
        $nodeSet = array_fill_keys(array_map(static fn($value): string => trim((string)$value), $nodeIds), true);
        if (isset($nodeSet[$platformHotelId])) {
            return false;
        }

        $expectedSet = array_fill_keys(array_map(static fn($value): string => trim((string)$value), $expectedIds), true);
        if (isset($expectedSet[$platformHotelId])) {
            return true;
        }

        $targetHotelName = $this->getSystemHotelName($systemHotelId);
        if ($this->isCtripGenericSelfHotelName((string)($row['hotel_name'] ?? ''))) {
            return true;
        }
        if ($this->ctripHotelNameMatches((string)($row['hotel_name'] ?? ''), $targetHotelName)) {
            return true;
        }

        [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
        if (is_array($raw)) {
            foreach (['metric_hotel_name', 'hotelName', 'hotel_name', 'name'] as $key) {
                $rawHotelName = (string)($raw[$key] ?? '');
                if ($this->isCtripGenericSelfHotelName($rawHotelName) || $this->ctripHotelNameMatches($rawHotelName, $targetHotelName)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function shouldBlockCtripCurrentHotelIdConflict(string $platformHotelId, array $expectedIds): bool
    {
        $platformHotelId = trim($platformHotelId);
        if ($platformHotelId === '') {
            return false;
        }
        $expectedSet = array_fill_keys(array_map(static fn($value): string => trim((string)$value), $expectedIds), true);
        return !isset($expectedSet[$platformHotelId]);
    }

    private function ctripHotelNameMatches(string $candidate, string $target): bool
    {
        $left = $this->normalizeCtripHotelNameForMatch($candidate);
        $right = $this->normalizeCtripHotelNameForMatch($target);
        if ($left === '' || $right === '') {
            return false;
        }
        return $left === $right
            || (mb_strlen($left, 'UTF-8') >= 3 && str_contains($right, $left))
            || (mb_strlen($right, 'UTF-8') >= 3 && str_contains($left, $right));
    }

    private function isCtripGenericSelfHotelName(string $value): bool
    {
        $normalized = $this->normalizeCtripHotelNameForMatch($value);
        if ($normalized === '') {
            return false;
        }
        $genericNames = ['myhotel', 'currenthotel', 'selfhotel'];
        $decoded = json_decode('["\u6211\u7684\u9152\u5e97","\u672c\u5e97","\u672c\u9152\u5e97"]', true);
        foreach (is_array($decoded) ? $decoded : [] as $name) {
            $genericNames[] = $this->normalizeCtripHotelNameForMatch((string)$name);
        }
        return in_array($normalized, $genericNames, true);
    }

    private function normalizeCtripHotelNameForMatch(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/[\s\-_\.|()（）·]+/u', '', $value) ?? $value;
    }

    private function isCtripCompetitorAggregateRow(array $row): bool
    {
        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        if ($platformHotelId === '-1') {
            return true;
        }
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        if (in_array($compareType, ['competitor_avg', 'avg', 'average', 'peer'], true)) {
            return true;
        }
        $dimension = strtolower((string)($row['dimension'] ?? ''));
        return str_contains($dimension, 'competehotelavg')
            || str_contains($dimension, 'competitor_avg')
            || str_contains($dimension, 'competitoraverage');
    }

    private function shouldKeepCtripScopedHotelRow(array $row, int $systemHotelId, array $currentIds, array $expectedIds, array $nodeIds = []): bool
    {
        if (strtolower((string)($row['source'] ?? '')) !== 'ctrip') {
            return true;
        }
        if ($this->isCtripCompetitorAggregateRow($row)) {
            return true;
        }
        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        if ($platformHotelId !== '' && isset($currentIds[$platformHotelId])) {
            return true;
        }
        $nodeSet = array_fill_keys(array_map(static fn($value): string => trim((string)$value), $nodeIds), true);
        if ($platformHotelId !== '' && isset($nodeSet[$platformHotelId])) {
            return $this->ctripHotelNameMatches((string)($row['hotel_name'] ?? ''), $this->getSystemHotelName($systemHotelId));
        }
        return $this->isCtripCurrentHotelIdentityRow($row, $systemHotelId, $expectedIds, $nodeIds);
    }

    private function filterAmbiguousCtripHotelRows(array $rows, ?int $hotelId): array
    {
        if ($hotelId === null || $rows === []) {
            return $rows;
        }

        $expectedIds = $this->getCtripExpectedPlatformHotelIdsForSystemHotel((int)$hotelId);
        $nodeIds = $this->getCtripNodeResourceIdsForSystemHotel((int)$hotelId);
        $currentIds = $this->buildCtripCurrentHotelIdentityIdSet($rows, (int)$hotelId, $expectedIds, $nodeIds);
        $blockingIds = [];
        if ($currentIds !== []) {
            $conflicts = $this->findCtripPlatformHotelIdConflicts(array_keys($currentIds), (int)$hotelId);
            foreach ($conflicts as $conflict) {
                $platformHotelId = trim((string)($conflict['hotel_id'] ?? ''));
                if ($this->shouldBlockCtripCurrentHotelIdConflict($platformHotelId, array_keys($currentIds))) {
                    $blockingIds[$platformHotelId] = true;
                }
            }
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row) || strtolower((string)($row['source'] ?? '')) !== 'ctrip') {
                $filtered[] = $row;
                continue;
            }
            if (!$this->shouldKeepCtripScopedHotelRow($row, (int)$hotelId, $currentIds, $expectedIds, $nodeIds)) {
                continue;
            }
            $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
            if ($platformHotelId !== '' && isset($blockingIds[$platformHotelId])) {
                continue;
            }
            $filtered[] = $row;
        }
        return array_values($filtered);
    }
    private function buildCollectionHistoryReplayRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $rows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, $limit);
        $rows = $this->mergeCtripCoreRowsForCollectionHistory($rows, $hotelId, $startDate, $endDate, $limit);
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $quality = $this->buildOnlineDataQuality($row);
            $result[] = [
                'id' => $id,
                'source_ref' => 'online_daily_data#' . $id,
                'replay_api' => $id > 0 ? '/api/online-data/history/' . $id : '',
                'data_date' => (string)($row['data_date'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'system_hotel_id' => $row['system_hotel_id'] ?? null,
                'hotel_id' => $row['hotel_id'] ?? null,
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'quality_status' => (string)($quality['status'] ?? ''),
                'quality_score' => (int)($quality['score'] ?? 0),
                'metric_preview' => $this->buildCollectionMetricPreview($row),
                'raw_data_available' => trim((string)($row['raw_data'] ?? '')) !== '',
                'updated_at' => (string)($row['update_time'] ?? $row['create_time'] ?? ''),
            ];
        }
        return $result;
    }

    private function mergeCtripCoreRowsForCollectionHistory(array $rows, ?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        if ($hotelId === null || $limit <= 0) {
            return $rows;
        }

        $columns = $this->getOnlineDailyDataColumns();
        if (!isset($columns['source'], $columns['dimension'])) {
            return $rows;
        }

        $fields = array_values(array_filter([
            'id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'source', 'data_type', 'data_date',
            'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value',
            'dimension', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            'raw_data', 'validation_status', 'validation_flags', 'create_time', 'update_time',
        ], static fn(string $field): bool => isset($columns[$field])));

        if (empty($fields)) {
            return $rows;
        }

        $query = Db::name('online_daily_data')
            ->field($fields)
            ->where('source', 'ctrip')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate)
            ->where(function ($q): void {
                $q->where('dimension', 'like', 'catalog:%')
                    ->whereOr('dimension', 'like', 'Ctrip:%');
            });

        if (isset($columns['data_type'])) {
            $query->whereIn('data_type', ['business', 'traffic', 'quality', 'ranking']);
        }
        if (!$this->applyCollectionHotelScope($query, $hotelId, $columns)) {
            return $rows;
        }

        $coreRows = $query
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(12, $limit))
            ->select()
            ->toArray();

        $coreRows = $this->filterAmbiguousCtripHotelRows($coreRows, $hotelId);
        $ownHotelNames = array_values(array_filter(
            array_map(static fn (array $hotel): string => (string)($hotel['name'] ?? ''), $this->loadDashboardHotels($hotelId)),
            static fn (string $name): bool => trim($name) !== ''
        ));
        $coreRows = OtaOperatingScope::filterOwnOperatingRows($coreRows, $ownHotelNames);

        $merged = [];
        foreach (array_merge($coreRows, $rows) as $row) {
            $id = (int)($row['id'] ?? 0);
            $key = $id > 0 ? (string)$id : md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
            if (!isset($merged[$key])) {
                $merged[$key] = $row;
            }
        }

        return array_slice(array_values($merged), 0, max(1, $limit));
    }

    private function applyCollectionHotelScope($query, ?int $hotelId, array $columns): bool
    {
        if ($hotelId !== null) {
            if (isset($columns['system_hotel_id'])) {
                $query->where('system_hotel_id', $hotelId);
            } elseif (isset($columns['hotel_id'])) {
                $query->where('hotel_id', (string)$hotelId);
            }
        }

        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            if (!isset($columns['system_hotel_id'])) {
                return false;
            }
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                return false;
            }
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        return true;
    }

    private function buildCollectionQualitySnapshot(array $rows): array
    {
        $summary = $this->buildOnlineDataQualitySummary($rows);
        $checkedRecords = (int)($summary['checked_records'] ?? 0);
        if ($checkedRecords === 0) {
            return array_merge($summary, [
                'status' => 'no_data',
                'score' => 0,
                'grade' => 'no_data',
                'coverage_days' => 0,
                'source_breakdown' => [],
                'scoring_rule' => 'Average of per-record online_daily_data quality scores in the selected period.',
            ]);
        }

        $scoreTotal = 0;
        $dates = [];
        $sourceBreakdown = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quality = $this->buildOnlineDataQuality($row);
            $scoreTotal += (int)($quality['score'] ?? 0);
            $date = (string)($row['data_date'] ?? '');
            if ($date !== '') {
                $dates[$date] = true;
            }
            $source = (string)($row['source'] ?? 'unknown');
            $type = (string)($row['data_type'] ?? 'business');
            $key = $source . ':' . $type;
            if (!isset($sourceBreakdown[$key])) {
                $sourceBreakdown[$key] = [
                    'source' => $source,
                    'data_type' => $type,
                    'records' => 0,
                    'issue_records' => 0,
                ];
            }
            $sourceBreakdown[$key]['records']++;
            if (($quality['status'] ?? 'ok') !== 'ok') {
                $sourceBreakdown[$key]['issue_records']++;
            }
        }

        $score = round($scoreTotal / $checkedRecords, 1);
        $status = (string)($summary['status'] ?? 'ok');
        if ($score < 60 && $status !== 'error') {
            $status = 'error';
        } elseif ($score < 85 && $status === 'ok') {
            $status = 'warning';
        }

        return array_merge($summary, [
            'status' => $status,
            'score' => $score,
            'grade' => $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : 'D')),
            'coverage_days' => count($dates),
            'source_breakdown' => array_values($sourceBreakdown),
            'scoring_rule' => 'Average of per-record online_daily_data quality scores in the selected period.',
        ]);
    }

    private function buildCollectionMetricPreview(array $row): array
    {
        $preview = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'data_value', 'dimension',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            'comment_score', 'qunar_comment_score', 'platform', 'compare_type',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $preview[$field] = $row[$field];
            }
        }

        [$raw] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
        if (is_array($raw)) {
            $rawMetricKeys = [];
            if (!isset($preview['metric_key'])) {
                $dimension = (string)($preview['dimension'] ?? '');
                if ($dimension !== '' && preg_match('/^catalog:[^:]+:[^:]+:([^:]+)/', $dimension, $matches)) {
                    $preview['metric_key'] = strtolower(trim((string)$matches[1]));
                }
            }
            foreach (['capture_section', 'endpoint_id', 'metric_key', 'metric_label'] as $field) {
                if (!isset($preview[$field]) && array_key_exists($field, $raw) && is_scalar($raw[$field]) && $raw[$field] !== '') {
                    $preview[$field] = $raw[$field];
                }
            }
            foreach (['metrics', 'rank_metrics'] as $metricMapKey) {
                if (!is_array($raw[$metricMapKey] ?? null)) {
                    continue;
                }
                foreach ($raw[$metricMapKey] as $metricKey => $metricValue) {
                    $metricKey = strtolower(trim((string)$metricKey));
                    if ($metricKey === '') {
                        continue;
                    }
                    $rawMetricKeys[$metricKey] = true;
                    $this->appendCollectionMetricPreviewValue($preview, $metricKey, $metricValue);
                }
            }
            foreach (is_array($raw['facts'] ?? null) ? $raw['facts'] : [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if ($metricKey === '') {
                    continue;
                }
                $rawMetricKeys[$metricKey] = true;
                $value = $fact['value'] ?? $fact['metric_value'] ?? $fact['data_value'] ?? null;
                $this->appendCollectionMetricPreviewValue($preview, $metricKey, $value);
            }
            if (!isset($preview['metric_key']) && $rawMetricKeys !== []) {
                $preview['metric_key'] = implode('+', array_slice(array_keys($rawMetricKeys), 0, 8));
            }
            foreach ([
                'amount_rank' => ['amountRank', 'amount_rank', 'bookingGMVrank', 'rankOfAmount', 'rank'],
                'quantity_rank' => ['quantityRank', 'quantity_rank', 'stayInRNrank', 'rankOfQuantity'],
                'book_order_num_rank' => ['bookOrderNumRank', 'book_order_num_rank', 'bookingOrdersrank', 'rankOfOrder'],
                'avg_price_rank' => ['avgPriceRank', 'avg_price_rank', 'priceRank', 'price_rank'],
                'competitor_average' => ['competitor_average', 'competitorAverage', 'competeAverage', 'avgValue'],
                'competitor_avg_price' => ['competitor_avg_price', 'competitorAvgPrice', 'competeAvgPrice', 'avgPrice'],
                'psi' => ['psi', 'PSI', 'psiScore', 'serviceScore', 'service_score'],
                'psi_score' => ['psi_score', 'psiScore', 'PSI'],
                'five_min_reply_rate' => ['five_min_reply_rate', 'fiveMinReplyRate', 'fiveMinuteReplyRate', 'replyRate5Min'],
                'reply_rate' => ['reply_rate', 'replyRate'],
                'comment_response_rate' => ['comment_response_rate', 'responseRate'],
                'review_environment_score' => ['review_environment_score', 'ratingLocation', 'environmentScore', 'envScore', 'surroundingScore'],
                'review_facility_score' => ['review_facility_score', 'ratingFacility', 'facilityScore', 'facilitiesScore', 'equipmentScore'],
                'review_service_score' => ['review_service_score', 'ratingService', 'reviewServiceScore', 'commentServiceScore', 'serviceRating'],
                'review_cleanliness_score' => ['review_cleanliness_score', 'ratingRoom', 'cleanlinessScore', 'cleanScore', 'hygieneScore'],
                'review_photo_count' => ['review_photo_count', 'hasPicCount'],
                'review_photo_rate' => ['review_photo_rate'],
                'hotel_collect' => ['hotel_collect', 'hotelCollect', 'collectCount', 'favoriteCount', 'favCount'],
                'ad_order_amount' => ['ad_order_amount', 'orderAmount', 'adOrderAmount', 'bookingAmount', 'gmv'],
                'roas' => ['roas', 'ROAS', 'roi'],
            ] as $target => $keys) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $raw) && $raw[$key] !== null && $raw[$key] !== '') {
                        $preview[$target] = $raw[$key];
                        break;
                    }
                }
            }
        }
        return $preview;
    }

    private function appendCollectionMetricPreviewValue(array &$preview, string $metricKey, mixed $value): void
    {
        $metricKey = strtolower(trim($metricKey));
        if ($metricKey === '' || is_array($value) || is_object($value) || $value === null || $value === '') {
            return;
        }
        $preview[$metricKey] = $value;
    }


    /**
     * 更新线上数据
     */


    private function buildCookieStatusRows(): array
    {
        $rows = [];
        $hotelIds = $this->visibleCookieHotelIds();

        foreach ($this->getConfigList('online_data_cookies_global') as $item) {
            $rows[] = $this->buildCookieHealth('generic', 'global', null, $item);
        }

        foreach ($hotelIds as $hotelId) {
            foreach ($this->getConfigList("online_data_cookies_hotel_{$hotelId}") as $item) {
                $rows[] = $this->buildCookieHealth('generic', 'hotel', (int)$hotelId, $item);
            }
        }

        foreach ($this->getStoredCtripConfigList() as $item) {
            $itemHotelId = (int)($item['hotel_id'] ?? $item['system_hotel_id'] ?? 0);
            if ($this->canSeeCookieHotel($itemHotelId)) {
                $rows[] = $this->buildCookieHealth('ctrip', $itemHotelId > 0 ? 'hotel' : 'global', $itemHotelId ?: null, $item);
            }
        }

        foreach ($this->getConfigList('meituan_config_list') as $item) {
            $itemHotelId = (int)($item['hotel_id'] ?? $item['system_hotel_id'] ?? 0);
            if ($this->canSeeCookieHotel($itemHotelId)) {
                $rows[] = $this->buildCookieHealth('meituan', $itemHotelId > 0 ? 'hotel' : 'global', $itemHotelId ?: null, $item);
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return $rows;
    }

    private function buildCookieHealth(string $platform, string $scope, ?int $hotelId, array $item): array
    {
        $name = (string)($item['name'] ?? $item['hotel_name'] ?? $item['config_name'] ?? $platform);
        $configId = trim((string)($item['id'] ?? ''));
        $cookieValue = (string)($item['cookies'] ?? $item['cookie'] ?? '');
        $updatedAt = (string)($item['update_time'] ?? $item['updated_at'] ?? $item['created_at'] ?? '');
        $timestamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $ageDays = $timestamp ? max(0, (int)floor((time() - $timestamp) / 86400)) : null;
        $hasAlert = false;
        $alertMessage = '';
        foreach ($this->getCookieAlerts() as $alert) {
            if (($alert['platform'] ?? '') === $platform && (string)($alert['name'] ?? '') === $name) {
                $hasAlert = true;
                $alertMessage = (string)($alert['message'] ?? '');
                break;
            }
        }
        $status = $this->resolveCookieHealthState($cookieValue, $ageDays, $hasAlert, $this->cookieWarningDays(), $this->cookieExpireDays());
        $reason = $status;
        if ($cookieValue === '') {
            $reason = 'empty';
        } elseif ($ageDays === null && !$hasAlert) {
            $reason = 'unknown';
        }
        $message = $hasAlert && $alertMessage !== ''
            ? $alertMessage
            : $this->cookieHealthMessage($platform, $reason, $ageDays);

        return array_merge([
            'platform' => $platform,
            'scope' => $scope,
            'hotel_id' => $hotelId,
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'next_action' => $status === 'ok' ? '' : '重新登录OTA后台并通过书签脚本或配置页更新授权',
            'updated_at' => $updatedAt,
            'age_days' => $ageDays,
            'has_cookie' => $cookieValue !== '',
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ], $this->cookieHealthPresentationMeta($platform, $status, $configId));
    }

    private function resolveCookieHealthState(string $cookieValue, ?int $ageDays, bool $hasAlert, int $warningDays, int $expireDays): string
    {
        if ($hasAlert || $cookieValue === '') {
            return 'expired';
        }
        if ($ageDays === null) {
            return 'unknown';
        }
        if ($ageDays >= $expireDays) {
            return 'expired';
        }
        if ($ageDays >= $warningDays) {
            return 'warning';
        }
        return 'ok';
    }

    private function cookieHealthMessage(string $platform, string $reason, ?int $ageDays): string
    {
        $label = $this->otaPlatformLabel($platform);
        return match ($reason) {
            'empty' => $label . ' Cookie为空，请重新登录OTA后台后更新授权。',
            'unknown' => $label . ' Cookie缺少更新时间，请重新保存一次配置以便系统判断有效期。',
            'expired' => $label . ' Cookie已超过' . $this->cookieExpireDays() . '天有效期阈值，请重新授权。',
            'warning' => $label . ' Cookie已使用' . (string)$ageDays . '天，接近' . $this->cookieExpireDays() . '天过期阈值，建议提前更新。',
            default => $label . ' Cookie状态正常。',
        };
    }

    private function cookieHealthPresentationMeta(string $platform, string $status, string $configId = ''): array
    {
        $platform = strtolower(trim($platform));
        $status = strtolower(trim($status));
        $isUsable = in_array($status, ['ok', 'warning', 'success'], true);
        $isCtripConfig = $platform === 'ctrip' && $configId !== '';

        $configSource = match ($platform) {
            'ctrip' => 'ctrip_config',
            'meituan' => 'meituan_config',
            default => 'cookie_config',
        };

        $actionHint = match (true) {
            $status === 'warning' => '建议提前更新',
            $isUsable => '可继续使用',
            default => '不可用，建议删除或重新授权',
        };

        return [
            'config_id' => $configId,
            'config_source' => $configSource,
            'editable' => $isCtripConfig,
            'deletable' => $isCtripConfig,
            'is_usable' => $isUsable,
            'light_status' => $isUsable ? 'green' : 'red',
            'light_label' => $isUsable ? '可用' : '不可用',
            'action_hint' => $actionHint,
        ];
    }

    private function cookieReauthorizeEntry(): string
    {
        return '/online-data?tab=cookies';
    }

    private function otaPlatformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'ctrip' => '携程',
            'qunar' => '去哪儿',
            'meituan' => '美团',
            default => 'OTA',
        };
    }

    private function visibleCookieHotelIds(): array
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            $hotelId = (int)($this->currentUser->hotel_id ?? 0);
            return $hotelId > 0 ? [$hotelId] : [];
        }

        try {
            return array_map('intval', \app\model\Hotel::column('id'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canSeeCookieHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return true;
        }
        return (int)($this->currentUser->hotel_id ?? 0) === $hotelId;
    }

    private function cookieWarningDays(): int
    {
        return max(1, (int)SystemConfig::getValue('ota_cookie_warning_days', '5'));
    }

    private function cookieExpireDays(): int
    {
        return max($this->cookieWarningDays(), (int)SystemConfig::getValue('ota_cookie_expire_days', '14'));
    }

    private function isCookieAuthError(string $message): bool
    {
        return preg_match('/cookie|login|auth|unauthorized|forbidden|expired|302|401|403|html|登录|授权|过期|失效|权限/i', $message) === 1;
    }

    private function recordCookieAlert(string $platform, string $name, string $message, ?int $hotelId = null): void
    {
        if (!$this->isCookieAuthError($message)) {
            return;
        }

        $alerts = $this->getCookieAlerts();
        $key = md5($platform . '|' . $name . '|' . (string)$hotelId);
        $alerts[$key] = [
            'platform' => $platform,
            'name' => $name,
            'hotel_id' => $hotelId,
            'message' => mb_substr($message, 0, 240),
            'created_at' => date('Y-m-d H:i:s'),
            'next_action' => '重新登录' . $this->otaPlatformLabel($platform) . '后台，复制最新Cookie或重新运行书签脚本。',
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ];
        $alerts = array_slice($alerts, -50, null, true);
        SystemConfig::setValue('ota_cookie_alerts', json_encode($alerts, JSON_UNESCAPED_UNICODE), 'OTA Cookie alerts');

        try {
            OperationLog::record('online_data', 'cookie_expired', 'OTA cookie needs reauthorization: ' . $platform . '/' . $name, $this->currentUser->id ?? null, $hotelId, $message);
            SystemNotification::recordEvent([
                'hotel_id' => $hotelId,
                'user_id' => (int)($this->currentUser->id ?? 0),
                'platform' => $platform,
                'category' => 'cookie_alert',
                'severity' => 'warning',
                'title' => $this->otaPlatformLabel($platform) . '授权需要更新',
                'message' => '平台授权状态异常，需要重新登录或更新 Cookie 后再采集。',
                'action_type' => 'cookie',
                'action_payload' => [
                    'target_page' => 'online-data',
                    'target_tab' => 'data-health',
                    'action_label' => '更新授权',
                ],
                'source_module' => 'online_data',
                'source_key' => 'cookie_alert:' . $platform . ':' . (int)($hotelId ?? 0) . ':' . substr(sha1($name), 0, 16),
            ]);
        } catch (\Throwable $e) {
            // Alert storage must not block OTA fetching.
        }
    }

    private function getCookieAlerts(): array
    {
        $raw = SystemConfig::getValue('ota_cookie_alerts', '{}');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function filterCollectionAuthorizationRows(array $rows, ?int $hotelId): array
    {
        if ($hotelId === null) {
            return array_values($rows);
        }

        return array_values(array_filter($rows, static function (array $row) use ($hotelId): bool {
            $rowHotelId = (int)($row['hotel_id'] ?? 0);
            return $rowHotelId === 0 || $rowHotelId === $hotelId;
        }));
    }

    private function buildCollectionAuthorizationSummary(array $rows): array
    {
        $counts = [
            'ok' => 0,
            'warning' => 0,
            'expired' => 0,
            'unknown' => 0,
            'waiting_config' => 0,
            'failed' => 0,
            'partial_success' => 0,
            'success' => 0,
            'not_collected' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? 'unknown');
            if (!array_key_exists($status, $counts)) {
                $status = 'unknown';
            }
            $counts[$status]++;
        }

        $overall = 'waiting_config';
        if ($counts['expired'] > 0) {
            $overall = 'expired';
        } elseif ($counts['failed'] > 0) {
            $overall = 'failed';
        } elseif ($counts['partial_success'] > 0) {
            $overall = 'partial_success';
        } elseif ($counts['not_collected'] > 0) {
            $overall = 'not_collected';
        } elseif ($counts['warning'] > 0 || $counts['unknown'] > 0) {
            $overall = 'warning';
        } elseif ($counts['ok'] > 0 || $counts['success'] > 0) {
            $overall = 'ok';
        }

        return array_merge([
            'overall_status' => $overall,
            'total' => count($rows),
        ], $counts);
    }


    private function collectionReliabilityStatusCatalog(): array
    {
        return ['ok', 'warning', 'expired', 'unknown', 'waiting_config', 'failed', 'partial_success', 'success', 'not_collected'];
    }

    private function collectionLifecycleCatalog(): array
    {
        return [
            [
                'stage' => 'platform_binding',
                'label' => '平台账号/Profile绑定',
                'evidence' => '/api/online-data/platform-profile-status.binding_checks',
                'blocking_risk' => '酒店绑定或平台身份不清会导致混批',
            ],
            [
                'stage' => 'authorization',
                'label' => '授权有效性',
                'evidence' => '/api/online-data/collection-reliability.authorization',
                'blocking_risk' => '登录或Cookie失效会导致采集失败',
            ],
            [
                'stage' => 'trial_capture',
                'label' => '试采集与日志',
                'evidence' => '/api/online-data/collection-reliability.collection_logs',
                'blocking_risk' => '无试采证据时摘要结论不可信',
            ],
            [
                'stage' => 'field_assets',
                'label' => '字段资产与口径',
                'evidence' => '/api/online-data/collection-reliability.field_definitions',
                'blocking_risk' => '字段缺口会影响收益分析和AI诊断',
            ],
            [
                'stage' => 'quality_gate',
                'label' => '入库质量门禁',
                'evidence' => '/api/online-data/collection-reliability.data_quality',
                'blocking_risk' => '空值、缺失、校验失败不能进入决策口径',
            ],
        ];
    }

    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            $value
        ), static fn(string $item): bool => $item !== ''));
    }

    private function normalizeCollectionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'ok', 'success', 'warning', 'expired', 'unknown', 'waiting_config', 'failed', 'partial_success', 'not_collected' => $status,
            'skip', 'skipped', 'empty', 'disabled' => 'waiting_config',
            'partial' => 'partial_success',
            'fail', 'error' => 'failed',
            default => 'unknown',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCollectionLogStatuses(array $logs): array
    {
        return array_map(function (array $log): array {
            $rawStatus = (string)($log['status'] ?? '');
            $log['raw_status'] = $rawStatus;
            $log['status'] = $this->normalizeCollectionStatus($rawStatus);
            return $log;
        }, $logs);
    }

    /**
     * @param array<int, array<string, mixed>> $authorizationRows
     * @param array<int, array<string, mixed>> $alerts
     * @param array<int, array<string, mixed>> $collectionLogs
     * @param array<int, array<string, mixed>> $qualityRows
     * @return array<int, array<string, mixed>>
     */
    private function buildCollectionPendingActions(array $authorizationRows, array $alerts, array $collectionLogs, array $qualityRows): array
    {
        $actions = [];
        foreach ($authorizationRows as $row) {
            $status = $this->normalizeCollectionStatus((string)($row['status'] ?? 'unknown'));
            if ($status === 'ok' || $status === 'success') {
                continue;
            }
            $actions[] = [
                'action_code' => 'ota_authorization_' . $status,
                'type' => 'authorization',
                'status' => $status,
                'platform' => (string)($row['platform'] ?? ''),
                'hotel_id' => $row['hotel_id'] ?? null,
                'reason' => (string)($row['message'] ?? $status),
                'action' => (string)($row['next_action'] ?? '重新授权 OTA 账号后重跑同步'),
                'next_action' => (string)($row['next_action'] ?? '重新授权 OTA 账号后重跑同步'),
                'entry' => (string)($row['reauthorize_entry'] ?? $this->cookieReauthorizeEntry()),
                'owner' => '酒店运营人员',
                'evidence_needed' => ['授权状态', '账号/Profile绑定', '重跑同步日志'],
                'protected_boundary' => '只处理授权和账号绑定，不改变携程/美团采集字段、字段映射或获取逻辑。',
            ];
        }

        foreach ($alerts as $alert) {
            $actions[] = [
                'action_code' => 'ota_authorization_alert',
                'type' => 'failure_reason',
                'status' => 'expired',
                'platform' => (string)($alert['platform'] ?? ''),
                'hotel_id' => $alert['hotel_id'] ?? null,
                'reason' => (string)($alert['message'] ?? ''),
                'action' => (string)($alert['next_action'] ?? '重新授权 OTA 账号后重跑同步'),
                'next_action' => (string)($alert['next_action'] ?? '重新授权 OTA 账号后重跑同步'),
                'entry' => (string)($alert['reauthorize_entry'] ?? $this->cookieReauthorizeEntry()),
                'owner' => '酒店运营人员',
                'evidence_needed' => ['授权告警', '账号/Profile绑定', '重跑同步日志'],
                'protected_boundary' => '只处理授权和账号绑定，不改变携程/美团采集字段、字段映射或获取逻辑。',
            ];
        }

        foreach ($collectionLogs as $log) {
            $status = $this->normalizeCollectionStatus((string)($log['status'] ?? ''));
            if (!in_array($status, ['failed', 'partial_success', 'waiting_config'], true)) {
                continue;
            }
            $actions[] = [
                'action_code' => 'ota_collection_' . $status,
                'type' => 'collection',
                'status' => $status,
                'platform' => (string)($log['platform'] ?? ''),
                'hotel_id' => $log['hotel_id'] ?? null,
                'reason' => (string)($log['message'] ?? ''),
                'action' => '检查授权、字段结构和平台响应后，使用现有手动或自动获取入口重试采集',
                'next_action' => '检查授权、字段结构和平台响应后，使用现有手动或自动获取入口重试采集',
                'entry' => '',
                'owner' => '产品/技术 + 酒店运营人员',
                'evidence_needed' => ['采集日志', '平台响应状态', 'validation_flags', 'source_trace_id 或 raw_data 追踪证据'],
                'protected_boundary' => '只复查下游状态和响应证据，不改变携程/美团手动或自动获取逻辑。',
            ];
        }

        if ($qualityRows === []) {
            $actions[] = [
                'action_code' => 'ota_same_period_source_rows_missing',
                'type' => 'collection_gap',
                'status' => 'not_collected',
                'platform' => 'ctrip,meituan',
                'hotel_id' => null,
                'reason' => '选定周期没有可用于经营诊断的 OTA 入库数据',
                'action' => '使用现有携程/美团手动或自动获取入口补齐同日数据，再查看字段可信度、收益指标、AI 诊断和执行动作',
                'next_action' => '使用现有携程/美团手动或自动获取入口补齐同日数据，再查看字段可信度、收益指标、AI 诊断和执行动作',
                'entry' => '/api/online-data/collection-reliability',
                'owner' => '酒店运营人员',
                'evidence_needed' => ['online_daily_data 同日期源数据行', 'data_source_id 或 sync_task_id', 'source_trace_id 或 raw_data 追踪证据'],
                'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑；不能用空数据生成经营结论。',
            ];
        }

        foreach ($qualityRows as $row) {
            $quality = $this->buildOnlineDataQuality($row);
            if (($quality['status'] ?? 'ok') === 'ok') {
                continue;
            }
            $actions[] = [
                'action_code' => 'ota_field_quality_' . $this->normalizeCollectionStatus((string)($quality['status'] ?? 'warning')),
                'type' => 'field_quality',
                'status' => $this->normalizeCollectionStatus((string)($quality['status'] ?? 'warning')),
                'platform' => (string)($row['source'] ?? ''),
                'hotel_id' => $row['system_hotel_id'] ?? $row['hotel_id'] ?? null,
                'reason' => (string)($quality['summary'] ?? ''),
                'action' => '复核缺失字段、原始响应路径和字段映射，缺字段继续保留 data_gaps',
                'next_action' => '复核缺失字段、原始响应路径和字段映射，缺字段继续保留 data_gaps',
                'entry' => '',
                'owner' => '产品/技术',
                'evidence_needed' => ['缺失字段列表', 'raw_data', 'source_trace_id', 'validation_flags'],
                'protected_boundary' => '不使用兜底值掩盖字段缺失，不把缺字段指标显示成可信。',
            ];
            if (count($actions) >= 20) {
                break;
            }
        }

        return array_slice($actions, 0, 20);
    }

    private function filterCollectionAlertsByHotel(array $alerts, ?int $hotelId): array
    {
        return array_values(array_filter($alerts, function (array $alert) use ($hotelId): bool {
            $alertHotelId = (int)($alert['hotel_id'] ?? 0);
            if ($hotelId !== null) {
                return $alertHotelId === 0 || $alertHotelId === $hotelId;
            }
            return $alertHotelId === 0 || $this->canSeeCookieHotel($alertHotelId);
        }));
    }

    private function buildCollectionLogRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $hotelIds = $hotelId !== null ? [$hotelId] : $this->resolveAutoFetchRecordHotelIds('');
        if (empty($hotelIds)) {
            $hotelIds = $this->visibleCookieHotelIds();
        }

        $hotelMap = $this->getAutoFetchRecordHotelMap($hotelIds);
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
        $rows = [];
        foreach ($hotelIds as $id) {
            $status = cache($this->autoFetchStatusKey((int)$id));
            if (!is_array($status)) {
                continue;
            }
            $rows = array_merge($rows, $this->buildAutoFetchRecordRows($status, (int)$id, (string)($hotelMap[(int)$id] ?? ('Hotel ID ' . $id)), $filters));
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['run_time'] ?? ''), (string)($a['run_time'] ?? '')));
        return array_slice($rows, 0, max(1, $limit));
    }

    private function buildCollectionFailureReasons(array $alerts, array $collectionLogs, int $limit): array
    {
        $rows = [];
        foreach ($alerts as $alert) {
            $rows[] = [
                'type' => 'authorization',
                'platform' => (string)($alert['platform'] ?? ''),
                'hotel_id' => $alert['hotel_id'] ?? null,
                'occurred_at' => (string)($alert['created_at'] ?? ''),
                'reason' => (string)($alert['message'] ?? ''),
                'next_action' => (string)($alert['next_action'] ?? ''),
                'source_ref' => 'SystemConfig.ota_cookie_alerts',
            ];
        }

        foreach ($collectionLogs as $log) {
            if (($log['status'] ?? '') !== 'failed') {
                continue;
            }
            $rows[] = [
                'type' => 'collection',
                'platform' => (string)($log['platform'] ?? ''),
                'hotel_id' => $log['hotel_id'] ?? null,
                'occurred_at' => (string)($log['run_time'] ?? ''),
                'data_date' => (string)($log['data_date'] ?? ''),
                'reason' => (string)($log['message'] ?? ''),
                'next_action' => '检查授权、字段结构和平台接口返回后重试采集',
                'source_ref' => 'cache.online_data_auto_fetch_status',
                'record_id' => (string)($log['id'] ?? ''),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['occurred_at'] ?? ''), (string)($a['occurred_at'] ?? '')));
        return array_slice($rows, 0, max(1, $limit));
    }

    private function buildOtaCollectionFieldDefinitions(): array
    {
        return [
            [
                'source' => 'ctrip',
                'module' => 'traffic',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'list_exposure', 'label' => '列表页曝光量', 'source_fields' => ['myHotel.totalListExposure', 'totalListExposure', 'listExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'detail_exposure', 'label' => '详情页访客量', 'source_fields' => ['myHotel.totalDetailExposure', 'totalDetailExposure', 'detailExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'flow_rate', 'label' => '曝光转化率', 'source_fields' => ['listTransforDetailRate', 'flowRate'], 'calculation' => '详情页访客量 / 列表页曝光量 * 100', 'required' => false],
                    ['field' => 'order_filling_num', 'label' => '订单页访客量', 'source_fields' => ['orderFillingNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                    ['field' => 'order_submit_num', 'label' => '订单提交人数', 'source_fields' => ['orderSubmitNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'ctrip',
                'module' => 'business',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'amount', 'label' => '营业额', 'source_fields' => ['amount', 'totalAmount', 'saleAmount'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'quantity', 'label' => '间夜量', 'source_fields' => ['quantity', 'roomNights', 'checkOutQuantity'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'book_order_num', 'label' => '订单数', 'source_fields' => ['bookOrderNum', 'orderCount', 'orders'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'comment_score', 'label' => '点评分', 'source_fields' => ['commentScore', 'score'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'meituan',
                'module' => 'business',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'data_value', 'label' => '榜单指标值', 'source_fields' => ['dataValue', 'monthRoomNights'], 'calculation' => '按美团榜单维度保存原始指标值', 'required' => true],
                    ['field' => 'dimension', 'label' => '榜单维度', 'source_fields' => ['dimension', 'dimName', '_dimName'], 'calculation' => '平台维度名称直接入库', 'required' => true],
                    ['field' => 'amount', 'label' => '营业额', 'source_fields' => ['amount', 'saleAmount'], 'calculation' => '如接口返回则直接入库', 'required' => false],
                    ['field' => 'quantity', 'label' => '间夜量', 'source_fields' => ['quantity', 'roomNights'], 'calculation' => '如接口返回则直接入库', 'required' => false],
                    ['field' => 'raw_data.platformTags', 'label' => 'VIP/平台标签', 'source_fields' => ['platformTags', 'tags', 'tagList', 'vipTag', 'isVip', 'vipFlag', 'memberFlag', 'crownTag'], 'calculation' => '平台返回门店标签原样归一化到 raw_data.platformTags；VIP 布尔值写入 raw_data.hasVipTag', 'required' => false, 'asset_status' => 'not_returned_visible'],
                    ['field' => 'raw_data.platformTagStatus', 'label' => '平台标签返回状态', 'source_fields' => ['platformTags', 'tags', 'tagList', 'vipTag', 'isVip'], 'calculation' => 'returned / returned_empty / not_returned，未返回时不推断VIP', 'required' => false, 'asset_status' => 'not_returned_visible'],
                ],
            ],
            [
                'source' => 'meituan',
                'module' => 'traffic',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'list_exposure', 'label' => '列表页曝光量', 'source_fields' => ['self_list_exposure', 'totalListExposure', 'listExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'detail_exposure', 'label' => '详情页访客量', 'source_fields' => ['self_detail_exposure', 'totalDetailExposure', 'detailExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'flow_rate', 'label' => '曝光转化率', 'source_fields' => ['flowRate'], 'calculation' => '详情页访客量 / 列表页曝光量 * 100', 'required' => false],
                    ['field' => 'order_filling_num', 'label' => '订单页访客量', 'source_fields' => ['self_order_filling_num', 'orderFillingNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                    ['field' => 'order_submit_num', 'label' => '订单提交人数', 'source_fields' => ['self_order_submit_num', 'orderSubmitNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'privacy_boundary',
                'module' => 'forbidden',
                'storage_table' => 'not_collected',
                'fields' => [
                    ['field' => 'guest_phone', 'label' => '客人手机号', 'source_fields' => [], 'calculation' => '禁止采集、禁止展示、禁止进入字段资产稳定口径', 'required' => false, 'asset_status' => 'forbidden'],
                    ['field' => 'order_phone', 'label' => '订单手机号', 'source_fields' => [], 'calculation' => '禁止采集、禁止展示、禁止进入字段资产稳定口径', 'required' => false, 'asset_status' => 'forbidden'],
                    ['field' => 'room_status', 'label' => '房态明细', 'source_fields' => [], 'calculation' => '当前不进入采集范围；如需授权接入必须另走产品审批', 'required' => false, 'asset_status' => 'forbidden'],
                    ['field' => 'room_source_mapping', 'label' => '房源映射', 'source_fields' => [], 'calculation' => '当前不进入采集范围；如需授权接入必须另走产品审批', 'required' => false, 'asset_status' => 'forbidden'],
                ],
            ],
        ];
    }

    private function normalizeOtaCollectionFieldAssetStatus(array $field): string
    {
        $status = strtolower(trim((string)($field['asset_status'] ?? '')));
        if (in_array($status, ['stable', 'optional', 'not_returned_visible', 'forbidden'], true)) {
            return $status;
        }
        return !empty($field['required']) ? 'stable' : 'optional';
    }

    private function summarizeOtaCollectionFieldDefinitions(array $definitions): array
    {
        $sources = [];
        $modules = [];
        $storageTables = [];
        $totalFields = 0;
        $requiredFields = 0;
        $statusCounts = [
            'stable' => 0,
            'optional' => 0,
            'not_returned_visible' => 0,
            'forbidden' => 0,
        ];
        $statusRows = [
            'stable' => [],
            'not_returned_visible' => [],
            'forbidden' => [],
        ];

        foreach ($definitions as $definition) {
            $source = trim((string)($definition['source'] ?? ''));
            $module = trim((string)($definition['module'] ?? ''));
            $storageTable = trim((string)($definition['storage_table'] ?? ''));
            if ($source !== '') {
                $sources[] = $source;
            }
            if ($source !== '' || $module !== '') {
                $modules[] = trim($source . ':' . $module, ':');
            }
            if ($storageTable !== '') {
                $storageTables[] = $storageTable;
            }
            foreach (($definition['fields'] ?? []) as $field) {
                $totalFields++;
                if (!empty($field['required'])) {
                    $requiredFields++;
                }
                $assetStatus = $this->normalizeOtaCollectionFieldAssetStatus(is_array($field) ? $field : []);
                $statusCounts[$assetStatus] = ($statusCounts[$assetStatus] ?? 0) + 1;
                if (isset($statusRows[$assetStatus]) && count($statusRows[$assetStatus]) < 12) {
                    $statusRows[$assetStatus][] = [
                        'source' => $source,
                        'module' => $module,
                        'field' => (string)($field['field'] ?? ''),
                        'label' => (string)($field['label'] ?? ($field['field'] ?? '')),
                        'storage_table' => $storageTable,
                        'asset_status' => $assetStatus,
                    ];
                }
            }
        }

        return [
            'source_count' => count(array_unique($sources)),
            'module_count' => count(array_unique($modules)),
            'field_count' => $totalFields,
            'required_field_count' => $requiredFields,
            'optional_field_count' => max(0, $totalFields - $requiredFields),
            'collectable_field_count' => max(0, $totalFields - (int)($statusCounts['forbidden'] ?? 0)),
            'stable_field_count' => (int)($statusCounts['stable'] ?? 0),
            'not_returned_field_count' => (int)($statusCounts['not_returned_visible'] ?? 0),
            'forbidden_field_count' => (int)($statusCounts['forbidden'] ?? 0),
            'status_counts' => $statusCounts,
            'stable_fields' => $statusRows['stable'],
            'not_returned_fields' => $statusRows['not_returned_visible'],
            'forbidden_fields' => $statusRows['forbidden'],
            'sources' => array_values(array_unique($sources)),
            'modules' => array_values(array_unique($modules)),
            'storage_tables' => array_values(array_unique($storageTables)),
        ];
    }

    public function dashboardAccountOverview(): Response
    {
        $this->checkPermission();

        try {
            [$startDate, $endDate] = $this->resolveDashboardDateRange();
            $hotelId = $this->resolveDashboardHotelId($this->request->get('hotel_id', $this->request->get('system_hotel_id', '')), false);
            $hotels = $this->loadDashboardHotels($hotelId);
            $qualityRows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, 5000);
            $reliability = $this->buildCollectionReliabilityPayload($hotelId, $startDate, $endDate);

            return $this->success($this->buildDashboardAccountOverview($reliability, $hotels, $qualityRows));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('账号级驾驶舱加载失败: ' . $e->getMessage());
        }
    }
    public function dashboardHotelPortrait(): Response
    {
        $this->checkPermission();

        try {
            [$startDate, $endDate] = $this->resolveDashboardDateRange();
            $hotelId = $this->resolveDashboardHotelId($this->request->get('hotel_id', $this->request->get('system_hotel_id', '')), true);
            $hotels = $this->loadDashboardHotels($hotelId);
            $hotel = $hotels[0] ?? ['id' => $hotelId, 'name' => $hotelId ? ('Hotel ID ' . $hotelId) : ''];
            $qualityRows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, 2000);
            $reliability = $this->buildCollectionReliabilityPayload($hotelId, $startDate, $endDate);

            return $this->success($this->buildDashboardHotelPortrait($reliability, $hotel, $qualityRows));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('单店画像加载失败: ' . $e->getMessage());
        }
    }
    public function dashboardDataSources(): Response
    {
        $this->checkPermission();

        try {
            [$startDate, $endDate] = $this->resolveDashboardDateRange();
            $hotelId = $this->resolveDashboardHotelId($this->request->get('hotel_id', $this->request->get('system_hotel_id', '')), false);
            $reliability = $this->buildCollectionReliabilityPayload($hotelId, $startDate, $endDate);

            return $this->success($this->buildDashboardDataSources($reliability));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $this->safeHttpCode($e->getCode()));
        } catch (\Throwable $e) {
            return $this->error('数据源状态加载失败: ' . $e->getMessage());
        }
    }
    private function resolveDashboardDateRange(): array
    {
        $days = max(1, min(90, (int)$this->request->get('days', 30)));
        $endDate = trim((string)$this->request->get('end_date', date('Y-m-d')));
        $startDate = trim((string)$this->request->get('start_date', date('Y-m-d', strtotime($endDate . ' -' . ($days - 1) . ' days'))));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new \InvalidArgumentException('日期格式错误，请使用 YYYY-MM-DD');
        }
        if (strtotime($startDate) === false || strtotime($endDate) === false || $startDate > $endDate) {
            throw new \InvalidArgumentException('日期范围无效');
        }

        return [$startDate, $endDate];
    }
    private function normalizeCollectionReliabilityMode($mode): string
    {
        return strtolower(trim((string)$mode)) === 'light' ? 'light' : 'full';
    }
    private function collectionReliabilityCacheKey(?int $hotelId, string $startDate, string $endDate, string $mode): string
    {
        $scope = 'guest';
        if ($this->currentUser) {
            if ($this->currentUser->isSuperAdmin()) {
                $scope = 'super';
            } else {
                $hotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
                sort($hotelIds);
                $scope = 'user_' . (int)($this->currentUser->id ?? 0) . '_' . implode('-', $hotelIds);
            }
        }

        return 'collection_reliability:' . md5(implode('|', [
            (string)($hotelId ?? 'all'),
            $startDate,
            $endDate,
            $mode,
            $scope,
        ]));
    }
    private function unloadedCollectionQualitySnapshot(): array
    {
        return [
            'status' => 'not_loaded',
            'score' => null,
            'grade' => 'not_loaded',
            'checked_records' => 0,
            'missing_count' => 0,
            'top_prompts' => [],
            'prompts' => [],
            'calculation_scope' => 'light_mode_not_loaded',
            'message' => 'Light mode does not load online_daily_data quality rows. Use mode=full for field quality evidence.',
        ];
    }
    private function buildCollectionReliabilityLightPayload(?int $hotelId, string $startDate, string $endDate): array
    {
        $periodDays = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $authorizationRows = $this->filterCollectionAuthorizationRows($this->buildCookieStatusRows(), $hotelId);
        $alerts = $this->filterCollectionAlertsByHotel($this->getCookieAlerts(), $hotelId);
        $collectionLogs = $this->buildCollectionLogRows($hotelId, $startDate, $endDate, 10);

        return [
            'mode' => 'light',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $periodDays,
            ],
            'hotel_id' => $hotelId,
            'status_catalog' => $this->collectionReliabilityStatusCatalog(),
            'collection_lifecycle_catalog' => $this->collectionLifecycleCatalog(),
            'authorization' => [
                'summary' => $this->buildCollectionAuthorizationSummary($authorizationRows),
                'list' => $authorizationRows,
                'reauthorize_entry' => $this->cookieReauthorizeEntry(),
            ],
            'failure_reasons' => $this->buildCollectionFailureReasons($alerts, $collectionLogs, 20),
            'collection_logs' => $this->normalizeCollectionLogStatuses($collectionLogs),
            'pending_actions' => $this->buildCollectionPendingActions($authorizationRows, $alerts, $collectionLogs, []),
            'source_date_evidence' => $this->buildCollectionSourceDateEvidence($hotelId, $endDate),
            'data_quality' => $this->unloadedCollectionQualitySnapshot(),
            'field_asset_summary' => [
                'status' => 'not_loaded',
                'message' => 'Light mode skips field definitions. Use mode=full for field asset evidence.',
            ],
            'field_definitions' => [],
            'history_replay' => [],
            'ctrip_capture_catalog' => ['status' => 'not_loaded', 'message' => 'Light mode skips capture catalog evidence.'],
            'ctrip_latest_capture' => ['available' => false, 'status' => 'not_loaded', 'message' => 'Light mode skips latest capture evidence.'],
            'ctrip_hotel_identity_filter' => ['status' => 'not_loaded', 'message' => 'Light mode skips hotel identity filter evidence.'],
            'cache' => [
                'ttl_seconds' => 45,
                'scope' => 'hotel_id + start_date + end_date + mode + user_scope',
            ],
        ];
    }
    private function buildCollectionReliabilityPayload(?int $hotelId, string $startDate, string $endDate): array
    {
        $periodDays = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $authorizationRows = $this->filterCollectionAuthorizationRows($this->buildCookieStatusRows(), $hotelId);
        $alerts = $this->filterCollectionAlertsByHotel($this->getCookieAlerts(), $hotelId);
        $collectionLogs = $this->buildCollectionLogRows($hotelId, $startDate, $endDate, 30);
        $qualityRows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, 2000);
        $ctripIdentityFilter = $this->buildCtripHotelIdentityFilterReport($hotelId, $startDate, $endDate, 2000);
        $fieldDefinitions = $this->buildOtaCollectionFieldDefinitions();

        return [
            'mode' => 'full',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $periodDays,
            ],
            'hotel_id' => $hotelId,
            'status_catalog' => $this->collectionReliabilityStatusCatalog(),
            'ctrip_capture_catalog' => $this->readCtripCaptureCatalogHealth(),
            'ctrip_latest_capture' => $this->readCtripLatestCaptureDashboard(),
            'ctrip_hotel_identity_filter' => $ctripIdentityFilter,
            'collection_lifecycle_catalog' => $this->collectionLifecycleCatalog(),
            'authorization' => [
                'summary' => $this->buildCollectionAuthorizationSummary($authorizationRows),
                'list' => $authorizationRows,
                'reauthorize_entry' => $this->cookieReauthorizeEntry(),
            ],
            'failure_reasons' => $this->buildCollectionFailureReasons($alerts, $collectionLogs, 20),
            'field_asset_summary' => $this->summarizeOtaCollectionFieldDefinitions($fieldDefinitions),
            'field_definitions' => $fieldDefinitions,
            'collection_logs' => $this->normalizeCollectionLogStatuses($collectionLogs),
            'history_replay' => $this->buildCollectionHistoryReplayRows($hotelId, $startDate, $endDate, 30),
            'source_date_evidence' => $this->buildCollectionSourceDateEvidence($hotelId, $endDate),
            'data_quality' => $this->buildCollectionQualitySnapshot($qualityRows),
            'pending_actions' => $this->buildCollectionPendingActions($authorizationRows, $alerts, $collectionLogs, $qualityRows),
        ];
    }
    private function resolveDashboardHotelId($input, bool $required): ?int
    {
        $hasInput = $input !== null && $input !== '' && is_numeric($input) && (int)$input > 0;
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                throw new HttpException(403, '无可访问酒店');
            }

            if ($hasInput) {
                $hotelId = (int)$input;
                if (!in_array($hotelId, $permittedHotelIds, true)) {
                    throw new HttpException(403, '无权访问该酒店');
                }
                return $hotelId;
            }

            return $required ? $permittedHotelIds[0] : null;
        }

        if ($hasInput) {
            return (int)$input;
        }

        if (!$required) {
            return null;
        }

        $hotels = $this->loadDashboardHotels(null);
        $first = $hotels[0]['id'] ?? null;
        return $first !== null ? (int)$first : null;
    }
    private function loadDashboardHotels(?int $hotelId): array
    {
        try {
            $query = \app\model\Hotel::field('id,name,status,create_time,update_time')
                ->where('status', \app\model\Hotel::STATUS_ENABLED);
            if ($hotelId !== null) {
                $query->where('id', $hotelId);
            }
            if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
                $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
                if (empty($permittedHotelIds)) {
                    return [];
                }
                $query->whereIn('id', $permittedHotelIds);
            }

            return $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return $hotelId !== null ? [['id' => $hotelId, 'name' => 'Hotel ID ' . $hotelId, 'status' => 1]] : [];
        }
    }
    private function dashboardDataStateCatalog(): array
    {
        return ['ok', 'zero', 'null', 'not_collected', 'auth_failed', 'request_failed', 'field_missing', 'warning'];
    }
    private function buildDashboardMetricValue(array $row, string $field, string $label, string $unit = '', string $source = 'online_daily_data'): array
    {
        $forcedStatus = strtolower(trim((string)($row['__collection_status'] ?? '')));
        if (in_array($forcedStatus, ['not_collected', 'auth_failed', 'request_failed', 'field_missing', 'null'], true)) {
            return [
                'key' => $field,
                'label' => $label,
                'value' => null,
                'display_value' => $this->dashboardStateLabel($forcedStatus),
                'unit' => $unit,
                'state' => $forcedStatus,
                'source' => $source,
                'evidence' => [
                    'field' => $field,
                    'reason' => $forcedStatus,
                ],
            ];
        }

        if (!array_key_exists($field, $row)) {
            return [
                'key' => $field,
                'label' => $label,
                'value' => null,
                'display_value' => $this->dashboardStateLabel('field_missing'),
                'unit' => $unit,
                'state' => 'field_missing',
                'source' => $source,
                'evidence' => [
                    'field' => $field,
                    'reason' => 'field_missing',
                ],
            ];
        }

        $value = $row[$field];
        if ($value === null || $value === '') {
            return [
                'key' => $field,
                'label' => $label,
                'value' => null,
                'display_value' => $this->dashboardStateLabel('null'),
                'unit' => $unit,
                'state' => 'null',
                'source' => $source,
                'evidence' => [
                    'field' => $field,
                    'raw_value' => $value,
                    'reason' => 'null',
                ],
            ];
        }

        $numeric = is_numeric($value) ? (float)$value : null;
        $state = $numeric !== null && abs($numeric) < 0.000001 ? 'zero' : 'ok';
        $display = is_float($value) || is_int($value) || is_numeric($value)
            ? rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.')
            : (string)$value;

        return [
            'key' => $field,
            'label' => $label,
            'value' => $value,
            'display_value' => $unit !== '' ? $display . $unit : $display,
            'unit' => $unit,
            'state' => $state,
            'source' => $source,
            'evidence' => [
                'field' => $field,
                'raw_value' => $value,
                'reason' => $state,
            ],
        ];
    }
    private function dashboardStateLabel(string $state): string
    {
        return [
            'ok' => '已采集',
            'zero' => '0',
            'null' => 'null',
            'not_collected' => '未采集',
            'auth_failed' => '授权失败',
            'request_failed' => '请求失败',
            'field_missing' => '字段缺失',
            'warning' => '需复核',
        ][$state] ?? $state;
    }
    private function buildDashboardDiagnosis(string $problem, array $evidence, string $impact, string $action, string $status = 'warning', string $severity = 'medium'): array
    {
        return [
            'problem' => $problem,
            'evidence' => $evidence,
            'impact' => $impact,
            'action' => $action,
            'status' => $status,
            'severity' => $severity,
        ];
    }
    private function buildDashboardAccountOverview(array $reliability, array $hotels, array $qualityRows): array
    {
        $hotelCount = count($hotels);
        $rowsByHotel = $this->groupDashboardRowsByHotel($qualityRows);
        $issueHotelIds = [];
        $completedHotelIds = [];

        foreach ($hotels as $hotel) {
            $hotelId = (int)($hotel['id'] ?? 0);
            $rows = $rowsByHotel[$hotelId] ?? [];
            if (empty($rows)) {
                continue;
            }
            if ($this->dashboardRowsCompleteForPortrait($rows)) {
                $completedHotelIds[$hotelId] = true;
            } else {
                $issueHotelIds[$hotelId] = true;
            }
        }

        foreach ($reliability['collection_logs'] ?? [] as $log) {
            if (in_array($this->normalizeCollectionStatus((string)($log['status'] ?? '')), ['failed', 'partial_success'], true)) {
                $logHotelId = (int)($log['hotel_id'] ?? 0);
                if ($logHotelId > 0) {
                    $issueHotelIds[$logHotelId] = true;
                }
            }
        }

        $syncStatus = $this->dashboardSyncStatus($reliability);
        $diagnostics = $this->buildDashboardAccountDiagnostics($reliability, $qualityRows, $syncStatus);
        $todayActions = $this->buildDashboardTodayActions($reliability, $diagnostics);

        return [
            'title' => '宿析OS · 酒店数据驾驶舱',
            'scope' => 'OTA channel scope; not whole-hotel operating truth unless explicitly marked.',
            'period' => $reliability['period'] ?? [],
            'status_catalog' => $this->dashboardDataStateCatalog(),
            'summary' => [
                'hotel_count' => $hotelCount,
                'portrait_completed_count' => count($completedHotelIds),
                'abnormal_hotel_count' => count($issueHotelIds),
                'sync_status' => $syncStatus,
                'latest_synced_at' => $this->dashboardLatestSyncedAt($reliability),
            ],
            'core_kpis' => $this->buildDashboardCoreKpis($qualityRows),
            'risk_alerts' => array_slice($diagnostics, 0, 6),
            'today_actions' => $todayActions,
            'diagnostics' => $diagnostics,
            'hotels' => array_map(static fn(array $hotel): array => [
                'id' => $hotel['id'] ?? null,
                'name' => (string)($hotel['name'] ?? ''),
            ], $hotels),
        ];
    }
    private function buildDashboardHotelPortrait(array $reliability, array $hotel, array $qualityRows): array
    {
        $hotelId = (int)($hotel['id'] ?? 0);
        $businessRow = $this->dashboardFirstRowByType($qualityRows, 'business');
        $trafficRow = $this->dashboardFirstRowByType($qualityRows, 'traffic');
        $notCollectedRow = ['__collection_status' => 'not_collected'];
        $quality = $this->buildCollectionQualitySnapshot($qualityRows);

        $sections = [
            $this->buildDashboardPortraitSection('basic', '基础', [
                $this->buildDashboardStaticMetric('hotel_id', '门店ID', $hotelId ?: null, $hotelId ? 'ok' : 'field_missing'),
                $this->buildDashboardStaticMetric('hotel_name', '门店名称', (string)($hotel['name'] ?? ''), (string)($hotel['name'] ?? '') !== '' ? 'ok' : 'field_missing'),
            ], []),
            $this->buildDashboardPortraitSection('business', '经营', [
                $this->buildDashboardMetricValue($businessRow ?: $notCollectedRow, 'amount', '营业额', '元'),
                $this->buildDashboardMetricValue($businessRow ?: $notCollectedRow, 'quantity', '间夜'),
                $this->buildDashboardMetricValue($businessRow ?: $notCollectedRow, 'book_order_num', '订单'),
            ], $this->buildDashboardSectionDiagnostics('经营', $businessRow, ['amount', 'quantity', 'book_order_num'])),
            $this->buildDashboardPortraitSection('traffic', '流量', [
                $this->buildDashboardMetricValue($trafficRow ?: $notCollectedRow, 'list_exposure', '列表页曝光'),
                $this->buildDashboardMetricValue($trafficRow ?: $notCollectedRow, 'detail_exposure', '详情页访客'),
            ], $this->buildDashboardSectionDiagnostics('流量', $trafficRow, ['list_exposure', 'detail_exposure'])),
            $this->buildDashboardPortraitSection('conversion', '转化', [
                $this->buildDashboardMetricValue($trafficRow ?: $notCollectedRow, 'flow_rate', '曝光转化率', '%'),
                $this->buildDashboardMetricValue($trafficRow ?: $notCollectedRow, 'order_submit_num', '订单提交人数'),
            ], $this->buildDashboardSectionDiagnostics('转化', $trafficRow, ['flow_rate', 'order_submit_num'])),
            $this->buildDashboardPortraitSection('price_inventory', '价格房态', [
                $this->buildDashboardMetricValue($notCollectedRow, 'price', '价格'),
                $this->buildDashboardMetricValue($notCollectedRow, 'inventory', '房态库存'),
            ], $this->buildDashboardModuleNotCollectedDiagnostics('价格房态')),
            $this->buildDashboardPortraitSection('competitor', '竞争', [
                $this->buildDashboardMetricValue($notCollectedRow, 'rank', '竞争圈排名'),
                $this->buildDashboardMetricValue($notCollectedRow, 'competitor_price', '竞品价格'),
            ], $this->buildDashboardModuleNotCollectedDiagnostics('竞争')),
            $this->buildDashboardPortraitSection('review_service', '点评服务', [
                $this->buildDashboardMetricValue($businessRow ?: $notCollectedRow, 'comment_score', '点评分'),
                $this->buildDashboardMetricValue($businessRow ?: $notCollectedRow, 'qunar_comment_score', '去哪儿评分'),
            ], $this->buildDashboardSectionDiagnostics('点评服务', $businessRow, ['comment_score', 'qunar_comment_score'])),
            $this->buildDashboardPortraitSection('im', 'IM', [
                $this->buildDashboardMetricValue($notCollectedRow, 'im_response_rate', 'IM响应率'),
                $this->buildDashboardMetricValue($notCollectedRow, 'im_avg_response_seconds', '平均响应时长'),
            ], $this->buildDashboardModuleNotCollectedDiagnostics('IM')),
            $this->buildDashboardPortraitSection('ads', '广告', [
                $this->buildDashboardMetricValue($notCollectedRow, 'ad_cost', '广告花费'),
                $this->buildDashboardMetricValue($notCollectedRow, 'roas', 'ROAS'),
            ], $this->buildDashboardModuleNotCollectedDiagnostics('广告')),
            $this->buildDashboardPortraitSection('customer', '客群', [
                $this->buildDashboardMetricValue($notCollectedRow, 'customer_segment', '主要客群'),
                $this->buildDashboardMetricValue($notCollectedRow, 'member_share', '会员占比'),
            ], $this->buildDashboardModuleNotCollectedDiagnostics('客群')),
            $this->buildDashboardPortraitSection('data_health', '数据健康', [
                $this->buildDashboardStaticMetric('quality_score', '质量分', $quality['score'] ?? null, ($quality['status'] ?? 'no_data') === 'no_data' ? 'not_collected' : 'ok'),
                $this->buildDashboardStaticMetric('missing_count', '字段缺失', $quality['missing_count'] ?? null, array_key_exists('missing_count', $quality) ? ((int)$quality['missing_count'] === 0 ? 'zero' : 'ok') : 'field_missing'),
            ], $this->buildDashboardDataHealthDiagnostics($quality)),
        ];

        return [
            'title' => '单店酒店数据画像',
            'hotel' => [
                'id' => $hotelId ?: null,
                'name' => (string)($hotel['name'] ?? ''),
            ],
            'period' => $reliability['period'] ?? [],
            'status_catalog' => $this->dashboardDataStateCatalog(),
            'sections' => $sections,
        ];
    }
    private function buildDashboardDataSources(array $reliability): array
    {
        if (!isset($reliability['phase1_employee_questions'])) {
            $reliability = $this->withPhase1EmployeeQuestions($reliability);
        }
        $authorization = $reliability['authorization'] ?? [];
        $summary = is_array($authorization['summary'] ?? null) ? $authorization['summary'] : [];
        $logs = is_array($reliability['collection_logs'] ?? null) ? $reliability['collection_logs'] : [];
        $quality = is_array($reliability['data_quality'] ?? null) ? $reliability['data_quality'] : [];
        $diagnostics = $this->buildDashboardAccountDiagnostics($reliability, [], $this->dashboardSyncStatus($reliability));

        return [
            'title' => '数据源状态 / 证据链',
            'scope' => 'OTA channel scope',
            'period' => $reliability['period'] ?? [],
            'status_catalog' => $this->dashboardDataStateCatalog(),
            'authorization' => $authorization,
            'collection_logs' => $logs,
            'data_quality' => $quality,
            'source_date_evidence' => $reliability['source_date_evidence'] ?? [],
            'collection_source_summary' => $reliability['collection_source_summary'] ?? [],
            'revenue_metric_evidence' => $reliability['phase1_revenue_metric_evidence'] ?? [],
            'operation_execution_evidence' => $reliability['phase1_operation_execution_evidence'] ?? [],
            'field_definitions' => $reliability['field_definitions'] ?? [],
            'ctrip_capture_catalog' => $reliability['ctrip_capture_catalog'] ?? [],
            'ctrip_latest_capture' => $reliability['ctrip_latest_capture'] ?? [],
            'failure_reasons' => $reliability['failure_reasons'] ?? [],
            'pending_actions' => $reliability['pending_actions'] ?? [],
            'diagnostics' => $diagnostics,
            'phase1_employee_questions' => $reliability['phase1_employee_questions'] ?? [],
            'legacy_collection_reliability' => $reliability,
            'summary' => [
                'authorization_status' => (string)($summary['overall_status'] ?? 'waiting_config'),
                'latest_log_status' => (string)($logs[0]['status'] ?? 'unknown'),
                'quality_status' => (string)($quality['status'] ?? 'no_data'),
            ],
        ];
    }
    private function buildDashboardCoreKpis(array $qualityRows): array
    {
        return [
            $this->buildDashboardAggregateMetric($qualityRows, 'amount', '核心经营 KPI：营业额', '元'),
            $this->buildDashboardAggregateMetric($qualityRows, 'quantity', '间夜量'),
            $this->buildDashboardAggregateMetric($qualityRows, 'book_order_num', '订单数'),
            $this->buildDashboardAggregateMetric($qualityRows, 'list_exposure', '列表页曝光'),
            $this->buildDashboardAggregateMetric($qualityRows, 'detail_exposure', '详情页访客'),
            $this->buildDashboardAggregateMetric($qualityRows, 'order_submit_num', '订单提交人数'),
        ];
    }
    private function buildDashboardAggregateMetric(array $rows, string $field, string $label, string $unit = ''): array
    {
        if (empty($rows)) {
            return $this->buildDashboardMetricValue(['__collection_status' => 'not_collected'], $field, $label, $unit);
        }

        $sum = 0.0;
        $hasNumeric = false;
        $missingCount = 0;
        $nullCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($field, $row)) {
                $missingCount++;
                continue;
            }
            if ($row[$field] === null || $row[$field] === '') {
                $nullCount++;
                continue;
            }
            if (is_numeric($row[$field])) {
                $sum += (float)$row[$field];
                $hasNumeric = true;
            }
        }

        if (!$hasNumeric) {
            $state = $missingCount > 0 ? 'field_missing' : 'null';
            return $this->buildDashboardMetricValue(['__collection_status' => $state], $field, $label, $unit);
        }

        $metric = $this->buildDashboardMetricValue([$field => $sum], $field, $label, $unit);
        $metric['evidence']['rows'] = count($rows);
        $metric['evidence']['missing_count'] = $missingCount;
        $metric['evidence']['null_count'] = $nullCount;
        return $metric;
    }
    private function groupDashboardRowsByHotel(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hotelId = (int)($row['system_hotel_id'] ?? $row['hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                continue;
            }
            $grouped[$hotelId][] = $row;
        }
        return $grouped;
    }
    private function dashboardRowsCompleteForPortrait(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtolower((string)($row['data_type'] ?? 'business'));
            if ($type !== 'business') {
                continue;
            }
            $hasCoreBusiness = true;
            foreach (['amount', 'quantity', 'book_order_num'] as $field) {
                if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                    $hasCoreBusiness = false;
                    break;
                }
            }
            if ($hasCoreBusiness) {
                return true;
            }
        }

        return false;
    }
    private function dashboardFirstRowByType(array $rows, string $type): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && strtolower((string)($row['data_type'] ?? 'business')) === $type) {
                return $row;
            }
        }
        return [];
    }
    private function dashboardSyncStatus(array $reliability): string
    {
        $authStatus = (string)($reliability['authorization']['summary']['overall_status'] ?? 'waiting_config');
        if (in_array($authStatus, ['expired', 'failed'], true)) {
            return 'auth_failed';
        }

        foreach ($reliability['collection_logs'] ?? [] as $log) {
            if ($this->normalizeCollectionStatus((string)($log['status'] ?? '')) === 'failed') {
                return 'request_failed';
            }
        }

        $qualityStatus = (string)($reliability['data_quality']['status'] ?? 'no_data');
        if ($qualityStatus === 'no_data') {
            return 'not_collected';
        }
        if ((int)($reliability['data_quality']['missing_count'] ?? 0) > 0) {
            return 'field_missing';
        }
        if ($qualityStatus !== 'ok') {
            return 'warning';
        }

        return 'ok';
    }
    private function dashboardLatestSyncedAt(array $reliability): string
    {
        $logs = is_array($reliability['collection_logs'] ?? null) ? $reliability['collection_logs'] : [];
        if (!empty($logs[0]['run_time'])) {
            return (string)$logs[0]['run_time'];
        }
        return (string)($reliability['ctrip_latest_capture']['captured_at'] ?? '');
    }
    private function buildDashboardAccountDiagnostics(array $reliability, array $qualityRows, string $syncStatus): array
    {
        $diagnostics = [];
        $authStatus = (string)($reliability['authorization']['summary']['overall_status'] ?? 'waiting_config');
        if (in_array($syncStatus, ['auth_failed', 'not_collected'], true)) {
            $diagnostics[] = $this->buildDashboardDiagnosis(
                $syncStatus === 'auth_failed' ? 'OTA授权不可用' : 'OTA数据未采集',
                ['authorization_status' => $authStatus, 'hotel_id' => $reliability['hotel_id'] ?? null],
                '账号级驾驶舱和单店画像无法形成完整 OTA 经营口径',
                $syncStatus === 'auth_failed' ? '重新授权携程/美团账号后重跑同步' : '完成门店授权并执行自动同步',
                $syncStatus,
                $syncStatus === 'auth_failed' ? 'high' : 'medium'
            );
        }

        foreach ($reliability['collection_logs'] ?? [] as $log) {
            if ($this->normalizeCollectionStatus((string)($log['status'] ?? '')) !== 'failed') {
                continue;
            }
            $diagnostics[] = $this->buildDashboardDiagnosis(
                'OTA同步请求失败',
                [
                    'platform' => (string)($log['platform'] ?? ''),
                    'hotel_id' => $log['hotel_id'] ?? null,
                    'message' => (string)($log['message'] ?? ''),
                    'run_time' => (string)($log['run_time'] ?? ''),
                ],
                '该门店本轮经营、流量、转化等画像数据可能缺口',
                '检查授权、请求参数和平台响应后重试采集',
                'request_failed',
                'high'
            );
        }

        $quality = is_array($reliability['data_quality'] ?? null) ? $reliability['data_quality'] : [];
        if ((int)($quality['missing_count'] ?? 0) > 0) {
            $diagnostics[] = $this->buildDashboardDiagnosis(
                '存在字段缺失',
                [
                    'missing_count' => (int)($quality['missing_count'] ?? 0),
                    'top_prompts' => $quality['top_prompts'] ?? [],
                ],
                '相关 KPI 不能按 0 处理，行动建议需标记为待补采',
                '补齐字段映射或重新采集对应 OTA 模块',
                'field_missing',
                'medium'
            );
        }

        if (empty($qualityRows) && empty($diagnostics)) {
            $diagnostics[] = $this->buildDashboardDiagnosis(
                '选定周期无结构化经营数据',
                ['period' => $reliability['period'] ?? []],
                '无法生成账号级核心经营 KPI',
                '先完成 OTA 授权并执行一次自动同步',
                'not_collected',
                'medium'
            );
        }

        return $diagnostics;
    }
    private function buildDashboardTodayActions(array $reliability, array $diagnostics): array
    {
        $actions = [];
        foreach ($reliability['pending_actions'] ?? [] as $item) {
            $actions[] = [
                'title' => (string)($item['reason'] ?? $item['type'] ?? '待处理'),
                'action' => (string)($item['action'] ?? ''),
                'status' => (string)($item['status'] ?? 'warning'),
                'evidence' => $item,
            ];
            if (count($actions) >= 6) {
                return $actions;
            }
        }

        foreach ($diagnostics as $diagnosis) {
            $actions[] = [
                'title' => (string)($diagnosis['problem'] ?? '待处理'),
                'action' => (string)($diagnosis['action'] ?? ''),
                'status' => (string)($diagnosis['status'] ?? 'warning'),
                'evidence' => $diagnosis['evidence'] ?? [],
            ];
            if (count($actions) >= 6) {
                break;
            }
        }

        return $actions;
    }
    private function buildDashboardStaticMetric(string $key, string $label, $value, string $state = 'ok', string $unit = ''): array
    {
        $display = $value === null || $value === '' ? $this->dashboardStateLabel($state) : (string)$value;
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'display_value' => $unit !== '' && $value !== null && $value !== '' ? $display . $unit : $display,
            'unit' => $unit,
            'state' => $state,
            'source' => 'dashboard',
            'evidence' => [
                'field' => $key,
                'raw_value' => $value,
                'reason' => $state,
            ],
        ];
    }
    private function buildDashboardPortraitSection(string $key, string $label, array $metrics, array $diagnostics): array
    {
        $status = 'ok';
        foreach ($metrics as $metric) {
            $state = (string)($metric['state'] ?? 'ok');
            if (in_array($state, ['auth_failed', 'request_failed'], true)) {
                $status = $state;
                break;
            }
            if (in_array($state, ['not_collected', 'field_missing', 'null'], true) && $status === 'ok') {
                $status = $state;
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'metrics' => $metrics,
            'diagnostics' => $diagnostics,
        ];
    }
    private function buildDashboardSectionDiagnostics(string $label, array $row, array $fields): array
    {
        if (empty($row)) {
            return $this->buildDashboardModuleNotCollectedDiagnostics($label);
        }

        $diagnostics = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                $diagnostics[] = $this->buildDashboardDiagnosis(
                    $label . '字段缺失',
                    ['field' => $field],
                    $label . '模块对应指标不能按 0 进入诊断',
                    '补齐字段映射或重新采集该模块',
                    'field_missing',
                    'medium'
                );
            } elseif ($row[$field] === null || $row[$field] === '') {
                $diagnostics[] = $this->buildDashboardDiagnosis(
                    $label . '字段为空',
                    ['field' => $field, 'value' => $row[$field]],
                    '该指标为空值，不能替代为 0',
                    '检查 OTA 返回字段是否为空或解析丢失',
                    'null',
                    'medium'
                );
            }
        }

        return $diagnostics;
    }
    private function buildDashboardModuleNotCollectedDiagnostics(string $label): array
    {
        return [
            $this->buildDashboardDiagnosis(
                $label . '未采集',
                ['module' => $label, 'state' => 'not_collected'],
                $label . '画像暂不能用于账号级决策',
                '完成对应 OTA 模块授权、采集和字段映射',
                'not_collected',
                'medium'
            ),
        ];
    }
    private function buildDashboardDataHealthDiagnostics(array $quality): array
    {
        if (($quality['status'] ?? 'no_data') === 'no_data') {
            return $this->buildDashboardModuleNotCollectedDiagnostics('数据健康');
        }
        if (($quality['status'] ?? 'ok') === 'ok') {
            return [];
        }

        return [
            $this->buildDashboardDiagnosis(
                '数据健康存在异常',
                [
                    'status' => (string)($quality['status'] ?? ''),
                    'missing_count' => (int)($quality['missing_count'] ?? 0),
                    'abnormal_count' => (int)($quality['abnormal_count'] ?? 0),
                ],
                '画像可信度下降，部分 KPI 需要证据复核',
                '按字段质量提示补采或修正映射',
                (int)($quality['missing_count'] ?? 0) > 0 ? 'field_missing' : 'warning',
                'medium'
            ),
        ];
    }

    /**
     * 定时任务触发接口（供外部cron调用）
     * 每分钟调用一次，检查是否有需要执行的自动获取任务
     */

    public function cookieStatus(): Response
    {
        $this->checkPermission();
        return $this->success([
            'list' => $this->buildCookieStatusRows(),
            'alerts' => $this->getCookieAlerts(),
            'warning_days' => $this->cookieWarningDays(),
            'expire_days' => $this->cookieExpireDays(),
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ]);
    }

    public function collectionReliability(): Response
    {
        $this->checkPermission();

        $hotelIdRaw = $this->request->get('hotel_id', $this->request->get('system_hotel_id', ''));
        $hotelId = $this->resolveOnlineDataSystemHotelId($hotelIdRaw);
        [$startDate, $endDate] = $this->resolveDashboardDateRange();
        $mode = $this->normalizeCollectionReliabilityMode($this->request->get('mode', 'full'));

        try {
            if ($mode === 'light') {
                $cacheKey = $this->collectionReliabilityCacheKey($hotelId, $startDate, $endDate, $mode);
                $cached = cache($cacheKey);
                if (is_array($cached)) {
                    return $this->success($cached);
                }
                $payload = $this->withPhase1EmployeeQuestions(
                    $this->buildCollectionReliabilityLightPayload($hotelId, $startDate, $endDate)
                );
                cache($cacheKey, $payload, 45);
                return $this->success($payload);
            }

            return $this->success($this->withPhase1EmployeeQuestions(
                $this->buildCollectionReliabilityPayload($hotelId, $startDate, $endDate)
            ));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('采集可靠性查询失败: ' . $e->getMessage());
        }
    }
}
