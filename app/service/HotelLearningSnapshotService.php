<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Scoped learning snapshots reuse the immutable operating evidence store. */
final class HotelLearningSnapshotService
{
    private const DB_SCOPE_FIELDS = ['tenant_id', 'hotel_id', 'period_month', 'platform', 'kind'];
    private array $validatedScopes = [];
    private OperatingEvidenceSnapshotStore $store;

    public function __construct(?OperatingEvidenceSnapshotStore $store = null)
    {
        $this->store = $store ?? new OperatingEvidenceSnapshotStore();
    }

    public function scope(int $tenant, array $permitted, int $hotel, string $month, string $platform, string $mode): array
    {
        $allowed = [];
        foreach ($permitted as $id) {
            if (is_int($id) && $id > 0 || is_string($id) && preg_match('/^[1-9]\d*$/D', $id)) $allowed[] = (int)$id;
        }
        if ($tenant <= 0 || $hotel <= 0 || !in_array($hotel, $allowed, true)) {
            throw new RuntimeException('hotel_learning_hotel_forbidden', 403);
        }
        $this->assertHotel($tenant, $hotel);
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m') !== $month || (int)substr($month, 0, 4) < 1) {
            throw new InvalidArgumentException('hotel_learning_period_month_invalid');
        }
        if (!array_key_exists($mode, HotelLearningMechanismService::KINDS)
            || (in_array($mode, ['ota_scene', 'market_sample'], true)
                ? !in_array($platform, ['ctrip', 'meituan'], true) : $platform !== 'whole_hotel')) {
            throw new InvalidArgumentException('hotel_learning_mode_or_platform_invalid');
        }
        $scope = ['tenant_id' => $tenant, 'hotel_id' => $hotel, 'period_month' => $month,
            'platform' => $platform, 'kind' => HotelLearningMechanismService::KINDS[$mode], 'mode' => $mode];
        $this->validatedScopes[$this->key($scope)] = $scope;
        return $scope;
    }

    public function save(array $scope, array $result, string $idempotencyKey, int $actor): array
    {
        $dbScope = $this->validated($scope);
        if (!is_array($result['inputs'] ?? null) || !is_string($result['status'] ?? null)
            || trim($result['status']) === '' || strlen($result['status']) > 100
            || !is_string($result['source_quality'] ?? null) || trim($result['source_quality']) === ''
            || strlen($result['source_quality']) > 100) {
            throw new InvalidArgumentException('hotel_learning_result_invalid');
        }
        foreach (array_merge(self::DB_SCOPE_FIELDS, ['mode']) as $field) {
            if (array_key_exists($field, $result) && $result[$field] !== $scope[$field]) {
                throw new InvalidArgumentException('hotel_learning_result_scope_mismatch');
            }
        }
        if (array_key_exists('scope', $result) && $result['scope'] !== $scope) {
            throw new InvalidArgumentException('hotel_learning_result_scope_mismatch');
        }
        $payload = ['inputs' => $result['inputs'], 'result' => $result,
            'status' => $result['status'], 'source_quality' => $result['source_quality']];
        return $this->reply($this->store->save($dbScope, $payload, $idempotencyKey, $actor), $scope);
    }

    public function read(array $scope, int $id): array
    {
        $dbScope = $this->validated($scope);
        if ($id <= 0) throw new InvalidArgumentException('hotel_learning_snapshot_id_invalid');
        return $this->reply($this->store->read($dbScope, $id), $scope);
    }

    public function latest(array $scope): array
    {
        return $this->reply($this->store->latest($this->validated($scope)), $scope);
    }

    public function history(array $scope): array
    {
        $rows = $this->store->history($this->validated($scope));
        return array_map(fn(array $row): array => $this->reply($row, $scope), $rows);
    }

    private function validated(array $scope): array
    {
        $keys = array_keys($scope);
        $expected = array_merge(self::DB_SCOPE_FIELDS, ['mode']);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) throw new RuntimeException('hotel_learning_validated_scope_required', 403);
        $key = $this->key($scope);
        $known = $this->validatedScopes[$key] ?? null;
        if ($known === null) throw new RuntimeException('hotel_learning_validated_scope_required', 403);
        foreach ($expected as $field) {
            if ($scope[$field] !== $known[$field]) throw new RuntimeException('hotel_learning_validated_scope_required', 403);
        }
        $this->assertHotel($known['tenant_id'], $known['hotel_id']);
        $dbScope = [];
        foreach (self::DB_SCOPE_FIELDS as $field) $dbScope[$field] = $known[$field];
        return $dbScope;
    }

    private function assertHotel(int $tenant, int $hotel): void
    {
        if (!Db::name('hotels')->where('id', $hotel)->where('tenant_id', $tenant)->find()) {
            throw new RuntimeException('hotel_learning_hotel_forbidden', 403);
        }
    }

    private function key(array $scope): string
    {
        $parts = [];
        foreach (array_merge(self::DB_SCOPE_FIELDS, ['mode']) as $field) $parts[] = $scope[$field] ?? null;
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function reply(array $reply, array $scope): array
    {
        foreach (self::DB_SCOPE_FIELDS as $field) {
            if (($reply['scope'][$field] ?? null) !== $scope[$field]) throw new RuntimeException('hotel_learning_readback_scope_mismatch', 409);
        }
        $reply['scope']['mode'] = $scope['mode'];
        if (isset($reply['source_scope'])) $reply['source_scope']['mode'] = $scope['mode'];
        return $reply;
    }
}
