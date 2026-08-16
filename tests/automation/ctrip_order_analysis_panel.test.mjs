import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { readAppMainContractSource } from './helpers/frontend_source.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
const contentHash = (source) => crypto.createHash('sha256').update(source).digest('hex').slice(0, 10);

const indexHtml = read('public/index.html');
const loader = read('public/components/online-data/ctrip-order-analysis-loader.js');
const panel = read('public/components/online-data/ctrip-order-analysis-panel.js');
const appMainComponentsLoader = read('public/components/system/app-main-components-loader.js');
const appMainComponents = read('public/components/system/app-main-components.js');
const operatingIntelligenceLoader = read('public/components/system/operating-intelligence-loader.js');
const operatingIntelligenceComponents = read('public/components/system/operating-intelligence-components.js');
const startupHelperBuild = read('scripts/lib/frontend_startup_helpers_build.mjs');
const startupHelperBundle = read('public/app-startup-helpers.min.js');
const appBootstrap = read('public/app-bootstrap.js');
const appMain = readAppMainContractSource();
const ctripPage = read('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html');
const routes = read('route/app.php');
const syncController = read('app/controller/ota/SyncController.php');
const actionCatalog = read('app/domain/Ota/OtaActionCatalog.php');
const actionDispatcher = read('app/service/Ota/OtaActionDispatcher.php');
const actionHandler = read('app/service/Ota/OtaActionHandler.php');
const controllerConcern = read('app/controller/concern/PlatformDataSourceConcern.php');
const analysisService = read('app/service/CtripOrderAnalysisService.php');
const importer = read('app/service/CtripOrderExportImportService.php');

test('authenticated runtime loads the hashed lazy order-analysis component between Vue and app-main', () => {
  const vuePosition = indexHtml.indexOf('vue.runtime.global.prod.js?v=');
  const loaderPosition = indexHtml.indexOf('components/online-data/ctrip-order-analysis-loader.js?v=');
  const appMainPosition = indexHtml.indexOf('app-main.min.js?v=');

  assert.ok(vuePosition >= 0, 'authenticated assets must include runtime Vue');
  assert.ok(loaderPosition > vuePosition, 'order-analysis loader must run after runtime Vue');
  assert.ok(appMainPosition > loaderPosition, 'order-analysis loader must run before app-main');

  const loaderReference = indexHtml.match(
    /components\/online-data\/ctrip-order-analysis-loader\.js\?v=[^"']*-h([a-f0-9]{10})/,
  );
  assert.ok(loaderReference, 'loader reference must carry a ten-character content hash');
  assert.equal(loaderReference[1], contentHash(loader), 'loader reference hash must match loader contents');

  assert.match(loader, /window\.SUXI_SYSTEM_COMPONENTS/);
  assert.match(loader, /components\.CtripOrderAnalysisPanel\s*=\s*Vue\.defineAsyncComponent\s*\(/);
  assert.match(loader, /loader:\s*loadBody/);
  assert.match(loader, /const bodyKey = 'CtripOrderAnalysisPanelBody'/);
  assert.equal((loader.match(/inheritAttrs:\s*false/g) || []).length, 2, 'async loading and error cards must not forward the ctx object to DOM attributes');

  const bodyReference = loader.match(
    /components\/online-data\/ctrip-order-analysis-panel\.js\?v=[^']*-h([a-f0-9]{10})/,
  );
  assert.ok(bodyReference, 'lazy body reference must carry a ten-character content hash');
  assert.equal(bodyReference[1], contentHash(panel), 'lazy body hash must match panel contents');
  assert.ok(panel.length > 1_000, 'lazy panel body must not be an empty placeholder');

  for (const [asset, source] of [
    ['components/system/app-main-components.js', appMainComponents],
    ['components/system/operating-intelligence-components.js', operatingIntelligenceComponents],
  ]) {
    const reference = indexHtml.match(new RegExp(`${asset.replaceAll('.', '\\.') }\\?v=[^"']*-h([a-f0-9]{10})`));
    assert.ok(reference, `${asset} must be present in the authenticated runtime manifest`);
    assert.equal(reference[1], contentHash(source), `${asset} content hash must match its manifest URL`);
  }
  assert.match(indexHtml, /"src": "components\/system\/app-main-components\.js\?v=[^"]+"[\s\S]*?"phase": "after-first-paint"/);
  assert.match(indexHtml, /"src": "components\/system\/operating-intelligence-components\.js\?v=[^"]+"[\s\S]*?"phase": "after-first-paint"/);
  assert.match(startupHelperBuild, /'components\/system\/app-main-components-loader\.js'/);
  assert.match(startupHelperBuild, /'components\/system\/operating-intelligence-loader\.js'/);
  assert.match(startupHelperBundle, /SUXI_APP_MAIN_COMPONENTS/);
  assert.match(startupHelperBundle, /SUXI_OPERATING_INTELLIGENCE_COMPONENTS/);
  assert.match(appMainComponentsLoader, /window\.SUXI_APP_MAIN_COMPONENTS\s*=\s*Object\.freeze/);
  assert.match(appMainComponents, /window\.SUXI_APP_MAIN_COMPONENTS_FULL\s*=\s*exportedFactory/);
  assert.match(operatingIntelligenceLoader, /window\.SUXI_OPERATING_INTELLIGENCE_COMPONENTS\s*=\s*Object\.freeze/);
  assert.match(operatingIntelligenceComponents, /window\.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL\s*=\s*exportedFactory/);
  assert.match(appMainComponentsLoader, /script\.dataset\.suxiAssetLoaded = '1'/);
  assert.match(operatingIntelligenceLoader, /script\.dataset\.suxiAssetLoaded = '1'/);
  assert.match(
    appMain,
    /SUXI_OPERATING_INTELLIGENCE_COMPONENTS\?\.create\?\.\(\{\s*Vue,\s*ref,\s*computed,\s*inject,\s*h,\s*nextTick,\s*onMounted,\s*onUnmounted,/,
    'app-main must pass the Vue runtime APIs required by the operating-intelligence startup bridge',
  );
  assert.match(
    operatingIntelligenceComponents,
    /const create = \(\{ ref, computed, inject, h, nextTick, onMounted, onUnmounted \}\) =>/,
    'the deferred operating-intelligence factory must receive every Vue API used by its setup functions',
  );

  assert.match(appMain, /const CtripOrderAnalysisPanel = systemComponents\.CtripOrderAnalysisPanel \|\| Vue\.defineAsyncComponent\s*\(/);
  assert.match(appMain, /loader:\s*loadCtripOrderAnalysisPanelBody/);
  assert.equal((appMain.match(/inheritAttrs:\s*false/g) || []).length >= 2, true, 'entry fallback cards must not forward the ctx object to DOM attributes');
  assert.match(appMain, /DataConfigDialogs,\s*\.\.\.systemComponents,\s*CtripOrderAnalysisPanel/);
});

test('a dynamically loaded component script remains reusable by the deferred manifest loader', async () => {
  const scripts = [];
  const createScript = () => {
    const handlers = new Map();
    return {
      dataset: {},
      src: '',
      async: false,
      addEventListener: (name, handler) => handlers.set(name, handler),
      remove: () => {},
      dispatch: name => handlers.get(name)?.(),
    };
  };
  const document = {
    baseURI: 'https://hotel.example.test/',
    scripts,
    head: { appendChild: script => scripts.push(script) },
    createElement: createScript,
  };
  const window = {};
  vm.runInNewContext(appMainComponentsLoader, { window, document, URL }, {
    filename: 'app-main-components-loader.js',
  });
  const Vue = {
    defineAsyncComponent: definition => (
      typeof definition === 'function' ? { loader: definition } : definition
    ),
  };
  const components = window.SUXI_APP_MAIN_COMPONENTS.create({ Vue, h: () => null });
  const componentPromise = components.AiDecisionQualityDetails.loader();
  assert.equal(scripts.length, 1, 'dynamic component request must create one full-script element');
  window.SUXI_APP_MAIN_COMPONENTS_FULL = {
    create: () => ({ AiDecisionQualityDetails: { name: 'AiDecisionQualityDetails' } }),
  };
  scripts[0].dispatch('load');
  assert.equal((await componentPromise).name, 'AiDecisionQualityDetails');
  assert.equal(scripts[0].dataset.suxiAssetLoaded, '1');
  assert.match(appBootstrap, /if \(existing\.dataset\.suxiAssetLoaded === '1'\) \{\s*resolve\(\);/);

  const deferredManifestReuse = new Promise((resolve, reject) => {
    if (scripts[0].dataset.suxiAssetLoaded === '1') {
      resolve();
      return;
    }
    scripts[0].addEventListener('load', resolve, { once: true });
    scripts[0].addEventListener('error', reject, { once: true });
  });
  await deferredManifestReuse;
});

test('floating operating consultant requests its full component before any full-render transition', async () => {
  const scripts = [];
  const createScript = () => {
    const handlers = new Map();
    return {
      dataset: {},
      src: '',
      async: false,
      addEventListener: (name, handler) => handlers.set(name, handler),
      remove: () => {},
      dispatch: name => handlers.get(name)?.(),
    };
  };
  const document = {
    baseURI: 'https://hotel.example.test/',
    documentElement: { dataset: {} },
    scripts,
    head: { appendChild: script => scripts.push(script) },
    createElement: createScript,
  };
  const window = {
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => {},
  };
  const CustomEvent = class {
    constructor(type) { this.type = type; }
  };
  vm.runInNewContext(operatingIntelligenceLoader, {
    window, document, URL, CustomEvent,
  }, {
    filename: 'operating-intelligence-loader.js',
  });
  const Vue = {
    defineAsyncComponent: definition => (
      typeof definition === 'function' ? { loader: definition } : definition
    ),
  };
  const components = window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS.create({ Vue, h: () => null });

  const componentPromise = components.operatingQuestionConsultant.loader();
  await Promise.resolve();
  assert.equal(scripts.length, 1, 'the mounted floating consultant must start its full script immediately');
  window.SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL = {
    create: () => ({ operatingQuestionConsultant: { name: 'OperatingQuestionConsultant' } }),
  };
  scripts[0].dispatch('load');
  assert.equal((await componentPromise).name, 'OperatingQuestionConsultant');
});

test('deferred component bridges replace a completed script that did not register its factory', async () => {
  for (const contract of [
    {
      filename: 'app-main-components-loader.js',
      source: appMainComponentsLoader,
      bridgeKey: 'SUXI_APP_MAIN_COMPONENTS',
      fullKey: 'SUXI_APP_MAIN_COMPONENTS_FULL',
      componentKey: 'AiDecisionQualityDetails',
      expectedError: /主应用完整领域组件未完成注册/,
    },
    {
      filename: 'operating-intelligence-loader.js',
      source: operatingIntelligenceLoader,
      bridgeKey: 'SUXI_OPERATING_INTELLIGENCE_COMPONENTS',
      fullKey: 'SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL',
      componentKey: 'operatingQuestionPanel',
      expectedError: /经营问答完整组件未完成注册/,
    },
  ]) {
    const scripts = [];
    const createScript = () => {
      const handlers = new Map();
      const script = {
        dataset: {},
        src: '',
        async: false,
        addEventListener: (name, handler) => handlers.set(name, handler),
        remove: () => {
          const index = scripts.indexOf(script);
          if (index >= 0) scripts.splice(index, 1);
        },
        dispatch: name => handlers.get(name)?.(),
      };
      return script;
    };
    const document = {
      baseURI: 'https://hotel.example.test/',
      documentElement: { dataset: { suxiFullRenderReady: '1' } },
      scripts,
      head: { appendChild: script => scripts.push(script) },
      createElement: createScript,
    };
    const window = {
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => {},
    };
    const CustomEvent = class {
      constructor(type) { this.type = type; }
    };
    vm.runInNewContext(contract.source, { window, document, URL, CustomEvent }, {
      filename: contract.filename,
    });
    const Vue = {
      defineAsyncComponent: definition => (
        typeof definition === 'function' ? { loader: definition } : definition
      ),
    };
    const components = window[contract.bridgeKey].create({ Vue, h: () => null });

    const firstAttempt = components[contract.componentKey].loader();
    await Promise.resolve();
    assert.equal(scripts.length, 1, `${contract.filename} must create its first full-script node`);
    scripts[0].dispatch('load');
    await assert.rejects(firstAttempt, contract.expectedError);
    assert.equal(scripts.length, 0, `${contract.filename} must remove a completed unregistered script`);

    const retry = components[contract.componentKey].loader();
    await Promise.resolve();
    assert.equal(scripts.length, 1, `${contract.filename} retry must create a fresh full-script node`);
    window[contract.fullKey] = {
      create: () => ({ [contract.componentKey]: { name: contract.componentKey } }),
    };
    scripts[0].dispatch('load');
    assert.equal((await retry).name, contract.componentKey);
    assert.equal(scripts[0].dataset.suxiAssetLoaded, '1');
  }
});

test('deferred component bridges discard a failed manifest script before retrying', async () => {
  for (const contract of [
    {
      filename: 'app-main-components-loader.js',
      source: appMainComponentsLoader,
      fullScript: 'components/system/app-main-components.js?v=20260816-runtime-closure-h69f30ff2c7',
      bridgeKey: 'SUXI_APP_MAIN_COMPONENTS',
      fullKey: 'SUXI_APP_MAIN_COMPONENTS_FULL',
      componentKey: 'AiDecisionQualityDetails',
      expectedError: /主应用完整领域组件加载失败/,
    },
    {
      filename: 'operating-intelligence-loader.js',
      source: operatingIntelligenceLoader,
      fullScript: 'components/system/operating-intelligence-components.js?v=20260816-runtime-closure-hdadd9b311e',
      bridgeKey: 'SUXI_OPERATING_INTELLIGENCE_COMPONENTS',
      fullKey: 'SUXI_OPERATING_INTELLIGENCE_COMPONENTS_FULL',
      componentKey: 'operatingQuestionPanel',
      expectedError: /经营问答完整组件加载失败/,
    },
  ]) {
    const scripts = [];
    const createScript = () => {
      const handlers = new Map();
      const script = {
        dataset: {},
        src: '',
        async: false,
        addEventListener: (name, handler) => handlers.set(name, handler),
        remove: () => {
          const index = scripts.indexOf(script);
          if (index >= 0) scripts.splice(index, 1);
        },
        dispatch: name => handlers.get(name)?.(),
      };
      return script;
    };
    const document = {
      baseURI: 'https://hotel.example.test/',
      documentElement: { dataset: { suxiFullRenderReady: '1' } },
      scripts,
      head: { appendChild: script => scripts.push(script) },
      createElement: createScript,
    };
    const manifestScript = createScript();
    manifestScript.src = new URL(contract.fullScript, document.baseURI).href;
    scripts.push(manifestScript);
    const window = {
      addEventListener: () => {},
      removeEventListener: () => {},
      dispatchEvent: () => {},
    };
    const CustomEvent = class {
      constructor(type) { this.type = type; }
    };
    vm.runInNewContext(contract.source, { window, document, URL, CustomEvent }, {
      filename: contract.filename,
    });
    const Vue = {
      defineAsyncComponent: definition => (
        typeof definition === 'function' ? { loader: definition } : definition
      ),
    };
    const components = window[contract.bridgeKey].create({ Vue, h: () => null });

    const firstAttempt = components[contract.componentKey].loader();
    await Promise.resolve();
    assert.equal(scripts.length, 1, `${contract.filename} must reuse the manifest-created script`);
    manifestScript.dispatch('error');
    await assert.rejects(firstAttempt, contract.expectedError);
    assert.equal(scripts.length, 0, `${contract.filename} must remove the failed manifest-created script`);

    const retry = components[contract.componentKey].loader();
    await Promise.resolve();
    assert.equal(scripts.length, 1, `${contract.filename} retry must create a fresh script after manifest failure`);
    window[contract.fullKey] = {
      create: () => ({ [contract.componentKey]: { name: contract.componentKey } }),
    };
    scripts[0].dispatch('load');
    assert.equal((await retry).name, contract.componentKey);
    assert.equal(scripts[0].dataset.suxiAssetLoaded, '1');
  }
});

test('Ctrip upload page renders immediate import preview and the persisted range-analysis panel', () => {
  assert.match(panel, /data-testid['"]?:\s*['"]ctrip-channel-order-upload-preview['"]/);
  for (const binding of [
    'ctripChannelOrderUploadPreview',
    'ctripChannelOrderUploadChannels',
    'ctripChannelOrderUploadTotalOrders',
    'ctripChannelOrderUploadGrossOrders',
    'ctripChannelOrderUploadCancelledOrders',
    'ctripChannelOrderUploadCancelRate',
    'ctripChannelOrderPortraitInsight',
  ]) {
    assert.ok(panel.includes(binding), `lazy upload preview must render ${binding}`);
  }
  assert.match(ctripPage, /<ctrip-order-analysis-panel\s+:ctx="\$root"><\/ctrip-order-analysis-panel>/);
  assert.match(appMain, /const openCtripChannelOrderEvidenceUpload = \(\) => \{/);
  assert.match(appMain, /ctripChannelOrderUploadOpen\.value = true/);
  assert.match(appMain, /\[data-testid="ctrip-channel-order-upload"\]/);
  assert.match(appMain, /openCtripChannelOrderEvidenceUpload, handleCtripChannelOrderFileChange/);

  const deepPanelsPosition = ctripPage.indexOf('v-if="ctripEbookingDeepPanelsReady"');
  const snapshotGatePosition = ctripPage.indexOf('v-if="collectionReliabilityHasCurrentSnapshot"');
  const uploadPosition = ctripPage.indexOf('data-testid="ctrip-channel-order-upload"');
  const analysisPosition = ctripPage.indexOf('<ctrip-order-analysis-panel');
  assert.ok(deepPanelsPosition >= 0, 'page must retain deferred non-order business panels');
  assert.ok(snapshotGatePosition >= 0, 'page must retain the current-snapshot evidence gate');
  assert.ok(uploadPosition >= 0 && uploadPosition < snapshotGatePosition, 'order upload must remain visible when no fresh collection snapshot exists');
  assert.ok(analysisPosition >= 0 && analysisPosition < snapshotGatePosition, 'persisted order analysis must remain visible when no fresh collection snapshot exists');
  assert.ok(uploadPosition >= 0 && uploadPosition < deepPanelsPosition, 'order upload must remain visible before deferred panels hydrate');
  assert.ok(analysisPosition >= 0 && analysisPosition < deepPanelsPosition, 'persisted order analysis must remain visible before deferred panels hydrate');
});

test('order-analysis panel performs a scoped authenticated GET and preserves missing evidence', () => {
  assert.match(panel, /new URLSearchParams\(\{ system_hotel_id: String\(hotelId\) \}\)/);
  assert.match(panel, /params\.set\('date_from', this\.dateFrom\)/);
  assert.match(panel, /params\.set\('date_to', this\.dateTo\)/);
  assert.match(panel, /fetch\(`\/api\/online-data\/ctrip\/order-analysis\?\$\{params\.toString\(\)}`/);
  assert.match(panel, /headers:\s*authToken\s*\?\s*\{ Authorization: `Bearer \$\{authToken}` \}\s*:\s*\{\}/);
  assert.match(panel, /cache:\s*'no-store'/);
  assert.match(panel, /type="date"/);
  assert.match(panel, /render\(\)\s*\{/);

  const fetchStart = panel.indexOf('const response = await fetch(`/api/online-data/ctrip/order-analysis?');
  const fetchEnd = panel.indexOf('});', fetchStart);
  assert.ok(fetchStart >= 0 && fetchEnd > fetchStart, 'panel must contain its range-analysis fetch block');
  assert.doesNotMatch(panel.slice(fetchStart, fetchEnd), /method\s*:/, 'analysis read must use fetch default GET');

  assert.match(panel, /参考底价（非确认收入）/);
  assert.match(panel, /参考底价不是确认收入/);
  assert.match(panel, /旧聚合没有精确分布，不能从平均值反推/);
  assert.match(panel, /现存 v1 聚合没有逐状态回执，已入住订单不可独立核验/);
  assert.match(panel, /旧聚合仅保留每日 Top5，不能恢复完整房型排名/);
  assert.match(panel, /人工文件来源仍为待核验/);
  assert.match(panel, /旧聚合保存口径；缺逐单去重回执/);
  assert.match(panel, /已保存订单分析与实时 Cookie 采集相互独立；顶部授权告警只影响实时抓取/);
  assert.match(panel, /分析报告 HTML 可作材料对照，但不能替代逐笔订单、去重、状态和排除规则回执/);
  assert.match(panel, /data-testid['"]?:\s*['"]ctrip-order-analysis-open-upload['"]/);
  assert.match(panel, /this\.ctx\?\.openCtripChannelOrderEvidenceUpload/);
});

test('order-analysis route is owned by the sync catalog and reaches the scoped service', () => {
  assert.match(routes, /Route::get\('\/ctrip\/order-analysis',\s*'ota\.SyncController\/ctripOrderAnalysis'\)/);
  assert.match(syncController, /public function ctripOrderAnalysis\(\): Response/);
  assert.match(syncController, /return \$this->execute\(__FUNCTION__\)/);
  assert.match(syncController, /OtaDomain::SYNC/);
  assert.match(actionCatalog, /OtaDomain::SYNC\s*=>\s*\[[\s\S]*?'ctripOrderAnalysis'/);
  assert.match(actionDispatcher, /OtaActionCatalog::assertOwned\(\$domain, \$action\)/);
  assert.match(actionHandler, /use app\\controller\\concern\\PlatformDataSourceConcern/);
  assert.match(actionHandler, /use PlatformDataSourceConcern/);

  assert.match(controllerConcern, /public function ctripOrderAnalysis\(\): Response/);
  assert.match(controllerConcern, /checkActionPermission\('can_view_online_data'\)/);
  assert.match(controllerConcern, /hasHotelPermission\(\(int\)\$systemHotelId, 'can_view_online_data'\)/);
  assert.match(controllerConcern, /field\('id,name,tenant_id'\)/);
  assert.match(controllerConcern, /\(new CtripOrderAnalysisService\(\)\)->analyzeStoredRange\(/);
  assert.match(controllerConcern, /\$requestData\['date_from'\]/);
  assert.match(controllerConcern, /\$requestData\['date_to'\]/);
  assert.match(controllerConcern, /携程订单分析已回读/);
});

test('stored analysis selects one exact-readback batch and enforces v1/v2 amount semantics', () => {
  assert.match(analysisService, /private const CONTRACT_V1 = 'ctrip_order_aggregate_v1'/);
  assert.match(analysisService, /private const CONTRACT_V2 = 'ctrip_order_aggregate_v2'/);
  assert.match(analysisService, /Db::name\('online_daily_data'\)/);
  assert.match(analysisService, /->where\('tenant_id', \$tenantId\)/);
  assert.match(analysisService, /->where\('system_hotel_id', \$systemHotelId\)/);
  assert.match(analysisService, /->where\('source', 'ctrip'\)/);
  assert.match(analysisService, /->where\('data_type', 'order'\)/);
  assert.match(analysisService, /\$batchKey = \$taskId > 0[\s\S]*?'task:' \. \$taskId[\s\S]*?'legacy-source:' \. \$sourceId/);
  assert.match(analysisService, /'all_readback_verified'\s*=>\s*true/);
  assert.match(analysisService, /latest_single_readback_verified_import_batch_no_cross_batch_stitching/);
  assert.match(analysisService, /不会拼接其他批次/);
  assert.doesNotMatch(
    analysisService,
    /array_merge\s*\([^\n]*\$batches/,
    'analysis must not concatenate rows from separate stored batches',
  );

  assert.match(analysisService, /in_array\(\$contract, \[self::CONTRACT_V1, self::CONTRACT_V2\], true\)/);
  assert.match(analysisService, /count\(\$contracts\) !== 1/);
  assert.match(analysisService, /V2 订单批次的数据集哈希缺失或不一致/);
  assert.match(analysisService, /reference_bottom_price_not_confirmed_revenue/);
  assert.match(analysisService, /\$summary\['stayed_orders'\] = null/);
  assert.match(analysisService, /missingDimension\('los_distribution'/);
  assert.match(analysisService, /missingDimension\('lead_time_distribution'/);
  assert.match(analysisService, /'persistence_readback_status'\s*=>\s*'verified'/);

  assert.match(importer, /private const IMPORT_CONTRACT = 'ctrip_order_aggregate_v2'/);
  assert.match(importer, /private const CLASSIFICATION_POLICY_VERSION = 'ctrip_order_classification_v2'/);
  assert.match(importer, /private const EXCLUSION_POLICY_VERSION = 'ctrip_order_exclusion_v1_unverified_not_applied'/);
  assert.match(importer, /'amount'\s*=>\s*null/);
  assert.match(importer, /'amount_semantics'\s*=>\s*'reference_bottom_price_not_confirmed_revenue'/);
  assert.match(importer, /'exclusion_policy_status'\s*=>\s*'unverified_not_applied'/);
});
