<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;

final class InAppBrowserMeituanCaptureContractTest extends TestCase
{
    public function testSourceContainsBoundShortLivedCaptureContract(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/concern/PlatformDataSourceExecutionConcern.php'
        ) . (string)file_get_contents(
            dirname(__DIR__) . '/app/service/concern/PlatformInAppBrowserCaptureConcern.php'
        );
        self::assertStringContainsString('suxi_iab_meituan_capture.v1', $source);
        self::assertStringContainsString("'/api/v1/ebooking/common/pois'", $source);
        self::assertStringContainsString("'/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/workbench/simple'", $source);
        self::assertStringContainsString("'/api/v1/ebooking/workbench/business/analysis'", $source);
        self::assertStringContainsString("hash_equals(hash('sha256', \$expectedPoi), \$poiHash)", $source);
        self::assertStringContainsString("'interactive_browser'", (string)file_get_contents(
            dirname(__DIR__) . '/scripts/import_iab_meituan_capture.php'
        ));
    }

    public function testCaptureEntryDoesNotAcceptCredentialFields(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/import_iab_meituan_capture.php'
        );
        self::assertStringNotContainsString('cookie', strtolower($source));
        self::assertStringNotContainsString('authorization', strtolower($source));
        self::assertStringNotContainsString('password', strtolower($source));
    }

    public function testMatchingProtectedResponseBuildsBoundRowsAndCurrentSessionEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'verifiedInAppBrowserCaptureResult');
        $result = $method->invoke($service, $this->source(), $this->options());

        self::assertIsArray($result);
        self::assertSame('success', $result['status']);
        self::assertTrue($result['payload']['auth_status']['ok']);
        self::assertSame('matched', $result['payload']['platform_identity_validation']['status']);
        self::assertCount(2, $result['payload']['rows']);
        self::assertSame(7, $result['payload']['rows'][0]['detail_exposure']);
        self::assertSame(3, $result['payload']['rows'][1]['quantity']);
        self::assertSame(100.0, $result['payload']['rows'][1]['data_value']);
    }

    public function testMismatchedPoiHashIsRejectedBeforeRowsReachPersistence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'verifiedInAppBrowserCaptureResult');
        $options = $this->options();
        $options['in_app_browser_capture']['identity']['poi_id_sha256'] = hash('sha256', 'wrong-poi');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel identity mismatch');
        $method->invoke($service, $this->source(), $options);
    }

    public function testMatchingVisibleWorkbenchBuildsBoundRowsWithoutCredentialMaterial(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'verifiedInAppBrowserCaptureResult');
        $options = $this->options();
        $options['in_app_browser_capture'] = array_replace(
            $options['in_app_browser_capture'],
            [
                'contract_version' => 'suxi_iab_meituan_dom_capture.v1',
                'page_origin' => 'https://me.meituan.com',
                'page_path' => '/ebooking/merchant/ebIframe?iUrl=workbench',
                'visible_section' => '实时数据',
                'displayed_hotel_name' => '敦煌·漠蓝·Club·野奢度假民宿（鸣沙山月牙泉店）',
            ]
        );
        unset($options['in_app_browser_capture']['response_origin']);
        unset($options['in_app_browser_capture']['response_statuses']);
        unset($options['in_app_browser_capture']['identity']);

        $result = $method->invoke($service, $this->source(), $options);

        self::assertSame('success', $result['status']);
        self::assertSame(
            'authenticated_visible_workbench',
            $result['payload']['auth_status']['evidence_type']
        );
        self::assertTrue($result['payload']['capture_evidence']['visible_identity_matched']);
        self::assertCount(2, $result['payload']['rows']);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'enabled' => 1,
            'config' => [
                'poi_id' => 'poi-test-80',
                'partner_id' => 'partner-test-80',
                'poi_name' => '敦煌·漠蓝·Club·野奢度假民宿（鸣沙山月牙泉店）',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $capturedAt = new DateTimeImmutable('now', $timezone);
        return [
            'interactive_browser' => true,
            'in_app_browser_capture' => [
                'contract_version' => 'suxi_iab_meituan_capture.v1',
                'platform' => 'meituan',
                'data_source_id' => 68,
                'system_hotel_id' => 80,
                'data_date' => $capturedAt->format('Y-m-d'),
                'captured_at' => $capturedAt->format('Y-m-d H:i:s'),
                'response_origin' => 'https://eb.meituan.com',
                'response_statuses' => [
                    '/api/v1/ebooking/common/pois' => 200,
                    '/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/workbench/simple' => 200,
                    '/api/v1/ebooking/workbench/business/analysis' => 200,
                ],
                'identity' => [
                    'poi_id_sha256' => hash('sha256', 'poi-test-80'),
                    'partner_id_sha256' => hash('sha256', 'partner-test-80'),
                ],
                'facts' => [
                    'browse_users' => 7,
                    'stay_room_nights' => 3,
                    'sales_amount' => null,
                    'full_room_rate' => 100.0,
                    'lost_order_count' => null,
                ],
                'rankings' => [],
            ],
        ];
    }
}
