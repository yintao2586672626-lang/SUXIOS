<?php
declare(strict_types=1);

namespace app\command;

use app\service\SchemaVersionService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class MigrateOnlineData extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate:online-data')
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

    /** @return array<string,string> */
    protected function onlineDailyDataFieldsToAdd(): array
    {
        return [
            'system_hotel_id' => 'INTEGER',
            'data_value' => 'DECIMAL(15,2) DEFAULT 0',
            'source' => "VARCHAR(50) DEFAULT 'ctrip'",
            'dimension' => "VARCHAR(512) DEFAULT ''",
            'data_type' => "VARCHAR(50) DEFAULT ''",
            'validation_status' => "VARCHAR(60) DEFAULT 'normal'",
            'validation_flags' => 'TEXT',
            'data_source_id' => 'INTEGER',
            'sync_task_id' => 'BIGINT',
            'ingestion_method' => "VARCHAR(30) NOT NULL DEFAULT 'legacy'",
            'source_trace_id' => 'VARCHAR(80) DEFAULT NULL',
        ];
    }
}
