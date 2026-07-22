<?php
declare(strict_types=1);

namespace app\command;

use app\service\CloudOtaBundleExportService;
use app\service\CloudOtaBundleImportService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Env;

final class RunCloudDataBridge extends Command
{
    protected function configure()
    {
        $this->setName('cloud-data-bridge:run')
            ->addOption('mode', null, Option::VALUE_REQUIRED, 'export|import|validate|status', 'status')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Business date YYYY-MM-DD; defaults to yesterday')
            ->addOption('platforms', null, Option::VALUE_REQUIRED, 'Required OTA platforms', 'ctrip,meituan')
            ->addOption('binding-file', null, Option::VALUE_REQUIRED, 'Explicit local-to-cloud hotel/data-source binding JSON')
            ->addOption('output-file', null, Option::VALUE_REQUIRED, 'Export destination JSON path')
            ->addOption('bundle-file', null, Option::VALUE_REQUIRED, 'One bundle JSON to validate')
            ->addOption('state-dir', null, Option::VALUE_REQUIRED, 'Cloud bridge state directory')
            ->addOption('actor-user-id', null, Option::VALUE_REQUIRED, 'Enabled cloud user used for permission checks')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum inbox bundles per run, 1-50', '10')
            ->addOption('max-attempts', null, Option::VALUE_REQUIRED, 'Maximum transient import attempts, 1-50', '10')
            ->setDescription('Export verified local OTA facts or import credential-free bundles from the cloud inbox');
    }

    protected function execute(Input $input, Output $output)
    {
        date_default_timezone_set('Asia/Shanghai');
        $mode = strtolower(trim((string)$input->getOption('mode')));
        if (!in_array($mode, ['export', 'import', 'validate', 'status'], true)) {
            $output->writeln('mode must be export, import, validate, or status.');
            return 1;
        }

        try {
            $stateDir = $this->stateDirectory((string)$input->getOption('state-dir'));
            $importer = new CloudOtaBundleImportService();
            if ($mode === 'status') {
                $this->writeJson($output, $importer->status($stateDir));
                return 0;
            }

            $actorUserId = $this->actorUserId((string)$input->getOption('actor-user-id'));
            if ($mode === 'import') {
                $result = $importer->processInbox(
                    $stateDir,
                    $actorUserId,
                    max(1, min(50, (int)$input->getOption('limit'))),
                    max(1, min(50, (int)$input->getOption('max-attempts')))
                );
                $this->writeJson($output, $result);
                return in_array((string)($result['status'] ?? ''), ['succeeded', 'partial', 'skipped'], true) ? 0 : 2;
            }

            if ($mode === 'validate') {
                $bundleFile = trim((string)$input->getOption('bundle-file'));
                if ($bundleFile === '') {
                    throw new \RuntimeException('bundle-file is required for validate mode');
                }
                $result = $importer->importFile($bundleFile, $actorUserId, true);
                $this->writeJson($output, $result);
                return 0;
            }

            $bindingFile = trim((string)$input->getOption('binding-file'));
            if ($bindingFile === '') {
                throw new \RuntimeException('binding-file is required for export mode');
            }
            $targetDate = trim((string)$input->getOption('target-date'));
            if ($targetDate === '') {
                $targetDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
                    ->modify('-1 day')
                    ->format('Y-m-d');
            }
            $platforms = array_values(array_filter(array_map(
                static fn(string $platform): string => strtolower(trim($platform)),
                explode(',', (string)$input->getOption('platforms'))
            ), static fn(string $platform): bool => $platform !== ''));
            $outputFile = trim((string)$input->getOption('output-file'));
            if ($outputFile === '') {
                $outputFile = rtrim((string)runtime_path(), "\\/")
                    . DIRECTORY_SEPARATOR . 'cloud_bridge'
                    . DIRECTORY_SEPARATOR . 'outbox'
                    . DIRECTORY_SEPARATOR . 'ota-' . $targetDate . '.json';
            }
            $exporter = new CloudOtaBundleExportService();
            $result = $exporter->export(
                $exporter->readBindingFile($bindingFile),
                $targetDate,
                $platforms,
                $outputFile
            );
            $this->writeJson($output, $result);
            return 0;
        } catch (\Throwable $exception) {
            $message = preg_replace(
                '/(key|token|secret|cookie|password|authorization)\s*[=:]\s*[^\s,;]+/i',
                '$1=<redacted>',
                $exception->getMessage()
            ) ?? '';
            $output->writeln('Cloud data bridge failed: ' . mb_substr(trim($message), 0, 240, 'UTF-8'));
            return 1;
        }
    }

    private function stateDirectory(string $option): string
    {
        $option = trim($option);
        if ($option !== '') {
            return $option;
        }
        $configured = getenv('CLOUD_DATA_BRIDGE_STATE_DIR');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }
        $configured = trim((string)Env::get('CLOUD_DATA_BRIDGE_STATE_DIR', ''));
        if ($configured !== '') {
            return $configured;
        }
        $automationDir = getenv('CLOUD_AUTOMATION_STATE_DIR');
        if (is_string($automationDir) && trim($automationDir) !== '') {
            return rtrim(trim($automationDir), "\\/") . DIRECTORY_SEPARATOR . 'bridge';
        }
        return rtrim((string)runtime_path(), "\\/") . DIRECTORY_SEPARATOR . 'cloud_bridge';
    }

    private function actorUserId(string $option): int
    {
        $option = trim($option);
        if ($option !== '') {
            return max(0, (int)$option);
        }
        $configured = getenv('CLOUD_DATA_BRIDGE_ACTOR_USER_ID');
        if (is_string($configured) && trim($configured) !== '') {
            return max(0, (int)$configured);
        }
        return max(0, (int)Env::get('CLOUD_DATA_BRIDGE_ACTOR_USER_ID', 0));
    }

    /** @param array<string, mixed> $result */
    private function writeJson(Output $output, array $result): void
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $output->writeln(is_string($json) ? $json : '{"status":"serialization_failed"}');
    }
}
