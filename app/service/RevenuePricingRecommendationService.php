<?php
declare(strict_types=1);

namespace app\service;

use app\model\CompetitorAnalysis;
use app\model\DemandForecast;
use app\model\PriceSuggestion;
use app\model\RoomType;
use think\facade\Db;

class RevenuePricingRecommendationService
{
    public const TRUSTED_DECISION_CONTRACT_VERSION = 'revenue_ai_trusted_decision.v1';

    private const MODEL_VERSION = 'advisory_revenue_pricing_v1';
    private const MAX_CHANGE_RATE = 0.20;
    private const MIN_MATERIAL_CHANGE = 1.0;
    private const MIN_PRIMARY_SIGNAL_COUNT = 2;
    private const COMPETITOR_LOOKBACK_DAYS = 7;
    private const CTRIP_TRAFFIC_HISTORY_DAYS = 14;
    private const DB_BIND_PARAMETER_BUDGET = 60000;
    private const DB_IN_KEY_CHUNK_SIZE = 1000;
    private const BULK_INSERT_PACKET_BUDGET_BYTES = 2000000;
    private const PENDING_BATCH_INSERT_ATTEMPTS = 3;
    private const CTRIP_TRAFFIC_SOURCE_ALIASES = ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile'];
    private const CTRIP_COMPETITOR_PLATFORM_VALUES = [CompetitorAnalysis::PLATFORM_CTRIP, '1', 'ctrip'];

    private TrustedOtaFactRepository $trustedOtaFacts;
    private AiDecisionQualityService $decisionQualityService;
    private StrictCtripTrafficHistoryReader $strictTrafficHistory;

    /** @var array<string, array<string, mixed>> */
    private array $hotelSignalCache = [];

    /** @var array<int, string> */
    private array $hotelNameCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $forecastSignalCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $competitorSignalCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $ctripTrafficForecastSignalCache = [];

    public function __construct(
        ?TrustedOtaFactRepository $trustedOtaFacts = null,
        ?AiDecisionQualityService $decisionQualityService = null,
        ?StrictCtripTrafficHistoryReader $strictTrafficHistory = null
    )
    {
        $this->trustedOtaFacts = $trustedOtaFacts ?? new TrustedOtaFactRepository();
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
        $this->strictTrafficHistory = $strictTrafficHistory ?? new StrictCtripTrafficHistoryReader();
    }

    /**
     * @param array<string, mixed> $roomType
     * @return array<string, mixed>
     */
    public function recommend(int $hotelId, array $roomType, string $targetDate): array
    {
        $roomTypeId = (int)($roomType['id'] ?? 0);
        $hotelSignals = $this->hotelSignals($hotelId, $targetDate);
        $forecast = $this->forecastSignal($hotelId, $roomTypeId, $targetDate, $roomType);
        $competitor = $this->competitorSignal($hotelId, $roomTypeId, $targetDate, (float)($roomType['base_price'] ?? 0));
        $inventory = $this->inventorySignal($roomType, $forecast);

        $signals = array_merge($hotelSignals, [
            'demand_forecast' => $forecast,
            'competitor' => $competitor,
            'inventory' => $inventory,
        ]);
        $signals['data_gaps'] = $this->uniqueStrings(array_filter(array_merge(
            $hotelSignals['data_gaps'] ?? [],
            $forecast['data_gaps'] ?? [],
            $competitor['data_gaps'] ?? [],
            $inventory['data_gaps'] ?? []
        )));

        return $this->recommendFromSignals($roomType, $signals);
    }

    /**
     * Preload every database-backed signal needed by one bounded generation
     * request, then calculate each hotel/date/room recommendation in memory.
     * The returned recommendation payload is the same payload produced by
     * recommend(); only the read strategy changes.
     *
     * @param array<int, array<string, mixed>> $roomTypes
     * @param array<int, string> $targetDates
     * @return array<string, array<string, mixed>> keyed by date|room_type_id
     */
    public function recommendBatch(int $hotelId, array $roomTypes, array $targetDates): array
    {
        $targetDates = array_values(array_unique(array_filter(array_map(
            static fn(mixed $date): string => trim((string)$date),
            $targetDates
        ), static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1)));
        sort($targetDates, SORT_STRING);

        $roomTypesById = [];
        foreach ($roomTypes as $roomType) {
            if (!is_array($roomType)) {
                continue;
            }
            $roomTypeId = (int)($roomType['id'] ?? 0);
            if ($roomTypeId > 0) {
                $roomTypesById[$roomTypeId] = $roomType;
            }
        }
        if ($hotelId <= 0 || $targetDates === [] || $roomTypesById === []) {
            return [];
        }

        $roomTypeIds = array_map('intval', array_keys($roomTypesById));
        $this->primeHotelSignalsBatch($hotelId, $targetDates);
        $this->primeForecastSignalsBatch($hotelId, $roomTypeIds, $targetDates);
        $this->primeCompetitorSignalsBatch($hotelId, $roomTypeIds, $targetDates, $roomTypesById);

        $recommendations = [];
        foreach ($targetDates as $targetDate) {
            foreach ($roomTypesById as $roomTypeId => $roomType) {
                $recommendations[self::batchRecommendationKey((int)$roomTypeId, $targetDate)] =
                    $this->recommend($hotelId, $roomType, $targetDate);
            }
        }
        return $recommendations;
    }

    public static function batchRecommendationKey(int $roomTypeId, string $targetDate): string
    {
        return trim($targetDate) . '|' . $roomTypeId;
    }

    /**
     * Build the complete bounded generation set outside the write transaction,
     * insert eligible pending suggestions in one statement, then verify every
     * inserted identity with one readback query.
     *
     * @param array<int, array<string, mixed>> $roomTypes
     * @param array<int, string> $targetDates
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    public function generatePendingBatch(int $hotelId, array $roomTypes, array $targetDates): array
    {
        $targetDates = array_values(array_unique(array_filter(array_map(
            static fn(mixed $date): string => trim((string)$date),
            $targetDates
        ), static fn(string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1)));
        sort($targetDates, SORT_STRING);
        if ($hotelId <= 0 || $roomTypes === [] || $targetDates === []) {
            return [[], []];
        }
        $authoritativeTenantId = filter_var(
            Db::name('hotels')->where('id', $hotelId)->value('tenant_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($authoritativeTenantId === false) {
            throw new \RuntimeException('price suggestion batch hotel tenant unavailable');
        }

        $requestedRoomTypes = [];
        foreach ($roomTypes as $roomType) {
            if (!is_array($roomType)) {
                throw new \InvalidArgumentException('price suggestion batch room scope invalid');
            }
            $roomTypeId = (int)($roomType['id'] ?? 0);
            if ($roomTypeId <= 0) {
                throw new \InvalidArgumentException('price suggestion batch room scope invalid');
            }
            $requestedRoomTypes[$roomTypeId] = $roomType;
        }
        $authoritativeRooms = RoomType::whereIn('id', array_map('intval', array_keys($requestedRoomTypes)))
            ->where('tenant_id', (int)$authoritativeTenantId)
            ->where('hotel_id', $hotelId)
            ->select()
            ->toArray();
        $roomTypesById = [];
        foreach ($authoritativeRooms as $roomType) {
            if (is_array($roomType) && (int)($roomType['id'] ?? 0) > 0) {
                $roomTypesById[(int)$roomType['id']] = $roomType;
            }
        }
        $authoritativeRoomIds = array_map('intval', array_keys($roomTypesById));
        $requestedRoomIds = array_map('intval', array_keys($requestedRoomTypes));
        sort($authoritativeRoomIds, SORT_NUMERIC);
        sort($requestedRoomIds, SORT_NUMERIC);
        if ($authoritativeRoomIds !== $requestedRoomIds) {
            throw new \InvalidArgumentException('price suggestion batch room scope invalid');
        }
        foreach ($requestedRoomTypes as $roomTypeId => $requested) {
            if ((int)($requested['tenant_id'] ?? 0) !== (int)$authoritativeTenantId
                || (int)($requested['hotel_id'] ?? 0) !== $hotelId
                || (int)($roomTypesById[$roomTypeId]['tenant_id'] ?? 0) !== (int)$authoritativeTenantId
                || (int)($roomTypesById[$roomTypeId]['hotel_id'] ?? 0) !== $hotelId
            ) {
                throw new \InvalidArgumentException('price suggestion batch room scope invalid');
            }
        }

        $expectedDedupeByKey = [];
        foreach ($targetDates as $targetDate) {
            foreach (array_keys($roomTypesById) as $roomTypeId) {
                $key = self::batchRecommendationKey((int)$roomTypeId, $targetDate);
                $expectedDedupeByKey[$key] = PriceSuggestion::activeDedupeKey(
                    (int)$authoritativeTenantId,
                    $hotelId,
                    (int)$roomTypeId,
                    $targetDate
                );
            }
        }
        $pendingByDedupe = $this->pendingSuggestionsByDedupeKeys(
            array_values($expectedDedupeByKey),
            (int)$authoritativeTenantId,
            $hotelId
        );
        $pendingByKey = [];
        foreach ($expectedDedupeByKey as $key => $dedupeKey) {
            if (isset($pendingByDedupe[$dedupeKey])) {
                $pendingByKey[$key] = $pendingByDedupe[$dedupeKey];
            }
        }

        $expectedKeySet = array_fill_keys(array_keys($expectedDedupeByKey), true);
        $pendingKeySet = array_fill_keys(array_keys($pendingByKey), true);
        ksort($expectedKeySet, SORT_STRING);
        ksort($pendingKeySet, SORT_STRING);
        if ($pendingKeySet === $expectedKeySet) {
            $skipped = [];
            foreach ($targetDates as $targetDate) {
                foreach ($roomTypesById as $roomTypeId => $roomType) {
                    $key = self::batchRecommendationKey((int)$roomTypeId, $targetDate);
                    $skipped[] = $this->pendingSuggestionSkip(
                        $pendingByKey[$key],
                        (int)$roomTypeId,
                        (string)($roomType['name'] ?? ''),
                        $targetDate
                    );
                }
            }
            return [[], $skipped];
        }

        $recommendations = $this->recommendBatch(
            $hotelId,
            array_values($roomTypesById),
            $targetDates
        );
        $candidates = [];
        $skipped = [];
        $now = date('Y-m-d H:i:s');
        foreach ($targetDates as $targetDate) {
            foreach ($roomTypesById as $roomTypeId => $roomType) {
                $key = self::batchRecommendationKey((int)$roomTypeId, $targetDate);
                $roomTypeName = (string)($roomType['name'] ?? '');
                if (isset($pendingByKey[$key])) {
                    $skipped[] = $this->pendingSuggestionSkip(
                        $pendingByKey[$key],
                        (int)$roomTypeId,
                        $roomTypeName,
                        $targetDate
                    );
                    continue;
                }
                $recommendation = $recommendations[$key] ?? [];
                $exactGaps = $this->exactTargetSignalGaps(
                    $recommendation,
                    (int)$roomTypeId,
                    $targetDate
                );
                if ($exactGaps !== []) {
                    $skipped[] = $this->exactTargetGapSkip(
                        $recommendation,
                        (int)$roomTypeId,
                        $roomTypeName,
                        $targetDate,
                        $exactGaps
                    );
                    continue;
                }
                if (($recommendation['should_create'] ?? false) !== true) {
                    $skipped[] = $this->notCreatedSuggestionSkip(
                        $recommendation,
                        (int)$roomTypeId,
                        $roomTypeName,
                        $targetDate
                    );
                    continue;
                }

                $tenantId = (int)$authoritativeTenantId;
                $activeDedupeKey = PriceSuggestion::activeDedupeKey(
                    $tenantId,
                    $hotelId,
                    (int)$roomTypeId,
                    $targetDate
                );
                $candidates[$key] = [
                    'key' => $key,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'room_type_id' => (int)$roomTypeId,
                    'room_type_name' => $roomTypeName,
                    'target_date' => $targetDate,
                    'active_dedupe_key' => $activeDedupeKey,
                    'recommendation' => $recommendation,
                    'row' => [
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'room_type_id' => (int)$roomTypeId,
                        'suggestion_type' => PriceSuggestion::TYPE_DYNAMIC,
                        'status' => PriceSuggestion::STATUS_PENDING,
                        'suggestion_date' => $targetDate,
                        'current_price' => (float)$recommendation['current_price'],
                        'suggested_price' => (float)$recommendation['suggested_price'],
                        'min_price' => (float)($roomType['min_price'] ?? 0),
                        'max_price' => (float)($roomType['max_price'] ?? 0),
                        'confidence_score' => (float)$recommendation['confidence_score'],
                        'competitor_data' => json_encode(
                            $recommendation['competitor_data'] ?? [],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        ),
                        'factors' => json_encode(
                            $recommendation['factors'] ?? [],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                        ),
                        'demand_forecast_id' => (int)($recommendation['factors']['signals']['demand_forecast']['id'] ?? 0),
                        'reason' => (string)$recommendation['reason'],
                        'active_dedupe_key' => $activeDedupeKey,
                        'create_time' => $now,
                        'update_time' => $now,
                    ],
                ];
            }
        }

        if ($candidates === []) {
            return [[], $skipped];
        }
        [$insertedCandidates, $raceSkips] = $this->insertPendingSuggestionBatch($candidates);
        array_push($skipped, ...$raceSkips);
        if ($insertedCandidates === []) {
            return [[], $skipped];
        }
        $created = $this->readBackPendingSuggestionBatch($insertedCandidates);
        return [$this->markGeneratedSuggestionRows($this->enrichSuggestionRows($created)), $skipped];
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     * @return array{0:array<string,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function insertPendingSuggestionBatch(array $candidates): array
    {
        $raceSkips = [];
        $lastConflict = null;
        for ($attempt = 1; $attempt <= self::PENDING_BATCH_INSERT_ATTEMPTS; $attempt++) {
            $this->beforePendingBatchInsertAttempt($candidates, $attempt);
            try {
                Db::transaction(function () use ($candidates): void {
                    $this->insertCandidateChunks($candidates);
                });
                return [$candidates, $raceSkips];
            } catch (\Throwable $error) {
                if (!$this->isActiveDedupeConflict($error)) {
                    throw $error;
                }
                $lastConflict = $error;
            }

            // Db::transaction has fully rolled back before this exact read.
            // Earlier chunks from the failed attempt cannot masquerade as a
            // concurrent winner; only committed rows are removed as races.
            $first = reset($candidates);
            $raced = $this->pendingSuggestionsByDedupeKeys(
                array_column($candidates, 'active_dedupe_key'),
                (int)($first['tenant_id'] ?? 0),
                (int)($first['hotel_id'] ?? 0)
            );
            foreach ($candidates as $key => $candidate) {
                $dedupeKey = (string)$candidate['active_dedupe_key'];
                if (!isset($raced[$dedupeKey])) {
                    continue;
                }
                $raceSkips[] = $this->pendingSuggestionSkip(
                    $raced[$dedupeKey],
                    (int)$candidate['room_type_id'],
                    (string)$candidate['room_type_name'],
                    (string)$candidate['target_date'],
                    true
                );
                unset($candidates[$key]);
            }
            if ($candidates === []) {
                return [[], $raceSkips];
            }
        }

        throw new \RuntimeException(
            'price_suggestion_batch_dedupe_conflict_exhausted',
            0,
            $lastConflict
        );
    }

    /** @param array<string,array<string,mixed>> $candidates */
    protected function beforePendingBatchInsertAttempt(array $candidates, int $attempt): void
    {
    }

    /** @param array<string,array<string,mixed>> $candidates */
    private function insertCandidateChunks(array $candidates): void
    {
        foreach ($this->chunkCandidatesForInsert($candidates) as $chunk) {
            Db::name('price_suggestions')->insertAll(array_column($chunk, 'row'));
        }
    }

    /**
     * @param array<string,array<string,mixed>> $candidates
     * @return array<int,array<string,array<string,mixed>>>
     */
    private function chunkCandidatesForInsert(array $candidates): array
    {
        $chunks = [];
        $current = [];
        $parameterCount = 0;
        $estimatedBytes = 0;
        foreach ($candidates as $key => $candidate) {
            $row = is_array($candidate['row'] ?? null) ? $candidate['row'] : [];
            $rowParameters = count($row);
            $encoded = json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $rowBytes = (strlen((string)$encoded) * 2) + ($rowParameters * 32);
            if ($rowParameters <= 0
                || $rowParameters > self::DB_BIND_PARAMETER_BUDGET
                || $rowBytes > self::BULK_INSERT_PACKET_BUDGET_BYTES
            ) {
                throw new \RuntimeException('price suggestion batch row exceeds database write budget');
            }
            if ($current !== []
                && ($parameterCount + $rowParameters > self::DB_BIND_PARAMETER_BUDGET
                    || $estimatedBytes + $rowBytes > self::BULK_INSERT_PACKET_BUDGET_BYTES)
            ) {
                $chunks[] = $current;
                $current = [];
                $parameterCount = 0;
                $estimatedBytes = 0;
            }
            $current[$key] = $candidate;
            $parameterCount += $rowParameters;
            $estimatedBytes += $rowBytes;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    private function readBackPendingSuggestionBatch(array $candidates): array
    {
        $first = reset($candidates);
        $persistedByDedupe = $this->pendingSuggestionsByDedupeKeys(
            array_column($candidates, 'active_dedupe_key'),
            (int)($first['tenant_id'] ?? 0),
            (int)($first['hotel_id'] ?? 0)
        );
        $created = [];
        foreach ($candidates as $candidate) {
            $dedupeKey = (string)$candidate['active_dedupe_key'];
            $persisted = $persistedByDedupe[$dedupeKey] ?? null;
            $recommendation = (array)$candidate['recommendation'];
            if (!$persisted instanceof PriceSuggestion
                || (int)$persisted->tenant_id !== (int)$candidate['tenant_id']
                || (int)$persisted->hotel_id !== (int)$candidate['hotel_id']
                || (int)$persisted->room_type_id !== (int)$candidate['room_type_id']
                || (string)$persisted->suggestion_date !== (string)$candidate['target_date']
                || (int)$persisted->status !== PriceSuggestion::STATUS_PENDING
                || (string)($persisted->getData()['active_dedupe_key'] ?? '') !== $dedupeKey
                || abs((float)$persisted->current_price - (float)$recommendation['current_price']) > 0.001
                || abs((float)$persisted->suggested_price - (float)$recommendation['suggested_price']) > 0.001
            ) {
                throw new \RuntimeException('price suggestion saved readback identity mismatch');
            }
            $row = $persisted->toArray();
            $row['risk_level'] = (string)($recommendation['risk_level'] ?? 'medium');
            $row['review_checklist'] = array_values((array)($recommendation['review_checklist'] ?? []));
            $created[] = $row;
        }
        return $created;
    }

    /** @param array<int,string> $dedupeKeys @return array<string,PriceSuggestion> */
    protected function pendingSuggestionsByDedupeKeys(
        array $dedupeKeys,
        int $tenantId = 0,
        int $hotelId = 0
    ): array
    {
        $dedupeKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            $dedupeKeys
        ))));
        if ($dedupeKeys === []) {
            return [];
        }
        $indexed = [];
        foreach (array_chunk($dedupeKeys, self::DB_IN_KEY_CHUNK_SIZE) as $keyChunk) {
            $query = PriceSuggestion::whereIn('active_dedupe_key', $keyChunk)
                ->where('status', PriceSuggestion::STATUS_PENDING);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            if ($hotelId > 0) {
                $query->where('hotel_id', $hotelId);
            }
            foreach ($query->select() as $suggestion) {
                if (!$suggestion instanceof PriceSuggestion) {
                    continue;
                }
                $key = (string)($suggestion->getData()['active_dedupe_key'] ?? '');
                if ($key !== '') {
                    $indexed[$key] = $suggestion;
                }
            }
        }
        return $indexed;
    }

    private function isActiveDedupeConflict(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return (str_contains($message, 'duplicate') || str_contains($message, 'unique constraint'))
            && (str_contains($message, 'active_dedupe_key')
                || str_contains($message, 'uq_price_suggestions_active_dedupe'));
    }

    /** @return array<string,mixed> */
    private function pendingSuggestionSkip(
        PriceSuggestion $suggestion,
        int $roomTypeId,
        string $roomTypeName,
        string $targetDate,
        bool $deduplicated = false
    ): array {
        return [
            'suggestion_date' => $targetDate,
            'target_stay_date' => $targetDate,
            'room_type_id' => $roomTypeId,
            'room_type_name' => $roomTypeName,
            'reason' => 'pending_suggestion_exists',
            'existing_suggestion_id' => (int)$suggestion->id,
            'existing_readback_verified' => (int)$suggestion->id > 0,
            'deduplicated' => $deduplicated,
            'primary_signal_count' => null,
            'price_change_rate' => null,
            'risk_level' => 'medium',
            'data_gaps' => [],
            'review_checklist' => ['Review or close the existing pending suggestion before generating another one.'],
        ];
    }

    /** @param array<string,mixed> $recommendation @return array<int,string> */
    private function exactTargetSignalGaps(
        array $recommendation,
        int $roomTypeId,
        string $targetDate
    ): array {
        $signals = is_array($recommendation['factors']['signals'] ?? null)
            ? $recommendation['factors']['signals'] : [];
        $forecast = is_array($signals['demand_forecast'] ?? null) ? $signals['demand_forecast'] : [];
        $competitor = is_array($signals['competitor'] ?? null) ? $signals['competitor'] : [];
        $gaps = [];
        if (($forecast['data_status'] ?? '') !== 'ok'
            || (string)($forecast['source'] ?? '') !== 'demand_forecasts'
            || (int)($forecast['id'] ?? 0) <= 0
            || (int)($forecast['room_type_id'] ?? 0) !== $roomTypeId
            || (string)($forecast['forecast_date'] ?? '') !== $targetDate
        ) {
            $gaps[] = 'exact_target_room_type_demand_forecast_missing';
        }
        if (($competitor['data_status'] ?? '') !== 'ok'
            || (string)($competitor['source_scope'] ?? '') !== 'room_type'
            || (string)($competitor['source_date'] ?? '') !== $targetDate
            || (int)($competitor['sample_count'] ?? 0) <= 0
        ) {
            $gaps[] = 'exact_target_room_type_competitor_price_missing';
        }
        return $gaps;
    }

    /** @param array<string,mixed> $recommendation @param array<int,string> $exactGaps */
    private function exactTargetGapSkip(
        array $recommendation,
        int $roomTypeId,
        string $roomTypeName,
        string $targetDate,
        array $exactGaps
    ): array {
        return [
            'suggestion_date' => $targetDate,
            'target_stay_date' => $targetDate,
            'room_type_id' => $roomTypeId,
            'room_type_name' => $roomTypeName,
            'reason' => 'exact_target_signals_missing',
            'primary_signal_count' => (int)($recommendation['primary_signal_count'] ?? 0),
            'price_change_rate' => array_key_exists('price_change_rate', $recommendation)
                ? (float)$recommendation['price_change_rate'] : null,
            'risk_level' => 'high',
            'data_gaps' => array_values(array_unique(array_merge(
                (array)($recommendation['factors']['signals']['data_gaps'] ?? []),
                $exactGaps
            ))),
            'review_checklist' => [
                'Provide same-hotel, same-room-type, same-target-date demand and competitor evidence.',
            ],
        ];
    }

    /** @param array<string,mixed> $recommendation */
    private function notCreatedSuggestionSkip(
        array $recommendation,
        int $roomTypeId,
        string $roomTypeName,
        string $targetDate
    ): array {
        return [
            'suggestion_date' => $targetDate,
            'target_stay_date' => $targetDate,
            'room_type_id' => $roomTypeId,
            'room_type_name' => $roomTypeName,
            'reason' => (string)($recommendation['skip_reason'] ?? 'not_created'),
            'primary_signal_count' => (int)($recommendation['primary_signal_count'] ?? 0),
            'price_change_rate' => array_key_exists('price_change_rate', $recommendation)
                ? (float)$recommendation['price_change_rate'] : null,
            'risk_level' => (string)($recommendation['risk_level'] ?? 'high'),
            'data_gaps' => array_values((array)($recommendation['factors']['signals']['data_gaps'] ?? [])),
            'review_checklist' => array_values((array)($recommendation['review_checklist'] ?? [])),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function markGeneratedSuggestionRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $targetDate = (string)($row['suggestion_date'] ?? '');
            $complete = (int)($row['id'] ?? 0) > 0
                && (int)($row['tenant_id'] ?? 0) > 0
                && (int)($row['hotel_id'] ?? 0) > 0
                && (int)($row['room_type_id'] ?? 0) > 0
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetDate) === 1
                && (float)($row['current_price'] ?? 0) > 0
                && (float)($row['suggested_price'] ?? 0) > 0
                && (int)($row['status'] ?? 0) > 0;
            $row['target_stay_date'] = $targetDate;
            $row['persistence'] = [
                'saved' => (int)($row['id'] ?? 0) > 0,
                'storage' => 'price_suggestions',
                'loaded_from_storage' => (int)($row['id'] ?? 0) > 0,
                'exact_identity_complete' => $complete,
                'readback_verified' => $complete,
            ];
            $row['advisory_only'] = true;
            $row['manual_review_required'] = true;
            $row['auto_write_ota'] = false;
            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function hotelPricingModelSummary(int $hotelId, string $targetDate): array
    {
        $signals = $this->hotelSignals($hotelId, $targetDate);

        return [
            'advisory_only' => true,
            'model' => self::MODEL_VERSION,
            'target_date' => $targetDate,
            'create_policy' => [
                'minimum_primary_signal_count' => self::MIN_PRIMARY_SIGNAL_COUNT,
                'minimum_price_change_amount' => self::MIN_MATERIAL_CHANGE,
                'max_single_change_rate' => self::MAX_CHANGE_RATE,
            ],
            'pickup_curve' => $signals['pickup'] ?? [],
            'price_elasticity' => $signals['elasticity'] ?? [],
            'backtest' => $signals['backtest'] ?? [],
            'holiday' => $signals['holiday'] ?? [],
            'history_data_status' => $signals['history_data_status'] ?? 'unknown',
            'source_policy' => $signals['source_policy'] ?? [],
            'data_gaps' => $signals['data_gaps'] ?? [],
        ];
    }

    /**
     * Pure recommendation step. Kept public so tests can cover model behavior without a database.
     *
     * @param array<string, mixed> $roomType
     * @param array<string, mixed> $signals
     * @return array<string, mixed>
     */
    public function recommendFromSignals(array $roomType, array $signals): array
    {
        $currentPrice = $this->toFloat($roomType['base_price'] ?? 0);
        $minPrice = $this->toFloat($roomType['min_price'] ?? 0);
        $maxPrice = $this->toFloat($roomType['max_price'] ?? 0);
        if ($currentPrice <= 0) {
            return $this->emptyRecommendation($roomType, $signals, 'current_price_missing');
        }

        $changeRate = 0.0;
        $factorNotes = [];
        $drivers = [];

        $forecast = $signals['demand_forecast'] ?? [];
        $occupancy = $this->toNullableFloat($forecast['predicted_occupancy'] ?? null);
        if ($occupancy !== null && $occupancy > 0) {
            if ($occupancy >= 90) {
                $changeRate += 0.14;
                $factorNotes[] = 'demand_forecast:occupancy>=90';
                $drivers[] = $this->driver('demand_forecast', 'occupancy>=90', 0.14, 'increase');
            } elseif ($occupancy >= 80) {
                $changeRate += 0.10;
                $factorNotes[] = 'demand_forecast:occupancy>=80';
                $drivers[] = $this->driver('demand_forecast', 'occupancy>=80', 0.10, 'increase');
            } elseif ($occupancy <= 45) {
                $changeRate -= 0.08;
                $factorNotes[] = 'demand_forecast:occupancy<=45';
                $drivers[] = $this->driver('demand_forecast', 'occupancy<=45', -0.08, 'decrease');
            } elseif ($occupancy <= 60) {
                $changeRate -= 0.04;
                $factorNotes[] = 'demand_forecast:occupancy<=60';
                $drivers[] = $this->driver('demand_forecast', 'occupancy<=60', -0.04, 'decrease');
            }
        }

        $pickup = $signals['pickup'] ?? [];
        $paceIndex = $this->toNullableFloat($pickup['pace_index'] ?? null);
        if ($paceIndex !== null && ($pickup['data_status'] ?? '') === 'ok') {
            if ($paceIndex >= 130) {
                $changeRate += 0.06;
                $factorNotes[] = 'pickup_curve:pace_index>=130';
                $drivers[] = $this->driver('pickup_curve', 'pace_index>=130', 0.06, 'increase');
            } elseif ($paceIndex >= 110) {
                $changeRate += 0.03;
                $factorNotes[] = 'pickup_curve:pace_index>=110';
                $drivers[] = $this->driver('pickup_curve', 'pace_index>=110', 0.03, 'increase');
            } elseif ($paceIndex <= 70) {
                $changeRate -= 0.06;
                $factorNotes[] = 'pickup_curve:pace_index<=70';
                $drivers[] = $this->driver('pickup_curve', 'pace_index<=70', -0.06, 'decrease');
            } elseif ($paceIndex <= 90) {
                $changeRate -= 0.03;
                $factorNotes[] = 'pickup_curve:pace_index<=90';
                $drivers[] = $this->driver('pickup_curve', 'pace_index<=90', -0.03, 'decrease');
            }
        }

        $competitor = $signals['competitor'] ?? [];
        $gapPercent = $this->toNullableFloat($competitor['gap_percent'] ?? null);
        if ($gapPercent !== null && ($competitor['data_status'] ?? '') === 'ok') {
            if ($gapPercent >= 10) {
                $changeRate += 0.05;
                $factorNotes[] = 'competitor_price:avg>=current+10%';
                $drivers[] = $this->driver('competitor_price', 'avg>=current+10%', 0.05, 'increase');
            } elseif ($gapPercent <= -10) {
                $changeRate -= 0.05;
                $factorNotes[] = 'competitor_price:avg<=current-10%';
                $drivers[] = $this->driver('competitor_price', 'avg<=current-10%', -0.05, 'decrease');
            }
        }

        $holiday = $signals['holiday'] ?? [];
        if (($holiday['data_status'] ?? '') === 'ok') {
            if (!empty($holiday['is_in_holiday'])) {
                $changeRate += 0.08;
                $factorNotes[] = 'holiday:in_holiday';
                $drivers[] = $this->driver('holiday', 'in_holiday', 0.08, 'increase');
            } elseif (!empty($holiday['is_holiday_window'])) {
                $changeRate += 0.04;
                $factorNotes[] = 'holiday:near_holiday';
                $drivers[] = $this->driver('holiday', 'near_holiday', 0.04, 'increase');
            } elseif (!empty($holiday['is_weekend'])) {
                $changeRate += 0.03;
                $factorNotes[] = 'holiday:weekend';
                $drivers[] = $this->driver('holiday', 'weekend', 0.03, 'increase');
            }
        }

        $inventory = $signals['inventory'] ?? [];
        $utilization = $this->toNullableFloat($inventory['utilization_percent'] ?? null);
        if ($utilization !== null && ($inventory['data_status'] ?? '') === 'ok') {
            if ($utilization >= 95) {
                $changeRate += 0.08;
                $factorNotes[] = 'inventory:utilization>=95';
                $drivers[] = $this->driver('inventory', 'utilization>=95', 0.08, 'increase');
            } elseif ($utilization >= 85) {
                $changeRate += 0.04;
                $factorNotes[] = 'inventory:utilization>=85';
                $drivers[] = $this->driver('inventory', 'utilization>=85', 0.04, 'increase');
            } elseif ($utilization <= 45) {
                $changeRate -= 0.06;
                $factorNotes[] = 'inventory:utilization<=45';
                $drivers[] = $this->driver('inventory', 'utilization<=45', -0.06, 'decrease');
            }
        }

        $elasticity = $signals['elasticity'] ?? [];
        $elasticityValue = $this->toNullableFloat($elasticity['elasticity'] ?? null);
        if ($elasticityValue !== null && ($elasticity['data_status'] ?? '') === 'ok') {
            if ($changeRate > 0 && $elasticityValue <= -1.5) {
                $changeRate -= 0.04;
                $factorNotes[] = 'price_elasticity:sensitive_cap_increase';
                $drivers[] = $this->driver('price_elasticity', 'sensitive_cap_increase', -0.04, 'decrease');
            } elseif ($changeRate < 0 && $elasticityValue <= -1.0) {
                $changeRate -= 0.03;
                $factorNotes[] = 'price_elasticity:sensitive_support_discount';
                $drivers[] = $this->driver('price_elasticity', 'sensitive_support_discount', -0.03, 'decrease');
            } elseif ($changeRate > 0 && $elasticityValue > -0.5 && $elasticityValue < 0) {
                $changeRate += 0.03;
                $factorNotes[] = 'price_elasticity:inelastic_support_increase';
                $drivers[] = $this->driver('price_elasticity', 'inelastic_support_increase', 0.03, 'increase');
            } elseif ($elasticityValue >= 0) {
                $factorNotes[] = 'price_elasticity:non_negative_manual_review';
            }
        }

        $changeRate = max(-self::MAX_CHANGE_RATE, min(self::MAX_CHANGE_RATE, $changeRate));
        $rawSuggested = round($currentPrice * (1 + $changeRate), 2);
        $suggested = $rawSuggested;
        $constraints = [
            'max_single_change_rate' => self::MAX_CHANGE_RATE,
            'min_material_change' => self::MIN_MATERIAL_CHANGE,
        ];
        if ($minPrice > 0 && $suggested < $minPrice) {
            $suggested = $minPrice;
            $constraints['applied_min_price'] = $minPrice;
        }
        if ($maxPrice > 0 && $suggested > $maxPrice) {
            $suggested = $maxPrice;
            $constraints['applied_max_price'] = $maxPrice;
        }

        $priceDelta = round($suggested - $currentPrice, 2);
        $primarySignalCount = $this->primaryDriverCount($drivers);
        $confidence = $this->confidenceScore($signals);
        $direction = $priceDelta > 0 ? 'increase' : ($priceDelta < 0 ? 'decrease' : 'hold');
        $skipReason = $this->skipReason($priceDelta, $factorNotes, $primarySignalCount);
        $shouldCreate = $skipReason === '';
        $riskLevel = $this->riskLevel($confidence, $signals, $primarySignalCount);
        $reviewChecklist = $this->reviewChecklist($signals, $drivers, $riskLevel);

        return [
            'should_create' => $shouldCreate,
            'skip_reason' => $skipReason,
            'advisory_only' => true,
            'action' => $direction,
            'current_price' => round($currentPrice, 2),
            'suggested_price' => round($suggested, 2),
            'raw_suggested_price' => $rawSuggested,
            'price_change_rate' => $currentPrice > 0 ? round($priceDelta / $currentPrice * 100, 2) : 0.0,
            'confidence_score' => $confidence,
            'risk_level' => $riskLevel,
            'reason' => $this->buildReason($direction, $factorNotes, $signals),
            'factor_notes' => $factorNotes,
            'drivers' => $drivers,
            'review_checklist' => $reviewChecklist,
            'primary_signal_count' => $primarySignalCount,
            'competitor_data' => $competitor,
            'factors' => [
                'model' => self::MODEL_VERSION,
                'advisory_only' => true,
                'target' => [
                    'action' => $direction,
                    'current_price' => round($currentPrice, 2),
                    'suggested_price' => round($suggested, 2),
                    'price_change_rate' => $currentPrice > 0 ? round($priceDelta / $currentPrice * 100, 2) : 0.0,
                ],
                'signals' => $signals,
                'factor_notes' => $factorNotes,
                'drivers' => $drivers,
                'confidence_score' => $confidence,
                'risk_level' => $riskLevel,
                'review_checklist' => $reviewChecklist,
                'primary_signal_count' => $primarySignalCount,
                'constraints' => $constraints,
                'create_policy' => [
                    'minimum_primary_signal_count' => self::MIN_PRIMARY_SIGNAL_COUNT,
                    'minimum_price_change_amount' => self::MIN_MATERIAL_CHANGE,
                    'max_single_change_rate' => self::MAX_CHANGE_RATE,
                ],
                'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $suggestion
     * @param array<string, mixed>|null $executionItem
     * @return array<string, mixed>
     */
    public function buildSuggestionReadiness(array $suggestion, ?array $executionItem = null): array
    {
        $factors = is_array($suggestion['factors'] ?? null) ? $suggestion['factors'] : [];
        $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
        $dataGaps = array_values(array_filter(array_map('strval', (array)($signals['data_gaps'] ?? []))));
        $status = (int)($suggestion['status'] ?? 0);
        $riskLevel = strtolower((string)($factors['risk_level'] ?? $suggestion['risk_level'] ?? ''));
        $confidence = $this->toNullableFloat($factors['confidence_score'] ?? $suggestion['confidence_score'] ?? null);
        $primarySignalCount = (int)($factors['primary_signal_count'] ?? $suggestion['primary_signal_count'] ?? 0);
        $advisoryBoundary = (string)($factors['decision_boundary'] ?? '') === 'manual_review_required_no_auto_rate_write';
        $sourceReady = $primarySignalCount >= self::MIN_PRIMARY_SIGNAL_COUNT && empty($dataGaps);
        $riskClear = !in_array($riskLevel, ['high', 'medium_high'], true)
            && ($confidence === null || $confidence >= 0.6);
        $approved = in_array($status, [2, 4], true);
        $appliedLocal = $status === 4;
        $executionLinked = is_array($executionItem) && !empty($executionItem);
        $executionStage = $executionLinked ? (string)($executionItem['stage'] ?? '') : '';
        $evidenceReady = (int)($executionItem['evidence']['count'] ?? 0) > 0;
        $roiReady = (string)($executionItem['roi']['status'] ?? '') === 'ready';

        $checks = [
            $this->readinessCheck('pricing_signal', '调价信号', $sourceReady, '已满足主信号数量且无阻断性数据缺口', '先补齐需求预测、拾取、竞价、库存或弹性样本。', 20),
            $this->readinessCheck('advisory_boundary', '人工边界', $advisoryBoundary, '已标记为仅建议、禁止自动写 OTA 房价', '保留 manual_review_required_no_auto_rate_write 边界。', 10),
            $this->readinessCheck('risk_recheck', '风险复核', $riskClear, '置信度和风险等级未触发阻断', '先复核高风险、低置信度或数据缺口后再审批。', 15),
            $this->readinessCheck('manual_approval', '人工审批', $approved, '建议已通过人工审批或进入应用状态', '先完成批准/拒绝，不把待审建议当作执行动作。', 15),
            $this->readinessCheck('execution_intent', '执行意图', $executionLinked, '已关联运营执行意图', '创建执行意图，进入审批、执行、证据、复盘链路。', 15),
            $this->readinessCheck('local_price_applied', '本地价格应用', $appliedLocal, '已更新本地房型基础价', '如确认执行，先应用到本地房型价；OTA 仍需人工执行证据。', 10),
            $this->readinessCheck('execution_evidence', '执行证据', $evidenceReady, '已记录执行证据', '补充 OTA 后台、房价日历或执行截图等证据。', 10),
            $this->readinessCheck('roi_review', '效果复盘', $roiReady, '已形成 ROI/效果复盘', '完成调价后效果复盘，确认收入、间夜、ADR 或转化变化。', 5),
        ];

        $missingEvidence = [];
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += (int)$check['weight'];
                continue;
            }
            $missingEvidence[] = [
                'code' => $check['key'],
                'label' => $check['label'],
                'next_action' => $check['next_action'],
            ];
        }

        $stage = $this->pricingReadinessStage(
            (int)($suggestion['id'] ?? 0),
            $status,
            $sourceReady,
            $riskClear,
            $approved,
            $executionLinked,
            $executionStage,
            $appliedLocal,
            $evidenceReady,
            $roiReady
        );

        return [
            'stage' => $stage,
            'status_label' => $this->pricingReadinessStageLabel($stage),
            'score' => $score,
            'ready_for_review' => in_array($stage, ['approved_pending_execution', 'evidence_ready', 'pricing_ready'], true),
            'execution_intent_ready' => $stage === 'approved_pending_execution',
            'pricing_ready' => $stage === 'pricing_ready',
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => $missingEvidence[0]['next_action'] ?? '持续复盘调价效果，并保留执行证据。',
            'notice' => $this->pricingReadinessNotice($stage),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $executionItemsByRecordId
     * @return array<int, array<string, mixed>>
     */
    public function enrichSuggestionRows(array $rows, array $executionItemsByRecordId = []): array
    {
        return array_map(function (array $row) use ($executionItemsByRecordId): array {
            $row = $this->normalizeSuggestionDisplayFields($row);
            $id = (int)($row['id'] ?? 0);
            $row['pricing_readiness'] = $this->buildSuggestionReadiness($row, $executionItemsByRecordId[$id] ?? null);
            $row = $this->enrichSuggestionDecisionQuality($row);
            return $row;
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function enrichSuggestionDecisionQuality(array $row): array
    {
        $factors = is_array($row['factors'] ?? null)
            ? $row['factors']
            : $this->decodeJsonObject($row['factors'] ?? null);
        $row['factors'] = $factors;
        $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $targetDate = trim((string)($row['suggestion_date'] ?? ''));
        $authoritativeSignals = $hotelId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1
            ? $this->hotelSignals($hotelId, $targetDate)
            : [];
        $evidenceSources = [];
        $historyEvidence = is_array($authoritativeSignals['history_evidence'] ?? null)
            ? $authoritativeSignals['history_evidence']
            : [];
        if ($historyEvidence !== []) {
            $evidenceSources[] = $historyEvidence;
        }

        $roomType = is_array($row['room_type'] ?? null) ? $row['room_type'] : [];
        $roomName = trim((string)($roomType['name'] ?? $row['room_type_name'] ?? '目标房型'));
        $currentPrice = $this->toFloat($row['current_price'] ?? 0);
        $suggestedPrice = $this->toFloat($row['suggested_price'] ?? 0);
        $manualReview = is_array($factors['manual_review'] ?? null) ? $factors['manual_review'] : [];
        if (in_array((string)($manualReview['action'] ?? ''), ['approve', 'approve_with_changes'], true)
            && $this->toFloat($manualReview['approved_price'] ?? 0) > 0
        ) {
            $suggestedPrice = $this->toFloat($manualReview['approved_price']);
        }
        $riskLevel = strtolower(trim((string)($factors['risk_level'] ?? $row['risk_level'] ?? 'medium')));
        $readiness = is_array($row['pricing_readiness'] ?? null) ? $row['pricing_readiness'] : [];
        $primaryDrivers = [];
        foreach ((array)($factors['drivers'] ?? []) as $driver) {
            if (!is_array($driver)) {
                continue;
            }
            $signal = trim((string)($driver['signal'] ?? ''));
            if (in_array($signal, ['demand_forecast', 'pickup_curve', 'competitor_price', 'inventory', 'price_elasticity'], true)) {
                $primaryDrivers[$signal] = true;
            }
        }
        $verifiedDriverSignals = [
            'pickup_curve' => (string)($authoritativeSignals['pickup']['data_status'] ?? '') === 'ok',
            'price_elasticity' => (string)($authoritativeSignals['elasticity']['data_status'] ?? '') === 'ok',
        ];
        $unsupportedDrivers = array_values(array_filter(
            array_keys($primaryDrivers),
            static fn(string $driver): bool => ($verifiedDriverSignals[$driver] ?? false) !== true
        ));
        $serverEvidenceReady = $historyEvidence !== []
            && count($primaryDrivers) >= self::MIN_PRIMARY_SIGNAL_COUNT
            && $unsupportedDrivers === [];
        $upstreamReady = ($readiness['ready_for_review'] ?? false) === true && $serverEvidenceReady;
        $context = [
            'scope' => 'ota_channel',
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_date' => $targetDate,
            'basis_summary' => trim((string)($row['reason'] ?? '')) ?: '依据当前房型的需求、竞价、库存、拾取、弹性与日历信号生成。',
            'evidence_sources' => $evidenceSources,
            'default_priority' => $riskLevel === 'high' ? 'P0' : 'P1',
            'default_risk_level' => $riskLevel,
            'review_window' => '人工执行后观察7天，并按同酒店、同携程房型、同入住日口径复核订单、间夜、ADR与渠道收入',
            'expected_effect_policy' => [
                'status' => 'verification_target',
                'metric' => 'ota_revenue',
                'direction' => 'verify',
                'summary' => '预期效果是核验本次携程调价对订单、间夜、ADR与渠道收入组合的影响；完成同口径前后回读前不承诺提升幅度。',
                'review_window' => '人工执行后观察7天，并按同酒店、同携程房型、同入住日口径复核订单、间夜、ADR与渠道收入',
            ],
        ];
        $action = sprintf(
            '人工复核后，将%s在%s的携程目标价从¥%s调整为¥%s；本建议不自动写入OTA。',
            $roomName,
            $targetDate !== '' ? $targetDate : '目标日',
            rtrim(rtrim(number_format($currentPrice, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($suggestedPrice, 2, '.', ''), '0'), '.')
        );
        $recommendations = $this->decisionQualityService->enrichRecommendations([[
            'title' => $roomName . '携程调价建议',
            'action' => $action,
            'priority' => $riskLevel === 'high' ? 'P0' : 'P1',
            'reason' => (string)$context['basis_summary'],
            'action_type' => 'price_adjustment',
            'object_type' => 'price',
            'platform' => 'ctrip',
            'target_date' => $targetDate,
            'room_type_name' => $roomName,
            'recommendation_type' => 'operation',
            'expected_metric' => 'ota_revenue',
            'risk' => [
                'level' => $riskLevel !== '' ? $riskLevel : 'medium',
                'summary' => '调价可能牺牲ADR或订单转化；房型、入住日、早餐和取消政策不可比也会造成错误判断。',
                'controls' => array_values((array)($factors['review_checklist'] ?? $row['review_checklist'] ?? [])),
            ],
            'can_create_execution_intent' => $upstreamReady,
            'blocked_reason' => $upstreamReady
                ? ''
                : ($serverEvidenceReady
                    ? (string)($readiness['notice'] ?? '')
                    : '调价驱动尚未全部绑定到同酒店、同携程渠道的数据库回读证据；当前仅允许展示和人工复核。'),
        ]], $context);

        $decisionRecommendation = is_array($recommendations[0] ?? null) ? $recommendations[0] : [];
        $trustedDecision = $this->buildTrustedDecisionEnvelope(
            $row,
            $decisionRecommendation,
            $factors,
            $authoritativeSignals,
            $unsupportedDrivers,
            $serverEvidenceReady
        );
        $decisionRecommendation['trusted_decision'] = $trustedDecision;
        $decisionRecommendation['can_human_confirm'] = ($trustedDecision['human_confirmation']['can_confirm'] ?? false) === true;
        $row['decision_recommendation'] = $decisionRecommendation;
        $row['trusted_decision'] = $trustedDecision;
        $row['recommendation_quality'] = $this->decisionQualityService->summarize($recommendations, $context);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $recommendation
     * @param array<string, mixed> $factors
     * @param array<string, mixed> $authoritativeSignals
     * @param array<int, string> $unsupportedDrivers
     * @return array<string, mixed>
     */
    private function buildTrustedDecisionEnvelope(
        array $row,
        array $recommendation,
        array $factors,
        array $authoritativeSignals,
        array $unsupportedDrivers,
        bool $serverEvidenceReady
    ): array {
        $hotelId = (int)($row['hotel_id'] ?? 0);
        $hotelName = trim((string)($row['hotel_name'] ?? $row['store_name'] ?? ''));
        if ($hotelName === '' && $hotelId > 0) {
            $hotelName = $this->trustedDecisionHotelName($hotelId);
        }
        $targetDate = trim((string)($row['suggestion_date'] ?? ''));
        $statusCode = (int)($row['status'] ?? 0);
        $dataBasis = is_array($recommendation['data_basis'] ?? null) ? $recommendation['data_basis'] : [];
        $decisionQuality = is_array($recommendation['decision_quality'] ?? null) ? $recommendation['decision_quality'] : [];
        $expectedEffect = is_array($recommendation['expected_effect'] ?? null) ? $recommendation['expected_effect'] : [];
        $signals = is_array($factors['signals'] ?? null) ? $factors['signals'] : [];
        $currentPrice = $this->toNullableFloat($row['current_price'] ?? null);
        $suggestedPrice = $this->toNullableFloat($row['suggested_price'] ?? null);
        $manualReview = is_array($factors['manual_review'] ?? null) ? $factors['manual_review'] : [];
        if (in_array((string)($manualReview['action'] ?? ''), ['approve', 'approve_with_changes'], true)) {
            $approvedPrice = $this->toNullableFloat($manualReview['approved_price'] ?? null);
            if ($approvedPrice !== null && $approvedPrice > 0) {
                $suggestedPrice = $approvedPrice;
            }
        }
        $metricFormula = $this->trustedPriceChangeFormula($currentPrice, $suggestedPrice);
        $confidence = $this->toNullableFloat($factors['confidence_score'] ?? $row['confidence_score'] ?? null);
        if ($confidence !== null && $confidence > 1 && $confidence <= 100) {
            $confidence /= 100;
        }
        if ($confidence !== null && ($confidence < 0 || $confidence > 1)) {
            $confidence = null;
        }

        $gapCodes = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            (array)($signals['data_gaps'] ?? $authoritativeSignals['data_gaps'] ?? [])
        )));
        foreach ($unsupportedDrivers as $driver) {
            $gapCodes[] = 'driver_unverified:' . $driver;
        }
        foreach ((array)($decisionQuality['missing_fields'] ?? []) as $field) {
            $field = trim((string)$field);
            if ($field !== '') {
                $gapCodes[] = $field;
            }
        }
        if (($dataBasis['status'] ?? 'missing') !== 'verified') {
            $gapCodes[] = 'data_basis_' . trim((string)($dataBasis['status'] ?? 'missing'));
        }
        if (($metricFormula['status'] ?? '') !== 'calculable') {
            $gapCodes[] = (string)($metricFormula['reason'] ?? 'metric_denominator_missing');
        }
        if ($confidence === null) {
            $gapCodes[] = 'confidence_missing';
        }
        $gapCodes = $this->uniqueStrings($gapCodes);
        $gaps = array_map(fn(string $code): array => [
            'code' => $code,
            'message' => $this->trustedDecisionGapMessage($code),
            'blocking' => true,
        ], $gapCodes);

        $qualityStatus = trim((string)($dataBasis['status'] ?? 'missing'));
        $inputReady = $serverEvidenceReady
            && $qualityStatus === 'verified'
            && ($decisionQuality['complete'] ?? false) === true
            && ($metricFormula['status'] ?? '') === 'calculable'
            && $confidence !== null;
        $canConfirm = $statusCode === \app\model\PriceSuggestion::STATUS_PENDING && $inputReady;
        $canTransferToTask = $statusCode === \app\model\PriceSuggestion::STATUS_APPROVED
            && ($recommendation['can_create_execution_intent'] ?? false) === true
            && ($decisionQuality['execution_ready'] ?? false) === true;
        $confirmed = in_array($statusCode, [
            \app\model\PriceSuggestion::STATUS_APPROVED,
            \app\model\PriceSuggestion::STATUS_APPLIED,
        ], true);
        $refs = is_array($dataBasis['refs'] ?? null) ? array_values($dataBasis['refs']) : [];
        $storeDisplay = $hotelName !== ''
            ? $hotelName . ($hotelId > 0 ? ' (#' . $hotelId . ')' : '')
            : ($hotelId > 0 ? '门店 #' . $hotelId : '门店未绑定');

        return [
            'contract_version' => self::TRUSTED_DECISION_CONTRACT_VERSION,
            'scope' => 'ota_channel',
            'store' => [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName !== '' ? $hotelName : null,
                'display' => $storeDisplay,
            ],
            'platform' => [
                'key' => 'ctrip',
                'label' => '携程',
                'scope' => 'ota_channel',
            ],
            'date' => [
                'value' => $targetDate !== '' ? $targetDate : null,
                'basis' => 'suggestion_date',
                'status' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1 ? 'available' : 'missing',
            ],
            'sources' => [
                'status' => $qualityStatus,
                'summary' => trim((string)($dataBasis['summary'] ?? '')),
                'items' => $refs,
                'ref_count' => count($refs),
            ],
            'metric_formula' => $metricFormula,
            'data_quality' => [
                'status' => $qualityStatus,
                'label' => $this->trustedDecisionQualityLabel($qualityStatus),
                'decision_eligible' => $serverEvidenceReady && $qualityStatus === 'verified',
                'note' => trim((string)($dataBasis['quality_note'] ?? '')),
            ],
            'confidence' => [
                'score' => $confidence !== null ? round($confidence, 4) : null,
                'percentage' => $confidence !== null ? round($confidence * 100, 2) : null,
                'display' => $confidence !== null
                    ? rtrim(rtrim(number_format($confidence * 100, 2, '.', ''), '0'), '.') . '%'
                    : '不可计算',
                'level' => $confidence === null ? 'unknown' : ($confidence >= 0.8 ? 'high' : ($confidence >= 0.6 ? 'medium' : 'low')),
                'status' => $confidence !== null ? 'available' : 'missing',
                'basis' => 'model_signal_confidence',
            ],
            'gaps' => $gaps,
            'recommended_action' => [
                'summary' => trim((string)($recommendation['action'] ?? '')),
                'action_type' => trim((string)($recommendation['action_type'] ?? 'price_adjustment')),
                'current_price' => $currentPrice,
                'target_price' => $suggestedPrice,
                'manual_only' => true,
                'auto_write_ota' => false,
            ],
            'expected_effect' => array_merge($expectedEffect, [
                'display' => trim((string)($expectedEffect['summary'] ?? '')) ?: '待执行后按同口径回读验证',
            ]),
            'human_confirmation' => [
                'required' => true,
                'confirmed' => $confirmed,
                'can_confirm' => $canConfirm,
                'can_transfer_to_operation_task' => $canTransferToTask,
                'status' => $confirmed ? 'confirmed' : ($canConfirm ? 'ready' : 'blocked'),
                'operation_task_status_after_transfer' => 'pending_execute',
                'auto_write_ota' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function trustedPriceChangeFormula(?float $currentPrice, ?float $suggestedPrice): array
    {
        $calculable = $currentPrice !== null && $currentPrice > 0 && $suggestedPrice !== null && $suggestedPrice > 0;
        $value = $calculable ? round((($suggestedPrice - $currentPrice) / $currentPrice) * 100, 2) : null;
        $reason = '';
        if ($currentPrice === null || $currentPrice <= 0) {
            $reason = 'current_price_denominator_missing';
        } elseif ($suggestedPrice === null || $suggestedPrice <= 0) {
            $reason = 'suggested_price_numerator_missing';
        }

        return [
            'metric' => 'price_change_rate',
            'label' => '价格调整率',
            'expression' => '(建议价 - 当前价) ÷ 当前价 × 100%',
            'numerator' => $suggestedPrice !== null && $currentPrice !== null ? round($suggestedPrice - $currentPrice, 2) : null,
            'denominator' => $currentPrice,
            'denominator_required' => true,
            'value' => $value,
            'unit' => '%',
            'status' => $calculable ? 'calculable' : 'not_calculable',
            'display' => $calculable
                ? (($value ?? 0) > 0 ? '+' : '') . rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') . '%'
                : '不可计算',
            'reason' => $reason,
        ];
    }

    private function trustedDecisionHotelName(int $hotelId): string
    {
        if ($hotelId <= 0) {
            return '';
        }
        if (array_key_exists($hotelId, $this->hotelNameCache)) {
            return $this->hotelNameCache[$hotelId];
        }
        try {
            $name = trim((string)(Db::name('hotels')->where('id', $hotelId)->value('name') ?? ''));
        } catch (\Throwable) {
            $name = '';
        }

        return $this->hotelNameCache[$hotelId] = $name;
    }

    private function trustedDecisionQualityLabel(string $status): string
    {
        return match ($status) {
            'verified', 'readback_verified', 'decision_eligible' => '已验证',
            'partial' => '部分可用',
            'stale' => '已过期',
            'binding_missing' => '绑定缺失',
            'unverified' => '未验证',
            default => '缺失',
        };
    }

    private function trustedDecisionGapMessage(string $code): string
    {
        if (str_starts_with($code, 'driver_unverified:')) {
            return '建议驱动未绑定到同门店、同平台、同日期范围的数据库回读证据：' . substr($code, strlen('driver_unverified:'));
        }

        return match ($code) {
            'current_price_denominator_missing' => '当前价分母缺失，价格调整率不可计算',
            'suggested_price_numerator_missing' => '建议价分子缺失，价格调整率不可计算',
            'confidence_missing' => '模型置信度缺失',
            'data_basis_verification', 'data_basis_unverified' => '来源尚未通过数据库回读验证',
            'data_basis_missing' => '来源证据缺失',
            'data_basis_partial' => '来源证据仅部分可用',
            'data_basis_stale' => '来源证据已过期',
            'data_basis_binding_missing' => '来源与门店、平台或日期绑定缺失',
            'expected_effect_evidence' => '预期效果仍需执行后按同口径回读验证',
            'risk_controls' => '风险控制项不完整',
            default => $code,
        };
    }

    /** @return array<string, mixed> */
    public function aggregateSuggestionEffect(int $hotelId, string $startDate, string $endDate): array
    {
        try {
            $history = $this->trustedOtaFacts->pricingHistory($hotelId, $startDate, $endDate);
        } catch (\Throwable) {
            $history = [
                'data_status' => 'blocked',
                'rows' => [],
                'data_gaps' => ['pricing_effect_review_read_failed'],
                'source_policy' => [],
                'data_quality' => [],
            ];
        }

        $rows = array_values(array_filter(
            is_array($history['rows'] ?? null) ? $history['rows'] : [],
            static fn(mixed $row): bool => is_array($row)
        ));
        $sums = ['amount' => 0.0, 'quantity' => 0.0, 'orders' => 0.0];
        $observations = ['amount' => 0, 'quantity' => 0, 'orders' => 0];
        foreach ($rows as $row) {
            foreach (['amount' => 'amount', 'quantity' => 'quantity', 'book_order_num' => 'orders'] as $source => $metric) {
                $value = $row[$source] ?? null;
                if (!is_numeric($value)) {
                    continue;
                }
                $sums[$metric] += (float)$value;
                $observations[$metric]++;
            }
        }

        $amount = $observations['amount'] > 0 ? round($sums['amount'], 2) : null;
        $quantity = $observations['quantity'] > 0 ? (int)round($sums['quantity']) : null;
        $orders = $observations['orders'] > 0 ? (int)round($sums['orders']) : null;
        $trustedFactCount = count($rows);
        $historyStatus = strtolower(trim((string)($history['data_status'] ?? 'blocked')));
        $explainable = $trustedFactCount > 0 && array_sum($observations) > 0;
        $evidenceStatus = $historyStatus === 'ready' && $explainable
            ? 'trusted_deduplicated'
            : ($trustedFactCount > 0 ? 'partial' : 'missing');
        $dataStatus = $evidenceStatus === 'trusted_deduplicated'
            ? 'ok'
            : ($historyStatus === 'blocked' ? 'read_failed' : ($trustedFactCount > 0 ? 'partial' : 'no_sample'));
        $dataGaps = array_values(array_unique(array_filter(array_map(
            'strval',
            is_array($history['data_gaps'] ?? null) ? $history['data_gaps'] : []
        ))));
        if ($trustedFactCount === 0) {
            $dataGaps[] = 'trusted_deduplicated_operating_facts_missing';
        }
        if ($trustedFactCount > 0 && array_sum($observations) === 0) {
            $dataGaps[] = 'explainable_operating_metrics_missing';
        }

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'source' => 'online_daily_data',
            'scope' => 'online_ota_operating_sample',
            'metric_scope' => 'ota_channel',
            'data_status' => $dataStatus,
            'evidence_status' => $evidenceStatus,
            'trust_policy' => 'trusted_ota_fact_repository_readback_verified_canonical',
            'deduplication_policy' => 'trusted_ota_fact_repository_canonical',
            'sample_count' => $trustedFactCount,
            'trusted_fact_count' => $trustedFactCount,
            'metric_observation_counts' => $observations,
            'data_gaps' => array_values(array_unique($dataGaps)),
            'source_policy' => is_array($history['source_policy'] ?? null) ? $history['source_policy'] : [],
            'data_quality' => is_array($history['data_quality'] ?? null) ? $history['data_quality'] : [],
            'amount' => $amount,
            'quantity' => $quantity,
            'orders' => $orders,
            'adr' => $this->suggestionEffectAdr($amount, $quantity),
        ];
    }

    public function suggestionEffectAdr(int|float|null $amount, int|float|null $quantity): ?float
    {
        if (!is_numeric($amount) || !is_numeric($quantity) || (float)$quantity <= 0) {
            return null;
        }

        return round((float)$amount / (float)$quantity, 2);
    }

    /** @return array<string, int|float|null> */
    public function suggestionEffectDelta(array $before, array $after): array
    {
        $delta = [];
        foreach (['amount', 'quantity', 'orders', 'adr'] as $metric) {
            $beforeValue = $before[$metric] ?? null;
            $afterValue = $after[$metric] ?? null;
            if (!is_numeric($beforeValue) || !is_numeric($afterValue)) {
                $delta[$metric] = null;
                continue;
            }
            $value = (float)$afterValue - (float)$beforeValue;
            $delta[$metric] = in_array($metric, ['quantity', 'orders'], true)
                ? (int)round($value)
                : round($value, 2);
        }

        return $delta;
    }

    public function buildEffectReviewReadiness(array $suggestion, array $before, array $after, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $status = (int)($suggestion['status'] ?? 0);
        $applied = $status === 4 || trim((string)($suggestion['applied_time'] ?? '')) !== '';
        $beforeStatus = (string)($before['data_status'] ?? 'unknown');
        $afterStatus = (string)($after['data_status'] ?? 'unknown');
        $beforeFacts = (int)($before['trusted_fact_count'] ?? 0);
        $afterFacts = (int)($after['trusted_fact_count'] ?? 0);
        $afterEnd = substr((string)($after['end_date'] ?? ''), 0, 10);
        $windowClosed = $afterEnd !== '' && $afterEnd <= $today;

        if (!$applied) {
            return $this->effectReviewReadiness('effect_review_not_started', '未应用', 30, false, '先应用或完成执行意图后再复盘', [
                $this->missingEvidence('local_price_applied', '本地价格应用', '先应用或完成执行意图后再复盘'),
            ]);
        }

        if ($beforeStatus === 'read_failed' || $afterStatus === 'read_failed') {
            return $this->effectReviewReadiness('effect_review_read_failed', '复盘读取失败', 35, false, '修复复盘数据读取错误后再判断效果', [
                $this->missingEvidence('review_source_readable', '复盘数据可读', '修复 online_daily_data 读取错误'),
            ]);
        }

        if (!$windowClosed) {
            return $this->effectReviewReadiness('effect_review_window_open', '等待周期', 55, false, '等待应用后7天窗口结束再复盘', [
                $this->missingEvidence('review_window', '完整复盘周期', '等待应用后7天窗口结束再复盘'),
            ]);
        }

        if ($beforeFacts <= 0 || $afterFacts <= 0) {
            return $this->effectReviewReadiness('effect_review_sample_missing', '样本不足', 60, false, '补齐应用前后线上经营样本后再判断效果', [
                $this->missingEvidence('trusted_before_after_facts', '应用前后可信经营事实', '补齐经回读、校验和去重的应用前后经营事实'),
            ]);
        }

        if (!$this->effectReviewPeriodTrusted($before) || !$this->effectReviewPeriodTrusted($after)) {
            return $this->effectReviewReadiness('effect_review_evidence_untrusted', '证据未通过', 65, false, '仅使用可信、可解释、已去重的经营事实复盘', [
                $this->missingEvidence('trusted_deduplicated_operating_facts', '可信去重经营事实', '通过 TrustedOtaFactRepository 回读并保留规范事实'),
            ]);
        }

        $comparableMetrics = $this->effectReviewComparableMetrics($before, $after);
        if ($comparableMetrics === []) {
            return $this->effectReviewReadiness('effect_review_metric_missing', '口径不可比', 70, false, '补齐应用前后同口径经营指标后再判断效果', [
                $this->missingEvidence('comparable_operating_metrics', '应用前后同口径指标', '补齐收入、间夜或订单中的至少一个同口径可信指标'),
            ]);
        }

        return $this->effectReviewReadiness(
            'effect_review_ready',
            '复盘可用',
            100,
            true,
            '将复盘结论沉淀到执行证据或 ROI 记录',
            [],
            ['comparable_metrics' => $comparableMetrics]
        );
    }

    private function effectReviewPeriodTrusted(array $period): bool
    {
        return (string)($period['data_status'] ?? '') === 'ok'
            && (string)($period['evidence_status'] ?? '') === 'trusted_deduplicated'
            && (string)($period['deduplication_policy'] ?? '') === 'trusted_ota_fact_repository_canonical'
            && (int)($period['trusted_fact_count'] ?? 0) > 0;
    }

    /** @return array<int, string> */
    private function effectReviewComparableMetrics(array $before, array $after): array
    {
        $beforeCounts = is_array($before['metric_observation_counts'] ?? null)
            ? $before['metric_observation_counts']
            : [];
        $afterCounts = is_array($after['metric_observation_counts'] ?? null)
            ? $after['metric_observation_counts']
            : [];
        $metrics = [];
        foreach (['amount', 'quantity', 'orders'] as $metric) {
            if ((int)($beforeCounts[$metric] ?? 0) <= 0
                || (int)($afterCounts[$metric] ?? 0) <= 0
                || !is_numeric($before[$metric] ?? null)
                || !is_numeric($after[$metric] ?? null)
            ) {
                continue;
            }
            $metrics[] = $metric;
        }

        return $metrics;
    }

    private function effectReviewReadiness(
        string $stage,
        string $label,
        int $score,
        bool $reviewReady,
        string $nextAction,
        array $missingEvidence = [],
        array $extra = []
    ): array
    {
        return array_merge([
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'review_ready' => $reviewReady,
            'missing_evidence' => $missingEvidence,
            'next_action' => $nextAction,
            'notice' => $missingEvidence
                ? '仍缺：' . implode('、', array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missingEvidence))
                : '应用前后样本已满足复盘判断；需继续沉淀执行证据或 ROI 记录。',
        ], $extra);
    }

    private function missingEvidence(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function estimatePriceElasticity(array $rows): array
    {
        $points = [];
        foreach ($this->aggregateOnlineRowsByDate($rows) as $row) {
            $amount = $this->toFloat($row['amount'] ?? 0);
            $quantity = $this->toFloat($row['quantity'] ?? 0);
            if ($amount > 0 && $quantity > 0) {
                $points[] = [
                    'adr' => $amount / $quantity,
                    'quantity' => $quantity,
                ];
            }
        }

        if (count($points) < 10) {
            return [
                'data_status' => 'insufficient',
                'sample_count' => count($points),
                'elasticity' => null,
                'data_gaps' => ['elasticity_sample_lt_10'],
            ];
        }

        $logPrices = array_map(static fn(array $row): float => log($row['adr']), $points);
        $logDemand = array_map(static fn(array $row): float => log($row['quantity']), $points);
        $meanPrice = array_sum($logPrices) / count($logPrices);
        $meanDemand = array_sum($logDemand) / count($logDemand);
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($logPrices as $index => $price) {
            $dx = $price - $meanPrice;
            $dy = $logDemand[$index] - $meanDemand;
            $numerator += $dx * $dy;
            $denominator += $dx * $dx;
        }
        if ($denominator <= 0.0001) {
            return [
                'data_status' => 'insufficient',
                'sample_count' => count($points),
                'elasticity' => null,
                'data_gaps' => ['elasticity_price_variation_insufficient'],
            ];
        }

        $elasticity = round($numerator / $denominator, 3);
        $backtest = $this->medianSplitBacktest($points);

        return [
            'data_status' => 'ok',
            'sample_count' => count($points),
            'elasticity' => $elasticity,
            'interpretation' => $elasticity < -1 ? 'price_sensitive' : ($elasticity < 0 ? 'weak_negative' : 'non_negative'),
            'backtest' => $backtest,
            'data_gaps' => $elasticity >= 0 ? ['elasticity_non_negative_manual_review'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRecommendation(array $roomType, array $signals, string $reason): array
    {
        return [
            'should_create' => false,
            'skip_reason' => $reason,
            'advisory_only' => true,
            'action' => 'hold',
            'current_price' => $this->toFloat($roomType['base_price'] ?? 0),
            'suggested_price' => $this->toFloat($roomType['base_price'] ?? 0),
            'confidence_score' => 0.0,
            'risk_level' => 'high',
            'reason' => $reason,
            'factor_notes' => [],
            'drivers' => [],
            'review_checklist' => ['Fix blocking pricing input before manual review: ' . $reason],
            'primary_signal_count' => 0,
            'competitor_data' => $signals['competitor'] ?? [],
            'factors' => [
                'model' => self::MODEL_VERSION,
                'advisory_only' => true,
                'signals' => $signals,
                'factor_notes' => [],
                'drivers' => [],
                'confidence_score' => 0.0,
                'risk_level' => 'high',
                'review_checklist' => ['Fix blocking pricing input before manual review: ' . $reason],
                'primary_signal_count' => 0,
                'decision_boundary' => 'manual_review_required_no_auto_rate_write',
            ],
        ];
    }

    private function readinessCheck(string $key, string $label, bool $passed, string $evidence, string $nextAction, int $weight): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'ok' : 'missing',
            'evidence' => $evidence,
            'next_action' => $nextAction,
            'weight' => $weight,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSuggestionDisplayFields(array $row): array
    {
        if (!isset($row['status_name']) || $row['status_name'] === '') {
            $row['status_name'] = $this->pricingSuggestionStatusName((int)($row['status'] ?? 0));
        }
        if (!isset($row['suggestion_type_name']) || $row['suggestion_type_name'] === '') {
            $row['suggestion_type_name'] = $this->pricingSuggestionTypeName((int)($row['suggestion_type'] ?? 0));
        }
        if (!array_key_exists('price_change_percent', $row)) {
            $currentPrice = $this->toFloat($row['current_price'] ?? 0);
            $suggestedPrice = $this->toFloat($row['suggested_price'] ?? 0);
            $row['price_change_percent'] = $currentPrice > 0
                ? round(($suggestedPrice - $currentPrice) / $currentPrice * 100, 2)
                : 0.0;
        }

        return $row;
    }

    private function pricingSuggestionStatusName(int $status): string
    {
        return [
            1 => '待审批',
            2 => '已批准',
            3 => '已拒绝',
            4 => '已应用',
            5 => '已过期',
        ][$status] ?? '未知';
    }

    private function pricingSuggestionTypeName(int $type): string
    {
        return [
            1 => '动态定价',
            2 => '竞对跟价',
            3 => '事件驱动',
            4 => '预测驱动',
        ][$type] ?? '未知';
    }

    private function pricingReadinessStage(
        int $id,
        int $status,
        bool $sourceReady,
        bool $riskClear,
        bool $approved,
        bool $executionLinked,
        string $executionStage,
        bool $appliedLocal,
        bool $evidenceReady,
        bool $roiReady
    ): string {
        if ($id <= 0) {
            return 'suggestion_missing';
        }
        if ($status === 3 || $executionStage === 'rejected') {
            return 'rejected';
        }
        if ($executionStage === 'blocked') {
            return 'blocked';
        }
        if (!$sourceReady || !$riskClear) {
            return 'data_recheck_required';
        }
        if (!$approved) {
            return 'pending_approval';
        }
        if (!$executionLinked) {
            return 'approved_pending_execution';
        }
        if ($executionStage === 'approval') {
            return 'execution_intent_pending_approval';
        }
        if (!$appliedLocal || !$evidenceReady) {
            return 'local_applied_pending_evidence';
        }
        if (!$roiReady) {
            return 'evidence_ready';
        }
        return 'pricing_ready';
    }

    private function pricingReadinessStageLabel(string $stage): string
    {
        return [
            'suggestion_missing' => '未形成建议',
            'data_recheck_required' => '需数据复核',
            'pending_approval' => '待人工审批',
            'approved_pending_execution' => '已批待转执行',
            'execution_intent_pending_approval' => '执行意图待审',
            'local_applied_pending_evidence' => '待执行证据',
            'evidence_ready' => '待效果复盘',
            'pricing_ready' => '调价闭环就绪',
            'rejected' => '已拒绝',
            'blocked' => '执行阻断',
        ][$stage] ?? $stage;
    }

    private function pricingReadinessNotice(string $stage): string
    {
        return [
            'suggestion_missing' => '当前没有可复核的调价建议。',
            'data_recheck_required' => '建议仍有数据缺口、低置信度或高风险信号，不能直接执行。',
            'pending_approval' => '建议只代表模型输出，需人工审批后才能进入执行。',
            'approved_pending_execution' => '建议已审批，但还没有进入运营执行意图。',
            'execution_intent_pending_approval' => '已转入执行意图，仍需执行流审批。',
            'local_applied_pending_evidence' => '本地价格应用或执行意图已形成，但缺 OTA/人工执行证据。',
            'evidence_ready' => '已有执行证据，下一步需要做效果复盘和 ROI 判断。',
            'pricing_ready' => '建议、审批、执行证据和效果复盘均已形成，可视为调价闭环就绪。',
            'rejected' => '建议已被拒绝，不进入执行闭环。',
            'blocked' => '执行链路被阻断，需先处理阻断原因。',
        ][$stage] ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelSignals(int $hotelId, string $targetDate): array
    {
        $cacheKey = $hotelId . '|' . $targetDate;
        if (isset($this->hotelSignalCache[$cacheKey])) {
            return $this->hotelSignalCache[$cacheKey];
        }

        $asOfDate = min($targetDate, date('Y-m-d'));
        $historyStart = date('Y-m-d', strtotime($asOfDate . ' -60 days'));
        $history = $this->trustedOtaFacts->pricingHistory($hotelId, $historyStart, $asOfDate);

        return $this->hotelSignalCache[$cacheKey] = $this->buildHotelSignals(
            $hotelId,
            $targetDate,
            $asOfDate,
            $historyStart,
            $history
        );
    }

    /**
     * @param array<int, string> $targetDates
     */
    private function primeHotelSignalsBatch(int $hotelId, array $targetDates): void
    {
        $missingDates = array_values(array_filter(
            $targetDates,
            fn(string $targetDate): bool => !isset($this->hotelSignalCache[$hotelId . '|' . $targetDate])
        ));
        if ($missingDates === []) {
            return;
        }

        $today = date('Y-m-d');
        $asOfDates = [];
        foreach ($missingDates as $targetDate) {
            $asOfDates[$targetDate] = min($targetDate, $today);
        }
        $windows = [];
        $windowKeyByTarget = [];
        foreach ($missingDates as $targetDate) {
            $asOfDate = $asOfDates[$targetDate];
            $historyStart = date('Y-m-d', strtotime($asOfDate . ' -60 days'));
            $windowKey = $historyStart . '|' . $asOfDate;
            $windowKeyByTarget[$targetDate] = $windowKey;
            $windows[$windowKey] = [
                'start_date' => $historyStart,
                'end_date' => $asOfDate,
            ];
        }
        $histories = $this->trustedOtaFacts->pricingHistoryBatch($hotelId, $windows);
        foreach ($missingDates as $targetDate) {
            $asOfDate = $asOfDates[$targetDate];
            $windowKey = $windowKeyByTarget[$targetDate];
            $historyStart = $windows[$windowKey]['start_date'];
            $history = is_array($histories[$windowKey] ?? null)
                ? $histories[$windowKey]
                : [
                    'data_status' => 'blocked',
                    'rows' => [],
                    'data_gaps' => ['pricing_history_query_failed'],
                    'source_policy' => [],
                    'data_quality' => [],
                ];
            $this->hotelSignalCache[$hotelId . '|' . $targetDate] = $this->buildHotelSignals(
                $hotelId,
                $targetDate,
                $asOfDate,
                $historyStart,
                $history
            );
        }
    }

    /**
     * @param array<string, mixed> $history
     * @return array<string, mixed>
     */
    private function buildHotelSignals(
        int $hotelId,
        string $targetDate,
        string $asOfDate,
        string $historyStart,
        array $history
    ): array {
        $historyRows = is_array($history['rows'] ?? null) ? $history['rows'] : [];
        $historyRows = array_values(array_filter($historyRows, static function (array $row): bool {
            $source = strtolower(trim((string)($row['source'] ?? '')));
            return in_array($source, self::CTRIP_TRAFFIC_SOURCE_ALIASES, true);
        }));
        $elasticity = $this->estimatePriceElasticity($historyRows);
        $pickup = $this->pickupSignal($historyRows, $asOfDate);
        $holiday = $this->holidaySignal($targetDate);
        $backtest = $elasticity['backtest'] ?? [
            'data_status' => 'insufficient',
            'hit_rate' => null,
            'sample_count' => 0,
        ];

        $dataGaps = $this->uniqueStrings(array_filter(array_merge(
            empty($historyRows) ? ['online_daily_history_missing'] : [],
            is_array($history['data_gaps'] ?? null) ? $history['data_gaps'] : [],
            $elasticity['data_gaps'] ?? [],
            $pickup['data_gaps'] ?? [],
            $holiday['data_gaps'] ?? []
        )));

        return [
            'pickup' => $pickup,
            'elasticity' => $elasticity,
            'backtest' => $backtest,
            'holiday' => $holiday,
            'history_data_status' => (string)($history['data_status'] ?? 'unknown'),
            'source_policy' => is_array($history['source_policy'] ?? null) ? $history['source_policy'] : [],
            'history_data_quality' => is_array($history['data_quality'] ?? null) ? $history['data_quality'] : [],
            'history_evidence' => $historyRows === [] ? [] : [
                'ref' => 'online_daily_data#pricing_history:' . $hotelId . ':' . $historyStart . ':' . $asOfDate,
                'source' => 'online_daily_data',
                'date' => $asOfDate,
                'date_role' => 'historical',
                'scope' => 'ota_channel',
                'system_hotel_id' => $hotelId,
                'platform' => 'ctrip',
                'quality_status' => 'decision_eligible',
                'decision_eligible' => true,
                'readback_verified' => true,
                'metric_keys' => ['amount', 'quantity', 'book_order_num'],
                'summary' => '同系统酒店、携程渠道、历史窗口内的数据库回读核验经营事实。',
            ],
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forecastSignal(int $hotelId, int $roomTypeId, string $targetDate, array $roomType): array
    {
        $cacheKey = self::batchRecommendationKey($roomTypeId, $targetDate) . '|hotel:' . $hotelId;
        if (isset($this->forecastSignalCache[$cacheKey])) {
            return $this->forecastSignalCache[$cacheKey];
        }
        $forecast = DemandForecast::latestForPricing($hotelId, $roomTypeId, $targetDate);

        if ($forecast) {
            return $this->forecastSignalCache[$cacheKey] = $this->forecastSignalFromModel($forecast);
        }

        $trafficForecast = $this->ctripTrafficDemandForecastSignal($hotelId, $targetDate);
        return $this->forecastSignalCache[$cacheKey] = $this->forecastSignalFromTrafficFallback($trafficForecast);
    }

    /**
     * @param array<int, int> $roomTypeIds
     * @param array<int, string> $targetDates
     */
    private function primeForecastSignalsBatch(
        int $hotelId,
        array $roomTypeIds,
        array $targetDates
    ): void {
        $forecasts = DemandForecast::where('hotel_id', $hotelId)
            ->whereIn('room_type_id', $roomTypeIds)
            ->whereBetween('forecast_date', [$targetDates[0], $targetDates[count($targetDates) - 1]])
            ->select();
        $byKey = [];
        foreach ($forecasts as $forecast) {
            if (!$forecast instanceof DemandForecast) {
                continue;
            }
            $key = self::batchRecommendationKey(
                (int)$forecast->room_type_id,
                (string)$forecast->forecast_date
            );
            $byKey[$key][] = $forecast;
        }

        $missingDates = [];
        foreach ($targetDates as $targetDate) {
            foreach ($roomTypeIds as $roomTypeId) {
                $key = self::batchRecommendationKey($roomTypeId, $targetDate);
                $cacheKey = $key . '|hotel:' . $hotelId;
                $selected = $this->latestForecastForPricingFromBatch($byKey[$key] ?? []);
                if ($selected instanceof DemandForecast) {
                    $this->forecastSignalCache[$cacheKey] = $this->forecastSignalFromModel($selected);
                    continue;
                }
                $missingDates[$targetDate] = true;
            }
        }

        if ($missingDates !== []) {
            $this->primeCtripTrafficForecastSignalsBatch($hotelId, array_keys($missingDates));
        }
        foreach ($targetDates as $targetDate) {
            foreach ($roomTypeIds as $roomTypeId) {
                $cacheKey = self::batchRecommendationKey($roomTypeId, $targetDate) . '|hotel:' . $hotelId;
                if (isset($this->forecastSignalCache[$cacheKey])) {
                    continue;
                }
                $trafficKey = $hotelId . '|' . $targetDate;
                $trafficForecast = $this->ctripTrafficForecastSignalCache[$trafficKey]
                    ?? $this->ctripTrafficDemandForecastSignal($hotelId, $targetDate);
                $this->forecastSignalCache[$cacheKey] = $this->forecastSignalFromTrafficFallback($trafficForecast);
            }
        }
    }

    /** @param array<int, DemandForecast> $forecasts */
    private function latestForecastForPricingFromBatch(array $forecasts): ?DemandForecast
    {
        if ($forecasts === []) {
            return null;
        }
        usort($forecasts, static function (DemandForecast $left, DemandForecast $right): int {
            foreach (['update_time', 'create_time'] as $field) {
                $rightValue = $right->{$field} ?? null;
                $leftValue = $left->{$field} ?? null;
                $rightTime = is_int($rightValue) || (is_string($rightValue) && ctype_digit($rightValue))
                    ? (int)$rightValue
                    : (strtotime((string)$rightValue) ?: 0);
                $leftTime = is_int($leftValue) || (is_string($leftValue) && ctype_digit($leftValue))
                    ? (int)$leftValue
                    : (strtotime((string)$leftValue) ?: 0);
                $comparison = $rightTime <=> $leftTime;
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return (int)$right->id <=> (int)$left->id;
        });
        $latest = $forecasts[0];
        foreach ($forecasts as $forecast) {
            $metadata = is_array($forecast->historical_data ?? null)
                ? $forecast->historical_data
                : $this->decodeJsonObject($forecast->historical_data ?? null);
            if ((string)($metadata['input_type'] ?? '') === DemandForecast::MANUAL_INPUT_TYPE) {
                return $forecast;
            }
        }
        return $latest;
    }

    /** @return array<string, mixed> */
    private function forecastSignalFromModel(DemandForecast $forecast): array
    {
        $sourceMetadata = $this->manualInputMetadata(
            $forecast->historical_data ?? null,
            'manual_demand_forecast'
        );
        return [
            'data_status' => 'ok',
            'source' => 'demand_forecasts',
            'id' => (int)$forecast->id,
            'room_type_id' => (int)$forecast->room_type_id,
            'forecast_date' => (string)$forecast->forecast_date,
            'predicted_occupancy' => $this->toFloat($forecast->predicted_occupancy ?? 0),
            'predicted_demand' => (int)($forecast->predicted_demand ?? 0),
            'confidence_score' => $this->toFloat($forecast->confidence_score ?? 0),
            'event_type' => (int)($forecast->event_type ?? 0),
            'is_event_driven' => (int)($forecast->is_event_driven ?? 0),
            'source_metadata' => $sourceMetadata,
            'data_gaps' => [],
        ];
    }

    /** @param array<string, mixed> $trafficForecast @return array<string, mixed> */
    private function forecastSignalFromTrafficFallback(array $trafficForecast): array
    {
        if (($trafficForecast['data_status'] ?? '') === 'ok') {
            return $trafficForecast;
        }

        return [
            'data_status' => 'missing',
            'source' => 'demand_forecasts',
            'id' => 0,
            'predicted_occupancy' => null,
            'predicted_demand' => null,
            'confidence_score' => null,
            'fallback_source' => $trafficForecast,
            'data_gaps' => $this->uniqueStrings(array_merge(
                ['demand_forecast_missing'],
                (array)($trafficForecast['data_gaps'] ?? [])
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ctripTrafficDemandForecastSignal(int $hotelId, string $targetDate): array
    {
        $cacheKey = $hotelId . '|' . $targetDate;
        if (isset($this->ctripTrafficForecastSignalCache[$cacheKey])) {
            return $this->ctripTrafficForecastSignalCache[$cacheKey];
        }
        $endDate = $this->ctripTrafficForecastHistoryEndDate($targetDate);
        $startDate = date('Y-m-d', strtotime($endDate . ' -' . (self::CTRIP_TRAFFIC_HISTORY_DAYS - 1) . ' days'));

        $history = $this->strictTrafficHistory->read($hotelId, $startDate, $endDate);
        return $this->ctripTrafficForecastSignalCache[$cacheKey] = $this->buildTrafficForecastFromStrictHistory(
            $history,
            $targetDate,
            $startDate,
            $endDate,
            $hotelId
        );
    }

    /** @param array<int, string> $targetDates */
    private function primeCtripTrafficForecastSignalsBatch(int $hotelId, array $targetDates): void
    {
        $targetDates = array_values(array_unique(array_filter(array_map(
            static fn(mixed $date): string => trim((string)$date),
            $targetDates
        ))));
        if ($targetDates === []) {
            return;
        }
        sort($targetDates, SORT_STRING);

        $windows = [];
        $windowKeyByTarget = [];
        foreach ($targetDates as $targetDate) {
            $endDate = $this->ctripTrafficForecastHistoryEndDate($targetDate);
            $startDate = date('Y-m-d', strtotime($endDate . ' -' . (self::CTRIP_TRAFFIC_HISTORY_DAYS - 1) . ' days'));
            $windowKey = $startDate . '|' . $endDate;
            $windowKeyByTarget[$targetDate] = $windowKey;
            $windows[$windowKey] = [
                'start' => $startDate,
                'end' => $endDate,
            ];
        }
        $historyByWindow = $this->strictTrafficHistory->readBatch($hotelId, $windows);
        foreach ($targetDates as $targetDate) {
            $windowKey = $windowKeyByTarget[$targetDate];
            $window = $windows[$windowKey];
            $history = is_array($historyByWindow[$windowKey] ?? null)
                ? $historyByWindow[$windowKey]
                : [
                    'data_status' => 'blocked',
                    'rows' => [],
                    'data_gaps' => ['ctrip_traffic_history_query_failed'],
                    'data_quality' => [],
                ];
            $this->ctripTrafficForecastSignalCache[$hotelId . '|' . $targetDate] =
                $this->buildTrafficForecastFromStrictHistory(
                    $history,
                    $targetDate,
                    $window['start'],
                    $window['end'],
                    $hotelId
                );
        }
    }

    /** @param array<string,mixed> $history @return array<string,mixed> */
    private function buildTrafficForecastFromStrictHistory(
        array $history,
        string $targetDate,
        string $startDate,
        string $endDate,
        int $hotelId
    ): array {
        $gaps = array_values(array_filter(array_map(
            'strval',
            is_array($history['data_gaps'] ?? null) ? $history['data_gaps'] : []
        )));
        $quality = is_array($history['data_quality'] ?? null) ? $history['data_quality'] : [];
        if ((string)($history['data_status'] ?? '') !== 'ready' || $gaps !== []) {
            if ($gaps === []) {
                $gaps[] = 'ctrip_traffic_history_strict_evidence_incomplete';
            }
            return $this->ctripTrafficDemandForecastUnavailable(
                $targetDate,
                $startDate,
                $endDate,
                $hotelId,
                'blocked',
                $gaps[0],
                [
                    'data_gaps' => $this->uniqueStrings($gaps),
                    'strict_history_quality' => $quality,
                ]
            );
        }
        $forecast = $this->buildCtripTrafficDemandForecastSignal(
            $this->ctripTrafficDailySeries((array)($history['rows'] ?? [])),
            $targetDate,
            $startDate,
            $endDate,
            $hotelId,
            self::CTRIP_TRAFFIC_HISTORY_DAYS
        );
        $forecast['strict_history_quality'] = $quality;
        return $forecast;
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     * @return array<string, mixed>
     */
    public function buildCtripTrafficDemandForecastSignal(
        array $daily,
        string $targetDate,
        string $startDate,
        string $endDate,
        ?int $hotelId = null,
        int $historyDays = self::CTRIP_TRAFFIC_HISTORY_DAYS
    ): array {
        $primaryMetric = $this->ctripTrafficPrimaryMetric($daily);
        if ($primaryMetric === '') {
            return $this->ctripTrafficDemandForecastUnavailable(
                $targetDate,
                $startDate,
                $endDate,
                $hotelId,
                'missing',
                'no_positive_ctrip_traffic_metric_found'
            );
        }

        $values = [];
        foreach ($daily as $day) {
            $value = $this->toFloat(($day['metrics'] ?? [])[$primaryMetric] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        $usableDays = count($values);
        if ($usableDays < 3) {
            return $this->ctripTrafficDemandForecastUnavailable(
                $targetDate,
                $startDate,
                $endDate,
                $hotelId,
                'insufficient',
                'ctrip_traffic_demand_history_lt_3_days',
                [
                    'primary_metric' => $primaryMetric,
                    'usable_history_days' => $usableDays,
                ]
            );
        }

        $baselineAverage = $this->average($values);
        $recentAverage = $this->average(array_slice($values, -min(3, $usableDays)));
        $demandIndex = $baselineAverage > 0 ? 100.0 * $recentAverage / $baselineAverage : 100.0;
        $trendDeltaPercent = $demandIndex - 100.0;
        $trendDirection = 'flat';
        if ($trendDeltaPercent >= 5.0) {
            $trendDirection = 'rising';
        } elseif ($trendDeltaPercent <= -5.0) {
            $trendDirection = 'falling';
        }

        $variation = $baselineAverage > 0 ? $this->stddev($values) / $baselineAverage : 0.0;
        $coverageScore = $this->clamp($usableDays / min(14, max(3, $historyDays)), 0.0, 1.0);
        $stabilityPenalty = $this->clamp($variation * 0.18, 0.0, 0.2);
        $confidence = $this->clamp(0.45 + ($coverageScore * 0.35) - $stabilityPenalty, 0.2, 0.9);
        $trendScore = $this->clamp(50.0 + ($trendDeltaPercent / 2.0), 1.0, 100.0);

        $sourceMetadata = [
            'input_type' => 'ctrip_historical_traffic_trend',
            'input_scope' => 'traffic_derived_demand_forecast',
            'source_scope' => 'ctrip_ota_channel',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'derived_from_online_daily_data',
            'source_policy' => 'derived_trend_only_no_raw_rows_no_import',
            'history_window' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'history_days_requested' => $historyDays,
                'usable_history_days' => $usableDays,
            ],
            'primary_metric' => $primaryMetric,
            'trend_direction' => $trendDirection,
            'trend_delta_percent' => round($trendDeltaPercent, 2),
            'predicted_demand_index' => round($demandIndex, 2),
            'trend_score_0_100' => round($trendScore, 2),
            'field_semantics' => [
                'predicted_occupancy' => 'traffic_trend_score_0_100_for_Ctrip_channel_demand_trend_not_whole_hotel_occupancy_50_means_history_baseline',
                'predicted_demand' => 'Ctrip historical traffic demand index where 100 means history-window baseline',
            ],
            'auto_write_ota' => false,
        ];

        return [
            'data_status' => 'ok',
            'source' => 'ctrip_historical_traffic_trend',
            'id' => 0,
            'forecast_date' => $targetDate,
            'predicted_occupancy' => round($trendScore, 2),
            'predicted_demand' => round($demandIndex, 2),
            'confidence_score' => round($confidence, 2),
            'event_type' => 0,
            'is_event_driven' => 0,
            'trend_direction' => $trendDirection,
            'trend_delta_percent' => round($trendDeltaPercent, 2),
            'primary_metric' => $primaryMetric,
            'source_metadata' => $sourceMetadata,
            'data_gaps' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competitorSignal(int $hotelId, int $roomTypeId, string $targetDate, float $currentPrice): array
    {
        $cacheKey = self::batchRecommendationKey($roomTypeId, $targetDate) . '|hotel:' . $hotelId;
        if (isset($this->competitorSignalCache[$cacheKey])) {
            return $this->competitorSignalCache[$cacheKey];
        }
        $roomLookup = $this->latestCompetitorRows($hotelId, $roomTypeId, $targetDate);
        $lookups = [['source_scope' => 'room_type', 'lookup' => $roomLookup]];
        if ($this->competitorPrices((array)($roomLookup['rows'] ?? [])) === []) {
            $lookups[] = [
                'source_scope' => 'hotel',
                'lookup' => $this->latestCompetitorRows($hotelId, 0, $targetDate),
            ];
        }

        return $this->competitorSignalCache[$cacheKey] = $this->buildCompetitorSignal(
            $lookups,
            $targetDate,
            $currentPrice
        );
    }

    /**
     * @param array<int, int> $roomTypeIds
     * @param array<int, string> $targetDates
     * @param array<int, array<string, mixed>> $roomTypesById
     */
    private function primeCompetitorSignalsBatch(
        int $hotelId,
        array $roomTypeIds,
        array $targetDates,
        array $roomTypesById
    ): void {
        $startDate = date(
            'Y-m-d',
            strtotime($targetDates[0] . ' -' . self::COMPETITOR_LOOKBACK_DAYS . ' days')
        );
        $rows = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->whereBetween('analysis_date', [$startDate, $targetDates[count($targetDates) - 1]])
            ->whereIn('ota_platform', self::CTRIP_COMPETITOR_PLATFORM_VALUES)
            ->order('analysis_date', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($targetDates as $targetDate) {
            $hotelLookup = null;
            foreach ($roomTypeIds as $roomTypeId) {
                $roomLookup = $this->latestCompetitorRowsFromBatch($rows, $roomTypeId, $targetDate);
                $lookups = [['source_scope' => 'room_type', 'lookup' => $roomLookup]];
                if ($this->competitorPrices((array)($roomLookup['rows'] ?? [])) === []) {
                    $hotelLookup ??= $this->latestCompetitorRowsFromBatch($rows, 0, $targetDate);
                    $lookups[] = ['source_scope' => 'hotel', 'lookup' => $hotelLookup];
                }
                $currentPrice = (float)($roomTypesById[$roomTypeId]['base_price'] ?? 0);
                $cacheKey = self::batchRecommendationKey($roomTypeId, $targetDate) . '|hotel:' . $hotelId;
                $this->competitorSignalCache[$cacheKey] = $this->buildCompetitorSignal(
                    $lookups,
                    $targetDate,
                    $currentPrice
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows: array<int, array<string, mixed>>, source_date: string|null}
     */
    private function latestCompetitorRowsFromBatch(
        array $rows,
        int $roomTypeId,
        string $targetDate
    ): array {
        $startDate = date('Y-m-d', strtotime($targetDate . ' -' . self::COMPETITOR_LOOKBACK_DAYS . ' days'));
        $matches = array_values(array_filter(
            $rows,
            static function (array $row) use ($roomTypeId, $startDate, $targetDate): bool {
                $date = (string)($row['analysis_date'] ?? '');
                return $date >= $startDate
                    && $date <= $targetDate
                    && ($roomTypeId <= 0 || (int)($row['room_type_id'] ?? 0) === $roomTypeId);
            }
        ));
        if ($matches === []) {
            return ['rows' => [], 'source_date' => null];
        }
        $sourceDate = (string)($matches[0]['analysis_date'] ?? '');
        return [
            'rows' => array_values(array_filter(
                $matches,
                static fn(array $row): bool => (string)($row['analysis_date'] ?? '') === $sourceDate
            )),
            'source_date' => $sourceDate !== '' ? $sourceDate : null,
        ];
    }

    /**
     * @param array<int, array{source_scope:string,lookup:array<string,mixed>}> $lookups
     * @return array<string, mixed>
     */
    private function buildCompetitorSignal(array $lookups, string $targetDate, float $currentPrice): array
    {

        $rows = [];
        $sourceScope = 'room_type';
        $sourceDate = null;
        $prices = [];
        $sourceMetadataRows = [];
        foreach ($lookups as $candidate) {
            $candidateRows = (array)($candidate['lookup']['rows'] ?? []);
            $candidatePrices = $this->competitorPrices($candidateRows);
            if ($candidatePrices) {
                $rows = $candidateRows;
                $sourceScope = (string)$candidate['source_scope'];
                $sourceDate = (string)($candidate['lookup']['source_date'] ?? '');
                $prices = $candidatePrices;
                $sourceMetadataRows = $this->manualInputMetadataRows($candidateRows, 'competitor_data', 'manual_ctrip_competitor_price_sample');
                break;
            }
        }

        $stalenessDays = $sourceDate ? $this->daysBetween($sourceDate, $targetDate) : null;
        $dataGaps = [];
        if ($sourceScope === 'hotel') {
            $dataGaps[] = 'competitor_room_type_missing_using_hotel_scope';
        }
        if ($sourceDate && $sourceDate !== $targetDate) {
            $dataGaps[] = 'competitor_price_uses_recent_snapshot';
            if ($stalenessDays !== null && $stalenessDays > 3) {
                $dataGaps[] = 'competitor_price_stale_gt_3_days';
            }
        }

        if (!$prices) {
            return [
                'data_status' => 'missing',
                'source' => 'competitor_analysis',
                'source_scope' => $sourceScope,
                'source_date' => $sourceDate,
                'lookback_days' => self::COMPETITOR_LOOKBACK_DAYS,
                'staleness_days' => $stalenessDays,
                'avg_price' => null,
                'min_price' => null,
                'max_price' => null,
                'gap_percent' => null,
                'sample_count' => 0,
                'data_gaps' => $this->uniqueStrings(array_merge($dataGaps, ['competitor_price_missing'])),
            ];
        }

        $avgPrice = array_sum($prices) / count($prices);
        return [
            'data_status' => 'ok',
            'source' => 'competitor_analysis',
            'source_scope' => $sourceScope,
            'source_date' => $sourceDate,
            'lookback_days' => self::COMPETITOR_LOOKBACK_DAYS,
            'staleness_days' => $stalenessDays,
            'avg_price' => round($avgPrice, 2),
            'min_price' => round(min($prices), 2),
            'max_price' => round(max($prices), 2),
            'gap_percent' => $currentPrice > 0 ? round(($avgPrice - $currentPrice) / $currentPrice * 100, 2) : null,
            'sample_count' => count($prices),
            'source_metadata' => $sourceMetadataRows,
            'data_gaps' => $this->uniqueStrings($dataGaps),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, source_date: string|null}
     */
    private function latestCompetitorRows(int $hotelId, int $roomTypeId, string $targetDate): array
    {
        $startDate = date('Y-m-d', strtotime($targetDate . ' -' . self::COMPETITOR_LOOKBACK_DAYS . ' days'));
        $query = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->whereBetween('analysis_date', [$startDate, $targetDate])
            ->whereIn('ota_platform', self::CTRIP_COMPETITOR_PLATFORM_VALUES)
            ->order('analysis_date', 'desc')
            ->order('id', 'desc');

        if ($roomTypeId > 0) {
            $query->where('room_type_id', $roomTypeId);
        }

        $rows = $query->select()->toArray();
        if (!$rows) {
            return ['rows' => [], 'source_date' => null];
        }

        $sourceDate = (string)($rows[0]['analysis_date'] ?? '');
        $snapshotRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => (string)($row['analysis_date'] ?? '') === $sourceDate
        ));

        return [
            'rows' => $snapshotRows,
            'source_date' => $sourceDate !== '' ? $sourceDate : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, float>
     */
    private function competitorPrices(array $rows): array
    {
        $prices = [];
        foreach ($rows as $row) {
            $price = $this->toFloat($row['competitor_price'] ?? 0);
            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    private function daysBetween(string $fromDate, string $toDate): int
    {
        $from = strtotime($fromDate);
        $to = strtotime($toDate);
        if ($from === false || $to === false) {
            return 0;
        }

        return max(0, (int)floor(($to - $from) / 86400));
    }

    /**
     * @return array<string, mixed>
     */
    private function manualInputMetadata(mixed $value, string $inputType): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ((string)($value['input_type'] ?? '') !== $inputType) {
            return [];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function manualInputMetadataFromList(mixed $value, string $inputType): array
    {
        if (!is_array($value)) {
            return [];
        }

        $direct = $this->manualInputMetadata($value, $inputType);
        if ($direct !== []) {
            return $direct;
        }

        foreach ($value as $item) {
            $metadata = $this->manualInputMetadata($item, $inputType);
            if ($metadata !== []) {
                return $metadata;
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function manualInputMetadataRows(array $rows, string $field, string $inputType): array
    {
        $result = [];
        foreach ($rows as $row) {
            $metadata = $this->manualInputMetadata($row[$field] ?? null, $inputType);
            if ($metadata !== []) {
                $result[] = $metadata;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function inventorySignal(array $roomType, array $forecast): array
    {
        $roomCount = (int)($roomType['room_count'] ?? 0);
        $sourceMetadata = $this->manualInputMetadataFromList($roomType['facilities'] ?? null, 'manual_ctrip_room_type_pricing_guard');
        if ($roomCount <= 0) {
            return [
                'data_status' => 'missing',
                'capacity' => null,
                'predicted_demand' => $forecast['predicted_demand'] ?? null,
                'utilization_percent' => null,
                'source_metadata' => $sourceMetadata,
                'data_gaps' => ['room_type_room_count_missing'],
            ];
        }

        $predictedDemand = $this->toNullableFloat($forecast['predicted_demand'] ?? null);
        $occupancy = $this->toNullableFloat($forecast['predicted_occupancy'] ?? null);
        if (($predictedDemand === null || $predictedDemand <= 0) && $occupancy !== null && $occupancy > 0) {
            $predictedDemand = $roomCount * $occupancy / 100;
        }

        if ($predictedDemand === null || $predictedDemand <= 0) {
            return [
                'data_status' => 'missing',
                'capacity' => $roomCount,
                'predicted_demand' => null,
                'utilization_percent' => null,
                'source_metadata' => $sourceMetadata,
                'data_gaps' => ['inventory_demand_signal_missing'],
            ];
        }

        return [
            'data_status' => 'ok',
            'capacity' => $roomCount,
            'predicted_demand' => round($predictedDemand, 2),
            'utilization_percent' => round(min(150.0, $predictedDemand / $roomCount * 100), 2),
            'source_metadata' => $sourceMetadata,
            'data_gaps' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function pickupSignal(array $rows, string $asOfDate): array
    {
        $byDate = $this->aggregateOnlineRowsByDate($rows);
        $recentStart = date('Y-m-d', strtotime($asOfDate . ' -6 days'));
        $previousStart = date('Y-m-d', strtotime($asOfDate . ' -13 days'));
        $previousEnd = date('Y-m-d', strtotime($asOfDate . ' -7 days'));
        $earlyStart = date('Y-m-d', strtotime($asOfDate . ' -27 days'));
        $earlyEnd = date('Y-m-d', strtotime($asOfDate . ' -14 days'));

        $recent = $this->sumQuantityBetween($byDate, $recentStart, $asOfDate);
        $previous = $this->sumQuantityBetween($byDate, $previousStart, $previousEnd);
        $early = $this->sumQuantityBetween($byDate, $earlyStart, $earlyEnd);
        $sampleDays = count($byDate);
        if ($sampleDays < 14 || ($recent <= 0 && $previous <= 0)) {
            return [
                'data_status' => 'insufficient',
                'source' => 'online_daily_data_quantity_proxy',
                'as_of_date' => $asOfDate,
                'sample_days' => $sampleDays,
                'curve' => [
                    ['window' => 'd-27_to_d-14', 'room_nights' => round($early, 2)],
                    ['window' => 'd-13_to_d-7', 'room_nights' => round($previous, 2)],
                    ['window' => 'd-6_to_d0', 'room_nights' => round($recent, 2)],
                ],
                'pace_index' => null,
                'data_gaps' => [
                    'pickup_curve_uses_actual_sales_proxy_not_on_books',
                    'pickup_curve_on_books_snapshot_missing_or_short_history',
                ],
            ];
        }

        $recentAvg = $recent / 7;
        $previousAvg = $previous / 7;
        $paceIndex = $previousAvg > 0 ? round($recentAvg / $previousAvg * 100, 2) : null;

        return [
            'data_status' => 'ok',
            'source' => 'online_daily_data_quantity_proxy',
            'as_of_date' => $asOfDate,
            'sample_days' => $sampleDays,
            'curve' => [
                ['window' => 'd-27_to_d-14', 'room_nights' => round($early, 2)],
                ['window' => 'd-13_to_d-7', 'room_nights' => round($previous, 2)],
                ['window' => 'd-6_to_d0', 'room_nights' => round($recent, 2)],
            ],
            'pace_index' => $paceIndex,
            'data_gaps' => ['pickup_curve_uses_actual_sales_proxy_not_on_books'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function holidaySignal(string $targetDate): array
    {
        $year = (int)date('Y', strtotime($targetDate));
        $holidays = $this->holidays($year);
        $timestamp = strtotime($targetDate);
        if (!$timestamp || !$holidays) {
            return [
                'data_status' => 'missing',
                'target_date' => $targetDate,
                'is_weekend' => false,
                'is_holiday_window' => false,
                'is_in_holiday' => false,
                'data_gaps' => ['holiday_calendar_missing'],
            ];
        }

        $isWeekend = in_array((int)date('N', $timestamp), [6, 7], true);
        $nearest = null;
        foreach ($holidays as $holiday) {
            $start = strtotime($holiday['start_date']);
            $end = strtotime($holiday['end_date']);
            if ($start === false || $end === false) {
                continue;
            }
            if ($timestamp >= $start && $timestamp <= $end) {
                return [
                    'data_status' => 'ok',
                    'target_date' => $targetDate,
                    'name' => $holiday['name'],
                    'days_left' => 0,
                    'is_weekend' => $isWeekend,
                    'is_holiday_window' => true,
                    'is_in_holiday' => true,
                    'data_gaps' => [],
                ];
            }
            if ($timestamp < $start) {
                $daysLeft = (int)floor(($start - $timestamp) / 86400);
                $nearest = $nearest === null || $daysLeft < $nearest['days_left']
                    ? ['name' => $holiday['name'], 'days_left' => $daysLeft]
                    : $nearest;
            }
        }

        return [
            'data_status' => 'ok',
            'target_date' => $targetDate,
            'name' => $nearest['name'] ?? null,
            'days_left' => $nearest['days_left'] ?? null,
            'is_weekend' => $isWeekend,
            'is_holiday_window' => $nearest !== null && $nearest['days_left'] <= 14,
            'is_in_holiday' => false,
            'data_gaps' => [],
        ];
    }

    /**
     * @return array<int, array{name: string, start_date: string, end_date: string}>
     */
    private function holidays(int $year): array
    {
        return [
            2026 => [
                ['name' => 'new_year', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
                ['name' => 'spring_festival', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
                ['name' => 'qingming', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
                ['name' => 'labor_day', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
                ['name' => 'dragon_boat', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
                ['name' => 'mid_autumn', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
                ['name' => 'national_day', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
            ],
        ][$year] ?? [];
    }

    private function ctripTrafficForecastHistoryEndDate(string $targetDate): string
    {
        $targetTimestamp = strtotime($targetDate);
        if ($targetTimestamp === false) {
            $targetTimestamp = time();
        }
        $targetPreviousDate = date('Y-m-d', strtotime(date('Y-m-d', $targetTimestamp) . ' -1 day'));
        $yesterday = date('Y-m-d', strtotime('yesterday'));

        return min($targetPreviousDate, $yesterday);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function ctripTrafficDailySeries(array $rows): array
    {
        $daily = [];
        foreach ($rows as $row) {
            $date = (string)($row['data_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $metrics = $this->ctripTrafficMetrics($row);
            $trafficSignal = $metrics['list_exposure']
                + $metrics['detail_exposure']
                + $metrics['order_filling_num']
                + $metrics['order_submit_num'];
            if ($trafficSignal <= 0.0) {
                continue;
            }
            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'row_count' => 0,
                    'metrics' => [
                        'list_exposure' => 0.0,
                        'detail_exposure' => 0.0,
                        'order_filling_num' => 0.0,
                        'order_submit_num' => 0.0,
                        'book_order_num' => 0.0,
                        'room_nights' => 0.0,
                    ],
                ];
            }
            $daily[$date]['row_count']++;
            foreach (array_keys($daily[$date]['metrics']) as $metric) {
                $daily[$date]['metrics'][$metric] += $metrics[$metric] ?? 0.0;
            }
        }

        return array_values($daily);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, float>
     */
    private function ctripTrafficMetrics(array $row): array
    {
        $raw = ($row['_strict_traffic_history_verified'] ?? false) === true
            ? []
            : $this->decodeJsonObject($row['raw_data'] ?? null);
        return [
            'list_exposure' => max(0.0, (float)($this->rowNumber($row, ['list_exposure']) ?? $this->rawNumber($raw, ['listExposure', 'list_exposure', 'exposure']) ?? 0)),
            'detail_exposure' => max(0.0, (float)($this->rowNumber($row, ['detail_exposure']) ?? $this->rawNumber($raw, ['detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors']) ?? 0)),
            'order_filling_num' => max(0.0, (float)($this->rowNumber($row, ['order_filling_num']) ?? $this->rawNumber($raw, ['orderFillingNum', 'order_filling_num', 'orderVisitors']) ?? 0)),
            'order_submit_num' => max(0.0, (float)($this->rowNumber($row, ['order_submit_num']) ?? $this->rawNumber($raw, ['orderSubmitNum', 'order_submit_num', 'submitUsers']) ?? 0)),
            'book_order_num' => max(0.0, (float)($this->rowNumber($row, ['book_order_num']) ?? $this->rawNumber($raw, ['bookOrderNum', 'book_order_num', 'orderCount', 'orders']) ?? 0)),
            'room_nights' => max(0.0, (float)($this->rowNumber($row, ['quantity']) ?? $this->rawNumber($raw, ['roomNights', 'room_nights', 'quantity']) ?? 0)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $daily
     */
    private function ctripTrafficPrimaryMetric(array $daily): string
    {
        foreach (['order_submit_num', 'order_filling_num', 'detail_exposure', 'list_exposure'] as $metric) {
            $sum = 0.0;
            foreach ($daily as $day) {
                $sum += $this->toFloat(($day['metrics'] ?? [])[$metric] ?? 0);
            }
            if ($sum > 0.0) {
                return $metric;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function ctripTrafficDemandForecastUnavailable(
        string $targetDate,
        string $startDate,
        string $endDate,
        ?int $hotelId,
        string $status,
        string $reason,
        array $extra = []
    ): array {
        return array_merge([
            'data_status' => $status,
            'source' => 'ctrip_historical_traffic_trend',
            'id' => 0,
            'forecast_date' => $targetDate,
            'predicted_occupancy' => null,
            'predicted_demand' => null,
            'confidence_score' => null,
            'source_metadata' => [
                'input_type' => 'ctrip_historical_traffic_trend',
                'source_scope' => 'ctrip_ota_channel',
                'source_policy' => 'derived_trend_only_no_raw_rows_no_import',
                'history_window' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'history_days_requested' => self::CTRIP_TRAFFIC_HISTORY_DAYS,
                    'usable_history_days' => (int)($extra['usable_history_days'] ?? 0),
                ],
                'hotel_id' => $hotelId,
                'auto_write_ota' => false,
            ],
            'data_gaps' => [$reason],
        ], $extra);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, float|null>>
     */
    private function aggregateOnlineRowsByDate(array $rows): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $date = (string)($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = ['amount' => null, 'quantity' => null, 'orders' => null];
            }
            foreach (['amount' => 'amount', 'quantity' => 'quantity', 'book_order_num' => 'orders'] as $source => $target) {
                $value = $this->toNullableFloat($row[$source] ?? null);
                if ($value === null) {
                    continue;
                }
                $byDate[$date][$target] = ($byDate[$date][$target] ?? 0.0) + $value;
            }
        }
        ksort($byDate);

        return $byDate;
    }

    /**
     * @param array<string, array<string, float>> $byDate
     */
    private function sumQuantityBetween(array $byDate, string $startDate, string $endDate): float
    {
        $sum = 0.0;
        foreach ($byDate as $date => $row) {
            if ($date >= $startDate && $date <= $endDate) {
                $sum += (float)($row['quantity'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param array<int, array{adr: float, quantity: float}> $points
     * @return array<string, mixed>
     */
    private function medianSplitBacktest(array $points): array
    {
        $prices = array_column($points, 'adr');
        $quantities = array_column($points, 'quantity');
        sort($prices);
        sort($quantities);
        $medianPrice = $prices[(int)floor(count($prices) / 2)] ?? null;
        $medianQuantity = $quantities[(int)floor(count($quantities) / 2)] ?? null;
        if ($medianPrice === null || $medianQuantity === null) {
            return ['data_status' => 'insufficient', 'hit_rate' => null, 'sample_count' => 0];
        }

        $tested = 0;
        $hits = 0;
        foreach ($points as $point) {
            if ($point['adr'] === $medianPrice || $point['quantity'] === $medianQuantity) {
                continue;
            }
            $tested++;
            if (($point['adr'] > $medianPrice && $point['quantity'] < $medianQuantity)
                || ($point['adr'] < $medianPrice && $point['quantity'] > $medianQuantity)) {
                $hits++;
            }
        }

        return [
            'data_status' => $tested > 0 ? 'ok' : 'insufficient',
            'hit_rate' => $tested > 0 ? round($hits / $tested * 100, 2) : null,
            'sample_count' => $tested,
        ];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function confidenceScore(array $signals): float
    {
        $score = 0.45;
        foreach (['demand_forecast', 'pickup', 'elasticity', 'competitor', 'holiday', 'inventory'] as $key) {
            $status = (string)($signals[$key]['data_status'] ?? '');
            if ($status === 'ok') {
                $score += 0.07;
            } elseif ($status === 'insufficient') {
                $score += 0.02;
            }
        }

        $forecastConfidence = $this->toNullableFloat($signals['demand_forecast']['confidence_score'] ?? null);
        if ($forecastConfidence !== null && $forecastConfidence > 0) {
            $score = ($score + min(0.95, $forecastConfidence)) / 2;
        }

        $hitRate = $this->toNullableFloat($signals['backtest']['hit_rate'] ?? null);
        if ($hitRate !== null) {
            $score = ($score + min(0.9, max(0.3, $hitRate / 100))) / 2;
        }

        $gapCount = count((array)($signals['data_gaps'] ?? []));
        $score -= min(0.2, $gapCount * 0.03);

        return round(max(0.1, min(0.95, $score)), 2);
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function riskLevel(float $confidence, array $signals, int $primarySignalCount): string
    {
        $materialGaps = $this->materialDataGaps((array)($signals['data_gaps'] ?? []));
        if ($primarySignalCount < self::MIN_PRIMARY_SIGNAL_COUNT || $confidence < 0.55) {
            return 'high';
        }
        if ($confidence < 0.72 || $materialGaps) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $signals
     * @param array<int, array<string, mixed>> $drivers
     * @return array<int, string>
     */
    private function reviewChecklist(array $signals, array $drivers, string $riskLevel): array
    {
        $items = ['Confirm this is advisory-only before any OTA execution.'];
        $gaps = (array)($signals['data_gaps'] ?? []);

        if (in_array('pickup_curve_uses_actual_sales_proxy_not_on_books', $gaps, true)) {
            $items[] = 'Verify real on-books pickup before approving material changes.';
        }
        if (($signals['competitor']['data_status'] ?? '') !== 'ok'
            || in_array('competitor_room_type_missing_using_hotel_scope', $gaps, true)
            || in_array('competitor_price_uses_recent_snapshot', $gaps, true)
            || in_array('competitor_price_stale_gt_3_days', $gaps, true)) {
            $items[] = 'Check competitor snapshot date and price comparability.';
        }
        if (($signals['demand_forecast']['data_status'] ?? '') !== 'ok') {
            $items[] = 'Refresh demand forecast before relying on this recommendation.';
        }
        if (($signals['inventory']['data_status'] ?? '') !== 'ok') {
            $items[] = 'Confirm sellable inventory and room count before approval.';
        }
        if (($signals['elasticity']['data_status'] ?? '') !== 'ok'
            || (($signals['backtest']['hit_rate'] ?? null) !== null && (float)$signals['backtest']['hit_rate'] < 60)) {
            $items[] = 'Review elasticity and backtest evidence before changing price.';
        }
        if ($this->hasDriver($drivers, 'holiday')) {
            $items[] = 'Confirm holiday or event premium still applies to the target date.';
        }
        if ($riskLevel === 'high') {
            $items[] = 'Do not approve until blocking data gaps are resolved.';
        }

        return array_slice($this->uniqueStrings($items), 0, 8);
    }

    /**
     * @param array<int, string> $gaps
     * @return array<int, string>
     */
    private function materialDataGaps(array $gaps): array
    {
        $nonBlocking = [
            'pickup_curve_uses_actual_sales_proxy_not_on_books',
        ];

        return array_values(array_filter(
            $this->uniqueStrings($gaps),
            static fn(string $gap): bool => !in_array($gap, $nonBlocking, true)
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     */
    private function hasDriver(array $drivers, string $signal): bool
    {
        foreach ($drivers as $driver) {
            if (($driver['signal'] ?? '') === $signal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $factorNotes
     * @param array<string, mixed> $signals
     */
    private function buildReason(string $direction, array $factorNotes, array $signals): string
    {
        if (!$factorNotes) {
            return 'No material pricing signal; keep manual review.';
        }

        $prefix = match ($direction) {
            'increase' => 'Suggest raising listed price after manual review',
            'decrease' => 'Suggest lowering listed price after manual review',
            default => 'Suggest holding price after manual review',
        };
        $gaps = (array)($signals['data_gaps'] ?? []);
        $gapText = $gaps ? ' Data gaps: ' . implode(', ', array_slice($gaps, 0, 5)) . '.' : '';

        return $prefix . '. Signals: ' . implode(', ', $factorNotes) . '.' . $gapText;
    }

    /**
     * @return array<string, mixed>
     */
    private function driver(string $signal, string $rule, float $changeRate, string $direction): array
    {
        return [
            'signal' => $signal,
            'rule' => $rule,
            'change_rate' => round($changeRate * 100, 2),
            'direction' => $direction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     */
    private function primaryDriverCount(array $drivers): int
    {
        $primarySignals = [];
        foreach ($drivers as $driver) {
            $signal = (string)($driver['signal'] ?? '');
            if (in_array($signal, ['demand_forecast', 'pickup_curve', 'competitor_price', 'inventory', 'price_elasticity'], true)) {
                $primarySignals[$signal] = true;
            }
        }

        return count($primarySignals);
    }

    /**
     * @param array<int, string> $factorNotes
     */
    private function skipReason(float $priceDelta, array $factorNotes, int $primarySignalCount): string
    {
        if (empty($factorNotes)) {
            return 'no_material_signal';
        }
        if (abs($priceDelta) < self::MIN_MATERIAL_CHANGE) {
            return 'price_delta_below_threshold';
        }
        if ($primarySignalCount < self::MIN_PRIMARY_SIGNAL_COUNT) {
            return 'primary_signal_count_insufficient';
        }

        return '';
    }

    /**
     * @param iterable<mixed> $values
     * @return array<int, string>
     */
    private function uniqueStrings(iterable $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $text = trim((string)$value);
            if ($text !== '') {
                $result[$text] = true;
            }
        }

        return array_keys($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return [];
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function rowNumber(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $number = $this->toNullableFloat($row[$key]);
                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     */
    private function rawNumber(mixed $value, array $keys, int $depth = 0): ?float
    {
        if ($depth > 8 || !is_array($value)) {
            return null;
        }
        $wanted = array_fill_keys(array_map('strtolower', $keys), true);
        foreach ($value as $key => $child) {
            $normalized = strtolower((string)$key);
            if (isset($wanted[$normalized])) {
                $number = $this->toNullableFloat($child);
                if ($number !== null) {
                    return $number;
                }
            }
            if (is_array($child)) {
                $number = $this->rawNumber($child, $keys, $depth + 1);
                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, float> $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    /**
     * @param array<int, float> $values
     */
    private function stddev(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }
        $average = $this->average($values);
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $average) ** 2;
        }

        return sqrt($sum / count($values));
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }

        return 0.0;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        $text = str_replace([',', '%'], '', trim((string)$value));
        if ($text === '') {
            return null;
        }
        return is_numeric($text) ? (float)$text : null;
    }
}
