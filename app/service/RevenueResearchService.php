<?php
declare(strict_types=1);

namespace app\service;

use app\model\AiModelConfig;
use app\model\User;
use RuntimeException;
use think\facade\Db;

class RevenueResearchService
{
    /**
     * @return array<string, mixed>
     */
    public function run(string $productKey, string $modelKey, ?User $user = null, ?int $hotelId = null): array
    {
        $product = $this->product($productKey);
        $modelKey = trim($modelKey) !== '' ? trim($modelKey) : 'deepseek_chat';
        $permittedHotelIds = $user ? array_map('intval', $user->getPermittedHotelIds()) : [];
        $hotelIds = $permittedHotelIds;
        if ($hotelId !== null && $hotelId > 0) {
            if ($permittedHotelIds && !in_array($hotelId, $permittedHotelIds, true)) {
                throw new RuntimeException('当前账号没有该酒店的预测权限。', 403);
            }
            $hotelIds = [$hotelId];
        }
        $localSources = $this->collectLocalSources($product, $hotelIds);
        $businessForecast = $this->buildBusinessForecast($hotelIds);
        $gaps = $this->evaluateGaps($product, $localSources);
        $knowledgeContext = (new RevenueOperationsKnowledgeService())->load([
            'hotel_id' => count($hotelIds) === 1 ? (int)$hotelIds[0] : 0,
            'limit' => 30,
        ]);
        $webResult = $modelKey === 'openai_fast'
            ? $this->callOpenAiWebSearch($this->resolveOpenAiConfig($modelKey), $product, $localSources, $gaps, $businessForecast, $knowledgeContext)
            : $this->callConfiguredModel($modelKey, $product, $localSources, $gaps, $businessForecast, $knowledgeContext);
        $forecastDecisionReady = ($businessForecast['decision_ready'] ?? false) === true;
        $status = empty($gaps) && $forecastDecisionReady ? 'done' : 'pending_data';
        $result = $this->normalizeAiResult(
            $webResult['result'],
            $product,
            $gaps,
            $businessForecast,
            $localSources,
            count($hotelIds) === 1 ? (int)$hotelIds[0] : 0
        );
        $readiness = $this->buildResearchReadiness($product, $status, $gaps, $businessForecast, $result);
        $researchDataGaps = array_values(array_unique(array_merge(
            $this->stringList($businessForecast['data_gaps'] ?? []),
            array_map(
                static fn(array $gap): string => 'source_gap_' . (string)($gap['table'] ?? 'unknown'),
                $gaps
            )
        )));

        $research = [
            'status' => $status,
            'decision_ready' => $status === 'done',
            'data_gaps' => $researchDataGaps,
            'product_key' => $product['key'],
            'local_sources' => $localSources,
            'web_sources' => $webResult['web_sources'],
            'business_forecast' => $businessForecast,
            'knowledge_context' => $knowledgeContext,
            'result' => $result,
            'readiness' => $readiness,
            'gaps' => $gaps,
            'hotel_scope' => [
                'mode' => $hotelId !== null && $hotelId > 0 ? 'single_hotel' : 'all_permitted_hotels',
                'hotel_id' => $hotelId,
                'hotel_ids' => $hotelIds,
            ],
            'model_key' => $modelKey,
            'generation_mode' => $webResult['generation_mode'] ?? 'configured_model',
        ];
        $research['execution_artifact'] = $this->issueExecutionArtifact($research, $user, $hotelId);
        return $research;
    }

    /**
     * @param array<string, mixed> $research
     * @return array<string, mixed>
     */
    private function issueExecutionArtifact(array $research, ?User $user, ?int $hotelId): array
    {
        $readiness = is_array($research['readiness'] ?? null) ? $research['readiness'] : [];
        if (($readiness['execution_ready'] ?? false) !== true) {
            return ['status' => 'not_issued', 'reason' => 'research_not_execution_ready'];
        }

        $actorId = (int)($user?->id ?? 0);
        $boundHotelId = (int)($hotelId ?? 0);
        if ($actorId <= 0) {
            return ['status' => 'not_issued', 'reason' => 'authenticated_user_required'];
        }
        if ($boundHotelId <= 0) {
            return ['status' => 'not_issued', 'reason' => 'single_hotel_scope_required'];
        }

        try {
            return (new RevenueResearchExecutionArtifactService())->issue($research, $actorId, $boundHotelId);
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'reason' => 'artifact_persistence_or_readback_failed'];
        }
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function buildResearchReadiness(array $product, string $status, array $gaps, array $businessForecast, array $result): array
    {
        $module = trim((string)($product['module'] ?? ($result['module'] ?? '')));
        $moduleConnected = $module !== '' && !str_starts_with($module, '待新增');
        $decisionRecommendations = (array)($result['decision_recommendations'] ?? []);
        if ($decisionRecommendations === [] && !empty($result['recommended_actions'])) {
            $forecastReady = ($businessForecast['decision_ready'] ?? $businessForecast['available'] ?? false) === true
                && $gaps === [];
            $decisionRecommendations = (new AiDecisionQualityService())->enrichRecommendations(
                $result['recommended_actions'],
                $this->revenueResearchDecisionQualityContext($product, [], $businessForecast, 0, $forecastReady)
            );
        }
        $qualityActions = array_values(array_filter(
            $decisionRecommendations,
            static fn(mixed $item): bool => is_array($item)
                && ($item['can_create_execution_intent'] ?? false) === true
                && (($item['decision_quality']['execution_ready'] ?? false) === true)
        ));
        $hasActions = $qualityActions !== [];
        $modelNumericClaimUnverified = in_array(
            'model_numeric_claim_unverified',
            $this->stringList($result['data_gaps'] ?? []),
            true
        );

        if (($businessForecast['decision_ready'] ?? $businessForecast['available'] ?? false) !== true) {
            return $this->withReadinessNotice($this->readiness(
                'research_forecast_missing',
                '缺经营基线',
                20,
                false,
                false,
                '补齐可用于预测的日级经营数据后再生成研究结论',
                [
                    $this->readinessMissing('business_forecast', '经营预测基线', (string)($businessForecast['message'] ?? '补齐日级收入、间夜和订单样本')),
                ],
                $module,
                $moduleConnected
            ));
        }

        if (!empty($gaps)) {
            $missing = array_map(function (array $gap): array {
                return $this->readinessMissing(
                    'data_gap_' . (string)($gap['table'] ?? 'source'),
                    (string)($gap['label'] ?? $gap['table'] ?? '数据缺口'),
                    trim((string)($gap['collect_from'] ?? '补齐该方向要求的数据')) !== ''
                        ? (string)$gap['collect_from']
                        : (string)($gap['reason'] ?? '补齐该方向要求的数据')
                );
            }, array_slice($gaps, 0, 4));

            return $this->withReadinessNotice($this->readiness(
                'research_data_gaps_pending',
                '需补关键数据',
                40,
                false,
                false,
                '先补齐关键样本、字段或表，再把研究结论用于运营动作',
                $missing,
                $module,
                $moduleConnected
            ));
        }

        if ($modelNumericClaimUnverified) {
            return $this->withReadinessNotice($this->readiness(
                'research_model_numeric_claim_unverified',
                '模型数字待核验',
                45,
                false,
                false,
                '用已验证的结构化本地数据核对模型数字后，再进入运营执行',
                [
                    $this->readinessMissing(
                        'model_numeric_claim_unverified',
                        '模型数字证据',
                        '删除无结构化证据的数字，或补齐对应门店、平台、日期、来源与入库回读证据'
                    ),
                ],
                $module,
                $moduleConnected
            ));
        }

        if (!$moduleConnected) {
            return $this->withReadinessNotice($this->readiness(
                'research_module_bridge_missing',
                '模块未接入',
                60,
                false,
                false,
                '先新增对应数据表、列表或执行接口，再形成系统内闭环',
                [
                    $this->readinessMissing('module_bridge', '系统落点', $module !== '' ? $module : '补充目标模块和执行接口'),
                ],
                $module,
                $moduleConnected
            ));
        }

        if (!$hasActions) {
            $hasRecommendations = $decisionRecommendations !== [];
            return $this->withReadinessNotice($this->readiness(
                $hasRecommendations ? 'research_action_quality_pending' : 'research_actions_missing',
                $hasRecommendations ? '动作证据待核验' : '缺执行动作',
                55,
                false,
                false,
                $hasRecommendations
                    ? '补齐建议所引用数据的酒店、平台、日期、持久化与回读证据，并确认预期效果来源后重新生成'
                    : '让模型输出可执行运营动作，或人工补充动作后再进入模块',
                [
                    $this->readinessMissing(
                        $hasRecommendations ? 'recommendation_quality' : 'recommended_actions',
                        $hasRecommendations ? '建议证据与效果来源' : '建议动作',
                        $hasRecommendations ? '完成同酒店、同平台、同日期数据回读核验' : '补充可执行动作、负责人或复核口径'
                    ),
                ],
                $module,
                $moduleConnected
            ));
        }

        return $this->withReadinessNotice($this->readiness(
            'research_ready_for_execution',
            '可转执行',
            $status === 'done' ? 80 : 65,
            false,
            true,
            '进入对应模块创建定价、预警或运营执行记录，并保留复盘证据',
            [
                $this->readinessMissing('execution_record', '执行记录', '进入对应模块创建执行记录并跟踪结果'),
            ],
            $module,
            $moduleConnected
        ));
    }

    /**
     * @param array<string, mixed> $research
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildExecutionIntentInput(array $research, array $overrides = []): array
    {
        $productKey = mb_substr(trim((string)($research['product_key'] ?? $overrides['product_key'] ?? '')), 0, 120);
        if ($productKey === '') {
            throw new RuntimeException('product_key is required for revenue research execution intent', 422);
        }

        $result = is_array($research['result'] ?? null) ? $research['result'] : [];
        $readiness = is_array($research['readiness'] ?? null) ? $research['readiness'] : [];
        $gaps = array_values(array_filter((array)($research['gaps'] ?? []), 'is_array'));
        $hotelScope = is_array($research['hotel_scope'] ?? null) ? $research['hotel_scope'] : [];
        $hotelId = (int)($overrides['hotel_id'] ?? $hotelScope['hotel_id'] ?? 0);
        $sourceRecordId = (int)($overrides['source_record_id'] ?? 0);
        if ($sourceRecordId <= 0) {
            $unsignedCrc = (int)sprintf('%u', crc32($productKey . '|' . $hotelId . '|' . (string)($result['next_review_date'] ?? '')));
            $sourceRecordId = $unsignedCrc % 2147483647;
            if ($sourceRecordId <= 0) {
                $sourceRecordId = 1;
            }
        }

        $recommendedActions = $this->stringList($result['recommended_actions'] ?? []);
        $decisionRecommendations = array_values(array_filter(
            (array)($result['decision_recommendations'] ?? []),
            static fn(mixed $item): bool => is_array($item)
        ));
        $firstExecutable = null;
        foreach ($decisionRecommendations as $recommendation) {
            if (($recommendation['can_create_execution_intent'] ?? false) === true
                && (($recommendation['decision_quality']['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION)
                && (($recommendation['decision_quality']['execution_ready'] ?? false) === true)) {
                $firstExecutable = $recommendation;
                break;
            }
        }
        $actionText = trim((string)($firstExecutable['action'] ?? ''));
        if ($actionText === '') {
            $actionText = '复核收益研究结论并创建运营动作';
        }

        $dataGaps = $this->executionDataGapCodes($gaps, $result);
        $readinessStage = trim((string)($readiness['stage'] ?? ''));
        $executionReady = (bool)($readiness['execution_ready'] ?? false) && $firstExecutable !== null;
        $executionDates = $this->executionIntentDates($overrides);
        $platform = strtolower(trim((string)($overrides['platform'] ?? 'ota')));
        if ($platform === '') {
            $platform = 'ota';
        }

        return [
            'source_module' => 'revenue_research',
            'source_record_id' => $sourceRecordId,
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'object_type' => 'revenue_research',
            'action_type' => $productKey,
            'date_start' => $executionDates['date_start'],
            'date_end' => $executionDates['date_end'],
            'current_value' => [
                'research_status' => (string)($research['status'] ?? ''),
                'readiness_stage' => $readinessStage,
                'generation_mode' => (string)($research['generation_mode'] ?? ''),
            ],
            'target_value' => [
                'research_product' => $productKey,
                'action_text' => $actionText,
                'tracking_status' => $executionReady ? 'pending_revenue_research_execution' : 'blocked_by_research_data_gaps',
                'target_metric' => 'revenue_research_closure',
                'target_module' => (string)($readiness['target_module'] ?? $result['module'] ?? ''),
                'next_review_date' => (string)($result['next_review_date'] ?? ''),
                'recommended_actions' => $recommendedActions,
                'decision_recommendation' => $firstExecutable,
            ],
            'evidence' => [
                'evidence_refs' => [
                    'revenue_research#' . $productKey . '#' . $sourceRecordId,
                    '/api/revenue-research/run',
                ],
                'data_gaps' => $dataGaps,
                'source_policy' => 'revenue_research_output_to_operation_execution_intent',
                'protected_boundary' => 'Execution intent records manual review of revenue research output; it does not write OTA prices, inventory, campaigns, or platform data.',
                'research_readiness_stage' => $readinessStage,
                'execution_ready' => $executionReady,
                'recommendation_quality' => is_array($result['recommendation_quality'] ?? null)
                    ? $result['recommendation_quality']
                    : [],
                'decision_recommendation' => $firstExecutable,
                'metric_scope' => 'ota_channel',
                'model_key' => (string)($research['model_key'] ?? ''),
                'generation_mode' => (string)($research['generation_mode'] ?? ''),
                'summary' => mb_substr(trim((string)($result['summary'] ?? '')), 0, 500),
            ],
            'expected_metric' => 'revenue_research_closure',
            'expected_delta' => 0,
            'risk_level' => $dataGaps === [] ? 'medium' : 'high',
            'status' => 'pending_approval',
        ];
    }

    /**
     * @param array<string, mixed> $research
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function buildReadyExecutionIntentInput(array $research, array $overrides = []): array
    {
        $this->assertResearchReadyForExecution($research);

        return $this->buildExecutionIntentInput($research, $overrides);
    }

    /**
     * @param array<string, mixed> $intentInput
     * @param array<int, array<string, mixed>> $existingRows
     */
    public function assertNoDuplicateExecutionIntent(array $intentInput, array $existingRows): void
    {
        $sourceModule = trim((string)($intentInput['source_module'] ?? ''));
        $sourceRecordId = (int)($intentInput['source_record_id'] ?? 0);
        $hotelId = (int)($intentInput['hotel_id'] ?? 0);
        if ($sourceModule === '' || $sourceRecordId <= 0 || $hotelId <= 0) {
            return;
        }

        foreach ($existingRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string)($row['deleted_at'] ?? '')) !== '') {
                continue;
            }
            if (
                trim((string)($row['source_module'] ?? '')) !== $sourceModule
                || (int)($row['source_record_id'] ?? 0) !== $sourceRecordId
                || (int)($row['hotel_id'] ?? 0) !== $hotelId
            ) {
                continue;
            }

            $existingId = (int)($row['id'] ?? 0);
            $suffix = $existingId > 0 ? ': ' . $existingId : '';
            throw new RuntimeException('revenue research result already linked to execution intent' . $suffix, 409);
        }
    }

    /**
     * @param array<string, mixed> $research
     */
    private function assertResearchReadyForExecution(array $research): void
    {
        $readiness = is_array($research['readiness'] ?? null) ? $research['readiness'] : [];
        $stage = trim((string)($readiness['stage'] ?? 'unknown'));
        $executionReady = (bool)($readiness['execution_ready'] ?? false);
        $researchStatus = trim((string)($research['status'] ?? ''));
        $result = is_array($research['result'] ?? null) ? $research['result'] : [];
        $gaps = array_values(array_filter((array)($research['gaps'] ?? []), 'is_array'));
        $dataGapCodes = $this->executionDataGapCodes($gaps, $result);
        $hasExecutableRecommendation = false;
        foreach ((array)($result['decision_recommendations'] ?? []) as $recommendation) {
            if (is_array($recommendation)
                && ($recommendation['can_create_execution_intent'] ?? false) === true
                && (($recommendation['decision_quality']['contract_version'] ?? '') === AiDecisionQualityService::CONTRACT_VERSION)
                && (($recommendation['decision_quality']['execution_ready'] ?? false) === true)) {
                $hasExecutableRecommendation = true;
                break;
            }
        }
        if ($executionReady && $researchStatus === 'done' && $dataGapCodes === [] && $hasExecutableRecommendation) {
            return;
        }

        $missing = array_values(array_filter((array)($readiness['missing_evidence'] ?? []), 'is_array'));
        $missingCodes = array_values(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['code'] ?? $item['label'] ?? '')),
            $missing
        )));
        if ($researchStatus !== 'done') {
            $missingCodes[] = 'research_status_' . ($researchStatus !== '' ? $researchStatus : 'missing');
        }
        foreach ($dataGapCodes as $gapCode) {
            $missingCodes[] = 'data_gap_' . $gapCode;
        }
        if (!$hasExecutableRecommendation) {
            $missingCodes[] = 'recommendation_quality_v2';
        }
        $missingCodes = array_values(array_unique($missingCodes));
        $suffix = $missingCodes === [] ? '' : '; missing=' . implode(',', array_slice($missingCodes, 0, 6));
        $blockingStage = $executionReady && $researchStatus !== 'done'
            ? ($researchStatus !== '' ? $researchStatus : $stage)
            : $stage;

        throw new RuntimeException('revenue research is not ready for execution: ' . $blockingStage . $suffix, 422);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{date_start:string, date_end:string}
     */
    private function executionIntentDates(array $overrides): array
    {
        $dateStart = trim((string)($overrides['date_start'] ?? ''));
        if ($dateStart === '') {
            $dateStart = date('Y-m-d');
        }

        $dateEnd = trim((string)($overrides['date_end'] ?? ''));
        if ($dateEnd === '') {
            $dateEnd = $dateStart;
        }

        return [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ];
    }

    /**
     * @param array<int, array<string, string>> $missingEvidence
     * @return array<string, mixed>
     */
    private function readiness(string $stage, string $label, int $score, bool $closedLoop, bool $executionReady, string $nextAction, array $missingEvidence, string $module, bool $moduleConnected): array
    {
        return [
            'stage' => $stage,
            'status_label' => $label,
            'score' => $score,
            'closed_loop' => $closedLoop,
            'execution_ready' => $executionReady,
            'next_action' => $nextAction,
            'missing_evidence' => $missingEvidence,
            'target_module' => $module,
            'module_connected' => $moduleConnected,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function readinessMissing(string $code, string $label, string $nextAction): array
    {
        return [
            'code' => $code,
            'label' => $label,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param array<string, mixed> $readiness
     * @return array<string, mixed>
     */
    private function withReadinessNotice(array $readiness): array
    {
        $missing = array_values(array_filter((array)($readiness['missing_evidence'] ?? []), 'is_array'));
        if (!$missing) {
            $readiness['notice'] = '研究结论具备进入下一步的基础证据';
            return $readiness;
        }

        $labels = array_map(static fn(array $item): string => (string)($item['label'] ?? $item['code'] ?? '未命名缺口'), $missing);
        $readiness['notice'] = '仍缺：' . implode('、', array_slice($labels, 0, 4));

        return $readiness;
    }

    /**
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $result
     * @return array<int, string>
     */
    private function executionDataGapCodes(array $gaps, array $result): array
    {
        $codes = [];
        foreach ($gaps as $gap) {
            $code = trim((string)($gap['code'] ?? $gap['table'] ?? $gap['label'] ?? $gap['reason'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        foreach ($this->stringList($result['data_gaps'] ?? []) as $gapText) {
            $codes[] = $gapText;
        }
        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $productKey): array
    {
        $products = $this->products();
        $key = trim($productKey);
        if (!isset($products[$key])) {
            throw new RuntimeException('不支持的收益研究产品方向：' . $key, 422);
        }

        return $products[$key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function products(): array
    {
        return [
            'demand-forecast' => [
                'key' => 'demand-forecast',
                'name' => '需求预测',
                'query' => 'hotel demand forecasting stay date lead time WAPE sMAPE OTA revenue management',
                'module' => '酒店AI工具箱 / 收益管理 / 收益分析',
                'task' => '基于入住日、提前期、价格、库存、取消修正和节假日，生成酒店 OTA 需求预测的数据字段、模型选择、评估指标和上线验收清单。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 180, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num'], 'label' => '连续日级订单、价格和间夜数据', 'collect_from' => '平台数据自动获取：携程/美团日级经营数据'],
                    ['table' => 'daily_reports', 'min_count' => 30, 'fields' => ['report_date', 'occupancy_rate', 'revenue', 'room_count'], 'label' => '酒店日报入住率、收入和房量校验数据', 'collect_from' => '经营日报或日报导入'],
                ],
            ],
            'cancellation-risk' => [
                'key' => 'cancellation-risk',
                'name' => '取消率预测',
                'query' => 'hotel booking cancellation prediction free cancellation recoverable room nights hazard model',
                'module' => '待新增：取消风险订单表、预警列表、净需求修正接口',
                'task' => '设计取消率预测产品：输入特征、标签定义、PR-AUC 与可回收间夜指标、预警阈值、超售控制动作，以及旧订单数据兼容方案。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['cancel_order_num', 'cancel_rate', 'free_cancel_rule', 'order_id'], 'label' => '订单级取消标签、取消规则和可回收间夜', 'collect_from' => 'OTA订单明细、取消政策、取消流水'],
                ],
            ],
            'price-elasticity' => [
                'key' => 'price-elasticity',
                'name' => '价格弹性与收益管理',
                'query' => 'hotel dynamic pricing price elasticity constrained optimization contextual bandit RevPAR',
                'module' => '酒店AI工具箱 / 收益管理 / 定价建议',
                'task' => '用酒店价格、库存、竞对价格、节假日和提前期，输出价格弹性建模方案、约束条件、调价策略和人工审批规则。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 90, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num'], 'label' => '日级价格、销量和间夜数据', 'collect_from' => '平台数据自动获取'],
                    ['table' => 'competitor_analysis', 'min_count' => 10, 'fields' => ['analysis_date', 'our_price', 'competitor_price', 'price_index'], 'label' => '竞对价格与价格指数', 'collect_from' => '竞对价格监控'],
                ],
            ],
            'channel-attribution' => [
                'key' => 'channel-attribution',
                'name' => '渠道归因与增量评估',
                'query' => 'CUPED A/B testing difference in differences hotel OTA channel attribution incrementality',
                'module' => '酒店AI工具箱 / OTA诊断',
                'task' => '为酒店 OTA 活动评估设计增量归因方案：实验分组、历史协变量、MDE、ROI、置信区间和不可随机时的 DiD/BSTS 备选。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 30, 'fields' => ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'], 'label' => '曝光、访问、提交和订单转化链路', 'collect_from' => '携程/美团流量、订单、广告数据采集'],
                ],
            ],
            'customer-segmentation' => [
                'key' => 'customer-segmentation',
                'name' => '客群细分',
                'query' => 'hotel customer segmentation RFM KMeans HDBSCAN propensity model OTA repeat customer',
                'module' => '待新增：客群特征宽表、分群结果、运营触达动作',
                'task' => '设计酒店客群细分产品：RFM 字段、聚类特征、分群稳定性、可解释标签、触达策略和用户匿名化权限边界。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['customer_id', 'guest_id', 'order_id', 'last_stay_date', 'order_amount'], 'label' => '匿名用户主键、订单频次、最近入住和客单价', 'collect_from' => '订单明细、会员系统或脱敏客史'],
                ],
            ],
            'ltv' => [
                'key' => 'ltv',
                'name' => 'LTV 预测',
                'query' => 'hotel customer lifetime value BG NBD Gamma Gamma gradient boosting survival regression',
                'module' => '待新增：用户生命周期表、LTV 预测结果、CAC 联动配置',
                'task' => '设计酒店 LTV 预测：历史 LTV 与预测 LTV 区分、订单频次/间隔/客单价特征、MAE/RMSE/Decile Lift/Calibration 验收口径。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['customer_id', 'guest_id', 'order_id', 'order_amount', 'refund_amount', 'acquisition_cost'], 'label' => '用户级订单序列、退款取消和获客成本', 'collect_from' => '订单明细、会员系统、投放成本表'],
                ],
            ],
            'anomaly-detection' => [
                'key' => 'anomaly-detection',
                'name' => '异常检测',
                'query' => 'hotel OTA anomaly detection STL ESD isolation forest conversion rate alert root cause',
                'module' => '项目AI管理 / 运营管理 / 策见·预警推送',
                'task' => '设计酒店 OTA 异常检测：订单、库存、价格、转化率、广告投放、服务质量和接口失败码的规则阈值、误报率、发现时间和恢复时间指标。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 30, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num', 'raw_data'], 'label' => '日级经营、订单、流量和服务质量指标', 'collect_from' => '携程/美团业务、流量、订单、广告和服务质量数据采集'],
                    ['table' => 'operation_alerts', 'min_count' => 0, 'fields' => ['alert_type', 'level', 'message', 'raw_data'], 'label' => '运营预警落点', 'collect_from' => '运营管理预警表'],
                ],
            ],
            'service-quality' => [
                'key' => 'service-quality',
                'name' => '服务质量与转化缺口识别',
                'query' => 'hotel OTA service quality PSI traffic conversion service gap revenue impact',
                'module' => '数据配置 / OTA 服务质量 / 运营管理',
                'task' => '基于可采集的服务质量分、PSI、流量转化、订单和广告数据，输出影响转化的服务质量风险、人工复核规则和整改闭环模板。',
                'rules' => [
                    ['table' => 'online_daily_data', 'min_count' => 1, 'fields' => ['data_date', 'amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'flow_rate', 'raw_data'], 'label' => '服务质量、流量转化和订单经营数据', 'collect_from' => '携程/美团服务质量、流量、订单和广告浏览器采集'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, int> $hotelIds
     * @return array<int, array<string, mixed>>
     */
    private function collectLocalSources(array $product, array $hotelIds): array
    {
        return [
            $this->knowledgeUnitsSummary($product, $hotelIds),
            $this->knowledgeBaseSummary($product, $hotelIds),
            $this->tableSummary('online_daily_data', '平台日级数据', 'data_date', ['data_date', 'amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num', 'raw_data'], $hotelIds),
            $this->tableSummary('daily_reports', '经营日报', 'report_date', ['report_date', 'occupancy_rate', 'revenue', 'room_count', 'guest_count', 'report_data'], $hotelIds),
            $this->tableSummary('demand_forecasts', '需求预测结果', 'forecast_date', ['forecast_date', 'predicted_occupancy', 'predicted_demand', 'confidence_score', 'historical_data'], $hotelIds),
            $this->tableSummary('price_suggestions', '定价建议', 'suggestion_date', ['suggestion_date', 'current_price', 'suggested_price', 'min_price', 'max_price', 'competitor_data', 'factors'], $hotelIds),
            $this->tableSummary('competitor_analysis', '竞对分析', 'analysis_date', ['analysis_date', 'our_price', 'competitor_price', 'price_difference', 'price_index', 'competitor_data'], $hotelIds),
            $this->tableSummary('operation_alerts', '运营预警', 'related_date', ['alert_type', 'level', 'title', 'message', 'source', 'status', 'raw_data'], $hotelIds),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function knowledgeUnitsSummary(array $product, array $hotelIds): array
    {
        if (!$this->tableExists('knowledge_units') || !$this->tableExists('knowledge_chunks')) {
            return [
                'source' => 'knowledge_units',
                'label' => '智能知识单元',
                'status' => 'missing_table',
                'count' => 0,
                'summary' => '知识中枢表不存在',
            ];
        }

        $keywords = $this->knowledgeKeywords($product);
        $columns = $this->tableColumns('knowledge_units');
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        $query = Db::name('knowledge_units')->where('status', 'done');
        if (isset($columns['hotel_id']) && isset($columns['created_by'])) {
            if ($hotelIds !== []) {
                $query->where(function ($scope) use ($hotelIds): void {
                    $scope->whereIn('hotel_id', $hotelIds)
                        ->whereOr(function ($global): void {
                            $global->where('hotel_id', 0)->where('created_by', 0);
                        });
                });
            } else {
                $query->where('hotel_id', 0)->where('created_by', 0);
            }
        } else {
            $query->whereRaw('1 = 0');
        }
        $this->applyKeywordRawWhere($query, ['name', 'description'], $keywords, 'ku');
        $rows = $query->order('unit_id', 'desc')->limit(8)->select()->toArray();
        $unitIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['unit_id'] ?? 0), $rows)));
        $chunkCount = $unitIds ? (int)Db::name('knowledge_chunks')->whereIn('unit_id', $unitIds)->count() : 0;

        return [
            'source' => 'knowledge_units',
            'label' => '智能知识单元',
            'status' => $rows ? 'available' : 'empty',
            'count' => count($rows),
            'chunk_count' => $chunkCount,
            'summary' => $rows ? '已检索到相关知识单元' : '未检索到相关知识单元',
            'items' => array_map(static fn(array $row): array => [
                'unit_id' => (int)($row['unit_id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
            ], $rows),
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function knowledgeBaseSummary(array $product, array $hotelIds): array
    {
        if (!$this->tableExists('knowledge_base')) {
            return [
                'source' => 'knowledge_base',
                'label' => '员工知识库',
                'status' => 'missing_table',
                'count' => 0,
                'summary' => '员工知识库表不存在',
            ];
        }

        $keywords = $this->knowledgeKeywords($product);
        $query = Db::name('knowledge_base')->where('is_enabled', 1);
        $this->applyKeywordRawWhere($query, ['title', 'content', 'keywords'], $keywords, 'kb');
        $columns = $this->tableColumns('knowledge_base');
        if ($hotelIds && isset($columns['hotel_id'])) {
            $query->whereIn('hotel_id', $hotelIds);
        }

        $rows = $query->field('id,title,keywords,hotel_id')->order('id', 'desc')->limit(8)->select()->toArray();
        return [
            'source' => 'knowledge_base',
            'label' => '员工知识库',
            'status' => $rows ? 'available' : 'empty',
            'count' => count($rows),
            'summary' => $rows ? '已检索到相关员工知识库内容' : '未检索到相关员工知识库内容',
            'items' => array_map(static fn(array $row): array => [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? ''),
                'keywords' => (string)($row['keywords'] ?? ''),
            ], $rows),
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function tableSummary(string $table, string $label, string $dateColumn, array $fields, array $hotelIds): array
    {
        if (!$this->tableExists($table)) {
            return [
                'source' => $table,
                'label' => $label,
                'status' => 'missing_table',
                'count' => 0,
                'summary' => $label . '表不存在',
                'fields_available' => [],
                'fields_missing' => $fields,
            ];
        }

        $columns = $this->tableColumns($table);
        $available = array_values(array_intersect($fields, array_keys($columns)));
        $missing = array_values(array_diff($fields, $available));
        $countQuery = $this->scopedQuery($table, $columns, $hotelIds);
        $count = (int)$countQuery->count();
        $range = [];
        if ($dateColumn !== '' && isset($columns[$dateColumn]) && $count > 0) {
            $rangeRow = $this->scopedQuery($table, $columns, $hotelIds)
                ->field('MIN(`' . $dateColumn . '`) AS min_date, MAX(`' . $dateColumn . '`) AS max_date')
                ->find();
            if (is_array($rangeRow)) {
                $range = [
                    'start' => (string)($rangeRow['min_date'] ?? ''),
                    'end' => (string)($rangeRow['max_date'] ?? ''),
                ];
            }
        }

        return [
            'source' => $table,
            'label' => $label,
            'status' => $count > 0 ? 'available' : 'empty',
            'count' => $count,
            'date_range' => $range,
            'fields_available' => $available,
            'fields_missing' => $missing,
            'summary' => $count > 0 ? $label . '已存在 ' . $count . ' 条记录' : $label . '暂无记录',
        ];
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<string, mixed>
     */
    private function buildBusinessForecast(array $hotelIds): array
    {
        if (!$this->tableExists('online_daily_data')) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'online_daily_data 表不存在，未生成 OTA 渠道经营预测。',
                'data_gaps' => ['online_daily_data_table_missing'],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $columns = $this->tableColumns('online_daily_data');
        $required = [
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'source',
            'system_hotel_id',
            'data_type',
            'compare_type',
            'data_period',
            'is_final',
            'validation_status',
            'readback_verified',
        ];
        $missing = array_values(array_diff($required, array_keys($columns)));
        if (array_intersect(['source_trace_id', 'data_source_id', 'sync_task_id'], array_keys($columns)) === []) {
            $missing[] = 'source_trace_id/data_source_id/sync_task_id';
        }
        if (array_intersect(['collected_at', 'snapshot_time', 'raw_data'], array_keys($columns)) === []) {
            $missing[] = 'collected_at/snapshot_time/raw_data';
        }
        if ($missing) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'online_daily_data 缺少 OTA 渠道预测必需字段：' . implode('、', $missing),
                'data_gaps' => array_map(static fn(string $field): string => 'required_column_missing_' . $field, $missing),
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $fields = array_values(array_intersect([
            'id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'hotel_name',
            'hotel_id',
            'system_hotel_id',
            'validation_status',
            'validation_flags',
            'status',
            'save_status',
            'readback_verified',
            'readback_verified_at',
            'compare_type',
            'data_period',
            'is_final',
            'source_trace_id',
            'data_source_id',
            'sync_task_id',
            'ingestion_method',
            'collected_at',
            'snapshot_time',
            'raw_data',
            'error_info',
            'failure_reason',
            'failed_reason',
            'update_time',
            'create_time',
        ], array_keys($columns)));
        $fieldSql = implode(',', array_map(static fn(string $field): string => '`' . $field . '`', $fields));
        $forecastEndDate = date('Y-m-d', strtotime('-1 day'));
        $forecastStartDate = date('Y-m-d', strtotime($forecastEndDate . ' -119 days'));
        $query = $this->scopedQuery('online_daily_data', $columns, $hotelIds)
            ->field($fieldSql)
            ->where('data_date', '>=', $forecastStartDate)
            ->where('data_date', '<=', $forecastEndDate)
            ->whereIn('data_type', ['business', 'business_overview', 'overview', 'operation'])
            ->where('compare_type', 'self')
            ->where('data_period', 'historical_daily')
            ->where('is_final', 1)
            ->where('readback_verified', 1)
            ->whereIn('validation_status', ['normal', 'available', 'verified', 'valid', 'confirmed', 'approved', 'passed', 'ok', 'success', 'complete', 'completed']);

        $rows = [];
        $offset = 0;
        $pageSize = 1000;
        $maxRows = 50000;
        $tooManyRows = false;
        while (true) {
            $pageQuery = (clone $query)->order('data_date', 'desc');
            if (isset($columns['id'])) {
                $pageQuery->order('id', 'desc');
            }
            $batch = $pageQuery->limit($offset, $pageSize)->select()->toArray();
            if ($batch === []) {
                break;
            }
            if (count($rows) + count($batch) > $maxRows) {
                $tooManyRows = true;
                break;
            }
            $rows = array_merge($rows, $batch);
            if (count($batch) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }
        if ($tooManyRows) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => '可信日级经营记录超过安全窗口，请缩小酒店范围后重新生成，系统未使用截断样本。',
                'data_gaps' => ['trusted_forecast_rows_exceed_safe_window'],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
        $rows = $this->selectCanonicalOnlineOperatingRows($rows);

        $forecast = $this->buildBusinessForecastFromRows($rows);
        $forecast['truth_context'] = $this->businessForecastTruthContext($rows);
        return $forecast;
    }

    /**
     * @param array<int, array<string, mixed>> $rows Canonical, verified OTA daily operating rows.
     * @return array<string, mixed>
     */
    private function buildBusinessForecastFromRows(array $rows): array
    {
        $daily = [];
        $sourceCounts = [];
        $hotelNames = [];
        foreach ($rows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $revenue = $this->nullableNumberValue($row['amount'] ?? null);
            $roomNights = $this->nullableNumberValue($row['quantity'] ?? null);
            $orders = $this->nullableNumberValue($row['book_order_num'] ?? null);
            if ($revenue === null && $roomNights === null && $orders === null) {
                continue;
            }

            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'revenue' => null,
                    'room_nights' => null,
                    'orders' => null,
                    'row_count' => 0,
                ];
            }
            foreach (['revenue' => $revenue, 'room_nights' => $roomNights, 'orders' => $orders] as $metric => $value) {
                if ($value !== null) {
                    $daily[$date][$metric] = (float)($daily[$date][$metric] ?? 0.0) + $value;
                }
            }
            $daily[$date]['row_count']++;

            $source = trim((string)($row['source'] ?? ''));
            if ($source !== '') {
                $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            }
            $hotelName = trim((string)($row['hotel_name'] ?? ''));
            if ($hotelName !== '') {
                $hotelNames[$hotelName] = true;
            }
        }

        if (!$daily) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => '未找到包含收入、间夜或订单字段的可信 OTA 渠道日级记录，未生成经营预测。',
                'sample_days' => 0,
                'metric_sample_days' => ['revenue' => 0, 'room_nights' => 0, 'orders' => 0],
                'metric_completeness' => ['revenue' => 0.0, 'room_nights' => 0.0, 'orders' => 0.0],
                'data_gaps' => ['trusted_ota_daily_metrics_missing'],
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        usort($daily, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
        $observedDays = count($daily);
        $metricSampleDays = [];
        foreach (['revenue', 'room_nights', 'orders'] as $metric) {
            $metricSampleDays[$metric] = count(array_filter(
                $daily,
                static fn(array $row): bool => $row[$metric] !== null && is_numeric($row[$metric])
            ));
        }
        $completeDaily = array_values(array_filter(
            $daily,
            static fn(array $row): bool => $row['revenue'] !== null
                && $row['room_nights'] !== null
                && $row['orders'] !== null
        ));
        $completeSampleDays = count($completeDaily);
        $metricCompleteness = array_map(
            static fn(int $count): float => $observedDays > 0 ? round($count / $observedDays, 4) : 0.0,
            $metricSampleDays
        );
        $dataGaps = [];
        foreach ($metricSampleDays as $metric => $count) {
            if ($count < 3) {
                $dataGaps[] = $metric . '_sample_days_below_3';
            }
        }
        if ($completeSampleDays < 3) {
            $dataGaps[] = 'complete_operating_sample_days_below_3';
        }

        $dateRange = [
            'start' => (string)($daily[0]['date'] ?? ''),
            'end' => (string)($daily[$observedDays - 1]['date'] ?? ''),
        ];
        if ($dataGaps !== []) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'OTA 渠道日级收入、间夜和订单没有至少 3 个同日完整样本，未生成经营预测。',
                'sample_days' => $completeSampleDays,
                'observed_days' => $observedDays,
                'metric_sample_days' => $metricSampleDays,
                'metric_completeness' => $metricCompleteness,
                'complete_sample_days' => $completeSampleDays,
                'date_range' => $dateRange,
                'data_gaps' => $dataGaps,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }

        $sampleDays = $completeSampleDays;
        $recent7 = $this->aggregateWindow(array_slice($completeDaily, -7));
        $previous7 = $this->aggregateWindow(array_slice($completeDaily, max(0, $sampleDays - 14), min(7, max(0, $sampleDays - 7))));
        $recent30 = $this->aggregateWindow(array_slice($completeDaily, -30));
        $trend = is_numeric($previous7['avg_daily_revenue'] ?? null)
            && (float)$previous7['avg_daily_revenue'] > 0
            && is_numeric($recent7['avg_daily_revenue'] ?? null)
            ? ($recent7['avg_daily_revenue'] - $previous7['avg_daily_revenue']) / $previous7['avg_daily_revenue']
            : null;
        $trend = $trend === null ? null : max(-0.3, min(0.3, $trend));
        if (($recent7['adr'] ?? null) === null) {
            $dataGaps[] = 'recent_7d_adr_not_calculable';
        }
        if (($recent7['aov'] ?? null) === null) {
            $dataGaps[] = 'recent_7d_aov_not_calculable';
        }
        if ($dataGaps !== []) {
            return [
                'available' => false,
                'decision_ready' => false,
                'metric_scope' => 'ota_channel',
                'method' => '最近7天移动均值 + 最近7天/前7天趋势修正',
                'message' => 'OTA 渠道样本缺少可计算 ADR 或 AOV 的有效分母，未生成完整经营预测。',
                'sample_days' => $sampleDays,
                'observed_days' => $observedDays,
                'metric_sample_days' => $metricSampleDays,
                'metric_completeness' => $metricCompleteness,
                'complete_sample_days' => $completeSampleDays,
                'date_range' => $dateRange,
                'recent_7d' => $recent7,
                'previous_7d' => $previous7,
                'recent_30d' => $recent30,
                'data_gaps' => $dataGaps,
                'generated_at' => date('Y-m-d H:i:s'),
            ];
        }
        $forecast7 = $this->forecastWindow($recent7, 7, $trend);
        $forecast30 = $this->forecastWindow($recent30, 30, $trend);
        $dailyForecast = $this->buildDailyForecast($forecast7, $trend);
        $confidence = $sampleDays >= 60 ? 'high' : ($sampleDays >= 30 ? 'medium' : ($sampleDays >= 14 ? 'low' : 'very_low'));
        $riskSignals = [];
        if ($trend === null) {
            $riskSignals[] = '前一对比窗口没有可用收入分母，本次仅使用移动均值，未应用趋势修正。';
        }
        if ($trend !== null && $trend < -0.1) {
            $riskSignals[] = '最近7天收入均值较前7天下降超过10%，需关注流量、价格和竞对动作。';
        }
        if (is_numeric($recent7['adr'] ?? null)
            && is_numeric($recent30['adr'] ?? null)
            && (float)$recent7['adr'] > 0
            && (float)$recent30['adr'] > 0
            && (float)$recent7['adr'] < (float)$recent30['adr'] * 0.9
        ) {
            $riskSignals[] = '最近7天 ADR 低于近30天均值超过10%，需复盘低价订单、促销和房型结构。';
        }
        if ($sampleDays < 30) {
            $riskSignals[] = '样本少于30个有效经营日，预测只能作为短期经营参考。';
        }
        $forecastDateRange = [
            'start' => (string)($completeDaily[0]['date'] ?? ''),
            'end' => (string)($completeDaily[$sampleDays - 1]['date'] ?? ''),
        ];

        return [
            'available' => true,
            'decision_ready' => true,
            'metric_scope' => 'ota_channel',
            'method' => $trend === null
                ? '最近可用 OTA 渠道完整日样本移动均值；前一收入窗口不足，本次未应用趋势修正。'
                : '最近7天移动均值 + 最近7天/前7天趋势修正，趋势修正封顶为正负30%。',
            'generated_at' => date('Y-m-d H:i:s'),
            'sample_days' => $sampleDays,
            'observed_days' => $observedDays,
            'metric_sample_days' => $metricSampleDays,
            'metric_completeness' => $metricCompleteness,
            'complete_sample_days' => $completeSampleDays,
            'data_gaps' => [],
            'confidence' => $confidence,
            'date_range' => $forecastDateRange,
            'observed_date_range' => $dateRange,
            'hotel_names' => array_slice(array_keys($hotelNames), 0, 8),
            'source_counts' => $sourceCounts,
            'recent_7d' => $recent7,
            'previous_7d' => $previous7,
            'recent_30d' => $recent30,
            'trend_percent' => $trend === null ? null : round($trend * 100, 2),
            'forecast_7d' => $forecast7,
            'forecast_30d' => $forecast30,
            'daily_forecast' => $dailyForecast,
            'risk_signals' => $riskSignals,
        ];
    }

    /**
     * online_daily_data 同时保存本店日汇总、流量快照和竞品字段事实。
     * 收益预测只允许每个酒店/渠道/日期的一条已验证日级经营汇总进入计算。
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function selectCanonicalOnlineOperatingRows(array $rows): array
    {
        $selected = [];
        foreach ($rows as $row) {
            if (!$this->isCanonicalOnlineOperatingRow($row)) {
                continue;
            }

            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $hotelId = (string)($row['system_hotel_id'] ?? $row['hotel_id'] ?? '');
            $source = strtolower(trim((string)($row['source'] ?? ''))) ?: 'unknown';
            $key = $hotelId . '|' . $source . '|' . $date;
            if (!isset($selected[$key]) || $this->preferCanonicalOnlineOperatingRow($row, $selected[$key])) {
                $selected[$key] = $row;
            }
        }

        $result = array_values($selected);
        usort($result, static function (array $left, array $right): int {
            return strcmp((string)($left['data_date'] ?? ''), (string)($right['data_date'] ?? ''));
        });
        return $result;
    }

    /** @param array<string, mixed> $row */
    private function isCanonicalOnlineOperatingRow(array $row): bool
    {
        if (array_key_exists('dimension', $row) && trim((string)$row['dimension']) !== '') {
            return false;
        }

        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if (!in_array($dataType, [
            'business',
            'business_overview',
            'overview',
            'operation',
        ], true)) {
            return false;
        }

        if (strtolower(trim((string)($row['compare_type'] ?? ''))) !== 'self') {
            return false;
        }
        if (strtolower(trim((string)($row['data_period'] ?? ''))) !== 'historical_daily') {
            return false;
        }
        if (!in_array($row['is_final'] ?? null, [1, '1', true, 'true'], true)) {
            return false;
        }
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return false;
        }

        if (OnlineDataTrustStatusService::classifyRowStatus($row['status'] ?? $row['save_status'] ?? '') !== 'usable') {
            return false;
        }

        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if (!in_array($validationStatus, [
            'normal',
            'available',
            'verified',
            'valid',
            'confirmed',
            'approved',
            'passed',
            'ok',
            'success',
            'complete',
            'completed',
        ], true)) {
            return false;
        }

        if ((int)($row['system_hotel_id'] ?? 0) <= 0 || trim((string)($row['source'] ?? '')) === '') {
            return false;
        }
        $hasProvenance = trim((string)($row['source_trace_id'] ?? '')) !== ''
            || (int)($row['data_source_id'] ?? 0) > 0
            || (int)($row['sync_task_id'] ?? 0) > 0;
        if (!$hasProvenance || $this->hasBlockingOnlineValidationFlag($row['validation_flags'] ?? [])) {
            return false;
        }
        if ($this->onlineRowCollectedAt($row) === '') {
            return false;
        }
        $ingestionMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        if (in_array($ingestionMethod, [
            'legacy', 'manual', 'manual_import', 'manual_override',
            'user_provided', 'user_provided_unverified', 'import_csv', 'import_json',
        ], true)) {
            return false;
        }
        foreach (['error_info', 'failure_reason', 'failed_reason'] as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function onlineRowCollectedAt(array $row): string
    {
        $raw = $this->decodeJsonValue($row['raw_data'] ?? null);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $raw['collected_at'] ?? null,
            $raw['collectedAt'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['capturedAt'] ?? null,
            $raw['fetched_at'] ?? null,
            $meta['collected_at'] ?? null,
            $meta['captured_at'] ?? null,
            $capture['collected_at'] ?? null,
            $capture['captured_at'] ?? null,
        ] as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function decodeJsonValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function businessForecastTruthContext(array $rows): array
    {
        $dates = [];
        $collectedTimes = [];
        $rowIds = [];
        $traceIds = [];
        $platforms = [];
        $methods = [];
        $hotels = [];
        foreach ($rows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($date !== '') {
                $dates[] = $date;
            }
            $collectedAt = $this->onlineRowCollectedAt($row);
            if ($collectedAt !== '') {
                $collectedTimes[] = $collectedAt;
            }
            if (isset($row['id']) && trim((string)$row['id']) !== '') {
                $rowIds[] = $row['id'];
            }
            $traceId = trim((string)($row['source_trace_id'] ?? ''));
            if ($traceId !== '') {
                $traceIds[] = $traceId;
            }
            $platform = strtolower(trim((string)($row['source'] ?? '')));
            if ($platform !== '') {
                $platforms[] = $platform;
            }
            $method = trim((string)($row['ingestion_method'] ?? ''));
            if ($method !== '') {
                $methods[] = $method;
            }
            $hotelId = max(0, (int)($row['system_hotel_id'] ?? 0));
            if ($hotelId > 0) {
                $hotels['id:' . $hotelId] = [
                    'system_hotel_id' => $hotelId,
                    'name' => trim((string)($row['hotel_name'] ?? '')),
                ];
            }
        }
        sort($dates);
        sort($collectedTimes);
        $rowCount = count($rows);
        $trust = [
            'source' => [
                'table' => 'online_daily_data',
                'row_ids' => array_values(array_unique($rowIds, SORT_REGULAR)),
                'trace_ids' => array_values(array_unique($traceIds)),
                'hotels' => array_values($hotels),
                'platforms' => array_values(array_unique($platforms)),
                'data_types' => ['business'],
                'source_methods' => array_values(array_unique($methods)),
                'date_range' => [
                    'start' => $dates[0] ?? null,
                    'end' => $dates !== [] ? $dates[count($dates) - 1] : null,
                ],
                'collected_at_range' => [
                    'start' => $collectedTimes[0] ?? null,
                    'end' => $collectedTimes !== [] ? $collectedTimes[count($collectedTimes) - 1] : null,
                ],
                'row_count' => $rowCount,
                'stored_count' => count($rowIds),
                'readback_verified_count' => count(array_filter(
                    $rows,
                    static fn(array $row): bool => (int)($row['readback_verified'] ?? 0) === 1
                )),
            ],
            'caliber' => '以已验证 OTA 渠道完整日样本计算移动均值与趋势修正规则预测',
            'saved_success' => $rowCount > 0,
            'failure_reasons' => $rowCount > 0 ? [] : ['trusted_ota_daily_metrics_missing'],
        ];
        $truth = OnlineDataTrustStatusService::metricTruthEnvelope($trust);
        $truth['result_layer'] = 'rule_forecast';
        $truth['calculated_at'] = date('Y-m-d H:i:s');
        return $truth;
    }

    private function hasBlockingOnlineValidationFlag(mixed $flags): bool
    {
        $decoded = is_string($flags) ? json_decode($flags, true) : $flags;
        if (!is_array($decoded)) {
            return trim((string)$flags) !== '';
        }
        foreach ($decoded as $key => $value) {
            $flag = strtolower(trim(is_string($key) ? $key : (string)$value));
            if ($flag === '' || in_array($value, [false, 0, '0', null, ''], true)) {
                continue;
            }
            foreach (['mismatch', 'invalid', 'failed', 'failure', 'unverified', 'permission_denied', 'binding_missing'] as $blocked) {
                if (str_contains($flag, $blocked)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $current
     */
    private function preferCanonicalOnlineOperatingRow(array $candidate, array $current): bool
    {
        $candidateRank = $this->canonicalOnlineOperatingRowRank($candidate);
        $currentRank = $this->canonicalOnlineOperatingRowRank($current);
        if ($candidateRank !== $currentRank) {
            return $candidateRank > $currentRank;
        }

        $candidateTime = $this->onlineOperatingRowTimestamp($candidate);
        $currentTime = $this->onlineOperatingRowTimestamp($current);
        if ($candidateTime !== $currentTime) {
            return $candidateTime > $currentTime;
        }

        return (int)($candidate['id'] ?? 0) > (int)($current['id'] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private function canonicalOnlineOperatingRowRank(array $row): int
    {
        $typeRank = [
            'business' => 500,
            'business_overview' => 450,
            'overview' => 400,
            'operation' => 300,
        ];
        $rank = $typeRank[strtolower(trim((string)($row['data_type'] ?? '')))] ?? 0;
        if ($this->nullableNumberValue($row['amount'] ?? null) !== null) {
            $rank += 40;
        }
        if ($this->nullableNumberValue($row['quantity'] ?? null) !== null) {
            $rank += 30;
        }
        if ($this->nullableNumberValue($row['book_order_num'] ?? null) !== null) {
            $rank += 20;
        }
        return $rank;
    }

    /** @param array<string, mixed> $row */
    private function onlineOperatingRowTimestamp(array $row): int
    {
        foreach (['update_time', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function aggregateWindow(array $rows): array
    {
        $days = count($rows);
        $values = ['revenue' => [], 'room_nights' => [], 'orders' => []];
        $adrRevenue = 0.0;
        $adrRoomNights = 0.0;
        $adrSampleDays = 0;
        $aovRevenue = 0.0;
        $aovOrders = 0.0;
        $aovSampleDays = 0;
        foreach ($rows as $row) {
            $revenue = $this->nullableNumberValue($row['revenue'] ?? null);
            $roomNights = $this->nullableNumberValue($row['room_nights'] ?? null);
            $orders = $this->nullableNumberValue($row['orders'] ?? null);
            if ($revenue !== null) {
                $values['revenue'][] = $revenue;
            }
            if ($roomNights !== null) {
                $values['room_nights'][] = $roomNights;
            }
            if ($orders !== null) {
                $values['orders'][] = $orders;
            }
            if ($revenue !== null && $roomNights !== null) {
                $adrRevenue += $revenue;
                $adrRoomNights += $roomNights;
                $adrSampleDays++;
            }
            if ($revenue !== null && $orders !== null) {
                $aovRevenue += $revenue;
                $aovOrders += $orders;
                $aovSampleDays++;
            }
        }

        $totals = [];
        $averages = [];
        $sampleDays = [];
        foreach ($values as $metric => $metricValues) {
            $sampleDays[$metric] = count($metricValues);
            $totals[$metric] = $metricValues === [] ? null : round(array_sum($metricValues), $metric === 'revenue' ? 2 : 0);
            $averages[$metric] = $metricValues === []
                ? null
                : round(array_sum($metricValues) / count($metricValues), 2);
        }
        $dataGaps = [];
        foreach ($sampleDays as $metric => $count) {
            if ($count < $days) {
                $dataGaps[] = $metric . '_window_samples_incomplete';
            }
        }
        if ($adrSampleDays === 0 || $adrRoomNights <= 0) {
            $dataGaps[] = 'adr_not_calculable';
        }
        if ($aovSampleDays === 0 || $aovOrders <= 0) {
            $dataGaps[] = 'aov_not_calculable';
        }

        return [
            'days' => $days,
            'revenue' => $totals['revenue'],
            'room_nights' => $totals['room_nights'],
            'orders' => $totals['orders'],
            'adr' => $adrSampleDays > 0 && $adrRoomNights > 0 ? round($adrRevenue / $adrRoomNights, 2) : null,
            'aov' => $aovSampleDays > 0 && $aovOrders > 0 ? round($aovRevenue / $aovOrders, 2) : null,
            'avg_daily_revenue' => $averages['revenue'],
            'avg_daily_room_nights' => $averages['room_nights'],
            'avg_daily_orders' => $averages['orders'],
            'metric_sample_days' => $sampleDays,
            'aligned_sample_days' => ['adr' => $adrSampleDays, 'aov' => $aovSampleDays],
            'data_gaps' => array_values(array_unique($dataGaps)),
        ];
    }

    /**
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private function forecastWindow(array $window, int $days, ?float $trend): array
    {
        $factor = $trend === null ? 1.0 : 1 + $trend * 0.5;
        $project = function (string $key, int $precision) use ($window, $days, $factor): ?float {
            $average = $this->nullableNumberValue($window[$key] ?? null);
            return $average === null ? null : round(max(0, $average * $days * $factor), $precision);
        };
        $revenue = $project('avg_daily_revenue', 2);
        $roomNights = $project('avg_daily_room_nights', 0);
        $orders = $project('avg_daily_orders', 0);
        $adrReady = $revenue !== null
            && $roomNights !== null
            && $roomNights > 0
            && ($window['adr'] ?? null) !== null;
        $aovReady = $revenue !== null
            && $orders !== null
            && $orders > 0
            && ($window['aov'] ?? null) !== null;
        $dataGaps = [];
        foreach (['revenue' => $revenue, 'room_nights' => $roomNights, 'orders' => $orders] as $metric => $value) {
            if ($value === null) {
                $dataGaps[] = $metric . '_forecast_operand_missing';
            }
        }
        if (!$adrReady) {
            $dataGaps[] = 'adr_forecast_not_calculable';
        }
        if (!$aovReady) {
            $dataGaps[] = 'aov_forecast_not_calculable';
        }

        return [
            'days' => $days,
            'revenue' => $revenue,
            'room_nights' => $roomNights,
            'orders' => $orders,
            'adr' => $adrReady ? round($revenue / $roomNights, 2) : null,
            'aov' => $aovReady ? round($revenue / $orders, 2) : null,
            'trend_adjustment_percent' => $trend === null ? null : round(($factor - 1) * 100, 2),
            'decision_ready' => $dataGaps === [],
            'data_gaps' => $dataGaps,
        ];
    }

    /**
     * @param array<string, mixed> $forecast7
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyForecast(array $forecast7, ?float $trend): array
    {
        $daily = [];
        $forecastDays = max(1, (int)($forecast7['days'] ?? 7));
        $forecastRevenue = $this->nullableNumberValue($forecast7['revenue'] ?? null);
        $forecastRoomNights = $this->nullableNumberValue($forecast7['room_nights'] ?? null);
        $forecastOrders = $this->nullableNumberValue($forecast7['orders'] ?? null);
        $baseRevenue = $forecastRevenue === null ? null : $forecastRevenue / $forecastDays;
        $baseRoomNights = $forecastRoomNights === null ? null : $forecastRoomNights / $forecastDays;
        $baseOrders = $forecastOrders === null ? null : $forecastOrders / $forecastDays;
        $today = strtotime(date('Y-m-d'));
        for ($i = 1; $i <= 7; $i++) {
            $factor = $trend === null ? 1.0 : 1 + $trend * ($i - 4) / 28;
            $roomNights = $baseRoomNights === null ? null : max(0, $baseRoomNights * $factor);
            $orders = $baseOrders === null ? null : max(0, $baseOrders * $factor);
            $revenue = $baseRevenue === null ? null : max(0, $baseRevenue * $factor);
            $adr = $revenue !== null
                && $roomNights !== null
                && $roomNights > 0
                && ($forecast7['adr'] ?? null) !== null
                ? round($revenue / $roomNights, 2)
                : null;
            $daily[] = [
                'date' => date('Y-m-d', (int)$today + 86400 * $i),
                'revenue' => $revenue === null ? null : round($revenue, 2),
                'room_nights' => $roomNights === null ? null : round($roomNights, 0),
                'orders' => $orders === null ? null : round($orders, 0),
                'adr' => $adr,
                'data_status' => $revenue !== null && $roomNights !== null && $orders !== null && $adr !== null
                    ? 'rule_forecast'
                    : 'partial',
            ];
        }
        return $daily;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @return array<int, array<string, mixed>>
     */
    private function evaluateGaps(array $product, array $localSources): array
    {
        $indexed = [];
        foreach ($localSources as $source) {
            $indexed[(string)$source['source']] = $source;
        }

        $gaps = [];
        foreach (($product['rules'] ?? []) as $rule) {
            $table = (string)$rule['table'];
            $source = $indexed[$table] ?? null;
            if (!$source || ($source['status'] ?? '') === 'missing_table') {
                $gaps[] = $this->gap($rule, 'missing_table', '缺少数据表：' . $table);
                continue;
            }

            $missingFields = array_values(array_intersect((array)$rule['fields'], (array)($source['fields_missing'] ?? [])));
            if ($missingFields) {
                $gaps[] = $this->gap($rule, 'missing_fields', '缺少字段：' . implode('、', $missingFields), $missingFields);
                continue;
            }

            $minCount = (int)($rule['min_count'] ?? 0);
            $count = (int)($source['count'] ?? 0);
            if ($count < $minCount) {
                $gaps[] = $this->gap($rule, 'insufficient_rows', '样本量不足：当前 ' . $count . ' 条，至少需要 ' . $minCount . ' 条');
            }
        }

        return $gaps;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function gap(array $rule, string $type, string $reason, array $fields = []): array
    {
        return [
            'type' => $type,
            'table' => (string)($rule['table'] ?? ''),
            'label' => (string)($rule['label'] ?? ''),
            'fields' => $fields ?: (array)($rule['fields'] ?? []),
            'reason' => $reason,
            'collect_from' => (string)($rule['collect_from'] ?? ''),
            'priority' => 'high',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOpenAiConfig(string $modelKey): array
    {
        $config = AiModelConfig::where('model_key', $modelKey)->where('is_enabled', 1)->find();
        if (!$config) {
            throw new RuntimeException('缺少 OpenAI 配置：请进入“系统设置 > AI模型配置”配置并启用 openai_fast。', 422);
        }

        $baseUrl = rtrim(trim((string)$config->base_url), '/');
        $modelName = trim((string)$config->model_name);
        if ($baseUrl === '') {
            throw new RuntimeException('openai_fast 必须使用 OpenAI Responses API 地址：https://api.openai.com/v1。', 422);
        }
        $responsesTarget = $this->validateOpenAiResponsesTarget($baseUrl);
        if ($modelName === '') {
            throw new RuntimeException('OpenAI 模型名称为空，请进入“系统设置 > AI模型配置”补充模型名称。', 422);
        }
        if (trim((string)$config->api_key_encrypted) === '') {
            throw new RuntimeException('OpenAI API Key 为空，请进入“系统设置 > AI模型配置”重新保存 openai_fast。', 422);
        }

        $secret = trim((string)env('AI_CONFIG_SECRET', ''));
        if ($secret === '') {
            throw new RuntimeException('AI_CONFIG_SECRET 未配置，无法读取 openai_fast 的密钥。', 422);
        }
        $apiKey = AiModelConfig::decryptApiKey((string)$config->api_key_encrypted, $secret);
        if ($apiKey === null) {
            throw new RuntimeException('openai_fast API Key 解密失败，请确认 AI_CONFIG_SECRET 与保存密钥时一致。', 422);
        }

        return [
            'model_key' => $modelKey,
            'model' => $modelName,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'responses_target' => $responsesTarget,
        ];
    }

    /**
     * @return array{url:string,host:string,port:int,addresses:array<int,string>,curl_resolve:array<int,string>}
     */
    private function validateOpenAiResponsesTarget(
        string $baseUrl,
        ?OutboundUrlGuard $guard = null
    ): array {
        try {
            $target = ($guard ?? new OutboundUrlGuard())->validate(rtrim(trim($baseUrl), '/') . '/responses');
        } catch (\Throwable $e) {
            throw new RuntimeException('openai_fast 必须使用可验证的 OpenAI Responses API 地址。', 422, $e);
        }
        if (!hash_equals('api.openai.com', (string)$target['host'])) {
            throw new RuntimeException('openai_fast 必须使用 OpenAI Responses API 地址：https://api.openai.com/v1。', 422);
        }
        return $target;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function callOpenAiWebSearch(array $config, array $product, array $localSources, array $gaps, array $businessForecast, array $knowledgeContext): array
    {
        $lastMessage = '';
        foreach (['web_search', 'web_search_preview'] as $toolType) {
            $payload = $this->openAiResponsesPayload($config, $product, $localSources, $gaps, $businessForecast, $knowledgeContext, $toolType);
            $response = $this->sendOpenAiResponsesRequest($config, $payload);
            $status = (int)$response['status'];
            $data = $response['data'];

            if ($status >= 200 && $status < 300) {
                $text = $this->extractOutputText($data);
                $parsed = json_decode($this->extractJsonText($text), true);
                if (!is_array($parsed)) {
                    $parsed = [
                        'summary' => $text,
                        'forecast_assumptions' => [],
                        'key_metrics' => [],
                        'risk_signals' => ['OpenAI 未返回结构化 JSON，已保留原始文本。'],
                        'recommended_actions' => [],
                        'data_gaps' => [],
                        'confidence_note' => '',
                        'next_review_date' => '',
                    ];
                }

                return [
                    'result' => $parsed,
                    'web_sources' => $this->extractWebSources($data),
                    'generation_mode' => 'openai_web_search',
                ];
            }

            $lastMessage = (string)($data['error']['message'] ?? ('OpenAI Responses API HTTP ' . $status));
            if ($toolType === 'web_search' && preg_match('/web_search|tool/i', $lastMessage)) {
                continue;
            }
            break;
        }

        throw new RuntimeException('OpenAI 联网检索失败：' . $this->sanitize($lastMessage), 502);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function callConfiguredModel(string $modelKey, array $product, array $localSources, array $gaps, array $businessForecast, array $knowledgeContext): array
    {
        try {
            $messages = [
                [
                    'role' => 'system',
                    'content' => '你是酒店 OTA 渠道收益分析师。仅在给定基线 decision_ready=true 时输出 OTA 渠道经营预测；否则只列数据缺口和补数动作。不得外推全酒店经营结果，不得编造本地不存在的数据。输出必须是 JSON。',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($product, $localSources, $gaps, $businessForecast, false, $knowledgeContext),
                ],
            ];
            $result = (new LlmClient())->createJsonResponse($messages, $this->resultSchema(), $modelKey);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), $this->llmErrorCode($e->getMessage()));
        }

        $result['forecast_assumptions'] = array_values(array_unique(array_merge(
            $this->stringList($result['forecast_assumptions'] ?? []),
            [(string)($businessForecast['method'] ?? '本地 OTA 渠道预测基线')]
        )));

        return [
            'result' => $result,
            'web_sources' => [],
            'generation_mode' => 'deepseek_model',
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function openAiResponsesPayload(array $config, array $product, array $localSources, array $gaps, array $businessForecast, array $knowledgeContext, string $toolType): array
    {
        $schema = $this->resultSchema();
        unset($schema['x-governance']);

        return [
            'model' => $config['model'],
            'tools' => [
                [
                    'type' => $toolType,
                    'search_context_size' => 'medium',
                    'user_location' => [
                        'type' => 'approximate',
                        'country' => 'CN',
                        'timezone' => 'Asia/Shanghai',
                    ],
                ],
            ],
            'tool_choice' => 'required',
            'include' => ['web_search_call.action.sources'],
            'input' => [
                [
                    'role' => 'system',
                    'content' => '你是酒店 OTA 渠道收益分析师。必须区分本地已有事实、联网资料和缺失数据；仅在基线 decision_ready=true 时输出 OTA 渠道经营预测，否则只返回缺口和补数动作。不得外推全酒店经营结果。输出必须是 JSON。',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($product, $localSources, $gaps, $businessForecast, true, $knowledgeContext),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'revenue_business_prediction',
                    'schema' => $schema,
                    'strict' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $payload
     * @return array{status:int,data:array<string,mixed>}
     */
    private function sendOpenAiResponsesRequest(array $config, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('当前 PHP 未启用 curl 扩展，无法调用 OpenAI Responses API。', 500);
        }

        $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($rawPayload === false) {
            throw new RuntimeException('OpenAI 请求体编码失败：' . json_last_error_msg(), 500);
        }

        $target = isset($config['responses_target']) && is_array($config['responses_target'])
            ? $config['responses_target']
            : $this->validateOpenAiResponsesTarget((string)$config['base_url']);
        $ch = curl_init((string)$target['url']);
        if ($ch === false) {
            throw new RuntimeException('无法初始化 OpenAI Responses API 请求。', 500);
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $config['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $rawPayload,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => $target['curl_resolve'],
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OpenAI 联网检索请求失败：' . $this->sanitize($error ?: 'network error'), 502);
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('OpenAI 返回内容不是有效 JSON。', 502);
        }

        return ['status' => $status, 'data' => $data];
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     */
    private function buildPrompt(
        array $product,
        array $localSources,
        array $gaps,
        array $businessForecast,
        bool $requiresWebSources,
        array $knowledgeContext = []
    ): string
    {
        $sourceRule = $requiresWebSources
            ? '引用来源必须来自联网检索，并返回可点击来源。'
            : '当前默认使用 DeepSeek 配置模型，不要求联网引用；不得编造网页来源或假链接。';

        return implode("\n\n", [
            '产品方向：' . $product['name'],
            '系统落点：' . $product['module'],
            '研究关键词：' . $product['query'],
            'AI任务：基于本地 OTA 渠道数据和预测基线，输出未来7天与30天的 OTA 渠道经营预测、风险信号及人工复核动作；不得外推全酒店经营结果。不要输出代码实现方案，不要写入知识库。',
            '本地已有信息摘要：' . json_encode($localSources, JSON_UNESCAPED_UNICODE),
            '本地 OTA 渠道预测基线：' . json_encode($businessForecast, JSON_UNESCAPED_UNICODE),
            '收益运营知识方法（仅用于解释框架和建议结构，不得替代当前酒店事实或触发 OTA 写入）：' . json_encode($knowledgeContext, JSON_UNESCAPED_UNICODE),
            '本地缺口清单：' . json_encode($gaps, JSON_UNESCAPED_UNICODE),
            '输出要求：1. ' . $sourceRule . ' 2. decision_ready=false 时不得输出预测数值或完成结论，只列补数要求；3. 所有动作均需人工复核，不代表已执行 OTA 写入；4. 如果样本不足，必须明确数据缺口。',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultSchema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'x-governance' => [
                'module' => 'revenue_research',
                'scenario' => 'business_prediction',
                'prompt_version' => 'revenue_research.business_prediction.v1',
                'decision_impact' => 'operational',
                'knowledge_sources' => ['local_sources', 'business_forecast', RevenueOperationsKnowledgeService::SOURCE, 'data_gaps'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'summary' => ['type' => 'string'],
                'forecast_assumptions' => $stringArray,
                'key_metrics' => $stringArray,
                'risk_signals' => $stringArray,
                'recommended_actions' => $stringArray,
                'data_gaps' => $stringArray,
                'confidence_note' => ['type' => 'string'],
                'next_review_date' => ['type' => 'string'],
            ],
            'required' => ['summary', 'forecast_assumptions', 'key_metrics', 'risk_signals', 'recommended_actions', 'data_gaps', 'confidence_note', 'next_review_date'],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $gaps
     * @param array<string, mixed> $businessForecast
     * @param array<int, array<string, mixed>> $localSources
     * @return array<string, mixed>
     */
    private function normalizeAiResult(
        array $result,
        array $product,
        array $gaps,
        array $businessForecast,
        array $localSources = [],
        int $hotelId = 0
    ): array
    {
        $forecast7 = (array)($businessForecast['forecast_7d'] ?? []);
        $forecast30 = (array)($businessForecast['forecast_30d'] ?? []);
        $baselineMetrics = [];
        foreach ([7 => $forecast7, 30 => $forecast30] as $days => $forecast) {
            $parts = [];
            foreach ([
                'revenue' => ['预测收入', '元', 2],
                'room_nights' => ['预测间夜', '', 0],
                'orders' => ['预测订单', '', 0],
                'adr' => ['ADR', '元', 2],
                'aov' => ['AOV', '元', 2],
            ] as $metric => [$label, $unit, $precision]) {
                $value = $this->nullableNumberValue($forecast[$metric] ?? null);
                if ($value !== null) {
                    $parts[] = $label . '约 ' . round($value, $precision) . $unit;
                }
            }
            if ($parts !== []) {
                $baselineMetrics[] = '未来' . $days . '天 OTA 渠道规则预测：' . implode('，', $parts) . '。';
            }
        }
        $gapTexts = array_map(static fn(array $gap): string => (string)$gap['reason'], $gaps);
        $forecastGaps = $this->stringList($businessForecast['data_gaps'] ?? []);
        $decisionReady = ($businessForecast['decision_ready'] ?? false) === true && $gaps === [];
        $pendingMessage = trim((string)($businessForecast['message'] ?? ''));
        if ($pendingMessage === '') {
            $pendingMessage = 'OTA 渠道日级数据仍有缺口，当前仅返回数据准备状态。';
        }

        $allowedModelNumbers = $this->trustedModelNumericTokens($localSources, $businessForecast);
        $modelNumericClaimRejected = false;
        $modelSummary = trim((string)($result['summary'] ?? ''));
        if (
            $modelSummary !== ''
            && !$this->modelTextUsesOnlyTrustedNumbers($modelSummary, $allowedModelNumbers)
        ) {
            $modelSummary = '';
            $modelNumericClaimRejected = true;
        }
        $modelKeyMetrics = $this->filterModelNumericTextList(
            $result['key_metrics'] ?? [],
            $allowedModelNumbers,
            $modelNumericClaimRejected
        );
        $modelRiskSignals = $this->filterModelNumericTextList(
            $result['risk_signals'] ?? [],
            $allowedModelNumbers,
            $modelNumericClaimRejected
        );
        $recommendedActions = $this->filterModelNumericTextList(
            $result['recommended_actions'] ?? [],
            $allowedModelNumbers,
            $modelNumericClaimRejected
        );
        $modelDataGaps = $this->stringList($result['data_gaps'] ?? []);
        if ($modelNumericClaimRejected) {
            $modelDataGaps[] = 'model_numeric_claim_unverified';
        }
        $qualityContext = $this->revenueResearchDecisionQualityContext(
            $product,
            $localSources,
            $businessForecast,
            $hotelId,
            $decisionReady
        );
        $decisionRecommendations = (new AiDecisionQualityService())->enrichRecommendations(
            $recommendedActions,
            $qualityContext
        );

        return [
            'title' => $decisionReady ? $product['name'] . ' OTA渠道经营预测' : $product['name'] . '数据准备状态',
            'summary' => $decisionReady
                ? ($modelSummary !== '' ? $modelSummary : '已生成 OTA 渠道规则预测，仍需人工复核后使用。')
                : $pendingMessage,
            'forecast_assumptions' => $this->stringList($result['forecast_assumptions'] ?? []),
            'key_metrics' => $decisionReady
                ? array_values(array_unique(array_merge($baselineMetrics, $modelKeyMetrics)))
                : [],
            'risk_signals' => array_values(array_unique(array_merge(
                $this->stringList($businessForecast['risk_signals'] ?? []),
                $modelRiskSignals
            ))),
            'recommended_actions' => $recommendedActions,
            'decision_recommendations' => $decisionRecommendations,
            'recommendation_quality' => (new AiDecisionQualityService())->summarize(
                $decisionRecommendations,
                $qualityContext
            ),
            'data_gaps' => array_values(array_unique(array_merge(
                $modelDataGaps,
                $gapTexts,
                $forecastGaps
            ))),
            'confidence_note' => $decisionReady
                ? (string)($result['confidence_note'] ?? ('预测置信度：' . (string)($businessForecast['confidence'] ?? 'unknown')))
                : '数据不完整，未形成可用预测置信度。',
            'next_review_date' => (string)($result['next_review_date'] ?? date('Y-m-d', strtotime('+1 day'))),
            'module' => (string)$product['module'],
            'metric_scope' => 'ota_channel',
            'decision_ready' => $decisionReady,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $localSources
     * @param array<string, mixed> $businessForecast
     * @return array<string, true>
     */
    private function trustedModelNumericTokens(array $localSources, array $businessForecast): array
    {
        $tokens = [];
        $truthContext = is_array($businessForecast['truth_context'] ?? null)
            ? $businessForecast['truth_context']
            : [];
        $truthStatus = strtolower(trim((string)($truthContext['status'] ?? '')));
        $forecastTrusted = ($businessForecast['decision_ready'] ?? false) === true
            && ($truthContext === [] || $truthStatus === 'verified');
        if ($forecastTrusted) {
            $this->collectStructuredNumericTokens($businessForecast, $tokens);
        }

        $trustedStatuses = ['available', 'verified', 'ready', 'complete', 'completed', 'success', 'succeeded'];
        foreach ($localSources as $source) {
            $status = strtolower(trim((string)($source['status'] ?? '')));
            if (!in_array($status, $trustedStatuses, true)) {
                continue;
            }
            $this->collectStructuredNumericTokens($source, $tokens);
        }

        return $tokens;
    }

    /** @param array<string, true> $tokens */
    private function collectStructuredNumericTokens(mixed $value, array &$tokens): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectStructuredNumericTokens($item, $tokens);
            }
            return;
        }
        if (is_bool($value) || $value === null || (!is_int($value) && !is_float($value) && !is_string($value))) {
            return;
        }

        $raw = is_float($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : trim((string)$value);
        $token = $this->normalizeModelNumericToken($raw);
        if ($token !== null) {
            $tokens[$token] = true;
        }
    }

    /**
     * @param array<string, true> $allowedNumbers
     * @return array<int, string>
     */
    private function filterModelNumericTextList(mixed $value, array $allowedNumbers, bool &$rejected): array
    {
        $verified = [];
        foreach ($this->stringList($value) as $item) {
            if (!$this->modelTextUsesOnlyTrustedNumbers($item, $allowedNumbers)) {
                $rejected = true;
                continue;
            }
            $verified[] = $item;
        }
        return $verified;
    }

    /** @param array<string, true> $allowedNumbers */
    private function modelTextUsesOnlyTrustedNumbers(string $text, array $allowedNumbers): bool
    {
        foreach ($this->modelNumericTokens($text) as $token) {
            if (!isset($allowedNumbers[$token])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<int, string> */
    private function modelNumericTokens(string $text): array
    {
        $withoutDatesOrOrdinals = preg_replace([
            '/(?<!\d)\d{4}\s*年\s*\d{1,2}\s*月\s*\d{1,2}\s*日(?!\d)/u',
            '/(?<!\d)\d{1,2}\s*月\s*\d{1,2}\s*日(?!\d)/u',
            '/(?<!\d)\d{4}[-\/.]\d{1,2}(?:[-\/.]\d{1,2})?(?!\d)/u',
            '/(?<!\d)\d{1,2}:\d{2}(?::\d{2})?(?!\d)/u',
            '/第\s*\d+\s*(?:步|项|阶段|条|次|轮|版|章|节|名|位|天|日)/u',
            '/(?<![\pL\pN])P\s*\d+(?![\pL\pN])/iu',
            '/^\s*\d+\s*[.)、：:]\s*/u',
        ], ' ', $text) ?? $text;
        preg_match_all(
            '/(?<![\d.,])[-+]?(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?%?/u',
            $withoutDatesOrOrdinals,
            $matches
        );

        $tokens = [];
        foreach ((array)($matches[0] ?? []) as $match) {
            $token = $this->normalizeModelNumericToken((string)$match);
            if ($token !== null) {
                $tokens[] = $token;
            }
        }
        return array_values(array_unique($tokens));
    }

    private function normalizeModelNumericToken(string $value): ?string
    {
        $value = str_replace([',', '%', ' '], '', trim($value));
        $value = ltrim($value, '+');
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer !== '' ? $integer : '0';
        $fraction = rtrim($fraction, '0');
        if ($integer === '0' && $fraction === '') {
            $negative = false;
        }
        return ($negative ? '-' : '') . $integer . ($fraction !== '' ? '.' . $fraction : '');
    }

    /**
     * @param array<string, mixed> $product
     * @param array<int, array<string, mixed>> $localSources
     * @param array<string, mixed> $businessForecast
     * @return array<string, mixed>
     */
    private function revenueResearchDecisionQualityContext(
        array $product,
        array $localSources,
        array $businessForecast,
        int $hotelId,
        bool $decisionReady
    ): array {
        $evidenceSources = [];
        $dateRange = [];
        $requiredTables = array_values(array_unique(array_filter(array_map(
            static fn(array $rule): string => trim((string)($rule['table'] ?? '')),
            array_values(array_filter((array)($product['rules'] ?? []), 'is_array'))
        ))));
        foreach ($localSources as $source) {
            $sourceKey = trim((string)($source['source'] ?? ''));
            if ($sourceKey === '' || !in_array($sourceKey, $requiredTables, true)) {
                continue;
            }
            $sourceRange = is_array($source['date_range'] ?? null) ? $source['date_range'] : [];
            $sourceEnd = trim((string)($sourceRange['end'] ?? ''));
            if ($sourceKey === 'online_daily_data' && $sourceRange !== []) {
                $dateRange = $sourceRange;
            }
            $explicitlyVerified = ($source['decision_eligible'] ?? false) === true
                && ($source['readback_verified'] ?? false) === true;
            $evidenceSources[] = [
                'ref' => $sourceKey . ($sourceEnd !== '' ? '#' . $sourceEnd : ''),
                'source' => trim((string)($source['label'] ?? $sourceKey)),
                'date' => $sourceEnd,
                'scope' => 'ota_channel',
                'hotel_id' => $hotelId,
                'platform' => 'ota',
                'date_role' => 'historical',
                'quality_status' => $explicitlyVerified ? 'decision_eligible' : 'unverified',
                'source_status' => trim((string)($source['status'] ?? 'unverified')),
                'metric_keys' => array_values(array_filter(array_map(
                    'strval',
                    (array)($source['fields_available'] ?? [])
                ))),
                'summary' => trim((string)($source['summary'] ?? '')),
            ];
        }

        $forecastGeneratedAt = trim((string)($businessForecast['generated_at'] ?? ''));
        $evidenceSources[] = [
            'ref' => 'business_forecast' . ($forecastGeneratedAt !== '' ? '#' . $forecastGeneratedAt : ''),
            'source' => 'OTA渠道规则预测基线',
            'date' => $forecastGeneratedAt,
            'scope' => 'ota_channel',
            'hotel_id' => $hotelId,
            'platform' => 'ota',
            'date_role' => 'generated_at',
            'quality_status' => $decisionReady ? 'verified' : 'unverified',
            'metric_keys' => ['revenue', 'room_nights', 'orders', 'adr', 'aov'],
            'summary' => trim((string)($businessForecast['message'] ?? '')),
        ];

        $expectedMetric = match ((string)($product['key'] ?? '')) {
            'demand-forecast' => 'orders',
            'cancellation-risk' => 'cancellation_rate',
            'price-elasticity' => 'ota_adr',
            'channel-attribution', 'customer-segmentation' => 'orders',
            'ltv' => 'ota_revenue',
            'anomaly-detection' => 'data_completeness',
            'service-quality' => 'avg_psi_score',
            default => '',
        };

        return [
            'scope' => 'ota_channel',
            'hotel_id' => $hotelId,
            'platform' => 'ota',
            'date_range' => $dateRange,
            'basis_summary' => $decisionReady
                ? '依据本酒店可追溯的OTA渠道日级数据、规则预测基线及研究方向数据源生成。'
                : '当前OTA渠道证据未达到决策条件，建议仅用于补数或核验，不能扩大为全酒店结论。',
            'evidence_sources' => $evidenceSources,
            'default_priority' => $decisionReady ? 'P1' : 'P0',
            'default_risk_level' => $decisionReady ? 'medium' : 'high',
            'review_window' => '最迟于 ' . date('Y-m-d', strtotime('+1 day')) . ' 按同酒店、同OTA渠道和同指标口径复核',
            'expected_metric' => $expectedMetric,
            'expected_effect_policy' => [
                'status' => 'verification_target',
                'metric' => $expectedMetric,
                'direction' => 'verify',
                'summary' => '预期验证该动作对' . (string)($product['name'] ?? '目标OTA渠道指标') . '的影响；完成同口径复盘前不承诺改善幅度。',
                'review_window' => '最迟于 ' . date('Y-m-d', strtotime('+1 day')) . ' 按同酒店、同OTA渠道和同指标口径复核',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $product
     * @return array<int, string>
     */
    private function knowledgeKeywords(array $product): array
    {
        return array_values(array_unique(array_filter([
            (string)$product['name'],
            '收益管理',
            'OTA',
            '酒店',
        ])));
    }

    /**
     * @param array<string, bool> $columns
     * @param array<int, int> $hotelIds
     */
    private function scopedQuery(string $table, array $columns, array $hotelIds)
    {
        $query = Db::name($table);
        if (!$hotelIds) {
            return $query;
        }
        if ($table === 'online_daily_data' && isset($columns['system_hotel_id'])) {
            return $query->whereIn('system_hotel_id', $hotelIds);
        }
        if (isset($columns['hotel_id'])) {
            return $query->whereIn('hotel_id', $hotelIds);
        }
        return $query;
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

    /**
     * @return array<string, bool>
     */
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
                $columns[(string)$row['Field']] = true;
            }
        }
        $cache[$table] = $columns;
        return $columns;
    }

    /**
     * @param mixed $query
     * @param array<int, string> $fields
     * @param array<int, string> $keywords
     */
    private function applyKeywordRawWhere($query, array $fields, array $keywords, string $prefix): void
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

        if ($parts) {
            $query->whereRaw('(' . implode(' OR ', $parts) . ')', $bind);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, string>>
     */
    private function extractWebSources(array $data): array
    {
        $sources = [];
        $add = static function ($title, $url) use (&$sources): void {
            $url = trim((string)$url);
            if ($url === '' || isset($sources[$url])) {
                return;
            }
            $sources[$url] = [
                'title' => trim((string)$title) ?: $url,
                'url' => $url,
            ];
        };

        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['action']['sources'] ?? []) as $source) {
                if (is_array($source)) {
                    $add($source['title'] ?? '', $source['url'] ?? '');
                }
            }
            foreach (($item['content'] ?? []) as $content) {
                if (!is_array($content)) {
                    continue;
                }
                foreach (($content['annotations'] ?? []) as $annotation) {
                    if (is_array($annotation) && ($annotation['type'] ?? '') === 'url_citation') {
                        $add($annotation['title'] ?? '', $annotation['url'] ?? '');
                    }
                }
            }
        }

        return array_slice(array_values($sources), 0, 10);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractOutputText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return trim($data['output_text']);
        }
        $parts = [];
        foreach (($data['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (($item['content'] ?? []) as $content) {
                if (is_array($content) && isset($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function extractJsonText(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }

    private function numberValue($value): float
    {
        return $this->nullableNumberValue($value) ?? 0.0;
    }

    private function nullableNumberValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }
        $normalized = preg_replace('/[^0-9.\-]/', '', $text) ?? '';
        return $normalized !== '' && is_numeric($normalized) ? (float)$normalized : null;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function stringList($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }
        $result = [];
        foreach ($value as $item) {
            $text = trim(is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE));
            if ($text !== '') {
                $result[] = $text;
            }
        }
        return $result;
    }

    private function llmErrorCode(string $message): int
    {
        return preg_match('/配置|API Key|AI_CONFIG_SECRET|未找到|模型|启用|Base URL/u', $message) ? 422 : 502;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/sk-[A-Za-z0-9_\-]{8,}/', 'sk-****', $message) ?? $message;
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ****', $message) ?? $message;
        return mb_substr(trim($message), 0, 300);
    }
}
