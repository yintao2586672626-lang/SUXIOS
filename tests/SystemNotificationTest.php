<?php
declare(strict_types=1);

namespace Tests;

use app\model\SystemNotification;
use app\model\SystemNotificationUserState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;

final class SystemNotificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
    }

    protected function tearDown(): void
    {
        if (SystemNotification::tableReady()) {
            $ids = SystemNotification::whereLike('source_key', 'unit_test_notification:%')->column('id');
            if (!empty($ids) && SystemNotificationUserState::tableReady()) {
                SystemNotificationUserState::whereIn('notification_id', array_map('intval', $ids))->delete();
            }
            SystemNotification::whereLike('source_key', 'unit_test_notification:%')->delete();
        }
    }

    public function testNotificationTextRedactsPhonesLongIdsAndSecrets(): void
    {
        $text = $this->invokeStatic('safeText', [
            'Cookie=session-secret token:live-token 订单 5026028568383187252 手机 13812345678',
            500,
        ]);

        self::assertStringContainsString('Cookie=****', $text);
        self::assertStringContainsString('token=****', $text);
        self::assertStringContainsString('138****5678', $text);
        self::assertStringContainsString('[编号已隐藏]', $text);
        self::assertStringNotContainsString('session-secret', $text);
        self::assertStringNotContainsString('live-token', $text);
        self::assertStringNotContainsString('5026028568383187252', $text);
        self::assertStringNotContainsString('13812345678', $text);
    }

    public function testNotificationActionPayloadRedactsSensitiveNestedValues(): void
    {
        $encoded = $this->invokeStatic('encodeActionPayload', [[
            'target_page' => 'online-data',
            'cookie' => 'secret-cookie',
            'headers' => ['Authorization' => 'Bearer secret-token'],
            'details' => [
                'guest_phone' => '13987654321',
                'order_id' => '5026028568383187252',
                'safe_label' => '查看原因',
            ],
        ]]);

        self::assertIsString($encoded);
        $payload = json_decode($encoded, true);

        self::assertSame('online-data', $payload['target_page']);
        self::assertSame('***', $payload['cookie']);
        self::assertSame('***', $payload['headers']);
        self::assertSame('139****4321', $payload['details']['guest_phone']);
        self::assertSame('[编号已隐藏]', $payload['details']['order_id']);
        self::assertSame('查看原因', $payload['details']['safe_label']);

        self::assertStringNotContainsString('secret-cookie', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('13987654321', $encoded);
        self::assertStringNotContainsString('5026028568383187252', $encoded);
    }

    public function testNotificationSourceKeyIsBoundedAndStable(): void
    {
        $short = $this->invokeStatic('normalizeSourceKey', ['online_data:auto_fetch:7:2026-06-07:ok']);
        $longInput = str_repeat('notification-source-', 20);
        $long = $this->invokeStatic('normalizeSourceKey', [$longInput]);

        self::assertSame('online_data:auto_fetch:7:2026-06-07:ok', $short);
        self::assertLessThanOrEqual(160, strlen($long));
        self::assertSame($long, $this->invokeStatic('normalizeSourceKey', [$longInput]));
        self::assertStringContainsString(substr(sha1($longInput), 0, 32), $long);
    }

    public function testUserReadAndClearStateDoesNotMutateNotificationFact(): void
    {
        self::assertTrue(SystemNotification::tableReady(), 'system_notifications table must exist');
        self::assertTrue(SystemNotificationUserState::tableReady(), 'system_notification_user_states table must exist');

        $sourceKey = 'unit_test_notification:' . bin2hex(random_bytes(4));
        $notification = SystemNotification::recordEvent([
            'hotel_id' => 7,
            'user_id' => 1,
            'platform' => 'ota',
            'category' => 'capture_failed',
            'severity' => 'error',
            'title' => 'OTA 自动采集失败',
            'message' => '数据日期 2026-06-07，未配置平台授权',
            'action_type' => 'fetch',
            'source_module' => 'online_data',
            'source_key' => $sourceKey,
        ]);

        self::assertSame(0, (int)$notification->is_read);
        self::assertSame(0, (int)$notification->is_cleared);

        $readCount = SystemNotificationUserState::markReadForUser([(int)$notification->id], 1001);
        self::assertSame(1, $readCount);

        $storedNotification = SystemNotification::find((int)$notification->id);
        self::assertSame(0, (int)$storedNotification->is_read);
        self::assertSame(0, (int)$storedNotification->is_cleared);

        $userOneStates = SystemNotificationUserState::statesByNotificationId([(int)$notification->id], 1001);
        $userTwoStates = SystemNotificationUserState::statesByNotificationId([(int)$notification->id], 1002);
        self::assertSame(1, (int)$userOneStates[(int)$notification->id]['is_read']);
        self::assertSame([], $userTwoStates);

        $clearCount = SystemNotificationUserState::markClearedForUser([(int)$notification->id], 1001);
        self::assertSame(1, $clearCount);

        $storedNotification = SystemNotification::find((int)$notification->id);
        $userOneStates = SystemNotificationUserState::statesByNotificationId([(int)$notification->id], 1001);
        self::assertSame(0, (int)$storedNotification->is_cleared);
        self::assertSame(1, (int)$userOneStates[(int)$notification->id]['is_cleared']);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokeStatic(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionClass(SystemNotification::class);
        $methodReflection = $reflection->getMethod($method);
        $methodReflection->setAccessible(true);

        return $methodReflection->invokeArgs(null, $arguments);
    }
}
