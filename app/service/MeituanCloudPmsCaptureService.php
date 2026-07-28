<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

/**
 * Persists sanitized room-operation facts collected from an already
 * authenticated Meituan Cloud PMS workbench.
 *
 * This boundary never accepts or stores cookies, tokens, request headers,
 * guest/order PII or raw account responses. Unknown facts remain null.
 */
final class MeituanCloudPmsCaptureService
{
    public const PROVIDER = 'meituan_cloud_pms';
    public const PROFILE_PLATFORM = 'meituan_cloud_pms';
    public const SOURCE_URL = 'https://pms.meituan.com/#qk-workbench';
    public const SOURCE_SCOPE = 'today_realtime_accommodation';
    public const RENDER_SCOPE_NOTE =
        '美团云 PMS 工作台当日实时预计房费口径；不是已结算财务收入，也不包含未由该工作台返回的非房费收入。';

    private const CAPTURE_TABLE = 'meituan_cloud_pms_captures';
    private const DETAIL_TABLE = 'meituan_cloud_pms_room_type_details';
    private const AUTHORITATIVE_IDENTITY_EVIDENCE = [
        'verified_api_hotel_identity',
        'authenticated_profile_hotel_identity',
        'bound_profile_visible_hotel_name',
    ];
    private const AUTHORITATIVE_DATE_EVIDENCE = [
        'verified_api_business_date',
        'trusted_realtime_workbench_capture',
    ];
    private const REQUIRED_SUMMARY_FIELDS = [
        'estimated_room_revenue',
        'adr',
        'revpar',
        'sold_room_nights',
        'total_rooms',
        'available_rooms',
        'room_type_available_rooms',
        'occupancy_rate_percent',
    ];

    /** @var callable */
    private $clock;

    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn(): DateTimeImmutable =>
            new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
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
            throw new \InvalidArgumentException('meituan_cloud_capture_scope_invalid');
        }
        if (!$this->tableExists(self::CAPTURE_TABLE) || !$this->tableExists(self::DETAIL_TABLE)) {
            throw new \RuntimeException('meituan_cloud_capture_tables_missing');
        }

        $businessDate = $this->date((string)($input['business_date'] ?? ''));
        $sourceUrl = $this->sourceUrl((string)($input['source_url'] ?? ''));
        $sourceScope = strtolower(trim((string)($input['source_scope'] ?? self::SOURCE_SCOPE)));
        if ($sourceScope !== self::SOURCE_SCOPE) {
            throw new \InvalidArgumentException('meituan_cloud_capture_scope_invalid');
        }
        $captureMethod = strtolower(trim((string)($input['capture_method'] ?? 'same_origin_api')));
        if ($captureMethod !== 'same_origin_api') {
            throw new \InvalidArgumentException('meituan_cloud_capture_method_invalid');
        }

        $capturedAt = $this->dateTime((string)($input['captured_at'] ?? ''));
        $capturedTimestamp = strtotime($capturedAt);
        $capturedDate = $capturedTimestamp === false ? '' : date('Y-m-d', $capturedTimestamp);
        $providerHotelId = $this->textOrNull($input['provider_hotel_id'] ?? null, 120);
        $providerHotelName = $this->textOrNull($input['provider_hotel_name'] ?? null, 160);
        $identityEvidenceType = strtolower(trim((string)($input['identity_evidence_type'] ?? 'unverified')));
        $dateEvidenceType = strtolower(trim((string)($input['date_evidence_type'] ?? 'unverified')));
        $identityStatus = $this->identityStatus(
            $providerHotelName,
            $expectedHotelName,
            $identityEvidenceType
        );
        $dateStatus = $this->dateStatus(
            $businessDate,
            $capturedDate,
            $dateEvidenceType
        );
        $summary = $this->summary((array)($input['summary'] ?? []));
        $details = $this->details((array)($input['room_types'] ?? []));
        $fieldTrace = $this->fieldTrace((array)($input['field_trace'] ?? []));
        $warnings = $this->warnings((array)($input['validation_warnings'] ?? []));
        $observedNow = ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $dateMatchesToday = $businessDate === $observedNow->format('Y-m-d');

        $assessment = $this->assess(
            $summary,
            $details,
            $identityStatus,
            $dateStatus,
            $dateMatchesToday,
            $fieldTrace,
            $warnings
        );
        if (!$verifiedOnly && $assessment['quality_status'] === 'verified') {
            $manualGap = $this->gap('meituan_cloud_trusted_collection_required');
            $assessment['capture_status'] = 'identity_unverified';
            $assessment['quality_status'] = 'unverified';
            $assessment['quality_reason'] = $manualGap['message'];
            $assessment['gaps'] = $this->uniqueGaps([...$assessment['gaps'], $manualGap]);
        }
        if ($verifiedOnly) {
            $expectedProviderHotelId = $this->textOrNull($expectedProviderHotelId, 120);
            $captureAgeSeconds = $capturedTimestamp === false
                ? PHP_INT_MAX
                : $observedNow->getTimestamp() - $capturedTimestamp;
            if ($assessment['quality_status'] !== 'verified'
                || $assessment['capture_status'] !== 'verified'
                || $assessment['reconciliation_status'] !== 'matched'
                || ($expectedProviderHotelId !== null
                    && ($providerHotelId === null
                        || !hash_equals($expectedProviderHotelId, $providerHotelId)))
                || $capturedTimestamp === false
                || $captureAgeSeconds < -300
                || $captureAgeSeconds > 1800
                || $capturedDate !== $businessDate
            ) {
                throw new \InvalidArgumentException('meituan_cloud_capture_not_verified');
            }
        }

        $snapshot = [
            'contract_version' => 'meituan_cloud_pms_capture.v1',
            'provider' => self::PROVIDER,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_url' => $sourceUrl,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_method' => $captureMethod,
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => $providerHotelName,
            'expected_hotel_name' => $expectedHotelName,
            'identity_evidence_type' => $identityEvidenceType,
            'identity_status' => $identityStatus,
            'date_evidence_type' => $dateEvidenceType,
            'date_status' => $dateStatus,
            'summary' => $summary,
            'room_type_count' => count($details),
            'availability_difference' => $assessment['availability_difference'],
            'availability_tolerance' => $assessment['availability_tolerance'],
            'reconciliation_status' => $assessment['reconciliation_status'],
            'validation_warnings' => $assessment['warnings'],
            'field_trace' => $fieldTrace,
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
            $captureMethod,
            $capturedAt,
            $providerHotelId,
            $providerHotelName,
            $identityEvidenceType,
            $identityStatus,
            $dateEvidenceType,
            $dateStatus,
            $summary,
            $details,
            $fieldTrace,
            $assessment,
            $snapshot,
            $fingerprint,
            $now,
            $verifiedOnly
        ): array {
            if ($verifiedOnly) {
                $existing = Db::name(self::CAPTURE_TABLE)
                    ->where('tenant_id', $tenantId)
                    ->where('hotel_id', $hotelId)
                    ->where('business_date', $businessDate)
                    ->where('source_fingerprint', $fingerprint)
                    ->where('quality_status', 'verified')
                    ->where('readback_status', 'readback_verified')
                    ->lock(true)
                    ->find();
                if (is_array($existing)) {
                    return $this->read($tenantId, $hotelId, (int)$existing['id']);
                }
            }

            $captureId = (int)Db::name(self::CAPTURE_TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => self::PROVIDER,
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => $providerHotelName,
                'expected_hotel_name' => $expectedHotelName,
                'identity_evidence_type' => $identityEvidenceType,
                'identity_status' => $identityStatus,
                'date_evidence_type' => $dateEvidenceType,
                'date_status' => $dateStatus,
                'source_url' => $sourceUrl,
                'source_scope' => self::SOURCE_SCOPE,
                'capture_method' => $captureMethod,
                'business_date' => $businessDate,
                'estimated_room_revenue' => $summary['estimated_room_revenue'],
                'adr' => $summary['adr'],
                'revpar' => $summary['revpar'],
                'sold_room_nights' => $summary['sold_room_nights'],
                'total_rooms' => $summary['total_rooms'],
                'available_rooms' => $summary['available_rooms'],
                'room_type_available_rooms' => $summary['room_type_available_rooms'],
                'occupancy_rate_percent' => $summary['occupancy_rate_percent'],
                'sale_order_count' => $summary['sale_order_count'],
                'room_type_count' => count($details),
                'availability_difference' => $assessment['availability_difference'],
                'availability_tolerance' => $assessment['availability_tolerance'],
                'reconciliation_status' => $assessment['reconciliation_status'],
                'capture_status' => $assessment['capture_status'],
                'quality_status' => $assessment['quality_status'],
                'quality_reason' => $assessment['quality_reason'],
                'gap_codes_json' => $this->json(array_column($assessment['gaps'], 'code')),
                'validation_warnings_json' => $this->json($assessment['warnings']),
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
                throw new \RuntimeException('meituan_cloud_capture_save_failed');
            }

            foreach ($details as $index => $detail) {
                Db::name(self::DETAIL_TABLE)->insert([
                    'capture_id' => $captureId,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'business_date' => $businessDate,
                    'room_type' => $detail['room_type'],
                    'total_rooms' => $detail['total_rooms'],
                    'sold_rooms' => $detail['sold_rooms'],
                    'available_rooms' => $detail['available_rooms'],
                    'overbooked_rooms' => $detail['overbooked_rooms'],
                    'source_row_index' => $index + 1,
                    'create_time' => $now,
                ]);
            }

            $storedCount = (int)Db::name(self::DETAIL_TABLE)
                ->where('capture_id', $captureId)
                ->count();
            $storedTotal = (int)Db::name(self::DETAIL_TABLE)
                ->where('capture_id', $captureId)
                ->sum('total_rooms');
            $storedSold = (int)Db::name(self::DETAIL_TABLE)
                ->where('capture_id', $captureId)
                ->sum('sold_rooms');
            $storedAvailable = (int)Db::name(self::DETAIL_TABLE)
                ->where('capture_id', $captureId)
                ->sum('available_rooms');
            $storedCapture = Db::name(self::CAPTURE_TABLE)
                ->where('id', $captureId)
                ->find();
            $readbackVerified = is_array($storedCapture)
                && $storedCount === count($details)
                && $storedTotal === array_sum(array_column($details, 'total_rooms'))
                && $storedSold === array_sum(array_column($details, 'sold_rooms'))
                && $storedAvailable === array_sum(array_column($details, 'available_rooms'))
                && $this->mainReadbackMatches(
                    $storedCapture,
                    $tenantId,
                    $hotelId,
                    $businessDate,
                    $providerHotelId,
                    $providerHotelName,
                    $sourceUrl,
                    $summary,
                    $fingerprint
                );
            if ($verifiedOnly && !$readbackVerified) {
                throw new \RuntimeException('meituan_cloud_capture_readback_failed');
            }
            Db::name(self::CAPTURE_TABLE)
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
                        : '数据库房型明细或汇总字段回读不一致，已阻断经营目标预填。',
                    'update_time' => $now,
                ]);

            return $this->read($tenantId, $hotelId, $captureId);
        });
    }

    /** @return array<string,mixed> */
    public function latest(int $tenantId, int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists(self::CAPTURE_TABLE)) {
            return $this->missing($hotelId, $businessDate, 'meituan_cloud_capture_table_missing');
        }
        $row = Db::name(self::CAPTURE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('id', 'desc')
            ->find();
        return is_array($row)
            ? $this->present($row, true)
            : $this->missing($hotelId, $businessDate, 'meituan_cloud_capture_missing');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $tenantId, int $hotelId, string $businessDate, int $limit = 2): array
    {
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists(self::CAPTURE_TABLE)) {
            return [];
        }
        $rows = Db::name(self::CAPTURE_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('id', 'desc')
            ->limit(max(1, min($limit, 20)))
            ->select()
            ->toArray();
        return array_map(
            fn(array $row): array => $this->present($row, false),
            $rows
        );
    }

    /** @return array<string,mixed> */
    public function read(int $tenantId, int $hotelId, int $captureId): array
    {
        $row = Db::name(self::CAPTURE_TABLE)
            ->where('id', $captureId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('meituan_cloud_capture_not_found');
        }
        return $this->present($row, true);
    }

    /** @return array<string,mixed> */
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
                'gaps' => $capture['gaps'] ?? [[
                    'code' => 'meituan_cloud_capture_not_verified',
                    'message' => '美团云 PMS 今日数据尚未通过身份、日期、字段、差值对账和数据库回读门禁。',
                ]],
            ];
        }

        return [
            'status' => 'verified',
            'prefill' => [
                'target_date' => $businessDate,
                'actual_revenue' => $capture['summary']['estimated_room_revenue'],
                'sold_room_nights' => $capture['summary']['sold_room_nights'],
                'sellable_room_nights' => $capture['summary']['total_rooms'],
                'fact_scope' => 'accommodation_room_fee',
                'source_type' => 'pms',
                'source_reference' => '美团云 PMS 当日经营概览 / capture:' . (int)$capture['id'],
                'quality_status' => 'verified',
                'quality_reason' => self::RENDER_SCOPE_NOTE
                    . ' 已通过工作台汇总、房型库存、门店身份、日期、差值和数据库回读校验。',
                'fact_captured_at' => $capture['captured_at'],
            ],
            'capture' => $capture,
            'gaps' => [],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row, bool $includeDetails): array
    {
        $details = [];
        if ($includeDetails && $this->tableExists(self::DETAIL_TABLE)) {
            $details = Db::name(self::DETAIL_TABLE)
                ->where('capture_id', (int)$row['id'])
                ->order('source_row_index', 'asc')
                ->field('room_type,total_rooms,sold_rooms,available_rooms,overbooked_rooms,source_row_index')
                ->select()
                ->toArray();
            $details = array_map(static fn(array $detail): array => [
                'room_type' => (string)$detail['room_type'],
                'total_rooms' => (int)$detail['total_rooms'],
                'sold_rooms' => (int)$detail['sold_rooms'],
                'available_rooms' => (int)$detail['available_rooms'],
                'overbooked_rooms' => (int)$detail['overbooked_rooms'],
                'source_row_index' => (int)$detail['source_row_index'],
            ], $details);
        }
        $gaps = array_map(
            fn(mixed $code): array => [
                'code' => (string)$code,
                'message' => $this->gapMessage((string)$code),
            ],
            $this->decodeJson($row['gap_codes_json'] ?? null)
        );

        return [
            'status' => (string)($row['quality_status'] ?? 'unverified'),
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'provider' => self::PROVIDER,
            'provider_label' => '美团云 PMS',
            'provider_hotel_id' => $row['provider_hotel_id'] ?? null,
            'provider_hotel_name' => $row['provider_hotel_name'] ?? null,
            'expected_hotel_name' => (string)$row['expected_hotel_name'],
            'identity_evidence_type' => (string)$row['identity_evidence_type'],
            'identity_status' => (string)$row['identity_status'],
            'date_evidence_type' => (string)$row['date_evidence_type'],
            'date_status' => (string)$row['date_status'],
            'business_date' => (string)$row['business_date'],
            'source_url' => self::SOURCE_URL,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_method' => (string)$row['capture_method'],
            'summary' => [
                'estimated_room_revenue' => $this->nullableFloat($row['estimated_room_revenue'] ?? null),
                'adr' => $this->nullableFloat($row['adr'] ?? null),
                'revpar' => $this->nullableFloat($row['revpar'] ?? null),
                'sold_room_nights' => $this->nullableInt($row['sold_room_nights'] ?? null),
                'total_rooms' => $this->nullableInt($row['total_rooms'] ?? null),
                'available_rooms' => $this->nullableInt($row['available_rooms'] ?? null),
                'room_type_available_rooms' => $this->nullableInt($row['room_type_available_rooms'] ?? null),
                'occupancy_rate_percent' => $this->nullableFloat($row['occupancy_rate_percent'] ?? null),
                'sale_order_count' => $this->nullableInt($row['sale_order_count'] ?? null),
            ],
            'room_type_count' => (int)($row['room_type_count'] ?? 0),
            'room_types' => $details,
            'availability_difference' => $this->nullableInt($row['availability_difference'] ?? null),
            'availability_tolerance' => $this->nullableInt($row['availability_tolerance'] ?? null),
            'reconciliation_status' => (string)$row['reconciliation_status'],
            'capture_status' => (string)$row['capture_status'],
            'quality_status' => (string)$row['quality_status'],
            'quality_reason' => $row['quality_reason'] ?? null,
            'readback_status' => (string)$row['readback_status'],
            'captured_at' => (string)$row['captured_at'],
            'readback_verified_at' => $row['readback_verified_at'] ?? null,
            'source_fingerprint' => (string)$row['source_fingerprint'],
            'field_trace' => $this->decodeJson($row['field_trace_json'] ?? null),
            'validation_warnings' => $this->decodeJson($row['validation_warnings_json'] ?? null),
            'gaps' => $gaps,
            'scope_note' => self::RENDER_SCOPE_NOTE,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $summary
     */
    private function mainReadbackMatches(
        array $row,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        ?string $providerHotelId,
        ?string $providerHotelName,
        string $sourceUrl,
        array $summary,
        string $fingerprint
    ): bool {
        if ((int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['hotel_id'] ?? 0) !== $hotelId
            || (string)($row['provider'] ?? '') !== self::PROVIDER
            || (string)($row['business_date'] ?? '') !== $businessDate
            || (string)($row['source_url'] ?? '') !== $sourceUrl
            || (string)($row['source_scope'] ?? '') !== self::SOURCE_SCOPE
            || (string)($row['provider_hotel_id'] ?? '') !== (string)$providerHotelId
            || (string)($row['provider_hotel_name'] ?? '') !== (string)$providerHotelName
            || (string)($row['source_fingerprint'] ?? '') !== $fingerprint
        ) {
            return false;
        }
        foreach ([...self::REQUIRED_SUMMARY_FIELDS, 'sale_order_count'] as $field) {
            $expected = $summary[$field] ?? null;
            $stored = $row[$field] ?? null;
            if ($expected === null ? $stored !== null : abs((float)$stored - (float)$expected) > 0.01) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function summary(array $input): array
    {
        return [
            'estimated_room_revenue' => $this->decimalOrNull($input['estimated_room_revenue'] ?? null),
            'adr' => $this->decimalOrNull($input['adr'] ?? null),
            'revpar' => $this->decimalOrNull($input['revpar'] ?? null),
            'sold_room_nights' => $this->integerOrNull($input['sold_room_nights'] ?? null),
            'total_rooms' => $this->integerOrNull($input['total_rooms'] ?? null),
            'available_rooms' => $this->integerOrNull($input['available_rooms'] ?? null),
            'room_type_available_rooms' => $this->integerOrNull($input['room_type_available_rooms'] ?? null),
            'occupancy_rate_percent' => $this->percentOrNull($input['occupancy_rate_percent'] ?? null),
            'sale_order_count' => $this->integerOrNull($input['sale_order_count'] ?? null),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function details(array $rows): array
    {
        if (count($rows) > 300) {
            throw new \InvalidArgumentException('meituan_cloud_room_type_limit_exceeded');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('meituan_cloud_room_type_invalid');
            }
            $roomType = $this->textOrNull($row['room_type'] ?? null, 160);
            $total = $this->integerOrNull($row['total_rooms'] ?? null);
            $sold = $this->integerOrNull($row['sold_rooms'] ?? null);
            if ($roomType === null || $total === null || $sold === null) {
                throw new \InvalidArgumentException('meituan_cloud_room_type_invalid');
            }
            $available = max($total - $sold, 0);
            $result[] = [
                'room_type' => $roomType,
                'total_rooms' => $total,
                'sold_rooms' => $sold,
                'available_rooms' => $available,
                'overbooked_rooms' => max($sold - $total, 0),
            ];
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $details
     * @param array<string,string> $fieldTrace
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function assess(
        array $summary,
        array $details,
        string $identityStatus,
        string $dateStatus,
        bool $dateMatchesToday,
        array $fieldTrace,
        array $warnings
    ): array {
        $gaps = [];
        if ($identityStatus !== 'matched') {
            $gaps[] = $this->gap(
                $identityStatus === 'identity_mismatch'
                    ? 'meituan_cloud_hotel_identity_mismatch'
                    : 'meituan_cloud_hotel_identity_unverified'
            );
        }
        if ($dateStatus !== 'matched' || !$dateMatchesToday) {
            $gaps[] = $this->gap('meituan_cloud_business_date_unverified');
        }
        foreach (self::REQUIRED_SUMMARY_FIELDS as $field) {
            if ($summary[$field] === null) {
                $gaps[] = $this->gap('meituan_cloud_' . $field . '_missing');
            }
            if (!isset($fieldTrace[$field]) || trim((string)$fieldTrace[$field]) === '') {
                $gaps[] = $this->gap('meituan_cloud_' . $field . '_source_trace_missing');
            }
        }
        if ($details === []) {
            $gaps[] = $this->gap('meituan_cloud_room_types_missing');
        }

        $detailTotal = array_sum(array_column($details, 'total_rooms'));
        $detailSold = array_sum(array_column($details, 'sold_rooms'));
        $detailAvailable = array_sum(array_column($details, 'available_rooms'));
        $availabilityDifference = $summary['available_rooms'] === null
            || $summary['room_type_available_rooms'] === null
            ? null
            : abs($summary['available_rooms'] - $summary['room_type_available_rooms']);
        $availabilityTolerance = $summary['total_rooms'] === null
            ? null
            : max(2, (int)ceil($summary['total_rooms'] * 0.05));

        if ($details !== [] && $summary['total_rooms'] !== null
            && $detailTotal !== $summary['total_rooms']
        ) {
            $gaps[] = $this->gap('meituan_cloud_total_rooms_reconciliation_mismatch');
        }
        if ($details !== [] && $summary['sold_room_nights'] !== null
            && $detailSold !== $summary['sold_room_nights']
        ) {
            $gaps[] = $this->gap('meituan_cloud_sold_room_nights_reconciliation_mismatch');
        }
        if ($details !== [] && $summary['room_type_available_rooms'] !== null
            && $detailAvailable !== $summary['room_type_available_rooms']
        ) {
            $gaps[] = $this->gap('meituan_cloud_room_type_availability_reconciliation_mismatch');
        }
        if ($availabilityDifference !== null && $availabilityTolerance !== null) {
            if ($availabilityDifference > $availabilityTolerance) {
                $gaps[] = $this->gap('meituan_cloud_availability_difference_exceeded');
            } elseif ($availabilityDifference > 0) {
                $warnings[] = sprintf(
                    '首页可售与房型可售相差%d间，未超过按房量计算的容差%d间；经营目标使用首页可售事实。',
                    $availabilityDifference,
                    $availabilityTolerance
                );
            }
        }

        $revenue = $summary['estimated_room_revenue'];
        $sold = $summary['sold_room_nights'];
        $total = $summary['total_rooms'];
        if ($revenue !== null && $sold !== null && $sold > 0 && $summary['adr'] !== null) {
            $adrTolerance = max(2.0, $sold * 0.02);
            if (abs($revenue - ($summary['adr'] * $sold)) > $adrTolerance) {
                $gaps[] = $this->gap('meituan_cloud_adr_reconciliation_mismatch');
            }
        }
        if ($revenue !== null && $total !== null && $total > 0 && $summary['revpar'] !== null) {
            $revparTolerance = max(2.0, $total * 0.02);
            if (abs($revenue - ($summary['revpar'] * $total)) > $revparTolerance) {
                $gaps[] = $this->gap('meituan_cloud_revpar_reconciliation_mismatch');
            }
        }
        if ($sold !== null && $total !== null && $total > 0
            && $summary['occupancy_rate_percent'] !== null
            && abs(($sold / $total * 100) - $summary['occupancy_rate_percent']) > 0.2
        ) {
            $gaps[] = $this->gap('meituan_cloud_occupancy_reconciliation_mismatch');
        }
        foreach ($details as $detail) {
            if ((int)$detail['overbooked_rooms'] > 0) {
                $warnings[] = sprintf(
                    '%s超售%d间；已售按 PMS 实际值保留，可售按首页汇总事实使用。',
                    (string)$detail['room_type'],
                    (int)$detail['overbooked_rooms']
                );
            }
        }

        $missing = array_filter(
            $gaps,
            static fn(array $gap): bool => str_contains((string)$gap['code'], '_missing')
        ) !== [];
        $reconciliationFailed = array_filter(
            $gaps,
            static fn(array $gap): bool => str_contains((string)$gap['code'], '_mismatch')
                || str_contains((string)$gap['code'], '_exceeded')
        ) !== [];
        if ($identityStatus === 'identity_mismatch') {
            $captureStatus = 'identity_mismatch';
            $qualityStatus = 'identity_mismatch';
        } elseif ($identityStatus !== 'matched') {
            $captureStatus = 'identity_unverified';
            $qualityStatus = 'unverified';
        } elseif ($dateStatus !== 'matched' || !$dateMatchesToday) {
            $captureStatus = 'date_unverified';
            $qualityStatus = 'unverified';
        } elseif ($missing) {
            $captureStatus = 'missing';
            $qualityStatus = 'missing';
        } elseif ($reconciliationFailed) {
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
                ? self::RENDER_SCOPE_NOTE . ' 工作台汇总与房型库存已完成差值和公式对账。'
                : $gaps[0]['message'],
            'gaps' => $this->uniqueGaps($gaps),
            'warnings' => array_values(array_unique($warnings)),
            'availability_difference' => $availabilityDifference,
            'availability_tolerance' => $availabilityTolerance,
            'reconciliation_status' => $gaps === [] ? 'matched' : (
                $reconciliationFailed ? 'mismatch' : 'unverified'
            ),
        ];
    }

    private function identityStatus(
        ?string $providerHotelName,
        string $expectedHotelName,
        string $evidenceType
    ): string {
        if (!in_array($evidenceType, self::AUTHORITATIVE_IDENTITY_EVIDENCE, true)
            || $providerHotelName === null
        ) {
            return 'unverified';
        }
        return $this->normalizeHotelName($providerHotelName) === $this->normalizeHotelName($expectedHotelName)
            ? 'matched'
            : 'identity_mismatch';
    }

    private function dateStatus(
        string $businessDate,
        string $capturedDate,
        string $evidenceType
    ): string {
        return in_array($evidenceType, self::AUTHORITATIVE_DATE_EVIDENCE, true)
            && $capturedDate !== ''
            && $businessDate === $capturedDate
            ? 'matched'
            : 'unverified';
    }

    /** @return array<string,string> */
    private function fieldTrace(array $trace): array
    {
        $result = [];
        foreach (self::REQUIRED_SUMMARY_FIELDS as $field) {
            $value = $this->textOrNull($trace[$field] ?? null, 255);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }
        $optional = $this->textOrNull($trace['sale_order_count'] ?? null, 255);
        if ($optional !== null) {
            $result['sale_order_count'] = $optional;
        }
        return $result;
    }

    /** @return list<string> */
    private function warnings(array $warnings): array
    {
        $result = [];
        foreach (array_slice($warnings, 0, 20) as $warning) {
            $value = $this->textOrNull($warning, 300);
            if ($value !== null) {
                $result[] = $value;
            }
        }
        return array_values(array_unique($result));
    }

    private function sourceUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'pms.meituan.com'
            || !in_array((string)($parts['path'] ?? '/'), ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || (
                isset($parts['fragment'])
                && !str_starts_with((string)$parts['fragment'], 'qk-workbench')
            )
        ) {
            throw new \InvalidArgumentException('meituan_cloud_capture_source_url_invalid');
        }
        return self::SOURCE_URL;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('meituan_cloud_capture_date_invalid');
        }
        return $value;
    }

    private function dateTime(string $value): string
    {
        $value = trim($value);
        $timestamp = strtotime($value);
        if ($value === '' || $timestamp === false) {
            throw new \InvalidArgumentException('meituan_cloud_capture_time_invalid');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('meituan_cloud_capture_number_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0) {
            throw new \InvalidArgumentException('meituan_cloud_capture_number_invalid');
        }
        return round($number, 2);
    }

    private function percentOrNull(mixed $value): ?float
    {
        $number = $this->decimalOrNull($value);
        if ($number !== null && $number > 200) {
            throw new \InvalidArgumentException('meituan_cloud_capture_percent_invalid');
        }
        return $number;
    }

    private function integerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException('meituan_cloud_capture_integer_invalid');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || floor($number) !== $number) {
            throw new \InvalidArgumentException('meituan_cloud_capture_integer_invalid');
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
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string)$value);
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
            $code === 'meituan_cloud_capture_missing' => '该酒店该日期尚未保存美团云 PMS 经营事实。',
            $code === 'meituan_cloud_capture_table_missing' => '美团云 PMS 事实存储表尚未安装。',
            $code === 'meituan_cloud_hotel_identity_mismatch' => '美团云 PMS 当前门店与宿析OS酒店绑定不一致。',
            $code === 'meituan_cloud_hotel_identity_unverified' => '尚未从受保护登录会话或已验证接口取得可信门店身份。',
            $code === 'meituan_cloud_business_date_unverified' => '美团云 PMS 经营日期未与当日实时工作台采集时间形成可信对应。',
            $code === 'meituan_cloud_trusted_collection_required' => '人工提交不能自证登录会话、门店和接口来源，已按未验证状态保存。',
            $code === 'meituan_cloud_room_types_missing' => '未取得房型库存明细，不能核对首页汇总。',
            $code === 'meituan_cloud_total_rooms_reconciliation_mismatch' => '房型总房量合计与首页总房量不一致。',
            $code === 'meituan_cloud_sold_room_nights_reconciliation_mismatch' => '房型已售合计与首页预计已售间夜不一致。',
            $code === 'meituan_cloud_room_type_availability_reconciliation_mismatch' => '房型可售合计与房型明细重算结果不一致。',
            $code === 'meituan_cloud_availability_difference_exceeded' => '首页可售与房型可售差值超过按总房量计算的容差。',
            $code === 'meituan_cloud_adr_reconciliation_mismatch' => '预计房费与 ADR × 已售间夜的差值超过舍入容差。',
            $code === 'meituan_cloud_revpar_reconciliation_mismatch' => '预计房费与 RevPAR × 总房量的差值超过舍入容差。',
            $code === 'meituan_cloud_occupancy_reconciliation_mismatch' => '入住率与已售间夜 ÷ 总房量的重算结果不一致。',
            str_ends_with($code, '_source_trace_missing') => '美团云 PMS 指标缺少接口字段或计算来源路径。',
            str_ends_with($code, '_missing') => '美团云 PMS 当日经营指标存在缺失字段。',
            default => '美团云 PMS 经营事实未通过真实性门禁。',
        };
    }

    /** @param list<array{code:string,message:string}> $gaps */
    private function uniqueGaps(array $gaps): array
    {
        $unique = [];
        foreach ($gaps as $gap) {
            $unique[$gap['code']] = $gap;
        }
        return array_values($unique);
    }

    /** @return array<string,mixed> */
    private function missing(int $hotelId, string $businessDate, string $code): array
    {
        return [
            'status' => 'missing',
            'hotel_id' => $hotelId,
            'provider' => self::PROVIDER,
            'provider_label' => '美团云 PMS',
            'business_date' => $businessDate,
            'source_url' => self::SOURCE_URL,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_status' => 'missing',
            'quality_status' => 'missing',
            'readback_status' => 'missing',
            'identity_status' => 'unverified',
            'date_status' => 'unverified',
            'reconciliation_status' => 'unverified',
            'summary' => array_fill_keys(
                [...self::REQUIRED_SUMMARY_FIELDS, 'sale_order_count'],
                null
            ),
            'room_type_count' => 0,
            'room_types' => [],
            'validation_warnings' => [],
            'gaps' => [$this->gap($code)],
            'scope_note' => self::RENDER_SCOPE_NOTE,
        ];
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
            | JSON_THROW_ON_ERROR
        );
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
