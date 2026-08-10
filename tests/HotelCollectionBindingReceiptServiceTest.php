<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelCollectionBindingReceiptService;
use PHPUnit\Framework\TestCase;

final class HotelCollectionBindingReceiptServiceTest extends TestCase
{
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
        ?callable $executionOwnerPermission = null
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

        return new HotelCollectionBindingReceiptService(
            static fn(int $tenantId, int $hotelId): array => $sources,
            static fn(int $tenantId, int $hotelId): array => $profiles,
            static fn(int $tenantId, int $hotelId): array => $local,
            static fn(int $tenantId, int $hotelId, int $userId, string $date): array => $pms,
            $identityOwners,
            $clock,
            $executionOwnerPermission
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
