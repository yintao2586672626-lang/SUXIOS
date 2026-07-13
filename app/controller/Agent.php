<?php
declare(strict_types=1);

namespace app\controller;

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
use app\service\AgentClosureReadinessService;
use app\service\CompetitorPriceReadinessService;
use app\service\FeasibilityReportService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\RevenueForecastReadinessService;
use app\service\RevenuePricingRecommendationService;
use think\Response;
use think\facade\Db;

/**
 * Agent控制器
 * 管理 OTA 诊断、收益管理、知识和运行日志能力。
 */
class Agent extends Base
{
    private function feasibilityService(): FeasibilityReportService
    {
        return new FeasibilityReportService();
    }

    private function callLlm(string $prompt, string $modelKey = 'deepseek_v4_default', array $meta = [], array $options = []): array
    {
        return (new LlmClient())->chat($prompt, $modelKey, $meta, $options);
    }

    private function normalizeRequestedModelKey(string $modelKey, array $options = []): string
    {
        $key = trim($modelKey);
        $mode = strtolower(trim((string) ($options['model_mode'] ?? '')));
        if ($key === '') {
            $key = $mode === 'pro' ? 'deepseek_reasoner' : 'deepseek_chat';
        }

        $aliases = [
            'deepseek-v4-pro' => 'deepseek_reasoner',
            'deepseek-reasoner' => 'deepseek_reasoner',
            'deepseek-v4-flash' => 'deepseek_chat',
            'deepseek-chat' => 'deepseek_chat',
        ];
        $lowerKey = strtolower($key);
        if (isset($aliases[$lowerKey])) {
            return $aliases[$lowerKey];
        }

        if ($key === 'deepseek_v4_default') {
            return $mode === 'pro' ? 'deepseek_reasoner' : 'deepseek_chat';
        }

        return $key;
    }

    private function buildLlmDebug(string $errorType, array $config, int $httpStatus, string $curlError, string $prompt, string $response, string $errorMessage, array $meta = [], int $payloadSize = 0): array
    {
        return [
            'error_type' => $errorType,
            'debug' => [
                'provider' => (string) ($config['provider'] ?? ''),
                'model_key' => (string) ($config['model_key'] ?? ''),
                'model' => (string) ($config['model'] ?? ''),
                'model_name' => (string) ($config['model'] ?? ''),
                'config_source' => (string) ($config['source'] ?? ''),
                'http_status' => $httpStatus,
                'curl_errno' => 0,
                'curl_error' => $this->sanitizeLlmErrorMessage($curlError),
                'error_message' => $this->sanitizeLlmErrorMessage($errorMessage),
                'selected_hotel_count' => (int) ($meta['selected_hotel_count'] ?? 0),
                'request_payload_size' => $payloadSize,
                'prompt_length' => (int) ($meta['prompt_length'] ?? mb_strlen($prompt)),
                'response_preview' => $this->safeResponsePreview($response),
            ],
        ];
    }

    private function buildLlmSuccessDebug(array $config, int $httpStatus, string $prompt, array $meta = [], int $payloadSize = 0): array
    {
        return [
            'provider' => (string) ($config['provider'] ?? ''),
            'model_key' => (string) ($config['model_key'] ?? ''),
            'model' => (string) ($config['model'] ?? ''),
            'model_name' => (string) ($config['model'] ?? ''),
            'config_source' => (string) ($config['source'] ?? ''),
            'http_status' => $httpStatus,
            'selected_hotel_count' => (int) ($meta['selected_hotel_count'] ?? 0),
            'request_payload_size' => $payloadSize,
            'prompt_length' => (int) ($meta['prompt_length'] ?? mb_strlen($prompt)),
        ];
    }

    private function safeResponsePreview(string $response): string
    {
        if ($response === '') {
            return '';
        }
        return $this->sanitizeLlmErrorMessage($response, 500);
    }

    private function sanitizeLlmErrorMessage(string $message, int $limit = 300): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ****', $message);
        $message = preg_replace('/(api[_-]?key|authorization|cookie|spidertoken)\s*[:=]\s*[^,\s;]+/i', '$1=****', $message);
        return mb_substr((string) $message, 0, $limit);
    }

    private function isAllowedLlmModelKey(string $modelKey): bool
    {
        $modelKey = $this->normalizeRequestedModelKey($modelKey);
        if (in_array($modelKey, ['deepseek_chat', 'deepseek_reasoner', 'deepseek_v4_default', 'deepseek_v4_flash', 'deepseek_v4_fast', 'deepseek_v4_pro', 'openai_fast'], true)) {
            return true;
        }

        try {
            return AiModelConfig::where('model_key', $modelKey)->find() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查管理员权限
     */
    protected function checkAdmin(): void
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            abort(403, '只有超级管理员可以访问Agent功能');
        }
    }

    protected function checkLogin(): void
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }
    }

    // ==================== Agent概览 ====================

    /**
     * 获取Agent概览数据
     */
    public function overview(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 仅保留有真实业务链路的收益 Agent 状态。
        $agentConfigs = AgentConfig::where('hotel_id', $hotelId)
            ->column('agent_type, is_enabled', 'agent_type');
        
        // 获取最近日志
        $recentLogs = AgentLog::where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(10)
            ->select();
        
        return $this->success([
            'agents' => [
                'revenue' => [
                    'name' => '收益管理Agent',
                    'type' => AgentConfig::AGENT_TYPE_REVENUE,
                    'enabled' => ($agentConfigs[AgentConfig::AGENT_TYPE_REVENUE]['is_enabled'] ?? 0) == 1,
                    'icon' => '💰',
                    'description' => '竞对价格监控、定价建议、需求预测',
                ],
            ],
            'recent_logs' => $recentLogs,
        ]);
    }

    public function testLlm(): Response
    {
        $this->checkAdmin();

        $prompt = trim((string) $this->request->param('prompt', ''));
        if ($prompt === '') {
            $prompt = '请用一句话说明你已接入宿析OS';
        }

        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_v4_default'));
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        $result = $this->callLlm($prompt, $modelKey, [
            'module' => 'agent',
            'scenario' => 'test_llm',
            'prompt_version' => 'agent.test_llm.v1',
            'user_id' => (int)($this->currentUser->id ?? 0),
            'decision_impact' => 'none',
        ], $modelOptions);
        if (($result['ok'] ?? false) !== true) {
            return $this->error((string) $result['message'], (int) $result['code'], [
                'model_key' => $result['model_key'] ?? $modelKey,
                'config_entry' => $result['config_entry'] ?? '/ai-model-config',
                'next_action' => $result['next_action'] ?? '检查模型配置后重试。',
                'debug' => $result['data']['debug'] ?? null,
            ]);
        }

        return $this->success(['content' => $result['content']], 'success');
    }

    public function otaDiagnosis(): Response
    {
        $this->checkAdmin();

        $hotelIdRaw = trim((string) $this->request->param('hotel_id', ''));
        $hotelId = (int) $hotelIdRaw;
        $platformHotelIdRaw = trim((string) $this->request->param('platform_hotel_id', ''));
        $configId = trim((string) $this->request->param('config_id', ''));
        $hotelName = trim((string) $this->request->param('hotel_name', ''));
        $platform = strtolower(trim((string) $this->request->param('platform', 'ctrip')));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));
        $analysisType = strtolower(trim((string) $this->request->param('analysis_type', 'traffic')));
        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_v4_default'));
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
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
        if (strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 不能晚于 end_date', 422);
        }
        if (!in_array($analysisType, ['traffic', 'business', 'all'], true)) {
            return $this->error('analysis_type 仅支持 traffic、business、all', 422);
        }

        try {
            if ($hotelIdRaw === '' && $configId !== '') {
                $config = $this->resolveOtaDiagnosisConfig($platform, $configId);
                if (!empty($config)) {
                    $hotelId = (int) ($config['hotel_id'] ?? $hotelId);
                    $hotelIdRaw = (string) ($config['hotel_id'] ?? $hotelIdRaw);
                    $hotelName = trim((string) ($config['hotel_name'] ?? $hotelName));
                }
            }
            if ($hotelIdRaw === '') {
                return $this->error('请选择有效的酒店配置，诊断必须包含 hotel_id', 422);
            }

            $dataSet = $this->queryOtaDiagnosisData($hotelId, $hotelIdRaw, $platformHotelIdRaw, $platform, $startDate, $endDate, $analysisType);
            if (!$this->hasOtaDiagnosisData($dataSet)) {
                return $this->success($this->buildOtaDiagnosisNoDataResult(
                    $dataSet,
                    $hotelIdRaw,
                    $hotelName,
                    $platform,
                    $startDate,
                    $endDate
                ), '暂无 OTA 数据');
            }

            $effectiveStartDate = (string) ($dataSet['effective_start_date'] ?? $startDate);
            $effectiveEndDate = (string) ($dataSet['effective_end_date'] ?? $endDate);
            $usedLatestAvailableData = !empty($dataSet['used_latest_available_data']);
            $effectiveHotelName = $hotelName !== '' ? $hotelName : trim((string)($dataSet['hotel']['name'] ?? ''));
            $result = $this->buildOtaDiagnosisResult($dataSet, $hotelId, $hotelIdRaw, $effectiveHotelName, $platform, $effectiveStartDate, $effectiveEndDate, $analysisType);
            $result['knowledge_context'] = $this->loadOtaKnowledgeContext($platform, $analysisType, $hotelId > 0 ? [$hotelId] : []);
            $result['evidence_sources'] = $this->buildOtaDiagnosisEvidenceSources($dataSet, $result['metrics'] ?? []);
            if ($usedLatestAvailableData) {
                $result['requested_date_range'] = ['start_date' => $startDate, 'end_date' => $endDate];
                $result['data_summary']['used_latest_available_data'] = true;
                $result['source_policy'] = 'database_only_latest_available_reference_not_execution_ready';
                $result['data_gaps'] = array_values(array_merge((array)($result['data_gaps'] ?? []), [
                    $this->buildOtaLatestAvailableDataGap($startDate, $endDate, $effectiveStartDate, $effectiveEndDate),
                ]));
                $result['data_summary']['analysis_date_note'] = sprintf(
                    '所选日期范围暂无OTA明细，已自动使用最近一次已抓取数据：%s 至 %s。',
                    $effectiveStartDate,
                    $effectiveEndDate
                );
                $result['source_summary']['scope']['requested_start_date'] = $startDate;
                $result['source_summary']['scope']['requested_end_date'] = $endDate;
            }
            $llmResult = $this->callLlm($this->buildOtaDiagnosisPrompt($result), $modelKey, $this->buildAiGovernanceMeta('ota_diagnosis', $result, [
                'hotel_id' => $hotelId,
                'user_id' => (int)($this->currentUser->id ?? 0),
            ]), $modelOptions);
            if (($llmResult['ok'] ?? false) === true) {
                $result['diagnosis'] = array_merge($result['diagnosis'], $this->parseOtaDiagnosisResult((string) $llmResult['content']));
            } else {
                $result['missing_sections'][] = 'AI模型诊断';
                $result['diagnosis']['actions'][] = '模型诊断暂不可用，已基于系统历史数据生成基础诊断。';
            }
            if ($usedLatestAvailableData) {
                $latestDataAction = sprintf(
                    '所选日期范围暂无OTA明细，当前诊断已基于最近一次已抓取数据（%s 至 %s）生成。',
                    $effectiveStartDate,
                    $effectiveEndDate
                );
                $result['diagnosis']['actions'] = array_values(array_unique(array_merge(
                    [$latestDataAction],
                    is_array($result['diagnosis']['actions'] ?? null) ? $result['diagnosis']['actions'] : []
                )));
            }

            $result['core_conclusion'] = $result['diagnosis']['summary'] ?? '';
            $result['main_problems'] = $result['diagnosis']['abnormal_metrics'] ?? [];
            $result['possible_reasons'] = array_values(array_filter([
                $result['diagnosis']['exposure_analysis'] ?? '',
                $result['diagnosis']['visit_conversion_analysis'] ?? '',
                $result['diagnosis']['order_conversion_analysis'] ?? '',
                $result['diagnosis']['price_analysis'] ?? '',
                $result['diagnosis']['competitor_analysis'] ?? '',
                $result['diagnosis']['advertising_analysis'] ?? '',
                $result['diagnosis']['service_quality_analysis'] ?? '',
            ]));
            $result['recommended_actions'] = $result['diagnosis']['actions'] ?? [];
            $result['data_anomalies_needing_confirmation'] = $result['missing_sections'];
            $result['priority'] = $result['diagnosis']['priority'] ?? $result['priority'];
            $result['evidence_sources'] = $this->buildOtaDiagnosisEvidenceSources($dataSet, $result['metrics'] ?? []);
            $result['action_items'] = $this->buildOtaDiagnosisActionItems($result['recommended_actions'], $result['evidence_sources'], $result);
            if ($usedLatestAvailableData) {
                $result = $this->blockOtaDiagnosisActionsForLatestAvailableData($result, $startDate, $endDate, $effectiveStartDate, $effectiveEndDate);
            }
            $result['diagnosis_sections'] = $this->buildOtaDiagnosisSections($result['diagnosis'] ?? [], $result['missing_sections'] ?? []);
            $result['ai_governance'] = $this->buildAiGovernancePayload('ota_diagnosis', $result, $llmResult);
            $result['decision_closure'] = $this->buildAiDecisionClosure($result);
            $result['evidence_report'] = $this->buildOtaEvidenceReport($result);

            return $this->success($result, 'success');
        } catch (\Throwable $e) {
            return $this->error('OTA 诊断失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), 500);
        }
    }

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
                return $this->error('暂无可分析的抓取数据', 422);
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
            $report['summary'] = [
                'hotel_count' => $summary['hotel_count'],
                'input_hotel_count' => $summary['input_hotel_count'],
                'truncated' => $summary['truncated'],
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
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
        $totals = [
            'room_nights' => 0.0,
            'room_revenue' => 0.0,
            'sales' => 0.0,
            'exposure' => 0.0,
            'views' => 0.0,
            'orders' => 0.0,
        ];
        $scoreValues = [];
        $conversionValues = [];
        $flowQualityStats = [
            'exposure' => ['missing' => 0, 'zero' => 0],
            'views' => ['missing' => 0, 'zero' => 0],
            'browse_rate' => ['missing' => 0, 'zero' => 0],
            'order_rate' => ['missing' => 0, 'zero' => 0],
            'conversion_rate' => ['missing' => 0, 'zero' => 0],
        ];

        foreach (array_slice($hotels, 0, $maxHotels) as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }

            $hotelId = substr(trim((string) ($hotel['hotel_id'] ?? $hotel['hotelId'] ?? $hotel['poiId'] ?? '')), 0, 64);
            $hotelName = substr(trim((string) ($hotel['hotel_name'] ?? $hotel['hotelName'] ?? $hotel['name'] ?? '')), 0, 120);
            if ($hotelId === '' && $hotelName === '') {
                continue;
            }

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
            $roomNights = (float) ($safeMetrics['room_nights'] ?? 0);
            $roomRevenue = (float) ($safeMetrics['revenue'] ?? $safeMetrics['room_revenue'] ?? 0);
            $exposure = $this->readCapturedNullableMetric($safeMetrics, ['exposure']);
            $views = $this->readCapturedNullableMetric($safeMetrics, ['visitors', 'views']);
            $orders = (float) ($safeMetrics['orders'] ?? $safeMetrics['total_order_num'] ?? $safeMetrics['book_order_num'] ?? 0);
            $sales = (float) ($safeMetrics['sales'] ?? $safeMetrics['revenue'] ?? $roomRevenue);
            $commentScore = (float) ($safeMetrics['score'] ?? $safeMetrics['comment_score'] ?? 0);
            $viewConversion = $this->readCapturedNullableMetric($safeMetrics, ['view_conversion', 'browse_rate']);
            $payConversion = $this->readCapturedNullableMetric($safeMetrics, ['pay_conversion', 'order_rate']);
            $conversionRate = $this->readCapturedNullableMetric($safeMetrics, ['conversion_rate', 'qunar_detail_cr']);
            $tags = $this->sanitizeCapturedTags($hotel['tags'] ?? []);
            $shortSummary = mb_substr(trim((string) ($hotel['short_summary'] ?? '')), 0, 160);

            $safeMetrics['adr'] = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
            $safeMetrics['view_rate'] = $exposure !== null && $views !== null && $exposure > 0 ? round($views / $exposure * 100, 2) : ($exposure === null || $views === null ? null : 0.0);
            $safeMetrics['order_rate'] = $views !== null && $views > 0 ? round($orders / $views * 100, 2) : ($views === null ? null : 0.0);

            $this->recordCapturedFlowQuality($flowQualityStats, 'exposure', $exposure);
            $this->recordCapturedFlowQuality($flowQualityStats, 'views', $views);
            $this->recordCapturedFlowQuality($flowQualityStats, 'browse_rate', $viewConversion);
            $this->recordCapturedFlowQuality($flowQualityStats, 'order_rate', $payConversion);
            $this->recordCapturedFlowQuality($flowQualityStats, 'conversion_rate', $conversionRate);

            $totals['room_nights'] += $roomNights;
            $totals['room_revenue'] += $roomRevenue;
            $totals['sales'] += $sales;
            $totals['exposure'] += $exposure ?? 0;
            $totals['views'] += $views ?? 0;
            $totals['orders'] += $orders;
            if ($commentScore > 0) {
                $scoreValues[] = $commentScore;
            }
            if ($viewConversion !== null && $viewConversion > 0) {
                $conversionValues[] = $viewConversion;
            }
            if ($payConversion !== null && $payConversion > 0) {
                $conversionValues[] = $payConversion;
            }
            if ($conversionRate !== null && $conversionRate > 0) {
                $conversionValues[] = $conversionRate;
            }

            $rows[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName !== '' ? $hotelName : $hotelId,
                'metrics' => $safeMetrics,
                'tags' => $tags,
                'short_summary' => $shortSummary,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return ((float) ($b['metrics']['revenue'] ?? $b['metrics']['room_revenue'] ?? 0)) <=> ((float) ($a['metrics']['revenue'] ?? $a['metrics']['room_revenue'] ?? 0));
        });

        $dataQuality = $this->buildCapturedOtaDataQuality($flowQualityStats, $totals, $startDate, $endDate, count($rows));

        return [
            'scope' => [
                'platform' => $platform,
                'data_source' => $dataSource,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'input_hotel_count' => $inputCount,
            'hotel_count' => count($rows),
            'truncated' => $inputCount > $maxHotels,
            'totals' => $totals,
            'averages' => [
                'adr' => $this->percentSafeAverage($totals['room_revenue'], $totals['room_nights']),
                'view_rate' => $this->percentRate($totals['views'], $totals['exposure']),
                'order_rate' => $this->percentRate($totals['orders'], $totals['views']),
                'comment_score' => $this->average($scoreValues),
                'conversion_rate' => $this->average($conversionValues),
            ],
            'hotels' => $rows,
            'top_hotels_by_revenue' => array_slice($rows, 0, 10),
            'data_quality' => $dataQuality,
            'data_collection_notice' => $dataQuality['warning'],
            'data_anomalies' => $inputCount > $maxHotels ? ['单次最多分析 50 家酒店，已截断超出部分。'] : [],
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
            $unitFields = isset($unitColumns['hotel_id'])
                ? 'unit_id,hotel_id,name,source,status,description'
                : 'unit_id,name,source,status,description';
            $unitQuery = Db::name('knowledge_units')
                ->field($unitFields)
                ->where('status', 'done');
            if ($hotelIds && isset($unitColumns['hotel_id'])) {
                [$keywordSql, $keywordBind] = $this->buildOtaKnowledgeKeywordWhereSql(['name', 'description', 'source'], $keywords, 'ku');
                $hotelIdSql = implode(',', $hotelIds);
                $unitQuery->whereRaw(
                    '(`hotel_id` IN (' . $hotelIdSql . ') OR (`hotel_id` = 0 AND ' . $keywordSql . '))',
                    $keywordBind
                );
            } else {
                $this->applyOtaKnowledgeKeywordWhere($unitQuery, ['name', 'description', 'source'], $keywords, 'ku');
            }
            $unitRows = $unitQuery->order('unit_id', 'desc')->limit(6)->select()->toArray();
            $unitIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $unitRows)));
            $chunksByUnit = [];

            if ($unitIds) {
                $chunkRows = Db::name('knowledge_chunks')
                    ->field('unit_id,type,content')
                    ->whereIn('unit_id', $unitIds)
                    ->order('chunk_id', 'asc')
                    ->limit(18)
                    ->select()
                    ->toArray();
                foreach ($chunkRows as $chunkRow) {
                    $unitId = (int)($chunkRow['unit_id'] ?? 0);
                    if ($unitId <= 0 || count($chunksByUnit[$unitId] ?? []) >= 3) {
                        continue;
                    }
                    $chunksByUnit[$unitId][] = trim($this->sanitizeOtaKnowledgeText(
                        (string)($chunkRow['type'] ?? ''),
                        40
                    ) . ': ' . $this->sanitizeOtaKnowledgeText($chunkRow['content'] ?? '', 180), ': ');
                }
            }

            foreach ($unitRows as $row) {
                $unitId = (int)($row['unit_id'] ?? 0);
                $items[] = [
                    'source' => 'knowledge_units',
                    'id' => $unitId,
                    'hotel_id' => (int)($row['hotel_id'] ?? 0),
                    'title' => $this->sanitizeOtaKnowledgeText((string)($row['name'] ?? ''), 80),
                    'summary' => $this->sanitizeOtaKnowledgeText($row['description'] ?? '', 220),
                    'chunks' => $chunksByUnit[$unitId] ?? [],
                ];
            }
        }

        if ($hasKnowledgeBaseTable) {
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
            $baseRows = $baseQuery->order('id', 'desc')->limit(4)->select()->toArray();
            foreach ($baseRows as $row) {
                $items[] = [
                    'source' => 'knowledge_base',
                    'id' => (int)($row['id'] ?? 0),
                    'hotel_id' => (int)($row['hotel_id'] ?? 0),
                    'title' => $this->sanitizeOtaKnowledgeText((string)($row['title'] ?? ''), 80),
                    'summary' => $this->sanitizeOtaKnowledgeText($row['content'] ?? '', 260),
                    'chunks' => [],
                ];
            }
        }

        $unique = [];
        foreach ($items as $item) {
            $key = (string)($item['source'] ?? '') . '#' . (string)($item['id'] ?? '') . '#' . (string)($item['title'] ?? '');
            if (($item['title'] ?? '') === '' || isset($unique[$key])) {
                continue;
            }
            $unique[$key] = $item;
            if (count($unique) >= 8) {
                break;
            }
        }

        return [
            'status' => $unique ? 'available' : 'empty',
            'keywords' => $keywords,
            'items' => array_values($unique),
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
            foreach (array_slice((array)($item['chunks'] ?? []), 0, 2) as $chunk) {
                $chunkText = $this->sanitizeOtaKnowledgeText($chunk, 180);
                if ($chunkText !== '') {
                    $lines[] = '  - ' . $chunkText;
                }
            }
        }
        $lines[] = '知识库使用规则：指标必须标注口径，分母缺失或为0时写不可计算；平台私有分值不反推权重；异常描述必须优先写成数据口径提示或需复核提示。';

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

    private function queryOtaDiagnosisData(int $hotelId, string $hotelIdRaw, string $platformHotelIdRaw, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $columns = $this->onlineDailyDataColumns();
        $fields = array_values(array_intersect([
            'id',
            'hotel_id',
            'hotel_name',
            'system_hotel_id',
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
            'create_time',
            'update_time',
        ], array_keys($columns)));

        $onlineRows = [];
        $effectiveStartDate = $startDate;
        $effectiveEndDate = $endDate;
        $usedLatestAvailableData = false;
        $canQueryOnlineRows = !empty($fields)
            && isset($columns['data_date'])
            && (($hotelId > 0 && isset($columns['system_hotel_id'])) || (($hotelIdRaw !== '' || $platformHotelIdRaw !== '') && isset($columns['hotel_id'])));
        if ($canQueryOnlineRows) {
            $applyOnlineScope = function ($query) use ($hotelId, $hotelIdRaw, $platformHotelIdRaw, $platform, $analysisType, $columns) {
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
            $startDate,
            $endDate,
            'report_date'
        );
        $competitorPrices = $this->queryHotelDateRows(
            'competitor_price_log',
            ['id', 'store_id', 'hotel_id', 'platform', 'city', 'price', 'fetch_time', 'create_time', 'update_time'],
            $hotelId,
            'create_time',
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
            'fetch_time',
            function ($query, array $tableColumns) use ($platform): void {
                if (isset($tableColumns['platform'])) {
                    $query->where('platform', $platform);
                }
            }
        );
        $competitorAnalyses = $this->queryHotelDateRows(
            'competitor_analysis',
            ['id', 'hotel_id', 'competitor_hotel_id', 'room_type_id', 'analysis_date', 'our_price', 'competitor_price', 'price_difference', 'price_index', 'ota_platform', 'competitor_data', 'create_time', 'update_time'],
            $hotelId,
            'analysis_date',
            $startDate,
            $endDate,
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
            $startDate,
            $endDate,
            'suggestion_date'
        );
        $syncLogs = $this->queryHotelDateRows(
            'operation_logs',
            ['id', 'hotel_id', 'module', 'action', 'description', 'create_time', 'error_info'],
            $hotelId,
            'create_time',
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
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

        return [
            'hotel' => $hotel ?: ['id' => $hotelIdRaw, 'name' => ''],
            'online_rows' => $onlineRows,
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
        return !empty($dataSet['online_rows'])
            || !empty($dataSet['daily_reports'])
            || !empty($dataSet['competitor_prices'])
            || !empty($dataSet['competitor_analyses'])
            || !empty($dataSet['price_suggestions']);
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
        $result['decision_closure'] = $this->buildAiDecisionClosure($result);
        $result['evidence_report'] = $this->buildOtaEvidenceReport($result);

        return $result;
    }

    private function buildOtaDiagnosisResult(array $dataSet, int $hotelId, string $hotelIdRaw, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $rows = $dataSet['online_rows'] ?? [];
        $dailyReports = $dataSet['daily_reports'] ?? [];
        $competitorPrices = $dataSet['competitor_prices'] ?? [];
        $competitorAnalyses = $dataSet['competitor_analyses'] ?? [];
        $priceSuggestions = $dataSet['price_suggestions'] ?? [];
        $syncLogs = $dataSet['sync_logs'] ?? [];
        $summary = $this->buildOtaDiagnosisSummary($rows, $hotelId, $hotelName, $platform, $startDate, $endDate, $analysisType);
        $totals = $summary['totals'];
        $rates = $summary['derived_rates'];
        $avgCompetitorPrice = $this->nullableAverage(array_merge(
            array_map('floatval', array_column($competitorPrices, 'price')),
            array_map('floatval', array_column($competitorAnalyses, 'competitor_price'))
        ));
        $avgSuggestedPrice = $this->nullableAverage(array_map('floatval', array_column($priceSuggestions, 'suggested_price')));
        $dailyRevenue = $dailyReports === [] ? null : array_sum(array_map('floatval', array_column($dailyReports, 'revenue')));
        $hasTraffic = $this->hasKnownOtaDiagnosisMetric($totals, [
            'list_exposure', 'detail_visitors', 'order_visitors', 'submit_users',
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
        if ($hasTraffic && is_numeric($rates['detail_rate']) && $rates['detail_rate'] > 0 && $rates['detail_rate'] < 5) {
            $abnormal[] = '曝光到访问转化偏低';
        }
        if ($hasTraffic && is_numeric($rates['order_rate']) && $rates['order_rate'] > 0 && $rates['order_rate'] < 3) {
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
        $diagnosis = [
            'summary' => sprintf('已读取%s在%s至%s的历史OTA数据，覆盖%d条OTA记录、%d条日报、%d条竞对价格记录。', $displayHotelName, $startDate, $endDate, count($rows), count($dailyReports), count($competitorPrices)),
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
            'price_analysis' => $avgCompetitorPrice > 0 ? sprintf('竞对均价%s，本店ADR%s，需结合房型和日期校准价差。', $avgCompetitorPrice, $this->formatOtaDiagnosisMetric($metrics['adr'])) : ($avgSuggestedPrice > 0 ? sprintf('已有%d条定价建议，建议均价%s，可结合房态和订单转化复核。', count($priceSuggestions), $avgSuggestedPrice) : '缺少价格/房态/订单相关数据，暂不能判断价格竞争力。'),
            'competitor_analysis' => $hasCompetitor ? '已有竞对或对比数据，可继续关注价格、曝光和转化差距。' : '缺少竞对数据，无法判断同商圈机会。',
            'advertising_analysis' => $hasAdvertising ? sprintf('OTA广告花费%s，归因订单金额%s，ROAS %s。', $this->formatOtaDiagnosisMetric($metrics['advertising_spend']), $this->formatOtaDiagnosisMetric($metrics['advertising_order_amount']), $this->formatOtaDiagnosisMetric($metrics['advertising_roas'])) : '缺少OTA广告数据，暂不评估投放效率。',
            'service_quality_analysis' => $hasServiceQuality ? sprintf('OTA服务质量分%s，服务评分%s。', $this->formatOtaDiagnosisMetric($metrics['avg_psi_score']), $this->formatOtaDiagnosisMetric($metrics['avg_service_score'])) : '缺少OTA服务质量数据，暂不评估服务质量对转化的影响。',
            'comment_analysis' => '',
            'actions' => $this->buildOtaDiagnosisActions($hasTraffic, $hasCompetitor, $hasAdvertising, $hasServiceQuality, $metrics),
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
                'last_sync_time' => $dataSet['last_sync_time'] ?? '',
                'source_counts' => [
                    'online_rows' => count($rows),
                    'daily_reports' => count($dailyReports),
                    'competitor_prices' => count($competitorPrices),
                    'competitor_analyses' => count($competitorAnalyses),
                    'price_suggestions' => count($priceSuggestions),
                    'sync_logs' => count($syncLogs),
                ],
            ],
            'metrics' => $metrics,
            'diagnosis' => $diagnosis,
            'diagnosis_sections' => $this->buildOtaDiagnosisSections($diagnosis, array_values(array_unique($missingSections))),
            'missing_sections' => array_values(array_unique($missingSections)),
            'data_gaps' => $summary['data_gaps'] ?? [],
            'priority' => in_array('访问到订单转化偏低', $abnormal, true) || in_array('曝光到访问转化偏低', $abnormal, true) ? 'high' : 'medium',
            'source_summary' => $summary,
        ];
    }

    private function buildOtaDiagnosisActions(bool $hasTraffic, bool $hasCompetitor, bool $hasAdvertising, bool $hasServiceQuality, array $metrics): array
    {
        $actions = [];
        if ($hasTraffic && is_numeric($metrics['detail_rate'] ?? null) && (float)$metrics['detail_rate'] < 5) {
            $actions[] = '优先优化列表页主图、标题卖点和价格展示，提升曝光到访问转化。';
        }
        if ($hasTraffic && is_numeric($metrics['order_rate'] ?? null) && (float)$metrics['order_rate'] < 3) {
            $actions[] = '检查详情页房型、取消政策、促销和价格阶梯，降低访问后的下单阻力。';
        }
        if ($hasCompetitor) {
            $actions[] = '对比竞对价格和曝光差距，优先处理同价位竞品压制的日期。';
        }
        if ($hasAdvertising && (float)($metrics['advertising_roas'] ?? 0) > 0 && (float)$metrics['advertising_roas'] < 3) {
            $actions[] = '复核OTA广告投放词、出价和落地房型，ROAS低于3时先控预算再优化转化链路。';
        }
        if ($hasServiceQuality && (float)($metrics['avg_psi_score'] ?? 0) > 0 && (float)$metrics['avg_psi_score'] < 85) {
            $actions[] = '把OTA服务质量分作为转化背景信号，先排查服务响应、到店履约和平台服务质量扣分项。';
        }
        if (empty($actions)) {
            $actions[] = '先补齐缺失的数据源，再按曝光、访问、订单、广告效率、服务质量顺序复盘。';
        }
        return $actions;
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
        ]];

        foreach (array_slice($dataSet['online_rows'] ?? [], 0, 20) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'online_daily_data#' . (string)($row['id'] ?? ''),
                'table' => 'online_daily_data',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['data_date'] ?? ''),
                'tags' => $this->buildOtaEvidenceTags('online_daily_data', $row),
                'label' => trim(implode(' ', array_filter([(string)($row['source'] ?? ''), (string)($row['data_type'] ?? ''), (string)($row['compare_type'] ?? '')]))),
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
            ];
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
            ];
        }

        foreach (array_slice($dataSet['competitor_prices'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'competitor_price_log#' . (string)($row['id'] ?? ''),
                'table' => 'competitor_price_log',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['fetch_time'] ?? $row['create_time'] ?? ''),
                'tags' => ['competitor', 'price'],
                'label' => (string)($row['platform'] ?? 'competitor_price'),
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
            ];
        }

        foreach (array_slice($dataSet['competitor_analyses'] ?? [], 0, 10) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sources[] = [
                'ref' => 'competitor_analysis#' . (string)($row['id'] ?? ''),
                'table' => 'competitor_analysis',
                'record_id' => $row['id'] ?? null,
                'date' => (string)($row['analysis_date'] ?? ''),
                'tags' => ['competitor', 'price'],
                'label' => '竞对价格分析',
                'metrics' => $this->buildOtaEvidenceMetricPreview($row),
            ];
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
            ];
        }

        return array_values(array_filter($sources, static fn(array $source): bool => (string)($source['ref'] ?? '') !== '#'));
    }

    private function buildOtaDiagnosisSections(array $diagnosis, array $missingSections): array
    {
        $sections = [
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
                'status' => $status,
                'evidence_refs' => $refs,
                'required_evidence' => $requiredTags,
                'missing_evidence' => $missingEvidence,
                'execution_ready' => $executionReady,
                'can_request_execution_intent' => $executionReady,
                'human_confirmation_required' => true,
                'human_confirmation_status' => $executionReady ? 'pending' : 'blocked',
                'blocked_reason' => $blockedReason,
                'source_policy' => $executionReady
                    ? 'evidence_refs_required_manual_confirmation_before_execution'
                    : 'blocked_until_required_ota_evidence_is_available',
                'confirmation_policy' => 'manual_confirmation_required_before_operation_execution',
            ];
        }
        return $items;
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
            if ((string)($source['table'] ?? '') === 'derived') {
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
            if ((string)($source['table'] ?? '') !== 'derived') {
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
            'data_gaps' => $result['data_gaps'] ?? [],
            'decision_closure' => $result['decision_closure'] ?? null,
        ];
    }

    private function buildAiDecisionClosure(array $result): array
    {
        $actionItems = array_values(array_filter((array)($result['action_items'] ?? []), 'is_array'));
        $readyItems = array_values(array_filter($actionItems, static fn(array $item): bool => ($item['execution_ready'] ?? false) === true));
        $blockedItems = array_values(array_filter($actionItems, static function (array $item): bool {
            $status = (string)($item['status'] ?? '');
            return str_starts_with($status, 'blocked_') || ($item['execution_ready'] ?? true) === false;
        }));
        $dataGaps = array_values(array_filter((array)($result['data_gaps'] ?? []), 'is_array'));
        $governance = is_array($result['ai_governance'] ?? null) ? $result['ai_governance'] : [];
        $blockedReasons = [];
        foreach ($blockedItems as $item) {
            $reason = trim((string)($item['blocked_reason'] ?? ''));
            if ($reason !== '') {
                $blockedReasons[] = $reason;
            }
        }
        foreach ($dataGaps as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            if ($code !== '') {
                $blockedReasons[] = $code;
            }
        }
        $blockedReasons = array_values(array_unique($blockedReasons));
        $status = 'pending_human_confirmation';
        if (empty($readyItems)) {
            $status = 'blocked';
        } elseif (!empty($blockedItems) || !empty($dataGaps)) {
            $status = 'partial_ready';
        }

        return [
            'status' => $status,
            'scope' => 'ota_channel',
            'chain' => 'OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions',
            'data_evidence_input' => [
                'source_policy' => (string)($result['source_policy'] ?? 'database_only'),
                'source_counts' => $result['data_summary']['source_counts'] ?? [],
                'evidence_refs' => $this->extractAiEvidenceRefs($result),
                'data_gaps' => $dataGaps,
                'enough_for_executable_actions' => !empty($readyItems),
            ],
            'diagnostic_conclusion' => [
                'summary' => (string)($result['core_conclusion'] ?? $result['diagnosis']['summary'] ?? ''),
                'main_problems' => $result['main_problems'] ?? [],
                'possible_reasons' => $result['possible_reasons'] ?? [],
                'confidence_level' => (string)($governance['confidence_level'] ?? ''),
            ],
            'suggested_actions' => [
                'ready_count' => count($readyItems),
                'blocked_count' => count($blockedItems),
                'items' => $actionItems,
            ],
            'blocked_state' => [
                'is_blocked' => empty($readyItems) || !empty($blockedItems) || !empty($dataGaps),
                'blocked_reasons' => $blockedReasons,
                'blocked_items' => array_map(static fn(array $item): array => [
                    'id' => (string)($item['id'] ?? ''),
                    'status' => (string)($item['status'] ?? ''),
                    'blocked_reason' => (string)($item['blocked_reason'] ?? ''),
                    'missing_evidence' => $item['missing_evidence'] ?? [],
                ], $blockedItems),
            ],
            'human_confirmation' => [
                'required' => true,
                'status' => !empty($readyItems) ? 'pending' : 'blocked',
                'reason' => (string)($governance['human_confirmation_reason'] ?? 'manual confirmation required before operation execution'),
                'ready_action_ids' => array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $readyItems)),
                'confirm_before_execution' => true,
            ],
        ];
    }

    private function buildOtaEvidenceTags(string $table, array $row): array
    {
        $tags = [$table];
        $dataType = strtolower((string)($row['data_type'] ?? ''));
        if ($dataType !== '') {
            $tags[] = $dataType;
        }
        if (($row['compare_type'] ?? '') === 'competitor') {
            $tags[] = 'competitor';
        }
        if ((float)($row['list_exposure'] ?? 0) > 0 || (float)($row['detail_exposure'] ?? 0) > 0) {
            $tags[] = 'traffic';
        }
        $isNonRevenueType = in_array($dataType, ['advertising', 'quality', 'review', 'ads', 'ad', 'campaign'], true);
        if (!$isNonRevenueType && ((float)($row['amount'] ?? 0) > 0 || (float)($row['quantity'] ?? 0) > 0 || (float)($row['book_order_num'] ?? 0) > 0)) {
            $tags[] = 'revenue';
            $tags[] = 'order';
        }
        if (
            (float)($row['order_visitors'] ?? 0) > 0
            || (float)($row['submit_users'] ?? 0) > 0
            || (float)($row['order_filling_num'] ?? 0) > 0
            || (float)($row['order_submit_num'] ?? 0) > 0
        ) {
            $tags[] = 'order';
        }
        if (in_array($dataType, ['advertising', 'ads', 'ad', 'campaign'], true)) {
            $tags[] = 'advertising';
        }
        if (in_array($dataType, ['quality', 'service', 'service_quality', 'psi'], true)) {
            $tags[] = 'service_quality';
        }
        return array_values(array_unique($tags));
    }

    private function buildOtaEvidenceMetricPreview(array $row): array
    {
        $preview = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'adr', 'revenue', 'price', 'our_price', 'competitor_price',
            'current_price', 'suggested_price', 'list_exposure', 'detail_visitors', 'detail_exposure',
            'order_visitors', 'submit_users', 'order_filling_num', 'order_submit_num',
            'detail_rate', 'order_rate', 'submit_rate',
            'advertising_spend', 'advertising_order_amount', 'advertising_roas', 'avg_psi_score', 'avg_service_score',
            'occupancy_rate', 'room_count', 'guest_count',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $preview[$field] = $row[$field];
            }
        }
        return $preview;
    }

    private function selectOtaEvidenceRefsForAction(string $action, array $evidenceSources): array
    {
        $wantedTags = ['summary'];
        if ($this->textContainsAny($action, ['广告', '投放', 'ROAS', 'roi', 'ad', 'ads', 'advertising', 'campaign'])) {
            $wantedTags[] = 'advertising';
        }
        if ($this->textContainsAny($action, ['服务质量', '服务分', 'PSI', 'psi', 'service', 'quality'])) {
            $wantedTags[] = 'service_quality';
        }
        if ($this->textContainsAny($action, ['曝光', '访问', '流量', '列表', '详情', 'traffic', 'exposure'])) {
            $wantedTags[] = 'traffic';
        }
        if ($this->textContainsAny($action, ['价格', '竞对', 'ADR', '房型', '促销', 'price', 'competitor'])) {
            $wantedTags[] = 'price';
            $wantedTags[] = 'competitor';
        }
        if ($this->textContainsAny($action, ['订单', '下单', '转化', '间夜', 'order', 'conversion'])) {
            $wantedTags[] = 'order';
            $wantedTags[] = 'traffic';
        }
        if ($this->textContainsAny($action, ['补齐', '缺失', '同步', '抓取', '数据源', 'sync', 'missing'])) {
            $wantedTags[] = 'sync_log';
            $wantedTags[] = 'collection';
        }

        $refs = [];
        foreach ($evidenceSources as $source) {
            $sourceTags = is_array($source['tags'] ?? null) ? $source['tags'] : [];
            if (empty(array_intersect($wantedTags, $sourceTags))) {
                continue;
            }
            $ref = (string)($source['ref'] ?? '');
            if ($ref !== '' && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
            if (count($refs) >= 5) {
                break;
            }
        }

        if (empty($refs)) {
            foreach ($evidenceSources as $source) {
                $ref = (string)($source['ref'] ?? '');
                if ($ref !== '' && !in_array($ref, $refs, true)) {
                    $refs[] = $ref;
                }
                if (count($refs) >= 3) {
                    break;
                }
            }
        }

        return $refs;
    }

    private function hasCompareRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['compare_type'] ?? '') === 'competitor') {
                return true;
            }
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (!array_key_exists($table, $cache)) {
            $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        }
        return $cache[$table];
    }

    private function tableColumns(string $table): array
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!$this->tableExists($table)) {
            $cache[$table] = [];
            return [];
        }

        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string) $row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }

    private function existingFields(string $table, array $fields): array
    {
        $columns = $this->tableColumns($table);
        if (empty($columns)) {
            return [];
        }
        return array_values(array_intersect($fields, array_keys($columns)));
    }

    private function queryHotelDateRows(
        string $table,
        array $fields,
        int $hotelId,
        string $dateColumn,
        string $startDate,
        string $endDate,
        string $orderBy,
        ?callable $extraFilter = null,
        string $orderDirection = 'asc',
        int $limit = 0
    ): array {
        if ($hotelId <= 0) {
            return [];
        }

        $columns = $this->tableColumns($table);
        if (empty($columns) || !isset($columns['hotel_id']) || !isset($columns[$dateColumn])) {
            return [];
        }

        $selectedFields = array_values(array_unique(array_merge($fields, ['hotel_id', $dateColumn])));
        $selectedFields = array_values(array_intersect($selectedFields, array_keys($columns)));
        if (empty($selectedFields)) {
            return [];
        }

        $query = Db::name($table)
            ->field(implode(',', $selectedFields))
            ->where('hotel_id', $hotelId)
            ->where($dateColumn, '>=', $startDate)
            ->where($dateColumn, '<=', $endDate);

        if ($extraFilter !== null) {
            $extraFilter($query, $columns);
        }

        if (isset($columns[$orderBy])) {
            $query->order($orderBy, strtolower($orderDirection) === 'desc' ? 'desc' : 'asc');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->select()->toArray();
    }

    private function otaPlatformCode(string $platform): ?int
    {
        return [
            'ctrip' => 1,
            'meituan' => 2,
            'fliggy' => 3,
            'booking' => 4,
            'expedia' => 5,
            'agoda' => 6,
        ][$platform] ?? null;
    }

    private function maxDateTime(array $values): string
    {
        $max = '';
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ($max === '' || strtotime($value) > strtotime($max))) {
                $max = $value;
            }
        }
        return $max;
    }


    private function onlineDailyDataColumns(): array
    {
        return $this->tableColumns('online_daily_data');
    }

    private function buildOtaDiagnosisSummary(array $rows, int $hotelId, string $hotelName, string $platform, string $startDate, string $endDate, string $analysisType): array
    {
        $ownHotelNames = array_values(array_filter([$hotelName], static fn ($value): bool => trim((string)$value) !== ''));
        $summary = [
            'scope' => [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'platform' => $platform,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'analysis_type' => $analysisType,
            ],
            'record_count' => count($rows),
            'date_count' => 0,
            'hotel_names' => [],
            'totals' => [
                'amount' => null,
                'quantity' => null,
                'book_order_num' => null,
                'data_value' => null,
                'list_exposure' => null,
                'detail_visitors' => null,
                'order_visitors' => null,
                'submit_users' => null,
                'advertising_spend' => null,
                'advertising_order_amount' => null,
                'advertising_bookings' => null,
                'advertising_room_nights' => null,
                'advertising_impressions' => null,
                'advertising_clicks' => null,
                'advertising_rows' => 0,
                'service_quality_rows' => 0,
                'hotel_collect' => null,
            ],
            'averages' => [
                'comment_score' => null,
                'qunar_comment_score' => null,
                'adr' => null,
                'avg_psi_score' => null,
                'avg_service_score' => null,
                'avg_im_score' => null,
                'avg_reply_rate' => null,
            ],
            'daily' => [],
            'dimensions' => [],
            'data_anomalies' => [],
        ];

        $psiScores = [];
        $serviceScores = [];
        $imScores = [];
        $replyRates = [];
        $invalidRawCount = 0;
        $zeroValueCount = 0;
        $missingCoreValueCount = 0;

        foreach ($rows as $row) {
            $date = (string) ($row['data_date'] ?? '');
            if ($date === '') {
                continue;
            }

            $amount = $this->readRowNumber($row, 'amount');
            $quantity = $this->readRowNumber($row, 'quantity');
            $bookOrderNum = $this->readRowNumber($row, 'book_order_num');
            $dataValue = $this->readRowNumber($row, 'data_value');
            $dataType = $this->normalizeOtaDiagnosisDataType((string)($row['data_type'] ?? ''));

            $raw = [];
            if (!empty($row['raw_data'])) {
                $decoded = json_decode((string) $row['raw_data'], true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                } else {
                    $invalidRawCount++;
                }
            }

            $isOwnOperatingRow = OtaOperatingScope::isOwnOperatingRow($row, $raw, $ownHotelNames);
            if (!$isOwnOperatingRow && !in_array($dataType, ['advertising', 'quality', 'review'], true)) {
                $summary['excluded_non_operating_rows'] = (int)($summary['excluded_non_operating_rows'] ?? 0) + 1;
                continue;
            }

            if (!isset($summary['daily'][$date])) {
                $summary['daily'][$date] = [
                    'date' => $date,
                    'amount' => null,
                    'quantity' => null,
                    'book_order_num' => null,
                    'data_value' => null,
                    'list_exposure' => null,
                    'detail_visitors' => null,
                    'order_visitors' => null,
                    'submit_users' => null,
                    'advertising_spend' => null,
                    'advertising_order_amount' => null,
                    'advertising_bookings' => null,
                    'advertising_room_nights' => null,
                    'advertising_impressions' => null,
                    'advertising_clicks' => null,
                    'advertising_rows' => 0,
                    'service_quality_rows' => 0,
                    'hotel_collect' => null,
                ];
            }

            $rowHotelName = trim((string) ($row['hotel_name'] ?? ''));
            if ($rowHotelName !== '') {
                $summary['hotel_names'][$rowHotelName] = true;
            }

            $dimension = trim((string) ($row['dimension'] ?? ''));
            $dimensionKey = $dimension !== '' ? $dimension : '未标注维度';
            if (!isset($summary['dimensions'][$dimensionKey])) {
                $summary['dimensions'][$dimensionKey] = ['record_count' => 0, 'data_value' => null];
            }

            if (!in_array($dataType, ['advertising', 'quality', 'review'], true)) {
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'amount', $amount);
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'quantity', $quantity);
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'book_order_num', $bookOrderNum);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'amount', $amount);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'quantity', $quantity);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'book_order_num', $bookOrderNum);
            }
            $this->addNullableOtaDiagnosisMetric($summary['totals'], 'data_value', $dataValue);
            $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'data_value', $dataValue);
            $summary['dimensions'][$dimensionKey]['record_count']++;
            $this->addNullableOtaDiagnosisMetric($summary['dimensions'][$dimensionKey], 'data_value', $dataValue);

            if ($dataType === 'advertising') {
                $advertising = $this->extractOtaAdvertisingMetrics($row, $raw);
                foreach ($advertising as $key => $value) {
                    $this->addNullableOtaDiagnosisMetric($summary['totals'], $key, $value);
                    $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], $key, $value);
                }
                $summary['totals']['advertising_rows']++;
                $summary['daily'][$date]['advertising_rows']++;
            }

            if ($dataType === 'quality') {
                $quality = $this->extractOtaQualityMetrics($row, $raw);
                $summary['totals']['service_quality_rows']++;
                $summary['daily'][$date]['service_quality_rows']++;
                $this->addNullableOtaDiagnosisMetric($summary['totals'], 'hotel_collect', $quality['hotel_collect'] ?? null);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], 'hotel_collect', $quality['hotel_collect'] ?? null);
                if ($quality['avg_psi_score'] !== null) {
                    $psiScores[] = (float)$quality['avg_psi_score'];
                }
                if ($quality['avg_service_score'] !== null) {
                    $serviceScores[] = (float)$quality['avg_service_score'];
                }
                if ($quality['avg_im_score'] !== null) {
                    $imScores[] = (float)$quality['avg_im_score'];
                }
                if ($quality['avg_reply_rate'] !== null) {
                    $replyRates[] = (float)$quality['avg_reply_rate'];
                }
            }

            $traffic = $this->extractOtaTrafficMetrics($row, $raw);
            foreach ($traffic as $key => $value) {
                $this->addNullableOtaDiagnosisMetric($summary['totals'], $key, $value);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], $key, $value);
            }

            $knownCoreValues = array_values(array_filter(
                [$amount, $quantity, $bookOrderNum, $dataValue],
                static fn(?float $value): bool => $value !== null
            ));
            if ($knownCoreValues === []) {
                $missingCoreValueCount++;
            } elseif (count(array_filter($knownCoreValues, static fn(float $value): bool => $value > 0)) === 0) {
                $zeroValueCount++;
            }
        }

        $summary['date_count'] = count($summary['daily']);
        $summary['hotel_names'] = array_values(array_keys($summary['hotel_names']));
        $summary['daily'] = array_values($summary['daily']);
        $summary['dimensions'] = $this->topDimensionStats($summary['dimensions']);
        $summary['averages']['adr'] = $this->nullableSafeAverage($summary['totals']['amount'], $summary['totals']['quantity']);
        $summary['averages']['avg_psi_score'] = $this->nullableAverage($psiScores);
        $summary['averages']['avg_service_score'] = $this->nullableAverage($serviceScores);
        $summary['averages']['avg_im_score'] = $this->nullableAverage($imScores);
        $summary['averages']['avg_reply_rate'] = $this->nullableAverage($replyRates);
        $summary['averages']['advertising_roas'] = $this->nullableSafeAverage($summary['totals']['advertising_order_amount'], $summary['totals']['advertising_spend']);
        $summary['derived_rates'] = [
            'detail_rate' => $this->nullablePercentRate($summary['totals']['detail_visitors'], $summary['totals']['list_exposure']),
            'order_rate' => $this->nullablePercentRate($summary['totals']['order_visitors'], $summary['totals']['detail_visitors']),
            'submit_rate' => $this->nullablePercentRate($summary['totals']['submit_users'], $summary['totals']['order_visitors']),
        ];
        $summary['data_gaps'] = array_values(array_map(
            static fn(string $field): string => 'metric_missing:' . $field,
            array_keys(array_filter(
                $summary['totals'],
                static fn(mixed $value, string $field): bool => !in_array($field, ['advertising_rows', 'service_quality_rows'], true) && $value === null,
                ARRAY_FILTER_USE_BOTH
            ))
        ));

        $missingDates = $this->missingDates($startDate, $endDate, array_column($summary['daily'], 'date'));
        if (!empty($missingDates)) {
            $summary['data_anomalies'][] = '日期缺失: ' . implode(',', $missingDates);
        }
        if ($invalidRawCount > 0) {
            $summary['data_anomalies'][] = '原始 JSON 解析失败记录数: ' . $invalidRawCount;
        }
        if ($zeroValueCount > 0) {
            $summary['data_anomalies'][] = '全指标为 0 的记录数: ' . $zeroValueCount;
        }
        if ($missingCoreValueCount > 0) {
            $summary['data_anomalies'][] = '核心指标未返回的记录数: ' . $missingCoreValueCount;
        }

        return $summary;
    }

    private function extractOtaTrafficMetrics(array $row, array $raw): array
    {
        $listExposure = $this->readRowNumber($row, 'list_exposure');
        if ($listExposure === null) {
            $listExposure = $this->readSummaryNumber($raw, ['listExposure', 'list_exposure', 'exposure'], null);
        }
        if ($listExposure === null && ($row['data_type'] ?? '') === 'traffic') {
            $listExposure = $this->readRowNumber($row, 'data_value');
        }

        $detailVisitors = $this->readRowNumber($row, 'detail_exposure');
        if ($detailVisitors === null) {
            $detailVisitors = $this->readSummaryNumber($raw, ['detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors'], null);
        }

        $orderVisitors = $this->readRowNumber($row, 'order_filling_num');
        if ($orderVisitors === null) {
            $orderVisitors = $this->readSummaryNumber($raw, ['orderFillingNum', 'order_filling_num', 'orderVisitors'], null);
        }

        $submitUsers = $this->readRowNumber($row, 'order_submit_num');
        if ($submitUsers === null) {
            $submitUsers = $this->readSummaryNumber($raw, ['orderSubmitNum', 'order_submit_num', 'submitUsers'], null);
        }

        return [
            'list_exposure' => $listExposure,
            'detail_visitors' => $detailVisitors,
            'order_visitors' => $orderVisitors,
            'submit_users' => $submitUsers,
        ];
    }

    private function normalizeOtaDiagnosisDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['ads', 'ad', 'advertising', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        return $value;
    }

    private function extractOtaAdvertisingMetrics(array $row, array $raw): array
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $spend = $this->readRowNumberFromKeys($row, ['amount', 'spend', 'cost', 'today_cost'])
            ?? $this->readSummaryNumber($detail, ['spend', 'cost', 'todayCost', 'today_cost'], null);
        $orderAmount = $this->readSummaryNumber($detail, ['orderAmount', 'order_amount', 'bookAmount', 'saleAmount', 'revenue'], null);
        if ($orderAmount === null) {
            $roas = $this->readRowNumberFromKeys($row, ['data_value', 'roas'])
                ?? $this->readSummaryNumber($detail, ['roas', 'roi'], null);
            $orderAmount = $spend !== null && $roas !== null ? (float)$spend * (float)$roas : null;
        }

        return [
            'advertising_spend' => $spend,
            'advertising_order_amount' => $orderAmount,
            'advertising_bookings' => $this->readRowNumberFromKeys($row, ['book_order_num', 'bookings', 'order_count'])
                ?? $this->readSummaryNumber($detail, ['bookings', 'bookingCount', 'orderCount', 'orderQuantity'], null),
            'advertising_room_nights' => $this->readRowNumberFromKeys($row, ['quantity', 'room_nights'])
                ?? $this->readSummaryNumber($detail, ['roomNights', 'room_nights', 'nights'], null),
            'advertising_impressions' => $this->readRowNumberFromKeys($row, ['list_exposure', 'impressions'])
                ?? $this->readSummaryNumber($detail, ['impressions', 'exposure', 'listExposure'], null),
            'advertising_clicks' => $this->readRowNumberFromKeys($row, ['detail_exposure', 'clicks'])
                ?? $this->readSummaryNumber($detail, ['clicks', 'clickCount', 'detailExposure'], null),
        ];
    }

    /**
     * @return array<string, float|int|null>
     */
    private function extractOtaQualityMetrics(array $row, array $raw): array
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $psiScore = $this->readSummaryNumber($detail, ['psiScore', 'psi_score'], null)
            ?? $this->readRowNumberFromKeys($row, ['psi_score', 'data_value']);

        return [
            'avg_psi_score' => $psiScore,
            'avg_service_score' => $this->readSummaryNumber($detail, ['serviceScore', 'service_score'], null)
                ?? $this->readRowNumberFromKeys($row, ['service_score']),
            'avg_im_score' => $this->readSummaryNumber($detail, ['imScore', 'im_score'], null)
                ?? $this->readRowNumberFromKeys($row, ['im_score']),
            'avg_reply_rate' => $this->readSummaryNumber($detail, ['replyRate', 'reply_rate'], null)
                ?? $this->readRowNumberFromKeys($row, ['reply_rate']),
            'hotel_collect' => $this->readSummaryNumber($detail, ['hotelCollect', 'hotel_collect'], null)
                ?? $this->readRowNumberFromKeys($row, ['hotel_collect']),
        ];
    }

    private function otaDiagnosisRawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
    }

    private function readRowNumberFromKeys(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->readRowNumber($row, $key);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function readRowNumber(array $row, string $key): ?float
    {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (float) $row[$key];
        }
        return null;
    }

    private function readSummaryNumber(array $data, array $keys, ?float $default): ?float
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }
        return $default;
    }

    private function addNullableOtaDiagnosisMetric(array &$bucket, string $field, mixed $value): void
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return;
        }
        $bucket[$field] = ($bucket[$field] ?? 0) + (float)$value;
    }

    private function hasKnownOtaDiagnosisMetric(array $metrics, array $fields): bool
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $metrics) && $metrics[$field] !== null && $metrics[$field] !== '') {
                return true;
            }
        }
        return false;
    }

    private function nullablePercentRate(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator * 100, 2);
    }

    private function nullableSafeAverage(mixed $numerator, mixed $denominator): ?float
    {
        if (!is_numeric($numerator) || !is_numeric($denominator) || (float)$denominator <= 0) {
            return null;
        }
        return round((float)$numerator / (float)$denominator, 2);
    }

    private function formatOtaDiagnosisMetric(mixed $value, string $suffix = ''): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '未返回';
        }
        return (string)$value . $suffix;
    }

    private function topDimensionStats(array $dimensions): array
    {
        uasort($dimensions, function (array $a, array $b): int {
            $left = $a['data_value'] ?? null;
            $right = $b['data_value'] ?? null;
            if ($left === null) {
                return $right === null ? 0 : 1;
            }
            if ($right === null) {
                return -1;
            }
            return (float)$right <=> (float)$left;
        });
        return array_slice($dimensions, 0, 10, true);
    }

    private function average(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return round(array_sum($values) / count($values), 2);
    }

    private function nullableAverage(array $values): ?float
    {
        return $values === [] ? null : $this->average($values);
    }

    private function percentRate(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator * 100, 2);
    }

    private function percentSafeAverage(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }
        return round($numerator / $denominator, 2);
    }

    private function missingDates(string $startDate, string $endDate, array $existingDates): array
    {
        $existing = array_flip($existingDates);
        $missing = [];
        for ($time = strtotime($startDate); $time <= strtotime($endDate); $time += 86400) {
            $date = date('Y-m-d', $time);
            if (!isset($existing[$date])) {
                $missing[] = $date;
            }
        }
        return $missing;
    }

    private function buildOtaDiagnosisPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是宿析OS酒店OTA经营分析顾问。只基于以下系统已入库数据摘要输出诊断，不要实时抓取OTA后台，不要把Cookie状态作为历史诊断失败原因，不要编造未提供的数据。\n"
            . "可使用知识库参考解释指标口径、诊断模板和行动拆解，但经营结论必须来自本次结构化摘要。\n"
            . "必须返回 JSON，字段为 summary、data_overview、abnormal_metrics、traffic_analysis、exposure_analysis、visit_conversion_analysis、order_conversion_analysis、price_analysis、competitor_analysis、advertising_analysis、service_quality_analysis、actions、priority。\n"
            . "data_overview、abnormal_metrics、actions 必须是数组；priority 只能是 high、medium、low。\n"
            . "异常描述必须优先写成数据口径提示或需复核提示；除非历史日期多次同步仍异常，不输出严重异常、严重采集异常或违反基本漏斗逻辑。\n"
            . "建议动作必须受证据约束：证据不足时只输出补数据、复核或blocked类动作，不输出调价、投放、运营执行等可执行建议。\n"
            . $knowledgeContext
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是宿析OS酒店OTA经营分析顾问。经营结论只基于以下前端当前抓取的携程ebooking结构化摘要；知识库只用于解释指标口径、诊断模板和行动拆解，不要查询或假设其他经营数据。\n"
            . "必须返回 JSON，字段为 overall_conclusion、key_findings、competitor_insights、problem_hotels、recommended_actions、priority、data_anomalies。\n"
            . "key_findings、competitor_insights、recommended_actions、data_anomalies 必须是字符串数组；priority 只能是 high、medium、low。\n"
            . "problem_hotels 必须是对象数组，固定格式为 {\"hotel_name\":\"酒店名\",\"problem\":\"问题\",\"key_metrics\":[\"订单127\",\"间夜104\",\"ADR 387.60\",\"评分4.6\"],\"suggestion\":\"建议\"}，不允许返回字符串数组。\n"
            . "曝光、访客、浏览率、订单率、转化率为0时，必须先看 data_quality.is_cross_day_window；若处于OTA跨日统计窗口，不要判断为经营异常，统一表述为“流量类指标可能尚未完成统计”。\n"
            . "当天或刚过12点的数据，订单、间夜、收入、ADR、评分作为主要分析依据；流量漏斗类指标只作为数据完整性提示，不作为核心经营判断。\n"
            . "若 data_quality.warning 非空，必须把它归类为“数据口径提示”或“数据未完全更新”，不能写成“严重采集异常”或核心经营结论。\n"
            . "字段名 data_anomalies 是兼容字段；当 data_quality.warning 非空或处于跨日统计窗口时，内容写数据口径提示、数据未完全更新或需复核提示，不写异常定性。\n"
            . "不要输出“违反基本漏斗逻辑”“严重异常”“严重采集异常”等绝对结论，除非是历史日期且确认多次采集仍异常。\n"
            . "建议动作优先为：等待平台数据更新后重新同步；先看订单、间夜、收入、ADR、评分；次日上午复查曝光、访客、转化率；历史日期长期为0再检查接口、字段映射或Cookie权限。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。不要输出 API Key、Cookie 或认证信息。\n"
            . $knowledgeContext
            . "结构化摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function buildCapturedOtaFinalPrompt(array $summary): string
    {
        $knowledgeContext = $this->formatOtaKnowledgeContextForPrompt($summary);
        return "你是酒店OTA经营分析顾问。请基于多个分组分析结果，输出一份面向酒店经营者的综合诊断报告。\n"
            . "不要逐组复述，要综合归纳。只基于分组报告摘要，不要使用完整原始抓取数据或假设数据；知识库只用于解释指标口径、诊断模板和行动拆解。\n"
            . "重点回答：1. 整体经营现状；2. 最大问题；3. 最值得关注的酒店；4. 竞对机会；5. 价格与订单表现、流量数据口径提示；6. 下一步最优先的运营动作。\n"
            . "返回 JSON：{\"overall_conclusion\":\"总体结论\",\"key_findings\":[],\"competitor_insights\":[],\"problem_hotels\":[{\"hotel_name\":\"酒店名\",\"problem\":\"问题\",\"key_metrics\":[],\"suggestion\":\"建议\"}],\"recommended_actions\":[],\"priority\":\"high/medium/low\",\"data_anomalies\":[]}\n"
            . "key_findings、competitor_insights、recommended_actions、data_anomalies 必须是字符串数组；problem_hotels 必须是对象数组，不允许返回字符串数组；priority 只能是 high、medium、low。\n"
            . "若 data_quality.is_cross_day_window 为 true，曝光、访客、浏览率、订单率、转化率为0只作为数据口径提示，不能作为核心经营异常或严重结论。\n"
            . "综合结论主要基于订单、间夜、收入、ADR、评分等已返回指标；流量漏斗类指标建议待平台更新后复查。\n"
            . "若 data_quality.warning 非空，必须把它归类为“数据口径提示”或“数据未完全更新”，不能写成“严重采集异常”或核心经营结论。\n"
            . "字段名 data_anomalies 是兼容字段；当 data_quality.warning 非空或处于跨日统计窗口时，内容写数据口径提示、数据未完全更新或需复核提示，不写异常定性。\n"
            . "不要输出“违反基本漏斗逻辑”“严重异常”“严重采集异常”等绝对结论，除非是历史日期且确认多次采集仍异常。\n"
            . "建议动作优先为等待平台更新后重新同步、先看订单/间夜/收入/ADR/评分、次日上午复查流量指标，历史日期长期为0再检查接口、字段映射或Cookie权限。\n"
            . "只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。若存在失败组，请在 data_anomalies 中提示分析覆盖不足。不要输出 API Key、Cookie 或认证信息。\n"
            . $knowledgeContext
            . "分组报告摘要：\n"
            . json_encode($summary, JSON_UNESCAPED_UNICODE);
    }

    private function parseOtaDiagnosisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'core_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'main_problems' => [],
                'possible_reasons' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies_needing_confirmation' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'summary' => (string) ($data['summary'] ?? $data['core_conclusion'] ?? ''),
            'data_overview' => array_values((array) ($data['data_overview'] ?? [])),
            'abnormal_metrics' => array_values((array) ($data['abnormal_metrics'] ?? $data['main_problems'] ?? [])),
            'traffic_analysis' => (string) ($data['traffic_analysis'] ?? ''),
            'exposure_analysis' => (string) ($data['exposure_analysis'] ?? ''),
            'visit_conversion_analysis' => (string) ($data['visit_conversion_analysis'] ?? ''),
            'order_conversion_analysis' => (string) ($data['order_conversion_analysis'] ?? ''),
            'price_analysis' => (string) ($data['price_analysis'] ?? ''),
            'competitor_analysis' => (string) ($data['competitor_analysis'] ?? ''),
            'advertising_analysis' => (string) ($data['advertising_analysis'] ?? ''),
            'service_quality_analysis' => (string) ($data['service_quality_analysis'] ?? ''),
            'comment_analysis' => '',
            'actions' => array_values((array) ($data['actions'] ?? $data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
        ];
    }

    private function parseCapturedOtaAnalysisResult(string $content): array
    {
        $json = $this->extractJsonObjectFromText($content);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [
                'overall_conclusion' => '模型未返回可解析 JSON，已返回原始文本供人工判断。',
                'key_findings' => [],
                'competitor_insights' => [],
                'problem_hotels' => [],
                'recommended_actions' => [],
                'priority' => 'medium',
                'data_anomalies' => ['模型返回格式不是 JSON。'],
                'raw_text' => $content,
                'parse_warning' => '模型未返回标准JSON',
            ];
        }

        return [
            'overall_conclusion' => (string) ($data['overall_conclusion'] ?? ''),
            'key_findings' => array_values((array) ($data['key_findings'] ?? [])),
            'competitor_insights' => array_values((array) ($data['competitor_insights'] ?? [])),
            'problem_hotels' => $this->sanitizeProblemHotels($data['problem_hotels'] ?? [], 10),
            'recommended_actions' => array_values((array) ($data['recommended_actions'] ?? [])),
            'priority' => (string) ($data['priority'] ?? 'medium'),
            'data_anomalies' => array_values((array) ($data['data_anomalies'] ?? [])),
            'data_quality' => is_array($data['data_quality'] ?? null) ? $data['data_quality'] : [],
        ];
    }

    private function extractJsonObjectFromText(string $content): string
    {
        $json = trim($content);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim($matches[1]);
        }
        if (json_decode($json, true) !== null) {
            return $json;
        }
        $start = strpos($json, '{');
        $end = strrpos($json, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($json, $start, $end - $start + 1);
        }
        return $json;
    }

    public function feasibilityReportGenerate(): Response
    {
        $this->checkLogin();

        try {
            $data = $this->request->post();
            $report = $this->feasibilityService()->generate($data, (int) ($this->currentUser->id ?? 0));
            OperationLog::record('agent', 'feasibility_generate', '生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'report_id' => $report['id'] ?? 0,
                'project_name' => $report['project_name'] ?? '',
            ]);

            return $this->success($report, '报告生成成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_generate', '生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportDetail(): Response
    {
        $this->checkLogin();

        $id = (int) $this->request->param('id', 0);
        $report = $this->feasibilityService()->detail($id, (int) ($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
        if (!$report) {
            return $this->error('报告不存在', 404);
        }

        return $this->success($report);
    }

    public function feasibilityReportRegenerate(): Response
    {
        $this->checkLogin();

        try {
            $id = (int) $this->request->param('id', 0);
            $report = $this->feasibilityService()->regenerate($id, (int) ($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$report) {
                return $this->error('报告不存在', 404);
            }

            OperationLog::record('agent', 'feasibility_regenerate', '重新生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'source_report_id' => $id,
                'report_id' => $report['id'] ?? 0,
            ]);

            return $this->success($report, '报告重新生成成功');
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_regenerate', '重新生成智策可行性报告失败', $e->getMessage(), (int) ($this->currentUser->id ?? 0));
            return $this->error('报告重新生成失败：' . $e->getMessage(), 500);
        }
    }

    public function feasibilityReportList(): Response
    {
        $this->checkLogin();

        $pagination = $this->getPagination();
        return $this->success($this->feasibilityService()->list($pagination['page'], $pagination['page_size'], (int) ($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
    }

    public function feasibilityReportArchive(): Response
    {
        $this->checkLogin();

        try {
            $id = (int) $this->request->param('id', 0);
            if ($id <= 0) {
                return $this->error('报告ID无效', 422);
            }

            $archived = $this->feasibilityService()->archive($id, (int) ($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('报告不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '报告已归档');
        } catch (\Throwable $e) {
            return $this->error('报告归档失败：' . $e->getMessage(), 400);
        }
    }

    // ==================== Agent配置 ====================

    /**
     * 获取Agent配置
     */
    public function createFeasibilityExecutionIntent(): Response
    {
        $this->checkLogin();

        $id = (int) $this->request->param('id', 0);
        if ($id <= 0) {
            return $this->error('feasibility report id is invalid', 422);
        }

        $hotelId = (int) $this->request->param('hotel_id', 0);
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required for feasibility execution tracking', 422);
        }

        $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permittedHotelIds) || !in_array($hotelId, $permittedHotelIds, true)) {
            return $this->error('hotel_id is not permitted', 403);
        }

        $userId = (int) ($this->currentUser->id ?? 0);
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $feasibilityService = $this->feasibilityService();
        $report = $feasibilityService->detail($id, $userId, $isSuperAdmin);
        if (!$report) {
            return $this->error('feasibility report not found', 404);
        }

        if ((int)($report['report']['execution_intent_id'] ?? 0) > 0) {
            return $this->error('feasibility report already linked to execution intent', 409);
        }

        try {
            $result = Db::transaction(function () use ($feasibilityService, $report, $id, $hotelId, $permittedHotelIds, $userId, $isSuperAdmin): array {
                $operationService = new OperationManagementService();
                $input = $feasibilityService->buildExecutionIntentInput($report, $hotelId, [
                    'date_start' => (string)$this->request->param('date_start', ''),
                    'date_end' => (string)$this->request->param('date_end', ''),
                ]);
                $intent = $operationService->createExecutionIntent($permittedHotelIds, $hotelId, $input, $userId);
                $updatedReport = $feasibilityService->attachExecutionTracking($id, $userId, $isSuperAdmin, [
                    'execution_intent_id' => (int)($intent['id'] ?? 0),
                    'hotel_id' => $hotelId,
                    'status' => (string)($intent['status'] ?? ''),
                ]);

                return [
                    'execution_intent' => $intent,
                    'report' => $updatedReport,
                ];
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            OperationLog::error('agent', 'feasibility_execution_intent_create', 'create feasibility execution intent failed', $e->getMessage(), $userId);
            return $this->error($e->getMessage() ?: 'create feasibility execution intent failed', 500);
        }

        OperationLog::record('agent', 'feasibility_execution_intent_create', 'Create execution intent from feasibility report', $userId, null, null, [
            'report_id' => $id,
            'execution_intent_id' => (int)($result['execution_intent']['id'] ?? 0),
            'hotel_id' => $hotelId,
        ]);

        return $this->success($result, 'execution intent created');
    }

    public function getConfig(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);

        if ($agentType !== AgentConfig::AGENT_TYPE_REVENUE) {
            return $this->error('当前仅保留收益管理 Agent 配置', 422);
        }
        
        $config = AgentConfig::where('hotel_id', $hotelId)
            ->where('agent_type', $agentType)
            ->find();
        
        if (!$config) {
            // 返回默认配置
            $defaultConfig = [
                'price_monitor_interval' => 60,
                'auto_pricing_enabled' => false,
                'pricing_strategy' => 'balanced',
                'min_profit_margin' => 15,
                'max_price_adjustment' => 20,
                'notification_channels' => ['wechat'],
            ];
            
            return $this->success([
                'agent_type' => $agentType,
                'is_enabled' => false,
                'config_data' => $defaultConfig,
            ]);
        }
        
        return $this->success($config);
    }

    /**
     * 保存Agent配置
     */
    public function saveConfig(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'agent_type' => 'require|integer|in:2',
            'is_enabled' => 'require|integer|in:0,1',
        ]);
        
        $config = AgentConfig::where('hotel_id', $data['hotel_id'])
            ->where('agent_type', $data['agent_type'])
            ->find();
        
        if (!$config) {
            $config = new AgentConfig();
            $config->hotel_id = $data['hotel_id'];
            $config->agent_type = $data['agent_type'];
        }
        
        $config->is_enabled = $data['is_enabled'];
        $config->config_data = $data['config_data'] ?? [];
        $config->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            $data['agent_type'],
            'config_update',
            'Agent配置已更新',
            AgentLog::LEVEL_INFO,
            ['is_enabled' => $data['is_enabled']],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '配置保存成功');
    }

    // ==================== 知识库 ====================

    /**
     * 获取知识库列表
     */
    public function knowledgeList(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $categoryId = (int) $this->request->param('category_id', 0);
        $keyword = (string) $this->request->param('keyword', '');
        
        $query = KnowledgeBase::where('hotel_id', $hotelId);
        
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }
        
        if ($keyword) {
            $query->searchKeyword($keyword);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('category')
            ->order('sort_order', 'asc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select()
            ->toArray();
        $list = (new AgentClosureReadinessService())->enrichKnowledgeRows($list);
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 保存知识库条目
     */
    public function saveKnowledge(): Response
    {
        $this->checkAdmin();
        
        $data = $this->request->post();
        
        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'title' => 'require|max:200',
            'content' => 'require',
        ]);
        
        if (!empty($data['id'])) {
            $knowledge = KnowledgeBase::find($data['id']);
            if (!$knowledge) {
                return $this->error('知识库条目不存在');
            }
        } else {
            $knowledge = new KnowledgeBase();
            $knowledge->hotel_id = $data['hotel_id'];
        }
        
        $knowledge->category_id = $data['category_id'] ?? 0;
        $knowledge->title = $data['title'];
        $knowledge->content = $data['content'];
        $knowledge->keywords = $data['keywords'] ?? '';
        $knowledge->tags = $data['tags'] ?? [];
        $knowledge->sort_order = $data['sort_order'] ?? 0;
        $knowledge->is_enabled = $data['is_enabled'] ?? 1;
        $knowledge->save();
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_update',
            '知识库条目已保存: ' . $data['title'],
            AgentLog::LEVEL_INFO,
            ['knowledge_id' => $knowledge->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $knowledge->id], '保存成功');
    }

    /**
     * 删除知识库条目
     */
    public function deleteKnowledge(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $knowledge = KnowledgeBase::find($id);
        
        if (!$knowledge) {
            return $this->error('知识库条目不存在');
        }
        
        $hotelId = $knowledge->hotel_id;
        $title = $knowledge->title;
        $knowledge->delete();
        
        // 记录日志
        AgentLog::record(
            $hotelId,
            AgentLog::AGENT_TYPE_STAFF,
            'knowledge_delete',
            '知识库条目已删除: ' . $title,
            AgentLog::LEVEL_WARNING,
            [],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, '删除成功');
    }

    /**
     * 获取知识库分类
     */
    public function knowledgeCategories(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $tree = KnowledgeCategory::getTree($hotelId);
        
        return $this->success($tree);
    }

    // ==================== 收益管理Agent - 增强功能 ====================

    /**
     * 获取需求预测
     */
    public function demandForecasts(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d'));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d', strtotime('+30 days')));
        
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate)->toArray();
        $forecastIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $forecasts), static fn(int $id): bool => $id > 0));
        $forecasts = (new RevenueForecastReadinessService())->enrichForecastRows($forecasts, $this->priceSuggestionStatsByForecastId($hotelId, $forecastIds));
        
        // 获取准确率统计
        $accuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        
        return $this->success([
            'forecasts' => $forecasts,
            'accuracy' => $accuracy,
            'high_demand_dates' => DemandForecast::getHighDemandDates($hotelId, 80),
        ]);
    }

    private function priceSuggestionStatsByForecastId(int $hotelId, array $forecastIds): array
    {
        $forecastIds = array_values(array_unique(array_filter(array_map('intval', $forecastIds), static fn(int $id): bool => $id > 0)));
        if ($hotelId <= 0 || empty($forecastIds)) {
            return [];
        }

        $rows = PriceSuggestion::where('hotel_id', $hotelId)
            ->whereIn('demand_forecast_id', $forecastIds)
            ->field(
                'demand_forecast_id, COUNT(*) AS suggestion_count, '
                . 'SUM(CASE WHEN status IN (2, 4) THEN 1 ELSE 0 END) AS approved_count, '
                . 'SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) AS applied_count, '
                . 'MAX(update_time) AS latest_suggestion_at'
            )
            ->group('demand_forecast_id')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $forecastId = (int)($row['demand_forecast_id'] ?? 0);
            if ($forecastId <= 0) {
                continue;
            }
            $result[$forecastId] = [
                'suggestion_count' => (int)($row['suggestion_count'] ?? 0),
                'approved_count' => (int)($row['approved_count'] ?? 0),
                'applied_count' => (int)($row['applied_count'] ?? 0),
                'latest_suggestion_at' => (string)($row['latest_suggestion_at'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 创建需求预测
     */
    public function createForecast(): Response
    {
        $this->checkAdmin();
        
        try {
            $data = $this->normalizeDemandForecastPayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        
        $forecast = DemandForecast::createForecast($data['hotel_id'], $data['forecast_date'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_create',
            '需求预测已创建: ' . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            ['forecast_id' => $forecast->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $forecast->id], '预测创建成功');
    }

    /**
     * 获取竞对分析
     */
    public function competitorAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        // 获取价格矩阵
        $priceMatrix = CompetitorAnalysis::getPriceMatrix($hotelId, $date);
        $competitorReadinessService = new CompetitorPriceReadinessService();
        $priceMatrix = $competitorReadinessService->enrichPriceMatrix(
            $priceMatrix,
            $this->priceSuggestionStatsByRoomTypeId(
                $hotelId,
                $date,
                $competitorReadinessService->roomTypeIdsFromPriceMatrix($priceMatrix)
            )
        );
        
        // 获取价格波动预警
        $alerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 20);
        
        // 获取价格趋势
        $competitors = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->group('competitor_hotel_id')
            ->column('competitor_hotel_id');
        
        $trends = [];
        foreach ($competitors as $competitorId) {
            $trends[$competitorId] = CompetitorAnalysis::getPriceTrend($hotelId, $competitorId);
        }
        
        return $this->success([
            'price_matrix' => $priceMatrix,
            'alerts' => $alerts,
            'trends' => $trends,
            'date' => $date,
        ]);
    }

    /**
     * @param array<int, mixed> $roomTypeIds
     * @return array<int, array<string, mixed>>
     */
    private function priceSuggestionStatsByRoomTypeId(int $hotelId, string $date, array $roomTypeIds): array
    {
        $roomTypeIds = array_values(array_unique(array_filter(
            array_map('intval', $roomTypeIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelId <= 0 || empty($roomTypeIds) || !$this->isDateString($date)) {
            return [];
        }

        $rows = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date)
            ->whereIn('room_type_id', $roomTypeIds)
            ->field(
                'room_type_id, COUNT(*) AS suggestion_count, '
                . 'SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending_count, '
                . 'SUM(CASE WHEN status IN (2, 4) THEN 1 ELSE 0 END) AS approved_count, '
                . 'SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) AS rejected_count, '
                . 'SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) AS applied_count, '
                . 'MAX(update_time) AS latest_suggestion_at'
            )
            ->group('room_type_id')
            ->select()
            ->toArray();

        $result = [];
        foreach ($rows as $row) {
            $roomTypeId = (int)($row['room_type_id'] ?? 0);
            if ($roomTypeId <= 0) {
                continue;
            }
            $result[$roomTypeId] = [
                'suggestion_count' => (int)($row['suggestion_count'] ?? 0),
                'pending_count' => (int)($row['pending_count'] ?? 0),
                'approved_count' => (int)($row['approved_count'] ?? 0),
                'rejected_count' => (int)($row['rejected_count'] ?? 0),
                'applied_count' => (int)($row['applied_count'] ?? 0),
                'latest_suggestion_at' => (string)($row['latest_suggestion_at'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDemandForecastPayload(array $data): array
    {
        $hotelId = (int)($data['hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required');
        }

        $forecastDate = trim((string)($data['forecast_date'] ?? ''));
        if (!$this->isDateString($forecastDate)) {
            throw new \InvalidArgumentException('forecast_date must be YYYY-MM-DD');
        }

        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        if ($roomTypeId <= 0) {
            throw new \InvalidArgumentException('room_type_id is required');
        }

        $forecastMethod = (int)($data['forecast_method'] ?? DemandForecast::METHOD_HYBRID);
        if (!in_array($forecastMethod, [
            DemandForecast::METHOD_ARIMA,
            DemandForecast::METHOD_LLM,
            DemandForecast::METHOD_HYBRID,
            DemandForecast::METHOD_ML,
        ], true)) {
            throw new \InvalidArgumentException('forecast_method is invalid');
        }

        return [
            'hotel_id' => $hotelId,
            'forecast_date' => $forecastDate,
            'room_type_id' => $roomTypeId,
            'forecast_method' => $forecastMethod,
            'predicted_occupancy' => $this->parseBoundedNumber($data['predicted_occupancy'] ?? null, 'predicted_occupancy', 0.0, 100.0, false),
            'predicted_demand' => (int)round($this->parseBoundedNumber($data['predicted_demand'] ?? 0, 'predicted_demand', 0.0, null, true)),
            'confidence_score' => $this->normalizeConfidenceScore($data['confidence_score'] ?? ($data['confidence_percent'] ?? 0.8)),
            'is_event_driven' => (int)($data['is_event_driven'] ?? 0) === 1 ? 1 : 0,
            'event_factors' => is_array($data['event_factors'] ?? null) ? array_values((array)$data['event_factors']) : [],
            'historical_data' => $this->manualCtripPricingInputMetadata($data['historical_data'] ?? [], 'manual_demand_forecast'),
            'remark' => trim((string)($data['remark'] ?? 'operator_provided_ctrip_pricing_preflight_demand_forecast')),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeCtripCompetitorPricePayload(array $data): array
    {
        $hotelId = (int)($data['hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required');
        }

        $analysisDate = trim((string)($data['analysis_date'] ?? ''));
        if (!$this->isDateString($analysisDate)) {
            throw new \InvalidArgumentException('analysis_date must be YYYY-MM-DD');
        }

        $roomTypeId = (int)($data['room_type_id'] ?? 0);
        if ($roomTypeId <= 0) {
            throw new \InvalidArgumentException('room_type_id is required');
        }

        $platform = (int)($data['ota_platform'] ?? CompetitorAnalysis::PLATFORM_CTRIP);
        if ($platform !== CompetitorAnalysis::PLATFORM_CTRIP) {
            throw new \InvalidArgumentException('ota_platform must be ctrip for current pricing preflight');
        }

        $competitorId = max(0, (int)($data['competitor_hotel_id'] ?? 0));
        $competitorData = is_array($data['competitor_data'] ?? null) ? $data['competitor_data'] : [];
        $competitorName = trim((string)($data['competitor_name'] ?? ($competitorData['competitor_name'] ?? '')));
        if ($competitorId <= 0 && $competitorName === '') {
            throw new \InvalidArgumentException('competitor_name is required when competitor_hotel_id is unknown');
        }

        $ourPrice = $this->parsePositiveRoomTypeMoney($data['our_price'] ?? null, 'our_price');
        $competitorPrice = $this->parsePositiveRoomTypeMoney($data['competitor_price'] ?? null, 'competitor_price');
        $competitorData = $this->manualCtripPricingInputMetadata($competitorData, 'manual_ctrip_competitor_price_sample');
        $competitorData['competitor_name'] = $competitorName;

        return [
            'hotel_id' => $hotelId,
            'competitor_hotel_id' => $competitorId,
            'analysis_date' => $analysisDate,
            'room_type_id' => $roomTypeId,
            'competitor_room_type_id' => max(0, (int)($data['competitor_room_type_id'] ?? 0)),
            'our_price' => $ourPrice,
            'competitor_price' => $competitorPrice,
            'price_index' => round($ourPrice / $competitorPrice * 100, 2),
            'ota_platform' => CompetitorAnalysis::PLATFORM_CTRIP,
            'competitor_data' => $competitorData,
        ];
    }

    private function parseBoundedNumber(mixed $value, string $field, float $min, ?float $max = null, bool $allowMin = true): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || !is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' must be numeric');
        }

        $number = round((float)$value, 4);
        if ($allowMin ? $number < $min : $number <= $min) {
            throw new \InvalidArgumentException($field . ' is below allowed range');
        }
        if ($max !== null && $number > $max) {
            throw new \InvalidArgumentException($field . ' is above allowed range');
        }

        return $number;
    }

    private function normalizeConfidenceScore(mixed $value): float
    {
        $confidence = $this->parseBoundedNumber($value, 'confidence_score', 0.0, 100.0, false);
        if ($confidence > 1.0) {
            $confidence = round($confidence / 100, 4);
        }
        if ($confidence <= 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('confidence_score must be between 0 and 1 or 1 and 100 percent');
        }

        return $confidence;
    }

    /**
     * @return array<string, mixed>
     */
    private function manualCtripPricingInputMetadata(mixed $metadata, string $inputType): array
    {
        $result = is_array($metadata) ? $metadata : [];
        $result['input_scope'] = 'manual_pricing_configuration';
        $result['source_scope'] = 'ctrip_ota_channel';
        $result['target_workflow'] = 'ctrip_revenue_ai_pricing_generation';
        $result['evidence_status'] = 'operator_provided';
        $result['auto_write_ota'] = false;
        $result['input_type'] = $inputType;

        return $result;
    }

    /**
     * 记录竞对价格
     */
    public function recordCompetitorPrice(): Response
    {
        $this->checkAdmin();
        
        try {
            $data = $this->normalizeCtripCompetitorPricePayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        
        $analysis = CompetitorAnalysis::recordAnalysis(
            $data['hotel_id'],
            $data['competitor_hotel_id'],
            $data
        );
        
        return $this->success(['id' => $analysis->id], '记录成功');
    }

    /**
     * 获取定价建议列表
     */
    public function priceSuggestions(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        
        $query = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select()
            ->toArray();
        $pricingService = new RevenuePricingRecommendationService();
        $list = $pricingService->enrichSuggestionRows(
            $list,
            $this->priceSuggestionExecutionItemsByRecordId($hotelId, array_column($list, 'id'))
        );
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * @param array<int, mixed> $suggestionIds
     * @return array<int, array<string, mixed>>
     */
    private function priceSuggestionExecutionItemsByRecordId(int $hotelId, array $suggestionIds): array
    {
        $suggestionIds = array_values(array_filter(
            array_map('intval', $suggestionIds),
            static fn(int $id): bool => $id > 0
        ));
        if ($hotelId <= 0 || empty($suggestionIds)) {
            return [];
        }

        try {
            $flow = (new OperationManagementService())->executionFlow([$hotelId], $hotelId, ['object_type' => 'price']);
        } catch (\Throwable $e) {
            return [];
        }

        $idSet = array_fill_keys($suggestionIds, true);
        $items = [];
        foreach ((array)($flow['list'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $recommendation = is_array($item['recommendation'] ?? null) ? $item['recommendation'] : [];
            if ((string)($recommendation['source_module'] ?? '') !== 'price_suggestion') {
                continue;
            }
            $sourceRecordId = (int)($recommendation['source_record_id'] ?? 0);
            if (!isset($idSet[$sourceRecordId])) {
                continue;
            }
            if (!isset($items[$sourceRecordId]) || (int)($item['id'] ?? 0) > (int)($items[$sourceRecordId]['id'] ?? 0)) {
                $items[$sourceRecordId] = $item;
            }
        }

        return $items;
    }

    /**
     * 审批定价建议
     */
    public function approvePrice(): Response
    {
        $this->checkAdmin();
        
        $id = (int) $this->request->param('id', 0);
        $action = (string) $this->request->param('action', 'approve'); // approve/reject
        $remark = (string) $this->request->param('remark', '');
        
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('定价建议不存在');
        }
        
        if ($action === 'approve') {
            $suggestion->approve($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已批准';
        } else {
            $suggestion->reject($this->currentUser->id ?? 0, $remark);
            $message = '定价建议已拒绝';
        }
        
        // 记录日志
        AgentLog::record(
            $suggestion->hotel_id,
            AgentLog::AGENT_TYPE_REVENUE,
            'price_' . $action,
            $message . ': ' . $suggestion->room_type_name,
            AgentLog::LEVEL_INFO,
            ['suggestion_id' => $id, 'suggested_price' => $suggestion->suggested_price],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(null, $message);
    }

    public function generatePriceSuggestions(): Response
    {
        $this->checkAdmin();

        $hotelId = (int)$this->request->param('hotel_id', 0);
        $date = (string)$this->request->param('date', date('Y-m-d'));
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }

        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        $pricingService = new RevenuePricingRecommendationService();
        if (count($roomTypes) === 0) {
            return $this->success(
                $this->buildPriceSuggestionGenerationBlockedResult(
                    'room_types_empty',
                    $hotelId,
                    $date,
                    [],
                    '携程目标酒店暂无启用房型，不能生成待审调价建议。'
                ),
                'price suggestion generation blocked'
            );
        }
        $created = [];
        $skipped = [];
        foreach ($roomTypes as $roomType) {
            $roomTypeId = (int)$roomType->id;
            $exists = PriceSuggestion::where('hotel_id', $hotelId)
                ->where('room_type_id', $roomTypeId)
                ->where('suggestion_date', $date)
                ->where('status', PriceSuggestion::STATUS_PENDING)
                ->find();
            if ($exists) {
                $skipped[] = [
                    'room_type_id' => $roomTypeId,
                    'room_type_name' => (string)($roomType->name ?? ''),
                    'reason' => 'pending_suggestion_exists',
                    'existing_suggestion_id' => (int)$exists->id,
                    'primary_signal_count' => 0,
                    'price_change_rate' => 0.0,
                    'risk_level' => 'medium',
                    'data_gaps' => [],
                    'review_checklist' => ['Review or close the existing pending suggestion before generating another one.'],
                ];
                continue;
            }

            $recommendation = $pricingService->recommend($hotelId, $roomType->toArray(), $date);
            if (($recommendation['should_create'] ?? false) !== true) {
                $skipped[] = [
                    'room_type_id' => $roomTypeId,
                    'room_type_name' => (string)($roomType->name ?? ''),
                    'reason' => (string)($recommendation['skip_reason'] ?? 'not_created'),
                    'primary_signal_count' => (int)($recommendation['primary_signal_count'] ?? 0),
                    'price_change_rate' => (float)($recommendation['price_change_rate'] ?? 0),
                    'risk_level' => (string)($recommendation['risk_level'] ?? 'high'),
                    'data_gaps' => array_values((array)($recommendation['factors']['signals']['data_gaps'] ?? [])),
                    'review_checklist' => array_values((array)($recommendation['review_checklist'] ?? [])),
                ];
                continue;
            }

            $suggestion = PriceSuggestion::create([
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'suggestion_type' => PriceSuggestion::TYPE_DYNAMIC,
                'status' => PriceSuggestion::STATUS_PENDING,
                'suggestion_date' => $date,
                'current_price' => (float)$recommendation['current_price'],
                'suggested_price' => (float)$recommendation['suggested_price'],
                'min_price' => (float)$roomType->min_price,
                'max_price' => (float)$roomType->max_price,
                'confidence_score' => (float)$recommendation['confidence_score'],
                'competitor_data' => $recommendation['competitor_data'] ?? [],
                'factors' => $recommendation['factors'] ?? [],
                'demand_forecast_id' => (int)($recommendation['factors']['signals']['demand_forecast']['id'] ?? 0),
                'reason' => (string)$recommendation['reason'],
            ]);
            $createdRow = $suggestion->toArray();
            $createdRow['risk_level'] = (string)($recommendation['risk_level'] ?? 'medium');
            $createdRow['review_checklist'] = array_values((array)($recommendation['review_checklist'] ?? []));
            $created[] = $pricingService->enrichSuggestionRows([$createdRow])[0];
        }

        return $this->success(
            $this->buildPriceSuggestionGenerationRuntimeResult(
                $hotelId,
                $date,
                $created,
                $skipped,
                $pricingService->hotelPricingModelSummary($hotelId, $date)
            ),
            'success'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $created
     * @param array<int, array<string, mixed>> $skipped
     * @param array<string, mixed> $modelSummary
     * @return array<string, mixed>
     */
    private function buildPriceSuggestionGenerationRuntimeResult(
        int $hotelId,
        string $date,
        array $created,
        array $skipped,
        array $modelSummary
    ): array {
        $status = count($created) > 0 ? 'created' : 'blocked';
        $reason = count($created) > 0
            ? 'price_suggestions_pending_review'
            : (string)($skipped[0]['reason'] ?? 'pricing_candidate_signals_missing');
        $requiredInputs = count($created) > 0
            ? []
            : $this->buildPriceSuggestionGenerationRequiredInputs($reason);
        $nextAction = count($created) > 0
            ? '进入待审建议列表完成人工审核；本接口只创建待审建议，不写入携程 OTA 价格。'
            : $this->priceSuggestionGenerationNextAction($reason);

        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $this->priceSuggestionGenerationReasonText($reason),
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'target_hotel_ids' => [$hotelId],
            'target_filter' => [
                'hotel_id' => $hotelId,
                'date' => $date,
                'status' => count($created) > 0 ? PriceSuggestion::STATUS_PENDING : 0,
            ],
            'reviewed_count' => count($created) + count($skipped),
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'list' => $created,
            'skipped' => $skipped,
            'advisory_only' => true,
            'manual_review_required' => true,
            'auto_write_ota' => false,
            'review_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/review',
            'execution_intent_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/execution-intent',
            'ai_review_gate' => [
                'status' => count($created) > 0 ? 'pending_manual_review' : 'blocked_by_preconditions',
                'required_before' => 'operation_execution_intent',
                'manual_review_required' => true,
                'auto_apply_ai_advice' => false,
                'operation_intake_allowed' => false,
                'auto_write_ota' => false,
            ],
            'can_generate_pending_suggestions' => count($created) > 0,
            'required_inputs' => $requiredInputs,
            'model_summary' => $modelSummary,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $skipped
     * @return array<string, mixed>
     */
    private function buildPriceSuggestionGenerationBlockedResult(
        string $reason,
        int $hotelId,
        string $date,
        array $skipped = [],
        string $detail = ''
    ): array {
        return [
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail !== '' ? $detail : $this->priceSuggestionGenerationReasonText($reason),
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'target_hotel_ids' => [$hotelId],
            'target_filter' => [
                'hotel_id' => $hotelId,
                'date' => $date,
                'status' => 0,
            ],
            'reviewed_count' => count($skipped),
            'created_count' => 0,
            'skipped_count' => count($skipped),
            'list' => [],
            'skipped' => $skipped,
            'advisory_only' => true,
            'manual_review_required' => true,
            'auto_write_ota' => false,
            'can_generate_pending_suggestions' => false,
            'required_inputs' => $this->buildPriceSuggestionGenerationRequiredInputs($reason),
            'next_action' => $this->priceSuggestionGenerationNextAction($reason),
        ];
    }

    /**
     * @return array<int, array{code: string, status: string, source: string, required_before: string, next_action: string}>
     */
    private function buildPriceSuggestionGenerationRequiredInputs(string $reason): array
    {
        $inputs = [
            [
                'code' => 'demand_forecast',
                'status' => 'missing_or_blocked',
                'source' => 'demand_forecasts',
                'required_before' => 'POST /api/agent/price-suggestions/generate',
                'next_action' => '补齐目标经营日期的需求预测记录。',
            ],
            [
                'code' => 'competitor_price_samples',
                'status' => 'missing_or_blocked',
                'source' => 'competitor_analysis',
                'required_before' => 'POST /api/agent/price-suggestions/generate',
                'next_action' => '补齐携程目标经营日期前 7 天内的竞对价格样本。',
            ],
            [
                'code' => 'pricing_candidate_signal',
                'status' => 'missing_or_blocked',
                'source' => 'RevenuePricingRecommendationService',
                'required_before' => 'POST /api/agent/price-suggestions/generate',
                'next_action' => '补齐推荐模型需要的主要信号，直到只读预检出现可生成候选。',
            ],
        ];

        if ($reason === 'room_types_empty') {
            array_unshift(
                $inputs,
                [
                    'code' => 'room_types_enabled',
                    'status' => 'missing_or_blocked',
                    'source' => 'room_types',
                    'required_before' => 'POST /api/agent/price-suggestions/generate',
                    'next_action' => '为携程目标酒店配置至少一个启用房型。',
                ],
                [
                    'code' => 'floor_price_or_min_rate_guard',
                    'status' => 'missing_or_blocked',
                    'source' => 'room_types',
                    'required_before' => 'POST /api/agent/price-suggestions/generate',
                    'next_action' => '为启用房型补齐基础价和最低保护价。',
                ]
            );
        }

        if ($reason === 'pending_suggestion_exists') {
            return [[
                'code' => 'manual_review_existing_pending_suggestion',
                'status' => 'pending_review',
                'source' => 'price_suggestions',
                'required_before' => 'POST /api/agent/price-suggestions/generate',
                'next_action' => '先审核或关闭已有待审调价建议，再生成新的待审建议。',
            ]];
        }

        return $inputs;
    }

    private function priceSuggestionGenerationReasonText(string $reason): string
    {
        return match ($reason) {
            'room_types_empty' => '携程目标酒店暂无启用房型，不能生成待审调价建议。',
            'pending_suggestion_exists' => '已存在待审调价建议，不能重复生成。',
            'pricing_candidate_signals_missing' => '调价候选信号不足，当前不会生成待审建议。',
            default => '定价建议生成前置条件未满足。',
        };
    }

    private function priceSuggestionGenerationNextAction(string $reason): string
    {
        return match ($reason) {
            'room_types_empty' => '为携程目标酒店配置启用房型和最低保护价，再补需求预测与竞对样本；缺口未补齐前不生成待审建议。',
            'pending_suggestion_exists' => '进入收益 Agent 的定价建议列表完成已有待审建议审核；Revenue AI 不自动写 OTA。',
            default => '补齐需求预测、竞对价格、历史价格变化和保护价信号，直到只读预检出现可生成候选。',
        };
    }

    public function applyPrice(): Response
    {
        $this->checkAdmin();
        $id = (int)$this->request->param('id', 0);
        $result = $this->applyPriceSuggestionById($id, [
            'platform' => (string)$this->request->param('platform', $this->request->param('channel', '')),
            'room_type_key' => (string)$this->request->param('room_type_key', ''),
            'rate_plan_key' => (string)$this->request->param('rate_plan_key', ''),
            'expected_metric' => (string)$this->request->param('expected_metric', 'orders'),
            'expected_delta' => (float)$this->request->param('expected_delta', 0),
        ]);
        if (($result['ok'] ?? false) !== true) {
            return $this->error(
                (string)($result['message'] ?? 'apply failed'),
                (int)($result['code'] ?? 400),
                is_array($result['data'] ?? null) ? $result['data'] : null
            );
        }
        return $this->success($result['data'] ?? null, 'success');
    }

    public function createPriceSuggestionExecutionIntent(): Response
    {
        $this->checkAdmin();

        $id = (int)$this->request->param('id', 0);
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('price suggestion not found', 404);
        }

        $service = new OperationManagementService();
        $input = $service->buildPriceSuggestionExecutionIntentInput($suggestion->toArray(), [
            'platform' => (string)$this->request->param('platform', $this->request->param('channel', '')),
            'room_type_key' => (string)$this->request->param('room_type_key', ''),
            'rate_plan_key' => (string)$this->request->param('rate_plan_key', ''),
            'expected_metric' => (string)$this->request->param('expected_metric', 'orders'),
            'expected_delta' => (float)$this->request->param('expected_delta', 0),
        ]);
        $hotelIds = [(int)$suggestion->hotel_id];

        try {
            $intent = $service->createExecutionIntent($hotelIds, (int)$suggestion->hotel_id, $input, (int)($this->currentUser->id ?? 0));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage() ?: 'create execution intent failed', $e instanceof \InvalidArgumentException ? 422 : 500);
        }

        AgentLog::record(
            (int)$suggestion->hotel_id,
            AgentLog::AGENT_TYPE_REVENUE,
            'price_execution_intent_create',
            'Create execution intent from price suggestion: ' . $id,
            AgentLog::LEVEL_INFO,
            ['suggestion_id' => $id, 'execution_intent_id' => (int)($intent['id'] ?? 0), 'platform' => $input['platform']],
            (int)($this->currentUser->id ?? 0)
        );

        return $this->success($intent, '执行意图已创建');
    }

    private function applyPriceSuggestionById(int $id, array $executionIntentOverrides = []): array
    {
        return [
            'ok' => false,
            'code' => 409,
            'message' => 'direct price apply is disabled in Revenue AI Phase 1B; create an execution intent and record manual execution evidence instead',
            'data' => [
                'reason' => 'direct_price_apply_disabled',
                'suggestion_id' => $id,
                'advisory_only' => true,
                'manual_review_required' => true,
                'local_price_updated' => false,
                'auto_write_ota' => false,
                'allowed_endpoint' => '/api/revenue-ai/price-suggestions/' . $id . '/execution-intent',
                'forbidden_actions' => ['update_room_type_base_price', 'ota_write'],
                'next_action' => '先创建执行意图，审批后由运营执行页记录人工 OTA 执行证据和次日复盘。',
            ],
        ];
    }

    public function priceSuggestionReview(): Response
    {
        $this->checkAdmin();
        $id = (int)$this->request->param('id', 0);
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('price suggestion not found', 404);
        }

        $anchorDate = $suggestion->applied_time ? date('Y-m-d', strtotime((string)$suggestion->applied_time)) : (string)$suggestion->suggestion_date;
        $beforeStart = date('Y-m-d', strtotime($anchorDate . ' -7 days'));
        $beforeEnd = date('Y-m-d', strtotime($anchorDate . ' -1 day'));
        $afterStart = $anchorDate;
        $afterEnd = date('Y-m-d', strtotime($anchorDate . ' +6 days'));
        $before = $this->aggregateSuggestionEffect((int)$suggestion->hotel_id, $beforeStart, $beforeEnd);
        $after = $this->aggregateSuggestionEffect((int)$suggestion->hotel_id, $afterStart, $afterEnd);
        $delta = [
            'amount' => round($after['amount'] - $before['amount'], 2),
            'quantity' => (int)($after['quantity'] - $before['quantity']),
            'orders' => (int)($after['orders'] - $before['orders']),
            'adr' => round($after['adr'] - $before['adr'], 2),
        ];
        $pricingService = new RevenuePricingRecommendationService();

        return $this->success([
            'suggestion' => $suggestion,
            'anchor_date' => $anchorDate,
            'before' => $before,
            'after' => $after,
            'delta' => $delta,
            'readiness' => $pricingService->buildEffectReviewReadiness($suggestion->toArray(), $before, $after),
            'scope_notice' => '复盘基于 online_daily_data 线上/OTA经营样本，不等同于全酒店经营结论，也不能替代OTA后台执行证据。',
        ]);
    }

    private function aggregateSuggestionEffect(int $hotelId, string $startDate, string $endDate): array
    {
        try {
            $row = Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->whereBetween('data_date', [$startDate, $endDate])
                ->field('COUNT(*) sample_count, COALESCE(SUM(amount),0) amount, COALESCE(SUM(quantity),0) quantity, COALESCE(SUM(book_order_num),0) orders')
                ->find();
        } catch (\Throwable $e) {
            $row = ['sample_count' => 0, 'amount' => 0, 'quantity' => 0, 'orders' => 0];
            $dataStatus = 'read_failed';
            $dataGaps = [['code' => 'online_daily_data_read_failed', 'message' => '复盘样本读取失败']];
        }

        $amount = (float)($row['amount'] ?? 0);
        $quantity = (int)($row['quantity'] ?? 0);
        $sampleCount = (int)($row['sample_count'] ?? 0);
        $dataStatus ??= $sampleCount > 0 ? 'ok' : 'no_sample';
        $dataGaps ??= $sampleCount > 0 ? [] : [['code' => 'online_daily_data_no_sample', 'message' => '复盘周期内没有线上经营样本']];

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'source' => 'online_daily_data',
            'scope' => 'online_ota_operating_sample',
            'data_status' => $dataStatus,
            'sample_count' => $sampleCount,
            'data_gaps' => $dataGaps,
            'amount' => round($amount, 2),
            'quantity' => $quantity,
            'orders' => (int)($row['orders'] ?? 0),
            'adr' => $quantity > 0 ? round($amount / $quantity, 2) : 0,
        ];
    }

    public function cookieWarnings(): Response
    {
        $this->checkAdmin();
        $raw = SystemConfig::getValue('ota_cookie_alerts', '{}');
        $alerts = json_decode((string)$raw, true);
        return $this->success(['alerts' => $this->sanitizeCookieWarningAlerts(is_array($alerts) ? $alerts : [])]);
    }

    private function sanitizeCookieWarningAlerts(array $alerts): array
    {
        $safe = [];
        foreach ($alerts as $alert) {
            if (!is_array($alert)) {
                continue;
            }

            $platform = strtolower(trim((string)($alert['platform'] ?? 'ota')));
            if (!in_array($platform, ['ctrip', 'meituan', 'qunar', 'ota'], true)) {
                $platform = 'ota';
            }
            $hotelId = (int)($alert['hotel_id'] ?? 0);
            $createdAt = trim((string)($alert['created_at'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $createdAt) !== 1) {
                $createdAt = '';
            }

            $safe[] = [
                'platform' => $platform,
                'name' => $this->sanitizeCookieWarningName((string)($alert['name'] ?? '')),
                'hotel_id' => $hotelId > 0 ? $hotelId : null,
                'reason_code' => 'ota_credential_reauthorization_required',
                'message' => 'OTA authorization is unavailable. Reauthenticate the platform account before collection.',
                'created_at' => $createdAt,
                'next_action' => 'Reauthenticate the OTA account and save the refreshed authorization before collection.',
                'reauthorize_entry' => '/online-data?tab=cookies',
            ];
        }

        return $safe;
    }

    private function sanitizeCookieWarningName(string $name): string
    {
        $name = trim($name);
        if ($name === ''
            || preg_match('/(?:cookie|token|authorization|password|secret|spidertoken|mtgsig)\s*[:=]/i', $name) === 1) {
            return 'ota';
        }
        $name = preg_replace('/[^\p{L}\p{N}._\- ]/u', '_', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return $name !== '' ? mb_substr($name, 0, 100) : 'ota';
    }

    public function roomTypes(): Response
    {
        $this->checkAdmin();

        $hotelId = (int)$this->request->param('hotel_id', 0);
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }

        $rows = RoomType::where('hotel_id', $hotelId)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return $this->success([
            'list' => $rows,
            'input_scope' => 'manual_pricing_configuration',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'operator_provided',
            'auto_write_ota' => false,
            'next_action' => count($rows) > 0
                ? '继续补齐需求预测和竞对价格样本后，再生成待审调价建议。'
                : '先配置至少一个启用房型、基础价和最低保护价；未配置前不生成待审调价建议。',
        ]);
    }

    public function saveRoomType(): Response
    {
        $this->checkAdmin();

        try {
            $payload = $this->normalizeRoomTypePayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $id = (int)($payload['id'] ?? 0);
        unset($payload['id']);
        if ($id > 0) {
            $roomType = RoomType::where('id', $id)
                ->where('hotel_id', (int)$payload['hotel_id'])
                ->find();
            if (!$roomType) {
                return $this->error('room_type_not_found_for_hotel', 404);
            }
            $roomType->save($payload);
        } else {
            $roomType = RoomType::create($payload);
        }

        AgentLog::record(
            (int)$payload['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'room_type_pricing_guard_save',
            'Room type pricing guard saved for Ctrip Revenue AI workflow',
            AgentLog::LEVEL_INFO,
            [
                'room_type_id' => (int)$roomType->id,
                'input_scope' => 'manual_pricing_configuration',
                'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
                'auto_write_ota' => false,
            ],
            (int)($this->currentUser->id ?? 0)
        );

        return $this->success([
            'room_type' => $roomType->toArray(),
            'input_scope' => 'manual_pricing_configuration',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'operator_provided',
            'auto_write_ota' => false,
            'next_action' => '继续补齐需求预测和竞对价格样本后，再生成待审调价建议。',
        ], 'room type saved');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeRoomTypePayload(array $data): array
    {
        $hotelId = (int)($data['hotel_id'] ?? 0);
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required');
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('room_type_name is required');
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength > 80) {
            throw new \InvalidArgumentException('room_type_name too long');
        }

        $basePrice = $this->parsePositiveRoomTypeMoney($data['base_price'] ?? null, 'base_price');
        $minPrice = $this->parsePositiveRoomTypeMoney($data['min_price'] ?? null, 'min_price');
        $maxPrice = $this->parsePositiveRoomTypeMoney($data['max_price'] ?? null, 'max_price');
        if ($minPrice > $basePrice) {
            throw new \InvalidArgumentException('min_price cannot be greater than base_price');
        }
        if ($maxPrice < $basePrice) {
            throw new \InvalidArgumentException('max_price cannot be less than base_price');
        }

        $roomCount = max(0, (int)($data['room_count'] ?? 0));
        $sortOrder = max(0, (int)($data['sort_order'] ?? 0));
        $isEnabled = (int)($data['is_enabled'] ?? 1) === 0 ? 0 : 1;

        return [
            'id' => (int)($data['id'] ?? 0),
            'hotel_id' => $hotelId,
            'name' => $name,
            'base_price' => $basePrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'room_count' => $roomCount,
            'sort_order' => $sortOrder,
            'is_enabled' => $isEnabled,
            'facilities' => is_array($data['facilities'] ?? null) ? array_values((array)$data['facilities']) : [],
        ];
    }

    private function parsePositiveRoomTypeMoney(mixed $value, string $field): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '' || $value === null || !is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' must be a positive number');
        }
        $number = round((float)$value, 2);
        if ($number <= 0) {
            throw new \InvalidArgumentException($field . ' must be greater than 0');
        }
        return $number;
    }


    /**
     * 获取收益分析数据（增强版 - 含RevPAR分析）
     */
    public function revenueAnalysis(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        
        // 获取需求预测统计
        $forecastStats = DemandForecast::getAccuracyStats($hotelId, 30);
        $highDemandDates = DemandForecast::getHighDemandDates($hotelId, 80);
        
        // 计算RevPAR趋势（基于预测和历史数据）
        $revparTrend = [];
        $forecasts = DemandForecast::getForecastRange($hotelId, $startDate, $endDate);
        foreach ($forecasts as $forecast) {
            $revparTrend[] = [
                'date' => $forecast->forecast_date,
                'predicted_revpar' => $forecast->predicted_revpar,
                'predicted_occupancy' => $forecast->predicted_occupancy,
                'confidence' => $forecast->confidence_score,
            ];
        }
        
        // 获取定价策略建议
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates);
        
        return $this->success([
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);
    }

    /**
     * 生成定价策略建议
     */
    private function generatePricingStrategies(int $hotelId, array $highDemandDates): array
    {
        $strategies = [];
        
        if (count($highDemandDates) > 0) {
            $strategies[] = [
                'type' => 'high_demand',
                'title' => '高需求日期动态提价',
                'description' => '检测到 ' . count($highDemandDates) . ' 个高需求日期，建议在这些日期实施动态溢价策略',
                'suggested_action' => '在高需求日期将基础房价提高10-20%',
                'expected_impact' => '预计RevPAR提升 8-15%',
            ];
        }
        
        // 检查竞对价格差距
        $recentAnalysis = CompetitorAnalysis::where('hotel_id', $hotelId)
            ->where('analysis_date', date('Y-m-d'))
            ->select();
        
        $higherCount = 0;
        $lowerCount = 0;
        foreach ($recentAnalysis as $item) {
            if ($item->price_difference > 0) {
                $higherCount++;
            } elseif ($item->price_difference < 0) {
                $lowerCount++;
            }
        }
        
        if ($higherCount > $lowerCount) {
            $strategies[] = [
                'type' => 'competitor_price',
                'title' => '竞对价格跟进',
                'description' => '我方价格高于竞对的情况较多，可能导致客源流失',
                'suggested_action' => '针对部分房型适当降价，保持价格竞争力',
                'expected_impact' => '预计提升入住率 3-5%',
            ];
        }
        
        return $strategies;
    }

    /**
     * 获取收益管理Agent综合仪表板
     */
    public function revenueDashboard(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        
        // 今日定价建议
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', date('Y-m-d'))
            ->with('roomType')
            ->select();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = DemandForecast::getAccuracyStats($hotelId, 30);
        $pricingModelSummary = (new RevenuePricingRecommendationService())->hotelPricingModelSummary($hotelId, date('Y-m-d'));
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15);
        
        // 本周RevPAR预测
        $weekForecasts = DemandForecast::getForecastRange(
            $hotelId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+7 days'))
        );
        
        $avgPredictedRevpar = 0;
        if (count($weekForecasts) > 0) {
            $totalRevpar = array_sum(array_column($weekForecasts->toArray(), 'predicted_revpar'));
            $avgPredictedRevpar = round($totalRevpar / count($weekForecasts), 2);
        }
        
        return $this->success([
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'high_demand_count' => count(DemandForecast::getHighDemandDates($hotelId, 80)),
            'pricing_backtest' => $pricingModelSummary['backtest'] ?? [],
            'pricing_model_summary' => $pricingModelSummary,
        ]);
    }

    // ==================== Agent日志 ====================

    /**
     * 获取Agent日志
     */
    public function logs(): Response
    {
        $this->checkAdmin();
        
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $agentType = (int) $this->request->param('agent_type', 0);
        $logLevel = (int) $this->request->param('log_level', 0);
        
        $query = AgentLog::where('hotel_id', $hotelId);
        
        if ($agentType > 0) {
            $query->where('agent_type', $agentType);
        }
        
        if ($logLevel > 0) {
            $query->where('log_level', $logLevel);
        }
        
        $pagination = $this->getPagination();
        $total = $query->count();
        $list = $query->with('user')
            ->order('id', 'desc')
            ->page($pagination['page'], $pagination['page_size'])
            ->select();
        
        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

}
