<?php
declare(strict_types=1);

namespace app\command;

use app\service\OperatingQuestionCouncilService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use Throwable;

final class RunOperatingQuestionCouncilOnce extends Command
{
    protected function configure()
    {
        $this->setName(OperatingQuestionCouncilService::WORKER_COMMAND)
            ->addOption('run-id', null, Option::VALUE_REQUIRED, 'Reserved council run id')
            ->addOption('tenant-id', null, Option::VALUE_REQUIRED, 'Reserved council tenant id')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Reserved council hotel id')
            ->addOption('parent-digest', null, Option::VALUE_REQUIRED, 'CAS parent content digest')
            ->addOption('retry-failed', null, Option::VALUE_NONE, 'Retry failed lens checkpoints and chair synthesis')
            ->setDescription('Execute or resume one reserved operating-question council run');
    }

    protected function execute(Input $input, Output $output)
    {
        $runId = (int)$input->getOption('run-id');
        $tenantId = (int)$input->getOption('tenant-id');
        $hotelId = (int)$input->getOption('hotel-id');
        $parentDigest = strtolower(trim((string)$input->getOption('parent-digest')));
        if ($runId <= 0 || $tenantId <= 0 || $hotelId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $parentDigest) !== 1
        ) {
            $output->writeln('Council worker scope is invalid.');
            return 1;
        }

        try {
            $run = (new OperatingQuestionCouncilService())->processRun(
                $runId,
                $tenantId,
                [$hotelId],
                (bool)$input->getOption('retry-failed'),
                $parentDigest
            );
            $status = (string)($run['status'] ?? 'unknown');
            $workerStatus = (string)($run['worker_status'] ?? 'unknown');
            $output->writeln('Council run #' . $runId . ' status=' . $status . ' worker=' . $workerStatus . '.');
            if ($workerStatus === 'busy') {
                return 0;
            }
            return in_array($status, [
                'completed',
                'partial',
                'blocked_by_missing_facts',
                'blocked_not_configured',
            ], true) ? 0 : 2;
        } catch (Throwable $e) {
            $output->writeln('Council worker failed: ' . ($e->getMessage() ?: 'unknown error'));
            return 1;
        }
    }
}
