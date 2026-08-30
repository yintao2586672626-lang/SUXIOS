<?php
declare(strict_types=1);

namespace Tests;

use app\service\WeeklyOperatingPlanSnapshotService;
use PHPUnit\Framework\TestCase;

final class WeeklyOperatingPlanSnapshotServiceTest extends TestCase
{
    public function testRepeatedGapBecomesTheOnlyNextWeekFocus(): void
    {
        $service = new WeeklyOperatingPlanSnapshotService(
            clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable(
                '2026-08-29 03:30:00',
                new \DateTimeZone('Asia/Shanghai')
            )
        );
        $draft = $service->buildDraft(
            80,
            80,
            '2026-08-22',
            '2026-08-28',
            $this->sources(true)
        );

        self::assertSame('ready', $draft['status']);
        self::assertSame(7, $draft['daily_priority_coverage_count']);
        self::assertSame(7, $draft['trusted_broadcast_coverage_count']);
        self::assertSame('repeated_data_gap', $draft['selected_focus']['type']);
        self::assertSame(
            'ctrip_target_date_source_rows_missing',
            $draft['selected_focus']['key']
        );
        self::assertStringContainsString('补齐携程目标日期可信事实', $draft['final_text']);
        self::assertCount(1, array_filter(
            $draft['repeated_gap_summary'],
            static fn(array $row): bool => (int)$row['count'] >= 2
        ));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $draft['source_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $draft['snapshot_fingerprint']);
        self::assertSame(hash('sha256', $draft['final_text']), $draft['final_text_sha256']);
        self::assertSame(0, $draft['external_write_count']);
        self::assertSame(0, $draft['external_message_count']);
        self::assertFalse($draft['automatic_execution']);
    }

    public function testGeneratePersistsExactSnapshotAndReusesUnchangedSource(): void
    {
        $rows = [];
        $reader = static function (string $action, array $scope) use (&$rows): mixed {
            if ($action === 'next_version') {
                $versions = array_map(static fn(array $row): int => (int)$row['version_no'], $rows);
                return ($versions === [] ? 0 : max($versions)) + 1;
            }
            if ($action === 'exact') {
                foreach ($rows as $row) if ((int)$row['id'] === (int)$scope['id']) return $row;
                return null;
            }
            $matches = array_values(array_filter($rows, static function (array $row) use ($scope, $action): bool {
                foreach (['tenant_id', 'hotel_id', 'week_start', 'week_end'] as $field) {
                    if ((string)$row[$field] !== (string)$scope[$field]) return false;
                }
                return $action !== 'by_source'
                    || ((string)$row['generation_trigger'] === (string)$scope['generation_trigger']
                        && (string)$row['source_digest'] === (string)$scope['source_digest']);
            }));
            usort($matches, static fn(array $a, array $b): int => (int)$b['id'] <=> (int)$a['id']);
            return $matches[0] ?? null;
        };
        $writer = static function (array $row) use (&$rows): int {
            $row['id'] = count($rows) + 1;
            $rows[] = $row;
            return $row['id'];
        };
        $service = new WeeklyOperatingPlanSnapshotService(
            fn(): array => $this->sources(true),
            $reader,
            $writer,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable(
                '2026-08-29 03:30:00',
                new \DateTimeZone('Asia/Shanghai')
            ),
            static fn(): bool => true
        );

        $first = $service->generateAndReadback(80, 80, '2026-08-28');
        $second = $service->generateAndReadback(80, 80, '2026-08-28');
        $exact = $service->readExact(80, 80, (int)$first['snapshot_id']);
        $latest = $service->readLatest(80, 80, '2026-08-28');

        self::assertTrue($first['created']);
        self::assertSame('weekly_operating_plan.v2', $first['contract_version']);
        self::assertFalse($first['idempotent_replay']);
        self::assertFalse($second['created']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame($first['snapshot_id'], $second['snapshot_id']);
        self::assertSame($first['snapshot_fingerprint'], $exact['snapshot_fingerprint']);
        self::assertSame($first['source_digest'], $latest['source_digest']);
        self::assertSame($first['final_text'], $latest['final_text']);
        self::assertCount(1, $rows);

        $rows[0]['lifecycle_summary_json'] = '{"pending_approval":999}';
        try {
            $service->readExact(80, 80, (int)$first['snapshot_id']);
            self::fail('tampered lifecycle JSON must fail exact readback');
        } catch (\RuntimeException $error) {
            self::assertSame('weekly_plan_snapshot_readback_failed', $error->getMessage());
        }
    }

    public function testMissingCoverageBecomesFocusWhenNoRepeatedGapOrLifecycleBacklog(): void
    {
        $sources = $this->sources(false);
        $sources['daily_runs'] = array_slice($sources['daily_runs'], 0, 4);
        $sources['broadcasts'] = array_slice($sources['broadcasts'], 0, 3);
        $sources['intents'] = [];
        $sources['tasks'] = [];
        $service = new WeeklyOperatingPlanSnapshotService();

        $draft = $service->buildDraft(
            80,
            80,
            '2026-08-22',
            '2026-08-28',
            $sources
        );

        self::assertSame('partial', $draft['status']);
        self::assertSame('coverage_gap', $draft['selected_focus']['type']);
        self::assertNotEmpty($draft['missing_days']['daily_priority']);
        self::assertNotEmpty($draft['missing_days']['trusted_broadcast']);
        self::assertStringContainsString('缺失保持缺失', $draft['final_text']);
    }

    public function testMigrationApiAndWeeklyAutomationConsumeOneImmutableSnapshot(): void
    {
        $root = dirname(__DIR__);
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260829_create_weekly_operating_plan_snapshots.sql'
        );
        $versionMigration = (string)file_get_contents(
            $root . '/database/migrations/20260829_zz_add_weekly_operating_plan_contract_version.sql'
        );
        $controller = (string)file_get_contents($root . '/app/controller/OperatingOpportunity.php');
        $routes = (string)file_get_contents($root . '/route/domain/operations.php');
        $automation = (string)file_get_contents($root . '/app/service/CloudAutomationService.php');
        $delivery = (string)file_get_contents($root . '/app/service/WechatRobotDeliveryService.php');

        self::assertStringContainsString('weekly_operating_plan_snapshots', $migration);
        self::assertStringContainsString('uk_weekly_plan_source', $migration);
        self::assertStringContainsString('source_digest', $migration);
        self::assertStringContainsString('final_text_sha256', $migration);
        self::assertStringContainsString('contract_version', $versionMigration);
        self::assertStringNotContainsString('UPDATE ', strtoupper($migration));
        self::assertStringContainsString('weeklyPlanLatest', $controller);
        self::assertStringContainsString('weeklyPlanRead', $controller);
        self::assertStringContainsString("Route::get('/weekly-plan/latest'", $routes);
        self::assertStringContainsString("Route::get('/weekly-plan/snapshots/:id'", $routes);
        self::assertStringContainsString('WeeklyOperatingPlanSnapshotService', $automation);
        self::assertStringContainsString('weekly_plan_snapshot_id', $automation);
        self::assertStringContainsString('weekly_plan_source_digest', $automation);
        self::assertStringContainsString('下周唯一重点', $delivery);
        self::assertStringContainsString('周计划尚未完成保存与精确回读', $delivery);
        self::assertStringContainsString('AiDailyReportBroadcastSnapshotService', (string)file_get_contents(
            $root . '/app/service/WeeklyOperatingPlanSnapshotService.php'
        ));
    }

    public function testWeeklyPreparationUsesTheExistingCloudWeeklyEntranceOnly(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/PrepareWeeklyOperatingPlan.php');
        $config = (string)file_get_contents($root . '/config/console.php');
        $automation = (string)file_get_contents($root . '/app/service/CloudAutomationService.php');
        $timer = (string)file_get_contents($root . '/deploy/systemd/suxios-cloud-weekly.timer');

        self::assertStringContainsString("setName('operation:prepare-weekly')", $command);
        self::assertStringContainsString('WeeklyOperatingPlanSnapshotService', $command);
        self::assertStringContainsString("'operation:prepare-weekly'", $config);
        self::assertStringContainsString('WeeklyOperatingPlanSnapshotService', $automation);
        self::assertStringContainsString('suxios-cloud-automation@weekly.service', $timer);
        self::assertFileDoesNotExist($root . '/deploy/systemd/suxios-weekly-operating-preparation@.timer');
        self::assertStringNotContainsString('approve', strtolower($timer));
    }

    public function testSourceErrorsBlockReadyAndPreventNoBacklogClaim(): void
    {
        $sources = $this->sources(false);
        $sources['source_errors'] = ['tasks_unavailable'];
        $draft = (new WeeklyOperatingPlanSnapshotService())->buildDraft(
            80, 80, '2026-08-22', '2026-08-28', $sources
        );

        self::assertSame('blocked_by_source_errors', $draft['status']);
        self::assertSame('lifecycle_source_unavailable', $draft['selected_focus']['type']);
        self::assertStringContainsString('不能声称没有积压事项', $draft['final_text']);
    }

    public function testSpecificIntentIdentityChangesSourceDigestEvenWhenCountsStayEqual(): void
    {
        $firstSources = $this->sources(false);
        $firstSources['intents'] = [['id' => 10, 'source_record_id' => 100, 'status' => 'pending_approval']];
        $secondSources = $this->sources(false);
        $secondSources['intents'] = [['id' => 11, 'source_record_id' => 101, 'status' => 'pending_approval']];
        $service = new WeeklyOperatingPlanSnapshotService();

        $first = $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $firstSources);
        $second = $service->buildDraft(80, 80, '2026-08-22', '2026-08-28', $secondSources);
        self::assertNotSame($first['source_digest'], $second['source_digest']);
        self::assertNotSame($first['selected_focus']['evidence_refs'], $second['selected_focus']['evidence_refs']);
    }

    public function testReviewFocusReferencesTheObservingTaskNotAnOlderTerminalTask(): void
    {
        $sources = $this->sources(false);
        $sources['intents'] = [];
        $sources['tasks'] = [
            ['id' => 30, 'intent_id' => 20, 'status' => 'executed', 'result_status' => 'success'],
            ['id' => 31, 'intent_id' => 21, 'status' => 'executed', 'result_status' => 'observing'],
        ];
        $draft = (new WeeklyOperatingPlanSnapshotService())->buildDraft(
            80, 80, '2026-08-22', '2026-08-28', $sources
        );

        self::assertSame(1, $draft['lifecycle_summary']['review_pending']);
        self::assertSame('review_pending', $draft['selected_focus']['type']);
        self::assertSame(['operation_execution_tasks#31'], $draft['selected_focus']['evidence_refs']);
    }

    public function testScopeMismatchDoesNotInvokeSnapshotWriter(): void
    {
        $writes = 0;
        $service = new WeeklyOperatingPlanSnapshotService(
            fn(): array => $this->sources(false),
            static fn(): mixed => null,
            static function () use (&$writes): int { $writes++; return 1; },
            null,
            static fn(): bool => false
        );

        $this->expectException(\RuntimeException::class);
        try {
            $service->generateAndReadback(81, 80, '2026-08-28');
        } finally {
            self::assertSame(0, $writes);
        }
    }

    public function testDuplicateInsertReadsBackTheConcurrentWinner(): void
    {
        $winner = null;
        $reader = static function (string $action) use (&$winner): mixed {
            if ($action === 'next_version') return 1;
            if ($action === 'by_source') return $winner;
            if ($action === 'exact') return $winner;
            return null;
        };
        $writer = static function (array $row) use (&$winner): never {
            $winner = ['id' => 77] + $row;
            throw new \RuntimeException('SQLSTATE[23000]: duplicate entry');
        };
        $service = new WeeklyOperatingPlanSnapshotService(
            fn(): array => $this->sources(false),
            $reader,
            $writer,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-08-29 03:30:00'),
            static fn(): bool => true
        );

        $result = $service->generateAndReadback(80, 80, '2026-08-28');
        self::assertSame(77, $result['snapshot_id']);
        self::assertFalse($result['created']);
        self::assertTrue($result['idempotent_replay']);
        self::assertTrue($result['readback_verified']);
    }

    /** @return array<string,mixed> */
    private function sources(bool $withRepeatedGap): array
    {
        $daily = [];
        $broadcasts = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = (new \DateTimeImmutable('2026-08-22'))->modify('+' . $offset . ' day')->format('Y-m-d');
            $gaps = $withRepeatedGap && $offset < 3
                ? ['ctrip_target_date_source_rows_missing']
                : [];
            $daily[] = [
                'id' => 100 + $offset,
                'business_date' => $date,
                'input_digest' => hash('sha256', 'input-' . $date),
                'result_digest' => hash('sha256', 'result-' . $date),
                'input' => ['source_digest' => hash('sha256', 'source-' . $date)],
                'result' => [
                    'selected' => [
                        'problem' => $gaps === [] ? '检查已保存事项' : '携程目标日期可信事实尚未回读',
                        'source' => ['gap_codes' => $gaps],
                    ],
                ],
            ];
            $broadcasts[] = [
                'id' => 200 + $offset,
                'business_date' => $date,
                'facts_fingerprint' => hash('sha256', 'facts-' . $date),
                'snapshot_fingerprint' => hash('sha256', 'snapshot-' . $date),
            ];
        }
        return [
            'hotel_name' => '敦煌漠蓝新',
            'daily_runs' => $daily,
            'broadcasts' => $broadcasts,
            'intents' => [],
            'tasks' => [],
            'source_errors' => [],
        ];
    }
}
