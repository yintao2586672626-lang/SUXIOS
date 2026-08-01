<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class CtripTaskScopedCollectionTest extends TestCase
{
    use ReflectionHelper;

    public function testOrdinaryTaskContractRejectsRawEndpointMaterial(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        self::assertTrue($this->invokeNonPublic($controller, 'isTaskScopedCtripCookieApiRequest', [[
            'config_id' => 'ctrip_7',
            'system_hotel_id' => 7,
            'ctrip_hotel_id' => '9988',
            'data_date' => '2026-07-26',
            'request_source' => 'competition_circle',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isTaskScopedCtripCookieApiRequest', [[
            'config_id' => 'ctrip_7',
            'system_hotel_id' => 7,
            'request_source' => 'competition_circle',
            'request_url' => 'https://example.invalid/private',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isTaskScopedCtripCookieApiRequest', [[
            'config_id' => 'ctrip_7',
            'system_hotel_id' => 7,
            'request_source' => 'unknown_task',
        ]]));
    }

    public function testCompetitionCircleTaskExpandsOnlyOnServer(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $endpoints = $this->invokeNonPublic($controller, 'buildCtripCookieApiPresetEndpoints', [
            'competition_circle',
            '',
            '2026-07-26',
            '9988',
        ]);

        self::assertCount(4, $endpoints);
        self::assertSame(
            ['competitor_overview', 'competitor_overview', 'loss_analysis', 'competitor_rank'],
            array_column($endpoints, 'section')
        );
        foreach ($endpoints as $endpoint) {
            self::assertSame('POST', $endpoint['method']);
            self::assertSame('9988', $endpoint['payload']['hotelId']);
            self::assertSame('2026-07-26', $endpoint['payload']['startDate']);
            self::assertSame('2026-07-26', $endpoint['payload']['endDate']);
        }
    }

    public function testCompetitionCircleCoverageRequiresEveryRequestToReturnUsableRows(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $completeResponses = [];
        foreach (range(1, 4) as $index) {
            $completeResponses[] = [
                'status' => 200,
                'endpoint_id' => 'endpoint_' . $index,
                'catalog_fact_count' => 1,
                'standard_row_count' => 1,
            ];
        }

        $complete = $this->invokeNonPublic($controller, 'buildCtripCookieApiRequestCoverage', [[
            'responses' => $completeResponses,
            'errors' => [],
        ], 4]);
        self::assertTrue($complete['request_complete']);
        self::assertSame(4, $complete['successful_request_count']);
        self::assertSame(4, $complete['usable_request_count']);
        self::assertSame(0, $complete['request_gap_count']);

        $partial = $this->invokeNonPublic($controller, 'buildCtripCookieApiRequestCoverage', [[
            'responses' => array_slice($completeResponses, 0, 3),
            'errors' => [['error' => 'request_failed']],
        ], 4]);
        self::assertFalse($partial['request_complete']);
        self::assertSame(3, $partial['successful_request_count']);
        self::assertSame(3, $partial['usable_request_count']);
        self::assertSame(1, $partial['request_gap_count']);
    }

    public function testCompetitionCircleCanUseVerifiedStoredIdentityWhenResponseOmitsHotelId(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $identityCheck = [
            'ok' => true,
            'status' => 'no_platform_hotel_id',
            'captured_hotel_ids' => [],
            'expected_hotel_ids' => ['9988'],
        ];
        $storedConfig = [
            'system_hotel_id' => 7,
            'platform_hotel_id' => '9988',
        ];

        self::assertTrue($this->invokeNonPublic($controller, 'canUseStoredCtripCompetitionIdentity', [
            'competition_circle',
            $identityCheck,
            $storedConfig,
            7,
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'canUseStoredCtripCompetitionIdentity', [
            'revenue_overview',
            $identityCheck,
            $storedConfig,
            7,
        ]));

        $identityCheck['expected_hotel_ids'] = ['8877'];
        self::assertFalse($this->invokeNonPublic($controller, 'canUseStoredCtripCompetitionIdentity', [
            'competition_circle',
            $identityCheck,
            $storedConfig,
            7,
        ]));

        $identityCheck['expected_hotel_ids'] = ['9988'];
        $identityCheck['captured_hotel_ids'] = ['8877'];
        self::assertFalse($this->invokeNonPublic($controller, 'canUseStoredCtripCompetitionIdentity', [
            'competition_circle',
            $identityCheck,
            $storedConfig,
            7,
        ]));

        $source = file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataRequestConcern.php');
        self::assertIsString($source);
        self::assertStringContainsString(
            '$this->canUseStoredCtripCompetitionIdentity(',
            $source
        );
        self::assertStringContainsString(
            "'verified_stored_config_request_binding'",
            $source
        );
    }

    public function testOtherBusinessTaskCodesStillResolve(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();

        $expectedCounts = [
            'revenue_overview' => 4,
            'traffic_report' => 7,
            'quality_psi' => 1,
            'ads_pyramid' => 1,
        ];
        foreach ($expectedCounts as $task => $expectedCount) {
            $endpoints = $this->invokeNonPublic($controller, 'buildCtripCookieApiPresetEndpoints', [
                $task,
                'SPIDERKEY',
                '2026-07-26',
                '9988',
            ]);
            self::assertCount($expectedCount, $endpoints, $task);
        }
    }
}
