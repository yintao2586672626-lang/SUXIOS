<?php
declare(strict_types=1);

use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\TestCase;

final class RevenueOverviewDateContractTest extends TestCase
{
    public function testServerDateUsesAsiaShanghaiAtTheUtcBoundary(): void
    {
        $utcClock = new DateTimeImmutable('2026-08-25T16:30:00+00:00');

        self::assertSame('2026-08-26', RevenueOverviewDateContract::serverAsOfDate($utcClock));
    }

    public function testOnlyTheExactCurrentServerDateAndVersionAreCurrent(): void
    {
        $current = RevenueOverviewDateContract::serverAsOfDate();
        $clock = new DateTimeImmutable($current, new DateTimeZone('Asia/Shanghai'));
        $stale = $clock->modify('-1 day')->format('Y-m-d');
        $future = $clock->modify('+1 day')->format('Y-m-d');

        self::assertTrue(RevenueOverviewDateContract::isCurrentAsOfDate(
            $current,
            RevenueOverviewDateContract::VERSION
        ));
        self::assertFalse(RevenueOverviewDateContract::isCurrentAsOfDate(
            $stale,
            RevenueOverviewDateContract::VERSION
        ));
        self::assertFalse(RevenueOverviewDateContract::isCurrentAsOfDate(
            $future,
            RevenueOverviewDateContract::VERSION
        ));
        self::assertFalse(RevenueOverviewDateContract::isCurrentAsOfDate($current, 'client_forged.v0'));
        self::assertFalse(RevenueOverviewDateContract::isCurrentAsOfDate('2026-02-31', RevenueOverviewDateContract::VERSION));
    }
}
