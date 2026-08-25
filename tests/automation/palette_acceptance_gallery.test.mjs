import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');
const manifest = JSON.parse(read('docs/knowledge/palette-acceptance/source-manifest.json'));

const expectedCandidates = {
  suxios_anchor: ['#111418', '#B9965B', '#1F5B63'],
  editorial_coral_cyan: ['#FF6438', '#A8EDF0', '#5E4FA2'],
  boardroom_navy_gold: ['#1F5AA6', '#C99A3D', '#347C72'],
  night_signal: ['#FF6B3D', '#7DD8DE', '#B8A2FF'],
  data_neutral: ['#2F6FED', '#2A9D8F', '#8E63CE'],
  training_warm: ['#D95C3A', '#E6B566', '#477C71'],
};

test('source manifest keeps the three attachments traceable and independently disposed', () => {
  assert.deepEqual(
    manifest.sources.map((source) => source.sha256),
    [
      'DE85C8F3F43F9DB7EB4CDFE907CE0E4FE33866EF96DD53EFF708F6486902F756',
      '6712AF19E4E5464635A4299BD243E64AFE6D66BEB3343744C48221537BC959CA',
      '32C06DE45983119EFD6F7CFA9B1E8CA5CE59F8A4E5339267DC383A5FC0EE3970',
    ]
  );
  assert.deepEqual(
    manifest.sources.map((source) => source.disposition),
    [
      'formal_integration_source',
      'mechanism_reference_only',
      'duplicate_reference_only_no_new_ingestion',
    ]
  );
  assert.equal(manifest.sources[1].license, 'not_provided');
  assert.equal(manifest.sources[2].execution_policy, 'not_installed_not_executed_not_activated');
});

test('candidate palette values are exact and semantic status colors remain fixed', () => {
  assert.deepEqual(
    Object.fromEntries(manifest.palette_contract.candidates.map(({ id, colors }) => [id, colors])),
    expectedCandidates
  );
  assert.deepEqual(manifest.palette_contract.fixed_semantic_colors, {
    success: '#3E7B5E',
    danger: '#A85252',
  });
});

test('split component and generated template expose the same-content candidate review loop', () => {
  const sourceDialog = read('resources/frontend/templates/fragments/43-dialogs-system-config.html');
  const component = read('public/components/system/palette-acceptance-gallery.js');
  const generatedTemplate = read('resources/frontend/app-template.html');
  const publicEntry = read('public/index.html');

  for (const [candidate, colors] of Object.entries(expectedCandidates)) {
    assert.match(component, new RegExp(candidate));
    for (const color of colors) assert.match(component, new RegExp(color, 'i'));
  }

  for (const copy of ['信号', '判断', '再验证', 'MOCK · 样式样例，非经营数据']) {
    assert.match(component, new RegExp(copy));
  }
  assert.match(component, /palette_acceptance_candidate/);
  assert.match(component, /CANDIDATE ONLY/);
  assert.match(component, /不会切换正式主题、修改登录页、部署、发布或触发经营动作/);
  assert.match(component, /data-testid': 'legacy-display-settings'/);
  assert.match(component, /当前运行时代码未接入全局主题切换/);
  assert.doesNotMatch(component, /data-theme|document\.documentElement|style\.setProperty\s*\(/);

  assert.match(sourceDialog, /<palette-acceptance-gallery :ctx="\$root"><\/palette-acceptance-gallery>/);
  assert.match(generatedTemplate, /<palette-acceptance-gallery :ctx="\$root"><\/palette-acceptance-gallery>/);
  assert.match(publicEntry, /components\/system\/palette-acceptance-gallery\.js\?v=20260823-palette-acceptance-h6d889e81f4/);
  assert.equal(
    crypto.createHash('sha256').update(component).digest('hex').slice(0, 10),
    '6d889e81f4'
  );
});

test('split component changes only the candidate field in the existing reactive form', () => {
  const source = read('public/components/system/palette-acceptance-gallery.js');
  const sandbox = {
    window: {},
    Vue: {
      h: (type, props, children) => ({ type, props: props || {}, children }),
    },
  };
  vm.runInNewContext(source, sandbox, { filename: 'palette-acceptance-gallery.js' });

  const form = { palette_acceptance_candidate: 'suxios_anchor', untouched: 'preserve-me' };
  const component = sandbox.window.SUXI_SYSTEM_COMPONENTS.PaletteAcceptanceGallery;
  const tree = component.render.call({ ctx: { systemConfigForm: form } });
  const queue = [tree];
  let targetRadio = null;
  while (queue.length) {
    const node = queue.shift();
    if (!node || typeof node !== 'object') continue;
    if (node.type === 'input' && node.props?.value === 'training_warm') targetRadio = node;
    if (Array.isArray(node.children)) queue.push(...node.children);
  }
  assert.ok(targetRadio, 'training_warm radio should be rendered');
  targetRadio.props.onChange();
  assert.deepEqual(form, {
    palette_acceptance_candidate: 'training_warm',
    untouched: 'preserve-me',
  });
});

test('backend contract registers the key and rejects unknown values with 422', () => {
  const model = read('app/model/SystemConfig.php');
  const controller = read('app/controller/SystemConfigController.php');

  assert.match(model, /KEY_PALETTE_ACCEPTANCE_CANDIDATE\s*=\s*'palette_acceptance_candidate'/);
  assert.match(model, /normalizePaletteAcceptanceCandidate/);
  assert.match(model, /array_key_exists\(\$candidate, self::PALETTE_ACCEPTANCE_CANDIDATES\)/);
  assert.match(controller, /normalizePaletteAcceptanceCandidate/);
  assert.match(controller, /return \$this->error\(\$e->getMessage\(\), 422\)/);
});
