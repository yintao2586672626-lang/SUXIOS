const freezeList = (values) => Object.freeze([...values]);

export const MANAGED_FRONTEND_PATHS = Object.freeze({
  prefixes: freezeList([
    'resources/frontend/',
  ]),
  exact: freezeList([
    'package.json',
    'package-lock.json',
  ]),
  patterns: freezeList([
    /^public\/(?:[^/]+\/)*[^/]+\.(?:css|html|js)$/u,
    /^scripts\/(?:lib\/)?[^/]*(?:frontend|tailwind|login_critical|public_entry)[^/]*\.mjs$/u,
  ]),
});

export const PUBLIC_ENTRY_PATHS = Object.freeze({
  exact: freezeList([
    'public/router.php',
    'public/.htaccess',
  ]),
});

export const normalizeManagedGitPath = (value) => String(value || '').trim().replaceAll('\\', '/');

export const isManagedFrontendPath = (value) => {
  const file = normalizeManagedGitPath(value);
  return MANAGED_FRONTEND_PATHS.exact.includes(file)
    || MANAGED_FRONTEND_PATHS.prefixes.some((prefix) => file.startsWith(prefix))
    || MANAGED_FRONTEND_PATHS.patterns.some((pattern) => pattern.test(file));
};

export const isPublicFrontendPath = (value) => {
  const file = normalizeManagedGitPath(value);
  return file.startsWith('public/') && isManagedFrontendPath(file);
};

export const isPublicEntryPath = (value) => {
  const file = normalizeManagedGitPath(value);
  return PUBLIC_ENTRY_PATHS.exact.includes(file) || isPublicFrontendPath(file);
};
