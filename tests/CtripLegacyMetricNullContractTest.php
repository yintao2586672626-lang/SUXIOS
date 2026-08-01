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

        self::assertStringContainsString('buildCtripBusinessObservedMetricPatch($item, $columns)', $method);
        self::assertStringContainsString('OnlineDailyDataPersistenceService::buildMetricAwareWriteData(', $method);
        self::assertStringContainsString('!$exists', $method);
        self::assertStringContainsString('$value = $this->nullableNumberFromKeys($item, $keys);', $method);
        self::assertStringContainsString('if ($value === null)', $method);
        foreach (['amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'] as $field) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($field, '/') . "'\\s*=>/",
                $method,
                "{$field} must be part of the nullable observed-metric patch"
            );
        }
        self::assertStringNotContainsString('?? 0.0', $method);
    }

    public function testScheduledCtripCollectionCannotRegressToCookieFallback(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString('scheduled_browser_profile_source_required', $source);
        self::assertStringContainsString('Scheduled collection is Profile-only.', $source);
        self::assertStringNotContainsString('private function sendHttpRequest(', $source);
        self::assertStringNotContainsString('private function parseAndSaveData(', $source);
        self::assertStringNotContainsString('legacyBusinessObservedMetrics(', $source);
        self::assertStringNotContainsString("'Cookie: ' .", $source);
        self::assertStringNotContainsString('OnlineDailyDataPersistenceService', $source);
    }
}
