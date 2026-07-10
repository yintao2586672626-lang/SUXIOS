<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OtaCredentialResponseTest extends TestCase
{
    private function otaConfigHarness(): object
    {
        return new class {
            use \app\controller\concern\OtaConfigConcern;

            public function split(array $config): array
            {
                return $this->splitOtaConfigSecrets($config);
            }

            public function sanitize(array $config): array
            {
                return $this->sanitizeSecretConfig($config);
            }
        };
    }

    public function testSplitOtaConfigSecretsRecursivelySeparatesCaseInsensitiveSecrets(): void
    {
        $config = [
            'hotel_id' => 58,
            'hotel_name' => '测试门店',
            'credential_ref' => 'cred-58',
            'status' => 'active',
            'cookie' => '',
            'Cookies' => 'ctrip-cookie-secret',
            'nested' => [
                'label' => 'safe-label',
                'Access_Token' => 'meituan-token-secret',
                'refresh-token' => 'refresh-secret',
                'Api_Key' => 'api-key-secret',
                'Secret_JSON' => 'secret-json-secret',
                'Auth-Token' => 'auth-token-secret',
                'headers' => [
                    'Authorization' => 'Bearer authorization-secret',
                    'Set-Cookie' => 'sid=set-cookie-secret',
                    'X-Trace-Id' => 'trace-safe',
                ],
                'Headers' => 'Authorization: Bearer string-header-secret',
                'encrypted_payload' => 'encrypted-secret',
                'CipherText' => 'ciphertext-secret',
            ],
        ];

        [$metadata, $secretPayload] = $this->otaConfigHarness()->split($config);
        $metadataJson = (string)json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secretJson = (string)json_encode($secretPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame(58, $metadata['hotel_id']);
        self::assertSame('safe-label', $metadata['nested']['label']);
        self::assertSame('cred-58', $metadata['credential_ref']);
        self::assertSame('active', $metadata['status']);
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'refresh-secret',
            'authorization-secret',
            'set-cookie-secret',
            'api-key-secret',
            'secret-json-secret',
            'auth-token-secret',
            'string-header-secret',
            'trace-safe',
            'encrypted-secret',
            'ciphertext-secret',
            'encrypted_payload',
            'CipherText',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $metadataJson);
        }
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'refresh-secret',
            'authorization-secret',
            'set-cookie-secret',
            'api-key-secret',
            'secret-json-secret',
            'auth-token-secret',
            'string-header-secret',
            'trace-safe',
            'encrypted-secret',
            'ciphertext-secret',
        ] as $secret) {
            self::assertStringContainsString($secret, $secretJson);
        }
    }

    public function testSanitizeSecretConfigReturnsOnlySafeMetadataAndOpaqueIndicators(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitize([
            'hotel_id' => 58,
            'credential_ref' => 'cred-58',
            'status' => 'active',
            'cookie' => '',
            'Cookies' => 'ctrip-cookie-secret',
            'nested' => [
                'UserToken' => 'meituan-token-secret',
                'Authorization' => 'Bearer authorization-secret',
                'encrypted_payload' => 'encrypted-secret',
                'ciphertext' => 'ciphertext-secret',
                'label' => 'safe-label',
            ],
        ]);
        $encoded = (string)json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame(58, $sanitized['hotel_id']);
        self::assertSame('cred-58', $sanitized['credential_ref']);
        self::assertSame('active', $sanitized['status']);
        self::assertSame('safe-label', $sanitized['nested']['label']);
        self::assertTrue($sanitized['has_cookies']);
        self::assertSame('********', $sanitized['secret_mask']);
        foreach ([
            'ctrip-cookie-secret',
            'meituan-token-secret',
            'authorization-secret',
            'encrypted-secret',
            'ciphertext-secret',
            'encrypted_payload',
            'ciphertext',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $encoded);
        }
        self::assertArrayNotHasKey('cookies_preview', $sanitized);
        self::assertArrayNotHasKey('token_preview', $sanitized);
    }

    public function testSanitizeSecretConfigDoesNotSignalEmptyNestedSecrets(): void
    {
        $sanitized = $this->otaConfigHarness()->sanitize([
            'hotel_id' => 58,
            'cookie' => ['nested' => [null, '', '   ', []]],
            'token' => '',
            'api_key' => null,
            'headers' => [],
            'secret_json' => ['nested' => ['']],
        ]);

        self::assertSame(58, $sanitized['hotel_id']);
        self::assertFalse($sanitized['has_cookies']);
        self::assertArrayNotHasKey('secret_mask', $sanitized);
    }
}
