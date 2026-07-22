<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\CloudOtaBundleCodec;
use app\service\CloudOtaBundleImportService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Db;

final class CloudOtaBundleImportIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    public function testBundleImportIsReadBackVerifiedAndIdempotentInsideRollback(): void
    {
        $actor = $this->superAdmin();
        $hotel = Db::name('hotels')
            ->where('status', 1)
            ->where('tenant_id', '>', 0)
            ->order('id', 'asc')
            ->field('id,tenant_id')
            ->find();
        if (!$actor instanceof User || !is_array($hotel)) {
            self::markTestSkipped('An enabled super-admin and hotel are required for the transactional bridge integration test.');
        }

        Db::startTrans();
        try {
            $ctripSourceId = $this->createSource((int)$hotel['id'], (int)$hotel['tenant_id'], (int)$actor->id, 'ctrip');
            $meituanSourceId = $this->createSource((int)$hotel['id'], (int)$hotel['tenant_id'], (int)$actor->id, 'meituan');
            $ctripPackage = $this->package('ctrip', 7001, $ctripSourceId, 'ctrip:bridge-integration');
            $ctripPackage['rows'][] = array_merge($ctripPackage['rows'][0], [
                'hotel_id' => '100002',
                'hotel_name' => '同批次第二家竞品酒店',
            ]);
            $ctripPackage['source_row_count'] = 2;
            $bundle = CloudOtaBundleCodec::build([
                'source_system_hotel_id' => 987654,
                'destination_system_hotel_id' => (int)$hotel['id'],
                'target_date' => '2026-07-21',
                'required_platforms' => ['ctrip', 'meituan'],
            ], [
                $ctripPackage,
                $this->package('meituan', 7002, $meituanSourceId, 'meituan:bridge-integration'),
            ], '2026-07-22 10:00:00');

            $service = new CloudOtaBundleImportService();
            $first = $service->importBundle($bundle, (int)$actor->id, false);
            self::assertSame('succeeded', $first['status']);
            self::assertSame(3, $first['inserted_count']);
            self::assertSame(3, $first['readback_count']);
            self::assertTrue($first['readback_verified']);

            $second = $service->importBundle($bundle, (int)$actor->id, false);
            self::assertSame('succeeded', $second['status']);
            self::assertSame(0, $second['inserted_count']);
            self::assertSame(3, $second['updated_count']);
            self::assertSame(3, $second['readback_count']);
            self::assertTrue($second['readback_verified']);

            $persisted = Db::name('online_daily_data')
                ->whereIn('data_source_id', [$ctripSourceId, $meituanSourceId])
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_date', '2026-07-21')
                ->select()
                ->toArray();
            self::assertCount(3, $persisted);
            self::assertCount(3, array_unique(array_column($persisted, 'source_trace_id')));
            foreach ($persisted as $row) {
                self::assertSame(1, (int)$row['readback_verified']);
                self::assertStringStartsWith('bridge:', (string)$row['source_trace_id']);
                self::assertSame('cloud_bundle', (string)$row['ingestion_method']);
            }

            $incompleteCtrip = $this->package('ctrip', 7001, $ctripSourceId, 'ctrip:bridge-integration');
            $incompleteCtrip['collection']['last_sync_time'] = '2026-07-22 09:30:00';
            $incompleteCtrip['snapshot_complete'] = false;
            $incompleteCtrip['source_row_count'] = 2;
            $incompleteMeituan = $this->package('meituan', 7002, $meituanSourceId, 'meituan:bridge-integration');
            $incompleteMeituan['collection']['last_sync_time'] = '2026-07-22 09:30:00';
            $incompleteBundle = CloudOtaBundleCodec::build([
                'source_system_hotel_id' => 987654,
                'destination_system_hotel_id' => (int)$hotel['id'],
                'target_date' => '2026-07-21',
                'required_platforms' => ['ctrip', 'meituan'],
            ], [$incompleteCtrip, $incompleteMeituan], '2026-07-22 10:30:00');

            $incomplete = $service->importBundle($incompleteBundle, (int)$actor->id, false);
            self::assertSame('succeeded', $incomplete['status']);
            self::assertSame(0, $incomplete['retired_count']);
            self::assertSame(2, (int)Db::name('online_daily_data')
                ->where('data_source_id', $ctripSourceId)
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_date', '2026-07-21')
                ->where('readback_verified', 1)
                ->count());

            $correctedCtrip = $this->package('ctrip', 7001, $ctripSourceId, 'ctrip:bridge-integration');
            $correctedCtrip['collection']['last_sync_time'] = '2026-07-22 10:00:00';
            $correctedCtrip['rows'][0]['amount'] = 999.5;
            $correctedMeituan = $this->package('meituan', 7002, $meituanSourceId, 'meituan:bridge-integration');
            $correctedMeituan['collection']['last_sync_time'] = '2026-07-22 10:00:00';
            $correctedBundle = CloudOtaBundleCodec::build([
                'source_system_hotel_id' => 987654,
                'destination_system_hotel_id' => (int)$hotel['id'],
                'target_date' => '2026-07-21',
                'required_platforms' => ['ctrip', 'meituan'],
            ], [$correctedCtrip, $correctedMeituan], '2026-07-22 11:00:00');

            $corrected = $service->importBundle($correctedBundle, (int)$actor->id, false);
            self::assertSame('succeeded', $corrected['status']);
            self::assertSame(1, $corrected['retired_count']);

            $ctripRows = Db::name('online_daily_data')
                ->where('data_source_id', $ctripSourceId)
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_date', '2026-07-21')
                ->order('hotel_id', 'asc')
                ->select()
                ->toArray();
            self::assertCount(2, $ctripRows);
            self::assertSame(999.5, (float)$ctripRows[0]['amount']);
            self::assertSame(1, (int)$ctripRows[0]['readback_verified']);
            self::assertSame(0, (int)$ctripRows[1]['readback_verified']);
            self::assertSame('unverified', (string)$ctripRows[1]['validation_status']);
            self::assertStringContainsString(
                'cloud_bundle_row_absent_from_newer_verified_snapshot',
                (string)$ctripRows[1]['validation_flags']
            );

            try {
                $service->importBundle($bundle, (int)$actor->id, false);
                self::fail('An older bundle must not overwrite a newer verified snapshot.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('cloud_bundle_stale_package:ctrip', $exception->getMessage());
            }
            self::assertSame(999.5, (float)Db::name('online_daily_data')
                ->where('id', (int)$ctripRows[0]['id'])
                ->value('amount'));
        } finally {
            Db::rollback();
        }
    }

    private function superAdmin(): ?User
    {
        foreach (User::where('status', User::STATUS_ENABLED)->order('id', 'asc')->select() as $user) {
            if ($user instanceof User && $user->isSuperAdmin()) {
                return $user;
            }
        }
        return null;
    }

    private function createSource(int $hotelId, int $tenantId, int $actorId, string $platform): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'user_id' => $actorId,
            'name' => 'Cloud bridge integration ' . $platform,
            'platform' => $platform,
            'data_type' => 'business',
            'ingestion_method' => 'manual',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => '{}',
            'secret_json' => '{}',
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function package(string $platform, int $sourceId, int $destinationId, string $trace): array
    {
        return [
            'platform' => $platform,
            'source_data_source_id' => $sourceId,
            'destination_data_source_id' => $destinationId,
            'collection' => [
                'status' => 'success',
                'message' => 'target_date_rows_readback_verified',
                'last_sync_time' => '2026-07-22 09:00:00',
            ],
            'snapshot_complete' => true,
            'source_row_count' => 1,
            'rows' => [[
                'tenant_id' => 123,
                'system_hotel_id' => 987654,
                'data_source_id' => $sourceId,
                'hotel_id' => $platform === 'ctrip' ? '100001' : '200002',
                'hotel_name' => '桥接集成测试酒店',
                'data_date' => '2026-07-21',
                'source' => $platform,
                'platform' => $platform,
                'data_type' => 'business',
                'amount' => $platform === 'ctrip' ? 888.5 : 666.0,
                'quantity' => $platform === 'ctrip' ? 8 : 6,
                'book_order_num' => $platform === 'ctrip' ? 5 : 4,
                'validation_status' => 'normal',
                'validation_flags' => '[]',
                'source_trace_id' => $trace,
                'readback_verified' => 1,
                'readback_verified_at' => '2026-07-22 09:01:00',
            ]],
        ];
    }
}
