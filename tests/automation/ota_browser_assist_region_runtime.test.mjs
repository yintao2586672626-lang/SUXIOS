import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

import { chromium } from 'playwright';

const helperSource = readFileSync('public/ota-browser-assist-static.js', 'utf8');
const helperContext = {
  window: {},
  globalThis: {},
};
helperContext.globalThis = helperContext;
vm.createContext(helperContext);
vm.runInContext(helperSource, helperContext);

const collectorScript = helperContext.window.SUXI_OTA_BROWSER_ASSIST_STATIC
  .buildOtaBrowserAssistCollectorScript();

const readCapture = async (page) => {
  const output = await page.locator('#suxi-ota-browser-assist-output').inputValue();
  return JSON.parse(output);
};

test('region capture reads only the selected visible area and fails closed when selection is missing or stale', {
  timeout: 30_000,
}, async (t) => {
  const browser = await chromium.launch({ headless: true });
  t.after(async () => browser.close());

  const page = await browser.newPage();
  await page.route('https://ebooking.ctrip.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>携程指标测试页</title></head>
          <body style="font-family:sans-serif">
            <section data-testid="unrelated-region" style="width:360px;height:80px">
              实时访客量 999　竞争圈平均 888
            </section>
            <section data-testid="target-wrapper" style="width:360px;height:140px;margin-top:20px">
              实时访客量 41　竞争圈平均 43
              <div data-testid="target-region" style="width:320px;height:60px">
                实时访客量 23　竞争圈平均 31
              </div>
            </section>
            <section data-testid="second-target" style="width:360px;height:80px;margin-top:20px">
              实时访客量 47　竞争圈平均 53
            </section>
          </body>
        </html>`,
    });
  });
  await page.goto('https://ebooking.ctrip.com/suxios-region-fixture');
  await page.addScriptTag({ content: collectorScript });

  const captureRegionButton = page.getByRole('button', {
    name: '采集圈选区域',
    exact: true,
  });

  await captureRegionButton.click();
  const missingSelection = await readCapture(page);
  assert.equal(missingSelection.capture_scope, 'region');
  assert.equal(missingSelection.target_region.status, 'not_selected');
  assert.equal('ctripStats' in missingSelection, false);
  assert.equal(missingSelection.warnings[0].code, 'target_region_not_selected');

  await page.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await page.locator('[data-testid="target-region"]').click({ position: { x: 8, y: 8 } });
  await page.locator('#suxi-ota-browser-assist-template').selectOption('metrics');
  await captureRegionButton.click();

  const selectedCapture = await readCapture(page);
  assert.equal(selectedCapture.capture_scope, 'region');
  assert.equal(selectedCapture.capture_template, 'metrics');
  assert.equal(selectedCapture.target_region.status, 'ready');
  assert.equal(selectedCapture.target_region.matchCount, 1);
  assert.equal(
    selectedCapture.ctripStats.metrics.ctrip.realtimeVisitors.value,
    '23',
  );
  assert.equal(
    selectedCapture.ctripStats.metrics.ctrip.visitorPeerAvg.value,
    '31',
  );
  assert.doesNotMatch(JSON.stringify(selectedCapture), /999|888/);

  await page.getByRole('button', { name: '扩大一级', exact: true }).click();
  await page.getByRole('button', { name: '复制JSON', exact: true }).click();
  const expandedCapture = await readCapture(page);
  assert.equal(
    expandedCapture.ctripStats.metrics.ctrip.realtimeVisitors.value,
    '41',
  );

  await page.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await page.locator('[data-testid="second-target"]').click({ position: { x: 8, y: 8 } });
  await page.getByRole('button', { name: '复制JSON', exact: true }).click();
  const reselectedCapture = await readCapture(page);
  assert.equal(
    reselectedCapture.ctripStats.metrics.ctrip.realtimeVisitors.value,
    '47',
  );
  assert.doesNotMatch(
    JSON.stringify(reselectedCapture),
    /"value":"(?:23|31|41|43|999|888)"/,
  );

  await page.locator('[data-testid="second-target"]').evaluate((node) => node.remove());
  await captureRegionButton.click();

  const staleSelection = await readCapture(page);
  assert.equal(staleSelection.target_region.status, 'stale');
  assert.equal('ctripStats' in staleSelection, false);
  assert.equal(staleSelection.warnings[0].code, 'target_region_stale');
  assert.doesNotMatch(JSON.stringify(staleSelection), /999|888/);
});

test('collector keeps platform identity, metric labels, evidence text, and inventory rows truthful', {
  timeout: 30_000,
}, async (t) => {
  const browser = await chromium.launch({ headless: true });
  t.after(async () => browser.close());

  const identityPage = await browser.newPage();
  await identityPage.route('https://me.meituan.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>美团身份测试页</title></head>
          <body>
            <div style="width:320px;height:80px">曝光人数 10</div>
            <img src="https://eb.meituan.com/api/poi-only?poiId=POI-B" alt="">
          </body>
        </html>`,
    });
  });
  await identityPage.route('https://eb.meituan.com/api/**', async (route) => {
    await route.fulfill({
      contentType: 'image/gif',
      body: Buffer.from('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', 'base64'),
    });
  });
  await identityPage.goto('https://me.meituan.com/suxios-identity-fixture?partnerId=PARTNER-A');
  await identityPage.addScriptTag({ content: collectorScript });
  await identityPage.getByRole('button', { name: '采集当前页（兼容）', exact: true }).click();

  const partialIdentityCapture = await readCapture(identityPage);
  assert.equal(partialIdentityCapture.platformIdentity.partnerId, 'PARTNER-A');
  assert.equal(partialIdentityCapture.platformIdentity.poiId, '');

  await Promise.all([
    identityPage.waitForResponse(/identity-complete/),
    identityPage.evaluate(() => {
      const image = document.createElement('img');
      image.src = 'https://eb.meituan.com/api/identity-complete?partnerId=PARTNER-C&poiId=POI-D';
      document.body.appendChild(image);
    }),
  ]);
  await identityPage.getByRole('button', { name: '采集当前页（兼容）', exact: true }).click();

  const completeIdentityCapture = await readCapture(identityPage);
  assert.equal(completeIdentityCapture.platformIdentity.partnerId, 'PARTNER-C');
  assert.equal(completeIdentityCapture.platformIdentity.poiId, 'POI-D');

  const ctripMetricPage = await browser.newPage();
  await ctripMetricPage.route('https://ebooking.ctrip.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>去哪儿指标测试页</title></head>
          <body>
            <section data-testid="qunar-target" style="width:360px;height:80px">
              去哪儿实时访客量 100　去哪儿竞争圈平均 80
            </section>
          </body>
        </html>`,
    });
  });
  await ctripMetricPage.goto('https://ebooking.ctrip.com/suxios-qunar-fixture');
  await ctripMetricPage.addScriptTag({ content: collectorScript });
  await ctripMetricPage.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await ctripMetricPage.locator('[data-testid="qunar-target"]').click({ position: { x: 8, y: 8 } });
  await ctripMetricPage.locator('#suxi-ota-browser-assist-template').selectOption('metrics');
  await ctripMetricPage.getByRole('button', { name: '采集圈选区域', exact: true }).click();

  const qunarCapture = await readCapture(ctripMetricPage);
  assert.equal('ctrip' in qunarCapture.ctripStats.metrics, false);
  assert.equal(qunarCapture.ctripStats.metrics.qunar.realtimeVisitors.value, '100');

  const meituanMetricPage = await browser.newPage();
  await meituanMetricPage.route('https://me.meituan.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>美团指标测试页</title></head>
          <body>
            <section data-testid="rate-target" style="width:360px;height:80px">
              曝光浏览转化率 12.5%　浏览支付转化率 6.5%
            </section>
          </body>
        </html>`,
    });
  });
  await meituanMetricPage.goto('https://me.meituan.com/suxios-rate-fixture');
  await meituanMetricPage.addScriptTag({ content: collectorScript });
  await meituanMetricPage.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await meituanMetricPage.locator('[data-testid="rate-target"]').click({ position: { x: 8, y: 8 } });
  await meituanMetricPage.locator('#suxi-ota-browser-assist-template').selectOption('metrics');
  await meituanMetricPage.getByRole('button', { name: '采集圈选区域', exact: true }).click();

  const meituanRateCapture = await readCapture(meituanMetricPage);
  assert.equal(meituanRateCapture.meituanStats.metrics.exposureUsers, null);
  assert.equal(meituanRateCapture.meituanStats.metrics.browseUsers, null);
  assert.equal(meituanRateCapture.meituanStats.metrics.exposureBrowseRate.value, '12.5');
  assert.equal(meituanRateCapture.meituanStats.metrics.browsePayRate.value, '6.5');

  const sensitivePage = await browser.newPage();
  await sensitivePage.route('https://ebooking.ctrip.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>敏感信息边界测试页</title></head>
          <body>
            <section aria-label="账号 token=SECRET_SELECTOR" style="width:360px;height:80px">
              实时访客量 23　token=SECRET_TEXT
            </section>
            <section data-testid="hidden-target" style="width:360px;height:80px">
              <span style="display:none">实时访客量 999 token=HIDDEN_SECRET</span>
            </section>
          </body>
        </html>`,
    });
  });
  await sensitivePage.goto('https://ebooking.ctrip.com/suxios-sensitive-fixture');
  await sensitivePage.addScriptTag({ content: collectorScript });
  await sensitivePage.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await sensitivePage.locator('[aria-label*="SECRET_SELECTOR"]').click({ position: { x: 8, y: 8 } });
  await sensitivePage.locator('#suxi-ota-browser-assist-template').selectOption('metrics');
  await sensitivePage.getByRole('button', { name: '采集圈选区域', exact: true }).click();

  const sensitiveCapture = await readCapture(sensitivePage);
  assert.equal(sensitiveCapture.ctripStats.metrics.ctrip.realtimeVisitors.value, '23');
  assert.doesNotMatch(JSON.stringify(sensitiveCapture), /SECRET_SELECTOR|SECRET_TEXT/);

  await sensitivePage.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await sensitivePage.locator('[data-testid="hidden-target"]').click({ position: { x: 8, y: 8 } });
  await sensitivePage.getByRole('button', { name: '采集圈选区域', exact: true }).click();

  const hiddenCapture = await readCapture(sensitivePage);
  assert.equal(hiddenCapture.target_region.status, 'empty');
  assert.equal('ctripStats' in hiddenCapture, false);
  assert.doesNotMatch(JSON.stringify(hiddenCapture), /999|HIDDEN_SECRET/);

  const inventoryPage = await browser.newPage();
  await inventoryPage.route('https://ebooking.ctrip.com/**', async (route) => {
    await route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html>
        <html lang="zh-CN">
          <head><title>房态去重测试页</title></head>
          <body>
            <section data-testid="inventory-target" class="room-list" style="width:360px;height:120px">
              <div class="room-row" style="width:320px;height:80px">
                <span class="room-name">高级大床房</span>
                <span data-date="2026-07-23">剩余 3</span>
              </div>
            </section>
          </body>
        </html>`,
    });
  });
  await inventoryPage.goto('https://ebooking.ctrip.com/suxios-inventory-fixture');
  await inventoryPage.addScriptTag({ content: collectorScript });
  await inventoryPage.getByRole('button', { name: '圈选目标区域', exact: true }).click();
  await inventoryPage.locator('[data-testid="inventory-target"]').click({ position: { x: 340, y: 100 } });
  await inventoryPage.locator('#suxi-ota-browser-assist-template').selectOption('inventory');
  await inventoryPage.getByRole('button', { name: '采集圈选区域', exact: true }).click();

  const inventoryCapture = await readCapture(inventoryPage);
  assert.equal(inventoryCapture.ctrip.rooms.length, 1, JSON.stringify(inventoryCapture));
  assert.equal(inventoryCapture.ctrip.rooms[0].name, '高级大床房');
  assert.equal(inventoryCapture.ctrip.rooms[0].days.length, 1);
});
