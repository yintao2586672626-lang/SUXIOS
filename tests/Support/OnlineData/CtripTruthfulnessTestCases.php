<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

use Tests\OnlineDataQuerySpy;

trait CtripTruthfulnessTestCases
{
    public function testManualCtripBusinessInputIsExplicitlyUnverifiedAndExcludedFromAnalytics(): void
    {
        $controller = $this->controller();
        $provenance = $this->invokeNonPublic($controller, 'buildCtripBusinessPersistenceProvenance', [[
            'hotelId' => 'ctrip-80',
            'amount' => 123.45,
        ], [
            'ingestion_method' => 'user_provided_unverified',
            'force_unverified' => true,
        ], 'ctrip-80', 80]);

        self::assertSame('user_provided_unverified', $provenance['ingestion_method']);
        self::assertTrue($provenance['force_unverified']);
        self::assertSame('manual_input_unverified', $provenance['analysis_exclusion_reason']);
        self::assertTrue($provenance['raw_data']['manual_input']);
        self::assertFalse($provenance['raw_data']['analysis_eligibility']['eligible']);

        $row = $this->invokeNonPublic($controller, 'markCtripBusinessRowUnverified', [[
            'validation_status' => 'normal',
            'validation_flags' => '[]',
        ], [
            'validation_status' => true,
            'validation_flags' => true,
        ], 'manual_input_unverified']);
        self::assertSame('unverified', $row['validation_status']);
        self::assertSame(
            'manual_input_unverified',
            json_decode((string)$row['validation_flags'], true)[0]['code']
        );
    }

    public function testCtripStandardRowsPreserveExplicitZeroButKeepMissingNumericFieldsNull(): void
    {
        $controller = $this->controller();

        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [[], 'amount']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => null], 'amount']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => ''], 'amount']));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'ctripStandardRowFloatMetric', [['amount' => 0], 'amount']));

        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [[], 'quantity']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => null], 'quantity']));
        self::assertNull($this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => 'not-a-number'], 'quantity']));
        self::assertSame(0, $this->invokeNonPublic($controller, 'ctripStandardRowIntegerMetric', [['quantity' => 0], 'quantity']));
    }

    public function testCtripLatestAcceptsAnExactHistoricalBusinessDate(): void
    {
        $controller = $this->controller();
        $targetDate = '2026-07-31';

        self::assertSame($targetDate, $this->invokeNonPublic($controller, 'normalizeCtripLatestRange', [$targetDate]));
        self::assertSame($targetDate, $this->invokeNonPublic($controller, 'resolveCtripLatestTargetDate', [$targetDate]));
        self::assertSame('', $this->invokeNonPublic($controller, 'normalizeCtripLatestRange', ['2026-02-30']));

        $query = new OnlineDataQuerySpy();
        $this->invokeNonPublic($controller, 'applyCtripLatestPeriodScope', [
            $query,
            ['data_period' => true, 'is_final' => true],
            $targetDate,
        ]);

        self::assertSame([
            ['where', 'data_period', 'historical_daily'],
            ['where', 'is_final', 1],
        ], $query->calls);
    }

    public function testCtripExactDateTrafficKeepsLatestSelfAndCompetitorAverageAcrossAdjacentBatches(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();

        $this->invokeNonPublic($controller, 'applyCtripSectionTypeFilter', [
            $query,
            'traffic',
            ['data_type' => true, 'compare_type' => true],
            true,
        ]);

        self::assertSame([
            ['where', 'data_type', 'traffic'],
            ['whereIn', 'compare_type', ['self', 'competitor_avg']],
        ], $query->calls);

        $rows = $this->invokeNonPublic($controller, 'selectLatestCtripExactDateTrafficRoleRows', [[
            ['id' => 70943, 'compare_type' => 'competitor_avg', 'update_time' => '2026-08-01 22:10:36'],
            ['id' => 70942, 'compare_type' => 'competitor_avg', 'update_time' => '2026-08-01 22:10:35'],
            ['id' => 70940, 'compare_type' => 'self', 'update_time' => '2026-08-01 22:10:34'],
            ['id' => 70939, 'compare_type' => 'self', 'update_time' => '2026-08-01 22:10:33'],
            ['id' => 70938, 'compare_type' => 'competitor', 'update_time' => '2026-08-01 22:10:32'],
        ]]);

        self::assertSame([70940, 70943], array_column($rows, 'id'));
    }
}
