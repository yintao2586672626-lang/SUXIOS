import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import { PurgeCSS } from 'purgecss';
import { readFrontendAssetVersion } from './frontend_asset_version.mjs';

const TAILWIND_DYNAMIC_PREFIX = '(?:bg|text|border|ring|shadow|from|via|to|divide|placeholder|stroke|fill|grid-cols|col-span|row-span|w|h|p[trblxy]?|m[trblxy]?|gap|space-[xy]|rounded|opacity|z|top|right|bottom|left|inset|translate-[xy]|scale(?:-[xy])?|rotate|skew-[xy])';
const TAILWIND_TOKEN_PATTERN = /[A-Za-z0-9_!./:[\]%-]+/g;

const toPosix = (value) => value.replaceAll('\\', '/');

const walkFiles = (directory, predicate, output = []) => {
  if (!fs.existsSync(directory)) return output;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) walkFiles(file, predicate, output);
    else if (predicate(file)) output.push(file);
  }
  return output;
};

export function collectTailwindContentFiles(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const publicFiles = walkFiles(publicRoot, (file) => {
    const relative = toPosix(path.relative(publicRoot, file));
    if (!/\.(?:html|js)$/.test(relative)) return false;
    if (relative.includes('/vendor/') || relative.startsWith('vendor/')) return false;
    if (/\.min\.js$/.test(relative)) return false;
    return !['vue.global.prod.js', 'chart.umd.js'].includes(path.basename(relative));
  });
  const phpFiles = [path.join(repoRoot, 'app'), path.join(repoRoot, 'route')]
    .flatMap((directory) => walkFiles(directory, (file) => file.endsWith('.php')));
  const resourceFiles = walkFiles(path.join(repoRoot, 'resources/frontend'), (file) => file.endsWith('.html'));

  return [...new Set([...publicFiles, ...resourceFiles, ...phpFiles])].sort((left, right) => left.localeCompare(right));
}

export function extractTailwindCandidateTokens(content) {
  return String(content || '').match(TAILWIND_TOKEN_PATTERN) || [];
}

const escapeCssClassToken = (token) => [...String(token || '')]
  .map((character, index) => index === 0 && /[0-9]/.test(character)
    ? `\\3${character} `
    : (/[A-Za-z0-9_-]/.test(character) ? character : `\\${character}`))
  .join('');

export function collectCssClassSelectors(css) {
  return new Set(
    [...String(css || '').matchAll(/\.((?:\\[0-9a-fA-F]{1,6}\s?|\\.|[A-Za-z0-9_-])+)(?=[\s:{,.#>+~]|\[)/g)]
      .map((match) => match[1])
  );
}

export function cssContainsClassSelector(css, token) {
  return collectCssClassSelectors(css).has(escapeCssClassToken(token));
}

export function findDynamicTailwindConstructions(contentFiles) {
  const interpolation = new RegExp(`\\b${TAILWIND_DYNAMIC_PREFIX}-\\$\\{`, 'g');
  const concatenation = new RegExp(`[\\'"\\x60]${TAILWIND_DYNAMIC_PREFIX}-[\\'"\\x60]\\s*\\+`, 'g');
  const rows = [];

  for (const file of contentFiles) {
    const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
    lines.forEach((line, index) => {
      const matches = [...line.matchAll(interpolation), ...line.matchAll(concatenation)];
      const hasStyleConstruction = matches.some((match) => {
        const before = line.slice(0, match.index);
        return before.lastIndexOf('class') >= before.lastIndexOf('key');
      });
      if (hasStyleConstruction) {
        rows.push({ file, line: index + 1, source: line.trim().slice(0, 300) });
      }
      interpolation.lastIndex = 0;
      concatenation.lastIndex = 0;
    });
  }
  return rows;
}

export async function buildTailwindRuntimeCss(source, contentFiles) {
  const purgeResult = await new PurgeCSS().purge({
    content: contentFiles,
    css: [{ raw: source }],
    defaultExtractor: (content) => extractTailwindCandidateTokens(content),
    fontFace: false,
    keyframes: false,
    variables: false,
    rejected: true,
  });
  const result = purgeResult[0];
  if (!result?.css) throw new Error('PurgeCSS returned an empty Tailwind runtime artifact.');

  const leadingLicenses = source.match(/^(?:\/\*![\s\S]*?\*\/)+/)?.[0] || '';
  const css = result.css.startsWith('/*!') ? result.css : `${leadingLicenses}${result.css}`;
  return `${css.trim()}\n`;
}

export async function inspectTailwindRuntimeBuild(repoRoot) {
  const sourcePath = path.join(repoRoot, 'public/tailwind.full.css');
  const artifactPath = path.join(repoRoot, 'public/tailwind.min.css');
  const indexPath = path.join(repoRoot, 'public/index.html');
  const source = fs.readFileSync(sourcePath, 'utf8');
  const artifact = fs.readFileSync(artifactPath, 'utf8');
  const html = fs.readFileSync(indexPath, 'utf8');
  const contentFiles = collectTailwindContentFiles(repoRoot);
  const rebuilt = await buildTailwindRuntimeCss(source, contentFiles);
  const dynamicConstructions = findDynamicTailwindConstructions(contentFiles);
  const tokens = new Set(contentFiles.flatMap((file) => extractTailwindCandidateTokens(fs.readFileSync(file, 'utf8'))));
  const sourceSelectors = collectCssClassSelectors(source);
  const artifactSelectors = collectCssClassSelectors(artifact);
  const referencedTailwindTokens = [...tokens]
    .filter((token) => sourceSelectors.has(escapeCssClassToken(token)))
    .sort();
  const missingReferencedSelectors = referencedTailwindTokens
    .filter((token) => !artifactSelectors.has(escapeCssClassToken(token)));
  const artifactHash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const sourceBytes = Buffer.byteLength(source);
  const artifactBytes = Buffer.byteLength(artifact);
  const sourceGzipBytes = gzipSync(source, { level: 6 }).length;
  const artifactGzipBytes = gzipSync(artifact, { level: 6 }).length;
  const failures = [];
  let tailwindVersion = null;
  try {
    tailwindVersion = readFrontendAssetVersion(html, 'tailwind.min.css');
  } catch (error) {
    failures.push(error.message);
  }

  if (artifact !== rebuilt) failures.push('public/tailwind.min.css is stale or does not match the pinned PurgeCSS build.');
  if (dynamicConstructions.length) failures.push('Runtime source contains unresolved dynamic Tailwind class construction.');
  if (missingReferencedSelectors.length) failures.push('The runtime Tailwind artifact dropped statically referenced selectors.');
  if (!(artifactBytes < sourceBytes * 0.25)) failures.push('The runtime Tailwind artifact must stay below 25% of the full source size.');
  if (!(artifactGzipBytes < sourceGzipBytes * 0.25)) failures.push('The gzipped Tailwind runtime artifact must stay below 25% of the full source size.');
  if (!artifact.startsWith('/*! tailwindcss v2.2.19')) failures.push('The Tailwind license/version banner must be preserved.');
  if (!tailwindVersion || tailwindVersion.hash !== artifactHash) {
    failures.push('public/index.html must reference the current Tailwind runtime content hash.');
  }
  if (/tailwind\.full\.css/.test(html)) failures.push('public/index.html must not load the full rollback stylesheet.');

  return {
    failures,
    dynamic_constructions: dynamicConstructions,
    missing_referenced_selectors: missingReferencedSelectors,
    metrics: {
      content_file_count: contentFiles.length,
      referenced_selector_count: referencedTailwindTokens.length,
      source_bytes: sourceBytes,
      artifact_bytes: artifactBytes,
      raw_reduction_ratio: sourceBytes ? 1 - (artifactBytes / sourceBytes) : 0,
      source_gzip_bytes: sourceGzipBytes,
      artifact_gzip_bytes: artifactGzipBytes,
      gzip_reduction_ratio: sourceGzipBytes ? 1 - (artifactGzipBytes / sourceGzipBytes) : 0,
      artifact_hash: artifactHash,
    },
  };
}
