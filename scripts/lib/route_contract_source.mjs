import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const DOMAIN_REQUIRE_PATTERN = /require __DIR__ \. '\/domain\/([a-z0-9_]+\.php)';/g;

export function registeredRouteContractFiles(root = process.cwd()) {
  const bootstrapPath = resolve(root, 'route/app.php');
  const bootstrap = readFileSync(bootstrapPath, 'utf8');
  const files = ['route/app.php'];
  const seen = new Set();

  for (const match of bootstrap.matchAll(DOMAIN_REQUIRE_PATTERN)) {
    const relativePath = `route/domain/${match[1]}`;
    if (seen.has(relativePath)) {
      throw new Error(`duplicate route domain manifest registration: ${relativePath}`);
    }
    seen.add(relativePath);
    readFileSync(resolve(root, relativePath), 'utf8');
    files.push(relativePath);
  }

  return files;
}

export function readRouteContractSource(root = process.cwd()) {
  const bootstrap = readFileSync(resolve(root, 'route/app.php'), 'utf8');
  const seen = new Set();

  return bootstrap.replace(DOMAIN_REQUIRE_PATTERN, (_statement, fileName) => {
    const relativePath = `route/domain/${fileName}`;
    if (seen.has(relativePath)) {
      throw new Error(`duplicate route domain manifest registration: ${relativePath}`);
    }
    seen.add(relativePath);
    return readFileSync(resolve(root, relativePath), 'utf8');
  });
}
