import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const systemStaticSource = readFileSync(new URL('../../public/system-static.js', import.meta.url), 'utf8');
const indexHtml = readFileSync(new URL('../../public/index.html', import.meta.url), 'utf8');
const sandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(systemStaticSource, sandbox, { filename: 'public/system-static.js' });

const { buildHotelOtaStatusBadges } = sandbox.window.SUXI_SYSTEM_STATIC;
const texts = rows => Array.from(buildHotelOtaStatusBadges(rows), badge => badge.text);

assert.deepEqual(texts([
  { platform: 'ctrip', level: 'partial', sessionVerified: false, storeIdentitySaved: false },
  { platform: 'meituan', level: 'partial', sessionVerified: false, storeIdentitySaved: true },
]), ['待登录']);

assert.deepEqual(texts([
  { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
  { platform: 'meituan', level: 'partial', sessionVerified: false, storeIdentitySaved: true },
]), ['携程']);

assert.deepEqual(texts([
  { platform: 'ctrip', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
  { platform: 'meituan', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
]), ['双平台']);

assert.deepEqual(texts([
  { platform: 'meituan', level: 'ready', sessionVerified: true, storeIdentitySaved: true },
]), ['美团']);

assert.deepEqual(texts([
  { platform: 'ctrip', level: 'ready', sessionVerified: false, profileReusable: true, storeIdentitySaved: true },
  { platform: 'meituan', level: 'ready', sessionVerified: false, profileReusable: true, storeIdentitySaved: true },
]), ['双平台'], 'reusable logged-in Profiles must not be mislabeled as waiting for login');

assert.deepEqual(texts([]), []);
assert.equal((indexHtml.match(/v-for="badge in hotelOtaStatusBadges\(hotel\)"/g) || []).length, 2);

console.log('hotel OTA status badge checks passed');
