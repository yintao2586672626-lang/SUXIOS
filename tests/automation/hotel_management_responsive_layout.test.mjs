import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { readFrontendContractSource } from './helpers/frontend_source.mjs';

const indexHtml = readFrontendContractSource();
const styleCss = fs.readFileSync(new URL('../../public/style.css', import.meta.url), 'utf8');
const systemStaticSource = fs.readFileSync(new URL('../../public/system-static.js', import.meta.url), 'utf8');
const systemStaticSandbox = { window: {}, console, setTimeout, clearTimeout };
vm.runInNewContext(systemStaticSource, systemStaticSandbox, { filename: 'public/system-static.js' });
const { sortHotelManagementRows } = systemStaticSandbox.window.SUXI_SYSTEM_STATIC;

assert.match(
  indexHtml,
  /style\.css\?v=20260712-sidebar-expanded-205-collapsed-72/,
  'responsive hotel styles must use a fresh browser cache key'
);

assert.match(
  indexHtml,
  /class="xl:hidden hotel-preview-layout"/,
  'compact hotel management view must expose an intrinsic responsive grid'
);
assert.match(
  indexHtml,
  /class="hotel-preview-card"/,
  'each hotel must be an independently responsive preview card'
);
assert.match(
  indexHtml,
  /v-if="isHotelDetailsExpanded\(hotel\)" class="hotel-preview-card__platforms"/,
  'OTA platform panels must adapt to the card width instead of the viewport width'
);
assert.doesNotMatch(
  indexHtml,
  /class="shrink-0 w-\[128px\] space-y-1\.5"/,
  'hotel actions must not reserve a fixed-width column on compact screens'
);
assert.match(
  styleCss,
  /\.hotel-preview-layout\s*\{[\s\S]*grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(min\(100%,\s*28rem\),\s*1fr\)\)/,
  'hotel columns must be selected from available content width with auto-fit'
);

assert.match(indexHtml, /问题队列/);
assert.match(indexHtml, /未绑定\/待登录/);
assert.match(indexHtml, /登录失效/);
assert.match(indexHtml, /尚未采集/);
assert.match(indexHtml, /未设负责人/);
assert.match(indexHtml, /const batchUpdateHotelStatus = async \(status\) =>/);
assert.match(indexHtml, /\/hotels\/batch-status/);
assert.match(indexHtml, /confirm: false/);
assert.match(indexHtml, /confirm: true/);
assert.match(indexHtml, /@click="openHotelPlatformAccountAction\(hotel, account\)"[^>]*>下一步：/);
assert.match(indexHtml, /\{\{ isHotelDetailsExpanded\(hotel\) \? '收起详情' : '展开详情' \}\}/);
assert.match(
  styleCss,
  /\.hotel-preview-card__platforms\s*\{[\s\S]*grid-template-columns:\s*repeat\(auto-fit,\s*minmax\(min\(100%,\s*13rem\),\s*1fr\)\)/,
  'OTA platform columns must independently auto-fit within each hotel card'
);
assert.match(
  styleCss,
  /@container hotel-preview-card \(max-width:\s*27\.99rem\)/,
  'narrow hotel cards must have a container-driven fallback layout'
);
assert.match(
  styleCss,
  /@media \(min-width:\s*1280px\)\s*\{[\s\S]*\.hotel-preview-layout\s*\{\s*display:\s*none;/,
  'desktop table and compact hotel cards must never render at the same time'
);

assert.equal(typeof sortHotelManagementRows, 'function', 'hotel management must expose deterministic display sorting');
const sourceRows = [
  { id: 81, create_time: '2026-07-10 09:00:00' },
  { id: 94, create_time: '2026-07-11 08:00:00' },
  { id: 107, create_time: '2026-07-11 08:00:00' },
  { id: 7, create_time: '' },
];
assert.deepEqual(
  Array.from(sortHotelManagementRows(sourceRows), row => row.id),
  [107, 94, 81, 7],
  'hotels must display newest creation records first and use the real ID only as a stable tie-breaker'
);
assert.deepEqual(sourceRows.map(row => row.id), [81, 94, 107, 7], 'display sorting must not mutate API data or database IDs');
assert.match(indexHtml, /const sortHotelManagementRows = requireSystemStatic\('sortHotelManagementRows'\)/);
assert.match(indexHtml, /hotelCreatedDateText\(hotel\)/, 'the real ID must be accompanied by its creation date');
assert.equal(
  (indexHtml.match(/data-testid="hotel-ota-strategy"/g) || []).length,
  2,
  'verified OTA channel status must render beside hotel status in both desktop and compact layouts'
);
assert.match(indexHtml, /营业中[\s\S]{0,500}v-for="badge in hotelOtaStatusBadges\(hotel\)"[\s\S]{0,300}data-testid="hotel-ota-strategy"/, 'desktop selected-channel badges must follow the business status');

console.log('hotel management responsive layout checks passed');
