<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudAutomationStateStore;
use app\service\CloudWechatPushOrchestratorService;
use app\service\WechatRobotDeliveryService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudWechatPushOrchestratorServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;
    private string $stateDirectory;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/cloud_wechat_push_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => false];
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
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, owner_user_id INTEGER NULL, notification_scope VARCHAR(40) NULL, name VARCHAR(120) NOT NULL, webhook TEXT NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS account_wechat_push_policies (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER NOT NULL, owner_user_id INTEGER NOT NULL, robot_id INTEGER NOT NULL, failure_robot_id INTEGER NULL, frequency VARCHAR(16) NOT NULL, template_key VARCHAR(40) NOT NULL, visual_card_enabled INTEGER NOT NULL, failure_alert_enabled INTEGER NOT NULL, status INTEGER NOT NULL, timezone VARCHAR(40) NOT NULL, last_dispatch_window VARCHAR(24) NULL, last_delivery_status VARCHAR(32) NULL, last_failure_alert_status VARCHAR(32) NULL, create_time DATETIME NULL, update_time DATETIME NULL)');
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('account_wechat_push_policies')->delete(true);
        $this->stateDirectory = sys_get_temp_dir() . '/cloud_wechat_push_state_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->stateDirectory, 0750, true);
        $this->seedRobots();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->stateDirectory);
    }

    public function testAccountPolicyIsScopedToOwnedHotelRobot(): void
    {
        $service = $this->service();
        $saved = $service->savePolicy(80, 7, [
            'robot_id' => 1,
            'failure_robot_id' => 2,
            'frequency' => 'hourly',
            'template_key' => 'hourly_monitor',
            'visual_card_enabled' => true,
            'failure_alert_enabled' => true,
            'enabled' => true,
        ]);

        self::assertSame(80, $saved['hotel_id']);
        self::assertSame(7, $saved['owner_user_id']);
        self::assertTrue($saved['visual_card_enabled']);
        self::assertCount(1, $service->policiesForAccount(80, 7));
        self::assertSame([], $service->policiesForAccount(80, 8));

        $this->expectException(\InvalidArgumentException::class);
        $service->savePolicy(80, 8, ['robot_id' => 1]);
    }

    public function testHourlyPolicyPreviewsThenDispatchesOneMessageAndOneVisualCardPerWindow(): void
    {
        $hourlyCalls = 0;
        $visualCalls = 0;
        $service = $this->service(
            function () use (&$hourlyCalls): array {
                $hourlyCalls++;
                return ['status' => 'sent', 'delivery_status' => 'sent'];
            },
            function () use (&$visualCalls): array {
                $visualCalls++;
                return ['status' => 'sent', 'delivery_status' => 'sent'];
            }
        );
        $service->savePolicy(80, 7, [
            'robot_id' => 1,
            'frequency' => 'hourly',
            'visual_card_enabled' => true,
            'enabled' => true,
        ]);
        $time = new DateTimeImmutable('2026-07-26 10:05:00', new DateTimeZone('Asia/Shanghai'));

        $preview = $service->runDue($time, false);
        self::assertSame('preview_ready', $preview['policies'][0]['status']);
        self::assertSame(0, $hourlyCalls);
        self::assertSame(0, $visualCalls);

        $first = $service->runDue($time, true);
        self::assertSame('dispatched', $first['policies'][0]['status']);
        self::assertSame(1, $hourlyCalls);
        self::assertSame(1, $visualCalls);

        $second = $service->runDue($time, true);
        self::assertSame('window_already_dispatched', $second['policies'][0]['status']);
        self::assertSame(1, $hourlyCalls);
        self::assertSame(1, $visualCalls);
    }

    public function testDailyPolicyRunsDuringTheNineOClockWindowOnly(): void
    {
        $calls = 0;
        $service = $this->service(function () use (&$calls): array {
            $calls++;
            return ['status' => 'sent', 'delivery_status' => 'sent'];
        });
        $service->savePolicy(80, 7, [
            'robot_id' => 1,
            'frequency' => 'daily',
            'enabled' => true,
        ]);

        $atNine = new DateTimeImmutable('2026-07-26 09:07:00', new DateTimeZone('Asia/Shanghai'));
        self::assertSame('dispatched', $service->runDue($atNine, true)['policies'][0]['status']);
        self::assertSame(1, $calls);
        $atTen = new DateTimeImmutable('2026-07-27 10:00:00', new DateTimeZone('Asia/Shanghai'));
        self::assertSame('not_due', $service->runDue($atTen, true)['policies'][0]['status']);
        self::assertSame(1, $calls);
    }

    public function testFailureAlertUsesConfiguredFallbackRobotAndIsWindowIdempotent(): void
    {
        $alertCalls = 0;
        $service = $this->service(
            static fn(): array => ['status' => 'failed', 'delivery_status' => 'failed', 'error_summary' => 'network timeout'],
            null,
            null,
            function () use (&$alertCalls): array {
                $alertCalls++;
                return ['delivery_status' => 'sent', 'robot_count' => 1, 'sent_count' => 1, 'failed_count' => 0, 'failures' => []];
            }
        );
        $service->savePolicy(80, 7, [
            'robot_id' => 1,
            'failure_robot_id' => 2,
            'frequency' => 'hourly',
            'failure_alert_enabled' => true,
            'enabled' => true,
        ]);
        $time = new DateTimeImmutable('2026-07-26 11:00:00', new DateTimeZone('Asia/Shanghai'));

        $first = $service->runDue($time, true);
        self::assertSame('delivery_failed', $first['policies'][0]['status']);
        self::assertSame('sent', $first['policies'][0]['failure_alert']['status']);
        self::assertSame(1, $alertCalls);

        $second = $service->runDue($time, true);
        self::assertSame('window_already_dispatched', $second['policies'][0]['status']);
        self::assertSame(1, $alertCalls);
    }

    public function testCloudTimerCallsOnlyTheExplicitPolicyDispatchCommand(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-account-wechat-push.service');
        $timer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-account-wechat-push.timer');
        self::assertStringContainsString('cloud-wechat-push:run --dispatch --limit=50', $service);
        self::assertStringNotContainsString('online-data:auto-fetch', $service);
        self::assertStringContainsString('OnUnitActiveSec=5min', $timer);
        self::assertStringContainsString('Persistent=true', $timer);
    }

    private function service(
        ?callable $hourly = null,
        ?callable $visual = null,
        ?WechatRobotDeliveryService $delivery = null,
        ?callable $failureAlert = null
    ): CloudWechatPushOrchestratorService
    {
        return new CloudWechatPushOrchestratorService(
            new CloudAutomationStateStore($this->stateDirectory),
            $delivery,
            $hourly,
            $visual,
            $failureAlert
        );
    }

    private function seedRobots(): void
    {
        foreach ([1 => '经营群', 2 => '异常提醒群'] as $id => $name) {
            Db::name('competitor_wechat_robot')->insert([
                'id' => $id,
                'store_id' => 80,
                'owner_user_id' => 7,
                'notification_scope' => 'account_onboarding',
                'name' => $name,
                'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=abcdefghijklmnopqrstuvwxyz',
                'status' => 1,
            ]);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
