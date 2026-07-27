import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = (path) => readFileSync(path, 'utf8');
const canonicalAssets = [
  'public/ctrip-static.js',
  'public/app-main.js',
  'public/system-static.js',
  'public/auto-fetch-static.js',
  'public/components/system/data-config-dialogs.js',
];
const generatedAssets = [
  'public/app-startup-helpers.min.js',
  'public/app-main.min.js',
];
const sensitiveEndpointPattern = /https:\/\/(?:ebooking\.ctrip\.com|bbk\.ctripbiz\.cn)\/(?:restapi|datacenter\/api|psi\/api|toolcenter\/api)/i;
const competitionImplementationPattern = /\b(?:getManagementData|getFlowSource|getTripartiteOrderLoss|getCompetingRank)\b/;

test('public frontend assets do not disclose the Ctrip collection catalog', () => {
  for (const path of [...canonicalAssets, ...generatedAssets]) {
    const source = read(path);
    assert.doesNotMatch(source, sensitiveEndpointPattern, path);
    assert.doesNotMatch(source, competitionImplementationPattern, path);
  }
});

test('ordinary browser sends one opaque task code and minimum target context', () => {
  const context = {
    window: {},
    console,
    URL,
    setTimeout,
    clearTimeout,
  };
  vm.runInNewContext(read('public/ctrip-static.js'), context, {
    filename: 'public/ctrip-static.js',
  });
  const api = context.window.SUXI_CTRIP_STATIC;
  const body = api.buildCtripCookieApiFetchRequestBody({
    configId: 'ctrip_7',
    systemHotelId: 7,
    hotelId: '9988',
    hotelName: 'Private Hotel Name',
    profileId: 'private-profile',
    dataDate: '2026-07-26',
    requestUrl: 'https://example.invalid/private',
    endpointsJson: JSON.stringify([{ request_url: 'https://example.invalid/other' }]),
    requestSource: 'competition_circle',
    form: { method: 'POST' },
  });

  assert.deepEqual(
    Object.keys(body).sort(),
    ['auto_save', 'config_id', 'ctrip_hotel_id', 'data_date', 'request_source', 'system_hotel_id'].sort(),
  );
  assert.equal(body.request_source, 'competition_circle');
  assert.equal(body.ctrip_hotel_id, '9988');
  assert.equal(body.request_url, undefined);
  assert.equal(body.request_urls, undefined);
  assert.equal(body.profile_id, undefined);
  assert.deepEqual(Array.from(api.getCtripCookieApiCorePresetEndpoints()), []);
});

test('the task code expands into the private server-side collector catalog', () => {
  const serverSource = read('app/controller/concern/PlatformProfileCaptureConcern.php');
  assert.match(serverSource, /'competition_circle'\s*=>/);
  assert.match(serverSource, competitionImplementationPattern);
  assert.match(serverSource, /buildCtripCookieApiPresetEndpoints/);
});
