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

    public function testNormalizeDateRangeUsesLastSettledBusinessDateAroundCutoff(): void
    {
        $beforeCutoff = CtripManualFetchRequestService::normalizeDateRange(
            '',
            '2026-05-03',
            new \DateTimeImmutable('2026-08-31 03:20:13', new \DateTimeZone('Asia/Shanghai'))
        );
        $afterCutoff = CtripManualFetchRequestService::normalizeDateRange(
            '',
            '',
            new \DateTimeImmutable('2026-08-31 08:00:00', new \DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('2026-08-29', $beforeCutoff['start_date']);
        self::assertSame('2026-08-29', $beforeCutoff['end_date']);
        self::assertSame('2026-08-30', $afterCutoff['start_date']);
        self::assertSame('2026-08-30', $afterCutoff['end_date']);
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

    public function testResponseBusinessDateMustUniquelyMatchRequestedDate(): void
    {
        self::assertSame(
            ['2026-08-28', '2026-08-29'],
            CtripManualFetchRequestService::extractResponseDates([
                'dataDate' => '20260829',
                'data' => ['statDate' => '2026-08-28 12:00:00'],
                'invalid' => ['reportDate' => ['2026-08-27']],
            ])
        );
        self::assertSame([], CtripManualFetchRequestService::extractResponseDates([
            'hotel' => ['date' => '2026-08-29'],
            'unrelated' => ['statDate' => '2026-08-29'],
        ]));
        $verified = CtripManualFetchRequestService::verifyResponseBusinessDate(
            '2026-08-29',
            ['20260829', '2026-08-29 12:00:00']
        );
        $missing = CtripManualFetchRequestService::verifyResponseBusinessDate('2026-08-29', []);
        $mismatch = CtripManualFetchRequestService::verifyResponseBusinessDate('2026-08-30', ['2026-08-29']);
        $ambiguous = CtripManualFetchRequestService::verifyResponseBusinessDate(
            '2026-08-29',
            ['2026-08-28', '2026-08-29']
        );

        self::assertTrue($verified['verified']);
        self::assertSame('verified', $verified['status']);
        self::assertSame('2026-08-29', $verified['source_business_date']);
        self::assertFalse($missing['verified']);
        self::assertSame('response_business_date_missing', $missing['reason']);
        self::assertFalse($mismatch['verified']);
        self::assertSame('target_date_mismatch', $mismatch['status']);
        self::assertSame('2026-08-29', $mismatch['source_business_date']);
        self::assertFalse($ambiguous['verified']);
        self::assertSame('response_business_date_ambiguous', $ambiguous['reason']);
    }
}
