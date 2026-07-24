<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripCompetitiveOperationsService;
use PHPUnit\Framework\TestCase;

final class CtripCompetitiveOperationsServiceTest extends TestCase
{
    public function testBuildsComparisonTrendRankingFunnelAndRuleBasedAnomalies(): void
    {
        $businessRows = [];
        $trafficRows = [];
        $dates = ['2026-07-12', '2026-07-13', '2026-07-14', '2026-07-15'];
        $selfAmounts = [1000, 1000, 1000, 400];
        $selfRanks = [2, 2, 2, 8];
        $selfExposure = [1000, 1000, 1000, 400];

        foreach ($dates as $index => $date) {
            $businessRows[] = $this->businessRow(
                $index * 10 + 1,
                $date,
                '1001',
                '我的酒店',
                'self',
                $selfAmounts[$index],
                10,
                8,
                $selfRanks[$index]
            );
            $businessRows[] = $this->businessRow($index * 10 + 2, $date, '2001', '竞品A', 'competitor', 900, 9, 7, 3);
            $businessRows[] = $this->businessRow($index * 10 + 3, $date, '2002', '竞品B', 'competitor', 1100, 10, 8, 1);

            $trafficRows[] = $this->trafficRow($index * 10 + 4, $date, '1001', 'self', $selfExposure[$index], 200, 40, 20);
            $trafficRows[] = $this->trafficRow($index * 10 + 5, $date, '-1', 'competitor_avg', 1000, 300, 60, 30);
        }

        $profiles = [[
            'ota_hotel_id' => '2001',
            'capture_status' => 'partial',
            'fields' => ['name' => '竞品A', 'room_count' => 88],
        ]];
        $result = (new CtripCompetitiveOperationsService())->analyzeRows(
            $businessRows,
            $trafficRows,
            $profiles,
            '1001',
            ['system_hotel_id' => 7]
        );

        self::assertSame('available', $result['status']);
        self::assertSame('2026-07-15', $result['business_comparison']['latest_date']);
        self::assertSame(400.0, $result['business_comparison']['self']['amount']);
        self::assertSame(40.0, $result['business_comparison']['self']['adr']);
        self::assertSame(1000.0, $result['business_comparison']['competitor_average']['amount']);
        self::assertCount(4, $result['trend']['rows']);

        $selfRank = $result['rank_monitoring']['items'][0];
        self::assertSame('self', $selfRank['compare_type']);
        self::assertSame(8, $selfRank['latest']['ranks']['revenue_rank']);
        self::assertSame(-6, $selfRank['changes']['revenue_rank']);

        self::assertSame(23.53, $result['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertSame(30.0, $result['traffic_funnel_comparison']['competitor_average']['detail_entry_rate']);
        self::assertSame('anomaly_detected', $result['anomaly_diagnosis']['status']);
        self::assertContains('revenue_anomaly', array_column($result['anomaly_diagnosis']['items'], 'type'));
        self::assertContains('traffic_anomaly', array_column($result['anomaly_diagnosis']['items'], 'type'));
        self::assertContains('rank_anomaly', array_column($result['anomaly_diagnosis']['items'], 'type'));
        self::assertSame(2, $result['competitor_profiles']['competitor_count']);
        self::assertSame(1, $result['competitor_profiles']['profiled_competitor_count']);
        self::assertSame(50.0, $result['competitor_profiles']['coverage_rate']);
        self::assertSame('ctrip_ota_competition_circle_only', $result['source_scope']);
    }

    public function testUnverifiedRowsRemainVisibleButCannotBecomeDiagnosisEvidence(): void
    {
        $row = $this->businessRow(1, '2026-07-15', '1001', '我的酒店', 'self', 1000, 10, 8, 1);
        $row['validation_status'] = 'unverified';

        $result = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '1001');

        self::assertSame(1, $result['data_coverage']['business_row_count']);
        self::assertSame(0, $result['data_coverage']['business_usable_count']);
        self::assertSame(0, $result['data_coverage']['decision_eligible_row_count']);
        self::assertSame(1, $result['data_coverage']['excluded_from_decision_count']);
        self::assertSame('insufficient_evidence', $result['data_coverage']['decision_gate']);
        self::assertSame('data_missing', $result['business_comparison']['status']);
        self::assertNull($result['business_comparison']['self']);
        self::assertSame('data_missing', $result['trend']['status']);
        self::assertSame('data_missing', $result['rank_monitoring']['status']);
        self::assertSame('insufficient_data', $result['anomaly_diagnosis']['status']);
        self::assertSame(['unverified' => 1], $result['data_coverage']['quality_status_counts']);
    }

    public function testMissingReadbackProofAndFailedProfilesNeverBecomeUsableCoverage(): void
    {
        $row = $this->businessRow(1, '2026-07-15', '1001', '我的酒店', 'self', 1000, 10, 8, 1);
        $row['readback_verified'] = 0;
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [[
            'ota_hotel_id' => '2001',
            'capture_status' => 'collection_failed',
            'fields' => ['name' => '失败档案'],
        ]], '1001');

        self::assertSame('unverified', $result['status']);
        self::assertSame(0, $result['data_coverage']['business_usable_count']);
        self::assertSame(['readback_unverified' => 1], $result['data_coverage']['quality_status_counts']);
        self::assertSame('unverified', $result['competitor_profiles']['status']);
        self::assertSame(0, $result['competitor_profiles']['usable_profile_count']);
        self::assertSame(0, $result['competitor_profiles']['profiled_competitor_count']);
    }

    public function testPartialValidationStatusIsVisibleButNotDiagnosisEvidence(): void
    {
        $row = $this->businessRow(1, '2026-07-15', '1001', '我的酒店', 'self', 1000, 10, 8, 1);
        $row['validation_status'] = 'partial';
        $result = (new CtripCompetitiveOperationsService())->analyzeRows([$row], [], [], '1001');

        self::assertSame('unverified', $result['status']);
        self::assertSame(0, $result['data_coverage']['business_usable_count']);
        self::assertSame(['partial' => 1], $result['data_coverage']['quality_status_counts']);
    }

    public function testMixedQualityFunnelExcludesUnverifiedRowFromSummary(): void
    {
        $rows = [];
        foreach (['2026-07-12', '2026-07-13', '2026-07-14', '2026-07-15'] as $index => $date) {
            $self = $this->trafficRow($index * 10 + 1, $date, '1001', 'self', 1000, 200, 40, 20);
            if ($date === '2026-07-15') {
                $self['validation_status'] = 'unverified';
                $self['raw_data'] = json_encode([
                    'hotelId' => '1001',
                    'compareType' => 'self',
                    'listExposure' => 10000,
                    'detailExposure' => 9000,
                    'orderFillingNum' => 8000,
                    'orderSubmitNum' => 7000,
                ], JSON_UNESCAPED_UNICODE);
            }
            $rows[] = $self;
            $rows[] = $this->trafficRow($index * 10 + 2, $date, '-1', 'competitor_avg', 1000, 300, 60, 30);
        }

        $result = (new CtripCompetitiveOperationsService())->analyzeRows([], $rows, [], '1001');

        self::assertSame('usable', $result['traffic_funnel_comparison']['self']['quality_status']);
        self::assertSame('usable', $result['traffic_funnel_comparison']['competitor_average']['quality_status']);
        self::assertSame(20.0, $result['traffic_funnel_comparison']['self']['detail_entry_rate']);
        self::assertSame(1, $result['data_coverage']['excluded_from_decision_count']);
        self::assertSame('usable_evidence_only', $result['data_coverage']['decision_gate']);
    }

    /** @return array<string,mixed> */
    private function businessRow(
        int $id,
        string $date,
        string $hotelId,
        string $name,
        string $compareType,
        float $amount,
        int $quantity,
        int $orders,
        int $rank
    ): array {
        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'hotel_name' => $name,
            'data_date' => $date,
            'amount' => $amount,
            'quantity' => $quantity,
            'book_order_num' => $orders,
            'compare_type' => $compareType,
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'hotelId' => (int)$hotelId,
                'hotelName' => $name,
                'amount' => $amount,
                'quantity' => $quantity,
                'bookOrderNum' => $orders,
                'amountRank' => $rank,
                'quantityRank' => $rank,
                'bookOrderNumRank' => $rank,
                'totalDetailNum' => 200,
                'convertionRate' => 10,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string,mixed> */
    private function trafficRow(
        int $id,
        string $date,
        string $hotelId,
        string $compareType,
        int $list,
        int $detail,
        int $orders,
        int $submits
    ): array {
        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'data_date' => $date,
            'compare_type' => $compareType,
            'list_exposure' => $list,
            'detail_exposure' => $detail,
            'order_filling_num' => $orders,
            'order_submit_num' => $submits,
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'hotelId' => $hotelId,
                'compareType' => $compareType,
                'listExposure' => $list,
                'detailExposure' => $detail,
                'orderFillingNum' => $orders,
                'orderSubmitNum' => $submits,
            ], JSON_UNESCAPED_UNICODE),
        ];
    }
}
