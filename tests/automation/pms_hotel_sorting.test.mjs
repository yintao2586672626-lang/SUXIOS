import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');

const fragment = read('resources/frontend/templates/fragments/15aab-page-pms-operating-data.html');
const appMain = read('public/app-main.js');

test('PMS hotel filter sorts by per-user frequency, recency and stable name fallback', () => {
  assert.match(fragment, /v-for="hotel in pmsHotelOptions"/);

  assert.match(appMain, /suxios_pms_hotel_usage_\$\{user\.value\?\.id \|\| 'guest'\}_v1/);
  assert.match(appMain, /const pmsHotelOptions = computed\(\(\) => \{/);
  assert.match(appMain, /return \(b\.count - a\.count\)[\s\S]*\(b\.lastUsedAt - a\.lastUsedAt\)[\s\S]*\(a\.index - b\.index\)/);
  assert.match(appMain, /a\.name\.localeCompare\(b\.name,\s*'zh-CN'/);
  assert.match(appMain, /a\.id\.localeCompare\(b\.id,\s*'zh-CN'/);
});

test('successful PMS reads record usage without changing hotel identity or backend data', () => {
  assert.match(appMain, /const recordPmsHotelUsage = \(hotelId\) => \{/);
  assert.match(appMain, /if \(!key \|\| !isOperationHotelPermitted\(key\)\) return/);
  assert.match(appMain, /count:\s*Math\.min\(Number\(current\.count \|\| 0\) \+ 1,\s*9999\)/);
  assert.match(appMain, /last_used_at:\s*Date\.now\(\)/);
  assert.match(appMain, /if \(currentPage\.value === 'pms-operating-data'\) \{\s*recordPmsHotelUsage\(context\.hotelId\)/);
  assert.doesNotMatch(fragment, /hotel 5|hotel 80|system_hotel_id/);
});
