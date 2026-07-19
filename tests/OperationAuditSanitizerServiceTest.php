<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperationLogController;
use app\middleware\Auth;
use app\service\OperationAuditSanitizerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class OperationAuditSanitizerServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testOtaCredentialAliasesAndSensitiveSubtreesAreMaskedRecursively(): void
    {
        $service = new OperationAuditSanitizerService();
        $payload = [
            'spidertoken' => 'sentinel-spider-token',
            'spiderKey' => 'sentinel-spider-key',
            'mtgsig' => 'sentinel-mtg',
            'userSign' => 'sentinel-sign',
            'user_token' => 'sentinel-user-token',
            'set_cookie' => 'sentinel-set-cookie',
            'access_token' => 'sentinel-access',
            'refreshToken' => 'sentinel-refresh',
            'secret' => ['spiderkey' => 'nested-sentinel'],
            'secret_json' => '{"spidertoken":"json-sentinel"}',
            'credentials' => ['password' => 'credential-sentinel'],
            'headers' => ['Authorization' => 'Bearer header-sentinel'],
            'cookie_enabled' => true,
            'cookie_count' => 3,
            'probe_cookie' => '0',
        ];

        $safe = $service->sanitizeArray($payload, 500);
        foreach ([
            'spidertoken', 'spiderKey', 'mtgsig', 'userSign', 'user_token', 'set_cookie',
            'access_token', 'refreshToken', 'secret', 'secret_json', 'credentials', 'headers',
        ] as $key) {
            self::assertSame('***', $safe[$key], $key . ' must be redacted');
        }
        self::assertTrue($safe['cookie_enabled']);
        self::assertSame(3, $safe['cookie_count']);
        self::assertSame('0', $safe['probe_cookie']);
        self::assertStringNotContainsString('sentinel', json_encode($safe, JSON_UNESCAPED_UNICODE));
    }

    public function testSerializedJsonAndBearerTextAreSanitized(): void
    {
        $service = new OperationAuditSanitizerService();
        $json = $service->sanitizeText('{"platform":"ctrip","spidertoken":"json-secret","nested":{"mtgsig":"nested-secret"}}', 500);
        $text = $service->sanitizeText('Authorization: Bearer abcdefghijklmnop access_token=plain-secret', 500);

        self::assertStringContainsString('***', $json);
        self::assertStringNotContainsString('json-secret', $json);
        self::assertStringNotContainsString('nested-secret', $json);
        self::assertStringNotContainsString('abcdefghijklmnop', $text);
        self::assertStringNotContainsString('plain-secret', $text);
    }

    public function testAuthMiddlewareUsesTheSharedSanitizerAndRecognizesCredentialPaths(): void
    {
        $auth = new Auth();
        $safe = $this->invokeNonPublic($auth, 'sanitizeAuditParams', [[
            'hotel_id' => 80,
            'spidertoken' => 'auth-sentinel',
            'nested' => ['usersign' => 'nested-auth-sentinel'],
        ]]);

        self::assertSame(80, $safe['hotel_id']);
        self::assertSame('***', $safe['spidertoken']);
        self::assertSame('***', $safe['nested']['usersign']);
        foreach ([
            'api/online-data/save-ctrip-config',
            'api/online-data/save-meituan-config/12',
            'api/online-data/data-sources',
        ] as $path) {
            self::assertTrue($this->invokeNonPublic($auth, 'isCredentialAuditPath', [$path]));
        }
        self::assertFalse($this->invokeNonPublic($auth, 'isCredentialAuditPath', ['api/online-data/history']));
    }

    public function testOperationLogReadbackIsSanitizedAgain(): void
    {
        $controller = (new ReflectionClass(OperationLogController::class))->newInstanceWithoutConstructor();
        $row = [
            'description' => 'Bearer readback-secret-token',
            'error_info' => 'spiderkey=readback-spider-key',
            'extra_data' => json_encode([
                'params' => [
                    'spidertoken' => 'stored-spider-token',
                    'secret' => ['mtgsig' => 'stored-mtg'],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];

        $safe = $this->invokeNonPublic($controller, 'sanitizeOperationLogOutputRow', [$row, true]);
        $encoded = json_encode($safe, JSON_UNESCAPED_UNICODE);
        self::assertIsArray($safe['extra_data']);
        self::assertStringNotContainsString('readback-secret-token', $encoded);
        self::assertStringNotContainsString('readback-spider-key', $encoded);
        self::assertStringNotContainsString('stored-spider-token', $encoded);
        self::assertStringNotContainsString('stored-mtg', $encoded);
        self::assertStringContainsString('***', $encoded);
    }

    public function testOperationLogReadbackWhitelistsRelationsAndMasksSessionIdentifiers(): void
    {
        $controller = (new ReflectionClass(OperationLogController::class))->newInstanceWithoutConstructor();
        $row = [
            'description' => 'sessionid=session-description JSESSIONID=jsession-description sid=sid-description',
            'error_info' => 'request failed?sid=sid-error&sessionid=session-error',
            'user_agent' => 'Browser Cookie: JSESSIONID=jsession-agent; sid=sid-agent',
            'user' => [
                'id' => 8,
                'username' => 'operator sid=sid-username',
                'realname' => 'Operator',
                'email' => 'private-user@example.test',
                'phone' => '13900000000',
                'last_login_ip' => '198.51.100.88',
                'password' => 'password-hash-sentinel',
            ],
            'hotel' => [
                'id' => 12,
                'name' => 'Test Hotel sessionid=session-hotel-name',
                'address' => 'private-hotel-address',
                'contact_person' => 'private-contact-person',
                'contact_phone' => 'private-contact-phone',
            ],
            'extra_data' => json_encode([
                'sessionid' => 'session-extra',
                'nested' => [
                    'jsessionid' => 'jsession-extra',
                    'sid' => 'sid-extra',
                    'url' => 'https://example.test/path?sid=sid-url&sessionid=session-url',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $safe = $this->invokeNonPublic($controller, 'sanitizeOperationLogOutputRow', [$row, true]);
        $encoded = (string)json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertSame(['id', 'username', 'realname'], array_keys($safe['user']));
        self::assertSame(['id', 'name'], array_keys($safe['hotel']));
        foreach ([
            'session-description', 'jsession-description', 'sid-description',
            'sid-error', 'session-error', 'jsession-agent', 'sid-agent', 'sid-username',
            'private-user@example.test', '13900000000', '198.51.100.88', 'password-hash-sentinel',
            'session-hotel-name', 'private-hotel-address', 'private-contact-person', 'private-contact-phone',
            'session-extra', 'jsession-extra', 'sid-extra', 'sid-url', 'session-url',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertSame('***', $safe['extra_data']['sessionid']);
        self::assertSame('***', $safe['extra_data']['nested']['jsessionid']);
        self::assertSame('***', $safe['extra_data']['nested']['sid']);
    }
}
