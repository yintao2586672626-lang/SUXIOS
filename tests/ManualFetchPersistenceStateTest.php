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

        $blocked = $method->invoke($harness, true, 0, true);
        self::assertSame('blocked', $blocked['persistence_status']);
        self::assertFalse($blocked['persisted']);

        $displayOnly = $method->invoke($harness, false, 0, false);
        self::assertSame('display_only', $displayOnly['persistence_status']);
        self::assertFalse($displayOnly['persisted']);

        $mismatch = $method->invoke($harness, true, 3, false, 2, false);
        self::assertSame('readback_failed', $mismatch['persistence_status']);
        self::assertFalse($mismatch['persisted']);
        self::assertSame(3, $mismatch['processed_count']);
        self::assertSame(2, $mismatch['readback_count']);

        $verified = $method->invoke($harness, true, 3, false, 3, true);
        self::assertSame('readback_verified', $verified['persistence_status']);
        self::assertTrue($verified['persisted']);
        self::assertSame(3, $verified['saved_count']);

        $empty = $method->invoke($harness, true, 0, false, 0, false);
        self::assertSame('not_persisted', $empty['persistence_status']);
        self::assertFalse($empty['persisted']);

        $outcomeMethod = $this->stateMethod($harness, 'buildCtripManualFetchPersistenceOutcome');
        $failureOutcome = $outcomeMethod->invoke($harness, true, $mismatch);
        self::assertSame(500, $failureOutcome['code']);
        self::assertSame(500, $failureOutcome['http_status']);
        self::assertSame('readback_failed', $failureOutcome['save_status']);
        self::assertStringContainsString('未确认入库', $failureOutcome['message']);

        $emptyOutcome = $outcomeMethod->invoke($harness, true, $empty);
        self::assertSame(422, $emptyOutcome['code']);
        self::assertSame(422, $emptyOutcome['http_status']);
        self::assertSame('not_persisted', $emptyOutcome['save_status']);
        self::assertStringContainsString('没有可入库记录', $emptyOutcome['message']);

        $successOutcome = $outcomeMethod->invoke($harness, true, $verified);
        self::assertSame(200, $successOutcome['http_status']);
        self::assertSame('', $successOutcome['save_status']);
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

    public function testCtripTrafficAndAdsUseDatabaseReadbackBeforeReportingPersistence(): void
    {
        $root = dirname(__DIR__);
        $manualFetchSource = (string)file_get_contents($root . '/app/controller/concern/OnlineDataManualFetchConcern.php');
        $adsSource = (string)file_get_contents($root . '/app/controller/concern/CtripAdsConcern.php');

        self::assertStringContainsString('countCtripTrafficReadbackRows(', $manualFetchSource);
        self::assertStringContainsString('countCtripCapturedAdRowsReadback(', $manualFetchSource);
        self::assertStringContainsString("'readback_verified' => \$persistenceState['readback_verified']", $manualFetchSource);
        self::assertStringContainsString("\$persistenceState['persistence_status'] === 'readback_failed'", $manualFetchSource);
        self::assertStringContainsString('private function countCtripCapturedAdRowsReadback(array $rows): int', $adsSource);
        self::assertStringContainsString("->field('id,raw_data,readback_verified')->find()", $adsSource);
        self::assertStringContainsString("(int)(\$stored['readback_verified'] ?? 0) !== 1", $adsSource);
    }

    public function testCtripTrafficReadbackHashIgnoresGeneratedFieldFactsOnly(): void
    {
        $harness = $this->harness();
        $method = $this->stateMethod($harness, 'hashCtripTrafficReadbackPayload');
        $sourceRow = [
            'date' => '2026-07-18',
            'compareType' => 'self',
            'listExposure' => 120,
            'detailExposure' => 45,
            'hotelId' => 'ctrip-137',
            '_source_path' => 'data.myHotel',
        ];
        $storedRow = $sourceRow;
        $storedRow['field_facts'] = [[
            'metric_key' => 'list_exposure',
            'status' => 'captured',
        ]];
        $storedRow['field_fact_summary'] = [
            'captured_count' => 1,
            'missing_count' => 0,
        ];

        self::assertSame(
            $method->invoke($harness, $sourceRow),
            $method->invoke($harness, $storedRow)
        );

        $storedRow['listExposure'] = 121;
        self::assertNotSame(
            $method->invoke($harness, $sourceRow),
            $method->invoke($harness, $storedRow)
        );
    }

    public function testTrafficSuccessAuditRunsOnlyAfterTheTerminalPersistenceGate(): void
    {
        $harness = $this->harness();
        foreach ([
            ['executeCtripTrafficFetch', 'fetch_ctrip_traffic'],
            ['executeMeituanTrafficFetch', 'fetch_meituan_traffic'],
        ] as [$methodName, $action]) {
            $source = $this->methodSource($harness, $methodName);
            $failureGate = strpos($source, "if (\$autoSave && !\$persistenceState['persisted'])");
            $successAudit = strpos($source, "OperationLog::record('online_data', '{$action}'");

            self::assertNotFalse($failureGate, "{$methodName} must retain the persistence failure gate.");
            self::assertNotFalse($successAudit, "{$methodName} must emit a terminal success audit.");
            self::assertGreaterThan(
                $failureGate,
                $successAudit,
                "{$methodName} must not log success before persistence/readback failure is ruled out."
            );
            self::assertStringContainsString("'outcome' => 'success'", substr($source, (int)$successAudit));
            self::assertStringContainsString("'status' => \$persistenceState['persistence_status']", substr($source, (int)$successAudit));
        }
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

    private function methodSource(object $harness, string $name): string
    {
        $method = $this->stateMethod($harness, $name);
        $fileName = $method->getFileName();
        self::assertIsString($fileName);
        $lines = file($fileName);
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));
    }
}
