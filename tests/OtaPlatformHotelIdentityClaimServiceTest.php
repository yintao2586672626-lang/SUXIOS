<?php
declare(strict_types=1);

namespace tests;

use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaPlatformHotelIdentityClaimService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaPlatformHotelIdentityClaimServiceTest extends TestCase
{
    private const RAW_IDENTITY = 'MT-H80-SECRET';
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'ota_platform_hotel_identity_claim_' . getmypid() . '.sqlite';
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        $this->createSchema();
        $this->seedReadySource();
    }

    public function testPreflightIsReadyAndDoesNotExposeRawIdentityOrProfile(): void
    {
        $receipt = $this->service()->preflight(80, 80, 68);

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['claim_ready']);
        self::assertTrue($receipt['source_scope_verified']);
        self::assertSame(1, $receipt['identity_candidate_count']);
        self::assertSame('verified', $receipt['profile_binding']['status']);
        self::assertSame('verified', $receipt['current_session_proof']['status']);
        self::assertSame('verified', $receipt['ownership']['status']);
        self::assertTrue($receipt['write']['needed']);
        self::assertFalse($receipt['write']['attempted']);
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $receipt['receipt_digest']);

        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::RAW_IDENTITY, $json);
        self::assertStringNotContainsString('profile-root', $json);
        self::assertStringNotContainsString('cookie', strtolower($json));
        self::assertStringNotContainsString('token', strtolower($json));
    }

    public function testExecuteWritesOnlyFourClaimFieldsAndVerifiesExactReadback(): void
    {
        $beforeJson = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');
        $before = json_decode($beforeJson, true, 512, JSON_THROW_ON_ERROR);

        $receipt = $this->service()->execute(80, 80, 68);
        $afterJson = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');
        $after = json_decode($afterJson, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['claim_ready']);
        self::assertTrue($receipt['claimed']);
        self::assertSame(1, $receipt['write']['affected_rows']);
        self::assertTrue($receipt['write']['config_only']);
        self::assertTrue($receipt['write']['claim_fields_verified']);
        self::assertTrue($receipt['write']['preserved_fields_verified']);
        self::assertTrue($receipt['write']['readback_verified']);
        self::assertSame(self::RAW_IDENTITY, $after['platform_hotel_id']);
        self::assertSame('same_origin_profile_probe', $after['platform_hotel_identity_source']);
        self::assertSame('2026-08-11 09:15:30', $after['platform_hotel_identity_checked_at']);
        self::assertSame(self::RAW_IDENTITY, $after['current_session_probe_platform_hotel_id']);

        $expected = $before;
        $expected['platform_hotel_id'] = self::RAW_IDENTITY;
        $expected['platform_hotel_identity_source'] = 'same_origin_profile_probe';
        $expected['platform_hotel_identity_checked_at'] = '2026-08-11 09:15:30';
        $expected['current_session_probe_platform_hotel_id'] = self::RAW_IDENTITY;
        self::assertSame($expected, $after);

        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::RAW_IDENTITY, $json);
        self::assertStringNotContainsString('profile-root', $json);
    }

    public function testCandidateConflictBlocksWithoutWriting(): void
    {
        $config = $this->sourceConfig();
        $config['poi_id'] = 'DIFFERENT-MEITUAN-ID';
        $this->replaceConfig(68, $config);
        $before = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');

        $receipt = $this->service()->execute(80, 80, 68);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(
            ['canonical_identity_claim_candidate_conflict'],
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'));
    }

    public function testProofScopeDriftBlocksWithoutWriting(): void
    {
        $config = $this->sourceConfig();
        $config['current_session_probe_system_hotel_id'] = 81;
        $this->replaceConfig(68, $config);
        $before = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');

        $receipt = $this->service()->execute(80, 80, 68);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(
            ['canonical_identity_claim_proof_scope_drift'],
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'));
    }

    public function testCrossHotelOwnerBlocksWithoutWriting(): void
    {
        Db::name('platform_data_sources')->insert([
            'id' => 69,
            'tenant_id' => 80,
            'system_hotel_id' => 81,
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'store_id' => self::RAW_IDENTITY,
                'platform_hotel_id' => self::RAW_IDENTITY,
            ], JSON_THROW_ON_ERROR),
        ]);
        $before = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');

        $receipt = $this->service()->execute(80, 80, 68);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(
            ['canonical_identity_claim_cross_hotel_conflict'],
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'));
    }

    public function testExecuteIsIdempotentAfterVerifiedClaim(): void
    {
        $first = $this->service()->execute(80, 80, 68);
        $afterFirst = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');
        $second = $this->service()->execute(80, 80, 68);

        self::assertTrue($first['claimed']);
        self::assertSame('ready', $second['status']);
        self::assertTrue($second['claim_ready']);
        self::assertFalse($second['claimed']);
        self::assertTrue($second['already_canonical']);
        self::assertTrue($second['write']['idempotent']);
        self::assertSame(0, $second['write']['affected_rows']);
        self::assertTrue($second['write']['readback_verified']);
        self::assertSame($afterFirst, (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'));
    }

    public function testLaterSameIdentitySessionProofDoesNotInvalidateExistingCanonicalClaim(): void
    {
        $first = $this->service()->execute(80, 80, 68);
        self::assertTrue($first['claimed']);
        $config = $this->sourceConfig();
        $config['current_session_probe_at'] = '2026-08-11 09:45:00';
        $config['current_session_probe_date'] = '2026-08-11';
        $this->replaceConfig(68, $config);
        $before = (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json');

        $receipt = $this->service()->execute(80, 80, 68);

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['claim_ready']);
        self::assertTrue($receipt['already_canonical']);
        self::assertFalse($receipt['claimed']);
        self::assertSame(0, $receipt['write']['affected_rows']);
        self::assertTrue($receipt['write']['readback_verified']);
        self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'));
    }

    public function testCliDefaultsToPreflightAndRequiresExactExecuteConfirmation(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/claim_verified_ota_platform_hotel_identity.php'
        );

        self::assertStringContainsString("'execute' => false", $script);
        self::assertStringContainsString('executionConfirmation(', $script);
        self::assertStringContainsString('hash_equals($requiredConfirmation', $script);
        self::assertStringContainsString('canonical_identity_claim_confirmation_mismatch', $script);
    }

    private function service(): OtaPlatformHotelIdentityClaimService
    {
        return new OtaPlatformHotelIdentityClaimService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-11 10:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
    }

    /** @return array<string,mixed> */
    private function sourceConfig(): array
    {
        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', 68)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($config);
        return $config;
    }

    /** @param array<string,mixed> $config */
    private function replaceConfig(int $sourceId, array $config): void
    {
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    private function seedReadySource(): void
    {
        $profileKey = 'profile-root-80';
        $profileHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($profileKey));
        $config = [
            'profile_binding_key' => $profileKey,
            'store_id' => self::RAW_IDENTITY,
            'poi_id' => self::RAW_IDENTITY,
            'preserved_field' => ['nested' => true],
            'current_session_probe_performed' => true,
            'current_session_verified' => true,
            'current_session_status' => 'verified',
            'current_session_probe_at' => '2026-08-11 09:15:30',
            'current_session_probe_date' => '2026-08-11',
            'current_session_probe_timezone' => 'Asia/Shanghai',
            'current_session_probe_data_source_id' => 68,
            'current_session_probe_tenant_id' => 80,
            'current_session_probe_system_hotel_id' => 80,
            'current_session_probe_platform' => 'meituan',
            'current_session_probe_profile_key_hash' => $profileHash,
            'current_session_probe_scope' => 'same_data_source_profile_session',
            'current_session_probe_producer' => 'platform_data_sync_preflight',
            'current_session_probe_contract_version' => 'collection-preflight-v1',
            'current_session_probe_evidence_level' => 'strong',
            'current_session_probe_evidence_type' => 'successful_collection_preflight_identity_matched',
            'current_session_probe_identity_status' => 'matched',
        ];
        Db::name('platform_data_sources')->insert([
            'id' => 68,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
        Db::name('ota_profile_bindings')->insert([
            'id' => 17,
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'profile_key_hash' => $profileHash,
            'binding_status' => 'active',
        ]);
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            status TEXT NOT NULL,
            config_json TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE ota_profile_bindings (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_key_hash TEXT NOT NULL,
            binding_status TEXT NOT NULL
        )');
    }
}
