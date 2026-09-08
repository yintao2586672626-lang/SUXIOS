<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaLocalCollectorReadbackProofService;
use app\service\OtaStandardEtlService;
use app\service\RevenueFactLayerService;
use PHPUnit\Framework\TestCase;
use Tests\Support\OtaLocalCollectorRealImportFixture;
use think\db\connector\Sqlite;
use think\facade\Config;
use think\facade\Db;

/** Only translates MySQL metadata syntax; every business row is read by the real SQLite driver. */
final class OtaReadbackProofSqlite extends Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match('/^SHOW COLUMNS FROM \x60([a-zA-Z0-9_]+)\x60$/D', $sql, $match)) {
            return array_map(static fn(array $row): array => [
                'Field' => $row['name'], 'Type' => $row['type'], 'Null' => $row['notnull'] ? 'NO' : 'YES',
                'Key' => $row['pk'] ? 'PRI' : '', 'Default' => $row['dflt_value'], 'Extra' => '',
            ], parent::query("PRAGMA table_info('" . $match[1] . "')", [], $master));
        }
        if (preg_match("/^SHOW TABLES LIKE '([a-zA-Z0-9_]+)'$/D", $sql, $match)) {
            return parent::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$match[1]], $master);
        }
        return parent::query($sql, $bind, $master);
    }
}

final class OtaLocalCollectorReadbackProofTest extends TestCase
{
    private OtaLocalCollectorRealImportFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new OtaLocalCollectorRealImportFixture();
        Db::connect()->close();
        Config::set(['default' => 'synthetic_sqlite', 'connections' => ['synthetic_sqlite' => [
            'type' => OtaReadbackProofSqlite::class, 'builder' => '\\think\\db\\builder\\Sqlite',
            'database' => $this->fixture->databasePath, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
    }

    public function testOriginalNativeReceiptAllows688Point5WithoutChangingBusinessOrReceipt(): void
    {
        [, $saved] = $this->saveOriginal();
        $before = $this->state();
        $fact = $this->businessFact();
        self::assertSame(688.5, $fact['revenue']);
        self::assertTrue($fact['source_trace']['readback_verified']);
        self::assertTrue($fact['source_trace']['saved_success']);
        self::assertSame($saved['delivery']['result_hash'], $fact['source_trace']['collector_readback_proof']['result_hash']);
        $layer = (new RevenueFactLayerService())->build(101, '2026-09-01');
        self::assertSame(688.5, $layer['facts']['ota_channel']['meituan']['revenue']);
        self::assertSame('readback_verified', $layer['sources']['meituan_ota']['fact_statuses']['revenue']['status']);
        self::assertSame($before, $this->state(), 'Proof reads must not mutate values, labels or original receipts.');
    }

    public function testChangedAmountIsUnverifiedBeforeReplayAndAfterReplayUnknown(): void
    {
        [$envelope] = $this->saveOriginal();
        Db::name('online_daily_data')->where('system_hotel_id', 101)->where('data_type', 'business')->update(['amount' => 9999]);
        $before = $this->state();
        foreach ([false, true] as $replay) {
            if ($replay) {
                $response = $this->fixture->submit($envelope);
                self::assertSame('result_unknown', $response['status']);
                self::assertSame('original_row_values_changed', $response['reconciliation']['reason_code']);
            }
            $fact = $this->businessFact();
            self::assertSame(9999.0, $fact['revenue'], 'Observed amount stays visible as unverified evidence.');
            $this->assertUnverified($fact, 'original_row_values_changed');
            $layer = (new RevenueFactLayerService())->build(101, '2026-09-01');
            self::assertNull($layer['facts']['ota_channel']['meituan']['revenue']);
            self::assertNotSame('readback_verified', $layer['sources']['meituan_ota']['fact_statuses']['revenue']['status']);
            self::assertSame($before, $this->state());
        }
    }

    public function testFreshProofNeverCachesAPreviousVerifiedAmountAndCanVerifyExactRestoration(): void
    {
        $this->saveOriginal();
        $etl = new OtaStandardEtlService();
        self::assertTrue($this->businessFact(101, $etl)['source_trace']['saved_success']);
        Db::name('online_daily_data')->where('data_type', 'business')->update(['amount' => 9999]);
        $this->assertUnverified($this->businessFact(101, $etl), 'original_row_values_changed');
        Db::name('online_daily_data')->where('data_type', 'business')->update(['amount' => 688.5]);
        self::assertTrue($this->businessFact(101, $etl)['source_trace']['saved_success']);
    }

    public function testChangedAmountCannotBypassProofByChangingTheRowIngestionLabel(): void
    {
        $this->saveOriginal();
        foreach ([null, '', 'profile_browser'] as $label) {
            Db::name('online_daily_data')->where('data_type', 'business')
                ->update(['amount' => 9999, 'ingestion_method' => $label]);
            $before = $this->state();
            $fact = $this->businessFact();
            self::assertSame(9999.0, $fact['revenue']);
            $this->assertUnverified($fact, 'original_row_values_changed');
            $layer = (new RevenueFactLayerService())->build(101, '2026-09-01');
            self::assertNull($layer['facts']['ota_channel']['meituan']['revenue']);
            self::assertNotSame('readback_verified', $layer['sources']['meituan_ota']['fact_statuses']['revenue']['status']);
            self::assertSame($before, $this->state());
        }
    }

    public function testChangingOnlyTheIngestionLabelStillRequiresTheOriginalContentProof(): void
    {
        $this->saveOriginal();
        foreach ([null, '', 'profile_browser'] as $label) {
            Db::name('online_daily_data')->where('data_type', 'business')->update(['ingestion_method' => $label]);
            self::assertSame(688.5, $this->businessFact()['revenue']);
            $this->assertUnverified($this->businessFact(), 'original_row_content_changed');
        }
        Db::name('online_daily_data')->where('data_type', 'business')->update(['ingestion_method' => 'local_collector']);
        self::assertTrue($this->businessFact()['source_trace']['saved_success']);
    }

    public function testIndependentNonCollectorBatchKeepsItsExistingTrustRulesInTheSameScope(): void
    {
        $this->saveOriginal();
        $id = Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 12, 'system_hotel_id' => 101, 'hotel_id' => OtaLocalCollectorRealImportFixture::PLATFORM_HOTEL_ID,
            'data_source_id' => 2001, 'sync_task_id' => 2002, 'platform' => 'meituan', 'source' => 'meituan',
            'data_date' => '2026-09-01', 'data_type' => 'business', 'data_period' => 'today',
            'compare_type' => 'own', 'dimension' => 'SELF_HOTEL', 'amount' => 525.0,
            'quantity' => 5, 'book_order_num' => 5, 'raw_data' => '{}', 'ingestion_method' => 'profile_browser',
            'readback_verified' => 1, 'validation_status' => 'verified', 'validation_flags' => '[]',
            'source_trace_id' => 'synthetic-profile-browser-source-2001-run-2002',
        ]);
        $before = $this->state();
        $dataset = (new OtaStandardEtlService())->buildDataset($this->filters());
        $facts = array_values(array_filter($dataset['fact_ota_daily'], fn(array $fact): bool => $fact['source_trace']['row_id'] === $id));
        self::assertCount(1, $facts);
        self::assertSame(525.0, $facts[0]['revenue']);
        self::assertTrue($facts[0]['source_trace']['readback_verified']);
        self::assertTrue($facts[0]['source_trace']['saved_success']);
        self::assertArrayNotHasKey('collector_readback_proof', $facts[0]['source_trace']);
        $collector = array_values(array_filter($dataset['fact_ota_daily'], fn(array $fact): bool => $fact['source_trace']['row_id'] !== $id));
        self::assertTrue($collector[0]['source_trace']['saved_success']);
        self::assertSame($before, $this->state());
    }

    public function testMissingReceiptLookupCannotTurnAnUntaggedCollectorBatchIntoVerifiedData(): void
    {
        $this->saveOriginal();
        Db::name('online_daily_data')->where('data_type', 'business')->update(['ingestion_method' => '']);
        Db::execute('DROP TABLE ota_local_collector_tasks');
        $fact = $this->businessFact();
        $this->assertUnverified($fact, 'original_proof_unavailable');
    }

    public function testMissingOriginalReceiptAndMissingOriginalFingerprintRemainUnverified(): void
    {
        [, $saved] = $this->saveOriginal();
        $task = Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->find();
        $request = json_decode($task['request_json'], true);
        $without = $request;
        $without['result_delivery_receipts'] = [];
        Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->update(['request_json' => json_encode($without)]);
        $this->assertUnverified($this->businessFact(), 'original_receipt_missing');
        foreach ($request['result_delivery_receipts'] as &$receipt) unset($receipt['rows_fingerprint']);
        unset($receipt);
        Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->update(['request_json' => json_encode($request)]);
        $this->assertUnverified($this->businessFact(), 'original_rows_fingerprint_missing');
    }

    public function testUnavailableOriginalEvidenceIsUnverifiedWithObservationIntact(): void
    {
        [, $saved] = $this->saveOriginal();
        $receipt = $saved['delivery'];
        file_put_contents($this->fixture->root . '/evidence/12/' . $receipt['device_id'] . '/' . $receipt['task_id']
            . '/' . $receipt['attempt'] . '/' . $receipt['evidence']['result_hash'] . '.json', '{}');
        $this->assertUnverified($this->businessFact(), 'original_evidence_unavailable');
        self::assertSame(688.5, $this->businessFact()['revenue']);
    }

    public function testChangedProjectionCannotBorrowProofFromARestoredDatabaseRow(): void
    {
        $this->saveOriginal();
        $row = Db::name('online_daily_data')->where('data_type', 'business')->find();
        $row['amount'] = 9999.0; // Simulates the ETL snapshot before a concurrent restore; no database write.
        $projected = app(OtaLocalCollectorReadbackProofService::class)->projectRows([$row]);
        $fact = (new OtaStandardEtlService())->buildDatasetFromRows($projected)['fact_ota_daily'][0];
        $this->assertUnverified($fact, 'original_projection_values_changed');
        self::assertSame(688.5, (float)Db::name('online_daily_data')->where('data_type', 'business')->value('amount'));
    }

    public function testPartialProjectionStillVerifiesEveryOriginalRowInItsReceipt(): void
    {
        $this->saveOriginal();
        Db::name('online_daily_data')->where('data_type', 'traffic')->update(['list_exposure' => 9999]);
        $dataset = (new OtaStandardEtlService())->buildDataset($this->filters() + ['data_type' => 'business']);
        self::assertCount(1, $dataset['fact_ota_daily']);
        $this->assertUnverified($dataset['fact_ota_daily'][0], 'original_row_values_changed');
    }

    public function testOldFailureCannotBlockAnotherHotelsVerifiedRun(): void
    {
        $this->saveOriginal();
        $this->saveNewRun(102);
        Db::name('online_daily_data')->where('system_hotel_id', 101)->where('data_type', 'business')->update(['amount' => 9999]);
        $this->assertUnverified($this->businessFact(), 'original_row_values_changed');
        $other = $this->businessFact(102);
        self::assertSame(688.5, $other['revenue']);
        self::assertTrue($other['source_trace']['saved_success']);
        self::assertSame(102, $other['source_trace']['system_hotel_id']);
    }

    public function testOldRunCannotPoisonANewerValidRunInTheSameHotel(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult(false));
        $original = $this->fixture->submit($envelope);
        self::assertTrue($original['delivery']['readback_verified']);
        Db::name('online_daily_data')->where('sync_task_id', $original['delivery']['sync_task_id'])->where('data_type', 'business')->update(['amount' => 9999]);
        Db::name('ota_local_collector_tasks')->where('id', $this->fixture->task['id'])->update(['available_at' => '2000-01-01 00:00:00']);
        $pair = $this->fixture->pair;
        $lease = $this->fixture->service->nextTask($pair['device_public_id'], $pair['device_token'])['task'];
        self::assertSame(2, (int)$lease['attempt']);
        $new = $this->submitLease($lease, $this->fixture->businessResult(true));
        self::assertNotSame($original['delivery']['sync_task_id'], $new['delivery']['sync_task_id']);
        $replayed = $this->fixture->submit($envelope);
        self::assertSame('result_unknown', $replayed['status']);
        self::assertSame(1, $replayed['reconciliation']['attempt']);
        $row = Db::name('online_daily_data')->where('sync_task_id', $new['delivery']['sync_task_id'])->where('data_type', 'business')->find();
        self::assertIsArray($row);
        $rows = app(OtaLocalCollectorReadbackProofService::class)->projectRows([$row]);
        self::assertTrue($rows[0]['_local_collector_readback_proof']['readback_verified']);
        self::assertSame($new['delivery']['result_hash'], $rows[0]['_local_collector_readback_proof']['result_hash']);
    }

    public function testForeignScopeAndDifferentRunCannotBorrowTheOriginalReceipt(): void
    {
        $this->saveOriginal();
        $row = Db::name('online_daily_data')->where('data_type', 'business')->find();
        foreach (['tenant_id' => 13, 'system_hotel_id' => 102, 'platform' => 'ctrip', 'data_date' => '2026-09-02',
            'data_source_id' => 999, 'sync_task_id' => 999] as $key => $value) {
            $rows = app(OtaLocalCollectorReadbackProofService::class)->projectRows([array_replace($row, [$key => $value])]);
            self::assertFalse($rows[0]['_local_collector_readback_proof']['readback_verified'], $key);
        }
    }

    public function testChangedReceiptIdentityScopeOrMalformedProofCannotBePromoted(): void
    {
        [, $saved] = $this->saveOriginal();
        $request = json_decode((string)Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->value('request_json'), true);
        $key = array_key_first($request['result_delivery_receipts']);
        foreach (['attempt' => 2, 'result_id' => 'another-result', 'result_hash' => str_repeat('a', 64),
            'tenant_id' => 13, 'system_hotel_id' => 102, 'platform' => 'ctrip', 'business_date' => '2026-09-02',
            'platform_hotel_id' => 'ANOTHER-STORE', 'data_type' => 'another-type', 'row_ids' => [999],
            'rows_fingerprint' => str_repeat('b', 64)] as $field => $value) {
            $changed = $request;
            $changed['result_delivery_receipts'][$key][$field] = $value;
            Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->update(['request_json' => json_encode($changed)]);
            $fact = $this->businessFact();
            self::assertFalse($fact['source_trace']['readback_verified'], $field);
            self::assertFalse($fact['source_trace']['saved_success'], $field);
            self::assertNotEmpty($fact['source_trace']['failure_reasons'], $field);
        }
        $request['result_delivery_receipts'] = 'legacy-invalid-structure';
        Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->update(['request_json' => json_encode($request)]);
        $this->assertUnverified($this->businessFact(), 'original_receipt_missing');
    }

    public function testNativeRowsWithoutACurrentProofRemainVisibleButCannotClaimSuccess(): void
    {
        $this->saveOriginal();
        $rows = Db::name('online_daily_data')->where('data_type', 'business')->select()->toArray();
        $fact = (new OtaStandardEtlService())->buildDatasetFromRows($rows)['fact_ota_daily'][0];
        self::assertSame(688.5, $fact['revenue']);
        $this->assertUnverified($fact, 'original_receipt_missing');
    }

    public function testRawFinancialChangesCannotBorrowAnUnchangedAmountFingerprint(): void
    {
        [$envelope, $saved] = $this->saveOriginal();
        self::assertSame('ota_local_collector_content.v1', $saved['delivery']['content_fingerprint']['version']);
        self::assertContains('raw_data', $saved['delivery']['content_fingerprint']['fields']);
        $row = Db::name('online_daily_data')->where('data_type', 'business')->find();
        $task = Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->find();
        $original = json_decode($row['raw_data'], true);
        foreach (['settlement_amount', 'refund_amount', 'room_revenue', 'net_revenue', 'commission_amount'] as $field) {
            $raw = $original;
            $raw[$field] = 9999;
            Db::name('online_daily_data')->where('id', $row['id'])->update(['raw_data' => json_encode($raw)]);
            self::assertSame(688.5, (float)Db::name('online_daily_data')->where('id', $row['id'])->value('amount'));
            self::assertSame($saved['delivery']['rows_fingerprint'], app(OtaLocalCollectorReadbackProofService::class)->fingerprint($task, $saved['delivery']));
            $fact = $this->businessFact();
            self::assertSame(9999.0, (float)$fact[$field], $field . ' observation must stay present.');
            $this->assertUnverified($fact, 'original_row_content_changed');
            $replayed = $this->fixture->submit($envelope);
            self::assertSame('result_unknown', $replayed['status']);
            self::assertSame('original_row_content_changed', $replayed['reconciliation']['reason_code']);
        }
    }

    public function testSettlementAndRefundColumnsAreCoveredWithoutChangingTheLegacyHashFormat(): void
    {
        Db::execute('ALTER TABLE online_daily_data ADD COLUMN settlement_amount REAL');
        Db::execute('ALTER TABLE online_daily_data ADD COLUMN refund_amount REAL');
        [, $saved] = $this->saveOriginal();
        self::assertContains('settlement_amount', $saved['delivery']['content_fingerprint']['fields']);
        self::assertContains('refund_amount', $saved['delivery']['content_fingerprint']['fields']);
        $task = Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->find();
        foreach (['settlement_amount', 'refund_amount'] as $field) {
            Db::name('online_daily_data')->where('data_type', 'business')->update(['settlement_amount' => null, 'refund_amount' => null, $field => 9999]);
            self::assertSame($saved['delivery']['rows_fingerprint'], app(OtaLocalCollectorReadbackProofService::class)->fingerprint($task, $saved['delivery']));
            $fact = $this->businessFact();
            self::assertSame(688.5, $fact['revenue']);
            self::assertSame(9999.0, (float)$fact[$field]);
            $this->assertUnverified($fact, 'original_row_content_changed');
        }
    }

    public function testLegacyReceiptRetainsItsOriginalProofButDoesNotCertifyUncoveredContent(): void
    {
        [$envelope, $saved] = $this->saveOriginal();
        $request = json_decode((string)Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->value('request_json'), true);
        foreach ($request['result_delivery_receipts'] as &$receipt) unset($receipt['content_fingerprint']);
        unset($receipt);
        Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->update(['request_json' => json_encode($request)]);
        $before = $this->state();
        $replayed = $this->fixture->submit($envelope);
        self::assertTrue($replayed['reconciliation']['readback_verified'], 'The original covered-column hash is still valid.');
        self::assertFalse($replayed['reconciliation']['content_readback_verified']);
        self::assertSame($saved['delivery']['rows_fingerprint'], $replayed['delivery']['rows_fingerprint']);
        $this->assertUnverified($this->businessFact(), 'original_content_proof_missing');
        self::assertSame(688.5, $this->businessFact()['revenue']);
        self::assertSame($before, $this->state());
    }

    public function testSemanticAndTimeChangesCannotBorrowAnUnchangedMonetaryFingerprint(): void
    {
        [, $saved] = $this->saveOriginal();
        $available = Db::getTableInfo('online_daily_data', 'fields');
        self::assertSame(array_values(array_intersect(OtaStandardEtlService::SOURCE_ROW_FIELDS, $available)), $saved['delivery']['content_fingerprint']['fields']);
        $row = Db::name('online_daily_data')->where('data_type', 'business')->find();
        $task = Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->find();
        foreach (['compare_type' => 'competitor', 'snapshot_time' => '2026-09-02 12:00:00',
            'validation_status' => 'stale', 'validation_flags' => '["synthetic_changed_quality"]'] as $field => $value) {
            Db::name('online_daily_data')->where('id', $row['id'])->update([$field => $value]);
            self::assertSame($saved['delivery']['rows_fingerprint'], app(OtaLocalCollectorReadbackProofService::class)->fingerprint($task, $saved['delivery']));
            $proof = app(OtaLocalCollectorReadbackProofService::class)->verifyReceipt($task, $saved['delivery']);
            self::assertFalse($proof['readback_verified'], $field);
            self::assertSame('original_row_content_changed', $proof['reason_code'], $field);
            Db::name('online_daily_data')->where('id', $row['id'])->update([$field => $row[$field]]);
        }
        self::assertTrue($this->businessFact()['source_trace']['saved_success']);
    }

    public function testScheduledPlanReceiptCannotRemainReadyAfterTheOriginalRowsChange(): void
    {
        $taskId = (int)$this->fixture->task['id'];
        $dispatcher = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $request = json_decode((string)Db::name('ota_local_collector_tasks')->where('id', $taskId)->value('request_json'), true);
        $request['dispatcher_run_id'] = $dispatcher;
        $request['trigger_type'] = 'scheduler';
        Db::name('ota_local_collector_tasks')->where('id', $taskId)->update(['request_json' => json_encode($request)]);
        [, $saved] = $this->saveOriginal();
        $read = function () use ($saved, $dispatcher): array {
            $task = Db::name('ota_local_collector_tasks')->where('id', $saved['task_id'])->find();
            $source = ['id' => $saved['delivery']['data_source_id'], 'system_hotel_id' => 101, 'platform' => 'meituan'];
            $device = ['status' => 'active', 'last_seen_at' => date('Y-m-d H:i:s')];
            return (new \ReflectionMethod($this->fixture->service, 'scheduledPlanTaskReceipt'))
                ->invoke($this->fixture->service, $task, $source, $device, $dispatcher, '2026-09-01', false);
        };
        self::assertSame('ready', $read()['historical_core_contract_status']);
        self::assertTrue($read()['readback_verified']);
        $before = (string)Db::name('ota_local_collector_tasks')->where('id', $taskId)->value('result_summary_json');
        Db::name('online_daily_data')->where('data_type', 'business')->update(['amount' => 9999]);
        $unknown = $read();
        self::assertFalse($unknown['readback_verified']);
        self::assertFalse($unknown['success']);
        self::assertSame('blocked', $unknown['historical_core_contract_status']);
        self::assertSame('original_row_values_changed', $unknown['failure_reason']);
        self::assertSame($before, (string)Db::name('ota_local_collector_tasks')->where('id', $taskId)->value('result_summary_json'));
    }

    private function assertUnverified(array $fact, string $reason): void
    {
        self::assertFalse($fact['source_trace']['readback_verified']);
        self::assertFalse($fact['source_trace']['saved_success']);
        self::assertContains($reason, $fact['source_trace']['failure_reasons']);
    }

    private function saveOriginal(): array
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult(true));
        $saved = $this->fixture->submit($envelope);
        self::assertSame('success', $saved['status']);
        self::assertTrue($saved['delivery']['readback_verified']);
        return [$envelope, $saved];
    }

    private function filters(int $hotelId = 101): array
    {
        return ['system_hotel_id' => $hotelId, 'source' => 'meituan', 'start_date' => '2026-09-01', 'end_date' => '2026-09-01'];
    }

    private function businessFact(int $hotelId = 101, ?OtaStandardEtlService $etl = null): array
    {
        return ($etl ?? new OtaStandardEtlService())->buildDataset($this->filters($hotelId))['fact_ota_daily'][0];
    }

    private function state(): array
    {
        return [Db::name('online_daily_data')->order('id')->select()->toArray(),
            Db::name('ota_local_collector_tasks')->field('id,attempt,status,request_json,result_summary_json')->order('id')->select()->toArray()];
    }

    private function saveNewRun(int $hotelId): array
    {
        $actor = new class {
            public int $id = 7;
            public int $tenant_id = 12;
            public function getPermittedHotelIds(): array { return [101, 102]; }
            public function isSuperAdmin(): bool { return false; }
        };
        $accountId = (int)$this->fixture->task['account_id'];
        $store = OtaLocalCollectorRealImportFixture::PLATFORM_HOTEL_ID;
        if ($hotelId !== 101) {
            Db::name('hotels')->insert(['id' => $hotelId, 'tenant_id' => 12, 'name' => 'SYNTHETIC Other Hotel', 'status' => 1]);
            Db::name('user_hotel_permissions')->insert(['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => $hotelId,
                'status' => 'active', 'can_view' => 1, 'can_fetch_online_data' => 1, 'expires_at' => null]);
            $store = 'SYNTHETIC-MT-' . $hotelId;
            $account = $this->fixture->service->createAccount($actor, ['device_id' => $this->fixture->pair['device_id'],
                'platform' => 'meituan', 'account_alias' => 'SYNTHETIC other account', 'system_hotel_id' => $hotelId, 'platform_hotel_id' => $store]);
            $accountId = (int)$account['account_id'];
            Db::name('ota_local_collector_accounts')->where('id', $accountId)->update(['status' => 'active',
                'session_status' => 'current_session_verified', 'last_session_verified_at' => date('Y-m-d H:i:s')]);
        }
        $this->fixture->service->createTask($actor, ['account_id' => $accountId, 'system_hotel_id' => $hotelId,
            'task_type' => 'collect', 'data_date' => '2026-09-01', 'force' => true]);
        $pair = $this->fixture->pair;
        $lease = $this->fixture->service->nextTask($pair['device_public_id'], $pair['device_token'])['task'];
        self::assertIsArray($lease);
        $result = $this->fixture->businessResult(true);
        foreach ($result['rows'] as &$row) {
            $row['system_hotel_id'] = $hotelId;
            $row['platform_hotel_id'] = $row['hotel_id'] = $store;
        }
        unset($row);
        $result['capture_summary']['platform_identity_validation']['validated_identifier'] = $store;
        return $this->submitLease($lease, $result);
    }

    private function submitLease(array $lease, array $result): array
    {
        $pair = $this->fixture->pair;
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $identity = ['result_id' => 'synthetic-new-proof-' . bin2hex(random_bytes(8)), 'result_hash' => hash('sha256', $json), 'attempt' => (int)$lease['attempt']];
        $upload = $this->fixture->service->resumeResultUpload($pair['device_public_id'], $pair['device_token'], (int)$lease['id'], $identity);
        $saved = $this->fixture->service->submitTaskResult($pair['device_public_id'], $pair['device_token'], (int)$lease['id'],
            $identity + ['result_json' => $json, 'lease_token' => $upload['lease_token']]);
        self::assertSame('success', $saved['status']);
        return $saved;
    }
}
