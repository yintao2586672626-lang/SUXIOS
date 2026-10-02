<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\concern\OperationWorkbenchConcern;
use app\service\DailyWorkbenchPatrolService;
use PHPUnit\Framework\TestCase;
use think\Request;
use think\Response;

final class DailyWorkbenchPatrolDateSelectionTest extends TestCase
{
    private string $originalRuntime;
    private string $runtime;
    private ?object $originalEnv;
    private DailyWorkbenchPatrolService $service;

    protected function setUp(): void
    {
        $this->originalRuntime = app()->getRuntimePath();
        $this->runtime = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthetic_patrol_date_view_' . bin2hex(random_bytes(8));
        self::assertDirectoryDoesNotExist($this->runtime);
        app()->setRuntimePath($this->runtime . DIRECTORY_SEPARATOR);
        $this->originalEnv = app()->exists('env') ? app()->make('env') : null;
        app()->instance('env', new class {
            public function get(?string $name = null, $default = null) { return $default; }
        });
        $this->service = new DailyWorkbenchPatrolService();
    }

    protected function tearDown(): void
    {
        app()->setRuntimePath($this->originalRuntime);
        $this->originalEnv !== null ? app()->instance('env', $this->originalEnv) : app()->delete('env');
        foreach (array_keys($this->contents()) as $path) unlink($path);
        foreach (glob($this->baseDir() . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
            if (preg_match('/^\d{8}$/', basename($directory)) && count(scandir($directory)) === 2) rmdir($directory);
        }
        if (is_dir($this->baseDir()) && count(scandir($this->baseDir())) === 2) rmdir($this->baseDir());
        if (is_dir($this->runtime) && count(scandir($this->runtime)) === 2) rmdir($this->runtime);
    }

    public function testSelectedDateReturnsItsCompleteSavedActionsAndTracking(): void
    {
        $a = $this->save(7, '2026-09-13');
        $tracked = $this->service->updateActionStatusForHotel([
            'run_id' => $a['run_id'], 'hotel_id' => 7, 'action_code' => 'check_rate',
            'status' => 'in_progress', 'note' => 'Synthetic operator note retained by date view.',
        ], 7, 2);
        $b = $this->save(7, '2026-09-14');
        $c = $this->save(8, '2026-09-13');
        $before = $this->contents();
        $data = $this->read(['target_date' => '2026-09-13']);

        self::assertSame($a['run_id'], $data['latest']['run_id']);
        self::assertSame($tracked['next_actions'], $data['latest']['next_actions']);
        self::assertSame($tracked['action_tracking'], $data['latest']['action_tracking']);
        self::assertSame($a['run_id'], $data['health']['latest_run_id']);
        self::assertSame('manual_ready', $data['health']['status']);
        self::assertTrue($data['health']['is_target_date_ready']);
        $report = $this->service->markdownReportForHotel(7, $data['latest']['run_id']);
        self::assertSame($a['run_id'], $report['snapshot']['run_id']);
        self::assertStringContainsString('Synthetic saved date action', $report['content']);
        self::assertNotContains($c['run_id'], array_column($data['list'], 'run_id'));
        self::assertSame($b['run_id'], $this->service->latestForHotel(7)['run_id']);
        self::assertSame($c['run_id'], $this->service->latest()['run_id']);
        self::assertSame($before, $this->contents());
    }

    public function testDateLookupFindsSnapshotOutsideThirtyItemHistoryWindow(): void
    {
        $a = $this->save(7, '2026-09-13');
        for ($index = 0; $index < 31; $index++) $this->save(7, '2026-09-14');
        $data = $this->read(['target_date' => '2026-09-13', 'limit' => '30']);
        self::assertCount(30, $data['list']);
        self::assertNotContains($a['run_id'], array_column($data['list'], 'run_id'));
        self::assertSame($a['run_id'], $data['latest']['run_id']);
        self::assertNotEmpty($data['latest']['next_actions']);
        self::assertSame($a['run_id'], $data['health']['latest_run_id']);
    }

    public function testSameDateSelectsLatestCreationWithoutPromotingUpdatedOlderRun(): void
    {
        $a = $this->save(7, '2026-09-13');
        $b = $this->save(7, '2026-09-13', 'cron');
        $this->service->updateActionStatusForHotel([
            'run_id' => $a['run_id'], 'hotel_id' => 7, 'action_code' => 'check_rate', 'status' => 'skipped',
        ], 7);
        $this->save(7, '2026-09-14');
        $data = $this->read(['target_date' => '2026-09-13']);
        self::assertSame($b['run_id'], $data['latest']['run_id']);
        self::assertSame('auto_ready', $data['health']['status']);
        self::assertSame($b['run_id'], $data['health']['latest_run_id']);
    }

    public function testMissingDateDoesNotSubstituteAnotherDateOrHotel(): void
    {
        $this->save(7, '2026-09-14');
        $this->save(8, '2026-09-13');
        $before = $this->contents();
        $data = $this->read(['target_date' => '2026-09-13']);
        self::assertNull($data['latest']);
        self::assertSame('missing', $data['health']['status']);
        self::assertFalse($data['health']['is_target_date_ready']);
        self::assertSame('', $data['health']['latest_run_id']);
        self::assertSame($before, $this->contents());
    }

    public function testLegacyIdRemainsReadableWhileInvalidOrMissingDatesAreSkipped(): void
    {
        $saved = $this->save(7, '2026-09-13');
        $legacy = $this->service->findByRunIdForHotel($saved['run_id'], 7);
        $legacy['run_id'] = 'daily_workbench_20260913_120000_abcdef12';
        $legacy['created_at_microseconds']++;
        $directory = $this->baseDir() . DIRECTORY_SEPARATOR . '20260913';
        file_put_contents($directory . DIRECTORY_SEPARATOR . $legacy['run_id'] . '.json', json_encode($legacy, JSON_THROW_ON_ERROR));
        foreach (['2026-09-13-extra', '', ['2026-09-13'], null] as $index => $invalidDate) {
            $bad = $legacy;
            $bad['run_id'] = 'daily_workbench_20260913_120000_abcdef2' . $index;
            $bad['created_at_microseconds'] += 10 + $index;
            $bad['scope']['target_date'] = $invalidDate;
            file_put_contents($directory . DIRECTORY_SEPARATOR . $bad['run_id'] . '.json', json_encode($bad, JSON_THROW_ON_ERROR));
        }
        $data = $this->read(['target_date' => '2026-09-13']);
        self::assertSame($legacy['run_id'], $data['latest']['run_id']);
        self::assertSame('manual_ready', $data['health']['status']);
        self::assertSame($legacy['next_actions'], $data['latest']['next_actions']);
    }

    public function testEndDateAliasUsesSelectedDateAndUndatedEndpointKeepsLegacyLatest(): void
    {
        $a = $this->save(7, '2026-09-13');
        $b = $this->save(7, '2026-09-14');
        $data = $this->read(['end_date' => '2026-09-13']);
        self::assertSame($a['run_id'], $data['latest']['run_id']);
        self::assertSame('2026-09-13', $data['scope']['target_date']);
        foreach ([[], ['target_date' => ''], ['target_date' => '  '], ['end_date' => ''], ['end_date' => '  ']] as $undatedQuery) {
            $undated = $this->read($undatedQuery);
            self::assertNotNull($undated['latest'], 'Empty date values keep the existing undated endpoint behavior.');
            self::assertSame($b['run_id'], $undated['latest']['run_id']);
            self::assertSame($this->service->healthForHotel(7)['latest_run_id'], $undated['health']['latest_run_id']);
        }
    }

    private function read(array $query): array
    {
        $controller = new class($query) extends Base {
            use OperationWorkbenchConcern;
            public array $capabilities = [];
            public function __construct(array $query) { $this->request = (new Request())->withGet(['hotel_id' => '7'] + $query); }
            protected function checkPermission(): void {}
            private function resolveDashboardHotelId($hotelId, bool $required): int {
                TestCase::assertSame('7', $hotelId);
                TestCase::assertTrue($required);
                return 7;
            }
            private function requireOperationHotelCapability(int $hotelId, string $capability): void { $this->capabilities[] = [$hotelId, $capability]; }
            private function operationWorkbenchInternalError(\Throwable $exception, string $event, string $message): Response { throw $exception; }
        };
        $response = $controller->dailyWorkbenchPatrols();
        self::assertSame(200, $response->getCode());
        self::assertSame([[7, 'operation.view']], $controller->capabilities);
        return $response->getData()['data'];
    }

    private function save(int $hotelId, string $date, string $trigger = 'manual'): array
    {
        return $this->service->write([
            'scope' => ['hotel_id' => $hotelId, 'target_date' => $date, 'requested_hotel_limit' => 1],
            'summary' => ['hotel_count' => 1],
            'rows' => [['hotel_id' => $hotelId, 'target_date' => $date]],
            'next_actions' => [['hotel_id' => $hotelId, 'target_date' => $date, 'action_code' => 'check_rate', 'action' => 'Synthetic saved date action']],
        ], ['trigger_type' => $trigger]);
    }

    private function baseDir(): string { return $this->runtime . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol'; }
    private function contents(): array
    {
        $paths = array_merge(glob($this->baseDir() . DIRECTORY_SEPARATOR . '*.json') ?: [], glob($this->baseDir() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . '*.json') ?: []);
        sort($paths);
        return array_combine($paths, array_map('file_get_contents', $paths)) ?: [];
    }
}
