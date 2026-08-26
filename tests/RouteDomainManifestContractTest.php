<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\RouteContractSource;

final class RouteDomainManifestContractTest extends TestCase
{
    private const EXTRACTED_ROUTE_SURFACE_COUNT = 125;
    private const EXTRACTED_ROUTE_SURFACE_SHA256 = 'dedd6a8580657a9ff62ed26a9fd9ab3e644ab04404d3ea157dcc80d706cb39ce';

    private const EXTRACTED_GROUP_PREFIXES = [
        'api/ai-config',
        'api/ai-governance',
        'api/operating-loop',
        'api/operating-opportunities',
        'api/operation',
        'api/opening',
        'api/expansion',
        'api/transfer',
        'admin/competitor-wechat-robot',
        'api/admin/competitor-wechat-robot',
        'api/wechat-notification',
    ];

    /**
     * @var array<string, list<array{0:string, 1:string, 2:string}>>
     */
    private const EXPECTED_GROUPS = [
        'api/ai-config' => [
            ['get', '/models', 'AiConfig/models'],
            ['post', '/providers/quick-setup', 'AiConfig/quickSetupProvider'],
            ['post', '/models/<id>/test', 'AiConfig/testModel'],
            ['post', '/models', 'AiConfig/createModel'],
            ['put', '/models/<id>', 'AiConfig/updateModel'],
            ['delete', '/models/<id>', 'AiConfig/deleteModel'],
        ],
        'api/ai-governance' => [
            ['get', '/summary', 'AiGovernance/summary'],
            ['get', '/logs/:id', 'AiGovernance/logDetail'],
            ['post', '/logs/:id/confirm', 'AiGovernance/confirmLog'],
            ['get', '/logs', 'AiGovernance/logs'],
            ['get', '/prompt-versions', 'AiGovernance/promptVersions'],
            ['post', '/prompt-versions', 'AiGovernance/savePromptVersion'],
            ['post', '/evaluation-cases/replay', 'AiGovernance/replayEvaluationCases'],
            ['get', '/evaluation-runs/:id', 'AiGovernance/evaluationRunDetail'],
            ['get', '/evaluation-runs', 'AiGovernance/evaluationRuns'],
            ['delete', '/evaluation-cases/:id', 'AiGovernance/archiveEvaluationCase'],
            ['get', '/evaluation-cases', 'AiGovernance/evaluationCases'],
            ['post', '/evaluation-cases', 'AiGovernance/saveEvaluationCase'],
        ],
    ];

    public function testBootstrapLoadsTheAiGovernanceManifestOnceAtTheLegacyOrderBoundary(): void
    {
        $bootstrap = $this->read('route/app.php');
        $require = "require __DIR__ . '/domain/ai_governance.php';";

        self::assertSame(1, substr_count($bootstrap, $require));
        self::assertStringNotContainsString("Route::group('api/ai-config'", $bootstrap);
        self::assertStringNotContainsString("Route::group('api/ai-governance'", $bootstrap);

        $revenueAi = strpos($bootstrap, "Route::group('api/revenue-ai'");
        $manifest = strpos($bootstrap, $require);
        $holidayRevenue = strpos($bootstrap, "Route::group('api/holiday-revenue'");
        self::assertIsInt($revenueAi);
        self::assertIsInt($manifest);
        self::assertIsInt($holidayRevenue);
        self::assertLessThan($manifest, $revenueAi);
        self::assertLessThan($holidayRevenue, $manifest);
    }

    public function testManifestPreservesEveryMethodPathHandlerAndAuthenticationBoundary(): void
    {
        $manifest = $this->read('route/domain/ai_governance.php');
        $allActualRoutes = [];

        foreach (self::EXPECTED_GROUPS as $prefix => $expectedRoutes) {
            $groupPattern = sprintf(
                "/Route::group\\('%s', function \\(\\) \\{(?P<body>.*?)\\}\\)->middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);/s",
                preg_quote($prefix, '/')
            );
            self::assertSame(1, preg_match($groupPattern, $manifest, $groupMatch), "Missing authenticated route group {$prefix}");

            preg_match_all(
                "/Route::(get|post|put|delete)\\('([^']+)', '([^']+)'\\);/",
                $groupMatch['body'],
                $routeMatches,
                PREG_SET_ORDER
            );
            $actualRoutes = array_map(
                static fn(array $match): array => [$match[1], $match[2], $match[3]],
                $routeMatches
            );
            self::assertSame($expectedRoutes, $actualRoutes, "Route contract drifted for {$prefix}");
            array_push($allActualRoutes, ...$actualRoutes);
        }

        preg_match_all(
            "/Route::(get|post|put|delete)\\('([^']+)', '([^']+)'\\);/",
            $manifest,
            $manifestMatches,
            PREG_SET_ORDER
        );
        self::assertCount(count($allActualRoutes), $manifestMatches, 'Manifest contains an ungoverned or duplicate route');
    }

    public function testOperatingOpportunityRoutesKeepTheSpecificWriteBeforeTheDynamicRead(): void
    {
        $manifest = $this->read('route/domain/operations.php');
        $groupPattern = "/Route::group\\('api\/operating-opportunities', function \\(\\) \\{(?P<body>.*?)\\}\\)->middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);/s";
        self::assertSame(1, preg_match($groupPattern, $manifest, $groupMatch));

        preg_match_all(
            "/Route::(get|post)\\('([^']+)', '([^']+)'\\);/",
            $groupMatch['body'],
            $routeMatches,
            PREG_SET_ORDER
        );
        $actualRoutes = array_map(
            static fn(array $match): array => [$match[1], $match[2], $match[3]],
            $routeMatches
        );

        self::assertSame([
            ['get', '/overview', 'OperatingOpportunity/overview'],
            ['post', '/runs/:id/pending-approval', 'OperatingOpportunity/pendingApproval'],
            ['get', '/runs/:id', 'OperatingOpportunity/read'],
            ['post', '/evaluate', 'OperatingOpportunity/evaluate'],
            ['post', '/priority', 'OperatingOpportunity/priority'],
        ], $actualRoutes);
    }

    public function testEveryDomainManifestIsRegisteredAndTheExtractedRouteSurfaceIsByteStable(): void
    {
        $root = dirname(__DIR__);
        $bootstrap = $this->read('route/app.php');
        preg_match_all(
            "/require __DIR__ \\. '\/domain\/([a-z0-9_]+\\.php)';/",
            $bootstrap,
            $registeredMatches
        );
        $registeredFiles = array_map(
            static fn(string $file): string => 'route/domain/' . $file,
            $registeredMatches[1]
        );
        self::assertSame([
            'route/domain/ai_daily_reports.php',
            'route/domain/ai_governance.php',
            'route/domain/operations.php',
            'route/domain/wecom_admin.php',
            'route/domain/wecom_api.php',
            'route/domain/agent_guidance.php',
        ], $registeredFiles);

        $domainFiles = glob($root . '/route/domain/*.php') ?: [];
        $domainFiles = array_map(
            static fn(string $path): string => 'route/domain/' . basename($path),
            $domainFiles
        );
        sort($domainFiles);
        $registeredSorted = $registeredFiles;
        sort($registeredSorted);
        self::assertSame($domainFiles, $registeredSorted, 'Every domain manifest must be explicitly registered exactly once');

        $source = RouteContractSource::read($root);
        $tuples = [];
        foreach (self::EXTRACTED_GROUP_PREFIXES as $prefix) {
            $pattern = sprintf(
                "/Route::group\\('%s', function \\(\\) \\{(?P<body>.*?)\\}\\)->middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);/s",
                preg_quote($prefix, '/')
            );
            self::assertSame(1, preg_match($pattern, $source, $groupMatch), "Missing authenticated route group {$prefix}");
            preg_match_all(
                "/Route::(get|post|put|delete|patch|any|rule)\\('([^']+)', '([^']+)'\\);/",
                $groupMatch['body'],
                $routeMatches,
                PREG_SET_ORDER
            );
            foreach ($routeMatches as $routeMatch) {
                $tuples[] = implode('|', [$prefix, $routeMatch[1], $routeMatch[2], $routeMatch[3]]);
            }
        }

        self::assertCount(self::EXTRACTED_ROUTE_SURFACE_COUNT, $tuples);
        self::assertSame(
            self::EXTRACTED_ROUTE_SURFACE_SHA256,
            hash('sha256', implode("\n", $tuples)),
            'An extracted route changed method, URL, handler, order, or authentication boundary'
        );
    }

    private function read(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
        self::assertIsString($source);

        return $source;
    }
}
