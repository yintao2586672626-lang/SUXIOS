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
    public function build(
        int $hotelId,
        string $businessDate,
        array $otaOperationalDatasets = []
    ): array
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

        if ($otaOperationalDatasets === []) {
            $otaOperationalDatasets = $this->loadOtaOperationalDatasets(
                $hotelId,
                $businessDate
            );
        }
        $otaOperationalMetrics = $this->otaOperationalMetrics(
            $otaOperationalDatasets,
            $hotelId,
            $businessDate
        );
        $pmsDateEvidence = $this->nearestPmsDateEvidence(
            $tenantId,
            $hotelId,
            $businessDate
        );

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
            is_array($roomTypes) ? $roomTypes : [],
            $otaOperationalMetrics,
            $pmsDateEvidence
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
        array $roomTypes,
        array $otaOperationalMetrics = [],
        array $pmsDateEvidence = []
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
            $businessDate,
            $otaOperationalMetrics
        );
        $pricingGuard = $this->pricingGuardEnvelope(
            $roomTypes,
            $hotelId
        );
        $dateAlignment = $this->dateAlignment(
            $businessDate,
            $pms,
            $ota,
            $pmsDateEvidence
        );

        $sourceCompleteness = [
            'dingdandao_pms' => (string)$pms['data_status'],
            'ctrip_ota' => (string)$ota['ctrip']['data_status'],
            'meituan_ota' => (string)$ota['meituan']['data_status'],
        ];
        $allThreeSourcesReadbackVerified = !in_array(
            false,
            array_map(
                static fn(string $status): bool =>
                    $status === 'readback_verified',
                $sourceCompleteness
            ),
            true
        );
        $allOtaAnalysisGatesAllowed =
            ($ota['ctrip']['analysis_readiness']['allowed'] ?? false) === true
            && ($ota['meituan']['analysis_readiness']['allowed'] ?? false) === true;
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

        foreach (['ctrip', 'meituan'] as $platform) {
            $sourceKey = $platform . '_ota';
            if (($sourceCompleteness[$sourceKey] ?? '') !== 'readback_verified'
                || ($ota[$platform]['analysis_readiness']['allowed'] ?? false) === true
            ) {
                continue;
            }
            $analysisGaps[] = $this->gap(
                $platform . '_ota_revenue_analysis_blocked',
                $sourceKey,
                (string)($ota[$platform]['analysis_readiness']['status']
                    ?? 'blocked'),
                'revenue_analysis_credibility'
            );
        }

        if (($dateAlignment['status'] ?? '') === 'blocked_date_mismatch') {
            $analysisGaps[] = $this->gap(
                'business_date_mismatch',
                'pms_ota_reconciliation',
                'blocked_date_mismatch',
                'business_date_identity'
            ) + [
                'display_reason' => (string)($dateAlignment['message'] ?? ''),
                'next_action' => '按目标业务日重新取得 PMS 事实并完成精确回读；不得把相邻日期、页面当前日期或 OTA 目标日期自动改写为同一天。',
            ];
        }

        $allThreeSourcesReady = $allThreeSourcesReadbackVerified
            && $allOtaAnalysisGatesAllowed
            && $analysisGaps === []
            && ($dateAlignment['status'] ?? '') === 'aligned';
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
        $derivedMetrics = $this->derivedOperatingMetrics(
            $pms,
            $ota,
            $combinedOta,
            $pricingGuard,
            $crossSource
        );
        $reconciliation = $this->reconciliation(
            $dateAlignment,
            $pms,
            $ota,
            $combinedOta,
            $derivedMetrics,
            $otaRepositoryResult,
            $pricingGuard
        );
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
            'date_alignment' => $dateAlignment,
            'source_completeness' => $sourceCompleteness,
            'all_three_sources_readback_verified' =>
                $allThreeSourcesReadbackVerified,
            'all_ota_analysis_gates_allowed' => $allOtaAnalysisGatesAllowed,
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
            'derived_metrics' => $derivedMetrics,
            'reconciliation' => $reconciliation,
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
        $captureBusinessDate = $this->text(
            $capture['business_date'] ?? null,
            10
        );
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
            // The current verified PMS contract captures posted accommodation
            // room fees. It does not capture payment-channel cash receipts.
            'payment_collected_amount' => null,
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
            'actual_business_date' => $captureBusinessDate,
            'facts' => $facts,
            'fact_statuses' => [
                'room_revenue' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                    'caliber' => 'PMS住宿房费，不等同支付实收',
                ],
                'payment_collected_amount' => [
                    'status' => 'missing',
                    'reason' => 'pms_payment_collected_amount_not_captured',
                    'caliber' => '支付通道确认的实收金额',
                ],
                'sold_room_nights' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                ],
                'sellable_room_nights' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                    'caliber' => '由出租房晚与入住率交叉推导并校验的可售房晚',
                ],
                'occupancy_rate_percent' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                ],
                'adr' => [
                    'status' => $trusted ? 'derived_verified' : 'not_calculable',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                    'formula' => 'room_revenue / sold_room_nights',
                ],
                'revpar' => [
                    'status' => $trusted ? 'derived_verified' : 'not_calculable',
                    'reason' => $trusted ? '' : 'dingdandao_pms_not_readback_verified',
                    'formula' => 'room_revenue / sellable_room_nights',
                ],
            ],
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
                'data_date' => $captureBusinessDate,
                'target_business_date' => $businessDate,
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
        string $businessDate,
        array $operationalMetrics = []
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
            $operational = is_array($operationalMetrics[$platform] ?? null)
                ? $operationalMetrics[$platform]
                : [];
            $operationalQuality = is_array(
                $operational['data_quality'] ?? null
            ) ? $operational['data_quality'] : [];
            $revenueRepresentationConflicts =
                $this->revenueRepresentationConflicts(
                    $operationalQuality['revenue_representation_conflicts']
                        ?? []
                );
            $analysisBlockedByRepresentation =
                $revenueRepresentationConflicts !== [];
            $operationalFacts = is_array($operational['facts'] ?? null)
                ? $operational['facts']
                : [];
            $operationalStatuses = is_array(
                $operational['fact_statuses'] ?? null
            )
                ? $operational['fact_statuses']
                : [];
            $operationalAnalysis = is_array(
                $operational['analysis_readiness'] ?? null
            )
                ? $operational['analysis_readiness']
                : [];
            $operationalMetricKeys = [];
            foreach ($operationalFacts as $metricKey => $metricValue) {
                if ($this->operationalMetricStatusReady(
                    $operationalStatuses[$metricKey] ?? [],
                    (string)$metricKey,
                    $platform,
                    $hotelId,
                    $businessDate
                ) && $this->number($metricValue) !== null) {
                    $operationalMetricKeys[] = (string)$metricKey;
                }
            }
            $hasOperationalEvidence = $operationalMetricKeys !== [];
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
            $resolvedRevenue = $trusted
                ? round($revenue, 2)
                : (
                    in_array('revenue', $operationalMetricKeys, true)
                        ? $this->number($operationalFacts['revenue'] ?? null)
                        : null
                );
            $resolvedOrders = $trusted
                ? $this->wholeNumber($orders)
                : (
                    in_array('orders', $operationalMetricKeys, true)
                        ? $this->wholeNumber(
                            $this->number($operationalFacts['orders'] ?? null)
                        )
                        : null
                );
            $resolvedRoomNights = $trusted
                ? $this->wholeNumber($roomNights)
                : (
                    in_array('room_nights', $operationalMetricKeys, true)
                        ? $this->wholeNumber(
                            $this->number(
                                $operationalFacts['room_nights'] ?? null
                            )
                        )
                        : null
                );
            $resolvedAdr = $trusted && $roomNights > 0
                ? round($revenue / $roomNights, 2)
                : (
                    in_array('adr', $operationalMetricKeys, true)
                        ? $this->number($operationalFacts['adr'] ?? null)
                        : null
                );
            $coreFactStatuses = [
                'revenue' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'trusted_ota_revenue_missing',
                ],
                'orders' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'trusted_ota_orders_missing',
                ],
                'room_nights' => [
                    'status' => $trusted ? 'readback_verified' : 'not_verified',
                    'reason' => $trusted ? '' : 'trusted_ota_room_nights_missing',
                ],
                'adr' => [
                    'status' => $trusted && $roomNights > 0
                        ? 'derived_verified'
                        : 'not_calculable',
                    'reason' => $trusted && $roomNights > 0
                        ? ''
                        : 'ota_room_nights_denominator_missing_or_zero',
                    'formula' => 'ota_room_revenue / ota_room_nights',
                ],
            ];
            foreach ($operationalStatuses as $metricKey => $metricStatus) {
                if (!is_array($metricStatus)) {
                    continue;
                }
                $metricKey = (string)$metricKey;
                $currentStatus = is_array($coreFactStatuses[$metricKey] ?? null)
                    ? $coreFactStatuses[$metricKey]
                    : [];
                $operationalReady = $this->operationalMetricStatusReady(
                    $metricStatus,
                    $metricKey,
                    $platform,
                    $hotelId,
                    $businessDate
                );
                $safeMetricStatus = $metricStatus;
                if ((string)($metricStatus['status'] ?? '') === 'derived_verified'
                    && !$this->metricStatusReady($metricStatus, $metricKey)
                ) {
                    $safeMetricStatus['status'] = 'not_verified';
                    $safeMetricStatus['reason'] =
                        'derived_status_not_allowed_for_raw_metric';
                } elseif ($this->metricStatusReady($metricStatus, $metricKey)
                    && !$operationalReady
                ) {
                    $safeMetricStatus['status'] = 'not_verified';
                    $safeMetricStatus['reason'] =
                        'operational_metric_source_identity_mismatch';
                }
                if ($operationalReady
                    || !$this->metricStatusReady($currentStatus, $metricKey)
                ) {
                    $coreFactStatuses[$metricKey] = $safeMetricStatus;
                }
            }
            $operationalMetricProvenance = [];
            foreach ($operationalMetricKeys as $metricKey) {
                $metricStatus = is_array($coreFactStatuses[$metricKey] ?? null)
                    ? $coreFactStatuses[$metricKey]
                    : [];
                $provenance = is_array(
                    $metricStatus['source_provenance'] ?? null
                ) ? $metricStatus['source_provenance'] : [];
                if ($provenance !== []) {
                    $operationalMetricProvenance[$metricKey] = $provenance;
                }
            }
            $analysisReadinessKnown = array_key_exists(
                'allowed',
                $operationalAnalysis
            );
            $analysisAllowed = ($analysisReadinessKnown
                ? ($operationalAnalysis['allowed'] ?? false) === true
                : $trusted) && !$analysisBlockedByRepresentation;
            $analysisReadiness = [
                'allowed' => $analysisAllowed,
                'status' => $analysisBlockedByRepresentation
                    ? 'blocked_representation_conflict'
                    : (
                        $analysisReadinessKnown
                            ? (string)(
                                $operationalAnalysis['status'] ?? 'blocked'
                            )
                            : ($trusted ? 'allowed' : 'not_verified')
                    ),
                'basis' => $analysisReadinessKnown
                    ? 'ota_revenue_metric_credibility_gate'
                    : 'trusted_ota_fact_repository',
            ];
            $result[$platform] = [
                'data_status' => $trusted
                    ? 'readback_verified'
                    : (
                        $hasOperationalEvidence || $platformRows !== []
                            ? 'partial'
                            : 'missing'
                    ),
                'metric_scope' => 'ota_channel',
                'business_scope' => 'ota_channel',
                'business_date' => $businessDate,
                'actual_business_date' => $platformRows !== []
                    ? (string)($platformRows[0]['data_date'] ?? '')
                    : ($hasOperationalEvidence ? $businessDate : null),
                'platform' => $platform,
                'analysis_readiness' => $analysisReadiness,
                'facts' => [
                    'revenue' => $resolvedRevenue,
                    'orders' => $resolvedOrders,
                    'room_nights' => $resolvedRoomNights,
                    'adr' => $resolvedAdr,
                    'list_exposure' => in_array(
                        'list_exposure',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['list_exposure'] ?? null) : null,
                    'detail_exposure' => in_array(
                        'detail_exposure',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['detail_exposure'] ?? null) : null,
                    'flow_rate_percent' => in_array(
                        'flow_rate_percent',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['flow_rate_percent'] ?? null) : null,
                    'submit_rate_percent' => in_array(
                        'submit_rate_percent',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['submit_rate_percent'] ?? null) : null,
                    'cancellation_rate_percent' => in_array(
                        'cancellation_rate_percent',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['cancellation_rate_percent'] ?? null) : null,
                ],
                'fact_statuses' => $coreFactStatuses,
                'operational_crosscheck' => [
                    'revenue' => in_array(
                        'revenue',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['revenue'] ?? null) : null,
                    'orders' => in_array(
                        'orders',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['orders'] ?? null) : null,
                    'room_nights' => in_array(
                        'room_nights',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['room_nights'] ?? null) : null,
                    'adr' => in_array(
                        'adr',
                        $operationalMetricKeys,
                        true
                    ) ? ($operationalFacts['adr'] ?? null) : null,
                    'data_status' => (string)($operational['data_status'] ?? 'not_loaded'),
                    'data_gaps' => array_values((array)(
                        $operational['data_gaps'] ?? []
                    )),
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
                        : (
                            $hasOperationalEvidence
                                ? 'partial_readback_verified'
                                : 'not_verified'
                    ),
                    'operational_metric_keys' => $operationalMetricKeys,
                    'operational_metric_provenance' =>
                        $operationalMetricProvenance,
                    'operational_data_quality' => array_replace(
                        $operationalQuality,
                        [
                            'revenue_representation_conflicts' =>
                                $revenueRepresentationConflicts,
                        ]
                    ),
                ],
                'allowed_uses' => $trusted && $analysisAllowed
                    ? [
                        'ota_channel_revenue_analysis',
                        'cross_source_comparison_without_revenue_addition',
                    ]
                    : (
                        $trusted || $hasOperationalEvidence
                            ? ['ota_channel_metric_level_display']
                            : []
                    ),
            ];
        }

        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    private function loadOtaOperationalDatasets(
        int $hotelId,
        string $businessDate
    ): array {
        $datasets = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            try {
                $datasets[$platform] = (new OtaStandardEtlService())
                    ->buildDataset([
                        'system_hotel_id' => $hotelId,
                        'source' => $platform,
                        'start_date' => $businessDate,
                        'end_date' => $businessDate,
                        'limit' => 5000,
                    ]);
            } catch (\Throwable) {
                $datasets[$platform] = [
                    'status' => 'read_failed',
                    'data_quality' => [
                        'data_gaps' => ['ota_operational_dataset_read_failed'],
                    ],
                ];
            }
        }

        return $datasets;
    }

    /**
     * Normalize the already-persisted OTA standard datasets into factual
     * metrics used by the reconciliation view. Every value still requires its
     * own saved/readback trust envelope; a non-empty dataset is not enough.
     *
     * @param array<string,mixed> $datasets
     * @return array<string,array<string,mixed>>
     */
    private function otaOperationalMetrics(
        array $datasets,
        int $hotelId,
        string $businessDate
    ): array {
        $result = [];
        $definitions = [
            'revenue' => ['totals.revenue', 'totals.revenue'],
            'orders' => ['totals.order_count', 'totals.order_count'],
            'room_nights' => ['totals.room_nights', 'totals.room_nights'],
            'adr' => ['totals.adr', 'totals.adr'],
            'list_exposure' => [
                'traffic.list_exposure',
                'traffic.list_exposure',
            ],
            'detail_exposure' => [
                'traffic.detail_exposure',
                'traffic.detail_exposure',
            ],
            'flow_rate_percent' => [
                'traffic.avg_flow_rate',
                'traffic.avg_flow_rate',
            ],
            'submit_rate_percent' => [
                'traffic.avg_submit_rate',
                'traffic.avg_submit_rate',
            ],
            'cancellation_rate_percent' => [
                'totals.cancellation_rate',
                'totals.cancellation_rate',
            ],
        ];

        foreach (['ctrip', 'meituan'] as $platform) {
            $dataset = is_array($datasets[$platform] ?? null)
                ? $datasets[$platform]
                : [];
            try {
                $summary = $dataset !== []
                    && (string)($dataset['status'] ?? '') !== 'read_failed'
                    ? (new OtaRevenueMetricService())->summarizeDataset($dataset)
                    : [];
            } catch (\Throwable) {
                $summary = [];
            }

            $facts = [];
            $factStatuses = [];
            foreach ($definitions as $key => [$valuePath, $trustKey]) {
                [$value, $status] = $this->trustedOperationalMetric(
                    $summary,
                    $valuePath,
                    $trustKey,
                    $hotelId,
                    $platform,
                    $businessDate
                );
                $facts[$key] = $value;
                $factStatuses[$key] = $status;
            }
            $availableCount = count(array_filter(
                $facts,
                static fn(mixed $value): bool => $value !== null
            ));
            $dataGaps = [];
            foreach ((array)($summary['data_gaps'] ?? []) as $gap) {
                $code = is_array($gap)
                    ? trim((string)($gap['code'] ?? ''))
                    : trim((string)$gap);
                if ($code !== '' && !in_array($code, $dataGaps, true)) {
                    $dataGaps[] = $code;
                }
            }
            if ($summary === []) {
                $dataGaps[] = 'ota_operational_metrics_not_loaded';
            }
            $revenueDecision = is_array(
                $summary['credibility_gate']['decision_use']['revenue_analysis']
                    ?? null
            )
                ? $summary['credibility_gate']['decision_use']['revenue_analysis']
                : [];
            $etlQuality = is_array($summary['etl_quality'] ?? null)
                ? $summary['etl_quality']
                : [];
            $revenueRepresentationConflicts =
                $this->revenueRepresentationConflicts(
                    $etlQuality['meituan_revenue_representation_conflicts']
                        ?? []
                );
            $analysisBlockedByRepresentation =
                $revenueRepresentationConflicts !== [];

            $result[$platform] = [
                'data_status' => $availableCount === 0
                    ? ($summary === [] ? 'not_loaded' : 'missing')
                    : ($availableCount === count($definitions) ? 'ready' : 'partial'),
                'metric_scope' => 'ota_channel',
                'business_date' => $businessDate,
                'platform' => $platform,
                'facts' => $facts,
                'fact_statuses' => $factStatuses,
                'analysis_readiness' => [
                    'allowed' =>
                        ($revenueDecision['allowed'] ?? false) === true
                        && !$analysisBlockedByRepresentation,
                    'status' => $summary === []
                        ? 'not_loaded'
                        : (
                            $analysisBlockedByRepresentation
                                ? 'blocked_representation_conflict'
                                : (string)($revenueDecision['status'] ?? 'blocked')
                        ),
                ],
                'data_quality' => [
                    'canonicalized_traffic_projection_groups' => max(
                        0,
                        (int)($summary['traffic']
                            ['canonicalized_projection_groups'] ?? 0)
                    ),
                    'traffic_projection_policy' => (string)(
                        $summary['traffic']['projection_policy'] ?? ''
                    ),
                    'revenue_representation_conflicts' =>
                        $revenueRepresentationConflicts,
                ],
                'data_gaps' => array_values(array_unique($dataGaps)),
            ];
        }

        return $result;
    }

    /** @return array{0:?float,1:array<string,mixed>} */
    private function trustedOperationalMetric(
        array $summary,
        string $valuePath,
        string $trustKey,
        int $hotelId,
        string $platform,
        string $businessDate
    ): array {
        $trust = is_array($summary['metric_trust'][$trustKey] ?? null)
            ? $summary['metric_trust'][$trustKey]
            : [];
        $value = $this->arrayPath($summary, $valuePath);
        $number = $this->number($value);
        $failureReasons = $this->textList(
            (array)($trust['failure_reasons'] ?? []),
            160
        );
        $source = is_array($trust['source'] ?? null)
            ? $trust['source']
            : [];
        [$sourceIdentity, $identityFailureReasons] =
            $this->operationalSourceIdentity(
                $source,
                $hotelId,
                $platform,
                $businessDate
            );
        $failureReasons = array_values(array_unique(array_merge(
            $failureReasons,
            $identityFailureReasons
        )));
        $trusted = $number !== null
            && ($trust['saved_success'] ?? false) === true
            && $failureReasons === [];

        return [
            $trusted ? $number : null,
            [
                'status' => $trusted ? 'readback_verified' : (
                    $number === null ? 'missing' : 'not_verified'
                ),
                'reason' => $trusted
                    ? ''
                    : ($failureReasons[0] ?? 'metric_readback_not_verified'),
                'caliber' => (string)($trust['caliber'] ?? ''),
                'updated_at' => $trusted
                    ? (string)($trust['updated_at'] ?? '')
                    : '',
                'source_identity' => $sourceIdentity,
                'source_provenance' => [
                    'table' => (string)($source['table'] ?? ''),
                    'row_ids' => $this->positiveIntList(
                        (array)($source['row_ids'] ?? [])
                    ),
                    'trace_ids' => $this->textList(
                        (array)($source['trace_ids'] ?? []),
                        255
                    ),
                    'data_source_ids' => $this->positiveIntList(
                        (array)($source['data_source_ids'] ?? [])
                    ),
                    'sync_task_ids' => $this->positiveIntList(
                        (array)($source['sync_task_ids'] ?? [])
                    ),
                    'data_types' => $this->textList(
                        (array)($source['data_types'] ?? []),
                        80
                    ),
                    'source_methods' => $this->textList(
                        (array)($source['source_methods'] ?? []),
                        80
                    ),
                    'stored_count' => max(
                        0,
                        $this->integer($source['stored_count'] ?? null) ?? 0
                    ),
                    'readback_verified_count' => max(
                        0,
                        $this->integer(
                            $source['readback_verified_count'] ?? null
                        ) ?? 0
                    ),
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $source
     * @return array{0:array<string,mixed>,1:array<int,string>}
     */
    private function operationalSourceIdentity(
        array $source,
        int $hotelId,
        string $platform,
        string $businessDate
    ): array {
        $hotelIds = [];
        foreach ((array)($source['hotels'] ?? []) as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $value = $this->positiveInt($hotel['system_hotel_id'] ?? null);
            if ($value !== null && !in_array($value, $hotelIds, true)) {
                $hotelIds[] = $value;
            }
        }
        sort($hotelIds);
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($source['platforms'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
        sort($platforms);
        $dateRange = is_array($source['date_range'] ?? null)
            ? $source['date_range']
            : [];
        $startDate = $this->text($dateRange['start'] ?? null, 10);
        $endDate = $this->text($dateRange['end'] ?? null, 10);

        $failureReasons = [];
        if ($hotelIds !== [$hotelId]) {
            $failureReasons[] = 'metric_source_hotel_mismatch';
        }
        if ($platforms !== [$platform]) {
            $failureReasons[] = 'metric_source_platform_mismatch';
        }
        if ($startDate !== $businessDate || $endDate !== $businessDate) {
            $failureReasons[] = 'metric_source_date_mismatch';
        }

        return [[
            'status' => $failureReasons === [] ? 'matched' : 'unverified',
            'system_hotel_ids' => $hotelIds,
            'platforms' => $platforms,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
        ], $failureReasons];
    }

    /** @param array<string,mixed> $status */
    private function operationalMetricStatusReady(
        array $status,
        string $metricKey,
        string $platform,
        int $hotelId,
        string $businessDate
    ): bool {
        if (!$this->metricStatusReady($status, $metricKey)) {
            return false;
        }
        $identity = is_array($status['source_identity'] ?? null)
            ? $status['source_identity']
            : [];
        $hotelIds = $this->positiveIntList(
            (array)($identity['system_hotel_ids'] ?? [])
        );
        sort($hotelIds);
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string)$value)),
            (array)($identity['platforms'] ?? [])
        ), static fn(string $value): bool => $value !== '')));
        sort($platforms);
        $dateRange = is_array($identity['date_range'] ?? null)
            ? $identity['date_range']
            : [];

        return (string)($identity['status'] ?? '') === 'matched'
            && $hotelIds === [$hotelId]
            && $platforms === [$platform]
            && (string)($dateRange['start'] ?? '') === $businessDate
            && (string)($dateRange['end'] ?? '') === $businessDate;
    }

    /** @param array<string,mixed> $status */
    private function metricStatusReady(
        array $status,
        string $metricKey
    ): bool
    {
        $value = (string)($status['status'] ?? '');
        return $value === 'readback_verified'
            || ($value === 'derived_verified' && $metricKey === 'adr');
    }

    private function arrayPath(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    /** @return array<string,mixed> */
    private function nearestPmsDateEvidence(
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        try {
            $rows = Db::name('dingdandao_operating_target_captures')
                ->field('id,business_date,captured_at,capture_status,quality_status,identity_status,reconciliation_status,readback_status')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->order('captured_at', 'desc')
                ->order('id', 'desc')
                ->limit(50)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [
                'status' => 'not_available',
                'business_date' => null,
                'distance_days' => null,
            ];
        }

        $target = new DateTimeImmutable($businessDate);
        $nearest = null;
        $nearestDistance = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dateText = trim((string)($row['business_date'] ?? ''));
            try {
                $date = $this->date($dateText);
            } catch (\Throwable) {
                continue;
            }
            $distance = abs((int)$target->diff(new DateTimeImmutable($date))->format('%r%a'));
            if ($nearestDistance === null || $distance < $nearestDistance) {
                $nearest = $row;
                $nearestDistance = $distance;
            }
        }
        if (!is_array($nearest)) {
            return [
                'status' => 'missing',
                'business_date' => null,
                'distance_days' => null,
            ];
        }

        $trusted = (string)($nearest['capture_status'] ?? '') === 'verified'
            && (string)($nearest['quality_status'] ?? '') === 'verified'
            && (string)($nearest['identity_status'] ?? '') === 'matched'
            && (string)($nearest['reconciliation_status'] ?? '') === 'matched'
            && (string)($nearest['readback_status'] ?? '') === 'readback_verified';

        return [
            'status' => $trusted ? 'available' : 'unverified_candidate',
            'record_id' => $this->positiveInt($nearest['id'] ?? null),
            'business_date' => (string)($nearest['business_date'] ?? ''),
            'distance_days' => $nearestDistance,
            'captured_at' => $this->text($nearest['captured_at'] ?? null, 32),
            'capture_status' => (string)($nearest['capture_status'] ?? ''),
            'quality_status' => (string)($nearest['quality_status'] ?? ''),
            'identity_status' => (string)($nearest['identity_status'] ?? ''),
            'reconciliation_status' => (string)(
                $nearest['reconciliation_status'] ?? ''
            ),
            'readback_status' => (string)($nearest['readback_status'] ?? ''),
            'may_block_date_alignment' => $trusted,
        ];
    }

    /**
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,mixed>
     */
    private function dateAlignment(
        string $businessDate,
        array $pms,
        array $ota,
        array $pmsDateEvidence
    ): array {
        $pmsRecordId = $this->positiveInt(
            $pms['source']['record_id'] ?? null
        );
        $pmsObservedDate = $pmsRecordId !== null
            ? $this->text($pms['actual_business_date'] ?? null, 10)
            : null;
        if ($pmsObservedDate === null
            && ($pmsDateEvidence['status'] ?? '') === 'available'
        ) {
            $pmsObservedDate = $this->text(
                $pmsDateEvidence['business_date'] ?? null,
                10
            );
        }

        $sources = [
            'dingdandao_pms' => [
                'target_date' => $businessDate,
                'observed_date' => $pmsObservedDate,
                'date_basis' => 'pms_business_date',
                'data_status' => (string)($pms['data_status'] ?? 'not_verified'),
                'nearest_saved_evidence' => $pmsDateEvidence,
            ],
        ];
        foreach (['ctrip', 'meituan'] as $platform) {
            $sources[$platform . '_ota'] = [
                'target_date' => $businessDate,
                'observed_date' => $this->text(
                    $ota[$platform]['actual_business_date'] ?? null,
                    10
                ),
                'date_basis' => 'platform_data_date',
                'data_status' => (string)(
                    $ota[$platform]['data_status'] ?? 'missing'
                ),
            ];
        }

        $mismatches = [];
        $missing = [];
        foreach ($sources as $source => $row) {
            $observedDate = (string)($row['observed_date'] ?? '');
            if ($observedDate !== '' && $observedDate !== $businessDate) {
                $mismatches[] = [
                    'source' => $source,
                    'target_date' => $businessDate,
                    'observed_date' => $observedDate,
                ];
                continue;
            }
            if ($observedDate === ''
                || (string)($row['data_status'] ?? '') !== 'readback_verified'
            ) {
                $missing[] = $source;
            }
        }

        $status = $mismatches !== []
            ? 'blocked_date_mismatch'
            : ($missing === [] ? 'aligned' : 'incomplete');
        $message = match ($status) {
            'aligned' => 'PMS、携程、美团均已按同一目标业务日精确回读；指标仍按各自来源口径分层展示。',
            'blocked_date_mismatch' => '发现来源实际业务日期与目标日不一致，本次不可对账，也不可自动改日或合并。',
            default => '尚未取得全部来源的目标日精确回读，当前只展示已验证来源，缺失来源不以旧日期替代。',
        };

        return [
            'status' => $status,
            'comparison_allowed' => $status === 'aligned',
            'target_business_date' => $businessDate,
            'timezone' => 'Asia/Shanghai',
            'pms_date_basis' => 'pms_business_date',
            'ota_date_basis' => 'platform_data_date',
            'sources' => $sources,
            'mismatches' => $mismatches,
            'missing_sources' => $missing,
            'message' => $message,
            'note' => '日期对齐只允许分层比较；不自动认定 OTA 下单、支付或结算口径等于 PMS 入住经营口径。',
        ];
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
            && ($ota['meituan']['data_status'] ?? '') === 'readback_verified'
            && ($ota['ctrip']['analysis_readiness']['allowed'] ?? false) === true
            && ($ota['meituan']['analysis_readiness']['allowed'] ?? false) === true
            && in_array(
                'ota_channel_revenue_analysis',
                (array)($ota['ctrip']['allowed_uses'] ?? []),
                true
            )
            && in_array(
                'ota_channel_revenue_analysis',
                (array)($ota['meituan']['allowed_uses'] ?? []),
                true
            );
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
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,array<string,mixed>>
     */
    private function derivedOperatingMetrics(
        array $pms,
        array $ota,
        array $combinedOta,
        array $pricingGuard,
        array $crossSource
    ): array {
        $pmsFacts = is_array($pms['facts'] ?? null) ? $pms['facts'] : [];
        $otaFacts = is_array($combinedOta['facts'] ?? null)
            ? $combinedOta['facts']
            : [];
        $pmsReady = ($pms['data_status'] ?? '') === 'readback_verified';
        $otaReady = ($combinedOta['data_status'] ?? '') === 'readback_verified';
        $pmsSold = $this->number($pmsFacts['sold_room_nights'] ?? null);
        $pmsRevenue = $this->number($pmsFacts['room_revenue'] ?? null);
        $otaRoomNights = $this->number($otaFacts['room_nights'] ?? null);
        $otaRevenue = $this->number($otaFacts['revenue'] ?? null);
        $otaAdr = $this->number($otaFacts['adr'] ?? null);

        $roomNightShare = $pmsReady && $otaReady
            && $pmsSold !== null && $pmsSold > 0
            && $otaRoomNights !== null
                ? round($otaRoomNights / $pmsSold * 100, 2)
                : null;
        $roomRevenueShare = $pmsReady && $otaReady
            && $pmsRevenue !== null && $pmsRevenue > 0
            && $otaRevenue !== null
                ? round($otaRevenue / $pmsRevenue * 100, 2)
                : null;

        $cancelNumerator = 0.0;
        $cancelDenominator = 0.0;
        $cancelPlatforms = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $facts = is_array($ota[$platform]['facts'] ?? null)
                ? $ota[$platform]['facts']
                : [];
            $orders = $this->number($facts['orders'] ?? null);
            $rate = $this->number(
                $facts['cancellation_rate_percent'] ?? null
            );
            if ($orders === null || $orders < 0) {
                continue;
            }
            if ($orders === 0.0) {
                // A verified zero-order platform contributes no weight and
                // must not make the other platform's cancellation rate vanish.
                $cancelPlatforms[] = $platform;
                continue;
            }
            if ($rate === null) {
                continue;
            }
            $cancelNumerator += $orders * $rate / 100;
            $cancelDenominator += $orders;
            $cancelPlatforms[] = $platform;
        }
        $cancellationRate = count($cancelPlatforms) === 2
            && $cancelDenominator > 0
                ? round($cancelNumerator / $cancelDenominator * 100, 2)
                : null;

        $floorPrice = ($pricingGuard['data_status'] ?? '') === 'ready'
            ? $this->number($pricingGuard['minimum_floor_price'] ?? null)
            : null;
        $floorGap = $floorPrice !== null && $otaAdr !== null
            ? round($otaAdr - $floorPrice, 2)
            : null;

        return [
            'whole_hotel_adr' => $this->operatingMetric(
                $pmsFacts['adr'] ?? null,
                'CNY',
                'whole_hotel_accommodation',
                'room_revenue / sold_room_nights',
                $pmsReady ? '' : 'dingdandao_pms_not_readback_verified'
            ),
            'whole_hotel_revpar' => $this->operatingMetric(
                $pmsFacts['revpar'] ?? null,
                'CNY',
                'whole_hotel_accommodation',
                'room_revenue / sellable_room_nights',
                $pmsReady ? '' : 'dingdandao_pms_not_readback_verified'
            ),
            'ota_adr' => $this->operatingMetric(
                $otaAdr,
                'CNY',
                'ota_channel',
                'ota_room_revenue / ota_room_nights',
                $otaReady ? '' : 'three_source_ota_facts_partial'
            ),
            'ota_room_night_share_percent' => $this->operatingMetric(
                $roomNightShare,
                '%',
                'cross_source_comparison',
                'ota_room_nights / pms_sold_room_nights * 100',
                $roomNightShare === null
                    ? 'pms_sold_room_nights_or_ota_room_nights_missing'
                    : '',
                'OTA渠道房晚占PMS全酒店出租房晚；可能受取消、入住日与订单日口径影响。'
            ),
            'ota_room_revenue_share_percent' => $this->operatingMetric(
                $roomRevenueShare,
                '%',
                'cross_source_comparison',
                'ota_room_revenue / pms_accommodation_room_fee * 100',
                $roomRevenueShare === null
                    ? 'pms_room_fee_or_ota_room_revenue_missing'
                    : '',
                '分母是PMS住宿房费，不是支付实收；只作同日房费结构参考。'
            ),
            'ota_cancellation_rate_percent' => $this->operatingMetric(
                $cancellationRate,
                '%',
                'ota_channel',
                'sum(platform_cancel_rate * platform_orders) / sum(platform_orders)',
                $cancellationRate === null
                    ? 'all_platform_cancellation_rate_or_order_base_missing'
                    : '',
                '按平台订单数加权；不与PMS取消口径混算。'
            ),
            'ota_revenue_per_whole_hotel_sellable_room' =>
                $this->operatingMetric(
                    $crossSource[
                        'ota_revenue_per_whole_hotel_sellable_room'
                    ] ?? null,
                    'CNY',
                    'cross_source_comparison',
                    (string)($crossSource['formula'] ?? ''),
                    ($crossSource['status'] ?? '') === 'ready'
                        ? ''
                        : 'cross_source_denominator_or_ota_facts_missing'
                ),
            'ota_adr_minus_minimum_floor_price' => $this->operatingMetric(
                $floorGap,
                'CNY',
                'reference_only',
                'combined_ota_adr - minimum_configured_room_type_floor_price',
                $floorGap === null
                    ? 'floor_price_or_ota_adr_missing'
                    : 'room_type_grain_not_aligned',
                '全店最低保护价与综合OTA ADR粒度不同，只作预警参考，不能据此判定某房型低价销售。'
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function operatingMetric(
        mixed $value,
        string $unit,
        string $scope,
        string $formula,
        string $reason = '',
        string $note = ''
    ): array {
        $number = $this->number($value);
        $status = $number !== null
            ? ($reason === '' ? 'ready' : 'reference_only')
            : 'not_calculable';
        return [
            'value' => $number,
            'unit' => $unit,
            'status' => $status,
            'scope' => $scope,
            'formula' => $formula,
            'reason' => $number === null ? $reason : (
                $status === 'reference_only' ? $reason : ''
            ),
            'note' => $note,
        ];
    }

    /**
     * @param array{ctrip:array<string,mixed>,meituan:array<string,mixed>} $ota
     * @return array<string,mixed>
     */
    private function reconciliation(
        array $dateAlignment,
        array $pms,
        array $ota,
        array $combinedOta,
        array $derivedMetrics,
        array $otaRepository,
        array $pricingGuard
    ): array {
        $checks = [];
        $dateStatus = (string)($dateAlignment['status'] ?? 'incomplete');
        $checks[] = [
            'key' => 'business_date',
            'label' => '业务日期',
            'status' => match ($dateStatus) {
                'aligned' => 'matched',
                'blocked_date_mismatch' => 'blocked',
                default => 'incomplete',
            },
            'detail' => (string)($dateAlignment['message'] ?? ''),
        ];

        $quality = is_array($otaRepository['data_quality'] ?? null)
            ? $otaRepository['data_quality']
            : [];
        $suppressedRepresentationRows = 0;
        foreach ([
            'suppressed_mixed_type_rows',
            'superseded_period_rows',
            'superseded_snapshot_rows',
        ] as $key) {
            $suppressedRepresentationRows += max(0, (int)($quality[$key] ?? 0));
        }
        $canonicalizedTrafficProjectionGroups = 0;
        $revenueRepresentationConflicts = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $operationalQuality = is_array(
                $ota[$platform]['source']['operational_data_quality'] ?? null
            ) ? $ota[$platform]['source']['operational_data_quality'] : [];
            $canonicalizedTrafficProjectionGroups += max(
                0,
                (int)($operationalQuality
                    ['canonicalized_traffic_projection_groups'] ?? 0)
            );
            foreach ($this->revenueRepresentationConflicts(
                $operationalQuality['revenue_representation_conflicts'] ?? []
            ) as $conflict) {
                $conflict['platform'] = $platform;
                $revenueRepresentationConflicts[] = $conflict;
            }
        }
        $duplicatesCanonicalized = $suppressedRepresentationRows > 0
            || $canonicalizedTrafficProjectionGroups > 0;
        $checks[] = [
            'key' => 'duplicate_orders',
            'label' => '重复订单/重复汇总',
            'status' => $duplicatesCanonicalized
                ? 'canonicalized'
                : 'not_checkable',
            'detail' => $duplicatesCanonicalized
                ? "事实层已排除 {$suppressedRepresentationRows} 条被替代的周期、快照或汇总表示，并按指标择优处理 {$canonicalizedTrafficProjectionGroups} 组同业务日累计漏斗投影；这不等同订单级重复核验。"
                : '当前没有订单级唯一标识覆盖证明，不能宣称不存在重复订单；汇总与订单明细也不会重复相加。',
            'suppressed_representation_rows' => $suppressedRepresentationRows,
            'canonicalized_traffic_projection_groups' =>
                $canonicalizedTrafficProjectionGroups,
        ];

        $summaryMismatches = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $facts = is_array($ota[$platform]['facts'] ?? null)
                ? $ota[$platform]['facts']
                : [];
            $crosscheck = is_array(
                $ota[$platform]['operational_crosscheck'] ?? null
            )
                ? $ota[$platform]['operational_crosscheck']
                : [];
            foreach ([
                'orders' => 0.01,
                'room_nights' => 0.01,
                'adr' => 0.02,
            ] as $metric => $tolerance) {
                $primary = $this->number($facts[$metric] ?? null);
                $secondary = $this->number($crosscheck[$metric] ?? null);
                if ($primary === null || $secondary === null) {
                    continue;
                }
                if (abs($primary - $secondary) > $tolerance) {
                    $summaryMismatches[] = [
                        'platform' => $platform,
                        'metric' => $metric,
                        'trusted_repository_value' => $primary,
                        'standard_metric_value' => $secondary,
                    ];
                }
            }
        }
        foreach ($revenueRepresentationConflicts as $conflict) {
            $summaryMismatches[] = [
                'platform' => (string)($conflict['platform'] ?? 'meituan'),
                'metric' => 'revenue_representation',
                'selected_value' => $conflict['winner_amount'] ?? null,
                'candidate_value' => $conflict['candidate_amount'] ?? null,
                'delta' => $conflict['amount_delta'] ?? null,
                'delta_percent_of_selected' =>
                    $conflict['amount_delta_percent_of_winner'] ?? null,
                'selected_row_id' => $conflict['winner_row_id'] ?? null,
                'candidate_row_id' => $conflict['candidate_row_id'] ?? null,
                'selected_data_type' =>
                    $conflict['winner_data_type'] ?? null,
                'candidate_data_type' =>
                    $conflict['candidate_data_type'] ?? null,
            ];
        }
        $checks[] = [
            'key' => 'summary_representation',
            'label' => '汇总与订单表示',
            'status' => $summaryMismatches === []
                ? 'matched_or_not_calculable'
                : 'mismatch',
            'detail' => $summaryMismatches === []
                ? '已验证的严格收益汇总与标准指标未发现数值冲突；缺字段仍保持不可核验。'
                : '严格收益汇总与标准指标存在差异，已阻止静默覆盖，请核对汇总行、订单行和最新快照。',
            'differences' => $summaryMismatches,
        ];

        $platformCancellation = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $rate = $this->number(
                $ota[$platform]['facts']['cancellation_rate_percent'] ?? null
            );
            if ($rate !== null) {
                $platformCancellation[$platform] = $rate;
            }
        }
        $combinedCancellationStatus = (string)(
            $derivedMetrics['ota_cancellation_rate_percent']['status'] ?? ''
        );
        $cancellationReady = $combinedCancellationStatus === 'ready';
        $checks[] = [
            'key' => 'cancellation',
            'label' => '取消订单',
            'status' => $cancellationReady
                ? 'ota_only_ready'
                : 'incomplete',
            'detail' => $cancellationReady
                ? 'OTA取消率按各平台有效订单数加权；真实零订单平台不增加权重。PMS取消/未入住口径尚未采集，不做跨源相减。'
                : '目标日取消字段未完成可信回读；不以0表示无取消。',
            'platform_rates_percent' => $platformCancellation,
            'combined_rate_percent' => $derivedMetrics[
                'ota_cancellation_rate_percent'
            ]['value'] ?? null,
        ];

        $paymentCollected = $this->number(
            $pms['facts']['payment_collected_amount'] ?? null
        );
        $checks[] = [
            'key' => 'payment_caliber',
            'label' => '支付与收入口径',
            'status' => $paymentCollected === null
                ? 'not_comparable'
                : 'available',
            'detail' => $paymentCollected === null
                ? 'PMS当前只验证住宿房费，尚未取得支付通道实收；OTA房费/成交额不能替代实收。'
                : 'PMS实收已取得，但仍需按支付、核销、退款和结算日期分别核对。',
        ];

        $floorReady = ($pricingGuard['data_status'] ?? '') === 'ready';
        $combinedAdr = $this->number(
            $combinedOta['facts']['adr'] ?? null
        );
        $checks[] = [
            'key' => 'floor_vs_sales',
            'label' => '底价与销售收入',
            'status' => $floorReady && $combinedAdr !== null
                ? 'reference_only'
                : 'incomplete',
            'detail' => $floorReady && $combinedAdr !== null
                ? '已显示综合OTA ADR与全店最低保护价的粗粒度差值；房型、价格计划、早餐和取消政策未对齐前不能判定低于底价。'
                : '最低保护价或OTA ADR缺失，当前不可比较；不以历史均价、竞品价或平台起价补位。',
            'minimum_floor_price' => $floorReady
                ? $this->number($pricingGuard['minimum_floor_price'] ?? null)
                : null,
            'combined_ota_adr' => $combinedAdr,
            'reference_gap' => $derivedMetrics[
                'ota_adr_minus_minimum_floor_price'
            ]['value'] ?? null,
        ];

        $status = 'partial';
        if ($dateStatus === 'blocked_date_mismatch') {
            $status = 'blocked';
        } elseif ($summaryMismatches !== []) {
            $status = 'review_needed';
        } elseif ($dateStatus === 'aligned'
            && $paymentCollected !== null
            && $cancellationReady
        ) {
            $status = 'matched_with_scope_caveats';
        }

        return [
            'status' => $status,
            'comparison_allowed' => $dateStatus === 'aligned',
            'business_date' => (string)(
                $dateAlignment['target_business_date'] ?? ''
            ),
            'checks' => $checks,
            'hard_blockers' => $dateStatus === 'blocked_date_mismatch'
                ? ['business_date_mismatch']
                : [],
            'scope_note' => 'PMS全酒店住宿事实与OTA渠道事实只做同店同日分层对照，绝不相加为酒店总收入。',
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
        $result = [
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
            'date_alignment' => [
                'status' => 'blocked_scope',
                'comparison_allowed' => false,
                'target_business_date' => $businessDate,
                'timezone' => 'Asia/Shanghai',
                'sources' => [],
                'mismatches' => [],
                'missing_sources' => [
                    'dingdandao_pms',
                    'ctrip_ota',
                    'meituan_ota',
                ],
                'message' => '酒店身份范围未验证，不能读取或对账经营事实。',
            ],
            'source_completeness' => [
                'dingdandao_pms' => 'blocked',
                'ctrip_ota' => 'blocked',
                'meituan_ota' => 'blocked',
            ],
            'all_three_sources_readback_verified' => false,
            'all_ota_analysis_gates_allowed' => false,
            'sources' => [],
            'facts' => [],
            'derived_metrics' => [],
            'reconciliation' => [
                'status' => 'blocked',
                'comparison_allowed' => false,
                'business_date' => $businessDate,
                'checks' => [[
                    'key' => 'hotel_scope',
                    'label' => '酒店身份',
                    'status' => 'blocked',
                    'detail' => '酒店身份范围未验证，禁止跨酒店或无授权对账。',
                ]],
                'hard_blockers' => ['system_hotel_scope_unavailable'],
                'scope_note' => 'PMS与OTA事实必须属于同一已授权酒店。',
            ],
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

    /** @return array<int,array<string,mixed>> */
    private function revenueRepresentationConflicts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $conflicts = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $winnerAmount = $this->number($entry['winner_amount'] ?? null);
            $candidateAmount = $this->number(
                $entry['candidate_amount'] ?? null
            );
            if ($winnerAmount === null
                || $candidateAmount === null
                || abs($candidateAmount - $winnerAmount) <= 0.01
            ) {
                continue;
            }
            $businessDate = $this->text($entry['business_date'] ?? null, 10);
            if ($businessDate !== null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $businessDate) !== 1
            ) {
                $businessDate = null;
            }
            $delta = round($candidateAmount - $winnerAmount, 2);
            $safe = [
                'system_hotel_id' => $this->positiveInt(
                    $entry['system_hotel_id'] ?? null
                ),
                'business_date' => $businessDate,
                'winner_row_id' => $this->positiveInt(
                    $entry['winner_row_id'] ?? null
                ),
                'winner_data_type' => $this->text(
                    $entry['winner_data_type'] ?? null,
                    32
                ),
                'winner_amount' => round($winnerAmount, 2),
                'winner_room_nights' => $this->number(
                    $entry['winner_room_nights'] ?? null
                ),
                'winner_order_count' => $this->number(
                    $entry['winner_order_count'] ?? null
                ),
                'candidate_row_id' => $this->positiveInt(
                    $entry['candidate_row_id'] ?? null
                ),
                'candidate_data_type' => $this->text(
                    $entry['candidate_data_type'] ?? null,
                    32
                ),
                'candidate_amount' => round($candidateAmount, 2),
                'candidate_room_nights' => $this->number(
                    $entry['candidate_room_nights'] ?? null
                ),
                'candidate_order_count' => $this->number(
                    $entry['candidate_order_count'] ?? null
                ),
                'amount_delta' => $delta,
                'amount_delta_percent_of_winner' => $winnerAmount > 0
                    ? round(abs($delta) / $winnerAmount * 100, 2)
                    : null,
            ];
            $key = implode('|', [
                (string)($safe['system_hotel_id'] ?? ''),
                (string)($safe['business_date'] ?? ''),
                (string)($safe['winner_row_id'] ?? ''),
                (string)($safe['candidate_row_id'] ?? ''),
                (string)$safe['winner_amount'],
                (string)$safe['candidate_amount'],
            ]);
            $conflicts[$key] = $safe;
        }
        return array_values($conflicts);
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
