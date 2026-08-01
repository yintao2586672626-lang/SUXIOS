<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripTemporalNotificationPayloadService;
use app\service\CtripTemporalRefreshService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CtripTemporalRefreshServiceTest extends TestCase
{
    public function testSameDayPastAndFutureAreReusedAndOnlyRealtimeIsRefreshed(): void
    {
        $date = date('Y-m-d');
        $capturedAt = date('Y-m-d H:i:s');
        $rows = [
            $this->historicalRow(1, $date, $capturedAt),
            $this->futureRow(2, $date, $capturedAt),
        ];
        $flows = [];
        $payloads = new CtripTemporalNotificationPayloadService(
            static function () use (&$rows): array {
                return $rows;
            }
        );
        $service = new CtripTemporalRefreshService(
            function (mixed $actor, int $sourceId, array $options) use (
                &$flows,
                &$rows,
                $date,
                $capturedAt
            ): array {
                $flows[] = (string)$options['collector_flow'];
                $rows[] = $this->presentRow(3, 300, $date, $capturedAt);
                return [
                    'status' => 'success',
                    'task_id' => 300,
                    'saved_count' => 1,
                    'readback_verified' => true,
                ];
            },
            static fn(): array => ['id' => 25],
            $payloads
        );

        $result = $service->refresh(
            (object)['id' => 7],
            9,
            80,
            '敦煌漠蓝新',
            $date,
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(['realtime'], $flows);
        self::assertSame(300, $result['sync_task_id']);
        self::assertSame(1, $result['saved_count']);
        self::assertTrue($result['readback_verified']);
    }

    public function testMissingDailySegmentsRunSeriallyBeforeRealtime(): void
    {
        $date = date('Y-m-d');
        $capturedAt = date('Y-m-d H:i:s');
        $rows = [];
        $flows = [];
        $periods = [];
        $taskIds = [
            'historical_review' => 401,
            'future_demand' => 402,
            'realtime' => 403,
        ];
        $payloads = new CtripTemporalNotificationPayloadService(
            static function () use (&$rows): array {
                return $rows;
            }
        );
        $service = new CtripTemporalRefreshService(
            function (mixed $actor, int $sourceId, array $options) use (
                &$flows,
                &$periods,
                &$rows,
                $taskIds,
                $date,
                $capturedAt
            ): array {
                $flow = (string)$options['collector_flow'];
                $flows[] = $flow;
                $periods[] = (string)$options['data_period'];
                if ($flow === 'realtime') {
                    $rows[] = $this->presentRow(
                        4,
                        $taskIds[$flow],
                        $date,
                        $capturedAt
                    );
                }
                return [
                    'status' => 'success',
                    'task_id' => $taskIds[$flow],
                    'saved_count' => 1,
                    'readback_verified' => true,
                ];
            },
            static fn(): array => ['id' => 25],
            $payloads
        );

        $result = $service->refresh(
            (object)['id' => 7],
            9,
            80,
            '敦煌漠蓝新',
            $date,
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(
            ['historical_review', 'future_demand', 'realtime'],
            $flows
        );
        self::assertSame(3, $result['saved_count']);
        self::assertSame(403, $result['sync_task_id']);
        self::assertSame(
            ['historical_daily', 'next_30_days', 'realtime_snapshot'],
            $periods
        );
    }

    public function testDailySupplementalFlowsAreNotRetriedAfterAnAttempt(): void
    {
        $date = date('Y-m-d');
        $capturedAt = date('Y-m-d H:i:s');
        $rows = [];
        $flows = [];
        $payloads = new CtripTemporalNotificationPayloadService(
            static function () use (&$rows): array {
                return $rows;
            }
        );
        $service = new CtripTemporalRefreshService(
            function (mixed $actor, int $sourceId, array $options) use (
                &$flows,
                &$rows,
                $date,
                $capturedAt
            ): array {
                $flows[] = (string)$options['collector_flow'];
                $rows[] = $this->presentRow(5, 500, $date, $capturedAt);
                return [
                    'status' => 'success',
                    'task_id' => 500,
                    'saved_count' => 1,
                    'readback_verified' => true,
                ];
            },
            static fn(): array => ['id' => 25],
            $payloads,
            static fn(): array => ['historical_review', 'future_demand']
        );

        $result = $service->refresh(
            (object)['id' => 7],
            9,
            80,
            'Dunhuang Molan',
            $date,
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('ready', $result['status']);
        self::assertSame(['realtime'], $flows);
        self::assertSame(
            ['skipped', 'skipped', 'ready'],
            array_column($result['flows'], 'status')
        );
        self::assertSame(
            [
                'ctrip_daily_flow_already_attempted',
                'ctrip_daily_flow_already_attempted',
                'ctrip_flow_saved_and_read_back',
            ],
            array_column($result['flows'], 'reason_code')
        );
    }

    public function testNonCurrentObservationBlocksWithoutStartingCapture(): void
    {
        $calls = 0;
        $service = new CtripTemporalRefreshService(
            static function () use (&$calls): array {
                $calls++;
                return [];
            },
            static fn(): array => ['id' => 25],
            new CtripTemporalNotificationPayloadService(static fn(): array => [])
        );

        $result = $service->refresh(
            (object)['id' => 7],
            9,
            80,
            '敦煌漠蓝新',
            date('Y-m-d'),
            (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
                ->modify('-10 minutes')
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame('ctrip_dispatch_observation_not_current', $result['reason_code']);
        self::assertSame(0, $calls);
    }

    /** @return array<string, mixed> */
    private function presentRow(
        int $id,
        int $taskId,
        string $date,
        string $capturedAt
    ): array {
        return $this->baseRow(
            $id,
            $taskId,
            $date,
            $capturedAt,
            'business_visitor_title',
            [
                'field_facts' => [
                    $this->fact('visitor_count', 6, 'visitortotal'),
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    private function historicalRow(
        int $id,
        string $date,
        string $capturedAt
    ): array {
        $yesterday = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        return $this->baseRow(
            $id,
            200,
            $yesterday,
            $capturedAt,
            'traffic_flow_transform',
            [
                'field_facts' => [
                    $this->fact('list_exposure', 296, '0.listExposure'),
                    $this->fact('detail_visitor', 74, '0.detailExposure'),
                ],
                'dimension_values' => ['analysis_window' => 'yesterday'],
            ],
            [
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'dimension' =>
                    'catalog:traffic_report:traffic_flow_transform:flow:0:window=yesterday',
            ]
        );
    }

    /** @return array<string, mixed> */
    private function futureRow(
        int $id,
        string $date,
        string $capturedAt
    ): array {
        $targetDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        return $this->baseRow(
            $id,
            201,
            $date,
            $capturedAt,
            'traffic_search_details',
            [
                'dimension_values' => [
                    'target_date' => $targetDate,
                    'search_window' => 'cumulative',
                    'compare_scope' => 'self',
                ],
                'metrics' => ['future_search_uv' => 80],
            ],
            [
                'data_period' => 'next_30_days',
                'dimension' => 'catalog:traffic_report:traffic_search_details',
            ]
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseRow(
        int $id,
        int $taskId,
        string $date,
        string $capturedAt,
        string $endpointId,
        array $raw,
        array $overrides = []
    ): array {
        return array_replace([
            'id' => $id,
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => $date,
            'data_type' => 'traffic',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => $capturedAt,
            'is_final' => 0,
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'data_source_id' => 25,
            'sync_task_id' => $taskId,
            'source_trace_id' => 'trace-' . $id,
            'raw_data' => [
                'endpoint_id' => $endpointId,
                'captured_at' => $capturedAt,
                ...$raw,
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function fact(string $key, int|float $value, string $path): array
    {
        return [
            'metric_key' => $key,
            'value' => $value,
            'source_path' => $path,
            'fact_status' => 'captured',
        ];
    }
}
