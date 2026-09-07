<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueForecastWorkbenchService;
use app\service\TemporalInsightService;
use Tests\fixtures\RevenueForecastReplayFixture as Fixture;
use PHPUnit\Framework\TestCase;

final class RevenueForecastWorkbenchTest extends TestCase
{
    private string $root;
    private RevenueForecastWorkbenchService $service;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/suxi-l05-' . bin2hex(random_bytes(6));
        $this->service = new RevenueForecastWorkbenchService($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $file) unlink($file);
        foreach (glob($this->root . '/*') ?: [] as $dir) rmdir($dir);
        if (is_dir($this->root)) rmdir($this->root);
    }

    public function testAllHorizonsAndWorseThanWeeklyBaseline(): void
    {
        $result = $this->service->preview(Fixture::input(), Fixture::scope());
        self::assertSame([7, 14, 30], array_keys($result['replay']['forecasts']));
        foreach ([7, 14, 30] as $n) {
            $plan = $result['replay']['forecasts'][$n];
            $stats = $result['replay']['comparisons'][$n];
            self::assertCount($n, $plan['points']);
            self::assertGreaterThanOrEqual(3, $stats['complete_fold_count']);
            self::assertSame(0.0, $stats['metrics']['weekly']['mae']);
            self::assertGreaterThan(0, $stats['metrics']['model']['mae']);
            self::assertSame('not_better_than_baseline', $stats['assessment']['status']);
            self::assertFalse($stats['assessment']['execution_ready']);
            self::assertNotNull($stats['interval_coverage_percent']);
            self::assertNull($stats['nominal_coverage_percent']);
            $dates = [];
            foreach ($stats['folds'] as $fold) {
                self::assertLessThanOrEqual(strtotime($fold['origin_at']), strtotime($fold['training_max_available_at']));
                foreach ($fold['points'] as $point) {
                    self::assertGreaterThan(substr($fold['origin_at'], 0, 10), $point['target_date']);
                    self::assertArrayNotHasKey($point['target_date'], $dates);
                    $dates[$point['target_date']] = true;
                }
            }
        }
        self::assertSame('synthetic', $result['replay']['source_kind']);
        self::assertFalse($result['scenario']['causality_claimed']);
        self::assertEqualsWithDelta($result['scenario']['base_amount_cny'], $result['scenario']['proposed_amount_cny'], 0.01);
    }

    public function testFutureDatesAndLateRevisionsCannotLeakIntoTraining(): void
    {
        $input = Fixture::input();
        $before = $this->service->preview($input, Fixture::scope());
        $late = $input['evidence']['observations'][100];
        $late['value'] = 99999;
        $late['available_at'] = '2026-09-02T00:00:00+08:00';
        $input['evidence']['observations'][] = $late;
        $input['evidence']['evaluation_at'] = '2026-09-03T08:00:00+08:00';
        $after = $this->service->preview($input, Fixture::scope());
        self::assertSame($before['replay']['forecasts'], $after['replay']['forecasts']);
        self::assertSame(1, $after['replay']['input_evidence']['excluded_after_as_of_count']);
        foreach ($after['replay']['comparisons'] as $horizon => $comparison) {
            foreach ($comparison['folds'] as $i => $fold) {
                self::assertSame($before['replay']['comparisons'][$horizon]['folds'][$i]['training_refs'], $fold['training_refs']);
                self::assertSame(array_column($before['replay']['comparisons'][$horizon]['folds'][$i]['points'], 'predicted_value'), array_column($fold['points'], 'predicted_value'));
            }
        }
        $series = array_map(static fn($r) => ['date' => $r['business_date'], 'ota_room_nights' => $r['value']], $input['evidence']['observations']);
        $model = new TemporalInsightService();
        $p = $model->buildForecastPlan($series, '2026-09-01', 30);
        $series[] = ['date' => '2026-09-02', 'ota_room_nights' => 999999];
        self::assertSame($p, $model->buildForecastPlan($series, '2026-09-01', 30));
    }

    public function testLaterMissingVersionInvalidatesOldValue(): void
    {
        $input = Fixture::input();
        $missing = end($input['evidence']['observations']);
        $missing['available_at'] = '2026-09-01T07:00:00+08:00';
        $missing['quality_status'] = 'missing'; $missing['value'] = null;
        $input['evidence']['observations'][] = $missing;
        $result = $this->service->preview($input, Fixture::scope());
        self::assertSame(55, $result['replay']['input_evidence']['training_sample_count']);
        self::assertSame(6, $result['replay']['forecasts'][7]['recent_sample_count']);
        self::assertNull($result['replay']['forecasts'][7]['points'][0]['baseline_mean7']);
        self::assertSame('partial', $result['replay']['forecasts'][7]['status']);
    }

    public function testShortAndAllMissingNeverReturnZeroForecast(): void
    {
        foreach ([[], array_slice(Fixture::input()['evidence']['observations'], -6)] as $observations) {
            $input = Fixture::input(); $input['evidence']['observations'] = $observations;
            $result = $this->service->preview($input, Fixture::scope());
            self::assertNull($result['replay']['forecasts'][30]['total_predicted_room_nights']);
            self::assertSame('blocked', $result['scenario']['status']);
            self::assertNull($result['replay']['comparisons'][30]['metrics']['model']['mae']);
            self::assertSame('insufficient_samples', $result['replay']['comparisons'][30]['assessment']['status']);
        }
    }

    public function testTrueZeroAndMissingActualsAreDistinct(): void
    {
        $input = Fixture::input();
        foreach ($input['evidence']['observations'] as &$row) $row['value'] = 0;
        unset($row);
        $result = $this->service->preview($input, Fixture::scope());
        self::assertEquals(0, $result['replay']['forecasts'][7]['total_predicted_room_nights']);
        self::assertSame(0.0, $result['replay']['comparisons'][7]['metrics']['model']['mae']);
        self::assertNull($result['replay']['comparisons'][7]['metrics']['model']['wape_percent']);
        self::assertEquals(100.0, $result['replay']['comparisons'][7]['interval_coverage_percent']);
        $input['evidence']['observations'][200]['quality_status'] = 'failed';
        $input['evidence']['observations'][200]['value'] = null;
        $partial = $this->service->preview($input, Fixture::scope());
        self::assertGreaterThan(0, $partial['replay']['comparisons'][7]['unpaired_point_count']);
        self::assertSame('insufficient_samples', $partial['replay']['comparisons'][7]['assessment']['status']);
    }

    public function testIdentityUnitsCancellationAndTimesRejectBeforeSaving(): void
    {
        $cases = [
            ['observations', 0, 'tenant_id', 9002], ['observations', 0, 'hotel_id', 90002],
            ['observations', 0, 'platform', 'meituan'], ['observations', 0, 'platform_store_id', 'other'],
            ['observations', 0, 'room_scope', 'whole_hotel'], ['observations', 0, 'value', -1],
            ['observations', 0, 'value', 0.5], ['observations', 0, 'available_at', '2026-01-02'],
            ['observations', 0, 'available_at', '2026-01-01T12:00:00+08:00'],
            ['unit', 'percent'], ['date_basis', 'booking_date'], ['metric_definition', 'gross_booked_including_cancelled'],
            ['source_kind', 'verified'], ['backtest_start', '2026-02-30'],
        ];
        foreach ($cases as $case) {
            $input = Fixture::input();
            if (count($case) === 4) $input['evidence'][$case[0]][$case[1]][$case[2]] = $case[3];
            else $input['evidence'][$case[0]] = $case[1];
            try { $this->service->save($input, Fixture::scope()); self::fail('Invalid evidence accepted: ' . json_encode($case)); }
            catch (\InvalidArgumentException) { self::assertDirectoryDoesNotExist($this->root); }
        }
    }

    public function testDuplicatesRejectedAndTimezoneEquivalentCutoffMatches(): void
    {
        $input = Fixture::input();
        $original = $this->service->preview($input, Fixture::scope());
        $input['evidence']['as_of_at'] = '2026-09-01T00:00:00Z';
        self::assertSame($original['replay']['forecasts'], $this->service->preview($input, Fixture::scope())['replay']['forecasts']);
        $input['evidence']['observations'][] = $input['evidence']['observations'][0];
        $this->expectException(\InvalidArgumentException::class);
        $this->service->save($input, Fixture::scope());
    }

    public function testUnexpectedAndNestedImportFieldsRejectBeforeStorage(): void
    {
        foreach (['root', 'evidence', 'observation', 'scenario', 'nested_leaf'] as $location) {
            $input = Fixture::input();
            $sentinel = 'synthetic-secret-field-must-never-persist';
            match ($location) {
                'root' => $input['password'] = $sentinel,
                'evidence' => $input['evidence']['authorization'] = $sentinel,
                'observation' => $input['evidence']['observations'][0]['cookie'] = $sentinel,
                'scenario' => $input['scenario']['token'] = $sentinel,
                'nested_leaf' => $input['evidence']['observations'][0]['source_ref'] = ['password' => $sentinel],
            };
            try { $this->service->save($input, Fixture::scope()); self::fail('Undeclared import field accepted'); }
            catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString($sentinel, $e->getMessage());
                self::assertDirectoryDoesNotExist($this->root);
            }
        }
    }

    public function testLegacyDocumentWithUndeclaredInputStaysUnreadableAndPreserved(): void
    {
        $saved = $this->service->save(Fixture::input(), Fixture::scope());
        $path = (glob($this->root . '/*/*.json') ?: [])[0];
        $saved = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $saved['payload']['input']['evidence']['authorization'] = 'synthetic-legacy-sentinel';
        $normalize = function ($value) use (&$normalize) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($normalize, $value);
        };
        $saved['id'] = hash('sha256', json_encode($normalize($saved['payload']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        unset($saved['envelope_sha256']);
        $saved['envelope_sha256'] = hash('sha256', json_encode($normalize($saved), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $legacyPath = dirname($path) . '/' . $saved['id'] . '.json';
        file_put_contents($legacyPath, json_encode($saved, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $items = array_column($this->service->history(Fixture::scope()), null, 'id');
        self::assertSame('unverified_or_unsupported', $items[$saved['id']]['status']);
        try { $this->service->read($saved['id'], Fixture::scope()); self::fail('Undeclared legacy content returned'); }
        catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('synthetic-legacy-sentinel', $e->getMessage());
            self::assertFileExists($legacyPath);
        }
    }

    public function testSaveReadbackInputChangeHistoryAndTenantSeparation(): void
    {
        $input = Fixture::input(); $scope = Fixture::scope();
        $saved = $this->service->save($input, $scope);
        self::assertTrue($saved['readback_verified']); self::assertFalse($saved['idempotent_replay']);
        $repeated = $this->service->save($input, $scope);
        self::assertTrue($repeated['idempotent_replay']); self::assertSame($saved['id'], $repeated['id']);
        $read = $this->service->read($saved['id'], $scope);
        self::assertEquals($input, $read['payload']['input']);
        self::assertSame($saved['payload'], $read['payload']);
        $input['scenario']['proposed_price'] = 210;
        $changed = $this->service->save($input, $scope);
        self::assertNotSame($saved['id'], $changed['id']);
        self::assertCount(2, $this->service->history($scope));
        foreach (['tenant_id' => 9002, 'hotel_id' => 90002, 'platform' => 'meituan', 'room_scope' => 'another', 'platform_store_id' => 'another'] as $key => $value) {
            $other = array_replace($scope, [$key => $value]);
            self::assertSame([], $this->service->history($other));
            try { $this->service->read($saved['id'], $other); self::fail('Cross-scope read allowed'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertSame(220, $this->service->read($saved['id'], $scope)['payload']['input']['scenario']['proposed_price']);
    }

    public function testCorruptAndUnsupportedDocumentsFailClosedAndPreserveFile(): void
    {
        $saved = $this->service->save(Fixture::input(), Fixture::scope());
        $path = (glob($this->root . '/*/*.json') ?: [])[0];
        $raw = file_get_contents($path);
        foreach ([str_replace('synthetic-room', 'other-room', $raw), str_replace('revenue_forecast_document.v1', 'revenue_forecast_document.v0', $raw), '{bad'] as $broken) {
            file_put_contents($path, $broken);
            self::assertSame('unverified_or_unsupported', $this->service->history(Fixture::scope())[0]['status']);
            try { $this->service->read($saved['id'], Fixture::scope()); self::fail('Corrupt document accepted'); } catch (\RuntimeException) { self::assertFileExists($path); }
        }
        file_put_contents($path, $raw);
        self::assertTrue($this->service->read($saved['id'], Fixture::scope())['readback_verified']);
    }

    public function testScenarioBoundsMissingInventoryAndScopeFailClosed(): void
    {
        foreach (['inventory_room_nights' => null, 'inventory_scope' => 'whole_hotel', 'price_unit' => 'wan_CNY', 'elasticity' => 1, 'proposed_price' => 0] as $key => $value) {
            $input = Fixture::input(); $input['scenario'][$key] = $value;
            try { $this->service->preview($input, Fixture::scope()); self::fail('Invalid scenario accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $input = Fixture::input(); $input['scenario']['inventory_room_nights'] = 0;
        $result = $this->service->preview($input, Fixture::scope());
        self::assertSame(0.0, $result['scenario']['proposed_amount_cny']);
        self::assertFalse($result['scenario']['automatic_price_write']);
    }

    public function testUnavailableStorageDoesNotClaimSaveAndCanRecover(): void
    {
        file_put_contents($this->root, 'synthetic storage blocker');
        try {
            try { $this->service->save(Fixture::input(), Fixture::scope()); self::fail('Save unexpectedly succeeded'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('存储目录不可用', $e->getMessage()); }
        } finally { unlink($this->root); }
        self::assertTrue($this->service->save(Fixture::input(), Fixture::scope())['readback_verified']);
    }

    public function testSourceReferencesRejectCredentialShapedTextBeforeStorage(): void
    {
        foreach (['Authorization: Bearer synthetic-only', 'https://example.invalid/data?sig=synthetic-only', 'cookie=synthetic-only', 'eyJfake.eyJfake.fake'] as $reference) {
            $input = Fixture::input(); $input['evidence']['observations'][0]['source_ref'] = $reference;
            try { $this->service->save($input, Fixture::scope()); self::fail('Unsafe source reference accepted'); }
            catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString($reference, $e->getMessage());
                self::assertDirectoryDoesNotExist($this->root);
            }
        }
    }

    public function testReplayIdentityPreservesFractionalCutoffs(): void
    {
        $input = Fixture::input();
        $input['evidence']['as_of_at'] = '2026-09-01T08:00:00.100000+08:00';
        $input['evidence']['evaluation_at'] = '2026-09-01T08:00:00.900000+08:00';
        $late = end($input['evidence']['observations']);
        $late['available_at'] = '2026-09-01T08:00:00.500000+08:00'; $late['value'] = 100;
        $input['evidence']['observations'][] = $late;
        $early = $this->service->preview($input, Fixture::scope())['replay'];
        self::assertSame($input['evidence']['as_of_at'], $early['as_of_at']);
        self::assertSame($input['evidence']['evaluation_at'], $early['evaluation_at']);
        self::assertStringContainsString('.100000+08:00', $early['comparisons'][7]['folds'][0]['origin_at']);
        $input['evidence']['as_of_at'] = '2026-09-01T08:00:00.700000+08:00';
        $later = $this->service->preview($input, Fixture::scope())['replay'];
        self::assertNotSame($early['as_of_at'], $later['as_of_at']);
        self::assertNotSame($early['forecasts'], $later['forecasts']);
    }

    public function testRfc3339ClockAndOffsetRangesAreEnforced(): void
    {
        foreach (['+24:00', '-24:00', '+08:60', '-99:00'] as $offset) {
            $input = Fixture::input(); $input['evidence']['evaluation_at'] = '2026-09-03T08:00:00' . $offset;
            try { $this->service->preview($input, Fixture::scope()); self::fail('Invalid offset accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testObservationScopeDoesNotCoerceBooleansOrNumbers(): void
    {
        foreach ([['tenant_id', 1, true], ['hotel_id', 90001, '90001'], ['platform_store_id', '123', 123]] as [$key, $scopeValue, $rowValue]) {
            $scope = Fixture::scope(); $scope[$key] = $scopeValue;
            $input = Fixture::input();
            foreach ($input['evidence']['observations'] as &$row) $row[$key] = $scopeValue;
            unset($row);
            $input['evidence']['observations'][0][$key] = $rowValue;
            try { $this->service->save($input, $scope); self::fail('Coerced scope accepted'); }
            catch (\InvalidArgumentException) { self::assertDirectoryDoesNotExist($this->root); }
        }
    }

    public function testOlderTrainingFailuresRemainVisibleWithEnoughReadySamples(): void
    {
        $input = Fixture::input();
        foreach ([200 => 'failed', 201 => 'missing'] as $index => $quality) {
            $row = $input['evidence']['observations'][$index];
            $row['available_at'] = '2026-09-01T07:00:00+08:00'; $row['quality_status'] = $quality; $row['value'] = null;
            $input['evidence']['observations'][] = $row;
        }
        $replay = $this->service->preview($input, Fixture::scope())['replay'];
        self::assertSame('partial', $replay['data_status']);
        self::assertSame(['ready' => 54, 'missing' => 1, 'failed' => 1, 'unobserved' => 0], $replay['input_evidence']['training_quality']);
        foreach ($replay['forecasts'] as $forecast) self::assertSame('partial', $forecast['status']);
    }

    public function testEnvelopeMetadataAndStoredSafetyFlagsCannotOverrideVerification(): void
    {
        $saved = $this->service->save(Fixture::input(), Fixture::scope());
        $path = (glob($this->root . '/*/*.json') ?: [])[0];
        $raw = (string)file_get_contents($path);
        foreach (['created_at' => '2026-01-01T00:00:00+00:00', 'readback_verified' => true, 'automatic_price_write' => true, 'causality_claimed' => true] as $key => $value) {
            $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); $doc[$key] = $value;
            file_put_contents($path, json_encode($doc, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $rejected = false;
            try { $this->service->read($saved['id'], Fixture::scope()); }
            catch (\RuntimeException) { $rejected = true; }
            self::assertTrue($rejected, 'Altered envelope accepted');
            self::assertFileExists($path);
        }
    }

    public function testConfiguredExternalStorageSurvivesServiceRestartAndMissingProductionPathFails(): void
    {
        $names = ['SUXIOS_FORECAST_PLAN_PATH', 'SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE'];
        $before = array_combine($names, array_map('getenv', $names));
        $originalRuntime = app()->getRuntimePath();
        try {
            putenv('SUXIOS_FORECAST_PLAN_PATH=' . $this->root);
            putenv('SUXIOS_REQUIRE_PERSISTENT_LOCAL_STATE=true');
            app()->setRuntimePath($this->root . '-release-a/runtime/');
            $saved = (new RevenueForecastWorkbenchService())->save(Fixture::input(), Fixture::scope());
            self::assertCount(1, glob($this->root . '/*/*.json') ?: []);
            app()->setRuntimePath($this->root . '-release-b/runtime/');
            self::assertSame($saved['payload'], (new RevenueForecastWorkbenchService())->read($saved['id'], Fixture::scope())['payload']);
            $next = Fixture::input(); $next['scenario']['proposed_price'] = 230;
            $nextSaved = (new RevenueForecastWorkbenchService())->save($next, Fixture::scope());
            app()->setRuntimePath($this->root . '-release-a/runtime/');
            self::assertSame(230, (new RevenueForecastWorkbenchService())->read($nextSaved['id'], Fixture::scope())['payload']['input']['scenario']['proposed_price']);
            self::assertCount(2, (new RevenueForecastWorkbenchService())->history(Fixture::scope()));
            app()->setRuntimePath($originalRuntime);
            foreach (['', 'runtime/forecasts', runtime_path() . 'forecasts', (string)getenv('SUXIOS_CACHE_PATH') . '/forecasts'] as $invalid) {
                putenv('SUXIOS_FORECAST_PLAN_PATH=' . $invalid);
                $service = new RevenueForecastWorkbenchService();
                self::assertSame('synthetic', $service->preview(Fixture::input(), Fixture::scope())['replay']['source_kind']);
                try { $service->save(Fixture::input(), Fixture::scope()); self::fail('Invalid production plan path accepted'); }
                catch (\RuntimeException $e) { self::assertStringContainsString('持久化', $e->getMessage()); }
            }
        } finally {
            app()->setRuntimePath($originalRuntime);
            foreach ($before as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
        }
    }
}
