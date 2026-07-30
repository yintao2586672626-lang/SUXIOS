<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
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
    private $clock;

    /** @param array<string,mixed>|null $scope */
    public function __construct(
        ?callable $hotelLoader = null,
        ?callable $pmsLoader = null,
        ?callable $trustedOtaLoader = null,
        ?callable $meituanLoader = null,
        private readonly ?array $scope = null,
        ?callable $clock = null
    ) {
        $this->hotelLoader = $hotelLoader;
        $this->pmsLoader = $pmsLoader;
        $this->trustedOtaLoader = $trustedOtaLoader;
        $this->meituanLoader = $meituanLoader;
        $this->clock = $clock;
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
                'base_delivery_allowed' => false,
                'target_delivery_allowed' => false,
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
        $targetStatus = strtolower(trim((string)($operatingTargetPreview['status'] ?? 'missing')));
        $targetPreviewPresent = $targetStatus !== 'missing'
            && $targetRevenue !== null
            && $targetRevenue > 0;
        $targetMatched = $targetPreviewPresent
            && (int)($operatingTargetPreview['hotel_id'] ?? 0) === $hotelId
            && (string)($operatingTargetPreview['target_date'] ?? '') === $businessDate;
        $targetReady = $targetPreviewPresent
            && $targetMatched
            && $targetStatus === 'ready';

        $pmsRaw = $this->pmsLoader === null
            ? (new DingdandaoOperatingTargetCaptureService())->latest($tenantId, $hotelId, $businessDate)
            : call_user_func($this->pmsLoader, $tenantId, $hotelId, $businessDate);
        $pms = $this->normalizePms(
            is_array($pmsRaw) ? $pmsRaw : [],
            $tenantId,
            $hotelId,
            $businessDate,
            $scope
        );

        $ctripReadFailed = false;
        try {
            $trustedRaw = $this->trustedOtaLoader === null
                ? (new TrustedOtaFactRepository())->pricingHistory($hotelId, $businessDate, $businessDate)
                : call_user_func($this->trustedOtaLoader, $hotelId, $businessDate);
        } catch (\Throwable) {
            $trustedRaw = [];
            $ctripReadFailed = true;
        }
        $ctrip = $this->normalizeCtrip(
            is_array($trustedRaw) ? $trustedRaw : [],
            $businessDate,
            trim((string)($scope['platforms']['ctrip']['platform_hotel_id'] ?? '')),
            $scope
        );
        if ($ctripReadFailed) {
            $ctrip['status'] = 'failed';
            $ctrip['delivery_evidence_ready'] = false;
            $ctrip['gaps'] = [[
                'code' => 'ctrip_source_read_failed',
                'message' => '携程可选渠道事实读取失败，保持未获取且不阻断PMS基础经营事实。',
            ]];
            $ctrip['blocking_reason'] = '携程可选渠道事实读取失败。';
        }

        $meituanReadFailed = false;
        try {
            $meituanRaw = $this->meituanLoader === null
                ? $this->loadMeituanTrafficFacts($tenantId, $hotelId, $businessDate, $scope)
                : call_user_func($this->meituanLoader, $tenantId, $hotelId, $businessDate, $scope);
        } catch (\Throwable) {
            $meituanRaw = [];
            $meituanReadFailed = true;
        }
        $meituan = $this->normalizeMeituan(
            is_array($meituanRaw) ? $meituanRaw : [],
            $businessDate,
            trim((string)($scope['platforms']['meituan']['platform_hotel_id'] ?? '')),
            $scope
        );
        if ($meituanReadFailed) {
            $meituan['status'] = 'failed';
            $meituan['delivery_evidence_ready'] = false;
            $meituan['gaps'] = [[
                'code' => 'meituan_source_read_failed',
                'message' => '美团可选渠道事实读取失败，保持未获取且不阻断PMS基础经营事实。',
            ]];
            $meituan['blocking_reason'] = '美团可选渠道事实读取失败。';
        }

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
        if (($pms['delivery_evidence_ready'] ?? false) !== true) {
            $blockers[] = $this->blocker(
                'pms_delivery_evidence_missing',
                (string)($pms['blocking_reason'] ?? '订单来了PMS来源证据未通过。')
            );
        }

        $optionalSourceGaps = [];
        foreach ([
            'ctrip' => ['source' => $ctrip, 'label' => '携程'],
            'meituan' => ['source' => $meituan, 'label' => '美团'],
        ] as $sourceKey => $definition) {
            $source = $definition['source'];
            if (($source['delivery_evidence_ready'] ?? false) === true) {
                continue;
            }
            $optionalSourceGaps[] = [
                'code' => $sourceKey . '_optional_source_unavailable',
                'message' => $definition['label']
                    . '同店同日渠道事实未通过；该可选渠道块标记为未获取，不阻断PMS基础经营事实推送。',
            ];
        }

        $gaps = array_merge(
            $targetPreviewPresent ? [] : [[
                'code' => 'operating_target_not_set',
                'message' => '经营目标模块未启用；目标、完成率、剩余营业额和所需均价不适用，不阻断PMS基础经营事实推送。',
            ]],
            $targetPreviewPresent && !$targetReady ? [[
                'code' => $targetMatched
                    ? 'operating_target_not_ready'
                    : 'operating_target_scope_mismatch',
                'message' => 'Operating target is not ready or does not match the current hotel and date; the target module stays disabled.',
            ]] : [],
            (array)($pms['gaps'] ?? []),
            $optionalSourceGaps,
            (array)($ctrip['gaps'] ?? []),
            (array)($meituan['gaps'] ?? [])
        );
        $baseDeliveryAllowed = $blockers === [];
        $integratedBlockers = $blockers;
        foreach ([
            'ctrip' => $ctrip,
            'meituan' => $meituan,
        ] as $sourceKey => $source) {
            if (($source['delivery_evidence_ready'] ?? false) === true) {
                continue;
            }
            $integratedBlockers[] = $this->blocker(
                $sourceKey . '_delivery_evidence_missing',
                (string)($source['blocking_reason'] ?? ($sourceKey . '来源证据未通过。'))
            );
        }
        $deliveryAllowed = $integratedBlockers === [];
        $status = !$baseDeliveryAllowed
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
            'base_delivery_allowed' => $baseDeliveryAllowed,
            'target_delivery_allowed' => $baseDeliveryAllowed && $targetReady,
            'operating_target_status' => !$targetPreviewPresent
                ? 'not_set'
                : ($targetReady ? 'ready' : ($targetMatched ? 'not_ready' : 'mismatched')),
            'optional_source_status' => [
                'ctrip' => $this->optionalSourceStatus($ctrip),
                'meituan' => $this->optionalSourceStatus($meituan),
            ],
            'scope_boundary' => [
                'pms' => '订单来了住宿数据中心房费口径，不代表全酒店全部收入。',
                'ctrip' => '携程渠道自店订单口径，不与PMS住宿收入相加。',
                'meituan' => '美团渠道流量与支付订单口径；当前未返回房费和间夜。',
            ],
            'sources' => [
                'pms' => $pms,
                'ctrip' => $ctrip,
                'meituan' => $meituan,
            ],
            'gaps' => array_values($gaps),
            'blockers' => $blockers,
            'integrated_blockers' => $integratedBlockers,
            'generated_at' => $this->now()->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $capture @return array<string,mixed> */
    private function normalizePms(
        array $capture,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $scope
    ): array {
        $summary = is_array($capture['summary'] ?? null) ? $capture['summary'] : [];
        $collectedAt = $this->dateTimeOrNull($capture['captured_at'] ?? null);
        $freshnessStatus = $this->freshnessStatus($businessDate, $collectedAt, $scope);
        $factsReady = (int)($capture['tenant_id'] ?? 0) === $tenantId
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
        $ready = $factsReady && $this->freshnessReady($freshnessStatus);

        return [
            'source' => 'dingdandao',
            'source_label' => '订单来了PMS',
            'metric_scope' => 'accommodation_room_fee',
            'status' => $ready ? 'ready' : ($factsReady ? $freshnessStatus : 'blocked'),
            'delivery_evidence_ready' => $ready,
            'identity_status' => (string)($capture['identity_status'] ?? 'unverified'),
            'readback_verified' => (string)($capture['readback_status'] ?? '') === 'readback_verified',
            'reconciliation_status' => (string)($capture['reconciliation_status'] ?? 'unverified'),
            'capture_id' => (int)($capture['id'] ?? 0),
            'collected_at' => $collectedAt,
            'freshness_status' => $freshnessStatus,
            'lineage' => [
                'capture_id' => (int)($capture['id'] ?? 0),
                'captured_at' => $collectedAt,
            ],
            'facts' => [
                'room_fee_revenue' => $ready
                    ? $this->number($summary['total_room_fee'] ?? null)
                    : null,
                'adr' => $ready ? $this->number($summary['adr'] ?? null) : null,
                'occupancy_rate_percent' => $ready
                    ? $this->number($summary['occupancy_rate_percent'] ?? null)
                    : null,
                'revpar' => $ready ? $this->number($summary['revpar'] ?? null) : null,
                'sold_room_nights' => $ready
                    ? $this->number($summary['sold_room_nights'] ?? null)
                    : null,
                'average_daily_room_nights' => $ready
                    ? $this->number($summary['average_daily_room_nights'] ?? null)
                    : null,
                'sellable_room_nights' => $ready
                    ? $this->number($summary['derived_sellable_room_nights'] ?? null)
                    : null,
                'detail_room_fee_total' => $ready
                    ? $this->number($capture['detail_room_fee_total'] ?? null)
                    : null,
                'detail_row_count' => $ready
                    ? (int)($capture['detail_row_count'] ?? 0)
                    : null,
            ],
            'gaps' => $ready ? [] : [[
                'code' => $factsReady
                    ? 'pms_current_fact_stale'
                    : 'pms_capture_not_verified',
                'message' => '订单来了PMS的门店、日期、汇总明细对账或数据库回读未全部通过。',
            ]],
            'blocking_reason' => '订单来了PMS证据未通过完整门禁。',
        ];
    }

    /** @param array<string,mixed> $trusted @return array<string,mixed> */
    private function normalizeCtrip(
        array $trusted,
        string $businessDate,
        string $expectedPlatformHotelId,
        array $scope
    ): array
    {
        $dataStatusDeclared = array_key_exists('data_status', $trusted)
            && array_key_exists('data_gaps', $trusted);
        $dataStatus = strtolower(trim((string)($trusted['data_status'] ?? 'unverified')));
        $dataGaps = array_values(array_filter(
            (array)($trusted['data_gaps'] ?? []),
            static fn(mixed $gap): bool => is_string($gap) && trim($gap) !== ''
        ));
        // Callable loaders are test/compatibility seams. The real repository
        // always declares both fields and must be explicitly ready with no gap.
        $repositoryReady = $dataStatusDeclared
            ? $dataStatus === 'ready' && $dataGaps === []
            : $this->trustedOtaLoader !== null;
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
        $lineageIncomplete = false;
        foreach ($policyVerified ? (array)($trusted['rows'] ?? []) : [] as $row) {
            if (!is_array($row)
                || (string)($row['data_date'] ?? '') !== $businessDate
                || strtolower(trim((string)($row['source'] ?? ''))) !== 'ctrip'
            ) {
                continue;
            }
            $rowPlatformHotelId = trim((string)($row['platform_hotel_id'] ?? ''));
            $observedPlatformHotelId = trim((string)(
                $row['observed_platform_hotel_id'] ?? $rowPlatformHotelId
            ));
            if ($expectedPlatformHotelId === ''
                || $rowPlatformHotelId === ''
                || !hash_equals($expectedPlatformHotelId, $rowPlatformHotelId)
                || $observedPlatformHotelId === ''
                || !hash_equals($expectedPlatformHotelId, $observedPlatformHotelId)
                || (int)($row['data_source_id'] ?? 0) <= 0
            ) {
                $identityMismatchObserved = true;
                continue;
            }
            if ($dataStatusDeclared
                && (
                    (int)($row['row_id'] ?? 0) <= 0
                    || trim((string)($row['source_trace_id'] ?? '')) === ''
                )
            ) {
                $lineageIncomplete = true;
                continue;
            }
            $row['observed_platform_hotel_id'] = $observedPlatformHotelId;
            $rows[] = $row;
        }
        $revenue = $this->sumMetric($rows, 'amount');
        $orders = $this->sumMetric($rows, 'book_order_num');
        $roomNights = $this->sumMetric($rows, 'quantity');
        $identityVerified = $expectedPlatformHotelId !== ''
            && !$identityMismatchObserved
            && $rows !== [];
        $collectedAt = $this->latestDateTime($rows, 'collected_at');
        $freshnessStatus = $this->freshnessStatus($businessDate, $collectedAt, $scope);
        $ready = $repositoryReady
            && $identityVerified
            && !$lineageIncomplete
            && $this->freshnessReady($freshnessStatus)
            && $revenue !== null
            && $orders !== null
            && $roomNights !== null;
        $sourceStatus = $ready
            ? 'ready'
            : ($rows === []
                ? 'missing'
                : ($freshnessStatus === 'stale' ? 'stale' : 'partial'));
        $gapCode = !$repositoryReady
            ? 'ctrip_trusted_repository_not_ready'
            : (!$policyVerified
                ? 'ctrip_trusted_policy_unverified'
                : ($identityMismatchObserved
                    ? 'ctrip_platform_hotel_identity_mismatch'
                    : ($lineageIncomplete
                        ? 'ctrip_lineage_incomplete'
                        : ($freshnessStatus === 'stale'
                            ? 'ctrip_current_fact_stale'
                            : ($rows === []
                                ? 'ctrip_exact_date_fact_missing'
                                : 'ctrip_required_metric_missing')))));
        $observedPlatformHotelIds = $this->uniqueTexts(
            array_column($rows, 'observed_platform_hotel_id')
        );

        return [
            'source' => 'ctrip',
            'source_label' => '携程',
            'metric_scope' => 'ota_channel',
            'status' => $sourceStatus,
            'delivery_evidence_ready' => $ready,
            'repository_data_status' => $dataStatusDeclared ? $dataStatus : 'compatibility_fixture',
            'repository_data_gaps' => $dataGaps,
            'data_quality' => is_array($trusted['data_quality'] ?? null)
                ? $trusted['data_quality']
                : [],
            'identity_status' => $identityMismatchObserved
                ? 'mismatched'
                : ($identityVerified ? 'matched' : 'unverified'),
            'platform_hotel_id' => $identityVerified
                ? $expectedPlatformHotelId
                : null,
            'observed_platform_hotel_id' => count($observedPlatformHotelIds) === 1
                ? $observedPlatformHotelIds[0]
                : null,
            'readback_verified' => $repositoryReady && $rows !== [],
            'trusted_row_count' => count($rows),
            'collected_at' => $collectedAt,
            'freshness_status' => $freshnessStatus,
            'lineage' => [
                'row_ids' => $this->positiveInts(array_column($rows, 'row_id')),
                'data_source_ids' => $this->positiveInts(array_column($rows, 'data_source_id')),
                'source_trace_ids' => $this->uniqueTexts(array_column($rows, 'source_trace_id')),
                'collected_at' => $collectedAt,
            ],
            'facts' => [
                'channel_revenue' => $ready ? $revenue : null,
                'orders' => $ready ? $orders : null,
                'room_nights' => $ready ? $roomNights : null,
            ],
            'gaps' => $ready ? [] : [[
                'code' => $gapCode,
                'message' => !$policyVerified
                    ? '携程可信仓库的酒店、回读或渠道口径策略未通过。'
                    : '携程同店同日可信收入、订单或间夜事实不完整。',
            ]],
            'blocking_reason' => '携程同店同日可信事实未完成数据库回读。',
        ];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function normalizeMeituan(
        array $source,
        string $businessDate,
        string $expectedPlatformHotelId,
        array $scope
    ): array
    {
        $dateMatched = (string)($source['business_date'] ?? '') === $businessDate;
        $configuredPlatformHotelIds = $this->uniqueTexts(
            (array)($source['configured_platform_hotel_ids'] ?? [
                $source['configured_platform_hotel_id'] ?? $expectedPlatformHotelId,
            ])
        );
        $configuredPlatformHotelId = count($configuredPlatformHotelIds) === 1
            ? $configuredPlatformHotelIds[0]
            : '';
        $observedPlatformHotelId = trim((string)(
            $source['observed_platform_hotel_id'] ?? ''
        ));
        $identityValuesDeclared = array_key_exists('configured_platform_hotel_id', $source)
            || array_key_exists('observed_platform_hotel_id', $source);
        $strictIdentityMatched = !$identityValuesDeclared
            ? ($source['identity_matched'] ?? false) === true
            : $expectedPlatformHotelId !== ''
                && $configuredPlatformHotelId !== ''
                && $observedPlatformHotelId !== ''
                && hash_equals($expectedPlatformHotelId, $configuredPlatformHotelId)
                && $configuredPlatformHotelIds === [$expectedPlatformHotelId]
                && hash_equals($expectedPlatformHotelId, $observedPlatformHotelId)
                && ($source['identity_matched'] ?? false) === true;
        $sourceGateVerified = ($source['source_gate_verified'] ?? true) === true;
        $collectedAt = $this->dateTimeOrNull($source['collected_at'] ?? null);
        $orderCollectedAt = $this->dateTimeOrNull($source['order_collected_at'] ?? null);
        $freshnessStatus = $this->freshnessStatus($businessDate, $collectedAt, $scope);
        $orderFreshnessStatus = $this->freshnessStatus(
            $businessDate,
            $orderCollectedAt,
            $scope
        );
        $evidenceReady = $dateMatched
            && $strictIdentityMatched
            && $sourceGateVerified
            && ($source['readback_verified'] ?? false) === true
            && ($source['field_facts_verified'] ?? false) === true
            && ($source['order_fact_verified'] ?? false) === true
            && (int)($source['order_row_id'] ?? 0) > 0
            && (int)($source['data_source_id'] ?? 0) > 0
            && $orderCollectedAt !== null
            && $this->freshnessReady($freshnessStatus)
            && $this->freshnessReady($orderFreshnessStatus);
        $facts = is_array($source['facts'] ?? null) ? $source['facts'] : [];
        $requiredTrafficFactsPresent = true;
        foreach (['list_exposure', 'detail_exposure', 'flow_rate_percent', 'paid_orders'] as $field) {
            if (!array_key_exists($field, $facts) || $facts[$field] === null) {
                $requiredTrafficFactsPresent = false;
            }
        }
        $evidenceReady = $evidenceReady && $requiredTrafficFactsPresent;
        $gaps = [];
        if ($evidenceReady) {
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
                'code' => !$sourceGateVerified
                    ? 'meituan_source_gate_unverified'
                    : (!$strictIdentityMatched
                        ? 'meituan_platform_hotel_identity_mismatch'
                        : (($source['order_fact_verified'] ?? false) !== true
                            ? 'meituan_order_fact_unverified'
                            : ($freshnessStatus === 'stale'
                                || $orderFreshnessStatus === 'stale'
                            ? 'meituan_current_fact_stale'
                            : 'meituan_exact_date_fact_unverified'))),
                'message' => '美团门店身份、日期、字段事实或数据库回读未全部通过。',
            ];
        }

        return [
            'source' => 'meituan',
            'source_label' => '美团',
            'metric_scope' => 'ota_channel',
            'status' => $evidenceReady
                ? 'partial'
                : ($freshnessStatus === 'stale' ? 'stale' : 'blocked'),
            'delivery_evidence_ready' => $evidenceReady,
            'identity_status' => $strictIdentityMatched
                ? 'matched'
                : ($identityValuesDeclared ? 'mismatched' : 'unverified'),
            'platform_hotel_id' => $strictIdentityMatched ? $expectedPlatformHotelId : null,
            'observed_platform_hotel_id' => $observedPlatformHotelId !== ''
                ? $observedPlatformHotelId
                : null,
            'source_enabled' => $source['source_enabled'] ?? null,
            'source_status' => (string)($source['source_status'] ?? 'unverified'),
            'profile_binding_active' => $source['profile_binding_active'] ?? null,
            'readback_verified' => ($source['readback_verified'] ?? false) === true,
            'row_id' => (int)($source['row_id'] ?? 0),
            'collected_at' => $collectedAt,
            'freshness_status' => $freshnessStatus,
            'order_freshness_status' => $orderFreshnessStatus,
            'lineage' => [
                'traffic_row_id' => (int)($source['row_id'] ?? 0),
                'order_row_id' => (int)($source['order_row_id'] ?? 0),
                'data_source_id' => (int)($source['data_source_id'] ?? 0),
                'source_trace_ids' => $this->uniqueTexts(
                    (array)($source['source_trace_ids'] ?? [])
                ),
                'collected_at' => $collectedAt,
                'order_collected_at' => $orderCollectedAt,
            ],
            'facts' => [
                'list_exposure' => $evidenceReady
                    ? $this->number($facts['list_exposure'] ?? null)
                    : null,
                'detail_exposure' => $evidenceReady
                    ? $this->number($facts['detail_exposure'] ?? null)
                    : null,
                'flow_rate_percent' => $evidenceReady
                    ? $this->number($facts['flow_rate_percent'] ?? null)
                    : null,
                'paid_orders' => $evidenceReady
                    ? $this->number($facts['paid_orders'] ?? null)
                    : null,
                'target_date_order_count' => $evidenceReady
                    ? $this->number($facts['target_date_order_count'] ?? null)
                    : null,
                'channel_revenue' => null,
                'room_nights' => null,
            ],
            'gaps' => $gaps,
            'blocking_reason' => '美团同店同日流量与支付订单事实未通过完整证据门禁。',
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
            ->where('data_period', 'realtime_snapshot')
            ->where('readback_verified', 1)
            ->orderRaw(
                "COALESCE(NULLIF(snapshot_time, ''), NULLIF(update_time, ''), create_time) DESC"
            )
            ->order('id', 'desc')
            ->field(
                'id,data_source_id,system_hotel_id,data_date,list_exposure,detail_exposure,'
                . 'flow_rate,order_submit_num,validation_status,ingestion_method,source_trace_id,'
                . 'snapshot_time,update_time,create_time,raw_data,readback_verified'
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
            ->where('data_period', 'realtime_snapshot')
            ->where('readback_verified', 1)
            ->orderRaw(
                "COALESCE(NULLIF(snapshot_time, ''), NULLIF(update_time, ''), create_time) DESC"
            )
            ->order('id', 'desc')
            ->field(
                'id,data_source_id,data_date,book_order_num,source_trace_id,raw_data,'
                . 'snapshot_time,update_time,create_time,readback_verified'
            )
            ->find();

        $source = Db::name('platform_data_sources')
            ->where('id', (int)($row['data_source_id'] ?? 0))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', 'meituan')
            ->where('ingestion_method', 'browser_profile')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->find();
        $config = is_array($source)
            ? json_decode((string)($source['config_json'] ?? ''), true)
            : null;
        $config = is_array($config) ? $config : [];
        $expectedPlatformHotelId = trim((string)(
            $scope['platforms']['meituan']['platform_hotel_id'] ?? ''
        ));
        $configuredPoiId = $this->firstText($config, ['poi_id', 'poiId']);
        $configuredPlatformHotelIds = $this->uniqueTexts([
            $config['platform_hotel_id'] ?? null,
            $config['platformHotelId'] ?? null,
            $config['poi_id'] ?? null,
            $config['poiId'] ?? null,
            $config['store_id'] ?? null,
            $config['storeId'] ?? null,
        ]);
        $actualPlatformHotelId = count($configuredPlatformHotelIds) === 1
            ? $configuredPlatformHotelIds[0]
            : '';
        $sourceStatus = strtolower(trim((string)($source['status'] ?? '')));
        $sourceGateVerified = is_array($source)
            && (int)($source['enabled'] ?? 0) === 1
            && in_array(
                $sourceStatus,
                ['ready', 'success', 'active', 'verified', 'available'],
                true
            );
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
        $observedPlatformHotelId = $this->observedPlatformHotelId(
            $raw,
            ['platform_hotel_id', 'external_hotel_id', 'poi_id', 'poiId', 'store_id', 'storeId']
        );
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        $rawTraceId = trim((string)($raw['source_trace_id'] ?? ''));
        $identifierProof = strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? '')));
        $identityMatched = is_array($source)
            && $sourceGateVerified
            && $expectedPlatformHotelId !== ''
            && $configuredPoiId !== ''
            && hash_equals($expectedPlatformHotelId, $configuredPoiId)
            && $configuredPlatformHotelIds === [$expectedPlatformHotelId]
            && hash_equals($expectedPlatformHotelId, $actualPlatformHotelId)
            && $observedPlatformHotelId !== ''
            && hash_equals($expectedPlatformHotelId, $observedPlatformHotelId)
            && $bindingActive
            && ($raw['platform_hotel_identifier_present'] ?? null) === true
            && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
            && $identifierProof !== ''
            && !in_array($identifierProof, ['missing', 'unverified'], true)
            && $traceId !== ''
            && $rawTraceId !== ''
            && hash_equals($traceId, $rawTraceId);

        $fieldFactsVerified = $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['list_exposure', 'mt_exposure'],
            'list_exposure'
        ) && $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['detail_exposure', 'mt_intention_uv'],
            'detail_exposure'
        ) && $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['flow_rate'],
            'flow_rate'
        ) && $this->fieldFactCaptured(
            $raw,
            $traceId,
            ['order_submit_num', 'mt_pay_orders'],
            'order_submit_num'
        );
        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        $ingestionMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        $orderRaw = is_array($orderRow)
            ? json_decode((string)($orderRow['raw_data'] ?? ''), true)
            : null;
        $orderRaw = is_array($orderRaw) ? $orderRaw : [];
        $orderObservedPlatformHotelId = $this->observedPlatformHotelId(
            $orderRaw,
            ['platform_hotel_id', 'external_hotel_id', 'poi_id', 'poiId', 'store_id', 'storeId']
        );
        $orderTraceId = trim((string)($orderRow['source_trace_id'] ?? ''));
        $orderCollectedAt = is_array($orderRow)
            ? $this->firstDateTime($orderRow, [
                'snapshot_time',
                'update_time',
                'create_time',
            ])
            : null;
        $orderCountVerified = is_array($orderRow)
            && (int)($orderRow['data_source_id'] ?? 0) === (int)($row['data_source_id'] ?? 0)
            && (string)($orderRow['data_date'] ?? '') === $businessDate
            && (int)($orderRow['readback_verified'] ?? 0) === 1
            && $orderObservedPlatformHotelId !== ''
            && hash_equals($expectedPlatformHotelId, $orderObservedPlatformHotelId)
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
            'order_row_id' => (int)($orderRow['id'] ?? 0),
            'data_source_id' => (int)($row['data_source_id'] ?? 0),
            'source_trace_ids' => $this->uniqueTexts([$traceId, $orderTraceId]),
            'source_enabled' => (int)($source['enabled'] ?? 0) === 1,
            'source_status' => $sourceStatus,
            'source_gate_verified' => $sourceGateVerified,
            'profile_binding_active' => $bindingActive,
            'configured_platform_hotel_id' => $actualPlatformHotelId,
            'configured_platform_hotel_ids' => $configuredPlatformHotelIds,
            'observed_platform_hotel_id' => $observedPlatformHotelId,
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
            'order_collected_at' => $orderCollectedAt,
            'order_fact_verified' => $orderCountVerified,
            'facts' => [
                'list_exposure' => $this->number($row['list_exposure'] ?? null),
                'detail_exposure' => $this->number($row['detail_exposure'] ?? null),
                'flow_rate_percent' => $this->number($row['flow_rate'] ?? null),
                'paid_orders' => $this->number($row['order_submit_num'] ?? null),
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

    /** @param array<string,mixed> $raw @param array<int,string> $keys */
    private function observedPlatformHotelId(array $raw, array $keys): string
    {
        $candidates = [];
        $identity = is_array($raw['platform_identity_validation'] ?? null)
            ? $raw['platform_identity_validation']
            : [];
        if (strtolower(trim((string)($identity['status'] ?? ''))) === 'matched') {
            $candidates[] = trim((string)($identity['validated_identifier'] ?? ''));
        }
        foreach (['row', 'metrics', 'detail'] as $nestedKey) {
            $values = is_array($raw[$nestedKey] ?? null) ? $raw[$nestedKey] : [];
            $candidates[] = $this->firstText($values, $keys);
        }
        $candidates = $this->uniqueTexts($candidates);

        return count($candidates) === 1 ? $candidates[0] : '';
    }

    /** @param array<int,mixed> $values @return array<int,int> */
    private function positiveInts(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value > 0 && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }
        sort($result, SORT_NUMERIC);

        return $result;
    }

    /** @param array<int,mixed> $values @return array<int,string> */
    private function uniqueTexts(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param array<string,mixed> $scope */
    private function freshnessStatus(
        string $businessDate,
        ?string $collectedAt,
        array $scope
    ): string {
        $now = $this->now();
        if ($collectedAt === null) {
            return 'missing';
        }
        if ($businessDate !== $now->format('Y-m-d')) {
            return 'historical';
        }
        try {
            $collected = new DateTimeImmutable($collectedAt, new DateTimeZone('Asia/Shanghai'));
        } catch (\Throwable) {
            return 'missing';
        }
        $maxAgeMinutes = (int)($scope['realtime_max_age_minutes'] ?? 180);
        $maxAgeMinutes = max(1, min(1440, $maxAgeMinutes));
        $ageSeconds = $now->getTimestamp() - $collected->getTimestamp();

        return $ageSeconds <= ($maxAgeMinutes * 60) ? 'fresh' : 'stale';
    }

    private function freshnessReady(string $status): bool
    {
        return in_array($status, ['fresh', 'historical'], true);
    }

    /** @param array<string,mixed> $source */
    private function optionalSourceStatus(array $source): string
    {
        if (($source['delivery_evidence_ready'] ?? false) === true) {
            return 'ready';
        }

        if (strtolower(trim((string)($source['identity_status'] ?? ''))) === 'mismatched') {
            return 'identity_mismatch';
        }

        $freshness = strtolower(trim((string)($source['freshness_status'] ?? '')));
        $orderFreshness = strtolower(trim((string)($source['order_freshness_status'] ?? '')));
        if ($freshness === 'stale' || $orderFreshness === 'stale') {
            return 'stale';
        }

        $statuses = [
            strtolower(trim((string)($source['status'] ?? ''))),
            strtolower(trim((string)($source['repository_data_status'] ?? ''))),
            strtolower(trim((string)($source['source_status'] ?? ''))),
        ];
        if (array_intersect($statuses, ['failed', 'error', 'collection_failed', 'capture_failed']) !== []) {
            return 'failed';
        }
        if (in_array('missing', $statuses, true)) {
            return 'missing';
        }
        if (in_array('blocked', $statuses, true)) {
            return 'blocked';
        }

        return 'unverified';
    }

    private function now(): DateTimeImmutable
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $value = $this->clock === null ? null : call_user_func($this->clock);
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timezone);
        }
        if (is_string($value) && trim($value) !== '') {
            return new DateTimeImmutable($value, $timezone);
        }

        return new DateTimeImmutable('now', $timezone);
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
