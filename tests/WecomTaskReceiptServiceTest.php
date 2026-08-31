<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperatingFinance;
use app\service\WecomInboundService;
use app\service\WecomTaskReceiptService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class WecomTaskReceiptServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    /** @var array<string,mixed> */
    private array $scopeFacts = [];

    /** @var array<string,int|string> */
    private array $lastResolverContext = [];

    /** @var array<int,array<string,mixed>> */
    private array $events = [];

    /** @var array<string,int> */
    private array $lastEventReaderContext = [];

    private WecomTaskReceiptService $service;

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wecom_task_receipt_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        foreach ([WecomTaskReceiptService::TABLE, 'operation_execution_tasks'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute(
            'CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'status TEXT NOT NULL, operator_id INTEGER NOT NULL DEFAULT 0, target_value_json TEXT, updated_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE wecom_task_receipts ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, contract_version TEXT NOT NULL, tenant_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, source_hotel_id INTEGER NOT NULL, task_id INTEGER NOT NULL, source_event_id INTEGER NOT NULL, '
            . 'source_binding_id INTEGER NOT NULL, source_event_ref TEXT NOT NULL, source_binding_ref TEXT NOT NULL, '
            . 'task_ref TEXT NOT NULL, sender_id_hash TEXT NOT NULL, reported_status TEXT NOT NULL, '
            . 'reported_amount DECIMAL(20,2) NULL, reported_amount_status TEXT NOT NULL, result_digest TEXT NOT NULL, '
            . 'evidence_note_digest TEXT NOT NULL, structured_payload_digest TEXT NOT NULL, '
            . 'source_event_payload_digest TEXT NOT NULL, source_event_content_digest TEXT NOT NULL, '
            . 'binding_scope_digest TEXT NOT NULL, sender_scope_digest TEXT NOT NULL, task_scope_digest TEXT NOT NULL, '
            . 'task_status_at_receipt TEXT NOT NULL, input_digest TEXT NOT NULL, content_digest TEXT NOT NULL, created_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id,hotel_id,source_event_id,task_id))'
        );
        Db::name('operation_execution_tasks')->insert([
            'id' => 321,
            'tenant_id' => 10,
            'hotel_id' => 80,
            'status' => 'pending_execute',
            'operator_id' => 0,
            'target_value_json' => json_encode(['assignee_id' => 77], JSON_THROW_ON_ERROR),
            'updated_at' => '2026-08-30 09:00:00',
        ]);
        $senderHash = hash('sha256', 'wecom-sender-v1|employee-77');
        $this->scopeFacts = [
            'binding' => ['id' => 12, 'tenant_id' => 10, 'hotel_id' => 80, 'status' => 'verified'],
            'sender' => [
                'binding_id' => 12,
                'tenant_id' => 10,
                'hotel_id' => 80,
                'sender_id_hash' => $senderHash,
                'actor_id' => 77,
                'status' => 'verified',
            ],
            'task' => [
                'id' => 321,
                'tenant_id' => 10,
                'hotel_id' => 80,
                'assignee_id' => 77,
                'status' => 'pending_execute',
                'deleted_at' => null,
            ],
        ];
        $this->lastResolverContext = [];
        $this->events = [];
        $this->lastEventReaderContext = [];
        $this->service = new WecomTaskReceiptService(
            function (array $context): array {
                $this->lastResolverContext = $context;
                return $this->scopeFacts;
            },
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-30 10:11:12.123456',
                new DateTimeZone('Asia/Shanghai')
            ),
            function (array $context): array {
                $this->lastEventReaderContext = $context;
                $id = (int)($context['source_event_id'] ?? 0);
                if (!isset($this->events[$id])) {
                    throw new RuntimeException('test_event_not_found', 404);
                }
                return $this->events[$id];
            }
        );
    }

    public function testPersistsPrivacyMinimizedClaimWithoutMutatingTaskOrClaimingSuccess(): void
    {
        $content = $this->structuredContent(
            'completed',
            '客房巡检已按清单完成。',
            '现场照片保留在门店授权资料库。',
            '120.50'
        );
        $event = $this->event($content);
        $taskBefore = Db::name('operation_execution_tasks')->where('id', 321)->find();

        $saved = $this->recordEvent($event);
        $taskAfter = Db::name('operation_execution_tasks')->where('id', 321)->find();

        self::assertTrue($saved['created']);
        self::assertFalse($saved['replayed']);
        self::assertTrue($saved['readback_verified']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame('completed', $saved['reported_status']);
        self::assertSame('120.50', $saved['reported_amount']);
        self::assertSame('unverified_sender_reported', $saved['reported_amount_status']);
        self::assertSame('unverified_employee_report', $saved['boundaries']['receipt_semantics']);
        self::assertFalse($saved['boundaries']['human_approval_recorded']);
        self::assertFalse($saved['boundaries']['task_state_mutated']);
        self::assertFalse($saved['boundaries']['execution_success_verified']);
        self::assertFalse($saved['boundaries']['external_send_performed']);
        self::assertFalse($saved['boundaries']['ota_write']);
        self::assertFalse($saved['boundaries']['pms_write']);
        self::assertFalse($saved['boundaries']['financial_fact_created']);
        self::assertFalse($saved['boundaries']['raw_sender_identifier_persisted']);
        self::assertTrue($saved['boundaries']['pseudonymous_sender_hash_persisted']);
        self::assertSame($taskBefore, $taskAfter);
        self::assertArrayNotHasKey('content_text', $this->lastResolverContext);
        self::assertArrayNotHasKey('result', $this->lastResolverContext);
        self::assertArrayNotHasKey('evidence_note', $this->lastResolverContext);
        self::assertSame(['tenant_id' => 10, 'hotel_id' => 80, 'source_event_id' => 9001], $this->lastEventReaderContext);

        $stored = (array)Db::name(WecomTaskReceiptService::TABLE)->where('id', (int)$saved['id'])->find();
        self::assertArrayNotHasKey('result_text', $stored);
        self::assertArrayNotHasKey('evidence_note_text', $stored);
        self::assertArrayNotHasKey('content_text', $stored);
        self::assertFalse(in_array('客房巡检已按清单完成。', array_values($stored), true));
        self::assertFalse(in_array('现场照片保留在门店授权资料库。', array_values($stored), true));
        self::assertSame(hash('sha256', $this->encode('客房巡检已按清单完成。')), $saved['result_digest']);
        self::assertSame(
            hash('sha256', $this->encode('现场照片保留在门店授权资料库。')),
            $saved['evidence_note_digest']
        );

        $readback = $this->service->read((int)$saved['id'], 10, 80, 321, (int)$event['id']);
        self::assertSame($saved['content_digest'], $readback['content_digest']);
        self::assertSame('wecom_inbound_events#9001/result', $readback['result_ref']);
        self::assertSame('wecom_inbound_events#9001/evidence_note', $readback['evidence_note_ref']);

        Db::name(WecomTaskReceiptService::TABLE)->where('id', (int)$saved['id'])->update(['hotel_id' => 90]);
        $migrated = $this->service->read((int)$saved['id'], 10, 90, 321, (int)$event['id']);
        self::assertSame(90, $migrated['hotel_id']);
        self::assertSame(80, $migrated['source_hotel_id']);
    }

    public function testIdenticalEventReplaysOneReceiptAndChangedReplayConflicts(): void
    {
        $firstEvent = $this->event($this->structuredContent(
            'in_progress',
            '已完成首轮检查。',
            '等待工程人员复核。'
        ));
        $first = $this->recordEvent($firstEvent);
        $replayed = $this->recordEvent($firstEvent);

        self::assertSame($first['id'], $replayed['id']);
        self::assertFalse($replayed['created']);
        self::assertTrue($replayed['replayed']);
        self::assertSame(1, (int)Db::name(WecomTaskReceiptService::TABLE)->count());

        $changed = $this->event($this->structuredContent(
            'completed',
            '第二次内容试图覆盖首条回执。',
            '相同事件编号但正文不同。'
        ));
        try {
            $this->recordEvent($changed);
            self::fail('changed replay must conflict');
        } catch (RuntimeException $error) {
            self::assertSame('wecom_task_receipt_idempotency_conflict', $error->getMessage());
            self::assertSame(409, $error->getCode());
        }
        self::assertSame(1, (int)Db::name(WecomTaskReceiptService::TABLE)->count());
    }

    public function testRejectsFreeTextApprovalFieldsUnboundedStatusAndAmbiguousAmount(): void
    {
        $invalidContents = [
            '任务321已完成，请自动审批',
            json_encode([
                'task_id' => 321,
                'status' => 'completed',
                'result' => '已完成',
                'evidence_note' => '已检查',
                'approve' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $this->structuredContent('approved', '请求审批。', '这不是允许的回执状态。'),
            $this->structuredContent('success', '请求记为成功。', '成功不能由回执直接证明。'),
            json_encode([
                'task_id' => 321,
                'status' => 'completed',
                'result' => '已完成',
                'evidence_note' => '已检查',
                'amount' => 120.5,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        foreach ($invalidContents as $index => $content) {
            try {
                $this->recordEvent($this->event((string)$content, 9100 + $index));
                self::fail('invalid structured receipt must be rejected');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame(0, (int)Db::name(WecomTaskReceiptService::TABLE)->count());
    }

    public function testRejectsUnverifiedArchiveBindingSenderAndCrossScopeTask(): void
    {
        $content = $this->structuredContent('blocked', '暂时无法继续。', '等待门店授权人员处理。');

        $unverifiedEvent = $this->event($content, 9201);
        $unverifiedEvent['archive_status'] = 'received';
        $unverifiedEvent['content_digest'] = $this->eventDigest($unverifiedEvent);
        $this->assertRuntimeFailure(
            fn() => $this->recordEvent($unverifiedEvent),
            'wecom_task_receipt_archived_event_invalid'
        );

        $pendingEvent = $this->event($content, 9205);
        $pendingEvent['processing_status'] = 'pending';
        $pendingEvent['content_digest'] = $this->eventDigest($pendingEvent);
        $this->assertRuntimeFailure(
            fn() => $this->recordEvent($pendingEvent),
            'wecom_task_receipt_archived_event_invalid'
        );

        $this->scopeFacts['binding']['status'] = 'pending_verification';
        $this->assertRuntimeFailure(
            fn() => $this->recordEvent($this->event($content, 9202)),
            'wecom_task_receipt_binding_scope_invalid'
        );

        $this->scopeFacts['binding']['status'] = 'verified';
        $this->scopeFacts['sender']['actor_id'] = 78;
        $this->assertRuntimeFailure(
            fn() => $this->recordEvent($this->event($content, 9203)),
            'wecom_task_receipt_sender_not_assignee'
        );

        $this->scopeFacts['sender']['actor_id'] = 77;
        $this->scopeFacts['task']['hotel_id'] = 81;
        $this->assertRuntimeFailure(
            fn() => $this->recordEvent($this->event($content, 9204)),
            'wecom_task_receipt_task_scope_invalid'
        );
        self::assertSame(0, (int)Db::name(WecomTaskReceiptService::TABLE)->count());
    }

    public function testTamperedReceiptFailsExactReadback(): void
    {
        $event = $this->event($this->structuredContent(
            'acknowledged',
            '任务已收到。',
            '尚未开始执行。'
        ));
        $saved = $this->recordEvent($event);
        Db::name(WecomTaskReceiptService::TABLE)->where('id', (int)$saved['id'])->update([
            'reported_status' => 'completed',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wecom_task_receipt_payload_digest_drift');
        $this->service->read((int)$saved['id'], 10, 80, 321, (int)$event['id']);
    }

    public function testOperatingFinanceOverviewVerifiesLatestReceiptBeforeReportingReady(): void
    {
        $event = $this->event($this->structuredContent(
            'acknowledged',
            '任务已收到。',
            '尚未开始执行。'
        ));
        $saved = $this->recordEvent($event);
        $controller = (new \ReflectionClass(OperatingFinance::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(OperatingFinance::class, 'wecomReceiptSummary');
        $method->setAccessible(true);

        $summary = $method->invoke($controller, 10, 80);
        self::assertSame('ready', $summary['status']);
        self::assertSame(1, $summary['receipt_count']);
        self::assertTrue($summary['latest']['readback_verified']);
        self::assertSame('readback_verified', $summary['latest']['persistence_status']);

        Db::name(WecomTaskReceiptService::TABLE)->where('id', (int)$saved['id'])->update([
            'reported_status' => 'completed',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wecom_task_receipt_payload_digest_drift');
        $method->invoke($controller, 10, 80);
    }

    public function testMigrationDeclaresAppendOnlyPrivacyMinimizedContract(): void
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260830_z_create_wecom_task_receipts.sql'
        );
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS `wecom_task_receipts`', $sql);
        self::assertStringContainsString('trg_wecom_task_receipt_no_update', $sql);
        self::assertStringContainsString('trg_wecom_task_receipt_no_delete', $sql);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $sql);
        self::assertStringContainsString('`source_hotel_id`', $sql);
        self::assertStringContainsString('@suxi_cloud_hotel_id_migration', $sql);
        self::assertStringNotContainsString('`content_text`', $sql);
        self::assertStringNotContainsString('`result_text`', $sql);
        self::assertStringNotContainsString('`evidence_note_text`', $sql);
        self::assertStringNotContainsString('`employee_name`', $sql);
    }

    private function assertRuntimeFailure(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('expected scoped receipt failure');
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function recordEvent(array $event): array
    {
        $id = (int)($event['id'] ?? 0);
        $this->events[$id] = $event;
        return $this->service->record(10, 80, 321, $id);
    }

    private function structuredContent(
        string $status,
        string $result,
        string $evidenceNote,
        ?string $amount = null
    ): string {
        $payload = [
            'task_id' => 321,
            'status' => $status,
            'result' => $result,
            'evidence_note' => $evidenceNote,
        ];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function event(string $content, int $id = 9001): array
    {
        $event = [
            'contract_version' => WecomInboundService::CONTRACT_VERSION,
            'id' => $id,
            'binding_id' => 12,
            'tenant_id' => 10,
            'hotel_id' => 80,
            'external_event_id' => 'evt-' . $id,
            'payload_digest' => hash('sha256', 'payload|' . $content),
            'occurred_at' => '2026-08-30 10:10:00',
            'message_type' => 'text',
            'transport' => 'wecom_app_callback',
            'sender_id_hash' => hash('sha256', 'wecom-sender-v1|employee-77'),
            'content_text' => $content,
            'archive_status' => 'readback_verified',
            'processing_status' => 'blocked',
            'block_code' => 'query_blocked',
            'answer' => [],
            'evidence_refs' => [],
            'delivery_status' => 'not_sent',
            'delivery_reference' => null,
        ];
        $event['content_digest'] = $this->eventDigest($event);
        return $event;
    }

    /** @param array<string,mixed> $event */
    private function eventDigest(array $event): string
    {
        $payload = array_intersect_key($event, array_flip([
            'contract_version', 'binding_id', 'tenant_id', 'hotel_id', 'external_event_id',
            'payload_digest', 'occurred_at', 'message_type', 'transport', 'sender_id_hash', 'content_text',
            'archive_status', 'processing_status', 'block_code', 'answer', 'evidence_refs', 'delivery_status',
            'delivery_reference',
        ]));
        return hash('sha256', $this->encode($this->canonicalize($payload)));
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
