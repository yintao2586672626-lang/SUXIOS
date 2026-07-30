#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const ignoredDirectories = new Set(['.git', 'node_modules', 'vendor', 'dist', 'build']);
const scriptExtensions = new Set(['.sh', '.bash', '.ps1', '.bat', '.cmd', '.py', '.js', '.mjs', '.cjs', '.ts', '.rb', '.php', '.exe']);
const textExtensions = new Set(['', '.md', '.txt', '.json', '.yaml', '.yml', '.toml', '.xml', '.csv', '.sh', '.bash', '.ps1', '.bat', '.cmd', '.py', '.js', '.mjs', '.cjs', '.ts', '.rb', '.php']);
const riskPatterns = [
  { severity: 'high', code: 'pipe-to-shell', pattern: /(?:curl|wget)[^\n|]*\|\s*(?:sh|bash|zsh)\b/i, message: 'Downloads remote content and pipes it to a shell.' },
  { severity: 'high', code: 'encoded-powershell', pattern: /powershell(?:\.exe)?[^\n]*(?:-enc|-encodedcommand)\b/i, message: 'Runs an encoded PowerShell command.' },
  { severity: 'high', code: 'invoke-expression', pattern: /\b(?:Invoke-Expression|iex)\b/i, message: 'Uses dynamic PowerShell expression execution.' },
  { severity: 'high', code: 'broad-destructive-command', pattern: /(?:rm\s+-rf\s+(?:\/|~|\$HOME)|git\s+reset\s+--hard)/i, message: 'Contains a broad destructive command.' },
  { severity: 'high', code: 'preapproved-shell', pattern: /^\s*allowed-tools\s*:\s*.*(?:\*|shell|bash|powershell)/im, message: 'Pre-approves a powerful shell tool.' },
  { severity: 'medium', code: 'network-access', scriptsOnly: true, pattern: /(?:\bcurl\b|\bwget\b|Invoke-WebRequest|Invoke-RestMethod|\bfetch\s*\(|requests\.(?:get|post|put|delete)\s*\(|https?:\/\/)/i, message: 'Script performs or declares network access; review hosts, redirects, and data flow.' },
  { severity: 'medium', code: 'runtime-install', scriptsOnly: true, pattern: /(?:\bnpm\s+(?:install|i)\b|\bnpx\b|\bpip(?:3)?\s+install\b|\buv\s+(?:add|pip\s+install)\b)/i, message: 'Script installs or executes a dependency at runtime; pin and review it before use.' },
  { severity: 'medium', code: 'prompt-injection-language', pattern: /(?:ignore\s+(?:all\s+)?previous\s+instructions|reveal\s+(?:the\s+)?system\s+prompt|exfiltrat(?:e|ion))/i, message: 'Contains prompt-injection or exfiltration language.' },
  { severity: 'medium', code: 'credential-access', pattern: /(?:process\.env|os\.environ|System\.Environment|getenv\s*\(|credential|api[_-]?key|secret|token)/i, message: 'References environment variables or credentials; review data access.' },
  { severity: 'medium', code: 'machine-absolute-path', pattern: /(?:[A-Za-z]:\\(?:Users|Windows|Program Files)\\|\/(?:home|Users)\/[^/\s]+)/, message: 'Contains a machine-specific absolute path that reduces portability.' },
];

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

function printAndExit(payload, exitCode) {
  process.stdout.write(`${JSON.stringify(payload, null, 2)}\n`);
  process.exit(exitCode);
}

const targetArg = process.argv[2];
if (!targetArg || targetArg === '--help' || targetArg === '-h') {
  const payload = {
    status: targetArg ? 'help' : 'invalid',
    usage: 'node scripts/inspect-skill-package.mjs <skill-directory>',
    note: 'Performs read-only static preview. It does not prove a skill is safe.',
  };
  printAndExit(payload, targetArg ? 0 : 2);
}

const target = path.resolve(targetArg);
if (!fs.existsSync(target) || !fs.statSync(target).isDirectory()) {
  printAndExit({ status: 'invalid', target, validation_errors: ['Target is not an existing directory.'] }, 2);
}

const validationErrors = [];
const findings = [];
const files = [];
let totalBytes = 0;

function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    const relative = path.relative(target, absolute).split(path.sep).join('/');
    if (entry.isSymbolicLink()) {
      findings.push({ severity: 'high', code: 'symlink', file: relative, line: null, message: 'Symlink target is not inspected.' });
      continue;
    }
    if (entry.isDirectory()) {
      if (!ignoredDirectories.has(entry.name)) {
        walk(absolute);
      }
      continue;
    }
    if (!entry.isFile()) {
      continue;
    }

    const stat = fs.statSync(absolute);
    const content = fs.readFileSync(absolute);
    const extension = path.extname(entry.name).toLowerCase();
    totalBytes += stat.size;
    const isScript = relative.startsWith('scripts/') || scriptExtensions.has(extension);
    files.push({ path: relative, bytes: stat.size, sha256: sha256(content), script: isScript });

    if (extension === '.exe') {
      findings.push({ severity: 'high', code: 'binary-executable', file: relative, line: null, message: 'Bundled executable requires manual provenance review.' });
    }
    if (stat.size > 1024 * 1024) {
      findings.push({ severity: 'medium', code: 'large-file', file: relative, line: null, message: 'File exceeds 1 MiB and was not text-scanned.' });
      continue;
    }
    if (!textExtensions.has(extension)) {
      continue;
    }

    const text = content.toString('utf8');
    const lines = text.split(/\r?\n/);
    for (const risk of riskPatterns) {
      if (risk.scriptsOnly && !isScript) {
        continue;
      }
      const index = lines.findIndex((line) => risk.pattern.test(line));
      if (index !== -1) {
        findings.push({ severity: risk.severity, code: risk.code, file: relative, line: index + 1, message: risk.message });
      }
    }
  }
}

walk(target);
files.sort((left, right) => left.path.localeCompare(right.path));

const skillFile = path.join(target, 'SKILL.md');
let metadata = { name: null, description_length: 0, license_declared: false, compatibility_declared: false, metadata_declared: false, allowed_tools_declared: false };
if (!fs.existsSync(skillFile)) {
  validationErrors.push('SKILL.md is missing.');
} else {
  const skillText = fs.readFileSync(skillFile, 'utf8');
  const frontmatterMatch = skillText.match(/^---\s*\r?\n([\s\S]*?)\r?\n---(?:\r?\n|$)/);
  if (!frontmatterMatch) {
    validationErrors.push('SKILL.md frontmatter is missing or malformed.');
  } else {
    const frontmatter = frontmatterMatch[1];
    const scalar = (key) => {
      const match = frontmatter.match(new RegExp(`^${key}:\\s*(.+)$`, 'm'));
      return match ? match[1].trim().replace(/^['"]|['"]$/g, '') : null;
    };
    const name = scalar('name');
    const description = scalar('description') ?? '';
    metadata = {
      name,
      description_length: description.length,
      license_declared: /^license:/m.test(frontmatter),
      compatibility_declared: /^compatibility:/m.test(frontmatter),
      metadata_declared: /^metadata:/m.test(frontmatter),
      allowed_tools_declared: /^allowed-tools:/m.test(frontmatter),
    };
    if (!name || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) || name.length > 64) {
      validationErrors.push('Skill name must be 1-64 lowercase letters, numbers, or single hyphens.');
    }
    if (name && name !== path.basename(target)) {
      validationErrors.push(`Skill name ${name} does not match directory ${path.basename(target)}.`);
    }
    if (!description || description.length > 1024) {
      validationErrors.push('Skill description must be 1-1024 characters.');
    }
  }
}

if (totalBytes > 5 * 1024 * 1024) {
  findings.push({ severity: 'medium', code: 'large-package', file: null, line: null, message: 'Package exceeds 5 MiB; review whether bundled content is necessary.' });
}

const manifestInput = files.map((file) => `${file.path}\0${file.sha256}`).join('\n');
const scripts = files.filter((file) => file.script).map((file) => file.path);
const status = validationErrors.length > 0 ? 'invalid' : findings.length > 0 || scripts.length > 0 ? 'review_required' : 'previewed';
const payload = {
  status,
  target,
  metadata,
  package: {
    file_count: files.length,
    total_bytes: totalBytes,
    file_tree_sha256: sha256(Buffer.from(manifestInput, 'utf8')),
    scripts,
  },
  risk_findings: findings,
  validation_errors: validationErrors,
  manual_review_required: true,
  install_allowed: false,
  next_action: validationErrors.length > 0
    ? 'Fix validation errors before considering installation.'
    : 'Review every instruction and script, record provenance and dependencies, then run one isolated sample before integration.',
};

printAndExit(payload, validationErrors.length > 0 ? 1 : 0);
