<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class AiDailyReportJudgmentRetryTest extends TestCase
{
    private array $originalConfig;
    private array $originalCacheConfig;
    private array $originalLogConfig;
    private string $databasePath;
    private const REQUEST = '02b61de2-0150-4f21-a9c2-f95c10d22b48';

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $this->originalCacheConfig = Config::get('cache', []);
        $this->originalLogConfig = Config::get('log', []);
        $connection = 'ai_judgment_retry_' . bin2hex(random_bytes(8));
        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $connection . '.sqlite';
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => $this->databasePath,
            'builder' => '\\' . JudgmentRetrySqliteBuilder::class,
            'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        $cachePath = (string)getenv('SUXIOS_CACHE_PATH');
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $cachePath]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $cachePath . '/logs']]], 'log');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([['id' => 904, 'tenant_id' => 9004], ['id' => 905, 'tenant_id' => 9005]]);
        Db::execute('CREATE TABLE ai_daily_reports (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, report_date TEXT,
            status TEXT, model_status TEXT, summary TEXT, source_refs_json TEXT,
            recommended_actions_json TEXT, abnormal_metrics_json TEXT, snapshot_json TEXT,
            created_by INTEGER, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE ai_report_human_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL, report_id INTEGER NOT NULL, subject_type TEXT,
            subject_key TEXT, decision TEXT, before_json TEXT, correction_json TEXT,
            reason TEXT, result_version TEXT, created_by INTEGER, created_at TEXT)');
        foreach ([[301, 904, 9004], [302, 905, 9005], [303, 904, 9004]] as [$id, $hotel, $tenant]) {
            Db::name('ai_daily_reports')->insert([
                'id' => $id, 'hotel_id' => $hotel, 'tenant_id' => $tenant,
                'report_date' => $id === 303 ? '2026-09-13' : '2026-09-14',
                'status' => 'generated', 'model_status' => 'not_requested',
                'summary' => 'SYNTHETIC retry fixture; no operating evidence',
                'source_refs_json' => '[]', 'recommended_actions_json' => '[]',
                'abnormal_metrics_json' => '[]',
                'snapshot_json' => '{"result_contract":{"result_version":"synthetic-v1"},"human_judgments":[]}',
                'created_by' => 99, 'updated_at' => '2026-09-14 08:00:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Config::set($this->originalCacheConfig, 'cache');
        Config::set($this->originalLogConfig, 'log');
        @unlink($this->databasePath);
    }

    public function testNormalizedSameRequestReadsOriginalJudgmentAndPersistsKey(): void
    {
        $service = new AiDailyReportService();
        $first = $service->recordHumanJudgment(301, [904], 99, $this->input([
            'request_id' => ' ' . strtoupper(self::REQUEST) . ' ',
            'target_type' => ' OVERALL ', 'target_key' => ' synthetic ',
            'decision' => ' ACCEPTED ', 'comment' => ' synthetic opinion ', 'correction' => ' ',
        ]), 'First reviewer label');
        $retry = $service->recordHumanJudgment(301, [904], 99, $this->input(), 'Renamed reviewer');
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        self::assertCount(1, $first['human_judgments']);
        self::assertSame($first['human_judgments'], $retry['human_judgments']);
        self::assertSame(self::REQUEST, $retry['human_judgments'][0]['request_id']);
        self::assertSame($retry['human_judgments'], $retry['snapshot']['human_judgments']);
        self::assertSame($retry['human_judgments'], $retry['result_layers']['human_judgments']);
        self::assertSame(self::REQUEST, json_decode((string)Db::name('ai_report_human_reviews')->value('correction_json'), true)['request_id']);
        self::assertSame(self::REQUEST, $this->snapshot()['human_judgments'][0]['request_id']);
    }

    public function testNewRequestWithIdenticalContentIsAnotherIntentionalJudgment(): void
    {
        $service = new AiDailyReportService();
        $service->recordHumanJudgment(301, [904], 99, $this->input());
        $result = $service->recordHumanJudgment(301, [904], 99, $this->input(['request_id' => $this->key(2)]));
        self::assertSame(2, Db::name('ai_report_human_reviews')->count());
        self::assertCount(2, $result['human_judgments']);
        self::assertNotSame($result['human_judgments'][0]['review_record_id'], $result['human_judgments'][1]['review_record_id']);
    }

    public function testLengthNormalizationIsStableAcrossSavedReadbackAndRetry(): void
    {
        $input = $this->input(['target_key' => str_repeat('界', 119) . ' extra',
            'comment' => str_repeat('评', 999) . ' extra', 'correction' => str_repeat('改', 999) . ' extra']);
        $service = new AiDailyReportService();
        $first = $service->recordHumanJudgment(301, [904], 99, $input);
        $retry = $service->recordHumanJudgment(301, [904], 99, $input);
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        self::assertSame($first['human_judgments'], $retry['human_judgments']);
    }

    public function testReportAndReceiptChecksGenerateCurrentReadsBeforeUnlockedProjection(): void
    {
        JudgmentRetrySqliteBuilder::$mysqlSelects = [];
        JudgmentRetrySqliteBuilder::$captureMysql = true;
        try {
            (new AiDailyReportService())->recordHumanJudgment(301, [904], 99, $this->input());
        } finally {
            JudgmentRetrySqliteBuilder::$captureMysql = false;
        }
        $selects = JudgmentRetrySqliteBuilder::$mysqlSelects;
        self::assertStringContainsString('FROM `ai_daily_reports`', $selects[0]);
        self::assertStringContainsString('FOR UPDATE', $selects[0]);
        $reviews = array_values(array_filter($selects, static fn(string $sql): bool => str_contains($sql, 'FROM `ai_report_human_reviews`')));
        self::assertCount(2, $reviews, 'The receipt lookup and response projection execute as separate reads.');
        self::assertStringContainsString('FOR UPDATE', $reviews[0], 'A retry must see the latest committed receipt even after an earlier transaction snapshot.');
        self::assertStringNotContainsString('FOR UPDATE', $reviews[1]);
    }

    public function testAppendOnlyReceiptSurvivesSnapshotEvictionAndKeepsFullHistory(): void
    {
        $service = new AiDailyReportService();
        $first = $service->recordHumanJudgment(301, [904], 99, $this->input());
        $snapshot = $this->snapshot();
        $snapshot['human_judgments'] = [];
        $record = Db::name('ai_report_human_reviews')->where('report_id', 301)->find();
        unset($record['id']);
        for ($i = 1; $i <= 101; $i++) {
            $record['correction_json'] = json_encode(['request_id' => $this->key($i), 'comment' => 'later-' . $i, 'correction' => '']);
            $reviewId = (int)Db::name('ai_report_human_reviews')->insertGetId($record);
            $snapshot['human_judgments'][] = array_replace($first['human_judgments'][0], [
                'id' => 'review-' . $reviewId, 'review_record_id' => $reviewId,
                'request_id' => $this->key($i), 'comment' => 'later-' . $i,
            ]);
        }
        $snapshot['human_judgments'] = array_slice($snapshot['human_judgments'], -100);
        Db::name('ai_daily_reports')->where('id', 301)->update(['snapshot_json' => json_encode($snapshot)]);
        $retry = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame(102, Db::name('ai_report_human_reviews')->count());
        self::assertCount(102, $retry['human_judgments']);
        self::assertSame($first['human_judgments'][0], $retry['human_judgments'][0]);
    }

    public function testSnapshotReceiptRemainsRetryableWhenAppendOnlyStorageBecomesAvailable(): void
    {
        Db::execute('ALTER TABLE ai_report_human_reviews RENAME TO synthetic_hidden_reviews');
        $service = new AiDailyReportService();
        $first = $service->recordHumanJudgment(301, [904], 99, $this->input());
        Db::execute('ALTER TABLE synthetic_hidden_reviews RENAME TO ai_report_human_reviews');
        $retry = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame(0, Db::name('ai_report_human_reviews')->count());
        self::assertSame($first['human_judgments'], $retry['human_judgments']);
        $next = $service->recordHumanJudgment(301, [904], 99, $this->input(['request_id' => $this->key(2)]));
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        self::assertCount(2, $next['human_judgments']);
    }

    public static function conflictingFields(): array
    {
        return [['target_type', 'ai_interpretation'], ['target_key', 'changed'],
            ['decision', 'rejected'], ['comment', 'changed'], ['correction', 'changed']];
    }

    #[DataProvider('conflictingFields')]
    public function testSameRequestWithChangedContentIsRejectedWithoutChangingHistory(string $field, string $value): void
    {
        $service = new AiDailyReportService();
        $service->recordHumanJudgment(301, [904], 99, $this->input());
        $before = $this->snapshot();
        try {
            $service->recordHumanJudgment(301, [904], 99, $this->input([$field => $value]));
            self::fail('Conflicting content must not append or replace the original judgment.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('request_id', $error->getMessage());
        }
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        self::assertSame($before, $this->snapshot());
    }

    public function testRequestIdentityIsScopedToActorReportHotelAndTenant(): void
    {
        $service = new AiDailyReportService();
        foreach ([[301, 904, 99], [301, 904, 100], [302, 905, 99], [303, 904, 99]] as [$report, $hotel, $actor]) {
            $service->recordHumanJudgment($report, [$hotel], $actor, $this->input());
            $service->recordHumanJudgment($report, [$hotel], $actor, $this->input());
        }
        self::assertSame(4, Db::name('ai_report_human_reviews')->count());
        self::assertSame([99, 100], array_column($service->read(301, [904])['human_judgments'], 'user_id'));
        foreach ([[301, [905]], [302, [904]]] as [$report, $scope]) {
            try {
                $service->recordHumanJudgment($report, $scope, 99, $this->input());
                self::fail('Out-of-scope request must not retrieve an existing receipt.');
            } catch (\RuntimeException $error) {
                self::assertSame('AI daily report not found', $error->getMessage());
            }
        }
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9014]);
        try {
            $service->recordHumanJudgment(301, [904], 99, $this->input());
            self::fail('Previous-tenant receipt must not bypass current report authorization.');
        } catch (\RuntimeException $error) {
            self::assertSame('AI daily report not found', $error->getMessage());
        }
        self::assertSame(4, Db::name('ai_report_human_reviews')->count());
    }

    public function testForeignReviewRowsCannotSupplyReceiptOrLeakThroughReadback(): void
    {
        foreach ([[905, 9005], [904, 9014]] as [$hotel, $tenant]) {
            Db::name('ai_report_human_reviews')->insert([
                'report_id' => 301, 'hotel_id' => $hotel, 'tenant_id' => $tenant,
                'subject_type' => 'overall', 'subject_key' => 'synthetic', 'decision' => 'accepted',
                'correction_json' => json_encode(['request_id' => self::REQUEST,
                    'comment' => 'foreign synthetic opinion', 'correction' => '']),
                'created_by' => 99, 'created_at' => '2026-09-14 08:00:00',
            ]);
        }
        $result = (new AiDailyReportService())->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame(3, Db::name('ai_report_human_reviews')->count());
        self::assertCount(1, $result['human_judgments']);
        self::assertSame('synthetic opinion', $result['human_judgments'][0]['comment']);
    }

    public function testLegacyClientsWithoutRequestKeyContinueToAppend(): void
    {
        $input = $this->input();
        unset($input['request_id']);
        $service = new AiDailyReportService();
        $service->recordHumanJudgment(301, [904], 99, $input);
        $result = $service->recordHumanJudgment(301, [904], 99, $input);
        self::assertSame(2, Db::name('ai_report_human_reviews')->count());
        self::assertCount(2, $result['human_judgments']);
    }

    public function testLegacySnapshotRetainsRequestReceiptBeyondTheHundredJudgmentWindow(): void
    {
        Db::execute('DROP TABLE ai_report_human_reviews');
        $service = new AiDailyReportService();
        $first = $service->recordHumanJudgment(301, [904], 99, $this->input());
        // Seed later synthetic legacy history without doing 101 unrelated report projections.
        $snapshot = $this->snapshot();
        for ($i = 1; $i <= 100; $i++) {
            $snapshot['human_judgments'][] = array_replace($first['human_judgments'][0], [
                'id' => 'later-' . $i, 'request_id' => $this->key($i), 'comment' => 'later-' . $i,
            ]);
        }
        Db::name('ai_daily_reports')->where('id', 301)->update(['snapshot_json' => json_encode($snapshot)]);
        $service->recordHumanJudgment(301, [904], 99, $this->input(['request_id' => $this->key(102)]));
        $before = $this->snapshot();
        $retry = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame($before, $this->snapshot());
        $matches = array_values(array_filter($retry['human_judgments'], static fn(array $row): bool => ($row['request_id'] ?? '') === self::REQUEST));
        self::assertCount(1, $matches);
        self::assertSame($first['human_judgments'][0]['id'], $matches[0]['id']);
        self::assertSame('snapshot_compatibility_migration_required', $matches[0]['storage_status']);
    }

    public function testUnkeyedLegacySnapshotKeepsItsExistingHundredEntryLimit(): void
    {
        Db::execute('DROP TABLE ai_report_human_reviews');
        $snapshot = $this->snapshot();
        for ($i = 0; $i < 101; $i++) {
            $snapshot['human_judgments'][] = ['id' => 'legacy-' . $i, 'user_id' => 99,
                'target_type' => 'overall', 'decision' => 'accepted', 'comment' => 'legacy-' . $i];
        }
        Db::name('ai_daily_reports')->where('id', 301)->update(['snapshot_json' => json_encode($snapshot)]);
        $input = $this->input();
        unset($input['request_id']);
        $result = (new AiDailyReportService())->recordHumanJudgment(301, [904], 99, $input);
        self::assertCount(100, $this->snapshot()['human_judgments']);
        self::assertCount(100, $result['human_judgments']);
        self::assertSame('legacy-2', $result['human_judgments'][0]['id']);
    }

    public function testCommittedWriteSurvivesReadFailureAndRetryReturnsItsOriginalReceipt(): void
    {
        $service = new class extends AiDailyReportService {
            public bool $failRead = true;
            public function read(int $id, array $hotelIds): ?array
            {
                if ($this->failRead) return ['status' => 'blocked', 'data_status' => 'read_failed', 'report_id' => $id];
                return parent::read($id, $hotelIds);
            }
        };
        $failed = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame('read_failed', $failed['data_status']);
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        $reviewId = (int)Db::name('ai_report_human_reviews')->value('id');
        $service->failRead = false;
        $retry = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
        self::assertSame($reviewId, $retry['human_judgments'][0]['review_record_id']);
        self::assertSame(self::REQUEST, $retry['human_judgments'][0]['request_id']);
    }

    public function testSnapshotWriteFailureRollsBackAppendRecordBeforeRetry(): void
    {
        Db::execute("CREATE TRIGGER synthetic_snapshot_failure BEFORE UPDATE ON ai_daily_reports BEGIN SELECT RAISE(ABORT, 'synthetic snapshot write failure'); END");
        $service = new AiDailyReportService();
        try {
            $service->recordHumanJudgment(301, [904], 99, $this->input());
            self::fail('Synthetic write failure was expected.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('synthetic snapshot write failure', $error->getMessage());
        } finally {
            Db::execute('DROP TRIGGER synthetic_snapshot_failure');
        }
        self::assertSame(0, Db::name('ai_report_human_reviews')->count());
        self::assertSame([], $this->snapshot()['human_judgments']);
        $result = $service->recordHumanJudgment(301, [904], 99, $this->input());
        self::assertCount(1, $result['human_judgments']);
        self::assertSame(1, Db::name('ai_report_human_reviews')->count());
    }

    public function testMalformedRequestIdIsRejectedBeforeAnyWrite(): void
    {
        try {
            (new AiDailyReportService())->recordHumanJudgment(301, [904], 99, $this->input(['request_id' => 'not-a-uuid']));
            self::fail('A supplied request key must be a UUID.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('request_id', $error->getMessage());
        }
        self::assertSame(0, Db::name('ai_report_human_reviews')->count());
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['request_id' => self::REQUEST, 'target_type' => 'overall',
            'target_key' => 'synthetic', 'decision' => 'accepted',
            'comment' => 'synthetic opinion', 'correction' => ''], $overrides);
    }

    private function snapshot(): array
    {
        return json_decode((string)Db::name('ai_daily_reports')->where('id', 301)->value('snapshot_json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function key(int $id): string
    {
        return sprintf('82b61de2-0150-4f21-a9c2-%012x', $id);
    }
}

/** Captures MySQL SQL for the actual service queries; execution stays entirely in temporary SQLite. */
final class JudgmentRetrySqliteBuilder extends \think\db\builder\Sqlite
{
    public static bool $captureMysql = false;
    public static array $mysqlSelects = [];

    public function select(\think\db\BaseQuery $query, bool $one = false): string
    {
        if (self::$captureMysql) {
            self::$mysqlSelects[] = (new \think\db\builder\Mysql($this->connection))->select(clone $query, $one);
        }
        return parent::select($query, $one);
    }
}
