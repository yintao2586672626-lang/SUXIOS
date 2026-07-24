<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

final class WechatRobotWebhookSecret
{
    private const SCOPE_PREFIX = 'competitor-wechat-robot:';
    private const MAX_PLAINTEXT_BYTES = 256;
    private const MAX_STORED_BYTES = 512;

    public function __construct(private readonly ?SensitiveValueCipher $cipher = null)
    {
    }

    public function protect(string $webhook, int $robotId): string
    {
        $webhook = trim($webhook);
        if ($webhook === '') {
            return '';
        }

        $cipher = $this->cipher();
        if ($cipher->isEncrypted($webhook)) {
            $cipher->decrypt($webhook, $this->scope($robotId));
            return $webhook;
        }
        if (strlen($webhook) > self::MAX_PLAINTEXT_BYTES) {
            throw new RuntimeException('Webhook value is too long.');
        }

        $protected = $cipher->encrypt($webhook, $this->scope($robotId));
        if (strlen($protected) > self::MAX_STORED_BYTES) {
            throw new RuntimeException('Protected webhook value exceeds the storage limit.');
        }
        return $protected;
    }

    public function reveal(string $stored, int $robotId): string
    {
        $stored = trim($stored);
        if ($stored === '' || !SensitiveValueCipher::hasEnvelopePrefix($stored)) {
            return $stored;
        }
        return $this->cipher()->decrypt($stored, $this->scope($robotId));
    }

    public function isProtected(string $stored): bool
    {
        return SensitiveValueCipher::hasEnvelopePrefix(trim($stored));
    }

    private function cipher(): SensitiveValueCipher
    {
        return $this->cipher ?? SensitiveValueCipher::fromEnvironment();
    }

    private function scope(int $robotId): string
    {
        if ($robotId <= 0) {
            throw new RuntimeException('Robot identifier is invalid.');
        }
        return self::SCOPE_PREFIX . $robotId . ':webhook';
    }
}
