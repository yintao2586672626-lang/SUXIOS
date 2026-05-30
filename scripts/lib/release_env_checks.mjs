import fs from 'node:fs';
import path from 'node:path';

export function isPlaceholder(value) {
  return String(value ?? '').trim() === '' || /TODO|CHANGE_ME|example|your-|placeholder/i.test(String(value));
}

function parseEnv(content) {
  const values = new Map();
  for (const line of content.split(/\r?\n/)) {
    const match = line.match(/^\s*([^#][A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (!match) {
      continue;
    }
    values.set(match[1], match[2].replace(/^"|"$/g, '').trim());
  }
  return values;
}

function resolveInputPath(repoRoot, filePath) {
  return path.isAbsolute(filePath) ? filePath : path.join(repoRoot, filePath);
}

function isPathInsideRepo(repoRoot, filePath) {
  const resolved = resolveInputPath(repoRoot, filePath);
  const relative = path.relative(repoRoot, resolved);
  return relative === '' || (!relative.startsWith('..') && !path.isAbsolute(relative));
}

export function checkProductionEnvFile({ repoRoot, envFile, requireOutsideRepo }) {
  const failures = [];
  const passes = [];
  const resolvedPath = resolveInputPath(repoRoot, envFile);

  if (!fs.existsSync(resolvedPath)) {
    failures.push(`Production env file was not found: ${envFile}. Set RELEASE_ENV_FILE to a controlled production env file before release.`);
    return { passes, failures };
  }

  const envBaseName = path.basename(envFile).toLowerCase();
  if (envBaseName.includes('example') || envBaseName.includes('sample') || envBaseName.includes('template')) {
    failures.push(`Production env file must not be an example/template file: ${envFile}.`);
  }
  if (requireOutsideRepo && isPathInsideRepo(repoRoot, envFile)) {
    failures.push(`RELEASE_ENV_FILE must point to a controlled location outside the repository, not ${envFile}.`);
  }

  const env = parseEnv(fs.readFileSync(resolvedPath, 'utf8'));
  const appDebug = (env.get('APP_DEBUG') ?? '').toLowerCase();
  const appTrace = (env.get('APP_TRACE') ?? '').toLowerCase();
  const aiConfigSecret = env.get('AI_CONFIG_SECRET') ?? '';
  const dbHost = env.get('DB_HOST') ?? '';
  const dbPass = env.get('DB_PASS') ?? '';
  const dbUser = env.get('DB_USER') ?? '';

  const placeholderFields = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'AI_CONFIG_SECRET'].filter((field) => {
    return isPlaceholder(env.get(field));
  });
  if (placeholderFields.length > 0) {
    failures.push(`Production env contains missing or placeholder values: ${placeholderFields.join(', ')}`);
  }

  if (appDebug === 'false') {
    passes.push('APP_DEBUG is false.');
  } else {
    failures.push('APP_DEBUG is not false; production must not expose debug mode.');
  }
  if (appTrace === 'false') {
    passes.push('APP_TRACE is false.');
  } else {
    failures.push('APP_TRACE is not false; production must not expose trace output.');
  }

  if (aiConfigSecret.length >= 32 && !isPlaceholder(aiConfigSecret)) {
    passes.push('AI_CONFIG_SECRET is present with sufficient length.');
  } else {
    failures.push('AI_CONFIG_SECRET is missing or too short for encrypted AI model configs.');
  }

  if (dbPass.length > 0 && !isPlaceholder(dbPass)) {
    passes.push('DB_PASS is non-empty.');
  } else {
    failures.push('DB_PASS is empty; production database must not use an empty password.');
  }

  if (/^root$/i.test(dbUser.trim())) {
    failures.push('DB_USER must not be root; production must use a least-privilege database user.');
  }
  if (/^(localhost|127\.0\.0\.1|0\.0\.0\.0|::1)$/i.test(dbHost.trim())) {
    failures.push('DB_HOST must not point to localhost or loopback for production release evidence.');
  }

  return { passes, failures };
}
