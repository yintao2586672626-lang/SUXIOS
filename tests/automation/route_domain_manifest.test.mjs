import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync } from 'node:fs';
import test from 'node:test';
import {
  readRouteContractSource,
  registeredRouteContractFiles,
} from '../../scripts/lib/route_contract_source.mjs';

const root = process.cwd();
const bootstrap = readFileSync('route/app.php', 'utf8');
const effectiveSource = readRouteContractSource(root);
const extractedPrefixes = [
  'api/ai-config',
  'api/ai-governance',
  'api/operating-loop',
  'api/operating-opportunities',
  'api/operation',
  'api/opening',
  'api/expansion',
  'api/transfer',
  'admin/competitor-wechat-robot',
  'api/admin/competitor-wechat-robot',
  'api/wechat-notification',
];

test('route bootstrap registers every domain manifest once and stays below the 800-line discovery boundary', () => {
  assert.deepEqual(registeredRouteContractFiles(root), [
    'route/app.php',
    'route/domain/ai_daily_reports.php',
    'route/domain/ai_governance.php',
    'route/domain/operations.php',
    'route/domain/wecom_admin.php',
    'route/domain/wecom_api.php',
    'route/domain/agent_guidance.php',
  ]);
  const discovered = readdirSync('route/domain')
    .filter((file) => file.endsWith('.php'))
    .map((file) => `route/domain/${file}`)
    .sort();
  assert.deepEqual(discovered, registeredRouteContractFiles(root).slice(1).sort());
  assert.ok(bootstrap.split(/\r?\n/).length - 1 < 800);
  for (const prefix of extractedPrefixes) {
    assert.doesNotMatch(bootstrap, new RegExp(`Route::group\\('${prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`));
  }
});

test('extracted method, URL, handler, order and Auth middleware surface matches the reviewed baseline', () => {
  const tuples = [];
  for (const prefix of extractedPrefixes) {
    const escapedPrefix = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const group = effectiveSource.match(new RegExp(
      `Route::group\\('${escapedPrefix}', function \\(\\) \\{([\\s\\S]*?)\\}\\)->middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);`,
    ));
    assert.ok(group, `missing authenticated group ${prefix}`);
    for (const route of group[1].matchAll(/Route::(get|post|put|delete|patch|any|rule)\('([^']+)', '([^']+)'\);/g)) {
      tuples.push(`${prefix}|${route[1]}|${route[2]}|${route[3]}`);
    }
  }

  assert.equal(tuples.length, 125);
  assert.equal(
    createHash('sha256').update(tuples.join('\n')).digest('hex'),
    'dedd6a8580657a9ff62ed26a9fd9ab3e644ab04404d3ea157dcc80d706cb39ce',
  );
});
