#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const failOnFindings = process.argv.includes('--fail-on-findings');

const excludedPrefixes = [
  '.git/',
  'node_modules/',
  'vendor/',
  'storage/',
  'runtime/',
  'output/',
  'reports/',
  'public/assets/',
];

const excludedExtensions = new Set([
  '.7z',
  '.avif',
  '.bmp',
  '.docx',
  '.db',
  '.eot',
  '.gif',
  '.ico',
  '.jpg',
  '.jpeg',
  '.lock',
  '.pdf',
  '.png',
  '.rar',
  '.sqlite',
  '.sql',
  '.ttf',
  '.otf',
  '.webp',
  '.woff',
  '.woff2',
  '.xlsx',
  '.zip',
]);

const mojibakeTokens = [
  '鏃犳硶',
  '缇庡洟',
  '娴忚',
  '姄鍙',
  '閫€鍑',
  '宸叉姄',
  '璇锋',
  '鎼虹',
  '杩佺Щ',
  '绾夸笂',
  '妯″潡',
  '娣诲姞',
  '妫€',
  '琛ㄥ',
  '绱㈠紩',
  '涓囧',
  '妗岄潰',
  '瀹挎瀽',
  '鍒濆',
  '鐗圽',
  '�',
];

function normalizeGitPath(filePath) {
  return filePath.replaceAll('\\', '/');
}

function shouldScan(filePath) {
  const normalized = normalizeGitPath(filePath);
  if (normalized === 'scripts/scan_mojibake_text.mjs') {
    return false;
  }
  if (excludedPrefixes.some((prefix) => normalized.startsWith(prefix))) {
    return false;
  }
  if (excludedExtensions.has(path.extname(normalized).toLowerCase())) {
    return false;
  }
  return true;
}

function listTrackedFiles() {
  const stdout = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' });
  return stdout
    .split('\0')
    .filter(Boolean)
    .filter(shouldScan);
}

function findToken(line) {
  return mojibakeTokens.find((token) => line.includes(token)) ?? null;
}

function isDocumentedEncodingExample(filePath, line) {
  return (
    ['DEV_LOG.md', '项目问题解决方案库.md'].includes(normalizeGitPath(filePath))
    && line.includes('乱码')
    && (
      line.includes('PowerShell')
      || line.includes('错误信息')
      || line.includes('路径出现')
      || line.includes('中文路径显示')
    )
  );
}

const findings = [];
let ignoredExamples = 0;
for (const filePath of listTrackedFiles()) {
  const buffer = fs.readFileSync(filePath);
  if (buffer.includes(0)) {
    continue;
  }
  const text = buffer.toString('utf8');
  const lines = text.split(/\r?\n/);
  lines.forEach((line, index) => {
    const token = findToken(line);
    if (token === null) {
      return;
    }
    if (isDocumentedEncodingExample(filePath, line)) {
      ignoredExamples += 1;
      return;
    }
    findings.push({
      filePath,
      line: index + 1,
      token,
      snippet: line.trim().slice(0, 160),
    });
  });
}

if (findings.length === 0) {
  console.log('No mojibake text findings in tracked source/docs scope.');
  if (ignoredExamples > 0) {
    console.log(`Ignored documented encoding examples: ${ignoredExamples}`);
  }
  process.exit(0);
}

console.log(`Mojibake text findings: ${findings.length}`);
for (const finding of findings) {
  console.log(`${finding.filePath}:${finding.line}: token=${JSON.stringify(finding.token)} ${finding.snippet}`);
}

if (failOnFindings) {
  process.exit(1);
}
