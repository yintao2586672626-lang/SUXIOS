<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/RevenueAiCanonicalRequestedPlatformScopeTest.php';

final class RevenueAiBusinessSignalEvidenceTest extends TestCase
{
    public function testUntracedNumbersCannotBecomeVerifiedBookingSignals(): void
    {
        $overview = $this->overview('no_trace');
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            self::assertSame('--', $overview['signals'][$key]['value'], $key);
            self::assertSame('unverified', $overview['signals'][$key]['status'], $key);
            self::assertSame('metric_truth_unverified', $overview['signals'][$key]['reason']);
        }
        self::assertFalse($overview['pricing_readiness']['can_auto_write_ota']);
    }

    public function testSavedButNotReadBackSignalInputsStayUnverified(): void
    {
        $overview = $this->overview('no_readback');
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            self::assertSame('--', $overview['signals'][$key]['value']);
            self::assertSame('unverified', $overview['signals'][$key]['status']);
            self::assertContains('readback_not_fully_verified', $overview['signals'][$key]['truth']['evidence_gap_codes']);
        }
    }

    public function testCollectionFailureCannotBeHiddenByNumericSignalInputs(): void
    {
        $overview = $this->overview('collection_failed');
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            self::assertSame('--', $overview['signals'][$key]['value']);
            self::assertSame('collection_failed', $overview['signals'][$key]['status']);
            self::assertSame('metric_truth_collection_failed', $overview['signals'][$key]['reason']);
        }
    }

    public function testVerifiedIndependentGroupsRemainAvailableDespiteRejectedCanonicalTotals(): void
    {
        $normal = $this->overview('normal');
        $rejected = $this->overview('canonical_rejected');
        $different = $this->overview('canonical_different');
        self::assertSame('当天 ¥300.00 · 8-14天 ¥200.00', $normal['signals']['booking_window_adr']['value']);
        self::assertSame('ok', $normal['signals']['booking_window_adr']['status']);
        self::assertSame('ok', $normal['signals']['channel_booking_window_month']['status']);
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            self::assertSame($normal['signals'][$key], $rejected['signals'][$key]);
            self::assertSame($normal['signals'][$key], $different['signals'][$key]);
        }
        self::assertNull($rejected['metrics']['ota_room_revenue']['value']);
        self::assertSame(125.45, $different['metrics']['ota_room_revenue']['value']);
    }

    public function testPartialReadbackCannotExposeAnUnverifiedCombinedStructure(): void
    {
        $overview = $this->overview('partial_readback');
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            $signal = $overview['signals'][$key];
            self::assertSame('--', $signal['value']);
            self::assertSame('partial', $signal['status']);
            self::assertSame('metric_truth_partial', $signal['reason']);
            self::assertSame(2, $signal['truth']['persistence']['record_count']);
            self::assertSame(1, $signal['truth']['persistence']['readback_verified_count']);
            self::assertStringContainsString('精确回读', $signal['detail']);
        }
    }

    public function testVerifiedAlignedSubsetAndIndependentOrdersRemainUsable(): void
    {
        $overview = $this->overview('partial_fields');
        $signal = $overview['signals']['booking_window_adr'];
        self::assertSame('当天 ¥300.00', $signal['value']);
        self::assertSame('partial', $signal['status']);
        self::assertSame('booking_window_adr_fields_partial', $signal['reason']);
        self::assertSame(1, $signal['truth']['persistence']['record_count']);
        self::assertTrue($signal['truth']['persistence']['readback_verified']);
        self::assertSame([], $signal['truth']['evidence_gap_codes']);
        self::assertSame('ok', $overview['signals']['channel_booking_window_month']['status']);
        self::assertNotSame('--', $overview['signals']['channel_booking_window_month']['value']);
    }

    public function testActualZeroIncomeIsAvailableButNoNightsAndNoLeadTimeRetainPreciseReasons(): void
    {
        $zero = $this->overview('zero_income')['signals']['booking_window_adr'];
        self::assertSame('当天 ¥0.00 · 8-14天 ¥0.00', $zero['value']);
        self::assertSame('ok', $zero['status']);
        self::assertSame('verified', $zero['truth']['status']);
        foreach (['zero_nights' => 'booking_window_adr_fields_missing', 'no_lead' => 'lead_time_fields_missing'] as $case => $reason) {
            $signal = $this->overview($case)['signals']['booking_window_adr'];
            self::assertSame('--', $signal['value']);
            self::assertSame('not_calculable', $signal['status']);
            self::assertSame($reason, $signal['reason']);
        }
        self::assertSame('ok', $this->overview('zero_nights')['signals']['channel_booking_window_month']['status']);
    }

    public function testRepairingEvidenceRestoresExactSignalValuesWithoutEnablingOperations(): void
    {
        $failed = $this->overview('no_readback');
        $recovered = $this->overview('restored_exact');
        self::assertSame('--', $failed['signals']['booking_window_adr']['value']);
        self::assertSame('当天 ¥125.45 · 8-14天 ¥200.00', $recovered['signals']['booking_window_adr']['value']);
        self::assertSame('ok', $recovered['signals']['booking_window_adr']['status']);
        self::assertSame(125.45, $recovered['signals']['booking_window_adr']['detail_metrics']['buckets'][0]['adr']);
        foreach (['booking_window_adr', 'channel_booking_window_month'] as $key) {
            self::assertSame('online-data', $failed['signals'][$key]['target_page']);
            self::assertSame('data-health', $failed['signals'][$key]['target_tab']);
            self::assertStringContainsString('精确回读', $failed['signals'][$key]['next_action']);
        }
        self::assertFalse($recovered['pricing_readiness']['can_auto_write_ota']);
    }

    public function testSparseAndMissingOrderSamplesAreNeverZeroFilled(): void
    {
        $sparse = $this->overview('sparse_orders')['signals']['channel_booking_window_month'];
        self::assertSame('--', $sparse['value']);
        self::assertSame('partial', $sparse['status']);
        self::assertSame('channel_booking_window_month_sparse_cells', $sparse['reason']);
        self::assertCount(2, $sparse['detail_metrics']['cells']);
        self::assertSame('not_calculable', $this->overview('no_orders')['signals']['channel_booking_window_month']['status']);
        self::assertSame('ok', $this->overview('no_orders')['signals']['booking_window_adr']['status']);
    }

    public function testConflictingSourceHotelDateOrPlatformCannotBeHiddenByVerifiedLabels(): void
    {
        foreach (['hotel', 'date', 'platform', 'label_only'] as $case) {
            $summary = (new \app\service\OtaRevenueMetricService())->summarizeDataset($this->dataset('normal'));
            foreach (['booking_window_adr.buckets', 'channel_booking_window_month.cells'] as $key) {
                if ($case === 'hotel') $summary['metric_trust'][$key]['source']['hotels'] = [['system_hotel_id' => 81]];
                if ($case === 'date') $summary['metric_trust'][$key]['source']['date_range'] = ['start' => '2026-07-27', 'end' => '2026-07-27'];
                if ($case === 'platform') $summary['metric_trust'][$key]['source']['platforms'] = ['meituan'];
                if ($case === 'label_only') unset($summary['metric_trust'][$key]['source']);
            }
            $service = new RevenueAiOverviewService();
            $signals = (new \ReflectionMethod($service, 'signals'))->invoke($service, $summary, ['ctrip'], [], '2026-07-28', 80);
            foreach (['booking_window_adr' => 'booking_window_adr.buckets', 'channel_booking_window_month' => 'channel_booking_window_month.cells'] as $key => $trustKey) {
                self::assertSame('--', $signals[$key]['value'], $case);
                self::assertSame('unverified', $signals[$key]['status']);
                self::assertSame($summary['metric_trust'][$trustKey]['truth'], $signals[$key]['truth']);
            }
        }
    }

    public function testAValidSingleChannelSubsetDoesNotClaimOtherChannels(): void
    {
        $overview = $this->overview('channel_subset');
        $signal = $overview['signals']['booking_window_adr'];
        self::assertSame('当天 ¥300.00', $signal['value']);
        self::assertSame('partial', $signal['status']);
        self::assertSame(['ctrip'], $signal['source_channels']);
        self::assertSame(['ctrip'], $signal['truth']['platforms']);
        self::assertSame(['ctrip', 'meituan'], $overview['signals']['channel_booking_window_month']['source_channels']);
    }

    private function dataset(string $scenario): array
    {
        $fixture = new RevenueAiPricingMetricEvidenceGateTest('testVerifiedSameScopeIncomeAndNightsRemainReady');
        $dataset = (new \ReflectionMethod($fixture, 'verifiedDataset'))->invoke($fixture, 'ctrip');
        $row = $dataset['fact_ota_daily'][0];
        $dataset['fact_ota_daily'] = [];
        foreach ([[300, 1, 0, 12], [400, 2, 10, 20]] as $index => [$income, $nights, $lead, $orders]) {
            $fact = array_merge($row, ['room_revenue' => $income, 'revenue' => $income,
                'gross_revenue' => $income, 'net_revenue' => $income, 'room_nights' => $nights,
                'lead_time_days' => $lead, 'checkin_date' => '2026-08-01', 'order_count' => $orders]);
            $fact['source_trace']['row_id'] = 'synthetic-booking-' . $index;
            $fact['source_trace']['source_trace_id'] = 'synthetic:booking:' . $index;
            if ($scenario === 'no_trace') unset($fact['source_trace']);
            if ($scenario === 'no_readback') $fact['source_trace']['readback_verified'] = false;
            if ($scenario === 'partial_readback' && $index === 1) $fact['source_trace']['readback_verified'] = false;
            if ($scenario === 'partial_fields' && $index === 1) $fact['room_revenue'] = null;
            if ($scenario === 'zero_income') $fact['room_revenue'] = 0;
            if ($scenario === 'zero_nights') $fact['room_nights'] = 0;
            if ($scenario === 'no_lead') $fact['lead_time_days'] = null;
            if ($scenario === 'no_orders') $fact['order_count'] = null;
            if ($scenario === 'sparse_orders') $fact['order_count'] = 1;
            if ($scenario === 'restored_exact' && $index === 0) $fact['room_revenue'] = 125.45;
            if ($scenario === 'channel_subset' && $index === 1) {
                $fact['room_revenue'] = null;
                $fact['platform_key'] = 'meituan';
                $fact['source_trace']['platform'] = 'meituan';
            }
            if ($scenario === 'collection_failed') {
                $fact['source_trace']['saved_success'] = false;
                $fact['source_trace']['failure_reasons'] = ['collection_failed'];
            }
            $dataset['fact_ota_daily'][] = $fact;
        }
        return $dataset;
    }

    private function overview(string $scenario): array
    {
        $dataset = $this->dataset($scenario);
        $layer = [];
        if (str_starts_with($scenario, 'canonical_')) {
            $fixture = new RevenueAiCanonicalRequestedPlatformScopeTest('testOneForeignMetricDoesNotInvalidateOtherMatchingMetrics');
            $layer = (new \ReflectionMethod($fixture, 'layer'))->invoke($fixture, $dataset);
            if ($scenario === 'canonical_rejected') $layer['analysis_metrics']['ota_room_revenue']['key'] = 'ota_room_nights';
            if ($scenario === 'canonical_different') $layer['analysis_metrics']['ota_room_revenue']['value'] = 125.45;
        }
        $channels = ['ctrip' => $dataset];
        $enabled = ['ctrip'];
        if ($scenario === 'channel_subset') {
            $channels['ctrip']['fact_ota_daily'] = [$dataset['fact_ota_daily'][0]];
            $channels['meituan'] = $dataset;
            $channels['meituan']['fact_ota_daily'] = [$dataset['fact_ota_daily'][1]];
            $enabled[] = 'meituan';
        }
        return (new RevenueAiOverviewService())->buildOverviewFromDataset([], $channels, [],
            ['business_date' => '2026-07-28', 'hotel_id' => 80, 'enabled_channels' => $enabled, 'revenue_fact_layer' => $layer]);
    }
}
