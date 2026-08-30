<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use Tests\Support\RouteContractSource;

final class ControllerRouteContractTest extends TestCase
{
    public function testLocalMediaExtractionWriteRequiresOperationExecuteWhileReadbackStaysViewOnly(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OperatingIntelligence.php');
        $extractStart = strpos($source, 'public function extractLocalMedia');
        $listStart = strpos($source, 'public function localMediaExtractions');
        $readStart = strpos($source, 'public function readLocalMediaExtraction');

        self::assertNotFalse($extractStart);
        self::assertNotFalse($listStart);
        self::assertNotFalse($readStart);

        $extractMethod = substr($source, (int)$extractStart, (int)$listStart - (int)$extractStart);
        $listMethod = substr($source, (int)$listStart, (int)$readStart - (int)$listStart);

        self::assertStringContainsString("'operation.execute'", $extractMethod);
        self::assertStringNotContainsString("'operation.view'", $extractMethod);
        self::assertStringContainsString("'operation.view'", $listMethod);
    }

    public function testEveryRouteHandlerResolvesToPublicControllerMethod(): void
    {
        $handlers = $this->routeHandlers();

        self::assertGreaterThan(100, count($handlers));

        foreach ($handlers as $handler) {
            [$controller, $method] = $handler;
            $class = 'app\\controller\\' . str_replace('.', '\\', $controller);

            self::assertTrue(class_exists($class), "Missing route controller: {$class}");
            self::assertTrue(method_exists($class, $method), "Missing route method: {$controller}/{$method}");

            $reflection = new ReflectionMethod($class, $method);
            self::assertTrue($reflection->isPublic(), "Route method must be public: {$controller}/{$method}");
        }
    }

    public function testEveryControllerFileCanBeAutoloaded(): void
    {
        $classes = $this->controllerClasses();

        self::assertGreaterThan(25, count($classes));

        foreach ($classes as $class) {
            self::assertTrue(
                class_exists($class) || trait_exists($class),
                "Controller class or trait is not autoloadable: {$class}"
            );
        }
    }

    public function testExpansionRecordDeleteRoutesKeepSpecificHandlersBeforeCollectionClear(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();
        $marketClear = strpos($source, "Route::delete('/records/market-evaluation', 'Expansion/clearMarketEvaluation')");
        $singleArchive = strpos($source, "Route::delete('/records/:id', 'Expansion/archive')");
        $collectionClear = strpos($source, "Route::delete('/records', 'Expansion/clearRecords')");

        self::assertNotFalse($marketClear, 'Missing expansion market-evaluation clear route');
        self::assertNotFalse($singleArchive, 'Missing expansion single-record archive route');
        self::assertNotFalse($collectionClear, 'Missing expansion collection clear route');
        self::assertLessThan($singleArchive, $marketClear, 'Specific market clear route must be checked before :id route');
        self::assertLessThan($collectionClear, $singleArchive, 'Single-record archive route must be checked before collection clear route');
    }

    public function testRevenueResearchCanCreateExecutionIntentRoute(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/execution-intent', 'RevenueResearch/createExecutionIntent')",
            $source,
            'Revenue research must expose a canonical execution-intent bridge route'
        );
    }

    public function testRevenueResearchExecutionIntentUsesOneTimeServerArtifactInsteadOfClientResearchPayload(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/RevenueResearch.php');
        $start = strpos($source, 'public function createExecutionIntent');
        $end = strpos($source, 'private function existingExecutionIntentRows', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString("\$data['research_artifact_id']", $method);
        self::assertStringContainsString('$artifactService->consume(', $method);
        self::assertStringNotContainsString("\$data['research']", $method);
        self::assertStringNotContainsString("'action_text'", $method);
    }

    public function testRevenueAiPriceSuggestionManualReviewRoutes(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion')",
            $source,
            'Revenue AI must expose a hotel-permission manual review route'
        );
        self::assertStringContainsString(
            "Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent')",
            $source,
            'Revenue AI must expose an approved suggestion execution-intent route'
        );
        self::assertStringContainsString(
            "Route::post('/price-suggestions/:id/approve', 'RevenueAi/reviewPriceSuggestion')",
            $source,
            'The legacy approve URL must pass through the Revenue AI trusted-input review gate'
        );
        self::assertStringNotContainsString(
            "Route::post('/price-suggestions/:id/approve', 'Agent/approvePrice')",
            $source,
            'The legacy approve URL must not retain the direct Agent status mutation path'
        );
    }

    public function testRevenueBundleDashboardUsesTheRequestedBusinessDate(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/Agent.php');

        self::assertStringContainsString(
            "'dashboard' => \$this->buildRevenueDashboardPayload(\$hotelId, \$businessDate)",
            $source
        );
        self::assertStringContainsString(
            "->where('suggestion_date', \$businessDate)",
            $source
        );
        self::assertStringContainsString(
            "hotelPricingModelSummary(\$hotelId, \$businessDate)",
            $source
        );
        self::assertSame(
            3,
            substr_count(
                $source,
                "param('business_date', \$this->defaultRevenueBusinessDate())"
            ),
            'Bundle, standalone analysis, and standalone dashboard must share the Revenue AI complete-day default'
        );
        self::assertStringContainsString("return date('Y-m-d', strtotime('-1 day'));", $source);
        self::assertStringNotContainsString(
            '(new RevenueAiOverviewService())->buildOverviewFromDataset([], [], [], [])',
            $source,
            'Reading a default business date must not build a complete Revenue AI overview'
        );
    }

    public function testOperationExecutionResourcesExposeHotelScopedReadRoutesBeforeCollection(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();
        $intentRead = strpos($source, "Route::get('/execution-intents/:id', 'OperationManagement/readExecutionIntent')");
        $taskRead = strpos($source, "Route::get('/execution-tasks/:id', 'OperationManagement/readExecutionTask')");
        $collection = strpos($source, "Route::get('/execution-intents', 'OperationManagement/executionIntents')");

        self::assertNotFalse($intentRead);
        self::assertNotFalse($taskRead);
        self::assertNotFalse($collection);
        self::assertLessThan($collection, $intentRead);
        self::assertLessThan($collection, $taskRead);
    }

    public function testAgentSavedOtaDiagnosisCanCreateManualExecutionIntentRoute(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/ota-diagnoses/:id/actions/:actionIndex/execution-intent', 'Agent/createOtaDiagnosisExecutionIntent')",
            $source,
            'A saved OTA diagnosis must expose a manual execution-intent bridge route'
        );
    }

    public function testCtripReviewOrderMatchRoutes(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/im-sessions', 'ota.CtripController/saveCtripReviewImSession')",
            $source,
            'Ctrip review matching must accept authorized IM session cache imports'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/reviews', 'ota.CtripController/saveCtripReviewForMatch')",
            $source,
            'Ctrip review matching must accept review records without enabling live comment collection'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/orders', 'ota.CtripController/saveCtripOrderForMatch')",
            $source,
            'Ctrip review matching must accept OTA order pool records'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/lookup', 'ota.CtripController/lookupCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose lookup route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/identity-preview', 'ota.CtripController/previewCtripReviewOrdererIdentity')",
            $source,
            'Ctrip review matching must expose read-only page identity preview route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/run', 'ota.CtripController/runCtripReviewOrderMatchAutomation')",
            $source,
            'Ctrip review matching must expose one-click automation route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/closure', 'ota.CtripController/checkCtripReviewOrderMatchClosure')",
            $source,
            'Ctrip review matching must expose real-data closure verification route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/bind', 'ota.CtripController/bindCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose manual bind route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/reject', 'ota.CtripController/rejectCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose manual reject route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/unbind', 'ota.CtripController/unbindCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose manual unbind route'
        );
    }

    public function testMeituanReviewOrderMatchRoutes(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/reviews', 'ota.MeituanController/saveMeituanReviewForMatch')",
            $source,
            'Meituan review matching must accept review records'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/orders', 'ota.MeituanController/saveMeituanOrderForMatch')",
            $source,
            'Meituan review matching must accept authorized OTA order pool records'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/lookup', 'ota.MeituanController/lookupMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose lookup route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/run', 'ota.MeituanController/runMeituanReviewOrderMatchAutomation')",
            $source,
            'Meituan review matching must expose the safe batch scoring route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/closure', 'ota.MeituanController/checkMeituanReviewOrderMatchClosure')",
            $source,
            'Meituan review matching must expose persisted closure readback'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/bind', 'ota.MeituanController/bindMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose manual bind route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/unbind', 'ota.MeituanController/unbindMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose manual unbind route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/reject', 'ota.MeituanController/rejectMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose manual reject route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-orders/phone-state', 'ota.MeituanController/meituanOrderPhoneState')",
            $source,
            'Meituan order phone handling must expose a masked status route'
        );
    }

    public function testOperationExecutionReviewControllerForwardsManualReviewPayload(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OperationManagement.php');

        self::assertStringContainsString(
            '$this->service->reviewExecutionTask(',
            $source,
            'Operation execution review must forward manual result_status/result_summary payload'
        );
        self::assertStringContainsString(
            '$this->service->reconcileScheduledExecutionTask(',
            $source,
            'Scheduled execution readback must use the scoped operation service'
        );
        self::assertStringContainsString(
            '$this->service->cancelExecutionIntent(',
            $source,
            'Managed action cancellation must use the scoped operation service'
        );

        $routes = $this->allRouteSourceWithoutPhpComments();
        self::assertStringContainsString(
            "Route::post('/execution-tasks/:id/reconcile-review', 'OperationManagement/reconcileExecutionTaskReview')",
            $routes,
            'Scheduled execution readback must expose an authenticated operation route'
        );
        self::assertStringContainsString(
            "Route::post('/execution-intents/:id/cancel', 'OperationManagement/cancelExecutionIntent')",
            $routes,
            'Managed action cancellation must expose an authenticated operation route'
        );
    }

    public function testOperatingGrowthArchiveRoutesStayBehindScopedOperationController(): void
    {
        $controller = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OperationManagement.php');
        self::assertStringContainsString('$this->memoryService->growthTimeline(', $controller);
        self::assertStringContainsString('$this->memoryService->createManualGrowthEvent(', $controller);
        self::assertStringContainsString('$this->memoryService->addOwnerAnnotation(', $controller);
        self::assertStringContainsString('$this->memoryService->markMilestone(', $controller);
        self::assertStringContainsString("'operation.execute'", $controller);

        $routes = $this->allRouteSourceWithoutPhpComments();
        self::assertStringContainsString(
            "Route::get('/growth-archive/timeline', 'OperationManagement/growthArchiveTimeline')",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/growth-archive/events', 'OperationManagement/createGrowthArchiveEvent')",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/growth-archive/:id/annotations', 'OperationManagement/addGrowthArchiveAnnotation')",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('/growth-archive/:id/milestone', 'OperationManagement/markGrowthArchiveMilestone')",
            $routes
        );
    }

    public function testStrategyAndQuantRecordsCanCreateExecutionIntentRoutes(): void
    {
        $source = $this->allRouteSourceWithoutPhpComments();

        self::assertStringContainsString(
            "Route::post('/records/:id/execution-intent', 'StrategySimulation/createExecutionIntent')",
            $source,
            'Strategy records must expose execution-intent bridge route'
        );
        self::assertStringContainsString(
            "Route::post('/records/:id/execution-intent', 'Simulation/createExecutionIntent')",
            $source,
            'Quant simulation records must expose execution-intent bridge route'
        );
    }

    public function testReservedExecutionSourcesStayBehindScopedProducerControllers(): void
    {
        $operationController = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OperationManagement.php');
        self::assertStringContainsString("\$input['source_module'] = 'manual';", $operationController);
        self::assertStringContainsString("\$input['source_record_id'] = 0;", $operationController);
        self::assertStringContainsString("'source_module' => 'operation_strategy_simulation'", $operationController);

        $strategy = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/StrategySimulation.php');
        $quant = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/Simulation.php');
        self::assertStringContainsString("'investment.simulate'", $quant);
        self::assertStringContainsString("'investment.view'", $quant);
        self::assertStringContainsString('canAccessInvestmentRecord(', $quant);
        $publicDiagnosis = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/concern/CtripCompetitiveOperationsConcern.php');
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*null,\s*true\s*\)/', $strategy);
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*null,\s*true\s*\)/', $quant);
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*\$idempotencyKey,\s*true\s*\)/', $publicDiagnosis);

        $service = $this->sourceWithoutPhpComments(__DIR__ . '/../app/service/OperationManagementService.php');
        foreach (['ota_diagnosis', 'strategy_simulation', 'quant_simulation'] as $reservedSource) {
            self::assertStringContainsString("'{$reservedSource}'", $service);
        }
        self::assertStringContainsString('assertPublicPageDiagnosisIntentReadyForApproval($intent)', $service);
        self::assertStringContainsString('withSourceBackedExecutionIntentApprovalAuthorization(', $service);

        $tenantConcern = $this->sourceWithoutPhpComments(
            __DIR__ . '/../app/service/operation/OperationExecutionTenantConcern.php'
        );
        self::assertStringContainsString('assertSimulationIntentSourceIsCurrent(', $tenantConcern);
    }

    public function testReleaseEvidenceStatusRouteStaysAuthenticatedAndNonClosing(): void
    {
        $routes = $this->allRouteSourceWithoutPhpComments();
        $otaHandler = $this->sourceWithoutPhpComments(__DIR__ . '/../app/service/Ota/OtaActionHandler.php');
        $concern = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/concern/ReleaseEvidenceConcern.php');

        self::assertStringContainsString(
            "Route::get('/release-evidence-status', 'OnlineData/releaseEvidenceStatus')",
            $routes,
            'Release evidence status must be exposed only through the authenticated online-data route group'
        );
        self::assertStringContainsString(
            "Route::group('api/online-data'",
            $routes,
            'Release evidence status must remain inside the authenticated online-data route group'
        );
        self::assertStringContainsString(
            '})->middleware(\app\middleware\Auth::class);',
            $routes,
            'Online-data route group must stay behind Auth middleware'
        );
        self::assertStringContainsString('use ReleaseEvidenceConcern;', $otaHandler);
        self::assertStringContainsString('$this->checkPermission();', $concern);
        self::assertStringContainsString('if (!$this->currentUser->isSuperAdmin()) {', $concern);
        self::assertStringContainsString('abort(403, \'release evidence status requires super admin\');', $concern);
        self::assertStringContainsString('$this->checkActionPermission(\'can_view_online_data\');', $concern);
        self::assertStringContainsString("'does_not_close_release_readiness' => true", $concern);
        self::assertStringContainsString("docs/release_blocker_policy.json", $concern);
        self::assertStringNotContainsString("docs/release_readiness_status.json", $concern, 'The authenticated API must not expose the dated historical snapshot as current state');
        self::assertStringContainsString("'required_file' => '../release-evidence-temp/design_handoff_manifest.json'", $concern);
        self::assertStringNotContainsString("releaseEvidenceRepoPath('../release-evidence-temp", $concern, 'Runtime evidence directory paths must not be read directly by the API');
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    private function routeHandlers(): array
    {
        $source = $this->allRouteSourceWithoutPhpComments();
        preg_match_all("/['\"]((?:[A-Z][A-Za-z0-9_]*|admin\\.[A-Za-z0-9_.]+))\\/([A-Za-z0-9_]+)['\"]/", $source, $matches, PREG_SET_ORDER);

        $handlers = [];
        foreach ($matches as $match) {
            $handlers[$match[1] . '/' . $match[2]] = [$match[1], $match[2]];
        }

        return array_values($handlers);
    }

    private function allRouteSourceWithoutPhpComments(): string
    {
        return $this->sourceTextWithoutPhpComments(RouteContractSource::read(dirname(__DIR__)));
    }

    /**
     * @return array<int, class-string>
     */
    private function controllerClasses(): array
    {
        $root = realpath(__DIR__ . '/../app/controller');
        self::assertIsString($root);

        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $classes[] = 'app\\controller\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        sort($classes);

        return $classes;
    }

    private function sourceWithoutPhpComments(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $this->sourceTextWithoutPhpComments($source);
    }

    private function sourceTextWithoutPhpComments(string $source): string
    {

        $output = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $output .= is_array($token) ? $token[1] : $token;
        }

        return $output;
    }
}
