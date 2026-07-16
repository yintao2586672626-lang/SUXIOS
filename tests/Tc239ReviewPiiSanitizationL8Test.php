<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Tc239ReviewPiiSanitizationL8Test extends TestCase
{
    private const TARGET_DATE = '2026-07-15';
    private const STALE_DATE = '2026-07-01';
    private const REVIEWER_NAME = 'TC239_ZHANG_SAN';
    private const FORMATTED_PHONE = '+86 138-0013-8000';
    private const EMAIL = 'guest239@example.com';
    private const ID_CARD = '110101199001012391';

    /**
     * TC-239 binds directly to the production normalization and review-storage
     * sanitization boundary. Fixtures are local arrays: no database, OTA login,
     * network request, or production state is used.
     *
     * @param array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc239ReviewPiiSanitizationAndQualityGuards(
        string $caseId,
        array $factors
    ): void {
        $payload = $this->payloadForFactors($factors);
        $source = $this->sourceForFactors($factors);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        $this->assertFixtureFactorsAreExplicit($payload, $source, $factors, $message);

        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($payload, $source, 239);

        if ($factors['actor_review_scope'] === 'restricted') {
            self::assertSame([], $rows, $message . ' restricted review detail must not cross the allow_review guard');
            return;
        }

        $inputReady = $factors['data_completeness'] === 'complete'
            && $factors['freshness'] === 'fresh'
            && $factors['upstream_state'] === 'success';
        if (!$inputReady) {
            $this->assertBlockedOrExplicitlyQuarantined($rows, $message);
            $this->assertPiiDoesNotReachNormalizedStorage($rows, $message);
            return;
        }

        self::assertCount(1, $rows, $message . ' authorized complete fresh success fixture should normalize once');
        self::assertSame('review', $rows[0]['data_type'], $message);
        self::assertSame(4.7, $rows[0]['comment_score'], $message);
        self::assertSame(9, $rows[0]['quantity'], $message);
        self::assertSame('normal', $rows[0]['validation_status'], $message);

        $this->assertPiiDoesNotReachNormalizedStorage($rows, $message);

        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('quiet_room', $raw['row']['tags'][0] ?? null, $message . ' safe review tag should be preserved');
        self::assertSame(
            'service_positive',
            $raw['row']['labels']['public']['value'] ?? null,
            $message . ' safe nested review label should be preserved'
        );
    }

    /**
     * @return array<string, array{0:string,1:array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-1905 authorized complete fresh success' => ['DX-1905', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-1906 authorized complete stale failure' => ['DX-1906', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-1907 authorized missing fresh failure' => ['DX-1907', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-1908 authorized missing stale success' => ['DX-1908', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-1909 restricted complete fresh failure' => ['DX-1909', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-1910 restricted complete stale success' => ['DX-1910', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-1911 restricted missing fresh success' => ['DX-1911', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-1912 restricted missing stale failure' => ['DX-1912', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /**
     * @param array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function payloadForFactors(array $factors): array
    {
        $row = [
            'hotel_id' => 'ctrip-tc239-fixture',
            'hotel_name' => 'TC239 Local Fixture Hotel',
            'data_date' => $factors['freshness'] === 'fresh' ? self::TARGET_DATE : self::STALE_DATE,
            'score' => 4.7,
            'review_count' => 9,
            'reviewer_name' => self::REVIEWER_NAME,
            'content' => sprintf(
                '姓名 %s，联系电话 %s，邮箱 %s，证件号 %s；房间安静。',
                self::REVIEWER_NAME,
                self::FORMATTED_PHONE,
                self::EMAIL,
                self::ID_CARD
            ),
            'tags' => [
                'quiet_room',
                'guest_name:' . self::REVIEWER_NAME,
                'guest_phone:' . self::FORMATTED_PHONE,
            ],
            'labels' => [
                'public' => ['value' => 'service_positive'],
                'contact' => [
                    'email_value' => self::EMAIL,
                    'identity_value' => self::ID_CARD,
                ],
            ],
        ];
        if ($factors['data_completeness'] === 'missing_required') {
            unset($row['score']);
        }

        return [
            'review_detail_collection' => true,
            'target_date' => self::TARGET_DATE,
            'collection_status' => $factors['upstream_state'],
            'rows' => [$row],
        ];
    }

    /**
     * @param array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function sourceForFactors(array $factors): array
    {
        return [
            'id' => 239,
            'name' => 'TC239 local review source',
            'platform' => 'ctrip',
            'data_type' => 'review',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'manual',
            'config' => [
                'allow_review' => $factors['actor_review_scope'] === 'authorized',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $source
     * @param array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureFactorsAreExplicit(
        array $payload,
        array $source,
        array $factors,
        string $message
    ): void {
        self::assertSame(
            $factors['actor_review_scope'] === 'authorized',
            $source['config']['allow_review'],
            $message . ' actor/allow_review factor'
        );
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('score', $payload['rows'][0]),
            $message . ' score completeness factor'
        );
        self::assertSame(
            $factors['freshness'] === 'fresh' ? self::TARGET_DATE : self::STALE_DATE,
            $payload['rows'][0]['data_date'],
            $message . ' data_date freshness factor'
        );
        self::assertSame(self::TARGET_DATE, $payload['target_date'], $message . ' fixed target date');
        self::assertSame($factors['upstream_state'], $payload['collection_status'], $message . ' upstream factor');
    }

    /**
     * Invalid-but-present upstream rows may be rejected or explicitly retained
     * as quarantined evidence. They must never become a normal row silently.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function assertBlockedOrExplicitlyQuarantined(array $rows, string $message): void
    {
        if ($rows === []) {
            self::assertSame([], $rows, $message . ' invalid review input was blocked');
            return;
        }

        foreach ($rows as $row) {
            self::assertNotSame(
                'normal',
                strtolower(trim((string)($row['validation_status'] ?? ''))),
                $message . ' stale, failed, or incomplete review input must not be silently normalized'
            );
            $flags = json_decode((string)($row['validation_flags'] ?? '[]'), true);
            self::assertIsArray($flags, $message . ' quarantine flags must be JSON');
            self::assertNotEmpty($flags, $message . ' quarantined review input must expose a reason');
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function assertPiiDoesNotReachNormalizedStorage(array $rows, string $message): void
    {
        $stored = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ([
            'reviewer_name' => self::REVIEWER_NAME,
            'formatted_phone' => self::FORMATTED_PHONE,
            'email' => self::EMAIL,
            'id_card' => self::ID_CARD,
        ] as $kind => $privateValue) {
            self::assertStringNotContainsString(
                $privateValue,
                $stored,
                $message . ' ' . $kind . ' must not reach raw_data, dimension, tags, labels, or normalized fields'
            );
        }
    }

    /** @return array{actor_review_scope:string,data_completeness:string,freshness:string,upstream_state:string} */
    private static function factors(
        string $actorReviewScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_review_scope' => $actorReviewScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}
