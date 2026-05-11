<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AiModelConfig extends Model
{
    protected $name = 'ai_model_configs';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'is_default' => 'integer',
        'is_enabled' => 'integer',
    ];

    public static function encryptApiKey(string $apiKey, string $secret): ?string
    {
        $apiKey = trim($apiKey);
        $secret = trim($secret);
        if ($apiKey === '' || $secret === '') {
            return null;
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($apiKey, 'AES-256-CBC', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return null;
        }

        return base64_encode($iv) . ':' . base64_encode($cipher);
    }

    public static function decryptApiKey(string $encryptedApiKey, string $secret): ?string
    {
        $secret = trim($secret);
        $parts = explode(':', $encryptedApiKey, 2);
        if ($secret === '' || count($parts) !== 2) {
            return null;
        }

        $iv = base64_decode($parts[0], true);
        $cipher = base64_decode($parts[1], true);
        if ($iv === false || $cipher === false || strlen($iv) !== 16) {
            return null;
        }

        $apiKey = openssl_decrypt($cipher, 'AES-256-CBC', hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv);
        return is_string($apiKey) && trim($apiKey) !== '' ? $apiKey : null;
    }

    public static function maskApiKey(string $apiKey): string
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return '';
        }

        if (strlen($apiKey) <= 8) {
            return '****';
        }

        return substr($apiKey, 0, 3) . '****' . substr($apiKey, -4);
    }

    public function toSafeArray(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->getAttr('name'),
            'model_key' => (string) $this->getAttr('model_key'),
            'provider' => (string) $this->getAttr('provider'),
            'base_url' => (string) $this->getAttr('base_url'),
            'model_name' => (string) $this->getAttr('model_name'),
            'api_key' => (string) ($this->getAttr('api_key_mask') ?? ''),
            'usage_scene' => (string) ($this->getAttr('usage_scene') ?? ''),
            'is_default' => (int) $this->is_default,
            'is_enabled' => (int) $this->is_enabled,
            'create_time' => $this->create_time,
            'update_time' => $this->update_time,
        ];
    }
}
