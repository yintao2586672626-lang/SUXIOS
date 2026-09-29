<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

use app\controller\OnlineData;
use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripTrafficDisplayService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\OnlineDataQuerySpy;
use Tests\Support\ReflectionHelper;
use think\App;

use app\service\CtripCompetitionCirclePersistenceService;

trait CtripSourceDateEvidenceTestCases
{
    public function testCtripRankingCacheRequiresTrustedTodayDatabaseReadback(): void
    {
        $controller = $this->controller();
        $trustedRow = [
            'status' => 'success',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'system_hotel_id' => 80,
            'platform' => 'Ctrip',
            'hotel_id' => '122476915',
            'data_date' => '2026-08-03',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
            'snapshot_time' => '2026-08-04 09:12:00',
            'amount' => 1888,
            'raw_data' => json_encode([
                'hotelId' => '122476915',
                'hotelName' => '当前酒店',
            ], JSON_UNESCAPED_UNICODE),
        ];

        $storageProof = $this->invokeNonPublic($controller, 'buildCtripLatestStorageProof', [[$trustedRow]]);
        self::assertTrue($storageProof['readback_verified']);
        self::assertTrue($storageProof['source_verified']);

        $manualCookieRow = array_merge($trustedRow, [
            'ingestion_method' => CtripCompetitionCirclePersistenceService::INGESTION_METHOD,
            'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
            'dimension' => CtripCompetitionCirclePersistenceService::DIMENSION,
        ]);
        $manualWithoutDateEvidence = $this->invokeNonPublic(
            $controller,
            'buildCtripLatestStorageProof',
            [[$manualCookieRow]]
        );
        self::assertTrue($manualWithoutDateEvidence['readback_verified']);
        self::assertFalse($manualWithoutDateEvidence['source_verified']);
        $unverifiedMetadata = $this->invokeNonPublic($controller, 'buildCtripLatestMetadata', [[
            'rank' => [
                'total' => 1,
                'fetched_at' => '2026-08-04 09:12:00',
                'data_date' => '2026-08-03',
                'target_data_date' => '2026-08-03',
                'request_date' => '2026-08-03',
                'source_business_date' => '',
                'response_date_status' => 'target_date_unverified',
                'cache_eligible' => false,
                'cache_reason' => 'source_verification_incomplete',
            ],
        ], '80', '2026-08-03']);
        self::assertSame('source_unverified', $unverifiedMetadata['status']);
        self::assertSame('2026-08-03', $unverifiedMetadata['request_date']);
        self::assertSame('', $unverifiedMetadata['source_business_date']);
        self::assertSame('target_date_unverified', $unverifiedMetadata['response_date_status']);
        self::assertFalse($unverifiedMetadata['ranking_cache_eligible']);

        $manualRaw = json_decode((string)$manualCookieRow['raw_data'], true);
        $manualRaw['_suxi_source_evidence'] = [
            'schema' => CtripCompetitionCirclePersistenceService::DATE_EVIDENCE_SCHEMA,
            'status' => 'verified',
            'endpoint_id' => CtripCompetitionCirclePersistenceService::ENDPOINT_ID,
            'request_date' => '2026-08-03',
            'response_dates' => ['2026-08-03'],
            'response_date_evidence' => [[
                'path' => 'dataDate',
                'date' => '2026-08-03',
            ]],
            'resolved_business_date' => '2026-08-03',
            'captured_at' => '2026-08-04 09:12:00',
        ];
        $manualCookieRow['raw_data'] = json_encode($manualRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $manualWithDateEvidence = $this->invokeNonPublic(
            $controller,
            'buildCtripLatestStorageProof',
            [[$manualCookieRow]]
        );
        self::assertTrue($manualWithDateEvidence['source_verified']);

        $cache = $this->invokeNonPublic($controller, 'buildCtripRankingCachePolicy', [
            $storageProof,
            [
                'data_date' => '2026-08-03',
                'target_data_date' => '2026-08-03',
                'fetched_at' => '2026-08-04 09:12:00',
                'today' => '2026-08-04',
                'identity_check' => ['ok' => true],
                'display_hotels' => [['hotelId' => '122476915']],
                'traffic_fallback' => null,
            ],
        ]);
        self::assertTrue($cache['eligible']);
        self::assertSame('trusted_today_snapshot', $cache['reason']);

        $stale = $this->invokeNonPublic($controller, 'buildCtripRankingCachePolicy', [
            $storageProof,
            [
                'data_date' => '2026-08-03',
                'target_data_date' => '2026-08-03',
                'fetched_at' => '2026-08-03 22:00:00',
                'today' => '2026-08-04',
                'identity_check' => ['ok' => true],
                'display_hotels' => [['hotelId' => '122476915']],
                'traffic_fallback' => null,
            ],
        ]);
        self::assertFalse($stale['eligible']);
        self::assertSame('not_collected_today', $stale['reason']);

        $unreadRow = $trustedRow;
        $unreadRow['readback_verified'] = 0;
        $unreadProof = $this->invokeNonPublic($controller, 'buildCtripLatestStorageProof', [[$unreadRow]]);
        self::assertFalse($unreadProof['readback_verified']);
        self::assertFalse($unreadProof['source_verified']);
    }

    public function testCtripSearchOpportunityDoesNotPromoteUnchangedCumulativeSnapshotsAsZeroYesterdayFacts(): void
    {
        $controller = $this->controller();
        $makeRow = static function (string $dataDate, string $scope): array {
            return [
                'data_date' => $dataDate,
                'compare_type' => $scope === 'self' ? 'self' : 'competitor',
                'ingestion_method' => 'ctrip_cookie_api',
                'raw_data' => json_encode([
                    'endpoint_id' => 'traffic_search_details',
                    'dimension_values' => [
                        'target_date' => '2026-07-11',
                        'search_window' => 'cumulative',
                        'compare_scope' => $scope,
                    ],
                    'metrics' => [
                        'future_search_pv' => 100,
                        'future_search_uv' => 80,
                        'future_search_order_count' => null,
                        'future_search_conversion_rate' => 2.0,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        };

        $payload = $this->invokeNonPublic($controller, 'buildCtripSearchOpportunityPayload', [
            [$makeRow('2026-07-12', 'self'), $makeRow('2026-07-12', 'competitor_avg')],
            '2026-07-12',
            [$makeRow('2026-07-11', 'self'), $makeRow('2026-07-11', 'competitor_avg')],
            '2026-07-11',
        ]);

        self::assertArrayNotHasKey('yesterday', $payload['dates'][0]);
    }

    public function testCtripSearchOpportunityDateValidationRejectsEmptyAggregateSentinel(): void
    {
        $controller = $this->controller();

        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['0']));
        self::assertFalse($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['']));
        self::assertTrue($this->invokeNonPublic($controller, 'isCtripSearchOpportunityDate', ['2026-07-11']));
    }

    public function testCtripSearchOpportunityLatestDateKeepsTheFullDateString(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-11';

        $latestDate = $this->invokeNonPublic($controller, 'resolveLatestCtripSearchOpportunityDate', [$query]);

        self::assertSame('2026-07-11', $latestDate);
        self::assertSame([
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }

    public function testCtripSearchOpportunityPreviousDateUsesTheLatestEarlierCapture(): void
    {
        $controller = $this->controller();
        $query = new OnlineDataQuerySpy();
        $query->valueResult = '2026-07-10';

        $previousDate = $this->invokeNonPublic($controller, 'resolvePreviousCtripSearchOpportunityDate', [
            $query,
            '2026-07-11',
        ]);

        self::assertSame('2026-07-10', $previousDate);
        self::assertSame([
            ['where', 'data_date', '<', '2026-07-11'],
            ['order', 'data_date', 'desc'],
            ['value', 'data_date'],
        ], $query->calls);
    }
}
