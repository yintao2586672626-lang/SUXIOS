<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only future stay-date matrix backed by the existing strict competitor
 * event feed. It does not infer inventory, map room types, or create prices.
 */
final class CompetitorFutureWindowService
{
    public const CONTRACT_VERSION = 'competitor_future_window.v1';
    public const DEFAULT_DAYS = 21;
    public const MAX_DAYS = 31;

    /** @var callable|null */
    private $dayReader;

    /** @var callable|null */
    private $clock;

    public function __construct(?callable $dayReader = null, ?callable $clock = null)
    {
        $this->dayReader = $dayReader;
        $this->clock = $clock;
    }

    /** @return array<string,mixed> */
    public function build(
        int $systemHotelId,
        mixed $platformFilter,
        string $startDate,
        int $days = self::DEFAULT_DAYS,
        string $collectedAtEnd = ''
    ): array {
        if ($systemHotelId <= 0) {
            throw new \InvalidArgumentException('system_hotel_id must be positive');
        }
        $platform = $this->platform($platformFilter);
        $start = $this->date($startDate, 'start_date');
        $days = max(1, min(self::MAX_DAYS, $days));
        $now = $this->now();
        $today = $now->format('Y-m-d');
        $latest = $now->modify('+90 days')->format('Y-m-d');
        if ($start < $today || $start > $latest) {
            throw new \InvalidArgumentException('start_date must be today through the next 90 days');
        }
        $end = (new DateTimeImmutable($start))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        if ($end > $latest) {
            throw new \InvalidArgumentException('end_date must stay within the next 90 days');
        }
        // Freeze one read boundary for the entire matrix so a collection that
        // lands mid-loop cannot produce a cross-date mixture of snapshots.
        $collectedAtEnd = trim($collectedAtEnd) !== ''
            ? trim($collectedAtEnd)
            : $now->format('Y-m-d H:i:s');
        $matrix = [];
        $windowGaps = [];
        $totalCells = 0;
        $availabilityCells = 0;
        $priceCells = 0;
        $targetCount = 0;
        $coveredDates = 0;
        $rangeFeeds = $this->dayReader === null
            ? (new CompetitorEventFeedService())->buildRange(
                $systemHotelId,
                $platform,
                $start,
                $end,
                $collectedAtEnd,
                500
            )
            : [];
        for ($offset = 0; $offset < $days; $offset++) {
            $stayDate = (new DateTimeImmutable($start))->modify('+' . $offset . ' days')->format('Y-m-d');
            $feed = $this->dayReader === null
                ? (is_array($rangeFeeds[$stayDate] ?? null) ? $rangeFeeds[$stayDate] : [])
                : $this->readDay($systemHotelId, $platform, $stayDate, $collectedAtEnd);
            $cells = $this->latestCells((array)($feed['events'] ?? []), $stayDate);
            $coverage = is_array($feed['collection_coverage'] ?? null)
                ? $feed['collection_coverage']
                : [];
            $dateTargetCount = max(0, (int)($coverage['target_count'] ?? 0));
            $dateAvailability = count(array_filter(
                $cells,
                static fn(array $cell): bool => ($cell['availability_evidence_eligible'] ?? false) === true
            ));
            $datePrices = count(array_filter(
                $cells,
                static fn(array $cell): bool => ($cell['price_evidence_eligible'] ?? false) === true
            ));
            $dateGaps = array_values(array_unique(array_filter(array_merge(
                (array)($feed['data_gaps'] ?? []),
                $cells === [] ? ['collection_missing'] : [],
                array_filter(array_map(
                    static fn(array $cell): ?string => (string)($cell['mapping_status'] ?? '') === 'mapping_missing'
                        ? 'room_type_mapping_missing'
                        : null,
                    $cells
                ))
            ))));
            $dateStatus = $cells === []
                ? 'missing'
                : ($dateGaps === [] ? 'available' : 'partial');
            $matrix[] = [
                'stay_date' => $stayDate,
                'status' => $dateStatus,
                'feed_status' => (string)($feed['status'] ?? 'unknown'),
                'target_count' => $dateTargetCount,
                'cell_count' => count($cells),
                'availability_evidence_cell_count' => $dateAvailability,
                'price_evidence_cell_count' => $datePrices,
                'data_gaps' => $dateGaps,
                'cells' => $cells,
            ];
            $totalCells += count($cells);
            $availabilityCells += $dateAvailability;
            $priceCells += $datePrices;
            $targetCount = max($targetCount, $dateTargetCount);
            if ($cells !== []) $coveredDates++;
            foreach ($dateGaps as $gap) $windowGaps[$gap] = true;
        }

        $pricingStatus = $totalCells === 0
            ? 'blocked_by_missing_competitor_events'
            : 'blocked_by_room_type_mapping';
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $totalCells === 0
                ? 'empty'
                : ($windowGaps === [] ? 'available' : 'partial'),
            'system_hotel_id' => $systemHotelId,
            'platform' => $platform,
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'as_of_collected_at' => $collectedAtEnd,
            'target_count' => $targetCount,
            'covered_date_count' => $coveredDates,
            'missing_date_count' => $days - $coveredDates,
            'cell_count' => $totalCells,
            'availability_evidence_cell_count' => $availabilityCells,
            'price_evidence_cell_count' => $priceCells,
            'data_gaps' => array_values(array_keys($windowGaps)),
            'pricing_decision_status' => $pricingStatus,
            'room_type_mapping_status' => 'mapping_missing',
            'matrix' => $matrix,
            'decision_eligible' => false,
            'price_suggestion_created' => false,
            'auto_write_ota' => false,
            'source_scope' => 'ctrip_meituan_ota_channel_future_stay_date_observations_only',
            'scope_notice' => '仅展示目标入住日的公开价格与可售观测；房型未完成人工映射前不进入定价建议，不代表真实库存、销量或营收。',
        ];
    }

    /** @return array<string,mixed> */
    private function readDay(
        int $hotelId,
        string $platform,
        string $stayDate,
        string $collectedAtEnd
    ): array {
        $result = $this->dayReader !== null
            ? call_user_func($this->dayReader, $hotelId, $platform, $stayDate, $collectedAtEnd)
            : (new CompetitorEventFeedService())->build(
                $hotelId,
                $platform,
                $stayDate,
                '',
                $collectedAtEnd,
                500
            );
        return is_array($result) ? $result : [];
    }

    /** @return list<array<string,mixed>> */
    private function latestCells(array $events, string $stayDate): array
    {
        $latest = [];
        foreach ($events as $event) {
            if (!is_array($event) || (string)($event['stay_date'] ?? '') !== $stayDate) continue;
            $platform = strtolower(trim((string)($event['platform'] ?? '')));
            $competitorId = (int)($event['competitor_hotel_id'] ?? $event['hotel_id'] ?? 0);
            $otaHotelId = trim((string)($event['ota_hotel_id'] ?? ''));
            $roomKey = trim((string)($event['room_type_key'] ?? ''));
            $cellIdentity = [
                $platform,
                $competitorId,
                $otaHotelId,
                $roomKey,
                (string)($event['rate_plan_key'] ?? ''),
                (string)($event['package_name'] ?? ''),
                (string)($event['check_out_date'] ?? ''),
                (string)($event['nights'] ?? ''),
                (string)($event['breakfast'] ?? ''),
                (string)($event['cancellation_policy'] ?? ''),
                (string)($event['payment_mode'] ?? ''),
                (string)($event['tax_fee_included'] ?? ''),
                (string)($event['currency'] ?? ''),
                (string)($event['adults'] ?? ''),
                (string)($event['children'] ?? ''),
                (string)($event['price_basis'] ?? ''),
            ];
            $key = hash('sha256', json_encode($cellIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $candidateOrder = [(string)($event['collected_at'] ?? ''), (int)($event['id'] ?? 0)];
            $existingOrder = isset($latest[$key])
                ? [(string)$latest[$key]['collected_at'], (int)$latest[$key]['event_id']]
                : ['', 0];
            if (isset($latest[$key]) && $candidateOrder <= $existingOrder) continue;
            $latest[$key] = [
                'cell_key' => $key,
                'event_id' => (int)($event['id'] ?? 0),
                'platform' => $platform,
                'competitor_hotel_id' => $competitorId ?: null,
                'competitor_hotel_name' => $this->text($event['competitor_hotel_name'] ?? null),
                'ota_hotel_id' => $otaHotelId !== '' ? $otaHotelId : null,
                'stay_date' => $stayDate,
                'room_type_key' => $roomKey !== '' ? $roomKey : null,
                'mapped_room_type_id' => null,
                'mapping_status' => 'mapping_missing',
                'check_out_date' => $this->text($event['check_out_date'] ?? null),
                'nights' => is_numeric($event['nights'] ?? null) && (int)$event['nights'] > 0
                    ? (int)$event['nights']
                    : null,
                'rate_plan_key' => $this->text($event['rate_plan_key'] ?? null),
                'package_name' => $this->text($event['package_name'] ?? null),
                'rate_terms_key' => hash('sha256', json_encode([
                    $platform,
                    $stayDate,
                    $roomKey,
                    (string)($event['rate_plan_key'] ?? ''),
                    (string)($event['package_name'] ?? ''),
                    (string)($event['check_out_date'] ?? ''),
                    (string)($event['nights'] ?? ''),
                    (string)($event['breakfast'] ?? ''),
                    (string)($event['cancellation_policy'] ?? ''),
                    (string)($event['payment_mode'] ?? ''),
                    (string)($event['tax_fee_included'] ?? ''),
                    (string)($event['currency'] ?? ''),
                    (string)($event['adults'] ?? ''),
                    (string)($event['children'] ?? ''),
                    (string)($event['price_basis'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'availability' => $this->text($event['availability'] ?? null),
                'price' => is_numeric($event['price'] ?? null) && (float)$event['price'] > 0
                    ? round((float)$event['price'], 2)
                    : null,
                'currency' => $this->text($event['currency'] ?? null),
                'collected_at' => (string)($event['collected_at'] ?? ''),
                'availability_evidence_eligible' => ($event['availability_evidence_eligible'] ?? false) === true,
                'price_evidence_eligible' => ($event['price_evidence_eligible'] ?? false) === true,
                'readback_verified' => ($event['readback_verified'] ?? false) === true,
                'source_ref' => $this->text($event['source_ref'] ?? null),
                'evidence_gaps' => array_values(array_unique(array_merge(
                    (array)($event['evidence_gaps'] ?? []),
                    ['room_type_mapping_missing']
                ))),
            ];
        }
        $cells = array_values($latest);
        usort($cells, static fn(array $left, array $right): int => [
            (string)$left['platform'],
            (int)($left['competitor_hotel_id'] ?? 0),
            (string)($left['room_type_key'] ?? ''),
        ] <=> [
            (string)$right['platform'],
            (int)($right['competitor_hotel_id'] ?? 0),
            (string)($right['room_type_key'] ?? ''),
        ]);
        return $cells;
    }

    private function platform(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return match ($value) {
            'ctrip', 'xc' => 'ctrip',
            'meituan', 'mt' => 'meituan',
            default => throw new \InvalidArgumentException('platform only supports ctrip or meituan'),
        };
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($field . ' must be YYYY-MM-DD');
        }
        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null
            ? call_user_func($this->clock)
            : new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text !== '' ? mb_substr($text, 0, 500, 'UTF-8') : null;
    }
}
