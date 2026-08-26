<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\RouteContractSource;

final class OperatingGoalInterventionContractTest extends TestCase
{
    public function testMigrationDefinesThreeAppendOnlyLedgers(): void
    {
        $sql = $this->read('database/migrations/20260812_z_create_operating_goal_intervention_learning.sql');

        self::assertStringContainsString('hotel_operating_goal_contracts', $sql);
        self::assertStringContainsString('operation_intervention_contracts', $sql);
        self::assertStringContainsString('operation_intervention_assessments', $sql);
        self::assertStringContainsString('`content_digest` CHAR(64) NOT NULL', $sql);
        self::assertStringContainsString('`causality_claimed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0', $sql);

        $monitorSql = $this->read('database/migrations/20260812_zb_create_operating_goal_monitor_runs.sql');
        self::assertStringContainsString('operating_goal_monitor_runs', $monitorSql);
        self::assertStringContainsString('`last_observed_at` DATETIME NOT NULL', $monitorSql);
    }

    public function testAuthenticatedRoutesExposeOverviewAndThreeWrites(): void
    {
        $routes = RouteContractSource::read(dirname(__DIR__));

        self::assertStringContainsString("Route::get('/goal-intervention-overview', 'OperationManagement/operatingGoalInterventionOverview');", $routes);
        self::assertStringContainsString("Route::post('/goal-contracts', 'OperationManagement/createOperatingGoalContract');", $routes);
        self::assertStringContainsString("Route::post('/execution-intents/:id/intervention', 'OperationManagement/saveExecutionIntentIntervention');", $routes);
        self::assertStringContainsString("Route::post('/execution-tasks/:id/intervention-assessments', 'OperationManagement/assessExecutionTaskIntervention');", $routes);
    }

    public function testControllerPinsHotelScopeBeforeEveryWrite(): void
    {
        $controller = $this->read('app/controller/OperationManagement.php');

        self::assertStringContainsString('public function createOperatingGoalContract(): Response', $controller);
        self::assertStringContainsString('public function createManualIntervention(): Response', $controller);
        self::assertStringContainsString('public function saveExecutionIntentIntervention(int $id): Response', $controller);
        self::assertStringContainsString('public function assessExecutionTaskIntervention(int $id): Response', $controller);
        self::assertGreaterThanOrEqual(4, substr_count($controller, "'operation.execute'"));
    }

    public function testSourceUiExposesGoalInterventionAndOnlyThreeLearningVerdicts(): void
    {
        $template = $this->read('resources/frontend/templates/fragments/17-page-ops-track.html');
        $app = $this->read('public/app-main.js');

        self::assertStringContainsString('data-testid="operating-goal-intervention-learning"', $template);
        self::assertStringContainsString('data-testid="operating-goal-monitor-status"', $template);
        self::assertStringContainsString('系统自动观察与判定', $template);
        self::assertStringContainsString('supported', $template);
        self::assertStringContainsString('contradicted', $template);
        self::assertStringContainsString('indeterminate', $template);
        self::assertStringContainsString("'/operation/goal-contracts'", $app);
        self::assertStringContainsString('/intervention-assessments', $app);
        self::assertStringContainsString("baseline_mode: 'automatic'", $app);
        self::assertStringNotContainsString("name: 'baseline_value'", $app);
        self::assertStringContainsString('不会直接修改 OTA', $app);
    }

    public function testConsoleRegistersBackgroundGoalMonitorWithoutOtaWrite(): void
    {
        $console = $this->read('config/console.php');
        $command = $this->read('app/command/MonitorOperatingGoalInterventions.php');
        $runner = $this->read('scripts/run_operating_goal_monitor.ps1');
        $registrar = $this->read('scripts/register_operating_goal_monitor_task.ps1');

        self::assertStringContainsString("'operation:goal-intervention-monitor'", $console);
        self::assertStringContainsString("->addOption('execute'", $command);
        self::assertStringContainsString("'auto_write_ota' => false", $command);
        self::assertStringContainsString("'timer_status' => 'external_scheduler_unverified'", $command);
        self::assertStringContainsString("'operation:goal-intervention-monitor'", $runner);
        self::assertStringContainsString("'--execute'", $runner);
        self::assertStringContainsString('New-ScheduledTaskAction', $registrar);
        self::assertStringContainsString('New-ScheduledTaskTrigger', $registrar);
        self::assertStringContainsString('New-TimeSpan -Minutes $IntervalMinutes', $registrar);
        self::assertStringContainsString('ActionReadbackVerified = $true', $registrar);
        self::assertStringContainsString('Get-ScheduledTaskInfo', $registrar);
        self::assertStringContainsString('AutoWriteOta = $false', $registrar);
    }

    private function read(string $relativePath): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
        self::assertIsString($content, 'Unable to read ' . $relativePath);
        return $content;
    }
}
