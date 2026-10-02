<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DailyWorkbenchPatrolDateValidationTest extends TestCase
{
    private string $originalRuntimePath;
    private string $temporaryRuntimePath;
    private string $baseDir;
    private ?object $originalEnv;

    protected function setUp(): void
    {
        $this->originalRuntimePath = app()->getRuntimePath();
        $this->temporaryRuntimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'synthetic_patrol_dates_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->baseDir = $this->temporaryRuntimePath . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
        app()->setRuntimePath($this->temporaryRuntimePath . DIRECTORY_SEPARATOR);
        $this->originalEnv = app()->exists('env') ? app()->make('env') : null;
        app()->instance('env', new class {
            public function get(?string $name = null, $default = null)
            {
                return $default;
            }
        });
    }

    protected function tearDown(): void
    {
        app()->setRuntimePath($this->originalRuntimePath);
        if ($this->originalEnv !== null) {
            app()->instance('env', $this->originalEnv);
        } else {
            app()->delete('env');
        }
        foreach (array_keys($this->snapshotContents()) as $path) {
            unlink($path);
        }
        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^\d{8}$/', basename($dir)) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }
        if (is_dir($this->baseDir) && count(scandir($this->baseDir)) === 2) {
            rmdir($this->baseDir);
        }
        if (is_dir($this->temporaryRuntimePath) && count(scandir($this->temporaryRuntimePath)) === 2) {
            rmdir($this->temporaryRuntimePath);
        }
    }

    public static function invalidDates(): array
    {
        return [
            'invalid calendar day' => ['2026-02-30'],
            'non leap year' => ['2026-02-29'],
            'zero year' => ['0000-01-01'],
            'incomplete format' => ['2026-2-01'],
            'invalid text' => ['bad'],
            'datetime instead of date' => ['2026-02-28T12:00:00'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateCannotCreateRuntime(string $date): void
    {
        $failure = null;
        try {
            (new DailyWorkbenchPatrolService())->write($this->payload($date), ['target_date' => '2024-02-29']);
        } catch (\InvalidArgumentException $exception) {
            $failure = $exception;
        }
        self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
        self::assertInstanceOf(\InvalidArgumentException::class, $failure);
        self::assertStringContainsString('target_date', $failure->getMessage());
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateCannotReplaceExistingSnapshot(string $date): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $service->write($this->payload('2024-02-29'));
        $before = $this->snapshotContents();
        foreach (['scope', 'context'] as $source) {
            $payload = $this->payload($date);
            if ($source === 'context') {
                unset($payload['scope']['target_date']);
            }
            $failure = null;
            try {
                $service->write($payload, ['target_date' => $source === 'context' ? $date : '2024-02-29']);
            } catch (\InvalidArgumentException $exception) {
                $failure = $exception;
            }
            self::assertSame($before, $this->snapshotContents(), $source);
            self::assertInstanceOf(\InvalidArgumentException::class, $failure, $source);
            self::assertSame($snapshot, $service->latest(), $source);
            self::assertSame($snapshot['run_id'], $service->findByRunIdForHotel($snapshot['run_id'], 7)['run_id']);
        }
    }

    public function testLeapDayPersistsWithExactReadbackAndIndependentActionDate(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $payload = $this->payload('2024-02-29');
        $payload['next_actions'][0]['target_date'] = '2024-03-01';
        $snapshot = $service->write($payload, ['target_date' => '2026-09-15']);
        $expected = $snapshot;
        unset($expected['storage']);

        self::assertSame('2024-02-29', $snapshot['scope']['target_date']);
        self::assertSame('2024-03-01', $snapshot['next_actions'][0]['target_date']);
        self::assertSame($expected, $service->findByRunIdForHotel($snapshot['run_id'], 7));
        self::assertSame($snapshot, $service->latest());
        self::assertSame($expected, $service->latestForHotel(7));
        self::assertSame($snapshot['scope'], $service->listForHotel(7)[0]['scope']);
        self::assertFileExists($this->baseDir . DIRECTORY_SEPARATOR . '20240229' . DIRECTORY_SEPARATOR . $snapshot['run_id'] . '.json');
        $health = $service->healthForHotel(7, '2024-02-29');
        self::assertSame('manual_ready', $health['status']);
        self::assertTrue($health['is_target_date_ready']);
        self::assertFalse($health['automation_configured']);
        self::assertFalse($health['collection_logic_changed']);
    }

    public function testMissingBlankAndValidDatesPreserveSourcePriority(): void
    {
        $build = new ReflectionMethod(DailyWorkbenchPatrolService::class, 'buildSnapshot');
        $service = new DailyWorkbenchPatrolService();
        $today = date('Y-m-d');
        foreach ([
            [[], [], $today],
            [['target_date' => '  '], ['target_date' => '2024-02-29'], $today],
            [['target_date' => null], ['target_date' => '2024-02-29'], '2024-02-29'],
            [[], ['target_date' => '2024-02-29'], '2024-02-29'],
            [[], ['target_date' => '  '], $today],
            [['target_date' => ' 2024-02-29 '], ['target_date' => 'bad'], '2024-02-29'],
        ] as [$scope, $context, $expected]) {
            $snapshot = $build->invoke($service, ['scope' => $scope], $context);
            self::assertSame($expected, $snapshot['scope']['target_date']);
        }
        self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
    }

    public function testInvalidHealthRequestDoesNotReadOrCreateSnapshots(): void
    {
        $service = new DailyWorkbenchPatrolService();
        foreach (self::invalidDates() as [$date]) {
            foreach (['health', 'healthForHotel'] as $method) {
                $failure = null;
                try {
                    $method === 'health' ? $service->health($date) : $service->healthForHotel(7, $date);
                } catch (\InvalidArgumentException $exception) {
                    $failure = $exception;
                }
                self::assertInstanceOf(\InvalidArgumentException::class, $failure);
                self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
            }
        }
    }

    public function testLegacyInvalidAndMissingDatesStayReadableWithoutClaimingToday(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $service->write($this->payload('2024-02-29'));
        $paths = array_keys($this->snapshotContents());
        foreach (['2026-02-30', 'bad', '  ', null] as $declaredDate) {
            $legacy = $snapshot;
            if ($declaredDate === null) {
                unset($legacy['scope']['target_date']);
            } else {
                $legacy['scope']['target_date'] = $declaredDate;
            }
            foreach ($paths as $path) {
                $stored = $legacy;
                if (basename($path) !== 'latest.json') {
                    unset($stored['storage']);
                }
                file_put_contents($path, json_encode($stored, JSON_THROW_ON_ERROR), LOCK_EX);
            }
            $before = $this->snapshotContents();
            self::assertSame($legacy, $service->latest());
            self::assertSame($legacy['scope'], $service->listForHotel(7)[0]['scope']);
            self::assertSame($legacy['scope'], $service->findByRunIdForHotel($snapshot['run_id'], 7)['scope']);
            foreach ([$service->health(), $service->healthForHotel(7)] as $health) {
                self::assertSame('stale', $health['status']);
                self::assertSame($declaredDate ?? '', $health['latest_target_date']);
                self::assertFalse($health['is_target_date_ready']);
                self::assertSame('run_patrol_now', $health['next_action']);
                self::assertStringContainsString(trim($declaredDate ?? '') === '' ? 'missing' : 'invalid', $health['message']);
                self::assertFalse($health['automation_configured']);
            }
            self::assertSame($before, $this->snapshotContents());
        }
    }

    public function testRealControllerRejectsInvalidCalendarAndFormat(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $resolve = new ReflectionMethod($controller, 'resolveDailyWorkbenchPatrolTargetDate');
        foreach (self::invalidDates() as [$date]) {
            $failure = null;
            try {
                $resolve->invoke($controller, $date);
            } catch (\InvalidArgumentException $exception) {
                $failure = $exception;
            }
            self::assertInstanceOf(\InvalidArgumentException::class, $failure, $date);
        }
    }

    public function testRealControllerPreservesLeapDayAndDefaultDate(): void
    {
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $resolve = new ReflectionMethod($controller, 'resolveDailyWorkbenchPatrolTargetDate');
        self::assertSame('2024-02-29', $resolve->invoke($controller, ' 2024-02-29 '));
        foreach ([null, '', '  '] as $blank) {
            self::assertSame(date('Y-m-d'), $resolve->invoke($controller, $blank));
        }
    }

    private function payload(string $date): array
    {
        return [
            'scope' => ['target_date' => $date, 'hotel_id' => 7, 'requested_hotel_limit' => 1],
            'summary' => ['hotel_count' => 1],
            'rows' => [['hotel_id' => 7, 'hotel_name' => 'Synthetic date fixture', 'target_date' => $date]],
            'next_actions' => [[
                'hotel_id' => 7, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
                'target_date' => $date, 'action' => 'Review synthetic OTA fixture.',
            ]],
        ];
    }

    private function snapshotContents(): array
    {
        $contents = [];
        $paths = array_merge(
            glob($this->baseDir . DIRECTORY_SEPARATOR . 'latest.json') ?: [],
            glob($this->baseDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'daily_workbench_*.json') ?: []
        );
        sort($paths);
        foreach ($paths as $path) {
            $contents[$path] = (string)file_get_contents($path);
        }
        return $contents;
    }
}
