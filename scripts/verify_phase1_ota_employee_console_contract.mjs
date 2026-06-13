import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function packageScript(name, command) {
  let actual = '';
  try {
    actual = JSON.parse(read('package.json')).scripts?.[name] ?? '';
  } catch {
    actual = '';
  }
  check('package.json', `package script ${name}`, actual === command, `${name}: ${command}`);
}

includesAll('docs/phase1_ota_employee_console_acceptance.md', 'employee six questions and explicit non-completion rules are documented', [
  '员工视角必须回答六个问题',
  '今天携程、美团 OTA 数据有没有采到',
  '哪些字段可信',
  '哪些字段缺失、失败、未授权或未采集',
  'AI 建议依据',
  '下一步该执行什么动作',
  'direct_next_action_code',
  'primary_next_action_code',
  'linked_action_count',
  'employee_explanation',
  'limited_conclusions',
  'still_usable_metrics',
  'explanation_next_action',
  'live_closure_gap_codes',
  'verify:public-entry',
  'verify:e2e-contracts',
  '真实当天携程/美团采集样例',
  'source_date_evidence.platforms',
  'source_date_evidence_missing',
  'entry_options[].readiness',
  'requires_user_context',
  'profile_found_login_unverified',
  'read_local_profile_directory_names_only',
  '只有字段资产定义或字段列表，不能直接声明字段可信',
  'metric_trust',
  'metric_trust_keys',
  'field_definition_keys',
  'missing_field_codes',
  'data_gap_codes',
  'success_criteria',
  '历史分析样本或汇总卡片只能作为参考',
  'evidence_sources',
  '响应缺少 `data_gaps` 字段',
  '追溯到 `ota_diagnosis` 的可执行 `action_items`',
  'blocked_by_*',
  '不能声明 AI 建议可执行',
  'data_gaps',
  'operation_execution_evidence_incomplete',
  '不允许用空值、默认值、成功文案或本地兜底分析替代',
]);

includesAll('route/app.php', 'employee workflow routes exist', [
  "Route::get('/collection-reliability', 'OnlineData/collectionReliability');",
  "Route::get('/data-analysis', 'OnlineData/dataAnalysis');",
  "Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics');",
  "Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');",
  "Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');",
  "Route::get('/execution-flow', 'OperationManagement/executionFlow');",
]);

includesAll('public/index.html', 'data health UI exposes collection state, field assets, and next actions', [
  'collectionHealthSummaryCards',
  'collectionHealthStatusText',
  'collectionHealthFieldAssetCards',
  'collectionHealthFieldAssetListText',
  'collectionHealthFailureReasonRanking',
  'collectionHealthPendingActions',
  'phase1-employee-six-question-summary',
  'phase1-employee-closure-summary',
  'phase1-employee-collection-source-summary',
  'phase1-employee-field-trust-summary',
  'phase1-employee-missing-field-summary',
  'phase1-employee-metric-domain-summary',
  'phase1-employee-ai-evidence-summary',
  'phase1-employee-operation-summary',
  'phase1-employee-next-required-actions',
  'phase1EmployeeQuestionRows',
  'phase1EmployeeRequiredActions',
  'phase1EmployeeClosureSummary',
  'backendSummary',
  'backendQuestionSource?.closure_summary',
  'phase1EmployeeCollectionSourceRows',
  'phase1EmployeeFieldTrustRows',
  'phase1EmployeeMissingFieldRows',
  'phase1EmployeeMissingFieldOverflowText',
  'phase1EmployeeMetricDomainRows',
  'phase1EmployeeAiEvidenceSummary',
  'phase1EmployeeOperationSummary',
  'phase1EmployeeBackendQuestionRow',
  'phase1EmployeeCountItem',
  'phase1EmployeeQuestionStatusText',
  'phase1EmployeeQuestionEvidenceText',
  'phase1_employee_questions',
  'closure_summary',
  'collection_source_summary',
  'normalizePhase1CollectionSourceSummaryRow',
  'normalizePhase1EmployeeFieldTrustRow',
  'phase1FieldTrustStatusText',
  'phase1FieldTrustStatusClass',
  'normalizePhase1EmployeeMissingFieldRow',
  'phase1MissingFieldLabel',
  'normalizePhase1EmployeeMetricDomainRow',
  'phase1MetricDomainStatusText',
  'phase1MetricDomainStatusClass',
  'phase1MetricDomainMissingLabel',
  'next_required_actions',
  'dashboardDataSources.value?.phase1_employee_questions',
  'normalizePhase1EmployeeQuestionRow',
  'mergePhase1EmployeeQuestionRow',
  'normalizedLocalRows',
  'normalizePhase1EmployeeRequiredAction',
  'phase1EmployeeActionEntryText',
  'phase1EmployeeActionEntryOptionText',
  'phase1LocalActionMeta',
  'buildPhase1LocalRequiredAction',
  'local_ui_derived_from_employee_questions',
  '不代表后端采集成功',
  'phase1EmployeeActionFamilyText',
  'primary_next_action_code',
  'direct_next_action_code',
  'linked_action_count',
  '直接动作',
  '先处理动作',
  'actionCode',
  'actionCodes',
  'actionFamily',
  'actionFamilyText',
  'platform: String(item?.platform || \'\').toUpperCase()',
  '类型：',
  'primaryNextActionEntry',
  'directNextActionEntry',
  'primaryNextActionSuccessCriteria',
  'directNextActionSuccessCriteria',
  'blockedActionCodes',
  'employeeExplanation',
  'limitedConclusions',
  'stillUsableMetrics',
  'explanationNextAction',
  'liveClosureGapCodes',
  '员工解释：',
  '受限结论：',
  '仍可使用：',
  '补证据动作：',
  '巡检缺口：',
  'entry: String(item?.entry || \'\')',
  'entryText: phase1EmployeeActionEntryText(item?.entry || \'\', item)',
  "const entryOptions = Array.isArray(item?.entry_options)",
  'item.entry_options.map(phase1EmployeeActionEntryOptionText)',
  'phase1EmployeeActionEntryOptionModeText',
  'phase1EmployeeActionEntryOptionRawText',
  'entryOptionsRawText',
  'phase1EmployeeActionEntryOptionGuidanceText',
  'phase1EmployeeActionEntryOptionReadinessText',
  'phase1EmployeeReadinessStatusText',
  'phase1EmployeeReadinessEvidenceText',
  'entryOptionGuidanceText',
  'entryReadinessText',
  'readiness.can_run_now === true',
  '可直接执行',
  '需先准备',
  'readiness.profile_count',
  'readiness.evidence',
  '需要先提供授权上下文',
  '未找到本机 Profile',
  '只读取本机 Profile 目录数量',
  '只读现有采集可靠性状态',
  '入口选择：',
  '入口状态：',
  'relatedQuestionKeys',
  'relatedQuestionKeysText',
  'phase1EmployeeQuestionKeyText',
  'phase1EmployeeGapCodeText',
  'phase1EmployeeActionCodeText',
  'phase1EmployeeActionSuccessCriteriaText',
  'phase1EmployeeActionEvidenceNeededText',
  'related_question_keys.map(phase1EmployeeQuestionKeyText)',
  'top_action_related_question_keys.map(phase1EmployeeQuestionKeyText)',
  'missing_question_keys) ? backendSummary.missing_question_keys.map',
  'backendMissingQuestionKeys.map(phase1EmployeeQuestionKeyText)',
  'topActionCodeText = phase1EmployeeActionCodeText(topActionCode)',
  'topActionTextRaw',
  'unresolvedQuestionTextRaw',
  'blocking_missing_codes.slice(0, 3).map(phase1EmployeeGapCodeText)',
  'phase1EmployeeActionCodeText(directNextActionCode)',
  'entryOptions,',
  "entryOptionsText: entryOptions.join('、')",
  'successCriteria: String(item?.success_criteria || \'\')',
  'successCriteriaText: phase1EmployeeActionSuccessCriteriaText(item)',
  'evidenceNeededText: phase1EmployeeActionEvidenceNeededText(item)',
  "employeeExplanation: String(item?.employee_explanation || '')",
  'limitedConclusions: Array.isArray(item?.limited_conclusions)',
  'stillUsableMetrics: Array.isArray(item?.still_usable_metrics)',
  "explanationNextAction: String(item?.explanation_next_action || '')",
  'liveClosureGapCodes: Array.isArray(item?.live_closure_gap_codes)',
  'actionCodes: Array.isArray(row?.next_action_codes)',
  'actionCodesText: Array.isArray(row?.next_action_codes)',
  'primaryNextActionText: phase1EmployeeActionCodeText',
  'directNextActionText: phase1EmployeeActionCodeText',
  'primaryNextActionEntry: String(row?.primary_next_action_entry',
  'directNextActionEntry: String(row?.direct_next_action_entry',
  'primaryNextActionEntryText: phase1EmployeeActionEntryText',
  'directNextActionEntryText: phase1EmployeeActionEntryText',
  'primaryNextActionSuccessCriteria: String(row?.primary_next_action_success_criteria',
  'directNextActionSuccessCriteria: String(row?.direct_next_action_success_criteria',
  'primaryNextActionSuccessCriteriaText: phase1EmployeeActionSuccessCriteriaText',
  'directNextActionSuccessCriteriaText: phase1EmployeeActionSuccessCriteriaText',
  'topActionSuccessCriteriaRaw',
  'blockedActionCodes: Array.isArray(row?.blocked_action_codes',
  'blockedActionCodesText: Array.isArray(row?.blocked_action_codes',
  'blockedByActions: Array.isArray(item?.blocked_by_action_codes)',
  'item.blocked_by_action_codes.map(phase1EmployeeActionCodeText)',
  'resolvesMissingCodes: Array.isArray(item?.resolves_missing_codes)',
  'item.resolves_missing_codes.map(phase1EmployeeGapCodeText)',
  'item.live_closure_gap_codes.map(phase1EmployeeGapCodeText)',
  'blockingMissingCodes',
  'blockingGapCodes',
  'blockingReasonText',
  'blocking_gap_codes',
  '未证明原因：',
  'evidenceStatusText',
  'diagnosis_status',
  'action_item_status',
  'source_policy',
  'AI状态',
  '动作状态',
  '证据口径',
  'blocked_by_verified_ota_gaps',
  'read_existing_ota_gap_evidence_only',
  'read_existing_collection_reliability_only',
  'read_existing_operation_execution_state_only',
  'target_date_rows_field_definitions_metric_trust_required',
  'operation_evidence_status',
  'evidenceStatusText(evidence.operation_evidence_status)',
  'row.directNextActionCode',
  'row.directNextActionText',
  'row.primaryNextActionCode',
  'row.primaryNextActionText',
  'row.directNextActionEntry',
  'row.directNextActionEntryText',
  'row.directNextActionSuccessCriteria',
  'row.blockedActionCodes',
  'row.blockedActionCodesText',
  'item.entry',
  'item.entryText',
  'item.entryOptionsText',
  'item.entryReadinessText',
  'item.successCriteria',
  'row.actionCodes',
  'row.actionCodesText',
  'item.blockedByActions',
  'item.resolvesMissingCodes',
  '入口：',
  '直接动作：',
  '先处理动作：',
  '可选入口：',
  '完成判定：',
  '阻断动作：',
  '先处理：',
  '解除缺口：',
  '阻断缺口',
  'source_date_evidence',
  'sourceDateEvidenceAvailable',
  'target_date_source_rows',
  'target_date_platform_coverage',
  'targetDatePlatformCoverageEvidence',
  'evidence.platforms',
  'latest_available',
  'latest_available_reference_only',
  'legacy_status',
  'coverage_status',
  'target_date_complete',
  'target_date_partial',
  'target_date_missing',
  'latest_available_date',
  'latest_available_date_relation',
  'date_relation',
  '平台明细',
  '今日 OTA 经营结论',
  '已证明问题',
  '未完成问题',
  '首要动作',
  '未证明前不输出确定经营结论',
  'latest_available/历史样本只作参考',
  '不改变携程/美团手动或自动获取逻辑，不改变获取字段',
  'top_action_entry',
  'top_action_entry_options',
  'topActionEntryText',
  'topActionEntryOptionsRawText',
  'top_action_success_criteria',
  'topActionEntryOptionsText',
  'topActionEntryOptionGuidanceText',
  'topActionEntryReadinessText',
  'topActionImpactText',
  'topActionResolvesText',
  'topActionLiveGapText',
  'backendSummary.top_action_resolves_missing_codes.map(phase1EmployeeGapCodeText)',
  'backendSummary.top_action_live_closure_gap_codes.map(phase1EmployeeGapCodeText)',
  'phase1EmployeePlatformText',
  'phase1EmployeeDateRelationText',
  'phase1EmployeeSourceSnapshotText',
  'topActionSourceSnapshotText',
  '最近可用只作参考，不能替代目标日入库证明',
  '证明要求：目标日该平台入库行 > 0',
  'phase1EmployeeActionEntryOptionGuidanceText',
  'phase1EmployeeActionEntryOptionReadinessText',
  '可选入口：',
  '入口选择：',
  '入口状态：',
  '影响问题：',
  '解除缺口：',
  '巡检缺口：',
  '当前证据：',
  '证明要求：',
  '平台源数据摘要',
  '字段可信摘要',
  '只按目标日源数据 + metric_trust 判断',
  '缺失字段摘要',
  '来自 data_gaps / missing_field_codes',
  '收入/流量/转化证据摘要',
  '只按目标日 OTA 指标域判断',
  'AI 依据摘要',
  '只读 evidence_sources / data_gaps / action_items',
  '运营执行摘要',
  '只读 execution_intents / execution_flow',
  '证据来源',
  '可执行动作',
  '执行意图',
  '执行流',
  '执行证据',
  'AI 建议必须引用 evidence_sources、data_gaps、action_items',
  '只有可追溯到 OTA diagnosis action_items',
  '不能替代目标日',
  '最近 ',
  '覆盖 ${evidence.coverage_status}',
  'source_date_evidence_available',
  'source_date_evidence_missing',
  'reference_saved_count',
  'reference_replay_count',
  'metric_domain_readiness',
  'read_target_date_online_daily_data_types_only',
  'analysis_rows_reference_only',
  'revenue_ready_platforms',
  'traffic_ready_platforms',
  'conversion_ready_platforms',
  'revenue_missing_platforms',
  'traffic_missing_platforms',
  'conversion_missing_platforms',
  'metric_domain_gap_codes',
  'metric_domain_policy',
  'metric_trust_required',
  'metric_trust_keys',
  'platform_field_trust',
  'platformFieldTrust',
  'field_trust_status',
  '字段可信平台',
  '可复核字段：',
  '未证明原因：',
  '未证明时不把字段写成可信',
  'field_definition_keys',
  'field_pending_action_codes',
  'missing_field_codes',
  'data_gap_codes',
  '显式保留缺口；不使用 0、空值或成功状态替代',
  '另有 ${count - 9} 项缺口未展开',
  'field_trust_policy',
  '/api/ota-standard/revenue-metrics.metric_trust',
  '收益可复核',
  '流量可复核',
  '转化可复核',
  '收益 {{ row.revenueText }}',
  '流量 {{ row.trafficText }}',
  '转化 {{ row.conversionText }}',
  '缺口：',
  '缺失时不输出确定结论',
  '指标域缺失',
  '缺失平台',
  '入库/回放/分析参考',
  '不能证明目标日携程/美团均已采到',
  '目标日',
  'upstream_blockers',
  'requires_target_date_rows_field_definitions_metric_trust_and_data_quality',
  'blocking_missing_codes',
  'phase1AiDiagnosisEvidence',
  'phase1AiDiagnosisEvidence()',
  'phase1DiagnosisActionItemBlocked',
  'actionable_action_item_count',
  'blocked_action_item_count',
  'data_gap_evidence_present',
  'ai_evidence_sources_missing',
  'ai_data_gaps_missing',
  'ai_action_items_blocked',
  "Object.prototype.hasOwnProperty.call(diagnosisResult, 'data_gaps')",
  'operationExecutionPhase1Evidence',
  'operationExecutionPhase1Evidence()',
  'operation_evidence_status',
  'operation_execution_evidence_incomplete',
  'operation_execution_ai_action_link_missing',
  'ota_diagnosis_linked_intent_count',
  'ota_diagnosis_linked_flow_item_count',
  'operation_ai_action_link_required',
  'ai_action_items_ready',
  "operationEvidence.operation_evidence_status !== 'missing'",
  'source_module',
  'ota_diagnosis',
  'approved_count',
  'execution_evidence_count',
  'blocked_by',
  '阻断',
  '员工六问闭环',
  '今天 OTA 数据有没有采到',
  'AI 建议依据是什么',
  '下一步该执行什么动作',
  'collectionHealthCtripMissingActionRows',
  'evidence_needed',
  'protected_boundary',
  '负责人：',
  '所需证据：',
  '边界：',
]);

includesAll('public/index.html', 'AI diagnosis and operation UI bindings exist', [
  'otaDiagnosisMetricCards',
  'otaDiagnosisResultSections',
  'otaDiagnosisResult',
  'otaDiagnosisDataGaps',
  'otaDiagnosisActionItems',
  '数据缺口',
  '下一步动作',
  '证据：',
  'operationExecutionFlow',
  'operationExecutionItems',
  'operationExecutionPhase1Evidence',
  'completion_signal_count',
  'operationExecutionNextActionClass',
  '/operation/execution-flow',
  '/operation/execution-intents',
]);

includesAll('app/controller/OnlineData.php', 'collection reliability backend keeps explicit states and actions', [
  'public function collectionReliability',
  'data_quality',
  'missing_count',
  'not_collected',
  'auth_failed',
  'field_missing',
  'phase1_employee_questions',
  'withPhase1EmployeeQuestions',
  'phase1EmployeeClosureSummary',
  'buildDashboardDataSources',
  'read_existing_collection_reliability_only',
  'source_date_evidence',
  'collection_source_summary',
  'phase1CollectionSourceSummary',
  'phase1RevenueMetricEvidence',
  'normalizePhase1RevenueMetricEvidence',
  'phase1OperationExecutionEvidence',
  'normalizePhase1OperationExecutionEvidence',
  'phase1OperationExecutionEvidenceFromFlow',
  'phase1OperationItemHasOtaDiagnosisEvidence',
  'read_existing_online_daily_data_only',
  'read_existing_ota_standard_revenue_metrics_only',
  'read_existing_operation_execution_state_only',
  'collection_logic_changed',
  'latest_available_reference_only',
  'collection_source_platform_count',
  'top_action_source_snapshot',
  'phase1TopActionSourceSnapshot',
  'proof_requirement',
  'revenue_metric_evidence',
  'operation_execution_evidence',
  'metric_trust_key_count',
  'data_gap_count',
  'buildCollectionSourceDateEvidence',
  'read_online_daily_data_aggregate_only',
  'target_date_source_rows',
  'target_date_platform_coverage',
  'source_date_evidence_available',
  'source_date_evidence_missing',
  'reference_source_rows',
  'reference_rows_only',
  'phase1TargetDatePlatformCoverage',
  'phase1HasSourceDatePlatformEvidence',
  'phase1MetricDomainReadiness',
  'phase1PlatformFieldTrust',
  'phase1ReadyMetricPlatforms',
  'phase1MissingMetricPlatforms',
  'phase1MetricDomainGapCodes',
  'read_target_date_online_daily_data_types_only',
  'analysis_rows_reference_only',
  'target_date_data_types',
  'metric_domain_readiness',
  'revenue_ready_platforms',
  'traffic_ready_platforms',
  'conversion_ready_platforms',
  'revenue_missing_platforms',
  'traffic_missing_platforms',
  'conversion_missing_platforms',
  'metric_domain_gap_codes',
  'phase1AiEvidenceBlockers',
  'upstream_blockers',
  'blocking_missing_codes',
  'diagnosis_status',
  'action_item_status',
  'blocked_by_verified_ota_gaps',
  'read_existing_ota_gap_evidence_only',
  'ai_action_items_missing',
  'ai_action_items_blocked',
  'operation_execution_sample_missing',
  'operation_execution_ai_action_link_missing',
  'operation_execution_evidence_incomplete',
  'operation_evidence_status',
  'operation_blocking_missing_codes',
  'ota_diagnosis_linked_intent_count',
  'ota_diagnosis_linked_flow_item_count',
  'execution_intent_count',
  'execution_flow_item_count',
  'approved_count',
  'executed_count',
  'evidence_ready_count',
  'reviewed_count',
  'roi_ready_count',
  'completion_signal_count',
  'raw_data_exposed',
  'metric_trust_required',
  'platform_field_trust',
  'field_trust_status',
  'field_definition_keys',
  'phase1FieldDefinitionKeys',
  'field_pending_action_codes',
  'phase1PendingActionCodes',
  'missing_field_codes',
  'phase1DataQualityMissingFieldCodes',
  'field_trust_policy',
  'approval.status=approved',
  'missing_platforms',
  'partial',
  'future_dated_for_target',
  'stale_before_target',
  'today_ota_collected',
  'ai_evidence',
  'next_operation_action',
  'next_action',
  'next_required_actions',
  'closure_summary',
  'employee_question_count',
  'missing_question_keys',
  'top_action_code',
  'top_action_entry',
  'top_action_entry_options',
  'phase1TargetDateEntryOptionReadiness',
  'phase1BrowserProfileDirectoryCount',
  'readiness',
  'requires_user_context',
  'profile_missing',
  'profile_found_login_unverified',
  'read_local_profile_directory_names_only',
  'top_action_related_question_keys',
  'top_action_resolves_missing_codes',
  'top_action_live_closure_gap_codes',
  'top_action_source_snapshot',
  'top_action_success_criteria',
  'proof_requirement',
  'latest_available_reference_only',
  'use_when',
  'requires',
  'boundary',
  'read_existing_phase1_employee_question_rows_only',
  'latest_available_and_history_rows_are_reference_only_not_target_date_proof',
  'next_action_codes',
  'primary_next_action_code',
  'direct_next_action_code',
  'linked_action_count',
  'blocked_action_codes',
  'related_question_keys',
  'action_family',
  'success_criteria',
  'employee_explanation',
  'limited_conclusions',
  'still_usable_metrics',
  'explanation_next_action',
  'live_closure_gap_codes',
  'blocked_by_action_codes',
  'resolves_missing_codes',
  'phase1NextRequiredActionSuccessCriteria',
  'phase1NextRequiredActionEmployeeExplanation',
  'phase1NextRequiredActionPlatformLabel',
  'phase1NextRequiredActionLiveClosureGapCodes',
  'withPhase1EmployeeQuestionActionCodes',
  'phase1NextRequiredActionRelatedQuestionKeys',
  'phase1NextRequiredActionResolvesMissingCodes',
  'phase1NextRequiredActionForBlockerCode',
  'phase1NextRequiredActionBlockedByActionCodes',
  'phase1NextRequiredActionFamily',
  'buildPhase1NextRequiredActions',
  'phase1NextRequiredAction',
  'dedupePhase1NextRequiredActions',
  'sortPhase1NextRequiredActions',
  'phase1_collect_ai_diagnosis_evidence',
  'phase1_create_operation_execution_evidence',
  "$aiActionBlockers === [] ? 'missing' : 'blocked'",
  "$operationActionBlockers === [] ? 'missing' : 'blocked'",
  'phase1_confirm_source_date_evidence',
  'phase1_confirm_',
  'blocked_by',
  '/api/online-data/collection-reliability.field_definitions',
  '/api/online-data/collection-reliability.data_quality',
]);

includesAll('app/service/OtaRevenueMetricService.php', 'revenue metrics expose data gaps and trust state', [
  "'data_gaps' => $dataGaps",
  "'metric_trust' => $metricTrust",
  "'traffic' =>",
  "'channel_metrics' => $this->channelMetrics",
]);

includesAll('app/controller/Agent.php', 'OTA diagnosis attaches evidence and actionable next steps', [
  'public function otaDiagnosis',
  'evidence_sources',
  'action_items',
  'source_policy',
  'data_gaps',
  'next_action',
  'database_only_no_synthetic_conclusion',
  'blocked_by_missing_ota_data',
]);

includesAll('app/service/OperationManagementService.php', 'operation execution flow requires approval and evidence', [
  'public function createExecutionIntent',
  'public function executionFlow',
  'blocked execution intent cannot be approved',
  'execution evidence is required',
  'next_action',
  'data_collection',
  'evidence_refs',
  'source_policy',
  'protected_boundary',
]);

includesAll('docs/phase1_ota_trusted_loop_goal.md', 'goal document includes employee console verifier', [
  'npm.cmd run verify:phase1-employee-console',
]);

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit document references employee console contract', [
  'verify:phase1-employee-console',
  '员工视角验收',
]);

packageScript('verify:phase1-employee-console', 'node scripts/verify_phase1_ota_employee_console_contract.mjs');

const publicEntry = read('public/index.html');
const platformAutoSettingsPanelsContent = read('public/components/online-data/platform-auto-settings-panels.js');
check(
  'public/index.html',
  'employee next required actions are not truncated',
  !publicEntry.includes('phase1EmployeeRequiredActions.slice(0, 6)'),
  'phase1EmployeeRequiredActions.slice(0, 6)'
);
check(
  'public/index.html',
  'employee action cards show impacted employee questions',
  publicEntry.includes('v-for="item in phase1EmployeeRequiredActions"') && publicEntry.includes('item.relatedQuestionKeysText') && publicEntry.includes('phase1EmployeeQuestionKeyText'),
  'v-for="item in phase1EmployeeRequiredActions" + item.relatedQuestionKeysText + phase1EmployeeQuestionKeyText'
);
check(
  'public/index.html',
  'employee gap codes are mapped to readable labels',
  publicEntry.includes('phase1EmployeeGapCodeText') &&
    publicEntry.includes('blocking_missing_codes.slice(0, 3).map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('item.resolves_missing_codes.map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('item.live_closure_gap_codes.map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('backendSummary.top_action_resolves_missing_codes.map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('backendSummary.top_action_live_closure_gap_codes.map(phase1EmployeeGapCodeText)'),
  'phase1EmployeeGapCodeText + blocking/resolves/live closure mappings'
);
check(
  'public/index.html',
  'employee action codes are mapped to readable labels',
  publicEntry.includes('phase1EmployeeActionCodeText') &&
    publicEntry.includes('phase1EmployeeActionCodeText(directNextActionCode)') &&
    publicEntry.includes('actionCodesText: Array.isArray(row?.next_action_codes)') &&
    publicEntry.includes('blockedActionCodesText: Array.isArray(row?.blocked_action_codes') &&
    publicEntry.includes('item.blocked_by_action_codes.map(phase1EmployeeActionCodeText)') &&
    publicEntry.includes('row.directNextActionText || row.directNextActionCode') &&
    publicEntry.includes('row.actionCodesText || row.actionCodes'),
  'phase1EmployeeActionCodeText + direct/primary/blocked/linked mappings'
);
check(
  'public/index.html',
  'employee question next action prefers readable action-code text',
  publicEntry.includes('phase1EmployeeQuestionNextActionText') &&
    publicEntry.includes('nextActionText: phase1EmployeeQuestionNextActionText(row)') &&
    publicEntry.includes('nextActionText: localRow?.nextActionText || backendRow?.nextActionText ||') &&
    publicEntry.includes('row.nextActionText || row.nextAction') &&
    publicEntry.includes('row?.direct_next_action_code || row?.evidence?.direct_next_action_code') &&
    publicEntry.includes('row?.primary_next_action_code || row?.evidence?.primary_next_action_code') &&
    publicEntry.includes('row?.next_action_codes'),
  'next_action display is derived from direct/primary/linked action codes with raw title trace'
);
check(
  'public/index.html',
  'employee AI and operation summaries map next action and policy entry text',
  publicEntry.includes('const phase1EmployeeAiEvidenceSummary = computed') &&
    publicEntry.includes('const phase1EmployeeOperationSummary = computed') &&
    publicEntry.includes("const mappedNextAction = (directCode || primaryCode || linkedCodes.length) ? phase1EmployeeQuestionNextActionText(row) : ''") &&
    publicEntry.includes('const entryText = phase1EmployeeActionEntryText(entryRaw, {') &&
    publicEntry.includes("question_key: 'ai_evidence'") &&
    publicEntry.includes("question_key: 'next_operation_action'") &&
    publicEntry.includes("action_family: row?.direct_next_action_family || row?.evidence?.direct_next_action_family || 'ai_diagnosis_evidence'") &&
    publicEntry.includes("action_family: row?.direct_next_action_family || row?.evidence?.direct_next_action_family || 'operation_execution_evidence'") &&
    publicEntry.includes("blockingText: blocking.map(phase1EmployeeGapCodeText).filter(Boolean).join('、')") &&
    publicEntry.includes("blockingRawText: blocking.join('、')") &&
    publicEntry.includes('phase1EmployeeAiEvidenceSummary.blockingRawText || phase1EmployeeAiEvidenceSummary.blockingText') &&
    publicEntry.includes('phase1EmployeeOperationSummary.blockingRawText || phase1EmployeeOperationSummary.blockingText') &&
    publicEntry.includes('policyRawText: entryRaw') &&
    publicEntry.includes('phase1EmployeeAiEvidenceSummary.policyRawText || phase1EmployeeAiEvidenceSummary.policyText') &&
    publicEntry.includes('phase1EmployeeOperationSummary.policyRawText || phase1EmployeeOperationSummary.policyText') &&
    !publicEntry.includes('nextActionText: String(row?.next_action || row?.nextAction || directEntry') &&
    !publicEntry.includes("nextActionText: String(row?.next_action || row?.nextAction || '先取得真实 OTA"),
  'AI/operation summary next_action and policy entry use readable mappings; raw API path remains title trace'
);
check(
  'public/index.html',
  'employee question presentation fallback does not override backend facts',
  publicEntry.includes('phase1EmployeeQuestionPresentationRow') &&
    publicEntry.includes('detail: backendRow?.detail || localRow?.detail ||') &&
    publicEntry.includes('nextActionText: backendRow?.nextActionText || localRow?.nextActionText ||') &&
    publicEntry.includes("['today_ota_collected', 'trusted_fields', 'missing_fields'].includes(row.key)") &&
    publicEntry.includes('return phase1EmployeeQuestionPresentationRow(row, local)'),
  'presentation fallback fills question/detail text only and keeps backend status/evidence/actions'
);
check(
  'public/index.html',
  'employee closure summary maps missing question keys and top action codes',
  publicEntry.includes('backendMissingQuestionKeys.map(phase1EmployeeQuestionKeyText)') &&
    publicEntry.includes('topActionCodeText = phase1EmployeeActionCodeText(topActionCode)') &&
    publicEntry.includes('topActionTextRaw') &&
    publicEntry.includes('unresolvedQuestionTextRaw') &&
    publicEntry.includes('phase1EmployeeClosureSummary.topActionTextRaw || phase1EmployeeClosureSummary.topActionText') &&
    publicEntry.includes('phase1EmployeeClosureSummary.unresolvedQuestionTextRaw || phase1EmployeeClosureSummary.unresolvedQuestionText'),
  'missing_question_keys/top_action_code readable summary mappings with raw-title trace'
);
check(
  'public/index.html',
  'employee action entry paths are mapped to readable entry names',
  publicEntry.includes('phase1EmployeeActionEntryText') &&
    publicEntry.includes('entryText: phase1EmployeeActionEntryText(item?.entry || \'\', item)') &&
    publicEntry.includes('row.directNextActionEntryText || row.directNextActionEntry') &&
    publicEntry.includes('item.entryText || item.entry') &&
    publicEntry.includes('topActionEntryText') &&
    publicEntry.includes('美团手动 Cookie/API 获取入口') &&
    publicEntry.includes('美团浏览器 Profile 采集入口') &&
    publicEntry.includes('OTA 收益指标与标准事实核对') &&
    publicEntry.includes('AI 诊断证据核对入口') &&
    publicEntry.includes('运营执行意图入口'),
  'phase1EmployeeActionEntryText + readable entry names + raw-title trace'
);
check(
  'public/index.html',
  'employee entry option labels prefer stable mode mappings',
  publicEntry.includes('phase1EmployeeActionEntryOptionModeText') &&
    publicEntry.includes('phase1EmployeeActionEntryOptionRawText') &&
    publicEntry.includes("manual_cookie_api: '手动 Cookie/API'") &&
    publicEntry.includes("browser_profile: '浏览器 Profile'") &&
    publicEntry.includes("status_check: '状态核对'") &&
    publicEntry.includes('const modeText = phase1EmployeeActionEntryOptionModeText(option)') &&
    publicEntry.includes('const entry = phase1EmployeeActionEntryText(option.entry || \'\', option)') &&
    publicEntry.includes('entryOptionsRawText: entryOptionRaw.join') &&
    publicEntry.includes('topActionEntryOptionsRawText') &&
    publicEntry.includes('phase1EmployeeClosureSummary.topActionEntryOptionsRawText || phase1EmployeeClosureSummary.topActionEntryOptionsText') &&
    publicEntry.includes('item.entryOptionsRawText || item.entryOptionsText'),
  'phase1EmployeeActionEntryOptionModeText + raw entry option title trace'
);
check(
  'public/index.html',
  'employee action success criteria and evidence are mapped to readable labels',
  publicEntry.includes('phase1EmployeeActionSuccessCriteriaText') &&
    publicEntry.includes('phase1EmployeeActionEvidenceNeededText') &&
    publicEntry.includes('successCriteriaText: phase1EmployeeActionSuccessCriteriaText(item)') &&
    publicEntry.includes('evidenceNeededText: phase1EmployeeActionEvidenceNeededText(item)') &&
    publicEntry.includes('v-if="item.successCriteriaText"') &&
    publicEntry.includes('v-if="item.evidenceNeededText"') &&
    publicEntry.includes('v-if="row.directNextActionSuccessCriteriaText"') &&
    publicEntry.includes('{{ item.successCriteriaText }}') &&
    publicEntry.includes('{{ item.evidenceNeededText }}') &&
    publicEntry.includes('{{ row.directNextActionSuccessCriteriaText }}') &&
    publicEntry.includes('topActionSuccessCriteriaRaw') &&
    !publicEntry.includes('item.successCriteriaText || item.successCriteria') &&
    !publicEntry.includes('item.evidenceNeededText || item.evidenceNeeded') &&
    !publicEntry.includes('row.directNextActionSuccessCriteriaText || row.directNextActionSuccessCriteria') &&
    !publicEntry.includes('}) || topActionSuccessCriteriaRaw') &&
    publicEntry.includes('目标日入库行数 > 0；最近可用/历史数据只作参考') &&
    publicEntry.includes('AI 动作项不再被上游 OTA 缺口阻断') &&
    publicEntry.includes('原始完成条件仅保留追溯') &&
    publicEntry.includes('当前动作对应的目标日 OTA 证据、状态快照和缺口清单'),
  'phase1EmployeeActionSuccessCriteriaText + phase1EmployeeActionEvidenceNeededText + no raw main-text fallback'
);
check(
  'public/index.html',
  'collection pending actions use readable mapped presentation',
  publicEntry.includes('collectionHealthPendingActionRows') &&
    publicEntry.includes('collectionHealthPendingActionTypeText') &&
    publicEntry.includes('collectionHealthPendingActionText') &&
    publicEntry.includes('collectionHealthPendingActionEvidenceText') &&
    publicEntry.includes('collectionHealthPendingActionProtectedBoundaryText') &&
    publicEntry.includes('v-for="item in collectionHealthPendingActionRows.slice(0, 6)"') &&
    publicEntry.includes('item.actionRawText || item.actionText') &&
    publicEntry.includes('item.evidenceNeededRawText || item.evidenceNeededText') &&
    publicEntry.includes('item.protectedBoundaryRawText || item.protectedBoundaryText') &&
    publicEntry.includes('ota_same_period_source_rows_missing') &&
    !publicEntry.includes("{{ item.evidence_needed.slice(0, 3).join('、') }}"),
  'collectionHealthPendingActionRows + readable action/evidence/boundary mappings + raw title trace'
);
check(
  'public/index.html',
  'collection field definition panel uses readable mapped labels',
  publicEntry.includes('collectionHealthFieldSourceText') &&
    publicEntry.includes('collectionHealthFieldModuleText') &&
    publicEntry.includes('collectionHealthFieldStorageTableText') &&
    publicEntry.includes('collectionHealthFieldAssetStatusText') &&
    publicEntry.includes("privacy_boundary: '隐私边界'") &&
    publicEntry.includes("not_collected: '不采集/不入库'") &&
    publicEntry.includes("labelText: field.label || '字段未命名'") &&
    publicEntry.includes('fieldRawText: rawField ||') &&
    publicEntry.includes('metaRawText: `${source ||') &&
    publicEntry.includes('assetStatusText: collectionHealthFieldAssetStatusText(normalizedField)') &&
    publicEntry.includes('{{ field.labelText }}') &&
    publicEntry.includes('{{ field.metaText }}') &&
    publicEntry.includes('{{ field.assetStatusText }}') &&
    publicEntry.includes(':title="field.fieldRawText"') &&
    publicEntry.includes(':title="field.metaRawText"') &&
    publicEntry.includes(':title="field.assetStatusRawText"') &&
    !publicEntry.includes('{{ field.source }} / {{ field.module }} / {{ field.storage_table }}'),
  'field/source/module/storage_table/asset_status mapped to readable text with raw title trace'
);
check(
  'public/index.html',
  'collection failure reasons use readable mapped presentation',
  publicEntry.includes('collectionHealthFailureTypeText') &&
    publicEntry.includes('collectionHealthFailureReasonText') &&
    publicEntry.includes('collectionHealthFailureNextActionText') &&
    publicEntry.includes('collectionHealthFailureReasonRows') &&
    publicEntry.includes("authorization: '授权/登录'") &&
    publicEntry.includes('目标日 OTA 源数据缺失，不能证明当天已采到') &&
    publicEntry.includes('流量/转化事实缺失，不能输出确定漏斗判断') &&
    publicEntry.includes('标准事实或收益指标未就绪，需要复核入库与指标输入') &&
    publicEntry.includes('{{ item.platformText }} · {{ item.typeText }}') &&
    publicEntry.includes('{{ item.reasonText }}') &&
    publicEntry.includes('{{ item.nextActionText }}') &&
    publicEntry.includes('{{ row.reasonText }}') &&
    publicEntry.includes('{{ row.nextActionText }}') &&
    publicEntry.includes(':title="item.metaRawText"') &&
    publicEntry.includes(':title="item.reasonRawText"') &&
    publicEntry.includes(':title="item.nextActionRawText"') &&
    publicEntry.includes(':title="row.reasonRawText"') &&
    publicEntry.includes(':title="row.nextActionRawText"') &&
    !publicEntry.includes('{{ item.platform || \'-\' }} · {{ item.type || \'-\' }}') &&
    !publicEntry.includes('{{ item.occurred_at || \'-\' }} · {{ item.next_action || \'-\' }}'),
  'failure platform/type/reason/next_action mapped to readable text with raw title trace'
);
check(
  'public/index.html',
  'collection authorization rows use readable mapped presentation',
  publicEntry.includes('collectionHealthAuthorizationPlatformText') &&
    publicEntry.includes('collectionHealthAuthorizationMessageText') &&
    publicEntry.includes('collectionHealthAuthorizationActionHintText') &&
    publicEntry.includes('collectionHealthAuthorizationRowsReadable') &&
    publicEntry.includes('授权可用，仍以目标日入库行为采集证明') &&
    publicEntry.includes('授权配置待补齐') &&
    publicEntry.includes('授权或登录状态异常，需要重新授权后再采集') &&
    publicEntry.includes('{{ row.platformText }} · {{ row.nameText }}') &&
    publicEntry.includes('{{ row.messageText }}') &&
    publicEntry.includes('{{ row.actionHintText }}') &&
    publicEntry.includes(':title="row.messageRawText || row.metaRawText"') &&
    publicEntry.includes(':title="row.actionHintRawText || row.metaRawText"') &&
    publicEntry.includes(':title="row.statusRawText"') &&
    !publicEntry.includes('{{ row.platform || \'-\' }} · {{ row.name || \'-\' }}') &&
    !publicEntry.includes('{{ row.message || \'-\' }}') &&
    !publicEntry.includes('{{ row.action_hint || \'-\' }}'),
  'authorization platform/status/message/action_hint mapped to readable text with raw title trace'
);
check(
  'public/index.html + platform-auto component',
  'platform Profile status rows use readable mapped presentation',
  publicEntry.includes('platformProfileMachineText') &&
    publicEntry.includes('platformProfileStatusRawText') &&
    publicEntry.includes('platformProfileBindingRawText') &&
    publicEntry.includes('platformProfileNextActionText') &&
    publicEntry.includes('platformProfileLoginTaskText') &&
    publicEntry.includes('授权可用，下一步以目标日入库行证明采集成功') &&
    publicEntry.includes('登录会话已绑定') &&
    publicEntry.includes('平台门店标识已配置') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileBindingRawText(ctx.meituanPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileStatusRawText(ctx.meituanPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileNextActionText(ctx.meituanPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileLoginTaskText(ctx.meituanPlatformProfileLoginTask)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileBindingRawText(ctx.ctripPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileStatusRawText(ctx.ctripPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileNextActionText(ctx.ctripPlatformProfileStatusRow)') &&
    platformAutoSettingsPanelsContent.includes('ctx.platformProfileLoginTaskText(ctx.ctripPlatformProfileLoginTask)') &&
    !platformAutoSettingsPanelsContent.includes('{{ ctx.meituanPlatformProfileStatusRow.next_action }}') &&
    !platformAutoSettingsPanelsContent.includes('{{ ctx.ctripPlatformProfileStatusRow.next_action }}') &&
    !platformAutoSettingsPanelsContent.includes('{{ ctx.meituanPlatformProfileLoginTask.status_text || \'-\' }} · {{ ctx.meituanPlatformProfileLoginTask.message || \'-\' }}') &&
    !platformAutoSettingsPanelsContent.includes('{{ ctx.ctripPlatformProfileLoginTask.status_text || \'-\' }} · {{ ctx.ctripPlatformProfileLoginTask.message || \'-\' }}'),
  'Profile status/binding/next_action/task message mapped to readable text with raw title trace'
);
check(
  'public/index.html',
  'ctrip capture catalog uses readable mapped presentation',
  publicEntry.includes('collectionHealthCtripCatalogStatusText') &&
    publicEntry.includes('collectionHealthCtripCatalogAuthStatusText') &&
    publicEntry.includes('collectionHealthCtripCatalogCodeText') &&
    publicEntry.includes('collectionHealthCtripCatalogDetailRows') &&
    publicEntry.includes('collectionHealthCtripCatalogActionRows') &&
    publicEntry.includes("capture_gate_missing: '采集门禁缺失'") &&
    publicEntry.includes("auth_session: '授权会话'") &&
    publicEntry.includes("endpoint_coverage: '采集规则覆盖'") &&
    publicEntry.includes("field_coverage: '字段覆盖'") &&
    publicEntry.includes("traffic_report: '流量漏斗'") &&
    publicEntry.includes("valueText: collectionHealthCtripCatalogStatusText(catalog.capture_gate_status)") &&
    publicEntry.includes("valueText: collectionHealthCtripCatalogAuthStatusText(catalog.auth_status)") &&
    publicEntry.includes('v-for="row in collectionHealthCtripCatalogDetailRows"') &&
    publicEntry.includes(':title="row.rawText"') &&
    publicEntry.includes('v-for="action in collectionHealthCtripCatalogActionRows.slice(0, 4)"') &&
    publicEntry.includes(':title="action.rawText"') &&
    publicEntry.includes('{{ action.actionText }}') &&
    !publicEntry.includes('capture_gate_status：{{ collectionHealthCtripCatalog.capture_gate_status') &&
    !publicEntry.includes('auth_status：{{ collectionHealthCtripCatalog.auth_status') &&
    !publicEntry.includes('<span v-if="action.endpoint_id"> / {{ action.endpoint_id }}</span>'),
  'Ctrip capture catalog statuses/sections/actions mapped to readable text with raw title trace'
);
check(
  'public/index.html',
  'employee action explanations prefer readable mapped text',
  publicEntry.includes('phase1EmployeeActionEmployeeExplanationText') &&
    publicEntry.includes('phase1EmployeeActionLimitedConclusionsText') &&
    publicEntry.includes('phase1EmployeeActionStillUsableMetricsText') &&
    publicEntry.includes('phase1EmployeeActionExplanationNextActionText') &&
    publicEntry.includes('phase1EmployeeActionBlockedActionText') &&
    publicEntry.includes('employeeExplanationText: phase1EmployeeActionEmployeeExplanationText(item)') &&
    publicEntry.includes('limitedConclusionsText: phase1EmployeeActionLimitedConclusionsText(item)') &&
    publicEntry.includes('stillUsableMetricsText: phase1EmployeeActionStillUsableMetricsText(item)') &&
    publicEntry.includes('explanationNextActionText: phase1EmployeeActionExplanationNextActionText(item)') &&
    publicEntry.includes('item.employeeExplanationText || item.employeeExplanation') &&
    publicEntry.includes('item.limitedConclusionsText || item.limitedConclusions') &&
    publicEntry.includes('item.stillUsableMetricsText || item.stillUsableMetrics') &&
    publicEntry.includes('item.explanationNextActionText || item.explanationNextAction'),
  'employee_explanation/limited_conclusions/still_usable_metrics/explanation_next_action mapped with raw-title trace'
);
check(
  'public/index.html',
  'employee action card title metadata and boundary prefer readable mapped text',
  publicEntry.includes('phase1EmployeeActionDisplayText') &&
    publicEntry.includes('phase1EmployeeActionOwnerText') &&
    publicEntry.includes('phase1EmployeeActionMetaText') &&
    publicEntry.includes('phase1EmployeeActionProtectedBoundaryText') &&
    publicEntry.includes('actionText: phase1EmployeeActionDisplayText(item)') &&
    publicEntry.includes('ownerText: phase1EmployeeActionOwnerText(item)') &&
    publicEntry.includes('actionMetaText: phase1EmployeeActionMetaText(item)') &&
    publicEntry.includes('protectedBoundaryText: phase1EmployeeActionProtectedBoundaryText(item)') &&
    publicEntry.includes('item.actionText || item.action') &&
    publicEntry.includes('item.actionMetaRawText || item.actionMetaText') &&
    publicEntry.includes('item.protectedBoundaryText || item.protectedBoundary') &&
    publicEntry.includes('item.actionCode && (item.action || item.actionText)'),
  'action/action owner/reason/protected_boundary mapped with raw-title trace'
);
check(
  'public/index.html',
  'employee evidence status codes are mapped to readable labels',
  publicEntry.includes('evidenceStatusText') &&
    publicEntry.includes("ai_action_items_blocked: 'AI 动作项被上游证据阻断'") &&
    publicEntry.includes("read_existing_collection_reliability_only: '只读采集可靠性状态'") &&
    publicEntry.includes("read_existing_operation_execution_state_only: '只读运营执行状态'") &&
    publicEntry.includes('target_date_rows_field_definitions_metric_trust_required') &&
    publicEntry.includes('evidenceStatusText(evidence.operation_evidence_status)'),
  'evidenceStatusText + AI/source/operation status mappings'
);
check(
  'public/index.html',
  'employee field trust status codes are mapped to readable labels',
  publicEntry.includes('phase1FieldTrustStatusText') &&
    publicEntry.includes('phase1FieldTrustStatusText(row?.field_trust_status)') &&
    publicEntry.includes('reason_codes.slice(0, 2).map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('字段可信平台 ${platformFieldTrustText}'),
  'phase1FieldTrustStatusText + platform_field_trust summary mappings'
);
check(
  'public/index.html',
  'employee field trust reason codes are mapped to readable labels with raw trace',
  publicEntry.includes("reasonText: reasonCodes.map(phase1EmployeeGapCodeText).filter(Boolean).join('、')") &&
    publicEntry.includes("reasonRawText: reasonCodes.join('、')") &&
    publicEntry.includes('row.reasonRawText || row.reasonText'),
  'platform_field_trust reason_codes use phase1EmployeeGapCodeText and raw title trace'
);
check(
  'public/index.html',
  'employee evidence policy and storage codes are mapped to readable labels',
  publicEntry.includes('phase1EmployeeEvidencePolicyText') &&
    publicEntry.includes('phase1EmployeeStorageTableText') &&
    publicEntry.includes("read_existing_online_daily_data_only: '只读 OTA 入库状态'") &&
    publicEntry.includes("requires_target_date_rows_field_definitions_metric_trust_and_data_quality: '需要目标日源数据、字段定义、指标可信和数据质量证据'") &&
    publicEntry.includes("online_daily_data: 'OTA 入库表'") &&
    publicEntry.includes("parts.push(`指标域口径 ${phase1EmployeeEvidencePolicyText(evidence.metric_domain_policy)}`)") &&
    publicEntry.includes("boundaryText: `${phase1EmployeeStorageTableText(row?.storage_table || 'online_daily_data')} / ${phase1EmployeeEvidencePolicyText(row?.source_policy || 'read_existing_online_daily_data_only')} / 不改变采集逻辑`") &&
    publicEntry.includes("boundaryRawText: `${row?.storage_table || 'online_daily_data'} / ${row?.source_policy || 'read_existing_online_daily_data_only'} / collection_logic_changed=${row?.collection_logic_changed === true ? 'true' : 'false'}`") &&
    publicEntry.includes("policyText: `${phase1EmployeeEvidencePolicyText(row?.source_policy || 'target_date_rows_plus_metric_trust_required')}；未证明时不把字段写成可信`") &&
    publicEntry.includes("policyRawText: String(row?.source_policy || 'target_date_rows_plus_metric_trust_required')") &&
    publicEntry.includes('row.boundaryRawText || row.boundaryText') &&
    publicEntry.includes('row.policyRawText || row.policyText'),
  'phase1EmployeeEvidencePolicyText + phase1EmployeeStorageTableText + raw title trace for source_policy/storage_table'
);
check(
  'public/index.html',
  'employee top action source snapshot is rendered as readable evidence',
  publicEntry.includes('phase1EmployeeSourceSnapshotText') &&
    publicEntry.includes('phase1EmployeeDateRelationText') &&
    publicEntry.includes('目标日入库 ${targetRows} 行') &&
    publicEntry.includes('最近可用只作参考，不能替代目标日入库证明') &&
    publicEntry.includes('证明要求：目标日该平台入库行 > 0') &&
    publicEntry.includes('const topActionSourceSnapshotText = phase1EmployeeSourceSnapshotText(sourceSnapshot)'),
  'phase1EmployeeSourceSnapshotText + readable latest/proof boundary'
);
check(
  'public/index.html',
  'employee entry readiness codes are mapped to readable labels',
  publicEntry.includes('phase1EmployeeReadinessStatusText') &&
    publicEntry.includes('phase1EmployeeReadinessEvidenceText') &&
    publicEntry.includes('requires_user_context') &&
    publicEntry.includes('需要先提供授权上下文') &&
    publicEntry.includes('profile_missing') &&
    publicEntry.includes('未找到本机 Profile') &&
    publicEntry.includes('storage_profile_directory_count') &&
    publicEntry.includes('只读取本机 Profile 目录数量') &&
    publicEntry.includes('read_existing_collection_reliability_only') &&
    publicEntry.includes('只读现有采集可靠性状态') &&
    publicEntry.includes('phase1EmployeeReadinessEvidenceText(readiness.source_policy)'),
  'phase1EmployeeReadinessStatusText + phase1EmployeeReadinessEvidenceText'
);

const acceptanceDoc = read('docs/phase1_ota_employee_console_acceptance.md');
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee console complete action queue is documented',
  acceptanceDoc.includes('必须展示完整 `next_required_actions` 队列') && acceptanceDoc.includes('不能只截取前几条'),
  '必须展示完整 `next_required_actions` 队列 / 不能只截取前几条'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action explanation readable mapping is documented',
  acceptanceDoc.includes('employee_explanation') &&
    acceptanceDoc.includes('limited_conclusions') &&
    acceptanceDoc.includes('still_usable_metrics') &&
    acceptanceDoc.includes('explanation_next_action') &&
    acceptanceDoc.includes('action_code') &&
    acceptanceDoc.includes('action_family') &&
    acceptanceDoc.includes('blocked_by_action_codes') &&
    acceptanceDoc.includes('resolves_missing_codes') &&
    acceptanceDoc.includes('question_key') &&
    acceptanceDoc.includes('后端原始解释字段只能保留在结构化数据或标题追溯中') &&
    acceptanceDoc.includes('不能因为编码异常、技术码或平台原文让员工看到乱码主文案'),
  'employee action explanation fields mapped from action metadata with raw trace'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action title metadata and boundary readable mapping is documented',
  acceptanceDoc.includes('员工动作队列主标题') &&
    acceptanceDoc.includes('负责人/平台/状态元信息') &&
    acceptanceDoc.includes('protected_boundary') &&
    acceptanceDoc.includes('后端原始 `action`') &&
    acceptanceDoc.includes('`owner`') &&
    acceptanceDoc.includes('`reason`') &&
    acceptanceDoc.includes('不能作为主展示文案') &&
    acceptanceDoc.includes('不能因此改变携程/美团手动或自动获取逻辑'),
  'action/owner/reason/protected_boundary readable mapping and protected acquisition boundary'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee impact question keys are mapped to readable labels',
  acceptanceDoc.includes('映射成员工可读问题文案') && acceptanceDoc.includes('不能直接展示技术键名'),
  '映射成员工可读问题文案 / 不能直接展示技术键名'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'entry readiness can-run state is documented',
  acceptanceDoc.includes('readiness.can_run_now') && acceptanceDoc.includes('可直接执行/需先准备'),
  'readiness.can_run_now / 可直接执行/需先准备'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'entry readiness readable mapping is documented',
  acceptanceDoc.includes('requires_user_context') &&
    acceptanceDoc.includes('profile_missing') &&
    acceptanceDoc.includes('profile_found_login_unverified') &&
    acceptanceDoc.includes('user_supplied_cookie_or_payload_required') &&
    acceptanceDoc.includes('storage_profile_directory_count') &&
    acceptanceDoc.includes('read_existing_collection_reliability_only') &&
    acceptanceDoc.includes('映射成可读状态和证据说明') &&
    acceptanceDoc.includes('不能只把 readiness 技术码拼到页面上'),
  'entry readiness technical codes mapped to readable labels'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action entry readable mapping is documented',
  acceptanceDoc.includes('动作 `entry`') &&
    acceptanceDoc.includes('映射成员工可读入口名') &&
    acceptanceDoc.includes('美团手动 Cookie/API 获取入口') &&
    acceptanceDoc.includes('美团浏览器 Profile 采集入口') &&
    acceptanceDoc.includes('OTA 收益指标与标准事实核对') &&
    acceptanceDoc.includes('AI 诊断证据核对入口') &&
    acceptanceDoc.includes('原始入口路径只能保留在结构化数据或标题追溯中'),
  'entry 映射成员工可读入口名 / 原始入口路径只能保留'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee entry option mode mapping is documented',
  acceptanceDoc.includes('展示 `entry_options`') &&
    acceptanceDoc.includes('优先用 `mode` 映射稳定入口类型文案') &&
    acceptanceDoc.includes('manual_cookie_api=手动 Cookie/API') &&
    acceptanceDoc.includes('browser_profile=浏览器 Profile') &&
    acceptanceDoc.includes('status_check=状态核对') &&
    acceptanceDoc.includes('后端 `label` 和原始 `entry` 只能保留在结构化数据或标题追溯中') &&
    acceptanceDoc.includes('不能因为 label 编码异常'),
  'entry_options mode stable labels / raw label and entry title trace'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action code mapping is documented',
  acceptanceDoc.includes('direct_next_action_code') && acceptanceDoc.includes('映射成员工可读动作名') && acceptanceDoc.includes('原始 action code 仍保留'),
  'direct_next_action_code / 映射成员工可读动作名 / 原始 action code 仍保留'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question next_action readable mapping is documented',
  acceptanceDoc.includes('展示 `next_action`/`nextAction`') &&
    acceptanceDoc.includes('direct_next_action_code') &&
    acceptanceDoc.includes('primary_next_action_code') &&
    acceptanceDoc.includes('next_action_codes') &&
    acceptanceDoc.includes('映射成员工可读下一步') &&
    acceptanceDoc.includes('原始 `next_action` 只能保留'),
  'next_action / direct_next_action_code / primary_next_action_code readable mapping and raw trace'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee AI and operation summary next_action mapping is documented',
  acceptanceDoc.includes('AI 依据摘要和运营执行摘要') &&
    acceptanceDoc.includes('phase1EmployeeQuestionNextActionText') &&
    acceptanceDoc.includes('phase1EmployeeActionEntryText') &&
    acceptanceDoc.includes('原始 `next_action` 不能作为摘要主文案') &&
    acceptanceDoc.includes('原始 API 路径只能保留在标题追溯') &&
    acceptanceDoc.includes('`blocking_missing_codes`') &&
    acceptanceDoc.includes('phase1EmployeeGapCodeText') &&
    acceptanceDoc.includes('原始阻断码只能保留在标题追溯'),
  'AI/operation summary next_action and entry policy readable mapping documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question presentation fallback boundary is documented',
  acceptanceDoc.includes('缺少 `detail`/`message` 展示说明') &&
    acceptanceDoc.includes('复用本地六问说明文本') &&
    acceptanceDoc.includes('不能覆盖后端 `status`') &&
    acceptanceDoc.includes('不能把本地说明当作采集成功'),
  'backend facts remain authoritative when local detail text fills card explanation'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee closure summary readable mapping is documented',
  acceptanceDoc.includes('missing_question_keys') &&
    acceptanceDoc.includes('top_action_code') &&
    acceptanceDoc.includes('映射成员工可读问题名和动作名') &&
    acceptanceDoc.includes('原始文案只能保留在结构化数据或标题追溯中') &&
    acceptanceDoc.includes('不能让后端原始文案、编码异常或技术码替代稳定展示'),
  'missing_question_keys/top_action_code 映射成员工可读问题名和动作名'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action success criteria and evidence mapping is documented',
  acceptanceDoc.includes('success_criteria') &&
    acceptanceDoc.includes('evidence_needed') &&
    acceptanceDoc.includes('映射成员工可读完成判定和所需证据') &&
    acceptanceDoc.includes('原始技术值仍保留') &&
    acceptanceDoc.includes('不能作为主文案') &&
    acceptanceDoc.includes('不能把可读文案当作采集成功或闭环完成证据'),
  'success_criteria/evidence_needed 映射成员工可读完成判定和所需证据 / 原始技术值仍保留'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee gap codes mapping is documented',
  acceptanceDoc.includes('映射成员工可读缺口文案') && acceptanceDoc.includes('原始技术码仍保留'),
  '映射成员工可读缺口文案 / 原始技术码仍保留'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee evidence status mapping is documented',
  acceptanceDoc.includes('diagnosis_status') &&
    acceptanceDoc.includes('action_item_status') &&
    acceptanceDoc.includes('source_policy') &&
    acceptanceDoc.includes('operation_evidence_status') &&
    acceptanceDoc.includes('映射成员工可读状态文案'),
  'diagnosis_status/action_item_status/source_policy/operation_evidence_status 映射成员工可读状态文案'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee field trust status mapping is documented',
  acceptanceDoc.includes('platform_field_trust[].field_trust_status') &&
    acceptanceDoc.includes('target_date_source_missing') &&
    acceptanceDoc.includes('target_date_metric_inputs_missing') &&
    acceptanceDoc.includes('映射成员工可读字段可信状态') &&
    acceptanceDoc.includes('platform_field_trust[].reason_codes') &&
    acceptanceDoc.includes('映射成员工可读未证明原因') &&
    acceptanceDoc.includes('原始 reason code 只保留在标题追溯'),
  'platform_field_trust[].field_trust_status/reason_codes mapped to readable labels'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'collection pending actions readable mapping is documented',
  acceptanceDoc.includes('collectionReliability.pending_actions') &&
    acceptanceDoc.includes('历史回放 / 待处理') &&
    acceptanceDoc.includes('action_code') &&
    acceptanceDoc.includes('evidence_needed') &&
    acceptanceDoc.includes('protected_boundary') &&
    acceptanceDoc.includes('不能作为员工主文案') &&
    acceptanceDoc.includes('不能改变携程/美团手动或自动获取逻辑'),
  'collectionReliability.pending_actions readable mapping and raw-title boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee evidence policy and storage code mapping is documented',
  acceptanceDoc.includes('collection_source_summary.source_policy') &&
    acceptanceDoc.includes('storage_table') &&
    acceptanceDoc.includes('field_trust_policy') &&
    acceptanceDoc.includes('metric_domain_policy') &&
    acceptanceDoc.includes('必须映射成可读证据口径') &&
    acceptanceDoc.includes('原始机器口径只能保留在标题追溯') &&
    acceptanceDoc.includes('phase1EmployeeEvidencePolicyText'),
  'source_policy/storage_table/field_trust_policy/metric_domain_policy readable evidence mapping documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'collection field definition readable mapping is documented',
  acceptanceDoc.includes('字段缺失 / 定义') &&
    acceptanceDoc.includes('字段名、平台来源、业务模块、入库位置和字段状态') &&
    acceptanceDoc.includes('field') &&
    acceptanceDoc.includes('source_fields') &&
    acceptanceDoc.includes('asset_status') &&
    acceptanceDoc.includes('原始机器口径只能保留在标题追溯') &&
    acceptanceDoc.includes('privacy_boundary') &&
    acceptanceDoc.includes('隐私边界') &&
    acceptanceDoc.includes('not_collected') &&
    acceptanceDoc.includes('不采集/不入库') &&
    acceptanceDoc.includes('禁止采集') &&
    acceptanceDoc.includes('不能改变携程/美团手动或自动获取逻辑'),
  'field definition panel readable labels and protected collection boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'collection failure reason readable mapping is documented',
  acceptanceDoc.includes('失败原因') &&
    acceptanceDoc.includes('platform') &&
    acceptanceDoc.includes('type') &&
    acceptanceDoc.includes('reason') &&
    acceptanceDoc.includes('next_action') &&
    acceptanceDoc.includes('映射成员工可读的平台、失败类型、失败原因和下一步动作') &&
    acceptanceDoc.includes('原始失败类型、机器码、接口路径或平台返回原文只能保留在标题追溯') &&
    acceptanceDoc.includes('授权/登录') &&
    acceptanceDoc.includes('目标日源数据缺失') &&
    acceptanceDoc.includes('字段结构异常') &&
    acceptanceDoc.includes('流量/转化缺失') &&
    acceptanceDoc.includes('标准事实或收益指标未就绪') &&
    acceptanceDoc.includes('不能改变携程/美团手动或自动获取逻辑'),
  'failure reason panel readable labels and protected collection boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'collection authorization readable mapping is documented',
  acceptanceDoc.includes('授权记录 / 携程授权记录') &&
    acceptanceDoc.includes('authorization.list[].platform') &&
    acceptanceDoc.includes('status') &&
    acceptanceDoc.includes('message') &&
    acceptanceDoc.includes('action_hint') &&
    acceptanceDoc.includes('映射成员工可读的平台、授权状态、授权说明和下一步动作') &&
    acceptanceDoc.includes('原始平台码、状态码、接口消息或动作提示只能保留在标题追溯') &&
    acceptanceDoc.includes('不能改变携程/美团手动或自动获取逻辑'),
  'authorization rows readable labels and protected collection boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'platform Profile status readable mapping is documented',
  acceptanceDoc.includes('美团 / 携程 Profile 状态') &&
    acceptanceDoc.includes('platformProfileStatus.items[].status_code') &&
    acceptanceDoc.includes('current_status') &&
    acceptanceDoc.includes('profile_key') &&
    acceptanceDoc.includes('binding.profile_id') &&
    acceptanceDoc.includes('binding.store_id') &&
    acceptanceDoc.includes('binding.poi_id') &&
    acceptanceDoc.includes('登录任务 `status_text/message`') &&
    acceptanceDoc.includes('映射成员工可读的登录状态、绑定状态、下一步动作和登录任务状态') &&
    acceptanceDoc.includes('原始 Profile、门店标识、POI、状态码或任务消息只能保留在标题追溯') &&
    acceptanceDoc.includes('不能改变携程/美团手动或自动获取逻辑'),
  'Profile status rows readable labels and protected collection boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'ctrip capture catalog readable mapping is documented',
  acceptanceDoc.includes('采集覆盖统计') &&
    acceptanceDoc.includes('capture_gate_status') &&
    acceptanceDoc.includes('auth_status') &&
    acceptanceDoc.includes('failed_check_ids') &&
    acceptanceDoc.includes('capture_gap_status') &&
    acceptanceDoc.includes('capture_gap_blockers') &&
    acceptanceDoc.includes('default_sections') &&
    acceptanceDoc.includes('wide_sections') &&
    acceptanceDoc.includes('capture_gap_next_actions[].section/endpoint_id') &&
    acceptanceDoc.includes('映射成员工可读的采集状态、授权状态、未通过检查、阻塞原因、采集范围和下一步动作') &&
    acceptanceDoc.includes('原始状态码、section key、endpoint id 只能保留在标题追溯') &&
    acceptanceDoc.includes('不能改变携程手动或自动获取逻辑'),
  'Ctrip capture catalog readable labels and protected collection boundary documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee top action source snapshot rendering is documented',
  acceptanceDoc.includes('当前证据') &&
    acceptanceDoc.includes('目标日入库行') &&
    acceptanceDoc.includes('最近可用日期') &&
    acceptanceDoc.includes('日期关系') &&
    acceptanceDoc.includes('不能只显示 `target_date_rows=0`') &&
    acceptanceDoc.includes('stale_before_target'),
  '当前证据 / 目标日入库行 / 最近可用日期 / 日期关系 / stale_before_target'
);

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 employee console contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-employee-console] ${checks.length} checks passed`);
