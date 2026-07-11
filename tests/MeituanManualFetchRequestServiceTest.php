<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanManualFetchRequestService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MeituanManualFetchRequestServiceTest extends TestCase
{
    public function testMissingRankResourceFieldsStayExplicit(): void
    {
        self::assertSame(['Partner ID', 'POI ID'], MeituanManualFetchRequestService::missingRankResourceFields('', ''));
        self::assertSame(['POI ID'], MeituanManualFetchRequestService::missingRankResourceFields('partner-1', ''));
        self::assertSame([], MeituanManualFetchRequestService::missingRankResourceFields('partner-1', 'poi-1'));
    }

    public function testNormalizeDateRangeKeepsPlatformDateCompatibility(): void
    {
        self::assertSame(['2026-05-02', '2026-05-03'], MeituanManualFetchRequestService::normalizeDateRange('2026/5/2', '20260503'));
        self::assertSame(['2026-05-03', '2026-05-03'], MeituanManualFetchRequestService::normalizeDateRange('', '2026-05-03'));
    }

    public function testNormalizeDateRangeRejectsReverseRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MeituanManualFetchRequestService::normalizeDateRange('2026-05-04', '2026-05-03');
    }

    public function testBuildRankRequestParamsUsesExplicitDatesAndResourceIds(): void
    {
        $plan = MeituanManualFetchRequestService::buildRankRequestParams(
            'vpoi',
            'partner-1',
            'poi-1',
            'P_RZ',
            '7',
            '2026-05-02',
            '2026-05-03'
        );

        self::assertSame('2026-05-02', $plan['start_date']);
        self::assertSame('2026-05-03', $plan['end_date']);
        self::assertSame(1, $plan['date_range']);
        self::assertSame([
            'dataScope' => 'vpoi',
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
            'partnerId' => 'partner-1',
            'poiId' => 'poi-1',
            'rankType' => 'P_RZ',
            'startDate' => '20260502',
            'endDate' => '20260503',
            'dateRange' => 1,
        ], $plan['params']);
    }

    public function testBuildRankRequestParamsRejectsFutureExplicitDates(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持未来日期');

        MeituanManualFetchRequestService::buildRankRequestParams(
            'vpoi',
            'partner-1',
            'poi-1',
            'P_RZ',
            'custom',
            $tomorrow,
            $tomorrow
        );
    }

    public function testRelativeSevenDayRankRangeUsesSevenCalendarDays(): void
    {
        $plan = MeituanManualFetchRequestService::buildRankRequestParams(
            'vpoi',
            'partner-1',
            'poi-1',
            'P_RZ',
            '7',
            '',
            ''
        );

        self::assertSame(7, $plan['date_range']);
        self::assertSame(date('Y-m-d', strtotime('-6 days')), $plan['start_date']);
        self::assertSame(date('Y-m-d'), $plan['end_date']);
        self::assertSame(date('Ymd', strtotime('-6 days')), $plan['params']['startDate']);
        self::assertSame(date('Ymd'), $plan['params']['endDate']);
    }

    public function testBuildTrafficRequestParamsPreservesExtraParams(): void
    {
        $plan = MeituanManualFetchRequestService::buildTrafficRequestParams(
            ['custom' => 'value', 'dateRange' => 30],
            'partner-1',
            'poi-1',
            '2026-05-02',
            '2026-05-03'
        );

        self::assertSame('2026-05-02', $plan['start_date']);
        self::assertSame('2026-05-03', $plan['end_date']);
        self::assertSame('value', $plan['params']['custom']);
        self::assertSame('partner-1', $plan['params']['partnerId']);
        self::assertSame('poi-1', $plan['params']['poiId']);
        self::assertSame('20260502', $plan['params']['startDate']);
        self::assertSame('20260503', $plan['params']['endDate']);
        self::assertSame(1, $plan['params']['dateRange']);
    }
}
