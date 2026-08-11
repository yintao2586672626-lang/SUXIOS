<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\AutoFetchProfileSyncConcern;
use app\controller\concern\BusinessDisplayConcern;
use app\controller\concern\CtripAutoFetchExecutionConcern;
use PHPUnit\Framework\TestCase;

final class CookieConfigAutoFetchReceiptTest extends TestCase
{
    public function testCtripBoundWriteIsSurfacedAsPartialWithoutCoreMetricPromotion(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $scope = $this->runScope('ctrip');
        $receipt = [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'historical_daily',
            'data_source_id' => 25,
            'sync_task_id' => 3101,
            'started_at' => '2026-08-10 00:10:01',
            'row_ids' => [82001, 82002],
            'source_trace_ids' => ['ctrip-cc:exact-test-trace'],
            'readback_verified' => true,
            'p0_status' => 'blocked',
            'verified_metric_keys' => [],
            'gap_codes' => [
                'self_hotel_operating_fact_missing',
                'core_business_metrics_missing',
            ],
        ];

        $result = $this->invoke($harness, 'withCookieConfigAutoFetchReceipt', [[
            'module' => 'ctrip-business',
            'success' => true,
            'saved_count' => 2,
            'run_readback' => $receipt,
        ], $scope]);

        self::assertTrue($result['success'], 'Legacy module success remains write-success semantics.');
        self::assertTrue($result['write_success']);
        self::assertSame('partial', $result['receipt_status']);
        self::assertTrue($result['run_readback']['readback_verified']);
        self::assertSame('blocked', $result['run_readback']['p0_status']);
        self::assertSame([], $result['run_readback']['verified_metric_keys']);
        self::assertSame(25, $result['run_readback']['data_source_id']);
        self::assertSame(3101, $result['run_readback']['sync_task_id']);
        self::assertContains('self_hotel_operating_fact_missing', $result['gap_codes']);
        self::assertContains('core_business_metrics_missing', $result['gap_codes']);
        self::assertFalse($this->invoke(
            $harness,
            'autoFetchPlatformRunSucceeded',
            [2, $result['run_readback']]
        ));
    }

    public function testMeituanRankWriteWithoutEvidenceIdsSurfacesBlockedReceiptWithoutInventingIds(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $result = $this->invoke($harness, 'withCookieConfigAutoFetchReceipt', [[
            'module' => 'meituan-P_RZ',
            'success' => true,
            'saved_count' => 42,
        ], $this->runScope('meituan')]);

        self::assertTrue($result['success']);
        self::assertTrue($result['write_success']);
        self::assertSame('blocked', $result['receipt_status']);
        self::assertFalse($result['run_readback']['readback_verified']);
        self::assertSame('blocked', $result['run_readback']['p0_status']);
        self::assertSame([], $result['run_readback']['verified_metric_keys']);
        self::assertArrayNotHasKey('data_source_id', $result['run_readback']);
        self::assertArrayNotHasKey('sync_task_id', $result['run_readback']);
        self::assertSame([], $result['run_readback']['row_ids']);
        self::assertSame([], $result['run_readback']['source_trace_ids']);
        foreach ([
            'data_source_id_missing',
            'sync_task_id_missing',
            'row_ids_missing',
            'source_trace_ids_missing',
            'exact_run_readback_missing',
            'core_business_metrics_missing',
        ] as $gapCode) {
            self::assertContains($gapCode, $result['gap_codes']);
        }
        self::assertFalse($this->invoke(
            $harness,
            'autoFetchPlatformRunSucceeded',
            [42, $result['run_readback']]
        ));
    }

    public function testPlatformReceiptSelectionUsesModulesAndBrowserButRejectsWrongScopeOrRun(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $scope = $this->runScope('ctrip');
        $partial = [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'historical_daily',
            'auto_fetch_run_id' => 'run-cookie-1',
            'started_at' => '2026-08-10 00:10:00',
            'data_source_id' => 25,
            'sync_task_id' => 4100,
            'row_ids' => [83001],
            'source_trace_ids' => ['trace-partial'],
            'readback_verified' => true,
            'p0_status' => 'blocked',
            'verified_metric_keys' => [],
        ];
        $readyBrowser = array_replace($partial, [
            'auto_fetch_run_id' => '',
            'started_at' => '2026-08-10 00:10:01',
            'sync_task_id' => 4099,
            'row_ids' => [83002],
            'source_trace_ids' => ['trace-ready'],
            'p0_status' => 'ready',
            'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
        ]);

        $selected = $this->invoke($harness, 'selectCurrentAutoFetchPlatformRunReadback', [[
            ['module' => 'ctrip-business', 'run_readback' => $partial],
            ['module' => 'wrong-hotel', 'run_readback' => array_replace($readyBrowser, ['system_hotel_id' => 81])],
        ], ['run_readback' => $readyBrowser], $scope]);
        self::assertSame([83002], $selected['row_ids']);
        self::assertTrue($this->invoke($harness, 'autoFetchPlatformRunSucceeded', [2, $selected]));

        foreach ([
            'hotel' => ['system_hotel_id' => 81],
            'platform' => ['platform' => 'meituan'],
            'date' => ['target_date' => '2026-08-08'],
            'period' => ['data_period' => 'realtime_snapshot'],
            'run' => ['auto_fetch_run_id' => 'another-run'],
            'stale' => ['auto_fetch_run_id' => '', 'started_at' => '2026-08-10 00:09:59'],
        ] as $label => $mutation) {
            $candidate = array_replace($readyBrowser, $mutation);
            $rejected = $this->invoke(
                $harness,
                'selectCurrentAutoFetchPlatformRunReadback',
                [[], ['run_readback' => $candidate], $scope]
            );
            self::assertSame([], $rejected, "{$label} mismatch must be rejected.");
        }
    }

    public function testFailedCookieModuleSurfacesFailureReceiptWithoutChangingLegacySuccess(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $result = $this->invoke($harness, 'withCookieConfigAutoFetchReceipt', [[
            'module' => 'meituan-P_LL',
            'success' => false,
            'saved_count' => 0,
            'message' => 'meituan_ranking_request_failed',
        ], $this->runScope('meituan')]);

        self::assertFalse($result['success']);
        self::assertFalse($result['write_success']);
        self::assertSame('failed', $result['receipt_status']);
        self::assertContains('write_failed', $result['gap_codes']);
        self::assertFalse($result['run_readback']['readback_verified']);
    }

    public function testCapturedButUnsavedModuleDoesNotClaimWriteSuccess(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $result = $this->invoke($harness, 'withCookieConfigAutoFetchReceipt', [[
            'module' => 'ctrip-cookie-api',
            'success' => true,
            'saved_count' => 0,
            'message' => 'captured rows but not saved',
        ], $this->runScope('ctrip')]);

        self::assertTrue($result['success'], 'The legacy capture-success field remains compatible.');
        self::assertFalse($result['write_success']);
        self::assertSame('failed', $result['receipt_status']);
        self::assertContains('write_failed', $result['gap_codes']);
        self::assertSame('blocked', $result['run_readback']['p0_status']);
        self::assertSame([], $result['run_readback']['verified_metric_keys']);
    }

    public function testFailedWriteCannotPromoteAnOtherwiseReadyReceipt(): void
    {
        $harness = new CookieConfigAutoFetchReceiptHarness();
        $readyReceipt = [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'historical_daily',
            'data_source_id' => 25,
            'sync_task_id' => 3101,
            'started_at' => '2026-08-10 00:10:01',
            'row_ids' => [82001],
            'source_trace_ids' => ['trace-ready-but-failed'],
            'readback_verified' => true,
            'p0_status' => 'ready',
            'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
        ];
        $result = $this->invoke($harness, 'withCookieConfigAutoFetchReceipt', [[
            'module' => 'ctrip-cookie-api',
            'success' => false,
            'saved_count' => 0,
            'message' => 'write failed',
            'run_readback' => $readyReceipt,
        ], $this->runScope('ctrip')]);

        self::assertFalse($result['success']);
        self::assertFalse($result['write_success']);
        self::assertSame('failed', $result['receipt_status']);
        self::assertSame('blocked', $result['run_readback']['p0_status']);
        self::assertSame([], $result['run_readback']['verified_metric_keys']);
        self::assertFalse($this->invoke(
            $harness,
            'autoFetchPlatformRunSucceeded',
            [0, $result['run_readback']]
        ));
    }

    public function testNextCtripTaskCannotReusePreviousTaskStructuredReceipt(): void
    {
        $harness = new CookieConfigAutoFetchTaskReceiptHarness();
        $property = new \ReflectionProperty($harness, 'lastCtripStructuredRunReadback');
        $property->setValue($harness, [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'target_date' => '2026-08-09',
            'data_period' => 'historical_daily',
            'data_source_id' => 25,
            'sync_task_id' => 3101,
            'started_at' => '2026-08-10 00:10:01',
            'row_ids' => [82001],
            'source_trace_ids' => ['previous-task-trace'],
            'readback_verified' => true,
            'p0_status' => 'blocked',
            'verified_metric_keys' => [],
            'write_success' => true,
        ]);

        $result = $this->invoke($harness, 'executeAutoFetchTask', [[
            'platform' => 'ctrip',
            'module' => 'unsupported_after_previous_task',
            'label' => 'unsupported-after-previous-task',
            'strategy' => 'cookie_config',
        ], 80, '2026-08-09', $this->runScope('ctrip')]);

        self::assertFalse($result['success']);
        self::assertFalse($result['write_success']);
        self::assertSame('skipped', $result['receipt_status']);
        self::assertContains('write_skipped', $result['gap_codes']);
        self::assertArrayNotHasKey('data_source_id', $result['run_readback']);
        self::assertArrayNotHasKey('sync_task_id', $result['run_readback']);
        self::assertSame([], $result['run_readback']['row_ids']);
        self::assertSame([], $result['run_readback']['source_trace_ids']);
        self::assertSame([], $this->invoke($harness, 'lastCtripStructuredRunReadback', []));
    }

    /** @return array<string, mixed> */
    private function runScope(string $platform): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => $platform,
            'target_date' => '2026-08-09',
            'data_period' => 'historical_daily',
            'run_started_at' => '2026-08-10 00:10:00',
            'auto_fetch_run_id' => 'run-cookie-1',
        ];
    }

    private function invoke(object $target, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}

final class CookieConfigAutoFetchReceiptHarness
{
    use BusinessDisplayConcern;
    use AutoFetchProfileSyncConcern;
}

final class CookieConfigAutoFetchTaskReceiptHarness
{
    use BusinessDisplayConcern;
    use AutoFetchProfileSyncConcern;
    use CtripAutoFetchExecutionConcern;

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function withAutoFetchResultMeta(array $result, string $strategy, string $label = ''): array
    {
        $result['strategy'] = $strategy;
        if ($label !== '') {
            $result['label'] = $label;
        }
        return $result;
    }
}
