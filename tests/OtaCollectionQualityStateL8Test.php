<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCollectionQualityStateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtaCollectionQualityStateL8Test extends TestCase
{
    private const TARGET_DATE = '2026-07-14';
    private const STALE_DATA_DATE = '2026-07-13';

    private const REQUIRED_TRAFFIC_METRICS = [
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    private const DEFINED_QUALITY_STATES = [
        'available',
        'partial',
        'stale',
        'unverified',
        'binding_missing',
        'permission_denied',
        'collection_failed',
    ];

    /**
     * This is a service-boundary test. A restricted actor is represented by the
     * service input profile_status=permission_denied; it does not claim that an
     * HTTP authorization layer ran or returned 403.
     *
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc157L8VariantAppliesAllFourFactorsWithoutClaimingHttpAuthorization(
        string $caseId,
        array $factors,
        string $expectedState,
        string $expectedFlag
    ): void {
        $input = $this->inputForFactors($factors);
        $quality = (new OtaCollectionQualityStateService())->evaluate($input);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        self::assertContains($quality['primary_quality_state'], self::DEFINED_QUALITY_STATES, $message);
        self::assertSame($expectedState, $quality['primary_quality_state'], $message);
        if ($expectedFlag === '') {
            self::assertSame([], $quality['quality_flags'], $message);
        } else {
            self::assertContains($expectedFlag, $quality['quality_flags'], $message);
        }
        self::assertSame('ota_channel', $quality['metric_scope'], $message);

        $expectedProfileStatus = $factors['actor_scope'] === 'restricted'
            ? 'permission_denied'
            : 'logged_in';
        self::assertSame($expectedProfileStatus, $quality['evidence']['profile_status'], $message);

        $expectedMissingMetrics = $factors['data_completeness'] === 'missing_required' ? 1 : 0;
        self::assertSame($expectedMissingMetrics, $quality['evidence']['missing_traffic_metric_count'], $message);
        self::assertSame(
            count(self::REQUIRED_TRAFFIC_METRICS) - $expectedMissingMetrics,
            $quality['evidence']['verified_traffic_metric_count'],
            $message
        );

        $expectedDataAsOf = $factors['freshness'] === 'stale'
            ? self::STALE_DATA_DATE
            : self::TARGET_DATE;
        self::assertSame(self::TARGET_DATE, $quality['target_date'], $message);
        self::assertSame($expectedDataAsOf, $quality['data_as_of'], $message);

        $expectedCollectionStatus = $factors['upstream_state'] === 'failure'
            ? 'failed'
            : 'collected';
        self::assertSame($expectedCollectionStatus, $quality['evidence']['collection_status'], $message);
    }

    /**
     * @return array<string, array{
     *     0: string,
     *     1: array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string},
     *     2: string,
     *     3: string
     * }>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-1249 authorized complete fresh success' => [
                'DX-1249',
                self::factors('authorized', 'complete', 'fresh', 'success'),
                'available',
                '',
            ],
            'DX-1250 authorized complete stale failure' => [
                'DX-1250',
                self::factors('authorized', 'complete', 'stale', 'failure'),
                'collection_failed',
                'platform_response_invalid',
            ],
            'DX-1251 authorized missing fresh failure' => [
                'DX-1251',
                self::factors('authorized', 'missing_required', 'fresh', 'failure'),
                'collection_failed',
                'platform_response_invalid',
            ],
            'DX-1252 authorized missing stale success' => [
                'DX-1252',
                self::factors('authorized', 'missing_required', 'stale', 'success'),
                'unverified',
                'target_date_required_traffic_metrics_missing',
            ],
            'DX-1253 restricted complete fresh failure' => [
                'DX-1253',
                self::factors('restricted', 'complete', 'fresh', 'failure'),
                'permission_denied',
                'platform_permission_denied',
            ],
            'DX-1254 restricted complete stale success' => [
                'DX-1254',
                self::factors('restricted', 'complete', 'stale', 'success'),
                'permission_denied',
                'platform_permission_denied',
            ],
            'DX-1255 restricted missing fresh success' => [
                'DX-1255',
                self::factors('restricted', 'missing_required', 'fresh', 'success'),
                'permission_denied',
                'platform_permission_denied',
            ],
            'DX-1256 restricted missing stale failure' => [
                'DX-1256',
                self::factors('restricted', 'missing_required', 'stale', 'failure'),
                'permission_denied',
                'platform_permission_denied',
            ],
        ];
    }

    /**
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     * @return array<string, mixed>
     */
    private function inputForFactors(array $factors): array
    {
        $verifiedTrafficMetricKeys = self::REQUIRED_TRAFFIC_METRICS;
        if ($factors['data_completeness'] === 'missing_required') {
            array_pop($verifiedTrafficMetricKeys);
        }

        $input = [
            'binding_contract_status' => 'complete',
            'binding_check_status' => 'ok',
            'profile_status' => $factors['actor_scope'] === 'restricted'
                ? 'permission_denied'
                : 'logged_in',
            'collection_status' => $factors['upstream_state'] === 'failure'
                ? 'failed'
                : 'collected',
            'target_date' => self::TARGET_DATE,
            'latest_data_date' => $factors['freshness'] === 'stale'
                ? self::STALE_DATA_DATE
                : self::TARGET_DATE,
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'verified_traffic_metric_keys' => $verifiedTrafficMetricKeys,
            'has_stored_data' => true,
        ];

        if ($factors['upstream_state'] === 'failure') {
            $input['failure_reason'] = 'platform_response_invalid';
        }

        return $input;
    }

    /**
     * @return array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}
     */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}
