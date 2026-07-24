import { readFileSync } from 'node:fs';
import readline from 'node:readline/promises';
import { join } from 'node:path';
import process from 'node:process';

export function parseArgs(argv) {
  const result = {};
  for (const arg of argv) {
    if (!arg.startsWith('--')) {
      continue;
    }
    const [rawKey, ...rest] = arg.slice(2).split('=');
    const key = rawKey.replace(/-([a-z])/g, (_, char) => char.toUpperCase());
    result[key] = rest.length ? rest.join('=') : 'true';
  }
  return result;
}

export function timestamp(date = new Date()) {
  return date.toISOString().replace(/[-:T.Z]/g, '').slice(0, 14);
}

export function formatDateInTimeZone(date = new Date(), timeZone = 'Asia/Shanghai') {
  const value = date instanceof Date ? date : new Date(date);
  if (Number.isNaN(value.getTime())) {
    throw new TypeError('Invalid date');
  }
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(value);
  const byType = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${byType.year}-${byType.month}-${byType.day}`;
}

export function safeName(value) {
  return String(value || 'default').replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 80);
}

export async function waitForEnter(prompt) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  try {
    await rl.question(prompt);
  } finally {
    rl.close();
  }
}

export function fail(message) {
  console.error(message);
  process.exit(1);
}

export function readText(relativePath, root) {
  const absolutePath = root ? join(root, relativePath) : relativePath;
  const buffer = readFileSync(absolutePath);
  if (buffer[0] === 0xff && buffer[1] === 0xfe) {
    return buffer.toString('utf16le');
  }
  return buffer.toString('utf8');
}

export function extractPhpMethod(content, methodName) {
  const pattern = new RegExp(`(?:public\\s+|protected\\s+|private\\s+)?function\\s+${escapeRegExp(methodName)}\\s*\\(`);
  const match = pattern.exec(content);
  if (!match) {
    return '';
  }

  const bodyStart = content.indexOf('{', match.index);
  if (bodyStart === -1) {
    return '';
  }

  let depth = 0;
  for (let i = bodyStart; i < content.length; i += 1) {
    const char = content[i];
    if (char === '{') depth += 1;
    if (char === '}') depth -= 1;
    if (depth === 0) {
      return content.slice(bodyStart + 1, i);
    }
  }

  return '';
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
