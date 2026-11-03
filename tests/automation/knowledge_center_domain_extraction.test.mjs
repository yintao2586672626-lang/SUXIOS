import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const domain = readFileSync('public/components/system/knowledge-center-domain.js', 'utf8');
const indexHtml = readFileSync('public/index.html', 'utf8');

test('knowledge center domain is action-gated behind one content-addressed loader', () => {
  const digest = crypto.createHash('sha256').update(domain).digest('hex').slice(0, 10);
  const reference = appMain.match(
    /components\/system\/knowledge-center-domain\.js\?v=[^'"]*-h([a-f0-9]{10})/,
  );

  assert.ok(reference, 'app-main must keep the versioned knowledge-center domain reference');
  assert.equal(reference[1], digest, 'knowledge-center domain version must match its content hash');
  assert.match(appMain, /loadOnlineDataComponentScript\(knowledgeCenterDomainScript\)/);
  assert.match(appMain, /window\.SUXI_KNOWLEDGE_CENTER_DOMAIN/);
  assert.doesNotMatch(indexHtml, /components\/system\/knowledge-center-domain\.js/);
  assert.match(domain, /window\.SUXI_KNOWLEDGE_CENTER_DOMAIN = Object\.freeze\(\{ create \}\)/);
});

test('knowledge center extraction preserves the public setup bridge and full domain API', () => {
  for (const method of [
    'loadKnowledgeCenter',
    'loadKnowledgePromotionWorkbench',
    'loadOperatingNetwork',
    'saveOperatingNetworkProfile',
    'importKnowledgeUnits',
  ]) {
    assert.match(domain, new RegExp(`\\b${method},`), `domain export missing ${method}`);
    assert.match(
      appMain,
      new RegExp(`const ${method} = \\(\\.\\.\\.args\\) => callKnowledgeCenterDomain\\('${method}'`),
      `setup bridge missing ${method}`,
    );
  }
  assert.match(appMain, /const knowledgeCenterVisibleChunks = computed\(\(\) => \{/);
  assert.match(domain, /const knowledgeCenterVisibleChunks = computed\(\(\) =>/);
});

test('actual knowledge center bootstrap works before and after operation helpers load', () => {
  const start = appMain.indexOf('const createKnowledgeCenterDomain = () => {');
  const end = appMain.indexOf('const loadKnowledgeCenterDomain = () => {', start);
  assert.ok(start >= 0 && end > start);
  const factorySource = appMain.slice(start, end);
  // Supply unrelated setup dependencies; production has no operationStatic binding.
  const dependencies = Object.fromEntries(
    [...factorySource.matchAll(/^\s{20}([\w$]+),\s*$/gm)]
      .map(match => match[1])
      .filter(name => name !== 'operationStatic')
      .map(name => [name, null]),
  );
  const browserWindow = { SUXI_KNOWLEDGE_CENTER_DOMAIN: { create: context => context } };
  const context = vm.runInNewContext(
    `let knowledgeCenterDomainInstance = null; ${factorySource}\ncreateKnowledgeCenterDomain();`,
    { ...dependencies, window: browserWindow },
  );
  assert.equal(context.operationStatic.value, null);
  const loadedHelpers = { operatingNetworkReplicationLabel: () => '已保存草稿' };
  browserWindow.SUXI_OPERATION_STATIC = loadedHelpers;
  assert.equal(context.operationStatic.value, loadedHelpers);
  assert.equal(context.operationStatic.value.operatingNetworkReplicationLabel(), '已保存草稿');
});

function createDomain(overrides = {}) {
  const browserWindow = {};
  vm.runInNewContext(domain, { window: browserWindow, URLSearchParams });
  const state = {
    computed: read => ({ get value() { return read(); } }),
    knowledgeCenterFilter: { value: {} },
    knowledgeCenterPagination: { value: { page: 1, page_size: 10 } },
    knowledgeCenterUnits: { value: [{ unit_id: 9 }] },
    selectedKnowledgeCenterUnitIds: { value: ['9'] },
    knowledgeCenterChunks: { value: [] },
    knowledgeCenterLoading: { value: false },
    knowledgeCenterListError: { value: '' },
    knowledgeCenterDisplayLabel: value => String(value || ''),
    formatKnowledgeJson: JSON.stringify,
    requireSystemStatic: () => [],
    showToast: () => {},
    ...overrides,
  };
  const context = new Proxy(state, {
    get: (target, key) => key in target ? target[key] : (target[key] = { value: {} }),
  });
  return { state, api: browserWindow.SUXI_KNOWLEDGE_CENTER_DOMAIN.create(context) };
}

test('failed knowledge reads keep an explicit error and a successful retry clears it', async () => {
  let failing = true;
  const { state, api } = createDomain({ request: async () => {
    if (failing) throw new Error('isolated read failure');
    return { code: 0, data: { list: [{ unit_id: 79 }], pagination: { total: 1, page: 1, page_size: 10, total_page: 1 } } };
  } });
  await api.loadKnowledgeCenter();
  assert.equal(state.knowledgeCenterListError.value, 'isolated read failure');
  assert.equal(state.knowledgeCenterLoading.value, false);
  assert.equal(state.knowledgeCenterUnits.value.length, 0);
  assert.equal(state.selectedKnowledgeCenterUnitIds.value.length, 0);
  failing = false;
  await api.loadKnowledgeCenter();
  assert.equal(state.knowledgeCenterListError.value, '');
  assert.equal(state.knowledgeCenterUnits.value[0].unit_id, 79);
  assert.equal(state.knowledgeCenterPagination.value.total, 1);
});

test('current reference sources precede history and quarantined material has no editing action', () => {
  const history = { chunk_id: 1, lifecycle_status: 'superseded', content: {} };
  const current = { chunk_id: 2, lifecycle_status: 'active', content: {} };
  const isolated = { chunk_id: 3, lifecycle_status: 'active', content: { entry: { disposition: 'reject_or_quarantine' } } };
  const { api } = createDomain({ knowledgeCenterChunks: { value: [history, current, isolated] } });
  assert.equal(api.knowledgeCenterVisibleChunks.value[0].chunk_id, 2);
  assert.equal(api.knowledgeChunkView(current).canPrepareReference, true);
  assert.equal(api.knowledgeChunkView(history).isHistorical, true);
  assert.equal(api.knowledgeChunkView(history).canPrepareReference, false);
  assert.equal(api.knowledgeChunkView(isolated).isQuarantined, true);
  assert.equal(api.knowledgeChunkView(isolated).canPrepareReference, false);
});
