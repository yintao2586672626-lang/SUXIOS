<?php
declare(strict_types=1);

namespace Tests;

use app\command\RunCloudDataBridge;
use app\service\CloudOtaBundleCodec;
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

    public function testExporterExtractsOnlyAllowlistedOriginMetadata(): void
    {
        $service = new CloudOtaBundleExportService();
        $captureSource = new ReflectionMethod($service, 'safeCaptureSource');
        $originMethod = new ReflectionMethod($service, 'safeOriginIngestionMethod');

        self::assertSame('xhr:traffic:traffic', $captureSource->invoke($service, json_encode([
            'row' => ['_capture_source' => 'XHR:traffic:traffic'],
            'capture_evidence' => ['capture_source' => 'xhr:traffic:traffic'],
        ], JSON_THROW_ON_ERROR)));
        self::assertNull($captureSource->invoke($service, json_encode([
            'row' => ['_capture_source' => 'dom:traffic:flow_funnel'],
        ], JSON_THROW_ON_ERROR)));
        self::assertNull($captureSource->invoke($service, json_encode([
            'row' => ['_capture_source' => 'xhr:traffic:traffic'],
            'capture_evidence' => ['capture_source' => 'xhr:traffic:business_data'],
        ], JSON_THROW_ON_ERROR)));
        self::assertSame('local_collector', $originMethod->invoke(
            $service,
            [],
            ['ingestion_method' => 'local_collector'],
            ['ingestion_method' => 'manual']
        ));
        self::assertNull($originMethod->invoke(
            $service,
            ['ingestion_method' => 'cloud_bundle'],
            ['ingestion_method' => 'local_collector'],
            ['ingestion_method' => 'browser_profile']
        ));
    }

    public function testExporterExtractsOnlyCredentialFreeP0FieldFacts(): void
    {
        $service = new CloudOtaBundleExportService();
        $method = new ReflectionMethod($service, 'safeP0Evidence');
        $row = $this->p0SourceRow();
        $row['raw_data'] = json_encode($this->p0RawData(), JSON_THROW_ON_ERROR);

        $evidence = $method->invoke($service, $row, 'meituan');

        self::assertSame(
            ['source_url_hash', 'capture_source', 'field_facts'],
            array_keys($evidence)
        );
        self::assertSame(hash('sha256', 'meituan-p0-source'), $evidence['source_url_hash']);
        self::assertSame('xhr:traffic:traffic', $evidence['capture_source']);
        self::assertSame(
            ['detail_exposure', 'flow_rate', 'list_exposure'],
            array_column($evidence['field_facts'], 'metric_key')
        );
        self::assertStringNotContainsString('https://', json_encode($evidence, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('cookie', strtolower(json_encode($evidence, JSON_THROW_ON_ERROR)));
    }

    public function testExporterRejectsConflictingOrCredentialShapedP0FactEvidence(): void
    {
        $service = new CloudOtaBundleExportService();
        $method = new ReflectionMethod($service, 'safeP0Evidence');
        $row = $this->p0SourceRow();
        $raw = $this->p0RawData();
        $raw['field_facts'][0]['capture_evidence']['capture_source'] = 'fetch:traffic:traffic';
        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
        try {
            $method->invoke($service, $row, 'meituan');
            self::fail('A Meituan fact must not borrow a different network source.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertStringContainsString(
                'cloud_bundle_export_meituan_fact_capture_source_mismatch',
                $exception->getMessage()
            );
        }

        $raw = $this->p0RawData();
        $raw['field_facts'][0]['authorization'] = 'Bearer abcdefghijklmnop';
        $row['raw_data'] = json_encode($raw, JSON_THROW_ON_ERROR);
        try {
            $method->invoke($service, $row, 'meituan');
            self::fail('Credential-shaped fact evidence must fail closed.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::assertStringContainsString(
                'cloud_bundle_export_p0_credential_evidence_rejected',
                $exception->getMessage()
            );
        }
    }

    public function testPartialCollectionUsesExplicitPartialReceiptInsteadOfSuccessReceipt(): void
    {
        $source = (string)file_get_contents((new ReflectionMethod(
            CloudOtaBundleImportService::class,
            'processInboxFile'
        ))->getFileName());

        self::assertStringContainsString("!== 'success'", $source);
        self::assertStringContainsString("'.partial.json'", $source);
        self::assertStringContainsString("'status' => \$resultStatus === 'succeeded' ? 'success' : 'partial'", $source);
    }

    public function testExportCommandParsesExactSourceToSyncTaskBindings(): void
    {
        $method = new ReflectionMethod(new RunCloudDataBridge(), 'parseSyncTaskIds');
        self::assertSame([25 => 901, 68 => 902], $method->invoke(new RunCloudDataBridge(), '68:902,25:901'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unique positive source_id:sync_task_id pairs');
        $method->invoke(new RunCloudDataBridge(), '25:901,25:902');
    }

    public function testImportWrapperPreservesCredentialFreeP0OriginMetadata(): void
    {
        $sourceRow = $this->p0SourceRow();
        $bundle = CloudOtaBundleCodec::build([
            'source_system_hotel_id' => 64,
            'destination_system_hotel_id' => 1,
            'target_date' => '2026-07-21',
            'required_platforms' => ['meituan'],
        ], [[
            'platform' => 'meituan',
            'source_data_source_id' => 12,
            'destination_data_source_id' => 22,
            'collection' => [
                'status' => 'success',
                'message' => 'target_date_rows_readback_verified',
                'last_sync_time' => '2026-07-22 08:00:00',
            ],
            'rows' => [[
                'tenant_id' => 9,
                'system_hotel_id' => 64,
                'data_source_id' => 12,
                'hotel_id' => 'meituan-hotel-1',
                'data_date' => '2026-07-21',
                'source' => 'meituan',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'dimension' => 'traffic_overview',
                'compare_type' => 'self',
                'ingestion_method' => 'local_collector',
                'capture_source' => 'xhr:traffic:traffic',
                'source_url_hash' => $sourceRow['source_url_hash'],
                'field_facts' => $sourceRow['field_facts'],
                'list_exposure' => 120,
                'detail_exposure' => 40,
                'flow_rate' => 0.33,
                'validation_status' => 'normal',
                'source_trace_id' => 'meituan:trusted-source-trace',
                'readback_verified' => 1,
            ]],
        ]]);
        $package = $bundle['packages'][0];
        $sourceRow = $package['rows'][0];
        $columns = array_fill_keys([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id',
            'hotel_id', 'data_date', 'source', 'platform', 'data_type', 'dimension',
            'compare_type', 'ingestion_method', 'source_trace_id', 'raw_data',
            'list_exposure', 'detail_exposure', 'flow_rate', 'validation_status',
            'validation_flags', 'readback_verified', 'readback_verified_at',
        ], true);
        $method = new ReflectionMethod(new CloudOtaBundleImportService(), 'prepareDestinationRow');
        $destination = $method->invoke(
            new CloudOtaBundleImportService(),
            $bundle,
            $package,
            ['id' => 22],
            ['id' => 1, 'tenant_id' => 9],
            501,
            $sourceRow,
            0,
            hash('sha256', 'row-identity'),
            1,
            $columns
        );
        $wrapper = json_decode((string)$destination['raw_data'], true, 64, JSON_THROW_ON_ERROR);

        self::assertSame('cloud_bundle', $destination['ingestion_method']);
        self::assertSame('local_collector', $wrapper['row']['ingestion_method']);
        self::assertSame('xhr:traffic:traffic', $wrapper['row']['capture_source']);
        self::assertSame($sourceRow['source_url_hash'], $wrapper['row']['source_url_hash']);
        self::assertCount(3, $wrapper['row']['field_facts']);
        self::assertArrayNotHasKey('raw_data', $wrapper['row']);
        self::assertSame(
            [],
            array_values(array_intersect(
                ['cookie', 'token', 'password', 'secret', 'authorization'],
                array_map('strtolower', array_keys($wrapper['row']))
            ))
        );
    }

    public function testDestinationReadbackUsesFieldSpecificExactComparison(): void
    {
        $service = new CloudOtaBundleImportService();
        $matches = new ReflectionMethod($service, 'destinationRowMatches');

        self::assertTrue($matches->invoke($service, [
            'hotel_id' => '00123',
            'quantity' => '1',
            'amount' => '1280.50',
            'flow_rate' => '0.10',
            'raw_data' => '{"nested":{"ok":true},"count":1}',
        ], [
            'hotel_id' => '00123',
            'quantity' => 1,
            'amount' => 1280.5,
            'flow_rate' => 0.1,
            'raw_data' => '{"count":1,"nested":{"ok":true}}',
        ]));

        self::assertFalse($matches->invoke($service, ['hotel_id' => '123'], ['hotel_id' => '00123']));
        self::assertFalse($matches->invoke($service, ['hotel_id' => '1000'], ['hotel_id' => '1e3']));
        self::assertFalse($matches->invoke($service, ['quantity' => 2], ['quantity' => 1]));
        self::assertFalse($matches->invoke($service, ['amount' => '1280.51'], ['amount' => '1280.50']));
        self::assertFalse($matches->invoke($service, ['amount' => '1280.50009'], ['amount' => '1280.50']));
        self::assertFalse($matches->invoke($service, ['flow_rate' => '0.2'], ['flow_rate' => '0.1']));
        self::assertFalse($matches->invoke(
            $service,
            ['raw_data' => '{"count":"1","nested":{"ok":true}}'],
            ['raw_data' => '{"nested":{"ok":true},"count":1}']
        ));
        self::assertFalse($matches->invoke(
            $service,
            ['raw_data' => '{"items":{"0":"value"}}'],
            ['raw_data' => '{"items":["value"]}']
        ));
        self::assertFalse($matches->invoke(
            $service,
            ['raw_data' => '"scalar"'],
            ['raw_data' => '"scalar"']
        ));
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

    /** @return array<string, mixed> */
    private function p0SourceRow(): array
    {
        $traceId = 'meituan:trusted-source-trace';
        $sourceUrlHash = hash('sha256', 'meituan-p0-source');
        $values = [
            'list_exposure' => 120,
            'detail_exposure' => 40,
            'flow_rate' => 0.33,
        ];
        $sourceKeys = [
            'list_exposure' => 'listExposure',
            'detail_exposure' => 'detailExposure',
            'flow_rate' => 'flowRate',
        ];
        $facts = [];
        foreach ($sourceKeys as $metricKey => $sourceKey) {
            $facts[] = [
                'metric_key' => $metricKey,
                'source_key' => $sourceKey,
                'source_path' => '$.metrics.' . $sourceKey,
                'storage_field' => 'online_daily_data.' . $metricKey,
                'stored_value_present' => true,
                'status' => 'captured',
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                    'capture_source' => 'xhr:traffic:traffic',
                ],
            ];
        }
        return array_merge($values, [
            'source_trace_id' => $traceId,
            'source_url_hash' => $sourceUrlHash,
            'capture_source' => 'xhr:traffic:traffic',
            'field_facts' => $facts,
        ]);
    }

    /** @return array<string, mixed> */
    private function p0RawData(): array
    {
        $row = $this->p0SourceRow();
        $rawFacts = [];
        foreach ($row['field_facts'] as $fact) {
            $rawFacts[] = array_merge($fact, [
                'data_type' => 'traffic',
                'storage_table' => 'online_daily_data',
                'normalized_field' => $fact['metric_key'],
                'missing_state' => '',
                'value' => $row[$fact['metric_key']],
            ]);
        }
        return [
            'source_trace_id' => $row['source_trace_id'],
            'source_url_hash' => $row['source_url_hash'],
            'capture_evidence' => [
                'source_trace_id' => $row['source_trace_id'],
                'source_url_hash' => $row['source_url_hash'],
                'capture_source' => $row['capture_source'],
            ],
            'row' => ['_capture_source' => $row['capture_source']],
            'field_facts' => $rawFacts,
        ];
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
