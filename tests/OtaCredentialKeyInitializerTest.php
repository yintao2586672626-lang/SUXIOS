<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialKeyInitializer;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Env;

final class OtaCredentialKeyInitializerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_key_initializer_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isFile() || $item->isLink()) {
                    @unlink($item->getPathname());
                }
            }
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testDryRunReportsMissingConfigurationWithoutCreatingOrChangingFile(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . '.env';

        $summary = $this->initializer()->run($path, false);

        self::assertSame([
            'mode' => 'dry-run',
            'status' => 'initialization_required',
            'configured' => false,
            'initialized' => false,
            'key_id' => null,
            'fingerprint' => null,
            'reason_code' => 'credentials_missing',
        ], $summary);
        self::assertFileDoesNotExist($path);
    }

    public function testExecuteInitializesEmptyAssignmentsAndPreservesOtherContentAndLineEndings(): void
    {
        $path = $this->envPath();
        $otherSecret = 'database-password-must-stay-private';
        $original = "APP_ENV = production\r\n"
            . "DB_PASS=\"{$otherSecret}\"\r\n"
            . "  OTA_CREDENTIAL_KEY_B64 = \"\"\r\n"
            . "OTA_CREDENTIAL_KEY_ID=   \r\n"
            . "# OTA_CREDENTIAL_KEY_B64=ignored-comment\r\n";
        file_put_contents($path, $original);

        $summary = $this->initializer()->run($path, true);

        self::assertSame('execute', $summary['mode']);
        self::assertSame('initialized', $summary['status']);
        self::assertTrue($summary['configured']);
        self::assertTrue($summary['initialized']);
        self::assertSame('ota-20260710-' . substr(hash('sha256', str_repeat('k', 32)), 0, 12), $summary['key_id']);
        self::assertSame(hash('sha256', str_repeat('k', 32)), $summary['fingerprint']);
        self::assertSame('credentials_initialized', $summary['reason_code']);

        $written = (string)file_get_contents($path);
        self::assertStringContainsString("APP_ENV = production\r\n", $written);
        self::assertStringContainsString("DB_PASS=\"{$otherSecret}\"\r\n", $written);
        self::assertStringContainsString("# OTA_CREDENTIAL_KEY_B64=ignored-comment\r\n", $written);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $written), 'The existing CRLF convention must be retained.');
        self::assertSame(1, preg_match('/^\s*OTA_CREDENTIAL_KEY_B64\s*=\s*["\']?([A-Za-z0-9+\/]+=*)["\']?/m', $written, $keyMatch));
        self::assertSame(str_repeat('k', 32), base64_decode($keyMatch[1], true));
        self::assertSame(1, preg_match('/^\s*OTA_CREDENTIAL_KEY_ID\s*=\s*["\']?([A-Za-z0-9._-]+)["\']?/m', $written, $idMatch));
        self::assertSame($summary['key_id'], $idMatch[1]);
        $this->assertThinkEnvLoadsCredentialPair($path, (string)$summary['key_id']);

        $encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(base64_encode(str_repeat('k', 32)), $encodedSummary);
        self::assertStringNotContainsString($otherSecret, $encodedSummary);
    }

    public function testExistingValidQuotedConfigurationIsReportedWithoutByteChanges(): void
    {
        $path = $this->envPath();
        $rawKey = str_repeat('v', 32);
        $original = "# ignored duplicate-looking comment\n"
            . " OTA_CREDENTIAL_KEY_B64 = \"" . base64_encode($rawKey) . "\"\n"
            . "OTA_CREDENTIAL_KEY_ID = \"ota-existing_1\"\n";
        file_put_contents($path, $original);

        $summary = $this->initializer()->run($path, true);

        self::assertSame('already_configured', $summary['status']);
        self::assertTrue($summary['configured']);
        self::assertFalse($summary['initialized']);
        self::assertSame('ota-existing_1', $summary['key_id']);
        self::assertSame(hash('sha256', $rawKey), $summary['fingerprint']);
        self::assertSame('credentials_already_configured', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        self::assertStringNotContainsString(base64_encode($rawKey), json_encode($summary, JSON_THROW_ON_ERROR));
        $this->assertThinkEnvLoadsCredentialPair($path, 'ota-existing_1');
    }

    public function testExecuteIsIdempotentAfterInitialization(): void
    {
        $path = $this->envPath();
        file_put_contents($path, "APP_ENV=local\n");
        $initializer = $this->initializer();

        $first = $initializer->run($path, true);
        $afterFirst = (string)file_get_contents($path);
        $second = $initializer->run($path, true);

        self::assertSame('initialized', $first['status']);
        self::assertSame('already_configured', $second['status']);
        self::assertSame($first['key_id'], $second['key_id']);
        self::assertSame($first['fingerprint'], $second['fingerprint']);
        self::assertSame($afterFirst, file_get_contents($path));
    }

    #[DataProvider('blockedConfigurationProvider')]
    public function testPartialMalformedAndDuplicateDefinitionsFailClosed(string $content, string $reasonCode): void
    {
        $path = $this->envPath();
        file_put_contents($path, $content);

        $summary = $this->initializer()->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertFalse($summary['configured']);
        self::assertFalse($summary['initialized']);
        self::assertSame($reasonCode, $summary['reason_code']);
        self::assertSame($content, file_get_contents($path));
        self::assertStringNotContainsString($content, json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public static function blockedConfigurationProvider(): array
    {
        $validKey = base64_encode(str_repeat('p', 32));

        return [
            'key only' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\n", 'partial_configuration'],
            'id only' => ["OTA_CREDENTIAL_KEY_ID=ota-partial\n", 'partial_configuration'],
            'key missing with empty id definition' => ["OTA_CREDENTIAL_KEY_ID=\n", 'partial_configuration'],
            'empty key definition with id missing' => ["OTA_CREDENTIAL_KEY_B64=''\n", 'partial_configuration'],
            'empty key with populated id' => ["OTA_CREDENTIAL_KEY_B64=\"\"\nOTA_CREDENTIAL_KEY_ID=ota-partial\n", 'partial_configuration'],
            'malformed key' => ["OTA_CREDENTIAL_KEY_B64=not-canonical-base64\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'malformed_configuration'],
            'wrong key length' => ["OTA_CREDENTIAL_KEY_B64=" . base64_encode('short') . "\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'malformed_configuration'],
            'malformed id' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_ID=bad id\n", 'malformed_configuration'],
            'duplicate key' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'duplicate_definition'],
            'duplicate id' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'duplicate_definition'],
            'export key definition' => ["export OTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'unsupported_definition'],
            'export id definition' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\nexport OTA_CREDENTIAL_KEY_ID=ota-valid\n", 'unsupported_definition'],
            'single quoted values are not Think Env compatible' => ["OTA_CREDENTIAL_KEY_B64='{$validKey}'\nOTA_CREDENTIAL_KEY_ID='ota-valid'\n", 'malformed_configuration'],
            'single quoted empty definitions are not Think Env compatible' => ["OTA_CREDENTIAL_KEY_B64=''\nOTA_CREDENTIAL_KEY_ID=''\n", 'malformed_configuration'],
            'lowercase target definition' => ["ota_credential_key_b64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'unsupported_definition'],
            'case variant duplicates canonical target' => ["OTA_CREDENTIAL_KEY_B64={$validKey}\nota_credential_key_b64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'unsupported_definition'],
            'canonical targets inside section' => ["[DATABASE]\nOTA_CREDENTIAL_KEY_B64={$validKey}\nOTA_CREDENTIAL_KEY_ID=ota-valid\n", 'unsupported_definition'],
        ];
    }

    public function testMissingTargetsAreInsertedBeforeFirstIniSectionAndLoadGlobally(): void
    {
        $path = $this->envPath();
        $original = "# database settings\r\n\r\n[DATABASE]\r\nHOST=127.0.0.1\r\n[APP]\r\nNAME=SUXIOS\r\n";
        file_put_contents($path, $original);

        $summary = $this->initializer()->run($path, true);

        self::assertSame('initialized', $summary['status'], json_encode($summary, JSON_THROW_ON_ERROR));
        $written = (string)file_get_contents($path);
        self::assertStringStartsWith("# database settings\r\n\r\n", $written);
        self::assertLessThan(strpos($written, '[DATABASE]'), strpos($written, 'OTA_CREDENTIAL_KEY_B64='));
        self::assertStringContainsString("[DATABASE]\r\nHOST=127.0.0.1\r\n[APP]\r\nNAME=SUXIOS\r\n", $written);
        $this->assertThinkEnvLoadsCredentialPair($path, (string)$summary['key_id']);
        $runtime = new Env();
        $runtime->load($path);
        self::assertSame('127.0.0.1', $runtime->get('DATABASE_HOST'));
        self::assertSame('SUXIOS', $runtime->get('APP_NAME'));
    }

    public function testInvalidIniBlocksBeforeAnySecretWrite(): void
    {
        $path = $this->envPath();
        $original = "APP_ENV=local\n[BROKEN\nVALUE=1\n";
        file_put_contents($path, $original);

        $summary = $this->initializer()->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame('env_runtime_validation_failed', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        $this->assertNoSecretTempArtifact();
    }

    public function testValidCredentialPairWithUnrelatedBrokenSectionFailsClosedInDryRunAndExecute(): void
    {
        $path = $this->envPath();
        $validKey = base64_encode(str_repeat('z', 32));
        $original = "OTA_CREDENTIAL_KEY_B64={$validKey}\n"
            . "OTA_CREDENTIAL_KEY_ID=ota-valid-existing\n"
            . "UNRELATED_RUNTIME_MARKER=must-not-load\n"
            . "[BROKEN\nVALUE=1\n";
        file_put_contents($path, $original);

        $dryRun = $this->initializer()->run($path, false);
        $afterDryRun = (string)file_get_contents($path);
        $execute = $this->initializer()->run($path, true);

        foreach ([$dryRun, $execute] as $summary) {
            self::assertSame('blocked', $summary['status']);
            self::assertSame('env_runtime_validation_failed', $summary['reason_code']);
            self::assertFalse($summary['configured']);
            self::assertFalse($summary['initialized']);
            self::assertStringNotContainsString($validKey, json_encode($summary, JSON_THROW_ON_ERROR));
        }
        self::assertSame($original, $afterDryRun);
        self::assertSame($original, file_get_contents($path));
        $runtime = new Env();
        @$runtime->load($path);
        self::assertNull($runtime->get('UNRELATED_RUNTIME_MARKER'));
        $this->assertNoSecretTempArtifact(false);
    }

    public function testShortWriteFailureLeavesOriginalBytesAndNoSecretTempArtifact(): void
    {
        $path = $this->envPath();
        $original = "APP_ENV=short-write-test\n";
        file_put_contents($path, $original);
        $writeCalls = 0;
        $initializer = $this->initializer(
            writeChunk: static function ($handle, string $chunk) use (&$writeCalls): int|false {
                $writeCalls++;
                if ($writeCalls > 1) {
                    return false;
                }
                return fwrite($handle, substr($chunk, 0, max(1, intdiv(strlen($chunk), 2))));
            }
        );

        $summary = $initializer->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame('env_write_failed', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        $this->assertNoSecretTempArtifact();
    }

    public function testRenameFailureLeavesOriginalBytesAndNoSecretTempArtifact(): void
    {
        $path = $this->envPath();
        $original = "APP_ENV=rename-test\n";
        file_put_contents($path, $original);
        $initializer = $this->initializer(
            renameFile: static fn(string $from, string $to): bool => false
        );

        $summary = $initializer->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame('env_replace_failed', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        $this->assertNoSecretTempArtifact();
    }

    public function testPermissionFailureBeforeWriteLeavesOriginalBytesAndNoSecretTempArtifact(): void
    {
        $path = $this->envPath();
        $original = "APP_ENV=permission-test\n";
        file_put_contents($path, $original);
        $initializer = $this->initializer(
            permissionGate: static fn(string $target, string $stage): bool => $stage !== 'temp_prewrite'
        );

        $summary = $initializer->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame('env_permission_failed', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        $this->assertNoSecretTempArtifact();
    }

    public function testFinalPermissionFailureAtomicallyRestoresOriginalBytes(): void
    {
        $path = $this->envPath();
        $original = "APP_ENV=final-permission-test\n";
        file_put_contents($path, $original);
        $initializer = $this->initializer(
            permissionGate: static fn(string $target, string $stage): bool => $stage !== 'final'
        );

        $summary = $initializer->run($path, true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame('env_permission_failed', $summary['reason_code']);
        self::assertSame($original, file_get_contents($path));
        $this->assertNoSecretTempArtifact();
    }

    public function testRealPlatformPermissionsAndAtomicReplacementIntegrateOnTemporaryEnv(): void
    {
        $path = $this->envPath();
        file_put_contents($path, "APP_ENV=acl-integration\n");

        $summary = $this->initializer()->run($path, true);

        self::assertSame('initialized', $summary['status'], json_encode($summary, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('APP_ENV=acl-integration', (string)file_get_contents($path));
        $this->assertThinkEnvLoadsCredentialPair($path, (string)$summary['key_id']);
        $this->assertNoSecretTempArtifact(false);
    }

    public function testCliDefaultsToDryRunAndEmitsOnlySafeJson(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'cli.env';
        $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'initialize_ota_credential_key.php';
        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($script)
            . ' --env=' . escapeshellarg($path)
            . ' 2>&1';

        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
        $summary = json_decode(implode(PHP_EOL, $output), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('dry-run', $summary['mode']);
        self::assertSame('initialization_required', $summary['status']);
        self::assertFalse($summary['configured']);
        self::assertFileDoesNotExist($path);
        self::assertSame([
            'mode',
            'status',
            'configured',
            'initialized',
            'key_id',
            'fingerprint',
            'reason_code',
        ], array_keys($summary));
    }

    public function testPackageScriptsKeepInitializationDryRunFirst(): void
    {
        $package = json_decode((string)file_get_contents(dirname(__DIR__) . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            'C:\\xampp\\php\\php.exe scripts\\initialize_ota_credential_key.php',
            $package['scripts']['init:ota-credential-key:dry-run']
        );
        self::assertSame(
            'C:\\xampp\\php\\php.exe scripts\\initialize_ota_credential_key.php --execute',
            $package['scripts']['init:ota-credential-key:execute']
        );
    }

    private function initializer(
        ?callable $writeChunk = null,
        ?callable $renameFile = null,
        ?callable $permissionGate = null
    ): OtaCredentialKeyInitializer
    {
        return new OtaCredentialKeyInitializer(
            randomBytes: static fn(int $length): string => str_repeat('k', $length),
            dateProvider: static fn(): string => '20260710',
            writeChunk: $writeChunk,
            renameFile: $renameFile,
            permissionGate: $permissionGate
        );
    }

    private function assertThinkEnvLoadsCredentialPair(string $path, string $expectedKeyId): void
    {
        $env = new Env();
        $env->load($path);
        $encodedKey = $env->get('OTA_CREDENTIAL_KEY_B64');
        $keyId = $env->get('OTA_CREDENTIAL_KEY_ID');
        self::assertIsString($encodedKey);
        $decoded = base64_decode($encodedKey, true);
        self::assertIsString($decoded);
        self::assertSame(32, strlen($decoded));
        self::assertSame($encodedKey, base64_encode($decoded));
        self::assertSame($expectedKeyId, $keyId);
    }

    private function assertNoSecretTempArtifact(bool $expectNoKeyOutsideEnv = true): void
    {
        $encodedKey = base64_encode(str_repeat('k', 32));
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $item) {
            self::assertStringNotContainsString('.ota-key.tmp.', $item->getFilename());
            if ($expectNoKeyOutsideEnv && $item->getPathname() !== $this->envPath() && $item->isFile()) {
                self::assertStringNotContainsString($encodedKey, (string)file_get_contents($item->getPathname()));
            }
        }
    }

    private function envPath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . '.env';
    }
}
