<?php
declare(strict_types=1);

namespace tests;

use app\service\OtaBrowserAssistImportService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OtaBrowserAssistImportServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testNormalizePlatformIdentityEvidenceWithoutCookieOrFullUrl(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 58,
            'generatedAt' => '2026-06-30 10:30:00',
            'platformIdentity' => [
                'platform' => 'meituan',
                'updatedAt' => '2026-06-30 10:20:00',
                'partnerId' => '313720',
                'poiId' => '888754073',
                'evidence' => [
                    [
                        'source' => 'performance_resource',
                        'host' => 'eb.meituan.com',
                        'path' => '/api/v1/ebooking/diagnosis/analysis/detail',
                        'fields' => ['partnerId', 'poiId'],
                    ],
                ],
            ],
        ]);

        self::assertSame(1, $result['summary']['row_count']);
        self::assertSame(['meituan'], $result['summary']['platforms']);
        self::assertSame(['platform_identity'], $result['summary']['data_types']);
        self::assertSame('platform_identity', $result['packages'][0]['data_type']);

        $row = $result['rows'][0];
        self::assertSame('platform_identity', $row['data_type']);
        self::assertSame('888754073', $row['hotel_id']);
        self::assertSame('313720', $row['partner_id']);
        self::assertSame('888754073', $row['poi_id']);
        self::assertSame(1, $row['data_value']);
        self::assertSame('browser_assist_dom:browser_assist_platform_identity', $row['capture_evidence']['capture_source']);
        self::assertArrayNotHasKey('url', $row);
        self::assertStringNotContainsString('diagnosisAnalysisType', json_encode($result, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('Cookie', json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function testAggregateImportStatusDoesNotPromotePartialPackageToSuccess(): void
    {
        $service = new OtaBrowserAssistImportService();

        self::assertSame('success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'success'],
        ]]));
        self::assertSame('partial_success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'partial_success'],
        ]]));
        self::assertSame('partial_success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'failed'],
        ]]));
        self::assertSame('failed', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'failed'],
            ['status' => 'unknown'],
        ]]));
    }

    public function testNormalizeSelectedCtripAndQunarRealtimeFactsWithoutDefaultingZero(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'data_date' => '2026-07-28',
            'snapshot_time' => '2026-07-28 21:38:00',
            'ctripStats' => [
                'sourceUrl' => 'https://ebooking.ctrip.com/home/mainland?secret=must-not-persist',
                'identityEvidence' => [
                    'status' => 'operator_confirmed',
                    'evidenceType' => 'authenticated_page_header',
                    'systemHotelId' => 80,
                    'expectedHotelName' => '敦煌漠蓝新',
                    'observedHotelName' => '敦煌·漠蓝Club·野奢民宿(鸣沙山月牙泉店)',
                    'confirmedAt' => '2026-07-28 21:38:00',
                    'cookie' => 'must-not-persist',
                ],
                'sourceSurfaces' => [
                    [
                        'surface' => 'home_channel_card',
                        'channel' => 'ctrip',
                        'observedAt' => '2026-07-28 21:37:00',
                        'fields' => ['starting_price', 'booking_order_count'],
                        'cookie' => 'must-not-persist',
                    ],
                ],
                'metrics' => [
                    'ctrip' => [
                        'realtimeVisitors' => 76,
                        'lastWeekVisitors' => 195,
                        'bookingOrderCount' => 0,
                        'inHouseRoomNights' => 4,
                        'realtimeRank' => 588,
                        'competitorRank' => 24,
                        'competitorTotal' => 26,
                        'startingPrice' => 0.00,
                    ],
                    'qunar' => [
                        'realtimeVisitors' => 25,
                        'visitorPeerAvg' => 59,
                        'visitorLagging' => true,
                        'bookingOrderCount' => 0,
                        'orderConversionRate' => 0,
                        'conversionPeerAvg' => 7.69,
                        'conversionLagging' => true,
                    ],
                ],
            ],
        ]);

        self::assertSame(3, $result['summary']['row_count']);
        foreach ($result['packages'] as $package) {
            self::assertSame('browser_assist_dom', $package['ingestion_method']);
        }
        $rows = $result['rows'];
        $ctripTraffic = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:ctrip'
                && ($row['data_type'] ?? '') === 'traffic'
        ))[0];
        self::assertSame(76.0, $ctripTraffic['detail_exposure']);
        self::assertSame(0.0, $ctripTraffic['book_order_num']);
        self::assertSame(4.0, $ctripTraffic['quantity']);
        self::assertSame(0.0, $ctripTraffic['raw_data']['metrics']['starting_price']);
        self::assertSame(195, $ctripTraffic['raw_data']['metrics']['last_week_visitors']);
        self::assertSame(
            'operator_confirmed',
            $ctripTraffic['browser_assist_identity']['status']
        );
        self::assertSame(
            'authenticated_page_header',
            $ctripTraffic['raw_data']['browser_assist_identity']['evidence_type']
        );
        self::assertSame('home_channel_card', $ctripTraffic['raw_data']['source_surfaces'][0]['surface']);

        $ctripRank = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:ctrip:rank'
        ))[0];
        self::assertSame(588.0, $ctripRank['rank']);
        self::assertSame(24, $ctripRank['raw_data']['rank_metrics']['competitor_rank']);
        self::assertSame(26, $ctripRank['raw_data']['rank_metrics']['competitor_total']);

        $qunarTraffic = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:qunar'
        ))[0];
        self::assertSame(25.0, $qunarTraffic['detail_exposure']);
        self::assertSame(0.0, $qunarTraffic['book_order_num']);
        self::assertSame(0.0, $qunarTraffic['flow_rate']);
        self::assertTrue($qunarTraffic['raw_data']['metrics']['visitor_lagging']);
        self::assertSame(7.69, $qunarTraffic['raw_data']['metrics']['conversion_peer_avg']);
        self::assertTrue($qunarTraffic['raw_data']['metrics']['conversion_lagging']);

        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($serialized);
        self::assertStringNotContainsString('must-not-persist', $serialized);
        self::assertStringNotContainsString('cookie', strtolower($serialized));
    }

    public function testNormalizeMeituanRealtimeFactsPreservesAuthenticatedHeaderIdentity(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'data_date' => '2026-08-12',
            'snapshot_time' => '2026-08-12 20:39:48',
            'meituanStats' => [
                'identityEvidence' => [
                    'status' => 'operator_confirmed',
                    'evidenceType' => 'authenticated_page_header',
                    'systemHotelId' => 80,
                    'expectedHotelName' => '敦煌漠蓝新',
                    'observedHotelName' => '敦煌·漠蓝·Club·野奢度假民宿（鸣沙山月牙泉店）',
                    'confirmedAt' => '2026-08-12 20:39:48',
                ],
                'metrics' => [
                    'browseUsers' => 264,
                ],
            ],
        ]);

        self::assertSame(1, $result['summary']['row_count']);
        $row = $result['rows'][0];
        self::assertSame(264.0, $row['detail_exposure']);
        self::assertSame('operator_confirmed', $row['browser_assist_identity']['status']);
        self::assertSame('authenticated_page_header', $row['browser_assist_identity']['evidence_type']);
        self::assertSame('敦煌漠蓝新', $row['browser_assist_identity']['expected_hotel_name']);
        self::assertSame(
            $row['browser_assist_identity'],
            $row['raw_data']['browser_assist_identity']
        );
    }
}
