import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from 'acorn';

// local_synthetic_fixture_only: evaluate the real guard with in-memory mutants.
// No generated assets, network requests, authentication or business writes.
const read = path => readFileSync(path, 'utf8');
const guard = read('scripts/verify_public_entry_guard.mjs');
const main = read('public/app-main.js');
const revenueStatic = read('public/revenue-ai-static.js');
const service = read('app/service/RevenueAiOverviewService.php');
const semanticTemplate = read('resources/frontend/app-template.html')
  .replaceAll('&amp;', '&').replaceAll('&gt;', '>')
  .replaceAll('&lt;', '<').replaceAll('&quot;', '"');
const contractPrefix = [
  read('public/index.html'), semanticTemplate,
  ...['app-main-components.js', 'operating-intelligence-components.js']
    .map(name => read(`public/components/system/${name}`)),
  read('public/operation-static.js'),
].join('\n');
const ast = parse(guard, { ecmaVersion: 'latest', sourceType: 'module' });
let condition;
function visit(node) {
  if (!node || typeof node !== 'object') return;
  if (node.type === 'IfStatement' && node.consequent.type === 'BlockStatement'
    && node.consequent.body.some(statement => statement.type === 'ExpressionStatement'
      && statement.expression.type === 'CallExpression'
      && typeof statement.expression.arguments[0]?.value === 'string'
      && statement.expression.arguments[0].value.startsWith(
        'public/index.html must delegate Revenue AI display helpers'))) {
    assert.equal(condition, undefined, 'Revenue AI guard must be unique');
    condition = guard.slice(node.test.start, node.test.end);
    return;
  }
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach(visit);
    else if (value?.type) visit(value);
  }
}
visit(ast);
assert.ok(condition, 'the production Revenue AI entry guard must exist');
const rejected = (source = main, helpers = revenueStatic) => Function(
  'content', 'revenueAiStaticContent', 'revenueAiServiceContent',
  `return (${condition});`,
)(`${contractPrefix}\n${source}`, helpers, service);

function mutateFunction(source, name, search, replacement = '') {
  const start = source.indexOf(`const ${name} =`);
  assert.ok(start >= 0, `missing production function ${name}`);
  const indentation = source.slice(source.lastIndexOf('\n', start) + 1, start);
  const next = new RegExp(`\\n${indentation}const `).exec(source.slice(start + name.length + 8));
  const end = next ? start + name.length + 8 + next.index : source.length;
  const body = source.slice(start, end);
  assert.equal(body.split(search).length, 2, `mutation must target one ${name} protection: ${search}`);
  return source.slice(0, start) + body.replace(search, replacement) + source.slice(end);
}

test('the complete current Revenue AI production guard accepts its protected implementation', () => {
  assert.equal(rejected(), false);
});

const displayMutations = [
  ['loading/error status', "const currentReadStatus = overviewLoading ? 'loading' : (overviewError ? 'failed' : 'ready');", "const currentReadStatus = 'ready';"],
  ['loading explanation', "? '正在读取当前酒店和日期的建议；历史记录暂不可审核，请等待读取完成。'", "? ''"],
  ['failure explanation', ": (overviewError ? `当前总览读取失败（${overviewError}）；历史记录尚未重新复核，请只读刷新后再审核。` : '');", ": '';"],
  ['review summary current status', "reviewQueueSummary: currentReadStatus === 'ready' ? (action.review_queue_summary || reviewQueue.display || '') : currentReadReason,", "reviewQueueSummary: action.review_queue_summary || reviewQueue.display || '',"],
  ['basis current reason', "decisionBasisDisplay: currentReadReason || decisionBasis.display || '',", "decisionBasisDisplay: decisionBasis.display || '',"],
  ['basis status label', "decisionBasisStatusLabel: currentReadStatus === 'ready' ? (decisionBasisStatus ? revenueAiStatusLabel(decisionBasisStatus) : '') : (overviewLoading ? '正在读取' : '读取失败'),"],
  ['basis status class', "decisionBasisClassName: currentReadStatus === 'ready' ? (decisionBasisStatus ? revenueAiStatusClass(decisionBasisStatus) : revenueAiStatusClass('unknown')) : revenueAiStatusClass(overviewLoading ? 'pending' : 'failed'),"],
  ['basis current items', "decisionBasisItems: currentReadStatus === 'ready' ? decisionBasisItems : [],", 'decisionBasisItems,'],
  ['basis ready null', "decisionBasisReadyCount: currentReadStatus === 'ready' ? Number(decisionBasis.ready_count || 0) : null,"],
  ['basis blocked null', "decisionBasisBlockedCount: currentReadStatus === 'ready' ? decisionBasisBlockedCount : null,"],
  ['basis hidden blocked null', "decisionBasisHiddenBlockedCount: currentReadStatus === 'ready' ? decisionBasisHiddenBlockedCount : null,"],
  ['review map current status', "buildRevenueAiReviewQueueItems(reviewQueue).map(item => currentReadStatus === 'ready'", 'buildRevenueAiReviewQueueItems(reviewQueue).map(item => true'],
  ['review current read state', 'currentReadStatus,'],
  ['review current read reason', 'currentReadStatus,\n                    currentReadReason,', 'currentReadStatus,'],
  ...['canReview', 'canApprove', 'canApproveWithChanges', 'canReject', 'canCreateExecutionIntent']
    .map(flag => [flag, `${flag}: false,`]),
  ['empty current action buttons', 'actionButtons: [],', 'actionButtons: item.actionButtons,'],
  ['current action explanation', 'actionHelpText: currentReadReason,'],
];
for (const [name, search, replacement] of displayMutations) {
  test(`entry guard rejects missing ${name}`, () => {
    assert.equal(rejected(main, mutateFunction(revenueStatic, 'buildRevenueAiActionRows', search, replacement)), true);
  });
}

const openMutations = [
  ['origin current callback', "if (typeof options.isCurrent === 'function' && !options.isCurrent()) return false;"],
  ['destination session', 'isAuthSessionCurrent(openSession)', 'true'],
  ['destination page', "&& currentPage.value === 'ops-track'"],
  ['destination hotel', "&& String(operationFilters.value.hotel_id || '') === openHotel"],
  ['destination focus', '&& revenueAiExecutionFocus.value === openFocus'],
  ['navigation await ownership', 'if (!isDestinationCurrent()) return false;'],
  ['loader await ownership/success', 'if (!isDestinationCurrent() || loaded !== true) return false;'],
];
for (const [name, search, replacement] of openMutations) {
  test(`entry guard rejects missing ${name}`, () => {
    assert.equal(rejected(mutateFunction(main, 'openRevenueAiExecutionItem', search, replacement)), true);
  });
}

const submitMutations = [
  ['current item ID', 'Number(row.id) === Number(item.id)', 'true'],
  ['current item hotel', "&& String(row.hotelId || '') === String(item.hotelId || '')"],
  ['current item date', "&& String(row.suggestionDate || '') === String(item.suggestionDate || '')"],
  ['draft current item', 'item: currentItem || item,', 'item,'],
  ['draft failure state', 'overviewError: revenueAiOverviewError.value,'],
  ['draft loading state', 'overviewLoading: revenueAiOverviewLoading.value,'],
  ['draft current item availability', 'currentActionAvailable: Boolean(currentItem)', 'currentActionAvailable: true'],
  ['current overview scope', '&& overviewMatchesReviewScope'],
  ['current action button', '&& currentItem.actionButtons.some(button => button.key === action)'],
  ['current overview identity', '&& revenueAiOverview.value === reviewOverview'],
  ['current review session', '&& isAuthSessionCurrent(reviewSession)'],
  ['current review page', '&& currentPage.value === reviewPage'],
  ['current review hotel', "&& String(filterReportHotel.value || '').trim() === reviewHotel"],
  ['current review date', "&& String(currentPageReadPolicy(reviewPage).businessDate || '') === reviewDate"],
  ['current draft invocation', 'const draft = resolveCurrentDraft();', 'const draft = revenueAiResolveReviewActionDraft({ item, action });'],
];
for (const [name, search, replacement] of submitMutations) {
  test(`entry guard rejects missing ${name}`, () => {
    assert.equal(rejected(mutateFunction(main, 'submitRevenueAiReviewAction', search, replacement)), true);
  });
}
