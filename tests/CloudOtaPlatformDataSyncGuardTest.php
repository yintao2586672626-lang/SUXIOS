<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudOtaPlatformDataSyncGuardTest extends TestCase
{
    public function testFinalSyncGuardAcceptsOnlyExactPlatformHotelId(): void
    {
        $service = new PlatformDataSyncService();
        $bindingGuard = new \ReflectionMethod($service, 'assertRequiredCollectorBinding');
        $sessionGuard = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $source = $this->source();
        $options = $this->options();

        $bindingGuard->invoke($service, $source, $options);
        $sessionGuard->invoke($service, $source, $options, $this->captureResult('hotel-80'));
        self::assertTrue(true);
    }

    public function testTamperedSourcePlatformHotelIdIsRejectedBeforeCapture(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCollectorBinding');
        $source = $this->source();
        $config = $source['config'];
        $config['platform_hotel_id'] = 'hotel-81';
        $source['config'] = $config;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside its explicit user/device/hotel/platform scope');
        $method->invoke($service, $source, $this->options());
    }

    public function testMissingOrMismatchedCurrentRunPlatformHotelIdIsRejectedBeforePersistence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        foreach (['', 'hotel-81'] as $validatedIdentifier) {
            try {
                $method->invoke(
                    $service,
                    $this->source(),
                    $this->options(),
                    $this->captureResult($validatedIdentifier)
                );
                self::fail('Expected current-run platform_hotel_id mismatch to be rejected.');
            } catch (\ReflectionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                self::assertStringContainsString(
                    'outside the bound platform hotel',
                    $e->getMessage()
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'id' => 25,
            'tenant_id' => 9,
            'user_id' => 1,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config' => [
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => 'cloud-owner-device',
                'collector_device_id_hash' => hash('sha256', 'cloud-owner-device'),
                'collector_user_id' => 1,
                'collector_tenant_id' => 9,
                'collector_hotel_id' => 80,
                'collector_platform' => 'ctrip',
                'collector_bound_at' => '2026-07-26 09:30:00',
                'platform_hotel_id' => 'hotel-80',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'require_collector_binding' => true,
            'require_current_run_session_probe' => true,
            'required_platform_hotel_id' => 'hotel-80',
            'required_collector_binding' => [
                'mode' => 'single_user_local',
                'tenant_id' => 9,
                'user_id' => 1,
                'device_id' => 'cloud-owner-device',
                'device_id_hash' => hash('sha256', 'cloud-owner-device'),
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'platform_hotel_id' => 'hotel-80',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function captureResult(string $validatedIdentifier): array
    {
        return [
            'status' => 'success',
            'payload' => [
                'network_freshness' => [
                    'status' => 'ready',
                    'http_cache_disabled' => true,
                    'service_worker_bypassed' => true,
                    'sensitive_values_exposed' => false,
                ],
                'auth_status' => [
                    'ok' => true,
                    'status' => 'logged_in',
                ],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => $validatedIdentifier,
                    'sensitive_values_exposed' => false,
                ],
            ],
        ];
    }
}
