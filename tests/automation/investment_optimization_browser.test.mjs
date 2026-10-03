import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { chromium } from 'playwright';
import { compileFrontendTemplate } from '../../scripts/lib/frontend_template_build.mjs';

// Isolated synthetic browser acceptance. All requests stay in memory; no account or database is used.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const source = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const screenshotRoot = path.join(root, 'output/refinement/20261003/screenshots/investment');
const compiledPayback = () => {
    if (process.env.SUXIOS_INVESTMENT_PAYBACK_ARTIFACT === '1') return source('public/components/system/investment-payback.min.js');
    const text = source('public/components/system/investment-payback.js');
    const startMarker = '        template: `', closingMarker = '\n        `,';
    const start = text.indexOf(startMarker), end = text.indexOf(closingMarker, start + startMarker.length);
    assert.ok(start >= 0 && end > start);
    return text.slice(0, start) + `        render: (function(Vue){${compileFrontendTemplate(text.slice(start + startMarker.length, end))}})(Vue),` + text.slice(end + closingMarker.length);
};

test('rendered scenario confirms removal of known zero months and saves only the confirmed range', async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], network = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { network.push(route.request().url()); return route.abort(); });
    try {
        await page.setContent('<!doctype html><html lang="zh-CN"><body style="margin:0"><main style="padding:12px"><div id="app"></div></main></body></html>');
        await page.addStyleTag({ content: source('public/tailwind.min.css') });
        await page.addScriptTag({ content: source('public/vue.runtime.global.prod.js') });
        await page.addScriptTag({ content: source('public/components/system/investment-scenario.min.js') });
        await page.evaluate(() => {
            let current = { project_id: 1, project_version: 7, scenario_version: 1, readback: 'exact', content_digest: 'synthetic-1', result: null,
                input: { scenario_name: '合成验收情景', as_of: '2026-10-01', currency: 'CNY',
                    cash_plan: { start_month: '2026-10', months: 2, opening_liquidity: '100.00', source_label: '合成现金假设', loans: [],
                        monthly_inputs: [{ month: '2026-10', operating_net_cash: '100.00', capex_cash: '0.00', other_net_cash: '0.00' },
                            { month: '2026-11', operating_net_cash: '0.00', capex_cash: '0.00', other_net_cash: '0.00' }] } } };
            window.__scenarioWrites = [];
            const request = async (url, options) => {
                if (options?.method === 'POST') {
                    const body = JSON.parse(options.body);
                    window.__scenarioWrites.push(body);
                    current = { ...current, input: body.scenario, scenario_version: 2, project_version: 8, content_digest: 'synthetic-2' };
                }
                return { code: 200, data: JSON.parse(JSON.stringify(current)) };
            };
            window.__scenario = Vue.createApp(window.SUXI_SYSTEM_COMPONENTS.InvestmentScenarioWorkbench, {
                project: { id: 1, version: 7, hotel_id: 80, project_name: '合成验收项目' }, request,
            }).mount('#app');
        });
        await page.getByText('已存版本 1', { exact: false }).waitFor();
        const plan = page.getByTestId('scenario-cash-plan');
        await plan.locator('summary').click();
        await plan.getByLabel('计划月数', { exact: true }).fill('1');
        await plan.getByRole('button', { name: '生成月份，保留同月已填值', exact: true }).click();
        const prompt = page.getByTestId('scenario-draft-replacement');
        await prompt.waitFor();
        assert.match(await prompt.textContent(), /2026-11/);
        assert.equal(await page.evaluate(() => window.__scenario.form.cash_plan.monthly_inputs.length), 2);
        await page.getByTestId('scenario-keep-draft').click();
        assert.equal(await page.evaluate(() => window.__scenario.form.cash_plan.monthly_inputs[1].operating_net_cash), '0.00');
        await plan.getByRole('button', { name: '生成月份，保留同月已填值', exact: true }).click();
        for (const width of [1280, 320]) {
            await page.setViewportSize({ width, height: 900 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
            fs.mkdirSync(screenshotRoot, { recursive: true });
            await prompt.screenshot({ path: path.join(screenshotRoot, `month-removal-confirmation-${width}.png`) });
        }
        await page.getByTestId('scenario-confirm-replacement').click();
        assert.equal(await page.evaluate(() => window.__scenario.form.cash_plan.monthly_inputs.length), 1);
        await page.getByTestId('scenario-save').click();
        await page.getByTestId('scenario-notice').filter({ hasText: '精确回读' }).waitFor();
        const saved = await page.evaluate(() => ({ writes: window.__scenarioWrites, readback: window.__scenario.readback, name: window.__scenario.form.scenario_name }));
        assert.equal(saved.writes.length, 1);
        assert.equal(saved.writes[0].scenario.cash_plan.monthly_inputs.length, 1);
        assert.equal(saved.readback, 'exact');
        assert.equal(saved.name, '合成验收情景');
        assert.deepEqual(errors, []);
        assert.deepEqual(network, []);
    } finally { await browser.close(); }
});

test('rendered payback distinguishes a small positive duration from recovered and missing forecasts', async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], network = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { network.push(route.request().url()); return route.abort(); });
    try {
        await page.setContent('<!doctype html><html lang="zh-CN"><body style="margin:0"><main style="padding:12px"><div id="app"></div></main></body></html>');
        await page.addStyleTag({ content: source('public/tailwind.min.css') });
        await page.addScriptTag({ content: source('public/vue.runtime.global.prod.js') });
        await page.addScriptTag({ content: compiledPayback() });
        await page.evaluate(() => {
            const request = async url => ({ code: 200, data: url.includes('/projects/1?') ? {
                project: { id: 1, version: 1, project_name: '合成验收项目', investor_name: '合成投资主体', expected_monthly_amount: '30000.00' },
                entries: [], audit_history: [], summary: { as_of: new URL(url, 'https://synthetic.invalid').searchParams.get('as_of'), state: 'unrecovered', invested_amount: '500.00', net_recovered_amount: '0.00', unrecovered_amount: '500.00',
                    data_quality: { history_complete: true, issues: [] }, forecast: { status: 'ready', remaining_months: 0, remaining_months_exact: 1 / 60, whole_months: 1, payback_month: '2026-11' } },
            } : { list: [], pagination: { total: 0 } } });
            window.__payback = Vue.createApp(window.SUXI_SYSTEM_COMPONENTS.InvestmentPaybackBody, { request, hotels: [] }).mount('#app');
        });
        await page.evaluate(() => window.__payback.selectProject(1));
        const details = page.locator('details').filter({ has: page.locator('input[name^="forecastForm-amount-"]') });
        await details.locator('summary').click();
        await page.getByText('少于0.1个月', { exact: false }).waitFor();
        assert.match(await details.textContent(), /少于0\.1个月，按整月收回需\s*1\s*个月/);
        assert.doesNotMatch(await details.textContent(), /约\s*0\s*个月/);
        for (const width of [1280, 320]) {
            await page.setViewportSize({ width, height: 900 });
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1));
            fs.mkdirSync(screenshotRoot, { recursive: true });
            await details.screenshot({ path: path.join(screenshotRoot, `small-positive-payback-${width}.png`) });
        }
        await page.evaluate(() => { delete window.__payback.detail.summary.forecast.remaining_months_exact; });
        await page.getByText('少于0.1个月', { exact: false }).waitFor();
        await page.evaluate(() => { window.__payback.detail.summary.forecast = { status: 'already_recovered', remaining_months: null }; });
        await page.getByText('当前已收回全部已投入资金', { exact: false }).waitFor();
        assert.equal(await page.getByText('少于0.1个月', { exact: false }).count(), 0);
        await page.evaluate(() => { window.__payback.detail.summary.forecast = { status: 'missing_monthly', remaining_months: null }; });
        await page.getByText('待填写每月净收回', { exact: false }).waitFor();
        assert.deepEqual(errors, []);
        assert.deepEqual(network, []);
    } finally { await browser.close(); }
});
