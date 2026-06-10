#!/usr/bin/env node
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { parseArgs } from './lib/shared_helpers.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = parseArgs(process.argv.slice(2));
const outputJson = args.json === 'true';
const topLimit = Math.max(1, Number(args.top || 12));
const changedPathSet = new Set(gitChangedFiles());

const targets = [
  analyzePublicIndex('public/index.html'),
  analyzePhpController('app/controller/OnlineData.php'),
];

const report = {
  schema_version: 1,
  generated_at: new Date().toISOString(),
  repo_root: repoRoot,
  targets,
};

if (outputJson) {
  console.log(JSON.stringify(report, null, 2));
} else {
  renderText(report);
}

function analyzePublicIndex(relativePath) {
  const lines = readLines(relativePath);
  const functionSpans = spansFromMatches(lines, [
    /^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/,
    /^\s*const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?\(/,
    /^\s*const\s+([A-Za-z_$][\w$]*)\s*=\s*async\s*\(/,
    /^\s*const\s+([A-Za-z_$][\w$]*)\s*=\s*computed\s*\(/,
  ]).map((row) => ({
    ...row,
    domain: classifyFrontendDomain(row.name),
  }));
  const pageRefs = countMatches(lines, /currentPage\s*={0,3}\s*['"]([A-Za-z0-9_-]+)['"]/g);
  const vIfRefs = countMatches(lines, /currentPage\s*===\s*['"]([A-Za-z0-9_-]+)['"]/g);
  return {
    path: relativePath,
    type: 'public_spa',
    lines: lines.length,
    worktree_changed: changedPathSet.has(relativePath),
    page_ref_count: pageRefs.reduce((sum, row) => sum + row.count, 0),
    current_page_refs: pageRefs,
    current_page_v_if_refs: vIfRefs,
    function_count: functionSpans.length,
    domain_summary: summarizeByDomain(functionSpans),
    largest_blocks: functionSpans.sort(bySpanDesc).slice(0, topLimit),
  };
}

function analyzePhpController(relativePath) {
  const lines = readLines(relativePath);
  const methods = spansFromMatches(lines, [
    /^\s*(public|protected|private)\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/,
  ], { nameIndex: 2, visibilityIndex: 1 }).map((row) => ({
    ...row,
    domain: classifyBackendDomain(row.name),
  }));
  return {
    path: relativePath,
    type: 'php_controller',
    lines: lines.length,
    worktree_changed: changedPathSet.has(relativePath),
    method_count: methods.length,
    domain_summary: summarizeByDomain(methods),
    largest_blocks: methods.sort(bySpanDesc).slice(0, topLimit),
  };
}

function readLines(relativePath) {
  const absolutePath = path.join(repoRoot, relativePath);
  const content = fs.readFileSync(absolutePath, 'utf8').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  return content.endsWith('\n') ? content.slice(0, -1).split('\n') : content.split('\n');
}

function spansFromMatches(lines, patterns, options = {}) {
  const matches = [];
  for (let index = 0; index < lines.length; index += 1) {
    const line = lines[index];
    for (const pattern of patterns) {
      const match = pattern.exec(line);
      if (!match) {
        continue;
      }
      matches.push({
        name: match[options.nameIndex ?? 1],
        visibility: options.visibilityIndex ? match[options.visibilityIndex] : '',
        start_line: index + 1,
      });
      break;
    }
  }

  return matches.map((row, index) => {
    const next = matches[index + 1]?.start_line ?? (lines.length + 1);
    return {
      ...row,
      end_line: next - 1,
      span_lines: next - row.start_line,
    };
  });
}

function countMatches(lines, pattern) {
  const counts = new Map();
  for (const line of lines) {
    pattern.lastIndex = 0;
    for (const match of line.matchAll(pattern)) {
      const key = match[1];
      counts.set(key, (counts.get(key) || 0) + 1);
    }
  }
  return [...counts.entries()]
    .map(([name, count]) => ({ name, count }))
    .sort((left, right) => right.count - left.count || left.name.localeCompare(right.name));
}

function summarizeByDomain(rows) {
  const byDomain = new Map();
  for (const row of rows) {
    const current = byDomain.get(row.domain) || { domain: row.domain, blocks: 0, span_lines: 0 };
    current.blocks += 1;
    current.span_lines += row.span_lines;
    byDomain.set(row.domain, current);
  }
  return [...byDomain.values()].sort((left, right) => right.span_lines - left.span_lines);
}

function classifyFrontendDomain(name) {
  return classifyByName(name, [
    ['ctrip', 'ctrip'],
    ['meituan', 'meituan'],
    ['ota', 'ota'],
    ['agent', 'agent'],
    ['ai', 'ai'],
    ['daily', 'daily_report'],
    ['operation', 'operation'],
    ['dashboard', 'dashboard'],
    ['strategy', 'strategy'],
    ['simulation', 'simulation'],
    ['transfer', 'transfer'],
    ['expansion', 'expansion'],
    ['opening', 'opening'],
    ['hotel', 'hotel_admin'],
    ['user', 'user_admin'],
    ['role', 'role_admin'],
    ['cookie', 'cookie'],
    ['config', 'config'],
  ]);
}

function classifyBackendDomain(name) {
  return classifyByName(name, [
    ['ctrip', 'ctrip'],
    ['meituan', 'meituan'],
    ['capture', 'capture'],
    ['traffic', 'traffic'],
    ['cookie', 'cookie'],
    ['profile', 'profile'],
    ['dataSource', 'data_source'],
    ['autoFetch', 'auto_fetch'],
    ['dailyData', 'daily_data'],
    ['dashboard', 'dashboard'],
    ['analysis', 'analysis'],
    ['comment', 'comment'],
    ['config', 'config'],
    ['sync', 'sync'],
  ]);
}

function classifyByName(name, rules) {
  const lowered = String(name).toLowerCase();
  for (const [needle, domain] of rules) {
    if (lowered.includes(needle.toLowerCase())) {
      return domain;
    }
  }
  return 'general';
}

function bySpanDesc(left, right) {
  return right.span_lines - left.span_lines || left.start_line - right.start_line;
}

function gitChangedFiles() {
  const result = spawnSync('git', ['-c', 'core.quotePath=false', 'status', '--short'], {
    cwd: repoRoot,
    encoding: 'utf8',
    shell: false,
  });
  if (result.status !== 0) {
    return [];
  }
  return result.stdout
    .split(/\r?\n/)
    .map((line) => line.slice(3).trim())
    .filter(Boolean)
    .map((value) => {
      const marker = ' -> ';
      const pathValue = value.includes(marker) ? value.slice(value.lastIndexOf(marker) + marker.length) : value;
      return normalizePath(pathValue.replace(/^"|"$/g, ''));
    });
}

function normalizePath(value) {
  return String(value).replace(/\\/g, '/');
}

function renderText(data) {
  console.log('Project split map');
  console.log(`Repo: ${data.repo_root}`);
  console.log(`Generated: ${data.generated_at}`);
  console.log('');
  for (const target of data.targets) {
    console.log(`${target.path}`);
    console.log(`- type: ${target.type}`);
    console.log(`- lines: ${target.lines}`);
    console.log(`- worktree changed: ${target.worktree_changed ? 'yes' : 'no'}`);
    if (target.method_count !== undefined) {
      console.log(`- methods: ${target.method_count}`);
    }
    if (target.function_count !== undefined) {
      console.log(`- functions: ${target.function_count}`);
      console.log(`- currentPage refs: ${target.page_ref_count}`);
    }
    console.log('');
    console.log('Domain summary');
    renderRows(target.domain_summary.slice(0, topLimit), ['domain', 'blocks', 'span_lines']);
    console.log('');
    if (target.current_page_refs?.length) {
      console.log('Top currentPage refs');
      renderRows(target.current_page_refs.slice(0, topLimit), ['name', 'count']);
      console.log('');
    }
    console.log('Largest blocks');
    renderRows(target.largest_blocks, ['name', 'domain', 'visibility', 'start_line', 'end_line', 'span_lines']);
    console.log('');
  }
}

function renderRows(rows, columns) {
  if (!rows.length) {
    console.log('(none)');
    return;
  }
  const widths = {};
  for (const column of columns) {
    widths[column] = Math.max(column.length, ...rows.map((row) => String(row[column] ?? '').length));
  }
  console.log(columns.map((column) => column.padEnd(widths[column])).join('  '));
  console.log(columns.map((column) => '-'.repeat(widths[column])).join('  '));
  for (const row of rows) {
    console.log(columns.map((column) => String(row[column] ?? '').padEnd(widths[column])).join('  '));
  }
}
