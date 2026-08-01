import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptPath = fileURLToPath(import.meta.url);
const defaultRepoRoot = path.resolve(path.dirname(scriptPath), '..');
const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const phpCommand = process.platform === 'win32' ? 'C:\\xampp\\php\\php.exe' : 'php';
const phpunitPath = process.platform === 'win32' ? 'vendor\\bin\\phpunit' : 'vendor/bin/phpunit';
const routeCoveragePath = process.platform === 'win32'
  ? 'scripts\\verify_route_coverage.php'
  : 'scripts/verify_route_coverage.php';

export const VERIFICATION_LEVELS = Object.freeze({
  daily: 1,
  feature: 2,
  commit: 3,
});

const LEVEL_LABELS = Object.freeze({
  1: '日常小改',
  2: '功能闭环',
  3: '提交/PR/发布候选',
});

function levelFromToken(token) {
  if (token === '--daily') return VERIFICATION_LEVELS.daily;
  if (token === '--feature') return VERIFICATION_LEVELS.feature;
  if (token === '--commit') return VERIFICATION_LEVELS.commit;
  if (token.startsWith('--level=')) {
    const value = Number(token.slice('--level='.length));
    if ([1, 2, 3].includes(value)) return value;
    throw new Error(`Unsupported verification level: ${token}`);
  }
  return null;
}

export function parseSmartVerificationArgs(argv) {
  const options = {
    level: VERIFICATION_LEVELS.daily,
    run: false,
    json: false,
    help: false,
    files: [],
  };
  let explicitLevel = null;
  let positionalOnly = false;

  for (const token of argv) {
    if (positionalOnly) {
      options.files.push(token);
      continue;
    }
    if (token === '--') {
      positionalOnly = true;
      continue;
    }
    if (token === '--run') {
      options.run = true;
      continue;
    }
    if (token === '--json') {
      options.json = true;
      continue;
    }
    if (token === '--help' || token === '-h') {
      options.help = true;
      continue;
    }

    const parsedLevel = levelFromToken(token);
    if (parsedLevel !== null) {
      if (explicitLevel !== null && explicitLevel !== parsedLevel) {
        throw new Error('Choose only one verification level: --daily, --feature, or --commit.');
      }
      explicitLevel = parsedLevel;
      options.level = parsedLevel;
      continue;
    }
    if (token.startsWith('-')) {
      throw new Error(`Unknown option: ${token}`);
    }
    options.files.push(token);
  }

  if (options.run && options.json) {
    throw new Error('--json is plan-only and cannot be combined with --run.');
  }
  return options;
}

export function normalizeRepoPath(input, repoRoot = defaultRepoRoot, baseDir = repoRoot) {
  const value = String(input || '').trim();
  if (!value) throw new Error('Verification path cannot be empty.');

  const absolute = path.resolve(baseDir, value);
  const relative = path.relative(repoRoot, absolute);
  if (!relative || relative === '.') {
    throw new Error('Select one or more files, not the repository root.');
  }
  if (relative === '..' || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
    throw new Error(`Verification path is outside HOTEL/: ${input}`);
  }
  return relative.split(path.sep).join('/');
}

function runGitPathList(repoRoot, args) {
  const result = spawnSync('git', args, {
    cwd: repoRoot,
    encoding: 'utf8',
    shell: false,
  });
  if (result.status !== 0) {
    const detail = result.error
      ? String(result.error.message || result.error)
      : String(result.stderr || 'unknown git error').trim();
    throw new Error(`Unable to inspect changed files: ${detail}`);
  }
  return result.stdout.split('\0').map((value) => value.trim()).filter(Boolean);
}

export function collectChangedFiles(repoRoot = defaultRepoRoot) {
  const candidates = [
    ...runGitPathList(repoRoot, ['diff', '--name-only', '--diff-filter=ACMRD', '-z', '--']),
    ...runGitPathList(repoRoot, ['diff', '--cached', '--name-only', '--diff-filter=ACMRD', '-z', '--']),
    ...runGitPathList(repoRoot, ['ls-files', '--others', '--exclude-standard', '-z', '--']),
  ];
  return [...new Set(candidates.map((file) => normalizeRepoPath(file, repoRoot, repoRoot)))].sort();
}

function command(id, executable, args, reason) {
  return { id, executable, args, reason };
}

function isNodeFile(file) {
  return /\.(?:js|mjs|cjs)$/i.test(file);
}

function isNodeTest(file) {
  return /^tests\/automation\/.+\.(?:test|spec)\.(?:js|mjs|cjs)$/i.test(file);
}

function isPhpFile(file) {
  return /\.php$/i.test(file);
}

function isPhpTest(file) {
  return /^tests\/.+Test\.php$/i.test(file);
}

function touchesFrontendEntry(file) {
  return file === 'public/index.html'
    || /^public\/.+\.(?:js|mjs|css|html)$/i.test(file)
    || file.startsWith('resources/frontend/');
}

function touchesSharedContract(file) {
  return file.startsWith('app/')
    || file.startsWith('route/')
    || file === 'public/index.html'
    || /^public\/.+\.(?:js|mjs)$/i.test(file);
}

function touchesRouteContract(file) {
  return file.startsWith('route/') || file.startsWith('app/controller/');
}

export function buildVerificationPlan({
  level = VERIFICATION_LEVELS.daily,
  files,
  repoRoot = defaultRepoRoot,
  source = 'explicit',
  pathExists = fs.existsSync,
} = {}) {
  if (![1, 2, 3].includes(level)) {
    throw new Error(`Verification level must be 1, 2, or 3; received ${level}.`);
  }
  if (!Array.isArray(files) || files.length === 0) {
    throw new Error('No files selected for verification.');
  }

  const normalizedFiles = [...new Set(
    files.map((file) => normalizeRepoPath(file, repoRoot, repoRoot)),
  )].sort();
  const commands = [];
  const commandIds = new Set();
  const notes = [];

  const addCommand = (entry) => {
    if (commandIds.has(entry.id)) return;
    commandIds.add(entry.id);
    commands.push(entry);
  };

  addCommand(command(
    'git-diff-check',
    'git',
    ['diff', '--check', 'HEAD', '--', ...normalizedFiles],
    '检查所选已跟踪改动（含暂存区）的空白错误和冲突残留',
  ));

  for (const file of normalizedFiles) {
    const absolutePath = path.join(repoRoot, ...file.split('/'));
    if (!pathExists(absolutePath)) continue;

    if (isPhpFile(file)) {
      addCommand(command(
        `php-lint:${file}`,
        phpCommand,
        ['-l', file],
        `检查 PHP 语法：${file}`,
      ));
    }
    if (isNodeFile(file)) {
      addCommand(command(
        `node-check:${file}`,
        'node',
        ['--check', file],
        `检查 JavaScript 语法：${file}`,
      ));
    }

    if (level < VERIFICATION_LEVELS.commit && isPhpTest(file)) {
      addCommand(command(
        `phpunit-test:${file}`,
        phpCommand,
        [phpunitPath, '--colors=never', file],
        `运行所选 PHP 回归测试：${file}`,
      ));
    }
    if (level < VERIFICATION_LEVELS.commit && isNodeTest(file)) {
      addCommand(command(
        `node-test:${file}`,
        'node',
        ['--test', file],
        `运行所选 Node 回归测试：${file}`,
      ));
    }
  }

  if (level === VERIFICATION_LEVELS.feature) {
    if (normalizedFiles.some(touchesFrontendEntry)) {
      addCommand(command(
        'verify-public-entry',
        npmCommand,
        ['run', 'verify:public-entry'],
        '所选文件可能影响前端入口或公共运行时',
      ));
    }
    if (normalizedFiles.some(touchesSharedContract)) {
      addCommand(command(
        'verify-e2e-contracts',
        npmCommand,
        ['run', 'verify:e2e-contracts'],
        '所选文件可能影响路由、接口或 UI 合同',
      ));
    }
    if (normalizedFiles.some(touchesRouteContract)) {
      addCommand(command(
        'verify-route-coverage',
        phpCommand,
        [routeCoveragePath],
        '所选文件可能改变控制器或路由覆盖',
      ));
    }
    if (!commandIds.has('verify-public-entry') && !commandIds.has('verify-e2e-contracts')) {
      notes.push('当前功能范围未触及共享入口或业务合同，不追加全局守卫。');
    }
  }

  if (level === VERIFICATION_LEVELS.commit) {
    if (normalizedFiles.some(isPhpFile)) {
      addCommand(command(
        'phpunit-full',
        phpCommand,
        [phpunitPath, '--colors=never'],
        '提交范围包含 PHP，运行完整后端测试',
      ));
    }
    if (normalizedFiles.some((file) => isNodeFile(file) || file === 'package.json')) {
      addCommand(command(
        'node-full',
        npmCommand,
        ['run', 'test:node'],
        '提交范围包含 Node/前端代码，运行完整 Node 自动化',
      ));
    }
    addCommand(command(
      'self-check',
      npmCommand,
      ['run', 'self:check'],
      '运行仓库自检伞形入口',
    ));
    notes.push('已去重：self:check 已包含 verify:p0-guards，后者已包含 verify:e2e-contracts。');
    notes.push('若 self:check 在进入 P0 前失败，只在隔离该失败时单独补跑 verify:p0-guards。');
  }

  if (level === VERIFICATION_LEVELS.daily) {
    notes.push('Level 1 不运行项目级全量守卫；行为改动应显式加入对应测试文件或改用 --feature。');
  }
  if (source === 'worktree') {
    notes.push('当前范围来自整个工作区；混合任务应改为显式传入本次文件。');
  }

  return {
    level,
    levelLabel: LEVEL_LABELS[level],
    source,
    files: normalizedFiles,
    commands,
    notes,
    weight: level === 1 ? 'light' : (level === 2 ? 'focused' : 'full'),
  };
}

function quoteArgument(value) {
  const text = String(value);
  return /[\s"]/u.test(text) ? JSON.stringify(text) : text;
}

export function formatVerificationCommand(entry) {
  return [entry.executable, ...entry.args].map(quoteArgument).join(' ');
}

export function printVerificationPlan(plan, { run = false } = {}) {
  console.log(`智能验证计划：Level ${plan.level}（${plan.levelLabel}）`);
  console.log(`范围：${plan.source === 'explicit' ? '显式文件' : '当前工作区'}，${plan.files.length} 个文件`);
  for (const file of plan.files) console.log(`  - ${file}`);
  console.log(`命令：${plan.commands.length} 个，负载=${plan.weight}`);
  plan.commands.forEach((entry, index) => {
    console.log(`  ${index + 1}. ${formatVerificationCommand(entry)}`);
    console.log(`     ${entry.reason}`);
  });
  for (const note of plan.notes) console.log(`说明：${note}`);
  if (!run) console.log('当前仅预览；确认范围后追加 --run 执行。');
}

export function runVerificationPlan(plan, {
  repoRoot = defaultRepoRoot,
  spawn = spawnSync,
} = {}) {
  const startedAt = Date.now();
  for (let index = 0; index < plan.commands.length; index += 1) {
    const entry = plan.commands[index];
    console.log(`\n[${index + 1}/${plan.commands.length}] ${formatVerificationCommand(entry)}`);
    const commandStartedAt = Date.now();
    const result = spawn(entry.executable, entry.args, {
      cwd: repoRoot,
      stdio: 'inherit',
      shell: false,
    });
    const status = result.status ?? 1;
    const elapsedMs = Date.now() - commandStartedAt;
    if (status !== 0) {
      const detail = result.error ? `: ${result.error.message}` : '';
      console.error(`验证失败（${elapsedMs}ms，exit=${status}）${detail}`);
      if (entry.id === 'self-check') {
        console.error('如果失败发生在 P0 守卫之前，可单独运行 npm.cmd run verify:p0-guards 隔离结果。');
      }
      return {
        status,
        failedCommandId: entry.id,
        completedCommands: index,
        elapsedMs: Date.now() - startedAt,
      };
    }
    console.log(`通过（${elapsedMs}ms）`);
  }
  return {
    status: 0,
    failedCommandId: null,
    completedCommands: plan.commands.length,
    elapsedMs: Date.now() - startedAt,
  };
}

function helpText() {
  return `用法：
  node scripts/verify_smart.mjs [--daily|--feature|--commit] [--run] [文件...]

默认行为：
  - Level 1（日常小改）
  - 只预览，不执行
  - 未传文件时读取当前 Git 工作区；混合任务应显式传文件

示例：
  node scripts/verify_smart.mjs public/data-health-static.js
  node scripts/verify_smart.mjs --feature --run public/data-health-static.js
  node scripts/verify_smart.mjs --commit --run app/service/FooService.php tests/FooServiceTest.php

选项：
  --daily       Level 1，日常单文件或低风险修改
  --feature     Level 2，一个功能闭环完成
  --commit      Level 3，提交/PR/发布候选
  --run         执行计划；不加时只预览
  --json        以 JSON 输出计划（不能与 --run 同用）
  --help, -h    显示帮助`;
}

export function main(argv = process.argv.slice(2)) {
  try {
    const options = parseSmartVerificationArgs(argv);
    if (options.help) {
      console.log(helpText());
      return 0;
    }

    const source = options.files.length > 0 ? 'explicit' : 'worktree';
    const files = source === 'explicit'
      ? options.files.map((file) => normalizeRepoPath(file, defaultRepoRoot, process.cwd()))
      : collectChangedFiles(defaultRepoRoot);
    const plan = buildVerificationPlan({
      level: options.level,
      files,
      repoRoot: defaultRepoRoot,
      source,
    });

    if (options.json) {
      console.log(JSON.stringify(plan, null, 2));
      return 0;
    }
    printVerificationPlan(plan, { run: options.run });
    if (!options.run) return 0;

    const result = runVerificationPlan(plan, { repoRoot: defaultRepoRoot });
    if (result.status === 0) {
      console.log(`\n智能验证通过：${result.completedCommands}/${plan.commands.length} 个命令，${result.elapsedMs}ms。`);
    }
    return result.status;
  } catch (error) {
    console.error(`智能验证无法开始：${error.message}`);
    return 1;
  }
}

const directEntry = process.argv[1] ? path.resolve(process.argv[1]) : '';
const isDirectExecution = process.platform === 'win32'
  ? directEntry.toLowerCase() === scriptPath.toLowerCase()
  : directEntry === scriptPath;

if (isDirectExecution) {
  process.exitCode = main();
}
