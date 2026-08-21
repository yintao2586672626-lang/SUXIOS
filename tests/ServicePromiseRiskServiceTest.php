<?php
declare(strict_types=1);

namespace Tests;

use app\service\ServicePromiseRiskService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServicePromiseRiskServiceTest extends TestCase
{
    public function testRiskIsDetectedFromVerifiedFacts(): void
    {
        $result = (new ServicePromiseRiskService())->evaluate($this->validInput([
            'promised_quantity' => 10,
            'fulfillable_capacity' => 7,
            'breach_cost_per_unit' => 80.50,
        ]));

        self::assertSame('risk_detected', $result['status']);
        self::assertSame(3, $result['shortage_quantity']);
        self::assertNull($result['surplus_quantity']);
        self::assertSame(241.5, $result['risk_amount']);
        self::assertSame(
            ServicePromiseRiskService::CONTRACT_VERSION,
            $result['contract_version']
        );
        self::assertSame('declared_service_promise_only', $result['source_boundary']['fact_scope']);
        self::assertFalse($result['source_boundary']['whole_hotel_fact']);
        self::assertSame(['ota-benefit-snapshot#20260822-breakfast'], $result['source_boundary']['source_references']);
        self::assertSame([], $result['missing_facts']);
    }

    public function testAvailableCapacityReturnsSurplusAndCalculatedZeroRisk(): void
    {
        $result = (new ServicePromiseRiskService())->evaluate($this->validInput([
            'promised_quantity' => 8,
            'fulfillable_capacity' => 11,
        ]));

        self::assertSame('capacity_available', $result['status']);
        self::assertNull($result['shortage_quantity']);
        self::assertSame(3, $result['surplus_quantity']);
        self::assertSame(0.0, $result['risk_amount']);
        self::assertStringContainsString('3 份可履约余量', $result['recommendation_draft']['summary']);
    }

    public function testMissingFactsStayBlockedAndAreNotReplacedWithZero(): void
    {
        $input = $this->validInput();
        unset($input['fulfillable_capacity']);
        $input['source_references'] = [];

        $result = (new ServicePromiseRiskService())->evaluate($input);

        self::assertSame('blocked_by_missing_facts', $result['status']);
        self::assertContains('fulfillable_capacity', $result['missing_facts']);
        self::assertContains('source_references', $result['missing_facts']);
        self::assertNull($result['shortage_quantity']);
        self::assertNull($result['surplus_quantity']);
        self::assertNull($result['risk_amount']);
        self::assertNull($result['source_boundary']['source_references']);
    }

    public function testUnverifiedSourceQualityBlocksOtherwiseCompleteCalculation(): void
    {
        $result = (new ServicePromiseRiskService())->evaluate($this->validInput([
            'source_quality' => 'partial',
        ]));

        self::assertSame('blocked_by_missing_facts', $result['status']);
        self::assertSame(['source_quality_not_verified'], $result['missing_facts']);
        self::assertNull($result['risk_amount']);
        self::assertSame('partial', $result['source_boundary']['source_quality']);
    }

    /** @param array<string,mixed> $override */
    #[DataProvider('invalidInputProvider')]
    public function testInvalidProvidedFactsAreRejected(array $override, string $expectedField): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedField);

        (new ServicePromiseRiskService())->evaluate($this->validInput($override));
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'invalid business date' => [['business_date' => '2026-02-30'], 'business_date'];
        yield 'negative promised quantity' => [['promised_quantity' => -1], 'promised_quantity'];
        yield 'fractional capacity' => [['fulfillable_capacity' => 1.5], 'fulfillable_capacity'];
        yield 'negative breach cost' => [['breach_cost_per_unit' => -0.01], 'breach_cost_per_unit'];
        yield 'non-string source quality' => [['source_quality' => ['verified']], 'source_quality'];
        yield 'malformed source references' => [
            ['source_references' => ['primary' => 'ota-benefit-snapshot#1']],
            'source_references',
        ];
    }

    public function testInputCannotCreateExecutionAuthorityOrOtaWritePermission(): void
    {
        $result = (new ServicePromiseRiskService())->evaluate($this->validInput([
            'execution_authorized' => true,
            'ota_write_allowed' => true,
            'approval_token' => 'must-not-propagate',
        ]));

        $draft = $result['recommendation_draft'];
        self::assertSame('read_only', $draft['mode']);
        self::assertFalse($draft['execution_authorized']);
        self::assertFalse($draft['ota_write_allowed']);
        self::assertSame('none', $draft['external_action']);
        self::assertArrayNotHasKey('execution_intent', $result);
        self::assertArrayNotHasKey('approval_token', $result);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('must-not-propagate', $encoded);
    }

    public function testCalculatorInputBudgetAcceptsFiftyRefsAndRejectsFiftyOne(): void
    {
        $service = new ServicePromiseRiskService();
        $fifty = array_map(static fn(int $index): string => 'promise-ref#' . $index, range(1, 50));
        self::assertSame('risk_detected', $service->evaluate($this->validInput([
            'source_references' => $fifty,
        ]))['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会来源引用不能超过50条');
        $service->evaluate($this->validInput([
            'source_references' => [...$fifty, 'promise-ref#51'],
        ]));
    }

    public function testCalculatorInputBudgetRejectsUnboundedObservationOrTextPayloads(): void
    {
        $service = new ServicePromiseRiskService();
        try {
            $service->evaluate($this->validInput([
                'observations' => array_fill(0, 101, []),
            ]));
            self::fail('101 observations must be rejected');
        } catch (InvalidArgumentException $error) {
            self::assertSame('经营机会观察记录不能超过100条', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会单条文本不能超过1000字符');
        $service->evaluate($this->validInput([
            'benefit_type' => str_repeat('权', 1001),
        ]));
    }

    /**
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function validInput(array $override = []): array
    {
        return array_replace([
            'business_date' => '2026-08-22',
            'benefit_type' => 'breakfast',
            'promised_quantity' => 10,
            'fulfillable_capacity' => 7,
            'breach_cost_per_unit' => 80,
            'source_quality' => 'readback_verified',
            'source_references' => ['ota-benefit-snapshot#20260822-breakfast'],
        ], $override);
    }
}
