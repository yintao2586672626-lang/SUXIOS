<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudOtaCollectionScopeService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CloudOtaCollectionScopeServiceTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-07-26 10:30:00', new DateTimeZone('Asia/Shanghai'));
    }

    public function testVerifiedHotel80CtripAndMeituanScopeIsReady(): void
    {
        $service = $this->service(true);
        $receipt = $service->evaluate([
            $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80']),
            $this->source(68, 'meituan', ['platform_hotel_id' => 'meituan-h80']),
        ], $this->scope());

        self::assertSame('ready_to_collect', $receipt['status']);
        self::assertTrue($receipt['collection_allowed']);
        self::assertSame([25, 68], $receipt['source_ids']);
        self::assertSame(['ctrip', 'meituan'], $receipt['platforms']);
        self::assertTrue($receipt['sources'][0]['authorization_verified']);
        self::assertTrue($receipt['sources'][0]['collector_binding_verified']);
        self::assertTrue($receipt['sources'][0]['platform_hotel_identity_anchor_present']);
        self::assertTrue($receipt['sources'][0]['current_session_verified']);
        self::assertSame('2026-07-26 10:00:00', $receipt['sources'][0]['session_verified_at']);
        self::assertFalse($receipt['collection_performed']);
        self::assertFalse($receipt['persistence_performed']);
        self::assertFalse($receipt['sensitive_values_exposed']);
    }

    public function testBoundScopeWithoutCurrentSessionWaitsForUserLogin(): void
    {
        $service = $this->service(false);
        $source = $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80']);
        $config = json_decode((string)$source['config_json'], true);
        unset(
            $config['current_session_probe_at'],
            $config['current_session_probe_identity_status']
        );
        $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        $receipt = $service->evaluate([$source], $this->scope([25], ['ctrip']));

        self::assertSame('pending_user_login', $receipt['status']);
        self::assertFalse($receipt['collection_allowed']);
        self::assertSame('login_required', $receipt['sources'][0]['status_code']);
        self::assertStringContainsString('同一云端浏览器登录', $receipt['sources'][0]['message']);
    }

    public function testYesterdaySessionProofCannotAuthorizeTodayCollection(): void
    {
        $service = $this->service(false);
        $source = $this->source(25, 'ctrip', [
            'platform_hotel_id' => 'ctrip-h80',
            'current_session_probe_at' => '2026-07-25 23:50:00',
        ]);

        $receipt = $service->evaluate([$source], $this->scope([25], ['ctrip']));

        self::assertSame('pending_user_login', $receipt['status']);
        self::assertFalse($receipt['collection_allowed']);
        self::assertSame('session_expired', $receipt['sources'][0]['status_code']);
        self::assertStringContainsString('会话验证已过期', $receipt['sources'][0]['message']);
    }

    public function testMissingPlatformHotelIdentityIsHardBlocked(): void
    {
        $receipt = $this->service(true)->evaluate(
            [$this->source(25, 'ctrip')],
            $this->scope([25], ['ctrip'])
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertFalse($receipt['collection_allowed']);
        self::assertSame('platform_hotel_identity_missing', $receipt['sources'][0]['status_code']);
    }

    public function testLegacyIdentityAliasesCannotReplaceCanonicalPlatformHotelId(): void
    {
        foreach ([
            ['hotel_id' => 'ctrip-h80'],
            ['node_id' => 'ctrip-h80'],
            ['store_id' => 'meituan-h80'],
            ['poi_id' => 'meituan-h80'],
        ] as $patch) {
            $platform = array_key_exists('store_id', $patch) || array_key_exists('poi_id', $patch)
                ? 'meituan'
                : 'ctrip';
            $sourceId = $platform === 'ctrip' ? 25 : 68;
            $receipt = $this->service(true)->evaluate(
                [$this->source($sourceId, $platform, $patch)],
                $this->scope([$sourceId], [$platform])
            );

            self::assertSame('blocked', $receipt['status']);
            self::assertSame(
                'platform_hotel_identity_missing',
                $receipt['sources'][0]['status_code']
            );
        }
    }

    public function testExplicitlyScopedHotelOtherThan80UsesSameIsolationContract(): void
    {
        $scope = $this->scope([25], ['ctrip']);
        $scope['hotel_id'] = 81;
        $source = $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h81']);
        $source['system_hotel_id'] = 81;
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $config['collector_hotel_id'] = 81;
        $config['current_session_probe_system_hotel_id'] = 81;
        $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        $receipt = $this->service(true)->evaluate([$source], $scope);

        self::assertSame('ready_to_collect', $receipt['status']);
        self::assertTrue($receipt['collection_allowed']);
        self::assertSame('ready_to_collect', $receipt['sources'][0]['status']);
        self::assertSame('ready', $receipt['sources'][0]['status_code']);
    }

    public function testTamperedPlatformHotelAnchorCannotReuseMatchedProbe(): void
    {
        $source = $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80']);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $config['platform_hotel_id'] = 'ctrip-other-hotel';
        $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        $receipt = $this->service(true)->evaluate(
            [$source],
            $this->scope([25], ['ctrip'])
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertSame('identity_mismatch', $receipt['sources'][0]['status_code']);
    }

    public function testCrossScopeProbeCannotAuthorizeCollection(): void
    {
        foreach ([
            ['current_session_probe_data_source_id', 99],
            ['current_session_probe_tenant_id', 10],
            ['current_session_probe_system_hotel_id', 81],
            ['current_session_probe_platform', 'meituan'],
        ] as [$field, $value]) {
            $source = $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80']);
            $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
            $config[$field] = $value;
            $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

            $receipt = $this->service(true)->evaluate(
                [$source],
                $this->scope([25], ['ctrip'])
            );

            self::assertSame('blocked', $receipt['status'], (string)$field);
            self::assertSame('session_scope_mismatch', $receipt['sources'][0]['status_code'], (string)$field);
        }
    }

    public function testIdentityMismatchIsHardBlocked(): void
    {
        $source = $this->source(68, 'meituan', [
            'platform_hotel_id' => 'meituan-h80',
            'current_session_status' => 'identity_mismatch',
            'current_session_probe_identity_status' => 'mismatch',
        ]);
        $receipt = $this->service(false)->evaluate(
            [$source],
            $this->scope([68], ['meituan'])
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertSame('identity_mismatch', $receipt['sources'][0]['status_code']);
        self::assertStringContainsString('身份与目标酒店绑定不一致', $receipt['sources'][0]['message']);
    }

    public function testCrossHotelAndCrossPlatformSourcesCannotEnterScope(): void
    {
        foreach ([
            ['system_hotel_id', 81],
            ['platform', 'meituan'],
            ['tenant_id', 10],
            ['user_id', 2],
        ] as [$field, $value]) {
            $source = $this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80']);
            $source[$field] = $value;
            $receipt = $this->service(true)->evaluate(
                [$source],
                $this->scope([25], ['ctrip'])
            );

            self::assertSame('blocked', $receipt['status'], (string)$field);
            self::assertSame('scope_mismatch', $receipt['sources'][0]['status_code'], (string)$field);
        }
    }

    public function testMissingAuthorizationCannotEnterPendingLoginState(): void
    {
        $scope = $this->scope([25], ['ctrip']);
        $scope['authorization_mode'] = '';
        $receipt = $this->service(false)->evaluate(
            [$this->source(25, 'ctrip', ['platform_hotel_id' => 'ctrip-h80'])],
            $scope
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertSame('authorization_missing', $receipt['sources'][0]['status_code']);
    }

    public function testIncompleteOrDifferentDeviceBindingIsBlocked(): void
    {
        $source = $this->source(25, 'ctrip', [
            'platform_hotel_id' => 'ctrip-h80',
            'collector_device_id' => 'another-device',
            'collector_device_id_hash' => hash('sha256', 'another-device'),
        ]);
        $receipt = $this->service(true)->evaluate(
            [$source],
            $this->scope([25], ['ctrip'])
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertSame('binding_missing', $receipt['sources'][0]['status_code']);
    }

    private function service(bool $currentSessionVerified): CloudOtaCollectionScopeService
    {
        return new CloudOtaCollectionScopeService(
            static fn(array $source): bool => $currentSessionVerified,
            fn(): DateTimeImmutable => $this->now
        );
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array<int, string> $platforms
     * @return array<string, mixed>
     */
    private function scope(array $sourceIds = [25, 68], array $platforms = ['ctrip', 'meituan']): array
    {
        return [
            'mode' => 'single_user_local',
            'authorization_mode' => 'same_tenant_explicit_hotel_grant',
            'tenant_id' => 9,
            'user_id' => 1,
            'device_id' => 'cloud-owner-device',
            'device_id_hash' => hash('sha256', 'cloud-owner-device'),
            'hotel_id' => 80,
            'source_ids' => $sourceIds,
            'platforms' => $platforms,
        ];
    }

    /** @param array<string, mixed> $patch @return array<string, mixed> */
    private function source(int $id, string $platform, array $patch = []): array
    {
        $config = array_replace([
            'source_method' => 'single_user_local',
            'collector_binding_mode' => 'single_user_local',
            'collector_device_id' => 'cloud-owner-device',
            'collector_device_id_hash' => hash('sha256', 'cloud-owner-device'),
            'collector_user_id' => 1,
            'collector_tenant_id' => 9,
            'collector_hotel_id' => 80,
            'collector_platform' => $platform,
            'collector_bound_at' => '2026-07-26 09:30:00',
            'current_session_probe_at' => '2026-07-26 10:00:00',
            'current_session_probe_date' => '2026-07-26',
            'current_session_probe_timezone' => 'Asia/Shanghai',
            'current_session_probe_performed' => true,
            'current_session_verified' => true,
            'current_session_probe_data_source_id' => $id,
            'current_session_probe_tenant_id' => 9,
            'current_session_probe_system_hotel_id' => 80,
            'current_session_probe_platform' => $platform,
            'current_session_probe_scope' => 'same_data_source_profile_session',
            'current_session_status' => 'verified',
            'current_session_probe_identity_status' => 'matched',
        ], $patch);
        $config['current_session_probe_platform_hotel_id'] = (string)($config['platform_hotel_id'] ?? '');

        return [
            'id' => $id,
            'tenant_id' => 9,
            'user_id' => 1,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
        ];
    }
}
