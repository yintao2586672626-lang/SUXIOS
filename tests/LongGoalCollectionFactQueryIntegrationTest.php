<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaFieldClosureService;
use app\service\PreciseQueryRouterService;
use app\service\RevenueFactLayerService;
use app\service\TrustedOtaFactRepository;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\OtaLocalCollectorRealImportFixture;
use think\db\connector\Sqlite;
use think\facade\Config;
use think\facade\Db;

/**
 * Metadata-only seam: every returned table/column exists in the isolated SQLite
 * database. All business SQL and values go through the unmodified SQLite driver.
 * This verifies service contracts; it does not verify the MySQL engine.
 */
final class LongGoalCollectionSchemaSqlite extends Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match('/^SHOW COLUMNS FROM \x60([a-zA-Z0-9_]+)\x60$/D', $sql, $match)) {
            $rows = parent::query("PRAGMA table_info('" . $match[1] . "')", [], $master);
            return array_map(static fn(array $row): array => [
                'Field' => $row['name'], 'Type' => $row['type'],
                'Null' => $row['notnull'] ? 'NO' : 'YES',
                'Key' => $row['pk'] ? 'PRI' : '', 'Default' => $row['dflt_value'],
                'Extra' => '',
            ], $rows);
        }
        if (preg_match("/^SHOW TABLES LIKE '([a-zA-Z0-9_]+)'$/D", $sql, $match)) {
            return parent::query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$match[1]],
                $master,
            );
        }
        return parent::query($sql, $bind, $master);
    }
}

/**
 * Basis: collector recovery must not leave a contradicted source amount marked
 * readback_verified in the revenue consumer. Reuse the real-import/recovery
 * fixture, the fact-layer public build method, and the router persistence schema.
 * Never alter a source method, captured fact, or canonical trust result to make
 * a sample consumable. The original fixture intentionally lacks canonical proof.
 */
final class LongGoalCollectionFactQueryIntegrationTest extends TestCase
{
    private OtaLocalCollectorRealImportFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new OtaLocalCollectorRealImportFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    public function testOriginalSavedAmountReachesFactLayerWhileCanonicalQueryRemainsExplicitlyBlocked(): void
    {
        [, $saved] = $this->importOriginal();
        $observed = $this->observe('original', $saved);
        self::assertSame('success', $saved['status']);
        self::assertTrue($saved['delivery']['readback_verified']);
        self::assertSame(2, $saved['delivery']['saved_count']);
        self::assertSame(688.5, $observed['revenue_fact_layer']['value']);
        self::assertSame('readback_verified', $observed['revenue_fact_layer']['metric_status']);
        self::assertSame([1], $observed['revenue_fact_layer']['source_row_ids']);
        self::assertSame('empty', $observed['trusted_repository']['status']);
        self::assertSame(
            ['ingestion_method_untrusted' => 2],
            $observed['trusted_repository']['rejected_reasons'],
        );
        $this->assertCanonicalQueryIsBlockedAndReadBack($observed);
    }

    public function testCollectorUnknownAfterChangedAmountCannotRemainVerifiedInFactLayer(): void
    {
        [$envelope] = $this->importOriginal();
        // Same mutation as OtaLocalCollectorRecoveryTest, in this fixture only.
        Db::name('online_daily_data')->where('system_hotel_id', 101)
            ->where('data_type', 'business')->update(['amount' => 9999]);
        $replayed = $this->fixture->submit($envelope);
        $observed = $this->observe('unknown_changed_amount', $replayed);
        self::assertSame('result_unknown', $replayed['status']);
        self::assertSame('original_row_values_changed', $replayed['reconciliation']['reason_code']);
        self::assertFalse($replayed['reconciliation']['readback_verified']);
        $this->assertCanonicalQueryIsBlockedAndReadBack($observed);
        self::assertNull(
            $observed['revenue_fact_layer']['value'],
            'An original-row fingerprint mismatch must not publish the changed amount as a verified revenue fact.',
        );
        self::assertNotSame('readback_verified', $observed['revenue_fact_layer']['metric_status']);
    }

    public function testExplicitReadbackFailureCannotRemainVerifiedInFactLayer(): void
    {
        [$envelope] = $this->importOriginal();
        Db::name('online_daily_data')->where('system_hotel_id', 101)
            ->where('data_type', 'business')->update(['readback_verified' => 0]);
        $replayed = $this->fixture->submit($envelope);
        $observed = $this->observe('readback_failed', $replayed);
        self::assertFalse($replayed['reconciliation']['readback_verified']);
        $this->assertCanonicalQueryIsBlockedAndReadBack($observed);
        self::assertNull($observed['revenue_fact_layer']['value']);
        self::assertNotSame('readback_verified', $observed['revenue_fact_layer']['metric_status']);
    }

    /** @return array{array,array} */
    private function importOriginal(): array
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult(true));
        $saved = $this->fixture->submit($envelope);
        self::assertSame('accepted', $saved['delivery']['status'] ?? null);
        self::assertTrue($saved['delivery']['readback_verified']);
        $this->enableMetadataOnlySeam();
        return [$envelope, $saved];
    }

    private function enableMetadataOnlySeam(): void
    {
        Db::connect()->close();
        Config::set([
            'default' => 'synthetic_sqlite',
            'connections' => ['synthetic_sqlite' => [
                'type' => LongGoalCollectionSchemaSqlite::class,
                'builder' => '\\think\\db\\builder\\Sqlite',
                'database' => $this->fixture->databasePath,
                'prefix' => '', 'fields_strict' => false,
            ]],
        ], 'database');
        Db::connect(null, true);
        // Same isolated persistence shape used by PreciseQueryRouterServiceTest.
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    private function observe(string $case, array $collector): array
    {
        $layer = (new RevenueFactLayerService())->build(101, '2026-09-01');
        $repository = (new TrustedOtaFactRepository())->pricingHistory(101, '2026-09-01', '2026-09-01');
        $reader = static fn(int $hotelId, string $date): array =>
            (new DualOtaFieldClosureService())->build($hotelId, $date);
        $closure = $reader(101, '2026-09-01');
        $field = array_column($closure['platforms']['meituan']['fields'], null, 'key')['revenue'];
        $router = new PreciseQueryRouterService(
            static function (): array { throw new RuntimeException('Unexpected non-operating route'); },
            static function (): array { throw new RuntimeException('Unexpected knowledge route'); },
            static fn(): DateTimeImmutable =>
                new DateTimeImmutable('2026-09-02 12:00:00', new DateTimeZone('Asia/Shanghai')),
            $reader,
            $reader,
        );
        $query = $router->route(12, [101], 7, [
            'query' => '美团订单金额多少？',
            'current_scope' => [
                'hotel_id' => 101, 'hotel_name' => 'SYNTHETIC Hotel', 'platform' => 'meituan',
                'date_start' => '2026-09-01', 'date_end' => '2026-09-01',
            ],
        ]);
        $queryReadback = $router->read((int)$query['id'], 12, [101]);
        $metric = $layer['sources']['meituan_ota']['fact_statuses']['revenue'];
        $result = [
            'case' => $case, 'dataset_kind' => 'synthetic',
            'scope' => ['tenant_id' => 12, 'hotel_id' => 101, 'platform' => 'meituan',
                'platform_hotel_id' => OtaLocalCollectorRealImportFixture::PLATFORM_HOTEL_ID,
                'business_date' => '2026-09-01'],
            'environment' => ['engine' => 'SQLite', 'project_configuration_loaded' => false,
                'metadata_seam' => 'SHOW metadata from actual PRAGMA/sqlite_master only',
                'business_results_injected' => false, 'network_used' => false],
            'collector' => [
                'status' => $collector['status'] ?? null,
                'delivery' => array_intersect_key($collector['delivery'] ?? [], array_flip([
                    'status', 'business_status', 'saved_count', 'readback_verified', 'rows_fingerprint',
                ])),
                'reconciliation' => array_intersect_key($collector['reconciliation'] ?? [], array_flip([
                    'status', 'reason_code', 'readback_verified', 'row_ids',
                ])),
            ],
            'stored_rows' => Db::name('online_daily_data')->field(
                'id,tenant_id,system_hotel_id,source,hotel_id,data_date,data_type,amount,readback_verified,ingestion_method'
            )->order('id')->select()->toArray(),
            'trusted_repository' => [
                'status' => $repository['data_status'],
                'rejected_reasons' => $repository['data_quality']['rejected_reasons'],
                'ingestion_policy' => $repository['source_policy']['ingestion_policy'],
            ],
            'revenue_fact_layer' => [
                'overall_status' => $layer['revenue_analysis_status'],
                'value' => $layer['facts']['ota_channel']['meituan']['revenue'],
                'metric_status' => $metric['status'],
                'source_row_ids' => $metric['source_provenance']['row_ids'] ?? [],
                'source_provenance' => $metric['source_provenance'] ?? null,
            ],
            'canonical_revenue' => array_intersect_key($field, array_flip([
                'key', 'status', 'value', 'basis', 'note', 'quality_flags', 'formal_readback_verified',
                'current_receipt_binding_verified', 'exact_run_scope_verified', 'strict_final_gate',
                'revenue_analysis_consumable', 'revenue_analysis_blockers', 'source_record_refs',
            ])),
            'query' => [
                'status' => $query['status'], 'value' => $query['answer']['value'],
                'fact_refs' => $query['fact_refs'], 'summary' => $query['answer_summary'],
                'exact_saved_readback' => $query === $queryReadback,
            ],
            'positive_01_to_02_to_03_claim' => 'BLOCKED: original fixture does not provide consumable canonical revenue',
        ];
        $this->writeEvidence($case, $result);
        return $result;
    }

    private function assertCanonicalQueryIsBlockedAndReadBack(array $observed): void
    {
        self::assertSame('blocked_by_canonical_fact_status', $observed['query']['status']);
        self::assertNull($observed['query']['value']);
        self::assertTrue($observed['query']['exact_saved_readback']);
        self::assertFalse($observed['canonical_revenue']['revenue_analysis_consumable']);
    }

    private function writeEvidence(string $case, array $result): void
    {
        $root = dirname(__DIR__);
        foreach ([
            'tests/LongGoalCollectionFactQueryIntegrationTest.php',
            'tests/Support/OtaLocalCollectorRealImportFixture.php',
            'app/service/OtaLocalCollectorService.php',
            'app/service/concern/OtaLocalCollectorRecoveryConcern.php',
            'app/service/RevenueFactLayerService.php',
            'app/service/OtaStandardEtlService.php',
            'app/service/OtaRevenueMetricService.php',
            'app/service/TrustedOtaFactRepository.php',
            'app/service/DualOtaFieldClosureService.php',
            'app/service/PreciseQueryRouterService.php',
        ] as $file) {
            $result['source_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
        }
        $directory = $root . '/output/long-goals';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents(
            $directory . '/collection-fact-query-' . $case . '.json',
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }
}
