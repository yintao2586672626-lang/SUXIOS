<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaScheduledAnalysisAuthorizationProvisioningService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CanonicalOtaScheduledAnalysisAuthorizationProvisioningServiceTest extends TestCase
{
    public function testPreviewIsZeroWriteAndBuildsBoundedMeituanGrant(): void
    {
        $status = ['enabled' => true, 'marker' => 'preserved'];
        $writes = 0;
        $service = $this->service($status, $writes);

        $result = $service->preview(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['execute']);
        self::assertTrue($result['would_write']);
        self::assertFalse($result['readback_verified']);
        self::assertSame('meituan', $result['platform']);
        self::assertSame(0, $writes);
        self::assertSame(['enabled' => true, 'marker' => 'preserved'], $status);
        self::assertFalse($result['external_action_triggered']);
    }

    public function testExecutePreservesStatusAndExactReadbackIsIdempotent(): void
    {
        $status = ['enabled' => true, 'marker' => 'preserved'];
        $writes = 0;
        $service = $this->service($status, $writes);

        $first = $service->execute(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');
        $second = $service->execute(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');

        self::assertSame('saved', $first['status']);
        self::assertTrue($first['readback_verified']);
        self::assertFalse($first['idempotent']);
        self::assertTrue($second['idempotent']);
        self::assertSame($first['authorization_digest'], $second['authorization_digest']);
        self::assertSame(1, $writes);
        self::assertSame('preserved', $status['marker']);
        self::assertSame(
            'meituan',
            $status['canonical_daily_analysis_authorizations']['meituan']['platform']
        );
    }

    public function testMeituanWriteDoesNotReplaceLegacyCtripGrant(): void
    {
        $legacy = ['opaque' => 'legacy-ctrip'];
        $status = [
            'enabled' => true,
            'canonical_daily_analysis_authorization' => $legacy,
        ];
        $writes = 0;
        $service = $this->service($status, $writes);

        $service->execute(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');

        self::assertSame($legacy, $status['canonical_daily_analysis_authorization']);
        self::assertArrayHasKey('meituan', $status['canonical_daily_analysis_authorizations']);
    }

    public function testDisabledStatusAndCrossTenantFailBeforeWrite(): void
    {
        $status = ['enabled' => false];
        $writes = 0;
        $service = $this->service($status, $writes);
        try {
            $service->execute(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');
            self::fail('Disabled status must block.');
        } catch (RuntimeException $exception) {
            self::assertSame('canonical_scheduled_analysis_status_not_enabled', $exception->getMessage());
        }
        self::assertSame(0, $writes);

        $status['enabled'] = true;
        $service = new CanonicalOtaScheduledAnalysisAuthorizationProvisioningService(
            static fn(int $hotelId): array => $status,
            static function (): bool {
                throw new RuntimeException('writer_must_not_run');
            },
            static fn(int $hotelId): int => 81,
            static fn(): string => '2026-08-09T15:00:00+08:00'
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_scheduled_analysis_hotel_tenant_mismatch');
        $service->execute(80, 80, 'meituan', 'hotel80_meituan_daily_goal_019fe32a_v1');
    }

    public function testExactLifecycleManagedAnalysisMarkerAuthorizesAnalysisOnlyWithoutLegacyFetchFlag(): void
    {
        $status = [
            'enabled' => false,
            'lifecycle_managed_analysis_enabled' => true,
            'lifecycle_managed_tenant_id' => 80,
            'lifecycle_managed_hotel_id' => 80,
            'lifecycle_external_action_allowed' => false,
        ];
        $writes = 0;
        $service = $this->service($status, $writes);

        $receipt = $service->execute(
            80,
            80,
            'ctrip',
            'hotel-lifecycle-80-80-aaaaaaaaaaaaaaaaaaaa'
        );

        self::assertSame('saved', $receipt['status']);
        self::assertTrue($receipt['readback_verified']);
        self::assertTrue($receipt['analysis_only']);
        self::assertFalse($receipt['external_action_allowed']);
        self::assertFalse($status['enabled']);
        self::assertSame(1, $writes);

        $status = [
            'enabled' => false,
            'lifecycle_managed_analysis_enabled' => true,
            'lifecycle_managed_tenant_id' => 81,
            'lifecycle_managed_hotel_id' => 80,
            'lifecycle_external_action_allowed' => false,
        ];
        $writes = 0;
        $service = $this->service($status, $writes);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical_scheduled_analysis_status_not_enabled');
        $service->execute(80, 80, 'ctrip', 'hotel-lifecycle-80-80-bbbbbbbbbbbbbbbbbbbb');
    }

    private function service(array &$status, int &$writes): CanonicalOtaScheduledAnalysisAuthorizationProvisioningService
    {
        return new CanonicalOtaScheduledAnalysisAuthorizationProvisioningService(
            static function (int $hotelId) use (&$status): array {
                return $status;
            },
            static function (int $hotelId, array $next, int $ttl) use (&$status, &$writes): bool {
                $status = $next;
                $writes++;
                return true;
            },
            static fn(int $hotelId): int => 80,
            static fn(): string => '2026-08-09T15:00:00+08:00'
        );
    }
}
