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
use app\model\User as UserModel;
use app\service\AgentClosureReadinessService;
use app\service\AiDecisionQualityService;
use app\service\CompetitorPriceReadinessService;
use app\service\FeasibilityReportService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\RevenueAiOverviewService;
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
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $revenueForecastRangeCache = [];
    /** @var array<string, array<string, mixed>> */
    private array $revenueForecastAccuracyCache = [];
    /** @var array<string, array<int, string>> */
    private array $revenueHighDemandDatesCache = [];

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

    private function assertRevenueHotelPermission(int $hotelId): void
    {
        if ($hotelId <= 0) {
            abort(422, 'revenue hotel scope is invalid');
        }
        if (!$this->currentUser || !$this->currentUser->hasHotelPermission($hotelId, 'can_use_ai_decision')) {
            abort(403, 'no can_use_ai_decision permission for this hotel');
        }
    }

    private function assertRevenueRoomTypeScope(int $hotelId, int $roomTypeId): void
    {
        $roomTypeExists = RoomType::where('id', $roomTypeId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!$roomTypeExists) {
            abort(422, 'room_type_id does not belong to the selected hotel');
        }
    }

    private function assertOtaDiagnosisHotelPermission(
        int $hotelId,
        string $capability,
        bool $hideUnauthorizedRecord = false
    ): void {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('saved OTA diagnosis hotel scope is invalid', 422);
        }
        if (!$this->currentUser || !$this->currentUser->hasHotelPermission($hotelId, $capability)) {
            throw new \RuntimeException(
                $hideUnauthorizedRecord
                    ? 'saved OTA diagnosis not found'
                    : 'no ' . $capability . ' permission for this hotel',
                $hideUnauthorizedRecord ? 404 : 403
            );
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

    /**
     * Read the latest active diagnosis for one exact OTA scope without generating a new record.
     */
    public function latestOtaDiagnosis(): Response
    {
        $this->checkLogin();
        $hotelId = (int)$this->request->get('hotel_id', 0);
        $platform = strtolower(trim((string)$this->request->get('platform', '')));
        $startDate = trim((string)$this->request->get('start_date', ''));
        $endDate = trim((string)$this->request->get('end_date', ''));

        if ($hotelId <= 0) {
            return $this->error('hotel_id must be a positive system hotel id', 422);
        }
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate) || strtotime($startDate) > strtotime($endDate)) {
            return $this->error('start_date 和 end_date 必须是有效的 YYYY-MM-DD 范围', 422);
        }

        try {
            $this->assertOtaDiagnosisHotelPermission($hotelId, 'operation.view');
            $targetRange = $this->normalizeOtaDiagnosisScopeDateRange([
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            $records = AgentLog::where('hotel_id', $hotelId)
                ->where('agent_type', AgentLog::AGENT_TYPE_REVENUE)
                ->where('action', 'ota_diagnosis')
                ->order('id', 'desc')
                ->limit(100)
                ->select();

            foreach ($records as $record) {
                $context = $record->context_data;
                if (is_string($context)) {
                    $decoded = json_decode($context, true);
                    $context = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($context)
                    || strtolower((string)($context['platform'] ?? '')) !== $platform
                    || (string)($context['record_status'] ?? '') !== 'active'
                    || $this->normalizeOtaDiagnosisScopeDateRange((array)($context['requested_date_range'] ?? [])) !== $targetRange
                ) {
                    continue;
                }
                $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
                if ($snapshot === [] || (string)($snapshot['record_status'] ?? 'active') !== 'active') {
                    continue;
                }
                $snapshot['saved_record'] = array_replace([
                    'id' => (int)$record->id,
                    'saved' => true,
                    'readback_verified' => true,
                    'storage' => 'agent_logs.context_data',
                ], is_array($snapshot['saved_record'] ?? null) ? $snapshot['saved_record'] : []);

                return $this->success([
                    'status' => 'ready',
                    'diagnosis' => $snapshot,
                    'scope' => [
                        'hotel_id' => $hotelId,
                        'platform' => $platform,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                ], '已读取保存的 OTA 诊断');
            }

            return $this->success([
                'status' => 'missing',
                'diagnosis' => null,
                'scope' => [
                    'hotel_id' => $hotelId,
                    'platform' => $platform,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ], '该门店目标日尚无已保存 OTA 诊断');
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? (int)$e->getCode() : 500;
            return $this->error('读取 OTA 诊断失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), $status);
        }
    }

    public function otaDiagnosis(): Response
    {
        $this->checkLogin();

        $hotelIdRaw = trim((string) $this->request->param('hotel_id', ''));
        $hotelId = (int) $hotelIdRaw;
        $platformHotelIdRaw = trim((string) $this->request->param('platform_hotel_id', ''));
        $configId = trim((string) $this->request->param('config_id', ''));
        $hotelName = trim((string) $this->request->param('hotel_name', ''));
        $platform = strtolower(trim((string) $this->request->param('platform', 'ctrip')));
        $startDate = trim((string) $this->request->param('start_date', ''));
        $endDate = trim((string) $this->request->param('end_date', ''));
        $analysisType = strtolower(trim((string) $this->request->param('analysis_type', 'traffic')));
        $analysisMode = strtolower(trim((string) $this->request->param('analysis_mode', 'auto')));
        $modelKey = trim((string) $this->request->param('model_key', 'deepseek_v4_default'));
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }

        if (!in_array($analysisMode, ['auto', 'rules_only'], true)) {
            return $this->error('analysis_mode 仅支持 auto、rules_only', 422);
        }
        $analysisRuntime = $this->resolveOtaDiagnosisAnalysisRuntime(
            $analysisMode,
            $this->isAllowedLlmModelKey($modelKey)
        );
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
            if ($hotelId <= 0) {
                return $this->error('hotel_id must be a positive system hotel id', 422);
            }
            $this->assertOtaDiagnosisHotelPermission($hotelId, 'operation.view');

            $dataSet = $this->queryOtaDiagnosisData($hotelId, $hotelIdRaw, $platformHotelIdRaw, $platform, $startDate, $endDate, $analysisType);
            if (!$this->hasOtaDiagnosisData($dataSet)) {
                $result = $this->buildOtaDiagnosisNoDataResult(
                    $dataSet,
                    $hotelIdRaw,
                    $hotelName,
                    $platform,
                    $startDate,
                    $endDate
                );
                $result['analysis_runtime'] = array_merge($analysisRuntime, [
                    'mode' => 'not_run_no_data',
                    'model_called' => false,
                ]);
                $result = $this->persistOtaDiagnosisResult($result, $hotelId, $platform);

                return $this->success($result, '暂无 OTA 数据');
            }

            $effectiveStartDate = (string) ($dataSet['effective_start_date'] ?? $startDate);
            $effectiveEndDate = (string) ($dataSet['effective_end_date'] ?? $endDate);
            $usedLatestAvailableData = !empty($dataSet['used_latest_available_data']);
            $effectiveHotelName = $hotelName !== '' ? $hotelName : trim((string)($dataSet['hotel']['name'] ?? ''));
            $result = $this->buildOtaDiagnosisResult($dataSet, $hotelId, $hotelIdRaw, $effectiveHotelName, $platform, $effectiveStartDate, $effectiveEndDate, $analysisType);
            $ruleDiagnosis = is_array($result['diagnosis'] ?? null) ? $result['diagnosis'] : [];
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
            if (($analysisRuntime['use_rules_only'] ?? false) === true) {
                $llmResult = [
                    'ok' => true,
                    'provider' => 'local',
                    'model_key' => 'deterministic_rules',
                    'model' => 'ota_diagnosis_rule_engine',
                    'data' => [
                        'governance' => [
                            'status' => (string)($analysisRuntime['fallback_reason'] ?? '') === 'model_not_available'
                                ? 'skipped_model_unavailable'
                                : 'skipped_rules_only',
                            'prompt_version' => 'ota_diagnosis.rules_only.v1',
                        ],
                    ],
                ];
                $result['diagnosis']['model_note'] = (string)($analysisRuntime['fallback_reason'] ?? '') === 'model_not_available'
                    ? '模型配置当前不可用，已自动降级为系统规则诊断；结论仅依据真实入库 OTA 数据和确定性规则。'
                    : '当前使用系统规则诊断；未调用外部模型，结论仅依据真实入库 OTA 数据和确定性规则。';
                $result['diagnosis'] = $this->applyOtaDiagnosisRuleEvidenceGuard($result['diagnosis'], $ruleDiagnosis);
                if (!$usedLatestAvailableData) {
                    $result['source_policy'] = 'database_only_deterministic_rules';
                }
            } else {
                $llmResult = $this->callLlm($this->buildOtaDiagnosisPrompt($result), $modelKey, $this->buildAiGovernanceMeta('ota_diagnosis', $result, [
                    'hotel_id' => $hotelId,
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]), $modelOptions);
                $analysisRuntime['model_called'] = true;
                if (($llmResult['ok'] ?? false) === true) {
                    $analysisRuntime['mode'] = 'llm_augmented_rules';
                    $result['diagnosis'] = array_merge($result['diagnosis'], $this->parseOtaDiagnosisResult((string) $llmResult['content']));
                    $result['diagnosis'] = $this->applyOtaDiagnosisRuleEvidenceGuard($result['diagnosis'], $ruleDiagnosis);
                } else {
                    $analysisRuntime['mode'] = 'deterministic_rules_fallback';
                    $analysisRuntime['fallback_reason'] = 'model_call_failed';
                    $result['missing_sections'][] = 'AI模型诊断';
                    $result['diagnosis']['model_note'] = '模型诊断暂不可用，当前结论仅使用系统规则和真实入库数据。';
                    $result['diagnosis'] = $this->applyOtaDiagnosisRuleEvidenceGuard($result['diagnosis'], $ruleDiagnosis);
                }
            }
            $result['analysis_runtime'] = $analysisRuntime;
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
            $result = $this->finalizeOtaDiagnosisDecision($result);
            $result = $this->persistOtaDiagnosisResult($result, $hotelId, $platform);

            return $this->success($result, 'success');
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? (int)$e->getCode() : 500;
            return $this->error('OTA 诊断失败: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), $status);
        }
    }

    public function createOtaDiagnosisExecutionIntent(int $id, int $actionIndex): Response
    {
        $this->checkLogin();
        if ($id <= 0 || $actionIndex < 0) {
            return $this->error('invalid OTA diagnosis action identity', 422);
        }

        try {
            $scheduleInput = $this->request->post();
            $result = Db::transaction(function () use ($id, $actionIndex, $scheduleInput): array {
                $log = Db::name('agent_logs')
                    ->where('id', $id)
                    ->where('action', 'ota_diagnosis')
                    ->where('agent_type', AgentLog::AGENT_TYPE_REVENUE)
                    ->lock(true)
                    ->find();
                if (!is_array($log)) {
                    throw new \RuntimeException('saved OTA diagnosis not found', 404);
                }

                $hotelId = (int)($log['hotel_id'] ?? 0);
                $this->assertOtaDiagnosisHotelPermission($hotelId, 'operation.view', true);
                $this->assertOtaDiagnosisHotelPermission($hotelId, 'operation.execute');

                $rawContext = is_string($log['context_data'] ?? null)
                    ? (string)$log['context_data']
                    : json_encode($log['context_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $context = json_decode((string)$rawContext, true);
                $context = is_array($context) ? $context : [];
                if (($context['record_status'] ?? '') === 'superseded') {
                    throw new \RuntimeException('saved OTA diagnosis has been superseded', 409);
                }
                $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
                if (($snapshot['record_status'] ?? '') === 'superseded') {
                    throw new \RuntimeException('saved OTA diagnosis has been superseded', 409);
                }
                if (($snapshot['decision_status'] ?? $snapshot['decision_closure']['status'] ?? '') !== 'action_required') {
                    throw new \RuntimeException('saved OTA diagnosis is not action_required', 409);
                }

                $actionItems = array_values(array_filter((array)($snapshot['action_items'] ?? []), 'is_array'));
                $action = $actionItems[$actionIndex] ?? null;
                if (!is_array($action)
                    || ($action['execution_ready'] ?? false) !== true
                    || ($action['can_request_execution_intent'] ?? false) !== true
                    || !$this->isOtaDiagnosisActionDecisionQualityExecutionReady($action)
                ) {
                    throw new \RuntimeException('saved OTA diagnosis action lacks executable evidence', 409);
                }

                if ($hotelId <= 0
                    || ((int)($snapshot['hotel']['id'] ?? $hotelId) > 0 && (int)$snapshot['hotel']['id'] !== $hotelId)
                ) {
                    throw new \RuntimeException('saved OTA diagnosis hotel scope mismatch', 409);
                }

                $intentInput = $this->buildOtaDiagnosisExecutionIntentInput($snapshot, $action, $id, $hotelId, $scheduleInput);
                $this->assertOtaDiagnosisExecutionAssigneeScope(
                    (int)($intentInput['target_value']['assignee_id'] ?? 0),
                    $hotelId
                );
                $idempotencyKey = $this->otaDiagnosisActionIdempotencyKey($id, $actionIndex, $action, $intentInput);
                $existing = $this->findOtaDiagnosisActionIntent(
                    $id,
                    $hotelId,
                    $actionIndex,
                    $idempotencyKey,
                    $action,
                    (string)$intentInput['action_type'],
                    (array)($intentInput['target_value']['workflow_schedule'] ?? [])
                );
                $retryableTerminal = is_array($existing)
                    && $this->isRetryableOtaDiagnosisIntentTerminal((string)($existing['status'] ?? ''));
                $retryAttempt = is_array($existing)
                    ? max(1, $this->otaDiagnosisIntentAttempt($existing)) + ($retryableTerminal ? 1 : 0)
                    : 1;
                $intentInput['evidence']['action_index'] = $actionIndex;
                $intentInput['evidence']['action_idempotency_key'] = $idempotencyKey;
                $intentInput['evidence']['intent_attempt'] = $retryAttempt;
                $intentInput['evidence']['retry_of_intent_id'] = $retryableTerminal ? (int)($existing['id'] ?? 0) : 0;
                $atomicIdempotencyKey = $idempotencyKey . ':attempt:' . $retryAttempt;

                $reused = is_array($existing) && !$retryableTerminal;
                $intent = $reused
                    ? $this->otaDiagnosisIntentSummary($existing, $hotelId, $snapshot, $intentInput)
                    : (new OperationManagementService())->createExecutionIntent(
                        [$hotelId],
                        $hotelId,
                        $intentInput,
                        (int)($this->currentUser->id ?? 0),
                        false,
                        $atomicIdempotencyKey,
                        true
                    );
                $reused = $reused || ($intent['idempotent_replay'] ?? false) === true;
                $persistedSchedule = $this->otaDiagnosisIntentWorkflowSchedule($intent);
                if ($persistedSchedule === [] && !$reused) {
                    $persistedSchedule = (array)($intentInput['target_value']['workflow_schedule'] ?? []);
                }
                if ($persistedSchedule === []) {
                    throw new \RuntimeException('OTA diagnosis execution intent schedule readback failed');
                }
                if (!$reused
                    && ((int)($intent['id'] ?? 0) <= 0
                        || (string)($intent['status'] ?? '') !== 'pending_approval'
                        || (string)($intent['blocked_reason'] ?? '') !== '')
                ) {
                    throw new \RuntimeException('OTA diagnosis execution intent postcondition failed');
                }

                $actionItems[$actionIndex]['execution_intent_id'] = (int)($intent['id'] ?? 0);
                $actionItems[$actionIndex]['execution_status'] = (string)($intent['status'] ?? '');
                $actionItems[$actionIndex]['execution_blocked_reason'] = (string)($intent['blocked_reason'] ?? '');
                $actionItems[$actionIndex]['execution_idempotency_key'] = $idempotencyKey;
                $actionItems[$actionIndex]['execution_attempt'] = $retryAttempt;
                $actionItems[$actionIndex]['execution_retry_of_intent_id'] = $retryableTerminal ? (int)($existing['id'] ?? 0) : 0;
                $actionItems[$actionIndex]['execution_schedule'] = $persistedSchedule;
                $snapshot['action_items'] = $actionItems;
                $context['diagnosis_result'] = $snapshot;
                $newContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($newContext)) {
                    throw new \RuntimeException('saved OTA diagnosis intent writeback encoding failed');
                }
                if ($newContext !== $rawContext) {
                    $affected = (int)Db::name('agent_logs')
                        ->where('id', $id)
                        ->where('context_data', $rawContext)
                        ->update(['context_data' => $newContext]);
                    if ($affected !== 1) {
                        throw new \RuntimeException('saved OTA diagnosis intent writeback compare-and-swap failed');
                    }
                }

                return [
                    'execution_intent' => $intent,
                    'saved_diagnosis_id' => $id,
                    'action_index' => $actionIndex,
                    'reused_existing_intent' => $reused,
                    'retry_created' => $retryableTerminal,
                    'idempotency_key' => $idempotencyKey,
                    'intent_attempt' => $retryAttempt,
                    'execution_schedule' => $persistedSchedule,
                    'hotel_id' => $hotelId,
                ];
            });

            $hotelId = (int)$result['hotel_id'];
            unset($result['hotel_id']);
            $result['next_page'] = 'ops-track';
            $result['next_entry'] = '/api/operation/execution-flow?hotel_id=' . $hotelId;
            $result['source_policy'] = 'saved_ota_diagnosis_evidence_only_manual_execution';
            return $this->success(
                $result,
                ($result['reused_existing_intent'] ?? false)
                    ? 'matching execution intent already exists'
                    : 'execution intent created and awaits manual approval'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
            return $this->error('OTA diagnosis execution-intent transfer failed: ' . $this->sanitizeLlmErrorMessage($e->getMessage()), $status);
        }
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $input */
    private function otaDiagnosisActionIdempotencyKey(int $recordId, int $actionIndex, array $action, array $input): string
    {
        $identity = [
            'record_id' => $recordId,
            'action_index' => $actionIndex,
            'action_item_id' => trim((string)($action['id'] ?? '')),
            'action_type' => trim((string)($input['action_type'] ?? '')),
            'platform' => trim((string)($input['platform'] ?? '')),
            'workflow_schedule' => (array)($input['target_value']['workflow_schedule'] ?? []),
        ];
        return 'ota_diagnosis_action_' . substr(hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        ), 0, 32);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>|null
     */
    private function findOtaDiagnosisActionIntent(
        int $recordId,
        int $hotelId,
        int $actionIndex,
        string $idempotencyKey,
        array $action,
        string $actionType,
        array $workflowSchedule
    ): ?array {
        if (!$this->tableExists('operation_execution_intents')) {
            return null;
        }
        $linkedId = (int)($action['execution_intent_id'] ?? 0);
        $query = Db::name('operation_execution_intents')
            ->where('source_module', 'ota_diagnosis_saved')
            ->where('source_record_id', $recordId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at');
        if ($linkedId > 0) {
            $linked = (clone $query)->where('id', $linkedId)->find();
            if (is_array($linked) && $this->otaDiagnosisIntentMatchesIdentity(
                $linked,
                $idempotencyKey,
                $actionIndex,
                $action,
                $workflowSchedule
            )) {
                return $linked;
            }
        }

        foreach ($query->where('action_type', $actionType)->order('id', 'desc')->select()->toArray() as $row) {
            if (is_array($row) && $this->otaDiagnosisIntentMatchesIdentity(
                $row,
                $idempotencyKey,
                $actionIndex,
                $action,
                $workflowSchedule
            )) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $intent @param array<string, mixed> $action */
    private function otaDiagnosisIntentMatchesIdentity(
        array $intent,
        string $idempotencyKey,
        int $actionIndex,
        array $action,
        array $workflowSchedule
    ): bool {
        $evidence = json_decode((string)($intent['evidence_json'] ?? ''), true);
        $evidence = is_array($evidence) ? $evidence : [];
        $storedKey = trim((string)($evidence['action_idempotency_key'] ?? ''));
        if ($storedKey !== '') {
            return hash_equals($idempotencyKey, $storedKey);
        }

        $actionItemId = trim((string)($action['id'] ?? ''));
        $storedActionItemId = trim((string)($evidence['action_item_id'] ?? ''));
        $legacyActionMatches = (int)($evidence['action_index'] ?? -1) === $actionIndex
            || ($actionItemId !== '' && $storedActionItemId !== '' && hash_equals($actionItemId, $storedActionItemId));

        return $legacyActionMatches
            && $this->otaDiagnosisIntentWorkflowSchedule($intent) === $workflowSchedule;
    }

    /** @param array<string, mixed> $intent @return array<string, mixed> */
    private function otaDiagnosisIntentWorkflowSchedule(array $intent): array
    {
        $targetValue = $intent['target_value'] ?? null;
        if (!is_array($targetValue)) {
            $targetValue = json_decode((string)($intent['target_value_json'] ?? ''), true);
        }
        $targetValue = is_array($targetValue) ? $targetValue : [];
        $schedule = is_array($targetValue['workflow_schedule'] ?? null)
            ? $targetValue['workflow_schedule']
            : [];
        if ($schedule === [] && (int)($targetValue['assignee_id'] ?? 0) > 0) {
            $schedule = [
                'assignee_id' => (int)$targetValue['assignee_id'],
                'due_at' => trim((string)($targetValue['due_at'] ?? '')),
                'review_at' => trim((string)($targetValue['review_at'] ?? '')),
                'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
            ];
        }

        return $schedule;
    }

    private function isRetryableOtaDiagnosisIntentTerminal(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['failed', 'failure', 'rejected', 'cancelled', 'canceled'], true);
    }

    /** @param array<string, mixed> $intent */
    private function otaDiagnosisIntentAttempt(array $intent): int
    {
        $evidence = json_decode((string)($intent['evidence_json'] ?? ''), true);
        return max(1, (int)(is_array($evidence) ? ($evidence['intent_attempt'] ?? 1) : 1));
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $snapshot @param array<string, mixed> $input */
    private function otaDiagnosisIntentSummary(array $existing, int $hotelId, array $snapshot, array $input): array
    {
        $targetValue = json_decode((string)($existing['target_value_json'] ?? ''), true);
        return [
            'id' => (int)($existing['id'] ?? 0),
            'status' => (string)($existing['status'] ?? ''),
            'blocked_reason' => (string)($existing['blocked_reason'] ?? ''),
            'hotel_id' => (int)($existing['hotel_id'] ?? $hotelId),
            'platform' => (string)($existing['platform'] ?? $snapshot['platform'] ?? ''),
            'source_module' => (string)($existing['source_module'] ?? $input['source_module']),
            'source_record_id' => (int)($existing['source_record_id'] ?? 0),
            'target_value' => is_array($targetValue) ? $targetValue : [],
            'workflow_schedule' => $this->otaDiagnosisIntentWorkflowSchedule($existing),
        ];
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
            $unitQuery = Db::name('knowledge_units')
                ->field(implode(',', $unitFieldNames))
                ->where('status', 'done');
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
            $unitRows = $unitQuery->order('unit_id', 'desc')->limit(6)->select()->toArray();
            $unitIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $unitRows)));
            $chunksByUnit = [];

            if ($unitIds) {
                $chunkRows = Db::name('knowledge_chunks')
                    ->field('chunk_id,unit_id,type,content')
                    ->whereIn('unit_id', $unitIds)
                    ->order('chunk_id', 'desc')
                    ->limit(240)
                    ->select()
                    ->toArray();
                foreach ($chunkRows as &$chunkRow) {
                    $searchText = mb_strtolower((string)($chunkRow['type'] ?? '') . ' ' . $this->sanitizeOtaKnowledgeText($chunkRow['content'] ?? '', 6000));
                    $score = 0;
                    foreach ($keywords as $keywordIndex => $keyword) {
                        $keyword = mb_strtolower(trim((string)$keyword));
                        if ($keyword === '' || mb_stripos($searchText, $keyword) === false) {
                            continue;
                        }
                        $score += $keywordIndex < 3 ? 4 : 1;
                    }
                    $chunkRow['_relevance_score'] = $score;
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

    private function finalizeOtaDiagnosisDecision(array $result): array
    {
        $result['decision_closure'] = $this->buildAiDecisionClosure($result);
        $result['decision_status'] = (string)($result['decision_closure']['status'] ?? 'blocked_by_data');
        $result['blocking_data_gaps'] = $result['decision_closure']['data_evidence_input']['blocking_data_gaps'] ?? [];
        $result['optional_data_gaps'] = $result['decision_closure']['data_evidence_input']['optional_data_gaps'] ?? [];

        if ($result['decision_status'] === 'no_action') {
            $platformLabel = (string)($result['platform'] ?? '') === 'meituan' ? '美团' : 'OTA';
            $summary = sprintf(
                '本次%s渠道已覆盖的入库核心字段通过校验，未发现达到当前诊断阈值的异常；该结论仅限本次渠道数据，“无需新增行动”，继续观察下一数据日。',
                $platformLabel
            );
            $result['diagnosis']['summary'] = $summary;
            $result['diagnosis']['abnormal_metrics'] = [];
            $result['diagnosis']['actions'] = [];
            $result['core_conclusion'] = $summary;
            $result['main_problems'] = [];
            $result['recommended_actions'] = [];
            $result['priority'] = 'none';
            $result['no_action_reason'] = [
                'codes' => ['core_metrics_available', 'no_threshold_breach'],
                'scope' => 'ota_channel',
                'statement' => '无需行动只表示本次已覆盖的 OTA 渠道指标未触发行动阈值，不代表全酒店经营无问题。',
            ];
            $result['diagnosis_sections'] = $this->buildOtaDiagnosisSections(
                $result['diagnosis'],
                $result['missing_sections'] ?? []
            );
            $result['decision_closure'] = $this->buildAiDecisionClosure($result);
        }
        if ($result['decision_status'] === 'blocked_by_data') {
            $result['priority'] = 'none';
        }

        $result['execution_policy'] = $result['decision_status'] === 'action_required'
            ? 'saved_evidence_action_requires_manual_confirmation'
            : 'do_not_create_execution_intent';
        $result['evidence_report'] = $this->buildOtaEvidenceReport($result);

        return $result;
    }

    private function persistOtaDiagnosisResult(array $result, int $hotelId, string $platform): array
    {
        $resolvedHotelId = $hotelId > 0 ? $hotelId : (int)($result['hotel']['id'] ?? 0);
        if ($resolvedHotelId <= 0) {
            $result['saved_record'] = [
                'saved' => false,
                'reason' => 'system_hotel_id_missing',
            ];
            return $result;
        }

        $decisionStatus = (string)($result['decision_status'] ?? $result['decision_closure']['status'] ?? 'blocked_by_data');
        $statusLabels = [
            'action_required' => '需要人工确认行动',
            'no_action' => '无需新增行动',
            'blocked_by_data' => '数据不足，暂不能行动',
        ];
        $dateRange = is_array($result['date_range'] ?? null) ? $result['date_range'] : [];
        $requestedDateRange = $this->normalizeOtaDiagnosisScopeDateRange(
            is_array($result['requested_date_range'] ?? null) ? $result['requested_date_range'] : $dateRange
        );
        $platformLabel = strtolower($platform) === 'meituan' ? '美团' : strtoupper($platform);
        $message = sprintf(
            '%s渠道诊断已保存：%s（%s 至 %s）',
            $platformLabel,
            $statusLabels[$decisionStatus] ?? $decisionStatus,
            (string)($dateRange['start_date'] ?? ''),
            (string)($dateRange['end_date'] ?? '')
        );
        $level = $decisionStatus === 'blocked_by_data' ? AgentLog::LEVEL_WARNING : AgentLog::LEVEL_INFO;
        Db::transaction(function () use (
            &$result,
            $resolvedHotelId,
            $platform,
            $message,
            $level,
            $dateRange,
            $requestedDateRange,
            $decisionStatus
        ): void {
            $log = AgentLog::record(
                $resolvedHotelId,
                AgentLog::AGENT_TYPE_REVENUE,
                'ota_diagnosis',
                $message,
                $level,
                [
                    'schema_version' => 1,
                    'record_type' => 'ota_diagnosis',
                    'platform' => strtolower($platform),
                    'date_range' => $dateRange,
                    'decision_status' => $decisionStatus,
                ],
                (int)($this->currentUser->id ?? 0)
            );

            $logId = (int)$log->id;
            $result['record_status'] = 'active';
            $result['saved_record'] = [
                'saved' => false,
                'readback_verified' => false,
                'id' => $logId,
                'saved_at' => (string)($log->create_time ?? date('Y-m-d H:i:s')),
                'storage' => 'agent_logs.context_data',
                'action' => 'ota_diagnosis',
            ];
            $context = [
                'schema_version' => 1,
                'record_type' => 'ota_diagnosis',
                'record_status' => 'active',
                'platform' => strtolower($platform),
                'date_range' => $dateRange,
                'requested_date_range' => $requestedDateRange,
                'decision_status' => $decisionStatus,
                'diagnosis_result' => $this->buildOtaDiagnosisSnapshot($result),
            ];
            $log->context_data = $context;
            $log->save();

            $stored = AgentLog::where('id', $logId)
                ->where('hotel_id', $resolvedHotelId)
                ->where('action', 'ota_diagnosis')
                ->find();
            $storedContext = $stored?->context_data ?? [];
            if (is_string($storedContext)) {
                $decoded = json_decode($storedContext, true);
                $storedContext = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($storedContext)
                || (int)($storedContext['schema_version'] ?? 0) !== 1
                || (string)($storedContext['record_status'] ?? '') !== 'active'
                || strtolower((string)($storedContext['platform'] ?? '')) !== strtolower($platform)
                || $this->normalizeOtaDiagnosisScopeDateRange((array)($storedContext['requested_date_range'] ?? [])) !== $requestedDateRange
                || !is_array($storedContext['diagnosis_result']['evidence_sources'] ?? null)
            ) {
                throw new \RuntimeException('OTA diagnosis save readback verification failed');
            }

            $supersededCount = $this->supersedePriorOtaDiagnosisRecords(
                $resolvedHotelId,
                strtolower($platform),
                $requestedDateRange,
                $logId
            );
            $result['saved_record']['saved'] = true;
            $result['saved_record']['readback_verified'] = true;
            $result['saved_record']['readback_verified_at'] = date('Y-m-d H:i:s');
            $result['saved_record']['superseded_prior_count'] = $supersededCount;
            $context['diagnosis_result'] = $this->buildOtaDiagnosisSnapshot($result);
            $log->context_data = $context;
            $log->save();

            $verified = AgentLog::where('id', $logId)->where('hotel_id', $resolvedHotelId)->find();
            $verifiedContext = $verified?->context_data ?? [];
            if (is_string($verifiedContext)) {
                $decoded = json_decode($verifiedContext, true);
                $verifiedContext = is_array($decoded) ? $decoded : [];
            }
            if (($verifiedContext['diagnosis_result']['saved_record']['saved'] ?? false) !== true
                || ($verifiedContext['diagnosis_result']['saved_record']['readback_verified'] ?? false) !== true
            ) {
                throw new \RuntimeException('OTA diagnosis final readback verification failed');
            }
        });

        return $result;
    }

    private function supersedePriorOtaDiagnosisRecords(int $hotelId, string $platform, array $dateRange, int $newLogId): int
    {
        $targetRange = $this->normalizeOtaDiagnosisScopeDateRange($dateRange);
        if ($hotelId <= 0 || $newLogId <= 0 || $targetRange['start_date'] === '' || $targetRange['end_date'] === '') {
            return 0;
        }

        $superseded = 0;
        $records = AgentLog::where('hotel_id', $hotelId)
            ->where('agent_type', AgentLog::AGENT_TYPE_REVENUE)
            ->where('action', 'ota_diagnosis')
            ->where('id', '<', $newLogId)
            ->order('id', 'desc')
            ->lock(true)
            ->select();
        $operationService = new OperationManagementService();
        foreach ($records as $record) {
            $context = $record->context_data;
            if (is_string($context)) {
                $decoded = json_decode($context, true);
                $context = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($context) || ($context['record_status'] ?? '') === 'superseded') {
                continue;
            }
            $snapshot = is_array($context['diagnosis_result'] ?? null) ? $context['diagnosis_result'] : [];
            $recordRange = $this->normalizeOtaDiagnosisScopeDateRange(
                is_array($snapshot['requested_date_range'] ?? null)
                    ? $snapshot['requested_date_range']
                    : (is_array($context['requested_date_range'] ?? null)
                        ? $context['requested_date_range']
                        : (array)($context['date_range'] ?? []))
            );
            $recordPlatform = strtolower((string)($context['platform'] ?? $snapshot['platform'] ?? ''));
            if ($recordPlatform !== $platform
                || $recordRange !== $targetRange
            ) {
                continue;
            }
            if ($operationService->hasOtaDiagnosisExecutionReference($hotelId, (int)$record->id)) {
                continue;
            }

            $supersededAt = date('Y-m-d H:i:s');
            $context['record_status'] = 'superseded';
            $context['superseded_by_log_id'] = $newLogId;
            $context['superseded_at'] = $supersededAt;
            $context['superseded_reason'] = 'newer_same_scope_diagnosis_saved';
            if (is_array($context['diagnosis_result'] ?? null)) {
                $context['diagnosis_result']['record_status'] = 'superseded';
                $context['diagnosis_result']['superseded_by'] = [
                    'log_id' => $newLogId,
                    'superseded_at' => $supersededAt,
                    'reason' => 'newer_same_scope_diagnosis_saved',
                ];
                if (is_array($context['diagnosis_result']['saved_record'] ?? null)) {
                    $context['diagnosis_result']['saved_record']['status'] = 'superseded';
                    $context['diagnosis_result']['saved_record']['superseded_by_log_id'] = $newLogId;
                }
            }
            $record->context_data = $context;
            $record->save();
            $superseded++;
        }

        return $superseded;
    }

    private function normalizeOtaDiagnosisScopeDateRange(array $dateRange): array
    {
        $startDate = trim((string)($dateRange['start_date'] ?? $dateRange['start'] ?? ''));
        $endDate = trim((string)($dateRange['end_date'] ?? $dateRange['end'] ?? $startDate));

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    private function buildOtaDiagnosisSnapshot(array $result): array
    {
        $allowed = [
            'hotel', 'platform', 'date_range', 'requested_date_range', 'data_summary', 'metrics',
            'derived_metric_lineage', 'data_gaps', 'blocking_data_gaps', 'optional_data_gaps',
            'diagnosis', 'diagnosis_sections', 'core_conclusion', 'main_problems', 'possible_reasons',
            'recommended_actions', 'priority', 'source_policy', 'source_summary', 'evidence_sources',
            'action_items', 'ai_governance', 'decision_status', 'decision_closure', 'execution_policy',
            'evidence_report', 'no_action_reason', 'saved_record', 'record_status', 'superseded_by',
            'validation_status', 'invalid_reason',
        ];
        $snapshot = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $result)) {
                $snapshot[$field] = $result[$field];
            }
        }
        if (is_array($snapshot['diagnosis'] ?? null)) {
            unset($snapshot['diagnosis']['raw_text']);
        }

        return $snapshot;
    }

    private function buildOtaDiagnosisExecutionIntentInput(
        array $snapshot,
        array $action,
        int $recordId,
        int $hotelId,
        array $scheduleInput = []
    ): array
    {
        $platform = strtolower(trim((string)($snapshot['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar'], true)) {
            throw new \InvalidArgumentException('saved OTA diagnosis platform is invalid');
        }
        $dateRange = is_array($snapshot['date_range'] ?? null) ? $snapshot['date_range'] : [];
        $dateStart = trim((string)($dateRange['start_date'] ?? ''));
        $dateEnd = trim((string)($dateRange['end_date'] ?? $dateStart));
        if (!$this->isDateString($dateStart) || !$this->isDateString($dateEnd)) {
            throw new \InvalidArgumentException('saved OTA diagnosis date range is invalid');
        }
        if (($snapshot['decision_status'] ?? $snapshot['decision_closure']['status'] ?? '') !== 'action_required') {
            throw new \InvalidArgumentException('saved OTA diagnosis is not action_required');
        }
        if (($action['execution_ready'] ?? false) !== true
            || ($action['can_request_execution_intent'] ?? false) !== true
            || !$this->isOtaDiagnosisActionDecisionQualityExecutionReady($action)
        ) {
            throw new \InvalidArgumentException('saved OTA diagnosis action is not execution ready');
        }

        $actionText = trim((string)($action['action'] ?? ''));
        if ($actionText === '') {
            throw new \InvalidArgumentException('saved OTA diagnosis action text is missing');
        }
        [$actionType, $targetMetric] = $this->classifyOtaDiagnosisExecutionAction($actionText);
        $evidenceRefs = array_values(array_unique(array_filter(array_map('strval', (array)($action['evidence_refs'] ?? [])))));
        if (empty($evidenceRefs)) {
            throw new \InvalidArgumentException('saved OTA diagnosis action evidence refs are missing');
        }

        $referencedEvidence = [];
        foreach ((array)($snapshot['evidence_sources'] ?? []) as $source) {
            if (!is_array($source) || !in_array((string)($source['ref'] ?? ''), $evidenceRefs, true)) {
                continue;
            }
            $referencedEvidence[] = $source;
        }
        $currentValue = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'adr', 'list_exposure', 'detail_visitors', 'flow_rate',
            'order_visitors', 'submit_users', 'detail_rate', 'order_rate', 'submit_rate',
            'advertising_spend', 'advertising_order_amount', 'advertising_roas', 'avg_psi_score', 'avg_service_score',
        ] as $metric) {
            $value = $snapshot['metrics'][$metric] ?? null;
            if ($value !== null && $value !== '') {
                $currentValue[$metric] = $value;
            }
        }
        $priority = strtolower(trim((string)($snapshot['priority'] ?? 'medium')));
        $workflowSchedule = $this->normalizeOtaDiagnosisExecutionSchedule($scheduleInput);

        return [
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $recordId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'object_type' => 'campaign',
            'action_type' => $actionType,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => $currentValue,
            'target_value' => [
                'campaign_type' => $actionType,
                'target_metric' => $targetMetric,
                'expected_direction' => 'increase',
                'action_text' => $actionText,
                'measurement_policy' => 'target_not_quantified_until_manual_confirmation',
                'assignee_id' => $workflowSchedule['assignee_id'],
                'due_at' => $workflowSchedule['due_at'],
                'review_at' => $workflowSchedule['review_at'],
                'workflow_schedule' => $workflowSchedule,
            ],
            'evidence' => [
                'evidence_refs' => $evidenceRefs,
                'evidence_sources' => $referencedEvidence,
                'derived_metric_lineage' => $snapshot['derived_metric_lineage'] ?? [],
                'data_gaps' => [],
                'optional_data_gaps' => $snapshot['optional_data_gaps'] ?? [],
                'source_policy' => 'saved_ota_diagnosis_evidence_only',
                'protected_boundary' => '人工审批和平台外执行；不自动修改 OTA 价格、库存、广告或竞争圈数据。',
                'diagnosis_log_id' => $recordId,
                'action_item_id' => (string)($action['id'] ?? ''),
                'action_item_status' => (string)($action['status'] ?? ''),
                'diagnosis_summary' => (string)($snapshot['core_conclusion'] ?? $snapshot['diagnosis']['summary'] ?? ''),
                'metric_scope' => 'ota_channel',
                'expected_delta_status' => 'not_quantified',
                'expected_direction' => 'increase',
                'workflow_schedule' => $workflowSchedule,
                'decision_recommendation' => $action,
            ],
            'expected_metric' => $targetMetric,
            'risk_level' => $priority === 'high' ? 'high' : ($priority === 'low' ? 'low' : 'medium'),
            'status' => 'pending_approval',
        ];
    }

    /** @return array{assignee_id:int,due_at:string,review_at:string,source_policy:string} */
    private function normalizeOtaDiagnosisExecutionSchedule(array $input): array
    {
        $assigneeId = (int)($input['assignee_id'] ?? 0);
        if ($assigneeId <= 0) {
            throw new \InvalidArgumentException('assignee_id is required before creating an execution intent');
        }

        $dueAt = $this->normalizeOtaDiagnosisExecutionDateTime((string)($input['due_at'] ?? ''), 'due_at');
        $reviewAt = $this->normalizeOtaDiagnosisExecutionDateTime((string)($input['review_at'] ?? ''), 'review_at');
        if (strtotime($reviewAt) < strtotime($dueAt)) {
            throw new \InvalidArgumentException('review_at must not be earlier than due_at');
        }

        return [
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
            'review_at' => $reviewAt,
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ];
    }

    private function normalizeOtaDiagnosisExecutionDateTime(string $value, string $field): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            throw new \InvalidArgumentException($field . ' is required before creating an execution intent');
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \InvalidArgumentException($field . ' must be a valid date-time');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function assertOtaDiagnosisExecutionAssigneeScope(int $assigneeId, int $hotelId): void
    {
        $assignee = UserModel::where('id', $assigneeId)->where('status', UserModel::STATUS_ENABLED)->find();
        if (!$assignee) {
            throw new \InvalidArgumentException('assignee_id must reference an enabled user');
        }
        if (!$assignee->hasHotelPermission($hotelId, 'operation.execute')) {
            throw new \InvalidArgumentException('assignee_id lacks operation.execute permission for the diagnosis hotel');
        }
    }

    private function classifyOtaDiagnosisExecutionAction(string $action): array
    {
        if ($this->textContainsAny($action, ['广告', '投放', 'ROAS', 'roas'])) {
            return ['advertising_optimization', 'advertising_roas'];
        }
        if ($this->textContainsAny($action, ['服务质量', '服务分', 'PSI', 'psi'])) {
            return ['service_quality_improvement', 'avg_psi_score'];
        }
        if ($this->textContainsAny($action, ['曝光', '列表页', '主图', '标题'])) {
            return ['listing_conversion_optimization', 'detail_rate'];
        }
        if ($this->textContainsAny($action, ['下单', '订单', '转化', '房型', '取消政策'])) {
            return ['booking_conversion_optimization', 'order_rate'];
        }

        return ['ota_operation_follow_up', 'book_order_num'];
    }

    private function buildAiDecisionClosure(array $result): array
    {
        $actionItems = array_values(array_filter((array)($result['action_items'] ?? []), 'is_array'));
        $readyItems = array_values(array_filter($actionItems, static fn(array $item): bool => ($item['execution_ready'] ?? false) === true));
        $blockedItems = array_values(array_filter($actionItems, static function (array $item): bool {
            $status = (string)($item['status'] ?? '');
            return str_starts_with($status, 'blocked_') || ($item['execution_ready'] ?? true) === false;
        }));
        $dataGaps = $this->normalizeOtaDiagnosisDataGaps($result['data_gaps'] ?? []);
        $blockingDataGaps = $this->blockingOtaDiagnosisDataGaps($dataGaps);
        $blockingGapCodes = array_values(array_filter(array_map(
            static fn(array $gap): string => trim((string)($gap['code'] ?? '')),
            $blockingDataGaps
        )));
        $optionalDataGaps = array_values(array_filter($dataGaps, static function (array $gap) use ($blockingGapCodes): bool {
            return !in_array(trim((string)($gap['code'] ?? '')), $blockingGapCodes, true);
        }));
        $unresolvedProblems = array_values(array_filter(array_map(
            static fn(mixed $problem): string => trim((string)$problem),
            (array)($result['main_problems'] ?? $result['diagnosis']['abnormal_metrics'] ?? [])
        )));
        $governance = is_array($result['ai_governance'] ?? null) ? $result['ai_governance'] : [];
        $blockedReasons = [];
        foreach ($blockedItems as $item) {
            $reason = trim((string)($item['blocked_reason'] ?? ''));
            if ($reason !== '') {
                $blockedReasons[] = $reason;
            }
        }
        foreach ($blockingDataGaps as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            if ($code !== '') {
                $blockedReasons[] = $code;
            }
        }
        if (empty($actionItems) && !empty($unresolvedProblems)) {
            $blockedReasons[] = 'unresolved_diagnostic_signal_without_evidence_backed_action';
        }
        $blockedReasons = array_values(array_unique($blockedReasons));
        $status = 'action_required';
        if (!empty($blockingDataGaps) || (empty($actionItems) && !empty($unresolvedProblems))) {
            $status = 'blocked_by_data';
        } elseif (!empty($readyItems)) {
            $status = 'action_required';
        } elseif (empty($actionItems)) {
            $status = 'no_action';
        } else {
            $status = 'blocked_by_data';
        }
        $isBlocked = $status === 'blocked_by_data';
        $isNoAction = $status === 'no_action';
        $legacyStatus = $isBlocked ? 'blocked' : ($isNoAction ? 'ready' : 'pending_human_confirmation');

        return [
            'status' => $status,
            'legacy_status' => $legacyStatus,
            'scope' => 'ota_channel',
            'chain' => 'OTA data -> revenue analysis -> AI decisions -> operations management -> investment decisions',
            'data_evidence_input' => [
                'source_policy' => (string)($result['source_policy'] ?? 'database_only'),
                'source_counts' => $result['data_summary']['source_counts'] ?? [],
                'evidence_refs' => $this->extractAiEvidenceRefs($result),
                'data_gaps' => $dataGaps,
                'blocking_data_gaps' => $blockingDataGaps,
                'optional_data_gaps' => $optionalDataGaps,
                'enough_for_decision' => !$isBlocked,
                'enough_for_executable_actions' => !$isBlocked && !empty($readyItems),
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
                'decision' => $isNoAction ? 'no_new_action' : ($isBlocked ? 'resolve_data_gaps' : 'manual_confirmation_required'),
                'items' => $actionItems,
            ],
            'blocked_state' => [
                'is_blocked' => $isBlocked,
                'blocked_reasons' => $blockedReasons,
                'blocked_items' => array_map(static fn(array $item): array => [
                    'id' => (string)($item['id'] ?? ''),
                    'status' => (string)($item['status'] ?? ''),
                    'blocked_reason' => (string)($item['blocked_reason'] ?? ''),
                    'missing_evidence' => $item['missing_evidence'] ?? [],
                ], $blockedItems),
            ],
            'human_confirmation' => [
                'required' => $status === 'action_required',
                'status' => $status === 'action_required' ? 'pending' : 'not_required',
                'reason' => $isNoAction
                    ? '本次没有达到行动阈值的证据，不创建运营执行意图。'
                    : ($isBlocked
                        ? '先补齐目标日期核心 OTA 证据，再重新生成诊断。'
                        : (string)($governance['human_confirmation_reason'] ?? 'manual confirmation required before operation execution')),
                'ready_action_ids' => array_values(array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $readyItems)),
                'confirm_before_execution' => $status === 'action_required',
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
        $hasKnownTraffic = $this->hasKnownOtaDiagnosisMetric($row, [
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        ]);
        if ($hasKnownTraffic) {
            $tags[] = 'traffic';
        }
        $isNonRevenueType = in_array($dataType, ['advertising', 'quality', 'review', 'ads', 'ad', 'campaign'], true);
        $hasKnownRevenue = $this->hasKnownOtaDiagnosisMetric($row, ['amount', 'quantity', 'book_order_num']);
        if (!$isNonRevenueType && $hasKnownRevenue) {
            $tags[] = 'revenue';
        }
        if (!$isNonRevenueType && $this->hasKnownOtaDiagnosisMetric($row, ['book_order_num', 'order_filling_num', 'order_submit_num'])) {
            $tags[] = 'order';
        }
        if ($this->hasKnownOtaDiagnosisMetric($row, ['order_visitors', 'submit_users'])) {
            $tags[] = 'order';
        }
        $amount = $this->readRowNumber($row, 'amount');
        $quantity = $this->readRowNumber($row, 'quantity');
        if (!$isNonRevenueType && ($this->hasKnownOtaDiagnosisMetric($row, ['adr', 'price', 'our_price', 'current_price']) || ($amount !== null && $quantity !== null && $quantity > 0))) {
            $tags[] = 'price';
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
            if (($source['decision_eligible'] ?? false) !== true) {
                continue;
            }
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
                if (($source['decision_eligible'] ?? false) !== true) {
                    continue;
                }
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
            try {
                $cache[$table] = !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
            } catch (\Throwable) {
                $cache[$table] = !empty(Db::query('PRAGMA table_info(`' . $table . '`)'));
            }
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
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($rows as $row) {
                if (!empty($row['Field'])) {
                    $columns[(string)$row['Field']] = true;
                }
            }
        } catch (\Throwable) {
            foreach (Db::query('PRAGMA table_info(`' . $table . '`)') as $row) {
                if (!empty($row['name'])) {
                    $columns[(string)$row['name']] = true;
                }
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }

    private function authoritativeTenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            return 0;
        }

        try {
            return max(0, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        } catch (\Throwable) {
            return 0;
        }
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
        int $limit = 0,
        string $hotelScopeColumn = 'hotel_id'
    ): array {
        if ($hotelId <= 0) {
            return [];
        }

        $columns = $this->tableColumns($table);
        if (empty($columns) || !isset($columns[$hotelScopeColumn]) || !isset($columns[$dateColumn])) {
            return [];
        }

        $selectedFields = array_values(array_unique(array_merge($fields, [$hotelScopeColumn, $dateColumn])));
        $selectedFields = array_values(array_intersect($selectedFields, array_keys($columns)));
        if (empty($selectedFields)) {
            return [];
        }

        $query = Db::name($table)
            ->field(implode(',', $selectedFields))
            ->where($hotelScopeColumn, $hotelId)
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
        $ownPlatformHotelIds = $this->otaDiagnosisOwnPlatformHotelIds($rows, $hotelId, $platform);
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
                'flow_rate' => null,
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

            $isOrderMetricRow = in_array($dataType, ['business', 'order'], true);
            $amount = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'amount',
                    ['amount', 'checkoutRevenue', 'checkout_revenue', 'revenue', 'order_amount', 'orderAmount', 'room_revenue', 'bookAmount', 'saleAmount', 'totalAmount', 'payAmount'],
                    ['order_amount', 'amount']
                )
                : null;
            $quantity = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'quantity',
                    ['quantity', 'room_nights', 'roomNights', 'nights', 'night_count', 'nightCount', 'roomCount', 'room_count', 'checkoutRoomNights', 'checkout_room_nights', 'checkOutQuantity', 'bookQuantity'],
                    ['room_nights', 'quantity']
                )
                : null;
            $bookOrderNum = $isOrderMetricRow
                ? $this->readOtaDiagnosisEvidenceNumber(
                    $row,
                    $raw,
                    'book_order_num',
                    ['book_order_num', 'orders', 'order_count', 'orderCount', 'bookOrderNum', 'orderNum', 'orderQuantity', 'bookings', 'bookingCount'],
                    ['order_count', 'book_order_num']
                )
                : null;
            $dataValue = $this->readRowNumber($row, 'data_value');

            $isOwnOperatingRow = OtaOperatingScope::isOwnOperatingRow(
                $row,
                $raw,
                $ownHotelNames,
                $ownPlatformHotelIds
            );
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
                    'flow_rate' => null,
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

            $traffic = in_array($dataType, ['traffic', 'business'], true)
                ? $this->extractOtaTrafficMetrics($row, $raw)
                : [
                    'list_exposure' => null,
                    'detail_visitors' => null,
                    'flow_rate' => null,
                    'order_visitors' => null,
                    'submit_users' => null,
                ];
            foreach ($traffic as $key => $value) {
                if ($key === 'flow_rate' && $value !== null) {
                    $summary['totals'][$key] = $value;
                    $summary['daily'][$date][$key] = $value;
                    continue;
                }
                $this->addNullableOtaDiagnosisMetric($summary['totals'], $key, $value);
                $this->addNullableOtaDiagnosisMetric($summary['daily'][$date], $key, $value);
            }

            $knownCoreValues = array_values(array_filter(
                array_merge([$amount, $quantity, $bookOrderNum], array_values($traffic)),
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

    /**
     * Resolve only the OTA identifiers carried by the exact persisted data
     * sources represented in the diagnosis rows. This keeps name drift from
     * excluding the hotel's own facts without treating every hotel-bound row
     * as self evidence.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function otaDiagnosisOwnPlatformHotelIds(array $rows, int $hotelId, string $platform): array
    {
        $platform = strtolower(trim($platform));
        if ($hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
            return [];
        }

        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int)($row['data_source_id'] ?? 0),
            array_values(array_filter($rows, 'is_array'))
        ), static fn (int $id): bool => $id > 0)));
        if ($sourceIds === []) {
            return [];
        }
        $tenantId = $this->authoritativeTenantIdForHotel($hotelId);
        if ($tenantId <= 0) {
            return [];
        }

        try {
            $sources = Db::name('platform_data_sources')
                ->field('id,config_json')
                ->whereIn('id', $sourceIds)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'external_hotel_id'];
        $ids = [];
        foreach ($sources as $source) {
            $config = json_decode((string)($source['config_json'] ?? ''), true);
            if (!is_array($config)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = trim((string)($config[$key] ?? ''));
                if ($value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function extractOtaTrafficMetrics(array $row, array $raw): array
    {
        return [
            'list_exposure' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'list_exposure',
                ['mt_exposure', 'listExposure', 'list_exposure', 'exposure', 'exposure_count', 'exposureCount', 'exposureUV', 'exposure_uv'],
                ['list_exposure', 'mt_exposure']
            ),
            'detail_visitors' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'detail_exposure',
                ['mt_intention_uv', 'intentionUV', 'intention_uv', 'detailExposure', 'detail_exposure', 'totalDetailNum', 'detailVisitors', 'qunarDetailVisitors'],
                ['detail_exposure', 'mt_intention_uv']
            ),
            'flow_rate' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'flow_rate',
                ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'cvr'],
                ['flow_rate']
            ),
            'order_visitors' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'order_filling_num',
                ['orderFillingNum', 'order_filling_num', 'orderVisitors'],
                ['order_filling_num']
            ),
            'submit_users' => $this->readOtaDiagnosisEvidenceNumber(
                $row,
                $raw,
                'order_submit_num',
                ['mt_pay_orders', 'pay_orders', 'payOrders', 'orderSubmitNum', 'order_submit_num', 'submitUsers', 'orderCount', 'order_count'],
                ['order_submit_num', 'mt_pay_orders']
            ),
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

    private function readOtaDiagnosisEvidenceNumber(array $row, array $raw, string $rowKey, array $rawKeys, array $metricKeys): ?float
    {
        $detail = $this->otaDiagnosisRawDetail($raw);
        $rawValue = $this->readSummaryNumber($detail, $rawKeys, null);
        if ($rawValue !== null) {
            return $rawValue;
        }

        $value = $this->readRowNumber($row, $rowKey);
        if ($value === null) {
            return null;
        }
        if ((float)$value !== 0.0) {
            return $value;
        }

        return $this->otaDiagnosisMetricFactCaptured($raw, $rowKey, $metricKeys) ? 0.0 : null;
    }

    private function otaDiagnosisMetricFactCaptured(array $raw, string $normalizedField, array $metricKeys): bool
    {
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact) || ($fact['status'] ?? '') !== 'captured' || ($fact['stored_value_present'] ?? false) !== true) {
                continue;
            }
            $factMetric = trim((string)($fact['metric_key'] ?? ''));
            $factField = trim((string)($fact['normalized_field'] ?? ''));
            if ($factField === $normalizedField || in_array($factMetric, $metricKeys, true)) {
                return true;
            }
        }
        return false;
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

    /**
     * Return prices from the latest single, fully comparable public-rate key.
     * Legacy rows intentionally fail this gate instead of being coerced to 0.
     *
     * @param array<int,array<string,mixed>> $priceRows
     * @param array<int,array<string,mixed>> $analysisRows
     * @return array<int,float>
     */
    private function otaDiagnosisComparableCompetitorPrices(array $priceRows, array $analysisRows): array
    {
        $groups = [];
        foreach (array_merge($priceRows, $analysisRows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->otaDiagnosisCompetitorComparisonKey($row);
            if ($key === '') {
                continue;
            }
            $price = $row['price'] ?? $row['competitor_price'] ?? null;
            $capturedAt = trim((string)($row['collected_at'] ?? $row['fetch_time'] ?? $row['create_time'] ?? ''));
            $groups[$key]['prices'][] = (float)$price;
            if ($capturedAt !== '' && strcmp($capturedAt, (string)($groups[$key]['latest'] ?? '')) > 0) {
                $groups[$key]['latest'] = $capturedAt;
            }
        }

        if ($groups === []) {
            return [];
        }
        uasort($groups, static fn(array $left, array $right): int => strcmp((string)($right['latest'] ?? ''), (string)($left['latest'] ?? '')));
        $latestGroup = reset($groups);
        return is_array($latestGroup['prices'] ?? null) ? array_values($latestGroup['prices']) : [];
    }

    private function otaDiagnosisCompetitorComparisonKey(array $row): string
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1
            || !in_array(strtolower(trim((string)($row['validation_status'] ?? ''))), ['normal', 'available', 'ok', 'valid', 'verified'], true)
            || !in_array(strtolower(trim((string)($row['availability'] ?? ''))), ['available', 'bookable'], true)
        ) {
            return '';
        }

        $price = $row['price'] ?? $row['competitor_price'] ?? null;
        if (!is_numeric($price) || (float)$price <= 0) {
            return '';
        }

        $requiredStrings = [
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis', 'currency',
            'source_method', 'source_ref',
        ];
        foreach ($requiredStrings as $field) {
            $value = $field === 'platform'
                ? ($row['platform'] ?? $row['ota_platform'] ?? null)
                : ($row[$field] ?? null);
            if (trim((string)$value) === '') {
                return '';
            }
        }

        $capturedAt = trim((string)($row['collected_at'] ?? $row['fetch_time'] ?? $row['create_time'] ?? ''));
        if ($capturedAt === '' || strtotime($capturedAt) === false) {
            return '';
        }
        $checkIn = trim((string)($row['check_in_date'] ?? ''));
        $checkOut = trim((string)($row['check_out_date'] ?? ''));
        if (strtotime($checkIn) === false || strtotime($checkOut) === false || strtotime($checkOut) <= strtotime($checkIn)) {
            return '';
        }
        if (!array_key_exists('tax_fee_included', $row)
            || !is_numeric($row['adults'] ?? null)
            || (int)$row['adults'] <= 0
            || !is_numeric($row['children'] ?? null)
            || (int)$row['children'] < 0
        ) {
            return '';
        }

        $keyFields = [
            'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key', 'breakfast',
            'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis', 'currency',
            'adults', 'children',
        ];
        $keyParts = [strtolower(trim((string)($row['platform'] ?? $row['ota_platform'] ?? '')))];
        foreach ($keyFields as $field) {
            $keyParts[] = strtolower(trim((string)$row[$field]));
        }

        return hash('sha256', implode('|', $keyParts));
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
            . "actions 允许为空数组；当结构化摘要中的规则动作和异常指标均为空时，不得自行新增问题或行动，明确说明本次无需新增行动。\n"
            . "派生指标只能按摘要给出的公式和真实字段解释；未知字段、未知金额或未量化目标必须保持未知，不能补0或猜测。\n"
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
        return "你是酒店OTA渠道分析顾问。请基于多个分组分析结果，输出一份面向酒店经营者的携程OTA渠道样本诊断报告。\n"
            . "不要逐组复述，要综合归纳。只基于分组报告摘要，不要使用完整原始抓取数据或假设数据；知识库只用于解释指标口径、诊断模板和行动拆解。所有结论必须限定在已抓取的携程OTA渠道、覆盖酒店和已返回字段内，不得外推全酒店营收、全渠道需求或整体经营状况。\n"
            . "重点回答：1. 携程OTA渠道样本现状；2. 渠道内最需关注的问题或尚不能判断的缺口；3. 最值得关注的酒店；4. 已有竞对样本体现的机会或证据不足；5. 价格与订单表现、流量数据口径提示；6. 下一步建议优先复核的运营动作。建议不等于已执行。\n"
            . "返回 JSON：{\"overall_conclusion\":\"总体结论\",\"key_findings\":[],\"competitor_insights\":[],\"problem_hotels\":[{\"hotel_name\":\"酒店名\",\"problem\":\"问题\",\"key_metrics\":[],\"suggestion\":\"建议\"}],\"recommended_actions\":[],\"priority\":\"high/medium/low\",\"data_anomalies\":[]}\n"
            . "key_findings、competitor_insights、recommended_actions、data_anomalies 必须是字符串数组；problem_hotels 必须是对象数组，不允许返回字符串数组；priority 只能是 high、medium、low。\n"
            . "若 data_quality.is_cross_day_window 为 true，曝光、访客、浏览率、订单率、转化率为0只作为数据口径提示，不能作为核心经营异常或严重结论。\n"
            . "综合结论主要基于订单、间夜、OTA渠道收入、OTA渠道ADR、评分等已返回指标；流量漏斗类指标建议待平台更新后复查。这些指标不得表述为全酒店经营指标。\n"
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

            return $this->success(
                $report,
                ($report['decision_ready'] ?? false) === true ? '可行性测算已生成' : '核心输入不足，已保存为待评估'
            );
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
            $report = $this->feasibilityService()->regenerate(
                $id,
                (int)($this->currentUser->id ?? 0),
                $this->currentUser->isSuperAdmin(),
                $this->request->post()
            );
            if (!$report) {
                return $this->error('报告不存在', 404);
            }

            OperationLog::record('agent', 'feasibility_regenerate', '重新生成智策可行性报告', (int) ($this->currentUser->id ?? 0), null, null, [
                'source_report_id' => $id,
                'report_id' => $report['id'] ?? 0,
            ]);

            return $this->success(
                $report,
                ($report['decision_ready'] ?? false) === true ? '可行性测算已重新生成' : '核心输入不足，已重新保存为待评估'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
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
     * @return array<int, array<string, mixed>>
     */
    private function revenueForecastRange(int $hotelId, string $startDate, string $endDate): array
    {
        $key = $hotelId . '|' . $startDate . '|' . $endDate;
        if (!array_key_exists($key, $this->revenueForecastRangeCache)) {
            $this->revenueForecastRangeCache[$key] = DemandForecast::getForecastRange(
                $hotelId,
                $startDate,
                $endDate
            )->toArray();
        }
        return $this->revenueForecastRangeCache[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueForecastAccuracy(int $hotelId, int $days = 30): array
    {
        $key = $hotelId . '|' . $days;
        if (!array_key_exists($key, $this->revenueForecastAccuracyCache)) {
            $this->revenueForecastAccuracyCache[$key] = DemandForecast::getAccuracyStats($hotelId, $days);
        }
        return $this->revenueForecastAccuracyCache[$key];
    }

    /**
     * @return array<int, string>
     */
    private function revenueHighDemandDates(int $hotelId, float $threshold = 80): array
    {
        $key = $hotelId . '|' . $threshold;
        if (!array_key_exists($key, $this->revenueHighDemandDatesCache)) {
            $this->revenueHighDemandDatesCache[$key] = DemandForecast::getHighDemandDates($hotelId, $threshold);
        }
        return $this->revenueHighDemandDatesCache[$key];
    }

    /**
     * 获取需求预测
     */
    public function demandForecasts(): Response
    {
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d'));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d', strtotime('+30 days')));
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate) || $startDate > $endDate) {
            return $this->error('start_date and end_date must be a valid date range', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);
        
        return $this->success($this->buildDemandForecastsPayload($hotelId, $startDate, $endDate));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDemandForecastsPayload(int $hotelId, string $startDate, string $endDate): array
    {
        $forecasts = $this->revenueForecastRange($hotelId, $startDate, $endDate);
        $forecastIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $forecasts), static fn(int $id): bool => $id > 0));
        $forecasts = (new RevenueForecastReadinessService())->enrichForecastRows($forecasts, $this->priceSuggestionStatsByForecastId($hotelId, $forecastIds));

        return [
            'forecasts' => $forecasts,
            'accuracy' => $this->revenueForecastAccuracy($hotelId, 30),
            'high_demand_dates' => $this->revenueHighDemandDates($hotelId, 80),
        ];
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
        try {
            $data = $this->normalizeDemandForecastPayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->assertRevenueHotelPermission((int)$data['hotel_id']);
        $this->assertRevenueRoomTypeScope((int)$data['hotel_id'], (int)$data['room_type_id']);
        
        $forecast = DemandForecast::createForecast($data['hotel_id'], $data['forecast_date'], $data);
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_create',
            '人工需求预测输入已保存: ' . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            ['forecast_id' => $forecast->id],
            $this->currentUser->id ?? 0
        );
        
        return $this->success(['id' => $forecast->id], '人工需求预测输入已保存（未代表模型预测已校准）');
    }

    /**
     * 获取竞对分析
     */
    public function competitorAnalysis(): Response
    {
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildCompetitorAnalysisPayload($hotelId, $date));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompetitorAnalysisPayload(int $hotelId, string $date): array
    {
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
        $alerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 20, $date);
        
        // 获取价格趋势
        $trends = CompetitorAnalysis::getPriceTrends($hotelId, [], 0, $date);
        
        return [
            'price_matrix' => $priceMatrix,
            'alerts' => $alerts,
            'trends' => $trends,
            'date' => $date,
            'query_scope' => [
                'hotel_id' => $hotelId,
                'date' => $date,
                'metric_scope' => 'ota_channel',
            ],
        ];
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

        $forecastMethodRaw = $data['forecast_method'] ?? null;
        if ($forecastMethodRaw === null || trim((string)$forecastMethodRaw) === '') {
            throw new \InvalidArgumentException('forecast_method is required');
        }
        $forecastMethod = (int)$forecastMethodRaw;
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
            'predicted_demand' => (int)round($this->parseBoundedNumber($data['predicted_demand'] ?? null, 'predicted_demand', 0.0, null, true)),
            'confidence_score' => $this->normalizeConfidenceScore($data['confidence_score'] ?? ($data['confidence_percent'] ?? null)),
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
        try {
            $data = $this->normalizeCtripCompetitorPricePayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->assertRevenueHotelPermission((int)$data['hotel_id']);
        $this->assertRevenueRoomTypeScope((int)$data['hotel_id'], (int)$data['room_type_id']);
        
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
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $status = (int) $this->request->param('status', 0);
        $date = (string) $this->request->param('date', date('Y-m-d'));
        $pagination = $this->getPagination();
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildPriceSuggestionsPayload(
            $hotelId,
            $status,
            $date,
            $pagination['page'],
            $pagination['page_size']
        ));
    }

    /**
     * @return array{list:array<int, array<string, mixed>>, pagination:array<string, int|float>}
     */
    private function buildPriceSuggestionsPayload(
        int $hotelId,
        int $status,
        string $date,
        int $page,
        int $pageSize
    ): array {
        
        $query = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $date);
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $pricingService = new RevenuePricingRecommendationService();
        $list = $pricingService->enrichSuggestionRows(
            $list,
            $this->priceSuggestionExecutionItemsByRecordId($hotelId, array_column($list, 'id'))
        );
        
        return [
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => (int)ceil($total / $pageSize),
            ],
        ];
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
        $hotelId = (int)$this->request->param('hotel_id', 0);
        $date = (string)$this->request->param('date', date('Y-m-d'));
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($date)) {
            return $this->error('date must be YYYY-MM-DD', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

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

        try {
            $service = new OperationManagementService();
            $input = $service->buildPriceSuggestionExecutionIntentInput($suggestion->toArray(), [
                'platform' => (string)$this->request->param('platform', $this->request->param('channel', '')),
                'room_type_key' => (string)$this->request->param('room_type_key', ''),
                'rate_plan_key' => (string)$this->request->param('rate_plan_key', ''),
                'execution_date' => (string)$this->request->param('execution_date', ''),
                'expected_metric' => (string)$this->request->param('expected_metric', 'orders'),
                'expected_delta' => (float)$this->request->param('expected_delta', 0),
            ]);
            $hotelIds = [(int)$suggestion->hotel_id];
            $intent = $service->createExecutionIntent(
                $hotelIds,
                (int)$suggestion->hotel_id,
                $input,
                (int)($this->currentUser->id ?? 0),
                false,
                null,
                true
            );
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
        $hotelId = (int)$this->request->param('hotel_id', 0);
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildRoomTypesPayload($hotelId));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRoomTypesPayload(int $hotelId): array
    {
        $rows = RoomType::where('hotel_id', $hotelId)
            ->order('sort_order', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        return [
            'list' => $rows,
            'input_scope' => 'manual_pricing_configuration',
            'target_workflow' => 'ctrip_revenue_ai_pricing_generation',
            'evidence_status' => 'operator_provided',
            'auto_write_ota' => false,
            'next_action' => count($rows) > 0
                ? '继续补齐需求预测和竞对价格样本后，再生成待审调价建议。'
                : '先配置至少一个启用房型、基础价和最低保护价；未配置前不生成待审调价建议。',
        ];
    }

    /**
     * Load the read-only Revenue Agent workbench in one authenticated request.
     */
    public function revenueBundle(): Response
    {
        $hotelId = (int)$this->request->param('hotel_id', 0);
        $startDate = (string)$this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string)$this->request->param('end_date', date('Y-m-d'));
        $businessDate = (string)$this->request->param('business_date', date('Y-m-d'));
        $priceDate = (string)$this->request->param('date', $businessDate);
        $competitorDate = (string)$this->request->param('competitor_date', $businessDate);
        $status = (int)$this->request->param('status', 0);
        $pagination = $this->getPagination();

        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        foreach ([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'business_date' => $businessDate,
            'date' => $priceDate,
            'competitor_date' => $competitorDate,
        ] as $field => $date) {
            if (!$this->isDateString($date)) {
                return $this->error($field . ' must be YYYY-MM-DD', 422);
            }
        }
        if ($startDate > $endDate) {
            return $this->error('start_date must not be after end_date', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        $overviewFilters = [
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'permitted_hotel_ids' => array_values(array_unique(array_filter(
                array_map('intval', $this->currentUser?->getPermittedHotelIds() ?? []),
                static fn(int $id): bool => $id > 0
            ))),
            'is_super_admin' => (bool)($this->currentUser?->isSuperAdmin() ?? false),
        ];

        return $this->success([
            'overview' => (new RevenueAiOverviewService())->overview($overviewFilters),
            'analysis' => $this->buildRevenueAnalysisPayload($hotelId, $startDate, $endDate),
            'dashboard' => $this->buildRevenueDashboardPayload($hotelId),
            'forecasts' => $this->buildDemandForecastsPayload($hotelId, $startDate, $endDate),
            'competitor' => $this->buildCompetitorAnalysisPayload($hotelId, $competitorDate),
            'room_types' => $this->buildRoomTypesPayload($hotelId),
            'price_suggestions' => $this->buildPriceSuggestionsPayload(
                $hotelId,
                $status,
                $priceDate,
                $pagination['page'],
                $pagination['page_size']
            ),
            'query_scope' => [
                'hotel_id' => $hotelId,
                'metric_scope' => 'ota_channel',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'business_date' => $businessDate,
                'price_date' => $priceDate,
                'competitor_date' => $competitorDate,
            ],
        ]);
    }

    public function saveRoomType(): Response
    {
        try {
            $payload = $this->normalizeRoomTypePayload($this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->assertRevenueHotelPermission((int)$payload['hotel_id']);

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
        $hotelId = (int) $this->request->param('hotel_id', 0);
        $startDate = (string) $this->request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = (string) $this->request->param('end_date', date('Y-m-d'));
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate) || $startDate > $endDate) {
            return $this->error('start_date and end_date must be a valid date range', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);
        
        return $this->success($this->buildRevenueAnalysisPayload($hotelId, $startDate, $endDate));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevenueAnalysisPayload(int $hotelId, string $startDate, string $endDate): array
    {
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId)->toArray();
        
        // 获取需求预测统计
        $forecastStats = $this->revenueForecastAccuracy($hotelId, 30);
        $highDemandDates = $this->revenueHighDemandDates($hotelId, 80);
        
        // 计算RevPAR趋势（基于预测和历史数据）
        $revparTrend = [];
        $forecasts = $this->revenueForecastRange($hotelId, $startDate, $endDate);
        foreach ($forecasts as $forecast) {
            $revparTrend[] = [
                'date' => $forecast['forecast_date'] ?? null,
                'predicted_revpar' => $forecast['predicted_revpar'] ?? null,
                'predicted_occupancy' => $forecast['predicted_occupancy'] ?? null,
                'confidence' => $forecast['confidence_score'] ?? null,
            ];
        }
        
        // 获取定价策略建议
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates);
        
        return [
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ];
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
                'title' => '高需求预测日期待复核',
                'description' => '需求预测记录标记了 ' . count($highDemandDates) . ' 个高需求日期；该标记不是实际需求或涨价效果证明。',
                'suggested_action' => '结合当前库存、最低保护价、竞对同房型价格和人工审核后再决定是否调价。',
                'expected_impact' => '尚未评估；需用执行前后同口径数据验证。',
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
                'title' => '竞对价差待复核',
                'description' => '今日竞对分析记录中，我方价格高于竞对的记录较多；价差本身不能证明客源流失。',
                'suggested_action' => '先核对同日期、同房型、同取消与早餐条件，再结合最低保护价决定是否调整。',
                'expected_impact' => '尚未评估；不能仅凭竞对价差推算入住率变化。',
            ];
        }
        
        return $strategies;
    }

    /**
     * 获取收益管理Agent综合仪表板
     */
    public function revenueDashboard(): Response
    {
        $hotelId = (int) $this->request->param('hotel_id', 0);
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildRevenueDashboardPayload($hotelId));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevenueDashboardPayload(int $hotelId): array
    {
        // 今日定价建议
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', date('Y-m-d'))
            ->with('roomType')
            ->select()
            ->toArray();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = $this->revenueForecastAccuracy($hotelId, 30);
        $pricingModelSummary = (new RevenuePricingRecommendationService())->hotelPricingModelSummary($hotelId, date('Y-m-d'));
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15);
        
        // 本周RevPAR预测
        $weekForecasts = $this->revenueForecastRange(
            $hotelId,
            date('Y-m-d'),
            date('Y-m-d', strtotime('+7 days'))
        );
        
        $revparValues = [];
        foreach ($weekForecasts as $forecast) {
            $value = $forecast['predicted_revpar'] ?? null;
            if (is_numeric($value)) {
                $revparValues[] = (float)$value;
            }
        }
        $avgPredictedRevpar = $revparValues !== []
            ? round(array_sum($revparValues) / count($revparValues), 2)
            : null;
        
        return [
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'week_revpar_forecast_status' => $avgPredictedRevpar === null ? 'insufficient_data' : 'available',
            'high_demand_count' => count($this->revenueHighDemandDates($hotelId, 80)),
            'pricing_backtest' => $pricingModelSummary['backtest'] ?? [],
            'pricing_model_summary' => $pricingModelSummary,
        ];
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
        
        $query = AgentLog::where('id', '>', 0);
        if ($hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        }
        
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
