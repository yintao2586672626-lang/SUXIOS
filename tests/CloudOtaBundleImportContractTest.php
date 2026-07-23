<?php
declare(strict_types=1);

namespace Tests;

use app\command\RunCloudDataBridge;
use app\service\CloudOtaBundleImportService;
use app\service\CloudOtaBundleExportService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CloudOtaBundleImportContractTest extends TestCase
{
    public function testImporterCannotTriggerCollectionReportOrWechatDelivery(): void
    {
        $source = (string)file_get_contents((new ReflectionMethod(
            CloudOtaBundleImportService::class,
            'importBundle'
        ))->getFileName());

        self::assertStringContainsString('CloudOtaBundleCodec::verify', $source);
        self::assertStringContainsString('assertDestinationBindings', $source);
        self::assertStringContainsString('destinationRowMatches', $source);
        self::assertStringContainsString('markReadbackVerified', $source);
        self::assertStringNotContainsString('DailyWorkbenchPatrol', $source);
        self::assertStringNotContainsString('AiDailyReportService', $source);
        self::assertStringNotContainsString('WechatRobotDeliveryService', $source);
        self::assertStringNotContainsString('Cookie', $source);
    }

    public function testEmptyInboxDoesNoCollectionReportOrDatabaseWork(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'suxios-cloud-bridge-' . bin2hex(random_bytes(6));
        $service = new CloudOtaBundleImportService();
        try {
            $result = $service->processInbox($directory, 0, 10, 10);
            self::assertSame('succeeded', $result['status']);
            self::assertSame(0, $result['processed_count']);
            self::assertSame(0, $result['inbox_count']);
            self::assertFalse($result['collection_triggered']);
            self::assertFalse($result['report_generation_triggered']);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testExporterRefusesOversizedSourceAndImporterRetiresOnlyCompleteSnapshots(): void
    {
        $exportSource = (string)file_get_contents((new ReflectionMethod(
            CloudOtaBundleExportService::class,
            'trustedTargetRows'
        ))->getFileName());
        $importSource = (string)file_get_contents((new ReflectionMethod(
            CloudOtaBundleImportService::class,
            'importPackage'
        ))->getFileName());

        self::assertStringContainsString('CloudOtaBundleCodec::MAX_ROWS + 1', $exportSource);
        self::assertStringContainsString('cloud_bundle_source_row_limit_exceeded', $exportSource);
        self::assertStringContainsString("->where('sync_task_id', (int)\$syncTask['id'])", $exportSource);
        self::assertStringContainsString("'source_sync_task_id' => \$syncTaskId", $exportSource);
        self::assertStringContainsString("'snapshot_complete' => count(\$rows) === \$targetRowCount", $exportSource);
        self::assertStringContainsString('cloud_bundle_sync_task_row_identity_mismatch:', $exportSource);
        self::assertStringContainsString("->whereIn('id', \$receiptRowIds)", $exportSource);
        self::assertStringContainsString('count($rows) === $targetRowCount', $exportSource);
        self::assertStringContainsString('($package[\'snapshot_complete\'] ?? false) === true', $importSource);
        self::assertStringContainsString('(int)($package[\'source_row_count\'] ?? -1) === count($rows)', $importSource);
    }

    public function testExporterIncludesOnlyExplicitlyRequestedPlatformPackages(): void
    {
        $source = (string)file_get_contents((new ReflectionMethod(
            CloudOtaBundleExportService::class,
            'export'
        ))->getFileName());

        self::assertStringContainsString('$selectedBindings = array_values(array_filter(', $source);
        self::assertStringContainsString("in_array((string)\$item['platform'], \$requiredPlatforms, true)", $source);
        self::assertStringContainsString('foreach ($selectedBindings as $item)', $source);
        self::assertStringContainsString('loadVerifiedSyncTask(', $source);
    }

    public function testExportCommandParsesExactSourceToSyncTaskBindings(): void
    {
        $method = new ReflectionMethod(new RunCloudDataBridge(), 'parseSyncTaskIds');
        self::assertSame([25 => 901, 68 => 902], $method->invoke(new RunCloudDataBridge(), '68:902,25:901'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unique positive source_id:sync_task_id pairs');
        $method->invoke(new RunCloudDataBridge(), '25:901,25:902');
    }

    public function testSystemdBridgeIsShortLivedAndResourceBounded(): void
    {
        $root = dirname(__DIR__);
        $service = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-data-bridge.service');
        $timer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-data-bridge.timer');

        self::assertStringContainsString('Type=oneshot', $service);
        self::assertStringContainsString('MemoryMax=384M', $service);
        self::assertStringContainsString('CPUQuota=50%', $service);
        self::assertStringContainsString('cloud-data-bridge:run --mode=import', $service);
        self::assertStringContainsString('OnUnitActiveSec=5min', $timer);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
