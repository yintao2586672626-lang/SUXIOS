<?php
declare(strict_types=1);
namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Physical room inventory confirmed by staff; never inferred from a QR label or PMS sample. */
final class GuestRoomRegistryService
{
    public function save(int $tenant, array $ids, int $hotel, int $actor, array $input): array
    {
        $store = new GuestOperationRecordStore(); $tenant = $store->scope($tenant, $ids, $hotel, $actor);
        if (array_diff(array_keys($input), ['idempotency_key', 'rooms', 'confirmed_physical_rooms']) || ($input['confirmed_physical_rooms'] ?? false) !== true) throw new InvalidArgumentException('必须人工确认这些编号为本店真实房间');
        $rooms = $input['rooms'] ?? null;
        if (!is_array($rooms) || !array_is_list($rooms) || count($rooms) < 1 || count($rooms) > 100) throw new InvalidArgumentException('每批必须为1至100个房间');
        return Db::transaction(function () use ($store, $tenant, $hotel, $actor, $rooms): array {
            $saved = []; $seen = [];
            foreach ($rooms as $room) {
                if (!is_array($room) || array_diff(array_keys($room), ['room_number', 'active', 'expected_revision'])) throw new InvalidArgumentException('房间字段无效');
                $number = trim((string)($room['room_number'] ?? ''));
                if ($number === '' || mb_strlen($number) > 30 || preg_match('/[\x00-\x1f]/u', $number)) throw new InvalidArgumentException('房间编号必须为1至30个字符');
                $key = 'room:' . hash('sha256', $number);
                if (isset($seen[$key])) throw new InvalidArgumentException('同批房间编号重复'); $seen[$key] = true;
                $current = $store->latest($tenant, $hotel, 'room', $key);
                $active = $room['active'] ?? true;
                if (!is_bool($active)) throw new InvalidArgumentException('房间启用状态必须为布尔值');
                $document = ['room_number' => $number, 'active' => $active, 'source_method' => 'staff_confirmed_physical_room'];
                if ($current && $current['document'] === $document) { $saved[] = $current; continue; }
                $expected = $room['expected_revision'] ?? 0;
                if (!is_int($expected) || $expected < 0) throw new InvalidArgumentException('expected_revision 无效');
                $saved[] = $store->append($tenant, $hotel, $actor, 'room', $key, $expected, $document);
            }
            return $store->receipt($tenant, $hotel, $saved);
        });
    }
    public function list(int $tenant, int $hotel): array
    {
        $store = new GuestOperationRecordStore();
        return array_map(function (array $record) use ($tenant, $hotel): array {
            $original = Db::name('guest_operation_records')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', 'room')->where('record_key', $record['record_key'])->order('revision')->find();
            return $record + ['room_id' => (int)$original['id']];
        }, $store->current($tenant, $hotel, 'room'));
    }
    public function requireActive(int $tenant, int $hotel, int $roomId): array
    {
        $store = new GuestOperationRecordStore(); $original = $store->read($roomId);
        if ($original['tenant_id'] !== $tenant || $original['hotel_id'] !== $hotel || $original['kind'] !== 'room' || $original['revision'] !== 1) throw new RuntimeException('房间不属于当前租户酒店', 404);
        $current = $store->latest($tenant, $hotel, 'room', $original['record_key']);
        if (!$current || ($current['document']['active'] ?? false) !== true) throw new RuntimeException('房间已停用', 409);
        return $current + ['room_id' => $roomId];
    }
}
