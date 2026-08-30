<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Builds one safe field-by-field Ctrip + Meituan closure contract from formal
 * online_daily_data rows. Readback truth, metric semantics, historical
 * finality, and revenue-consumption eligibility remain separate states.
 */
final class DualOtaFieldClosureService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const BLOCKING_VALIDATION_STATUSES = [
        'blocked', 'failed', 'invalid', 'mismatch', 'quarantined',
        'rejected', 'rolled_back', 'unverified',
    ];
    private const STATUS_LABELS = [
        'strict_readback' => '已严格回读',
        'verified_calculation' => '已验证计算',
        'source_missing' => '来源缺失',
        'field_unavailable' => '字段未取得',
        'readback_failed' => '回读未闭合',
        'collection_failed' => '采集失败',
        'login_expired' => '登录失效',
        'date_mismatch' => '日期不符',
        'caliber_uncertain' => '口径不确定',
        // Kept as input aliases for old cached payloads. New evaluations only
        // emit the four explicit unavailable states above.
        'missing' => '缺失',
        'platform_not_provided' => '平台未提供',
    ];
    private const CONSUMER_METRIC_KEYS = [
        'revenue' => ['revenue'],
        'order_count' => ['orders'],
        'room_nights' => ['room_nights'],
        'adr' => ['adr'],
        'exposure' => ['list_exposure'],
        'visits' => ['detail_exposure'],
        'conversion' => ['flow_rate_percent'],
        'cancellation' => ['cancellation_rate_percent'],
        'sellable' => ['sellable_room_nights'],
        'bookable' => ['bookable_room_nights'],
    ];
    private const FIELD_FACT_METRIC_KEYS = [
        'revenue' => ['order_amount', 'sales_amount'],
        'order_count' => ['order_count', 'paid_order_count'],
        'room_nights' => ['room_nights', 'sales_room_nights'],
        'adr' => ['average_price', 'sales_avg_price', 'order_amount', 'room_nights'],
        'exposure' => ['list_exposure', 'exposure_users', 'total_exposure'],
        'visits' => ['detail_exposure', 'detail_visitors'],
        'conversion' => ['flow_rate', 'exposure_to_browse_rate', 'list_exposure', 'detail_exposure'],
        'cancellation' => ['cancellation_rate'],
        'sellable' => ['sellable_room_nights'],
        'bookable' => ['bookable_room_nights'],
    ];
    private const FIELD_DEFINITIONS = [
        'revenue' => ['label' => '收入', 'unit' => 'CNY'],
        'order_count' => ['label' => '订单量', 'unit' => 'orders'],
        'room_nights' => ['label' => '间夜量', 'unit' => 'room_nights'],
        'adr' => ['label' => 'ADR', 'unit' => 'CNY'],
        'exposure' => ['label' => '曝光', 'unit' => 'users'],
        'visits' => ['label' => '访问', 'unit' => 'users'],
        'conversion' => ['label' => '曝光→访问转化', 'unit' => 'percent'],
        'cancellation' => ['label' => '取消', 'unit' => 'percent'],
        'sellable' => ['label' => '在售', 'unit' => 'rooms'],
        'bookable' => ['label' => '可订', 'unit' => 'rooms'],
        'data_date' => ['label' => '数据日期', 'unit' => 'date'],
        'collected_at' => ['label' => '采集时间', 'unit' => 'datetime'],
        'source_record_id' => ['label' => '来源记录 ID', 'unit' => 'records'],
    ];

    /** @var array<string,array<string,bool>> */
    private array $columns = [];

    /** @return array<string,mixed> */
    public function build(int $hotelId, string $businessDate): array
    {
        self::assertDate($businessDate);
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('dual_ota_field_closure_hotel_invalid');
        }

        $hotelColumns = $this->tableColumns('hotels');
        $hotelFields = array_values(array_intersect(
            ['id', 'tenant_id', 'name', 'status'],
            array_keys($hotelColumns)
        ));
        $hotel = $hotelFields === [] ? null : Db::name('hotels')
            ->where('id', $hotelId)
            ->field(implode(',', $hotelFields))
            ->find();
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('dual_ota_field_closure_hotel_scope_missing', 422);
        }

        $rows = $this->loadRows(
            (int)$hotel['tenant_id'],
            $hotelId,
            $businessDate
        );
        $trust = (new DualOtaContinuousTrustService())->inspectHotel(
            $hotelId,
            $businessDate,
            $businessDate
        );

        return self::evaluate($hotel, $businessDate, $rows, $trust);
    }

    /**
     * Pure evaluator used by focused tests and the live database adapter.
     *
     * @param array<string,mixed> $hotel
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $trust
     * @return array<string,mixed>
     */
    public static function evaluate(
        array $hotel,
        string $businessDate,
        array $rows,
        array $trust = []
    ): array {
        self::assertDate($businessDate);
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0) {
            throw new InvalidArgumentException('dual_ota_field_closure_scope_invalid');
        }

        $scopedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowHotelId = (int)($row['system_hotel_id'] ?? 0);
            $rowTenantId = (int)($row['tenant_id'] ?? 0);
            $rowDate = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            $platform = self::rowPlatform($row);
            if ($rowHotelId !== $hotelId
                || $rowTenantId !== $tenantId
                || $rowDate !== $businessDate
                || !in_array($platform, self::PLATFORMS, true)
            ) {
                continue;
            }
            $row['_closure_platform'] = $platform;
            $scopedRows[] = $row;
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $platformRows = array_values(array_filter(
                $scopedRows,
                static fn(array $row): bool => ($row['_closure_platform'] ?? '') === $platform
            ));
            $platforms[$platform] = self::buildPlatform(
                $platform,
                $tenantId,
                $hotelId,
                $businessDate,
                $platformRows,
                self::trustPlatformDay($trust, $platform, $businessDate)
            );
        }

        $statusCounts = [];
        $analysisConsumableFields = 0;
        foreach ($platforms as $platform) {
            foreach ($platform['fields'] as $field) {
                $status = (string)($field['status'] ?? 'source_missing');
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                if (($field['revenue_analysis_consumable'] ?? false) === true) {
                    $analysisConsumableFields++;
                }
            }
        }
        ksort($statusCounts, SORT_STRING);

        $stable = [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platforms' => $platforms,
        ];
        $digest = hash('sha256', self::encodeStable($stable));
        $allReady = $analysisConsumableFields > 0
            && array_reduce(
                $platforms,
                static fn(bool $ready, array $platform): bool => $ready
                    && ($platform['status'] ?? '') === 'ready',
                true
            );

        return $stable + [
            'status' => $allReady ? 'ready' : 'partial',
            'metric_scope' => 'ota_channel_only',
            'status_labels' => self::STATUS_LABELS,
            'status_counts' => $statusCounts,
            'revenue_analysis_consumable_field_count' => $analysisConsumableFields,
            'closure_digest' => $digest,
            'page_identity' => 'dual_ota_field_closure#' . substr($digest, 0, 16),
            'same_payload_required_on' => [
                'data_health',
                'revenue_cockpit',
                'operating_broadcast',
                'operating_query',
                'operation_gate',
            ],
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
                'closure_identity' => 'dual_ota_field_closure#' . substr($digest, 0, 16),
                'field_source_path' => 'platforms.{platform}.fields',
                'platform_order' => self::PLATFORMS,
                'field_order' => array_keys(self::FIELD_DEFINITIONS),
                'metric_values_duplicated' => false,
                'missing_value_policy' => 'null_with_explicit_status',
                'allowed_fact_statuses' => ['strict_readback', 'verified_calculation'],
                'required_consumers' => [
                    'data_health',
                    'revenue_cockpit',
                    'operating_broadcast',
                    'operating_query',
                    'operation_gate',
                ],
            ],
            'boundary' => '正式数据库回读、字段口径可信、历史数据最终态和收益分析可消费是四个独立门槛；缺失、失败、日期不符或口径不确定的字段不会被改写成 0。',
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $trustDay
     * @return array<string,mixed>
     */
    private static function buildPlatform(
        string $platform,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $rows,
        array $trustDay
    ): array {
        $collection = self::projectCollectionState($trustDay);
        $currentReceiptEvidenceRows = [];
        $receiptBoundRows = [];
        foreach ($rows as $row) {
            if ((int)($row['readback_verified'] ?? 0) !== 1
                || !self::rowBelongsToCurrentReceipt($row, $collection)
            ) {
                continue;
            }
            $row['_closure_current_receipt_bound'] = true;
            $row['_closure_receipt_scope_status'] = (string)($collection['exact_run_readback_status'] ?? 'unverified');
            $currentReceiptEvidenceRows[] = $row;
            if (!self::rowFieldEligible($row)) {
                continue;
            }
            $receiptBoundRows[] = $row;
        }
        $acceptedRecordIds = self::positiveIds($collection['accepted_record_ids'] ?? []);
        $observedReceiptIds = self::positiveIds(array_column($currentReceiptEvidenceRows, 'id'));
        if ($acceptedRecordIds !== [] && $observedReceiptIds !== $acceptedRecordIds) {
            $collection['exact_run_readback_status'] = 'readback_failed';
            $collection['reason_codes'] = self::sortedStrings(array_merge(
                (array)($collection['reason_codes'] ?? []),
                ['accepted_receipt_row_readback_mismatch']
            ));
            foreach ($currentReceiptEvidenceRows as &$row) {
                $row['_closure_receipt_scope_status'] = 'readback_failed';
            }
            unset($row);
            foreach ($receiptBoundRows as &$row) {
                $row['_closure_receipt_scope_status'] = 'readback_failed';
            }
            unset($row);
        }
        $currentBlockerStatus = self::collectionBlockerStatus($collection);
        $eligibleRows = $currentBlockerStatus === null ? $receiptBoundRows : [];
        $currentReceiptDiagnosticRows = $currentBlockerStatus === null
            ? $currentReceiptEvidenceRows
            : [];
        $semanticVetoRows = $currentBlockerStatus === null && $eligibleRows !== []
            ? array_values(array_filter(
                $rows,
                static fn(array $row): bool => self::rowFieldEligible($row)
                    && (int)($row['data_source_id'] ?? 0) > 0
                    && (int)($row['data_source_id'] ?? 0) === (int)($collection['data_source_id'] ?? 0)
            ))
            : [];
        $fields = $platform === 'ctrip'
            ? self::buildCtripFields($businessDate, $eligibleRows, $collection)
            : self::buildMeituanFields(
                $businessDate,
                $eligibleRows,
                $collection,
                $semanticVetoRows,
                $currentReceiptDiagnosticRows
            );

        $usedRows = [];
        foreach ($fields as $field) {
            foreach ((array)($field['_rows'] ?? []) as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $usedRows[$id] = $row;
                }
            }
        }
        if ($usedRows === []) {
            foreach ($eligibleRows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0) {
                    $usedRows[$id] = $row;
                }
            }
        }
        ksort($usedRows, SORT_NUMERIC);

        $currentReceiptRows = [];
        foreach ($eligibleRows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $currentReceiptRows[$id] = $row;
            }
        }
        ksort($currentReceiptRows, SORT_NUMERIC);
        $currentReceiptAllRows = [];
        foreach ($currentReceiptEvidenceRows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $currentReceiptAllRows[$id] = $row;
            }
        }
        ksort($currentReceiptAllRows, SORT_NUMERIC);
        $metadataRows = array_values($currentReceiptAllRows);
        $fields[] = self::field(
            'data_date',
            $metadataRows === [] ? self::missingStatus($collection, 'missing') : 'strict_readback',
            $metadataRows === [] ? null : $businessDate,
            $metadataRows,
            'online_daily_data.data_date',
            '列出当前采集回执中严格限定为当前租户、酒店、平台和营业日且已精确回读的全部正式记录；其中可能包含被校验隔离、不可作为字段事实消费的记录。'
        );
        $collectedAt = self::latestCollectionTime($metadataRows);
        $fields[] = self::field(
            'collected_at',
            $collectedAt === null ? self::missingStatus($collection, 'missing') : 'strict_readback',
            $collectedAt,
            $metadataRows,
            'online_daily_data.snapshot_time',
            $collectedAt === null
                ? '未保存可信采集时间；不会用数据库更新时间替代。'
                : '采集/快照时间与数据库更新时间保持分离。'
        );
        $recordRefs = array_map(
            static fn(array $row): string => 'online_daily_data#' . (int)$row['id'],
            $metadataRows
        );
        $fields[] = self::field(
            'source_record_id',
            $recordRefs === [] ? self::missingStatus($collection, 'missing') : 'strict_readback',
            $recordRefs === [] ? null : $recordRefs,
            $metadataRows,
            'online_daily_data.id',
            '这些是当前采集回执精确回读到的全部标准化正式记录 ID，不是 OTA 账号或门店标识；字段可用记录另行列示。'
        );

        $identitySteps = is_array($trustDay['steps'] ?? null) ? $trustDay['steps'] : [];
        $identityReady = $identitySteps !== []
            && ($identitySteps['source'] ?? false) === true
            && ($identitySteps['account_profile_binding'] ?? false) === true
            && ($collection['platform_hotel_status'] ?? '') === 'verified'
            && ($collection['target_date_status'] ?? '') === 'matched'
            && $eligibleRows !== []
            && (int)($collection['data_source_id'] ?? 0) > 0
            && (int)($collection['sync_task_id'] ?? 0) > 0
            && (array)($collection['accepted_record_ids'] ?? []) !== [];

        $fieldStatusCounts = [];
        $analysisFields = [];
        $cleanFields = [];
        $formalRecordIds = [];
        foreach ($fields as $field) {
            $status = (string)($field['status'] ?? 'source_missing');
            $fieldStatusCounts[$status] = ($fieldStatusCounts[$status] ?? 0) + 1;
            $field['identity_binding_verified'] = $identityReady;
            $revenueBlockers = [];
            if (!$identityReady) {
                $revenueBlockers[] = 'identity_binding_not_verified';
            }
            if (($field['strict_final_gate'] ?? false) !== true) {
                $revenueBlockers[] = 'strict_final_gate_not_verified';
            }
            if (!in_array($status, ['strict_readback', 'verified_calculation'], true)) {
                $revenueBlockers[] = 'field_status_not_consumable';
            }
            $field['revenue_analysis_blockers'] = $revenueBlockers;
            $field['revenue_analysis_consumable'] =
                ($field['revenue_analysis_consumable'] ?? false) === true
                && $identityReady;
            $field = self::withFieldIdentity(
                $field,
                $tenantId,
                $hotelId,
                $platform,
                $businessDate,
                $collection,
                $identityReady
            );
            if (($field['revenue_analysis_consumable'] ?? false) === true) {
                $analysisFields[] = (string)$field['key'];
            }
            $formalRecordIds = array_merge(
                $formalRecordIds,
                array_map('intval', (array)($field['source_record_ids'] ?? []))
            );
            unset($field['_rows']);
            $cleanFields[] = $field;
        }
        $formalRecordIds = array_values(array_unique(array_filter(
            $formalRecordIds,
            static fn(int $id): bool => $id > 0
        )));
        sort($formalRecordIds, SORT_NUMERIC);
        $currentReceiptRecordIds = array_keys($currentReceiptRows);
        $currentReceiptAllRecordIds = array_keys($currentReceiptAllRows);
        $currentReceiptNonEligibleRecordIds = array_values(array_diff(
            $currentReceiptAllRecordIds,
            $currentReceiptRecordIds
        ));
        sort($currentReceiptNonEligibleRecordIds, SORT_NUMERIC);
        $semanticVetoRecordIds = array_values(array_diff($formalRecordIds, $currentReceiptAllRecordIds));
        sort($semanticVetoRecordIds, SORT_NUMERIC);
        ksort($fieldStatusCounts, SORT_STRING);

        $requiredKeys = ['revenue', 'order_count', 'room_nights', 'adr', 'exposure', 'visits', 'conversion'];
        $requiredReady = true;
        foreach ($cleanFields as $field) {
            if (in_array((string)$field['key'], $requiredKeys, true)
                && !in_array((string)$field['status'], ['strict_readback', 'verified_calculation'], true)
            ) {
                $requiredReady = false;
            }
        }
        $analysisReady = $identityReady
            && $requiredReady
            && count(array_intersect($requiredKeys, $analysisFields)) === count($requiredKeys);

        $excludedPriorIds = [];
        if ($currentBlockerStatus !== null) {
            foreach ($rows as $row) {
                $id = (int)($row['id'] ?? 0);
                if ($id > 0 && self::rowFieldEligible($row)) {
                    $excludedPriorIds[$id] = true;
                }
            }
        }
        $excludedPriorIds = array_keys($excludedPriorIds);
        sort($excludedPriorIds, SORT_NUMERIC);

        return [
            'platform' => $platform,
            'platform_label' => $platform === 'ctrip' ? '携程' : '美团',
            'status' => $analysisReady ? 'ready' : 'partial',
            'identity_status' => $identityReady ? 'verified' : 'partial',
            'business_date' => $businessDate,
            'latest_collection' => $collection,
            'current_collection_blocker_status' => $currentBlockerStatus,
            'excluded_prior_formal_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $excludedPriorIds
            ),
            'formal_record_ids' => $formalRecordIds,
            'formal_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $formalRecordIds
            ),
            'current_receipt_record_ids' => $currentReceiptRecordIds,
            'current_receipt_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $currentReceiptRecordIds
            ),
            'current_receipt_all_record_ids' => $currentReceiptAllRecordIds,
            'current_receipt_all_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $currentReceiptAllRecordIds
            ),
            'current_receipt_non_eligible_record_ids' => $currentReceiptNonEligibleRecordIds,
            'current_receipt_non_eligible_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $currentReceiptNonEligibleRecordIds
            ),
            'semantic_veto_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $semanticVetoRecordIds
            ),
            'field_status_counts' => $fieldStatusCounts,
            'revenue_analysis' => [
                'status' => $analysisReady ? 'ready' : 'blocked',
                'consumable_fields' => $analysisFields,
                'blocked_reason' => $analysisReady
                    ? null
                    : (!$identityReady
                        ? 'identity_binding_not_verified'
                        : 'current_receipt_binding_or_strict_history_validation_gate_not_met_or_required_field_incomplete'),
                'strict_gate' => 'verified account/Profile/store binding + current accepted receipt row + exact run scope verified + history_status=success + validation_status=verified + readback_verified=1',
            ],
            'fields' => $cleanFields,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $collection @return array<int,array<string,mixed>> */
    private static function buildCtripFields(string $date, array $rows, array $collection): array
    {
        unset($date);
        $market = self::latestRow($rows, static function (array $row): bool {
            $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
            return (string)($row['data_type'] ?? '') === 'business'
                && str_contains($dimension, 'business_market_overview:order_amount')
                && self::numeric($row['amount'] ?? null) !== null
                && self::numeric($row['quantity'] ?? null) !== null;
        });
        $orders = self::latestRow($rows, static function (array $row): bool {
            return strtolower(trim((string)($row['dimension'] ?? '')))
                === 'semantic:ctrip_business_market_overview:booking_order_count'
                && self::numeric($row['book_order_num'] ?? null) !== null;
        });
        $visits = self::latestRow($rows, static function (array $row): bool {
            return str_contains(
                strtolower(trim((string)($row['dimension'] ?? ''))),
                'business_visitor_title:visitor_count'
            ) && self::numeric($row['detail_exposure'] ?? null) !== null;
        });
        $traffic = self::latestRow($rows, static function (array $row): bool {
            return (string)($row['data_type'] ?? '') === 'traffic'
                && self::rowStrictFinalEligible($row)
                && self::numeric($row['list_exposure'] ?? null) !== null
                && self::numeric($row['detail_exposure'] ?? null) !== null;
        });

        $revenue = self::numeric($market['amount'] ?? null);
        $roomNights = self::numeric($market['quantity'] ?? null);
        $orderCount = self::numeric($orders['book_order_num'] ?? null);
        $adr = $revenue !== null && $roomNights !== null && $roomNights > 0
            ? round($revenue / $roomNights, 2)
            : null;
        $zeroOrderConflict = $orderCount === 0.0
            && (($revenue !== null && $revenue > 0) || ($roomNights !== null && $roomNights > 0));
        $orderField = $zeroOrderConflict
            ? self::field(
                'order_count',
                'caliber_uncertain',
                null,
                array_values(array_filter([$orders, $market], 'is_array')),
                'ctrip_booking_order_count_conflicted_with_same_run_business_summary',
                '当前回执明确返回预订订单量 0，但同次经营概览存在非零收入或间夜；保留 0 候选及两条正式记录用于追溯，不把该 0 当作已确认订单量。',
                self::observedValues([[
                    $orderCount,
                    'booking_order_count_conflicted_with_nonzero_business_summary',
                    $orders,
                ]]),
                ['same_run_zero_order_count_conflicts_with_nonzero_revenue_or_room_nights']
            )
            : self::fieldOrMissing('order_count', $orderCount, $orders, $collection, 'online_daily_data.book_order_num', '携程预订订单量语义投影。');
        $revenueField = self::fieldOrMissing(
            'revenue',
            $revenue,
            $market,
            $collection,
            'online_daily_data.amount',
            '携程经营概览中的订单金额。'
        );
        $roomNightsField = self::fieldOrMissing(
            'room_nights',
            $roomNights,
            $market,
            $collection,
            'online_daily_data.quantity',
            '携程经营概览中的间夜量。'
        );
        $adrRows = [];
        foreach (array_merge(
            (array)($revenueField['_rows'] ?? []),
            (array)($roomNightsField['_rows'] ?? [])
        ) as $adrRow) {
            if (is_array($adrRow) && (int)($adrRow['id'] ?? 0) > 0) {
                $adrRows[(int)$adrRow['id']] = $adrRow;
            }
        }
        ksort($adrRows, SORT_NUMERIC);
        $adrInputsStrict = (string)($revenueField['status'] ?? '') === 'strict_readback'
            && (string)($roomNightsField['status'] ?? '') === 'strict_readback';
        $listExposure = self::numeric($traffic['list_exposure'] ?? null);
        $trafficVisits = self::numeric($traffic['detail_exposure'] ?? null);
        $visitRow = is_array($traffic) ? $traffic : $visits;
        $visitValue = $trafficVisits ?? self::numeric($visits['detail_exposure'] ?? null);
        $conversion = $listExposure !== null
            && $listExposure > 0
            && $trafficVisits !== null
                ? round($trafficVisits / $listExposure * 100, 2)
                : null;
        $conversionStatus = $conversion !== null
            ? 'verified_calculation'
            : self::missingStatus($collection, 'field_unavailable');
        $conversionFlags = [];
        $conversionObservedValues = [];
        $conversionNote = '使用同一条携程 P0 canonical 流量记录，按访问量 / 曝光量计算。';
        $storedFlowRate = self::numeric($traffic['flow_rate'] ?? null);
        if ($conversion !== null
            && $storedFlowRate !== null
            && abs($conversion - $storedFlowRate) > 0.05
        ) {
            $conversionObservedValues = self::observedValues([
                [$conversion, 'detail_exposure / list_exposure', $traffic],
                [$storedFlowRate, 'online_daily_data.flow_rate', $traffic],
            ]);
            $conversion = null;
            $conversionStatus = 'caliber_uncertain';
            $conversionFlags[] = 'ctrip_stored_flow_rate_mismatch';
            $conversionNote = '携程 canonical 曝光、访问与保存的流量转化率不一致，保留候选并停止主值。';
        }

        return [
            $revenueField,
            $orderField,
            $roomNightsField,
            $adr !== null && $adrInputsStrict
                ? self::field('adr', 'verified_calculation', $adr, array_values($adrRows), 'revenue / room_nights', '仅使用同一批严格经营概览记录中的收入与间夜计算，不回退到页面展示 ADR。')
                : ($adr !== null && is_array($market)
                    ? self::field(
                        'adr',
                        in_array('readback_failed', [
                            (string)($revenueField['status'] ?? ''),
                            (string)($roomNightsField['status'] ?? ''),
                        ], true) ? 'readback_failed' : 'caliber_uncertain',
                        null,
                        array_values($adrRows),
                        'revenue / room_nights',
                        '收入与间夜候选来自同一记录，但其历史最终态或字段校验未达到 verified，不生成 ADR 主值。下一步：先验证金额与间夜口径，再重新计算。',
                        self::observedValues([[$adr, 'revenue / room_nights (candidate)', $market]]),
                        ['adr_inputs_not_strict_final']
                    )
                    : self::field(
                        'adr',
                        self::missingStatus($collection, 'field_unavailable'),
                        null,
                        [],
                        'revenue / room_nights',
                        '收入或间夜缺失，无法计算 ADR。下一步：补齐同一快照的金额与间夜字段。'
                    )),
            self::fieldOrMissing(
                'exposure',
                $listExposure,
                $traffic,
                $collection,
                'online_daily_data.list_exposure',
                '携程 P0 canonical 目标日流量曝光。'
            ),
            self::fieldOrMissing(
                'visits',
                $visitValue,
                $visitRow,
                $collection,
                'online_daily_data.detail_exposure',
                is_array($traffic)
                    ? '携程 P0 canonical 目标日流量访问量。'
                    : '携程经营概览中的目标日访问量。'
            ),
            self::field(
                'conversion',
                $conversionStatus,
                $conversion,
                is_array($traffic) ? [$traffic] : [],
                'detail_exposure / list_exposure',
                $conversionNote,
                $conversionObservedValues,
                $conversionFlags
            ),
            self::field('cancellation', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '携程目标日采集', '本次采集区段未取得取消数据。下一步：补采同一营业日的取消/订单状态字段。'),
            self::field('sellable', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '携程目标日采集', '本次采集区段未取得在售库存。下一步：补采同一营业日的房态库存端点。'),
            self::field('bookable', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '携程目标日采集', '本次采集区段未取得可订库存。下一步：补采同一营业日的游客侧可订性证据。'),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $collection @return array<int,array<string,mixed>> */
    private static function buildMeituanFields(
        string $date,
        array $rows,
        array $collection,
        array $semanticVetoRows = [],
        array $currentReceiptDiagnosticRows = []
    ): array
    {
        unset($date);
        $business = self::latestRow($rows, static fn(array $row): bool =>
            self::captureSource($row) === 'xhr:traffic:business_data'
            && self::numeric($row['amount'] ?? null) !== null
        );
        $orders = self::latestRow($rows, static fn(array $row): bool =>
            (string)($row['data_type'] ?? '') === 'order'
            && self::numeric($row['amount'] ?? null) !== null
        );
        $traffic = self::latestRow($rows, static fn(array $row): bool =>
            (string)($row['data_type'] ?? '') === 'traffic'
            && self::captureSource($row) === 'xhr:traffic:traffic'
            && self::numeric($row['list_exposure'] ?? null) !== null
            && self::numeric($row['detail_exposure'] ?? null) !== null
        );
        $trafficConflict = is_array($traffic) ? null : self::latestRow(
            $currentReceiptDiagnosticRows,
            static fn(array $row): bool => self::isSameRunZeroTrafficConflict($row)
        );

        // A prior same-day formal row may veto promotion when it proves that
        // the platform exposed two different amount semantics. It can never
        // supply a missing current-receipt value or pass the consumption gate.
        $vetoBusiness = is_array($business) ? null : self::latestRow(
            $semanticVetoRows,
            static fn(array $row): bool => self::captureSource($row) === 'xhr:traffic:business_data'
                && self::numeric($row['amount'] ?? null) !== null
        );
        $vetoOrders = is_array($orders) ? null : self::latestRow(
            $semanticVetoRows,
            static fn(array $row): bool => (string)($row['data_type'] ?? '') === 'order'
                && self::numeric($row['amount'] ?? null) !== null
        );
        $businessForConflict = is_array($business) ? $business : (is_array($orders) ? $vetoBusiness : null);
        $ordersForConflict = is_array($orders) ? $orders : (is_array($business) ? $vetoOrders : null);

        $businessRevenue = self::numeric($businessForConflict['amount'] ?? null);
        $orderRevenue = self::numeric($ordersForConflict['amount'] ?? null);
        $revenueRows = array_values(array_filter([$businessForConflict, $ordersForConflict], 'is_array'));
        $revenueConflict = $businessRevenue !== null
            && $orderRevenue !== null
            && abs($businessRevenue - $orderRevenue) > 0.01;
        if ($revenueConflict) {
            $revenueField = self::field(
                'revenue',
                'caliber_uncertain',
                null,
                $revenueRows,
                'distinct_meituan_business_and_order_amount_semantics',
                '当前回执金额与同营业日另一正式金额语义不同；旧记录只用于阻断错误提升，不会替代当前回执值。下一步：确认业务卡片金额与订单汇总金额各自定义后再提升收入事实。',
                self::observedValues([
                    [$businessRevenue, 'business_card_amount', $businessForConflict],
                    [$orderRevenue, 'order_summary_amount', $ordersForConflict],
                ])
            );
        } else {
            $value = self::numeric($orders['amount'] ?? null) ?? self::numeric($business['amount'] ?? null);
            $row = is_array($orders) ? $orders : $business;
            $revenueField = self::fieldOrMissing(
                'revenue',
                $value,
                $row,
                $collection,
                'online_daily_data.amount',
                '美团金额不存在相互冲突的正式表示。'
            );
        }

        $orderCount = self::numeric($orders['book_order_num'] ?? null);
        $roomNights = self::numeric($orders['quantity'] ?? null);
        $currentBusinessRevenue = self::numeric($business['amount'] ?? null);
        $currentBusinessNights = self::numeric($business['quantity'] ?? null);
        $currentBusinessAdr = $currentBusinessRevenue !== null
            && $currentBusinessNights !== null
            && $currentBusinessNights > 0
                ? round($currentBusinessRevenue / $currentBusinessNights, 2)
                : null;
        $currentOrderRevenue = self::numeric($orders['amount'] ?? null);
        $currentOrderAdr = $currentOrderRevenue !== null
            && $roomNights !== null
            && $roomNights > 0
                ? round($currentOrderRevenue / $roomNights, 2)
                : null;
        $businessAdr = $businessRevenue !== null
            && ($businessNights = self::numeric($businessForConflict['quantity'] ?? null)) !== null
            && $businessNights > 0
                ? round($businessRevenue / $businessNights, 2)
                : null;
        $orderConflictNights = self::numeric($ordersForConflict['quantity'] ?? null);
        $orderAdr = $orderRevenue !== null && $orderConflictNights !== null && $orderConflictNights > 0
            ? round($orderRevenue / $orderConflictNights, 2)
            : null;
        $adrConflict = $businessAdr !== null
            && $orderAdr !== null
            && abs($businessAdr - $orderAdr) > 0.01;
        $adrField = $adrConflict
            ? self::field(
                'adr',
                'caliber_uncertain',
                null,
                $revenueRows,
                'distinct_meituan_amount_semantics / room_nights',
                '两个金额口径不同，导致 ADR 候选值不同。下一步：先确认收入金额口径，再按同一口径金额与间夜重算 ADR。',
                self::observedValues([
                    [$businessAdr, 'business_card_amount / room_nights', $businessForConflict],
                    [$orderAdr, 'order_summary_amount / room_nights', $ordersForConflict],
                ])
            )
            : ((string)($revenueField['status'] ?? '') !== 'strict_readback'
                && ($currentOrderAdr ?? $currentBusinessAdr) !== null
                ? self::field(
                    'adr',
                    in_array((string)($revenueField['status'] ?? ''), ['readback_failed', 'source_missing'], true)
                        ? 'readback_failed'
                        : 'caliber_uncertain',
                    null,
                    array_values(array_filter((array)($revenueField['_rows'] ?? []), 'is_array')),
                    'strict revenue / room_nights',
                    '收入字段本身尚未成为严格事实，因此不生成 ADR 主值。下一步：先解决收入口径、重复记录或回读问题。',
                    self::observedValues([[
                        $currentOrderAdr ?? $currentBusinessAdr,
                        'revenue / room_nights (candidate)',
                        is_array($orders) ? $orders : $business,
                    ]]),
                    ['adr_blocked_by_non_strict_revenue']
                )
                : (($currentOrderAdr ?? $currentBusinessAdr) !== null
                    && is_array(is_array($orders) ? $orders : $business)
                    && self::rowStrictFinalEligible(is_array($orders) ? $orders : $business)
                ? self::field(
                    'adr',
                    'verified_calculation',
                    $currentOrderAdr ?? $currentBusinessAdr,
                    [is_array($orders) ? $orders : $business],
                    $currentOrderAdr !== null
                        ? 'order_summary_amount / room_nights'
                        : 'business_card_amount / room_nights',
                    '使用同一条当前回执正式记录计算。'
                )
                : (($currentOrderAdr ?? $currentBusinessAdr) !== null
                    ? self::field(
                        'adr',
                        self::rowReadbackIdentityReady(is_array($orders) ? $orders : $business)
                            ? 'caliber_uncertain'
                            : 'readback_failed',
                        null,
                        [is_array($orders) ? $orders : $business],
                        $currentOrderAdr !== null
                            ? 'order_summary_amount / room_nights'
                            : 'business_card_amount / room_nights',
                        '金额与间夜候选来自同一记录，但字段最终校验未达到 verified，不生成 ADR 主值。下一步：确认金额语义并重新校验。',
                        self::observedValues([[
                            $currentOrderAdr ?? $currentBusinessAdr,
                            'revenue / room_nights (candidate)',
                            is_array($orders) ? $orders : $business,
                        ]]),
                        ['adr_inputs_not_strict_final']
                    )
                    : self::field(
                        'adr',
                        self::missingStatus($collection, 'field_unavailable'),
                        null,
                        [],
                        'revenue / room_nights',
                        '收入或间夜缺失，无法计算 ADR。下一步：补齐同一快照的金额与间夜字段。'
                    ))));

        $listExposure = self::numeric($traffic['list_exposure'] ?? null);
        $detailExposure = self::numeric($traffic['detail_exposure'] ?? null);
        $conversion = $listExposure !== null && $listExposure > 0 && $detailExposure !== null
            ? round($detailExposure / $listExposure * 100, 2)
            : null;
        $calculatedConversion = $conversion;
        $explicitConversion = self::rawNumeric($traffic, [
            'exposure_to_browse_rate', 'exposureToBrowseRate',
            'intentionPerExposure', 'expose_visit_rate', 'exposeVisitRate',
        ]);
        $storedFlowRate = self::numeric($traffic['flow_rate'] ?? null);
        $conversionFlags = [];
        $conversionStatus = $conversion !== null ? 'verified_calculation' : self::missingStatus($collection, 'field_unavailable');
        $conversionNote = '使用同一条精确正式流量记录，按访问量 / 曝光量计算。';
        $conversionObservedValues = [];
        if ($conversion !== null && $explicitConversion !== null
            && abs($conversion - $explicitConversion) > 0.05
        ) {
            $conversion = null;
            $conversionStatus = 'caliber_uncertain';
            $conversionFlags[] = 'platform_exposure_to_browse_rate_mismatch';
            $conversionNote = '保存的曝光量、访问量与平台曝光到访问比率冲突。';
        } elseif ($conversion !== null && $explicitConversion !== null) {
            $conversionFlags[] = 'verified_against_platform_exposure_to_browse_rate';
        }
        if ($conversion !== null && $storedFlowRate !== null
            && abs($conversion - $storedFlowRate) > 0.05
        ) {
            $conversionFlags[] = 'legacy_stored_flow_rate_semantic_mismatch';
        }
        if ($conversion !== null && is_array($traffic) && !self::rowStrictFinalEligible($traffic)) {
            $conversionStatus = self::rowReadbackIdentityReady($traffic)
                ? 'caliber_uncertain'
                : 'readback_failed';
            $conversion = null;
            $conversionObservedValues = self::observedValues([[
                $calculatedConversion,
                'detail_exposure / list_exposure (candidate)',
                $traffic,
            ]]);
            $conversionFlags[] = self::rowReadbackIdentityReady($traffic)
                ? 'conversion_inputs_not_strict_final'
                : 'conversion_readback_not_verified';
            $conversionNote = '曝光与访问来自同一快照，但字段最终校验或回读尚未闭合，不生成转化率主值。下一步：先完成该快照的严格校验与回读。';
        }

        $trafficConflictFlag = 'same_run_zero_traffic_conflicts_with_nonzero_orders';
        if (is_array($trafficConflict)) {
            $conflictNote = '平台为所选营业日明确返回 0，但与同次正式回读的非零订单冲突；该记录保留为正式矛盾证据，不把 0 当作可消费事实。';
            $exposureField = self::field(
                'exposure',
                'caliber_uncertain',
                null,
                [$trafficConflict],
                'platform_yesterday_exposure_conflicted_with_same_run_orders',
                $conflictNote,
                self::observedValues([[
                    self::numeric($trafficConflict['list_exposure'] ?? null),
                    'platform_yesterday_exposure_conflicted_with_orders',
                    $trafficConflict,
                ]]),
                [$trafficConflictFlag]
            );
            $visitsField = self::field(
                'visits',
                'caliber_uncertain',
                null,
                [$trafficConflict],
                'platform_yesterday_visits_conflicted_with_same_run_orders',
                $conflictNote,
                self::observedValues([[
                    self::numeric($trafficConflict['detail_exposure'] ?? null),
                    'platform_yesterday_visits_conflicted_with_orders',
                    $trafficConflict,
                ]]),
                [$trafficConflictFlag]
            );
            $conflictConversion = self::rawNumeric($trafficConflict, [
                'exposure_to_browse_rate', 'exposureToBrowseRate',
                'intentionPerExposure', 'expose_visit_rate', 'exposeVisitRate',
            ]) ?? self::numeric($trafficConflict['flow_rate'] ?? null);
            $conversionField = self::field(
                'conversion',
                'caliber_uncertain',
                null,
                [$trafficConflict],
                'platform_yesterday_conversion_conflicted_with_same_run_orders',
                $conflictNote,
                self::observedValues([[
                    $conflictConversion,
                    'platform_yesterday_conversion_conflicted_with_orders',
                    $trafficConflict,
                ]]),
                [$trafficConflictFlag]
            );
        } else {
            $exposureField = self::fieldOrMissing('exposure', $listExposure, $traffic, $collection, 'online_daily_data.list_exposure', '结构化 XHR 全漏斗曝光；DOM 回退数据不重复计入。');
            $visitsField = self::fieldOrMissing('visits', $detailExposure, $traffic, $collection, 'online_daily_data.detail_exposure', '结构化 XHR 全漏斗访问量。');
            $conversionField = self::field(
                'conversion',
                $conversionStatus,
                $conversion,
                is_array($traffic) ? [$traffic] : [],
                'detail_exposure / list_exposure',
                $conversionNote,
                $conversionObservedValues,
                $conversionFlags
            );
        }

        return [
            $revenueField,
            self::fieldOrMissing('order_count', $orderCount, $orders, $collection, 'online_daily_data.book_order_num', '美团目标日订单汇总。'),
            self::fieldOrMissing('room_nights', $roomNights, $orders, $collection, 'online_daily_data.quantity', '美团目标日订单汇总。'),
            $adrField,
            $exposureField,
            $visitsField,
            $conversionField,
            self::field('cancellation', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '美团目标日采集', '本次采集区段未取得取消数据。下一步：补采同一营业日的取消/订单状态字段。'),
            self::field('sellable', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '美团目标日采集', '本次采集区段未取得在售库存。下一步：补采同一营业日的直连房态库存端点。'),
            self::field('bookable', self::platformNotProvidedStatus($collection, $rows !== []), null, [], '美团目标日采集', '本次采集区段未取得可订库存。下一步：补采同一营业日的游客侧可订性证据。'),
        ];
    }

    /** @param array<string,mixed>|null $row @param array<string,mixed> $collection @return array<string,mixed> */
    private static function fieldOrMissing(
        string $key,
        mixed $value,
        ?array $row,
        array $collection,
        string $basis,
        string $note
    ): array {
        if ($value === null || !is_array($row)) {
            return self::field(
                $key,
                self::missingStatus($collection, 'field_unavailable'),
                null,
                [],
                $basis,
                $note . ' 下一步：补齐该字段的当前采集证据并完成正式保存与精确回读。'
            );
        }
        $duplicateRows = array_values(array_filter(
            (array)($row['_closure_duplicate_rows'] ?? []),
            'is_array'
        ));
        if (count($duplicateRows) > 1) {
            $duplicateValues = [];
            $observed = [];
            foreach ($duplicateRows as $duplicateRow) {
                $duplicateValue = self::fieldValueFromRow($key, $duplicateRow);
                if ($duplicateValue !== null) {
                    $duplicateValues[] = $duplicateValue;
                    $observed[] = [$duplicateValue, $basis . ' (duplicate candidate)', $duplicateRow];
                }
            }
            $normalizedValues = array_values(array_unique(array_map(
                static fn(float $candidate): string => number_format($candidate, 6, '.', ''),
                $duplicateValues
            )));
            $allStrict = count(array_filter(
                $duplicateRows,
                static fn(array $candidate): bool => self::rowStrictFinalEligible($candidate)
            )) === count($duplicateRows);
            if (count($normalizedValues) === 1 && $allStrict) {
                return self::field(
                    $key,
                    'strict_readback',
                    $duplicateValues[0],
                    $duplicateRows,
                    $basis,
                    $note . ' 当前回执含重复记录，值完全一致，已按记录身份去重且未累加。',
                    [],
                    ['duplicate_current_receipt_records_deduplicated']
                );
            }
            return self::field(
                $key,
                $allStrict ? 'caliber_uncertain' : 'readback_failed',
                null,
                $duplicateRows,
                $basis,
                '当前回执包含重复记录且值或严格校验状态不一致；不会选择其中一条冒充事实。下一步：去重并重新保存回读。',
                self::observedValues($observed),
                ['duplicate_current_receipt_records_conflicted']
            );
        }
        if (self::rowStrictFinalEligible($row)) {
            return self::field($key, 'strict_readback', $value, [$row], $basis, $note);
        }

        $readbackIdentityReady = self::rowReadbackIdentityReady($row);
        return self::field(
            $key,
            $readbackIdentityReady ? 'caliber_uncertain' : 'readback_failed',
            null,
            [$row],
            $basis,
            $readbackIdentityReady
                ? $note . ' 正式记录已回读，但历史最终态或字段校验尚未达到 verified；候选值只用于追溯。下一步：补充口径证据并重新校验该字段。'
                : $note . ' 正式记录存在，但当前采集回执或整批精确回读没有闭合。下一步：重新保存并按当前回执逐条精确回读。',
            self::observedValues([[$value, $basis . ' (candidate)', $row]]),
            [$readbackIdentityReady ? 'validation_status_not_verified' : 'exact_readback_not_verified']
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $observedValues
     * @param array<int,string> $qualityFlags
     * @return array<string,mixed>
     */
    private static function field(
        string $key,
        string $status,
        mixed $value,
        array $rows,
        string $basis,
        string $note,
        array $observedValues = [],
        array $qualityFlags = []
    ): array {
        $definition = self::FIELD_DEFINITIONS[$key] ?? ['label' => $key, 'unit' => 'value'];
        $rowIds = self::positiveIds(array_column($rows, 'id'));
        $sourceIds = self::positiveIds(array_column($rows, 'data_source_id'));
        $taskIds = self::positiveIds(array_column($rows, 'sync_task_id'));
        $readbackVerified = $rows !== [] && count(array_filter(
            $rows,
            static fn(array $row): bool => (int)($row['readback_verified'] ?? 0) === 1
        )) === count($rows);
        $receiptBindingVerified = $rows !== [] && count(array_filter(
            $rows,
            static fn(array $row): bool => ($row['_closure_current_receipt_bound'] ?? false) === true
        )) === count($rows);
        $exactRunScopeVerified = $rows !== [] && count(array_filter(
            $rows,
            static fn(array $row): bool => strtolower(trim((string)(
                $row['_closure_receipt_scope_status'] ?? ''
            ))) === 'verified'
        )) === count($rows);
        $strictFinal = $rows !== [] && count(array_filter(
            $rows,
            static fn(array $row): bool => self::rowStrictFinalEligible($row)
        )) === count($rows);
        $statusAllowsConsumption = in_array($status, ['strict_readback', 'verified_calculation'], true);

        return [
            'key' => $key,
            'label' => (string)$definition['label'],
            'unit' => (string)$definition['unit'],
            'status' => array_key_exists($status, self::STATUS_LABELS) ? $status : 'source_missing',
            'status_label' => self::STATUS_LABELS[$status] ?? self::STATUS_LABELS['source_missing'],
            'value' => $value,
            'observed_values' => $observedValues,
            'basis' => $basis,
            'note' => $note,
            'quality_flags' => self::sortedStrings($qualityFlags),
            'formal_readback_verified' => $readbackVerified,
            'current_receipt_binding_verified' => $receiptBindingVerified,
            'exact_run_scope_verified' => $exactRunScopeVerified,
            'strict_final_gate' => $strictFinal,
            'revenue_analysis_consumable' => $statusAllowsConsumption && $strictFinal,
            'source_table' => $rowIds === [] ? null : 'online_daily_data',
            'source_record_ids' => $rowIds,
            'source_record_refs' => array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $rowIds
            ),
            'data_source_ids' => $sourceIds,
            'sync_task_ids' => $taskIds,
            'data_dates' => array_values(array_unique(array_filter(array_map(
                static fn(array $row): string => substr(trim((string)($row['data_date'] ?? '')), 0, 10),
                $rows
            )))),
            'collected_at' => self::latestCollectionTime($rows),
            'history_statuses' => self::stringValues($rows, 'history_status'),
            'validation_statuses' => self::stringValues($rows, 'validation_status'),
            '_rows' => $rows,
        ];
    }

    /**
     * Attach the complete non-sensitive identity chain required by every
     * visible/downloaded/downstream field. Profile hashes, credentials and raw
     * responses are deliberately excluded.
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $collection
     * @return array<string,mixed>
     */
    private static function withFieldIdentity(
        array $field,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $businessDate,
        array $collection,
        bool $profileBindingVerified
    ): array {
        $rows = array_values(array_filter(
            (array)($field['_rows'] ?? []),
            'is_array'
        ));
        $key = (string)($field['key'] ?? '');
        $provenance = self::fieldProvenance($key, $rows);
        $sourceIds = self::positiveIds($field['data_source_ids'] ?? []);
        $taskIds = self::positiveIds($field['sync_task_ids'] ?? []);
        $recordIds = self::positiveIds($field['source_record_ids'] ?? []);
        $effectiveSourceId = $sourceIds[0] ?? max(0, (int)($collection['data_source_id'] ?? 0));
        $effectiveTaskId = $taskIds[0] ?? max(0, (int)($collection['sync_task_id'] ?? 0));
        $formalSaved = $recordIds !== [];
        $readbackVerified = ($field['formal_readback_verified'] ?? false) === true
            && ($field['current_receipt_binding_verified'] ?? false) === true
            && ($field['exact_run_scope_verified'] ?? false) === true;
        $fieldOrder = array_search($key, array_keys(self::FIELD_DEFINITIONS), true);
        $note = (string)($field['note'] ?? '');
        $nextAction = '';
        if (preg_match('/下一步：(.+)$/u', $note, $matches) === 1) {
            $nextAction = trim((string)$matches[1]);
        }
        if ($nextAction === '' && ($field['revenue_analysis_consumable'] ?? false) !== true) {
            $nextAction = match ((string)($field['status'] ?? '')) {
                'readback_failed' => '重新保存当前采集结果，并按当前回执逐条精确回读。',
                'caliber_uncertain' => '补充平台字段定义或同口径证据后重新校验。',
                'source_missing' => '建立当前门店的平台来源并重新采集。',
                'field_unavailable' => '补采该字段对应端点并保存回读。',
                default => '补齐严格事实证据后再供下游消费。',
            };
        }

        return $field + [
            'metric_key' => $key,
            'display_order' => $fieldOrder === false ? 999 : $fieldOrder + 1,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'platform_store_id' => trim((string)($collection['platform_hotel_id'] ?? '')) ?: null,
            'store_profile_status' => $profileBindingVerified ? 'verified' : 'unverified',
            'data_source_id' => $effectiveSourceId > 0 ? $effectiveSourceId : null,
            'store_profile_ref' => $effectiveSourceId > 0
                ? 'platform_data_source#' . $effectiveSourceId
                : null,
            'business_date' => $businessDate,
            'capture_id' => $effectiveTaskId > 0 ? $effectiveTaskId : null,
            'capture_ref' => $effectiveTaskId > 0
                ? 'platform_data_sync_task#' . $effectiveTaskId
                : null,
            'source_method' => trim((string)($collection['source_method'] ?? ''))
                ?: ($provenance['source_methods'][0] ?? null),
            'endpoint_ids' => $provenance['endpoint_ids'],
            'source_paths' => $provenance['source_paths'],
            'raw_metric_keys' => $provenance['metric_keys'],
            'storage_fields' => $provenance['storage_fields'],
            'source_trace_refs' => $provenance['source_trace_refs'],
            'validation_status' => match ((string)($field['status'] ?? 'source_missing')) {
                'strict_readback' => ($field['strict_final_gate'] ?? false) === true
                    ? 'verified'
                    : 'readback_verified',
                'verified_calculation' => 'derived_verified',
                default => (string)($field['status'] ?? 'source_missing'),
            },
            'source_validation_statuses' => (array)($field['validation_statuses'] ?? []),
            'persistence_status' => $formalSaved ? 'formally_saved' : 'not_saved',
            'readback_status' => $readbackVerified
                ? 'readback_verified'
                : ($formalSaved ? 'readback_failed' : 'not_attempted'),
            'formal_saved' => $formalSaved,
            'same_snapshot_verified' => (string)($field['status'] ?? '') === 'verified_calculation'
                && count($recordIds) === 1,
            'snapshot_refs' => (array)($field['source_record_refs'] ?? []),
            'consumer_metric_keys' => self::CONSUMER_METRIC_KEYS[$key] ?? [],
            'next_action' => $nextAction,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{endpoint_ids:array<int,string>,source_paths:array<int,string>,metric_keys:array<int,string>,storage_fields:array<int,string>,source_trace_refs:array<int,string>,source_methods:array<int,string>}
     */
    private static function fieldProvenance(string $fieldKey, array $rows): array
    {
        $endpointIds = [];
        $sourcePaths = [];
        $metricKeys = [];
        $storageFields = [];
        $sourceTraceRefs = [];
        $sourceMethods = [];
        $requestedMetricKeys = self::FIELD_FACT_METRIC_KEYS[$fieldKey] ?? [];

        foreach ($rows as $row) {
            $raw = self::decodeArray($row['raw_data'] ?? []);
            $detail = is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
            $capture = is_array($raw['capture_evidence'] ?? null)
                ? $raw['capture_evidence']
                : (is_array($detail['capture_evidence'] ?? null) ? $detail['capture_evidence'] : []);
            foreach ([
                $detail['endpoint_id'] ?? null,
                $detail['capture_section'] ?? null,
                $capture['endpoint_id'] ?? null,
                $capture['capture_source'] ?? null,
                $detail['_capture_source'] ?? null,
            ] as $candidate) {
                $value = trim((string)($candidate ?? ''));
                if ($value !== '') {
                    $endpointIds[] = $value;
                    break;
                }
            }
            foreach ([
                $row['source_trace_id'] ?? null,
                $capture['source_trace_id'] ?? null,
            ] as $candidate) {
                $value = trim((string)($candidate ?? ''));
                if ($value !== '') {
                    $sourceTraceRefs[] = $value;
                    break;
                }
            }
            $method = trim((string)($row['ingestion_method'] ?? $detail['acquisition_method'] ?? ''));
            if ($method !== '') {
                $sourceMethods[] = $method;
            }
            if ($requestedMetricKeys === []) {
                continue;
            }
            $metricStatus = OnlineDataFieldFactService::buildMetricStatus(
                $row,
                $raw,
                $requestedMetricKeys
            );
            foreach ((array)($metricStatus['sample_facts'] ?? []) as $fact) {
                if (!is_array($fact) || (string)($fact['status'] ?? '') === 'missing') {
                    continue;
                }
                $metricKey = trim((string)($fact['metric_key'] ?? ''));
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                $storageField = trim((string)($fact['storage_field'] ?? ''));
                if ($metricKey !== '') {
                    $metricKeys[] = $metricKey;
                }
                if ($sourcePath !== '') {
                    $sourcePaths[] = $sourcePath;
                }
                if ($storageField !== '') {
                    $storageFields[] = $storageField;
                }
            }
        }

        return [
            'endpoint_ids' => self::sortedStrings($endpointIds),
            'source_paths' => self::sortedStrings($sourcePaths),
            'metric_keys' => self::sortedStrings($metricKeys),
            'storage_fields' => self::sortedStrings($storageFields),
            'source_trace_refs' => self::sortedStrings($sourceTraceRefs),
            'source_methods' => self::sortedStrings($sourceMethods),
        ];
    }

    /** @param array<int,array{0:mixed,1:string,2:array<string,mixed>|null}> $items @return array<int,array<string,mixed>> */
    private static function observedValues(array $items): array
    {
        $values = [];
        foreach ($items as [$value, $basis, $row]) {
            if ($value === null || !is_array($row)) {
                continue;
            }
            $rowId = (int)($row['id'] ?? 0);
            $values[] = [
                'value' => $value,
                'basis' => $basis,
                'source_record_id' => $rowId > 0 ? $rowId : null,
                'source_record_ref' => $rowId > 0 ? 'online_daily_data#' . $rowId : null,
            ];
        }
        return $values;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function latestRow(array $rows, callable $predicate): ?array
    {
        $candidates = array_values(array_filter($rows, $predicate));
        usort($candidates, static function (array $left, array $right): int {
            $leftTime = self::rowCollectionTime($left) ?? '';
            $rightTime = self::rowCollectionTime($right) ?? '';
            return [$rightTime, (int)($right['id'] ?? 0)] <=> [$leftTime, (int)($left['id'] ?? 0)];
        });
        if ($candidates === []) {
            return null;
        }
        $selected = $candidates[0];
        if (count($candidates) > 1) {
            $selected['_closure_duplicate_rows'] = $candidates;
        }
        return $selected;
    }

    /** @param array<string,mixed> $row */
    private static function fieldValueFromRow(string $key, array $row): ?float
    {
        return match ($key) {
            'revenue' => self::numeric($row['amount'] ?? null),
            'order_count' => self::numeric($row['book_order_num'] ?? null),
            'room_nights' => self::numeric($row['quantity'] ?? null),
            'exposure' => self::numeric($row['list_exposure'] ?? null),
            'visits' => self::numeric($row['detail_exposure'] ?? null),
            default => null,
        };
    }

    /** @param array<string,mixed> $row */
    private static function rowFieldEligible(array $row): bool
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return false;
        }
        $status = strtolower(trim((string)($row['validation_status'] ?? '')));
        if (in_array($status, self::BLOCKING_VALIDATION_STATUSES, true)) {
            return false;
        }
        foreach (self::decodeArray($row['validation_flags'] ?? []) as $flag) {
            $code = strtolower(trim((string)(is_array($flag) ? ($flag['code'] ?? '') : $flag)));
            if ($code !== '' && (
                str_contains($code, 'date_mismatch')
                || str_contains($code, 'period_mismatch')
                || str_contains($code, 'wrong_hotel')
                || str_contains($code, 'binding_mismatch')
                || str_contains($code, 'conflicts_with_nonzero')
                || str_contains($code, 'collection_failed')
                || str_contains($code, 'parse_failed')
            )) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $row */
    private static function rowReadbackIdentityReady(array $row): bool
    {
        return (int)($row['readback_verified'] ?? 0) === 1
            && ($row['_closure_current_receipt_bound'] ?? false) === true
            && strtolower(trim((string)($row['_closure_receipt_scope_status'] ?? ''))) === 'verified';
    }

    /** @param array<string,mixed> $row */
    private static function rowStrictFinalEligible(array $row): bool
    {
        return self::rowReadbackIdentityReady($row)
            && strtolower(trim((string)($row['history_status'] ?? ''))) === 'success'
            && strtolower(trim((string)($row['validation_status'] ?? ''))) === 'verified';
    }

    /** @param array<string,mixed> $row */
    private static function isSameRunZeroTrafficConflict(array $row): bool
    {
        if ((string)($row['data_type'] ?? '') !== 'traffic'
            || self::captureSource($row) !== 'xhr:traffic:traffic'
            || strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'quarantined'
            || self::numeric($row['list_exposure'] ?? null) !== 0.0
            || self::numeric($row['detail_exposure'] ?? null) !== 0.0
        ) {
            return false;
        }
        foreach (self::decodeArray($row['validation_flags'] ?? []) as $flag) {
            $code = strtolower(trim((string)(is_array($flag) ? ($flag['code'] ?? '') : $flag)));
            if ($code === 'same_run_zero_traffic_conflicts_with_nonzero_orders') {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $collection */
    private static function rowBelongsToCurrentReceipt(array $row, array $collection): bool
    {
        $rowId = (int)($row['id'] ?? 0);
        $sourceId = (int)($collection['data_source_id'] ?? 0);
        $taskId = (int)($collection['sync_task_id'] ?? 0);
        $acceptedRecordIds = array_fill_keys(
            self::positiveIds($collection['accepted_record_ids'] ?? []),
            true
        );
        if ($rowId <= 0
            || $sourceId <= 0
            || $taskId <= 0
            || !isset($acceptedRecordIds[$rowId])
            || (int)($row['data_source_id'] ?? 0) !== $sourceId
            || (int)($row['sync_task_id'] ?? 0) !== $taskId
        ) {
            return false;
        }
        $receiptPeriod = strtolower(trim((string)($collection['data_period'] ?? '')));
        $rowPeriod = strtolower(trim((string)($row['data_period'] ?? '')));
        return $receiptPeriod !== '' && $rowPeriod === $receiptPeriod;
    }

    /** @param array<string,mixed> $collection */
    private static function collectionBlockerStatus(array $collection): ?string
    {
        $status = self::collectionFailureReason($collection);
        if (in_array($status, ['login_expired', 'date_mismatch'], true)) {
            return $status;
        }
        if ($status === 'collection_failed'
            && (self::positiveIds($collection['accepted_record_ids'] ?? []) === []
                || strtolower(trim((string)($collection['exact_run_readback_status'] ?? ''))) !== 'verified')
        ) {
            return 'collection_failed';
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private static function captureSource(array $row): string
    {
        $raw = self::decodeArray($row['raw_data'] ?? []);
        $detail = is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
        return strtolower(trim((string)(
            $detail['_capture_source']
            ?? $detail['capture_source']
            ?? $raw['capture_evidence']['capture_source']
            ?? ''
        )));
    }

    /** @param array<string,mixed>|null $row @param array<int,string> $keys */
    private static function rawNumeric(?array $row, array $keys): ?float
    {
        if (!is_array($row)) {
            return null;
        }
        $raw = self::decodeArray($row['raw_data'] ?? []);
        $detail = is_array($raw['row'] ?? null) ? array_merge($raw, $raw['row']) : $raw;
        foreach ($keys as $key) {
            $value = self::numeric($detail[$key] ?? null);
            if ($value !== null) {
                return $value > 0 && $value <= 1 ? $value * 100 : $value;
            }
        }
        return null;
    }

    private static function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = is_string($value)
            ? str_replace([',', '%'], '', trim($value))
            : $value;
        return is_numeric($value) ? (float)$value : null;
    }

    /** @param array<string,mixed> $collection */
    private static function missingStatus(array $collection, string $fallback): string
    {
        $failureReason = self::collectionFailureReason($collection);
        if (in_array($failureReason, ['login_expired', 'date_mismatch', 'collection_failed'], true)) {
            return 'source_missing';
        }
        $exactRunStatus = strtolower(trim((string)($collection['exact_run_readback_status'] ?? '')));
        if ($exactRunStatus !== ''
            && $exactRunStatus !== 'verified'
            && ((int)($collection['sync_task_id'] ?? 0) > 0
                || self::positiveIds($collection['receipt_record_ids'] ?? []) !== [])
        ) {
            return 'readback_failed';
        }
        if ((int)($collection['data_source_id'] ?? 0) <= 0
            || (int)($collection['sync_task_id'] ?? 0) <= 0
            || self::positiveIds($collection['accepted_record_ids'] ?? []) === []
        ) {
            return 'source_missing';
        }
        return in_array($fallback, ['field_unavailable', 'source_missing', 'readback_failed'], true)
            ? $fallback
            : 'field_unavailable';
    }

    /** @param array<string,mixed> $collection */
    private static function collectionFailureReason(array $collection): ?string
    {
        $codes = array_map('strtolower', array_map('strval', (array)($collection['reason_codes'] ?? [])));
        $taskStatus = strtolower(trim((string)($collection['sync_task_status'] ?? '')));
        foreach ($codes as $code) {
            if (str_contains($code, 'login_expired')
                || str_contains($code, 'auth_expired')
                || str_contains($code, 'session_expired')
            ) {
                return 'login_expired';
            }
            if (str_contains($code, 'date_mismatch') || str_contains($code, 'target_date_mismatch')) {
                return 'date_mismatch';
            }
        }
        if (strtolower(trim((string)($collection['target_date_status'] ?? ''))) === 'mismatch') {
            return 'date_mismatch';
        }
        if (strtolower(trim((string)($collection['platform_status'] ?? ''))) === 'collection_failed') {
            return 'collection_failed';
        }
        if (strtolower(trim((string)($collection['status'] ?? ''))) === 'collection_failed') {
            return 'collection_failed';
        }
        if (in_array($taskStatus, [
            'failed', 'capture_failed', 'collection_failed',
            'permission_denied', 'waiting_config', 'profile_session_not_ready',
        ], true)) {
            return str_contains($taskStatus, 'session') ? 'login_expired' : 'collection_failed';
        }
        return null;
    }

    /** @param array<string,mixed> $collection */
    private static function platformNotProvidedStatus(
        array $collection,
        bool $hasCurrentReceiptEvidence
    ): string
    {
        $status = self::missingStatus($collection, 'field_unavailable');
        return $hasCurrentReceiptEvidence
            && strtolower(trim((string)($collection['exact_run_readback_status'] ?? ''))) === 'verified'
            && self::positiveIds($collection['accepted_record_ids'] ?? []) !== []
                ? 'field_unavailable'
                : $status;
    }

    /** @param array<string,mixed> $trust @return array<string,mixed> */
    private static function trustPlatformDay(array $trust, string $platform, string $date): array
    {
        foreach ((array)($trust['days'] ?? []) as $day) {
            if (!is_array($day) || (string)($day['date'] ?? '') !== $date) {
                continue;
            }
            foreach ((array)($day['platforms'] ?? []) as $row) {
                if (is_array($row) && (string)($row['platform'] ?? '') === $platform) {
                    return $row;
                }
            }
        }
        return [];
    }

    /** @param array<string,mixed> $trustDay @return array<string,mixed> */
    private static function projectCollectionState(array $trustDay): array
    {
        $receipt = is_array($trustDay['acceptance_receipt'] ?? null)
            ? $trustDay['acceptance_receipt']
            : [];
        $reasonCodes = array_values(array_unique(array_filter(array_map(
            'strval',
            (array)($receipt['reason_codes'] ?? $trustDay['gap_codes'] ?? [])
        ))));
        $receiptScope = is_array($receipt['run_readback_scope'] ?? null)
            ? $receipt['run_readback_scope']
            : [];
        $reasonCodes = self::normalizeProjectedReasonCodes(
            $reasonCodes,
            $receipt,
            $receiptScope
        );
        return [
            'platform_status' => (string)($trustDay['status'] ?? 'unverified'),
            'status' => (string)($receipt['status'] ?? $trustDay['acceptance_status'] ?? 'unverified'),
            'p0_status' => (string)($trustDay['p0_status'] ?? 'blocked'),
            'target_date' => $receipt['target_date'] ?? $trustDay['target_date'] ?? null,
            'target_date_status' => (string)($receipt['target_date_status'] ?? 'unverified'),
            'platform_hotel_id' => $receipt['platform_hotel_id'] ?? null,
            'platform_hotel_status' => (string)($receipt['platform_hotel_status'] ?? 'unverified'),
            'captured_at' => $receipt['captured_at'] ?? null,
            'finished_at' => $receipt['finished_at'] ?? null,
            'source_method' => $receipt['source_method'] ?? null,
            'capture_strategy' => is_array($receipt['capture_strategy'] ?? null)
                ? $receipt['capture_strategy']
                : [],
            'data_period' => $receipt['data_period'] ?? null,
            'data_source_id' => isset($receipt['data_source_id']) ? (int)$receipt['data_source_id'] : null,
            'sync_task_id' => isset($receipt['sync_task_id']) ? (int)$receipt['sync_task_id'] : null,
            'sync_task_status' => $receipt['sync_task_status'] ?? $trustDay['sync_task_status'] ?? null,
            'counts' => is_array($receipt['counts'] ?? null) ? $receipt['counts'] : [],
            'reason_codes' => $reasonCodes,
            'receipt_record_ids' => self::positiveIds($receiptScope['receipt_record_ids'] ?? []),
            'accepted_record_ids' => self::positiveIds($receiptScope['accepted_record_ids'] ?? []),
            'receipt_row_count' => max(0, (int)($receiptScope['receipt_row_count'] ?? 0)),
            'receipt_current_row_count' => max(0, (int)($receiptScope['receipt_current_row_count'] ?? 0)),
            'receipt_missing_row_count' => max(0, (int)($receiptScope['receipt_missing_row_count'] ?? 0)),
            'receipt_identity_mismatch_count' => max(0, (int)($receiptScope['receipt_identity_mismatch_count'] ?? 0)),
            'authoritative_row_count' => max(0, (int)($receiptScope['authoritative_row_count'] ?? 0)),
            'mismatched_row_count' => max(0, (int)($receiptScope['mismatched_row_count'] ?? 0)),
            'exact_run_readback_status' => (string)($receiptScope['status'] ?? 'unverified'),
            'claim_allowed' => ($receipt['claim_allowed'] ?? false) === true,
        ];
    }

    /**
     * The continuous-trust service uses a traffic-P0 vocabulary for several
     * gaps. Once the acceptance receipt independently proves hotel identity,
     * target date and exact persisted-row membership, keep the unresolved
     * traffic gap but do not project it as a contradiction of those broader
     * facts on the shared field-closure contract.
     *
     * @param array<int,string> $reasonCodes
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $receiptScope
     * @return array<int,string>
     */
    private static function normalizeProjectedReasonCodes(
        array $reasonCodes,
        array $receipt,
        array $receiptScope
    ): array {
        $receiptIds = self::positiveIds($receiptScope['receipt_record_ids'] ?? []);
        $acceptedIds = self::positiveIds($receiptScope['accepted_record_ids'] ?? []);
        $declaredReceiptCount = array_key_exists('receipt_row_count', $receiptScope)
            ? max(0, (int)$receiptScope['receipt_row_count'])
            : null;
        $declaredCurrentCount = array_key_exists('receipt_current_row_count', $receiptScope)
            ? max(0, (int)$receiptScope['receipt_current_row_count'])
            : null;
        $exactReadbackVerified = strtolower(trim((string)($receiptScope['status'] ?? ''))) === 'verified'
            && $receiptIds !== []
            && $acceptedIds === $receiptIds
            && $declaredReceiptCount !== null
            && $declaredCurrentCount !== null
            && $declaredReceiptCount === count($receiptIds)
            && $declaredCurrentCount === count($receiptIds)
            && max(0, (int)($receiptScope['receipt_missing_row_count'] ?? 0)) === 0
            && max(0, (int)($receiptScope['receipt_identity_mismatch_count'] ?? 0)) === 0
            && max(0, (int)($receiptScope['mismatched_row_count'] ?? 0)) === 0;
        $identityVerified = strtolower(trim((string)($receipt['platform_hotel_status'] ?? ''))) === 'verified';
        $targetDateMatched = strtolower(trim((string)($receipt['target_date_status'] ?? ''))) === 'matched';
        $counts = is_array($receipt['counts'] ?? null) ? $receipt['counts'] : [];
        $taskCountsVerified = ($counts['saved_readback_match'] ?? false) === true
            && ($counts['target_saved_readback_match'] ?? false) === true;

        $normalized = [];
        foreach ($reasonCodes as $reasonCode) {
            $code = strtolower(trim((string)$reasonCode));
            if ($code === '' || preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $code) !== 1) {
                continue;
            }
            if ($exactReadbackVerified && $code === 'database_readback_not_verified') {
                $code = 'required_traffic_readback_not_verified';
            } elseif ($exactReadbackVerified && $code === 'organized_save_missing') {
                $code = 'required_traffic_formal_row_missing';
            } elseif ($exactReadbackVerified && $code === 'organized_save_scope_conflict') {
                $code = 'required_traffic_formal_scope_conflict';
            } elseif ($exactReadbackVerified && $identityVerified && $code === 'hotel_binding_not_ready') {
                $code = 'required_traffic_hotel_identity_missing';
            } elseif ($exactReadbackVerified && $targetDateMatched && $code === 'target_date_data_missing') {
                $code = 'required_traffic_target_date_data_missing';
            } elseif ($exactReadbackVerified
                && $taskCountsVerified
                && in_array($code, [
                    'saved_readback_count_unverified',
                    'exact_run_readback_scope_mismatch',
                ], true)
            ) {
                continue;
            }
            $normalized[] = $code;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function latestCollectionTime(array $rows): ?string
    {
        $times = array_values(array_filter(array_map(
            static fn(array $row): ?string => self::rowCollectionTime($row),
            $rows
        )));
        rsort($times, SORT_STRING);
        return $times[0] ?? null;
    }

    /** @param array<string,mixed> $row */
    private static function rowCollectionTime(array $row): ?string
    {
        $raw = self::decodeArray($row['raw_data'] ?? []);
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $raw['collected_at'] ?? null,
            $raw['captured_at'] ?? null,
            $capture['collected_at'] ?? null,
            $capture['captured_at'] ?? null,
        ] as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,string> */
    private static function stringValues(array $rows, string $key): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => strtolower(trim((string)($row[$key] ?? ''))),
            $rows
        ))));
        sort($values, SORT_STRING);
        return $values;
    }

    /** @return array<int,int> */
    private static function positiveIds(mixed $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($values) ? $values : [$values]),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $row */
    private static function rowPlatform(array $row): string
    {
        $value = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        return match (true) {
            in_array($value, ['ctrip', 'ctrip_ebooking', 'xiecheng'], true) => 'ctrip',
            in_array($value, ['meituan', 'meituan_ebooking'], true) => 'meituan',
            default => $value,
        };
    }

    /** @return array<string,mixed> */
    private static function decodeArray(mixed $value): array
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

    private static function encodeStable(array $value): string
    {
        return json_encode(
            self::canonicalizeForDigest($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalizeForDigest(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::canonicalizeForDigest($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeForDigest($item);
        }
        return $value;
    }

    /** @return array<int,string> */
    private static function sortedStrings(mixed $values): array
    {
        $items = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            is_array($values) ? $values : [$values]
        ), static fn(string $value): bool => $value !== '')));
        sort($items, SORT_STRING);
        return $items;
    }

    private static function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new \DateTimeZone('Asia/Shanghai')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable
            || (is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('dual_ota_field_closure_date_invalid');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function loadRows(int $tenantId, int $hotelId, string $date): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'source', 'platform',
            'data_type', 'data_date', 'data_period', 'dimension', 'compare_type',
            'history_status', 'validation_status', 'validation_flags',
            'amount', 'quantity', 'book_order_num', 'data_value',
            'list_exposure', 'detail_exposure', 'flow_rate',
            'order_filling_num', 'order_submit_num',
            'readback_verified', 'data_source_id', 'sync_task_id',
            'ingestion_method', 'source_trace_id', 'collected_at', 'snapshot_time',
            'create_time', 'update_time', 'raw_data',
        ], array_keys($columns)));
        foreach ([
            'id', 'tenant_id', 'system_hotel_id', 'source', 'data_type', 'data_date',
            'data_period', 'readback_verified', 'data_source_id', 'sync_task_id',
        ] as $required) {
            if (!isset($columns[$required])) {
                throw new RuntimeException('dual_ota_field_closure_schema_missing:' . $required, 422);
            }
        }
        if ($fields === []) {
            return [];
        }

        $query = Db::name('online_daily_data')
            ->field(implode(',', $fields))
            ->where('system_hotel_id', $hotelId)
            ->where('data_date', $date)
            ->whereIn('source', self::PLATFORMS)
            ->order('id', 'asc');
        $query->where('tenant_id', $tenantId);
        return $query->select()->toArray();
    }

    /** @return array<string,bool> */
    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $this->columns[$table] = array_fill_keys(array_filter(array_map(
                static fn(array $row): string => (string)($row['Field'] ?? ''),
                $rows
            )), true);
        } catch (\Throwable) {
            $this->columns[$table] = [];
        }
        return $this->columns[$table];
    }
}
