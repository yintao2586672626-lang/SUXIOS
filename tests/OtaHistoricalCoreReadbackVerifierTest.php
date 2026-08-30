<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaHistoricalCoreReadbackVerifier;
use PHPUnit\Framework\TestCase;

final class OtaHistoricalCoreReadbackVerifierTest extends TestCase
{
    public function testMeituanRequiresExactOrdersAndTrafficRows(): void
    {
        $verifier = new OtaHistoricalCoreReadbackVerifier();
        $readback = $this->readback();
        $rows = $this->completeRows();

        self::assertTrue($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily', $readback, $rows
        ));
        self::assertFalse($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily',
            array_replace($readback, ['row_ids' => [2]]),
            [$rows[1]]
        ));
    }

    public function testWrongTenantPeriodTaskOrTraceFailsClosed(): void
    {
        $verifier = new OtaHistoricalCoreReadbackVerifier();
        $baseReadback = $this->readback();
        $baseRows = $this->completeRows();

        $wrongTenant = $baseRows;
        $wrongTenant[0]['tenant_id'] = 9;
        self::assertFalse($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily', $baseReadback, $wrongTenant
        ));

        $wrongPeriod = $baseRows;
        $wrongPeriod[1]['data_period'] = 'realtime_snapshot';
        self::assertFalse($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily', $baseReadback, $wrongPeriod
        ));

        $wrongTask = $baseRows;
        $wrongTask[0]['sync_task_id'] = 778;
        self::assertFalse($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily', $baseReadback, $wrongTask
        ));

        self::assertFalse($verifier->verifyRows(
            'meituan', 8, 68, 80, '2026-08-28', 'historical_daily',
            array_replace($baseReadback, ['source_trace_ids' => []]),
            $baseRows
        ));
    }

    /** @return array<string,mixed> */
    private function readback(): array
    {
        return [
            'readback_verified' => true,
            'sync_task_id' => 777,
            'data_source_id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'target_date' => '2026-08-28',
            'data_period' => 'historical_daily',
            'row_ids' => [1, 2],
            'source_trace_ids' => ['meituan:orders:2026-08-28', 'meituan:traffic:2026-08-28'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function completeRows(): array
    {
        $scope = [
            'tenant_id' => 8,
            'sync_task_id' => 777,
            'data_source_id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_date' => '2026-08-28',
            'data_period' => 'historical_daily',
            'readback_verified' => 1,
            'validation_status' => 'verified',
            'compare_type' => 'self',
        ];
        return [
            $scope + [
                'id' => 1,
                'data_type' => 'orders',
                'amount' => 1200.50,
                'quantity' => 8,
                'book_order_num' => 6,
            ],
            $scope + [
                'id' => 2,
                'data_type' => 'traffic',
                'list_exposure' => 2000,
                'detail_exposure' => 230,
                'flow_rate' => 0.08,
            ],
        ];
    }
}
