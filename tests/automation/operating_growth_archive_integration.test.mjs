import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = path => readFileSync(path, 'utf8');

const systemStatic = read('public/system-static.js');
const appMain = read('public/app-main.js');
const manifest = JSON.parse(read('resources/frontend/templates/manifest.json'));
const fragment = read('resources/frontend/templates/fragments/17a-page-operating-growth-archive.html');

const operationTaskSourceRecord = {
  raw: {
    source_reference: {
      record_type: 'operation_execution_task',
      record_id: 731,
    },
  },
};

const operatingGrowthSourceHandlerSource = () => {
  const match = appMain.match(
    /const openOperatingGrowthSource = async record => \{[\s\S]*?\n\s*const operatingGrowthArchiveListeners = \{/,
  );
  assert.ok(match, 'missing operating growth source handler');
  return match[0].replace(/\n\s*const operatingGrowthArchiveListeners = \{$/, '');
};

const createOperatingGrowthSourceHarness = (overrides = {}) => {
  const toasts = [];
  const domQueries = [];
  const dependencies = {
    operationFilters: { value: { hotel_id: '80' } },
    operationExecutionStageFilter: { value: 'executed' },
    currentPage: { value: 'operating-growth-archive' },
    revenueAiExecutionFocus: { value: { taskId: 999 } },
    isOperationHotelPermitted: () => true,
    showToast: (message, type) => toasts.push({ message, type }),
    document: {
      querySelector: (selector) => {
        domQueries.push(selector);
        return {
          setAttribute: () => {},
          focus: () => {},
          scrollIntoView: () => {},
        };
      },
    },
    nextTick: async () => {},
    loadOperationActions: async () => {},
    operationError: { value: { actions: '' } },
    operationExecutionItems: { value: [{ id: 911, execution: { task_id: 731 } }] },
    ...overrides,
  };
  const dependencyNames = Object.keys(dependencies);
  const handlerFactory = new Function(
    ...dependencyNames,
    `${operatingGrowthSourceHandlerSource()}\nreturn openOperatingGrowthSource;`,
  );
  return {
    handler: handlerFactory(...dependencyNames.map(name => dependencies[name])),
    dependencies,
    toasts,
    domQueries,
  };
};

const assertSourceLocationFailedClosed = (harness) => {
  assert.equal(harness.dependencies.revenueAiExecutionFocus.value, null);
  assert.deepEqual(harness.domQueries, []);
  assert.equal(harness.toasts.some(toast => toast.type === 'success' || toast.message.includes('已定位来源任务')), false);
  assert.equal(harness.toasts.some(toast => toast.message.includes('未能定位')), true);
};

test('operating growth archive has a findable operations entry and a mounted page fragment', () => {
  assert.match(systemStatic, /name:\s*['"]经营成长档案['"],\s*path:\s*['"]operating-growth-archive['"]/);
  assert.match(appMain, /sourcePath:\s*'operating-growth-archive',\s*overrides:\s*\{\s*name:\s*'经营成长档案'\s*\}/);
  assert.ok(manifest.fragments.some(item => (
    item.id === 'page-operating-growth-archive'
      && item.path === 'fragments/17a-page-operating-growth-archive.html'
  )));
  assert.match(fragment, /currentPage === 'operating-growth-archive'/);
  assert.match(fragment, /currentPage === 'operating-growth-archive' && operatingGrowthArchiveBody/);
  assert.match(fragment, /v-bind="operatingGrowthArchiveBindings"/);
  assert.match(fragment, /v-on="operatingGrowthArchiveListeners"/);
  assert.match(appMain, /'change-hotel':\s*changeOperatingGrowthHotel/);
  assert.match(
    appMain,
    /currentPage\.value === 'operating-growth-archive'[\s\S]*?runPageLoadOnce\(currentPage\.value, 'main', \(\) => loadOperatingGrowthArchive\(\)\)/,
  );
  assert.match(appMain, /'submit-event':\s*submitOperatingGrowthEvent/);
  assert.match(appMain, /'add-note':\s*addOperatingGrowthAnnotation/);
  assert.match(appMain, /'set-milestone':\s*setOperatingGrowthMilestone/);
});

test('timeline read is scoped by exact hotel, system hotel and date range', () => {
  assert.match(appMain, /params\.set\('hotel_id',\s*hotelId\)/);
  assert.match(appMain, /params\.set\('system_hotel_id',\s*hotelId\)/);
  assert.match(appMain, /params\.set\('date_start',\s*range\.dateStart\)/);
  assert.match(appMain, /params\.set\('date_end',\s*range\.dateEnd\)/);
  assert.match(appMain, /\/operation\/growth-archive\/timeline\?\$\{params\.toString\(\)\}/);
  assert.match(appMain, /Number\(payload\.hotel_id[^\n]+Number\(hotelId\)/);
  assert.match(appMain, /crossHotel[^\n]+row[^\n]+hotel_id/);
});

test('every archive write requires persistence boundaries and an exact GET readback', () => {
  assert.match(appMain, /persistence_status[^\n]+readback_verified/);
  assert.match(appMain, /write_boundaries\?\.ota_write !== false/);
  assert.match(appMain, /write_boundaries\?\.external_message !== false/);
  assert.match(appMain, /\/operation\/operating-memories\/\$\{memoryId\}\?\$\{params\.toString\(\)\}/);
  assert.match(appMain, /readback\.content_digest[^\n]+saved\.content_digest/);

  for (const endpoint of [
    "'/operation/growth-archive/events'",
    '`/operation/growth-archive/${memoryId}/annotations`',
    '`/operation/growth-archive/${memoryId}/milestone`',
  ]) {
    assert.ok(appMain.includes(endpoint), `missing archive write endpoint ${endpoint}`);
  }

  const strictReadbackCalls = appMain.match(/await verifyOperatingGrowthWriteReadback\(res\.data, hotelId\)/g) || [];
  assert.ok(strictReadbackCalls.length >= 3, 'all three write paths must perform strict readback');
});

test('manual archive writes preserve source scope and never claim OTA execution', () => {
  assert.match(appMain, /ctrip:\s*\{\s*platform:\s*'ctrip',\s*source_scope:\s*'ota_channel'\s*\}/);
  assert.match(appMain, /meituan:\s*\{\s*platform:\s*'meituan',\s*source_scope:\s*'ota_channel'\s*\}/);
  assert.match(appMain, /whole_hotel:\s*\{\s*platform:\s*'manual',\s*source_scope:\s*'whole_hotel'\s*\}/);
  assert.match(appMain, /business_date:\s*draft\.date/);
  assert.match(appMain, /occurred_at:\s*`\$\{draft\.date\} \$\{draft\.time\}:00`/);
  assert.match(appMain, /evidence_refs:\s*\[\]/);
});

test('archive operation task sources load and focus the exact execution task before success', () => {
  const handler = operatingGrowthSourceHandlerSource();

  assert.match(handler, /revenueAiExecutionFocus\.value = \{ taskId: sourceId \}/);
  assert.match(handler, /!Number\.isInteger\(sourceId\) \|\| sourceId <= 0/);
  assert.match(handler, /来源任务 ID 无效，未能定位对应任务/);
  assert.match(handler, /来源任务 #\$\{sourceId\} 未能按当前酒店权限定位/);
  assert.match(handler, /来源任务 #\$\{sourceId\} 已回读，但页面未能定位对应记录/);
  assert.doesNotMatch(handler, /已进入任务执行与复盘/);

  const loadIndex = handler.indexOf('await loadOperationActions();');
  const exactTaskIndex = handler.indexOf('operationExecutionItems.value.find');
  const focusIndex = handler.indexOf('revenueAiExecutionFocus.value = { taskId: sourceId };');
  const rowIndex = handler.indexOf('document.querySelector(`[data-operation-execution-intent-id="${sourceIntentId}"]`)');
  const focusRowIndex = handler.indexOf("sourceRow.focus({ preventScroll: true });");
  const scrollIndex = handler.indexOf("sourceRow.scrollIntoView({ behavior: 'smooth', block: 'center' });");
  const successIndex = handler.indexOf('showToast(`已定位来源任务 #${sourceId}`');
  assert.ok(
    loadIndex >= 0
      && loadIndex < exactTaskIndex
      && exactTaskIndex < focusIndex
      && focusIndex < rowIndex
      && rowIndex < focusRowIndex
      && focusRowIndex < scrollIndex
      && scrollIndex < successIndex,
    'source task must be loaded, matched, focused and scrolled before success is reported',
  );
});

test('archive operation task source fails closed when the operation loader throws', async () => {
  let loaderCalls = 0;
  const harness = createOperatingGrowthSourceHarness({
    loadOperationActions: async () => {
      loaderCalls += 1;
      throw new Error('operation static unavailable');
    },
  });

  await harness.handler(operationTaskSourceRecord);

  assert.equal(loaderCalls, 1);
  assertSourceLocationFailedClosed(harness);
});

test('archive operation task source fails closed on permission loss or hotel-filter drift', async () => {
  let deniedLoadCalls = 0;
  const deniedHarness = createOperatingGrowthSourceHarness({
    isOperationHotelPermitted: () => false,
    loadOperationActions: async () => {
      deniedLoadCalls += 1;
    },
  });

  await deniedHarness.handler(operationTaskSourceRecord);

  assert.equal(deniedLoadCalls, 0);
  assertSourceLocationFailedClosed(deniedHarness);

  const driftedFilters = { value: { hotel_id: '80' } };
  let driftLoadCalls = 0;
  const driftHarness = createOperatingGrowthSourceHarness({
    operationFilters: driftedFilters,
    loadOperationActions: async () => {
      driftLoadCalls += 1;
      driftedFilters.value.hotel_id = '81';
    },
  });

  await driftHarness.handler(operationTaskSourceRecord);

  assert.equal(driftLoadCalls, 1);
  assertSourceLocationFailedClosed(driftHarness);
});
