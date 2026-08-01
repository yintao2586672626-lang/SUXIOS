<?php
declare(strict_types=1);

namespace Tests;

use app\model\SystemConfig;
use app\service\SensitiveValueCipher;
use app\service\WechatRobotWebhookSecret;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SensitiveValueCipherTest extends TestCase
{
    private SensitiveValueCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new SensitiveValueCipher(base64_encode(str_repeat('s', 32)), 'test-sensitive-key');
    }

    public function testAuthenticatedEncryptionDoesNotRetainPlaintext(): void
    {
        $secret = 'smtp-password-that-must-not-appear';
        $encrypted = $this->cipher->encrypt($secret, 'system-config:notify_email_pass');

        self::assertTrue($this->cipher->isEncrypted($encrypted));
        self::assertStringNotContainsString($secret, $encrypted);
        self::assertSame($secret, $this->cipher->decrypt($encrypted, 'system-config:notify_email_pass'));
    }

    public function testCiphertextCannotBeMovedToAnotherScopeOrTampered(): void
    {
        $encrypted = $this->cipher->encrypt('secret-value', 'scope:a');

        $this->expectException(RuntimeException::class);
        $this->cipher->decrypt($encrypted, 'scope:b');
    }

    public function testTamperedEnvelopeIsRejectedWithoutReturningPlaintext(): void
    {
        $encrypted = $this->cipher->encrypt('secret-value', 'scope:a');
        $last = substr($encrypted, -1);
        $tampered = substr($encrypted, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $this->expectException(RuntimeException::class);
        $this->cipher->decrypt($tampered, 'scope:a');
    }

    public function testEnvelopeCannotBeOpenedWithAnotherKey(): void
    {
        $encrypted = $this->cipher->encrypt('secret-value', 'scope:a');
        $otherCipher = new SensitiveValueCipher(base64_encode(str_repeat('x', 32)), 'test-sensitive-key');

        $this->expectException(RuntimeException::class);
        $otherCipher->decrypt($encrypted, 'scope:a');
    }

    public function testSystemConfigSensitiveValuesEncryptForStorageAndLegacyPlaintextStillReads(): void
    {
        $stored = SystemConfig::encodeValueForStorage(
            SystemConfig::KEY_NOTIFY_EMAIL_PASS,
            'mail-secret',
            $this->cipher
        );

        self::assertIsString($stored);
        self::assertTrue($this->cipher->isEncrypted($stored));
        self::assertSame(
            'mail-secret',
            SystemConfig::decodeValueFromStorage(SystemConfig::KEY_NOTIFY_EMAIL_PASS, $stored, $this->cipher)
        );
        self::assertSame(
            'legacy-mail-secret',
            SystemConfig::decodeValueFromStorage(
                SystemConfig::KEY_NOTIFY_EMAIL_PASS,
                'legacy-mail-secret',
                $this->cipher
            )
        );
        self::assertSame('SUXIOS', SystemConfig::encodeValueForStorage('system_name', 'SUXIOS', $this->cipher));
        self::assertTrue(SystemConfig::isSensitiveKey(SystemConfig::KEY_AMAP_WEB_API_KEY));
    }

    public function testSensitiveResponseUsesPreserveSentinelWithoutLeakingValue(): void
    {
        self::assertSame(
            SystemConfig::SENSITIVE_RESPONSE_SENTINEL,
            SystemConfig::valueForResponse(SystemConfig::KEY_WECHAT_MINI_SECRET, 'plain-or-encrypted-secret')
        );
        self::assertSame('', SystemConfig::valueForResponse(SystemConfig::KEY_WECHAT_MINI_SECRET, ''));
        self::assertSame('SUXIOS', SystemConfig::valueForResponse('system_name', 'SUXIOS'));
        self::assertTrue(SystemConfig::shouldPreserveSensitiveInput(
            SystemConfig::KEY_WECHAT_MINI_SECRET,
            SystemConfig::SENSITIVE_RESPONSE_SENTINEL
        ));
        self::assertTrue(SystemConfig::shouldPreserveSensitiveInput(
            SystemConfig::KEY_WECHAT_MINI_SECRET,
            ''
        ));
    }

    public function testWechatWebhookStorageIsEncryptedAndLegacyRowsRemainReadable(): void
    {
        $service = new WechatRobotWebhookSecret($this->cipher);
        $webhook = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=unit-test-key';
        $stored = $service->protect($webhook, 17);

        self::assertTrue($service->isProtected($stored));
        self::assertStringNotContainsString('unit-test-key', $stored);
        self::assertLessThanOrEqual(512, strlen($stored));
        self::assertSame($webhook, $service->reveal($stored, 17));
        self::assertSame($webhook, $service->reveal($webhook, 17));

        $this->expectException(RuntimeException::class);
        $service->reveal($stored, 18);
    }
}
