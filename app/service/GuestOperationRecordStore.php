<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use RuntimeException;
use think\facade\Db;

/** Companion paths use the same immutable guest record/readback contract. */
final class GuestOperationRecordStore
{
    public function scope(int $tenant, array $ids, int $hotel, int $actor): int
    {
        if ($actor <= 0) throw new RuntimeException('未登录', 401);
        if (!in_array($hotel, array_map('intval', $ids), true)) throw new RuntimeException('guest_hotel_not_found', 404);
        $row = Db::name('hotels')->where('id', $hotel)->where('status', 1)->find();
        if (!$row || (int)$row['tenant_id'] <= 0 || ($tenant > 0 && $tenant !== (int)$row['tenant_id'])) throw new RuntimeException('guest_hotel_not_found', 404);
        return (int)$row['tenant_id'];
    }
    public function read(int $id): array
    {
        $row = Db::name('guest_operation_records')->where('id', $id)->find();
        if (!$row) throw new RuntimeException('guest_record_not_found', 404);
        $json = (string)$row['content_json'];
        if (!hash_equals((string)$row['content_digest'], hash('sha256', $json))) throw new RuntimeException('guest_readback_digest_drift', 409);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'revision', 'created_by'] as $field) {
            if ((int)($row[$field] ?? 0) !== ($payload[$field] ?? null)) throw new RuntimeException('guest_readback_scope_drift', 409);
        }
        $sourceHotel = (int)($row['source_hotel_id'] ?? $row['hotel_id']);
        if ($sourceHotel !== (int)($payload['hotel_id'] ?? 0) || $sourceHotel !== (int)($payload['source_hotel_id'] ?? $payload['hotel_id'] ?? 0)) throw new RuntimeException('guest_readback_source_scope_drift', 409);
        foreach (['kind', 'record_key', 'business_date', 'platform', 'created_at'] as $field) {
            if ($row[$field] !== ($payload[$field] ?? null)) throw new RuntimeException('guest_readback_scope_drift', 409);
        }
        if (($payload['contract_version'] ?? '') !== GuestOperationsService::VERSION) throw new RuntimeException('guest_contract_unsupported', 409);
        return array_replace($payload, [
            'hotel_id' => (int)$row['hotel_id'], 'source_hotel_id' => $sourceHotel,
            'scope' => ['tenant_id' => (int)$row['tenant_id'], 'hotel_id' => (int)$row['hotel_id']],
            'source_scope' => ['tenant_id' => (int)$payload['tenant_id'], 'hotel_id' => $sourceHotel],
            'id' => (int)$row['id'], 'content_digest' => $row['content_digest'], 'readback_verified' => true,
        ]);
    }
    public function latest(int $tenant, int $hotel, string $kind, string $key): ?array
    {
        $head = Db::name('guest_operation_heads')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', $kind)->where('record_key', $key)->find();
        if (!$head) return null;
        $row = $this->read((int)$head['record_id']);
        if ($row['tenant_id'] !== $tenant || $row['hotel_id'] !== $hotel || $row['kind'] !== $kind || $row['record_key'] !== $key || $row['revision'] !== (int)$head['revision']) throw new RuntimeException('guest_head_readback_drift', 409);
        return $row;
    }
    public function current(int $tenant, int $hotel, string $kind): array
    {
        $heads = Db::name('guest_operation_heads')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', $kind)->select()->toArray();
        return array_map(fn(array $head): array => $this->latest($tenant, $hotel, $kind, $head['record_key']), $heads);
    }
    public function append(int $tenant, int $hotel, int $actor, string $kind, string $key, int $expected, array $document, string $platform = 'manual', ?string $date = null): array
    {
        $current = $this->latest($tenant, $hotel, $kind, $key);
        if (($current['revision'] ?? 0) !== $expected) throw new RuntimeException('guest_revision_conflict，请刷新后重试', 409);
        $payload = ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'source_hotel_id' => $hotel, 'kind' => $kind, 'record_key' => $key, 'revision' => $expected + 1, 'business_date' => $date ?? substr($this->now(), 0, 10), 'platform' => $platform, 'created_by' => $actor, 'created_at' => $this->now(), 'document' => $document, 'contract_version' => GuestOperationsService::VERSION];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $id = (int)Db::name('guest_operation_records')->insertGetId(array_diff_key($payload, ['document' => true, 'contract_version' => true]) + ['content_json' => $json, 'content_digest' => hash('sha256', $json)]);
        $query = Db::name('guest_operation_heads')->where('tenant_id', $tenant)->where('hotel_id', $hotel)->where('kind', $kind)->where('record_key', $key);
        if ($expected === 0) $query->insert(['tenant_id' => $tenant, 'hotel_id' => $hotel, 'source_hotel_id' => $hotel, 'kind' => $kind, 'record_key' => $key, 'record_id' => $id, 'revision' => 1]);
        elseif ($query->where('revision', $expected)->update(['record_id' => $id, 'revision' => $expected + 1]) !== 1) throw new RuntimeException('guest_revision_conflict', 409);
        return $this->read($id);
    }
    public function receipt(int $tenant, int $hotel, array $records): array
    {
        return ['contract_version' => GuestOperationsService::VERSION, 'tenant_id' => $tenant, 'hotel_id' => $hotel, 'persistence_status' => 'readback_verified', 'records' => $records];
    }
    public function now(): string { return (new DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'); }
}
