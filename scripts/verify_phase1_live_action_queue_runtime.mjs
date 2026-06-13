import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function todayShanghai() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Shanghai',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const byType = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${byType.year}-${byType.month}-${byType.day}`;
}

function readArgs(argv) {
  const options = {
    date: todayShanghai(),
    platform: '',
    hotel_id: '',
    system_hotel_id: '',
  };
  for (const arg of argv.slice(2)) {
    if (!arg.startsWith('--') || !arg.includes('=')) continue;
    const [key, value] = arg.slice(2).split(/=(.*)/s, 2);
    if (Object.hasOwn(options, key)) {
      options[key] = value.trim();
    }
  }
  return options;
}

function check(label, ok, detail = '') {
  checks.push({ label, ok: Boolean(ok), detail });
}

function fail(message) {
  check(message, false);
}

function asArray(value) {
  return Array.isArray(value) ? value : [];
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function nonEmptyString(value) {
  return typeof value === 'string' && value.trim() !== '';
}

function hasTechnicalEmployeeCopy(value) {
  const text = String(value ?? '');
  return /\b(metric_trust|evidence_sources|evidence_refs|data_gaps|action_items|source_date_evidence|raw_data|data_type|source_trace_id|sync_task_id|data_source_id|accepted_rows|rejected_rows|validation_flags|online_daily_data|target_date_rows|target_date_data_types|revenue_metrics|book_order_num|room_nights|order_count|list_exposure|detail_exposure|flow_rate|order_filling_num|order_submit_num|execution_intents|execution_flow|latest_available|revenue_status|traffic_status|conversion_status|blocked)\b/.test(text)
    || /\b(?:ETL status|OTA diagnosis)\b/.test(text)
    || /\b(?:approval\.status|execution\.status|evidence\.count|review\.status|source_date_evidence\.platforms)\b/.test(text)
    || /\b(CTRIP|MEITUAN)\b/.test(text)
    || /\b(?:ctrip|meituan|ai|operation)_[a-z0-9_]+\b/i.test(text);
}

function validateEmployeeActionCopy(label, action) {
  const rawAction = String(action?.action ?? action?.next_action ?? '');
  const employeeAction = String(action?.employee_action ?? '');
  if (rawAction.trim() !== '') {
    check(`${label} employee action exists beside raw action`, employeeAction.trim() !== '', JSON.stringify(action));
  }
  check(`${label} employee action avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeAction), employeeAction);

  const rawExplanationNextAction = String(action?.explanation_next_action ?? '');
  const employeeExplanationNextAction = String(action?.employee_explanation_next_action ?? '');
  if (rawExplanationNextAction.trim() !== '') {
    check(`${label} employee explanation next action exists beside raw explanation_next_action`, employeeExplanationNextAction.trim() !== '', JSON.stringify(action));
  }
  check(`${label} employee explanation next action avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeExplanationNextAction), employeeExplanationNextAction);

  const rawSuccessCriteria = String(action?.success_criteria ?? '');
  const employeeSuccessCriteria = String(action?.employee_success_criteria ?? '');
  if (rawSuccessCriteria.trim() !== '') {
    check(`${label} employee success criteria exists beside raw success_criteria`, employeeSuccessCriteria.trim() !== '', JSON.stringify(action));
  }
  check(`${label} employee success criteria avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeSuccessCriteria), employeeSuccessCriteria);

  const rawEvidenceNeeded = asArray(action?.evidence_needed);
  const employeeEvidenceNeeded = asArray(action?.employee_evidence_needed);
  if (rawEvidenceNeeded.length > 0) {
    check(`${label} employee evidence needed exists beside raw evidence_needed`, employeeEvidenceNeeded.length > 0, JSON.stringify(action));
  }
  for (const [index, item] of employeeEvidenceNeeded.entries()) {
    check(`${label} employee evidence needed ${index + 1} avoids technical evidence names`, !hasTechnicalEmployeeCopy(item), String(item ?? ''));
  }

  const employeeVerificationSteps = asArray(action?.employee_verification_steps);
  check(`${label} employee verification steps exist`, employeeVerificationSteps.length > 0, JSON.stringify(action));
  for (const [index, item] of employeeVerificationSteps.entries()) {
    check(`${label} employee verification step ${index + 1} avoids technical evidence names`, !hasTechnicalEmployeeCopy(item), String(item ?? ''));
  }
}

function missingFieldSummaryDigest(question) {
  return asArray(question?.evidence?.missing_field_summary).map((row) => ({
    code: String(row?.code ?? ''),
    label: String(row?.label ?? ''),
    source_text: String(row?.source_text ?? ''),
    business_impact: String(row?.business_impact ?? ''),
    next_action: String(row?.next_action ?? ''),
    policy: String(row?.policy ?? ''),
  }));
}

function validateMissingFieldSummary(label, question) {
  const evidence = question?.evidence ?? {};
  const rawCodes = [
    ...asArray(evidence?.data_gap_codes),
    ...asArray(evidence?.missing_field_codes),
  ].map((value) => String(value ?? '').trim()).filter(Boolean);
  const summary = asArray(evidence?.missing_field_summary);
  if (rawCodes.length > 0) {
    check(`${label} exposes missing field readable summary`, summary.length > 0, JSON.stringify(evidence));
  }
  for (const [index, row] of summary.entries()) {
    const prefix = `${label} missing field summary ${index + 1}`;
    check(`${prefix} keeps raw code for trace`, nonEmptyString(row?.code), JSON.stringify(row));
    for (const field of ['label', 'source_text', 'business_impact', 'next_action', 'policy']) {
      const value = String(row?.[field] ?? '');
      check(`${prefix} ${field} exists`, value.trim() !== '', JSON.stringify(row));
      check(`${prefix} ${field} avoids technical names`, !hasTechnicalEmployeeCopy(value), value);
    }
  }
}

function metricDomainSummaryDigest(question) {
  return asArray(question?.evidence?.metric_domain_summary).map((row) => ({
    platform: String(row?.platform ?? ''),
    platform_label: String(row?.platform_label ?? ''),
    revenue_text: String(row?.revenue_text ?? ''),
    traffic_text: String(row?.traffic_text ?? ''),
    conversion_text: String(row?.conversion_text ?? ''),
    missing_text: String(row?.missing_text ?? ''),
    source_text: String(row?.source_text ?? ''),
    problem: String(row?.problem ?? ''),
    next_action: String(row?.next_action ?? ''),
    policy: String(row?.policy ?? ''),
  }));
}

function validateMetricDomainSummary(label, question) {
  const evidence = question?.evidence ?? {};
  const readiness = asArray(evidence?.metric_domain_readiness);
  const summary = asArray(evidence?.metric_domain_summary);
  if (readiness.length > 0) {
    check(`${label} exposes metric domain readable summary`, summary.length === readiness.length, JSON.stringify(evidence));
  }
  for (const [index, row] of summary.entries()) {
    const prefix = `${label} metric domain summary ${index + 1}`;
    check(`${prefix} keeps platform for trace`, nonEmptyString(row?.platform), JSON.stringify(row));
    for (const field of ['platform_label', 'revenue_text', 'traffic_text', 'conversion_text', 'source_text', 'problem', 'next_action', 'policy']) {
      const value = String(row?.[field] ?? '');
      check(`${prefix} ${field} exists`, value.trim() !== '', JSON.stringify(row));
      check(`${prefix} ${field} avoids technical names`, !hasTechnicalEmployeeCopy(value), value);
    }
  }
}

function collectKeys(value, keys = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectKeys(item, keys);
    return keys;
  }
  if (!isObject(value)) return keys;
  for (const [key, child] of Object.entries(value)) {
    keys.push(key);
    collectKeys(child, keys);
  }
  return keys;
}

function actionFamilyRank(action) {
  const familyRank = {
    evidence_scope: 0,
    target_date_source_rows: 1,
    standard_facts: 2,
    revenue_metric_inputs: 3,
    traffic_conversion_facts: 4,
    ai_diagnosis_evidence: 5,
    operation_execution_evidence: 6,
  };
  return familyRank[String(action?.action_family ?? '')] ?? 99;
}

function actionRank(action) {
  const statusRank = { missing: 0, blocked: 1 };
  const priorityRank = { high: 0, medium: 1, low: 2 };
  return [
    statusRank[action.status] ?? 99,
    actionFamilyRank(action),
    priorityRank[action.priority] ?? 99,
    String(action.action_code ?? ''),
  ];
}

function rankIsNonDecreasing(previous, current) {
  for (let index = 0; index < Math.min(previous.length, current.length); index += 1) {
    if (previous[index] !== current[index]) return previous[index] <= current[index];
  }
  return previous.length <= current.length;
}

function sortedStrings(value) {
  return asArray(value).map((item) => String(item)).sort();
}

function sameStringList(left, right) {
  return JSON.stringify(sortedStrings(left)) === JSON.stringify(sortedStrings(right));
}

function collectionSourceSummaryDigest(rows) {
  return asArray(rows)
    .map((row) => {
      const latestAvailable = row?.latest_available ?? {};
      return {
        platform: String(row?.platform ?? '').toLowerCase(),
        storage_table: String(row?.storage_table ?? ''),
        source_policy: String(row?.source_policy ?? ''),
        metric_scope: String(row?.metric_scope ?? ''),
        target_date_rows: Number(row?.target_date_rows ?? 0),
        target_date_data_types: sortedStrings(row?.target_date_data_types),
        target_date_latest_trace_time: String(row?.target_date_latest_trace_time ?? ''),
        latest_available_date: String(latestAvailable?.date ?? ''),
        latest_available_relation: String(latestAvailable?.date_relation ?? ''),
        latest_available_rows: Number(latestAvailable?.rows ?? 0),
        latest_available_data_types: sortedStrings(latestAvailable?.data_types),
        latest_available_reference_only: Boolean(row?.latest_available_reference_only),
        etl_status: String(row?.etl_status ?? ''),
        metric_status: String(row?.metric_status ?? ''),
        traffic_rows: Number(row?.traffic_rows ?? 0),
        collection_logic_changed: Boolean(row?.collection_logic_changed),
      };
    })
    .sort((left, right) => left.platform.localeCompare(right.platform));
}

function validateCollectionSourceSummary(label, rows, expectedPlatforms) {
  check(`${label} collection_source_summary array exists`, Array.isArray(rows));
  const sourceSummary = asArray(rows);
  check(`${label} collection_source_summary exposes platform rows`, sourceSummary.length > 0, JSON.stringify(sourceSummary));
  if (asArray(expectedPlatforms).length > 0) {
    check(
      `${label} collection_source_summary platform list matches scope`,
      sameStringList(sourceSummary.map((row) => row?.platform), expectedPlatforms),
      JSON.stringify(sourceSummary),
    );
  }
  for (const row of sourceSummary) {
    const platform = String(row?.platform ?? 'unknown').toLowerCase();
    const latestAvailable = row?.latest_available ?? null;
    check(`${label} ${platform} source summary reads online_daily_data`, row?.storage_table === 'online_daily_data', JSON.stringify(row));
    check(`${label} ${platform} source summary uses read-only source policy`, row?.source_policy === 'read_existing_online_daily_data_only', JSON.stringify(row));
    check(`${label} ${platform} source summary stays OTA scoped`, row?.metric_scope === 'ota_channel', JSON.stringify(row));
    check(`${label} ${platform} source summary target rows numeric`, Number.isFinite(Number(row?.target_date_rows)), JSON.stringify(row));
    check(`${label} ${platform} source summary target data types array`, Array.isArray(row?.target_date_data_types), JSON.stringify(row));
    check(`${label} ${platform} source summary latest_available object or null`, isObject(latestAvailable) || latestAvailable === null, JSON.stringify(row));
    check(`${label} ${platform} source summary latest reference flag boolean`, typeof row?.latest_available_reference_only === 'boolean', JSON.stringify(row));
    check(`${label} ${platform} source summary etl status visible`, typeof row?.etl_status === 'string' && row.etl_status.length > 0, JSON.stringify(row));
    check(`${label} ${platform} source summary metric status visible`, typeof row?.metric_status === 'string' && row.metric_status.length > 0, JSON.stringify(row));
    check(`${label} ${platform} source summary traffic rows numeric`, Number.isFinite(Number(row?.traffic_rows)), JSON.stringify(row));
    check(`${label} ${platform} source summary does not change acquisition logic`, row?.collection_logic_changed === false, JSON.stringify(row));
    if (isObject(latestAvailable)) {
      check(`${label} ${platform} source summary latest relation visible`, typeof latestAvailable.date_relation === 'string' && latestAvailable.date_relation.length > 0, JSON.stringify(latestAvailable));
      check(
        `${label} ${platform} source summary marks non-target latest as reference only`,
        latestAvailable.date_relation === 'target_date' || row.latest_available_reference_only === true,
        JSON.stringify(row),
      );
    }
  }
}

function previousDate(date) {
  const parsed = new Date(`${date}T00:00:00Z`);
  parsed.setUTCDate(parsed.getUTCDate() - 1);
  return parsed.toISOString().slice(0, 10);
}

function actionCodeList(value) {
  return asArray(value).map((item) => String(item?.action_code ?? '')).filter(Boolean);
}

function actionFamilyList(value) {
  return asArray(value).map((item) => String(item?.action_family ?? '')).filter(Boolean);
}

function actionEntryList(value) {
  return asArray(value).map((item) => String(item?.entry ?? '')).filter(Boolean);
}

function actionSuccessCriteriaList(value) {
  return asArray(value).map((item) => String(item?.success_criteria ?? '')).filter(Boolean);
}

function actionStringFieldList(value, field) {
  return asArray(value).map((item) => String(item?.[field] ?? '')).filter(Boolean);
}

function actionArrayFieldList(value, field) {
  return asArray(value).map((item) => asArray(item?.[field]).map(String).join('|'));
}

function questionActionCodeMap(rows) {
  return Object.fromEntries(asArray(rows).map((row) => [
    String(row?.key ?? row?.question ?? ''),
    asArray(row?.next_action_codes).map(String),
  ]).filter(([key]) => key !== ''));
}

function questionActionSummaryMap(rows) {
  return Object.fromEntries(asArray(rows).map((row) => [
    String(row?.key ?? row?.question ?? ''),
    {
      primary: String(row?.primary_next_action_code ?? row?.evidence?.primary_next_action_code ?? ''),
      direct: String(row?.direct_next_action_code ?? row?.evidence?.direct_next_action_code ?? ''),
      linked: Number(row?.evidence?.linked_action_count ?? 0),
      blocked: sortedStrings(row?.blocked_action_codes ?? row?.evidence?.blocked_action_codes ?? []),
    },
  ]).filter(([key]) => key !== ''));
}

function resolverActionCodesByMissingCode(actions) {
  const byMissingCode = new Map();
  for (const action of asArray(actions)) {
    const actionCode = String(action?.action_code ?? '');
    if (!actionCode) continue;
    for (const missingCode of asArray(action?.resolves_missing_codes).map(String).filter(Boolean)) {
      const current = byMissingCode.get(missingCode) ?? [];
      current.push(actionCode);
      byMissingCode.set(missingCode, current);
    }
  }
  return byMissingCode;
}

function questionBlockingMissingCodes(question) {
  return sortedStrings([
    ...asArray(question?.evidence?.blocking_missing_codes).map(String),
    ...asArray(question?.evidence?.operation_blocking_missing_codes).map(String),
  ]);
}

function trustedMetricTrustKeys(metricTrust) {
  if (!isObject(metricTrust)) return [];
  return Object.entries(metricTrust)
    .filter(([key, value]) => {
      if (String(key).trim() === '') return false;
      if (isObject(value)) return value.saved_success === true;
      return value === true;
    })
    .map(([key]) => String(key));
}

function expectedTrafficActionEntry(action) {
  const actionCode = String(action?.action_code ?? '');
  if (actionCode.includes('meituan')) return '/api/online-data/fetch-meituan-traffic';
  if (actionCode.includes('ctrip')) return '/api/online-data/fetch-ctrip-traffic';
  return '';
}

function expectedSourceRowsActionEntries(action) {
  const actionCode = String(action?.action_code ?? '');
  if (actionCode.includes('meituan')) {
    return {
      manual: '/api/online-data/fetch-meituan',
      profile: '/api/online-data/capture-meituan-browser',
      status: '/api/online-data/collection-reliability',
    };
  }
  if (actionCode.includes('ctrip')) {
    return {
      manual: '/api/online-data/fetch-ctrip-overview',
      profile: '/api/online-data/capture-ctrip-browser',
      status: '/api/online-data/collection-reliability',
    };
  }
  return null;
}

function expectedActionPlatform(action) {
  const actionCode = String(action?.action_code ?? '');
  if (actionCode.startsWith('ctrip_')) return 'ctrip';
  if (actionCode.startsWith('meituan_')) return 'meituan';
  if (['collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items', 'collect_operation_execution_evidence'].includes(actionCode)) {
    return 'ctrip,meituan';
  }
  if (actionCode === 'align_evidence_scope_date') return 'ota';
  return '';
}

function actionEntryOptionEntries(action) {
  return asArray(action?.entry_options)
    .map((option) => (isObject(option) ? String(option.entry ?? '') : String(option ?? '')))
    .filter((entry) => entry !== '');
}

function actionEntryOptionFieldValues(action, field) {
  return asArray(action?.entry_options)
    .map((option) => (isObject(option) ? String(option[field] ?? '').trim() : ''))
    .filter((value) => value !== '');
}

function entryOptionByMode(options, mode) {
  return asArray(options).find((option) => isObject(option) && String(option.mode ?? option.type ?? '') === mode) ?? null;
}

function validateSourceRowsEntryOptionReadiness(prefix, options) {
  const optionObjects = asArray(options).filter(isObject);
  check(`${prefix} source row action exposes readiness for entry options`, optionObjects.length >= 3 && optionObjects.every((option) => isObject(option.readiness)), JSON.stringify(options));

  const manual = entryOptionByMode(optionObjects, 'manual_cookie_api');
  const profile = entryOptionByMode(optionObjects, 'browser_profile');
  const status = entryOptionByMode(optionObjects, 'status_check');

  check(`${prefix} manual entry requires user context`, manual?.readiness?.status === 'requires_user_context' && manual?.readiness?.can_run_now === false, JSON.stringify(manual ?? {}));
  check(`${prefix} profile entry does not claim login is verified`, ['profile_missing', 'profile_found_login_unverified'].includes(String(profile?.readiness?.status ?? '')) && profile?.readiness?.can_run_now === false, JSON.stringify(profile ?? {}));
  check(`${prefix} profile entry exposes local profile directory count`, Number.isFinite(Number(profile?.readiness?.profile_count)) && profile?.readiness?.source_policy === 'read_local_profile_directory_names_only', JSON.stringify(profile ?? {}));
  check(`${prefix} status check entry is read-only and runnable`, status?.readiness?.status === 'ready' && status?.readiness?.can_run_now === true && status?.readiness?.evidence === 'read_existing_collection_reliability_only', JSON.stringify(status ?? {}));
}

function validateQuestionBlockerActionLinks(label, questions, actions) {
  const resolverByMissingCode = resolverActionCodesByMissingCode(actions);
  for (const [index, question] of asArray(questions).entries()) {
    const questionLabel = String(question?.key ?? question?.question ?? `row_${index + 1}`);
    const nextActionCodes = asArray(question?.next_action_codes).map(String);
    for (const missingCode of questionBlockingMissingCodes(question)) {
      for (const resolverActionCode of resolverByMissingCode.get(missingCode) ?? []) {
        check(
          `${label} ${questionLabel} links resolver action for ${missingCode}`,
          nextActionCodes.includes(resolverActionCode),
          JSON.stringify({ missing_code: missingCode, resolver_action_code: resolverActionCode, next_action_codes: nextActionCodes }),
        );
      }
    }
  }
}

function actionResolvesMissingCodesList(value) {
  return asArray(value).map((item) => asArray(item?.resolves_missing_codes).map(String).join('|'));
}

function actionBlockedByActionCodesList(value) {
  return asArray(value).map((item) => asArray(item?.blocked_by_action_codes).map(String).join('|'));
}

function questionStatusMap(rows) {
  return Object.fromEntries(asArray(rows).map((row) => [String(row?.question ?? ''), String(row?.status ?? '')]).filter(([question]) => question !== ''));
}

function questionStringFieldMap(rows, field) {
  return Object.fromEntries(asArray(rows).map((row) => [
    String(row?.key ?? row?.question ?? ''),
    String(row?.[field] ?? ''),
  ]).filter(([key]) => key !== ''));
}

function runInspector(options, extraArgs = [], label = 'inspector') {
  const args = [
    'scripts\\inspect_phase1_ota_live_closure.php',
    '--format=json',
    `--date=${options.date}`,
    ...extraArgs,
  ];
  for (const key of ['platform', 'hotel_id', 'system_hotel_id']) {
    if (options[key] !== '') {
      args.push(`--${key}=${options[key]}`);
    }
  }

  const result = spawnSync('C:\\xampp\\php\\php.exe', args, {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
  });
  check(`${label} exits successfully in non-strict mode`, result.status === 0, result.stderr || result.stdout);
  if (result.status !== 0) {
    return null;
  }
  try {
    return JSON.parse(result.stdout);
  } catch (error) {
    fail(`inspector output is valid JSON: ${error.message}`);
    return null;
  }
}

function runInspectorForEvidencePayload(options, evidencePayload, label) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-inspector-evidence-'));
  const evidencePath = path.join(tempDir, 'evidence.json');
  fs.writeFileSync(evidencePath, JSON.stringify(evidencePayload), 'utf8');
  try {
    return runInspector(options, [`--evidence=${evidencePath}`], label);
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

function runEvidenceBuilder(options, extraArgs = [], label = 'evidence builder') {
  const args = [
    'scripts\\build_phase1_ota_live_closure_evidence.php',
    `--date=${options.date}`,
    ...extraArgs,
  ];
  for (const key of ['platform', 'hotel_id', 'system_hotel_id']) {
    if (options[key] !== '') {
      args.push(`--${key}=${options[key]}`);
    }
  }

  const result = spawnSync('C:\\xampp\\php\\php.exe', args, {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
    maxBuffer: 64 * 1024 * 1024,
  });
  check(`${label} exits successfully in read-only mode`, result.status === 0, result.stderr || result.stdout);
  if (result.status !== 0) {
    return null;
  }
  try {
    return JSON.parse(result.stdout);
  } catch (error) {
    fail(`evidence builder output is valid JSON: ${error.message}`);
    return null;
  }
}

const options = readArgs(process.argv);
const payload = runInspector(options);
const evidencePayload = runEvidenceBuilder(options);

function runBlockedDiagnosisEvidenceBuilder(options) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-diagnosis-'));
  const diagnosisPath = path.join(tempDir, 'blocked-diagnosis.json');
  const diagnosis = {
    source: '/api/agent/ota-diagnosis',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    evidence_sources: [
      {
        ref: 'ota_no_data_scope',
        source_policy: 'database_only_no_synthetic_conclusion',
      },
    ],
    data_gaps: [
      {
        code: 'ota_same_period_source_rows_missing',
        message: 'same-period OTA source rows missing',
      },
    ],
    action_items: [
      {
        id: 'ota_action_blocked_1',
        action: 'collect same-period OTA rows before diagnosis',
        status: 'blocked_by_missing_ota_data',
        evidence_refs: ['ota_no_data_scope'],
      },
    ],
  };
  fs.writeFileSync(diagnosisPath, JSON.stringify(diagnosis), 'utf8');
  try {
    return runEvidenceBuilder(options, [`--diagnosis=${diagnosisPath}`], 'blocked diagnosis evidence builder');
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

function runIncompleteOperationEvidenceBuilder(options) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-operation-'));
  const diagnosisPath = path.join(tempDir, 'actionable-diagnosis.json');
  const operationPath = path.join(tempDir, 'incomplete-operation.json');
  const diagnosis = {
    source: '/api/agent/ota-diagnosis',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    evidence_sources: [
      {
        ref: 'ota_channel_metric_evidence',
        metric_scope: 'ota_channel',
      },
    ],
    data_gaps: [],
    action_items: [
      {
        id: 'ota_action_ready_1',
        action: 'review OTA channel price and traffic gap',
        status: 'ready',
        evidence_refs: ['ota_channel_metric_evidence'],
      },
    ],
  };
  const operation = {
    source: '/api/operation/execution-intents',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    execution_intents: [
      {
        id: 1,
        status: 'blocked',
        blocked_reason: 'approval evidence missing',
      },
    ],
    execution_flow: {
      summary: {
        total: 1,
        stage_counts: {
          blocked: 1,
        },
        approved: 0,
        executed: 0,
        evidence_ready: 0,
        roi_ready: 0,
      },
      list: [
        {
          id: 1,
          stage: 'blocked',
          recommendation: {
            source: 'ota_diagnosis#ota_action_ready_1',
            platform: 'ota',
            object_type: 'pricing',
            action_type: 'review',
            evidence: {
              evidence_refs: ['ota_channel_metric_evidence'],
              action_item_id: 'ota_action_ready_1',
            },
          },
          approval: {
            status: 'blocked',
            blocked_reason: 'approval evidence missing',
          },
          execution: {
            status: 'pending_create',
            blocked_reason: '',
          },
          evidence: {
            count: 0,
            latest: {},
          },
          review: {
            status: 'observing',
            summary: '',
          },
          roi: {
            status: 'data_gap',
          },
          next_action: 'resolve approval blocker before execution',
        },
      ],
    },
  };
  fs.writeFileSync(diagnosisPath, JSON.stringify(diagnosis), 'utf8');
  fs.writeFileSync(operationPath, JSON.stringify(operation), 'utf8');
  try {
    return runEvidenceBuilder(
      options,
      [`--diagnosis=${diagnosisPath}`, `--operation=${operationPath}`],
      'incomplete operation evidence builder',
    );
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

function runMismatchedDiagnosisEvidenceBuilder(options) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-diagnosis-scope-'));
  const diagnosisPath = path.join(tempDir, 'mismatched-diagnosis.json');
  const diagnosis = {
    source: '/api/agent/ota-diagnosis',
    scope: {
      date: previousDate(options.date),
      metric_scope: 'ota_channel',
    },
    evidence_sources: [
      {
        ref: 'stale_ota_channel_metric_evidence',
        metric_scope: 'ota_channel',
      },
    ],
    data_gaps: [],
    action_items: [
      {
        id: 'stale_ota_action_ready_1',
        action: 'review stale OTA channel price evidence',
        status: 'ready',
        evidence_refs: ['stale_ota_channel_metric_evidence'],
      },
    ],
  };
  fs.writeFileSync(diagnosisPath, JSON.stringify(diagnosis), 'utf8');
  try {
    return runEvidenceBuilder(options, [`--diagnosis=${diagnosisPath}`], 'mismatched diagnosis evidence builder');
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

function runMismatchedOperationEvidenceBuilder(options) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-operation-scope-'));
  const diagnosisPath = path.join(tempDir, 'actionable-diagnosis.json');
  const operationPath = path.join(tempDir, 'mismatched-operation.json');
  const diagnosis = {
    source: '/api/agent/ota-diagnosis',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    evidence_sources: [
      {
        ref: 'ota_channel_metric_evidence',
        metric_scope: 'ota_channel',
      },
    ],
    data_gaps: [],
    action_items: [
      {
        id: 'ota_action_ready_1',
        action: 'review OTA channel price and traffic gap',
        status: 'ready',
        evidence_refs: ['ota_channel_metric_evidence'],
      },
    ],
  };
  const operation = {
    source: '/api/operation/execution-intents',
    scope: {
      date: previousDate(options.date),
      metric_scope: 'ota_channel',
    },
    execution_intents: [
      {
        id: 1,
        status: 'approved',
      },
    ],
    execution_flow: {
      summary: {
        total: 1,
        stage_counts: {
          approved: 1,
        },
        approved: 1,
        executed: 0,
        evidence_ready: 0,
        roi_ready: 0,
      },
      list: [
        {
          id: 1,
          stage: 'approved',
          recommendation: {
            source: 'ota_diagnosis#ota_action_ready_1',
            platform: 'ota',
            object_type: 'pricing',
            action_type: 'review',
          },
          approval: {
            status: 'approved',
          },
          execution: {
            status: 'pending',
          },
          evidence: {
            count: 0,
          },
        },
      ],
    },
  };
  fs.writeFileSync(diagnosisPath, JSON.stringify(diagnosis), 'utf8');
  fs.writeFileSync(operationPath, JSON.stringify(operation), 'utf8');
  try {
    return runEvidenceBuilder(
      options,
      [`--diagnosis=${diagnosisPath}`, `--operation=${operationPath}`],
      'mismatched operation evidence builder',
    );
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

function runUnlinkedOperationEvidenceBuilder(options) {
  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'suxi-phase1-operation-unlinked-'));
  const diagnosisPath = path.join(tempDir, 'actionable-diagnosis.json');
  const operationPath = path.join(tempDir, 'unlinked-operation.json');
  const diagnosis = {
    source: '/api/agent/ota-diagnosis',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    evidence_sources: [
      {
        ref: 'ota_channel_metric_evidence',
        metric_scope: 'ota_channel',
      },
    ],
    data_gaps: [],
    action_items: [
      {
        id: 'ota_action_ready_1',
        action: 'review OTA channel price and traffic gap',
        status: 'ready',
        evidence_refs: ['ota_channel_metric_evidence'],
      },
    ],
  };
  const operation = {
    source: '/api/operation/execution-intents',
    scope: {
      date: options.date,
      metric_scope: 'ota_channel',
    },
    execution_intents: [
      {
        id: 1,
        source_module: 'manual',
        status: 'approved',
      },
    ],
    execution_flow: {
      summary: {
        total: 1,
        stage_counts: {
          reviewed: 1,
        },
        approved: 1,
        executed: 1,
        evidence_ready: 1,
        roi_ready: 1,
      },
      list: [
        {
          id: 1,
          stage: 'reviewed',
          recommendation: {
            source: 'manual#1',
            source_module: 'manual',
            platform: 'ota',
            object_type: 'pricing',
            action_type: 'review',
          },
          approval: {
            status: 'approved',
          },
          execution: {
            status: 'executed',
          },
          evidence: {
            count: 1,
          },
          review: {
            status: 'success',
          },
          roi: {
            status: 'ready',
          },
        },
      ],
    },
  };
  fs.writeFileSync(diagnosisPath, JSON.stringify(diagnosis), 'utf8');
  fs.writeFileSync(operationPath, JSON.stringify(operation), 'utf8');
  try {
    return runEvidenceBuilder(
      options,
      [`--diagnosis=${diagnosisPath}`, `--operation=${operationPath}`],
      'unlinked operation evidence builder',
    );
  } finally {
    try {
      fs.rmSync(tempDir, { recursive: true, force: true });
    } catch {
      // Temporary verifier files are best-effort cleanup only.
    }
  }
}

const blockedDiagnosisEvidencePayload = runBlockedDiagnosisEvidenceBuilder(options);
const incompleteOperationEvidencePayload = runIncompleteOperationEvidenceBuilder(options);
const mismatchedDiagnosisEvidencePayload = runMismatchedDiagnosisEvidenceBuilder(options);
const mismatchedOperationEvidencePayload = runMismatchedOperationEvidenceBuilder(options);
const unlinkedOperationEvidencePayload = runUnlinkedOperationEvidenceBuilder(options);
const mismatchedDiagnosisInspectionPayload = mismatchedDiagnosisEvidencePayload
  ? runInspectorForEvidencePayload(options, mismatchedDiagnosisEvidencePayload, 'mismatched diagnosis inspector')
  : null;
const mismatchedOperationInspectionPayload = mismatchedOperationEvidencePayload
  ? runInspectorForEvidencePayload(options, mismatchedOperationEvidencePayload, 'mismatched operation inspector')
  : null;
const unlinkedOperationInspectionPayload = unlinkedOperationEvidencePayload
  ? runInspectorForEvidencePayload(options, unlinkedOperationEvidencePayload, 'unlinked operation inspector')
  : null;

if (payload) {
  check('inspection status is explicit', ['passed', 'incomplete'].includes(payload.status), String(payload.status ?? ''));
  check('inspection stays in OTA channel scope', payload.scope?.metric_scope === 'ota_channel', JSON.stringify(payload.scope ?? {}));
  check('inspection reads online_daily_data', payload.scope?.table === 'online_daily_data', JSON.stringify(payload.scope ?? {}));
  check('inspection does not expose raw_data keys', !collectKeys(payload).includes('raw_data'), 'raw_data key must stay out of runtime guard payload');
  validateCollectionSourceSummary('inspection', payload.collection_source_summary, payload.scope?.platforms);

  const questions = asArray(payload.employee_questions);
  check('employee six-question rows exist', questions.length === 6, `count=${questions.length}`);
  for (const question of [
    '今天 OTA 数据有没有采到',
    '哪些字段可信',
    '哪些字段缺失',
    '收入/流量/转化出了什么问题',
    'AI 建议依据是什么',
    '下一步该执行什么动作',
  ]) {
    check(`employee question: ${question}`, questions.some((row) => row.question === question));
  }
  for (const row of questions) {
    const key = String(row?.key ?? row?.question ?? 'unknown');
    const rawNextAction = String(row?.next_action ?? '');
    const employeeNextAction = String(row?.employee_next_action ?? '');
    const employeeDetail = String(row?.employee_detail ?? '');
    if (rawNextAction.trim() !== '') {
      check(`${key} employee next action exists beside raw next_action`, employeeNextAction.trim() !== '', JSON.stringify(row));
    }
    check(`${key} employee detail exists`, employeeDetail.trim() !== '', JSON.stringify(row));
    check(`${key} employee next action avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeNextAction), employeeNextAction);
    check(`${key} employee detail avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeDetail), employeeDetail);
  }

  const actions = asArray(payload.next_actions);
  const missingRequirements = asArray(payload.missing_requirements);
  const missingCodes = new Set(missingRequirements.map((row) => String(row?.code ?? '')).filter(Boolean));
  const actionCodes = new Set(actions.map((row) => String(row?.action_code ?? '')).filter(Boolean));
  validateQuestionBlockerActionLinks('inspection employee question', questions, actions);
  const gapMatrix = fs.readFileSync(path.join(root, 'docs', 'phase1_ota_gap_explanation_matrix.md'), 'utf8');
  for (const code of missingCodes) {
    check(`missing requirement ${code} is explained in gap matrix`, gapMatrix.includes(code));
  }
  for (const row of missingRequirements) {
    const code = String(row?.code ?? 'missing');
    check(`missing requirement ${code} keeps technical message`, nonEmptyString(row?.message), JSON.stringify(row));
    check(`missing requirement ${code} has explicit status`, String(row?.status ?? '') === 'missing', JSON.stringify(row));
    check(`missing requirement ${code} has platform scope`, nonEmptyString(row?.platform), JSON.stringify(row));
    check(`missing requirement ${code} has action code`, nonEmptyString(row?.action_code), JSON.stringify(row));
    check(`missing requirement ${code} has action family`, nonEmptyString(row?.action_family), JSON.stringify(row));
    check(`missing requirement ${code} has question key`, nonEmptyString(row?.question_key), JSON.stringify(row));
    check(`missing requirement ${code} has related question keys`, asArray(row?.related_question_keys).length > 0, JSON.stringify(row));
    check(`missing requirement ${code} has resolving gap codes`, asArray(row?.resolves_missing_codes).includes(code), JSON.stringify(row));
    check(`missing requirement ${code} has live closure gap codes`, asArray(row?.live_closure_gap_codes).includes(code), JSON.stringify(row));
    check(`missing requirement ${code} has employee explanation`, nonEmptyString(row?.employee_explanation), JSON.stringify(row));
    check(`missing requirement ${code} has limited conclusions`, asArray(row?.limited_conclusions).length > 0, JSON.stringify(row));
    check(`missing requirement ${code} has still usable metrics`, asArray(row?.still_usable_metrics).length > 0, JSON.stringify(row));
    check(`missing requirement ${code} has explanation next action`, nonEmptyString(row?.explanation_next_action), JSON.stringify(row));
  }
  const inspectorSource = fs.readFileSync(path.join(root, 'scripts', 'inspect_phase1_ota_live_closure.php'), 'utf8');
  check('markdown report exposes missing employee explanations', inspectorSource.includes('employee_explanation') && inspectorSource.includes('员工解释'));
  check('markdown report exposes limited conclusions', inspectorSource.includes('limited_conclusions') && inspectorSource.includes('受限结论'));
  check('markdown report exposes still usable metrics', inspectorSource.includes('still_usable_metrics') && inspectorSource.includes('仍可使用'));
  check('markdown report exposes explanation next action', inspectorSource.includes('explanation_next_action') && inspectorSource.includes('补证据动作'));
  check('markdown report exposes next action family label', inspectorSource.includes('action_family_label') && inspectorSource.includes('动作类型'));
  check('markdown report exposes next action entry', inspectorSource.includes('inspection_next_action_entry') && inspectorSource.includes('入口'));
  check('markdown report exposes next action success criteria', inspectorSource.includes('inspection_next_action_success_criteria') && inspectorSource.includes('完成判定'));
  check('markdown report exposes blocker resolution actions', inspectorSource.includes('inspection_next_action_blocked_by_action_codes') && inspectorSource.includes('先处理动作'));
  check('markdown report exposes resolved missing codes', inspectorSource.includes('inspection_next_action_resolves_missing_codes') && inspectorSource.includes('解除缺口'));
  check('markdown report exposes employee question action codes', inspectorSource.includes('next_action_codes') && inspectorSource.includes('with_inspection_employee_question_action_codes'));
  check('markdown report exposes employee question action summaries', inspectorSource.includes('primary_next_action_code') && inspectorSource.includes('direct_next_action_code') && inspectorSource.includes('linked_action_count'));
  check('markdown report uses employee next action copy', inspectorSource.includes('employee_next_action') && inspectorSource.includes('inspection_employee_readable_copy'));
  check('markdown report exposes employee question evidence summary', inspectorSource.includes('inspection_employee_question_evidence') && inspectorSource.includes('证据摘要'));
  check('markdown report exposes platform latest-available summary', inspectorSource.includes('inspection_platform_coverage_summary') && inspectorSource.includes('platform_rows') && inspectorSource.includes('latest_available') && inspectorSource.includes('date_relation'));
  check('markdown report exposes platform field trust summary', inspectorSource.includes('inspection_platform_field_trust_summary') && inspectorSource.includes('field_trust_by_platform'));
  check('markdown report exposes blocking missing codes in evidence summary', inspectorSource.includes('blocking_missing_codes') && inspectorSource.includes('blocking:'));
  const todayQuestion = questions.find((row) => row.question === '今天 OTA 数据有没有采到');
  const todayEvidencePlatforms = asArray(todayQuestion?.evidence?.platforms);
  if (todayQuestion) {
    const coverage = todayQuestion.evidence?.coverage_status;
    check('collection coverage status is explicit', ['complete', 'partial', 'missing'].includes(coverage), String(coverage ?? ''));
    check('collection coverage exposes per-platform row details', todayEvidencePlatforms.length > 0, JSON.stringify(todayQuestion.evidence ?? {}));
    for (const platformRow of todayEvidencePlatforms) {
      const rowPlatform = String(platformRow?.platform ?? '');
      const latestAvailable = platformRow?.latest_available ?? {};
      check(`${rowPlatform} employee coverage exposes target-date row count`, Number.isFinite(Number(platformRow?.source_rows ?? platformRow?.target_date_rows)), JSON.stringify(platformRow ?? {}));
      check(`${rowPlatform} employee coverage exposes latest_available object`, isObject(latestAvailable) || latestAvailable === null, JSON.stringify(platformRow ?? {}));
      if (isObject(latestAvailable) && latestAvailable.date) {
        check(`${rowPlatform} employee coverage exposes latest_available date_relation`, typeof latestAvailable.date_relation === 'string' && latestAvailable.date_relation.length > 0, JSON.stringify(latestAvailable));
      }
    }
    if (coverage !== 'complete') {
      check('missing platforms are visible when coverage is not complete', asArray(todayQuestion.evidence?.missing_platforms).length > 0);
    }
  }

  const trustedFieldsQuestion = questions.find((row) => row?.key === 'trusted_fields');
  if (trustedFieldsQuestion) {
    check('trusted fields row exposes metric_trust_keys array', Array.isArray(trustedFieldsQuestion.evidence?.metric_trust_keys), JSON.stringify(trustedFieldsQuestion.evidence ?? {}));
    if (Number(trustedFieldsQuestion.evidence?.metric_trust_key_count ?? 0) > 0) {
      check('trusted fields row lists metric trust keys when count is positive', asArray(trustedFieldsQuestion.evidence?.metric_trust_keys).length > 0, JSON.stringify(trustedFieldsQuestion.evidence ?? {}));
    }
    const platformFieldTrust = asArray(trustedFieldsQuestion.evidence?.platform_field_trust);
    check('trusted fields row exposes platform field trust array', platformFieldTrust.length === asArray(payload.platforms).length, JSON.stringify(trustedFieldsQuestion.evidence ?? {}));
    for (const platform of asArray(payload.platforms)) {
      const platformName = String(platform?.platform ?? '');
      if (!platformName) continue;
      const row = platformFieldTrust.find((item) => String(item?.platform ?? '').toLowerCase() === platformName.toLowerCase());
      const sourceRows = Number(platform?.source_rows?.count ?? 0);
      const metricTrustKeys = asArray(platform?.metrics?.metric_trust_keys);
      check(`${platformName} trusted fields row carries platform trust detail`, Boolean(row), JSON.stringify(platformFieldTrust));
      if (row) {
        check(`${platformName} platform trust row count matches source rows`, Number(row?.target_date_rows ?? -1) === sourceRows, JSON.stringify(row));
        check(`${platformName} platform trust status is explicit`, typeof row.field_trust_status === 'string' && row.field_trust_status.length > 0, JSON.stringify(row));
        check(`${platformName} platform trust key count matches metric trust keys`, Number(row?.metric_trust_key_count ?? 0) === metricTrustKeys.length, JSON.stringify(row));
        if (sourceRows === 0) {
          check(`${platformName} platform trust does not claim missing target-date source as trusted`, row.field_trust_status === 'target_date_source_missing', JSON.stringify(row));
          check(`${platformName} missing target-date source contributes no trusted metric keys`, Number(row?.metric_trust_key_count ?? 0) === 0 && asArray(row?.metric_trust_keys).length === 0, JSON.stringify(row));
        }
      }
    }
  }

  const missingFieldsQuestion = questions.find((row) => row?.key === 'missing_fields');
  if (missingFieldsQuestion) {
    check('missing fields row exposes data_gap_codes array', Array.isArray(missingFieldsQuestion.evidence?.data_gap_codes), JSON.stringify(missingFieldsQuestion.evidence ?? {}));
    check('missing fields row exposes missing_field_codes array', Array.isArray(missingFieldsQuestion.evidence?.missing_field_codes), JSON.stringify(missingFieldsQuestion.evidence ?? {}));
    validateMissingFieldSummary('missing fields row', missingFieldsQuestion);
  }

  for (const platform of asArray(payload.platforms)) {
    const platformName = String(platform?.platform ?? '');
    if (platformName === '') continue;
    const sourceRows = Number(platform?.source_rows?.count ?? 0);
    const latestAvailable = platform?.source_rows?.latest_available ?? {};
    const latestRelation = String(latestAvailable?.date_relation ?? '');
    const sourceRowsCheck = asArray(platform?.checks).find((row) => row?.code === 'source_rows_present');
    const trustedFieldsVisibleCheck = asArray(platform?.checks).find((row) => row?.code === 'trusted_fields_visible');
    const metricStatus = String(platform?.metrics?.status ?? '');
    const metricTrustKeys = asArray(platform?.metrics?.metric_trust_keys);
    const employeePlatform = todayEvidencePlatforms.find((row) => String(row?.platform ?? '').toLowerCase() === platformName.toLowerCase());
    check(`${platformName} employee coverage carries platform row`, Boolean(employeePlatform), JSON.stringify(todayQuestion?.evidence ?? {}));
    if (employeePlatform) {
      const employeeRows = Number(employeePlatform?.source_rows ?? employeePlatform?.target_date_rows ?? -1);
      const employeeLatest = employeePlatform?.latest_available ?? {};
      check(`${platformName} employee coverage row count matches target-date source rows`, employeeRows === sourceRows, JSON.stringify(employeePlatform));
      check(`${platformName} employee coverage latest_available relation matches`, String(employeeLatest?.date_relation ?? '') === latestRelation, JSON.stringify(employeePlatform));
      if (latestAvailable?.date) {
        check(`${platformName} employee coverage latest_available date matches`, String(employeeLatest?.date ?? '') === String(latestAvailable.date), JSON.stringify(employeePlatform));
      }
    }

    if (sourceRows === 0) {
      check(`${platformName} target-date source rows are explicitly missing`, sourceRowsCheck?.status === 'missing', JSON.stringify(sourceRowsCheck ?? {}));
      check(`${platformName} trusted fields check does not prove missing target-date source`, trustedFieldsVisibleCheck?.status !== 'proved', JSON.stringify(trustedFieldsVisibleCheck ?? {}));
      check(`${platformName} missing source rows requirement exists`, missingCodes.has(`${platformName}_source_rows_missing`), [...missingCodes].join(','));
      check(`${platformName} missing platform is visible in employee coverage`, asArray(todayQuestion?.evidence?.missing_platforms).includes(platformName));
      check(`${platformName} latest_available does not prove target date`, latestRelation !== 'target_date', JSON.stringify(latestAvailable));
      check(`${platformName} non-target latest_available keeps coverage incomplete`, todayQuestion?.evidence?.coverage_status !== 'complete', String(todayQuestion?.evidence?.coverage_status ?? ''));
      check(`${platformName} target-date collection action exists`, [...actionCodes].some((code) => code.startsWith(`${platformName}_source_rows_missing`)), [...actionCodes].join(','));
    } else {
      check(`${platformName} target-date rows are proved independently of latest_available`, sourceRowsCheck?.status === 'proved', JSON.stringify(sourceRowsCheck ?? {}));
      check(`${platformName} target-date rows clear source missing requirement`, !missingCodes.has(`${platformName}_source_rows_missing`), [...missingCodes].join(','));
    }
    if (sourceRows > 0 && metricStatus === 'ready' && metricTrustKeys.length > 0) {
      check(`${platformName} trusted fields check is proved only with target-date rows and ready metric trust`, trustedFieldsVisibleCheck?.status === 'proved', JSON.stringify(trustedFieldsVisibleCheck ?? {}));
    } else {
      check(`${platformName} trusted fields check keeps unproved metric trust context explicit`, trustedFieldsVisibleCheck?.status !== 'proved', JSON.stringify(trustedFieldsVisibleCheck ?? {}));
    }

    if (['stale_before_target', 'future_dated_for_target'].includes(latestRelation)) {
      check(`${platformName} non-target latest_available is reference only`, sourceRows === 0 ? sourceRowsCheck?.status === 'missing' : sourceRowsCheck?.status === 'proved', JSON.stringify(sourceRowsCheck ?? {}));
      if (sourceRows === 0) {
        check(`${platformName} non-target latest_available cannot make employee row proved`, todayQuestion?.status !== 'proved', String(todayQuestion?.status ?? ''));
      }
    }
  }

  const metricQuestion = questions.find((row) => row.question === '收入/流量/转化出了什么问题');
  if (metricQuestion) {
    check('metric domain readiness is present', asArray(metricQuestion.evidence?.metric_domain_readiness).length > 0);
    validateMetricDomainSummary('metric question', metricQuestion);
    check('revenue ready platforms are explicit', Array.isArray(metricQuestion.evidence?.revenue_ready_platforms));
    check('traffic ready platforms are explicit', Array.isArray(metricQuestion.evidence?.traffic_ready_platforms));
    check('conversion ready platforms are explicit', Array.isArray(metricQuestion.evidence?.conversion_ready_platforms));
    check('revenue missing platforms are explicit', Array.isArray(metricQuestion.evidence?.revenue_missing_platforms));
    check('traffic missing platforms are explicit', Array.isArray(metricQuestion.evidence?.traffic_missing_platforms));
    check('conversion missing platforms are explicit', Array.isArray(metricQuestion.evidence?.conversion_missing_platforms));
    check('metric domain gap codes are explicit', Array.isArray(metricQuestion.evidence?.metric_domain_gap_codes));
  }

  const aiQuestion = questions.find((row) => row.question === 'AI 建议依据是什么');
  if (aiQuestion && aiQuestion.status !== 'proved') {
    const aiBlockingCodes = asArray(aiQuestion.evidence?.blocking_missing_codes).map(String);
    check('AI evidence blockers are visible when AI evidence is missing', aiBlockingCodes.length > 0);
    check('AI evidence row keeps blockers in summary evidence', collectKeys(aiQuestion.evidence ?? {}).includes('blocking_missing_codes'), JSON.stringify(aiQuestion.evidence ?? {}));
    check('AI evidence row exposes diagnosis status', String(aiQuestion.evidence?.diagnosis_status ?? '').length > 0, JSON.stringify(aiQuestion.evidence ?? {}));
    check('AI evidence row exposes action item status', String(aiQuestion.evidence?.action_item_status ?? '').length > 0, JSON.stringify(aiQuestion.evidence ?? {}));
    check('AI evidence row exposes readable source policy key', String(aiQuestion.evidence?.source_policy ?? '').length > 0, JSON.stringify(aiQuestion.evidence ?? {}));
    if (aiBlockingCodes.length > 0) {
      const diagnosisStatus = String(aiQuestion.evidence?.diagnosis_status ?? '');
      const sourcePolicy = String(aiQuestion.evidence?.source_policy ?? '');
      const onlyMissingRealDiagnosis = aiBlockingCodes.length === 1 && aiBlockingCodes.includes('ai_diagnosis_evidence_sample_missing');
      if (onlyMissingRealDiagnosis) {
        check('AI evidence row marks missing real diagnosis response', diagnosisStatus === 'missing_real_api_response', JSON.stringify(aiQuestion.evidence ?? {}));
        check('AI evidence row uses missing diagnosis response policy', sourcePolicy === 'missing_real_ota_diagnosis_response', JSON.stringify(aiQuestion.evidence ?? {}));
      } else {
        check('AI evidence row marks verified upstream OTA blocker', diagnosisStatus === 'blocked_by_verified_ota_gaps', JSON.stringify(aiQuestion.evidence ?? {}));
        check('AI evidence row uses read-only OTA gap policy', sourcePolicy === 'read_existing_ota_gap_evidence_only', JSON.stringify(aiQuestion.evidence ?? {}));
      }
    }
    check('AI evidence row points to direct diagnosis action', ['phase1_collect_ai_diagnosis_evidence', 'collect_ai_diagnosis_evidence', 'resolve_ai_diagnosis_blocked_action_items'].includes(String(aiQuestion.direct_next_action_code ?? aiQuestion.evidence?.direct_next_action_code ?? '')), JSON.stringify(aiQuestion));
  }

  const nextStepQuestion = questions.find((row) => row.question === '下一步该执行什么动作');
  if (nextStepQuestion && nextStepQuestion.status !== 'proved') {
    check('operation action blockers include AI action gap when execution is missing', ['ai_action_items_missing', 'ai_action_items_blocked'].some((code) => asArray(nextStepQuestion.evidence?.blocking_missing_codes).includes(code)), JSON.stringify(nextStepQuestion.evidence ?? {}));
    check('operation action row keeps blockers in summary evidence', collectKeys(nextStepQuestion.evidence ?? {}).includes('blocking_missing_codes'), JSON.stringify(nextStepQuestion.evidence ?? {}));
    check('operation action row exposes execution source policy', String(nextStepQuestion.evidence?.source_policy ?? '') === 'read_existing_operation_execution_state_only', JSON.stringify(nextStepQuestion.evidence ?? {}));
    check('operation action row exposes completion signal count', Number.isFinite(Number(nextStepQuestion.evidence?.completion_signal_count)), JSON.stringify(nextStepQuestion.evidence ?? {}));
    check('operation action row never exposes raw execution payload', nextStepQuestion.evidence?.raw_data_exposed === false || nextStepQuestion.evidence?.raw_data_exposed === undefined, JSON.stringify(nextStepQuestion.evidence ?? {}));
    check('operation action row points to direct execution evidence action', ['phase1_create_operation_execution_evidence', 'collect_operation_execution_evidence'].includes(String(nextStepQuestion.direct_next_action_code ?? nextStepQuestion.evidence?.direct_next_action_code ?? '')), JSON.stringify(nextStepQuestion));
    if (String(nextStepQuestion.evidence?.operation_evidence_status ?? '') === 'missing' && asArray(nextStepQuestion.evidence?.blocking_missing_codes).length > 0) {
      check('operation action row is warning when blockers define the next step', nextStepQuestion.status === 'warning', JSON.stringify(nextStepQuestion));
    }
  }
  for (const [index, question] of questions.entries()) {
    const prefix = `employee question ${index + 1}`;
    check(`${prefix} has stable key`, typeof question.key === 'string' && question.key.length > 0, JSON.stringify(question));
    check(`${prefix} has next_action_codes array`, Array.isArray(question.next_action_codes), JSON.stringify(question));
    if (!['proved', 'no_gap_reported'].includes(String(question.status ?? ''))) {
      check(`${prefix} has linked action code when not proved`, asArray(question.next_action_codes).length > 0, JSON.stringify(question));
      const primaryActionCode = String(question.primary_next_action_code ?? question.evidence?.primary_next_action_code ?? '');
      const directActionCode = String(question.direct_next_action_code ?? question.evidence?.direct_next_action_code ?? '');
      const linkedActionCount = Number(question.evidence?.linked_action_count ?? 0);
      check(`${prefix} has primary next action summary when not proved`, primaryActionCode.length > 0, JSON.stringify(question));
      check(`${prefix} primary next action is linked`, asArray(question.next_action_codes).includes(primaryActionCode), JSON.stringify(question));
      check(`${prefix} has direct next action summary when not proved`, directActionCode.length > 0, JSON.stringify(question));
      check(`${prefix} direct next action is linked`, asArray(question.next_action_codes).includes(directActionCode), JSON.stringify(question));
      check(`${prefix} linked action count matches codes`, linkedActionCount === asArray(question.next_action_codes).length, JSON.stringify(question));
      check(`${prefix} exposes blocking gap codes when not proved`, asArray(question.blocking_gap_codes ?? question.evidence?.blocking_gap_codes).length > 0, JSON.stringify(question));
    }
  }

  check('next_actions array exists', Array.isArray(payload.next_actions));
  if (missingRequirements.length > 0) {
    check('missing requirements produce executable next actions', actions.length > 0, `missing=${missingRequirements.length}`);
  }

  let previousRank = null;
  for (const [index, action] of actions.entries()) {
    const prefix = `next action ${index + 1}`;
    check(`${prefix} has stable action_code`, typeof action.action_code === 'string' && action.action_code.length > 0);
    check(`${prefix} has stable action_family`, typeof action.action_family === 'string' && action.action_family.length > 0);
    check(`${prefix} has platform scope`, nonEmptyString(action.platform), JSON.stringify(action));
    const expectedPlatform = expectedActionPlatform(action);
    if (expectedPlatform !== '') {
      check(`${prefix} platform matches action code`, String(action.platform ?? '').toLowerCase() === expectedPlatform, JSON.stringify(action));
    }
    check(`${prefix} has question_key`, typeof action.question_key === 'string' && action.question_key.length > 0);
    check(`${prefix} has related_question_keys`, Array.isArray(action.related_question_keys) && action.related_question_keys.length > 0);
    check(`${prefix} has execution entry`, typeof action.entry === 'string' && action.entry.trim().length > 0);
    check(`${prefix} has success criteria`, typeof action.success_criteria === 'string' && action.success_criteria.trim().length > 0);
    check(`${prefix} has valid priority`, ['high', 'medium', 'low'].includes(action.priority), String(action.priority ?? ''));
    check(`${prefix} has valid status`, ['missing', 'blocked'].includes(action.status), String(action.status ?? ''));
    check(`${prefix} has owner`, typeof action.owner === 'string' && action.owner.trim().length > 0);
    check(`${prefix} has action text`, typeof action.action === 'string' && action.action.trim().length > 0);
    check(`${prefix} has evidence_needed`, asArray(action.evidence_needed).length > 0);
    check(`${prefix} has blocked_by array`, Array.isArray(action.blocked_by));
    check(`${prefix} has resolves_missing_codes array`, Array.isArray(action.resolves_missing_codes));
    check(`${prefix} has live_closure_gap_codes array`, Array.isArray(action.live_closure_gap_codes));
    check(`${prefix} names live closure gap codes`, asArray(action.live_closure_gap_codes).length > 0);
    check(`${prefix} has blocked_by_action_codes array`, Array.isArray(action.blocked_by_action_codes));
    check(`${prefix} does not self-block`, !asArray(action.blocked_by_action_codes).map(String).includes(String(action.action_code ?? '')), JSON.stringify(action));
    check(`${prefix} names resolved missing codes`, asArray(action.resolves_missing_codes).length > 0);
    check(`${prefix} has employee explanation`, nonEmptyString(action.employee_explanation), JSON.stringify(action));
    check(`${prefix} has limited conclusions`, asArray(action.limited_conclusions).length > 0, JSON.stringify(action));
    check(`${prefix} has still usable metrics`, asArray(action.still_usable_metrics).length > 0, JSON.stringify(action));
    check(`${prefix} has explanation next action`, nonEmptyString(action.explanation_next_action), JSON.stringify(action));
    validateEmployeeActionCopy(prefix, action);
    check(`${prefix} has protected boundary`, typeof action.protected_boundary === 'string' && action.protected_boundary.trim().length > 0);
    if (action.action_family === 'target_date_source_rows') {
      const expectedEntries = expectedSourceRowsActionEntries(action);
      if (expectedEntries) {
        const optionEntries = actionEntryOptionEntries(action);
        check(`${prefix} source row action uses manual collection entry`, action.entry === expectedEntries.manual, JSON.stringify(action));
        check(`${prefix} source row action exposes manual entry option`, optionEntries.includes(expectedEntries.manual), JSON.stringify(action));
        check(`${prefix} source row action exposes browser profile entry option`, optionEntries.includes(expectedEntries.profile), JSON.stringify(action));
        check(`${prefix} source row action keeps status check entry option`, optionEntries.includes(expectedEntries.status), JSON.stringify(action));
        check(`${prefix} source row action explains when to use entry options`, actionEntryOptionFieldValues(action, 'use_when').length >= 3, JSON.stringify(action));
        check(`${prefix} source row action names entry option requirements`, actionEntryOptionFieldValues(action, 'requires').length >= 3, JSON.stringify(action));
        check(`${prefix} source row action preserves entry option boundaries`, actionEntryOptionFieldValues(action, 'boundary').some((value) => value.includes('不改变')), JSON.stringify(action));
        validateSourceRowsEntryOptionReadiness(prefix, action.entry_options);
      }
    }
    if (action.action_family === 'traffic_conversion_facts') {
      const expectedEntry = expectedTrafficActionEntry(action);
      check(`${prefix} traffic action has platform-specific entry`, expectedEntry !== '', JSON.stringify(action));
      check(`${prefix} traffic action uses existing traffic fetch entry`, action.entry === expectedEntry, JSON.stringify(action));
      check(`${prefix} traffic action does not point to read-only revenue metrics`, action.entry !== '/api/ota-standard/revenue-metrics', JSON.stringify(action));
    }
    if (action.status === 'blocked') {
      check(`${prefix} blocked action names blockers`, asArray(action.blocked_by).length > 0);
      check(`${prefix} blocked action names resolver actions`, asArray(action.blocked_by_action_codes).length > 0);
    }
    if (action.status === 'missing') {
      check(`${prefix} missing action is directly actionable`, asArray(action.blocked_by).length === 0);
      check(`${prefix} missing action does not require resolver actions`, asArray(action.blocked_by_action_codes).length === 0);
    }
    const currentRank = actionRank(action);
    if (previousRank) {
      check(`${prefix} order is missing-before-blocked, family-ranked, and priority-sorted`, rankIsNonDecreasing(previousRank, currentRank), `${previousRank.join('/')} -> ${currentRank.join('/')}`);
    }
    previousRank = currentRank;
  }

  const summary = payload.closure_summary ?? {};
  check('closure summary stays in OTA channel scope', summary.metric_scope === 'ota_channel', JSON.stringify(summary));
  check('closure summary counts six questions', summary.employee_question_count === 6, JSON.stringify(summary));
  check('closure summary keeps protected acquisition boundary', String(summary.protected_boundary ?? '').includes('不改变携程/美团手动或自动获取逻辑'));
  check('closure summary exposes reference-only policy', summary.reference_policy === 'latest_available_and_history_rows_are_reference_only_not_target_date_proof', JSON.stringify(summary));
  if (summary.status === 'incomplete') {
    check('closure summary exposes top action code while incomplete', String(summary.top_action_code ?? '') !== '', JSON.stringify(summary));
    check('closure summary exposes top action entry while incomplete', String(summary.top_action_entry ?? '') !== '', JSON.stringify(summary));
    check('closure summary exposes top action entry options while incomplete', asArray(summary.top_action_entry_options).length > 0, JSON.stringify(summary));
    check('closure summary exposes top action impacted questions while incomplete', asArray(summary.top_action_related_question_keys).includes(String(summary.top_question_key ?? '')), JSON.stringify(summary));
    check('closure summary exposes top action resolved missing codes while incomplete', asArray(summary.top_action_resolves_missing_codes).length > 0, JSON.stringify(summary));
    check('closure summary exposes top action live closure gaps while incomplete', asArray(summary.top_action_live_closure_gap_codes).length > 0, JSON.stringify(summary));
    check('closure summary exposes top action source snapshot while incomplete', isObject(summary.top_action_source_snapshot), JSON.stringify(summary));
    if (actions.length > 0) {
      check('closure summary top action is first visible next action', String(summary.top_action_code ?? '') === String(actions[0]?.action_code ?? ''), `${summary.top_action_code ?? ''} vs ${actions[0]?.action_code ?? ''}`);
    }
    if (String(summary.top_action_code ?? '').includes('target_date_source_rows')) {
      validateSourceRowsEntryOptionReadiness('closure summary top action', summary.top_action_entry_options);
    }
    check('closure summary top action source snapshot names platform', ['ctrip', 'meituan'].includes(String(summary.top_action_source_snapshot?.platform ?? '').toLowerCase()), JSON.stringify(summary.top_action_source_snapshot ?? {}));
    check('closure summary top action source snapshot exposes target rows', Number.isFinite(Number(summary.top_action_source_snapshot?.target_date_rows)), JSON.stringify(summary.top_action_source_snapshot ?? {}));
    check('closure summary top action source snapshot keeps reference-only proof boundary', String(summary.top_action_source_snapshot?.reference_policy ?? '').includes('latest_available'), JSON.stringify(summary.top_action_source_snapshot ?? {}));
    check('closure summary top action maps to a missing question', asArray(summary.missing_question_keys).includes(String(summary.top_question_key ?? '')), JSON.stringify(summary));
  }
}

if (evidencePayload) {
  check('evidence package stays in OTA channel scope', evidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(evidencePayload.scope ?? {}));
  check('evidence package is read-only', evidencePayload.generator?.mode === 'read_only' && evidencePayload.generator?.writes_ota_data === false, JSON.stringify(evidencePayload.generator ?? {}));
  check('evidence package does not change acquisition logic', evidencePayload.generator?.changes_acquisition_logic === false, JSON.stringify(evidencePayload.generator ?? {}));
  check('evidence package does not expose raw_data keys', !collectKeys(evidencePayload).includes('raw_data'), 'raw_data key must stay out of evidence package');
  validateCollectionSourceSummary('evidence package', evidencePayload.collection_source_summary, evidencePayload.scope?.platforms);

  const reliability = evidencePayload.collection_reliability ?? {};
  const reliabilityPlatforms = asArray(reliability.platforms);
  check('evidence package collection coverage is explicit', ['complete', 'partial', 'missing'].includes(reliability.coverage_status), JSON.stringify(reliability));
  check('evidence package collection reliability exposes platform rows', reliabilityPlatforms.length > 0, JSON.stringify(reliability));
  for (const platformRow of reliabilityPlatforms) {
    const rowPlatform = String(platformRow?.platform ?? '');
    const latestAvailable = platformRow?.latest_available ?? {};
    check(`${rowPlatform} evidence package reliability exposes source rows`, Number.isFinite(Number(platformRow?.source_rows)), JSON.stringify(platformRow ?? {}));
    check(`${rowPlatform} evidence package reliability exposes latest_available object`, isObject(latestAvailable) || latestAvailable === null, JSON.stringify(platformRow ?? {}));
    if (isObject(latestAvailable) && latestAvailable.date) {
      check(`${rowPlatform} evidence package reliability exposes latest_available date_relation`, typeof latestAvailable.date_relation === 'string' && latestAvailable.date_relation.length > 0, JSON.stringify(latestAvailable));
    }
  }
  if (reliability.coverage_status !== 'complete') {
    check('evidence package missing platforms are visible', asArray(reliability.missing_platforms).length > 0, JSON.stringify(reliability));
  }

  const evidenceQuestions = asArray(evidencePayload.employee_questions);
  check('evidence package has employee six-question rows', evidenceQuestions.length === 6, `count=${evidenceQuestions.length}`);
  for (const [index, question] of evidenceQuestions.entries()) {
    const prefix = `evidence package employee question ${index + 1}`;
    if (!['proved', 'no_gap_reported'].includes(String(question.status ?? ''))) {
      const primaryActionCode = String(question.primary_next_action_code ?? question.evidence?.primary_next_action_code ?? '');
      const directActionCode = String(question.direct_next_action_code ?? question.evidence?.direct_next_action_code ?? '');
      check(`${prefix} has primary next action summary when not proved`, primaryActionCode.length > 0, JSON.stringify(question));
      check(`${prefix} has direct next action summary when not proved`, directActionCode.length > 0, JSON.stringify(question));
      check(`${prefix} linked action count matches codes`, Number(question.evidence?.linked_action_count ?? 0) === asArray(question.next_action_codes).length, JSON.stringify(question));
      check(`${prefix} exposes blocking gap codes when not proved`, asArray(question.blocking_gap_codes ?? question.evidence?.blocking_gap_codes).length > 0, JSON.stringify(question));
    }
  }
  const evidenceTodayQuestion = evidenceQuestions.find((row) => row.question === '今天 OTA 数据有没有采到');
  if (evidenceTodayQuestion && reliability.coverage_status !== 'complete') {
    check('evidence package does not mark partial collection as proved', evidenceTodayQuestion.status !== 'proved', JSON.stringify(evidenceTodayQuestion));
    check('evidence package employee row carries coverage status', evidenceTodayQuestion.evidence?.coverage_status === reliability.coverage_status, JSON.stringify(evidenceTodayQuestion.evidence ?? {}));
  }
  const evidenceTodayPlatforms = asArray(evidenceTodayQuestion?.evidence?.platforms);
  if (evidenceTodayQuestion) {
    check('evidence package employee row exposes platform rows', evidenceTodayPlatforms.length === reliabilityPlatforms.length, JSON.stringify(evidenceTodayQuestion.evidence ?? {}));
    for (const reliabilityRow of reliabilityPlatforms) {
      const platformName = String(reliabilityRow?.platform ?? '');
      const employeeRow = evidenceTodayPlatforms.find((row) => String(row?.platform ?? '').toLowerCase() === platformName.toLowerCase());
      check(`${platformName} evidence package employee row carries platform detail`, Boolean(employeeRow), JSON.stringify(evidenceTodayQuestion.evidence ?? {}));
      if (employeeRow) {
        const employeeRows = Number(employeeRow?.source_rows ?? employeeRow?.target_date_rows ?? -1);
        const employeeLatest = employeeRow?.latest_available ?? {};
        const reliabilityLatest = reliabilityRow?.latest_available ?? {};
        check(`${platformName} evidence package employee row count matches reliability`, employeeRows === Number(reliabilityRow?.source_rows ?? -1), JSON.stringify(employeeRow));
        check(`${platformName} evidence package employee latest relation matches reliability`, String(employeeLatest?.date_relation ?? '') === String(reliabilityLatest?.date_relation ?? ''), JSON.stringify(employeeRow));
        if (reliabilityLatest?.date) {
          check(`${platformName} evidence package employee latest date matches reliability`, String(employeeLatest?.date ?? '') === String(reliabilityLatest.date), JSON.stringify(employeeRow));
        }
      }
    }
  }
  const evidenceTrustedFieldsQuestion = evidenceQuestions.find((row) => row?.key === 'trusted_fields');
  if (evidenceTrustedFieldsQuestion) {
    check('evidence package trusted fields row exposes metric_trust_keys array', Array.isArray(evidenceTrustedFieldsQuestion.evidence?.metric_trust_keys), JSON.stringify(evidenceTrustedFieldsQuestion.evidence ?? {}));
    if (Number(evidenceTrustedFieldsQuestion.evidence?.metric_trust_key_count ?? 0) > 0) {
      check('evidence package trusted fields row lists metric trust keys when count is positive', asArray(evidenceTrustedFieldsQuestion.evidence?.metric_trust_keys).length > 0, JSON.stringify(evidenceTrustedFieldsQuestion.evidence ?? {}));
    }
    const evidencePlatformFieldTrust = asArray(evidenceTrustedFieldsQuestion.evidence?.platform_field_trust);
    check('evidence package trusted fields row exposes platform field trust array', evidencePlatformFieldTrust.length === asArray(evidencePayload.platform_evidence).length, JSON.stringify(evidenceTrustedFieldsQuestion.evidence ?? {}));
    for (const platform of asArray(evidencePayload.platform_evidence)) {
      const platformName = String(platform?.platform ?? '');
      if (!platformName) continue;
      const row = evidencePlatformFieldTrust.find((item) => String(item?.platform ?? '').toLowerCase() === platformName.toLowerCase());
      const sourceRows = Number(platform?.source_rows?.count ?? 0);
      const metricStatus = String(platform?.revenue_metrics?.status ?? '');
      const rawMetricTrust = isObject(platform?.revenue_metrics?.metric_trust) ? platform.revenue_metrics.metric_trust : {};
      const metricTrustKeys = sourceRows > 0 && metricStatus === 'ready' ? trustedMetricTrustKeys(rawMetricTrust) : [];
      check(`${platformName} evidence package trusted fields carries platform trust detail`, Boolean(row), JSON.stringify(evidencePlatformFieldTrust));
      if (row) {
        check(`${platformName} evidence package platform trust row count matches source rows`, Number(row?.target_date_rows ?? -1) === sourceRows, JSON.stringify(row));
        check(`${platformName} evidence package platform trust status is explicit`, typeof row.field_trust_status === 'string' && row.field_trust_status.length > 0, JSON.stringify(row));
        check(`${platformName} evidence package platform trust key count matches metric trust keys`, Number(row?.metric_trust_key_count ?? 0) === metricTrustKeys.length, JSON.stringify(row));
        if (sourceRows === 0) {
          check(`${platformName} evidence package missing target-date source contributes no trusted metric keys`, Number(row?.metric_trust_key_count ?? 0) === 0 && asArray(row?.metric_trust_keys).length === 0, JSON.stringify(row));
        }
      }
    }
  }
  const evidenceMissingFieldsQuestion = evidenceQuestions.find((row) => row?.key === 'missing_fields');
  if (evidenceMissingFieldsQuestion) {
    check('evidence package missing fields row exposes data_gap_codes array', Array.isArray(evidenceMissingFieldsQuestion.evidence?.data_gap_codes), JSON.stringify(evidenceMissingFieldsQuestion.evidence ?? {}));
    check('evidence package missing fields row exposes missing_field_codes array', Array.isArray(evidenceMissingFieldsQuestion.evidence?.missing_field_codes), JSON.stringify(evidenceMissingFieldsQuestion.evidence ?? {}));
    validateMissingFieldSummary('evidence package missing fields row', evidenceMissingFieldsQuestion);
  }

  const evidenceMetrics = evidencePayload.revenue_metrics ?? {};
  const evidenceMetricQuestionForSummary = evidenceQuestions.find((row) => row?.key === 'revenue_traffic_conversion' || row?.question === '收入/流量/转化出了什么问题');
  if (evidenceMetricQuestionForSummary) {
    validateMetricDomainSummary('evidence package metric question', evidenceMetricQuestionForSummary);
  }
  check('evidence package revenue metric status is platform-aware', ['ready', 'partial', 'empty'].includes(evidenceMetrics.status), JSON.stringify(evidenceMetrics));
  if (asArray(evidenceMetrics.revenue_ready_platforms).length > 0) {
    check('evidence package exposes revenue-ready platforms', evidenceMetrics.status !== 'empty', JSON.stringify(evidenceMetrics));
  }
  check('evidence package metric domain readiness is present', asArray(evidenceMetrics.metric_domain_readiness).length > 0, JSON.stringify(evidenceMetrics));
  check('evidence package exposes conversion-ready platforms', Array.isArray(evidenceMetrics.conversion_ready_platforms), JSON.stringify(evidenceMetrics));
  check('evidence package exposes revenue-missing platforms', Array.isArray(evidenceMetrics.revenue_missing_platforms), JSON.stringify(evidenceMetrics));
  check('evidence package exposes traffic-missing platforms', Array.isArray(evidenceMetrics.traffic_missing_platforms), JSON.stringify(evidenceMetrics));
  check('evidence package exposes conversion-missing platforms', Array.isArray(evidenceMetrics.conversion_missing_platforms), JSON.stringify(evidenceMetrics));
  check('evidence package exposes metric domain gap codes', Array.isArray(evidenceMetrics.metric_domain_gap_codes), JSON.stringify(evidenceMetrics));

  const evidenceActions = asArray(evidencePayload.next_actions);
  check('evidence package next_actions array exists', Array.isArray(evidencePayload.next_actions));
  validateQuestionBlockerActionLinks('evidence package employee question', evidenceQuestions, evidenceActions);
  if (reliability.coverage_status !== 'complete') {
    check('evidence package creates target-date collection action for missing platform', evidenceActions.some((action) => String(action?.action_code ?? '').includes('source_rows_missing')), JSON.stringify(evidenceActions));
  }
  for (const [index, action] of evidenceActions.entries()) {
    const prefix = `evidence package next action ${index + 1}`;
    check(`${prefix} has priority`, ['high', 'medium', 'low'].includes(action.priority), String(action.priority ?? ''));
    check(`${prefix} has action_family`, typeof action.action_family === 'string' && action.action_family.length > 0);
    check(`${prefix} has platform scope`, nonEmptyString(action.platform), JSON.stringify(action));
    const expectedPlatform = expectedActionPlatform(action);
    if (expectedPlatform !== '') {
      check(`${prefix} platform matches action code`, String(action.platform ?? '').toLowerCase() === expectedPlatform, JSON.stringify(action));
    }
    check(`${prefix} has question_key`, typeof action.question_key === 'string' && action.question_key.length > 0);
    check(`${prefix} has related_question_keys`, Array.isArray(action.related_question_keys) && action.related_question_keys.length > 0);
    check(`${prefix} has entry`, typeof action.entry === 'string' && action.entry.trim().length > 0);
    check(`${prefix} has success_criteria`, typeof action.success_criteria === 'string' && action.success_criteria.trim().length > 0);
    check(`${prefix} has resolves_missing_codes`, Array.isArray(action.resolves_missing_codes) && action.resolves_missing_codes.length > 0);
    check(`${prefix} has blocked_by_action_codes`, Array.isArray(action.blocked_by_action_codes));
    check(`${prefix} has status`, ['missing', 'blocked'].includes(action.status), String(action.status ?? ''));
    validateEmployeeActionCopy(prefix, action);
    check(`${prefix} has protected boundary`, typeof action.protected_boundary === 'string' && action.protected_boundary.trim().length > 0);
    if (action.action_family === 'target_date_source_rows') {
      const expectedEntries = expectedSourceRowsActionEntries(action);
      if (expectedEntries) {
        const optionEntries = actionEntryOptionEntries(action);
        check(`${prefix} source row action uses manual collection entry`, action.entry === expectedEntries.manual, JSON.stringify(action));
        check(`${prefix} source row action exposes manual entry option`, optionEntries.includes(expectedEntries.manual), JSON.stringify(action));
        check(`${prefix} source row action exposes browser profile entry option`, optionEntries.includes(expectedEntries.profile), JSON.stringify(action));
        check(`${prefix} source row action keeps status check entry option`, optionEntries.includes(expectedEntries.status), JSON.stringify(action));
        validateSourceRowsEntryOptionReadiness(prefix, action.entry_options);
      }
    }
    if (action.action_family === 'traffic_conversion_facts') {
      const expectedEntry = expectedTrafficActionEntry(action);
      check(`${prefix} traffic action has platform-specific entry`, expectedEntry !== '', JSON.stringify(action));
      check(`${prefix} traffic action uses existing traffic fetch entry`, action.entry === expectedEntry, JSON.stringify(action));
      check(`${prefix} traffic action does not point to read-only revenue metrics`, action.entry !== '/api/ota-standard/revenue-metrics', JSON.stringify(action));
    }
  }

  const evidenceSummary = evidencePayload.closure_summary ?? {};
  check('evidence package closure summary stays OTA scoped', evidenceSummary.metric_scope === 'ota_channel', JSON.stringify(evidenceSummary));
  check('evidence package closure summary counts six questions', evidenceSummary.employee_question_count === 6, JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action', evidenceSummary.status !== 'incomplete' || String(evidenceSummary.top_action_code ?? '') !== '', JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action entry options', evidenceSummary.status !== 'incomplete' || asArray(evidenceSummary.top_action_entry_options).length > 0, JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action impact', evidenceSummary.status !== 'incomplete' || asArray(evidenceSummary.top_action_related_question_keys).length > 0, JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action resolved gaps', evidenceSummary.status !== 'incomplete' || asArray(evidenceSummary.top_action_resolves_missing_codes).length > 0, JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action live gaps', evidenceSummary.status !== 'incomplete' || asArray(evidenceSummary.top_action_live_closure_gap_codes).length > 0, JSON.stringify(evidenceSummary));
  check('evidence package closure summary exposes top action source snapshot', evidenceSummary.status !== 'incomplete' || isObject(evidenceSummary.top_action_source_snapshot), JSON.stringify(evidenceSummary));
  if (evidenceSummary.status === 'incomplete') {
    if (evidenceActions.length > 0) {
      check('evidence package closure summary top action is first visible next action', String(evidenceSummary.top_action_code ?? '') === String(evidenceActions[0]?.action_code ?? ''), `${evidenceSummary.top_action_code ?? ''} vs ${evidenceActions[0]?.action_code ?? ''}`);
    }
    check('evidence package closure summary top action source snapshot names platform', ['ctrip', 'meituan'].includes(String(evidenceSummary.top_action_source_snapshot?.platform ?? '').toLowerCase()), JSON.stringify(evidenceSummary.top_action_source_snapshot ?? {}));
    if (String(evidenceSummary.top_action_code ?? '').includes('target_date_source_rows')) {
      validateSourceRowsEntryOptionReadiness('evidence package closure summary top action', evidenceSummary.top_action_entry_options);
    }
    check('evidence package closure summary top action source snapshot exposes target rows', Number.isFinite(Number(evidenceSummary.top_action_source_snapshot?.target_date_rows)), JSON.stringify(evidenceSummary.top_action_source_snapshot ?? {}));
    check('evidence package closure summary top action source snapshot keeps reference-only proof boundary', String(evidenceSummary.top_action_source_snapshot?.reference_policy ?? '').includes('latest_available'), JSON.stringify(evidenceSummary.top_action_source_snapshot ?? {}));
  }
}

if (blockedDiagnosisEvidencePayload) {
  check('blocked diagnosis evidence package stays read-only', blockedDiagnosisEvidencePayload.generator?.mode === 'read_only' && blockedDiagnosisEvidencePayload.generator?.writes_ota_data === false, JSON.stringify(blockedDiagnosisEvidencePayload.generator ?? {}));
  check('blocked diagnosis evidence package stays OTA scoped', blockedDiagnosisEvidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(blockedDiagnosisEvidencePayload.scope ?? {}));
  check('blocked diagnosis evidence package does not expose raw_data keys', !collectKeys(blockedDiagnosisEvidencePayload).includes('raw_data'), 'raw_data key must stay out of blocked diagnosis evidence package');

  const blockedQuestions = asArray(blockedDiagnosisEvidencePayload.employee_questions);
  const blockedAiQuestion = blockedQuestions.find((row) => row.question === 'AI 建议依据是什么');
  const blockedNextQuestion = blockedQuestions.find((row) => row.question === '下一步该执行什么动作');
  check('blocked diagnosis evidence is visible but not proved as actionable AI advice', blockedAiQuestion?.status === 'warning', JSON.stringify(blockedAiQuestion ?? {}));
  check('blocked diagnosis counts blocked action items', Number(blockedAiQuestion?.evidence?.blocked_action_item_count ?? 0) > 0, JSON.stringify(blockedAiQuestion?.evidence ?? {}));
  check('blocked diagnosis has no actionable action items', Number(blockedAiQuestion?.evidence?.actionable_action_item_count ?? 0) === 0, JSON.stringify(blockedAiQuestion?.evidence ?? {}));
  check('blocked diagnosis keeps operation execution blocked by AI action items', asArray(blockedNextQuestion?.evidence?.blocking_missing_codes).includes('ai_action_items_blocked'), JSON.stringify(blockedNextQuestion?.evidence ?? {}));

  const blockedActions = asArray(blockedDiagnosisEvidencePayload.next_actions);
  const resolveAction = blockedActions.find((action) => action?.action_code === 'resolve_ai_diagnosis_blocked_action_items');
  check('blocked diagnosis creates explicit AI-blocker resolution action', Boolean(resolveAction), JSON.stringify(blockedActions));
  if (resolveAction) {
    check('AI-blocker resolution action is blocked', resolveAction.status === 'blocked', JSON.stringify(resolveAction));
    check('AI-blocker resolution action preserves boundary', String(resolveAction.protected_boundary ?? '').includes('不能把阻断 action_items 当成可执行经营建议'), JSON.stringify(resolveAction));
  }
  check('blocked diagnosis evidence keeps closure incomplete', blockedDiagnosisEvidencePayload.closure_summary?.status === 'incomplete', JSON.stringify(blockedDiagnosisEvidencePayload.closure_summary ?? {}));
}

if (incompleteOperationEvidencePayload) {
  check('incomplete operation evidence package stays read-only', incompleteOperationEvidencePayload.generator?.mode === 'read_only' && incompleteOperationEvidencePayload.generator?.writes_ota_data === false, JSON.stringify(incompleteOperationEvidencePayload.generator ?? {}));
  check('incomplete operation evidence package stays OTA scoped', incompleteOperationEvidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(incompleteOperationEvidencePayload.scope ?? {}));
  check('incomplete operation evidence package does not expose raw_data keys', !collectKeys(incompleteOperationEvidencePayload).includes('raw_data'), 'raw_data key must stay out of incomplete operation evidence package');

  const incompleteQuestions = asArray(incompleteOperationEvidencePayload.employee_questions);
  const incompleteAiQuestion = incompleteQuestions.find((row) => row.question === 'AI 建议依据是什么');
  const incompleteNextQuestion = incompleteQuestions.find((row) => row.question === '下一步该执行什么动作');
  check('incomplete operation keeps actionable AI evidence proved', incompleteAiQuestion?.status === 'proved', JSON.stringify(incompleteAiQuestion ?? {}));
  check('incomplete operation evidence is warning, not proved', incompleteNextQuestion?.status === 'warning', JSON.stringify(incompleteNextQuestion ?? {}));
  check('incomplete operation counts execution intent', Number(incompleteNextQuestion?.evidence?.execution_intent_count ?? 0) > 0, JSON.stringify(incompleteNextQuestion?.evidence ?? {}));
  check('incomplete operation counts flow item', Number(incompleteNextQuestion?.evidence?.execution_flow_item_count ?? 0) > 0, JSON.stringify(incompleteNextQuestion?.evidence ?? {}));
  check('incomplete operation has no approved item', Number(incompleteNextQuestion?.evidence?.approved_count ?? 0) === 0, JSON.stringify(incompleteNextQuestion?.evidence ?? {}));
  check('incomplete operation has no execution evidence', Number(incompleteNextQuestion?.evidence?.execution_evidence_count ?? 0) === 0, JSON.stringify(incompleteNextQuestion?.evidence ?? {}));
  check('incomplete operation exposes blocked execution count', Number(incompleteNextQuestion?.evidence?.blocked_execution_count ?? 0) > 0, JSON.stringify(incompleteNextQuestion?.evidence ?? {}));
  check('incomplete operation names incomplete evidence gap', asArray(incompleteNextQuestion?.evidence?.blocking_missing_codes).includes('operation_execution_evidence_incomplete'), JSON.stringify(incompleteNextQuestion?.evidence ?? {}));

  const incompleteActions = asArray(incompleteOperationEvidencePayload.next_actions);
  const operationAction = incompleteActions.find((action) => action?.action_code === 'collect_operation_execution_evidence');
  check('incomplete operation keeps operation evidence action visible', Boolean(operationAction), JSON.stringify(incompleteActions));
  if (operationAction) {
    check('incomplete operation action asks for structured operation evidence', asArray(operationAction.evidence_needed).some((item) => String(item).includes('approval.status=approved')), JSON.stringify(operationAction));
    check('incomplete operation action preserves protected boundary', String(operationAction.protected_boundary ?? '').includes('不能只凭 AI 建议卡片标记闭环完成'), JSON.stringify(operationAction));
  }
  check('incomplete operation keeps closure incomplete', incompleteOperationEvidencePayload.closure_summary?.status === 'incomplete', JSON.stringify(incompleteOperationEvidencePayload.closure_summary ?? {}));
}

if (unlinkedOperationEvidencePayload) {
  check('unlinked operation evidence package stays read-only', unlinkedOperationEvidencePayload.generator?.mode === 'read_only' && unlinkedOperationEvidencePayload.generator?.writes_ota_data === false, JSON.stringify(unlinkedOperationEvidencePayload.generator ?? {}));
  check('unlinked operation evidence package stays OTA scoped', unlinkedOperationEvidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(unlinkedOperationEvidencePayload.scope ?? {}));
  const unlinkedQuestions = asArray(unlinkedOperationEvidencePayload.employee_questions);
  const unlinkedAiQuestion = unlinkedQuestions.find((row) => row?.key === 'ai_evidence');
  const unlinkedNextQuestion = unlinkedQuestions.find((row) => row?.key === 'next_operation_action');
  check('unlinked operation keeps actionable AI evidence proved', unlinkedAiQuestion?.status === 'proved', JSON.stringify(unlinkedAiQuestion ?? {}));
  check('unlinked operation evidence is warning, not proved', unlinkedNextQuestion?.status === 'warning', JSON.stringify(unlinkedNextQuestion ?? {}));
  check('unlinked operation retains raw execution item count', Number(unlinkedNextQuestion?.evidence?.execution_flow_item_count ?? 0) > 0, JSON.stringify(unlinkedNextQuestion?.evidence ?? {}));
  check('unlinked operation has no OTA diagnosis linked flow item', Number(unlinkedNextQuestion?.evidence?.ota_diagnosis_linked_flow_item_count ?? 0) === 0, JSON.stringify(unlinkedNextQuestion?.evidence ?? {}));
  check('unlinked operation does not count generic approval as completion', Number(unlinkedNextQuestion?.evidence?.approved_count ?? 0) === 0, JSON.stringify(unlinkedNextQuestion?.evidence ?? {}));
  check('unlinked operation names AI action link gap', asArray(unlinkedNextQuestion?.evidence?.blocking_missing_codes).includes('operation_execution_ai_action_link_missing'), JSON.stringify(unlinkedNextQuestion?.evidence ?? {}));
  const unlinkedActions = asArray(unlinkedOperationEvidencePayload.next_actions);
  const operationAction = unlinkedActions.find((action) => action?.action_code === 'collect_operation_execution_evidence');
  check('unlinked operation keeps operation evidence action visible', Boolean(operationAction), JSON.stringify(unlinkedActions));
  if (operationAction) {
    check('unlinked operation action asks for OTA diagnosis linkage', asArray(operationAction.evidence_needed).some((item) => String(item).includes('source_module=ota_diagnosis')), JSON.stringify(operationAction));
    check('unlinked operation action preserves acquisition boundary', String(operationAction.protected_boundary ?? '').includes('不改携程/美团采集字段和采集逻辑'), JSON.stringify(operationAction));
    check('unlinked operation action resolves AI action link gap', asArray(operationAction.resolves_missing_codes).includes('operation_execution_ai_action_link_missing'), JSON.stringify(operationAction));
  }
  check('unlinked operation keeps closure incomplete', unlinkedOperationEvidencePayload.closure_summary?.status === 'incomplete', JSON.stringify(unlinkedOperationEvidencePayload.closure_summary ?? {}));
}

if (mismatchedDiagnosisEvidencePayload) {
  check('mismatched diagnosis evidence package stays read-only', mismatchedDiagnosisEvidencePayload.generator?.mode === 'read_only' && mismatchedDiagnosisEvidencePayload.generator?.writes_ota_data === false, JSON.stringify(mismatchedDiagnosisEvidencePayload.generator ?? {}));
  check('mismatched diagnosis evidence package stays OTA scoped', mismatchedDiagnosisEvidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(mismatchedDiagnosisEvidencePayload.scope ?? {}));
  const mismatchDiagnosisQuestions = asArray(mismatchedDiagnosisEvidencePayload.employee_questions);
  const mismatchDiagnosisAiQuestion = mismatchDiagnosisQuestions.find((row) => row?.key === 'ai_evidence');
  const mismatchDiagnosisNextQuestion = mismatchDiagnosisQuestions.find((row) => row?.key === 'next_operation_action');
  check('mismatched diagnosis evidence is visible but not proved', mismatchDiagnosisAiQuestion?.status === 'warning', JSON.stringify(mismatchDiagnosisAiQuestion ?? {}));
  check('mismatched diagnosis exposes scope mismatch', mismatchDiagnosisAiQuestion?.evidence?.scope_date_status === 'mismatch', JSON.stringify(mismatchDiagnosisAiQuestion?.evidence ?? {}));
  check('mismatched diagnosis blocks AI by evidence scope date', asArray(mismatchDiagnosisAiQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(mismatchDiagnosisAiQuestion?.evidence ?? {}));
  check('mismatched diagnosis blocks operation by evidence scope date', asArray(mismatchDiagnosisNextQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(mismatchDiagnosisNextQuestion?.evidence ?? {}));
  const mismatchDiagnosisActions = asArray(mismatchedDiagnosisEvidencePayload.next_actions);
  const alignDiagnosisAction = mismatchDiagnosisActions.find((action) => action?.action_code === 'align_evidence_scope_date');
  check('mismatched diagnosis creates evidence scope alignment action', Boolean(alignDiagnosisAction), JSON.stringify(mismatchDiagnosisActions));
  if (alignDiagnosisAction) {
    check('mismatched diagnosis alignment action resolves scope mismatch', asArray(alignDiagnosisAction.resolves_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(alignDiagnosisAction));
    check('mismatched diagnosis alignment action is linked to employee questions', asArray(alignDiagnosisAction.related_question_keys).includes('ai_evidence') && asArray(alignDiagnosisAction.related_question_keys).includes('next_operation_action'), JSON.stringify(alignDiagnosisAction));
  }
  check('mismatched diagnosis keeps closure incomplete', mismatchedDiagnosisEvidencePayload.closure_summary?.status === 'incomplete', JSON.stringify(mismatchedDiagnosisEvidencePayload.closure_summary ?? {}));
}

if (mismatchedOperationEvidencePayload) {
  check('mismatched operation evidence package stays read-only', mismatchedOperationEvidencePayload.generator?.mode === 'read_only' && mismatchedOperationEvidencePayload.generator?.writes_ota_data === false, JSON.stringify(mismatchedOperationEvidencePayload.generator ?? {}));
  check('mismatched operation evidence package stays OTA scoped', mismatchedOperationEvidencePayload.scope?.metric_scope === 'ota_channel', JSON.stringify(mismatchedOperationEvidencePayload.scope ?? {}));
  const mismatchOperationQuestions = asArray(mismatchedOperationEvidencePayload.employee_questions);
  const mismatchOperationAiQuestion = mismatchOperationQuestions.find((row) => row?.key === 'ai_evidence');
  const mismatchOperationNextQuestion = mismatchOperationQuestions.find((row) => row?.key === 'next_operation_action');
  check('mismatched operation keeps actionable AI evidence proved', mismatchOperationAiQuestion?.status === 'proved', JSON.stringify(mismatchOperationAiQuestion ?? {}));
  check('mismatched operation evidence is warning, not proved', mismatchOperationNextQuestion?.status === 'warning', JSON.stringify(mismatchOperationNextQuestion ?? {}));
  check('mismatched operation exposes scope mismatch', mismatchOperationNextQuestion?.evidence?.scope_date_status === 'mismatch', JSON.stringify(mismatchOperationNextQuestion?.evidence ?? {}));
  check('mismatched operation names scope mismatch blocker', asArray(mismatchOperationNextQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(mismatchOperationNextQuestion?.evidence ?? {}));
  const mismatchOperationActions = asArray(mismatchedOperationEvidencePayload.next_actions);
  const alignOperationAction = mismatchOperationActions.find((action) => action?.action_code === 'align_evidence_scope_date');
  check('mismatched operation creates evidence scope alignment action', Boolean(alignOperationAction), JSON.stringify(mismatchOperationActions));
  check('mismatched operation keeps closure incomplete', mismatchedOperationEvidencePayload.closure_summary?.status === 'incomplete', JSON.stringify(mismatchedOperationEvidencePayload.closure_summary ?? {}));
}

if (mismatchedDiagnosisInspectionPayload) {
  const inspectedQuestions = asArray(mismatchedDiagnosisInspectionPayload.employee_questions);
  const inspectedAiQuestion = inspectedQuestions.find((row) => row?.key === 'ai_evidence');
  const inspectedNextQuestion = inspectedQuestions.find((row) => row?.key === 'next_operation_action');
  check('inspector keeps mismatched diagnosis AI evidence unproved', inspectedAiQuestion?.status === 'warning', JSON.stringify(inspectedAiQuestion ?? {}));
  check('inspector exposes mismatched diagnosis scope blocker', asArray(inspectedAiQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(inspectedAiQuestion?.evidence ?? {}));
  check('inspector keeps mismatched diagnosis operation blocked by scope', asArray(inspectedNextQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(inspectedNextQuestion?.evidence ?? {}));
  check('inspector keeps mismatched diagnosis closure incomplete', mismatchedDiagnosisInspectionPayload.closure_summary?.status === 'incomplete', JSON.stringify(mismatchedDiagnosisInspectionPayload.closure_summary ?? {}));
}

if (mismatchedOperationInspectionPayload) {
  const inspectedQuestions = asArray(mismatchedOperationInspectionPayload.employee_questions);
  const inspectedAiQuestion = inspectedQuestions.find((row) => row?.key === 'ai_evidence');
  const inspectedNextQuestion = inspectedQuestions.find((row) => row?.key === 'next_operation_action');
  check('inspector keeps matched diagnosis AI evidence proved for mismatched operation package', inspectedAiQuestion?.status === 'proved', JSON.stringify(inspectedAiQuestion ?? {}));
  check('inspector keeps mismatched operation evidence unproved', inspectedNextQuestion?.status === 'warning', JSON.stringify(inspectedNextQuestion ?? {}));
  check('inspector exposes mismatched operation scope blocker', asArray(inspectedNextQuestion?.evidence?.blocking_missing_codes).includes('evidence_scope_date_mismatch'), JSON.stringify(inspectedNextQuestion?.evidence ?? {}));
  check('inspector keeps mismatched operation closure incomplete', mismatchedOperationInspectionPayload.closure_summary?.status === 'incomplete', JSON.stringify(mismatchedOperationInspectionPayload.closure_summary ?? {}));
}

if (unlinkedOperationInspectionPayload) {
  const inspectedQuestions = asArray(unlinkedOperationInspectionPayload.employee_questions);
  const inspectedNextQuestion = inspectedQuestions.find((row) => row?.key === 'next_operation_action');
  check('inspector keeps unlinked operation evidence unproved', inspectedNextQuestion?.status === 'warning', JSON.stringify(inspectedNextQuestion ?? {}));
  check('inspector exposes unlinked operation blocker', asArray(inspectedNextQuestion?.evidence?.blocking_missing_codes).includes('operation_execution_ai_action_link_missing'), JSON.stringify(inspectedNextQuestion?.evidence ?? {}));
  check('inspector keeps generic operation approvals out of completion counts', Number(inspectedNextQuestion?.evidence?.approved_count ?? 0) === 0, JSON.stringify(inspectedNextQuestion?.evidence ?? {}));
  check('inspector keeps unlinked operation closure incomplete', unlinkedOperationInspectionPayload.closure_summary?.status === 'incomplete', JSON.stringify(unlinkedOperationInspectionPayload.closure_summary ?? {}));
}

if (evidencePayload) {
  for (const row of asArray(evidencePayload.employee_questions)) {
    const key = String(row?.key ?? row?.question ?? 'unknown');
    const rawNextAction = String(row?.next_action ?? '');
    const employeeNextAction = String(row?.employee_next_action ?? '');
    const employeeDetail = String(row?.employee_detail ?? '');
    if (rawNextAction.trim() !== '') {
      check(`evidence ${key} employee next action exists beside raw next_action`, employeeNextAction.trim() !== '', JSON.stringify(row));
    }
    check(`evidence ${key} employee detail exists`, employeeDetail.trim() !== '', JSON.stringify(row));
    check(`evidence ${key} employee next action avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeNextAction), employeeNextAction);
    check(`evidence ${key} employee detail avoids technical evidence names`, !hasTechnicalEmployeeCopy(employeeDetail), employeeDetail);
    if (key === 'ai_evidence' && String(row?.status ?? '') !== 'proved') {
      check('evidence package AI row exposes diagnosis status', String(row?.evidence?.diagnosis_status ?? '').length > 0, JSON.stringify(row?.evidence ?? {}));
      check('evidence package AI row exposes action item status', String(row?.evidence?.action_item_status ?? '').length > 0, JSON.stringify(row?.evidence ?? {}));
      check('evidence package AI row exposes source policy', String(row?.evidence?.source_policy ?? '').length > 0, JSON.stringify(row?.evidence ?? {}));
    }
    if (key === 'next_operation_action' && String(row?.status ?? '') !== 'proved') {
      check('evidence package operation row exposes execution source policy', String(row?.evidence?.source_policy ?? '') === 'read_existing_operation_execution_state_only', JSON.stringify(row?.evidence ?? {}));
      check('evidence package operation row exposes completion signal count', Number.isFinite(Number(row?.evidence?.completion_signal_count)), JSON.stringify(row?.evidence ?? {}));
      check('evidence package operation row does not expose raw payload', row?.evidence?.raw_data_exposed === false || row?.evidence?.raw_data_exposed === undefined, JSON.stringify(row?.evidence ?? {}));
    }
  }
}

if (payload && evidencePayload) {
  const inspectorQuestions = asArray(payload.employee_questions);
  const evidenceQuestions = asArray(evidencePayload.employee_questions);
  const inspectorToday = inspectorQuestions.find((row) => row.question === '今天 OTA 数据有没有采到');
  const evidenceToday = evidenceQuestions.find((row) => row.question === '今天 OTA 数据有没有采到');
  const inspectorTrustedFields = inspectorQuestions.find((row) => row?.key === 'trusted_fields');
  const evidenceTrustedFields = evidenceQuestions.find((row) => row?.key === 'trusted_fields');
  const inspectorMissingFields = inspectorQuestions.find((row) => row?.key === 'missing_fields');
  const evidenceMissingFields = evidenceQuestions.find((row) => row?.key === 'missing_fields');
  const inspectorMetricQuestion = inspectorQuestions.find((row) => row?.key === 'revenue_traffic_conversion');
  const evidenceMetricQuestion = evidenceQuestions.find((row) => row?.key === 'revenue_traffic_conversion');
  const inspectorSummary = payload.closure_summary ?? {};
  const evidenceSummary = evidencePayload.closure_summary ?? {};
  const platformCoverageDigest = (rows) => asArray(rows)
    .map((row) => {
      const latestAvailable = row?.latest_available ?? {};
      return {
        platform: String(row?.platform ?? '').toLowerCase(),
        rows: Number(row?.source_rows ?? row?.target_date_rows ?? 0),
        latest_date: String(latestAvailable?.date ?? row?.latest_available_date ?? ''),
        latest_relation: String(latestAvailable?.date_relation ?? row?.latest_available_date_relation ?? ''),
      };
    })
    .sort((left, right) => left.platform.localeCompare(right.platform));
  const platformFieldTrustDigest = (rows) => asArray(rows)
    .map((row) => ({
      platform: String(row?.platform ?? '').toLowerCase(),
      rows: Number(row?.target_date_rows ?? 0),
      status: String(row?.field_trust_status ?? ''),
      key_count: Number(row?.metric_trust_key_count ?? 0),
    }))
    .sort((left, right) => left.platform.localeCompare(right.platform));

  check('cross-output scope date matches', payload.scope?.date === evidencePayload.scope?.date, `${payload.scope?.date} vs ${evidencePayload.scope?.date}`);
  check('cross-output platform list matches', sameStringList(payload.scope?.platforms, evidencePayload.scope?.platforms), `${JSON.stringify(payload.scope?.platforms)} vs ${JSON.stringify(evidencePayload.scope?.platforms)}`);
  check('cross-output metric scope matches', payload.scope?.metric_scope === evidencePayload.scope?.metric_scope, `${payload.scope?.metric_scope} vs ${evidencePayload.scope?.metric_scope}`);
  check('cross-output collection coverage matches', inspectorToday?.evidence?.coverage_status === evidencePayload.collection_reliability?.coverage_status, `${inspectorToday?.evidence?.coverage_status} vs ${evidencePayload.collection_reliability?.coverage_status}`);
  check('cross-output missing platforms match', sameStringList(inspectorToday?.evidence?.missing_platforms, evidencePayload.collection_reliability?.missing_platforms), `${JSON.stringify(inspectorToday?.evidence?.missing_platforms)} vs ${JSON.stringify(evidencePayload.collection_reliability?.missing_platforms)}`);
  check('cross-output collection source summary matches', JSON.stringify(collectionSourceSummaryDigest(payload.collection_source_summary)) === JSON.stringify(collectionSourceSummaryDigest(evidencePayload.collection_source_summary)), `${JSON.stringify(collectionSourceSummaryDigest(payload.collection_source_summary))} vs ${JSON.stringify(collectionSourceSummaryDigest(evidencePayload.collection_source_summary))}`);
  check('cross-output employee platform coverage details match', JSON.stringify(platformCoverageDigest(inspectorToday?.evidence?.platforms)) === JSON.stringify(platformCoverageDigest(evidenceToday?.evidence?.platforms)), `${JSON.stringify(platformCoverageDigest(inspectorToday?.evidence?.platforms))} vs ${JSON.stringify(platformCoverageDigest(evidenceToday?.evidence?.platforms))}`);
  check('cross-output employee question statuses match', JSON.stringify(questionStatusMap(inspectorQuestions)) === JSON.stringify(questionStatusMap(evidenceQuestions)), `${JSON.stringify(questionStatusMap(inspectorQuestions))} vs ${JSON.stringify(questionStatusMap(evidenceQuestions))}`);
  check('cross-output employee question details match', JSON.stringify(questionStringFieldMap(inspectorQuestions, 'employee_detail')) === JSON.stringify(questionStringFieldMap(evidenceQuestions, 'employee_detail')), `${JSON.stringify(questionStringFieldMap(inspectorQuestions, 'employee_detail'))} vs ${JSON.stringify(questionStringFieldMap(evidenceQuestions, 'employee_detail'))}`);
  check('cross-output employee question next actions match', JSON.stringify(questionStringFieldMap(inspectorQuestions, 'employee_next_action')) === JSON.stringify(questionStringFieldMap(evidenceQuestions, 'employee_next_action')), `${JSON.stringify(questionStringFieldMap(inspectorQuestions, 'employee_next_action'))} vs ${JSON.stringify(questionStringFieldMap(evidenceQuestions, 'employee_next_action'))}`);
  check('cross-output employee question action codes match', JSON.stringify(questionActionCodeMap(inspectorQuestions)) === JSON.stringify(questionActionCodeMap(evidenceQuestions)), `${JSON.stringify(questionActionCodeMap(inspectorQuestions))} vs ${JSON.stringify(questionActionCodeMap(evidenceQuestions))}`);
  check('cross-output employee question action summaries match', JSON.stringify(questionActionSummaryMap(inspectorQuestions)) === JSON.stringify(questionActionSummaryMap(evidenceQuestions)), `${JSON.stringify(questionActionSummaryMap(inspectorQuestions))} vs ${JSON.stringify(questionActionSummaryMap(evidenceQuestions))}`);
  check('cross-output metric_trust_keys match', sameStringList(inspectorTrustedFields?.evidence?.metric_trust_keys, evidenceTrustedFields?.evidence?.metric_trust_keys), `${JSON.stringify(inspectorTrustedFields?.evidence?.metric_trust_keys)} vs ${JSON.stringify(evidenceTrustedFields?.evidence?.metric_trust_keys)}`);
  check('cross-output platform field trust details match', JSON.stringify(platformFieldTrustDigest(inspectorTrustedFields?.evidence?.platform_field_trust)) === JSON.stringify(platformFieldTrustDigest(evidenceTrustedFields?.evidence?.platform_field_trust)), `${JSON.stringify(platformFieldTrustDigest(inspectorTrustedFields?.evidence?.platform_field_trust))} vs ${JSON.stringify(platformFieldTrustDigest(evidenceTrustedFields?.evidence?.platform_field_trust))}`);
  check('cross-output data_gap_codes match', sameStringList(inspectorMissingFields?.evidence?.data_gap_codes, evidenceMissingFields?.evidence?.data_gap_codes), `${JSON.stringify(inspectorMissingFields?.evidence?.data_gap_codes)} vs ${JSON.stringify(evidenceMissingFields?.evidence?.data_gap_codes)}`);
  check('cross-output missing field summaries match', JSON.stringify(missingFieldSummaryDigest(inspectorMissingFields)) === JSON.stringify(missingFieldSummaryDigest(evidenceMissingFields)), `${JSON.stringify(missingFieldSummaryDigest(inspectorMissingFields))} vs ${JSON.stringify(missingFieldSummaryDigest(evidenceMissingFields))}`);
  check('cross-output metric domain summaries match', JSON.stringify(metricDomainSummaryDigest(inspectorMetricQuestion)) === JSON.stringify(metricDomainSummaryDigest(evidenceMetricQuestion)), `${JSON.stringify(metricDomainSummaryDigest(inspectorMetricQuestion))} vs ${JSON.stringify(metricDomainSummaryDigest(evidenceMetricQuestion))}`);
  check('cross-output metric domain gap codes match', sameStringList(inspectorMetricQuestion?.evidence?.metric_domain_gap_codes, evidenceMetricQuestion?.evidence?.metric_domain_gap_codes), `${JSON.stringify(inspectorMetricQuestion?.evidence?.metric_domain_gap_codes)} vs ${JSON.stringify(evidenceMetricQuestion?.evidence?.metric_domain_gap_codes)}`);
  check('cross-output traffic missing platforms match', sameStringList(inspectorMetricQuestion?.evidence?.traffic_missing_platforms, evidenceMetricQuestion?.evidence?.traffic_missing_platforms), `${JSON.stringify(inspectorMetricQuestion?.evidence?.traffic_missing_platforms)} vs ${JSON.stringify(evidenceMetricQuestion?.evidence?.traffic_missing_platforms)}`);
  check('cross-output next action code order matches', JSON.stringify(actionCodeList(payload.next_actions)) === JSON.stringify(actionCodeList(evidencePayload.next_actions)), `${JSON.stringify(actionCodeList(payload.next_actions))} vs ${JSON.stringify(actionCodeList(evidencePayload.next_actions))}`);
  check('cross-output next action platform order matches', JSON.stringify(actionStringFieldList(payload.next_actions, 'platform')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'platform')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'platform'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'platform'))}`);
  check('cross-output next action family order matches', JSON.stringify(actionFamilyList(payload.next_actions)) === JSON.stringify(actionFamilyList(evidencePayload.next_actions)), `${JSON.stringify(actionFamilyList(payload.next_actions))} vs ${JSON.stringify(actionFamilyList(evidencePayload.next_actions))}`);
  check('cross-output next action entry order matches', JSON.stringify(actionEntryList(payload.next_actions)) === JSON.stringify(actionEntryList(evidencePayload.next_actions)), `${JSON.stringify(actionEntryList(payload.next_actions))} vs ${JSON.stringify(actionEntryList(evidencePayload.next_actions))}`);
  check('cross-output next action success criteria order matches', JSON.stringify(actionSuccessCriteriaList(payload.next_actions)) === JSON.stringify(actionSuccessCriteriaList(evidencePayload.next_actions)), `${JSON.stringify(actionSuccessCriteriaList(payload.next_actions))} vs ${JSON.stringify(actionSuccessCriteriaList(evidencePayload.next_actions))}`);
  check('cross-output next action resolved missing codes match', JSON.stringify(actionResolvesMissingCodesList(payload.next_actions)) === JSON.stringify(actionResolvesMissingCodesList(evidencePayload.next_actions)), `${JSON.stringify(actionResolvesMissingCodesList(payload.next_actions))} vs ${JSON.stringify(actionResolvesMissingCodesList(evidencePayload.next_actions))}`);
  check('cross-output next action live closure gap codes match', JSON.stringify(actionArrayFieldList(payload.next_actions, 'live_closure_gap_codes')) === JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'live_closure_gap_codes')), `${JSON.stringify(actionArrayFieldList(payload.next_actions, 'live_closure_gap_codes'))} vs ${JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'live_closure_gap_codes'))}`);
  check('cross-output next action employee explanations match', JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_explanation')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_explanation')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_explanation'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_explanation'))}`);
  check('cross-output next action employee actions match', JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_action')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_action')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_action'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_action'))}`);
  check('cross-output next action employee evidence needed match', JSON.stringify(actionArrayFieldList(payload.next_actions, 'employee_evidence_needed')) === JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'employee_evidence_needed')), `${JSON.stringify(actionArrayFieldList(payload.next_actions, 'employee_evidence_needed'))} vs ${JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'employee_evidence_needed'))}`);
  check('cross-output next action employee verification steps match', JSON.stringify(actionArrayFieldList(payload.next_actions, 'employee_verification_steps')) === JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'employee_verification_steps')), `${JSON.stringify(actionArrayFieldList(payload.next_actions, 'employee_verification_steps'))} vs ${JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'employee_verification_steps'))}`);
  check('cross-output next action employee success criteria match', JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_success_criteria')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_success_criteria')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_success_criteria'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_success_criteria'))}`);
  check('cross-output next action employee explanation next actions match', JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_explanation_next_action')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_explanation_next_action')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'employee_explanation_next_action'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'employee_explanation_next_action'))}`);
  check('cross-output next action limited conclusions match', JSON.stringify(actionArrayFieldList(payload.next_actions, 'limited_conclusions')) === JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'limited_conclusions')), `${JSON.stringify(actionArrayFieldList(payload.next_actions, 'limited_conclusions'))} vs ${JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'limited_conclusions'))}`);
  check('cross-output next action still usable metrics match', JSON.stringify(actionArrayFieldList(payload.next_actions, 'still_usable_metrics')) === JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'still_usable_metrics')), `${JSON.stringify(actionArrayFieldList(payload.next_actions, 'still_usable_metrics'))} vs ${JSON.stringify(actionArrayFieldList(evidencePayload.next_actions, 'still_usable_metrics'))}`);
  check('cross-output next action explanation next actions match', JSON.stringify(actionStringFieldList(payload.next_actions, 'explanation_next_action')) === JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'explanation_next_action')), `${JSON.stringify(actionStringFieldList(payload.next_actions, 'explanation_next_action'))} vs ${JSON.stringify(actionStringFieldList(evidencePayload.next_actions, 'explanation_next_action'))}`);
  check('cross-output next action blocker resolver actions match', JSON.stringify(actionBlockedByActionCodesList(payload.next_actions)) === JSON.stringify(actionBlockedByActionCodesList(evidencePayload.next_actions)), `${JSON.stringify(actionBlockedByActionCodesList(payload.next_actions))} vs ${JSON.stringify(actionBlockedByActionCodesList(evidencePayload.next_actions))}`);
  check('cross-output closure summary status matches', inspectorSummary.status === evidenceSummary.status, `${inspectorSummary.status} vs ${evidenceSummary.status}`);
  check('cross-output closure summary proved count matches', inspectorSummary.proved_count === evidenceSummary.proved_count, `${inspectorSummary.proved_count} vs ${evidenceSummary.proved_count}`);
  check('cross-output closure summary missing count matches', inspectorSummary.missing_count === evidenceSummary.missing_count, `${inspectorSummary.missing_count} vs ${evidenceSummary.missing_count}`);
  check('cross-output closure summary top action matches', String(inspectorSummary.top_action_code ?? '') === String(evidenceSummary.top_action_code ?? ''), `${inspectorSummary.top_action_code ?? ''} vs ${evidenceSummary.top_action_code ?? ''}`);
  check('cross-output closure summary top action entry matches', String(inspectorSummary.top_action_entry ?? '') === String(evidenceSummary.top_action_entry ?? ''), `${inspectorSummary.top_action_entry ?? ''} vs ${evidenceSummary.top_action_entry ?? ''}`);
  check('cross-output closure summary top action entry options match', JSON.stringify(asArray(inspectorSummary.top_action_entry_options)) === JSON.stringify(asArray(evidenceSummary.top_action_entry_options)), `${JSON.stringify(asArray(inspectorSummary.top_action_entry_options))} vs ${JSON.stringify(asArray(evidenceSummary.top_action_entry_options))}`);
  check('cross-output closure summary top action impact matches', JSON.stringify(asArray(inspectorSummary.top_action_related_question_keys)) === JSON.stringify(asArray(evidenceSummary.top_action_related_question_keys)), `${JSON.stringify(asArray(inspectorSummary.top_action_related_question_keys))} vs ${JSON.stringify(asArray(evidenceSummary.top_action_related_question_keys))}`);
  check('cross-output closure summary top action resolved gaps match', JSON.stringify(asArray(inspectorSummary.top_action_resolves_missing_codes)) === JSON.stringify(asArray(evidenceSummary.top_action_resolves_missing_codes)), `${JSON.stringify(asArray(inspectorSummary.top_action_resolves_missing_codes))} vs ${JSON.stringify(asArray(evidenceSummary.top_action_resolves_missing_codes))}`);
  check('cross-output closure summary top action live gaps match', JSON.stringify(asArray(inspectorSummary.top_action_live_closure_gap_codes)) === JSON.stringify(asArray(evidenceSummary.top_action_live_closure_gap_codes)), `${JSON.stringify(asArray(inspectorSummary.top_action_live_closure_gap_codes))} vs ${JSON.stringify(asArray(evidenceSummary.top_action_live_closure_gap_codes))}`);
  check('cross-output closure summary top action source snapshot matches', JSON.stringify(inspectorSummary.top_action_source_snapshot ?? {}) === JSON.stringify(evidenceSummary.top_action_source_snapshot ?? {}), `${JSON.stringify(inspectorSummary.top_action_source_snapshot ?? {})} vs ${JSON.stringify(evidenceSummary.top_action_source_snapshot ?? {})}`);
  check('cross-output protected boundary is preserved', String(inspectorSummary.protected_boundary ?? '').includes('不改变携程/美团手动或自动获取逻辑') && String(evidenceSummary.protected_boundary ?? '').includes('不改变携程/美团手动或自动获取逻辑'));
}

const failures = checks.filter((item) => !item.ok);
if (failures.length > 0) {
  console.error('Phase 1 live action queue runtime verification failed:');
  for (const failure of failures) {
    console.error(`- ${failure.label}`);
    if (failure.detail) console.error(`  ${failure.detail}`);
  }
  process.exit(1);
}

console.log(`[verify:phase1-live-action-queue] ${checks.length} checks passed for ${options.date}`);
