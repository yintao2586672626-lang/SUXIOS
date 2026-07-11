<?php
declare(strict_types=1);

namespace app\command;

use app\service\OtaCredentialMigrationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use Throwable;

final class MigrateOtaCredentials extends Command
{
    protected function configure()
    {
        $this->setName('migrate:ota-credentials')
            ->addOption('execute', null, Option::VALUE_NONE, 'Execute verified migrations; omitted means dry-run')
            ->setDescription('Inventory legacy OTA credentials and migrate only verified hotel bindings');
    }

    protected function execute(Input $input, Output $output)
    {
        $execute = (bool)$input->getOption('execute');
        try {
            $summary = (new OtaCredentialMigrationService())->run($execute);
        } catch (Throwable) {
            $output->writeln(json_encode([
                'mode' => $execute ? 'execute' : 'dry-run',
                'status' => 'blocked',
                'reason_code' => 'migration_failed',
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $output->writeln(json_encode(
            $summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        return in_array((string)($summary['status'] ?? ''), ['ready', 'completed'], true) ? 0 : 1;
    }
}
