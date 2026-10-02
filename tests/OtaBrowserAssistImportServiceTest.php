<?php
declare(strict_types=1);

namespace tests;

use app\service\OtaBrowserAssistImportService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OtaBrowserAssistImportServiceTest extends TestCase
{
    use ReflectionHelper;

    #[\PHPUnit\Framework\Attributes\DataProvider('unprovenHookBusinessDates')]
    public function testHookRowsWithMissingOrInvalidBusinessDatesNeverEnterImportPackages(array $overrides, array $itemOverrides, string $expectedWarningCode): void
    {
        $payload = array_replace($this->syntheticHookCapture(), $overrides);
        $payload['meituanHook']['FLOW_CONV_0'] = array_replace($payload['meituanHook']['FLOW_CONV_0'], $itemOverrides);
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        $result = $service->normalizeCapturePackages($payload);
        self::assertSame([], $result['rows']);
        self::assertSame([], $result['packages']);
        $dateWarnings = array_values(array_filter($result['warnings'], fn(array $warning): bool => $warning['code'] === $expectedWarningCode));
        self::assertCount(1, $dateWarnings);
        self::assertSame('meituan', $dateWarnings[0]['platform']);
        self::assertStringStartsWith('meituan_hook', $dateWarnings[0]['module']);
        self::assertSame('meituan_hook.FLOW_CONV_0.data', $dateWarnings[0]['source_path']);
        if ($expectedWarningCode === 'data_date_invalid') {
            self::assertStringContainsString('snapshot time was not used as a fallback', $dateWarnings[0]['message']);
        } elseif ($expectedWarningCode === 'source_timestamp_invalid') {
            self::assertStringContainsString('normalizer-generated time was not used as a business date', $dateWarnings[0]['message']);
        } else {
            self::assertStringContainsString('no data_date could be proven', $dateWarnings[0]['message']);
        }
        self::assertContains('hook_rows_missing', array_column($result['warnings'], 'code'));
    }

    public static function unprovenHookBusinessDates(): array
    {
        return [
            'missing date and source time' => [[], [], 'data_date_missing'],
            'invalid source time cannot use generation time' => [[], ['capturedAt' => '2026-02-30 12:00:00'], 'source_timestamp_invalid'],
            'invalid item date cannot use valid source time' => [[], ['data_date' => '2026-02-30', 'capturedAt' => '2026-08-23 12:00:00'], 'data_date_invalid'],
            'invalid context date cannot use valid source time' => [['data_date' => '2026-02-30'], ['capturedAt' => '2026-08-23 12:00:00'], 'data_date_invalid'],
        ];
    }

    public function testForecastRejectsInvalidTargetDateAndPreservesValidTargetZeroAndOtherPlatform(): void
    {
        $payload = $this->syntheticHookCapture();
        $payload['meituanHook'] = ['FORECAST_2' => ['rankType' => 'FORECAST', 'forecastType' => '2',
            'data_date' => '2026-08-24', 'capturedAt' => '2026-08-23 12:00:00',
            'data' => ['detail' => [['dateTime' => '2026-02-30', 'current' => 9], ['dateTime' => '20260826', 'current' => 0]]],
        ]];
        $payload['ctrip'] = ['rooms' => [['name' => 'Synthetic other channel', 'days' => [['date' => '2026-08-21', 'remain' => 0]]]]];
        $original = $payload;
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        $result = $service->normalizeCapturePackages($payload);
        self::assertSame($original, $payload);
        self::assertSame(['ctrip', 'meituan'], array_column($result['rows'], 'source'));
        self::assertSame(['2026-08-21', '2026-08-26'], array_column($result['rows'], 'data_date'));
        self::assertCount(2, $result['packages']);
        self::assertSame(0.0, $result['rows'][0]['inventory_remaining']);
        $forecast = $result['rows'][1];
        self::assertSame('traffic_forecast', $forecast['data_type']);
        self::assertSame('next_30_days', $forecast['data_period']);
        self::assertSame('signal_only', $forecast['raw_data']['quality_status']);
        self::assertSame('source_timestamp', $forecast['raw_data']['snapshot_time_source']);
        self::assertSame(0.0, $forecast['data_value']);
        self::assertSame(80, $forecast['system_hotel_id']);
        self::assertSame('browser_assist_dom:meituan_hook', $forecast['capture_evidence']['capture_source']);
        self::assertSame('meituan_hook.FORECAST_2.data.detail.1', $forecast['capture_evidence']['source_path']);
        $dateWarnings = array_values(array_filter($result['warnings'], fn(array $warning): bool => $warning['code'] === 'data_date_invalid'));
        self::assertCount(1, $dateWarnings);
        self::assertSame('meituan', $dateWarnings[0]['platform']);
        self::assertSame('meituan_hook_traffic_forecast', $dateWarnings[0]['module']);
        self::assertSame('meituan_hook.FORECAST_2.data.detail.0', $dateWarnings[0]['source_path']);
        self::assertStringContainsString('explicit target date is invalid', $dateWarnings[0]['message']);
        self::assertStringContainsString('snapshot time was not used as a fallback', $dateWarnings[0]['message']);
    }

    public function testForecastWithTrulyMissingDetailDateKeepsMissingWarning(): void
    {
        $payload = $this->syntheticHookCapture();
        $payload['meituanHook'] = ['FORECAST_2' => ['rankType' => 'FORECAST', 'forecastType' => '2',
            'data' => ['detail' => [['current' => 0]]],
        ]];
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        $result = $service->normalizeCapturePackages($payload);
        self::assertSame([], $result['rows']);
        self::assertSame([], $result['packages']);
        $dateWarnings = array_values(array_filter($result['warnings'], fn(array $warning): bool => $warning['code'] === 'data_date_missing'));
        self::assertCount(1, $dateWarnings);
        self::assertSame('meituan', $dateWarnings[0]['platform']);
        self::assertSame('meituan_hook_traffic_forecast', $dateWarnings[0]['module']);
        self::assertSame('meituan_hook.FORECAST_2.data.detail.0', $dateWarnings[0]['source_path']);
        self::assertStringContainsString('no data_date could be proven', $dateWarnings[0]['message']);
    }

    public function testNestedHookRowsKeepParentDateFailuresDistinctFromMissing(): void
    {
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        foreach ([
            ['label' => 'invalid', 'data_date' => '2026-02-30', 'capturedAt' => '2026-08-23 12:00:00', 'code' => 'data_date_invalid'],
            ['label' => 'invalid source time', 'data_date' => null, 'capturedAt' => '2026-02-30 12:00:00', 'code' => 'source_timestamp_invalid'],
            ['label' => 'missing', 'data_date' => null, 'capturedAt' => null, 'code' => 'data_date_missing'],
        ] as $case) {
            $peer = ['rankType' => 'P_RZ', 'source' => 'peer', 'data' => ['peerRankData' => [[
                'dimName' => 'Synthetic ranking', 'roundRanks' => [['poiId' => 'peer-1', 'rank' => 2, 'dataValue' => 0]],
            ]]]];
            $keyword = ['rankType' => 'KEYWORDS', 'source' => 'keywords', 'data' => ['cards' => [[
                'title' => 'Synthetic terms', 'itemList' => [['name' => 'airport', 'value' => 0]],
            ]]]];
            $flowSource = ['rankType' => 'FLOW_SRC', 'source' => 'flow', 'data' => ['list' => [[
                'name' => 'Synthetic organic exposure', 'value' => 0,
            ]]]];
            if ($case['data_date'] !== null) {
                $peer['data_date'] = $case['data_date'];
                $keyword['data_date'] = $case['data_date'];
                $flowSource['data_date'] = $case['data_date'];
            }
            if ($case['capturedAt'] !== null) {
                $peer['capturedAt'] = $case['capturedAt'];
                $keyword['capturedAt'] = $case['capturedAt'];
                $flowSource['capturedAt'] = $case['capturedAt'];
            }
            $payload = $this->syntheticHookCapture();
            $payload['meituanHook'] = ['P_RZ_0' => $peer, 'KEYWORDS' => $keyword, 'FLOW_SRC_0' => $flowSource];
            $result = $service->normalizeCapturePackages($payload);
            self::assertSame([], $result['rows'], $case['label']);
            self::assertSame([], $result['packages'], $case['label']);
            $dateWarnings = array_values(array_filter($result['warnings'], fn(array $warning): bool => $warning['code'] === $case['code']));
            self::assertCount(3, $dateWarnings, $case['label']);
            self::assertSame([
                ['meituan_hook_peer_rank', 'meituan_hook.P_RZ_0.data.peerRankData.0.roundRanks.0'],
                ['meituan_hook_search_keyword', 'meituan_hook.KEYWORDS.data.cards.0.itemList.0'],
                ['meituan_hook_flow_source', 'meituan_hook.FLOW_SRC_0.data.list.0'],
            ], array_map(static fn(array $warning): array => [$warning['module'], $warning['source_path']], $dateWarnings));
            self::assertSame(['meituan', 'meituan', 'meituan'], array_column($dateWarnings, 'platform'));
            if ($case['code'] === 'data_date_invalid') {
                self::assertStringContainsString('explicit business date is invalid', $dateWarnings[0]['message']);
                self::assertStringContainsString('snapshot time was not used as a fallback', $dateWarnings[0]['message']);
            } elseif ($case['code'] === 'source_timestamp_invalid') {
                self::assertStringContainsString('source timestamp is invalid', $dateWarnings[0]['message']);
                self::assertStringContainsString('normalizer-generated time was not used as a business date', $dateWarnings[0]['message']);
            } else {
                self::assertStringContainsString('no data_date could be proven', $dateWarnings[0]['message']);
            }
        }
    }

    public function testValidHookSourceDayAndExplicitBusinessDayPreserveZeroAndProvenance(): void
    {
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        foreach ([[null, '2026-08-23'], ['20260822', '2026-08-22']] as [$explicitDate, $expectedDate]) {
            $payload = $this->syntheticHookCapture();
            $payload['meituanHook']['FLOW_CONV_0']['capturedAt'] = '2026-08-23 12:00:00';
            if ($explicitDate !== null) $payload['meituanHook']['FLOW_CONV_0']['data_date'] = $explicitDate;
            $result = $service->normalizeCapturePackages($payload);
            self::assertCount(1, $result['packages']);
            self::assertSame([], $result['warnings']);
            $row = $result['rows'][0];
            self::assertSame($expectedDate, $row['data_date']);
            self::assertSame('source_timestamp', $row['raw_data']['snapshot_time_source']);
            self::assertSame('2026-08-23 12:00:00', $row['snapshot_time']);
            self::assertSame(0.0, $row['detail_exposure']);
            self::assertSame(80, $row['system_hotel_id']);
            self::assertSame('meituan', $row['platform']);
            self::assertSame('browser_assist_dom:meituan_hook', $row['capture_evidence']['capture_source']);
        }
    }

    private function syntheticHookCapture(): array
    {
        return ['system_hotel_id' => 80, 'generatedAt' => '2026-09-15 10:00:00',
            'meituanHook' => ['FLOW_CONV_0' => ['rankType' => 'FLOW_CONV', 'dateRange' => '0',
                'source' => 'flow', 'data' => ['visitCount' => 0]]],
        ];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInterruptedImportPreservesPriorReceiptsAndMarksUnknownAndUnattemptedPackages(): void
    {
        [$service, $sync] = $this->syntheticImportService([
            $this->syntheticPackageReceipt('success', 2, 101),
            new \RuntimeException('synthetic-private-upstream-detail', 403),
            $this->syntheticPackageReceipt('success', 1, 103),
        ]);
        $payload = $this->syntheticThreePackageCapture();
        $original = $payload;
        $result = $service->importCapture(null, $payload);

        self::assertCount(2, $sync->calls, 'An uncertain package must stop all later imports.');
        self::assertSame($original, $payload);
        self::assertSame('partial_success', $result['status']);
        self::assertSame(2, $result['saved_count']);
        self::assertFalse($result['saved_count_complete']);
        self::assertSame(1, $result['unconfirmed_package_count']);
        self::assertSame(3, $result['package_count']);
        self::assertSame(['success', 'unknown', 'not_attempted'], array_column($result['packages'], 'status'));
        self::assertSame(2, $result['packages'][0]['saved_count']);
        self::assertTrue($result['packages'][0]['readback_verified']);
        self::assertSame(101, $result['packages'][0]['sync_task_id']);
        $uncertain = $result['packages'][1];
        foreach (['normalized_count', 'saved_count', 'readback_verified', 'sync_task_id'] as $field) {
            self::assertArrayHasKey($field, $uncertain);
            self::assertNull($uncertain[$field], $field);
        }
        self::assertSame('browser_assist_package_outcome_unknown', $uncertain['code']);
        self::assertSame('分包导入中断，保存结果尚未确认；请核对任务和回读结果后再处理。', $uncertain['message']);
        self::assertSame('browser_assist_package_not_attempted', $result['packages'][2]['code']);
        self::assertSame(0, $result['packages'][2]['saved_count']);
        self::assertStringNotContainsString('synthetic-private-upstream-detail', json_encode($result, JSON_UNESCAPED_UNICODE));
        $this->assertSyntheticPackageScopes($result);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnedPackageReceiptsKeepSuccessFailureCountsAndSeparateRequestScopes(): void
    {
        foreach ([['success', 'success', 'success'], ['success', 'failed', 'success'], ['failed', 'failed', 'failed']] as $statuses) {
            $receipts = array_map(fn(string $status, int $index): array => $this->syntheticPackageReceipt($status, $index === 0 ? 2 : 1, 101 + $index), $statuses, [0, 1, 2]);
            [$service, $sync] = $this->syntheticImportService($receipts);
            $result = $service->importCapture(null, $this->syntheticThreePackageCapture());
            self::assertCount(3, $sync->calls);
            self::assertSame($statuses, array_column($result['packages'], 'status'));
            self::assertSame(array_sum(array_column($receipts, 'saved_count')), $result['saved_count']);
            self::assertTrue($result['saved_count_complete']);
            self::assertSame(0, $result['unconfirmed_package_count']);
            self::assertSame(count(array_unique($statuses)) === 1 ? $statuses[0] : 'partial_success', $result['status']);
            self::assertSame(array_column($receipts, 'readback_verified'), array_column($result['packages'], 'readback_verified'));
            $this->assertSyntheticPackageScopes($result);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInterruptionAfterReturnedFailureDoesNotImplyZeroTotalWrites(): void
    {
        [$service, $sync] = $this->syntheticImportService([
            $this->syntheticPackageReceipt('failed', 2, 101),
            new \Error('synthetic-internal-error'),
        ]);
        $result = $service->importCapture(null, $this->syntheticThreePackageCapture());
        self::assertCount(2, $sync->calls);
        self::assertSame('failed', $result['status']);
        self::assertSame(0, $result['saved_count'], 'This is only the returned receipt subtotal.');
        self::assertFalse($result['saved_count_complete']);
        self::assertSame(1, $result['unconfirmed_package_count']);
        self::assertNull($result['packages'][1]['saved_count']);
        self::assertSame('not_attempted', $result['packages'][2]['status']);
        self::assertStringNotContainsString('synthetic-internal-error', json_encode($result));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFirstPackageExceptionKeepsExistingAuthorizationAndValidationErrors(): void
    {
        foreach ([401, 403, 422] as $code) {
            $expected = new \RuntimeException('synthetic first package failure', $code);
            [$service, $sync] = $this->syntheticImportService([$expected]);
            try {
                $service->importCapture(null, $this->syntheticThreePackageCapture());
                self::fail('The first exception must retain the existing controller behavior.');
            } catch (\RuntimeException $actual) {
                self::assertSame($expected, $actual);
                self::assertCount(1, $sync->calls);
            }
        }
    }

    private function syntheticImportService(array $responses): array
    {
        // Each caller runs in its own PHP process. Never load the real sync service or a database.
        if (!class_exists(\app\service\PlatformDataSyncService::class, false)) {
            eval(<<<'PHP'
namespace app\service;
final class PlatformDataSyncService {
    public const SYNTHETIC_ONLY = true;
    public array $calls = [];
    public function __construct(private array $responses) {}
    public function importRows($user, array $payload): array {
        $this->calls[] = $payload;
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) throw $response;
        return $response;
    }
}
PHP);
        }
        self::assertTrue(defined(\app\service\PlatformDataSyncService::class . '::SYNTHETIC_ONLY'));
        $sync = new \app\service\PlatformDataSyncService($responses);
        return [new OtaBrowserAssistImportService($sync), $sync];
    }

    private function syntheticPackageReceipt(string $status, int $rowCount, int $taskId): array
    {
        return ['status' => $status, 'message' => 'synthetic_' . $status, 'normalized_count' => $rowCount,
            'saved_count' => $status === 'success' ? $rowCount : 0,
            'readback_verified' => $status === 'success', 'task_id' => $taskId];
    }

    private function syntheticThreePackageCapture(): array
    {
        return ['system_hotel_id' => 80, 'generatedAt' => '2026-09-15 10:00:00',
            'ctrip' => ['rooms' => [['name' => 'Synthetic Ctrip room', 'days' => [
                ['date' => '2026-08-24', 'remain' => 0], ['date' => '2026-08-23', 'remain' => 1],
            ]]]],
            'meituan' => ['rooms' => [['name' => 'Synthetic Meituan room', 'days' => [
                ['date' => '2026-08-22', 'remain' => 1],
            ]]]],
            'ctripStats' => ['dataDate' => '2026-08-25', 'metrics' => ['ctrip' => ['realtimeVisitors' => 0]]],
        ];
    }

    private function assertSyntheticPackageScopes(array $result): void
    {
        foreach ([['ctrip', 'inventory', ['2026-08-23', '2026-08-24']], ['meituan', 'inventory', ['2026-08-22']], ['ctrip', 'traffic', ['2026-08-25']]] as $index => [$platform, $type, $dates]) {
            self::assertSame(['scope_type' => 'parsed_import_request', 'system_hotel_id' => 80,
                'platform' => $platform, 'data_type' => $type, 'business_dates' => $dates], $result['packages'][$index]['request_scope']);
            self::assertArrayNotHasKey('readback_verified', $result['packages'][$index]['request_scope']);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sourceTimestampTimezones')]
    public function testOffsetSourceTimesMatchEpochInTheApplicationTimezone(string $timezone, string $expected): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set($timezone);
        try {
            $service = new OtaBrowserAssistImportService();
            $epoch = (new \DateTimeImmutable('2026-08-23T23:30:00Z'))->getTimestamp();
            foreach (['2026-08-23T23:30:00Z', '2026-08-23T23:30:00.123Z',
                '2026-08-24T07:30:00+08:00', '2026-08-24T07:30:00+08',
                '2026-08-23T19:30:00-04:00', $epoch, $epoch * 1000] as $timestamp) {
                $payload = $this->syntheticRealtimeCapture();
                $payload['snapshot_time'] = $timestamp;
                $payload['ctripStats']['updatedAt'] = $timestamp;
                $original = $payload;
                $rows = $service->normalizeCapturePackages($payload)['rows'];
                self::assertSame([$expected, $expected], array_column($rows, 'snapshot_time'), (string)$timestamp);
                self::assertSame([substr($expected, 0, 10), substr($expected, 0, 10)], array_column($rows, 'data_date'));
                self::assertSame($original, $payload);
                foreach ($rows as $row) {
                    self::assertSame('source_timestamp', $row['raw_data']['snapshot_time_source']);
                    self::assertStringStartsWith('browser_assist_dom:', $row['capture_evidence']['capture_source']);
                }
            }
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public static function sourceTimestampTimezones(): array
    {
        return [
            'Shanghai crosses midnight' => ['Asia/Shanghai', '2026-08-24 07:30:00'],
            'UTC follows application timezone' => ['UTC', '2026-08-23 23:30:00'],
        ];
    }

    public function testOffsetCaptureTimeDoesNotRewriteAnExplicitPlatformBusinessDate(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Shanghai');
        try {
            $payload = $this->syntheticRealtimeCapture();
            $payload['snapshot_time'] = '2026-08-23T23:30:00Z';
            $payload['data_date'] = '2026-08-23';
            $payload['meituanStats']['dataDate'] = '2026-08-22';
            $service = new OtaBrowserAssistImportService();
            $rows = $service->normalizeCapturePackages($payload)['rows'];
            self::assertSame(['2026-08-24 07:30:00', '2026-08-24 07:30:00'], array_column($rows, 'snapshot_time'));
            self::assertSame(['2026-08-23', '2026-08-22'], array_column($rows, 'data_date'));
            $payload['snapshot_time'] = '2026-08-23 23:30:00';
            $rows = $service->normalizeCapturePackages($payload)['rows'];
            self::assertSame(['2026-08-23 23:30:00', '2026-08-23 23:30:00'], array_column($rows, 'snapshot_time'));
            self::assertSame(['2026-08-23', '2026-08-22'], array_column($rows, 'data_date'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBusinessDates')]
    public function testInvalidExplicitBusinessDateCannotFallBackToAValidSnapshot(string $date): void
    {
        $payload = $this->syntheticRealtimeCapture();
        $payload['data_date'] = $date;
        $payload['snapshot_time'] = '2026-09-15 10:00:00';
        $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages($payload);
        self::assertSame(0, $result['summary']['row_count']);
        self::assertSame([], $result['packages']);
        $dateWarnings = array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_')));
        self::assertSame(['data_date_invalid', 'data_date_invalid'], array_column($dateWarnings, 'code'));
        self::assertSame(['ctrip', 'meituan'], array_column($dateWarnings, 'platform'));
        self::assertSame(['ctrip_stats', 'meituan_stats'], array_column($dateWarnings, 'module'));
        self::assertSame(['ctrip_stats.metrics.ctrip', 'meituan_stats.metrics'], array_column($dateWarnings, 'source_path'));
        foreach ($dateWarnings as $warning) {
            self::assertStringContainsString('invalid', strtolower($warning['message']));
            self::assertStringContainsString('snapshot time was not used', strtolower($warning['message']));
        }
    }

    public function testRealtimeMetricDatesSkipBlankSectionFieldsButKeepInvalidExplicitDatesDistinct(): void
    {
        $validContext = $this->syntheticRealtimeCapture();
        $validContext['data_date'] = '2026-08-23';
        $validContext['ctripStats']['dataDate'] = '   ';
        $validContext['ctripStats']['updatedAt'] = '2026-08-24 12:00:00';
        $validContext['meituanStats']['data_date'] = '';
        $validContext['meituanStats']['updatedAt'] = '2026-08-25 12:00:00';
        $validRows = (new OtaBrowserAssistImportService())->normalizeCapturePackages($validContext);
        self::assertSame(['ctrip', 'meituan'], array_column($validRows['rows'], 'platform'));
        self::assertSame(['2026-08-23', '2026-08-23'], array_column($validRows['rows'], 'data_date'));
        self::assertSame(['2026-08-24 12:00:00', '2026-08-25 12:00:00'], array_column($validRows['rows'], 'snapshot_time'));
        self::assertSame([0.0, 0.0], array_column($validRows['rows'], 'detail_exposure'));
        self::assertSame([], array_values(array_filter($validRows['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_') || $warning['code'] === 'source_timestamp_invalid')));
        self::assertSame(['ctrip', 'meituan'], array_column($validRows['packages'], 'platform'));

        $invalidCases = [];
        $invalidSection = $validContext;
        $invalidSection['ctripStats']['dataDate'] = '2026-02-30';
        $invalidSection['meituanStats']['data_date'] = '2026-02-30';
        $invalidCases[] = $invalidSection;
        $invalidContext = $validContext;
        $invalidContext['data_date'] = '2026-02-30';
        $invalidContext['ctripStats']['dataDate'] = ' ';
        $invalidContext['meituanStats']['data_date'] = '';
        $invalidCases[] = $invalidContext;
        foreach ($invalidCases as $payload) {
            $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages($payload);
            self::assertSame([], $result['rows']);
            self::assertSame([], $result['packages']);
            $dateWarnings = array_values(array_filter($result['warnings'],
                static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_')));
            self::assertSame(['data_date_invalid', 'data_date_invalid'], array_column($dateWarnings, 'code'));
            self::assertSame(['ctrip', 'meituan'], array_column($dateWarnings, 'platform'));
            self::assertSame(['ctrip_stats', 'meituan_stats'], array_column($dateWarnings, 'module'));
            self::assertSame(['ctrip_stats.metrics.ctrip', 'meituan_stats.metrics'], array_column($dateWarnings, 'source_path'));
            foreach ($dateWarnings as $warning) {
                self::assertStringContainsString('explicit business date is invalid', $warning['message']);
                self::assertStringContainsString('snapshot time was not used as a fallback', $warning['message']);
            }
        }

        $contextTimestamp = $this->syntheticRealtimeCapture();
        $contextTimestamp['snapshot_time'] = '2026-08-23 23:30:00';
        $contextTimestamp['ctripStats']['updatedAt'] = ' ';
        $contextTimestamp['meituanStats']['updatedAt'] = '';
        $timestampRows = (new OtaBrowserAssistImportService())->normalizeCapturePackages($contextTimestamp);
        self::assertSame(['2026-08-23', '2026-08-23'], array_column($timestampRows['rows'], 'data_date'));
        self::assertSame(['2026-08-23 23:30:00', '2026-08-23 23:30:00'], array_column($timestampRows['rows'], 'snapshot_time'));

        $invalidTimestamp = $this->syntheticRealtimeCapture();
        $invalidTimestamp['ctripStats']['updatedAt'] = '2026-02-30 12:00:00';
        $invalidTimestamp['meituanStats']['updatedAt'] = '2026-02-30 12:00:00';
        $timestampFailure = (new OtaBrowserAssistImportService())->normalizeCapturePackages($invalidTimestamp);
        self::assertSame([], $timestampFailure['rows']);
        $timestampWarnings = array_values(array_filter($timestampFailure['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'source_timestamp_invalid'));
        self::assertSame(['source_timestamp_invalid', 'source_timestamp_invalid'], array_column($timestampWarnings, 'code'));
        foreach ($timestampWarnings as $warning) {
            self::assertStringContainsString('source timestamp is invalid', $warning['message']);
            self::assertStringContainsString('normalizer-generated time was not used as a business date', $warning['message']);
        }

        $topLevelTimestamp = $this->syntheticRealtimeCapture();
        $topLevelTimestamp['snapshot_time'] = '2026-02-30 12:00:00';
        $topLevelFailure = (new OtaBrowserAssistImportService())->normalizeCapturePackages($topLevelTimestamp);
        self::assertSame([], $topLevelFailure['rows']);
        $topLevelWarnings = array_values(array_filter($topLevelFailure['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'source_timestamp_invalid'));
        self::assertSame(['source_timestamp_invalid', 'source_timestamp_invalid'], array_column($topLevelWarnings, 'code'));
        self::assertSame(['ctrip_stats', 'meituan_stats'], array_column($topLevelWarnings, 'module'));

        $wrappedHook = ['generatedAt' => '2026-09-15 10:00:00', 'capture' => [
            'snapshot_time' => '2026-02-30 12:00:00',
            'FLOW_CONV_0' => ['rankType' => 'FLOW_CONV', 'dateRange' => '0', 'source' => 'synthetic', 'data' => ['visitCount' => 0]],
        ]];
        $wrappedFailure = (new OtaBrowserAssistImportService())->normalizeCapturePackages($wrappedHook);
        self::assertSame([], $wrappedFailure['rows']);
        $wrappedWarnings = array_values(array_filter($wrappedFailure['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'source_timestamp_invalid'));
        self::assertSame(['source_timestamp_invalid'], array_column($wrappedWarnings, 'code'));
        self::assertSame(['meituan_hook_flow_conversion'], array_column($wrappedWarnings, 'module'));
        self::assertSame(['meituan_hook.FLOW_CONV_0.data'], array_column($wrappedWarnings, 'source_path'));

        $missing = (new OtaBrowserAssistImportService())->normalizeCapturePackages($this->syntheticRealtimeCapture());
        self::assertSame([], $missing['rows']);
        $missingWarnings = array_values(array_filter($missing['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_')));
        self::assertSame(['data_date_missing', 'data_date_missing'], array_column($missingWarnings, 'code'));
    }

    public static function invalidBusinessDates(): array
    {
        return array_map(static fn(string $date): array => [$date], [
            '2026-02-30', '2025-02-29', '2026/13/01', '2026.00.01',
            '2026-09-00', '20260230', '2026-09-15 trailing',
        ]);
    }

    public function testBlankContextBusinessDatesDeferToAliasesAndCaptureWhileInvalidTopLevelDatesFailClosed(): void
    {
        $service = new OtaBrowserAssistImportService();
        $aliasPayload = $this->syntheticRealtimeCapture();
        $aliasPayload['data_date'] = '  ';
        $aliasPayload['dataDate'] = '2026-08-23';
        $aliasResult = $service->normalizeCapturePackages($aliasPayload);
        self::assertSame(['2026-08-23', '2026-08-23'], array_column($aliasResult['rows'], 'data_date'));
        self::assertSame([0.0, 0.0], array_column($aliasResult['rows'], 'detail_exposure'));
        self::assertSame([], array_values(array_filter($aliasResult['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_'))));

        $wrappedPayload = [
            'system_hotel_id' => 80,
            'data_date' => '',
            'capture' => [
                'data_date' => '2026-08-23',
                'ctripStats' => ['metrics' => ['ctrip' => ['realtimeVisitors' => 0]]],
                'meituanStats' => ['metrics' => ['browseUsers' => 0]],
            ],
        ];
        $wrappedResult = $service->normalizeCapturePackages($wrappedPayload);
        self::assertSame(['2026-08-23', '2026-08-23'], array_column($wrappedResult['rows'], 'data_date'));
        self::assertSame([80, 80], array_column($wrappedResult['rows'], 'system_hotel_id'));

        $wrappedPayload['data_date'] = '2026-02-30';
        $invalidResult = $service->normalizeCapturePackages($wrappedPayload);
        self::assertSame([], $invalidResult['rows']);
        self::assertSame(['data_date_invalid', 'data_date_invalid'], array_column(
            array_values(array_filter($invalidResult['warnings'],
                static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_'))),
            'code'
        ));
    }

    public function testInventoryDistinguishesInvalidAndMissingDatesWithoutUsingSnapshotTime(): void
    {
        $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages([
            'system_hotel_id' => 80,
            'generatedAt' => '2026-09-15 10:00:00',
            'ctrip' => ['capturedAt' => '2026-08-23 12:00:00', 'rooms' => [['name' => 'Synthetic room', 'days' => [
                ['date' => '2026-02-30', 'remain' => 4],
                ['date' => '2024/02/29', 'remain' => 0],
                ['remain' => 3],
            ]]]],
        ]);
        self::assertSame(1, $result['summary']['row_count']);
        self::assertCount(1, $result['packages']);
        self::assertSame('2024-02-29', $result['rows'][0]['data_date']);
        self::assertSame(0.0, $result['rows'][0]['inventory_remaining']);
        self::assertSame('source_timestamp', $result['rows'][0]['raw_data']['snapshot_time_source']);
        $dateWarnings = array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_')));
        self::assertSame(['data_date_invalid', 'data_date_missing'], array_column($dateWarnings, 'code'));
        self::assertSame(['ctrip', 'ctrip'], array_column($dateWarnings, 'platform'));
        self::assertSame(['ctrip_inventory', 'ctrip_inventory'], array_column($dateWarnings, 'module'));
        self::assertSame([
            'ctrip_inventory.rooms.0.days.0', 'ctrip_inventory.rooms.0.days.2',
        ], array_column($dateWarnings, 'source_path'));
        self::assertStringContainsString('explicit business date is invalid', $dateWarnings[0]['message']);
        self::assertStringContainsString('snapshot time was not used as a fallback', $dateWarnings[0]['message']);
        self::assertSame('Inventory row skipped because no data_date could be proven.', $dateWarnings[1]['message']);
    }

    public function testInventoryInheritsSectionDateOnlyForBlankDayDates(): void
    {
        $result = (new \ReflectionClass(OtaBrowserAssistImportService::class))
            ->newInstanceWithoutConstructor()
            ->normalizeCapturePackages([
                'system_hotel_id' => 80,
                'generatedAt' => '2026-09-15 10:00:00',
                'ctrip' => ['data_date' => '2026-08-23', 'capturedAt' => '2026-08-23 12:00:00',
                    'rooms' => [['name' => 'Synthetic Ctrip room', 'days' => [
                        ['date' => '', 'remain' => 0],
                        ['date' => '   ', 'remain' => 1],
                        ['date' => '2026-02-30', 'remain' => 4],
                        ['date' => '2026-08-24', 'remain' => 2],
                    ]]],
                ],
                'meituan' => ['dataDate' => '2024-02-29', 'capturedAt' => '2026-08-23 12:00:00',
                    'rooms' => [['name' => 'Synthetic Meituan room', 'days' => [
                        ['date' => '', 'remain' => 0],
                        ['date' => '2026-02-30', 'remain' => 5],
                    ]]],
                ],
            ]);

        self::assertSame(['ctrip', 'ctrip', 'ctrip', 'meituan'], array_column($result['rows'], 'platform'));
        self::assertSame(['2026-08-23', '2026-08-23', '2026-08-24', '2024-02-29'], array_column($result['rows'], 'data_date'));
        self::assertSame([0.0, 1.0, 2.0, 0.0], array_column($result['rows'], 'inventory_remaining'));
        self::assertSame([80, 80, 80, 80], array_column($result['rows'], 'system_hotel_id'));
        self::assertSame(['ctrip', 'meituan'], array_column($result['packages'], 'platform'));
        self::assertSame([3, 1], array_map(static fn(array $package): int => count($package['rows']), $result['packages']));
        self::assertSame([
            'ctrip_inventory.rooms.0.days.2', 'meituan_inventory.rooms.0.days.1',
        ], array_column(array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'data_date_invalid')), 'source_path'));

        $invalidParent = (new \ReflectionClass(OtaBrowserAssistImportService::class))
            ->newInstanceWithoutConstructor()
            ->normalizeCapturePackages([
                'generatedAt' => '2026-09-15 10:00:00',
                'ctrip' => ['data_date' => '2026-02-30', 'capturedAt' => '2026-08-23 12:00:00',
                    'rooms' => [['name' => 'Synthetic invalid-parent room', 'days' => [['date' => ' ', 'remain' => 0]]]],
                ],
            ]);
        self::assertSame([], $invalidParent['rows']);
        self::assertSame(['data_date_invalid'], array_column(array_values(array_filter($invalidParent['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_'))), 'code'));

        $missing = (new \ReflectionClass(OtaBrowserAssistImportService::class))
            ->newInstanceWithoutConstructor()
            ->normalizeCapturePackages([
                'generatedAt' => '2026-09-15 10:00:00',
                'ctrip' => ['capturedAt' => '2026-08-23 12:00:00', 'rooms' => [['name' => 'Synthetic undated room', 'days' => [
                    ['date' => '   ', 'remain' => 3],
                ]]]],
            ]);
        self::assertSame([], $missing['rows']);
        $missingDateWarnings = array_values(array_filter($missing['warnings'],
            static fn(array $warning): bool => str_starts_with($warning['code'], 'data_date_')));
        self::assertSame(['data_date_missing'], array_column($missingDateWarnings, 'code'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSourceTimestamps')]
    public function testInvalidSourceTimestampCannotCreateRealtimeMetricRows(string $timestamp): void
    {
        $payload = $this->syntheticRealtimeCapture();
        $payload['ctripStats']['updatedAt'] = $timestamp;
        $payload['meituanStats']['updatedAt'] = $timestamp;
        $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages($payload);
        self::assertSame(0, $result['summary']['row_count']);
        self::assertSame([], $result['packages']);
        $timestampWarnings = array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => in_array($warning['code'], ['data_date_missing', 'source_timestamp_invalid'], true)));
        self::assertSame(['source_timestamp_invalid', 'source_timestamp_invalid'], array_column($timestampWarnings, 'code'));
        self::assertSame(['ctrip', 'meituan'], array_column($timestampWarnings, 'platform'));
        self::assertSame(['ctrip_stats', 'meituan_stats'], array_column($timestampWarnings, 'module'));
        self::assertSame(['ctrip_stats.metrics.ctrip', 'meituan_stats.metrics'], array_column($timestampWarnings, 'source_path'));
        foreach ($timestampWarnings as $warning) {
            self::assertStringContainsString('source timestamp is invalid', strtolower($warning['message']));
            self::assertStringContainsString('generated time was not used as a business date', strtolower($warning['message']));
        }
    }

    public static function invalidSourceTimestamps(): array
    {
        return array_map(static fn(string $timestamp): array => [$timestamp], [
            '2026-02-30 12:00:00', '2026-09-15 24:00:00', '2026-09-15 12:60',
            '2026-09-15 12:00:60', '2026-09-15 12:00:00junk',
            '2026-09-15 12:00:00:20', '2026-09-15 12:00x',
        ]);
    }

    public function testValidDateFormsAndEpochTimestampsRemainCompatible(): void
    {
        $service = new OtaBrowserAssistImportService();
        foreach (['2024-02-29', '2024/2/29', '2024.2.29', '20240229'] as $date) {
            $payload = $this->syntheticRealtimeCapture();
            $payload['data_date'] = $date;
            $rows = $service->normalizeCapturePackages($payload)['rows'];
            self::assertSame(['2024-02-29', '2024-02-29'], array_column($rows, 'data_date'));
        }
        $epoch = strtotime('2026-09-15 10:00:00');
        foreach ([$epoch, $epoch * 1000, (string)$epoch, (string)($epoch * 1000),
            '2026/9/15 10:00', '2026.9.15T10:00:00'] as $timestamp) {
            $payload = $this->syntheticRealtimeCapture();
            $payload['snapshot_time'] = $timestamp;
            $rows = $service->normalizeCapturePackages($payload)['rows'];
            self::assertSame(['2026-09-15', '2026-09-15'], array_column($rows, 'data_date'));
            self::assertSame(['2026-09-15 10:00:00', '2026-09-15 10:00:00'], array_column($rows, 'snapshot_time'));
        }
    }

    public function testRealtimeMetricsWithoutSourceDateNeverAcquireTheUploadDate(): void
    {
        $payload = $this->syntheticRealtimeCapture();
        $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages($payload);
        self::assertSame(0, $result['summary']['row_count']);
        self::assertSame([], $result['packages']);
        $dateWarnings = array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'data_date_missing'));
        self::assertSame(['ctrip', 'meituan'], array_column($dateWarnings, 'platform'));
    }

    public function testUndatedRealtimeCaptureNeverReachesThePersistenceService(): void
    {
        // Leave persistence uninitialized: this rejected input must fail before accessing it.
        $service = (new \ReflectionClass(OtaBrowserAssistImportService::class))->newInstanceWithoutConstructor();
        $result = $service->importCapture(null, $this->syntheticRealtimeCapture());
        self::assertSame('failed', $result['status']);
        self::assertSame('ota_browser_assist_collection_contract.v1', $result['source_contract']);
        self::assertSame('browser_assist_dom', $result['collection_mode']);
        self::assertSame(0, $result['package_count']);
        self::assertSame(0, $result['row_count']);
        self::assertSame(0, $result['normalized_count']);
        self::assertSame(0, $result['saved_count']);
        self::assertTrue($result['saved_count_complete']);
        self::assertSame(0, $result['unconfirmed_package_count']);
        self::assertSame([], $result['packages']);
        $dateWarnings = array_values(array_filter($result['warnings'],
            static fn(array $warning): bool => $warning['code'] === 'data_date_missing'));
        self::assertSame(['ctrip', 'meituan'], array_column($dateWarnings, 'platform'));
        self::assertSame(['ctrip_stats', 'meituan_stats'], array_column($dateWarnings, 'module'));
        $corrected = $this->syntheticRealtimeCapture();
        $corrected['snapshot_time'] = '2026-08-23 21:00:00';
        $normalized = $service->normalizeCapturePackages($corrected);
        self::assertSame(2, $normalized['summary']['row_count']);
        self::assertSame(['2026-08-23', '2026-08-23'], array_column($normalized['rows'], 'data_date'));
    }

    public function testRealSnapshotDateAndExplicitBusinessDateKeepTheirOwnScopeAndZero(): void
    {
        $payload = $this->syntheticRealtimeCapture();
        $payload['ctripStats']['updatedAt'] = '2026-08-23 21:00:00';
        $payload['meituanStats']['dataDate'] = '2026-08-22';
        $result = (new OtaBrowserAssistImportService())->normalizeCapturePackages($payload);
        self::assertSame(2, $result['summary']['row_count']);
        $rows = array_column($result['rows'], null, 'source');
        self::assertSame('2026-08-23', $rows['ctrip']['data_date']);
        self::assertSame('2026-08-22', $rows['meituan']['data_date']);
        self::assertSame(0.0, $rows['ctrip']['detail_exposure']);
        self::assertSame(0.0, $rows['meituan']['detail_exposure']);
        foreach ($result['rows'] as $row) {
            self::assertSame(80, $row['system_hotel_id']);
            self::assertSame('browser_assist_dom', $row['acquisition_method']);
            self::assertFalse(($row['readback_verified'] ?? false) === true);
        }
    }

    private function syntheticRealtimeCapture(): array
    {
        return [
            'system_hotel_id' => 80,
            'hotel_name' => 'Synthetic date-source fixture',
            'generatedAt' => '2026-09-15 10:00:00',
            'ctripStats' => ['metrics' => ['ctrip' => ['realtimeVisitors' => 0]]],
            'meituanStats' => ['metrics' => ['browseUsers' => 0]],
        ];
    }

    public function testNormalizePlatformIdentityEvidenceWithoutCookieOrFullUrl(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 58,
            'generatedAt' => '2026-06-30 10:30:00',
            'platformIdentity' => [
                'platform' => 'meituan',
                'updatedAt' => '2026-06-30 10:20:00',
                'partnerId' => '313720',
                'poiId' => '888754073',
                'evidence' => [
                    [
                        'source' => 'performance_resource',
                        'host' => 'eb.meituan.com',
                        'path' => '/api/v1/ebooking/diagnosis/analysis/detail',
                        'fields' => ['partnerId', 'poiId'],
                    ],
                ],
            ],
        ]);

        self::assertSame(1, $result['summary']['row_count']);
        self::assertSame(['meituan'], $result['summary']['platforms']);
        self::assertSame(['platform_identity'], $result['summary']['data_types']);
        self::assertSame('platform_identity', $result['packages'][0]['data_type']);

        $row = $result['rows'][0];
        self::assertSame('platform_identity', $row['data_type']);
        self::assertSame('888754073', $row['hotel_id']);
        self::assertSame('313720', $row['partner_id']);
        self::assertSame('888754073', $row['poi_id']);
        self::assertSame(1, $row['data_value']);
        self::assertSame('browser_assist_dom:browser_assist_platform_identity', $row['capture_evidence']['capture_source']);
        self::assertArrayNotHasKey('url', $row);
        self::assertStringNotContainsString('diagnosisAnalysisType', json_encode($result, JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('Cookie', json_encode($result, JSON_UNESCAPED_SLASHES));
    }

    public function testAggregateImportStatusDoesNotPromotePartialPackageToSuccess(): void
    {
        $service = new OtaBrowserAssistImportService();

        self::assertSame('success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'success'],
        ]]));
        self::assertSame('partial_success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'partial_success'],
        ]]));
        self::assertSame('partial_success', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'success'],
            ['status' => 'failed'],
        ]]));
        self::assertSame('failed', $this->invokeNonPublic($service, 'aggregateImportStatus', [[
            ['status' => 'failed'],
            ['status' => 'unknown'],
        ]]));
    }

    public function testNormalizeSelectedCtripAndQunarRealtimeFactsWithoutDefaultingZero(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'data_date' => '2026-07-28',
            'snapshot_time' => '2026-07-28 21:38:00',
            'ctripStats' => [
                'sourceUrl' => 'https://ebooking.ctrip.com/home/mainland?secret=must-not-persist',
                'identityEvidence' => [
                    'status' => 'operator_confirmed',
                    'evidenceType' => 'authenticated_page_header',
                    'systemHotelId' => 80,
                    'expectedHotelName' => '敦煌漠蓝新',
                    'observedHotelName' => '敦煌·漠蓝Club·野奢民宿(鸣沙山月牙泉店)',
                    'confirmedAt' => '2026-07-28 21:38:00',
                    'cookie' => 'must-not-persist',
                ],
                'sourceSurfaces' => [
                    [
                        'surface' => 'home_channel_card',
                        'channel' => 'ctrip',
                        'observedAt' => '2026-07-28 21:37:00',
                        'fields' => ['starting_price', 'booking_order_count'],
                        'cookie' => 'must-not-persist',
                    ],
                ],
                'metrics' => [
                    'ctrip' => [
                        'realtimeVisitors' => 76,
                        'lastWeekVisitors' => 195,
                        'bookingOrderCount' => 0,
                        'inHouseRoomNights' => 4,
                        'realtimeRank' => 588,
                        'competitorRank' => 24,
                        'competitorTotal' => 26,
                        'startingPrice' => 0.00,
                    ],
                    'qunar' => [
                        'realtimeVisitors' => 25,
                        'visitorPeerAvg' => 59,
                        'visitorLagging' => true,
                        'bookingOrderCount' => 0,
                        'orderConversionRate' => 0,
                        'conversionPeerAvg' => 7.69,
                        'conversionLagging' => true,
                    ],
                ],
            ],
        ]);

        self::assertSame(3, $result['summary']['row_count']);
        foreach ($result['packages'] as $package) {
            self::assertSame('browser_assist_dom', $package['ingestion_method']);
        }
        $rows = $result['rows'];
        $ctripTraffic = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:ctrip'
                && ($row['data_type'] ?? '') === 'traffic'
        ))[0];
        self::assertSame(76.0, $ctripTraffic['detail_exposure']);
        self::assertSame(0.0, $ctripTraffic['book_order_num']);
        self::assertSame(4.0, $ctripTraffic['quantity']);
        self::assertSame(0.0, $ctripTraffic['raw_data']['metrics']['starting_price']);
        self::assertSame(195, $ctripTraffic['raw_data']['metrics']['last_week_visitors']);
        self::assertSame(
            'operator_confirmed',
            $ctripTraffic['browser_assist_identity']['status']
        );
        self::assertSame(
            'authenticated_page_header',
            $ctripTraffic['raw_data']['browser_assist_identity']['evidence_type']
        );
        self::assertSame('home_channel_card', $ctripTraffic['raw_data']['source_surfaces'][0]['surface']);

        $ctripRank = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:ctrip:rank'
        ))[0];
        self::assertSame(588.0, $ctripRank['rank']);
        self::assertSame(24, $ctripRank['raw_data']['rank_metrics']['competitor_rank']);
        self::assertSame(26, $ctripRank['raw_data']['rank_metrics']['competitor_total']);

        $qunarTraffic = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['dimension'] ?? '') === 'realtime:qunar'
        ))[0];
        self::assertSame(25.0, $qunarTraffic['detail_exposure']);
        self::assertSame(0.0, $qunarTraffic['book_order_num']);
        self::assertSame(0.0, $qunarTraffic['flow_rate']);
        self::assertTrue($qunarTraffic['raw_data']['metrics']['visitor_lagging']);
        self::assertSame(7.69, $qunarTraffic['raw_data']['metrics']['conversion_peer_avg']);
        self::assertTrue($qunarTraffic['raw_data']['metrics']['conversion_lagging']);

        $serialized = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertIsString($serialized);
        self::assertStringNotContainsString('must-not-persist', $serialized);
        self::assertStringNotContainsString('cookie', strtolower($serialized));
    }

    public function testNormalizeMeituanRealtimeFactsPreservesAuthenticatedHeaderIdentity(): void
    {
        $service = new OtaBrowserAssistImportService();

        $result = $service->normalizeCapturePackages([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'data_date' => '2026-08-12',
            'snapshot_time' => '2026-08-12 20:39:48',
            'meituanStats' => [
                'identityEvidence' => [
                    'status' => 'operator_confirmed',
                    'evidenceType' => 'authenticated_page_header',
                    'systemHotelId' => 80,
                    'expectedHotelName' => '敦煌漠蓝新',
                    'observedHotelName' => '敦煌·漠蓝·Club·野奢度假民宿（鸣沙山月牙泉店）',
                    'confirmedAt' => '2026-08-12 20:39:48',
                ],
                'metrics' => [
                    'browseUsers' => 264,
                ],
            ],
        ]);

        self::assertSame(1, $result['summary']['row_count']);
        $row = $result['rows'][0];
        self::assertSame(264.0, $row['detail_exposure']);
        self::assertSame('operator_confirmed', $row['browser_assist_identity']['status']);
        self::assertSame('authenticated_page_header', $row['browser_assist_identity']['evidence_type']);
        self::assertSame('敦煌漠蓝新', $row['browser_assist_identity']['expected_hotel_name']);
        self::assertSame(
            $row['browser_assist_identity'],
            $row['raw_data']['browser_assist_identity']
        );
    }
}
