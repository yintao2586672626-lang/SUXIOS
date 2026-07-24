<?php
declare(strict_types=1);

namespace app\command;

use app\service\SensitiveStorageMigrationService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class MigrateSensitiveStorage extends Command
{
    protected function configure()
    {
        $this->setName('migrate:sensitive-storage')
            ->addOption('execute', null, Option::VALUE_NONE, 'Encrypt verified legacy values; omitted means dry-run')
            ->setDescription('Encrypt legacy system secrets and enterprise WeChat robot webhooks');
    }

    protected function execute(Input $input, Output $output)
    {
        $execute = (bool)$input->getOption('execute');
        try {
            $summary = (new SensitiveStorageMigrationService())->run($execute);
        } catch (Throwable) {
            $output->writeln(json_encode([
                'mode' => $execute ? 'execute' : 'dry-run',
                'status' => 'blocked',
                'reason_code' => 'sensitive_storage_key_or_migration_failure',
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $output->writeln(json_encode(
            $summary,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        return in_array((string)($summary['status'] ?? ''), ['ready', 'migration_required', 'completed'], true) ? 0 : 1;
    }
}
