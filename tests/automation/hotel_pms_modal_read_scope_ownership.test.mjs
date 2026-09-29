import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const app = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
function declarationBetween(startMarker, endMarker) {
  const start = app.indexOf(startMarker);
  const end = app.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `Expected active declaration ${startMarker}`);
  return app.slice(start, end);
}

const bindingFunctions = [
  app.match(/^\s*let hotelPmsBindingModalRequestSequence = 0;\s*$/m)?.[0],
  declarationBetween(
    'const applyHotelPmsBinding = (data) => {',
    'const loadHotelPmsBindingForModal = async (hotelId) => {'
  ),
  declarationBetween(
    'const loadHotelPmsBindingForModal = async (hotelId) => {',
    'const handleHotelPmsProviderChange = () => {'
  ),
  'globalThis.loadBinding = loadHotelPmsBindingForModal;',
].join('\n');
assert.ok(bindingFunctions.includes('hotelPmsBindingModalRequestSequence = 0'), 'Use the active modal request owner');

const binding = (providerHotelId, providerHotelName) => ({
  binding_status: 'bound',
  selected_provider: 'dingdandao_pms',
  sources: {
    dingdandao_pms: {
      provider_hotel_id: providerHotelId,
      provider_hotel_name: providerHotelName,
    },
  },
});

function fixture() {
  const requests = [];
  const state = {
    showHotelModal: { value: true },
    hotelForm: { value: { id: '80', name: 'Synthetic hotel', pms_provider: 'none', pms_provider_hotel_id: '', pms_provider_hotel_name: '' } },
    hotelPmsBinding: { value: null },
    hotelPmsBindingLoading: { value: false },
    hotelPmsBindingError: { value: '' },
  };
  let authEpoch = 1;
  const context = {
    ...state,
    captureAuthSession: () => ({ epoch: authEpoch }),
    isAuthSessionCurrent: session => session.epoch === authEpoch,
    apiRequest: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  };
  vm.runInNewContext(bindingFunctions, context, { filename: 'public/app-main.js PMS modal declarations' });
  return {
    state,
    requests,
    load: context.loadBinding,
    changeSession: () => { authEpoch += 1; },
    closeAndReopenSameHotel() {
      state.showHotelModal.value = false;
      state.hotelPmsBinding.value = null;
      state.hotelPmsBindingLoading.value = false;
      state.hotelPmsBindingError.value = '';
      state.hotelForm.value = { id: '80', name: 'Synthetic hotel', pms_provider: 'none', pms_provider_hotel_id: '', pms_provider_hotel_name: '' };
      state.showHotelModal.value = true;
    },
  };
}

async function flush() {
  await new Promise(resolve => setImmediate(resolve));
}

test('reopened same-hotel modal ignores older PMS binding success and retains latest loading lock', async () => {
  const f = fixture();
  const older = f.load('80');
  f.closeAndReopenSameHotel();
  const current = f.load('80');

  f.requests[0].resolve({ code: 200, data: binding('OLD-80', 'Old binding') });
  await older;
  await flush();

  assert.equal(f.state.hotelPmsBinding.value, null, 'superseded binding must not fill the reopened form');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '', 'old provider identity must not become saveable');
  assert.equal(f.state.hotelPmsBindingLoading.value, true, 'older finally must not unlock the current read');

  f.requests[1].resolve({ code: 200, data: binding('NEW-80', 'Current binding') });
  await current;
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, 'NEW-80');
  assert.equal(f.state.hotelPmsBindingLoading.value, false);
});

test('older same-hotel PMS read error cannot replace a newer successful binding', async () => {
  const f = fixture();
  const older = f.load('80');
  f.closeAndReopenSameHotel();
  const current = f.load('80');

  f.requests[1].resolve({ code: 200, data: binding('NEW-80', 'Current binding') });
  await current;
  f.requests[0].reject(new Error('Synthetic stale read failure'));
  await older;

  assert.equal(f.state.hotelPmsBinding.value.selected_provider, 'dingdandao_pms');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, 'NEW-80');
  assert.equal(f.state.hotelPmsBindingError.value, '', 'superseded error must not disable the current form');
  assert.equal(f.state.hotelPmsBindingLoading.value, false);
});

test('old-session PMS read cannot apply to a still-open hotel dialog with the same hotel ID', async () => {
  const f = fixture();
  const oldSessionRead = f.load('80');
  f.changeSession();
  f.requests[0].resolve({ code: 200, data: binding('OLD-TENANT-80', 'Prior session binding') });
  await oldSessionRead;

  assert.equal(f.state.hotelPmsBinding.value, null, 'session-changed response must not cross the auth boundary');
  assert.equal(f.state.hotelForm.value.pms_provider_hotel_id, '');
  assert.equal(f.state.hotelPmsBindingError.value, '');
  assert.equal(f.state.hotelPmsBindingLoading.value, false, 'latest request settles its own loading state');
});
