import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (file) => readFileSync(file, 'utf8');

const agents = read('AGENTS.md');
const rule = read('rules/business-page-contract.md');
const registry = JSON.parse(read('rules/business-page-contract-registry.json'));
const templateManifest = JSON.parse(read(registry.source_manifest));
const absorption = read('docs/capability-absorption/2026-08-22-business-page-contract.md');
const baseController = read('app/controller/Base.php');
const appMain = read('public/app-main.js');
const systemStatic = read('public/system-static.js');
const ctripRegression = read('tests/automation/ctrip_channel_order_breakdown.test.mjs');
const visualSmoke = read('scripts/verify_taste_visual_smoke.mjs');
const businessPageVerifier = read('scripts/verify_business_page_contract.mjs');
const packageJson = JSON.parse(read('package.json'));

const registryFragmentIds = registry.surfaces.flatMap((surface) => surface.fragment_ids);
const includedManifestFragments = templateManifest.fragments.filter((fragment) =>
  registry.included_manifest_domains.includes(fragment.domain),
);

const assertChecks = (surface, checks, kind) => {
  assert.ok(Array.isArray(checks) && checks.length > 0, `${surface.id} requires ${kind} checks`);
  for (const check of checks) {
    assert.equal(typeof check.path, 'string', `${surface.id} ${kind} path must be a string`);
    const target = read(check.path);
    assert.ok(Array.isArray(check.includes) && check.includes.length > 0, `${surface.id} ${kind} needs markers`);
    for (const marker of check.includes) {
      assert.ok(target.includes(marker), `${surface.id} ${kind} missing ${marker} in ${check.path}`);
    }
  }
};

test('durable project instructions route relevant work through the business page contract', () => {
  assert.match(agents, /经营页面合同（适当严格执行）/);
  assert.match(agents, /rules\/business-page-contract\.md/);
  assert.match(agents, /不要求无关页面机械实现全部状态/);
  assert.match(agents, /不为统一格式一次性重写无关控制器/);
});

test('business page contract keeps truth gates strict and governance proportional', () => {
  for (const required of [
    'tenant_id',
    'system_hotel_id',
    'platform_store_id',
    'business_date',
    'source_method',
    'collected_at',
    'schema_version',
    '`loading`',
    '`ready`',
    '`empty`',
    '`partial`',
    '`stale`',
    '`unverified`',
    '`blocked`',
    '`error`',
    '同一 ViewModel',
    '不得用 `0`、空数组、旧数据、缓存、其他门店数据',
    '非默认阻塞项',
    '六视口',
  ]) {
    assert.ok(rule.includes(required), `business page contract missing: ${required}`);
  }
});

test('registry classifies every manifest domain and covers every included fragment exactly once', () => {
  assert.equal(registry.schema_version, 'business-page-coverage.v1');
  assert.equal(registry.policy.new_fragment, 'fail_closed_until_registered');
  assert.equal(registry.policy.maximum_claim, 'unit_contract_pass');
  assert.equal(registry.policy.field_validation_claimed, false);

  const includedDomains = registry.included_manifest_domains;
  const excludedDomains = registry.excluded_manifest_domains.map((item) => item.domain);
  const manifestDomains = [...new Set(templateManifest.fragments.map((fragment) => fragment.domain))].sort();
  assert.equal(new Set(includedDomains).size, includedDomains.length, 'included domains must be unique');
  assert.equal(new Set(excludedDomains).size, excludedDomains.length, 'excluded domains must be unique');
  assert.deepEqual(
    includedDomains.filter((domain) => excludedDomains.includes(domain)),
    [],
    'a manifest domain cannot be both included and excluded',
  );
  assert.deepEqual(
    [...includedDomains, ...excludedDomains].sort(),
    manifestDomains,
    'every manifest domain must be explicitly included or excluded',
  );
  for (const exclusion of registry.excluded_manifest_domains) {
    assert.ok(exclusion.reason.length >= 10, `${exclusion.domain} exclusion needs a reason`);
    assert.ok(exclusion.covered_by.length >= 8, `${exclusion.domain} exclusion needs a replacement guard`);
  }

  assert.equal(
    new Set(registryFragmentIds).size,
    registryFragmentIds.length,
    'a manifest fragment must not inherit two competing page contracts',
  );
  assert.deepEqual(
    [...registryFragmentIds].sort(),
    includedManifestFragments.map((fragment) => fragment.id).sort(),
    'every fragment in a covered product-chain domain must be registered, with no unknown fragment ids',
  );

  for (const fragment of includedManifestFragments) {
    const fragmentPath = `resources/frontend/templates/${fragment.path}`;
    const source = read(fragmentPath);
    assert.ok(source.trim().length > 0, `${fragment.id} points to an empty template fragment`);
    assert.equal(typeof fragment.anchor, 'string', `${fragment.id} must retain a manifest anchor description`);
    assert.ok(fragment.anchor.trim().length > 0, `${fragment.id} has an empty manifest anchor description`);
  }
});

test('active surfaces enforce scope truth states and focused regressions while frozen surfaces stay explicit', () => {
  const requiredStrictDimensions = [
    'scope_identity',
    'truthful_states',
    'failure_visibility',
    'focused_regression',
  ];

  const surfaceIds = registry.surfaces.map((surface) => surface.id);
  assert.equal(new Set(surfaceIds).size, surfaceIds.length, 'surface ids must be unique');

  for (const surface of registry.surfaces) {
    assert.ok(surface.fragment_ids.length > 0, `${surface.id} must own at least one fragment`);
    assert.ok(surface.scope_dimensions.length > 0, `${surface.id} must declare its fact scope`);
    assert.ok(surface.state_requirements.length > 0, `${surface.id} must declare truthful states`);
    assert.equal(typeof surface.evidence_limit, 'string', `${surface.id} must state its evidence limit`);
    assert.ok(surface.evidence_limit.length >= 20, `${surface.id} evidence limit is too vague`);
    assert.ok(
      surface.inherited_fragment_ids.every((id) => surface.fragment_ids.includes(id)),
      `${surface.id} can only inherit fragments that it owns`,
    );
    if (surface.inherited_fragment_ids.length > 0) {
      assert.ok(surface.inheritance_reason.length >= 10, `${surface.id} needs an inheritance reason`);
    }

    assertChecks(surface, surface.source_checks, 'source');
    assertChecks(surface, surface.regression_checks, 'regression');

    if (surface.availability === 'active') {
      assert.equal(surface.enforcement, 'strict', `${surface.id} active pages must be strict`);
      for (const dimension of ['hotel', 'source', 'fact_status']) {
        assert.ok(surface.scope_dimensions.includes(dimension), `${surface.id} missing ${dimension} scope`);
      }
      assert.ok(
        surface.scope_dimensions.includes('business_date') || surface.scope_dimensions.includes('date_range'),
        `${surface.id} must declare a business date or applicable date range`,
      );
      for (const state of ['loading', 'error']) {
        assert.ok(surface.state_requirements.includes(state), `${surface.id} missing ${state} state`);
      }
      for (const dimension of requiredStrictDimensions) {
        assert.ok(surface.contract_dimensions.includes(dimension), `${surface.id} missing ${dimension} contract`);
      }
      assert.equal(surface.evidence_maturity, 'unit_contract_pass');
    } else {
      assert.equal(surface.availability, 'frozen_hidden', `${surface.id} availability must be explicit`);
      assert.equal(surface.enforcement, 'baseline', `${surface.id} hidden page must not claim strict runtime coverage`);
      assert.equal(surface.evidence_maturity, 'static_contract_only');
      assert.ok(surface.state_requirements.includes('hidden'), `${surface.id} must declare hidden state`);
      assert.ok(surface.state_requirements.includes('not_field_validated'), `${surface.id} must reject field validation claims`);
      assert.ok(Array.isArray(surface.known_limits) && surface.known_limits.length > 0, `${surface.id} needs known limits`);
    }
  }
});

test('discoverable opening and quant surfaces stay active while formal investment and lifecycle remain frozen', () => {
  const surfaceByFragment = new Map(
    registry.surfaces.flatMap((surface) => surface.fragment_ids.map((fragmentId) => [fragmentId, surface])),
  );
  for (const [fragmentId, surfaceId] of [
    ['page-opening-overview', 'opening-management'],
    ['page-opening-checklist', 'opening-management'],
    ['page-ai-simulation', 'quant-simulation'],
  ]) {
    const surface = surfaceByFragment.get(fragmentId);
    assert.equal(surface?.id, surfaceId, `${fragmentId} must belong to ${surfaceId}`);
    assert.equal(surface?.availability, 'active', `${fragmentId} must remain discoverable`);
  }
  for (const path of ['opening-overview', 'opening-checklist', 'ai-simulation']) {
    assert.match(systemStatic, new RegExp(`path:\\s*['\"]${path}['\"]`));
    assert.match(appMain, new RegExp(`sourcePath:\\s*['\"]${path}['\"]`));
  }
  assert.equal(surfaceByFragment.get('page-investment-decision')?.availability, 'frozen_hidden');
  assert.equal(surfaceByFragment.get('page-lifecycle')?.availability, 'frozen_hidden');
  for (const path of ['investment-decision', 'lifecycle-auxiliary']) {
    assert.doesNotMatch(systemStatic, new RegExp(`path:\\s*['\"]${path}['\"]`));
  }
});

test('existing coverage stays proportional and does not promote code checks to field evidence', () => {
  assert.match(rule, /business-page-contract-registry\.json/);
  assert.match(rule, /新增核心片段未登记时门禁失败/);
  assert.match(rule, /冻结隐藏/);
  assert.match(absorption, /42 个现有产品链片段/);
  assert.match(absorption, /5 个明确排除域/);
  assert.match(absorption, /field_validated/);
  for (const surface of registry.surfaces) {
    assert.doesNotMatch(surface.evidence_limit, /已现场验证|已生产验证|field_validated/);
  }
});

test('absorption record pins the supplied source and preserves screenshot evidence limits', () => {
  assert.match(absorption, /7A53E8840461523E2BEE2E6F7F0DE221B04D0F862EA934AD1184FD4CC22404CC/);
  assert.match(absorption, /截图仅证明可见规范文字/);
  assert.match(absorption, /不提升为真实账号、生产或现场证据/);
});

test('standard backend response baseline remains code message data with explicit error HTTP status', () => {
  const successStart = baseController.indexOf('protected function success');
  const errorStart = baseController.indexOf('protected function error');
  assert.ok(successStart >= 0 && errorStart > successStart);

  const successBlock = baseController.slice(successStart, errorStart);
  const errorBlock = baseController.slice(errorStart, baseController.indexOf('protected function paginate', errorStart));
  for (const field of ["'code'", "'message'", "'data'"]) {
    assert.ok(successBlock.includes(field), `success response missing ${field}`);
    assert.ok(errorBlock.includes(field), `error response missing ${field}`);
  }
  assert.match(errorBlock, /return json\(\$result, \$httpStatus\)/);
});

test('Ctrip golden sample keeps page cards table and source notice in the download canvas', () => {
  const start = appMain.indexOf('const buildCtripBusinessCanvas =');
  const end = appMain.indexOf('const canvasToPngBlob =', start);
  assert.ok(start >= 0 && end > start, 'Ctrip business canvas block must exist');
  const block = appMain.slice(start, end);

  assert.match(block, /cards: visibleSnapshot\.cards/);
  assert.match(block, /table: visibleSnapshot\.table/);
  assert.match(block, /sourceNotice: visibleSnapshot\.sourceNotice/);
  assert.ok(
    ctripRegression.includes('assert.match(canvasDownload, /table: visibleSnapshot\\.table/);'),
    'existing Ctrip regression must protect the exact rendered table source',
  );
  assert.ok(
    ctripRegression.includes('assert.doesNotMatch(appMain, /table: ctripDownloadRows\\(\\)/);'),
    'existing Ctrip regression must reject the legacy reconstructed table source',
  );
});

test('visual mock evidence remains labeled and cannot silently become field evidence', () => {
  assert.match(visualSmoke, /const authMode = process\.env\.E2E_TASTE_AUTH_MODE \|\| 'mock'/);
  assert.match(visualSmoke, /authMode,/);
  assert.match(visualSmoke, /viewport,/);
  assert.match(rule, /Mock\/fixture.*synthetic.*test-only/);
  assert.match(rule, /不证明真实账号点击、线上部署或现场数据正确/);
});

test('visual smoke follows canonical page aliases and returns valid mock evidence shapes', () => {
  assert.match(visualSmoke, /const canonicalPageAliases = new Map/);
  assert.match(visualSmoke, /\['ai-workbench', 'compass'\]/);
  assert.match(visualSmoke, /expectedPageKey: canonicalPageAliases\.get\(pageKey\) \|\| pageKey/);
  assert.match(visualSmoke, /pathname\.endsWith\('\/api\/online-data\/manual-fetch-evidence'\)/);
  assert.match(visualSmoke, /\? \{ rows: \[\] \}/);
});

test('the focused verifier is part of the P0 guard without forcing the full visual matrix', () => {
  assert.equal(
    packageJson.scripts['verify:business-page-contract'],
    'node scripts/verify_business_page_contract.mjs',
  );
  assert.match(packageJson.scripts['verify:p0-guards'], /npm run verify:business-page-contract/);
  assert.doesNotMatch(packageJson.scripts['verify:business-page-contract'], /verify:taste-visual/);
  assert.match(businessPageVerifier, /surface\.regression_checks/);
  assert.match(businessPageVerifier, /tests\/automation\/business_page_contract\.test\.mjs/);
  assert.match(businessPageVerifier, /spawnSync\(process\.execPath, \['--test', \.\.\.testPaths\]/);
  assert.match(businessPageVerifier, /relativeToAutomation\.startsWith/);
});

test('Ctrip golden sample captures the rendered cards table and source notice for download', () => {
  const start = appMain.indexOf('const buildCtripBusinessCanvas =');
  const end = appMain.indexOf('const canvasToPngBlob =', start);
  assert.ok(start >= 0 && end > start, 'Ctrip business canvas block must exist');
  const block = appMain.slice(start, end);

  assert.match(block, /captureCtripBusinessDownloadSnapshot/);
  assert.match(block, /cards: visibleSnapshot\.cards/);
  assert.match(block, /table: visibleSnapshot\.table/);
  assert.match(block, /sourceNotice: visibleSnapshot\.sourceNotice/);
  assert.ok(
    ctripRegression.includes('assert.match(canvasDownload, /cards: visibleSnapshot\\.cards/);'),
    'existing Ctrip regression must protect the rendered card source',
  );
  assert.ok(
    ctripRegression.includes('assert.match(canvasDownload, /sourceNotice: visibleSnapshot\\.sourceNotice/);'),
    'existing Ctrip regression must protect the rendered source notice',
  );
});
