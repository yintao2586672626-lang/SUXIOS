<?php
declare(strict_types=1);
namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Immutable, scoped evidence. Database readback does not verify its source. */
final class OperatingEvidenceSnapshotStore
{
    public const TABLE = 'hotel_operating_evidence_snapshots';
    public function scope(int $tenant, array $permitted, int $hotel, string $month, string $platform, string $kind): array
    {
        if ($tenant <= 0 || $hotel <= 0 || !in_array($hotel, array_map('intval', $permitted), true)
            || !Db::name('hotels')->where('id', $hotel)->where('tenant_id', $tenant)->find()) {
            throw new RuntimeException('operating_evidence_hotel_forbidden', 403);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m') !== $month) throw new InvalidArgumentException('period_month_invalid');
        if (!in_array($kind, ['channel_economics', 'consumables_actual'], true)
            || !in_array($platform, ['ctrip', 'meituan', 'whole_hotel'], true)
            || ($kind === 'consumables_actual' && $platform !== 'whole_hotel')
            || ($kind === 'channel_economics' && $platform === 'whole_hotel')) {
            throw new InvalidArgumentException('operating_evidence_scope_invalid');
        }
        return ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'period_month' => $month, 'platform' => $platform, 'kind' => $kind];
    }
    public function replayRequest(array $scope, string $key, array $input): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) throw new InvalidArgumentException('operating_evidence_actor_or_request_invalid');
        $row = Db::name(self::TABLE)->where($scope)->where('idempotency_key', $key)->find();
        if (!$row) return null;
        $saved = $this->decode($row);
        // Older versions retain their original payload-digest replay contract.
        if (!isset($saved['request_digest'])) return null;
        if (!hash_equals((string)$saved['request_digest'], $this->requestDigest($input))) throw new RuntimeException('operating_evidence_idempotency_conflict',409);
        return $saved + ['idempotent'=>true];
    }
    public function save(array $scope, array $payload, string $key, int $actor, ?array $requestInput = null): array
    {
        if ($actor <= 0 || !preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) throw new InvalidArgumentException('operating_evidence_actor_or_request_invalid');
        $payload = ['contract_version' => 'operating_evidence.v1', 'scope' => $scope] + $payload;
        if ($requestInput !== null) $payload['request_digest'] = $this->requestDigest($requestInput);
        $json = $this->json($this->compactPayload($payload));
        // 100 accepted rows can include four-byte Unicode references and missing-item labels.
        $limit = $scope['kind'] === 'consumables_actual' ? 1000000 : 200000;
        if (strlen($json) > $limit) throw new InvalidArgumentException('operating_evidence_payload_too_large');
        $digest = hash('sha256', $json);
        $transaction = function () use ($scope, $payload, $json, $digest, $key, $actor): array {
            $existing = Db::name(self::TABLE)->where($scope)->where('idempotency_key', $key)->lock(true)->find();
            if ($existing) {
                return $this->verifiedReplay($existing, $payload, $digest);
            }
            $id = (int)Db::name(self::TABLE)->insertGetId($scope + [
                'source_hotel_id' => $scope['hotel_id'],
                'payload_json' => $json, 'content_digest' => $digest, 'idempotency_key' => $key,
                'created_by' => $actor, 'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
            ]);
            $saved = $this->read($scope, $id);
            if (!hash_equals($digest, $saved['content_digest'])) throw new RuntimeException('operating_evidence_readback_mismatch');
            return $saved + ['idempotent' => false];
        };
        return (new BookingDemandPlanningService())->runIdempotentWrite($transaction, function () use ($scope,$key,$payload,$digest): ?array {
            $row = Db::name(self::TABLE)->where($scope)->where('idempotency_key',$key)->find();
            if (!$row) return null;
            return $this->verifiedReplay($row, $payload, $digest);
        }, static fn(array $saved): array => $saved);
    }
    public function read(array $scope, int $id): array
    {
        $row = Db::name(self::TABLE)->where($scope)->where('id', $id)->find();
        if (!$row) throw new RuntimeException('operating_evidence_not_found', 404);
        return $this->decode($row);
    }
    public function latest(array $scope): array
    {
        $row = Db::name(self::TABLE)->where($scope)->order('id', 'desc')->find();
        return $row ? $this->decode($row) : ['status' => 'missing', 'scope' => $scope, 'readback_verified' => false];
    }
    public function history(array $scope): array
    {
        return array_map(fn(array $row): array => $this->decode($row), Db::name(self::TABLE)->where($scope)->order('id', 'desc')->limit(30)->select()->toArray());
    }
    private function decode(array $row): array
    {
        $json = (string)$row['payload_json'];
        if (!hash_equals((string)$row['content_digest'], hash('sha256', $json))) throw new RuntimeException('operating_evidence_integrity_failed', 409);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach (['tenant_id', 'hotel_id', 'kind', 'period_month', 'platform'] as $key) {
            $boundValue = $key === 'hotel_id' ? ($row['source_hotel_id'] ?? $row['hotel_id']) : $row[$key];
            if ((string)($payload['scope'][$key] ?? '') !== (string)$boundValue) throw new RuntimeException('operating_evidence_scope_mismatch', 409);
        }
        if (isset($payload['_storage_encoding'])) {
            if ($payload['_storage_encoding'] !== 'consumables_inputs_once.v1'
                || $payload['scope']['kind'] !== 'consumables_actual'
                || !is_array($payload['inputs']['items'] ?? null)
                || ($payload['result']['inputs'] ?? null) !== ['$ref'=>'inputs']
                || ($payload['result']['items'] ?? null) !== ['$ref'=>'inputs.items']) {
                throw new RuntimeException('operating_evidence_integrity_failed', 409);
            }
            $payload['result']['inputs'] = $payload['inputs'];
            $payload['result']['items'] = $payload['inputs']['items'];
            unset($payload['_storage_encoding']);
        }
        $currentScope = $payload['scope'];
        $currentScope['hotel_id'] = (int)$row['hotel_id'];
        return ['scope' => $currentScope, 'source_scope' => $payload['scope']] + $payload + ['snapshot_id' => (int)$row['id'], 'content_digest' => (string)$row['content_digest'],
            'created_at' => $row['created_at'], 'created_by' => (int)$row['created_by'], 'readback_verified' => true];
    }
    private function requestDigest(array $input): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value,SORT_STRING);
            return array_map($canonical,$value);
        };
        return hash('sha256',$this->json($canonical($input)));
    }
    private function verifiedReplay(array $row, array $payload, string $digest): array
    {
        $saved = $this->decode($row);
        if (!hash_equals((string)$row['content_digest'], $digest)) {
            unset($payload['request_digest']);
            if (isset($saved['request_digest']) || (!hash_equals((string)$row['content_digest'], hash('sha256', $this->json($payload)))
                && !hash_equals((string)$row['content_digest'], hash('sha256', $this->json($this->compactPayload($payload)))))) {
                throw new RuntimeException('operating_evidence_idempotency_conflict', 409);
            }
        }
        return $saved + ['idempotent' => true];
    }
    private function compactPayload(array $payload): array
    {
        if (($payload['scope']['kind'] ?? '') === 'consumables_actual'
            && is_array($payload['inputs']['items'] ?? null)
            && ($payload['result']['inputs'] ?? null) === $payload['inputs']
            && ($payload['result']['items'] ?? null) === $payload['inputs']['items']) {
            $payload['result']['inputs'] = ['$ref'=>'inputs'];
            $payload['result']['items'] = ['$ref'=>'inputs.items'];
            $payload['_storage_encoding'] = 'consumables_inputs_once.v1';
        }
        return $payload;
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
