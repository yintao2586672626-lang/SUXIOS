<?php
declare(strict_types=1);

namespace Tests;

use app\service\ExpansionService;
use app\service\FeasibilityReportService;
use app\service\TransferDecisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class RetiredBackendServiceContractTest extends TestCase
{
    #[DataProvider('retiredServiceWrites')]
    public function testRetiredWritesRejectBeforeInputOrDependencyAccess(string $class, string $method, array $arguments): void
    {
        // An uninitialized service has no LLM, projection, quality service or
        // application/database state. Any access before the guard fails here.
        $service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        try {
            $service->$method(...$arguments);
            self::fail('Retired service write was accepted: ' . $class . '::' . $method);
        } catch (RuntimeException $exception) {
            self::assertSame('retired_read_only', $exception->getMessage());
            self::assertSame(410, $exception->getCode());
        }
    }

    public static function retiredServiceWrites(): array
    {
        return [
            'expansion market' => [ExpansionService::class, 'evaluateMarket', [[]]],
            'expansion benchmark' => [ExpansionService::class, 'buildBenchmarkModel', [[]]],
            'expansion collaboration' => [ExpansionService::class, 'improveCollaboration', [[]]],
            'expansion save' => [ExpansionService::class, 'saveRecord', ['market', [], [], 3]],
            'expansion archive' => [ExpansionService::class, 'archive', [37, 3, false]],
            'expansion clear one type' => [ExpansionService::class, 'archiveByType', ['market', 3, false]],
            'expansion clear types' => [ExpansionService::class, 'archiveByTypes', [['market'], 3, false]],
            'transfer pricing' => [TransferDecisionService::class, 'calculateAssetPricing', [[]]],
            'transfer timing' => [TransferDecisionService::class, 'calculateTransferTiming', [[]]],
            'transfer dashboard' => [TransferDecisionService::class, 'buildTransferDashboard', [[], [], []]],
            'transfer save' => [TransferDecisionService::class, 'saveRecord', ['pricing', [], [], [], 7, 3]],
            'transfer archive' => [TransferDecisionService::class, 'archive', [37, [7], 3, false]],
            'feasibility generate' => [FeasibilityReportService::class, 'generate', [[], 3]],
            'feasibility regenerate' => [FeasibilityReportService::class, 'regenerate', [37, 3, false]],
            'feasibility archive' => [FeasibilityReportService::class, 'archive', [37, 3, false]],
        ];
    }
}
