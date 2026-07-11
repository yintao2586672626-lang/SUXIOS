<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformProfileBindingReadinessService;
use PHPUnit\Framework\TestCase;

final class PlatformProfileBindingReadinessServiceTest extends TestCase
{
    public function testCtripBrowserProfileContractRequiresStoreProfileAndCurrentSessionProof(): void
    {
        $contract = PlatformProfileBindingReadinessService::buildContract('ctrip', 58, [
            'ota_hotel_id' => 'ctrip-58',
            'profile_id' => 'profile-58',
            'profile_binding_key' => 'profile-58',
            'profile_reuse_scope' => 'ota_account_store',
            'profile_daily_reuse_enabled' => true,
            'manual_login_state_verified' => false,
            'login_status' => 'historical_logged_in',
            'cookies' => 'must-not-copy',
            'password' => 'must-not-copy',
        ], [
            'id' => 14,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'last_sync_time' => '2026-06-27 09:03:00',
            'secret_json' => '{"cookies":"must-not-copy"}',
        ], 'logged_in', true, 'profile-58');

        self::assertSame('complete', $contract['status']);
        self::assertTrue($contract['is_complete']);
        self::assertSame([], $contract['missing_requirements']);
        self::assertSame(58, $contract['system_hotel_id']);
        self::assertSame('ctrip', $contract['platform']);
        self::assertSame(14, $contract['data_source_id']);
        self::assertSame('browser_profile', $contract['ingestion_method']);
        self::assertSame('ctrip-58', $contract['ota_store_id']);
        self::assertSame('ota_hotel_id', $contract['ota_store_id_source']);
        self::assertSame('profile-58', $contract['profile_id']);
        self::assertSame('profile_id', $contract['profile_id_source']);
        self::assertSame('ota_account_store', $contract['profile_reuse_scope']);
        self::assertTrue($contract['profile_daily_reuse_enabled']);
        self::assertTrue($contract['current_session_verified']);
        self::assertFalse($contract['historical_login_metadata_present']);
        self::assertFalse($contract['manual_login_state_verified']);
        self::assertSame('', $contract['last_login_verified_at']);

        $encoded = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('must-not-copy', (string)$encoded);
        self::assertStringNotContainsString('password', strtolower((string)$encoded));
        self::assertStringNotContainsString('cookies', strtolower((string)$encoded));
    }

    public function testCtripContractDoesNotTreatProfileOnlyAsStoreBinding(): void
    {
        $contract = PlatformProfileBindingReadinessService::buildContract('ctrip', 58, [
            'profile_id' => 'profile-58',
            'manual_login_state_verified' => true,
            'last_login_verified_at' => '2026-06-27 09:00:00',
        ], [
            'id' => 14,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ], 'logged_in', true, 'profile-58');

        self::assertSame('incomplete', $contract['status']);
        self::assertFalse($contract['is_complete']);
        self::assertSame('', $contract['ota_store_id']);
        self::assertContains('ota_store_id', $contract['missing_requirements']);
    }

    public function testCtripChecksDoNotMarkProfileOnlyIdentityAsOk(): void
    {
        $checks = PlatformProfileBindingReadinessService::buildChecks('ctrip', 58, [
            'profile_id' => 'profile-58',
            'manual_login_state_verified' => true,
            'last_login_verified_at' => '2026-06-27 09:00:00',
        ], [
            'id' => 14,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ], 'logged_in', true, 'profile-58');

        $byKey = array_column($checks, null, 'key');

        self::assertSame('warning', $byKey['platform_identity']['status']);
        self::assertSame('complete_ctrip_identity', $byKey['platform_identity']['action_key']);
    }

    public function testCtripChecksRequireOtaStoreAndProfileIdentityTogether(): void
    {
        $checks = PlatformProfileBindingReadinessService::buildChecks('ctrip', 58, [
            'ota_hotel_id' => 'ctrip-58',
            'profile_id' => 'profile-58',
            'manual_login_state_verified' => true,
            'last_login_verified_at' => '2026-06-27 09:00:00',
        ], [
            'id' => 14,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ], 'logged_in', true, 'profile-58');

        $byKey = array_column($checks, null, 'key');

        self::assertSame('ok', $byKey['platform_identity']['status']);
        self::assertSame('run_ctrip_trial_capture', $byKey['platform_identity']['action_key']);
    }

    public function testMeituanContractKeepsHistoricalLoginMetadataReferenceOnly(): void
    {
        $contract = PlatformProfileBindingReadinessService::buildContract('meituan', 58, [
            'store_id' => 'mt-store-58',
            'poi_id' => 'mt-poi-58',
            'profile_binding_key' => 'mt-store-58',
            'profile_reuse_scope' => 'ota_account_store',
            'profile_daily_reuse_enabled' => true,
            'manual_login_state_verified' => true,
            'last_login_verified_at' => '2026-06-27 09:00:00',
        ], [
            'id' => 18,
            'system_hotel_id' => 58,
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ], 'waiting_login', true, 'mt-store-58');

        self::assertSame('incomplete', $contract['status']);
        self::assertFalse($contract['is_complete']);
        self::assertSame('mt-store-58', $contract['ota_store_id']);
        self::assertSame('store_id', $contract['ota_store_id_source']);
        self::assertSame('mt-store-58', $contract['profile_id']);
        self::assertFalse($contract['current_session_verified']);
        self::assertTrue($contract['historical_login_metadata_present']);
        self::assertTrue($contract['manual_login_state_verified']);
        self::assertSame('2026-06-27 09:00:00', $contract['last_login_verified_at']);
        self::assertSame(['current_session_verified'], $contract['missing_requirements']);
    }

    public function testMeituanChecksDoNotRequirePartnerIdForP0ProfileIdentity(): void
    {
        $checks = PlatformProfileBindingReadinessService::buildChecks('meituan', 58, [
            'store_id' => 'mt-store-58',
            'poi_id' => 'mt-poi-58',
            'manual_login_state_verified' => true,
            'last_login_verified_at' => '2026-06-27 09:00:00',
        ], [
            'id' => 18,
            'system_hotel_id' => 58,
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ], 'logged_in', true, 'mt-store-58');

        $byKey = array_column($checks, null, 'key');

        self::assertSame('ok', $byKey['platform_identity']['status']);
        self::assertSame('login_platform_profile', $byKey['platform_identity']['action_key']);
        self::assertStringContainsString('Browser Profile', $byKey['platform_identity']['detail']);
        self::assertStringNotContainsString('Cookie/API', $byKey['platform_identity']['detail']);
    }
}
