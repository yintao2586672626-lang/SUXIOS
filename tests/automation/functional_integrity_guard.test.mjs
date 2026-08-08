import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const appMain = fs.readFileSync(path.join(repoRoot, 'public', 'app-main.js'), 'utf8');
const pmsTemplate = fs.readFileSync(
  path.join(repoRoot, 'resources', 'frontend', 'templates', 'fragments', '15aab-page-pms-operating-data.html'),
  'utf8',
);

test('home Revenue AI overview is not requested for an account without Revenue AI permission', () => {
  assert.match(
    appMain,
    /const canUseRevenueAi = \(\) =>[\s\S]*can_use_ai_decision[\s\S]*ai\.view[\s\S]*ai\.execute/,
  );
  const start = appMain.indexOf('const loadRevenueAiOverview = async (options = {}) => {');
  const end = appMain.indexOf('const loadCompassData = async', start);
  assert.ok(start >= 0 && end > start, 'Revenue AI overview loader must be present');
  const loader = appMain.slice(start, end);
  const permissionGate = loader.indexOf('if (!canUseRevenueAi()) {');
  const sessionCapture = loader.indexOf('const requestSession = captureAuthSession();');
  const networkRequest = loader.indexOf('await request(overviewRequest.endpoint');
  assert.ok(permissionGate >= 0, 'Revenue AI permission gate must be present');
  assert.match(loader.slice(permissionGate, sessionCapture), /return null;/);
  assert.ok(sessionCapture > permissionGate, 'permission denial must happen before request state is captured');
  assert.ok(networkRequest > sessionCapture, 'network access must happen only after permission and request-scope checks');
});

test('combined OTA loss-chain preserves known subtotals without treating a missing platform as zero', () => {
  const helperStart = appMain.indexOf('const dualOtaLossNode =');
  const helperEnd = appMain.indexOf('dualOtaCurrentLossNodes =', helperStart);
  assert.ok(helperStart >= 0 && helperEnd > helperStart, 'dual OTA loss helpers are missing');

  const helperSource = appMain.slice(helperStart, helperEnd);
  const observedNumber = (value) => (
    typeof value === 'number' && Number.isFinite(value) ? value : null
  );
  const formatNumber = (value) => (
    observedNumber(value) === null ? '未返回' : String(value)
  );
  const helpers = Function(
    'dualOtaMoneyText',
    'dualOtaNumberText',
    'dualOtaObservedNumber',
    `${helperSource}; return { dualOtaCombinedLossNode };`,
  )(
    (value) => (observedNumber(value) === null ? '未返回' : `¥${value}`),
    formatNumber,
    observedNumber,
  );

  const partial = helpers.dualOtaCombinedLossNode('revenue', '收入', [
    { platform: '携程', value: 28976 },
    { platform: '美团', value: null },
  ]);
  assert.equal(partial.value, '已知 ¥28976');
  assert.equal(partial.dataStatus, 'partial');
  assert.equal(partial.delta, '合计不完整');
  assert.match(partial.note, /携程已返回/);
  assert.match(partial.note, /美团未返回/);

  const complete = helpers.dualOtaCombinedLossNode('paidOrders', '订单', [
    { platform: '携程', value: 76 },
    { platform: '美团', value: 4 },
  ]);
  assert.equal(complete.value, '80');
  assert.equal(complete.dataStatus, 'ok');

  const missing = helpers.dualOtaCombinedLossNode('roomNights', '间夜', [
    { platform: '携程', value: null },
    { platform: '美团', value: null },
  ]);
  assert.equal(missing.value, '未返回');
  assert.equal(missing.dataStatus, 'missing');
});

test('realtime PMS sync is disabled until the selected hotel has one configured PMS', () => {
  assert.match(
    pmsTemplate,
    /data-testid="pms-operating-data-live-sync"[\s\S]{0,500}:disabled="[^"]*operatingHotelPmsBindingLoading[^"]*operatingHotelPmsBinding\?\.binding_status !== 'configured'[^"]*"/,
  );
});
