import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const appMain = readAppMainContractSource();
const keyStartMarker = 'const automationMonitorManualActionKey = (row = {}, source = \'\') => (';
const keyEndMarker = '\n            const automationMonitorManualAction =';
const keyStart = appMain.indexOf(keyStartMarker);
const keyEnd = appMain.indexOf(keyEndMarker, keyStart + keyStartMarker.length);
assert.notEqual(keyStart, -1, 'automation monitor manual action key must exist');
assert.notEqual(keyEnd, -1, 'manual action key end must exist');
const keySource = appMain.slice(keyStart, keyEnd);

const actionStartMarker = 'const automationMonitorManualAction = (row = {}, source = \'\') => (';
const actionEndMarker = '\n            const automationMonitorManualActionLabel';
const actionStart = appMain.indexOf(actionStartMarker);
const actionEnd = appMain.indexOf(actionEndMarker, actionStart + actionStartMarker.length);
assert.notEqual(actionStart, -1, 'automation monitor manual action reader must exist');
assert.notEqual(actionEnd, -1, 'manual action reader end must exist');
const actionSource = appMain.slice(actionStart, actionEnd);

const createReader = (businessDate = '2026-09-28') => vm.runInNewContext(
  `(() => {
    const automationMonitorDate = {value: ${JSON.stringify(businessDate)}};
    const shanghaiBusinessToday = '2026-09-29';
    const automationMonitorManualActions = {value: {}};
    ${keySource}
    ${actionSource}
    return {automationMonitorDate, automationMonitorManualActions, automationMonitorManualActionKey, automationMonitorManualAction};
  })()`,
);

test('a manual action result is isolated between business dates for the same hotel and source', () => {
  const reader = createReader();
  const priorDay = {hotel_id: '64', business_date: '2026-09-28'};
  const nextDay = {hotel_id: '64', business_date: '2026-09-27'};
  const priorKey = reader.automationMonitorManualActionKey(priorDay, 'meituan');
  reader.automationMonitorManualActions.value[priorKey] = {
    status: 'failed',
    message: 'previous day failed',
  };

  assert.equal(reader.automationMonitorManualAction(priorDay, 'meituan').status, 'failed');
  assert.equal(reader.automationMonitorManualAction(nextDay, 'meituan').status, 'idle');
});

test('rows without a date use the same selected-date fallback as the monitor action', () => {
  const reader = createReader('2026-09-28');
  const undatedRow = {hotel_id: '64'};
  const explicitSameDateRow = {hotel_id: '64', business_date: '2026-09-28'};
  assert.equal(
    reader.automationMonitorManualActionKey(undatedRow, 'ctrip'),
    reader.automationMonitorManualActionKey(explicitSameDateRow, 'ctrip'),
  );

  reader.automationMonitorDate.value = '2026-09-27';
  assert.notEqual(
    reader.automationMonitorManualActionKey(undatedRow, 'ctrip'),
    reader.automationMonitorManualActionKey(explicitSameDateRow, 'ctrip'),
  );
});
