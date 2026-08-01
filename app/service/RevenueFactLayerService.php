<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Same-hotel, same-date revenue fact layer for PMS + Ctrip + Meituan.
 *
 * PMS accommodation facts remain whole-hotel facts. OTA facts remain
 * channel-scoped and are never added to PMS revenue. The only cross-source
 * metric divides verified OTA revenue by the verified PMS sellable-room
 * denominator and labels that mixed scope explicitly.
 */
final class RevenueFactLayerService
{
    public const CONTRACT_VERSION = 'revenue_three_source_fact_layer.v1';

    /** @var callable|null */
    private $hotelLoader;

    /** @var callable|null */
    private $pmsLoader;

    /** @var callable|null */
    private $otaLoader;

    /** @var callable|null */
    private $pricingGuardLoader;

    public function __construct(
        ?callable $hotelLoader = null,
        ?callable $pmsLoader = null,
        ?callable $otaLoader = null,
        ?callable $pricingGuardLoader = null
    ) {
        $this->hotelLoader = $hotelLoader;
        $this->pmsLoader = $pmsLoader;
        $this->otaLoader = $otaLoader;
        $this->pricingGuardLoader = $pricingGuardLoader;
    }

    /** @return array<string,mixed> */
    public function build(int $hotelId, string $businessDate): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('revenue_fact_layer_hotel_invalid');
        }
        $businessDate = $this->date($businessDate);

        try {
            $hotel = $this->hotelLoader === null
                ? Db::name('hotels')
                    ->where('id', $hotelId)
                    ->where('status', 1)
                    ->field('id,tenant_id,name,status')
                    ->find()
                : call_user_func($this->hotelLoader, $hotelId);
        } catch (\Throwable) {
            $hotel = null;
        }
        if (!is_array($hotel)
            || (int)($hotel['id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) <= 0
        ) {
            return $this->blockedScope($hotelId, $businessDate);
        }

        $tenantId = (int)$hotel['tenant_id'];
        try {
            $pms = $this->pmsLoader === null
                ? (new DingdandaoOperatingTargetCaptureService())->latest(
                    $tenantId,
                    $hotelId,
                    $businessDate
                )
                : call_user_func(
                    $this->pmsLoader,
                    $tenantId,
                    $hotelId,
                    $businessDate
                );
        } catch (\Throwable) {
            $pms = ['load_status' => 'read_failed'];
        }

        try {
            $ota = $this->otaLoader === null
                ? (new TrustedOtaFactRepository())->pricingHistory(
                    $hotelId,
                    $businessDate,
                    $businessDate
                )
                : call_user_func($this->otaLoader, $hotelId, $businessDate);
        } catch (\Throwable) {
            $ota = [
                'data_status' => 'read_failed',
                'rows' => [],
                'data_gaps' => ['revenue_fact_layer_ota_read_failed'],
            ];
        }

        try {
            $roomTypes = $this->pricingGuardLoader === null
                ? Db::name('room_types')
                    ->field('id,hotel_id,name,base_price,min_price,max_price,room_count,is_enabled')
                    ->where('hotel_id', $hotelId)
                    ->where('is_enabled', 1)
                    ->order('sort_order', 'asc')
                    ->order('id', 'asc')
                    ->select()
                    ->toArray()
                : call_user_func($this->pricingGuardLoader, $hotelId);
        } catch (\Throwable) {
            $roomTypes = ['load_status' => 'read_failed'];
        }

        return $this->assemble(
            $hotel,
            $businessDate,
            is_array($pms) ? $pms : [],
            is_array($ota) ? $ota : [],
            is_array($roomTypes) ? $roomTypes : []
        );
    }

    /**
     * Pure assembler used by focused tests and non-database consumers.
     *
     * @param array<string,mixed> $hotel
     * @param array<string,mixed> $pmsCapture
     * @param array<string,mixed> $otaRepositoryResult
     * @param array<int|string,mixed> $roomTypes
     * @return array<string,mixed>
     */
    public function assemble(
        array $hotel,
        string $businessDate,
        array $pmsCapture,
        array $otaRepositoryResult,
        array $roomTypes
    ): array {
        $businessDate = $this->date($businessDate);
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0) {
            throw new InvalidArgumentException('revenue_fact_layer_scope_invalid');
        }

        $pms = $this->pmsEnvelope(
            $pmsCapture,
            $hotel,
            $businessDate
        );
        $ota = $this->otaEnvelopes(
            $otaRepositoryResult,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $pricingGuard = $this->pricingGuardEnvelope(
            $roomTypes,
            $hotelId
        );

        $sourceCompleteness = [
            'dingdandao_pms' => (string)$pms['data_status'],
            'ctrip_ota' => (string)$ota['ctrip']['data_status'],
            'meituan_ota' => (string)$ota['meituan']['data_status'],
        ];
        $analysisGaps = [];
        foreach ($sourceCompleteness as $source => $status) {
            if ($status === 'readback_verified') {
                continue;
            }
            $gap = $this->gap(
                $source . '_not_readback_verified',
                $source,
                $status,
                'source_identity_or_readback'
            );
            if ($source === 'dingdandao_pms') {
                $currentDate = (new DateTimeImmutable(
                    'now',
                    new \DateTimeZone('Asia/Shanghai')
                ))->format('Y-m-d');
                $pmsHistorical = $businessDate < $currentDate;
                $pmsFuture = $businessDate > $currentDate;
                $gap['recovery_status'] = $pmsHistorical
                    ? 'historical_recollection_available'
                    : (
                        $pmsFuture
                            ? 'future_date_recollection_not_allowed'
                            : 'live_recollection_available'
                    );
                $gap['live_recollection_allowed'] =
                    !$pmsHistorical && !$pmsFuture;
                $gap['historical_recollection_allowed'] = $pmsHistorical;
                $claimReasonCodes = $this->textList(
                    (array)($pms['source']['collection_claim_reason_codes'] ?? []),
                    120
                );
                if ($claimReasonCodes !== []) {
                    $gap['evidence_gap_codes'] = $claimReasonCodes;
                    $gap['display_reason'] =
                        'PMS capture 已保存回读，但未通过当前来源证据合同：'
                        . implode('、', array_map(
                            fn(string $code): string =>
                                $this->pmsClaimGapLabel($code),
                            $claimReasonCodes
                        ))
                        . '。';
                }
                $gap['next_action'] = $pmsHistorical
                    ? '使用授权结构化接口按该历史业务日执行单日经营指标补采，保存后精确回读；不得补写，也不得用今天、旧快照或人工数据冒充。'
                    : (
                        $pmsFuture
                            ? '未来业务日尚不能形成 PMS 经营事实，请保持未验证并等待业务日到达。'
                            : '使用当前验证采集器重新采集并完成精确回读；旧记录不得补写或冒充新来源证据。'
                    );
            }
            $analysisGaps[] = $gap;
        }

        $allThreeSourcesReady = $analysisGaps === [];
        $revenueAnalysisStatus = $allThreeSourcesReady
            ? 'ready'
            : (
                $sourceCompleteness['dingdandao_pms'] === 'readback_verified'
                    ? 'partial'
                    : 'blocked'
            );

        $aiReviewGaps = $analysisGaps;
        if ($analysisGaps === []
            && ($pricingGuard['data_status'] ?? '') !== 'ready'
        ) {
            $aiReviewGaps[] = $this->gap(
                'floor_price_missing',
                'pricing_guard',
                (string)($pricingGuard['data_status'] ?? 'missing'),
                'room_type_floor_price'
            );
        }

        $combinedOta = $this->combinedOta($ota);
        $crossSource = $this->crossSourceMetrics($pms, $combinedOta);
        $analysisMetrics = $this->analysisMetrics(
            $hotel,
            $businessDate,
            $pms,
            $ota,
            $combinedOta,
            $crossSource
        );

        $result = [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $revenueAnalysisStatus,
            'revenue_analysis_status' => $revenueAnalysisStatus,
            'ai_review_status' => $aiReviewGaps === []
                ? 'ready_for_manual_review'
                : 'blocked_by_required_inputs',
            'hotel' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'name' => $this->text($hotel['name'] ?? null, 120),
            ],
            'business_date' => $businessDate,
            'date_alignment' => [
                'status' => 'same_date_key_distinct_source_semantics',
                'pms_date_basis' => 'pms_business_date',
                'ota_date_basis' => 'platform_data_date',
                'note' => '同一日期键只允许分层比较；不自动认定 OTA 下单/支付口径等于 PMS 入住经营口径。',
            ],
            'source_completeness' => $sourceCompleteness,
            'all_three_sources_readback_verified' => $allThreeSourcesReady,
            'sources' => [
                'dingdandao_pms' => $pms,
                'ctrip_ota' => $ota['ctrip'],
                'meituan_ota' => $ota['meituan'],
                'pricing_guard' => $pricingGuard,
            ],
            'facts' => [
                'whole_hotel_accommodation' => $pms['facts'],
                'ota_channel' => [
                    'ctrip' => $ota['ctrip']['facts'],
                    'meituan' => $ota['meituan']['facts'],
                    'combined' => $combinedOta['facts'],
                ],
                'cross_source_comparison' => $crossSource,
            ],
            'analysis_metrics' => $analysisMetrics,
            'analysis_gaps' => $analysisGaps,
            'ai_review_gaps' => $aiReviewGaps,
            'unique_remaining_gap' => count($aiReviewGaps) === 1
                ? $aiReviewGaps[0]
                : null,
            'aggregation_policy' => [
                'pms_plus_ota_revenue_addition_allowed' => false,
                'ota_platform_addition_allowed' => true,
                'ota_platform_addition_scope' => 'ota_channel_only',
                'missing_source_value' => null,
                'cross_source_comparison_requires_same_hotel_and_date' => true,
                'ota_data_may_represent_whole_hotel_revenue' => false,
                'ota_lead_price_may_be_used_as_floor_price' => false,
            ],
        ];
        $result['analysis_diagnostics'] =
            (new RevenueAnalysisDiagnosticsService())->build($result);

        return $result;
    }

    /** @return array<string,mixed> */
    private function pmsEnvelope(
        array $capture,
        array $hotel,
        string $businessDate
    ): array {
        $summary = is_array($capture['summary'] ?? null)
            ? $capture['summary']
            : [];
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $hotelId = (int)($hotel['id'] ?? 0);
        $roomRevenue = $this->number($summary['total_room_fee'] ?? null);
        $sold = $this->integer($summary['sold_room_nights'] ?? null);
        $sellable = $this->integer(
            $summary['derived_sellable_room_nights'] ?? null
        );
        $occupancy = $this->number(
            $summary['occupancy_rate_percent'] ?? null
        );
        $adr = $this->number($summary['adr'] ?? null);
        $revpar = $this->number($summary['revpar'] ?? null);
        $collectionValidation = (new CollectionResultContractService())
            ->validateDingdandaoCaptureClaim($capture, [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
            ]);
        $collectionResult = is_array($collectionValidation['contract'] ?? null)
            ? $collectionValidation['contract']
            : [];

        $trusted = ($collectionValidation['allowed'] ?? false) === true
            && (int)($capture['tenant_id'] ?? 0) === $tenantId
            && (int)($capture['hotel_id'] ?? 0) === $hotelId
            && (string)($capture['business_date'] ?? '') === $businessDate
            && (string)($capture['provider'] ?? '')
                === DingdandaoOperatingTargetCaptureService::PROVIDER
            && (string)($capture['capture_status'] ?? '') === 'verified'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && (string)($capture['reconciliation_status'] ?? '') === 'matched'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified'
            && $roomRevenue !== null
            && $sold !== null
            && $sellable !== null
            && $occupancy !== null
            && $adr !== null
            && $revpar !== null
            && $roomRevenue >= 0
            && $sold >= 0
            && $sellable > 0
            && $sold <= $sellable
            && $occupancy >= 0
            && $occupancy <= 100
            && $adr >= 0
            && $revpar >= 0;
        if ($trusted) {
            $expectedOccupancy = round($sold / $sellable * 100, 2);
            $expectedAdr = $sold > 0
                ? round($roomRevenue / $sold, 2)
                : 0.0;
            $expectedRevpar = round($roomRevenue / $sellable, 2);
            $trusted = abs($occupancy - $expectedOccupancy) <= 0.02
                && abs($adr - $expectedAdr) <= 0.02
                && abs($revpar - $expectedRevpar) <= 0.02;
        }

        $facts = [
            'room_revenue' => $trusted ? round($roomRevenue, 2) : null,
            'sold_room_nights' => $trusted ? $sold : null,
            'sellable_room_nights' => $trusted ? $sellable : null,
            'remaining_sellable_room_nights' => $trusted
                ? max(0, $sellable - $sold)
                : null,
            'occupancy_rate_percent' => $trusted
                ? round($occupancy, 2)
                : null,
            'adr' => $trusted ? round($adr, 2) : null,
            'revpar' => $trusted ? round($revpar, 2) : null,
        ];

        return [
            'data_status' => $trusted
                ? 'readback_verified'
                : (
                    (string)($capture['load_status'] ?? '') === 'read_failed'
                        ? 'read_failed'
                        : 'not_verified'
                ),
            'metric_scope' => 'whole_hotel_accommodation',
            'business_scope' => 'accommodation_room_fee',
            'business_date' => $businessDate,
            'facts' => $facts,
            'source' => [
                'table' => 'dingdandao_operating_target_captures',
                'record_id' => $this->positiveInt($capture['id'] ?? null),
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'provider_hotel_id' => $this->text(
                    $capture['provider_hotel_id'] ?? null,
                    120
                ),
                'provider_hotel_name' => $this->text(
                    $capture['provider_hotel_name'] ?? null,
                    160
                ),
                'system_hotel_name' => $this->text(
                    $hotel['name'] ?? null,
                    160
                ),
                'data_date' => $businessDate,
                'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                'capture_source_scope' => $this->text(
                    $capture['source_scope'] ?? null,
                    80
                ),
                'captured_at' => $this->text(
                    $capture['captured_at'] ?? null,
                    32
                ),
                'source_fingerprint' => $this->hashText(
                    $capture['source_fingerprint'] ?? null
                ),
                'readback_status' => $trusted
                    ? 'readback_verified'
                    : (string)($capture['readback_status'] ?? 'not_verified'),
                'collection_result' => $collectionResult,
                'collection_claim_reason_codes' => $trusted
                    ? []
                    : array_values((array)(
                        $collectionValidation['reason_codes'] ?? []
                    )),
            ],
            'allowed_uses' => $trusted
                ? [
                    'whole_hotel_accommodation_revenue_analysis',
                    'whole_hotel_sellable_room_denominator',
                    'cross_source_comparison_without_revenue_addition',
                ]
                : [],
        ];
    }

    /**
     * @return array{ctrip:array<string,mixed>,meituan:array<string,mixed>}
     */
    private function otaEnvelopes(
        array $repository,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $rows = array_values(array_filter(
            is_array($repository['rows'] ?? null) ? $repository['rows'] : [],
            'is_array'
        ));
        $repositoryReady = (string)($repository['data_status'] ?? '')
            === 'ready'
            && array_values((array)($repository['data_gaps'] ?? [])) === [];
        $result = [];

        foreach (['ctrip', 'meituan'] as $platform) {
            $platformRows = array_values(array_filter(
                $rows,
                static fn(array $row): bool =>
                    strtolower(trim((string)($row['source'] ?? '')))
                        === $platform
                    && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                    && (string)($row['data_date'] ?? '') === $businessDate
                    && ($row['readback_verified'] ?? false) === true
            ));
            $revenue = $this->sumMetric($platformRows, 'amount');
            $orders = $this->sumMetric(
                $platformRows,
                'book_order_num'
            );
            $roomNights = $this->sumMetric(
                $platformRows,
                'quantity'
            );
            $provenanceReady = $platformRows !== [];
            foreach ($platformRows as $row) {
                if ($this->positiveInt($row['row_id'] ?? null) === null
                    || $this->text($row['source_trace_id'] ?? null, 255) === null
                    || $this->text($row['ingestion_method'] ?? null, 80) === null
                ) {
                    $provenanceReady = false;
                    break;
                }
            }
            $trusted = $repositoryReady
                && $platformRows !== []
                && $revenue !== null
                && $orders !== null
                && $roomNights !== null
                && $provenanceReady;
            $result[$platform] = [
                'data_status' => $trusted
                    ? 'readback_verified'
                    : ($platformRows === [] ? 'missing' : 'partial'),
                'metric_scope' => 'ota_channel',
                'business_scope' => 'ota_channel',
                'business_date' => $businessDate,
                'platform' => $platform,
                'facts' => [
                    'revenue' => $trusted ? round($revenue, 2) : null,
                    'orders' => $trusted ? $this->wholeNumber($orders) : null,
                    'room_nights' => $trusted
                        ? $this->wholeNumber($roomNights)
                        : null,
                    'adr' => $trusted && $roomNights > 0
                        ? round($revenue / $roomNights, 2)
                        : null,
                ],
                'source' => [
                    'table' => 'online_daily_data',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'data_date' => $businessDate,
                    'platform' => $platform,
                    'row_ids' => $this->positiveIntList(
                        array_column($platformRows, 'row_id')
                    ),
                    'source_trace_ids' => $this->textList(
                        array_column($platformRows, 'source_trace_id'),
                        255
                    ),
                    'data_source_ids' => $this->positiveIntList(
                        array_column($platformRows, 'data_source_id')
                    ),
                    'sync_task_ids' => $this->positiveIntList(
                        array_column($platformRows, 'sync_task_id')
                    ),
                    'ingestion_methods' => $this->textList(
                        array_column($platformRows, 'ingestion_method'),
                        80
                    ),
                    'readback_status' => $trusted
                        ? 'readback_verified'
                        : 'not_verified',
                ],
                'allowed_uses' => $trusted
                    ? [
                        'ota_channel_revenue_analysis',
                        'cross_source_comparison_without_revenue_addition',
                    ]
                    : [],
            ];
        }

        return $result;
    }

    /** @param array<int|string,mixed> $rows @return array<string,mixed> */
    private function pricingGuardEnvelope(array $rows, int $hotelId): array
    {
        if (($rows['load_status'] ?? null) === 'read_failed') {
            return [
                'data_status' => 'read_failed',
                'metric_scope' => 'manual_pricing_configuration',
                'minimum_floor_price' => null,
                'items' => [],
                'source' => [
                    'table' => 'room_types',
                    'hotel_id' => $hotelId,
                    'evidence_status' => 'read_failed',
                ],
            ];
        }

        $items = [];
        $invalid = false;
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                $invalid = true;
                continue;
            }
            if ((int)($row['hotel_id'] ?? 0) !== $hotelId
                || (int)($row['is_enabled'] ?? 0) !== 1
            ) {
                $invalid = true;
                continue;
            }
            $minPrice = $this->number($row['min_price'] ?? null);
            $basePrice = $this->number($row['base_price'] ?? null);
            $maxPrice = $this->number($row['max_price'] ?? null);
            if ($minPrice === null
                || $basePrice === null
                || $maxPrice === null
                || $minPrice <= 0
                || $basePrice < $minPrice
                || $maxPrice < $basePrice
            ) {
                $invalid = true;
            }
            $items[] = [
                'room_type_id' => $this->positiveInt($row['id'] ?? null),
                'name' => $this->text($row['name'] ?? null, 80),
                'base_price' => $basePrice !== null
                    ? round($basePrice, 2)
                    : null,
                'min_price' => $minPrice !== null
                    ? round($minPrice, 2)
                    : null,
                'max_price' => $maxPrice !== null
                    ? round($maxPrice, 2)
                    : null,
                'room_count' => $this->integer($row['room_count'] ?? null),
            ];
        }
        $floorPrices = array_values(array_filter(
            array_column($items, 'min_price'),
            static fn(mixed $value): bool => is_numeric($value)
                && (float)$value > 0
        ));
        $ready = $items !== []
            && !$invalid
            && count($floorPrices) === count($items);

        return [
            'data_status' => $ready
                ? 'ready'
                : ($items === [] ? 'missing' : 'partial'),
            'metric_scope' => 'manual_pricing_configuration',
            'minimum_floor_price' => $ready
                ? round((float)min($floorPrices), 2)
                : null,
            'items' => $items,
            'source' => [
                'table' => 'room_types',
                'hotel_id' => $hotelId,
                'input_scope' => 'manual_pricing_configuration',
                'evidence_status' => $ready
                    ? 'operator_provided'
                    : 'missing_or_incomplete',
            ],
            'forbidden_substitutes' => [
                'ota_lead_price',
                'ota_sales_avg_price',
                'historical_adr',
                'competitor_price',
            ],
        ];
    }

    /**
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,mixed>
     */
    private function combinedOta(array $ota): array
    {
        $trusted = ($ota['ctrip']['data_status'] ?? '') === 'readback_verified'
            && ($ota['meituan']['data_status'] ?? '') === 'readback_verified';
        $ctripFacts = is_array($ota['ctrip']['facts'] ?? null)
            ? $ota['ctrip']['facts']
            : [];
        $meituanFacts = is_array($ota['meituan']['facts'] ?? null)
            ? $ota['meituan']['facts']
            : [];
        $revenue = $trusted
            ? $this->strictSum([
                $ctripFacts['revenue'] ?? null,
                $meituanFacts['revenue'] ?? null,
            ])
            : null;
        $orders = $trusted
            ? $this->strictSum([
                $ctripFacts['orders'] ?? null,
                $meituanFacts['orders'] ?? null,
            ])
            : null;
        $roomNights = $trusted
            ? $this->strictSum([
                $ctripFacts['room_nights'] ?? null,
                $meituanFacts['room_nights'] ?? null,
            ])
            : null;
        $ready = $trusted
            && $revenue !== null
            && $orders !== null
            && $roomNights !== null;

        return [
            'data_status' => $ready ? 'readback_verified' : 'partial',
            'metric_scope' => 'ota_channel',
            'facts' => [
                'revenue' => $ready ? round($revenue, 2) : null,
                'orders' => $ready ? $this->wholeNumber($orders) : null,
                'room_nights' => $ready
                    ? $this->wholeNumber($roomNights)
                    : null,
                'adr' => $ready && $roomNights > 0
                    ? round($revenue / $roomNights, 2)
                    : null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function crossSourceMetrics(
        array $pms,
        array $combinedOta
    ): array {
        $pmsFacts = is_array($pms['facts'] ?? null) ? $pms['facts'] : [];
        $otaFacts = is_array($combinedOta['facts'] ?? null)
            ? $combinedOta['facts']
            : [];
        $otaRevenue = $this->number($otaFacts['revenue'] ?? null);
        $sellable = $this->number(
            $pmsFacts['sellable_room_nights'] ?? null
        );
        $ready = ($pms['data_status'] ?? '') === 'readback_verified'
            && ($combinedOta['data_status'] ?? '') === 'readback_verified'
            && $otaRevenue !== null
            && $sellable !== null
            && $sellable > 0;

        return [
            'ota_revenue_per_whole_hotel_sellable_room' => $ready
                ? round($otaRevenue / $sellable, 2)
                : null,
            'status' => $ready ? 'ready' : 'not_calculable',
            'metric_scope' => 'cross_source_comparison',
            'numerator_scope' => 'ota_channel',
            'denominator_scope' => 'whole_hotel_accommodation',
            'formula' => 'sum(ctrip_ota.revenue + meituan_ota.revenue) / pms.sellable_room_nights',
            'label' => 'OTA收入/全酒店可售间夜',
            'whole_hotel_revenue_claim_allowed' => false,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function analysisMetrics(
        array $hotel,
        string $businessDate,
        array $pms,
        array $ota,
        array $combinedOta,
        array $crossSource
    ): array {
        $pmsFacts = is_array($pms['facts'] ?? null) ? $pms['facts'] : [];
        $otaFacts = is_array($combinedOta['facts'] ?? null)
            ? $combinedOta['facts']
            : [];
        $pmsTruth = $this->pmsTruth($hotel, $businessDate, $pms);
        $otaTruth = $this->otaTruth($hotel, $businessDate, $ota);
        $crossTruth = $this->crossSourceTruth(
            $hotel,
            $businessDate,
            $pms,
            $ota
        );

        return [
            'ota_room_revenue' => $this->metricRow(
                'ota_room_revenue',
                '目标日 OTA 房费收入',
                $otaFacts['revenue'] ?? null,
                'CNY',
                'ota_channel',
                'data_date',
                ['ctrip', 'meituan'],
                $otaTruth,
                ($combinedOta['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'three_source_ota_facts_partial'
            ),
            'ota_room_nights' => $this->metricRow(
                'ota_room_nights',
                '目标日 OTA 间夜',
                $otaFacts['room_nights'] ?? null,
                'room_nights',
                'ota_channel',
                'data_date',
                ['ctrip', 'meituan'],
                $otaTruth,
                ($combinedOta['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'three_source_ota_facts_partial'
            ),
            'ota_adr' => $this->metricRow(
                'ota_adr',
                '目标日 OTA ADR',
                $otaFacts['adr'] ?? null,
                'CNY',
                'ota_channel',
                'data_date',
                ['ctrip', 'meituan'],
                $otaTruth,
                ($combinedOta['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'three_source_ota_facts_partial'
            ),
            'whole_hotel_room_revenue' => $this->metricRow(
                'whole_hotel_room_revenue',
                '全酒店住宿房费',
                $pmsFacts['room_revenue'] ?? null,
                'CNY',
                'whole_hotel_accommodation',
                'pms_business_date',
                ['dingdandao_pms'],
                $pmsTruth,
                ($pms['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'dingdandao_pms_not_readback_verified'
            ),
            'whole_hotel_sellable_room_nights' => $this->metricRow(
                'whole_hotel_sellable_room_nights',
                '全酒店可售间夜',
                $pmsFacts['sellable_room_nights'] ?? null,
                'room_nights',
                'whole_hotel_accommodation',
                'pms_business_date',
                ['dingdandao_pms'],
                $pmsTruth,
                ($pms['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'dingdandao_pms_not_readback_verified'
            ),
            'whole_hotel_revpar' => $this->metricRow(
                'whole_hotel_revpar',
                '全酒店住宿 RevPAR',
                $pmsFacts['revpar'] ?? null,
                'CNY',
                'whole_hotel_accommodation',
                'pms_business_date',
                ['dingdandao_pms'],
                $pmsTruth,
                ($pms['data_status'] ?? '') === 'readback_verified'
                    ? ''
                    : 'dingdandao_pms_not_readback_verified'
            ),
            // Compatibility key for existing consumers. The fact layer replaces
            // the legacy OTA-only denominator with the verified PMS whole-hotel
            // sellable-room denominator and labels the mixed scope explicitly.
            'ota_contribution_revpar' => $this->metricRow(
                'ota_contribution_revpar',
                'OTA渠道收入/全酒店可售间夜',
                $crossSource['ota_revenue_per_whole_hotel_sellable_room']
                    ?? null,
                'CNY',
                'cross_source_comparison',
                'same_date_key_distinct_source_semantics',
                ['dingdandao_pms', 'ctrip', 'meituan'],
                $crossTruth,
                ($crossSource['status'] ?? '') === 'ready'
                    ? ''
                    : 'cross_source_denominator_or_ota_facts_missing'
            ),
            'ota_revenue_per_whole_hotel_sellable_room' => $this->metricRow(
                'ota_revenue_per_whole_hotel_sellable_room',
                'OTA收入/全酒店可售间夜',
                $crossSource['ota_revenue_per_whole_hotel_sellable_room']
                    ?? null,
                'CNY',
                'cross_source_comparison',
                'same_date_key_distinct_source_semantics',
                ['dingdandao_pms', 'ctrip', 'meituan'],
                $crossTruth,
                ($crossSource['status'] ?? '') === 'ready'
                    ? ''
                    : 'cross_source_denominator_or_ota_facts_missing'
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function metricRow(
        string $key,
        string $label,
        mixed $value,
        string $unit,
        string $scope,
        string $dateBasis,
        array $sourceChannels,
        array $truth,
        string $reason
    ): array {
        $number = $this->number($value);
        return [
            'key' => $key,
            'label' => $label,
            'value' => $number,
            'unit' => $unit,
            'scope' => $scope,
            'date_basis' => $dateBasis,
            'source_channels' => $sourceChannels,
            'status' => $number !== null ? 'ok' : 'not_calculable',
            'reason' => $number !== null ? '' : $reason,
            'truth' => $truth,
        ];
    }

    /** @return array<string,mixed> */
    private function pmsTruth(
        array $hotel,
        string $businessDate,
        array $pms
    ): array {
        $source = is_array($pms['source'] ?? null) ? $pms['source'] : [];
        $verified = ($pms['data_status'] ?? '') === 'readback_verified';
        return [
            'status' => $verified ? 'verified' : 'unverified',
            'status_label' => $verified ? '已验证' : '未验证',
            'metric_scope' => 'whole_hotel_accommodation',
            'scope_label' => 'PMS全酒店住宿口径，不含未证明的其他经营收入',
            'hotels' => [[
                'system_hotel_id' => (int)($hotel['id'] ?? 0),
                'name' => $this->text($hotel['name'] ?? null, 120),
            ]],
            'platforms' => ['dingdandao_pms'],
            'date_range' => [
                'start' => $businessDate,
                'end' => $businessDate,
            ],
            'source' => [
                'table' => 'dingdandao_operating_target_captures',
                'row_ids' => $source['record_id'] === null
                    ? []
                    : [(int)$source['record_id']],
                'trace_ids' => [],
                'methods' => ['verified_pms_capture'],
                'data_types' => ['accommodation_room_fee'],
                'caliber' => 'PMS whole-hotel accommodation facts',
            ],
            'source_methods' => ['verified_pms_capture'],
            'collected_at_range' => [
                'start' => (string)($source['captured_at'] ?? ''),
                'end' => (string)($source['captured_at'] ?? ''),
            ],
            'persistence' => [
                'stored' => $verified,
                'stored_count' => $verified ? 1 : 0,
                'record_count' => $verified ? 1 : 0,
                'readback_verified' => $verified,
                'readback_verified_count' => $verified ? 1 : 0,
            ],
            'failure_reason' => $verified
                ? ''
                : 'dingdandao_pms_not_readback_verified',
            'evidence_gap_codes' => $verified
                ? []
                : ['dingdandao_pms_not_readback_verified'],
        ];
    }

    /**
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,mixed>
     */
    private function otaTruth(
        array $hotel,
        string $businessDate,
        array $ota
    ): array {
        $verified = ($ota['ctrip']['data_status'] ?? '')
                === 'readback_verified'
            && ($ota['meituan']['data_status'] ?? '')
                === 'readback_verified';
        $rowIds = [];
        $traceIds = [];
        $methods = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $source = is_array($ota[$platform]['source'] ?? null)
                ? $ota[$platform]['source']
                : [];
            $rowIds = array_merge(
                $rowIds,
                (array)($source['row_ids'] ?? [])
            );
            $traceIds = array_merge(
                $traceIds,
                (array)($source['source_trace_ids'] ?? [])
            );
            $methods = array_merge(
                $methods,
                (array)($source['ingestion_methods'] ?? [])
            );
        }
        return [
            'status' => $verified ? 'verified' : 'partial',
            'status_label' => $verified ? '已验证' : '部分数据',
            'metric_scope' => 'ota_channel',
            'scope_label' => '携程与美团OTA渠道指标，不代表全酒店经营收入',
            'hotels' => [[
                'system_hotel_id' => (int)($hotel['id'] ?? 0),
                'name' => $this->text($hotel['name'] ?? null, 120),
            ]],
            'platforms' => ['ctrip', 'meituan'],
            'date_range' => [
                'start' => $businessDate,
                'end' => $businessDate,
            ],
            'source' => [
                'table' => 'online_daily_data',
                'row_ids' => $this->positiveIntList($rowIds),
                'trace_ids' => $this->textList($traceIds, 255),
                'methods' => $this->textList($methods, 80),
                'data_types' => ['business'],
                'caliber' => 'latest canonical readback-verified row per OTA platform/date/business grain',
            ],
            'source_methods' => $this->textList($methods, 80),
            'collected_at_range' => [
                'start' => '',
                'end' => '',
            ],
            'persistence' => [
                'stored' => $verified,
                'stored_count' => count($this->positiveIntList($rowIds)),
                'record_count' => count($this->positiveIntList($rowIds)),
                'readback_verified' => $verified,
                'readback_verified_count' => $verified
                    ? count($this->positiveIntList($rowIds))
                    : 0,
            ],
            'failure_reason' => $verified
                ? ''
                : 'three_source_ota_facts_partial',
            'evidence_gap_codes' => $verified
                ? []
                : ['three_source_ota_facts_partial'],
        ];
    }

    /**
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,mixed>
     */
    private function crossSourceTruth(
        array $hotel,
        string $businessDate,
        array $pms,
        array $ota
    ): array {
        $otaTruth = $this->otaTruth($hotel, $businessDate, $ota);
        $verified = ($pms['data_status'] ?? '') === 'readback_verified'
            && ($otaTruth['status'] ?? '') === 'verified';
        return [
            'status' => $verified ? 'verified' : 'partial',
            'status_label' => $verified ? '已验证' : '部分数据',
            'metric_scope' => 'cross_source_comparison',
            'scope_label' => 'OTA渠道分子/PMS全酒店住宿分母；不是全酒店收入',
            'hotels' => $otaTruth['hotels'],
            'platforms' => ['dingdandao_pms', 'ctrip', 'meituan'],
            'date_range' => $otaTruth['date_range'],
            'source' => [
                'table' => 'online_daily_data + dingdandao_operating_target_captures',
                'row_ids' => $otaTruth['source']['row_ids'] ?? [],
                'trace_ids' => $otaTruth['source']['trace_ids'] ?? [],
                'methods' => [
                    'verified_pms_capture',
                    'trusted_ota_canonical_readback',
                ],
                'data_types' => [
                    'accommodation_room_fee',
                    'ota_business',
                ],
                'caliber' => 'OTA revenue / PMS whole-hotel sellable room nights',
            ],
            'source_methods' => [
                'verified_pms_capture',
                'trusted_ota_canonical_readback',
            ],
            'collected_at_range' => [
                'start' => '',
                'end' => '',
            ],
            'persistence' => [
                'stored' => $verified,
                'stored_count' => $verified ? 3 : 0,
                'record_count' => $verified ? 3 : 0,
                'readback_verified' => $verified,
                'readback_verified_count' => $verified ? 3 : 0,
            ],
            'failure_reason' => $verified
                ? ''
                : 'cross_source_denominator_or_ota_facts_missing',
            'evidence_gap_codes' => $verified
                ? []
                : ['cross_source_denominator_or_ota_facts_missing'],
        ];
    }

    /** @return array<string,mixed> */
    private function blockedScope(int $hotelId, string $businessDate): array
    {
        $gap = $this->gap(
            'system_hotel_scope_unavailable',
            'hotel',
            'blocked',
            'system_hotel_identity'
        );
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'blocked',
            'revenue_analysis_status' => 'blocked',
            'ai_review_status' => 'blocked_by_required_inputs',
            'hotel' => [
                'tenant_id' => null,
                'system_hotel_id' => $hotelId,
                'name' => null,
            ],
            'business_date' => $businessDate,
            'source_completeness' => [
                'dingdandao_pms' => 'blocked',
                'ctrip_ota' => 'blocked',
                'meituan_ota' => 'blocked',
            ],
            'all_three_sources_readback_verified' => false,
            'sources' => [],
            'facts' => [],
            'analysis_metrics' => [],
            'analysis_gaps' => [$gap],
            'ai_review_gaps' => [$gap],
            'unique_remaining_gap' => $gap,
            'aggregation_policy' => [
                'pms_plus_ota_revenue_addition_allowed' => false,
                'missing_source_value' => null,
                'ota_data_may_represent_whole_hotel_revenue' => false,
            ],
        ];
        $result['analysis_diagnostics'] =
            (new RevenueAnalysisDiagnosticsService())->build($result);

        return $result;
    }

    /** @return array<string,string> */
    private function gap(
        string $code,
        string $source,
        string $status,
        string $category
    ): array {
        return [
            'code' => $code,
            'source' => $source,
            'status' => $status,
            'category' => $category,
        ];
    }

    private function pmsClaimGapLabel(string $code): string
    {
        return match ($code) {
            'business_module_missing' => '业务模块标识缺失',
            'source_method_missing' => '来源方法缺失',
            'source_trace_missing' => '来源追踪标识缺失',
            'collection_strategy_unverified' => '采集策略未验证',
            'collection_claim_not_allowed' => '来源声明未放行',
            'collection_contract_mismatch' => '来源合同与回读记录不一致',
            default => $code,
        };
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumMetric(array $rows, string $key): ?float
    {
        if ($rows === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            $value = $this->number($row[$key] ?? null);
            if ($value === null || $value < 0) {
                return null;
            }
            $sum += $value;
        }
        return $sum;
    }

    /** @param array<int,mixed> $values */
    private function strictSum(array $values): ?float
    {
        $sum = 0.0;
        foreach ($values as $value) {
            $number = $this->number($value);
            if ($number === null) {
                return null;
            }
            $sum += $number;
        }
        return $sum;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null
            || (is_string($value) && trim($value) === '')
            || !is_numeric($value)
        ) {
            return null;
        }
        $number = (float)$value;
        return is_finite($number) ? $number : null;
    }

    private function integer(mixed $value): ?int
    {
        $number = $this->number($value);
        if ($number === null || floor($number) !== $number) {
            return null;
        }
        return (int)$number;
    }

    private function wholeNumber(float $value): int|float
    {
        return floor($value) === $value ? (int)$value : round($value, 2);
    }

    private function positiveInt(mixed $value): ?int
    {
        $integer = $this->integer($value);
        return $integer !== null && $integer > 0 ? $integer : null;
    }

    /** @param array<int,mixed> $values @return array<int,int> */
    private function positiveIntList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $integer = $this->positiveInt($value);
            if ($integer !== null) {
                $result[$integer] = $integer;
            }
        }
        return array_values($result);
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private function hashList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $hash = $this->hashText($value);
            if ($hash !== null) {
                $result[$hash] = $hash;
            }
        }
        return array_values($result);
    }

    private function hashText(mixed $value): ?string
    {
        $text = strtolower(trim((string)($value ?? '')));
        return preg_match('/^[a-f0-9]{64}$/D', $text) === 1
            ? $text
            : null;
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private function textList(array $values, int $maxLength): array
    {
        $result = [];
        foreach ($values as $value) {
            $text = $this->text($value, $maxLength);
            if ($text !== null) {
                $result[$text] = $text;
            }
        }
        return array_values($result);
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $maxLength)
            : substr($text, 0, $maxLength);
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException(
                'revenue_fact_layer_business_date_invalid'
            );
        }
        return $value;
    }
}
