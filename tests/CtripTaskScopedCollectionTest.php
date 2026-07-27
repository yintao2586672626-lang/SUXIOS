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
