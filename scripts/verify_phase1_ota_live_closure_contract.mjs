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

function matchingLinesExclude(file, label, marker, forbidden) {
  const lines = read(file).split(/\r?\n/).filter((line) => line.includes(marker));
  const violating = lines.filter((line) => line.includes(forbidden));
  check(
    file,
    label,
    lines.length > 0 && violating.length === 0,
    lines.length === 0 ? `missing marker: ${marker}` : violating.join(' | '),
  );
}

function appearsBefore(file, label, first, second) {
  const source = read(file);
  const firstIndex = source.indexOf(first);
  const secondIndex = source.indexOf(second);
  check(
    file,
    label,
    firstIndex >= 0 && secondIndex >= 0 && firstIndex < secondIndex,
    firstIndex < 0 ? `missing first: ${first}` : (secondIndex < 0 ? `missing second: ${second}` : 'wrong order'),
  );
}

function rangeExcludes(file, label, start, end, forbidden) {
  const source = read(file);
  const startIndex = source.indexOf(start);
  const endIndex = startIndex >= 0 ? source.indexOf(end, startIndex + start.length) : -1;
  const range = startIndex >= 0 && endIndex >= 0 ? source.slice(startIndex, endIndex + end.length) : '';
  const violating = forbidden.filter((needle) => range.includes(needle));
  check(
    file,
    label,
    range !== '' && violating.length === 0,
    range === '' ? `missing range: ${start} ... ${end}` : violating.join(', '),
  );
}

function rangeContract(file, label, start, end, required, forbidden = []) {
  const source = read(file);
  const startIndex = source.indexOf(start);
  const endIndex = startIndex >= 0 ? source.indexOf(end, startIndex + start.length) : -1;
  const range = startIndex >= 0 && endIndex >= 0 ? source.slice(startIndex, endIndex) : '';
  const missing = required.filter((needle) => !range.includes(needle));
  const violating = forbidden.filter((needle) => range.includes(needle));
  check(
    file,
    label,
    range !== '' && missing.length === 0 && violating.length === 0,
    range === ''
      ? `missing range: ${start} ... ${end}`
      : [...missing.map((needle) => `missing ${needle}`), ...violating.map((needle) => `forbidden ${needle}`)].join(', '),
  );
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

includesAll('docs/phase1_ota_live_closure_evidence.md', 'live closure evidence scope is explicit', [
  'capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution',
  '不启动携程或美团采集',
  'online_daily_data',
  'metric_trust',
  'data_gaps',
  'evidence_sources',
  'action_items',
  'next_actions',
  'collection_source_summary',
  'read_existing_online_daily_data_only',
  'collection_logic_changed',
  'blocked_by',
  'blocked_by_action_codes',
  'resolves_missing_codes',
  'question_key',
  'related_question_keys',
  'next_action_codes',
  'protected_boundary',
  'execution_intents',
  'execution_flow',
]);

includesAll('docs/phase1_ota_live_closure_evidence.md', 'live closure commands are documented', [
  'npm.cmd run inspect:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure-contract',
  '--strict',
  '--evidence=',
  '--format=markdown',
  'npm.cmd run verify:phase1-live-action-queue',
  'npm.cmd run build:phase1-live-evidence',
  'employee_questions',
  'closure_summary',
]);

includesAll('docs/phase1_ota_live_closure_evidence.md', 'employee console carriers are documented as read-only downstream state', [
  '员工控制台承载',
  '/api/online-data/collection-reliability',
  'phase1_employee_questions',
  'read_existing_collection_reliability_only',
  'phase1-employee-six-question-summary',
  '只读取已有采集可靠性、OTA 诊断和运营执行状态',
  '不启动携程/美团采集',
  '不改变手动或自动获取逻辑',
  '不新增、删除或重映射获取字段',
]);

includesAll('docs/phase1_ota_live_closure_evidence.md', 'latest available data cannot replace target-date evidence', [
  '最近可用数据说明',
  'latest_available',
  'target_date',
  'stale_before_target',
  'future_dated_for_target',
  '不能替代目标日闭环证据',
  '不读取或展示原始 `raw_data` 内容',
]);

includesAll('docs/phase1_ota_live_closure_evidence.md', 'next action queue keeps executable and blocked work distinct', [
  '`next_actions` 是员工可执行动作队列，不是采集器',
  '`status`：`missing` 表示可以直接补证据；`blocked` 表示必须先处理 `blocked_by`',
  '`entry`',
  '`success_criteria`',
  '`blocked_by_action_codes`',
  '`resolves_missing_codes`',
  '`protected_boundary`：不得改变携程/美团手动或自动获取逻辑，不得改变获取字段和字段映射',
  '排序规则：`missing` 动作先于 `blocked` 动作',
]);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'inspector reads live closure evidence without starting collection', [
  'online_daily_data',
  'vendor_autoload_missing',
  'source_rows_present',
  'OtaStandardEtlService',
  'OtaRevenueMetricService',
  'OtaDataCredibilityGateService',
  'evaluateRevenueAiReadiness',
  'Revenue metrics are trusted for revenue analysis.',
  'Revenue metrics are not trusted for revenue analysis.',
  'ai_metric_input_ready',
  'revenue_ready',
  'ai_ready',
  'ai_input_not_ready',
  "$revenueReady = ($revenueAiReadiness['revenue_ready'] ?? false) === true",
  "$aiInputReady = ($revenueAiReadiness['ai_ready'] ?? false) === true",
  'metric_trust',
  'data_gaps',
  'field_facts_visible',
  'field_fact_closure_summary',
  'field_fact_row_has_evidence_anchor',
  'ignored_unanchored_field_fact_rows',
  'ignored_unanchored_field_fact_count',
  "['source_trace_id', 'data_source_id', 'sync_task_id']",
  'capture_evidence_count',
  'structured_source_path_count',
  'source_path_structured',
  'field_fact_source_path_structured',
  'raw_data_exposed',
  "$evidence['source_url_hash'] ?? $evidence['_source_url_hash'] ?? $evidence['url_hash'] ?? $evidence['_url_hash'] ?? ''",
  '_field_facts_missing',
  '_field_fact_closure_incomplete',
  'Target-date source rows are missing; metric_trust cannot prove field trust for this platform.',
  'Revenue metrics are not ready; metric_trust cannot prove field trust for this platform.',
  'Field facts are not closed; metric_trust remains reference-only for this platform.',
  'metric_trust is present with target-date source rows and ready revenue metrics.',
  'ai_diagnosis_evidence',
  'ai_diagnosis_persistence',
  'ai_diagnosis_persistence_unverified',
  'strict_system_hotel_scope_required',
  'evidence_scope_system_hotel_mismatch',
  'saved_record',
  'readback_verified',
  'operation_execution_sample',
  'missing_requirements',
  'missing_requirement_employee_explanation',
  'missing_requirement_platform_label',
  "'status' => 'missing'",
  "$row['platform'] = $platform;",
  "$row['action_code'] = $actionCode;",
  "$row['action_family'] = inspection_next_action_family($actionCode);",
  "$row['question_key'] = inspection_next_action_question_key($actionCode);",
  "$row['related_question_keys'] = inspection_next_action_related_question_keys($actionCode);",
  "$row['resolves_missing_codes'] = inspection_next_action_resolves_missing_codes($actionCode);",
  "$row['live_closure_gap_codes'] = inspection_next_action_live_closure_gap_codes(['action_code' => $actionCode]);",
  'employee_explanation',
  'employee_detail',
  'employee_next_action',
  'missing_field_summary',
  'metric_domain_summary',
  'field_fact_closure_summary',
  'capture_evidence_count',
  'raw_data_exposed',
  '_field_facts_missing',
  '_field_fact_closure_incomplete',
  'employee_action',
  'employee_evidence_needed',
  'employee_success_criteria',
  'employee_explanation_next_action',
  'employee_verification_steps',
  'inspection_employee_readable_copy',
  'inspection_employee_question_detail',
  'inspection_employee_platform_list_text',
  'limited_conclusions',
  'still_usable_metrics',
  'explanation_next_action',
  '归属：',
  '员工解释',
  '受限结论',
  '仍可使用',
  '补证据动作',
  'employee_questions',
  'closure_summary',
  'top_action_code',
  'top_action_entry',
  'top_action_entry_options',
  'use_when',
  'requires',
  'boundary',
  'top_action_related_question_keys',
  'top_action_resolves_missing_codes',
  'top_action_live_closure_gap_codes',
  'top_action_platform',
  'top_action_source_snapshot',
  'top_action_success_criteria',
  'top_action_employee_text',
  'top_question_key',
  'missing_question_keys',
  '_entry_options',
  'entry_options',
  'readiness',
  'inspection_entry_option_readiness',
  'inspection_profile_directory_count',
  'requires_user_context',
  'profile_missing',
  'profile_found_login_unverified',
  'read_existing_collection_reliability_only',
  'read_local_profile_directory_names_only',
  'blocking_gap_codes',
  'reference_policy',
  'proof_requirement',
  'latest_available 只能作参考',
  'collection_source_summary',
  'build_collection_source_summary',
  'inspection_top_action_source_snapshot',
  'read_existing_online_daily_data_only',
  'collection_logic_changed',
  'build_inspection_employee_questions',
  'build_inspection_closure_summary',
  'inspection_closure_top_action',
  '今天 OTA 数据有没有采到',
  '员工六问',
  'query_latest_available_source_rows',
  'source_date_relation',
  'latest_available',
  'missing_platforms',
  'target_date_rows',
  'latest_available_reference_only',
  'coverage_status',
  'blocking_missing_codes',
  'ai_action_items_missing',
  'operation_execution_ai_action_link_missing',
  'operation_execution_evidence_incomplete',
  'inspection_operation_signal_counts',
  'inspection_operation_item_has_ota_diagnosis_link',
  'inspection_operation_intent_has_ota_diagnosis_link',
  'inspection_operation_linked_payload_signal_count',
  'inspection_operation_evidence_status',
  'operationQuestionStatus',
  'approval.status=approved',
  'metric_domain_readiness',
  'calculation_status',
  'revenue_status',
  'ai_status',
  'downstream_readiness',
  'ai_input_not_ready',
  'traffic_source_readiness',
  'required_next_inputs',
  'recommended_collection_mode',
  'action_entry',
  'p0_traffic_gate_status',
  'p0_next_action_mode',
  'p0_next_action_entry',
  'p0_next_step_count',
  'next_command_policy',
  'p0_external_evidence_status',
  'p0_pre_import_evidence_status',
  'p0_pre_import_evidence_policy',
  'p0_traffic_field_fact_status',
  'no_target_date_traffic_rows',
  'p0_required_metric_keys',
  'p0_required_storage_fields',
  'p0_required_field_fact_keys',
  'p0_missing_metric_keys',
  'p0_field_loop_matrix',
  'p0_target_traffic_data_types',
  'p0_source_chain_reference_only',
  'p0_source_chain_scope',
  'p0_source_chain_policy',
  'no_target_date_source_rows',
  'reference_only_non_traffic_source_rows',
  'metadata_only_no_sensitive_commands',
  'inspection_traffic_source_recommended_mode',
  'inspection_traffic_source_action_entry_for_mode',
  'manual_login_state_verified',
  '/api/online-data/capture-meituan-browser',
  'revenue_ready_platforms',
  'traffic_ready_platforms',
  'partial',
  'future_dated_for_target',
  'stale_before_target',
  'next_action_for_missing_requirement',
  'finalize_inspection_next_actions',
  'normalize_inspection_next_action',
  'with_inspection_next_action_entry',
  'inspection_next_action_entry',
  'with_inspection_next_action_success_criteria',
  'inspection_next_action_success_criteria',
  'with_inspection_next_action_resolution',
  'inspection_next_action_resolves_missing_codes',
  'inspection_next_action_blocked_by_action_codes',
  'with_inspection_next_action_employee_explanation',
  'with_inspection_next_action_employee_copy',
  'inspection_next_action_employee_explanation',
  'inspection_next_action_live_closure_gap_codes',
  'live_closure_gap_codes',
  '巡检缺口',
  'inspection_next_action_question_key',
  'inspection_next_action_related_question_keys',
  'inspection_next_action_platform',
  'with_inspection_employee_question_action_codes',
  'sort_inspection_next_actions',
  'inspection_ai_blocking_missing_codes',
  "'missing' => 0",
  "'blocked' => 1",
  'priority',
  "$action['entry']",
  "$action['success_criteria']",
  "$action['employee_success_criteria']",
  "$action['employee_evidence_needed']",
  "$action['employee_verification_steps']",
  "$action['blocked_by_action_codes']",
  "$action['resolves_missing_codes']",
  "$action['question_key']",
  "$action['related_question_keys']",
  'blocked_by',
  'protected_boundary',
  'JSON_UNESCAPED_UNICODE',
]);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'inspector strict mode fails incomplete evidence only in verify mode', [
  "'strict' => false",
  '$options[\'strict\'] = true',
  "'mode' => $options['strict'] ? 'verify' : 'inspect'",
  '$strictIncomplete',
  "'format' => 'json'",
  'render_phase1_live_closure_markdown',
  '第一阶段 OTA 真实闭环巡检',
  '| 优先级 | 状态 | 动作类型 | 入口 | 动作编码 | 负责人 | 动作 | 员工解释 | 受限结论 | 仍可使用 | 补证据动作 | 巡检缺口 | 阻断 | 先处理动作 | 解除缺口 | 所需证据 | 完成判定 | 边界 |',
]);

includesAll('scripts/verify_phase1_live_action_queue_runtime.mjs', 'runtime action queue verifier validates employee-facing output from live inspector', [
  'inspect_phase1_ota_live_closure.php',
  '--format=json',
  'employee six-question rows exist',
  'inspection stays in OTA channel scope',
  'next_actions array exists',
  'evidence package next_actions array exists',
  'collection_source_summary array exists',
  'collection_source_summary exposes platform rows',
  'source summary uses read-only source policy',
  'source summary does not change acquisition logic',
  'cross-output collection source summary matches',
  'action entry is explicit',
  'required next inputs are explicit array',
  'waiting_config exposes Profile authorization inputs',
  'evidence package collection coverage is explicit',
  'evidence package does not mark partial collection as proved',
  'evidence package revenue metric status is platform-aware',
  'latest_available does not prove target date',
  'non-target latest_available is reference only',
  'target-date collection action exists',
  'validateSourceRowsEntryOptionReadiness',
  'has platform scope',
  'platform matches action code',
  'manual entry requires user context',
  'profile entry does not claim login is verified',
  'profile entry exposes local profile directory count',
  'status check entry is read-only and runnable',
  'missing-before-blocked',
  'actionFamilyRank',
  'family-ranked',
  'has execution entry',
  'has success criteria',
  'has resolves_missing_codes',
  'has live_closure_gap_codes',
  'names live closure gap codes',
  'has blocked_by_action_codes',
  'does not self-block',
  'has employee explanation',
  'employee detail exists',
  'employee action exists beside raw action',
  'employee evidence needed exists beside raw evidence_needed',
  'employee success criteria exists beside raw success_criteria',
  'employee explanation next action exists beside raw explanation_next_action',
  'has limited conclusions',
  'has still usable metrics',
  'has explanation next action',
  'has question_key',
  'has related_question_keys',
  'cross-output employee question action codes match',
  'cross-output employee question details match',
  'cross-output employee question next actions match',
  'cross-output next action platform order matches',
  'cross-output next action entry order matches',
  'cross-output next action success criteria order matches',
  'cross-output next action resolved missing codes match',
  'cross-output next action live closure gap codes match',
  'cross-output next action employee explanations match',
  'cross-output next action employee actions match',
  'cross-output next action employee evidence needed match',
  'cross-output next action employee success criteria match',
  'cross-output next action employee explanation next actions match',
  'cross-output next action limited conclusions match',
  'cross-output next action still usable metrics match',
  'cross-output next action explanation next actions match',
  'cross-output next action blocker resolver actions match',
  'phase1_ota_gap_explanation_matrix.md',
  'is explained in gap matrix',
  'keeps technical message',
  'has employee explanation',
  'has limited conclusions',
  'has still usable metrics',
  'has explanation next action',
  'markdown report exposes missing employee explanations',
  'markdown report exposes limited conclusions',
  'markdown report exposes still usable metrics',
  'markdown report exposes explanation next action',
  'operation action row is warning when blockers define the next step',
  'blocked action names blockers',
  'blocked action names resolver actions',
  'raw_data key must stay out',
  'closure summary keeps protected acquisition boundary',
  'closure summary top action is first visible next action',
  'evidence package closure summary top action is first visible next action',
]);

includesAll('scripts/build_phase1_ota_live_closure_evidence.php', 'evidence builder summarizes employee questions and closure status', [
  "$revenueStatus = ($revenueAiReadiness['revenue_ready'] ?? false) === true",
  "$aiStatus = ($revenueAiReadiness['ai_ready'] ?? false) === true",
  "'status' => $revenueStatus",
  'employee_questions',
  'next_actions',
  'live_closure_gap_codes',
  'employee_explanation',
  'employee_detail',
  'employee_next_action',
  'missing_field_summary',
  'metric_domain_summary',
  'capture_evidence_count',
  'desensitized_capture_evidence_count',
  "$evidence['source_url_hash'] ?? $evidence['_source_url_hash'] ?? $evidence['url_hash'] ?? $evidence['_url_hash'] ?? ''",
  'employee_action',
  'employee_evidence_needed',
  'employee_success_criteria',
  'employee_explanation_next_action',
  'employee_verification_steps',
  'evidence_employee_readable_copy',
  'evidence_employee_question_detail',
  'evidence_employee_platform_list_text',
  'limited_conclusions',
  'still_usable_metrics',
  'explanation_next_action',
  'closure_summary',
  'top_action_code',
  'top_action_entry',
  'top_action_entry_options',
  'use_when',
  'requires',
  'boundary',
  'top_action_related_question_keys',
  'top_action_resolves_missing_codes',
  'top_action_live_closure_gap_codes',
  'top_action_platform',
  'top_action_source_snapshot',
  'top_action_success_criteria',
  'top_action_employee_text',
  'top_question_key',
  'missing_question_keys',
  '_entry_options',
  'entry_options',
  'readiness',
  'entry_option_readiness',
  'profile_directory_count',
  'requires_user_context',
  'profile_missing',
  'profile_found_login_unverified',
  'read_existing_collection_reliability_only',
  'read_local_profile_directory_names_only',
  'blocking_gap_codes',
  'reference_policy',
  'proof_requirement',
  'latest_available 只能作参考',
  'collection_source_summary',
  'build_collection_source_summary',
  'top_action_source_snapshot',
  'read_existing_online_daily_data_only',
  'collection_logic_changed',
  'build_employee_questions',
  'build_next_actions',
  'next_action_entry',
  'next_action_success_criteria',
  'next_action_resolves_missing_codes',
  'next_action_blocked_by_action_codes',
  'next_action_employee_explanation',
  'next_action_live_closure_gap_codes',
  'live_closure_gap_codes',
  'next_action_question_key',
  'next_action_related_question_keys',
  'next_action_platform',
  'with_employee_question_action_codes',
  'closure_top_action',
  "'entry' => next_action_entry($code)",
  "'success_criteria' => next_action_success_criteria($code)",
  "'resolves_missing_codes' => next_action_resolves_missing_codes($code)",
  "'live_closure_gap_codes' => next_action_live_closure_gap_codes($code)",
  "'blocked_by_action_codes' => next_action_blocked_by_action_codes($blockedBy, $code)",
  "'question_key' => next_action_question_key($code)",
  "'related_question_keys' => next_action_related_question_keys($code)",
  'build_closure_summary',
  'coverage_status',
  'source_rows_partial',
  'missing_platforms',
  'metric_domain_readiness',
  'traffic_source_readiness',
  'required_next_inputs',
  'recommended_collection_mode',
  'action_entry',
  'p0_traffic_gate_status',
  'p0_next_action_mode',
  'p0_next_action_entry',
  'p0_next_step_count',
  'next_command_policy',
  'p0_external_evidence_status',
  'p0_pre_import_evidence_status',
  'p0_pre_import_evidence_policy',
  'p0_traffic_field_fact_status',
  'no_target_date_traffic_rows',
  'p0_required_metric_keys',
  'p0_required_storage_fields',
  'p0_required_field_fact_keys',
  'p0_missing_metric_keys',
  'p0_field_loop_matrix',
  'p0_target_traffic_data_types',
  'p0_source_chain_reference_only',
  'p0_source_chain_scope',
  'p0_source_chain_policy',
  'no_target_date_source_rows',
  'reference_only_non_traffic_source_rows',
  'metadata_only_no_sensitive_commands',
  'traffic_source_recommended_mode',
  'traffic_source_action_entry_for_mode',
  'manual_login_state_verified',
  '/api/online-data/capture-meituan-browser',
  'revenue_ready_platforms',
  'operationQuestionStatus',
  'latest_available',
  '今天 OTA 数据有没有采到',
  '哪些字段可信',
  '哪些字段缺失',
  '收入/流量/转化出了什么问题',
  'AI 建议依据是什么',
  '下一步该执行什么动作',
  '不改变携程/美团手动或自动获取逻辑',
]);

includesAll('app/service/OtaDataCredibilityGateService.php', 'revenue and AI readiness require exact OTA scope and readback proof', [
  'public function evaluateRevenueAiReadiness(array $metrics, array $options = []): array',
  '$scope = $this->revenueAiReadinessScope($options);',
  'readiness_scope_system_hotel_id_missing',
  'readiness_scope_target_date_missing',
  'readiness_scope_platform_missing',
  'readiness_scope_metric_scope_missing',
  "$metricScopeValid = $metricScope === 'ota_channel';",
  "$gateMetricScope !== 'ota_channel'",
  'critical_metrics_evidence_missing',
  'failed_critical_metrics_evidence_missing',
  'foreach (self::DEFAULT_CRITICAL_METRICS as $metricKey)',
  '$metricValue = $this->nestedMetricValue($metrics, $metricKey);',
  'if (!$this->isFiniteMetricNumber($metricValue)) {',
  "'critical_metric_value_missing_or_invalid:' . $metricKey",
  "'critical_metric_value_keys' => $criticalMetricValueKeys",
  '$this->criticalMetricSourceMatchesHotel($source, $scope[\'system_hotel_id\'])',
  '$this->criticalMetricSourceMatchesDate($source, $scope[\'target_date\'])',
  '$this->criticalMetricSourceMatchesPlatform($source, $scope[\'platform\'])',
  '$this->criticalMetricSourceHasReadbackProof($source)',
  "foreach (['row_count', 'stored_count', 'readback_verified_count'] as $key)",
  "$counts['stored_count'] === $rowCount",
  "$counts['readback_verified_count'] === $rowCount",
  'private function nestedMetricValue(array $metrics, string $metricKey): mixed',
  'if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {',
  'private function isFiniteMetricNumber(mixed $value): bool',
  'return (is_int($value) || is_float($value)) && is_finite((float)$value);',
]);

includesAll('app/service/OtaDiagnosisPersistenceProofService.php', 'diagnosis persistence proves normalized external content against database snapshot', [
  'final class OtaDiagnosisPersistenceProofService',
  "Db::name('agent_logs')",
  "->where('id', $recordId)",
  "->where('hotel_id', $systemHotelId)",
  "$externalContent = $this->diagnosisContent($diagnosis, 'external');",
  "$snapshotContent = $this->diagnosisContent($snapshot, 'snapshot');",
  "'summary' => [",
  "'evidence_sources' => [",
  "'data_gaps' => [",
  "'action_items' => [",
  "'decision_status' => [",
  "$reasonCodes[] = 'diagnosis_' . $side . '_' . $field . '_missing_or_invalid';",
  "$checks[$side . '_content_fields_present'] = count($values) === count($paths);",
  '$this->canonicalize($externalContent[\'values\'][$field])',
  '=== $this->canonicalize($snapshotContent[\'values\'][$field]);',
  "$reasonCodes[] = 'diagnosis_' . $field . '_mismatch';",
  'if (array_is_list($value)) {',
  'ksort($value, SORT_STRING);',
  "$contentChecks['diagnosis_content_matched'] = $this->allContentChecksMatched($contentChecks);",
  "if (($checks[$key] ?? false) !== true) {",
  'if ($reasonCodes !== []) {',
  'return $this->unverified(',
  'external_content_fields_present',
  'snapshot_content_fields_present',
  'summary_matched',
  'evidence_sources_matched',
  'data_gaps_matched',
  'action_items_matched',
  'decision_status_matched',
  'diagnosis_snapshot_saved_record_missing',
  'diagnosis_snapshot_readback_unverified',
]);

for (const file of [
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'scripts/inspect_phase1_ota_live_closure.php',
]) {
  includesAll(file, 'revenue and AI gate consumer passes explicit OTA scope', [
    'evaluateRevenueAiReadiness($metrics, [',
    "'system_hotel_id' => (int)($options['system_hotel_id'] ?? 0)",
    "'target_date' => (string)($options['date'] ?? '')",
    "'platform' => $platform",
    "'metric_scope' => 'ota_channel'",
  ]);
  includesAll(file, 'diagnosis persistence uses exact database proof service', [
    'use app\\service\\OtaDiagnosisPersistenceProofService;',
    '(new OtaDiagnosisPersistenceProofService())->verify(',
    "'system_hotel_id' => (int)($options['system_hotel_id'] ?? 0)",
    "'target_date' => (string)($options['date'] ?? '')",
    "'platform' => (string)($options['platform'] ?? '')",
    "'metric_scope' => 'ota_channel'",
  ]);
}

const otaDiagnosisLinkRequired = [
  "$sourceRecordId = (int)",
  "$diagnosisLogId = (int)($evidence['diagnosis_log_id'] ?? 0);",
  '$typedEvidenceLink = $sourceRecordId > 0',
  '&& $diagnosisLogId === $sourceRecordId',
  "&& trim((string)($evidence['action_item_id'] ?? '')) !== ''",
  "&& strtolower(trim((string)($evidence['metric_scope'] ?? ''))) === 'ota_channel';",
  "$typedSource = str_starts_with($sourceModule, 'ota_diagnosis')",
  "|| str_contains($source, 'ota_diagnosis')",
  'return $typedSource && $typedEvidenceLink;',
];
const otaDiagnosisLinkForbidden = [
  "evidence['evidence_refs']",
  "evidence['data_gaps']",
  "evidence['action_item_status']",
  "evidence['diagnosis_summary']",
];
for (const contract of [
  {
    file: 'scripts/build_phase1_ota_live_closure_evidence.php',
    item: 'function operation_item_has_ota_diagnosis_link(array $item): bool',
    intent: 'function operation_intent_has_ota_diagnosis_link(array $intent): bool',
    afterIntent: 'function operation_completion_signal_count(array $operation): int',
  },
  {
    file: 'scripts/inspect_phase1_ota_live_closure.php',
    item: 'function inspection_operation_item_has_ota_diagnosis_link(array $item): bool',
    intent: 'function inspection_operation_intent_has_ota_diagnosis_link(array $intent): bool',
    afterIntent: 'function inspection_operation_payload_signal_count(array $operation): int',
  },
]) {
  rangeContract(
    contract.file,
    'operation item OTA diagnosis link requires typed identity or explicit OTA diagnosis source',
    contract.item,
    contract.intent,
    otaDiagnosisLinkRequired,
    otaDiagnosisLinkForbidden,
  );
  rangeContract(
    contract.file,
    'operation intent OTA diagnosis link requires typed identity or explicit OTA diagnosis source',
    contract.intent,
    contract.afterIntent,
    otaDiagnosisLinkRequired,
    otaDiagnosisLinkForbidden,
  );
}

includesAll('scripts/build_phase1_ota_live_closure_evidence.php', 'builder completion requires linked evidence plus a landing signal', [
  "'approved_count' => $approvedItems",
  '$verifiedCompletionItems = count_operation_items($linkedItems, static function (array $item): bool {',
  "if ((int)($item['evidence']['count'] ?? 0) <= 0) {",
  "return (string)($item['execution']['status'] ?? '') === 'executed'",
  "|| (string)($item['stage'] ?? '') === 'reviewed'",
  "|| in_array((string)($item['review']['status'] ?? ''), ['success', 'near_success', 'failed'], true)",
  "|| (string)($item['roi']['status'] ?? '') === 'ready';",
  "'completion_signal_count' => $verifiedCompletionItems",
  "return (int)$counts['completion_signal_count'];",
  '仅审批或仅声明已执行都不算完成',
]);
matchingLinesExclude(
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'builder approved count is not a completion signal',
  "'completion_signal_count' =>",
  '$approved',
);
rangeExcludes(
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'builder verified completion predicate cannot use approval',
  '$verifiedCompletionItems = count_operation_items($linkedItems, static function (array $item): bool {',
  '});',
  ['approval', '$approved'],
);
appearsBefore(
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'builder requires AI action link before proved operation status',
  'if (operation_linked_payload_signal_count($operation) <= 0) {',
  'if (operation_completion_signal_count($operation) > 0) {',
);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'inspector completion requires linked evidence plus a landing signal', [
  "'approved_count' => $approvedCount",
  '$verifiedCompletionCount = $countItems(static function (array $item): bool {',
  "if ((int)($item['evidence']['count'] ?? 0) <= 0) {",
  "return (string)($item['execution']['status'] ?? '') === 'executed'",
  "|| (string)($item['stage'] ?? '') === 'reviewed'",
  "|| in_array((string)($item['review']['status'] ?? ''), ['success', 'near_success', 'failed'], true)",
  "|| (string)($item['roi']['status'] ?? '') === 'ready';",
  "'completion_signal_count' => $verifiedCompletionCount",
  "$completionSignalCount = (int)$counts['completion_signal_count'];",
  'approval or executed status alone is insufficient',
]);
matchingLinesExclude(
  'scripts/inspect_phase1_ota_live_closure.php',
  'inspector approved count is not a completion signal',
  "'completion_signal_count' =>",
  '$approved',
);
rangeExcludes(
  'scripts/inspect_phase1_ota_live_closure.php',
  'inspector verified completion predicate cannot use approval',
  '$verifiedCompletionCount = $countItems(static function (array $item): bool {',
  '});',
  ['approval', '$approved'],
);
appearsBefore(
  'scripts/inspect_phase1_ota_live_closure.php',
  'inspector requires AI action link before proved operation status',
  'if (inspection_operation_linked_payload_signal_count($operation) <= 0) {',
  "$completionSignalCount = (int)$counts['completion_signal_count'];",
);

includesAll('scripts/build_phase1_ota_live_closure_evidence.php', 'builder rejects conflicting repeated section identity values', [
  '$collectCandidates = static function (array $paths, callable $normalize) use ($data): array {',
  '$dateCandidates = $collectCandidates([',
  '$systemHotelCandidates = [];',
  '$systemHotelInvalid = false;',
  '$normalized = strict_positive_integer($value);',
  '$systemHotelInvalid = true;',
  '$platformCandidates = $collectCandidates([',
  "count($dateCandidates) > 1 ? 'conflict'",
  "count($systemHotelCandidates) > 1 ? 'conflict'",
  "count($platformCandidates) > 1 ? 'conflict'",
  "'scope_date_candidates' => $dateCandidates",
  "'scope_system_hotel_candidates' => $systemHotelCandidates",
  "'scope_system_hotel_invalid' => $systemHotelInvalid",
  "'scope_platform_candidates' => $platformCandidates",
  "$scopeDateStatus === 'matched'",
  "$scopeSystemHotelStatus === 'matched'",
  "$scopePlatformStatus === 'matched'",
]);

for (const file of [
  'scripts/build_phase1_ota_live_closure_evidence.php',
  'scripts/inspect_phase1_ota_live_closure.php',
]) {
  includesAll(file, 'system hotel CLI scope uses strict positive integer parsing', [
    'function strict_positive_integer(mixed $value): ?int',
    'if (is_int($value)) {',
    "preg_match('/^[1-9][0-9]*$/D', $value) !== 1",
    '$normalized = (int)$value;',
    'return $normalized > 0 && (string)$normalized === $value ? $normalized : null;',
    "strict_positive_integer($options['system_hotel_id']) === null",
    'Invalid --system_hotel_id, expected a positive integer.',
  ]);
}

includesAll('scripts/build_phase1_ota_live_closure_evidence.php', 'builder preserves invalid hotel scope for downstream fail-closed inspection', [
  "$expectedSystemHotelId = strict_positive_integer($options['system_hotel_id'] ?? null) ?? 0;",
  '$scopeSystemHotelStatus = $systemHotelInvalid',
  "? ($systemHotelCandidates === [] ? 'mismatch' : 'conflict')",
  "'scope_system_hotel_invalid' => $systemHotelInvalid",
]);

includesAll('scripts/inspect_phase1_ota_live_closure.php', 'external evidence sections validate their own hotel date and platform', [
  'function external_section_scope_identity(array $section, array $options): array',
  'never a fallback because that would allow evidence from another hotel or',
  '$dateCandidates = [];',
  '$systemHotelCandidates = [];',
  '$platformCandidates = [];',
  "$dateMatched = $expectedDate !== '' && count($dateCandidates) === 1 && $sectionDate === $expectedDate;",
  '$systemHotelInvalid = ($section[\'scope_system_hotel_invalid\'] ?? false) === true;',
  '$normalized = strict_positive_integer($value);',
  "$expectedSystemHotelId = strict_positive_integer($options['system_hotel_id'] ?? null) ?? 0;",
  '$systemHotelMatched = !$systemHotelInvalid',
  '&& $expectedSystemHotelId > 0',
  '&& count($systemHotelCandidates) === 1',
  '&& $sectionSystemHotelId === $expectedSystemHotelId;',
  "$platformMatched = $expectedPlatform !== '' && count($platformCandidates) === 1 && $sectionPlatform === $expectedPlatform;",
  "count($dateCandidates) > 1 ? 'conflict'",
  "count($systemHotelCandidates) > 1 ? 'conflict'",
  "count($platformCandidates) > 1 ? 'conflict'",
  "'date_candidates' => $dateCandidates",
  "'system_hotel_candidates' => $systemHotelCandidates",
  "'system_hotel_invalid' => $systemHotelInvalid",
  "'platform_candidates' => $platformCandidates",
  "'matched' => $dateMatched && $systemHotelMatched && $platformMatched",
  '$diagnosisScopeIdentity = external_section_scope_identity($diagnosis, $options);',
  '$operationScopeIdentity = external_section_scope_identity($operation, $options);',
  'external_section_scope_missing_codes($diagnosisScopeIdentity)',
  'external_section_scope_missing_codes($operationScopeIdentity)',
  'evidence_scope_date_mismatch',
  'evidence_scope_system_hotel_mismatch',
  'evidence_scope_platform_mismatch',
]);

packageScript('inspect:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php');
packageScript('verify:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php --strict');
packageScript('verify:phase1-live-closure-contract', 'node scripts/verify_phase1_ota_live_closure_contract.mjs');
packageScript('verify:phase1-live-action-queue', 'node scripts/verify_phase1_live_action_queue_runtime.mjs');
packageScript('build:phase1-live-evidence', 'C:\\xampp\\php\\php.exe scripts\\build_phase1_ota_live_closure_evidence.php');

includesAll('docs/phase1_ota_trusted_loop_audit.md', 'audit references live closure evidence gate', [
  'verify:phase1-live-closure-contract',
  'inspect:phase1-live-closure',
  '真实当天携程/美团采集结果尚未完成端到端证明',
]);

includesAll('docs/release_functional_acceptance_matrix.md', 'release matrix includes live closure contract guard', [
  'verify:phase1-live-closure-contract',
  'live closure evidence',
]);

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA live closure contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) console.error(`  missing/expected: ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-live-closure-contract] ${checks.length} checks passed`);
