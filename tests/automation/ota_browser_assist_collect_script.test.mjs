import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/ota-browser-assist-static.js', 'utf8');

const context = {
  window: {},
  globalThis: {},
};
context.globalThis = context;
vm.createContext(context);
vm.runInContext(source, context);

const helper = context.window.SUXI_OTA_BROWSER_ASSIST_STATIC;

test('OTA browser assist static helper exposes the collector script generator', () => {
  assert.equal(typeof helper, 'object');
  assert.equal(helper.CONTRACT_VERSION, 'ota_browser_assist_collection_contract.v1');
  assert.equal(helper.COLLECTION_MODE, 'browser_assist_dom');
  assert.equal(typeof helper.buildOtaBrowserAssistCollectorScript, 'function');
});

test('generated collector script produces supplemental OTA contract JSON without credential access', () => {
  const script = helper.buildOtaBrowserAssistCollectorScript();

  for (const marker of [
    '// ==UserScript==',
    '@match        https://ebooking.ctrip.com/*',
    '@match        https://me.meituan.com/*',
    '@match        https://eb.meituan.com/*',
    'source_contract',
    'ota_browser_assist_collection_contract.v1',
    'collection_mode',
    'browser_assist_dom',
    'ctripStats',
    'meituanStats',
    'warnings',
    'selector_not_found',
    'metrics_not_found',
    'navigator.clipboard.writeText',
    'platformIdentity',
    'partnerId',
    'poiId',
    'performance.getEntriesByType',
    '实时访客量',
    '曝光人数',
    '剩余',
  ]) {
    assert.match(script, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `missing marker: ${marker}`);
  }

  for (const forbidden of [
    /document\.cookie/i,
    /localStorage/i,
    /sessionStorage/i,
    /fetch\s*\(/i,
    /XMLHttpRequest/i,
    /password/i,
    /authorization/i,
    /token/i,
  ]) {
    assert.doesNotMatch(script, forbidden, `generated collector must not include ${forbidden}`);
  }
});
