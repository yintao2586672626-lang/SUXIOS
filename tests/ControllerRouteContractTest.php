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

    public function testOperationExecutionReviewControllerForwardsManualReviewPayload(): void
    {
        $source = $this->sourceWithoutPhpComments(__DIR__ . '/../app/controller/OperationManagement.php');

        self::assertStringContainsString(
            '$this->service->reviewExecutionTask($id, $hotelIds, $this->requestData())',
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
