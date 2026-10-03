<?php
declare(strict_types=1);
namespace Tests;

use app\controller\GuestFeedback;
use app\controller\GuestOperations;
use app\model\User;
use app\service\GuestOperationRecordStore;
use app\service\GuestOperationsService;
use app\service\GuestPublicFeedbackService;
use app\service\GuestRoomRegistryService;
use app\service\GuestStayFileImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class GuestOperationsCompanionTest extends TestCase
{
    private static App $app; private static array $original; private static string $database; private array $files = [];
    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__)); self::$app->initialize(); self::$original = Config::get('database');
        self::$database = sys_get_temp_dir() . '/guest-companion-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = self::$original; $config['default'] = 'sqlite'; $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$database, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database'); Db::connect(null, true);
    }
    public static function tearDownAfterClass(): void
    {
        try { Db::connect('sqlite')->close(); } catch (\Throwable) {}
        Config::set(self::$original, 'database'); Db::connect(null, true); @unlink(self::$database);
    }
    protected function setUp(): void
    {
        \Tests\Support\GuestOperationsSqliteFixture::create(); self::$app->request->user = new User(Db::name('users')->where('id', 11)->find());
    }
    protected function tearDown(): void { foreach ($this->files as $path) @unlink($path); }
    private function fails(callable $operation, string $message, ?int $code = null): void
    {
        $error = null; try { $operation(); } catch (\Throwable $caught) { $error = $caught; }
        self::assertNotNull($error); self::assertStringContainsString($message, $error->getMessage()); if ($code !== null) self::assertSame($code, $error->getCode());
    }
    private function rooms(int $hotel = 80): array
    {
        (new GuestRoomRegistryService())->save(10, [80, 82], $hotel, 11, ['confirmed_physical_rooms' => true, 'rooms' => [['room_number' => '101'], ['room_number' => '102']]]);
        return (new GuestRoomRegistryService())->list(10, $hotel);
    }
    private function entry(array $override = [], ?array $rooms = null): array
    {
        $rooms ??= $this->rooms();
        return (new GuestPublicFeedbackService())->configure(10, [80], 80, 11, array_replace(['idempotency_key' => 'public-config', 'room_ids' => array_column($rooms, 'room_id'), 'owner_user_id' => 11, 'label' => '合成宾客反馈', 'enabled' => true, 'rotate_tokens' => false, 'expected_revisions' => []], $override));
    }
    private function token(array $saved): string { return explode('#', $saved['entry_links'][0]['entry_path'])[1]; }
    private function submission(string $key = 'a'): array { return ['request_key' => str_repeat($key, 32), 'summary' => '合成反馈：请检查空调', 'category' => 'complaint']; }
    public function testRoomsAreRealScopedStableRecordsWithAtomicBatchAndDisabledLookup(): void
    {
        $rooms = $this->rooms(); self::assertCount(2, $rooms); self::assertNotSame($rooms[0]['room_id'], $rooms[1]['room_id']);
        $service = new GuestRoomRegistryService(); $id = $rooms[0]['room_id'];
        $service->save(10, [80], 80, 11, ['confirmed_physical_rooms' => true, 'rooms' => [['room_number' => '101', 'active' => false, 'expected_revision' => 1]]]);
        self::assertSame($id, $service->list(10, 80)[0]['room_id']); self::assertSame(2, $service->list(10, 80)[0]['revision']);
        $this->fails(fn() => $service->requireActive(10, 80, $id), '停用', 409);
        $this->fails(fn() => $service->requireActive(10, 82, $rooms[1]['room_id']), '当前租户酒店', 404);
        $this->fails(fn() => $service->save(10, [80], 80, 11, ['confirmed_physical_rooms' => true, 'rooms' => [['room_number' => '103'], ['room_number' => '103']]]), '重复');
        self::assertCount(2, $service->list(10, 80));
        $this->fails(fn() => $service->save(10, [80], 80, 11, ['rooms' => [['room_number' => '103']]]), '人工确认');
    }
    public function testPublicCapabilitiesAreDigestOnlyIssuedOnceAndPrivateRecordsCannotBeRead(): void
    {
        $saved = $this->entry(); $token = $this->token($saved); $service = new GuestPublicFeedbackService();
        self::assertCount(2, $saved['entry_links']); self::assertSame(64, strlen($token));
        self::assertSame('101', $service->entry($token)['room_label']);
        $serialized = json_encode(Db::name('guest_operation_records')->select()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token, $serialized);
        $replay = $this->entry([], (new GuestRoomRegistryService())->list(10, 80)); self::assertSame([], $replay['entry_links']); self::assertSame('already_issued_regenerate_if_lost', $replay['token_delivery']);
        $pointer = Db::name('guest_operation_records')->where('kind', 'guest_public_pointer')->find();
        $this->fails(fn() => (new GuestOperationsService())->read(10, [80], 80, (int)$pointer['id']), 'guest_record_not_found', 404);
        $this->fails(fn() => (new GuestOperationsService())->history(10, [80], 80, 'guest_public_pointer', $pointer['record_key']), 'kind');
        $this->fails(fn() => $service->entry(str_repeat('0', 64)), '不存在', 404);
    }
    public function testPublicSubmissionPersistsScopedRoomCaseAndPreservesStaffClosureHistory(): void
    {
        $saved = $this->entry(); $token = $this->token($saved); $public = new GuestPublicFeedbackService();
        $receipt = $public->submit($token, '127.0.0.2', $this->submission()); self::assertSame('readback_verified', $receipt['submission_status']);
        self::assertArrayNotHasKey('records', $receipt); self::assertSame($receipt, $public->submit($token, '127.0.0.2', $this->submission()));
        $store = new GuestOperationRecordStore(); $case = $store->latest(10, 80, 'feedback', $receipt['receipt']);
        self::assertSame(0, $case['created_by']); self::assertSame($saved['records'][0]['document']['room_id'], $case['document']['room_id']); self::assertSame('guest_public_submission', $case['document']['source_method']);
        $ops = new GuestOperationsService();
        $edit = $ops->saveFeedback(10, [80], 80, 11, ['idempotency_key' => 'staff-edit', 'case_key' => $receipt['receipt'], 'expected_revision' => 1, 'incident_date' => $case['document']['incident_date'], 'category' => 'complaint', 'summary' => '合成反馈已分配', 'owner_user_id' => 11, 'due_at' => $case['document']['due_at'], 'source_reference' => 'synthetic staff review', 'evidence_refs' => [], 'edit_reason' => '分配事实']);
        self::assertSame($case['document']['guest_submission'], $edit['records'][0]['document']['guest_submission']); self::assertSame('guest_public_submission', $edit['records'][0]['document']['source_method']);
        $closed = $ops->appendFeedbackFact(10, [80], 80, 11, $receipt['receipt'], ['idempotency_key' => 'staff-close', 'expected_revision' => 2, 'action' => 'close', 'occurred_at' => (new GuestOperationRecordStore())->now(), 'note' => '合成人工确认处理完成', 'evidence_refs' => ['synthetic-confirmation'], 'confirmation' => true, 'confirmed_by_role' => 'manager']);
        self::assertSame('closed', $closed['records'][0]['document']['status']); self::assertCount(3, $ops->history(10, [80], 80, 'feedback', $receipt['receipt']));
        self::assertSame($receipt, $public->submit($token, '127.0.0.2', $this->submission()));
    }
    public function testDisableRotateInactiveRoomAndCrossHotelNeverGrantGuestOrStaffAccess(): void
    {
        $rooms = $this->rooms(); $saved = $this->entry([], $rooms); $token = $this->token($saved); $public = new GuestPublicFeedbackService();
        $revisions = array_fill_keys(array_column($rooms, 'room_id'), 1);
        $this->entry(['idempotency_key' => 'disable', 'enabled' => false, 'expected_revisions' => $revisions], $rooms);
        $this->fails(fn() => $public->submit($token, '127.0.0.1', $this->submission()), '停用', 404);
        $new = $this->entry(['idempotency_key' => 'rotate', 'enabled' => true, 'rotate_tokens' => true, 'expected_revisions' => array_fill_keys(array_column($rooms, 'room_id'), 2)], $rooms);
        $this->fails(fn() => $public->entry($token), '停用', 404); self::assertSame('101', $public->entry($this->token($new))['room_label']);
        (new GuestRoomRegistryService())->save(10, [80], 80, 11, ['confirmed_physical_rooms' => true, 'rooms' => [['room_number' => '101', 'active' => false, 'expected_revision' => 1]]]);
        $this->fails(fn() => $public->entry($this->token($new)), '停用', 409);
        $foreign = $this->rooms(82);
        $this->fails(fn() => $this->entry(['idempotency_key' => 'foreign', 'room_ids' => [$foreign[0]['room_id']]], $rooms), '当前租户酒店', 404);
    }
    public function testSubmissionLimitsAndPrivacyFailuresRollbackCountersAndCases(): void
    {
        $token = $this->token($this->entry()); $public = new GuestPublicFeedbackService();
        $this->fails(fn() => $public->submit($token, 'client-A', $this->submission() + ['hotel_id' => 81]), '只允许');
        $this->fails(fn() => $public->submit($token, 'client-A', array_replace($this->submission(), ['summary' => '合成联系电话13800000000'])), '删除');
        $this->fails(fn() => $public->submit($token, 'client-A', array_replace($this->submission(), ['summary' => str_repeat('x', 1501)])), '1500');
        for ($i = 1; $i <= 5; $i++) $public->submit($token, 'client-A', $this->submission(dechex($i)));
        $before = Db::name('guest_operation_records')->count();
        $this->fails(fn() => $public->submit($token, 'client-A', $this->submission('6')), '频繁', 429);
        self::assertSame($before, Db::name('guest_operation_records')->count()); self::assertSame(5, Db::name('guest_operation_heads')->where('kind', 'feedback')->count());
        Db::name('users')->where('id', 11)->update(['status' => 0]);
        $this->fails(fn() => $public->submit($token, 'client-B', $this->submission('7')), '责任人', 503);
        self::assertSame($before, Db::name('guest_operation_records')->count());
    }
    private function options(array $override = []): array
    {
        return array_replace(['mapping' => ['event' => 0, 'identity' => 1, 'stay_date' => 2, 'status' => 3, 'hotel' => 4], 'date_start' => '2026-10-01', 'date_end' => '2026-10-03', 'completed_values' => ['已离店'], 'source_reference' => 'synthetic JD06 import', 'idempotency_key' => 'jd06-import'], $override);
    }
    private function file(string $extension, ?array $rows = null): string
    {
        $book = new Spreadsheet(); $book->getActiveSheet()->fromArray($rows ?? [['订单号', '手机号', '离店日期', '状态', '酒店ID'], ['synthetic-order-A', '13800000000', '2026-10-01', '已离店', 80], ['synthetic-order-B', '13800000000', '2026-10-02', '已离店', 80], ['synthetic-order-B', '13800000000', '2026-10-02', '已离店', 80], ['synthetic-not-done', '13800000001', '2026-10-03', '在住', 80]], null, 'A1', true);
        $path = sys_get_temp_dir() . '/jd06-synthetic-' . bin2hex(random_bytes(5)) . '.' . $extension; $this->files[] = $path;
        IOFactory::createWriter($book, ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv' => 'Csv'][$extension])->save($path); $book->disconnectWorksheets(); return $path;
    }
    public function testXlsxXlsAndCsvMappingPreviewAnonymizationCanonicalSaveAndCrossFileDedup(): void
    {
        $service = new GuestStayFileImportService(); $guestHash = null;
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            $path = $this->file($extension); $options = $this->options(['idempotency_key' => 'jd06-' . $extension, 'source_reference' => 'synthetic JD06 ' . $extension]);
            $preview = $service->preview(10, [80], 80, 11, $path, $extension, $options);
            self::assertSame('ready', $preview['preview_status']); self::assertSame(2, $preview['valid_count']); self::assertSame(1, $preview['duplicate_rows']); self::assertSame(1, $preview['skipped_non_completed']);
            self::assertStringNotContainsString('13800000000', json_encode($preview)); self::assertStringNotContainsString('synthetic-order-A', json_encode($preview));
            self::assertSame($preview['events'][0]['guest_hash'], $preview['events'][1]['guest_hash']);
            if ($guestHash) self::assertSame($guestHash, $preview['events'][0]['guest_hash']); $guestHash = $preview['events'][0]['guest_hash'];
            $saved = $service->import(10, [80], 80, 11, $path, $extension, $options); self::assertSame('readback_verified', $saved['persistence_status']); self::assertSame('unverified', $saved['source_quality']);
            foreach ($saved['records'] as $record) self::assertTrue((new GuestOperationsService())->read(10, [80], 80, $record['id'])['readback_verified']);
        }
        self::assertSame(2, Db::name('guest_operation_heads')->where('kind', 'stay_event')->count());
        self::assertSame(3, Db::name('guest_operation_heads')->where('kind', 'stay_import')->count());
        $serialized = json_encode(Db::name('guest_operation_records')->select()->toArray()); self::assertStringNotContainsString('13800000000', $serialized); self::assertStringNotContainsString('synthetic-order-A', $serialized);
        $overview = (new GuestOperationsService())->overview(10, [80], 80, '2026-10-01', '2026-10-03', 'pms'); self::assertNull($overview['repeat_guest']['rate']); self::assertCount(3, $overview['stay_imports']);
    }
    public function testImportRejectsScopeDatesMappingAndMissingIdentityBeforeAnonymousEventWrites(): void
    {
        $service = new GuestStayFileImportService(); $headers = ['订单号', '手机号', '离店日期', '状态', '酒店ID'];
        foreach ([['synthetic-A', '13800000000', '2026-10-01', '已离店', 81], ['synthetic-A', '', '2026-10-01', '已离店', 80], ['synthetic-A', '13800000000', '2099-01-01', '已离店', 80], ['synthetic-A', '13800000000', '2026-09-30', '已离店', 80]] as $row) {
            $path = $this->file('xlsx', [$headers, $row]); $preview = $service->preview(10, [80], 80, 11, $path, 'xlsx', $this->options()); self::assertSame('blocked', $preview['preview_status']); self::assertSame(2, $preview['errors'][0]['row']);
            $this->fails(fn() => $service->import(10, [80], 80, 11, $path, 'xlsx', $this->options()), '预览未通过');
        }
        self::assertSame(0, Db::name('guest_operation_heads')->where('kind', 'stay_event')->count());
        $path = $this->file('csv'); $headerOnly = $service->preview(10, [80], 80, 11, $path, 'csv', []); self::assertSame('mapping_required', $headerOnly['preview_status']); self::assertSame([], $headerOnly['events']);
        $this->fails(fn() => $service->preview(10, [80], 80, 11, $path, 'csv', $this->options(['mapping' => ['event' => 0, 'identity' => 0, 'stay_date' => 2, 'status' => 3]])), '不同业务字段');
        $options = $this->options(['mapping' => ['event' => 0, 'identity' => 1, 'stay_date' => 2, 'status' => 3, 'hotel' => null]]);
        $this->fails(fn() => $service->preview(10, [80], 80, 11, $path, 'csv', $options), '人工确认');
        $declared = $service->preview(10, [80], 80, 11, $path, 'csv', $options + ['confirmed_single_hotel' => true, 'confirmed_hotel_id' => 80]); self::assertSame('staff_declared_single_hotel', $declared['scope_evidence']);
        $this->fails(fn() => $service->preview(10, [81], 81, 11, $path, 'csv', $this->options()), 'guest_hotel_not_found', 404);
    }
    public function testUploadSizeRowBoundCorruptionAndUnexpectedHeadersAreTruthfulFailures(): void
    {
        $service = new GuestStayFileImportService(); $rows = [['订单号', '手机号', '离店日期', '状态', '酒店ID']];
        for ($i = 0; $i < 501; $i++) $rows[] = ['synthetic-' . $i, '13800000000', '2026-10-01', '已离店', 80];
        $path = $this->file('xlsx', $rows); $this->fails(fn() => $service->preview(10, [80], 80, 11, $path, 'xlsx', $this->options()), '最多500行');
        file_put_contents($path, 'synthetic corrupted upload'); $this->fails(fn() => $service->preview(10, [80], 80, 11, $path, 'xlsx', $this->options()), '解析失败');
        $this->fails(fn() => $service->preview(10, [80], 80, 11, $path, 'html', $this->options()), '5MB');
        $path = $this->file('csv', [['13800000000', 'Synthetic Guest Name'], ['synthetic-A', 'synthetic-B']]);
        $preview = $service->preview(10, [80], 80, 11, $path, 'csv', []); self::assertStringNotContainsString('13800000000', json_encode($preview)); self::assertStringNotContainsString('Synthetic Guest Name', json_encode($preview));
    }
    public function testReexportedCorrectedEventAndConflictingRowsDoNotBecomeDuplicateCompletedStays(): void
    {
        $service = new GuestStayFileImportService(); $path = $this->file('csv'); $service->import(10, [80], 80, 11, $path, 'csv', $this->options());
        $headers = ['订单号', '手机号', '离店日期', '状态', '酒店ID'];
        $corrected = $this->file('csv', [$headers, ['synthetic-order-A', '13800000000', '2026-10-02', '已离店', 80]]);
        $preview = $service->preview(10, [80], 80, 11, $corrected, 'csv', $this->options(['idempotency_key' => 'corrected'])); self::assertSame('blocked', $preview['preview_status']); self::assertStringContainsString('已有事件', $preview['errors'][0]['message']);
        $this->fails(fn() => $service->import(10, [80], 80, 11, $corrected, 'csv', $this->options(['idempotency_key' => 'corrected'])), '预览未通过');
        $conflicting = $this->file('csv', [$headers, ['synthetic-new', '13800000000', '2026-10-01', '已离店', 80], ['synthetic-new', '13800000001', '2026-10-01', '已离店', 80]]);
        $preview = $service->preview(10, [80], 80, 11, $conflicting, 'csv', $this->options()); self::assertSame('blocked', $preview['preview_status']); self::assertStringContainsString('不同客人', $preview['errors'][0]['message']);
        self::assertSame(2, Db::name('guest_operation_heads')->where('kind', 'stay_event')->count());
    }
    public function testAnonymousStaffWritesDeniedWhilePublicControllerOnlyReturnsMinimalReceipt(): void
    {
        $token = $this->token($this->entry()); $reflection = new \ReflectionClass(GuestFeedback::class); $controller = $reflection->newInstanceWithoutConstructor();
        $request = new class($token) {
            public function __construct(private string $token) {}
            public function post(): array { return ['token' => $this->token, 'request_key' => str_repeat('b', 32), 'category' => 'feedback', 'summary' => '合成公开反馈']; }
            public function method(): string { return 'POST'; }
            public function getContent(): string { return ''; }
            public function server(string $name, string $default): string { return '127.0.0.9'; }
        };
        $reflection->getParentClass()->getProperty('request')->setValue($controller, $request);
        $response = $controller->submit(); self::assertSame(200, $response->getCode()); self::assertSame(['submission_status', 'receipt', 'message'], array_keys($response->getData()['data']));
        $reflection = new \ReflectionClass(GuestOperations::class); $staff = $reflection->newInstanceWithoutConstructor();
        $reflection->getParentClass()->getProperty('currentUser')->setValue($staff, null);
        foreach (['saveRooms', 'savePublicEntries', 'overview'] as $method) self::assertSame(401, $staff->{$method}()->getCode());
    }
}
