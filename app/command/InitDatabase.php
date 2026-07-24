<?php
declare(strict_types=1);

namespace app\command;

use app\service\FreshDatabaseInitializerService;
use app\service\SchemaVersionService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * Backward-compatible class alias for callers that instantiated the former
 * ad-hoc initializer directly. The registered db:init command uses
 * InitializeDatabaseSchema and both paths now share the governed catalog.
 */
class InitDatabase extends Command
{
    protected function configure(): void
    {
        $this->setName('db:init-legacy')
            ->setDescription('Compatibility alias for the governed database initializer');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $result = FreshDatabaseInitializerService::initialize($config, app()->getRootPath());
            $status = $result['status'];
            $output->writeln(sprintf(
                'Database initialized through SchemaVersionService: version=%s, registered=%d/%d.',
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
