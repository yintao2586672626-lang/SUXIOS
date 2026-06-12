<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripManualFetchRequestService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CtripManualFetchRequestServiceTest extends TestCase
{
    public function testNormalizeBusinessReportUrlAndNodeIdUseExplicitValues(): void
    {
        self::assertSame('https://example.test/report', CtripManualFetchRequestService::normalizeBusinessReportUrl(' https://example.test/report '));
        self::assertSame('node-1', CtripManualFetchRequestService::normalizeNodeId(' node-1 '));
    }

    public function testNormalizeBusinessReportUrlAndNodeIdUseProjectDefaults(): void
    {
        self::assertSame(
            'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
            CtripManualFetchRequestService::normalizeBusinessReportUrl('')
        );
        self::assertSame('24588', CtripManualFetchRequestService::normalizeNodeId(''));
    }

    public function testNormalizeDateRangeKeepsExplicitRange(): void
    {
        $plan = CtripManualFetchRequestService::normalizeDateRange('2026-05-02', '2026-05-03');

        self::assertSame('2026-05-02', $plan['start_date']);
        self::assertSame('2026-05-03', $plan['end_date']);
        self::assertSame(strtotime('2026-05-02'), $plan['start_timestamp']);
        self::assertSame(strtotime('2026-05-03'), $plan['end_timestamp']);
    }

    public function testNormalizeDateRangeDefaultsMissingRangeToYesterday(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $plan = CtripManualFetchRequestService::normalizeDateRange('', '2026-05-03');

        self::assertSame($yesterday, $plan['start_date']);
        self::assertSame($yesterday, $plan['end_date']);
    }

    public function testNormalizeDateRangeRejectsReverseRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CtripManualFetchRequestService::normalizeDateRange('2026-05-04', '2026-05-03');
    }

    public function testBuildDailyPostDataUsesSingleDateRange(): void
    {
        self::assertSame([
            'nodeId' => '24588',
            'startDate' => '2026-05-02',
            'endDate' => '2026-05-02',
        ], CtripManualFetchRequestService::buildDailyPostData('24588', '2026-05-02'));
    }

    public function testRepeatedMultiDayFingerprintOnlyBlocksMultiDayDuplicateData(): void
    {
        $sameFingerprintRows = [
            ['fingerprint' => 'same'],
            ['fingerprint' => 'same'],
        ];
        $differentFingerprintRows = [
            ['fingerprint' => 'first'],
            ['fingerprint' => 'second'],
        ];

        self::assertTrue(CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint('2026-05-02', '2026-05-03', $sameFingerprintRows));
        self::assertFalse(CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint('2026-05-02', '2026-05-02', $sameFingerprintRows));
        self::assertFalse(CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint('2026-05-02', '2026-05-03', $differentFingerprintRows));
    }
}
