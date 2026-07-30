<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
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
