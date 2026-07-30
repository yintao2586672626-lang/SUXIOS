<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\KnowledgeBase;
use app\model\KnowledgeCategory;
use app\model\PriceSuggestion;
use app\model\RoomType;
use app\model\DemandForecast;
use app\model\CompetitorAnalysis;
use app\model\OperationLog;
use app\model\SystemConfig;
use app\model\AiModelConfig;
use app\model\User as UserModel;
use app\service\AgentClosureReadinessService;
use app\service\AiDecisionQualityService;
use app\service\AiModelRoutingService;
use app\service\CompetitorPriceReadinessService;
use app\service\FeasibilityReportService;
use app\service\KnowledgeDecisionGateService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\RevenueAiOverviewService;
use app\service\RevenueForecastReadinessService;
use app\service\RevenuePricingRecommendationService;
use think\Response;
use think\facade\Db;

trait AgentOtaDiagnosisBuildConcern
{
    private function resolveOtaDiagnosisConfig(string $platform, string $configId): array
    {
        $platform = strtolower(trim($platform));
        $configId = trim($configId);
        if (!in_array($platform, ['ctrip', 'meituan'], true)
            || preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) !== 1
            || !$this->tableExists('ota_credentials')) {
            return [];
        }

        $matches = Db::name('ota_credentials')
            ->where('platform', $platform)
            ->where('config_id', $configId)
            ->where('credential_status', 'ready')
            ->field('system_hotel_id,config_id')
            ->limit(2)
            ->select()
            ->toArray();
        if (count($matches) !== 1) {
            return [];
        }

        $hotelId = (int)($matches[0]['system_hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            return [];
        }
        $hotelName = (string)(Db::name('hotels')->where('id', $hotelId)->value('name') ?? '');
        return ['hotel_id' => $hotelId, 'hotel_name' => $hotelName];
    }

    /** @param array<string, bool> $columns */
    private function otaDiagnosisOnlineRowFields(array $columns): array
    {
        return array_values(array_intersect([
            'id',
            'hotel_id',
            'hotel_name',
            'system_hotel_id',
            'data_source_id',
            'sync_task_id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'raw_data',
            'readback_verified',
            'readback_verified_at',
            'validation_status',
            'source_trace_id',
            'create_time',
            'update_time',
        ], array_keys($columns)));
    }

    private function queryOtaDiagnosisData(int $hotelId, string $hotelIdRaw, string $platformHotelIdRaw, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $columns = $this->onlineDailyDataColumns();
        $fields = $this->otaDiagnosisOnlineRowFields($columns);
        $tenantId = $this->authoritativeTenantIdForHotel($hotelId);

        $onlineRows = [];
        $effectiveStartDate = $startDate;
        $effectiveEndDate = $endDate;
        $usedLatestAvailableData = false;
        $canQueryOnlineRows = !empty($fields)
            && $tenantId > 0
            && isset($columns['tenant_id'])
            && isset($columns['data_date'])
            && isset($columns['readback_verified'])
            && (($hotelId > 0 && isset($columns['system_hotel_id'])) || (($hotelIdRaw !== '' || $platformHotelIdRaw !== '') && isset($columns['hotel_id'])));
        if ($canQueryOnlineRows) {
            $applyOnlineScope = function ($query) use ($tenantId, $hotelId, $hotelIdRaw, $platformHotelIdRaw, $platform, $analysisType, $columns) {
                $query->where('tenant_id', $tenantId);
                if (isset($columns['source'])) {
                    $query->where('source', $platform);
                }
                $query->where(function ($q) use ($hotelId, $hotelIdRaw, $platformHotelIdRaw, $columns) {
                    $hasWhere = false;
                    if ($hotelId > 0 && isset($columns['system_hotel_id'])) {
                        $q->where('system_hotel_id', $hotelId);
                        $hasWhere = true;
                    }
                    if ($hotelIdRaw !== '' && isset($columns['hotel_id'])) {
                        $hasWhere ? $q->whereOr('hotel_id', $hotelIdRaw) : $q->where('hotel_id', $hotelIdRaw);
                        $hasWhere = true;
                    }
                    if ($platformHotelIdRaw !== '' && $platformHotelIdRaw !== $hotelIdRaw && isset($columns['hotel_id'])) {
                        $hasWhere ? $q->whereOr('hotel_id', $platformHotelIdRaw) : $q->where('hotel_id', $platformHotelIdRaw);
                    }
                });

                if (isset($columns['data_type']) && $analysisType === 'traffic') {
                    $query->where('data_type', 'traffic');
                } elseif (isset($columns['data_type']) && $analysisType === 'business') {
                    $query->whereIn('data_type', ['business', '']);
                }
                return $query;
            };
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where('data_date', '>=', $startDate)
                ->where('data_date', '<=', $endDate);
            $applyOnlineScope($query);

            $onlineRows = $query->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
            if (empty($onlineRows)) {
                $latestDateQuery = Db::name('online_daily_data');
                $applyOnlineScope($latestDateQuery);
                $latestDataDateRaw = (string) ($latestDateQuery->order('data_date', 'desc')->value('data_date') ?: '');
                $latestDataTime = $latestDataDateRaw !== '' ? strtotime($latestDataDateRaw) : false;
                $latestDataDate = $latestDataTime !== false ? date('Y-m-d', $latestDataTime) : '';
                if ($this->isDateString($latestDataDate)) {
                    $fallbackQuery = Db::name('online_daily_data')
                        ->field(implode(',', $fields))
                        ->where('data_date', $latestDataDate);
                    $applyOnlineScope($fallbackQuery);
                    $onlineRows = $fallbackQuery->order('data_date', 'asc')->order('id', 'asc')->select()->toArray();
                    if (!empty($onlineRows)) {
                        $effectiveStartDate = $latestDataDate;
                        $effectiveEndDate = $latestDataDate;
                        $usedLatestAvailableData = true;
                    }
                }
            }
        }

        $dailyReports = $this->queryHotelDateRows(
            'daily_reports',
            ['id', 'hotel_id', 'report_date', 'report_data', 'occupancy_rate', 'room_count', 'guest_count', 'revenue', 'expenses', 'notes', 'create_time', 'update_time'],
            $hotelId,
            'report_date',
            $effectiveStartDate,
            $effectiveEndDate,
            'report_date'
        );
        $competitorPrices = $this->queryHotelDateRows(
            'competitor_price_log',
            [
                'id', 'store_id', 'hotel_id', 'platform', 'city', 'price', 'fetch_time', 'create_time', 'update_time',
                'ota_hotel_id', 'collected_at', 'source_method', 'source_ref', 'validation_status', 'readback_verified',
                'failure_reason', 'check_in_date', 'check_out_date', 'nights', 'adults', 'children', 'room_type_key',
                'ota_product_id', 'rate_plan_key', 'package_name', 'breakfast', 'cancellation_policy', 'payment_mode',
                'tax_fee_included', 'price_basis', 'currency', 'availability', 'comparison_key',
            ],
            $hotelId,
            'create_time',
            $effectiveStartDate . ' 00:00:00',
            $effectiveEndDate . ' 23:59:59',
            'fetch_time',
            function ($query, array $tableColumns) use ($platform): void {
                if (isset($tableColumns['platform'])) {
                    $query->where('platform', $platform);
                }
            },
            'asc',
            0,
            'store_id'
        );
        $competitorAnalyses = $this->queryHotelDateRows(
            'competitor_analysis',
            [
                'id', 'hotel_id', 'competitor_hotel_id', 'room_type_id', 'analysis_date', 'our_price', 'competitor_price',
                'price_difference', 'price_index', 'ota_platform', 'competitor_data', 'create_time', 'update_time',
                'collected_at', 'source_method', 'source_ref', 'validation_status', 'readback_verified', 'failure_reason',
                'check_in_date', 'check_out_date', 'nights', 'adults', 'children', 'room_type_key', 'rate_plan_key',
                'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis', 'currency',
                'availability', 'comparison_key',
            ],
            $hotelId,
            'analysis_date',
            $effectiveStartDate,
            $effectiveEndDate,
            'analysis_date',
            function ($query, array $tableColumns) use ($platform): void {
                $platformCode = $this->otaPlatformCode($platform);
                if ($platformCode !== null && isset($tableColumns['ota_platform'])) {
                    $query->where('ota_platform', $platformCode);
                }
            }
        );
        $priceSuggestions = $this->queryHotelDateRows(
            'price_suggestions',
            ['id', 'hotel_id', 'room_type_id', 'suggestion_date', 'suggestion_type', 'current_price', 'suggested_price', 'min_price', 'max_price', 'competitor_data', 'factors', 'status', 'create_time', 'update_time'],
            $hotelId,
            'suggestion_date',
            $effectiveStartDate,
            $effectiveEndDate,
            'suggestion_date'
        );
        $syncLogs = $this->queryHotelDateRows(
            'operation_logs',
            ['id', 'hotel_id', 'module', 'action', 'description', 'create_time', 'error_info'],
            $hotelId,
            'create_time',
            $effectiveStartDate . ' 00:00:00',
            $effectiveEndDate . ' 23:59:59',
            'create_time',
            function ($query, array $tableColumns): void {
                if (isset($tableColumns['module'])) {
                    $query->where('module', 'online_data');
                }
            },
            'desc',
            10
        );
        $hotelFields = $this->existingFields('hotels', ['id', 'name', 'code', 'address', 'status']);
        $hotel = $hotelId > 0 && !empty($hotelFields)
            ? (Db::name('hotels')->field(implode(',', $hotelFields))->where('id', $hotelId)->find() ?: [])
            : [];
        $lastSyncTime = $this->maxDateTime(array_merge(
            array_column($onlineRows, 'update_time'),
            array_column($onlineRows, 'create_time'),
            array_column($dailyReports, 'update_time'),
            array_column($competitorPrices, 'fetch_time'),
            array_column($competitorPrices, 'update_time'),
            array_column($competitorAnalyses, 'update_time'),
            array_column($competitorAnalyses, 'create_time'),
            array_column($priceSuggestions, 'update_time'),
            array_column($priceSuggestions, 'create_time'),
            array_column($syncLogs, 'create_time')
        ));

        $decisionEligibleOnlineRows = array_values(array_filter(
            $onlineRows,
            fn(array $row): bool => $this->isOtaDiagnosisDecisionEligibleRow($row)
        ));
        $excludedOnlineRows = array_values(array_filter(
            $onlineRows,
            fn(array $row): bool => !$this->isOtaDiagnosisDecisionEligibleRow($row)
        ));
        $excludedQualityStatuses = [];
        foreach ($excludedOnlineRows as $row) {
            $status = $this->otaDiagnosisRowQualityStatus($row);
            $excludedQualityStatuses[$status] = ($excludedQualityStatuses[$status] ?? 0) + 1;
        }
        ksort($excludedQualityStatuses);

        return [
            'hotel' => $hotel ?: ['id' => $hotelIdRaw, 'name' => ''],
            'online_rows' => $onlineRows,
            'decision_eligible_online_rows' => $decisionEligibleOnlineRows,
            'excluded_online_rows' => $excludedOnlineRows,
            'decision_quality' => [
                'visible_row_count' => count($onlineRows),
                'eligible_row_count' => count($decisionEligibleOnlineRows),
                'excluded_row_count' => count($excludedOnlineRows),
                'excluded_quality_statuses' => $excludedQualityStatuses,
                'gate' => $decisionEligibleOnlineRows === []
                    ? 'insufficient_evidence'
                    : ($excludedOnlineRows === [] ? 'all_visible_rows_eligible' : 'eligible_rows_only'),
            ],
            'daily_reports' => $dailyReports,
            'competitor_prices' => $competitorPrices,
            'competitor_analyses' => $competitorAnalyses,
            'price_suggestions' => $priceSuggestions,
            'sync_logs' => $syncLogs,
            'last_sync_time' => $lastSyncTime,
            'effective_start_date' => $effectiveStartDate,
            'effective_end_date' => $effectiveEndDate,
            'used_latest_available_data' => $usedLatestAvailableData,
        ];
    }

    private function hasOtaDiagnosisData(array $dataSet): bool
    {
        return !empty($dataSet['online_rows']);
    }

    private function isOtaDiagnosisDecisionEligibleRow(array $row): bool
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return false;
        }

        return in_array($this->otaDiagnosisRowQualityStatus($row), [
            'normal',
            'available',
            'ok',
            'valid',
            'verified',
        ], true);
    }

    private function otaDiagnosisRowQualityStatus(array $row): string
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return 'readback_unverified';
        }

        $status = strtolower(trim((string)($row['validation_status'] ?? 'unverified')));
        return $status !== '' ? $status : 'unverified';
    }

    private function buildOtaDiagnosisNoDataResult(array $dataSet, string $hotelIdRaw, string $hotelName, string $platform, string $startDate, string $endDate): array
    {
        $sourceCounts = [
            'online_rows' => 0,
            'daily_reports' => 0,
            'competitor_prices' => 0,
            'competitor_analyses' => 0,
            'price_suggestions' => 0,
            'sync_logs' => count($dataSet['sync_logs'] ?? []),
        ];
        $missingSections = ['OTA历史数据', 'OTA流量数据', '竞对数据', '价格/房态/订单相关数据', '日报经营数据'];
        $dataGaps = [[
            'code' => 'ota_same_period_source_rows_missing',
            'message' => '选定日期范围没有可用于 OTA 经营诊断的真实入库数据。',
            'scope' => 'ota_channel',
            'blocked_conclusions' => ['收入诊断', '流量诊断', '转化诊断', '价格/竞对诊断', '广告和服务质量诊断'],
            'next_action' => '默认使用携程/美团浏览器 Profile 采集入口补齐同日 OTA 数据后重新诊断；手动 Cookie/API 仅作临时补数或排障。',
        ]];
        $evidenceSources = [[
            'ref' => 'ota_no_data_scope',
            'table' => 'derived',
            'record_id' => null,
            'date' => $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate,
            'tags' => ['scope', 'missing_data', 'ota_channel'],
            'label' => 'OTA诊断无数据范围证据',
            'metrics' => [
                'online_rows' => 0,
                'sync_logs' => $sourceCounts['sync_logs'],
            ],
        ]];
        $actions = ['默认使用携程/美团浏览器 Profile 采集入口补齐同日 OTA 数据，再重新生成 AI 诊断和运营执行动作；手动 Cookie/API 仅作临时补数或排障。'];
        $actionItems = [[
            'id' => 'ota_action_collect_same_period_data',
            'action' => $actions[0],
            'status' => 'blocked_by_missing_ota_data',
            'evidence_refs' => ['ota_no_data_scope'],
            'required_evidence' => ['same_period_ota_data'],
            'missing_evidence' => [[
                'code' => 'missing_same_period_ota_data',
                'label' => '同日 OTA 入库数据',
                'next_action' => '默认使用携程/美团浏览器 Profile 采集入口补齐同日 OTA 数据后重新诊断；手动 Cookie/API 仅作临时补数或排障。',
            ]],
            'execution_ready' => false,
            'can_request_execution_intent' => false,
            'human_confirmation_required' => true,
            'human_confirmation_status' => 'blocked',
            'blocked_reason' => 'missing same-period OTA evidence',
            'source_policy' => 'must collect same-period OTA evidence before diagnosis or execution',
            'owner' => '酒店运营人员',
            'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
        ]];
        $diagnosis = [
            'summary' => '暂无该酒店在该日期范围内的 OTA 数据，不能生成可信经营诊断。',
            'exposure_analysis' => '',
            'visit_conversion_analysis' => '',
            'order_conversion_analysis' => '',
            'price_analysis' => '',
            'competitor_analysis' => '',
            'advertising_analysis' => '',
            'service_quality_analysis' => '',
            'comment_analysis' => '',
            'actions' => $actions,
        ];

        $result = [
            'hotel' => $dataSet['hotel'] ?? ['id' => $hotelIdRaw, 'name' => $hotelName],
            'platform' => $platform,
            'date_range' => ['start_date' => $startDate, 'end_date' => $endDate],
            'data_summary' => [
                'has_ota_data' => false,
                'has_traffic_data' => false,
                'has_competitor_data' => false,
                'has_comment_data' => false,
                'has_advertising_data' => false,
                'has_service_quality_data' => false,
                'has_price_order_data' => false,
                'has_daily_report_data' => false,
                'last_sync_time' => $dataSet['last_sync_time'] ?? '',
                'source_counts' => $sourceCounts,
            ],
            'metrics' => [],
            'data_gaps' => $dataGaps,
            'diagnosis' => $diagnosis,
            'missing_sections' => $missingSections,
            'core_conclusion' => $diagnosis['summary'],
            'main_problems' => [],
            'possible_reasons' => [],
            'recommended_actions' => $actions,
            'data_anomalies_needing_confirmation' => $missingSections,
            'evidence_sources' => $evidenceSources,
            'action_items' => $actionItems,
            'diagnosis_sections' => $this->buildOtaDiagnosisSections($diagnosis, $missingSections),
            'priority' => 'none',
            'source_policy' => 'database_only_no_synthetic_conclusion',
        ];
        $result['ai_governance'] = $this->buildAiGovernancePayload('ota_diagnosis', $result, [
            'ok' => true,
            'data' => [
                'governance' => [
                    'status' => 'skipped',
                    'prompt_version' => 'ota_diagnosis.no_data.v1',
                ],
            ],
        ]);
        return $this->finalizeOtaDiagnosisDecision($result);
    }

    private function buildOtaDiagnosisResult(array $dataSet, int $hotelId, string $hotelIdRaw, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $visibleRows = is_array($dataSet['online_rows'] ?? null) ? $dataSet['online_rows'] : [];
        // Production queryOtaDiagnosisData always provides the gated list.
        // Falling back to the supplied rows keeps this pure builder usable for
        // already-gated in-memory callers and focused unit tests.
        $rows = array_key_exists('decision_eligible_online_rows', $dataSet)
            ? (is_array($dataSet['decision_eligible_online_rows']) ? $dataSet['decision_eligible_online_rows'] : [])
            : $visibleRows;
        $dailyReports = $dataSet['daily_reports'] ?? [];
        $competitorPrices = $dataSet['competitor_prices'] ?? [];
        $competitorAnalyses = $dataSet['competitor_analyses'] ?? [];
        $priceSuggestions = $dataSet['price_suggestions'] ?? [];
        $syncLogs = $dataSet['sync_logs'] ?? [];
        $summary = $this->buildOtaDiagnosisSummary($rows, $hotelId, $hotelName, $platform, $startDate, $endDate, $analysisType);
        $totals = $summary['totals'];
        $rates = $summary['derived_rates'];
        // Legacy competitor price rows do not carry a complete comparison key
        // (stay dates, room/rate plan, meal, cancellation, tax and currency).
        // Keep them visible as reference records, but do not turn them into a
        // price average or an automated price conclusion.
        $comparableCompetitorPrices = $this->otaDiagnosisComparableCompetitorPrices($competitorPrices, $competitorAnalyses);
        $avgCompetitorPrice = $this->nullableAverage($comparableCompetitorPrices);
        $avgSuggestedPrice = $this->nullableAverage(array_values(array_filter(
            array_column($priceSuggestions, 'suggested_price'),
            static fn(mixed $value): bool => is_numeric($value) && (float)$value > 0
        )));
        $dailyRevenue = $dailyReports === [] ? null : array_sum(array_map('floatval', array_column($dailyReports, 'revenue')));
        $hasTraffic = $this->hasKnownOtaDiagnosisMetric($totals, [
            'list_exposure', 'detail_visitors', 'flow_rate', 'order_visitors', 'submit_users',
        ]);
        $hasComment = false;
        $hasAdvertising = (int)($totals['advertising_rows'] ?? 0) > 0;
        $hasServiceQuality = (int)($totals['service_quality_rows'] ?? 0) > 0;
        $hasCompetitor = !empty($competitorPrices) || !empty($competitorAnalyses) || $this->hasCompareRows($rows);
        $hasPriceOrder = $this->hasKnownOtaDiagnosisMetric($totals, ['amount', 'quantity', 'book_order_num'])
            || !empty($priceSuggestions);
        $hasDaily = !empty($dailyReports);
        $missingSections = [];
        if (!$hasTraffic) {
            $missingSections[] = 'OTA流量数据';
        }
        if (!$hasCompetitor) {
            $missingSections[] = '竞对数据';
        }
        if (!$hasPriceOrder) {
            $missingSections[] = '价格/房态/订单相关数据';
        }
        if (!$hasDaily) {
            $missingSections[] = '日报经营数据';
        }
        if (empty($syncLogs) && ($dataSet['last_sync_time'] ?? '') === '') {
            $missingSections[] = '抓取日志/最近同步时间';
        }

        $metrics = [
            'record_count' => count($rows),
            'date_count' => $summary['date_count'],
            'amount' => $totals['amount'] === null ? null : round((float)$totals['amount'], 2),
            'quantity' => $totals['quantity'] === null ? null : (int)$totals['quantity'],
            'book_order_num' => $totals['book_order_num'] === null ? null : (int)$totals['book_order_num'],
            'adr' => $summary['averages']['adr'],
            'list_exposure' => $totals['list_exposure'] === null ? null : (int)$totals['list_exposure'],
            'detail_visitors' => $totals['detail_visitors'] === null ? null : (int)$totals['detail_visitors'],
            'flow_rate' => $totals['flow_rate'] === null ? null : round((float)$totals['flow_rate'], 4),
            'order_visitors' => $totals['order_visitors'] === null ? null : (int)$totals['order_visitors'],
            'submit_users' => $totals['submit_users'] === null ? null : (int)$totals['submit_users'],
            'detail_rate' => $rates['detail_rate'],
            'order_rate' => $rates['order_rate'],
            'submit_rate' => $rates['submit_rate'],
            'comment_score' => null,
            'qunar_comment_score' => null,
            'advertising_spend' => $totals['advertising_spend'] === null ? null : round((float)$totals['advertising_spend'], 2),
            'advertising_order_amount' => $totals['advertising_order_amount'] === null ? null : round((float)$totals['advertising_order_amount'], 2),
            'advertising_bookings' => $totals['advertising_bookings'] === null ? null : (int)$totals['advertising_bookings'],
            'advertising_room_nights' => $totals['advertising_room_nights'] === null ? null : round((float)$totals['advertising_room_nights'], 2),
            'advertising_impressions' => $totals['advertising_impressions'] === null ? null : (int)round((float)$totals['advertising_impressions']),
            'advertising_clicks' => $totals['advertising_clicks'] === null ? null : (int)round((float)$totals['advertising_clicks']),
            'advertising_roas' => $summary['averages']['advertising_roas'],
            'avg_psi_score' => $summary['averages']['avg_psi_score'],
            'avg_service_score' => $summary['averages']['avg_service_score'],
            'avg_im_score' => $summary['averages']['avg_im_score'],
            'avg_reply_rate' => $summary['averages']['avg_reply_rate'],
            'hotel_collect' => $totals['hotel_collect'] === null ? null : (int)$totals['hotel_collect'],
            'daily_report_revenue' => $dailyRevenue === null ? null : round($dailyRevenue, 2),
            'competitor_avg_price' => $avgCompetitorPrice,
            'suggested_avg_price' => $avgSuggestedPrice,
            'daily_report_count' => count($dailyReports),
            'competitor_price_count' => count($competitorPrices),
            'competitor_analysis_count' => count($competitorAnalyses),
            'price_suggestion_count' => count($priceSuggestions),
            'sync_log_count' => count($syncLogs),
        ];
        $abnormal = $summary['data_anomalies'];
        if ($hasTraffic && $metrics['list_exposure'] !== null && (float)$metrics['list_exposure'] === 0.0) {
            $abnormal[] = 'OTA列表曝光为0';
        }
        if ($hasTraffic && (float)($metrics['list_exposure'] ?? 0) > 0 && is_numeric($rates['detail_rate']) && $rates['detail_rate'] < 5) {
            $abnormal[] = '曝光到访问转化偏低';
        }
        if ($hasTraffic && (float)($metrics['detail_visitors'] ?? 0) > 0 && is_numeric($rates['order_rate']) && $rates['order_rate'] < 3) {
            $abnormal[] = '访问到订单转化偏低';
        }
        if ($hasAdvertising && (float)($metrics['advertising_roas'] ?? 0) > 0 && (float)$metrics['advertising_roas'] < 3) {
            $abnormal[] = 'OTA广告ROAS低于3';
        }
        if ($hasServiceQuality && (float)($metrics['avg_psi_score'] ?? 0) > 0 && (float)$metrics['avg_psi_score'] < 85) {
            $abnormal[] = 'OTA服务质量分低于85';
        }

        $displayHotelName = trim((string) ($dataSet['hotel']['name'] ?? ''));
        if ($displayHotelName === '') {
            $displayHotelName = $hotelName !== '' ? $hotelName : $hotelIdRaw;
        }
        $abnormal = array_values(array_unique($abnormal));
        if ($visibleRows !== [] && $rows === []) {
            $summary['data_gaps'][] = [
                'code' => 'ota_rows_excluded_by_quality',
                'message' => '已找到入库记录，但没有同时通过质量状态与保存回读门禁的证据。',
                'scope' => 'ota_channel',
                'blocked_conclusions' => ['经营汇总', '异常判断', '运营动作'],
                'next_action' => '修复采集或字段校验并完成保存回读后重新诊断。',
            ];
        }
        $blockingDataGaps = $this->blockingOtaDiagnosisDataGaps($summary['data_gaps'] ?? []);
        $diagnosis = [
            'summary' => sprintf('已读取%s在%s至%s的历史OTA数据；%d条记录可用于诊断，%d条因质量或回读证据不足仅保留展示，另有%d条日报、%d条竞对价格参考记录。', $displayHotelName, $startDate, $endDate, count($rows), max(0, count($visibleRows) - count($rows)), count($dailyReports), count($competitorPrices)),
            'data_overview' => [
                'OTA记录数: ' . count($rows),
                '日期覆盖: ' . $summary['date_count'] . ' 天',
                '收入: ' . $this->formatOtaDiagnosisMetric($metrics['amount']),
                '间夜: ' . $this->formatOtaDiagnosisMetric($metrics['quantity']),
                '订单: ' . $this->formatOtaDiagnosisMetric($metrics['book_order_num']),
            ],
            'abnormal_metrics' => $abnormal,
            'traffic_analysis' => $hasTraffic ? sprintf('曝光%s，访问%s，曝光到访问率%s。', $this->formatOtaDiagnosisMetric($metrics['list_exposure']), $this->formatOtaDiagnosisMetric($metrics['detail_visitors']), $this->formatOtaDiagnosisMetric($metrics['detail_rate'], '%')) : '缺少OTA流量数据，无法判断曝光和访问漏斗。',
            'exposure_analysis' => $hasTraffic ? sprintf('曝光%s，访问%s，曝光到访问率%s。', $this->formatOtaDiagnosisMetric($metrics['list_exposure']), $this->formatOtaDiagnosisMetric($metrics['detail_visitors']), $this->formatOtaDiagnosisMetric($metrics['detail_rate'], '%')) : '缺少OTA流量数据，无法判断曝光表现。',
            'visit_conversion_analysis' => $hasTraffic ? sprintf('访问%s，订单意向%s，访问到订单率%s。', $this->formatOtaDiagnosisMetric($metrics['detail_visitors']), $this->formatOtaDiagnosisMetric($metrics['order_visitors']), $this->formatOtaDiagnosisMetric($metrics['order_rate'], '%')) : '缺少访问转化数据。',
            'order_conversion_analysis' => $hasTraffic ? sprintf('订单意向%s，提交用户%s，提交率%s。', $this->formatOtaDiagnosisMetric($metrics['order_visitors']), $this->formatOtaDiagnosisMetric($metrics['submit_users']), $this->formatOtaDiagnosisMetric($metrics['submit_rate'], '%')) : '缺少订单转化数据。',
            'price_analysis' => $avgCompetitorPrice !== null ? sprintf('同一可比条件下的竞对公开价均值%s；该值仅代表指定入住条件的OTA公开售卖价，不与全酒店ADR直接比较。', $avgCompetitorPrice) : ($avgSuggestedPrice !== null ? sprintf('已有%d条定价建议，建议均价%s；当前竞对记录缺少完整可比条件，不能据此计算价差。', count($priceSuggestions), $avgSuggestedPrice) : '缺少通过可比性门禁的价格记录，暂不能判断价格竞争力。'),
            'competitor_analysis' => $hasCompetitor ? '已有竞对或对比数据，可继续关注价格、曝光和转化差距。' : '缺少竞对数据，无法判断同商圈机会。',
            'advertising_analysis' => $hasAdvertising ? sprintf('OTA广告花费%s，归因订单金额%s，ROAS %s。', $this->formatOtaDiagnosisMetric($metrics['advertising_spend']), $this->formatOtaDiagnosisMetric($metrics['advertising_order_amount']), $this->formatOtaDiagnosisMetric($metrics['advertising_roas'])) : '缺少OTA广告数据，暂不评估投放效率。',
            'service_quality_analysis' => $hasServiceQuality ? sprintf('OTA服务质量分%s，服务评分%s。', $this->formatOtaDiagnosisMetric($metrics['avg_psi_score']), $this->formatOtaDiagnosisMetric($metrics['avg_service_score'])) : '缺少OTA服务质量数据，暂不评估服务质量对转化的影响。',
            'comment_analysis' => '',
            'actions' => $this->buildOtaDiagnosisActions($hasTraffic, $hasCompetitor, $hasAdvertising, $hasServiceQuality, $metrics, $summary['data_gaps'] ?? []),
        ];

        return [
            'hotel' => $dataSet['hotel'] ?: ['id' => $hotelIdRaw, 'name' => $hotelName],
            'platform' => $platform,
            'date_range' => ['start_date' => $startDate, 'end_date' => $endDate],
            'data_summary' => [
                'has_ota_data' => !empty($rows),
                'has_traffic_data' => $hasTraffic,
                'has_competitor_data' => $hasCompetitor,
                'has_comment_data' => $hasComment,
                'has_advertising_data' => $hasAdvertising,
                'has_service_quality_data' => $hasServiceQuality,
                'has_price_order_data' => $hasPriceOrder,
                'has_daily_report_data' => $hasDaily,
                'core_metrics_complete' => empty($blockingDataGaps),
                'last_sync_time' => $dataSet['last_sync_time'] ?? '',
                'source_counts' => [
                    'online_rows' => count($rows),
                    'online_rows_visible' => count($visibleRows),
                    'online_rows_excluded_from_decision' => max(0, count($visibleRows) - count($rows)),
                    'daily_reports' => count($dailyReports),
                    'competitor_prices' => count($competitorPrices),
                    'competitor_analyses' => count($competitorAnalyses),
                    'price_suggestions' => count($priceSuggestions),
                    'sync_logs' => count($syncLogs),
                ],
            ],
            'metrics' => $metrics,
            'decision_quality' => $dataSet['decision_quality'] ?? [
                'visible_row_count' => count($visibleRows),
                'eligible_row_count' => count($rows),
                'excluded_row_count' => max(0, count($visibleRows) - count($rows)),
                'excluded_quality_statuses' => [],
                'gate' => $rows === [] ? 'insufficient_evidence' : 'eligible_rows_only',
            ],
            'diagnosis' => $diagnosis,
            'diagnosis_sections' => $this->buildOtaDiagnosisSections($diagnosis, array_values(array_unique($missingSections))),
            'missing_sections' => array_values(array_unique($missingSections)),
            'data_gaps' => $summary['data_gaps'] ?? [],
            'blocking_data_gaps' => $blockingDataGaps,
            'derived_metric_lineage' => $this->buildOtaDerivedMetricLineage($metrics),
            'priority' => empty($abnormal) && empty($blockingDataGaps)
                ? 'none'
                : (in_array('访问到订单转化偏低', $abnormal, true) || in_array('曝光到访问转化偏低', $abnormal, true) ? 'high' : 'medium'),
            'source_policy' => 'database_only_real_rows_and_derived_metrics',
            'source_summary' => $summary,
        ];
    }

    private function buildOtaDiagnosisActions(bool $hasTraffic, bool $hasCompetitor, bool $hasAdvertising, bool $hasServiceQuality, array $metrics, array $dataGaps = []): array
    {
        $actions = [];
        if ($hasTraffic && array_key_exists('list_exposure', $metrics) && $metrics['list_exposure'] !== null && (float)$metrics['list_exposure'] === 0.0) {
            $actions[] = '检查目标日期门店可售状态、列表页内容完整性和平台曝光入口，确认目标平台列表曝光为0的原因。';
        }
        if ($hasTraffic && (float)($metrics['list_exposure'] ?? 0) > 0 && is_numeric($metrics['detail_rate'] ?? null) && (float)$metrics['detail_rate'] < 5) {
            $actions[] = '优先优化列表页主图、标题卖点和页面信息呈现，提升曝光到访问转化。';
        }
        if ($hasTraffic && (float)($metrics['detail_visitors'] ?? 0) > 0 && is_numeric($metrics['order_rate'] ?? null) && (float)$metrics['order_rate'] < 3) {
            $actions[] = '检查详情页房型、取消政策、促销和价格阶梯，降低访问后的下单阻力。';
        }
        if ($hasAdvertising && (float)($metrics['advertising_roas'] ?? 0) > 0 && (float)$metrics['advertising_roas'] < 3) {
            $actions[] = '复核OTA广告投放词、出价和落地房型，ROAS低于3时先控预算再优化转化链路。';
        }
        if ($hasServiceQuality && (float)($metrics['avg_psi_score'] ?? 0) > 0 && (float)$metrics['avg_psi_score'] < 85) {
            $actions[] = '把OTA服务质量分作为转化背景信号，先排查服务响应、到店履约和平台服务质量扣分项。';
        }
        if (empty($actions) && !empty($this->blockingOtaDiagnosisDataGaps($dataGaps))) {
            $actions[] = '先补齐缺失的数据源，再按曝光、访问、订单、广告效率、服务质量顺序复盘。';
        }
        return $actions;
    }

    private function applyOtaDiagnosisRuleEvidenceGuard(array $candidate, array $ruleDiagnosis): array
    {
        // LLM 只负责解释真实证据；异常和可执行动作必须来自可复核的系统规则。
        $candidate['abnormal_metrics'] = array_values(array_filter(array_map(
            'strval',
            (array)($ruleDiagnosis['abnormal_metrics'] ?? [])
        )));
        $candidate['actions'] = array_values(array_filter(array_map(
            'strval',
            (array)($ruleDiagnosis['actions'] ?? [])
        )));

        return $candidate;
    }

    private function normalizeOtaDiagnosisDataGaps(mixed $dataGaps): array
    {
        $items = is_array($dataGaps) && (empty($dataGaps) || array_is_list($dataGaps))
            ? $dataGaps
            : [$dataGaps];
        $normalized = [];
        foreach ($items as $index => $gap) {
            if (is_array($gap)) {
                $code = trim((string)($gap['code'] ?? $gap['key'] ?? ''));
                if ($code === '') {
                    $code = 'ota_data_gap_' . ($index + 1);
                }
                $gap['code'] = $code;
                $gap['message'] = trim((string)($gap['message'] ?? $gap['label'] ?? $code));
                $gap['scope'] = trim((string)($gap['scope'] ?? 'ota_channel'));
                $normalized[] = $gap;
                continue;
            }

            $code = trim((string)$gap);
            if ($code === '') {
                continue;
            }
            $normalized[] = [
                'code' => $code,
                'message' => str_starts_with($code, 'metric_missing:')
                    ? '指标未返回：' . substr($code, strlen('metric_missing:'))
                    : $code,
                'scope' => 'ota_channel',
                'next_action' => '补齐目标日期对应的真实 OTA 数据后重新生成诊断。',
            ];
        }

        $seen = [];
        return array_values(array_filter($normalized, static function (array $gap) use (&$seen): bool {
            $key = (string)($gap['code'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));
    }

    private function blockingOtaDiagnosisDataGaps(mixed $dataGaps): array
    {
        $coreMetricCodes = array_fill_keys(array_map(
            static fn(string $field): string => 'metric_missing:' . $field,
            ['amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_visitors', 'flow_rate', 'order_visitors', 'submit_users']
        ), true);

        $blocking = [];
        foreach ($this->normalizeOtaDiagnosisDataGaps($dataGaps) as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            $isMetricGap = str_starts_with($code, 'metric_missing:');
            if (($isMetricGap && !isset($coreMetricCodes[$code])) || $code === '') {
                continue;
            }
            $gap['status'] = 'blocked_by_data_gap';
            $gap['blocking'] = true;
            $blocking[] = $gap;
        }

        return $blocking;
    }

    private function buildOtaDerivedMetricLineage(array $metrics): array
    {
        $definitions = [
            'adr' => ['formula' => 'amount / quantity', 'source_fields' => ['online_daily_data.amount', 'online_daily_data.quantity']],
            'detail_rate' => ['formula' => 'detail_visitors / list_exposure * 100', 'source_fields' => ['online_daily_data.detail_exposure', 'online_daily_data.list_exposure']],
            'order_rate' => ['formula' => 'order_visitors / detail_visitors * 100', 'source_fields' => ['online_daily_data.order_filling_num', 'online_daily_data.detail_exposure']],
            'submit_rate' => ['formula' => 'submit_users / order_visitors * 100', 'source_fields' => ['online_daily_data.order_submit_num', 'online_daily_data.order_filling_num']],
            'advertising_roas' => ['formula' => 'advertising_order_amount / advertising_spend', 'source_fields' => ['online_daily_data.raw_data.orderAmount', 'online_daily_data.amount']],
        ];
        $lineage = [];
        foreach ($definitions as $metric => $definition) {
            if (!array_key_exists($metric, $metrics) || $metrics[$metric] === null || $metrics[$metric] === '') {
                continue;
            }
            $lineage[] = [
                'metric' => $metric,
                'value' => $metrics[$metric],
                'formula' => $definition['formula'],
                'source_fields' => $definition['source_fields'],
                'source_scope' => 'selected_hotel_platform_and_date_range',
                'evidence_ref' => 'source_summary',
            ];
        }

        return $lineage;
    }

    private function buildOtaDiagnosisEvidenceSources(array $dataSet, array $metrics = []): array
    {
        $sources = [[
            'ref' => 'source_summary',
            'table' => 'derived',
            'record_id' => null,
            'date' => '',
            'tags' => ['summary'],
            'label' => '本次诊断聚合指标',
            'metrics' => $this->buildOtaEvidenceMetricPreview($metrics),
            'quality_status' => (string)($dataSet['decision_quality']['gate'] ?? 'unknown'),
            'decision_eligible' => false,
        ]];

        $eligibleRows = array_key_exists('decision_eligible_online_rows', $dataSet)
            ? (is_array($dataSet['decision_eligible_online_rows']) ? $dataSet['decision_eligible_online_rows'] : [])
            : (is_array($dataSet['online_rows'] ?? null) ? $dataSet['online_rows'] : []);
        usort($eligibleRows, static function (array $left, array $right): int {
            $dateCompare = strcmp((string)($right['data_date'] ?? ''), (string)($left['data_date'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : ((int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0));
        });
        foreach (array_slice($eligibleRows, 0, 20) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = array_merge([
                'ref' => 'online_daily_data#' . (string)($row['id'] ?? ''),
                'table' => 'online_daily_data',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['data_date'] ?? ''),
                'tags' => $this->buildOtaEvidenceTags('online_daily_data', $row),
                'label' => trim(implode(' ', array_filter([(string)($row['source'] ?? ''), (string)($row['data_type'] ?? ''), (string)($row['compare_type'] ?? '')]))),
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
                'decision_eligible' => true,
            ], $this->buildOtaDiagnosisEvidenceMetadata($row));
        }

        foreach (array_slice(is_array($dataSet['excluded_online_rows'] ?? null) ? $dataSet['excluded_online_rows'] : [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = array_merge([
                'ref' => 'online_daily_data_excluded#' . (string)($row['id'] ?? ''),
                'table' => 'online_daily_data',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['data_date'] ?? ''),
                'tags' => ['excluded_from_decision', 'quality_gap', 'ota_channel'],
                'label' => '不可用于诊断的入库记录（仅展示质量状态）',
                'metrics' => [],
                'excluded_from_decision' => true,
                'decision_eligible' => false,
            ], $this->buildOtaDiagnosisEvidenceMetadata($row));
        }

        foreach (array_slice($dataSet['daily_reports'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'daily_reports#' . (string)($row['id'] ?? ''),
                'table' => 'daily_reports',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['report_date'] ?? ''),
                'tags' => ['daily', 'revenue'],
                'label' => '日报经营数据',
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
                'quality_status' => 'internal_persisted_report',
                'decision_eligible' => true,
            ];
        }

        foreach (array_slice($dataSet['competitor_prices'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comparisonKey = $this->otaDiagnosisCompetitorComparisonKey($row);
            $eligible = $comparisonKey !== '';
            $sources[] = array_merge([
                'ref' => 'competitor_price_log#' . (string)($row['id'] ?? ''),
                'table' => 'competitor_price_log',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['fetch_time'] ?? $row['create_time'] ?? ''),
                'tags' => $eligible ? ['competitor', 'price'] : ['excluded_from_decision', 'quality_gap', 'competitor_reference'],
                'label' => (string)($row['platform'] ?? 'competitor_price'),
                'metrics' => $eligible ? $this->buildOtaEvidenceMetricPreview($row) : [],
                'decision_eligible' => $eligible,
                'excluded_from_decision' => !$eligible,
                'comparison_key' => $comparisonKey,
            ], $this->buildOtaDiagnosisEvidenceMetadata($row));
        }

        foreach (array_slice($dataSet['competitor_analyses'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comparisonKey = $this->otaDiagnosisCompetitorComparisonKey($row);
            $eligible = $comparisonKey !== '';
            $sources[] = array_merge([
                'ref' => 'competitor_analysis#' . (string)($row['id'] ?? ''),
                'table' => 'competitor_analysis',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['analysis_date'] ?? ''),
                'tags' => $eligible ? ['competitor', 'price'] : ['excluded_from_decision', 'quality_gap', 'competitor_reference'],
                'label' => '竞对价格分析',
                'metrics' => $eligible ? $this->buildOtaEvidenceMetricPreview($row) : [],
                'decision_eligible' => $eligible,
                'excluded_from_decision' => !$eligible,
                'comparison_key' => $comparisonKey,
            ], $this->buildOtaDiagnosisEvidenceMetadata($row));
        }

        foreach (array_slice($dataSet['price_suggestions'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'price_suggestions#' . (string)($row['id'] ?? ''),
                'table' => 'price_suggestions',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['suggestion_date'] ?? $row['create_time'] ?? ''),
                'tags' => ['price', 'suggestion'],
                'label' => '收益价格建议',
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
                'quality_status' => 'derived_suggestion',
                'decision_eligible' => false,
            ];
        }

        foreach (array_slice($dataSet['sync_logs'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'operation_logs#' . (string)($row['id'] ?? ''),
                'table' => 'operation_logs',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['create_time'] ?? ''),
                'tags' => ['sync_log', 'collection'],
                'label' => (string)($row['action'] ?? 'online_data_log'),
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
                'quality_status' => 'process_log_only',
                'decision_eligible' => false,
            ];
        }

        return array_values(array_filter($sources, static fn(array $source): bool => (string)($source['ref'] ?? '') !== '#'));
    }

    /** @return array<string,mixed> */
    private function buildOtaDiagnosisEvidenceMetadata(array $row): array
    {
        $raw = [];
        if (is_array($row['raw_data'] ?? null)) {
            $raw = $row['raw_data'];
        } elseif (is_string($row['raw_data'] ?? null) && trim((string)$row['raw_data']) !== '') {
            $decoded = json_decode((string)$row['raw_data'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $captureMeta = is_array($raw['capture_meta'] ?? null)
            ? $raw['capture_meta']
            : (is_array($raw['captureMeta'] ?? null) ? $raw['captureMeta'] : []);
        $firstText = static function (array $values): string {
            foreach ($values as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
            return '';
        };

        return [
            'platform' => strtolower(trim((string)($row['source'] ?? $row['platform'] ?? ''))),
            'system_hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'platform_hotel_id' => trim((string)($row['hotel_id'] ?? '')),
            'date_role' => trim((string)($row['date_role'] ?? 'target')),
            'quality_status' => $this->otaDiagnosisRowQualityStatus($row),
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1,
            'readback_verified_at' => (string)($row['readback_verified_at'] ?? ''),
            'source_trace_id' => trim((string)($row['source_trace_id'] ?? '')),
            'captured_at' => $firstText([
                $row['collected_at'] ?? null,
                $captureMeta['captured_at'] ?? null,
                $captureMeta['collected_at'] ?? null,
                $row['create_time'] ?? null,
                $row['update_time'] ?? null,
            ]),
            'source_method' => $firstText([
                $row['source_method'] ?? null,
                $captureMeta['source_method'] ?? null,
                $captureMeta['method'] ?? null,
                $raw['source_method'] ?? null,
            ]),
            'source_url' => $firstText([
                $row['source_url'] ?? null,
                $row['source_ref'] ?? null,
                $captureMeta['source_url'] ?? null,
                $captureMeta['page_url'] ?? null,
                $raw['source_url'] ?? null,
            ]),
            'evidence_asset_ref' => $firstText([
                $row['evidence_asset_ref'] ?? null,
                $captureMeta['evidence_asset_ref'] ?? null,
                $captureMeta['screenshot_ref'] ?? null,
            ]),
        ];
    }

    private function buildOtaDiagnosisSections(array $diagnosis, array $missingSections): array
    {
        $sections = [
            [
                'key' => 'analysis_mode',
                'title' => '诊断方式',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['model_note'] ?? ''),
            ],
            [
                'key' => 'data_overview',
                'title' => '数据概览',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['data_overview'] ?? []),
            ],
            [
                'key' => 'abnormal_metrics',
                'title' => '异常指标',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['abnormal_metrics'] ?? []),
            ],
            [
                'key' => 'traffic',
                'title' => '流量问题',
                'items' => $this->normalizeOtaDiagnosisItems([
                    $diagnosis['traffic_analysis'] ?? '',
                    $diagnosis['exposure_analysis'] ?? '',
                ]),
            ],
            [
                'key' => 'conversion',
                'title' => '转化问题',
                'items' => $this->normalizeOtaDiagnosisItems([
                    $diagnosis['visit_conversion_analysis'] ?? '',
                    $diagnosis['order_conversion_analysis'] ?? '',
                ]),
            ],
            [
                'key' => 'price_competitor',
                'title' => '价格/竞对问题',
                'items' => $this->normalizeOtaDiagnosisItems([
                    $diagnosis['price_analysis'] ?? '',
                    $diagnosis['competitor_analysis'] ?? '',
                ]),
            ],
            [
                'key' => 'advertising_efficiency',
                'title' => '广告效率',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['advertising_analysis'] ?? ''),
            ],
            [
                'key' => 'service_quality',
                'title' => '服务质量',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['service_quality_analysis'] ?? ''),
            ],
            [
                'key' => 'actions',
                'title' => '运营建议',
                'items' => $this->normalizeOtaDiagnosisItems($diagnosis['actions'] ?? []),
            ],
            [
                'key' => 'data_gaps',
                'title' => '数据缺失提示',
                'items' => $this->normalizeOtaDiagnosisItems($missingSections),
            ],
        ];

        return array_values(array_filter($sections, static fn(array $section): bool => !empty($section['items'])));
    }

    private function normalizeOtaDiagnosisItems(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $normalized = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                foreach ($this->normalizeOtaDiagnosisItems($item) as $nested) {
                    $normalized[] = $nested;
                }
                continue;
            }
            $text = trim((string)$item);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function buildOtaDiagnosisActionItems(array $actions, array $evidenceSources, array $context = []): array
    {
        $items = [];
        $blockingDataGaps = $this->blockingOtaDiagnosisDataGaps($context['data_gaps'] ?? []);
        foreach ($actions as $index => $action) {
            $actionText = trim((string)$action);
            if ($actionText === '') {
                continue;
            }
            $refs = $this->selectOtaEvidenceRefsForAction($actionText, $evidenceSources);
            $requiredTags = $this->requiredOtaEvidenceTagsForAction($actionText);
            $missingTags = $this->missingOtaEvidenceTags($requiredTags, $evidenceSources);
            $isDataRepairAction = $this->isOtaDataRepairAction($actionText);
            $hasExecutableRefs = $this->hasExecutableOtaEvidenceRefs($refs, $evidenceSources);
            $executionReady = !$isDataRepairAction && empty($missingTags) && $hasExecutableRefs;
            [$actionType, $expectedMetric] = $this->classifyOtaDiagnosisExecutionAction($actionText);
            $status = $executionReady ? 'pending_manual_review' : 'blocked_by_insufficient_evidence';
            $blockedReason = '';
            $missingEvidence = $this->buildOtaMissingEvidenceItems($missingTags);

            if ($isDataRepairAction) {
                $status = 'blocked_by_data_gap';
                $blockedReason = 'action is a data-repair prerequisite, not an executable operating recommendation';
                if (empty($missingEvidence)) {
                    $missingEvidence[] = [
                        'code' => 'data_gap_requires_repair',
                        'label' => '数据缺口修复',
                        'next_action' => '先补齐对应 OTA 数据证据，再重新生成 AI 诊断。',
                    ];
                }
            } elseif (!empty($blockingDataGaps)) {
                $status = 'blocked_by_data_gap';
                $executionReady = false;
                $blockedReason = 'core OTA evidence is incomplete for the selected date';
                foreach ($blockingDataGaps as $gap) {
                    $code = trim((string)($gap['code'] ?? 'core_ota_data_gap'));
                    $missingEvidence[] = [
                        'code' => $code,
                        'label' => (string)($gap['label'] ?? $gap['message'] ?? $code),
                        'next_action' => (string)($gap['next_action'] ?? '补齐目标日期核心 OTA 数据后重新生成诊断。'),
                    ];
                }
            } elseif (!$hasExecutableRefs) {
                $blockedReason = 'action has no non-derived OTA evidence reference';
                if (empty($missingEvidence)) {
                    $missingEvidence[] = [
                        'code' => 'missing_non_derived_ota_evidence',
                        'label' => '真实 OTA 证据引用',
                        'next_action' => '补齐入库 OTA 行或已验证的经营证据后再生成可执行建议。',
                    ];
                }
            } elseif (!empty($missingTags)) {
                $blockedReason = 'missing required OTA evidence: ' . implode(', ', $missingTags);
            }

            $items[] = [
                'id' => 'ota_action_' . ($index + 1),
                'action' => $actionText,
                'title' => 'OTA渠道建议动作 ' . ($index + 1),
                'priority' => (string)($context['priority'] ?? 'medium'),
                'action_type' => $actionType,
                'recommendation_type' => $isDataRepairAction ? 'data_repair' : 'operation',
                'expected_metric' => $expectedMetric,
                'review_window' => '执行后在下一可用数据日按同酒店、同平台、同指标口径与执行前数据复核',
                'status' => $status,
                'evidence_refs' => $refs,
                'required_evidence' => $requiredTags,
                'missing_evidence' => $missingEvidence,
                'execution_ready' => $executionReady,
                'can_request_execution_intent' => $executionReady,
                'can_create_execution_intent' => $executionReady,
                'human_confirmation_required' => true,
                'human_confirmation_status' => $executionReady ? 'pending' : 'blocked',
                'blocked_reason' => $blockedReason,
                'source_policy' => $executionReady
                    ? 'evidence_refs_required_manual_confirmation_before_execution'
                    : 'blocked_until_required_ota_evidence_is_available',
                'confirmation_policy' => 'manual_confirmation_required_before_operation_execution',
            ];
        }

        $enriched = (new AiDecisionQualityService())->enrichRecommendations($items, [
            'scope' => 'ota_channel',
            'hotel_id' => (int)($context['hotel']['id'] ?? 0),
            'platform' => (string)($context['platform'] ?? ''),
            'date_range' => is_array($context['date_range'] ?? null) ? $context['date_range'] : [],
            'evidence_sources' => $evidenceSources,
            'default_priority' => (string)($context['priority'] ?? 'medium'),
            'basis_summary' => (string)($context['core_conclusion'] ?? $context['diagnosis']['summary'] ?? ''),
            'review_window' => '执行后在下一可用数据日按同酒店、同平台、同指标口径与执行前数据复核',
            'expected_effect_policy' => [
                'status' => 'verification_target',
                'direction' => 'verify',
                'summary' => '预期效果仅作为服务端核验目标：执行后按动作对应指标比较同酒店、同平台、同口径的前后数据；完成回读前不承诺改善幅度。',
                'review_window' => '执行后在下一可用数据日按同酒店、同平台、同指标口径与执行前数据复核',
            ],
        ]);

        foreach ($enriched as &$item) {
            $legacyAllowsExecution = ($item['execution_ready'] ?? false) === true
                && ($item['can_request_execution_intent'] ?? false) === true;
            $executionReady = $legacyAllowsExecution
                && $this->isOtaDiagnosisActionDecisionQualityExecutionReady($item);
            $item['execution_ready'] = $executionReady;
            $item['can_request_execution_intent'] = $executionReady;
            if (!$executionReady) {
                $item['human_confirmation_status'] = 'blocked';
            }
        }
        unset($item);

        return $enriched;
    }

    /** @param array<string, mixed> $action */
    private function isOtaDiagnosisActionDecisionQualityExecutionReady(array $action): bool
    {
        $decisionQuality = is_array($action['decision_quality'] ?? null)
            ? $action['decision_quality']
            : [];

        return ($action['can_create_execution_intent'] ?? false) === true
            && ($decisionQuality['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION
            && ($decisionQuality['execution_ready'] ?? false) === true;
    }

    private function requiredOtaEvidenceTagsForAction(string $action): array
    {
        $tags = [];
        if ($this->textContainsAny($action, ['广告', '投放', 'ROAS', 'roi', 'ad', 'ads', 'advertising', 'campaign'])) {
            $tags[] = 'advertising';
        }
        if ($this->textContainsAny($action, ['服务质量', '服务分', 'PSI', 'psi', 'service', 'quality'])) {
            $tags[] = 'service_quality';
        }
        if ($this->textContainsAny($action, ['曝光', '访问', '流量', '列表', '详情', 'traffic', 'exposure'])) {
            $tags[] = 'traffic';
        }
        if ($this->textContainsAny($action, ['竞对', 'competitor'])) {
            $tags[] = 'competitor';
        }
        if ($this->textContainsAny($action, ['价格', 'ADR', '房型', '促销', 'price'])) {
            $tags[] = 'price';
        }
        if ($this->textContainsAny($action, ['订单', '下单', '转化', '间夜', 'order', 'conversion'])) {
            $tags[] = 'traffic';
            $tags[] = 'order';
        }

        return array_values(array_unique($tags));
    }

    private function missingOtaEvidenceTags(array $requiredTags, array $evidenceSources): array
    {
        $available = [];
        foreach ($evidenceSources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if (($source['decision_eligible'] ?? false) !== true) {
                continue;
            }
            foreach ((array)($source['tags'] ?? []) as $tag) {
                $tag = trim((string)$tag);
                if ($tag !== '') {
                    $available[$tag] = true;
                }
            }
        }

        $missing = [];
        foreach ($requiredTags as $tag) {
            if (empty($available[$tag])) {
                $missing[] = $tag;
            }
        }

        return $missing;
    }

    private function hasExecutableOtaEvidenceRefs(array $refs, array $evidenceSources): bool
    {
        $sourceByRef = [];
        foreach ($evidenceSources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $ref = trim((string)($source['ref'] ?? ''));
            if ($ref !== '') {
                $sourceByRef[$ref] = $source;
            }
        }

        foreach ($refs as $ref) {
            $ref = trim((string)$ref);
            $source = $sourceByRef[$ref] ?? null;
            if (!is_array($source)) {
                continue;
            }
            if (($source['decision_eligible'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function isOtaDataRepairAction(string $action): bool
    {
        return $this->textContainsAny($action, ['补齐', '缺失', '同步', '抓取', '采集', '获取入口', '重新诊断', '数据源', 'sync', 'missing']);
    }

    private function buildOtaMissingEvidenceItems(array $missingTags): array
    {
        $labels = [
            'advertising' => ['label' => 'OTA 广告证据', 'next_action' => '补齐广告花费、归因金额、ROAS 或投放明细证据。'],
            'service_quality' => ['label' => 'OTA 服务质量证据', 'next_action' => '补齐 PSI、服务评分或响应质量证据。'],
            'traffic' => ['label' => 'OTA 流量证据', 'next_action' => '补齐曝光、访问、详情页或流量漏斗证据。'],
            'competitor' => ['label' => '竞对证据', 'next_action' => '补齐同商圈竞对价格、排名或曝光对比证据。'],
            'price' => ['label' => '价格/房型证据', 'next_action' => '补齐本店价格、房型、促销或 ADR 证据。'],
            'order' => ['label' => '订单转化证据', 'next_action' => '补齐订单、间夜、提交用户或转化证据。'],
        ];

        $items = [];
        foreach ($missingTags as $tag) {
            $meta = $labels[$tag] ?? ['label' => $tag, 'next_action' => '补齐该证据后重新生成 AI 诊断。'];
            $items[] = [
                'code' => 'missing_' . $tag . '_evidence',
                'label' => $meta['label'],
                'next_action' => $meta['next_action'],
            ];
        }

        return $items;
    }

    private function buildOtaLatestAvailableDataGap(string $requestedStartDate, string $requestedEndDate, string $effectiveStartDate, string $effectiveEndDate): array
    {
        return [
            'code' => 'ota_requested_period_source_rows_missing_used_latest_available',
            'message' => '所选日期范围没有同日 OTA 明细，当前诊断仅可作为最近可用数据参考，不能作为目标日执行依据。',
            'scope' => 'ota_channel',
            'requested_date_range' => ['start_date' => $requestedStartDate, 'end_date' => $requestedEndDate],
            'effective_date_range' => ['start_date' => $effectiveStartDate, 'end_date' => $effectiveEndDate],
            'blocked_conclusions' => ['target_date_ai_action', 'operation_execution'],
            'next_action' => '默认使用携程/美团浏览器 Profile 采集入口补齐目标日期 OTA 数据后重新诊断；手动 Cookie/API 仅作临时补数或排障。',
        ];
    }

    private function buildOtaLatestAvailableEvidenceSource(string $requestedStartDate, string $requestedEndDate, string $effectiveStartDate, string $effectiveEndDate): array
    {
        return [
            'ref' => 'ota_latest_available_not_target_date',
            'table' => 'derived',
            'record_id' => null,
            'date' => $effectiveStartDate === $effectiveEndDate ? $effectiveStartDate : $effectiveStartDate . ' 至 ' . $effectiveEndDate,
            'tags' => ['scope', 'latest_available', 'not_target_date'],
            'label' => '最近可用数据不是目标日期证据',
            'metrics' => [
                'requested_start_date' => $requestedStartDate,
                'requested_end_date' => $requestedEndDate,
                'effective_start_date' => $effectiveStartDate,
                'effective_end_date' => $effectiveEndDate,
            ],
        ];
    }

    private function blockOtaDiagnosisActionsForLatestAvailableData(array $result, string $requestedStartDate, string $requestedEndDate, string $effectiveStartDate, string $effectiveEndDate): array
    {
        $guardRef = 'ota_latest_available_not_target_date';
        $result['source_policy'] = 'database_only_latest_available_reference_not_execution_ready';
        $result['data_summary']['target_date_execution_ready'] = false;
        $result['evidence_sources'] = array_values(array_merge(
            (array)($result['evidence_sources'] ?? []),
            [$this->buildOtaLatestAvailableEvidenceSource($requestedStartDate, $requestedEndDate, $effectiveStartDate, $effectiveEndDate)]
        ));
        $existingGapCodes = array_values(array_filter(array_map(
            static fn($item): string => is_array($item) ? (string)($item['code'] ?? '') : '',
            (array)($result['data_gaps'] ?? [])
        )));
        if (!in_array('ota_requested_period_source_rows_missing_used_latest_available', $existingGapCodes, true)) {
            $result['data_gaps'][] = $this->buildOtaLatestAvailableDataGap($requestedStartDate, $requestedEndDate, $effectiveStartDate, $effectiveEndDate);
        }

        $items = [];
        foreach ((array)($result['action_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $refs = array_values(array_unique(array_filter(array_map('strval', array_merge((array)($item['evidence_refs'] ?? []), [$guardRef])))));
            $item['original_status'] = (string)($item['status'] ?? '');
            $item['status'] = 'blocked_by_non_target_date_data';
            $item['evidence_refs'] = $refs;
            $item['execution_ready'] = false;
            $item['can_request_execution_intent'] = false;
            $item['human_confirmation_required'] = true;
            $item['human_confirmation_status'] = 'blocked';
            $item['source_policy'] = 'target-date OTA evidence required before execution';
            $item['blocked_reason'] = 'requested date has no same-period OTA rows; latest available data is reference only';
            $item['missing_evidence'] = array_values(array_merge((array)($item['missing_evidence'] ?? []), [[
                'code' => 'missing_target_date_ota_evidence',
                'label' => '目标日期 OTA 证据',
                'next_action' => '补齐目标日期 OTA 入库数据后重新生成 AI 诊断。',
            ]]));
            $item['protected_boundary'] = '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。';
            $items[] = $item;
        }
        $result['action_items'] = $items;

        return $result;
    }

    private function buildOtaEvidenceReport(array $result): array
    {
        return [
            'report_type' => 'daily_diagnosis_action_list',
            'source_policy' => (string)($result['source_policy'] ?? 'database_only'),
            'date_range' => $result['date_range'] ?? [],
            'source_counts' => $result['data_summary']['source_counts'] ?? [],
            'diagnosis' => [
                'summary' => (string)($result['core_conclusion'] ?? ''),
                'main_problems' => $result['main_problems'] ?? [],
                'possible_reasons' => $result['possible_reasons'] ?? [],
            ],
            'action_items' => $result['action_items'] ?? [],
            'diagnosis_sections' => $result['diagnosis_sections'] ?? [],
            'evidence_sources' => $result['evidence_sources'] ?? [],
            'derived_metric_lineage' => $result['derived_metric_lineage'] ?? [],
            'data_gaps' => $result['data_gaps'] ?? [],
            'decision_closure' => $result['decision_closure'] ?? null,
            'analysis_runtime' => $result['analysis_runtime'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function resolveOtaDiagnosisAnalysisRuntime(string $requestedMode, bool $modelAllowed): array
    {
        $requestedMode = strtolower(trim($requestedMode));
        if (!in_array($requestedMode, ['auto', 'rules_only'], true)) {
            throw new \InvalidArgumentException('analysis_mode must be auto or rules_only');
        }

        $useRulesOnly = $requestedMode === 'rules_only' || !$modelAllowed;

        return [
            'requested_mode' => $requestedMode,
            'mode' => $useRulesOnly ? 'deterministic_rules' : 'llm_augmented_rules',
            'use_rules_only' => $useRulesOnly,
            'model_allowed' => $modelAllowed,
            'model_called' => false,
            'rules_evidence_guard_applied' => true,
            'fallback_reason' => !$modelAllowed ? 'model_not_available' : '',
        ];
    }

}
