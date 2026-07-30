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
    public const SOURCE_SCOPE = 'today_only';
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
            'sum_detail_room_fee',
            'daily_detail_room_fee',
            'trend_total_room_fee',
        ],
        'full_diagnostic' => [
            'store_identity',
            'operating_total',
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

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
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
        ?string $expectedProviderHotelId = null
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
        if ($sourceScope !== self::SOURCE_SCOPE) {
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
        $details = $this->details((array)($input['room_fee_details'] ?? []));
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
        $dateMatchesToday = $businessDate === $observedNow->format('Y-m-d');

        $assessment = $this->assess(
            $summary,
            $details,
            $identityStatus,
            $dateMatchesToday,
            $fieldTrace
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
            $capturedTimestamp = strtotime($capturedAt);
            $captureAgeSeconds = $capturedTimestamp === false
                ? PHP_INT_MAX
                : $observedNow->getTimestamp() - $capturedTimestamp;
            if ($assessment['quality_status'] !== 'verified'
                || $assessment['capture_status'] !== 'verified'
                || $assessment['reconciliation_status'] !== 'matched'
                || $providerHotelId === null
                || $expectedProviderHotelId === null
                || !hash_equals($expectedProviderHotelId, $providerHotelId)
                || $capturedTimestamp === false
                || $captureAgeSeconds < -300
                || $captureAgeSeconds > 1800
                || date('Y-m-d', $capturedTimestamp) !== $businessDate
            ) {
                throw new \InvalidArgumentException('dingdandao_capture_not_verified');
            }
        }
        $snapshot = [
            'contract_version' => 'dingdandao_operating_target_capture.v3',
            'provider' => self::PROVIDER,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_url' => $sourceUrl,
            'source_api_path' => $sourceApiPath,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_method' => $captureMethod,
            'collection_mode' => $collectionMode,
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => $providerHotelName,
            'expected_hotel_name' => $expectedHotelName,
            'identity_evidence_type' => $identityEvidenceType,
            'identity_status' => $identityStatus,
            'summary' => $summary,
            'detail_row_count' => count($details),
            'detail_room_fee_total' => $assessment['detail_room_fee_total'],
            'detail_fingerprint' => hash('sha256', $this->json($details)),
            'reconciliation_status' => $assessment['reconciliation_status'],
            'trend' => $trend,
            'auxiliary_query_status' => $auxiliaryQueryStatus,
            'county_context' => $countyContext,
            'forward_room_status' => $forwardRoomStatus,
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
            $verifiedOnly
        ): array {
            if ($verifiedOnly) {
                $existing = Db::name('dingdandao_operating_target_captures')
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('business_date', $businessDate)
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
                'source_scope' => self::SOURCE_SCOPE,
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
            || (string)($row['source_scope'] ?? '') !== self::SOURCE_SCOPE
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
        $auxiliaryQueryStatus = $this->auxiliaryQueryStatus(
            $snapshot['auxiliary_query_status'] ?? []
        );
        $countyContext = $this->countyContext(
            $snapshot['county_context'] ?? null,
            (string)$row['business_date']
        );
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
            ($forwardRoomStatus['data_status'] ?? '') === 'verified'
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
            'detail_room_fee_total' => $this->nullableFloat($row['detail_room_fee_total'] ?? null),
            'detail_row_count' => (int)($row['detail_row_count'] ?? 0),
            'room_fee_details' => $details,
            'reconciliation_status' => (string)$row['reconciliation_status'],
            'capture_status' => (string)$row['capture_status'],
            'quality_status' => (string)$row['quality_status'],
            'quality_reason' => $row['quality_reason'] ?? null,
            'gaps' => $gaps,
            'trend' => $this->decodeJson($row['trend_json'] ?? null),
            'auxiliary_query_status' => $auxiliaryQueryStatus,
            'county_context' => $countyContext,
            'forward_room_status' => $forwardRoomStatus,
            'field_trace' => $this->decodeJson($row['field_trace_json'] ?? null),
            'capture_evidence' => is_array($snapshot['capture_evidence'] ?? null)
                ? $snapshot['capture_evidence']
                : [],
            'source_trace_id' => (string)($snapshot['capture_evidence']['source_trace_id'] ?? ''),
            'source_url_hash' => (string)($snapshot['capture_evidence']['source_url_hash'] ?? ''),
            'source_fingerprint' => (string)$row['source_fingerprint'],
            'captured_at' => (string)$row['captured_at'],
            'readback_status' => (string)$row['readback_status'],
            'readback_verified_at' => $row['readback_verified_at'] ?? null,
            'created_at' => $row['create_time'] ?? null,
        ];
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
        bool $dateMatchesToday,
        array $fieldTrace
    ): array {
        $gaps = [];
        if ($identityStatus !== 'matched') {
            $gaps[] = $this->gap(
                $identityStatus === 'identity_mismatch'
                    ? 'dingdandao_hotel_identity_mismatch'
                    : 'dingdandao_hotel_identity_unverified'
            );
        }
        if (!$dateMatchesToday) {
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
        $summaryTotal = $summary['total_room_fee'];
        $grandTotal = $grandTotals === [] ? null : round((float)end($grandTotals)['room_fee'], 2);
        $reconciliationStatus = 'unverified';
        if ($summaryTotal !== null && $roomRows !== []) {
            $reconciliationStatus = abs($detailRoomFeeTotal - $summaryTotal) <= 0.01
                && ($grandTotal === null || abs($grandTotal - $summaryTotal) <= 0.01)
                ? 'matched'
                : 'mismatch';
            if ($reconciliationStatus !== 'matched') {
                $gaps[] = $this->gap('dingdandao_room_fee_reconciliation_mismatch');
            }
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
        } elseif (!$dateMatchesToday) {
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

    /** @return array<int,array{api_path:string,type:int,fact_scope:string,status:string}> */
    private function auxiliaryQueryStatus(mixed $input): array
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
            if (!in_array($path, self::AUXILIARY_API_PATHS, true)
                || !is_int($type)
                || $type < 1
                || $type > 3
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
        $complete = !in_array(null, array_values($summary), true)
            && $regionName !== null
            && isset($fieldTrace['summary'], $fieldTrace['region_name']);
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
            'bool_city' => null,
            'summary' => array_fill_keys(self::SUMMARY_FIELDS, null),
            'trend' => [],
            'field_trace' => [],
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
        if ($dataStatus !== 'verified') {
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
        if (($input['contract_version'] ?? '') !== 'dingdandao_forward_room_status.v1'
            || ($input['fact_scope'] ?? '') !== 'whole_hotel_forward_room_status'
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
            || (array)($input['gap_codes'] ?? []) !== []
            || (array)($input['display_horizons'] ?? []) !== self::FORWARD_HORIZONS
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

        return [
            'contract_version' => 'dingdandao_forward_room_status.v1',
            'fact_scope' => 'whole_hotel_forward_room_status',
            'source_api_path' => self::FORWARD_API_PATH,
            'data_status' => 'verified',
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
            'gap_codes' => [],
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
            || $normalized['oversold_rooms'] !== 0
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
                'room_fee' => $roomFee,
                'occupancy_rate_percent' => $sellable > 0
                    ? round(($booked / $sellable) * 100, 2)
                    : 0.0,
                'adr' => $booked > 0 ? round($roomFee / $booked, 2) : 0.0,
                'revpar' => $sellable > 0 ? round($roomFee / $sellable, 2) : 0.0,
                'quality_status' => 'verified',
                'gap_codes' => [],
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

        $section = $collectionMode === 'full_diagnostic'
            ? 'pms_full_diagnostic'
            : 'pms_operating';
        $sourceUrlHash = hash('sha256', $sourceUrl);
        $providerHotelIdHash = hash('sha256', $providerHotelId);
        $recipeIds = self::COLLECTION_RECIPE_IDS[$collectionMode];
        $recipePlanHash = hash('sha256', $this->json($recipeIds));
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
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => count($recipeIds),
        ];
        $expected = [
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
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => count($recipeIds),
            'source_trace_id' => 'dingdandao:'
                . hash('sha256', $this->json($traceBasis)),
        ];

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
        $timestamp = strtotime($value);
        if ($value === '' || $timestamp === false) {
            throw new \InvalidArgumentException('dingdandao_capture_time_invalid');
        }
        return date('Y-m-d H:i:s', $timestamp);
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
            $code === 'dingdandao_today_only_date_mismatch' => '当前试用范围只允许读取今日数据，页面日期与当前日期不一致。',
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
        $capture = [
            'status' => 'missing',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'provider' => self::PROVIDER,
            'provider_label' => '订单来了',
            'business_date' => $businessDate,
            'source_url' => self::SOURCE_URL,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_status' => 'missing',
            'quality_status' => 'missing',
            'readback_status' => 'missing',
            'summary' => [
                'total_room_fee' => null,
                'adr' => null,
                'occupancy_rate_percent' => null,
                'revpar' => null,
                'sold_room_nights' => null,
                'average_daily_room_nights' => null,
                'derived_sellable_room_nights' => null,
            ],
            'room_fee_details' => [],
            'forward_room_status' => [
                ...$this->partialForwardRoomStatus(
                    $businessDate,
                    'dingdandao_forward_not_collected'
                ),
                'readback_status' => 'missing',
                'capture_id' => null,
                'captured_at' => null,
            ],
            'gaps' => [$this->gap($code)],
        ];
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
