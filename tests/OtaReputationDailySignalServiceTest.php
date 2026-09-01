<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaReputationDailySignalService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OtaReputationDailySignalServiceTest extends TestCase
{
    public function testBuildsOnlyReadbackVerifiedAdjacentDayOperatingSignals(): void
    {
        $service = new OtaReputationDailySignalService(fn(): array => [
            $this->row(12, 'ctrip', '2026-09-01', 4.6, 3, 2, true),
            $this->row(11, 'ctrip', '2026-08-31', 4.8, 1, 0, true),
            $this->row(22, 'meituan', '2026-09-01', 5.0, 0, 0, true),
            $this->row(21, 'meituan', '2026-08-31', 5.0, 0, 0, true),
            $this->row(99, 'ctrip', '2026-09-01', 1.0, 99, 99, false),
        ]);

        $result = $service->build(80, 80, '2026-09-01');
        $signals = [];
        foreach ($result['signals'] as $signal) {
            $signals[$signal['signal_key']] = $signal;
        }

        self::assertSame('actionable_signals_available', $result['status']);
        self::assertCount(3, $signals);
        self::assertSame(2, $signals['signal:ctrip:reputation:unreplied_reviews']['current_value']);
        self::assertSame(3, $signals['signal:ctrip:reputation:bad_reviews_increased']['current_value']);
        self::assertSame(1, $signals['signal:ctrip:reputation:bad_reviews_increased']['previous_value']);
        self::assertSame(4.6, $signals['signal:ctrip:reputation:score_declined']['current_value']);
        self::assertSame(4.8, $signals['signal:ctrip:reputation:score_declined']['previous_value']);
        self::assertSame(['online_daily_data#12', 'online_daily_data#11'], $signals['signal:ctrip:reputation:score_declined']['fact_refs']);
        self::assertSame('strict_fact_available', $result['platforms']['meituan']['status']);
        self::assertSame([], array_values(array_filter(
            $result['signals'],
            static fn(array $signal): bool => $signal['platform'] === 'meituan'
        )));
        self::assertFalse($result['boundary']['review_text_read']);
        self::assertFalse($result['boundary']['automatic_reply']);
        self::assertSame(0, $result['boundary']['external_write_count']);
    }

    public function testExplicitZeroIsPreservedButDoesNotCreateAnAlert(): void
    {
        $result = (new OtaReputationDailySignalService(fn(): array => [
            $this->row(2, 'meituan', '2026-09-01', 5.0, 0, 0, true),
            $this->row(1, 'meituan', '2026-08-31', 5.0, 0, 0, true),
        ]))->build(80, 80, '2026-09-01');

        self::assertSame('no_actionable_signal', $result['status']);
        self::assertSame([], $result['signals']);
        self::assertSame('strict_fact_available', $result['platforms']['meituan']['status']);
    }

    public function testUnverifiedOrWrongScopeRowsCannotBecomeSignals(): void
    {
        $wrongHotel = $this->row(3, 'ctrip', '2026-09-01', 3.0, 5, 4, true);
        $wrongHotel['system_hotel_id'] = 81;
        $missingTrace = $this->row(4, 'ctrip', '2026-09-01', 3.0, 5, 4, true);
        $missingTrace['source_trace_id'] = '';
        $result = (new OtaReputationDailySignalService(fn(): array => [
            $this->row(2, 'ctrip', '2026-09-01', 3.0, 5, 4, false),
            $wrongHotel,
            $missingTrace,
        ]))->build(80, 80, '2026-09-01');

        self::assertSame('no_actionable_signal', $result['status']);
        self::assertSame([], $result['signals']);
        self::assertSame('no_current_strict_fact', $result['platforms']['ctrip']['status']);
    }

    public function testNonPrimaryChannelsAndNonUsableValidationStatesCannotBecomeSignals(): void
    {
        $qunar = $this->row(20, 'ctrip', '2026-09-01', 2.0, 8, 7, true);
        $raw = json_decode((string)$qunar['raw_data'], true);
        self::assertIsArray($raw);
        $raw['dimension_values']['comment_channel'] = '去哪儿';
        $qunar['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE);

        $rows = [$qunar];
        foreach (['', 'abnormal', 'quarantined', 'warning', 'partial', 'unverified', 'stale'] as $offset => $status) {
            $row = $this->row(30 + $offset, 'ctrip', '2026-09-01', 2.0, 8, 7, true);
            $row['validation_status'] = $status;
            $rows[] = $row;
        }

        $result = (new OtaReputationDailySignalService(static fn(): array => $rows))
            ->build(80, 80, '2026-09-01');

        self::assertSame('no_actionable_signal', $result['status']);
        self::assertSame([], $result['signals']);
        self::assertSame('no_current_strict_fact', $result['platforms']['ctrip']['status']);
    }

    public function testRejectsInvalidScopeAndDate(): void
    {
        $service = new OtaReputationDailySignalService(fn(): array => []);
        $this->expectException(InvalidArgumentException::class);
        $service->build(0, 80, '2026-02-30');
    }

    /** @return array<string,mixed> */
    private function row(
        int $id,
        string $source,
        string $dataDate,
        float $score,
        int $badReviewCount,
        int $unrepliedCount,
        bool $readbackVerified
    ): array {
        $channel = $source === 'ctrip' ? '携程' : '美团';
        return [
            'id' => $id,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'hotel_id' => $source . '-80',
            'source' => $source,
            'platform' => $source,
            'data_type' => 'review',
            'data_date' => $dataDate,
            'comment_score' => $score,
            'readback_verified' => $readbackVerified ? 1 : 0,
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $source . ':' . str_repeat('a', 64),
            'update_time' => $dataDate . ' 09:00:00',
            'raw_data' => json_encode([
                'metrics' => [
                    'bad_review_count' => $badReviewCount,
                    'comment_unreply_count' => $unrepliedCount,
                ],
                'dimension_values' => ['comment_channel' => $channel],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
}
