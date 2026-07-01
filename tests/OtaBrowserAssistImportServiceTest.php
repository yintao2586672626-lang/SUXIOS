<?php
declare(strict_types=1);

namespace tests;

use app\service\OtaBrowserAssistImportService;
use PHPUnit\Framework\TestCase;

final class OtaBrowserAssistImportServiceTest extends TestCase
{
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
}
