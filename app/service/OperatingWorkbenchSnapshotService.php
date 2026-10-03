<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Versioned extensions use the existing workspace schema, without changing old documents. */
final class OperatingWorkbenchSnapshotService
{
    public const CONTRACT = 'operating_workbench.v1';

    public function scope(int $tenant, array $permitted, int $hotel, string $kind): array
    {
        if ($tenant <= 0 || $hotel <= 0 || !in_array($hotel, array_map('intval', $permitted), true)
            || !Db::name('hotels')->where('id', $hotel)->where('tenant_id', $tenant)->find()) {
            throw new RuntimeException('workbench_forbidden', 403);
        }
        if (!preg_match('/^(?:budget_\d{4}-\d{2}|report_\d{4}-\d{2}-\d{2}|booking_\d{4}-\d{2}-\d{2}|appeal_[a-z0-9]{8,20})$/D', $kind)) {
            throw new InvalidArgumentException('workbench_kind_invalid');
        }
        return ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'owner_user_id' => 0, 'kind' => $kind];
    }

    public function latest(array $scope): ?array
    {
        $row = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->order('id', 'desc')->find();
        return $row ? $this->decode($row) : null;
    }

    public function read(array $scope, int $id): array
    {
        $row = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->where('id', $id)->find();
        if (!$row) throw new RuntimeException('workbench_not_found', 404);
        return $this->decode($row);
    }

    public function cases(int $tenant, int $hotel): array
    {
        $scope = ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'owner_user_id' => 0];
        $latest = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->whereLike('kind', 'appeal_%')
            ->field('MAX(id) AS latest_id')->group('kind')->order('latest_id', 'desc')->limit(501)->select()->toArray();
        if (count($latest) > 500) throw new RuntimeException('workbench_case_limit', 422);
        if ($latest === []) return [];
        $rows = Db::name(BusinessWorkspaceService::TABLE)->where($scope)
            ->whereIn('id', array_column($latest, 'latest_id'))->order('id', 'desc')->select()->toArray();
        return array_map(fn(array $row): array => $this->decode($row), $rows);
    }

    public function save(array $scope, array $inputs, int $actor, string $key, int $expected): array
    {
        if ($actor <= 0 || !preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) throw new InvalidArgumentException('workbench_request_invalid');
        $payload = ['contract_version' => self::CONTRACT, 'scope' => $scope, 'inputs' => $inputs, 'external_write_count' => 0];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (strlen($json) > 200000) throw new InvalidArgumentException('workbench_document_too_large');
        $digest = hash('sha256', $json);
        return Db::transaction(function () use ($scope, $actor, $key, $expected, $json, $digest): array {
            if (!Db::name('hotels')->where('id', $scope['hotel_id'])->where('tenant_id', $scope['tenant_id'])->lock(true)->find()) {
                throw new RuntimeException('workbench_forbidden', 403);
            }
            $existing = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->where('idempotency_key', $key)->lock(true)->find();
            if ($existing) {
                if ((int)$existing['created_by'] !== $actor || !hash_equals((string)$existing['content_digest'], $digest)) throw new RuntimeException('workbench_idempotency_conflict', 409);
                return $this->decode($existing) + ['idempotent' => true];
            }
            $latest = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->order('id', 'desc')->lock(true)->find();
            if ((int)($latest['id'] ?? 0) !== $expected) throw new RuntimeException('workbench_version_conflict', 409);
            $id = (int)Db::name(BusinessWorkspaceService::TABLE)->insertGetId($scope + [
                'source_hotel_id' => $scope['hotel_id'], 'previous_id' => $expected, 'idempotency_key' => $key,
                'payload_json' => $json, 'content_digest' => $digest, 'created_by' => $actor,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
            ]);
            $saved = $this->read($scope, $id);
            if (!hash_equals($digest, $saved['content_digest'])) throw new RuntimeException('workbench_readback_failed', 409);
            return $saved + ['idempotent' => false];
        });
    }

    public function reportReplay(array $scope, string $key, int $actor, string $requestDigest): ?array
    {
        $row = Db::name(BusinessWorkspaceService::TABLE)->where($scope)->where('idempotency_key', $key)->find();
        if (!$row) return null;
        $saved = $this->decode($row);
        if ($saved['created_by'] !== $actor || !hash_equals((string)($saved['inputs']['request_digest'] ?? ''), $requestDigest)) throw new RuntimeException('workbench_idempotency_conflict', 409);
        return $saved + ['idempotent' => true];
    }

    private function decode(array $row): array
    {
        if (!hash_equals((string)$row['content_digest'], hash('sha256', (string)$row['payload_json']))) throw new RuntimeException('workbench_integrity_failed', 409);
        $payload = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'owner_user_id', 'kind'] as $field) {
            if ((string)($payload['scope'][$field] ?? '') !== (string)$row[$field]) throw new RuntimeException('workbench_scope_mismatch', 409);
        }
        if ((int)($payload['scope']['hotel_id'] ?? 0) !== (int)$row['source_hotel_id']) throw new RuntimeException('workbench_scope_mismatch', 409);
        $sourceScope = $payload['scope'];
        $payload['scope']['hotel_id'] = (int)$row['hotel_id'];
        return $payload + ['source_scope' => $sourceScope, 'snapshot_id' => (int)$row['id'], 'previous_id' => (int)$row['previous_id'],
            'content_digest' => $row['content_digest'], 'created_at' => $row['created_at'], 'created_by' => (int)$row['created_by'], 'readback_verified' => true];
    }
}
