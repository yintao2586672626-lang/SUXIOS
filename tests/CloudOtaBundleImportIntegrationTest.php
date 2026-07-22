<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\CloudOtaBundleCodec;
use app\service\CloudOtaBundleImportService;
use PHPUnit\Framework\TestCase;
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
            $bundle = CloudOtaBundleCodec::build([
                'source_system_hotel_id' => 987654,
                'destination_system_hotel_id' => (int)$hotel['id'],
                'target_date' => '2026-07-21',
                'required_platforms' => ['ctrip', 'meituan'],
            ], [
                $this->package('ctrip', 7001, $ctripSourceId, 'ctrip:bridge-integration'),
                $this->package('meituan', 7002, $meituanSourceId, 'meituan:bridge-integration'),
            ], '2026-07-22 10:00:00');

            $service = new CloudOtaBundleImportService();
            $first = $service->importBundle($bundle, (int)$actor->id, false);
            self::assertSame('succeeded', $first['status']);
            self::assertSame(2, $first['inserted_count']);
            self::assertSame(2, $first['readback_count']);
            self::assertTrue($first['readback_verified']);

            $second = $service->importBundle($bundle, (int)$actor->id, false);
            self::assertSame('succeeded', $second['status']);
            self::assertSame(0, $second['inserted_count']);
            self::assertSame(2, $second['updated_count']);
            self::assertSame(2, $second['readback_count']);
            self::assertTrue($second['readback_verified']);

            $persisted = Db::name('online_daily_data')
                ->whereIn('data_source_id', [$ctripSourceId, $meituanSourceId])
                ->where('system_hotel_id', (int)$hotel['id'])
                ->where('data_date', '2026-07-21')
                ->select()
                ->toArray();
            self::assertCount(2, $persisted);
            foreach ($persisted as $row) {
                self::assertSame(1, (int)$row['readback_verified']);
                self::assertStringStartsWith('bridge:', (string)$row['source_trace_id']);
                self::assertSame('cloud_bundle', (string)$row['ingestion_method']);
            }
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
