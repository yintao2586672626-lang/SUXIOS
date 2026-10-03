import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { chromium } from 'playwright';

const main = readFileSync('public/app-main.js', 'utf8');
const helper = readFileSync('public/form-operation-support.js', 'utf8');
const vue = readFileSync('public/vue.global.prod.js', 'utf8');
const start = main.indexOf('const loadFormOperationSupport = () => {');
const end = main.indexOf('const clearFormOperationSupportLoadTimer', start);
assert.ok(start >= 0 && end > start, 'formal application draft loader must be available');

async function fixture(t) {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // A fresh browser and an in-memory store keep this fixture away from real
  // accounts, storage, the running application and external services.
  await page.route('**/*', route => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/form-operation-support.js') {
      return route.fulfill({ contentType: 'text/javascript; charset=utf-8', body: helper });
    }
    if (pathname !== '/') return route.abort();
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html>
      <div id="app"><form data-form-key="business-form">
        <input name="description" aria-label="business description"></form>
        <div id="teleport-host"></div></div>` });
  });
  await page.addInitScript(() => {
    const drafts = new Map([
      ['suxios.form.draft.v1:business-form', JSON.stringify({ description: 'other hotel draft' })],
      ['suxios.form.draft.v1:teleport-form', JSON.stringify({ description: 'other user dialog draft' })],
    ]);
    window.__draftCalls = { reads: 0, writes: 0 };
    Object.defineProperty(window, 'localStorage', { configurable: true, value: {
      getItem: key => { window.__draftCalls.reads += 1; return drafts.get(key) ?? null; },
      setItem: (key, value) => { window.__draftCalls.writes += 1; drafts.set(key, value); },
      removeItem: key => drafts.delete(key),
    } });
    window.__fixtureDraft = key => drafts.get(key);
  });
  await page.goto('http://127.0.0.1:19991/');
  return { page, errors };
}

test('formal loader blocks drafts for app forms and real Vue body Teleport after clear and mutation', {
  timeout: 30_000,
}, async t => {
  const { page, errors } = await fixture(t);
  await page.addScriptTag({ content: `
    const formOperationSupportScript = 'form-operation-support.js';
    const formOperationSupportScriptVersion = 'synthetic-fixture';
    let formOperationSupportLoadPromise = null;
    ${main.slice(start, end)}
    window.__formalDraftLoader = loadFormOperationSupport;
  ` });
  await page.evaluate(() => window.__formalDraftLoader());
  const form = page.getByRole('textbox', { name: 'business description', exact: true });
  assert.equal(await form.inputValue(), '');
  await page.addScriptTag({ content: vue });
  await page.evaluate(() => {
    Vue.createApp({ template: '<teleport to="body"><form data-form-key="teleport-form"><input name="description" aria-label="dialog description"></form></teleport>' }).mount('#teleport-host');
  });
  const dialog = page.getByRole('textbox', { name: 'dialog description', exact: true });
  assert.equal(await dialog.evaluate(field => field.closest('#app')), null);
  assert.equal(await dialog.inputValue(), '');
  for (const field of [form, dialog]) {
    await field.fill('current hotel edit');
    await field.fill('');
    await field.evaluate(node => node.closest('form').appendChild(document.createElement('span')));
    await page.evaluate(() => window.__formalDraftLoader());
    assert.equal(await field.inputValue(), '');
  }
  assert.deepEqual(await page.evaluate(() => window.__draftCalls), { reads: 0, writes: 0 });
  assert.deepEqual(errors, []);
});

test('standalone generic helper keeps its original restore and save behavior', { timeout: 30_000 }, async t => {
  const { page, errors } = await fixture(t);
  await page.addScriptTag({ content: helper });
  const field = page.getByRole('textbox', { name: 'business description', exact: true });
  assert.equal(await field.inputValue(), 'other hotel draft');
  await field.fill('standalone current draft');
  assert.equal(await page.evaluate(() => JSON.parse(window.__fixtureDraft('suxios.form.draft.v1:business-form')).description), 'standalone current draft');
  const calls = await page.evaluate(() => window.__draftCalls);
  assert.ok(calls.reads > 0 && calls.writes > 0);
  assert.deepEqual(errors, []);
});
