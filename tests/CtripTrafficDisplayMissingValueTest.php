<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripTrafficDisplayService;
use PHPUnit\Framework\TestCase;

final class CtripTrafficDisplayMissingValueTest extends TestCase
{
    public function testMissingCaptureFieldsRemainDistinctFromRealZeroInHistoryRowsAndSummary(): void
    {
        $rows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
            ['dataDate' => '2026-07-29', 'hotelId' => 80],
            [
                'dataDate' => '2026-07-29', 'hotelId' => -1,
                'listExposure' => 0, 'detailExposure' => 0,
                'orderFillingNum' => 0, 'orderSubmitNum' => 0,
            ],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('self', $rows[0]['compareType']);
        foreach (['listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum'] as $key) {
            self::assertNull($rows[0][$key], $key);
            self::assertSame(0.0, $rows[1][$key], $key);
        }

        $summary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($rows);
        self::assertNull($summary['self']['listExposure']);
        self::assertNull($summary['self']['detailExposure']);
        self::assertNull($summary['self']['flowRate']);
        self::assertSame(0.0, $summary['avg']['listExposure']);
        self::assertSame(0.0, $summary['avg']['flowRate']);
    }

    public function testPartlyCapturedRowKeepsObservedMetricAndLeavesUnobservedMetricMissing(): void
    {
        $rows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
            ['dataDate' => '2026-07-29', 'hotelId' => 80, 'listExposure' => 100],
        ]);

        self::assertSame(100.0, $rows[0]['listExposure']);
        self::assertNull($rows[0]['detailExposure']);
        self::assertNull($rows[0]['flowRate']);
        $summary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($rows);
        self::assertSame(100.0, $summary['self']['listExposure']);
        self::assertNull($summary['self']['detailExposure']);
        self::assertNull($summary['self']['flowRate']);
    }

    public function testAbsentComparisonRoleStaysMissingWhileObservedZeroRemainsZero(): void
    {
        $selfOnly = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary(
            CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
                ['dataDate' => '2026-07-29', 'hotelId' => 80, 'listExposure' => 0,
                    'detailExposure' => 0, 'orderFillingNum' => 0, 'orderSubmitNum' => 0],
            ])
        );
        self::assertSame(0.0, $selfOnly['self']['listExposure']);
        self::assertSame(0.0, $selfOnly['self']['flowRate']);
        foreach ($selfOnly['avg'] as $metric) {
            self::assertNull($metric, 'Missing competitor role must not be a zero comparison');
        }

        $avgOnly = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary(
            CtripTrafficDisplayService::buildCtripTrafficDisplayRows([
                ['dataDate' => '2026-07-29', 'hotelId' => -1, 'listExposure' => 0,
                    'detailExposure' => 0, 'orderFillingNum' => 0, 'orderSubmitNum' => 0],
            ])
        );
        self::assertSame(0.0, $avgOnly['avg']['listExposure']);
        foreach ($avgOnly['self'] as $metric) {
            self::assertNull($metric, 'Missing self role must not be a zero fact');
        }
        foreach (CtripTrafficDisplayService::emptyCtripTrafficDisplaySummary() as $role) {
            foreach ($role as $metric) {
                self::assertNull($metric, 'No rows means missing metrics, not observed zeros');
            }
        }
    }

    public function testExplicitZeroSourceRatesStayZeroInSingleDateRowAndSummary(): void
    {
        $source = [
            'dataDate' => '2026-07-29', 'hotelId' => 80,
            'listExposure' => 100, 'detailExposure' => 20,
            'orderFillingNum' => 5, 'orderSubmitNum' => 1,
            'flowRate' => 0, 'orderFillRate' => 0, 'submitRate' => 0,
        ];
        $normalized = CtripTrafficDisplayService::normalizeAppTrafficRow($source);
        self::assertSame(0.0, $normalized['metrics']['exposure_rate']);
        self::assertSame(0.0, $normalized['metrics']['order_rate']);
        self::assertSame(0.0, $normalized['metrics']['deal_rate']);

        $rows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$source]);
        $summary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($rows);
        foreach (['flowRate', 'orderFillRate', 'submitRate'] as $key) {
            self::assertSame(0.0, $rows[0][$key], $key);
            self::assertSame(0.0, $summary['self'][$key], $key);
        }

        $withoutSourceRates = $source;
        unset($withoutSourceRates['flowRate'], $withoutSourceRates['orderFillRate'], $withoutSourceRates['submitRate']);
        $derivedRows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$withoutSourceRates]);
        $derived = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($derivedRows);
        self::assertSame(20.0, $derived['self']['flowRate']);
        self::assertSame(25.0, $derived['self']['orderFillRate']);
        self::assertSame(20.0, $derived['self']['submitRate']);

        $positiveSourceRate = $source;
        $positiveSourceRate['flowRate'] = 5;
        $positiveRows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$positiveSourceRate]);
        $positiveSummary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($positiveRows);
        self::assertSame(5.0, $positiveRows[0]['flowRate']);
        self::assertSame(5.0, $positiveSummary['self']['flowRate']);

        $secondDay = $withoutSourceRates;
        $secondDay['dataDate'] = '2026-07-30';
        $secondDay['detailExposure'] = 40;
        $multiDay = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary(
            CtripTrafficDisplayService::buildCtripTrafficDisplayRows([$source, $secondDay])
        );
        self::assertSame(30.0, $multiDay['self']['flowRate']);
    }
}
