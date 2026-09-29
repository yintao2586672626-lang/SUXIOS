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
    private string $originalRuntimePath;
    private string $temporaryRuntimePath;

    /** @var array<int, string> */
    private array $createdSnapshotPaths = [];

    /** @var array<string, bool> */
    private array $createdDateDirs = [];

    protected function setUp(): void
    {
        $this->originalRuntimePath = app()->getRuntimePath();
        $this->temporaryRuntimePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'synthetic_patrol_status_' . getmypid() . '_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        app()->setRuntimePath($this->temporaryRuntimePath);
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
        app()->setRuntimePath($this->originalRuntimePath);
        if (is_dir($this->temporaryRuntimePath) && count(scandir($this->temporaryRuntimePath)) === 2) {
            rmdir($this->temporaryRuntimePath);
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

    #[\PHPUnit\Framework\Attributes\DataProvider('replayedReviewResults')]
    public function testSameStatusReplayPreservesExactReviewAndExecutionBinding(string $resultStatus, bool $includeBinding): void
    {
        $service = new DailyWorkbenchPatrolService();
        [$input, $reviewed] = $this->writeReviewedAction($service, $resultStatus);
        $before = $reviewed['action_tracking']['items']['7|price_adjust'];
        $input['note'] = 'Synthetic repeated completion request.';
        if ($includeBinding) {
            foreach (['task_id', 'intent_id', 'source_record_id'] as $field) {
                $input['operation_execution'][$field] = (string)$input['operation_execution'][$field];
            }
            $input['operation_execution']['execution_evidence_count'] = 3;
        } else {
            unset($input['operation_execution']);
        }
        $service->updateActionStatusForHotel($input, 7, 8);
        $read = $service->findByRunIdForHotel($input['run_id'], 7);
        $after = $read['action_tracking']['items']['7|price_adjust'];
        self::assertSame($before['review_result'], $after['review_result']);
        self::assertSame($before['review_state'], $after['review_state']);
        self::assertSame($before['reviewed_at'], $after['reviewed_at']);
        self::assertSame($before['reviewed_by_user_id'], $after['reviewed_by_user_id']);
        foreach (['task_id', 'intent_id', 'source_record_id', 'review_status', 'review_summary', 'reviewed_at'] as $field) {
            self::assertSame($before['operation_execution'][$field], $after['operation_execution'][$field], $field);
        }
        self::assertSame($input['note'], $after['note']);
        self::assertSame(1, $read['action_tracking']['review_summary'][$resultStatus]);
        self::assertSame($resultStatus === 'observing' ? 0 : 1, $read['action_tracking']['review_summary']['reviewed_count']);
        self::assertSame($after, $service->latestForHotel(7)['action_tracking']['items']['7|price_adjust']);
        if ($includeBinding) self::assertSame(3, $after['operation_execution']['execution_evidence_count']);
    }

    public static function replayedReviewResults(): array
    {
        return [['success', true], ['observing', true], ['failed', true], ['success', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedReviewContexts')]
    public function testChangedActionStatusOrBindingDoesNotInheritReviewAndRecountsSummary(string $change): void
    {
        $service = new DailyWorkbenchPatrolService();
        [$input] = $this->writeReviewedAction($service);
        if (in_array($change, ['task_id', 'intent_id', 'source_record_id'], true)) {
            $input['operation_execution'][$change]++;
        } elseif ($change === 'missing_binding') {
            unset($input['operation_execution']['task_id']);
        } elseif ($change === 'invalid_binding') {
            $input['operation_execution']['task_id'] = true;
        } elseif ($change === 'question_key') {
            $input['question_key'] = 'different_question';
        } else {
            $input['status'] = $change;
        }
        $service->updateActionStatusForHotel($input, 7, 8);
        $read = $service->findByRunIdForHotel($input['run_id'], 7);
        $item = $read['action_tracking']['items']['7|price_adjust'];
        self::assertArrayNotHasKey('review_result', $item);
        self::assertArrayNotHasKey('reviewed_at', $item);
        self::assertSame(0, $read['action_tracking']['review_summary']['success']);
        self::assertSame(0, $read['action_tracking']['review_summary']['reviewed_count']);
        self::assertSame($input['status'], $item['status']);
        self::assertSame($input['status'] === 'done' ? 'pending_review' : ($input['status'] === 'review_needed' ? 'needs_review' : 'open'), $item['review_state']);
    }

    public static function changedReviewContexts(): array
    {
        return array_map(static fn(string $value): array => [$value], [
            'task_id', 'intent_id', 'source_record_id', 'missing_binding', 'invalid_binding', 'question_key',
            'pending', 'in_progress', 'skipped', 'review_needed',
        ]);
    }

    public function testStatusReplayCannotInheritReviewFromAnotherActionOrHotel(): void
    {
        $service = new DailyWorkbenchPatrolService();
        [$input, $reviewed] = $this->writeReviewedAction($service);
        $original = $reviewed['action_tracking']['items']['7|price_adjust'];
        $otherAction = array_replace($input, ['action_code' => 'inventory_check']);
        $service->updateActionStatusForHotel($otherAction, 7, 8);
        $read = $service->findByRunIdForHotel($input['run_id'], 7);
        self::assertSame($original, $read['action_tracking']['items']['7|price_adjust']);
        self::assertArrayNotHasKey('review_result', $read['action_tracking']['items']['7|inventory_check']);
        self::assertSame(1, $read['action_tracking']['review_summary']['success']);

        $otherRun = $this->writeSnapshot($service, 7, 'Synthetic second run', 2);
        self::assertNotSame($input['run_id'], $otherRun['run_id']);
        $service->updateActionStatusForHotel(array_replace($input, ['run_id' => $otherRun['run_id']]), 7, 8);
        self::assertArrayNotHasKey('review_result', $service->findByRunIdForHotel($otherRun['run_id'], 7)['action_tracking']['items']['7|price_adjust']);
        self::assertSame($original, $service->findByRunIdForHotel($input['run_id'], 7)['action_tracking']['items']['7|price_adjust']);

        $hotelEight = $this->writeSnapshot($service, 8, 'Synthetic other hotel');
        $otherHotel = array_replace($input, ['run_id' => $hotelEight['run_id'], 'hotel_id' => 8]);
        $service->updateActionStatusForHotel($otherHotel, 8, 8);
        $otherRead = $service->findByRunIdForHotel($hotelEight['run_id'], 8);
        self::assertArrayNotHasKey('review_result', $otherRead['action_tracking']['items']['8|price_adjust']);
        self::assertSame(0, $otherRead['action_tracking']['review_summary']['reviewed_count']);
        self::assertSame($original, $service->findByRunIdForHotel($input['run_id'], 7)['action_tracking']['items']['7|price_adjust']);
        try {
            $service->updateActionStatusForHotel($input, 8, 8);
            self::fail('A status replay must remain scoped to the requested hotel.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('selected hotel scope', $error->getMessage());
        }
    }

    private function writeReviewedAction(DailyWorkbenchPatrolService $service, string $resultStatus = 'success'): array
    {
        $snapshot = $this->writeSnapshot($service);
        $input = [
            'run_id' => $snapshot['run_id'], 'hotel_id' => 7,
            'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
            'status' => 'done', 'operation_execution' => [
                'intent_id' => 701, 'source_record_id' => 601, 'task_id' => 801,
                'task_status' => 'executed',
            ],
        ];
        $service->updateActionStatusForHotel($input, 7, 5);
        return [$input, $service->updateActionReviewForHotel($input + [
            'result_status' => $resultStatus, 'result_summary' => 'Synthetic scoped review.',
        ], 7, 6)];
    }

    public function testFailedStatusReplayPreservesSavedReviewAndCanRetry(): void
    {
        $service = new DailyWorkbenchPatrolService();
        [$input] = $this->writeReviewedAction($service);
        $before = $service->findByRunIdForHotel($input['run_id'], 7);
        $invalidInput = $input;
        $invalidInput['operation_execution']['synthetic_invalid_utf8'] = "\xB1";
        try {
            $service->updateActionStatusForHotel($invalidInput, 7, 8);
            self::fail('A failed status save must leave the original snapshot readable.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('snapshot update failed', $error->getMessage());
        }
        self::assertSame($before, $service->findByRunIdForHotel($input['run_id'], 7));
        $service->updateActionStatusForHotel($input, 7, 8);
        self::assertSame($before['action_tracking']['items']['7|price_adjust']['review_result'],
            $service->findByRunIdForHotel($input['run_id'], 7)['action_tracking']['items']['7|price_adjust']['review_result']);
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

    public function testPatrolActionInputUsesPersistedSourceMetadata(): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $this->writeSnapshot($service);
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $request = [
            'run_id' => $snapshot['run_id'], 'hotel_id' => 7,
            'action_code' => 'price_adjust', 'question_key' => 'conversion_gap',
            'status' => 'done', 'note' => 'Synthetic employee note.',
            'target_date' => '2099-12-30', 'platform' => 'meituan', 'priority' => 'low',
            'action_text' => 'Synthetic request override.', 'entry' => '/synthetic-replacement',
            'data_gaps' => ['synthetic_request_gap'],
        ];
        $input = (new \ReflectionMethod($controller, 'dailyWorkbenchPatrolActionInput'))->invoke($controller, $snapshot, $request);
        self::assertSame('2099-12-31', $input['target_date']);
        self::assertSame('ota', $input['platform']);
        self::assertSame('high', $input['priority']);
        self::assertSame('Review OTA price and inventory.', $input['action_text']);
        self::assertSame('', $input['entry']);
        self::assertSame(['conversion_gap'], $input['data_gaps']);
        foreach (['run_id', 'hotel_id', 'action_code', 'question_key', 'status', 'note'] as $field) {
            self::assertSame($request[$field], $input[$field], $field);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('patrolActionIdentities')]
    public function testPatrolActionInputPreservesSingleIdentitySourceKey(string $actionCode, string $questionKey): void
    {
        $snapshot = $this->writeSnapshot(new DailyWorkbenchPatrolService());
        $request = ['run_id' => $snapshot['run_id'], 'hotel_id' => 7,
            'action_code' => $actionCode, 'question_key' => $questionKey, 'status' => 'in_progress'];
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $input = (new \ReflectionMethod($controller, 'dailyWorkbenchPatrolActionInput'))->invoke($controller, $snapshot, $request);
        self::assertSame($actionCode, $input['action_code']);
        self::assertSame($questionKey, $input['question_key']);
        $recordId = new \ReflectionMethod(\app\service\OperationManagementService::class, 'dailyWorkbenchPatrolSourceRecordId');
        self::assertSame((int)sprintf('%u', crc32($request['run_id'] . '|7|' . $actionCode . '|' . $questionKey)),
            $recordId->invoke(new \app\service\OperationManagementService(), $input['run_id'], 7, $input['action_code'], $input['question_key']));
    }

    public static function patrolActionIdentities(): array
    {
        return [['price_adjust', ''], ['', 'conversion_gap'], ['price_adjust', 'conversion_gap']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('conflictingPatrolActionIdentities')]
    public function testPatrolActionContextRejectsConflictingPairedIdentity(string $actionCode, string $questionKey): void
    {
        $service = new DailyWorkbenchPatrolService();
        $snapshot = $this->writeSnapshot($service);
        $before = $service->findByRunIdForHotel($snapshot['run_id'], 7);
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        try {
            (new \ReflectionMethod($controller, 'dailyWorkbenchPatrolActionContext'))->invoke($controller, $snapshot, [
                'hotel_id' => 7, 'action_code' => $actionCode, 'question_key' => $questionKey,
            ]);
            self::fail('A matching identity field must not authorize a conflicting second identity.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('not in this snapshot', $error->getMessage());
        }
        self::assertSame($before, $service->findByRunIdForHotel($snapshot['run_id'], 7));
    }

    public static function conflictingPatrolActionIdentities(): array
    {
        return [['price_adjust', 'synthetic_other_question'], ['synthetic_other_action', 'conversion_gap']];
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

        $this->expectException(\app\exception\MissingPatrolSnapshotException::class);
        (new Phase3OperationEffectLoopService())->build([
            'run_id' => $hotelEight['run_id'],
            'scope_hotel_id' => 7,
            'metric_window' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function writeSnapshot(DailyWorkbenchPatrolService $service, int $hotelId = 7, string $hotelName = 'North Hotel', int $actionCount = 1): array
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
                'high_priority_action_count' => $actionCount,
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
