<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DailyWorkbenchPatrolIndexAtomicityTest.php';

final class DailyWorkbenchPatrolSnapshotAtomicityTest extends TestCase
{
    public static function renameFaults(): array
    {
        return ['rename returns false' => ['rename_false'], 'rename throws' => ['rename_throw']];
    }

    #[DataProvider('renameFaults')]
    public function testRenameFaultTargetsOnlySelectedRun(string $fault): void
    {
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_rename_' . bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($runtime);
        $base = PatrolIndexMemoryStream::key($runtime . '/phase2_daily_workbench_patrol');
        $a = $base . '/20991231/run-a.json';
        $b = $base . '/20991231/run-b.json';
        $latest = $base . '/latest.json';
        PatrolIndexMemoryStream::reset($runtime, $fault, $a);
        foreach ([$a, $b, $latest] as $path) {
            PatrolIndexMemoryStream::$files[$path] = 'synthetic old';
            PatrolIndexMemoryStream::$files[$path . '.synthetic.tmp'] = 'synthetic new';
        }
        PatrolIndexMemoryStream::$activeFault = true;
        $registered = false;
        $results = [];
        set_error_handler(static fn(): bool => true);
        try {
            if (!stream_wrapper_unregister('file') || !stream_wrapper_register('file', PatrolIndexMemoryStream::class)) {
                throw new \RuntimeException('Synthetic file wrapper registration failed.');
            }
            $registered = true;
            foreach ([$a, $b, $latest] as $path) {
                try {
                    $results[$path] = rename($path . '.synthetic.tmp', $path);
                } catch (\Throwable $error) {
                    $results[$path] = $error::class;
                }
            }
        } finally {
            if ($registered || !in_array('file', stream_get_wrappers(), true)) stream_wrapper_restore('file');
            restore_error_handler();
            clearstatcache();
        }
        self::assertDirectoryDoesNotExist($runtime);
        self::assertSame($fault === 'rename_false' ? false : \RuntimeException::class, $results[$a]);
        self::assertSame('synthetic old', PatrolIndexMemoryStream::$files[$a]);
        self::assertSame('synthetic new', PatrolIndexMemoryStream::$files[$a . '.synthetic.tmp']);
        foreach ([$b, $latest] as $path) {
            self::assertTrue($results[$path], 'The selected run fault must not affect another run or the index.');
            self::assertSame('synthetic new', PatrolIndexMemoryStream::$files[$path]);
            self::assertArrayNotHasKey($path . '.synthetic.tmp', PatrolIndexMemoryStream::$files);
        }
    }

    public static function updateFailures(): array
    {
        $cases = [];
        foreach (['status', 'review'] as $entrypoint) {
            foreach ([['short', false], ['short', true], ['flush', false], ['rename_false', false],
                ['rename_throw', false], ['close_throw', false], ['write_warning', true]] as [$fault, $frameworkWarnings]) {
                $cases[$entrypoint . ' historical ' . $fault . ($frameworkWarnings ? ' ThinkPHP' : '')]
                    = [$entrypoint, $fault, $frameworkWarnings, true];
            }
            $cases[$entrypoint . ' current latest short'] = [$entrypoint, 'short', true, false];
        }
        return $cases;
    }

    #[DataProvider('updateFailures')]
    public function testUpdateFailureKeepsSavedRunReadableAndRetryKeepsItsIdentity(
        string $entrypoint, string $fault, bool $frameworkWarnings, bool $withNewer
    ): void {
        $originalRuntime = app()->getRuntimePath();
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_snapshot_' . bin2hex(random_bytes(8));
        $base = $runtime . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $dateDir = $base . DIRECTORY_SEPARATOR . '20991231';
        $indexPath = $base . DIRECTORY_SEPARATOR . 'latest.json';
        self::assertDirectoryDoesNotExist($runtime);
        app()->setRuntimePath($runtime . DIRECTORY_SEPARATOR);
        $ownedPaths = [];
        try {
            $service = new DailyWorkbenchPatrolService();
            $frameworkError = new \think\initializer\Error();
            class_exists(\think\exception\ErrorException::class);
            $first = $service->write($this->payload());
            $runPath = $dateDir . DIRECTORY_SEPARATOR . $first['run_id'] . '.json';
            $ownedPaths = [$runPath, $indexPath];
            $input = [
                'run_id' => $first['run_id'], 'hotel_id' => 7,
                'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
                'status' => 'done', 'note' => 'Synthetic saved status',
                'operation_execution' => ['task_id' => 801, 'intent_id' => 701, 'source_record_id' => 601],
            ];
            $service->updateActionStatusForHotel($input, 7, 5);
            $service->updateActionReviewForHotel(array_replace($input, [
                'result_status' => 'observing', 'result_summary' => 'Synthetic saved review',
            ]), 7, 5);
            if ($withNewer) {
                $second = $service->write($this->payload());
                $ownedPaths[] = $dateDir . DIRECTORY_SEPARATOR . $second['run_id'] . '.json';
            }
            $baselineViews = $this->readViews($first['run_id']);
            self::assertSame('done', $baselineViews['find']['action_tracking']['items']['7|price_adjust']['status']);
            self::assertSame('observing', $baselineViews['find']['action_tracking']['items']['7|price_adjust']['review_result']['result_status']);
            $oldFiles = [];
            foreach ($ownedPaths as $path) $oldFiles[PatrolIndexMemoryStream::key($path)] = file_get_contents($path);

            // Native glob enumerates only the newly created synthetic files. During
            // the fault, all file reads/writes shadow those bytes in this process.
            PatrolIndexMemoryStream::reset($runtime, $fault, $runPath);
            PatrolIndexMemoryStream::$directories[PatrolIndexMemoryStream::key($base)] = true;
            PatrolIndexMemoryStream::$directories[PatrolIndexMemoryStream::key($dateDir)] = true;
            PatrolIndexMemoryStream::$files = $oldFiles;
            PatrolIndexMemoryStream::$activeFault = true;
            $originalReporting = error_reporting(E_ALL);
            set_error_handler(static function (int $errno, string $message, string $file, int $line) use ($frameworkWarnings, $frameworkError): bool {
                if ($frameworkWarnings) $frameworkError->appError($errno, $message, $file, $line);
                return true;
            });
            $registered = false;
            $returned = null;
            $error = null;
            $retry = null;
            $retryError = null;
            try {
                if (!stream_wrapper_unregister('file') || !stream_wrapper_register('file', PatrolIndexMemoryStream::class)) {
                    throw new \RuntimeException('Synthetic file wrapper registration failed.');
                }
                $registered = true;
                try {
                    $returned = $this->update($service, $entrypoint, $input);
                } catch (\Throwable $caught) {
                    $error = $caught;
                }
                clearstatcache();
                $failedFiles = PatrolIndexMemoryStream::$files;
                $failedViews = $this->readViews($first['run_id']);
                PatrolIndexMemoryStream::$activeFault = false;
                try {
                    $retry = $this->update(new DailyWorkbenchPatrolService(), $entrypoint, $input);
                } catch (\Throwable $caught) {
                    $retryError = $caught;
                }
                clearstatcache();
                $retryFiles = PatrolIndexMemoryStream::$files;
                $retryViews = $this->readViews($first['run_id']);
            } finally {
                if ($registered || !in_array('file', stream_get_wrappers(), true)) stream_wrapper_restore('file');
                restore_error_handler();
                error_reporting($originalReporting);
                clearstatcache();
            }

            // Every previously saved byte, including A's only historical file and
            // the B/latest pair, must survive before any retry is considered.
            self::assertSame($oldFiles, $failedFiles, 'Failed run publication must preserve all saved bytes and clean its temporary file.');
            self::assertNull($returned, 'A failed run publication cannot report success.');
            self::assertInstanceOf(\RuntimeException::class, $error);
            self::assertSame('Daily workbench patrol snapshot update failed.', $error->getMessage());
            if ($fault === 'write_warning') self::assertInstanceOf(\think\exception\ErrorException::class, $error->getPrevious());
            self::assertSame($baselineViews, $failedViews, 'Exact reads, history, latest and Markdown must retain the saved tracking and review.');
            foreach ($ownedPaths as $path) self::assertSame($oldFiles[PatrolIndexMemoryStream::key($path)], file_get_contents($path));
            self::assertNull($retryError);
            $this->assertUpdated($entrypoint, $retry, $retryViews, $baselineViews, $withNewer);
            self::assertSame(array_keys($oldFiles), array_keys($retryFiles), 'Retry replaces the same run without creating another identity or leaving temporary files.');
            foreach ($oldFiles as $path => $bytes) {
                if ($path !== PatrolIndexMemoryStream::key($runPath) && ($withNewer || $path !== PatrolIndexMemoryStream::key($indexPath))) {
                    self::assertSame($bytes, $retryFiles[$path]);
                }
            }
            self::assertSame($retryViews['find'], json_decode($retryFiles[PatrolIndexMemoryStream::key($runPath)], true, 512, JSON_THROW_ON_ERROR));

            // With the wrapper restored, exercise the same replacement and exact
            // readback through the native Windows filesystem in this owned runtime.
            $native = $this->update(new DailyWorkbenchPatrolService(), $entrypoint, $input);
            $nativeViews = $this->readViews($first['run_id']);
            $this->assertUpdated($entrypoint, $native, $nativeViews, $baselineViews, $withNewer);
            self::assertSame($nativeViews['find'], json_decode(file_get_contents($runPath), true, 512, JSON_THROW_ON_ERROR));
            self::assertCount(count($ownedPaths) - 1, glob($dateDir . DIRECTORY_SEPARATOR . '*.json') ?: []);
            self::assertSame([], glob($dateDir . DIRECTORY_SEPARATOR . '*.tmp') ?: []);
            self::assertSame([], glob($base . DIRECTORY_SEPARATOR . '*.tmp') ?: []);
            foreach ($oldFiles as $path => $bytes) {
                if ($path !== PatrolIndexMemoryStream::key($runPath) && ($withNewer || $path !== PatrolIndexMemoryStream::key($indexPath))) {
                    self::assertSame($bytes, file_get_contents($path));
                }
            }
        } finally {
            app()->setRuntimePath($originalRuntime);
            foreach ($ownedPaths as $path) {
                if (str_starts_with($path, $runtime . DIRECTORY_SEPARATOR) && is_file($path)) unlink($path);
            }
            foreach ([$dateDir, $base, $runtime] as $dir) {
                if (is_dir($dir) && count(scandir($dir)) === 2) rmdir($dir);
            }
        }
    }

    private function update(DailyWorkbenchPatrolService $service, string $entrypoint, array $input): array
    {
        return $entrypoint === 'status'
            ? $service->updateActionStatusForHotel(array_replace($input, ['status' => 'review_needed', 'note' => 'Synthetic retried status']), 7, 6)
            : $service->updateActionReviewForHotel(array_replace($input, ['result_status' => 'success', 'result_summary' => 'Synthetic retried review']), 7, 6);
    }

    private function readViews(string $runId): array
    {
        $service = new DailyWorkbenchPatrolService();
        $views = [
            'find' => $service->findByRunId($runId), 'scoped' => $service->findByRunIdForHotel($runId, 7),
            'other_hotel' => $service->findByRunIdForHotel($runId, 8),
            'list' => $service->list(), 'hotel_list' => $service->listForHotel(7), 'other_list' => $service->listForHotel(8),
            'latest' => $service->latest(), 'hotel_latest' => $service->latestForHotel(7),
        ];
        try {
            $views['markdown'] = $service->markdownReport($runId);
            $views['hotel_markdown'] = $service->markdownReportForHotel(7, $runId);
        } catch (\Throwable $error) {
            $views['markdown_error'] = $error::class;
        }
        return $views;
    }

    private function assertUpdated(string $entrypoint, array $returned, array $views, array $before, bool $withNewer): void
    {
        $snapshot = $views['find'];
        foreach (['run_id', 'created_at', 'created_at_microseconds', 'scope', 'rows', 'next_actions'] as $field) {
            self::assertSame($before['find'][$field], $snapshot[$field]);
        }
        $withoutStorage = $returned;
        unset($withoutStorage['storage']);
        self::assertSame($withoutStorage, $snapshot, 'The returned update must match the exact saved run.');
        self::assertSame($snapshot, $views['scoped']);
        self::assertNull($views['other_hotel']);
        self::assertSame([], $views['other_list']);
        self::assertSame(array_column($before['list'], 'run_id'), array_column($views['list'], 'run_id'));
        self::assertSame(array_column($before['hotel_list'], 'run_id'), array_column($views['hotel_list'], 'run_id'));
        self::assertArrayNotHasKey('markdown_error', $views);
        self::assertSame($views['markdown'], $views['hotel_markdown']);
        self::assertSame($snapshot['run_id'], $views['markdown']['snapshot']['run_id']);
        $item = $snapshot['action_tracking']['items']['7|price_adjust'];
        self::assertSame($entrypoint === 'status' ? 'review_needed' : 'done', $item['status']);
        foreach (['task_id' => 801, 'intent_id' => 701, 'source_record_id' => 601] as $key => $value) {
            self::assertSame($value, $item['operation_execution'][$key]);
        }
        if ($entrypoint === 'review') {
            self::assertSame('success', $item['review_result']['result_status']);
            self::assertSame('Synthetic retried review', $item['review_result']['result_summary']);
        } else {
            self::assertSame('Synthetic retried status', $item['note']);
        }
        if ($withNewer) {
            self::assertSame($before['latest'], $views['latest']);
            self::assertSame($before['hotel_latest'], $views['hotel_latest']);
        } else {
            self::assertSame($returned, $views['latest']);
            self::assertSame($snapshot, $views['hotel_latest']);
        }
    }

    private function payload(): array
    {
        return [
            'scope' => ['hotel_id' => 7, 'target_date' => '2099-12-31'],
            'rows' => [['hotel_id' => 7, 'hotel_name' => 'Synthetic Snapshot Hotel', 'target_date' => '2099-12-31']],
            'summary' => ['hotel_count' => 1],
            'next_actions' => [[
                'hotel_id' => 7, 'platform' => 'ctrip', 'action_code' => 'price_adjust',
                'question_key' => 'conversion_gap', 'action' => 'Synthetic saved action',
                'evidence_refs' => ['synthetic_channel_fact#601'],
            ]],
            'data_status' => ['status' => 'synthetic_fixture_only'],
        ];
    }
}
