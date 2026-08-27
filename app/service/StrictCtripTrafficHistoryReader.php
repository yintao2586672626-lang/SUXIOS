<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Windowed Ctrip history reader for revenue recommendations.
 * Canonical trust belongs exclusively to CanonicalOtaHistoryReceiptVerifier;
 * this class only applies window bounds, row limits and exact readback.
 */
final class StrictCtripTrafficHistoryReader
{
    public const WINDOW_ROW_LIMIT = CanonicalOtaHistoryReceiptVerifier::MAX_WINDOW_CANDIDATE_ROWS;

    private const OUTPUT_COLUMNS = [
        'id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id',
        'source', 'platform', 'data_date', 'data_period', 'data_type',
        'readback_verified', 'history_status', 'validation_status', 'ingestion_method',
        'source_trace_id', 'raw_data', 'list_exposure', 'detail_exposure', 'flow_rate',
        'order_filling_num', 'order_submit_num', 'book_order_num', 'quantity',
    ];

    public function __construct(
        private ?CanonicalOtaHistoryReceiptVerifier $receiptVerifier = null
    ) {
        $this->receiptVerifier ??= new CanonicalOtaHistoryReceiptVerifier();
    }

    /** @return array<string,mixed> */
    public function read(int $systemHotelId, string $startDate, string $endDate): array
    {
        $results = $this->readBatch($systemHotelId, [
            'single' => ['start' => $startDate, 'end' => $endDate],
        ]);
        return $results['single'] ?? $this->blocked(
            $systemHotelId,
            $startDate,
            $endDate,
            ['ctrip_traffic_history_query_failed']
        );
    }

    /**
     * @param array<string,array{start:string,end:string}> $windows
     * @return array<string,array<string,mixed>>
     */
    public function readBatch(int $systemHotelId, array $windows): array
    {
        if ($windows === []) {
            return [];
        }
        $results = [];
        $validWindows = [];
        foreach ($windows as $key => $window) {
            $start = trim((string)($window['start'] ?? ''));
            $end = trim((string)($window['end'] ?? ''));
            if (!$this->isDate($start) || !$this->isDate($end) || $start > $end) {
                $results[(string)$key] = $this->blocked(
                    $systemHotelId,
                    $start,
                    $end,
                    ['ctrip_traffic_history_date_range_invalid']
                );
                continue;
            }
            $validWindows[(string)$key] = ['start' => $start, 'end' => $end];
        }
        if ($validWindows === []) {
            return $results;
        }

        $authority = $this->receiptVerifier->verifyWindows($systemHotelId, $validWindows);
        $tenantId = $this->authoritativeTenantId($systemHotelId);
        $queryWindows = [];
        foreach ($validWindows as $key => $window) {
            $proof = is_array($authority[$key] ?? null) ? $authority[$key] : [];
            $gaps = array_values(array_filter(array_map(
                'strval',
                is_array($proof['data_gaps'] ?? null) ? $proof['data_gaps'] : []
            )));
            $rowIds = $this->positiveIds($proof['authoritative_row_ids'] ?? []);
            if (($proof['status'] ?? '') !== 'ready' || $gaps !== [] || $rowIds === []) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    $gaps !== [] ? $gaps : ['canonical_ota_history_authoritative_rows_missing'],
                    $proof
                );
                continue;
            }
            if (count($rowIds) > self::WINDOW_ROW_LIMIT
                || (int)($proof['candidate_row_count'] ?? 0) > self::WINDOW_ROW_LIMIT
            ) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    ['ctrip_traffic_history_row_limit_exceeded'],
                    $proof
                );
                continue;
            }
            $queryWindows[$key] = $window + ['row_ids' => $rowIds, 'proof' => $proof];
        }
        if ($queryWindows === []) {
            return $results;
        }
        if ($tenantId <= 0) {
            foreach ($queryWindows as $key => $window) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    ['ctrip_traffic_history_hotel_tenant_unavailable'],
                    $window['proof']
                );
            }
            return $results;
        }

        try {
            $rowsByWindow = $this->queryWindows($tenantId, $systemHotelId, $queryWindows);
        } catch (\Throwable) {
            foreach ($queryWindows as $key => $window) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    ['ctrip_traffic_history_query_failed'],
                    $window['proof']
                );
            }
            return $results;
        }
        foreach ($queryWindows as $key => $window) {
            $rows = $rowsByWindow[$key] ?? [];
            $expectedIds = $window['row_ids'];
            $actualIds = $this->positiveIds(array_column($rows, 'id'));
            if (count($rows) > self::WINDOW_ROW_LIMIT) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    ['ctrip_traffic_history_row_limit_exceeded'],
                    $window['proof']
                );
                continue;
            }
            if ($actualIds !== $expectedIds) {
                $results[$key] = $this->blocked(
                    $systemHotelId,
                    $window['start'],
                    $window['end'],
                    ['canonical_ota_history_exact_readback_mismatch'],
                    $window['proof']
                );
                continue;
            }
            foreach ($rows as &$row) {
                $row['_strict_traffic_history_verified'] = true;
            }
            unset($row);
            $proof = $window['proof'];
            $results[$key] = [
                'data_status' => 'ready',
                'rows' => $rows,
                'data_gaps' => [],
                'data_quality' => [
                    'queried_rows' => count($rows),
                    'trusted_rows' => count($rows),
                    'rejected_rows' => 0,
                    'rejected_reasons' => [],
                    'candidate_rows' => (int)($proof['candidate_row_count'] ?? count($rows)),
                    'authoritative_rows' => (int)($proof['authoritative_row_count'] ?? count($rows)),
                    'ignored_unselected_rows' => (int)($proof['ignored_unselected_row_count'] ?? 0),
                    'window_row_limit' => self::WINDOW_ROW_LIMIT,
                ],
                'query_scope' => [
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $systemHotelId,
                    'platform' => 'ctrip',
                    'start_date' => $window['start'],
                    'end_date' => $window['end'],
                ],
            ];
        }
        return $results;
    }

    /**
     * Each branch receives at most 2,000 canonical IDs plus seven fixed binds.
     * The bounded 31-window caller therefore uses at most 62,217 parameters.
     *
     * @param array<string,array<string,mixed>> $windows
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function queryWindows(int $tenantId, int $systemHotelId, array $windows): array
    {
        $fields = implode(',', array_map(
            static fn(string $field): string => '`' . $field . '`',
            self::OUTPUT_COLUMNS
        ));
        $branches = [];
        $bindings = [];
        foreach ($windows as $windowKey => $window) {
            $rowIds = $this->positiveIds($window['row_ids'] ?? []);
            $branches[] = 'SELECT ? AS `__window_key`, scoped.* FROM ('
                . 'SELECT ' . $fields . ' FROM `online_daily_data` WHERE '
                . '`tenant_id` = ? AND `system_hotel_id` = ? '
                . 'AND `source` = ? AND `platform` = ? '
                . 'AND `data_date` BETWEEN ? AND ? '
                . 'AND `readback_verified` = 1 '
                . "AND `history_status` = 'success' AND `validation_status` = 'verified' "
                . 'AND `id` IN (' . implode(',', array_fill(0, count($rowIds), '?')) . ') '
                . 'ORDER BY `data_date` ASC, `id` ASC LIMIT ' . (self::WINDOW_ROW_LIMIT + 1)
                . ') AS scoped';
            array_push($bindings, ...array_merge(
                [
                    (string)$windowKey,
                    $tenantId,
                    $systemHotelId,
                    'ctrip',
                    'ctrip',
                    (string)$window['start'],
                    (string)$window['end'],
                ],
                $rowIds
            ));
        }
        $rows = Db::query(implode(' UNION ALL ', $branches), $bindings);
        $grouped = array_fill_keys(array_keys($windows), []);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string)($row['__window_key'] ?? '');
            unset($row['__window_key']);
            if (array_key_exists($key, $grouped)) {
                $grouped[$key][] = $row;
            }
        }
        foreach ($grouped as &$windowRows) {
            usort($windowRows, static function (array $left, array $right): int {
                $dateOrder = strcmp(
                    (string)($left['data_date'] ?? ''),
                    (string)($right['data_date'] ?? '')
                );
                return $dateOrder !== 0
                    ? $dateOrder
                    : (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
            });
        }
        unset($windowRows);
        return $grouped;
    }

    /** @return array<string,mixed> */
    private function blocked(
        int $systemHotelId,
        string $startDate,
        string $endDate,
        array $gaps,
        array $proof = []
    ): array {
        return [
            'data_status' => 'blocked',
            'rows' => [],
            'data_gaps' => array_values(array_unique(array_filter(array_map('strval', $gaps)))),
            'data_quality' => [
                'queried_rows' => 0,
                'trusted_rows' => 0,
                'rejected_rows' => max(0, (int)($proof['candidate_row_count'] ?? 0)
                    - (int)($proof['ignored_unselected_row_count'] ?? 0)),
                'rejected_reasons' => [],
                'candidate_rows' => (int)($proof['candidate_row_count'] ?? 0),
                'authoritative_rows' => (int)($proof['authoritative_row_count'] ?? 0),
                'ignored_unselected_rows' => (int)($proof['ignored_unselected_row_count'] ?? 0),
                'window_row_limit' => self::WINDOW_ROW_LIMIT,
            ],
            'query_scope' => [
                'system_hotel_id' => $systemHotelId,
                'platform' => 'ctrip',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ];
    }

    private function authoritativeTenantId(int $systemHotelId): int
    {
        if ($systemHotelId <= 0) {
            return 0;
        }
        try {
            return max(0, (int)Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim($value)) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
