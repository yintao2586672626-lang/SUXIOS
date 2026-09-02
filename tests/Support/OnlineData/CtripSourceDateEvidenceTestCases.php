<?php
declare(strict_types=1);

namespace Tests\Support\OnlineData;

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
}
