<?php
declare(strict_types=1);

namespace Tests;

use app\service\PromotionIncrementalityService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromotionIncrementalityServiceTest extends TestCase
{
    public function testPositiveIncrementWithQualifiedEvidenceIsSupported(): void
    {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput());

        self::assertSame('supported', $result['verdict']);
        self::assertSame(['positive_increment_and_net_profit_estimate'], $result['reason_codes']);
        self::assertSame(40.0, $result['treated_change']);
        self::assertSame(10.0, $result['control_change']);
        self::assertSame(30.0, $result['incremental_room_nights']);
        self::assertSame(1500.0, $result['incremental_contribution']);
        self::assertSame(1100.0, $result['net_incremental_profit']);
        self::assertTrue($result['design_assessment']['evidence_threshold_met']);
        self::assertFalse(
            $result['platform_attribution_distinction']['platform_attribution_equals_incrementality']
        );
        self::assertFalse($result['evidence_boundary']['causality_claimed']);
        self::assertFalse($result['evidence_boundary']['statistical_significance_tested']);
        self::assertSame(PromotionIncrementalityService::CONTRACT_VERSION, $result['contract_version']);
        self::assertStringContainsString('不证明促销导致结果', $result['evidence_boundary']['statement']);
    }

    public function testNegativeIncrementWithQualifiedMatchedDesignIsContradicted(): void
    {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput([
            'treated_after' => 95,
            'control_after' => 90,
            'discount_cost' => 200,
            'design_quality' => 'validated_matched',
        ]));

        self::assertSame('contradicted', $result['verdict']);
        self::assertSame(['negative_net_profit_estimate'], $result['reason_codes']);
        self::assertSame(-5.0, $result['treated_change']);
        self::assertSame(10.0, $result['control_change']);
        self::assertSame(-15.0, $result['incremental_room_nights']);
        self::assertSame(-750.0, $result['incremental_contribution']);
        self::assertSame(-950.0, $result['net_incremental_profit']);
        self::assertTrue($result['design_assessment']['evidence_threshold_met']);
        self::assertFalse($result['evidence_boundary']['causality_claimed']);
    }

    public function testInsufficientEvidenceStaysIndeterminateWhileKeepingTheEstimateVisible(): void
    {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput([
            'design_quality' => 'unverified',
            'pretrend_status' => 'unverified',
            'sample_size' => 12,
            'source_quality' => 'partial',
        ]));

        self::assertSame('indeterminate', $result['verdict']);
        self::assertSame(
            [
                'design_quality_unverified',
                'pretrend_unverified',
                'sample_size_below_minimum',
                'source_quality_insufficient',
            ],
            $result['reason_codes']
        );
        self::assertSame(30.0, $result['incremental_room_nights']);
        self::assertSame(1500.0, $result['incremental_contribution']);
        self::assertSame(1100.0, $result['net_incremental_profit']);
        self::assertFalse($result['design_assessment']['evidence_threshold_met']);
        self::assertFalse($result['evidence_boundary']['causality_claimed']);
    }

    #[DataProvider('invalidInputProvider')]
    public function testInvalidInputIsRejected(array $input, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        (new PromotionIncrementalityService())->evaluate($input);
    }

    /** @return iterable<string, array{0:array<string,mixed>,1:string}> */
    public static function invalidInputProvider(): iterable
    {
        $missingTreatedBefore = self::validInput();
        unset($missingTreatedBefore['treated_before']);
        yield 'required metric missing' => [
            $missingTreatedBefore,
            'promotion_incrementality_missing_field:treated_before',
        ];

        yield 'invalid business date' => [
            self::validInput(['business_date' => '2026-02-30']),
            'promotion_incrementality_date_invalid:business_date',
        ];

        yield 'negative discount cost' => [
            self::validInput(['discount_cost' => -1]),
            'promotion_incrementality_non_negative_number_required:discount_cost',
        ];

        yield 'fractional sample size' => [
            self::validInput(['sample_size' => 30.5]),
            'promotion_incrementality_positive_integer_required:sample_size',
        ];
    }

    #[DataProvider('unrecognizedStatusProvider')]
    public function testUnrecognizedStatusFailsClosedAsIndeterminate(
        string $field,
        string $expectedReason
    ): void {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput([
            $field => 'unexpected_status',
        ]));

        self::assertSame('indeterminate', $result['verdict']);
        self::assertContains($expectedReason, $result['reason_codes']);
        self::assertFalse($result['design_assessment']['evidence_threshold_met']);
        self::assertFalse($result['evidence_boundary']['causality_claimed']);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function unrecognizedStatusProvider(): iterable
    {
        yield 'design quality' => ['design_quality', 'design_quality_unrecognized'];
        yield 'pretrend status' => ['pretrend_status', 'pretrend_status_unrecognized'];
        yield 'source quality' => ['source_quality', 'source_quality_unrecognized'];
    }

    public function testZeroDifferenceRemainsIndeterminateEvenWithQualifiedEvidence(): void
    {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput([
            'treated_after' => 110,
            'control_after' => 90,
            'discount_cost' => 0,
        ]));

        self::assertSame('indeterminate', $result['verdict']);
        self::assertSame(['net_incremental_profit_zero'], $result['reason_codes']);
        self::assertSame(0.0, $result['incremental_room_nights']);
        self::assertTrue($result['design_assessment']['evidence_threshold_met']);
    }

    public function testPositiveIncrementThatLosesMoneyIsContradicted(): void
    {
        $result = (new PromotionIncrementalityService())->evaluate(self::validInput([
            'discount_cost' => 2500,
        ]));

        self::assertSame(30.0, $result['incremental_room_nights']);
        self::assertSame(-1000.0, $result['net_incremental_profit']);
        self::assertSame('contradicted', $result['verdict']);
        self::assertSame(['positive_increment_but_negative_net_profit'], $result['reason_codes']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function validInput(array $overrides = []): array
    {
        return [
            'promotion_name' => '暑期连住优惠',
            'business_date' => '2026-08-21',
            'treated_before' => 100,
            'treated_after' => 140,
            'control_before' => 80,
            'control_after' => 90,
            'discount_cost' => 400,
            'contribution_per_incremental_room_night' => 50,
            'design_quality' => 'randomized',
            'pretrend_status' => 'passed',
            'sample_size' => 80,
            'source_quality' => 'verified',
            ...$overrides,
        ];
    }
}
