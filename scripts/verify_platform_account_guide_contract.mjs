import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(path, 'utf8');
const publicSource = read('public/index.html');
const systemStaticSource = read('public/system-static.js');
const packageSource = read('package.json');
const controllerSource = read('app/controller/OnlineData.php');

const sliceBetween = (source, startNeedle, endNeedle) => {
  const start = source.indexOf(startNeedle);
  if (start < 0) return '';
  const end = source.indexOf(endNeedle, start + startNeedle.length);
  return end > start ? source.slice(start, end) : source.slice(start);
};

const guideMarkup = sliceBetween(
  publicSource,
  'data-testid="platform-account-binding-guide"',
  '<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">'
);
const onlineDataTabs = sliceBetween(
  publicSource,
  '<div v-if="currentPage === \'online-data\'">',
  'data-testid="online-data-health-panel"'
);
const guideRows = sliceBetween(
  publicSource,
  'const platformAccountBindingGuideRows = computed',
  'const platformAccountBindingStatusRows = computed'
);
const statusRows = sliceBetween(
  publicSource,
  'const platformAccountBindingStatusRows = computed',
  'const platformImportForm = ref'
);
const applyGuide = sliceBetween(
  publicSource,
  'const applyPlatformAccountBindingGuide = (preset) =>',
  'const resetPlatformDataSourceForm = () =>'
);
const setupReturn = sliceBetween(
  publicSource,
  'platformDataSourceConfigPlaceholder, platformDataSourceSecretPlaceholder',
  'platformImportForm'
);
const profileStatusMarkup = sliceBetween(
  publicSource,
  '<div v-for="item in platformProfileStatusRows"',
  '<div v-if="onlineDataTab === \'ctrip-download\'"'
);
const profileActionHandlers = sliceBetween(
  publicSource,
  'const openPlatformProfileAction = async',
  'const fillPlatformProfileForms ='
);
const hotelActionBlock = sliceBetween(
  systemStaticSource,
  'const platformNextActionMeta =',
  'const platformAccountStoreText ='
) + '\n' + sliceBetween(
  publicSource,
  'const buildHotelPlatformAccountRowStatic',
  'const refreshHotelBindingPanel = async'
);

const checks = [
  {
    name: 'platform data-source panel exposes account binding guide',
    pass: guideMarkup.includes('平台账号绑定向导')
      && guideMarkup.includes('Cookie / API / Profile')
      && guideMarkup.includes('platformAccountBindingGuideRows')
      && guideMarkup.includes('platformAccountBindingStatusRows'),
  },
  {
    name: 'online data tabs expose a direct platform account guide entry',
    pass: onlineDataTabs.includes("onlineDataTab = 'platform-sources'")
      && onlineDataTabs.includes("onlineDataTab === 'platform-sources'")
      && onlineDataTabs.includes('loadPlatformDataSourcePanel()')
      && onlineDataTabs.includes('loadPlatformProfileStatus({ silent: true })'),
  },
  {
    name: 'guide presets cover Ctrip Profile, Meituan Profile and Cookie/API',
    pass: guideRows.includes("key: 'ctrip-profile'")
      && guideRows.includes("key: 'meituan-profile'")
      && guideRows.includes("key: 'cookie-api'")
      && guideRows.includes("ingestionMethod: 'browser_profile'")
      && guideRows.includes("ingestionMethod: 'api'"),
  },
  {
    name: 'Meituan Profile guide stays on non-privacy traffic/rank path',
    pass: guideRows.includes("capture_sections: 'traffic'")
      && guideRows.includes('不配置订单手机号、房态、房源映射')
      && !guideRows.includes("capture_sections: 'traffic,orders'")
      && !guideRows.includes("capture_sections: 'orders'")
      && !guideRows.includes('room_status')
      && !guideRows.includes('room_source_mapping'),
  },
  {
    name: 'guide status keeps missing identity and trial capture states visible',
    pass: statusRows.includes('identity_blocked')
      && statusRows.includes('needs_identity_check')
      && statusRows.includes('ready_to_collect')
      && guideMarkup.includes('POI不匹配')
      && guideMarkup.includes('试采集失败'),
  },
  {
    name: 'backend profile status returns structured direct next actions',
    pass: controllerSource.includes('buildPlatformProfileBindingCheck')
      && controllerSource.includes("'primary_action' => $primaryAction")
      && controllerSource.includes("'configure_platform_profile'")
      && controllerSource.includes("'platform-sources'")
      && controllerSource.includes("'login_platform_profile'")
      && controllerSource.includes("'open_sync_logs'")
      && controllerSource.includes("'sync-logs'"),
  },
  {
    name: 'profile status cards render action buttons from backend action metadata',
    pass: profileStatusMarkup.includes('check.action_label')
      && profileStatusMarkup.includes('openPlatformProfileAction(item, check)')
      && profileStatusMarkup.includes('item.primary_action.action_label')
      && setupReturn.includes('openPlatformProfileAction'),
  },
  {
    name: 'frontend routes POI, login and failed trial actions to direct panels',
    pass: profileActionHandlers.includes("target === 'profile-login'")
      && profileActionHandlers.includes("target === 'sync-logs'")
      && profileActionHandlers.includes("target === 'platform-auto'")
      && profileActionHandlers.includes("target === 'meituan-ranking'"),
  },
  {
    name: 'hotel management next action carries direct targets',
    pass: systemStaticSource.includes('const buildHotelPlatformAccountRow')
      && hotelActionBlock.includes('buildHotelPlatformAccountRowStatic')
      && hotelActionBlock.includes("target: 'profile-login'")
      && hotelActionBlock.includes("target: 'sync-logs'")
      && hotelActionBlock.includes('nextActionTarget')
      && hotelActionBlock.includes("action?.target === 'profile-login'")
      && hotelActionBlock.includes("action?.target === 'sync-logs'"),
  },
  {
    name: 'applying a guide clears secret_json and only pre-fills config_json',
    pass: applyGuide.includes('config_json: JSON.stringify(preset.config || {}, null, 2)')
      && applyGuide.includes("secret_json: ''")
      && applyGuide.includes('敏感凭证请在密文区单独填写'),
  },
  {
    name: 'guide bindings are returned from setup',
    pass: setupReturn.includes('platformAccountBindingGuideRows')
      && setupReturn.includes('platformAccountBindingStatusRows')
      && setupReturn.includes('applyPlatformAccountBindingGuide'),
  },
  {
    name: 'npm script exposes platform account guide verifier',
    pass: packageSource.includes('"verify:platform-account-guide": "node scripts/verify_platform_account_guide_contract.mjs"'),
  },
];

const failed = checks.filter((check) => !check.pass);
if (failed.length > 0) {
  console.error('[verify:platform-account-guide] failed checks:');
  for (const check of failed) {
    console.error(`- ${check.name}`);
  }
  process.exit(1);
}

console.log(`[verify:platform-account-guide] ${checks.length} checks passed`);
