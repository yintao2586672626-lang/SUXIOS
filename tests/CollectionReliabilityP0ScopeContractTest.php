<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CollectionReliabilityP0ScopeContractTest extends TestCase
{
    public function testEmployeeConsoleScopesSourcesAndFactsByHotelAndTenant(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/Phase1EmployeeConsoleConcern.php'
        );

        self::assertStringContainsString("\$rowsQuery->where('system_hotel_id', \$systemHotelId);", $source);
        self::assertStringContainsString("\$rowsQuery->where('tenant_id', \$tenantId);", $source);
        self::assertStringContainsString("\$query->where('system_hotel_id', \$systemHotelId);", $source);
        self::assertStringContainsString("\$query->where('tenant_id', \$tenantId);", $source);
        self::assertStringContainsString('rowBelongsToAuthoritativeP0Traffic($row, $platform)', $source);
        self::assertStringContainsString("\$base['status'] = 'scope_unavailable';", $source);
    }

    public function testReliabilityTenantComesFromHotelAndFailsClosedWhenUnavailable(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/CollectionReliabilityConcern.php'
        );
        $start = strpos($source, 'private function collectionReliabilityTenantId');
        $end = strpos($source, 'private function resolveDashboardHotelId', is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("where('id', \$hotelId)->value('tenant_id')", $method);
        $failClosedReturn = strpos($method, 'return 0;');
        $authenticatedFallback = strrpos($method, 'return max(0, (int)($this->currentUser->tenant_id ?? 0));');
        self::assertIsInt($failClosedReturn);
        self::assertIsInt($authenticatedFallback);
        self::assertLessThan($authenticatedFallback, $failClosedReturn);
    }

    public function testMatrixRequiresEveryAuthoritativeRowUiStatusToBeReady(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/Phase1EmployeeConsoleConcern.php'
        );

        self::assertStringContainsString('$allAuthoritativeRowsUiReady = true;', $source);
        self::assertStringContainsString('if (!$rowUiReady)', $source);
        self::assertStringContainsString('&& $allAuthoritativeRowsUiReady', $source);
        self::assertStringNotContainsString("\$entry['status'] = \$complete ? 'complete' : 'incomplete';", $source);
    }

    public function testTenantAggregationDoesNotOverwriteRequestedHotelScope(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/Phase1EmployeeConsoleConcern.php'
        );
        $start = strpos($source, 'private function phase1TrafficSourceReadinessForPlatform');
        $end = strpos($source, 'private function phase1P0ProfileLoginTriggerAction', is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("\$rowSystemHotelId = (int)(\$row['system_hotel_id'] ?? 0);", $method);
        self::assertStringContainsString('as $candidateSystemHotelId)', $method);
        self::assertStringContainsString('as $expectedSystemHotelId)', $method);
        self::assertStringNotContainsString("\$systemHotelId = (int)(\$row['system_hotel_id'] ?? 0);", $method);
        self::assertStringNotContainsString('as $systemHotelId)', $method);
    }

    public function testMatrixRequiresExplicitStoredValueEvidenceLikeVerifier(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/Phase1EmployeeConsoleConcern.php'
        );
        $start = strpos($source, 'private function phase1P0TrafficFieldLoopMatrix(');
        $end = strpos($source, 'private function phase1P0TrafficFieldLoopMatrixIndex', is_int($start) ? $start : 0);

        self::assertIsInt($start);
        self::assertIsInt($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString("\$storedValuePresent = (\$fact['stored_value_present'] ?? null) === true;", $method);
        self::assertStringNotContainsString('phase1P0StoredValueState(', $method);
    }
}
