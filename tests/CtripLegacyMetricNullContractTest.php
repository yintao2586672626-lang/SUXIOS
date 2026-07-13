<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CtripLegacyMetricNullContractTest extends TestCase
{
    public function testLegacyCtripPersistenceDoesNotDefaultMissingCoreMetricsToZero(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/BusinessDisplayConcern.php');
        $start = strpos($source, 'private function parseAndSaveData(');
        $end = strpos($source, 'private function persistCtripCompetitionCircleRowsFromLegacyParser', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        foreach (['amount', 'quantity', 'bookOrderNum', 'listExposure', 'detailExposure', 'orderFillingNum', 'orderSubmitNum'] as $variable) {
            self::assertMatchesRegularExpression(
                '/\$' . preg_quote($variable, '/') . '\s*=\s*(?:\(int\))?\$this->nullableNumberFromKeys\(/',
                $method,
                "{$variable} must keep missing values nullable"
            );
        }
        self::assertStringNotContainsString('?? 0.0', $method);
    }
}
