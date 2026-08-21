import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import vm from 'node:vm';

const phpCandidates = [
  process.env.SUXIOS_PHP_EXE,
  'C:\\xampp\\php\\php.exe',
  'php',
].filter(Boolean);

const phpCode = String.raw`
require getcwd() . '/vendor/autoload.php';
$app = new \think\App(getcwd());
$app->initialize();

$candidate = \think\facade\Db::name('online_daily_data')
    ->where('history_status', 'success')
    ->where('readback_verified', 1)
    ->where('validation_status', 'verified')
    ->where('data_date', '<=', date('Y-m-d'))
    ->whereRaw("LOWER(COALESCE(NULLIF(platform, ''), source, '')) IN ('ctrip','meituan')")
    ->field('tenant_id,system_hotel_id')
    ->order('data_date', 'desc')
    ->order('id', 'desc')
    ->find();

if (!is_array($candidate)) {
    fwrite(STDERR, "No strict OTA readback scope is available.\n");
    exit(3);
}

$tenantId = (int)($candidate['tenant_id'] ?? 0);
$hotelId = (int)($candidate['system_hotel_id'] ?? 0);
$scope = (new \app\service\OperatingQuestionService())->scopeOptions($tenantId, $hotelId);
$recommended = is_array($scope['recommended'] ?? null) ? $scope['recommended'] : [];
$platform = strtolower(trim((string)($recommended['platform'] ?? '')));
$businessDate = substr(trim((string)($recommended['date_start'] ?? '')), 0, 10);
if ($platform === '' || $businessDate === '') {
    fwrite(STDERR, "Strict scope service returned no recommended platform/date.\n");
    exit(4);
}

$platformRow = null;
foreach (($scope['platforms'] ?? []) as $row) {
    if (is_array($row) && strtolower(trim((string)($row['platform'] ?? ''))) === $platform) {
        $platformRow = $row;
        break;
    }
}
$availableDates = is_array($platformRow['available_dates'] ?? null)
    ? array_values($platformRow['available_dates'])
    : [];
$previousDate = isset($availableDates[1]) ? substr(trim((string)$availableDates[1]), 0, 10) : null;
$enabledChannels = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
$filters = [
    'business_date' => $businessDate,
    'hotel_id' => $hotelId,
    'permitted_hotel_ids' => [$hotelId],
    'enabled_channels' => $enabledChannels,
    'strict_readback_only' => true,
];
$overviewService = new \app\service\RevenueAiOverviewService();
$overview = $overviewService->overview($filters);
$strictEvidenceService = new \app\service\RevenueCockpitStrictEvidenceService();
$overview['cockpit_strict_evidence'] = $strictEvidenceService->build(
    $overview,
    $tenantId,
    $hotelId,
    $businessDate,
    $platform
);
$comparisonOverview = $previousDate !== null
    ? $overviewService->overview(array_replace($filters, ['business_date' => $previousDate]))
    : null;
if (is_array($comparisonOverview) && $previousDate !== null) {
    $comparisonOverview['cockpit_strict_evidence'] = $strictEvidenceService->build(
        $comparisonOverview,
        $tenantId,
        $hotelId,
        $previousDate,
        $platform
    );
}

$strictRowsQuery = \think\facade\Db::name('online_daily_data')
    ->where('tenant_id', $tenantId)
    ->where('system_hotel_id', $hotelId)
    ->where('data_date', $businessDate)
    ->where('history_status', 'success')
    ->where('readback_verified', 1)
    ->where('validation_status', 'verified');
if ($platform === 'all_ota') {
    $strictRowsQuery->whereRaw("LOWER(COALESCE(NULLIF(platform, ''), source, '')) IN ('ctrip','meituan')");
} else {
    $strictRowsQuery->whereRaw(
        "LOWER(COALESCE(NULLIF(platform, ''), source, '')) = :cockpit_platform",
        ['cockpit_platform' => $platform]
    );
}
$strictRows = $strictRowsQuery
    ->field('id,source,platform,data_date,history_status,validation_status,readback_verified,source_trace_id')
    ->order('id', 'asc')
    ->select()
    ->toArray();

$today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
echo json_encode([
    'today' => $today,
    'tenant_id' => $tenantId,
    'hotel_id' => $hotelId,
    'scope' => $scope,
    'overview' => $overview,
    'comparison_overview' => $comparisonOverview,
    'strict_rows' => $strictRows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
`;

const runPhp = () => {
  const failures = [];
  for (const candidate of phpCandidates) {
    if (candidate !== 'php' && !existsSync(candidate)) continue;
    const result = spawnSync(candidate, ['-r', phpCode], {
      cwd: process.cwd(),
      encoding: 'utf8',
      maxBuffer: 32 * 1024 * 1024,
      windowsHide: true,
    });
    if (!result.error && result.status === 0) return result.stdout;
    failures.push({
      candidate,
      status: result.status,
      error: result.error?.message || '',
      stderr: String(result.stderr || '').trim(),
    });
  }
  throw new Error(`Unable to build live cockpit readback payload: ${JSON.stringify(failures)}`);
};

const payload = JSON.parse(runPhp());
assert.equal(payload.scope?.data_status, 'ready', 'strict scope must be ready');
assert.equal(payload.scope?.boundary?.silent_date_fallback, false, 'date fallback must stay disabled');
assert.equal(payload.scope?.boundary?.whole_hotel_conclusion, false, 'OTA facts cannot become whole-hotel facts');

const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync('public/revenue-ai-static.js', 'utf8'), context, {
  filename: 'public/revenue-ai-static.js',
});
const helpers = context.window.SUXI_REVENUE_AI_STATIC;
const resolvedScope = helpers.resolveRevenueCockpitScope({
  scopePayload: payload.scope,
  today: payload.today,
});
assert.equal(resolvedScope.selectedPlatform, payload.scope.recommended.platform);
assert.equal(resolvedScope.selectedDate, payload.scope.recommended.date_start);

const model = helpers.buildRevenueCockpitModel({
  overview: payload.overview,
  comparisonOverview: payload.comparison_overview,
  scope: resolvedScope,
  selectedPlatform: resolvedScope.selectedPlatform,
  businessDate: resolvedScope.selectedDate,
  today: payload.today,
});

const expectedSections = [
  '1. 数据是否完整',
  '2. 核心收入与订单指标',
  '3. 渠道流量和转化',
  '4. 同口径变化',
  '5. 异常原因',
  '6. 建议动作',
  '7. 数据缺口',
];
assert.deepEqual(
  Array.from(model.visibleSections, (section) => section.title),
  expectedSections,
  'live cockpit must preserve the seven-section operating order',
);
assert.match(model.scopeBoundary, /OTA 渠道结论|OTA渠道结论/);
assert.match(model.scopeBoundary, /不同来源收入不相加/);
assert.equal(model.businessDate, payload.scope.recommended.date_start);
assert.equal(model.selectedPlatform, payload.scope.recommended.platform);
assert.ok(model.dateNotice, 'date notice must be visible');
if (payload.scope.recommended.is_today === false) {
  assert.ok(model.dateDistance > 0, 'historical strict date must expose distance from today');
  assert.match(model.dateNotice, /比今天早/);
}

const cards = Array.from(model.visibleSections).flatMap((section) => Array.from(section.cards));
assert.ok(cards.length > 0, 'live cockpit must produce visible cards');
for (const card of cards) {
  assert.ok(card.sourceLabel, `${card.key} must expose a source`);
  assert.ok(card.businessDate, `${card.key} must expose a business date`);
  assert.ok(card.statusLabel, `${card.key} must expose a verification status`);
  assert.ok(card.scopeLabel, `${card.key} must expose a scope`);
  assert.ok(card.missingState, `${card.key} must expose a missing state`);
  assert.ok(Array.isArray(card.evidenceLines) && card.evidenceLines.length > 0, `${card.key} must expose evidence`);
  if (card.value === null && ['metric', 'comparison'].includes(card.kind)) {
    assert.equal(card.display, '—', `${card.key} cannot turn a missing value into zero`);
  }
}
assert.equal(cards.some((card) => String(card.key).includes('combined')), false, 'sources must not be silently combined');

const downloadRows = helpers.buildRevenueCockpitDownloadRows(model);
assert.equal(downloadRows.length, cards.length, 'download must contain every visible card exactly once');
downloadRows.forEach((row, index) => {
  const card = cards[index];
  assert.equal(row.order, index + 1);
  assert.equal(row.card, card.label);
  assert.equal(row.display, card.display);
  assert.equal(row.source, card.sourceLabel);
  assert.equal(row.business_date, card.businessDate);
  assert.equal(row.verification_status, card.statusLabel);
  assert.equal(row.scope, card.scopeLabel);
  assert.equal(row.missing_state, card.missingState);
});
const csv = helpers.buildRevenueCockpitCsv(model);
assert.equal(csv.split('\r\n').length, downloadRows.length + 1);

const numericCards = cards.filter((card) => card.value !== null);
const missingCards = cards.filter((card) => card.value === null && card.display === '—');
const strictRowIds = Array.from(payload.strict_rows || [], (row) => Number(row.id)).filter((id) => id > 0);
assert.ok(strictRowIds.length > 0, 'selected scope must retain strict source row ids');
assert.ok(numericCards.length > 0, 'selected real date must expose at least one strict user-visible metric');
for (const card of numericCards) {
  assert.equal(card.missingState, '有值', `${card.key} numeric display must be explicitly verified`);
  assert.ok(
    ['readback_verified', 'derived_verified', 'verified'].includes(String(card.status).toLowerCase()),
    `${card.key} cannot display a number with an unverified status`,
  );
  if (String(card.sourceKey).endsWith('_ota')) {
    assert.ok(
      card.evidenceLines.some((line) => strictRowIds.some((id) => String(line).includes(`#${id}`))),
      `${card.key} must trace to a strict saved OTA row`,
    );
  }
}

const summary = {
  verifier: 'revenue_cockpit_live_readback.v1',
  status: 'passed',
  scope: {
    tenant_id: payload.tenant_id,
    hotel_id: payload.hotel_id,
    platform: model.selectedPlatform,
    business_date: model.businessDate,
    today: payload.today,
    date_distance_days: model.dateDistance,
    selection_reason: payload.scope.recommended.selection_reason,
  },
  truth_gate: payload.scope.boundary.strict_gate,
  source_row_ids: strictRowIds,
  overview_data_status: payload.overview?.data_status || null,
  strict_evidence_status: payload.overview?.cockpit_strict_evidence?.status || null,
  strict_evidence_platforms: payload.overview?.cockpit_strict_evidence?.platforms || {},
  cockpit_status: model.status,
  section_count: model.visibleSections.length,
  card_count: cards.length,
  numeric_card_count: numericCards.length,
  numeric_cards: numericCards.map((card) => ({
    key: card.key,
    label: card.label,
    display: card.display,
    source: card.sourceLabel,
    verification_status: card.statusLabel,
  })),
  explicit_missing_card_count: missingCards.length,
  can_ask_question: model.canAskQuestion,
  can_create_pending_approval: model.canCreatePendingApproval,
  action_disabled_reason: model.actionDisabledReason,
  download_row_count: downloadRows.length,
  download_sha256: crypto.createHash('sha256').update(csv).digest('hex'),
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
