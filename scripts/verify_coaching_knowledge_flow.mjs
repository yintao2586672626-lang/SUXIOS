import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.join(root, 'output/qiansu-merge-20260926');
fs.mkdirSync(out, { recursive: true });
const db = path.join(os.tmpdir(), 'coaching-test-' + process.pid + '.sqlite');
const php = process.env.SUXI_PHP || 'C:/xampp/php/php.exe';
const env = { ...process.env, SUXI_COACHING_TEST_DB: db };
const router = path.join(root, 'tests/Support/coaching_fixture_router.php');
const seed = spawnSync(php, [router, '--seed'], { cwd: root, env, encoding: 'utf8', windowsHide: true });
assert.equal(seed.status, 0, seed.stderr || seed.stdout);
const port = 18388;
const base = 'http://127.0.0.1:' + port;
const server = spawn(php, ['-S', '127.0.0.1:' + port, router], { cwd: root, env, stdio: 'ignore', windowsHide: true });
let browser;
const checks = [];
try {
  for (let n = 0; n < 40; n++) {
    try { if ((await fetch(base)).ok) break; } catch {}
    await new Promise(r => setTimeout(r, 100));
  }
  const api = async (route, data) => (await fetch(base + route, data ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) } : {})).json();
  for (const [query, code] of [['hotel_id=21&manager_user_id=7', 403], ['hotel_id=20&manager_user_id=8', 403], ['hotel_id=20&manager_user_id=7&unauthenticated=1', 401]]) {
    assert.equal((await api('/operation/manager-capability/coaching?' + query)).code, code);
  }
  checks.push('real_controller_rejects_unauthenticated_wrong_hotel_wrong_person');
  browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(base);
  await page.getByRole('button', { name: '制定带教/改进计划' }).click();
  for (const [label, value] of Object.entries({
    '计划标题': '交接实操验证', '原因依据或待核实问题': '合成样例：核对标准后仍遗漏操作步骤',
    '目标行为': '独立完成交接复核', '执行步骤': '示范后独立操作并抽查',
    '验收标准': '三笔记录都有可核对的复核签字', '带教或整改负责人': '样例负责人', '最低复查样本数': '3',
  })) await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByLabel('原因分类', { exact: true }).selectOption('knowledge');
  await page.getByText('引用知识版本（知识问题必填）', { exact: true }).click();
  await page.getByRole('button', { name: '查找', exact: true }).click();
  await page.getByRole('button', { name: '引用：隔离样例：交接标准', exact: true }).click();
  await page.getByRole('button', { name: '保存并核对', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[role="status"],[role="alert"]'));
  assert.equal(await page.getByRole('alert').count(), 0, await page.getByRole('alert').allTextContents().then(v => v.join('\n')));
  await page.getByRole('status').waitFor();
  let list = await api('/operation/manager-capability/coaching?hotel_id=20&manager_user_id=7');
  assert.equal(list.code, 200, JSON.stringify(list));
  assert.equal(list.data.list.length, 1);
  let plan = list.data.list[0];
  assert.equal(plan.content.knowledge_snapshots.length, 1);
  checks.push('real_component_create_knowledge_snapshot_post_get_sqlite');
  await page.getByRole('button', { name: '记录学习/实操', exact: true }).click();
  await page.getByLabel('观察阶段', { exact: true }).selectOption('independent');
  await page.getByLabel('实际样本数', { exact: true }).fill('3');
  await page.getByLabel('观察记录或原因说明', { exact: true }).fill('合成样例：三笔完成并记录复核签字');
  await page.getByRole('button', { name: '保存并核对', exact: true }).click();
  await page.getByRole('alert').waitFor();
  assert.equal(await page.getByLabel('观察记录或原因说明').inputValue(), '合成样例：三笔完成并记录复核签字');
  await page.getByLabel('证据位置或记录编号').fill('synthetic-record-3');
  await page.getByRole('button', { name: '保存并核对', exact: true }).click();
  await page.getByRole('status').waitFor();
  checks.push('missing_evidence_rejected_draft_retained_retry_saved');
  await page.getByRole('button', { name: '追加复查', exact: true }).click();
  await page.getByLabel('复查结论', { exact: true }).selectOption('target_met');
  await page.getByLabel('实际样本数').fill('3');
  await page.getByLabel('证据位置或记录编号').fill('synthetic-review-3');
  await page.getByLabel('观察记录或原因说明').fill('人工复核：逐项达到计划标准');
  await page.getByLabel('我已逐项核对本计划验收标准').check();
  await page.getByRole('button', { name: '保存并核对', exact: true }).click();
  await page.getByRole('button', { name: '整理参考经验', exact: true }).waitFor();
  await page.getByRole('button', { name: '整理参考经验', exact: true }).click();
  for (const [label, value] of Object.entries({ '经验标题': '交接实操参考', '脱敏经验摘要': '先示范再独立演练', '经验步骤': '明确标准、示范、演练、复查', '适用条件': '已有清单和复核规则', '停止条件': '标准或证据不足时暂停判断', '脱敏验收方法': '抽样核对操作清单' })) await page.getByLabel(label, { exact: true }).fill(value);
  await page.getByRole('button', { name: '保存并核对', exact: true }).click();
  await page.getByRole('status').waitFor();
  const exact = await api('/operation/manager-capability/coaching/' + plan.id + '?hotel_id=20&manager_user_id=7');
  assert.equal(exact.data.plan.status, 'completed');
  const knowledgeId = exact.data.events.at(-1).payload.knowledge_unit_id;
  const knowledge = await api('/knowledge/' + knowledgeId);
  assert.equal(knowledge.data.chunks[0].content.scope, 'reference_only');
  assert.ok(!JSON.stringify(knowledge.data.chunks[0].content).includes('problem_facts'));
  checks.push('review_to_reference_knowledge_exact_get_no_score_claim');
  await page.screenshot({ path: path.join(out, 'coaching-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole('button', { name: '记录复发', exact: true }).click();
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
  await page.screenshot({ path: path.join(out, 'coaching-mobile.png'), fullPage: true });
  checks.push('390px_recurrence_form_no_horizontal_overflow');
  const appMain = fs.readFileSync(path.join(root, 'public/app-main.js'), 'utf8');
  const workflow = appMain.slice(appMain.indexOf('const createWorkflowFormDialogState ='), appMain.indexOf('let runtimeErrorRecoveryQueued ='));
  const fragment = fs.readFileSync(path.join(root, 'resources/frontend/templates/fragments/46-global-toast.html'), 'utf8');
  const dialog = fragment.slice(fragment.indexOf('<div v-if="workflowFormDialog.visible"'), fragment.indexOf('<!-- 全局智能咨询'));
  await page.addScriptTag({ path: path.join(root, 'public/components/system/knowledge-center-domain.js') });
  await page.evaluate(async ({ workflow, dialog }) => {
    const { ref, computed } = Vue;
    const forms = new Function('ref', workflow + ';return {workflowFormDialog,openWorkflowFormDialog,closeWorkflowFormDialog,submitWorkflowFormDialog};')(ref);
    const request = async (url, options = {}) => (await fetch(url, { ...options, headers: { 'Content-Type': 'application/json' } })).json();
    const data = (await request('/knowledge/1')).data;
    window.referenceNotices = [];
    const store = { ...forms, computed, request, requireSystemStatic: () => [], captureAuthSession: () => 'synthetic', isAuthSessionCurrent: () => true,
      defaultKnowledgeCenterHotelId: () => 20, defaultKnowledgeExperienceChunk: () => '',
      showToast: message => referenceNotices.push(message), sameAiGovernanceJson: (a, b) => JSON.stringify(a) === JSON.stringify(b),
      currentPage: ref('knowledge-center'), knowledgeCenterSelectedUnit: ref(data.unit),
      knowledgeCenterFilter: ref({ hotel_id: 20 }), knowledgeCenterPagination: ref({ page: 1, page_size: 10 }),
      selectedKnowledgeCenterUnitIds: ref([]), knowledgeCenterUnits: ref([]), knowledgeCenterChunks: ref([]) };
    const context = new Proxy(store, { get: (target, key) => key in target ? target[key] : (target[key] = ref({})) });
    window.referenceDomain = SUXI_KNOWLEDGE_CENTER_DOMAIN.create(context);
    const el = document.createElement('div'); document.body.appendChild(el);
    Vue.createApp({ setup: () => forms, template: dialog }).mount(el);
    void referenceDomain.editKnowledgeReference(data.chunks[0]);
  }, { workflow, dialog });
  const referenceDialog = page.getByRole('dialog', { name: '整理为参考 SOP', exact: true });
  await referenceDialog.waitFor();
  const inputs = referenceDialog.locator('input');
  await inputs.first().fill('隔离验收参考稿');
  const areas = referenceDialog.locator('textarea');
  for (let n = 0; n < await areas.count(); n++) await areas.nth(n).fill('依据来源人工整理：' + ['目标', '步骤', '适用条件', '停止条件', '验收方法'][n]);
  const selects = referenceDialog.locator('select');
  for (let n = 0; n < await selects.count(); n++) {
    const values = await selects.nth(n).locator('option').evaluateAll(options => options.map(o => o.value).filter(Boolean));
    await selects.nth(n).selectOption(values[0]);
  }
  await page.screenshot({ path: path.join(out, 'knowledge-reference-mobile.png'), fullPage: true });
  await referenceDialog.getByRole('button', { name: '保存参考稿并回读' }).click();
  await page.waitForFunction(() => referenceNotices.length > 0);
  assert.ok((await page.evaluate(() => referenceNotices)).some(n => n.includes('已保存并回读')), JSON.stringify(await page.evaluate(() => referenceNotices)));
  checks.push('real_knowledge_domain_and_shared_dialog_citations_post_get_mobile');
  // An old response must not repopulate the next hotel.
  let release;
  const delayed = new Promise(r => { release = r; });
  await page.route('**/operation/manager-capability/coaching?hotel_id=20**', async route => { await delayed; await route.continue(); });
  await page.evaluate(() => { fixtureVm.hotel = 21; });
  await page.waitForTimeout(100);
  await page.evaluate(() => { fixtureVm.hotel = 20; });
  await page.waitForTimeout(100);
  await page.evaluate(() => { fixtureVm.hotel = 21; });
  release();
  await page.waitForTimeout(300);
  assert.equal(await page.getByText('交接实操验证', { exact: true }).count(), 0);
  checks.push('stale_hotel_response_ignored');
  await page.unrouteAll();
  await page.goto(base + '/parent');
  await page.getByTestId('manager-coaching-panel').waitFor();
  assert.equal(await page.getByTestId('manager-capability-manager').inputValue(), '7');
  await page.getByTestId('manager-coaching-panel').getByRole('button', { name: '制定带教/改进计划' }).click();
  assert.equal(await page.getByLabel('来源管理案例', { exact: true }).inputValue(), '1');
  checks.push('production_parent_entry_loads_coaching_with_scoped_case');
  let releaseProfile, profileStarted;
  const profileDelay = new Promise(r => { releaseProfile = r; });
  const profileBegin = new Promise(r => { profileStarted = r; });
  await page.route('**/manager-capability/profile?*manager_user_id=10*', async route => {
    profileStarted(); await profileDelay; await route.continue();
  });
  await page.getByTestId('manager-capability-manager').selectOption('10');
  await profileBegin;
  await page.evaluate(() => Vue.nextTick());
  const retainedOldCoaching = await page.getByTestId('manager-coaching-panel').count();
  if (retainedOldCoaching) await page.screenshot({ path: path.join(out, 'parent-scope-before-fix.png'), fullPage: true });
  releaseProfile();
  assert.equal(retainedOldCoaching, 0, 'Previous manager case/permissions must disappear while the next profile is loading');
  await page.getByText('先记录一个管理案例，再制定有依据的计划。', { exact: true }).waitFor();
  checks.push('parent_manager_switch_clears_previous_profile_before_response');
  let releaseManagers, managersStarted;
  const managersDelay = new Promise(r => { releaseManagers = r; });
  const managersBegin = new Promise(r => { managersStarted = r; });
  await page.route('**/manager-capability/managers?hotel_id=21', async route => {
    managersStarted(); await managersDelay; await route.continue();
  });
  await page.evaluate(() => { fixtureVm.hotel = 21; });
  await managersBegin;
  await page.evaluate(() => Vue.nextTick());
  const retainedOtherHotel = await page.getByTestId('manager-coaching-panel').count();
  releaseManagers();
  assert.equal(retainedOtherHotel, 0, 'Previous hotel profile and coaching must disappear before the new manager list response');
  checks.push('parent_hotel_switch_clears_previous_profile_before_response');
  assert.deepEqual(errors, []);
  const result = { status: 'passed', evidence_tier: 'synthetic_sqlite_real_controller_and_component', checks, browser_errors: errors.length, account_login_verified: false, field_verified: false };
  fs.writeFileSync(path.join(out, 'browser-evidence.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} finally {
  await browser?.close();
  server.kill();
  await new Promise(r => setTimeout(r, 200));
  if (fs.existsSync(db)) fs.unlinkSync(db);
}
