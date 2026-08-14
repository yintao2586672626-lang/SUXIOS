import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
  MANAGED_FRONTEND_PATHS,
  PUBLIC_ENTRY_PATHS,
  isManagedFrontendPath,
  isPublicEntryPath,
} from '../../hooks/frontend-managed-paths.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const helperPath = path.join(projectRoot, 'hooks/verify-staged-frontend-build.mjs');

test('the shared managed frontend manifest covers build inputs and generated artifacts', () => {
  const requiredPaths = [
    'resources/frontend/templates/fragments/root.html',
    'resources/frontend/app-template.html',
    'scripts/lib/frontend_template_build.mjs',
    'scripts/verify_frontend_template_build.mjs',
    'scripts/build_tailwind_runtime.mjs',
    'public/index.html',
    'public/app-main.js',
    'public/app-render.min.js',
    'public/style.min.css',
    'public/tailwind.min.css',
    'public/app-bootstrap.min.js',
  ];

  assert.equal(Object.isFrozen(MANAGED_FRONTEND_PATHS), true);
  assert.equal(Object.isFrozen(PUBLIC_ENTRY_PATHS), true);
  for (const file of requiredPaths) assert.equal(isManagedFrontendPath(file), true, file);
  assert.equal(isManagedFrontendPath('app/service/RevenueFactLayerService.php'), false);
  assert.equal(isManagedFrontendPath('public/images/hotel-photo.webp'), false);
  for (const file of ['public/router.php', 'public/.htaccess']) {
    assert.equal(isManagedFrontendPath(file), false, `${file} must not trigger the verifier triplet`);
    assert.equal(isPublicEntryPath(file), true, `${file} must trigger the public-entry verifier`);
  }
  assert.equal(isPublicEntryPath('public/images/hotel-photo.webp'), false);
});

const run = (cwd, command, args, expectedStatus = 0) => {
  const result = spawnSync(command, args, {
    cwd,
    encoding: 'utf8',
    windowsHide: true,
  });
  assert.equal(result.status, expectedStatus, [result.stdout, result.stderr].filter(Boolean).join('\n'));
  return result;
};

const write = (root, relativePath, content) => {
  const target = path.join(root, relativePath);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, content);
};

const hash = (value) => crypto.createHash('sha256').update(value).digest('hex');

function writeFixtureState(root, value) {
  write(root, 'resources/frontend/templates/fragments/root.html', value);
  write(root, 'resources/frontend/app-template.html', value);
  write(root, 'resources/frontend/templates/manifest.json', JSON.stringify({
    source_snapshot_sha256: hash(value),
  }));
  write(root, 'public/app-render.min.js', value);
  writeEntryFixtureState(root, value);
}

const entryArtifact = (source) => `compiled:${source}`;

function writeEntryFixtureState(root, value) {
  const source = `entry:${value}`;
  const artifact = entryArtifact(source);
  const version = hash(artifact).slice(0, 10);
  write(root, 'public/app-main.js', source);
  write(root, 'public/app-main.min.js', artifact);
  write(root, 'public/index.html', `<script defer src="app-main.min.js?v=fixture-h${version}"></script><meta data-contract="base">`);
}

function createFixtureRepository(parentRoot = '') {
  const root = parentRoot
    ? path.join(parentRoot, 'HOTEL')
    : fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hook-contract-'));
  if (parentRoot) fs.mkdirSync(root, { recursive: true });
  write(root, 'package.json', JSON.stringify({
    private: true,
    scripts: {
      'verify:frontend-template': 'node verify.mjs',
      'verify:frontend-entry-build': 'node verify-entry.mjs',
      'verify:tailwind-runtime': 'node verify-assets.mjs',
      'verify:public-entry': 'node verify-public.mjs',
      'verify:taste-coverage': 'node verify-public.mjs',
      'verify:p0-guards': 'node verify-public.mjs',
      'verify:ctrip-capture-catalog': 'node -e "process.exit(0)"',
      'verify:context-assets': 'node verify-context.mjs',
    },
  }));
  write(root, 'verify.mjs', `
    import assert from 'node:assert/strict';
    import crypto from 'node:crypto';
    import fs from 'node:fs';
    const source = fs.readFileSync('resources/frontend/templates/fragments/root.html', 'utf8');
    const snapshot = fs.readFileSync('resources/frontend/app-template.html', 'utf8');
    const generated = fs.readFileSync('public/app-render.min.js', 'utf8');
    const buildPrefix = fs.readFileSync('scripts/lib/frontend_template_build.mjs', 'utf8');
    const manifest = JSON.parse(fs.readFileSync('resources/frontend/templates/manifest.json', 'utf8'));
    assert.equal(snapshot, source, 'snapshot must equal staged fragment');
    assert.equal(generated, buildPrefix + source, 'generated render must equal staged fragment build contract');
    assert.equal(manifest.source_snapshot_sha256, crypto.createHash('sha256').update(source).digest('hex'));
  `);
  write(root, 'verify-entry.mjs', `
    import assert from 'node:assert/strict';
    import crypto from 'node:crypto';
    import fs from 'node:fs';
    const source = fs.readFileSync('public/app-main.js', 'utf8');
    const artifact = fs.readFileSync('public/app-main.min.js', 'utf8');
    const html = fs.readFileSync('public/index.html', 'utf8');
    const expected = 'compiled:' + source;
    assert.equal(artifact, expected, 'app-main artifact must equal the staged source build');
    const version = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
    assert.match(html, new RegExp('app-main\\\\.min\\\\.js\\\\?v=fixture-h' + version), 'index must reference the staged artifact hash');
  `);
  write(root, 'verify-public.mjs', `
    import assert from 'node:assert/strict';
    import fs from 'node:fs';
    const html = fs.readFileSync('public/index.html', 'utf8');
    const style = fs.readFileSync('public/style.css', 'utf8');
    assert.equal(html.match(/data-contract="([^"]+)"/u)?.[1], style.match(/contract:([^*]+)/u)?.[1].trim());
    assert.equal(fs.readFileSync('public/router.php', 'utf8'), fs.readFileSync('public/router.contract', 'utf8'), 'router contract must use the staged index');
    assert.equal(fs.readFileSync('public/.htaccess', 'utf8'), fs.readFileSync('public/htaccess.contract', 'utf8'), 'htaccess contract must use the staged index');
  `);
  write(root, 'verify-assets.mjs', `
    import assert from 'node:assert/strict';
    import fs from 'node:fs';
    const contract = (file, pattern) => fs.readFileSync(file, 'utf8').match(pattern)?.[1];
    assert.equal(
      fs.readFileSync('public/style.min.css', 'utf8'),
      'minified:' + contract('public/style.css', /contract:([^*]+)/u).trim(),
      'authenticated style artifact must match the staged source'
    );
    assert.equal(
      fs.readFileSync('public/tailwind.min.css', 'utf8'),
      'minified-' + fs.readFileSync('public/tailwind.full.css', 'utf8'),
      'Tailwind artifact must match the staged source'
    );
    assert.equal(
      fs.readFileSync('public/app-bootstrap.min.js', 'utf8'),
      'compiled-' + fs.readFileSync('public/app-bootstrap.js', 'utf8'),
      'bootstrap artifact must match the staged source'
    );
  `);
  write(root, 'verify-context.mjs', `
    import assert from 'node:assert/strict';
    import fs from 'node:fs';
    assert.equal(fs.readFileSync('AGENTS.md', 'utf8'), fs.readFileSync('vault/project-state.md', 'utf8'));
  `);
  write(root, 'scripts/lib/frontend_template_build.mjs', '');
  writeFixtureState(root, 'base');
  write(root, 'public/style.css', '/* contract:base */');
  write(root, 'public/style.min.css', 'minified:base');
  write(root, 'public/tailwind.full.css', 'tailwind:base');
  write(root, 'public/tailwind.min.css', 'minified-tailwind:base');
  write(root, 'public/app-bootstrap.js', 'bootstrap:base');
  write(root, 'public/app-bootstrap.min.js', 'compiled-bootstrap:base');
  write(root, 'public/router.php', 'base');
  write(root, 'public/router.contract', 'base');
  write(root, 'public/.htaccess', 'base');
  write(root, 'public/htaccess.contract', 'base');
  write(root, 'AGENTS.md', 'base');
  write(root, 'vault/project-state.md', 'base');
  run(root, 'git', ['init', '--quiet']);
  run(root, 'git', ['config', 'user.name', 'SUXIOS Hook Test']);
  run(root, 'git', ['config', 'user.email', 'hook-test@example.invalid']);
  run(root, 'git', ['add', '.']);
  run(root, 'git', ['commit', '--quiet', '-m', 'fixture']);
  return root;
}

function runHelper(root, expectedStatus, extraArgs = []) {
  return run(root, process.execPath, [helperPath, '--repo', root, ...extraArgs], expectedStatus);
}

test('fragment-only stage cannot borrow synchronized snapshot and generated files from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'resources/frontend/templates/fragments/root.html', 'next');
    run(root, 'git', ['add', 'resources/frontend/templates/fragments/root.html']);
    writeFixtureState(root, 'next');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /snapshot must equal staged fragment/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('generated-only stage triggers index verification and cannot borrow matching source from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'public/app-render.min.js', 'next');
    run(root, 'git', ['add', 'public/app-render.min.js']);
    writeFixtureState(root, 'next');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /generated render must equal staged fragment/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('frontend template build-library-only stage cannot borrow matching generated files from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'scripts/lib/frontend_template_build.mjs', 'compiled:');
    run(root, 'git', ['add', 'scripts/lib/frontend_template_build.mjs']);
    write(root, 'public/app-render.min.js', 'compiled:base');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /staged fragment build contract/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

for (const artifact of [
  ['public/style.min.css', 'minified:next', 'public/style.css', '/* contract:next */'],
  ['public/tailwind.min.css', 'minified-tailwind:next', 'public/tailwind.full.css', 'tailwind:next'],
  ['public/app-bootstrap.min.js', 'compiled-bootstrap:next', 'public/app-bootstrap.js', 'bootstrap:next'],
]) {
  test(`${artifact[0]}-only stage cannot bypass the frontend verifier triplet`, () => {
    const root = createFixtureRepository();
    try {
      write(root, artifact[0], artifact[1]);
      run(root, 'git', ['add', artifact[0]]);
      write(root, artifact[2], artifact[3]);

      const result = runHelper(root, 1);
      assert.doesNotMatch(result.stdout, /index verification skipped/u);
    } finally {
      fs.rmSync(root, { recursive: true, force: true });
    }
  });
}

for (const entry of [
  ['public/router.php', 'public/router.contract'],
  ['public/.htaccess', 'public/htaccess.contract'],
]) {
  test(`${entry[0]}-only stage cannot skip public-entry verification or borrow matching worktree state`, () => {
    const root = createFixtureRepository();
    try {
      write(root, entry[0], 'next');
      run(root, 'git', ['add', entry[0]]);
      write(root, entry[1], 'next');

      const result = runHelper(root, 1);
      const output = `${result.stdout}\n${result.stderr}`;
      assert.doesNotMatch(result.stdout, /index verification skipped/u);
      assert.match(output, /contract must use the staged index/u);
      assert.doesNotMatch(result.stdout, /verify:frontend-template|verify:frontend-entry-build|verify:tailwind-runtime/u);
    } finally {
      fs.rmSync(root, { recursive: true, force: true });
    }
  });
}

test('renaming a managed fragment outside the managed tree cannot skip staged verification', () => {
  const root = createFixtureRepository();
  try {
    fs.mkdirSync(path.join(root, 'archive'), { recursive: true });
    run(root, 'git', [
      'mv',
      'resources/frontend/templates/fragments/root.html',
      'archive/root.html',
    ]);

    const result = runHelper(root, 1);
    assert.doesNotMatch(result.stdout, /index verification skipped/u);
    assert.match(`${result.stdout}\n${result.stderr}`, /root\.html|missing/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('the PowerShell and Node staged routers both disable rename folding', () => {
  const powerShellHook = fs.readFileSync(path.join(projectRoot, 'hooks/pre-commit.ps1'), 'utf8');
  const nodeHook = fs.readFileSync(helperPath, 'utf8');
  assert.match(powerShellHook, /'--cached', '--no-renames', '--diff-filter=ACMRD'/u);
  assert.match(nodeHook, /'--cached', '--no-renames', '--diff-filter=ACMRD'/u);
  assert.match(powerShellHook, /verify-staged-frontend-build\.mjs/u);
  assert.match(powerShellHook, /--context-verifier/u);
  assert.doesNotMatch(powerShellHook, /npm\.cmd/u);
  assert.match(nodeHook, /from '\.\/frontend-managed-paths\.mjs'/u);
  assert.equal(isManagedFrontendPath('public/app-main.js'), true);
  assert.equal(isManagedFrontendPath('public/app-main.min.js'), true);
});

test('app-main source-only stage cannot borrow artifact and version from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'public/app-main.js', 'entry:next');
    run(root, 'git', ['add', 'public/app-main.js']);
    writeEntryFixtureState(root, 'next');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /artifact must equal the staged source build/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('app-main artifact-only stage cannot borrow source and version from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'public/app-main.min.js', entryArtifact('entry:next'));
    run(root, 'git', ['add', 'public/app-main.min.js']);
    writeEntryFixtureState(root, 'next');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /artifact must equal the staged source build/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('app-main source artifact and matching staged index version pass together', () => {
  const root = createFixtureRepository();
  try {
    writeEntryFixtureState(root, 'next');
    run(root, 'git', ['add', 'public/app-main.js', 'public/app-main.min.js', 'public/index.html']);

    const result = runHelper(root, 0);
    assert.match(result.stdout, /Staged project index verification passed/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('style-only stage cannot borrow a matching public entry from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'public/style.css', '/* contract:next */');
    run(root, 'git', ['add', 'public/style.css']);
    write(root, 'public/index.html', `${fs.readFileSync(path.join(root, 'public/index.html'), 'utf8').replace('base', 'next')}`);

    const result = runHelper(root, 1);
    assert.match(
      `${result.stdout}\n${result.stderr}`,
      /authenticated style artifact must match the staged source|Expected values to be strictly equal/u
    );
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('public-entry-only stage cannot borrow a matching style from the worktree', () => {
  const root = createFixtureRepository();
  try {
    const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8').replace('base', 'next');
    write(root, 'public/index.html', html);
    run(root, 'git', ['add', 'public/index.html']);
    write(root, 'public/style.css', '/* contract:next */');

    const result = runHelper(root, 1);
    assert.match(`${result.stdout}\n${result.stderr}`, /Expected values to be strictly equal/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('context-only stage cannot borrow matching context state from the worktree', () => {
  const root = createFixtureRepository();
  try {
    write(root, 'AGENTS.md', 'next');
    run(root, 'git', ['add', 'AGENTS.md']);
    write(root, 'vault/project-state.md', 'next');

    const result = runHelper(root, 1, ['--context-verifier', 'verify-context.mjs', '--context-only']);
    assert.match(`${result.stdout}\n${result.stderr}`, /Expected values to be strictly equal/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a complete consistent frontend stage passes the index contract', () => {
  const root = createFixtureRepository();
  try {
    writeFixtureState(root, 'next');
    run(root, 'git', ['add', 'resources/frontend', 'public/app-render.min.js']);

    const result = runHelper(root, 0);
    assert.match(result.stdout, /Staged project index verification passed/u);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});

test('a linked worktree resolves the workspace AGENTS.md from the common repository', () => {
  const workspace = fs.mkdtempSync(path.join(os.tmpdir(), 'suxios-hook-worktree-'));
  const primary = createFixtureRepository(workspace);
  const linked = path.join(workspace, '.codex-worktrees', 'review');
  try {
    write(workspace, 'AGENTS.md', 'workspace-outer');
    write(primary, 'verify-outer-context.mjs', `
      import assert from 'node:assert/strict';
      import fs from 'node:fs';
      assert.equal(fs.readFileSync('../AGENTS.md', 'utf8'), 'workspace-outer');
    `);
    run(primary, 'git', ['add', 'verify-outer-context.mjs']);
    run(primary, 'git', ['commit', '--quiet', '-m', 'add outer verifier']);
    fs.mkdirSync(path.dirname(linked), { recursive: true });
    run(primary, 'git', ['worktree', 'add', '--quiet', '-b', 'fixture-linked', linked]);

    write(linked, 'AGENTS.md', 'linked-change');
    run(linked, 'git', ['add', 'AGENTS.md']);
    const result = runHelper(linked, 0, [
      '--context-verifier',
      'verify-outer-context.mjs',
      '--context-only',
    ]);
    assert.match(result.stdout, /Staged project index verification passed/u);
  } finally {
    fs.rmSync(workspace, { recursive: true, force: true });
  }
});
