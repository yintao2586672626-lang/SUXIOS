<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialAuditTrail;
use app\service\OtaCredentialVault;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\App;
use think\facade\Config;
use think\Request;

final class OtaCredentialVaultTest extends TestCase
{
    private static array $original=[]; private static string $path=''; private static App $app;
    public static function setUpBeforeClass(): void { self::$app=new App(); self::$app->initialize(); self::$original=Config::get('database'); self::$path=sys_get_temp_dir().'/ota_vault_'.getmypid().'.sqlite'; @unlink(self::$path); $c=self::$original; $c['default']='sqlite'; $c['connections']['sqlite']=['type'=>'sqlite','database'=>self::$path,'prefix'=>'','fields_strict'=>false]; Config::set($c,'database'); Db::connect(null,true); }
    public static function tearDownAfterClass(): void { Config::set(self::$original,'database'); Db::connect(null,true); @unlink(self::$path); }
    protected function setUp(): void
    {
        parent::setUp();
        $this->bindHttpActor(null);
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100), create_time INTEGER, update_time INTEGER)');
        Db::execute('CREATE TABLE IF NOT EXISTS ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at DATETIME, last_used_at DATETIME, revoked_at DATETIME, create_time DATETIME, update_time DATETIME, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
        Db::execute('CREATE TABLE IF NOT EXISTS ota_credential_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER NOT NULL DEFAULT 0, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id_hash VARCHAR(64) NOT NULL, event_sequence INTEGER NOT NULL, credential_version INTEGER NOT NULL DEFAULT 0, event_type VARCHAR(40) NOT NULL, outcome VARCHAR(20) NOT NULL, failure_code VARCHAR(80) NOT NULL DEFAULT \'\', actor_id INTEGER NOT NULL DEFAULT 0, payload_digest VARCHAR(64) NOT NULL DEFAULT \'\', previous_entry_hash VARCHAR(64) NOT NULL DEFAULT \'\', entry_hash VARCHAR(64) NOT NULL, occurred_at DATETIME NOT NULL, UNIQUE(tenant_id,system_hotel_id,platform,config_id_hash,event_sequence), UNIQUE(entry_hash))');
        Db::execute('DELETE FROM ota_credentials');
        Db::execute('DELETE FROM ota_credential_audit_logs');
        Db::name('hotels')->where('id', 'in', [101, 102])->delete();
        Db::name('hotels')->insertAll([['id'=>101,'tenant_id'=>7,'name'=>'A'],['id'=>102,'tenant_id'=>8,'name'=>'B']]);
    }

    private function vault(): OtaCredentialVault
    {
        return new OtaCredentialVault(new OtaCredentialEnvelope(base64_encode(str_repeat('k', 32)), 'test-key'), 'test-key');
    }

    private function bindHttpActor(?User $actor): void
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->user = $actor;
        self::$app->instance('request', $request);
    }

    public function testInternalHttpExecutionWithoutUserUsesExplicitTenantScope(): void
    {
        $vault = $this->vault();
        $stored = $vault->store(7, 101, 'ctrip', 'internal-http', ['token' => 'secret'], 3);

        self::assertSame(7, $stored['tenant_id']);
        self::assertSame('secret', $vault->withPayloadForExecution(
            7,
            101,
            'ctrip',
            'internal-http',
            static fn(array $payload): string => $payload['token']
        ));
        self::assertTrue($vault->delete(7, 101, 'ctrip', 'internal-http'));
    }

    public function testSuperAdminVaultOperationsUseExplicitTenantScope(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        $this->bindHttpActor($admin);

        $vault = $this->vault();
        $stored = $vault->store(8, 102, 'meituan', 'superadmin', ['token' => 'secret'], 1);

        self::assertSame(8, $stored['tenant_id']);
        self::assertSame(102, $stored['system_hotel_id']);
        self::assertSame('ready', $vault->metadata(8, 102, 'meituan', 'superadmin')['credential_status']);
        self::assertTrue($vault->delete(8, 102, 'meituan', 'superadmin'));
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
        self::assertNotEmpty(Db::name('ota_credentials')->where('config_id', 'main')->value('last_used_at'));
    }

    public function testAccessSuccessAndFailureUseDedicatedSanitizedAuditTrail(): void
    {
        $vault = $this->vault();
        $vault->store(7, 101, 'ctrip', 'audit-main', ['token' => 'TOP-SECRET'], 3);
        self::assertSame('TOP-SECRET', $vault->withPayloadForExecution(
            7,
            101,
            'ctrip',
            'audit-main',
            static fn(array $payload): string => (string)$payload['token']
        ));

        Db::name('ota_credentials')->where('config_id', 'audit-main')->update([
            'encrypted_payload' => 'tampered',
        ]);
        try {
            $vault->withPayloadForExecution(7, 101, 'ctrip', 'audit-main', static fn(): null => null);
            self::fail('Tampered ciphertext must fail.');
        } catch (\RuntimeException) {
        }

        $rows = Db::name('ota_credential_audit_logs')
            ->where('config_id_hash', hash('sha256', 'audit-main'))
            ->order('event_sequence', 'asc')
            ->select()
            ->toArray();
        self::assertSame(
            [
                'credential_created',
                'execution_access_started',
                'execution_access',
                'execution_access_started',
                'execution_access',
            ],
            array_column($rows, 'event_type')
        );
        self::assertSame(
            ['success', 'success', 'success', 'success', 'failed'],
            array_column($rows, 'outcome')
        );
        self::assertSame('decrypt_failed', $rows[4]['failure_code']);

        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TOP-SECRET', $encoded);
        self::assertStringNotContainsString('audit-main', $encoded);
        self::assertStringNotContainsString('tampered', $encoded);
    }

    public function testRotationAuditBuildsTamperEvidentCredentialVersionChain(): void
    {
        $vault = $this->vault();
        $first = $vault->store(7, 101, 'ctrip', 'versioned', ['token' => 'first'], 3);
        $second = $vault->store(7, 101, 'ctrip', 'versioned', ['token' => 'second'], 4);

        self::assertSame($first['credential_ref'], $second['credential_ref']);
        $rows = Db::name('ota_credential_audit_logs')
            ->where('credential_id', (int)$first['credential_ref'])
            ->whereIn('event_type', ['credential_created', 'credential_rotated'])
            ->order('event_sequence', 'asc')
            ->select()
            ->toArray();

        self::assertSame(['credential_created', 'credential_rotated'], array_column($rows, 'event_type'));
        self::assertSame([1, 2], array_map('intval', array_column($rows, 'credential_version')));
        self::assertSame('', $rows[0]['previous_entry_hash']);
        self::assertSame($rows[0]['entry_hash'], $rows[1]['previous_entry_hash']);
        self::assertNotSame($rows[0]['payload_digest'], $rows[1]['payload_digest']);
        self::assertTrue((new OtaCredentialAuditTrail())->verifyScopeChain(7, 101, 'ctrip', 'versioned'));

        Db::name('ota_credential_audit_logs')->where('id', (int)$rows[0]['id'])->update([
            'credential_version' => 99,
        ]);
        self::assertFalse((new OtaCredentialAuditTrail())->verifyScopeChain(7, 101, 'ctrip', 'versioned'));
    }

    public function testCredentialVersionChainContinuesAfterDeleteAndRecreate(): void
    {
        $vault = $this->vault();
        $audit = new OtaCredentialAuditTrail();

        $first = $vault->store(7, 101, 'ctrip', 'recreated', ['token' => 'first-secret'], 3);
        self::assertTrue($vault->delete(7, 101, 'ctrip', 'recreated'));
        $second = $vault->store(7, 101, 'ctrip', 'recreated', ['token' => 'second-secret'], 4);

        self::assertNotSame($first['credential_ref'], $second['credential_ref']);
        self::assertSame(
            [1, 2],
            array_map(
                'intval',
                Db::name('ota_credential_audit_logs')
                    ->where('config_id_hash', hash('sha256', 'recreated'))
                    ->whereIn('event_type', ['credential_created', 'credential_rotated'])
                    ->order('event_sequence', 'asc')
                    ->column('credential_version')
            )
        );
        self::assertTrue($audit->verifyScopeChain(7, 101, 'ctrip', 'recreated'));
    }

    public function testRotationAndVersionAuditRollBackTogetherWhenVersionAppendFails(): void
    {
        $vault = $this->vault();
        $stored = $vault->store(7, 101, 'ctrip', 'atomic-rotation', ['token' => 'old'], 3);
        $beforeCiphertext = (string)Db::name('ota_credentials')
            ->where('id', (int)$stored['credential_ref'])
            ->value('encrypted_payload');

        Db::execute(<<<'SQL'
CREATE TRIGGER fail_credential_rotation_audit
BEFORE INSERT ON ota_credential_audit_logs
WHEN NEW.event_type = 'credential_rotated'
BEGIN
    SELECT RAISE(ABORT, 'injected rotation audit failure');
END
SQL);
        try {
            $vault->store(7, 101, 'ctrip', 'atomic-rotation', ['token' => 'new'], 4);
            self::fail('Rotation must fail closed when its version audit cannot append.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not committed', $error->getMessage());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_credential_rotation_audit');
        }

        self::assertSame(
            $beforeCiphertext,
            (string)Db::name('ota_credentials')
                ->where('id', (int)$stored['credential_ref'])
                ->value('encrypted_payload')
        );
        self::assertSame(
            'old',
            $vault->withPayloadForExecution(
                7,
                101,
                'ctrip',
                'atomic-rotation',
                static fn(array $payload): string => (string)$payload['token']
            )
        );
        self::assertSame(1, (int)Db::name('ota_credential_audit_logs')
            ->where('credential_id', (int)$stored['credential_ref'])
            ->whereIn('event_type', ['credential_created', 'credential_rotated'])
            ->count());
        self::assertSame(1, (int)Db::name('ota_credential_audit_logs')
            ->where('credential_id', (int)$stored['credential_ref'])
            ->where('event_type', 'credential_store')
            ->where('outcome', 'failed')
            ->where('failure_code', 'audit_write_failed')
            ->count());
        self::assertTrue((new OtaCredentialAuditTrail())->verifyScopeChain(
            7,
            101,
            'ctrip',
            'atomic-rotation'
        ));
    }

    public function testMetadataVerificationDoesNotRefreshLastUsedAt(): void
    {
        $vault = $this->vault();
        $vault->store(7, 101, 'ctrip', 'verify-only', ['token' => 'secret'], 3);

        $metadata = $vault->verifiedMetadataForExecution(7, 101, 'ctrip', 'verify-only');

        self::assertSame('ready', $metadata['credential_status']);
        self::assertNull(Db::name('ota_credentials')->where('config_id', 'verify-only')->value('last_used_at'));
    }

    public function testWrongScopeAndRevokedFail(): void
    {
        $v = $this->vault();
        $v->store(7, 101, 'ctrip', 'main', ['token'=>'secret'], 3);
        $this->expectException(\RuntimeException::class);
        $v->metadata(8, 101, 'ctrip', 'main');
    }

    public function testInvalidPlatformAndConfigAreRejected(): void { $this->expectException(\RuntimeException::class); $this->vault()->store(7, 101, 'other', 'main', [], 3); }
    public function testMissingHotelIsRejected(): void { $this->expectException(\RuntimeException::class); $this->vault()->store(7, 999, 'ctrip', 'main', [], 3); }
    public function testWrongTenantIsRejected(): void { $this->expectException(\RuntimeException::class); $this->vault()->store(8, 101, 'ctrip', 'main', [], 3); }
    public function testUpdateSameScopeKeepsUniqueRow(): void { $v=$this->vault(); $a=$v->store(7,101,'ctrip','main',['token'=>'a'],3); $b=$v->store(7,101,'ctrip','main',['token'=>'b'],4); self::assertSame($a['credential_ref'],$b['credential_ref']); self::assertSame('b',$v->withPayloadForExecution(7,101,'ctrip','main',fn(array $p)=>$p['token'])); }
    public function testDifferentConfigLocatorsRemainIndependentlyExecutable(): void
    {
        $vault = $this->vault();
        $vault->store(7, 101, 'ctrip', 'old-config', ['token' => 'old'], 3);
        $vault->store(7, 101, 'ctrip', 'new-config', ['token' => 'new'], 3);

        self::assertSame('ready', $vault->metadata(7, 101, 'ctrip', 'old-config')['credential_status']);
        self::assertSame('ready', $vault->metadata(7, 101, 'ctrip', 'new-config')['credential_status']);
        self::assertSame(2, (int)Db::name('ota_credentials')
            ->where('tenant_id', 7)
            ->where('system_hotel_id', 101)
            ->where('platform', 'ctrip')
            ->whereIn('config_id', ['old-config', 'new-config'])
            ->where('credential_status', 'ready')
            ->count());
        self::assertSame('old', $vault->withPayloadForExecution(7, 101, 'ctrip', 'old-config', fn(array $payload): string => $payload['token']));
        self::assertSame('new', $vault->withPayloadForExecution(7, 101, 'ctrip', 'new-config', fn(array $payload): string => $payload['token']));
    }
    public function testVaultDoesNotApplyForUpdateLockToUpdateStatements(): void
    {
        $source = file_get_contents(__DIR__ . '/../app/service/OtaCredentialVault.php');

        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression(
            '/->lock\(true\)\s*->update\s*\(/s',
            $source,
            'MariaDB rejects UPDATE statements with a trailing FOR UPDATE clause.'
        );
    }
    public function testMetadataIncludesKeyAndStatus(): void { $m=$this->vault()->store(7,101,'ctrip','main',['token'=>'secret'],3); self::assertSame('test-key',$m['key_id']); self::assertSame('ready',$m['credential_status']); }
    public function testTamperedCiphertextFails(): void { $v=$this->vault(); $v->store(7,101,'ctrip','main',['token'=>'secret'],3); Db::name('ota_credentials')->where('tenant_id',7)->update(['encrypted_payload'=>'tampered']); $this->expectException(\RuntimeException::class); $v->withPayloadForExecution(7,101,'ctrip','main',fn()=>null); }
    public function testRevokeIsIdempotentAndClearsCiphertext(): void
    {
        $vault = $this->vault();
        $vault->store(7, 101, 'ctrip', 'main', ['token' => 'secret'], 3);

        self::assertSame('revoked', $vault->revoke(7, 101, 'ctrip', 'main')['credential_status']);
        $firstRevokedAt = Db::name('ota_credentials')->where('config_id', 'main')->value('revoked_at');
        self::assertNotEmpty($firstRevokedAt);
        self::assertSame('', Db::name('ota_credentials')->where('config_id', 'main')->value('encrypted_payload'));
        self::assertSame('', Db::name('ota_credentials')->where('config_id', 'main')->value('secret_mask'));

        self::assertSame('revoked', $vault->revoke(7, 101, 'ctrip', 'main')['credential_status']);
        self::assertSame($firstRevokedAt, Db::name('ota_credentials')->where('config_id', 'main')->value('revoked_at'));
        self::assertSame('revoked', $vault->metadata(7, 101, 'ctrip', 'main')['credential_status']);
    }

    public function testStoreReactivatesRevokedCredentialWithoutCreatingAnotherRow(): void
    {
        $vault = $this->vault();
        $first = $vault->store(7, 101, 'ctrip', 'reactivate', ['token' => 'old'], 3);
        $vault->revoke(7, 101, 'ctrip', 'reactivate');

        $second = $vault->store(7, 101, 'ctrip', 'reactivate', ['token' => 'new'], 4);

        self::assertSame($first['credential_ref'], $second['credential_ref']);
        self::assertSame('ready', $second['credential_status']);
        self::assertNull(Db::name('ota_credentials')->where('config_id', 'reactivate')->value('revoked_at'));
        self::assertNull(Db::name('ota_credentials')->where('config_id', 'reactivate')->value('last_used_at'));
        self::assertSame('new', $vault->withPayloadForExecution(7, 101, 'ctrip', 'reactivate', fn(array $payload): string => $payload['token']));
    }
    public function testRevokedExecutionIsBlocked(): void { $v=$this->vault(); $v->store(7,101,'ctrip','main',['token'=>'secret'],3); $v->revoke(7,101,'ctrip','main'); $this->expectException(\RuntimeException::class); $v->withPayloadForExecution(7,101,'ctrip','main',fn()=>null); }
    public function testEveryNonReadyCredentialStatusIsBlockedFromExecution(): void
    {
        $vault = $this->vault();
        foreach (['unknown', 'expired', 'invalid'] as $status) {
            $configId = 'status-' . $status;
            $vault->store(7, 101, 'ctrip', $configId, ['token' => 'secret'], 3);
            Db::name('ota_credentials')
                ->where('tenant_id', 7)
                ->where('system_hotel_id', 101)
                ->where('platform', 'ctrip')
                ->where('config_id', $configId)
                ->update(['credential_status' => $status]);

            $exception = null;
            try {
                $vault->withPayloadForExecution(7, 101, 'ctrip', $configId, fn(array $payload): array => $payload);
            } catch (\RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(\RuntimeException::class, $exception, "Credential status {$status} must not be executable.");
            self::assertSame('Credential is not ready for execution.', $exception->getMessage());
        }
    }
    public function testExecutionRejectsMismatchedCryptographicMetadata(): void
    {
        $vault = $this->vault();
        foreach ([
            'wrong-key' => ['key_id' => 'retired-key'],
            'wrong-version' => ['payload_version' => 2],
        ] as $configId => $mutation) {
            $vault->store(7, 101, 'ctrip', $configId, ['token' => 'secret'], 3);
            Db::name('ota_credentials')
                ->where('tenant_id', 7)
                ->where('system_hotel_id', 101)
                ->where('platform', 'ctrip')
                ->where('config_id', $configId)
                ->update($mutation);

            $exception = null;
            try {
                $vault->withPayloadForExecution(7, 101, 'ctrip', $configId, fn(array $payload): array => $payload);
            } catch (\RuntimeException $caught) {
                $exception = $caught;
            }

            self::assertInstanceOf(\RuntimeException::class, $exception, "{$configId} must not be executable.");
            self::assertSame('Credential cryptographic metadata is not executable.', $exception->getMessage());
        }
    }
    public function testDeleteRevoked(): void { $v=$this->vault(); $v->store(7,101,'ctrip','main',['token'=>'secret'],3); $v->revoke(7,101,'ctrip','main'); self::assertTrue($v->delete(7,101,'ctrip','main')); }
    public function testDeleteIsIdempotent(): void { $v=$this->vault(); $v->store(7,101,'ctrip','main',['token'=>'secret'],3); self::assertTrue($v->delete(7,101,'ctrip','main')); self::assertFalse($v->delete(7,101,'ctrip','main')); }
}
