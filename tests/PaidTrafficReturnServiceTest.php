<?php
declare(strict_types=1);
namespace Tests;

use app\service\PaidTrafficReturnService;
use PHPUnit\Framework\TestCase;
use Tests\Support\PromotionExperimentFixture as F;

final class PaidTrafficReturnServiceTest extends TestCase
{
    private function runRows(array $rows): array { return (new PaidTrafficReturnService())->evaluate(F::scope(), $rows, '2026-08-20', 7); }

    public function testRefundsCostsAndExpandableAccountingAreAligned(): void
    {
        $r = $this->runRows([F::row()]);
        self::assertSame(900.0, $r['platform_attribution']['net_revenue']);
        self::assertSame(9.0, $r['platform_attribution']['net_orders']);
        self::assertSame(9.0, $r['platform_attribution']['net_roas']);
        self::assertSame(500.0, $r['book_return']['balance_after_known_scope_costs']);
        self::assertSame(500.0, $r['calculation'][2]['value']);
        self::assertSame(5.0, $r['book_return']['return_on_ad_spend']);
        self::assertFalse($r['book_return']['is_hotel_profit']);
        self::assertNull($r['evidence_boundary']['incremental_revenue']);
    }

    public function testDuplicateAndGrowingCumulativeSnapshotsAreNeverSummed(): void
    {
        $old = F::row(['period_end' => '2026-08-10', 'collected_at' => '2026-08-11T02:00:00Z', 'spend' => 40]);
        $new = F::row();
        foreach ([[$old, $new, $new], [$new, $old, $new]] as $rows) {
            $r = $this->runRows($rows);
            self::assertSame(100.0, $r['totals']['spend']);
            self::assertSame(1, $r['deduplication']['selected']);
            self::assertSame(2, $r['deduplication']['superseded_or_duplicate']);
        }
    }

    public function testZeroIsValidWhileUnknownCostAndZeroDenominatorStayUnknown(): void
    {
        $r = $this->runRows([F::row(['spend' => 0, 'refunds' => 0, 'commission' => null])]);
        self::assertSame(0.0, $r['totals']['spend']);
        self::assertSame(1000.0, $r['platform_attribution']['net_revenue']);
        self::assertNull($r['platform_attribution']['net_roas']);
        self::assertNull($r['book_return']['balance_after_known_scope_costs']);
        self::assertContains('commission', $r['missing_fields']);
        self::assertSame('partial', $r['status']);
        $loss = $this->runRows([F::row(['attributed_revenue' => 0, 'refunds' => 0, 'commission' => 0, 'fulfillment_cost' => 0, 'other_cost' => 0])]);
        self::assertSame(-100.0, $loss['book_return']['balance_after_known_scope_costs']);
        self::assertSame(-1.0, $loss['book_return']['return_on_ad_spend']);
    }

    public function testPartialAndAllMissingPeriodsKeepCoverageExplicit(): void
    {
        $r = $this->runRows([F::row(['period_end' => '2026-08-10'])]);
        self::assertSame(['2026-08-11'], $r['coverage']['missing_dates']);
        self::assertSame('非全期间金额', $r['coverage']['amount_label']);
        $empty = $this->runRows([]);
        self::assertSame('blocked', $empty['status']);
        self::assertNull($empty['totals']['spend']);
        self::assertNull($empty['known_subtotals']['spend']);
    }

    public function testIncompleteFieldsDoNotExposeKnownSubtotalAsTotal(): void
    {
        $r = $this->runRows([F::row(), F::row(['campaign_id' => 'SYNTHETIC-OTHER', 'spend' => null])]);
        self::assertNull($r['totals']['spend']);
        self::assertSame(100.0, $r['known_subtotals']['spend']);
        self::assertNull($r['platform_attribution']['net_roas']);
    }

    public function testOtherCampaignCoverageDoesNotHideMissingCampaignDates(): void
    {
        $r = $this->runRows([F::row(['period_end' => '2026-08-10']), F::row(['campaign_id' => 'OTHER', 'period_start' => '2026-08-11'])]);
        self::assertSame([], $r['coverage']['missing_dates']);
        self::assertSame('非全期间金额', $r['coverage']['amount_label']);
        self::assertSame(['2026-08-11'], $r['coverage']['missing_campaign_dates']['SYNTHETIC-AD']);
        self::assertSame(['2026-08-10'], $r['coverage']['missing_campaign_dates']['OTHER']);
    }

    public function testManualImportCannotClaimVerifiedSource(): void
    {
        $r = $this->runRows([F::row(['source_method' => 'manual_import'])]);
        self::assertSame('unverified', $r['source_quality']);
        self::assertSame('manual_unverified', $r['records'][0]['source_quality']);
    }

    public function testWindowMismatchAndOldSnapshotCannotMatureByClockAlone(): void
    {
        $old = $this->runRows([F::row(['collected_at' => '2026-08-12T10:00:00+08:00'])]);
        self::assertSame('pending', $old['maturity']['status']);
        self::assertSame('mature_snapshot_missing', $old['maturity']['reason']);
        $this->expectException(\InvalidArgumentException::class);
        $this->runRows([F::row(['attribution_window_days' => 3])]);
    }

    public function testUndefinedImportFieldsAreRejectedBeforePersistence(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未定义字段');
        $this->runRows([F::row(['raw_response' => ['synthetic_only' => true]])]);
    }

    public function testScopeUnitsInvalidMoneyAndOverlapsFailClosed(): void
    {
        $invalid = [['spend' => -0.01], ['refunds' => 1001], ['refunded_orders' => 11], ['attributed_orders' => 1.2], ['spend' => 0.001], ['spend' => true], ['spend' => INF], ['currency' => 'USD'], ['amount_unit' => 'fen'], ['platform_store_id' => 'OTHER'], ['tenant_id' => 701], ['system_hotel_id' => 702], ['platform' => 'meituan'], ['dimension' => 'qunar'], ['compare_type' => 'competitor'], ['period_start' => '2026-08-09'], ['collected_at' => '2026-02-30T10:00:00+08:00'], ['collected_at' => '2026-08-15 10:00:00'], ['attribution_model' => 'multi_touch'], ['cost_source_ref' => '']];
        foreach ($invalid as $change) {
            try { $this->runRows([F::row($change)]); self::fail('Should reject ' . json_encode($change)); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
        foreach ([[F::row(), F::row(['spend' => 101])], [F::row(), F::row(['snapshot_kind' => 'daily_total', 'period_start' => '2026-08-11'])]] as $rows) {
            try { $this->runRows($rows); self::fail('Should reject conflicting/overlapping snapshot'); }
            catch (\InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }
}
