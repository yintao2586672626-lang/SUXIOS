<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PlatformDataSyncLocalCollectorP0Test extends TestCase
{
    public function testOrderedLocalCollectorTrafficUsesTheRealTargetDateP0Diagnostics(): void
    {
        $service = (new ReflectionClass(PlatformDataSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PlatformDataSyncService::class, 'syncRequiresTargetDateTrafficEvidence');

        self::assertTrue($method->invoke($service, [
            'platform' => 'ctrip',
            'ingestion_method' => 'local_collector',
            'data_type' => 'business',
        ], [
            'trigger_type' => 'local_collector_upload',
            'capture_sections' => 'business_overview,traffic_report',
        ], []));

        self::assertFalse($method->invoke($service, [
            'platform' => 'meituan',
            'ingestion_method' => 'local_collector',
            'data_type' => 'business',
        ], [
            'trigger_type' => 'local_collector_upload',
            'capture_sections' => 'orders',
        ], []));
    }

    public function testLocalCollectorCurrentSessionProofComesFromTheAccountOwnedSessionSummary(): void
    {
        $service = (new ReflectionClass(PlatformDataSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(
            PlatformDataSyncService::class,
            'browserProfileCurrentSessionProofMissingRequirements'
        );

        self::assertSame([], $method->invoke($service, [
            'platform' => 'ctrip',
            'ingestion_method' => 'local_collector',
            'config_json' => json_encode(['current_session_verified' => true], JSON_THROW_ON_ERROR),
        ]));
        self::assertSame(['current_session_verified'], $method->invoke($service, [
            'platform' => 'ctrip',
            'ingestion_method' => 'local_collector',
            'config_json' => json_encode(['current_session_verified' => false], JSON_THROW_ON_ERROR),
        ]));
    }

    public function testLocalCollectorBindingRequiresResponseDerivedIdentityInsteadOfARowEcho(): void
    {
        $service = (new ReflectionClass(PlatformDataSyncService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PlatformDataSyncService::class, 'assertGenericOtaPayloadBinding');
        $source = [
            'platform' => 'ctrip',
            'ingestion_method' => 'local_collector',
            'config_json' => json_encode(['platform_hotel_id' => 'CTRIP-101'], JSON_THROW_ON_ERROR),
        ];

        try {
            $method->invoke($service, $source, [
                'rows' => [['platform_hotel_id' => 'CTRIP-101']],
            ]);
            self::fail('A request-scoped row identifier must not replace platform identity proof.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $cause = $exception instanceof \ReflectionException
                ? $exception
                : ($exception->getPrevious() ?? $exception);
            self::assertSame('binding_unverified', $cause->getMessage());
            self::assertSame(422, $cause->getCode());
        }

        self::assertSame([
            'status' => 'matched',
            'proof' => 'platform_identity_validation',
        ], $method->invoke($service, $source, [
            'platform_identity_validation' => [
                'status' => 'matched',
                'source_validation' => true,
                'validated_identifier' => 'CTRIP-101',
            ],
            'rows' => [['platform_hotel_id' => 'CTRIP-101']],
        ]));
    }
}
