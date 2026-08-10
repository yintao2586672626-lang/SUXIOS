<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudOtaBundleCodec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudOtaBundleCodecTest extends TestCase
{
    public function testValidBundleKeepsMissingPlatformExplicitWithoutInventingRows(): void
    {
        $bundle = CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]),
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ], '2026-07-22 09:00:00');

        self::assertSame('suxios.cloud_ota_bundle.v1', $bundle['contract_version']);
        self::assertSame('ota_channel', $bundle['metric_scope']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bundle['bundle_id']);
        self::assertSame(1, $bundle['packages'][0]['row_count']);
        self::assertSame(0, $bundle['packages'][1]['row_count']);
        self::assertSame('target_date_missing', $bundle['packages'][1]['collection']['status']);
        self::assertArrayNotHasKey('amount', $bundle['packages'][1]);
    }

    public function testPayloadTamperingIsRejected(): void
    {
        $bundle = $this->validBundle();
        $bundle['packages'][0]['rows'][0]['amount'] = 9999.0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_payload_sha256_mismatch');
        CloudOtaBundleCodec::verify($bundle);
    }

    public function testWrongTargetDateIsRejectedBeforeImport(): void
    {
        $package = $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]);
        $package['rows'][0]['data_date'] = '2026-07-20';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_target_date_mismatch');
        CloudOtaBundleCodec::build($this->context(), [
            $package,
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);
    }

    public function testUnverifiedRowIsRejectedInsteadOfBecomingZero(): void
    {
        $row = $this->row('ctrip', 11);
        $row['readback_verified'] = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_row_readback_unverified');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [$row]),
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);
    }

    public function testCredentialLikeValueIsRejected(): void
    {
        $row = $this->row('ctrip', 11);
        $row['validation_flags'] = '{"authorization":"Bearer abcdefghijklmnop"}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_sensitive_value_rejected');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [$row]),
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);
    }

    public function testRequiredPlatformNeedsAnExplicitPackageAndBinding(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_required_platform_package_missing:meituan');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]),
        ]);
    }

    public function testCompleteSnapshotMetadataMustMatchExportedRows(): void
    {
        $package = $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]);
        $package['snapshot_complete'] = true;
        $package['source_row_count'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_complete_snapshot_row_count_mismatch');
        CloudOtaBundleCodec::build($this->context(), [
            $package,
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);
    }

    public function testLegacyPackageWithoutSnapshotMetadataRemainsVerifiableButIncomplete(): void
    {
        $bundle = $this->validBundle();
        $verified = CloudOtaBundleCodec::verify($bundle);

        self::assertArrayNotHasKey('snapshot_complete', $verified['packages'][0]);
        self::assertArrayNotHasKey('source_row_count', $verified['packages'][0]);
    }

    public function testSourceSyncTaskIdentitySurvivesBundleNormalization(): void
    {
        $ctrip = $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]);
        $ctrip['source_sync_task_id'] = 901;
        $bundle = CloudOtaBundleCodec::build($this->context(), [
            $ctrip,
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);

        self::assertSame(901, $bundle['packages'][0]['source_sync_task_id']);
    }

    public function testBindingContractHasNoHotelNameFallback(): void
    {
        $binding = CloudOtaBundleCodec::verifyBinding([
            'contract_version' => CloudOtaBundleCodec::BINDING_VERSION,
            'source_system_hotel_id' => 64,
            'destination_system_hotel_id' => 1,
            'bindings' => [
                ['platform' => 'ctrip', 'source_data_source_id' => 11, 'destination_data_source_id' => 21],
                ['platform' => 'meituan', 'source_data_source_id' => 12, 'destination_data_source_id' => 22],
            ],
        ]);

        self::assertSame(64, $binding['source_system_hotel_id']);
        self::assertSame(1, $binding['destination_system_hotel_id']);
        self::assertArrayNotHasKey('hotel_name', $binding);
    }

    public function testMeituanTrafficKeepsOnlyAllowlistedNetworkProvenance(): void
    {
        $bundle = CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$this->trafficRow('meituan', 12)]),
        ]);

        $row = $bundle['packages'][1]['rows'][0];
        self::assertSame('local_collector', $row['ingestion_method']);
        self::assertSame('xhr:traffic:traffic', $row['capture_source']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['source_url_hash']);
        self::assertSame(
            ['detail_exposure', 'flow_rate', 'list_exposure'],
            array_column($row['field_facts'], 'metric_key')
        );
        self::assertArrayNotHasKey('raw_data', $row);
        self::assertSame(
            [],
            array_values(array_intersect(['cookie', 'token', 'password', 'secret'], array_keys($row)))
        );
    }

    public function testMeituanTrafficRejectsDomCaptureSource(): void
    {
        $row = $this->trafficRow('meituan', 12);
        $row['capture_source'] = 'dom:traffic:flow_funnel';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_row_capture_source_invalid');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testMeituanTrafficRejectsMissingCaptureSource(): void
    {
        $row = $this->trafficRow('meituan', 12);
        unset($row['capture_source']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_meituan_p0_capture_source_missing');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testTrafficRejectsNonProfileOriginIngestionMethod(): void
    {
        $row = $this->trafficRow('meituan', 12);
        $row['ingestion_method'] = 'manual';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_row_ingestion_method_invalid');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testTrafficRejectsMissingSourceUrlHash(): void
    {
        $row = $this->trafficRow('meituan', 12);
        unset($row['source_url_hash']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_p0_source_url_hash_missing');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testMeituanFactSourceMustMatchTheRowNetworkSource(): void
    {
        $row = $this->trafficRow('meituan', 12);
        $row['field_facts'][0]['capture_evidence']['capture_source'] = 'fetch:traffic:traffic';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_meituan_field_fact_capture_source_mismatch');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testFieldFactUnknownAndCredentialShapedFieldsFailClosed(): void
    {
        $row = $this->trafficRow('meituan', 12);
        $row['field_facts'][0]['header'] = 'authorization';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_field_fact_field_not_allowed:header');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    public function testRawDataFieldRemainsOutsideTheTransportContract(): void
    {
        $row = $this->trafficRow('meituan', 12);
        $row['raw_data'] = ['cookie' => 'must-not-leave-source'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cloud_bundle_row_field_not_allowed:raw_data');
        CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [], 'target_date_missing'),
            $this->package('meituan', 12, 22, [$row]),
        ]);
    }

    /** @return array<string, mixed> */
    private function validBundle(): array
    {
        return CloudOtaBundleCodec::build($this->context(), [
            $this->package('ctrip', 11, 21, [$this->row('ctrip', 11)]),
            $this->package('meituan', 12, 22, [], 'target_date_missing'),
        ]);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'source_system_hotel_id' => 64,
            'destination_system_hotel_id' => 1,
            'target_date' => '2026-07-21',
            'required_platforms' => ['ctrip', 'meituan'],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    private function package(
        string $platform,
        int $sourceId,
        int $destinationId,
        array $rows,
        string $status = 'success'
    ): array {
        return [
            'platform' => $platform,
            'source_data_source_id' => $sourceId,
            'destination_data_source_id' => $destinationId,
            'collection' => [
                'status' => $status,
                'message' => $status === 'success' ? 'target_date_rows_readback_verified' : 'target_date_rows_missing',
                'last_sync_time' => '2026-07-22 08:00:00',
            ],
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function row(string $platform, int $sourceId): array
    {
        return [
            'tenant_id' => 9,
            'system_hotel_id' => 64,
            'data_source_id' => $sourceId,
            'hotel_id' => $platform === 'ctrip' ? '123456' : '654321',
            'hotel_name' => '试点酒店',
            'data_date' => '2026-07-21',
            'source' => $platform,
            'platform' => $platform,
            'data_type' => 'business',
            'amount' => 1200.5,
            'quantity' => 12,
            'book_order_num' => 8,
            'validation_status' => 'normal',
            'validation_flags' => '[]',
            'source_trace_id' => $platform . ':trusted-source-trace',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-07-22 08:01:00',
        ];
    }

    /** @return array<string, mixed> */
    private function trafficRow(string $platform, int $sourceId): array
    {
        $traceId = $platform . ':trusted-source-trace';
        $sourceUrlHash = hash('sha256', $platform . ':traffic-source');
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
        return array_merge($this->row($platform, $sourceId), [
            'data_type' => 'traffic',
            'dimension' => 'traffic_overview',
            'compare_type' => 'self',
            'ingestion_method' => 'local_collector',
            'capture_source' => 'xhr:traffic:traffic',
            'source_url_hash' => $sourceUrlHash,
            'field_facts' => $facts,
            'list_exposure' => 120,
            'detail_exposure' => 40,
            'flow_rate' => 0.33,
        ]);
    }
}
