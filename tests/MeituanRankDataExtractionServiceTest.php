<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanRankDataExtractionService;
use PHPUnit\Framework\TestCase;

final class MeituanRankDataExtractionServiceTest extends TestCase
{
    public function testPersistenceExtractsPeerRankRowsWithSource(): void
    {
        $result = MeituanRankDataExtractionService::extractForPersistenceWithSource([
            'data' => [
                'peerRankData' => [
                    [
                        'dimName' => 'room nights',
                        'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                        'roundRanks' => [
                            ['poiId' => 8, 'poiName' => 'Meituan A', 'dataValue' => 9],
                        ],
                    ],
                ],
            ],
        ]);

        self::assertSame('data.peerRankData[].roundRanks', $result['source']);
        self::assertCount(1, $result['rows']);
        self::assertSame('room nights', $result['rows'][0]['_dimName']);
        self::assertSame('P_RZ_NIGHT_COUNT', $result['rows'][0]['_aiMetricName']);
        self::assertSame(8, $result['rows'][0]['poiId']);
    }

    public function testPersistenceKeepsExpandedDataBranchCompatibility(): void
    {
        $result = MeituanRankDataExtractionService::extractForPersistenceWithSource([
            'data' => [
                'first' => [
                    ['poiId' => 1, 'poiName' => 'A'],
                ],
                'second' => [
                    ['poiId' => 2, 'poiName' => 'B'],
                ],
            ],
        ]);

        self::assertSame('data.*', $result['source']);
        self::assertSame([1, 2], array_column($result['rows'], 'poiId'));
    }

    public function testDisplayExtractsNestedAndTopLevelPeerRankRows(): void
    {
        $nestedRows = MeituanRankDataExtractionService::extractForDisplay([
            'data' => [
                'data' => [
                    'peerRankData' => [
                        [
                            'dimName' => 'revenue',
                            'aiMetricName' => 'P_RZ_ROOM_PAY',
                            'roundRanks' => [['poiId' => 3, 'dataValue' => 600]],
                        ],
                    ],
                ],
            ],
        ]);
        $topLevelRows = MeituanRankDataExtractionService::extractForDisplay([
            'peerRankData' => [
                [
                    'dimName' => 'views',
                    'aiMetricName' => 'VIEW',
                    'roundRanks' => [['poiId' => 4, 'dataValue' => 120]],
                ],
            ],
        ]);

        self::assertSame(3, $nestedRows[0]['poiId']);
        self::assertSame('revenue', $nestedRows[0]['_dimName']);
        self::assertSame(4, $topLevelRows[0]['poiId']);
        self::assertSame('VIEW', $topLevelRows[0]['_aiMetricName']);
    }

    public function testDisplayDoesNotExpandAssociativeDataBranches(): void
    {
        $rows = MeituanRankDataExtractionService::extractForDisplay([
            'data' => [
                'first' => [
                    ['poiId' => 1, 'poiName' => 'A'],
                ],
            ],
        ]);

        self::assertSame([], $rows);
    }
}
