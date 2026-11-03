<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiChannelStatusFactPresenceTest extends TestCase
{
    public function testAbsentObjectsCannotDisplayTargetDateRowsOrSuccessfulStatus(): void
    {
        $overview = $this->overview([]);
        foreach (['ctrip', 'meituan'] as $source) {
            self::assertFalse($overview['channel_statuses'][$source]['has_target_date_rows']);
            self::assertSame('unknown', $overview['channel_statuses'][$source]['status']);
        }
        self::assertSame([], $overview['actual_source_channels']);
    }

    public function testSparseCtripFactsCannotMakeTheMeituanCardReady(): void
    {
        $overview = $this->overview(['ctrip' => $this->dataset('ctrip')]);
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertFalse($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertSame('ok', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('unknown', $overview['channel_statuses']['meituan']['status']);
    }

    public function testSameDateSyncWithoutAcceptedFactsDoesNotBecomeCurrentRows(): void
    {
        $overview = $this->overview([], [], ['ctrip' => [
            'status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-07-28 10:00:00',
        ]]);
        self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame('unknown', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('DATE_NOT_AVAILABLE', $overview['channel_statuses']['ctrip']['reason']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testFailedDatasetWithoutFactsRemainsFailedAndHasNoCurrentRows(): void
    {
        $overview = $this->overview(['meituan' => ['status' => 'failed']]);
        self::assertFalse($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertSame('failed', $overview['channel_statuses']['meituan']['status']);
        self::assertNotEmpty($overview['channel_statuses']['meituan']['reason']);
        self::assertSame('failed', $overview['data_status']);
    }

    public function testAggregateCurrentFactsKeepTheMatchingCardEvenWithoutAChannelObject(): void
    {
        $overview = $this->overview([], $this->dataset('ctrip'));
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertFalse($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
    }

    public function testBothActualChannelRowsStillAppear(): void
    {
        $overview = $this->overview(['ctrip' => $this->dataset('ctrip'), 'meituan' => $this->dataset('meituan')]);
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertTrue($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertSame(['ctrip', 'meituan'], $overview['actual_source_channels']);
    }

    public function testSparseMeituanFactsCannotMakeTheCtripCardReady(): void
    {
        $overview = $this->overview(['meituan' => $this->dataset('meituan')]);
        self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame('unknown', $overview['channel_statuses']['ctrip']['status']);
        self::assertTrue($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertSame('ok', $overview['channel_statuses']['meituan']['status']);
    }

    public function testWrongDateHotelPlatformAndTraceCannotBecomeTargetDateRows(): void
    {
        foreach ([['date_key', '2026-07-27'], ['hotel_key', 'system:81'], ['platform_key', 'meituan'],
            ['source_trace', ['date_key' => '2026-07-27', 'platform' => 'ctrip', 'system_hotel_id' => 80]],
            ['source_trace', ['platform' => 'meituan']], ['source_trace', ['system_hotel_id' => 81]]] as [$field, $value]) {
            $dataset = $this->dataset('ctrip');
            $dataset['fact_ota_daily'][0][$field] = $value;
            $overview = $this->overview(['ctrip' => $dataset]);
            self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows'], $field);
            self::assertSame('unknown', $overview['channel_statuses']['ctrip']['status'], $field);
            self::assertSame([], $overview['actual_source_channels'], $field);
            self::assertNull($overview['metrics']['ota_room_revenue']['value'], $field);
        }
    }

    public function testReadFailuresDoNotBecomeReadyFromAnEarlierSuccessfulSync(): void
    {
        foreach (['failed', 'error'] as $status) {
            $overview = $this->overview(['ctrip' => ['status' => $status]], [], ['ctrip' => [
                'status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-07-28 10:00:00',
            ]]);
            $card = $overview['channel_statuses']['ctrip'];
            self::assertFalse($card['has_target_date_rows']);
            self::assertSame('failed', $card['status']);
            self::assertSame('target_date_dataset_failed', $card['reason']);
            self::assertSame('读取失败', $card['label']);
            self::assertSame('failed', $overview['data_status']);
            $issues = array_values(array_filter($overview['quality_issues'],
                static fn(array $issue): bool => $issue['reason'] === 'target_date_dataset_failed'));
            self::assertCount(1, $issues);
            self::assertSame('high', $issues[0]['severity']);
            self::assertSame('data-health', $issues[0]['target_tab']);
            self::assertStringContainsString('读取失败', $issues[0]['display_reason']);
        }
    }

    public function testReadFailureWithAcceptedFactsKeepsPresenceSeparateFromFailure(): void
    {
        $dataset = $this->dataset('ctrip');
        $dataset['status'] = 'failed';
        $overview = $this->overview(['ctrip' => $dataset]);
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertSame('failed', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('target_date_dataset_failed', $overview['channel_statuses']['ctrip']['reason']);
        self::assertSame('failed', $overview['data_status']);
    }

    public function testExplicitCollectionAndAuthorizationFailuresArePreservedWithFacts(): void
    {
        foreach (['network timeout' => ['failed', 'NETWORK_ERROR'], 'login expired' => ['unauthorized', 'AUTH_EXPIRED'],
            'captcha required' => ['unauthorized', 'CAPTCHA_REQUIRED']] as $error => [$status, $reason]) {
            $dataset = $this->dataset('ctrip');
            $dataset['status'] = 'failed';
            $overview = $this->overview(['ctrip' => $dataset], [], ['ctrip' => [
                'status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => $error,
            ]]);
            self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
            self::assertSame($status, $overview['channel_statuses']['ctrip']['status']);
            self::assertSame($reason, $overview['channel_statuses']['ctrip']['reason']);
            self::assertSame($status, $overview['data_status']);
        }
    }

    public function testOldSuccessDoesNotSubstituteForMissingTargetDateFacts(): void
    {
        $overview = $this->overview([], [], ['ctrip' => [
            'status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-07-27 23:00:00',
        ]]);
        self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame('stale', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('DATA_STALE', $overview['channel_statuses']['ctrip']['reason']);
        self::assertSame('stale', $overview['data_status']);
    }

    public function testConfirmedEmptyIsStillEmptyWithoutSyntheticZeroMetrics(): void
    {
        $overview = $this->overview([], [], ['ctrip' => [
            'status' => 'ready', 'last_sync_status' => 'empty_confirmed', 'last_sync_time' => '2026-07-28 10:00:00',
        ]]);
        self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame('empty_confirmed', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame('ZERO_CONFIRMED', $overview['channel_statuses']['ctrip']['reason']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
    }

    public function testRequestedCtripScopeDoesNotIncludeMeituanFactsOrFailures(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset([], [
            'ctrip' => $this->dataset('ctrip'), 'meituan' => ['status' => 'failed'],
        ], ['meituan' => ['status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'login expired']],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertSame(['ctrip'], array_keys($overview['channel_statuses']));
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertSame('ok', $overview['channel_statuses']['ctrip']['status']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertNotSame('unauthorized', $overview['data_status']);
    }

    public function testTrafficFactsShowPresenceWithoutClaimingRevenue(): void
    {
        $overview = $this->overview([], ['status' => 'ready', 'fact_ota_traffic' => [[
            'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => 'ctrip',
            'metric_scope' => 'ota_channel', 'visitors' => 15,
        ]]]);
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertFalse($overview['channel_statuses']['meituan']['has_target_date_rows']);
        self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        self::assertNotSame('verified', $overview['metrics']['ota_room_revenue']['status']);
    }

    private function overview(array $channels, array $aggregate = [], array $sourceStatuses = []): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset($aggregate, $channels, $sourceStatuses,
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip', 'meituan']]);
    }

    private function dataset(string $source): array
    {
        return ['status' => 'ready', 'fact_ota_daily' => [[
            'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => $source,
            'data_type' => 'business', 'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact', 'room_revenue' => 100,
            'revenue' => 100, 'gross_revenue' => 100, 'net_revenue' => 100,
            'room_nights' => 1, 'available_room_nights' => 10, 'order_count' => 1,
        ]], 'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    }
}
