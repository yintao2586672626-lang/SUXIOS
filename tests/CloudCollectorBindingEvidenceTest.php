<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudCollectorBindingEvidenceTest extends TestCase
{
    public function testCompleteBindingBecomesNonSensitivePersistenceEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'collectorBindingEvidence');

        $evidence = $method->invoke($service, $this->source());

        self::assertSame('single_user_local', $evidence['mode']);
        self::assertSame(80, $evidence['tenant_id']);
        self::assertSame(1, $evidence['user_id']);
        self::assertSame('server-owner-device', $evidence['device_id']);
        self::assertSame(hash('sha256', 'server-owner-device'), $evidence['device_id_hash']);
        self::assertSame(80, $evidence['hotel_id']);
        self::assertSame('ctrip', $evidence['platform']);
        self::assertSame('ctrip-h80', $evidence['platform_hotel_id']);
        self::assertSame('2026-07-25 22:30:00', $evidence['bound_at']);
        self::assertFalse($evidence['sensitive_values_exposed']);
    }

    public function testSyncProcessRevalidatesTheExactRequiredBinding(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCollectorBinding');
        $options = [
            'require_current_session_probe' => true,
            'required_collector_binding' => [
                'mode' => 'single_user_local',
                'tenant_id' => 80,
                'user_id' => 1,
                'device_id' => 'server-owner-device',
                'device_id_hash' => hash('sha256', 'server-owner-device'),
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'platform_hotel_id' => 'ctrip-h80',
            ],
        ];

        $method->invoke($service, $this->source(), $options);
        self::assertTrue(true);

        $options['required_collector_binding']['device_id'] = 'another-device';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside its explicit user/device/hotel/platform scope');
        $method->invoke($service, $this->source(), $options);
    }

    public function testIncompleteOrTamperedBindingProducesNoEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'collectorBindingEvidence');
        $source = $this->source();
        $source['config']['collector_device_id_hash'] = hash('sha256', 'another-device');

        self::assertSame([], $method->invoke($service, $source));
    }

    public function testValidatedBindingEvidenceIsAttachedToRawAndNormalizedPersistencePayloads(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/PlatformDataSyncService.php'
        );

        self::assertStringContainsString(
            "\$payload['_collector_binding_evidence'] = \$collectorBindingEvidence",
            $source
        );
        self::assertStringContainsString(
            "\$raw['collector_binding'] = \$collectorBindingEvidence",
            $source
        );
    }

    public function testGenericSourceUpdateCannotForgeACompleteCollectorBinding(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'allowlistedOtaSourceConfig');
        $safe = $method->invoke($service, $this->source()['config'], 'ctrip');

        self::assertArrayNotHasKey('collector_binding_mode', $safe);
        self::assertArrayNotHasKey('collector_device_id', $safe);
        self::assertArrayNotHasKey('collector_user_id', $safe);
        self::assertArrayNotHasKey('collector_tenant_id', $safe);
        self::assertArrayNotHasKey('collector_hotel_id', $safe);
        self::assertArrayNotHasKey('collector_platform', $safe);
        self::assertArrayNotHasKey('collector_bound_at', $safe);
    }

    public function testNormalProfileEditPreservesOnlyAnExistingCompleteManagedBinding(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'managedCloudCollectorBindingConfig');
        $config = $this->source()['config'];
        $config['stable_profile_id'] = 'profile-80';

        $managed = $method->invoke($service, $config);

        self::assertSame('server-owner-device', $managed['collector_device_id']);
        self::assertSame(1, $managed['collector_user_id']);
        self::assertArrayNotHasKey('stable_profile_id', $managed);

        unset($config['collector_bound_at']);
        self::assertSame([], $method->invoke($service, $config));
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'id' => 25,
            'tenant_id' => 80,
            'user_id' => 1,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'config' => [
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => 'server-owner-device',
                'collector_device_id_hash' => hash('sha256', 'server-owner-device'),
                'collector_user_id' => 1,
                'collector_tenant_id' => 80,
                'collector_hotel_id' => 80,
                'collector_platform' => 'ctrip',
                'collector_bound_at' => '2026-07-25 22:30:00',
                'platform_hotel_id' => 'ctrip-h80',
            ],
        ];
    }
}
