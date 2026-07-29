<?php
declare(strict_types=1);

namespace tests;

use app\service\MeituanTemporalService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MeituanTemporalServiceTest extends TestCase
{
    public function testTodayUsesOneSnapshotPreservesZeroAndDerivesOnlyInsideIt(): void
    {
        $rows = [
            $this->row(80, 101, 'business', '2026-07-29', [
                'amount' => 2026.78,
                'quantity' => 2,
                'book_order_num' => 1,
                'data_value' => null,
                'list_exposure' => 81,
                'detail_exposure' => 81,
                'flow_rate' => null,
            ], [
                'lead_price' => 868,
                'sales_amount' => 2026.78,
                'sales_room_nights' => 2,
                'sales_avg_price' => null,
                'exposure_users' => 81,
                'detail_visitors' => 81,
                'paid_order_count' => 1,
                'browse_to_pay_rate' => null,
            ], [
                'lead_price', 'sales_amount', 'sales_room_nights',
                'exposure_users', 'detail_visitors', 'paid_order_count',
            ]),
        ];

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            $rows,
            80,
            '2026-07-29',
            new DateTimeImmutable('2026-07-29 18:00:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('ready', $summary['today']['status']);
        self::assertSame(1013.39, $summary['today']['metrics']['sales_avg_price']['value']);
        self::assertSame('derived', $summary['today']['metrics']['sales_avg_price']['status']);
        self::assertSame(1.23, $summary['today']['metrics']['browse_to_pay_rate']['value']);
        self::assertSame('derived', $summary['today']['metrics']['browse_to_pay_rate']['status']);
    }

    public function testExplicitZeroIsVerifiedAndMissingIsNotReplaced(): void
    {
        $row = $this->row(80, 102, 'business', '2026-07-29', [
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'data_value' => null,
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
        ], [
            'lead_price' => 542.24,
            'sales_amount' => 0,
            'sales_room_nights' => 0,
            'sales_avg_price' => null,
            'exposure_users' => 0,
            'detail_visitors' => 0,
            'paid_order_count' => 0,
            'browse_to_pay_rate' => 0,
        ], [
            'lead_price', 'sales_amount', 'sales_room_nights', 'exposure_users',
            'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows([$row], 80, '2026-07-29');
        self::assertSame(0, $summary['today']['metrics']['sales_amount']['value']);
        self::assertSame('verified', $summary['today']['metrics']['sales_amount']['status']);
        self::assertNull($summary['today']['metrics']['sales_avg_price']['value']);
        self::assertSame('missing', $summary['today']['metrics']['sales_avg_price']['status']);
        self::assertSame(0, $summary['today']['metrics']['browse_to_pay_rate']['value']);
        self::assertSame('verified', $summary['today']['metrics']['browse_to_pay_rate']['status']);
    }

    public function testLatestIncompleteSnapshotDoesNotBorrowOlderValues(): void
    {
        $complete = $this->completeBusinessRow(80, 201, '2026-07-29', '2026-07-29 10:00:00');
        $latest = $this->row(80, 202, 'business', '2026-07-29', [
            'amount' => 500,
        ], [
            'sales_amount' => 500,
        ], ['sales_amount'], '2026-07-29 18:00:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows([$complete, $latest], 80, '2026-07-29');
        self::assertSame('partial', $summary['today']['status']);
        self::assertSame(500, $summary['today']['metrics']['sales_amount']['value']);
        self::assertNull($summary['today']['metrics']['lead_price']['value']);
        self::assertSame(201, $summary['today']['latest_verified_reference']['sync_task_id']);
    }

    public function testHotelIsolationAndYesterdayNineOClockGate(): void
    {
        $hotel80 = $this->completeBusinessRow(80, 301, '2026-07-28', '2026-07-29 08:30:00');
        $hotel81 = $this->completeBusinessRow(81, 999, '2026-07-28', '2026-07-29 08:59:00');
        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$hotel80, $hotel81],
            80,
            '2026-07-29',
            new DateTimeImmutable('2026-07-29 08:59:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('pending_source_update', $summary['yesterday']['status']);
        self::assertSame(301, $summary['yesterday']['metrics']['sales_amount']['sync_task_id']);
    }

    public function testFutureRowsRequireSemanticTypesAndKeepPeerAverages(): void
    {
        $rows = [];
        foreach (['pv' => [31, 11], 'uv' => [20, 8], 'advance_orders' => [2, 0.5]] as $type => [$current, $peer]) {
            $rows[] = $this->row(80, 401, 'traffic_forecast', '2026-07-30', [
                'data_value' => $current,
                'dimension' => 'traffic_forecast:' . $type,
            ], [
                'forecast_type' => $type,
                'current' => $current,
                'peer_avg' => $peer,
            ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 18:08:00');
        }
        $rows[] = $this->row(80, 401, 'traffic_forecast', '2026-07-31', [
            'data_value' => 999,
            'dimension' => 'traffic_forecast:1',
        ], [
            'forecast_type' => '1',
            'current' => 999,
        ], ['forecast_current'], '2026-07-29 18:08:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows($rows, 80, '2026-07-29');
        self::assertCount(1, $summary['future']['rows']);
        self::assertSame(31, $summary['future']['rows'][0]['metrics']['pv']['value']);
        self::assertSame(11, $summary['future']['rows'][0]['metrics']['pv_peer_avg']['value']);
        self::assertSame(0.5, $summary['future']['rows'][0]['metrics']['advance_orders_peer_avg']['value']);
        self::assertSame('ready', $summary['future']['rows'][0]['status']);
    }

    public function testFutureLatestSnapshotDoesNotBlendOlderTabsAndKeepsReferenceSeparate(): void
    {
        $rows = [];
        $start = new DateTimeImmutable('2026-07-30', new DateTimeZone('Asia/Shanghai'));
        for ($day = 0; $day < 30; $day++) {
            $targetDate = $start->modify('+' . $day . ' days')->format('Y-m-d');
            foreach (['pv' => [31, 11], 'uv' => [20, 8], 'advance_orders' => [2, 0.5]] as $type => [$current, $peer]) {
                $rows[] = $this->row(80, 410, 'traffic_forecast', $targetDate, [
                    'data_value' => $current,
                    'dimension' => 'traffic_forecast:' . $type,
                ], [
                    'forecast_type' => $type,
                    'current' => $current,
                    'peer_avg' => $peer,
                ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 09:00:00');
            }
        }
        $rows[] = $this->row(80, 411, 'traffic_forecast', '2026-07-30', [
            'data_value' => 42,
            'dimension' => 'traffic_forecast:pv',
        ], [
            'forecast_type' => 'pv',
            'current' => 42,
            'peer_avg' => 12,
        ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 18:08:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows($rows, 80, '2026-07-29');

        self::assertSame('partial', $summary['future']['status']);
        self::assertSame(42, $summary['future']['rows'][0]['metrics']['pv']['value']);
        self::assertNull($summary['future']['rows'][0]['metrics']['uv']['value']);
        self::assertSame(410, $summary['future']['latest_verified_reference']['sync_task_id']);
        self::assertCount(30, $summary['future']['latest_verified_reference']['rows']);
    }

    private function completeBusinessRow(
        int $hotelId,
        int $taskId,
        string $dataDate,
        string $capturedAt
    ): array {
        return $this->row($hotelId, $taskId, 'business', $dataDate, [
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'flow_rate' => 1.30,
        ], [
            'lead_price' => 868,
            'sales_amount' => 2026.78,
            'sales_room_nights' => 2,
            'sales_avg_price' => 1013.39,
            'exposure_users' => 81,
            'detail_visitors' => 77,
            'paid_order_count' => 1,
            'browse_to_pay_rate' => 1.30,
        ], [
            'lead_price', 'sales_amount', 'sales_room_nights', 'sales_avg_price',
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ], $capturedAt);
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $rawRow
     * @param array<int, string> $capturedFacts
     * @return array<string, mixed>
     */
    private function row(
        int $hotelId,
        int $taskId,
        string $dataType,
        string $dataDate,
        array $columns,
        array $rawRow,
        array $capturedFacts,
        string $capturedAt = '2026-07-29 18:00:00'
    ): array {
        $facts = [];
        foreach ($capturedFacts as $metricKey) {
            $facts[] = [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'stored_value_present' => true,
                'source_path' => '$.' . $metricKey,
            ];
        }
        return array_merge([
            'id' => $taskId,
            'system_hotel_id' => $hotelId,
            'source' => 'meituan',
            'data_type' => $dataType,
            'data_date' => $dataDate,
            'compare_type' => $dataType === 'traffic_forecast' ? 'forecast' : 'self',
            'data_source_id' => 18,
            'sync_task_id' => $taskId,
            'source_trace_id' => 'trace-' . $taskId . '-' . $dataType,
            'snapshot_time' => $capturedAt,
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'row' => array_merge($rawRow, [
                    'dataDate' => $dataDate,
                    'date_source' => $dataType === 'traffic_forecast'
                        ? 'row.dateTime'
                        : 'page.business_period_selection.readback',
                ]),
                'date_source' => $dataType === 'traffic_forecast'
                    ? 'row.dateTime'
                    : 'page.business_period_selection.readback',
                'captured_at' => $capturedAt,
                'field_facts' => $facts,
            ], JSON_UNESCAPED_UNICODE),
        ], $columns);
    }
}
