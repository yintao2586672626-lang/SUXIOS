<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CampaignOperations;
use app\service\CampaignOperationsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CampaignOperationsServiceTest extends TestCase
{
    private static array $previous = [];
    private static string $path = '';
    private static App $app;
    private CampaignOperationsService $service;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__)); self::$app->initialize();
        self::$previous = Config::get('database');
        self::$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campaign_operations_' . getmypid() . '_' . bin2hex(random_bytes(5)) . '.sqlite';
        $config = self::$previous; $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$path, 'prefix' => 'cw_', 'fields_strict' => false];
        Config::set($config, 'database'); Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close(); Config::set(self::$previous, 'database'); Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    protected function setUp(): void
    {
        foreach (['campaign_operation_versions', 'hotels', 'operation_execution_tasks', 'daily_reports'] as $table) Db::execute('DROP TABLE IF EXISTS cw_' . $table);
        Db::execute('CREATE TABLE cw_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([['id' => 11, 'tenant_id' => 101, 'status' => 1], ['id' => 12, 'tenant_id' => 102, 'status' => 1], ['id' => 13, 'tenant_id' => 101, 'status' => 1]]);
        Db::execute('CREATE TABLE cw_campaign_operation_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, source_hotel_id INTEGER, kind TEXT NOT NULL, record_key TEXT NOT NULL, version_no INTEGER NOT NULL, parent_id INTEGER, business_date TEXT NOT NULL, source_method TEXT NOT NULL, source_label TEXT NOT NULL, data_status TEXT NOT NULL, payload_json TEXT NOT NULL, content_sha256 TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE(tenant_id,hotel_id,kind,record_key,version_no))');
        Db::execute('CREATE TABLE cw_operation_execution_tasks (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL)');
        Db::name('operation_execution_tasks')->insertAll([['id' => 7, 'tenant_id' => 101, 'hotel_id' => 11], ['id' => 8, 'tenant_id' => 102, 'hotel_id' => 12]]);
        Db::execute('CREATE TABLE cw_daily_reports (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, report_date TEXT NOT NULL)');
        Db::name('daily_reports')->insertAll([['id' => 9, 'tenant_id' => 101, 'hotel_id' => 11, 'report_date' => '2026-10-02'], ['id' => 10, 'tenant_id' => 102, 'hotel_id' => 12, 'report_date' => '2026-10-02']]);
        $this->service = new CampaignOperationsService();
    }

    public function testMarketingSaveReadbackDuplicateImportAndUnknownResults(): void
    {
        $input = $this->marketing();
        $first = $this->save($input);
        self::assertSame('saved_and_readback_verified', $first['request_status']);
        self::assertSame(null, $first['record']['payload']['actual_revenue']);
        self::assertSame(0, $first['record']['payload']['views']);
        self::assertSame('unverified', $first['record']['data_status']);
        self::assertSame($first['record'], $this->service->read(101, 11, $first['record']['id']));
        self::assertTrue($this->save($input)['reused']);
        self::assertSame(1, Db::name(CampaignOperationsService::TABLE)->count());
        $input['payload']['effective_leads'] = 8;
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(409);
        $this->save($input);
    }

    public function testSqlJsonObjectReorderingPreservesReadbackAndDuplicateSave(): void
    {
        $input = $this->marketing(); $saved = $this->save($input)['record'];
        $reordered = array_reverse(json_decode(Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->value('payload_json'), true, 64, JSON_THROW_ON_ERROR), true);
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update([
            'payload_json' => json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $read = $this->service->read(101, 11, $saved['id']);
        self::assertEquals($saved['payload'], $read['payload']);
        self::assertSame($saved['content_sha256'], $read['content_sha256']);
        $repeated = $this->save($input);
        self::assertTrue($repeated['reused']); self::assertSame($saved['id'], $repeated['record']['id']);
        self::assertSame(1, Db::name(CampaignOperationsService::TABLE)->count());
    }

    public function testNestedJsonObjectReorderingPreservesListOrderAndRejectsListChanges(): void
    {
        $input = $this->base('handover', ['shift_label' => '隔离早班', 'new_items' => [
            ['title' => '第一项', 'owner' => '隔离负责人', 'due_at' => '2026-10-02T18:00'],
            ['title' => '第二项', 'owner' => '隔离负责人', 'due_at' => '2026-10-02T19:00'],
        ]]);
        $saved = $this->save($input)['record'];
        $raw = json_decode(Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->value('payload_json'), true, 64, JSON_THROW_ON_ERROR);
        $reordered = array_reverse($raw, true);
        $payload =& $reordered;
        if (isset($reordered['integrity_contract'])) $payload =& $reordered['payload'];
        $payload = array_reverse($payload, true);
        $payload['items'] = array_map(static fn(array $item): array => array_reverse($item, true), $payload['items']);
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update([
            'payload_json' => json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $read = $this->service->read(101, 11, $saved['id']);
        self::assertSame(array_column($saved['payload']['items'], 'item_id'), array_column($read['payload']['items'], 'item_id'));
        self::assertSame($saved['content_sha256'], $read['content_sha256']);
        self::assertTrue($this->save($input)['reused']);
        self::assertSame(1, Db::name(CampaignOperationsService::TABLE)->count());
        $payload['items'] = array_reverse($payload['items']);
        unset($payload);
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update([
            'payload_json' => json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $this->service->read(101, 11, $saved['id']);
    }

    public function testLegacyDigestRemainsReadableAndDuplicateSaveReusesItsVersion(): void
    {
        $input = $this->marketing(); $saved = $this->save($input)['record'];
        $legacy = hash('sha256', json_encode([
            'kind' => $saved['kind'], 'business_date' => $saved['business_date'],
            'source_label' => $saved['source_label'], 'payload' => $saved['payload'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update([
            'payload_json' => json_encode($saved['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'content_sha256' => $legacy,
        ]);
        $legacyRead = $this->service->read(101, 11, $saved['id']);
        self::assertSame($legacy, $legacyRead['content_sha256']); self::assertSame('legacy_content_only', $legacyRead['integrity_status']);
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update(['created_by' => 10000]);
        $legacyMetadata = $this->service->read(101, 11, $saved['id']);
        self::assertSame(10000, $legacyMetadata['created_by']); self::assertSame('legacy_content_only', $legacyMetadata['integrity_status']);
        self::assertSame($legacy, $legacyMetadata['content_sha256']);
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update(['created_by' => $saved['created_by']]);
        $repeated = $this->save($input);
        self::assertTrue($repeated['reused']); self::assertSame($saved['id'], $repeated['record']['id']);
        self::assertSame($legacy, $repeated['record']['content_sha256']);
        self::assertSame(1, Db::name(CampaignOperationsService::TABLE)->count());
        $input['expected_id'] = $saved['id']; $input['payload']['title'] = '升级摘要后的新版本';
        $new = $this->save($input)['record'];
        self::assertSame(2, $new['version_no']);
        self::assertSame('immutable_metadata_verified', $new['integrity_status']);
        self::assertSame($legacy, $this->service->read(101, 11, $saved['id'])['content_sha256']);
    }

    public function testNewVersionsRejectImmutableMetadataDriftAndActionVersionsAreProtected(): void
    {
        $saved = $this->save($this->marketing())['record'];
        $row = Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->find();
        foreach ([['source_hotel_id' => 12], ['source_hotel_id' => null], ['record_key' => 'changed_record_key'], ['version_no' => 99], ['parent_id' => 999], ['created_by' => 10000], ['source_method' => 'automatic_ota'], ['data_status' => 'available'], ['created_at' => '2026-01-01 00:00:00'], ['tenant_id' => 102, 'hotel_id' => 12]] as $change) {
            Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update($change);
            $error = null;
            try { $this->service->read((int)($change['tenant_id'] ?? 101), (int)($change['hotel_id'] ?? 11), $saved['id']); }
            catch (RuntimeException $caught) { $error = $caught; }
            self::assertInstanceOf(RuntimeException::class, $error, 'Metadata drift accepted: ' . json_encode($change));
            self::assertSame(503, $error->getCode()); self::assertStringContainsString('元数据', $error->getMessage());
            Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update($row);
        }
        self::assertSame('immutable_metadata_verified', $this->service->read(101, 11, $saved['id'])['integrity_status']);
        $handover = $this->save($this->base('handover', ['shift_label' => 'synthetic', 'new_items' => []]))['record'];
        $ack = $this->service->handoverAction(101, 11, 6, $handover['id'], ['action' => 'acknowledge'])['record'];
        self::assertSame(6, $ack['created_by']); self::assertSame($handover['id'], $ack['parent_id']);
        self::assertSame('immutable_metadata_verified', $ack['integrity_status']);
        self::assertSame($ack, $this->service->read(101, 11, $ack['id']));
        Db::name(CampaignOperationsService::TABLE)->where('id', $ack['id'])->update(['created_by' => 5]);
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $this->service->read(101, 11, $ack['id']);
    }
    public function testMarketingRevisionKeepsOldVersionAndSeparatesActualResult(): void
    {
        $input = $this->marketing(); $first = $this->save($input)['record'];
        $input['expected_id'] = $first['id'];
        $input['payload'] += ['actual_arrivals' => 2, 'actual_revenue' => 800.55, 'result_business_date' => '2026-10-05', 'result_source_label' => '隔离PMS核对样例', 'attribution_notes' => '隔离样例已人工对照预约编号'];
        $second = $this->save($input)['record'];
        self::assertSame(2, $second['version_no']); self::assertSame($first['id'], $second['parent_id']);
        self::assertSame(800.55, $second['payload']['actual_revenue']);
        self::assertNull($this->service->read(101, 11, $first['id'])['payload']['actual_revenue']);
        self::assertCount(1, $this->service->overview(101, 11, '2026-10-02')['records']);
    }

    public function testActualResultWithoutSourceAndDateIsRejectedBeforeWrite(): void
    {
        $input = $this->marketing(); $input['payload']['actual_revenue'] = 100;
        try { $this->save($input); self::fail('Missing evidence accepted'); }
        catch (InvalidArgumentException $e) { self::assertStringContainsString('结果日期', $e->getMessage()); }
        self::assertSame(0, Db::name(CampaignOperationsService::TABLE)->count());
    }

    public function testHandoverInheritsOnlyUnclosedItemsAndRequiresAcknowledgementAndClosureEvidence(): void
    {
        $input = $this->base('handover', ['shift_label' => '隔离早班', 'notes' => '隔离测试', 'new_items' => [
            ['title' => '隔离任务核对', 'owner' => '隔离负责人', 'task_id' => 7, 'due_at' => '2026-10-02T18:00'],
            ['title' => '隔离未结事项', 'owner' => '隔离负责人', 'due_at' => '2026-10-02T19:00'],
        ]]);
        $first = $this->save($input)['record']; $item = $first['payload']['items'][0];
        try { $this->service->handoverAction(101, 11, 5, $first['id'], ['action' => 'close_item', 'item_id' => $item['item_id'], 'closure_evidence' => '隔离回执']); self::fail('Unacknowledged closure'); }
        catch (RuntimeException $e) { self::assertSame(409, $e->getCode()); }
        $ack = $this->service->handoverAction(101, 11, 5, $first['id'], ['action' => 'acknowledge'])['record'];
        try { $this->service->handoverAction(101, 11, 5, $ack['id'], ['action' => 'close_item', 'item_id' => $item['item_id']]); self::fail('Missing closure evidence'); }
        catch (InvalidArgumentException) {}
        $closed = $this->service->handoverAction(101, 11, 5, $ack['id'], ['action' => 'close_item', 'item_id' => $item['item_id'], 'closure_evidence' => '隔离回执已核对'])['record'];
        self::assertSame('closed', $closed['payload']['items'][0]['status']);
        self::assertSame('open', $this->service->read(101, 11, $first['id'])['payload']['items'][0]['status']);
        $next = $this->base('handover', ['shift_label' => '隔离晚班', 'previous_id' => $closed['id'], 'new_items' => []]); $next['record_key'] = 'next_shift_002';
        $inherited = $this->save($next)['record'];
        self::assertCount(1, $inherited['payload']['items']);
        self::assertSame($closed['id'], $inherited['payload']['items'][0]['inherited_from_id']);
        self::assertNull($inherited['payload']['acknowledged_by']);
        self::assertSame($first['payload']['items'][1]['item_id'], $inherited['payload']['items'][0]['item_id']);
    }

    public function testCrossHotelTaskReferenceIsRejected(): void
    {
        $input = $this->base('handover', ['shift_label' => '早班', 'new_items' => [['title' => '事项', 'owner' => '责任人', 'task_id' => 8, 'due_at' => '2026-10-02T12:00']]]);
        $this->expectException(InvalidArgumentException::class); $this->save($input);
    }
    public function testHandoverEditCannotMoveBeforeInheritedSourceBusinessDate(): void
    {
        $priorInput = $this->base('handover', ['shift_label' => 'source', 'new_items' => [['title' => 'synthetic', 'owner' => 'owner', 'due_at' => '2026-10-03T18:00']]]);
        $prior = $this->save($priorInput)['record'];
        $input = $this->base('handover', ['shift_label' => 'next', 'previous_id' => $prior['id'], 'new_items' => []]);
        $input['record_key'] = 'next_shift_future'; $input['business_date'] = '2026-10-03';
        $next = $this->save($input)['record'];
        $input['expected_id'] = $next['id']; $input['business_date'] = '2026-10-01';
        $error = null;
        try { $this->save($input); } catch (InvalidArgumentException $caught) { $error = $caught; }
        self::assertInstanceOf(InvalidArgumentException::class, $error, 'Future source inherited during edit');
        self::assertSame(0, $error->getCode()); self::assertStringContainsString('未来业务日', $error->getMessage());
        self::assertSame(2, Db::name(CampaignOperationsService::TABLE)->count());
        self::assertSame('2026-10-03', $this->service->read(101, 11, $next['id'])['business_date']);
        $input['business_date'] = '2026-10-04';
        self::assertSame($prior['id'], $this->save($input)['record']['payload']['items'][0]['inherited_from_id']);
    }
    public function testHandoverDeadlineRejectsInvalidSecondsAndKeepsValidPrecision(): void
    {
        $input = $this->base('handover', ['shift_label' => 'synthetic', 'new_items' => [['title' => 'synthetic', 'owner' => 'owner', 'due_at' => '2026-10-02T18:00:99']]]);
        $error = null;
        try { $this->save($input); } catch (InvalidArgumentException $caught) { $error = $caught; }
        self::assertInstanceOf(InvalidArgumentException::class, $error, 'Invalid seconds accepted');
        self::assertSame(0, $error->getCode()); self::assertStringContainsString('截止时间无效', $error->getMessage());
        self::assertSame(0, Db::name(CampaignOperationsService::TABLE)->count());
        $input['payload']['new_items'][0]['due_at'] = '2026-10-02T18:00:59';
        self::assertSame('2026-10-02T18:00:59', $this->save($input)['record']['payload']['items'][0]['due_at']);
    }
    public function testOverviewFiltersCurrentVersionsBeforeBusinessDateAndHandoverInheritance(): void
    {
        $input = $this->base('handover', ['shift_label' => 'source', 'new_items' => [['title' => 'synthetic', 'owner' => 'owner', 'due_at' => '2026-10-03T18:00']]]);
        $old = $this->save($input)['record'];
        $input['expected_id'] = $old['id']; $input['business_date'] = '2026-10-03';
        $current = $this->save($input)['record'];
        $oldDate = $this->service->overview(101, 11, '2026-10-02');
        self::assertSame([], $oldDate['records']); self::assertSame(0, $oldDate['total']); self::assertNull($oldDate['previous_handover']);
        $newDate = $this->service->overview(101, 11, '2026-10-03');
        self::assertSame([$current['id']], array_column($newDate['records'], 'id'));
        self::assertSame(1, $newDate['total']); self::assertSame($current['id'], $newDate['previous_handover']['id']);
        self::assertSame($old['content_sha256'], $this->service->read(101, 11, $old['id'])['content_sha256']);
        $next = $this->base('handover', ['shift_label' => 'next', 'previous_id' => $old['id'], 'new_items' => []]);
        $next['record_key'] = 'next_shift_old_source';
        $error = null;
        try { $this->save($next); } catch (RuntimeException $caught) { $error = $caught; }
        self::assertInstanceOf(RuntimeException::class, $error, 'Old source version inherited');
        self::assertSame(409, $error->getCode()); self::assertStringContainsString('已有新版本', $error->getMessage());
        self::assertSame(2, Db::name(CampaignOperationsService::TABLE)->count());
        $next['payload']['previous_id'] = $current['id']; $next['business_date'] = '2026-10-03';
        self::assertSame($current['id'], $this->save($next)['record']['payload']['items'][0]['inherited_from_id']);
    }

    public function testCrossTenantAndSameTenantOtherHotelCannotReadVersion(): void
    {
        $saved = $this->save($this->marketing())['record'];
        foreach ([[102, 12], [101, 13]] as [$tenant, $hotel]) {
            try { $this->service->read($tenant, $hotel, $saved['id']); self::fail('Cross-hotel read'); }
            catch (RuntimeException $e) { self::assertSame(404, $e->getCode()); }
        }
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(403);
        $this->service->overview(102, 11, '2026-10-02');
    }

    public function testNativePosterExportEscapesTextAndContentEditInvalidatesReview(): void
    {
        $input = $this->base('poster', $this->creative());
        $input['payload']['title'] = '<script>隔离测试</script>';
        $first = $this->save($input)['record'];
        $svg = $this->service->artifact(101, 11, $first['id'], 'svg');
        self::assertStringContainsString('&lt;script&gt;', $svg['content']);
        self::assertStringNotContainsString('<script>', $svg['content']);
        self::assertStringContainsString('品牌审核：reviewed', strip_tags($svg['content']));
        self::assertSame(hash('sha256', $svg['content']), $svg['content_sha256']);
        $input['expected_id'] = $first['id']; $input['payload']['copy'] = '编辑后的隔离样例';
        $second = $this->save($input)['record'];
        self::assertSame('pending_review', $second['payload']['brand_review_status']);
        self::assertSame('pending_review', $second['payload']['material_review_status']);
        self::assertSame('reviewed', $this->service->read(101, 11, $first['id'])['payload']['brand_review_status']);
    }

    public function testBriefPersistsBrowserRenderMethodAndPreservesProvenanceInHtml(): void
    {
        $saved = $this->save($this->base('video_brief', $this->creative() + ['duration_seconds' => 3]))['record'];
        self::assertSame('browser_canvas_webm', $saved['payload']['render_method']);
        self::assertSame('not_provided', $saved['payload']['real_footage_status']);
        $artifact = $this->service->artifact(101, 11, $saved['id'], 'html');
        self::assertStringContainsString('版本1 / #' . $saved['id'], $artifact['content']);
        self::assertStringContainsString('不包含自动取得的酒店实拍或音乐', $artifact['content']);
    }

    public function testLongPosterKeepsAllCopyAndSourceAndSupportsLightBrandColor(): void
    {
        $input = $this->base('poster', $this->creative());
        $input['payload']['copy'] = str_repeat('字', 1190) . '完整文案末尾保留标记';
        $input['payload']['material_notes'] = str_repeat('材', 490) . '素材末尾保留';
        $input['payload']['hotel_name'] = str_repeat('店', 120);
        $input['payload']['brand_color'] = '#ffffff';
        $saved = $this->save($input)['record']; $svg = $this->service->artifact(101, 11, $saved['id'], 'svg');
        $xml = new \SimpleXMLElement($svg['content']);
        self::assertGreaterThan(1440, (int)$xml['height']);
        $text = strip_tags($svg['content']);
        self::assertStringContainsString('完整文案末尾保留标记', $text);
        self::assertStringContainsString('素材末尾保留', $text);
        self::assertSame(120, mb_substr_count($text, '店') - 2);
        self::assertStringContainsString('fill="#1c3028" font-size="58"', $svg['content']);
    }

    public function testReconciliationReusesExistingReportAndRejectsOtherHotelOrDate(): void
    {
        $input = $this->base('report_reconciliation', ['daily_report_id' => 9, 'notes' => '隔离报表补录核对']);
        $record = $this->save($input)['record']; self::assertFalse($record['payload']['creates_platform_order']);
        self::assertSame(2, Db::name('daily_reports')->count());
        $input['payload']['daily_report_id'] = 10;
        try { $this->save($input); self::fail('Cross-hotel report'); } catch (InvalidArgumentException) {}
        $input['payload']['daily_report_id'] = 9; $input['business_date'] = '2026-10-03';
        $this->expectException(InvalidArgumentException::class); $this->save($input);
    }

    public function testRenumberedHotelMetadataPreservesSourceHotelPayloadAndDigest(): void
    {
        $input = $this->base('poster', $this->creative()); $saved = $this->save($input)['record'];
        Db::name('hotels')->where('id', 11)->update(['id' => 22]);
        Db::name(CampaignOperationsService::TABLE)->where('hotel_id', 11)->update(['hotel_id' => 22]);
        $read = $this->service->read(101, 22, $saved['id']);
        self::assertSame(22, $read['hotel_id']); self::assertSame(11, $read['source_hotel_id']);
        self::assertSame($saved['payload'], $read['payload']); self::assertSame($saved['content_sha256'], $read['content_sha256']);
        self::assertSame(11, $read['source_scope']['hotel_id']);
        $artifact = $this->service->artifact(101, 22, $read['id'], 'svg');
        self::assertStringContainsString('当前酒店22', $artifact['content']);
        self::assertStringContainsString('来源酒店11', $artifact['content']);
        $input['expected_id'] = $read['id']; $input['payload']['copy'] = '改号后版本';
        $new = $this->service->save(101, 22, 5, $input)['record']; self::assertSame(11, $new['source_hotel_id']);
        // Old rows without an original source ID remain readable with explicit fallback.
        $legacyDigest = hash('sha256', json_encode(['kind' => $new['kind'], 'business_date' => $new['business_date'], 'source_label' => $new['source_label'], 'payload' => $new['payload']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        Db::name(CampaignOperationsService::TABLE)->where('id', $new['id'])->update([
            'source_hotel_id' => null, 'payload_json' => json_encode($new['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'content_sha256' => $legacyDigest,
        ]);
        self::assertSame(22, $this->service->read(101, 22, $new['id'])['source_hotel_id']);
        self::assertSame('legacy_content_only', $this->service->read(101, 22, $new['id'])['integrity_status']);
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(404); $this->service->read(101, 11, $saved['id']);
    }

    public function testInvalidNumberOrCredentialUrlAndStaleVersionFail(): void
    {
        foreach ([['views', -1], ['effective_leads', 1.5], ['title', 'https://example.invalid/media?token=secret']] as [$field, $value]) {
            $input = $this->marketing(); $input['payload'][$field] = $value;
            try { $this->save($input); self::fail('Invalid value accepted'); } catch (InvalidArgumentException) {}
        }
        $input = $this->marketing(); $first = $this->save($input)['record'];
        $input['expected_id'] = $first['id']; $input['payload']['title'] = '第二版'; $this->save($input);
        $input['payload']['title'] = '过期编辑';
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(409); $this->save($input);
    }

    public function testTamperedStorageAndMissingMigrationAreExplicitFailures(): void
    {
        $saved = $this->save($this->marketing())['record'];
        Db::name(CampaignOperationsService::TABLE)->where('id', $saved['id'])->update(['payload_json' => '{}']);
        try { $this->service->read(101, 11, $saved['id']); self::fail('Tampered record read'); }
        catch (RuntimeException $e) { self::assertSame(503, $e->getCode()); }
        Db::execute('DROP TABLE cw_campaign_operation_versions');
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503); $this->service->overview(101, 11, '2026-10-02');
    }

    public function testControllerDeniesViewOnlyWriteAndDoesNotTrustBodyTenant(): void
    {
        $user = new class {
            public int $id = 5;
            public function getPermittedHotelIds(): array { return [11]; }
            public function hasHotelPermission(int $id, string $permission): bool { return $id === 11 && $permission === 'operation.view'; }
        };
        $controller = new CampaignOperations(self::$app);
        $reflection = new \ReflectionProperty(\app\controller\Base::class, 'currentUser'); $reflection->setValue($controller, $user);
        $request = new \think\Request(); $request->withPost($this->marketing() + ['hotel_id' => 11, 'tenant_id' => 102]);
        $property = new \ReflectionProperty(\app\controller\Base::class, 'request'); $property->setValue($controller, $request);
        $response = $controller->save(); self::assertSame(403, $response->getCode());
        self::assertSame(0, Db::name(CampaignOperationsService::TABLE)->count());
    }

    public function testControllerAuthenticatedSaveUsesAuthoritativeHotelTenantAndReturnsReadback(): void
    {
        $user = new class {
            public int $id = 5;
            public function getPermittedHotelIds(): array { return [11]; }
            public function hasHotelPermission(int $id, string $permission): bool { return $id === 11; }
        };
        $controller = new CampaignOperations(self::$app);
        (new \ReflectionProperty(\app\controller\Base::class, 'currentUser'))->setValue($controller, $user);
        $request = new \think\Request(); $request->withPost($this->marketing() + ['hotel_id' => 11, 'tenant_id' => 102]);
        (new \ReflectionProperty(\app\controller\Base::class, 'request'))->setValue($controller, $request);
        $response = $controller->save(); self::assertSame(200, $response->getCode());
        $body = $response->getData(); self::assertSame('saved_and_readback_verified', $body['data']['request_status']);
        self::assertSame(101, $body['data']['record']['tenant_id']);
        self::assertSame(11, $body['data']['record']['hotel_id']);
    }

    private function save(array $input): array { return $this->service->save(101, 11, 5, $input); }
    private function base(string $kind, array $payload): array { return ['kind' => $kind, 'record_key' => 'isolated_record_001', 'business_date' => '2026-10-02', 'source_label' => 'synthetic 隔离测试来源', 'payload' => $payload]; }
    private function marketing(): array { return $this->base('marketing', ['platform' => 'douyin', 'work_id' => 'isolated_work_001', 'title' => '隔离作品', 'views' => 0, 'reservations' => 5, 'effective_leads' => 3]); }
    private function creative(): array { return ['hotel_name' => '隔离测试酒店', 'title' => '隔离节日', 'copy' => '隔离文字画面\n不代表真实经营素材', 'brand_color' => '#143a31', 'material_notes' => '测试专用文字，没有实拍素材', 'brand_review_status' => 'reviewed', 'material_review_status' => 'reviewed']; }
}
