<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePricingRecommendationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenuePricingRiskReadinessTest extends TestCase
{
    private function row(array $risk = []): array
    {
        return ['id' => 91, 'status' => 2, 'factors' => array_merge([
            'signals' => ['data_gaps' => []], 'primary_signal_count' => 2,
            'decision_boundary' => 'manual_review_required_no_auto_rate_write',
        ], $risk)];
    }

    public static function unverifiedRisk(): iterable
    {
        yield 'missing both' => [[]];
        yield 'missing confidence' => [['risk_level' => 'low']];
        yield 'blank confidence' => [['risk_level' => 'low', 'confidence_score' => '']];
        yield 'missing risk' => [['confidence_score' => 0.8]];
        yield 'unknown risk' => [['risk_level' => 'unknown', 'confidence_score' => 0.8]];
        yield 'legacy 50 percent' => [['risk_level' => 'low', 'confidence_score' => 50]];
        yield 'legacy 59 percent text' => [['risk_level' => 'medium', 'confidence_score' => '59']];
        yield 'outside confidence range' => [['risk_level' => 'low', 'confidence_score' => 101]];
        yield 'overflow confidence' => [['risk_level' => 'low', 'confidence_score' => '1e309']];
        yield 'boolean confidence' => [['risk_level' => 'low', 'confidence_score' => true]];
        yield 'one percent confidence' => [['risk_level' => 'low', 'confidence_score' => '1%']];
        yield 'malformed percent confidence' => [['risk_level' => 'low', 'confidence_score' => '8%0']];
        yield 'structured confidence' => [['risk_level' => 'low', 'confidence_score' => [0.8]]];
        yield 'structured risk' => [['risk_level' => ['low'], 'confidence_score' => 0.8]];
        yield 'explicit zero confidence' => [['risk_level' => 'low', 'confidence_score' => 0]];
        yield 'high risk' => [['risk_level' => 'high', 'confidence_score' => 0.9]];
    }

    #[DataProvider('unverifiedRisk')]
    public function testMissingInvalidOrLowRiskEvidenceCannotPassReadiness(array $risk): void
    {
        $result = (new RevenuePricingRecommendationService())->buildSuggestionReadiness($this->row($risk));
        self::assertSame('data_recheck_required', $result['stage']);
        self::assertFalse($result['execution_intent_ready']);
        self::assertFalse($result['ready_for_review']);
        self::assertContains('risk_recheck', array_column($result['missing_evidence'], 'code'));
    }

    public function testVerifiedLegacyAndCurrentConfidenceCanRecoverWithKnownRisk(): void
    {
        foreach ([0.6, 0.8, 1, 60, 80, 100, '80', '80%', '100%'] as $confidence) {
            foreach (['low', 'medium'] as $risk) {
                $result = (new RevenuePricingRecommendationService())->buildSuggestionReadiness($this->row([
                    'risk_level' => $risk, 'confidence_score' => $confidence,
                ]));
                self::assertSame('approved_pending_execution', $result['stage']);
                self::assertTrue($result['execution_intent_ready']);
                self::assertNotContains('risk_recheck', array_column($result['missing_evidence'], 'code'));
            }
        }
    }

    public function testSavedFactorsOverrideRowAndLegacyRowsRemainSupported(): void
    {
        $service = new RevenuePricingRecommendationService();
        $row = $this->row(['risk_level' => 'low', 'confidence_score' => 0]);
        $row['confidence_score'] = 0.9;
        $row['risk_level'] = 'low';
        self::assertSame('data_recheck_required', $service->buildSuggestionReadiness($row)['stage']);
        unset($row['factors']['confidence_score'], $row['factors']['risk_level']);
        self::assertSame('approved_pending_execution', $service->buildSuggestionReadiness($row)['stage']);
    }

    public function testMissingRiskCannotAppearClosedEvenWhenExecutionEvidenceExists(): void
    {
        $row = $this->row();
        $row['status'] = 4;
        $result = (new RevenuePricingRecommendationService())->buildSuggestionReadiness($row, [
            'stage' => 'reviewed', 'evidence' => ['count' => 2], 'roi' => ['status' => 'ready'],
        ]);
        self::assertSame('data_recheck_required', $result['stage']);
        self::assertFalse($result['pricing_ready']);
    }

    public function testPersistedRowEnrichmentExposesRecheckLabelAndCanRecover(): void
    {
        $row = array_merge($this->row(), [
            'hotel_name' => 'Fixture hotel', 'room_type_id' => 501, 'suggestion_date' => '2026-09-27',
            'current_price' => 200, 'suggested_price' => 220,
        ]);
        $service = new RevenuePricingRecommendationService();
        $enriched = $service->enrichSuggestionRows([$row])[0];
        self::assertSame('需数据复核', $enriched['pricing_readiness']['status_label']);
        self::assertStringContainsString('有效置信度', $enriched['pricing_readiness']['next_action']);
        self::assertFalse($enriched['pricing_readiness']['execution_intent_ready']);
        $row['factors']['risk_level'] = 'low';
        $row['factors']['confidence_score'] = 80;
        $enriched = $service->enrichSuggestionRows([$row])[0];
        self::assertSame('已批待转执行', $enriched['pricing_readiness']['status_label']);
        self::assertSame(501, $enriched['room_type_id']);
        self::assertSame('2026-09-27', $enriched['suggestion_date']);
    }
}
