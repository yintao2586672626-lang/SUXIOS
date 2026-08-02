import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const onlineDataPage = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const exportStart = appMain.indexOf('const exportDailyWorkbenchPatrolReport =');
const exportEnd = appMain.indexOf('const dailyWorkbenchPatrolActionKey =', exportStart);
const exportSource = appMain.slice(exportStart, exportEnd);

test('daily workbench patrol report is downloaded through the authenticated session', () => {
  assert.match(onlineDataPage, /data-testid="core-loop-export-patrol-report"/);
  assert.match(onlineDataPage, /@click="exportDailyWorkbenchPatrolReport"/);
  assert.ok(exportStart > 0 && exportEnd > exportStart, 'report export function must remain discoverable');
  assert.match(exportSource, /const exportDailyWorkbenchPatrolReport = async \(\) =>/);
  assert.match(exportSource, /captureAuthSession\(\)/);
  assert.match(exportSource, /Authorization:\s*requestSession\.token/);
  assert.match(exportSource, /fetch\(API_BASE \+ `\/online-data\/daily-workbench-patrols\/report\?/);
  assert.match(exportSource, /response\.blob\(\)/);
  assert.match(exportSource, /downloadBlob\(/);
  assert.match(exportSource, /text\/markdown/);
  assert.match(exportSource, /clearAuthSessionIfCurrent\(requestSession/);
  assert.doesNotMatch(exportSource, /window\.open\(/);
  assert.doesNotMatch(exportSource, /[?&](?:token|authorization)=/i);
});
