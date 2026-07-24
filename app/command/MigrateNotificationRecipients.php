<?php
declare(strict_types=1);

namespace app\command;

use app\service\SchemaVersionService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class MigrateNotificationRecipients extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate:notification-recipients')
            ->setDescription('Compatibility alias; apply the governed migration catalog');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $result = SchemaVersionService::fromDatabaseConfig($config, app()->getRootPath())->migrate();
            $output->writeln(sprintf(
                'Governed migration catalog applied: %d migration(s).',
                count($result['executed'])
            ));
            return 0;
        } catch (Throwable $exception) {
            $output->writeln('Database migration failed: ' . $exception->getMessage());
            return 1;
        }
    }
}
