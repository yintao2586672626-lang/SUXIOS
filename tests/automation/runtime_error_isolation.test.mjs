import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const html = readFrontendContractSource();
const publicEntryGuard = fs.readFileSync(new URL('../../scripts/verify_public_entry_guard.mjs', import.meta.url), 'utf8');

test('page runtime errors recover to a safe page instead of replacing the whole app', () => {
  assert.match(html, /let recoverSuxiRuntimeError = null;/);
  assert.match(html, /recoverSuxiRuntimeError = \(\{ error, info \}\) => \{/);
  assert.match(html, /currentPage\.value = 'compass'/);
  assert.match(html, /当前功能发生异常，已返回今日经营看板/);

  const handlerStart = html.indexOf('app.config.errorHandler = (error, _instance, info) => {');
  const handlerEnd = html.indexOf("app.config.globalProperties.aiModelConfigText", handlerStart);
  const handler = html.slice(handlerStart, handlerEnd);

  assert.ok(handlerStart > 0 && handlerEnd > handlerStart);
  assert.match(handler, /const recovered = typeof recoverSuxiRuntimeError === 'function'/);
  assert.match(handler, /if \(recovered\) return;/);
  assert.match(handler, /scheduleSuxiStartupError\(error\);/);
  assert.ok(handler.indexOf('if (recovered) return;') < handler.indexOf('scheduleSuxiStartupError(error);'));
  assert.doesNotMatch(handler, /renderSuxiStartupError\(error\);/);
  const recoveryStart = html.indexOf('const scheduleSuxiStartupError = (error) => {');
  const recoveryEnd = html.indexOf('const operatingIntelligenceComponents =', recoveryStart);
  const recovery = html.slice(recoveryStart, recoveryEnd);
  const unmountCatchStart = recovery.indexOf('} catch (unmountError) {');
  const unmountCatchEnd = recovery.indexOf('}', unmountCatchStart);

  assert.ok(recoveryStart > 0 && recoveryEnd > recoveryStart);
  assert.match(recovery, /const appToUnmount = suxiApp;\s*suxiApp = null;/);
  assert.match(recovery, /appToUnmount\?\.unmount\(\);/);
  assert.doesNotMatch(recovery.slice(unmountCatchStart, unmountCatchEnd), /\breturn\b/);
  assert.ok(recovery.indexOf('renderSuxiStartupError(error);') > unmountCatchEnd);
});

test('development and production Vue error information use the same recovery classes', () => {
  assert.match(html, /app unmount cleanup function\|#runtime-\(\?:0\|10\|11\|16\)\\b/);
  assert.match(html, /scheduler flush\|#runtime-\(\?:1\|14\|15\)\\b/);
  assert.match(html, /if \(isFatalStartupError\) return false;/);
});

test('public entry guard delegates runtime behavior to the AST contract instead of exact source fragments', () => {
  assert.match(publicEntryGuard, /inspectPublicEntryRuntimeContracts/);
  assert.doesNotMatch(publicEntryGuard, /content\.includes\('const isFatalStartupError/);
  assert.doesNotMatch(publicEntryGuard, /content\.includes\("currentPage\.value = 'compass';"\)/);
});
