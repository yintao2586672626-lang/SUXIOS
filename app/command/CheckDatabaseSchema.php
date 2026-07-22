<?php
declare(strict_types=1);

namespace app\command;

use app\service\SchemaVersionService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class CheckDatabaseSchema extends Command
{
    protected function configure(): void
    {
        $this->setName('db:check')
            ->setDescription('Check whether the database schema matches all project migrations');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $service = SchemaVersionService::fromDatabaseConfig($config, app()->getRootPath());
            $status = $service->status();

            if ($status['ready']) {
                $output->writeln(sprintf(
                    'Database schema is current: %s (%d/%d registered).',
                    (string)($status['required_version'] ?? 'unknown'),
                    (int)$status['applied_count'],
                    (int)$status['required_count']
                ));
                return 0;
            }

            $output->writeln(sprintf(
                'Database schema upgrade required: current=%s, required=%s, pending=%d.',
                (string)($status['current_version'] ?? 'unregistered'),
                (string)($status['required_version'] ?? 'unknown'),
                count($status['pending'])
            ));
            if ((int)$status['application_table_count'] === 0) {
                $output->writeln('Empty database: run php scripts/init_database.php');
            } elseif ($status['version_mismatches'] !== []
                || $status['checksum_mismatches'] !== []
                || $status['missing_checksums'] !== []
                || $status['unknown_registrations'] !== []
                || $status['baseline_checksum_mismatches'] !== []
                || $status['baseline_unknown'] !== []
            ) {
                $output->writeln(
                    'Migration evidence drift detected; inspect schema_versions, schema_baseline_sources, and the SQL catalog.'
                );
            } elseif (!$status['registry_exists']) {
                $output->writeln('Legacy database: run the baseline preflight with php think db:migrate --baseline');
            } elseif ($status['unresolved_failures'] !== []) {
                $output->writeln(
                    'Unresolved migration failure(s): ' . implode(', ', $status['unresolved_failures'])
                );
                $output->writeln('Fix the recorded cause, then run: php think db:migrate');
            } elseif (!$status['registry_checksum_supported']
                || !$status['registry_execution_kind_supported']
                || !$status['baseline_registry_exists']
                || $status['baseline_missing'] !== []
            ) {
                $output->writeln('Migration evidence tables are incomplete. Run: php think db:migrate');
            } else {
                $output->writeln('Run: php think db:migrate');
            }
            return 2;
        } catch (Throwable $exception) {
            $output->writeln('Database schema check failed: ' . $exception->getMessage());
            return 1;
        }
    }
}
