<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\CtripAutoFetchExecutionConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CtripProfileStandardReadbackStateTest extends TestCase
{
    public function testPartialStandardRowReadbackDoesNotCompleteProfileCapture(): void
    {
        $harness = new class {
            use CtripAutoFetchExecutionConcern;
        };
        $method = new ReflectionMethod($harness, 'ctripProfileReadbackComplete');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($harness, [
            'saved_count' => 3,
            'standard_expected_count' => 2,
            'standard_saved' => 1,
        ]));
        self::assertTrue($method->invoke($harness, [
            'saved_count' => 3,
            'standard_expected_count' => 2,
            'standard_saved' => 2,
        ]));
        self::assertTrue($method->invoke($harness, [
            'saved_count' => 1,
            'standard_expected_count' => 0,
            'standard_saved' => 0,
        ]));
        self::assertFalse($method->invoke($harness, [
            'saved_count' => 0,
            'standard_expected_count' => 1,
            'standard_saved' => 0,
        ]));
    }
}
