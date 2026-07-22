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
}
