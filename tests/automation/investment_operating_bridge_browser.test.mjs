import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

// Synthetic acceptance of the generated production component, without a live
// account, shared browser profile, API call or financial write.
const source = path => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

test('generated finance page reads investor cash, hides stale scope and opens the ledger', async () => {
    const browser = await chromium.launch({ headless: true });
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const errors = [], network = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => { network.push(route.request().url()); return route.abort(); });
    try {
        await page.setContent('<!doctype html><html lang="zh-CN"><body style="margin:0"><main style="padding:12px"><div id="app"></div></main></body></html>');
        await page.addStyleTag({ content: source('public/tailwind.min.css') });
        await page.addStyleTag({ content: source('public/style.min.css') });
        await page.addScriptTag({ content: source('public/vue.runtime.global.prod.js') });
        await page.addScriptTag({ content: source('public/components/system/operating-finance-control-center.min.js') });
        await page.evaluate(() => {
            window.__cashMode = 'ready';
            window.__requests = [];
            window.__ledgerOpened = 0;
            window.__selectedHotel = Vue.ref(80);
            window.__releaseCash = null;
            const request = async url => {
                if (!String(url).startsWith('/operating-finance/overview?')) throw new Error('Financial writes are forbidden in this fixture');
                window.__requests.push(String(url));
                const query = new URL(String(url), 'http://synthetic.test').searchParams;
                const hotel = Number(query.get('hotel_id'));
                if (window.__cashMode === 'deferred') await new Promise(resolve => { window.__releaseCash = resolve; });
                const amounts = { actual_invested: '10000.00', net_actual_recovered: '3000.00', unrecovered: '7000.00', excess_return: '0.00' };
                let bridge = { contract_version: 'investment_operating_bridge.v1', tenant_id: 7, hotel_id: hotel, period_month: query.get('period_month'), effective_as_of: '2026-10-02', requested_period_end: '2026-10-31', cutoff_status: 'current_month_to_date', status: 'ready', totals: amounts,
                    projects: [{ project_id: 1, project_name: '合成验收项目', investor_name: '合成投资主体', history_complete: true, scope_compatible: true, amounts }] };
                if (window.__cashMode === 'partial') bridge = { ...bridge, status: 'partial', totals: null, recorded_totals: amounts, projects: [{ ...bridge.projects[0], history_complete: false }] };
                if (window.__cashMode === 'missing') bridge = { ...bridge, status: 'missing', totals: null, projects: [] };
                if (window.__cashMode === 'blocked') bridge = { ...bridge, status: 'blocked', reason_code: 'investment_view_permission_required', totals: null, projects: null };
                return { code: 200, data: { contract_version: 'operating_finance_control_center.v1', tenant_id: 7, hotel_id: hotel, period_month: query.get('period_month'), investment_bridge: bridge, monthly_finance: { status: 'missing' }, portfolio: { status: 'missing', items: [] }, boundaries: { external_write_count: 0 } } };
            };
            Vue.createApp({ render() { return Vue.h(window.SUXI_SYSTEM_COMPONENTS.OperatingFinanceControlCenterBody, {
                hotels: [{ id: 80, tenant_id: 7, name: '合成酒店甲' }, { id: 81, tenant_id: 7, name: '合成酒店乙' }], selectedHotelId: window.__selectedHotel.value,
                request, canExecute: false, 'onUpdate:selectedHotelId': value => { window.__selectedHotel.value = Number(value); },
                onOpenInvestmentLedger: () => { window.__ledgerOpened++; },
            }); } }).mount('#app');
        });
        await page.getByTestId('operating-finance-tab-finance').click();
        await page.waitForFunction(() => document.querySelector('[data-metric="actual_invested"]')?.textContent.includes('10,000.00'));
        const panel = page.getByTestId('investment-operating-bridge');
        assert.match(await panel.textContent(), /累计实际投入[\s\S]*10,000\.00/);
        assert.match(await panel.textContent(), /资金来源为人工台账，未独立核验/);
        await panel.getByRole('button', { name: '打开投资回本台账' }).click();
        assert.equal(await page.evaluate(() => window.__ledgerOpened), 1);

        await page.evaluate(() => { window.__cashMode = 'partial'; });
        await page.getByTestId('operating-finance-refresh').click();
        await page.getByText('以上仅为已录入记录的部分合计，不能认定真实累计回款完整。').waitFor();
        assert.match(await panel.textContent(), /账目核对不完整/);

        await page.evaluate(() => { window.__cashMode = 'deferred'; window.__selectedHotel.value = 81; });
        await page.getByText('正在读取当前酒店、账期的资金台账…').waitFor();
        assert.equal(await panel.locator('[data-metric]').count(), 0, 'Previous hotel totals must disappear while a new scope loads');
        await page.evaluate(() => { window.__cashMode = 'missing'; window.__releaseCash(); });
        await page.getByTestId('investment-bridge-scope').filter({ hasText: '酒店 #81' }).waitFor();
        assert.equal(await panel.locator('[data-metric]').count(), 0);

        await page.evaluate(() => { window.__cashMode = 'blocked'; });
        await page.getByTestId('operating-finance-refresh').click();
        await page.getByText('当前账号没有该酒店的投资查看权限，资金台账保持隐藏。').waitFor();
        assert.equal(await panel.getByRole('button', { name: '打开投资回本台账' }).isDisabled(), true);
        assert.equal(await panel.locator('[data-metric]').count(), 0);
        await page.setViewportSize({ width: 320, height: 734 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1);
        await page.getByTestId('operating-finance-tab-portfolio').click();
        await panel.waitFor();
        assert.equal(await page.evaluate(() => window.__requests.every(url => url.startsWith('/operating-finance/overview?'))), true);
        assert.deepEqual(errors, []);
        assert.deepEqual(network, []);
    } finally {
        await browser.close();
    }
});
