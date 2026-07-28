<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ManualNotificationScheduleContractTest extends TestCase
{
    public function testCommandAndSystemdAssetsRemainPreviewFirst(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/RunManualNotificationSchedule.php');
        $service = (string)file_get_contents($root . '/app/service/ManualNotificationScheduleService.php');
        $unit = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-manual-notification-schedule-preview.service'
        );
        $timer = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-manual-notification-schedule-preview.timer'
        );

        self::assertStringContainsString("->addOption('preview'", $command);
        self::assertStringContainsString("->addOption('dispatch'", $command);
        self::assertStringContainsString('$dispatch = (bool)$input->getOption(\'dispatch\')', $command);
        self::assertStringContainsString('if ($dispatch) {', $command);
        self::assertStringContainsString("->where('enabled', 1)", $service);
        self::assertStringContainsString("->where('schedule_status', 'schedule_enabled')", $service);
        self::assertStringContainsString('dispatch_window_already_claimed', $service);
        self::assertStringContainsString("'wecom_formal'", $service);
        self::assertStringContainsString('resolvePlanRobot(', $service);
        self::assertStringNotContainsString('formal_delivery_not_authorized', $service);
        self::assertStringNotContainsString('--dispatch', $unit);
        self::assertStringContainsString('--preview', $unit);
        self::assertStringNotContainsString('[Install]', $timer);
    }

    public function testDispatchMigrationUsesNotificationWindowModeUniqueness(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260726_create_manual_notification_schedule_dispatches.sql'
        );

        self::assertStringContainsString('manual_notification_schedule_dispatches', $sql);
        self::assertMatchesRegularExpression(
            '/UNIQUE KEY[^\\n]+\\n\\s*\\(`notification_id`, `dispatch_window`, `delivery_mode`\\)/',
            $sql
        );
    }

    public function testDispatchBaseMigrationSortsBeforeAttemptExtension(): void
    {
        $files = glob(dirname(__DIR__) . '/database/migrations/20260726_*manual_notification_*.sql');
        self::assertIsArray($files);
        sort($files, SORT_STRING);
        $names = array_map('basename', $files);
        $base = array_search(
            '20260726_create_manual_notification_schedule_dispatches.sql',
            $names,
            true
        );
        $attempts = array_search(
            '20260726_extend_manual_notification_dispatch_attempts.sql',
            $names,
            true
        );
        self::assertIsInt($base);
        self::assertIsInt($attempts);
        self::assertLessThan($attempts, $base);
    }
}
