#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  SOURCE_HOTSPOT_BUDGETS,
  sourceLineCount,
} from './lib/source_hotspot_budget.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const registryPath = path.join(repoRoot, 'rules', 'verifier-domain-contract-registry.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
const failures = [];

if (registry.schema_version !== 'suxios.verifier_domain_registry.v1') {
  failures.push('registry schema_version is invalid');
}
const domains = Array.isArray(registry.domains) ? registry.domains : [];
const domainIds = new Set();
const registeredEntrypoints = new Set();
const allowedClaims = new Set(['contract_only', 'local_integration_no_field']);
const unregisteredLargeScriptPolicy = String(registry.policy?.unregistered_large_script || '');
const maximumDefaultClaim = String(registry.policy?.maximum_default_claim || '');
if (unregisteredLargeScriptPolicy !== 'fail_closed') {
  failures.push('unregistered_large_script policy must be fail_closed');
}
if (!allowedClaims.has(maximumDefaultClaim)) {
  failures.push('maximum_default_claim policy is invalid');
}
for (const domain of domains) {
  const id = String(domain?.id || '').trim();
  if (!/^[a-z0-9_]+$/u.test(id) || domainIds.has(id)) {
    failures.push(`invalid or duplicate domain id: ${id || '<empty>'}`);
    continue;
  }
  domainIds.add(id);
  const claimLimit = String(domain.claim_limit || maximumDefaultClaim);
  if (!allowedClaims.has(claimLimit)) {
    failures.push(`invalid claim_limit for ${id}`);
  }
  if (!Array.isArray(domain.owners) || domain.owners.length === 0
    || !Array.isArray(domain.evidence_kinds) || domain.evidence_kinds.length === 0
    || !Array.isArray(domain.entrypoints) || domain.entrypoints.length === 0
  ) {
    failures.push(`incomplete domain contract: ${id}`);
    continue;
  }
  for (const entrypoint of domain.entrypoints) {
    const normalized = String(entrypoint || '').replaceAll('\\', '/');
    if (!normalized.startsWith('scripts/') || registeredEntrypoints.has(normalized)) {
      failures.push(`invalid or duplicate verifier entrypoint: ${normalized}`);
      continue;
    }
    registeredEntrypoints.add(normalized);
    if (!fs.existsSync(path.join(repoRoot, normalized))) {
      failures.push(`registered verifier entrypoint is missing: ${normalized}`);
    }
  }
}

const largeScriptLines = Number(registry.policy?.large_script_lines || 0);
if (!Number.isInteger(largeScriptLines) || largeScriptLines < 1000) {
  failures.push('large_script_lines policy is invalid');
}
const largeScripts = [];
const visit = (directory) => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      visit(absolute);
      continue;
    }
    if (!entry.isFile() || !['.js', '.mjs', '.php'].includes(path.extname(entry.name))) continue;
    const lines = sourceLineCount(fs.readFileSync(absolute, 'utf8'));
    if (lines > largeScriptLines) {
      largeScripts.push(path.relative(repoRoot, absolute).replaceAll('\\', '/'));
    }
  }
};
visit(path.join(repoRoot, 'scripts'));

const budgetPaths = new Set(SOURCE_HOTSPOT_BUDGETS.map((item) => item.path));
for (const script of largeScripts) {
  if (!registeredEntrypoints.has(script) && unregisteredLargeScriptPolicy === 'fail_closed') {
    failures.push(`large verifier script is outside the domain registry: ${script}`);
  }
  if (!budgetPaths.has(script)) {
    failures.push(`large verifier script is outside the source hotspot budget: ${script}`);
  }
}

if (failures.length > 0) {
  console.error(`Verifier domain registry failed:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}
console.log(`Verifier domain registry passed: ${domains.length} domains, ${largeScripts.length} governed large scripts.`);
