import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/operating-growth-static.js', 'utf8');
const context = {
  window: {
    Vue: {
      h(tag, props, children) {
        return { tag, props: props || {}, children };
      },
    },
  },
};
vm.runInNewContext(source, context, { filename: 'public/operating-growth-static.js' });

const {
  EVENT_TYPES,
  buildOperatingGrowthArchiveModel,
  normalizeGrowthEvent,
  normalizeGrowthMetric,
  OperatingGrowthArchive,
} = context.window.SUXI_OPERATING_GROWTH_STATIC;

const verifiedSummary = {
  archive_count: { value: 3, available: true },
  reviewed_count: { value: 1, evidence_count: 1 },
  observing_count: { value: 0, available: true },
  repeated_issue_count: { value: 1, available: true },
};

const baseRecord = {
  id: 61,
  hotel_id: 80,
  hotel_name: '敦煌漠蓝新',
  business_date: '2026-08-02',
  occurred_at: '2026-08-02 16:45:00',
  usage_level: 'archive_only',
  type: 'review',
  title: '周末价格调整复盘',
  summary: '携程渠道流量回升，仍需继续观察。',
  owner_judgment: '结果变好，但不能证明一定由调价造成。',
  result_summary: '对应渠道流量较调整前回升。',
  scope_label: '携程 OTA 渠道',
  source_module: '运营执行',
  source_ref: 'operation_intent#330',
  quality_status: 'partial',
  evidence_count: 3,
};

const build = (extra = {}) => buildOperatingGrowthArchiveModel({
  hotel: { id: 80, name: '敦煌漠蓝新' },
  selectedHotelId: '80',
  dateRangeKey: '90d',
  dateRangeLabel: '近 90 天',
  rangeOptions: [{ key: '30d', label: '近 30 天' }, { key: '90d', label: '近 90 天' }],
  records: [baseRecord],
  summary: verifiedSummary,
  dataStatus: 'ok',
  permissions: { can_create: true, can_open_source: true },
  ...extra,
});

function walk(node, visit) {
  if (node === null || node === undefined) return;
  if (Array.isArray(node)) {
    for (const item of node) walk(item, visit);
    return;
  }
  if (typeof node !== 'object') return;
  visit(node);
  walk(node.children, visit);
}

function findNode(root, predicate) {
  let found;
  walk(root, node => {
    if (found === undefined && predicate(node)) found = node;
  });
  return found;
}

function textContent(node) {
  if (node === null || node === undefined) return '';
  if (Array.isArray(node)) return node.map(textContent).join('');
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  return textContent(node.children);
}

test('archive model keeps exact hotel, date range, source, scope, quality and evidence identity', () => {
  const model = build();

  assert.equal(model.scopeHotelId, '80');
  assert.equal(model.scopeHotelName, '敦煌漠蓝新');
  assert.equal(model.dateRangeLabel, '近 90 天');
  assert.equal(model.stateCode, 'ready');
  assert.equal(model.records.length, 1);
  assert.equal(model.records[0].date, '2026-08-02');
  assert.equal(model.records[0].occurredAt, '2026-08-02 16:45:00');
  assert.equal(model.records[0].dateTimeLabel, '2026-08-02 16:45:00');
  assert.equal(model.records[0].usageLabel, '仅用于档案');
  assert.equal(model.records[0].scopeLabel, '携程 OTA 渠道');
  assert.equal(model.records[0].sourceModule, '运营执行');
  assert.equal(model.records[0].sourceRef, 'operation_intent#330');
  assert.equal(model.records[0].qualityLabel, '部分核验');
  assert.equal(model.records[0].evidenceText, '证据 3 条');
});

test('overview displays zero only when the API explicitly marks the metric available', () => {
  assert.deepEqual(
    { ...normalizeGrowthMetric({ value: 0, available: true }, '仍在观察') },
    { label: '仍在观察', valueText: '0', available: true, status: 'ready' },
  );
  assert.equal(normalizeGrowthMetric({ value: 0 }, '仍在观察').valueText, '未取得');
  assert.equal(normalizeGrowthMetric({ value: 8, available: false }, '经营事件').valueText, '未取得');
  assert.equal(normalizeGrowthMetric({ status: 'failed' }, '反复问题').valueText, '读取失败');

  const model = build({
    summary: {
      archive_count: { value: 0 },
      reviewed_count: { value: 0, available: true },
    },
  });
  assert.equal(model.metrics[0].valueText, '未取得');
  assert.equal(model.metrics[1].valueText, '0');
  assert.equal(model.metrics[2].valueText, '未取得');
});

test('all seven archive categories are present and milestone filtering includes promoted records', () => {
  assert.deepEqual(
    Array.from(EVENT_TYPES, item => item.label),
    ['全部', '事实', '分析', '老板判断', '经营决策', '执行动作', '效果复盘', '里程碑'],
  );
  const model = build({
    activeType: 'milestone',
    records: [
      { ...baseRecord, id: 1, type: 'review', is_milestone: true },
      { ...baseRecord, id: 2, type: 'milestone', is_milestone: false },
      { ...baseRecord, id: 3, type: 'fact' },
    ],
  });
  assert.deepEqual(Array.from(model.visibleRecords, row => row.id), ['1', '2']);
});

test('loading, empty, partial, failure, migration and permission states never collapse into success', () => {
  assert.equal(build({ records: [], dataStatus: '', loading: true }).stateCode, 'loading');
  assert.equal(build({ records: [], dataStatus: 'ok' }).stateCode, 'empty');
  assert.equal(build({ dataStatus: 'partial', gaps: ['source_missing'] }).stateCode, 'partial');
  assert.equal(build({ records: [], dataStatus: '', error: '接口超时' }).stateCode, 'failed');
  assert.equal(build({ records: [], dataStatus: '' }).stateCode, 'waiting');
  assert.equal(build({ migrationReady: false }).stateCode, 'migration_missing');
  assert.equal(build({ canRead: false }).stateCode, 'permission_denied');

  const refreshFailed = build({ error: '刷新超时' });
  assert.equal(refreshFailed.stateCode, 'refresh_failed');
  assert.match(refreshFailed.notice, /不能视为当前最新状态/);
});

test('cross-hotel and identity-missing records are rejected instead of leaking into the selected hotel', () => {
  const partial = build({
    records: [
      baseRecord,
      { ...baseRecord, id: 62, hotel_id: 5, hotel_name: '其他酒店' },
      { ...baseRecord, id: 63, hotel_id: null, hotel_name: '身份缺失酒店' },
    ],
  });
  assert.equal(partial.stateCode, 'partial');
  assert.equal(partial.records.length, 1);
  assert.equal(partial.rejectedIdentityCount, 2);
  assert.match(partial.notice, /已拒绝 2 条/);
  assert.equal(partial.records.some(row => row.hotelName === '其他酒店'), false);

  const blocked = build({ records: [{ ...baseRecord, hotel_id: 5 }] });
  assert.equal(blocked.stateCode, 'identity_mismatch');
  assert.equal(blocked.records.length, 0);
});

test('missing event facts remain explicitly missing and never become empty success values', () => {
  const event = normalizeGrowthEvent({ hotel_id: 80, type: 'fact' }, '80');
  assert.equal(event.date, '日期未取得');
  assert.equal(event.dateTimeLabel, '发生时间未取得');
  assert.equal(event.title, '标题未取得');
  assert.equal(event.summary, '摘要未取得');
  assert.equal(event.scopeLabel, '平台/范围未取得');
  assert.equal(event.sourceModule, '来源模块未取得');
  assert.equal(event.qualityLabel, '可信状态未取得');
  assert.equal(event.evidenceText, '证据数未取得');
  assert.equal(event.usageLabel, '');
  assert.equal(event.sourceAvailable, false);
  assert.equal(event.canAnnotate, false);
  assert.equal(event.canSetMilestone, false);
});

test('component exposes the page header, truthful timeline actions and callback-driven filters', () => {
  const emitted = [];
  const vnode = OperatingGrowthArchive.render.call({
    model: build(),
    showEventForm: false,
    eventDraft: {},
    saving: false,
    busyActionId: '',
    $emit: (...args) => emitted.push(args),
  });

  assert.ok(findNode(vnode, node => node.props?.['data-testid'] === 'operating-growth-header'));
  assert.match(textContent(vnode), /经营成长档案/);
  assert.match(textContent(vnode), /2026-08-02 16:45:00/);
  assert.match(textContent(vnode), /仅用于档案/);
  assert.match(textContent(vnode), /敦煌漠蓝新/);
  assert.match(textContent(vnode), /携程 OTA 渠道/);
  assert.match(textContent(vnode), /部分核验/);
  assert.match(textContent(vnode), /证据 3 条/);

  const factFilter = findNode(vnode, node => node.tag === 'button' && textContent(node) === '事实');
  factFilter.props.onClick();
  assert.deepEqual(emitted[0], ['change-filter', 'fact']);

  const sourceButton = findNode(vnode, node => node.tag === 'button' && textContent(node) === '查看来源');
  sourceButton.props.onClick();
  assert.equal(emitted[1][0], 'open-source');
  assert.equal(emitted[1][1].sourceRef, 'operation_intent#330');
});

test('manual event form locks hotel context and emits every required field without claiming save success', () => {
  const emitted = [];
  const vnode = OperatingGrowthArchive.render.call({
    model: build(),
    showEventForm: true,
    eventDraft: {
      date: '2026-08-03',
      type: 'owner_judgment',
      dataScope: 'manual_context',
      title: '当地活动临时取消',
      factDescription: '活动方当天通知取消。',
      ownerJudgment: '',
    },
    saving: false,
    busyActionId: '',
    $emit: (...args) => emitted.push(args),
  });

  const form = findNode(vnode, node => node.props?.['data-testid'] === 'operating-growth-event-form');
  assert.ok(form);
  assert.match(textContent(form), /酒店（当前上下文锁定）/);
  assert.match(textContent(form), /发生日期/);
  assert.match(textContent(form), /事件类型/);
  assert.match(textContent(form), /标题/);
  assert.match(textContent(form), /事实描述/);
  assert.match(textContent(form), /老板判断/);
  assert.match(textContent(form), /数据范围/);
  assert.match(textContent(form), /保存并严格回读/);
  assert.doesNotMatch(textContent(form), /保存成功/);

  const lockedHotel = findNode(form, node => node.tag === 'input' && node.props?.disabled === true);
  assert.equal(lockedHotel.props.value, '敦煌漠蓝新');

  const dateInput = findNode(form, node => node.tag === 'input' && node.props?.type === 'date');
  dateInput.props.onInput({ target: { value: '2026-08-04' } });
  assert.equal(emitted[0][0], 'update:eventDraft');
  assert.equal(emitted[0][1].date, '2026-08-04');

  const submit = findNode(form, node => node.tag === 'button' && textContent(node) === '保存并严格回读');
  submit.props.onClick();
  assert.deepEqual(emitted[1], ['submit-event']);
});

test('layout contract stays fluid and prevents horizontal page escape on narrow screens', () => {
  assert.match(source, /repeat\(auto-fit, minmax\(min\(100%, 220px\), 1fr\)\)/);
  assert.match(source, /overflow-x-hidden/);
  assert.match(source, /max-w-full flex-wrap/);
  assert.match(source, /break-words/);
  assert.doesNotMatch(source, /min-width:\s*[4-9]\d\dpx/);
});
