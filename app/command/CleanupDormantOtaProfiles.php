<?php
declare(strict_types=1);

namespace app\command;

use app\service\OtaProfileRetentionService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class CleanupDormantOtaProfiles extends Command
{
    protected function configure()
    {
        $this->setName('online-data:cleanup-dormant-profiles')
            ->addOption('retention-days', null, Option::VALUE_REQUIRED, 'Profile inactivity retention in days; default 30')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Preview expired OTA credentials and local assets without changing them')
            ->setDescription('Revoke dormant OTA credentials and remove expired local Profiles or disposable artifacts');
    }

    protected function execute(Input $input, Output $output)
    {
        $value = trim((string)($input->getOption('retention-days') ?? ''));
        if ($value !== '' && preg_match('/^\d+$/D', $value) !== 1) {
            $output->writeln('retention-days must be an integer between 1 and 3650.');
            return 2;
        }
        $days = $value === '' ? OtaProfileRetentionService::DEFAULT_RETENTION_DAYS : (int)$value;
        if ($days < 1 || $days > 3650) {
            $output->writeln('retention-days must be an integer between 1 and 3650.');
            return 2;
        }

        try {
            $result = (new OtaProfileRetentionService())->cleanup(
                $days,
                (bool)$input->getOption('dry-run')
            );
        } catch (\Throwable) {
            $output->writeln((string)json_encode([
                'retention_days' => $days,
                'dry_run' => (bool)$input->getOption('dry-run'),
                'errors' => 1,
                'error_codes' => ['retention_command_failed'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 1;
        }

        $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return (int)($result['errors'] ?? 0) === 0 ? 0 : 1;
    }
}
