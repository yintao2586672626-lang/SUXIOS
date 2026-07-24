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

    public function testMissingCoreMetricsRemainNullAndPartialWhileRealZeroRemainsZero(): void
    {
        $missing = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'amount' => '--',
                'quantity' => null,
                'bookOrderNum' => 'not-a-number',
            ])
        );

        self::assertNull($missing['amount']);
        self::assertNull($missing['quantity']);
        self::assertNull($missing['book_order_num']);
        self::assertSame('partial', $missing['validation_status']);
        self::assertContains('field_missing:amount', $missing['validation_flag_codes']);
        self::assertContains('field_missing:quantity', $missing['validation_flag_codes']);
        self::assertContains('field_missing:book_order_num', $missing['validation_flag_codes']);

        $zero = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'amount' => 0,
                'quantity' => '0',
                'bookOrderNum' => 0,
            ])
        );

        self::assertSame(0.0, $zero['amount']);
        self::assertSame(0, $zero['quantity']);
        self::assertSame(0, $zero['book_order_num']);
        self::assertNotContains('field_missing:amount', $zero['validation_flag_codes']);
        self::assertNotContains('field_missing:quantity', $zero['validation_flag_codes']);
        self::assertNotContains('field_missing:book_order_num', $zero['validation_flag_codes']);
    }

    public function testMalformedPrimaryAliasesDoNotHideValidFallbackAliases(): void
    {
        $normalized = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'amount' => '--',
                'Amount' => '1280.5',
                'quantity' => 'bad',
                'Quantity' => '7',
                'bookOrderNum' => 'bad',
                'book_order_num' => '3',
            ])
        );

        self::assertSame(1280.5, $normalized['amount']);
        self::assertSame(7, $normalized['quantity']);
        self::assertSame(3, $normalized['book_order_num']);
        self::assertNotContains('field_missing:amount', $normalized['validation_flag_codes']);
        self::assertNotContains('field_missing:quantity', $normalized['validation_flag_codes']);
        self::assertNotContains('field_missing:book_order_num', $normalized['validation_flag_codes']);
    }

    public function testMalformedPrimaryScoreAliasDoesNotHideValidFallbackScore(): void
    {
        $normalized = CtripCompetitionCirclePersistenceService::normalizeRowSemantics(
            $this->competitionRow([
                'commentScore' => '--',
                'comment_score' => '4.6',
                'qunarCommentScore' => 7,
                'qunar_comment_score' => '4.8',
            ])
        );

        self::assertSame(4.6, $normalized['comment_score']);
        self::assertSame(4.8, $normalized['qunar_comment_score']);
    }

    public function testPersistenceReadbackRequiresIdentityTraceAndMetricsToMatch(): void
    {
        $expected = [
            11 => [
                'id' => 11,
                'tenant_id' => 44,
                'system_hotel_id' => 7,
                'hotel_id' => '832085',
                'data_date' => '2026-07-13',
                'source' => 'ctrip',
                'data_type' => 'competitor',
                'dimension' => 'competition_circle_hotel',
                'source_trace_id' => 'ctrip-cc:trace-a',
                'amount' => 1280.5,
                'quantity' => 7,
                'book_order_num' => 3,
                'comment_score' => null,
            ],
            12 => [
                'id' => 12,
                'tenant_id' => 44,
                'system_hotel_id' => 7,
                'hotel_id' => '688665',
                'data_date' => '2026-07-13',
                'source' => 'ctrip',
                'data_type' => 'competitor',
                'dimension' => 'competition_circle_hotel',
                'source_trace_id' => 'ctrip-cc:trace-a',
                'amount' => 980.0,
                'quantity' => 5,
                'book_order_num' => 2,
                'comment_score' => 4.6,
            ],
        ];
        $matchingRows = array_values($expected);

        $verified = (new CtripCompetitionCirclePersistenceService(
            static fn(array $_scope): array => $matchingRows
        ))->verifyPersistedRows($expected);
        self::assertTrue($verified['verified']);
        self::assertSame(2, $verified['matched_count']);
        self::assertSame([11, 12], $verified['row_ids']);
        self::assertCount(2, $verified['matched_rows']);

        $tenantMismatchRows = $matchingRows;
        $tenantMismatchRows[0]['tenant_id'] = 45;
        $tenantMismatch = (new CtripCompetitionCirclePersistenceService(
            static fn(array $_scope): array => $tenantMismatchRows
        ))->verifyPersistedRows($expected);
        self::assertFalse($tenantMismatch['verified']);
        self::assertSame(1, $tenantMismatch['matched_count']);

        $mismatchRows = $matchingRows;
        $mismatchRows[1]['amount'] = 0;
        $mismatch = (new CtripCompetitionCirclePersistenceService(
            static fn(array $_scope): array => $mismatchRows
        ))->verifyPersistedRows($expected);
        self::assertFalse($mismatch['verified']);
        self::assertSame(1, $mismatch['matched_count']);
        self::assertSame('database_readback_mismatch', $mismatch['reason']);

        $missingTrace = $expected;
        $missingTrace[11]['source_trace_id'] = '';
        $traceFailure = (new CtripCompetitionCirclePersistenceService(
            static fn(array $_scope): array => $matchingRows
        ))->verifyPersistedRows($missingTrace);
        self::assertFalse($traceFailure['verified']);
    }

    public function testPersistenceUsesCanonicalHotelTenantScope(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/CtripCompetitionCirclePersistenceService.php'
        );

        self::assertGreaterThanOrEqual(3, substr_count(
            $source,
            'OnlineDailyDataPersistenceService::resolveTenantIdForSystemHotel($systemHotelId)'
        ));
        self::assertStringNotContainsString("'tenant_id' => \$systemHotelId", $source);
    }

    public function testPersistenceReadbackUsesDatabaseDecimalPrecision(): void
    {
        $expected = [
            21 => [
                'id' => 21,
                'tenant_id' => 44,
                'system_hotel_id' => 7,
                'hotel_id' => '832085',
                'data_date' => '2026-07-13',
                'source' => 'ctrip',
                'data_type' => 'competitor',
                'dimension' => 'competition_circle_hotel',
                'source_trace_id' => 'ctrip-cc:precision',
                'amount' => 88.505,
                'quantity' => 7,
                'book_order_num' => 3,
                'comment_score' => 4.74,
                'qunar_comment_score' => 4.86,
            ],
        ];
        $stored = array_values($expected);
        $stored[0]['amount'] = '88.51';
        $stored[0]['comment_score'] = '4.7';
        $stored[0]['qunar_comment_score'] = '4.9';

        $result = (new CtripCompetitionCirclePersistenceService(
            static fn(array $_scope): array => $stored
        ))->verifyPersistedRows($expected);

        self::assertTrue($result['verified']);
        self::assertSame(1, $result['matched_count']);
    }

    public function testDuplicateHotelDateInputIsOnePersistenceLocator(): void
    {
        $method = new \ReflectionMethod(CtripCompetitionCirclePersistenceService::class, 'deduplicatePersistenceRows');
        $method->setAccessible(true);
        [$unique, $duplicateCount] = $method->invoke(null, [
            $this->competitionRow(['amount' => 100]),
            $this->competitionRow(['amount' => 200]),
        ], '2026-07-13', 7);

        self::assertCount(1, $unique);
        self::assertSame(1, $duplicateCount);
        self::assertSame(100, $unique[0]['amount']);
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
