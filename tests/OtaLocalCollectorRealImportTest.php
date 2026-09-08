<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\OtaLocalCollectorRealImportFixture;
use think\facade\Db;

final class OtaLocalCollectorRealImportTest extends TestCase
{
    private OtaLocalCollectorRealImportFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new OtaLocalCollectorRealImportFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) $this->fixture->close();
    }

    public function testRealAdapterNormalizesSavesAndReadsBackTheExactSyntheticBusinessScope(): void
    {
        $response = $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        self::assertSame('accepted', $response['delivery']['status'] ?? null, $this->failureSummary($response));
        self::assertTrue($response['delivery']['readback_verified']);
        self::assertSame(1, $response['delivery']['saved_count']);
        $rows = Db::name('online_daily_data')->select()->toArray();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(12, (int)$row['tenant_id']);
        self::assertSame(101, (int)$row['system_hotel_id']);
        self::assertSame('meituan', $row['source']);
        self::assertSame('2026-09-01', $row['data_date']);
        self::assertSame('local_collector', $row['ingestion_method']);
        self::assertSame(688.5, (float)$row['amount']);
        self::assertSame(2, (int)$row['quantity']);
        self::assertSame(1, (int)$row['book_order_num']);
        self::assertSame(1, (int)$row['readback_verified']);
        self::assertSame((int)$response['delivery']['data_source_id'], (int)$row['data_source_id']);
        self::assertSame((int)$response['delivery']['sync_task_id'], (int)$row['sync_task_id']);
        self::assertSame(1, Db::name('platform_data_raw_records')->count(), 'The real adapter raw-record persistence must execute.');
        self::assertSame(1, Db::name('platform_data_sync_tasks')->count());
    }

    public function testSavedFieldGapCannotClaimCanonicalVerificationAndFinalizerRunsAfterCommit(): void
    {
        $response = $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        self::assertSame('accepted', $response['delivery']['status'] ?? null, $this->failureSummary($response));
        self::assertSame('field_gap', $response['delivery']['business_status']);
        self::assertTrue($response['delivery']['readback_verified']);
        self::assertNotSame('success', $response['status']);
        self::assertCount(1, $this->fixture->finalizationScopes);
        self::assertSame([
            'tenant_id' => 12, 'hotel_id' => 101, 'target_date' => '2026-09-01', 'committed_row_count' => 1,
        ], $this->fixture->finalizationScopes[0]);
        self::assertNotContains('ready', array_column($response['delivery']['verification']['platform_results'] ?? [], 'status'));
        $stored = json_decode((string)Db::name('ota_local_collector_tasks')->where('id', $response['task_id'])->value('result_summary_json'), true);
        self::assertSame(['synthetic_fixture_external_verification_not_run'], $stored['canonical_history']['blockers'] ?? []);
        self::assertFalse($stored['canonical_history']['canonical_history_complete']);
    }

    public function testRevokedHotelCollectionPermissionRejectsImportWithoutBusinessWrites(): void
    {
        Db::name('user_hotel_permissions')->where('user_id', 7)->update(['can_fetch_online_data' => 0]);
        $response = $this->fixture->submit($this->fixture->envelope($this->fixture->businessResult()));
        self::assertNotSame('accepted', $response['delivery']['status'] ?? null);
        self::assertSame(0, Db::name('online_daily_data')->count());
        self::assertSame(0, Db::name('platform_data_raw_records')->count());
        self::assertSame([], $this->fixture->finalizationScopes);
    }

    public function testLostAckResumeAndDuplicateResultDoNotRepeatRealBusinessImport(): void
    {
        $envelope = $this->fixture->envelope($this->fixture->businessResult());
        $first = $this->fixture->submit($envelope);
        self::assertSame('accepted', $first['delivery']['status'] ?? null, $this->failureSummary($first));
        $identity = array_intersect_key($envelope, array_flip(['result_id', 'result_hash', 'attempt']));
        $resumed = $this->fixture->resume($identity);
        $duplicate = $this->fixture->submit($envelope);
        self::assertSame($first['delivery']['result_hash'], $resumed['delivery']['result_hash']);
        self::assertSame($first['delivery']['result_hash'], $duplicate['delivery']['result_hash']);
        self::assertTrue($resumed['replayed']);
        self::assertTrue($duplicate['replayed']);
        self::assertSame(1, Db::name('online_daily_data')->count());
        self::assertSame(1, Db::name('platform_data_raw_records')->count());
        self::assertSame(1, Db::name('platform_data_sync_tasks')->count());
        self::assertCount(1, $this->fixture->finalizationScopes);
    }

    private function failureSummary(array $response): string
    {
        // Only synthetic status text; never include the fixture pairing or envelope lease.
        return json_encode(array_intersect_key($response, array_flip(['status', 'error_code', 'error_summary'])), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
