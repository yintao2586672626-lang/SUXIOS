<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\CtripOverviewRowsConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CtripOverviewReadbackStateTest extends TestCase
{
    public function testOnlyEveryMergedOverviewRowReadBackCanBeReportedAsSaved(): void
    {
        $harness = new class {
            use CtripOverviewRowsConcern;
        };
        $method = new ReflectionMethod($harness, 'ctripOverviewReadbackComplete');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($harness, 3, 2));
        self::assertFalse($method->invoke($harness, 3, 0));
        self::assertFalse($method->invoke($harness, 0, 0));
        self::assertTrue($method->invoke($harness, 3, 3));
    }
}
