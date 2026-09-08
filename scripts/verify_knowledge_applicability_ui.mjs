import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const root = process.cwd();
const output = path.join(root, 'output/long-goal');
const evidence = JSON.parse(fs.readFileSync(path.join(output, 'controller-readback.json'), 'utf8'));
const fragment = fs.readFileSync('resources/frontend/templates/fragments/38-dialogs-knowledge-center.html', 'utf8');
const template = fragment.slice(fragment.indexOf('<div v-if="showKnowledgeCenterChunksModal"'));
const browser = await chromium.launch({ headless: true });
const errors = [];
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  page.on('pageerror', error => errors.push(error.message));
  await page.setContent('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><body><div style="padding:12px">L08 · 隔离样本 / 已执行控制器的固定响应 · 不连接真实数据库</div><div id="app"></div></body></html>');
  await page.addStyleTag({ content: fs.readFileSync('public/tailwind.full.css', 'utf8') + '\n.modal-overlay{background:rgba(15,23,42,.25)}body{font-family:Arial,"Microsoft YaHei",sans-serif}button,input,select,textarea{font:inherit}details>summary{cursor:pointer}' });
  await page.addScriptTag({ path: path.join(root, 'node_modules/vue/dist/vue.global.prod.js') });
  await page.addScriptTag({ path: path.join(root, 'public/components/system/knowledge-center-domain.js') });
  await page.evaluate(({ template, evidence }) => {
    const { ref, computed, createApp } = Vue;
    const state = {};
    const initial = { knowledgeCenterSelectedUnit: null, knowledgeCenterChunks: [], knowledgeCenterChunkForm: {}, knowledgeCenterFilter: {}, showKnowledgeCenterChunksModal: false, knowledgeCenterLoading: false, knowledgeCenterPagination: {}, knowledgeCenterUnits: [], selectedKnowledgeCenterUnitIds: [], knowledgeSopTaskCreatingChunkId: 0 };
    for (const [key, value] of Object.entries(initial)) state[key] = ref(value);
    let saved = false; window.requests = []; window.unexpectedRequests = []; window.simulateFailure = false; window.toasts = [];
    const helpers = { computed, requireSystemStatic: () => ({}), defaultKnowledgeCenterHotelId: () => 80, defaultKnowledgeExperienceChunk: () => '{}', formatKnowledgeJson: value => JSON.stringify(value, null, 2), knowledgeCenterDisplayLabel: value => value, captureAuthSession: () => 1, isAuthSessionCurrent: () => true, showToast: text => window.toasts.push(text),
      request: async (url, options) => {
        window.requests.push({ url, body: options ? JSON.parse(options.body) : null });
        const parsed = new URL(url, 'http://synthetic.invalid');
        const unitPath = '/knowledge/' + evidence.detail.unit.unit_id;
        const known = options
          ? parsed.pathname === unitPath + '/add-chunk' && options.method === 'POST' && parsed.searchParams.get('hotel_id') === '80'
          : parsed.pathname === '/knowledge/list' || (parsed.pathname === unitPath && parsed.searchParams.get('hotel_id') === '80');
        if (!known) { window.unexpectedRequests.push(url); throw new Error('Unexpected synthetic knowledge API: ' + url); }
        if (window.holdNextKnowledgeDetail && !options && parsed.pathname === unitPath) {
          window.holdNextKnowledgeDetail = false;
          await new Promise(resolve => { window.releaseKnowledgeDetail = resolve; });
        }
        if (window.simulateFailure) return { code: 500, msg: '隔离失败样本：请重试' };
        if (options) { saved = true; return { code: 0, data: evidence.save }; }
        if (url.includes('/list?')) return { code: 0, data: { list: [], pagination: {} } };
        const response = structuredClone(saved ? evidence.detail_after : evidence.detail);
        if (!url.includes('evaluate=1')) response.evaluation = null;
        return { code: 0, data: response };
      },
    };
    const methods = window.SUXI_KNOWLEDGE_CENTER_DOMAIN.create({ ...state, ...helpers });
    window.knowledgeUi = { state, methods };
    createApp({ template, setup: () => ({ ...state, ...helpers, ...methods }) }).mount('#app');
    methods.openKnowledgeChunks(evidence.detail.unit);
  }, { template, evidence });
  await page.getByRole('button', { name: '修改并保存新版本' }).waitFor();
  await page.getByRole('button', { name: '检查适用性与32题检索' }).click();
  await page.getByText(/已评估 32 个固定问题/).waitFor();
  await page.screenshot({ path: path.join(output, 'knowledge-desktop.png'), fullPage: true });
  await page.evaluate(() => { window.holdNextKnowledgeDetail = true; });
  await page.getByRole('button', { name: '检查适用性与32题检索' }).click();
  await page.waitForFunction(() => typeof window.releaseKnowledgeDetail === 'function');
  await page.locator('select').first().selectOption('ctrip');
  await page.evaluate(() => window.releaseKnowledgeDetail());
  await page.getByText('适用条件已变化，请重新核验；可重新检查。').waitFor();
  assert.equal(await page.getByRole('button', { name: '修改并保存新版本' }).count(), 0, 'Old applicability results must stay hidden after filter changes');
  await page.getByRole('button', { name: '检查适用性与32题检索' }).click();
  await page.getByRole('button', { name: '修改并保存新版本' }).waitFor();
  assert.equal(await page.evaluate(() => new URL(window.requests.at(-1).url, 'http://synthetic.invalid').searchParams.get('platform')), 'ctrip');
  await page.getByRole('button', { name: '修改并保存新版本' }).click();
  assert.match(await page.locator('textarea').inputValue(), /曝光下降/);
  await page.locator('textarea').fill(JSON.stringify({ text: '新版曝光规则需重新核验', source_refs: ['synthetic://revision'], platforms: ['ctrip'], valid_from: '2026-09-08', valid_until: '2026-10-08' }, null, 2));
  await page.getByRole('button', { name: '保存并精确回读' }).click();
  await page.getByText(/保存后重评/).waitFor();
  const submitted = await page.evaluate(() => window.requests.find(item => item.body));
  assert.equal(submitted.body.replaces_chunk_id, 101);
  assert.match(submitted.body.expected_digest, /^[a-f0-9]{64}$/);
  assert.ok(submitted.body.request_id);
  await page.getByText('已有新版本，旧版本仅供历史回读').waitFor();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: path.join(output, 'knowledge-mobile.png'), fullPage: true });
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'No horizontal page overflow');
  await page.evaluate(() => { window.simulateFailure = true; });
  await page.getByRole('button', { name: '检查适用性与32题检索' }).click();
  await page.getByText('隔离失败样本：请重试；可重新检查。').waitFor();
  await page.evaluate(() => { window.simulateFailure = false; });
  await page.getByRole('button', { name: '检查适用性与32题检索' }).click();
  await page.getByRole('button', { name: '修改并保存新版本' }).waitFor();
  assert.deepEqual(errors, []);
  assert.deepEqual(await page.evaluate(() => window.unexpectedRequests), []);
  fs.writeFileSync(path.join(output, 'ui-browser.json'), JSON.stringify({ status: 'pass', evidence: 'headless_isolated_vue_real_fragment_controller_response_fixture', assertions: ['detail', 'source_validity_version', '32_question_evaluation', 'changed_filter_discards_pending_results_and_refreshes', 'edit_revision_submission', 'exact_readback_notice', 'history_retained', '390px_no_horizontal_overflow', 'error_and_recovery', 'no_page_errors'], requests: await page.evaluate(() => window.requests), errors }, null, 2));
  console.log('PASS: isolated Vue knowledge dialog desktop/mobile, revision, evaluation, failure/recovery; no shared browser or service used.');
} finally { await browser.close(); }
