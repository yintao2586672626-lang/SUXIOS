import crypto from 'node:crypto';
import { gzipSync } from 'node:zlib';
import { minify } from 'terser';

export const FRONTEND_ENTRY_MINIFY_OPTIONS = Object.freeze({
  ecma: 2020,
  module: false,
  toplevel: false,
  compress: Object.freeze({
    defaults: true,
    passes: 2,
  }),
  mangle: Object.freeze({
    safari10: true,
    toplevel: false,
  }),
  format: Object.freeze({
    ascii_only: false,
    beautify: false,
    comments: false,
    semicolons: true,
  }),
});

export async function buildFrontendEntry(source) {
  const result = await minify(
    { 'app-main.js': String(source || '') },
    structuredClone(FRONTEND_ENTRY_MINIFY_OPTIONS)
  );
  if (!result.code) throw new Error('Terser returned an empty frontend entry artifact.');
  return `${result.code}\n`;
}

export async function inspectFrontendEntryBuild({ source, artifact, html }) {
  const rebuilt = await buildFrontendEntry(source);
  const sourceBytes = Buffer.byteLength(source);
  const artifactBytes = Buffer.byteLength(artifact);
  const sourceGzipBytes = gzipSync(source, { level: 1 }).length;
  const artifactGzipBytes = gzipSync(artifact, { level: 1 }).length;
  const artifactHash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
  const deferredScripts = [...String(html || '').matchAll(/<script\s+defer\s+src="([^"]+)"[^>]*><\/script>/g)]
    .map((match) => match[1].split('?')[0]);
  const failures = [];

  if (artifact !== rebuilt) failures.push('public/app-main.min.js is stale or was not generated with the pinned build contract.');
  if (!(artifactBytes < sourceBytes * 0.7)) failures.push('The runtime entry must remain below 70% of the canonical source size.');
  if (!(artifactGzipBytes < sourceGzipBytes * 0.8)) failures.push('The gzipped runtime entry must remain below 80% of the canonical source size.');
  if (!new RegExp(`<script defer src="app-main\\.min\\.js\\?v=[^"]*-h${artifactHash}"`).test(html)) {
    failures.push('public/index.html must reference the current minified entry content hash.');
  }
  if (/<script\s+defer\s+src="app-main\.js\?/.test(html)) {
    failures.push('public/index.html must not load the canonical unminified source at runtime.');
  }
  if (deferredScripts[0] !== 'vue.runtime.global.prod.js'
    || deferredScripts.at(-2) !== 'app-render.min.js'
    || deferredScripts.at(-1) !== 'app-main.min.js') {
    failures.push('The deferred startup chain must keep runtime Vue first, the render before app-main, and app-main last.');
  }
  try {
    new Function(artifact);
  } catch (error) {
    failures.push(`The generated runtime entry is not valid JavaScript: ${error.message}`);
  }

  return {
    failures,
    metrics: {
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
