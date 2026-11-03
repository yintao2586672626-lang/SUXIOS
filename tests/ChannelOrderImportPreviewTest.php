<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\PlatformDataSourceConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ChannelOrderImportPreviewTest extends TestCase
{
    public function testMissingCancelledCountDoesNotBecomeZeroRate(): void
    {
        $channel = self::fixturePreview([['gross_order_num' => 10]])['channels'][0];

        self::assertNull($channel['cancel_rate']);
        self::assertSame('evidence_missing', $channel['cancel_rate_status']);
        self::assertSame(1, $channel['cancel_rate_missing_rows']);
        self::assertSame(10.0, $channel['gross_orders']);
        self::assertNull($channel['cancelled_orders']);
        self::assertSame(1, $channel['row_count']);
    }

    public function testUnpairedRowsKeepKnownCountsWithoutProducingWholeScopeRate(): void
    {
        $channel = self::fixturePreview([
            ['gross_order_num' => 10, 'cancel_order_num' => 2],
            ['gross_order_num' => 6],
            ['cancel_order_num' => 3],
        ])['channels'][0];

        self::assertNull($channel['cancel_rate']);
        self::assertSame('evidence_missing', $channel['cancel_rate_status']);
        self::assertSame(2, $channel['cancel_rate_missing_rows']);
        self::assertSame(16.0, $channel['gross_orders']);
        self::assertSame(5.0, $channel['cancelled_orders']);
        self::assertSame(3, $channel['row_count']);
    }

    public function testExplicitNullAndBlankCountsRemainMissing(): void
    {
        foreach ([null, '', ' ', 'unknown'] as $cancelled) {
            $channel = self::fixturePreview([
                ['gross_order_num' => 10, 'cancel_order_num' => $cancelled],
            ])['channels'][0];

            self::assertNull($channel['cancel_rate']);
            self::assertSame('evidence_missing', $channel['cancel_rate_status']);
            self::assertSame(1, $channel['cancel_rate_missing_rows']);
        }
    }

    public function testCompleteZeroCancelledCountIsAnAvailableZeroRate(): void
    {
        $channel = self::fixturePreview([
            ['gross_order_num' => 10, 'cancel_order_num' => 0],
            ['gross_order_num' => 5, 'cancel_order_num' => '0'],
        ])['channels'][0];

        self::assertSame(0.0, $channel['cancel_rate']);
        self::assertSame('available', $channel['cancel_rate_status']);
        self::assertSame(0, $channel['cancel_rate_missing_rows']);
        self::assertSame(15.0, $channel['gross_orders']);
        self::assertSame(0.0, $channel['cancelled_orders']);
    }

    public function testCompleteRowsUsePairedAggregateCounts(): void
    {
        $channel = self::fixturePreview([
            ['gross_order_num' => 10, 'cancel_order_num' => 2],
            ['gross_order_num' => 30, 'cancel_order_num' => 10],
        ])['channels'][0];

        self::assertSame(0.3, $channel['cancel_rate']);
        self::assertSame('available', $channel['cancel_rate_status']);
        self::assertSame(0, $channel['cancel_rate_missing_rows']);
        self::assertSame(40.0, $channel['gross_orders']);
        self::assertSame(12.0, $channel['cancelled_orders']);
        self::assertSame(2, $channel['row_count']);
    }

    public function testCompleteZeroDenominatorIsNotComputable(): void
    {
        $channel = self::fixturePreview([
            ['gross_order_num' => 0, 'cancel_order_num' => 0],
        ])['channels'][0];

        self::assertNull($channel['cancel_rate']);
        self::assertSame('not_computable', $channel['cancel_rate_status']);
        self::assertSame(0, $channel['cancel_rate_missing_rows']);
        self::assertSame(0.0, $channel['gross_orders']);
        self::assertSame(0.0, $channel['cancelled_orders']);
    }

    public function testIncompleteZeroDenominatorStillReportsMissingEvidence(): void
    {
        $channel = self::fixturePreview([['gross_order_num' => 0]])['channels'][0];

        self::assertNull($channel['cancel_rate']);
        self::assertSame('evidence_missing', $channel['cancel_rate_status']);
        self::assertSame(1, $channel['cancel_rate_missing_rows']);
    }

    public function testInvalidCountPairsCannotProduceRates(): void
    {
        foreach ([
            [-1, 0],
            [10, -1],
            [10, 11],
            [10.5, 1],
            [10, 1.5],
            [INF, 1],
            [10, NAN],
        ] as [$gross, $cancelled]) {
            $channel = self::fixturePreview([
                ['gross_order_num' => $gross, 'cancel_order_num' => $cancelled],
            ])['channels'][0];

            self::assertNull($channel['cancel_rate']);
            self::assertSame('evidence_missing', $channel['cancel_rate_status']);
            self::assertSame(1, $channel['cancel_rate_missing_rows']);
        }
    }

    public function testChannelGapsDoNotHideCompleteOtherChannels(): void
    {
        $preview = self::fixturePreview([
            ['source' => 'ctrip', 'gross_order_num' => 10],
            ['source' => 'qunar', 'gross_order_num' => 20, 'cancel_order_num' => 5],
        ]);
        $channels = array_column($preview['channels'], null, 'key');

        self::assertNull($channels['ctrip']['cancel_rate']);
        self::assertSame('evidence_missing', $channels['ctrip']['cancel_rate_status']);
        self::assertSame(1, $channels['ctrip']['cancel_rate_missing_rows']);
        self::assertSame(0.25, $channels['qunar']['cancel_rate']);
        self::assertSame('available', $channels['qunar']['cancel_rate_status']);
        self::assertSame(0, $channels['qunar']['cancel_rate_missing_rows']);
    }

    public function testLegacyAliasesRemainCompatibleAndBookOrdersAreNotAGrossDenominator(): void
    {
        $preview = self::fixturePreview([
            ['渠道' => '去哪儿', '订单数' => 16, '总订单数' => '20', '取消订单数' => '4'],
            ['source' => 'ctrip', 'book_order_num' => 7],
        ]);
        $channels = array_column($preview['channels'], null, 'key');

        self::assertSame(0.2, $channels['去哪儿']['cancel_rate']);
        self::assertSame('available', $channels['去哪儿']['cancel_rate_status']);
        self::assertSame(0, $channels['去哪儿']['cancel_rate_missing_rows']);
        self::assertSame(16.0, $channels['去哪儿']['orders']);
        self::assertNull($channels['ctrip']['cancel_rate']);
        self::assertSame('evidence_missing', $channels['ctrip']['cancel_rate_status']);
        self::assertSame(7.0, $channels['ctrip']['orders']);
        self::assertNull($channels['ctrip']['gross_orders']);
        self::assertSame(64, $preview['system_hotel_id']);
        self::assertSame('ota_channel', $preview['metric_scope']);
        self::assertSame('user_provided_unverified', $preview['quality_status']);
        self::assertSame('test_fixture_only', $preview['real_file_acceptance']);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, mixed> */
    public static function fixturePreview(array $rows): array
    {
        $owner = new class {
            use PlatformDataSourceConcern;
        };
        $method = new ReflectionMethod($owner, 'buildChannelOrderImportPreview');
        $rows = array_map(static fn(array $row): array => $row + [
            'source' => array_key_exists('渠道', $row) ? $row['渠道'] : 'ctrip',
            'data_date' => '2026-09-30',
            'raw_data' => ['fixture_status' => 'explicit_test_fixture'],
        ], $rows);

        return $method->invoke($owner, $rows, [
            'system_hotel_id' => 64,
            'hotel_name' => 'test_fixture / 酒店64',
            'fixture_status' => 'explicit_test_fixture',
            'import_file_count' => 1,
        ]);
    }
}
