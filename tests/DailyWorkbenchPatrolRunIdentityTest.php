<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DailyWorkbenchPatrolRunIdentityTest extends TestCase
{
    private string $originalRuntimePath;
    private string $temporaryRuntimePath;
    private string $baseDir;
    private ?object $originalEnv;

    protected function setUp(): void
    {
        $this->originalRuntimePath = app()->getRuntimePath();
        $this->temporaryRuntimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'synthetic_patrol_identity_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $this->baseDir = $this->temporaryRuntimePath . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        self::assertDirectoryDoesNotExist($this->temporaryRuntimePath);
        app()->setRuntimePath($this->temporaryRuntimePath . DIRECTORY_SEPARATOR);
        $this->originalEnv = app()->exists('env') ? app()->make('env') : null;
        app()->instance('env', new class {
            public function get(?string $name = null, $default = null) { return $default; }
        });
    }

    protected function tearDown(): void
    {
        app()->setRuntimePath($this->originalRuntimePath);
        $this->originalEnv !== null ? app()->instance('env', $this->originalEnv) : app()->delete('env');
        foreach (array_keys($this->snapshotContents()) as $path) {
            unlink($path);
        }
        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/^\d{8}$/', basename($dir)) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }
        if (is_dir($this->baseDir) && count(scandir($this->baseDir)) === 2) rmdir($this->baseDir);
        if (is_dir($this->temporaryRuntimePath) && count(scandir($this->temporaryRuntimePath)) === 2) rmdir($this->temporaryRuntimePath);
    }

    public static function regenerationContents(): array
    {
        return ['same contents' => [false], 'changed contents with same summary' => [true]];
    }

    #[DataProvider('regenerationContents')]
    public function testSameSecondRegenerationPreservesReviewedRun(bool $changeContents): void
    {
        $service = new DailyWorkbenchPatrolService();
        $pair = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $first = $service->write($this->payload(), ['trigger_type' => 'manual']);
            $this->reviewRun($service, $first['run_id']);
            $reviewed = $service->findByRunIdForHotel($first['run_id'], 7);
            $before = (string)file_get_contents($this->snapshotPath($first));
            $payload = $this->payload();
            if ($changeContents) {
                $payload['rows'][0]['synthetic_value'] = 99;
                $payload['next_actions'][0]['action_code'] = 'inventory_check';
                $payload['next_actions'][0]['action'] = 'Check the updated synthetic inventory.';
            }
            $second = (new DailyWorkbenchPatrolService())->write($payload, ['trigger_type' => 'cron']);
            if ($first['created_at'] === $second['created_at']) {
                $pair = [$first, $reviewed, $before, $second];
                break;
            }
        }
        self::assertNotNull($pair, 'The fixture must exercise actual sequential writes within one second.');
        [$first, $reviewed, $before, $second] = $pair;
        $readFirst = $service->findByRunIdForHotel($first['run_id'], 7);
        self::assertSame($reviewed['action_tracking'], $readFirst['action_tracking'], 'Regeneration must preserve the old tracked execution and review.');
        self::assertNotSame($first['run_id'], $second['run_id']);
        self::assertSame($reviewed, $readFirst);
        self::assertSame($before, (string)file_get_contents($this->snapshotPath($first)));
        self::assertSame([], $second['action_tracking']['items']);
        self::assertSame($changeContents ? 99 : 0, $second['rows'][0]['synthetic_value']);
        self::assertSame($changeContents ? 'inventory_check' : 'price_adjust', $second['next_actions'][0]['action_code']);
        self::assertSame($changeContents ? 'Check the updated synthetic inventory.' : 'Review the synthetic price.', $second['next_actions'][0]['action']);
        self::assertSame($second, $service->latest());
        self::assertSame($second['run_id'], $service->latestForHotel(7)['run_id']);
        self::assertSame([$second['run_id'], $first['run_id']], array_column($service->listForHotel(7, 2), 'run_id'));
        self::assertSame($second['run_id'], $service->healthForHotel(7, '2026-09-15')['latest_run_id']);
        self::assertNull($service->findByRunIdForHotel($first['run_id'], 8));
        self::assertNull($service->findByRunIdForHotel($second['run_id'], 8));
    }

    public function testRapidCreationsStayUniqueAndInCreationOrderAcrossServiceInstances(): void
    {
        $reader = new DailyWorkbenchPatrolService();
        $ids = [];
        $previousMicros = 0;
        for ($index = 0; $index < 24; $index++) {
            $snapshot = (new DailyWorkbenchPatrolService())->write($this->payload());
            $ids[] = $snapshot['run_id'];
            self::assertMatchesRegularExpression('/^daily_workbench_20260915_\d{6}_[a-f0-9]{32}$/', $snapshot['run_id']);
            self::assertGreaterThan($previousMicros, $snapshot['created_at_microseconds']);
            self::assertSame(date('Y-m-d H:i:s', intdiv($snapshot['created_at_microseconds'], 1000000)), $snapshot['created_at']);
            $previousMicros = $snapshot['created_at_microseconds'];
            self::assertSame($snapshot['run_id'], $reader->latestForHotel(7)['run_id']);
        }
        self::assertCount(24, array_unique($ids));
        self::assertSame(array_reverse($ids), array_column($reader->listForHotel(7, 30), 'run_id'));
        self::assertSame(array_reverse($ids), array_column($reader->list(30), 'run_id'));
    }

    public function testCreationOrderIsIndependentOfBusinessDateHotelAndLaterReview(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $first = $service->write($this->payload(7, '2026-09-15'));
        self::assertSame($first['run_id'], $service->latestForHotel(7)['run_id']);
        $otherHotel = (new DailyWorkbenchPatrolService())->write($this->payload(8, '2099-12-31'));
        $second = (new DailyWorkbenchPatrolService())->write($this->payload(7, '2024-02-29'));
        $this->reviewRun($service, $first['run_id']);
        self::assertSame($second['run_id'], $service->latest()['run_id']);
        self::assertSame($second['run_id'], $service->latestForHotel(7)['run_id']);
        self::assertSame('2024-02-29', $service->latestForHotel(7)['scope']['target_date']);
        self::assertSame($otherHotel['run_id'], $service->latestForHotel(8)['run_id']);
        self::assertSame([$second['run_id'], $otherHotel['run_id'], $first['run_id']], array_column($service->list(), 'run_id'));
        self::assertSame([$second['run_id'], $first['run_id']], array_column($service->listForHotel(7), 'run_id'));
        self::assertFalse($service->healthForHotel(7, '2026-09-15')['is_target_date_ready']);
        self::assertSame('success', $service->findByRunIdForHotel($first['run_id'], 7)['action_tracking']['items']['7|price_adjust']['review_result']['result_status']);
    }

    public function testLegacyIdRemainsReadableTrackableAndReportableBesideNewRun(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $legacy = $this->writeLegacySnapshot(date('Y-m-d H:i:s'));
        self::assertSame($legacy, $service->findByRunIdForHotel($legacy['run_id'], 7));
        self::assertSame($legacy['run_id'], $service->latestForHotel(7)['run_id']);
        $new = $service->write($this->payload(7, '2024-02-29'));
        $this->reviewRun($service, $legacy['run_id']);
        self::assertSame($new['run_id'], $service->latestForHotel(7)['run_id']);
        self::assertSame([$new['run_id'], $legacy['run_id']], array_column($service->listForHotel(7), 'run_id'));
        $report = $service->markdownReportForHotel(7, $legacy['run_id']);
        self::assertSame($legacy['run_id'], $report['snapshot']['run_id']);
        self::assertStringContainsString($legacy['run_id'], $report['content']);
        self::assertSame('success', $service->findByRunIdForHotel($legacy['run_id'], 7)['action_tracking']['items']['7|price_adjust']['review_result']['result_status']);
        self::assertNull($service->findByRunIdForHotel($legacy['run_id'], 8));
    }

    public function testMissingOrInvalidLegacyCreationTimeIsNotReplacedWithCurrentTime(): void
    {
        foreach ([null, 'bad', '2026-02-30 12:00:00'] as $createdAt) {
            $legacy = $this->writeLegacySnapshot($createdAt);
            $service = new DailyWorkbenchPatrolService();
            $new = $service->write($this->payload(7, '2024-02-29'));
            self::assertSame($new['run_id'], $service->latestForHotel(7)['run_id']);
            self::assertSame($legacy, $service->findByRunIdForHotel($legacy['run_id'], 7));
        }
    }

    public function testOnlyLegacyAndNewRunIdFormatsCanReadExistingFiles(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $legacy = $this->writeLegacySnapshot('2020-01-01 12:00:00');
        $new = $service->write($this->payload());
        $before = $this->snapshotContents();
        foreach ([$legacy['run_id'], $new['run_id']] as $runId) {
            self::assertSame($runId, $service->findByRunId($runId)['run_id']);
            self::assertSame($runId, $service->findByRunIdForHotel($runId, 7)['run_id']);
        }
        foreach ([
            'daily_workbench_20991231_120000_' . str_repeat('a', 16),
            'daily_workbench_20991231_120000_' . str_repeat('a', 31),
            'daily_workbench_20991231_120000_' . str_repeat('a', 33),
            'daily_workbench_20991231_120000_' . str_repeat('g', 32),
            '../' . $legacy['run_id'],
            '..\\' . $new['run_id'],
            '%2e%2e%2f' . $legacy['run_id'],
            $new['run_id'] . '/..',
            $legacy['run_id'] . '.json',
        ] as $invalid) {
            self::assertNull($service->findByRunId($invalid));
            self::assertNull($service->findByRunIdForHotel($invalid, 7));
        }
        self::assertSame($before, $this->snapshotContents());
    }

    public function testExclusiveCreateCollisionCannotOverwriteRunOrLatest(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $service->write($this->payload());
        $this->reviewRun($service, $snapshot['run_id']);
        $before = $this->snapshotContents();
        $collision = $snapshot;
        $collision['rows'][0]['synthetic_value'] = 99;
        $failure = null;
        try {
            (new ReflectionMethod($service, 'writeNewSnapshotFile'))->invoke($service, $this->snapshotPath($snapshot), $collision);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame($before, $this->snapshotContents());
        self::assertSame('success', $service->findByRunIdForHotel($snapshot['run_id'], 7)['action_tracking']['items']['7|price_adjust']['review_result']['result_status']);
    }

    public function testEncodingFailureCannotCreateNewRunOrChangeLatest(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $service->write($this->payload());
        $this->reviewRun($service, $snapshot['run_id']);
        $before = $this->snapshotContents();
        $payload = $this->payload();
        $payload['rows'][0]['synthetic_invalid_utf8'] = "\xB1\x31";
        $failure = null;
        try {
            $service->write($payload);
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame($before, $this->snapshotContents());
        self::assertSame($snapshot['run_id'], $service->latestForHotel(7)['run_id']);
    }

    private function reviewRun(DailyWorkbenchPatrolService $service, string $runId): void
    {
        $identity = ['run_id' => $runId, 'hotel_id' => 7, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap'];
        $service->updateActionStatusForHotel($identity + [
            'status' => 'done', 'operation_execution' => ['intent_id' => 701, 'source_record_id' => 601, 'task_id' => 801],
        ], 7, 5);
        $service->updateActionReviewForHotel($identity + [
            'result_status' => 'success', 'result_summary' => 'Synthetic review retained on the exact original run.',
        ], 7, 6);
    }

    private function writeLegacySnapshot(?string $createdAt): array
    {
        $snapshot = (new ReflectionMethod(DailyWorkbenchPatrolService::class, 'buildSnapshot'))->invoke(
            new DailyWorkbenchPatrolService(), $this->payload(7, '2099-12-31'), []
        );
        $snapshot['run_id'] = 'daily_workbench_20991231_120000_abcdef12';
        unset($snapshot['created_at_microseconds']);
        if ($createdAt === null) unset($snapshot['created_at']);
        else $snapshot['created_at'] = $createdAt;
        $path = $this->snapshotPath($snapshot);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX);
        return $snapshot;
    }

    private function payload(int $hotelId = 7, string $date = '2026-09-15'): array
    {
        return [
            'scope' => ['target_date' => $date, 'hotel_id' => $hotelId, 'requested_hotel_limit' => 1],
            'summary' => ['hotel_count' => 1, 'high_priority_action_count' => 1],
            'rows' => [['hotel_id' => $hotelId, 'hotel_name' => 'Synthetic identity fixture', 'target_date' => $date, 'synthetic_value' => 0]],
            'next_actions' => [['hotel_id' => $hotelId, 'action_code' => 'price_adjust', 'question_key' => 'conversion_gap', 'priority' => 'high', 'action' => 'Review the synthetic price.']],
        ];
    }

    private function snapshotPath(array $snapshot): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('-', '', $snapshot['scope']['target_date'])
            . DIRECTORY_SEPARATOR . $snapshot['run_id'] . '.json';
    }

    private function snapshotContents(): array
    {
        $contents = [];
        $paths = array_merge(
            glob($this->baseDir . DIRECTORY_SEPARATOR . 'latest.json') ?: [],
            glob($this->baseDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'daily_workbench_*.json') ?: []
        );
        sort($paths);
        foreach ($paths as $path) $contents[$path] = (string)file_get_contents($path);
        return $contents;
    }
}
