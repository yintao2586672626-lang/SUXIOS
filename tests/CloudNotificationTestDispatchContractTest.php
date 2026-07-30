<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CloudNotificationTestDispatchContractTest extends TestCase
{
    public function testDispatchUnitUsesExplicitVerifiedTestScopeFromEnvironment(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-manual-notification-test-dispatch.service'
        );
        $timer = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-manual-notification-test-dispatch.timer'
        );

        self::assertMatchesRegularExpression(
            '/^ExecStart=.*manual-notification:schedule'
                . ' --dispatch --mode=test'
                . ' --hotel-id=\$\{SUXIOS_MANUAL_NOTIFICATION_HOTEL_ID\}'
                . ' --robot-id=\$\{SUXIOS_MANUAL_NOTIFICATION_ROBOT_ID\}'
                . ' --limit=5$/m',
            $service
        );
        self::assertStringContainsString('TimeoutStartSec=3min', $service);
        self::assertStringNotContainsString('--mode=formal', $service);
        self::assertStringContainsString(
            'EnvironmentFile=/etc/suxios/manual-notification-test-dispatch.env',
            $service
        );
        self::assertStringContainsString('Persistent=false', $timer);
        self::assertStringContainsString(
            'Unit=suxios-manual-notification-test-dispatch.service',
            $timer
        );
    }

    public function testInstallerRejectsBrowserReleaseAndDefaultsToCheckOnly(): void
    {
        $installer = (string)file_get_contents(
            dirname(__DIR__) . '/deploy/systemd/install_manual_notification_test_dispatch.sh'
        );
        $browserRefusal = strpos($installer, 'suxios-cloud-browser-*');
        $installWrite = strpos($installer, 'install -o root -g root -m 0644');
        $timerDisable = strpos($installer, 'systemctl disable --now "$TIMER_NAME"');

        self::assertNotFalse($browserRefusal);
        self::assertNotFalse($installWrite);
        self::assertNotFalse($timerDisable);
        self::assertLessThan($installWrite, $browserRefusal);
        self::assertLessThan($installWrite, $timerDisable);
        self::assertStringContainsString('Full application release marker missing', $installer);
        self::assertStringContainsString(
            "grep -qE '^manual-notification:schedule[[:space:]]'",
            $installer
        );
        self::assertStringContainsString('verify_manual_notification_test_dispatch.php', $installer);
        self::assertStringContainsString('ManualNotificationDispatchLedgerService.php', $installer);
        self::assertStringContainsString(
            '20260726_extend_manual_notification_schedule_runs_scope_robot.sql',
            $installer
        );
        self::assertStringContainsString('--require-enabled', $installer);
        self::assertStringContainsString('--hotel-id) HOTEL_ID="$2"', $installer);
        self::assertStringContainsString('--robot-id) ROBOT_ID="$2"', $installer);
        self::assertStringNotContainsString('manual-notification:schedule --preview', $installer);
        self::assertStringContainsString(
            '--hotel-id="$HOTEL_ID" --robot-id="$ROBOT_ID"',
            $installer
        );
        self::assertStringContainsString(
            'SUXIOS_MANUAL_NOTIFICATION_HOTEL_ID=%s',
            $installer
        );
        self::assertStringContainsString(
            '--release-root must be the release currently resolved by /var/www/suxios/current',
            $installer
        );
        self::assertStringContainsString('sudo -u www-data test -r "$ENV_FILE"', $installer);
        self::assertStringContainsString('systemd-analyze verify', $installer);
        self::assertStringContainsString('CHECK_OK', $installer);
        self::assertStringContainsString('install_requested=0 enable_requested=0', $installer);
        self::assertStringNotContainsString(
            'installed=0 enabled=0 database_write=0',
            $installer
        );
        self::assertStringContainsString(
            'Install refused while a test dispatch is running',
            $installer
        );
        self::assertStringContainsString('systemctl disable --now "$TIMER_NAME"', $installer);
        self::assertStringContainsString('assert_timer_disabled', $installer);
        self::assertStringContainsString('""|disabled|not-found', $installer);
        self::assertStringNotContainsString(
            'systemctl disable --now "$TIMER_NAME" >/dev/null 2>&1 || true',
            $installer
        );
        self::assertStringContainsString('--enable-test-dispatch', $installer);
    }

    public function testPreviewRemainsDefaultAndScopedDispatchRequiresExactPair(): void
    {
        $root = dirname(__DIR__);
        $preview = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-manual-notification-schedule-preview.service'
        );
        $command = (string)file_get_contents($root . '/app/command/RunManualNotificationSchedule.php');
        $verifier = (string)file_get_contents(
            $root . '/deploy/systemd/verify_manual_notification_test_dispatch.php'
        );

        self::assertStringContainsString(
            'verify_manual_notification_test_dispatch.php',
            $preview
        );
        self::assertStringNotContainsString('manual-notification:schedule', $preview);
        self::assertStringNotContainsString('--dispatch', $preview);
        self::assertStringContainsString("->addOption('hotel-id'", $command);
        self::assertStringContainsString("->addOption('robot-id'", $command);
        self::assertStringContainsString(
            'Dispatch requires --mode=test with one explicit --hotel-id/--robot-id pair.',
            $command
        );
        self::assertStringContainsString(
            "(string)(\$result['status'] ?? '') !== 'dispatch_checked'",
            $command
        );
        self::assertStringNotContainsString('$scopeHotelId !== 80', $command);
        self::assertStringNotContainsString('$scopeRobotId !== 1', $command);
        self::assertStringContainsString('ManualNotificationTestTargetService', $verifier);
        self::assertStringContainsString(
            '`scope_hotel_id`, `scope_robot_id`, `status`',
            $verifier
        );
        self::assertStringContainsString(
            '`payload_snapshot_json`',
            $verifier
        );
        self::assertStringContainsString("'webhook_read' => false", $verifier);
        self::assertStringNotContainsString("field('webhook", $verifier);
    }

    public function testRunbookNamesOneExplicitActivationAction(): void
    {
        $document = (string)file_get_contents(
            dirname(__DIR__) . '/docs/manual_notification_cloud_deployment.md'
        );

        self::assertStringContainsString('## 授权后的单一真实启用动作', $document);
        self::assertStringContainsString('--install', $document);
        self::assertStringContainsString('--enable-test-dispatch', $document);
        self::assertStringContainsString(
            "SET notification_scope = 'operating_target_test'",
            $document
        );
        self::assertStringContainsString(
            '若原作用域非空且不同，`UPDATE` 必须保持 0 行并停止部署',
            $document
        );
        self::assertStringContainsString('disabled', $document);
        self::assertStringContainsString('inactive', $document);
    }

    public function testFullApplicationConsoleRegistersTheScheduleCommand(): void
    {
        $console = (string)file_get_contents(dirname(__DIR__) . '/config/console.php');

        self::assertStringContainsString(
            "'manual-notification:schedule' => 'app\\command\\RunManualNotificationSchedule'",
            $console
        );
        self::assertSame(1, substr_count($console, "'manual-notification:schedule' =>"));
    }
}
