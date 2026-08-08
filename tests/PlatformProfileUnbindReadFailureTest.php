<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\AutoFetchConcern;
use app\controller\concern\OnlineDataRequestConcern;
use app\controller\concern\OtaConfigConcern;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class PlatformProfileUnbindReadFailureTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'platform_profile_unbind_read_failure_' . getmypid() . '.sqlite';
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
        self::createSourceTable();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove Profile unbind SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('platform_data_sources')->delete(true);
        Db::name('platform_data_sources')->insert($this->sourceRow());
    }

    public function testProfileKeyUnbindReadFailureIsTypedAndPreservesStatusCache(): void
    {
        $harness = new PlatformProfileUnbindReadFailureHarness([
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'profile_key' => 'ctrip-profile-80',
        ]);
        $harness->primeSourceListCache(80, 'ctrip', []);
        $statusCacheKey = $harness->statusCacheKey('ctrip', 80, 'ctrip-profile-80');
        $statusMarker = ['auth_status' => 'logged_in', 'checked_at' => '2026-08-05 09:00:00'];
        cache($statusCacheKey, $statusMarker, 60);

        $this->renameSourceTable('platform_data_sources_unavailable');
        try {
            $response = $harness->deletePlatformProfileBinding();
            $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(503, $response->getCode());
            self::assertSame(503, $payload['code']);
            self::assertSame('browser_profile_source_read_failed', $payload['message']);
            self::assertSame('browser_profile_source_read_failed', $payload['data']['reason_code']);
            self::assertSame('platform_data_sources_read', $payload['data']['stage']);
            self::assertTrue($payload['data']['retryable']);
            self::assertSame($statusMarker, cache($statusCacheKey));
        } finally {
            $this->restoreSourceTable('platform_data_sources_unavailable');
            cache($statusCacheKey, null);
        }

        $source = $harness->sourceForUnbind(80, 'ctrip', 'ctrip-profile-80');
        self::assertSame(25, (int)$source['id']);
        self::assertSame(80, (int)$source['system_hotel_id']);
        self::assertSame('ctrip', $source['platform']);
        self::assertSame(
            'ctrip-profile-80',
            json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR)['profile_id']
        );
    }

    public function testSourceIdUnbindReadFailureIsTypedInsteadOfMissingBinding(): void
    {
        $harness = new PlatformProfileUnbindReadFailureHarness([
            'platform' => 'ctrip',
            'system_hotel_id' => 80,
            'data_source_id' => 25,
        ]);

        $this->renameSourceTable('platform_data_sources_unavailable');
        try {
            $response = $harness->deletePlatformProfileBinding();
            $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(503, $response->getCode());
            self::assertSame('browser_profile_source_read_failed', $payload['data']['reason_code']);
        } finally {
            $this->restoreSourceTable('platform_data_sources_unavailable');
        }
    }

    public function testDataSourceIdLookupNeverTurnsReadFailureIntoZeroAndRecoversExactly(): void
    {
        $harness = new PlatformProfileUnbindReadFailureHarness([]);

        $this->renameSourceTable('platform_data_sources_unavailable');
        try {
            $harness->sourceId(80, 'ctrip', 'ctrip-profile-80');
            self::fail('Expected the failed platform_data_sources read to remain explicit.');
        } catch (RuntimeException $e) {
            self::assertSame('browser_profile_source_read_failed', $e->getMessage());
            self::assertSame(503, $e->getCode());
        } finally {
            $this->restoreSourceTable('platform_data_sources_unavailable');
        }

        self::assertSame(25, $harness->sourceId(80, 'ctrip', 'ctrip-profile-80'));
    }

    private static function createSourceTable(): void
    {
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, system_hotel_id INTEGER, '
            . 'platform TEXT, data_type TEXT, ingestion_method TEXT, config_json TEXT, '
            . 'enabled INTEGER, status TEXT, last_sync_status TEXT, last_error TEXT)'
        );
    }

    /** @return array<string, mixed> */
    private function sourceRow(): array
    {
        return [
            'id' => 25,
            'tenant_id' => 80,
            'name' => 'Ctrip Profile Source 80',
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'config_json' => json_encode([
                'profile_id' => 'ctrip-profile-80',
                'ota_hotel_id' => 'ctrip-hotel-80',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'enabled' => 1,
            'status' => 'ready',
            'last_sync_status' => 'success',
            'last_error' => '',
        ];
    }

    private function renameSourceTable(string $temporaryName): void
    {
        Db::execute('ALTER TABLE platform_data_sources RENAME TO ' . $temporaryName);
    }

    private function restoreSourceTable(string $temporaryName): void
    {
        Db::execute('ALTER TABLE ' . $temporaryName . ' RENAME TO platform_data_sources');
    }
}

final class PlatformProfileUnbindReadFailureHarness
{
    use AutoFetchConcern;
    use OtaConfigConcern;
    use OnlineDataRequestConcern;

    private const AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS = 5;

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $autoFetchLightReadCache = [];

    public object $currentUser;

    /** @param array<string, mixed> $requestData */
    public function __construct(private readonly array $requestData)
    {
        $this->currentUser = (object)['id' => 1];
    }

    public function sourceForUnbind(int $hotelId, string $platform, string $profileKey): ?array
    {
        return $this->findBrowserProfileDataSourceForUnbind($hotelId, $platform, $profileKey);
    }

    public function sourceId(int $hotelId, string $platform, string $profileKey): int
    {
        return $this->findBrowserProfileDataSourceId($hotelId, $platform, $profileKey);
    }

    /** @param array<int, array<string, mixed>> $sources */
    public function primeSourceListCache(int $hotelId, string $platform, array $sources): void
    {
        $cacheKey = $this->autoFetchLightProfileSourcesCacheKey($hotelId, $platform);
        $this->autoFetchLightReadCache[$cacheKey] = $sources;
        cache($cacheKey, $sources, 60);
    }

    public function statusCacheKey(string $platform, int $hotelId, string $profileKey): string
    {
        return $this->platformProfileStatusCacheKey($platform, $hotelId, $profileKey);
    }

    private function checkPermission(): void
    {
    }

    private function checkActionPermission(string $permission): void
    {
    }

    /** @param mixed $input */
    private function resolveOnlineDataSystemHotelId($input): ?int
    {
        return is_numeric($input) && (int)$input > 0 ? (int)$input : null;
    }

    /** @return array<string, mixed> */
    protected function requestData(): array
    {
        return $this->requestData;
    }

    /** @param mixed $data */
    protected function success($data = null, string $message = 'ok', int $code = 200): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
    }

    /** @param mixed $data */
    protected function error(string $message = 'error', int $code = 400, $data = null): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data], $code);
    }
}
