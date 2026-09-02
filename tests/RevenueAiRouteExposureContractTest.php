<?php
declare(strict_types=1);

namespace Tests;

use app\controller\RevenueAi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RevenueAiRouteExposureContractTest extends TestCase
{
    public function testObsoleteCockpitApprovalActionIsNotPublicOrRouted(): void
    {
        $controller = new ReflectionClass(RevenueAi::class);
        $routes = (string)file_get_contents(__DIR__ . '/../route/app.php');

        self::assertFalse($controller->hasMethod('createCockpitPendingApproval'));
        self::assertStringNotContainsString(
            'RevenueAi/createCockpitPendingApproval',
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/cockpit/decision-snapshots/:id/pending-approval', 'RevenueAi/createCockpitOpportunityPendingApproval')",
            $routes
        );
        self::assertStringContainsString(
            "Route::get('/cockpit/pending-approval', 'RevenueAi/readCockpitPendingApproval')",
            $routes
        );
    }
}
