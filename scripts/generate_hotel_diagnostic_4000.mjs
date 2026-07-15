import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(scriptDir, '..');
const qaDir = path.join(projectRoot, 'docs', 'qa');
const auditDate = '2026-07-15';
const sourcePath = path.join(qaDir, 'hotel_system_500_test_cases_2026-07-14.json');

const sourceAudit = JSON.parse(readFileSync(sourcePath, 'utf8'));
const baseCases = sourceAudit.test_cases;

if (!Array.isArray(baseCases) || baseCases.length !== 500) {
  throw new Error(`Expected 500 source cases, received ${baseCases?.length ?? 'invalid input'}`);
}

// L8 orthogonal array for four binary factors. Every pair of factors covers all
// four value combinations, while keeping the suite materially smaller than the
// full 2^4 cross-product for every source case.
const coverageRows = [
  ['authorized', 'complete', 'fresh', 'success'],
  ['authorized', 'complete', 'stale', 'failure'],
  ['authorized', 'missing_required', 'fresh', 'failure'],
  ['authorized', 'missing_required', 'stale', 'success'],
  ['restricted', 'complete', 'fresh', 'failure'],
  ['restricted', 'complete', 'stale', 'success'],
  ['restricted', 'missing_required', 'fresh', 'success'],
  ['restricted', 'missing_required', 'stale', 'failure'],
].map(([actorScope, dataCompleteness, freshness, upstreamState], index) => ({
  variant: index + 1,
  actor_scope: actorScope,
  data_completeness: dataCompleteness,
  freshness,
  upstream_state: upstreamState,
}));

const factorNames = ['actor_scope', 'data_completeness', 'freshness', 'upstream_state'];
const baseResultMap = {
  满足: 'pass',
  部分满足: 'partial',
  不满足: 'fail',
  未验证: 'blocked',
  不适用: 'not_applicable',
};

function classifyBaseEvidence(testCase) {
  const basis = testCase.assessment_basis ?? '';
  if (/(自动化|最新执行|本机证据|运行检查)/u.test(basis)) return 'automated_or_runtime_evidence';
  if (/(代码|静态|范围|产品|公式|法规|WCAG|接口一致性)/u.test(basis)) return 'static_or_contract_evidence';
  return 'manual_or_external_evidence';
}

function classifyExecutionType(testCase) {
  if (testCase.assessment_status === '不适用') return 'scope_review';
  const baseEvidenceClass = classifyBaseEvidence(testCase);
  if (baseEvidenceClass === 'automated_or_runtime_evidence') return 'automated_integration';
  if (baseEvidenceClass === 'static_or_contract_evidence') return 'contract_plus_runtime';
  return 'manual_or_external_integration';
}

function buildVariantSteps(testCase, factors) {
  const context = [
    `权限范围=${factors.actor_scope}`,
    `数据完整性=${factors.data_completeness}`,
    `数据新鲜度=${factors.freshness}`,
    `上游状态=${factors.upstream_state}`,
  ].join('；');

  return [
    `建立隔离测试上下文：${context}。`,
    ...testCase.steps,
    '核对接口、数据库回读、页面回显和日志证据是否保持同一酒店、平台、业务日期与质量状态。',
  ];
}

function buildVariantExpected(testCase, factors) {
  const guards = [];
  if (factors.actor_scope === 'restricted') guards.push('越权主体必须被拒绝且不得泄露其他酒店数据');
  if (factors.data_completeness === 'missing_required') guards.push('缺失必填数据必须显式标记，不得以 0、空数组或旧数据补齐');
  if (factors.freshness === 'stale') guards.push('过期数据必须标记 stale/截止时间，不得作为实时事实');
  if (factors.upstream_state === 'failure') guards.push('上游失败必须保留真实失败阶段，不得伪报成功或写入正式快照');
  if (guards.length === 0) guards.push('正常路径应完成保存、回读、回显和可追溯验证');
  return `${testCase.expected}；组合守卫：${guards.join('；')}。`;
}

function buildPendingExecutionNote(factors) {
  const context = factorNames.map((factorName) => `${factorName}=${factors[factorName]}`).join('；');
  return `尚无该四因子组合的直接执行证据；需在隔离环境按“${context}”执行完整步骤，保存请求/响应、数据库回读、页面回显和日志证据后再更新状态。500 条基线评估仅作参考，不能替代本变体验证。`;
}

function createScenarioSignature(baseCase, factors, steps, expected) {
  // Exclude generated IDs, ordinal numbers, dates and the L8 title suffix so
  // title-only/date-only variants cannot pass the semantic uniqueness check.
  const semanticDefinition = {
    category_code: baseCase.category_code,
    category_name: baseCase.category_name,
    scope: baseCase.scope,
    test_type: baseCase.type,
    benchmark_basis: baseCase.benchmark_basis,
    precondition: baseCase.precondition,
    steps,
    expected,
    factors: factorNames.map((factorName) => [factorName, factors[factorName]]),
  };
  return `sha256:${createHash('sha256').update(JSON.stringify(semanticDefinition), 'utf8').digest('hex')}`;
}

const cases = [];
for (const baseCase of baseCases) {
  for (const factors of coverageRows) {
    const ordinal = cases.length + 1;
    const steps = buildVariantSteps(baseCase, factors);
    const expected = buildVariantExpected(baseCase, factors);
    cases.push({
      id: `DX-${String(ordinal).padStart(4, '0')}`,
      base_case_id: baseCase.id,
      variant: factors.variant,
      category_code: baseCase.category_code,
      category_name: baseCase.category_name,
      priority: baseCase.priority,
      scope: baseCase.scope,
      type: baseCase.type,
      execution_type: classifyExecutionType(baseCase),
      title: `${baseCase.title}｜L8-${factors.variant}`,
      factors: {
        actor_scope: factors.actor_scope,
        data_completeness: factors.data_completeness,
        freshness: factors.freshness,
        upstream_state: factors.upstream_state,
      },
      benchmark_basis: baseCase.benchmark_basis,
      precondition: baseCase.precondition,
      steps,
      expected,
      scenario_signature: createScenarioSignature(baseCase, factors, steps, expected),
      base_evidence_execution_class: classifyBaseEvidence(baseCase),
      base_assessment_result: baseResultMap[baseCase.assessment_status],
      base_assessment_status: baseCase.assessment_status,
      base_assessment_basis: baseCase.assessment_basis,
      base_project_evidence: baseCase.project_evidence,
      base_next_action: baseCase.next_action,
      variant_execution_status: 'not_executed',
      variant_execution_evidence: null,
      pending_execution_note: buildPendingExecutionNote(factors),
      case_definition_valid: true,
    });
  }
}

function verifyPairwiseCoverage(rows) {
  const gaps = [];
  for (let left = 0; left < factorNames.length; left += 1) {
    for (let right = left + 1; right < factorNames.length; right += 1) {
      const leftName = factorNames[left];
      const rightName = factorNames[right];
      const leftValues = [...new Set(rows.map((row) => row[leftName]))];
      const rightValues = [...new Set(rows.map((row) => row[rightName]))];
      const actual = new Set(rows.map((row) => `${row[leftName]}|${row[rightName]}`));
      for (const leftValue of leftValues) {
        for (const rightValue of rightValues) {
          const key = `${leftValue}|${rightValue}`;
          if (!actual.has(key)) gaps.push(`${leftName}/${rightName}:${key}`);
        }
      }
    }
  }
  return gaps;
}

const pairwiseGaps = verifyPairwiseCoverage(coverageRows);
const requiredFields = [
  'id',
  'base_case_id',
  'category_code',
  'priority',
  'type',
  'execution_type',
  'title',
  'benchmark_basis',
  'precondition',
  'steps',
  'expected',
  'scenario_signature',
  'base_assessment_result',
  'base_assessment_status',
  'variant_execution_status',
  'pending_execution_note',
];
const hasRequiredValue = (value) => {
  if (Array.isArray(value)) return value.length > 0 && value.every((item) => typeof item === 'string' && item.trim() !== '');
  return value !== undefined && value !== null && (typeof value !== 'string' || value.trim() !== '');
};
const invalidCases = cases.filter((testCase) => requiredFields.some((field) => !hasRequiredValue(testCase[field])));
const uniqueIds = new Set(cases.map((testCase) => testCase.id));
const invalidContinuousIds = cases.filter((testCase, index) => testCase.id !== `DX-${String(index + 1).padStart(4, '0')}`);
const uniqueScenarioSignatures = new Set(cases.map((testCase) => testCase.scenario_signature));
const duplicateScenarioSignatures = cases.length - uniqueScenarioSignatures.size;
const baseCoverage = new Map();
for (const testCase of cases) baseCoverage.set(testCase.base_case_id, (baseCoverage.get(testCase.base_case_id) ?? 0) + 1);
const invalidBaseCoverage = [...baseCoverage.entries()].filter(([, count]) => count !== coverageRows.length);
const categoryCoverage = new Map();
for (const testCase of cases) categoryCoverage.set(testCase.category_code, (categoryCoverage.get(testCase.category_code) ?? 0) + 1);
const expectedCategoryCoverage = new Map(sourceAudit.category_summary.map((category) => [category.code, category.total * coverageRows.length]));
const invalidCategoryCoverage = [...expectedCategoryCoverage.entries()]
  .filter(([code, expected]) => categoryCoverage.get(code) !== expected)
  .map(([code, expected]) => ({ code, expected, actual: categoryCoverage.get(code) ?? 0 }));
const unexpectedCategories = [...categoryCoverage.keys()].filter((code) => !expectedCategoryCoverage.has(code));
const allowedVariantStatuses = new Set(['not_executed', 'pass', 'partial', 'fail', 'blocked', 'not_applicable']);
const invalidVariantEvidence = cases.filter((testCase) => {
  if (!allowedVariantStatuses.has(testCase.variant_execution_status)) return true;
  if (testCase.variant_execution_status === 'not_executed') {
    return testCase.variant_execution_evidence !== null || !hasRequiredValue(testCase.pending_execution_note);
  }
  return !hasRequiredValue(testCase.variant_execution_evidence);
});
const unexpectedExecutedVariants = cases.filter((testCase) => testCase.variant_execution_status !== 'not_executed');

if (
  cases.length !== 4000
  || uniqueIds.size !== 4000
  || invalidContinuousIds.length
  || duplicateScenarioSignatures
  || invalidCases.length
  || baseCoverage.size !== baseCases.length
  || invalidBaseCoverage.length
  || invalidCategoryCoverage.length
  || unexpectedCategories.length
  || invalidVariantEvidence.length
  || unexpectedExecutedVariants.length
  || pairwiseGaps.length
) {
  throw new Error(JSON.stringify({
    total: cases.length,
    unique: uniqueIds.size,
    invalidContinuousIds: invalidContinuousIds.length,
    uniqueScenarioSignatures: uniqueScenarioSignatures.size,
    duplicateScenarioSignatures,
    invalidCases: invalidCases.length,
    baseCoverageSize: baseCoverage.size,
    invalidBaseCoverage,
    invalidCategoryCoverage,
    unexpectedCategories,
    invalidVariantEvidence: invalidVariantEvidence.length,
    unexpectedExecutedVariants: unexpectedExecutedVariants.length,
    pairwiseGaps,
  }));
}

const countBy = (field) => Object.fromEntries(
  [...new Set(cases.map((testCase) => testCase[field]))]
    .map((value) => [value, cases.filter((testCase) => testCase[field] === value).length]),
);

const categorySummary = sourceAudit.category_summary.map((category) => {
  const rows = cases.filter((testCase) => testCase.category_code === category.code);
  return {
    code: category.code,
    name: category.name,
    total: rows.length,
    base_assessment_results: Object.fromEntries(
      ['pass', 'partial', 'fail', 'blocked', 'not_applicable']
        .map((result) => [result, rows.filter((testCase) => testCase.base_assessment_result === result).length]),
    ),
    variant_execution_statuses: Object.fromEntries(
      ['not_executed', 'pass', 'partial', 'fail', 'blocked', 'not_applicable']
        .map((status) => [status, rows.filter((testCase) => testCase.variant_execution_status === status).length]),
    ),
  };
});

const internetSources = [
  ...sourceAudit.metadata.sources,
  {
    name: 'NIST SP 800-142 Practical Combinatorial Testing',
    use: 'L8/两两交互覆盖的测试设计依据',
    url: 'https://csrc.nist.gov/pubs/sp/800/142/final',
  },
  {
    name: 'W3C ACT Rules for WCAG 2.2',
    use: '可访问性规则及通过/失败/不适用样例结构',
    url: 'https://www.w3.org/WAI/standards-guidelines/act/rules/',
  },
  {
    name: 'Hotel booking demand datasets',
    use: '真实脱敏酒店 PMS 预订数据的字段、日期、取消和 ADR 语义参照',
    url: 'https://doi.org/10.1016/j.dib.2018.11.126',
  },
  {
    name: 'JSON Schema Test Suite',
    use: '结构化测试用例的 schema + tests + valid 组织方式参照',
    url: 'https://github.com/json-schema-org/JSON-Schema-Test-Suite',
  },
];

const summary = {
  title: '宿析OS 4,000 条软件与酒店运营诊断矩阵',
  audit_date: auditDate,
  generated_at: new Date().toISOString(),
  methodology: '500 个互联网标准/行业基线核心场景 × NIST L8 四因子两两组合 = 4,000 条；500 条基线评估只保存在 base_assessment_result，所有没有组合级直接证据的 L8 变体均为 not_executed，不把基线结论传播为变体动态通过。',
  totals: {
    cases: cases.length,
    base_cases: baseCases.length,
    variants_per_base: coverageRows.length,
    unique_ids: uniqueIds.size,
    continuous_ids: cases.length - invalidContinuousIds.length,
    unique_scenario_signatures: uniqueScenarioSignatures.size,
    duplicate_scenario_signatures: duplicateScenarioSignatures,
    definition_validation_passed: cases.length - invalidCases.length,
    definition_validation_failed: invalidCases.length,
    invalid_variant_evidence_records: invalidVariantEvidence.length,
    pairwise_coverage_gaps: pairwiseGaps.length,
    base_assessment_results: countBy('base_assessment_result'),
    variant_execution_statuses: countBy('variant_execution_status'),
    execution_types: countBy('execution_type'),
    base_evidence_execution_classes: countBy('base_evidence_execution_class'),
  },
  factor_model: {
    factors: factorNames,
    rows: coverageRows,
    pairwise_coverage: pairwiseGaps.length === 0 ? 'complete' : 'incomplete',
  },
  category_summary: categorySummary,
  sources: internetSources,
  execution_boundary: {
    matrix_validation: '4,000 条用例定义均已执行结构校验；ID 连续唯一、语义签名唯一、必填字段完整、领域配额正确、每个核心场景 8 个变体完整且两两覆盖无缺口。',
    variant_execution: '当前 4,000 条 L8 变体均为 not_executed；只有保存该权限/完整性/新鲜度/上游状态组合的直接证据后，才能更新为 pass、partial、fail、blocked 或 not_applicable。',
    base_assessment: 'base_assessment_result 来自 500 条基线资产，只用于排期与上下文参考，不是 L8 变体执行结果。',
  },
};

mkdirSync(qaDir, { recursive: true });

const jsonlPath = path.join(qaDir, `hotel_system_4000_diagnostic_cases_${auditDate}.jsonl`);
writeFileSync(jsonlPath, `${cases.map((testCase) => JSON.stringify(testCase)).join('\n')}\n`, 'utf8');

const resultPath = path.join(qaDir, `hotel_system_4000_diagnostic_results_${auditDate}.json`);
writeFileSync(resultPath, `${JSON.stringify(summary, null, 2)}\n`, 'utf8');

const markdown = [];
markdown.push('# 宿析OS 4,000 条软件与酒店运营诊断矩阵', '');
markdown.push(`- 生成日期：${auditDate}`);
markdown.push('- 设计：500 个核心场景 × NIST L8 四因子两两组合 = 4,000 条。');
markdown.push('- 四因子：权限范围、数据完整性、数据新鲜度、上游状态。');
markdown.push('- 真实性边界：4,000 条均完成用例定义与组合覆盖校验，但当前没有组合级直接执行证据，全部保持 `not_executed`；500 条基线评估不能替代变体执行。', '');
markdown.push('## 矩阵校验', '');
markdown.push(`- 用例总数：${summary.totals.cases}`);
markdown.push(`- 唯一 ID：${summary.totals.unique_ids}`);
markdown.push(`- 连续 ID：${summary.totals.continuous_ids}`);
markdown.push(`- 唯一语义签名：${summary.totals.unique_scenario_signatures}`);
markdown.push(`- 重复语义签名：${summary.totals.duplicate_scenario_signatures}`);
markdown.push(`- 定义校验通过：${summary.totals.definition_validation_passed}`);
markdown.push(`- 变体证据状态违规：${summary.totals.invalid_variant_evidence_records}`);
markdown.push(`- 两两组合覆盖缺口：${summary.totals.pairwise_coverage_gaps}`, '');
markdown.push('## 变体执行状态（动态结果唯一口径）', '');
markdown.push('| 状态 | 数量 |', '|---|---:|');
for (const [status, count] of Object.entries(summary.totals.variant_execution_statuses)) markdown.push(`| ${status} | ${count} |`);
markdown.push('', '## 500 条基线评估映射（仅参考）', '');
markdown.push('| 基线结果 | 映射变体数 |', '|---|---:|');
for (const [result, count] of Object.entries(summary.totals.base_assessment_results)) markdown.push(`| ${result} | ${count} |`);
markdown.push('', '## 计划执行类型', '');
markdown.push('| 类型 | 数量 |', '|---|---:|');
for (const [executionType, count] of Object.entries(summary.totals.execution_types)) markdown.push(`| ${executionType} | ${count} |`);
markdown.push('', '## 分类结果', '');
markdown.push('| 分类 | 总数 | 基线通过映射 | 基线部分映射 | 基线失败映射 | 基线阻塞映射 | 基线不适用映射 | 变体未执行 |', '|---|---:|---:|---:|---:|---:|---:|---:|');
for (const row of categorySummary) {
  markdown.push(`| ${row.code} ${row.name} | ${row.total} | ${row.base_assessment_results.pass} | ${row.base_assessment_results.partial} | ${row.base_assessment_results.fail} | ${row.base_assessment_results.blocked} | ${row.base_assessment_results.not_applicable} | ${row.variant_execution_statuses.not_executed} |`);
}
markdown.push('', '## 生成物', '');
markdown.push(`- 完整 4,000 条 JSONL：\`docs/qa/${path.basename(jsonlPath)}\``);
markdown.push(`- 汇总 JSON：\`docs/qa/${path.basename(resultPath)}\``);
markdown.push(`- 生成脚本：\`scripts/${path.basename(fileURLToPath(import.meta.url))}\``, '');

const summaryPath = path.join(qaDir, `hotel_system_4000_diagnostic_summary_${auditDate}.md`);
writeFileSync(summaryPath, `${markdown.join('\n')}\n`, 'utf8');

console.log(JSON.stringify({
  jsonlPath,
  resultPath,
  summaryPath,
  totals: summary.totals,
}, null, 2));
