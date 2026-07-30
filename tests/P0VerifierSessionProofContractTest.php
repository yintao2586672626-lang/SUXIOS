<?php
declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class P0VerifierSessionProofContractTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p0_verifier_session_proof_' . getmypid() . '.sqlite';

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

        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE ota_profile_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_key_hash TEXT NOT NULL,
            binding_status TEXT NOT NULL
        )');
        Db::name('hotels')->insert(['id' => 701, 'tenant_id' => 70]);
        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => 70,
            'system_hotel_id' => 701,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', 'profile-701'),
            'binding_status' => 'active',
        ]);
    }

    public function testVerifierDelegatesCurrentSessionDecisionToTheRuntimeProofService(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        $method = $this->extractFunctionDefinition($verifier, 'p0_traffic_current_session_verified');

        self::assertStringContainsString('OtaProfileSessionProofService', $method);
        self::assertStringContainsString('->isCurrentVerified($source)', $method);
        self::assertStringNotContainsString("['verified', 'logged_in', 'ready']", $method);
    }

    public function testVerifierFailsClosedUsingTheFullRuntimeProofContract(): void
    {
        $this->loadVerifierHelpers();
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $today = $now->format('Y-m-d');
        $row = [
            'id' => 901,
            'tenant_id' => 70,
            'system_hotel_id' => 701,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
        ];
        $config = [
            'profile_id' => 'profile-701',
            'current_session_probe_performed' => true,
            'current_session_verified' => true,
            'current_session_status' => 'verified',
            'current_session_probe_at' => $now->format('Y-m-d H:i:s'),
            'current_session_probe_data_source_id' => 901,
            'current_session_probe_date' => $today,
            'current_session_probe_timezone' => 'Asia/Shanghai',
            'current_session_probe_platform' => 'ctrip',
            'current_session_probe_tenant_id' => 70,
            'current_session_probe_system_hotel_id' => 701,
            'current_session_probe_profile_key_hash' => hash('sha256', 'profile-701'),
            'current_session_probe_scope' => 'same_data_source_profile_session',
            'current_session_probe_producer' => 'platform_profile_login_task',
            'current_session_probe_contract_version' => '2026-07-19.1',
            'current_session_probe_evidence_level' => 'strong',
            'current_session_probe_evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'current_session_probe_identity_status' => 'matched',
        ];

        self::assertTrue(p0_traffic_current_session_verified($row, $config));

        foreach ([
            'logged_in_status' => ['current_session_status' => 'logged_in'],
            'ready_status' => ['current_session_status' => 'ready'],
            'string_true_flag' => ['current_session_verified' => '1'],
            'wrong_tenant' => ['current_session_probe_tenant_id' => 71],
            'wrong_hotel' => ['current_session_probe_system_hotel_id' => 702],
            'wrong_platform' => ['current_session_probe_platform' => 'meituan'],
            'wrong_timezone' => ['current_session_probe_timezone' => 'UTC'],
            'wrong_scope' => ['current_session_probe_scope' => 'historical_metadata'],
            'wrong_producer' => ['current_session_probe_producer' => 'manual_patch'],
            'wrong_profile_hash' => ['current_session_probe_profile_key_hash' => hash('sha256', 'other-profile')],
            'missing_probe_date' => ['current_session_probe_date' => null],
        ] as $case => $patch) {
            self::assertFalse(
                p0_traffic_current_session_verified($row, array_replace($config, $patch)),
                $case
            );
        }

        Db::name('ota_profile_bindings')->where('id', 1)->update(['binding_status' => 'revoked']);
        self::assertFalse(p0_traffic_current_session_verified($row, $config), 'active binding is required');
    }

    private function loadVerifierHelpers(): void
    {
        $verifier = (string)file_get_contents(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'verify_p0_ota_field_loop_closure.php');
        foreach (['p0_truthy_config_value', 'p0_traffic_current_session_verified'] as $functionName) {
            if (function_exists(__NAMESPACE__ . '\\' . $functionName)) {
                continue;
            }
            $definition = $this->extractFunctionDefinition($verifier, $functionName);
            self::assertNotSame('', $definition, 'Missing verifier helper: ' . $functionName);
            eval($definition);
        }
    }

    private function extractFunctionDefinition(string $source, string $functionName): string
    {
        $start = strpos($source, 'function ' . $functionName . '(');
        if (!is_int($start)) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if (!is_int($brace)) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);
        for ($index = $brace; $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }
        return '';
    }
}
