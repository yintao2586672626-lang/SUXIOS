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
                'target_date_traffic_verified_metric_keys' => $this->requiredTrafficMetrics(),
                'target_date_traffic_ready_data_source_ids' => [31],
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

    public function testCanonicalTrafficClosureRejectsSingleFactUnsafeEvidenceAndForecastRows(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $raw = $this->completeTrafficFactRaw();

        self::assertTrue($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$raw]));

        $singleFact = $raw;
        $singleFact['field_facts'] = [$raw['field_facts'][0]];
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$singleFact]));

        $wrongStorage = $raw;
        $wrongStorage['field_facts'][0]['storage_field'] = 'online_daily_data.detail_exposure';
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$wrongStorage]));

        $unstructuredSourcePath = $raw;
        $unstructuredSourcePath['field_facts'][0]['source_path'] = 'list_exposure';
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$unstructuredSourcePath]));

        $notStored = $raw;
        $notStored['field_facts'][0]['stored_value_present'] = false;
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$notStored]));

        $uiStatusIncomplete = $raw;
        $uiStatusIncomplete['field_facts'][0]['status'] = 'missing';
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$uiStatusIncomplete]));

        $unsafeEvidence = $raw;
        $unsafeEvidence['field_facts'][0]['capture_evidence']['cookie'] = 'sensitive-cookie';
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$unsafeEvidence]));

        $forecast = $raw;
        $forecast['data_period'] = 'next_30_days';
        self::assertFalse($this->invokeNonPublic($controller, 'collectionStatusRawHasFieldFacts', [$forecast]));
    }

    public function testCollectionStatusTargetDateRejectsInvalidAndFutureDates(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        foreach (['2026-02-31', '2999-01-01', '2026/07/09'] as $targetDate) {
            $thrown = null;
            try {
                $this->invokeNonPublic($controller, 'collectionStatusTargetDate', [['target_date' => $targetDate]]);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }
            self::assertInstanceOf(\RuntimeException::class, $thrown, $targetDate);
            self::assertSame(422, $thrown->getCode(), $targetDate);
        }
    }

    public function testBrowserProfileProjectionRequiresCurrentProofFromTrafficSource(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'ctrip',
            ['resources' => []],
            [[
                'id' => 91,
                'tenant_id' => 1,
                'system_hotel_id' => 10,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'config' => ['profile_binding_key' => 'profile-10'],
            ]],
            [],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 1,
                'target_date_traffic_rows' => 1,
                'target_date_traffic_field_fact_status' => 'ready',
                'target_date_traffic_field_fact_ready_count' => 1,
                'target_date_traffic_verified_metric_keys' => $this->requiredTrafficMetrics(),
                'target_date_traffic_ready_data_source_ids' => [91],
                'row_count' => 1,
                'end_date' => '2026-07-09',
            ],
            [
                'items' => [[
                    'platform' => 'ctrip',
                    'status_code' => 'logged_in',
                    'binding_check_status' => 'ok',
                    'binding_contract' => ['status' => 'complete', 'missing_requirements' => []],
                ]],
            ],
        ]);

        self::assertFalse($row['dataCollected']);
        self::assertSame('unverified', $row['quality']['primary_quality_state']);
        self::assertContains('current_session_proof_missing', $row['quality']['quality_flags']);
        self::assertTrue($row['quality']['evidence']['profile_session_proof_required']);
        self::assertFalse($row['quality']['evidence']['profile_session_same_source']);
    }

    public function testTargetDateEvidenceQueryHasNoUnorderedLimitedWindowAndExcludesForecasts(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/app/controller/concern/PlatformDataSourceConcern.php');
        self::assertIsString($source);
        self::assertStringNotContainsString('->limit(2000)', $source);
        self::assertStringContainsString("->whereNotIn('data_type', ['traffic_forecast', 'forecast'])", $source);
        self::assertStringContainsString("->whereOr('data_period', 'not in', ['next_7_days', 'next_30_days', 'forecast', 'future_forecast'])", $source);
    }

    public function testPlatformDataTaskResponseDoesNotExposePartialOrFailedWorkAsSuccess(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $success = $this->invokeNonPublic($controller, 'platformDataTaskResponse', [[
            'status' => 'success',
            'saved_count' => 2,
        ], '同步']);
        $partial = $this->invokeNonPublic($controller, 'platformDataTaskResponse', [[
            'status' => 'partial_success',
            'saved_count' => 1,
        ], '同步']);
        $failed = $this->invokeNonPublic($controller, 'platformDataTaskResponse', [[
            'status' => 'failed',
            'saved_count' => 0,
        ], '同步']);

        self::assertSame(200, $success->getCode());
        self::assertSame(422, $partial->getCode());
        self::assertSame(500, $failed->getCode());
        self::assertSame(1, json_decode($partial->getContent(), true)['data']['saved_count']);
        self::assertSame(0, json_decode($failed->getContent(), true)['data']['saved_count']);
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
                    'sync_diagnostics' => [
                        'target_date' => '2026-07-09',
                        'capability_states' => [
                            'business' => 'verified',
                            'orders' => 'permission_denied',
                            'reviews' => 'unverified',
                            'raw_response' => 'must-not-be-projected',
                        ],
                    ],
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
        self::assertSame([
            'business' => 'verified',
            'orders' => 'permission_denied',
            'reviews' => 'unverified',
        ], $row['latestTask']['syncDiagnostics']['capability_states']);
        self::assertArrayNotHasKey('token', $row['latestTask']['collectionQuality']['evidence']);
        self::assertArrayNotHasKey('raw_payload', $row['latestTask']['collectionQuality']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($row['latestTask']['collectionQuality'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testCollectionStatusRowSanitizesLegacyTaskDiagnostics(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'meituan',
            ['resources' => []],
            [[
                'id' => 51,
                'platform' => 'meituan',
                'status' => 'ready',
                'last_sync_time' => '2026-07-10 08:20:00',
            ]],
            [[
                'id' => 52,
                'platform' => 'meituan',
                'status' => 'failed',
                'message' => 'external failure token=must-not-be-projected',
                'finished_at' => '2026-07-10 08:20:00',
                'stats_json' => json_encode([
                    'sync_diagnostics' => [
                        'target_date' => '2026-07-09',
                        'requires_target_date_traffic' => true,
                        'target_date_rows' => 0,
                        'target_date_traffic_rows' => 0,
                        'field_fact_status' => 'not_loaded',
                        'p0_status' => 'blocked',
                        'missing_inputs' => ['target_date_traffic_rows'],
                        'operator_message' => 'external failure token=must-not-be-projected',
                        'adapter_status' => 'failed',
                        'adapter_message' => 'Authorization: Bearer must-not-be-projected',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 0,
                'target_date_traffic_rows' => 0,
                'target_date_traffic_field_fact_status' => 'not_loaded',
                'row_count' => 0,
            ],
            ['items' => []],
        ]);

        $diagnostics = $row['latestTask']['syncDiagnostics'];

        self::assertSame('2026-07-09', $diagnostics['target_date']);
        self::assertSame('failed', $diagnostics['adapter_status']);
        self::assertSame('collection_failed', $diagnostics['operator_message']);
        self::assertArrayNotHasKey('adapter_message', $diagnostics);
        self::assertSame('collection_failed', $row['latestTask']['message']);
        self::assertSame('collection_failed', $row['failureReason']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testCollectionStatusFailureReasonDoesNotExposeLegacySourceError(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'meituan',
            ['resources' => []],
            [[
                'id' => 61,
                'platform' => 'meituan',
                'status' => 'ready',
                'last_sync_status' => 'failed',
                'last_error' => 'external failure token=must-not-be-projected',
                'last_sync_time' => '2026-07-10 08:20:00',
            ]],
            [],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 1,
                'target_date_traffic_rows' => 1,
                'target_date_traffic_field_fact_status' => 'ready',
                'target_date_traffic_verified_metric_keys' => $this->requiredTrafficMetrics(),
                'target_date_traffic_ready_data_source_ids' => [61],
                'row_count' => 1,
            ],
            ['items' => []],
        ]);

        self::assertSame('collection_failed', $row['failureReason']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testCollectionStatusResourceReasonDoesNotExposeLegacyExternalText(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'meituan',
            [
                'resources' => [[
                    'resource' => 'traffic',
                    'data_type' => 'traffic',
                    'platform_statuses' => [[
                        'platform' => 'meituan',
                        'collection_status' => 'failed',
                        'missing_reason' => 'external failure token=must-not-be-projected',
                    ]],
                ]],
            ],
            [],
            [],
            [
                'target_date' => '2026-07-09',
                'target_date_rows' => 0,
                'target_date_traffic_rows' => 0,
                'target_date_traffic_field_fact_status' => 'not_loaded',
                'row_count' => 0,
            ],
            ['items' => []],
        ]);

        self::assertSame('collection_failed', $row['resourceStatuses'][0]['missingReason']);
        self::assertSame('collection_failed', $row['failureReason']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testCollectionStatusRowProjectsIndependentSafeCapabilityReport(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $row = $this->invokeNonPublic($controller, 'buildCollectionStatusPlatformRow', [
            'meituan',
            [
                'resources' => [
                    [
                        'resource' => 'businessData',
                        'data_type' => 'business',
                        'platform_statuses' => [[
                            'platform' => 'meituan',
                            'collection_status' => 'ready',
                            'etl_status' => 'stored_displayable',
                            'stored_row_count' => 2,
                            'missing_reason' => 'Authorization: Bearer must-not-be-projected',
                        ]],
                    ],
                    [
                        'resource' => 'orderData',
                        'data_type' => 'order',
                        'platform_statuses' => [[
                            'platform' => 'meituan',
                            'collection_status' => 'permission_denied',
                            'etl_status' => 'not_started',
                            'stored_row_count' => 0,
                        ]],
                    ],
                    [
                        'resource' => 'reviewData',
                        'data_type' => 'review',
                        'platform_statuses' => [[
                            'platform' => 'meituan',
                            'collection_status' => 'unbound',
                            'etl_status' => 'not_started',
                            'stored_row_count' => 0,
                        ]],
                    ],
                ],
            ],
            [[
                'id' => 71,
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

        self::assertArrayHasKey('capabilityReport', $row);
        self::assertSame([
            'business' => 'verified',
            'orders' => 'permission_denied',
            'reviews' => 'unverified',
        ], $row['capabilityReport']);
        self::assertStringNotContainsString('must-not-be-projected', (string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, string> */
    private function requiredTrafficMetrics(): array
    {
        return [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
    }

    /** @return array<string, mixed> */
    private function completeTrafficFactRaw(): array
    {
        $facts = [];
        foreach ($this->requiredTrafficMetrics() as $metric) {
            $facts[] = [
                'metric_key' => $metric,
                'source_path' => '$.traffic.' . $metric,
                'storage_field' => 'online_daily_data.' . $metric,
                'stored_value_present' => true,
                'status' => 'captured',
                'capture_evidence' => [
                    'source_trace_id' => 'trace-' . $metric,
                    'source_url_hash' => hash('sha256', 'https://example.invalid/' . $metric),
                ],
            ];
        }

        return [
            'data_period' => 'day',
            'field_facts' => $facts,
        ];
    }
}
