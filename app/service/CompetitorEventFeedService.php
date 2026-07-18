<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;

/**
 * Read-only event feed for comparable Ctrip/Meituan competitor rate facts.
 *
 * The feed deliberately selects only structured rate evidence. Screenshot
 * paths, device identifiers, raw responses and credential material are never
 * read by this service.
 */
final class CompetitorEventFeedService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const PLATFORM_ALIASES = [
        'ctrip' => ['ctrip', 'xc'],
        'meituan' => ['meituan', 'mt'],
    ];
    private const DECISION_ELIGIBLE_STATUSES = ['available', 'normal', 'ok', 'valid', 'verified'];
    private const REQUIRED_COLUMNS = [
        'id', 'tenant_id', 'store_id', 'hotel_id', 'ota_hotel_id', 'platform', 'price',
        'collected_at', 'fetch_time', 'source_method', 'source_ref', 'validation_status',
        'readback_verified', 'check_in_date', 'check_out_date', 'nights', 'adults',
        'children', 'room_type_key', 'ota_product_id', 'rate_plan_key', 'package_name',
        'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included',
        'price_basis', 'currency', 'availability', 'comparison_key',
    ];

    /**
     * @return array<string,mixed>
     */
    public function build(
        int $systemHotelId,
        mixed $platformFilter,
        string $stayDate,
        string $collectedAtStart = '',
        string $collectedAtEnd = '',
        int $limit = 200
    ): array {
        if ($systemHotelId <= 0) {
            throw new InvalidArgumentException('system_hotel_id/store_id must be a positive integer');
        }

        $platforms = $this->normalizePlatforms($platformFilter);
        $stayDate = $this->normalizeDate($stayDate, 'stay_date');
        $collectedAtStart = $this->normalizeDateTimeFilter($collectedAtStart, false);
        $collectedAtEnd = $this->normalizeDateTimeFilter($collectedAtEnd, true);
        if ($collectedAtStart !== '' && $collectedAtEnd !== '' && $collectedAtStart > $collectedAtEnd) {
            throw new InvalidArgumentException('collected_at_start cannot be later than collected_at_end');
        }
        $limit = max(1, min(500, $limit));

        $missingColumns = $this->missingSchemaColumns();
        if ($missingColumns !== []) {
            return $this->schemaInsufficientPayload(
                $systemHotelId,
                $platforms,
                $stayDate,
                $collectedAtStart,
                $collectedAtEnd,
                $missingColumns
            );
        }

        $storagePlatforms = [];
        foreach ($platforms as $platform) {
            $storagePlatforms = array_merge($storagePlatforms, self::PLATFORM_ALIASES[$platform]);
        }

        $query = Db::name('competitor_price_log')
            ->where('store_id', $systemHotelId)
            ->whereIn('platform', array_values(array_unique($storagePlatforms)))
            ->where('check_in_date', $stayDate);
        if ($collectedAtStart !== '') {
            $query->where('collected_at', '>=', $collectedAtStart);
        }
        if ($collectedAtEnd !== '') {
            $query->where('collected_at', '<=', $collectedAtEnd);
        }

        $matchedCount = (int)(clone $query)->count();
        $rows = $query
            ->field(implode(',', self::REQUIRED_COLUMNS))
            ->order('collected_at', 'desc')
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        return $this->buildFromRows(
            $rows,
            $systemHotelId,
            $platforms,
            $stayDate,
            $collectedAtStart,
            $collectedAtEnd,
            $matchedCount
        );
    }

    /**
     * Pure row analyzer used by the read path and focused unit tests.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>|string $platformFilter
     * @return array<string,mixed>
     */
    public function buildFromRows(
        array $rows,
        int $systemHotelId,
        mixed $platformFilter,
        string $stayDate,
        string $collectedAtStart = '',
        string $collectedAtEnd = '',
        ?int $matchedCount = null
    ): array {
        if ($systemHotelId <= 0) {
            throw new InvalidArgumentException('system_hotel_id/store_id must be a positive integer');
        }
        $platforms = $this->normalizePlatforms($platformFilter);
        $stayDate = $this->normalizeDate($stayDate, 'stay_date');
        $collectedAtStart = $this->normalizeDateTimeFilter($collectedAtStart, false);
        $collectedAtEnd = $this->normalizeDateTimeFilter($collectedAtEnd, true);
        if ($collectedAtStart !== '' && $collectedAtEnd !== '' && $collectedAtStart > $collectedAtEnd) {
            throw new InvalidArgumentException('collected_at_start cannot be later than collected_at_end');
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['store_id'] ?? 0) !== $systemHotelId) {
                continue;
            }
            $platform = $this->canonicalPlatform((string)($row['platform'] ?? ''));
            if ($platform === null || !in_array($platform, $platforms, true)) {
                continue;
            }
            if ($this->storedDate($row['check_in_date'] ?? null) !== $stayDate) {
                continue;
            }
            $collectedAt = $this->storedDateTime($row['collected_at'] ?? null);
            if ($collectedAtStart !== '' && ($collectedAt === null || $collectedAt < $collectedAtStart)) {
                continue;
            }
            if ($collectedAtEnd !== '' && ($collectedAt === null || $collectedAt > $collectedAtEnd)) {
                continue;
            }

            $events[] = $this->normalizeEvent($row, $systemHotelId, $platform, $stayDate, $collectedAt);
        }

        usort($events, static function (array $left, array $right): int {
            $leftTime = $left['collected_at'] ?? '9999-12-31 23:59:59';
            $rightTime = $right['collected_at'] ?? '9999-12-31 23:59:59';
            $timeOrder = strcmp((string)$leftTime, (string)$rightTime);
            return $timeOrder !== 0 ? $timeOrder : ((int)$left['id'] <=> (int)$right['id']);
        });

        $returnedCount = count($events);
        $sampleCount = $matchedCount !== null ? max($returnedCount, $matchedCount) : $returnedCount;
        $decisionEligibleCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['decision_eligible'] ?? false) === true
        ));
        $readbackVerifiedCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['readback_verified'] ?? false) === true
        ));

        $qualityStatusCounts = [];
        $sourceMethods = [];
        $capturedTimes = [];
        foreach ($events as $event) {
            $quality = (string)$event['quality_status'];
            $qualityStatusCounts[$quality] = ($qualityStatusCounts[$quality] ?? 0) + 1;
            if (($event['source_method'] ?? null) !== null) {
                $sourceMethods[(string)$event['source_method']] = true;
            }
            if (($event['collected_at'] ?? null) !== null) {
                $capturedTimes[] = (string)$event['collected_at'];
            }
        }
        ksort($qualityStatusCounts);

        $status = $this->feedStatus($sampleCount, $decisionEligibleCount, $returnedCount);
        $truncated = $sampleCount > $returnedCount;
        $decisionGate = match (true) {
            $status === 'empty' => 'no_matching_events',
            $status === 'insufficient_evidence' => 'no_readback_verified_comparable_events',
            $truncated => 'returned_window_only_matching_events_truncated',
            $status === 'partial' => 'some_events_excluded_from_decision',
            default => 'verified_comparable_events_only',
        };
        $dataGaps = match ($status) {
            'empty' => ['no_matching_competitor_price_events'],
            'insufficient_evidence' => ['no_readback_verified_comparable_events'],
            'partial' => ['some_events_excluded_from_decision'],
            default => [],
        };
        if ($truncated) {
            $dataGaps = array_values(array_unique(['matching_events_truncated', ...$dataGaps]));
        }
        $platformSummaries = [];
        foreach ($platforms as $platform) {
            $platformEvents = array_values(array_filter(
                $events,
                static fn(array $event): bool => ($event['platform'] ?? '') === $platform
            ));
            $platformEligible = count(array_filter(
                $platformEvents,
                static fn(array $event): bool => ($event['decision_eligible'] ?? false) === true
            ));
            $platformStatus = $this->feedStatus(count($platformEvents), $platformEligible, count($platformEvents));
            if ($truncated && $platformStatus === 'available') {
                $platformStatus = 'partial';
            }
            $platformSummaries[] = [
                'platform' => $platform,
                'status' => $platformStatus,
                'sample_count' => count($platformEvents),
                'decision_eligible_sample_count' => $platformEligible,
                'readback_verified_count' => count(array_filter(
                    $platformEvents,
                    static fn(array $event): bool => ($event['readback_verified'] ?? false) === true
                )),
            ];
        }

        return [
            'status' => $status,
            'quality_status' => $status,
            'system_hotel_id' => $systemHotelId,
            'store_id' => $systemHotelId,
            'platforms' => $platforms,
            'stay_date' => $stayDate,
            'requested_collected_at_range' => [
                'start' => $collectedAtStart !== '' ? $collectedAtStart : null,
                'end' => $collectedAtEnd !== '' ? $collectedAtEnd : null,
            ],
            'observed_collected_at_range' => [
                'start' => $capturedTimes !== [] ? min($capturedTimes) : null,
                'end' => $capturedTimes !== [] ? max($capturedTimes) : null,
            ],
            'sample_count' => $sampleCount,
            'returned_event_count' => $returnedCount,
            'decision_eligible_sample_count' => $decisionEligibleCount,
            'decision_eligible_count_scope' => $truncated ? 'latest_returned_events_only' : 'all_matching_events',
            'readback_verified_count' => $readbackVerifiedCount,
            'truncated' => $truncated,
            'summary_scope' => $truncated ? 'latest_returned_events_only' : 'all_matching_events',
            'quality_status_counts' => $qualityStatusCounts,
            'source_methods' => array_values(array_keys($sourceMethods)),
            'platform_summaries' => $platformSummaries,
            'decision_gate' => $decisionGate,
            'data_gaps' => $dataGaps,
            'events' => $events,
            'source_scope' => 'ctrip_meituan_ota_channel_competitor_rate_events_only',
            'scope_notice' => '仅表示携程/美团 OTA 渠道公开竞价与可订状态，不代表酒店总房态、真实剩余库存或全酒店经营事实；不形成自动评分。',
        ];
    }

    /** @return array<int,string> */
    private function normalizePlatforms(mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[,\s]+/', strtolower(trim((string)$value)), -1, PREG_SPLIT_NO_EMPTY);
        $items = is_array($items) ? $items : [];
        if ($items === [] || in_array('all', array_map('strtolower', array_map('strval', $items)), true)) {
            return self::PLATFORMS;
        }

        $platforms = [];
        foreach ($items as $item) {
            $platform = $this->canonicalPlatform((string)$item);
            if ($platform === null) {
                throw new InvalidArgumentException('platform only supports ctrip/xc and meituan/mt');
            }
            $platforms[$platform] = true;
        }

        return array_values(array_keys($platforms));
    }

    private function canonicalPlatform(string $platform): ?string
    {
        $platform = strtolower(trim($platform));
        return match ($platform) {
            'ctrip', 'xc' => 'ctrip',
            'meituan', 'mt' => 'meituan',
            default => null,
        };
    }

    private function normalizeDate(string $value, string $field): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($field . ' must be YYYY-MM-DD');
        }
        return $value;
    }

    private function normalizeDateTimeFilter(string $value, bool $endOfDay): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            return $this->normalizeDate($value, 'collected_at') . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('collected_at filters must be YYYY-MM-DD or a valid local date-time');
    }

    /** @return array<string,mixed> */
    private function normalizeEvent(
        array $row,
        int $systemHotelId,
        string $platform,
        string $stayDate,
        ?string $collectedAt
    ): array {
        $validationStatus = $this->validationStatus((string)($row['validation_status'] ?? ''));
        $qualityStatus = $this->qualityStatus($validationStatus);
        $readbackVerified = filter_var($row['readback_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sourceMethod = $this->nullableText($row['source_method'] ?? null, 40);
        $sourceRef = $this->safeSourceReference($row['source_ref'] ?? null);
        $availability = $this->nullableText(strtolower(trim((string)($row['availability'] ?? ''))), 32);
        $comparisonKey = $this->nullableHash($row['comparison_key'] ?? null);
        $price = is_numeric($row['price'] ?? null) && (float)$row['price'] > 0
            ? (float)$row['price']
            : null;

        $gaps = [];
        if ($collectedAt === null) $gaps[] = 'collected_at_missing';
        if ($sourceMethod === null) $gaps[] = 'source_method_missing';
        if ($sourceRef === null) $gaps[] = 'source_ref_missing_or_redacted';
        if (!$readbackVerified) $gaps[] = 'readback_unverified';
        if (!in_array($validationStatus, self::DECISION_ELIGIBLE_STATUSES, true)) $gaps[] = 'validation_status_not_eligible';
        if ($comparisonKey === null) $gaps[] = 'comparison_key_missing';
        if ($price === null) $gaps[] = 'price_missing';
        if (!in_array($availability, ['available', 'bookable'], true)) $gaps[] = 'ota_channel_bookable_status_missing';

        return [
            'id' => (int)($row['id'] ?? 0),
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'store_id' => $systemHotelId,
            'competitor_hotel_id' => (int)($row['hotel_id'] ?? 0) > 0 ? (int)$row['hotel_id'] : null,
            'ota_hotel_id' => $this->nullableText($row['ota_hotel_id'] ?? null, 80),
            'stay_date' => $stayDate,
            'check_out_date' => $this->storedDate($row['check_out_date'] ?? null),
            'collected_at' => $collectedAt,
            'recorded_at' => $this->storedDateTime($row['fetch_time'] ?? null),
            'price' => $price,
            'currency' => $this->currency($row['currency'] ?? null),
            'availability' => $availability,
            'room_type_key' => $this->nullableText($row['room_type_key'] ?? null, 160),
            'ota_product_id' => $this->nullableText($row['ota_product_id'] ?? null, 120),
            'rate_plan_key' => $this->nullableText($row['rate_plan_key'] ?? null, 160),
            'package_name' => $this->nullableText($row['package_name'] ?? null, 160),
            'breakfast' => $this->nullableText($row['breakfast'] ?? null, 80),
            'cancellation_policy' => $this->nullableText($row['cancellation_policy'] ?? null, 500),
            'payment_mode' => $this->nullableText($row['payment_mode'] ?? null, 80),
            'tax_fee_included' => $this->nullableBoolean($row['tax_fee_included'] ?? null),
            'price_basis' => $this->nullableText($row['price_basis'] ?? null, 80),
            'nights' => $this->nullableInteger($row['nights'] ?? null, 1),
            'adults' => $this->nullableInteger($row['adults'] ?? null, 1),
            'children' => $this->nullableInteger($row['children'] ?? null, 0),
            'source_method' => $sourceMethod,
            'source_ref' => $sourceRef,
            'validation_status' => $validationStatus,
            'quality_status' => $qualityStatus,
            'readback_verified' => $readbackVerified,
            'decision_eligible' => $gaps === [],
            'evidence_gaps' => $gaps,
        ];
    }

    private function feedStatus(int $sampleCount, int $decisionEligibleCount, int $returnedCount): string
    {
        if ($sampleCount <= 0 || $returnedCount <= 0) {
            return 'empty';
        }
        if ($decisionEligibleCount <= 0) {
            return 'insufficient_evidence';
        }
        return $sampleCount > $returnedCount || $decisionEligibleCount < $returnedCount ? 'partial' : 'available';
    }

    private function validationStatus(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_-]{1,32}$/D', $value) === 1 ? $value : 'unverified';
    }

    private function qualityStatus(string $validationStatus): string
    {
        return match (true) {
            in_array($validationStatus, self::DECISION_ELIGIBLE_STATUSES, true) => 'verified',
            in_array($validationStatus, ['incomplete', 'partial'], true) => 'partial',
            in_array($validationStatus, ['collection_failed', 'error', 'failed'], true) => 'failed',
            $validationStatus === 'stale' => 'stale',
            default => 'unverified',
        };
    }

    private function safeSourceReference(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $parts = parse_url($value);
        if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower((string)$parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
            return mb_substr($scheme . '://' . strtolower((string)$parts['host']) . (string)($parts['path'] ?? ''), 0, 500, 'UTF-8');
        }
        if (preg_match('/(?:authorization|cookie|password|secret|session|spidertoken|token)\s*[:=]/i', $value) === 1) {
            return null;
        }
        return mb_substr($value, 0, 500, 'UTF-8');
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function nullableHash(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : null;
    }

    private function currency(mixed $value): ?string
    {
        $value = strtoupper(trim((string)$value));
        return preg_match('/^[A-Z]{3}$/D', $value) === 1 ? $value : null;
    }

    private function nullableBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (in_array($value, [true, 1, '1', 'true'], true)) return true;
        if (in_array($value, [false, 0, '0', 'false'], true)) return false;
        return null;
    }

    private function nullableInteger(mixed $value, int $minimum): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int)$value;
        return $value >= $minimum ? $value : null;
    }

    private function storedDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function storedDateTime(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /** @return array<int,string> */
    private function missingSchemaColumns(): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `competitor_price_log`');
        } catch (\Throwable) {
            return self::REQUIRED_COLUMNS;
        }
        $available = array_fill_keys(array_map(
            static fn(array $row): string => (string)($row['Field'] ?? ''),
            is_array($rows) ? $rows : []
        ), true);
        return array_values(array_filter(
            self::REQUIRED_COLUMNS,
            static fn(string $column): bool => !isset($available[$column])
        ));
    }

    /** @return array<string,mixed> */
    private function schemaInsufficientPayload(
        int $systemHotelId,
        array $platforms,
        string $stayDate,
        string $collectedAtStart,
        string $collectedAtEnd,
        array $missingColumns
    ): array {
        return [
            'status' => 'insufficient_evidence',
            'quality_status' => 'insufficient_evidence',
            'system_hotel_id' => $systemHotelId,
            'store_id' => $systemHotelId,
            'platforms' => $platforms,
            'stay_date' => $stayDate,
            'requested_collected_at_range' => [
                'start' => $collectedAtStart !== '' ? $collectedAtStart : null,
                'end' => $collectedAtEnd !== '' ? $collectedAtEnd : null,
            ],
            'observed_collected_at_range' => ['start' => null, 'end' => null],
            'sample_count' => null,
            'returned_event_count' => 0,
            'decision_eligible_sample_count' => 0,
            'readback_verified_count' => 0,
            'truncated' => false,
            'summary_scope' => 'schema_unavailable',
            'quality_status_counts' => [],
            'source_methods' => [],
            'platform_summaries' => [],
            'decision_gate' => 'competitor_rate_schema_missing',
            'data_gaps' => ['competitor_rate_comparability_schema_missing'],
            'schema_missing_fields' => array_values($missingColumns),
            'events' => [],
            'source_scope' => 'ctrip_meituan_ota_channel_competitor_rate_events_only',
            'scope_notice' => '竞对价格可比字段尚未就绪，不能形成事件事实或评分。',
        ];
    }
}
