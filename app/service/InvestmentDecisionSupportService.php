<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class InvestmentDecisionSupportService
{
    private const OPERATING_MODULE_KEYS = ['ai_daily_report', 'revenue_pricing', 'operation_execution'];

    public function overview(array $hotelIds, ?int $hotelId, int $userId, bool $isSuperAdmin): array
    {
        $closureOverview = (new BusinessClosureOverviewService())->overview($hotelIds, $hotelId, $userId, $isSuperAdmin);

        return $this->buildOverviewFromEvidence(
            $closureOverview,
            $this->readExpansionRecords($userId, $isSuperAdmin),
            $this->readTransferRecords($hotelIds, $hotelId),
            $this->readFeasibilityRecords($userId, $isSuperAdmin),
            $this->readCompetitorEvidence($hotelIds, $hotelId)
        );
    }

    public function buildOverviewFromEvidence(
        array $closureOverview,
        array $expansionEvidence = [],
        array $transferEvidence = [],
        array $feasibilityEvidence = [],
        array $competitorEvidence = []
    ): array {
        $modules = $this->modulesByKey((array)($closureOverview['modules'] ?? []));
        $operatingGate = $this->buildOperatingGate($closureOverview, $modules);
        $singleStoreQuality = $this->buildSingleStoreQuality($operatingGate, $modules, $closureOverview);
        $competitorComparison = $this->buildCompetitorComparison($operatingGate, $modules, $competitorEvidence);
        $investmentCalculation = $this->buildInvestmentCalculation($operatingGate, $expansionEvidence, $transferEvidence, $feasibilityEvidence);
        $decisionRecords = $this->buildDecisionRecords($operatingGate, $investmentCalculation['records']);
        $businessClosureChain = $this->buildBusinessClosureChain(
            $closureOverview,
            $modules,
            $operatingGate,
            $singleStoreQuality,
            $competitorComparison,
            $investmentCalculation,
            $decisionRecords
        );
        $riskAlerts = $this->buildRiskAlerts($operatingGate, $competitorComparison, $investmentCalculation, $decisionRecords, $closureOverview);
        $actionQueue = $this->buildActionQueue(
            $businessClosureChain,
            $operatingGate,
            $competitorComparison,
            $investmentCalculation,
            $decisionRecords,
            $riskAlerts
        );

        $decisionAllowed = (bool)$operatingGate['can_use_for_investment_judgement']
            && (int)$decisionRecords['eligible_count'] > 0
            && (int)$riskAlerts['blocking_count'] === 0;

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'source_scope' => 'closed_operating_data_only',
            'protected_boundary' => 'P4 reads closed operation, expansion, transfer and feasibility records only; it does not trigger OTA collection, does not write OTA data, and does not turn missing evidence into investment conclusions.',
            'summary' => [
                'status' => $decisionAllowed ? 'decision_ready' : 'not_ready',
                'decision_allowed' => $decisionAllowed,
                'closed_operating_data_ready' => (bool)$operatingGate['can_use_for_investment_judgement'],
                'single_store_quality_status' => $singleStoreQuality['status'],
                'competitor_comparison_status' => $competitorComparison['status'],
                'investment_calculation_status' => $investmentCalculation['status'],
                'business_closure_chain_status' => $businessClosureChain['status'],
                'business_closure_chain_ready_count' => (int)$businessClosureChain['closed_stage_count'],
                'business_closure_chain_stage_count' => (int)$businessClosureChain['stage_count'],
                'risk_blocking_count' => (int)$riskAlerts['blocking_count'],
                'action_queue_count' => (int)$actionQueue['item_count'],
                'action_queue_blocking_count' => (int)$actionQueue['blocking_count'],
                'decision_record_count' => (int)$decisionRecords['record_count'],
                'eligible_decision_record_count' => (int)$decisionRecords['eligible_count'],
            ],
            'business_closure_chain' => $businessClosureChain,
            'action_queue' => $actionQueue,
            'operating_data_gate' => $operatingGate,
            'sections' => [
                'single_store_quality' => $singleStoreQuality,
                'competitor_comparison' => $competitorComparison,
                'investment_calculation' => $investmentCalculation,
                'risk_alerts' => $riskAlerts,
                'decision_records' => $decisionRecords,
            ],
        ];
    }

    private function buildOperatingGate(array $closureOverview, array $modules): array
    {
        $p0DownstreamGate = is_array($closureOverview['p0_downstream_gate'] ?? null)
            ? (new P0OtaDownstreamGateService())->normalize($closureOverview['p0_downstream_gate'])
            : [];
        $p0Blocked = (string)($p0DownstreamGate['status'] ?? '') === 'blocked_by_p0_ota_gate';
        $operation = $modules['operation_execution'] ?? [];
        $operationRoiEvidenceReady = (bool)($operation['roi_ready'] ?? false)
            || (int)($operation['roi_ready_count'] ?? 0) > 0
            || (int)($closureOverview['summary']['operation_roi_ready'] ?? 0) > 0;
        $processClosedEvidenceReady = (bool)($operation['process_closed_loop'] ?? false)
            || (string)($operation['process_status'] ?? '') === 'closed';
        $operationRoiReady = !$p0Blocked && $operationRoiEvidenceReady;
        $processClosed = !$p0Blocked && $processClosedEvidenceReady;

        $missing = [];
        if ($p0Blocked) {
            $missing[] = [
                'code' => 'p0_ota_gate_not_ready',
                'label' => 'P0 OTA字段闭环门禁',
                'next_action' => '先完成授权浏览器 Profile 登录态、目标日 OTA/流量入库和 P0 field-loop verifier ready，再进入投决判断。',
            ];
        }
        if (!$operationRoiReady) {
            $missing[] = [
                'code' => 'closed_operating_roi_missing',
                'label' => '闭合经营 ROI 证据',
                'next_action' => '先在运营执行中完成审批、执行证据、效果复盘和 ROI/增量收益证据。',
            ];
        }
        if (!$processClosed) {
            $missing[] = [
                'code' => 'operation_process_closure_missing',
                'label' => '运营执行过程闭环',
                'next_action' => '先把 AI/人工建议转执行单，并完成执行、证据和复盘。',
            ];
        }

        $signals = [];
        foreach (self::OPERATING_MODULE_KEYS as $key) {
            $module = $modules[$key] ?? [];
            $signals[] = [
                'key' => $key,
                'label' => (string)($module['label'] ?? $key),
                'status' => (string)($module['status'] ?? 'not_loaded'),
                'status_label' => (string)($module['status_label'] ?? ''),
                'process_closed_loop' => (bool)($module['process_closed_loop'] ?? false),
                'roi_ready' => (bool)($module['roi_ready'] ?? false),
                'record_count' => (int)($module['record_count'] ?? 0),
                'roi_ready_count' => (int)($module['roi_ready_count'] ?? 0),
                'source_scope' => (string)($module['source_scope'] ?? ''),
            ];
        }

        $status = $p0Blocked
            ? 'blocked_by_p0_ota_gate'
            : ($operationRoiReady
            ? 'closed_operating_data_ready'
            : ($processClosed ? 'process_closed_missing_roi' : 'not_ready'));

        return [
            'status' => $status,
            'status_label' => $p0Blocked ? 'P0未就绪' : ($operationRoiReady ? '可进入投决读取' : '未达到投决准入'),
            'can_use_for_investment_judgement' => $operationRoiReady,
            'required_gate' => $p0Blocked ? 'p0_ota_field_loop.ready + operation_execution.roi_ready' : 'operation_execution.roi_ready',
            'source_scope' => 'OTA -> revenue -> AI/manual decision -> operation execution -> review/ROI',
            'missing_evidence' => $missing,
            'signals' => $signals,
            'p0_downstream_gate' => $p0DownstreamGate,
        ];
    }

    private function buildBusinessClosureChain(
        array $closureOverview,
        array $modules,
        array $operatingGate,
        array $singleStoreQuality,
        array $competitorComparison,
        array $investmentCalculation,
        array $decisionRecords
    ): array {
        $aiDaily = $modules['ai_daily_report'] ?? [];
        $revenue = $modules['revenue_pricing'] ?? [];
        $operation = $modules['operation_execution'] ?? [];
        $closureDataGaps = array_values((array)($closureOverview['data_gaps'] ?? []));
        $p0Blocked = (string)($operatingGate['status'] ?? '') === 'blocked_by_p0_ota_gate';
        $p0MissingEvidence = $p0Blocked ? [[
            'code' => 'p0_ota_gate_not_ready',
            'label' => 'P0 OTA字段闭环门禁',
            'next_action' => '先完成 P0 field-loop verifier ready，再声明收益、AI、运营或投决闭环。',
        ]] : [];

        $otaRecordCount = (int)($aiDaily['record_count'] ?? 0) + (int)($revenue['record_count'] ?? 0);
        $otaComplete = !$p0Blocked && $otaRecordCount > 0 && $closureDataGaps === [];
        $otaMissing = $closureDataGaps;
        if ($p0Blocked) {
            $otaMissing = array_merge($otaMissing, $p0MissingEvidence);
        }
        if ($otaRecordCount <= 0) {
            $otaMissing[] = [
                'code' => 'ota_operating_samples_missing',
                'label' => 'OTA经营样本',
                'next_action' => '先形成可追溯的 OTA 经营记录，再进入收益分析和投决辅助。',
            ];
        }

        $revenueRoiReady = !$p0Blocked && ((bool)($revenue['roi_ready'] ?? false) || (int)($revenue['roi_ready_count'] ?? 0) > 0);
        $revenueProcessClosed = !$p0Blocked && (bool)($revenue['process_closed_loop'] ?? false);
        $revenueStatus = $p0Blocked
            ? 'blocked_by_p0_ota_gate'
            : ($revenueRoiReady
            ? 'roi_ready'
            : ($revenueProcessClosed ? 'process_closed_missing_roi' : ((int)($revenue['record_count'] ?? 0) > 0 ? 'record_only' : 'data_gap')));

        $aiProcessClosed = !$p0Blocked && ((bool)($aiDaily['process_closed_loop'] ?? false) || (bool)($aiDaily['roi_ready'] ?? false));
        $aiStatus = $p0Blocked
            ? 'blocked_by_p0_ota_gate'
            : ($aiProcessClosed
            ? 'process_closed'
            : ((int)($aiDaily['record_count'] ?? 0) > 0 ? 'record_only' : 'data_gap'));

        $operationComplete = !$p0Blocked && (bool)$operatingGate['can_use_for_investment_judgement'];
        $investmentComplete = !$p0Blocked && (int)($decisionRecords['eligible_count'] ?? 0) > 0 && (bool)($investmentCalculation['decision_allowed'] ?? false);

        $stages = [
            $this->businessChainStage(
                'ota_data',
                'OTA数据',
                '事实来源',
                $otaComplete ? 'evidence_available' : 'data_gap',
                $otaComplete,
                false,
                '读取 AI日报与收益调价模块中的 OTA/经营记录信号，不触发携程或美团采集。',
                ['ai_daily_report', 'revenue_pricing'],
                [
                    ['label' => '关联记录', 'value' => $otaRecordCount],
                    ['label' => '缺口', 'value' => count($otaMissing)],
                ],
                $otaMissing,
                'single_store_quality'
            ),
            $this->businessChainStage(
                'revenue_analysis',
                '收益分析',
                '经营判断',
                $revenueStatus,
                $revenueRoiReady,
                $p0Blocked,
                '收益分析必须保留调价建议、执行关联和 ROI/增量收益证据；未闭合时只能作为辅助信号。',
                ['revenue_pricing'],
                [
                    ['label' => '收益记录', 'value' => (int)($revenue['record_count'] ?? 0)],
                    ['label' => 'ROI闭合', 'value' => (int)($revenue['roi_ready_count'] ?? 0)],
                ],
                $revenueRoiReady ? [] : array_merge($p0MissingEvidence, [[
                    'code' => 'revenue_analysis_roi_missing',
                    'label' => '收益分析 ROI 证据',
                    'next_action' => '把收益建议关联到执行闭环，并补齐复盘后的 ROI/增量收益证据。',
                ]]),
                'competitor_comparison'
            ),
            $this->businessChainStage(
                'ai_decision',
                'AI决策',
                '决策建议',
                $aiStatus,
                $aiProcessClosed,
                $p0Blocked,
                'AI建议必须能回到日报、建议或人工复核记录；未转执行闭环时不得直接进入投决结论。',
                ['ai_daily_report'],
                [
                    ['label' => 'AI记录', 'value' => (int)($aiDaily['record_count'] ?? 0)],
                    ['label' => '过程闭环', 'value' => $aiProcessClosed ? 1 : 0],
                ],
                $aiProcessClosed ? [] : array_merge($p0MissingEvidence, [[
                    'code' => 'ai_decision_closure_missing',
                    'label' => 'AI决策执行闭环',
                    'next_action' => '将 AI/人工建议转为执行单，并完成执行证据和复盘。',
                ]]),
                'single_store_quality'
            ),
            $this->businessChainStage(
                'operation_management',
                '运营管理',
                '闭环执行',
                (string)($operatingGate['status'] ?? 'not_ready'),
                $operationComplete,
                !$operationComplete,
                'P4 投决准入硬门槛：运营执行必须形成复盘后的 ROI/增量收益证据。',
                ['operation_execution'],
                [
                    ['label' => '执行单', 'value' => (int)($operation['record_count'] ?? 0)],
                    ['label' => 'ROI闭合', 'value' => (int)($operation['roi_ready_count'] ?? 0)],
                ],
                (array)($operatingGate['missing_evidence'] ?? []),
                'single_store_quality'
            ),
            $this->businessChainStage(
                'investment_decision',
                '投资决策',
                '人工复核',
                $investmentComplete ? 'decision_ready' : (string)($decisionRecords['status'] ?? 'not_started'),
                $investmentComplete,
                !$investmentComplete,
                '扩张、转让、可行性记录只有在经营准入与自身 readiness 同时满足后，才允许进入人工投决复核。',
                ['expansion_records', 'transfer_records', 'feasibility_reports'],
                [
                    ['label' => '决策记录', 'value' => (int)($decisionRecords['record_count'] ?? 0)],
                    ['label' => '可复核', 'value' => (int)($decisionRecords['eligible_count'] ?? 0)],
                ],
                $investmentComplete ? [] : array_merge($p0MissingEvidence, [[
                    'code' => 'investment_decision_readiness_missing',
                    'label' => '投决记录准入',
                    'next_action' => '补齐扩张、转让或可行性记录的 readiness 证据，并确认经营数据准入。',
                ]]),
                'decision_records'
            ),
        ];

        $closedCount = count(array_filter($stages, static fn(array $stage): bool => (bool)($stage['closed'] ?? false)));
        $blockingCount = count(array_filter($stages, static fn(array $stage): bool => (bool)($stage['blocking'] ?? false)));

        return [
            'key' => 'business_closure_chain',
            'title' => '业务闭环拆解',
            'status' => $closedCount === count($stages) ? 'closed' : 'not_closed',
            'stage_count' => count($stages),
            'closed_stage_count' => $closedCount,
            'blocking_count' => $blockingCount,
            'source_policy' => 'OTA数据 -> 收益分析 -> AI决策 -> 运营管理 -> 投资决策；缺失阶段必须显式展示，不用兜底值补成闭环。',
            'judgement_gate' => $p0Blocked
                ? 'p0_ota_field_loop.ready + operation_execution.roi_ready + decision_record.readiness_ready'
                : 'operation_execution.roi_ready + decision_record.readiness_ready',
            'stages' => $stages,
        ];
    }

    private function businessChainStage(
        string $key,
        string $title,
        string $role,
        string $status,
        bool $closed,
        bool $blocking,
        string $evidencePolicy,
        array $sourceModules,
        array $metrics,
        array $missingEvidence,
        string $relatedSection
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'role' => $role,
            'status' => $status,
            'closed' => $closed,
            'blocking' => $blocking,
            'evidence_policy' => $evidencePolicy,
            'source_modules' => array_values($sourceModules),
            'metrics' => array_values($metrics),
            'missing_evidence' => array_values($missingEvidence),
            'related_section' => $relatedSection,
        ];
    }

    private function buildSingleStoreQuality(array $operatingGate, array $modules, array $closureOverview): array
    {
        $operation = $modules['operation_execution'] ?? [];
        $revenue = $modules['revenue_pricing'] ?? [];
        $aiDaily = $modules['ai_daily_report'] ?? [];

        return [
            'key' => 'single_store_quality',
            'title' => '单店经营质量',
            'status' => (bool)$operatingGate['can_use_for_investment_judgement'] ? 'usable' : 'blocked',
            'decision_allowed' => (bool)$operatingGate['can_use_for_investment_judgement'],
            'data_policy' => '只读取已闭合经营数据；未形成 ROI/增量收益证据时不输出扩张、转让或投资判断。',
            'metrics' => [
                ['key' => 'operation_execution_total', 'label' => '执行单', 'value' => (int)($closureOverview['summary']['operation_execution_total'] ?? $operation['linked_execution_count'] ?? 0)],
                ['key' => 'operation_roi_ready', 'label' => 'ROI闭合', 'value' => (int)($closureOverview['summary']['operation_roi_ready'] ?? $operation['roi_ready_count'] ?? 0)],
                ['key' => 'revenue_roi_ready', 'label' => '收益复盘', 'value' => (int)($revenue['roi_ready_count'] ?? 0)],
                ['key' => 'ai_report_records', 'label' => 'AI日报记录', 'value' => (int)($aiDaily['record_count'] ?? 0)],
            ],
            'evidence' => [
                'operation_execution' => $this->compactModule($operation),
                'revenue_pricing' => $this->compactModule($revenue),
                'ai_daily_report' => $this->compactModule($aiDaily),
            ],
            'missing_evidence' => (array)($operatingGate['missing_evidence'] ?? []),
        ];
    }

    private function buildCompetitorComparison(array $operatingGate, array $modules, array $competitorEvidence): array
    {
        $sampleCount = (int)($competitorEvidence['sample_count'] ?? 0);
        $revenue = $modules['revenue_pricing'] ?? [];
        $pricingRoiReady = (bool)($revenue['roi_ready'] ?? false) || (int)($revenue['roi_ready_count'] ?? 0) > 0;
        $gateReady = (bool)$operatingGate['can_use_for_investment_judgement'];

        if (!$gateReady) {
            $status = 'blocked_by_operating_closure';
        } elseif ($sampleCount <= 0) {
            $status = 'data_gap';
        } elseif (!$pricingRoiReady) {
            $status = 'supporting_only';
        } else {
            $status = 'usable';
        }

        $missing = [];
        if ($sampleCount <= 0) {
            $missing[] = [
                'code' => 'competitor_sample_missing',
                'label' => '闭合竞对样本',
                'next_action' => '补齐竞对价格、排名或竞品均值样本，并关联收益动作复盘。',
            ];
        }
        if ($sampleCount > 0 && !$pricingRoiReady) {
            $missing[] = [
                'code' => 'competitor_to_pricing_roi_missing',
                'label' => '竞对信号收益复盘',
                'next_action' => '把竞对价差或排名信号转为收益动作，并补齐执行后 ROI/增量收益证据。',
            ];
        }

        return [
            'key' => 'competitor_comparison',
            'title' => '竞对比较',
            'status' => $status,
            'decision_allowed' => $status === 'usable',
            'sample_count' => $sampleCount,
            'latest_at' => (string)($competitorEvidence['latest_at'] ?? ''),
            'data_sources' => array_values((array)($competitorEvidence['data_sources'] ?? [])),
            'source_scope' => 'competitor samples are support evidence only until connected to closed revenue/operation ROI.',
            'table_statuses' => (array)($competitorEvidence['table_statuses'] ?? []),
            'missing_evidence' => array_values(array_merge($missing, (array)($competitorEvidence['data_gaps'] ?? []))),
            'pricing_closure' => $this->compactModule($revenue),
        ];
    }

    private function buildInvestmentCalculation(array $operatingGate, array $expansionEvidence, array $transferEvidence, array $feasibilityEvidence): array
    {
        $records = array_values(array_merge(
            $this->normalizeExpansionRecords((array)($expansionEvidence['records'] ?? [])),
            $this->normalizeTransferRecords((array)($transferEvidence['records'] ?? [])),
            $this->normalizeFeasibilityRecords((array)($feasibilityEvidence['records'] ?? []))
        ));

        usort($records, static function (array $a, array $b): int {
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        });

        $readyCount = count(array_filter($records, static fn(array $record): bool => (bool)($record['readiness_ready'] ?? false)));
        $gateReady = (bool)$operatingGate['can_use_for_investment_judgement'];
        $status = !$gateReady ? 'blocked_by_operating_closure' : ($readyCount > 0 ? 'calculation_ready' : 'readiness_gap');

        return [
            'key' => 'investment_calculation',
            'title' => '投资测算',
            'status' => $status,
            'decision_allowed' => $gateReady && $readyCount > 0,
            'record_count' => count($records),
            'ready_record_count' => $readyCount,
            'source_counts' => [
                'expansion_records' => count((array)($expansionEvidence['records'] ?? [])),
                'transfer_records' => count((array)($transferEvidence['records'] ?? [])),
                'feasibility_reports' => count((array)($feasibilityEvidence['records'] ?? [])),
            ],
            'formula_inventory' => [
                ['scope' => 'single_store_quality', 'formula' => 'closed_operating_data = operation_execution.roi_ready_count > 0'],
                ['scope' => 'transfer', 'formula' => 'payback_months = expected_transfer_price / monthly_net_profit'],
                ['scope' => 'expansion', 'formula' => 'rent_per_room = estimated_rent / target_room_count'],
                ['scope' => 'feasibility', 'formula' => 'RevPAR = ADR * OCC; payback_months from base scenario net cashflow'],
            ],
            'missing_evidence' => array_values(array_merge(
                (array)($expansionEvidence['data_gaps'] ?? []),
                (array)($transferEvidence['data_gaps'] ?? []),
                (array)($feasibilityEvidence['data_gaps'] ?? [])
            )),
            'records' => array_slice($records, 0, 30),
        ];
    }

    private function buildRiskAlerts(array $operatingGate, array $competitorComparison, array $investmentCalculation, array $decisionRecords, array $closureOverview): array
    {
        $risks = [];
        foreach ((array)($operatingGate['missing_evidence'] ?? []) as $gap) {
            $risks[] = $this->risk('closed_operating_data_missing', 'high', (string)($gap['label'] ?? '闭合经营数据缺失'), (string)($gap['next_action'] ?? ''), true);
        }
        foreach ((array)($competitorComparison['missing_evidence'] ?? []) as $gap) {
            $risks[] = $this->risk('competitor_comparison_gap', 'medium', (string)($gap['label'] ?? $gap['code'] ?? '竞对证据缺口'), (string)($gap['next_action'] ?? $gap['message'] ?? ''), false);
        }
        foreach ((array)($investmentCalculation['missing_evidence'] ?? []) as $gap) {
            $risks[] = $this->risk('investment_calculation_gap', 'medium', (string)($gap['label'] ?? $gap['code'] ?? '投资测算证据缺口'), (string)($gap['next_action'] ?? $gap['message'] ?? ''), false);
        }
        foreach ((array)($decisionRecords['records'] ?? []) as $record) {
            if ((bool)($record['judgement_allowed'] ?? false)) {
                continue;
            }
            foreach (array_slice((array)($record['missing_evidence'] ?? []), 0, 2) as $gap) {
                $risks[] = $this->risk('decision_record_gap', 'medium', (string)($gap['label'] ?? $gap['code'] ?? '决策记录证据缺口'), (string)($gap['next_action'] ?? ''), false);
            }
        }
        foreach ((array)($closureOverview['data_gaps'] ?? []) as $gap) {
            $risks[] = $this->risk('business_closure_gap', 'medium', (string)($gap['code'] ?? 'business_closure_gap'), (string)($gap['message'] ?? ''), false);
        }

        $unique = [];
        foreach ($risks as $risk) {
            $key = $risk['code'] . '|' . $risk['title'] . '|' . $risk['next_action'];
            $unique[$key] = $risk;
        }
        $risks = array_values($unique);

        return [
            'key' => 'risk_alerts',
            'title' => '风险提示',
            'status' => empty($risks) ? 'clear' : 'has_risk',
            'blocking_count' => count(array_filter($risks, static fn(array $risk): bool => (bool)($risk['blocking'] ?? false))),
            'items' => array_slice($risks, 0, 12),
        ];
    }

    private function buildActionQueue(
        array $businessClosureChain,
        array $operatingGate,
        array $competitorComparison,
        array $investmentCalculation,
        array $decisionRecords,
        array $riskAlerts
    ): array {
        $items = [];

        foreach ((array)($businessClosureChain['stages'] ?? []) as $stage) {
            if (!is_array($stage)) {
                continue;
            }
            foreach ((array)($stage['missing_evidence'] ?? []) as $gap) {
                $items[] = $this->actionItem(
                    'business_closure_chain',
                    (string)($stage['related_section'] ?? ''),
                    $this->sectionTitle((string)($stage['related_section'] ?? '')),
                    (string)($stage['key'] ?? ''),
                    (string)($stage['title'] ?? ''),
                    is_array($gap) ? $gap : [],
                    (bool)($stage['blocking'] ?? false),
                    (bool)($stage['blocking'] ?? false) ? 'high' : 'medium',
                    (bool)($stage['blocking'] ?? false) ? 1 : 3
                );
            }
        }

        foreach ((array)($operatingGate['missing_evidence'] ?? []) as $gap) {
            $items[] = $this->actionItem('operating_data_gate', 'single_store_quality', '单店经营质量', 'operation_management', '运营管理', is_array($gap) ? $gap : [], true, 'high', 1);
        }

        foreach ((array)($competitorComparison['missing_evidence'] ?? []) as $gap) {
            $items[] = $this->actionItem('competitor_comparison', 'competitor_comparison', '竞对比较', 'revenue_analysis', '收益分析', is_array($gap) ? $gap : [], false, 'medium', 3);
        }

        foreach ((array)($investmentCalculation['missing_evidence'] ?? []) as $gap) {
            $items[] = $this->actionItem('investment_calculation', 'investment_calculation', '投资测算', 'investment_decision', '投资决策', is_array($gap) ? $gap : [], false, 'medium', 3);
        }

        foreach ((array)($decisionRecords['records'] ?? []) as $record) {
            if ((bool)($record['judgement_allowed'] ?? false)) {
                continue;
            }
            foreach (array_slice((array)($record['missing_evidence'] ?? []), 0, 3) as $gap) {
                $items[] = $this->actionItem('decision_records', 'decision_records', '决策记录', 'investment_decision', '投资决策', is_array($gap) ? $gap : [], false, 'medium', 2);
            }
        }

        foreach ((array)($riskAlerts['items'] ?? []) as $risk) {
            if (!is_array($risk)) {
                continue;
            }
            $items[] = $this->actionItem(
                'risk_alerts',
                'risk_alerts',
                '风险提示',
                '',
                '',
                [
                    'code' => (string)($risk['code'] ?? ''),
                    'label' => (string)($risk['title'] ?? ''),
                    'next_action' => (string)($risk['next_action'] ?? ''),
                ],
                (bool)($risk['blocking'] ?? false),
                (string)($risk['severity'] ?? 'medium'),
                (bool)($risk['blocking'] ?? false) ? 1 : 4
            );
        }

        $unique = [];
        foreach ($items as $item) {
            $key = $item['evidence_code'] . '|' . $item['title'] . '|' . $item['next_action'];
            if (!isset($unique[$key]) || (int)$item['priority'] < (int)$unique[$key]['priority']) {
                $unique[$key] = $item;
            }
        }
        $items = array_values($unique);

        usort($items, function (array $a, array $b): int {
            $priority = (int)$a['priority'] <=> (int)$b['priority'];
            if ($priority !== 0) {
                return $priority;
            }
            $blocking = (int)((bool)($b['blocking'] ?? false)) <=> (int)((bool)($a['blocking'] ?? false));
            if ($blocking !== 0) {
                return $blocking;
            }
            $severity = $this->severityRank((string)($a['severity'] ?? 'medium')) <=> $this->severityRank((string)($b['severity'] ?? 'medium'));
            if ($severity !== 0) {
                return $severity;
            }
            return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        return [
            'key' => 'action_queue',
            'title' => '下一步动作队列',
            'status' => empty($items) ? 'clear' : 'has_action',
            'item_count' => count($items),
            'blocking_count' => count(array_filter($items, static fn(array $item): bool => (bool)($item['blocking'] ?? false))),
            'source_policy' => '行动队列只汇总缺口与下一步，不自动创建执行单，不替代人工复核。',
            'items' => array_slice($items, 0, 12),
        ];
    }

    private function actionItem(
        string $source,
        string $sectionKey,
        string $sectionTitle,
        string $stageKey,
        string $stageTitle,
        array $gap,
        bool $blocking,
        string $severity,
        int $priority
    ): array {
        $title = (string)($gap['label'] ?? $gap['title'] ?? $gap['code'] ?? '证据缺口');
        $nextAction = (string)($gap['next_action'] ?? $gap['message'] ?? '补齐证据后再复核。');

        return [
            'key' => $source . ':' . (string)($gap['code'] ?? md5($title . $nextAction)),
            'priority' => max(1, min(5, $priority)),
            'priority_label' => $priority <= 1 ? '先处理' : ($priority === 2 ? '高优先' : ($priority === 3 ? '补证' : '观察')),
            'severity' => $severity,
            'blocking' => $blocking,
            'source' => $source,
            'stage_key' => $stageKey,
            'stage_title' => $stageTitle !== '' ? $stageTitle : '全局',
            'section_key' => $sectionKey,
            'section_title' => $sectionTitle !== '' ? $sectionTitle : '投决辅助',
            'evidence_code' => (string)($gap['code'] ?? ''),
            'title' => $title,
            'next_action' => $nextAction,
        ];
    }

    private function buildDecisionRecords(array $operatingGate, array $records): array
    {
        $gateReady = (bool)$operatingGate['can_use_for_investment_judgement'];
        $rows = [];
        foreach ($records as $record) {
            $ready = (bool)($record['readiness_ready'] ?? false);
            $allowed = $gateReady && $ready;
            $rows[] = array_merge($record, [
                'judgement_allowed' => $allowed,
                'blocked_reason' => $allowed ? '' : ($gateReady ? 'readiness_evidence_missing' : 'closed_operating_data_missing'),
                'decision_policy' => $allowed
                    ? '可进入人工投决复核；仍需保留记录和投后跟踪。'
                    : '仅展示记录和缺口，不输出扩张、转让或投资判断。',
            ]);
        }

        return [
            'key' => 'decision_records',
            'title' => '决策记录',
            'status' => empty($rows) ? 'not_started' : ($gateReady ? 'records_visible' : 'blocked_by_operating_closure'),
            'record_count' => count($rows),
            'eligible_count' => count(array_filter($rows, static fn(array $record): bool => (bool)($record['judgement_allowed'] ?? false))),
            'records' => $rows,
        ];
    }

    private function readExpansionRecords(int $userId, bool $isSuperAdmin): array
    {
        if (!$this->tableExists('expansion_records')) {
            return $this->tableGap('expansion_records');
        }

        try {
            $query = Db::name('expansion_records')->whereNull('deleted_at');
            if (!$isSuperAdmin) {
                $query->where('created_by', $userId > 0 ? $userId : -1);
            }
            $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
            $service = new ExpansionService();
            return [
                'status' => 'ok',
                'records' => array_map(fn(array $row): array => $this->formatExpansionRecord($row, $service), $rows),
            ];
        } catch (Throwable $e) {
            return $this->readFailed('expansion_records');
        }
    }

    private function readTransferRecords(array $hotelIds, ?int $hotelId): array
    {
        if (!$this->tableExists('transfer_records')) {
            return $this->tableGap('transfer_records');
        }

        try {
            $query = Db::name('transfer_records')->whereNull('deleted_at');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
            $service = new TransferDecisionService();
            return [
                'status' => 'ok',
                'records' => array_map(fn(array $row): array => $this->formatTransferRecord($row, $service), $rows),
            ];
        } catch (Throwable $e) {
            return $this->readFailed('transfer_records');
        }
    }

    private function readFeasibilityRecords(int $userId, bool $isSuperAdmin): array
    {
        if (!$this->tableExists('feasibility_reports')) {
            return $this->tableGap('feasibility_reports');
        }

        try {
            $query = Db::name('feasibility_reports')->whereNull('deleted_at');
            if (!$isSuperAdmin) {
                $query->where('created_by', $userId > 0 ? $userId : -1);
            }
            $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
            $service = new FeasibilityReportService();
            return [
                'status' => 'ok',
                'records' => array_map(fn(array $row): array => $this->formatFeasibilityRecord($row, $service), $rows),
            ];
        } catch (Throwable $e) {
            return $this->readFailed('feasibility_reports');
        }
    }

    private function readCompetitorEvidence(array $hotelIds, ?int $hotelId): array
    {
        $sampleCount = 0;
        $latest = '';
        $sources = [];
        $tableStatuses = [];
        $gaps = [];

        foreach ([
            ['table' => 'competitor_analysis', 'field' => 'hotel_id', 'date' => 'analysis_date'],
            ['table' => 'competitor_price_log', 'field' => 'hotel_id', 'date' => 'created_at'],
            ['table' => 'online_daily_data', 'field' => 'system_hotel_id', 'date' => 'data_date', 'competitor_filter' => true],
        ] as $source) {
            $table = $source['table'];
            if (!$this->tableExists($table)) {
                $tableStatuses[$table] = 'missing_table';
                $gaps[] = ['code' => $table . '_missing', 'message' => $table . ' table missing.'];
                continue;
            }

            try {
                $query = Db::name($table);
                $this->applyHotelScope($query, $hotelIds, $hotelId, (string)$source['field']);
                if (($source['competitor_filter'] ?? false) === true) {
                    $query->where(function ($q): void {
                        $q->where('data_type', 'competitor')
                            ->whereOr('compare_type', 'competitor_avg')
                            ->whereOr('hotel_name', 'like', '%竞争圈%');
                    });
                }
                $count = (int)(clone $query)->count();
                $sourceLatest = (string)((clone $query)->max((string)$source['date']) ?: '');
                $sampleCount += $count;
                if ($count > 0) {
                    $sources[] = ['table' => $table, 'count' => $count, 'latest_at' => $sourceLatest];
                    $latest = $this->maxDateString($latest, $sourceLatest);
                }
                $tableStatuses[$table] = 'ok';
            } catch (Throwable $e) {
                $tableStatuses[$table] = 'read_failed';
                $gaps[] = ['code' => $table . '_read_failed', 'message' => $table . ' summary read failed.'];
            }
        }

        return [
            'status' => $sampleCount > 0 ? 'ok' : 'data_gap',
            'sample_count' => $sampleCount,
            'latest_at' => $latest,
            'data_sources' => $sources,
            'table_statuses' => $tableStatuses,
            'data_gaps' => $gaps,
        ];
    }

    private function formatExpansionRecord(array $row, ExpansionService $service): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        $result = $this->decodeJson($row['result_json'] ?? []);
        $readiness = $service->buildProjectReadiness((string)($row['record_type'] ?? ''), $input, $result);

        return [
            'source_module' => 'expansion',
            'id' => (int)($row['id'] ?? 0),
            'record_type' => (string)($row['record_type'] ?? ''),
            'title' => (string)($row['project_name'] ?? $input['project_name'] ?? '扩张记录'),
            'decision' => (string)($row['decision'] ?? $result['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? $result['investment_risk_level'] ?? ''),
            'readiness' => $readiness,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
        ];
    }

    private function formatTransferRecord(array $row, TransferDecisionService $service): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        $result = $this->decodeJson($row['result_json'] ?? []);
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? []);
        $readiness = $service->buildDecisionReadiness((string)($row['record_type'] ?? ''), $input, $result, $snapshot, (int)($row['hotel_id'] ?? 0));

        return [
            'source_module' => 'transfer',
            'id' => (int)($row['id'] ?? 0),
            'record_type' => (string)($row['record_type'] ?? ''),
            'title' => (string)($row['hotel_name'] ?? $input['hotel_name'] ?? '转让记录'),
            'decision' => (string)($row['decision'] ?? $result['suggested_action'] ?? $result['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? $result['risk_level'] ?? ''),
            'readiness' => $readiness,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
        ];
    }

    private function formatFeasibilityRecord(array $row, FeasibilityReportService $service): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? []);
        $report = $this->decodeJson($row['report_json'] ?? []);
        $readiness = $service->buildFeasibilityReadiness($input, $snapshot, $report);

        return [
            'source_module' => 'feasibility_report',
            'id' => (int)($row['id'] ?? 0),
            'record_type' => 'feasibility',
            'title' => (string)($row['project_name'] ?? $input['project_name'] ?? '可行性报告'),
            'decision' => (string)($row['conclusion_grade'] ?? $report['conclusion_grade'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? $this->riskLevelFromGrade((string)($row['conclusion_grade'] ?? $report['conclusion_grade'] ?? ''))),
            'readiness' => $readiness,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
        ];
    }

    private function normalizeExpansionRecords(array $records): array
    {
        return array_map(function (array $record): array {
            $readiness = is_array($record['readiness'] ?? null) ? $record['readiness'] : (array)($record['project_readiness'] ?? []);
            return $this->normalizeDecisionRecord($record, $readiness, (bool)($readiness['project_ready'] ?? false), 'expansion_screening_and_project_decision');
        }, $records);
    }

    private function normalizeTransferRecords(array $records): array
    {
        return array_map(function (array $record): array {
            $readiness = is_array($record['readiness'] ?? null) ? $record['readiness'] : (array)($record['decision_readiness'] ?? []);
            return $this->normalizeDecisionRecord($record, $readiness, (bool)($readiness['decision_ready'] ?? false), (string)($readiness['source_scope'] ?? 'transfer_decision_scope'));
        }, $records);
    }

    private function normalizeFeasibilityRecords(array $records): array
    {
        return array_map(function (array $record): array {
            $readiness = is_array($record['readiness'] ?? null) ? $record['readiness'] : (array)($record['feasibility_readiness'] ?? []);
            return $this->normalizeDecisionRecord($record, $readiness, (bool)($readiness['feasibility_ready'] ?? false), (string)($readiness['source_scope'] ?? 'feasibility_report_scope'));
        }, $records);
    }

    private function normalizeDecisionRecord(array $record, array $readiness, bool $ready, string $sourceScope): array
    {
        return [
            'source_module' => (string)($record['source_module'] ?? ''),
            'id' => (int)($record['id'] ?? 0),
            'record_type' => (string)($record['record_type'] ?? ''),
            'title' => (string)($record['title'] ?? $record['project_name'] ?? $record['hotel_name'] ?? ''),
            'decision' => (string)($record['decision'] ?? ''),
            'risk_level' => (string)($record['risk_level'] ?? ''),
            'readiness_stage' => (string)($readiness['stage'] ?? ''),
            'readiness_score' => (int)($readiness['score'] ?? 0),
            'readiness_status_label' => (string)($readiness['status_label'] ?? ''),
            'readiness_ready' => $ready,
            'source_scope' => (string)($readiness['source_scope'] ?? $sourceScope),
            'missing_evidence' => array_values((array)($readiness['missing_evidence'] ?? [])),
            'created_at' => (string)($record['created_at'] ?? ''),
            'updated_at' => (string)($record['updated_at'] ?? ''),
        ];
    }

    private function modulesByKey(array $modules): array
    {
        $result = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            $key = (string)($module['key'] ?? '');
            if ($key !== '') {
                $result[$key] = $module;
            }
        }
        return $result;
    }

    private function compactModule(array $module): array
    {
        return [
            'key' => (string)($module['key'] ?? ''),
            'label' => (string)($module['label'] ?? ''),
            'status' => (string)($module['status'] ?? 'not_loaded'),
            'status_label' => (string)($module['status_label'] ?? ''),
            'record_count' => (int)($module['record_count'] ?? 0),
            'process_closed_loop' => (bool)($module['process_closed_loop'] ?? false),
            'roi_ready' => (bool)($module['roi_ready'] ?? false),
            'roi_ready_count' => (int)($module['roi_ready_count'] ?? 0),
            'source_scope' => (string)($module['source_scope'] ?? ''),
            'data_gaps' => array_values((array)($module['data_gaps'] ?? [])),
        ];
    }

    private function risk(string $code, string $severity, string $title, string $nextAction, bool $blocking): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'next_action' => $nextAction,
            'blocking' => $blocking,
        ];
    }

    private function tableGap(string $table): array
    {
        return [
            'status' => 'missing_table',
            'records' => [],
            'data_gaps' => [
                ['code' => $table . '_missing', 'message' => $table . ' table missing.'],
            ],
        ];
    }

    private function readFailed(string $table): array
    {
        return [
            'status' => 'read_failed',
            'records' => [],
            'data_gaps' => [
                ['code' => $table . '_read_failed', 'message' => $table . ' summary read failed.'],
            ],
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function applyHotelScope($query, array $hotelIds, ?int $hotelId, string $field): void
    {
        if ($hotelId !== null && $hotelId > 0) {
            $query->where($field, $hotelId);
            return;
        }

        $hotelIds = array_values(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0));
        if ($hotelIds !== []) {
            $query->whereIn($field, $hotelIds);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array)$value;
        }

        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function maxDateString(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }
        return $left >= $right ? $left : $right;
    }

    private function sectionTitle(string $sectionKey): string
    {
        return match ($sectionKey) {
            'single_store_quality' => '单店经营质量',
            'competitor_comparison' => '竞对比较',
            'investment_calculation' => '投资测算',
            'risk_alerts' => '风险提示',
            'decision_records' => '决策记录',
            default => '投决辅助',
        };
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };
    }

    private function riskLevelFromGrade(string $grade): string
    {
        return match (strtoupper(trim($grade))) {
            'A' => '低风险',
            'B' => '中风险',
            'C' => '中高风险',
            'D' => '高风险',
            default => '',
        };
    }
}
