<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OperatingLoopHttpContractTest extends TestCase
{
    public function testRoutesExposeOneAuthenticatedKernelApiAndOrderStaticPathsFirst(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        $start = strpos($routes, "Route::group('api/operating-loop'");
        $end = strpos($routes, "Route::group('api/operation'", $start + 1);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $group = substr($routes, (int)$start, (int)$end - (int)$start);

        self::assertMatchesRegularExpression(
            "/Route::get\('\/current'.*Route::post\('\/reconcile'.*Route::post\('\/'.*Route::post\('\/:id\/transitions'.*Route::get\('\/:id'/s",
            $group
        );
        self::assertStringContainsString('middleware(\\app\\middleware\\Auth::class)', $group);
    }

    public function testHttpBoundaryAllowsOnlyCoordinatorToAdvanceAuthority(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/OperatingLoop.php');

        self::assertStringContainsString("authorizedScope(\$hotelId, 'operation.view')", $controller);
        self::assertStringContainsString("authorizedScope(\$hotelId, 'operation.execute')", $controller);
        self::assertStringContainsString("'actor_id' => (int)(\$this->currentUser->id ?? 0)", $controller);
        self::assertSame(3, substr_count($controller, 'OperatingLoopCoordinatorService())->reconcile('));
        self::assertStringNotContainsString('OperatingLoopKernelService())->open(', $controller);
        self::assertStringNotContainsString('OperatingLoopKernelService())->transition(', $controller);
        self::assertStringNotContainsString("\$input['stage']", $controller);
        self::assertStringNotContainsString("\$input['stage_status']", $controller);
        self::assertStringNotContainsString("\$input['source_identities']", $controller);
        self::assertStringNotContainsString("\$input['metric_definition']", $controller);
        self::assertStringContainsString('并行闭环', $controller);
    }

    public function testCoordinatorReadsDomainRowsButWritesOnlyThroughKernel(): void
    {
        $coordinator = (string)file_get_contents(dirname(__DIR__) . '/app/service/OperatingLoopCoordinatorService.php');
        $kernel = (string)file_get_contents(dirname(__DIR__) . '/app/service/OperatingLoopKernelService.php');

        self::assertStringContainsString('existing_formal_rows_to_operating_cycle_kernel_only', $coordinator);
        self::assertStringContainsString("'trusted_collection' =>", $coordinator);
        self::assertStringContainsString("'review_experience_promotion' =>", $coordinator);
        self::assertMatchesRegularExpression('/\$this->kernel->(?:open|transition)\(/', $coordinator);
        self::assertDoesNotMatchRegularExpression(
            "/Db::name\('(online_daily_data|operation_execution_intents|operation_execution_tasks|operation_effect_reviews|hotel_operating_memories)'\)->(?:insert|update|delete)/",
            $coordinator
        );
        self::assertSame(4, substr_count($kernel, "'entry' => '/api/operating-loop/reconcile'"));
        self::assertStringNotContainsString("'entry' => '/api/operating-loop/' .", $kernel);
    }
}
