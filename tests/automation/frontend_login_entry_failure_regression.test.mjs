import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = fs.readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const businessClosureLoader = fs.readFileSync(
  new URL('../../public/components/system/business-closure-loader.js', import.meta.url),
  'utf8',
);

const helperStart = appMain.indexOf('    const normalizeSuxiDomAttributeText = (value) => {');
const helperEnd = appMain.indexOf('\n    const requireSuxiAppRender', helperStart);
assert.ok(helperStart >= 0 && helperEnd > helperStart, 'DOM attribute normalizer must remain extractable');

const helperContext = {};
vm.runInNewContext(
  `${appMain.slice(helperStart, helperEnd)}\nthis.normalizeSuxiDomAttributeText = normalizeSuxiDomAttributeText;`,
  helperContext,
  { filename: 'normalize-suxi-dom-attribute-text.js' },
);

test('DOM attribute text normalization handles non-primitive startup data without throwing', () => {
  const normalize = helperContext.normalizeSuxiDomAttributeText;
  assert.equal(normalize(null), '');
  assert.equal(normalize('已更新'), '已更新');
  assert.equal(normalize(12), '12');

  const nullPrototypeValue = Object.create(null);
  nullPrototypeValue.reason = '待核验';
  assert.equal(normalize(nullPrototypeValue), '{"reason":"待核验"}');

  const circularNullPrototypeValue = Object.create(null);
  circularNullPrototypeValue.self = circularNullPrototypeValue;
  assert.equal(normalize(circularNullPrototypeValue), '内容格式异常');
});

test('login entry waits for deferred data-health helpers before restoring the saved fetch snapshot', () => {
  const restoreStart = appMain.indexOf('const restoreManualOneClickFetchSnapshot = () => {');
  const restoreEnd = appMain.indexOf('\n            const manualOneClickFetchScopeText', restoreStart);
  const restoreSource = appMain.slice(restoreStart, restoreEnd);

  assert.ok(restoreStart >= 0 && restoreEnd > restoreStart);
  assert.match(restoreSource, /const dataHealthStatic = window\.SUXI_DATA_HEALTH_STATIC;/);
  assert.match(restoreSource, /if \(typeof normalizeStoredRows !== 'function'\) return false;/);
  assert.match(restoreSource, /normalizeStoredRows\.call\(dataHealthStatic, snapshot\?\.rows\)/);
  assert.match(restoreSource, /watch\(dataHealthStaticVersion, restoreManualOneClickFetchSnapshot, \{ immediate: true \}\);/);
  assert.doesNotMatch(restoreSource, /^\s*restoreManualOneClickFetchSnapshot\(\);\s*$/m);
});

test('AI workbench title sources are normalized before Vue patches DOM attributes', () => {
  assert.match(appMain, /note: normalizeSuxiDomAttributeText\(node\?\.note\)/);
  assert.match(appMain, /reason: normalizeSuxiDomAttributeText\(cohort\?\.reason\)/);
  assert.match(appMain, /fullDetail: normalizeSuxiDomAttributeText\(card\?\.fullDetail \|\| card\?\.detail\)/);
  assert.match(appMain, /fullDetail: normalizeSuxiDomAttributeText\(review\?\.fullDetail \|\| review\?\.detail\)/);
});

test('business closure async placeholders do not fall through the root ctx proxy to a DOM attribute', () => {
  const loadingStart = businessClosureLoader.indexOf('const loadingComponent = {');
  const errorStart = businessClosureLoader.indexOf('const errorComponent = {');
  const definitionsStart = businessClosureLoader.indexOf('const definitions = [');
  assert.ok(loadingStart >= 0 && errorStart > loadingStart && definitionsStart > errorStart);
  assert.match(businessClosureLoader.slice(loadingStart, errorStart), /inheritAttrs: false/);
  assert.match(businessClosureLoader.slice(errorStart, definitionsStart), /inheritAttrs: false/);
});
