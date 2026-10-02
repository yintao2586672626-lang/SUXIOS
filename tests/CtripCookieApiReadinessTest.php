<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\CtripAutoFetchExecutionConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CtripCookieApiReadinessTest extends TestCase
{
    public function testPartialStandardReadbackCannotBeReadyToGenerateDiagnosis(): void
    {
        $harness = new class {
            use CtripAutoFetchExecutionConcern;
        };
        $method = new ReflectionMethod($harness, 'buildCtripCookieApiReadiness');
        $method->setAccessible(true);
        $payload = ['auth_status' => ['ok' => true]];
        $captured = ['standard_rows' => 2];

        $partial = $method->invoke($harness, $payload, $captured, [
            'saved_count' => 3,
            'standard_expected_count' => 2,
            'standard_saved' => 1,
        ], true);
        self::assertFalse($partial['is_ready']);
        self::assertSame('not_ready', $partial['status']);
        self::assertStringContainsString('1/2', $partial['next_action']);

        $complete = $method->invoke($harness, $payload, $captured, [
            'saved_count' => 3,
            'standard_expected_count' => 2,
            'standard_saved' => 2,
        ], true);
        self::assertTrue($complete['is_ready']);

        $displayOnly = $method->invoke($harness, $payload, $captured, [
            'saved_count' => 0,
            'standard_expected_count' => 0,
            'standard_saved' => 0,
        ], false);
        self::assertTrue($displayOnly['is_ready']);
    }
}
