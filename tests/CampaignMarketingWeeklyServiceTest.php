<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CampaignOperations;
use app\service\CampaignCreativePayloadService;
use app\service\CampaignMarketingWeeklyService;
use app\service\CampaignOperationsService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CampaignMarketingWeeklyServiceTest extends TestCase
{
    private static array $previous;
    private static string $path;
    private static App $app;
    private CampaignOperationsService $campaign;
    private CampaignMarketingWeeklyService $weekly;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__)); self::$app->initialize(); self::$previous = Config::get('database');
        self::$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campaign_weekly_' . getmypid() . '_' . bin2hex(random_bytes(5)) . '.sqlite';
        $config = self::$previous; $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$path, 'prefix' => 'wm_', 'fields_strict' => false, 'debug' => true];
        Config::set($config, 'database'); Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close(); Config::set(self::$previous, 'database'); Db::connect(null, true);
        if (is_file(self::$path)) unlink(self::$path);
    }

    protected function setUp(): void
    {
        foreach (['campaign_operation_versions', 'hotels'] as $table) Db::execute('DROP TABLE IF EXISTS wm_' . $table);
        Db::execute('CREATE TABLE wm_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([['id' => 11, 'tenant_id' => 101, 'status' => 1], ['id' => 13, 'tenant_id' => 101, 'status' => 1], ['id' => 12, 'tenant_id' => 102, 'status' => 1]]);
        Db::execute('CREATE TABLE wm_campaign_operation_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, source_hotel_id INTEGER, kind TEXT NOT NULL, record_key TEXT NOT NULL, version_no INTEGER NOT NULL, parent_id INTEGER, business_date TEXT NOT NULL, source_method TEXT NOT NULL, source_label TEXT NOT NULL, data_status TEXT NOT NULL, payload_json TEXT NOT NULL, content_sha256 TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE(tenant_id,hotel_id,kind,record_key,version_no))');
        $this->campaign = new CampaignOperationsService(); $this->weekly = new CampaignMarketingWeeklyService();
    }

    public function testPublishedTimeRepostsPersistenceMissingAndValidation(): void
    {
        $record = $this->work(11, 'work_001', ['published_at' => '2026-09-28T08:09', 'reposts' => 0]);
        self::assertSame('2026-09-28 08:09:00', $record['payload']['published_at']);
        self::assertSame('Asia/Shanghai', $record['payload']['published_timezone']); self::assertSame(0, $record['payload']['reposts']);
        self::assertSame($record, $this->campaign->read(101, 11, $record['id']));
        $unknown = $this->work(11, 'work_missing', ['published_at' => '', 'reposts' => '']);
        self::assertNull($unknown['payload']['published_at']); self::assertNull($unknown['payload']['reposts']);
        foreach ([['published_at' => '2026-02-30T08:00'], ['published_at' => '2026-10-05T08:00'], ['reposts' => -1], ['reposts' => 1.5]] as $bad) {
            try { $this->work(11, 'work_invalid', $bad); self::fail('Invalid marketing field accepted'); }
            catch (InvalidArgumentException $e) { self::assertNotEmpty($e->getMessage()); }
        }
    }

    public function testCompleteSevenDaySelectedTenantRankingUsesSavedExplicitRules(): void
    {
        $rule = $this->rule(); $this->cover(11); $this->cover(13);
        $a = $this->work(11, 'work_a', ['views' => 100, 'likes' => 10, 'reposts' => 5]);
        $b = $this->work(13, 'work_b', ['views' => 50, 'likes' => 5, 'reposts' => 0]);
        $result = $this->weekly->overview(101, [13, 11], '2026-09-28', $rule['id']);
        self::assertSame([11, 13], $result['hotel_ids']); self::assertCount(7, $result['dates']);
        self::assertSame('2026-10-04', $result['metric_as_of_date']); self::assertSame('manual_comparable', $result['ranking_status']);
        self::assertSame(100.0, $result['rows'][0]['score']); self::assertSame(1, $result['rows'][0]['rank']);
        self::assertSame(40.0, $result['rows'][1]['score']); self::assertSame(2, $result['rows'][1]['rank']);
        self::assertSame([$a['id']], $result['rows'][0]['work_record_ids']); self::assertSame([$b['id']], $result['rows'][1]['work_record_ids']);
        self::assertSame(0, $result['rows'][1]['totals']['reposts']); self::assertSame('unverified', $result['data_status']);
        self::assertSame($rule, $result['rule']);
        $selected = $this->weekly->overview(101, [11], '2026-09-28', $rule['id']); self::assertCount(1, $selected['rows']);
    }

    public function testMissingCoverageAndMetricsRemainUnknownAndCannotRank(): void
    {
        $rule = $this->rule(); $this->work(11, 'work_a', ['reposts' => '']);
        $missing = $this->weekly->overview(101, [11], '2026-09-28', $rule['id']);
        self::assertNull($missing['rows'][0]['score']); self::assertNull($missing['rows'][0]['rank']);
        self::assertNull($missing['rows'][0]['totals']['posts']); self::assertSame('missing', $missing['rows'][0]['daily_coverage'][0]['status']);
        $this->cover(11); $result = $this->weekly->overview(101, [11], '2026-09-28', $rule['id']);
        self::assertSame(7, $result['rows'][0]['coverage_days']); self::assertNull($result['rows'][0]['totals']['reposts']);
        self::assertFalse($result['rows'][0]['eligible_for_rank']); self::assertContains('reposts指标缺失', $result['rows'][0]['exclusion_reasons']);
        $withoutRule = $this->weekly->overview(101, [11], '2026-09-28', null); self::assertSame('configuration_required', $withoutRule['ranking_status']);
    }

    public function testLatestWorkSnapshotDeduplicatesAndNonComparableDateExcluded(): void
    {
        $rule = $this->rule(); $this->cover(11);
        $this->work(11, 'same_work', ['views' => 1], '2026-09-30');
        $new = $this->work(11, 'same_work', ['views' => 100]);
        $result = $this->weekly->overview(101, [11], '2026-09-28', $rule['id']);
        self::assertSame(1, $result['rows'][0]['totals']['posts']); self::assertSame([$new['id']], $result['rows'][0]['work_record_ids']);
        $this->work(13, 'old_snapshot', [], '2026-10-03'); $this->cover(13);
        $result = $this->weekly->overview(101, [11, 13], '2026-09-28', $rule['id']);
        self::assertFalse($result['rows'][1]['eligible_for_rank']); self::assertNull($result['rows'][1]['score']);
    }

    public function testNoPostsMustBeExplicitlyCoveredAndRanksAsKnownZero(): void
    {
        $rule = $this->rule(); $this->cover(11, false);
        $result = $this->weekly->overview(101, [11], '2026-09-28', $rule['id']);
        self::assertTrue($result['rows'][0]['eligible_for_rank']); self::assertSame(0, $result['rows'][0]['totals']['posts']); self::assertSame(0.0, $result['rows'][0]['score']);
    }

    public function testTenantPermissionAndRuleVersionBoundaries(): void
    {
        foreach ([[101, [11, 12], '2026-09-28', null, 403], [101, [11], '2026-09-29', null, 0], [101, [13], '2026-09-28', $this->rule()['id'], 0]] as [$tenant, $ids, $start, $rule, $code]) {
            try { $this->weekly->overview($tenant, $ids, $start, $rule); self::fail('Invalid scope accepted'); }
            catch (InvalidArgumentException|RuntimeException $e) { self::assertSame($code, $e->getCode()); }
        }
        $user = new class { public int $id = 5; public function getPermittedHotelIds(): array { return [11, 12]; } public function hasHotelPermission(int $id, string $permission): bool { return true; } };
        $controller = new CampaignOperations(self::$app);
        (new \ReflectionProperty(\app\controller\Base::class, 'currentUser'))->setValue($controller, $user);
        foreach (['11,13', '11,12'] as $ids) {
            $request = new \think\Request(); $request->withGet(['hotel_ids' => $ids, 'week_start' => '2026-09-28']);
            (new \ReflectionProperty(\app\controller\Base::class, 'request'))->setValue($controller, $request);
            self::assertSame(403, $controller->weekly()->getCode());
        }
        $request = new \think\Request(); $request->withGet(['hotel_ids' => '11', 'week_start' => '2026-09-28']);
        (new \ReflectionProperty(\app\controller\Base::class, 'request'))->setValue($controller, $request);
        self::assertSame(200, $controller->weekly()->getCode());
    }

    public function testCreativeManifestExactReadbackReviewResetAndImageCapabilityTruth(): void
    {
        $manifest = [['name' => 'synthetic-hotel.png', 'kind' => 'image', 'mime_type' => 'image/png', 'size_bytes' => 1024, 'sha256' => hash('sha256', 'synthetic fixture')]];
        $payload = ['hotel_name' => 'synthetic酒店', 'title' => 'synthetic影像', 'copy' => 'synthetic测试', 'brand_color' => '#143a31', 'material_notes' => 'synthetic隔离文件', 'brand_review_status' => 'reviewed', 'material_review_status' => 'reviewed', 'duration_seconds' => 3, 'local_media_manifest' => $manifest];
        $record = $this->save(11, 'video_brief', $payload, '2026-10-04', 'video_fixture_001');
        self::assertSame($manifest, $record['payload']['local_media_manifest']); self::assertSame('browser_canvas_local_media_webm', $record['payload']['render_method']);
        self::assertSame($record, $this->campaign->read(101, 11, $record['id']));
        $html = $this->campaign->artifact(101, 11, $record['id'], 'html')['content'];
        self::assertStringContainsString('本地酒店素材WebM', $html); self::assertStringContainsString($manifest[0]['sha256'], $html);
        $payload['local_media_manifest'][0]['sha256'] = hash('sha256', 'changed fixture');
        $edit = $this->save(11, 'video_brief', $payload, '2026-10-04', 'video_fixture_001', $record['id']);
        self::assertSame('pending_review', $edit['payload']['material_review_status']); self::assertSame(2, $edit['version_no']);
        self::assertSame('configuration_required', CampaignCreativePayloadService::capability()['ai_image']['status']);
        $payload['local_media_manifest'][0]['name'] = '../outside.png';
        $this->expectException(InvalidArgumentException::class); $this->save(11, 'video_brief', $payload, '2026-10-04', 'video_fixture_002');
    }

    private function rule(): array
    {
        return $this->save(11, 'marketing_score_rule', ['name' => 'synthetic测试周规则', 'metric_definition' => 'synthetic周日累计指标', 'weights' => ['posts' => 20, 'views' => 30, 'likes' => 10, 'reposts' => 40], 'targets' => ['posts' => 1, 'views' => 100, 'likes' => 10, 'reposts' => 5]], '2026-09-28', 'score_fixture_001');
    }

    public function testWeeklySelectCountDoesNotGrowWithHeadsAndOverflowStillFails(): void
    {
        $saved = $this->work(11,'synthetic-batch-0');
        $template = Db::name(CampaignOperationsService::TABLE)->where('id',$saved['id'])->find(); unset($template['id']);
        $seal = new \ReflectionMethod($this->campaign,'sealedRow'); $inserted = 1;
        $counting = false; $selects = 0; $counts = [];
        Db::listen(static function (string $sql) use (&$counting, &$selects): void { if ($counting && preg_match('/^SELECT\b/i',ltrim($sql))) $selects++; });
        foreach ([1,100,5000] as $size) {
            $batch = [];
            for (; $inserted < $size; $inserted++) {
                $row = array_replace($template,['record_key'=>'synthetic-batch-'.$inserted]);
                $payload = array_replace($saved['payload'],['work_id'=>'synthetic-batch-'.$inserted]);
                $batch[] = $seal->invoke($this->campaign,$row,$payload);
                if (count($batch) === 100) { Db::name(CampaignOperationsService::TABLE)->insertAll($batch); $batch = []; }
            }
            if ($batch) Db::name(CampaignOperationsService::TABLE)->insertAll($batch);
            $selects = 0; $counting = true;
            try { $result = $this->weekly->overview(101,[11],'2026-09-28',null); } finally { $counting = false; }
            self::assertSame($size, $result['rows'][0]['observed_posts']); self::assertGreaterThan(0,$selects);
            $counts[] = $selects; self::assertLessThanOrEqual(6,$selects);
        }
        self::assertSame([6,6,6], $counts);
        Db::name(CampaignOperationsService::TABLE)->insert($seal->invoke($this->campaign,array_replace($template,['record_key'=>'synthetic-overflow']),$saved['payload']));
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503);
        $this->weekly->overview(101,[11],'2026-09-28',null);
    }

    public function testBatchReadsKeepOrderIsolationMissingAndIntegrityFailures(): void
    {
        $a = $this->work(11,'synthetic-a'); $b = $this->work(13,'synthetic-b');
        $heads = [['hotel_id'=>13,'latest_id'=>$b['id']],['hotel_id'=>11,'latest_id'=>$a['id']],['hotel_id'=>13,'latest_id'=>$b['id']]];
        self::assertSame([$b,$a,$b],$this->campaign->readHeads(101,$heads));
        foreach ([[['hotel_id'=>11,'latest_id'=>$b['id']],$heads[0]], [['hotel_id'=>11,'latest_id'=>99999]], [['hotel_id'=>12,'latest_id'=>$a['id']]]] as $bad) {
            try { $this->campaign->readHeads(101,$bad); self::fail('invalid batch scope accepted'); }
            catch (RuntimeException $e) { self::assertContains($e->getCode(),[403,404]); }
        }
        $row = Db::name(CampaignOperationsService::TABLE)->where('id',$a['id'])->find();
        $envelope = json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR); $envelope['payload']['views'] = 999;
        foreach ([['content_sha256'=>str_repeat('0',64)], ['created_by'=>999], ['payload_json'=>json_encode($envelope,JSON_THROW_ON_ERROR)]] as $tamper) {
            Db::name(CampaignOperationsService::TABLE)->where('id',$a['id'])->update($tamper);
            foreach ([fn() => $this->campaign->readHeads(101,$heads), fn() => $this->weekly->overview(101,[11,13],'2026-09-28',null)] as $read) {
                try { $read(); self::fail('tampered version accepted'); } catch (RuntimeException $e) { self::assertSame(503,$e->getCode()); }
            }
            Db::name(CampaignOperationsService::TABLE)->where('id',$a['id'])->update($row);
        }
        Db::execute('DROP TABLE wm_campaign_operation_versions');
        $this->expectException(RuntimeException::class); $this->expectExceptionCode(503); $this->campaign->readHeads(101,$heads);
    }

    private function cover(int $hotel, bool $hasWork = true): void
    {
        for ($i = 0; $i < 7; $i++) {
            $count = $hasWork && $i === 0 ? 1 : 0;
            $this->save($hotel, 'marketing_coverage', ['coverage_status' => $count ? 'complete' : 'no_posts', 'expected_posts' => $count, 'notes' => 'synthetic每日完整核对'], (new \DateTimeImmutable('2026-09-28'))->modify('+' . $i . ' days')->format('Y-m-d'), 'coverage_fixture');
        }
    }

    private function work(int $hotel, string $id, array $change = [], string $date = '2026-10-04'): array
    {
        return $this->save($hotel, 'marketing', array_replace(['platform' => 'douyin', 'work_id' => $id, 'title' => 'synthetic作品', 'published_at' => '2026-09-28T08:00', 'views' => 100, 'likes' => 10, 'reposts' => 5], $change), $date, 'marketing_fixture');
    }

    private function save(int $hotel, string $kind, array $payload, string $date, string $key, int $expected = 0): array
    {
        return $this->campaign->save(101, $hotel, 5, ['kind' => $kind, 'record_key' => $key, 'business_date' => $date, 'source_label' => 'synthetic隔离证据', 'expected_id' => $expected, 'payload' => $payload])['record'];
    }
}
