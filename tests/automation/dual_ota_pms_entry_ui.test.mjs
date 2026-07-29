import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = process.cwd();
const read = path => readFileSync(resolve(root, path), 'utf8');

const authController = read('app/controller/Auth.php');
const appMain = read('public/app-main.js');
const workbenchTemplate = read('resources/frontend/templates/fragments/23b-page-ai-workbench.html');

assert.match(
  authController,
  /use app\\service\\HotelPmsBindingService;/,
  'auth payload must use the existing hotel PMS binding service'
);
assert.match(
  authController,
  /buildPermittedHotels[\s\S]*appendPmsSelectionSummaries[\s\S]*pms_binding_status[\s\S]*pms_provider_label/,
  'permitted hotel rows must carry their truthful PMS selection summary'
);
assert.match(
  appMain,
  /const dualOtaConfiguredPms = computed\([\s\S]*pms_binding_status[\s\S]*configured[\s\S]*dingdandao_pms[\s\S]*meituan_cloud_pms/,
  'the workbench must expose PMS only for one configured provider'
);
assert.match(
  workbenchTemplate,
  /aria-label="经营数据源选择"[\s\S]*v-if="dualOtaConfiguredPms"[\s\S]*data-testid="dual-ota-pms-entry"[\s\S]*@click="openDualOtaPms"/,
  'configured PMS must be a peer option in the operating data-source selector'
);
assert.ok(
  workbenchTemplate.indexOf('data-testid="dual-ota-pms-entry"')
    > workbenchTemplate.indexOf('aria-label="经营数据源选择"'),
  'PMS must sit after the selector starts instead of inside the current-hotel control'
);
const pmsEntryMarkup = workbenchTemplate.match(
  /<button v-if="dualOtaConfiguredPms"[\s\S]*?data-testid="dual-ota-pms-entry"[\s\S]*?<\/button>/
)?.[0] || '';
assert.match(
  pmsEntryMarkup,
  /<strong>PMS<\/strong>/,
  'the PMS source option must use the single short PMS label'
);
assert.doesNotMatch(
  pmsEntryMarkup,
  /shortLabel|<small>/,
  'the PMS source option must not append the configured provider name'
);
assert.match(
  appMain,
  /const openDualOtaPms = async \(\) => \{[\s\S]*applyDualOtaPmsContext\(\)[\s\S]*dualOtaPmsSelected\.value = true[\s\S]*loadOperatingTarget\(\)/,
  'the PMS option must keep the selected hotel and load PMS facts inside the workbench'
);
assert.doesNotMatch(
  appMain.match(/const openDualOtaPms = async \(\) => \{[\s\S]*?\n\s*\};/)?.[0] || '',
  /currentPage\.value = 'pms-operating-data'/,
  'the primary PMS option must not navigate away from the workbench'
);
assert.match(
  appMain,
  /const openDualOtaPmsDetail = async \(\) => \{[\s\S]*currentPage\.value = 'pms-operating-data'[\s\S]*restorePmsContextIfNeeded/,
  'the optional full-detail action may navigate to the existing PMS page'
);
assert.match(
  workbenchTemplate,
  /data-testid="dual-ota-pms-view"[\s\S]*data-testid="dual-ota-pms-evidence"[\s\S]*data-testid="dual-ota-pms-metrics"/,
  'the workbench must render PMS source evidence and metrics in place'
);
assert.match(
  workbenchTemplate,
  /data-testid="dual-ota-pms-live-sync"[\s\S]*@click="syncDualOtaPmsRealtime"/,
  'the in-place PMS view must expose the truthful real-time sync action'
);
assert.match(
  workbenchTemplate,
  /<section data-testid="home-temporal-axis"[\s\S]*dualOtaPmsSelected \? 'PMS数据状态' : '数据进度与预测'[\s\S]*dualOtaTemporalCards/,
  'the temporal overview must keep one stable shell and switch its content for PMS'
);
assert.doesNotMatch(
  workbenchTemplate,
  /<section v-if="!dualOtaPmsSelected" data-testid="home-temporal-axis"/,
  'selecting PMS must not remove the temporal overview and pull the page upward'
);
assert.match(
  appMain,
  /const dualOtaPmsTemporalCards = computed\([\s\S]*来源身份[\s\S]*经营快照[\s\S]*事实验真[\s\S]*const dualOtaTemporalCards = computed/,
  'the stable overview must expose PMS source, snapshot, and verification truth'
);
assert.match(
  appMain,
  /const dualOtaPmsSelected = ref\(false\)[\s\S]*persistDualOtaWorkbenchPreferences[\s\S]*store_scope: dualOtaSelectedStoreScope\.value/,
  'PMS selection must remain browser-local and outside persisted OTA scope'
);
assert.match(
  appMain,
  /SUXI_INITIAL_PMS_CONTEXT_OVERRIDE[\s\S]*initialPmsHotelOverride[\s\S]*hotel_id: initialPmsHotelOverride/,
  'the startup-to-full-render handoff must preserve the selected PMS hotel'
);
assert.doesNotMatch(
  workbenchTemplate,
  /setDualOtaStoreScope\(['"]pms['"]\)/,
  'PMS must not be folded into the Ctrip or Meituan OTA metric scope'
);
