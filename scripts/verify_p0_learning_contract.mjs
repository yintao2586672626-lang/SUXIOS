import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = existsSync('app/controller/OnlineData.php') ? '.' : 'HOTEL';
const read = (path) => readFileSync(join(root, path), 'utf8');

const controllerSource = read('app/controller/OnlineData.php');
const autoFetchConcernSource = read('app/controller/concern/AutoFetchConcern.php');
const businessDisplayConcernSource = read('app/controller/concern/BusinessDisplayConcern.php');
const collectionReliabilityConcernSource = read('app/controller/concern/CollectionReliabilityConcern.php');
const onlineDataManualFetchConcernSource = read('app/controller/concern/OnlineDataManualFetchConcern.php');
const platformProfileBindingReadinessSource = read('app/service/PlatformProfileBindingReadinessService.php');
const publicSource = read('public/index.html');
const homeStaticSource = read('public/home-static.js');
const ctripStaticSource = read('public/ctrip-static.js');
const meituanStaticSource = read('public/meituan-static.js');
const packageSource = read('package.json');
const backfillSource = read('scripts/backfill_meituan_vip_tags.php');
const openHotelModalStart = publicSource.indexOf('const openHotelModal = async (hotel = null, options = {})');
const openHotelModalEnd = publicSource.indexOf('const saveHotel = async () =>', openHotelModalStart);
const openHotelModalSource = openHotelModalStart >= 0 && openHotelModalEnd > openHotelModalStart
  ? publicSource.slice(openHotelModalStart, openHotelModalEnd)
  : '';
const meituanVisibleRankInsightStart = publicSource.indexOf('const meituanVisibleRankInsightCards = computed');
const meituanVisibleRankInsightEnd = publicSource.indexOf('const meituanRankHealthRows = computed', meituanVisibleRankInsightStart);
const meituanVisibleRankInsightSource = meituanVisibleRankInsightStart >= 0 && meituanVisibleRankInsightEnd > meituanVisibleRankInsightStart
  ? publicSource.slice(meituanVisibleRankInsightStart, meituanVisibleRankInsightEnd)
  : '';
const onlineDataRuntimeSource = [
  controllerSource,
  autoFetchConcernSource,
  businessDisplayConcernSource,
  collectionReliabilityConcernSource,
  onlineDataManualFetchConcernSource,
].join('\n');
const platformProfileBindingSource = onlineDataRuntimeSource + platformProfileBindingReadinessSource;
const hasBindingCheckKey = (key) => platformProfileBindingSource.includes(`'key' => '${key}'`)
  || new RegExp(`buildPlatformProfileBindingCheck\\(\\s*'${key}'`).test(platformProfileBindingSource)
  || new RegExp(`buildCheck\\(\\s*'${key}'`).test(platformProfileBindingSource);

const checks = [
  {
    name: 'platform profile status exposes P0 binding checks',
    pass: onlineDataRuntimeSource.includes('private function buildPlatformProfileBindingChecks')
      && onlineDataRuntimeSource.includes("'binding_checks' => $bindingChecks")
      && onlineDataRuntimeSource.includes("'p0_readiness' => $p0Readiness"),
  },
  {
    name: 'platform profile summary counts collection readiness and identity blockers',
    pass: onlineDataRuntimeSource.includes("'ready_to_collect' => $readyToCollect")
      && onlineDataRuntimeSource.includes("'needs_identity_check' => $needsIdentityCheck")
      && onlineDataRuntimeSource.includes("'identity_blocked' => $identityBlocked"),
  },
  {
    name: 'P0 binding checks cover hotel binding, platform identity, login, and trial capture',
    pass: ['hotel_binding', 'platform_identity', 'profile_login', 'trial_capture'].every(hasBindingCheckKey),
  },
  {
    name: 'collection reliability exposes lifecycle catalog and field asset summary',
    pass: onlineDataRuntimeSource.includes("'collection_lifecycle_catalog' => $this->collectionLifecycleCatalog()")
      && onlineDataRuntimeSource.includes("'field_asset_summary' => $this->summarizeOtaCollectionFieldDefinitions($fieldDefinitions)")
      && onlineDataRuntimeSource.includes('private function summarizeOtaCollectionFieldDefinitions'),
  },
  {
    name: 'competitor summary API exposes readiness for trusted quick judgment',
    pass: onlineDataRuntimeSource.includes('private function buildMeituanCompetitorSummaryReadiness')
      && onlineDataRuntimeSource.includes("'readiness' => $readiness")
      && onlineDataRuntimeSource.includes("'readiness' => $this->buildMeituanCompetitorSummaryReadiness([], $context, false)"),
  },
  {
    name: 'competitor summary uses latest data_date and adjacent batch window',
    pass: /if\s*\(isset\(\$columns\['data_date'\]\)\)\s*\{\s*\$query->order\('data_date',\s*'desc'\);\s*\}\s*\$this->orderOnlineDataByFetchTime\(\$query,\s*\$columns,\s*'desc'\);/.test(onlineDataRuntimeSource)
      && onlineDataRuntimeSource.includes('MEITUAN_COMPETITOR_BATCH_WINDOW_SECONDS')
      && onlineDataRuntimeSource.includes("$query->where('sync_task_id'")
      && onlineDataRuntimeSource.includes("$query->where('source_trace_id'")
      && onlineDataRuntimeSource.includes('$query->whereBetween($column'),
  },
  {
    name: 'Meituan VIP and platform tags are recorded as field assets',
    pass: onlineDataRuntimeSource.includes("'platformTags' => $platformTagInfo['tags']")
      && (onlineDataRuntimeSource.includes("'hasVipTag' => $hasVipTag") || onlineDataRuntimeSource.includes("$row['hasVipTag'] = $hasVipTag"))
      && onlineDataRuntimeSource.includes("'platform_tag_summary' => $platformTagSummary")
      && onlineDataRuntimeSource.includes('private function buildMeituanPlatformTagSummary')
      && onlineDataRuntimeSource.includes("'field' => 'raw_data.platformTags'")
      && onlineDataRuntimeSource.includes("'field' => 'raw_data.platformTagStatus'"),
  },
  {
    name: 'Meituan ranking auto-fetch uses a defined rank type for request and parse metadata',
    pass: onlineDataRuntimeSource.includes("$rankType = trim((string)($body['rank_type'] ?? 'P_RZ')) ?: 'P_RZ';")
      && onlineDataRuntimeSource.includes("'rankType' => $rankType")
      && onlineDataRuntimeSource.includes("'rank_type' => $rankType"),
  },
  {
    name: 'frontend renders P0 binding checks in platform profile panel',
    pass: publicSource.includes('(item.checks || []).length')
      && publicSource.includes('platformProfileCheckClass(check.status)')
      && publicSource.includes('const platformProfileCheckClass = (status)'),
  },
  {
    name: 'frontend surfaces competitor summary readiness on home and hotel detail',
    pass: publicSource.includes('homeCompetitorReadiness')
      && publicSource.includes('hotelCompetitorReadiness(hotel)')
      && publicSource.includes('const competitorSummaryReadiness = (summary, hotel = null)')
      && publicSource.includes("const competitorSummaryReadinessClass = requireHomeStatic('competitorSummaryReadinessClass')")
      && homeStaticSource.includes('const competitorSummaryReadinessClass = (readiness)'),
  },
  {
    name: 'Meituan ranking page refreshes competitor summary for selected hotel',
    pass: publicSource.includes('const isMeituanRankingPage = currentPage.value === \'meituan-ebooking\' && onlineDataTab.value === \'meituan-ranking\';')
      && publicSource.includes('const meituanRankingHotelId = isMeituanRankingPage')
      && publicSource.includes('const summaryHotelId = meituanRankingHotelId || String(filterReportHotel.value || \'\')')
      && publicSource.includes('await loadCompetitorSummary({ includeByHotel: false });'),
  },
  {
    name: 'Meituan selected-hotel binding prompt uses saved config identifiers',
    pass: publicSource.includes('selectedMeituanHotelConfig.partner_id || selectedMeituanHotelConfig.partnerId')
      && publicSource.includes('selectedMeituanHotelConfig.poi_id || selectedMeituanHotelConfig.poiId || selectedMeituanHotelConfig.store_id || selectedMeituanHotelConfig.storeId')
      && publicSource.includes('firstNonEmptyText(config.partner_id, config.partnerId)')
      && publicSource.includes('firstNonEmptyText(config.poi_id, config.poiId, config.store_id, config.storeId)')
      && publicSource.includes("const res = await request('/online-data/get-meituan-config-list')")
      && (publicSource.includes('configSource = findMeituanConfigByHotelId(meituanForm.value.hotelId)')
        || publicSource.includes('options.resolvedConfig || selectedMeituanHotelConfig.value')),
  },
  {
    name: 'Meituan ranking insights are not overwritten by client table sort',
    pass: meituanVisibleRankInsightSource.includes("card?.key !== 'tag-metric-link'")
      && !meituanVisibleRankInsightSource.includes('meituanDynamicSelfRankRow'),
  },
  {
    name: 'frontend surfaces VIP platform tag evidence without inference',
    pass: publicSource.includes('homeCompetitorPlatformTagText')
      && (publicSource.includes('hotelCompetitorPlatformTagText(hotel)') || publicSource.includes('homeCompetitorPlatformTagText'))
      && homeStaticSource.includes('const competitorPlatformTagSummary = (summary)')
      && homeStaticSource.includes('displaySummary.platform_tag_summary')
      && homeStaticSource.includes('VIP ')
      && homeStaticSource.includes('raw_data.platformTagStatus'),
  },
  {
    name: 'frontend surfaces VIP platform tag evidence on Meituan ranking page',
    pass: publicSource.includes('data-testid="meituan-ranking-vip-evidence"')
      && publicSource.includes('meituanPlatformTagEvidenceText')
      && publicSource.includes('meituanPlatformTagEvidenceClass'),
  },
  {
    name: 'hotel edit modal opens before slow platform account config refresh',
    pass: openHotelModalSource.includes('showHotelModal.value = true;')
      && openHotelModalSource.includes('ensureHotelOtaConfigLists()')
      && openHotelModalSource.includes('.then(() =>')
      && openHotelModalSource.includes('hotelOtaConfigLoading')
      && !openHotelModalSource.includes('await ensureHotelOtaConfigLists();'),
  },
  {
    name: 'Meituan VIP backfill script is explicit, scoped, and preserves capture timestamps',
    pass: backfillSource.includes('--execute')
      && backfillSource.includes("data_type = 'peer_rank'")
      && backfillSource.includes("raw_data LIKE '%peerRankData%'")
      && backfillSource.includes('platform hotel tags only')
      && backfillSource.includes("'platformTagText'")
      && backfillSource.includes("'hasVipTag'")
      && backfillSource.includes('update_time = :update_time')
      && packageSource.includes('"backfill:meituan-vip-tags"')
      && packageSource.includes('"backfill:meituan-vip-tags:execute"'),
  },
  {
    name: 'hotel management closes the binding-to-competitor next-action loop',
    pass: publicSource.includes("applyHotelQuickFilter('competitor', '1')")
      && publicSource.includes('hotelBindingOverview.competitorReady')
      && publicSource.includes('const hotelCompetitorActionMeta = (hotel = {})')
      && publicSource.includes('const openHotelNextAction = async (hotel = {})')
      && publicSource.includes("openHomeQuickEntry({ page: 'meituan-ebooking', tab: 'meituan-ranking' })"),
  },
  {
    name: 'frontend surfaces mixed collection lifecycle and field asset summary',
    pass: publicSource.includes('data-testid="mixed-collection-lifecycle-panel"')
      && publicSource.includes('Cookie/API 辅助 / 接口 + 登录会话混合采集生命周期')
      && publicSource.includes('data-testid="field-asset-summary-panel"')
      && publicSource.includes('const collectionHealthLifecycleRows = computed')
      && publicSource.includes('const collectionHealthFieldAssetCards = computed'),
  },
  {
    name: 'frontend setup exposes P0 binding check class helper',
    pass: /platformProfileStatusBadgeClass,\s*platformProfileCheckClass,\s*platformProfileBindingText/.test(publicSource),
  },
  {
    name: 'competitor summary remains available for quick judgment',
    pass: ['本店第几', 'TOP1 是谁', '与前一名差多少', 'VIP/平台标签', '榜单升降'].every((text) => (publicSource + meituanStaticSource).includes(text))
      && publicSource.includes("requireMeituanStatic('buildCompetitorSummaryCoreCards')")
      && publicSource.includes("requireMeituanStatic('buildHomeCompetitorSummaryCards')"),
  },
  {
    name: 'Meituan default capture forms and section normalization live in static module',
    pass: meituanStaticSource.includes('const createMeituanRankingForm')
      && meituanStaticSource.includes('const createMeituanBrowserCaptureForm')
      && meituanStaticSource.includes('const normalizeMeituanCaptureSections')
      && publicSource.includes("requireMeituanStatic('createMeituanRankingForm')")
      && publicSource.includes("requireMeituanStatic('createMeituanBrowserCaptureForm')")
      && publicSource.includes("requireMeituanStatic('normalizeMeituanCaptureSections')")
      && !publicSource.includes('const normalizeMeituanCaptureSections = (sections)'),
  },
  {
    name: 'Ctrip default capture forms live in static module',
    pass: ctripStaticSource.includes('const createCtripFetchForm')
      && ctripStaticSource.includes('const createCtripBrowserCaptureForm')
      && ctripStaticSource.includes('const createCtripCookieApiForm')
      && ctripStaticSource.includes('const createCtripEndpointEvidenceForm')
      && publicSource.includes("requireCtripStatic('createCtripFetchForm')")
      && publicSource.includes("requireCtripStatic('createCtripBrowserCaptureForm')")
      && publicSource.includes("requireCtripStatic('createCtripCookieApiForm')")
      && publicSource.includes("requireCtripStatic('createCtripEndpointEvidenceForm')")
      && !publicSource.includes('const ctripOverviewForm = ref({')
      && !publicSource.includes('const ctripCommentForm = ref({'),
  },
  {
    name: 'privacy boundary remains visible for high-risk order and room-state data',
    pass: publicSource.includes('不触碰订单手机号、房态或房源映射')
      && publicSource.includes('不展示订单手机号、Cookie/API 辅助内容或原始敏感数据'),
  },
  {
    name: 'npm script exposes the P0 learning verifier',
    pass: packageSource.includes('"verify:p0-learning": "node scripts/verify_p0_learning_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);

if (failed.length > 0) {
  console.error('[verify:p0-learning] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:p0-learning] ${checks.length} checks passed`);
