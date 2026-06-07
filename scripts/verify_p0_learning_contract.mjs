import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = existsSync('app/controller/OnlineData.php') ? '.' : 'HOTEL';
const read = (path) => readFileSync(join(root, path), 'utf8');

const controllerSource = read('app/controller/OnlineData.php');
const publicSource = read('public/index.html');
const packageSource = read('package.json');
const backfillSource = read('scripts/backfill_meituan_vip_tags.php');
const openHotelModalStart = publicSource.indexOf('const openHotelModal = async (hotel = null, options = {})');
const openHotelModalEnd = publicSource.indexOf('const saveHotel = async () =>', openHotelModalStart);
const openHotelModalSource = openHotelModalStart >= 0 && openHotelModalEnd > openHotelModalStart
  ? publicSource.slice(openHotelModalStart, openHotelModalEnd)
  : '';

const checks = [
  {
    name: 'platform profile status exposes P0 binding checks',
    pass: controllerSource.includes('private function buildPlatformProfileBindingChecks')
      && controllerSource.includes("'binding_checks' => $bindingChecks")
      && controllerSource.includes("'p0_readiness' => $p0Readiness"),
  },
  {
    name: 'platform profile summary counts collection readiness and identity blockers',
    pass: controllerSource.includes("'ready_to_collect' => $readyToCollect")
      && controllerSource.includes("'needs_identity_check' => $needsIdentityCheck")
      && controllerSource.includes("'identity_blocked' => $identityBlocked"),
  },
  {
    name: 'P0 binding checks cover hotel binding, platform identity, login, and trial capture',
    pass: ['hotel_binding', 'platform_identity', 'profile_login', 'trial_capture'].every((key) => controllerSource.includes(`'key' => '${key}'`)),
  },
  {
    name: 'collection reliability exposes lifecycle catalog and field asset summary',
    pass: controllerSource.includes("'collection_lifecycle_catalog' => $this->collectionLifecycleCatalog()")
      && controllerSource.includes("'field_asset_summary' => $this->summarizeOtaCollectionFieldDefinitions($fieldDefinitions)")
      && controllerSource.includes('private function summarizeOtaCollectionFieldDefinitions'),
  },
  {
    name: 'competitor summary API exposes readiness for trusted quick judgment',
    pass: controllerSource.includes('private function buildMeituanCompetitorSummaryReadiness')
      && controllerSource.includes("'readiness' => $readiness")
      && controllerSource.includes("'readiness' => $this->buildMeituanCompetitorSummaryReadiness([], $context, false)"),
  },
  {
    name: 'Meituan VIP and platform tags are recorded as field assets',
    pass: controllerSource.includes("'platformTags' => $platformTagInfo['tags']")
      && controllerSource.includes("'hasVipTag' => $hasVipTag")
      && controllerSource.includes("'platform_tag_summary' => $platformTagSummary")
      && controllerSource.includes('private function buildMeituanPlatformTagSummary')
      && controllerSource.includes("'field' => 'raw_data.platformTags'")
      && controllerSource.includes("'field' => 'raw_data.platformTagStatus'"),
  },
  {
    name: 'Meituan ranking auto-fetch uses a defined rank type for request and parse metadata',
    pass: controllerSource.includes("$rankType = trim((string)($body['rank_type'] ?? 'P_RZ')) ?: 'P_RZ';")
      && controllerSource.includes("'rankType' => $rankType")
      && controllerSource.includes("'rank_type' => $rankType"),
  },
  {
    name: 'frontend renders P0 binding checks in platform profile panel',
    pass: publicSource.includes('item.binding_checks && item.binding_checks.length')
      && publicSource.includes('platformProfileCheckClass(check.status)')
      && publicSource.includes('const platformProfileCheckClass = (status)'),
  },
  {
    name: 'frontend surfaces competitor summary readiness on home and hotel detail',
    pass: publicSource.includes('homeCompetitorReadiness')
      && publicSource.includes('hotelCompetitorReadiness(hotelFormAccountHotel())')
      && publicSource.includes('const competitorSummaryReadiness = (summary, hotel = null)')
      && publicSource.includes('const competitorSummaryReadinessClass = (readiness)'),
  },
  {
    name: 'frontend surfaces VIP platform tag evidence without inference',
    pass: publicSource.includes('homeCompetitorPlatformTagText')
      && publicSource.includes('hotelCompetitorPlatformTagText(hotelFormAccountHotel())')
      && publicSource.includes('const competitorPlatformTagSummary = (summary)')
      && publicSource.includes('displaySummary.platform_tag_summary')
      && publicSource.includes('VIP标签证据')
      && publicSource.includes('平台标签未返回，不推断VIP'),
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
      && backfillSource.includes("data_type = 'business'")
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
      && publicSource.includes('平台授权 / 接口 + 登录会话混合采集生命周期')
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
    pass: ['本店第几', 'TOP1 是谁', '与前一名差多少', 'VIP/平台标签', '榜单升降'].every((text) => publicSource.includes(text)),
  },
  {
    name: 'privacy boundary remains visible for high-risk order and room-state data',
    pass: publicSource.includes('不触碰订单手机号、房态或房源映射')
      && publicSource.includes('不展示订单手机号、平台授权或原始敏感数据'),
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
