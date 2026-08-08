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

trait AgentCapturedOtaAnalysisConcern
{
    public function analyzeCapturedOtaData(): Response
    {
        $this->checkAdmin();

        $payload = $this->request->post();
        $platform = strtolower(trim((string) ($payload['platform'] ?? 'ctrip')));
        $dataSource = strtolower(trim((string) ($payload['data_source'] ?? 'rank')));
        $modelKey = trim((string) ($payload['model_key'] ?? 'deepseek_v4_default'));
        $modelMode = $payload['model_mode'] ?? null;
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $startDate = trim((string) ($payload['start_date'] ?? ''));
        $endDate = trim((string) ($payload['end_date'] ?? ''));
        $hotels = $payload['hotels'] ?? [];

        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!in_array($dataSource, ['rank', 'traffic', 'business', 'captured'], true)) {
            return $this->error('data_source 仅支持 rank、traffic、business、captured', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 不能晚于 end_date', 422);
        }
        if (!is_array($hotels) || empty($hotels)) {
            return $this->error('暂无抓取数据', 422);
        }

        try {
            $summary = $this->buildCapturedOtaSummary($hotels, $platform, $dataSource, $startDate, $endDate);
            $summary['knowledge_context'] = $this->loadOtaKnowledgeContext($platform, $dataSource, $this->extractKnowledgeHotelIds(['hotels' => $hotels, 'summary' => $summary]));
            if (empty($summary['hotels'])) {
                return $this->error('暂无可分析的已验证入库回读抓取数据', 422, [
                    'summary' => [
                        'scope' => $summary['scope'],
                        'input_hotel_count' => $summary['input_hotel_count'],
                        'hotel_count' => 0,
                        'excluded_hotel_count' => $summary['excluded_hotel_count'],
                        'totals' => $summary['totals'],
                        'averages' => $summary['averages'],
                        'truth_context' => $summary['truth_context'],
                        'metric_truth' => $summary['metric_truth'],
                        'excluded' => $summary['excluded'],
                        'data_gaps' => $summary['data_gaps'],
                        'failure_reasons' => $summary['failure_reasons'],
                    ],
                ]);
            }

            $llmResult = $this->callLlm($this->buildCapturedOtaPrompt($summary), $modelKey, $this->buildAiGovernanceMeta('captured_ota_analysis', $summary, [
                'selected_hotel_count' => $summary['hotel_count'],
                'user_id' => (int)($this->currentUser->id ?? 0),
            ]), $modelOptions);
            if (($llmResult['ok'] ?? false) !== true) {
                return $this->error((string) $llmResult['message'], (int) $llmResult['code'], $llmResult['data'] ?? null);
            }

            $report = $this->parseCapturedOtaAnalysisResult((string) $llmResult['content']);
            if (isset($llmResult['data']['debug']) && is_array($llmResult['data']['debug'])) {
                $report['debug'] = $llmResult['data']['debug'];
            }
            $report['data_quality'] = $summary['data_quality'];
            $report['data_collection_notice'] = $summary['data_collection_notice'];
            $report['knowledge_context'] = $summary['knowledge_context'];
            $report = $this->applyCapturedOtaDataQualityGuard($report);
            $report['ai_governance'] = $this->buildAiGovernancePayload('captured_ota_analysis', $summary, $llmResult);
            $report = $this->attachCapturedOtaRecommendationQuality($report, $summary);
            $report['truth_context'] = $summary['truth_context'];
            $report['metric_truth'] = $summary['metric_truth'];
            $report['summary'] = [
                'scope' => $summary['scope'],
                'hotel_count' => $summary['hotel_count'],
                'input_hotel_count' => $summary['input_hotel_count'],
                'excluded_hotel_count' => $summary['excluded_hotel_count'],
                'truncated' => $summary['truncated'],
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'totals' => $summary['totals'],
                'averages' => $summary['averages'],
                'metric_sample_counts' => $summary['metric_sample_counts'],
                'truth_context' => $summary['truth_context'],
                'metric_truth' => $summary['metric_truth'],
                'excluded' => $summary['excluded'],
                'data_gaps' => $summary['data_gaps'],
                'failure_reasons' => $summary['failure_reasons'],
                'data_quality' => $summary['data_quality'],
            ];

            OperationLog::record('agent', 'analyze_captured_ota_data', '分析当前抓取OTA数据', (int) ($this->currentUser->id ?? 0), null, null, [
                'platform' => $platform,
                'data_source' => $dataSource,
                'model_key' => $modelKey,
                'hotel_count' => $summary['hotel_count'],
                'truncated' => $summary['truncated'],
            ]);

            return $this->success($report, 'success');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'analyze_captured_ota_data', '分析当前抓取OTA数据失败', $this->sanitizeLlmErrorMessage($e->getMessage()), (int) ($this->currentUser->id ?? 0));
            return $this->error('抓取数据 AI 分析失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

    public function summarizeCapturedOtaAnalysis(): Response
    {
        $this->checkAdmin();

        $payload = $this->request->post();
        $platform = strtolower(trim((string) ($payload['platform'] ?? 'ctrip')));
        $modelKey = trim((string) ($payload['model_key'] ?? 'deepseek_v4_default'));
        $modelMode = $payload['model_mode'] ?? null;
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $dateRange = is_array($payload['date_range'] ?? null) ? $payload['date_range'] : [];
        $startDate = trim((string) ($dateRange['start_date'] ?? $payload['start_date'] ?? ''));
        $endDate = trim((string) ($dateRange['end_date'] ?? $payload['end_date'] ?? ''));
        $selectedHotelCount = max(0, (int) ($payload['selected_hotel_count'] ?? 0));
        $successHotelCount = max(0, (int) ($payload['success_hotel_count'] ?? 0));
        $failedHotelCount = max(0, (int) ($payload['failed_hotel_count'] ?? 0));
        $groupReports = $payload['group_summaries'] ?? $payload['group_reports'] ?? [];
        $failedGroups = $payload['failed_groups'] ?? [];

        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        if (!$this->isAllowedLlmModelKey($modelKey)) {
            return $this->error('未找到启用的模型配置：' . $modelKey . '，请先到系统设置 > AI模型配置中配置', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            return $this->error('start_date 和 end_date 必须为 YYYY-MM-DD', 422);
        }
        if (!is_array($groupReports) || empty($groupReports)) {
            return $this->error('暂无可汇总的分组报告', 422);
        }

        $summary = null;
        try {
            $summary = $this->buildCapturedOtaFinalSummary(
                $groupReports,
                is_array($failedGroups) ? $failedGroups : [],
                $platform,
                $startDate,
                $endDate,
                $selectedHotelCount,
                $successHotelCount,
                $failedHotelCount,
                $modelKey
            );
            $summary['knowledge_context'] = $this->loadOtaKnowledgeContext($platform, 'captured_final', $this->extractKnowledgeHotelIds($summary));
            $process = $this->buildCapturedOtaProcess($summary);
            $summaryMeta = [
                'group_count' => count($summary['groups']),
                'failed_group_count' => count($summary['failed_groups']),
                'selected_hotel_count' => $summary['selected_hotel_count'],
                'success_hotel_count' => $summary['success_hotel_count'],
                'failed_hotel_count' => $summary['failed_hotel_count'],
                'hotel_count' => $summary['success_hotel_count'],
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];

            $llmResult = $this->callLlm($this->buildCapturedOtaFinalPrompt($summary), $modelKey, $this->buildAiGovernanceMeta('captured_ota_final_summary', $summary, [
                'selected_hotel_count' => $summary['selected_hotel_count'],
                'user_id' => (int)($this->currentUser->id ?? 0),
            ]), $modelOptions);
            $debug = isset($llmResult['data']['debug']) && is_array($llmResult['data']['debug']) ? $llmResult['data']['debug'] : null;
            if (($llmResult['ok'] ?? false) === true) {
                $report = $this->parseCapturedOtaAnalysisResult((string) $llmResult['content']);
                $report['fallback'] = false;
            } else {
                $report = $this->buildCapturedOtaFallbackReport($summary, (string) ($llmResult['message'] ?? '汇总失败'));
            }
            $report['data_quality'] = $summary['data_quality'];
            $report['data_collection_notice'] = $summary['data_quality']['warning'] ?? '';
            $report['knowledge_context'] = $summary['knowledge_context'];
            $report = $this->applyCapturedOtaDataQualityGuard($report);
            if ($debug !== null) {
                $report['debug'] = $debug;
            }
            $report['ai_governance'] = $this->buildAiGovernancePayload('captured_ota_final_summary', $summary, $llmResult);
            $report = $this->attachCapturedOtaRecommendationQuality($report, $summary);
            $report['summary'] = $summaryMeta;

            OperationLog::record('agent', 'summarize_captured_ota_analysis', '汇总当前抓取OTA分组报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'platform' => $platform,
                'model_key' => $modelKey,
                'group_count' => count($summary['groups']),
                'failed_group_count' => count($summary['failed_groups']),
                'selected_hotel_count' => $summary['selected_hotel_count'],
                'success_hotel_count' => $summary['success_hotel_count'],
                'failed_hotel_count' => $summary['failed_hotel_count'],
            ]);

            return $this->success([
                'report' => $report,
                'process' => $process,
                'debug' => $debug,
            ], 'success');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'summarize_captured_ota_analysis', '汇总当前抓取OTA分组报告失败', $this->sanitizeLlmErrorMessage($e->getMessage()), (int) ($this->currentUser->id ?? 0));
            if (is_array($summary) && !empty($summary['groups'])) {
                $report = $this->buildCapturedOtaFallbackReport($summary, $e->getMessage());
                $report['knowledge_context'] = $summary['knowledge_context'] ?? [];
                $report = $this->applyCapturedOtaDataQualityGuard($report);
                $report['summary'] = [
                    'group_count' => count($summary['groups']),
                    'failed_group_count' => count($summary['failed_groups']),
                    'selected_hotel_count' => $summary['selected_hotel_count'],
                    'success_hotel_count' => $summary['success_hotel_count'],
                    'failed_hotel_count' => $summary['failed_hotel_count'],
                    'hotel_count' => $summary['success_hotel_count'],
                    'platform' => $platform,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ];
                return $this->success([
                    'report' => $report,
                    'process' => $this->buildCapturedOtaProcess($summary),
                ], 'success');
            }
            return $this->error('批量总报告生成失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

    private function isDateString(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $time = strtotime($date);
        return $time !== false && date('Y-m-d', $time) === $date;
    }

    private function buildCapturedOtaSummary(array $hotels, string $platform, string $dataSource, string $startDate, string $endDate): array
    {
        $maxHotels = 50;
        $inputCount = count($hotels);
        $rows = [];
        $excludedRows = [];
        $totals = [
            'room_nights' => 0.0,
            'room_revenue' => 0.0,
            'sales' => 0.0,
            'exposure' => 0.0,
            'views' => 0.0,
            'orders' => 0.0,
        ];
        $metricSampleCounts = array_fill_keys(array_keys($totals), 0);
        $metricSourceHotelIds = array_fill_keys(array_merge(array_keys($totals), [
            'adr', 'view_rate', 'order_rate', 'comment_score', 'conversion_rate',
        ]), []);
        $scoreValues = [];
        $conversionValues = [];
        $adrRevenueTotal = 0.0;
        $adrRoomNightsTotal = 0.0;
        $adrSampleCount = 0;
        $viewExposureTotal = 0.0;
        $viewTotal = 0.0;
        $viewRateSampleCount = 0;
        $orderViewTotal = 0.0;
        $orderTotal = 0.0;
        $orderRateSampleCount = 0;
        $truthStateCounts = array_fill_keys(['verified', 'partial', 'unverified', 'collection_failed'], 0);
        $verifiedSources = [];
        $verifiedRowsWithMetricGaps = 0;
        $flowQualityStats = [
            'exposure' => ['missing' => 0, 'zero' => 0],
            'views' => ['missing' => 0, 'zero' => 0],
            'browse_rate' => ['missing' => 0, 'zero' => 0],
            'order_rate' => ['missing' => 0, 'zero' => 0],
            'conversion_rate' => ['missing' => 0, 'zero' => 0],
        ];

        foreach (array_slice($hotels, 0, $maxHotels) as $hotel) {
            if (!is_array($hotel)) {
                $hotel = [];
            }

            $hotelId = substr(trim((string) ($hotel['hotel_id'] ?? $hotel['hotelId'] ?? $hotel['poiId'] ?? '')), 0, 64);
            $hotelName = substr(trim((string) ($hotel['hotel_name'] ?? $hotel['hotelName'] ?? $hotel['name'] ?? '')), 0, 120);

            $metrics = [];
            foreach (['rank', 'price', 'score', 'comments_count', 'exposure', 'visitors', 'orders', 'revenue', 'room_nights'] as $field) {
                if (isset($hotel[$field])) {
                    $metrics[$field] = $hotel[$field];
                }
            }
            $extraMetrics = $hotel['raw_metrics'] ?? $hotel['metrics'] ?? [];
            if (!is_array($extraMetrics)) {
                $extraMetrics = [];
            }
            foreach ($extraMetrics as $field => $value) {
                if (!isset($metrics[$field])) {
                    $metrics[$field] = $value;
                }
            }
            if (!is_array($metrics)) {
                $metrics = [];
            }
            $safeMetrics = $this->sanitizeCapturedOtaMetrics($metrics);
            $roomNights = $this->readCapturedNullableMetric($safeMetrics, ['room_nights']);
            $roomRevenue = $this->readCapturedNullableMetric($safeMetrics, ['revenue', 'room_revenue']);
            $exposure = $this->readCapturedNullableMetric($safeMetrics, ['exposure']);
            $views = $this->readCapturedNullableMetric($safeMetrics, ['visitors', 'views']);
            $orders = $this->readCapturedNullableMetric($safeMetrics, ['orders', 'total_order_num', 'book_order_num']);
            $sales = $this->readCapturedNullableMetric($safeMetrics, ['sales', 'revenue', 'room_revenue']);
            $commentScore = $this->readCapturedNullableMetric($safeMetrics, ['score', 'comment_score']);
            $viewConversion = $this->readCapturedNullableMetric($safeMetrics, ['view_conversion', 'browse_rate']);
            $payConversion = $this->readCapturedNullableMetric($safeMetrics, ['pay_conversion', 'order_rate']);
            $conversionRate = $this->readCapturedNullableMetric($safeMetrics, ['conversion_rate', 'qunar_detail_cr']);
            $tags = $this->sanitizeCapturedTags($hotel['tags'] ?? []);
            $shortSummary = mb_substr(trim((string) ($hotel['short_summary'] ?? '')), 0, 160);
            $truth = $this->assessCapturedOtaHotelTruth($hotel, $hotelId, $hotelName, $platform, $startDate, $endDate);

            $safeMetrics['adr'] = $roomNights !== null && $roomRevenue !== null && $roomNights > 0
                ? round($roomRevenue / $roomNights, 2)
                : null;
            $safeMetrics['view_rate'] = $exposure !== null && $views !== null && $exposure > 0
                ? round($views / $exposure * 100, 2)
                : null;
            $safeMetrics['order_rate'] = $orders !== null && $views !== null && $views > 0
                ? round($orders / $views * 100, 2)
                : null;
            $metricDataGaps = array_values(array_filter([
                $roomNights === null ? 'room_nights_missing' : null,
                $roomRevenue === null ? 'room_revenue_missing' : null,
                $orders === null ? 'orders_missing' : null,
            ]));
            $safeMetrics['data_gaps'] = $metricDataGaps;

            $rowMetricValues = [
                'room_nights' => $roomNights,
                'room_revenue' => $roomRevenue,
                'sales' => $sales,
                'exposure' => $exposure,
                'views' => $views,
                'orders' => $orders,
                'adr' => $safeMetrics['adr'],
                'view_rate' => $safeMetrics['view_rate'],
                'order_rate' => $safeMetrics['order_rate'],
                'comment_score' => $commentScore,
                'conversion_rate' => $conversionRate ?? $payConversion ?? $viewConversion,
            ];
            $rowMetricTruth = [];
            foreach ($rowMetricValues as $metricKey => $metricValue) {
                $decisionEligible = $truth['status'] === 'verified' && $metricValue !== null;
                $rowMetricTruth[$metricKey] = [
                    'status' => $decisionEligible ? 'verified' : ($truth['status'] === 'verified' ? 'unverified' : $truth['status']),
                    'scope' => 'ota_channel',
                    'whole_hotel_scope' => false,
                    'value' => $metricValue,
                    'observed_count' => $decisionEligible ? 1 : 0,
                    'sample_count' => $decisionEligible ? 1 : 0,
                    'decision_eligible' => $decisionEligible,
                    'source_hotel_id' => $hotelId,
                    'platform' => $truth['platform'],
                    'date_range' => $truth['date_range'],
                    'source_method' => $truth['source_method'],
                    'collected_at' => $truth['collected_at'],
                    'stored' => $truth['stored'],
                    'readback_verified' => $truth['readback_verified'],
                    'data_gaps' => $metricValue === null ? [$metricKey . '_missing'] : ($decisionEligible ? [] : $truth['data_gaps']),
                    'failure_reason' => $truth['failure_reason'],
                ];
            }

            $row = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'metrics' => $safeMetrics,
                'tags' => $tags,
                'short_summary' => $shortSummary,
                'truth_status' => $truth['status'],
                'scope' => 'ota_channel',
                'whole_hotel_scope' => false,
                'platform' => $truth['platform'],
                'date_range' => $truth['date_range'],
                'source_method' => $truth['source_method'],
                'collected_at' => $truth['collected_at'],
                'stored' => $truth['stored'],
                'readback_verified' => $truth['readback_verified'],
                'failure_reason' => $truth['failure_reason'],
                'data_gaps' => array_values(array_unique(array_merge($truth['data_gaps'], $metricDataGaps))),
                'metric_truth' => $rowMetricTruth,
            ];

            $truthStateCounts[$truth['status']]++;
            if ($truth['status'] !== 'verified') {
                $excludedRows[] = $row;
                continue;
            }
            if ($metricDataGaps !== []) {
                $verifiedRowsWithMetricGaps++;
            }

            $this->recordCapturedFlowQuality($flowQualityStats, 'exposure', $exposure);
            $this->recordCapturedFlowQuality($flowQualityStats, 'views', $views);
            $this->recordCapturedFlowQuality($flowQualityStats, 'browse_rate', $viewConversion);
            $this->recordCapturedFlowQuality($flowQualityStats, 'order_rate', $payConversion);
            $this->recordCapturedFlowQuality($flowQualityStats, 'conversion_rate', $conversionRate);

            foreach ([
                'room_nights' => $roomNights,
                'room_revenue' => $roomRevenue,
                'sales' => $sales,
                'exposure' => $exposure,
                'views' => $views,
                'orders' => $orders,
            ] as $metricKey => $metricValue) {
                if ($metricValue !== null) {
                    $totals[$metricKey] += $metricValue;
                    $metricSampleCounts[$metricKey]++;
                    $metricSourceHotelIds[$metricKey][] = $hotelId;
                }
            }
            if ($commentScore !== null) {
                $scoreValues[] = $commentScore;
                $metricSourceHotelIds['comment_score'][] = $hotelId;
            }
            if ($viewConversion !== null) {
                $conversionValues[] = $viewConversion;
                $metricSourceHotelIds['conversion_rate'][] = $hotelId;
            }
            if ($payConversion !== null) {
                $conversionValues[] = $payConversion;
                $metricSourceHotelIds['conversion_rate'][] = $hotelId;
            }
            if ($conversionRate !== null) {
                $conversionValues[] = $conversionRate;
                $metricSourceHotelIds['conversion_rate'][] = $hotelId;
            }
            if ($roomRevenue !== null && $roomNights !== null && $roomNights > 0) {
                $adrRevenueTotal += $roomRevenue;
                $adrRoomNightsTotal += $roomNights;
                $adrSampleCount++;
                $metricSourceHotelIds['adr'][] = $hotelId;
            }
            if ($views !== null && $exposure !== null && $exposure > 0) {
                $viewTotal += $views;
                $viewExposureTotal += $exposure;
                $viewRateSampleCount++;
                $metricSourceHotelIds['view_rate'][] = $hotelId;
            }
            if ($orders !== null && $views !== null && $views > 0) {
                $orderTotal += $orders;
                $orderViewTotal += $views;
                $orderRateSampleCount++;
                $metricSourceHotelIds['order_rate'][] = $hotelId;
            }

            $verifiedSources[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'platform' => $truth['platform'],
                'date_range' => $truth['date_range'],
                'source_method' => $truth['source_method'],
                'collected_at' => $truth['collected_at'],
                'stored' => true,
                'readback_verified' => true,
                'failure_reason' => '',
            ];
            $rows[] = $row;
        }

        usort($rows, function (array $left, array $right): int {
            $leftRevenue = $this->readCapturedNullableMetric((array)($left['metrics'] ?? []), ['revenue', 'room_revenue']);
            $rightRevenue = $this->readCapturedNullableMetric((array)($right['metrics'] ?? []), ['revenue', 'room_revenue']);
            if ($leftRevenue === null || $rightRevenue === null) {
                if ($leftRevenue === null && $rightRevenue === null) {
                    return strcmp((string)($left['hotel_id'] ?? ''), (string)($right['hotel_id'] ?? ''));
                }
                return $leftRevenue === null ? 1 : -1;
            }
            $valueCompare = $rightRevenue <=> $leftRevenue;
            return $valueCompare !== 0
                ? $valueCompare
                : strcmp((string)($left['hotel_id'] ?? ''), (string)($right['hotel_id'] ?? ''));
        });

        $displayTotals = $totals;
        foreach ($displayTotals as $metricKey => $_value) {
            if (($metricSampleCounts[$metricKey] ?? 0) === 0) {
                $displayTotals[$metricKey] = null;
            }
        }
        $averages = [
            'adr' => $adrSampleCount > 0 && $adrRoomNightsTotal > 0
                ? $this->percentSafeAverage($adrRevenueTotal, $adrRoomNightsTotal)
                : null,
            'view_rate' => $viewRateSampleCount > 0 && $viewExposureTotal > 0
                ? $this->percentRate($viewTotal, $viewExposureTotal)
                : null,
            'order_rate' => $orderRateSampleCount > 0 && $orderViewTotal > 0
                ? $this->percentRate($orderTotal, $orderViewTotal)
                : null,
            'comment_score' => $scoreValues !== [] ? $this->average($scoreValues) : null,
            'conversion_rate' => $conversionValues !== [] ? $this->average($conversionValues) : null,
        ];
        $processedCount = min($inputCount, $maxHotels);
        $unprocessedCount = max(0, $inputCount - $processedCount);
        $coverageExcludedCount = count($excludedRows) + $unprocessedCount;
        $truthStatus = $this->capturedOtaSummaryTruthStatus(
            count($rows),
            $truthStateCounts,
            $coverageExcludedCount,
            $verifiedRowsWithMetricGaps > 0
        );
        $failureReasons = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['failure_reason'] ?? '')),
            $excludedRows
        ))));
        $truthContext = [
            'status' => $truthStatus,
            'scope' => 'ota_channel',
            'whole_hotel_scope' => false,
            'scope_notice' => '仅代表所列门店、平台和日期范围内已验证且已入库回读的 OTA 渠道数据，不代表全酒店经营数据。',
            'platform' => $platform,
            'data_source' => $dataSource,
            'date_range' => ['start_date' => $startDate, 'end_date' => $endDate],
            'input_hotel_count' => $inputCount,
            'processed_hotel_count' => $processedCount,
            'verified_hotel_count' => count($rows),
            'excluded_hotel_count' => count($excludedRows),
            'unprocessed_hotel_count' => $unprocessedCount,
            'verified_rows_with_metric_gaps' => $verifiedRowsWithMetricGaps,
            'state_counts' => $truthStateCounts,
            'verified_sources' => $verifiedSources,
            'failure_reasons' => $failureReasons,
        ];
        $metricTruth = [];
        foreach ($displayTotals as $metricKey => $metricValue) {
            $metricTruth[$metricKey] = $this->buildCapturedOtaMetricTruth(
                $metricValue,
                (int)($metricSampleCounts[$metricKey] ?? 0),
                count($rows),
                $coverageExcludedCount,
                $truthStatus,
                $metricSourceHotelIds[$metricKey] ?? [],
                $failureReasons
            );
        }
        foreach ($averages as $metricKey => $metricValue) {
            $sampleCount = match ($metricKey) {
                'adr' => $adrSampleCount,
                'view_rate' => $viewRateSampleCount,
                'order_rate' => $orderRateSampleCount,
                'comment_score' => count($scoreValues),
                'conversion_rate' => count($conversionValues),
            };
            $metricTruth[$metricKey] = $this->buildCapturedOtaMetricTruth(
                $metricValue,
                $sampleCount,
                count($rows),
                $coverageExcludedCount,
                $truthStatus,
                $metricSourceHotelIds[$metricKey] ?? [],
                $failureReasons
            );
        }
        $dataQuality = $this->buildCapturedOtaDataQuality($flowQualityStats, $displayTotals, $startDate, $endDate, count($rows));
        $dataQuality['truth_status'] = $truthStatus;
        $dataQuality['is_reliable'] = $truthStatus === 'verified';
        if ($truthStatus !== 'verified') {
            $truthWarning = $truthStatus === 'collection_failed'
                ? '本次 OTA 采集失败，没有可进入分析的已验证入库回读样本。'
                : '本次仅有部分或未验证 OTA 数据；汇总值只包含已验证入库回读样本，不能外推为全部门店或全酒店经营结论。';
            $dataQuality['warning'] = trim($truthWarning . ' ' . (string)($dataQuality['warning'] ?? ''));
        }

        return [
            'scope' => [
                'type' => 'ota_channel',
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'whole_hotel_scope' => false,
            ],
            'input_hotel_count' => $inputCount,
            'hotel_count' => count($rows),
            'excluded_hotel_count' => count($excludedRows),
            'truncated' => $inputCount > $maxHotels,
            'totals' => $displayTotals,
            'metric_sample_counts' => $metricSampleCounts,
            'averages' => $averages,
            'truth_context' => $truthContext,
            'metric_truth' => $metricTruth,
            'hotels' => $rows,
            'excluded' => $excludedRows,
            'data_gaps' => array_map(static fn(array $row): array => [
                'hotel_id' => (string)($row['hotel_id'] ?? ''),
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'status' => (string)($row['truth_status'] ?? 'unverified'),
                'data_gaps' => (array)($row['data_gaps'] ?? []),
                'failure_reason' => (string)($row['failure_reason'] ?? ''),
            ], $excludedRows),
            'failure_reasons' => $failureReasons,
            'top_hotels_by_revenue' => array_slice($rows, 0, 10),
            'data_quality' => $dataQuality,
            'data_collection_notice' => $dataQuality['warning'],
            'data_anomalies' => $inputCount > $maxHotels ? ['单次最多分析 50 家酒店，已截断超出部分。'] : [],
        ];
    }

    /** @return array<string,mixed> */
    private function assessCapturedOtaHotelTruth(array $hotel, string $hotelId, string $hotelName, string $requestedPlatform, string $requestedStartDate, string $requestedEndDate): array
    {
        $captureMeta = is_array($hotel['capture_meta'] ?? null)
            ? $hotel['capture_meta']
            : (is_array($hotel['captureMeta'] ?? null) ? $hotel['captureMeta'] : []);
        $dateRange = is_array($hotel['date_range'] ?? null) ? $hotel['date_range'] : [];
        $persistence = is_array($hotel['persistence'] ?? null) ? $hotel['persistence'] : [];
        $firstText = static function (array $values): string {
            foreach ($values as $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
            return '';
        };

        $platform = strtolower($firstText([
            $hotel['platform'] ?? null,
            $hotel['ota_platform'] ?? null,
            $hotel['source_platform'] ?? null,
            $captureMeta['platform'] ?? null,
        ]));
        $sourceStartDate = $firstText([
            $hotel['start_date'] ?? null,
            $dateRange['start_date'] ?? null,
            $dateRange['start'] ?? null,
            $captureMeta['start_date'] ?? null,
            $hotel['data_date'] ?? null,
        ]);
        $sourceEndDate = $firstText([
            $hotel['end_date'] ?? null,
            $dateRange['end_date'] ?? null,
            $dateRange['end'] ?? null,
            $captureMeta['end_date'] ?? null,
            $hotel['data_date'] ?? null,
        ]);
        $sourceMethod = $firstText([
            $hotel['source_method'] ?? null,
            $hotel['collection_method'] ?? null,
            $captureMeta['source_method'] ?? null,
            $captureMeta['method'] ?? null,
        ]);
        $collectedAt = $firstText([
            $hotel['collected_at'] ?? null,
            $hotel['captured_at'] ?? null,
            $hotel['fetch_time'] ?? null,
            $captureMeta['collected_at'] ?? null,
            $captureMeta['captured_at'] ?? null,
        ]);
        $persistenceStatus = strtolower($firstText([
            $hotel['persistence_status'] ?? null,
            $persistence['status'] ?? null,
        ]));
        $stored = $this->capturedOtaTruthFlag(
            $hotel['stored'] ?? $hotel['is_stored'] ?? $hotel['persisted'] ?? $persistence['stored'] ?? false
        ) || in_array($persistenceStatus, ['stored', 'persisted', 'readback_verified'], true);
        $readbackVerified = $this->capturedOtaTruthFlag(
            $hotel['readback_verified'] ?? $hotel['database_readback_verified'] ?? $persistence['readback_verified'] ?? false
        ) || $persistenceStatus === 'readback_verified';
        $validationStatus = strtolower($firstText([
            $hotel['validation_status'] ?? null,
            $hotel['truth_status'] ?? null,
            $hotel['quality_status'] ?? null,
            $hotel['collection_status'] ?? null,
            $captureMeta['validation_status'] ?? null,
        ]));
        $failureReason = $firstText([
            $hotel['failure_reason'] ?? null,
            $hotel['collection_error'] ?? null,
            $hotel['capture_error'] ?? null,
            $hotel['error'] ?? null,
            $captureMeta['failure_reason'] ?? null,
        ]);

        $dataGaps = [];
        if ($hotelId === '') {
            $dataGaps[] = 'hotel_id_missing';
        }
        if ($hotelName === '') {
            $dataGaps[] = 'hotel_name_missing';
        }
        if ($platform === '') {
            $dataGaps[] = 'platform_missing';
        } elseif ($platform !== strtolower($requestedPlatform)) {
            $dataGaps[] = 'platform_mismatch';
        }
        if (!$this->isDateString($sourceStartDate) || !$this->isDateString($sourceEndDate)) {
            $dataGaps[] = 'date_range_missing_or_invalid';
        } elseif ($sourceStartDate !== $requestedStartDate || $sourceEndDate !== $requestedEndDate) {
            $dataGaps[] = 'date_range_mismatch';
        }
        $manualOrSyntheticSource = $sourceMethod !== ''
            && preg_match('/(?:^|[_\-\s])(manual|mock|synthetic|fixture|legacy)(?:$|[_\-\s])/i', $sourceMethod) === 1;
        if ($sourceMethod === '') {
            $dataGaps[] = 'source_method_missing';
        } elseif ($manualOrSyntheticSource) {
            $dataGaps[] = 'source_method_not_verified_online_capture';
        }
        if (!$this->isPreciseCapturedOtaDateTime($collectedAt)) {
            $dataGaps[] = $collectedAt === '' ? 'collected_at_missing' : 'collected_at_not_precise';
        }
        if (!$stored) {
            $dataGaps[] = 'not_stored';
        }
        if (!$readbackVerified) {
            $dataGaps[] = 'readback_not_verified';
        }

        $failedStatuses = ['collection_failed', 'failed', 'failure', 'error', 'capture_failed', 'save_failed', 'readback_failed'];
        $partialStatuses = ['partial', 'partial_data', 'incomplete', 'partially_verified'];
        $verifiedStatuses = ['verified', 'readback_verified', 'normal', 'available', 'ok', 'valid', 'success', 'complete', 'completed'];
        if ($failureReason !== '' || in_array($validationStatus, $failedStatuses, true)) {
            $status = 'collection_failed';
            if ($failureReason === '') {
                $failureReason = $validationStatus !== '' ? $validationStatus : 'collection_failed';
            }
        } elseif (in_array($validationStatus, $partialStatuses, true) || ($manualOrSyntheticSource && in_array($validationStatus, $verifiedStatuses, true))) {
            $status = 'partial';
            $dataGaps[] = in_array($validationStatus, $partialStatuses, true)
                ? 'validation_status_partial'
                : 'source_method_not_verified_online_capture';
        } elseif (in_array($validationStatus, $verifiedStatuses, true)) {
            $status = $dataGaps === [] ? 'verified' : 'partial';
        } else {
            $status = 'unverified';
            $dataGaps[] = $validationStatus === '' ? 'validation_status_missing' : 'validation_status_unverified';
        }

        return [
            'status' => $status,
            'platform' => $platform,
            'date_range' => ['start_date' => $sourceStartDate, 'end_date' => $sourceEndDate],
            'source_method' => $sourceMethod,
            'collected_at' => $collectedAt,
            'stored' => $stored,
            'readback_verified' => $readbackVerified,
            'failure_reason' => $failureReason,
            'data_gaps' => array_values(array_unique($dataGaps)),
        ];
    }

    private function capturedOtaTruthFlag(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
    }

    private function isPreciseCapturedOtaDateTime(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?$/', $value)) {
            return false;
        }
        return strtotime($value) !== false;
    }

    private function capturedOtaSummaryTruthStatus(int $verifiedCount, array $stateCounts, int $coverageExcludedCount, bool $hasVerifiedMetricGaps): string
    {
        if ($verifiedCount > 0) {
            return $coverageExcludedCount > 0 || $hasVerifiedMetricGaps ? 'partial' : 'verified';
        }
        if (($stateCounts['partial'] ?? 0) > 0) {
            return 'partial';
        }
        $failedCount = (int)($stateCounts['collection_failed'] ?? 0);
        $unverifiedCount = (int)($stateCounts['unverified'] ?? 0);
        return $failedCount > 0 && $unverifiedCount === 0 ? 'collection_failed' : 'unverified';
    }

    /** @return array<string,mixed> */
    private function buildCapturedOtaMetricTruth(?float $value, int $observedCount, int $verifiedHotelCount, int $coverageExcludedCount, string $summaryStatus, array $sourceHotelIds, array $failureReasons): array
    {
        if ($observedCount === 0) {
            $status = in_array($summaryStatus, ['collection_failed', 'partial'], true) && $verifiedHotelCount === 0
                ? $summaryStatus
                : 'unverified';
        } else {
            $status = $coverageExcludedCount > 0 || $observedCount < $verifiedHotelCount ? 'partial' : 'verified';
        }

        return [
            'status' => $status,
            'scope' => 'ota_channel',
            'whole_hotel_scope' => false,
            'value' => $value,
            'observed_count' => $observedCount,
            'sample_count' => $observedCount,
            'verified_hotel_count' => $verifiedHotelCount,
            'excluded_hotel_count' => $coverageExcludedCount,
            'source_hotel_ids' => array_values(array_unique(array_filter(array_map('strval', $sourceHotelIds)))),
            'failure_reasons' => $failureReasons,
            'scope_notice' => '仅为 OTA 渠道已验证样本，不代表全酒店经营指标。',
        ];
    }

    private function sanitizeCapturedOtaMetrics(array $metrics): array
    {
        $allowed = [
            'rank',
            'price',
            'score',
            'comments_count',
            'visitors',
            'orders',
            'revenue',
            'room_nights',
            'room_revenue',
            'sales_room_nights',
            'sales',
            'view_conversion',
            'pay_conversion',
            'exposure',
            'views',
            'comment_score',
            'qunar_comment_score',
            'conversion_rate',
            'qunar_detail_cr',
            'browse_rate',
            'order_rate',
            'amount_rank',
            'quantity_rank',
            'comment_score_rank',
            'qunar_detail_cr_rank',
            'total_order_num',
            'book_order_num',
        ];

        $safe = [];
        foreach ($allowed as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                $safe[$key] = round((float) $metrics[$key], 4);
            } elseif (array_key_exists($key, $metrics) && ($metrics[$key] === null || $metrics[$key] === '')) {
                $safe[$key] = null;
            }
        }
        return $safe;
    }

    private function readCapturedNullableMetric(array $metrics, array $keys): ?float
    {
        $found = false;
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metrics)) {
                continue;
            }
            $found = true;
            if (is_numeric($metrics[$key])) {
                return (float) $metrics[$key];
            }
        }
        return $found ? null : null;
    }

    private function recordCapturedFlowQuality(array &$stats, string $field, ?float $value): void
    {
        if (!isset($stats[$field])) {
            return;
        }
        if ($value === null) {
            $stats[$field]['missing']++;
            return;
        }
        if ($value == 0.0) {
            $stats[$field]['zero']++;
        }
    }

    private function buildCapturedOtaDataQuality(array $flowQualityStats, array $totals, string $startDate, string $endDate, int $hotelCount): array
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $now = new \DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');
        $hour = (int) $now->format('H');
        $isTodayQuery = $startDate <= $today && $endDate >= $today;
        $isCrossDayWindow = $isTodayQuery && $hour >= 0 && $hour < 8;
        $businessReturned = ((float) ($totals['orders'] ?? 0) + (float) ($totals['room_nights'] ?? 0) + (float) ($totals['room_revenue'] ?? 0) + (float) ($totals['sales'] ?? 0)) > 0;

        $missingFields = [];
        $zeroFields = [];
        foreach ($flowQualityStats as $field => $stat) {
            if (($stat['missing'] ?? 0) > 0) {
                $missingFields[] = $field;
            }
            if (($stat['zero'] ?? 0) > 0) {
                $zeroFields[] = $field;
            }
        }

        $warning = '';
        $isReliable = true;
        if ($isCrossDayWindow && (!empty($missingFields) || !empty($zeroFields))) {
            $warning = '当前可能处于OTA跨日统计窗口，曝光、访客、浏览率、订单率、转化率等流量指标可能暂未更新，不建议直接按0判断经营异常。';
        } elseif ($businessReturned && !empty($zeroFields)) {
            $warning = '流量类指标为0但订单、间夜或收入已返回，优先按采集口径提示处理，待平台数据稳定后复查。';
        } elseif (!$isTodayQuery && !$businessReturned && $hotelCount > 0 && !empty($zeroFields)) {
            $warning = '历史日期流量类指标仍为0，需结合多次同步结果检查接口、字段映射或Cookie权限。';
            $isReliable = false;
        }

        return [
            'is_reliable' => $isReliable,
            'is_cross_day_window' => $isCrossDayWindow,
            'warning' => $warning,
            'missing_fields' => array_values(array_unique($missingFields)),
            'zero_maybe_unready_fields' => array_values(array_unique($zeroFields)),
        ];
    }

    private function sanitizeCapturedTags($tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $safe = [];
        foreach (array_slice($tags, 0, 8) as $tag) {
            $tag = mb_substr(trim((string) $tag), 0, 24);
            if ($tag !== '') {
                $safe[] = $tag;
            }
        }
        return $safe;
    }

    private function buildCapturedOtaFinalSummary(
        array $groupReports,
        array $failedGroups,
        string $platform,
        string $startDate,
        string $endDate,
        int $selectedHotelCount,
        int $successHotelCount,
        int $failedHotelCount,
        string $modelKey
    ): array
    {
        $groups = [];
        $hotelCount = 0;
        foreach (array_slice($groupReports, 0, 20) as $index => $group) {
            if (!is_array($group)) {
                continue;
            }
            $report = $group['report'] ?? $group;
            if (!is_array($report)) {
                continue;
            }
            $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
            $dataQuality = is_array($report['data_quality'] ?? null)
                ? $report['data_quality']
                : (is_array($summary['data_quality'] ?? null) ? $summary['data_quality'] : []);
            $hotelCount += (int) ($summary['hotel_count'] ?? $group['hotel_count'] ?? 0);
            $groups[] = [
                'group_index' => (int) ($group['group_index'] ?? ($index + 1)),
                'hotel_count' => (int) ($summary['hotel_count'] ?? $group['hotel_count'] ?? 0),
                'overall_conclusion' => mb_substr((string) ($report['overall_conclusion'] ?? ''), 0, 300),
                'key_findings' => $this->sanitizeReportList($report['key_findings'] ?? [], 5),
                'competitor_insights' => $this->sanitizeReportList($report['competitor_insights'] ?? [], 5),
                'problem_hotels' => $this->sanitizeProblemHotels($report['problem_hotels'] ?? [], 8),
                'recommended_actions' => $this->sanitizeReportList($report['recommended_actions'] ?? [], 6),
                'priority' => in_array(($report['priority'] ?? ''), ['high', 'medium', 'low'], true) ? (string) $report['priority'] : 'medium',
                'data_anomalies' => $this->sanitizeReportList($report['data_anomalies'] ?? [], 5),
                'data_quality' => $dataQuality,
            ];
        }

        $safeFailedGroups = [];
        foreach (array_slice($failedGroups, 0, 20) as $group) {
            if (!is_array($group)) {
                continue;
            }
            $safeFailedGroups[] = [
                'group_index' => (int) ($group['group_index'] ?? 0),
                'hotel_count' => (int) ($group['hotel_count'] ?? 0),
                'error' => $this->sanitizeLlmErrorMessage((string) ($group['error'] ?? '分析失败')),
            ];
        }

        return [
            'scope' => [
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'selected_hotel_count' => $selectedHotelCount > 0 ? $selectedHotelCount : ($hotelCount + $failedHotelCount),
            'success_hotel_count' => $successHotelCount > 0 ? $successHotelCount : $hotelCount,
            'failed_hotel_count' => $failedHotelCount,
            'model_key' => $modelKey,
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'hotel_count' => $hotelCount,
            'groups' => $groups,
            'failed_groups' => $safeFailedGroups,
            'data_quality' => $this->buildCapturedOtaFinalDataQuality($groups),
        ];
    }

    private function buildCapturedOtaProcess(array $summary): array
    {
        return [
            'selected_hotel_count' => (int) ($summary['selected_hotel_count'] ?? 0),
            'success_hotel_count' => (int) ($summary['success_hotel_count'] ?? 0),
            'failed_hotel_count' => (int) ($summary['failed_hotel_count'] ?? 0),
            'group_count' => count($summary['groups'] ?? []),
            'failed_group_count' => count($summary['failed_groups'] ?? []),
            'groups' => array_values($summary['groups'] ?? []),
            'failed_groups' => array_values($summary['failed_groups'] ?? []),
        ];
    }

    private function buildCapturedOtaFallbackReport(array $summary, string $reason = ''): array
    {
        $groups = is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        $failedGroups = is_array($summary['failed_groups'] ?? null) ? $summary['failed_groups'] : [];
        $selectedCount = (int) ($summary['selected_hotel_count'] ?? 0);
        $successCount = (int) ($summary['success_hotel_count'] ?? 0);
        $failedCount = (int) ($summary['failed_hotel_count'] ?? 0);

        $keyFindings = [];
        $competitorInsights = [];
        $problemHotels = [];
        $recommendedActions = [];
        $dataAnomalies = [];
        $priority = 'medium';
        $priorityRank = ['low' => 1, 'medium' => 2, 'high' => 3];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            if (!empty($group['overall_conclusion'])) {
                $keyFindings[] = (string) $group['overall_conclusion'];
            }
            $keyFindings = array_merge($keyFindings, $this->sanitizeReportList($group['key_findings'] ?? [], 3));
            $competitorInsights = array_merge($competitorInsights, $this->sanitizeReportList($group['competitor_insights'] ?? [], 3));
            $problemHotels = array_merge($problemHotels, $this->sanitizeProblemHotels($group['problem_hotels'] ?? [], 4));
            $recommendedActions = array_merge($recommendedActions, $this->sanitizeReportList($group['recommended_actions'] ?? [], 4));
            $dataAnomalies = array_merge($dataAnomalies, $this->sanitizeReportList($group['data_anomalies'] ?? [], 3));
            $groupPriority = (string) ($group['priority'] ?? 'medium');
            if (($priorityRank[$groupPriority] ?? 2) > ($priorityRank[$priority] ?? 2)) {
                $priority = $groupPriority;
            }
        }

        if (!empty($failedGroups)) {
            $dataAnomalies[] = '部分分组汇总失败，报告覆盖可能不完整。';
        }
        if ($reason !== '') {
            $dataAnomalies[] = 'AI综合汇总失败，已自动生成基础综合报告。';
        }

        return [
            'overall_conclusion' => sprintf(
                '已完成 %d/%d 家酒店的OTA抓取数据分析，系统基于成功分组自动归纳基础综合报告。',
                $successCount,
                max($selectedCount, $successCount + $failedCount)
            ),
            'key_findings' => array_values(array_slice(array_unique(array_filter($keyFindings)), 0, 8)),
            'competitor_insights' => array_values(array_slice(array_unique(array_filter($competitorInsights)), 0, 8)),
            'problem_hotels' => $this->uniqueProblemHotels($problemHotels, 10),
            'recommended_actions' => array_values(array_slice(array_unique(array_filter($recommendedActions)), 0, 10)),
            'priority' => $priority,
            'data_anomalies' => array_values(array_slice(array_unique(array_filter($dataAnomalies)), 0, 8)),
            'data_quality' => $summary['data_quality'] ?? $this->buildCapturedOtaFinalDataQuality($groups),
            'fallback' => true,
            'fallback_reason' => $this->sanitizeLlmErrorMessage($reason),
        ];
    }

    private function buildCapturedOtaFinalDataQuality(array $groups): array
    {
        $missingFields = [];
        $zeroFields = [];
        $isCrossDayWindow = false;
        $isReliable = true;
        $warning = '';

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $quality = is_array($group['data_quality'] ?? null) ? $group['data_quality'] : [];
            if (empty($quality)) {
                continue;
            }
            $isCrossDayWindow = $isCrossDayWindow || (bool) ($quality['is_cross_day_window'] ?? false);
            $isReliable = $isReliable && (bool) ($quality['is_reliable'] ?? true);
            $missingFields = array_merge($missingFields, array_values((array) ($quality['missing_fields'] ?? [])));
            $zeroFields = array_merge($zeroFields, array_values((array) ($quality['zero_maybe_unready_fields'] ?? [])));
            if ($warning === '' && trim((string) ($quality['warning'] ?? '')) !== '') {
                $warning = trim((string) $quality['warning']);
            }
        }

        if ($isCrossDayWindow && $warning === '') {
            $warning = '当前可能处于OTA跨日统计窗口，曝光、访客、浏览率、订单率、转化率等流量指标可能尚未完成统计。本次报告优先参考订单、间夜、收入、ADR、评分等已返回指标，流量类指标建议待平台更新后复查。';
        }

        return [
            'is_reliable' => $isReliable,
            'is_cross_day_window' => $isCrossDayWindow,
            'warning' => $warning,
            'missing_fields' => array_values(array_unique(array_filter($missingFields))),
            'zero_maybe_unready_fields' => array_values(array_unique(array_filter($zeroFields))),
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function attachCapturedOtaRecommendationQuality(array $report, array $summary): array
    {
        $scope = is_array($summary['scope'] ?? null) ? $summary['scope'] : [];
        $dateRange = is_array($summary['date_range'] ?? null) ? $summary['date_range'] : [
            'start_date' => (string)($scope['start_date'] ?? ''),
            'end_date' => (string)($scope['end_date'] ?? ''),
        ];
        $platform = strtolower(trim((string)($scope['platform'] ?? 'ota')));
        if ($platform === '') {
            $platform = 'ota';
        }
        $dataQuality = is_array($report['data_quality'] ?? null)
            ? $report['data_quality']
            : (is_array($summary['data_quality'] ?? null) ? $summary['data_quality'] : []);
        $hotelCount = (int)($summary['hotel_count'] ?? $summary['success_hotel_count'] ?? 0);
        $qualityStatus = $hotelCount > 0 && ($dataQuality['is_reliable'] ?? true) === true
            ? 'available'
            : 'unverified';
        $startDate = trim((string)($dateRange['start_date'] ?? $dateRange['start'] ?? ''));
        $endDate = trim((string)($dateRange['end_date'] ?? $dateRange['end'] ?? ''));
        $context = [
            'scope' => 'ota_channel_multi_hotel',
            'platform' => $platform,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'basis_summary' => sprintf(
                '依据本次%s OTA授权捕获摘要（%s至%s，成功覆盖%d家酒店）生成；仅用于OTA渠道比较，不代表全酒店经营事实。',
                strtoupper($platform),
                $startDate !== '' ? $startDate : '日期待核验',
                $endDate !== '' ? $endDate : '日期待核验',
                $hotelCount
            ),
            'evidence_sources' => [[
                'ref' => implode('#', array_filter(['captured_ota_summary', $platform, $startDate, $endDate])),
                'source' => 'authorized_captured_ota_summary',
                'date' => $endDate,
                'platform' => $platform,
                'date_role' => 'historical',
                'scope' => 'ota_channel_multi_hotel',
                'quality_status' => $qualityStatus,
                'metric_keys' => array_values(array_filter(array_keys((array)($summary['totals'] ?? [])))),
                'summary' => trim((string)($dataQuality['warning'] ?? '')),
            ]],
            'default_priority' => (string)($report['priority'] ?? 'medium'),
            'default_risk_level' => ($dataQuality['is_reliable'] ?? true) === true ? 'medium' : 'high',
            'review_window' => '执行前核对目标酒店；执行后按同酒店、同OTA渠道、同日期口径复核',
        ];

        $rawActions = $this->sanitizeReportList($report['recommended_actions'] ?? [], 10);
        foreach ($this->sanitizeProblemHotels($report['problem_hotels'] ?? [], 10) as $hotel) {
            $suggestion = trim((string)($hotel['suggestion'] ?? ''));
            if ($suggestion === '') {
                continue;
            }
            $rawActions[] = [
                'title' => trim((string)($hotel['hotel_name'] ?? '问题酒店')) . '处置建议',
                'action' => $suggestion,
                'priority' => (string)($report['priority'] ?? 'medium'),
                'reason' => trim(implode('；', array_filter([
                    (string)($hotel['problem'] ?? ''),
                    implode('、', (array)($hotel['key_metrics'] ?? [])),
                ]))),
            ];
        }

        $structured = (new AiDecisionQualityService())->enrichRecommendations($rawActions, $context);
        $report['decision_recommendations'] = $structured;
        $report['recommendation_quality'] = (new AiDecisionQualityService())->summarize($structured, $context);
        $report['legacy_recommendation_fields'] = ['recommended_actions', 'problem_hotels[].suggestion'];

        return $report;
    }

    private function extractKnowledgeHotelIds(array $payload): array
    {
        $ids = [];
        $collect = static function ($value) use (&$collect, &$ids): void {
            if (!is_array($value)) {
                return;
            }

            foreach (['system_hotel_id', 'systemHotelId', 'system_hotelId'] as $key) {
                $id = (int)($value[$key] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }

            if (isset($value['hotel']) && is_array($value['hotel'])) {
                foreach (['id', 'system_hotel_id', 'systemHotelId'] as $key) {
                    $id = (int)($value['hotel'][$key] ?? 0);
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }

            foreach ($value as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };

        $collect($payload);

        return array_values($ids);
    }

    private function loadOtaKnowledgeContext(string $platform, string $scene = '', array $hotelIds = []): array
    {
        $keywords = $this->buildOtaKnowledgeKeywords($platform, $scene);
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        $items = [];
        $knowledgeContextGaps = [];
        $hasKnowledgeUnitTables = $this->tableExists('knowledge_units') && $this->tableExists('knowledge_chunks');
        $hasKnowledgeBaseTable = $this->tableExists('knowledge_base');

        if (!$hasKnowledgeUnitTables && !$hasKnowledgeBaseTable) {
            return [
                'status' => 'missing_table',
                'keywords' => $keywords,
                'items' => [],
            ];
        }

        if ($hasKnowledgeUnitTables) {
            $unitColumns = $this->tableColumns('knowledge_units');
            $unitFieldNames = ['unit_id', 'name', 'source', 'status', 'description'];
            if (isset($unitColumns['hotel_id'])) {
                $unitFieldNames[] = 'hotel_id';
            }
            if (isset($unitColumns['created_by'])) {
                $unitFieldNames[] = 'created_by';
            }
            if (isset($unitColumns['lifecycle_status'])) {
                $unitFieldNames[] = 'lifecycle_status';
            }
            if (isset($unitColumns['known_knowns'])) {
                $unitFieldNames[] = 'known_knowns';
            }
            if (isset($unitColumns['known_unknowns'])) {
                $unitFieldNames[] = 'known_unknowns';
            }
            if (isset($unitColumns['truth_profile_version'])) {
                $unitFieldNames[] = 'truth_profile_version';
            }
            if (isset($unitColumns['reviewed_at'])) {
                $unitFieldNames[] = 'reviewed_at';
            }
            if (isset($unitColumns['review_due_at'])) {
                $unitFieldNames[] = 'review_due_at';
            }
            $unitQuery = Db::name('knowledge_units')
                ->field(implode(',', $unitFieldNames))
                ->where('status', 'done');
            if (isset($unitColumns['lifecycle_status'])) {
                $unitQuery->where('lifecycle_status', 'active');
            }
            if (isset($unitColumns['hotel_id']) && isset($unitColumns['created_by']) && $hotelIds) {
                [$keywordSql, $keywordBind] = $this->buildOtaKnowledgeKeywordWhereSql(['name', 'description', 'source'], $keywords, 'ku');
                $unitQuery->where(function ($scope) use ($hotelIds, $keywordSql, $keywordBind): void {
                    $scope->whereIn('hotel_id', $hotelIds)
                        ->whereOr(function ($global) use ($keywordSql, $keywordBind): void {
                            $global->where('hotel_id', 0)->where('created_by', 0);
                            if ($keywordSql !== '') {
                                $global->whereRaw($keywordSql, $keywordBind);
                            }
                        });
                });
            } elseif (isset($unitColumns['hotel_id']) && isset($unitColumns['created_by'])) {
                $unitQuery->where('hotel_id', 0)->where('created_by', 0);
                $this->applyOtaKnowledgeKeywordWhere($unitQuery, ['name', 'description', 'source'], $keywords, 'ku');
            } else {
                $unitQuery->whereRaw('1 = 0');
            }
            $unitRows = $unitQuery->order('unit_id', 'desc')->limit(40)->select()->toArray();
            $unitIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $unitRows)));
            $unitRowsById = [];
            foreach ($unitRows as $unitRow) {
                $unitRowsById[(int)($unitRow['unit_id'] ?? 0)] = $unitRow;
            }
            $chunksByUnit = [];
            $knowledgeGatesByUnit = [];
            $decisionGate = new KnowledgeDecisionGateService();

            if ($unitIds) {
                $chunkRows = Db::name('knowledge_chunks')
                    ->field('chunk_id,unit_id,type,content')
                    ->whereIn('unit_id', $unitIds)
                    ->order('chunk_id', 'desc')
                    ->limit(800)
                    ->select()
                    ->toArray();
                foreach ($chunkRows as &$chunkRow) {
                    $unitId = (int)($chunkRow['unit_id'] ?? 0);
                    $payload = $this->decodeOtaKnowledgePayload($chunkRow['content'] ?? null);
                    $knowledgeGate = $decisionGate->assess($unitRowsById[$unitId] ?? [], $payload);
                    $chunkRow['_knowledge_gate'] = $knowledgeGate;
                    $chunkRow['_decoded_content'] = $payload;
                    if (!$this->isDefaultOtaKnowledgeChunkAllowed(
                        $payload,
                        $platform,
                        $unitRowsById[$unitId] ?? [],
                        $knowledgeGate
                    )) {
                        $chunkRow['_relevance_score'] = -1;
                        continue;
                    }
                    $searchText = mb_strtolower((string)($chunkRow['type'] ?? '') . ' ' . $this->sanitizeOtaKnowledgeText($chunkRow['content'] ?? '', 6000));
                    $score = 0;
                    foreach ($keywords as $keywordIndex => $keyword) {
                        $keyword = mb_strtolower(trim((string)$keyword));
                        if ($keyword === '' || mb_stripos($searchText, $keyword) === false) {
                            continue;
                        }
                        $score += $keywordIndex < 3 ? 4 : 1;
                    }
                    if ($this->otaKnowledgePayloadExplicitlyMatchesPlatform(
                        $chunkRow['content'] ?? null,
                        $platform
                    )) {
                        $score += 8;
                    }
                    $chunkRow['_relevance_score'] = $score;
                }
                unset($chunkRow);

                $claimCandidates = [];
                foreach ($chunkRows as $chunkRow) {
                    if ((int)($chunkRow['_relevance_score'] ?? -1) < 0) {
                        continue;
                    }
                    $claimCandidates[] = [
                        'chunk_id' => (int)($chunkRow['chunk_id'] ?? 0),
                        'unit_id' => (int)($chunkRow['unit_id'] ?? 0),
                        'content' => $chunkRow['_decoded_content'] ?? [],
                    ];
                }
                $conflictResolution = $decisionGate->resolveConflictingClaims($claimCandidates);
                $keptClaimIds = array_fill_keys(array_map(
                    static fn(array $entry): int => (int)($entry['chunk_id'] ?? 0),
                    $conflictResolution['entries']
                ), true);
                if ((int)$conflictResolution['unresolved_conflict_count'] > 0) {
                    $knowledgeContextGaps[] = [
                        'code' => 'knowledge_claim_conflict_unresolved',
                        'label' => '同一知识键存在未解决冲突',
                    ];
                }
                foreach ($chunkRows as &$chunkRow) {
                    if ((int)($chunkRow['_relevance_score'] ?? -1) >= 0
                        && !isset($keptClaimIds[(int)($chunkRow['chunk_id'] ?? 0)])
                    ) {
                        $chunkRow['_relevance_score'] = -1;
                    }
                }
                unset($chunkRow);

                usort($chunkRows, static function (array $left, array $right): int {
                    $scoreCompare = (int)($right['_relevance_score'] ?? 0) <=> (int)($left['_relevance_score'] ?? 0);
                    return $scoreCompare !== 0
                        ? $scoreCompare
                        : ((int)($right['chunk_id'] ?? 0) <=> (int)($left['chunk_id'] ?? 0));
                });
                foreach ($chunkRows as $chunkRow) {
                    $unitId = (int)($chunkRow['unit_id'] ?? 0);
                    if ($unitId <= 0
                        || (int)($chunkRow['_relevance_score'] ?? 0) <= 0
                        || count($chunksByUnit[$unitId] ?? []) >= 6
                    ) {
                        continue;
                    }
                    $chunksByUnit[$unitId][] = trim($this->sanitizeOtaKnowledgeText(
                        (string)($chunkRow['type'] ?? ''),
                        40
                    ) . ': ' . $this->sanitizeOtaKnowledgeText($chunkRow['content'] ?? '', 180), ': ');
                    $knowledgeGatesByUnit[$unitId][] = is_array($chunkRow['_knowledge_gate'] ?? null)
                        ? $chunkRow['_knowledge_gate']
                        : [];
                }
            }

            foreach ($unitRows as $row) {
                $unitId = (int)($row['unit_id'] ?? 0);
                if (empty($chunksByUnit[$unitId])) {
                    continue;
                }
                $items[] = [
                    'source' => 'knowledge_units',
                    'id' => $unitId,
                    'hotel_id' => (int)($row['hotel_id'] ?? 0),
                    'title' => $this->sanitizeOtaKnowledgeText((string)($row['name'] ?? ''), 80),
                    'summary' => $this->sanitizeOtaKnowledgeText($row['description'] ?? '', 220),
                    'known_knowns' => $this->normalizeOtaKnowledgeStatements($row['known_knowns'] ?? []),
                    'known_unknowns' => $this->normalizeOtaKnowledgeStatements($row['known_unknowns'] ?? []),
                    'truth_profile_version' => trim((string)($row['truth_profile_version'] ?? '')),
                    'knowledge_gate' => $this->summarizeOtaKnowledgeGates($knowledgeGatesByUnit[$unitId] ?? []),
                    'chunks' => $chunksByUnit[$unitId] ?? [],
                ];
            }
        }

        if ($hasKnowledgeBaseTable && !$hasKnowledgeUnitTables) {
            $baseQuery = Db::name('knowledge_base')->field('id,title,content,keywords,hotel_id');
            $columns = $this->tableColumns('knowledge_base');
            if (isset($columns['is_enabled'])) {
                $baseQuery->where('is_enabled', 1);
            }
            if ($hotelIds && isset($columns['hotel_id'])) {
                [$keywordSql, $keywordBind] = $this->buildOtaKnowledgeKeywordWhereSql(['title', 'content', 'keywords'], $keywords, 'kb');
                $hotelIdSql = implode(',', $hotelIds);
                $baseQuery->whereRaw(
                    '(`hotel_id` IN (' . $hotelIdSql . ') OR (`hotel_id` = 0 AND ' . $keywordSql . '))',
                    $keywordBind
                );
            } else {
                $this->applyOtaKnowledgeKeywordWhere($baseQuery, ['title', 'content', 'keywords'], $keywords, 'kb');
            }
            $baseRows = $baseQuery->order('id', 'desc')->limit(20)->select()->toArray();
            $acceptedBaseRows = 0;
            foreach ($baseRows as $row) {
                if ($acceptedBaseRows >= 4
                    || !$this->isOtaKnowledgeBaseCompatibleWithPlatform($row, $platform)
                ) {
                    continue;
                }
                $items[] = [
                    'source' => 'knowledge_base',
                    'id' => (int)($row['id'] ?? 0),
                    'hotel_id' => (int)($row['hotel_id'] ?? 0),
                    'title' => $this->sanitizeOtaKnowledgeText((string)($row['title'] ?? ''), 80),
                    'summary' => $this->sanitizeOtaKnowledgeText($row['content'] ?? '', 260),
                    'chunks' => [],
                ];
                $acceptedBaseRows++;
            }
        }

        $unique = $this->deduplicateOtaKnowledgeItems($items, 8);
        $attentionRequired = $knowledgeContextGaps !== [];
        foreach ($unique as $item) {
            if (($item['knowledge_gate']['attention_required'] ?? false) === true) {
                $attentionRequired = true;
                break;
            }
        }

        return [
            'status' => $unique ? ($attentionRequired ? 'partial' : 'available') : 'empty',
            'keywords' => $keywords,
            'items' => $unique,
            'data_gaps' => $knowledgeContextGaps,
        ];
    }

    private function buildOtaKnowledgeKeywords(string $platform, string $scene = ''): array
    {
        $keywords = ['OTA', '酒店指标', '专业口径', '转化率', '流量', '平台评分', '收益管理', '知识库'];
        $platform = strtolower(trim($platform));
        $scene = strtolower(trim($scene));

        if ($platform === 'ctrip') {
            $keywords = array_merge($keywords, ['携程', '服务质量分', 'ebooking']);
        } elseif ($platform === 'meituan') {
            $keywords = array_merge($keywords, ['美团', 'HOS', '预留房']);
        } elseif ($platform === 'dianping') {
            $keywords = array_merge($keywords, ['大众点评', '评价诚信', '违规评价']);
        } elseif (in_array($platform, ['pms', 'dingdandao'], true)) {
            $keywords = array_merge($keywords, ['PMS', '订单来了', '经营日', '夜审', '对账']);
        } elseif ($platform === 'qunar') {
            $keywords = array_merge($keywords, ['去哪儿', '点评分', '转化']);
        }

        if (in_array($scene, ['traffic', 'rank'], true)) {
            $keywords = array_merge($keywords, ['曝光', '访客', 'CTR', '搜索流量']);
        } elseif (in_array($scene, ['business', 'captured', 'captured_final'], true)) {
            $keywords = array_merge($keywords, ['订单', '间夜', 'ADR', 'RevPAR', '诊断模板']);
        }

        return array_values(array_unique(array_filter($keywords, static fn(string $keyword): bool => trim($keyword) !== '')));
    }

    private function applyOtaKnowledgeKeywordWhere($query, array $fields, array $keywords, string $prefix): void
    {
        [$sql, $bind] = $this->buildOtaKnowledgeKeywordWhereSql($fields, $keywords, $prefix);
        if ($sql !== '') {
            $query->whereRaw($sql, $bind);
        }
    }

    private function buildOtaKnowledgeKeywordWhereSql(array $fields, array $keywords, string $prefix): array
    {
        $parts = [];
        $bind = [];
        foreach (array_values($keywords) as $index => $keyword) {
            $fieldParts = [];
            foreach ($fields as $field) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $field)) {
                    continue;
                }
                $name = $prefix . '_' . $field . '_' . $index;
                $fieldParts[] = '`' . $field . '` LIKE :' . $name;
                $bind[$name] = '%' . $keyword . '%';
            }
            if ($fieldParts) {
                $parts[] = '(' . implode(' OR ', $fieldParts) . ')';
            }
        }

        return $parts ? ['(' . implode(' OR ', $parts) . ')', $bind] : ['', []];
    }

    private function sanitizeOtaKnowledgeText($value, int $limit): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\s+/u', ' ', $text);
        return mb_substr((string)$text, 0, $limit);
    }

    /**
     * Default OTA prompts may use reusable methods and guardrails, but case
     * figures require an explicit case-key lookup through the bounded service.
     *
     * @param mixed $content
     */
    private function isDefaultOtaKnowledgeChunkAllowed(
        $content,
        string $platform = '',
        array $unit = [],
        ?array $knowledgeGate = null
    ): bool
    {
        $payload = $this->decodeOtaKnowledgePayload($content);
        $knowledgeGate = $knowledgeGate ?? (new KnowledgeDecisionGateService())->assess($unit, $payload);
        if (($knowledgeGate['retrieval_safe'] ?? false) !== true) {
            return false;
        }

        $scope = strtolower(trim((string)($payload['scope'] ?? '')));
        if ($scope === 'case_reference') {
            return false;
        }

        $evidenceLevel = trim((string)($payload['evidence_level'] ?? ''));
        $sourceRefs = is_array($payload['source_refs'] ?? null) ? $payload['source_refs'] : [];
        if ($scope === '' || $evidenceLevel === '' || $sourceRefs === []) {
            return false;
        }

        $requestedPlatform = $this->normalizeOtaKnowledgePlatform($platform);
        $explicitPlatforms = $this->normalizeOtaKnowledgePlatforms($payload['platforms'] ?? []);
        if ($requestedPlatform !== ''
            && $explicitPlatforms !== []
            && !in_array($requestedPlatform, $explicitPlatforms, true)
        ) {
            return false;
        }

        return filter_var(
            $payload['requires_explicit_case_key'] ?? false,
            FILTER_VALIDATE_BOOL
        ) !== true;
    }

    /**
     * @param array<int, array<string, mixed>> $gates
     * @return array<string, mixed>
     */
    private function summarizeOtaKnowledgeGates(array $gates): array
    {
        $statuses = [];
        $grades = [];
        $freshness = [];
        $reasons = [];
        $decisionSafe = true;
        foreach ($gates as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $status = trim((string)($gate['status'] ?? ''));
            $grade = trim((string)($gate['evidence_grade'] ?? ''));
            $fresh = trim((string)($gate['freshness_status'] ?? ''));
            if ($status !== '') {
                $statuses[$status] = $status;
            }
            if ($grade !== '') {
                $grades[$grade] = $grade;
            }
            if ($fresh !== '') {
                $freshness[$fresh] = $fresh;
            }
            foreach ((array)($gate['reason_codes'] ?? []) as $reason) {
                $reason = trim((string)$reason);
                if ($reason !== '') {
                    $reasons[$reason] = $reason;
                }
            }
            if (($gate['decision_safe'] ?? false) !== true) {
                $decisionSafe = false;
            }
        }

        $status = isset($statuses['known_unknown'])
            ? 'known_unknown'
            : (isset($statuses['reference_only']) ? 'reference_only' : 'approved');
        $attentionRequired = $status === 'known_unknown'
            || isset($freshness['review_due'])
            || isset($freshness['expired'])
            || isset($freshness['not_yet_effective']);

        return [
            'status' => $status,
            'status_label' => match ($status) {
                'known_unknown' => '含已知未知',
                'reference_only' => '仅供参考',
                default => '可用于决策支持',
            },
            'evidence_grades' => array_values($grades),
            'freshness_statuses' => array_values($freshness),
            'decision_safe' => $decisionSafe && $gates !== [],
            'attention_required' => $attentionRequired,
            'reason_codes' => array_values($reasons),
        ];
    }

    /**
     * @param mixed $content
     */
    private function otaKnowledgePayloadExplicitlyMatchesPlatform($content, string $platform): bool
    {
        $requestedPlatform = $this->normalizeOtaKnowledgePlatform($platform);
        if ($requestedPlatform === '') {
            return false;
        }
        $payload = $this->decodeOtaKnowledgePayload($content);
        return in_array(
            $requestedPlatform,
            $this->normalizeOtaKnowledgePlatforms($payload['platforms'] ?? []),
            true
        );
    }

    /**
     * Mirrored staff knowledge has no structured platforms column. Prefer an
     * explicit platform in the title, then keywords, then content. Rows with no
     * explicit platform remain reusable; rows naming another platform are not
     * allowed to enter the requested platform prompt.
     *
     * @param array<string, mixed> $row
     */
    private function isOtaKnowledgeBaseCompatibleWithPlatform(array $row, string $platform): bool
    {
        $requestedPlatform = $this->normalizeOtaKnowledgePlatform($platform);
        if ($requestedPlatform === '') {
            return true;
        }

        foreach (['title', 'keywords', 'content'] as $field) {
            $detected = $this->detectOtaKnowledgePlatformsFromText((string)($row[$field] ?? ''));
            if ($detected !== []) {
                return in_array($requestedPlatform, $detected, true);
            }
        }
        return true;
    }

    /**
     * @param mixed $content
     * @return array<string, mixed>
     */
    private function decodeOtaKnowledgePayload($content): array
    {
        if (is_array($content)) {
            return $content;
        }
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeOtaKnowledgePlatforms($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,，\s]+/u', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $platforms = [];
        foreach ($value as $item) {
            $platform = $this->normalizeOtaKnowledgePlatform((string)$item);
            if ($platform !== '') {
                $platforms[$platform] = $platform;
            }
        }
        return array_values($platforms);
    }

    private function normalizeOtaKnowledgePlatform(string $platform): string
    {
        $platform = mb_strtolower(trim($platform));
        return match ($platform) {
            '携程', 'trip.com', 'ebooking' => 'ctrip',
            '美团' => 'meituan',
            '大众点评', '点评' => 'dianping',
            '订单来了' => 'dingdandao',
            default => $platform,
        };
    }

    /**
     * @return array<int, string>
     */
    private function detectOtaKnowledgePlatformsFromText(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $patterns = [
            'ctrip' => ['携程', 'ctrip', 'trip.com', 'ebooking'],
            'meituan' => ['美团', 'meituan', 'hos'],
            'dianping' => ['大众点评', 'dianping'],
            'qunar' => ['去哪儿', 'qunar'],
            'fliggy' => ['飞猪', 'fliggy'],
            'douyin' => ['抖音', 'douyin'],
            'dingdandao' => ['订单来了', 'dingdandao'],
        ];
        $detected = [];
        foreach ($patterns as $platform => $needles) {
            foreach ($needles as $needle) {
                if (mb_stripos($text, $needle) !== false) {
                    $detected[$platform] = $platform;
                    break;
                }
            }
        }
        return array_values($detected);
    }

    /**
     * Structured knowledge_units entries are appended before their mirrored
     * knowledge_base rows, so title/hotel deduplication keeps the traceable
     * structured copy and avoids repeating the same knowledge in one prompt.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateOtaKnowledgeItems(array $items, int $limit = 8): array
    {
        $unique = [];
        $limit = max(1, min(20, $limit));
        foreach ($items as $item) {
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = (int)($item['hotel_id'] ?? 0) . '#' . mb_strtolower($title);
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $item;
            if (count($unique) >= $limit) {
                break;
            }
        }

        return array_values($unique);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeOtaKnowledgeStatements($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = $this->sanitizeOtaKnowledgeText(is_scalar($item) ? (string)$item : '', 180);
            if ($text !== '') {
                $items[$text] = $text;
            }
        }

        return array_values($items);
    }

    private function formatOtaKnowledgeContextForPrompt(array $summary): string
    {
        $context = is_array($summary['knowledge_context'] ?? null) ? $summary['knowledge_context'] : [];
        $items = is_array($context['items'] ?? null) ? $context['items'] : [];
        if (empty($items)) {
            return '';
        }

        $lines = ['知识库参考（只用于指标解释、诊断口径和行动拆解，不替代本次经营数据）：'];
        foreach (array_slice($items, 0, 6) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = $this->sanitizeOtaKnowledgeText($item['title'] ?? '', 80);
            $itemSummary = $this->sanitizeOtaKnowledgeText($item['summary'] ?? '', 220);
            if ($title === '' && $itemSummary === '') {
                continue;
            }
            $lines[] = '- ' . trim($title . ($itemSummary !== '' ? '：' . $itemSummary : ''));
            $gate = is_array($item['knowledge_gate'] ?? null) ? $item['knowledge_gate'] : [];
            $gateLabel = $this->sanitizeOtaKnowledgeText($gate['status_label'] ?? '', 40);
            $grades = array_values(array_filter(array_map(
                static fn(mixed $grade): string => trim((string)$grade),
                (array)($gate['evidence_grades'] ?? [])
            )));
            if ($gateLabel !== '') {
                $lines[] = '  - 知识状态：' . $gateLabel
                    . ($grades !== [] ? '；证据等级 ' . implode('/', $grades) : '');
            }
            foreach (array_slice((array)($item['known_knowns'] ?? []), 0, 2) as $knownKnown) {
                $knownText = $this->sanitizeOtaKnowledgeText($knownKnown, 180);
                if ($knownText !== '') {
                    $lines[] = '  - 已确认：' . $knownText;
                }
            }
            foreach (array_slice((array)($item['known_unknowns'] ?? []), 0, 2) as $knownUnknown) {
                $unknownText = $this->sanitizeOtaKnowledgeText($knownUnknown, 180);
                if ($unknownText !== '') {
                    $lines[] = '  - 待验证：' . $unknownText;
                }
            }
            foreach (array_slice((array)($item['chunks'] ?? []), 0, 2) as $chunk) {
                $chunkText = $this->sanitizeOtaKnowledgeText($chunk, 180);
                if ($chunkText !== '') {
                    $lines[] = '  - ' . $chunkText;
                }
            }
        }
        $lines[] = '知识库使用规则：只采用结构化、可追溯且通过时间与冲突门禁的知识；“仅供参考”不得写成当前门店事实，“已知未知”只能生成核验要求；已确认内容仍须匹配本次门店、日期和来源；禁止用0、旧数据或默认值补齐；指标分母缺失或为0时写不可计算；平台私有分值不反推权重。';

        return implode("\n", $lines) . "\n";
    }

    private function buildAiGovernanceMeta(string $scenario, array $context, array $extra = []): array
    {
        $payload = $this->buildAiGovernancePayload($scenario, $context, []);
        $knowledgeSources = $payload['knowledge_citations'];
        foreach ($payload['evidence_refs'] as $ref) {
            $knowledgeSources[] = ['ref' => $ref, 'source' => 'database_evidence'];
        }

        return array_merge([
            'module' => 'agent',
            'scenario' => $scenario,
            'prompt_version' => $payload['prompt_version'],
            'knowledge_sources' => $knowledgeSources,
            'confidence_score' => $payload['confidence_score'],
            'low_confidence_reason' => $payload['low_confidence_reason'],
            'decision_impact' => $payload['decision_impact'],
            'human_confirmation_required' => $payload['human_confirmation_required'],
            'human_confirmation_reason' => $payload['human_confirmation_reason'],
            'evaluation_set' => $payload['evaluation_set'],
            'hotel_id' => (int)($context['hotel']['id'] ?? $context['scope']['hotel_id'] ?? 0),
            'user_id' => (int)($this->currentUser->id ?? 0),
        ], $extra);
    }

    private function buildAiGovernancePayload(string $scenario, array $context, array $llmResult): array
    {
        $modelGovernance = is_array($llmResult['data']['governance'] ?? null) ? $llmResult['data']['governance'] : [];
        $knowledgeCitations = $this->extractAiKnowledgeCitations($context['knowledge_context'] ?? []);
        $evidenceRefs = $this->extractAiEvidenceRefs($context);
        $confidenceLevel = $this->resolveAiGovernanceConfidenceLevel($context, $llmResult, $knowledgeCitations, $evidenceRefs);
        $lowConfidence = $confidenceLevel !== 'high';
        $manualRequired = $this->aiGovernanceRequiresManualConfirmation($scenario, $context, $lowConfidence);

        return [
            'scenario' => $scenario,
            'prompt_version' => (string)($modelGovernance['prompt_version'] ?? $this->defaultAiPromptVersion($scenario)),
            'evaluation_set' => $this->defaultAiEvaluationSet($scenario),
            'confidence_level' => $confidenceLevel,
            'confidence_score' => $this->confidenceScoreForLevel($confidenceLevel),
            'low_confidence' => $lowConfidence,
            'low_confidence_reason' => $lowConfidence ? $this->buildAiLowConfidenceReason($context, $llmResult, $knowledgeCitations, $evidenceRefs) : '',
            'human_confirmation_required' => $manualRequired,
            'human_confirmation_reason' => $manualRequired ? $this->buildAiHumanConfirmationReason($scenario, $confidenceLevel, $context) : '',
            'decision_impact' => $this->aiDecisionImpact($scenario),
            'knowledge_citations' => $knowledgeCitations,
            'evidence_refs' => $evidenceRefs,
            'source_policy' => 'database_evidence_and_knowledge_citations_required',
            'model_call' => [
                'call_id' => (string)($modelGovernance['call_id'] ?? $modelGovernance['request_id'] ?? $modelGovernance['call_log_id'] ?? ''),
                'call_log_id' => (int)($modelGovernance['call_log_id'] ?? 0),
                'status' => (string)($modelGovernance['status'] ?? (($llmResult['ok'] ?? false) === true ? 'success' : 'failed')),
                'provider' => (string)($llmResult['provider'] ?? ''),
                'model_key' => (string)($llmResult['model_key'] ?? ''),
                'model' => (string)($llmResult['model'] ?? ''),
            ],
            'log_sink' => 'ai_model_call_logs',
        ];
    }

    /**
     * Expose the actual OTA decision path without pretending that a missing
     * knowledge or model layer produced an answer.
     *
     * @return array<string, mixed>
     */
    private function buildOtaDiagnosisDecisionRoute(array $context): array
    {
        $governance = is_array($context['ai_governance'] ?? null) ? $context['ai_governance'] : [];
        $runtime = is_array($context['analysis_runtime'] ?? null) ? $context['analysis_runtime'] : [];
        $evidenceRefs = array_values(array_filter(
            $this->extractAiEvidenceRefs($context),
            static fn(string $ref): bool => $ref !== '' && !str_contains($ref, 'no_data')
        ));
        $knowledgeCitations = is_array($governance['knowledge_citations'] ?? null)
            ? array_values($governance['knowledge_citations'])
            : $this->extractAiKnowledgeCitations($context['knowledge_context'] ?? []);

        $decisionStatus = strtolower(trim((string)($context['decision_status'] ?? $context['decision_closure']['status'] ?? '')));
        $dataQuality = is_array($context['data_quality'] ?? null) ? $context['data_quality'] : [];
        $evidenceReady = $evidenceRefs !== []
            && ($dataQuality['is_reliable'] ?? true) !== false
            && !in_array($decisionStatus, ['blocked', 'blocked_by_data'], true);

        $modelCalled = ($runtime['model_called'] ?? false) === true;
        $modelCall = is_array($governance['model_call'] ?? null) ? $governance['model_call'] : [];
        $modelStatus = strtolower(trim((string)($modelCall['status'] ?? '')));
        $modelSucceeded = $modelCalled && in_array($modelStatus, ['success', 'ok', 'completed'], true);
        $modelFallback = $modelCalled && !$modelSucceeded;

        $humanRequired = ($governance['human_confirmation_required'] ?? false) === true || !$evidenceReady;
        $finalStatus = !$evidenceReady
            ? 'blocked'
            : ($humanRequired ? 'pending_manual_review' : 'ready');
        $humanReason = strtolower(trim((string)($governance['human_confirmation_reason'] ?? '')));
        $humanDetail = match (true) {
            str_contains($humanReason, 'blocked until required evidence') => '建议动作证据尚未补齐，暂不能进入执行。',
            str_contains($humanReason, 'pending manual review') => '建议动作等待有权限人员人工确认。',
            str_contains($humanReason, 'confidence level') => '当前置信度不足，需要有权限人员复核。',
            str_contains($humanReason, 'operational decision') => '运营决策必须由有权限人员确认。',
            default => '证据不足或建议将影响运营，必须由有权限人员确认。',
        };

        return [
            'version' => 'ota_decision_route.v1',
            'policy' => 'verified_evidence_then_knowledge_then_model_then_human_confirmation',
            'final_status' => $finalStatus,
            'stages' => [
                [
                    'key' => 'verified_evidence',
                    'label' => '真实数据与规则',
                    'status' => $evidenceReady ? 'used' : 'blocked',
                    'status_label' => $evidenceReady ? '已使用' : '证据不足',
                    'detail' => $evidenceReady
                        ? sprintf('已绑定 %d 条可追溯数据库证据，并先执行确定性规则。', count($evidenceRefs))
                        : '目标范围缺少可用数据库证据；未用 0、旧值或默认值补齐。',
                    'refs' => array_slice($evidenceRefs, 0, 6),
                ],
                [
                    'key' => 'knowledge',
                    'label' => '知识解释',
                    'status' => $knowledgeCitations !== [] ? 'used' : 'skipped',
                    'status_label' => $knowledgeCitations !== [] ? '已引用' : '未参与',
                    'detail' => $knowledgeCitations !== []
                        ? sprintf('引用 %d 条知识，只解释口径与行动，不替代本次经营事实。', count($knowledgeCitations))
                        : '当前没有可追溯知识引用；系统保留规则结论，不臆造知识答案。',
                    'refs' => array_values(array_filter(array_map(
                        static fn($item): string => is_array($item) ? trim((string)($item['ref'] ?? '')) : '',
                        array_slice($knowledgeCitations, 0, 6)
                    ))),
                ],
                [
                    'key' => 'model',
                    'label' => '模型增强',
                    'status' => $modelSucceeded ? 'used' : ($modelFallback ? 'fallback' : 'skipped'),
                    'status_label' => $modelSucceeded ? '已增强' : ($modelFallback ? '已降级' : '未调用'),
                    'detail' => $modelSucceeded
                        ? '模型只补充解释与建议，确定性规则和证据门禁仍保留。'
                        : ($modelFallback
                            ? '模型调用未成功，已回退到真实数据与系统规则。'
                            : '本次未调用模型，结果来自真实数据与确定性规则。'),
                    'refs' => array_values(array_filter([
                        trim((string)($modelCall['call_id'] ?? '')),
                        trim((string)($modelCall['model_key'] ?? '')),
                    ])),
                ],
                [
                    'key' => 'human_confirmation',
                    'label' => '人工确认',
                    'status' => $humanRequired ? 'required' : 'not_required',
                    'status_label' => $humanRequired ? '必须确认' : '无需确认',
                    'detail' => $humanRequired
                        ? $humanDetail
                        : '当前结果仅供读取，不包含待执行的运营动作。',
                    'refs' => [],
                ],
            ],
        ];
    }

    private function extractAiKnowledgeCitations($knowledgeContext): array
    {
        $context = is_array($knowledgeContext) ? $knowledgeContext : [];
        $items = is_array($context['items'] ?? null) ? $context['items'] : [];
        $citations = [];
        foreach (array_slice($items, 0, 12) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $source = mb_substr(trim((string)($item['source'] ?? 'knowledge_context')), 0, 80);
            $id = (int)($item['id'] ?? 0);
            $title = mb_substr(trim((string)($item['title'] ?? '')), 0, 160);
            $ref = $source . '#' . ($id > 0 ? (string)$id : substr(hash('sha256', $title), 0, 12));
            $citations[$ref] = [
                'ref' => $ref,
                'source' => $source,
                'title' => $title,
            ];
        }

        return array_values($citations);
    }

    private function extractAiEvidenceRefs(array $context): array
    {
        $refs = [];
        foreach ((array)($context['evidence_sources'] ?? []) as $source) {
            if (!is_array($source)) {
                continue;
            }
            $ref = trim((string)($source['ref'] ?? ''));
            if ($ref !== '') {
                $refs[$ref] = true;
            }
        }
        foreach ((array)($context['action_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['evidence_refs'] ?? []) as $ref) {
                $ref = trim((string)$ref);
                if ($ref !== '') {
                    $refs[$ref] = true;
                }
            }
        }

        return array_slice(array_keys($refs), 0, 30);
    }

    private function resolveAiGovernanceConfidenceLevel(array $context, array $llmResult, array $knowledgeCitations, array $evidenceRefs): string
    {
        if (!empty($llmResult) && ($llmResult['ok'] ?? false) !== true) {
            return 'low';
        }

        $quality = is_array($context['data_quality'] ?? null) ? $context['data_quality'] : [];
        if (($quality['is_reliable'] ?? true) === false) {
            return 'low';
        }
        if (!empty($context['data_gaps']) || $this->hasBlockedAiActionItems($context)) {
            return 'low';
        }

        $missingSections = array_values(array_filter((array)($context['missing_sections'] ?? []), static fn($value): bool => trim((string)$value) !== ''));
        if (count($missingSections) >= 3) {
            return 'low';
        }
        if (!empty($missingSections) || trim((string)($quality['warning'] ?? '')) !== '' || empty($evidenceRefs)) {
            return 'medium';
        }
        if (empty($knowledgeCitations)) {
            return 'medium';
        }

        return 'high';
    }

    private function confidenceScoreForLevel(string $level): float
    {
        return ['high' => 0.9, 'medium' => 0.62, 'low' => 0.35][$level] ?? 0.35;
    }

    private function aiGovernanceRequiresManualConfirmation(string $scenario, array $context, bool $lowConfidence): bool
    {
        if ($lowConfidence || in_array($scenario, ['ota_diagnosis', 'captured_ota_analysis', 'captured_ota_final_summary'], true)) {
            return true;
        }
        foreach ((array)($context['action_items'] ?? []) as $item) {
            if (is_array($item) && ($item['status'] ?? '') === 'pending_manual_review') {
                return true;
            }
        }
        return false;
    }

    private function buildAiLowConfidenceReason(array $context, array $llmResult, array $knowledgeCitations, array $evidenceRefs): string
    {
        if (!empty($llmResult) && ($llmResult['ok'] ?? false) !== true) {
            return 'model call failed or returned fallback content';
        }
        $quality = is_array($context['data_quality'] ?? null) ? $context['data_quality'] : [];
        if (($quality['is_reliable'] ?? true) === false || trim((string)($quality['warning'] ?? '')) !== '') {
            return 'data quality warning requires manual review';
        }
        if (!empty($context['missing_sections'])) {
            return 'source coverage is incomplete';
        }
        if (empty($evidenceRefs)) {
            return 'no database evidence refs attached';
        }
        if (empty($knowledgeCitations)) {
            return 'no knowledge citation attached';
        }
        return 'manual review required by governance policy';
    }

    private function buildAiHumanConfirmationReason(string $scenario, string $confidenceLevel, array $context): string
    {
        if ($this->hasBlockedAiActionItems($context)) {
            return 'recommended actions are blocked until required evidence is repaired';
        }
        foreach ((array)($context['action_items'] ?? []) as $item) {
            if (is_array($item) && ($item['status'] ?? '') === 'pending_manual_review') {
                return 'recommended actions are pending manual review';
            }
        }
        if ($confidenceLevel !== 'high') {
            return 'confidence level ' . $confidenceLevel . ' requires operator review';
        }
        return $this->aiDecisionImpact($scenario) . ' decision requires operator confirmation';
    }

    private function hasBlockedAiActionItems(array $context): bool
    {
        foreach ((array)($context['action_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $status = (string)($item['status'] ?? '');
            if (str_starts_with($status, 'blocked_') || ($item['execution_ready'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    private function aiDecisionImpact(string $scenario): string
    {
        return in_array($scenario, ['ota_diagnosis', 'captured_ota_analysis', 'captured_ota_final_summary'], true)
            ? 'operational'
            : 'none';
    }

    private function defaultAiPromptVersion(string $scenario): string
    {
        return [
            'ota_diagnosis' => 'ota_diagnosis:v1',
            'captured_ota_analysis' => 'captured_ota_analysis:v1',
            'captured_ota_final_summary' => 'captured_ota_final_summary:v1',
            'agent_test_llm' => 'agent_test_llm:v1',
        ][$scenario] ?? ($scenario . ':v1');
    }

    private function defaultAiEvaluationSet(string $scenario): string
    {
        return [
            'ota_diagnosis' => 'ota_diagnosis_governance_v1',
            'captured_ota_analysis' => 'captured_ota_governance_v1',
            'captured_ota_final_summary' => 'captured_ota_final_governance_v1',
            'agent_test_llm' => 'agent_test_llm_smoke_v1',
        ][$scenario] ?? ($scenario . '_governance_v1');
    }

    private function applyCapturedOtaDataQualityGuard(array $report): array
    {
        $quality = is_array($report['data_quality'] ?? null) ? $report['data_quality'] : [];
        $warning = trim((string) ($quality['warning'] ?? ''));
        $isCrossDayWindow = (bool) ($quality['is_cross_day_window'] ?? false);
        $isReliable = ($quality['is_reliable'] ?? true) !== false;
        $shouldUseNoticeTone = $isCrossDayWindow || ($isReliable && $warning !== '');
        if (!$shouldUseNoticeTone) {
            return $report;
        }

        $notice = $isCrossDayWindow
            ? '当前流量类指标可能受OTA统计更新时间影响，暂不作为经营判断依据。本组报告主要基于订单、间夜、收入、ADR、评分等已返回指标进行分析，建议待平台流量数据更新后复查。'
            : ($warning !== '' ? $warning : '流量类指标当前按采集口径提示处理，暂不作为核心经营判断依据。');
        $blockedPhrases = [
            '违反基本漏斗逻辑',
            '严重异常',
            '严重经营异常',
            '严重数据异常',
            '严重采集异常',
            '数据异常',
            '采集异常',
            '严重缺失',
            '漏斗逻辑',
            '无法准确评估实际经营表现',
            '立即联系携程ebooking支持团队',
            '立即联系携程 ebooking 支持团队',
        ];

        if ($this->textContainsAny((string) ($report['overall_conclusion'] ?? ''), $blockedPhrases)) {
            $report['overall_conclusion'] = $notice;
        }

        foreach (['key_findings', 'competitor_insights', 'data_anomalies'] as $field) {
            $list = $this->sanitizeReportList($report[$field] ?? [], 10);
            $list = array_values(array_filter($list, fn($item) => !$this->textContainsAny($item, $blockedPhrases)));
            if ($field === 'data_anomalies' && empty($list)) {
                $list[] = $warning !== '' ? $warning : $notice;
            }
            $report[$field] = $list;
        }

        $report['problem_hotels'] = $this->rewriteProblemHotelDataQualityNotices(
            $report['problem_hotels'] ?? [],
            $blockedPhrases,
            $isCrossDayWindow,
            $warning
        );

        $actions = $this->sanitizeReportList($report['recommended_actions'] ?? [], 10);
        $actions = array_values(array_filter($actions, fn($item) => !$this->textContainsAny($item, $blockedPhrases)));
        $practicalActions = [
            '若当前为凌晨或当天数据，等待平台数据更新后重新同步。',
            '优先查看订单、间夜、收入、ADR、评分判断经营趋势。',
            '次日上午或平台数据稳定后，再复查曝光、访客、转化率。',
            '若历史日期仍长期为0，再检查接口、字段映射或Cookie权限。',
        ];
        $report['recommended_actions'] = array_values(array_slice(array_unique(array_merge($practicalActions, $actions)), 0, 10));

        return $report;
    }

    private function rewriteProblemHotelDataQualityNotices($value, array $blockedPhrases, bool $isCrossDayWindow, string $warning): array
    {
        $hotels = $this->sanitizeProblemHotels($value, 10);
        foreach ($hotels as &$hotel) {
            $problem = (string)($hotel['problem'] ?? '');
            $suggestion = (string)($hotel['suggestion'] ?? '');
            if (!$this->textContainsAny($problem . ' ' . $suggestion, $blockedPhrases)) {
                continue;
            }

            $hotel['problem'] = $isCrossDayWindow
                ? '数据口径提示：流量类指标可能尚未完成统计，暂不单独作为经营问题定性。'
                : '数据口径提示：流量类指标需先复核采集口径，暂不单独作为经营问题定性。';
            $hotel['suggestion'] = $isCrossDayWindow
                ? '待平台流量数据更新后复查，先参考订单、间夜、收入、ADR、评分等已返回指标。'
                : ($warning !== '' ? $warning : '先复核数据口径、字段映射和同步结果，再决定是否进入经营整改。');
        }
        unset($hotel);

        return $hotels;
    }

    private function textContainsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && mb_strpos($text, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeReportList($value, int $limit): array
    {
        $items = is_array($value) ? $value : [$value];
        $safe = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            if (is_array($item)) {
                $parts = [];
                foreach ($item as $key => $val) {
                    if (is_scalar($val) && trim((string) $val) !== '') {
                        $parts[] = mb_substr((string) $key, 0, 40) . ': ' . mb_substr((string) $val, 0, 160);
                    }
                }
                $text = implode('；', $parts);
            } else {
                $text = (string) $item;
            }
            $text = mb_substr(trim($text), 0, 240);
            if ($text !== '') {
                $safe[] = $text;
            }
        }
        return $safe;
    }

    private function sanitizeProblemHotels($value, int $limit): array
    {
        $items = is_array($value) ? ($this->isListArray($value) ? $value : [$value]) : [$value];
        $safe = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $hotel = is_array($item) ? $this->normalizeProblemHotelArray($item) : $this->parseProblemHotelString((string) $item);
            if (!empty(array_filter($hotel, fn($val) => is_array($val) ? !empty($val) : trim((string) $val) !== ''))) {
                $safe[] = $hotel;
            }
        }
        return $safe;
    }

    private function normalizeProblemHotelArray(array $item): array
    {
        $metrics = $item['key_metrics'] ?? $item['关键指标'] ?? [];
        if (is_string($metrics)) {
            $metrics = $this->splitProblemHotelMetrics($metrics);
        } elseif (!is_array($metrics)) {
            $metrics = [];
        }

        return [
            'hotel_name' => mb_substr(trim((string) ($item['hotel_name'] ?? $item['酒店'] ?? $item['name'] ?? '')), 0, 120),
            'problem' => mb_substr(trim((string) ($item['problem'] ?? $item['问题'] ?? '')), 0, 240),
            'key_metrics' => array_values(array_slice(array_filter(array_map(
                fn($metric) => mb_substr(trim((string) $metric), 0, 80),
                $metrics
            )), 0, 8)),
            'suggestion' => mb_substr(trim((string) ($item['suggestion'] ?? $item['建议'] ?? '')), 0, 240),
        ];
    }

    private function parseProblemHotelString(string $text): array
    {
        $text = trim($text);
        $result = [
            'hotel_name' => '',
            'problem' => '',
            'key_metrics' => [],
            'suggestion' => '',
        ];
        if ($text === '') {
            return $result;
        }

        $map = [
            'hotel_name' => 'hotel_name',
            '酒店' => 'hotel_name',
            'problem' => 'problem',
            '问题' => 'problem',
            'key_metrics' => 'key_metrics',
            '关键指标' => 'key_metrics',
            'suggestion' => 'suggestion',
            '建议' => 'suggestion',
        ];
        $keys = implode('|', array_map(fn($key) => preg_quote($key, '/'), array_keys($map)));
        preg_match_all('/(' . $keys . ')\s*[:：]\s*(.*?)(?=\s*(?:' . $keys . ')\s*[:：]|[；;\r\n]+|$)/us', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $key = trim($match[1]);
            $target = $map[$key] ?? null;
            if ($target === null) {
                continue;
            }
            if ($target === 'key_metrics') {
                $result[$target] = $this->splitProblemHotelMetrics($match[2]);
            } else {
                $result[$target] = mb_substr(trim($match[2]), 0, $target === 'hotel_name' ? 120 : 240);
            }
        }

        if ($result['hotel_name'] === '' && $result['problem'] === '' && empty($result['key_metrics']) && $result['suggestion'] === '') {
            $result['problem'] = mb_substr($text, 0, 240);
        }

        return $result;
    }

    private function isListArray(array $value): bool
    {
        $index = 0;
        foreach (array_keys($value) as $key) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    private function splitProblemHotelMetrics(string $metrics): array
    {
        return array_values(array_slice(array_filter(array_map(
            fn($item) => mb_substr(trim((string) $item), 0, 80),
            preg_split('/[、,，；;]\s*/u', $metrics) ?: []
        )), 0, 8));
    }

    private function uniqueProblemHotels(array $items, int $limit): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = md5(json_encode($item, JSON_UNESCAPED_UNICODE));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
            if (count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }

}
