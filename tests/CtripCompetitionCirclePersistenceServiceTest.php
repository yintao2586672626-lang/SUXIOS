<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripCompetitionCirclePersistenceService;
use PHPUnit\Framework\TestCase;

final class CtripCompetitionCirclePersistenceServiceTest extends TestCase
{
    private function competitionRow(array $overrides = []): array
    {
        return array_replace([
            'hotelId' => 832085,
            'hotelName' => '我的酒店',
            'amount' => 1280.5,
            'quantity' => 10,
            'bookOrderNum' => 8,
            'commentScore' => 4.7,
            'qunarCommentScore' => 4.9,
            'amountRank' => 2,
            'quantityRank' => 3,
            'commentScoreRank' => 1,
        ], $overrides);
    }

    public function testCompetitionSignatureExcludesOrdinaryBusinessRows(): void
    {
        self::assertTrue(CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature(
            $this->competitionRow()
        ));
        self::assertFalse(CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature([
            'hotelId' => 832085,
            'hotelName' => '巢湖测试',
            'amount' => 1280.5,
            'quantity' => 10,
            'bookOrderNum' => 8,
        ]));
    }

    public function testSystemHotelOwnsCircleWhilePlatformHotelIdDeterminesRole(): void
    {
        $self = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow(),
            ['self_hotel_ids' => ['832085']]
        );
        $competitor = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'hotelId' => 688665,
                'hotelName' => '巢湖碧桂园凤悦凤凰酒店',
            ]),
            ['self_hotel_ids' => ['832085']]
        );

        self::assertSame('competitor', $self['data_type']);
        self::assertSame('competition_circle_hotel', $self['dimension']);
        self::assertSame('self', $self['compare_type']);
        self::assertSame('competitor', $competitor['compare_type']);
    }

    public function testExplicitRawSelfMarkerCannotOverrideConfiguredPlatformHotelId(): void
    {
        $normalized = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'hotelId' => 120819980,
                'hotelName' => '我的酒店',
            ]),
            ['self_hotel_ids' => ['120820008']]
        );

        self::assertSame('competitor', $normalized['compare_type']);
    }

    public function testMissingQunarScoreIsNullAndPartialInsteadOfNormalZero(): void
    {
        $normalized = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow(['qunarCommentScore' => 0]),
            ['self_hotel_ids' => ['832085']]
        );

        self::assertNull($normalized['qunar_comment_score']);
        self::assertSame('partial', $normalized['validation_status']);
        self::assertContains('field_missing:qunar_comment_score', $normalized['validation_flag_codes']);
    }

    public function testMissingCtripScoreIsNullAndPartialInsteadOfNormalZero(): void
    {
        $normalized = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow(['commentScore' => 0]),
            ['self_hotel_ids' => ['832085']]
        );

        self::assertNull($normalized['comment_score']);
        self::assertSame('partial', $normalized['validation_status']);
        self::assertContains('field_missing:comment_score', $normalized['validation_flag_codes']);
    }

    public function testLegacyBackfillTraceIsMigrationEvidenceNotPlatformProof(): void
    {
        $fields = CtripCompetitionCirclePersistenceService::buildLegacyBackfillFields(
            [
                'id' => 31947,
                'system_hotel_id' => 7,
                'hotel_id' => '832085',
                'data_date' => '2026-07-10',
                'raw_data' => json_encode($this->competitionRow(), JSON_UNESCAPED_UNICODE),
                'update_time' => '2026-07-11 15:43:35',
            ],
            ['832085']
        );

        self::assertStringStartsWith('legacy_backfill:', $fields['source_trace_id']);
        self::assertSame('unverified', $fields['validation_status']);
        self::assertContains('historical_source_trace_unavailable', $fields['validation_flag_codes']);
        self::assertSame('self', $fields['compare_type']);
        self::assertSame('2026-07-11 15:43:35', $fields['snapshot_time']);
    }

    public function testWeakLegacyParserCannotOverwriteACompleteCapturedRow(): void
    {
        self::assertTrue(CtripCompetitionCirclePersistenceService::shouldPreserveExistingEvidence(
            [
                'data_source_id' => 71,
                'sync_task_id' => 226,
                'source_trace_id' => 'ctrip-cc:captured',
            ],
            [
                'data_source_id' => 0,
                'sync_task_id' => 0,
                'source_trace_id' => 'ctrip-cc:legacy-parser',
                'ingestion_method' => 'legacy_parser',
            ]
        ));

        self::assertFalse(CtripCompetitionCirclePersistenceService::shouldPreserveExistingEvidence(
            [
                'data_source_id' => 71,
                'sync_task_id' => 226,
                'source_trace_id' => 'ctrip-cc:captured',
            ],
            [
                'data_source_id' => 72,
                'sync_task_id' => 227,
                'source_trace_id' => 'ctrip-cc:new-capture',
                'ingestion_method' => 'manual_cookie_api',
            ]
        ));
    }
}
