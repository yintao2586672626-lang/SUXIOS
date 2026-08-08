<?php
declare(strict_types=1);

namespace Tests;

use app\command\AutoFetchOnlineData;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AutoFetchCloudBindingTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'auto_fetch_cloud_binding_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, '
            . 'system_hotel_id INTEGER, platform TEXT, ingestion_method TEXT, '
            . 'enabled INTEGER, status TEXT, config_json TEXT, update_time DATETIME)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove cloud binding SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('platform_data_sources')->delete(true);
    }

    public function testConfirmedBindingIsAtomicAndPreservesExistingProfileMetadata(): void
    {
        $sources = [
            $this->source(25, 'ctrip', [
                'stable_profile_id' => 'ctrip-profile',
                'platform_hotel_id' => 'ctrip-h80',
                'current_session_probe_performed' => true,
                'current_session_verified' => true,
                'current_session_probe_platform_hotel_id' => 'ctrip-h80',
            ]),
            $this->source(68, 'meituan', [
                'store_id' => 'meituan-store',
                'platform_hotel_id' => 'meituan-h80',
            ]),
        ];
        foreach ($sources as $source) {
            Db::name('platform_data_sources')->insert([
                'id' => $source['id'],
                'tenant_id' => $source['tenant_id'],
                'user_id' => $source['user_id'],
                'system_hotel_id' => $source['system_hotel_id'],
                'platform' => $source['platform'],
                'ingestion_method' => $source['ingestion_method'],
                'enabled' => $source['enabled'],
                'status' => $source['status'],
                'config_json' => $source['config_json'],
            ]);
        }

        $this->bind($sources, $this->scope('server-owner-device'), false);

        $ctrip = $this->storedConfig(25);
        $meituan = $this->storedConfig(68);
        self::assertSame('ctrip-profile', $ctrip['stable_profile_id']);
        self::assertSame('meituan-store', $meituan['store_id']);
        self::assertSame('ctrip-h80', $ctrip['platform_hotel_id']);
        self::assertSame('meituan-h80', $meituan['platform_hotel_id']);
        self::assertArrayNotHasKey('current_session_probe_performed', $ctrip);
        self::assertArrayNotHasKey('current_session_verified', $ctrip);
        self::assertArrayNotHasKey('current_session_probe_platform_hotel_id', $ctrip);
        foreach ([25 => $ctrip, 68 => $meituan] as $sourceId => $config) {
            self::assertSame('single_user_local', $config['source_method'], (string)$sourceId);
            self::assertSame('server-owner-device', $config['collector_device_id'], (string)$sourceId);
            self::assertSame(
                hash('sha256', 'server-owner-device'),
                $config['collector_device_id_hash'],
                (string)$sourceId
            );
            self::assertSame(1, $config['collector_user_id'], (string)$sourceId);
            self::assertSame(80, $config['collector_tenant_id'], (string)$sourceId);
            self::assertSame(80, $config['collector_hotel_id'], (string)$sourceId);
        }
        self::assertSame('ctrip', $ctrip['collector_platform']);
        self::assertSame('meituan', $meituan['collector_platform']);
    }

    public function testDifferentExistingDeviceRequiresExplicitRotationAndRollsBackEverySource(): void
    {
        $first = $this->source(25, 'ctrip', [
            'stable_profile_id' => 'ctrip-profile',
            'platform_hotel_id' => 'ctrip-h80',
        ]);
        $second = $this->source(68, 'meituan', [
            'platform_hotel_id' => 'meituan-h80',
            'source_method' => 'single_user_local',
            'collector_device_id' => 'old-device',
            'collector_device_id_hash' => hash('sha256', 'old-device'),
        ]);
        foreach ([$first, $second] as $source) {
            Db::name('platform_data_sources')->insert([
                'id' => $source['id'],
                'tenant_id' => $source['tenant_id'],
                'user_id' => $source['user_id'],
                'system_hotel_id' => $source['system_hotel_id'],
                'platform' => $source['platform'],
                'ingestion_method' => $source['ingestion_method'],
                'enabled' => $source['enabled'],
                'status' => $source['status'],
                'config_json' => $source['config_json'],
            ]);
        }

        try {
            $this->bind([$first, $second], $this->scope('replacement-device'), false);
            self::fail('Expected a different existing device binding to be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('rotate-cloud-device-binding', $e->getMessage());
        }

        self::assertArrayNotHasKey('source_method', $this->storedConfig(25));
        self::assertSame('old-device', $this->storedConfig(68)['collector_device_id']);

        $this->bind([$first, $second], $this->scope('replacement-device'), true);
        self::assertSame('replacement-device', $this->storedConfig(25)['collector_device_id']);
        self::assertSame('replacement-device', $this->storedConfig(68)['collector_device_id']);
    }

    public function testConfirmedUnbindRemovesOnlyCollectorBindingMetadata(): void
    {
        $sources = [
            $this->source(25, 'ctrip', [
                'stable_profile_id' => 'ctrip-profile',
                'platform_hotel_id' => 'ctrip-h80',
            ]),
            $this->source(68, 'meituan', [
                'store_id' => 'meituan-store',
                'platform_hotel_id' => 'meituan-h80',
            ]),
        ];
        foreach ($sources as $source) {
            Db::name('platform_data_sources')->insert([
                'id' => $source['id'],
                'tenant_id' => $source['tenant_id'],
                'user_id' => $source['user_id'],
                'system_hotel_id' => $source['system_hotel_id'],
                'platform' => $source['platform'],
                'ingestion_method' => $source['ingestion_method'],
                'enabled' => $source['enabled'],
                'status' => $source['status'],
                'config_json' => $source['config_json'],
            ]);
        }
        $scope = $this->scope('server-owner-device');
        $this->bind($sources, $scope, false);
        foreach ($sources as &$source) {
            $source['config_json'] = (string)Db::name('platform_data_sources')
                ->where('id', $source['id'])
                ->value('config_json');
        }
        unset($source);

        $command = new AutoFetchOnlineData();
        (new \ReflectionProperty($command, 'cloudCollectorScope'))->setValue($command, $scope);
        (new \ReflectionMethod($command, 'unbindCloudCollectorSources'))
            ->invoke($command, $sources);

        $ctrip = $this->storedConfig(25);
        $meituan = $this->storedConfig(68);
        self::assertSame('ctrip-profile', $ctrip['stable_profile_id']);
        self::assertSame('meituan-store', $meituan['store_id']);
        foreach ([$ctrip, $meituan] as $config) {
            self::assertArrayNotHasKey('source_method', $config);
            self::assertArrayNotHasKey('collector_device_id', $config);
            self::assertArrayNotHasKey('collector_device_id_hash', $config);
            self::assertArrayNotHasKey('collector_user_id', $config);
            self::assertArrayNotHasKey('collector_hotel_id', $config);
        }
    }

    public function testCrossHotelCanonicalPlatformIdentityConflictBlocksWithoutWrite(): void
    {
        $source = $this->source(25, 'ctrip', [
            'stable_profile_id' => 'ctrip-profile',
            'platform_hotel_id' => 'ctrip-h80',
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => $source['id'],
            'tenant_id' => $source['tenant_id'],
            'user_id' => $source['user_id'],
            'system_hotel_id' => $source['system_hotel_id'],
            'platform' => $source['platform'],
            'ingestion_method' => $source['ingestion_method'],
            'enabled' => $source['enabled'],
            'status' => $source['status'],
            'config_json' => $source['config_json'],
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 99,
            'tenant_id' => 9,
            'user_id' => 2,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'platform_hotel_id' => 'ctrip-h80',
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $this->bind([$source], $this->scope('server-owner-device'), false);
            self::fail('Expected a cross-hotel canonical platform identity conflict.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(
                'already bound to another tenant or system hotel',
                $e->getMessage()
            );
        }

        $stored = $this->storedConfig(25);
        self::assertArrayNotHasKey('source_method', $stored);
        self::assertSame('ctrip-h80', $stored['platform_hotel_id']);
    }

    public function testCloudFailureReasonChainKeepsOnlySafeMachineCodes(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'safeExceptionReasonCodes');
        $error = new RuntimeException(
            'cloud_ota_profile_collection_failed',
            0,
            new RuntimeException(
                'cloud_ota_profile_gateway_failed',
                0,
                new RuntimeException('unsafe message with token=secret')
            )
        );

        self::assertSame([
            'cloud_ota_profile_collection_failed',
            'cloud_ota_profile_gateway_failed',
        ], $method->invoke($command, $error));
    }

    /** @return array<string, mixed> */
    private function source(int $id, string $platform, array $config): array
    {
        return [
            'id' => $id,
            'tenant_id' => 80,
            'user_id' => 1,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function scope(string $deviceId): array
    {
        return [
            'mode' => 'single_user_local',
            'device_id' => $deviceId,
            'device_id_hash' => hash('sha256', $deviceId),
            'user_id' => 1,
            'tenant_id' => 80,
            'hotel_id' => 80,
            'source_ids' => [25, 68],
            'platforms' => ['ctrip', 'meituan'],
        ];
    }

    /** @param array<int, array<string, mixed>> $sources @param array<string, mixed> $scope */
    private function bind(array $sources, array $scope, bool $allowRotation): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'bindCloudCollectorSources');
        $method->invoke($command, $sources, $scope, $allowRotation);
    }

    /** @return array<string, mixed> */
    private function storedConfig(int $sourceId): array
    {
        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'),
            true
        );
        return is_array($config) ? $config : [];
    }
}
