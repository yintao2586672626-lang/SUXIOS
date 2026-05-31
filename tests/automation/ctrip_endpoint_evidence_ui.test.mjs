import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync('public/index.html', 'utf8');

test('Ctrip endpoint evidence UI can explicitly save catalog preview standard rows', () => {
  assert.match(html, /saveStandardRows:\s*false/);
  assert.match(html, /v-model="ctripEndpointEvidenceForm\.saveStandardRows"/);
  assert.match(html, /save_standard_rows:\s*Boolean\(form\.saveStandardRows\)/);
  assert.match(html, /system_hotel_id:\s*autoFetchHotelId\.value \|\| user\.value\?\.hotel_id \|\| null/);
  assert.match(html, /catalog_preview_import/);
  assert.match(html, /saved_count/);
});

test('legacy Ctrip capture settings links to the endpoint evidence workflow', () => {
  assert.match(html, /转到接口证据校验/);
  assert.match(html, /currentPage = 'online-data'; onlineDataTab = 'platform-auto'; loadAutoFetchPanel\(\)/);
});

test('data health UI surfaces Ctrip capture catalog and live capture gate separately', () => {
  assert.match(html, /collectionReliability\.value\?\.ctrip_capture_catalog/);
  assert.match(html, /collectionHealthCtripCatalog/);
  assert.match(html, /携程字段目录/);
  assert.match(html, /真实抓取门禁/);
  assert.match(html, /capture_gate_status/);
  assert.match(html, /auth_status/);
  assert.match(html, /is_live_capture_ready/);
  assert.match(html, /standard_row_count/);
  assert.match(html, /采集缺口/);
  assert.match(html, /capture_gap_status/);
  assert.match(html, /capture_gap_missing_formal_endpoint_count/);
  assert.match(html, /capture_gap_missing_field_count/);
  assert.match(html, /capture_gap_next_actions/);
  assert.match(html, /下一步抓取动作/);
});

test('Ctrip Profile UI exposes login-only preparation before capture', () => {
  assert.match(html, /只登录并保存 Profile/);
  assert.match(html, /runCtripBrowserCapture\(\{ loginOnly:\s*true \}\)/);
  assert.match(html, /login_only:\s*Boolean\(options\.loginOnly\)/);
  assert.match(html, /Profile 登录已保存/);
});
