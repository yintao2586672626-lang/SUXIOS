import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const source = readFileSync('public/app-main.js', 'utf8');

const sliceBetween = (start, end) => {
  const startIndex = source.indexOf(start);
  const endIndex = source.indexOf(end, startIndex + start.length);
  assert.notEqual(startIndex, -1, `missing start marker: ${start}`);
  assert.notEqual(endIndex, -1, `missing end marker: ${end}`);
  return source.slice(startIndex, endIndex);
};

test('stale authentication responses cannot clear or replace a newer session', () => {
  const sessionHelpers = sliceBetween('let authSessionEpoch = 0;', 'const createDefaultAuthContext');
  const apiRequest = sliceBetween('const request = async (url, options = {}) => {', 'const apiRequest = request;');
  const mountedBootstrap = sliceBetween('const bootstrapSession = captureAuthSession();', '\n                }\n            });');

  assert.match(sessionHelpers, /epoch:\s*authSessionEpoch/);
  assert.match(sessionHelpers, /Number\(session\.epoch\) === authSessionEpoch/);
  assert.match(sessionHelpers, /String\(session\.token \|\| ''\) === String\(token\.value \|\| ''\)/);
  assert.match(apiRequest, /const requestSession = captureAuthSession\(\);/);
  assert.match(apiRequest, /clearAuthSessionIfCurrent\(requestSession, tokenStatus\)/);
  assert.match(apiRequest, /data\.code === 403\) && isAuthSessionCurrent\(requestSession\)/);
  assert.match(mountedBootstrap, /if \(!isAuthSessionCurrent\(bootstrapSession\)\) return;/);
  assert.match(mountedBootstrap, /beginAuthSession\(bootstrapSession\.token\);/);
  assert.match(mountedBootstrap, /if \(isAuthSessionCurrent\(bootstrapSession\)\) \{\s*clearAuthSession\(\);/);
});

test('login, logout, and account switches reset hotel-scoped browser state', () => {
  const resetState = sliceBetween('const resetHotelScopedClientState = () => {', 'const clearActiveHotelDashboardSnapshots = () => {');
  const loginFlow = sliceBetween('const handleLogin = async () => {', 'const loadLoginSupportContact = async () => {');
  const logoutFlow = sliceBetween('const handleLogout = async () => {', 'const dedupeHotels = (items = []) => {');

  for (const expected of [
    'pageLoadRequests.clear();',
    'loadHotelsRequestPromises.clear();',
    'platformDataSourcesRequestPromises.clear();',
    'competitorSummaryRequestPromises.clear();',
    'ctripLatestRequestPromises.clear();',
    'hotels.value = [];',
    'permittedHotels.value = [];',
    "filterReportHotel.value = '';",
    'ctripConfigList.value = [];',
    'meituanConfigList.value = [];',
  ]) {
    assert.ok(resetState.includes(expected), `account reset must include: ${expected}`);
  }
  assert.match(loginFlow, /beginAuthSession\(res\.data\.token\);[\s\S]*permittedHotels\.value = dedupeHotels[\s\S]*hotels\.value = \[\.\.\.permittedHotels\.value\]/);
  assert.ok(logoutFlow.indexOf('clearAuthSession();') < logoutFlow.indexOf('await logoutRequest;'));
  assert.match(logoutFlow, /const logoutRequest = request\('\/auth\/logout', \{ method: 'POST' \}\);/);
  assert.match(logoutFlow, /catch \(error\)/);
});

test('hotel and dashboard loaders reject stale responses and reuse in-flight requests', () => {
  const pageLoadGuard = sliceBetween('const runPageLoadOnce = (page, loadingKey, task, options = {}) => {', 'const activateCoreOperationsAfterLogin = () => {');
  const workbench = sliceBetween('let dualOtaWorkbenchRequestSeq = 0;', 'const setDualOtaPlatform = (platform, options = {}) => {');
  const competitor = sliceBetween('const loadCompetitorSummary = async (options = {}) => {', 'const loadRevenueAiOverview = async () => {');
  const latest = sliceBetween('const loadLatestCtripData = async (', 'const hasVisibleCtripSnapshot = () => {');
  const hotelSwitch = sliceBetween('watch(filterReportHotel, () => {', 'watch(weatherLocationName, () => {');
  const clearHotel = sliceBetween('const clearActiveHotelDashboardSnapshots = () => {', 'const beginAuthSession = (nextToken) => {');

  assert.match(pageLoadGuard, /const key = `\$\{sessionEpoch\}:\$\{page\}:\$\{loadingKey\}`/);
  assert.match(pageLoadGuard, /if \(sessionEpoch !== authSessionEpoch\)/);
  assert.match(workbench, /if \(!isLoggedIn\.value \|\| !token\.value \|\| !isCompassDataPage\(\)\) return;/);
  assert.match(workbench, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(workbench, /if \(!isCurrentRequest\(\)\) return null;/);
  assert.match(competitor, /competitorSummaryRequestPromises\.has\(requestKey\)/);
  assert.match(competitor, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(competitor, /currentPage\.value === requestPage/);
  assert.match(latest, /ctripLatestRequestPromises\.get\(requestKey\)/);
  assert.match(latest, /isAuthSessionCurrent\(requestSession\)/);
  assert.match(hotelSwitch, /clearActiveHotelDashboardSnapshots\(\);[\s\S]*refreshDualOtaWorkbenchData/);
  assert.match(hotelSwitch, /if \(suppressNextReportHotelDashboardRefresh\) \{\s*suppressNextReportHotelDashboardRefresh = false;\s*return;/);
  assert.match(clearHotel, /homeTrendData\.value = \{/);
  assert.match(clearHotel, /competitorSummary\.value = null;/);
  assert.match(clearHotel, /clearCtripOverviewDisplayState\(\);/);
});
