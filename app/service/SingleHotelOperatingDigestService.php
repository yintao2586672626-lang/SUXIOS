<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use think\facade\Db;

/**
 * Exact-date, single-hotel digest used by the Molanxin operating-target report.
 *
 * PMS accommodation facts and OTA-channel facts remain separate. A captured
 * zero is retained only when its field fact is present; unknown values stay
 * null and are surfaced as explicit gaps.
 */
final class SingleHotelOperatingDigestService
{
    public const CONTRACT_VERSION = 'suxios.single_hotel_digest.v1';

    /** @var callable|null */
    private $hotelLoader;

    /** @var callable|null */
    private $pmsLoader;

    /** @var callable|null */
    private $trustedOtaLoader;

    /** @var callable|null */
    private $meituanLoader;

    /** @var callable|null */
    private $ctripTrafficLoader;

    /** @param array<string,mixed>|null $scope */
    public function __construct(
        ?callable $hotelLoader = null,
        ?callable $pmsLoader = null,
        ?callable $trustedOtaLoader = null,
        ?callable $meituanLoader = null,
        private readonly ?array $scope = null,
        ?callable $ctripTrafficLoader = null
    ) {
        $this->hotelLoader = $hotelLoader;
        $this->pmsLoader = $pmsLoader;
        $this->trustedOtaLoader = $trustedOtaLoader;
        $this->meituanLoader = $meituanLoader;
        $this->ctripTrafficLoader = $ctripTrafficLoader;
    }

    public function appliesTo(int $tenantId, int $hotelId): bool
    {
        $scope = $this->resolvedScope();

        return $tenantId === (int)($scope['tenant_id'] ?? 0)
            && $hotelId === (int)($scope['hotel_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $operatingTargetPreview
     * @return array<string,mixed>
     */
    public function build(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $operatingTargetPreview
    ): array {
        $scope = $this->resolvedScope();
        if (!$this->appliesTo($tenantId, $hotelId)) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'applies' => false,
                'delivery_allowed' => false,
                'status' => 'out_of_scope',
                'blockers' => [['code' => 'single_hotel_digest_scope_mismatch']],
            ];
        }
        if (!$this->isDate($businessDate)) {
            throw new \InvalidArgumentException('single_hotel_digest_date_invalid');
        }

        $hotel = $this->hotelLoader === null
            ? Db::name('hotels')
                ->field('id,tenant_id,name,status')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->find()
            : call_user_func($this->hotelLoader, $tenantId, $hotelId);
        $hotel = is_array($hotel) ? $hotel : [];
        $hotelMatched = (int)($hotel['id'] ?? 0) === $hotelId
            && (int)($hotel['tenant_id'] ?? 0) === $tenantId
            && (int)($hotel['status'] ?? 0) === 1
            && hash_equals(
                (string)($scope['hotel_name'] ?? ''),
                trim((string)($hotel['name'] ?? ''))
            );

        $targetFacts = is_array($operatingTargetPreview['facts'] ?? null)
            ? $operatingTargetPreview['facts']
            : [];
        $targetRevenue = $this->number($targetFacts['target_revenue'] ?? null);
        $targetPreviewPresent = strtolower(trim((string)(
            $operatingTargetPreview['status'] ?? 'missing'
        ))) !== 'missing'
            && $targetRevenue !== null
            && $targetRevenue > 0;
        $targetMatched = !$targetPreviewPresent
            || (
                (int)($operatingTargetPreview['hotel_id'] ?? 0) === $hotelId
                && (string)($operatingTargetPreview['target_date'] ?? '') === $businessDate
            );

        $pmsRaw = $this->pmsLoader === null
            ? (new DingdandaoOperatingTargetCaptureService())->latest($tenantId, $hotelId, $businessDate)
            : call_user_func($this->pmsLoader, $tenantId, $hotelId, $businessDate);
        $pms = $this->normalizePms(is_array($pmsRaw) ? $pmsRaw : [], $tenantId, $hotelId, $businessDate);

        $trustedRaw = $this->trustedOtaLoader === null
            ? (new TrustedOtaFactRepository())->pricingHistory($hotelId, $businessDate, $businessDate)
            : call_user_func($this->trustedOtaLoader, $hotelId, $businessDate);
        $ctripTrafficRaw = $this->ctripTrafficLoader === null
            ? $this->loadCtripTrafficFacts($tenantId, $hotelId, $businessDate, $scope)
            : call_user_func(
                $this->ctripTrafficLoader,
                $tenantId,
                $hotelId,
                $businessDate,
                $scope
            );
        $ctrip = $this->normalizeCtrip(
            is_array($trustedRaw) ? $trustedRaw : [],
            is_array($ctripTrafficRaw) ? $ctripTrafficRaw : [],
            $businessDate,
            trim((string)($scope['platforms']['ctrip']['platform_hotel_id'] ?? ''))
        );

        $meituanRaw = $this->meituanLoader === null
            ? $this->loadMeituanTrafficFacts($tenantId, $hotelId, $businessDate, $scope)
            : call_user_func($this->meituanLoader, $tenantId, $hotelId, $businessDate, $scope);
        $meituan = $this->normalizeMeituan(
            is_array($meituanRaw) ? $meituanRaw : [],
            $businessDate
        );

        $blockers = [];
        if (!$hotelMatched) {
            $blockers[] = $this->blocker(
                'single_hotel_identity_mismatch',
                '宿析OS酒店身份与敦煌漠蓝新专用配置不一致。'
            );
        }
        if ($targetPreviewPresent && !$targetMatched) {
            $blockers[] = $this->blocker(
                'operating_target_scope_mismatch',
                '经营目标与综合日报的酒店或日期不一致。'
            );
        }
        foreach ([
            'pms' => $pms,
            'ctrip' => $ctrip,
            'meituan' => $meituan,
        ] as $sourceKey => $source) {
            if (($source['delivery_evidence_ready'] ?? false) === true) {
                continue;
            }
            $blockers[] = $this->blocker(
                $sourceKey . '_delivery_evidence_missing',
                (string)($source['blocking_reason'] ?? ($sourceKey . '来源证据未通过。'))
            );
        }

        $gaps = array_merge(
            $targetPreviewPresent ? [] : [[
                'code' => 'operating_target_not_set',
                'message' => '经营目标未设置，不阻断PMS、携程和美团三源经营简报预览。',
            ]],
            (array)($pms['gaps'] ?? []),
            (array)($ctrip['gaps'] ?? []),
            (array)($meituan['gaps'] ?? [])
        );
        $deliveryAllowed = $blockers === [];
        $status = !$deliveryAllowed
            ? 'blocked'
            : ($gaps === [] ? 'ready' : 'partial');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'applies' => true,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'hotel_name' => (string)($scope['hotel_name'] ?? ''),
            'business_date' => $businessDate,
            'status' => $status,
            'delivery_allowed' => $deliveryAllowed,
            'operating_target_status' => $targetPreviewPresent ? 'present' : 'not_set',
            'scope_boundary' => [
                'pms' => '订单来了住宿数据中心房费口径，不代表全酒店全部收入。',
                'ctrip' => '携程渠道自店流量、转化与成交口径，不与PMS住宿收入相加。',
                'meituan' => '美团渠道自店流量与支付订单口径；未验证字段保持缺失。',
            ],
            'sources' => [
                'pms' => $pms,
                'ctrip' => $ctrip,
                'meituan' => $meituan,
            ],
            'gaps' => array_values($gaps),
            'blockers' => $blockers,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $capture @return array<string,mixed> */
    private function normalizePms(
        array $capture,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
        $ready = (int)($capture['tenant_id'] ?? 0) === $tenantId
            && (int)($capture['hotel_id'] ?? 0) === $hotelId
            && (string)($capture['business_date'] ?? '') === $businessDate
            && strtolower(trim((string)($capture['identity_status'] ?? ''))) === 'matched'
            && strtolower(trim((string)($capture['capture_status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($capture['quality_status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($capture['reconciliation_status'] ?? ''))) === 'matched'
            && strtolower(trim((string)($capture['readback_status'] ?? ''))) === 'readback_verified'
            && $this->number($summary['total_room_fee'] ?? null) !== null
            && $this->number($summary['adr'] ?? null) !== null
            && $this->number($summary['occupancy_rate_percent'] ?? null) !== null
            && $this->number($summary['revpar'] ?? null) !== null
            && $this->number($summary['sold_room_nights'] ?? null) !== null
            && $this->number($summary['average_daily_room_nights'] ?? null) !== null
            && $this->number($summary['derived_sellable_room_nights'] ?? null) !== null;

        return [
            'source' => 'dingdandao',
            'source_label' => '订单来了PMS',
            'metric_scope' => 'accommodation_room_fee',
            'status' => $ready ? 'ready' : 'blocked',
            'delivery_evidence_ready' => $ready,
            'identity_status' => (string)($capture['identity_status'] ?? 'unverified'),
            'readback_verified' => (string)($capture['readback_status'] ?? '') === 'readback_verified',
            'reconciliation_status' => (string)($capture['reconciliation_status'] ?? 'unverified'),
            'capture_id' => (int)($capture['id'] ?? 0),
            'collected_at' => $this->dateTimeOrNull($capture['captured_at'] ?? null),
            'facts' => [
                'room_fee_revenue' => $this->number($summary['total_room_fee'] ?? null),
                'adr' => $this->number($summary['adr'] ?? null),
                'occupancy_rate_percent' => $this->number($summary['occupancy_rate_percent'] ?? null),
                'revpar' => $this->number($summary['revpar'] ?? null),
                'sold_room_nights' => $this->number($summary['sold_room_nights'] ?? null),
                'average_daily_room_nights' => $this->number(
                    $summary['average_daily_room_nights'] ?? null
                ),
                'sellable_room_nights' => $this->number($summary['derived_sellable_room_nights'] ?? null),
                'detail_room_fee_total' => $this->number($capture['detail_room_fee_total'] ?? null),
                'detail_row_count' => (int)($capture['detail_row_count'] ?? 0),
            ],
            'gaps' => $ready ? [] : [[
                'code' => 'pms_capture_not_verified',
                'message' => '订单来了PMS的门店、日期、汇总明细对账或数据库回读未全部通过。',
            ]],
            'blocking_reason' => '订单来了PMS证据未通过完整门禁。',
        ];
    }

    /** @param array<string,mixed> $trusted @return array<string,mixed> */
    private function normalizeCtrip(
        array $trusted,
        array $trafficSource,
        string $businessDate,
        string $expectedPlatformHotelId
    ): array {
        $policy = is_array($trusted['source_policy'] ?? null)
            ? $trusted['source_policy']
            : [];
        $policyVerified = ($policy['hotel_scope'] ?? '') === 'system_hotel_id_strict_exact_only'
            && ($policy['readback_policy'] ?? '') === 'readback_verified_required_equals_1'
            && ($policy['platform_hotel_identity_policy'] ?? '')
                === 'platform_data_source_config_exact_required'
            && ($policy['metric_scope'] ?? '') === 'ota_channel';
        $rows = [];
        $identityMismatchObserved = false;
        foreach ($policyVerified ? (array)($trusted['rows'] ?? []) : [] as $row) {
            if (!is_array($row)
                || (string)($row['data_date'] ?? '') !== $businessDate
                || strtolower(trim((string)($row['source'] ?? ''))) !== 'ctrip'
            ) {
                continue;
            }
            $rowPlatformHotelId = trim((string)($row['platform_hotel_id'] ?? ''));
            if ($expectedPlatformHotelId === ''
                || $rowPlatformHotelId === ''
                || !hash_equals($expectedPlatformHotelId, $rowPlatformHotelId)
                || (int)($row['data_source_id'] ?? 0) <= 0
            ) {
                $identityMismatchObserved = true;
                continue;
            }
            $rows[] = $row;
        }
        $revenue = $this->sumMetric($rows, 'amount');
        $orders = $this->sumMetric($rows, 'book_order_num');
        $roomNights = $this->sumMetric($rows, 'quantity');
        $revenueIdentityVerified = $expectedPlatformHotelId !== ''
            && !$identityMismatchObserved
            && $rows !== [];
        $revenueReady = $revenueIdentityVerified
            && $revenue !== null
            && $orders !== null
            && $roomNights !== null;

        $trafficFacts = is_array($trafficSource['facts'] ?? null)
            ? $trafficSource['facts']
            : [];
        $listExposure = $this->number($trafficFacts['list_exposure'] ?? null);
        $detailExposure = $this->number($trafficFacts['detail_exposure'] ?? null);
        $orderFilling = $this->number(
            $trafficFacts['order_filling_visitors']
                ?? $trafficFacts['order_filling_num']
                ?? null
        );
        $orderSubmit = $this->number(
            $trafficFacts['order_submit_users']
                ?? $trafficFacts['order_submit_num']
                ?? null
        );
        $reportedFlowRate = $this->number(
            $trafficFacts['platform_reported_rate_percent']
                ?? $trafficFacts['flow_rate_percent']
                ?? null
        );
        $trafficIdentityVerified = (string)($trafficSource['business_date'] ?? '')
                === $businessDate
            && ($trafficSource['identity_matched'] ?? false) === true
            && ($trafficSource['readback_verified'] ?? false) === true
            && ($trafficSource['field_facts_verified'] ?? false) === true;
        $trafficRequiredFactsPresent = $listExposure !== null
            && $detailExposure !== null
            && $orderFilling !== null
            && $orderSubmit !== null;
        $trafficReady = $trafficIdentityVerified && $trafficRequiredFactsPresent;
        $conversionRates = [
            'list_to_detail' => $this->conversionRate(
                'detail_exposure / list_exposure * 100',
                'detail_exposure',
                $detailExposure,
                'list_exposure',
                $listExposure
            ),
            'detail_to_order_filling' => $this->conversionRate(
                'order_filling_visitors / detail_exposure * 100',
                'order_filling_visitors',
                $orderFilling,
                'detail_exposure',
                $detailExposure
            ),
            'order_filling_to_submit' => $this->conversionRate(
                'order_submit_users / order_filling_visitors * 100',
                'order_submit_users',
                $orderSubmit,
                'order_filling_visitors',
                $orderFilling
            ),
            'detail_to_submit' => $this->conversionRate(
                'order_submit_users / detail_exposure * 100',
                'order_submit_users',
                $orderSubmit,
                'detail_exposure',
                $detailExposure
            ),
        ];
        $rateGaps = $trafficReady
            ? $this->conversionRateGaps('ctrip', $conversionRates)
            : [];
        $ready = $revenueReady && $trafficReady;
        $gaps = [];
        if (!$revenueReady) {
            $gaps[] = [
                'code' => !$policyVerified
                    ? 'ctrip_trusted_policy_unverified'
                    : ($identityMismatchObserved
                        ? 'ctrip_platform_hotel_identity_mismatch'
                        : ($rows === []
                            ? 'ctrip_exact_date_fact_missing'
                            : 'ctrip_required_metric_missing')),
                'message' => !$policyVerified
                    ? '携程可信仓库的酒店、回读或渠道口径策略未通过。'
                    : '携程同店同日可信收入、订单或间夜事实不完整。',
            ];
        }
        if (!$trafficReady) {
            $gaps[] = [
                'code' => 'ctrip_traffic_funnel_unverified',
                'message' => '携程同店同日列表曝光、详情访问、填单和提交订单字段未完成证据回读。',
            ];
        }
        $gaps = array_merge($gaps, $rateGaps);
        $identityVerified = $revenueIdentityVerified
            && ($trafficSource['identity_matched'] ?? false) === true;

        return [
            'source' => 'ctrip',
            'source_label' => '携程',
            'metric_scope' => 'ota_channel',
            'status' => !$ready
                ? ($rows === [] ? 'missing' : 'blocked')
                : ($gaps === [] ? 'ready' : 'partial'),
            'delivery_evidence_ready' => $ready,
            'identity_status' => $identityMismatchObserved
                ? 'mismatched'
                : ($identityVerified ? 'matched' : 'unverified'),
            'platform_hotel_id' => $identityVerified
                ? $expectedPlatformHotelId
                : null,
            'readback_verified' => $rows !== []
                && ($trafficSource['readback_verified'] ?? false) === true,
            'trusted_row_count' => count($rows),
            'traffic_row_id' => (int)($trafficSource['row_id'] ?? 0),
            'collected_at' => $this->latestDateTime([
                ...$rows,
                ['collected_at' => $trafficSource['collected_at'] ?? null],
            ], 'collected_at'),
            'facts' => [
                'channel_revenue' => $revenue,
                'orders' => $orders,
                'room_nights' => $roomNights,
                'list_exposure' => $listExposure,
                'detail_exposure' => $detailExposure,
                'order_filling_visitors' => $orderFilling,
                'order_submit_users' => $orderSubmit,
                'platform_reported_rate_percent' => $reportedFlowRate,
                'list_to_detail_rate_percent' => $conversionRates['list_to_detail']['value_percent'],
                'detail_to_order_filling_rate_percent' => $conversionRates['detail_to_order_filling']['value_percent'],
                'order_filling_to_submit_rate_percent' => $conversionRates['order_filling_to_submit']['value_percent'],
                'detail_to_submit_rate_percent' => $conversionRates['detail_to_submit']['value_percent'],
            ],
            'conversion_rates' => $conversionRates,
            'gaps' => $gaps,
            'blocking_reason' => '携程同店同日成交与流量漏斗事实未完成数据库回读。',
        ];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function normalizeMeituan(array $source, string $businessDate): array
    {
        $dateMatched = (string)($source['business_date'] ?? '') === $businessDate;
        $baseEvidenceReady = $dateMatched
            && ($source['identity_matched'] ?? false) === true
            && ($source['readback_verified'] ?? false) === true
            && ($source['field_facts_verified'] ?? false) === true;
        $facts = is_array($source['facts'] ?? null) ? $source['facts'] : [];
        $listExposure = $this->number($facts['list_exposure'] ?? null);
        $detailExposure = $this->number($facts['detail_exposure'] ?? null);
        $orderFilling = $this->number(
            $facts['order_filling_visitors']
                ?? $facts['order_filling_num']
                ?? null
        );
        $paidOrders = $this->number($facts['paid_orders'] ?? null);
        $reportedFlowRate = $this->number(
            $facts['platform_reported_rate_percent']
                ?? $facts['flow_rate_percent']
                ?? null
        );
        $platformDetailToPaidRate = $this->number(
            $facts['platform_detail_to_paid_rate_percent'] ?? null
        );
        $requiredTrafficFactsPresent = $listExposure !== null
            && $detailExposure !== null
            && $paidOrders !== null;
        $evidenceReady = $baseEvidenceReady && $requiredTrafficFactsPresent;
        $conversionRates = [
            'list_to_detail' => $this->conversionRate(
                'detail_exposure / list_exposure * 100',
                'detail_exposure',
                $detailExposure,
                'list_exposure',
                $listExposure
            ),
            'detail_to_paid_order' => $this->conversionRate(
                'paid_orders / detail_exposure * 100',
                'paid_orders',
                $paidOrders,
                'detail_exposure',
                $detailExposure
            ),
            'detail_to_order_filling' => $this->conversionRate(
                'order_filling_visitors / detail_exposure * 100',
                'order_filling_visitors',
                $orderFilling,
                'detail_exposure',
                $detailExposure
            ),
            'order_filling_to_paid_order' => $this->conversionRate(
                'paid_orders / order_filling_visitors * 100',
                'paid_orders',
                $paidOrders,
                'order_filling_visitors',
                $orderFilling
            ),
            'platform_detail_to_paid_order' => $this->reportedConversionRate(
                'payOrderPerIntention',
                $platformDetailToPaidRate
            ),
        ];
        $gaps = [];
        if ($evidenceReady) {
            if ($orderFilling === null) {
                $gaps[] = [
                    'code' => 'meituan_order_filling_missing',
                    'message' => '美团当前页面未返回独立填单人数，不使用支付订单数冒充。',
                ];
            }
            if ($platformDetailToPaidRate === null) {
                $gaps[] = [
                    'code' => 'meituan_platform_detail_to_paid_rate_missing',
                    'message' => '美团平台详情访问→支付转化率未返回，保留自算校验但不冒充平台口径。',
                ];
            }
            $gaps = array_merge(
                $gaps,
                $this->conversionRateGaps('meituan', [
                    'list_to_detail' => $conversionRates['list_to_detail'],
                    'detail_to_paid_order' => $conversionRates['detail_to_paid_order'],
                ])
            );
            $gaps[] = [
                'code' => 'meituan_room_revenue_missing',
                'message' => '美团当前已验证来源未返回房费收入，保持缺失。',
            ];
            $gaps[] = [
                'code' => 'meituan_room_nights_missing',
                'message' => '美团当前已验证来源未返回售出间夜，保持缺失。',
            ];
        } else {
            $gaps[] = [
                'code' => 'meituan_exact_date_fact_unverified',
                'message' => '美团门店身份、日期、字段事实或数据库回读未全部通过。',
            ];
        }

        return [
            'source' => 'meituan',
            'source_label' => '美团',
            'metric_scope' => 'ota_channel',
            'status' => $evidenceReady ? 'partial' : 'blocked',
            'delivery_evidence_ready' => $evidenceReady,
            'identity_status' => ($source['identity_matched'] ?? false) === true ? 'matched' : 'unverified',
            'readback_verified' => ($source['readback_verified'] ?? false) === true,
            'row_id' => (int)($source['row_id'] ?? 0),
            'collected_at' => $this->dateTimeOrNull($source['collected_at'] ?? null),
            'facts' => [
                'list_exposure' => $listExposure,
                'detail_exposure' => $detailExposure,
                'order_filling_visitors' => $orderFilling,
                'flow_rate_percent' => $reportedFlowRate,
                'platform_reported_rate_percent' => $reportedFlowRate,
                'platform_detail_to_paid_rate_percent' => $platformDetailToPaidRate,
                'paid_orders' => $paidOrders,
                'target_date_order_count' => $this->number($facts['target_date_order_count'] ?? null),
                'list_to_detail_rate_percent' => $conversionRates['list_to_detail']['value_percent'],
                'detail_to_paid_order_rate_percent' => $conversionRates['detail_to_paid_order']['value_percent'],
                'detail_to_order_filling_rate_percent' => $conversionRates['detail_to_order_filling']['value_percent'],
                'order_filling_to_paid_order_rate_percent' => $conversionRates['order_filling_to_paid_order']['value_percent'],
                'channel_revenue' => null,
                'room_nights' => null,
            ],
            'conversion_rates' => $conversionRates,
            'gaps' => $gaps,
            'blocking_reason' => '美团同店同日流量与支付订单事实未通过完整证据门禁。',
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function loadCtripTrafficFacts(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $scope
    ): array {
        $expectedPlatformHotelId = trim((string)(
            $scope['platforms']['ctrip']['platform_hotel_id'] ?? ''
        ));
        if ($expectedPlatformHotelId === '') {
            return ['business_date' => $businessDate];
        }

        $row = Db::name('online_daily_data')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('source', 'ctrip')
            ->where('hotel_id', $expectedPlatformHotelId)
            ->where('data_date', $businessDate)
            ->where('data_type', 'traffic')
            ->whereNotNull('data_source_id')
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('readback_verified', 1)
            ->order('is_final', 'desc')
            ->order('snapshot_time', 'desc')
            ->order('id', 'desc')
            ->field(
                'id,data_source_id,system_hotel_id,hotel_id,data_date,dimension,compare_type,'
                . 'data_period,list_exposure,detail_exposure,flow_rate,order_filling_num,'
                . 'order_submit_num,validation_status,ingestion_method,source_trace_id,'
                . 'snapshot_time,update_time,create_time,raw_data,readback_verified,is_final'
            )
            ->find();
        if (!is_array($row)) {
            return ['business_date' => $businessDate];
        }

        $source = Db::name('platform_data_sources')
            ->where('id', (int)($row['data_source_id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', 'ctrip')
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->field(
                'id,tenant_id,system_hotel_id,platform,ingestion_method,status,config_json'
            )
            ->find();
        $config = is_array($source)
            ? json_decode((string)($source['config_json'] ?? ''), true)
            : null;
        $config = is_array($config) ? $config : [];
        $actualPlatformHotelId = $this->firstText($config, [
            'platform_hotel_id',
            'hotel_id',
            'external_hotel_id',
            'master_hotel_id',
        ]);
        $profileKey = $this->firstText($config, [
            'profile_binding_key',
            'stable_profile_id',
            'profile_id',
        ]);
        $bindingActive = false;
        if ($profileKey !== '') {
            try {
                (new OtaProfileBindingService())->assertBound($hotelId, 'ctrip', $profileKey);
                $bindingActive = true;
            } catch (\Throwable) {
                $bindingActive = false;
            }
        }

        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        $rawTraceId = trim((string)($raw['source_trace_id'] ?? ''));
        $identifierProof = strtolower(trim((string)(
            $raw['platform_hotel_identifier_proof'] ?? ''
        )));
        $identityMatched = is_array($source)
            && hash_equals($expectedPlatformHotelId, trim((string)($row['hotel_id'] ?? '')))
            && hash_equals($expectedPlatformHotelId, $actualPlatformHotelId)
            && $bindingActive
            && ($raw['platform_hotel_identifier_present'] ?? null) === true
            && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
            && $identifierProof !== ''
            && !in_array($identifierProof, ['missing', 'unverified'], true)
            && $traceId !== ''
            && $rawTraceId !== ''
            && hash_equals($traceId, $rawTraceId);
        $listExposureVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['list_exposure'],
            'list_exposure'
        );
        $detailExposureVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['detail_exposure', 'detail_visitor'],
            'detail_exposure'
        );
        $orderFillingVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['order_filling_num', 'order_page_visitor'],
            'order_filling_num'
        );
        $orderSubmitVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['order_submit_num', 'order_submit_user'],
            'order_submit_num'
        );
        $reportedFlowRateVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['flow_rate'],
            'flow_rate'
        );
        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        $ingestionMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));

        return [
            'business_date' => (string)($row['data_date'] ?? ''),
            'row_id' => (int)($row['id'] ?? 0),
            'identity_matched' => $identityMatched,
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1
                && in_array($validationStatus, ['normal', 'verified', 'available'], true)
                && in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true),
            'field_facts_verified' => $listExposureVerified
                && $detailExposureVerified
                && $orderFillingVerified
                && $orderSubmitVerified,
            'collected_at' => $this->firstDateTime($row, [
                'snapshot_time',
                'update_time',
                'create_time',
            ]),
            'facts' => [
                'list_exposure' => $listExposureVerified
                    ? $this->number($row['list_exposure'] ?? null)
                    : null,
                'detail_exposure' => $detailExposureVerified
                    ? $this->number($row['detail_exposure'] ?? null)
                    : null,
                'order_filling_visitors' => $orderFillingVerified
                    ? $this->number($row['order_filling_num'] ?? null)
                    : null,
                'order_submit_users' => $orderSubmitVerified
                    ? $this->number($row['order_submit_num'] ?? null)
                    : null,
                'platform_reported_rate_percent' => $reportedFlowRateVerified
                    ? $this->number($row['flow_rate'] ?? null)
                    : null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function loadMeituanTrafficFacts(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $scope
    ): array {
        $row = Db::name('online_daily_data')
            ->where('system_hotel_id', $hotelId)
            ->where('source', 'meituan')
            ->where('data_date', $businessDate)
            ->where('data_type', 'traffic')
            ->where('dimension', 'flow_conversion')
            ->where('readback_verified', 1)
            ->order('is_final', 'desc')
            ->order('snapshot_time', 'desc')
            ->order('id', 'desc')
            ->field(
                'id,data_source_id,system_hotel_id,data_date,list_exposure,detail_exposure,'
                . 'flow_rate,order_filling_num,order_submit_num,validation_status,ingestion_method,'
                . 'source_trace_id,data_period,snapshot_time,update_time,create_time,raw_data,'
                . 'readback_verified,is_final'
            )
            ->find();
        if (!is_array($row)) {
            return ['business_date' => $businessDate];
        }
        $orderRow = Db::name('online_daily_data')
            ->where('system_hotel_id', $hotelId)
            ->where('source', 'meituan')
            ->where('data_date', $businessDate)
            ->where('data_type', 'order')
            ->where('compare_type', 'self')
            ->where('readback_verified', 1)
            ->order('is_final', 'desc')
            ->order('snapshot_time', 'desc')
            ->order('id', 'desc')
            ->field(
                'id,data_source_id,data_date,book_order_num,source_trace_id,raw_data,readback_verified'
            )
            ->find();

        $source = Db::name('platform_data_sources')
            ->where('id', (int)($row['data_source_id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', 'meituan')
            ->where('ingestion_method', 'browser_profile')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,status,config_json')
            ->find();
        $config = is_array($source)
            ? json_decode((string)($source['config_json'] ?? ''), true)
            : null;
        $config = is_array($config) ? $config : [];
        $expectedPlatformHotelId = trim((string)(
            $scope['platforms']['meituan']['platform_hotel_id'] ?? ''
        ));
        $actualPlatformHotelId = $this->firstText($config, [
            'platform_hotel_id',
            'store_id',
            'poi_id',
        ]);
        $profileKey = $this->firstText($config, [
            'profile_binding_key',
            'stable_profile_id',
            'profile_id',
        ]);
        $bindingActive = false;
        if ($profileKey !== '') {
            try {
                (new OtaProfileBindingService())->assertBound($hotelId, 'meituan', $profileKey);
                $bindingActive = true;
            } catch (\Throwable) {
                $bindingActive = false;
            }
        }

        $raw = json_decode((string)($row['raw_data'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        $rawTraceId = trim((string)($raw['source_trace_id'] ?? ''));
        $identifierProof = strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? '')));
        $identityMatched = is_array($source)
            && $expectedPlatformHotelId !== ''
            && hash_equals($expectedPlatformHotelId, $actualPlatformHotelId)
            && $bindingActive
            && ($raw['platform_hotel_identifier_present'] ?? null) === true
            && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
            && $identifierProof !== ''
            && !in_array($identifierProof, ['missing', 'unverified'], true)
            && $traceId !== ''
            && $rawTraceId !== ''
            && hash_equals($traceId, $rawTraceId);

        $listExposureVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['list_exposure', 'mt_exposure'],
            'list_exposure'
        );
        $detailExposureVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['detail_exposure', 'mt_intention_uv'],
            'detail_exposure'
        );
        $reportedFlowRateVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['flow_rate'],
            'flow_rate'
        );
        $platformDetailToPaidRateVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['meituan_detail_to_paid_rate'],
            'raw_data.row.payorderperintention'
        );
        $paidOrdersVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['mt_pay_orders'],
            'order_submit_num'
        );
        $rawRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $orderFillingPolicy = $this->firstText(
            array_merge($raw, $rawRow),
            ['_order_filling_source_policy', 'order_filling_source_policy']
        );
        $orderFillingVerified = !str_contains(
            strtolower($orderFillingPolicy),
            'pay_order_count_used'
        ) && $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['order_filling_num'],
            'order_filling_num'
        );
        $fieldFactsVerified = $listExposureVerified
            && $detailExposureVerified
            && $paidOrdersVerified;
        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        $ingestionMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        $orderRaw = is_array($orderRow)
            ? json_decode((string)($orderRow['raw_data'] ?? ''), true)
            : null;
        $orderRaw = is_array($orderRaw) ? $orderRaw : [];
        $orderTraceId = trim((string)($orderRow['source_trace_id'] ?? ''));
        $orderCountVerified = is_array($orderRow)
            && (int)($orderRow['data_source_id'] ?? 0) === (int)($row['data_source_id'] ?? 0)
            && (string)($orderRow['data_date'] ?? '') === $businessDate
            && (int)($orderRow['readback_verified'] ?? 0) === 1
            && trim((string)($orderRaw['source_trace_id'] ?? '')) !== ''
            && hash_equals($orderTraceId, trim((string)($orderRaw['source_trace_id'] ?? '')))
            && $this->fieldFactCaptured(
                $orderRaw,
                $orderTraceId,
                ['order_count'],
                'book_order_num'
            );

        return [
            'business_date' => (string)($row['data_date'] ?? ''),
            'row_id' => (int)($row['id'] ?? 0),
            'identity_matched' => $identityMatched,
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1
                && in_array($validationStatus, ['normal', 'verified', 'available'], true)
                && in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true),
            'field_facts_verified' => $fieldFactsVerified,
            'collected_at' => $this->firstDateTime($row, [
                'snapshot_time',
                'update_time',
                'create_time',
            ]),
            'facts' => [
                'list_exposure' => $listExposureVerified
                    ? $this->number($row['list_exposure'] ?? null)
                    : null,
                'detail_exposure' => $detailExposureVerified
                    ? $this->number($row['detail_exposure'] ?? null)
                    : null,
                'order_filling_visitors' => $orderFillingVerified
                    ? $this->number($row['order_filling_num'] ?? null)
                    : null,
                'platform_reported_rate_percent' => $reportedFlowRateVerified
                    ? $this->number($row['flow_rate'] ?? null)
                    : null,
                'platform_detail_to_paid_rate_percent' => $platformDetailToPaidRateVerified
                    ? (
                        $this->number($rawRow['browse_pay_rate'] ?? null)
                        ?? $this->percentNumber($rawRow['payOrderPerIntention'] ?? null)
                    )
                    : null,
                'paid_orders' => $paidOrdersVerified
                    ? $this->number($row['order_submit_num'] ?? null)
                    : null,
                'target_date_order_count' => $orderCountVerified
                    ? $this->number($orderRow['book_order_num'] ?? null)
                    : null,
            ],
        ];
    }

    /** @param array<string,mixed> $raw @param array<int,string> $metricKeys */
    private function fieldFactCaptured(
        array $raw,
        string $traceId,
        array $metricKeys,
        string $field
    ): bool {
        if ($traceId === '') {
            return false;
        }
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || !in_array(strtolower(trim((string)($fact['metric_key'] ?? ''))), $metricKeys, true)
                || strtolower(trim((string)($fact['normalized_field'] ?? ''))) !== $field
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? null) !== true
            ) {
                continue;
            }
            $storageField = strtolower(trim((string)($fact['storage_field'] ?? '')));
            if (!in_array($storageField, [$field, 'online_daily_data.' . $field], true)) {
                continue;
            }
            $capture = is_array($fact['capture_evidence'] ?? null)
                ? $fact['capture_evidence']
                : [];
            $factTrace = trim((string)($capture['source_trace_id'] ?? ''));
            if ($factTrace !== '' && hash_equals($traceId, $factTrace)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function resolvedScope(): array
    {
        if (is_array($this->scope)) {
            return $this->scope;
        }
        $scope = config('single_hotel_operating_digest');

        return is_array($scope) ? $scope : [];
    }

    /**
     * @return array{
     *   status:string,
     *   value_percent:?float,
     *   formula:string,
     *   numerator_metric:string,
     *   numerator:?float,
     *   denominator_metric:string,
     *   denominator:?float
     * }
     */
    private function conversionRate(
        string $formula,
        string $numeratorMetric,
        ?float $numerator,
        string $denominatorMetric,
        ?float $denominator
    ): array {
        $status = 'available';
        $value = null;
        if ($numerator === null || $denominator === null) {
            $status = 'not_calculable_missing_input';
        } elseif ($denominator === 0.0) {
            $status = 'not_calculable_zero_denominator';
        } else {
            $value = round(($numerator / $denominator) * 100, 2);
        }

        return [
            'status' => $status,
            'value_percent' => $value,
            'formula' => $formula,
            'numerator_metric' => $numeratorMetric,
            'numerator' => $numerator,
            'denominator_metric' => $denominatorMetric,
            'denominator' => $denominator,
        ];
    }

    /**
     * @return array{
     *   status:string,
     *   value_percent:?float,
     *   formula:string,
     *   numerator_metric:string,
     *   numerator:?float,
     *   denominator_metric:string,
     *   denominator:?float
     * }
     */
    private function reportedConversionRate(string $metric, ?float $value): array
    {
        return [
            'status' => $value === null ? 'not_calculable_missing_input' : 'available',
            'value_percent' => $value,
            'formula' => 'platform_reported:' . $metric,
            'numerator_metric' => '',
            'numerator' => null,
            'denominator_metric' => '',
            'denominator' => null,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $rates
     * @return array<int,array{code:string,message:string}>
     */
    private function conversionRateGaps(string $source, array $rates): array
    {
        $label = $source === 'ctrip' ? '携程' : '美团';
        $gaps = [];
        foreach ($rates as $key => $rate) {
            $status = (string)($rate['status'] ?? 'not_calculable_missing_input');
            if ($status === 'available') {
                continue;
            }
            $reason = $status === 'not_calculable_zero_denominator'
                ? '分母为0'
                : '缺少分子或分母';
            $gaps[] = [
                'code' => $source . '_' . $key . '_rate_not_calculable',
                'message' => $label . $key . '转化率不可计算：' . $reason . '。',
            ];
        }

        return $gaps;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumMetric(array $rows, string $field): ?float
    {
        $found = false;
        $sum = 0.0;
        foreach ($rows as $row) {
            $number = $this->number($row[$field] ?? null);
            if ($number === null) {
                continue;
            }
            $found = true;
            $sum += $number;
        }

        return $found ? round($sum, 2) : null;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function latestDateTime(array $rows, string $field): ?string
    {
        $latest = null;
        foreach ($rows as $row) {
            $candidate = $this->dateTimeOrNull($row[$field] ?? null);
            if ($candidate !== null && ($latest === null || $candidate > $latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }

    /** @param array<string,mixed> $values @param array<int,string> $keys */
    private function firstText(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($values[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $values @param array<int,string> $keys */
    private function firstDateTime(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->dateTimeOrNull($values[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        if (is_bool($value) || $value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float)$value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private function percentNumber(mixed $value): ?float
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }
        $normalized = trim((string)$value);
        if (str_ends_with($normalized, '%')) {
            $normalized = trim(substr($normalized, 0, -1));
        }

        return $this->number($normalized);
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || strtotime($value) === false) {
            return null;
        }

        return date('Y-m-d H:i:s', (int)strtotime($value));
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    /** @return array{code:string,message:string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}
