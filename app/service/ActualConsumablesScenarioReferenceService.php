<?php
declare(strict_types=1);
namespace app\service;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** A monthly manual cost reference can only bind the exact saved hotel version. */
final class ActualConsumablesScenarioReferenceService
{
    public function validate(int $tenant, int $hotel, array $input): array
    {
        $id = $input['cost_evidence_snapshot_id'] ?? null;
        $digest = $input['cost_evidence_digest'] ?? '';
        $confirmed = $input['cost_evidence_confirmed'] ?? false;
        if (($id === null || $id === '') && ($digest === null || $digest === '') && $confirmed === false) return $input;
        if (!is_scalar($id) || !preg_match('/^[1-9]\d{0,17}$/D', (string)$id) || !is_string($digest) || !preg_match('/^[a-f0-9]{64}$/iD', $digest) || $confirmed !== true) throw new InvalidArgumentException('实际耗材引用须提供完整快照与明确采用确认');
        $row = Db::name(OperatingEvidenceSnapshotStore::TABLE)->where('id',(int)$id)->where('tenant_id',$tenant)->where('hotel_id',$hotel)->where('kind','consumables_actual')->field('period_month')->find();
        if (!$row) throw new RuntimeException('实际耗材证据不存在或与当前项目酒店不一致',404);
        $store = new OperatingEvidenceSnapshotStore();
        $scope = $store->scope($tenant,[$hotel],$hotel,(string)$row['period_month'],'whole_hotel','consumables_actual');
        $saved = $store->read($scope,(int)$id);
        if (!hash_equals($saved['content_digest'],strtolower($digest))) throw new RuntimeException('实际耗材引用版本摘要不一致，请重新采用',409);
        if (($saved['source_quality'] ?? '') !== 'operator_attested' || ($saved['result']['status'] ?? '') !== 'calculated' || ($saved['result']['actual_consumables_cost_per_room_night'] ?? null) === null || trim((string)($saved['result']['inputs']['occupied_room_nights_source_ref'] ?? '')) === '') throw new RuntimeException('实际耗材证据尚未完整人工核对，不能采用',409);
        $dates = [];
        foreach ($saved['result']['items'] ?? [] as $entry) {
            if (!is_array($entry)) throw new RuntimeException('实际耗材证据计量项不完整，请重新核对',409);
            if (($entry['enabled'] ?? false) !== true) continue;
            $date = $entry['source_date'] ?? null;
            $parsed = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || substr($date,0,7) !== $scope['period_month']) throw new RuntimeException('实际耗材证据来源日期与核算月不一致，请重新核对',409);
            $dates[] = $date;
        }
        if ($dates === []) throw new RuntimeException('实际耗材证据计量项不完整，请重新核对',409);
        $cost = $input['consumables_cost'] ?? [];
        $items = array_values(array_filter($cost['items'] ?? [],static fn($r):bool=>is_array($r) && ($r['enabled'] ?? false) === true));
        if (($cost['mode'] ?? '') !== 'derived' || count($items) !== 1) throw new InvalidArgumentException('实际耗材引用必须保持独立月度成本计量项');
        $item = $items[0];
        if (($item['id'] ?? '') !== 'actual-evidence-'.$id || ($item['unit'] ?? '') !== 'piece' || ($item['usage_basis'] ?? '') !== 'occupied_room_night') throw new InvalidArgumentException('实际耗材引用计量项不一致');
        foreach (['package_quantity','usage_quantity','occurrences_per_occupied_night'] as $key) if (!is_numeric($item[$key] ?? null) || (float)$item[$key] !== 1.0) throw new InvalidArgumentException('实际耗材引用计量参数已修改，请解除引用后另建假设');
        if (!is_numeric($item['package_price'] ?? null) || !is_finite((float)$item['package_price']) || abs((float)$item['package_price']-(float)$saved['result']['actual_consumables_cost_per_room_night']) > 0.0000001) throw new RuntimeException('实际耗材采用值与保存版本不一致',409);
        sort($dates);
        foreach ($input['consumables_cost']['items'] as &$entry) if (($entry['id'] ?? '') === 'actual-evidence-'.$id) {
            $entry['source_label'] = '人工核对月度耗材证据 #'.$id.'；'.$scope['period_month'].'；测算引用';
            $entry['as_of'] = $dates ? end($dates) : '';
        }
        unset($entry);
        return $input;
    }
}
