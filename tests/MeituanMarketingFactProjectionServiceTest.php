<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanMarketingFactProjectionService;
use PHPUnit\Framework\TestCase;

final class MeituanMarketingFactProjectionServiceTest extends TestCase
{
    public function testAlignedSameRecordAdvertisingFactsCalculateRoasAndOnlyOneReviewDraft(): void
    {
        $service = $this->service([
            $this->row(101, 'advertising', [
                'campaignId' => 'campaign-1',
                'keyword' => '敦煌酒店',
                'spend' => 300,
                'attributedOrderAmount' => 1800,
                'attributionBasis' => 'same-day-paid-order',
                'impressions' => 5000,
                'clicks' => 200,
                // A stored value is not trusted as ROAS; the service recomputes it.
                'roas' => 999,
            ], amount: 300),
        ]);

        $result = $service->project(10, 80, '2026-08-30');

        self::assertSame('ready', $result['status']);
        self::assertCount(1, $result['projections']);
        self::assertSame('campaign', $result['projections'][0]['scope']['object_type']);
        self::assertSame(300.0, $result['projections'][0]['metrics']['spend']);
        self::assertSame(1800.0, $result['projections'][0]['metrics']['attributed_order_amount']);
        self::assertSame(6.0, $result['projections'][0]['metrics']['roas']);
        self::assertSame('calculated', $result['projections'][0]['metrics']['roas_status']);
        self::assertSame('aligned', $result['projections'][0]['metrics']['basis_status']);
        self::assertSame('pending_review', $result['pending_review_draft']['status']);
        self::assertSame('effect_observation', $result['pending_review_draft']['draft_type']);
        self::assertNull($result['pending_review_draft']['system_recommendation']);
        self::assertSame(['continue', 'adjust', 'stop'], $result['pending_review_draft']['human_decision_options']);
        self::assertTrue($result['pending_review_draft']['human_confirmation_required']);
        self::assertFalse($result['pending_review_draft']['operation_intent_created']);
        self::assertFalse($result['pending_review_draft']['operation_task_created']);
        self::assertFalse($result['pending_review_draft']['auto_execution_allowed']);
        self::assertSame(0, $result['pending_review_draft']['external_write_count']);
        self::assertFalse($result['writeback_allowed']);
        self::assertFalse($result['auto_budget_change_allowed']);
        self::assertFalse($result['auto_bid_change_allowed']);
        self::assertSame(0, $result['external_write_count']);
    }

    public function testRoasRemainsNullWhenAttributionBasisIsMissingOrMismatched(): void
    {
        $service = $this->service([
            $this->row(102, 'advertising', [
                'campaignId' => 'missing-basis',
                'spend' => 100,
                'attributedOrderAmount' => 500,
            ], amount: 100),
            $this->row(103, 'advertising', [
                'campaignId' => 'mismatched-basis',
                'spend' => 100,
                'attributedOrderAmount' => 500,
                'spendBasis' => 'same-day-paid-order',
                'attributedOrderAmountBasis' => 'seven-day-click-attribution',
            ], amount: 100, snapshot: '2026-08-30 11:00:00'),
        ]);

        $result = $service->project(10, 80, '2026-08-30');
        $byCampaign = [];
        foreach ($result['projections'] as $projection) {
            $byCampaign[$projection['campaign_id']] = $projection;
        }

        self::assertSame('partial', $result['status']);
        self::assertNull($byCampaign['missing-basis']['metrics']['roas']);
        self::assertSame('attribution_basis_missing', $byCampaign['missing-basis']['metrics']['roas_status']);
        self::assertNull($byCampaign['mismatched-basis']['metrics']['roas']);
        self::assertSame('attribution_basis_mismatch', $byCampaign['mismatched-basis']['metrics']['roas_status']);
        self::assertCount(2, $result['projections']);
        self::assertIsArray($result['pending_review_draft']);
        self::assertSame(
            ['request_data_completion', 'dismiss'],
            $result['pending_review_draft']['human_decision_options']
        );
        self::assertSame(0, $result['external_write_count']);
    }

    public function testMissingAttributedAmountStaysNullAndExplicitZeroIsNotTreatedAsMissing(): void
    {
        $service = $this->service([
            $this->row(104, 'advertising', [
                'campaignId' => 'missing-amount',
                'spend' => 40,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 40),
            $this->row(105, 'advertising', [
                'campaignId' => 'zero-attributed-amount',
                'spend' => 40,
                'attributedOrderAmount' => 0,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 40, snapshot: '2026-08-30 11:00:00'),
        ]);

        $result = $service->project(10, 80, '2026-08-30');
        $byCampaign = [];
        foreach ($result['projections'] as $projection) {
            $byCampaign[$projection['campaign_id']] = $projection;
        }

        self::assertNull($byCampaign['missing-amount']['metrics']['attributed_order_amount']);
        self::assertNull($byCampaign['missing-amount']['metrics']['roas']);
        self::assertSame('attributed_order_amount_missing', $byCampaign['missing-amount']['metrics']['roas_status']);
        self::assertSame(0.0, $byCampaign['zero-attributed-amount']['metrics']['attributed_order_amount']);
        self::assertSame(0.0, $byCampaign['zero-attributed-amount']['metrics']['roas']);
        self::assertSame('calculated', $byCampaign['zero-attributed-amount']['metrics']['roas_status']);
    }

    public function testNegativeAttributedAmountIsInvalidAndCannotProduceNormalReviewActions(): void
    {
        $result = $this->service([
            $this->row(120, 'advertising', [
                'campaignId' => 'negative-attributed-amount',
                'spend' => 100,
                'attributedOrderAmount' => -500,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 100),
        ])->project(10, 80, '2026-08-30');

        self::assertSame('partial', $result['status']);
        self::assertSame('invalid', $result['projections'][0]['quality_status']);
        self::assertNull($result['projections'][0]['metrics']['attributed_order_amount']);
        self::assertNull($result['projections'][0]['metrics']['roas']);
        self::assertSame('invalid', $result['projections'][0]['metrics']['basis_status']);
        self::assertSame(
            'attributed_order_amount_negative',
            $result['projections'][0]['metrics']['roas_status']
        );
        self::assertContains(
            'attributed_order_amount_negative',
            $result['data_quality']['gap_codes']
        );
        self::assertSame(
            ['request_data_completion', 'dismiss'],
            $result['pending_review_draft']['human_decision_options']
        );
        self::assertNotContains('continue', $result['pending_review_draft']['human_decision_options']);
        self::assertNotContains('adjust', $result['pending_review_draft']['human_decision_options']);
        self::assertNotContains('stop', $result['pending_review_draft']['human_decision_options']);
    }

    public function testTenantHotelPlatformAndBusinessDatePollutionIsRejected(): void
    {
        $valid = $this->row(106, 'search_keyword', [
            'keyword' => '机场酒店',
            'impressions' => 800,
            'clicks' => 40,
        ], dimension: '机场酒店');
        $wrongTenant = $valid;
        $wrongTenant['id'] = 107;
        $wrongTenant['tenant_id'] = 11;
        $wrongHotel = $valid;
        $wrongHotel['id'] = 108;
        $wrongHotel['system_hotel_id'] = 81;
        $wrongDate = $valid;
        $wrongDate['id'] = 109;
        $wrongDate['data_date'] = '2026-08-29';
        $wrongPlatform = $valid;
        $wrongPlatform['id'] = 110;
        $wrongPlatform['platform'] = 'ctrip';
        $wrongPlatform['source'] = 'ctrip';
        $unverified = $valid;
        $unverified['id'] = 111;
        $unverified['readback_verified'] = 0;
        $unknownPlatform = $valid;
        $unknownPlatform['id'] = 119;
        unset($unknownPlatform['platform'], $unknownPlatform['source']);

        $result = $this->service([
            $wrongTenant,
            $wrongHotel,
            $wrongDate,
            $wrongPlatform,
            $unknownPlatform,
            $unverified,
            $valid,
        ])->project(10, 80, '2026-08-30');

        self::assertSame('partial', $result['status']);
        self::assertCount(1, $result['projections']);
        self::assertSame('机场酒店', $result['projections'][0]['keyword']);
        self::assertSame(1, $result['data_quality']['rejected_reason_counts']['tenant_scope_mismatch']);
        self::assertSame(1, $result['data_quality']['rejected_reason_counts']['hotel_scope_mismatch']);
        self::assertSame(1, $result['data_quality']['rejected_reason_counts']['business_date_mismatch']);
        self::assertSame(1, $result['data_quality']['ignored_other_platform_row_count']);
        self::assertSame(1, $result['data_quality']['rejected_reason_counts']['platform_scope_mismatch']);
        self::assertSame(1, $result['data_quality']['rejected_reason_counts']['strict_readback_gate_failed']);
        self::assertSame('online_daily_data#106', $result['projections'][0]['evidence_refs'][0]);
    }

    public function testLatestSnapshotWinsWithoutSummingCumulativeCampaignRows(): void
    {
        $service = $this->service([
            $this->row(112, 'advertising', [
                'campaignId' => 'campaign-snapshot',
                'spend' => 100,
                'attributedOrderAmount' => 300,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 100, snapshot: '2026-08-30 09:00:00'),
            $this->row(113, 'advertising', [
                'campaignId' => 'campaign-snapshot',
                'spend' => 150,
                'attributedOrderAmount' => 600,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 150, snapshot: '2026-08-30 12:00:00'),
        ]);

        $result = $service->project(10, 80, '2026-08-30');

        self::assertCount(1, $result['projections']);
        self::assertSame(1, $result['data_quality']['superseded_snapshot_count']);
        self::assertSame(150.0, $result['projections'][0]['metrics']['spend']);
        self::assertSame(600.0, $result['projections'][0]['metrics']['attributed_order_amount']);
        self::assertSame(4.0, $result['projections'][0]['metrics']['roas']);
        self::assertSame(['online_daily_data#113'], $result['projections'][0]['evidence_refs']);
    }

    public function testVerifiedCtripAdvertisingDoesNotDowngradeMeituanProjection(): void
    {
        $meituan = $this->row(117, 'advertising', [
            'campaignId' => 'meituan-campaign',
            'spend' => 100,
            'attributedOrderAmount' => 500,
            'attributionBasis' => 'same-day-paid-order',
        ], amount: 100);
        $ctrip = $meituan;
        $ctrip['id'] = 118;
        $ctrip['platform'] = 'ctrip';
        $ctrip['source'] = 'ctrip';

        $result = $this->service([$ctrip, $meituan])->project(10, 80, '2026-08-30');

        self::assertSame('ready', $result['status']);
        self::assertCount(1, $result['projections']);
        self::assertSame(1, $result['data_quality']['ignored_other_platform_row_count']);
        self::assertSame([], $result['data_quality']['rejected_reason_counts']);
    }

    public function testNoStrictFactsProducesNoDraftAndNoExternalWrite(): void
    {
        $result = $this->service([])->project(10, 80, '2026-08-30');

        self::assertSame('blocked', $result['status']);
        self::assertSame([], $result['projections']);
        self::assertNull($result['pending_review_draft']);
        self::assertContains('strict_meituan_marketing_fact_missing', $result['data_quality']['gap_codes']);
        self::assertSame(0, $result['external_write_count']);
    }

    public function testPersistedRawDataJsonIsDecodedBeforeScopeAndBasisProjection(): void
    {
        $row = $this->row(114, 'advertising', [
            'campaignId' => 'json-campaign',
            'spend' => 80,
            'attributedOrderAmount' => 320,
            'attributionBasis' => 'same-day-paid-order',
        ], amount: 80);
        $row['raw_data'] = json_encode($row['raw_data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $result = $this->service([$row])->project(10, 80, '2026-08-30');

        self::assertSame('ready', $result['status']);
        self::assertCount(1, $result['projections']);
        self::assertSame('json-campaign', $result['projections'][0]['campaign_id']);
        self::assertSame(4.0, $result['projections'][0]['metrics']['roas']);
    }

    public function testZeroSpendCannotProduceRoasEvenWhenSourceStoredOne(): void
    {
        $result = $this->service([
            $this->row(115, 'advertising', [
                'campaignId' => 'zero-spend',
                'spend' => 0,
                'attributedOrderAmount' => 100,
                'attributionBasis' => 'same-day-paid-order',
                'roas' => 9,
            ], amount: 0),
        ])->project(10, 80, '2026-08-30');

        self::assertSame('partial', $result['status']);
        self::assertSame(0.0, $result['projections'][0]['metrics']['spend']);
        self::assertNull($result['projections'][0]['metrics']['roas']);
        self::assertSame('spend_not_positive', $result['projections'][0]['metrics']['roas_status']);
    }

    public function testStoredAndRawSpendConflictFailsClosed(): void
    {
        $result = $this->service([
            $this->row(116, 'advertising', [
                'campaignId' => 'spend-conflict',
                'spend' => 120,
                'attributedOrderAmount' => 600,
                'attributionBasis' => 'same-day-paid-order',
            ], amount: 100),
        ])->project(10, 80, '2026-08-30');

        self::assertSame('partial', $result['status']);
        self::assertNull($result['projections'][0]['metrics']['spend']);
        self::assertNull($result['projections'][0]['metrics']['roas']);
        self::assertSame('conflict', $result['projections'][0]['metrics']['basis_status']);
        self::assertSame('spend_value_conflict', $result['projections'][0]['metrics']['roas_status']);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function service(array $rows): MeituanMarketingFactProjectionService
    {
        return new MeituanMarketingFactProjectionService(
            static fn(int $tenantId, int $hotelId, string $date): array => $rows
        );
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function row(
        int $id,
        string $dataType,
        array $raw,
        ?float $amount = null,
        string $dimension = '',
        string $snapshot = '2026-08-30 10:00:00'
    ): array {
        return [
            'id' => $id,
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'hotel_id' => 'poi-80',
            'data_date' => '2026-08-30',
            'source' => 'meituan',
            'platform' => 'Meituan',
            'data_type' => $dataType,
            'dimension' => $dimension,
            'amount' => $amount,
            'list_exposure' => $raw['impressions'] ?? null,
            'detail_exposure' => $raw['clicks'] ?? null,
            'raw_data' => $raw,
            'history_status' => 'success',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'source_trace_id' => 'meituan-marketing-' . $id,
            'snapshot_time' => $snapshot,
            'data_period' => 'same_day_cumulative',
            'ingestion_method' => 'browser_profile',
        ];
    }
}
