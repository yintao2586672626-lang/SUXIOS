<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Fixed observation slots, never a proxy for all-hotel demand or an automatic pricing action. */
final class BookingMonitoringService
{
    public const TABLE = 'hotel_room_type_on_books_snapshots';
    public const CONTRACT = 'booking_fixed_baseline_monitor.v1';
    public const SNAPSHOT_CONTRACT = 'room_type_on_books_snapshot.v1';
    private const MAX_CELLS = 1000;
    private const CONTENT_FIELDS = [
        'contract_version', 'tenant_id', 'hotel_id', 'source_hotel_id', 'platform', 'fact_scope',
        'stay_date', 'captured_at', 'source_method', 'source_ref_hash', 'on_books_room_nights',
        'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights',
        'quality_status', 'readback_verified', 'room_type_id', 'room_type_name', 'supersedes_snapshot_id',
    ];
    private $clock;
    private BookingDemandPlanningService $planning;

    public function __construct(?callable $clock = null, ?callable $transactionRunner = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $this->planning = new BookingDemandPlanningService($this->clock, $transactionRunner);
    }

    /** User submissions remain manual/unverified; confirmation is never platform source verification. */
    public function saveSnapshots(int $tenantId, array $permittedHotelIds, array $rows, int $actorId): array
    {
        if ($tenantId <= 0) throw new InvalidArgumentException('booking_monitor_tenant_required');
        if ($actorId <= 0) throw new InvalidArgumentException('booking_monitor_actor_required');
        if ($rows === [] || count($rows) > 200) throw new InvalidArgumentException('booking_monitor_import_requires_1_to_200_rows');
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('booking_monitor_row_invalid');
            $row['hotel_id'] = $this->roomId($row['hotel_id'] ?? 0);
            $row['source_method'] = ($row['source_method'] ?? '') === 'manual_entry' ? 'manual_entry' : 'manual_file_import';
            $row['quality_status'] = filter_var($row['operator_attested'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'manual_confirmed' : 'unverified';
            $normalizedRows[] = $row;
        }
        $contents = $this->planning->validatedSnapshotBatchContent($tenantId, $permittedHotelIds, $normalizedRows);
        $prepared = [];
        $keys = [];
        $rooms = [];
        $superseded = [];
        foreach ($normalizedRows as $index => $row) {
            $hotelId = $row['hotel_id'];
            $content = $contents[$index];
            $roomId = $this->roomId($row['room_type_id'] ?? 0);
            $supersedes = $this->roomId($row['supersedes_snapshot_id'] ?? 0);
            $old = null;
            if ($supersedes > 0) {
                // The batch already authorized this hotel; cache only the digest-verified immutable original.
                if (!isset($superseded[$hotelId][$supersedes])) {
                    $stored = Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('id', $supersedes)->find();
                    if (!$stored) throw new RuntimeException('booking_monitor_snapshot_not_found', 404);
                    $superseded[$hotelId][$supersedes] = $this->hydrate($stored);
                }
                $old = $superseded[$hotelId][$supersedes];
                foreach (['platform', 'stay_date', 'captured_at', 'fact_scope'] as $field) {
                    if ($old[$field] !== $content[$field]) throw new InvalidArgumentException('booking_monitor_correction_scope_mismatch');
                }
                if ($old['room_type_id'] !== $roomId) throw new InvalidArgumentException('booking_monitor_correction_room_type_mismatch');
            }
            $content['contract_version'] = self::SNAPSHOT_CONTRACT;
            $content['room_type_id'] = $roomId;
            $content['supersedes_snapshot_id'] = $supersedes > 0 ? $supersedes : null;
            $key = trim((string)($row['idempotency_key'] ?? ''));
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) throw new InvalidArgumentException('booking_monitor_idempotency_key_invalid');
            // An original receipt keeps its saved room name even after catalogue edits.
            // Scope authorization and all submitted facts were validated above.
            $replay = $this->findSubmittedReplay($content, $key);
            $roomName = $replay['room_type_name'] ?? '酒店汇总';
            if ($replay === null && $roomId > 0) {
                if ($old !== null) $roomName = $old['room_type_name'];
                else {
                    if (!array_key_exists($roomId, $rooms[$hotelId] ?? [])) {
                        $rooms[$hotelId][$roomId] = Db::name('room_types')->where('hotel_id', $hotelId)->where('id', $roomId)->where('is_enabled', 1)->field('id,name')->find();
                    }
                    $room = $rooms[$hotelId][$roomId];
                    if (!$room) throw new RuntimeException('booking_monitor_room_type_outside_hotel', 403);
                    $roomName = (string)$room['name'];
                }
            }
            $content['room_type_name'] = $roomName;
            $digest = $this->digest($content);
            if ($key === '') $key = $digest;
            $key = hash('sha256', $key);
            $scopeKey = $hotelId . '|' . $key;
            if (isset($keys[$scopeKey])) throw new InvalidArgumentException('booking_monitor_import_duplicate_key');
            $keys[$scopeKey] = true;
            $prepared[] = ['content' => $content, 'content_digest' => $digest, 'idempotency_key' => $key];
        }
        $transaction = function () use ($prepared, $actorId): array {
            $saved = [];
            foreach ($prepared as $item) {
                $content = $item['content'];
                $existing = Db::name(self::TABLE)->where('tenant_id', $content['tenant_id'])->where('hotel_id', $content['hotel_id'])
                    ->where('idempotency_key', $item['idempotency_key'])->lock(true)->find();
                if ($existing) {
                    $saved[] = $this->verifiedReplay($existing, $item['content_digest']);
                    continue;
                }
                $id = (int)Db::name(self::TABLE)->insertGetId($content + [
                    'content_digest' => $item['content_digest'], 'idempotency_key' => $item['idempotency_key'],
                    'created_by' => $actorId, 'created_at' => $this->now()->format('Y-m-d H:i:s.u'),
                ]);
                $stored = Db::name(self::TABLE)->where('id', $id)->where('tenant_id', $content['tenant_id'])->where('hotel_id', $content['hotel_id'])->find();
                if (!$stored) throw new RuntimeException('booking_monitor_readback_missing');
                $receipt = $this->hydrate($stored);
                if (!hash_equals($receipt['content_digest'], $item['content_digest'])) throw new RuntimeException('booking_monitor_readback_mismatch');
                $saved[] = $receipt + ['idempotent' => false];
            }
            return $saved;
        };
        $saved = $this->planning->runIdempotentWrite($transaction,
            fn(): ?array => $this->findBatchReplay($prepared), static fn(array $receipts): array => $receipts);
        return ['contract_version' => self::CONTRACT, 'tenant_id' => $tenantId, 'save_status' => 'saved_readback_verified',
            'readback_verified' => true, 'row_count' => count($saved), 'snapshots' => $saved, 'external_write_count' => 0];
    }

    public function readSnapshot(int $tenantId, array $permittedHotelIds, int $hotelId, int $id): array
    {
        $this->authorizedHotels($tenantId, $permittedHotelIds, [$hotelId]);
        $row = Db::name(self::TABLE)->where('tenant_id', $tenantId)->where('hotel_id', $hotelId)->where('id', $id)->find();
        if (!$row) throw new RuntimeException('booking_monitor_snapshot_not_found', 404);
        return $this->hydrate($row);
    }

    public function overview(int $tenantId, array $permittedHotelIds, array $hotelIds, array $input): array
    {
        $hotels = $this->authorizedHotels($tenantId, $permittedHotelIds, $hotelIds);
        $platform = trim((string)($input['platform'] ?? 'ctrip'));
        if (!in_array($platform, ['ctrip', 'meituan', 'dingdandao_pms', 'manual_all_channels'], true)) throw new InvalidArgumentException('booking_monitor_platform_invalid');
        $date = $this->date((string)($input['business_date'] ?? $this->now()->format('Y-m-d')));
        $fixedTime = trim((string)($input['fixed_time'] ?? '09:00'));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $fixedTime)) throw new InvalidArgumentException('booking_monitor_fixed_time_invalid');
        $horizonInput = $input['horizon_days'] ?? 7;
        if (!is_int($horizonInput) && !is_string($horizonInput)) throw new InvalidArgumentException('booking_monitor_horizon_invalid');
        $horizon = filter_var($horizonInput, FILTER_VALIDATE_INT);
        if ($horizon === false || $horizon < 1 || $horizon > 30) throw new InvalidArgumentException('booking_monitor_horizon_invalid');
        $anchor = new DateTimeImmutable($date . ' ' . $fixedTime . ':00', new DateTimeZone('Asia/Shanghai'));
        if ($date > $this->now()->format('Y-m-d')) throw new InvalidArgumentException('booking_monitor_business_date_future');
        $start = $anchor->modify('+1 day')->format('Y-m-d');
        $end = $anchor->modify('+' . $horizon . ' days')->format('Y-m-d');
        $historyStart = $anchor->modify('-27 days')->format('Y-m-d');
        $maxDimensions = intdiv(self::MAX_CELLS, $horizon);
        // Include one overflow sentinel, without materializing an unbounded catalogue.
        $roomTypes = Db::name('room_types')->whereIn('hotel_id', array_keys($hotels))->where('is_enabled', 1)->field('id,hotel_id,name')
            ->order('id', 'asc')->limit($maxDimensions - count($hotels) + 1)->select()->toArray();
        $dimensions = [];
        foreach ($hotels as $hotelId => $hotel) $dimensions[$hotelId] = [0 => '酒店汇总（不拆分旧数据）'];
        foreach ($roomTypes as $room) $dimensions[(int)$room['hotel_id']][(int)$room['id']] = (string)$room['name'];
        $this->assertCellLimit($dimensions, $horizon);
        $rows = Db::name(self::TABLE)->where('tenant_id', $tenantId)->whereIn('hotel_id', array_keys($hotels))->where('platform', $platform)
            ->whereBetween('stay_date', [$historyStart, $end])->where('captured_at', '<=', $this->now()->format('Y-m-d H:i:s.u'))
            ->order('captured_at', 'asc')->order('id', 'asc')->limit(10001)->select()->toArray();
        $legacy = Db::name(BookingDemandPlanningService::SNAPSHOT_TABLE)->where('tenant_id', $tenantId)->whereIn('hotel_id', array_keys($hotels))->where('platform', $platform)
            ->whereBetween('stay_date', [$historyStart, $end])->where('captured_at', '<=', $this->now()->format('Y-m-d H:i:s.u'))
            ->order('captured_at', 'asc')->order('id', 'asc')->limit(10001)->select()->toArray();
        if (count($rows) > 10000 || count($legacy) > 10000) throw new RuntimeException('booking_monitor_snapshot_limit_narrow_scope', 422);
        $groups = [];
        foreach ($legacy as $row) {
            $item = $this->planning->validatedSnapshotReadback($row);
            $item['room_type_id'] = 0;
            $item['evidence_ref'] = BookingDemandPlanningService::SNAPSHOT_TABLE . '#' . $item['id'];
            $item['_priority'] = 0;
            $groups[$item['hotel_id'] . '|0|' . $item['stay_date']][] = $item;
        }
        foreach ($rows as $row) {
            $item = $this->hydrate($row);
            $item['_priority'] = 1;
            $hotelId = (int)$item['hotel_id'];
            $roomId = (int)$item['room_type_id'];
            $dimensions[$hotelId][$roomId] ??= $item['room_type_name'] . '（历史房型）';
            $groups[$hotelId . '|' . $roomId . '|' . $item['stay_date']][] = $item;
        }
        // Disabled/deleted room types represented by immutable history consume the same budget.
        $this->assertCellLimit($dimensions, $horizon);
        $cells = [];
        foreach ($hotels as $hotelId => $hotel) {
            foreach ($dimensions[$hotelId] as $roomId => $roomName) {
                for ($lead = 1; $lead <= $horizon; $lead++) {
                    $stayDate = $anchor->modify('+' . $lead . ' days')->format('Y-m-d');
                    $cell = $this->compareSlots($tenantId, $hotelId, $platform, $stayDate, $anchor, $groups[$hotelId . '|' . $roomId . '|' . $stayDate] ?? []);
                    $history = [];
                    for ($week = 1; $week <= 4; $week++) {
                        $historyAnchor = $anchor->modify('-' . ($week * 7) . ' days');
                        $historyStayDate = $historyAnchor->modify('+' . $lead . ' days')->format('Y-m-d');
                        $slot = $this->selectSlot($groups[$hotelId . '|' . $roomId . '|' . $historyStayDate] ?? [], $historyAnchor);
                        $sameScope = $cell['current']['fact_scope'] !== null && $slot['fact_scope'] === $cell['current']['fact_scope'];
                        unset($slot['_row']);
                        $history[] = ['stay_date' => $historyStayDate, 'lead_time_days' => $lead, 'slot' => $slot,
                            'comparable' => $slot['status'] === 'ready' && $sameScope,
                            'observed_on_books_room_nights' => $slot['on_books_room_nights'],
                            'scope_status' => $sameScope ? 'same_scope' : ($cell['current']['fact_scope'] === null ? 'current_scope_missing' : 'scope_mismatch'),
                            'on_books_room_nights' => $slot['status'] === 'ready' && $sameScope ? $slot['on_books_room_nights'] : null];
                    }
                    $values = array_values(array_filter(array_column($history, 'on_books_room_nights'), static fn($value): bool => $value !== null));
                    sort($values, SORT_NUMERIC);
                    $count = count($values);
                    $median = $count < 2 ? null : ($count % 2 ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2);
                    $currentRooms = $cell['current']['status'] === 'ready' ? $cell['current']['on_books_room_nights'] : null;
                    $requirements = [];
                    foreach (['current' => $cell['current'], 'baseline' => $cell['baseline']] as $role => $slot) {
                        $requirements[] = ['role' => $role, 'stay_date' => $stayDate, 'target_time' => $slot['target_time'],
                            'status' => $slot['status'], 'evidence_ref' => $slot['evidence_ref']];
                    }
                    foreach ($history as $index => $item) {
                        $requirements[] = ['role' => 'history_week_' . ($index + 1), 'stay_date' => $item['stay_date'],
                            'target_time' => $item['slot']['target_time'], 'status' => $item['comparable'] ? 'ready'
                                : ($item['slot']['status'] === 'ready' ? $item['scope_status'] : $item['slot']['status']),
                            'evidence_ref' => $item['slot']['evidence_ref']];
                    }
                    unset($cell['current']['_row'], $cell['baseline']['_row']);
                    $cells[] = $cell + ['hotel_id' => $hotelId, 'hotel_name' => (string)$hotel['name'], 'room_type_id' => $roomId,
                        'room_type_name' => $roomName, 'stay_date' => $stayDate, 'lead_time_days' => $lead,
                        'history' => $history, 'history_coverage' => $count, 'history_status' => $count === 4 ? 'ready' : ($count > 0 ? 'partial' : 'missing'),
                        'same_lead_time_median_room_nights' => $median,
                        'delta_vs_same_lead_time_median' => $median !== null && $currentRooms !== null ? round($currentRooms - $median, 4) : null,
                        'baseline_readiness' => ['status' => $cell['status'] === 'ready' && $count === 4 ? 'ready' : 'incomplete',
                            'requirements' => $requirements,
                            'gaps' => array_values(array_filter($requirements, static fn(array $item): bool => $item['status'] !== 'ready'))]];
                }
            }
        }
        $readyCount = count(array_filter($cells, static fn(array $cell): bool => $cell['status'] === 'ready'));
        $completeBaselineCount = count(array_filter($cells, static fn(array $cell): bool => $cell['baseline_readiness']['status'] === 'ready'));
        $selectableHotels = Db::name('hotels')->where('tenant_id', $tenantId)->whereIn('id', array_map('intval', $permittedHotelIds))
            ->field('id,tenant_id,name')->order('id', 'asc')->select()->toArray();
        foreach ($selectableHotels as &$hotel) {
            $hotel['id'] = (int)$hotel['id'];
            $hotel['tenant_id'] = (int)$hotel['tenant_id'];
        }
        unset($hotel);
        return ['contract_version' => self::CONTRACT, 'tenant_id' => $tenantId, 'hotel_ids' => array_keys($hotels), 'selectable_hotels' => $selectableHotels, 'platform' => $platform,
            'business_date' => $date, 'fixed_time' => $fixedTime, 'timezone' => 'Asia/Shanghai', 'horizon_days' => $horizon,
            'observation_time' => $anchor->format('Y-m-d H:i:s'), 'baseline_time' => $anchor->modify('-1 day')->format('Y-m-d H:i:s'),
            'status' => $readyCount === count($cells) ? 'ready' : ($readyCount > 0 ? 'partial' : 'blocked'),
            'cells' => $cells, 'room_types' => $roomTypes, 'ready_cell_count' => $readyCount, 'cell_count' => count($cells),
            'baseline_readiness' => ['status' => $completeBaselineCount === count($cells) ? 'ready' : 'incomplete',
                'complete_cell_count' => $completeBaselineCount, 'cell_count' => count($cells), 'required_history_weeks' => 4],
            'meta' => ['schema_version' => self::CONTRACT, 'tenant_id' => $tenantId, 'hotel_ids' => array_keys($hotels), 'platform' => $platform,
                'business_date' => $date, 'source_method' => 'saved_snapshot_readback', 'collected_at' => $this->now()->format(DATE_ATOM)],
            'boundaries' => ['whole_hotel_demand_claimed' => false, 'automatic_pricing' => false, 'automatic_inventory_write' => false, 'external_write_count' => 0]];
    }

    public function compareSlots(int $tenantId, int $hotelId, string $platform, string $stayDate, DateTimeImmutable $anchor, array $rows): array
    {
        $current = $this->selectSlot($rows, $anchor);
        $previous = $this->selectSlot($rows, $anchor->modify('-1 day'));
        $result = ['status' => 'blocked', 'current' => $current, 'baseline' => $previous, 'net_pickup_24h_room_nights' => null,
            'gross_pickup_24h_room_nights' => null, 'room_revenue_delta_24h' => null, 'elapsed_hours' => null, 'data_gaps' => []];
        if ($anchor > $this->now()) {
            $result['data_gaps'][] = 'fixed_observation_time_not_reached';
            return $result;
        }
        if ($current['status'] !== 'ready' || $previous['status'] !== 'ready') {
            $result['data_gaps'] = ['current_slot_' . $current['status'], 'baseline_slot_' . $previous['status']];
            $result['status'] = in_array('stale', [$current['status'], $previous['status']], true) ? 'stale' : 'partial';
            if ($current['status'] === 'missing' && $previous['status'] === 'missing') $result['status'] = 'blocked';
            if (in_array('unverified', [$current['status'], $previous['status']], true)) $result['status'] = 'unverified';
            return $result;
        }
        $pair = [$previous['_row'], $current['_row']];
        $pace = $this->planning->summarizeSnapshots($tenantId, $hotelId, $platform, $stayDate, $pair);
        $result['data_gaps'] = $pace['data_gaps'];
        $result['elapsed_hours'] = $pace['elapsed_hours'];
        if ($pace['status'] !== 'ready' || $pace['elapsed_hours'] !== 24.0) {
            $result['status'] = 'not_comparable';
            $result['data_gaps'][] = 'exact_24h_same_scope_baseline_required';
            return $result;
        }
        $result['status'] = 'ready';
        $result['net_pickup_24h_room_nights'] = $pace['net_pickup_room_nights'];
        $result['gross_pickup_24h_room_nights'] = $pace['gross_pickup_room_nights'];
        $result['room_revenue_delta_24h'] = $pace['room_revenue_delta'];
        return $result;
    }

    private function selectSlot(array $rows, DateTimeImmutable $target): array
    {
        $base = ['status' => 'missing', 'target_time' => $target->format('Y-m-d H:i:s'), 'captured_at' => null,
            'evidence_ref' => null, 'quality_status' => null, 'source_method' => null, 'fact_scope' => null,
            'on_books_room_nights' => null, 'on_books_room_revenue' => null, 'lag_minutes' => null, '_row' => null];
        if ($target > $this->now()) return array_replace($base, ['status' => 'not_due']);
        usort($rows, static fn(array $a, array $b): int => [($a['captured_at'] ?? ''), ($a['_priority'] ?? 0), ($a['id'] ?? 0)] <=> [($b['captured_at'] ?? ''), ($b['_priority'] ?? 0), ($b['id'] ?? 0)]);
        $before = null;
        $after = null;
        foreach ($rows as $row) {
            $time = new DateTimeImmutable((string)$row['captured_at'], new DateTimeZone('Asia/Shanghai'));
            if ($time > $this->now()) continue;
            if ($time <= $target) $before = $row;
            elseif ($after === null || $row['captured_at'] === $after['captured_at']) $after = $row;
        }
        if ($before !== null && substr((string)$before['captured_at'], 0, 10) !== $target->format('Y-m-d')
            && $after !== null && substr((string)$after['captured_at'], 0, 10) === $target->format('Y-m-d')) $before = null;
        if ($before === null && $after !== null
            && substr((string)$after['captured_at'], 0, 10) !== $target->format('Y-m-d')) return $base;
        $row = $before ?? $after;
        if ($row === null) return $base;
        $time = new DateTimeImmutable((string)$row['captured_at'], new DateTimeZone('Asia/Shanghai'));
        $seconds = (float)$target->format('U.u') - (float)$time->format('U.u');
        $verified = in_array((string)$row['quality_status'], ['verified', 'manual_confirmed'], true) && (bool)$row['readback_verified'];
        $status = !$verified ? 'unverified' : ($seconds === 0.0 ? 'ready' : ($seconds < 0 ? 'late' : ($seconds <= 1800 ? 'approximate' : 'stale')));
        return ['status' => $status, 'target_time' => $base['target_time'], 'captured_at' => (string)$row['captured_at'],
            'evidence_ref' => $row['evidence_ref'], 'quality_status' => $row['quality_status'], 'source_method' => $row['source_method'],
            'fact_scope' => $row['fact_scope'], 'on_books_room_nights' => $row['on_books_room_nights'],
            'on_books_room_revenue' => $row['on_books_room_revenue'], 'lag_minutes' => round($seconds / 60, 4), '_row' => $row];
    }

    private function assertCellLimit(array $dimensions, int $horizon): void
    {
        if (array_sum(array_map('count', $dimensions)) * $horizon > self::MAX_CELLS) {
            throw new RuntimeException('booking_monitor_cell_limit_narrow_scope', 422);
        }
    }

    private function authorizedHotels(int $tenantId, array $permitted, array $ids): array
    {
        if ($tenantId <= 0 || $ids === [] || count($ids) > 20) throw new InvalidArgumentException('booking_monitor_requires_1_to_20_same_tenant_hotels');
        $ids = array_values(array_unique(array_map(fn(mixed $id): int => $this->roomId($id), $ids)));
        if (in_array(0, $ids, true) || array_diff($ids, array_map('intval', $permitted)) !== []) throw new RuntimeException('booking_monitor_hotel_outside_permitted_scope', 403);
        $rows = Db::name('hotels')->where('tenant_id', $tenantId)->whereIn('id', $ids)->field('id,tenant_id,name')->select()->toArray();
        if (count($rows) !== count($ids)) throw new RuntimeException('booking_monitor_hotel_tenant_scope_mismatch', 403);
        $result = [];
        foreach ($rows as $row) $result[(int)$row['id']] = $row;
        ksort($result);
        return $result;
    }

    /** Match immutable submitted facts before consulting the mutable room catalogue. */
    private function findSubmittedReplay(array $submitted, string $rawKey): ?array
    {
        $query = Db::name(self::TABLE)->where('tenant_id', $submitted['tenant_id'])->where('hotel_id', $submitted['hotel_id']);
        if ($rawKey !== '') {
            $row = $query->where('idempotency_key', hash('sha256', $rawKey))->find();
            if (!$row) return null;
            $receipt = $this->hydrate($row);
            foreach ($submitted as $field => $value) {
                if ($receipt[$field] !== $value) throw new RuntimeException('booking_monitor_idempotency_conflict', 409);
            }
            return $receipt;
        }
        // The existing scope index narrows this to one hotel/platform/room/stay/capture;
        // every submitted source, metric and quality field further limits candidates.
        foreach ($submitted as $field => $value) {
            if ($value === null) $query->whereNull($field);
            else $query->where($field, $value);
        }
        foreach ($query->order('id', 'asc')->cursor() as $row) {
            // Preserve the original auto-key algorithm, including the saved room name.
            // Identical facts saved with an explicit key are separate submissions.
            if (!hash_equals(hash('sha256', (string)$row['content_digest']), (string)$row['idempotency_key'])) continue;
            $receipt = $this->hydrate($row);
            foreach ($submitted as $field => $value) {
                if ($receipt[$field] !== $value) throw new RuntimeException('booking_monitor_idempotency_conflict', 409);
            }
            return $receipt;
        }
        return null;
    }

    /** Recover only a complete digest-verified batch; a partial winner requires retrying the atomic batch. */
    private function findBatchReplay(array $prepared): ?array
    {
        $receipts = [];
        $complete = true;
        foreach ($prepared as $item) {
            $content = $item['content'];
            $existing = Db::name(self::TABLE)->where('tenant_id', $content['tenant_id'])->where('hotel_id', $content['hotel_id'])
                ->where('idempotency_key', $item['idempotency_key'])->find();
            if (!$existing) { $complete = false; continue; }
            $receipts[] = $this->verifiedReplay($existing, $item['content_digest']);
        }
        return $complete ? $receipts : null;
    }

    private function verifiedReplay(array $row, string $contentDigest): array
    {
        $receipt = $this->hydrate($row);
        if (!hash_equals($receipt['content_digest'], $contentDigest)) throw new RuntimeException('booking_monitor_idempotency_conflict', 409);
        return $receipt + ['idempotent' => true];
    }

    private function hydrate(array $row): array
    {
        $content = [];
        foreach (self::CONTENT_FIELDS as $field) $content[$field] = $row[$field] ?? null;
        foreach (['tenant_id', 'hotel_id', 'source_hotel_id', 'readback_verified', 'room_type_id'] as $field) $content[$field] = (int)$content[$field];
        $content['supersedes_snapshot_id'] = $content['supersedes_snapshot_id'] === null ? null : (int)$content['supersedes_snapshot_id'];
        foreach (['on_books_room_nights', 'on_books_room_revenue', 'cumulative_cancel_room_nights', 'gross_booking_room_nights'] as $field) $content[$field] = $content[$field] === null ? null : (float)$content[$field];
        if (!hash_equals((string)$row['content_digest'], $this->digest($content))) throw new RuntimeException('booking_monitor_content_digest_mismatch');
        return $content + ['id' => (int)$row['id'], 'content_digest' => (string)$row['content_digest'],
            'idempotency_key' => (string)$row['idempotency_key'], 'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'], 'evidence_ref' => self::TABLE . '#' . $row['id'], 'external_write_count' => 0];
    }

    private function digest(array $content): string
    {
        unset($content['hotel_id']);
        ksort($content);
        return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function roomId(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) throw new InvalidArgumentException('booking_monitor_room_type_id_invalid');
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 0) throw new InvalidArgumentException('booking_monitor_room_type_id_invalid');
        return $id;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('booking_monitor_business_date_invalid');
        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'));
    }
}
