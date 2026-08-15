<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelCollectionBindingReceiptService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class HotelCollectionBindingReceiptServiceTest extends TestCase
{
    public function testNumericLegacyProfileAliasesRemainStrings(): void
    {
        $service = new HotelCollectionBindingReceiptService();
        $method = new \ReflectionMethod($service, 'explicitProfileAliasValues');

        self::assertSame(
            ['130079194'],
            $method->invoke($service, [
                'profile_id' => 130079194,
                'stable_profile_id' => '130079194',
            ])
        );
    }

    public function testOperatorConfirmedOnboardingIdentityIsTruthfullyLabeledAndCanBeScheduled(): void
    {
        $sources = $this->sources();
        foreach ($sources as &$source) {
            if (($source['platform'] ?? '') !== 'ctrip') {
                continue;
            }
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['platform_hotel_identity_source'] = 'operator_confirmed_onboarding';
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);

        $receipt = $this->service($sources)->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['binding_ready']);
        self::assertSame('operator_confirmed', $receipt['bindings']['ctrip']['identity_evidence']['status']);
    }

    public function testExactThreeSourceBindingIsReadyWithoutExposingExecutionSecrets(): void
    {
        $receipt = $this->service()->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['binding_ready']);
        self::assertSame(25, $receipt['bindings']['ctrip']['source_id']);
        self::assertSame('CTRIP-80', $receipt['bindings']['ctrip']['platform_hotel_id']);
        self::assertSame(68, $receipt['bindings']['meituan']['source_id']);
        self::assertSame('MT-80', $receipt['bindings']['meituan']['platform_hotel_id']);
        self::assertSame('dingdandao_pms', $receipt['bindings']['pms']['provider']);
        self::assertSame('DD-80', $receipt['bindings']['pms']['provider_hotel_id']);
        self::assertSame('bound', $receipt['bindings']['ctrip']['execution_device_binding']['status']);
        self::assertFalse($receipt['bindings']['ctrip']['execution_device_binding']['automatic_device_substitution']);
        self::assertSame([], $receipt['blockers']);
        self::assertSame([], $receipt['recovery_reasons']);
        self::assertFalse($receipt['replication_gate']['ready']);
        self::assertSame(
            'binding_ready_runtime_acceptance_still_required',
            $receipt['replication_gate']['status']
        );
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['binding_digest']);
        self::assertSame(
            $receipt['binding_digest'],
            $this->service()->receipt($this->hotel(), 7, '2026-08-09')['binding_digest']
        );

        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('device-public-501', $json);
        self::assertStringNotContainsString('secret_json', strtolower($json));
        self::assertStringNotContainsString('device_token_hash', strtolower($json));
        self::assertStringNotContainsString('profile_path', strtolower($json));
        self::assertArrayNotHasKey('device_id', $receipt['bindings']['ctrip']['execution_device_binding']);
        self::assertArrayNotHasKey('account_id', $receipt['bindings']['ctrip']['execution_device_binding']);
    }

    public function testSingleUserLocalBrowserProfileUsesItsExactPersistedExecutionBinding(): void
    {
        $receipt = $this->service($this->singleUserLocalProfileSources(), null, [])->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('ready', $receipt['status']);
        foreach (['ctrip', 'meituan'] as $platform) {
            $execution = $receipt['bindings'][$platform]['execution_device_binding'];
            self::assertSame('bound', $execution['status'], $platform);
            self::assertSame('browser_profile_single_user_local', $execution['binding_kind'], $platform);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$execution['execution_binding_digest']);
            self::assertSame(hash('sha256', 'server-owner-device'), $execution['device_binding_digest']);
            self::assertNull($execution['device_status']);
            self::assertNull($execution['account_status']);
            self::assertSame('profile_reuse_verified', $execution['session_status']);
            self::assertSame(
                'same_bound_local_profile_owner_hotel_platform',
                $execution['resume_scope']
            );
        }
        self::assertNotContains(
            'ota_execution_device_binding_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertFalse($receipt['execution_policy']['automatic_device_substitution']);
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertStringNotContainsString(
            'server-owner-device',
            json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public function testExactReadyCloudBrowserProfileIsASecretFreeExecutionBinding(): void
    {
        $cloudProfiles = $this->cloudProfiles();
        $receipt = $this->service(
            sources: $this->cloudProfileSources(),
            profiles: $this->cloudProfileBindings(),
            local: [],
            cloudProfiles: $cloudProfiles
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('ready', $receipt['status']);
        foreach (['ctrip', 'meituan'] as $platform) {
            $execution = $receipt['bindings'][$platform]['execution_device_binding'];
            self::assertSame('bound', $execution['status'], $platform);
            self::assertSame('cloud_browser_profile', $execution['binding_kind'], $platform);
            self::assertSame('ready_to_collect', $execution['session_status'], $platform);
            self::assertSame('ready_to_collect', $execution['authorization_status'], $platform);
            self::assertTrue($execution['ready_to_collect'], $platform);
            self::assertSame(
                'same_cloud_profile_owner_hotel_platform',
                $execution['resume_scope'],
                $platform
            );
            self::assertMatchesRegularExpression(
                '/^[a-f0-9]{64}$/D',
                (string)$execution['execution_binding_digest']
            );
        }
        self::assertSame('profile_browser', $receipt['bindings']['meituan']['ingestion_method']);

        $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($cloudProfiles as $profile) {
            self::assertStringNotContainsString((string)$profile['profile_public_id'], $encoded);
        }
        self::assertFalse($receipt['sensitive_values_exposed']);
    }

    public function testExpiredExactCloudProfileStaysBoundButMakesReceiptRecoverable(): void
    {
        $cloudProfiles = $this->cloudProfiles();
        $cloudProfiles[0]['session_expires_at'] = '2026-08-09 08:00:00';

        $receipt = $this->service(
            sources: $this->cloudProfileSources(),
            profiles: $this->cloudProfileBindings(),
            local: [],
            cloudProfiles: $cloudProfiles
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('recoverable', $receipt['status']);
        self::assertSame([], $receipt['blockers']);
        self::assertContains('session_expired', array_column($receipt['recovery_reasons'], 'code'));
        self::assertSame('bound', $receipt['bindings']['ctrip']['execution_device_binding']['status']);
        self::assertSame(
            'session_expired',
            $receipt['bindings']['ctrip']['execution_device_binding']['session_status']
        );
        self::assertFalse($receipt['bindings']['ctrip']['execution_device_binding']['ready_to_collect']);
    }

    public function testCloudProfileRotationMustAlsoUpdateTheExactSourceBinding(): void
    {
        $cloudProfiles = $this->cloudProfiles();
        $sources = $this->cloudProfileSources();
        $profiles = $this->cloudProfileBindings();
        $baseline = $this->service(sources: $sources, profiles: $profiles, local: [], cloudProfiles: $cloudProfiles)->receipt(
            $this->hotel(), 7, '2026-08-09', ['ctrip' => 25, 'meituan' => 68]
        );
        $renewed = $cloudProfiles;
        $renewed[0]['session_expires_at'] = '2026-08-11 08:20:00';
        $renewedReceipt = $this->service(sources: $sources, profiles: $profiles, local: [], cloudProfiles: $renewed)->receipt(
            $this->hotel(), 7, '2026-08-09', ['ctrip' => 25, 'meituan' => 68]
        );
        $rotated = $cloudProfiles;
        $rotated[0]['profile_public_id'] = 'cbp_ctrip_hotel80_replaced';
        $rotatedReceipt = $this->service(sources: $sources, profiles: $profiles, local: [], cloudProfiles: $rotated)->receipt(
            $this->hotel(), 7, '2026-08-09', ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame($baseline['binding_digest'], $renewedReceipt['binding_digest']);
        self::assertSame('blocked', $rotatedReceipt['status']);
        self::assertContains(
            'ota_cloud_profile_source_binding_mismatch',
            array_column($rotatedReceipt['blockers'], 'code')
        );
        self::assertSame('invalid', $rotatedReceipt['bindings']['ctrip']['execution_device_binding']['status']);
    }

    public function testCloudProfileOwnedByAnotherUserCannotBecomeExecutionBinding(): void
    {
        $cloudProfiles = $this->cloudProfiles();
        foreach ($cloudProfiles as &$profile) {
            $profile['owner_user_id'] = 99;
        }
        unset($profile);

        $receipt = $this->service(
            sources: $this->cloudProfileSources(),
            profiles: $this->cloudProfileBindings(),
            local: [],
            cloudProfiles: $cloudProfiles
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_execution_device_binding_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame('missing', $receipt['bindings']['ctrip']['execution_device_binding']['status']);
    }

    public function testOrdinaryBrowserProfileDoesNotSilentlyBecomeASchedulerExecutionBinding(): void
    {
        $receipt = $this->service(null, null, [])->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_execution_device_binding_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame('missing', $receipt['bindings']['ctrip']['execution_device_binding']['status']);
    }

    public function testIncompleteSingleUserLocalDeclarationCannotFallBackToAnotherDeviceMapping(): void
    {
        $sources = $this->singleUserLocalProfileSources();
        foreach ($sources as &$source) {
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($config['collector_device_id_hash']);
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);

        $receipt = $this->service($sources)->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_execution_device_binding_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame('missing', $receipt['bindings']['ctrip']['execution_device_binding']['status']);
    }

    public function testExpiredSingleUserLocalProfileIsRecoverableWithoutChangingItsBinding(): void
    {
        $receipt = $this->service(
            $this->singleUserLocalProfileSources(),
            null,
            [],
            null,
            null,
            null,
            null,
            static fn(array $source): array => [
                'status' => 'expired',
                'is_reusable' => false,
                'reason' => 'profile_reauthentication_required',
            ]
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('recoverable', $receipt['status']);
        self::assertSame([], $receipt['blockers']);
        self::assertContains('session_expired', array_column($receipt['recovery_reasons'], 'code'));
        self::assertSame(
            'bound',
            $receipt['bindings']['ctrip']['execution_device_binding']['status']
        );
        self::assertSame(
            'session_expired',
            $receipt['bindings']['ctrip']['execution_device_binding']['session_status']
        );
    }

    public function testSingleUserLocalBindingDigestChangesWithDeviceOrBindingTime(): void
    {
        $sources = $this->singleUserLocalProfileSources();
        $baseline = $this->service($sources, null, [])->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        $rotated = $sources;
        $delayed = $sources;
        foreach ($rotated as &$source) {
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['collector_device_id'] = 'replacement-owner-device';
            $config['collector_device_id_hash'] = hash('sha256', 'replacement-owner-device');
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);
        foreach ($delayed as &$source) {
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['collector_bound_at'] = '2026-08-09 08:21:00';
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);

        $rotatedReceipt = $this->service($rotated, null, [])->receipt(
            $this->hotel(), 7, '2026-08-09', ['ctrip' => 25, 'meituan' => 68]
        );
        $delayedReceipt = $this->service($delayed, null, [])->receipt(
            $this->hotel(), 7, '2026-08-09', ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertNotSame($baseline['binding_digest'], $rotatedReceipt['binding_digest']);
        self::assertNotSame($baseline['binding_digest'], $delayedReceipt['binding_digest']);
        self::assertNotSame(
            $baseline['bindings']['ctrip']['execution_device_binding']['execution_binding_digest'],
            $rotatedReceipt['bindings']['ctrip']['execution_device_binding']['execution_binding_digest']
        );
        self::assertNotSame(
            $baseline['bindings']['ctrip']['execution_device_binding']['execution_binding_digest'],
            $delayedReceipt['bindings']['ctrip']['execution_device_binding']['execution_binding_digest']
        );
    }

    public function testLocalCollectorStillRequiresItsExactOperatorDeviceMapping(): void
    {
        $receipt = $this->service(
            $this->localCollectorSources(),
            [],
            []
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_execution_device_binding_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame(
            'missing',
            $receipt['bindings']['ctrip']['execution_device_binding']['status']
        );
    }

    public function testLegacyMeituanAliasesCannotSilentlyReplaceCanonicalIdentity(): void
    {
        $sources = $this->sources();
        $config = json_decode((string)$sources[1]['config_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($config['platform_hotel_id']);
        $sources[1]['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        $receipt = $this->service($sources)->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('blocked', $receipt['status']);
        self::assertFalse($receipt['binding_ready']);
        self::assertNull($receipt['bindings']['meituan']['platform_hotel_id']);
        self::assertSame('MT-80', $receipt['bindings']['meituan']['legacy_platform_hotel_id_candidate']);
        self::assertContains(
            'ota_platform_hotel_id_canonical_missing',
            array_column($receipt['bindings']['meituan']['blockers'], 'code')
        );
    }

    public function testTwoActiveProfileSourcesRemainAnExplicitConflict(): void
    {
        $sources = $this->sources();
        $duplicate = $sources[1];
        $duplicate['id'] = 101;
        $sources[] = $duplicate;

        $receipt = $this->service($sources)->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('blocked', $receipt['status']);
        self::assertNull($receipt['bindings']['meituan']['source_id']);
        self::assertSame([68, 101], $receipt['bindings']['meituan']['candidate_source_ids']);
        self::assertContains(
            'ota_source_binding_conflict',
            array_column($receipt['bindings']['meituan']['blockers'], 'code')
        );
    }

    public function testPlanDesignatedSourcePinsExecutionWithoutAutoSelectingAnotherSource(): void
    {
        $sources = $this->sources();
        $duplicate = $sources[1];
        $duplicate['id'] = 101;
        $sources[] = $duplicate;

        $receipt = $this->service($sources)->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('ready', $receipt['status']);
        self::assertSame(68, $receipt['bindings']['meituan']['source_id']);
        self::assertSame(68, $receipt['bindings']['meituan']['designated_source_id']);
        self::assertSame([101], $receipt['bindings']['meituan']['unselected_active_source_ids']);
        self::assertFalse($receipt['execution_policy']['automatic_device_substitution']);
    }

    public function testOfflineDeviceIsRecoverableOnlyOnTheSameBinding(): void
    {
        $local = $this->localBindings();
        $local[1]['device_status'] = 'online';
        $local[1]['last_seen_at'] = '2026-08-09 08:30:00';

        $receipt = $this->service(null, null, $local)->receipt(
            $this->hotel(),
            7,
            '2026-08-09'
        );

        self::assertSame('recoverable', $receipt['status']);
        self::assertSame([], $receipt['blockers']);
        self::assertContains('device_offline', array_column($receipt['recovery_reasons'], 'code'));
        self::assertFalse($receipt['execution_policy']['automatic_device_substitution']);
        self::assertSame(
            'same_account_same_device_same_hotel_same_platform',
            $receipt['bindings']['meituan']['execution_device_binding']['resume_scope']
        );
    }

    public function testLocalCollectorSourceUsesTheExactAccountProfileWithoutBrowserProfilePool(): void
    {
        $receipt = $this->service(
            $this->localCollectorSources(),
            [],
            $this->localBindings()
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('ready', $receipt['status']);
        self::assertSame('local_collector', $receipt['bindings']['ctrip']['ingestion_method']);
        self::assertSame(
            'local_account_bound',
            $receipt['bindings']['ctrip']['profile_binding']['status']
        );
        self::assertSame(
            hash('sha256', 'ctrip-profile-80'),
            $receipt['bindings']['ctrip']['profile_binding']['profile_binding_digest']
        );
        self::assertNotContains(
            'ota_profile_binding_missing',
            array_column($receipt['bindings']['ctrip']['blockers'], 'code')
        );
    }

    public function testLocalCollectorProfileMismatchBlocksTheExactDeviceBinding(): void
    {
        $local = $this->localBindings();
        $local[0]['profile_key_hash'] = hash('sha256', 'another-device-profile');

        $receipt = $this->service(
            $this->localCollectorSources(),
            [],
            $local
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_execution_profile_binding_mismatch',
            array_column($receipt['bindings']['ctrip']['blockers'], 'code')
        );
        self::assertSame(
            'local_account_profile_declared',
            $receipt['bindings']['ctrip']['profile_binding']['status']
        );
    }

    public function testLocalCollectorSourceCannotSubstituteAnotherAccount(): void
    {
        $local = $this->localBindings();
        $local[0]['account_id'] = 999;

        $receipt = $this->service(
            $this->localCollectorSources(),
            [],
            $local
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_local_collector_source_execution_mismatch',
            array_column($receipt['bindings']['ctrip']['blockers'], 'code')
        );
    }

    public function testLocalCollectorSourceCannotSubstituteAnotherDevice(): void
    {
        $local = $this->localBindings();
        $local[0]['device_binding_digest'] = hash('sha256', 'another-device');

        $receipt = $this->service(
            $this->localCollectorSources(),
            [],
            $local
        )->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_local_collector_source_execution_mismatch',
            array_column($receipt['bindings']['ctrip']['blockers'], 'code')
        );
    }

    public function testSameHotelLegacySourceDoesNotBecomeACrossHotelIdentityConflict(): void
    {
        $identityOwners = static function (string $kind, string $platform, string $externalId): array {
            if ($kind === 'pms') {
                return [['tenant_id' => 8, 'system_hotel_id' => 80]];
            }
            $sourceId = $platform === 'ctrip' ? 25 : 68;
            return [
                ['tenant_id' => 8, 'system_hotel_id' => 80, 'source_id' => $sourceId],
                ['tenant_id' => 8, 'system_hotel_id' => 80, 'source_id' => $sourceId + 1000],
            ];
        };

        $receipt = $this->service(null, null, null, null, $identityOwners)->receipt(
            $this->hotel(),
            7,
            '2026-08-09',
            ['ctrip' => 25, 'meituan' => 68]
        );

        self::assertSame('ready', $receipt['status']);
        self::assertNotContains(
            'ota_platform_hotel_identity_cross_hotel_conflict',
            array_column($receipt['blockers'], 'code')
        );
    }

    public function testRevokedExecutionOwnerPermissionIsRecoverableWithoutSubstitution(): void
    {
        $receipt = $this->service(
            null,
            null,
            null,
            null,
            null,
            null,
            static fn(int $tenantId, int $hotelId, int $userId): bool => false
        )->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('recoverable', $receipt['status']);
        self::assertFalse($receipt['binding_ready']);
        self::assertContains('permission_denied', array_column($receipt['recovery_reasons'], 'code'));
        self::assertFalse($receipt['execution_policy']['automatic_device_substitution']);
    }

    public function testSuperAdminExecutionOwnerMayUseAnExplicitCrossTenantHotelScope(): void
    {
        $method = new ReflectionMethod(
            HotelCollectionBindingReceiptService::class,
            'executionOwnerTenantCompatible'
        );
        $method->setAccessible(true);
        $service = $this->service();

        self::assertTrue($method->invoke($service, 7, 80, true));
        self::assertFalse($method->invoke($service, 7, 80, false));
        self::assertTrue($method->invoke($service, 80, 80, false));
    }

    public function testBindingDigestChangesWhenAccountOrExecutionOwnerChangesOnSameDevice(): void
    {
        $baseline = $this->service()->receipt($this->hotel(), 7, '2026-08-09');

        $changedAccount = $this->localBindings();
        $changedAccount[0]['account_id'] = 999;
        $accountReceipt = $this->service(null, null, $changedAccount)->receipt(
            $this->hotel(),
            7,
            '2026-08-09'
        );

        $changedOwnerSources = $this->sources();
        foreach ($changedOwnerSources as &$source) {
            $source['user_id'] = 9;
        }
        unset($source);
        $changedOwnerLocal = $this->localBindings();
        foreach ($changedOwnerLocal as &$binding) {
            $binding['account_user_id'] = 9;
            $binding['device_user_id'] = 9;
        }
        unset($binding);
        $ownerReceipt = $this->service(
            $changedOwnerSources,
            null,
            $changedOwnerLocal
        )->receipt($this->hotel(), 7, '2026-08-09');

        self::assertSame('ready', $accountReceipt['status']);
        self::assertSame('ready', $ownerReceipt['status']);
        self::assertNotSame($baseline['binding_digest'], $accountReceipt['binding_digest']);
        self::assertNotSame($baseline['binding_digest'], $ownerReceipt['binding_digest']);
        self::assertSame(
            $baseline['bindings']['ctrip']['execution_device_binding']['device_binding_digest'],
            $accountReceipt['bindings']['ctrip']['execution_device_binding']['device_binding_digest']
        );
        self::assertSame(9, $ownerReceipt['execution_owner_user_id']);
    }

    public function testForeignPlatformIdentityOwnerBlocksCrossHotelReuse(): void
    {
        $identityOwners = static function (string $kind, string $platform, string $externalId): array {
            if ($kind === 'pms') {
                return [['tenant_id' => 8, 'system_hotel_id' => 80]];
            }
            if ($platform === 'meituan') {
                return [
                    ['tenant_id' => 8, 'system_hotel_id' => 80, 'source_id' => 68],
                    ['tenant_id' => 8, 'system_hotel_id' => 81, 'source_id' => 81],
                ];
            }
            return [['tenant_id' => 8, 'system_hotel_id' => 80, 'source_id' => 25]];
        };

        $receipt = $this->service(null, null, null, null, $identityOwners)->receipt(
            $this->hotel(),
            7,
            '2026-08-09'
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'ota_platform_hotel_identity_cross_hotel_conflict',
            array_column($receipt['bindings']['meituan']['blockers'], 'code')
        );
    }

    public function testInvalidBusinessDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hotel_collection_binding_date_invalid');

        $this->service()->receipt($this->hotel(), 7, '2026-02-30');
    }

    /**
     * @param array<int,array<string,mixed>>|null $sources
     * @param array<int,array<string,mixed>>|null $profiles
     * @param array<int,array<string,mixed>>|null $local
     */
    private function service(
        ?array $sources = null,
        ?array $profiles = null,
        ?array $local = null,
        ?array $pms = null,
        ?callable $identityOwners = null,
        ?callable $clock = null,
        ?callable $executionOwnerPermission = null,
        ?callable $profileSessionState = null,
        ?array $cloudProfiles = null
    ): HotelCollectionBindingReceiptService {
        $sources ??= $this->sources();
        $profiles ??= $this->profiles();
        $local ??= $this->localBindings();
        $pms ??= $this->pms();
        $identityOwners ??= static function (string $kind, string $platform, string $externalId): array {
            if ($kind === 'pms') {
                return [['tenant_id' => 8, 'system_hotel_id' => 80]];
            }
            return [[
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'source_id' => $platform === 'ctrip' ? 25 : 68,
            ]];
        };
        $clock ??= static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-08-09 08:36:30');
        $executionOwnerPermission ??= static fn(int $tenantId, int $hotelId, int $userId): bool => true;
        $profileSessionState ??= static fn(array $source): array => [
            'status' => 'reusable',
            'is_reusable' => true,
            'reason' => 'profile_proof_reusable',
        ];
        $cloudProfiles ??= [];

        return new HotelCollectionBindingReceiptService(
            static fn(int $tenantId, int $hotelId): array => $sources,
            static fn(int $tenantId, int $hotelId): array => $profiles,
            static fn(int $tenantId, int $hotelId): array => $local,
            static fn(int $tenantId, int $hotelId, int $userId, string $date): array => $pms,
            $identityOwners,
            $clock,
            $executionOwnerPermission,
            $profileSessionState,
            static fn(int $tenantId, int $hotelId): array => $cloudProfiles
        );
    }

    /** @return array<string,mixed> */
    private function hotel(): array
    {
        return [
            'id' => 80,
            'tenant_id' => 8,
            'name' => 'Hotel 80',
            'status' => 1,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function sources(): array
    {
        return [
            [
                'id' => 25,
                'tenant_id' => 8,
                'user_id' => 7,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
                'last_sync_time' => '2026-08-09 08:32:00',
                'config_json' => json_encode([
                    'profile_id' => 'ctrip-profile-80',
                    'platform_hotel_id' => 'CTRIP-80',
                    'hotel_id' => 'CTRIP-80',
                    'platform_hotel_identity_source' => 'same_origin_profile_probe',
                    'platform_hotel_identity_checked_at' => '2026-08-09 08:30:00',
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 68,
                'tenant_id' => 8,
                'user_id' => 7,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
                'last_sync_time' => '2026-08-09 08:35:00',
                'config_json' => json_encode([
                    'platform_hotel_id' => 'MT-80',
                    'store_id' => 'MT-80',
                    'poi_id' => 'MT-80',
                    'platform_hotel_identity_source' => 'same_origin_profile_probe',
                    'platform_hotel_identity_checked_at' => '2026-08-09 08:33:00',
                ], JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function singleUserLocalProfileSources(): array
    {
        $sources = $this->sources();
        foreach ($sources as &$source) {
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['source_method'] = 'single_user_local';
            $config['collector_binding_mode'] = 'single_user_local';
            $config['collector_device_id'] = 'server-owner-device';
            $config['collector_device_id_hash'] = hash('sha256', 'server-owner-device');
            $config['collector_user_id'] = 7;
            $config['collector_tenant_id'] = 8;
            $config['collector_hotel_id'] = 80;
            $config['collector_platform'] = (string)$source['platform'];
            $config['collector_bound_at'] = '2026-08-09 08:20:00';
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);
        return $sources;
    }

    /** @return array<int,array<string,mixed>> */
    private function localCollectorSources(): array
    {
        $sources = $this->sources();
        $profileHashes = [
            'ctrip' => hash('sha256', 'ctrip-profile-80'),
            'meituan' => hash('sha256', 'MT-80'),
        ];
        foreach ($sources as &$source) {
            $platform = (string)$source['platform'];
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['profile_key_hash'] = $profileHashes[$platform];
            $config['local_collector_account_id'] = 400 + (int)$source['id'];
            $config['collector_device_id_hash'] = hash('sha256', 'device-public-501');
            $config['platform_hotel_identity_source'] = 'local_collector_verified_capture';
            $source['ingestion_method'] = 'local_collector';
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
        }
        unset($source);
        return $sources;
    }

    /** @return array<int,array<string,mixed>> */
    private function profiles(): array
    {
        return [
            [
                'id' => 1,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'profile_key_hash' => hash('sha256', 'ctrip-profile-80'),
                'binding_status' => 'active',
            ],
            [
                'id' => 2,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'profile_key_hash' => hash('sha256', 'MT-80'),
                'binding_status' => 'active',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function cloudProfiles(): array
    {
        return [
            [
                'id' => 31,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'owner_user_id' => 7,
                'platform' => 'ctrip',
                'profile_public_id' => 'cbp_ctrip_hotel80_abcdef',
                'authorization_status' => 'ready_to_collect',
                'ready_at' => '2026-08-09 08:20:00',
                'session_expires_at' => '2026-08-10 08:20:00',
            ],
            [
                'id' => 32,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'owner_user_id' => 7,
                'platform' => 'meituan',
                'profile_public_id' => 'cbp_meituan_hotel80_abcdef',
                'authorization_status' => 'ready_to_collect',
                'ready_at' => '2026-08-09 08:21:00',
                'session_expires_at' => '2026-08-10 08:21:00',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function cloudProfileSources(): array
    {
        $sources = $this->sources();
        $profileIds = [
            'ctrip' => 'cbp_ctrip_hotel80_abcdef',
            'meituan' => 'cbp_meituan_hotel80_abcdef',
        ];
        foreach ($sources as &$source) {
            $platform = (string)$source['platform'];
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config['profile_binding_key'] = $profileIds[$platform];
            $config['stable_profile_id'] = $profileIds[$platform];
            $config['profile_id'] = $profileIds[$platform];
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);
            if ($platform === 'meituan') {
                $source['ingestion_method'] = 'profile_browser';
            }
        }
        unset($source);
        return $sources;
    }

    /** @return array<int,array<string,mixed>> */
    private function cloudProfileBindings(): array
    {
        return [
            [
                'id' => 31,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'platform' => 'ctrip',
                'profile_key_hash' => hash('sha256', 'cbp_ctrip_hotel80_abcdef'),
                'binding_status' => 'active',
            ],
            [
                'id' => 32,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'profile_key_hash' => hash('sha256', 'cbp_meituan_hotel80_abcdef'),
                'binding_status' => 'active',
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function localBindings(): array
    {
        return [
            $this->localBinding(
                'ctrip',
                25,
                'CTRIP-80',
                hash('sha256', 'ctrip-profile-80')
            ),
            $this->localBinding(
                'meituan',
                68,
                'MT-80',
                hash('sha256', 'MT-80')
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function localBinding(
        string $platform,
        int $sourceId,
        string $platformHotelId,
        string $profileHash
    ): array {
        return [
            'mapping_id' => $sourceId,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'platform_hotel_id' => $platformHotelId,
            'data_source_id' => $sourceId,
            'mapping_status' => 'active',
            'account_id' => 400 + $sourceId,
            'account_tenant_id' => 8,
            'account_user_id' => 7,
            'account_status' => 'active',
            'session_status' => 'current_session_verified',
            'profile_key_hash' => $profileHash,
            'last_error_code' => '',
            'device_id' => 501,
            'device_tenant_id' => 8,
            'device_user_id' => 7,
            'device_status' => 'online',
            'last_seen_at' => '2026-08-09 08:36:00',
            'device_binding_digest' => hash('sha256', 'device-public-501'),
        ];
    }

    /** @return array<string,mixed> */
    private function pms(): array
    {
        return [
            'binding_status' => 'configured',
            'selected_provider' => 'dingdandao_pms',
            'selected_source' => [
                'config' => [
                    'provider_hotel_id' => 'DD-80',
                    'provider_hotel_name' => 'Hotel 80',
                    'last_capture_business_date' => '2026-08-09',
                    'last_capture_status' => 'verified',
                    'last_readback_status' => 'readback_verified',
                ],
            ],
            'blockers' => [],
        ];
    }
}
