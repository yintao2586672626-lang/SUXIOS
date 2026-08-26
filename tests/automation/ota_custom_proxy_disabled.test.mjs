import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(file, 'utf8');

test('generic OTA proxy controls are absent from frontend sources', () => {
  const app = read('public/app-main.js');
  const template = read('resources/frontend/templates/fragments/35-page-online-data.html');
  const frontend = `${app}\n${template}`;

  assert.doesNotMatch(frontend, /fetchCustomData/);
  assert.doesNotMatch(frontend, /customForm/);
  assert.doesNotMatch(frontend, /\/online-data\/fetch-custom/);
  assert.doesNotMatch(frontend, /Authorization:\s*Bearer/);
  assert.match(app, /const MANUAL_ONLINE_FETCH_CONFIG_TABS = new Set\(\['ctrip', 'meituan'\]\);/);
});

test('legacy backend boundary is inert and returns a stable gone response', () => {
  const service = read('app/service/OtaCustomRequestService.php');
  const controller = read('app/controller/concern/OnlineDataRequestConcern.php');

  assert.match(service, /DISABLED_ERROR_CODE = 'custom_request_disabled'/);
  assert.match(service, /trim\(\$errorCode\) === self::DISABLED_ERROR_CODE \? 410 : 500/);
  assert.doesNotMatch(service, /curl_init|curl_exec|file_get_contents\s*\(/);
  assert.match(controller, /'custom_request_disabled'/);
  assert.match(controller, /410 => '通用 OTA 请求已停用，请使用固定业务采集入口'/);
});
