<?php
declare(strict_types=1);

namespace Tests;

use app\service\MacroSignalService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class MacroSignalServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testDetailRejectsUnknownSignalTypeBeforeReadingData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MacroSignalService())->detail('unknown');
    }

    public function testResolveTrendRangeSupportsCustomAndNormalizesReverseDates(): void
    {
        $range = $this->invokeNonPublic(new MacroSignalService(), 'resolveTrendRange', [
            'custom',
            '2026-05-10',
            '2026-05-01',
        ]);

        self::assertSame(['2026-05-01', '2026-05-10', 'custom', '自定义'], $range);
    }

    public function testResolveTrendRangeFallsBackToThirtyDaysForInvalidCustomRange(): void
    {
        $range = $this->invokeNonPublic(new MacroSignalService(), 'resolveTrendRange', [
            'custom',
            'bad-date',
            '2026-05-01',
        ]);

        self::assertSame('30', $range[2]);
        self::assertSame('近30日', $range[3]);
    }
}
