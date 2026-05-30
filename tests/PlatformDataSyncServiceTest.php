<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
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
}
