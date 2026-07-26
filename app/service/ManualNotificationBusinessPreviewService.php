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

    public function __construct(
        ?callable $temporalOverviewLoader = null,
        ?callable $trustedOtaFactLoader = null,
        ?callable $collectionStateLoader = null
    ) {
        $this->temporalOverviewLoader = $temporalOverviewLoader;
        $this->trustedOtaFactLoader = $trustedOtaFactLoader;
        $this->collectionStateLoader = $collectionStateLoader;
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

        return self::buildPreview(
            $hotel,
            $businessDate,
            $dailyReport,
            $temporal,
            $trustedOta,
            $collectionState
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
        array $collectionState = []
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
        $future = self::futureSection($temporal, $tenantId, $hotelId, $businessDate);
        $review = self::reviewSection(
            $wholeHotel,
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
        $todayGaps = array_merge($todayGaps, $otaToday['gaps']);
        $today = self::sectionResult(
            'today_revenue_management',
            '今日收益管理',
            $todayFacts,
            [],
            $todayGaps
        );
        $today['ota_collection'] = $otaToday['collection'];

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
     *   collection:array<string,mixed>
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
        $qualityStatus = $trustedRows === []
            ? (string)($trustedOta['data_status'] ?? 'pending')
            : (count($platforms) === 2 ? 'readback_verified' : 'partial_readback_verified');
        $source = [
            'table' => 'online_daily_data',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_date' => $businessDate,
            'metric_scope' => 'ota_channel',
            'platforms' => $platforms,
            'quality_status' => $qualityStatus,
            'readback_verified' => $trustedRows !== [],
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

        $collection = self::normalizeCollectionState(
            $collectionState,
            $platforms,
            $hotelId,
            $businessDate
        );
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

        return [
            'facts' => $facts,
            'gaps' => $gaps,
            'collection' => $collection,
        ];
    }

    /** @param array<string, mixed> $temporal @return array<string, mixed> */
    private static function futureSection(
        array $temporal,
        int $tenantId,
        int $hotelId,
        string $businessDate
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

        return self::sectionResult('future_room_status', '远期房态', $facts, $forecasts, $gaps);
    }

    /**
     * @param array<string, array<string, mixed>> $wholeHotel
     * @param array<string, mixed> $temporal
     * @return array<string, mixed>
     */
    private static function reviewSection(
        array $wholeHotel,
        bool $hasDailyReport,
        array $temporal,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        $facts = [
            $wholeHotel['revenue'],
            $wholeHotel['sold_room_nights'],
            $wholeHotel['occupancy_rate'],
        ];
        $gaps = [];
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
