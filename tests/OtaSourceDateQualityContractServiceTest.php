<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaSourceDateQualityContractService;
use PHPUnit\Framework\TestCase;

final class OtaSourceDateQualityContractServiceTest extends TestCase
{
    public function testReadyMeituanSinglePointRequiresEveryEvidenceGateAndReadback(): void
    {
        $contract = (new OtaSourceDateQualityContractService())->build(
            $this->context(),
            $this->readyPlatformRow()
        );

        self::assertSame('ready', $contract['status']);
        self::assertSame('available', $contract['quality_status']);
        self::assertTrue($contract['claim_allowed']);
        self::assertSame('consume_in_unified_report', $contract['next_action']['code']);
        self::assertSame('ready', $contract['stages']['persistence_readback']['status']);
        self::assertFalse($contract['sensitive_values_exposed']);
    }

    public function testExpectedHotelIdentityMismatchFailsClosedBeforeProfileReuse(): void
    {
        $context = $this->context();
        $context['system_hotel_name'] = '另一家酒店';

        $contract = (new OtaSourceDateQualityContractService())->build(
            $context,
            $this->readyPlatformRow()
        );

        self::assertSame('blocked', $contract['status']);
        self::assertSame('binding_missing', $contract['quality_status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertContains('expected_hotel_name_mismatch', $contract['quality_flags']);
        self::assertSame('register_exact_system_hotel_identity', $contract['next_action']['code']);
    }

    public function testMissingAuthorizedLoginSessionIsBlockedWithoutUsingStoredRowsAsProof(): void
    {
        $row = $this->readyPlatformRow();
        $row['profile']['statusCode'] = 'waiting_login';
        $row['profile']['currentSessionVerified'] = false;
        $row['profile']['currentSessionSameSource'] = false;

        $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

        self::assertSame('partial', $contract['status']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('complete_authorized_meituan_login', $contract['next_action']['code']);
        self::assertContains('authorized_login_session_missing', $contract['quality_flags']);
    }

    public function testTargetDateRowsWithoutReadbackNeverBecomeAvailable(): void
    {
        $row = $this->readyPlatformRow();
        $row['targetDateReadbackVerifiedRows'] = 0;
        $row['targetDateReadbackUnverifiedRows'] = 2;

        $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

        self::assertSame('partial', $contract['status']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertContains('target_date_readback_unverified', $contract['quality_flags']);
        self::assertSame('repair_meituan_persistence_readback', $contract['next_action']['code']);
    }

    public function testNoTargetDateRowsRemainBlockedAndNeverUseZeroAsEvidence(): void
    {
        $row = $this->readyPlatformRow();
        $row['targetDateRows'] = 0;
        $row['targetDateTrafficRows'] = 0;
        $row['fieldFactStatus'] = 'not_loaded';
        $row['verifiedTrafficMetricKeys'] = [];
        $row['missingTrafficMetricKeys'] = ['list_exposure', 'detail_exposure', 'flow_rate'];
        $row['targetDateReadbackVerifiedRows'] = 0;
        $row['targetDateReadbackUnverifiedRows'] = 0;
        $row['quality'] = [
            'primary_quality_state' => 'unverified',
            'quality_flags' => ['target_date_rows_missing'],
        ];

        $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

        self::assertSame('blocked', $contract['status']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('capture_meituan_target_date', $contract['next_action']['code']);
        self::assertContains('target_date_rows_missing', $contract['quality_flags']);
    }

    public function testDifferentPlatformCannotReplaceTheRequestedSource(): void
    {
        $context = $this->context();
        $context['source'] = 'ctrip';

        $contract = (new OtaSourceDateQualityContractService())->build($context, $this->readyPlatformRow());

        self::assertSame('blocked', $contract['status']);
        self::assertSame('ctrip', $contract['source']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertContains('source_identity_mismatch', $contract['quality_flags']);
        self::assertSame('verify_requested_ota_source', $contract['next_action']['code']);
        self::assertSame('meituan', $contract['stages']['source_identity']['evidence']['observed_source']);
    }

    public function testUnsupportedOrExplicitlyMissingPlatformCannotBecomeReady(): void
    {
        foreach (['qunar', '', 'all_ota'] as $source) {
            $row = $this->readyPlatformRow();
            $row['platform'] = $source;
            $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

            self::assertSame('blocked', $contract['status'], $source);
            self::assertFalse($contract['claim_allowed'], $source);
            self::assertContains('source_identity_invalid', $contract['quality_flags']);
            self::assertNull($contract['execution']['capture_entry']);
        }
    }

    public function testDifferentBusinessDateCannotReplaceTheRequestedDate(): void
    {
        $row = $this->readyPlatformRow();
        $row['targetDate'] = '2026-07-27';
        $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

        self::assertSame('blocked', $contract['status']);
        self::assertSame('2026-07-28', $contract['target_date']);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertContains('business_date_identity_mismatch', $contract['quality_flags']);
        self::assertSame('verify_requested_business_date', $contract['next_action']['code']);
        self::assertSame('2026-07-27', $contract['stages']['business_date_identity']['evidence']['observed_target_date']);
    }

    public function testMissingAndInvalidCalendarDatesNeverBecomeReady(): void
    {
        foreach (['', '2026-02-30', '2026-02-29', '2026-7-28', '2026-07-28 00:00:00'] as $date) {
            $context = $this->context();
            $context['target_date'] = $date;
            $row = $this->readyPlatformRow();
            $row['targetDate'] = $date;

            $contract = (new OtaSourceDateQualityContractService())->build($context, $row);

            self::assertSame('blocked', $contract['status'], $date);
            self::assertFalse($contract['claim_allowed'], $date);
            self::assertContains('business_date_identity_invalid', $contract['quality_flags']);
            self::assertSame('verify_requested_business_date', $contract['next_action']['code']);
        }
    }

    public function testExplicitMissingResultIdentityCannotBorrowValidRequestIdentity(): void
    {
        foreach (['', null] as $missing) {
            $context = $this->context();
            $context['source'] = 'meituan';
            $row = $this->readyPlatformRow();
            $row['platform'] = $missing;
            $row['targetDate'] = $missing;

            $contract = (new OtaSourceDateQualityContractService())->build($context, $row);

            self::assertFalse($contract['claim_allowed']);
            self::assertContains('source_identity_invalid', $contract['quality_flags']);
            self::assertContains('business_date_identity_invalid', $contract['quality_flags']);
        }
    }

    public function testExplicitMissingRequestIdentityCannotBorrowValidResultIdentity(): void
    {
        $context = $this->context();
        $context['source'] = null;
        $context['target_date'] = null;
        $contract = (new OtaSourceDateQualityContractService())->build($context, $this->readyPlatformRow());

        self::assertFalse($contract['claim_allowed']);
        self::assertContains('source_identity_invalid', $contract['quality_flags']);
        self::assertContains('business_date_identity_invalid', $contract['quality_flags']);
    }

    public function testInvalidDateIsResolvedBeforeRequestingLoginOrCapture(): void
    {
        $row = $this->readyPlatformRow();
        $row['targetDate'] = '2026-07-27';
        $row['profile']['statusCode'] = 'waiting_login';
        $contract = (new OtaSourceDateQualityContractService())->build($this->context(), $row);

        self::assertSame('verify_requested_business_date', $contract['next_action']['code']);
        self::assertFalse($contract['claim_allowed']);
    }

    public function testLegacySingleIdentityCallsAndCamelCaseDateRemainCompatible(): void
    {
        $service = new OtaSourceDateQualityContractService();
        $context = $this->context();
        unset($context['target_date']);
        self::assertTrue($service->build($context, $this->readyPlatformRow())['claim_allowed']);

        $context['source'] = ' MEITUAN ';
        $context['targetDate'] = '2026-07-28';
        $row = $this->readyPlatformRow();
        unset($row['platform'], $row['targetDate']);
        $contract = $service->build($context, $row);
        self::assertTrue($contract['claim_allowed']);
        self::assertSame('meituan', $contract['source']);
        self::assertSame('2026-07-28', $contract['target_date']);
    }

    public function testMatchingCtripAndLeapDayRemainAvailable(): void
    {
        $context = $this->context();
        $context['source'] = 'ctrip';
        $context['target_date'] = '2024-02-29';
        $row = $this->readyPlatformRow();
        $row['platform'] = 'ctrip';
        $row['targetDate'] = '2024-02-29';

        $contract = (new OtaSourceDateQualityContractService())->build($context, $row);
        self::assertTrue($contract['claim_allowed']);
        self::assertSame('available', $contract['quality_status']);
        self::assertSame('consume_in_unified_report', $contract['next_action']['code']);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'system_hotel_id' => 80,
            'system_hotel_name' => '敦煌漠蓝新',
            'expected_hotel_name' => '敦煌漠蓝新',
            'target_date' => '2026-07-28',
        ];
    }

    /** @return array<string, mixed> */
    private function readyPlatformRow(): array
    {
        return [
            'platform' => 'meituan',
            'targetDate' => '2026-07-28',
            'targetDateRows' => 2,
            'targetDateTrafficRows' => 1,
            'fieldFactStatus' => 'ready',
            'verifiedTrafficMetricKeys' => ['list_exposure', 'detail_exposure', 'flow_rate'],
            'missingTrafficMetricKeys' => [],
            'targetDateReadbackCheckSupported' => true,
            'targetDateReadbackVerifiedRows' => 2,
            'targetDateReadbackUnverifiedRows' => 0,
            'quality' => [
                'primary_quality_state' => 'available',
                'quality_flags' => [],
            ],
            'sourceSummary' => [
                'configuredCount' => 1,
            ],
            'profile' => [
                'statusCode' => 'logged_in',
                'dataSourceId' => 50,
                'profileExists' => true,
                'bindingContractStatus' => 'complete',
                'bindingCheckStatus' => 'ok',
                'platformIdentityConfigured' => true,
                'currentSessionProofRequired' => true,
                'currentSessionVerified' => true,
                'currentSessionSameSource' => true,
            ],
        ];
    }
}
