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
            ]
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
        self::assertSame('readback_verified', $today['ota_platforms']['ctrip']['status']);
        self::assertSame(800, $today['ota_platforms']['ctrip']['metrics']['revenue']);
        self::assertSame(6, $today['ota_platforms']['ctrip']['metrics']['orders']);
        self::assertSame(7, $today['ota_platforms']['ctrip']['metrics']['room_nights']);
        self::assertSame(
            '2026-07-26 18:31:00',
            $today['ota_platforms']['ctrip']['source']['collected_at']
        );
        self::assertSame('readback_verified', $today['ota_platforms']['meituan']['status']);
        self::assertSame(500, $today['ota_platforms']['meituan']['metrics']['revenue']);
        self::assertSame(4, $today['ota_platforms']['meituan']['metrics']['orders']);
        self::assertSame(5, $today['ota_platforms']['meituan']['metrics']['room_nights']);
        self::assertSame(
            '2026-07-26 18:32:00',
            $today['ota_platforms']['meituan']['source']['collected_at']
        );

        $future = $preview['sections']['future_room_status'];
        self::assertSame('partial', $future['status']);
        self::assertSame('not_configured', $this->field($future['facts'], 'future_sellable_room_nights')['status']);
        self::assertSame('forecast_available', $future['forecasts'][0]['status']);
        self::assertSame('ota_channel', $future['forecasts'][0]['scope']);
        self::assertStringContainsString('不是全酒店远期房态事实', $future['forecasts'][0]['note']);
        self::assertContains(
            'whole_hotel_forward_room_status_source_not_configured',
            array_column($future['gaps'], 'code')
        );

        $review = $preview['sections']['daily_review'];
        self::assertSame('ready', $review['status']);
        self::assertSame('2026-07-26', $review['reviews'][0]['target_date']);
        self::assertSame('ota_channel', $review['reviews'][0]['scope']);
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
        self::assertSame(
            ['revenue' => null, 'orders' => null, 'room_nights' => null],
            $today['ota_platforms']['ctrip']['metrics']
        );
        self::assertSame(
            ['revenue' => null, 'orders' => null, 'room_nights' => null],
            $today['ota_platforms']['meituan']['metrics']
        );
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
                    'data_date' => '2026-07-26',
                    'source' => 'ctrip',
                    'amount' => 800,
                    'quantity' => 7,
                    'book_order_num' => 6,
                    'collected_at' => '2026-07-26 18:31:00',
                ],
                [
                    'data_date' => '2026-07-26',
                    'source' => 'meituan',
                    'amount' => 500,
                    'quantity' => 5,
                    'book_order_num' => 4,
                    'collected_at' => '2026-07-26 18:32:00',
                ],
            ],
            'source_policy' => [
                'hotel_scope' => 'system_hotel_id_strict_exact_only',
                'readback_policy' => 'readback_verified_required_equals_1',
            ],
            'data_gaps' => [],
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
