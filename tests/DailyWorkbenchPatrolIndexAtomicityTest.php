<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyWorkbenchPatrolIndexAtomicityTest extends TestCase
{
    public function testNativeFirstWriteAndReplacementHaveExactReadback(): void
    {
        $originalRuntime = app()->getRuntimePath();
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_index_' . bin2hex(random_bytes(8));
        $base = $runtime . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $dateDir = $base . DIRECTORY_SEPARATOR . '20991231';
        self::assertDirectoryDoesNotExist($runtime);
        app()->setRuntimePath($runtime . DIRECTORY_SEPARATOR);
        $ownedPaths = [];
        try {
            $service = new DailyWorkbenchPatrolService();
            $first = $service->write($this->payload());
            $ownedPaths[] = $dateDir . DIRECTORY_SEPARATOR . $first['run_id'] . '.json';
            $ownedPaths[] = $base . DIRECTORY_SEPARATOR . 'latest.json';
            self::assertSame($first, $service->latest());
            $firstIndex = file_get_contents($base . DIRECTORY_SEPARATOR . 'latest.json');
            $second = $service->write($this->payload());
            $ownedPaths[] = $dateDir . DIRECTORY_SEPARATOR . $second['run_id'] . '.json';
            self::assertNotSame($first['run_id'], $second['run_id']);
            self::assertSame($second, (new DailyWorkbenchPatrolService())->latest());
            self::assertNotSame($firstIndex, file_get_contents($base . DIRECTORY_SEPARATOR . 'latest.json'));
            self::assertSame($first['run_id'], $service->findByRunIdForHotel($first['run_id'], 7)['run_id']);
            self::assertSame($second['run_id'], $service->findByRunIdForHotel($second['run_id'], 7)['run_id']);
            self::assertNull($service->findByRunIdForHotel($second['run_id'], 8));
            self::assertCount(2, $service->listForHotel(7));
            self::assertSame([], glob($base . DIRECTORY_SEPARATOR . '*.tmp') ?: []);
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

    public static function publicationFailures(): array
    {
        return [
            'short write retains old index' => ['short', true, false],
            'flush failure retains old index' => ['flush', true, false],
            'rename false retains old index' => ['rename_false', true, false],
            'rename exception retains old index' => ['rename_throw', true, false],
            'close exception still cleans the temporary file' => ['close_throw', true, false],
            'short write with real ThinkPHP warning handling' => ['short', true, true],
            'real ThinkPHP exception during a write' => ['write_warning', true, true],
            'short first index does not leave a false index' => ['short', false, false],
            'flush first index does not leave a false index' => ['flush', false, false],
            'rename first index does not leave a false index' => ['rename_false', false, false],
            'exclusive create failure preserves the foreign temporary file' => ['open_collision', true, false],
        ];
    }

    #[DataProvider('publicationFailures')]
    public function testPublicationFailurePreservesOnlyPreviouslyPublishedIndex(string $fault, bool $withOld, bool $frameworkWarnings): void
    {
        $originalRuntime = app()->getRuntimePath();
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_memory_index_' . bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($runtime);
        // Load all classes before temporarily replacing this process's file wrapper.
        $service = new DailyWorkbenchPatrolService();
        $frameworkError = new \think\initializer\Error();
        class_exists(\think\exception\ErrorException::class);
        PatrolIndexMemoryStream::reset($runtime, $fault);
        app()->setRuntimePath($runtime . DIRECTORY_SEPARATOR);
        $registered = false;
        $result = [];
        $originalReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $errno, string $message, string $file, int $line) use ($frameworkWarnings, $frameworkError): bool {
            if ($frameworkWarnings) $frameworkError->appError($errno, $message, $file, $line);
            return true;
        });
        try {
            if (!stream_wrapper_unregister('file') || !stream_wrapper_register('file', PatrolIndexMemoryStream::class)) {
                throw new \RuntimeException('Synthetic file wrapper registration failed.');
            }
            $registered = true;
            $old = $withOld ? $service->write($this->payload()) : null;
            $index = PatrolIndexMemoryStream::key($runtime . '/phase2_daily_workbench_patrol/latest.json');
            $oldBytes = PatrolIndexMemoryStream::$files[$index] ?? null;
            $oldFiles = PatrolIndexMemoryStream::$files;
            $baselineReadback = $service->latest();
            PatrolIndexMemoryStream::$activeFault = true;
            $returned = null;
            $error = null;
            try {
                $returned = $service->write($this->payload());
            } catch (\Throwable $caught) {
                $error = $caught;
            }
            clearstatcache();
            $result = [
                'old' => $old, 'old_bytes' => $oldBytes, 'old_files' => $oldFiles,
                'baseline_readback' => $baselineReadback,
                'returned' => $returned, 'error' => $error,
                'latest' => (new DailyWorkbenchPatrolService())->latest(),
                'index_bytes' => PatrolIndexMemoryStream::$files[$index] ?? null,
                'files' => PatrolIndexMemoryStream::$files,
                'events' => PatrolIndexMemoryStream::$events,
                'foreign_temp' => PatrolIndexMemoryStream::$foreignTemporaryPath,
            ];
        } finally {
            if ($registered || !in_array('file', stream_get_wrappers(), true)) stream_wrapper_restore('file');
            restore_error_handler();
            error_reporting($originalReporting);
            clearstatcache();
            app()->setRuntimePath($originalRuntime);
        }
        self::assertDirectoryDoesNotExist($runtime);
        self::assertSame($result['old'], $result['baseline_readback']);
        self::assertNull($result['returned'], 'Failed index publication must not return success.');
        self::assertInstanceOf(\RuntimeException::class, $result['error']);
        self::assertStringContainsString('latest patrol index', $result['error']->getMessage());
        self::assertSame($result['old_bytes'], $result['index_bytes'], 'Failed publication must preserve the previously published bytes.');
        self::assertSame($result['old'], $result['latest']);
        foreach ($result['old_files'] as $path => $bytes) {
            self::assertSame($bytes, $result['files'][$path] ?? null, 'A previously saved file was changed by failed publication.');
        }
        $snapshots = array_filter($result['files'], static fn($path) => preg_match('~/\d{8}/daily_workbench_[^/]+\.json$~', $path), ARRAY_FILTER_USE_KEY);
        self::assertCount($withOld ? 2 : 1, $snapshots, 'The independently saved new snapshot is retained; this is not a transaction rollback.');
        foreach ($snapshots as $bytes) {
            $snapshot = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(7, $snapshot['scope']['hotel_id']);
            self::assertSame('2099-12-31', $snapshot['scope']['target_date']);
        }
        $temporary = array_filter($result['files'], static fn($path) => str_ends_with($path, '.tmp'), ARRAY_FILTER_USE_KEY);
        if ($fault === 'open_collision') {
            self::assertSame([$result['foreign_temp'] => 'synthetic foreign temporary contents'], $temporary);
        } else {
            self::assertSame([], $temporary, 'Only the current write temporary file must be cleaned after failure.');
        }
        self::assertFalse(in_array('unlink_latest', $result['events'], true), 'Never unlink the published index before replacement.');
        if ($fault === 'write_warning') {
            self::assertInstanceOf(\think\exception\ErrorException::class, $result['error']->getPrevious());
        }
    }

    public static function actionIndexEntrypoints(): array
    {
        return ['status update' => ['status'], 'review update' => ['review']];
    }

    #[DataProvider('actionIndexEntrypoints')]
    public function testActionUpdateIndexFailureKeepsOldIndexButRetainsUpdatedRun(string $entrypoint): void
    {
        $originalRuntime = app()->getRuntimePath();
        $runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_action_index_' . bin2hex(random_bytes(8));
        $base = $runtime . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $dateDir = $base . DIRECTORY_SEPARATOR . '20991231';
        $indexPath = $base . DIRECTORY_SEPARATOR . 'latest.json';
        self::assertDirectoryDoesNotExist($runtime);
        app()->setRuntimePath($runtime . DIRECTORY_SEPARATOR);
        $runPath = '';
        try {
            $service = new DailyWorkbenchPatrolService();
            $frameworkError = new \think\initializer\Error();
            class_exists(\think\exception\ErrorException::class);
            $payload = $this->payload();
            $payload['next_actions'] = [['hotel_id' => 7, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap', 'action' => 'Synthetic action']];
            $snapshot = $service->write($payload);
            $runPath = $dateDir . DIRECTORY_SEPARATOR . $snapshot['run_id'] . '.json';
            $input = [
                'run_id' => $snapshot['run_id'], 'hotel_id' => 7,
                'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
                'status' => $entrypoint === 'review' ? 'done' : 'in_progress',
                'operation_execution' => ['task_id' => 801, 'intent_id' => 701, 'source_record_id' => 601],
            ];
            $service->updateActionStatusForHotel($input, 7, 5);
            $oldRunBytes = file_get_contents($runPath);
            $oldIndexBytes = file_get_contents($indexPath);
            $oldIndex = $service->latest();
            // Native glob sees only this synthetic directory; the process-local
            // file stream shadows every byte read/write with the in-memory copy.
            PatrolIndexMemoryStream::reset($runtime, 'short');
            PatrolIndexMemoryStream::$directories[PatrolIndexMemoryStream::key($base)] = true;
            PatrolIndexMemoryStream::$directories[PatrolIndexMemoryStream::key($dateDir)] = true;
            PatrolIndexMemoryStream::$files = [
                PatrolIndexMemoryStream::key($runPath) => $oldRunBytes,
                PatrolIndexMemoryStream::key($indexPath) => $oldIndexBytes,
            ];
            PatrolIndexMemoryStream::$activeFault = true;
            $originalReporting = error_reporting(E_ALL);
            set_error_handler(static function (int $errno, string $message, string $file, int $line) use ($frameworkError): void {
                $frameworkError->appError($errno, $message, $file, $line);
            });
            $registered = false;
            $returned = null;
            $error = null;
            try {
                if (!stream_wrapper_unregister('file') || !stream_wrapper_register('file', PatrolIndexMemoryStream::class)) {
                    throw new \RuntimeException('Synthetic file wrapper registration failed.');
                }
                $registered = true;
                try {
                    $returned = $entrypoint === 'status'
                        ? $service->updateActionStatusForHotel(array_replace($input, ['status' => 'done']), 7, 6)
                        : $service->updateActionReviewForHotel(array_replace($input, ['result_status' => 'success', 'result_summary' => 'Synthetic saved review']), 7, 6);
                } catch (\Throwable $caught) {
                    $error = $caught;
                }
                clearstatcache();
                $readback = (new DailyWorkbenchPatrolService())->findByRunIdForHotel($snapshot['run_id'], 7);
                $latestReadback = (new DailyWorkbenchPatrolService())->latest();
                $files = PatrolIndexMemoryStream::$files;
            } finally {
                if ($registered || !in_array('file', stream_get_wrappers(), true)) stream_wrapper_restore('file');
                restore_error_handler();
                error_reporting($originalReporting);
                clearstatcache();
            }
            self::assertSame($oldIndexBytes, $files[PatrolIndexMemoryStream::key($indexPath)], 'The action entrypoint must preserve the previously published index.');
            self::assertSame($oldIndex, $latestReadback);
            self::assertNull($returned);
            self::assertInstanceOf(\RuntimeException::class, $error);
            self::assertStringContainsString('latest patrol index', $error->getMessage());
            self::assertNotSame($oldRunBytes, $files[PatrolIndexMemoryStream::key($runPath)], 'The independent run update has already succeeded.');
            self::assertSame($snapshot['run_id'], $readback['run_id']);
            self::assertSame(7, $readback['scope']['hotel_id']);
            $item = $readback['action_tracking']['items']['7|price_adjust'];
            self::assertSame(801, $item['operation_execution']['task_id']);
            self::assertSame('done', $item['status']);
            if ($entrypoint === 'review') {
                self::assertSame('success', $item['review_result']['result_status']);
                self::assertSame('Synthetic saved review', $item['review_result']['result_summary']);
                self::assertSame(1, $readback['action_tracking']['review_summary']['reviewed_count']);
            }
            self::assertSame([], array_filter($files, static fn($path) => str_ends_with($path, '.tmp'), ARRAY_FILTER_USE_KEY));
            self::assertSame($oldRunBytes, file_get_contents($runPath), 'Fault fixture must not write its shadow state to the real file.');
            self::assertSame($oldIndexBytes, file_get_contents($indexPath));
        } finally {
            app()->setRuntimePath($originalRuntime);
            foreach ([$runPath, $indexPath] as $path) {
                if (str_starts_with($path, $runtime . DIRECTORY_SEPARATOR) && is_file($path)) unlink($path);
            }
            foreach ([$dateDir, $base, $runtime] as $dir) {
                if (is_dir($dir) && count(scandir($dir)) === 2) rmdir($dir);
            }
        }
    }

    private function payload(): array
    {
        return [
            'scope' => ['hotel_id' => 7, 'target_date' => '2099-12-31', 'requested_hotel_limit' => 1],
            'rows' => [['hotel_id' => 7, 'hotel_name' => 'Synthetic Index Hotel', 'target_date' => '2099-12-31']],
            'summary' => ['hotel_count' => 1], 'next_actions' => [],
            'data_status' => ['status' => 'synthetic_fixture_only'],
        ];
    }
}

/** Single-process in-memory file stream. No source function or return value is replaced. */
final class PatrolIndexMemoryStream
{
    public $context;
    public static array $files = [];
    public static array $directories = [];
    public static array $events = [];
    public static string $runtime;
    public static string $fault;
    public static string $faultPath;
    public static bool $activeFault = false;
    public static ?string $foreignTemporaryPath = null;
    private string $path = '';
    private int $position = 0;
    private int $written = 0;
    private bool $writing = false;
    private bool $closeFaultRaised = false;

    public static function key(string $path): string { return rtrim(str_replace('\\', '/', preg_replace('~^file://~', '', $path)), '/'); }
    public static function reset(string $runtime, string $fault, ?string $faultPath = null): void
    {
        self::$runtime = self::key($runtime);
        self::$files = [];
        self::$directories = [self::$runtime => true];
        self::$events = [];
        self::$fault = $fault;
        self::$faultPath = self::key($faultPath ?? $runtime . '/phase2_daily_workbench_patrol/latest.json');
        self::$activeFault = false;
        self::$foreignTemporaryPath = null;
        clearstatcache();
    }
    private static function isFaultPath(string $path): bool
    {
        return $path === self::$faultPath || preg_match('~^' . preg_quote(self::$faultPath, '~') . '\.[^/]+\.tmp$~', $path) === 1;
    }
    private function isIndexWrite(): bool { return $this->writing && self::isFaultPath($this->path); }
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = self::key($path);
        $this->writing = $mode[0] !== 'r';
        if (!str_starts_with($this->path, self::$runtime . '/')) return false;
        if (self::$activeFault && self::isFaultPath($this->path) && self::$fault === 'open_collision' && str_ends_with($this->path, '.tmp') && $mode[0] === 'x') {
            self::$files[$this->path] = 'synthetic foreign temporary contents';
            self::$foreignTemporaryPath = $this->path;
            return false;
        }
        $exists = array_key_exists($this->path, self::$files);
        if (($mode[0] === 'x' && $exists) || ($mode[0] === 'r' && !$exists)) return false;
        if (!$exists || in_array($mode[0], ['w', 'x'], true)) self::$files[$this->path] = '';
        $this->position = 0;
        return true;
    }
    public function stream_write(string $data): int
    {
        $count = strlen($data);
        if (self::$activeFault && $this->isIndexWrite()) {
            if (self::$fault === 'short') $count = min($count, max(0, 48 - $this->written));
            if (self::$fault === 'write_warning') trigger_error('Synthetic latest index stream write warning.', E_USER_WARNING);
        }
        self::$files[$this->path] = substr(self::$files[$this->path], 0, $this->position)
            . substr($data, 0, $count) . substr(self::$files[$this->path], $this->position + $count);
        $this->position += $count;
        $this->written += $count;
        return $count;
    }
    public function stream_flush(): bool { return !(self::$activeFault && self::$fault === 'flush' && $this->isIndexWrite()); }
    public function stream_close(): void
    {
        if (self::$activeFault && self::$fault === 'close_throw' && $this->isIndexWrite() && !$this->closeFaultRaised) {
            $this->closeFaultRaised = true;
            throw new \RuntimeException('Synthetic index stream close failed.');
        }
    }
    public function rename(string $from, string $to): bool
    {
        $from = self::key($from);
        $to = self::key($to);
        if (self::$activeFault && self::isFaultPath($to) && self::$fault === 'rename_false') return false;
        if (self::$activeFault && self::isFaultPath($to) && self::$fault === 'rename_throw') throw new \RuntimeException('Synthetic index rename failed.');
        if (!array_key_exists($from, self::$files)) return false;
        self::$files[$to] = self::$files[$from];
        unset(self::$files[$from]);
        return true;
    }
    public function unlink(string $path): bool
    {
        $path = self::key($path);
        if (str_ends_with($path, '/latest.json')) self::$events[] = 'unlink_latest';
        unset(self::$files[$path]);
        return true;
    }
    public function mkdir(string $path, int $mode, int $options): bool { self::$directories[self::key($path)] = true; return true; }
    public function stream_lock(int $operation): bool { return true; }
    public function stream_truncate(int $size): bool { self::$files[$this->path] = substr(self::$files[$this->path], 0, $size); return true; }
    public function stream_read(int $count): string
    {
        $data = substr(self::$files[$this->path], $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }
    public function stream_eof(): bool { return $this->position >= strlen(self::$files[$this->path]); }
    public function stream_tell(): int { return $this->position; }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->position = match ($whence) { SEEK_CUR => $this->position + $offset, SEEK_END => strlen(self::$files[$this->path]) + $offset, default => $offset };
        return $this->position >= 0;
    }
    public function url_stat(string $path, int $flags): array|false
    {
        $path = self::key($path);
        $file = array_key_exists($path, self::$files);
        if (!$file && !isset(self::$directories[$path])) return false;
        $mode = ($file ? 0100000 : 0040000) | 0777;
        $size = $file ? strlen(self::$files[$path]) : 0;
        return [0 => 0, 1 => 0, 2 => $mode, 3 => 1, 4 => 0, 5 => 0, 6 => 0, 7 => $size,
            8 => 0, 9 => 0, 10 => 0, 11 => -1, 12 => -1, 'mode' => $mode, 'size' => $size, 'mtime' => 0];
    }
    public function stream_stat(): array|false { return $this->url_stat($this->path, 0); }
}
