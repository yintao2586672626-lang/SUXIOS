<?php
declare(strict_types=1);

namespace app\command;

use app\service\FreshDatabaseInitializerService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class InitializeDatabaseSchema extends Command
{
    protected function configure(): void
    {
        $this->setName('db:init')
            ->setDescription('Initialize an empty database through the governed migration catalog');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $result = FreshDatabaseInitializerService::initialize($config, app()->getRootPath());
            $status = $result['status'];
            $output->writeln(sprintf(
                'Database initialized: baseline=%d, newly_applied=%d, version=%s, registered=%d/%d.',
                (int)$result['baseline_registered'],
                count($result['executed']),
                (string)($status['required_version'] ?? 'unknown'),
                (int)$status['applied_count'],
                (int)$status['required_count']
            ));
            return 0;
        } catch (Throwable $exception) {
            $output->writeln('Database initialization failed: ' . $exception->getMessage());
            return 1;
        }
    }
}
