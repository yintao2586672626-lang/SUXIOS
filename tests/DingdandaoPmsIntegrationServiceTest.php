<?php
declare(strict_types=1);

namespace tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoPmsIntegrationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DingdandaoPmsIntegrationServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/dingdandao_pms_integration_' . getmypid() . '.sqlite';
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
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('dingdandao_pms_push_dispatches')->delete(true);
        Db::name('dingdandao_pms_integrations')->delete(true);
        Db::name('dingdandao_room_fee_capture_details')->delete(true);
        Db::name('dingdandao_operating_target_captures')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('room_types')->delete(true);
        Db::name('ota_ctrip_entity_snapshots')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 80,
            'name' => '敦煌漠蓝新',
            'code' => 'HOTEL9421',
            'address' => '甘肃敦煌月牙泉镇合水村二组68号',
            'city' => '',
            'update_time' => '2026-07-27 00:08:03',
        ]);
        Db::name('ota_ctrip_entity_snapshots')->insert([
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'entity_type' => 'public_hotel_profile',
            'capture_status' => 'partial',
            'attributes_json' => json_encode([
                'role' => 'self',
                'fields' => [
                    'name' => '敦煌·漠蓝',
                    'address' => '甘肃敦煌月牙泉镇合水村二组68号',
                    'city_name' => '敦煌',
                    'room_count' => 25,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_seen_at' => '2026-07-27 00:08:03',
            'update_time' => '2026-07-27 00:08:03',
            'create_time' => '2026-07-27 00:08:03',
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'id' => 1,
            'tenant_id' => 80,
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => '漠蓝测试',
            'webhook' => 'encrypted-placeholder',
            'status' => 1,
            'last_tested_at' => '2026-07-27 00:10:00',
            'last_test_status' => 'success',
        ]);
    }

    public function testConfigPersistsProviderAliasAndSharedRobotPolicy(): void
    {
        $service = new DingdandaoPmsIntegrationService();
        $saved = $service->save(80, 80, 7, [
            'provider_hotel_id' => 'provider-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝',
            'robot_id' => 1,
            'status' => true,
            'auto_push_enabled' => true,
        ]);

        self::assertTrue($saved['config']['configured']);
        self::assertTrue($saved['config']['status']);
        self::assertTrue($saved['config']['auto_push_enabled']);
        self::assertSame('敦煌漠蓝', $saved['config']['provider_hotel_name']);
        self::assertSame(1, $saved['config']['robot_id']);
        self::assertSame(
            '敦煌漠蓝',
            $service->captureExpectation(80, 80, '敦煌漠蓝新')['expected_provider_hotel_name']
        );
    }

    public function testStatusAutofillsStableMasterDataWithTruthfulSources(): void
    {
        $service = new DingdandaoPmsIntegrationService();
        $status = $service->status(80, 80, 7);
        $items = [];
        foreach ($status['stable_master_data']['items'] as $item) {
            $items[$item['key']] = $item;
        }

        self::assertSame(80, $items['system_hotel_id']['value']);
        self::assertSame('fixed', $items['system_hotel_id']['status']);
        self::assertSame('HOTEL9421', $items['system_hotel_code']['value']);
        self::assertSame('suxios_hotel_master', $items['system_hotel_code']['source']);
        self::assertSame(
            '甘肃敦煌月牙泉镇合水村二组68号',
            $items['address']['value']
        );
        self::assertSame('suxios_hotel_master', $items['address']['source']);
        self::assertNull($items['city']['value']);
        self::assertSame('missing', $items['city']['status']);
        self::assertNull($items['physical_room_count']['value']);
        self::assertSame('missing', $items['physical_room_count']['source']);
        self::assertSame('missing', $items['physical_room_count']['status']);
        self::assertNull($items['provider_hotel_id']['value']);
        self::assertContains(
            'sellable_room_nights',
            $status['stable_master_data']['dynamic_fields_excluded']
        );
        self::assertSame('敦煌漠蓝新', $status['config']['provider_hotel_name']);

        Db::name('room_types')->insertAll([
            [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'room_count' => 10,
                'is_enabled' => 1,
            ],
            [
                'tenant_id' => 80,
                'hotel_id' => 80,
                'room_count' => 15,
                'is_enabled' => 1,
            ],
        ]);
        $refreshed = $service->status(80, 80, 7);
        $refreshedItems = [];
        foreach ($refreshed['stable_master_data']['items'] as $item) {
            $refreshedItems[$item['key']] = $item;
        }
        self::assertSame(25, $refreshedItems['physical_room_count']['value']);
        self::assertSame('suxios_room_types', $refreshedItems['physical_room_count']['source']);
        self::assertSame('master', $refreshedItems['physical_room_count']['status']);
    }

    public function testAutomaticPushRequiresCurrentRobotDeliveryTest(): void
    {
        Db::name('competitor_wechat_robot')->where('id', 1)->update([
            'last_tested_at' => null,
            'last_test_status' => 'pending',
        ]);
        $service = new DingdandaoPmsIntegrationService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dingdandao_pms_robot_test_required');
        $service->save(80, 80, 7, [
            'provider_hotel_id' => 'provider-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝',
            'robot_id' => 1,
            'status' => true,
            'auto_push_enabled' => true,
        ]);
    }

    public function testWebhookRetestRequirementBlocksPreviouslyEnabledAutomaticPush(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            }
        );
        $this->saveEnabledConfig($service);
        Db::name('competitor_wechat_robot')->where('id', 1)->update([
            'last_tested_at' => null,
            'last_test_status' => 'pending',
        ]);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertSame(0, $calls);
        self::assertContains(
            'pms_wecom_robot_test_required',
            array_column($result['blockers'], 'code')
        );
    }

    public function testGateInterleavingAutoPushDisableBlocksFinalSender(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            },
            static function (
                int $tenantId,
                int $hotelId,
                int $integrationId
            ): void {
                Db::name('dingdandao_pms_integrations')
                    ->where('id', $integrationId)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->update(['auto_push_enabled' => 0]);
            }
        );
        $this->saveEnabledConfig($service);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertSame(
            'failed',
            (string)Db::name('dingdandao_pms_push_dispatches')->value('delivery_status')
        );
        self::assertContains(
            'pms_auto_push_disabled',
            array_column($result['blockers'], 'code')
        );
    }

    public function testGateInterleavingIntegrationDisableBlocksFinalSender(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            },
            static function (
                int $tenantId,
                int $hotelId,
                int $integrationId
            ): void {
                Db::name('dingdandao_pms_integrations')
                    ->where('id', $integrationId)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->update(['status' => 0]);
            }
        );
        $this->saveEnabledConfig($service);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertSame(
            'failed',
            (string)Db::name('dingdandao_pms_push_dispatches')->value('delivery_status')
        );
        self::assertContains(
            'pms_integration_disabled',
            array_column($result['blockers'], 'code')
        );
    }

    public function testGateInterleavingRobotSwitchBlocksOldRobotSender(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 2,
            'tenant_id' => 80,
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => '新共享机器人',
            'webhook' => 'encrypted-placeholder-2',
            'status' => 1,
            'last_tested_at' => '2026-07-28 00:20:00',
            'last_test_status' => 'success',
        ]);
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            },
            static function (
                int $tenantId,
                int $hotelId,
                int $integrationId
            ): void {
                Db::name('dingdandao_pms_integrations')
                    ->where('id', $integrationId)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->update(['robot_id' => 2]);
            }
        );
        $this->saveEnabledConfig($service);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertSame(
            'failed',
            (string)Db::name('dingdandao_pms_push_dispatches')->value('delivery_status')
        );
        self::assertContains(
            'pms_wecom_robot_changed',
            array_column($result['blockers'], 'code')
        );
    }

    public function testGateInterleavingRobotPolicyChangeBlocksFinalSender(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            },
            static function (
                int $tenantId,
                int $hotelId,
                int $integrationId,
                int $robotId
            ): void {
                Db::name('competitor_wechat_robot')
                    ->where('id', $robotId)
                    ->where('store_id', $hotelId)
                    ->update([
                        'tenant_id' => 81,
                        'name' => '已变更机器人',
                        'notification_scope' => 'account_owned',
                        'last_tested_at' => null,
                        'last_test_status' => 'pending',
                    ]);
            }
        );
        $this->saveEnabledConfig($service);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertSame(
            'failed',
            (string)Db::name('dingdandao_pms_push_dispatches')->value('delivery_status')
        );
        self::assertContains(
            'pms_wecom_robot_changed',
            array_column($result['blockers'], 'code')
        );
        self::assertContains(
            'pms_wecom_robot_missing',
            array_column($result['blockers'], 'code')
        );
    }

    public function testUnverifiedCaptureIsBlockedWithoutCallingDelivery(): void
    {
        $calls = [];
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();
        Db::name('dingdandao_operating_target_captures')
            ->where('id', 501)
            ->update(['readback_status' => 'readback_failed']);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame([], $calls);
        self::assertSame(0, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertContains(
            'pms_capture_authoritative_readback_missing',
            array_column($result['blockers'], 'code')
        );
    }

    public function testDispatchUsesDatabaseFactsInsteadOfCallerMutation(): void
    {
        $payloads = [];
        $service = new DingdandaoPmsIntegrationService(
            static function (
                int $_hotelId,
                int $_robotId,
                array $payload
            ) use (&$payloads): array {
                $payloads[] = $payload;
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();
        $capture['summary']['total_room_fee'] = 999999.99;
        $capture['county_context']['summary']['total_room_fee'] = 1;

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertCount(1, $payloads);
        $content = (string)$payloads[0]['markdown']['content'];
        self::assertStringContainsString('客房收入：¥8,275.67', $content);
        self::assertStringContainsString(
            '门店平均总房费：¥6,053.86（本店较区域 ↑36.70%）',
            $content
        );
        self::assertStringNotContainsString('999,999.99', $content);
    }

    public function testMissingAuthoritativeCaptureBlocksBeforeDispatchCreation(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();
        $capture['id'] = 999;

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(0, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertContains(
            'pms_capture_authoritative_readback_missing',
            array_column($result['blockers'], 'code')
        );
    }

    public function testCaptureFingerprintMismatchBlocksBeforeDispatchCreation(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();
        $capture['source_fingerprint'] = str_repeat('b', 64);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(0, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertContains(
            'pms_capture_authoritative_readback_missing',
            array_column($result['blockers'], 'code')
        );
    }

    public function testVerifiedCaptureUsesExistingSenderOnceAndKeepsReceipt(): void
    {
        $calls = [];
        $service = new DingdandaoPmsIntegrationService(
            static function (
                int $hotelId,
                int $robotId,
                array $payload,
                array $context
            ) use (&$calls): array {
                $calls[] = compact('hotelId', 'robotId', 'payload', 'context');
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();

        $sent = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );
        $duplicate = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );

        self::assertSame('sent', $sent['delivery_status']);
        self::assertTrue($sent['delivery_attempted']);
        self::assertSame('already_sent', $duplicate['delivery_status']);
        self::assertFalse($duplicate['delivery_attempted']);
        self::assertCount(1, $calls);
        self::assertSame(80, $calls[0]['hotelId']);
        self::assertSame(1, $calls[0]['robotId']);
        self::assertStringContainsString(
            '订单来了 PMS 经营事实',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '区域参考（甘肃省/酒泉市/敦煌市）',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '门店平均总房费：¥6,053.86（本店较区域 ↑36.70%）',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '经营指标趋势（07-27 至 07-28）',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '平均房价 ADR：07-27 ¥636.59 → 07-28 ¥583.04',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '远期房态（累计窗口）',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringContainsString(
            '3 天（07-29 至 07-31）：已订12 间夜｜剩余可售33 间夜｜OCC 26.67%｜ADR ¥320.00｜RevPAR ¥85.33',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertStringNotContainsString(
            '22 天',
            $calls[0]['payload']['markdown']['content']
        );
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        self::assertSame(
            'sent',
            (string)Db::name('dingdandao_pms_push_dispatches')->value('delivery_status')
        );
    }

    public function testFailedRetryClaimsOnceBeforeReentrantConcurrentAttempt(): void
    {
        $capture = $this->verifiedCapture();
        $calls = 0;
        $nested = null;
        $service = null;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls, &$nested, &$service, $capture): array {
                $calls++;
                $nested = $service->dispatchVerifiedCapture(
                    80,
                    80,
                    7,
                    '敦煌漠蓝新',
                    $capture,
                    'manual',
                    true
                );
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            }
        );
        $this->saveEnabledConfig($service);
        $this->insertDispatch('failed', '2026-07-28 00:31:00', 1);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'manual',
            true
        );

        self::assertSame(1, $calls);
        self::assertSame('sent', $result['delivery_status']);
        self::assertTrue($result['delivery_attempted']);
        self::assertIsArray($nested);
        self::assertSame('sending', $nested['delivery_status']);
        self::assertFalse($nested['delivery_attempted']);
        $stored = Db::name('dingdandao_pms_push_dispatches')->where('capture_id', 501)->find();
        self::assertSame(2, (int)$stored['attempt_count']);
        self::assertSame('sent', $stored['delivery_status']);
        self::assertSame(
            [
                'delivery_status' => 'sent',
                'robot_count' => 1,
                'sent_count' => 1,
                'failed_count' => 0,
                'failures' => [],
            ],
            json_decode((string)$stored['delivery_receipt_json'], true)
        );
    }

    public function testFreshPendingIsNotRetriedEvenWhenExplicitlyRequested(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            }
        );
        $this->saveEnabledConfig($service);
        $this->insertDispatch('pending', date('Y-m-d H:i:s'), 1);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'manual',
            true
        );

        self::assertSame(0, $calls);
        self::assertSame('pending', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(
            1,
            (int)Db::name('dingdandao_pms_push_dispatches')->value('attempt_count')
        );
    }

    public function testStalePendingRemainsFailClosedEvenWithExplicitRetryFlag(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            }
        );
        $this->saveEnabledConfig($service);
        $dispatchId = $this->insertDispatch(
            'pending',
            date('Y-m-d H:i:s', time() - 601),
            1
        );

        $withoutExplicitRetry = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'manual',
            false
        );
        $retried = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'manual',
            true
        );

        self::assertSame('outcome_unknown', $withoutExplicitRetry['delivery_status']);
        self::assertFalse($withoutExplicitRetry['delivery_attempted']);
        self::assertSame('outcome_unknown', $retried['delivery_status']);
        self::assertFalse($retried['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(1, (int)Db::name('dingdandao_pms_push_dispatches')->count());
        $stored = Db::name('dingdandao_pms_push_dispatches')->where('id', $dispatchId)->find();
        self::assertSame($dispatchId, (int)$stored['id']);
        self::assertSame(1, (int)$stored['attempt_count']);
        self::assertSame('outcome_unknown', $stored['delivery_status']);
    }

    public function testFirstVerifiedCaptureLearnsProviderIdBeforeAutomaticPush(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            }
        );
        $service->save(80, 80, 7, [
            'provider_hotel_id' => '',
            'provider_hotel_name' => '敦煌漠蓝',
            'robot_id' => 1,
            'status' => true,
            'auto_push_enabled' => true,
        ]);

        self::assertNull(
            $service->captureExpectation(80, 80, '敦煌漠蓝新')['expected_provider_hotel_id']
        );
        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertSame(1, $calls);
        self::assertSame(
            'provider-hotel-80',
            (string)Db::name('dingdandao_pms_integrations')->value('provider_hotel_id')
        );
    }

    public function testDeliverySuccessThenTransactionFailureIsNeverRetried(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return [
                    'delivery_status' => 'sent',
                    'robot_count' => 1,
                    'sent_count' => 1,
                    'failed_count' => 0,
                    'failures' => [],
                ];
            },
            null,
            null,
            static function (): void {
                throw new \RuntimeException('simulated_post_delivery_crash');
            }
        );
        $this->saveEnabledConfig($service);
        $capture = $this->verifiedCapture();

        $first = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture'
        );
        $automaticRetry = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'capture',
            false
        );
        $explicitRetry = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $capture,
            'manual',
            true
        );

        self::assertSame(1, $calls);
        self::assertSame('outcome_unknown', $first['delivery_status']);
        self::assertTrue($first['delivery_attempted']);
        self::assertSame('outcome_unknown', $automaticRetry['delivery_status']);
        self::assertFalse($automaticRetry['delivery_attempted']);
        self::assertSame('outcome_unknown', $explicitRetry['delivery_status']);
        self::assertFalse($explicitRetry['delivery_attempted']);
        $stored = Db::name('dingdandao_pms_push_dispatches')->where('capture_id', 501)->find();
        self::assertSame('outcome_unknown', $stored['delivery_status']);
        self::assertSame(1, (int)$stored['attempt_count']);
    }

    public function testConcurrentManualProviderBindingIsNotOverwrittenByCaptureLearning(): void
    {
        $calls = 0;
        $service = new DingdandaoPmsIntegrationService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            },
            null,
            static function (array $config): void {
                Db::name('dingdandao_pms_integrations')
                    ->where('id', (int)$config['id'])
                    ->update([
                        'provider_hotel_id' => 'manually-bound-hotel-b',
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
            }
        );
        $service->save(80, 80, 7, [
            'provider_hotel_id' => '',
            'provider_hotel_name' => '敦煌漠蓝',
            'robot_id' => 1,
            'status' => true,
            'auto_push_enabled' => true,
        ]);

        $result = $service->dispatchVerifiedCapture(
            80,
            80,
            7,
            '敦煌漠蓝新',
            $this->verifiedCapture(),
            'capture'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertFalse($result['delivery_attempted']);
        self::assertSame(0, $calls);
        self::assertSame(
            'manually-bound-hotel-b',
            (string)Db::name('dingdandao_pms_integrations')->value('provider_hotel_id')
        );
        self::assertContains(
            'pms_provider_hotel_id_mismatch',
            array_column($result['blockers'], 'code')
        );
        self::assertSame(0, (int)Db::name('dingdandao_pms_push_dispatches')->count());
    }

    public function testPrefillRechecksCurrentBindingAndRejectsStaleHotelCapture(): void
    {
        $service = new DingdandaoPmsIntegrationService();
        $this->saveEnabledConfig($service);
        $this->insertVerifiedCaptureRow();
        Db::name('dingdandao_pms_integrations')
            ->where('tenant_id', 80)
            ->where('hotel_id', 80)
            ->update([
                'provider_hotel_id' => 'manually-bound-hotel-b',
                'update_time' => '2026-07-28 12:01:00',
            ]);

        $prefill = $service->prefill(80, 80, 7, '2026-07-28');

        self::assertSame('blocked', $prefill['status']);
        self::assertNull($prefill['prefill']);
        self::assertContains(
            'pms_provider_hotel_id_mismatch',
            array_column($prefill['gaps'], 'code')
        );
        self::assertSame(
            'manually-bound-hotel-b',
            (string)Db::name('dingdandao_pms_integrations')->value('provider_hotel_id')
        );
    }

    private function saveEnabledConfig(DingdandaoPmsIntegrationService $service): void
    {
        $service->save(80, 80, 7, [
            'provider_hotel_id' => 'provider-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝',
            'robot_id' => 1,
            'status' => true,
            'auto_push_enabled' => true,
        ]);
    }

    private function insertDispatch(
        string $status,
        string $claimedAt,
        int $attemptCount
    ): int {
        $integrationId = (int)Db::name('dingdandao_pms_integrations')->value('id');
        return (int)Db::name('dingdandao_pms_push_dispatches')->insertGetId([
            'integration_id' => $integrationId,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'capture_id' => 501,
            'business_date' => '2026-07-28',
            'source_fingerprint' => str_repeat('a', 64),
            'robot_id' => 1,
            'trigger_type' => 'manual',
            'delivery_status' => $status,
            'attempt_count' => $attemptCount,
            'delivery_receipt_json' => $status === 'failed'
                ? json_encode(['delivery_status' => 'failed'])
                : null,
            'error_summary' => $status === 'failed' ? 'temporary_failure' : null,
            'claimed_at' => $claimedAt,
            'delivered_at' => null,
            'created_by' => 7,
            'create_time' => $claimedAt,
            'update_time' => $claimedAt,
        ]);
    }

    /** @return array<string,mixed> */
    private function verifiedCapture(bool $persist = true): array
    {
        $countyContext = $this->verifiedCountyContext();
        $forwardRoomStatus = $this->verifiedForwardRoomStatus();
        $capture = [
            'id' => 501,
            'hotel_id' => 80,
            'business_date' => '2026-07-28',
            'provider_hotel_id' => 'provider-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝',
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'source_fingerprint' => str_repeat('a', 64),
            'summary' => [
                'total_room_fee' => 8275.67,
                'adr' => 636.59,
                'occupancy_rate_percent' => 86.67,
                'revpar' => 551.71,
                'sold_room_nights' => 13,
                'average_daily_room_nights' => 13.0,
                'derived_sellable_room_nights' => 15,
            ],
            'detail_row_count' => 25,
            'detail_room_fee_total' => 8275.67,
            'trend' => [
                'total_room_fee' => [
                    ['date' => '2026-07-27', 'value' => 8275.67],
                    ['date' => '2026-07-28', 'value' => 8745.66],
                ],
                'adr' => [
                    ['date' => '2026-07-27', 'value' => 636.59],
                    ['date' => '2026-07-28', 'value' => 583.04],
                ],
                'occupancy_rate_percent' => [
                    ['date' => '2026-07-27', 'value' => 86.67],
                    ['date' => '2026-07-28', 'value' => 100],
                ],
                'revpar' => [
                    ['date' => '2026-07-27', 'value' => 551.71],
                    ['date' => '2026-07-28', 'value' => 583.04],
                ],
                'sold_room_nights' => [
                    ['date' => '2026-07-27', 'value' => 13],
                    ['date' => '2026-07-28', 'value' => 15],
                ],
            ],
            'county_context' => $countyContext,
            'forward_room_status' => $forwardRoomStatus,
            'captured_at' => '2026-07-28 00:30:00',
        ];
        if ($persist) {
            $this->insertVerifiedCaptureRow($capture);
        }
        return $capture;
    }

    /** @return array<string,mixed> */
    private function verifiedCountyContext(): array
    {
        $trend = [
            'total_room_fee' => [
                ['date' => '2026-07-27', 'value' => 5887.06],
                ['date' => '2026-07-28', 'value' => 6053.86],
            ],
            'adr' => [
                ['date' => '2026-07-27', 'value' => 366.18],
                ['date' => '2026-07-28', 'value' => 396.87],
            ],
            'occupancy_rate_percent' => [
                ['date' => '2026-07-27', 'value' => 66.5],
                ['date' => '2026-07-28', 'value' => 60.96],
            ],
            'revpar' => [
                ['date' => '2026-07-27', 'value' => 243.5],
                ['date' => '2026-07-28', 'value' => 241.93],
            ],
            'sold_room_nights' => [
                ['date' => '2026-07-27', 'value' => 16.08],
                ['date' => '2026-07-28', 'value' => 15.25],
            ],
        ];
        return [
            'fact_scope' => 'county_diagnostic_only',
            'data_status' => 'readable_separate',
            'region_name' => '甘肃省/酒泉市/敦煌市',
            'bool_city' => false,
            'summary' => [
                'total_room_fee' => 6053.86,
                'adr' => 396.87,
                'occupancy_rate_percent' => 60.96,
                'revpar' => 241.93,
                'sold_room_nights' => 15.25,
                'average_daily_room_nights' => 15.25,
            ],
            'trend' => $trend,
            'field_trace' => [
                'summary' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTotal/county#data',
                'region_name' => 'DOM:当前区域指标',
                'total_room_fee' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=5#data.list[]',
                'adr' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=0#data.list[]',
                'occupancy_rate_percent' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=1#data.list[]',
                'revpar' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=2#data.list[]',
                'sold_room_nights' =>
                    'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=3#data.list[]',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function verifiedForwardRoomStatus(): array
    {
        $businessDate = new \DateTimeImmutable('2026-07-28');
        $dailyRows = [];
        for ($offset = 0; $offset < 31; $offset++) {
            $dailyRows[] = [
                'stay_date' => $businessDate->modify('+' . $offset . ' days')
                    ->format('Y-m-d'),
                'remaining_sellable_rooms' => 11,
                'booked_rooms' => 4,
                'unavailable_rooms' => 0,
                'oversold_rooms' => 0,
                'room_fee' => 1280,
                'sold_room_nights' => 4,
                'sellable_room_nights' => 15,
                'occupancy_rate_percent' => 26.67,
                'adr' => 320,
                'revpar' => 85.33,
            ];
        }
        $horizons = [];
        foreach ([3, 7, 14, 21] as $days) {
            $horizons[] = [
                'horizon_days' => $days,
                'date_from' => '2026-07-29',
                'date_to' => $businessDate->modify('+' . $days . ' days')
                    ->format('Y-m-d'),
                'expected_days' => $days,
                'covered_days' => $days,
                'sellable_room_nights' => 15 * $days,
                'booked_room_nights' => 4 * $days,
                'remaining_sellable_room_nights' => 11 * $days,
                'unavailable_room_nights' => 0,
                'room_fee' => 1280 * $days,
                'occupancy_rate_percent' => 26.67,
                'adr' => 320,
                'revpar' => 85.33,
                'quality_status' => 'verified',
                'gap_codes' => [],
            ];
        }
        return [
            'contract_version' => 'dingdandao_forward_room_status.v1',
            'fact_scope' => 'whole_hotel_forward_room_status',
            'source_api_path' => '/v2/hm-b/pro/web/accom/roomStat/forward/v2',
            'data_status' => 'verified',
            'readback_status' => 'readback_verified',
            'as_of_date' => '2026-07-28',
            'range_start_date' => '2026-07-28',
            'range_end_date' => '2026-08-27',
            'requested_range_start_date' => '2026-07-28',
            'requested_range_end_date' => '2026-08-27',
            'source_day_count' => 31,
            'display_day_count' => 21,
            'source_room_type_count' => 1,
            'total_room_count' => 15,
            'display_horizons' => [3, 7, 14, 21],
            'display_semantics' => 'future_days_after_as_of_date',
            'source_coverage_status' => 'complete',
            'source_gap_codes' => [],
            'daily_rows' => $dailyRows,
            'room_types' => [[
                'provider_room_type_id' => 'room-type-1',
                'room_type_name' => '测试房型',
                'room_count' => 15,
                'daily_rows' => $dailyRows,
            ]],
            'horizons' => $horizons,
            'reconciliation_status' => 'matched',
            'gap_codes' => [],
            'field_trace' => [],
        ];
    }

    /** @param array<string,mixed>|null $capture */
    private function insertVerifiedCaptureRow(?array $capture = null): void
    {
        if ((int)Db::name('dingdandao_operating_target_captures')
            ->where('id', 501)
            ->count() > 0
        ) {
            return;
        }
        $capture ??= $this->verifiedCapture(false);
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 501,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'provider_hotel_id' => 'provider-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝',
            'expected_hotel_name' => '敦煌漠蓝',
            'identity_evidence_type' => 'provider_hotel_id',
            'identity_status' => 'matched',
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'source_api_path' => '/api/verified',
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'capture_method' => 'network_response',
            'business_date' => '2026-07-28',
            'total_room_fee' => $capture['summary']['total_room_fee'],
            'adr' => $capture['summary']['adr'],
            'occupancy_rate_percent' => $capture['summary']['occupancy_rate_percent'],
            'revpar' => $capture['summary']['revpar'],
            'sold_room_nights' => $capture['summary']['sold_room_nights'],
            'average_daily_room_nights' => $capture['summary']['average_daily_room_nights'],
            'derived_sellable_room_nights' => $capture['summary']['derived_sellable_room_nights'],
            'detail_room_fee_total' => $capture['detail_room_fee_total'],
            'detail_row_count' => $capture['detail_row_count'],
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'quality_reason' => 'verified test capture',
            'gap_codes_json' => '[]',
            'trend_json' => json_encode(
                $capture['trend'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'field_trace_json' => '{}',
            'snapshot_json' => json_encode([
                'collection_mode' => 'full_diagnostic',
                'county_context' => $capture['county_context'],
                'forward_room_status' => $capture['forward_room_status'],
                'capture_evidence' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_fingerprint' => $capture['source_fingerprint'],
            'captured_at' => $capture['captured_at'],
            'captured_by' => 7,
            'readback_status' => 'readback_verified',
            'readback_verified_at' => '2026-07-28 00:30:01',
            'create_time' => '2026-07-28 00:30:00',
            'update_time' => '2026-07-28 00:30:01',
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE hotels ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, code TEXT NULL, address TEXT NULL, '
            . 'city TEXT NULL, update_time TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE room_types ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, '
            . 'room_count INTEGER, is_enabled INTEGER)'
        );
        Db::execute(
            'CREATE TABLE ota_ctrip_entity_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, '
            . 'source TEXT, entity_type TEXT, capture_status TEXT, attributes_json TEXT, '
            . 'last_seen_at TEXT NULL, update_time TEXT NULL, create_time TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_pms_integrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, source_url TEXT, robot_id INTEGER NULL, '
            . 'status INTEGER, auto_push_enabled INTEGER, last_capture_id INTEGER NULL, '
            . 'last_capture_business_date TEXT NULL, last_capture_status TEXT NULL, last_readback_status TEXT NULL, '
            . 'last_push_business_date TEXT NULL, last_push_status TEXT NULL, last_push_at TEXT NULL, '
            . 'last_push_error TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL, '
            . 'create_time TEXT, update_time TEXT, UNIQUE(tenant_id, hotel_id, provider))'
        );
        Db::execute(
            'CREATE TABLE dingdandao_pms_push_dispatches ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, integration_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'capture_id INTEGER, business_date TEXT, source_fingerprint TEXT, robot_id INTEGER, trigger_type TEXT, '
            . 'delivery_status TEXT, attempt_count INTEGER, delivery_receipt_json TEXT NULL, '
            . 'error_summary TEXT NULL, claimed_at TEXT, delivered_at TEXT NULL, created_by INTEGER NULL, '
            . 'create_time TEXT, update_time TEXT, UNIQUE(integration_id, capture_id, robot_id))'
        );
        Db::execute(
            'CREATE TABLE dingdandao_operating_target_captures ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, expected_hotel_name TEXT, '
            . 'identity_evidence_type TEXT, identity_status TEXT, source_url TEXT, source_api_path TEXT NULL, '
            . 'source_scope TEXT, capture_method TEXT, business_date TEXT, total_room_fee REAL NULL, adr REAL NULL, '
            . 'occupancy_rate_percent REAL NULL, revpar REAL NULL, sold_room_nights INTEGER NULL, '
            . 'average_daily_room_nights REAL NULL, derived_sellable_room_nights INTEGER NULL, '
            . 'detail_room_fee_total REAL NULL, detail_row_count INTEGER, reconciliation_status TEXT, '
            . 'capture_status TEXT, quality_status TEXT, quality_reason TEXT NULL, gap_codes_json TEXT NULL, '
            . 'trend_json TEXT NULL, field_trace_json TEXT NULL, snapshot_json TEXT, source_fingerprint TEXT, '
            . 'captured_at TEXT, captured_by INTEGER NULL, readback_status TEXT, readback_verified_at TEXT NULL, '
            . 'create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_room_fee_capture_details ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, capture_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'business_date TEXT, row_kind TEXT, room_type TEXT NULL, room_number TEXT NULL, room_fee REAL, '
            . 'source_row_index INTEGER, create_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE competitor_wechat_robot ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, store_id INTEGER, '
            . 'owner_user_id INTEGER NULL, '
            . 'notification_scope TEXT NULL, name TEXT, webhook TEXT, status INTEGER, '
            . 'last_tested_at TEXT NULL, last_test_status TEXT NULL)'
        );
    }
}
