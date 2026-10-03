<?php
declare(strict_types=1);

use app\service\AiDailyReportBroadcastSnapshotService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\db\BaseQuery;
use think\db\connector\Sqlite;
use think\facade\Config;
use think\facade\Db;

/** Isolated store with optional stale reads and MySQL-style unique-conflict evidence. */
final class BroadcastSnapshotFixtureSqlite extends Sqlite
{
    public int $hiddenFactReads = 0;
    public int $duplicateKeyConflicts = 0;

    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match("/^SHOW TABLES LIKE '([a-zA-Z0-9_]+)'$/D", $sql, $match)) {
            return parent::query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$match[1]],
                $master
            );
        }
        return parent::query($sql, $bind, $master);
    }

    public function find(BaseQuery $query): array
    {
        if ($this->hiddenFactReads > 0 && str_contains(
            json_encode($query->getOptions('where'), JSON_THROW_ON_ERROR),
            'facts_fingerprint'
        )) {
            $this->hiddenFactReads--;
            return [];
        }
        return parent::find($query);
    }

    public function insert(BaseQuery $query, bool $getLastInsID = false)
    {
        try {
            return parent::insert($query, $getLastInsID);
        } catch (Throwable $error) {
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                $this->duplicateKeyConflicts++;
                throw new RuntimeException('duplicate entry in snapshot fixture', 0, $error);
            }
            throw $error;
        }
    }
}

final class AiDailyReportBroadcastSnapshotServiceTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedBusinessDates')]
    public function testRequestDateMismatchIsRejectedBeforeGeneration(string $entrypoint, ?string $returnedDate): void
    {
        $closure = $this->hotel80Closure();
        $closure['business_date'] = $returnedDate;
        $clockCalls = 0;
        $service = new AiDailyReportBroadcastSnapshotService(
            static fn(): array => $closure,
            static fn(): array => ['id' => 80, 'tenant_id' => 80, 'name' => 'Fixture'],
            static function () use (&$clockCalls): DateTimeImmutable {
                $clockCalls++;
                // Stop the old implementation before it can enter persistence.
                throw new RuntimeException('fixture_generation_must_not_start');
            }
        );

        $error = null;
        try {
            $service->{$entrypoint}(80, '2026-08-23');
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame('AI daily report broadcast strict fact business date mismatch', $error->getMessage());
        self::assertSame(422, $error->getCode());
        self::assertSame(0, $clockCalls, 'Date mismatch must stop before draft generation or persistence.');
    }

    public static function mismatchedBusinessDates(): array
    {
        return [
            'preview wrong day' => ['preview', '2026-08-24'],
            'preview missing day' => ['preview', null],
            'generate wrong day' => ['generateAndReadback', '2026-08-24'],
            'generate missing day' => ['generateAndReadback', null],
        ];
    }

    public function testHotel80PartialFactsAreBroadcastReadyWhileAnalysisRemainsBlocked(): void
    {
        $service = $this->service($this->hotel80Closure());
        $draft = $service->preview(80, '2026-08-23');

        self::assertSame('facts_broadcast_ready', $draft['facts_broadcast_status']);
        self::assertSame('analysis_blocked', $draft['analysis_status']);
        self::assertSame('2026-08-24 23:17:33', $draft['data_cutoff_at']);
        self::assertCount(3, $draft['facts']);
        self::assertSame(['online_daily_data#102476'], $draft['fact_refs']);
        self::assertSame(
            ['exposure', 'visits', 'conversion'],
            array_column($draft['facts'], 'metric_key')
        );
        self::assertStringContainsString('门店：敦煌漠蓝新（Hotel 80）', $draft['final_text']);
        self::assertStringContainsString('业务日期：2026-08-23', $draft['final_text']);
        self::assertStringContainsString(
            '已确认事实：美团曝光人数 1,422、商详访客 206、曝光到访率 14.49%。',
            $draft['final_text']
        );
        self::assertStringContainsString(
            '携程曝光人数事实缺失、收入口径未确认，因此暂不生成双平台竞争和收益结论。',
            $draft['final_text']
        );
        self::assertStringNotContainsString('6,461.43', $draft['final_text']);
        self::assertStringNotContainsString('7,025.14', $draft['final_text']);
        self::assertStringNotContainsString('全酒店经营', $draft['final_text']);
        self::assertFalse($draft['authorization']['wecom_send_authorized']);
        self::assertFalse($draft['authorization']['external_delivery_authorized']);
    }

    public function testNoStrictFactsReturnsCollectionFailureWithoutAdviceOrText(): void
    {
        $closure = $this->hotel80Closure();
        foreach (['ctrip', 'meituan'] as $platform) {
            $closure['platforms'][$platform]['status'] = 'collection_failed';
            $closure['platforms'][$platform]['revenue_analysis']['status'] = 'blocked';
            foreach ($closure['platforms'][$platform]['fields'] as &$field) {
                $field = [
                    'status' => 'collection_failed',
                    'value' => null,
                    'source_record_refs' => [],
                    'revenue_analysis_consumable' => false,
                ];
            }
            unset($field);
        }
        $closure['status'] = 'partial';

        $draft = $this->service($closure)->preview(80, '2026-08-23');

        self::assertSame('collection_failed', $draft['facts_broadcast_status']);
        self::assertSame('analysis_blocked', $draft['analysis_status']);
        self::assertSame([], $draft['facts']);
        self::assertSame([], $draft['fact_refs']);
        self::assertSame('', $draft['final_text']);
        self::assertNull($draft['today_attention']);
    }

    public function testFactsFingerprintAndFinalTextDoNotDriftWithGenerationTime(): void
    {
        $first = $this->service(
            $this->hotel80Closure(),
            '2026-08-25 09:00:00'
        )->preview(80, '2026-08-23');
        $second = $this->service(
            $this->hotel80Closure(),
            '2026-08-25 10:30:00'
        )->preview(80, '2026-08-23');

        self::assertSame($first['facts_fingerprint'], $second['facts_fingerprint']);
        self::assertSame($first['final_text'], $second['final_text']);
        self::assertNotSame($first['generated_at'], $second['generated_at']);
    }

    #[DataProvider('mismatchedSourceScopeCases')]
    public function testPreviewRejectsFactsOutsideTheRequestedScope(string $field, mixed $value): void
    {
        $closure = $this->hotel80Closure();
        $closure[$field] = $value;

        $this->expectExceptionMessage($field === 'business_date'
            ? 'AI daily report broadcast strict fact business date mismatch'
            : 'AI daily report broadcast strict fact scope mismatch');
        $this->expectExceptionCode(422);
        $this->service($closure)->preview(80, '2026-08-23');
    }

    public static function mismatchedSourceScopeCases(): array
    {
        return [
            'hotel' => ['hotel_id', 81],
            'tenant' => ['tenant_id', 81],
            'earlier date' => ['business_date', '2026-08-22'],
            'later date' => ['business_date', '2026-08-24'],
        ];
    }

    public function testMismatchedSourceDateCannotLeaveAnUnreadableSavedSnapshot(): void
    {
        $this->withSnapshotStore(function (): void {
            $closure = $this->hotel80Closure();
            $closure['business_date'] = '2026-08-22';
            $failure = null;
            try {
                $this->service($closure)->generateAndReadback(80, '2026-08-23');
            } catch (RuntimeException $error) {
                $failure = $error;
            }

            self::assertSame(0, Db::name('ai_daily_report_broadcast_snapshots')->count());
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame(422, $failure->getCode());
            self::assertSame('AI daily report broadcast strict fact business date mismatch', $failure->getMessage());
            $recovered = $this->service($this->hotel80Closure())->generateAndReadback(80, '2026-08-23');
            self::assertTrue($recovered['readback_verified']);
            self::assertSame(1, Db::name('ai_daily_report_broadcast_snapshots')->count());
        });
    }

    public function testExactReadbackCannotExposeAnEarlierTenantSnapshotForTheSameHotel(): void
    {
        $this->withSnapshotStore(function (): void {
            $saved = $this->service($this->hotel80Closure())->generateAndReadback(80, '2026-08-23');
            $currentTenantService = new AiDailyReportBroadcastSnapshotService(
                hotelReader: static fn(): array => ['id' => 80, 'tenant_id' => 81, 'name' => '当前租户门店']
            );

            self::assertNull($currentTenantService->readLatest(80, '2026-08-23', [80]));
            self::assertNull($currentTenantService->readExact($saved['snapshot_id'], [80]));
            self::assertSame(1, Db::name('ai_daily_report_broadcast_snapshots')->count());
        });
    }

    public function testLatestPreviewRejectsUnauthorizedHotelBeforeLoadingItsFacts(): void
    {
        $reads = 0;
        $closure = $this->hotel80Closure();
        $closure['hotel_id'] = 81;
        $closure['tenant_id'] = 81;
        $service = new AiDailyReportBroadcastSnapshotService(
            closureReader: static fn(): array => $closure,
            hotelReader: static function () use (&$reads): array {
                $reads++;
                return ['id' => 81, 'tenant_id' => 81, 'name' => '无权访问的门店'];
            }
        );
        $failure = null;
        try {
            $service->latestOrPreview(81, '2026-08-23', [80]);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertSame(0, $reads, 'an unauthorized preview must not reach the hotel reader');
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('hotel_id is not permitted', $failure->getMessage());
    }

    #[DataProvider('requestedDateCases')]
    public function testSavedLatestExactAndReplayKeepTheSameFactsAndBroadcastText(string $requestedDate): void
    {
        $this->withSnapshotStore(function () use ($requestedDate): void {
            $service = $this->service($this->hotel80Closure());
            $saved = $service->generateAndReadback(80, $requestedDate);
            self::assertTrue($saved['created']);
            self::assertTrue($saved['readback_verified']);
            self::assertSame('2026-08-23', $saved['business_date']);
            self::assertSame(80, $saved['tenant_id']);
            self::assertSame(80, $saved['hotel_id']);

            $replay = $service->generateAndReadback(80, '2026-08-23');
            self::assertTrue($replay['reused']);
            foreach ([$replay, $service->readLatest(80, '2026-08-23', [80]),
                $service->latestOrPreview(80, '2026-08-23', [80]),
                $service->readExact($saved['snapshot_id'], [80])] as $readback) {
                foreach (['snapshot_id', 'facts', 'fact_refs', 'missing_items', 'source_status',
                    'final_text', 'facts_fingerprint', 'snapshot_fingerprint', 'final_text_sha256'] as $field) {
                    self::assertSame($saved[$field], $readback[$field], $field);
                }
                self::assertTrue($readback['readback_verified']);
            }
            self::assertNull($service->readExact($saved['snapshot_id'], [81]));
            self::assertNull($service->readLatest(80, '2026-08-22', [80]));
            self::assertSame(1, Db::name('ai_daily_report_broadcast_snapshots')->count());
        });
    }

    public static function requestedDateCases(): array
    {
        return [
            'canonical date' => ['2026-08-23'],
            'trimmed date' => [' 2026-08-23 '],
        ];
    }

    public function testChangedStoredTextCannotBeReadAsAVerifiedBroadcast(): void
    {
        $this->withSnapshotStore(function (): void {
            $service = $this->service($this->hotel80Closure());
            $saved = $service->generateAndReadback(80, '2026-08-23');
            Db::name('ai_daily_report_broadcast_snapshots')->where('id', $saved['snapshot_id'])
                ->update(['final_text' => '与已保存事实不一致的正文']);

            $this->expectExceptionMessage('AI daily report broadcast snapshot readback identity mismatch');
            $service->readExact($saved['snapshot_id'], [80]);
        });
    }

    public function testCorruptedWriteReadbackRollsBackAndSameFactsCanRecover(): void
    {
        $this->withSnapshotStore(function (): void {
            $service = $this->service($this->hotel80Closure());
            Db::execute("CREATE TRIGGER corrupt_broadcast_fixture
                AFTER INSERT ON ai_daily_report_broadcast_snapshots
                BEGIN
                    UPDATE ai_daily_report_broadcast_snapshots
                    SET final_text = 'fixture storage corruption' WHERE id = NEW.id;
                END");
            $failure = null;
            try {
                $service->generateAndReadback(80, '2026-08-23');
            } catch (RuntimeException $error) {
                $failure = $error;
            }

            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame('AI daily report broadcast snapshot readback identity mismatch', $failure->getMessage());
            self::assertSame(0, Db::name('ai_daily_report_broadcast_snapshots')->count());

            Db::execute('DROP TRIGGER corrupt_broadcast_fixture');
            $recovered = $service->generateAndReadback(80, '2026-08-23');
            self::assertTrue($recovered['created']);
            self::assertTrue($recovered['readback_verified']);
            self::assertSame(1, $recovered['version_no']);
            $replay = $service->generateAndReadback(80, '2026-08-23');
            self::assertTrue($replay['reused']);
            self::assertSame($recovered['snapshot_id'], $replay['snapshot_id']);
            self::assertSame(1, Db::name('ai_daily_report_broadcast_snapshots')->count());
        });
    }

    #[DataProvider('concurrentWinnerReadCases')]
    public function testConcurrentWinnerIsReadBackAsReusedWithoutAnotherSnapshot(int $hiddenReads): void
    {
        $this->withSnapshotStore(function () use ($hiddenReads): void {
            $service = $this->service($this->hotel80Closure());
            $winner = $service->generateAndReadback(80, '2026-08-23');
            $database = Db::connect();
            self::assertInstanceOf(BroadcastSnapshotFixtureSqlite::class, $database);
            // Model a winner appearing after the first read or only after a unique-key conflict.
            $database->hiddenFactReads = $hiddenReads;

            $reused = $service->generateAndReadback(80, '2026-08-23');

            self::assertFalse($reused['created']);
            self::assertTrue($reused['reused']);
            self::assertTrue($reused['readback_verified']);
            self::assertSame($winner['snapshot_id'], $reused['snapshot_id']);
            self::assertSame($winner['snapshot_fingerprint'], $reused['snapshot_fingerprint']);
            self::assertSame(0, $database->hiddenFactReads);
            self::assertSame($hiddenReads === 2 ? 1 : 0, $database->duplicateKeyConflicts);
            self::assertSame(1, Db::name('ai_daily_report_broadcast_snapshots')->count());
        });
    }

    public static function concurrentWinnerReadCases(): array
    {
        return [
            'winner visible inside transaction' => [1],
            'winner visible after unique-key conflict' => [2],
        ];
    }

    private function withSnapshotStore(callable $operation): void
    {
        $originalConfig = Config::get('database', []);
        $originalCache = Config::get('cache', []);
        $originalLog = Config::get('log', []);
        $connection = 'broadcast_snapshot_fixture_' . bin2hex(random_bytes(6));
        $databasePath = tempnam(sys_get_temp_dir(), 'broadcast-snapshot-');
        Config::set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH'),
        ]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs',
        ]]], 'log');
        Config::set([
            'default' => $connection,
            'connections' => [$connection => [
                'type' => '\\' . BroadcastSnapshotFixtureSqlite::class,
                'builder' => '\\think\\db\\builder\\Sqlite',
                'database' => $databasePath,
                'prefix' => '', 'fields_strict' => true,
            ]],
        ], 'database');
        $database = null;
        try {
            $database = Db::connect(null, true);
            Db::execute('CREATE TABLE ai_daily_report_broadcast_snapshots (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
                hotel_id INTEGER NOT NULL, business_date TEXT NOT NULL, version_no INTEGER NOT NULL,
                facts_broadcast_status TEXT NOT NULL, analysis_status TEXT NOT NULL,
                view_status TEXT NOT NULL, generation_trigger TEXT NOT NULL, template_version TEXT NOT NULL,
                hotel_name_snapshot TEXT NOT NULL, data_cutoff_at TEXT, facts_fingerprint TEXT NOT NULL,
                snapshot_fingerprint TEXT NOT NULL, final_text_sha256 TEXT NOT NULL, facts_json TEXT NOT NULL,
                fact_refs_json TEXT NOT NULL, missing_items_json TEXT NOT NULL, source_status_json TEXT NOT NULL,
                final_text TEXT NOT NULL, generated_at TEXT NOT NULL, created_by INTEGER NOT NULL,
                UNIQUE(tenant_id, hotel_id, business_date, version_no),
                UNIQUE(tenant_id, hotel_id, business_date, template_version, generation_trigger, facts_fingerprint)
            )');
            $operation();
        } finally {
            $database?->close();
            Config::set($originalConfig, 'database');
            Config::set($originalCache, 'cache');
            Config::set($originalLog, 'log');
            unlink($databasePath);
        }
    }

    public function testStrictValuesRejectedByCanonicalIdentityGateCannotBecomeBroadcastFacts(): void
    {
        $closure = $this->hotel80Closure();
        foreach (['ctrip', 'meituan'] as $platform) {
            $closure['platforms'][$platform]['identity_status'] = 'unverified';
            foreach ($closure['platforms'][$platform]['fields'] as &$field) {
                $field['revenue_analysis_consumable'] = false;
                $field['revenue_analysis_blockers'] = ['identity_binding_not_verified'];
            }
            unset($field);
        }

        $draft = $this->service($closure)->preview(80, '2026-08-23');

        self::assertSame([], $draft['facts']);
        self::assertSame([], $draft['fact_refs']);
        self::assertSame('', $draft['final_text']);
        self::assertFalse($draft['can_generate']);
    }

    public function testVerifiedPartialFactsSurviveWhileUnusableOrUnreferencedFieldsStayMissing(): void
    {
        $closure = $this->hotel80Closure();
        $closure['platforms']['meituan']['fields']['visits']['revenue_analysis_consumable'] = false;
        $closure['platforms']['meituan']['fields']['conversion']['source_record_refs'] = [];

        $draft = $this->service($closure)->preview(80, '2026-08-23');

        self::assertSame('facts_broadcast_ready', $draft['facts_broadcast_status']);
        self::assertSame(['exposure'], array_column($draft['facts'], 'metric_key'));
        self::assertSame(1422, $draft['facts'][0]['value']);
        $missing = array_column($draft['missing_items'], 'code');
        self::assertContains('meituan_visits_strict_readback', $missing);
        self::assertContains('meituan_conversion_verified_calculation', $missing);
    }

    public function testMissingTrafficIsNamedEvenWhenExposureAndRevenueHeadlinesAreSatisfied(): void
    {
        $closure = $this->hotel80Closure();
        $closure['platforms']['ctrip']['fields']['exposure'] = $this->fact('strict_readback', 100, ['online_daily_data#102231'], true);
        $closure['platforms']['ctrip']['fields']['revenue']['revenue_analysis_consumable'] = true;
        $closure['platforms']['meituan']['fields']['revenue'] = $this->fact('strict_readback', 200, ['online_daily_data#102476'], true);
        $closure['platforms']['meituan']['fields']['adr'] = $this->missing('missing');
        $closure['platforms']['meituan']['fields']['exposure'] = $this->missing('missing');

        $draft = $this->service($closure)->preview(80, '2026-08-23');

        self::assertNotEmpty($draft['missing_items']);
        self::assertStringNotContainsString('当前未发现关键事实缺口', $draft['final_text']);
        self::assertStringContainsString('美团曝光人数事实缺失', $draft['final_text']);
    }

    /** @param array<string,mixed> $closure */
    private function service(
        array $closure,
        string $now = '2026-08-25 09:00:00'
    ): AiDailyReportBroadcastSnapshotService {
        return new AiDailyReportBroadcastSnapshotService(
            static fn(int $hotelId, string $businessDate): array => $closure,
            static fn(int $hotelId): array => [
                'id' => $hotelId,
                'tenant_id' => 80,
                'name' => '敦煌漠蓝新',
            ],
            static fn(): DateTimeImmutable => new DateTimeImmutable($now)
        );
    }

    /** @return array<string,mixed> */
    private function hotel80Closure(): array
    {
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 80,
            'hotel_id' => 80,
            'business_date' => '2026-08-23',
            'status' => 'partial',
            'platforms' => [
                'ctrip' => [
                    'identity_status' => 'verified',
                    'platform_status' => 'verified',
                    'target_date_status' => 'matched',
                    'exact_run_readback_status' => 'verified',
                    'status' => 'partial',
                    'revenue_analysis' => ['status' => 'blocked'],
                    'fields' => [
                        'revenue' => $this->fact('strict_readback', 6647.02, ['online_daily_data#102231']),
                        'order_count' => $this->fact('strict_readback', 4, ['online_daily_data#102235']),
                        'room_nights' => $this->fact('strict_readback', 11, ['online_daily_data#102231']),
                        'adr' => $this->fact('verified_calculation', 604.27, ['online_daily_data#102231']),
                        'exposure' => $this->missing('missing'),
                        'visits' => $this->fact('strict_readback', 44, ['online_daily_data#102479']),
                        'conversion' => $this->missing('missing'),
                        'collected_at' => $this->textFact('2026-08-24 23:19:12'),
                    ],
                ],
                'meituan' => [
                    'identity_status' => 'verified',
                    'platform_status' => 'verified',
                    'target_date_status' => 'matched',
                    'exact_run_readback_status' => 'verified',
                    'status' => 'partial',
                    'revenue_analysis' => ['status' => 'blocked'],
                    'fields' => [
                        'revenue' => [
                            'status' => 'caliber_uncertain',
                            'value' => null,
                            'observed_values' => [
                                ['value' => 6461.43, 'source_record_ref' => 'online_daily_data#101920'],
                                ['value' => 7025.14, 'source_record_ref' => 'online_daily_data#101926'],
                            ],
                            'source_record_refs' => ['online_daily_data#101920', 'online_daily_data#101926'],
                            'revenue_analysis_consumable' => false,
                        ],
                        'order_count' => $this->fact('strict_readback', 8, ['online_daily_data#101926']),
                        'room_nights' => $this->fact('strict_readback', 12, ['online_daily_data#101926']),
                        'adr' => $this->missing('caliber_uncertain'),
                        'exposure' => $this->fact('strict_readback', 1422, ['online_daily_data#102476'], true),
                        'visits' => $this->fact('strict_readback', 206, ['online_daily_data#102476'], true),
                        'conversion' => $this->fact('verified_calculation', 14.49, ['online_daily_data#102476'], true),
                        'collected_at' => $this->textFact('2026-08-24 23:17:33'),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function fact(
        string $status,
        int|float $value,
        array $refs,
        bool $consumable = false
    ): array {
        return [
            'status' => $status,
            'value' => $value,
            'source_record_refs' => $refs,
            'revenue_analysis_consumable' => $consumable,
        ];
    }

    /** @return array<string,mixed> */
    private function missing(string $status): array
    {
        return [
            'status' => $status,
            'value' => null,
            'source_record_refs' => [],
            'revenue_analysis_consumable' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function textFact(string $value): array
    {
        return [
            'status' => 'strict_readback',
            'value' => $value,
            'source_record_refs' => ['online_daily_data#102476'],
            'revenue_analysis_consumable' => false,
        ];
    }
}
