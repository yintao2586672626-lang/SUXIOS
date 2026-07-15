<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\TestCase;

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
    }

    /** @return array<string, mixed> */
    private function writeSnapshot(DailyWorkbenchPatrolService $service): array
    {
        $dateDir = $this->baseDir . DIRECTORY_SEPARATOR . '20991231';
        $dateDirExisted = is_dir($dateDir);
        $snapshot = $service->write([
            'scope' => [
                'target_date' => '2099-12-31',
                'hotel_id' => 7,
                'requested_hotel_limit' => 1,
            ],
            'summary' => [
                'hotel_count' => 1,
                'high_priority_action_count' => 1,
            ],
            'rows' => [[
                'hotel_id' => 7,
                'hotel_name' => 'North Hotel',
                'target_date' => '2099-12-31',
            ]],
            'next_actions' => [[
                'hotel_id' => 7,
                'hotel_name' => 'North Hotel',
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
