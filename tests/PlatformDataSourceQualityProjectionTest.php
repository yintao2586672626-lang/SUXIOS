<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class PlatformDataSourceQualityProjectionTest extends TestCase
{
    use ReflectionHelper;

    public function testCollectionStatusRowAddsQualityWithoutReplacingLegacyFields(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'meituan',
            ['resources' => []],
            [[
                'id' => 31,
                'platform' => 'meituan',
                'status' => 'ready',
                'last_sync_time' => '2026-07-10 08:20:00',
            ]],
            [],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 2,
                'target_date_traffic_rows' => 1,
                'target_date_traffic_field_fact_status' => 'ready',
                'target_date_traffic_field_fact_ready_count' => 1,
                'row_count' => 2,
                'end_date' => '2026-07-09',
                'latest_collected_at' => '2026-07-10 08:20:00',
                'data_range' => '2026-07-09',
            ],
            [
                'items' => [[
                    'platform' => 'meituan',
                    'status_code' => 'logged_in',
                    'current_status' => 'verified',
                    'binding_check_status' => 'ok',
                    'binding_contract' => [
                        'status' => 'complete',
                        'missing_requirements' => [],
                    ],
                    'profile_exists' => true,
                ]],
            ],
        ]);

        self::assertSame('collected', $row['collectionStatus']);
        self::assertSame('', $row['failureReason']);
        self::assertSame('ota_channel', $row['dataScope']);
        self::assertSame('available', $row['quality']['primary_quality_state']);
        self::assertSame('ota_channel', $row['quality']['metric_scope']);
        self::assertSame('2026-07-09', $row['quality']['data_as_of']);
        self::assertSame(1, $row['quality']['evidence']['source_count']);
        self::assertArrayNotHasKey('raw_payload', $row['quality']);
        self::assertArrayNotHasKey('password', $row['quality']);
    }

    public function testCollectionStatusRowExposesOnlySafeTaskQualitySnapshot(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'ctrip',
            ['resources' => []],
            [[
                'id' => 31,
                'platform' => 'ctrip',
                'status' => 'ready',
                'last_sync_time' => '2026-07-10 08:20:00',
            ]],
            [[
                'id' => 41,
                'platform' => 'ctrip',
                'status' => 'success',
                'finished_at' => '2026-07-10 08:20:00',
                'stats_json' => json_encode([
                    'sync_diagnostics' => ['target_date' => '2026-07-09'],
                    'collection_quality' => [
                        'primary_quality_state' => 'available',
                        'quality_flags' => [],
                        'metric_scope' => 'ota_channel',
                        'evidence_scope' => 'sync_task',
                        'target_date' => '2026-07-09',
                        'data_as_of' => '2026-07-09',
                        'collected_at' => '2026-07-10 08:20:00',
                        'evidence' => [
                            'task_status' => 'success',
                            'ingestion_method' => 'browser_profile',
                            'p0_status' => 'ready',
                            'target_date_rows' => 2,
                            'target_date_traffic_rows' => 1,
                            'field_fact_status' => 'ready',
                            'normalized_count' => 2,
                            'saved_count' => 2,
                            'token' => 'must-not-be-projected',
                        ],
                        'next_action' => '',
                        'raw_payload' => ['password' => 'must-not-be-projected'],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 2,
                'target_date_traffic_rows' => 1,
                'target_date_traffic_field_fact_status' => 'ready',
                'target_date_traffic_field_fact_ready_count' => 1,
                'row_count' => 2,
                'end_date' => '2026-07-09',
                'latest_collected_at' => '2026-07-10 08:20:00',
                'data_range' => '2026-07-09',
            ],
            [
                'items' => [[
                    'platform' => 'ctrip',
                    'status_code' => 'logged_in',
                    'current_status' => 'verified',
                    'binding_check_status' => 'ok',
                    'binding_contract' => [
                        'status' => 'complete',
                        'missing_requirements' => [],
                    ],
                    'profile_exists' => true,
                ]],
            ],
        ]);

        self::assertSame('available', $row['latestTask']['collectionQuality']['primary_quality_state']);
        self::assertSame('sync_task', $row['latestTask']['collectionQuality']['evidence_scope']);
        self::assertSame(2, $row['latestTask']['collectionQuality']['evidence']['saved_count']);
        self::assertArrayNotHasKey('token', $row['latestTask']['collectionQuality']['evidence']);
        self::assertArrayNotHasKey('raw_payload', $row['latestTask']['collectionQuality']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($row['latestTask']['collectionQuality'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
