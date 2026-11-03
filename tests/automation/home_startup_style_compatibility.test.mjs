import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync('public/app-bootstrap.js', 'utf8');
const start = source.indexOf('const loadAuthenticatedApp = () => {');
const end = source.indexOf('\n\n    const loginMarkup', start);
assert.ok(start >= 0 && end > start, 'the real authenticated startup must be extractable');

function startupHarness(polishPhase) {
  const scripts = [];
  const styles = [];
  const failures = [];
  let releaseStyle;
  let rejectStyle;
  const polishReady = new Promise((resolve, reject) => {
    releaseStyle = resolve;
    rejectStyle = reject;
  });
  const manifest = [
    { src: 'vue.runtime.global.prod.js?v=test-only', type: 'script', phase: 'startup' },
    { src: 'style-startup.min.css?v=test-only', type: 'style', phase: 'startup' },
    { src: 'compass-authority-polish.css?v=test-only', type: 'style', phase: polishPhase },
    { src: 'app-startup-render.min.js?v=test-only', type: 'script', phase: 'startup' },
    { src: 'app-main.min.js?v=test-only', type: 'script', phase: 'startup' },
    { src: 'unrelated-page.js?v=test-only', type: 'script', phase: 'after-first-paint' },
  ];
  const createStartup = Function(
    'authenticatedAssets', 'assetBaseName', 'loadScript', 'loadStylesheet', 'window',
    'ASSET_PHASE_STARTUP', 'ASSET_TYPE_SCRIPT', 'ASSET_TYPE_STYLE',
    `let authenticatedAppPromise = null; ${source.slice(start, end)}; return loadAuthenticatedApp;`,
  );
  const load = createStartup(
    () => manifest,
    value => String(value).split(/[?#]/, 1)[0],
    async src => { scripts.push(src); },
    src => {
      styles.push(src);
      return src.startsWith('compass-authority-polish.css') ? polishReady : Promise.resolve();
    },
    { SUXI_RENDER_ASSET_LOAD_ERROR: asset => failures.push(asset) },
    'startup', 'script', 'style',
  );
  return { load, scripts, styles, failures, releaseStyle, rejectStyle };
}

for (const phase of ['after-first-paint', 'startup']) {
  test(`home styling finishes before mount with a ${phase} manifest entry`, async () => {
    const harness = startupHarness(phase);
    const loading = harness.load();
    assert.equal(harness.load(), loading, 'repeat startup must share one load');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(harness.styles.filter(src => src.startsWith('compass-authority-polish.css')).length, 1);
    assert.ok(!harness.scripts.some(src => src.startsWith('app-main.min.js')), 'mount must wait for the home stylesheet');
    harness.releaseStyle();
    await loading;
    assert.ok(harness.scripts.at(-1).startsWith('app-main.min.js'));
    assert.ok(!harness.scripts.some(src => src.startsWith('unrelated-page.js')), 'other page groups stay deferred');
  });
}

test('a home stylesheet failure stops mounting and reports the failed resource', async () => {
  const harness = startupHarness('after-first-paint');
  const loading = harness.load();
  assert.equal(harness.styles.filter(src => src.startsWith('compass-authority-polish.css')).length, 1);
  const rejection = assert.rejects(loading, /compass-authority-polish\.css 加载失败/);
  harness.rejectStyle(new Error('compass-authority-polish.css 加载失败'));
  await rejection;
  assert.deepEqual(harness.failures, ['compass-authority-polish.css']);
  assert.ok(!harness.scripts.some(src => src.startsWith('app-main.min.js')));
});
