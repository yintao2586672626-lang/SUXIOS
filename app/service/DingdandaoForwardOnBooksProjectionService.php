<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Projects verified Dingdandao whole-hotel forward facts into the existing
 * on-books ledger. It never invents cancellation or gross-booking counters.
 */
final class DingdandaoForwardOnBooksProjectionService
{
    public const CONTRACT_VERSION = 'dingdandao_forward_on_books_projection.v1';

    /** @var null|callable(int,int,int):array<string,mixed> */
    private $captureReader;

    public function __construct(
        ?callable $captureReader = null,
        private readonly BookingDemandPlanningService $planning = new BookingDemandPlanningService()
    ) {
        $this->captureReader = $captureReader;
    }

    /** @param list<int> $permittedHotelIds @return array<string,mixed> */
    public function project(
        int $tenantId,
        array $permittedHotelIds,
        int $hotelId,
        int $captureId,
        int $actorId
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $captureId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('dingdandao_forward_projection_scope_invalid');
        }
        $capture = $this->captureReader !== null
            ? call_user_func($this->captureReader, $tenantId, $hotelId, $captureId)
            : (new DingdandaoOperatingTargetCaptureService())->read($tenantId, $hotelId, $captureId);
        $forward = $this->verifiedForward($capture, $tenantId, $hotelId, $captureId);
        $asOfDate = (string)$forward['as_of_date'];
        $capturedAt = (string)$capture['captured_at'];
        $dailyByDate = [];
        foreach ((array)$forward['daily_rows'] as $row) {
            if (is_array($row) && isset($row['stay_date'])) {
                $dailyByDate[(string)$row['stay_date']] = $row;
            }
        }
        $inputs = [];
        for ($offset = 1; $offset <= 7; $offset++) {
            $stayDate = (new DateTimeImmutable($asOfDate, new DateTimeZone('Asia/Shanghai')))
                ->modify('+' . $offset . ' days')
                ->format('Y-m-d');
            $row = $dailyByDate[$stayDate] ?? null;
            if (!is_array($row)
                || !is_numeric($row['booked_rooms'] ?? null)
                || !is_numeric($row['room_fee'] ?? null)
                || (float)$row['booked_rooms'] < 0
                || (float)$row['room_fee'] < 0
            ) {
                throw new RuntimeException('dingdandao_forward_projection_daily_fact_missing');
            }
            $inputs[] = [
                'platform' => 'dingdandao_pms',
                'fact_scope' => 'accommodation_room_fee',
                'stay_date' => $stayDate,
                'captured_at' => $capturedAt,
                'source_method' => 'dingdandao_forward_readback',
                'source_ref' => 'dingdandao_operating_target_capture#'
                    . $captureId . '/forward/' . $stayDate,
                'on_books_room_nights' => (float)$row['booked_rooms'],
                'on_books_room_revenue' => round((float)$row['room_fee'], 2),
                'cumulative_cancel_room_nights' => null,
                'gross_booking_room_nights' => null,
                'quality_status' => 'verified',
                'readback_verified' => true,
                'idempotency_key' => 'dingdandao-forward-on-books:' . $captureId . ':' . $stayDate,
            ];
        }

        $snapshots = Db::transaction(function () use (
            $tenantId,
            $permittedHotelIds,
            $hotelId,
            $actorId,
            $inputs
        ): array {
            $saved = [];
            foreach ($inputs as $input) {
                $snapshot = $this->planning->saveOnBooksSnapshot(
                    $tenantId,
                    $permittedHotelIds,
                    $hotelId,
                    $input,
                    $actorId
                );
                $readback = $this->planning->readSnapshot(
                    $tenantId,
                    $hotelId,
                    (int)$snapshot['id']
                );
                if (($readback['readback_verified'] ?? false) !== true
                    || !hash_equals(
                        (string)($snapshot['content_digest'] ?? ''),
                        (string)($readback['content_digest'] ?? '')
                    )
                ) {
                    throw new RuntimeException('dingdandao_forward_projection_readback_failed');
                }
                $saved[] = $snapshot;
            }
            return $saved;
        });
        $plan = $this->planning->demandPlan(
            $tenantId,
            $permittedHotelIds,
            $hotelId,
            'dingdandao_pms',
            $asOfDate
        );

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'saved_and_readback_verified',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'capture_id' => $captureId,
            'as_of_date' => $asOfDate,
            'captured_at' => $capturedAt,
            'source_scope' => 'whole_hotel_forward_room_status',
            'forward_data_status' => (string)$forward['data_status'],
            'projected_count' => count($snapshots),
            'snapshot_ids' => array_map('intval', array_column($snapshots, 'id')),
            'stay_dates' => array_values(array_column($snapshots, 'stay_date')),
            'idempotent_count' => count(array_filter(
                $snapshots,
                static fn(array $snapshot): bool => ($snapshot['idempotent'] ?? false) === true
            )),
            'readback_verified' => count($snapshots) === 7,
            'demand_plan' => [
                'status' => (string)($plan['status'] ?? 'blocked'),
                'timezone' => (string)($plan['timezone'] ?? 'Asia/Shanghai'),
                'requested_horizons' => (array)($plan['requested_horizons'] ?? []),
                'windows' => array_map(
                    static fn(array $window): array => [
                        'window_key' => (string)($window['window_key'] ?? ''),
                        'status' => (string)($window['status'] ?? 'blocked'),
                        'snapshot_coverage_days' => (int)($window['snapshot_coverage_days'] ?? 0),
                        'pickup_coverage_days' => (int)($window['pickup_coverage_days'] ?? 0),
                        'on_books_room_nights_total' => $window['on_books_room_nights_total'] ?? null,
                        'on_books_room_revenue_total' => $window['on_books_room_revenue_total'] ?? null,
                        'net_pickup_room_nights_total' => $window['net_pickup_room_nights_total'] ?? null,
                        'pickup_comparison_pair' => $window['pickup_comparison_pair'] ?? null,
                        'decision_status' => (string)($window['decision_status'] ?? 'data_gap_repair'),
                        'data_gaps' => array_values(array_map(
                            'strval',
                            (array)($window['data_gaps'] ?? [])
                        )),
                    ],
                    (array)($plan['windows'] ?? [])
                ),
            ],
            'automatic_pricing' => false,
            'automatic_inventory_write' => false,
            'cancellation_metrics_available' => false,
            'gross_pickup_metrics_available' => false,
            'causality_claimed' => false,
            'external_write_count' => 0,
        ];
    }

    /** @return array<string,mixed> */
    private function verifiedForward(
        array $capture,
        int $tenantId,
        int $hotelId,
        int $captureId
    ): array {
        $forward = is_array($capture['forward_room_status'] ?? null)
            ? $capture['forward_room_status'] : [];
        $businessDate = trim((string)($capture['business_date'] ?? ''));
        $capturedAt = trim((string)($capture['captured_at'] ?? ''));
        if ((int)($capture['id'] ?? $capture['capture_id'] ?? 0) !== $captureId
            || (int)($capture['tenant_id'] ?? 0) !== $tenantId
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || ($capture['capture_status'] ?? '') !== 'verified'
            || ($capture['quality_status'] ?? '') !== 'verified'
            || ($capture['identity_status'] ?? '') !== 'matched'
            || ($capture['reconciliation_status'] ?? '') !== 'matched'
            || ($capture['readback_status'] ?? '') !== 'readback_verified'
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
            || strtotime($capturedAt) === false
            || ($forward['contract_version'] ?? '') !== 'dingdandao_forward_room_status.v1'
            || ($forward['fact_scope'] ?? '') !== 'whole_hotel_forward_room_status'
            || ($forward['data_status'] ?? '') !== 'verified'
            || ($forward['readback_status'] ?? '') !== 'readback_verified'
            || ($forward['reconciliation_status'] ?? '') !== 'matched'
            || ($forward['as_of_date'] ?? '') !== $businessDate
            || !is_array($forward['daily_rows'] ?? null)
            || count($forward['daily_rows']) < 8
        ) {
            throw new RuntimeException('dingdandao_forward_projection_capture_not_verified');
        }
        return $forward;
    }
}
