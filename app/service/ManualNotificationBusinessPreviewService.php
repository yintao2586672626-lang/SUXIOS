<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use think\facade\Db;

/**
 * Read-only business fact preview for the manual notification center.
 *
 * Whole-hotel daily-report facts and OTA-channel facts are deliberately kept
 * separate. Missing exact-date evidence remains null and is exposed as a gap.
 */
final class ManualNotificationBusinessPreviewService
{
    public const CONTRACT_VERSION = 'manual_notification_business_preview.v1';

    private const SECTION_TYPES = [
        'today_revenue_management',
        'future_room_status',
        'daily_review',
    ];

    /** @var callable|null */
    private $temporalOverviewLoader;

    /** @var callable|null */
    private $trustedOtaFactLoader;

    /** @var callable|null */
    private $collectionStateLoader;

    /** @var callable|null */
    private $forwardRoomStatusLoader;

    public function __construct(
        ?callable $temporalOverviewLoader = null,
        ?callable $trustedOtaFactLoader = null,
        ?callable $collectionStateLoader = null,
        ?callable $forwardRoomStatusLoader = null
    ) {
        $this->temporalOverviewLoader = $temporalOverviewLoader;
        $this->trustedOtaFactLoader = $trustedOtaFactLoader;
        $this->collectionStateLoader = $collectionStateLoader;
        $this->forwardRoomStatusLoader = $forwardRoomStatusLoader;
    }

    /**
     * Main integration contract for notification-center preview.
     *
     * @return array<string, mixed>
     */
    public function preview(int $hotelId, string $businessDate): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('business_preview_hotel_invalid');
        }
        $businessDate = self::normalizeDate($businessDate);
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('status', 1)
            ->field('id,tenant_id,name,status')
            ->find();
        if (!is_array($hotel)) {
            throw new InvalidArgumentException('business_preview_hotel_unavailable');
        }

        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('business_preview_tenant_unavailable');
        }

        $dailyReport = Db::name('daily_reports')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('report_date', $businessDate)
            ->where('status', 2)
            ->field(
                'id,tenant_id,hotel_id,report_date,report_data,occupancy_rate,'
                . 'room_count,guest_count,revenue,expenses,status,submitter_id,create_time,update_time'
            )
            ->order('update_time', 'desc')
            ->order('id', 'desc')
            ->find();
        $dailyReport = is_array($dailyReport) ? $dailyReport : null;

        try {
            $temporal = $this->temporalOverviewLoader === null
                ? (new TemporalInsightService())->overview([$hotelId], 30, 7, $businessDate)
                : call_user_func($this->temporalOverviewLoader, $hotelId, $businessDate);
        } catch (\Throwable $error) {
            $temporal = [
                'metric_scope' => 'ota_channel',
                'source_status' => 'read_failed',
                'source_error' => self::safeText($error->getMessage(), 160),
                'past' => [],
                'present' => [],
                'future' => [],
                'review' => [],
            ];
        }
        $temporal = is_array($temporal) ? $temporal : [];

        try {
            $trustedOta = $this->trustedOtaFactLoader === null
                ? (new TrustedOtaFactRepository())->pricingHistory($hotelId, $businessDate, $businessDate)
                : call_user_func($this->trustedOtaFactLoader, $hotelId, $businessDate);
        } catch (\Throwable $error) {
            $trustedOta = [
                'data_status' => 'read_failed',
                'rows' => [],
                'data_gaps' => ['trusted_ota_fact_read_failed'],
                'source_error' => self::safeText($error->getMessage(), 160),
            ];
        }
        $trustedOta = is_array($trustedOta) ? $trustedOta : [];

        try {
            $collectionState = $this->collectionStateLoader === null
                ? $this->loadCollectionState($tenantId, $hotelId, $businessDate, $trustedOta)
                : call_user_func($this->collectionStateLoader, $tenantId, $hotelId, $businessDate, $trustedOta);
        } catch (\Throwable $error) {
            $collectionState = self::pendingCollectionState(
                $hotelId,
                $businessDate,
                '采集状态待回写；当前不输出 OTA 数值。',
                self::safeText($error->getMessage(), 160)
            );
        }
        $collectionState = is_array($collectionState) ? $collectionState : [];

        try {
            if ($this->forwardRoomStatusLoader === null) {
                $captureService = new DingdandaoOperatingTargetCaptureService();
                $history = $captureService->history(
                    $tenantId,
                    $hotelId,
                    $businessDate,
                    2
                );
                $pmsCapture = $history[0]
                    ?? $captureService->latest($tenantId, $hotelId, $businessDate);
                if (is_array($history[1] ?? null) && is_array($pmsCapture)) {
                    $pmsCapture['previous_comparable_capture'] = $history[1];
                }
            } else {
                $pmsCapture = call_user_func(
                    $this->forwardRoomStatusLoader,
                    $tenantId,
                    $hotelId,
                    $businessDate
                );
            }
        } catch (\Throwable $error) {
            $pmsCapture = [
                'status' => 'read_failed',
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
                'forward_room_status' => [
                    'data_status' => 'partial',
                    'readback_status' => 'not_verified',
                    'gap_codes' => ['dingdandao_forward_read_failed'],
                ],
                'source_error' => self::safeText($error->getMessage(), 160),
            ];
        }
        $pmsCapture = is_array($pmsCapture) ? $pmsCapture : [];

        return self::buildPreview(
            $hotel,
            $businessDate,
            $dailyReport,
            $temporal,
            $trustedOta,
            $collectionState,
            $pmsCapture
        );
    }

    /**
     * Template-specific adapter for future ManualNotificationService wiring.
     *
     * @return array<string, mixed>
     */
    public function section(string $templateType, int $hotelId, string $businessDate): array
    {
        if (!in_array($templateType, self::SECTION_TYPES, true)) {
            throw new InvalidArgumentException('business_preview_section_invalid');
        }
        $preview = $this->preview($hotelId, $businessDate);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'preview_only' => true,
            'hotel' => $preview['hotel'],
            'business_date' => $preview['business_date'],
            'scope_boundary' => $preview['scope_boundary'],
            'section' => $preview['sections'][$templateType],
        ];
    }

    /**
     * Pure data-shaping contract used by direct tests and downstream adapters.
     *
     * @param array<string, mixed> $hotel
     * @param array<string, mixed>|null $dailyReport
     * @param array<string, mixed> $temporal
     * @param array<string, mixed> $trustedOta
     * @param array<string, mixed> $collectionState
     * @return array<string, mixed>
     */
    public static function buildPreview(
        array $hotel,
        string $businessDate,
        ?array $dailyReport,
        array $temporal,
        array $trustedOta = [],
        array $collectionState = [],
        array $pmsCapture = []
    ): array {
        $businessDate = self::normalizeDate($businessDate);
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelId <= 0 || $tenantId <= 0) {
            throw new InvalidArgumentException('business_preview_scope_invalid');
        }

        if (!self::dailyReportMatches($dailyReport, $tenantId, $hotelId, $businessDate)) {
            $dailyReport = null;
        }
        $reportData = self::decodeReportData($dailyReport['report_data'] ?? null);
        $reportSource = self::dailyReportSource($dailyReport, $tenantId, $hotelId, $businessDate);

        $wholeHotel = self::wholeHotelFacts($dailyReport, $reportData, $reportSource);
        $otaToday = self::otaTodayFacts(
            $trustedOta,
            $collectionState,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $pmsToday = self::pmsTodayFacts(
            $pmsCapture,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $future = self::futureSection(
            $temporal,
            $tenantId,
            $hotelId,
            $businessDate,
            $pmsCapture
        );
        $review = self::reviewSection(
            $wholeHotel,
            $pmsToday,
            $dailyReport !== null,
            $temporal,
            $tenantId,
            $hotelId,
            $businessDate
        );

        $todayFacts = array_merge(
            [
                $wholeHotel['revenue'],
                $wholeHotel['room_revenue'],
                $wholeHotel['sold_room_nights'],
                $wholeHotel['sellable_room_nights'],
                $wholeHotel['occupancy_rate'],
                $wholeHotel['adr'],
                $wholeHotel['revpar'],
            ],
            $pmsToday['facts'],
            $otaToday['facts']
        );
        $todayGaps = [];
        if ($dailyReport === null) {
            $todayGaps[] = self::gap(
                'whole_hotel_daily_report_missing',
                '未取得该酒店、该经营日期的已提交全酒店经营日报。',
                'missing',
                'daily_reports',
                $hotelId,
                $businessDate
            );
        } else {
            foreach ([
                'revenue' => '全酒店实际营收',
                'sold_room_nights' => '全酒店已售间夜',
                'sellable_room_nights' => '全酒店可售间夜',
            ] as $key => $label) {
                if (($wholeHotel[$key]['status'] ?? '') !== 'available') {
                    $todayGaps[] = self::gap(
                        'whole_hotel_' . $key . '_missing',
                        '已提交日报中未取得' . $label . '，不以 0 代替。',
                        'missing',
                        'daily_reports',
                        $hotelId,
                        $businessDate
                    );
                }
            }
        }
        $todayGaps = array_merge(
            $todayGaps,
            $pmsToday['gaps'],
            $otaToday['gaps']
        );
        $today = self::sectionResult(
            'today_revenue_management',
            '今日收益管理',
            $todayFacts,
            [],
            $todayGaps
        );
        $today['ota_collection'] = $otaToday['collection'];
        $today['message_data'] = [
            'contract_version' => 'three_source_today_message_facts.v1',
            'data_status' => self::threeSourceMessageDataStatus(
                $pmsToday['message_data'],
                $otaToday['source_snapshots']
            ),
            'business_date' => $businessDate,
            'fact_scope' => 'three_sources_kept_independent',
            'sources' => array_merge(
                ['dingdandao_pms' => $pmsToday['message_data']],
                $otaToday['source_snapshots']
            ),
            'aggregation_policy' => self::messageAggregationPolicy(),
        ];
        if (is_array($future['message_data'] ?? null)) {
            $future['message_data']['sources'] = [
                'dingdandao_pms' => [
                    'data_status' => (string)($future['message_data']['data_status'] ?? 'missing'),
                    'business_scope' => 'whole_hotel_forward_room_status',
                    'source' => $future['message_data']['source'] ?? null,
                ],
                'ctrip_ota' => $otaToday['source_snapshots']['ctrip_ota'],
                'meituan_ota' => $otaToday['source_snapshots']['meituan_ota'],
            ];
            $future['message_data']['aggregation_policy'] =
                self::messageAggregationPolicy();
            $future['message_data']['three_source_data_status'] =
                self::threeSourceMessageDataStatus(
                    [
                        'data_status' => (string)(
                            $future['message_data']['data_status']
                            ?? 'missing'
                        ),
                    ],
                    $otaToday['source_snapshots']
                );
        }
        $review['message_data'] = [
            'contract_version' => 'three_source_daily_review_message_facts.v1',
            'data_status' => ($pmsToday['message_data']['data_status'] ?? '')
                === 'readback_verified'
                ? (
                    self::threeSourceMessageDataStatus(
                        $pmsToday['message_data'],
                        $otaToday['source_snapshots']
                    ) === 'readback_verified'
                    && (string)($review['status'] ?? 'blocked') === 'ready'
                        ? 'readback_verified'
                        : 'partial'
                )
                : 'blocked',
            'business_date' => $businessDate,
            'snapshot_role' => 'latest_verified_snapshot_not_end_of_day_final',
            'sources' => array_merge(
                ['dingdandao_pms' => $pmsToday['message_data']],
                $otaToday['source_snapshots']
            ),
            'review_items' => array_values((array)($review['reviews'] ?? [])),
            'aggregation_policy' => self::messageAggregationPolicy(),
        ];

        $sections = [
            'today_revenue_management' => $today,
            'future_room_status' => $future,
            'daily_review' => $review,
        ];
        $availableCount = 0;
        $gapCount = 0;
        $sectionStatuses = [];
        foreach ($sections as $section) {
            $availableCount += (int)($section['available_count'] ?? 0);
            $gapCount += count((array)($section['gaps'] ?? []));
            $sectionStatuses[] = (string)($section['status'] ?? 'blocked');
        }
        $overallStatus = in_array('collecting', $sectionStatuses, true)
            ? 'collecting'
            : (in_array('pending_readback', $sectionStatuses, true)
                ? 'pending_readback'
                : (in_array('collection_failed', $sectionStatuses, true)
                    ? 'collection_failed'
                    : self::status($availableCount, $gapCount)));

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'preview_only' => true,
            'read_only' => true,
            'delivery_status' => 'not_sent',
            'status' => $overallStatus,
            'hotel' => [
                'id' => $hotelId,
                'tenant_id' => $tenantId,
                'name' => self::safeText((string)($hotel['name'] ?? ''), 120),
            ],
            'business_date' => $businessDate,
            'generated_at' => date('Y-m-d H:i:s'),
            'scope_boundary' => [
                'whole_hotel' => '仅使用同酒店、同日期、已提交的 daily_reports 经营日报字段。',
                'ota_channel' => 'TemporalInsight 仅代表已授权 OTA 渠道，不代表全酒店经营结果或真实远期房态。',
                'missing_data' => '未取得字段保持 null；不使用 0、旧日期或其他酒店数据补位。',
            ],
            'sections' => $sections,
            'summary' => [
                'available_count' => $availableCount,
                'gap_count' => $gapCount,
                'ota_collection_status' => (string)($otaToday['collection']['status'] ?? 'pending_collection'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $dailyReport
     * @param array<string, mixed> $reportData
     * @param array<string, mixed> $source
     * @return array<string, array<string, mixed>>
     */
    private static function wholeHotelFacts(?array $dailyReport, array $reportData, array $source): array
    {
        if ($dailyReport === null) {
            return [
                'revenue' => self::missingField('whole_hotel_revenue', '全酒店实际营收', '元', 'whole_hotel', $source),
                'room_revenue' => self::missingField('whole_hotel_room_revenue', '全酒店客房营收', '元', 'whole_hotel', $source),
                'sold_room_nights' => self::missingField('whole_hotel_sold_room_nights', '全酒店已售间夜', '间夜', 'whole_hotel', $source),
                'sellable_room_nights' => self::missingField('whole_hotel_sellable_room_nights', '全酒店可售间夜', '间夜', 'whole_hotel', $source),
                'occupancy_rate' => self::missingField('whole_hotel_occupancy_rate', '全酒店入住率', '%', 'whole_hotel', $source),
                'adr' => self::missingField('whole_hotel_adr', '全酒店 ADR', '元', 'whole_hotel', $source),
                'revpar' => self::missingField('whole_hotel_revpar', '全酒店 RevPAR', '元', 'whole_hotel', $source),
            ];
        }

        $revenue = self::numeric($dailyReport['revenue'] ?? $reportData['revenue'] ?? null);
        $roomRevenue = self::numeric($reportData['room_revenue'] ?? null);
        $sold = self::numeric($dailyReport['room_count'] ?? $reportData['total_rooms'] ?? null);
        $sellable = self::numeric($reportData['salable_rooms'] ?? null);
        $occupancy = self::numeric($dailyReport['occupancy_rate'] ?? null);
        if ($occupancy !== null && ($occupancy < 0 || $occupancy > 100)) {
            $occupancy = null;
        }
        if ($occupancy === null && $sold !== null && $sellable !== null && $sellable > 0) {
            $occupancy = round($sold / $sellable * 100, 2);
        }
        $adr = $roomRevenue !== null && $sold !== null && $sold > 0
            ? round($roomRevenue / $sold, 2)
            : null;
        $revpar = $roomRevenue !== null && $sellable !== null && $sellable > 0
            ? round($roomRevenue / $sellable, 2)
            : null;

        return [
            'revenue' => self::factField('whole_hotel_revenue', '全酒店实际营收', $revenue, '元', 'source_fact', 'whole_hotel', $source),
            'room_revenue' => self::factField('whole_hotel_room_revenue', '全酒店客房营收', $roomRevenue, '元', 'source_fact', 'whole_hotel', $source),
            'sold_room_nights' => self::factField('whole_hotel_sold_room_nights', '全酒店已售间夜', $sold, '间夜', 'source_fact', 'whole_hotel', $source),
            'sellable_room_nights' => self::factField('whole_hotel_sellable_room_nights', '全酒店可售间夜', $sellable, '间夜', 'source_fact', 'whole_hotel', $source),
            'occupancy_rate' => self::factField('whole_hotel_occupancy_rate', '全酒店入住率', $occupancy, '%', 'derived_or_source_fact', 'whole_hotel', $source),
            'adr' => self::factField('whole_hotel_adr', '全酒店 ADR', $adr, '元', 'derived_metric', 'whole_hotel', $source),
            'revpar' => self::factField('whole_hotel_revpar', '全酒店 RevPAR', $revpar, '元', 'derived_metric', 'whole_hotel', $source),
        ];
    }

    /**
     * @param array<string, mixed> $trustedOta
     * @param array<string, mixed> $collectionState
     * @return array{
     *   facts:array<int,array<string,mixed>>,
     *   gaps:array<int,array<string,mixed>>,
     *   collection:array<string,mixed>,
     *   source_snapshots:array<string,array<string,mixed>>
     * }
     */
    private static function otaTodayFacts(
        array $trustedOta,
        array $collectionState,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $sourcePolicy = is_array($trustedOta['source_policy'] ?? null)
            ? $trustedOta['source_policy']
            : [];
        $policyVerified = ($sourcePolicy['readback_policy'] ?? '') === 'readback_verified_required_equals_1'
            && ($sourcePolicy['hotel_scope'] ?? '') === 'system_hotel_id_strict_exact_only';
        $trustedRows = [];
        if ($policyVerified) {
            foreach ((array)($trustedOta['rows'] ?? []) as $row) {
                if (!is_array($row) || (string)($row['data_date'] ?? '') !== $businessDate) {
                    continue;
                }
                if (array_key_exists('system_hotel_id', $row)
                    && (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                ) {
                    continue;
                }
                if (array_key_exists('readback_verified', $row)
                    && !in_array(
                        $row['readback_verified'],
                        [1, '1', true, 'true'],
                        true
                    )
                ) {
                    continue;
                }
                $platform = self::platform((string)($row['source'] ?? ''));
                if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                    continue;
                }
                $row['source'] = $platform;
                $trustedRows[] = $row;
            }
        }
        $platforms = array_values(array_unique(array_column($trustedRows, 'source')));
        sort($platforms);
        $metrics = [
            'ota_revenue' => self::sumMetric($trustedRows, 'amount'),
            'ota_orders' => self::sumMetric($trustedRows, 'book_order_num'),
            'ota_room_nights' => self::sumMetric($trustedRows, 'quantity'),
        ];
        $collection = self::normalizeCollectionState(
            $collectionState,
            $platforms,
            $hotelId,
            $businessDate
        );
        $sourceSnapshots = self::otaSourceSnapshots(
            $trustedRows,
            $collection,
            $tenantId,
            $hotelId,
            $businessDate,
            $trustedOta
        );
        $verifiedSourceCount = count(array_filter(
            $sourceSnapshots,
            static fn(array $snapshot): bool =>
                ($snapshot['data_status'] ?? '') === 'readback_verified'
        ));
        $qualityStatus = $trustedRows === []
            ? (string)($trustedOta['data_status'] ?? 'pending')
            : ($verifiedSourceCount === 2
                ? 'readback_verified'
                : 'partial_readback_verified');
        $source = [
            'table' => 'online_daily_data',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_date' => $businessDate,
            'metric_scope' => 'ota_channel',
            'platforms' => $platforms,
            'quality_status' => $qualityStatus,
            'readback_verified' => $verifiedSourceCount > 0,
            'readback_policy' => $sourcePolicy['readback_policy'] ?? 'not_verified',
            'trusted_row_count' => count($trustedRows),
        ];
        $map = [
            'ota_revenue' => ['OTA 渠道收入', '元'],
            'ota_orders' => ['OTA 渠道订单', '单'],
            'ota_room_nights' => ['OTA 渠道间夜', '间夜'],
        ];
        $facts = [];
        $available = 0;
        foreach ($map as $key => [$label, $unit]) {
            $value = self::numeric($metrics[$key] ?? null);
            $facts[] = self::factField($key, $label, $value, $unit, 'source_fact', 'ota_channel', $source);
            $available += $value !== null ? 1 : 0;
        }

        $gaps = [];
        foreach ((array)($collection['platforms'] ?? []) as $platform => $state) {
            if (!is_array($state) || ($state['status'] ?? '') === 'readback_verified') {
                continue;
            }
            $status = (string)($state['status'] ?? 'pending_collection');
            $message = match ($status) {
                'collecting' => self::platformLabel((string)$platform) . '今日数据正在采集，结果尚未进入经营事实。',
                'pending_readback' => self::platformLabel((string)$platform) . '采集已返回，正在等待保存与数据库回读验证。',
                'collection_failed' => self::platformLabel((string)$platform) . '今日采集明确失败；当前不输出该平台数值。',
                default => self::platformLabel((string)$platform) . '今日采集任务待启动或等待任务状态回写。',
            };
            $gaps[] = self::gap(
                (string)$platform . '_' . $status,
                $message,
                $status,
                $status === 'pending_readback' ? 'online_daily_data' : 'platform_data_sync_tasks',
                $hotelId,
                $businessDate
            );
        }
        if (!$policyVerified) {
            $gaps[] = self::gap(
                'trusted_ota_readback_gate_unavailable',
                '可信 OTA 回读门禁未确认；即使存在采集结果也不输出数值。',
                'pending_readback',
                'online_daily_data',
                $hotelId,
                $businessDate
            );
        }
        $repositoryGapCodes = self::otaGapCodes($trustedOta['data_gaps'] ?? []);
        if ($repositoryGapCodes !== []) {
            $gaps[] = self::gap(
                'trusted_ota_fact_evidence_partial',
                '可信 OTA 回读仍有字段或证据缺口：'
                    . implode('、', array_slice($repositoryGapCodes, 0, 3))
                    . '。',
                'partial_readback_verified',
                'online_daily_data',
                $hotelId,
                $businessDate
            );
        }

        return [
            'facts' => $facts,
            'gaps' => $gaps,
            'collection' => $collection,
            'source_snapshots' => $sourceSnapshots,
        ];
    }

    /**
     * @return array{
     *   facts:list<array<string,mixed>>,
     *   gaps:list<array<string,mixed>>,
     *   message_data:array<string,mixed>
     * }
     */
    private static function pmsTodayFacts(
        array $capture,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $summary = is_array($capture['summary'] ?? null)
            ? $capture['summary']
            : [];
        $source = [
            'table' => 'dingdandao_operating_target_captures',
            'record_id' => isset($capture['id']) && is_numeric($capture['id'])
                ? (int)$capture['id']
                : null,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'data_date' => $businessDate,
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'business_scope' => 'accommodation_room_fee',
            'quality_status' => (string)($capture['quality_status'] ?? 'not_verified'),
            'readback_status' => (string)($capture['readback_status'] ?? 'not_verified'),
            'captured_at' => self::safeText((string)($capture['captured_at'] ?? ''), 32),
        ];
        $trusted = self::pmsTodayCaptureTrusted(
            $capture,
            $summary,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $map = [
            'pms_room_fee' => ['订单来了住宿房费', '元', 'total_room_fee', 'source_fact'],
            'pms_sold_room_nights' => ['订单来了已售间夜', '间夜', 'sold_room_nights', 'source_fact'],
            'pms_sellable_room_nights' => ['订单来了可售间夜', '间夜', 'derived_sellable_room_nights', 'derived_metric'],
            'pms_occupancy_rate' => ['订单来了入住率', '%', 'occupancy_rate_percent', 'source_fact'],
            'pms_adr' => ['订单来了 ADR', '元', 'adr', 'source_fact'],
            'pms_revpar' => ['订单来了 RevPAR', '元', 'revpar', 'source_fact'],
        ];
        $facts = [];
        if ($trusted) {
            $source['quality_status'] = 'readback_verified';
            $revenueOverview = self::pmsRevenueOverviewMessage($capture);
            $temporalContext = self::pmsTemporalContextMessage($capture);
            $snapshotDelta = self::pmsSnapshotDeltaMessage($capture);
            $alerts = self::pmsTodayAlerts($summary);
            $pmsGaps = [];
            if (($revenueOverview['data_status'] ?? '') !== 'readback_verified') {
                $pmsGaps[] = self::gap(
                    'dingdandao_revenue_overview_readback_not_verified',
                    '订单来了住宿营业额汇总未取得或未通过数据库回读；房费事实仍保持独立可用。',
                    (string)($revenueOverview['data_status'] ?? 'missing'),
                    'dingdandao_operating_target_captures',
                    $hotelId,
                    $businessDate
                );
            }
            if (($temporalContext['data_status'] ?? '') !== 'readback_verified') {
                $pmsGaps[] = self::gap(
                    'dingdandao_temporal_context_missing',
                    '订单来了过去、当前、未来时间口径未完整取得；当前消息不混用其他快照。',
                    (string)($temporalContext['data_status'] ?? 'missing'),
                    'dingdandao_operating_target_captures',
                    $hotelId,
                    $businessDate
                );
            }
            foreach ($map as $key => [$label, $unit, $field, $basis]) {
                $facts[] = self::factField(
                    $key,
                    $label,
                    self::numeric($summary[$field] ?? null),
                    $unit,
                    $basis,
                    'accommodation_room_fee',
                    $source
                );
            }
            $sold = (int)$summary['sold_room_nights'];
            $sellable = (int)$summary['derived_sellable_room_nights'];
            $remaining = max(0, $sellable - $sold);
            $facts[] = self::factField(
                'pms_remaining_sellable_room_nights',
                '订单来了剩余可售间夜',
                $remaining,
                '间夜',
                'derived_metric',
                'accommodation_room_fee',
                $source
            );
            return [
                'facts' => $facts,
                'gaps' => $pmsGaps,
                'message_data' => [
                    'contract_version' => 'dingdandao_today_message_facts.v1',
                    'data_status' => 'readback_verified',
                    'business_scope' => 'accommodation_room_fee',
                    'business_date' => $businessDate,
                    'facts' => [
                        'room_fee' => round((float)$summary['total_room_fee'], 2),
                        'sold_room_nights' => $sold,
                        'sellable_room_nights' => $sellable,
                        'remaining_sellable_room_nights' => $remaining,
                        'occupancy_rate_percent' => round(
                            (float)$summary['occupancy_rate_percent'],
                            2
                        ),
                        'adr' => round((float)$summary['adr'], 2),
                        'revpar' => round((float)$summary['revpar'], 2),
                        'revenue_overview' => $revenueOverview,
                        'temporal_context' => $temporalContext,
                        'snapshot_delta' => $snapshotDelta,
                        'alerts' => $alerts,
                    ],
                    'revenue_overview' => $revenueOverview,
                    'temporal_context' => $temporalContext,
                    'snapshot_delta' => $snapshotDelta,
                    'alerts' => $alerts,
                    'source' => $source,
                    'allowed_uses' => [
                        'notification_preview',
                        'pms_accommodation_revenue_analysis',
                        'cross_source_comparison_without_addition',
                    ],
                ],
            ];
        }

        $status = ($capture['status'] ?? '') === 'read_failed'
            ? 'collection_failed'
            : (($capture['readback_status'] ?? '') === 'pending'
                ? 'pending_readback'
                : 'missing');
        foreach ($map as $key => [$label, $unit]) {
            $facts[] = self::missingField(
                $key,
                $label,
                $unit,
                'accommodation_room_fee',
                $source,
                $status
            );
        }
        $facts[] = self::missingField(
            'pms_remaining_sellable_room_nights',
            '订单来了剩余可售间夜',
            '间夜',
            'accommodation_room_fee',
            $source,
            $status
        );
        return [
            'facts' => $facts,
            'gaps' => [
                self::gap(
                    'dingdandao_today_capture_readback_not_verified',
                    '订单来了当天住宿事实尚未通过同酒店、同日期、身份、字段对账和数据库回读门禁。',
                    $status,
                    'dingdandao_operating_target_captures',
                    $hotelId,
                    $businessDate
                ),
            ],
            'message_data' => [
                'contract_version' => 'dingdandao_today_message_facts.v1',
                'data_status' => $status,
                'business_scope' => 'accommodation_room_fee',
                'business_date' => $businessDate,
                'facts' => [
                    'room_fee' => null,
                    'sold_room_nights' => null,
                    'sellable_room_nights' => null,
                    'remaining_sellable_room_nights' => null,
                    'occupancy_rate_percent' => null,
                    'adr' => null,
                    'revpar' => null,
                    'revenue_overview' => [
                        'contract_version' =>
                            'dingdandao_accommodation_revenue_overview_message.v1',
                        'data_status' => $status,
                        'total_accommodation_turnover' => null,
                        'subjects' => [],
                        'total_trend' => [],
                    ],
                    'temporal_context' => [
                        'contract_version' =>
                            'dingdandao_temporal_context_message.v1',
                        'data_status' => 'missing',
                        'past' => [],
                        'current' => [],
                        'future' => [],
                    ],
                    'snapshot_delta' => [
                        'contract_version' =>
                            'dingdandao_snapshot_delta_message.v1',
                        'data_status' => 'missing',
                        'deltas' => [],
                    ],
                    'alerts' => [],
                ],
                'revenue_overview' => [
                    'contract_version' =>
                        'dingdandao_accommodation_revenue_overview_message.v1',
                    'data_status' => $status,
                    'total_accommodation_turnover' => null,
                    'subjects' => [],
                    'total_trend' => [],
                ],
                'temporal_context' => [
                    'contract_version' => 'dingdandao_temporal_context_message.v1',
                    'data_status' => 'missing',
                    'past' => [],
                    'current' => [],
                    'future' => [],
                ],
                'snapshot_delta' => [
                    'contract_version' => 'dingdandao_snapshot_delta_message.v1',
                    'data_status' => 'missing',
                    'deltas' => [],
                ],
                'alerts' => [],
                'source' => $source,
                'allowed_uses' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function pmsRevenueOverviewMessage(array $capture): array
    {
        $overview = is_array($capture['revenue_overview'] ?? null)
            ? $capture['revenue_overview']
            : [];
        $base = [
            'contract_version' =>
                'dingdandao_accommodation_revenue_overview_message.v1',
            'data_status' => 'missing',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'total_accommodation_turnover' => null,
            'subjects' => [],
            'total_trend' => [],
            'gap_codes' => array_values((array)($overview['gap_codes'] ?? [])),
        ];
        if (($overview['contract_version'] ?? '')
                !== 'dingdandao_accommodation_revenue_overview.v1'
            || ($overview['fact_scope'] ?? '')
                !== 'whole_hotel_accommodation_turnover'
            || ($overview['data_status'] ?? '') !== 'verified'
            || ($overview['readback_status'] ?? '') !== 'readback_verified'
            || self::numeric($overview['total_accommodation_turnover'] ?? null) === null
        ) {
            $base['data_status'] = ($overview['data_status'] ?? '') === 'partial'
                ? 'partial'
                : 'missing';
            return $base;
        }
        $subjects = [];
        foreach (array_slice((array)($overview['subjects'] ?? []), 0, 100) as $subject) {
            if (!is_array($subject)) {
                continue;
            }
            $name = self::safeText((string)($subject['subject_name'] ?? ''), 80);
            $singleDayTotal = self::numeric($subject['single_day_total'] ?? null);
            $periodTotal = self::numeric($subject['period_total'] ?? null);
            if ($name === '' || $singleDayTotal === null || $periodTotal === null) {
                continue;
            }
            $subjects[] = [
                'provider_subject_type' =>
                    is_numeric($subject['provider_subject_type'] ?? null)
                        ? (int)$subject['provider_subject_type']
                        : null,
                'subject_name' => $name,
                'single_day_total' => $singleDayTotal,
                'period_total' => $periodTotal,
                'percent' => self::numeric($subject['percent'] ?? null),
            ];
        }
        $totalTrend = [];
        foreach (array_slice((array)($overview['total_trend'] ?? []), -30) as $point) {
            if (!is_array($point)) {
                continue;
            }
            $date = trim((string)($point['observation_date'] ?? ''));
            $amount = self::numeric($point['amount'] ?? null);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1
                || $amount === null
            ) {
                continue;
            }
            $totalTrend[] = [
                'observation_date' => $date,
                'amount' => $amount,
            ];
        }
        if ($subjects === [] || $totalTrend === []) {
            return [
                ...$base,
                'data_status' => 'partial',
                'gap_codes' => array_values(array_unique([
                    ...$base['gap_codes'],
                    'dingdandao_revenue_overview_message_incomplete',
                ])),
            ];
        }
        return [
            ...$base,
            'data_status' => 'readback_verified',
            'total_accommodation_turnover' =>
                self::numeric($overview['total_accommodation_turnover']),
            'subjects' => $subjects,
            'total_trend' => $totalTrend,
            'gap_codes' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function pmsTemporalContextMessage(array $capture): array
    {
        $coverage = is_array($capture['component_coverage'] ?? null)
            ? $capture['component_coverage']
            : [];
        $temporal = is_array($coverage['temporal_context'] ?? null)
            ? $coverage['temporal_context']
            : [];
        $base = [
            'contract_version' => 'dingdandao_temporal_context_message.v1',
            'data_status' => 'missing',
            'past' => [],
            'current' => [],
            'future' => [],
        ];
        if (($temporal['contract_version'] ?? '') !== 'dingdandao_temporal_context.v1') {
            return $base;
        }
        $past = is_array($temporal['past'] ?? null) ? $temporal['past'] : [];
        $current = is_array($temporal['current'] ?? null) ? $temporal['current'] : [];
        $future = is_array($temporal['future'] ?? null) ? $temporal['future'] : [];
        $pastMessage = [
            'status' => self::safeText((string)($past['status'] ?? ''), 32),
            'snapshot_role' =>
                self::safeText((string)($past['snapshot_role'] ?? ''), 48),
            'settlement_status' =>
                self::safeText((string)($past['settlement_status'] ?? ''), 32),
            'date_from' => self::safeText((string)($past['date_from'] ?? ''), 10),
            'date_to' => self::safeText((string)($past['date_to'] ?? ''), 10),
            'expected_days' => self::numeric($past['expected_days'] ?? null),
            'covered_days' => self::numeric($past['covered_days'] ?? null),
        ];
        $currentMessage = [
            'status' => self::safeText((string)($current['status'] ?? ''), 32),
            'snapshot_role' =>
                self::safeText((string)($current['snapshot_role'] ?? ''), 48),
            'settlement_status' =>
                self::safeText((string)($current['settlement_status'] ?? ''), 32),
            'business_date' =>
                self::safeText((string)($current['business_date'] ?? ''), 10),
        ];
        $futureMessage = [
            'status' => self::safeText((string)($future['status'] ?? ''), 32),
            'snapshot_role' =>
                self::safeText((string)($future['snapshot_role'] ?? ''), 48),
            'as_of_date' => self::safeText((string)($future['as_of_date'] ?? ''), 10),
            'stay_date_from' =>
                self::safeText((string)($future['stay_date_from'] ?? ''), 10),
            'stay_date_to' =>
                self::safeText((string)($future['stay_date_to'] ?? ''), 10),
            'display_horizons' => array_values(array_intersect(
                array_map('intval', (array)($future['display_horizons'] ?? [])),
                [3, 7, 14, 21]
            )),
        ];
        $statuses = [
            $pastMessage['status'],
            $currentMessage['status'],
            $futureMessage['status'],
        ];
        $verifiedStatuses = ['verified', 'verified_with_anomalies'];
        $allVerified = count(array_filter(
            $statuses,
            static fn(string $status): bool =>
                in_array($status, $verifiedStatuses, true)
        )) === count($statuses);
        $allMissing = count(array_filter(
            $statuses,
            static fn(string $status): bool =>
                $status === '' || $status === 'missing'
        )) === count($statuses);
        $dataStatus = $allVerified
            ? 'readback_verified'
            : ($allMissing ? 'missing' : 'partial');
        return [
            ...$base,
            'data_status' => $dataStatus,
            'past' => $pastMessage,
            'current' => $currentMessage,
            'future' => $futureMessage,
        ];
    }

    /** @return array<string,mixed> */
    private static function pmsSnapshotDeltaMessage(array $capture): array
    {
        $base = [
            'contract_version' => 'dingdandao_snapshot_delta_message.v1',
            'data_status' => 'baseline_only',
            'from_capture_id' => null,
            'to_capture_id' => isset($capture['id']) && is_numeric($capture['id'])
                ? (int)$capture['id']
                : null,
            'captured_from' => null,
            'captured_to' =>
                self::safeText((string)($capture['captured_at'] ?? ''), 32),
            'deltas' => [],
        ];
        $previous = is_array($capture['previous_comparable_capture'] ?? null)
            ? $capture['previous_comparable_capture']
            : [];
        if ($previous === []) {
            return $base;
        }
        $sameScope = (int)($previous['tenant_id'] ?? 0)
                === (int)($capture['tenant_id'] ?? 0)
            && (int)($previous['hotel_id'] ?? 0)
                === (int)($capture['hotel_id'] ?? 0)
            && (string)($previous['business_date'] ?? '')
                === (string)($capture['business_date'] ?? '')
            && (string)($previous['provider'] ?? '')
                === (string)($capture['provider'] ?? '')
            && ($previous['quality_status'] ?? '') === 'verified'
            && ($previous['capture_status'] ?? '') === 'verified'
            && ($previous['identity_status'] ?? '') === 'matched'
            && ($previous['reconciliation_status'] ?? '') === 'matched'
            && ($previous['readback_status'] ?? '') === 'readback_verified';
        $previousCapturedAt = trim((string)($previous['captured_at'] ?? ''));
        $currentCapturedAt = trim((string)($capture['captured_at'] ?? ''));
        $differentCapture = (int)($previous['id'] ?? 0) > 0
            && (int)($previous['id'] ?? 0) !== (int)($capture['id'] ?? 0)
            && $previousCapturedAt !== ''
            && $currentCapturedAt !== ''
            && $previousCapturedAt !== $currentCapturedAt;
        if (!$sameScope || !$differentCapture) {
            return [
                ...$base,
                'data_status' => 'not_comparable',
            ];
        }
        $previousSummary = is_array($previous['summary'] ?? null)
            ? $previous['summary']
            : [];
        $currentSummary = is_array($capture['summary'] ?? null)
            ? $capture['summary']
            : [];
        $metricMap = [
            'room_fee' => 'total_room_fee',
            'sold_room_nights' => 'sold_room_nights',
            'occupancy_rate_percent' => 'occupancy_rate_percent',
            'adr' => 'adr',
            'revpar' => 'revpar',
        ];
        $deltas = [];
        foreach ($metricMap as $output => $source) {
            $previousValue = self::numeric($previousSummary[$source] ?? null);
            $currentValue = self::numeric($currentSummary[$source] ?? null);
            if ($previousValue === null || $currentValue === null) {
                return [
                    ...$base,
                    'data_status' => 'not_comparable',
                ];
            }
            $deltas[$output] = round((float)$currentValue - (float)$previousValue, 2);
        }
        return [
            ...$base,
            'data_status' => 'comparable',
            'from_capture_id' => (int)$previous['id'],
            'captured_from' =>
                self::safeText((string)($previous['captured_at'] ?? ''), 32),
            'deltas' => $deltas,
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function pmsTodayAlerts(array $summary): array
    {
        $sold = self::numeric($summary['sold_room_nights'] ?? null);
        $sellable = self::numeric($summary['derived_sellable_room_nights'] ?? null);
        $occupancy = self::numeric($summary['occupancy_rate_percent'] ?? null);
        if ($sold === null || $sellable === null || $sellable <= 0
            || (int)$sold !== (int)$sellable
        ) {
            return [];
        }
        return [[
            'code' => 'pms_today_sold_out',
            'severity' => 'warning',
            'message' => '今日可售已归零。',
            'sold_room_nights' => (int)$sold,
            'sellable_room_nights' => (int)$sellable,
            'remaining_sellable_room_nights' => 0,
            'occupancy_rate_percent' => $occupancy,
        ]];
    }

    private static function pmsTodayCaptureTrusted(
        array $capture,
        array $summary,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): bool {
        if ((int)($capture['tenant_id'] ?? 0) !== $tenantId
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || (string)($capture['business_date'] ?? '') !== $businessDate
            || (string)($capture['provider'] ?? '')
                !== DingdandaoOperatingTargetCaptureService::PROVIDER
            || ($capture['quality_status'] ?? '') !== 'verified'
            || ($capture['capture_status'] ?? '') !== 'verified'
            || ($capture['identity_status'] ?? '') !== 'matched'
            || ($capture['reconciliation_status'] ?? '') !== 'matched'
            || ($capture['readback_status'] ?? '') !== 'readback_verified'
        ) {
            return false;
        }
        $required = [
            'total_room_fee',
            'sold_room_nights',
            'derived_sellable_room_nights',
            'occupancy_rate_percent',
            'adr',
            'revpar',
        ];
        foreach ($required as $key) {
            if (self::numeric($summary[$key] ?? null) === null) {
                return false;
            }
        }
        $fee = (float)$summary['total_room_fee'];
        $sold = (int)$summary['sold_room_nights'];
        $sellable = (int)$summary['derived_sellable_room_nights'];
        $occupancy = (float)$summary['occupancy_rate_percent'];
        $adr = (float)$summary['adr'];
        $revpar = (float)$summary['revpar'];
        if ($fee < 0 || $sold < 0 || $sellable <= 0 || $sold > $sellable
            || $occupancy < 0 || $occupancy > 100 || $adr < 0 || $revpar < 0
        ) {
            return false;
        }
        $expectedOccupancy = round($sold / $sellable * 100, 2);
        $expectedAdr = $sold > 0 ? round($fee / $sold, 2) : 0.0;
        $expectedRevpar = round($fee / $sellable, 2);
        return abs($occupancy - $expectedOccupancy) <= 0.02
            && abs($adr - $expectedAdr) <= 0.02
            && abs($revpar - $expectedRevpar) <= 0.02;
    }

    /**
     * @param list<array<string,mixed>> $trustedRows
     * @return array<string,array<string,mixed>>
     */
    private static function otaSourceSnapshots(
        array $trustedRows,
        array $collection,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $trustedOta
    ): array {
        $snapshots = [];
        $repositoryGapCodes = self::otaGapCodes($trustedOta['data_gaps'] ?? []);
        $repositoryReady = ($trustedOta['data_status'] ?? '') === 'ready'
            && $repositoryGapCodes === [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $rows = array_values(array_filter(
                $trustedRows,
                static fn(array $row): bool => ($row['source'] ?? '') === $platform
            ));
            $state = is_array($collection['platforms'][$platform] ?? null)
                ? $collection['platforms'][$platform]
                : [];
            $facts = [
                'revenue' => self::sumMetric($rows, 'amount'),
                'orders' => self::sumMetric($rows, 'book_order_num'),
                'room_nights' => self::sumMetric($rows, 'quantity'),
            ];
            $missingMetrics = [];
            foreach ($facts as $key => $value) {
                if (self::numeric($value) === null) {
                    $missingMetrics[] = 'ota_' . $platform . '_' . $key . '_missing';
                }
            }
            $provenanceComplete = $rows !== []
                && count(array_filter(
                    $rows,
                    static fn(array $row): bool =>
                        self::otaRowProvenanceTrusted($row, $hotelId)
                )) === count($rows);
            $dataStatus = $rows === []
                ? (string)($state['status'] ?? 'pending_collection')
                : (
                    $repositoryReady
                    && $missingMetrics === []
                    && $provenanceComplete
                        ? 'readback_verified'
                        : 'partial_readback_verified'
                );
            $gapCodes = array_values(array_unique(array_merge(
                $repositoryGapCodes,
                $missingMetrics,
                $provenanceComplete || $rows === []
                    ? []
                    : ['ota_' . $platform . '_provenance_incomplete']
            )));
            $snapshots[$platform . '_ota'] = [
                'contract_version' => 'ota_channel_message_facts.v1',
                'data_status' => $dataStatus,
                'business_scope' => 'ota_channel',
                'business_date' => $businessDate,
                'platform' => $platform,
                'facts' => $facts,
                'gap_codes' => $gapCodes,
                'source' => [
                    'table' => 'online_daily_data',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'data_date' => $businessDate,
                    'platform' => $platform,
                    'readback_status' => $dataStatus,
                    'trusted_row_count' => count($rows),
                    'row_ids' => self::positiveIntList(array_column($rows, 'row_id')),
                    'source_trace_ids' => self::safeStringList(
                        array_column($rows, 'source_trace_id'),
                        100
                    ),
                    'sync_task_ids' => self::positiveIntList(
                        array_column($rows, 'sync_task_id')
                    ),
                    'data_source_ids' => self::positiveIntList(
                        array_column($rows, 'data_source_id')
                    ),
                    'task_id' => isset($state['task_id']) && is_numeric($state['task_id'])
                        ? (int)$state['task_id']
                        : null,
                ],
                'allowed_uses' => $dataStatus === 'readback_verified'
                    ? [
                        'ota_channel_analysis',
                        'cross_source_comparison_without_addition',
                    ]
                    : ['source_status_monitoring'],
            ];
        }
        return $snapshots;
    }

    /** @return array<string,mixed> */
    private static function messageAggregationPolicy(): array
    {
        return [
            'pms_plus_ota_revenue_addition_allowed' => false,
            'missing_source_value' => null,
            'cross_source_comparison_requires_same_hotel_and_date' => true,
            'ota_scope' => 'ota_channel',
            'pms_scope' => 'accommodation_room_fee',
        ];
    }

    private static function threeSourceMessageDataStatus(
        array $pms,
        array $otaSnapshots
    ): string {
        if (($pms['data_status'] ?? '') !== 'readback_verified') {
            return 'blocked';
        }
        foreach (['ctrip_ota', 'meituan_ota'] as $sourceKey) {
            if (($otaSnapshots[$sourceKey]['data_status'] ?? '')
                !== 'readback_verified'
            ) {
                return 'partial';
            }
        }
        return 'readback_verified';
    }

    /** @param array<string, mixed> $temporal @return array<string, mixed> */
    private static function futureSection(
        array $temporal,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $pmsCapture = []
    ): array {
        $roomSource = [
            'table' => null,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'data_date' => $businessDate,
            'metric_scope' => 'whole_hotel',
            'quality_status' => 'not_configured',
        ];
        $facts = [
            self::missingField('future_sellable_room_nights', '远期全酒店可售间夜', '间夜', 'whole_hotel', $roomSource, 'not_configured'),
            self::missingField('future_booked_room_nights', '远期全酒店已订间夜', '间夜', 'whole_hotel', $roomSource, 'not_configured'),
            self::missingField('future_occupancy_rate', '远期全酒店入住率', '%', 'whole_hotel', $roomSource, 'not_configured'),
        ];
        $gaps = [
            self::gap(
                'whole_hotel_forward_room_status_source_not_configured',
                '全酒店远期房态来源待配置；美团/携程流量预测不能替代可售、已订或入住率。',
                'not_configured',
                null,
                $hotelId,
                $businessDate
            ),
        ];
        $pmsForward = null;
        if ($pmsCapture !== []) {
            $pmsForward = self::pmsForwardFacts(
                $pmsCapture,
                $tenantId,
                $hotelId,
                $businessDate
            );
            $facts = $pmsForward['facts'];
            $gaps = $pmsForward['gaps'];
        }

        $future = is_array($temporal['future'] ?? null) ? $temporal['future'] : [];
        $version = is_array($future['version'] ?? null) ? $future['version'] : [];
        $asOfDate = trim((string)($version['as_of_date'] ?? ''));
        $series = (array)($future['series'] ?? []);
        $forecasts = [];
        if (
            strtolower(trim((string)($future['status'] ?? ''))) === 'ready'
            && $asOfDate === $businessDate
            && strtolower(trim((string)($temporal['metric_scope'] ?? 'ota_channel'))) === 'ota_channel'
        ) {
            foreach ($series as $point) {
                if (!is_array($point)) {
                    continue;
                }
                $targetDate = trim((string)($point['date'] ?? ''));
                if (!self::isDate($targetDate) || strcmp($targetDate, $businessDate) <= 0) {
                    continue;
                }
                foreach ((array)($point['metrics'] ?? []) as $metricKey => $metric) {
                    if (!is_array($metric) || !in_array($metricKey, ['ota_revenue', 'ota_orders', 'ota_room_nights'], true)) {
                        continue;
                    }
                    $forecasts[] = [
                        'key' => (string)$metricKey,
                        'label' => self::forecastLabel((string)$metricKey),
                        'status' => 'forecast_available',
                        'basis' => 'derived_forecast',
                        'scope' => 'ota_channel',
                        'target_date' => $targetDate,
                        'direction' => self::safeText((string)($metric['direction'] ?? ''), 24),
                        'lower_bound' => self::numeric($metric['lower_bound'] ?? null),
                        'upper_bound' => self::numeric($metric['upper_bound'] ?? null),
                        'confidence_level' => self::safeText((string)($metric['confidence_level'] ?? ''), 24),
                        'source' => [
                            'table' => 'temporal_forecast_snapshots',
                            'tenant_id' => $tenantId,
                            'system_hotel_id' => $hotelId,
                            'as_of_date' => $asOfDate,
                            'target_date' => $targetDate,
                            'forecast_run_id' => self::safeText((string)($version['forecast_run_id'] ?? ''), 64),
                            'model_version' => self::safeText((string)($version['model_version'] ?? ''), 80),
                            'metric_scope' => 'ota_channel',
                        ],
                        'note' => '仅为 OTA 渠道趋势区间，不是全酒店远期房态事实。',
                    ];
                }
            }
        }
        if ($forecasts === []) {
            $gaps[] = self::gap(
                'exact_date_ota_forecast_missing',
                '未取得以该经营日期为基准生成的 OTA 渠道预测版本；不沿用旧版本。',
                'missing',
                'temporal_forecast_snapshots',
                $hotelId,
                $businessDate
            );
        }

        $section = self::sectionResult(
            'future_room_status',
            '远期房态',
            $facts,
            $forecasts,
            $gaps
        );
        $section['message_data'] = is_array($pmsForward)
            ? $pmsForward['message_data']
            : null;
        return $section;
    }

    /**
     * @return array{
     *   facts:list<array<string,mixed>>,
     *   gaps:list<array<string,mixed>>,
     *   message_data:array<string,mixed>
     * }
     */
    private static function pmsForwardFacts(
        array $capture,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $forward = is_array($capture['forward_room_status'] ?? null)
            ? $capture['forward_room_status']
            : [];
        $source = [
            'table' => 'dingdandao_operating_target_captures',
            'record_id' => isset($capture['id']) && is_numeric($capture['id'])
                ? (int)$capture['id']
                : null,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'data_date' => $businessDate,
            'provider' => DingdandaoOperatingTargetCaptureService::PROVIDER,
            'source_api_path' => '/v2/hm-b/pro/web/accom/roomStat/forward/v2',
            'metric_scope' => 'whole_hotel_forward_room_status',
            'quality_status' => 'not_verified',
            'readback_status' => (string)(
                $forward['readback_status']
                ?? $capture['readback_status']
                ?? 'not_verified'
            ),
            'captured_at' => self::safeText(
                (string)($capture['captured_at'] ?? $forward['captured_at'] ?? ''),
                32
            ),
        ];
        $trusted = self::pmsForwardCaptureTrusted(
            $capture,
            $forward,
            $tenantId,
            $hotelId,
            $businessDate
        );
        $horizon = $trusted ? self::forwardHorizon($forward, 7) : null;
        $dailyRows = $trusted
            ? self::forwardMessageDailyRows($forward, $businessDate)
            : [];
        $messageHorizons = $trusted ? self::forwardMessageHorizons($forward) : [];
        $messageRoomTypes = $trusted
            ? self::forwardMessageRoomTypes($forward, $businessDate)
            : [];
        $alerts = $trusted
            ? self::pmsForwardAlerts($forward, $dailyRows, $businessDate)
            : [];
        if (!is_array($horizon)
            || count($dailyRows) !== 21
            || count($messageHorizons) !== 4
            || count($messageRoomTypes)
                !== (int)($forward['source_room_type_count'] ?? 0)
        ) {
            $trusted = false;
        }
        $metricMap = [
            'future_sellable_room_nights' => [
                '未来7天可售房晚',
                '间夜',
                'sellable_room_nights',
            ],
            'future_booked_room_nights' => [
                '未来7天已订房晚',
                '间夜',
                'booked_room_nights',
            ],
            'future_remaining_sellable_room_nights' => [
                '未来7天剩余可售房晚',
                '间夜',
                'remaining_sellable_room_nights',
            ],
            'future_occupancy_rate' => [
                '未来7天累计入住率',
                '%',
                'occupancy_rate_percent',
            ],
            'future_room_fee' => [
                '未来7天累计房费',
                '元',
                'room_fee',
            ],
            'future_adr' => ['未来7天 ADR', '元', 'adr'],
            'future_revpar' => ['未来7天 RevPAR', '元', 'revpar'],
        ];
        $facts = [];
        if ($trusted) {
            $source['quality_status'] = 'readback_verified';
            foreach ($metricMap as $key => [$label, $unit, $field]) {
                $fact = self::factField(
                    $key,
                    $label,
                    self::numeric($horizon[$field] ?? null),
                    $unit,
                    'source_fact_or_reconciled_metric',
                    'whole_hotel_forward_room_status',
                    $source
                );
                $fact['horizon_days'] = 7;
                $fact['date_from'] = $horizon['date_from'];
                $fact['date_to'] = $horizon['date_to'];
                $facts[] = $fact;
            }
            return [
                'facts' => $facts,
                'gaps' => [],
                'message_data' => [
                    'contract_version' => 'dingdandao_forward_message_facts.v1',
                    'data_status' => 'readback_verified',
                    'quality_status' =>
                        ($forward['data_status'] ?? '') === 'verified_with_anomalies'
                            ? 'warning'
                            : 'verified',
                    'fact_scope' => 'whole_hotel_forward_room_status',
                    'as_of_date' => $businessDate,
                    'display_horizons' => [3, 7, 14, 21],
                    'default_horizon_days' => 7,
                    'source_day_count' => (int)$forward['source_day_count'],
                    'display_day_count' => count($dailyRows),
                    'source_coverage_status' => (string)(
                        $forward['source_coverage_status'] ?? 'complete'
                    ),
                    'source_gap_codes' => array_values((array)(
                        $forward['source_gap_codes'] ?? []
                    )),
                    'requested_range_end_date' =>
                        $forward['requested_range_end_date'] ?? null,
                    'total_room_count' => (int)$forward['total_room_count'],
                    'horizons' => $messageHorizons,
                    'daily_rows' => $dailyRows,
                    'room_types' => $messageRoomTypes,
                    'alerts' => $alerts,
                    'source' => $source,
                ],
            ];
        }

        $status = ($capture['status'] ?? '') === 'read_failed'
            ? 'collection_failed'
            : (($capture['readback_status'] ?? '') === 'pending'
                ? 'pending_readback'
                : 'missing');
        foreach ($metricMap as $key => [$label, $unit]) {
            $facts[] = self::missingField(
                $key,
                $label,
                $unit,
                'whole_hotel_forward_room_status',
                $source,
                $status
            );
        }
        return [
            'facts' => $facts,
            'gaps' => [
                self::gap(
                    'whole_hotel_forward_room_status_readback_not_verified',
                    '订单来了远期房态尚未通过同酒店、同日期、字段对账和数据库回读门禁；OTA 预测不替代该事实。',
                    $status,
                    'dingdandao_operating_target_captures',
                    $hotelId,
                    $businessDate
                ),
            ],
            'message_data' => [
                'contract_version' => 'dingdandao_forward_message_facts.v1',
                'data_status' => $status,
                'fact_scope' => 'whole_hotel_forward_room_status',
                'as_of_date' => $businessDate,
                'display_horizons' => [3, 7, 14, 21],
                'default_horizon_days' => 7,
                'source_day_count' => 0,
                'display_day_count' => 0,
                'source_coverage_status' => 'missing',
                'source_gap_codes' => array_values((array)(
                    $forward['source_gap_codes']
                    ?? $forward['gap_codes']
                    ?? []
                )),
                'requested_range_end_date' =>
                    $forward['requested_range_end_date'] ?? null,
                'total_room_count' => null,
                'horizons' => [],
                'daily_rows' => [],
                'room_types' => [],
                'alerts' => [],
                'source' => $source,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $dailyRows
     * @return list<array<string,mixed>>
     */
    private static function pmsForwardAlerts(
        array $forward,
        array $dailyRows,
        string $businessDate
    ): array
    {
        $alerts = [];
        $firstSourceDate = self::shiftDate($businessDate, 1);
        $sourceDayCount = max(
            2,
            min(31, (int)($forward['source_day_count'] ?? 31))
        );
        $lastSourceDate = self::shiftDate(
            $businessDate,
            $sourceDayCount - 1
        );
        foreach (array_slice((array)($forward['anomalies'] ?? []), 0, 100) as $anomaly) {
            if (!is_array($anomaly)
                || ($anomaly['anomaly_type'] ?? '') !== 'oversold'
            ) {
                continue;
            }
            $stayDate = trim((string)($anomaly['stay_date'] ?? ''));
            $roomTypeName = self::safeText(
                (string)($anomaly['room_type_name'] ?? ''),
                80
            );
            $oversoldRooms = self::numeric($anomaly['oversold_rooms'] ?? null);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stayDate) !== 1
                || $stayDate < $firstSourceDate
                || $stayDate > $lastSourceDate
                || $roomTypeName === ''
                || $oversoldRooms === null
                || $oversoldRooms <= 0
            ) {
                continue;
            }
            $alerts[] = [
                'code' => 'pms_forward_oversold',
                'severity' => 'critical',
                'stay_date' => $stayDate,
                'room_type_name' => $roomTypeName,
                'oversold_rooms' => (int)$oversoldRooms,
                'message' => '远期房型存在超售。',
            ];
        }
        foreach ($dailyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $remaining = self::numeric($row['remaining_sellable_rooms'] ?? null);
            $booked = self::numeric($row['booked_rooms'] ?? null);
            $stayDate = trim((string)($row['stay_date'] ?? ''));
            if ($remaining === null || $booked === null
                || (int)$remaining !== 0
                || $booked <= 0
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stayDate) !== 1
                || $stayDate < $firstSourceDate
                || $stayDate > $lastSourceDate
            ) {
                continue;
            }
            $alerts[] = [
                'code' => 'pms_forward_sold_out',
                'severity' => 'warning',
                'stay_date' => $stayDate,
                'booked_rooms' => (int)$booked,
                'remaining_sellable_rooms' => 0,
                'message' => '该日远期可售已归零。',
            ];
        }
        return $alerts;
    }

    private static function pmsForwardCaptureTrusted(
        array $capture,
        array $forward,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): bool {
        $sourceDayCount = (int)($forward['source_day_count'] ?? 0);
        $sourceCoverageStatus = (string)($forward['source_coverage_status'] ?? '');
        $sourceGapCodes = array_values((array)($forward['source_gap_codes'] ?? []));
        $forwardDataStatus = (string)($forward['data_status'] ?? '');
        $forwardGapCodes = array_values((array)($forward['gap_codes'] ?? []));
        $verifiedOversoldAlerts = self::pmsForwardAlerts(
            $forward,
            [],
            $businessDate
        );
        $forwardWarningValid = $forwardDataStatus === 'verified_with_anomalies'
            && $forwardGapCodes !== []
            && array_values(array_unique($forwardGapCodes)) === [
                'dingdandao_forward_oversold_present',
            ]
            && count($verifiedOversoldAlerts) > 0;
        return (int)($capture['tenant_id'] ?? 0) === $tenantId
            && (int)($capture['hotel_id'] ?? 0) === $hotelId
            && (string)($capture['business_date'] ?? '') === $businessDate
            && (string)($capture['provider'] ?? '')
                === DingdandaoOperatingTargetCaptureService::PROVIDER
            && ($capture['quality_status'] ?? '') === 'verified'
            && ($capture['capture_status'] ?? '') === 'verified'
            && ($capture['identity_status'] ?? '') === 'matched'
            && ($capture['reconciliation_status'] ?? '') === 'matched'
            && ($capture['readback_status'] ?? '') === 'readback_verified'
            && ($forward['contract_version'] ?? '')
                === 'dingdandao_forward_room_status.v1'
            && ($forward['fact_scope'] ?? '')
                === 'whole_hotel_forward_room_status'
            && ($forward['source_api_path'] ?? '')
                === '/v2/hm-b/pro/web/accom/roomStat/forward/v2'
            && (
                $forwardDataStatus === 'verified'
                || $forwardWarningValid
            )
            && ($forward['readback_status'] ?? '') === 'readback_verified'
            && ($forward['as_of_date'] ?? '') === $businessDate
            && ($forward['range_start_date'] ?? '') === $businessDate
            && ($forward['requested_range_start_date'] ?? '') === $businessDate
            && ($forward['requested_range_end_date'] ?? '')
                === self::shiftDate($businessDate, 30)
            && $sourceDayCount >= 22
            && $sourceDayCount <= 31
            && ($forward['range_end_date'] ?? '')
                === self::shiftDate($businessDate, $sourceDayCount - 1)
            && (int)($forward['display_day_count'] ?? 0) === 21
            && ($forward['display_semantics'] ?? '')
                === 'future_days_after_as_of_date'
            && (int)($forward['source_room_type_count'] ?? 0) > 0
            && (int)($forward['total_room_count'] ?? 0) > 0
            && (array)($forward['display_horizons'] ?? []) === [3, 7, 14, 21]
            && (
                (
                    $sourceCoverageStatus === 'complete'
                    && $sourceDayCount === 31
                    && $sourceGapCodes === []
                )
                || (
                    $sourceCoverageStatus === 'partial'
                    && $sourceDayCount < 31
                    && $sourceGapCodes === [
                        'dingdandao_forward_trailing_coverage_partial',
                    ]
                )
            )
            && ($forward['reconciliation_status'] ?? '') === 'matched'
            && (
                $forwardGapCodes === []
                || $forwardWarningValid
            );
    }

    /** @return array<string,mixed>|null */
    private static function forwardHorizon(array $forward, int $days): ?array
    {
        foreach ((array)($forward['horizons'] ?? []) as $row) {
            if (!is_array($row)
                || (int)($row['horizon_days'] ?? 0) !== $days
                || !in_array(
                    (string)($row['quality_status'] ?? ''),
                    ['verified', 'warning'],
                    true
                )
                || (int)($row['expected_days'] ?? 0) !== $days
                || (int)($row['covered_days'] ?? 0) !== $days
                || !in_array(
                    array_values((array)($row['gap_codes'] ?? [])),
                    [
                        [],
                        ['dingdandao_forward_oversold_present'],
                    ],
                    true
                )
            ) {
                continue;
            }
            foreach ([
                'sellable_room_nights',
                'booked_room_nights',
                'remaining_sellable_room_nights',
                'unavailable_room_nights',
                'room_fee',
                'occupancy_rate_percent',
                'adr',
                'revpar',
            ] as $field) {
                if (self::numeric($row[$field] ?? null) === null) {
                    continue 2;
                }
            }
            return $row;
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private static function forwardMessageHorizons(array $forward): array
    {
        $result = [];
        foreach ([3, 7, 14, 21] as $days) {
            $row = self::forwardHorizon($forward, $days);
            if (!is_array($row)) {
                return [];
            }
            $result[] = [
                'horizon_days' => $days,
                'date_from' => (string)$row['date_from'],
                'date_to' => (string)$row['date_to'],
                'covered_days' => (int)$row['covered_days'],
                'expected_days' => (int)$row['expected_days'],
                'sellable_room_nights' => self::numeric($row['sellable_room_nights']),
                'booked_room_nights' => self::numeric($row['booked_room_nights']),
                'remaining_sellable_room_nights' =>
                    self::numeric($row['remaining_sellable_room_nights']),
                'unavailable_room_nights' =>
                    self::numeric($row['unavailable_room_nights']),
                'room_fee' => self::numeric($row['room_fee']),
                'occupancy_rate_percent' =>
                    self::numeric($row['occupancy_rate_percent']),
                'adr' => self::numeric($row['adr']),
                'revpar' => self::numeric($row['revpar']),
                'quality_status' => (string)$row['quality_status'],
                'gap_codes' => array_values((array)($row['gap_codes'] ?? [])),
                'oversold_room_nights' =>
                    self::numeric($row['oversold_room_nights'] ?? 0),
            ];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function forwardMessageDailyRows(
        array $forward,
        string $businessDate
    ): array {
        $byDate = [];
        foreach ((array)($forward['daily_rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = trim((string)($row['stay_date'] ?? ''));
            if (!self::isDate($date)) {
                continue;
            }
            $normalized = self::forwardMessageDay($row, $date);
            if ($normalized !== null) {
                $byDate[$date] = $normalized;
            }
        }
        $result = [];
        for ($offset = 1; $offset <= 21; $offset++) {
            $date = self::shiftDate($businessDate, $offset);
            if (!isset($byDate[$date])) {
                return [];
            }
            $result[] = $byDate[$date];
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private static function forwardMessageDay(array $row, string $date): ?array
    {
        $result = ['stay_date' => $date];
        foreach ([
            'remaining_sellable_rooms',
            'booked_rooms',
            'unavailable_rooms',
            'room_fee',
            'sold_room_nights',
            'sellable_room_nights',
            'occupancy_rate_percent',
            'adr',
            'revpar',
        ] as $field) {
            $value = self::numeric($row[$field] ?? null);
            if ($value === null) {
                return null;
            }
            $result[$field] = $value;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function forwardMessageRoomTypes(
        array $forward,
        string $businessDate
    ): array {
        $result = [];
        foreach ((array)($forward['room_types'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = self::safeText((string)($row['provider_room_type_id'] ?? ''), 120);
            $name = self::safeText((string)($row['room_type_name'] ?? ''), 160);
            $roomCount = self::numeric($row['room_count'] ?? null);
            $dailyRows = self::forwardMessageDailyRows(
                ['daily_rows' => (array)($row['daily_rows'] ?? [])],
                $businessDate
            );
            if ($id === '' || $name === '' || $roomCount === null || count($dailyRows) !== 21) {
                continue;
            }
            $result[] = [
                'provider_room_type_id' => $id,
                'room_type_name' => $name,
                'room_count' => $roomCount,
                'daily_rows' => $dailyRows,
            ];
        }
        return $result;
    }

    private static function shiftDate(string $date, int $days): string
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('business_preview_date_invalid');
        }
        return $value->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * @param array<string, array<string, mixed>> $wholeHotel
     * @param array<string, mixed> $pmsToday
     * @param array<string, mixed> $temporal
     * @return array<string, mixed>
     */
    private static function reviewSection(
        array $wholeHotel,
        array $pmsToday,
        bool $hasDailyReport,
        array $temporal,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $facts = array_merge(
            [
                $wholeHotel['revenue'],
                $wholeHotel['sold_room_nights'],
                $wholeHotel['occupancy_rate'],
            ],
            array_values((array)($pmsToday['facts'] ?? []))
        );
        $gaps = array_values((array)($pmsToday['gaps'] ?? []));
        if (!$hasDailyReport) {
            $gaps[] = self::gap(
                'exact_date_whole_hotel_review_missing',
                '今日复盘未取得该酒店、该日期的已提交全酒店经营日报。',
                'missing',
                'daily_reports',
                $hotelId,
                $businessDate
            );
        }

        $review = is_array($temporal['review'] ?? null) ? $temporal['review'] : [];
        $reviews = [];
        foreach ((array)($review['items'] ?? []) as $item) {
            if (!is_array($item) || (string)($item['target_date'] ?? '') !== $businessDate) {
                continue;
            }
            $actual = self::numeric($item['actual_value'] ?? null);
            if ($actual === null) {
                continue;
            }
            $metricKey = (string)($item['metric_key'] ?? '');
            if (!in_array($metricKey, ['ota_revenue', 'ota_orders', 'ota_room_nights'], true)) {
                continue;
            }
            $interval = is_array($item['forecast_interval'] ?? null) ? $item['forecast_interval'] : [];
            $reviews[] = [
                'key' => $metricKey,
                'label' => self::forecastLabel($metricKey),
                'status' => 'available',
                'basis' => 'forecast_review',
                'scope' => 'ota_channel',
                'target_date' => $businessDate,
                'actual_value' => $actual,
                'forecast_interval' => [
                    'lower' => self::numeric($interval['lower'] ?? null),
                    'upper' => self::numeric($interval['upper'] ?? null),
                ],
                'within_range' => is_bool($item['within_range'] ?? null) ? $item['within_range'] : null,
                'source' => [
                    'table' => 'temporal_forecast_snapshots + online_daily_data',
                    'tenant_id' => $tenantId,
                    'system_hotel_id' => $hotelId,
                    'target_date' => $businessDate,
                    'forecast_run_id' => self::safeText((string)($review['forecast_run_id'] ?? ''), 64),
                    'metric_scope' => 'ota_channel',
                ],
                'note' => '仅复盘 OTA 渠道预测与同日 OTA 定稿事实，不外推全酒店。',
            ];
        }
        if ($reviews === []) {
            $gaps[] = self::gap(
                'exact_date_ota_forecast_review_missing',
                '未取得目标日期对应的 OTA 预测复盘；旧日期复盘不会用于今日。',
                'missing',
                'temporal_forecast_snapshots + online_daily_data',
                $hotelId,
                $businessDate
            );
        }

        return self::sectionResult('daily_review', '今日复盘', $facts, $reviews, $gaps);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<int, array<string, mixed>> $supplementary
     * @param array<int, array<string, mixed>> $gaps
     * @return array<string, mixed>
     */
    private static function sectionResult(
        string $key,
        string $title,
        array $facts,
        array $supplementary,
        array $gaps
    ): array {
        $available = count(array_filter(
            $facts,
            static fn(array $field): bool => ($field['status'] ?? '') === 'available'
        )) + count(array_filter(
            $supplementary,
            static fn(array $field): bool => in_array(
                (string)($field['status'] ?? ''),
                ['available', 'forecast_available'],
                true
            )
        ));

        $gapStatuses = array_values(array_map(
            static fn(array $gap): string => (string)($gap['status'] ?? ''),
            $gaps
        ));
        $status = $available === 0 && (
            in_array('collecting', $gapStatuses, true)
            || in_array('pending_collection', $gapStatuses, true)
        )
            ? 'collecting'
            : ($available === 0 && in_array('pending_readback', $gapStatuses, true)
                ? 'pending_readback'
                : ($available === 0 && in_array('collection_failed', $gapStatuses, true)
                    ? 'collection_failed'
                    : self::status($available, count($gaps))));

        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'facts' => $facts,
            $key === 'daily_review' ? 'reviews' : 'forecasts' => $supplementary,
            'gaps' => array_values($gaps),
            'available_count' => $available,
        ];
    }

    /** @param array<string, mixed> $trustedOta @return array<string, mixed> */
    private function loadCollectionState(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $trustedOta
    ): array {
        $trustedPlatforms = [];
        $sourcePolicy = is_array($trustedOta['source_policy'] ?? null)
            ? $trustedOta['source_policy']
            : [];
        if (
            ($sourcePolicy['readback_policy'] ?? '') === 'readback_verified_required_equals_1'
            && ($sourcePolicy['hotel_scope'] ?? '') === 'system_hotel_id_strict_exact_only'
        ) {
            foreach ((array)($trustedOta['rows'] ?? []) as $row) {
                if (!is_array($row) || (string)($row['data_date'] ?? '') !== $businessDate) {
                    continue;
                }
                $platform = self::platform((string)($row['source'] ?? ''));
                if (in_array($platform, ['ctrip', 'meituan'], true)) {
                    $trustedPlatforms[] = $platform;
                }
            }
        }
        $trustedPlatforms = array_values(array_unique($trustedPlatforms));

        try {
            $start = $businessDate . ' 00:00:00';
            $end = $businessDate . ' 23:59:59';
            $tasks = Db::name('platform_data_sync_tasks')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->whereIn('platform', ['ctrip', 'meituan'])
                ->whereBetween('create_time', [$start, $end])
                ->field(
                    'id,tenant_id,system_hotel_id,platform,status,started_at,finished_at,'
                    . 'create_time,update_time'
                )
                ->order('id', 'desc')
                ->limit(100)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return self::pendingCollectionState(
                $hotelId,
                $businessDate,
                '采集任务状态待回写；当前不输出 OTA 数值。'
            );
        }

        $latest = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $platform = self::platform((string)($task['platform'] ?? ''));
            if (!in_array($platform, ['ctrip', 'meituan'], true) || isset($latest[$platform])) {
                continue;
            }
            $latest[$platform] = $task;
        }

        $platformStates = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            if (in_array($platform, $trustedPlatforms, true)) {
                $platformStates[$platform] = [
                    'status' => 'readback_verified',
                    'label' => '已保存并回读验证',
                    'task_id' => isset($latest[$platform]) ? (int)($latest[$platform]['id'] ?? 0) : null,
                ];
                continue;
            }
            if (!isset($latest[$platform])) {
                $platformStates[$platform] = [
                    'status' => 'pending_collection',
                    'label' => '正在采集或等待任务状态回写',
                    'task_id' => null,
                ];
                continue;
            }
            $task = $latest[$platform];
            $status = self::taskCollectionStatus((string)($task['status'] ?? ''));
            $platformStates[$platform] = [
                'status' => $status,
                'label' => self::collectionStatusLabel($status),
                'task_id' => (int)($task['id'] ?? 0),
                'task_status' => self::safeText((string)($task['status'] ?? ''), 32),
                'started_at' => self::safeText((string)($task['started_at'] ?? ''), 24),
                'finished_at' => self::safeText((string)($task['finished_at'] ?? ''), 24),
            ];
        }

        return self::normalizeCollectionState(
            ['platforms' => $platformStates],
            $trustedPlatforms,
            $hotelId,
            $businessDate
        );
    }

    /** @return array<string, mixed> */
    private static function pendingCollectionState(
        int $hotelId,
        string $businessDate,
        string $message,
        string $diagnostic = ''
    ): array {
        return [
            'status' => 'collecting',
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'message' => $message,
            'diagnostic' => $diagnostic,
            'platforms' => [
                'ctrip' => [
                    'status' => 'pending_collection',
                    'label' => '正在采集或等待任务状态回写',
                    'task_id' => null,
                ],
                'meituan' => [
                    'status' => 'pending_collection',
                    'label' => '正在采集或等待任务状态回写',
                    'task_id' => null,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, string> $trustedPlatforms
     * @return array<string, mixed>
     */
    private static function normalizeCollectionState(
        array $state,
        array $trustedPlatforms,
        int $hotelId,
        string $businessDate
    ): array {
        $inputPlatforms = is_array($state['platforms'] ?? null) ? $state['platforms'] : [];
        $platforms = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $row = is_array($inputPlatforms[$platform] ?? null) ? $inputPlatforms[$platform] : [];
            $status = in_array($platform, $trustedPlatforms, true)
                ? 'readback_verified'
                : (string)($row['status'] ?? 'pending_collection');
            if (!in_array($status, [
                'readback_verified',
                'collecting',
                'pending_readback',
                'collection_failed',
                'pending_collection',
            ], true)) {
                $status = 'pending_collection';
            }
            $platforms[$platform] = [
                'status' => $status,
                'label' => self::collectionStatusLabel($status),
                'task_id' => isset($row['task_id']) && is_numeric($row['task_id'])
                    ? (int)$row['task_id']
                    : null,
                'task_status' => self::safeText((string)($row['task_status'] ?? ''), 32),
                'started_at' => self::safeText((string)($row['started_at'] ?? ''), 24),
                'finished_at' => self::safeText((string)($row['finished_at'] ?? ''), 24),
            ];
        }
        $statuses = array_column($platforms, 'status');
        $overall = in_array('collecting', $statuses, true)
            || in_array('pending_collection', $statuses, true)
            ? 'collecting'
            : (in_array('pending_readback', $statuses, true)
                ? 'pending_readback'
                : (in_array('collection_failed', $statuses, true)
                    ? 'collection_failed'
                    : 'readback_verified'));

        return [
            'status' => $overall,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'message' => match ($overall) {
                'readback_verified' => '携程、美团当前日期事实已保存并通过数据库回读门禁。',
                'pending_readback' => '采集已有结果，正在等待保存与数据库回读验证。',
                'collection_failed' => '当前日期采集已明确失败；未输出失败平台数值。',
                default => '携程、美团当前日期数据正在采集或等待任务状态回写。',
            },
            'platforms' => $platforms,
        ];
    }

    private static function taskCollectionStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pending', 'queued', 'running', 'leased', 'retry_wait',
            'waiting_user_login', 'verification_required' => 'collecting',
            'success', 'succeeded', 'partial_success', 'partial', 'completed' => 'pending_readback',
            'failed', 'error', 'collection_failed' => 'collection_failed',
            default => 'pending_collection',
        };
    }

    private static function collectionStatusLabel(string $status): string
    {
        return match ($status) {
            'readback_verified' => '已保存并回读验证',
            'collecting' => '正在采集',
            'pending_readback' => '待保存与回读验证',
            'collection_failed' => '采集失败',
            default => '正在采集或等待任务状态回写',
        };
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function sumMetric(array $rows, string $key): int|float|null
    {
        $sum = 0.0;
        $observed = false;
        foreach ($rows as $row) {
            $value = self::numeric($row[$key] ?? null);
            if ($value === null) {
                continue;
            }
            $sum += $value;
            $observed = true;
        }
        return $observed ? self::numeric($sum) : null;
    }

    /** @return list<string> */
    private static function otaGapCodes(mixed $value): array
    {
        $codes = [];
        foreach ((array)$value as $candidate) {
            $candidate = strtolower(trim((string)$candidate));
            if (preg_match('/^[a-z0-9][a-z0-9_:-]{0,119}$/', $candidate) === 1) {
                $codes[$candidate] = $candidate;
            }
        }
        return array_values($codes);
    }

    private static function otaRowProvenanceTrusted(array $row, int $hotelId): bool
    {
        return (int)($row['row_id'] ?? 0) > 0
            && (int)($row['system_hotel_id'] ?? 0) === $hotelId
            && in_array(
                $row['readback_verified'] ?? null,
                [1, '1', true, 'true'],
                true
            )
            && trim((string)($row['source_trace_id'] ?? '')) !== '';
    }

    /** @return list<int> */
    private static function positiveIntList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $result[(int)$value] = (int)$value;
            }
        }
        return array_values($result);
    }

    /** @return list<string> */
    private static function safeStringList(array $values, int $limit): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = self::safeText((string)$value, $limit);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        return array_values($result);
    }

    private static function platform(string $source): string
    {
        $source = strtolower(trim($source));
        if (str_contains($source, 'ctrip') || str_contains($source, '携程')) {
            return 'ctrip';
        }
        if (str_contains($source, 'meituan') || str_contains($source, '美团')) {
            return 'meituan';
        }
        return '';
    }

    private static function platformLabel(string $platform): string
    {
        return $platform === 'ctrip' ? '携程' : ($platform === 'meituan' ? '美团' : 'OTA');
    }

    /** @param array<string, mixed>|null $report */
    private static function dailyReportMatches(
        ?array $report,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): bool {
        return is_array($report)
            && (int)($report['tenant_id'] ?? 0) === $tenantId
            && (int)($report['hotel_id'] ?? 0) === $hotelId
            && (string)($report['report_date'] ?? '') === $businessDate
            && (int)($report['status'] ?? 0) === 2;
    }

    /** @param array<string, mixed>|null $report @return array<string, mixed> */
    private static function dailyReportSource(
        ?array $report,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        return [
            'table' => 'daily_reports',
            'record_id' => $report === null ? null : (int)($report['id'] ?? 0),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'report_date' => $businessDate,
            'required_status' => 'submitted',
            'quality_status' => $report === null ? 'missing' : 'submitted_exact_date_readback',
            'source_method' => 'not_recorded',
            'update_time' => $report === null ? null : self::safeText((string)($report['update_time'] ?? ''), 24),
        ];
    }

    /** @return array<string, mixed> */
    private static function factField(
        string $key,
        string $label,
        int|float|null $value,
        string $unit,
        string $basis,
        string $scope,
        array $source
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $value === null ? 'missing' : 'available',
            'value' => $value,
            'unit' => $unit,
            'basis' => $basis,
            'scope' => $scope,
            'source' => $source,
            'note' => $value === null ? '未取得，不以 0 或旧日期补位。' : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function missingField(
        string $key,
        string $label,
        string $unit,
        string $scope,
        array $source,
        string $status = 'missing'
    ): array {
        $field = self::factField($key, $label, null, $unit, 'source_fact', $scope, $source);
        $field['status'] = $status;
        return $field;
    }

    /** @return array<string, mixed> */
    private static function gap(
        string $code,
        string $message,
        string $status,
        ?string $sourceTable,
        int $hotelId,
        string $businessDate
    ): array {
        return [
            'code' => $code,
            'status' => $status,
            'message' => $message,
            'source_table' => $sourceTable,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
        ];
    }

    /** @return array<string, mixed> */
    private static function decodeReportData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array)$value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function numeric(mixed $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = (float)$value;
        return floor($number) === $number ? (int)$number : $number;
    }

    private static function status(int $availableCount, int $gapCount): string
    {
        if ($availableCount === 0) {
            return 'blocked';
        }
        return $gapCount > 0 ? 'partial' : 'ready';
    }

    private static function forecastLabel(string $metricKey): string
    {
        return match ($metricKey) {
            'ota_revenue' => 'OTA 渠道收入趋势',
            'ota_orders' => 'OTA 渠道订单趋势',
            'ota_room_nights' => 'OTA 渠道间夜趋势',
            default => 'OTA 渠道指标趋势',
        };
    }

    private static function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (!self::isDate($date)) {
            throw new InvalidArgumentException('business_preview_date_invalid');
        }
        return $date;
    }

    private static function isDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private static function safeText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, $limit, 'UTF-8');
    }
}
