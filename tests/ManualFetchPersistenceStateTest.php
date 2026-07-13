<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataManualFetchConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ManualFetchPersistenceStateTest extends TestCase
{
    public function testCtripFetchAndPersistenceStatesAreIndependent(): void
    {
        $harness = $this->harness();
        $method = $this->stateMethod($harness, 'buildCtripPersistenceState');

        self::assertSame(
            ['persistence_status' => 'blocked', 'persisted' => false],
            $method->invoke($harness, true, 0, true)
        );
        self::assertSame(
            ['persistence_status' => 'display_only', 'persisted' => false],
            $method->invoke($harness, false, 0, false)
        );
        self::assertSame(
            ['persistence_status' => 'persisted', 'persisted' => true],
            $method->invoke($harness, true, 3, false)
        );
        self::assertSame(
            ['persistence_status' => 'not_persisted', 'persisted' => false],
            $method->invoke($harness, true, 0, false)
        );
    }

    public function testMeituanAutoSaveRequiresRowsAndVerifiedReadback(): void
    {
        $harness = $this->harness();
        $method = $this->stateMethod($harness, 'buildMeituanPersistenceState');

        self::assertSame('display_only', $method->invoke($harness, false, 0, null)['persistence_status']);

        $empty = $method->invoke($harness, true, 0, null);
        self::assertFalse($empty['persisted']);
        self::assertSame(422, $empty['http_code']);
        self::assertSame('meituan_rank_persistence_empty', $empty['reason']);

        $mismatch = $method->invoke($harness, true, 2, [
            'verified' => false,
            'matched_count' => 1,
            'reason' => 'database_readback_mismatch',
        ]);
        self::assertFalse($mismatch['persisted']);
        self::assertSame(500, $mismatch['http_code']);
        self::assertSame('meituan_rank_readback_failed', $mismatch['reason']);

        $verified = $method->invoke($harness, true, 2, [
            'verified' => true,
            'matched_count' => 2,
            'reason' => '',
        ]);
        self::assertTrue($verified['persisted']);
        self::assertSame('readback_verified', $verified['persistence_status']);
        self::assertSame(2, $verified['saved_count']);
    }

    public function testMeituanDirectSectionsRequireEveryParsedRowToReadBack(): void
    {
        $harness = $this->harness();
        $method = $this->stateMethod($harness, 'buildMeituanDirectPersistenceState');

        $displayOnly = $method->invoke($harness, false, 3, 0, 'meituan_traffic');
        self::assertSame('display_only', $displayOnly['persistence_status']);
        self::assertFalse($displayOnly['persisted']);

        $empty = $method->invoke($harness, true, 0, 0, 'meituan_orders');
        self::assertSame(422, $empty['http_code']);
        self::assertSame('meituan_orders_persistence_empty', $empty['reason']);

        $partial = $method->invoke($harness, true, 3, 2, 'meituan_ads');
        self::assertSame(500, $partial['http_code']);
        self::assertSame('readback_failed', $partial['persistence_status']);
        self::assertSame('meituan_ads_readback_failed', $partial['reason']);
        self::assertFalse($partial['persisted']);

        $verified = $method->invoke($harness, true, 3, 3, 'meituan_ads');
        self::assertSame(200, $verified['http_code']);
        self::assertSame('readback_verified', $verified['persistence_status']);
        self::assertTrue($verified['persisted']);
        self::assertSame(3, $verified['saved_count']);
    }

    private function harness(): object
    {
        return new class {
            use OnlineDataManualFetchConcern;
        };
    }

    private function stateMethod(object $harness, string $name): ReflectionMethod
    {
        self::assertTrue(method_exists($harness, $name), "Missing {$name}");
        $method = new ReflectionMethod($harness, $name);
        $method->setAccessible(true);
        return $method;
    }
}
