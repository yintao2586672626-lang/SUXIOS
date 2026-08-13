<?php
declare(strict_types=1);

namespace tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use app\service\HotelCollectionQualityJudgmentService;
use app\service\HotelCollectionRunReceiptService;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelCollectionQualityJudgmentServiceTest extends TestCase
{
    private const TENANT_ID = 8;
    private const HOTEL_ID = 80;
    private const BUSINESS_DATE = '2026-08-11';
    private const DISPATCHER_RUN_ID = 'a1000000-0000-4000-8000-000000000001';

    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'hotel_collection_quality_judgment_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);

        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name(HotelCollectionQualityJudgmentService::TABLE)->delete(true);
    }

    public function testMigrationDefinesIndependentScopedReadbackLedger(): void
    {
        $migration = (string)file_get_contents(
            dirname(__DIR__)
            . '/database/migrations/20260812_zzzz_create_hotel_collection_quality_judgments.sql'
        );

        self::assertStringContainsString(
            'CREATE TABLE IF NOT EXISTS `hotel_collection_quality_judgments`',
            $migration
        );
        self::assertStringContainsString('`source_scope_hash` char(64) NOT NULL', $migration);
        self::assertStringContainsString('`saved_row_count` int unsigned NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('`readback_row_count` int unsigned NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('`missing_count` int unsigned NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('`conflict_count` int unsigned NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('`evidence_digest` char(64) NOT NULL', $migration);
        self::assertStringContainsString('`judgment_digest` char(64) NOT NULL', $migration);
        self::assertStringContainsString(
            'UNIQUE KEY `uq_hotel_collection_quality_scope`',
            $migration
        );
        self::assertStringNotContainsString('`cookie`', strtolower($migration));
        self::assertStringNotContainsString('`token`', strtolower($migration));
    }

    public function testVerifiedPublicReceiptsPersistCountsScopesDigestAndExactReadback(): void
    {
        $receipt = $this->trustedReceipt();
        $service = $this->service($receipt);

        $result = $service->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );

        self::assertSame('available', $result['conclusion']['status']);
        self::assertTrue($result['conclusion']['claim_allowed']);
        self::assertFalse($result['conclusion']['whole_hotel_conclusion_allowed']);
        self::assertFalse($result['conclusion']['business_outcome_claimed']);
        self::assertSame('fresh', $result['freshness']['status']);
        self::assertSame(1, $result['freshness']['age_days']);
        self::assertSame(7, $result['counts']['saved_row_count']);
        self::assertSame(7, $result['counts']['readback_row_count']);
        self::assertSame(0, $result['counts']['missing_count']);
        self::assertSame(0, $result['counts']['conflict_count']);
        self::assertSame(['ctrip', 'meituan'], array_column($result['source_scope'], 'platform'));
        self::assertSame(['ota_channel', 'ota_channel'], array_column($result['source_scope'], 'metric_scope'));
        self::assertSame('whole_hotel_accommodation', $result['pms_scope']['metric_scope']);
        self::assertFalse($result['pms_scope']['used_as_business_outcome']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['source_scope_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['evidence_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['judgment_digest']);
        self::assertTrue($result['readback_verified']);
        self::assertTrue($result['persistence']['saved']);
        self::assertTrue($result['persistence']['readback_verified']);
        self::assertSame(1, $result['persistence']['readback_row_count']);

        $readback = $service->read(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            self::DISPATCHER_RUN_ID
        );
        self::assertSame($result['judgment_digest'], $readback['judgment_digest']);
        self::assertTrue($readback['readback_verified']);
        self::assertSame(
            1,
            (int)Db::name(HotelCollectionQualityJudgmentService::TABLE)->count()
        );
    }

    public function testPartialAndMissingReceiptsPersistButFailClosed(): void
    {
        $partial = $this->trustedReceipt();
        $partial['status'] = 'partial';
        $partial['collection_anchor_hash'] = null;
        $partial['trust_receipt_digest'] = null;
        $partial['source_receipts'][1] = array_replace(
            $partial['source_receipts'][1],
            [
                'status' => 'failed',
                'saved_row_count' => 0,
                'readback_row_count' => 0,
                'readback_verified' => false,
                'failure_stage' => 'capture',
                'failure_code' => 'platform_login_required',
            ]
        );
        $partial['pms_receipt'] = [
            'provider' => 'dingdandao_pms',
            'status' => 'not_run',
            'capture_id' => null,
            'readback_verified' => false,
        ];
        $partialResult = $this->service($partial)->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );

        self::assertSame('partial', $partialResult['conclusion']['status']);
        self::assertFalse($partialResult['conclusion']['claim_allowed']);
        self::assertFalse($partialResult['conclusion']['whole_hotel_conclusion_allowed']);
        self::assertGreaterThan(0, $partialResult['counts']['missing_count']);
        self::assertSame(3, $partialResult['counts']['saved_row_count']);
        self::assertSame(3, $partialResult['counts']['readback_row_count']);
        self::assertTrue($partialResult['readback_verified']);

        Db::name(HotelCollectionQualityJudgmentService::TABLE)->delete(true);
        $missing = $this->missingReceipt();
        $missingResult = $this->service($missing)->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );
        self::assertSame('missing', $missingResult['conclusion']['status']);
        self::assertFalse($missingResult['conclusion']['claim_allowed']);
        self::assertFalse($missingResult['conclusion']['whole_hotel_conclusion_allowed']);
        self::assertSame(0, $missingResult['counts']['saved_row_count']);
        self::assertSame(0, $missingResult['counts']['readback_row_count']);
        self::assertTrue($missingResult['readback_verified']);
    }

    public function testCountConflictIsPersistedAsConflictedAndCannotClaimAvailability(): void
    {
        $receipt = $this->trustedReceipt();
        $receipt['source_receipts'][1]['readback_row_count'] = 2;

        $result = $this->service($receipt)->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );

        self::assertSame('conflicted', $result['conclusion']['status']);
        self::assertFalse($result['conclusion']['claim_allowed']);
        self::assertFalse($result['conclusion']['whole_hotel_conclusion_allowed']);
        self::assertContains(
            'source_saved_readback_count_conflict:meituan',
            $result['conflict_items']
        );
        self::assertGreaterThan(0, $result['counts']['conflict_count']);
        self::assertTrue($result['readback_verified']);
    }

    public function testVerifiedButOldEvidenceIsStaleAndFailsClosed(): void
    {
        $receipt = $this->trustedReceipt();
        $receipt['business_date'] = '2026-08-01';
        $receipt['started_at'] = '2026-08-02 06:55:00';
        $receipt['finished_at'] = '2026-08-02 07:05:00';
        foreach ($receipt['source_receipts'] as $index => $source) {
            $receipt['source_receipts'][$index]['started_at'] = '2026-08-02 06:56:00';
            $receipt['source_receipts'][$index]['finished_at'] = '2026-08-02 07:04:00';
        }

        $result = $this->service($receipt)->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            '2026-08-01',
            $this->assessedAt()
        );

        self::assertSame('stale', $result['conclusion']['status']);
        self::assertFalse($result['conclusion']['claim_allowed']);
        self::assertSame('stale', $result['freshness']['status']);
        self::assertSame(11, $result['freshness']['age_days']);
        self::assertSame(['business_date_stale'], $result['conclusion']['reason_codes']);
        self::assertTrue($result['readback_verified']);
    }

    public function testSameRunCanReconcileFromPartialToAvailableWithoutDuplicateRow(): void
    {
        $receipt = $this->missingReceipt();
        $service = $this->serviceByReference($receipt);
        $first = $service->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );
        $firstId = (int)$first['persistence']['judgment_id'];
        self::assertSame('missing', $first['conclusion']['status']);

        $receipt = $this->trustedReceipt();
        $second = $service->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );

        self::assertSame('available', $second['conclusion']['status']);
        self::assertSame($firstId, (int)$second['persistence']['judgment_id']);
        self::assertSame(
            1,
            (int)Db::name(HotelCollectionQualityJudgmentService::TABLE)->count()
        );
        self::assertTrue($second['readback_verified']);
    }

    public function testReadbackTamperingAndCrossTenantReadBothFailClosed(): void
    {
        $receipt = $this->trustedReceipt();
        $service = $this->service($receipt);
        $saved = $service->assessAndPersist(
            self::DISPATCHER_RUN_ID,
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            $this->assessedAt()
        );

        $crossTenant = $service->read(
            9,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            self::DISPATCHER_RUN_ID
        );
        self::assertSame('missing', $crossTenant['conclusion']['status']);
        self::assertFalse($crossTenant['conclusion']['claim_allowed']);
        self::assertFalse($crossTenant['readback_verified']);

        $row = Db::name(HotelCollectionQualityJudgmentService::TABLE)
            ->where('id', (int)$saved['persistence']['judgment_id'])
            ->find();
        $payload = json_decode((string)$row['judgment_json'], true, 512, JSON_THROW_ON_ERROR);
        $payload['counts']['saved_row_count'] = 999;
        Db::name(HotelCollectionQualityJudgmentService::TABLE)
            ->where('id', (int)$row['id'])
            ->update(['judgment_json' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        $tampered = $service->read(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::BUSINESS_DATE,
            self::DISPATCHER_RUN_ID
        );
        self::assertSame('conflicted', $tampered['conclusion']['status']);
        self::assertFalse($tampered['conclusion']['claim_allowed']);
        self::assertFalse($tampered['readback_verified']);
        self::assertContains('quality_judgment_readback_mismatch', $tampered['conflict_items']);
    }

    public function testWrongScopeOrSensitiveKeyIsRejectedBeforePersistence(): void
    {
        $wrongHotel = $this->trustedReceipt();
        $wrongHotel['system_hotel_id'] = 81;
        $this->assertRuntimeFailure(
            'hotel_collection_quality_public_receipt_contract_invalid',
            fn() => $this->service($wrongHotel)->assessAndPersist(
                self::DISPATCHER_RUN_ID,
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $this->assessedAt()
            )
        );

        $receiptWithForbiddenKey = $this->trustedReceipt();
        $receiptWithForbiddenKey['source_receipts'][0]['authorization'] = 'not-inspected';
        $this->assertRuntimeFailure(
            'hotel_collection_quality_public_receipt_contract_invalid',
            fn() => $this->service($receiptWithForbiddenKey)->assessAndPersist(
                self::DISPATCHER_RUN_ID,
                self::TENANT_ID,
                self::HOTEL_ID,
                self::BUSINESS_DATE,
                $this->assessedAt()
            )
        );
        self::assertSame(
            0,
            (int)Db::name(HotelCollectionQualityJudgmentService::TABLE)->count()
        );
    }

    /** @param array<string,mixed> $receipt */
    private function service(array $receipt): HotelCollectionQualityJudgmentService
    {
        return new HotelCollectionQualityJudgmentService(
            static fn(string $runId, int $tenantId, int $hotelId, string $date): array => $receipt
        );
    }

    /** @param array<string,mixed> $receipt */
    private function serviceByReference(array &$receipt): HotelCollectionQualityJudgmentService
    {
        return new HotelCollectionQualityJudgmentService(
            static function (
                string $runId,
                int $tenantId,
                int $hotelId,
                string $date
            ) use (&$receipt): array {
                return $receipt;
            }
        );
    }

    /** @return array<string,mixed> */
    private function trustedReceipt(): array
    {
        return [
            'schema_version' => HotelCollectionRunReceiptService::SCHEMA_VERSION,
            'id' => 501,
            'dispatcher_run_id' => self::DISPATCHER_RUN_ID,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'run_mode' => 'daily',
            'status' => 'succeeded',
            'failure_stage' => '',
            'failure_code' => '',
            'collection_anchor_contract_version' => 'ota-collection-anchor.v1',
            'collection_anchor_hash' => str_repeat('a', 64),
            'trust_receipt_digest' => str_repeat('b', 64),
            'pms_receipt' => [
                'provider' => 'dingdandao_pms',
                'status' => 'verified',
                'capture_id' => '701',
                'readback_verified' => true,
            ],
            'source_receipts' => [
                $this->sourceReceipt(601, 'ctrip', 25, 3),
                $this->sourceReceipt(602, 'meituan', 68, 4),
            ],
            'ledger_structure_verified' => true,
            'readback_verified' => true,
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
            'started_at' => '2026-08-12 06:55:00',
            'finished_at' => '2026-08-12 07:05:00',
        ];
    }

    /** @return array<string,mixed> */
    private function missingReceipt(): array
    {
        return [
            'schema_version' => HotelCollectionRunReceiptService::SCHEMA_VERSION,
            'dispatcher_run_id' => self::DISPATCHER_RUN_ID,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => self::BUSINESS_DATE,
            'status' => 'missing',
            'source_receipts' => [],
            'ledger_structure_verified' => false,
            'readback_verified' => false,
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function sourceReceipt(int $id, string $platform, int $sourceId, int $count): array
    {
        return [
            'id' => $id,
            'platform' => $platform,
            'data_source_id' => $sourceId,
            'ingestion_method' => 'browser_profile',
            'status' => 'success',
            'failure_stage' => '',
            'failure_code' => '',
            'saved_row_count' => $count,
            'readback_row_count' => $count,
            'readback_verified' => true,
            'started_at' => '2026-08-12 06:56:00',
            'finished_at' => '2026-08-12 07:04:00',
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    private function assessedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-12 08:00:00+08:00');
    }

    private function assertRuntimeFailure(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected RuntimeException: ' . $message);
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotel_collection_quality_judgments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schema_version INTEGER NOT NULL DEFAULT 1,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            dispatcher_run_id TEXT NOT NULL UNIQUE,
            collection_run_receipt_id INTEGER NULL,
            source_scope_hash TEXT NOT NULL,
            saved_row_count INTEGER NOT NULL DEFAULT 0,
            readback_row_count INTEGER NOT NULL DEFAULT 0,
            missing_count INTEGER NOT NULL DEFAULT 0,
            conflict_count INTEGER NOT NULL DEFAULT 0,
            freshness_status TEXT NOT NULL,
            conclusion_status TEXT NOT NULL,
            evidence_digest TEXT NOT NULL,
            judgment_digest TEXT NOT NULL,
            judgment_json TEXT NOT NULL,
            assessed_at TEXT NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (tenant_id, system_hotel_id, business_date, dispatcher_run_id)
        )');
    }
}
