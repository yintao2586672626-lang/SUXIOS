<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\service\DailyWorkbenchPatrolService;
use app\service\Phase3OperationEffectLoopService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DailyWorkbenchPatrolServiceTest extends TestCase
{
    private string $baseDir;
    private string $latestPath;
    private bool $baseDirExisted;
    private bool $latestExisted;
    private string $latestContents = '';

    /** @var array<int, string> */
    private array $createdSnapshotPaths = [];

    /** @var array<string, bool> */
    private array $createdDateDirs = [];

    protected function setUp(): void
    {
        $this->baseDir = rtrim(runtime_path(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'phase2_daily_workbench_patrol';
        $this->latestPath = $this->baseDir . DIRECTORY_SEPARATOR . 'latest.json';
        $this->baseDirExisted = is_dir($this->baseDir);
        $this->latestExisted = is_file($this->latestPath);
        if ($this->latestExisted) {
            $contents = file_get_contents($this->latestPath);
            $this->latestContents = $contents === false ? '' : $contents;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSnapshotPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (array_keys($this->createdDateDirs) as $dir) {
            if (is_dir($dir) && (glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
                rmdir($dir);
            }
        }

        if ($this->latestExisted) {
            if (!is_dir($this->baseDir)) {
                mkdir($this->baseDir, 0775, true);
            }
            file_put_contents($this->latestPath, $this->latestContents, LOCK_EX);
        } elseif (is_file($this->latestPath)) {
            unlink($this->latestPath);
        }

        if (!$this->baseDirExisted
            && is_dir($this->baseDir)
            && (glob($this->baseDir . DIRECTORY_SEPARATOR . '*') ?: []) === []
        ) {
            rmdir($this->baseDir);
        }
    }

    public function testSnapshotCanBeReadListedAndReportedWithoutCrossingOtaBoundary(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $this->writeSnapshot($service);

        $found = $service->findByRunId($snapshot['run_id']);
        $latest = $service->latest();
        $list = $service->list(30);
        $health = $service->health('2099-12-31');
        $report = $service->markdownReport($snapshot['run_id']);

        self::assertSame($snapshot['run_id'], $found['run_id']);
        self::assertSame($snapshot['run_id'], $latest['run_id']);
        self::assertContains($snapshot['run_id'], array_column($list, 'run_id'));
        self::assertSame('manual_ready', $health['status']);
        self::assertTrue($health['is_target_date_ready']);
        self::assertFalse($health['is_auto_patrol']);
        self::assertSame('ota_channel', $snapshot['scope']['metric_scope']);
        self::assertFalse($snapshot['evidence_policy']['collection_logic_changed']);
        self::assertFalse($snapshot['evidence_policy']['raw_data_exposed']);
        self::assertFalse($snapshot['evidence_policy']['sensitive_credentials_exposed']);
        self::assertStringContainsString($snapshot['run_id'], $report['content']);
        self::assertStringStartsWith('suxios_ota_daily_workbench_patrol_20991231_', $report['filename']);
    }

    public function testTrackedExecutionCanBeReviewedAndSummarized(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $this->writeSnapshot($service);

        $tracked = $service->updateActionStatus([
            'run_id' => $snapshot['run_id'],
            'hotel_id' => 7,
            'action_code' => 'price_adjust',
            'question_key' => 'conversion_gap',
            'status' => 'done',
            'note' => "Operator completed the task.\0",
            'operation_execution' => [
                'intent_id' => 701,
                'source_record_id' => 601,
                'intent_status' => 'approved',
                'task_id' => 801,
                'task_status' => 'executed',
            ],
        ], 5);

        self::assertSame(1, $tracked['action_tracking']['status_summary']['done']);
        self::assertSame('review_ready', $tracked['action_tracking']['review_state']);
        self::assertSame('pending_review', $tracked['action_tracking']['items']['7|price_adjust']['review_state']);
        self::assertStringNotContainsString("\0", $tracked['action_tracking']['items']['7|price_adjust']['note']);

        $reviewed = $service->updateActionReview([
            'run_id' => $snapshot['run_id'],
            'hotel_id' => 7,
            'action_code' => 'price_adjust',
            'question_key' => 'conversion_gap',
            'result_status' => 'success',
            'result_summary' => 'OTA conversion improved in the reviewed metric window.',
        ], 6);

        $item = $reviewed['action_tracking']['items']['7|price_adjust'];
        self::assertSame('reviewed', $item['review_state']);
        self::assertSame('success', $item['review_result']['result_status']);
        self::assertSame(1, $reviewed['action_tracking']['review_summary']['success']);
        self::assertSame(1, $reviewed['action_tracking']['review_summary']['reviewed_count']);
        self::assertSame('success', $item['operation_execution']['review_status']);
        self::assertSame(701, $item['operation_execution']['intent_id']);
        self::assertSame(601, $item['operation_execution']['source_record_id']);
        self::assertSame(801, $item['operation_execution']['task_id']);
    }

    public function testReviewRejectsEveryRuntimeIdentityConflictWithoutChangingSnapshot(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $this->writeSnapshot($service);
        $service->updateActionStatus([
            'run_id' => $snapshot['run_id'],
            'hotel_id' => 7,
            'action_code' => 'price_adjust',
            'question_key' => 'conversion_gap',
            'status' => 'done',
            'operation_execution' => [
                'intent_id' => 701,
                'source_record_id' => 601,
                'task_id' => 801,
            ],
        ], 5);
        $snapshotPath = $this->createdSnapshotPaths[array_key_last($this->createdSnapshotPaths)];

        foreach ([
            'task_id' => 802,
            'intent_id' => 702,
            'source_record_id' => 602,
        ] as $field => $conflictingValue) {
            $before = (string)file_get_contents($snapshotPath);
            try {
                $service->updateActionReview([
                    'run_id' => $snapshot['run_id'],
                    'hotel_id' => 7,
                    'action_code' => 'price_adjust',
                    'question_key' => 'conversion_gap',
                    'result_status' => 'success',
                    'operation_execution' => [$field => $conflictingValue],
                ], 6);
                self::fail('Expected identity conflict for ' . $field . '.');
            } catch (\RuntimeException $exception) {
                self::assertSame(422, $exception->getCode(), $field);
                self::assertStringContainsString($field, $exception->getMessage());
            }
            self::assertSame($before, (string)file_get_contents($snapshotPath), $field);
        }
    }

    public function testReviewTaskRequestCannotOverrideRuntimeTaskIdentity(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $resolveTaskId = $reflection->getMethod('dailyWorkbenchPatrolReviewTaskId');
        $resolveTaskId->setAccessible(true);

        self::assertSame(801, $resolveTaskId->invoke($controller, ['task_id' => 801], []));
        self::assertSame(801, $resolveTaskId->invoke($controller, ['task_id' => 801], ['task_id' => 801]));

        try {
            $resolveTaskId->invoke($controller, ['task_id' => 801], ['task_id' => 802]);
            self::fail('Expected runtime task identity conflict.');
        } catch (\RuntimeException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertStringContainsString('runtime snapshot', $exception->getMessage());
        }
    }

    public function testHotelScopedReadersDoNotExposeAnotherHotelSnapshot(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $hotelSeven = $this->writeSnapshot($service, 7, 'North Hotel');
        $hotelEight = $this->writeSnapshot($service, 8, 'South Hotel');

        self::assertSame($hotelSeven['run_id'], $service->latestForHotel(7)['run_id']);
        self::assertSame($hotelEight['run_id'], $service->latestForHotel(8)['run_id']);
        self::assertNull($service->findByRunIdForHotel($hotelEight['run_id'], 7));
        self::assertContains($hotelSeven['run_id'], array_column($service->listForHotel(7, 30), 'run_id'));
        self::assertNotContains($hotelEight['run_id'], array_column($service->listForHotel(7, 30), 'run_id'));
        self::assertSame('manual_ready', $service->healthForHotel(7, '2099-12-31')['status']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('selected hotel scope');
        $service->markdownReportForHotel(7, $hotelEight['run_id']);
    }

    public function testCronPayloadIsSplitIntoSingleHotelSnapshots(): void
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $split = $reflection->getMethod('splitDailyWorkbenchPatrolPayloadsByHotel');
        $split->setAccessible(true);

        $payloads = $split->invoke($controller, [
            'scope' => [
                'target_date' => '2099-12-31',
                'hotel_id' => null,
                'requested_hotel_limit' => 30,
                'returned_hotel_count' => 2,
            ],
            'rows' => [[
                'hotel_id' => 7,
                'hotel_name' => 'North Hotel',
                'target_date' => '2099-12-31',
                'status' => 'complete',
                'next_action' => ['action_code' => 'north_action', 'priority' => 'high'],
            ], [
                'hotel_id' => 8,
                'hotel_name' => 'South Hotel',
                'target_date' => '2099-12-31',
                'status' => 'incomplete',
                'next_action' => ['action_code' => 'south_action', 'priority' => 'medium'],
            ]],
        ]);

        self::assertCount(2, $payloads);
        self::assertSame(7, $payloads[0]['scope']['hotel_id']);
        self::assertSame(8, $payloads[1]['scope']['hotel_id']);
        self::assertSame(1, $payloads[0]['scope']['returned_hotel_count']);
        self::assertSame(1, $payloads[1]['scope']['returned_hotel_count']);
        self::assertSame([7], array_column($payloads[0]['rows'], 'hotel_id'));
        self::assertSame([8], array_column($payloads[1]['rows'], 'hotel_id'));
        self::assertSame([7], array_column($payloads[0]['next_actions'], 'hotel_id'));
        self::assertSame([8], array_column($payloads[1]['next_actions'], 'hotel_id'));
    }

    public function testHotelScopedSnapshotRejectsRowsFromAnotherHotel(): void
    {
        $service = new DailyWorkbenchPatrolService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('crosses the selected hotel scope');
        $service->write([
            'scope' => ['target_date' => '2099-12-31', 'hotel_id' => 7],
            'summary' => ['hotel_count' => 1],
            'rows' => [['hotel_id' => 8, 'hotel_name' => 'South Hotel']],
            'next_actions' => [],
        ]);
    }

    public function testPhase3ScopedBuildRejectsAnotherHotelRunId(): void
    {
        $patrolService = new DailyWorkbenchPatrolService();
        $this->writeSnapshot($patrolService, 7, 'North Hotel');
        $hotelEight = $this->writeSnapshot($patrolService, 8, 'South Hotel');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('snapshot not found');
        (new Phase3OperationEffectLoopService())->build([
            'run_id' => $hotelEight['run_id'],
            'scope_hotel_id' => 7,
            'metric_window' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function writeSnapshot(DailyWorkbenchPatrolService $service, int $hotelId = 7, string $hotelName = 'North Hotel'): array
    {
        $dateDir = $this->baseDir . DIRECTORY_SEPARATOR . '20991231';
        $dateDirExisted = is_dir($dateDir);
        $snapshot = $service->write([
            'scope' => [
                'target_date' => '2099-12-31',
                'hotel_id' => $hotelId,
                'requested_hotel_limit' => 1,
            ],
            'summary' => [
                'hotel_count' => 1,
                'high_priority_action_count' => 1,
            ],
            'rows' => [[
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'target_date' => '2099-12-31',
            ]],
            'next_actions' => [[
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'question_key' => 'conversion_gap',
                'action_code' => 'price_adjust',
                'priority' => 'high',
                'action' => 'Review OTA price and inventory.',
            ]],
            'data_status' => [
                'status' => 'verified_snapshot',
            ],
        ], [
            'trigger_type' => 'manual',
            'user_id' => 5,
        ]);

        $this->createdSnapshotPaths[] = $dateDir
            . DIRECTORY_SEPARATOR
            . $snapshot['run_id']
            . '.json';
        if (!$dateDirExisted) {
            $this->createdDateDirs[$dateDir] = true;
        }

        return $snapshot;
    }
}
