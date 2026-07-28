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
