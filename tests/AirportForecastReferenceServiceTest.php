<?php
declare(strict_types=1);

namespace Tests;

use app\service\AirportForecastReferenceService;
use app\service\KnowledgeDecisionGateService;
use PHPUnit\Framework\TestCase;

final class AirportForecastReferenceServiceTest extends TestCase
{
    public function testVisibleValuesRemainSourceReferenceWithoutCalculation(): void
    {
        $service = new AirportForecastReferenceService();
        self::assertFalse(method_exists($service, 'calculateSignedError'));

        $definition = $service->definition();
        $visible = $definition['chunks']['airport_forecast_visible_field_contract'];
        self::assertFalse($visible['calculation_performed']);
        self::assertCount(4, $visible['visible_2025_samples']);
        self::assertSame(8515, $visible['visible_2025_samples'][0]['forecast_wan_passenger_trips']);
        self::assertSame(8499, $visible['visible_2025_samples'][0]['actual_wan_passenger_trips']);
        self::assertSame(16, $visible['visible_2025_samples'][0]['visible_error_wan_passenger_trips']);
        self::assertSame(0.19, $visible['visible_2025_samples'][0]['visible_error_ratio_percent']);
        self::assertArrayNotHasKey('recalculated', $visible['visible_2025_samples'][0]);

        $errorDefinition = $definition['chunks']['airport_forecast_visible_error_definition'];
        self::assertSame('作者把误差比描述为误差量与实际客流量的比值。', $errorDefinition['source_definition']);
        self::assertFalse($errorDefinition['algorithm_present_in_source']);
        self::assertFalse($errorDefinition['calculation_implemented']);
        self::assertFalse($errorDefinition['formula_inferred']);
        self::assertNull($errorDefinition['rounding_rule']);
        self::assertNull($errorDefinition['error_sign_convention']);
    }

    public function testAuthorNarrativeIsStoredWithoutInventingAlgorithm(): void
    {
        $method = (new AirportForecastReferenceService())
            ->definition()['chunks']['airport_forecast_author_method_description'];

        self::assertSame('author_narrative_not_reproduced', $method['method_status']);
        self::assertSame('not_provided', $method['algorithm_status']);
        self::assertNull($method['equations']);
        self::assertNull($method['parameters']);
        self::assertNull($method['weights']);
        self::assertNull($method['monthly_procedure']);
        self::assertSame(['2024年全年', '2025年上半年'], $method['2025_author_description']['input_periods']);
        self::assertSame(['客流量', '运力', '客座率'], $method['2025_author_description']['monthly_inputs']);
        self::assertSame('2025年下半年', $method['2025_author_description']['stated_output_period']);
        self::assertSame(['2025年全年', '2026年1至7月'], $method['2026_author_description']['input_periods']);
        self::assertSame('2026年8至12月', $method['2026_author_description']['stated_output_period']);
        self::assertContains('成都两场运力再分配', $method['2026_author_description']['stated_interference_factors']);
        self::assertContains('深圳机场临时时刻减容', $method['2026_author_description']['stated_interference_factors']);
        self::assertSame('author_explanation_not_independently_verified', $method['2025_author_explanations'][0]['causal_status']);
        self::assertSame('not_implemented', $method['implementation_status']);

        $claims = $method['author_forecast_claims'];
        self::assertCount(10, $claims);
        $claimsByKey = array_column($claims, null, 'claim_key');
        self::assertCount(10, $claimsByKey);
        self::assertSame(
            'author_forecast_not_independently_verified',
            $claimsByKey['guangzhou_domestic_rank_and_90m_milestone']['claim_status']
        );
        self::assertStringContainsString(
            '第9升至第4',
            $claimsByKey['guangzhou_global_rank_change']['author_claim']
        );
        self::assertStringContainsString(
            '突破8800万人次',
            $claimsByKey['pudong_domestic_rank_and_throughput']['author_claim']
        );
        self::assertStringContainsString(
            '第5降至第6',
            $claimsByKey['pudong_global_rank_change']['author_claim']
        );
        self::assertStringContainsString(
            '反超5座大陆机场',
            $claimsByKey['taoyuan_rank_and_peak']['author_claim']
        );
        self::assertStringContainsString('上升5位', $claimsByKey['urumqi_rank_change']['author_claim']);
        self::assertStringContainsString('由第4升至第3', $claimsByKey['shijiazhuang_rank_change']['author_claim']);
        self::assertStringContainsString('下降3位', $claimsByKey['wenzhou_rank_change']['author_claim']);
        foreach ($claims as $claim) {
            self::assertStringContainsString(
                'not_independently_verified',
                (string)$claim['claim_status']
            );
            self::assertArrayNotHasKey('calculated_value', $claim);
        }

        $summary = (new AirportForecastReferenceService())
            ->definition()['chunks']['airport_forecast_visible_error_definition']['author_reported_summary'];
        self::assertSame(43, $summary['million_airport_count']);
        self::assertSame(23, $summary['error_ratio_within_1_percent_count']);
        self::assertSame(33, $summary['error_ratio_within_2_percent_count']);
    }

    public function testEveryChunkIsStorageOnlyAndBlocksInferenceOrExecution(): void
    {
        $service = new AirportForecastReferenceService();
        $definition = $service->definition();
        self::assertCount(5, $definition['chunks']);
        self::assertSame(0, $definition['unit']['hotel_id']);
        self::assertSame(0, $definition['unit']['created_by']);

        foreach ($definition['chunks'] as $content) {
            $assessment = (new KnowledgeDecisionGateService())->assess(
                $definition['unit'],
                $content,
                AirportForecastReferenceService::REVIEWED_AT
            );
            self::assertSame('reference_only', $assessment['status']);
            self::assertTrue($assessment['retrieval_safe']);
            self::assertFalse($assessment['decision_safe']);
            self::assertFalse($assessment['task_draft_safe']);
            self::assertFalse($content['contains_current_hotel_fact']);
            self::assertFalse($content['contains_current_ota_fact']);
            self::assertFalse($content['contains_current_airport_fact']);
            self::assertFalse($content['contains_algorithm_implementation']);
            self::assertFalse($content['contains_derived_forecast']);
            self::assertFalse($content['external_write_authorized']);
            self::assertContains('forecast_generation', $content['blocked_uses']);
            self::assertContains('metric_derivation', $content['blocked_uses']);
            self::assertContains('operation_execution', $content['blocked_uses']);
        }

        $boundary = $definition['chunks']['airport_forecast_hotel_boundary'];
        self::assertContains('do_not_generate_airport_forecast', $boundary['do_not_infer']);
        self::assertContains('do_not_calculate_missing_values', $boundary['do_not_infer']);
        self::assertContains('do_not_create_hotel_demand_signal', $boundary['do_not_infer']);
    }
}
