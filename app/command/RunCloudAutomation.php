<?php
declare(strict_types=1);

namespace app\command;

use app\service\CloudAutomationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class RunCloudAutomation extends Command
{
    protected function configure()
    {
        $this->setName('cloud-automation:run')
            ->addOption('mode', null, Option::VALUE_REQUIRED, 'daily|health|weekly|retry|status', 'daily')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Business date YYYY-MM-DD; defaults to yesterday')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum hotels/deliveries, 1-100', '30')
            ->addOption('max-attempts', null, Option::VALUE_REQUIRED, 'Maximum message delivery attempts, 1-50', '10')
            ->addOption('no-push', null, Option::VALUE_NONE, 'Generate/check without queueing or sending WeCom messages')
            ->addOption('use-llm', null, Option::VALUE_NONE, 'Enable configured remote LLM enhancement for daily reports')
            ->setDescription('Run serial cloud patrol, report, WeCom delivery, weekly digest, or message-only retry');
    }

    protected function execute(Input $input, Output $output)
    {
        date_default_timezone_set('Asia/Shanghai');
        $mode = strtolower(trim((string)$input->getOption('mode')));
        if (!in_array($mode, ['daily', 'health', 'weekly', 'retry', 'status'], true)) {
            $output->writeln('mode must be daily, health, weekly, retry, or status.');
            return 1;
        }

        $targetDate = trim((string)$input->getOption('target-date'));
        if ($targetDate === '') {
            $targetDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
                ->modify('-1 day')
                ->format('Y-m-d');
        }
        $limit = max(1, min(100, (int)$input->getOption('limit')));
        $maxAttempts = max(1, min(50, (int)$input->getOption('max-attempts')));

        try {
            $service = new CloudAutomationService();
            if ($mode === 'status') {
                $this->writeJson($output, $service->status());
                return 0;
            }

            $lock = $service->acquireLock();
            if (!is_resource($lock)) {
                $output->writeln('Another cloud automation task is already running; this run was skipped.');
                return 0;
            }
            try {
                $result = $service->run($mode, $targetDate, [
                    'limit' => $limit,
                    'max_attempts' => $maxAttempts,
                    'push' => !$input->getOption('no-push'),
                    'use_llm' => (bool)$input->getOption('use-llm'),
                ]);
            } finally {
                $service->releaseLock($lock);
            }

            $this->writeJson($output, $result);
            return in_array((string)($result['status'] ?? ''), ['succeeded', 'partial'], true) ? 0 : 2;
        } catch (\Throwable $e) {
            $message = preg_replace('/(key|token|secret|cookie|password)\s*[=:]\s*[^\s,;]+/i', '$1=<redacted>', $e->getMessage()) ?? '';
            $output->writeln('Cloud automation failed: ' . mb_substr(trim($message), 0, 240, 'UTF-8'));
            return 1;
        }
    }

    /** @param array<string, mixed> $result */
    private function writeJson(Output $output, array $result): void
    {
        $json = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
        $output->writeln(is_string($json) ? $json : '{"status":"serialization_failed"}');
    }
}
