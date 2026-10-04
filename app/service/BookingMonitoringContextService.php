<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class BookingMonitoringContextService
{
    public function normalize(array $input, int $hotel): array
    {
        $date = OperatingWorkbenchMetricsService::date((string)($input['business_date'] ?? ''));
        $prices = $input['prices'] ?? [];
        if (!is_array($prices) || !array_is_list($prices) || count($prices) > 100) throw new InvalidArgumentException('workbench_booking_prices_invalid');
        $rows = []; $seen = [];
        foreach ($prices as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('workbench_booking_price_invalid');
            $stay = OperatingWorkbenchMetricsService::date((string)($row['stay_date'] ?? ''));
            $room = filter_var($row['room_type_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($room === false || $room < 0 || ($room > 0 && !Db::name('room_types')->where('id', $room)->where('hotel_id', $hotel)->find())) throw new RuntimeException('workbench_booking_room_forbidden', 403);
            $key = $stay . '|' . $room;
            if (isset($seen[$key])) throw new InvalidArgumentException('workbench_booking_duplicate_price');
            $seen[$key] = true;
            if ($stay <= $date || $stay > (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d')) throw new InvalidArgumentException('workbench_booking_price_date_invalid');
            $captured = (string)($row['captured_at'] ?? '');
            $clock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $captured, new DateTimeZone('Asia/Shanghai'));
            if (!$clock || $clock->format('Y-m-d H:i:s') !== $captured || substr($captured, 0, 10) !== $date
                || $clock > new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))) throw new InvalidArgumentException('workbench_booking_capture_invalid');
            $price = OperatingWorkbenchMetricsService::number($row['starting_price'] ?? null);
            if ($price === null) throw new InvalidArgumentException('workbench_booking_price_required');
            $rows[] = ['stay_date' => $stay, 'room_type_id' => $room, 'starting_price' => $price, 'currency' => 'CNY',
                'platform' => 'ctrip', 'captured_at' => $captured, 'source_ref' => OperatingWorkbenchMetricsService::text($row['source_ref'] ?? '', 300, true),
                'source_method' => 'manual_entry', 'operator_attested' => ($row['operator_attested'] ?? false) === true,
                'quality_status' => ($row['operator_attested'] ?? false) === true ? 'manual_confirmed' : 'unverified'];
        }
        return ['business_date' => $date, 'region' => OperatingWorkbenchMetricsService::text($input['region'] ?? '', 100),
            'area_manager' => OperatingWorkbenchMetricsService::text($input['area_manager'] ?? '', 100), 'assignment_source' => 'manual_configuration', 'prices' => $rows];
    }

    public function overview(int $tenant, array $permitted, array $ids, array $input): array
    {
        $monitor = new BookingMonitoringService();
        $current = $monitor->overview($tenant, $permitted, $ids, $input);
        $date = $current['business_date'];
        $priorDate = ((int)substr($date, 0, 4) - 1) . substr($date, 4);
        $previous = null; $yearState = 'calendar_date_unavailable';
        try {
            OperatingWorkbenchMetricsService::date($priorDate);
            $previous = $monitor->overview($tenant, $permitted, $ids, array_replace($input, ['business_date' => $priorDate]));
            $yearState = 'loaded';
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage() !== 'workbench_date_invalid') throw $error;
        }
        $index = [];
        foreach ($previous['cells'] ?? [] as $cell) $index[$cell['hotel_id'] . '|' . $cell['room_type_id'] . '|' . $cell['lead_time_days']] = $cell;
        $store = new OperatingWorkbenchSnapshotService(); $contexts = [];
        foreach ($ids as $hotel) $contexts[$hotel] = $store->latest($store->scope($tenant, $permitted, $hotel, 'booking_' . $date));
        $groups = [];
        foreach ($current['cells'] as &$cell) {
            $old = $index[$cell['hotel_id'] . '|' . $cell['room_type_id'] . '|' . $cell['lead_time_days']] ?? null;
            $comparable = $old && $cell['current']['status'] === 'ready' && $old['current']['status'] === 'ready'
                && $cell['current']['fact_scope'] === $old['current']['fact_scope'];
            $cell['prior_year'] = ['observation_date' => $previous ? $priorDate : null, 'stay_date' => $old['stay_date'] ?? null,
                'status' => $comparable ? 'ready' : ($previous ? 'missing_or_incomparable' : $yearState),
                'on_books_room_nights' => $comparable ? $old['current']['on_books_room_nights'] : null,
                'difference' => $comparable ? OperatingWorkbenchMetricsService::finite($cell['current']['on_books_room_nights'] - $old['current']['on_books_room_nights']) : null,
                'source' => $old['current'] ?? null, 'comparison_basis' => '同日历观察日、同固定时点、同提前期、同酒店房型平台口径；闰日不替换。'];
            $context = $contexts[$cell['hotel_id']]['inputs'] ?? [];
            $cell['region'] = $context['region'] ?? ''; $cell['area_manager'] = $context['area_manager'] ?? '';
            $price = null;
            foreach ($context['prices'] ?? [] as $row) if ($row['stay_date'] === $cell['stay_date'] && $row['room_type_id'] === $cell['room_type_id']) $price = $row;
            $cell['ctrip_starting_price'] = $price;
            if ($cell['room_type_id'] !== 0) continue; // Never double count a hotel aggregate and its room types.
            $configured = trim((string)$cell['region']) !== '' && trim((string)$cell['area_manager']) !== '';
            $groupKey = json_encode($configured ? ['assignment', $cell['region'], $cell['area_manager'], $cell['stay_date']] : ['hotel', $cell['hotel_id'], $cell['stay_date']], JSON_THROW_ON_ERROR);
            $groups[$groupKey] ??= ['region' => $cell['region'], 'area_manager' => $cell['area_manager'], 'stay_date' => $cell['stay_date'],
                'assignment_status' => $configured ? 'configured' : 'missing', 'hotel_name' => $configured ? null : $cell['hotel_name'],
                'hotels' => [], 'covered_hotels' => 0, 'room_nights' => 0.0, 'fact_scopes' => []];
            $groups[$groupKey]['hotels'][] = $cell['hotel_id'];
            if ($cell['current']['status'] === 'ready' && $cell['current']['on_books_room_nights'] !== null) {
                $groups[$groupKey]['covered_hotels']++;
                $groups[$groupKey]['room_nights'] = OperatingWorkbenchMetricsService::finite($groups[$groupKey]['room_nights'] + $cell['current']['on_books_room_nights']);
                $groups[$groupKey]['fact_scopes'][] = $cell['current']['fact_scope'];
            }
        }
        unset($cell);
        foreach ($groups as &$group) {
            $group['expected_hotels'] = count($group['hotels']);
            $group['status'] = $group['assignment_status'] === 'configured' && $group['covered_hotels'] === $group['expected_hotels'] && count(array_unique($group['fact_scopes'])) === 1 ? 'ready' : 'partial';
            $group['observed_room_nights'] = $group['covered_hotels'] ? $group['room_nights'] : null;
            if ($group['status'] !== 'ready') $group['room_nights'] = null;
        }
        unset($group);
        return $current + ['prior_year_status' => $yearState, 'group_rollup' => array_values($groups), 'contexts' => $contexts];
    }
}
