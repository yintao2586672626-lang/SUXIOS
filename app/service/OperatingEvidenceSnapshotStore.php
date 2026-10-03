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
        if (!preg_match('/^\d{4}-\d{2}$/D', $month)) throw new InvalidArgumentException('period_month_invalid');
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
        $this->verifyRequest($saved, $input);
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
        $transaction = function () use ($scope, $payload, $json, $digest, $key, $actor, $requestInput): array {
            $existing = Db::name(self::TABLE)->where($scope)->where('idempotency_key', $key)->lock(true)->find();
            if ($existing) {
                return $this->verifiedReplay($existing, $payload, $digest, $requestInput);
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
        return (new BookingDemandPlanningService())->runIdempotentWrite($transaction, function () use ($scope,$key,$payload,$digest,$requestInput): ?array {
            $row = Db::name(self::TABLE)->where($scope)->where('idempotency_key',$key)->find();
            if (!$row) return null;
            return $this->verifiedReplay($row, $payload, $digest, $requestInput);
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
    private function verifyRequest(array $saved, array $input): void
    {
        if (isset($saved['request_digest'])) {
            $matches = hash_equals((string)$saved['request_digest'], $this->requestDigest($input));
        } else {
            // Legacy rows retained normalized inputs, not the raw request. Compare only
            // recoverable inputs; never consult current sources or recalculate old facts.
            try {
                $stored = $saved['inputs'] ?? null;
                if (!is_array($stored)) throw new InvalidArgumentException('legacy_inputs_missing');
                $kind = $saved['scope']['kind'];
                $matches = hash_equals($this->requestDigest($this->legacyInputs($stored, $kind, $stored)),
                    $this->requestDigest($this->legacyInputs($input, $kind, $stored)));
            } catch (InvalidArgumentException $error) { $matches = false; }
        }
        if (!$matches) throw new RuntimeException('operating_evidence_idempotency_conflict', 409);
    }
    private function legacyInputs(array $input, string $kind, array $stored): array
    {
        if ($kind === 'channel_economics') {
            $numbers = ['net_revenue','advertising_spend','attributed_order_amount','effective_order_amount','refund_amount'];
            $flags = ['advertising_included_in_net_revenue','advertising_in_direct_costs','cost_coverage_complete','operator_attested'];
            $this->legacyKeys($input, array_merge($numbers,$flags,['attribution_basis','source_refs','evidence_refs_by_metric','costs']));
            $normalized = [];
            foreach ($numbers as $key) $normalized[$key] = $this->legacyNumber($input[$key] ?? null, $key === 'net_revenue');
            foreach ($flags as $key) {
                $value = $input[$key] ?? (in_array($key,['cost_coverage_complete','operator_attested'],true) ? false : null);
                if (!is_bool($value)) throw new InvalidArgumentException('legacy_boolean_invalid');
                $normalized[$key] = $value;
            }
            $normalized['attribution_basis'] = $this->legacyText($input['attribution_basis'] ?? '',200);
            $refs = $input['evidence_refs_by_metric'] ?? [];
            if (!is_array($refs)) throw new InvalidArgumentException('legacy_refs_invalid');
            $this->legacyKeys($refs,$numbers);
            foreach ($numbers as $key) $normalized['evidence_refs_by_metric'][$key] = $this->legacyRefs($refs[$key] ?? []);
            $normalized['source_refs'] = array_values(array_unique(array_merge($this->legacyRefs($input['source_refs'] ?? []), ...array_values($normalized['evidence_refs_by_metric']))));
            $rows = $input['costs'] ?? [];
            if (!is_array($rows) || count($rows) > 100) throw new InvalidArgumentException('legacy_rows_invalid');
            $normalized['costs'] = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !is_bool($row['included_in_net_revenue'] ?? null)) throw new InvalidArgumentException('legacy_cost_invalid');
                $this->legacyKeys($row,['label','amount','cost_type','included_in_net_revenue','source_ref']);
                $costType = $row['cost_type'] ?? 'direct';
                if (!in_array($costType,['direct','advertising'],true)) throw new InvalidArgumentException('legacy_cost_invalid');
                $normalized['costs'][] = ['label'=>$this->legacyText($row['label'] ?? '',160),'amount'=>$this->legacyNumber($row['amount'] ?? null),
                    'cost_type'=>$costType,'included_in_net_revenue'=>$row['included_in_net_revenue'],
                    'source_ref'=>$this->legacyText($row['source_ref'] ?? '',500)];
            }
            return $normalized;
        }
        $this->legacyKeys($input,['occupied_room_nights','occupied_room_nights_source_ref','denominator_scope','operator_attested','items']);
        if (!is_bool($input['operator_attested'] ?? false)) throw new InvalidArgumentException('legacy_boolean_invalid');
        $normalized = ['occupied_room_nights'=>$this->legacyNumber($input['occupied_room_nights'] ?? null),
            'occupied_room_nights_source_ref'=>$this->legacyText($input['occupied_room_nights_source_ref'] ?? '',500),
            'denominator_scope'=>$input['denominator_scope'] ?? '', 'operator_attested'=>$input['operator_attested'] ?? false, 'items'=>[]];
        $numbers = ['opening_quantity','purchased_quantity','transfer_in_quantity','closing_quantity','transfer_out_quantity','returned_quantity','written_off_quantity','unit_price','budget_unit_price','budget_usage_per_room_night'];
        $derived = ['consumed_quantity','consumed_cost','loss_cost','budget_cost_at_actual_volume','price_variance','usage_variance','total_variance','missing_items','status'];
        $rows = $input['items'] ?? [];
        if (!is_array($rows) || count($rows) > 100) throw new InvalidArgumentException('legacy_rows_invalid');
        foreach ($rows as $index=>$row) {
            if (!is_array($row) || !is_bool($row['enabled'] ?? null)) throw new InvalidArgumentException('legacy_item_invalid');
            $this->legacyKeys($row,array_merge($numbers,$derived,['id','name','enabled','unit','source_ref','source_date','valuation_method']));
            $reference = array_values($stored['items'] ?? [])[count($normalized['items'])] ?? [];
            foreach ($derived as $key) {
                if (array_key_exists($key,$row) && (!array_key_exists($key,$reference)
                    || $this->requestDigest([$row[$key]]) !== $this->requestDigest([$reference[$key]]))) throw new InvalidArgumentException('legacy_derived_input_changed');
            }
            $item = ['id'=>$this->legacyText($row['id'] ?? (string)$index,100),'name'=>$this->legacyText($row['name'] ?? '',160),
                'enabled'=>$row['enabled'],'unit'=>$row['unit'] ?? '', 'valuation_method'=>$row['valuation_method'] ?? 'confirmed_unit_cost',
                'source_ref'=>$this->legacyText($row['source_ref'] ?? '',500),'source_date'=>$this->legacyText($row['source_date'] ?? '',10)];
            foreach ($numbers as $key) $item[$key] = $this->legacyNumber($row[$key] ?? null);
            $normalized['items'][] = $item;
        }
        return $normalized;
    }
    private function legacyKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('legacy_unknown_input');
    }
    private function legacyNumber(mixed $value, bool $signed = false): ?float
    {
        if ($value === null || is_string($value) && trim($value) === '') return null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 1e12
            || !$signed && (float)$value < 0) throw new InvalidArgumentException('legacy_number_invalid');
        if (is_string($value) && (float)$value === 0.0 && strpbrk(preg_split('/[eE]/',trim($value))[0],'123456789') !== false) throw new InvalidArgumentException('legacy_number_invalid');
        return (float)$value == 0.0 ? 0.0 : (float)$value;
    }
    private function legacyText(mixed $value, int $limit): string
    {
        if (!is_scalar($value) || is_bool($value) || mb_strlen((string)$value) > $limit) throw new InvalidArgumentException('legacy_text_invalid');
        return trim((string)$value);
    }
    private function legacyRefs(mixed $refs): array
    {
        if (!is_array($refs) || count($refs) > 100) throw new InvalidArgumentException('legacy_refs_invalid');
        return array_values(array_unique(array_filter(array_map(fn($ref): string=>$this->legacyText($ref,500),$refs),static fn(string $ref): bool=>$ref !== '')));
    }
    private function verifiedReplay(array $row, array $payload, string $digest, ?array $requestInput = null): array
    {
        $saved = $this->decode($row);
        if ($requestInput !== null) {
            $this->verifyRequest($saved,$requestInput);
            return $saved + ['idempotent'=>true];
        }
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
