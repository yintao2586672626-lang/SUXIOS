<?php
declare(strict_types=1);

namespace Tests;

use app\service\Phase3OperationEffectLoopService;
use PHPUnit\Framework\TestCase;

final class Phase3OperationEffectLoopServiceTest extends TestCase
{
    public function testMissingExecutionEvidenceCannotBecomeSopCandidate(): void
    {
        $service = new Phase3OperationEffectLoopService();
        $snapshot = $this->snapshot([
            $this->action(7, 'North Hotel'),
        ]);

        $result = $service->buildFromSnapshot($snapshot, [
            'target_date' => '2026-07-14',
            'metric_window' => $this->metricWindow([7]),
        ]);

        self::assertSame('ota_channel', $result['scope']['metric_scope']);
        self::assertFalse($result['boundaries']['collection_logic_changed']);
        self::assertFalse($result['boundaries']['raw_data_exposed']);
        self::assertFalse($result['boundaries']['auto_decision_enabled']);
        self::assertCount(1, $result['rows']);
        self::assertSame('patrol_anomaly_confirmed', $result['rows'][0]['stages']['anomaly']['status']);
        self::assertSame('execution_missing', $result['rows'][0]['stages']['execution_evidence']['status']);
        self::assertSame('execution_missing', $result['rows'][0]['stages']['effect_review']['status']);
        self::assertSame('not_ready', $result['rows'][0]['stages']['sop']['status']);
        self::assertSame('not_ready', $result['rows'][0]['stages']['replication']['status']);
        self::assertSame([], $result['sop_candidates']);
        self::assertSame([], $result['replication_candidates']);
        self::assertSame(0, $result['summary']['executed_action_count']);
        self::assertSame(0, $result['summary']['sop_candidate_count']);
    }

    public function testReviewedExecutionCreatesSopAndManualReplicationCandidates(): void
    {
        $actions = [
            $this->action(7, 'North Hotel'),
            $this->action(8, 'South Hotel'),
        ];
        $trackingItems = [
            '7|price_adjust' => $this->trackedExecution(701, 801, 'success'),
            '8|price_adjust' => $this->trackedExecution(702, 802, 'near_success'),
        ];
        $service = new Phase3OperationEffectLoopService();

        $result = $service->buildFromSnapshot(
            $this->snapshot($actions, $trackingItems),
            [
                'target_date' => '2026-07-14',
                'metric_window' => $this->metricWindow([7, 8]),
            ]
        );

        self::assertSame(2, $result['summary']['executed_action_count']);
        self::assertSame(2, $result['summary']['reviewed_action_count']);
        self::assertSame(2, $result['summary']['sop_candidate_count']);
        self::assertSame(2, $result['summary']['replication_candidate_count']);
        self::assertCount(2, $result['sop_candidates']);
        self::assertCount(2, $result['replication_candidates']);

        $first = $result['rows'][0]['stages'];
        self::assertSame('executed_evidence_recorded', $first['execution_evidence']['status']);
        self::assertSame('reviewed', $first['effect_review']['status']);
        self::assertSame('success', $first['effect_review']['result_status']);
        self::assertFalse($first['effect_review']['causality_claimed']);
        self::assertSame('candidate', $first['sop']['status']);
        self::assertSame('candidate', $first['replication']['status']);
        self::assertSame([8], array_column($first['replication']['target_hotels'], 'hotel_id'));
        self::assertFalse($first['replication']['auto_apply_enabled']);
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function snapshot(array $actions, array $trackingItems = []): array
    {
        $rows = array_map(static fn(array $action): array => [
            'hotel_id' => $action['hotel_id'],
            'hotel_name' => $action['hotel_name'],
            'target_date' => '2026-07-14',
            'metric_diagnosis' => [
                'data_gap_codes' => ['conversion_rate_low'],
            ],
            'ai_evidence' => [
                'diagnosis_status' => 'ready',
                'explanation' => [
                    'summary' => 'OTA conversion is below the comparison window.',
                    'missing_codes' => [],
                ],
            ],
        ], $actions);

        return [
            'run_id' => 'daily_workbench_20260714_090000_abcdef12',
            'snapshot_type' => 'phase2_daily_workbench_patrol',
            'scope' => [
                'metric_scope' => 'ota_channel',
                'target_date' => '2026-07-14',
            ],
            'rows' => $rows,
            'next_actions' => $actions,
            'action_tracking' => [
                'items' => $trackingItems,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function action(int $hotelId, string $hotelName): array
    {
        return [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'target_date' => '2026-07-14',
            'platform' => 'ctrip',
            'question_key' => 'conversion_gap',
            'action_code' => 'price_adjust',
            'priority' => 'high',
            'action' => 'Review price and inventory before applying a manual OTA adjustment.',
            'entry' => '/online-data',
            'data_gaps' => ['conversion_rate_low'],
        ];
    }

    /** @return array<string, mixed> */
    private function trackedExecution(int $intentId, int $taskId, string $reviewStatus): array
    {
        return [
            'status' => 'done',
            'note' => 'Operator evidence recorded.',
            'operation_execution' => [
                'intent_id' => $intentId,
                'intent_status' => 'approved',
                'task_id' => $taskId,
                'task_status' => 'executed',
            ],
            'review_result' => [
                'result_status' => $reviewStatus,
                'result_summary' => 'The reviewed OTA metric window improved after execution.',
            ],
        ];
    }

    /** @param array<int, int> $hotelIds */
    private function metricWindow(array $hotelIds): array
    {
        $byHotel = [];
        foreach ($hotelIds as $hotelId) {
            $byHotel[$hotelId] = [
                'status' => 'ready',
                'current' => ['amount' => 1200.0, 'book_order_num' => 12],
                'previous' => ['amount' => 1000.0, 'book_order_num' => 10],
                'delta' => ['amount' => 200.0, 'book_order_num' => 2],
                'missing_dates' => [],
                'data_gaps' => [],
            ];
        }

        return [
            'status' => 'ready',
            'target_date' => '2026-07-14',
            'previous_date' => '2026-07-13',
            'data_gaps' => [],
            'by_hotel' => $byHotel,
        ];
    }
}
