import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const read = path => readFileSync(path, 'utf8');
const page = read('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html');
const appMain = readAppMainContractSource();
const service = read('app/service/PmsRealtimeSyncService.php');

test('PMS page separates saved readback from explicit realtime synchronization', () => {
  assert.match(page, /data-testid="pms-operating-data-load"[\s\S]*读取已保存数据/);
  assert.match(page, /data-testid="pms-operating-data-live-sync"[\s\S]*syncOperatingPmsRealtime/);
  assert.match(page, /<pms-realtime-sync-result\s*\/>/);
  assert.match(appMain, /const operatingPmsRealtimeActionText = computed[\s\S]*实时同步 PMS[\s\S]*补采所选业务日 PMS/);
  assert.match(appMain, /apiRequest\('\/operating-targets\/pms\/realtime-sync'/);
  assert.match(appMain, /loadOperatingTarget\(\{ preserveRealtimeResult: true \}\)/);
  assert.match(appMain, /name: 'PmsRealtimeSyncResult'/);
  assert.match(appMain, /data-testid': 'pms-realtime-sync-result'/);
  assert.match(appMain, /data-testid': 'pms-execution-environment'/);
  assert.match(appMain, /尚未检测 · 订单来了 PMS 专用 Google Chrome/);
  assert.match(appMain, /data-testid': 'pms-execution-environment-check'/);
  assert.match(appMain, /检测正式环境并继续/);
  assert.match(appMain, /data-testid': 'pms-login-handoff'/);
  assert.match(appMain, /handoff\.activated_target_scope === 'login_entry'/);
  assert.match(appMain, /订单来了登录入口/);
  assert.match(appMain, /当前 Codex 内置浏览器不是 PMS 执行浏览器/);
  assert.match(appMain, /仅原绑定设备；不复制 Cookie\/Profile；不自动换设备代采/);
  assert.match(appMain, /data-testid': 'pms-login-handoff-retry'/);
  assert.match(appMain, /登录完成，重新校验并采集/);
  assert.match(appMain, /operatingPmsRealtimeRequestSequence/);
  assert.match(appMain, /operatingTargetRequestSequence/);
  assert.match(appMain, /operatingTargetHistoryRequestSequence/);
  assert.match(appMain, /operatingTargetSnapshotsRequestSequence/);
  assert.match(appMain, /operatingTargetReportGateRequestSequence/);
  assert.match(appMain, /operatingHotelPmsBindingRequestSequence/);
  assert.match(appMain, /const operatingTargetScopeIsCurrent = \(context\)/);
  assert.match(appMain, /requestSequence === operatingTargetRequestSequence[\s\S]*operatingTargetScopeIsCurrent\(context\)/);
  assert.match(appMain, /if \(!scopeIsCurrent\(\)\) return;[\s\S]*operatingTargetResult\.value = res\.data\?\.record/);
  assert.match(appMain, /String\(result\.system_hotel_id \|\| ''\) !== String\(context\.hotelId\)/);
  assert.match(appMain, /String\(result\.target_date \|\| ''\) !== String\(context\.targetDate\)/);
  assert.match(appMain, /if \(!scopeIsCurrent\(\)\) return/);
});

test('realtime PMS sync requires isolated session and never triggers push delivery', () => {
  assert.match(service, /--sandbox-id=/);
  assert.match(service, /--require-sandbox/);
  assert.match(service, /open_local_browser_sandbox\.ps1/);
  assert.match(service, /'-InteractiveLogin'/);
  assert.match(service, /'-SwitchMode'/);
  assert.match(service, /codex_iab_is_execution_browser/);
  assert.match(service, /automatic_device_substitution' => false/);
  assert.match(service, /profile_material_copied' => false/);
  assert.match(service, /session_material_exposed' => false/);
  assert.match(service, /readback_status[\s\S]*readback_verified/);
  assert.doesNotMatch(service, /'--push'/);
});
