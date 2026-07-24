import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const controller = readFileSync('app/controller/concern/AutoFetchConcern.php', 'utf8');
const page = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const panels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');

test('manual dual-platform auto-fetch is isolated to the stored Cookie/API path', () => {
  assert.match(appMain, /const buildManualAutoFetchModePayload = \(\) => \(\{[\s\S]*?ctrip_auto_fetch_mode:\s*'cookie_config'/);
  assert.match(appMain, /const buildManualAutoFetchModePayload = \(\) => \(\{[\s\S]*?meituan_auto_fetch_mode:\s*'cookie_config'/);
  assert.match(appMain, /buildModePayload:\s*buildManualAutoFetchModePayload/);
  assert.match(appMain, /retry-auto-fetch[\s\S]*?\.\.\.buildManualAutoFetchModePayload\(\)/);
  assert.match(page, /本页仅用已保存 Cookie\/API 补采；Profile 采集请到“昨日经营闭环”/);
  assert.match(page, /data-testid="cookie-api-temporary-fetch"/);
});

test('scheduled Meituan auto-fetch is persisted as Profile mode without silent fallback', () => {
  assert.match(appMain, /const buildScheduledAutoFetchModePayload = \(\) => \(\{[\s\S]*?meituan_auto_fetch_mode:\s*'profile_browser'/);
  assert.match(appMain, /set-fetch-schedule[\s\S]*?\.\.\.buildScheduledAutoFetchModePayload\(\)/);
  assert.match(appMain, /toggle-auto-fetch[\s\S]*?\.\.\.buildScheduledAutoFetchModePayload\(\)/);
  assert.match(controller, /if \(\$enabled && \$this->hasMeituanFetchConfigForHotel\(\(int\)\$hotelId\)\) \{\s*\$status\['meituan_auto_fetch_mode'\] = 'profile_browser';/);
  assert.match(controller, /if \(\$this->hasMeituanFetchConfigForHotel\(\(int\)\$hotelId\)\) \{[\s\S]*?\$status\['meituan_auto_fetch_mode'\] = 'profile_browser';/);
  assert.match(page, /失败不回退旧数据/);
  assert.match(panels, /美团定时任务固定使用已绑定 Profile/);
});
