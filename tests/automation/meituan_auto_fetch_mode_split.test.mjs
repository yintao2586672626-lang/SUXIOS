import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const controller = readFileSync('app/controller/concern/AutoFetchConcern.php', 'utf8');
const page = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const panels = readFileSync('public/components/online-data/platform-auto-settings-panels.js', 'utf8');

test('manual Meituan auto-fetch uses the stored Cookie/API path', () => {
  assert.match(appMain, /const buildManualAutoFetchModePayload = \(\) => \(\{[\s\S]*?meituan_auto_fetch_mode:\s*'cookie_config'/);
  assert.match(appMain, /buildModePayload:\s*buildManualAutoFetchModePayload/);
  assert.match(appMain, /retry-auto-fetch[\s\S]*?\.\.\.buildManualAutoFetchModePayload\(\)/);
  assert.match(page, /立即采集＝已保存 Cookie\/API 直连/);
});

test('scheduled Meituan auto-fetch is persisted as Profile mode without silent fallback', () => {
  assert.match(appMain, /const buildScheduledAutoFetchModePayload = \(\) => \(\{[\s\S]*?meituan_auto_fetch_mode:\s*'profile_browser'/);
  assert.match(appMain, /set-fetch-schedule[\s\S]*?\.\.\.buildScheduledAutoFetchModePayload\(\)/);
  assert.match(appMain, /toggle-auto-fetch[\s\S]*?\.\.\.buildScheduledAutoFetchModePayload\(\)/);
  assert.match(controller, /if \(\$enabled && \$this->hasMeituanFetchConfigForHotel\(\(int\)\$hotelId\)\) \{\s*\$status\['meituan_auto_fetch_mode'\] = 'profile_browser';/);
  assert.match(controller, /if \(\$this->hasMeituanFetchConfigForHotel\(\(int\)\$hotelId\)\) \{[\s\S]*?\$status\['meituan_auto_fetch_mode'\] = 'profile_browser';/);
  assert.match(page, /定时任务未绑定或登录失效时会明确阻塞，不回退到旧数据/);
  assert.match(panels, /美团定时任务固定使用已绑定 Profile/);
});
