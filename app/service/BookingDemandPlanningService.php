<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class BookingDemandPlanningService
{
    public const SNAPSHOT_TABLE = 'hotel_on_books_snapshots';
    public const EVENT_TABLE = 'hotel_demand_event_facts';
    public const SNAPSHOT_CONTRACT = 'hotel_on_books_snapshot.v1';
    public const OVERVIEW_CONTRACT = 'booking_pace_risk.v1';
    public const PLAN_CONTRACT = 'booking_demand_plan.v1';
    public const EVENT_CONTRACT = 'hotel_demand_event_fact.v1';
    public const CALENDAR_CONTRACT = 'hotel_demand_calendar.v1';

    private const PLATFORMS = ['ctrip', 'meituan', 'dingdandao_pms', 'manual_all_channels'];
    private const FACT_SCOPES = ['ota_channel', 'accommodation_room_fee', 'whole_hotel'];
    private const QUALITY_STATUSES = ['verified', 'manual_confirmed', 'partial', 'unverified', 'blocked'];
    private const EVENT_TYPES = ['holiday', 'exhibition', 'concert', 'exam', 'transport', 'weather', 'policy', 'other'];
    private const EVENT_SOURCE_STATUSES = ['verified_source', 'reference_only', 'unverified'];

    /** @var callable():DateTimeImmutable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Shanghai')
        );
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function saveOnBooksSnapshot(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        array $input,
        int $actorId
    ): array {
        $tenantId = $this->resolveScope($tenantId, $permittedHotelIds, $hotelId);
        if ($actorId <= 0) {
            throw new InvalidArgumentException('on_books_snapshot_actor_required');
        }
        $content = $this->normalizeSnapshot($tenantId, $hotelId, $input);
        $contentDigest = $this->contentDigest($content);
        $idempotencyKey = $this->idempotencyKey($input['idempotency_key'] ?? null);
        $now = $this->now();

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $actorId,
            $content,
            $contentDigest,
            $idempotencyKey,
            $now
        ): array {
            $existing = Db::name(self::SNAPSHOT_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('platform', $content['platform'])
                ->where('stay_date', $content['stay_date'])
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();
            if ($existing) {
                $saved = $this->hydrateSnapshot($existing);
                if (!hash_equals($saved['content_digest'], $contentDigest)) {
                    throw new RuntimeException('on_books_snapshot_idempotency_conflict', 409);
                }
                return $saved + ['idempotent' => true];
            }

            $id = (int)Db::name(self::SNAPSHOT_TABLE)->insertGetId([
                ...$content,
                'idempotency_key' => $idempotencyKey,
                'content_digest' => $contentDigest,
                'created_by' => $actorId,
                'created_at' => $now,
            ]);
            $saved = $this->readSnapshot($tenantId, $hotelId, $id);
            if (!hash_equals($saved['content_digest'], $contentDigest)) {
                throw new RuntimeException('on_books_snapshot_readback_mismatch');
            }
            return $saved + ['idempotent' => false];
        });
    }

    /** @return array<string,mixed> */
    public function readSnapshot(int $tenantId, int $hotelId, int $id): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $id <= 0) {
            throw new InvalidArgumentException('on_books_snapshot_scope_invalid');
        }
        $row = Db::name(self::SNAPSHOT_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!$row) {
            throw new RuntimeException('on_books_snapshot_not_found', 404);
        }
        return $this->hydrateSnapshot($row);
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function bookingOverview(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        string $platform,
        string $stayDate
    ): array {
        $tenantId = $this->resolveScope($tenantId, $permittedHotelIds, $hotelId);
        $platform = $this->enum($platform, self::PLATFORMS, 'on_books_snapshot_platform_invalid');
        $stayDate = $this->date($stayDate, 'stay_date');
        $rows = Db::name(self::SNAPSHOT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('stay_date', $stayDate)
            ->whereIn('quality_status', ['verified', 'manual_confirmed'])
            ->where('readback_verified', 1)
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->limit(2)
            ->select()
            ->toArray();

        return $this->summarizeSnapshots($tenantId, $hotelId, $platform, $stayDate, array_reverse($rows));
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function demandPlan(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        string $platform,
        string $businessDate
    ): array {
        $tenantId = $this->resolveScope($tenantId, $permittedHotelIds, $hotelId);
        $platform = $this->enum($platform, self::PLATFORMS, 'on_books_snapshot_platform_invalid');
        $businessDate = $this->date($businessDate, 'business_date');
        $anchor = new DateTimeImmutable($businessDate, new DateTimeZone('Asia/Shanghai'));
        $businessDayEnd = new DateTimeImmutable(
            $businessDate . ' 23:59:59.999999',
            new DateTimeZone('Asia/Shanghai')
        );
        $clockNow = $this->clockNow();
        $asOf = $clockNow < $businessDayEnd ? $clockNow : $businessDayEnd;
        $startDate = $anchor->modify('+1 day')->format('Y-m-d');
        $endDate = $anchor->modify('+7 days')->format('Y-m-d');

        $rows = Db::name(self::SNAPSHOT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('stay_date', '>=', $startDate)
            ->where('stay_date', '<=', $endDate)
            ->where('captured_at', '<=', $asOf->format('Y-m-d H:i:s.u'))
            ->whereIn('quality_status', ['verified', 'manual_confirmed'])
            ->where('readback_verified', 1)
            ->order('stay_date', 'asc')
            ->order('captured_at', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $rowsByDate = [];
        foreach ($rows as $row) {
            $date = (string)($row['stay_date'] ?? '');
            if ($date >= $startDate && $date <= $endDate) {
                $rowsByDate[$date][] = $row;
            }
        }

        $daily = [];
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = $anchor->modify('+' . $offset . ' days')->format('Y-m-d');
            $daily[] = $this->summarizeSnapshots(
                $tenantId,
                $hotelId,
                $platform,
                $stayDate,
                $rowsByDate[$stayDate] ?? []
            );
        }
        $calendar = $this->demandCalendar(
            $tenantId,
            $permittedHotelIds,
            $hotelId,
            $startDate,
            $endDate
        );
        $definitions = [
            ['tomorrow', '明天', 1],
            ['next_3_days', '未来3天', 3],
            ['next_7_days', '未来7天', 7],
        ];
        $windows = array_map(
            fn(array $definition): array => $this->summarizePlanWindow(
                $definition[0],
                $definition[1],
                array_slice($daily, 0, $definition[2]),
                (array)($calendar['events'] ?? [])
            ),
            $definitions
        );
        $sevenDayWindow = $windows[2];

        return [
            'contract_version' => self::PLAN_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'business_date' => $businessDate,
            'as_of_time' => $asOf->format('Y-m-d H:i:s.u'),
            'timezone' => 'Asia/Shanghai',
            'requested_horizons' => [1, 3, 7],
            'status' => (string)$sevenDayWindow['status'],
            'windows' => $windows,
            'automatic_pricing' => false,
            'automatic_inventory_write' => false,
            'causality_claimed' => false,
            'external_write_count' => 0,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function summarizeSnapshots(
        int $tenantId,
        int $hotelId,
        string $platform,
        string $stayDate,
        array $rows
    ): array {
        $base = [
            'contract_version' => self::OVERVIEW_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'stay_date' => $stayDate,
            'status' => 'blocked',
            'fact_scope' => null,
            'current_snapshot_ref' => null,
            'previous_snapshot_ref' => null,
            'current_captured_at' => null,
            'previous_captured_at' => null,
            'current_on_books_room_nights' => null,
            'current_on_books_room_revenue' => null,
            'current_cumulative_cancel_room_nights' => null,
            'current_gross_booking_room_nights' => null,
            'lead_time_days' => null,
            'elapsed_hours' => null,
            'net_pickup_room_nights' => null,
            'gross_pickup_room_nights' => null,
            'pickup_room_nights_per_hour' => null,
            'room_revenue_delta' => null,
            'room_revenue_per_hour' => null,
            'cancellation_rate_percent' => null,
            'risk_status' => 'not_classified_without_same_scope_baseline',
            'data_gaps' => [],
            'automatic_pricing' => false,
            'automatic_inventory_write' => false,
            'external_write_count' => 0,
        ];
        $eligible = [];
        foreach ($rows as $row) {
            if ((int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['hotel_id'] ?? 0) !== $hotelId
                || (string)($row['platform'] ?? '') !== $platform
                || (string)($row['stay_date'] ?? '') !== $stayDate
                || !in_array((string)($row['quality_status'] ?? ''), ['verified', 'manual_confirmed'], true)
                || (int)($row['readback_verified'] ?? 0) !== 1
            ) {
                continue;
            }
            $eligible[] = $row;
        }
        usort($eligible, static fn(array $a, array $b): int => strcmp(
            (string)($a['captured_at'] ?? ''),
            (string)($b['captured_at'] ?? '')
        ));
        if ($eligible === []) {
            $base['data_gaps'][] = 'verified_on_books_snapshot_missing';
            return $base;
        }

        $current = $eligible[array_key_last($eligible)];
        $currentTime = $this->time((string)($current['captured_at'] ?? ''), 'captured_at');
        $currentFactScope = strtolower(trim((string)($current['fact_scope'] ?? '')));
        $stayTime = $this->time($stayDate . ' 00:00:00', 'stay_date');
        $captureDate = $this->time($currentTime->format('Y-m-d') . ' 00:00:00', 'captured_at');
        $base['fact_scope'] = $currentFactScope !== '' ? $currentFactScope : null;
        $base['current_snapshot_ref'] = self::SNAPSHOT_TABLE . '#' . (int)($current['id'] ?? 0);
        $base['current_captured_at'] = $currentTime->format('Y-m-d H:i:s.u');
        $base['lead_time_days'] = (int)$captureDate->diff($stayTime)->format('%r%a');
        if ($base['lead_time_days'] < 0) {
            $base['status'] = 'rebaseline_required';
            $base['data_gaps'][] = 'on_books_snapshot_after_stay_date';
            return $base;
        }
        $currentRooms = $this->nullableNumber($current['on_books_room_nights'] ?? null, 'on_books_room_nights');
        $currentRevenue = $this->nullableNumber($current['on_books_room_revenue'] ?? null, 'on_books_room_revenue');
        $currentCancel = $this->nullableNumber($current['cumulative_cancel_room_nights'] ?? null, 'cumulative_cancel_room_nights');
        $grossBookings = $this->nullableNumber($current['gross_booking_room_nights'] ?? null, 'gross_booking_room_nights');
        $base['current_on_books_room_nights'] = $currentRooms;
        $base['current_on_books_room_revenue'] = $currentRevenue;
        $base['current_cumulative_cancel_room_nights'] = $currentCancel;
        $base['current_gross_booking_room_nights'] = $grossBookings;
        if ($currentCancel !== null
            && $grossBookings !== null
            && $currentCancel > $grossBookings
        ) {
            $base['status'] = 'rebaseline_required';
            $base['data_gaps'][] = 'cumulative_cancel_room_nights_exceeds_gross_booking_room_nights';
            return $base;
        }
        if (count($eligible) < 2) {
            $base['status'] = 'baseline_only';
            $base['data_gaps'][] = 'previous_verified_on_books_snapshot_missing';
            return $base;
        }

        $previous = $eligible[count($eligible) - 2];
        $previousTime = $this->time((string)($previous['captured_at'] ?? ''), 'previous_captured_at');
        $seconds = $this->elapsedSeconds($previousTime, $currentTime);
        $base['previous_snapshot_ref'] = self::SNAPSHOT_TABLE . '#' . (int)($previous['id'] ?? 0);
        $base['previous_captured_at'] = $previousTime->format('Y-m-d H:i:s.u');
        if ($seconds <= 0) {
            $base['status'] = 'rebaseline_required';
            $base['data_gaps'][] = 'on_books_snapshot_time_not_increasing';
            return $base;
        }
        if ($currentFactScope !== strtolower(trim((string)($previous['fact_scope'] ?? '')))) {
            $base['status'] = 'rebaseline_required';
            $base['data_gaps'][] = 'on_books_fact_scope_changed';
            return $base;
        }

        $hours = $seconds / 3600;
        $base['elapsed_hours'] = round($hours, 4);
        $previousRooms = $this->nullableNumber($previous['on_books_room_nights'] ?? null, 'previous_on_books_room_nights');
        if ($currentRooms === null || $previousRooms === null) {
            $base['data_gaps'][] = 'on_books_room_nights_missing';
        } else {
            $netPickup = $currentRooms - $previousRooms;
            $base['net_pickup_room_nights'] = round($netPickup, 2);
            $base['pickup_room_nights_per_hour'] = round($netPickup / $hours, 4);
        }

        $previousRevenue = $this->nullableNumber($previous['on_books_room_revenue'] ?? null, 'previous_on_books_room_revenue');
        if ($currentRevenue !== null && $previousRevenue !== null) {
            $delta = $currentRevenue - $previousRevenue;
            $base['room_revenue_delta'] = round($delta, 2);
            $base['room_revenue_per_hour'] = round($delta / $hours, 4);
        } else {
            $base['data_gaps'][] = 'on_books_room_revenue_missing';
        }

        $previousCancel = $this->nullableNumber($previous['cumulative_cancel_room_nights'] ?? null, 'previous_cumulative_cancel_room_nights');
        if ($currentCancel === null || $previousCancel === null) {
            $base['data_gaps'][] = 'cumulative_cancel_room_nights_missing';
        } elseif ($currentCancel < $previousCancel) {
            $base['status'] = 'rebaseline_required';
            $base['data_gaps'][] = 'cancellation_counter_reset_or_mismatch';
            return $base;
        } elseif ($base['net_pickup_room_nights'] !== null) {
            $base['gross_pickup_room_nights'] = round(
                (float)$base['net_pickup_room_nights'] + ($currentCancel - $previousCancel),
                2
            );
        }

        if ($currentCancel !== null && $grossBookings !== null && $grossBookings > 0) {
            $base['cancellation_rate_percent'] = round($currentCancel / $grossBookings * 100, 2);
        } else {
            $base['data_gaps'][] = 'cancellation_gross_booking_base_missing';
        }

        $base['status'] = $base['net_pickup_room_nights'] === null ? 'partial' : 'ready';
        $base['data_gaps'] = array_values(array_unique($base['data_gaps']));
        return $base;
    }

    /**
     * @param list<array<string,mixed>> $daily
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function summarizePlanWindow(string $key, string $label, array $daily, array $events): array
    {
        $dayCount = count($daily);
        $startDate = (string)($daily[0]['stay_date'] ?? '');
        $endDate = (string)($daily[$dayCount - 1]['stay_date'] ?? '');
        $withSnapshot = array_values(array_filter(
            $daily,
            static fn(array $day): bool => is_string($day['current_snapshot_ref'] ?? null)
                && (string)$day['current_snapshot_ref'] !== ''
        ));
        $withPickup = array_values(array_filter(
            $daily,
            static fn(array $day): bool => is_numeric($day['net_pickup_room_nights'] ?? null)
        ));
        $snapshotScopes = array_values(array_unique(array_map(
            static fn(array $day): string => trim((string)($day['fact_scope'] ?? '')),
            $withSnapshot
        )));
        $snapshotScopeComparable = $withSnapshot !== []
            && count($snapshotScopes) === 1
            && $snapshotScopes[0] !== '';
        $pickupPairs = array_values(array_unique(array_filter(array_map(
            static fn(array $day): string => is_numeric($day['net_pickup_room_nights'] ?? null)
                ? (string)($day['previous_captured_at'] ?? '') . '|' . (string)($day['current_captured_at'] ?? '')
                : '',
            $daily
        ))));
        $pickupScopes = array_values(array_unique(array_map(
            static fn(array $day): string => trim((string)($day['fact_scope'] ?? '')),
            $withPickup
        )));
        $pickupTimeComparable = $withPickup !== [] && count($pickupPairs) === 1;
        $pickupScopeComparable = $withPickup !== []
            && count($pickupScopes) === 1
            && $pickupScopes[0] !== '';
        $pickupWindowComparable = $pickupTimeComparable && $pickupScopeComparable;
        $roomDays = array_values(array_filter(
            $daily,
            static fn(array $day): bool => is_numeric($day['current_on_books_room_nights'] ?? null)
        ));
        $revenueDays = array_values(array_filter(
            $daily,
            static fn(array $day): bool => is_numeric($day['current_on_books_room_revenue'] ?? null)
        ));
        $windowEvents = array_values(array_filter(
            $events,
            static fn(array $event): bool => (string)($event['event_start_date'] ?? '') <= $endDate
                && (string)($event['event_end_date'] ?? '') >= $startDate
        ));
        $sum = static fn(array $rows, string $field): float => round(array_sum(array_map(
            static fn(array $row): float => (float)$row[$field],
            $rows
        )), 2);
        $dataGaps = [];
        if (count($withSnapshot) < $dayCount) $dataGaps[] = 'window_snapshot_coverage_incomplete';
        if ($withSnapshot !== [] && !$snapshotScopeComparable) $dataGaps[] = 'window_snapshot_fact_scope_mismatch';
        if (count($withPickup) < $dayCount) $dataGaps[] = 'window_pickup_coverage_incomplete';
        if ($withPickup !== [] && !$pickupTimeComparable) $dataGaps[] = 'window_pickup_comparison_window_mismatch';
        if ($withPickup !== [] && !$pickupScopeComparable) $dataGaps[] = 'window_pickup_fact_scope_mismatch';
        if (count($roomDays) < $dayCount) $dataGaps[] = 'window_on_books_room_nights_incomplete';
        if (count($revenueDays) < $dayCount) $dataGaps[] = 'window_on_books_room_revenue_incomplete';
        foreach ($daily as $day) {
            foreach ((array)($day['data_gaps'] ?? []) as $gap) {
                $dataGaps[] = (string)($day['stay_date'] ?? 'unknown') . ':' . (string)$gap;
            }
        }

        return [
            'window_key' => $key,
            'label' => $label,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'day_count' => $dayCount,
            'status' => count($withSnapshot) === 0
                ? 'blocked'
                : (count($withSnapshot) === $dayCount
                    && $snapshotScopeComparable
                    && count($withPickup) === $dayCount
                    && $pickupWindowComparable ? 'ready' : 'partial'),
            'snapshot_coverage_days' => count($withSnapshot),
            'pickup_coverage_days' => count($withPickup),
            'fact_scope' => $snapshotScopeComparable ? $snapshotScopes[0] : null,
            'observed_on_books_room_nights' => $roomDays === [] || !$snapshotScopeComparable
                ? null
                : $sum($roomDays, 'current_on_books_room_nights'),
            'on_books_room_nights_total' => count($roomDays) === $dayCount && $snapshotScopeComparable
                ? $sum($roomDays, 'current_on_books_room_nights')
                : null,
            'observed_on_books_room_revenue' => $revenueDays === [] || !$snapshotScopeComparable
                ? null
                : $sum($revenueDays, 'current_on_books_room_revenue'),
            'on_books_room_revenue_total' => count($revenueDays) === $dayCount && $snapshotScopeComparable
                ? $sum($revenueDays, 'current_on_books_room_revenue')
                : null,
            'pickup_comparison_pair' => $pickupWindowComparable ? $pickupPairs[0] : null,
            'pickup_fact_scope' => $pickupWindowComparable ? $pickupScopes[0] : null,
            'observed_net_pickup_room_nights' => !$pickupWindowComparable
                ? null
                : $sum($withPickup, 'net_pickup_room_nights'),
            'net_pickup_room_nights_total' => count($withPickup) === $dayCount && $pickupWindowComparable
                ? $sum($withPickup, 'net_pickup_room_nights')
                : null,
            'event_count' => count($windowEvents),
            'events' => $windowEvents,
            'daily' => $daily,
            'data_gaps' => array_values(array_unique($dataGaps)),
            'decision_status' => count($withSnapshot) === 0 ? 'data_gap_repair' : 'observation_only',
            'automatic_pricing' => false,
            'automatic_inventory_write' => false,
            'causality_claimed' => false,
            'external_write_count' => 0,
        ];
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function saveDemandEvent(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        array $input,
        int $actorId
    ): array {
        $tenantId = $this->resolveScope($tenantId, $permittedHotelIds, $hotelId);
        if ($actorId <= 0) {
            throw new InvalidArgumentException('demand_event_actor_required');
        }
        $content = $this->normalizeEvent($tenantId, $hotelId, $input);
        $digest = $this->contentDigest($content);
        $idempotencyKey = $this->idempotencyKey($input['idempotency_key'] ?? null);
        $now = $this->now();

        return Db::transaction(function () use ($tenantId, $hotelId, $actorId, $content, $digest, $idempotencyKey, $now): array {
            $existing = Db::name(self::EVENT_TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('idempotency_key', $idempotencyKey)
                ->lock(true)
                ->find();
            if ($existing) {
                $saved = $this->hydrateEvent($existing);
                if (!hash_equals($saved['content_digest'], $digest)) {
                    throw new RuntimeException('demand_event_idempotency_conflict', 409);
                }
                return $saved + ['idempotent' => true];
            }
            $id = (int)Db::name(self::EVENT_TABLE)->insertGetId([
                ...$content,
                'idempotency_key' => $idempotencyKey,
                'content_digest' => $digest,
                'created_by' => $actorId,
                'created_at' => $now,
            ]);
            $saved = $this->readDemandEvent($tenantId, $hotelId, $id);
            if (!hash_equals($saved['content_digest'], $digest)) {
                throw new RuntimeException('demand_event_readback_mismatch');
            }
            return $saved + ['idempotent' => false];
        });
    }

    /** @return array<string,mixed> */
    public function readDemandEvent(int $tenantId, int $hotelId, int $id): array
    {
        $row = Db::name(self::EVENT_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!$row) {
            throw new RuntimeException('demand_event_not_found', 404);
        }
        return $this->hydrateEvent($row);
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function demandCalendar(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        string $startDate,
        string $endDate
    ): array {
        $tenantId = $this->resolveScope($tenantId, $permittedHotelIds, $hotelId);
        $startDate = $this->date($startDate, 'start_date');
        $endDate = $this->date($endDate, 'end_date');
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('demand_calendar_date_range_invalid');
        }
        $rows = Db::name(self::EVENT_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('event_start_date', '<=', $endDate)
            ->where('event_end_date', '>=', $startDate)
            ->order('event_start_date', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $events = array_map(fn(array $row): array => $this->hydrateEvent($row), $rows);
        return [
            'contract_version' => self::CALENDAR_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $events === [] ? 'empty' : 'ready',
            'events' => $events,
            'event_count' => count($events),
            'reference_only' => true,
            'causality_claimed' => false,
            'automatic_pricing' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeSnapshot(int $tenantId, int $hotelId, array $input): array
    {
        $platform = $this->enum((string)($input['platform'] ?? ''), self::PLATFORMS, 'on_books_snapshot_platform_invalid');
        $factScope = $this->enum((string)($input['fact_scope'] ?? ''), self::FACT_SCOPES, 'on_books_snapshot_fact_scope_invalid');
        if (in_array($platform, ['ctrip', 'meituan'], true) && $factScope !== 'ota_channel') {
            throw new InvalidArgumentException('ota_on_books_snapshot_must_keep_channel_scope');
        }
        $quality = $this->enum((string)($input['quality_status'] ?? ''), self::QUALITY_STATUSES, 'on_books_snapshot_quality_invalid');
        $sourceRef = trim((string)($input['source_ref'] ?? ''));
        if ($sourceRef === '' || strlen($sourceRef) > 500) {
            throw new InvalidArgumentException('on_books_snapshot_source_ref_invalid');
        }
        $rooms = $this->nullableNumber($input['on_books_room_nights'] ?? null, 'on_books_room_nights');
        if ($rooms === null) {
            throw new InvalidArgumentException('on_books_room_nights_required');
        }
        $stayDate = $this->date((string)($input['stay_date'] ?? ''), 'stay_date');
        $capturedAt = $this->dateTime((string)($input['captured_at'] ?? ''), 'captured_at');
        $capturedTime = $this->time($capturedAt, 'captured_at');
        if ($capturedTime > $this->clockNow()) {
            throw new InvalidArgumentException('on_books_snapshot_captured_at_future');
        }
        if ($capturedTime->format('Y-m-d') > $stayDate) {
            throw new InvalidArgumentException('on_books_snapshot_after_stay_date');
        }
        return [
            'contract_version' => self::SNAPSHOT_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $hotelId,
            'platform' => $platform,
            'fact_scope' => $factScope,
            'stay_date' => $stayDate,
            'captured_at' => $capturedAt,
            'source_method' => $this->shortText((string)($input['source_method'] ?? ''), 'source_method', 32),
            'source_ref_hash' => hash('sha256', 'on-books-source-v1|' . $sourceRef),
            'on_books_room_nights' => $rooms,
            'on_books_room_revenue' => $this->nullableNumber($input['on_books_room_revenue'] ?? null, 'on_books_room_revenue'),
            'cumulative_cancel_room_nights' => $this->nullableNumber($input['cumulative_cancel_room_nights'] ?? null, 'cumulative_cancel_room_nights'),
            'gross_booking_room_nights' => $this->nullableNumber($input['gross_booking_room_nights'] ?? null, 'gross_booking_room_nights'),
            'quality_status' => $quality,
            // Persisted rows exist only after the service's exact local readback succeeds.
            // Source quality remains a separate caller-visible field above.
            'readback_verified' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function normalizeEvent(int $tenantId, int $hotelId, array $input): array
    {
        $start = $this->date((string)($input['event_start_date'] ?? ''), 'event_start_date');
        $end = $this->date((string)($input['event_end_date'] ?? ''), 'event_end_date');
        if ($start > $end) {
            throw new InvalidArgumentException('demand_event_date_range_invalid');
        }
        $sourceRef = trim((string)($input['source_ref'] ?? ''));
        if ($sourceRef === '' || strlen($sourceRef) > 500) {
            throw new InvalidArgumentException('demand_event_source_ref_invalid');
        }
        $sourceMethod = $this->shortText((string)($input['source_method'] ?? ''), 'source_method', 32);
        $sourceStatus = $this->enum((string)($input['source_status'] ?? 'reference_only'), self::EVENT_SOURCE_STATUSES, 'demand_event_source_status_invalid');
        if ($sourceMethod === 'manual_reference' && $sourceStatus !== 'reference_only') {
            throw new InvalidArgumentException('manual_demand_event_must_remain_reference_only');
        }
        $observedAt = $this->dateTime((string)($input['observed_at'] ?? ''), 'observed_at');
        if ($this->time($observedAt, 'observed_at') > $this->clockNow()) {
            throw new InvalidArgumentException('demand_event_observed_at_future');
        }
        return [
            'contract_version' => self::EVENT_CONTRACT,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_hotel_id' => $hotelId,
            'event_name' => $this->shortText((string)($input['event_name'] ?? ''), 'event_name', 160),
            'event_type' => $this->enum((string)($input['event_type'] ?? ''), self::EVENT_TYPES, 'demand_event_type_invalid'),
            'event_start_date' => $start,
            'event_end_date' => $end,
            'area_label' => $this->shortText((string)($input['area_label'] ?? ''), 'area_label', 160),
            'source_method' => $sourceMethod,
            'source_ref_hash' => hash('sha256', 'demand-event-source-v1|' . $sourceRef),
            'source_status' => $sourceStatus,
            'observed_at' => $observedAt,
            'reference_only' => 1,
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateSnapshot(array $row): array
    {
        $content = [
            'contract_version' => (string)$row['contract_version'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'source_hotel_id' => (int)$row['source_hotel_id'],
            'platform' => (string)$row['platform'],
            'fact_scope' => (string)$row['fact_scope'],
            'stay_date' => (string)$row['stay_date'],
            'captured_at' => (string)$row['captured_at'],
            'source_method' => (string)$row['source_method'],
            'source_ref_hash' => (string)$row['source_ref_hash'],
            'on_books_room_nights' => $this->nullableNumber($row['on_books_room_nights'] ?? null, 'on_books_room_nights'),
            'on_books_room_revenue' => $this->nullableNumber($row['on_books_room_revenue'] ?? null, 'on_books_room_revenue'),
            'cumulative_cancel_room_nights' => $this->nullableNumber($row['cumulative_cancel_room_nights'] ?? null, 'cumulative_cancel_room_nights'),
            'gross_booking_room_nights' => $this->nullableNumber($row['gross_booking_room_nights'] ?? null, 'gross_booking_room_nights'),
            'quality_status' => (string)$row['quality_status'],
            'readback_verified' => (int)$row['readback_verified'],
        ];
        $digest = $this->contentDigest($content);
        if (!hash_equals((string)$row['content_digest'], $digest)) {
            throw new RuntimeException('on_books_snapshot_content_digest_mismatch');
        }
        return [
            'id' => (int)$row['id'],
            ...$content,
            'readback_verified' => (int)$row['readback_verified'] === 1,
            'idempotency_key' => (string)$row['idempotency_key'],
            'content_digest' => $digest,
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function hydrateEvent(array $row): array
    {
        $content = [
            'contract_version' => (string)$row['contract_version'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'source_hotel_id' => (int)$row['source_hotel_id'],
            'event_name' => (string)$row['event_name'],
            'event_type' => (string)$row['event_type'],
            'event_start_date' => (string)$row['event_start_date'],
            'event_end_date' => (string)$row['event_end_date'],
            'area_label' => (string)$row['area_label'],
            'source_method' => (string)$row['source_method'],
            'source_ref_hash' => (string)$row['source_ref_hash'],
            'source_status' => (string)$row['source_status'],
            'observed_at' => (string)$row['observed_at'],
            'reference_only' => 1,
        ];
        $digest = $this->contentDigest($content);
        if (!hash_equals((string)$row['content_digest'], $digest)) {
            throw new RuntimeException('demand_event_content_digest_mismatch');
        }
        return [
            'id' => (int)$row['id'],
            ...$content,
            'reference_only' => true,
            'idempotency_key' => (string)$row['idempotency_key'],
            'content_digest' => $digest,
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'causality_claimed' => false,
            'automatic_pricing' => false,
            'external_write_count' => 0,
        ];
    }

    /** @param list<int> $permittedHotelIds */
    private function resolveScope(int $tenantId, array $permittedHotelIds, int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_scope_required');
        }
        $permitted = array_values(array_unique(array_filter(array_map('intval', $permittedHotelIds), static fn(int $id): bool => $id > 0)));
        if (!in_array($hotelId, $permitted, true)) {
            throw new RuntimeException('hotel_outside_permitted_scope', 403);
        }
        $row = Db::name('hotels')->where('id', $hotelId)->field('id,tenant_id')->find();
        if (!$row) {
            throw new RuntimeException('hotel_not_found', 404);
        }
        $actualTenant = (int)($row['tenant_id'] ?? 0);
        if ($actualTenant <= 0 || ($tenantId > 0 && $tenantId !== $actualTenant)) {
            throw new RuntimeException('hotel_tenant_scope_mismatch', 403);
        }
        return $actualTenant;
    }

    private function idempotencyKey(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '' || strlen($text) > 180) {
            throw new InvalidArgumentException('idempotency_key_invalid');
        }
        return hash('sha256', 'operating-finance-idempotency-v1|' . $text);
    }

    private function nullableNumber(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return round($number, 4);
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed, string $error): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function shortText(string $value, string $field, int $limit): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $limit) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return $value;
    }

    private function date(string $value, string $field): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('Asia/Shanghai'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return $parsed->format('Y-m-d');
    }

    private function dateTime(string $value, string $field): string
    {
        $value = trim(str_replace('T', ' ', $value));
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Asia/Shanghai'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))) {
                return $parsed->format('Y-m-d H:i:s.u');
            }
        }
        throw new InvalidArgumentException($field . '_invalid');
    }

    private function time(string $value, string $field): DateTimeImmutable
    {
        $normalized = $this->dateTime($value, $field);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, new DateTimeZone('Asia/Shanghai'));
        if (!$parsed) {
            throw new InvalidArgumentException($field . '_invalid');
        }
        return $parsed;
    }

    private function elapsedSeconds(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        return ((int)$end->format('U') - (int)$start->format('U'))
            + (((int)$end->format('u') - (int)$start->format('u')) / 1_000_000);
    }

    private function now(): string
    {
        return $this->clockNow()->format('Y-m-d H:i:s.u');
    }

    private function clockNow(): DateTimeImmutable
    {
        $now = ($this->clock)();
        if (!$now instanceof DateTimeImmutable) {
            throw new RuntimeException('booking_demand_clock_invalid');
        }
        return $now->setTimezone(new DateTimeZone('Asia/Shanghai'));
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $content */
    private function contentDigest(array $content): string
    {
        unset($content['hotel_id']);
        return $this->digest($content);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
