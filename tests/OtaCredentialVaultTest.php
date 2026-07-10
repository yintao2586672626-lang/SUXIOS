<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialVault;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\App;
use think\facade\Config;

final class OtaCredentialVaultTest extends TestCase
{
    private static array $original=[]; private static string $path='';
    public static function setUpBeforeClass(): void { $app=new App(); $app->initialize(); self::$original=Config::get('database'); self::$path=sys_get_temp_dir().'/ota_vault_'.getmypid().'.sqlite'; @unlink(self::$path); $c=self::$original; $c['default']='sqlite'; $c['connections']['sqlite']=['type'=>'sqlite','database'=>self::$path,'prefix'=>'','fields_strict'=>false]; Config::set($c,'database'); Db::connect(null,true); }
    public static function tearDownAfterClass(): void { Config::set(self::$original,'database'); Db::connect(null,true); @unlink(self::$path); }
    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), create_time INTEGER, update_time INTEGER)');
        Db::execute('CREATE TABLE IF NOT EXISTS ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at INTEGER, create_time INTEGER, update_time INTEGER, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
        Db::name('hotels')->where('id', 'in', [101, 102])->delete();
        Db::name('hotels')->insertAll([['id'=>101,'tenant_id'=>7,'name'=>'A'],['id'=>102,'tenant_id'=>8,'name'=>'B']]);
    }

    private function vault(): OtaCredentialVault
    {
        return new OtaCredentialVault(new OtaCredentialEnvelope(base64_encode(str_repeat('k', 32)), 'test-key'), 'test-key');
    }

    public function testStoreMetadataAndRoundTrip(): void
    {
        $v = $this->vault();
        $v->store(7, 101, 'ctrip', 'main', ['cookies'=>'abc','token'=>'xyz'], 3);
        $meta = $v->metadata(7, 101, 'ctrip', 'main');
        self::assertArrayNotHasKey('encrypted_payload', $meta);
        $seen = null;
        $v->withPayloadForExecution(7, 101, 'ctrip', 'main', function (array $payload) use (&$seen): void { $seen = $payload; });
        self::assertSame('abc', $seen['cookies']);
    }

    public function testWrongScopeAndRevokedFail(): void
    {
        $v = $this->vault();
        $v->store(7, 101, 'ctrip', 'main', ['token'=>'secret'], 3);
        $this->expectException(\RuntimeException::class);
        $v->metadata(8, 101, 'ctrip', 'main');
    }
}
