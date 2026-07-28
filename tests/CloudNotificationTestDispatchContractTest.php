<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CloudNotificationTestDispatchContractTest extends TestCase
{
    public function testDispatchUnitIsPinnedToHotel80Robot1TestMode(): void
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
                . ' --dispatch --mode=test --hotel-id=80 --robot-id=1 --limit=100$/m',
            $service
        );
        self::assertStringNotContainsString('--mode=formal', $service);
        self::assertStringNotContainsString('EnvironmentFile=', $service);
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

        self::assertNotFalse($browserRefusal);
        self::assertNotFalse($installWrite);
        self::assertLessThan($installWrite, $browserRefusal);
        self::assertStringContainsString('Full application release marker missing', $installer);
        self::assertStringContainsString(
            "grep -qE '^manual-notification:schedule[[:space:]]'",
            $installer
        );
        self::assertStringContainsString('verify_manual_notification_test_dispatch.php', $installer);
        self::assertStringContainsString('ManualNotificationDispatchLedgerService.php', $installer);
        self::assertStringContainsString('--require-enabled', $installer);
        self::assertStringContainsString('--preview --mode=test --hotel-id=80 --robot-id=1', $installer);
        self::assertStringContainsString('CHECK_OK', $installer);
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

        self::assertStringContainsString('--preview --mode=test', $preview);
        self::assertStringNotContainsString('--dispatch', $preview);
        self::assertStringContainsString("->addOption('hotel-id'", $command);
        self::assertStringContainsString("->addOption('robot-id'", $command);
        self::assertStringContainsString('manual_notification_schedule_scope_pair_required', (string)file_get_contents(
            $root . '/app/service/ManualNotificationScheduleService.php'
        ));
        self::assertStringContainsString('deliverToPlanRobot(', $command);
        self::assertStringNotContainsString('$scopeHotelId !== 80', $command);
        self::assertStringNotContainsString('$scopeRobotId !== 1', $command);
        self::assertStringContainsString("'webhook_read' => false", $verifier);
        self::assertStringNotContainsString("field('webhook", $verifier);
    }

    public function testRunbookNamesOneExplicitActivationAction(): void
    {
        $document = (string)file_get_contents(
            dirname(__DIR__) . '/docs/manual_notification_cloud_deployment.md'
        );

        self::assertStringContainsString('## 授权后的单一真实部署动作', $document);
        self::assertStringContainsString('--install', $document);
        self::assertStringContainsString('--enable-test-dispatch', $document);
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
