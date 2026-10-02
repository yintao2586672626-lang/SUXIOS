import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';
import { chromium } from 'playwright';

// Isolated, unauthenticated browser. Only the standalone calculator is opened.
const root = fileURLToPath(new URL('../', import.meta.url));
const output = path.join(root, 'output/playwright/commission-calculator');
const sourceUrl = process.argv[2]
  ? pathToFileURL(path.resolve(process.argv[2])).href
  : new URL('../public/tools/佣金调整测算器.html', import.meta.url).href;
await mkdir(output, { recursive:true });
const browser = await chromium.launch({ headless:true, ...(process.argv[3] ? { channel:process.argv[3] } : {}) });
const errors = [];
const externalRequests = [];
try {
  const context = await browser.newContext({ viewport:{ width:1440,height:1100 }, acceptDownloads:true, offline:true });
  context.on('page', page => {
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('request', request => { if (/^https?:/.test(request.url())) externalRequests.push(request.url()); });
  });
  const page = await context.newPage();
  await page.goto(sourceUrl);
  assert.equal(await page.locator('#threshold-value').innerText(), '5.88');
  assert.equal(await page.locator('#minimum-nights').innerText(), '1,059');
  await page.screenshot({ path:path.join(output,'desktop.png'), fullPage:true });

  await page.locator('#cost-enabled').check();
  assert.equal(await page.locator('#error').isVisible(), true);
  assert.equal(await page.locator('#valid-result').isVisible(), false);
  assert.equal(await page.locator('#download').isDisabled(), true);
  await page.locator('#cost').fill('30');
  assert.equal(await page.locator('#threshold-value').innerText(), '6.67');
  assert.equal(await page.locator('#minimum-nights').innerText(), '1,067');
  await page.locator('#forecast').fill('1067');
  assert.match(await page.locator('#forecast-result').innerText(), /多 ¥75\.00/);
  await page.locator('#swap').click();
  assert.equal(await page.locator('#threshold-value').innerText(), '6.25');
  assert.equal(await page.locator('#minimum-nights').innerText(), '938');
  assert.match(await page.locator('#threshold-title').innerText(), /最多下降/);

  await page.locator('#price').fill('399.50');
  await page.locator('#old-rate').fill('12.3');
  await page.locator('#new-slider').fill('14.7');
  assert.equal(await page.locator('#new-rate').inputValue(), '14.7');
  assert.equal(await page.locator('#old-slider').inputValue(), '12.3');
  await page.getByRole('button', { name:'试算调整到11%', exact:true }).click();
  assert.equal(await page.locator('#new-rate').inputValue(), '11');
  await page.locator('#nights').fill('731');
  await page.locator('#forecast').fill('710');
  const expectedThreshold = await page.locator('#threshold-value').innerText();
  const expectedMinimum = await page.locator('#minimum-nights').innerText();
  const expectedForecast = await page.locator('#forecast-result').innerText();

  // Force permission failure: the fallback must expose selectable honest text.
  await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable:true, value:undefined }));
  await page.locator('#copy').click();
  assert.equal(await page.locator('#copy-fallback').isVisible(), true);
  const copied = await page.locator('#share-text').inputValue();
  assert.match(copied, /净贡献持平/);
  assert.match(copied, /399\.50/);
  assert.match(copied, /未扣除其他渠道转移损失/);
  await page.locator('#close-copy').click();

  const downloadPromise = page.waitForEvent('download');
  await page.locator('#download').click();
  const download = await downloadPromise;
  assert.equal(download.suggestedFilename(), '佣金调整测算器.html');
  const saved = path.join(output, download.suggestedFilename());
  await download.saveAs(saved);
  const reopened = await context.newPage();
  await reopened.goto(pathToFileURL(saved).href);
  assert.equal(await reopened.locator('#price').inputValue(), '399.50');
  assert.equal(await reopened.locator('#nights').inputValue(), '731');
  assert.equal(await reopened.locator('#cost-enabled').isChecked(), true);
  assert.equal(await reopened.locator('#cost').inputValue(), '30');
  assert.equal(await reopened.locator('#old-rate').inputValue(), '12.3');
  assert.equal(await reopened.locator('#new-rate').inputValue(), '11');
  assert.equal(await reopened.locator('#threshold-value').innerText(), expectedThreshold);
  assert.equal(await reopened.locator('#minimum-nights').innerText(), expectedMinimum);
  assert.equal(await reopened.locator('#forecast-result').innerText(), expectedForecast);
  await reopened.locator('#new-rate').fill('15.1');
  assert.equal(await reopened.locator('#error').isVisible(), true);
  await reopened.locator('#reset').click();
  assert.equal(await reopened.locator('#cost-enabled').isChecked(), false);
  assert.equal(await reopened.locator('#cost').inputValue(), '');
  assert.equal(await reopened.locator('#threshold-value').innerText(), '5.88');

  // Verify both share-summary modes, not just the calculation underneath.
  await reopened.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable:true, value:undefined }));
  await reopened.locator('#copy').click();
  assert.match(await reopened.locator('#share-text').inputValue(), /未计成本，非真实保本线/);
  await reopened.locator('#close-copy').click();
  for (const width of [390,320,768]) {
    await reopened.setViewportSize({ width,height:844 });
    const fits = await reopened.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);
    assert.equal(fits, true, 'horizontal overflow at ' + width);
  }
  await reopened.setViewportSize({ width:390,height:844 });
  await reopened.locator('#cost-enabled').check();
  await reopened.locator('#cost').fill('30');
  assert.equal(await reopened.locator('#mobile-summary').isVisible(), true);
  assert.match(await reopened.locator('#mobile-threshold').innerText(), /6\.67%/);
  await reopened.locator('#mobile-summary').click();
  await reopened.locator('#toast').waitFor({ state:'hidden' });
  await reopened.screenshot({ path:path.join(output,'mobile-result.png') });
  await reopened.screenshot({ path:path.join(output,'mobile.png'), fullPage:true });
  const previewContext = await browser.newContext({ javaScriptEnabled:false, offline:true, viewport:{ width:1440,height:1100 } });
  const preview = await previewContext.newPage();
  await preview.goto(sourceUrl);
  await preview.screenshot({ path:path.join(output,'static-preview.png'), fullPage:true });
  assert.equal(await preview.locator('#threshold-value').innerText(), '5.88', 'static preview must not lose saved numbers');
  assert.equal(await preview.locator('#startup-notice').isVisible(), true);
  assert.equal(await preview.locator('#price').isDisabled(), true);
  assert.equal(await preview.locator('#cost-enabled').isDisabled(), true);
  assert.equal(await preview.locator('#forecast').isDisabled(), true);
  assert.equal(await preview.locator('#download').isDisabled(), true);
  await preview.goto(pathToFileURL(saved).href);
  assert.equal(await preview.locator('#threshold-value').innerText(), expectedThreshold);
  assert.equal(await preview.locator('#minimum-nights').innerText(), expectedMinimum);
  assert.equal(await preview.locator('#startup-notice').isVisible(), true);
  assert.equal(await preview.locator('#price').isDisabled(), true);
  assert.equal(await preview.locator('#forecast').isDisabled(), true);
  await previewContext.close();
  assert.deepEqual(errors, []);
  assert.deepEqual(externalRequests, []);
  console.log(JSON.stringify({ status:'passed', channel:process.argv[3] || 'chromium', checks:['offline file open','up/down/same basis','decimal price and rates','slider sync','table selection','invalid input fail-closed','forecast','copy fallback and scope','HTML download and exact reopen','reset','320/390/768px no overflow','static preview retains numbers and locks input','exported static snapshot retains current values','zero console errors','zero network requests'], screenshots:output }, null, 2));
} finally {
  await browser.close();
}
