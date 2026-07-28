<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudMessageTaskOverviewService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudMessageTaskOverviewServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/cloud_message_tasks_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY, store_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, module VARCHAR(50) NOT NULL, action VARCHAR(50) NOT NULL, hotel_id INTEGER NOT NULL, error_info TEXT NULL, extra_data TEXT NULL, create_time DATETIME NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, title VARCHAR(120) NOT NULL, trigger_type VARCHAR(32) NOT NULL, planned_send_at DATETIME NULL, enabled INTEGER NOT NULL, schedule_status VARCHAR(32) NOT NULL, test_robot_id INTEGER NULL, test_robot_name VARCHAR(120) NULL, update_time DATETIME NOT NULL)');

        Db::name('manual_notifications')->delete(true);
        Db::name('operation_logs')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);

        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 80,
            'name' => '敦煌漠蓝新',
            'status' => 1,
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'id' => 1,
            'store_id' => 80,
            'name' => '漠蓝测试',
            'status' => 1,
        ]);
        Db::name('operation_logs')->insert([
            'module' => 'wechat_monitor',
            'action' => 'hourly_formal_broadcast',
            'hotel_id' => 80,
            'error_info' => null,
            'extra_data' => json_encode([
                'robot_id' => 1,
                'delivery_status' => 'sent',
                'sent_count' => 1,
                'failed_count' => 0,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => '2026-07-27 22:00:07',
        ]);
        Db::name('operation_logs')->insert([
            'module' => 'cloud_automation',
            'action' => 'deliver_message',
            'hotel_id' => 80,
            'error_info' => null,
            'extra_data' => json_encode([
                'kind' => 'data_health_alert',
                'delivery_status' => 'sent',
                'sent_count' => 1,
                'failed_count' => 0,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => '2026-07-27 09:01:39',
        ]);
    }

    public function testLiveOverviewSeparatesScheduledAndConditionalTasks(): void
    {
        $units = [
            'suxios-cloud-hotel-monitor-formal.timer' => $this->timer('2026-07-27 22:00:06', '2026-07-27 23:00:00'),
            'suxios-cloud-hotel-monitor-formal.service' => $this->service(),
            'suxios-cloud-hotel-daily@80.timer' => $this->timer('2026-07-27 09:01:38', '2026-07-28 09:02:12'),
            'suxios-cloud-hotel-daily@80.service' => $this->service(),
            'suxios-cloud-hotel-health@80.timer' => $this->timer('2026-07-27 20:13:06', '2026-07-28 09:12:26'),
            'suxios-cloud-hotel-health@80.service' => $this->service(),
            'suxios-cloud-retry.timer' => $this->timer('2026-07-27 22:02:56', ''),
            'suxios-cloud-automation@retry.service' => $this->service(),
        ];
        $service = new CloudMessageTaskOverviewService(
            static fn(string $unit): array => $units[$unit] ?? [],
            static fn(): array => ['delivery_counts' => ['sent' => 63]],
            new DateTimeImmutable('2026-07-27 22:15:00', new DateTimeZone('Asia/Shanghai'))
        );

        $overview = $service->overview(80, 80);
        $tasks = array_column($overview['tasks'], null, 'key');

        self::assertSame('live', $overview['source_status']);
        self::assertSame(4, $overview['task_count']);
        self::assertSame(4, $overview['active_count']);
        self::assertSame('已发送', $tasks['hourly_operating_monitor']['last_result']);
        self::assertSame(
            '日报门禁阻断，健康提醒已发送',
            $tasks['daily_operating_report']['last_result']
        );
        self::assertSame('conditional_alert', $tasks['data_health_alert']['delivery_mode']);
        self::assertSame('当前没有待重试失败消息', $tasks['failed_delivery_retry']['last_result']);
        self::assertSame('漠蓝测试', $tasks['hourly_operating_monitor']['target_robot_name']);
        self::assertArrayNotHasKey('webhook', $tasks['hourly_operating_monitor']);
    }

    public function testTimestampedSnapshotRequiresExactHotelNameAndBecomesStale(): void
    {
        $snapshotPath = sys_get_temp_dir() . '/cloud_message_tasks_snapshot_' . getmypid() . '.json';
        file_put_contents($snapshotPath, json_encode([
            'observed_at' => '2026-07-27 12:00:00',
            'hotels' => [[
                'source_hotel_id' => 5,
                'name' => '敦煌漠蓝新',
                'tasks' => [[
                    'key' => 'hourly_operating_monitor',
                    'name' => '每小时经营监控',
                    'status' => 'active',
                    'status_label' => '运行中',
                    'schedule' => '每小时整点',
                    'delivery_mode' => 'scheduled_send',
                    'delivery_rule' => '仅文字',
                    'target_robot_id' => 2,
                    'target_robot_name' => '宿析OS云端日报',
                    'last_run_at' => '2026-07-27 12:00:00',
                    'next_run_at' => '2026-07-27 13:00:00',
                    'last_result' => '已发送',
                    'service_result' => 'success',
                    'source' => 'snapshot',
                    'source_label' => '云端核验快照',
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        try {
            $service = new CloudMessageTaskOverviewService(
                static fn(string $unit): array => [],
                static fn(): array => [],
                new DateTimeImmutable('2026-07-27 20:00:01', new DateTimeZone('Asia/Shanghai')),
                $snapshotPath
            );
            $overview = $service->overview(80, 80);

            self::assertSame('stale_snapshot', $overview['source_status']);
            self::assertTrue($overview['is_stale']);
            self::assertSame('stale', $overview['tasks'][0]['status']);
            self::assertStringContainsString('云端酒店 5', $overview['identity_note']);
            self::assertStringContainsString('本地酒店 80', $overview['identity_note']);
        } finally {
            @unlink($snapshotPath);
        }
    }

    /** @return array<string, string> */
    private function timer(string $last, string $next): array
    {
        return [
            'LoadState' => 'loaded',
            'ActiveState' => 'active',
            'UnitFileState' => 'enabled',
            'LastTriggerUSec' => $last === '' ? '' : 'Mon ' . $last . ' CST',
            'NextElapseUSecRealtime' => $next === '' ? '' : 'Tue ' . $next . ' CST',
            'Result' => 'success',
        ];
    }

    /** @return array<string, string> */
    private function service(): array
    {
        return [
            'LoadState' => 'loaded',
            'ActiveState' => 'inactive',
            'Result' => 'success',
            'ExecMainStatus' => '0',
        ];
    }
}
