import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const declaration = name => {
  const start = source.indexOf(`            const ${name} =`);
  const end = source.indexOf('\n            const ', start + 20);
  assert.ok(start > 0 && end > start);
  return source.slice(start, end);
};

function harness(kind) {
  const downloads = [], conversions = [], canvases = [], toasts = [];
  const state = vm.createContext({
    console: { error() {} }, document: { createElement: () => ({}) },
    window: { devicePixelRatio: 1 },
    ctripHotelsList: { value: [{ hotel_name: 'Synthetic A', amount: 0 }] },
    ctripLatestMeta: { value: { hotel_id: '80', data_date: '2026-09-25' } },
    ctripSearchOpportunityRows: { value: [{ target_date: '2026-10-01', cumulative: { self: { pv: 0 } } }] },
    ctripSearchOpportunityView: { value: { capture_date: '2026-09-25' } },
    ctripSearchOpportunitySaving: { value: false },
    selectedCtripHotelId: { value: '80' },
    getHotelNameById: id => id === '80' ? 'Synthetic A' : 'Synthetic B',
    formatDate: () => '2026-09-27',
    buildCtripSearchOpportunityDownloadCards: () => [{ value: 0 }],
    buildCtripSearchOpportunityDownloadTable: rows => ({ rows }),
    buildCtripBusinessCanvasStatic: options => {
      const canvas = structuredClone(options.table);
      canvases.push({ canvas, date: options.latestMeta.data_date });
      return canvas;
    },
    buildCtripBusinessCanvas: () => {
      const canvas = structuredClone(state.ctripHotelsList.value);
      canvases.push({ canvas, date: state.ctripLatestMeta.value?.data_date });
      return canvas;
    },
    canvasToPngBlob: canvas => new Promise((resolve, reject) => conversions.push({ canvas, resolve, reject })),
    downloadBlob: (blob, name) => downloads.push({ blob, name }),
    showToast: (message, level) => toasts.push({ message, level }),
  });
  const handler = kind === 'search' ? 'downloadCtripSearchOpportunityImage' : 'downloadCtripBusinessDataImage';
  vm.runInContext(`${declaration(handler)}\nglobalThis.download = ${handler};`, state);
  return { state, downloads, conversions, canvases, toasts, download: state.download };
}

for (const kind of ['search', 'business']) {
  test(`${kind} image keeps the initiating date and hotel while PNG encoding is pending`, async () => {
    const h = harness(kind);
    const pending = h.download();
    assert.equal(h.conversions.length, 1);
    h.state.selectedCtripHotelId.value = '121';
    h.state.ctripLatestMeta.value = { hotel_id: '121', data_date: '2026-09-26' };
    h.state.ctripSearchOpportunityView.value = { capture_date: '2026-09-26' };
    const blob = { synthetic: true, canvas: h.conversions[0].canvas };
    h.conversions[0].resolve(blob);
    await pending;
    assert.equal(h.downloads.length, 1);
    assert.equal(h.downloads[0].blob, blob);
    assert.match(h.downloads[0].name, /2026-09-25\.png$/);
    assert.doesNotMatch(h.downloads[0].name, /2026-09-26|Synthetic-B/);
    if (kind === 'search') {
      assert.match(h.downloads[0].name, /Synthetic-A/);
      assert.equal(h.downloads[0].blob.canvas.rows[0].cumulative.self.pv, 0);
    } else assert.equal(h.downloads[0].blob.canvas[0].amount, 0);
    assert.equal(h.canvases[0].date, '2026-09-25');
  });

  test(`${kind} image does not acquire a later date after its source is cleared`, async () => {
    const h = harness(kind);
    const pending = h.download();
    h.state.ctripLatestMeta.value = null;
    h.state.ctripSearchOpportunityView.value = {};
    h.state.selectedCtripHotelId.value = '';
    h.conversions[0].resolve({ synthetic: true });
    await pending;
    assert.match(h.downloads[0].name, /2026-09-25\.png$/);
  });

  test(`${kind} failed PNG encoding creates no download and a retry uses the new snapshot`, async () => {
    const h = harness(kind);
    const failed = h.download();
    h.conversions[0].reject(new Error('synthetic_png_failure'));
    await failed;
    assert.equal(h.downloads.length, 0);
    assert.equal(h.toasts.at(-1).level, 'error');
    assert.equal(h.state.ctripSearchOpportunitySaving.value, false);
    h.state.ctripLatestMeta.value = { data_date: '2026-09-26' };
    h.state.ctripSearchOpportunityView.value = { capture_date: '2026-09-26' };
    const retry = h.download();
    h.conversions[1].resolve({ synthetic: true });
    await retry;
    assert.match(h.downloads[0].name, /2026-09-26\.png$/);
  });
}
