<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;

final class PlatformDataSyncServiceTest extends TestCase
{
    public function testManualPayloadNormalizesRowsForOnlineDailyDataWithTraceability(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026/05/27',
                    'amount' => '1288.50',
                    'room_nights' => '6',
                    'orders' => '4',
                    'rating' => '4.7',
                    'list_exposure' => '1000',
                    'detail_exposure' => '250',
                    'flow_rate' => '25%',
                ],
            ],
        ], [
            'id' => 12,
            'name' => '携程手工导入',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'ingestion_method' => 'manual',
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-1001', $rows[0]['hotel_id']);
        self::assertSame('2026-05-27', $rows[0]['data_date']);
        self::assertSame(1288.5, $rows[0]['amount']);
        self::assertSame(6, $rows[0]['quantity']);
        self::assertSame(4, $rows[0]['book_order_num']);
        self::assertSame(25.0, $rows[0]['flow_rate']);
        self::assertSame('ctrip', $rows[0]['source']);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(7, $rows[0]['system_hotel_id']);
        self::assertSame(12, $rows[0]['data_source_id']);
        self::assertSame(34, $rows[0]['sync_task_id']);
        self::assertSame('manual', $rows[0]['ingestion_method']);
        self::assertStringContainsString('"data_source_name":"携程手工导入"', $rows[0]['raw_data']);
    }

    public function testManualPayloadRejectsMissingBusinessRows(): void
    {
        $service = new PlatformDataSyncService();

        self::assertSame([], $service->normalizeRowsFromPayload(['rows' => []], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
        ], 34));
    }

    public function testReviewAndCommentPayloadsAreSkippedUnlessExplicitlyEnabled(): void
    {
        $service = new PlatformDataSyncService();

        foreach (['review', 'reviews', 'comment', 'comments'] as $dataType) {
            $rows = $service->normalizeRowsFromPayload([
                'rows' => [
                    [
                        'hotel_id' => 'ctrip-1001',
                        'data_date' => '2026-05-28',
                        'score' => '3.0',
                        'content' => 'This review must not be collected.',
                    ],
                ],
            ], [
                'id' => 12,
                'platform' => 'ctrip',
                'data_type' => $dataType,
                'system_hotel_id' => 7,
            ], 34);

            self::assertSame([], $rows, $dataType . ' payload should not be normalized');
        }
    }

    public function testExplicitManualReviewPayloadKeepsScoresAndRedactedSummary(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-28',
                    'score' => '3.0',
                    'review_count' => 2,
                    'tags' => ['clean', 'service'],
                    'content' => 'Room was clean. Phone 13800138000 should not be stored.',
                ],
            ],
        ], [
            'id' => 12,
            'name' => 'Ctrip reviews',
            'platform' => 'ctrip',
            'data_type' => 'review',
            'system_hotel_id' => 7,
            'ingestion_method' => 'manual',
            'config' => ['allow_review' => true],
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame('review', $rows[0]['data_type']);
        self::assertSame(3.0, $rows[0]['comment_score']);
        self::assertSame(2, $rows[0]['quantity']);
        self::assertSame('manual', $rows[0]['ingestion_method']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringContainsString('"review_summary"', $rawData);
        self::assertStringContainsString('"tags":["clean","service"]', $rawData);
        self::assertStringNotContainsString('13800138000', $rawData);
        self::assertStringNotContainsString('Phone 13800138000', $rawData);
    }

    public function testOrderPayloadIsRedactedBeforeNormalizedRawDataIsStored(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-28',
                    'orderId' => 'CTRIP-ORDER-202605280001',
                    'guestName' => 'Alice Zhang',
                    'guestPhone' => '13812345678',
                    'mobile' => '13987654321',
                    'certificateNo' => 'ID-SECRET-001',
                    'remark' => 'late arrival, call guest directly',
                    'amount' => '588.00',
                    'nights' => '2',
                    'orderStatus' => 'confirmed',
                ],
            ],
        ], [
            'id' => 13,
            'name' => 'Ctrip orders',
            'platform' => 'ctrip',
            'data_type' => 'order',
            'system_hotel_id' => 7,
            'ingestion_method' => 'manual',
        ], 35);

        self::assertCount(1, $rows);
        self::assertSame('order', $rows[0]['data_type']);
        self::assertSame(588.0, $rows[0]['amount']);
        self::assertSame(2, $rows[0]['quantity']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringNotContainsString('CTRIP-ORDER-202605280001', $rawData);
        self::assertStringNotContainsString('Alice Zhang', $rawData);
        self::assertStringNotContainsString('13812345678', $rawData);
        self::assertStringNotContainsString('13987654321', $rawData);
        self::assertStringNotContainsString('ID-SECRET-001', $rawData);
        self::assertStringNotContainsString('late arrival', $rawData);

        $decoded = json_decode($rawData, true);
        self::assertIsArray($decoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decoded['row']['order_id_hash'] ?? ''));
        self::assertSame('A***', $decoded['row']['guest_name_masked'] ?? null);
        self::assertSame('*******5678', $decoded['row']['guest_phone_masked'] ?? null);
        self::assertArrayNotHasKey('guestName', $decoded['row']);
        self::assertArrayNotHasKey('certificateNo', $decoded['row']);
        self::assertArrayNotHasKey('remark', $decoded['row']);
    }

    public function testCtripBusinessAndQualityFieldsMapIntoExistingDailyColumns(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                [
                    'hotelId' => 'ctrip-2001',
                    'hotelName' => 'Business Hotel',
                    'statDate' => '2026-05-27',
                    'checkoutRevenue' => '2888.80',
                    'checkoutRoomNights' => '18',
                    'orderQuantity' => '12',
                    'averagePrice' => '160.49',
                    'visitorTotal' => '320',
                    'serviceScore' => '92.5',
                    'psiScore' => '88.6',
                    'hotelCollect' => '17',
                ],
            ],
        ], [
            'id' => 21,
            'name' => 'Ctrip business report',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'ingestion_method' => 'browser_profile',
        ], 41);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-2001', $rows[0]['hotel_id']);
        self::assertSame('Business Hotel', $rows[0]['hotel_name']);
        self::assertSame('2026-05-27', $rows[0]['data_date']);
        self::assertSame(2888.8, $rows[0]['amount']);
        self::assertSame(18, $rows[0]['quantity']);
        self::assertSame(12, $rows[0]['book_order_num']);
        self::assertSame(160.49, $rows[0]['data_value']);
        self::assertStringContainsString('"serviceScore":"92.5"', $rows[0]['raw_data']);
        self::assertStringContainsString('"psiScore":"88.6"', $rows[0]['raw_data']);
    }

    public function testAdvertisingRecordsMapCostTrafficConversionAndBookings(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                'records' => [
                    [
                        'hotelId' => 'ctrip-3001',
                        'hotelName' => 'Ad Hotel',
                        'date' => '2026-05-27',
                        'campaignId' => 'campaign-1',
                        'impressions' => '10000',
                        'clicks' => '320',
                        'cvr' => '8.5%',
                        'todayCost' => '256.75',
                        'bookings' => '16',
                        'nights' => '23',
                        'orderAmount' => '1888.00',
                        'roas' => '7.35',
                    ],
                ],
            ],
        ], [
            'id' => 22,
            'name' => 'Ctrip ad report',
            'platform' => 'ctrip',
            'data_type' => 'ads',
            'system_hotel_id' => 7,
            'ingestion_method' => 'browser_profile',
        ], 42);

        self::assertCount(1, $rows);
        self::assertSame('advertising', $rows[0]['data_type']);
        self::assertSame(256.75, $rows[0]['amount']);
        self::assertSame(23, $rows[0]['quantity']);
        self::assertSame(16, $rows[0]['book_order_num']);
        self::assertSame(10000, $rows[0]['list_exposure']);
        self::assertSame(320, $rows[0]['detail_exposure']);
        self::assertSame(8.5, $rows[0]['flow_rate']);
        self::assertSame(16, $rows[0]['order_submit_num']);
        self::assertSame(7.35, $rows[0]['data_value']);
        self::assertStringContainsString('"orderAmount":"1888.00"', $rows[0]['raw_data']);
    }

    public function testOrderListPayloadMapsAmountRoomNightsAndAveragePriceWithoutPii(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                'orderList' => [
                    [
                        'hotelId' => 'ctrip-4001',
                        'hotelName' => 'Order Hotel',
                        'orderDate' => '2026-05-27 10:30:00',
                        'orderId' => 'ORDER-4001',
                        'guestName' => 'Chen Ming',
                        'mobile' => '13800009999',
                        'totalAmount' => '1200.00',
                        'roomCount' => '2',
                        'nights' => '3',
                        'orderStatusDesc' => 'confirmed',
                    ],
                ],
            ],
        ], [
            'id' => 23,
            'name' => 'Ctrip orders',
            'platform' => 'ctrip',
            'data_type' => 'orders',
            'system_hotel_id' => 7,
            'ingestion_method' => 'browser_profile',
        ], 43);

        self::assertCount(1, $rows);
        self::assertSame('order', $rows[0]['data_type']);
        self::assertSame(1200.0, $rows[0]['amount']);
        self::assertSame(6, $rows[0]['quantity']);
        self::assertSame(1, $rows[0]['book_order_num']);
        self::assertSame(200.0, $rows[0]['data_value']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringNotContainsString('ORDER-4001', $rawData);
        self::assertStringNotContainsString('Chen Ming', $rawData);
        self::assertStringNotContainsString('13800009999', $rawData);
        self::assertStringContainsString('"order_id_hash"', $rawData);
        self::assertStringContainsString('"mobile_masked":"*******9999"', $rawData);
    }

    public function testRawPayloadStorageSanitizerRedactsNestedOrderPiiAndSecrets(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizePayloadForStorage');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'headers' => [
                'Cookie' => 'session=secret-cookie',
                'Authorization' => 'Bearer secret-token',
            ],
            'data' => [
                'orderList' => [
                    [
                        'orderId' => 'MT-ORDER-0001',
                        'guestName' => 'Bob Lee',
                        'phone' => '13700001111',
                        'contactMobile' => '13600002222',
                        'idCardNo' => 'IDCARD-SECRET',
                        'customerRemark' => 'do not store this remark',
                        'amount' => 388,
                    ],
                ],
            ],
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('secret-cookie', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('MT-ORDER-0001', $encoded);
        self::assertStringNotContainsString('Bob Lee', $encoded);
        self::assertStringNotContainsString('13700001111', $encoded);
        self::assertStringNotContainsString('13600002222', $encoded);
        self::assertStringNotContainsString('IDCARD-SECRET', $encoded);
        self::assertStringNotContainsString('do not store this remark', $encoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($payload['data']['orderList'][0]['order_id_hash'] ?? ''));
        self::assertSame('B***', $payload['data']['orderList'][0]['guest_name_masked'] ?? null);
        self::assertSame('*******1111', $payload['data']['orderList'][0]['phone_masked'] ?? null);
    }

    public function testCsvImportFileParsesRowsWithHeaders(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_csv_');
        file_put_contents($path, "data_date,hotel_id,amount\n2026-05-28,ctrip-1,328.5\n");

        try {
            $rows = $service->parseImportFile($path, 'ota.csv');
        } finally {
            @unlink($path);
        }

        self::assertSame([
            [
                'data_date' => '2026-05-28',
                'hotel_id' => 'ctrip-1',
                'amount' => '328.5',
            ],
        ], $rows);
    }

    public function testJsonImportFileParsesRowsEnvelope(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_json_');
        file_put_contents($path, json_encode([
            'rows' => [
                ['data_date' => '2026-05-28', 'hotel_id' => 'meituan-1', 'orders' => 3],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $rows = $service->parseImportFile($path, 'ota.json');
        } finally {
            @unlink($path);
        }

        self::assertSame('meituan-1', $rows[0]['hotel_id']);
        self::assertSame(3, $rows[0]['orders']);
    }

    public function testImportFileRejectsUnsupportedExtension(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_txt_');
        file_put_contents($path, 'data');

        try {
            $this->expectException(\RuntimeException::class);
            $service->parseImportFile($path, 'ota.txt');
        } finally {
            @unlink($path);
        }
    }

    public function testDataSourceSanitizerDoesNotExposeHeaderSecrets(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSourceRow');
        $method->setAccessible(true);

        $row = $method->invoke($service, [
            'id' => 1,
            'config_json' => json_encode([
                'url' => 'https://example.com/data',
                'headers' => [
                    'Authorization' => 'Bearer secret-token',
                    'Content-Type' => 'application/json',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'secret_json' => json_encode(['cookies' => 'abcdef123456'], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('https://example.com/data', $row['config']['url']);
        self::assertSame('application/json', $row['config']['headers']['Content-Type']);
        self::assertStringNotContainsString('secret-token', json_encode($row, JSON_UNESCAPED_UNICODE));
        self::assertTrue($row['has_secret']);
        self::assertSame('abcd...3456', $row['cookies_preview']);
    }

    public function testCtripBrowserProfileAdapterSupportsOnlyCtripBrowserProfileSources(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn() => []);

        self::assertTrue($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ]));
        self::assertTrue($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'profile_browser',
        ]));
        self::assertFalse($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ]));
    }

    public function testCtripBrowserProfileAdapterReturnsWaitingConfigWhenProfileIsMissing(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot();

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static fn() => []);
            $result = $adapter->fetch([
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'system_hotel_id' => 7,
                'config' => [
                    'profile_id' => 'hotel_001',
                ],
            ], ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertStringContainsString('storage/ctrip_profile_hotel_001', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterReturnsWaitingConfigWhenLoginExpired(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Ctrip login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('Ctrip login expired.', $result['message']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRejectsConcurrentCaptureForSameProfile(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $lockDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        mkdir($lockDir, 0775, true);
        $lockHandle = fopen($lockDir . DIRECTORY_SEPARATOR . 'profile_capture_ctrip_hotel_001.lock', 'c+');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [
                    ['hotel_id' => '24588', 'data_date' => '2026-05-31', 'amount' => 100],
                ],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('already running', $result['message']);
            self::assertSame('ctrip:hotel_001', $result['payload']['lock_key']);
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterFailsWhenNoBusinessRowsAreParsed(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [],
                'business' => [],
                'traffic' => [],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('no business rows', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterAllowsFieldCoverageWarningWhenRowsExist(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => [
                    'status' => 'fail',
                    'failed_check_ids' => ['field_coverage'],
                    'checks' => [
                        ['id' => 'field_coverage', 'status' => 'fail', 'actual' => '69.84%', 'expected' => '>=80%'],
                    ],
                ],
                'responses' => [['url' => 'https://ebooking.ctrip.com/restapi/test']],
                'standard_rows' => [
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'business',
                        'amount' => '1288.50',
                        'room_nights' => '6',
                        'orders' => '4',
                        'source_trace_id' => 'trace-soft-gate-row',
                    ],
                ],
                'business' => [],
                'traffic' => [],
            ]));
            $source = $this->ctripBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertStringContainsString('Field coverage warning', $result['message']);
            self::assertCount(1, $result['payload']['rows']);
            self::assertSame(['field_coverage'], $result['payload']['capture_gate_warning']['failed_check_ids']);
            self::assertSame([], $result['payload']['capture_gate_warning']['blocking_failed_check_ids']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRowsNormalizeWithTraceability(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'business',
                        'amount' => '1288.50',
                        'room_nights' => '6',
                        'orders' => '4',
                        'source_trace_id' => 'trace-business-row',
                    ],
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'traffic',
                        'list_exposure' => '1000',
                        'detail_exposure' => '250',
                        'flow_rate' => '25%',
                        'source_trace_id' => 'trace-traffic-row',
                    ],
                ],
            ]));
            $source = $this->ctripBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $result['payload']['rows']);
            self::assertSame('browser_profile', $result['payload']['rows'][0]['acquisition_method']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 88);
            self::assertCount(2, $rows);

            $businessRow = $rows[0]['data_type'] === 'business' ? $rows[0] : $rows[1];
            $trafficRow = $rows[0]['data_type'] === 'traffic' ? $rows[0] : $rows[1];

            self::assertSame('business', $businessRow['data_type']);
            self::assertSame(1288.5, $businessRow['amount']);
            self::assertSame(6, $businessRow['quantity']);
            self::assertSame(4, $businessRow['book_order_num']);
            self::assertSame('browser_profile', $businessRow['ingestion_method']);
            self::assertSame('trace-business-row', $businessRow['source_trace_id']);

            self::assertSame('traffic', $trafficRow['data_type']);
            self::assertSame(1000, $trafficRow['list_exposure']);
            self::assertSame(250, $trafficRow['detail_exposure']);
            self::assertSame(25.0, $trafficRow['flow_rate']);
            self::assertSame('trace-traffic-row', $trafficRow['source_trace_id']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterSupportsOnlyMeituanBrowserProfileSources(): void
    {
        $adapter = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn() => []);

        self::assertTrue($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ]));
        self::assertTrue($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'profile_browser',
        ]));
        self::assertFalse($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ]));
    }

    public function testMeituanBrowserProfileAdapterReturnsWaitingConfigWhenProfileIsMissing(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot();

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static fn() => []);
            $result = $adapter->fetch([
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'system_hotel_id' => 7,
                'config' => [
                    'store_id' => 'store_001',
                ],
            ], ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertStringContainsString('storage/meituan_profile_store_001', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterReturnsWaitingConfigWhenLoginExpired(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Meituan login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('Meituan login expired.', $result['message']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterFailsWhenNoBusinessRowsAreParsed(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [],
                'orders' => [],
                'ads' => [],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('no business rows', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterRowsNormalizeWithTraceability(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-05-31',
                        'list_exposure' => '900',
                        'detail_exposure' => '180',
                        'flow_rate' => '20%',
                        'source_trace_id' => 'mt-traffic-row',
                    ],
                ],
                'orders' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-05-31',
                        'amount' => '988.00',
                        'room_nights' => '5',
                        'orders' => '3',
                        'source_trace_id' => 'mt-order-row',
                    ],
                ],
            ]));
            $source = $this->meituanBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $result['payload']['rows']);
            self::assertSame('browser_profile', $result['payload']['rows'][0]['acquisition_method']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 89);
            self::assertCount(2, $rows);

            $trafficRow = $rows[0]['data_type'] === 'traffic' ? $rows[0] : $rows[1];
            $orderRow = $rows[0]['data_type'] === 'order' ? $rows[0] : $rows[1];

            self::assertSame('traffic', $trafficRow['data_type']);
            self::assertSame('meituan', $trafficRow['source']);
            self::assertSame(900, $trafficRow['list_exposure']);
            self::assertSame(180, $trafficRow['detail_exposure']);
            self::assertSame(20.0, $trafficRow['flow_rate']);
            self::assertSame('mt-traffic-row', $trafficRow['source_trace_id']);

            self::assertSame('order', $orderRow['data_type']);
            self::assertSame(988.0, $orderRow['amount']);
            self::assertSame(5, $orderRow['quantity']);
            self::assertSame(3, $orderRow['book_order_num']);
            self::assertSame('mt-order-row', $orderRow['source_trace_id']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function ctripBrowserProfileSource(): array
    {
        return [
            'id' => 77,
            'name' => 'Ctrip Profile Source',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'hotel_001',
                'hotel_id' => '24588',
                'hotel_name' => 'Ctrip Demo Hotel',
                'capture_sections' => 'core',
            ],
        ];
    }

    private function meituanBrowserProfileSource(): array
    {
        return [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'store_id' => 'store_001',
                'poi_id' => '68471',
                'poi_name' => 'Meituan Demo Hotel',
                'partner_id' => 'partner_001',
                'capture_sections' => 'traffic,orders',
            ],
        ];
    }

    private function createCtripBrowserProfileTestRoot(?string $profileId = null): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ctrip_browser_profile_adapter_' . bin2hex(random_bytes(4));
        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs', '// test script');
        if ($profileId !== null) {
            mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $profileId, 0775, true);
        }

        return $root;
    }

    private function createMeituanBrowserProfileTestRoot(?string $storeId = null): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'meituan_browser_profile_adapter_' . bin2hex(random_bytes(4));
        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs', '// test script');
        if ($storeId !== null) {
            mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $storeId, 0775, true);
        }

        return $root;
    }

    private function captureRunner(array $payload): callable
    {
        return static function (array $args) use ($payload): array {
            $outputPath = '';
            foreach ($args as $arg) {
                if (str_starts_with((string)$arg, '--output=')) {
                    $outputPath = substr((string)$arg, strlen('--output='));
                    break;
                }
            }
            if ($outputPath === '') {
                return ['success' => false, 'message' => 'missing output path', 'stdout' => '', 'stderr' => ''];
            }
            file_put_contents($outputPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
