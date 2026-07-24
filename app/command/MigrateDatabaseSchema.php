<?php
declare(strict_types=1);

namespace app\command;

use app\service\SchemaVersionService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class MigrateDatabaseSchema extends Command
{
    protected function configure(): void
    {
        $this->setName('db:migrate')
            ->setDescription('Apply and register pending database migrations')
            ->addOption(
                'baseline',
                null,
                Option::VALUE_NONE,
                'Register the frozen init_full.sql baseline before upgrading a legacy database'
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $service = $this->service();
            $baseline = (bool)$input->getOption('baseline');
            if ($service->requiresLegacyBaseline() && !$baseline) {
                $output->writeln('Database migration history is missing.');
                $output->writeln('Verify this legacy database, then run: php think db:migrate --baseline');
                return 2;
            }

            if ($baseline) {
                $registered = $service->baselineInitFullSources();
                $output->writeln("Frozen baseline registered: {$registered} migration(s).");
            }

            $result = $service->migrate();
            foreach ($result['executed'] as $migration) {
                $output->writeln("Applied: {$migration}");
            }
            $status = $result['status'];
            $output->writeln(sprintf(
                'Database schema is current: %s (%d/%d registered).',
                (string)($status['required_version'] ?? 'unknown'),
                (int)$status['applied_count'],
                (int)$status['required_count']
            ));
            return 0;
        } catch (Throwable $exception) {
            $output->writeln('Database migration failed: ' . $exception->getMessage());
            return 1;
        }
    }

    private function service(): SchemaVersionService
    {
        $default = (string)config('database.default', 'mysql');
        $config = (array)config("database.connections.{$default}", []);
        return SchemaVersionService::fromDatabaseConfig($config, app()->getRootPath());
    }
}
