<?php
declare(strict_types=1);

namespace Tests;

use app\service\WeeklyOperatingPlanSnapshotService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeeklyOperatingPlanPendingExecutionTest extends TestCase
{
    #[DataProvider('unfinishedStates')]
    public function testUnfinishedOriginalTaskIsSelectedAndSavedExactly(string $status): void
    {
        $sources = self::sources($status);
        $rows = [];
        $service = $this->service($sources, $rows);
        $saved = $service->generateAndReadback(80, 80, '2026-08-28');
        self::assertSame('execution_pending', $saved['selected_focus']['type']);
        self::assertSame($status, $saved['selected_focus']['key']);
        self::assertSame(['operation_execution_tasks#601'], $saved['selected_focus']['evidence_refs']);
        self::assertSame(1, $saved['lifecycle_summary'][$status]);
        self::assertSame(0, $saved['lifecycle_summary']['review_pending']);
        self::assertStringNotContainsString('没有积压事项', $saved['final_text']);
        self::assertStringContainsString($status === 'executing' ? '执行中：1' : '待执行：1', $saved['final_text']);
        $exact = $service->readExact(80, 80, $saved['snapshot_id']);
        self::assertSame($saved['selected_focus'], $exact['selected_focus']);
        self::assertSame($saved['final_text'], $exact['final_text']);
        self::assertSame($saved['snapshot_fingerprint'], $exact['snapshot_fingerprint']);
        self::assertSame($saved['snapshot_id'], $service->generateAndReadback(80, 80, '2026-08-28')['snapshot_id']);
        self::assertCount(1, $rows);
        $draft = $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $sources);
        self::assertFalse($draft['automatic_execution']);
        self::assertSame(0, $draft['external_write_count']);
    }

    public static function unfinishedStates(): array
    {
        return [['pending_execute'], ['executing']];
    }

    public function testOldestUnfinishedTaskWinsAndCompletedApprovedIntentIsNotBacklog(): void
    {
        $sources = self::sources('executed');
        $sources['tasks'][0]['result_status'] = 'success';
        $service = new WeeklyOperatingPlanSnapshotService();
        $closed = $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $sources);
        self::assertSame('workflow_improvement', $closed['selected_focus']['type']);
        self::assertSame(0, $closed['lifecycle_summary']['pending_execute']);
        self::assertSame(0, $closed['lifecycle_summary']['executing']);
        $sources['tasks'][] = ['id' => 603, 'intent_id' => 501, 'status' => 'pending_execute'];
        $sources['tasks'][] = ['id' => 602, 'intent_id' => 501, 'status' => 'executing'];
        $draft = $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $sources);
        self::assertSame(['operation_execution_tasks#602'], $draft['selected_focus']['evidence_refs']);
        self::assertSame('executing', $draft['selected_focus']['key']);
        self::assertSame(1, $draft['lifecycle_summary']['pending_execute']);
        self::assertSame(1, $draft['lifecycle_summary']['executing']);
        self::assertSame(1, $draft['lifecycle_summary']['reviewed']);
    }

    public function testExistingPriorityAndReviewStatesRemainDistinct(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService();
        $draft = static fn(array $sources): array => $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $sources);
        $sources = self::sources('executed');
        self::assertSame('review_pending', $draft($sources)['selected_focus']['type']);
        foreach (['blocked', 'failed', 'cancelled'] as $terminal) {
            $sources['tasks'][0]['status'] = $terminal;
            self::assertNotSame('execution_pending', $draft($sources)['selected_focus']['type']);
        }
        $sources = self::sources('pending_execute');
        $sources['source_errors'] = ['fixture_read_unavailable'];
        self::assertSame('lifecycle_source_unavailable', $draft($sources)['selected_focus']['type']);
        $sources['source_errors'] = [];
        $sources['intents'][0]['status'] = 'pending_approval';
        self::assertSame('oldest_pending_approval', $draft($sources)['selected_focus']['type']);
        $sources['intents'][0]['status'] = 'approved';
        $sources['task_workflow']['next_task'] = ['task_id' => 605, 'scope' => ['hotel_id' => 80],
            'next_step' => ['key' => 'verify_execution', 'label' => '核实原任务']];
        self::assertSame('task_workflow_next_step', $draft($sources)['selected_focus']['type']);
        self::assertSame(['operation_execution_tasks#605'], $draft($sources)['selected_focus']['evidence_refs']);
    }

    public function testLegacySavedMistakeStaysImmutableWhileRegenerationGetsNewVersion(): void
    {
        // Captured from the pre-fix service using sources('pending_execute'), without a database.
        $oldRow = json_decode((string)file_get_contents(__DIR__ . '/fixtures/weekly-plan/approved-pending-before-fix.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows = [$oldRow];
        $service = $this->service(self::sources('pending_execute'), $rows);
        $old = $service->readExact(80, 80, $oldRow['id']);
        self::assertSame('workflow_improvement', $old['selected_focus']['type']);
        self::assertStringContainsString('没有积压事项', $old['final_text']);
        $new = $service->generateAndReadback(80, 80, '2026-08-28');
        self::assertTrue($new['created']);
        self::assertSame(2, $new['version_no']);
        self::assertSame('execution_pending', $new['selected_focus']['type']);
        self::assertNotSame($old['source_digest'], $new['source_digest']);
        self::assertSame($old, $service->readExact(80, 80, $oldRow['id']));
        self::assertSame($new['snapshot_id'], $service->readLatest(80, 80, '2026-08-28')['snapshot_id']);
        self::assertSame($new['snapshot_id'], $service->generateAndReadback(80, 80, '2026-08-28')['snapshot_id']);
        self::assertCount(2, $rows);
    }

    /** Synthetic full-week coverage with an ordinary unconfigured task. */
    public static function sources(string $status): array
    {
        $daily = $broadcasts = [];
        for ($i = 0; $i < 7; $i++) {
            $date = (new \DateTimeImmutable('2026-08-22'))->modify('+' . $i . ' days')->format('Y-m-d');
            $daily[] = ['id' => 100 + $i, 'business_date' => $date, 'input_digest' => hash('sha256', 'input-' . $date),
                'result_digest' => hash('sha256', 'result-' . $date), 'input' => ['source_digest' => hash('sha256', 'source-' . $date)],
                'result' => ['selected' => ['problem' => '检查已保存事项', 'source' => ['gap_codes' => []]]]];
            $broadcasts[] = ['id' => 200 + $i, 'business_date' => $date, 'facts_fingerprint' => hash('sha256', 'facts-' . $date),
                'snapshot_fingerprint' => hash('sha256', 'snapshot-' . $date)];
        }
        return ['hotel_name' => 'Synthetic hotel', 'daily_runs' => $daily, 'broadcasts' => $broadcasts,
            'intents' => [['id' => 501, 'tenant_id' => 80, 'hotel_id' => 80, 'status' => 'approved']],
            'tasks' => [['id' => 601, 'intent_id' => 501, 'tenant_id' => 80, 'hotel_id' => 80, 'status' => $status, 'result_status' => 'observing']],
            'source_errors' => [], 'task_workflow' => ['status' => 'ready', 'configured' => 0, 'task_completed' => 0,
                'execution_verified' => 0, 'reviewed' => 0, 'effect_established' => 0, 'blocked' => 0, 'next_task' => null, 'versions' => []]];
    }

    private function service(array $sources, array &$rows): WeeklyOperatingPlanSnapshotService
    {
        return new WeeklyOperatingPlanSnapshotService(
            sourceReader: static fn(): array => $sources,
            snapshotReader: static function (string $action, array $scope) use (&$rows): mixed {
                if ($action === 'next_version') return count($rows) + 1;
                $matches = array_values(array_filter($rows, static function (array $row) use ($action, $scope): bool {
                    foreach (['tenant_id', 'hotel_id', 'week_start', 'week_end', 'id', 'generation_trigger', 'source_digest'] as $field) {
                        if (isset($scope[$field]) && (string)$row[$field] !== (string)$scope[$field]) return false;
                    }
                    return true;
                }));
                return $matches === [] ? null : $matches[count($matches) - 1];
            },
            snapshotWriter: static function (array $row) use (&$rows): int {
                $row['id'] = count($rows) + 1;
                $rows[] = $row;
                return $row['id'];
            },
            clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-08-29 03:30:00', new \DateTimeZone('Asia/Shanghai')),
            scopeVerifier: static fn(): bool => true
        );
    }
}
