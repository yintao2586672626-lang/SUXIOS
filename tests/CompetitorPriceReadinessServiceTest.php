<?php
declare(strict_types=1);

namespace Tests;

use app\service\CompetitorPriceReadinessService;
use PHPUnit\Framework\TestCase;

final class CompetitorPriceReadinessServiceTest extends TestCase
{
    public function testMissingPriceRequiresRecheck(): void
    {
        $readiness = (new CompetitorPriceReadinessService())->buildPriceSignalReadiness([
            'analysis_date' => date('Y-m-d'),
            'room_type_id' => 2,
            'our_price' => 0,
            'competitor_price' => 260,
        ]);

        self::assertSame('competitor_price_missing', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['price_sample'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testMaterialGapRequiresPricingSuggestion(): void
    {
        $readiness = (new CompetitorPriceReadinessService())->buildPriceSignalReadiness([
            'analysis_date' => date('Y-m-d'),
            'room_type_id' => 2,
            'our_price' => 320,
            'competitor_price' => 260,
        ]);

        self::assertSame('competitor_not_priced', $readiness['stage']);
        self::assertTrue($readiness['material_gap']);
        self::assertSame(23.08, $readiness['price_gap_percent']);
        self::assertSame(['price_suggestion'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testHistoricalSnapshotMustBeRefreshedBeforePricing(): void
    {
        $readiness = (new CompetitorPriceReadinessService())->buildPriceSignalReadiness([
            'analysis_date' => date('Y-m-d', strtotime('-5 days')),
            'room_type_id' => 2,
            'our_price' => 320,
            'competitor_price' => 260,
        ], [
            'suggestion_count' => 1,
            'approved_count' => 1,
        ]);

        self::assertSame('competitor_price_stale', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['fresh_competitor_snapshot'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testAppliedSuggestionStillRequiresOutcomeReview(): void
    {
        $readiness = (new CompetitorPriceReadinessService())->buildPriceSignalReadiness([
            'analysis_date' => date('Y-m-d'),
            'room_type_id' => 2,
            'our_price' => 260,
            'competitor_price' => 320,
        ], [
            'suggestion_count' => 2,
            'approved_count' => 2,
            'applied_count' => 1,
            'latest_suggestion_at' => '2026-06-14 12:00:00',
        ]);

        self::assertSame('competitor_pricing_applied', $readiness['stage']);
        self::assertTrue($readiness['execution_ready']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(1, $readiness['applied_count']);
        self::assertSame(['pricing_outcome'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testEnrichPriceMatrixKeepsMatrixShapeAndAddsReadiness(): void
    {
        $service = new CompetitorPriceReadinessService();
        $matrix = $service->enrichPriceMatrix([
            '大床房' => [
                '竞对A' => [
                    'analysis_date' => date('Y-m-d'),
                    'room_type_id' => 7,
                    'our_price' => 300,
                    'competitor_price' => 285,
                ],
            ],
        ], [
            7 => ['suggestion_count' => 1, 'pending_count' => 1],
        ]);

        self::assertArrayHasKey('大床房', $matrix);
        self::assertArrayHasKey('竞对A', $matrix['大床房']);
        self::assertSame('competitor_pricing_linked', $matrix['大床房']['竞对A']['price_signal_readiness']['stage']);
        self::assertSame([7], $service->roomTypeIdsFromPriceMatrix($matrix));
    }
}
