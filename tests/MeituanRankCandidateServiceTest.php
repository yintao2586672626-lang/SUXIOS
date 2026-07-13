<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanRankCandidateService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MeituanRankCandidateServiceTest extends TestCase
{
    public function testCandidateCommitIsBoundRecoverableAndIdempotent(): void
    {
        $store = [];
        $service = new MeituanRankCandidateService(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return $ttl === 300;
            },
            static function (string $key) use (&$store): bool {
                unset($store[$key]);
                return true;
            },
            static fn(): int => 1_000,
            static fn(): string => str_repeat('c', 32),
        );
        $binding = $this->binding();
        $payload = $this->completePayload($binding['poi_id']);

        $issued = $service->issue($binding, $payload);

        self::assertSame(str_repeat('c', 32), $issued['candidate_id']);
        self::assertSame(300, $issued['expires_in']);
        self::assertNull($service->beginCommit($issued['candidate_id'], [...$binding, 'system_hotel_id' => 59]));

        $started = $service->beginCommit($issued['candidate_id'], $binding);
        self::assertSame('started', $started['status'] ?? null);
        self::assertSame($payload, $started['payload'] ?? null);
        self::assertNull($service->beginCommit($issued['candidate_id'], $binding));

        self::assertTrue($service->releaseCommit($issued['candidate_id'], $binding));
        self::assertSame('started', $service->beginCommit($issued['candidate_id'], $binding)['status'] ?? null);
        $result = ['saved_count' => 20, 'persistence_status' => 'readback_verified'];
        self::assertTrue($service->completeCommit($issued['candidate_id'], $binding, $result));

        $replayed = $service->beginCommit($issued['candidate_id'], $binding);
        self::assertSame('committed', $replayed['status'] ?? null);
        self::assertSame($result, $replayed['result'] ?? null);
    }

    public function testCandidateRejectsIncompleteOrWrongPoiPayload(): void
    {
        $store = [];
        $service = new MeituanRankCandidateService(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? null;
            },
            static function (string $key, array $value, int $_ttl) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('d', 32),
        );
        $binding = $this->binding();

        $incomplete = $this->completePayload($binding['poi_id']);
        array_pop($incomplete['response_data']['data']['peerRankData']);
        try {
            $service->issue($binding, $incomplete);
            self::fail('Incomplete Meituan rank candidates must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('incomplete', $e->getMessage());
        }

        try {
            $service->issue($binding, $this->completePayload('wrong-poi'));
            self::fail('A Meituan rank candidate without the bound POI must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('POI', $e->getMessage());
        }

        $wrongDimensions = $this->completePayload($binding['poi_id']);
        foreach ($wrongDimensions['response_data']['data']['peerRankData'] as $index => &$dimension) {
            $dimension['dimName'] = '任意维度' . $index;
            $dimension['aiMetricName'] = 'UNKNOWN_' . $index;
        }
        unset($dimension);
        try {
            $service->issue($binding, $wrongDimensions);
            self::fail('Unexpected dimensions must not satisfy a Meituan rank candidate.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('expected dimensions', $e->getMessage());
        }
    }

    public function testHistoricalPercentOnlyCandidateUsesVerifiedSelfAnchors(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('e', 32),
        );
        $binding = [...$this->binding(), 'date_range' => '1'];
        $payload = $this->completePayload($binding['poi_id']);
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            foreach ($dimension['roundRanks'] as &$row) {
                $row['dataValue'] = null;
                $row['percent'] = 100;
            }
            unset($row);
        }
        unset($dimension);

        $issued = $service->issue($binding, $payload);

        self::assertSame('derived', $issued['value_mode']);
    }

    public function testHistoricalPercentOnlyCandidateRejectsMissingSelfAnchor(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('f', 32),
        );
        $binding = [...$this->binding(), 'date_range' => '7'];
        $payload = $this->completePayload($binding['poi_id']);
        unset($payload['self_metric_values']['roomRevenue']);
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            foreach ($dimension['roundRanks'] as &$row) {
                $row['dataValue'] = null;
                $row['percent'] = 100;
            }
            unset($row);
        }
        unset($dimension);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self metric anchor');
        $service->issue($binding, $payload);
    }

    public function testHistoricalMixedAbsoluteAndPercentRowsUseTargetPercentFromAbsoluteRow(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('b', 32),
        );
        $binding = [...$this->binding(), 'date_range' => '1'];
        $payload = $this->completePayload($binding['poi_id']);
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            $dimension['roundRanks'][0]['percent'] = 40;
            $dimension['roundRanks'][1]['dataValue'] = null;
            $dimension['roundRanks'][1]['percent'] = 60;
        }
        unset($dimension);

        $issued = $service->issue($binding, $payload);

        self::assertSame('derived', $issued['value_mode']);
    }

    public function testTodayStayPercentOnlyCandidateUsesVerifiedSelfAnchors(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('a', 32),
        );
        $binding = $this->binding();
        $payload = $this->completePayload($binding['poi_id']);
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            foreach ($dimension['roundRanks'] as &$row) {
                $row['dataValue'] = null;
                $row['percent'] = 100;
            }
            unset($row);
        }
        unset($dimension);

        $validation = $service->validatePayload($binding, $payload);
        $issued = $service->issue($binding, $payload);

        self::assertSame('derived', $validation['value_mode']);
        self::assertSame(2, $validation['derived_dimension_count']);
        self::assertSame(0, $validation['self_only_dimension_count']);
        self::assertSame('derived', $issued['value_mode']);
    }

    public function testTodayStayPercentOnlyCandidateRejectsMissingSelfAnchor(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('c', 32),
        );
        $binding = $this->binding();
        $payload = $this->completePayload($binding['poi_id']);
        unset($payload['self_metric_values']['roomRevenue']);
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            foreach ($dimension['roundRanks'] as &$row) {
                $row['dataValue'] = null;
                $row['percent'] = 100;
            }
            unset($row);
        }
        unset($dimension);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('self metric anchor and self percent');
        $service->issue($binding, $payload);
    }

    public function testTodaySalesPercentOnlyCandidateStillRequiresPlatformAbsoluteValues(): void
    {
        $service = new MeituanRankCandidateService(
            static fn(string $_key): mixed => null,
            static fn(string $_key, array $_value, int $_ttl): bool => true,
            static fn(string $_key): bool => true,
            static fn(): int => 1_000,
            static fn(): string => str_repeat('d', 32),
        );
        $binding = [...$this->binding(), 'rank_type' => 'P_XS'];
        $payload = $this->completePayload($binding['poi_id']);
        $payload['response_data']['data']['peerRankData'][0]['dimName'] = '销售间夜';
        $payload['response_data']['data']['peerRankData'][0]['aiMetricName'] = 'P_XS_NIGHT_COUNT';
        $payload['response_data']['data']['peerRankData'][1]['dimName'] = '销售额';
        $payload['response_data']['data']['peerRankData'][1]['aiMetricName'] = 'P_XS_REVENUE';
        $payload['self_metric_values'] = ['salesRoomNights' => 3, 'sales' => 680];
        foreach ($payload['response_data']['data']['peerRankData'] as &$dimension) {
            foreach ($dimension['roundRanks'] as &$row) {
                $row['dataValue'] = null;
                $row['percent'] = 100;
            }
            unset($row);
        }
        unset($dimension);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute values are missing');
        $service->issue($binding, $payload);
    }

    public function testManualFetchExposesDedicatedCandidateCommitContract(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        $concern = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/OnlineDataManualFetchConcern.php');

        self::assertStringContainsString(
            "Route::post('/meituan/rank-candidates/commit', 'OnlineData/commitMeituanRankCandidate')",
            $routes
        );
        self::assertStringContainsString('MeituanRankCandidateService', $concern);
        self::assertStringContainsString("'rank_candidate' =>", $concern);
        self::assertStringContainsString('public function commitMeituanRankCandidate(): Response', $concern);
        self::assertMatchesRegularExpression('/->beginCommit\([^;]+\).*parseAndSaveMeituanData/s', $concern);
        self::assertStringContainsString('completeCommit', $concern);
        self::assertStringContainsString('releaseCommit', $concern);
        self::assertStringContainsString('verifyPersistedRankCandidate', $concern);
        self::assertStringContainsString("'persistence_status' => 'readback_verified'", $concern);
        self::assertStringContainsString("'database_readback' =>", $concern);
    }

    /** @return array<string, mixed> */
    private function binding(): array
    {
        return [
            'actor_id' => 9,
            'config_id' => 'meituan-58',
            'system_hotel_id' => 58,
            'poi_id' => '1022727174',
            'start_date' => '2026-07-12',
            'end_date' => '2026-07-12',
            'date_range' => '0',
            'rank_type' => 'P_RZ',
        ];
    }

    /** @return array<string, mixed> */
    private function completePayload(string $poiId): array
    {
        return [
            'response_data' => [
                'data' => [
                    'peerRankData' => [
                        [
                            'dimName' => '入住间夜',
                            'aiMetricName' => 'P_RZ_NIGHT_COUNT',
                            'roundRanks' => [
                                ['poiId' => $poiId, 'poiName' => '目标酒店', 'dataValue' => 3],
                                ['poiId' => 'competitor-1', 'poiName' => '竞店一', 'dataValue' => 5],
                            ],
                        ],
                        [
                            'dimName' => '房费收入',
                            'aiMetricName' => 'P_RZ_REVENUE',
                            'roundRanks' => [
                                ['poiId' => $poiId, 'poiName' => '目标酒店', 'dataValue' => 680],
                                ['poiId' => 'competitor-1', 'poiName' => '竞店一', 'dataValue' => 920],
                            ],
                        ],
                    ],
                ],
            ],
            'self_metric_values' => ['roomNights' => 3, 'roomRevenue' => 680],
            'self_metric_status' => 'trade_returned',
        ];
    }
}
