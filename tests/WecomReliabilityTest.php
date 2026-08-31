<?php
declare(strict_types=1);

namespace Tests;

use app\service\WecomAibotService;
use app\service\WecomInboundService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\facade\Env;

final class WecomReliabilityTest extends TestCase
{
    private const BOT_ID = 'test-bot-id';
    private const TOKEN = 'test-wecom-callback-token';
    private const CORP_ID = 'ww-test-corp-id';

    private static array $originalDatabaseConfig = [];
    private static array $originalEnv = [];
    private static string $connection = '';
    private static string $databasePath = '';
    private static string $aesKey = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$connection = 'wecom_reliability_' . getmypid() . '_' . bin2hex(random_bytes(4));
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

        self::$aesKey = rtrim(base64_encode(str_repeat("\x19", 32)), '=');
        foreach ([
            'SUXIOS_WECOM_AIBOT_ID' => self::BOT_ID,
            'WECOM_INBOUND_TOKEN' => self::TOKEN,
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
        Db::execute('DROP TABLE IF EXISTS wecom_inbound_events');
        Db::execute('DROP TABLE IF EXISTS wecom_inbound_bindings');
        Db::execute('DROP TABLE IF EXISTS wecom_aibot_binding_codes');
        $this->createSchema();
    }

    public function testFirstBindingFrameCanBeRetriedExactlyWithoutPersistingPlaintextCode(): void
    {
        $plainCode = 'ABCD2345';
        Db::name('wecom_aibot_binding_codes')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'code_hash' => hash('sha256', 'wecom-aibot-binding-code-v1|' . $plainCode),
            'code_mask' => 'AB******',
            'label' => 'test binding',
            'status' => 'active',
            'created_by' => 17,
            'expires_at' => date('Y-m-d H:i:s', time() + 600),
            'used_at' => null,
            'bound_binding_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $frame = [
            'aibot_id' => self::BOT_ID,
            'msg_id' => 'binding-frame-retry-1',
            'conversation_id' => 'conversation-binding-retry-1',
            'sender_id' => 'sender-binding-retry-1',
            'message_type' => 'text',
            'content' => '绑定门店 ' . $plainCode,
            'create_time' => time(),
        ];

        $service = new WecomAibotService();
        $first = $service->ingest($frame);
        $retry = $service->ingest($frame);

        self::assertFalse($first['duplicate']);
        self::assertTrue($first['binding_confirmation']);
        self::assertTrue($first['reply_allowed']);
        self::assertTrue($retry['duplicate']);
        self::assertTrue($retry['binding_confirmation']);
        self::assertFalse($retry['reply_allowed']);
        self::assertSame($first['payload_digest'], $retry['payload_digest']);
        self::assertSame(1, (int)Db::name('wecom_inbound_bindings')->count());
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->count());
        self::assertSame('绑定门店 ********', Db::name('wecom_inbound_events')->value('content_text'));
        self::assertSame('used', Db::name('wecom_aibot_binding_codes')->value('status'));

        $stored = json_encode([
            Db::name('wecom_aibot_binding_codes')->select()->toArray(),
            Db::name('wecom_inbound_bindings')->select()->toArray(),
            Db::name('wecom_inbound_events')->select()->toArray(),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($plainCode, $stored);
    }

    public function testExpiredProcessingLeaseRecoversSameCallbackAfterTerminalWriteCrash(): void
    {
        $bindingKey = 'callback_binding_retry_0001';
        $conversationId = 'conversation-callback-retry-1';
        Db::name('wecom_inbound_bindings')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'binding_key' => $bindingKey,
            'conversation_id_hash' => hash('sha256', 'wecom-conversation-v1|' . $conversationId),
            'label' => 'callback test',
            'transport' => 'wecom_app_callback',
            'status' => 'verified',
            'reply_enabled' => 0,
            'created_by' => 17,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $callback = $this->encryptedCallback($conversationId, 'callback-retry-event-1');
        $service = new WecomInboundService();
        $completed = $service->handleCallback(
            $bindingKey,
            $callback['timestamp'],
            $callback['nonce'],
            $callback['signature'],
            $callback['xml']
        );
        $interrupted = Db::name('wecom_inbound_events')->where('id', (int)$completed['id'])->find();
        self::assertIsArray($interrupted);
        $crashedEvent = $completed;
        $crashedEvent['processing_status'] = 'processing';
        $crashedEvent['block_code'] = null;
        $crashedEvent['answer'] = [];
        $crashedEvent['evidence_refs'] = [];
        $digestMethod = new \ReflectionMethod(WecomInboundService::class, 'eventDigest');
        $digestMethod->setAccessible(true);
        $crashedDigest = (string)$digestMethod->invoke($service, $crashedEvent);
        Db::name('wecom_inbound_events')->where('id', (int)$interrupted['id'])->update([
            'processing_status' => 'processing',
            'processing_claim_token' => str_repeat('a', 64),
            'processing_lease_expires_at' => date('Y-m-d H:i:s', time() - 1),
            'block_code' => null,
            'answer_json' => '[]',
            'evidence_refs_json' => '[]',
            'content_digest' => $crashedDigest,
        ]);

        $faultState = Db::name('wecom_inbound_events')->where('id', (int)$interrupted['id'])->find();
        self::assertIsArray($faultState);
        self::assertSame('processing', $faultState['processing_status']);
        self::assertSame(str_repeat('a', 64), $faultState['processing_claim_token']);
        Db::execute(<<<'SQL'
CREATE TRIGGER steal_wecom_terminal_claim
BEFORE UPDATE OF processing_status ON wecom_inbound_events
WHEN OLD.processing_status = 'processing' AND NEW.processing_status IN ('reply_ready', 'blocked', 'failed')
BEGIN
    UPDATE wecom_inbound_events
       SET processing_claim_token = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
     WHERE id = OLD.id;
    SELECT RAISE(IGNORE);
END
SQL);
        try {
            $service->handleCallback(
                $bindingKey,
                $callback['timestamp'],
                $callback['nonce'],
                $callback['signature'],
                $callback['xml']
            );
            self::fail('A stolen final claim must prevent terminal success.');
        } catch (\RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('终态更新竞争校验失败', $exception->getMessage());
        }
        $stolen = Db::name('wecom_inbound_events')->where('id', (int)$interrupted['id'])->find();
        self::assertIsArray($stolen);
        self::assertSame('processing', $stolen['processing_status']);
        self::assertSame(str_repeat('b', 64), $stolen['processing_claim_token']);

        Db::execute('DROP TRIGGER steal_wecom_terminal_claim');
        Db::name('wecom_inbound_events')->where('id', (int)$interrupted['id'])->update([
            'processing_lease_expires_at' => date('Y-m-d H:i:s', time() - 1),
        ]);
        $recovered = $service->handleCallback(
            $bindingKey,
            $callback['timestamp'],
            $callback['nonce'],
            $callback['signature'],
            $callback['xml']
        );
        self::assertFalse($recovered['duplicate']);
        self::assertContains($recovered['processing_status'], ['reply_ready', 'blocked', 'failed']);
        self::assertSame('readback_verified', $recovered['persistence_status']);

        $terminal = Db::name('wecom_inbound_events')->where('id', (int)$interrupted['id'])->find();
        self::assertIsArray($terminal);
        self::assertNull($terminal['processing_claim_token']);
        self::assertNull($terminal['processing_lease_expires_at']);
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->count());

        $duplicate = $service->handleCallback(
            $bindingKey,
            $callback['timestamp'],
            $callback['nonce'],
            $callback['signature'],
            $callback['xml']
        );
        self::assertTrue($duplicate['duplicate']);
        self::assertSame('duplicate_readback_verified', $duplicate['persistence_status']);
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->count());
    }

    public function testCustomCallbackNeverPersistsSenderChallengePlaintext(): void
    {
        $bindingKey = 'callback_sender_challenge_0001';
        $conversationId = 'conversation-sender-challenge-1';
        $plainCode = 'ABCD234567';
        Db::name('wecom_inbound_bindings')->insert([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'binding_key' => $bindingKey,
            'conversation_id_hash' => hash('sha256', 'wecom-conversation-v1|' . $conversationId),
            'label' => 'sender challenge test',
            'transport' => 'wecom_app_callback',
            'status' => 'verified',
            'reply_enabled' => 0,
            'created_by' => 17,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $callback = $this->encryptedCallback(
            $conversationId,
            'sender-challenge-event-1',
            '绑定员工 ' . $plainCode
        );

        $event = (new WecomInboundService())->handleCallback(
            $bindingKey,
            $callback['timestamp'],
            $callback['nonce'],
            $callback['signature'],
            $callback['xml']
        );

        self::assertSame('绑定员工 **********', $event['content_text']);
        self::assertSame('sender_binding_challenge_received', $event['block_code']);
        $stored = json_encode(
            Db::name('wecom_inbound_events')->select()->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        self::assertStringNotContainsString($plainCode, $stored);

        $changedCallback = $this->encryptedCallback(
            $conversationId,
            'sender-challenge-event-1',
            '绑定员工 ZXCV234567'
        );
        try {
            (new WecomInboundService())->handleCallback(
                $bindingKey,
                $changedCallback['timestamp'],
                $changedCallback['nonce'],
                $changedCallback['signature'],
                $changedCallback['xml']
            );
            self::fail('the same event id with another challenge must conflict');
        } catch (\RuntimeException $error) {
            self::assertSame(409, $error->getCode());
            self::assertStringContainsString('幂等键内容冲突', $error->getMessage());
        }
    }

    private function createSchema(): void
    {
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

    /** @return array{timestamp:string,nonce:string,signature:string,xml:string} */
    private function encryptedCallback(
        string $conversationId,
        string $eventId,
        string $content = '昨日携程曝光如何？'
    ): array
    {
        $timestamp = (string)time();
        $nonce = 'nonce-retry-1';
        $inner = '<xml>'
            . '<MsgId><![CDATA[' . $eventId . ']]></MsgId>'
            . '<ChatId><![CDATA[' . $conversationId . ']]></ChatId>'
            . '<FromUserName><![CDATA[sender-callback-retry-1]]></FromUserName>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[' . $content . ']]></Content>'
            . '<CreateTime>' . $timestamp . '</CreateTime>'
            . '</xml>';
        $plain = str_repeat('R', 16) . pack('N', strlen($inner)) . $inner . self::CORP_ID;
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
        $parts = [self::TOKEN, $timestamp, $nonce, $encrypted];
        sort($parts, SORT_STRING);

        return [
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'signature' => sha1(implode('', $parts)),
            'xml' => '<xml><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>',
        ];
    }
}
