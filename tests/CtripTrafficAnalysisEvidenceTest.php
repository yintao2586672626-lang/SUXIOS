<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripTrafficDisplayService;
use PHPUnit\Framework\TestCase;

final class CtripTrafficAnalysisEvidenceTest extends TestCase
{
    private function completeRow(string $date, int $hotelId): array
    {
        return [
            'date' => $date, 'hotelId' => $hotelId,
            'listExposure' => 100, 'detailExposure' => 20,
            'orderFillingNum' => 5, 'orderSubmitNum' => 1,
        ];
    }

    public function testMissingSelfFieldsDoNotProduceDerivedValuesOrOperatingAdvice(): void
    {
        $partialSelf = ['date' => '2026-07-29', 'hotelId' => 80, 'listExposure' => 100];
        $analysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([
            $partialSelf, $this->completeRow('2026-07-29', -1),
        ]);

        self::assertSame([], $analysis['recommendations']);
        self::assertNull($analysis['summary']);
        self::assertSame([], $analysis['rows']);
        self::assertSame('partial', $analysis['status']);
        self::assertContains('2026-07-29:self:detail_visitors', $analysis['data_gaps']);
    }

    public function testMissingCompetitorForOneDateCannotBeReplacedByZeroOrAnotherDate(): void
    {
        $analysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([
            $this->completeRow('2026-07-28', 80),
            $this->completeRow('2026-07-28', -1),
            $this->completeRow('2026-07-29', 80),
        ]);

        self::assertNull($analysis['summary']);
        self::assertSame([], $analysis['recommendations']);
        self::assertSame('partial', $analysis['status']);
        self::assertContains('2026-07-29:competitor', $analysis['data_gaps']);
    }

    public function testCompleteRolesPreserveDerivedCalculationAndTrueZero(): void
    {
        $analysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([
            $this->completeRow('2026-07-29', 80),
            $this->completeRow('2026-07-29', -1),
        ]);
        self::assertSame('ready', $analysis['status']);
        self::assertSame([], $analysis['data_gaps']);
        self::assertCount(1, $analysis['rows']);
        self::assertSame(100.0, $analysis['summary']['self']['exposure']);

        $zero = $this->completeRow('2026-07-29', 80);
        $zero['listExposure'] = 0;
        $zeroAnalysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([
            $zero, $this->completeRow('2026-07-29', -1),
        ]);
        self::assertSame('ready', $zeroAnalysis['status']);
        self::assertSame(0.0, $zeroAnalysis['summary']['self']['exposure']);
    }

    public function testNoRowsKeepMissingStateAndNoAdvice(): void
    {
        $analysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis([]);
        self::assertSame('missing', $analysis['status']);
        self::assertNull($analysis['summary']);
        self::assertSame([], $analysis['rows']);
        self::assertSame([], $analysis['recommendations']);
    }
}
