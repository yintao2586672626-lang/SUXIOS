<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

final class RevenueAiAggregateReadFailureTest extends TestCase
{
    public function testFailedOrErrorAggregateWithoutFactsIsReadFailureRatherThanPartial(): void
    {
        foreach (['failed', 'error'] as $status) {
            $overview = $this->overview(['status' => $status]);
            self::assertSame('failed', $overview['data_status'], $status);
            self::assertSame([], $overview['actual_source_channels']);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
            $issues = array_values(array_filter($overview['quality_issues'],
                static fn(array $row): bool => $row['reason'] === 'overview_dataset_read_failed'));
            self::assertCount(1, $issues);
            self::assertSame('high', $issues[0]['severity']);
            self::assertStringContainsString('读取失败', $issues[0]['display_reason']);
            self::assertSame('data-health', $issues[0]['target_tab']);
        }
    }

    public function testAcceptedFactsDoNotHideAggregateReadFailure(): void
    {
        $overview = $this->overview($this->dataset('failed'));
        self::assertSame('failed', $overview['data_status']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertTrue($overview['channel_statuses']['ctrip']['has_target_date_rows']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
        self::assertNotSame('verified', $overview['metrics']['ota_room_revenue']['status']);
    }

    public function testExplicitAuthorizationRemainsThePrimaryBlockedStatus(): void
    {
        $overview = $this->overview(['status' => 'error'], ['ctrip' => [
            'status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'login expired',
        ]]);
        self::assertSame('unauthorized', $overview['data_status']);
        self::assertSame('AUTH_EXPIRED', $overview['channel_statuses']['ctrip']['reason']);
        self::assertFalse($overview['channel_statuses']['ctrip']['has_target_date_rows']);
    }

    public function testNormalMissingAndAcceptedAggregateKeepTheirSemantics(): void
    {
        $missing = $this->overview(['status' => 'empty']);
        self::assertSame('unknown', $missing['data_status']);
        self::assertNull($missing['metrics']['ota_room_revenue']['value']);
        $normal = $this->overview($this->dataset('ready'));
        self::assertNotSame('failed', $normal['data_status']);
        self::assertEquals(100, $normal['metrics']['ota_room_revenue']['value']);
    }

    public function testSuccessfulOrConfirmedEmptySyncCannotOverrideFailedAggregateRead(): void
    {
        foreach (['success', 'empty_confirmed'] as $syncStatus) {
            foreach (['2026-07-27 23:00:00', '2026-07-28 10:00:00'] as $lastSuccess) {
                $overview = $this->overview(['status' => 'failed'], [
                    'ctrip' => ['status' => 'ready', 'last_sync_status' => $syncStatus, 'last_sync_time' => $lastSuccess],
                    'meituan' => ['status' => 'ready', 'last_sync_status' => $syncStatus, 'last_sync_time' => $lastSuccess],
                ]);
                self::assertSame('failed', $overview['data_status']);
                self::assertSame([], $overview['actual_source_channels']);
                self::assertNull($overview['metrics']['ota_room_revenue']['value']);
            }
        }
    }

    public function testReadFailureCannotMarkThePricingDataQualityGateReady(): void
    {
        foreach ([false => 'overview_dataset_read_failed', true => 'target_date_dataset_failed'] as $channelInput => $reason) {
            $failed = $this->dataset('failed');
            $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(
                $channelInput ? [] : $failed,
                $channelInput ? ['ctrip' => $failed] : [],
                ['ctrip' => ['status' => 'ready', 'last_sync_status' => 'success', 'last_sync_time' => '2026-07-28 10:00:00']],
                ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]
            );
            $gates = array_values(array_filter($overview['pricing_readiness']['gates'],
                static fn(array $row): bool => $row['key'] === 'data_quality'));
            self::assertCount(1, $gates);
            self::assertSame('blocked', $gates[0]['status']);
            self::assertSame($reason, $gates[0]['reason']);
            self::assertStringContainsString('读取失败', $gates[0]['display_reason']);
            self::assertContains($reason, $overview['pricing_readiness']['blocking_reasons']);
            self::assertFalse($overview['pricing_readiness']['can_generate_recommendation']);
            self::assertFalse($overview['pricing_readiness']['ai_decision_review_contract']['approval_allowed']);
        }
    }

    public function testHealthyChannelRebuildSupersedesTheUnusedAggregateFailureFlag(): void
    {
        $overview = (new RevenueAiOverviewService())->buildOverviewFromDataset(['status' => 'error'],
            ['ctrip' => $this->dataset('ready')], [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip']]);
        self::assertNotSame('failed', $overview['data_status']);
        self::assertSame(['ctrip'], $overview['actual_source_channels']);
        self::assertEquals(100, $overview['metrics']['ota_room_revenue']['value']);
        self::assertNotContains('overview_dataset_read_failed', array_column($overview['quality_issues'], 'reason'));
    }

    public function testFailureDoesNotKeepForeignDateHotelOrPlatformFacts(): void
    {
        foreach ([['date_key', '2026-07-27'], ['hotel_key', 'system:81'], ['platform_key', 'qunar']] as [$field, $value]) {
            $aggregate = $this->dataset('failed');
            $aggregate['fact_ota_daily'][0][$field] = $value;
            $overview = $this->overview($aggregate);
            self::assertSame('failed', $overview['data_status']);
            self::assertSame([], $overview['actual_source_channels']);
            self::assertNull($overview['metrics']['ota_room_revenue']['value']);
        }
    }

    public function testExplicitCollectionFailureStillKeepsItsChannelCause(): void
    {
        $overview = $this->overview(['status' => 'error'], ['ctrip' => [
            'status' => 'ready', 'last_sync_status' => 'failed', 'last_error' => 'network timeout',
        ]]);
        self::assertSame('failed', $overview['data_status']);
        self::assertSame('NETWORK_ERROR', $overview['channel_statuses']['ctrip']['reason']);
        self::assertContains('NETWORK_ERROR', array_column($overview['quality_issues'], 'reason'));
        self::assertContains('overview_dataset_read_failed', array_column($overview['quality_issues'], 'reason'));
    }

    private function overview(array $aggregate, array $sourceStatuses = []): array
    {
        return (new RevenueAiOverviewService())->buildOverviewFromDataset($aggregate, [], $sourceStatuses,
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => ['ctrip', 'meituan']]);
    }

    private function dataset(string $status): array
    {
        return ['status' => $status, 'fact_ota_daily' => [[
            'date_key' => '2026-07-28', 'hotel_key' => 'system:80', 'platform_key' => 'ctrip',
            'data_type' => 'business', 'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact', 'room_revenue' => 100,
            'revenue' => 100, 'gross_revenue' => 100, 'net_revenue' => 100,
            'room_nights' => 1, 'available_room_nights' => 10, 'order_count' => 1,
        ]], 'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    }
}
