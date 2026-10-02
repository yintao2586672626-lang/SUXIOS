<?php
declare(strict_types=1);

namespace Tests;

use app\service\MeituanPublicPageEvidenceService;
use app\service\OtaPublicPageDiagnosisService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class MeituanPublicPageEvidenceChronologyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/']]], 'log');
        Config::set(['default' => 'meituan_chronology', 'connections' => ['meituan_chronology' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT)');
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => '合成本店'],
            ['id' => 20, 'tenant_id' => 2, 'name' => '合成其他店'],
        ]);
        $textColumns = ['source', 'platform', 'data_type', 'dimension', 'hotel_id', 'hotel_name', 'data_date',
            'compare_type', 'data_period', 'snapshot_bucket', 'validation_status', 'validation_flags',
            'source_method', 'source_url', 'source_trace_id', 'raw_data', 'amount', 'quantity', 'book_order_num',
            'comment_score', 'qunar_comment_score', 'data_value', 'update_time', 'create_time', 'readback_verified_at'];
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER,
            system_hotel_id INTEGER, readback_verified INTEGER, is_final INTEGER, '
            . implode(', ', array_map(static fn(string $column): string => $column . ' TEXT', $textColumns)) . ')');
    }

    private function observation(string $time = '2026-09-20 10:00:00', array $fields = ['rating' => 4.8]): array
    {
        return [
            'ota_hotel_id' => '1001', 'role' => 'self', 'business_date' => substr($time, 0, 10),
            'collected_at' => $time, 'source_url' => 'https://hotel.meituan.com/hotel/1001',
            'screenshot_ref' => 'synthetic-' . str_replace([' ', ':'], '-', $time), 'fields' => $fields,
            'evidence_paths' => array_combine(array_keys($fields), array_map(static fn(string $key): string => '公开页:' . $key, array_keys($fields))),
        ];
    }

    public function testOlderSameDaySaveIsRejectedWithoutChangingExactPersistedRow(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $first = $service->saveObservation(10, $this->observation(), 91);
        $before = Db::name('online_daily_data')->where('id', $first['profile']['snapshot_id'])->find();
        $stale = $this->observation('2026-09-20 09:00:00', ['rating' => 3.2, 'name' => '旧资料']);
        $stale['source_url'] = 'https://www.meituan.com/';
        $stale['evidence_paths']['rating'] = '旧截图:评分';
        try {
            $service->saveObservation(10, $stale, 92);
            self::fail('Older observation must not overwrite facts under the newer collection time.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('已有更晚采集时间', $exception->getMessage());
        }
        self::assertSame($before, Db::name('online_daily_data')->where('id', $first['profile']['snapshot_id'])->find());
        self::assertSame($first['profile'], $service->listDiagnosisProfiles(10, '2026-09-20')[0]);
        self::assertSame(4.8, $service->listProfiles(10)[0]['fields']['rating']);
    }

    public function testCorrectedTimeCanRecoverAndNewerPartialObservationKeepsEarlierFields(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $first = $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['name' => '合成本店', 'rating' => 4.8]), 91);
        $updated = $service->saveObservation(10, $this->observation('2026-09-20 11:00:00', ['rating' => 0]), 92);
        self::assertSame($first['profile']['snapshot_id'], $updated['profile']['snapshot_id']);
        self::assertSame(['name' => '合成本店', 'rating' => 0], $updated['profile']['fields']);
        self::assertTrue($updated['profile']['persistence_readback_verified']);
        self::assertSame('source_observed', $updated['profile']['source_validation_status']);
        self::assertSame('2026-09-20 11:00:00', $updated['profile']['collected_at']);
        self::assertSame('公开页:rating', $updated['profile']['evidence_paths']['rating']);
        self::assertSame($updated['profile'], $service->listDiagnosisProfiles(10, '2026-09-20')[0]);
    }

    public function testSameCollectionTimeAllowsCorrection(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $first = $service->saveObservation(10, $this->observation(), 91);
        $updated = $service->saveObservation(10, $this->observation('2026-09-20 10:00:00', ['rating' => 4.9]), 91);
        self::assertSame($first['profile']['snapshot_id'], $updated['profile']['snapshot_id']);
        self::assertSame(4.9, $updated['profile']['fields']['rating']);
    }

    public function testChronologyIsLimitedToTheSameHotelPoiDateAndRole(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $first = $service->saveObservation(10, $this->observation(), 91);
        $previousDay = $service->saveObservation(10, $this->observation('2026-09-19 09:00:00'), 91);
        $otherHotel = $service->saveObservation(20, $this->observation('2026-09-20 09:00:00'), 91);
        $competitorInput = $this->observation('2026-09-20 08:00:00');
        $competitorInput['role'] = 'competitor';
        $competitor = $service->saveObservation(10, $competitorInput, 91);
        $otherPoiInput = $this->observation('2026-09-20 07:00:00');
        $otherPoiInput['ota_hotel_id'] = '1002';
        $otherPoiInput['source_url'] = 'https://hotel.meituan.com/hotel/1002';
        $otherPoiInput['role'] = 'competitor';
        $otherPoi = $service->saveObservation(10, $otherPoiInput, 91);
        self::assertCount(5, array_unique(array_column(array_column([$first, $previousDay, $otherHotel, $competitor, $otherPoi], 'profile'), 'snapshot_id')));
        self::assertSame($first['profile'], $service->listDiagnosisProfiles(10, '2026-09-20')[0]);
        self::assertSame($previousDay['profile'], $service->listDiagnosisProfiles(10, '2026-09-19')[0]);
        self::assertSame($otherHotel['profile'], $service->listDiagnosisProfiles(20, '2026-09-20')[0]);
        $missing = (new OtaPublicPageDiagnosisService())->build(10, 'meituan', '2026-09-18', $service->listDiagnosisProfiles(10, '2026-09-18'));
        self::assertSame([], $missing['sources']);
        self::assertSame(0, $missing['evidence_coverage']['observed_field_count']);
    }

    public function testLegacyObservationWithoutCollectionTimeCanBeCompleted(): void
    {
        $service = new MeituanPublicPageEvidenceService();
        $first = $service->saveObservation(10, $this->observation(), 91);
        $row = Db::name('online_daily_data')->where('id', $first['profile']['snapshot_id'])->find();
        $legacy = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        unset($legacy['profile']['collected_at']);
        Db::name('online_daily_data')->where('id', $row['id'])->update(['raw_data' => json_encode($legacy, JSON_THROW_ON_ERROR)]);
        $recovered = $service->saveObservation(10, $this->observation('2026-09-20 09:00:00', ['rating' => 4.7]), 91);
        self::assertSame($first['profile']['snapshot_id'], $recovered['profile']['snapshot_id']);
        self::assertSame('2026-09-20 09:00:00', $recovered['profile']['collected_at']);
        self::assertSame(4.7, $recovered['profile']['fields']['rating']);
        self::assertTrue($recovered['profile']['persistence_readback_verified']);
    }
}
