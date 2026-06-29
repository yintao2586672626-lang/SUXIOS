import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');

const sliceBetween = (source, startText, endText) => {
  const start = source.indexOf(startText);
  assert.ok(start >= 0, `missing start marker: ${startText}`);
  const end = source.indexOf(endText, start);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const flowPanel = sliceBetween(
  html,
  'data-testid="platform-profile-p0-flow"',
  'platformCollectionStatusError'
);
const failureMapper = sliceBetween(
  html,
  'const platformCollectionFailureReasonText = (reason, row = null) => {',
  'const platformCollectionFailureReasonClass = (reason, row = null) => {'
);
const flowBuilder = sliceBetween(
  html,
  'const platformProfileFlowRows = computed(() => {',
  'const meituanPlatformProfileStatusRow = computed'
);

test('OTA platform status page exposes the P0 Profile login flow without credential custody', () => {
  assert.match(flowPanel, /Profile 主线，不托管 OTA 账号密码/);
  assert.match(flowPanel, /目标日期真实入库/);
  assert.match(flowPanel, /platformProfileFlowRows/);
  assert.match(flowPanel, /platformProfileFlowStepClass/);
  assert.match(flowPanel, /platformProfileFlowStepDotClass/);
  assert.doesNotMatch(flowPanel, /ctripPassword|meituanPassword|operator-request|full-phone|hasAppSession|App 会话/);
});

test('OTA platform failure reasons are mapped to user-visible blockers', () => {
  assert.match(html, /platformCollectionFailureReasonText\(row\.failureReason, row\)/);
  assert.match(html, /platformCollectionFailureReasonClass\(row\.failureReason, row\)/);
  for (const marker of [
    'sync_completed_without_saved_rows',
    'no_collected_ota_rows',
    'captcha_required',
    'sms_code_required',
    'slider_requires_manual',
    'human_verification_required',
    'login_expired',
    'missing_profile',
    'field_missing',
    'browser_runtime_error',
    'platform_api_error',
  ]) {
    assert.match(failureMapper, new RegExp(marker), `failure mapper must handle ${marker}`);
  }
  for (const text of [
    '同步完成但没有真实入库数据',
    '目标日期无入库行',
    '验证码或短信未完成',
    '人机验证未完成',
    '缺少浏览器 Profile',
    '登录态或授权已失效',
    '字段缺失或未解析到业务行',
    '浏览器运行环境异常',
    '平台接口返回异常',
  ]) {
    assert.match(failureMapper, new RegExp(text), `failure mapper must display ${text}`);
  }
});

test('OTA platform Profile flow uses login-state and target-date evidence as the closure standard', () => {
  assert.match(flowBuilder, /platformCollectionStatusRows\.value/);
  assert.match(flowBuilder, /platformProfileStatusRows\.value/);
  for (const label of ['打开平台登录', '等待用户验证', '确认登录态', '同步目标日期数据', '验证数据完整性']) {
    assert.match(flowBuilder, new RegExp(label), `missing P0 flow label: ${label}`);
  }
  assert.match(flowBuilder, /manual_login_state_verified/);
  assert.match(flowBuilder, /const collectionDone = collectionStatus === 'collected'/);
  assert.match(flowBuilder, /storedRows > 0/);
  assert.match(flowBuilder, /targetDateText/);
  assert.doesNotMatch(flowBuilder, /ctripPassword|meituanPassword|operator-request|full-phone|hasAppSession|App 会话/);
});
