<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

final class ControllerRouteContractTest extends TestCase
{
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
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');
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
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

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
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

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
    }

    public function testOperationExecutionResourcesExposeHotelScopedReadRoutesBeforeCollection(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');
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
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

        self::assertStringContainsString(
            "Route::post('/ota-diagnoses/:id/actions/:actionIndex/execution-intent', 'Agent/createOtaDiagnosisExecutionIntent')",
            $source,
            'A saved OTA diagnosis must expose a manual execution-intent bridge route'
        );
    }

    public function testCtripReviewOrderMatchRoutes(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/im-sessions', 'OnlineData/saveCtripReviewImSession')",
            $source,
            'Ctrip review matching must accept authorized IM session cache imports'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/reviews', 'OnlineData/saveCtripReviewForMatch')",
            $source,
            'Ctrip review matching must accept review records without enabling live comment collection'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/orders', 'OnlineData/saveCtripOrderForMatch')",
            $source,
            'Ctrip review matching must accept OTA order pool records'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/lookup', 'OnlineData/lookupCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose lookup route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/identity-preview', 'OnlineData/previewCtripReviewOrdererIdentity')",
            $source,
            'Ctrip review matching must expose read-only page identity preview route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/run', 'OnlineData/runCtripReviewOrderMatchAutomation')",
            $source,
            'Ctrip review matching must expose one-click automation route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/closure', 'OnlineData/checkCtripReviewOrderMatchClosure')",
            $source,
            'Ctrip review matching must expose real-data closure verification route'
        );
        self::assertStringContainsString(
            "Route::post('/ctrip-review-matches/bind', 'OnlineData/bindCtripReviewOrderMatch')",
            $source,
            'Ctrip review matching must expose manual bind route'
        );
    }

    public function testMeituanReviewOrderMatchRoutes(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/reviews', 'OnlineData/saveMeituanReviewForMatch')",
            $source,
            'Meituan review matching must accept review records'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/orders', 'OnlineData/saveMeituanOrderForMatch')",
            $source,
            'Meituan review matching must accept authorized OTA order pool records'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/lookup', 'OnlineData/lookupMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose lookup route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/bind', 'OnlineData/bindMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose manual bind route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-review-matches/unbind', 'OnlineData/unbindMeituanReviewOrderMatch')",
            $source,
            'Meituan review matching must expose manual unbind route'
        );
        self::assertStringContainsString(
            "Route::post('/meituan-orders/phone-state', 'OnlineData/meituanOrderPhoneState')",
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
    }

    public function testStrategyAndQuantRecordsCanCreateExecutionIntentRoutes(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');

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
        $publicDiagnosis = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/concern/CtripCompetitiveOperationsConcern.php');
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*null,\s*true\s*\)/', $strategy);
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*null,\s*true\s*\)/', $quant);
        self::assertMatchesRegularExpression('/createExecutionIntent\([\s\S]*?false,\s*\$idempotencyKey,\s*true\s*\)/', $publicDiagnosis);

        $service = $this->sourceWithoutPhpComments(__DIR__ . '/../app/service/OperationManagementService.php');
        foreach (['ota_diagnosis', 'strategy_simulation', 'quant_simulation'] as $reservedSource) {
            self::assertStringContainsString("'{$reservedSource}'", $service);
        }
        self::assertStringContainsString('assertPublicPageDiagnosisIntentReadyForApproval($intent)', $service);
        self::assertStringContainsString('assertSimulationIntentSourceIsCurrent($intent)', $service);
    }

    public function testReleaseEvidenceStatusRouteStaysAuthenticatedAndNonClosing(): void
    {
        $routes = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');
        $onlineData = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OnlineData.php');
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
        self::assertStringContainsString('use ReleaseEvidenceConcern;', $onlineData);
        self::assertStringContainsString('$this->checkPermission();', $concern);
        self::assertStringContainsString('if (!$this->currentUser->isSuperAdmin()) {', $concern);
        self::assertStringContainsString('abort(403, \'release evidence status requires super admin\');', $concern);
        self::assertStringContainsString('$this->checkActionPermission(\'can_view_online_data\');', $concern);
        self::assertStringContainsString("'does_not_close_release_readiness' => true", $concern);
        self::assertStringContainsString("docs/release_readiness_status.json", $concern);
        self::assertStringContainsString("'required_file' => '../release-evidence-temp/design_handoff_manifest.json'", $concern);
        self::assertStringNotContainsString("releaseEvidenceRepoPath('../release-evidence-temp", $concern, 'Runtime evidence directory paths must not be read directly by the API');
    }

    /**
     * @return array<int, array{0:string, 1:string}>
     */
    private function routeHandlers(): array
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../route/app.php');
        preg_match_all("/['\"]((?:[A-Z][A-Za-z0-9_]*|admin\\.[A-Za-z0-9_.]+))\\/([A-Za-z0-9_]+)['\"]/", $source, $matches, PREG_SET_ORDER);

        $handlers = [];
        foreach ($matches as $match) {
            $handlers[$match[1] . '/' . $match[2]] = [$match[1], $match[2]];
        }

        return array_values($handlers);
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
