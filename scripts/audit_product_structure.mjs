import fs from 'node:fs';
import path from 'node:path';

// Static inventory. It never loads application code, secrets or runtime data.
const root = process.cwd();
const files = [];
const visit = relative => {
  for (const item of fs.readdirSync(path.join(root, relative), { withFileTypes: true })) {
    const file = path.posix.join(relative, item.name);
    if (item.isDirectory()) visit(file);
    else if (file.endsWith('.php')) files.push(file);
  }
};
visit('app');
const manifest = JSON.parse(fs.readFileSync('resources/frontend/templates/manifest.json', 'utf8'));
const domains = new Map();
for (const fragment of manifest.fragments) {
  const group = domains.get(fragment.domain) || { domain: fragment.domain, active: [], frozen: [] };
  group[fragment.runtime === false ? 'frozen' : 'active'].push(fragment.path);
  domains.set(fragment.domain, group);
}
const references = new Map();
const addReference = (table, file) => {
  if (!references.has(table)) references.set(table, new Set());
  references.get(table).add(file);
};
const fileStats = [];
for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  fileStats.push({ file, lines: source.split('\n').length, bytes: Buffer.byteLength(source) });
  for (const match of source.matchAll(/(?:Db::name\(\s*|\$(?:name|table)\s*=\s*|const\s+[A-Z_]*TABLE[A-Z_]*\s*=\s*)['"]([a-z][a-z0-9_]+)['"]/g)) addReference(match[1], file);
}
const layers = {};
for (const file of files) {
  const layer = file.split('/')[1];
  layers[layer] = (layers[layer] || 0) + 1;
}
const report = {
  generated_at: new Date().toISOString(), scope: 'active_worktree_static_inventory',
  evidence_limit: 'Literal references are discovery hints, not proof that a table is unused. No usage frequency is inferred.',
  layers, domains: [...domains.values()],
  largest_php_sources: fileStats.sort((a, b) => b.lines - a.lines).slice(0, 12),
  table_literal_references: Object.fromEntries([...references].sort(([a], [b]) => a.localeCompare(b)).map(([table, refs]) => [table, [...refs]])),
};
console.log(JSON.stringify(report, null, 2));
