#!/usr/bin/env node
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  captureRuntimeAssetIdentity,
  verifyServedRuntimeAssetIdentity,
} from './lib/runtime_asset_identity.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const baseArgument = process.argv.find((argument) => argument.startsWith('--base-url='));
const baseUrl = baseArgument?.slice('--base-url='.length) || '';
const identity = captureRuntimeAssetIdentity(repoRoot);
if (!baseUrl) {
  console.log(JSON.stringify({ status: 'passed', identity }, null, 2));
  process.exit(0);
}

const verification = await verifyServedRuntimeAssetIdentity(baseUrl, identity);
console.log(JSON.stringify({
  status: verification.failures.length === 0 ? 'passed' : 'failed',
  identity,
  verification,
}, null, 2));
if (verification.failures.length > 0) process.exit(1);
