<?php
declare(strict_types=1);

namespace app\service;

use JsonException;
use RuntimeException;

final class SensitiveValueCipher
{
    private const PREFIX = 'suxi-secret:v1:';
    private const VERSION = 1;
    private const ALGORITHM = 'AES-256-GCM';
    private const CIPHER = 'aes-256-gcm';
    private const MASTER_KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const KEY_CONTEXT = 'suxios:sensitive-value:key:v1';
    private const AAD_PREFIX = 'suxios:sensitive-value:v1:';

    private readonly string $key;

    public function __construct(string $keyBase64, private readonly string $keyId)
    {
        $keyBase64 = trim($keyBase64);
        $masterKey = base64_decode($keyBase64, true);
        if (
            $masterKey === false
            || strlen($masterKey) !== self::MASTER_KEY_BYTES
            || base64_encode($masterKey) !== $keyBase64
        ) {
            throw new RuntimeException('Sensitive-value encryption key is invalid.');
        }
        if (trim($this->keyId) === '') {
            throw new RuntimeException('Sensitive-value key identifier is invalid.');
        }

        $this->key = hash_hkdf('sha256', $masterKey, self::MASTER_KEY_BYTES, self::KEY_CONTEXT);
    }

    public static function fromEnvironment(): self
    {
        $dedicatedKey = self::environmentValue('SUXI_SECRET_KEY_B64');
        $dedicatedKeyId = self::environmentValue('SUXI_SECRET_KEY_ID');
        if ($dedicatedKey !== '' || $dedicatedKeyId !== '') {
            return new self($dedicatedKey, $dedicatedKeyId);
        }

        return new self(
            self::environmentValue('OTA_CREDENTIAL_KEY_B64'),
            self::environmentValue('OTA_CREDENTIAL_KEY_ID')
        );
    }

    public function encrypt(string $plaintext, string $scope): string
    {
        if ($plaintext === '') {
            throw new RuntimeException('Sensitive value cannot be empty.');
        }
        $scope = $this->validatedScope($scope);
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($scope),
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Sensitive-value encryption failed.');
        }

        try {
            $metadata = json_encode([
                'v' => self::VERSION,
                'alg' => self::ALGORITHM,
                'kid' => $this->keyId,
                'n' => $this->base64UrlEncode($nonce),
                'c' => $this->base64UrlEncode($ciphertext),
                't' => $this->base64UrlEncode($tag),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('Sensitive-value envelope cannot be encoded.');
        }

        return self::PREFIX . $this->base64UrlEncode($metadata);
    }

    public function decrypt(string $envelope, string $scope): string
    {
        $scope = $this->validatedScope($scope);
        if (!$this->isEncrypted($envelope)) {
            throw new RuntimeException('Sensitive-value envelope prefix is invalid.');
        }

        $encoded = substr($envelope, strlen(self::PREFIX));
        try {
            $metadata = json_decode($this->base64UrlDecode($encoded), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Sensitive-value envelope is invalid.');
        }
        if (!is_array($metadata)
            || ($metadata['v'] ?? null) !== self::VERSION
            || ($metadata['alg'] ?? null) !== self::ALGORITHM
            || !isset($metadata['kid'])
            || !is_string($metadata['kid'])
            || !hash_equals($this->keyId, $metadata['kid'])
        ) {
            throw new RuntimeException('Sensitive-value envelope metadata is invalid.');
        }

        $nonce = $this->decodeField($metadata, 'n');
        $ciphertext = $this->decodeField($metadata, 'c');
        $tag = $this->decodeField($metadata, 't');
        if (strlen($nonce) !== self::NONCE_BYTES || strlen($tag) !== self::TAG_BYTES || $ciphertext === '') {
            throw new RuntimeException('Sensitive-value envelope cryptographic fields are invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($scope)
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Sensitive-value envelope authentication failed.');
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return self::hasEnvelopePrefix($value);
    }

    public static function hasEnvelopePrefix(string $value): bool
    {
        return str_starts_with(trim($value), self::PREFIX);
    }

    private function aad(string $scope): string
    {
        return self::AAD_PREFIX . self::VERSION . ':' . $this->keyId . ':' . $scope;
    }

    private function validatedScope(string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '' || strlen($scope) > 240) {
            throw new RuntimeException('Sensitive-value scope is invalid.');
        }
        return $scope;
    }

    /** @param array<string, mixed> $metadata */
    private function decodeField(array $metadata, string $field): string
    {
        if (!isset($metadata[$field]) || !is_string($metadata[$field])) {
            throw new RuntimeException('Sensitive-value envelope field is invalid.');
        }
        return $this->base64UrlDecode($metadata[$field]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new RuntimeException('Sensitive-value envelope encoding is invalid.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false || $this->base64UrlEncode($decoded) !== $value) {
            throw new RuntimeException('Sensitive-value envelope encoding is invalid.');
        }
        return $decoded;
    }

    private static function environmentValue(string $key): string
    {
        $value = getenv($key);
        if ($value === false && function_exists('env')) {
            $value = env($key, '');
        }
        return trim((string)$value);
    }
}
