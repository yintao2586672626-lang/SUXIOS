<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationBusinessPreviewService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationBusinessPreviewServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/manual_notification_business_preview_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);

        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        Db::execute(
            'CREATE TABLE hotels ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL)'
        );
        Db::execute(
            'CREATE TABLE daily_reports ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'report_date TEXT NOT NULL, report_data TEXT NULL, occupancy_rate REAL NULL, room_count INTEGER NULL, '
            . 'guest_count INTEGER NULL, revenue REAL NULL, expenses REAL NULL, notes TEXT NULL, '
            . 'submitter_id INTEGER NULL, status INTEGER NOT NULL, create_time TEXT NULL, update_time TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE platform_data_sync_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, data_source_id INTEGER NULL, '
            . 'system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, data_type TEXT NOT NULL, '
            . 'ingestion_method TEXT NOT NULL, trigger_type TEXT NOT NULL, status TEXT NOT NULL, '
            . 'started_at TEXT NULL, finished_at TEXT NULL, create_time TEXT NULL, update_time TEXT NULL)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('platform_data_sync_tasks')->delete(true);
        Db::name('daily_reports')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 80, 'name' => '敦煌漠蓝新', 'status' => 1],
            ['id' => 81, 'tenant_id' => 81, 'name' => '其他酒店', 'status' => 1],
        ]);
    }

    public function testPreviewKeepsWholeHotelFactsAndOtaFactsInSeparateScopes(): void
    {
        $this->insertReport(80, 80, '2026-07-26', 2, [
            'revenue' => 4200,
            'room_revenue' => 4000,
            'total_rooms' => 20,
            'salable_rooms' => 40,
        ], 4200, 20, 50);
        $this->insertReport(81, 81, '2026-07-26', 2, [
            'revenue' => 999999,
            'room_revenue' => 999999,
            'total_rooms' => 999,
            'salable_rooms' => 999,
        ], 999999, 999, 100);

        $preview = (new ManualNotificationBusinessPreviewService(
            fn(int $hotelId, string $date): array => $this->temporalFixture($hotelId, $date),
            fn(int $hotelId, string $date): array => $this->trustedOtaFixture($hotelId, $date),
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'readback_verified', 'task_id' => 701],
                    'meituan' => ['status' => 'readback_verified', 'task_id' => 702],
                ],
            ],
            fn(int $tenantId, int $hotelId, string $date): array =>
                $this->pmsForwardFixture($tenantId, $hotelId, $date)
        ))->preview(80, '2026-07-26');

        self::assertSame(ManualNotificationBusinessPreviewService::CONTRACT_VERSION, $preview['contract_version']);
        self::assertTrue($preview['preview_only']);
        self::assertTrue($preview['read_only']);
        self::assertSame('not_sent', $preview['delivery_status']);
        self::assertSame(['id' => 80, 'tenant_id' => 80, 'name' => '敦煌漠蓝新'], $preview['hotel']);
        self::assertSame('2026-07-26', $preview['business_date']);

        $today = $preview['sections']['today_revenue_management'];
        self::assertSame('ready', $today['status']);
        $wholeRevenue = $this->field($today['facts'], 'whole_hotel_revenue');
        $otaRevenue = $this->field($today['facts'], 'ota_revenue');
        self::assertSame(4200, $wholeRevenue['value']);
        self::assertSame('whole_hotel', $wholeRevenue['scope']);
        self::assertSame('daily_reports', $wholeRevenue['source']['table']);
        self::assertSame(80, $wholeRevenue['source']['hotel_id']);
        self::assertSame('2026-07-26', $wholeRevenue['source']['report_date']);
        self::assertSame(1300, $otaRevenue['value']);
        self::assertSame('ota_channel', $otaRevenue['scope']);
        self::assertSame('online_daily_data', $otaRevenue['source']['table']);
        self::assertSame(80, $otaRevenue['source']['system_hotel_id']);
        self::assertSame('2026-07-26', $otaRevenue['source']['data_date']);
        self::assertTrue($otaRevenue['source']['readback_verified']);
        self::assertSame(['ctrip', 'meituan'], $otaRevenue['source']['platforms']);
        self::assertSame('readback_verified', $today['ota_collection']['status']);
        $pmsRevenue = $this->field($today['facts'], 'pms_room_fee');
        self::assertSame(8745.6, $pmsRevenue['value']);
        self::assertSame('accommodation_room_fee', $pmsRevenue['scope']);
        self::assertSame(
            'dingdandao_operating_target_captures',
            $pmsRevenue['source']['table']
        );
        self::assertSame('readback_verified', $today['message_data']['data_status']);
        self::assertSame(
            8745.6,
            $today['message_data']['sources']['dingdandao_pms']['facts']['room_fee']
        );
        self::assertSame(
            800,
            $today['message_data']['sources']['ctrip_ota']['facts']['revenue']
        );
        self::assertSame(
            500,
            $today['message_data']['sources']['meituan_ota']['facts']['revenue']
        );
        self::assertSame(
            [801],
            $today['message_data']['sources']['ctrip_ota']['source']['row_ids']
        );
        self::assertSame(
            ['trace-ctrip-801'],
            $today['message_data']['sources']['ctrip_ota']['source']['source_trace_ids']
        );
        self::assertFalse(
            $today['message_data']['aggregation_policy']['pms_plus_ota_revenue_addition_allowed']
        );

        $future = $preview['sections']['future_room_status'];
        self::assertSame('ready', $future['status']);
        $forwardSellable = $this->field(
            $future['facts'],
            'future_sellable_room_nights'
        );
        self::assertSame('available', $forwardSellable['status']);
        self::assertSame(105, $forwardSellable['value']);
        self::assertSame(
            'whole_hotel_forward_room_status',
            $forwardSellable['scope']
        );
        self::assertSame(
            'dingdandao_operating_target_captures',
            $forwardSellable['source']['table']
        );
        self::assertSame('readback_verified', $forwardSellable['source']['quality_status']);
        self::assertSame([3, 7, 14, 21], $future['message_data']['display_horizons']);
        self::assertSame(21, $future['message_data']['display_day_count']);
        self::assertCount(4, $future['message_data']['horizons']);
        self::assertCount(1, $future['message_data']['room_types']);
        self::assertSame('forecast_available', $future['forecasts'][0]['status']);
        self::assertSame('ota_channel', $future['forecasts'][0]['scope']);
        self::assertStringContainsString('不是全酒店远期房态事实', $future['forecasts'][0]['note']);
        self::assertSame([], $future['gaps']);

        $review = $preview['sections']['daily_review'];
        self::assertSame('ready', $review['status']);
        self::assertSame('2026-07-26', $review['reviews'][0]['target_date']);
        self::assertSame('ota_channel', $review['reviews'][0]['scope']);
        self::assertSame(
            8745.6,
            $this->field($review['facts'], 'pms_room_fee')['value']
        );
        self::assertSame(
            'latest_verified_snapshot_not_end_of_day_final',
            $review['message_data']['snapshot_role']
        );
        self::assertSame('readback_verified', $review['message_data']['data_status']);
    }

    public function testIncompleteOtaMetricsRemainPartialForTodayAndReview(): void
    {
        $this->insertReport(80, 80, '2026-07-26', 2, [
            'revenue' => 4200,
            'room_revenue' => 4000,
            'total_rooms' => 20,
            'salable_rooms' => 40,
        ], 4200, 20, 50);

        $preview = (new ManualNotificationBusinessPreviewService(
            fn(int $hotelId, string $date): array =>
                $this->temporalFixture($hotelId, $date),
            function (int $hotelId, string $date): array {
                $trusted = $this->trustedOtaFixture($hotelId, $date);
                $trusted['data_status'] = 'partial';
                $trusted['data_gaps'] = [
                    'pricing_history_book_order_num_column_missing',
                ];
                $trusted['rows'][1]['book_order_num'] = null;
                return $trusted;
            },
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'readback_verified', 'task_id' => 701],
                    'meituan' => ['status' => 'readback_verified', 'task_id' => 702],
                ],
            ],
            fn(int $tenantId, int $hotelId, string $date): array =>
                $this->pmsForwardFixture($tenantId, $hotelId, $date)
        ))->preview(80, '2026-07-26');

        $today = $preview['sections']['today_revenue_management'];
        self::assertSame(
            'partial_readback_verified',
            $today['message_data']['sources']['ctrip_ota']['data_status']
        );
        self::assertSame(
            'partial_readback_verified',
            $today['message_data']['sources']['meituan_ota']['data_status']
        );
        self::assertNull(
            $today['message_data']['sources']['meituan_ota']['facts']['orders']
        );
        self::assertSame('partial', $today['message_data']['data_status']);
        self::assertSame(
            'partial',
            $preview['sections']['daily_review']['message_data']['data_status']
        );
        self::assertContains(
            'trusted_ota_fact_evidence_partial',
            array_column($today['gaps'], 'code')
        );
    }

    public function testStaleDraftAndCrossHotelRowsNeverBecomeExactDateFacts(): void
    {
        $this->insertReport(80, 80, '2026-07-25', 2, [
            'revenue' => 7000,
            'room_revenue' => 6500,
            'total_rooms' => 30,
            'salable_rooms' => 40,
        ], 7000, 30, 75);
        $this->insertReport(80, 80, '2026-07-26', 1, [
            'revenue' => 8000,
            'room_revenue' => 7500,
            'total_rooms' => 35,
            'salable_rooms' => 40,
        ], 8000, 35, 87.5);
        $this->insertReport(81, 81, '2026-07-26', 2, [
            'revenue' => 9000,
            'room_revenue' => 8500,
            'total_rooms' => 38,
            'salable_rooms' => 40,
        ], 9000, 38, 95);

        $preview = (new ManualNotificationBusinessPreviewService(
            static fn(): array => [
                'metric_scope' => 'ota_channel',
                'present' => [
                    'status' => 'ready',
                    'as_of_time' => '2026-07-25 23:59:00',
                    'metrics' => ['ota_revenue' => 7777, 'ota_orders' => 77, 'ota_room_nights' => 70],
                ],
                'future' => [
                    'status' => 'ready',
                    'version' => ['as_of_date' => '2026-07-25', 'forecast_run_id' => 'stale-run'],
                    'series' => [[
                        'date' => '2026-07-27',
                        'metrics' => ['ota_revenue' => ['direction' => 'up', 'lower_bound' => 7000, 'upper_bound' => 9000]],
                    ]],
                ],
                'review' => [
                    'items' => [[
                        'target_date' => '2026-07-25',
                        'metric_key' => 'ota_revenue',
                        'actual_value' => 7000,
                    ]],
                ],
            ],
            static fn(): array => [
                'data_status' => 'ready',
                'rows' => [[
                    'data_date' => '2026-07-25',
                    'source' => 'ctrip',
                    'amount' => 7777,
                    'quantity' => 70,
                    'book_order_num' => 77,
                ]],
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                ],
            ],
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'collecting', 'task_id' => 801],
                    'meituan' => ['status' => 'pending_readback', 'task_id' => 802],
                ],
            ]
        ))->preview(80, '2026-07-26');

        self::assertSame('collecting', $preview['status']);
        $json = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['7000', '7777', '8000', '9000', 'stale-run'] as $forbiddenValue) {
            self::assertStringNotContainsString($forbiddenValue, $json);
        }
        self::assertNull($this->field(
            $preview['sections']['today_revenue_management']['facts'],
            'whole_hotel_revenue'
        )['value']);
        self::assertNull($this->field(
            $preview['sections']['today_revenue_management']['facts'],
            'ota_revenue'
        )['value']);
        self::assertNull($this->field(
            $preview['sections']['today_revenue_management']['facts'],
            'pms_room_fee'
        )['value']);
        self::assertSame(
            'blocked',
            $preview['sections']['today_revenue_management']['message_data']['data_status']
        );
        self::assertSame([], $preview['sections']['future_room_status']['forecasts']);
        self::assertSame([], $preview['sections']['daily_review']['reviews']);
    }

    public function testExactDateSubmittedExplicitZeroRemainsARealFactButMissingRatiosStayNull(): void
    {
        $this->insertReport(80, 80, '2026-07-26', 2, [
            'revenue' => 0,
            'room_revenue' => 0,
            'total_rooms' => 0,
            'salable_rooms' => 30,
        ], 0, 0, 0);

        $preview = (new ManualNotificationBusinessPreviewService(
            static fn(): array => ['metric_scope' => 'ota_channel', 'present' => [], 'future' => [], 'review' => []],
            static fn(): array => [
                'data_status' => 'empty',
                'rows' => [],
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                ],
            ],
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'collecting', 'task_id' => 901],
                    'meituan' => ['status' => 'pending_readback', 'task_id' => 902],
                ],
            ]
        ))->preview(80, '2026-07-26');
        $facts = $preview['sections']['today_revenue_management']['facts'];

        self::assertSame('available', $this->field($facts, 'whole_hotel_revenue')['status']);
        self::assertSame(0, $this->field($facts, 'whole_hotel_revenue')['value']);
        self::assertSame('available', $this->field($facts, 'whole_hotel_sold_room_nights')['status']);
        self::assertSame(0, $this->field($facts, 'whole_hotel_sold_room_nights')['value']);
        self::assertSame(0, $this->field($facts, 'whole_hotel_occupancy_rate')['value']);
        self::assertNull($this->field($facts, 'whole_hotel_adr')['value']);
        self::assertSame(0.0, $this->field($facts, 'whole_hotel_revpar')['value']);
        self::assertNull($this->field($facts, 'ota_revenue')['value']);
    }

    public function testSectionAdapterReturnsOnlyRequestedStableTemplateContract(): void
    {
        $this->insertReport(80, 80, '2026-07-26', 2, [
            'revenue' => 4200,
            'room_revenue' => 4000,
            'total_rooms' => 20,
            'salable_rooms' => 40,
        ], 4200, 20, 50);
        $service = new ManualNotificationBusinessPreviewService(
            fn(int $hotelId, string $date): array => $this->temporalFixture($hotelId, $date),
            fn(int $hotelId, string $date): array => $this->trustedOtaFixture($hotelId, $date),
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'readback_verified', 'task_id' => 701],
                    'meituan' => ['status' => 'readback_verified', 'task_id' => 702],
                ],
            ]
        );

        $result = $service->section('today_revenue_management', 80, '2026-07-26');

        self::assertSame('today_revenue_management', $result['section']['key']);
        self::assertSame('今日收益管理', $result['section']['title']);
        self::assertArrayNotHasKey('sections', $result);
        self::assertTrue($result['preview_only']);
    }

    public function testRunningAndCompletedTasksStayCollectingOrPendingReadbackWithoutFacts(): void
    {
        Db::name('platform_data_sync_tasks')->insertAll([
            [
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'data_type' => 'business',
                'ingestion_method' => 'browser_profile',
                'trigger_type' => 'manual',
                'status' => 'running',
                'started_at' => '2026-07-26 10:00:00',
                'finished_at' => null,
                'create_time' => '2026-07-26 10:00:00',
                'update_time' => '2026-07-26 10:01:00',
            ],
            [
                'tenant_id' => 80,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'data_type' => 'business',
                'ingestion_method' => 'browser_profile',
                'trigger_type' => 'manual',
                'status' => 'success',
                'started_at' => '2026-07-26 09:55:00',
                'finished_at' => '2026-07-26 10:02:00',
                'create_time' => '2026-07-26 09:55:00',
                'update_time' => '2026-07-26 10:02:00',
            ],
        ]);
        $service = new ManualNotificationBusinessPreviewService(
            static fn(): array => ['metric_scope' => 'ota_channel', 'present' => [], 'future' => [], 'review' => []],
            static fn(): array => [
                'data_status' => 'empty',
                'rows' => [],
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                ],
            ]
        );

        $preview = $service->preview(80, '2026-07-26');
        $today = $preview['sections']['today_revenue_management'];

        self::assertSame('collecting', $preview['status']);
        self::assertSame('collecting', $today['status']);
        self::assertSame('collecting', $today['ota_collection']['status']);
        self::assertSame('collecting', $today['ota_collection']['platforms']['ctrip']['status']);
        self::assertSame('pending_readback', $today['ota_collection']['platforms']['meituan']['status']);
        self::assertContains('ctrip_collecting', array_column($today['gaps'], 'code'));
        self::assertContains('meituan_pending_readback', array_column($today['gaps'], 'code'));
        self::assertNull($this->field($today['facts'], 'ota_revenue')['value']);
        self::assertNull($this->field($today['facts'], 'ota_orders')['value']);
        self::assertNull($this->field($today['facts'], 'ota_room_nights')['value']);
    }

    public function testExplicitCollectionFailureIsNotCollapsedIntoMissingData(): void
    {
        $service = new ManualNotificationBusinessPreviewService(
            static fn(): array => ['metric_scope' => 'ota_channel', 'present' => [], 'future' => [], 'review' => []],
            static fn(): array => [
                'data_status' => 'empty',
                'rows' => [],
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                ],
            ],
            static fn(): array => [
                'platforms' => [
                    'ctrip' => ['status' => 'collection_failed', 'task_id' => 1001],
                    'meituan' => ['status' => 'collection_failed', 'task_id' => 1002],
                ],
            ]
        );

        $preview = $service->preview(80, '2026-07-26');
        $today = $preview['sections']['today_revenue_management'];

        self::assertSame('collection_failed', $preview['status']);
        self::assertSame('collection_failed', $today['status']);
        self::assertSame('collection_failed', $today['ota_collection']['status']);
        self::assertContains('ctrip_collection_failed', array_column($today['gaps'], 'code'));
        self::assertContains('meituan_collection_failed', array_column($today['gaps'], 'code'));
        self::assertNull($this->field($today['facts'], 'ota_revenue')['value']);
    }

    public function testServiceSourceIsReadOnlyAndHasNoNotificationOrDeliverySideEffects(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../app/service/ManualNotificationBusinessPreviewService.php'
        );

        foreach ([
            '->insert(',
            '->insertGetId(',
            '->insertAll(',
            '->update(',
            '->delete(',
            'deliverToHotel(',
            'WechatRobotDeliveryService',
            'new ManualNotificationService',
            'ManualNotification.php',
            'curl_',
            'qyapi.weixin.qq.com',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringContainsString("->where('tenant_id', \$tenantId)", $source);
        self::assertStringContainsString("->where('hotel_id', \$hotelId)", $source);
        self::assertStringContainsString("->where('report_date', \$businessDate)", $source);
        self::assertStringContainsString("->where('status', 2)", $source);
    }

    /** @param array<string, mixed> $data */
    private function insertReport(
        int $tenantId,
        int $hotelId,
        string $date,
        int $status,
        array $data,
        int|float|null $revenue,
        ?int $roomCount,
        int|float|null $occupancy
    ): void {
        Db::name('daily_reports')->insert([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'report_date' => $date,
            'report_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'occupancy_rate' => $occupancy,
            'room_count' => $roomCount,
            'revenue' => $revenue,
            'status' => $status,
            'submitter_id' => 7,
            'create_time' => $date . ' 20:00:00',
            'update_time' => $date . ' 20:05:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function temporalFixture(int $hotelId, string $date): array
    {
        self::assertSame(80, $hotelId);
        self::assertSame('2026-07-26', $date);

        return [
            'metric_scope' => 'ota_channel',
            'present' => [
                'status' => 'ready',
                'as_of_time' => '2026-07-26 18:30:00',
                'metrics' => [
                    'ota_revenue' => 1300,
                    'ota_orders' => 10,
                    'ota_room_nights' => 12,
                ],
            ],
            'future' => [
                'status' => 'ready',
                'version' => [
                    'as_of_date' => '2026-07-26',
                    'forecast_run_id' => 'run-80-20260726',
                    'model_version' => 'coarse_trend_v1',
                ],
                'series' => [[
                    'date' => '2026-07-27',
                    'metrics' => [
                        'ota_revenue' => [
                            'direction' => 'up',
                            'lower_bound' => 1200,
                            'upper_bound' => 1700,
                            'confidence_level' => 'medium',
                        ],
                    ],
                ]],
            ],
            'review' => [
                'status' => 'ready',
                'forecast_run_id' => 'review-80-20260726',
                'items' => [[
                    'target_date' => '2026-07-26',
                    'metric_key' => 'ota_revenue',
                    'actual_value' => 1300,
                    'forecast_interval' => ['lower' => 1100, 'upper' => 1500],
                    'within_range' => true,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function trustedOtaFixture(int $hotelId, string $date): array
    {
        self::assertSame(80, $hotelId);
        self::assertSame('2026-07-26', $date);

        return [
            'data_status' => 'ready',
            'rows' => [
                [
                    'row_id' => 801,
                    'system_hotel_id' => 80,
                    'readback_verified' => true,
                    'source_trace_id' => 'trace-ctrip-801',
                    'sync_task_id' => 701,
                    'data_source_id' => 1701,
                    'data_date' => '2026-07-26',
                    'source' => 'ctrip',
                    'amount' => 800,
                    'quantity' => 7,
                    'book_order_num' => 6,
                ],
                [
                    'row_id' => 802,
                    'system_hotel_id' => 80,
                    'readback_verified' => true,
                    'source_trace_id' => 'trace-meituan-802',
                    'sync_task_id' => 702,
                    'data_source_id' => 1702,
                    'data_date' => '2026-07-26',
                    'source' => 'meituan',
                    'amount' => 500,
                    'quantity' => 5,
                    'book_order_num' => 4,
                ],
            ],
            'source_policy' => [
                'hotel_scope' => 'system_hotel_id_strict_exact_only',
                'readback_policy' => 'readback_verified_required_equals_1',
            ],
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function pmsForwardFixture(
        int $tenantId,
        int $hotelId,
        string $date
    ): array {
        self::assertSame(80, $tenantId);
        self::assertSame(80, $hotelId);
        self::assertSame('2026-07-26', $date);
        $dailyRows = [];
        for ($offset = 0; $offset < 31; $offset++) {
            $stayDate = (new \DateTimeImmutable($date))
                ->modify('+' . $offset . ' days')
                ->format('Y-m-d');
            $dailyRows[] = [
                'stay_date' => $stayDate,
                'remaining_sellable_rooms' => 6,
                'booked_rooms' => 9,
                'unavailable_rooms' => 1,
                'oversold_rooms' => 0,
                'room_fee' => 4500,
                'sold_room_nights' => 9,
                'sellable_room_nights' => 15,
                'occupancy_rate_percent' => 60,
                'adr' => 500,
                'revpar' => 300,
            ];
        }
        $horizons = [];
        foreach ([3, 7, 14, 21] as $days) {
            $horizons[] = [
                'horizon_days' => $days,
                'date_from' => '2026-07-27',
                'date_to' => (new \DateTimeImmutable($date))
                    ->modify('+' . $days . ' days')
                    ->format('Y-m-d'),
                'expected_days' => $days,
                'covered_days' => $days,
                'sellable_room_nights' => 15 * $days,
                'booked_room_nights' => 9 * $days,
                'remaining_sellable_room_nights' => 6 * $days,
                'unavailable_room_nights' => $days,
                'room_fee' => 4500 * $days,
                'occupancy_rate_percent' => 60,
                'adr' => 500,
                'revpar' => 300,
                'quality_status' => 'verified',
                'gap_codes' => [],
            ];
        }
        return [
            'id' => 980,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'provider' => 'dingdandao_pms',
            'business_date' => $date,
            'quality_status' => 'verified',
            'capture_status' => 'verified',
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'captured_at' => '2026-07-26 18:35:00',
            'summary' => [
                'total_room_fee' => 8745.6,
                'sold_room_nights' => 15,
                'derived_sellable_room_nights' => 15,
                'occupancy_rate_percent' => 100,
                'adr' => 583.04,
                'revpar' => 583.04,
            ],
            'forward_room_status' => [
                'contract_version' => 'dingdandao_forward_room_status.v1',
                'fact_scope' => 'whole_hotel_forward_room_status',
                'source_api_path' => '/v2/hm-b/pro/web/accom/roomStat/forward/v2',
                'data_status' => 'verified',
                'readback_status' => 'readback_verified',
                'as_of_date' => $date,
                'range_start_date' => $date,
                'range_end_date' => '2026-08-25',
                'requested_range_start_date' => $date,
                'requested_range_end_date' => '2026-08-25',
                'source_day_count' => 31,
                'display_day_count' => 21,
                'source_room_type_count' => 1,
                'total_room_count' => 16,
                'display_horizons' => [3, 7, 14, 21],
                'display_semantics' => 'future_days_after_as_of_date',
                'source_coverage_status' => 'complete',
                'source_gap_codes' => [],
                'daily_rows' => $dailyRows,
                'room_types' => [[
                    'provider_room_type_id' => 'room-type-all',
                    'room_type_name' => '全部房型',
                    'room_count' => 16,
                    'daily_rows' => $dailyRows,
                ]],
                'horizons' => $horizons,
                'reconciliation_status' => 'matched',
                'gap_codes' => [],
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $fields @return array<string, mixed> */
    private function field(array $fields, string $key): array
    {
        foreach ($fields as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }
        self::fail('Missing preview field: ' . $key);
    }
}
