<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCollectionQualityStateService;
use PHPUnit\Framework\TestCase;

final class OtaCollectionQualityStateServiceTest extends TestCase
{
    public function testBindingMissingHasHighestPrecedence(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'incomplete',
            'profile_status' => 'permission_denied',
            'collection_status' => 'failed',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-01',
        ]);

        self::assertSame('binding_missing', $quality['primary_quality_state']);
        self::assertContains('binding_incomplete', $quality['quality_flags']);
    }

    public function testIncompleteBindingContractIsBindingMissingEvenWhenRequirementsAreNotEnumerated(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_contract_status' => 'incomplete',
            'binding_check_status' => 'ok',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'has_stored_data' => true,
        ]);

        self::assertSame('binding_missing', $quality['primary_quality_state']);
    }

    public function testPermissionDeniedIsExplicitWhenBindingIsComplete(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'permission_denied',
            'collection_status' => 'not_collected',
            'target_date' => '2026-07-09',
        ]);

        self::assertSame('permission_denied', $quality['primary_quality_state']);
        self::assertContains('platform_permission_denied', $quality['quality_flags']);
    }

    public function testCollectionFailureIsExplicitWhenLatestTaskFailed(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'failed',
            'target_date' => '2026-07-09',
            'failure_reason' => 'sync_completed_without_saved_rows',
        ]);

        self::assertSame('collection_failed', $quality['primary_quality_state']);
        self::assertContains('sync_completed_without_saved_rows', $quality['quality_flags']);
    }

    public function testUnverifiedDoesNotTreatTargetDateRowsWithoutFieldFactsAsAvailable(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_rows' => 2,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'missing',
            'has_stored_data' => true,
        ]);

        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertContains('target_date_field_facts_missing', $quality['quality_flags']);
    }

    public function testStaleDoesNotTreatHistoricalRowsAsCurrentData(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'stale',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-07',
            'has_stored_data' => true,
        ]);

        self::assertSame('stale', $quality['primary_quality_state']);
        self::assertContains('target_date_not_current', $quality['quality_flags']);
    }

    public function testUnverifiedEvidenceGapTakesPrecedenceOverStaleData(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'stale',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-07',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'missing',
            'has_stored_data' => true,
        ]);

        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertContains('target_date_field_facts_missing', $quality['quality_flags']);
    }

    public function testPartialRetainsVerifiedSubsetWithoutClaimingClosure(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'partial',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_rows' => 2,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'partial',
            'has_stored_data' => true,
        ]);

        self::assertSame('partial', $quality['primary_quality_state']);
        self::assertContains('target_date_field_facts_partial', $quality['quality_flags']);
    }

    public function testAvailableRequiresVerifiedBindingTargetDateTrafficAndFieldFacts(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'latest_collected_at' => '2026-07-10 08:20:00',
            'target_date_rows' => 3,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'verified_traffic_metric_keys' => $this->requiredTrafficMetrics(),
            'has_stored_data' => true,
        ]);

        self::assertSame('available', $quality['primary_quality_state']);
        self::assertSame('ota_channel', $quality['metric_scope']);
        self::assertSame('2026-07-09', $quality['data_as_of']);
        self::assertSame('2026-07-10 08:20:00', $quality['collected_at']);
        self::assertSame([], $quality['quality_flags']);
    }

    public function testReadyLabelWithoutAllFiveCanonicalTrafficMetricsIsNotAvailable(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_contract_status' => 'complete',
            'binding_check_status' => 'ok',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'verified_traffic_metric_keys' => ['list_exposure'],
            'has_stored_data' => true,
        ]);

        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertContains('target_date_required_traffic_metrics_missing', $quality['quality_flags']);
        self::assertSame(1, $quality['evidence']['verified_traffic_metric_count']);
        self::assertSame(4, $quality['evidence']['missing_traffic_metric_count']);
    }

    public function testBrowserProfileCannotBeAvailableWithoutCurrentSameSourceProof(): void
    {
        $input = [
            'binding_contract_status' => 'complete',
            'binding_check_status' => 'ok',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'verified_traffic_metric_keys' => $this->requiredTrafficMetrics(),
            'profile_session_proof_required' => true,
            'profile_session_verified' => false,
            'profile_session_same_source' => false,
            'has_stored_data' => true,
        ];

        $quality = (new OtaCollectionQualityStateService())->evaluate($input);

        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertContains('current_session_proof_missing', $quality['quality_flags']);
        self::assertFalse($quality['evidence']['profile_session_verified']);

        $input['profile_session_verified'] = true;
        $input['profile_session_same_source'] = true;
        $verified = (new OtaCollectionQualityStateService())->evaluate($input);
        self::assertSame('available', $verified['primary_quality_state']);
    }

    public function testInvalidOrFutureTargetDateFailsClosed(): void
    {
        foreach (['2026-02-31', '2999-01-01', '2026/07/09'] as $targetDate) {
            $quality = (new OtaCollectionQualityStateService())->evaluate([
                'binding_contract_status' => 'complete',
                'binding_check_status' => 'ok',
                'profile_status' => 'logged_in',
                'collection_status' => 'collected',
                'target_date' => $targetDate,
                'latest_data_date' => '2026-07-09',
                'target_date_rows' => 1,
                'target_date_traffic_rows' => 1,
                'field_fact_status' => 'ready',
                'verified_traffic_metric_keys' => $this->requiredTrafficMetrics(),
                'has_stored_data' => true,
            ]);

            self::assertSame('unverified', $quality['primary_quality_state'], $targetDate);
            self::assertContains('target_date_invalid', $quality['quality_flags'], $targetDate);
            self::assertSame('', $quality['target_date'], $targetDate);
        }
    }

    public function testOutputNeverExposesRawPayloadOrCredentials(): void
    {
        $quality = (new OtaCollectionQualityStateService())->evaluate([
            'binding_check_status' => 'complete',
            'profile_status' => 'logged_in',
            'collection_status' => 'collected',
            'target_date' => '2026-07-09',
            'latest_data_date' => '2026-07-09',
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'raw_payload' => ['cookie' => 'secret-cookie', 'token' => 'secret-token'],
            'password' => 'secret-password',
        ]);

        $encoded = json_encode($quality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString('secret-cookie', (string)$encoded);
        self::assertStringNotContainsString('secret-token', (string)$encoded);
        self::assertStringNotContainsString('secret-password', (string)$encoded);
        self::assertStringNotContainsString('raw_payload', (string)$encoded);
        self::assertStringNotContainsString('password', strtolower((string)$encoded));
    }

    /** @return array<int, string> */
    private function requiredTrafficMetrics(): array
    {
        return [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
    }
}
