<?php
declare(strict_types=1);

namespace app\service;

use JsonException;
use RuntimeException;

final class OtaCredentialEnvelope
{
    private const PREFIX = 'ota-cred:v1:';
    private const VERSION = 1;
    private const ALGORITHM = 'AES-256-GCM';
    private const OPENSSL_CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const AAD_PREFIX = 'suxios:ota-credential:v1:';

    private readonly string $key;

    public function __construct(string $keyBase64, private readonly string $keyId)
    {
        $key = base64_decode($keyBase64, true);
        if (
            $key === false
            || strlen($key) !== self::KEY_BYTES
            || base64_encode($key) !== $keyBase64
        ) {
            throw new RuntimeException('OTA credential encryption key is invalid.');
        }
        if (trim($this->keyId) === '') {
            throw new RuntimeException('OTA credential key identifier is invalid.');
        }

        $this->key = $key;
    }

    /** @param array<string|int, mixed> $credentials */
    public function encrypt(array $credentials, string $scope): string
    {
        $payload = [
            'header' => [
                'v' => self::VERSION,
                'alg' => self::ALGORITHM,
                'kid' => $this->keyId,
            ],
            'credentials' => $credentials,
        ];

        try {
            $plaintext = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw new RuntimeException('OTA credential data cannot be encoded.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::OPENSSL_CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD_PREFIX . $scope,
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('OTA credential encryption failed.');
        }

        $metadata = [
            'v' => self::VERSION,
            'alg' => self::ALGORITHM,
            'kid' => $this->keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ];

        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('OTA credential envelope cannot be encoded.');
        }

        return self::PREFIX . $this->base64UrlEncode($json);
    }

    /** @return array<string|int, mixed> */
    public function decrypt(string $envelope, string $scope): array
    {
        $metadata = $this->parseEnvelope($envelope);

        if (!array_key_exists('v', $metadata) || $metadata['v'] !== self::VERSION) {
            throw new RuntimeException('OTA credential envelope version is unsupported.');
        }
        if (!array_key_exists('alg', $metadata) || $metadata['alg'] !== self::ALGORITHM) {
            throw new RuntimeException('OTA credential envelope algorithm is unsupported.');
        }
        if (
            !isset($metadata['kid'])
            || !is_string($metadata['kid'])
            || !hash_equals($this->keyId, $metadata['kid'])
        ) {
            throw new RuntimeException('OTA credential envelope key identifier does not match.');
        }

        $nonce = $this->decodeBase64Field($metadata, 'nonce');
        $ciphertext = $this->decodeBase64Field($metadata, 'ciphertext');
        $tag = $this->decodeBase64Field($metadata, 'tag');
        if (
            strlen($nonce) !== self::NONCE_BYTES
            || strlen($tag) !== self::TAG_BYTES
            || $ciphertext === ''
        ) {
            throw new RuntimeException('OTA credential envelope cryptographic fields are invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::OPENSSL_CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD_PREFIX . $scope
        );
        if ($plaintext === false) {
            throw new RuntimeException('OTA credential envelope authentication failed.');
        }

        try {
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('OTA credential envelope plaintext is invalid.');
        }
        if (
            !is_array($payload)
            || !isset($payload['header'])
            || !is_array($payload['header'])
            || !array_key_exists('credentials', $payload)
            || !is_array($payload['credentials'])
        ) {
            throw new RuntimeException('OTA credential envelope plaintext is invalid.');
        }

        $header = $payload['header'];
        if (
            !array_key_exists('v', $header)
            || $header['v'] !== $metadata['v']
            || !array_key_exists('alg', $header)
            || $header['alg'] !== $metadata['alg']
            || !isset($header['kid'])
            || !is_string($header['kid'])
            || !hash_equals($metadata['kid'], $header['kid'])
            || !hash_equals($this->keyId, $header['kid'])
        ) {
            throw new RuntimeException('OTA credential envelope authenticated metadata is invalid.');
        }

        return $payload['credentials'];
    }

    /** @return array<string, mixed> */
    private function parseEnvelope(string $envelope): array
    {
        if (!str_starts_with($envelope, self::PREFIX)) {
            throw new RuntimeException('OTA credential envelope prefix is invalid.');
        }

        $encoded = substr($envelope, strlen(self::PREFIX));
        $json = $this->base64UrlDecode($encoded);

        try {
            $metadata = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('OTA credential envelope payload is invalid.');
        }
        if (!is_array($metadata)) {
            throw new RuntimeException('OTA credential envelope payload is invalid.');
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function decodeBase64Field(array $metadata, string $field): string
    {
        if (!isset($metadata[$field]) || !is_string($metadata[$field])) {
            throw new RuntimeException('OTA credential envelope Base64 field is invalid.');
        }

        $decoded = base64_decode($metadata[$field], true);
        if ($decoded === false || base64_encode($decoded) !== $metadata[$field]) {
            throw new RuntimeException('OTA credential envelope Base64 field is invalid.');
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new RuntimeException('OTA credential envelope Base64 payload is invalid.');
        }

        $paddingLength = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(
            strtr($value, '-_', '+/') . str_repeat('=', $paddingLength),
            true
        );
        if ($decoded === false || $this->base64UrlEncode($decoded) !== $value) {
            throw new RuntimeException('OTA credential envelope Base64 payload is invalid.');
        }

        return $decoded;
    }
}
