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

function includesAllSources(files, label, needles) {
  const source = files.map((file) => read(file)).join('\n');
  const missing = needles.filter((needle) => !source.includes(needle));
  check(files.join(' + '), label, missing.length === 0, missing.join(', '));
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
  'traffic_source_readiness',
  'traffic_source_text',
  'traffic_source_next_action',
  'traffic_source_policy',
  'p0_traffic_gate_status',
  'p0_next_action_mode',
  'p0_next_action_entry',
  'p0_next_step_count',
  'next_command_policy',
  'p0_external_evidence_status',
  'p0_pre_import_evidence_status',
  'p0_pre_import_evidence_policy',
  'p0_traffic_field_fact_status',
  'p0_required_metric_keys',
  'p0_required_storage_fields',
  'p0_required_field_fact_keys',
  'p0_missing_metric_keys',
  'p0_traffic_closure_chain',
  'p0_traffic_closure_chain_policy',
  'p0_target_traffic_data_types',
  'p0_source_chain_reference_only',
  'p0_source_chain_scope',
  'p0_source_chain_policy',
  'no_target_date_source_rows',
  'reference_only_non_traffic_source_rows',
  'trafficActionModeLabel',
  'trafficPreImportEvidenceLabel',
  'trafficP0NextText',
  'manual_login_state_verified',
  'trafficSourceText',
  '采集源：',
  'read_platform_data_sources_metadata_only',
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
  'trafficSourceRawText',
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
  'employeeAction',
  'employeeExplanation',
  'employeeEvidenceNeeded',
  'employeeSuccessCriteria',
  'employeeExplanationNextAction',
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
  'phase1EmployeeActionEntryOptionGuidanceRawText',
  'phase1EmployeeActionEntryOptionReadinessText',
  'phase1EmployeeActionEntryOptionInputText',
  'phase1EmployeeActionEntryOptionContractText',
  'input_contract',
  'required_metric_keys',
  'required_storage_fields',
  'required_inputs',
  'required_field_fact_keys',
  'sensitive_values_allowed',
  '需闭环指标',
  '需入库字段',
  '需补输入',
  '需证明采集证据、source path、metric key、入库字段和已入库值',
  '不展示 Cookie、token 或 Profile 原值',
  'phase1EmployeeReadinessStatusText',
  'phase1EmployeeReadinessEvidenceText',
  'entryOptionGuidanceText',
  'entryOptionGuidanceRawText',
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
  'phase1EmployeeKnownQuestionListText(item?.related_question_keys)',
  'phase1EmployeeKnownQuestionListText(backendTopActionImpactRaw)',
  'missing_question_keys) ? backendSummary.missing_question_keys.map',
  'backendMissingQuestionKeys.map(phase1EmployeeKnownQuestionText)',
  'phase1EmployeeActionDisplayText({',
  'topActionTextRaw',
  'unresolvedQuestionTextRaw',
  'blocking_missing_codes.slice(0, 3).map(phase1EmployeeGapCodeText)',
  'phase1EmployeeActionCodeText(directNextActionCode)',
  'entryOptions,',
  "entryOptionsText: entryOptions.join('、')",
  "employeeAction: String(item?.employee_action || '')",
  'successCriteria: String(item?.success_criteria || \'\')',
  "employeeSuccessCriteria: String(item?.employee_success_criteria || '')",
  'successCriteriaText: phase1EmployeeActionSuccessCriteriaText(item)',
  'employeeEvidenceNeeded: Array.isArray(item?.employee_evidence_needed)',
  'evidenceNeededText: phase1EmployeeActionEvidenceNeededText(item)',
  'employeeVerificationSteps: Array.isArray(item?.employee_verification_steps)',
  'verificationStepsText: phase1EmployeeActionVerificationStepsText(item)',
  "employeeExplanation: String(item?.employee_explanation || '')",
  'limitedConclusions: Array.isArray(item?.limited_conclusions)',
  'stillUsableMetrics: Array.isArray(item?.still_usable_metrics)',
  "employeeExplanationNextAction: String(item?.employee_explanation_next_action || '')",
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
  'topActionEntryOptionGuidanceRawText',
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
  '只按目标日源数据 + 指标可信证据判断',
  '缺失字段摘要',
  '来自数据缺口和字段缺口证据',
  '收入/流量/转化证据摘要',
  '只按目标日 OTA 指标域判断',
  'AI 依据摘要',
  '只读证据来源、数据缺口和动作项',
  '运营执行摘要',
  '只读执行意图和执行流',
  '证据来源',
  '数据缺口',
  '可执行动作',
  '执行意图',
  '执行流',
  '执行证据',
  'AI 建议必须引用证据来源、数据缺口和动作项',
  '只有可追溯到 OTA 诊断动作项',
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

includesAll('app/controller/concern/Phase1EmployeeConsoleConcern.php', 'employee console backend exposes traffic source readiness without sensitive values', [
  'phase1TrafficSourceReadiness',
  'phase1TrafficSourceReadinessForPlatform',
  'phase1TrafficConversionFactsActionEntryOptions',
  'phase1TrafficInputContract',
  'phase1TrafficAcceptanceContract',
  "'target_data_type' => 'traffic'",
  "'required_metric_keys'",
  "'required_field_fact_keys'",
  "'sensitive_values_allowed' => false",
  "'entry_options' => $this->phase1TrafficConversionFactsActionEntryOptions($platform)",
  'traffic_source_readiness',
  'traffic_source_text',
  'traffic_source_next_action',
  'traffic_source_policy',
  'read_platform_data_sources_metadata_only',
  'sensitive_values_exposed',
  'p0_traffic_gate_status',
  'p0_next_action_mode',
  'p0_next_action_entry',
  'p0_next_step_count',
  'next_command_policy',
  'p0_external_evidence_status',
  'p0_pre_import_evidence_status',
  'p0_pre_import_evidence_policy',
  'p0_traffic_field_fact_status',
  'phase1P0TrafficFieldLoopMatrix',
  'phase1P0TrafficRows',
  'phase1P0CaptureEvidenceMatchesRow',
  'phase1P0DesensitizedEvidence',
  'complete_row_count',
  'capture_evidence_matches_row',
  'desensitized_capture_evidence_present',
  "'complete'",
  "'incomplete'",
  "'missing'",
  'p0_required_metric_keys',
  'p0_required_storage_fields',
  'p0_required_field_fact_keys',
  'p0_missing_metric_keys',
  'p0_traffic_closure_chain',
  'p0_traffic_closure_chain_policy',
  'p0_target_traffic_data_types',
  'p0_source_chain_reference_only',
  'p0_source_chain_scope',
  'p0_source_chain_policy',
  'no_target_date_source_rows',
  'reference_only_non_traffic_source_rows',
  'metadata_only_no_sensitive_commands',
  'phase1TrafficSourceRecommendedMode',
  'phase1TrafficSourceActionEntryForMode',
  'manual_login_state_verified',
  '/api/online-data/capture-meituan-browser',
  'registered_waiting_config',
  'registered_ready_without_target_date_traffic',
]);

includesAllSources([
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'scripts/inspect_phase1_ota_live_closure.php',
], 'employee evidence scripts expose real P0 traffic field-loop matrix paths', [
  'target_date',
  'traffic_source_p0_field_loop_matrix',
  'inspection_traffic_source_p0_field_loop_matrix',
  'traffic_source_p0_closure_chain',
  'inspection_traffic_source_p0_closure_chain',
  'traffic_source_p0_traffic_rows',
  'inspection_traffic_source_p0_traffic_rows',
  'traffic_source_p0_capture_evidence_matches_row',
  'inspection_traffic_source_p0_capture_evidence_matches_row',
  'complete_row_count',
  'capture_evidence_matches_row',
  'desensitized_capture_evidence_present',
  "'complete'",
  "'incomplete'",
  "'missing'",
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

includesAllSources([
  'app/controller/OnlineData.php',
  'app/controller/concern/Phase1EmployeeConsoleConcern.php',
], 'collection reliability backend keeps explicit states and actions', [
  'public function collectionReliability',
  'data_quality',
  'missing_count',
  'not_collected',
  'auth_failed',
  'field_missing',
  'phase1_employee_questions',
  'withPhase1EmployeeQuestions',
  'phase1EmployeeReadableCopy',
  'employee_detail',
  'employee_next_action',
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
const collectionSourceRowStart = publicEntry.indexOf('const phase1EmployeeCollectionDataTypeText = (type) => {');
const collectionSourceRowEnd = publicEntry.indexOf('const phase1EmployeeCollectionSourceRows = computed', collectionSourceRowStart);
const collectionSourceRowSource = collectionSourceRowStart >= 0 && collectionSourceRowEnd > collectionSourceRowStart
  ? publicEntry.slice(collectionSourceRowStart, collectionSourceRowEnd)
  : '';
const metricDomainRowStart = publicEntry.indexOf('const phase1MetricDomainProblemText = ({ revenueReady, trafficReady, conversionReady, sourceRows, trafficRows }) => {');
const metricDomainRowEnd = publicEntry.indexOf('const phase1EmployeeMetricDomainRows = computed', metricDomainRowStart);
const metricDomainRowSource = metricDomainRowStart >= 0 && metricDomainRowEnd > metricDomainRowStart
  ? publicEntry.slice(metricDomainRowStart, metricDomainRowEnd)
  : '';
const questionEvidenceStart = publicEntry.indexOf('const phase1EmployeeQuestionEvidenceText = (evidence) => {');
const questionEvidenceEnd = publicEntry.indexOf('const phase1EmployeeActionFamilyText = (family) =>', questionEvidenceStart);
const questionEvidenceSource = questionEvidenceStart >= 0 && questionEvidenceEnd > questionEvidenceStart
  ? publicEntry.slice(questionEvidenceStart, questionEvidenceEnd)
  : '';
const questionRowsStart = publicEntry.indexOf('const phase1EmployeeQuestionRows = computed');
const questionRowsEnd = publicEntry.indexOf('const phase1EmployeeRequiredActions = computed', questionRowsStart);
const questionRowsSource = questionRowsStart >= 0 && questionRowsEnd > questionRowsStart
  ? publicEntry.slice(questionRowsStart, questionRowsEnd)
  : '';
const gapCodeTextStart = publicEntry.indexOf('const phase1EmployeeGapCodeText = (code) => {');
const gapCodeTextEnd = publicEntry.indexOf('const phase1EmployeePlatformText = (platform) => ({', gapCodeTextStart);
const gapCodeTextSource = gapCodeTextStart >= 0 && gapCodeTextEnd > gapCodeTextStart
  ? publicEntry.slice(gapCodeTextStart, gapCodeTextEnd)
  : '';
const actionCodeTextStart = publicEntry.indexOf('const phase1EmployeeActionCodeText = (code) => {');
const actionCodeTextEnd = publicEntry.indexOf('const phase1EmployeeQuestionNextActionText = (row) => {', actionCodeTextStart);
const actionCodeTextSource = actionCodeTextStart >= 0 && actionCodeTextEnd > actionCodeTextStart
  ? publicEntry.slice(actionCodeTextStart, actionCodeTextEnd)
  : '';
const questionNextActionStart = publicEntry.indexOf('const phase1EmployeeQuestionNextActionText = (row) => {');
const questionNextActionEnd = publicEntry.indexOf('const phase1EmployeeActionRawCode = (item) => {', questionNextActionStart);
const questionNextActionSource = questionNextActionStart >= 0 && questionNextActionEnd > questionNextActionStart
  ? publicEntry.slice(questionNextActionStart, questionNextActionEnd)
  : '';
const actionSuccessCriteriaStart = publicEntry.indexOf('const phase1EmployeeActionSuccessCriteriaText = (item) => {');
const actionSuccessCriteriaEnd = publicEntry.indexOf('const phase1EmployeeActionEvidenceNeededText = (item) => {', actionSuccessCriteriaStart);
const actionSuccessCriteriaSource = actionSuccessCriteriaStart >= 0 && actionSuccessCriteriaEnd > actionSuccessCriteriaStart
  ? publicEntry.slice(actionSuccessCriteriaStart, actionSuccessCriteriaEnd)
  : '';
const employeeActionExplanationStart = publicEntry.indexOf('const phase1EmployeeActionEmployeeExplanationText = (item) => {');
const employeeActionExplanationEnd = publicEntry.indexOf('const phase1EmployeeActionLimitedConclusionsText = (item) => {', employeeActionExplanationStart);
const employeeActionExplanationSource = employeeActionExplanationStart >= 0 && employeeActionExplanationEnd > employeeActionExplanationStart
  ? publicEntry.slice(employeeActionExplanationStart, employeeActionExplanationEnd)
  : '';
const actionExplanationNextActionStart = publicEntry.indexOf('const phase1EmployeeActionExplanationNextActionText = (item) => {');
const actionExplanationNextActionEnd = publicEntry.indexOf('const phase1EmployeeActionDisplayText = (item) => {', actionExplanationNextActionStart);
const actionExplanationNextActionSource = actionExplanationNextActionStart >= 0 && actionExplanationNextActionEnd > actionExplanationNextActionStart
  ? publicEntry.slice(actionExplanationNextActionStart, actionExplanationNextActionEnd)
  : '';
const actionMetaTextStart = publicEntry.indexOf('const phase1EmployeeActionMetaText = (item) => {');
const actionMetaTextEnd = publicEntry.indexOf('const phase1EmployeeActionProtectedBoundaryText = (item) => {', actionMetaTextStart);
const actionMetaTextSource = actionMetaTextStart >= 0 && actionMetaTextEnd > actionMetaTextStart
  ? publicEntry.slice(actionMetaTextStart, actionMetaTextEnd)
  : '';
const entryOptionGuidanceStart = publicEntry.indexOf('const phase1EmployeeActionEntryOptionGuidanceText = (option) => {');
const entryOptionGuidanceEnd = publicEntry.indexOf('const phase1EmployeeReadinessStatusText = (status) =>', entryOptionGuidanceStart);
const entryOptionGuidanceSource = entryOptionGuidanceStart >= 0 && entryOptionGuidanceEnd > entryOptionGuidanceStart
  ? publicEntry.slice(entryOptionGuidanceStart, entryOptionGuidanceEnd)
  : '';
const employeeSummaryTemplateStart = publicEntry.indexOf('data-testid="phase1-employee-field-trust-summary"');
const employeeSummaryTemplateEnd = publicEntry.indexOf('<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">', employeeSummaryTemplateStart);
const employeeSummaryTemplateSource = employeeSummaryTemplateStart >= 0 && employeeSummaryTemplateEnd > employeeSummaryTemplateStart
  ? publicEntry.slice(employeeSummaryTemplateStart, employeeSummaryTemplateEnd)
  : '';
const fieldTrustRowStart = publicEntry.indexOf('const normalizePhase1EmployeeFieldTrustRow = (row) => {');
const fieldTrustRowEnd = publicEntry.indexOf('const phase1EmployeeFieldTrustRows = computed', fieldTrustRowStart);
const fieldTrustRowSource = fieldTrustRowStart >= 0 && fieldTrustRowEnd > fieldTrustRowStart
  ? publicEntry.slice(fieldTrustRowStart, fieldTrustRowEnd)
  : '';
const aiEvidenceSummaryStart = publicEntry.indexOf('const phase1EmployeeAiEvidenceSummary = computed');
const aiEvidenceSummaryEnd = publicEntry.indexOf('const phase1EmployeeOperationSummary = computed', aiEvidenceSummaryStart);
const aiEvidenceSummarySource = aiEvidenceSummaryStart >= 0 && aiEvidenceSummaryEnd > aiEvidenceSummaryStart
  ? publicEntry.slice(aiEvidenceSummaryStart, aiEvidenceSummaryEnd)
  : '';
const operationSummaryStart = publicEntry.indexOf('const phase1EmployeeOperationSummary = computed');
const operationSummaryEnd = publicEntry.indexOf('const phase1EmployeeQuestionRows = computed', operationSummaryStart);
const operationSummarySource = operationSummaryStart >= 0 && operationSummaryEnd > operationSummaryStart
  ? publicEntry.slice(operationSummaryStart, operationSummaryEnd)
  : '';
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
    publicEntry.includes('backendSummary.top_action_live_closure_gap_codes.map(phase1EmployeeGapCodeText)') &&
    publicEntry.includes('phase1EmployeeKnownQuestionListText') &&
    publicEntry.includes('relatedQuestionKeysRawText') &&
    publicEntry.includes('topActionImpactRawText') &&
    gapCodeTextSource.includes('const questionText = phase1EmployeeKnownQuestionText(raw)') &&
    gapCodeTextSource.includes("[raw] || '未识别证据缺口')") &&
    !gapCodeTextSource.includes('phase1EmployeeQuestionKeyText(raw)') &&
    !gapCodeTextSource.includes('} || raw)') &&
    !publicEntry.includes('related_question_keys.map(phase1EmployeeQuestionKeyText)') &&
    !publicEntry.includes('top_action_related_question_keys.map(phase1EmployeeQuestionKeyText)'),
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
    actionCodeTextSource.includes("return '未识别补证动作'") &&
    actionCodeTextSource.includes("phase1EmployeeKnownQuestionText(localMatch[1]) || '未识别员工问题'") &&
    !actionCodeTextSource.includes('return raw;') &&
    publicEntry.includes('row.directNextActionText || row.directNextActionCode') &&
    publicEntry.includes('row.actionCodesText || row.actionCodes'),
  'phase1EmployeeActionCodeText + direct/primary/blocked/linked mappings'
);
check(
  'public/index.html',
  'employee question next action prefers readable action-code text',
  publicEntry.includes('phase1EmployeeQuestionNextActionText') &&
    publicEntry.includes('nextActionText: phase1EmployeeQuestionNextActionText(row)') &&
    publicEntry.includes('nextActionText: backendRow?.nextActionText || localRow?.nextActionText ||') &&
    publicEntry.includes('row.nextActionText || row.employeeNextActionText || row.nextAction') &&
    publicEntry.includes("String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || '').trim()") &&
    publicEntry.includes("next_action: String(row?.nextActionText || row?.employeeNextActionText || row?.nextAction || '复核当前问题对应证据。')") &&
    publicEntry.includes('row?.direct_next_action_code || row?.evidence?.direct_next_action_code') &&
    publicEntry.includes('row?.primary_next_action_code || row?.evidence?.primary_next_action_code') &&
    publicEntry.includes('row?.next_action_codes') &&
    questionNextActionSource.includes("const fallbackQuestionText = phase1EmployeeKnownQuestionText(row?.key || row?.question || '') || '当前员工问题';") &&
    questionNextActionSource.includes('return `先处理${primaryText}，再执行${directText || `补齐${fallbackQuestionText}证据`}`;') &&
    questionNextActionSource.includes("return questionText ? `补齐${questionText}证据` : '按动作队列补齐证据'") &&
    !questionNextActionSource.includes("phase1EmployeeQuestionKeyText(row?.key || row?.question || '')") &&
    !questionNextActionSource.includes("return String(row?.next_action || row?.nextAction || '').trim();"),
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
    publicEntry.includes('const rowBlocking = Array.isArray(row?.blocking_gap_codes)') &&
    publicEntry.includes('const allBlocking = Array.from(new Set([...blocking, ...rowBlocking]))') &&
    publicEntry.includes('const dataGapPresent = evidence.data_gap_evidence_present === true || allBlocking.length > 0') &&
    publicEntry.includes('phase1EmployeeAiJudgementText') &&
    publicEntry.includes('phase1EmployeeAiLimitText') &&
    publicEntry.includes('phase1EmployeeOperationJudgementText') &&
    publicEntry.includes('phase1EmployeeOperationLimitText') &&
    publicEntry.includes("blockingText: blocking.map(phase1EmployeeGapCodeText).filter(Boolean).join('、')") &&
    publicEntry.includes("blockingRawText: blocking.join('、')") &&
    publicEntry.includes('phase1EmployeeAiEvidenceSummary.blockingRawText || phase1EmployeeAiEvidenceSummary.blockingText') &&
    publicEntry.includes('phase1EmployeeOperationSummary.blockingRawText || phase1EmployeeOperationSummary.blockingText') &&
    publicEntry.includes('phase1EmployeeAiEvidenceSummary.judgementRawText || phase1EmployeeAiEvidenceSummary.judgementText') &&
    publicEntry.includes('phase1EmployeeAiEvidenceSummary.limitRawText || phase1EmployeeAiEvidenceSummary.limitText') &&
    publicEntry.includes('phase1EmployeeOperationSummary.judgementRawText || phase1EmployeeOperationSummary.judgementText') &&
    publicEntry.includes('phase1EmployeeOperationSummary.limitRawText || phase1EmployeeOperationSummary.limitText') &&
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
    publicEntry.includes('detail: String(row?.employee_detail || row?.detail || row?.message || \'\')') &&
    publicEntry.includes('detailRawText: String(row?.detail || row?.message || \'\')') &&
    publicEntry.includes('employeeNextActionText: String(row?.employee_next_action || \'\')') &&
    publicEntry.includes(':title="row.detailRawText || row.detail"') &&
    publicEntry.includes(':title="row.nextActionRawText || row.employeeNextActionText || row.nextActionText"') &&
    publicEntry.includes('const merged = { ...(localRow || {}), ...(backendRow || {}) };') &&
    publicEntry.includes('detail: backendRow?.detail || localRow?.detail ||') &&
    publicEntry.includes('detailRawText: backendRow?.detailRawText || localRow?.detailRawText ||') &&
    publicEntry.includes('nextActionText: backendRow?.nextActionText || localRow?.nextActionText ||') &&
    publicEntry.includes('nextActionRawText: backendRow?.nextActionRawText || localRow?.nextActionRawText ||') &&
    publicEntry.includes('employeeNextActionText: backendRow?.employeeNextActionText || localRow?.employeeNextActionText ||') &&
    publicEntry.includes('blockingReasonText: backendRow?.blockingReasonText || localRow?.blockingReasonText ||') &&
    !publicEntry.includes('const merged = { ...(backendRow || {}), ...(localRow || {}) };') &&
    !publicEntry.includes('nextActionText: localRow?.nextActionText || backendRow?.nextActionText ||') &&
    !publicEntry.includes('blockingReasonText: localRow?.blockingReasonText || backendRow?.blockingReasonText ||') &&
    publicEntry.includes("['today_ota_collected', 'trusted_fields', 'missing_fields'].includes(row.key)") &&
    publicEntry.includes('return phase1EmployeeQuestionPresentationRow(row, local)'),
  'presentation fallback keeps backend facts but uses stable readable display text'
);
check(
  'public/index.html',
  'employee closure summary maps missing question keys and top action codes',
  publicEntry.includes('backendMissingQuestionKeys.map(phase1EmployeeKnownQuestionText)') &&
    publicEntry.includes('phase1EmployeeKnownQuestionText') &&
    publicEntry.includes('phase1EmployeeActionDisplayText({') &&
    publicEntry.includes('topActionTextRaw') &&
    publicEntry.includes('unresolvedQuestionTextRaw') &&
    publicEntry.includes('phase1EmployeeClosureSummary.topActionTextRaw || phase1EmployeeClosureSummary.topActionText') &&
    publicEntry.includes('phase1EmployeeClosureSummary.unresolvedQuestionTextRaw || phase1EmployeeClosureSummary.unresolvedQuestionText') &&
    publicEntry.includes("return '现有首要补证动作'") &&
    publicEntry.includes("unresolvedCount > 0 ? '未识别员工问题' : ''") &&
    !publicEntry.includes("|| topActionTextRaw ||") &&
    !publicEntry.includes(").join('、') || unresolvedQuestionTextRaw") &&
    !publicEntry.includes("return String(source.next_action || source.action || '').trim();"),
  'missing_question_keys/top_action_code readable summary mappings with raw-title trace'
);
check(
  'public/index.html',
  'employee question evidence maps platform date relation and metric domain labels',
  questionEvidenceSource.includes('platformCoverage.missing_platforms.filter(Boolean).map(phase1EmployeePlatformText)') &&
    questionEvidenceSource.includes("const platform = phase1EmployeePlatformText(row?.platform || '')") &&
    questionEvidenceSource.includes('phase1EmployeeDateRelationText(latestRelation)') &&
    questionEvidenceSource.includes('items.filter(Boolean).map(phase1EmployeePlatformText).join') &&
    questionEvidenceSource.includes('row.missing_domains.filter(Boolean).map(phase1MetricDomainMissingLabel).join') &&
    questionRowsSource.includes('const sourceDateMissingPlatformText = sourceDateMissingPlatforms.map(phase1EmployeePlatformText).join') &&
    questionRowsSource.includes('；缺失平台 ${sourceDateMissingPlatformText}') &&
    !questionEvidenceSource.includes("const platform = String(row?.platform || '').toUpperCase()") &&
    !questionEvidenceSource.includes("latestRelation ? `(${latestRelation})`") &&
    !questionEvidenceSource.includes("String(row?.platform || '').toUpperCase()}:${domains}"),
  'six-question evidence details use readable platform/date/domain labels while raw values stay structured'
);
check(
  'public/index.html',
  'employee question evidence maps gap action entry and criteria codes',
  questionEvidenceSource.includes('const phase1EmployeeReadableGapText = (code) => {') &&
    questionEvidenceSource.includes('const fieldText = phase1MissingFieldLabel(raw)') &&
    questionEvidenceSource.includes('const gapText = phase1EmployeeGapCodeText(raw)') &&
    questionEvidenceSource.includes('const phase1EmployeeReadableActionOrGapText = (code) => {') &&
    questionEvidenceSource.includes('const actionText = phase1EmployeeActionCodeText(raw)') &&
    questionEvidenceSource.includes('const metricDomainGapCodes = evidenceKeyText(evidence.metric_domain_gap_codes, 4, phase1EmployeeReadableGapText)') &&
    questionEvidenceSource.includes('const dataGapCodes = evidenceKeyText(evidence.data_gap_codes, 4, phase1EmployeeReadableGapText)') &&
    questionEvidenceSource.includes('const missingFieldCodes = evidenceKeyText(evidence.missing_field_codes, 4, phase1EmployeeReadableGapText)') &&
    publicEntry.includes('normalizePhase1EmployeeMissingFieldSummaryRow') &&
    publicEntry.includes('evidence.missing_field_summary.map(normalizePhase1EmployeeMissingFieldSummaryRow)') &&
    publicEntry.includes('String(source.business_impact || source.businessImpact ||') &&
    publicEntry.includes('String(source.next_action || source.nextAction ||') &&
    questionEvidenceSource.includes('const fieldPendingActionCodes = evidenceKeyText(evidence.field_pending_action_codes, 4, phase1EmployeeReadableActionOrGapText)') &&
    questionEvidenceSource.includes('const blockedActionCodes = evidenceKeyText(evidence.blocked_action_codes, 3, phase1EmployeeReadableActionOrGapText)') &&
    questionEvidenceSource.includes('const blockingMissingCodes = evidenceKeyText(evidence.blocking_missing_codes, 5, phase1EmployeeReadableGapText)') &&
    questionEvidenceSource.includes('入口 ${phase1EmployeeActionEntryText(evidence.direct_next_action_entry, nextActionContext)}') &&
    questionEvidenceSource.includes('完成判定 ${phase1EmployeeActionSuccessCriteriaText({') &&
    questionEvidenceSource.includes("parts.push('需复核指标可信证据')") &&
    !questionEvidenceSource.includes('const metricDomainGapCodes = evidenceKeyText(evidence.metric_domain_gap_codes);') &&
    !questionEvidenceSource.includes('if (dataGapCodes) parts.push(`data_gaps ${dataGapCodes}`)') &&
    !questionEvidenceSource.includes('if (evidence.direct_next_action_entry) parts.push(`入口 ${evidence.direct_next_action_entry}`)') &&
    !questionEvidenceSource.includes('if (evidence.direct_next_action_success_criteria) parts.push(`完成判定 ${evidence.direct_next_action_success_criteria}`)') &&
    !questionEvidenceSource.includes("parts.push('需复核 metric_trust')"),
  'six-question evidence gap/action/entry/criteria raw codes are mapped before display'
);
check(
  'public/index.html',
  'employee summaries avoid technical evidence names in visible copy',
  employeeSummaryTemplateSource.includes('只按目标日源数据 + 指标可信证据判断') &&
    employeeSummaryTemplateSource.includes('只读证据来源、数据缺口和动作项') &&
    employeeSummaryTemplateSource.includes('只读执行意图和执行流') &&
    employeeSummaryTemplateSource.includes(':title="row.metricRawText || row.metricText"') &&
    !employeeSummaryTemplateSource.includes('只按目标日源数据 + metric_trust 判断') &&
    !employeeSummaryTemplateSource.includes('只读 evidence_sources / data_gaps / action_items') &&
    !employeeSummaryTemplateSource.includes('只读 execution_intents / execution_flow'),
  'field trust / AI / operation summary headers use readable copy and raw details stay in titles'
);
check(
  'public/index.html',
  'employee field trust row maps metric trust status to readable text',
  fieldTrustRowSource.includes('const metricStatusText = ({') &&
    publicEntry.includes("target_date_revenue_sample_present: '待指标可信证据'") &&
    fieldTrustRowSource.includes('platformLabel: phase1EmployeePlatformText(platform)') &&
    fieldTrustRowSource.includes('metricText: `目标日 ${targetRows} 行 / 指标可信证据 ${trustKeyCount} 项 / ${metricStatusText}`') &&
    fieldTrustRowSource.includes('metricRawText: `target_date_rows=${targetRows} / metric_trust_key_count=${trustKeyCount} / metric_status=${metricStatusRaw}`') &&
    !fieldTrustRowSource.includes('metricText: `目标日 ${targetRows} 行 / metric_trust') &&
    !fieldTrustRowSource.includes("platformLabel: platform ? platform.toUpperCase() : 'OTA'"),
  'field trust metric text is readable while metric_trust raw count remains traceable'
);
check(
  'public/index.html',
  'employee AI and operation summaries use readable evidence names',
  aiEvidenceSummarySource.includes("phase1EmployeeCountItem('data_gaps', '数据缺口'") &&
    aiEvidenceSummarySource.includes('dataGapPresent = evidence.data_gap_evidence_present === true || allBlocking.length > 0') &&
    aiEvidenceSummarySource.includes('blockingText: allBlocking.map(phase1EmployeeGapCodeText).filter(Boolean).join') &&
    aiEvidenceSummarySource.includes('judgementText: phase1EmployeeAiJudgementText({ status, blockingCount: allBlocking.length, actionableCount })') &&
    aiEvidenceSummarySource.includes('limitText: phase1EmployeeAiLimitText({ blockingCount: allBlocking.length, actionableCount })') &&
    aiEvidenceSummarySource.includes('AI 建议必须引用证据来源、数据缺口和动作项') &&
    !aiEvidenceSummarySource.includes("phase1EmployeeCountItem('data_gaps', 'data_gaps'") &&
    !aiEvidenceSummarySource.includes('AI 建议必须引用 evidence_sources、data_gaps、action_items') &&
    operationSummarySource.includes('const linkedIntentCount = Number(evidence.ota_diagnosis_linked_intent_count || 0)') &&
    operationSummarySource.includes('const linkedFlowCount = Number(evidence.ota_diagnosis_linked_flow_item_count || 0)') &&
    operationSummarySource.includes('completion_signal_count=${completionSignalCount}') &&
    operationSummarySource.includes('judgementText: phase1EmployeeOperationJudgementText({ status, executionIntentCount, executionFlowItemCount, completionSignalCount })') &&
    operationSummarySource.includes('limitText: phase1EmployeeOperationLimitText({ completionSignalCount, linkedIntentCount, linkedFlowCount })') &&
    operationSummarySource.includes('先取得真实 OTA 诊断动作项') &&
    operationSummarySource.includes('只有可追溯到 OTA 诊断动作项且有审批、执行、复盘或 ROI 信号时才算闭环') &&
    !operationSummarySource.includes('先取得真实 OTA 诊断 action_items') &&
    !operationSummarySource.includes('OTA diagnosis action_items'),
  'AI/operation summary main text maps evidence_sources/data_gaps/action_items to readable labels'
);
check(
  'public/index.html',
  'employee question rows use readable evidence wording',
  questionRowsSource.includes('指标可信证据和数据质量状态复核') &&
    questionRowsSource.includes('按数据缺口、字段资产和质量任务处理缺口') &&
    questionRowsSource.includes('指标可信证据、数据缺口和目标日指标域证据') &&
    questionRowsSource.includes('确认返回证据来源、数据缺口和动作项') &&
    questionRowsSource.includes('缺少目标日来源证据') &&
    !questionRowsSource.includes('结合目标日样例、metric_trust') &&
    !questionRowsSource.includes('按 data_gaps、字段资产') &&
    !questionRowsSource.includes('引用 metric_trust、data_gaps') &&
    !questionRowsSource.includes('确认返回 evidence_sources、data_gaps、action_items') &&
    !questionRowsSource.includes('缺少 source_date_evidence'),
  'employee question card detail/next-action text avoids raw evidence field names'
);
check(
  'public/index.html',
  'employee action entry paths are mapped to readable entry names',
  publicEntry.includes('phase1EmployeeActionEntryText') &&
    publicEntry.includes('entryText: phase1EmployeeActionEntryText(item?.entry || \'\', item)') &&
    publicEntry.includes('row.directNextActionEntryText || row.directNextActionEntry') &&
    publicEntry.includes('v-if="item.entryText"') &&
    publicEntry.includes('入口：{{ item.entryText }}') &&
    publicEntry.includes(':title="item.entry || item.entryText"') &&
    publicEntry.includes('topActionEntryText') &&
    publicEntry.includes('v-if="phase1EmployeeClosureSummary.topActionEntryText || phase1EmployeeClosureSummary.topActionSuccessCriteria"') &&
    publicEntry.includes('入口：{{ phase1EmployeeClosureSummary.topActionEntryText }}') &&
    publicEntry.includes('美团手动 Cookie/API 获取入口') &&
    publicEntry.includes('美团浏览器 Profile 采集入口') &&
    publicEntry.includes('OTA 收益指标与标准事实核对') &&
    publicEntry.includes('AI 诊断证据核对入口') &&
    publicEntry.includes('运营执行意图入口') &&
    publicEntry.includes("return '现有核验入口'") &&
    !publicEntry.includes('入口：{{ phase1EmployeeClosureSummary.topActionEntryText || phase1EmployeeClosureSummary.topActionEntry }}') &&
    !publicEntry.includes('入口：{{ item.entryText || item.entry }}'),
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
  'employee traffic source summary exposes P0 gate metadata without raw commands',
  publicEntry.includes('trafficActionModeLabel') &&
    publicEntry.includes('trafficP0NextText') &&
    publicEntry.includes('p0_next_action_mode') &&
    publicEntry.includes('p0_next_step_count') &&
    publicEntry.includes('p0_pre_import_evidence_status') &&
    publicEntry.includes('p0_traffic_field_fact_status') &&
    publicEntry.includes('trafficFieldFactLabel') &&
    publicEntry.includes('no_target_date_traffic_rows') &&
    publicEntry.includes('目标日流量字段未加载') &&
    publicEntry.includes('p0_required_metric_keys') &&
    publicEntry.includes('p0_required_storage_fields') &&
    publicEntry.includes('p0_field_loop_matrix') &&
    publicEntry.includes('p0_traffic_closure_chain') &&
    publicEntry.includes('closureChainNoTargetCount') &&
    publicEntry.includes('closureChainVerifierCount') &&
    publicEntry.includes('closureChainReadyCount') &&
    publicEntry.includes('closureChainIncompleteCount') &&
    publicEntry.includes('p0_platform_hotel_identifier_source') &&
    publicEntry.includes('p0_platform_hotel_identifier_status') &&
    publicEntry.includes('p0_platform_hotel_identifier_policy') &&
    publicEntry.includes('platformHotelIdentifierStatus') &&
    publicEntry.includes('platformHotelIdentifierSource') &&
    publicEntry.includes('not raw IDs') &&
    publicEntry.includes('completeFieldLoopCount') &&
    publicEntry.includes('incompleteFieldLoopCount') &&
    publicEntry.includes('missingFieldLoopCount') &&
    publicEntry.includes('verifierFieldLoopCount') &&
    publicEntry.includes('闭环链') &&
    publicEntry.includes('链路未加载') &&
    publicEntry.includes('链路待复验') &&
    publicEntry.includes('字段矩阵') &&
    publicEntry.includes('未加载') &&
    publicEntry.includes('p0_source_chain_reference_only') &&
    publicEntry.includes('p0_source_chain_scope') &&
    publicEntry.includes('no_target_date_source_rows') &&
    publicEntry.includes('reference_only_non_traffic_source_rows') &&
    publicEntry.includes('目标日源数据未入库') &&
    publicEntry.includes('源证据仅参考') &&
    publicEntry.includes('需闭环指标') &&
    publicEntry.includes('入库字段') &&
    publicEntry.includes('trafficPreImportEvidenceLabel') &&
    publicEntry.includes('预导入证据未提供') &&
    publicEntry.includes('manual_login_state_verified') &&
    publicEntry.includes('browser_profile') &&
    publicEntry.includes('metadata_only_no_sensitive_commands'),
  'trafficActionModeLabel / trafficP0NextText / P0 next metadata'
);
check(
  'public/index.html',
  'employee action success criteria and evidence are mapped to readable labels',
    publicEntry.includes('phase1EmployeeActionSuccessCriteriaText') &&
    publicEntry.includes('phase1EmployeeActionEvidenceNeededText') &&
    publicEntry.includes('phase1EmployeeActionVerificationStepsText') &&
    publicEntry.includes('source.employee_success_criteria || source.employeeSuccessCriteria') &&
    publicEntry.includes('source.employee_evidence_needed || source.employeeEvidenceNeeded') &&
    publicEntry.includes('source.employee_verification_steps || source.employeeVerificationSteps') &&
    publicEntry.includes("employeeSuccessCriteria: String(item?.employee_success_criteria || '')") &&
    publicEntry.includes('employeeEvidenceNeeded: Array.isArray(item?.employee_evidence_needed)') &&
    publicEntry.includes('employeeVerificationSteps: Array.isArray(item?.employee_verification_steps)') &&
    publicEntry.includes('successCriteriaText: phase1EmployeeActionSuccessCriteriaText(item)') &&
    publicEntry.includes('evidenceNeededText: phase1EmployeeActionEvidenceNeededText(item)') &&
    publicEntry.includes('verificationStepsText: phase1EmployeeActionVerificationStepsText(item)') &&
    publicEntry.includes('v-if="item.successCriteriaText"') &&
    publicEntry.includes('v-if="item.evidenceNeededText"') &&
    publicEntry.includes('v-if="item.verificationStepsText"') &&
    publicEntry.includes('phase1EmployeeClosureSummary.topActionVerificationText') &&
    publicEntry.includes('v-if="row.directNextActionSuccessCriteriaText"') &&
    publicEntry.includes('{{ item.successCriteriaText }}') &&
    publicEntry.includes('{{ item.evidenceNeededText }}') &&
    publicEntry.includes('{{ item.verificationStepsText }}') &&
    publicEntry.includes('{{ row.directNextActionSuccessCriteriaText }}') &&
    publicEntry.includes('topActionVerificationText') &&
    publicEntry.includes('topActionSuccessCriteriaRaw') &&
    !publicEntry.includes('item.successCriteriaText || item.successCriteria') &&
    !publicEntry.includes('item.evidenceNeededText || item.evidenceNeeded') &&
    !publicEntry.includes('row.directNextActionSuccessCriteriaText || row.directNextActionSuccessCriteria') &&
    !publicEntry.includes('}) || topActionSuccessCriteriaRaw') &&
    publicEntry.includes('目标日入库行数 > 0；最近可用/历史数据只作参考') &&
    publicEntry.includes('AI 动作项不再被上游 OTA 缺口阻断') &&
    publicEntry.includes('原始完成条件仅保留追溯') &&
    publicEntry.includes('当前动作对应的目标日 OTA 证据、状态快照和缺口清单') &&
    actionSuccessCriteriaSource.includes("const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';") &&
    !actionSuccessCriteriaSource.includes('phase1EmployeeQuestionKeyText(questionKey)'),
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
    publicEntry.includes('source.employee_explanation_next_action || source.employeeExplanationNextAction') &&
    publicEntry.includes("employeeExplanationNextAction: String(item?.employee_explanation_next_action || '')") &&
    publicEntry.includes('employeeExplanationText: phase1EmployeeActionEmployeeExplanationText(item)') &&
    publicEntry.includes('limitedConclusionsText: phase1EmployeeActionLimitedConclusionsText(item)') &&
    publicEntry.includes('stillUsableMetricsText: phase1EmployeeActionStillUsableMetricsText(item)') &&
    publicEntry.includes('explanationNextActionText: phase1EmployeeActionExplanationNextActionText(item)') &&
    publicEntry.includes('item.employeeExplanationText || item.employeeExplanation') &&
    publicEntry.includes('item.limitedConclusionsText || item.limitedConclusions') &&
    publicEntry.includes('item.stillUsableMetricsText || item.stillUsableMetrics') &&
    publicEntry.includes('item.explanationNextActionText || item.explanationNextAction') &&
    employeeActionExplanationSource.includes("const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';") &&
    employeeActionExplanationSource.includes('return `${questionText}还没有形成完整证据，只能作为待补证据项处理。`;') &&
    actionExplanationNextActionSource.includes("const questionText = phase1EmployeeKnownQuestionText(questionKey) || '当前员工问题';") &&
    actionExplanationNextActionSource.includes('return `按“${questionText}”对应证据清单补齐后复跑员工六问。`;') &&
    !employeeActionExplanationSource.includes('phase1EmployeeQuestionKeyText(questionKey)') &&
    !actionExplanationNextActionSource.includes('phase1EmployeeQuestionKeyText(questionKey)'),
  'employee_explanation/limited_conclusions/still_usable_metrics/explanation_next_action mapped with raw-title trace'
);
check(
  'public/index.html',
  'employee action card title metadata and boundary prefer readable mapped text',
  publicEntry.includes('phase1EmployeeActionDisplayText') &&
    publicEntry.includes('phase1EmployeeActionOwnerText') &&
    publicEntry.includes('phase1EmployeeActionMetaText') &&
    publicEntry.includes('phase1EmployeeActionProtectedBoundaryText') &&
    publicEntry.includes('source.employee_action || source.employeeAction') &&
    publicEntry.includes("employeeAction: String(item?.employee_action || '')") &&
    publicEntry.includes('actionText: phase1EmployeeActionDisplayText(item)') &&
    publicEntry.includes('ownerText: phase1EmployeeActionOwnerText(item)') &&
    publicEntry.includes('actionMetaText: phase1EmployeeActionMetaText(item)') &&
    publicEntry.includes('protectedBoundaryText: phase1EmployeeActionProtectedBoundaryText(item)') &&
    publicEntry.includes('item.actionText || item.action') &&
    publicEntry.includes('item.actionMetaRawText || item.actionMetaText') &&
    publicEntry.includes('v-if="item.protectedBoundaryText"') &&
    publicEntry.includes('边界：{{ item.protectedBoundaryText }}') &&
    publicEntry.includes(':title="item.protectedBoundary || item.protectedBoundaryText"') &&
    publicEntry.includes('不改变采集逻辑和字段，不把缺失证据写成完成') &&
    actionMetaTextSource.includes("const questionText = phase1EmployeeKnownQuestionText(source.question_key || source.questionKey || '') || '当前员工问题';") &&
    !actionMetaTextSource.includes("phase1EmployeeQuestionKeyText(source.question_key || source.questionKey || '')") &&
    !publicEntry.includes('边界：{{ item.protectedBoundaryText || item.protectedBoundary }}') &&
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
  'employee missing field codes use readable impact and action labels',
    publicEntry.includes('phase1MissingFieldDetailText') &&
    publicEntry.includes('phase1MissingFieldNextActionText') &&
    publicEntry.includes('phase1MissingFieldSourceText') &&
    publicEntry.includes('normalizePhase1EmployeeMissingFieldSummaryRow') &&
    publicEntry.includes('sourceText: String(source.source_text || source.sourceText ||') &&
    publicEntry.includes('detailText: String(source.business_impact || source.businessImpact ||') &&
    publicEntry.includes('nextActionText: String(source.next_action || source.nextAction ||') &&
    publicEntry.includes('缺可售房晚，暂不能可靠计算 OCC、RevPAR 或可售基准') &&
    publicEntry.includes('缺佣金金额或佣金率，暂不能核算净收入和渠道成本') &&
    publicEntry.includes('按字段资产核对平台返回和入库字段，再重跑收益指标核验') &&
    publicEntry.includes('来自数据缺口和字段缺口证据') &&
    publicEntry.includes('{{ row.detailText }}') &&
    publicEntry.includes('处理：{{ row.nextActionText }}') &&
    publicEntry.includes(':title="row.code"') &&
    publicEntry.includes(':title="row.nextActionRawText || row.nextActionText"') &&
    publicEntry.includes(':title="row.policyRawText || row.policyText"') &&
    !publicEntry.includes('<div class="mt-1 text-xs text-slate-500 truncate" :title="row.code">{{ row.code }}</div>') &&
    !publicEntry.includes('<div class="text-[11px] text-amber-700">来自 data_gaps / missing_field_codes</div>'),
  'missing field/data gap codes mapped to readable business impact and action with raw title trace'
);
check(
  'public/index.html',
  'employee metric domain evidence uses readable platform and data-type labels',
  publicEntry.includes('phase1MetricDomainPlatformText') &&
    publicEntry.includes('phase1MetricDomainDataTypeText') &&
    publicEntry.includes("ctrip: '携程'") &&
    publicEntry.includes("meituan: '美团'") &&
    publicEntry.includes("return '经营/收益'") &&
    publicEntry.includes("return '流量/转化'") &&
    metricDomainRowSource.includes('sourceText: `目标日源数据 ${sourceRows} 行 / 流量事实 ${trafficRows} 行`') &&
    publicEntry.includes('normalizePhase1EmployeeMetricDomainSummaryRow') &&
    publicEntry.includes('revenueQuestion.evidence.metric_domain_summary') &&
    publicEntry.includes('summaryRows.map(normalizePhase1EmployeeMetricDomainSummaryRow)') &&
    publicEntry.includes('String(source.problem || source.problemText ||') &&
    publicEntry.includes('String(source.next_action || source.nextAction ||') &&
    metricDomainRowSource.includes('target_date_data_types=${dataTypes.join') &&
    metricDomainRowSource.includes('phase1MetricDomainProblemText') &&
    metricDomainRowSource.includes('phase1MetricDomainNextActionText') &&
    metricDomainRowSource.includes('收益可先复核；流量/转化缺失，不能判断曝光到下单漏斗。') &&
    metricDomainRowSource.includes('补齐流量/转化事实，再复核漏斗诊断。') &&
    metricDomainRowSource.includes('目标日源数据缺失，收益、流量、转化都不能证明。') &&
    metricDomainRowSource.includes('missingText: missingDomains.join') &&
    metricDomainRowSource.includes('Array.from(new Set(row.missing_domains.map(phase1MetricDomainMissingLabel).filter(Boolean)))') &&
    publicEntry.includes('判断：{{ row.problemText }}') &&
    publicEntry.includes('处理：{{ row.nextActionText }}') &&
    publicEntry.includes(':title="row.problemRawText || row.problemText"') &&
    publicEntry.includes(':title="row.nextActionRawText || row.nextActionText"') &&
    publicEntry.includes(':title="row.sourceRawText || row.sourceText"') &&
    publicEntry.includes(':title="row.policyRawText || row.policyText"') &&
    !metricDomainRowSource.includes('platformLabel: platform ? platform.toUpperCase() :') &&
    !metricDomainRowSource.includes('trafficRows ?') &&
    !metricDomainRowSource.includes('sourceText: `源数据 ${sourceRows} 行${trafficRows ? ` / traffic ${trafficRows} 行` : \'\'}') &&
    !metricDomainRowSource.includes('policyText: `只读目标日指标域${dataTypes.length ? ` / ${dataTypes.join'),
  'metric domain platform/data-type/source labels mapped to readable text with raw title trace'
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
  'employee collection source summary maps platform data type and date relation labels',
  collectionSourceRowSource.includes('phase1EmployeeCollectionDataTypeText') &&
    collectionSourceRowSource.includes("return '经营/收益'") &&
    collectionSourceRowSource.includes("return '流量/转化'") &&
    collectionSourceRowSource.includes('const latestRelationText = phase1EmployeeDateRelationText(latestRelation);') &&
    collectionSourceRowSource.includes('platformLabel: phase1EmployeePlatformText(platform)') &&
    collectionSourceRowSource.includes('targetText: `目标日 ${targetRows} 行${targetTypeText ? ` / ${targetTypeText}` : \'\'}') &&
    collectionSourceRowSource.includes('latestRelationText ? ` / ${latestRelationText}` : \'\'') &&
    collectionSourceRowSource.includes('targetRawText: `target_date_data_types=${targetTypes.join') &&
    collectionSourceRowSource.includes('latestRawText: `latest_available.date=${latestDate || \'empty\'} / date_relation=${latestRelation || \'empty\'}') &&
    publicEntry.includes(':title="row.targetRawText || row.targetText"') &&
    publicEntry.includes(':title="row.latestRawText || row.latestText"') &&
    !collectionSourceRowSource.includes('platformLabel: platform ? platform.toUpperCase()') &&
    !collectionSourceRowSource.includes('latestRelation ? ` / ${latestRelation}`'),
  'collection_source_summary platform/data type/date relation are readable with raw title trace'
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
check(
  'public/index.html',
  'employee entry option guidance uses stable mode text with raw title trace',
  publicEntry.includes('phase1EmployeeActionEntryOptionPlatformText') &&
    publicEntry.includes('phase1EmployeeActionEntryOptionInputText') &&
    publicEntry.includes('phase1EmployeeActionEntryOptionContractText') &&
    entryOptionGuidanceSource.includes('manual_cookie_api') &&
    entryOptionGuidanceSource.includes('用于已取得${platformText} Cookie/Payload/导出上下文时补齐目标日数据') &&
    entryOptionGuidanceSource.includes('browser_profile') &&
    entryOptionGuidanceSource.includes('用于${platformPrefix}已授权本机浏览器 Profile 走现有自动采集路径') &&
    entryOptionGuidanceSource.includes('status_check') &&
    entryOptionGuidanceSource.includes('用于只读核对目标日入库、最近可用日期和失败原因') &&
    entryOptionGuidanceSource.includes('不改变采集逻辑和字段') &&
    entryOptionGuidanceSource.includes('phase1EmployeeActionEntryOptionContractText(option)') &&
    publicEntry.includes("String(contract.target_data_type || '').trim() !== 'traffic'") &&
    publicEntry.includes('contract.required_metric_keys') &&
    publicEntry.includes('contract.required_storage_fields') &&
    publicEntry.includes('contract.required_inputs') &&
    publicEntry.includes('contract.required_field_fact_keys') &&
    publicEntry.includes('contract.sensitive_values_allowed === false') &&
    publicEntry.includes('需闭环指标') &&
    publicEntry.includes('需入库字段') &&
    publicEntry.includes('需补输入') &&
    publicEntry.includes('需证明采集证据、source path、metric key、入库字段和已入库值') &&
    publicEntry.includes('不展示 Cookie、token 或 Profile 原值') &&
    entryOptionGuidanceSource.includes('phase1EmployeeActionEntryOptionGuidanceRawText') &&
    entryOptionGuidanceSource.includes("String(option.use_when || '').trim()") &&
    publicEntry.includes('entryOptionGuidanceRawText: entryOptionGuidanceRaw.join') &&
    publicEntry.includes('topActionEntryOptionGuidanceRawText') &&
    publicEntry.includes(':title="item.entryOptionGuidanceRawText || item.entryOptionGuidanceText"') &&
    publicEntry.includes(':title="phase1EmployeeClosureSummary.topActionEntryOptionGuidanceRawText || phase1EmployeeClosureSummary.topActionEntryOptionGuidanceText"') &&
    !entryOptionGuidanceSource.includes('const details = [useWhen, requires, boundary]'),
  'entry option guidance is mode-derived; raw use_when/requires/boundary stay in title trace'
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
  'unknown action question_key stays out of main employee copy',
  acceptanceDoc.includes('未知 `question_key`') &&
    acceptanceDoc.includes('主文案只能显示“当前员工问题”或“未识别员工问题”') &&
    acceptanceDoc.includes('原始 key 只能保留在标题追溯或结构化响应中'),
  'unknown question_key maps to readable placeholder with raw trace only'
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
    acceptanceDoc.includes('入口选择说明也必须由前端按 `mode` 生成稳定员工话术') &&
    acceptanceDoc.includes('不能把后端 `use_when`、`requires`、`boundary` 原样作为主展示文案') &&
    acceptanceDoc.includes('原始 `use_when/requires/boundary` 只能保留在结构化数据或标题追溯中') &&
    acceptanceDoc.includes('不能因为 label 编码异常'),
  'entry_options mode stable labels / raw label entry and guidance title trace'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'traffic entry input contract is documented as requirement not completion proof',
  acceptanceDoc.includes('entry_options[].input_contract') &&
    acceptanceDoc.includes('entry_options[].acceptance_contract') &&
    acceptanceDoc.includes('target_data_type=traffic') &&
    acceptanceDoc.includes('required_metric_keys') &&
    acceptanceDoc.includes('required_storage_fields') &&
    acceptanceDoc.includes('required_inputs') &&
    acceptanceDoc.includes('required_field_fact_keys') &&
    acceptanceDoc.includes('sensitive_values_allowed=false') &&
    acceptanceDoc.includes('需闭环指标') &&
    acceptanceDoc.includes('需补输入') &&
    acceptanceDoc.includes('需证明采集证据、source path、metric key、入库字段和已入库值') &&
    acceptanceDoc.includes('不展示 Cookie、token 或 Profile 原值') &&
    acceptanceDoc.includes('不能作为采集成功、目标日入库完成或 P0 闭环完成证据') &&
    acceptanceDoc.includes('状态核对入口不能挂采集型 input contract'),
  'input_contract / acceptance_contract / not completion proof'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'traffic source P0 gate metadata is documented',
  acceptanceDoc.includes('p0_traffic_gate_status') &&
    acceptanceDoc.includes('p0_next_action_mode') &&
    acceptanceDoc.includes('p0_next_action_entry') &&
    acceptanceDoc.includes('p0_next_step_count') &&
    acceptanceDoc.includes('p0_external_evidence_status') &&
    acceptanceDoc.includes('p0_pre_import_evidence_status') &&
    acceptanceDoc.includes('p0_pre_import_evidence_policy') &&
    acceptanceDoc.includes('no_target_date_traffic_rows') &&
    acceptanceDoc.includes('p0_field_loop_matrix') &&
    acceptanceDoc.includes('p0_traffic_closure_chain') &&
    acceptanceDoc.includes('p0_traffic_closure_chain_policy') &&
    acceptanceDoc.includes('platform_hotel_identifier') &&
    acceptanceDoc.includes('whole-hotel operating truth') &&
    acceptanceDoc.includes('p0_platform_hotel_identifier_source') &&
    acceptanceDoc.includes('p0_platform_hotel_identifier_status') &&
    acceptanceDoc.includes('p0_platform_hotel_identifier_policy') &&
    acceptanceDoc.includes('hotel_id_family') &&
    acceptanceDoc.includes('poi_id_family') &&
    acceptanceDoc.includes('OTA 酒店 ID/POI ID') &&
    acceptanceDoc.includes('complete') &&
    acceptanceDoc.includes('incomplete') &&
    acceptanceDoc.includes('requires_p0_verifier') &&
    acceptanceDoc.includes('字段矩阵') &&
    acceptanceDoc.includes('p0_target_traffic_data_types') &&
    acceptanceDoc.includes('p0_source_chain_reference_only') &&
    acceptanceDoc.includes('p0_source_chain_scope') &&
    acceptanceDoc.includes('p0_source_chain_policy') &&
    acceptanceDoc.includes('no_target_date_source_rows') &&
    acceptanceDoc.includes('reference_only_non_traffic_source_rows') &&
    acceptanceDoc.includes('next_command_policy=metadata_only_no_sensitive_commands') &&
    acceptanceDoc.includes('manual_login_state_verified') &&
    acceptanceDoc.includes('browser_profile'),
  'P0 gate metadata / next action mode / manual login state evidence'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action code mapping is documented',
  acceptanceDoc.includes('direct_next_action_code') &&
    acceptanceDoc.includes('映射成员工可读动作名') &&
    acceptanceDoc.includes('原始 action code 仍保留') &&
    acceptanceDoc.includes('未知 action code') &&
    acceptanceDoc.includes('未识别补证动作'),
  'direct_next_action_code / 映射成员工可读动作名 / unknown action code boundary'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'unknown related_question_keys are raw-title only',
  acceptanceDoc.includes('未知 `related_question_keys`') &&
    acceptanceDoc.includes('未识别员工问题') &&
    acceptanceDoc.includes('原始 key 只能保留在标题追溯或结构化响应中'),
  'unknown related_question_keys do not become main employee copy'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question next_action readable mapping is documented',
  acceptanceDoc.includes('展示 `next_action`/`nextAction`') &&
    acceptanceDoc.includes('direct_next_action_code') &&
    acceptanceDoc.includes('primary_next_action_code') &&
    acceptanceDoc.includes('next_action_codes') &&
    acceptanceDoc.includes('映射成员工可读下一步') &&
    acceptanceDoc.includes('原始 `next_action` 只能保留') &&
    acceptanceDoc.includes('按动作队列补齐证据'),
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
  'AI and operation judgement limit summaries are documented',
  acceptanceDoc.includes('`ai_evidence` 摘要必须把 `blocking_missing_codes` 和行级 `blocking_gap_codes` 合并') &&
    acceptanceDoc.includes('`data_gaps` 主展示就必须显示“已返回”') &&
    acceptanceDoc.includes('AI 建议依据已暴露上游缺口，动作项仍被阻断') &&
    acceptanceDoc.includes('不能把 blocked 动作项当成可执行经营建议') &&
    acceptanceDoc.includes('运营执行摘要必须展示员工可读“判断”和“限制”') &&
    acceptanceDoc.includes('还没有可追溯执行意图或执行流') &&
    acceptanceDoc.includes('不能证明动作已落地') &&
    acceptanceDoc.includes('不能把未关联 OTA 诊断的普通执行记录算作闭环'),
  'AI/operation judgement and limit copy documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question presentation fallback boundary is documented',
    acceptanceDoc.includes('缺少 `detail`/`message` 展示说明') &&
    acceptanceDoc.includes('复用本地六问说明文本') &&
    acceptanceDoc.includes('不能覆盖后端 `status`') &&
    acceptanceDoc.includes('不能把本地说明当作采集成功') &&
    acceptanceDoc.includes('`nextActionText`、`blockingReasonText` 这类员工主文案必须优先使用后端动作码、缺口码和 `employee_*` 字段派生出的稳定映射结果') &&
    acceptanceDoc.includes('本地六问说明只能在后端缺展示文本时兜底') &&
    acceptanceDoc.includes('`employee_detail`、`employee_next_action`') &&
    acceptanceDoc.includes('`employee_detail` 不能为空') &&
    acceptanceDoc.includes('不能只依赖前端本地说明兜底') &&
    acceptanceDoc.includes('等技术字段名，以及') &&
    acceptanceDoc.includes('只能出现在原始字段、证据键、title 追溯或契约检查里') &&
    acceptanceDoc.includes('`CTRIP`、`MEITUAN` 这类平台码') &&
    acceptanceDoc.includes('不能直接作为员工卡片正文或下一步主文案'),
  'backend facts remain authoritative when local detail text fills card explanation'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee closure summary readable mapping is documented',
  acceptanceDoc.includes('missing_question_keys') &&
    acceptanceDoc.includes('top_action_code') &&
    acceptanceDoc.includes('top_action_entry') &&
    acceptanceDoc.includes('top_action_success_criteria') &&
    acceptanceDoc.includes('映射成员工可读问题名、动作名、入口名和完成判定') &&
    acceptanceDoc.includes('API 路径') &&
    acceptanceDoc.includes('不能让后端原始文案、API 路径、编码异常或技术码替代稳定展示') &&
    acceptanceDoc.includes('未知 `missing_question_keys`') &&
    acceptanceDoc.includes('现有首要补证动作'),
  'missing_question_keys/top_action_code/top_action_entry mapped to readable closure summary text'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee action success criteria and evidence mapping is documented',
  acceptanceDoc.includes('success_criteria') &&
    acceptanceDoc.includes('evidence_needed') &&
    acceptanceDoc.includes('employee_action') &&
    acceptanceDoc.includes('employee_evidence_needed') &&
    acceptanceDoc.includes('employee_success_criteria') &&
    acceptanceDoc.includes('employee_explanation_next_action') &&
    acceptanceDoc.includes('employee_verification_steps') &&
    acceptanceDoc.includes('作为“复核方式”') &&
    acceptanceDoc.includes('执行动作后应刷新哪个闭环') &&
    acceptanceDoc.includes('映射成员工可读完成判定和所需证据') &&
    acceptanceDoc.includes('原始技术值仍保留') &&
    acceptanceDoc.includes('不能作为主文案') &&
    acceptanceDoc.includes('不能把可读文案当作采集成功或闭环完成证据'),
  'success_criteria/evidence_needed 映射成员工可读完成判定和所需证据 / 原始技术值仍保留'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee gap codes mapping is documented',
  acceptanceDoc.includes('映射成员工可读缺口文案') &&
    acceptanceDoc.includes('原始技术码仍保留') &&
    acceptanceDoc.includes('未知缺口码主文案只能显示“未识别证据缺口”') &&
    acceptanceDoc.includes('不能直接把未知机器码作为主文案'),
  '映射成员工可读缺口文案 / unknown gap code boundary'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question evidence readable platform/date/domain mapping is documented',
  acceptanceDoc.includes('员工六问的证据摘要展示平台覆盖、平台明细、最近可用日期关系和指标域缺失') &&
    acceptanceDoc.includes('latest_available.date_relation') &&
    acceptanceDoc.includes('revenue/traffic/conversion') &&
    acceptanceDoc.includes('映射成员工可读的平台、日期关系和指标域名称') &&
    acceptanceDoc.includes('stale_before_target') &&
    acceptanceDoc.includes('future_dated_for_target') &&
    acceptanceDoc.includes('metric_domain_readiness.missing_domains') &&
    acceptanceDoc.includes('不能作为主文案'),
  'six-question evidence platform/date/domain raw codes are title/structured only'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee question evidence gap action entry criteria mapping is documented',
  acceptanceDoc.includes('员工六问的证据摘要展示 `metric_domain_gap_codes`') &&
    acceptanceDoc.includes('data_gap_codes') &&
    acceptanceDoc.includes('missing_field_codes') &&
    acceptanceDoc.includes('field_pending_action_codes') &&
    acceptanceDoc.includes('blocked_action_codes') &&
    acceptanceDoc.includes('blocking_missing_codes') &&
    acceptanceDoc.includes('direct_next_action_entry') &&
    acceptanceDoc.includes('direct_next_action_success_criteria') &&
    acceptanceDoc.includes('映射成员工可读的指标域缺口、数据缺口、字段缺口、字段动作、阻断动作、阻断缺口、入口名称和完成判定') &&
    acceptanceDoc.includes('原始缺口码、动作码、API 路径和原始完成条件') &&
    acceptanceDoc.includes('不能作为主文案'),
  'six-question evidence gap/action/entry/criteria raw codes are title/structured only'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee summary technical evidence names are documented as raw-only',
  acceptanceDoc.includes('员工控制台的字段可信摘要、AI 依据摘要和运营执行摘要不得把 `metric_trust`') &&
    acceptanceDoc.includes('data_gaps') &&
    acceptanceDoc.includes('evidence_sources') &&
    acceptanceDoc.includes('action_items') &&
    acceptanceDoc.includes('execution_intents') &&
    acceptanceDoc.includes('execution_flow') &&
    acceptanceDoc.includes('source_date_evidence') &&
    acceptanceDoc.includes('“指标可信证据”“数据缺口”“证据来源”“动作项”“执行意图”“执行流”“目标日来源证据”') &&
    acceptanceDoc.includes('原始字段名只能用于结构化响应、title 追溯或契约检查'),
  'metric_trust/data_gaps/evidence_sources/action_items/execution terms are raw-only in summaries'
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
  'employee missing field code readable mapping is documented',
  acceptanceDoc.includes('缺失字段摘要展示 `data_gap_codes` 和 `missing_field_codes`') &&
    acceptanceDoc.includes('available_room_nights_missing') &&
    acceptanceDoc.includes('commission_fields_missing') &&
    acceptanceDoc.includes('net_revenue_fields_missing') &&
    acceptanceDoc.includes('lead_time_fields_missing') &&
    acceptanceDoc.includes('cancellation_fields_missing') &&
    acceptanceDoc.includes('cancel_room_nights_missing') &&
    acceptanceDoc.includes('competitor_price_fields_missing') &&
    acceptanceDoc.includes('映射成员工可读的业务影响和处理动作') &&
    acceptanceDoc.includes('missing_fields.evidence.missing_field_summary') &&
    acceptanceDoc.includes('`business_impact`') &&
    acceptanceDoc.includes('原始 `code` 只用于追溯') &&
    acceptanceDoc.includes('原始缺口码只能保留在标题追溯或结构化响应中') &&
    acceptanceDoc.includes('数据缺口 / 字段缺口') &&
    acceptanceDoc.includes('不能直接把 `data_gaps` 或 `missing_field_codes` 当作主文案'),
  'data_gap_codes/missing_field_codes readable business impact/action mapping documented'
);
check(
  'docs/phase1_ota_employee_console_acceptance.md',
  'employee metric domain readable mapping is documented',
    acceptanceDoc.includes('收入/流量/转化证据摘要展示 `platform`') &&
    acceptanceDoc.includes('metric_domain_summary') &&
    acceptanceDoc.includes('`platform_label`') &&
    acceptanceDoc.includes('`problem`') &&
    acceptanceDoc.includes('前端优先展示该摘要') &&
    acceptanceDoc.includes('target_date_data_types') &&
    acceptanceDoc.includes('source_rows') &&
    acceptanceDoc.includes('traffic_rows') &&
    acceptanceDoc.includes('revenue_status') &&
    acceptanceDoc.includes('traffic_status') &&
    acceptanceDoc.includes('conversion_status') &&
    acceptanceDoc.includes('映射成员工可读的平台、目标日源数据、流量事实、经营/收益、流量/转化和可复核/缺失状态') &&
    acceptanceDoc.includes('`traffic_rows=0` 也必须显示为“流量事实 0 行”') &&
    acceptanceDoc.includes('每个平台卡片还必须展示员工可读“判断”和“处理”') &&
    acceptanceDoc.includes('只能先复核收益、不能判断曝光到下单漏斗') &&
    acceptanceDoc.includes('补齐流量/转化事实后复核漏斗诊断') &&
    acceptanceDoc.includes('目标日源数据为 0 时，必须说明收益、流量、转化都不能证明') &&
    acceptanceDoc.includes('原始 `ctrip`、`meituan`、`business`、`traffic`') &&
    acceptanceDoc.includes('`revenue_status`、`traffic_status`、`conversion_status` 和 `missing_domains`') &&
    acceptanceDoc.includes('不能作为员工主文案') &&
    acceptanceDoc.includes('缺少流量或转化事实时必须明确显示“流量/转化缺失”'),
  'metric domain source/type/status readable labels and raw-title boundary documented'
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
  'employee collection source summary readable platform type date mapping is documented',
  acceptanceDoc.includes('平台源数据摘要展示 `collection_source_summary.platform`') &&
    acceptanceDoc.includes('target_date_data_types') &&
    acceptanceDoc.includes('latest_available.date_relation') &&
    acceptanceDoc.includes('主文案必须映射成“携程/美团”“经营/收益/流量/转化”“早于目标日/晚于目标日/目标日”') &&
    acceptanceDoc.includes('原始 `ctrip`、`meituan`、`business`、`traffic`、`stale_before_target`、`future_dated_for_target` 只能保留在标题追溯'),
  'collection_source_summary platform/type/date relation raw codes are title/structured only'
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
