<?php
declare(strict_types=1);
namespace Tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiDailyReportDateBoundaryTest extends TestCase
{
    private function normalize(string $date): string
    {
        return (new \ReflectionMethod(AiDailyReportService::class, 'normalizeDate'))
            ->invoke(new AiDailyReportService(), $date);
    }

    #[DataProvider('invalidDates')]
    public function testImpossibleDatesCannotBecomeAnotherBusinessDate(string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('date is invalid');
        $this->normalize($date);
    }

    public static function invalidDates(): array
    {
        return array_map(static fn($date) => [$date], [
            '2026-09-31', '2026-02-30', '2026-02-29', '1900-02-29',
            '2026-04-31', '2026-01-32', '2026-01-00', '2026-00-08',
            '2026-13-08', '2026/02/30', '02/30/2026', '29 February 2026',
            '2026-09-08 25:00:00', 'not-a-date', '2026-09-07/2026-09-08', '',
        ]);
    }

    #[DataProvider('validDates')]
    public function testValidLegacyFormatsAndCalendarBoundariesStayCompatible(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalize($input));
    }

    public static function validDates(): array
    {
        return [
            ['2026-09-08', '2026-09-08'], ['2026-9-8', '2026-09-08'],
            ['2026/09/08', '2026-09-08'], ['09/08/2026', '2026-09-08'],
            ['8 September 2026', '2026-09-08'], [' 2026-09-08 ', '2026-09-08'],
            ['2026-09-08T08:00:00+08:00', '2026-09-08'],
            ['2024-02-29', '2024-02-29'], ['2000-02-29', '2000-02-29'],
            ['1900-02-28', '1900-02-28'], ['2026-01-01', '2026-01-01'],
            ['2026-12-31', '2026-12-31'], ['2026-04-30', '2026-04-30'],
            ['2026-09-30', '2026-09-30'], ['0001-01-01', '0001-01-01'],
            ['9999-12-31', '9999-12-31'],
        ];
    }

    public function testValidRelativeDateCompatibilityIsNotChanged(): void
    {
        foreach (['yesterday', 'today', 'tomorrow', 'last day of February 2026'] as $input) {
            self::assertSame(date('Y-m-d', strtotime($input)), $this->normalize($input));
        }
    }
}
