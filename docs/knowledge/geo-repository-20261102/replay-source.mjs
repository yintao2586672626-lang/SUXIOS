import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, readdirSync, statSync, writeFileSync, mkdirSync } from 'node:fs';
import { stripTypeScriptTypes } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const project = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const cache = path.join(project, 'output/learning/geo-repository-20261102');
const sources = path.join(cache, 'sources');
const tree = JSON.parse(readFileSync(path.join(cache, 'source-tree.json'), 'utf8'));
const sha = 'ac07b6b6a79a6a3a1c489866ec2959d8d1b778a7';
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
const files = [];
function inventory(dir, prefix = '') {
  for (const name of readdirSync(dir).sort()) {
    const abs = path.join(dir, name), relative = prefix + name;
    if (statSync(abs).isDirectory()) inventory(abs, relative + '/');
    else {
      const bytes = readFileSync(abs);
      const gitSha = createHash('sha1').update(Buffer.from('blob ' + bytes.length + '\0')).update(bytes).digest('hex');
      const entry = tree.tree.find(e => e.path === relative && e.type === 'blob');
      assert.ok(entry, 'source not present in locked tree: ' + relative);
      assert.equal(gitSha, entry.sha, 'source bytes differ: ' + relative);
      files.push({ path: relative, bytes: bytes.length, git_blob_sha: gitSha, sha256: sha256(bytes) });
    }
  }
}
inventory(sources);
const allowed = new Set(['shared', 'service-utils', 'brain-shared', 'brain-matching', 'brand-retrieval', 'strategy-plan', 'model-errors']);
const urls = new Map();
function moduleUrl(name) {
  assert.ok(allowed.has(name), 'unreviewed source module: ' + name);
  if (urls.has(name)) return urls.get(name);
  const original = readFileSync(path.join(sources, 'src/lib', name + '.ts'), 'utf8');
  let js = stripTypeScriptTypes(original, { mode: 'transform' });
  js = js.replace(/from\s+["']\.\/([^"']+)["']/g, (_, dependency) => 'from ' + JSON.stringify(moduleUrl(dependency)));
  assert.ok(!/from\s+["'](?!data:)/.test(js), 'unexpected external runtime dependency');
  const url = 'data:text/javascript;base64,' + Buffer.from(js).toString('base64');
  urls.set(name, url); return url;
}
const shared = await import(moduleUrl('brain-shared'));
const matching = await import(moduleUrl('brain-matching'));
const plans = await import(moduleUrl('strategy-plan'));
const brands = await import(moduleUrl('brand-retrieval'));
const retry = await import(moduleUrl('model-errors'));
const queueText = readFileSync(path.join(sources, 'src/lib/ai-task-queue.ts'), 'utf8');
const queuePrefix = queueText.slice(queueText.indexOf('export const taskLeaseExpiry'), queueText.indexOf('export async function claimOpportunityTask'));
const queue = await import('data:text/javascript;base64,' + Buffer.from(stripTypeScriptTypes(
  'import { AppError } from ' + JSON.stringify(moduleUrl('service-utils')) + ';\n' + queuePrefix, { mode: 'transform' })).toString('base64'));
const scoreText = readFileSync(path.join(sources, 'src/lib/opportunity-scoring.ts'), 'utf8');
const gradeLine = scoreText.split('\n').find(l => l.startsWith('export function opportunityGrade('));
assert.ok(gradeLine);
const grade = await import('data:text/javascript;base64,' + Buffer.from(stripTypeScriptTypes(gradeLine, { mode: 'transform' })).toString('base64'));

const entities = [
  { id: 'g', name: '合成集团', entity_type: 'company', parent_id: null, status: 'active' },
  { id: 'b', name: '合成品牌', entity_type: 'brand', parent_id: 'g', status: 'active' },
  { id: 'h', name: '合成酒店', entity_type: 'store', parent_id: 'b', status: 'active' },
  { id: 'x', name: '合成兄弟店', entity_type: 'store', parent_id: 'b', status: 'active' },
];
const emptyFields = Object.fromEntries(Object.keys(shared.brainFields).map(k => [k, '']));
const rule = (name, keywords, extra = {}) => ({ name, keywords, question_types: [], applicable_customers: [], scenarios: [], excluded_scenarios: [], strategy_goal: name + '适用性说明', next_step: '核对资料', information_needed: ['经营与渠道数据'], ...extra });
const groupBrain = { ...emptyFields, id: 'brain-g', entity_id: 'g', status: 'active', core_business: '酒店托管\nOTA代运营\n酒店筹建规划\n供应链', generation_rules: '保留来源',
  business_rules: [rule('酒店托管', ['经营不好', '入住率低']), rule('OTA代运营', ['OTA']),
    rule('酒店筹建规划', ['筹建']), rule('供应链', ['采购'], { excluded_scenarios: ['不涉及采购'] })],
  decision_rules: [{ name: '经营改善', keywords: ['经营不好', '入住率低'], excluded_scenarios: [], user_need: '先核实经营问题',
    user_type: '经营者待确认', strategy_focus: '先诊断再选择服务', analysis: '原因未核实', primary_business: '酒店托管',
    supporting_businesses: ['OTA代运营'], content_directions: ['经营诊断资料清单'] }] };
const advantage = { id: 'a', entity_id: 'g', status: 'active', name: '集团经验', description: '合成案例能力',
  proof_data: '', supporting_fact_ids: ['f'], keywords: ['经营不好'], case_ids: ['c'], applicable_question_types: [],
  applicable_businesses: ['酒店托管', 'OTA代运营'], applicable_customers: [], excluded_scenarios: [] };
const caseRow = { id: 'c', entity_id: 'g', status: 'active', name: '合成案例', project_type: '合成',
  customer_problem: '经营资料缺口', solution: '核对渠道数据', methods: '', result: '仅说明处理过程',
  answerable_questions: ['酒店经营不好怎么办？'], keywords: ['经营不好'], source: 'synthetic',
  usage_notes: '不承诺其他项目效果', risk_level: 'normal', question_ids: [], solved_problems: [],
  applicable_scenarios: [], related_businesses: ['酒店托管', 'OTA代运营'], applicable_customers: ['酒店业主'], methodology_ids: ['m'] };
const method = { id: 'm', entity_id: 'g', status: 'active', name: '合成诊断方法', description: '步骤参考',
  steps: ['核对来源', '核对日期', '核对口径'], keywords: [] };
const base = { entities, brains: [groupBrain], advantages: [advantage], cases: [caseRow], methods: [method], forbidden: [],
  facts: [{ id: 'f', entity_id: 'g', fact_key: 'direct_store_count', value_json: 3, source_ref: 'synthetic#f' }],
  questions: [], logs: [], logs_total: 0, model_configured: false };
function context(question = '酒店经营不好怎么办？', request = {}, data = structuredClone(base)) {
  const scope = shared.entityScope(data.entities, 'h'), scopedBrains = data.brains.filter(b => scope.some(e => e.id === b.entity_id));
  const found = matching.matchKnowledge({ data, scope, brains: scopedBrains, facts: data.facts, question, request });
  const c = { entity: scope[0], scope, ready: true, gaps: [], profile: shared.effectiveProfile(scopedBrains, scope), brains: scopedBrains,
    facts: data.facts, question, question_id: null, documents: [], forbidden: [], fingerprint: 'synthetic', ...found };
  c.decision = matching.createBrainDecision(c); c.strategy = matching.createContentStrategy(c); return c;
}
const checks = [];
function check(id, origin, fn) { fn(); checks.push({ id, origin, status: 'passed' }); }
check('ancestor_scope_excludes_sibling', 'source mechanism / synthetic', () => assert.deepEqual(shared.entityScope(entities, 'h').map(e => e.id), ['h','b','g']));
check('profile_nearest_owner_and_rules_merged', 'source mechanism / synthetic', () => {
  const b = { ...emptyFields, entity_id: 'b', status: 'active', positioning: '品牌定位', generation_rules: '品牌限制' };
  const p = shared.effectiveProfile([groupBrain, b], shared.entityScope(entities, 'h'));
  assert.equal(p.positioning.entity_id, 'b'); assert.ok(p.generation_rules.value.includes('合成集团')); assert.ok(p.generation_rules.value.includes('合成品牌'));
});
check('operations_primary_supporting_case_method', 'docs/07 and docs/08 equivalent golden fixture', () => {
  const c = context(); assert.deepEqual(c.businesses.map(b => b.name), ['酒店托管','OTA代运营']);
  assert.equal(c.cases[0].id, 'c'); assert.equal(c.methods[0].id, 'm'); assert.equal(c.strategy.status, 'ready');
});
check('unknown_question_is_needs_info', 'source boundary / synthetic', () => { const c = context('如何维修卫星？'); assert.equal(c.businesses.length, 0); assert.equal(c.strategy.status, 'needs_info'); });
check('explicit_negation_does_not_trigger_setup', 'source direct-negation boundary', () => assert.equal(context('不需要筹建').businesses.length, 0));
check('exclusion_beats_keyword', 'docs/07 equivalent exclusion', () => { const c = context('筹建与采购评估，不涉及采购'); assert.ok(!c.businesses.some(b => b.name === '供应链')); assert.ok(c.excluded.some(e => e.name === '供应链')); });
check('customer_restriction_beats_exact_question', 'source failure boundary', () => { const c = context(undefined, { customer_type: '普通住客' }); assert.equal(c.cases.length, 0); assert.equal(c.strategy.status, 'needs_info'); });
check('missing_proof_excludes_advantage', 'source failure boundary', () => { const d = structuredClone(base); d.facts = []; assert.equal(context(undefined, {}, d).advantages.length, 0); });
check('unrelated_case_not_dragged_by_advantage', 'docs/07 boundary', () => {
  const d = structuredClone(base); d.cases.push({ ...caseRow, id: 'setup', name: '筹建案例', related_businesses: ['酒店筹建规划'], keywords: ['筹建'], answerable_questions: [] });
  d.advantages[0].case_ids.push('setup'); assert.deepEqual(context(undefined, {}, d).cases.map(c => c.id), ['c']);
});
check('strategy_valid_selection_and_owner', 'docs/09 equivalent golden fixture', () => { const s = plans.sourceStrategy(context()); assert.equal(s.knowledge.advantages[0].label, '3家直营经验'); assert.equal(s.knowledge.advantages[0].owner_name, '合成集团'); });
check('strategy_rejects_forged_reference', 'source failure boundary', () => { const c=context(), s=plans.defaultStrategySelection(c); s.case_ids=['forged']; assert.throws(() => plans.validateStrategySelection(c,s), /范围之外/); });
check('strategy_rejects_duplicate_reference', 'source failure boundary', () => { const c=context(), s=plans.defaultStrategySelection(c); s.case_ids=['c','c']; assert.throws(() => plans.validateStrategySelection(c,s), /重复/); });
check('strategy_rejects_missing_core_business', 'source failure boundary', () => { const c=context(), s=plans.defaultStrategySelection(c); s.business_ids=s.business_ids.slice(0,1); assert.throws(() => plans.validateStrategySelection(c,s), /核心业务/); });
check('changed_fact_rehydrates_number', 'docs/09 equivalent source-change fixture', () => { const d=structuredClone(base); d.facts[0].value_json=4; assert.equal(plans.sourceStrategy(context(undefined,{},d)).knowledge.advantages[0].label, '4家直营经验'); });
check('strategy_returns_no_article_outline', 'docs/09 boundary', () => assert.deepEqual(plans.sourceStrategy(context()).outline, []));
check('brand_room_range_in_and_out', 'docs/09 equivalent golden fixture', () => {
  const d=structuredClone(base); d.brains.push({...emptyFields, entity_id:'b', status:'active',positioning:'合成小体量品牌'});
  const b=[{id:'g:加盟',name:'加盟',brand_ids:['b']}], facts=[{id:'size',entity_id:'b',fact_key:'room_count',value_json:'20-60间房'}];
  assert.equal(brands.retrieveLinkedBrands(d,[entities[0]],b,facts,'20-60间房适合加盟什么酒店？').length,1);
  assert.equal(brands.retrieveLinkedBrands(d,[entities[0]],b,facts,'60-120间房适合加盟什么酒店？').length,0);
});
check('unicode_forbidden_expression', 'source lexical-boundary fixture', () => assert.deepEqual(shared.forbiddenHits('１００ ％回本',[{expression:'100%回本'}]), ['100%回本']));
check('grade_cutoffs', 'tests/opportunities.test.ts boundary values', () => assert.deepEqual([0,49,50,69,70,84,85,100].map(grade.opportunityGrade), ['C','C','B','B','A','A','S','S']));
const now=Date.parse('2026-09-18T08:00:00Z');
check('retry_after_seconds_and_date', 'tests/model-retry.test.ts source samples', () => {
  for (const [value,ms] of [['60',60000],['0.5',500],['103188',103188000],['Fri, 18 Sep 2026 08:01:00 GMT',60000]])
    assert.equal(retry.retryAfter(new Headers({'retry-after':value}),now).getTime(),now+ms);
});
check('retry_reset_window_and_invalid', 'tests/model-retry.test.ts source samples', () => {
  assert.equal(retry.retryAfter(new Headers({'x-ratelimit-reset-tokens':'1h2m3s4ms'}),now).getTime(),now+3723004);
  assert.equal(retry.retryAfter(new Headers({'retry-after':'invalid','x-ratelimit-reset-tokens':'60s'}),now).getTime(),now+60000);
  assert.equal(retry.retryAfter(new Headers({'retry-after':'-1','x-ratelimit-reset-tokens':'invalid'}),now),null);
});
check('provider_long_delay_never_shortened', 'tests/model-retry.test.ts source samples', () => { const e=new retry.ModelCallError('合成限流',429,null,new Date(now+103188000),true); assert.equal(retry.nextRetryAt(e,0,now,0).getTime(),now+103188000); });
check('exponential_retry_cap', 'tests/model-retry.test.ts source samples', () => {
  const e=new retry.ModelCallError('合成限流',429,null,null,true);
  for(const [count,ms] of [[0,5000],[2,20000],[10,300000]]) assert.equal(retry.nextRetryAt(e,count,now,0).getTime(),now+ms);
});
check('task_counts_success_reused_and_pending', 'queue pure-prefix boundary', () => assert.deepEqual(queue.taskCounts([{status:'success'},{status:'reused'},{status:'failed'},{status:'pending'}]),{success_count:2,failed_count:1}));
check('empty_failed_batch_reports_plan_twenty', 'observed source limitation, not desired SUXIOS semantics', () => assert.deepEqual(queue.taskCounts([],true),{success_count:0,failed_count:20}));
const observedLimitations=[];
check('uncovered_negation_is_observed', 'observed source limitation, not desired behavior', () => {
  const c=context('并不打算筹建'); assert.ok(c.businesses.some(b=>b.name==='酒店筹建规划'));
  observedLimitations.push({input:'并不打算筹建',actual:'matches 酒店筹建规划',meaning:'lexical negation patterns are incomplete; do not claim general semantic negation'});
});
const manifest={version:'geo-repository-20261102-v1',source_url:'https://github.com/lzy27272/GEO',source_ref:sha,source_tree_sha:tree.sha,
  observed_date:'2026-11-02',usage_policy:'reference_only',automatic_application_authorized:false,license:'No LICENSE/COPYING file observed in locked tree; code redistribution permission not established',
  cache_path:'output/learning/geo-repository-20261102/sources',cached_file_count:files.length,files,
  review_boundary:'Selected docs and source mechanisms reviewed; cached files include targeted excerpts, not full repository audit',
  execution_boundary:'Seven reviewed local TypeScript modules plus queue pure prefix and grade function; built-in type transformation and import URL rewriting only; no network, database, model, queue worker, seed or startup script executed'};
const receipt={source_ref:sha,verified_at:new Date().toISOString(),source_files_verified:files.length,status:'passed',
  passed_cases:checks.length,checks,observed_limitations:observedLimitations,fixture_kind:'synthetic equivalents and selected original source assertions',
  evidence_ceiling:'Local source function replay only; not full upstream tests, PostgreSQL queue recovery, live model, GEO measurement, SUXIOS integration, account/field or business-effect validation'};
mkdirSync(cache,{recursive:true});
writeFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)),'source-manifest.json'),JSON.stringify(manifest,null,2)+'\n');
writeFileSync(path.join(cache,'replay-receipt.json'),JSON.stringify(receipt,null,2)+'\n');
console.log(JSON.stringify({status:'passed',source_ref:sha,source_files_verified:files.length,passed_cases:checks.length,observed_limitations:observedLimitations.length}));
