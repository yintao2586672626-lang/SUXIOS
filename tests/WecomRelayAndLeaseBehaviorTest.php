<?php
declare(strict_types=1);

namespace Tests;

use app\controller\WecomAibotRelay;
use app\service\WecomInboundService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;
use think\Request;
use think\Response;

final class WecomRelayAndLeaseBehaviorTest extends TestCase
{
    private const BOT_ID = 'relay-behavior-test-bot';
    private const RELAY_TOKEN = 'relay-test-token-0123456789abcdef0123456789abcdef0123456789abcdef';
    private const CALLBACK_TOKEN = 'relay-lease-callback-token';
    private const CORP_ID = 'ww-relay-lease-test-corp';

    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static array $originalEnv = [];
    private static string $connection = '';
    private static string $databasePath = '';
    private static string $aesKey = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'wecom_relay_lease_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        @unlink(self::$databasePath);

        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        self::$aesKey = rtrim(base64_encode(str_repeat("\x1b", 32)), '=');
        foreach ([
            'SUXIOS_WECOM_AIBOT_ID' => self::BOT_ID,
            'SUXIOS_WECOM_AIBOT_RELAY_TOKEN' => self::RELAY_TOKEN,
            'WECOM_INBOUND_TOKEN' => self::CALLBACK_TOKEN,
            'WECOM_INBOUND_AES_KEY' => self::$aesKey,
            'WECOM_INBOUND_CORP_ID' => self::CORP_ID,
        ] as $key => $value) {
            self::$originalEnv[$key] = Env::get($key, null);
            Env::set($key, $value);
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$originalEnv as $key => $value) {
            Env::set($key, $value);
        }
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Env::set('SUXIOS_WECOM_AIBOT_RELAY_TOKEN', self::RELAY_TOKEN);
        foreach ([
            'wecom_inbound_events',
            'wecom_inbound_bindings',
            'wecom_aibot_binding_codes',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        $this->createSchema();
    }

    public function testLoopbackWithExactLongRelayTokenPassesTheGateAndInvokesTheService(): void
    {
        $plainCode = 'RELAY234';
        $this->seedBindingCode($plainCode);
        $queryCount = 0;
        Db::listen(static function ($sql) use (&$queryCount): void {
            if (!str_starts_with((string)$sql, 'CONNECT:')) {
                $queryCount++;
            }
        });

        $response = $this->callRelay(
            'ingest',
            '127.0.0.1',
            self::RELAY_TOKEN,
            $this->bindingFrame($plainCode, 'relay-success-event-1')
        );
        $queriesDuringCall = $queryCount;
        $payload = $this->payload($response);

        self::assertSame(200, $response->getCode());
        self::assertSame(200, $payload['code']);
        self::assertTrue((bool)($payload['data']['binding_confirmation'] ?? false));
        self::assertTrue((bool)($payload['data']['reply_allowed'] ?? false));
        self::assertGreaterThan(0, $queriesDuringCall, 'A successful relay request must reach the service boundary.');
        self::assertSame(1, (int)Db::name('wecom_inbound_bindings')->count());
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->count());
        self::assertSame('used', (string)Db::name('wecom_aibot_binding_codes')->value('status'));
    }

    #[DataProvider('rejectedRelayRequests')]
    public function testRejectedRelayRequestsReturn403BeforeAnyServiceDatabaseCall(
        string $endpoint,
        string $ip,
        string $configuredToken,
        ?string $providedToken,
        string $expectedMessage
    ): void {
        $plainCode = 'DENY2345';
        $this->seedBindingCode($plainCode);
        Env::set('SUXIOS_WECOM_AIBOT_RELAY_TOKEN', $configuredToken);
        $queryCount = 0;
        Db::listen(static function ($sql) use (&$queryCount): void {
            if (!str_starts_with((string)$sql, 'CONNECT:')) {
                $queryCount++;
            }
        });

        try {
            $response = $this->callRelay(
                $endpoint,
                $ip,
                $providedToken,
                $endpoint === 'delivery'
                    ? ['status' => 'sent', 'reference' => 'must-not-be-written']
                    : $this->bindingFrame($plainCode, 'relay-denied-event-1')
            );
            $queriesDuringCall = $queryCount;
        } finally {
            Env::set('SUXIOS_WECOM_AIBOT_RELAY_TOKEN', self::RELAY_TOKEN);
        }
        $payload = $this->payload($response);

        self::assertSame(403, $response->getCode());
        self::assertSame(403, $payload['code']);
        self::assertStringContainsString($expectedMessage, (string)$payload['message']);
        self::assertSame(
            0,
            $queriesDuringCall,
            'A rejected relay request must not instantiate a database-using WeCom service path.'
        );
        self::assertSame(0, (int)Db::name('wecom_inbound_bindings')->count());
        self::assertSame(0, (int)Db::name('wecom_inbound_events')->count());
        self::assertSame('active', (string)Db::name('wecom_aibot_binding_codes')->value('status'));
    }

    public static function rejectedRelayRequests(): array
    {
        return [
            'non-loopback with correct token' => [
                'ingest', '10.20.30.40', self::RELAY_TOKEN, self::RELAY_TOKEN, '仅允许本机访问',
            ],
            'loopback without provided token' => [
                'ingest', '127.0.0.1', self::RELAY_TOKEN, null, '中继认证失败',
            ],
            'loopback with short provided token on delivery' => [
                'delivery', '::1', self::RELAY_TOKEN, 'too-short', '中继认证失败',
            ],
            'loopback with wrong long token on delivery' => [
                'delivery', '127.0.0.1', self::RELAY_TOKEN, str_repeat('x', 64), '中继认证失败',
            ],
            'loopback with missing configured token' => [
                'ingest', '127.0.0.1', '', self::RELAY_TOKEN, '中继认证失败',
            ],
        ];
    }

    public function testUnexpiredProcessingLeaseReplayKeepsClaimDigestAndQueryInvocationUnchanged(): void
    {
        $bindingKey = 'active_lease_binding_0001';
        $conversationId = 'active-lease-conversation-1';
        $this->seedVerifiedInboundBinding($bindingKey, $conversationId);
        $callback = $this->encryptedCallback($conversationId, 'active-lease-event-1');
        $queryCalls = 0;
        Db::listen(static function ($sql) use (&$queryCalls): void {
            $normalized = strtolower((string)$sql);
            if (preg_match('/\bfrom\s+[`"]?hotels[`"]?\b/', $normalized) === 1) {
                $queryCalls++;
            }
        });

        $service = new WecomInboundService();
        $completed = $service->handleCallback(
            $bindingKey,
            $callback['timestamp'],
            $callback['nonce'],
            $callback['signature'],
            $callback['xml']
        );
        self::assertGreaterThan(0, $queryCalls, 'The first callback must reach the operating-query adapter.');

        $activeEvent = $completed;
        $activeEvent['processing_status'] = 'processing';
        $activeEvent['block_code'] = null;
        $activeEvent['answer'] = [];
        $activeEvent['evidence_refs'] = [];
        $digestMethod = new ReflectionMethod(WecomInboundService::class, 'eventDigest');
        $digestMethod->setAccessible(true);
        $activeDigest = (string)$digestMethod->invoke($service, $activeEvent);
        $activeToken = str_repeat('c', 64);
        $activeLease = date('Y-m-d H:i:s', time() + 120);
        Db::name('wecom_inbound_events')->where('id', (int)$completed['id'])->update([
            'processing_status' => 'processing',
            'processing_claim_token' => $activeToken,
            'processing_lease_expires_at' => $activeLease,
            'block_code' => null,
            'answer_json' => '[]',
            'evidence_refs_json' => '[]',
            'content_digest' => $activeDigest,
        ]);
        $before = Db::name('wecom_inbound_events')->where('id', (int)$completed['id'])->find();
        self::assertIsArray($before);
        $queryCallsBeforeReplay = $queryCalls;

        try {
            $service->handleCallback(
                $bindingKey,
                $callback['timestamp'],
                $callback['nonce'],
                $callback['signature'],
                $callback['xml']
            );
            self::fail('An unexpired processing lease must reject a concurrent replay.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('正在处理', $exception->getMessage());
        }
        $queryCallsAfterReplay = $queryCalls;
        $after = Db::name('wecom_inbound_events')->where('id', (int)$completed['id'])->find();
        self::assertIsArray($after);

        self::assertSame(
            $queryCallsBeforeReplay,
            $queryCallsAfterReplay,
            'The active-lease rejection must happen before another operating query is attempted.'
        );
        foreach ([
            'processing_status',
            'processing_claim_token',
            'processing_lease_expires_at',
            'content_digest',
            'updated_at',
            'answer_json',
            'evidence_refs_json',
        ] as $field) {
            self::assertSame((string)$before[$field], (string)$after[$field], $field . ' changed during rejected replay');
        }
        self::assertSame($activeToken, (string)$after['processing_claim_token']);
        self::assertSame($activeLease, (string)$after['processing_lease_expires_at']);
        self::assertSame($activeDigest, (string)$after['content_digest']);
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->count());
    }

    private function callRelay(string $endpoint, string $ip, ?string $token, array $payload): Response
    {
        $path = $endpoint === 'delivery'
            ? '/api/internal/wecom-aibot/events/1/delivery'
            : '/api/internal/wecom-aibot/events';
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['X-SUXIOS-Relay-Token'] = $token;
        }
        $request = (new Request())
            ->setMethod('POST')
            ->setUrl($path)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withServer(['REMOTE_ADDR' => $ip])
            ->withPost($payload)
            ->withHeader($headers);
        self::$app->instance('request', $request);
        $controller = new WecomAibotRelay(self::$app);

        return $endpoint === 'delivery' ? $controller->delivery(1) : $controller->ingest();
    }

    /** @return array<string,mixed> */
    private function payload(Response $response): array
    {
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }

    /** @return array<string,mixed> */
    private function bindingFrame(string $plainCode, string $eventId): array
    {
        return [
            'aibot_id' => self::BOT_ID,
            'msg_id' => $eventId,
            'conversation_id' => 'conversation-' . $eventId,
            'sender_id' => 'sender-' . $eventId,
            'message_type' => 'text',
            'content' => '绑定门店 ' . $plainCode,
            'create_time' => time(),
        ];
    }

    private function seedBindingCode(string $plainCode): void
    {
        Db::name('wecom_aibot_binding_codes')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'code_hash' => hash('sha256', 'wecom-aibot-binding-code-v1|' . $plainCode),
            'code_mask' => substr($plainCode, 0, 2) . '******',
            'label' => 'relay behavior binding',
            'status' => 'active',
            'created_by' => 17,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'used_at' => null,
            'bound_binding_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function seedVerifiedInboundBinding(string $bindingKey, string $conversationId): void
    {
        Db::name('wecom_inbound_bindings')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'binding_key' => $bindingKey,
            'conversation_id_hash' => hash('sha256', 'wecom-conversation-v1|' . $conversationId),
            'label' => 'active lease callback',
            'transport' => 'wecom_app_callback',
            'status' => 'verified',
            'reply_enabled' => 0,
            'created_by' => 17,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{timestamp:string,nonce:string,signature:string,xml:string} */
    private function encryptedCallback(string $conversationId, string $eventId): array
    {
        $timestamp = (string)time();
        $nonce = 'nonce-active-lease-1';
        $inner = '<xml>'
            . '<MsgId><![CDATA[' . $eventId . ']]></MsgId>'
            . '<ChatId><![CDATA[' . $conversationId . ']]></ChatId>'
            . '<FromUserName><![CDATA[sender-active-lease-1]]></FromUserName>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[昨日携程曝光如何？]]></Content>'
            . '<CreateTime>' . $timestamp . '</CreateTime>'
            . '</xml>';
        $plain = str_repeat('L', 16) . pack('N', strlen($inner)) . $inner . self::CORP_ID;
        $padding = 32 - (strlen($plain) % 32);
        $plain .= str_repeat(chr($padding), $padding);
        $key = base64_decode(self::$aesKey . '=', true);
        self::assertIsString($key);
        $cipher = openssl_encrypt(
            $plain,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            substr($key, 0, 16)
        );
        self::assertIsString($cipher);
        $encrypted = base64_encode($cipher);
        $signatureParts = [self::CALLBACK_TOKEN, $timestamp, $nonce, $encrypted];
        sort($signatureParts, SORT_STRING);

        return [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => sha1(implode('', $signatureParts)),
            'xml' => '<xml><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>',
        ];
    }

    private function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE hotels (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    status INTEGER NOT NULL
)
SQL);
        Db::name('hotels')->insert([
            'id' => 20,
            'tenant_id' => 10,
            'name' => 'active lease hotel',
            'status' => 1,
        ]);
        Db::execute(<<<'SQL'
CREATE TABLE wecom_inbound_bindings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    binding_key TEXT NOT NULL UNIQUE,
    conversation_id_hash TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL DEFAULT '',
    transport TEXT NOT NULL,
    status TEXT NOT NULL,
    reply_enabled INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE wecom_aibot_binding_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL UNIQUE,
    code_mask TEXT NOT NULL,
    label TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    created_by INTEGER NOT NULL,
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    bound_binding_id INTEGER NULL,
    created_at TEXT NOT NULL
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE wecom_inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    binding_id INTEGER NOT NULL,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    external_event_id TEXT NOT NULL,
    payload_digest TEXT NOT NULL,
    occurred_at TEXT NULL,
    message_type TEXT NOT NULL,
    transport TEXT NOT NULL,
    sender_id_hash TEXT NOT NULL,
    content_text TEXT NULL,
    archive_status TEXT NOT NULL,
    processing_status TEXT NOT NULL,
    processing_claim_token TEXT NULL,
    processing_lease_expires_at TEXT NULL,
    block_code TEXT NULL,
    answer_json TEXT NULL,
    evidence_refs_json TEXT NULL,
    delivery_status TEXT NOT NULL,
    delivery_reference TEXT NULL,
    content_digest TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(binding_id, external_event_id)
)
SQL);
    }
}
