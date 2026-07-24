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
  assert.match(handler, /renderSuxiStartupError\(error\);/);
  assert.ok(handler.indexOf('if (recovered) return;') < handler.indexOf('renderSuxiStartupError(error);'));
});

test('setup failures remain explicit fatal startup failures', () => {
  assert.match(html, /const isFatalStartupError = \/setup function\|app errorHandler\|app warnHandler\|app unmount cleanup function\/i\.test\(String\(info \|\| ''\)\);/);
  assert.match(html, /if \(isFatalStartupError\) return false;/);
});

test('public entry guard locks the fatal classification and safe-page recovery contract', () => {
  assert.match(publicEntryGuard, /isFatalStartupError/);
  assert.match(publicEntryGuard, /currentPage\.value = 'compass'/);
  assert.match(publicEntryGuard, /当前功能发生异常，已返回今日经营看板/);
});
