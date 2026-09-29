import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const script = path.join(repoRoot, 'scripts/sync_ctrip_order_analysis_assets.mjs');
const panel = 'components/online-data/ctrip-order-analysis-panel.js';
const orderLoader = 'components/online-data/ctrip-order-analysis-loader.js';
const components = 'components/system/app-main-components.js';
const componentsLoader = 'components/system/app-main-components-loader.js';
const hash = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex').slice(0, 10);

function fixture(t) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ctrip-order-assets-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const file = (asset) => path.join(root, 'public', asset);
  const write = (asset, content) => {
    fs.mkdirSync(path.dirname(file(asset)), { recursive: true });
    fs.writeFileSync(file(asset), content);
  };
  write(panel, '\uFEFF// 订单分析\r\nwindow.orderPanel = { version: 1 };\r\n');
  write(orderLoader, `const src = '${panel}?v=order-loader-prefix-h0000000000';\r\n`);
  write(components, `const src = '${panel}?v=main-body-prefix-h1111111111';\r\n// unrelated\r\n`);
  write(componentsLoader, `const src = '${components}?v=bridge-prefix-h2222222222';\r\n`);
  write('index.html', `<script type="application/json">[{"src":"${orderLoader}?v=index-order-prefix-h3333333333"},{"src":"${components}?v=index-main-prefix-h4444444444"}]</script>\r\n`);
  const read = (asset) => fs.readFileSync(file(asset));
  const run = (...args) => spawnSync(process.execPath, [script, '--root', root, ...args], { encoding: 'utf8' });
  return { root, file, write, read, run };
}

function snapshot(root) {
  const entries = new Map();
  const visit = (directory) => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const absolute = path.join(directory, entry.name);
      const relative = path.relative(root, absolute);
      if (entry.isDirectory()) {
        entries.set(relative, 'directory');
        visit(absolute);
      } else {
        entries.set(relative, { content: fs.readFileSync(absolute), mtime: fs.statSync(absolute).mtimeMs });
      }
    }
  };
  visit(root);
  return entries;
}

test('build synchronizes exact panel bytes through both loaders and the entry manifest', (t) => {
  const f = fixture(t);
  const originalPanel = f.read(panel);
  const result = f.run();
  assert.equal(result.status, 0, result.stderr);
  assert.equal(JSON.parse(result.stdout).status, 'updated');
  const panelHash = hash(originalPanel);
  assert.equal(f.read(orderLoader).toString(), `const src = '${panel}?v=order-loader-prefix-h${panelHash}';\r\n`);
  assert.equal(f.read(components).toString(), `const src = '${panel}?v=main-body-prefix-h${panelHash}';\r\n// unrelated\r\n`);
  assert.equal(f.read(componentsLoader).toString(), `const src = '${components}?v=bridge-prefix-h${hash(f.read(components))}';\r\n`);
  const manifest = JSON.parse(f.read('index.html').toString().match(/>(.*)<\/script>/)[1]);
  assert.deepEqual(manifest, [
    { src: `${orderLoader}?v=index-order-prefix-h${hash(f.read(orderLoader))}` },
    { src: `${components}?v=index-main-prefix-h${hash(f.read(components))}` },
  ]);
  assert.deepEqual(f.read(panel), originalPanel);

  const synced = snapshot(f.root);
  const repeated = f.run();
  assert.equal(repeated.status, 0, repeated.stderr);
  assert.equal(JSON.parse(repeated.stdout).status, 'current');
  assert.deepEqual(snapshot(f.root), synced, 'a current build must not rewrite artifacts');
});

test('--check detects stale references without writing files or creating the lock directory', (t) => {
  const f = fixture(t);
  const stale = snapshot(f.root);
  const result = f.run('--check');
  assert.equal(result.status, 1, result.stderr);
  assert.equal(JSON.parse(result.stdout).status, 'stale');
  assert.deepEqual(snapshot(f.root), stale);

  assert.equal(f.run().status, 0);
  const current = snapshot(f.root);
  assert.equal(f.run('--check').status, 0);
  assert.deepEqual(snapshot(f.root), current);

  f.write(panel, Buffer.concat([f.read(panel), Buffer.from('// changed body\r\n')]));
  const changedBody = snapshot(f.root);
  assert.equal(f.run('--check').status, 1);
  assert.deepEqual(snapshot(f.root), changedBody);
});

test('a duplicate or missing reference fails before publishing any asset', (t) => {
  for (const kind of ['duplicate', 'missing']) {
    const f = fixture(t);
    f.write('index.html', kind === 'duplicate'
      ? f.read('index.html').toString().replace('</script>', `,{"src":"${orderLoader}?v=duplicate-h5555555555"}</script>`)
      : '<html>No manifest</html>');
    const before = new Map([panel, orderLoader, components, componentsLoader, 'index.html'].map((asset) => [asset, f.read(asset)]));
    const result = f.run();
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /exactly once/);
    for (const [asset, content] of before) assert.deepEqual(f.read(asset), content);
  }
});

test('targeted and full npm builds include synchronization before one startup helper build', () => {
  const { scripts } = JSON.parse(fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'));
  for (const command of ['build:frontend', 'build:ctrip-order-analysis']) {
    const steps = scripts[command].split(' && ');
    const syncStep = steps.indexOf('node scripts/sync_ctrip_order_analysis_assets.mjs');
    const startupStep = steps.indexOf('npm run build:frontend-startup-helpers');
    assert.ok(syncStep >= 0 && startupStep > syncStep, command);
    assert.equal(steps.filter((step) => step === 'npm run build:frontend-startup-helpers').length, 1, command);
  }
});
