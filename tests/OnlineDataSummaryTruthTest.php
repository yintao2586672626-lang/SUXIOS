<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataSummaryConcern;
use PHPUnit\Framework\TestCase;

final class OnlineDataSummaryTruthTest extends TestCase
{
    private OnlineDataSummaryConcernHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new OnlineDataSummaryConcernHarness();
    }

    public function testMissingAdvertisingMetricsRemainNullInsteadOfBecomingZero(): void
    {
        $summary = $this->harness->advertising([
            ['data_type' => 'advertising', 'truth' => $this->truth('verified')],
        ]);

        self::assertNull($summary['spend']);
        self::assertNull($summary['order_amount']);
        self::assertNull($summary['bookings']);
        self::assertSame(0, $summary['sample_count']);
        self::assertSame('pending', $summary['data_status']);
    }

    public function testCapturedZeroAdvertisingMetricsRemainRealZeroValues(): void
    {
        $summary = $this->harness->advertising([
            [
                'data_type' => 'advertising',
                'amount' => 0,
                'order_amount' => 0,
                'book_order_num' => 0,
                'truth' => $this->truth('verified'),
            ],
        ]);

        self::assertSame(0.0, $summary['spend']);
        self::assertSame(0.0, $summary['order_amount']);
        self::assertSame(0, $summary['bookings']);
        self::assertSame(1, $summary['sample_count']);
        self::assertNull($summary['roas']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testUnverifiedAdvertisingRowsAreLabeledButExcludedFromNumbers(): void
    {
        $summary = $this->harness->advertising([
            [
                'data_type' => 'advertising',
                'amount' => 199.5,
                'truth' => $this->truth('unverified', '人工导入来源尚未核验'),
            ],
        ]);

        self::assertNull($summary['spend']);
        self::assertSame(0, $summary['sample_count']);
        self::assertSame('unverified', $summary['truth_context']['status']);
        self::assertStringContainsString('人工导入来源尚未核验', $summary['truth_context']['failure_reason']);
    }

    public function testServiceMetricKeepsMissingPeerMetricNull(): void
    {
        $summary = $this->harness->serviceQuality([
            [
                'data_type' => 'quality',
                'data_value' => 88.6,
                'truth' => $this->truth('partial', '服务分字段缺失'),
            ],
        ]);

        self::assertSame(88.6, $summary['avg_psi_score']);
        self::assertNull($summary['avg_service_score']);
        self::assertSame('partial', $summary['data_status']);
        self::assertSame('partial', $summary['truth_context']['status']);
    }

    public function testDailyAndTotalOperatingNumbersCarryTruthContext(): void
    {
        $summary = $this->harness->operating([
            [
                'data_type' => 'business',
                'data_date' => '2026-07-18',
                'system_hotel_id' => 7,
                'source' => 'ctrip',
                'amount' => 1200.5,
                'quantity' => null,
                'book_order_num' => 8,
                'truth' => $this->truth('verified'),
            ],
        ]);

        self::assertSame(1200.5, $summary['daily'][0]['total_amount']);
        self::assertNull($summary['daily'][0]['total_quantity']);
        self::assertSame('verified', $summary['daily'][0]['truth_context']['status']);
        self::assertSame('verified', $summary['total']['truth_context']['status']);
        self::assertSame('ota_channel', $summary['total']['scope']);
    }

    /** @return array<string, mixed> */
    private function truth(string $status, string $reason = ''): array
    {
        $labels = [
            'verified' => '已验证',
            'partial' => '部分数据',
            'unverified' => '未验证',
            'collection_failed' => '采集失败',
        ];

        return [
            'status' => $status,
            'status_label' => $labels[$status],
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA渠道数据，不代表全酒店经营',
            'hotel' => ['system_hotel_id' => 7, 'name' => '测试酒店'],
            'platform' => 'ctrip',
            'data_date' => '2026-07-18',
            'source' => ['method' => 'browser_profile'],
            'collected_at' => '2026-07-19 08:30:00',
            'persistence' => ['stored' => true, 'readback_verified' => true],
            'failure_reason' => $reason,
        ];
    }
}

final class OnlineDataSummaryConcernHarness
{
    use OnlineDataSummaryConcern;

    /** @param array<int, array<string, mixed>> $rows */
    public function advertising(array $rows): array
    {
        return $this->buildDailyOtaAdvertisingSummary($rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function serviceQuality(array $rows): array
    {
        return $this->buildDailyOtaServiceQualitySummary($rows);
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function operating(array $rows): array
    {
        return $this->buildDailyOperatingSummary($rows);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, mixed>} */
    private function decodeOnlineDataQualityRaw(mixed $value): array
    {
        if (is_array($value)) {
            return [$value, []];
        }
        if (!is_string($value) || trim($value) === '') {
            return [[], []];
        }
        $decoded = json_decode($value, true);
        return [is_array($decoded) ? $decoded : [], []];
    }

    private function onlineDataQualityNumber(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
