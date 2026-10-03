import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import test from 'node:test';
import { buildOperatingEconomicsComponent } from '../../scripts/build_operating_economics_component.mjs';

const repoRoot = process.cwd();
const scriptPath = path.resolve(process.env.OPERATING_COMPONENT_BUILD_SOURCE || 'scripts/build_operating_finance_component.mjs');
const { updateFrontendAssetVersion, readFrontendAssetVersion } = await import(pathToFileURL(path.join(repoRoot, 'scripts/lib/frontend_asset_version.mjs')));
const script = fs.readFileSync(scriptPath, 'utf8')
    .replace(/^import[\s\S]*?;\r?\n/gm, '')
    .replaceAll('import.meta.url', '__buildScriptUrl');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const runScript = new AsyncFunction('crypto', 'fs', 'path', 'fileURLToPath', 'minify',
    'compileFrontendTemplate', 'FRONTEND_TEMPLATE_MINIFY_OPTIONS', 'updateFrontendAssetVersion',
    'buildOperatingEconomicsComponent', '__buildScriptUrl', 'console', script);
const names = {
    finance: 'components/system/operating-finance-control-center.js',
    artifact: 'components/system/operating-finance-control-center.min.js',
    lab: 'components/system/operating-opportunity-lab.js',
    economics: 'components/system/operating-economics-workbench.min.js',
    economicsSource: 'components/system/operating-economics-workbench.js',
    booking: 'components/system/booking-monitoring-panel.js',
    component: 'components/system/app-main-components.js',
    bridge: 'components/system/app-main-components-loader.js',
    index: 'index.html',
};
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex').slice(0, 10);

function fixture() {
    const tick = String.fromCharCode(96);
    const entries = {
        finance: 'x = {\n        template: ' + tick + '内容\n        ' + tick + ',\n    };',
        artifact: 'compiled-finance;\n',
        lab: '// synthetic 今日事项 A\n',
        economics: '// synthetic 渠道贡献与耗材\n',
        economicsSource: '// synthetic economics source A\n',
        booking: '// synthetic 房型监测\n',
        component: "const business = 'preserve';\n"
            + "const finance = 'components/system/operating-finance-control-center.min.js?v=20260830-operating-finance-h0123456789';\n"
            + "const lab = 'components/system/operating-opportunity-lab.js?v=20260831-impact-estimate-h0123456789';\n"
            + "const economics = 'components/system/operating-economics-workbench.min.js?v=economics-h0123456789';\n"
            + "const booking = 'components/system/booking-monitoring-panel.js?v=booking-h0123456789';\n",
        bridge: "const exposed = ['OperatingFinanceControlCenter'];\nconst full = 'components/system/app-main-components.js?v=20260830-operating-finance-h0123456789';",
        index: '<script src="components/system/app-main-components.js?v=20260830-operating-finance-h0123456789"></script>',
    };
    const files = new Map(Object.entries(entries).map(([name, value]) => [path.join(repoRoot, 'public', names[name]), Buffer.from(value)]));
    const writes = [];
    const get = name => files.get(path.join(repoRoot, 'public', names[name])).toString();
    const set = (name, value) => files.set(path.join(repoRoot, 'public', names[name]), Buffer.from(value));
    const closedFs = {
        existsSync: file => files.has(path.normalize(file)),
        readFileSync(file, encoding) {
            const bytes = files.get(path.normalize(file));
            if (!bytes) throw new Error('Unexpected read in closed build fixture: ' + file);
            return encoding ? bytes.toString(encoding) : Buffer.from(bytes);
        },
        writeFileSync(file, value) {
            const target = path.normalize(file);
            assert.ok(files.has(target), 'build cannot write outside its known fixture assets');
            writes.push(target);
            files.set(target, Buffer.from(value));
        },
    };
    const run = async () => {
        let output;
        await runScript(crypto, closedFs, path, fileURLToPath,
            async () => ({ code: 'compiled-finance;' }), () => 'return null;', {},
            updateFrontendAssetVersion, async () => {
                const artifact = '// synthetic economics compiled ' + hash(get('economicsSource')) + '\n';
                if (get('economics') !== artifact) closedFs.writeFileSync(path.join(repoRoot, 'public', names.economics), artifact);
            }, pathToFileURL(path.join(repoRoot, 'scripts/build_operating_finance_component.mjs')).href,
            { log: value => { output = JSON.parse(value); } });
        return output;
    };
    return { files, writes, get, set, run };
}

function assertChain(f) {
    const child = readFrontendAssetVersion(f.get('component'), names.lab);
    assert.equal(child.hash, hash(f.get('lab')), 'nested URL follows exact child bytes');
    assert.equal(child.versionPrefix, '20260831-impact-estimate');
    for (const name of ['economics', 'booking']) {
        const version = readFrontendAssetVersion(f.get('component'), names[name]);
        assert.equal(version.hash, hash(f.get(name)), name + ' follows exact child bytes');
        assert.equal(version.versionPrefix, name);
    }
    for (const name of ['bridge', 'index']) {
        assert.equal(readFrontendAssetVersion(f.get(name), names.component).hash, hash(f.get('component')),
            name + ' follows updated parent bytes');
    }
}

test('official component build propagates child identity through parent and entry without changing business code', async () => {
    const f = fixture();
    const finance = f.get('finance');
    const artifact = f.get('artifact');
    const result = await f.run();
    assertChain(f);
    assert.equal(f.get('finance'), finance);
    assert.equal(f.get('artifact'), artifact);
    assert.ok(f.get('component').startsWith("const business = 'preserve';\n"));
    assert.equal(result.changed, false);
    assert.equal(result.opportunity_cache_identity_changed, true);
});

test('changing only nested lab bytes invalidates the chain and repeated identical build is stable', async () => {
    const f = fixture();
    await f.run();
    const previous = ['component', 'bridge', 'index'].map(name => f.get(name));
    f.set('lab', f.get('lab') + '// synthetic changed feedback ownership\n');
    await f.run();
    assertChain(f);
    ['component', 'bridge', 'index'].forEach((name, index) => assert.notEqual(f.get(name), previous[index]));
    f.writes.length = 0;
    const repeat = await f.run();
    assert.deepEqual(f.writes, []);
    assert.equal(repeat.opportunity_cache_identity_changed, false);
    assert.equal(repeat.bridge_cache_identity_changed, false);
    assert.equal(repeat.index_cache_identity_changed, false);
});

test('missing or ambiguous nested reference fails before publishing loader or entry', async () => {
    for (const name of ['lab', 'economics', 'booking']) for (const variant of ['missing', 'duplicate']) {
        const f = fixture();
        const current = f.get('component');
        f.set('component', variant === 'missing'
            ? current.split('\n').filter(line => !line.includes(names[name])).join('\n')
            : current + current.split('\n').find(line => line.includes(names[name])) + '\n');
        const before = ['component', 'bridge', 'index'].map(name => f.get(name));
        await assert.rejects(f.run(), /exactly once/);
        ['component', 'bridge', 'index'].forEach((name, index) => assert.equal(f.get(name), before[index]));
    }
});

test('each new workflow asset invalidates the parent and entry and identical rebuild stays stable', async () => {
    for (const name of ['economics', 'booking']) {
        const f = fixture();
        await f.run();
        const previous = ['component', 'bridge', 'index'].map(key => f.get(key));
        const editedName = name === 'economics' ? 'economicsSource' : name;
        f.set(editedName, f.get(editedName) + '// synthetic changed saved/readback behavior\n');
        const changed = await f.run();
        assertChain(f);
        assert.equal(changed[name + '_cache_identity_changed'], true);
        ['component', 'bridge', 'index'].forEach((key, index) => assert.notEqual(f.get(key), previous[index]));
        f.writes.length = 0;
        const repeat = await f.run();
        assert.deepEqual(f.writes, []);
        assert.equal(repeat[name + '_cache_identity_changed'], false);
    }
});

test('the parent build compiles changed economics source before publishing its cache identity', async () => {
    const temporaryRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-economics-build-'));
    try {
        const f = fixture();
        for (const [file, bytes] of f.files) {
            const target = path.join(temporaryRoot, path.relative(repoRoot, file));
            fs.mkdirSync(path.dirname(target), { recursive: true });
            fs.writeFileSync(target, bytes);
        }
        const sourcePath = path.join(temporaryRoot, 'public', names.economicsSource);
        const artifactPath = path.join(temporaryRoot, 'public', names.economics);
        const componentPath = path.join(temporaryRoot, 'public', names.component);
        const tick = String.fromCharCode(96);
        const source = marker => 'window.syntheticEconomics = {\n        template: ' + tick
            + '<div v-if="available">' + marker + '</div>\n        ' + tick + ',\n};';
        const run = () => runScript(crypto, fs, path, fileURLToPath,
            async () => ({ code: 'compiled-finance;' }), () => 'return null;', {},
            updateFrontendAssetVersion, buildOperatingEconomicsComponent,
            pathToFileURL(path.join(temporaryRoot, 'scripts/build_operating_finance_component.mjs')).href,
            { log() {} });
        fs.writeFileSync(sourcePath, source('workflowAlphaMarker'));
        await run();
        const firstArtifact = fs.readFileSync(artifactPath, 'utf8');
        const firstParent = fs.readFileSync(componentPath, 'utf8');
        assert.ok(firstArtifact.includes('workflowAlphaMarker'));
        fs.writeFileSync(sourcePath, source('workflowBetaMarker'));
        await run();
        const nextArtifact = fs.readFileSync(artifactPath, 'utf8');
        const nextParent = fs.readFileSync(componentPath, 'utf8');
        assert.ok(nextArtifact.includes('workflowBetaMarker'));
        assert.notEqual(nextArtifact, firstArtifact);
        assert.notEqual(nextParent, firstParent);
        assert.equal(readFrontendAssetVersion(nextParent, names.economics).hash, hash(nextArtifact));
        await run();
        assert.equal(fs.readFileSync(componentPath, 'utf8'), nextParent);
    } finally {
        assert.equal(path.dirname(temporaryRoot), os.tmpdir());
        assert.ok(path.basename(temporaryRoot).startsWith('suxios-economics-build-'));
        fs.rmSync(temporaryRoot, { recursive: true, force: true });
    }
});
