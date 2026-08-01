# Platform Account Collection Status Center Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate store-level OTA account summaries from a detailed account-level collection readiness center without changing backend collection, login, persistence, or authorization behavior.

**Architecture:** Reuse the existing `hotelPlatformBindingRows()` view model as the single source for hotel/platform account status. Add presentation-only computed rows, filters, summary counts, and actions in `public/index.html`; retain the current diagnostic/status blocks inside a collapsed advanced area. Keep the existing store table, but replace its four technical subcards with one concise status summary and links into the detailed account center.

**Tech Stack:** Vue 3 CDN setup, Tailwind utility classes, existing `public/system-static.js` account row helpers, Node.js contract tests.

---

### Task 1: Lock the information-architecture contract

**Files:**
- Modify: `tests/automation/ota_platform_binding_p0_ui.test.mjs`

- [ ] **Step 1: Add failing contract slices and assertions**

Add slices for `data-testid="platform-account-collection-center"` and `data-testid="hotel-account-summary-table"`. Assert that the detailed center exposes account-level readiness, recent collection result, blocker, next action, filters, and a collapsed advanced-tools marker. Assert that the store summary slice no longer contains `手动Cookie`, `采集配置`, or `自动化采集`.

```js
const accountCollectionCenter = sliceBetween(
  html,
  'data-testid="platform-account-collection-center"',
  'data-testid="platform-account-advanced-tools"'
);
const hotelAccountSummary = sliceBetween(
  html,
  'data-testid="hotel-account-summary-table"',
  '<div v-if="filteredHotels.length === 0"'
);

test('platform account page is the detailed collection-readiness center', () => {
  for (const marker of ['当前可采集', '最近采集结果', '阻塞原因', '下一步', 'platformAccountCenterRows']) {
    assert.match(accountCollectionCenter, new RegExp(marker));
  }
  assert.match(html, /data-testid="platform-account-advanced-tools"/);
});

test('hotel management remains a store-level summary', () => {
  assert.doesNotMatch(hotelAccountSummary, /手动Cookie|采集配置|自动化采集/);
  assert.match(hotelAccountSummary, /最近采集|下一步/);
});
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `node --test tests/automation/ota_platform_binding_p0_ui.test.mjs`

Expected: FAIL because the new test IDs and account-center presentation do not exist yet.

### Task 2: Add account-level presentation state

**Files:**
- Modify: `public/index.html` near the existing platform source state and hotel account helpers.

- [ ] **Step 1: Add filter and expansion state**

Add refs for platform, readiness, search text, and expanded row key.

```js
const platformAccountCenterPlatform = ref('');
const platformAccountCenterReadiness = ref('');
const platformAccountCenterSearch = ref('');
const platformAccountCenterExpandedKey = ref('');
```

- [ ] **Step 2: Build truthful account rows from existing hotel/platform rows**

Create presentation helpers after `hotelPlatformBindingRows()`. A Profile row is currently collectible only when its existing data-source config contains same-day `current_session_verified=true`; historical `manual_login_state_verified` alone is not sufficient. Keep API/manual compatibility paths distinct.

```js
const platformAccountCurrentSessionVerified = (account = {}) => {
  const source = account.profileSource || {};
  const config = source.config || {};
  return config.current_session_verified === true
    && String(config.current_session_probe_date || '') === formatDate(new Date());
};

const platformAccountReadinessCode = (hotel = {}, account = {}) => {
  if (String(hotel.status) !== '1') return 'inactive';
  if (account.statusCode === 'mismatch') return 'hotel_mismatch';
  if (account.statusCode === 'login_expired') return 'login_expired';
  if (account.statusCode === 'missing_config') return 'missing_config';
  if (account.statusCode === 'unbound') return 'unbound';
  if (account.profileSource && !platformAccountCurrentSessionVerified(account)) return 'waiting_login';
  return account.level === 'ready' ? 'ready' : 'waiting_login';
};
```

Build `platformAccountCenterRows`, `filteredPlatformAccountCenterRows`, and `platformAccountCenterSummaryCards`. Each row must retain the original `hotel` and `account` objects so existing actions can be reused.

- [ ] **Step 3: Add a single next-action router**

Route Profile login to `openHotelPlatformAccountAction`, sync failures to `openHotelSyncLogs`, trial collection to `openHotelPlatformConsole`, and configuration/identity problems to the existing hotel OTA editor. Do not create new backend requests.

```js
const openPlatformAccountCenterAction = async (row = {}) => {
  const target = row.account?.nextActionTarget || '';
  if (target === 'profile-login') return openHotelPlatformAccountAction(row.hotel, row.account);
  if (target === 'sync-logs') return openHotelSyncLogs(row.hotel, row.platform);
  if (target === 'platform-auto') return openHotelPlatformConsole(row.hotel, row.platform);
  return openHotelModal(row.hotel, { expandOta: true });
};
```

- [ ] **Step 4: Expose the new state and actions to the template**

Add every new ref, computed, label/class helper, expansion handler, and `openPlatformAccountCenterAction` to the setup return object.

### Task 3: Replace duplicate page hierarchy with summary and detail layers

**Files:**
- Modify: `public/index.html` in the hotel table and `onlineDataTab === 'platform-sources'` template.

- [ ] **Step 1: Simplify store management account cells**

Add `data-testid="hotel-account-summary-table"` to the OTA access table. For each platform cell, keep the platform label, one collectability badge, binding/login summary, recent collection, blocker, and one next-action button. Remove the four subcards labeled `手动Cookie`, `采集配置`, `自动化采集`, and technical module counts. Keep existing permission checks and actions.

- [ ] **Step 2: Add the account collection status center**

Insert `data-testid="platform-account-collection-center"` at the top of the platform-account tab. Render:

- six summary cards;
- hotel/platform/readiness/search filters;
- one row per `filteredPlatformAccountCenterRows` with hotel/platform, binding path, current login, current readiness, recent collection result/date, blocker, and one next action;
- an expandable evidence row containing target Profile/data-source identity, current-session proof status, last successful collection, and the existing safe reason text.

Use explicit `未验证`, `未采集`, and `未加载` states instead of empty success styling.

- [ ] **Step 3: Move dense existing panels behind advanced disclosure**

Wrap the existing guide presets, context/permission cards, data-type breakdown, five-step Profile flow, platform status table, data-source form, browser-assist JSON import, configured sources, tasks, and logs in:

```html
<details data-testid="platform-account-advanced-tools" class="mt-4 rounded-xl border border-slate-200 bg-white">
  <summary class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-slate-800">
    高级诊断与配置
  </summary>
  <div class="border-t border-slate-100 p-4">
    <!-- existing panels, unchanged -->
  </div>
</details>
```

Do not delete the existing diagnostic markers required by P0 tests.

- [ ] **Step 4: Run the focused contract test**

Run: `node --test tests/automation/ota_platform_binding_p0_ui.test.mjs`

Expected: PASS.

### Task 4: Verify the existing frontend contract and visible page

**Files:**
- Verify only: `public/index.html`, `tests/automation/ota_platform_binding_p0_ui.test.mjs`

- [ ] **Step 1: Run syntax and public-entry guards**

Run:

```powershell
npm.cmd run verify:public-entry
npm.cmd run verify:e2e-contracts
```

Expected: both commands exit `0`.

- [ ] **Step 2: Run account- and permission-specific tests**

Run:

```powershell
node --test tests/automation/ota_platform_binding_p0_ui.test.mjs
node --test tests/automation/access_tier_permissions.test.mjs
```

Expected: all tests pass.

- [ ] **Step 3: Inspect the local page**

Refresh `http://127.0.0.1:8080/`, open `线上数据 → 配置：平台账号`, and verify the account center is the default view while advanced diagnostics remain collapsed. Open `门店管理` and verify each platform is a concise summary with a working next-action entry.

- [ ] **Step 4: Review the scoped diff**

Run:

```powershell
git diff --check -- public/index.html tests/automation/ota_platform_binding_p0_ui.test.mjs
git diff --stat -- public/index.html tests/automation/ota_platform_binding_p0_ui.test.mjs
```

Expected: no whitespace errors; no backend, route, database, collector, or authentication files are part of this implementation diff.
