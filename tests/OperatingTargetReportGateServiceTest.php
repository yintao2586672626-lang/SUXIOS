<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingTargetReportGateService;
use app\service\ManualNotificationTestTargetService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingTargetReportGateServiceTest extends TestCase
{
    private const HOTEL_ID = 5;
    private const ROBOT_ID = 2;
    private const ROBOT_NAME = '宿析OS云端日报';

    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/operating_target_report_gate_' . getmypid() . '.sqlite';
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
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS competitor_wechat_robot ('
            . 'id INTEGER PRIMARY KEY, '
            . 'store_id INTEGER NOT NULL, '
            . 'notification_scope VARCHAR(40) NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'status INTEGER NOT NULL)'
        );
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('competitor_wechat_robot')->insert([
            'id' => self::ROBOT_ID,
            'store_id' => self::HOTEL_ID,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => self::ROBOT_NAME,
            'status' => 1,
        ]);
    }

    public function testReadyWholeHotelPreviewAllowsFormalSendDecisionWithoutSending(): void
    {
        $service = new OperatingTargetReportGateService();

        $result = $service->pagePreview($this->readyPreview(), '宿析测试酒店');

        self::assertSame('preview_ready', $result['status']);
        self::assertSame('preview_only', $result['delivery_status']);
        self::assertSame(64, strlen($result['preview_fingerprint']));
        self::assertSame(
            $result['preview_fingerprint'],
            $result['test_push_gate']['required_preview_fingerprint']
        );
        self::assertTrue($result['formal_send_gate']['allowed']);
        self::assertSame('authorization_required', $result['test_push_gate']['status']);
        self::assertStringContainsString('页面预览，未触发任何外部发送', $result['payload']['markdown']['content']);
        self::assertStringContainsString('全酒店实际营收：4000元', $result['payload']['markdown']['content']);
        self::assertStringContainsString('OTA 渠道事实不能替代全酒店经营事实', $result['payload']['markdown']['content']);
    }

    public function testMissingOrBlockedWholeHotelFactsAlwaysBlockFormalSend(): void
    {
        $service = new OperatingTargetReportGateService();
        $preview = $this->readyPreview();
        $preview['status'] = 'partial';
        $preview['facts']['actual_revenue'] = null;
        $preview['facts']['quality_status'] = 'collection_failed';
        $preview['gaps'] = [
            ['code' => 'actual_revenue_missing', 'message' => '未取得全酒店实际营收。'],
        ];

        $gate = $service->formalSendGate($preview);
        $codes = array_column($gate['blockers'], 'code');

        self::assertFalse($gate['allowed']);
        self::assertSame('formal_send_blocked', $gate['status']);
        self::assertContains('operating_target_not_ready', $codes);
        self::assertContains('operating_fact_quality_unverified', $codes);
        self::assertContains('actual_revenue_missing', $codes);
        self::assertContains('unresolved_data_gaps', $codes);

        $preview['status'] = 'ready';
        $preview['facts']['actual_revenue'] = 4000;
        $preview['facts']['quality_status'] = 'verified';
        $preview['facts']['fact_scope'] = 'ctrip_ota';
        $preview['gaps'] = [];
        $scopeGate = $service->formalSendGate($preview);

        self::assertFalse($scopeGate['allowed']);
        self::assertContains('operating_fact_scope_unsupported', array_column($scopeGate['blockers'], 'code'));
    }

    public function testUnauthorizedTestPushNeverInvokesInjectedDispatcher(): void
    {
        $calls = [];
        $service = new OperatingTargetReportGateService(
            null,
            static function (array $payload, array $context) use (&$calls): array {
                $calls[] = [$payload, $context];
                return ['success' => true];
            }
        );
        $preview = $this->readyPreview();
        $pagePreview = $service->pagePreview($preview, '宿析测试酒店');

        $result = $service->authorizedTestPush($preview, '宿析测试酒店', [
            'approved' => false,
            'purpose' => OperatingTargetReportGateService::TEST_PUSH_PURPOSE,
            'actor_id' => 7,
            'approval_reference' => 'approval-local-001',
            'test_destination' => '隔离测试接收器',
            'test_only' => true,
            'target_robot_id' => self::ROBOT_ID,
            'target_robot_name' => self::ROBOT_NAME,
            'first_confirmation' => true,
            'second_confirmation' => true,
            'preview_fingerprint' => $pagePreview['preview_fingerprint'],
        ]);

        self::assertSame('test_push_blocked', $result['delivery_status']);
        self::assertFalse($result['test_dispatcher_invoked']);
        self::assertSame([], $calls);
        self::assertContains('test_push_not_approved', array_column($result['authorization_blockers'], 'code'));
    }

    public function testAuthorizedTestPushUsesOnlyIsolatedDispatcherAndKeepsFormalBlockVisible(): void
    {
        $calls = [];
        $service = new OperatingTargetReportGateService(
            null,
            static function (array $payload, array $context) use (&$calls): array {
                $calls[] = [$payload, $context];
                return [
                    'success' => true,
                    'receipt_id' => 'local-test-receipt-001',
                    'message' => 'fake transport accepted',
                ];
            }
        );
        $preview = $this->readyPreview();
        $preview['status'] = 'partial';
        $preview['facts']['actual_revenue'] = null;
        $preview['facts']['quality_status'] = 'unverified';
        $preview['gaps'] = [
            ['code' => 'actual_revenue_missing', 'message' => '未取得全酒店实际营收。'],
        ];
        $pagePreview = $service->pagePreview($preview, '宿析测试酒店');

        $result = $service->authorizedTestPush($preview, '宿析测试酒店', [
            'approved' => true,
            'purpose' => OperatingTargetReportGateService::TEST_PUSH_PURPOSE,
            'actor_id' => 7,
            'approval_reference' => 'approval-local-002',
            'test_destination' => '隔离测试接收器',
            'test_only' => true,
            'target_robot_id' => self::ROBOT_ID,
            'target_robot_name' => self::ROBOT_NAME,
            'first_confirmation' => true,
            'second_confirmation' => true,
            'preview_fingerprint' => $pagePreview['preview_fingerprint'],
        ]);

        self::assertSame('test_push_sent', $result['delivery_status']);
        self::assertTrue($result['test_dispatcher_invoked']);
        self::assertFalse($result['formal_send_gate']['allowed']);
        self::assertCount(1, $calls);
        self::assertStringContainsString('授权测试推送，禁止作为正式经营结论', $calls[0][0]['markdown']['content']);
        self::assertStringContainsString('正式发送门禁：阻断', $calls[0][0]['markdown']['content']);
        self::assertStringContainsString('数据缺口（不以 0 代替）', $calls[0][0]['markdown']['content']);
        self::assertArrayNotHasKey('webhook', $calls[0][1]);
        self::assertArrayNotHasKey('url', $calls[0][1]);
        self::assertSame('local-test-receipt-001', $result['test_receipt']['receipt_id']);
    }

    public function testChangedPreviewInvalidatesPriorAuthorizationFingerprint(): void
    {
        $calls = [];
        $service = new OperatingTargetReportGateService(
            null,
            static function (array $payload, array $context) use (&$calls): array {
                $calls[] = [$payload, $context];
                return ['success' => true];
            }
        );
        $preview = $this->readyPreview();
        $pagePreview = $service->pagePreview($preview, '宿析测试酒店');
        $preview['facts']['actual_revenue'] = 5000;

        $result = $service->authorizedTestPush($preview, '宿析测试酒店', [
            'approved' => true,
            'purpose' => OperatingTargetReportGateService::TEST_PUSH_PURPOSE,
            'actor_id' => 7,
            'approval_reference' => 'approval-local-003',
            'test_destination' => '隔离测试接收器',
            'test_only' => true,
            'target_robot_id' => self::ROBOT_ID,
            'target_robot_name' => self::ROBOT_NAME,
            'first_confirmation' => true,
            'second_confirmation' => true,
            'preview_fingerprint' => $pagePreview['preview_fingerprint'],
        ]);

        self::assertSame('test_push_blocked', $result['delivery_status']);
        self::assertSame([], $calls);
        self::assertContains(
            'preview_fingerprint_mismatch',
            array_column($result['authorization_blockers'], 'code')
        );
    }

    public function testAdapterHasNoNetworkDatabaseOrSessionAccess(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../app/service/OperatingTargetReportGateService.php'
        );

        foreach ([
            'curl_',
            'Db::',
            'qyapi.weixin.qq.com',
            'Cookie::',
            'cookie(',
            'localStorage',
            'sessionStorage',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
        self::assertStringNotContainsString('deliverToHotel(', $source);
        self::assertStringContainsString('buildDailyReportPayload(', $source);
    }

    /** @return array<string, mixed> */
    private function readyPreview(): array
    {
        return [
            'title' => '每日经营目标报告预览',
            'status' => 'ready',
            'hotel_id' => self::HOTEL_ID,
            'target_date' => '2026-07-26',
            'facts' => [
                'target_revenue' => 10000,
                'actual_revenue' => 4000,
                'sold_room_nights' => 20,
                'sellable_room_nights' => 40,
                'fact_scope' => 'whole_hotel',
                'source_type' => 'manual',
                'source_reference' => 'manual-entry:test-fixture',
                'quality_status' => 'manual_confirmed',
                'fact_captured_at' => '2026-07-26 12:00:00',
            ],
            'metrics' => [
                'completion_rate_percent' => 40.0,
                'remaining_revenue' => 6000.0,
                'selling_progress_percent' => 50.0,
                'remaining_sellable_room_nights' => 20,
                'required_average_rate' => 300.0,
            ],
            'gaps' => [],
            'reminders' => [
                [
                    'level' => 'warning',
                    'code' => 'target_remaining',
                    'message' => '当前仍有营收目标待完成。',
                ],
            ],
            'delivery_status' => 'preview_only',
        ];
    }
}
