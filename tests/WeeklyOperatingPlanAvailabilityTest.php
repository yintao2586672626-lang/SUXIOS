<?php
declare(strict_types=1);

namespace Tests;

use app\service\WeeklyOperatingPlanSnapshotService;
use PHPUnit\Framework\TestCase;

final class WeeklyOperatingPlanAvailabilityTest extends TestCase
{
    public function testMissingLatestPlanHasAnExplicitScopedAvailabilityState(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService(
            snapshotReader: static fn() => null,
            scopeVerifier: static fn() => true
        );
        $result = $service->readLatestAvailability(121, 121, '2026-08-30');
        self::assertSame('not_generated', $result['status']);
        self::assertSame(WeeklyOperatingPlanSnapshotService::CONTRACT_VERSION, $result['contract_version']);
        self::assertSame(121, $result['hotel_id']);
        self::assertSame('2026-08-24', $result['week_start']);
        self::assertSame('2026-08-30', $result['week_end']);
        self::assertFalse($result['readback_verified']);
        self::assertArrayNotHasKey('selected_focus', $result);
    }

    public function testStorageFailureIsNotAnEmptyPlan(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService(
            snapshotReader: static fn() => throw new \RuntimeException('storage_unavailable', 503),
            scopeVerifier: static fn() => true
        );
        $this->expectExceptionMessage('storage_unavailable');
        $service->readLatestAvailability(121, 121, '2026-08-30');
    }

    public function testUnauthorizedHotelIsNotAnEmptyPlan(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService(
            snapshotReader: static fn() => null,
            scopeVerifier: static fn() => false
        );
        $this->expectExceptionMessage('weekly_plan_hotel_scope_unavailable');
        $service->readLatestAvailability(121, 122, '2026-08-30');
    }

    public function testMissingExactSnapshotRemainsAnError(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService(
            snapshotReader: static fn() => null,
            scopeVerifier: static fn() => true
        );
        $this->expectExceptionMessage('weekly_plan_snapshot_not_found');
        $this->expectExceptionCode(404);
        $service->readExact(121, 121, 100);
    }
}
