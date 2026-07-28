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
    private const ROW_KINDS = ['room', 'unassigned', 'room_type_total', 'grand_total'];

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
                || ($expectedProviderHotelId !== null
                    && !hash_equals($expectedProviderHotelId, $providerHotelId))
                || $capturedTimestamp === false
                || $captureAgeSeconds < -300
                || $captureAgeSeconds > 1800
                || date('Y-m-d', $capturedTimestamp) !== $businessDate
            ) {
                throw new \InvalidArgumentException('dingdandao_capture_not_verified');
            }
        }
        $snapshot = [
            'contract_version' => 'dingdandao_operating_target_capture.v1',
            'provider' => self::PROVIDER,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_url' => $sourceUrl,
            'source_api_path' => $sourceApiPath,
            'source_scope' => self::SOURCE_SCOPE,
            'capture_method' => $captureMethod,
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => $providerHotelName,
            'expected_hotel_name' => $expectedHotelName,
            'identity_evidence_type' => $identityEvidenceType,
            'identity_status' => $identityStatus,
            'summary' => $summary,
            'detail_row_count' => count($details),
            'detail_room_fee_total' => $assessment['detail_room_fee_total'],
            'reconciliation_status' => $assessment['reconciliation_status'],
            'trend' => $trend,
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
            $storedCapture = Db::name('dingdandao_operating_target_captures')
                ->where('id', $captureId)
                ->find();
            $readbackVerified = is_array($storedCapture)
                && $storedCount === count($details)
                && abs($storedRoomTotal - (float)$assessment['detail_room_fee_total']) <= 0.01;
            if ($readbackVerified) {
                $readbackVerified = $this->mainReadbackMatches(
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
        foreach (self::SUMMARY_FIELDS as $field) {
            $expected = $summary[$field] ?? null;
            $stored = $row[$field] ?? null;
            if ($expected === null ? $stored !== null : abs((float)$stored - (float)$expected) > 0.01) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function latest(int $tenantId, int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate);
        if (!$this->tableExists('dingdandao_operating_target_captures')) {
            return $this->missing($hotelId, $businessDate, 'dingdandao_capture_table_missing');
        }
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('business_date', $businessDate)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return $this->missing($hotelId, $businessDate, 'dingdandao_capture_missing');
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

        return [
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
            'field_trace' => $this->decodeJson($row['field_trace_json'] ?? null),
            'source_fingerprint' => (string)$row['source_fingerprint'],
            'captured_at' => (string)$row['captured_at'],
            'readback_status' => (string)$row['readback_status'],
            'readback_verified_at' => $row['readback_verified_at'] ?? null,
            'created_at' => $row['create_time'] ?? null,
        ];
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
        $soldRoomRows = array_values(array_filter(
            $details,
            static fn(array $row): bool => $row['row_kind'] === 'room'
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

        if ($summary['sold_room_nights'] !== null
            && count($soldRoomRows) !== $summary['sold_room_nights']
        ) {
            $gaps[] = $this->gap('dingdandao_sold_room_nights_detail_count_mismatch');
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
        foreach (self::SUMMARY_FIELDS as $field) {
            $value = $this->textOrNull($trace[$field] ?? null, 255);
            if ($value !== null) {
                $result[$field] = $value;
            }
        }
        return $result;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function trend(array $trend, string $businessDate): array
    {
        $allowed = ['total_room_fee', 'adr', 'occupancy_rate_percent', 'revpar', 'sold_room_nights'];
        $result = [];
        foreach ($allowed as $key) {
            $points = is_array($trend[$key] ?? null) ? $trend[$key] : [];
            $normalized = [];
            foreach (array_slice($points, 0, 31) as $point) {
                if (!is_array($point) || (string)($point['date'] ?? '') !== $businessDate) {
                    continue;
                }
                $value = $this->decimalOrNull($point['value'] ?? null);
                if ($value !== null) {
                    $normalized[] = ['date' => $businessDate, 'value' => $value];
                }
            }
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
            $code === 'dingdandao_sold_room_nights_detail_count_mismatch' => '房间明细数量与累计售出间夜不一致。',
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

    private function missing(int $hotelId, string $businessDate, string $code): array
    {
        return [
            'status' => 'missing',
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
            'gaps' => [$this->gap($code)],
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
