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
use app\service\AiModelRoutingService;
use app\service\CompetitorPriceReadinessService;
use app\service\CtripOperatingRadarDiagnosisService;
use app\service\FeasibilityReportService;
use app\service\KnowledgeDecisionGateService;
use app\service\LlmClient;
use app\service\OperationManagementService;
use app\service\OtaOperatingScope;
use app\service\OtaDiagnosisRequestedPeriodGateService;
use app\service\RevenueAiOverviewService;
use app\service\RevenueForecastReadinessService;
use app\service\RevenuePricingRecommendationService;
use app\service\PriceSuggestionShadowReplayService;
use think\Response;
use think\facade\Db;

/**
 * Agent控制器
 * 管理 OTA 诊断、收益管理、知识和运行日志能力。
 */
class Agent extends Base
{
    use \app\controller\concern\AgentOtaExecutionIntentConcern;
    use \app\controller\concern\AgentCapturedOtaAnalysisConcern;
    use \app\controller\concern\AgentOtaDiagnosisBuildConcern;
    use \app\controller\concern\AgentOtaDiagnosisActionConcern;
    use \app\controller\concern\AgentOtaDiagnosisPersistenceConcern;
    use \app\controller\concern\AgentOtaDiagnosisSummaryGuardConcern;

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
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar', 'all_ota'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar、all_ota', 422);
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
                if (!$this->isStoredOtaDiagnosisReadbackVerified(
                    $context,
                    $snapshot,
                    $hotelId,
                    $platform,
                    $targetRange
                )) {
                    return $this->success([
                        'status' => 'unverified',
                        'diagnosis' => null,
                        'reason' => 'saved_diagnosis_readback_identity_mismatch',
                        'scope' => [
                            'hotel_id' => $hotelId,
                            'platform' => $platform,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                        ],
                    ], '保存的 OTA 诊断身份回读不一致');
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
        $requestedModelKey = trim((string) $this->request->param('model_key', ''));
        $modelSelection = (new AiModelRoutingService())->resolve(
            $requestedModelKey,
            'ota_diagnosis',
            'deepseek_v4_default',
            'ollama'
        );
        $modelKey = (string)$modelSelection['model_key'];
        $modelMode = $this->request->param('model_mode', null);
        $modelOptions = $modelMode !== null && trim((string) $modelMode) !== '' ? ['model_mode' => $modelMode] : [];

        if (!in_array($analysisMode, ['auto', 'rules_only'], true)) {
            return $this->error('analysis_mode 仅支持 auto、rules_only', 422);
        }
        $analysisRuntime = $this->resolveOtaDiagnosisAnalysisRuntime(
            $analysisMode,
            $this->isAllowedLlmModelKey($modelKey)
        );
        $analysisRuntime['model_selection'] = $modelSelection;
        if (!in_array($platform, ['ctrip', 'meituan', 'qunar', 'all_ota'], true)) {
            return $this->error('platform 仅支持 ctrip、meituan、qunar、all_ota', 422);
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
            if ($platform !== 'all_ota' && $hotelIdRaw === '' && $configId !== '') {
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

            if ($platform === 'all_ota') {
                $platformDataSets = [];
                foreach (['ctrip', 'meituan'] as $scopedPlatform) {
                    // Cross-channel diagnosis is bound only by the explicit
                    // system hotel id. A platform config id or platform hotel
                    // id must never infer or broaden this scope.
                    $platformDataSets[$scopedPlatform] = $this->queryOtaDiagnosisData(
                        $hotelId,
                        '',
                        '',
                        $scopedPlatform,
                        $startDate,
                        $endDate,
                        $analysisType
                    );
                }
                $effectiveHotelName = $hotelName;
                foreach ($platformDataSets as $dataSet) {
                    $candidateName = trim((string)($dataSet['hotel']['name'] ?? ''));
                    if ($effectiveHotelName === '' && $candidateName !== '') {
                        $effectiveHotelName = $candidateName;
                    }
                }
                $result = $this->buildAllOtaDiagnosisResult(
                    $platformDataSets,
                    $hotelId,
                    $effectiveHotelName,
                    $startDate,
                    $endDate,
                    $analysisType
                );
                $result['analysis_runtime'] = array_merge($analysisRuntime, [
                    'mode' => 'deterministic_cross_channel_rules',
                    'model_called' => false,
                    'use_rules_only' => true,
                    'fallback_reason' => 'all_ota_metrics_kept_per_platform',
                ]);
                $result['ai_governance'] = $this->buildAiGovernancePayload('ota_diagnosis', $result, [
                    'ok' => true,
                    'provider' => 'local',
                    'model_key' => 'deterministic_cross_channel_rules',
                    'model' => 'ota_diagnosis_cross_channel_rule_engine',
                    'data' => [
                        'governance' => [
                            'status' => 'skipped_cross_channel_metric_comparability_guard',
                            'prompt_version' => 'ota_diagnosis.all_ota.rules_only.v1',
                        ],
                    ],
                ]);
                $result = $this->finalizeAllOtaDiagnosisDecision($result);
                $result['decision_route'] = $this->buildOtaDiagnosisDecisionRoute($result);
                $result = $this->persistOtaDiagnosisResult($result, $hotelId, $platform);

                return $this->success(
                    $result,
                    ($result['coverage']['complete'] ?? false) === true
                        ? 'success'
                        : '携程+美团 OTA 诊断因证据覆盖不完整而受阻'
                );
            }

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
                if ($platform === 'ctrip') {
                    $result['operating_radar'] = (new CtripOperatingRadarDiagnosisService())->build($result);
                }
                $result['analysis_runtime'] = array_merge($analysisRuntime, [
                    'mode' => 'not_run_no_data',
                    'model_called' => false,
                ]);
                $result['decision_route'] = $this->buildOtaDiagnosisDecisionRoute($result);
                $result = $this->persistOtaDiagnosisResult($result, $hotelId, $platform);

                return $this->success($result, '暂无 OTA 数据');
            }

            $effectiveStartDate = (string) ($dataSet['effective_start_date'] ?? $startDate);
            $effectiveEndDate = (string) ($dataSet['effective_end_date'] ?? $endDate);
            $usedLatestAvailableData = !empty($dataSet['used_latest_available_data']);
            $analysisRuntime = OtaDiagnosisRequestedPeriodGateService::apply($analysisRuntime, $usedLatestAvailableData);
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
                    'business_date' => $endDate,
                    'business_date_start' => $startDate,
                    'business_date_end' => $endDate,
                    'source_scope' => 'verified_ota_channel_only',
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
            if ($platform === 'ctrip') {
                $result['operating_radar'] = (new CtripOperatingRadarDiagnosisService())->build($result);
            }
            $result['diagnosis_sections'] = $this->buildOtaDiagnosisSections($result['diagnosis'] ?? [], $result['missing_sections'] ?? []);
            $result['ai_governance'] = $this->buildAiGovernancePayload('ota_diagnosis', $result, $llmResult);
            $result = $this->finalizeOtaDiagnosisDecision($result);
            $result['decision_route'] = $this->buildOtaDiagnosisDecisionRoute($result);
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

        $requestedHotelId = (int) $this->request->param('hotel_id', 0);
        $userId = (int) ($this->currentUser->id ?? 0);
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $feasibilityService = $this->feasibilityService();
        $report = $feasibilityService->detail($id, $userId, $isSuperAdmin);
        if (!$report) {
            return $this->error('feasibility report not found', 404);
        }

        try { $hotelId = $feasibilityService->executionHotelId($report); } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), str_contains($e->getMessage(), 'conflict') ? 409 : 422);
        }
        if ($requestedHotelId > 0 && $requestedHotelId !== $hotelId) return $this->error('feasibility report hotel scope mismatch', 409);
        $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (!$permittedHotelIds || !in_array($hotelId, $permittedHotelIds, true)) return $this->error('hotel_id is not permitted', 403);
        $denied = $this->hotelCapabilityDeniedResponse($hotelId, 'operation.execute', 'operation.execute permission is required for this hotel');
        if ($denied !== null) return $denied;
        try {
            $result = Db::transaction(function () use ($feasibilityService, $report, $id, $hotelId, $permittedHotelIds, $userId, $isSuperAdmin): array {
                $operationService = new OperationManagementService();
                $input = $feasibilityService->buildExecutionIntentInput($report, $hotelId, [
                    'date_start' => (string)$this->request->param('date_start', ''), 'date_end' => (string)$this->request->param('date_end', ''),
                ]);
                $intent = $operationService->createExecutionIntent($permittedHotelIds, $hotelId, $input, $userId, false, null, true);
                $updatedReport = ($intent['idempotent_replay'] ?? false) === true
                    ? $report
                    : $feasibilityService->attachExecutionTracking($id, $userId, $isSuperAdmin, [
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
    private function revenueHighDemandDates(int $hotelId, float $threshold = 80, ?string $businessDate = null): array
    {
        $anchorDate = $businessDate ?: date('Y-m-d');
        $key = $hotelId . '|' . $threshold . '|' . $anchorDate;
        if (!array_key_exists($key, $this->revenueHighDemandDatesCache)) {
            $this->revenueHighDemandDatesCache[$key] = DemandForecast::getHighDemandDates($hotelId, $threshold, $anchorDate);
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
            'high_demand_dates' => $this->revenueHighDemandDates($hotelId, 80, $startDate),
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
        
        $saveResult = DemandForecast::saveManualForecast($data['hotel_id'], $data['forecast_date'], $data);
        /** @var DemandForecast $forecast */
        $forecast = $saveResult['forecast'];
        $writeAction = (string)$saveResult['write_action'];
        $readbackVerified = (bool)$saveResult['readback_verified'];
        if (!$readbackVerified) {
            return $this->error('人工需求预测已写入，但数据库回读校验失败，请重试', 500);
        }
        
        // 记录日志
        AgentLog::record(
            $data['hotel_id'],
            AgentLog::AGENT_TYPE_REVENUE,
            'forecast_' . $writeAction,
            ($writeAction === 'updated' ? '人工需求预测输入已修正: ' : '人工需求预测输入已保存: ') . $data['forecast_date'],
            AgentLog::LEVEL_INFO,
            [
                'forecast_id' => $forecast->id,
                'write_action' => $writeAction,
                'readback_verified' => true,
            ],
            $this->currentUser->id ?? 0
        );
        
        return $this->success([
            'id' => (int)$forecast->id,
            'write_action' => $writeAction,
            'readback_verified' => true,
            'forecast' => $forecast->toArray(),
        ], $writeAction === 'updated'
            ? '人工需求预测输入已修正并回读验证（未代表模型预测已校准）'
            : '人工需求预测输入已保存并回读验证（未代表模型预测已校准）');
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
        $legacyDate = (string) $this->request->param('date', date('Y-m-d'));
        $startDate = (string) $this->request->param('start_date', $legacyDate);
        $endDate = (string) $this->request->param('end_date', $startDate);
        $pagination = $this->getPagination();
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        try {
            [$startDate, $endDate] = $this->normalizePriceSuggestionDateRange($startDate, $endDate);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildPriceSuggestionsPayload(
            $hotelId,
            $status,
            $startDate,
            $pagination['page'],
            $pagination['page_size'],
            $endDate
        ));
    }

    /**
     * @return array{list:array<int, array<string, mixed>>, pagination:array<string, int|float>}
     */
    private function buildPriceSuggestionsPayload(
        int $hotelId,
        int $status,
        string $startDate,
        int $page,
        int $pageSize,
        ?string $endDate = null
    ): array {
        $endDate = $endDate ?: $startDate;
        $query = PriceSuggestion::where('hotel_id', $hotelId);
        if ($startDate === $endDate) {
            $query->where('suggestion_date', $startDate);
        } else {
            $query->whereBetween('suggestion_date', [$startDate, $endDate]);
        }
        
        if ($status > 0) {
            $query->where('status', $status);
        }
        
        $total = $query->count();
        $list = $query->with('roomType')
            ->order('suggestion_date', 'asc')
            ->order('room_type_id', 'asc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $pricingService = new RevenuePricingRecommendationService();
        $list = $pricingService->enrichSuggestionRows(
            $list,
            $this->priceSuggestionExecutionItemsByRecordId($hotelId, array_column($list, 'id'))
        );
        $list = $this->markPersistedPriceSuggestionRows($list);
        
        return [
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => (int)ceil($total / $pageSize),
            ],
            'query_scope' => [
                'hotel_id' => $hotelId,
                'platform' => 'ctrip',
                'metric_scope' => 'ota_channel_price_recommendation',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'persistence' => [
                'storage' => 'price_suggestions',
                'rows_on_page' => count($list),
                'readback_verified_count' => count(array_filter(
                    $list,
                    static fn(array $row): bool => ($row['persistence']['readback_verified'] ?? false) === true
                )),
            ],
            'advisory_only' => true,
            'manual_review_required' => true,
            'auto_write_ota' => false,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizePriceSuggestionDateRange(string $startDate, string $endDate): array
    {
        $startDate = trim($startDate);
        $endDate = trim($endDate);
        if (!$this->isDateString($startDate) || !$this->isDateString($endDate)) {
            throw new \InvalidArgumentException('start_date and end_date must be YYYY-MM-DD');
        }
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start_date must not be after end_date');
        }

        $dayCount = count($this->priceSuggestionDateRange($startDate, $endDate));
        if ($dayCount > 31) {
            throw new \InvalidArgumentException('price suggestion date range must not exceed 31 days');
        }

        return [$startDate, $endDate];
    }

    /**
     * @return array<int, string>
     */
    private function priceSuggestionDateRange(string $startDate, string $endDate): array
    {
        $dates = [];
        $cursor = new \DateTimeImmutable($startDate);
        $last = new \DateTimeImmutable($endDate);
        while ($cursor <= $last) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function markPersistedPriceSuggestionRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $targetDate = (string)($row['suggestion_date'] ?? '');
            $id = (int)($row['id'] ?? 0);
            $loadedFromStorage = $id > 0;
            $exactIdentityComplete = $loadedFromStorage
                && (int)($row['tenant_id'] ?? 0) > 0
                && (int)($row['hotel_id'] ?? 0) > 0
                && (int)($row['room_type_id'] ?? 0) > 0
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1
                && (float)($row['current_price'] ?? 0) > 0
                && (float)($row['suggested_price'] ?? 0) > 0
                && (int)($row['status'] ?? 0) > 0;
            $row['target_stay_date'] = $targetDate;
            $row['persistence'] = [
                'saved' => $loadedFromStorage,
                'storage' => 'price_suggestions',
                'loaded_from_storage' => $loadedFromStorage,
                'exact_identity_complete' => $exactIdentityComplete,
                'readback_verified' => $exactIdentityComplete,
            ];
            $row['advisory_only'] = true;
            $row['manual_review_required'] = true;
            $row['auto_write_ota'] = false;

            return $row;
        }, $rows);
    }

    private function priceSuggestionGenerationLockName(int $hotelId): string
    {
        $connection = Db::connect();
        $databaseIdentity = implode(':', [
            strtolower((string)$connection->getConfig('type')),
            (string)$connection->getConfig('hostname'),
            (string)$connection->getConfig('hostport'),
            (string)$connection->getConfig('database'),
        ]);
        return 'suxios_price_gen_' . substr(hash('sha256', $databaseIdentity . ':hotel:' . $hotelId), 0, 40);
    }

    /**
     * @return array{acquired:bool,driver:string,name:string,handle:mixed,reason:string}
     */
    private function acquirePriceSuggestionGenerationLock(int $hotelId): array
    {
        $name = $this->priceSuggestionGenerationLockName($hotelId);
        $driver = strtolower((string)Db::connect()->getConfig('type'));
        if ($driver === 'mysql') {
            try {
                $rows = Db::query("SELECT GET_LOCK('{$name}', 0) AS acquired");
                $acquired = (int)(array_values((array)($rows[0] ?? []))[0] ?? 0) === 1;
                return [
                    'acquired' => $acquired,
                    'driver' => $driver,
                    'name' => $name,
                    'handle' => null,
                    'reason' => $acquired ? '' : 'price_suggestion_generation_in_progress',
                ];
            } catch (\Throwable) {
                return [
                    'acquired' => false,
                    'driver' => $driver,
                    'name' => $name,
                    'handle' => null,
                    'reason' => 'price_suggestion_generation_lock_unavailable',
                ];
            }
        }

        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $name
            . '.lock';
        $handle = @fopen($path, 'c+');
        $handleOpened = is_resource($handle);
        $acquired = $handleOpened && @flock($handle, LOCK_EX | LOCK_NB);
        if (!$acquired && is_resource($handle)) {
            fclose($handle);
            $handle = null;
        }

        return [
            'acquired' => $acquired,
            'driver' => $driver,
            'name' => $name,
            'handle' => $handle,
            'reason' => $acquired ? '' : ($handleOpened
                ? 'price_suggestion_generation_in_progress'
                : 'price_suggestion_generation_lock_unavailable'),
        ];
    }

    /** @param array{driver?:string,name?:string,handle?:mixed} $lock */
    private function releasePriceSuggestionGenerationLock(array $lock): void
    {
        if (($lock['driver'] ?? '') === 'mysql') {
            try {
                $name = (string)($lock['name'] ?? '');
                if ($name !== '') {
                    Db::query("SELECT RELEASE_LOCK('{$name}') AS released");
                }
            } catch (\Throwable) {
                // The connection teardown also releases a MySQL advisory lock.
            }
            return;
        }

        $handle = $lock['handle'] ?? null;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
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
        $id = (int) $this->request->param('id', 0);

        // Keep direct/internal legacy callers safe as well as the route alias.
        // RevenueAi owns the trusted-input, permission, CAS and manual-review
        // persistence contract; this method must never mutate status directly.
        return (new RevenueAi($this->app))->reviewPriceSuggestion($id);
    }

    public function generatePriceSuggestions(): Response
    {
        $hotelId = (int)$this->request->param('hotel_id', 0);
        $legacyDate = (string)$this->request->param('date', date('Y-m-d'));
        $startDate = (string)$this->request->param('start_date', $legacyDate);
        $endDate = (string)$this->request->param('end_date', $startDate);
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        try {
            [$startDate, $endDate] = $this->normalizePriceSuggestionDateRange($startDate, $endDate);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        $roomTypes = RoomType::getHotelRoomTypes($hotelId);
        $pricingService = new RevenuePricingRecommendationService();
        if (count($roomTypes) === 0) {
            return $this->success(
                $this->buildPriceSuggestionGenerationBlockedResult(
                    'room_types_empty',
                    $hotelId,
                    $startDate,
                    [],
                    '携程目标酒店暂无启用房型，不能生成待审调价建议。',
                    $endDate
                ),
                'price suggestion generation blocked'
            );
        }
        $dates = $this->priceSuggestionDateRange($startDate, $endDate);
        $generationLock = $this->acquirePriceSuggestionGenerationLock($hotelId);
        if (($generationLock['acquired'] ?? false) !== true) {
            $reason = (string)($generationLock['reason'] ?? 'price_suggestion_generation_lock_unavailable');
            return $this->success(
                $this->buildPriceSuggestionGenerationBlockedResult(
                    $reason,
                    $hotelId,
                    $startDate,
                    [],
                    '',
                    $endDate
                ),
                'price suggestion generation blocked'
            );
        }

        try {
            [$created, $skipped] = $pricingService->generatePendingBatch(
                $hotelId,
                $roomTypes->toArray(),
                $dates
            );
        } finally {
            $this->releasePriceSuggestionGenerationLock($generationLock);
        }

        return $this->success(
            $this->buildPriceSuggestionGenerationRuntimeResult(
                $hotelId,
                $startDate,
                $created,
                $skipped,
                $pricingService->hotelPricingModelSummary($hotelId, $startDate),
                $endDate
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
        array $modelSummary,
        ?string $endDate = null
    ): array {
        $endDate = $endDate ?: $date;
        $status = count($created) > 0
            ? (count($skipped) > 0 ? 'partial' : 'created')
            : 'blocked';
        $reason = count($created) > 0
            ? 'price_suggestions_pending_review'
            : (string)($skipped[0]['reason'] ?? 'pricing_candidate_signals_missing');
        $requiredInputs = count($created) > 0
            ? []
            : $this->buildPriceSuggestionGenerationRequiredInputs($reason);
        $nextAction = count($created) > 0
            ? '进入待审建议列表完成人工审核；本接口只创建待审建议，不写入携程 OTA 价格。'
            : $this->priceSuggestionGenerationNextAction($reason);

        $targetFilter = [
            'hotel_id' => $hotelId,
            'date' => $date,
            'status' => count($created) > 0 ? PriceSuggestion::STATUS_PENDING : 0,
        ];
        if ($endDate !== $date) {
            $targetFilter['start_date'] = $date;
            $targetFilter['end_date'] = $endDate;
        }
        $createdRowIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $created
        ), static fn(int $id): bool => $id > 0));
        $readbackVerifiedCount = count(array_filter(
            $created,
            static fn(array $row): bool => ($row['persistence']['readback_verified'] ?? false) === true
        ));

        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => $this->priceSuggestionGenerationReasonText($reason),
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'target_hotel_ids' => [$hotelId],
            'target_filter' => $targetFilter,
            'date_range' => [
                'start_date' => $date,
                'end_date' => $endDate,
                'day_count' => count($this->priceSuggestionDateRange($date, $endDate)),
            ],
            'reviewed_count' => count($created) + count($skipped),
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'created_row_ids' => $createdRowIds,
            'readback_verified_count' => $readbackVerifiedCount,
            'readback_verified' => count($created) > 0 && $readbackVerifiedCount === count($created),
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
        string $detail = '',
        ?string $endDate = null
    ): array {
        $endDate = $endDate ?: $date;
        $targetFilter = [
            'hotel_id' => $hotelId,
            'date' => $date,
            'status' => 0,
        ];
        if ($endDate !== $date) {
            $targetFilter['start_date'] = $date;
            $targetFilter['end_date'] = $endDate;
        }

        return [
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail !== '' ? $detail : $this->priceSuggestionGenerationReasonText($reason),
            'source_scope' => 'ctrip_ota_channel',
            'source_channels' => ['ctrip'],
            'target_hotel_ids' => [$hotelId],
            'target_filter' => $targetFilter,
            'date_range' => [
                'start_date' => $date,
                'end_date' => $endDate,
                'day_count' => count($this->priceSuggestionDateRange($date, $endDate)),
            ],
            'reviewed_count' => count($skipped),
            'created_count' => 0,
            'skipped_count' => count($skipped),
            'created_row_ids' => [],
            'readback_verified_count' => 0,
            'readback_verified' => false,
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

        if (in_array($reason, [
            'price_suggestion_generation_in_progress',
            'price_suggestion_generation_lock_unavailable',
        ], true)) {
            return [[
                'code' => 'price_suggestion_generation_lock',
                'status' => $reason === 'price_suggestion_generation_in_progress' ? 'in_progress' : 'unavailable',
                'source' => 'price_suggestion_generation_lock',
                'required_before' => 'POST /api/agent/price-suggestions/generate',
                'next_action' => $this->priceSuggestionGenerationNextAction($reason),
            ]];
        }

        return $inputs;
    }

    private function priceSuggestionGenerationReasonText(string $reason): string
    {
        return match ($reason) {
            'room_types_empty' => '携程目标酒店暂无启用房型，不能生成待审调价建议。',
            'pending_suggestion_exists' => '已存在待审调价建议，不能重复生成。',
            'exact_target_signals_missing' => '目标入住日、目标房型的需求预测或携程竞品价格证据不完整，不使用旧日或酒店级样本补齐。',
            'price_suggestion_generation_in_progress' => '同一酒店已有远期定价生成任务正在执行，本次未重复写入。',
            'price_suggestion_generation_lock_unavailable' => '远期定价生成互斥锁不可用，本次已安全阻断写入。',
            'pricing_candidate_signals_missing' => '调价候选信号不足，当前不会生成待审建议。',
            default => '定价建议生成前置条件未满足。',
        };
    }

    private function priceSuggestionGenerationNextAction(string $reason): string
    {
        return match ($reason) {
            'room_types_empty' => '为携程目标酒店配置启用房型和最低保护价，再补需求预测与竞对样本；缺口未补齐前不生成待审建议。',
            'pending_suggestion_exists' => '进入收益 Agent 的定价建议列表完成已有待审建议审核；Revenue AI 不自动写 OTA。',
            'exact_target_signals_missing' => '逐日补齐同酒店、同房型、同入住日的需求预测与携程竞品价格样本，再重新生成；旧日或酒店级样本只作参考。',
            'price_suggestion_generation_in_progress' => '等待当前生成请求结束后刷新台账；不要重复提交。',
            'price_suggestion_generation_lock_unavailable' => '检查数据库或本地锁目录可用性后重试；锁恢复前不生成待审建议。',
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
        $this->checkLogin();
        $id = (int)$this->request->param('id', 0);
        $suggestion = PriceSuggestion::find($id);
        if (!$suggestion) {
            return $this->error('price suggestion not found', 404);
        }
        $this->assertRevenueHotelPermission((int)$suggestion->hotel_id);

        $anchorDate = $suggestion->applied_time ? date('Y-m-d', strtotime((string)$suggestion->applied_time)) : (string)$suggestion->suggestion_date;
        $beforeStart = date('Y-m-d', strtotime($anchorDate . ' -7 days'));
        $beforeEnd = date('Y-m-d', strtotime($anchorDate . ' -1 day'));
        $afterStart = $anchorDate;
        $afterEnd = date('Y-m-d', strtotime($anchorDate . ' +6 days'));
        $pricingService = new RevenuePricingRecommendationService();
        $before = $pricingService->aggregateSuggestionEffect((int)$suggestion->hotel_id, $beforeStart, $beforeEnd);
        $after = $pricingService->aggregateSuggestionEffect((int)$suggestion->hotel_id, $afterStart, $afterEnd);
        $delta = $pricingService->suggestionEffectDelta($before, $after);

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

    public function createPriceSuggestionShadowReplay(): Response
    {
        $this->checkLogin();
        $id = (int)$this->request->param('id', 0);
        $suggestion = $id > 0 ? PriceSuggestion::find($id) : null;
        if (!$suggestion instanceof PriceSuggestion) {
            return $this->error('price suggestion not found', 404);
        }
        $hotelId = (int)$suggestion->hotel_id;
        $this->assertRevenueHotelPermission($hotelId);

        try {
            $result = (new PriceSuggestionShadowReplayService())->createFromSuggestion(
                $id,
                $hotelId,
                (int)($this->currentUser->id ?? 0)
            );
            return $this->success($result, '历史调价影子回放已保存并精确回读');
        } catch (\InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        } catch (\Throwable $error) {
            $code = (int)$error->getCode();
            return $this->error(
                $error->getMessage() !== '' ? $error->getMessage() : 'price suggestion shadow replay failed',
                in_array($code, [403, 404, 409, 422, 503], true) ? $code : 500
            );
        }
    }

    public function priceSuggestionShadowReplays(): Response
    {
        $this->checkLogin();
        $id = (int)$this->request->param('id', 0);
        $suggestion = $id > 0 ? PriceSuggestion::find($id) : null;
        if (!$suggestion instanceof PriceSuggestion) {
            return $this->error('price suggestion not found', 404);
        }
        $hotelId = (int)$suggestion->hotel_id;
        $tenantId = (int)$suggestion->tenant_id;
        $this->assertRevenueHotelPermission($hotelId);

        try {
            return $this->success((new PriceSuggestionShadowReplayService())->listForSuggestion(
                $tenantId,
                $hotelId,
                $id,
                (int)$this->request->param('limit', 20)
            ));
        } catch (\InvalidArgumentException $error) {
            return $this->error($error->getMessage(), 422);
        } catch (\Throwable $error) {
            $code = (int)$error->getCode();
            return $this->error(
                $error->getMessage() !== '' ? $error->getMessage() : 'price suggestion shadow replay read failed',
                in_array($code, [403, 404, 409, 422, 503], true) ? $code : 500
            );
        }
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
        $businessDate = (string)$this->request->param('business_date', $this->defaultRevenueBusinessDate());
        $priceDate = (string)$this->request->param('date', $businessDate);
        $priceStartDate = (string)$this->request->param('price_start_date', $priceDate);
        $priceEndDate = (string)$this->request->param('price_end_date', $priceStartDate);
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
            'price_start_date' => $priceStartDate,
            'price_end_date' => $priceEndDate,
            'competitor_date' => $competitorDate,
        ] as $field => $date) {
            if (!$this->isDateString($date)) {
                return $this->error($field . ' must be YYYY-MM-DD', 422);
            }
        }
        if ($startDate > $endDate) {
            return $this->error('start_date must not be after end_date', 422);
        }
        try {
            [$priceStartDate, $priceEndDate] = $this->normalizePriceSuggestionDateRange(
                $priceStartDate,
                $priceEndDate
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
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
        $overview = (new RevenueAiOverviewService())->overview($overviewFilters);
        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];

        return $this->success([
            'overview' => $overview,
            'analysis' => $this->buildRevenueAnalysisPayload(
                $hotelId,
                $startDate,
                $endDate,
                $businessDate,
                $factLayer
            ),
            'dashboard' => $this->buildRevenueDashboardPayload($hotelId, $businessDate),
            'forecasts' => $this->buildDemandForecastsPayload($hotelId, $startDate, $endDate),
            'competitor' => $this->buildCompetitorAnalysisPayload($hotelId, $competitorDate),
            'room_types' => $this->buildRoomTypesPayload($hotelId),
            'price_suggestions' => $this->buildPriceSuggestionsPayload(
                $hotelId,
                $status,
                $priceStartDate,
                $pagination['page'],
                $pagination['page_size'],
                $priceEndDate
            ),
            'query_scope' => [
                'hotel_id' => $hotelId,
                'metric_scope' => 'three_source_layered',
                'source_scopes' => [
                    'dingdandao_pms' => 'whole_hotel_accommodation',
                    'ctrip' => 'ota_channel',
                    'meituan' => 'ota_channel',
                ],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'business_date' => $businessDate,
                'price_date' => $priceDate,
                'price_start_date' => $priceStartDate,
                'price_end_date' => $priceEndDate,
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
        $businessDate = (string)$this->request->param('business_date', $this->defaultRevenueBusinessDate());
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($startDate)
            || !$this->isDateString($endDate)
            || !$this->isDateString($businessDate)
            || $startDate > $endDate
        ) {
            return $this->error('start_date, end_date and business_date must be valid dates', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);
        
        return $this->success(
            $this->buildRevenueAnalysisPayload(
                $hotelId,
                $startDate,
                $endDate,
                $businessDate
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevenueAnalysisPayload(
        int $hotelId,
        string $startDate,
        string $endDate,
        string $businessDate,
        array $factLayer = []
    ): array
    {
        if ($factLayer === []) {
            $factLayer = (new \app\service\RevenueFactLayerService())
                ->build($hotelId, $businessDate);
        }
        // 获取建议统计
        $stats = PriceSuggestion::getStatistics($hotelId, $startDate, $endDate);
        
        // 获取房型列表
        $roomTypes = RoomType::getHotelRoomTypes($hotelId)->toArray();
        
        // 获取需求预测统计
        $forecastStats = $this->revenueForecastAccuracy($hotelId, 30);
        $highDemandDates = $this->revenueHighDemandDates($hotelId, 80, $businessDate);
        
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
        $pricingStrategies = $this->generatePricingStrategies($hotelId, $highDemandDates, $businessDate);
        
        return [
            'revenue_analysis_status' => (string)(
                $factLayer['revenue_analysis_status']
                ?? 'blocked'
            ),
            'fact_layer' => $factLayer,
            'statistics' => $stats,
            'room_types' => $roomTypes,
            'forecast_accuracy' => $forecastStats,
            'revpar_trend' => $revparTrend,
            'high_demand_dates' => $highDemandDates,
            'pricing_strategies' => $pricingStrategies,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'business_date' => $businessDate,
        ];
    }

    /**
     * 生成定价策略建议
     */
    private function generatePricingStrategies(int $hotelId, array $highDemandDates, string $businessDate): array
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
            ->where('analysis_date', $businessDate)
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
                'description' => $businessDate . ' 竞对分析记录中，我方价格高于竞对的记录较多；价差本身不能证明客源流失。',
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
        $businessDate = (string)$this->request->param('business_date', $this->defaultRevenueBusinessDate());
        if ($hotelId <= 0) {
            return $this->error('hotel_id is required', 422);
        }
        if (!$this->isDateString($businessDate)) {
            return $this->error('business_date must be YYYY-MM-DD', 422);
        }
        $this->assertRevenueHotelPermission($hotelId);

        return $this->success($this->buildRevenueDashboardPayload($hotelId, $businessDate));
    }

    private function defaultRevenueBusinessDate(): string
    {
        return date('Y-m-d', strtotime('-1 day'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRevenueDashboardPayload(int $hotelId, string $businessDate): array
    {
        // Business-date anchored pricing suggestions.
        $todaySuggestions = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('suggestion_date', $businessDate)
            ->with('roomType')
            ->select()
            ->toArray();
        
        $pendingCount = PriceSuggestion::where('hotel_id', $hotelId)
            ->where('status', PriceSuggestion::STATUS_PENDING)
            ->count();
        
        // 预测准确率
        $forecastAccuracy = $this->revenueForecastAccuracy($hotelId, 30);
        $pricingModelSummary = (new RevenuePricingRecommendationService())->hotelPricingModelSummary($hotelId, $businessDate);
        
        // 竞对监控概览
        $competitorAlerts = CompetitorAnalysis::getAlertCompetitors($hotelId, 15, $businessDate);
        
        // 本周RevPAR预测
        $weekForecasts = $this->revenueForecastRange(
            $hotelId,
            $businessDate,
            date('Y-m-d', strtotime($businessDate . ' +7 days'))
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
            'business_date' => $businessDate,
            'today_suggestions' => $todaySuggestions,
            'pending_count' => $pendingCount,
            'forecast_accuracy' => $forecastAccuracy,
            'competitor_alerts' => $competitorAlerts,
            'week_revpar_forecast' => $avgPredictedRevpar,
            'week_revpar_forecast_status' => $avgPredictedRevpar === null ? 'insufficient_data' : 'available',
            'high_demand_count' => count($this->revenueHighDemandDates($hotelId, 80, $businessDate)),
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
