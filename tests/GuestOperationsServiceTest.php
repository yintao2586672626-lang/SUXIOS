<?php
declare(strict_types=1);
namespace Tests;

use app\controller\GuestOperations;
use app\model\User;
use app\service\GuestOperationsService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class GuestOperationsServiceTest extends TestCase
{
    private static array $original = [];
    private static App $app;
    private static string $database = '';
    private GuestOperationsService $service;
    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__)); self::$app->initialize();
        self::$original = Config::get('database');
        self::$database = sys_get_temp_dir() . '/guest-operations-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$original; $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database'); Db::connect(null, true);
    }
    public static function tearDownAfterClass(): void
    {
        try { Db::connect('sqlite')->close(); } catch (\Throwable) {}
        Config::set(self::$original, 'database'); Db::connect(null, true); @unlink(self::$database);
    }
    protected function setUp(): void
    {
        \Tests\Support\GuestOperationsSqliteFixture::create();
        $this->service = new GuestOperationsService();
        self::$app->request->user = new User(Db::name('users')->where('id', 11)->find());
    }
    private function events(string $key = 'stays-1', ?array $events = null, string $platform = 'pms'): array
    {
        return ['idempotency_key' => $key, 'source_reference' => 'synthetic-source-202610', 'platform' => $platform, 'events' => $events ?? [
            ['event_key' => 'a1', 'guest_hash' => str_repeat('a', 64), 'stay_date' => '2026-10-01', 'status' => 'completed'],
            ['event_key' => 'a2', 'guest_hash' => str_repeat('a', 64), 'stay_date' => '2026-10-02', 'status' => 'completed'],
            ['event_key' => 'b1', 'guest_hash' => str_repeat('b', 64), 'stay_date' => '2026-10-02', 'status' => 'completed'],
        ]];
    }
    private function coverage(array $overrides = []): array
    {
        return array_replace(['idempotency_key' => 'coverage-1', 'platform' => 'pms', 'date_start' => '2026-10-01', 'date_end' => '2026-10-02', 'expected_guests' => 2, 'source_quality' => 'complete', 'source_reference' => 'synthetic-coverage-evidence', 'expected_revision' => 0], $overrides);
    }
    private function feedback(array $overrides = []): array
    {
        return array_replace(['idempotency_key' => 'feedback-1', 'case_key' => 'case-room101', 'expected_revision' => 0, 'incident_date' => '2026-10-01', 'category' => 'complaint', 'summary' => 'synthetic 清洁反馈', 'owner_user_id' => 11, 'due_at' => '2026-10-02T18:00', 'source_reference' => 'synthetic-manual-register', 'evidence_refs' => ['synthetic-evidence-1'], 'edit_reason' => ''], $overrides);
    }
    private function fact(array $overrides = []): array
    {
        return array_replace(['idempotency_key' => 'fact-1', 'expected_revision' => 1, 'action' => 'handling', 'occurred_at' => '2026-10-02T10:00', 'note' => 'synthetic 已复查清洁', 'evidence_refs' => ['synthetic-handling-1'], 'confirmation' => false, 'confirmed_by_role' => ''], $overrides);
    }
    private function overview(): array { return $this->service->overview(10, [80], 80, '2026-10-01', '2026-10-02', 'pms'); }
    public function testOverviewProvidesOnlySameTenantHotelOperationAssignees(): void
    {
        self::$app->request->user = new User(Db::name('users')->where('id', 15)->find());
        $owners = $this->overview()['owners'];
        self::assertSame([11, 15], array_column($owners, 'id'));
        foreach ($owners as $owner) self::assertSame(['id', 'name'], array_keys($owner));
        self::assertSame('Synthetic owner', $owners[0]['name']);
        foreach ($owners as $owner) {
            $this->service->saveFeedback(10, [80], 80, 11, $this->feedback([
                'idempotency_key' => 'owner-' . $owner['id'], 'case_key' => 'owner-case-' . $owner['id'], 'owner_user_id' => $owner['id'],
            ]));
        }
        $otherHotel = $this->service->overview(10, [82], 82, '2026-10-01', '2026-10-02', 'pms');
        self::assertSame([11], array_column($otherHotel['owners'], 'id'), 'another hotel must not inherit hotel 80 operators');
    }
    private function fails(callable $operation, string $message, ?int $code = null): void
    {
        $error = null;
        try { $operation(); } catch (\RuntimeException|\InvalidArgumentException $caught) { $error = $caught; }
        self::assertNotNull($error, 'expected failure: ' . $message);
        self::assertStringContainsString($message, $error->getMessage());
        if ($code !== null) self::assertSame($code, $error->getCode());
    }
    public function testRepeatRequiresExplicitSameScopeDenominatorCoverage(): void
    {
        $this->service->importStays(10, [80], 80, 11, $this->events());
        $missing = $this->overview(); self::assertNull($missing['repeat_guest']['rate']); self::assertContains('source_coverage_missing', $missing['repeat_guest']['data_gaps']);
        $this->service->saveCoverage(10, [80], 80, 11, $this->coverage());
        $ready = $this->overview(); self::assertSame('ready', $ready['data_status']); self::assertSame(1, $ready['repeat_guest']['numerator']); self::assertSame(2, $ready['repeat_guest']['denominator']); self::assertSame(0.5, $ready['repeat_guest']['rate']);
        self::assertSame(3, $ready['repeat_guest']['observed_completed_stays']); self::assertSame(10, $ready['tenant_id']); self::assertSame('pms', $ready['platform']);
        $this->service->saveCoverage(10, [80], 80, 11, $this->coverage(['idempotency_key' => 'coverage-2', 'expected_revision' => 1, 'expected_guests' => 3]));
        self::assertNull($this->overview()['repeat_guest']['rate']); self::assertContains('denominator_mismatch', $this->overview()['repeat_guest']['data_gaps']);
    }
    public function testImportReplayDedupCorrectionAndImmutableHistory(): void
    {
        $input = $this->events(); $saved = $this->service->importStays(10, [80], 80, 11, $input);
        $replay = $this->service->importStays(10, [80], 80, 11, $input); self::assertTrue($replay['idempotent_replay']); self::assertSame($saved['records'], $replay['records']);
        $dedup = $this->service->importStays(10, [80], 80, 11, $this->events('stays-other')); self::assertSame($saved['records'], $dedup['records']); self::assertSame(3, Db::name('guest_operation_records')->count());
        $correct = $input['events'][1] + ['expected_revision' => 1, 'correction_reason' => 'synthetic 撤销重复源事件']; $correct['status'] = 'void';
        $this->service->importStays(10, [80], 80, 11, $this->events('correct-1', [$correct]));
        $history = $this->service->history(10, [80], 80, 'stay_event', 'pms:a2'); self::assertCount(2, $history); self::assertSame('void', $history[0]['document']['status']); self::assertSame('completed', $history[1]['document']['status']);
        self::assertSame($saved['records'], $this->service->importStays(10, [80], 80, 11, $input)['records']);
        self::assertSame(0, $this->overview()['repeat_guest']['numerator']); self::assertSame('readback_verified', $saved['persistence_status']);
    }
    public function testImportFailureRollsBackWholeBatchAndRejectsStaleCorrection(): void
    {
        $this->service->importStays(10, [80], 80, 11, $this->events());
        $new = ['event_key' => 'c1', 'guest_hash' => str_repeat('c', 64), 'stay_date' => '2026-10-02', 'status' => 'completed'];
        $bad = ['event_key' => 'a1', 'guest_hash' => str_repeat('a', 64), 'stay_date' => '2026-10-02', 'status' => 'void', 'expected_revision' => 0, 'correction_reason' => 'synthetic incorrect revision'];
        $this->fails(fn() => $this->service->importStays(10, [80], 80, 11, $this->events('atomic-1', [$new, $bad])), 'revision_conflict');
        self::assertSame(3, Db::name('guest_operation_records')->count()); self::assertSame(1, Db::name('guest_operation_requests')->count()); self::assertSame([], $this->service->history(10, [80], 80, 'stay_event', 'pms:c1'));
        $this->fails(fn() => $this->service->importStays(10, [80], 80, 11, $this->events('stays-1', [$new])), 'idempotency_conflict');
    }
    public function testScopePlatformAndPeriodDoNotLeakOrExpand(): void
    {
        $saved = $this->service->importStays(10, [80], 80, 11, $this->events());
        $id = $saved['records'][0]['id'];
        $this->fails(fn() => $this->service->read(20, [80], 80, $id), 'hotel_not_found');
        $this->fails(fn() => $this->service->read(10, [81], 80, $id), 'hotel_not_found');
        $this->fails(fn() => $this->service->read(10, [82], 82, $id), 'record_not_found');
        self::assertSame(10, $this->service->read(0, [80], 80, $id)['tenant_id']);
        self::assertSame(0, $this->service->overview(10, [80], 80, '2026-09-01', '2026-09-30', 'pms')['repeat_guest']['denominator']);
        self::assertSame(0, $this->service->overview(10, [80], 80, '2026-10-01', '2026-10-02', 'ctrip')['repeat_guest']['denominator']);
    }
    public function testZeroAndPartialNeverBecomeAZeroRepeatRate(): void
    {
        $this->service->saveCoverage(10, [80], 80, 11, $this->coverage(['expected_guests' => 0]));
        self::assertNull($this->overview()['repeat_guest']['rate']); self::assertContains('denominator_zero', $this->overview()['repeat_guest']['data_gaps']);
        $this->service->importStays(10, [80], 80, 11, $this->events());
        $this->service->saveCoverage(10, [80], 80, 11, $this->coverage(['idempotency_key' => 'coverage-partial', 'expected_revision' => 1, 'expected_guests' => null, 'source_quality' => 'partial']));
        self::assertNull($this->overview()['repeat_guest']['rate']); self::assertContains('explicit_denominator_missing', $this->overview()['repeat_guest']['data_gaps']);
    }
    public function testPrivacyAndMalformedDatesAreRejectedBeforeWrites(): void
    {
        $event = $this->events()['events'][0]; $event['name'] = 'synthetic Guest';
        $this->fails(fn() => $this->service->importStays(10, [80], 80, 11, $this->events('privacy', [$event])), '不支持的字段');
        unset($event['name']); $event['guest_hash'] = '13812345678';
        $this->fails(fn() => $this->service->importStays(10, [80], 80, 11, $this->events('privacy-2', [$event])), 'guest_hash');
        $event['guest_hash'] = str_repeat('a', 64); $event['stay_date'] = '2026-02-30';
        $this->fails(fn() => $this->service->importStays(10, [80], 80, 11, $this->events('date-bad', [$event])), '日期');
        self::assertSame(0, Db::name('guest_operation_records')->count());
    }
    public function testFeedbackEditingFactsCloseAndReplayKeepExactHistory(): void
    {
        $input = $this->feedback(); $saved = $this->service->saveFeedback(10, [80], 80, 11, $input);
        $edited = $this->service->saveFeedback(10, [80], 80, 11, $this->feedback(['idempotency_key' => 'feedback-edit', 'expected_revision' => 1, 'summary' => 'synthetic 复查登记更正', 'edit_reason' => 'synthetic 更正描述']));
        $handling = $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $this->fact(['expected_revision' => 2]));
        self::assertSame('in_progress', $handling['records'][0]['document']['status']);
        $close = $this->fact(['idempotency_key' => 'close-1', 'expected_revision' => 3, 'action' => 'close', 'confirmation' => true, 'confirmed_by_role' => 'manager']);
        $closed = $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $close);
        self::assertSame('closed', $closed['records'][0]['document']['status']); self::assertCount(2, $closed['records'][0]['document']['facts']);
        self::assertSame($closed['records'], $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $close)['records']);
        self::assertSame($saved['records'], $this->service->saveFeedback(10, [80], 80, 11, $input)['records']);
        self::assertSame('synthetic 清洁反馈', $this->service->read(10, [80], 80, $saved['records'][0]['id'])['document']['summary']);
        $history = $this->service->history(10, [80], 80, 'feedback', 'case-room101'); self::assertCount(4, $history); self::assertSame(4, $history[0]['revision']); self::assertSame('open', $history[3]['document']['status']);
        $reopened = $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $this->fact(['idempotency_key' => 'reopen-1', 'expected_revision' => 4, 'action' => 'reopen', 'note' => 'synthetic 再次反馈须复核']));
        self::assertSame('open', $reopened['records'][0]['document']['status']);
        self::assertSame($edited['records'][0]['content_digest'], $this->service->read(10, [80], 80, $edited['records'][0]['id'])['content_digest']);
    }
    public function testFeedbackClosureRequiresEvidenceExplicitConfirmationAndAssigneeAccess(): void
    {
        foreach ([12, 13, 14, 999] as $owner) $this->fails(fn() => $this->service->saveFeedback(10, [80], 80, 11, $this->feedback(['owner_user_id' => $owner])), '责任人');
        $this->service->saveFeedback(10, [80], 80, 11, $this->feedback());
        foreach ([['confirmation' => false], ['evidence_refs' => []], ['confirmed_by_role' => '']] as $missing) {
            $input = array_replace($this->fact(['action' => 'close', 'confirmation' => true, 'confirmed_by_role' => 'guest']), $missing);
            $this->fails(fn() => $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $input), '关闭必须');
        }
        $this->fails(fn() => $this->service->saveFeedback(10, [80], 80, 11, $this->feedback(['idempotency_key' => 'edit-missing', 'expected_revision' => 1])), '编辑必须');
        self::assertCount(1, $this->service->history(10, [80], 80, 'feedback', 'case-room101'));
    }
    public function testFeedbackFactReplayCannotTargetAnotherCase(): void
    {
        $this->service->saveFeedback(10, [80], 80, 11, $this->feedback());
        $this->service->saveFeedback(10, [80], 80, 11, $this->feedback(['idempotency_key' => 'feedback-other', 'case_key' => 'case-other']));
        $input = $this->fact();
        $saved = $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $input);
        $this->fails(fn() => $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-other', $input), 'idempotency_conflict', 409);
        self::assertSame([], $this->service->history(10, [80], 80, 'feedback', 'case-other')[0]['document']['facts']);
        self::assertSame($saved['records'], $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $input)['records']);
    }
    public function testFeedbackDateCorrectionCannotInvalidateSavedFacts(): void
    {
        $this->service->saveFeedback(10, [80], 80, 11, $this->feedback());
        $fact = $this->service->appendFeedbackFact(10, [80], 80, 11, 'case-room101', $this->fact());
        $this->fails(fn() => $this->service->saveFeedback(10, [80], 80, 11, $this->feedback([
            'idempotency_key' => 'date-correction', 'expected_revision' => 2, 'incident_date' => '2026-10-03',
            'due_at' => '2026-10-04T18:00', 'edit_reason' => 'synthetic date correction',
        ])), '发生日期', 0);
        self::assertCount(2, $this->service->history(10, [80], 80, 'feedback', 'case-room101'));
        self::assertSame($fact['records'][0]['content_digest'], $this->service->history(10, [80], 80, 'feedback', 'case-room101')[0]['content_digest']);
        $edited = $this->service->saveFeedback(10, [80], 80, 11, $this->feedback([
            'idempotency_key' => 'valid-date-correction', 'expected_revision' => 2, 'incident_date' => '2026-09-30', 'edit_reason' => 'synthetic prior date correction',
        ]));
        self::assertSame($fact['records'][0]['document']['facts'], $edited['records'][0]['document']['facts']);
    }
    public function testOverviewRejectsWrongMissingAndStaleHeadReferences(): void
    {
        $saved = $this->service->importStays(10, [80], 80, 11, $this->events());
        $this->service->saveCoverage(10, [80], 80, 11, $this->coverage());
        foreach ([['record_id' => $saved['records'][2]['id']], ['record_id' => 999999], ['record_id' => $saved['records'][0]['id'], 'revision' => 2]] as $change) {
            Db::name('guest_operation_heads')->where('record_key', 'pms:a1')->update($change);
            $this->fails(fn() => $this->overview(), 'head_readback_drift', 409);
            Db::name('guest_operation_heads')->where('record_key', 'pms:a1')->update(['record_id' => $saved['records'][0]['id'], 'revision' => 1]);
        }
        self::assertSame(0.5, $this->overview()['repeat_guest']['rate']);
    }
    public function testRoleDeniedUserCannotWriteOrBeAssignedDespiteHotelGrant(): void
    {
        $user = new User(Db::name('users')->where('id', 16)->find());
        self::assertFalse($user->hasHotelPermission(80, 'operation.execute'));
        self::assertSame(403, $this->controller($user, [], $this->events() + ['hotel_id' => 80])->importStays()->getCode());
        self::assertSame(0, Db::name('guest_operation_records')->count());
        self::assertNotContains(16, array_column($this->overview()['owners'], 'id'));
        $this->fails(fn() => $this->service->saveFeedback(10, [80], 80, 11, $this->feedback(['owner_user_id' => 16])), '责任人', 0);
        $this->service->saveFeedback(10, [80], 80, 11, $this->feedback());
        self::assertSame(403, $this->controller($user, ['hotel_id' => 80], $this->fact([
            'action' => 'close', 'confirmation' => true, 'confirmed_by_role' => 'manager',
        ]))->appendFact('case-room101')->getCode());
        self::assertSame('open', $this->service->history(10, [80], 80, 'feedback', 'case-room101')[0]['document']['status']);
        Db::name('roles')->where('id', 3)->update(['permissions' => '[]']);
        $deniedViewer = new User(Db::name('users')->where('id', 16)->find());
        self::assertSame(403, $this->controller($deniedViewer, ['hotel_id' => 80, 'date_start' => '2026-10-01', 'date_end' => '2026-10-02', 'platform' => 'pms'])->overview()->getCode());
    }
    public function testEntryConfigurationIsAuthenticatedScopedVersionedAndNotPublicSubmission(): void
    {
        $entry = ['idempotency_key' => 'entry-1', 'entry_key' => 'room101', 'room_label' => '101', 'label' => 'synthetic 员工反馈', 'enabled' => true];
        $saved = $this->service->saveEntry(10, [80], 80, 11, $entry); $document = $saved['records'][0]['document'];
        self::assertFalse($document['anonymous_submission_enabled']); self::assertSame('authenticated_staff_only', $document['access_mode']);
        self::assertSame('/?page=operating-finance&workspace=guests&hotel_id=80&feedback_entry=room101', $document['entry_path']);
        $this->service->saveEntry(10, [80], 80, 11, array_replace($entry, ['idempotency_key' => 'entry-disable', 'expected_revision' => 1, 'enabled' => false]));
        self::assertFalse($this->overview()['feedback_entries'][0]['document']['enabled']); self::assertTrue($this->service->read(10, [80], 80, $saved['records'][0]['id'])['document']['enabled']);
    }
    public function testDigestAndSchemaFailuresAreObservable(): void
    {
        $saved = $this->service->importStays(10, [80], 80, 11, $this->events()); $id = $saved['records'][0]['id'];
        Db::name('guest_operation_records')->where('id', $id)->update(['content_json' => '{}']);
        $this->fails(fn() => $this->service->read(10, [80], 80, $id), 'digest_drift');
        Db::execute('DROP TABLE guest_operation_heads');
        $this->fails(fn() => $this->overview(), 'storage_unavailable');
    }
    public function testHotelRenumberingPreservesSourceScopeAndImmutablePayloadDigest(): void
    {
        $saved = $this->service->importStays(10, [80], 80, 11, $this->events()); $id = $saved['records'][0]['id'];
        $before = Db::name('guest_operation_records')->where('id', $id)->find();
        foreach (['guest_operation_records', 'guest_operation_heads', 'guest_operation_requests'] as $table) Db::name($table)->where('hotel_id', 80)->update(['hotel_id' => 82]);
        $after = Db::name('guest_operation_records')->where('id', $id)->find();
        self::assertSame($before['content_json'], $after['content_json']); self::assertSame($before['content_digest'], $after['content_digest']);
        $read = $this->service->read(10, [82], 82, $id); self::assertSame(82, $read['hotel_id']); self::assertSame(80, $read['source_hotel_id']); self::assertSame(['tenant_id' => 10, 'hotel_id' => 80], $read['source_scope']); self::assertTrue($read['readback_verified']);
        self::assertSame(2, $this->service->overview(10, [82], 82, '2026-10-01', '2026-10-02', 'pms')['repeat_guest']['denominator']);
        $replayed = $this->service->importStays(10, [82], 82, 11, $this->events()); self::assertTrue($replayed['idempotent_replay']); self::assertSame($read, $replayed['records'][0]);
        $this->fails(fn() => $this->service->read(10, [80], 80, $id), 'record_not_found');
    }
    private function controller(?User $user, array $params, array $body = []): GuestOperations
    {
        self::$app->request->user = $user;
        $reflection = new \ReflectionClass(GuestOperations::class); $controller = $reflection->newInstanceWithoutConstructor();
        $request = new class($params, $body) {
            public function __construct(private array $params, private array $body) {}
            public function param(string $name, mixed $default = null): mixed { return $this->params[$name] ?? $this->body[$name] ?? $default; }
            public function post(): array { return $this->body; }
            public function method(): string { return $this->body ? 'POST' : 'GET'; }
            public function getContent(): string { return ''; }
        };
        foreach (['currentUser' => $user, 'request' => $request] as $name => $value) $reflection->getParentClass()->getProperty($name)->setValue($controller, $value);
        return $controller;
    }
    public function testControllerAuthenticationAndReadWriteCapabilities(): void
    {
        self::assertSame(401, $this->controller(null, ['hotel_id' => 80])->overview()->getCode());
        $viewer = new User(Db::name('users')->where('id', 15)->find());
        $viewResponse = $this->controller($viewer, ['hotel_id' => 80, 'date_start' => '2026-10-01', 'date_end' => '2026-10-02', 'platform' => 'pms'])->overview();
        self::assertSame(200, $viewResponse->getCode(), $viewResponse->getContent());
        $denied = new User(Db::name('users')->where('id', 13)->find());
        self::assertSame(403, $this->controller($denied, [], $this->events() + ['hotel_id' => 80])->importStays()->getCode());
        self::assertSame(403, $this->controller($viewer, ['hotel_id' => 81])->overview()->getCode());
        $admin = new User(Db::name('users')->where('id', 11)->find());
        $response = $this->controller($admin, [], $this->events() + ['hotel_id' => 80])->importStays();
        self::assertSame(200, $response->getCode()); self::assertSame(10, json_decode($response->getContent(), true)['data']['tenant_id']);
    }
}
