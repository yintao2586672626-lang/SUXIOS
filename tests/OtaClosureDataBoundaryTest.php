<?php
declare(strict_types=1);

namespace Tests;

use app\command\PlatformProfileLogin;
use app\service\AiDailyReportService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OperationManagementService;
use app\service\Phase3OperationEffectLoopService;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OtaClosureDataBoundaryTest extends TestCase
{
    use ReflectionHelper;

    public function testCtripCompetitorAndQunarRowsCannotBecomeHotelSelfFunnel(): void
    {
        $service = new OperationManagementService();
        $base = [
            'system_hotel_id' => 80,
            'data_date' => date('Y-m-d', strtotime('-1 day')),
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'validation_status' => 'available',
            'readback_verified' => 1,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => date('Y-m-d H:i:s'),
            'ingestion_method' => 'browser_profile',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'raw_data' => '{}',
        ];

        $summary = $this->invokeNonPublic($service, 'buildOtaFromRows', [[
            $base + [
                'id' => 101,
                'hotel_id' => '130079194',
                'platform' => 'Ctrip',
                'compare_type' => 'self',
                'list_exposure' => 100,
                'detail_exposure' => 20,
                'order_filling_num' => 2,
                'order_submit_num' => 1,
                'update_time' => date('Y-m-d H:i:s'),
            ],
            $base + [
                'id' => 102,
                'hotel_id' => '-1',
                'platform' => 'Qunar',
                'compare_type' => 'competitor_avg',
                'list_exposure' => 999,
                'detail_exposure' => 500,
                'order_filling_num' => 80,
                'order_submit_num' => 60,
                'update_time' => date('Y-m-d H:i:s'),
            ],
        ]]);

        self::assertSame(100, $summary['exposure']);
        self::assertSame(20, $summary['visitors']);
        self::assertSame(1, $summary['orders']);
        self::assertSame('online_daily_data#101', $summary['evidence_refs'][0]['source_ref']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testUnknownScaleQualityFactsCannotTriggerHundredPointThreshold(): void
    {
        $service = new OperationManagementService();
        $today = date('Y-m-d');
        $row = static function (float $psi, string $endpoint) use ($today): array {
            return [
                'hotel_id' => '130079194',
                'system_hotel_id' => 80,
                'source' => 'ctrip',
                'platform' => 'Ctrip',
                'compare_type' => 'self',
                'data_date' => $today,
                'data_type' => 'quality',
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => date('Y-m-d H:i:s'),
                'is_final' => 0,
                'validation_status' => 'normal',
                'readback_verified' => 1,
                'ingestion_method' => 'browser_profile',
                'update_time' => date('Y-m-d H:i:s'),
                'dimension' => "catalog:traffic_report:{$endpoint}:psi_score:data.score",
                'data_value' => $psi,
                'raw_data' => json_encode([
                    'row' => ['raw_data' => ['metrics' => ['psi_score' => $psi]]],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $quality = $this->invokeNonPublic($service, 'buildServiceQualityFromRows', [[
            $row(3.37, 'traffic_picture_quality'),
            $row(5.14, 'business_service_quantity'),
            [
                'hotel_id' => '130079194',
                'system_hotel_id' => 80,
                'source' => 'ctrip',
                'platform' => 'Ctrip',
                'compare_type' => 'self',
                'data_date' => $today,
                'data_type' => 'quality',
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => date('Y-m-d H:i:s'),
                'is_final' => 0,
                'validation_status' => 'normal',
                'readback_verified' => 1,
                'ingestion_method' => 'browser_profile',
                'update_time' => date('Y-m-d H:i:s'),
                'dimension' => 'catalog:traffic_report:traffic_comment_score_summary:ctrip_rating',
                'data_value' => 4.5,
                'raw_data' => '{}',
            ],
        ]]);

        self::assertSame(4.26, $quality['avg_psi_score']);
        self::assertSame(2, $quality['sample_count']);
        self::assertSame('unknown', $quality['score_scale']);
        self::assertFalse($quality['threshold_80_eligible']);
        self::assertSame('partial', $quality['data_status']);

        $rootCause = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => [],
            'summary' => [],
            'competitors' => [],
            'service_quality' => $quality,
            'holiday' => [],
        ], [], [], '']);
        self::assertNotContains('service_quality_low', array_column($rootCause['root_causes'], 'type'));
    }

    public function testZeroPlaceholderWithoutFlowEndpointCannotBecomeHotelFunnelEvidence(): void
    {
        $summary = $this->invokeNonPublic(new OperationManagementService(), 'buildOtaFromRows', [[[
            'id' => 15676,
            'system_hotel_id' => 80,
            'hotel_id' => '130079194',
            'data_date' => date('Y-m-d'),
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => '',
            'data_type' => 'traffic',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => date('Y-m-d H:i:s'),
            'is_final' => 0,
            'validation_status' => 'normal',
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'raw_data' => '{}',
        ]]]);

        self::assertSame('待接入真实数据', $summary['data_status']);
        self::assertSame([], $summary['evidence_refs']);
        self::assertNull($summary['order_filling']);
        self::assertNull($summary['order_submit']);
    }

    public function testCurrentDayProfileLoginDefaultsToRealtimeSnapshot(): void
    {
        $command = new PlatformProfileLogin();
        $options = $this->invokeNonPublic($command, 'buildProfileLoginSyncOptions', ['ctrip', [
            'data_date' => date('Y-m-d'),
            'sections' => 'business_overview,traffic_report',
        ]]);

        self::assertSame('realtime_snapshot', $options['data_period']);
        self::assertNotSame('', $options['snapshot_time']);
    }

    public function testPersistenceCorrectsCurrentAndForecastPeriodSemantics(): void
    {
        $columns = [
            'data_period' => true,
            'snapshot_time' => true,
            'snapshot_bucket' => true,
            'is_final' => true,
        ];
        $current = OnlineDailyDataPersistenceService::applyPeriodFields([
            'data_date' => date('Y-m-d'),
            'data_period' => 'historical_daily',
        ], $columns);
        self::assertSame('realtime_snapshot', $current['data_period']);
        self::assertSame(0, $current['is_final']);
        self::assertNotNull($current['snapshot_time']);

        $forecast = OnlineDailyDataPersistenceService::applyPeriodFields([
            'data_date' => date('Y-m-d', strtotime('+1 day')),
            'data_type' => 'traffic_forecast',
            'data_period' => 'historical_daily',
        ], $columns);
        self::assertSame('next_30_days', $forecast['data_period']);
        self::assertSame(0, $forecast['is_final']);
        self::assertNull($forecast['snapshot_time']);

        $forecastDates = [
            date('Y-m-d', strtotime('-1 day')),
            date('Y-m-d'),
            date('Y-m-d', strtotime('+1 day')),
        ];
        foreach ($forecastDates as $forecastDate) {
            foreach (['traffic_forecast', 'trafficForecast'] as $forecastType) {
                $persisted = OnlineDailyDataPersistenceService::applyPeriodFields([
                    'data_date' => $forecastDate,
                    'data_type' => $forecastType,
                    'data_period' => 'historical_daily',
                ], $columns);
                self::assertSame('next_30_days', $persisted['data_period']);
                self::assertSame(0, $persisted['is_final']);
                self::assertNull($persisted['snapshot_time']);

                $syncMetadata = $this->invokeNonPublic(new PlatformDataSyncService(), 'resolveDataPeriodMetadata', [[
                    'data_type' => $forecastType,
                    'data_period' => 'historical_daily',
                ], [], [], $forecastDate]);
                self::assertSame('next_30_days', $syncMetadata['data_period']);
                self::assertSame(0, $syncMetadata['is_final']);
                self::assertNull($syncMetadata['snapshot_time']);
            }
        }
    }

    public function testEffectReviewRejectsCompetitorAndForecastRows(): void
    {
        $service = new Phase3OperationEffectLoopService();
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '130079194',
            'data_date' => date('Y-m-d', strtotime('-1 day')),
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => null,
            'data_type' => 'business',
            'dimension' => '',
        ];

        self::assertSame('operating', $this->invokeNonPublic($service, 'effectMetricRecordRole', [$base, ['ctrip']]));
        self::assertSame('', $this->invokeNonPublic($service, 'effectMetricRecordRole', [
            array_merge($base, ['hotel_id' => '-1', 'compare_type' => 'competitor_avg']),
            ['ctrip'],
        ]));
        self::assertSame('', $this->invokeNonPublic($service, 'effectMetricRecordRole', [
            array_merge($base, ['data_type' => 'traffic_forecast', 'data_period' => 'next_30_days']),
            ['ctrip'],
        ]));
    }

    public function testAiReportSnapshotKeepsEvidenceStatusWithoutRawAttachmentReferences(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->invokeNonPublic($service, 'sanitizeExecutionFlowForSnapshot', [[
            'list' => [[
                'id' => 54,
                'evidence' => [
                    'count' => 1,
                    'latest' => [
                        'evidence_type' => 'manual_operation_execution',
                        'attachment_path' => 'online_daily_data#17652 / online_daily_data#43491',
                        'before' => ['exposure' => 268],
                    ],
                ],
                'evidence_summary' => [
                    'count' => 1,
                    'latest_type' => 'manual_operation_execution',
                    'latest_at' => '2026-07-15 10:12:49',
                ],
            ]],
        ]]);

        self::assertSame(1, $snapshot['list'][0]['evidence']['count']);
        self::assertSame('manual_operation_execution', $snapshot['list'][0]['evidence']['latest']['evidence_type']);
        self::assertSame('2026-07-15 10:12:49', $snapshot['list'][0]['evidence']['latest']['created_at']);
        self::assertArrayNotHasKey('attachment_path', $snapshot['list'][0]['evidence']['latest']);
        self::assertArrayNotHasKey('before', $snapshot['list'][0]['evidence']['latest']);
    }

    public function testAiReportSnapshotExcludesRejectedInternalAuditChainFromDecisionEvidence(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->invokeNonPublic($service, 'sanitizeExecutionFlowForSnapshot', [[
            'list' => [[
                'id' => 54,
                'stage' => 'rejected',
                'approval' => ['status' => 'rejected'],
                'review' => [
                    'status' => 'failed',
                    'summary' => 'invalid source online_daily_data#43491',
                ],
                'evidence' => ['count' => 2, 'latest' => ['attachment_path' => 'online_daily_data#43491']],
            ]],
            'data_gaps' => [],
        ]]);

        self::assertSame([], $snapshot['list']);
        self::assertSame(0, $snapshot['summary']['total']);
        self::assertSame(1, $snapshot['excluded_audit_count']);
        self::assertSame('partial', $snapshot['data_status']);
        self::assertStringNotContainsString('43491', json_encode($snapshot, JSON_UNESCAPED_UNICODE));

        $matched = $this->invokeNonPublic($service, 'executionItemForAction', [[
            'execution_intent_id' => 54,
        ], [[
            'id' => 54,
            'stage' => 'rejected',
            'approval' => ['status' => 'rejected'],
            'review' => ['status' => 'failed'],
            'recommendation' => ['evidence' => ['action_index' => 0]],
        ]], 0]);
        self::assertSame([], $matched);
    }
}
