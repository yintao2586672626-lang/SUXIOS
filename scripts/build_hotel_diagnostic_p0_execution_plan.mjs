import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const auditDate = '2026-07-15';
const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');
const qaDir = path.join(projectRoot, 'docs', 'qa');
const matrixPath = path.join(qaDir, `hotel_system_4000_diagnostic_cases_${auditDate}.jsonl`);
const ledgerPath = path.join(qaDir, `hotel_system_4000_execution_evidence_${auditDate}.json`);
const jsonPath = path.join(qaDir, `hotel_system_p0_2688_execution_plan_${auditDate}.json`);
const markdownPath = path.join(qaDir, `hotel_system_p0_2688_execution_plan_${auditDate}.md`);

const executionWaves = [
  {
    id: 'wave_a_automated_integration',
    executionType: 'automated_integration',
    label: '本地自动化集成',
    evidenceBoundary: '逐场景服务、控制器、数据库或隔离 API 运行证据；未覆盖的 HTTP/UI/真实 OTA 层必须继续标注。',
  },
  {
    id: 'wave_b_contract_plus_runtime',
    executionType: 'contract_plus_runtime',
    label: '合同加运行时验证',
    evidenceBoundary: '静态合同只能证明结构；必须补一次对应运行时、进程、浏览器或接口证据后才能提升状态。',
  },
  {
    id: 'wave_c_manual_or_external',
    executionType: 'manual_or_external_integration',
    label: '人工或外部集成',
    evidenceBoundary: '只在授权账号、目标酒店和可清理数据范围内执行；缺少真实 OTA/第三方条件时保持 blocked 或 not_executed。',
  },
];

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, 'utf8'));
}

function readMatrix() {
  if (!existsSync(matrixPath)) {
    throw new Error(`Diagnostic matrix is missing: ${matrixPath}`);
  }

  return readFileSync(matrixPath, 'utf8')
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line, index) => {
      try {
        return JSON.parse(line);
      } catch (error) {
        throw new Error(`Invalid JSONL at line ${index + 1}: ${error.message}`);
      }
    });
}

function readEvidence() {
  if (!existsSync(ledgerPath)) {
    return [];
  }

  const document = readJson(ledgerPath);
  if (!Array.isArray(document.records)) {
    throw new Error('Execution evidence ledger must contain a records array.');
  }
  return document.records;
}

function countBy(rows, selector) {
  const counts = new Map();
  for (const row of rows) {
    const key = selector(row);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return counts;
}

function statusFor(testCase, evidenceByCaseId) {
  const evidence = evidenceByCaseId.get(testCase.id);
  if (!evidence) {
    return 'not_executed';
  }
  if (evidence.scenario_signature !== testCase.scenario_signature) {
    throw new Error(`Evidence signature mismatch for ${testCase.id}.`);
  }
  return evidence.status;
}

function statusCounts(rows, evidenceByCaseId) {
  const counts = countBy(rows, (row) => statusFor(row, evidenceByCaseId));
  return Object.fromEntries([...counts.entries()].sort(([left], [right]) => left.localeCompare(right)));
}

function uniqueBaseCases(rows) {
  return [...new Set(rows.map((row) => row.base_case_id))].sort();
}

const matrix = readMatrix();
const p0Cases = matrix.filter((testCase) => testCase.priority === 'P0');
const p0GapCases = p0Cases.filter((testCase) => testCase.base_assessment_status !== '满足');
const evidence = readEvidence();
const matrixById = new Map(matrix.map((testCase) => [testCase.id, testCase]));
const evidenceByCaseId = new Map();

for (const record of evidence) {
  if (evidenceByCaseId.has(record.case_id)) {
    throw new Error(`Duplicate execution evidence for ${record.case_id}.`);
  }
  const testCase = matrixById.get(record.case_id);
  if (!testCase) {
    throw new Error(`Execution evidence references unknown case ${record.case_id}.`);
  }
  if (record.scenario_signature !== testCase.scenario_signature) {
    throw new Error(`Evidence signature mismatch for ${record.case_id}.`);
  }
  evidenceByCaseId.set(record.case_id, record);
}

const p0BaseCases = uniqueBaseCases(p0Cases);
for (const baseCaseId of p0BaseCases) {
  const variants = p0Cases.filter((testCase) => testCase.base_case_id === baseCaseId);
  if (variants.length !== 8) {
    throw new Error(`${baseCaseId} must have exactly 8 P0 L8 variants; found ${variants.length}.`);
  }
}

const waves = executionWaves.map((wave) => {
  const cases = p0Cases.filter((testCase) => testCase.execution_type === wave.executionType);
  const baseCaseIds = uniqueBaseCases(cases);
  const counts = statusCounts(cases, evidenceByCaseId);
  const evidenced = cases.length - (counts.not_executed ?? 0);

  return {
    id: wave.id,
    label: wave.label,
    execution_type: wave.executionType,
    base_case_count: baseCaseIds.length,
    variant_target: cases.length,
    evidenced_variants: evidenced,
    pending_variants: cases.length - evidenced,
    status_counts: counts,
    evidence_boundary: wave.evidenceBoundary,
    base_case_ids: baseCaseIds,
  };
});

const categoryGroups = new Map();
for (const testCase of p0Cases) {
  const key = `${testCase.category_code}|${testCase.category_name}`;
  if (!categoryGroups.has(key)) {
    categoryGroups.set(key, []);
  }
  categoryGroups.get(key).push(testCase);
}

const categories = [...categoryGroups.entries()]
  .map(([key, cases]) => {
    const [categoryCode, categoryName] = key.split('|');
    const counts = statusCounts(cases, evidenceByCaseId);
    const evidenced = cases.length - (counts.not_executed ?? 0);
    return {
      category_code: categoryCode,
      category_name: categoryName,
      base_case_count: uniqueBaseCases(cases).length,
      variant_target: cases.length,
      evidenced_variants: evidenced,
      pending_variants: cases.length - evidenced,
      status_counts: counts,
    };
  })
  .sort((left, right) => right.variant_target - left.variant_target || left.category_code.localeCompare(right.category_code));

const p0Statuses = statusCounts(p0Cases, evidenceByCaseId);
const p0Evidenced = p0Cases.length - (p0Statuses.not_executed ?? 0);
const localWaves = waves.filter((wave) => wave.execution_type !== 'manual_or_external_integration');
const localTarget = localWaves.reduce((sum, wave) => sum + wave.variant_target, 0);
const externalTarget = p0Cases.length - localTarget;
const localGapCases = p0GapCases.filter((testCase) => testCase.execution_type !== 'manual_or_external_integration');
const externalGapCases = p0GapCases.filter((testCase) => testCase.execution_type === 'manual_or_external_integration');
const gapStatuses = statusCounts(p0GapCases, evidenceByCaseId);
const gapEvidenced = p0GapCases.length - (gapStatuses.not_executed ?? 0);

const plan = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  audit_date: auditDate,
  source_matrix: path.relative(projectRoot, matrixPath).replaceAll('\\', '/'),
  source_evidence_ledger: path.relative(projectRoot, ledgerPath).replaceAll('\\', '/'),
  target: {
    priority: 'P0',
    base_case_count: p0BaseCases.length,
    l8_variants_per_base_case: 8,
    variant_target: p0Cases.length,
    local_executable_target: localTarget,
    external_or_manual_target: externalTarget,
    evidenced_variants: p0Evidenced,
    pending_variants: p0Cases.length - p0Evidenced,
    status_counts: p0Statuses,
    gap_first: {
      base_case_count: uniqueBaseCases(p0GapCases).length,
      variant_target: p0GapCases.length,
      local_executable_target: localGapCases.length,
      external_or_manual_target: externalGapCases.length,
      evidenced_variants: gapEvidenced,
      pending_variants: p0GapCases.length - gapEvidenced,
      status_counts: gapStatuses,
    },
  },
  waves,
  categories,
  evidence_rules: [
    '500 条基线评估不得传播为 L8 变体执行结果。',
    '每条执行证据必须同时匹配 case_id 与 scenario_signature。',
    '服务层或 PHPUnit 证据没有覆盖 HTTP、数据库、UI 或真实 OTA 时只能标记 partial。',
    '缺少授权账号、真实载荷或外部平台条件时必须保持 blocked 或 not_executed，不得用默认值、模拟值或旧数据冒充。',
    '同一场景重复运行只更新同一账本记录，不通过重复计数扩大完成量。',
  ],
  stop_condition: '完成 2,688 条 P0 L8 直接证据闭环；其中 2,432 条本地可执行变体完成自动化/运行时验证，256 条外部变体取得授权证据或被逐条诚实登记为外部阻塞。',
};

const markdown = `# 宿析OS P0 2,688 条 L8 执行计划

日期：${auditDate}（Asia/Shanghai）

## 结论

“从 500 条增加到 2,000–3,000 条”有意义，但目标不应是重复测试数量。当前 500 条基线中有 **${p0BaseCases.length} 条 P0**；每条按权限范围、数据完整性、新鲜度、上游状态执行 NIST L8，恰好形成 **${p0Cases.length.toLocaleString('en-US')} 条 P0 变体**。这就是本轮长任务的明确上限，不把 P1/P2 或重复运行混入完成量。

- 本地可执行目标：**${localTarget.toLocaleString('en-US')} 条**（自动化集成 + 合同/运行时）。
- 人工或外部集成：**${externalTarget.toLocaleString('en-US')} 条**（需要授权账号、真实 OTA 载荷或外部环境）。
- 缺口优先队列：**${uniqueBaseCases(p0GapCases).length} 个未满足/部分满足/未验证 P0，共 ${p0GapCases.length.toLocaleString('en-US')} 条**；先执行其中 ${localGapCases.length.toLocaleString('en-US')} 条本地场景，再处理 ${externalGapCases.length.toLocaleString('en-US')} 条外部证据场景。
- 当前已有直接证据：**${p0Evidenced.toLocaleString('en-US')} 条**；其余 **${(p0Cases.length - p0Evidenced).toLocaleString('en-US')} 条**仍未执行。
- 当前证据状态：${Object.entries(p0Statuses).map(([status, count]) => `\`${status}\` ${count.toLocaleString('en-US')}`).join('、')}。

## 三个执行波次

| 波次 | 基础场景 | L8 目标 | 已有证据 | 待执行 | 证据边界 |
|---|---:|---:|---:|---:|---|
${waves.map((wave) => `| ${wave.label} | ${wave.base_case_count} | ${wave.variant_target.toLocaleString('en-US')} | ${wave.evidenced_variants.toLocaleString('en-US')} | ${wave.pending_variants.toLocaleString('en-US')} | ${wave.evidence_boundary} |`).join('\n')}

## P0 类别清单

| 类别 | 基础场景 | L8 目标 | 已有证据 | 待执行 |
|---|---:|---:|---:|---:|
${categories.map((category) => `| ${category.category_name} | ${category.base_case_count} | ${category.variant_target.toLocaleString('en-US')} | ${category.evidenced_variants.toLocaleString('en-US')} | ${category.pending_variants.toLocaleString('en-US')} |`).join('\n')}

## 证据规则

${plan.evidence_rules.map((rule) => `- ${rule}`).join('\n')}

## 停止条件

${plan.stop_condition}
`;

writeFileSync(jsonPath, `${JSON.stringify(plan, null, 2)}\n`, 'utf8');
writeFileSync(markdownPath, markdown, 'utf8');

console.log(JSON.stringify({
  target: plan.target,
  outputs: [
    path.relative(projectRoot, jsonPath).replaceAll('\\', '/'),
    path.relative(projectRoot, markdownPath).replaceAll('\\', '/'),
  ],
}, null, 2));
