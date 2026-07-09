<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OtaCredentialEnvelopeTest extends TestCase
{
    private const PREFIX = 'ota-cred:v1:';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(
            class_exists(OtaCredentialEnvelope::class),
            'OtaCredentialEnvelope must exist before its behavior can be verified.'
        );
    }

    public function testUtf8NestedCredentialsRoundTripWithAuthenticatedMetadata(): void
    {
        $service = $this->service($this->key('primary-key'), 'ota-primary');
        $credentials = [
            'username' => '前台管理员',
            'password' => '秘密-pass-🔐',
            'cookies' => [
                ['name' => 'session', 'value' => '会话值'],
                ['name' => 'locale', 'value' => 'zh-CN'],
            ],
            'options' => ['remember' => true, 'retry' => 0],
        ];

        $envelope = $service->encrypt($credentials, 'ctrip:hotel:58');
        $metadata = $this->decodeEnvelope($envelope);

        self::assertSame(1, $metadata['v']);
        self::assertSame('AES-256-GCM', $metadata['alg']);
        self::assertSame('ota-primary', $metadata['kid']);
        self::assertSame(12, strlen($this->decodeBase64Field($metadata, 'nonce')));
        self::assertSame(16, strlen($this->decodeBase64Field($metadata, 'tag')));
        self::assertNotSame('', $this->decodeBase64Field($metadata, 'ciphertext'));
        self::assertSame($credentials, $service->decrypt($envelope, 'ctrip:hotel:58'));
    }

    public function testEncryptingSameCredentialsTwiceUsesDifferentNonceAndCiphertext(): void
    {
        $service = $this->service($this->key('primary-key'), 'ota-primary');
        $credentials = ['username' => 'operator', 'password' => 'same-secret'];

        $first = $this->decodeEnvelope($service->encrypt($credentials, 'meituan:hotel:9'));
        $second = $this->decodeEnvelope($service->encrypt($credentials, 'meituan:hotel:9'));

        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['ciphertext'], $second['ciphertext']);
    }

    public function testConstructorRejectsInvalidBase64AndNon32ByteKeysWithoutLeakingThem(): void
    {
        $invalidBase64 = 'not-a-base64-key!';
        $shortKey = base64_encode(str_repeat('k', 31));

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($invalidBase64, 'ota-primary'),
            [$invalidBase64]
        );
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($shortKey, 'ota-primary'),
            [$shortKey]
        );
    }

    public function testConstructorRejectsEmptyAndWhitespaceOnlyKeyIdentifiers(): void
    {
        $key = $this->key('primary-key');

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($key, ''),
            [$key]
        );

        $whitespaceKeyId = " \t\r\n";
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($key, $whitespaceKeyId),
            [$key, $whitespaceKeyId]
        );
    }

    public function testRecursiveCredentialsAreRejectedWithoutLeakingSecrets(): void
    {
        $key = $this->key('primary-key');
        $secret = 'recursive-credential-secret';
        $credentials = ['password' => $secret];
        $credentials['recursive'] = &$credentials;

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($key, 'ota-primary')->encrypt(
                $credentials,
                'ctrip:hotel:58'
            ),
            [$key, $secret]
        );
    }

    public function testEncryptionBindsExactAadPrefixAndScope(): void
    {
        $keyBase64 = $this->key('primary-key');
        $key = base64_decode($keyBase64, true);
        $scope = 'ctrip:hotel:58';
        $credentials = [
            'username' => 'operator',
            'password' => 'aad-secret',
            'options' => ['remember' => true],
        ];
        $metadata = $this->decodeEnvelope(
            $this->service($keyBase64, 'ota-primary')->encrypt($credentials, $scope)
        );

        self::assertNotFalse($key);
        self::assertSame(32, strlen($key));
        $plaintext = openssl_decrypt(
            $this->decodeBase64Field($metadata, 'ciphertext'),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $this->decodeBase64Field($metadata, 'nonce'),
            $this->decodeBase64Field($metadata, 'tag'),
            'suxios:ota-credential:v1:' . $scope
        );

        self::assertNotFalse($plaintext);
        self::assertSame(
            json_encode(
                [
                    'header' => [
                        'v' => 1,
                        'alg' => 'AES-256-GCM',
                        'kid' => 'ota-primary',
                    ],
                    'credentials' => $credentials,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            $plaintext
        );
    }

    public function testWrongKeyScopeAndKeyIdentifierAreRejectedWithoutLeakingSecrets(): void
    {
        $primaryKey = $this->key('primary-key');
        $wrongKey = $this->key('wrong-key');
        $credentials = ['username' => 'operator', 'password' => 'do-not-leak-this'];
        $scope = 'ctrip:hotel:58';
        $envelope = $this->service($primaryKey, 'ota-primary')->encrypt($credentials, $scope);

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($wrongKey, 'ota-primary')->decrypt($envelope, $scope),
            [$primaryKey, $wrongKey, $credentials['password']]
        );
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($primaryKey, 'ota-primary')->decrypt($envelope, 'ctrip:hotel:59'),
            [$primaryKey, $credentials['password']]
        );
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $this->service($primaryKey, 'ota-rotated')->decrypt($envelope, $scope),
            [$primaryKey, $credentials['password']]
        );
    }

    public function testOuterKeyIdentifierCannotBeReboundWhenKeyBytesAreShared(): void
    {
        $key = $this->key('shared-key');
        $scope = 'ctrip:hotel:58';
        $credentials = ['password' => 'kid-binding-secret'];
        $kidA = $this->service($key, 'kid-a');
        $kidB = $this->service($key, 'kid-b');
        $metadata = $this->decodeEnvelope($kidA->encrypt($credentials, $scope));
        $metadata['kid'] = 'kid-b';

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $kidB->decrypt($this->encodeEnvelope($metadata), $scope),
            [$key, $credentials['password']]
        );
    }

    public function testTamperedNonceCiphertextAndTagAreRejectedWithoutLeakingSecrets(): void
    {
        $key = $this->key('primary-key');
        $credentials = ['cookie' => 'session=top-secret-cookie'];
        $scope = 'ctrip:hotel:58';
        $service = $this->service($key, 'ota-primary');
        $metadata = $this->decodeEnvelope($service->encrypt($credentials, $scope));

        foreach (['nonce', 'ciphertext', 'tag'] as $field) {
            $tampered = $metadata;
            $binary = $this->decodeBase64Field($tampered, $field);
            $binary[0] = chr(ord($binary[0]) ^ 1);
            $tampered[$field] = base64_encode($binary);

            $this->assertRuntimeExceptionWithoutSecrets(
                fn () => $service->decrypt($this->encodeEnvelope($tampered), $scope),
                [$key, $credentials['cookie']]
            );
        }
    }

    #[DataProvider('invalidCryptographicMetadataProvider')]
    public function testInvalidCryptographicMetadataFieldsAreRejected(
        string $field,
        mixed $value,
        string $operation
    ): void {
        $key = $this->key('primary-key');
        $secret = 'invalid-field-secret';
        $scope = 'ctrip:hotel:58';
        $service = $this->service($key, 'ota-primary');
        $metadata = $this->decodeEnvelope(
            $service->encrypt(['password' => $secret], $scope)
        );

        if ($operation === 'unset') {
            unset($metadata[$field]);
        } elseif ($operation === 'strip-padding') {
            self::assertIsString($metadata[$field]);
            $nonCanonical = rtrim($metadata[$field], '=');
            self::assertNotSame($metadata[$field], $nonCanonical);
            $metadata[$field] = $nonCanonical;
        } else {
            $metadata[$field] = $value;
        }

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt($this->encodeEnvelope($metadata), $scope),
            [$key, $secret]
        );
    }

    public function testUnsupportedVersionAlgorithmAndMalformedBase64AreRejected(): void
    {
        $service = $this->service($this->key('primary-key'), 'ota-primary');
        $metadata = $this->decodeEnvelope(
            $service->encrypt(['cookie' => 'session=secret'], 'ctrip:hotel:58')
        );

        $unsupportedVersion = $metadata;
        $unsupportedVersion['v'] = 2;
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt($this->encodeEnvelope($unsupportedVersion), 'ctrip:hotel:58'),
            ['session=secret']
        );

        $unsupportedAlgorithm = $metadata;
        $unsupportedAlgorithm['alg'] = 'AES-128-GCM';
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt($this->encodeEnvelope($unsupportedAlgorithm), 'ctrip:hotel:58'),
            ['session=secret']
        );

        $malformedNonce = $metadata;
        $malformedNonce['nonce'] = '***';
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt($this->encodeEnvelope($malformedNonce), 'ctrip:hotel:58'),
            ['session=secret']
        );
        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt(self::PREFIX . '***', 'ctrip:hotel:58'),
            ['session=secret']
        );
    }

    public function testInvalidPrefixIsRejected(): void
    {
        $service = $this->service($this->key('primary-key'), 'ota-primary');
        $validEnvelope = $service->encrypt(
            ['cookie' => 'session=secret'],
            'ctrip:hotel:58'
        );
        $payload = substr($validEnvelope, strlen(self::PREFIX));

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt('invalid-prefix:' . $payload, 'ctrip:hotel:58'),
            ['session=secret']
        );
    }

    public function testValidPrefixAndBase64WithInvalidJsonAreRejected(): void
    {
        $service = $this->service($this->key('primary-key'), 'ota-primary');
        $invalidJsonPayload = rtrim(strtr(base64_encode('{invalid-json'), '+/', '-_'), '=');

        $this->assertRuntimeExceptionWithoutSecrets(
            fn () => $service->decrypt(self::PREFIX . $invalidJsonPayload, 'ctrip:hotel:58'),
            []
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function invalidCryptographicMetadataProvider(): iterable
    {
        yield 'nonce is 11 bytes' => [
            'nonce',
            base64_encode(str_repeat('n', 11)),
            'set',
        ];
        yield 'nonce is 13 bytes' => [
            'nonce',
            base64_encode(str_repeat('n', 13)),
            'set',
        ];
        yield 'tag is 15 bytes' => [
            'tag',
            base64_encode(str_repeat('t', 15)),
            'set',
        ];
        yield 'tag is 17 bytes' => [
            'tag',
            base64_encode(str_repeat('t', 17)),
            'set',
        ];
        yield 'ciphertext is empty' => ['ciphertext', '', 'set'];
        yield 'nonce is missing' => ['nonce', null, 'unset'];
        yield 'ciphertext is missing' => ['ciphertext', null, 'unset'];
        yield 'tag is missing' => ['tag', null, 'unset'];
        yield 'nonce has wrong type' => ['nonce', [], 'set'];
        yield 'ciphertext has wrong type' => ['ciphertext', false, 'set'];
        yield 'tag has wrong type' => ['tag', 16, 'set'];
        yield 'tag Base64 is noncanonical' => ['tag', null, 'strip-padding'];
    }

    private function service(string $keyBase64, string $keyId): OtaCredentialEnvelope
    {
        return new OtaCredentialEnvelope($keyBase64, $keyId);
    }

    private function key(string $seed): string
    {
        return base64_encode(hash('sha256', $seed, true));
    }

    /** @return array<string, mixed> */
    private function decodeEnvelope(string $envelope): array
    {
        self::assertStringStartsWith(self::PREFIX, $envelope);
        $encoded = substr($envelope, strlen(self::PREFIX));
        $json = base64_decode(
            strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true
        );
        self::assertNotFalse($json);

        $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function encodeEnvelope(array $metadata): string
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $metadata */
    private function decodeBase64Field(array $metadata, string $field): string
    {
        self::assertArrayHasKey($field, $metadata);
        self::assertIsString($metadata[$field]);
        $decoded = base64_decode($metadata[$field], true);
        self::assertNotFalse($decoded);

        return $decoded;
    }

    /** @param list<string> $secrets */
    private function assertRuntimeExceptionWithoutSecrets(callable $operation, array $secrets): void
    {
        $exception = null;
        try {
            $operation();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertNotSame('', $exception->getMessage());
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }
}
