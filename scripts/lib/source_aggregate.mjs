import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const moduleDirectory = path.dirname(fileURLToPath(import.meta.url));
const registryPath = path.resolve(moduleDirectory, '..', '..', 'rules', 'source-concern-contract-registry.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
if (registry?.schema_version !== 'suxios.source_concern_registry.v1'
  || registry?.aggregates === null
  || typeof registry?.aggregates !== 'object'
  || Array.isArray(registry?.aggregates)
) {
  throw new Error('Source concern registry is invalid.');
}

export const SOURCE_CONCERN_PATHS = Object.freeze(Object.fromEntries(
  Object.entries(registry.aggregates).map(([parent, members]) => {
    if (!Array.isArray(members) || members.some((member) => typeof member !== 'string')) {
      throw new Error(`Source concern registry members are invalid: ${parent}`);
    }
    return [parent, Object.freeze([...members])];
  }),
));

export function readSourceAggregate(relativePath, options = {}) {
  const repoRoot = path.resolve(options.repoRoot || process.cwd());
  const normalizedPath = String(relativePath || '')
    .replaceAll('\\', '/')
    .replace(/^\/+/, '');
  const members = [
    normalizedPath,
    ...(SOURCE_CONCERN_PATHS[normalizedPath] || []),
  ];

  return members.map((member) => {
    const absolutePath = path.join(repoRoot, member);
    if (!fs.existsSync(absolutePath)) {
      throw new Error(`Source aggregate member is missing: ${member}`);
    }
    return fs.readFileSync(absolutePath, 'utf8');
  }).join('\n');
}
