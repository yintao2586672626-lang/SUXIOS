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
    private const AVAILABILITY_STATUSES = ['available', 'bookable', 'unavailable', 'sold_out'];
    private const BOOKABLE_STATUSES = ['available', 'bookable'];
    private const REQUIRED_COLUMNS = [
        'id', 'tenant_id', 'store_id', 'hotel_id', 'ota_hotel_id', 'platform', 'price',
        'collected_at', 'fetch_time', 'source_method', 'source_ref', 'validation_status',
        'readback_verified', 'check_in_date', 'check_out_date', 'nights', 'adults',
        'children', 'room_type_key', 'ota_product_id', 'rate_plan_key', 'package_name',
        'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included',
        'price_basis', 'currency', 'availability', 'availability_scope_key', 'comparison_key',
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
        $rows = $this->attachCompetitorHotelNames($rows, $systemHotelId);

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
        $events = $this->attachTimelineTransitions($events);

        $returnedCount = count($events);
        $sampleCount = $matchedCount !== null ? max($returnedCount, $matchedCount) : $returnedCount;
        $priceEvidenceEligibleCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['price_evidence_eligible'] ?? false) === true
        ));
        $availabilityEvidenceEligibleCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['availability_evidence_eligible'] ?? false) === true
        ));
        $evidenceEligibleCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['event_eligible'] ?? false) === true
        ));
        $readbackVerifiedCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['readback_verified'] ?? false) === true
        ));
        $identityBoundCount = count(array_filter(
            $events,
            static fn(array $event): bool => preg_match(
                '/^[1-9][0-9]{0,19}$/D',
                (string)($event['ota_hotel_id'] ?? '')
            ) === 1
        ));
        $identityVerifiedCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['identity_status'] ?? '') === 'observation_identity_verified'
        ));
        $identityMismatchCount = count(array_filter(
            $events,
            static fn(array $event): bool => ($event['identity_status'] ?? '') === 'observation_identity_mismatch'
        ));
        $targetIdentityBoundCount = count(array_filter(
            $events,
            static fn(array $event): bool => preg_match(
                '/^[1-9][0-9]{0,19}$/D',
                (string)($event['target_ota_hotel_id'] ?? '')
            ) === 1
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

        $status = $this->feedStatus($sampleCount, $evidenceEligibleCount, $returnedCount);
        $truncated = $sampleCount > $returnedCount;
        $decisionGate = match (true) {
            $status === 'empty' => 'no_matching_events',
            $status === 'insufficient_evidence' && $identityMismatchCount > 0
                => 'observation_identity_mismatch',
            $status === 'insufficient_evidence' && $returnedCount > 0
                && $identityVerifiedCount === 0 && $targetIdentityBoundCount > 0
                => 'observation_identity_unverified',
            $status === 'insufficient_evidence' && $returnedCount > 0 && $targetIdentityBoundCount === 0
                => 'competitor_identity_binding_missing',
            $status === 'insufficient_evidence' => 'no_readback_verified_comparable_events',
            $truncated => 'returned_window_only_matching_events_truncated',
            $status === 'partial' => 'some_events_excluded_from_decision',
            $priceEvidenceEligibleCount === 0 && $availabilityEvidenceEligibleCount > 0 => 'verified_availability_events_only',
            default => 'verified_price_and_availability_events',
        };
        $dataGaps = match (true) {
            $status === 'empty' => ['no_matching_competitor_price_events'],
            $status === 'insufficient_evidence' && $identityMismatchCount > 0
                => ['observation_ota_identity_mismatch'],
            $status === 'insufficient_evidence' && $returnedCount > 0
                && $identityVerifiedCount === 0 && $targetIdentityBoundCount > 0
                => ['observation_ota_identity_unverified'],
            $status === 'insufficient_evidence' && $returnedCount > 0 && $targetIdentityBoundCount === 0
                => ['competitor_ota_identity_binding_missing'],
            $status === 'insufficient_evidence' => ['no_readback_verified_comparable_events'],
            $status === 'partial' => ['some_events_excluded_from_decision'],
            default => [],
        };
        if ($returnedCount > 0 && $priceEvidenceEligibleCount === 0) {
            $dataGaps[] = 'no_comparable_bookable_price_events';
        }
        if ($truncated) {
            $dataGaps = array_values(array_unique(['matching_events_truncated', ...$dataGaps]));
        }
        $dataGaps = $this->uniqueGaps($dataGaps);
        $platformSummaries = [];
        foreach ($platforms as $platform) {
            $platformEvents = array_values(array_filter(
                $events,
                static fn(array $event): bool => ($event['platform'] ?? '') === $platform
            ));
            $platformEligible = count(array_filter(
                $platformEvents,
                static fn(array $event): bool => ($event['event_eligible'] ?? false) === true
            ));
            $platformPriceEligible = count(array_filter(
                $platformEvents,
                static fn(array $event): bool => ($event['price_evidence_eligible'] ?? false) === true
            ));
            $platformAvailabilityEligible = count(array_filter(
                $platformEvents,
                static fn(array $event): bool => ($event['availability_evidence_eligible'] ?? false) === true
            ));
            $platformStatus = $this->feedStatus(count($platformEvents), $platformEligible, count($platformEvents));
            if ($truncated && $platformStatus === 'available') {
                $platformStatus = 'partial';
            }
            $platformSummaries[] = [
                'platform' => $platform,
                'status' => $platformStatus,
                'sample_count' => count($platformEvents),
                'evidence_eligible_sample_count' => $platformEligible,
                'availability_evidence_eligible_sample_count' => $platformAvailabilityEligible,
                'price_evidence_eligible_sample_count' => $platformPriceEligible,
                'decision_eligible_sample_count' => $platformPriceEligible,
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
            'evidence_eligible_sample_count' => $evidenceEligibleCount,
            'availability_evidence_eligible_sample_count' => $availabilityEvidenceEligibleCount,
            'price_evidence_eligible_sample_count' => $priceEvidenceEligibleCount,
            'decision_eligible_sample_count' => $priceEvidenceEligibleCount,
            'decision_eligible_count_scope' => $truncated ? 'latest_returned_events_only' : 'all_matching_events',
            'readback_verified_count' => $readbackVerifiedCount,
            'identity_bound_sample_count' => $identityBoundCount,
            'identity_verified_sample_count' => $identityVerifiedCount,
            'identity_mismatch_sample_count' => $identityMismatchCount,
            'target_identity_bound_sample_count' => $targetIdentityBoundCount,
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
        $otaHotelId = $this->nullableText($row['ota_hotel_id'] ?? null, 80);
        $targetOtaHotelId = $this->nullableText($row['competitor_ota_hotel_id'] ?? null, 80);
        if ($targetOtaHotelId === null || preg_match('/^[1-9][0-9]{0,19}$/D', $targetOtaHotelId) !== 1) {
            $targetOtaHotelId = null;
        }
        $observationIdentityNumeric = preg_match('/^[1-9][0-9]{0,19}$/D', (string)$otaHotelId) === 1;
        $observationIdentityVerified = $observationIdentityNumeric
            && $targetOtaHotelId !== null
            && hash_equals($targetOtaHotelId, (string)$otaHotelId);
        $availabilityScopeKey = $this->nullableHash($row['availability_scope_key'] ?? null);
        $comparisonKey = $this->nullableHash($row['comparison_key'] ?? null);
        $price = is_numeric($row['price'] ?? null) && (float)$row['price'] > 0
            ? (float)$row['price']
            : null;

        $commonGaps = [];
        if ($collectedAt === null) $commonGaps[] = 'collected_at_missing';
        if ($sourceMethod === null) $commonGaps[] = 'source_method_missing';
        if ($sourceRef === null) $commonGaps[] = 'source_ref_missing_or_redacted';
        if (!$readbackVerified) $commonGaps[] = 'readback_unverified';
        if (!$observationIdentityNumeric) {
            $commonGaps[] = 'ota_hotel_id_missing_or_unverified';
        } elseif ($targetOtaHotelId === null) {
            $commonGaps[] = 'competitor_target_ota_identity_missing';
        } elseif (!$observationIdentityVerified) {
            $commonGaps[] = 'ota_hotel_id_target_mismatch';
        }

        $availabilityGaps = $commonGaps;
        // A public availability observation can be complete even when room-rate
        // terms are partial. The dedicated scope key proves the stay/search
        // dimensions; incomplete/partial only blocks price decision evidence.
        if (!in_array($validationStatus, [...self::DECISION_ELIGIBLE_STATUSES, 'incomplete', 'partial'], true)) {
            $availabilityGaps[] = 'validation_status_not_eligible';
        }
        if ($availabilityScopeKey === null) $availabilityGaps[] = 'availability_scope_key_missing';
        if (!in_array($availability, self::AVAILABILITY_STATUSES, true)) {
            $availabilityGaps[] = 'ota_channel_availability_status_missing';
        }
        $priceGaps = $commonGaps;
        if (!in_array($validationStatus, self::DECISION_ELIGIBLE_STATUSES, true)) {
            $priceGaps[] = 'validation_status_not_eligible';
        }
        if ($comparisonKey === null) $priceGaps[] = 'comparison_key_missing';
        if ($price === null) $priceGaps[] = 'price_missing';
        if (!in_array($availability, self::BOOKABLE_STATUSES, true)) {
            $priceGaps[] = 'ota_channel_bookable_status_missing';
        }
        $availabilityEvidenceEligible = $availabilityGaps === [];
        $priceEvidenceEligible = $priceGaps === [];
        $gaps = in_array($availability, ['unavailable', 'sold_out'], true)
            ? $availabilityGaps
            : ($this->uniqueGaps([...$availabilityGaps, ...$priceGaps]));

        return [
            'id' => (int)($row['id'] ?? 0),
            'platform' => $platform,
            'system_hotel_id' => $systemHotelId,
            'store_id' => $systemHotelId,
            'competitor_hotel_id' => (int)($row['hotel_id'] ?? 0) > 0 ? (int)$row['hotel_id'] : null,
            'competitor_hotel_name' => $this->nullableText($row['competitor_hotel_name'] ?? null, 160),
            'ota_hotel_id' => $otaHotelId,
            'target_ota_hotel_id' => $targetOtaHotelId,
            'identity_status' => $observationIdentityVerified
                ? 'observation_identity_verified'
                : ($observationIdentityNumeric && $targetOtaHotelId !== null
                    ? 'observation_identity_mismatch'
                    : ($targetOtaHotelId !== null ? 'target_bound_observation_unverified' : 'target_binding_missing')),
            'stay_date' => $stayDate,
            'check_out_date' => $this->storedDate($row['check_out_date'] ?? null),
            'collected_at' => $collectedAt,
            'recorded_at' => $this->storedDateTime($row['fetch_time'] ?? null),
            'price' => $price,
            'currency' => $this->currency($row['currency'] ?? null),
            'availability' => $availability,
            'availability_scope_key' => $availabilityScopeKey,
            'comparison_key' => $comparisonKey,
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
            'availability_evidence_eligible' => $availabilityEvidenceEligible,
            'price_evidence_eligible' => $priceEvidenceEligible,
            'event_eligible' => $availabilityEvidenceEligible || $priceEvidenceEligible,
            'decision_eligible' => $priceEvidenceEligible,
            'availability_evidence_gaps' => $this->uniqueGaps($availabilityGaps),
            'price_evidence_gaps' => $this->uniqueGaps($priceGaps),
            'evidence_gaps' => $gaps,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function attachCompetitorHotelNames(array $rows, int $systemHotelId): array
    {
        $hotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['hotel_id'] ?? 0),
            $rows
        ), static fn(int $hotelId): bool => $hotelId > 0)));
        if ($hotelIds === []) {
            return $rows;
        }

        try {
            $targets = Db::name('competitor_hotel')
                ->where('store_id', $systemHotelId)
                ->whereIn('id', $hotelIds)
                ->field('id,hotel_name,hotel_code')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return $rows;
        }

        $names = [];
        $otaHotelIds = [];
        foreach ($targets as $target) {
            $targetId = (int)($target['id'] ?? 0);
            $targetName = trim((string)($target['hotel_name'] ?? ''));
            if ($targetId > 0 && $targetName !== '') {
                $names[$targetId] = $targetName;
            }
            $targetOtaHotelId = trim((string)($target['hotel_code'] ?? ''));
            if ($targetId > 0 && preg_match('/^[1-9][0-9]{0,19}$/D', $targetOtaHotelId) === 1) {
                $otaHotelIds[$targetId] = $targetOtaHotelId;
            }
        }
        foreach ($rows as &$row) {
            $hotelId = (int)($row['hotel_id'] ?? 0);
            if ($hotelId > 0 && isset($names[$hotelId])) {
                $row['competitor_hotel_name'] = $names[$hotelId];
            }
            if ($hotelId > 0 && isset($otaHotelIds[$hotelId])) {
                $row['competitor_ota_hotel_id'] = $otaHotelIds[$hotelId];
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<int,array<string,mixed>> $events @return array<int,array<string,mixed>> */
    private function attachTimelineTransitions(array $events): array
    {
        $previousAvailabilityByScope = [];
        $previousPriceByScope = [];
        foreach ($events as &$event) {
            $event['previous_event_id'] = null;
            $event['previous_price'] = null;
            $event['previous_availability'] = null;
            $event['price_change_amount'] = null;
            $event['price_change_percent'] = null;
            $event['event_type'] = 'unverified_observation';
            $event['secondary_event_type'] = null;
            $event['previous_price_event_id'] = null;
            $event['event_evidence_gaps'] = $event['evidence_gaps'] ?? [];

            $identity = (string)($event['competitor_hotel_id'] ?? $event['ota_hotel_id'] ?? '');
            $availabilityScopeKey = (string)($event['availability_scope_key'] ?? '');
            $comparisonKey = (string)($event['comparison_key'] ?? '');
            if ($identity === '' || ($availabilityScopeKey === '' && $comparisonKey === '')) {
                $event['event_eligible'] = false;
                continue;
            }

            $platform = (string)($event['platform'] ?? '');
            $surfaceKey = hash('sha256', json_encode([
                $event['source_method'] ?? null,
                $event['source_ref'] ?? null,
                $event['room_type_key'] ?? null,
                $event['ota_product_id'] ?? null,
                $event['rate_plan_key'] ?? null,
                $event['price_basis'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $availabilityScope = $availabilityScopeKey === ''
                ? null
                : implode('|', [$platform, $identity, $availabilityScopeKey, $surfaceKey]);
            $priceScope = $comparisonKey === ''
                ? null
                : implode('|', [$platform, $identity, $comparisonKey, $surfaceKey]);
            $previousAvailabilityEvent = $availabilityScope === null
                ? null
                : ($previousAvailabilityByScope[$availabilityScope] ?? null);
            $previousPriceEvent = $priceScope === null
                ? null
                : ($previousPriceByScope[$priceScope] ?? null);
            $currentAvailability = (string)($event['availability'] ?? '');
            $currentPrice = $event['price'] ?? null;
            $availabilityTransitionType = null;
            if ($previousAvailabilityEvent !== null) {
                $previousAvailabilityValue = (string)($previousAvailabilityEvent['availability'] ?? '');
                $previousBookable = in_array($previousAvailabilityValue, self::BOOKABLE_STATUSES, true);
                $currentBookable = in_array($currentAvailability, self::BOOKABLE_STATUSES, true);
                if ($currentAvailability !== $previousAvailabilityValue
                    && ($previousBookable !== $currentBookable || (!$previousBookable && !$currentBookable))
                ) {
                    $availabilityTransitionType = match (true) {
                        $previousBookable && $currentAvailability === 'sold_out' => 'became_sold_out',
                        $previousBookable && !$currentBookable => 'became_unavailable',
                        !$previousBookable && $currentBookable => 'became_available',
                        default => 'availability_changed',
                    };
                }
            }
            $priceTransitionType = null;
            $priceChange = null;
            $priceChangePercent = null;
            if ($previousPriceEvent !== null
                && is_numeric($currentPrice)
                && is_numeric($previousPriceEvent['price'] ?? null)
                && abs((float)$currentPrice - (float)$previousPriceEvent['price']) >= 0.001
            ) {
                $priceChange = round((float)$currentPrice - (float)$previousPriceEvent['price'], 2);
                $priceTransitionType = $priceChange > 0 ? 'price_increased' : 'price_decreased';
                $priceChangePercent = (float)$previousPriceEvent['price'] > 0
                    ? round($priceChange / (float)$previousPriceEvent['price'] * 100, 2)
                    : null;
            }

            if ($previousAvailabilityEvent === null && $previousPriceEvent === null) {
                $event['event_type'] = 'first_observation';
                $event['event_eligible'] = ($event['availability_evidence_eligible'] ?? false) === true
                    || ($event['price_evidence_eligible'] ?? false) === true;
                $event['event_evidence_gaps'] = $event['evidence_gaps'] ?? [];
            } elseif ($availabilityTransitionType !== null && $previousAvailabilityEvent !== null) {
                $previous = $previousAvailabilityEvent;
                $previousAvailability = (string)($previous['availability'] ?? '');
                $event['previous_event_id'] = (int)($previous['id'] ?? 0) ?: null;
                $event['previous_price'] = $previous['price'] ?? null;
                $event['previous_availability'] = $previousAvailability;
                $event['event_type'] = $availabilityTransitionType;
                $availabilityTransitionEligible = ($previous['availability_evidence_eligible'] ?? false) === true
                    && ($event['availability_evidence_eligible'] ?? false) === true;
                $event['event_eligible'] = $availabilityTransitionEligible;
                $event['event_evidence_gaps'] = $event['availability_evidence_gaps'] ?? [];
                if (($previous['availability_evidence_eligible'] ?? false) !== true) {
                    $event['event_evidence_gaps'][] = 'previous_availability_evidence_not_eligible';
                }
                if ($priceTransitionType !== null && $previousPriceEvent !== null) {
                    $event['secondary_event_type'] = $priceTransitionType;
                    $event['previous_price_event_id'] = (int)($previousPriceEvent['id'] ?? 0) ?: null;
                    $event['previous_price'] = $previousPriceEvent['price'];
                    $event['price_change_amount'] = $priceChange;
                    $event['price_change_percent'] = $priceChangePercent;
                    $priceTransitionEligible = ($previousPriceEvent['price_evidence_eligible'] ?? false) === true
                        && ($event['price_evidence_eligible'] ?? false) === true;
                    $event['event_eligible'] = $availabilityTransitionEligible || $priceTransitionEligible;
                    $event['event_evidence_gaps'] = [
                        ...$event['event_evidence_gaps'],
                        ...($event['price_evidence_gaps'] ?? []),
                    ];
                    if (($previousPriceEvent['price_evidence_eligible'] ?? false) !== true) {
                        $event['event_evidence_gaps'][] = 'previous_price_evidence_not_eligible';
                    }
                }
            } elseif ($priceTransitionType !== null && $previousPriceEvent !== null) {
                $previous = $previousPriceEvent;
                $previousPrice = $previous['price'];
                $event['previous_event_id'] = (int)($previous['id'] ?? 0) ?: null;
                $event['previous_price_event_id'] = $event['previous_event_id'];
                $event['previous_price'] = $previousPrice;
                $event['previous_availability'] = $previous['availability'] ?? null;
                $event['event_type'] = $priceTransitionType;
                $event['price_change_amount'] = $priceChange;
                $event['price_change_percent'] = $priceChangePercent;
                $event['event_eligible'] = ($previous['price_evidence_eligible'] ?? false) === true
                    && ($event['price_evidence_eligible'] ?? false) === true;
                $event['event_evidence_gaps'] = $event['price_evidence_gaps'] ?? [];
                if (($previous['price_evidence_eligible'] ?? false) !== true) {
                    $event['event_evidence_gaps'][] = 'previous_price_evidence_not_eligible';
                }
            } else {
                $previous = $previousAvailabilityEvent ?? $previousPriceEvent;
                $event['previous_event_id'] = (int)($previous['id'] ?? 0) ?: null;
                $event['previous_price'] = $previous['price'] ?? null;
                $event['previous_availability'] = $previous['availability'] ?? null;
                $event['event_type'] = 'no_change';
                $event['event_eligible'] = (($previousAvailabilityEvent['availability_evidence_eligible'] ?? false) === true
                        && ($event['availability_evidence_eligible'] ?? false) === true)
                    || (($previousPriceEvent['price_evidence_eligible'] ?? false) === true
                        && ($event['price_evidence_eligible'] ?? false) === true);
                $event['event_evidence_gaps'] = $event['evidence_gaps'] ?? [];
                if (($previous['event_eligible'] ?? false) !== true) {
                    $event['event_evidence_gaps'][] = 'previous_event_not_eligible';
                }
            }

            $event['event_evidence_gaps'] = $this->uniqueGaps($event['event_evidence_gaps']);
            if ($availabilityScope !== null) {
                $previousAvailabilityByScope[$availabilityScope] = $event;
            }
            if ($priceScope !== null && is_numeric($currentPrice) && (float)$currentPrice > 0) {
                $previousPriceByScope[$priceScope] = $event;
            }
        }
        unset($event);

        return $events;
    }

    /** @param array<int,string> $gaps @return array<int,string> */
    private function uniqueGaps(array $gaps): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $gaps), static fn(string $gap): bool => $gap !== '')));
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
            'evidence_eligible_sample_count' => 0,
            'availability_evidence_eligible_sample_count' => 0,
            'price_evidence_eligible_sample_count' => 0,
            'decision_eligible_sample_count' => 0,
            'readback_verified_count' => 0,
            'identity_bound_sample_count' => 0,
            'target_identity_bound_sample_count' => 0,
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
