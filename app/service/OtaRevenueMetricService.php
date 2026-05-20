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
}
