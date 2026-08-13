<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\OtaDiagnosisRequestedPeriodGateService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AgentOtaDiagnosisRuntimeModeTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): Agent
    {
        return (new ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
    }

    public function testExplicitRulesOnlyNeverRequestsModelCall(): void
    {
        $runtime = $this->invokeNonPublic(
            $this->controller(),
            'resolveOtaDiagnosisAnalysisRuntime',
            ['rules_only', true]
        );

        self::assertSame('deterministic_rules', $runtime['mode']);
        self::assertTrue($runtime['use_rules_only']);
        self::assertFalse($runtime['model_called']);
        self::assertSame('', $runtime['fallback_reason']);
        self::assertTrue($runtime['rules_evidence_guard_applied']);
    }

    public function testAutoModeFallsBackToRulesWhenModelIsUnavailable(): void
    {
        $runtime = $this->invokeNonPublic(
            $this->controller(),
            'resolveOtaDiagnosisAnalysisRuntime',
            ['auto', false]
        );

        self::assertSame('deterministic_rules', $runtime['mode']);
        self::assertTrue($runtime['use_rules_only']);
        self::assertFalse($runtime['model_allowed']);
        self::assertSame('model_not_available', $runtime['fallback_reason']);
    }

    public function testAutoModeKeepsModelAugmentationWhenModelIsAvailable(): void
    {
        $runtime = $this->invokeNonPublic(
            $this->controller(),
            'resolveOtaDiagnosisAnalysisRuntime',
            ['auto', true]
        );

        self::assertSame('llm_augmented_rules', $runtime['mode']);
        self::assertFalse($runtime['use_rules_only']);
        self::assertTrue($runtime['model_allowed']);
    }

    public function testHistoricalReferenceCannotCallModelForMissingRequestedPeriod(): void
    {
        $controller = $this->controller();
        $runtime = $this->invokeNonPublic(
            $controller,
            'resolveOtaDiagnosisAnalysisRuntime',
            ['auto', true]
        );

        $gated = OtaDiagnosisRequestedPeriodGateService::apply($runtime, true);

        self::assertSame('deterministic_historical_reference_only', $gated['mode']);
        self::assertTrue($gated['use_rules_only']);
        self::assertFalse($gated['model_allowed']);
        self::assertFalse($gated['model_called']);
        self::assertFalse($gated['requested_period_evidence_ready']);
        self::assertSame('requested_period_source_rows_missing', $gated['fallback_reason']);
    }

    public function testRequestedPeriodGateRunsBeforeOtaDiagnosisModelCall(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/Agent.php');
        $methodStart = strpos($source, 'public function otaDiagnosis(): Response');
        $methodEnd = strpos($source, 'public function createOtaDiagnosisExecutionIntent', $methodStart ?: 0);
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        $method = substr($source, (int)$methodStart, (int)$methodEnd - (int)$methodStart);
        $gatePosition = strpos($method, 'OtaDiagnosisRequestedPeriodGateService::apply');
        $modelPosition = strpos($method, '$this->callLlm(');

        self::assertNotFalse($gatePosition);
        self::assertNotFalse($modelPosition);
        self::assertLessThan($modelPosition, $gatePosition);
    }

    public function testInvalidRuntimeModeFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->invokeNonPublic(
            $this->controller(),
            'resolveOtaDiagnosisAnalysisRuntime',
            ['model_only', true]
        );
    }
}
