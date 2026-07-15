<?php
declare(strict_types=1);

namespace app\command;

use app\model\SystemNotification;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

final class MigrateNotificationRecipients extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate:notification-recipients')
            ->setDescription('Add targeted recipient support to system notifications');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!SystemNotification::tableReady()) {
            $output->writeln('system_notifications table is missing; run the base notification migration first.');
            return 1;
        }

        if (SystemNotification::recipientTargetingReady()) {
            $this->ensureIndex();
            $output->writeln('Notification recipient schema is already ready.');
            return 0;
        }

        $driver = strtolower((string)Db::connect()->getConfig('type'));
        if ($driver === 'sqlite') {
            Db::execute('ALTER TABLE `system_notifications` ADD COLUMN `recipient_user_id` INTEGER DEFAULT NULL');
        } else {
            Db::execute("ALTER TABLE `system_notifications` ADD COLUMN `recipient_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Target recipient user; NULL keeps legacy broadcast semantics' AFTER `user_id`");
        }
        $this->ensureIndex();

        if (!SystemNotification::recipientTargetingReady()) {
            $output->writeln('Notification recipient migration did not become visible.');
            return 1;
        }

        $output->writeln('Notification recipient schema migrated successfully.');
        return 0;
    }

    private function ensureIndex(): void
    {
        try {
            Db::execute('CREATE INDEX IF NOT EXISTS `idx_system_notifications_recipient` ON `system_notifications` (`recipient_user_id`, `is_cleared`, `is_read`, `update_time`)');
        } catch (\Throwable) {
            $indexes = Db::query("SHOW INDEX FROM `system_notifications` WHERE `Key_name` = 'idx_system_notifications_recipient'");
            if (empty($indexes)) {
                Db::execute('CREATE INDEX `idx_system_notifications_recipient` ON `system_notifications` (`recipient_user_id`, `is_cleared`, `is_read`, `update_time`)');
            }
        }
    }
}
