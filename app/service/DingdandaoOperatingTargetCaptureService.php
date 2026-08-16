<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Persists sanitized "订单来了" accommodation facts captured from an
 * already-authenticated, read-only browser session.
 *
 * This boundary accepts no Cookie, token, password, request header or raw
 * account response. Unknown summary values stay null. Explicit room-detail
 * zeroes are retained as observed facts.
 */
final class DingdandaoOperatingTargetCaptureService
{
    public const PROVIDER = 'dingdandao_pms';
    public const SOURCE_URL = 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData';
    public const FORWARD_SOURCE_URL = 'https://www.dingdandao.com/pmsManage/accommodation/calendarReport?identify=pro_basic_calendarReport';
    public const SOURCE_SCOPE = 'today_only';
    public const HISTORICAL_SOURCE_SCOPE = 'historical_single_date';
    public const RENDER_SCOPE_NOTE = '订单来了住宿数据中心总房费口径；不含未在住宿数据中心返回的非房费收入。';

    private const AUTHORITATIVE_IDENTITY_EVIDENCE = [
        'platform_store_selector',
        'verified_api_store_identity',
        'authenticated_account_store',
    ];
    private const SUMMARY_FIELDS = [
        'total_room_fee',
        'adr',
        'occupancy_rate_percent',
        'revpar',
        'sold_room_nights',
        'average_daily_room_nights',
    ];
    private const AUXILIARY_API_PATHS = [
        '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
        '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
    ];
    private const COUNTY_SUMMARY_TRACE =
        'API:/v2/um-b/web/pro/data/businessIndicatorsTotal/county#data';
    private const COUNTY_REGION_TRACE = 'DOM:当前区域指标';
    private const COUNTY_TREND_TRACES = [
        'total_room_fee' =>
            'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=5#data.list[]',
        'adr' =>
            'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=0#data.list[]',
        'occupancy_rate_percent' =>
            'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=1#data.list[]',
        'revpar' =>
            'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=2#data.list[]',
        'sold_room_nights' =>
            'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=3#data.list[]',
    ];
    private const ROW_KINDS = ['room', 'unassigned', 'room_type_total', 'grand_total'];
    private const REVENUE_OVERVIEW_API_PATH =
        '/v2/um-b/web/pro/data/sumAccBusiness';
    private const FORWARD_API_PATH = '/v2/hm-b/pro/web/accom/roomStat/forward/v2';
    private const FORWARD_HORIZONS = [3, 7, 14, 21];
    private const FORWARD_MIN_SOURCE_DAYS = 22;
    private const FORWARD_MAX_SOURCE_DAYS = 31;
    private const FORWARD_DISPLAY_SEMANTICS = 'future_days_after_as_of_date';
    private const COLLECTION_MODES = ['operating_indicators', 'full_diagnostic'];
    private const COLLECTION_RECIPE_IDS = [
        'operating_indicators' => [
            'store_identity',
            'operating_total',
            'accommodation_revenue_overview',
            'sum_detail_room_fee',
            'daily_detail_room_fee',
            'trend_total_room_fee',
        ],
        'full_diagnostic' => [
            'store_identity',
            'operating_total',
            'accommodation_revenue_overview',
            'sum_detail_room_fee',
            'daily_detail_room_fee',
            'sum_detail_room_nights',
            'daily_detail_room_nights',
            'sum_detail_occupancy_rate',
            'daily_detail_occupancy_rate',
            'sum_detail_revpar',
            'daily_detail_revpar',
            'trend_adr',
            'trend_occupancy_rate',
            'trend_revpar',
            'trend_sold_room_nights',
            'trend_total_room_fee',
            'county_total',
            'county_trend_adr',
            'county_trend_occupancy_rate',
            'county_trend_revpar',
            'county_trend_sold_room_nights',
            'county_trend_total_room_fee',
            'forward_room_status',
        ],
    ];
    private const FORWARD_INTEGER_FIELDS = [
        'remaining_sellable_rooms',
        'booked_rooms',
        'unavailable_rooms',
        'oversold_rooms',
        'sold_room_nights',
        'sellable_room_nights',
    ];
    private const FORWARD_DECIMAL_FIELDS = [
        'room_fee',
        'occupancy_rate_percent',
        'adr',
        'revpar',
    ];
    private const FORWARD_METRIC_DEFINITIONS = [
        'remaining_sellable_rooms' => [
            'provider_field' => 'availableSale',
            'definition' =>
                'remaining rooms that can still be sold for the stay date',
        ],
        'booked_rooms' => [
            'provider_field' => 'occupy',
            'definition' => 'rooms already sold for the stay date',
        ],
        'unavailable_rooms' => [
            'provider_field' => 'unavailableSale',
            'definition' =>
                'rooms unavailable because of stop, maintenance, hold, or linked closure',
            'components' => [
                'stopped',
                'maintenance',
                'held',
                'linked_closed',
            ],
        ],
        'room_fee' => [
            'provider_field' => 'roomFee',
            'definition' => 'room fee only',
            'material_exclusions' => [
                'guest_room_consumption',
                'penalties',
                'other_non_room_fee_revenue',
            ],
        ],
        'sellable_room_nights' => [
            'provider_field' => 'avaRoom',
            'formula' => 'remaining_sellable_rooms + booked_rooms',
        ],
        'occupancy_rate_percent' => [
            'provider_field' => 'occ',
            'formula' => 'sold_room_nights / sellable_room_nights * 100',
        ],
        'adr' => [
            'provider_field' => 'adr',
            'formula' => 'room_fee / sold_room_nights',
        ],
        'revpar' => [
            'provider_field' => 'revPar',
            'formula' => 'room_fee / sellable_room_nights',
            'equivalent_formula' => 'occupancy_rate_decimal * adr',
        ],
    ];

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    /**
     * @return array{recipe_plan_hash:string,recipe_count:int}|null
     */
    public static function expectedRecipeEvidence(string $collectionMode): ?array
    {
        $collectionMode = strtolower(trim($collectionMode));
        $recipeIds = self::COLLECTION_RECIPE_IDS[$collectionMode] ?? null;
        if (!is_array($recipeIds) || $recipeIds === []) {
            return null;
        }
        $recipeJson = (string)json_encode(
            $recipeIds,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return [
            'recipe_plan_hash' => hash('sha256', $recipeJson),
            'recipe_count' => count($recipeIds),
        ];
    }

    /**
     * Returns the exact sanitized evidence envelope produced by the trusted
     * endpoint recipe. It contains hashes only, never session material.
     *
     * @return array<string,mixed>|null
     */
    public static function expectedCaptureEvidence(
        string $sourceApiPath,
        string $businessDate,
        string $providerHotelId,
        string $collectionMode
    ): ?array {
        $sourceApiPath = trim($sourceApiPath);
        $businessDate = trim($businessDate);
        $providerHotelId = trim($providerHotelId);
        $collectionMode = strtolower(trim($collectionMode));
        $recipeEvidence = self::expectedRecipeEvidence($collectionMode);
        if ($sourceApiPath === ''
            || !str_starts_with($sourceApiPath, '/')
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate)
            || $providerHotelId === ''
            || $recipeEvidence === null
        ) {
            return null;
        }
        $section = $collectionMode === 'full_diagnostic'
            ? 'pms_full_diagnostic'
            : 'pms_operating';
        $sourceUrlHash = hash('sha256', self::SOURCE_URL);
        $providerHotelIdHash = hash('sha256', $providerHotelId);
        $traceBasis = [
            'platform' => 'dingdandao',
            'section' => $section,
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'source_url_hash' => $sourceUrlHash,
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => $providerHotelIdHash,
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
            'recipe_count' => $recipeEvidence['recipe_count'],
        ];
        $traceJson = (string)json_encode(
            $traceBasis,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return [
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'section' => $section,
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => $providerHotelIdHash,
            'source_url_hash' => $sourceUrlHash,
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipeEvidence['recipe_plan_hash'],
            'recipe_count' => $recipeEvidence['recipe_count'],
            'source_trace_id' => 'dingdandao:' . hash('sha256', $traceJson),
        ];
    }

    /**
     * Rebuilds only the evidence that was already persisted by the v2
     * network-response collector. This is a read-time compatibility adapter,
     * not a promotion path for DOM captures or unknown endpoint records.
     *
     * @param array<string,mixed> $capture
     * @return array<string,mixed>|null
     */
    public static function expectedLegacyV2CaptureEvidence(array $capture): ?array
    {
        $contractVersion = trim((string)(
            $capture['capture_contract_version']
                ?? $capture['contract_version']
                ?? ''
        ));
        $sourceApiPath = trim((string)($capture['source_api_path'] ?? ''));
        $businessDate = trim((string)($capture['business_date'] ?? ''));
        $capturedAt = trim((string)($capture['captured_at'] ?? ''));
        $providerHotelId = trim((string)($capture['provider_hotel_id'] ?? ''));
        $sourceFingerprint = strtolower(trim((string)(
            $capture['source_fingerprint'] ?? ''
        )));
        $captureId = (int)($capture['id'] ?? 0);
        $fieldTrace = is_array($capture['field_trace'] ?? null)
            ? $capture['field_trace']
            : [];
        $requiredFieldTrace = [
            'total_room_fee' =>
                'API:' . $sourceApiPath . '#data.totalRoomFee',
            'adr' => 'API:' . $sourceApiPath . '#data.adr',
            'occupancy_rate_percent' =>
                'API:' . $sourceApiPath . '#data.occ',
            'revpar' => 'API:' . $sourceApiPath . '#data.revPar',
            'sold_room_nights' =>
                'API:' . $sourceApiPath . '#data.totalSalesNight',
            'average_daily_room_nights' =>
                'API:' . $sourceApiPath . '#data.adn',
            'provider_hotel_identity' =>
                'API:/v2/ntw/web/ntw/get#data.id+data.name',
            'room_type_names' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsSumDetail?type=0#data.list[]',
            'room_fee_details' =>
                'API:/v2/um-b/web/pro/data/businessIndicatorsDailyDetail?type=0#data.list[].dailyRoomRate[]',
        ];

        if ($contractVersion !== 'dingdandao_operating_target_capture.v2'
            || (string)($capture['provider'] ?? '') !== self::PROVIDER
            || (string)($capture['source_url'] ?? '') !== self::SOURCE_URL
            || $sourceApiPath
                !== '/v2/um-b/web/pro/data/businessIndicatorsTotal'
            || (string)($capture['source_scope'] ?? '') !== self::SOURCE_SCOPE
            || (string)($capture['capture_method'] ?? '')
                !== 'network_response'
            || (string)($capture['identity_evidence_type'] ?? '')
                !== 'verified_api_store_identity'
            || (string)($capture['identity_status'] ?? '') !== 'matched'
            || (string)($capture['capture_status'] ?? '') !== 'verified'
            || (string)($capture['quality_status'] ?? '') !== 'verified'
            || (string)($capture['reconciliation_status'] ?? '') !== 'matched'
            || (string)($capture['readback_status'] ?? '')
                !== 'readback_verified'
            || $captureId <= 0
            || $providerHotelId === ''
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $capturedAt)
            || substr($capturedAt, 0, 10) !== $businessDate
            || !preg_match('/^[a-f0-9]{64}$/D', $sourceFingerprint)
            || (int)($capture['detail_row_count'] ?? 0) <= 0
        ) {
            return null;
        }
        foreach ($requiredFieldTrace as $field => $expectedTrace) {
            $actualTrace = $fieldTrace[$field] ?? null;
            if (!is_string($actualTrace)
                || !hash_equals($expectedTrace, $actualTrace)
            ) {
                return null;
            }
        }

        $traceJson = (string)json_encode(
            [
                'capture_contract_version' => $contractVersion,
                'capture_id' => $captureId,
                'platform' => 'dingdandao',
                'section' => 'pms_operating',
                'source_path' => $sourceApiPath . '#data',
                'capture_source' => 'persisted_browser_network_response',
                'source_url_hash' => hash('sha256', self::SOURCE_URL),
                'source_kind' => 'pms',
                'business_module' => 'accommodation_operating',
                'source_method' => 'authorized_browser_endpoint',
                'collection_mode' => 'operating_indicators',
                'data_date' => $businessDate,
                'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
                'capture_strategy' => 'browser_response',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'persisted_snapshot_fingerprint' => $sourceFingerprint,
                'required_field_trace_hash' => hash(
                    'sha256',
                    (string)json_encode(
                        $requiredFieldTrace,
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_PRESERVE_ZERO_FRACTION
                        | JSON_INVALID_UTF8_SUBSTITUTE
                    )
                ),
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $evidence = [
            'capture_contract_version' => $contractVersion,
            'capture_id' => $captureId,
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'persisted_browser_network_response',
            'section' => 'pms_operating',
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => 'operating_indicators',
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => hash('sha256', $providerHotelId),
            'source_url_hash' => hash('sha256', self::SOURCE_URL),
            'capture_strategy' => 'browser_response',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'persisted_snapshot_fingerprint' => $sourceFingerprint,
            'required_field_trace_hash' => hash(
                'sha256',
                (string)json_encode(
                    $requiredFieldTrace,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_PRESERVE_ZERO_FRACTION
                    | JSON_INVALID_UTF8_SUBSTITUTE
                )
            ),
        ];
        $evidence['source_trace_id'] =
            'dingdandao:legacy-v2:' . hash('sha256', $traceJson);
        return $evidence;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $expectedHotelName,
        array $input,
        bool $verifiedOnly = false,
        ?string $expectedProviderHotelId = null,
        bool $freshObservation = false
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $userId <= 0 || trim($expectedHotelName) === '') {
            throw new \InvalidArgumentException('dingdandao_capture_scope_invalid');
        }
        $businessDate = $this->date((string)($input['business_date'] ?? ''));
        $sourceUrl = $this->sourceUrl((string)($input['source_url'] ?? ''));
        $sourceApiPath = $this->sourceApiPath($input['source_api_path'] ?? null);
        $captureMethod = strtolower(trim((string)($input['capture_method'] ?? 'browser_assist_dom')));
        if (!in_array($captureMethod, ['browser_assist_dom', 'network_response'], true)) {
            throw new \InvalidArgumentException('dingdandao_capture_method_invalid');
        }
        $sourceScope = strtolower(trim((string)($input['source_scope'] ?? self::SOURCE_SCOPE)));
        if (!in_array(
            $sourceScope,
            [self::SOURCE_SCOPE, self::HISTORICAL_SOURCE_SCOPE],
            true
        )) {
            throw new \InvalidArgumentException('dingdandao_capture_scope_invalid');
        }

        $capturedAt = $this->dateTime((string)($input['captured_at'] ?? ''));
        $providerHotelId = $this->textOrNull($input['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($input['provider_hotel_name'] ?? null, 160);
        $collectionMode = $this->collectionMode(
            $input['collection_mode'] ?? null,
            $verifiedOnly
        );
        $captureEvidence = $this->captureEvidence(
            $input['capture_evidence'] ?? null,
            $sourceUrl,
            $sourceApiPath,
            $businessDate,
            $providerHotelId,
            $collectionMode,
            $verifiedOnly
        );
        $identityEvidenceType = strtolower(trim((string)($input['identity_evidence_type'] ?? 'unverified')));
        $identityStatus = $this->identityStatus(
            $providerHotelName,
            $expectedHotelName,
            $identityEvidenceType
        );
        $summary = $this->summary((array)($input['summary'] ?? []));
        $roomFeeSummaryRows = $this->roomFeeSummaryRows(
            (array)($input['room_fee_summary_rows'] ?? [])
        );
        $details = $this->details((array)($input['room_fee_details'] ?? []));
        $revenueOverview = $this->revenueOverview(
            $input['revenue_overview'] ?? null,
            $businessDate
        );
        $trend = $this->trend((array)($input['trend'] ?? []), $businessDate);
        $fieldTrace = $this->fieldTrace((array)($input['field_trace'] ?? []));
        $auxiliaryQueryStatus = $this->auxiliaryQueryStatus(
            $input['auxiliary_query_status'] ?? []
        );
        $countyContext = $this->countyContext(
            $input['county_context'] ?? null,
            $businessDate
        );
        $forwardRoomStatus = $this->forwardRoomStatus(
            $input['forward_room_status'] ?? null,
            $businessDate
        );
        $observedNow = ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $dateScopeMatches = $this->sourceScopeMatchesBusinessDate(
            $sourceScope,
            $businessDate,
            $observedNow->format('Y-m-d')
        );

        $assessment = $this->assess(
            $summary,
            $details,
            $identityStatus,
            $dateScopeMatches,
            $fieldTrace,
            $roomFeeSummaryRows
        );
        if (!$verifiedOnly && $assessment['quality_status'] === 'verified') {
            $manualGap = $this->gap('dingdandao_trusted_collection_required');
            $assessment['capture_status'] = 'identity_unverified';
            $assessment['quality_status'] = 'unverified';
            $assessment['quality_reason'] = $manualGap['message'];
            $assessment['gaps'] = $this->uniqueGaps([...$assessment['gaps'], $manualGap]);
        }
        if ($verifiedOnly) {
            $expectedProviderHotelId = $this->textOrNull($expectedProviderHotelId, 120);
            $capturedTimestamp = $this->timestampInShanghai($capturedAt);
            $captureAgeSeconds = $capturedTimestamp === false
                ? PHP_INT_MAX
                : $observedNow->getTimestamp() - $capturedTimestamp;
            if ($captureMethod !== 'network_response'
                || $assessment['quality_status'] !== 'verified'
                || $assessment['capture_status'] !== 'verified'
                || $assessment['reconciliation_status'] !== 'matched'
                || $providerHotelId === null
                || $expectedProviderHotelId === null
                || !hash_equals($expectedProviderHotelId, $providerHotelId)
                || $capturedTimestamp === false
                || $captureAgeSeconds < -300
                || $captureAgeSeconds > 1800
                || !$dateScopeMatches
                || (
                    $sourceScope === self::SOURCE_SCOPE
                    && $this->timestampDateInShanghai($capturedTimestamp) !== $businessDate
                )
                || (
                    $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                    && $collectionMode !== 'operating_indicators'
                )
            ) {
                throw new \InvalidArgumentException('dingdandao_capture_not_verified');
            }
        }
        $componentCoverage = $this->componentCoverage(
            $collectionMode,
            $sourceScope,
            $businessDate,
            $summary,
            count($details),
            count($roomFeeSummaryRows),
            $revenueOverview,
            $trend,
            $auxiliaryQueryStatus,
            $countyContext,
            $forwardRoomStatus,
            $assessment
        );
        $snapshot = [
            'contract_version' => 'dingdandao_operating_target_capture.v4',
            'provider' => self::PROVIDER,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_url' => $sourceUrl,
            'source_api_path' => $sourceApiPath,
            'source_scope' => $sourceScope,
            'capture_method' => $captureMethod,
            'collection_mode' => $collectionMode,
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => $providerHotelName,
            'expected_hotel_name' => $expectedHotelName,
            'identity_evidence_type' => $identityEvidenceType,
            'identity_status' => $identityStatus,
            'summary' => $summary,
            'room_fee_summary_rows' => $roomFeeSummaryRows,
            'revenue_overview' => $revenueOverview,
            'detail_row_count' => count($details),
            'detail_room_fee_total' => $assessment['detail_room_fee_total'],
            'detail_fingerprint' => hash('sha256', $this->json($details)),
            'reconciliation_status' => $assessment['reconciliation_status'],
            'reconciliation_basis' => $assessment['reconciliation_basis'],
            'trend' => $trend,
            'auxiliary_query_status' => $auxiliaryQueryStatus,
            'county_context' => $countyContext,
            'forward_room_status' => $forwardRoomStatus,
            'component_coverage' => $componentCoverage,
            'field_trace' => $fieldTrace,
            'capture_evidence' => $captureEvidence,
            'capture_status' => $assessment['capture_status'],
            'quality_status' => $assessment['quality_status'],
            'gap_codes' => array_column($assessment['gaps'], 'code'),
            'captured_at' => $capturedAt,
        ];
        $fingerprintFacts = $snapshot;
        unset($fingerprintFacts['captured_at']);
        $fingerprint = hash('sha256', $this->json($fingerprintFacts));
        $now = $observedNow->format('Y-m-d H:i:s');

        return Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $userId,
            $expectedHotelName,
            $businessDate,
            $sourceUrl,
            $sourceApiPath,
            $sourceScope,
            $captureMethod,
            $capturedAt,
            $providerHotelId,
            $providerHotelName,
            $identityEvidenceType,
            $identityStatus,
            $summary,
            $details,
            $trend,
            $fieldTrace,
            $assessment,
            $snapshot,
            $fingerprint,
            $now,
            $verifiedOnly,
            $freshObservation
        ): array {
            if ($verifiedOnly && !$freshObservation) {
                $existing = Db::name('dingdandao_operating_target_captures')
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('captured_by', $userId)
                    ->where('business_date', $businessDate)
                    ->where('source_scope', $sourceScope)
                    ->where('source_fingerprint', $fingerprint)
                    ->where('quality_status', 'verified')
                    ->where('readback_status', 'readback_verified')
                    ->lock(true)
                    ->find();
                if (is_array($existing)) {
                    $reusedSnapshot = $snapshot;
                    $reusedSnapshot['captured_at'] = (string)($existing['captured_at'] ?? '');
                    $existingDetails = Db::name('dingdandao_room_fee_capture_details')
                        ->where('capture_id', (int)$existing['id'])
                        ->order('source_row_index', 'asc')
                        ->field(
                            'tenant_id,hotel_id,business_date,row_kind,room_type,room_number,room_fee,source_row_index'
                        )
                        ->select()
                        ->toArray();
                    if (!$this->detailReadbackMatches(
                        $existingDetails,
                        $details,
                        $tenantId,
                        $hotelId,
                        $businessDate
                    ) || !$this->mainReadbackMatches(
                        $existing,
                        $tenantId,
                        $hotelId,
                        $businessDate,
                        $providerHotelId,
                        $providerHotelName,
                        $expectedHotelName,
                        $identityEvidenceType,
                        $identityStatus,
                        $sourceUrl,
                        $sourceApiPath,
                        $sourceScope,
                        $captureMethod,
                        $summary,
                        $assessment,
                        $trend,
                        $fieldTrace,
                        $reusedSnapshot,
                        $fingerprint,
                        (string)($existing['captured_at'] ?? ''),
                        $userId
                    )) {
                        throw new \RuntimeException('dingdandao_capture_readback_failed');
                    }
                    return $this->read($tenantId, $hotelId, (int)$existing['id']);
                }
            }
            $captureId = (int)Db::name('dingdandao_operating_target_captures')->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => self::PROVIDER,
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'expected_hotel_name' => $expectedHotelName,
                'identity_evidence_type' => $identityEvidenceType,
                'identity_status' => $identityStatus,
                'source_url' => $sourceUrl,
                'source_api_path' => $sourceApiPath,
                'source_scope' => $sourceScope,
                'capture_method' => $captureMethod,
                'business_date' => $businessDate,
                'total_room_fee' => $summary['total_room_fee'],
                'adr' => $summary['adr'],
                'occupancy_rate_percent' => $summary['occupancy_rate_percent'],
                'revpar' => $summary['revpar'],
                'sold_room_nights' => $summary['sold_room_nights'],
                'average_daily_room_nights' => $summary['average_daily_room_nights'],
                'derived_sellable_room_nights' => $assessment['derived_sellable_room_nights'],
                'detail_room_fee_total' => $assessment['detail_room_fee_total'],
                'detail_row_count' => count($details),
                'reconciliation_status' => $assessment['reconciliation_status'],
                'capture_status' => $assessment['capture_status'],
                'quality_status' => $assessment['quality_status'],
                'quality_reason' => $assessment['quality_reason'],
                'gap_codes_json' => $this->json(array_column($assessment['gaps'], 'code')),
                'trend_json' => $this->json($trend),
                'field_trace_json' => $this->json($fieldTrace),
                'snapshot_json' => $this->json($snapshot),
                'source_fingerprint' => $fingerprint,
                'captured_at' => $capturedAt,
                'captured_by' => $userId,
                'readback_status' => 'pending',
                'readback_verified_at' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            if ($captureId <= 0) {
                throw new \RuntimeException('dingdandao_capture_save_failed');
            }

            foreach ($details as $index => $detail) {
                Db::name('dingdandao_room_fee_capture_details')->insert([
                    'capture_id' => $captureId,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'row_kind' => $detail['row_kind'],
                    'room_type' => $detail['room_type'],
                    'room_number' => $detail['room_number'],
                    'room_fee' => $detail['room_fee'],
                    'source_row_index' => $index + 1,
                    'create_time' => $now,
                ]);
            }

            $storedCount = (int)Db::name('dingdandao_room_fee_capture_details')
                ->where('capture_id', $captureId)
                ->count();
            $storedRoomTotal = (float)Db::name('dingdandao_room_fee_capture_details')
                ->where('capture_id', $captureId)
                ->whereIn('row_kind', ['room', 'unassigned'])
                ->sum('room_fee');
            $storedDetails = Db::name('dingdandao_room_fee_capture_details')
                ->where('capture_id', $captureId)
                ->order('source_row_index', 'asc')
                ->field(
                    'tenant_id,hotel_id,business_date,row_kind,room_type,room_number,room_fee,source_row_index'
                )
                ->select()
                ->toArray();
            $storedCapture = Db::name('dingdandao_operating_target_captures')
                ->where('id', $captureId)
                ->find();
            $readbackVerified = is_array($storedCapture)
                && $storedCount === count($details)
                && abs($storedRoomTotal - (float)$assessment['detail_room_fee_total']) <= 0.01
                && $this->detailReadbackMatches(
                    $storedDetails,
                    $details,
                    $tenantId,
                    $hotelId,
                    $businessDate
                );
            if ($readbackVerified) {
                $readbackVerified = $this->mainReadbackMatches(
                    $storedCapture,
                    $tenantId,
                    $hotelId,
                    $businessDate,
                    $providerHotelId,
                    $providerHotelName,
                    $expectedHotelName,
                    $identityEvidenceType,
                    $identityStatus,
                    $sourceUrl,
                    $sourceApiPath,
                    $sourceScope,
                    $captureMethod,
                    $summary,
                    $assessment,
                    $trend,
                    $fieldTrace,
                    $snapshot,
                    $fingerprint,
                    $capturedAt,
                    $userId
                );
            }
            if ($verifiedOnly && !$readbackVerified) {
                throw new \RuntimeException('dingdandao_capture_readback_failed');
            }
            Db::name('dingdandao_operating_target_captures')
                ->where('id', $captureId)
                ->update([
                    'readback_status' => $readbackVerified ? 'readback_verified' : 'readback_failed',
                    'readback_verified_at' => $readbackVerified ? $now : null,
                    'quality_status' => $readbackVerified
                        ? $assessment['quality_status']
                        : 'collection_failed',
                    'capture_status' => $readbackVerified
                        ? $assessment['capture_status']
                        : 'readback_failed',
                    'quality_reason' => $readbackVerified
                        ? $assessment['quality_reason']
                        : '数据库明细回读数量或合计不一致，已阻断经营目标预填与推送。',
                    'update_time' => $now,
                ]);

            return $this->read($tenantId, $hotelId, $captureId);
        });
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $summary */
    private function mainReadbackMatches(
        array $row,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        ?string $providerHotelId,
        ?string $providerHotelName,
        string $expectedHotelName,
        string $identityEvidenceType,
        string $identityStatus,
        string $sourceUrl,
        ?string $sourceApiPath,
        string $sourceScope,
        string $captureMethod,
        array $summary,
        array $assessment,
        array $trend,
        array $fieldTrace,
        array $snapshot,
        string $fingerprint,
        string $capturedAt,
        int $userId
    ): bool {
        if ((int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['hotel_id'] ?? 0) !== $hotelId
            || (string)($row['provider'] ?? '') !== self::PROVIDER
            || (string)($row['business_date'] ?? '') !== $businessDate
            || (string)($row['source_url'] ?? '') !== $sourceUrl
            || (string)($row['source_api_path'] ?? '') !== (string)$sourceApiPath
            || (string)($row['source_scope'] ?? '') !== $sourceScope
            || (string)($row['capture_method'] ?? '') !== $captureMethod
            || (string)($row['provider_hotel_id'] ?? '') !== (string)$providerHotelId
            || (string)($row['provider_hotel_name'] ?? '') !== (string)$providerHotelName
            || (string)($row['expected_hotel_name'] ?? '') !== $expectedHotelName
            || (string)($row['identity_evidence_type'] ?? '') !== $identityEvidenceType
            || (string)($row['identity_status'] ?? '') !== $identityStatus
            || (int)($row['detail_row_count'] ?? -1) !== (int)($snapshot['detail_row_count'] ?? -2)
            || abs((float)($row['detail_room_fee_total'] ?? -1)
                - (float)($assessment['detail_room_fee_total'] ?? -2)) > 0.01
            || (string)($row['reconciliation_status'] ?? '')
                !== (string)($assessment['reconciliation_status'] ?? '')
            || (string)($row['capture_status'] ?? '')
                !== (string)($assessment['capture_status'] ?? '')
            || (string)($row['quality_status'] ?? '')
                !== (string)($assessment['quality_status'] ?? '')
            || (string)($row['quality_reason'] ?? '')
                !== (string)($assessment['quality_reason'] ?? '')
            || (string)($row['captured_at'] ?? '') !== $capturedAt
            || (int)($row['captured_by'] ?? 0) !== $userId
            || (string)($row['source_fingerprint'] ?? '') !== $fingerprint
            || !$this->jsonReadbackMatches($row['gap_codes_json'] ?? null, array_column(
                (array)($assessment['gaps'] ?? []),
                'code'
            ))
            || !$this->jsonReadbackMatches($row['trend_json'] ?? null, $trend)
            || !$this->jsonReadbackMatches($row['field_trace_json'] ?? null, $fieldTrace)
            || !$this->jsonReadbackMatches($row['snapshot_json'] ?? null, $snapshot)
        ) {
            return false;
        }
        $expectedSellable = $assessment['derived_sellable_room_nights'] ?? null;
        $storedSellable = $row['derived_sellable_room_nights'] ?? null;
        if ($expectedSellable === null ? $storedSellable !== null : (int)$storedSellable !== (int)$expectedSellable) {
            return false;
        }
        foreach (self::SUMMARY_FIELDS as $field) {
            $expected = $summary[$field] ?? null;
            $stored = $row[$field] ?? null;
            if ($expected === null ? $stored !== null : abs((float)$stored - (float)$expected) > 0.01) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $stored
     * @param array<int,array<string,mixed>> $expected
     */
    private function detailReadbackMatches(
        array $stored,
        array $expected,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): bool {
        if (count($stored) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $expectedRow) {
            $storedRow = $stored[$index] ?? null;
            if (!is_array($storedRow)
                || (int)($storedRow['tenant_id'] ?? 0) !== $tenantId
                || (int)($storedRow['hotel_id'] ?? 0) !== $hotelId
                || (string)($storedRow['business_date'] ?? '') !== $businessDate
                || (int)($storedRow['source_row_index'] ?? 0) !== $index + 1
                || (string)($storedRow['row_kind'] ?? '') !== (string)$expectedRow['row_kind']
                || (string)($storedRow['room_type'] ?? '') !== (string)($expectedRow['room_type'] ?? '')
                || (string)($storedRow['room_number'] ?? '') !== (string)($expectedRow['room_number'] ?? '')
                || abs((float)($storedRow['room_fee'] ?? -1) - (float)$expectedRow['room_fee']) > 0.01
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<mixed> $expected */
    private function jsonReadbackMatches(mixed $stored, array $expected): bool
    {
        return $this->json($this->decodeJson($stored)) === $this->json($expected);
    }

    /** @return array<string, mixed> */
    public function latest(int $tenantId, int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return $this->missing(
                $tenantId,
                $hotelId,
                $businessDate,
                'dingdandao_capture_table_missing'
            );
        }
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return $this->missing(
                $tenantId,
                $hotelId,
                $businessDate,
                'dingdandao_capture_missing'
            );
        }
        return $this->present($row, true);
    }

    /** @return array<string, mixed> */
    public function latestForActor(
        int $tenantId,
        int $hotelId,
        int $actorId,
        string $businessDate,
        ?DateTimeImmutable $capturedAtOrBefore = null
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            throw new \InvalidArgumentException('dingdandao_capture_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return [];
        }
        $query = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('captured_by', $actorId)
            ->where('business_date', $businessDate);
        if ($capturedAtOrBefore !== null) {
            $query->where(
                'captured_at',
                '<=',
                $capturedAtOrBefore
                    ->setTimezone(new DateTimeZone('Asia/Shanghai'))
                    ->format('Y-m-d H:i:s')
            );
        }
        $row = $query
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->find();
        return is_array($row) ? $this->present($row, true) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $tenantId, int $hotelId, string $businessDate, int $limit = 2): array
    {
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return [];
        }
        $rows = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('captured_at', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, min($limit, 20)))
            ->select()
            ->toArray();
        return array_map(
            fn(array $row): array => $this->present($row, false),
            $rows
        );
    }

    /** @return array<string, mixed> */
    public function read(int $tenantId, int $hotelId, int $captureId): array
    {
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('id', $captureId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('dingdandao_capture_not_found');
        }
        return $this->present($row, true);
    }

    /** @return array<string, mixed> */
    public function prefill(int $tenantId, int $hotelId, string $businessDate): array
    {
        $capture = $this->latest($tenantId, $hotelId, $businessDate);
        if (($capture['quality_status'] ?? '') !== 'verified'
            || ($capture['capture_status'] ?? '') !== 'verified'
            || ($capture['readback_status'] ?? '') !== 'readback_verified'
        ) {
            return [
                'status' => (string)($capture['quality_status'] ?? 'missing'),
                'prefill' => null,
                'capture' => $capture,
                'collection_result' => $capture['collection_result'] ?? null,
                'gaps' => $capture['gaps'] ?? [[
                    'code' => 'dingdandao_capture_not_verified',
                    'message' => '订单来了今日数据尚未通过身份、日期、字段、对账和数据库回读门禁。',
                ]],
            ];
        }

        return [
            'status' => 'verified',
            'prefill' => [
                'target_date' => $businessDate,
                'actual_revenue' => $capture['summary']['total_room_fee'],
                'sold_room_nights' => $capture['summary']['sold_room_nights'],
                'sellable_room_nights' => $capture['summary']['derived_sellable_room_nights'],
                'fact_scope' => 'accommodation_room_fee',
                'source_type' => 'pms',
                'source_reference' => '订单来了住宿数据中心 / capture:' . (int)$capture['id'],
                'quality_status' => 'verified',
                'quality_reason' => self::RENDER_SCOPE_NOTE
                    . ' 已通过汇总指标、房费明细合计、日期、门店身份和数据库回读校验。',
                'fact_captured_at' => $capture['captured_at'],
            ],
            'capture' => $capture,
            'collection_result' => $capture['collection_result'] ?? null,
            'gaps' => [],
        ];
    }

    /** @param array<string, mixed> $row */
    private function present(array $row, bool $includeDetails): array
    {
        $gaps = [];
        foreach ($this->decodeJson($row['gap_codes_json'] ?? null) as $code) {
            $gaps[] = [
                'code' => (string)$code,
                'message' => $this->gapMessage((string)$code),
            ];
        }
        $details = [];
        if ($includeDetails && $this->tableExists('dingdandao_room_fee_capture_details')) {
            $details = Db::name('dingdandao_room_fee_capture_details')
                ->where('capture_id', (int)$row['id'])
                ->order('source_row_index', 'asc')
                ->field('row_kind,room_type,room_number,room_fee,source_row_index')
                ->select()
                ->toArray();
            $details = array_map(static fn(array $detail): array => [
                'row_kind' => (string)$detail['row_kind'],
                'room_type' => $detail['room_type'] ?? null,
                'room_number' => $detail['room_number'] ?? null,
                'room_fee' => round((float)$detail['room_fee'], 2),
                'source_row_index' => (int)$detail['source_row_index'],
            ], $details);
        }
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? null);
        $fieldTrace = $this->decodeJson($row['field_trace_json'] ?? null);
        $auxiliaryQueryStatus = $this->auxiliaryQueryStatus(
            $snapshot['auxiliary_query_status'] ?? [],
            true
        );
        $countyContext = $this->countyContext(
            $snapshot['county_context'] ?? null,
            (string)$row['business_date']
        );
        try {
            $revenueOverview = $this->revenueOverview(
                $snapshot['revenue_overview'] ?? null,
                (string)$row['business_date']
            );
        } catch (\Throwable) {
            $revenueOverview = $this->partialRevenueOverview(
                (string)$row['business_date'],
                'dingdandao_revenue_overview_contract_upgrade_required'
            );
        }
        $revenueOverview['readback_status'] =
            ($revenueOverview['data_status'] ?? '') === 'verified'
            && ($row['readback_status'] ?? '') === 'readback_verified'
                ? 'readback_verified'
                : 'not_verified';
        $revenueOverview['capture_id'] = (int)$row['id'];
        $revenueOverview['captured_at'] = (string)$row['captured_at'];
        try {
            $forwardRoomStatus = $this->forwardRoomStatus(
                $snapshot['forward_room_status'] ?? null,
                (string)$row['business_date']
            );
        } catch (\Throwable) {
            $forwardRoomStatus = $this->partialForwardRoomStatus(
                (string)$row['business_date'],
                'dingdandao_forward_contract_upgrade_required'
            );
        }
        $forwardRoomStatus['readback_status'] =
            in_array(
                (string)($forwardRoomStatus['data_status'] ?? ''),
                ['verified', 'verified_with_anomalies'],
                true
            )
            && ($row['readback_status'] ?? '') === 'readback_verified'
                ? 'readback_verified'
                : 'not_verified';
        $forwardRoomStatus['capture_id'] = (int)$row['id'];
        $forwardRoomStatus['captured_at'] = (string)$row['captured_at'];

        $capture = [
            'status' => (string)($row['capture_status'] ?? 'unverified'),
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'provider' => self::PROVIDER,
            'capture_contract_version' =>
                (string)($snapshot['contract_version'] ?? ''),
            'provider_label' => '订单来了',
            'provider_hotel_id' => $row['provider_hotel_id'] ?? null,
            'provider_hotel_name' => $row['provider_hotel_name'] ?? null,
            'expected_hotel_name' => (string)$row['expected_hotel_name'],
            'identity_evidence_type' => (string)$row['identity_evidence_type'],
            'identity_status' => (string)$row['identity_status'],
            'business_date' => (string)$row['business_date'],
            'source_url' => (string)$row['source_url'],
            'source_api_path' => $row['source_api_path'] ?? null,
            'source_scope' => (string)$row['source_scope'],
            'capture_method' => (string)$row['capture_method'],
            'collection_mode' => $snapshot['collection_mode'] ?? null,
            'summary' => [
                'total_room_fee' => $this->nullableFloat($row['total_room_fee'] ?? null),
                'adr' => $this->nullableFloat($row['adr'] ?? null),
                'occupancy_rate_percent' => $this->nullableFloat($row['occupancy_rate_percent'] ?? null),
                'revpar' => $this->nullableFloat($row['revpar'] ?? null),
                'sold_room_nights' => $this->nullableInt($row['sold_room_nights'] ?? null),
                'average_daily_room_nights' => $this->nullableFloat($row['average_daily_room_nights'] ?? null),
                'derived_sellable_room_nights' => $this->nullableInt($row['derived_sellable_room_nights'] ?? null),
            ],
            'room_fee_summary_rows' => $this->roomFeeSummaryRows(
                (array)($snapshot['room_fee_summary_rows'] ?? [])
            ),
            'revenue_overview' => $revenueOverview,
            'detail_room_fee_total' => $this->nullableFloat($row['detail_room_fee_total'] ?? null),
            'detail_row_count' => (int)($row['detail_row_count'] ?? 0),
            'room_fee_details' => $details,
            'reconciliation_status' => (string)$row['reconciliation_status'],
            'reconciliation_basis' => (string)($snapshot['reconciliation_basis'] ?? 'unverified'),
            'capture_status' => (string)$row['capture_status'],
            'quality_status' => (string)$row['quality_status'],
            'quality_reason' => $row['quality_reason'] ?? null,
            'gaps' => $gaps,
            'trend' => $this->decodeJson($row['trend_json'] ?? null),
            'auxiliary_query_status' => $auxiliaryQueryStatus,
            'county_context' => $countyContext,
            'forward_room_status' => $forwardRoomStatus,
            'field_trace' => $fieldTrace,
            'capture_evidence' => is_array($snapshot['capture_evidence'] ?? null)
                ? $snapshot['capture_evidence']
                : [],
            'source_trace_id' => (string)($snapshot['capture_evidence']['source_trace_id'] ?? ''),
            'source_url_hash' => (string)($snapshot['capture_evidence']['source_url_hash'] ?? ''),
            'source_fingerprint' => (string)$row['source_fingerprint'],
            'captured_at' => (string)$row['captured_at'],
            'captured_by' => (int)($row['captured_by'] ?? 0),
            'readback_status' => (string)$row['readback_status'],
            'readback_verified_at' => $row['readback_verified_at'] ?? null,
            'created_at' => $row['create_time'] ?? null,
        ];
        if ($capture['capture_evidence'] === []) {
            $legacyEvidence = self::expectedLegacyV2CaptureEvidence($capture);
            if (is_array($legacyEvidence)) {
                $capture['collection_mode'] =
                    (string)$legacyEvidence['collection_mode'];
                $capture['capture_strategy'] =
                    (string)$legacyEvidence['capture_strategy'];
                $capture['capture_evidence'] = $legacyEvidence;
                $capture['source_trace_id'] =
                    (string)$legacyEvidence['source_trace_id'];
                $capture['source_url_hash'] =
                    (string)$legacyEvidence['source_url_hash'];
                $capture['evidence_compatibility'] =
                    'persisted_network_response_v2';
            }
        }
        $capture['component_coverage'] = $this->componentCoverage(
            is_string($capture['collection_mode'] ?? null)
                ? $capture['collection_mode']
                : null,
            (string)$capture['source_scope'],
            (string)$capture['business_date'],
            (array)$capture['summary'],
            (int)$capture['detail_row_count'],
            count((array)$capture['room_fee_summary_rows']),
            $revenueOverview,
            (array)$capture['trend'],
            $auxiliaryQueryStatus,
            $countyContext,
            $forwardRoomStatus,
            [
                'capture_status' => (string)$capture['capture_status'],
                'quality_status' => (string)$capture['quality_status'],
                'reconciliation_status' => (string)$capture['reconciliation_status'],
                'reconciliation_basis' => (string)($capture['reconciliation_basis'] ?? 'unverified'),
            ],
            ($capture['evidence_compatibility'] ?? '')
                === 'persisted_network_response_v2'
        );
        $capture['collection_result'] =
            (new CollectionResultContractService())->fromDingdandaoCapture($capture);
        return $capture;
    }

    /** @param array<string, mixed> $summary */
    private function summary(array $summary): array
    {
        return [
            'total_room_fee' => $this->decimalOrNull($summary['total_room_fee'] ?? null),
            'adr' => $this->decimalOrNull($summary['adr'] ?? null),
            'occupancy_rate_percent' => $this->percentOrNull($summary['occupancy_rate_percent'] ?? null),
            'revpar' => $this->decimalOrNull($summary['revpar'] ?? null),
            'sold_room_nights' => $this->integerOrNull($summary['sold_room_nights'] ?? null),
            'average_daily_room_nights' => $this->decimalOrNull($summary['average_daily_room_nights'] ?? null),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function roomFeeSummaryRows(array $rows): array
    {
        if (count($rows) > 500) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_room_fee_summary_limit_exceeded'
            );
        }
        $normalized = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_room_fee_summary_invalid'
                );
            }
            $providerRoomTypeId = $this->textOrNull(
                $row['provider_room_type_id'] ?? null,
                120
            );
            $roomType = $this->textOrNull($row['room_type'] ?? null, 160);
            $sourceRowIndex = $this->integerOrNull(
                $row['source_row_index'] ?? null
            );
            $roomFee = $this->decimalOrNull($row['room_fee'] ?? null);
            if ($providerRoomTypeId === null
                || $roomType === null
                || $sourceRowIndex === null
                || $sourceRowIndex <= 0
                || $roomFee === null
                || $roomFee < 0
            ) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_room_fee_summary_invalid'
                );
            }
            $key = $providerRoomTypeId . '|' . $sourceRowIndex;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_room_fee_summary_invalid'
                );
            }
            $seen[$key] = true;
            $normalized[] = [
                'provider_room_type_id' => $providerRoomTypeId,
                'room_type' => $roomType,
                'source_row_index' => $sourceRowIndex,
                'room_fee' => $roomFee,
            ];
        }
        return $normalized;
    }

    /** @return array<int, array<string, mixed>> */
    private function details(array $rows): array
    {
        if (count($rows) > 500) {
            throw new \InvalidArgumentException('dingdandao_capture_detail_limit_exceeded');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('dingdandao_capture_detail_invalid');
            }
            $kind = strtolower(trim((string)($row['row_kind'] ?? 'room')));
            if (!in_array($kind, self::ROW_KINDS, true)) {
                throw new \InvalidArgumentException('dingdandao_capture_detail_invalid');
            }
            $fee = $this->decimalOrNull($row['room_fee'] ?? null);
            if ($fee === null) {
                throw new \InvalidArgumentException('dingdandao_capture_detail_invalid');
            }
            $normalized[] = [
                'row_kind' => $kind,
                'room_type' => $this->textOrNull($row['room_type'] ?? null, 160),
                'room_number' => $this->textOrNull($row['room_number'] ?? null, 80),
                'room_fee' => $fee,
            ];
        }
        return $normalized;
    }

    /** @return array<string, mixed> */
    private function assess(
        array $summary,
        array $details,
        string $identityStatus,
        bool $dateScopeMatches,
        array $fieldTrace,
        array $roomFeeSummaryRows = []
    ): array {
        $gaps = [];
        if ($identityStatus !== 'matched') {
            $gaps[] = $this->gap(
                $identityStatus === 'identity_mismatch'
                    ? 'dingdandao_hotel_identity_mismatch'
                    : 'dingdandao_hotel_identity_unverified'
            );
        }
        if (!$dateScopeMatches) {
            $gaps[] = $this->gap('dingdandao_today_only_date_mismatch');
        }
        foreach (self::SUMMARY_FIELDS as $field) {
            if ($summary[$field] === null) {
                $gaps[] = $this->gap('dingdandao_' . $field . '_missing');
            }
            if (!isset($fieldTrace[$field]) || trim((string)$fieldTrace[$field]) === '') {
                $gaps[] = $this->gap('dingdandao_' . $field . '_source_trace_missing');
            }
        }
        if ($details === []) {
            $gaps[] = $this->gap('dingdandao_room_fee_details_missing');
        }
        $roomRows = array_values(array_filter(
            $details,
            static fn(array $row): bool => in_array($row['row_kind'], ['room', 'unassigned'], true)
        ));
        $grandTotals = array_values(array_filter(
            $details,
            static fn(array $row): bool => $row['row_kind'] === 'grand_total'
        ));
        $detailRoomFeeTotal = round(array_sum(array_column($roomRows, 'room_fee')), 2);
        $summaryRowsRoomFeeTotal = round(
            array_sum(array_column($roomFeeSummaryRows, 'room_fee')),
            2
        );
        $summaryTotal = $summary['total_room_fee'];
        $grandTotal = $grandTotals === [] ? null : round((float)end($grandTotals)['room_fee'], 2);
        $reconciliationStatus = 'unverified';
        $reconciliationBasis = 'unverified';
        $detailsCanProvideDailySummary = $roomRows !== []
            && count($grandTotals) === 1
            && array_filter(
                $roomRows,
                static fn(array $row): bool =>
                    abs((float)($row['room_fee'] ?? 0)) > 0.000001
                    && trim((string)($row['room_type'] ?? '')) === ''
            ) === [];
        if ($summaryTotal !== null
            && $roomRows !== []
            && $roomFeeSummaryRows !== []
        ) {
            $reconciliationBasis = 'details_summary_and_room_type_summary';
            $reconciliationStatus = abs($detailRoomFeeTotal - $summaryTotal) <= 0.01
                && abs($summaryRowsRoomFeeTotal - $summaryTotal) <= 0.01
                && ($grandTotal === null || abs($grandTotal - $summaryTotal) <= 0.01)
                ? 'matched'
                : 'mismatch';
            if ($reconciliationStatus !== 'matched') {
                $gaps[] = $this->gap('dingdandao_room_fee_reconciliation_mismatch');
            }
        } elseif ($summaryTotal !== null && $detailsCanProvideDailySummary) {
            $reconciliationBasis = 'details_to_summary_with_grand_total';
            $reconciliationStatus = abs($detailRoomFeeTotal - $summaryTotal) <= 0.01
                && $grandTotal !== null
                && abs($grandTotal - $summaryTotal) <= 0.01
                ? 'matched'
                : 'mismatch';
            if ($reconciliationStatus !== 'matched') {
                $gaps[] = $this->gap('dingdandao_room_fee_reconciliation_mismatch');
            }
        } elseif ($roomFeeSummaryRows === []) {
            $gaps[] = $this->gap('dingdandao_room_fee_summary_missing');
        }

        if ($summaryTotal !== null && $summary['sold_room_nights'] !== null
            && $summary['sold_room_nights'] > 0 && $summary['adr'] !== null
            && abs(round($summaryTotal / $summary['sold_room_nights'], 2) - $summary['adr']) > 0.02
        ) {
            $gaps[] = $this->gap('dingdandao_adr_reconciliation_mismatch');
        }
        if ($summary['sold_room_nights'] !== null && $summary['average_daily_room_nights'] !== null
            && abs($summary['sold_room_nights'] - $summary['average_daily_room_nights']) > 0.01
        ) {
            $gaps[] = $this->gap('dingdandao_average_daily_room_nights_mismatch');
        }

        $derivedSellable = null;
        $occupancy = $summary['occupancy_rate_percent'];
        if ($summary['sold_room_nights'] !== null && $occupancy !== null && $occupancy > 0) {
            $candidate = $summary['sold_room_nights'] / ($occupancy / 100);
            if (abs($candidate - round($candidate)) <= 0.01) {
                $derivedSellable = (int)round($candidate);
            } else {
                $gaps[] = $this->gap('dingdandao_sellable_room_nights_not_integral');
            }
        }
        if ($summaryTotal !== null && $derivedSellable !== null && $derivedSellable > 0
            && $summary['revpar'] !== null
            && abs(round($summaryTotal / $derivedSellable, 2) - $summary['revpar']) > 0.02
        ) {
            $gaps[] = $this->gap('dingdandao_revpar_reconciliation_mismatch');
        }

        $missing = array_filter(
            $gaps,
            static fn(array $gap): bool => str_contains((string)$gap['code'], '_missing')
        ) !== [];
        $reconciliationFailed = array_filter(
            $gaps,
            static fn(array $gap): bool =>
                str_contains((string)$gap['code'], '_mismatch')
                || str_contains((string)$gap['code'], '_not_integral')
        ) !== [];
        if ($identityStatus === 'identity_mismatch') {
            $captureStatus = 'identity_mismatch';
            $qualityStatus = 'identity_mismatch';
        } elseif ($identityStatus !== 'matched') {
            $captureStatus = 'identity_unverified';
            $qualityStatus = 'unverified';
        } elseif (!$dateScopeMatches) {
            $captureStatus = 'date_mismatch';
            $qualityStatus = 'unverified';
        } elseif ($missing) {
            $captureStatus = 'missing';
            $qualityStatus = 'missing';
        } elseif ($reconciliationFailed || $reconciliationStatus !== 'matched') {
            $captureStatus = 'quality_anomaly';
            $qualityStatus = 'collection_failed';
        } else {
            $captureStatus = 'verified';
            $qualityStatus = 'verified';
        }

        return [
            'capture_status' => $captureStatus,
            'quality_status' => $qualityStatus,
            'quality_reason' => $gaps === []
                ? self::RENDER_SCOPE_NOTE . ' 汇总指标与房费明细已对账，来源日期和门店身份已验证。'
                : $gaps[0]['message'],
            'gaps' => $this->uniqueGaps($gaps),
            'detail_room_fee_total' => $detailRoomFeeTotal,
            'derived_sellable_room_nights' => $derivedSellable,
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_basis' => $reconciliationBasis,
        ];
    }

    private function identityStatus(
        ?string $providerHotelName,
        string $expectedHotelName,
        string $evidenceType
    ): string {
        if (!in_array($evidenceType, self::AUTHORITATIVE_IDENTITY_EVIDENCE, true)) {
            return 'unverified';
        }
        if ($providerHotelName === null) {
            return 'unverified';
        }
        return $this->normalizeHotelName($providerHotelName) === $this->normalizeHotelName($expectedHotelName)
            ? 'matched'
            : 'identity_mismatch';
    }

    /** @return array<string, string> */
    private function fieldTrace(array $trace): array
    {
        $result = [];
        $allowed = array_merge(self::SUMMARY_FIELDS, [
            'provider_hotel_identity',
            'room_type_names',
            'room_fee_details',
            'trend',
            'trend_total_room_fee',
            'trend_adr',
            'trend_occupancy_rate_percent',
            'trend_revpar',
            'trend_sold_room_nights',
        ]);
        foreach ($allowed as $field) {
            $value = $this->textOrNull($trace[$field] ?? null, 255);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }
        return $result;
    }

    /** @return array<int,array{api_path:string,type:int,fact_scope:string,status:string,observed_row_count:?int}> */
    private function auxiliaryQueryStatus(
        mixed $input,
        bool $allowLegacyMissingRowCount = false
    ): array
    {
        if ($input === null || $input === []) {
            return [];
        }
        if (!is_array($input) || !array_is_list($input) || count($input) > 6) {
            throw new \InvalidArgumentException('dingdandao_capture_auxiliary_invalid');
        }
        $normalized = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('dingdandao_capture_auxiliary_invalid');
            }
            $path = trim((string)($row['api_path'] ?? ''));
            $type = $row['type'] ?? null;
            $observedRowCount = $row['observed_row_count'] ?? null;
            $rowCountValid = is_int($observedRowCount)
                && $observedRowCount > 0
                && $observedRowCount <= 500;
            if (!in_array($path, self::AUXILIARY_API_PATHS, true)
                || !is_int($type)
                || $type < 1
                || $type > 3
                || (!$rowCountValid && !$allowLegacyMissingRowCount)
                || trim((string)($row['fact_scope'] ?? '')) !== 'auxiliary_metric_only'
                || trim((string)($row['status'] ?? '')) !== 'readable_not_promoted'
            ) {
                throw new \InvalidArgumentException('dingdandao_capture_auxiliary_invalid');
            }
            $key = $type . '|' . $path;
            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException('dingdandao_capture_auxiliary_invalid');
            }
            $normalized[$key] = [
                'api_path' => $path,
                'type' => $type,
                'fact_scope' => 'auxiliary_metric_only',
                'status' => 'readable_not_promoted',
                'observed_row_count' => $rowCountValid
                    ? $observedRowCount
                    : null,
            ];
        }
        ksort($normalized);
        return array_values($normalized);
    }

    /** @return array<string,mixed> */
    private function countyContext(mixed $input, string $businessDate): array
    {
        if ($input === null || $input === []) {
            return $this->partialCountyContext();
        }
        if (!is_array($input)
            || trim((string)($input['fact_scope'] ?? '')) !== 'county_diagnostic_only'
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        $inputStatus = trim((string)($input['data_status'] ?? 'partial'));
        if (!in_array($inputStatus, ['readable_separate', 'partial'], true)) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        $boolCity = $input['bool_city'] ?? null;
        if ($boolCity !== null && !is_bool($boolCity)) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        $regionName = $this->textOrNull($input['region_name'] ?? null, 120);
        $summaryInput = $input['summary'] ?? [];
        if (!is_array($summaryInput)) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        $summary = [
            'total_room_fee' => $this->decimalOrNull($summaryInput['total_room_fee'] ?? null),
            'adr' => $this->decimalOrNull($summaryInput['adr'] ?? null),
            'occupancy_rate_percent' => $this->percentOrNull(
                $summaryInput['occupancy_rate_percent'] ?? null
            ),
            'revpar' => $this->decimalOrNull($summaryInput['revpar'] ?? null),
            'sold_room_nights' => $this->decimalOrNull(
                $summaryInput['sold_room_nights'] ?? null
            ),
            'average_daily_room_nights' => $this->decimalOrNull(
                $summaryInput['average_daily_room_nights'] ?? null
            ),
        ];
        $trendInput = $input['trend'] ?? [];
        if (!is_array($trendInput)) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        $trend = $this->trend($trendInput, $businessDate);
        $fieldTraceInput = $input['field_trace'] ?? [];
        $allowedTraceKeys = [
            'summary',
            'region_name',
            'trend',
            ...array_keys(self::COUNTY_TREND_TRACES),
        ];
        if (!is_array($fieldTraceInput)
            || array_diff(array_keys($fieldTraceInput), $allowedTraceKeys) !== []
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
        }
        if (isset($fieldTraceInput['trend'])
            && !isset($fieldTraceInput['total_room_fee'])
        ) {
            $fieldTraceInput['total_room_fee'] = $fieldTraceInput['trend'];
        }
        unset($fieldTraceInput['trend']);
        $fieldTrace = [];
        foreach ([
            'summary' => self::COUNTY_SUMMARY_TRACE,
            'region_name' => self::COUNTY_REGION_TRACE,
            ...self::COUNTY_TREND_TRACES,
        ] as $key => $expected) {
            $value = trim((string)($fieldTraceInput[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!hash_equals($expected, $value)) {
                throw new \InvalidArgumentException('dingdandao_capture_county_invalid');
            }
            $fieldTrace[$key] = $expected;
        }
        if ($regionName === null) {
            unset($fieldTrace['region_name']);
        } elseif (!isset($fieldTrace['region_name'])) {
            $regionName = null;
        }
        $complete = !in_array(null, array_values($summary), true)
            && isset($fieldTrace['summary']);
        foreach (self::COUNTY_TREND_TRACES as $metric => $_trace) {
            $complete = $complete
                && ($trend[$metric] ?? []) !== []
                && isset($fieldTrace[$metric]);
        }

        return [
            'fact_scope' => 'county_diagnostic_only',
            'data_status' => $inputStatus === 'readable_separate' && $complete
                ? 'readable_separate'
                : 'partial',
            'region_name' => $regionName,
            'region_name_status' => $regionName === null
                ? 'missing_optional'
                : 'verified_dom_label',
            'bool_city' => $boolCity,
            'summary' => $summary,
            'trend' => $trend,
            'field_trace' => $fieldTrace,
        ];
    }

    /** @return array<string,mixed> */
    private function partialCountyContext(): array
    {
        return [
            'fact_scope' => 'county_diagnostic_only',
            'data_status' => 'partial',
            'region_name' => null,
            'region_name_status' => 'missing_optional',
            'bool_city' => null,
            'summary' => array_fill_keys(self::SUMMARY_FIELDS, null),
            'trend' => [],
            'field_trace' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function revenueOverview(mixed $input, string $businessDate): array
    {
        if ($input === null || $input === []) {
            return $this->partialRevenueOverview(
                $businessDate,
                'dingdandao_revenue_overview_not_collected'
            );
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_revenue_overview_invalid'
            );
        }
        $dataStatus = strtolower(trim((string)($input['data_status'] ?? 'partial')));
        if ($dataStatus !== 'verified') {
            $gapCode = 'dingdandao_revenue_overview_response_contract_unverified';
            foreach ((array)($input['gap_codes'] ?? []) as $candidate) {
                $candidate = strtolower(trim((string)$candidate));
                if (preg_match(
                    '/^dingdandao_revenue_overview_[a-z0-9_]{1,100}$/D',
                    $candidate
                ) === 1) {
                    $gapCode = $candidate;
                    break;
                }
            }
            return $this->partialRevenueOverview($businessDate, $gapCode);
        }
        if (($input['contract_version'] ?? '')
                !== 'dingdandao_accommodation_revenue_overview.v1'
            || ($input['fact_scope'] ?? '')
                !== 'whole_hotel_accommodation_turnover'
            || ($input['source_page_url'] ?? '') !== self::SOURCE_URL
            || ($input['source_api_path'] ?? '')
                !== self::REVENUE_OVERVIEW_API_PATH
            || ($input['business_date_from'] ?? '') !== $businessDate
            || ($input['business_date_to'] ?? '') !== $businessDate
            || ($input['reconciliation_status'] ?? '')
                !== 'source_total_preserved'
            || array_values((array)($input['gap_codes'] ?? [])) !== []
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_revenue_overview_invalid'
            );
        }
        $total = $this->signedDecimalOrNull(
            $input['total_accommodation_turnover'] ?? null
        );
        $rawSubjects = $input['subjects'] ?? null;
        if ($total === null
            || !is_array($rawSubjects)
            || !array_is_list($rawSubjects)
            || $rawSubjects === []
            || count($rawSubjects) > 100
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_revenue_overview_invalid'
            );
        }
        $subjects = [];
        $subjectTypes = [];
        $minimumDate = $this->shiftedDate($businessDate, -30);
        foreach ($rawSubjects as $index => $subject) {
            if (!is_array($subject)) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_revenue_overview_invalid'
                );
            }
            $subjectType = $this->signedIntegerOrNull(
                $subject['provider_subject_type'] ?? null
            );
            $subjectName = $this->textOrNull(
                $subject['subject_name'] ?? null,
                160
            );
            $sourceRowIndex = $this->integerOrNull(
                $subject['source_row_index'] ?? null
            );
            $singleDayTotal = $this->signedDecimalOrNull(
                $subject['single_day_total'] ?? null
            );
            $periodTotal = $this->signedDecimalOrNull(
                $subject['period_total'] ?? null
            );
            $percent = $this->signedDecimalOrNull($subject['percent'] ?? null);
            if ($subjectType === null
                || $subjectName === null
                || $sourceRowIndex !== $index + 1
                || $singleDayTotal === null
                || $periodTotal === null
                || isset($subjectTypes[$subjectType])
            ) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_revenue_overview_invalid'
                );
            }
            $subjectTypes[$subjectType] = true;
            $rawPoints = $subject['daily_points'] ?? null;
            if (!is_array($rawPoints)
                || !array_is_list($rawPoints)
                || count($rawPoints) > 100
            ) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_revenue_overview_invalid'
                );
            }
            $points = [];
            foreach ($rawPoints as $point) {
                if (!is_array($point)) {
                    throw new \InvalidArgumentException(
                        'dingdandao_capture_revenue_overview_invalid'
                    );
                }
                $observationDate = $this->date(
                    (string)($point['observation_date'] ?? '')
                );
                $amount = $this->signedDecimalOrNull($point['amount'] ?? null);
                if ($amount === null
                    || $observationDate < $minimumDate
                    || $observationDate > $businessDate
                    || isset($points[$observationDate])
                ) {
                    throw new \InvalidArgumentException(
                        'dingdandao_capture_revenue_overview_invalid'
                    );
                }
                $points[$observationDate] = [
                    'observation_date' => $observationDate,
                    'amount' => $amount,
                ];
            }
            ksort($points);
            $subjects[] = [
                'provider_subject_type' => $subjectType,
                'subject_name' => $subjectName,
                'source_row_index' => $sourceRowIndex,
                'single_day_total' => $singleDayTotal,
                'period_total' => $periodTotal,
                'percent' => $percent,
                'daily_points' => array_values($points),
            ];
        }
        $totalSubject = null;
        foreach ($subjects as $subject) {
            if ($subject['provider_subject_type'] === -1) {
                $totalSubject = $subject;
                break;
            }
        }
        $totalTrendInput = $input['total_trend'] ?? null;
        $expectedTotalTrend = is_array($totalSubject)
            ? (array)($totalSubject['daily_points'] ?? [])
            : [];
        if (!is_array($totalSubject)
            || $expectedTotalTrend === []
            || !is_array($totalTrendInput)
            || !array_is_list($totalTrendInput)
            || count($totalTrendInput) !== count($expectedTotalTrend)
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_revenue_overview_invalid'
            );
        }
        foreach ($expectedTotalTrend as $index => $expectedPoint) {
            $actualPoint = $totalTrendInput[$index] ?? null;
            $actualAmount = is_array($actualPoint)
                ? $this->signedDecimalOrNull($actualPoint['amount'] ?? null)
                : null;
            if (!is_array($actualPoint)
                || ($actualPoint['observation_date'] ?? '')
                    !== ($expectedPoint['observation_date'] ?? '')
                || $actualAmount === null
                || abs($actualAmount - (float)$expectedPoint['amount']) > 0.01
            ) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_revenue_overview_invalid'
                );
            }
        }

        return [
            'contract_version' =>
                'dingdandao_accommodation_revenue_overview.v1',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'source_page_url' => self::SOURCE_URL,
            'source_api_path' => self::REVENUE_OVERVIEW_API_PATH,
            'data_status' => 'verified',
            'business_date_from' => $businessDate,
            'business_date_to' => $businessDate,
            'total_accommodation_turnover' => $total,
            'subjects' => $subjects,
            'total_trend' => $expectedTotalTrend,
            'reconciliation_status' => 'source_total_preserved',
            'metric_boundaries' => $this->revenueMetricBoundaries(),
            'gap_codes' => [],
            'field_trace' => $this->revenueFieldTrace(),
        ];
    }

    /** @return array<string,mixed> */
    private function partialRevenueOverview(
        string $businessDate,
        string $gapCode
    ): array {
        return [
            'contract_version' =>
                'dingdandao_accommodation_revenue_overview.v1',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'source_page_url' => self::SOURCE_URL,
            'source_api_path' => self::REVENUE_OVERVIEW_API_PATH,
            'data_status' => 'partial',
            'business_date_from' => $businessDate,
            'business_date_to' => $businessDate,
            'total_accommodation_turnover' => null,
            'subjects' => [],
            'total_trend' => [],
            'reconciliation_status' => 'unverified',
            'metric_boundaries' => $this->revenueMetricBoundaries(),
            'gap_codes' => [$gapCode],
            'field_trace' => [
                'request' => 'POST:' . self::REVENUE_OVERVIEW_API_PATH
                    . '#startDate&endDate&festivalType',
            ],
        ];
    }

    /** @return array<string,string> */
    private function revenueMetricBoundaries(): array
    {
        return [
            'total_accommodation_turnover' =>
                'provider accommodation turnover including returned accommodation subjects',
            'total_room_fee' =>
                'separate room-fee-only fact from businessIndicatorsTotal',
            'relationship' =>
                'preserve both source facts; do not substitute or force equality',
        ];
    }

    /** @return array<string,string> */
    private function revenueFieldTrace(): array
    {
        return [
            'request' => 'POST:' . self::REVENUE_OVERVIEW_API_PATH
                . '#startDate&endDate&festivalType',
            'total_accommodation_turnover' => 'API:'
                . self::REVENUE_OVERVIEW_API_PATH . '#data.totalConsume',
            'subjects' => 'API:' . self::REVENUE_OVERVIEW_API_PATH
                . '#data.subjects[]',
            'total_trend' => 'API:' . self::REVENUE_OVERVIEW_API_PATH
                . '#data.subjects[type=-1].subjectTypeDates[]',
        ];
    }

    /** @return array<string,mixed> */
    private function forwardRoomStatus(mixed $input, string $businessDate): array
    {
        if ($input === null || $input === []) {
            return $this->partialForwardRoomStatus(
                $businessDate,
                'dingdandao_forward_not_collected'
            );
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $dataStatus = strtolower(trim((string)($input['data_status'] ?? 'partial')));
        if (!in_array(
            $dataStatus,
            ['verified', 'verified_with_anomalies'],
            true
        )) {
            $gapCode = 'dingdandao_forward_response_contract_unverified';
            foreach ((array)($input['gap_codes'] ?? []) as $candidate) {
                $candidate = strtolower(trim((string)$candidate));
                if (preg_match('/^dingdandao_forward_[a-z0-9_]{1,100}$/', $candidate) === 1) {
                    $gapCode = $candidate;
                    break;
                }
            }
            return $this->partialForwardRoomStatus($businessDate, $gapCode);
        }
        $sourceDayCount = $this->integerOrNull($input['source_day_count'] ?? null);
        $sourceCoverageStatus = (string)($input['source_coverage_status'] ?? '');
        $sourceGapCodes = array_values((array)($input['source_gap_codes'] ?? []));
        $inputGapCodes = array_values((array)($input['gap_codes'] ?? []));
        $expectedTopLevelGapCodes = $dataStatus === 'verified_with_anomalies'
            ? ['dingdandao_forward_oversold_present']
            : [];
        if (($input['contract_version'] ?? '') !== 'dingdandao_forward_room_status.v1'
            || ($input['fact_scope'] ?? '') !== 'whole_hotel_forward_room_status'
            || ($input['source_page_url'] ?? '') !== self::FORWARD_SOURCE_URL
            || ($input['source_api_path'] ?? '') !== self::FORWARD_API_PATH
            || ($input['as_of_date'] ?? '') !== $businessDate
            || ($input['range_start_date'] ?? '') !== $businessDate
            || ($input['requested_range_start_date'] ?? '') !== $businessDate
            || ($input['requested_range_end_date'] ?? '')
                !== $this->shiftedDate($businessDate, 30)
            || $sourceDayCount === null
            || $sourceDayCount < self::FORWARD_MIN_SOURCE_DAYS
            || $sourceDayCount > self::FORWARD_MAX_SOURCE_DAYS
            || ($input['range_end_date'] ?? '')
                !== $this->shiftedDate($businessDate, $sourceDayCount - 1)
            || (int)($input['display_day_count'] ?? 0) !== 21
            || ($input['display_semantics'] ?? '')
                !== self::FORWARD_DISPLAY_SEMANTICS
            || ($input['reconciliation_status'] ?? '') !== 'matched'
            || $inputGapCodes !== $expectedTopLevelGapCodes
            || (array)($input['display_horizons'] ?? []) !== self::FORWARD_HORIZONS
            || (array)($input['metric_definitions'] ?? [])
                !== self::FORWARD_METRIC_DEFINITIONS
            || !(
                $sourceCoverageStatus === 'complete'
                && $sourceDayCount === self::FORWARD_MAX_SOURCE_DAYS
                && $sourceGapCodes === []
            )
            && !(
                $sourceCoverageStatus === 'partial'
                && $sourceDayCount < self::FORWARD_MAX_SOURCE_DAYS
                && $sourceGapCodes === [
                    'dingdandao_forward_trailing_coverage_partial',
                ]
            )
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $totalRoomCount = $this->integerOrNull($input['total_room_count'] ?? null);
        if ($totalRoomCount === null || $totalRoomCount <= 0) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $dailyRows = $this->forwardDailyRows(
            $input['daily_rows'] ?? null,
            $businessDate,
            $totalRoomCount,
            $sourceDayCount
        );
        if (count($dailyRows) !== $sourceDayCount) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $roomTypes = $this->forwardRoomTypes(
            $input['room_types'] ?? null,
            $businessDate,
            $sourceDayCount
        );
        if ((int)($input['source_room_type_count'] ?? 0) !== count($roomTypes)
            || array_sum(array_column($roomTypes, 'room_count')) !== $totalRoomCount
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        foreach ($dailyRows as $index => $totalRow) {
            foreach (self::FORWARD_INTEGER_FIELDS as $field) {
                $sum = 0;
                foreach ($roomTypes as $roomType) {
                    $sum += (int)$roomType['daily_rows'][$index][$field];
                }
                if ($sum !== (int)$totalRow[$field]) {
                    throw new \InvalidArgumentException(
                        'dingdandao_capture_forward_reconciliation_invalid'
                    );
                }
            }
            $roomFee = 0.0;
            foreach ($roomTypes as $roomType) {
                $roomFee += (float)$roomType['daily_rows'][$index]['room_fee'];
            }
            if (abs($roomFee - (float)$totalRow['room_fee']) > 0.02) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_forward_reconciliation_invalid'
                );
            }
        }
        $horizons = $this->forwardHorizons($businessDate, $dailyRows);
        if (!$this->forwardHorizonInputMatches($input['horizons'] ?? null, $horizons)) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $anomalies = $this->forwardAnomalies($roomTypes);
        if ($this->json($anomalies)
            !== $this->json(array_values((array)($input['anomalies'] ?? [])))
            || ($dataStatus === 'verified_with_anomalies') !== ($anomalies !== [])
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }

        return [
            'contract_version' => 'dingdandao_forward_room_status.v1',
            'fact_scope' => 'whole_hotel_forward_room_status',
            'source_page_url' => self::FORWARD_SOURCE_URL,
            'source_api_path' => self::FORWARD_API_PATH,
            'data_status' => $dataStatus,
            'as_of_date' => $businessDate,
            'range_start_date' => $businessDate,
            'range_end_date' => $this->shiftedDate(
                $businessDate,
                $sourceDayCount - 1
            ),
            'requested_range_start_date' => $businessDate,
            'requested_range_end_date' => $this->shiftedDate($businessDate, 30),
            'source_day_count' => count($dailyRows),
            'display_day_count' => 21,
            'source_room_type_count' => count($roomTypes),
            'total_room_count' => $totalRoomCount,
            'display_horizons' => self::FORWARD_HORIZONS,
            'display_semantics' => self::FORWARD_DISPLAY_SEMANTICS,
            'source_coverage_status' => $sourceCoverageStatus,
            'source_gap_codes' => $sourceGapCodes,
            'daily_rows' => $dailyRows,
            'room_types' => $roomTypes,
            'horizons' => $horizons,
            'reconciliation_status' => 'matched',
            'gap_codes' => $expectedTopLevelGapCodes,
            'anomalies' => $anomalies,
            'metric_definitions' => self::FORWARD_METRIC_DEFINITIONS,
            'field_trace' => $this->forwardFieldTrace(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function forwardDailyRows(
        mixed $input,
        string $startDate,
        int $roomCount,
        int $expectedDayCount
    ): array {
        if (!is_array($input)
            || !array_is_list($input)
            || count($input) !== $expectedDayCount
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $rows = [];
        foreach ($input as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
            }
            $rows[] = $this->forwardDay(
                $row,
                $this->shiftedDate($startDate, $index),
                $roomCount
            );
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function forwardDay(array $row, string $expectedDate, int $roomCount): array
    {
        if (($row['stay_date'] ?? '') !== $expectedDate) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_date_invalid');
        }
        $normalized = ['stay_date' => $expectedDate];
        foreach (self::FORWARD_INTEGER_FIELDS as $field) {
            $value = $this->integerOrNull($row[$field] ?? null);
            if ($value === null || $value < 0) {
                throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
            }
            $normalized[$field] = $value;
        }
        foreach (self::FORWARD_DECIMAL_FIELDS as $field) {
            $value = $field === 'occupancy_rate_percent'
                ? $this->percentOrNull($row[$field] ?? null)
                : $this->decimalOrNull($row[$field] ?? null);
            if ($value === null || $value < 0) {
                throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
            }
            $normalized[$field] = $value;
        }
        if ($normalized['remaining_sellable_rooms']
                + $normalized['booked_rooms']
                + $normalized['unavailable_rooms'] !== $roomCount
            || $normalized['sellable_room_nights']
                !== $normalized['remaining_sellable_rooms'] + $normalized['booked_rooms']
            || $normalized['sold_room_nights'] !== $normalized['booked_rooms']
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_forward_reconciliation_invalid'
            );
        }
        $sellable = $normalized['sellable_room_nights'];
        $booked = $normalized['booked_rooms'];
        $roomFee = $normalized['room_fee'];
        $expectedOcc = $sellable > 0 ? round(($booked / $sellable) * 100, 2) : 0.0;
        $expectedAdr = $booked > 0 ? round($roomFee / $booked, 2) : 0.0;
        $expectedRevpar = $sellable > 0 ? round($roomFee / $sellable, 2) : 0.0;
        if (abs($normalized['occupancy_rate_percent'] - $expectedOcc) > 0.02
            || abs($normalized['adr'] - $expectedAdr) > 0.02
            || abs($normalized['revpar'] - $expectedRevpar) > 0.02
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_forward_reconciliation_invalid'
            );
        }
        return $normalized;
    }

    /** @return list<array<string,mixed>> */
    private function forwardRoomTypes(
        mixed $input,
        string $businessDate,
        int $expectedDayCount
    ): array
    {
        if (!is_array($input)
            || !array_is_list($input)
            || $input === []
            || count($input) > 100
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
        }
        $result = [];
        $ids = [];
        foreach ($input as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
            }
            $providerRoomTypeId = trim((string)($row['provider_room_type_id'] ?? ''));
            $roomTypeName = $this->textOrNull($row['room_type_name'] ?? null, 160);
            $roomCount = $this->integerOrNull($row['room_count'] ?? null);
            if (preg_match('/^[A-Za-z0-9_-]{1,120}$/', $providerRoomTypeId) !== 1
                || isset($ids[$providerRoomTypeId])
                || $roomTypeName === null
                || $roomCount === null
                || $roomCount <= 0
            ) {
                throw new \InvalidArgumentException('dingdandao_capture_forward_invalid');
            }
            $ids[$providerRoomTypeId] = true;
            $result[] = [
                'provider_room_type_id' => $providerRoomTypeId,
                'room_type_name' => $roomTypeName,
                'room_count' => $roomCount,
                'daily_rows' => $this->forwardDailyRows(
                    $row['daily_rows'] ?? null,
                    $businessDate,
                    $roomCount,
                    $expectedDayCount
                ),
            ];
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $roomTypes
     * @return list<array<string,mixed>>
     */
    private function forwardAnomalies(array $roomTypes): array
    {
        $anomalies = [];
        foreach ($roomTypes as $roomType) {
            foreach ((array)($roomType['daily_rows'] ?? []) as $row) {
                $oversoldRooms = (int)($row['oversold_rooms'] ?? 0);
                if ($oversoldRooms <= 0) {
                    continue;
                }
                $anomalies[] = [
                    'anomaly_type' => 'oversold',
                    'stay_date' => (string)($row['stay_date'] ?? ''),
                    'provider_room_type_id' =>
                        (string)($roomType['provider_room_type_id'] ?? ''),
                    'room_type_name' => (string)($roomType['room_type_name'] ?? ''),
                    'oversold_rooms' => $oversoldRooms,
                ];
            }
        }
        return $anomalies;
    }

    /** @return list<array<string,mixed>> */
    private function forwardHorizons(string $businessDate, array $dailyRows): array
    {
        $byDate = [];
        foreach ($dailyRows as $row) {
            $byDate[(string)$row['stay_date']] = $row;
        }
        $result = [];
        foreach (self::FORWARD_HORIZONS as $days) {
            $rows = [];
            for ($offset = 1; $offset <= $days; $offset++) {
                $date = $this->shiftedDate($businessDate, $offset);
                if (!isset($byDate[$date])) {
                    throw new \InvalidArgumentException(
                        'dingdandao_capture_forward_coverage_invalid'
                    );
                }
                $rows[] = $byDate[$date];
            }
            $sum = static function (array $rows, string $field): float {
                return array_reduce(
                    $rows,
                    static fn(float $total, array $row): float =>
                        $total + (float)$row[$field],
                    0.0
                );
            };
            $sellable = (int)$sum($rows, 'sellable_room_nights');
            $booked = (int)$sum($rows, 'booked_rooms');
            $oversold = (int)$sum($rows, 'oversold_rooms');
            $roomFee = round($sum($rows, 'room_fee'), 2);
            $result[] = [
                'horizon_days' => $days,
                'date_from' => $this->shiftedDate($businessDate, 1),
                'date_to' => $this->shiftedDate($businessDate, $days),
                'expected_days' => $days,
                'covered_days' => count($rows),
                'sellable_room_nights' => $sellable,
                'booked_room_nights' => $booked,
                'remaining_sellable_room_nights' =>
                    (int)$sum($rows, 'remaining_sellable_rooms'),
                'unavailable_room_nights' => (int)$sum($rows, 'unavailable_rooms'),
                'oversold_room_nights' => $oversold,
                'room_fee' => $roomFee,
                'occupancy_rate_percent' => $sellable > 0
                    ? round(($booked / $sellable) * 100, 2)
                    : 0.0,
                'adr' => $booked > 0 ? round($roomFee / $booked, 2) : 0.0,
                'revpar' => $sellable > 0 ? round($roomFee / $sellable, 2) : 0.0,
                'quality_status' => $oversold > 0 ? 'warning' : 'verified',
                'gap_codes' => $oversold > 0
                    ? ['dingdandao_forward_oversold_present']
                    : [],
            ];
        }
        return $result;
    }

    private function forwardHorizonInputMatches(mixed $input, array $expected): bool
    {
        if (!is_array($input) || !array_is_list($input) || count($input) !== count($expected)) {
            return false;
        }
        foreach ($expected as $index => $expectedRow) {
            $row = $input[$index] ?? null;
            if (!is_array($row)) {
                return false;
            }
            foreach ($expectedRow as $field => $value) {
                $actual = $row[$field] ?? null;
                if (is_float($value)) {
                    if (!is_numeric($actual) || abs((float)$actual - $value) > 0.02) {
                        return false;
                    }
                } elseif ($actual !== $value) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function partialForwardRoomStatus(string $businessDate, string $gapCode): array
    {
        $horizons = [];
        foreach (self::FORWARD_HORIZONS as $days) {
            $horizons[] = [
                'horizon_days' => $days,
                'date_from' => $this->shiftedDate($businessDate, 1),
                'date_to' => $this->shiftedDate($businessDate, $days),
                'expected_days' => $days,
                'covered_days' => 0,
                'sellable_room_nights' => null,
                'booked_room_nights' => null,
                'remaining_sellable_room_nights' => null,
                'unavailable_room_nights' => null,
                'oversold_room_nights' => null,
                'room_fee' => null,
                'occupancy_rate_percent' => null,
                'adr' => null,
                'revpar' => null,
                'quality_status' => 'partial',
                'gap_codes' => [$gapCode],
            ];
        }
        return [
            'contract_version' => 'dingdandao_forward_room_status.v1',
            'fact_scope' => 'whole_hotel_forward_room_status',
            'source_page_url' => self::FORWARD_SOURCE_URL,
            'source_api_path' => self::FORWARD_API_PATH,
            'data_status' => 'partial',
            'as_of_date' => $businessDate,
            'range_start_date' => null,
            'range_end_date' => null,
            'requested_range_start_date' => $businessDate,
            'requested_range_end_date' => $this->shiftedDate($businessDate, 30),
            'source_day_count' => 0,
            'display_day_count' => 0,
            'source_room_type_count' => 0,
            'total_room_count' => null,
            'display_horizons' => self::FORWARD_HORIZONS,
            'display_semantics' => self::FORWARD_DISPLAY_SEMANTICS,
            'source_coverage_status' => 'missing',
            'source_gap_codes' => [$gapCode],
            'daily_rows' => [],
            'room_types' => [],
            'horizons' => $horizons,
            'reconciliation_status' => 'unverified',
            'gap_codes' => [$gapCode],
            'anomalies' => [],
            'metric_definitions' => self::FORWARD_METRIC_DEFINITIONS,
            'field_trace' => $this->forwardFieldTrace(),
        ];
    }

    /** @return array<string,string> */
    private function forwardFieldTrace(): array
    {
        return [
            'request' => 'POST:' . self::FORWARD_API_PATH
                . '#pageNum=1&pageSize=9999&startDate&endDate',
            'total_room_count' => 'API:' . self::FORWARD_API_PATH
                . '#data.list[total].roomNum',
            'daily_rows' => 'API:' . self::FORWARD_API_PATH
                . '#data.list[total].dateList[]',
            'room_types' => 'API:' . self::FORWARD_API_PATH
                . '#data.list[roomTypeId].dateList[]',
        ];
    }

    private function shiftedDate(string $date, int $days): string
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('dingdandao_capture_forward_date_invalid');
        }
        return $value->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function trend(array $trend, string $businessDate): array
    {
        $allowed = ['total_room_fee', 'adr', 'occupancy_rate_percent', 'revpar', 'sold_room_nights'];
        $businessDay = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$businessDay instanceof DateTimeImmutable) {
            return [];
        }
        $minimumDay = $businessDay->modify('-30 days');
        $result = [];
        foreach ($allowed as $key) {
            $points = is_array($trend[$key] ?? null) ? $trend[$key] : [];
            $byDate = [];
            foreach (array_slice($points, 0, 100) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $date = trim((string)($point['date'] ?? ''));
                $pointDay = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$pointDay instanceof DateTimeImmutable
                    || $pointDay->format('Y-m-d') !== $date
                    || $pointDay < $minimumDay
                    || $pointDay > $businessDay
                ) {
                    continue;
                }
                $value = $this->decimalOrNull($point['value'] ?? null);
                if ($value !== null) {
                    $byDate[$date] = ['date' => $date, 'value' => $value];
                }
            }
            ksort($byDate);
            $normalized = array_slice(array_values($byDate), -31);
            if ($normalized !== []) {
                $result[$key] = $normalized;
            }
        }
        return $result;
    }

    /**
     * Keeps the verified current-day core independent from diagnostic
     * completeness. A missing trend, regional, forward or auxiliary component
     * must not erase saved core facts, and must not be described as a complete
     * diagnostic either.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $revenueOverview
     * @param array<string,array<int,array<string,mixed>>> $trend
     * @param list<array<string,mixed>> $auxiliaryQueryStatus
     * @param array<string,mixed> $countyContext
     * @param array<string,mixed> $forwardRoomStatus
     * @param array<string,mixed> $assessment
     * @return array<string,mixed>
     */
    private function componentCoverage(
        ?string $collectionMode,
        string $sourceScope,
        string $businessDate,
        array $summary,
        int $detailRowCount,
        int $roomFeeSummaryRowCount,
        array $revenueOverview,
        array $trend,
        array $auxiliaryQueryStatus,
        array $countyContext,
        array $forwardRoomStatus,
        array $assessment,
        bool $legacyRoomFeeSummaryEvidence = false
    ): array {
        $coreGaps = [];
        if (($assessment['capture_status'] ?? '') !== 'verified') {
            $coreGaps[] = 'dingdandao_operating_core_capture_unverified';
        }
        if (($assessment['quality_status'] ?? '') !== 'verified') {
            $coreGaps[] = 'dingdandao_operating_core_quality_unverified';
        }
        if (($assessment['reconciliation_status'] ?? '') !== 'matched') {
            $coreGaps[] = 'dingdandao_operating_core_reconciliation_unmatched';
        }
        if ($detailRowCount <= 0) {
            $coreGaps[] = 'dingdandao_operating_core_details_missing';
        }
        $detailSummaryReconciliation =
            ($assessment['reconciliation_basis'] ?? '')
                === 'details_to_summary_with_grand_total'
            && ($assessment['reconciliation_status'] ?? '') === 'matched';
        if ($roomFeeSummaryRowCount <= 0
            && !$legacyRoomFeeSummaryEvidence
            && !$detailSummaryReconciliation
        ) {
            $coreGaps[] = 'dingdandao_operating_core_sum_detail_missing';
        }
        foreach (self::SUMMARY_FIELDS as $metric) {
            if (!is_int($summary[$metric] ?? null)
                && !is_float($summary[$metric] ?? null)
            ) {
                $coreGaps[] = 'dingdandao_operating_core_metric_missing';
                break;
            }
        }
        $operatingCore = [
            'status' => $coreGaps === [] ? 'verified' : 'partial',
            'fact_scope' => 'whole_hotel_daily_operating',
            'date_role' => 'business_date',
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'expected_metric_count' => count(self::SUMMARY_FIELDS),
            'observed_metric_count' => count(array_filter(
                self::SUMMARY_FIELDS,
                static fn(string $metric): bool =>
                    is_int($summary[$metric] ?? null)
                    || is_float($summary[$metric] ?? null)
            )),
            'detail_row_count' => max(0, $detailRowCount),
            'room_fee_summary_row_count' => max(0, $roomFeeSummaryRowCount),
            'room_fee_summary_evidence_status' =>
                $roomFeeSummaryRowCount > 0
                    ? 'readback_verified'
                    : ($legacyRoomFeeSummaryEvidence
                        ? 'legacy_v2_endpoint_trace_verified'
                        : ($detailSummaryReconciliation
                            ? 'derived_from_room_details_with_grand_total'
                            : 'missing')),
            'gap_codes' => array_values(array_unique($coreGaps)),
        ];

        $revenueGaps = [];
        $revenueVerified =
            ($revenueOverview['data_status'] ?? '') === 'verified'
            && ($revenueOverview['fact_scope'] ?? '')
                === 'whole_hotel_accommodation_turnover'
            && ($revenueOverview['reconciliation_status'] ?? '')
                === 'source_total_preserved'
            && (
                is_int($revenueOverview['total_accommodation_turnover'] ?? null)
                || is_float(
                    $revenueOverview['total_accommodation_turnover'] ?? null
                )
            )
            && count((array)($revenueOverview['subjects'] ?? [])) > 0
            && count((array)($revenueOverview['total_trend'] ?? [])) > 0;
        if (!$revenueVerified) {
            $revenueGaps[] = 'dingdandao_revenue_overview_partial';
        }
        foreach ((array)($revenueOverview['gap_codes'] ?? []) as $gapCode) {
            $gapCode = trim((string)$gapCode);
            if ($gapCode !== '') {
                $revenueGaps[] = $gapCode;
            }
        }
        $revenueCoverage = [
            'status' => $revenueVerified ? 'verified' : 'partial',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'date_role' => 'business_date',
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'total_accommodation_turnover' => $revenueVerified
                ? $revenueOverview['total_accommodation_turnover']
                : null,
            'observed_subject_count' => count(
                (array)($revenueOverview['subjects'] ?? [])
            ),
            'room_fee_is_distinct_fact' => true,
            'gap_codes' => array_values(array_unique($revenueGaps)),
        ];

        $hotelTrend = $this->trendComponentCoverage(
            'whole_hotel_operating_trend',
            $businessDate,
            $trend,
            $summary
        );

        $regionalSummaryGaps = [];
        if (($countyContext['fact_scope'] ?? '') !== 'county_diagnostic_only'
            || ($countyContext['data_status'] ?? '') !== 'readable_separate'
        ) {
            $regionalSummaryGaps[] = 'dingdandao_regional_summary_unverified';
        }
        $regionalSummary = is_array($countyContext['summary'] ?? null)
            ? $countyContext['summary']
            : [];
        foreach (self::SUMMARY_FIELDS as $metric) {
            if (!is_int($regionalSummary[$metric] ?? null)
                && !is_float($regionalSummary[$metric] ?? null)
            ) {
                $regionalSummaryGaps[] =
                    'dingdandao_regional_summary_metric_missing';
                break;
            }
        }
        $regionalSummaryCoverage = [
            'status' => $regionalSummaryGaps === [] ? 'verified' : 'partial',
            'fact_scope' => 'county_diagnostic_only',
            'date_role' => 'business_date',
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'region_name_status' => (string)(
                $countyContext['region_name_status'] ?? 'missing_optional'
            ),
            'expected_metric_count' => count(self::SUMMARY_FIELDS),
            'observed_metric_count' => count(array_filter(
                self::SUMMARY_FIELDS,
                static fn(string $metric): bool =>
                    is_int($regionalSummary[$metric] ?? null)
                    || is_float($regionalSummary[$metric] ?? null)
            )),
            'gap_codes' => array_values(array_unique($regionalSummaryGaps)),
        ];

        $regionalTrend = $this->trendComponentCoverage(
            'county_operating_trend',
            $businessDate,
            is_array($countyContext['trend'] ?? null)
                ? $countyContext['trend']
                : [],
            $regionalSummary
        );

        $forwardGaps = [];
        $verifiedHorizons = [];
        foreach ((array)($forwardRoomStatus['horizons'] ?? []) as $horizon) {
            if (!is_array($horizon)) {
                continue;
            }
            $days = (int)($horizon['horizon_days'] ?? 0);
            if (in_array($days, self::FORWARD_HORIZONS, true)
                && in_array(
                    (string)($horizon['quality_status'] ?? ''),
                    ['verified', 'warning'],
                    true
                )
                && (int)($horizon['covered_days'] ?? 0) === $days
            ) {
                $verifiedHorizons[] = $days;
            }
        }
        sort($verifiedHorizons);
        $forwardDataStatus = (string)($forwardRoomStatus['data_status'] ?? '');
        $forwardWarning = $forwardDataStatus === 'verified_with_anomalies';
        $forwardContractValid = in_array(
            $forwardDataStatus,
            ['verified', 'verified_with_anomalies'],
            true
        )
            && ($forwardRoomStatus['reconciliation_status'] ?? '') === 'matched'
            && $verifiedHorizons === self::FORWARD_HORIZONS;
        if (!$forwardContractValid) {
            $forwardGaps[] = 'dingdandao_forward_room_status_partial';
        }
        foreach ((array)($forwardRoomStatus['gap_codes'] ?? []) as $gapCode) {
            $gapCode = trim((string)$gapCode);
            if ($gapCode !== '') {
                $forwardGaps[] = $gapCode;
            }
        }
        $forwardCoverage = [
            'status' => !$forwardContractValid
                ? 'partial'
                : ($forwardWarning ? 'warning' : 'verified'),
            'fact_scope' => 'whole_hotel_forward_room_status',
            'date_role' => 'stay_date_window',
            'as_of_date' => $businessDate,
            'date_from' => $this->shiftedDate($businessDate, 1),
            'date_to' => $this->shiftedDate($businessDate, 21),
            'expected_horizons' => self::FORWARD_HORIZONS,
            'verified_horizons' => $verifiedHorizons,
            'anomaly_count' => count((array)(
                $forwardRoomStatus['anomalies'] ?? []
            )),
            'gap_codes' => array_values(array_unique($forwardGaps)),
        ];
        if ($sourceScope === self::HISTORICAL_SOURCE_SCOPE) {
            $forwardCoverage = [
                ...$forwardCoverage,
                'status' => 'not_requested',
                'verified_horizons' => [],
                'anomaly_count' => 0,
                'gap_codes' => [],
            ];
        }

        $auxiliaryExpected = $collectionMode === 'full_diagnostic' ? 6 : 0;
        $readableAuxiliary = 0;
        $observedAuxiliaryRows = 0;
        $auxiliaryRowsVerified = $auxiliaryExpected > 0;
        foreach ($auxiliaryQueryStatus as $row) {
            if (!is_array($row)
                || ($row['status'] ?? '') !== 'readable_not_promoted'
            ) {
                continue;
            }
            $readableAuxiliary++;
            $rowCount = $row['observed_row_count'] ?? null;
            if (!is_int($rowCount) || $rowCount <= 0) {
                $auxiliaryRowsVerified = false;
                continue;
            }
            $observedAuxiliaryRows += $rowCount;
        }
        $auxiliaryGaps = [];
        $auxiliaryStatus = 'not_requested';
        if ($auxiliaryExpected > 0) {
            if ($readableAuxiliary !== $auxiliaryExpected) {
                $auxiliaryGaps[] = 'dingdandao_auxiliary_response_partial';
            }
            if (!$auxiliaryRowsVerified) {
                $auxiliaryGaps[] = 'dingdandao_auxiliary_rows_unverified';
            }
            if ($auxiliaryGaps === []) {
                $auxiliaryStatus = 'readable_unmodeled';
                $auxiliaryGaps[] =
                    'dingdandao_auxiliary_metric_schema_not_promoted';
            } else {
                $auxiliaryStatus = 'partial';
            }
        }
        $auxiliaryCoverage = [
            'status' => $auxiliaryStatus,
            'fact_scope' => 'auxiliary_metric_only',
            'date_role' => 'business_date',
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'expected_query_count' => $auxiliaryExpected,
            'readable_query_count' => $readableAuxiliary,
            'observed_row_count' => $observedAuxiliaryRows,
            'promotion_status' => $auxiliaryStatus === 'readable_unmodeled'
                ? 'schema_mapping_required'
                : 'not_ready',
            'gap_codes' => $auxiliaryGaps,
        ];

        $diagnosticComponents = [
            'accommodation_revenue_overview' => $revenueCoverage,
            'hotel_trend' => $hotelTrend,
            'regional_summary' => $regionalSummaryCoverage,
            'regional_trend' => $regionalTrend,
            'forward_room_status' => $forwardCoverage,
            'auxiliary_details' => $auxiliaryCoverage,
        ];
        $diagnosticGaps = [];
        if ($collectionMode === 'full_diagnostic') {
            foreach ($diagnosticComponents as $name => $component) {
                if (($component['status'] ?? '') !== 'verified') {
                    $diagnosticGaps[] =
                        'dingdandao_full_diagnostic_' . $name . '_partial';
                }
            }
        }
        $fullDiagnostic = [
            'status' => $collectionMode !== 'full_diagnostic'
                ? 'not_requested'
                : ($diagnosticGaps === [] ? 'verified' : 'partial'),
            'fact_scope' => 'whole_hotel_operating_diagnostic',
            'date_role' => 'mixed_explicit_component_roles',
            'business_date' => $businessDate,
            'gap_codes' => $diagnosticGaps,
        ];

        $pastTemporal = $this->pastTemporalContext($businessDate, $trend);
        $currentTemporalGaps = array_values(array_unique([
            ...(array)$operatingCore['gap_codes'],
            ...(array)$revenueCoverage['gap_codes'],
        ]));
        $currentTemporal = [
            'status' =>
                $operatingCore['status'] === 'verified'
                && $revenueCoverage['status'] === 'verified'
                    ? 'verified'
                    : 'partial',
            'snapshot_role' =>
                $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                    ? 'historical_daily_snapshot'
                    : 'realtime_snapshot',
            'relation_to_collection_date' =>
                $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                    ? 'past'
                    : 'current',
            'settlement_status' =>
                $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                    ? 'historical_observed'
                    : 'provisional',
            'business_date' => $businessDate,
            'fact_scopes' => [
                'whole_hotel_daily_operating',
                'whole_hotel_accommodation_turnover',
            ],
            'total_room_fee' => $summary['total_room_fee'] ?? null,
            'total_accommodation_turnover' =>
                $revenueCoverage['total_accommodation_turnover'],
            'gap_codes' => $currentTemporalGaps,
        ];
        $futureTemporal = [
            'status' => $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                ? 'not_requested'
                : $forwardCoverage['status'],
            'snapshot_role' => 'forward_snapshot',
            'as_of_date' => $businessDate,
            'stay_date_from' => $this->shiftedDate($businessDate, 1),
            'stay_date_to' => $this->shiftedDate($businessDate, 21),
            'display_horizons' => self::FORWARD_HORIZONS,
            'display_semantics' => self::FORWARD_DISPLAY_SEMANTICS,
            'gap_codes' => $sourceScope === self::HISTORICAL_SOURCE_SCOPE
                ? []
                : $forwardCoverage['gap_codes'],
        ];
        $sourceSurfaces = [
            'accommodation_data_center' => [
                'source_page_url' => self::SOURCE_URL,
                'route_aliases' => [
                    'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/overview',
                ],
                'api_paths' => [
                    self::REVENUE_OVERVIEW_API_PATH,
                    '/v2/um-b/web/pro/data/businessIndicatorsTotal',
                    '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    '/v2/um-b/web/pro/data/businessIndicatorsTrend',
                    '/v2/um-b/web/pro/data/businessIndicatorsTotal/county',
                    '/v2/um-b/web/pro/data/businessIndicatorsTrend/county',
                ],
                'components' => [
                    'accommodation_revenue_overview',
                    'operating_core',
                    'hotel_trend',
                    'regional_summary',
                    'regional_trend',
                    'auxiliary_details',
                ],
            ],
            'forward_room_calendar' => [
                'source_page_url' => self::FORWARD_SOURCE_URL,
                'api_paths' => [self::FORWARD_API_PATH],
                'components' => ['forward_room_status'],
            ],
        ];

        return [
            'contract_version' => 'dingdandao_component_coverage.v2',
            'collection_mode' => $collectionMode,
            'source_scope' => $sourceScope,
            'business_date' => $businessDate,
            'overall_status' => $collectionMode === 'full_diagnostic'
                ? $fullDiagnostic['status']
                : $operatingCore['status'],
            'components' => [
                'operating_core' => $operatingCore,
                ...$diagnosticComponents,
                'full_diagnostic' => $fullDiagnostic,
            ],
            'source_surfaces' => $sourceSurfaces,
            'temporal_context' => [
                'contract_version' => 'dingdandao_temporal_context.v1',
                'past' => $pastTemporal,
                'current' => $currentTemporal,
                'future' => $futureTemporal,
            ],
        ];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $trend
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function trendComponentCoverage(
        string $factScope,
        string $businessDate,
        array $trend,
        array $summary
    ): array {
        $metricKeys = array_keys(self::COUNTY_TREND_TRACES);
        $expectedDates = [];
        for ($offset = -6; $offset <= 0; $offset++) {
            $expectedDates[] = $this->shiftedDate($businessDate, $offset);
        }
        $expectedDateSet = array_fill_keys($expectedDates, true);
        $datesCoveredByEveryMetric = $expectedDateSet;
        $missingDates = [];
        $observedPointCount = 0;
        $gaps = [];

        foreach ($metricKeys as $metric) {
            $pointsByDate = [];
            foreach ((array)($trend[$metric] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $date = trim((string)($point['date'] ?? ''));
                $value = $point['value'] ?? null;
                if (isset($expectedDateSet[$date])
                    && (is_int($value) || is_float($value))
                ) {
                    $pointsByDate[$date] = (float)$value;
                }
            }
            $observedPointCount += count($pointsByDate);
            foreach ($expectedDates as $date) {
                if (!array_key_exists($date, $pointsByDate)) {
                    unset($datesCoveredByEveryMetric[$date]);
                    $missingDates[$date] = true;
                }
            }
            $currentValue = $pointsByDate[$businessDate] ?? null;
            $summaryValue = $summary[$metric] ?? null;
            if ($currentValue === null
                || (!is_int($summaryValue) && !is_float($summaryValue))
                || abs($currentValue - (float)$summaryValue) > 0.02
            ) {
                $gaps[] = 'dingdandao_trend_current_point_mismatch';
            }
        }
        if ($observedPointCount !== count($metricKeys) * count($expectedDates)) {
            $gaps[] = 'dingdandao_trend_window_partial';
        }
        ksort($missingDates);

        return [
            'status' => $gaps === [] ? 'verified' : 'partial',
            'fact_scope' => $factScope,
            'date_role' => 'observation_date_window',
            'date_from' => $expectedDates[0],
            'date_to' => $businessDate,
            'expected_days' => count($expectedDates),
            'covered_days' => count($datesCoveredByEveryMetric),
            'expected_metric_count' => count($metricKeys),
            'expected_point_count' => count($metricKeys) * count($expectedDates),
            'observed_point_count' => $observedPointCount,
            'missing_dates' => array_keys($missingDates),
            'gap_codes' => array_values(array_unique($gaps)),
        ];
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $trend
     * @return array<string,mixed>
     */
    private function pastTemporalContext(
        string $businessDate,
        array $trend
    ): array {
        $metricKeys = array_keys(self::COUNTY_TREND_TRACES);
        $expectedDates = [];
        for ($offset = -6; $offset <= -1; $offset++) {
            $expectedDates[] = $this->shiftedDate($businessDate, $offset);
        }
        $expectedDateSet = array_fill_keys($expectedDates, true);
        $coveredByEveryMetric = $expectedDateSet;
        $missingDates = [];
        $observedPointCount = 0;
        foreach ($metricKeys as $metric) {
            $pointsByDate = [];
            foreach ((array)($trend[$metric] ?? []) as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $date = trim((string)($point['date'] ?? ''));
                $value = $point['value'] ?? null;
                if (isset($expectedDateSet[$date])
                    && (is_int($value) || is_float($value))
                ) {
                    $pointsByDate[$date] = (float)$value;
                }
            }
            $observedPointCount += count($pointsByDate);
            foreach ($expectedDates as $date) {
                if (!isset($pointsByDate[$date])) {
                    unset($coveredByEveryMetric[$date]);
                    $missingDates[$date] = true;
                }
            }
        }
        ksort($missingDates);
        $expectedPointCount = count($metricKeys) * count($expectedDates);
        $verified = $observedPointCount === $expectedPointCount;
        return [
            'status' => $verified ? 'verified' : 'partial',
            'snapshot_role' => 'historical_observation_window',
            'relation_to_business_date' => 'before',
            'settlement_status' => 'historical_observed',
            'date_from' => $expectedDates[0],
            'date_to' => $expectedDates[count($expectedDates) - 1],
            'expected_days' => count($expectedDates),
            'covered_days' => count($coveredByEveryMetric),
            'expected_metric_count' => count($metricKeys),
            'expected_point_count' => $expectedPointCount,
            'observed_point_count' => $observedPointCount,
            'missing_dates' => array_keys($missingDates),
            'gap_codes' => $verified
                ? []
                : ['dingdandao_past_trend_window_partial'],
        ];
    }

    private function sourceUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'www.dingdandao.com'
            || (string)($parts['path'] ?? '') !== '/pmsManage/report/pro/dataCenter/accommodationData'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('dingdandao_capture_source_url_invalid');
        }
        return self::SOURCE_URL;
    }

    private function sourceApiPath(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $parts = parse_url($value);
        if (is_array($parts) && isset($parts['host'])) {
            if (strtolower((string)$parts['host']) !== 'www.dingdandao.com') {
                throw new \InvalidArgumentException('dingdandao_capture_source_api_invalid');
            }
            $value = (string)($parts['path'] ?? '');
        }
        if (!str_starts_with($value, '/') || strlen($value) > 255) {
            throw new \InvalidArgumentException('dingdandao_capture_source_api_invalid');
        }
        return $value;
    }

    private function collectionMode(mixed $value, bool $required): ?string
    {
        $value = strtolower(trim((string)($value ?? '')));
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_collection_mode_invalid'
                );
            }
            return null;
        }
        if (!in_array($value, self::COLLECTION_MODES, true)) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_collection_mode_invalid'
            );
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function captureEvidence(
        mixed $value,
        string $sourceUrl,
        ?string $sourceApiPath,
        string $businessDate,
        ?string $providerHotelId,
        ?string $collectionMode,
        bool $required
    ): array {
        if ($value === null || $value === []) {
            if ($required) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_evidence_invalid'
                );
            }
            return [];
        }
        if (!is_array($value)
            || $sourceApiPath === null
            || $providerHotelId === null
            || $collectionMode === null
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_evidence_invalid'
            );
        }

        $expected = self::expectedCaptureEvidence(
            $sourceApiPath,
            $businessDate,
            $providerHotelId,
            $collectionMode
        );
        if ($expected === null
            || !hash_equals(
                (string)$expected['source_url_hash'],
                hash('sha256', $sourceUrl)
            )
        ) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_evidence_invalid'
            );
        }

        $actualKeys = array_keys($value);
        $expectedKeys = array_keys($expected);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new \InvalidArgumentException(
                'dingdandao_capture_evidence_invalid'
            );
        }
        foreach ($expected as $key => $expectedValue) {
            $actualValue = $value[$key] ?? null;
            $matches = is_string($expectedValue)
                ? is_string($actualValue) && hash_equals($expectedValue, $actualValue)
                : $actualValue === $expectedValue;
            if (!$matches) {
                throw new \InvalidArgumentException(
                    'dingdandao_capture_evidence_invalid'
                );
            }
        }
        return $expected;
    }

    private function sourceScopeMatchesBusinessDate(
        string $sourceScope,
        string $businessDate,
        string $currentDate
    ): bool {
        if ($sourceScope === self::SOURCE_SCOPE) {
            return $businessDate === $currentDate;
        }
        if ($sourceScope === self::HISTORICAL_SOURCE_SCOPE) {
            return $businessDate < $currentDate;
        }
        return false;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('dingdandao_capture_date_invalid');
        }
        return $value;
    }

    private function dateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('dingdandao_capture_time_invalid');
        }
        try {
            // Capture timestamps are business evidence. Treat timezone-less
            // values as Asia/Shanghai and normalize offset-bearing values into
            // the same business timezone before persisting or comparing them.
            $dateTime = new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('dingdandao_capture_time_invalid');
        }
        return $dateTime
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d H:i:s');
    }

    private function timestampDateInShanghai(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
    }

    private function timestampInShanghai(string $value): int|false
    {
        $value = trim($value);
        $dateTime = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('Asia/Shanghai')
        );
        if (!$dateTime instanceof DateTimeImmutable
            || $dateTime->format('Y-m-d H:i:s') !== $value
        ) {
            return false;
        }
        return $dateTime->getTimestamp();
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('dingdandao_capture_number_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new \InvalidArgumentException('dingdandao_capture_number_invalid');
        }
        return round($number, 2);
    }

    private function signedDecimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('dingdandao_capture_number_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('dingdandao_capture_number_invalid');
        }
        return round($number, 2);
    }

    private function percentOrNull(mixed $value): ?float
    {
        $number = $this->decimalOrNull($value);
        if ($number !== null && $number > 100) {
            throw new \InvalidArgumentException('dingdandao_capture_percent_invalid');
        }
        return $number;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('dingdandao_capture_integer_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || floor($number) !== $number) {
            throw new \InvalidArgumentException('dingdandao_capture_integer_invalid');
        }
        return (int)$number;
    }

    private function signedIntegerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('dingdandao_capture_integer_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || floor($number) !== $number) {
            throw new \InvalidArgumentException('dingdandao_capture_integer_invalid');
        }
        return (int)$number;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float)$value, 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int)$value;
    }

    private function textOrNull(mixed $value, int $limit): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }
        $value = preg_replace(
            '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            $value
        ) ?? '';
        return mb_substr($value, 0, $limit);
    }

    private function normalizeHotelName(string $value): string
    {
        return mb_strtolower(preg_replace('/[\s·•・（）()\-—_]+/u', '', trim($value)) ?? '');
    }

    /** @return array{code:string,message:string} */
    private function gap(string $code): array
    {
        return ['code' => $code, 'message' => $this->gapMessage($code)];
    }

    private function gapMessage(string $code): string
    {
        return match (true) {
            $code === 'dingdandao_capture_missing' => '该酒店该日期尚未保存订单来了住宿数据。',
            $code === 'dingdandao_capture_table_missing' => '订单来了住宿数据存储表尚未安装。',
            $code === 'dingdandao_hotel_identity_mismatch' => '订单来了当前门店与宿析OS酒店绑定不一致。',
            $code === 'dingdandao_hotel_identity_unverified' => '只看到了页面标题或通知，尚未从门店选择器或已验证接口取得权威门店身份。',
            $code === 'dingdandao_trusted_collection_required' => '人工上传的门店身份和来源证据未由服务端独立验证，已按未验证状态保存并阻断推送。',
            $code === 'dingdandao_today_only_date_mismatch' => '采集日期与来源范围不一致：今日采集只能保存今日，历史单日采集只能保存已过去的业务日。',
            $code === 'dingdandao_room_fee_details_missing' => '未取得房型/房间房费明细，不能核对汇总总房费。',
            $code === 'dingdandao_room_fee_reconciliation_mismatch' => '房费明细合计与经营指标总房费不一致。',
            $code === 'dingdandao_adr_reconciliation_mismatch' => '总房费除以售出间夜与页面 ADR 不一致。',
            $code === 'dingdandao_average_daily_room_nights_mismatch' => '今日平均每日间夜与累计售出间夜不一致。',
            $code === 'dingdandao_sellable_room_nights_not_integral' => '按已售间夜与入住率反推的可售房夜不是整数。',
            $code === 'dingdandao_revpar_reconciliation_mismatch' => '总房费除以可售房夜与页面 RevPAR 不一致。',
            str_ends_with($code, '_source_trace_missing') => '经营指标缺少页面标签或响应字段来源路径。',
            str_ends_with($code, '_missing') => '订单来了今日经营指标存在缺失字段。',
            default => '订单来了住宿数据未通过真实性门禁。',
        };
    }

    /** @param array<int, array{code:string,message:string}> $gaps */
    private function uniqueGaps(array $gaps): array
    {
        $unique = [];
        foreach ($gaps as $gap) {
            $unique[$gap['code']] = $gap;
        }
        return array_values($unique);
    }

    private function missing(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $code
    ): array
    {
        $currentDate = ($this->clock)()
            ->setTimezone(new DateTimeZone('Asia/Shanghai'))
            ->format('Y-m-d');
        $sourceScope = $businessDate < $currentDate
            ? self::HISTORICAL_SOURCE_SCOPE
            : self::SOURCE_SCOPE;
        $summary = [
            'total_room_fee' => null,
            'adr' => null,
            'occupancy_rate_percent' => null,
            'revpar' => null,
            'sold_room_nights' => null,
            'average_daily_room_nights' => null,
            'derived_sellable_room_nights' => null,
        ];
        $revenueOverview = $this->partialRevenueOverview(
            $businessDate,
            'dingdandao_revenue_overview_not_collected'
        );
        $forwardRoomStatus = $this->partialForwardRoomStatus(
            $businessDate,
            'dingdandao_forward_not_collected'
        );
        $capture = [
            'status' => 'missing',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'provider' => self::PROVIDER,
            'provider_label' => '订单来了',
            'business_date' => $businessDate,
            'source_url' => self::SOURCE_URL,
            'source_scope' => $sourceScope,
            'capture_status' => 'missing',
            'quality_status' => 'missing',
            'readback_status' => 'missing',
            'summary' => $summary,
            'revenue_overview' => [
                ...$revenueOverview,
                'readback_status' => 'missing',
                'capture_id' => null,
                'captured_at' => null,
            ],
            'room_fee_details' => [],
            'forward_room_status' => [
                ...$forwardRoomStatus,
                'readback_status' => 'missing',
                'capture_id' => null,
                'captured_at' => null,
            ],
            'gaps' => [$this->gap($code)],
        ];
        $capture['component_coverage'] = $this->componentCoverage(
            null,
            $sourceScope,
            $businessDate,
            $summary,
            0,
            0,
            $revenueOverview,
            [],
            [],
            $this->partialCountyContext(),
            $forwardRoomStatus,
            [
                'capture_status' => 'missing',
                'quality_status' => 'missing',
                'reconciliation_status' => 'unverified',
            ]
        );
        $capture['collection_result'] =
            (new CollectionResultContractService())->fromDingdandaoCapture($capture);
        return $capture;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Db::getTableInfo($table, 'fields') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function json(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
