import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const failures = [];
const passes = [];

const resolveRepoPath = (relativePath) => path.join(repoRoot, relativePath);
const exists = (relativePath) => fs.existsSync(resolveRepoPath(relativePath));
const read = (relativePath) => fs.readFileSync(resolveRepoPath(relativePath), 'utf8');

function check(label, condition, detail = '') {
  if (condition) {
    passes.push(label);
    return;
  }
  failures.push(detail ? `${label}: ${detail}` : label);
}

function checkFile(relativePath, label = relativePath) {
  check(`${label} exists`, exists(relativePath), relativePath);
}

function balancedBlock(source, openOffset) {
  if (openOffset < 0 || source[openOffset] !== '{') return '';

  let depth = 0;
  let state = 'code';
  for (let offset = openOffset; offset < source.length; offset += 1) {
    const char = source[offset];
    const next = source[offset + 1] || '';

    if (state === 'line-comment') {
      if (char === '\n') state = 'code';
      continue;
    }
    if (state === 'block-comment') {
      if (char === '*' && next === '/') {
        state = 'code';
        offset += 1;
      }
      continue;
    }
    if (state === 'single-quote') {
      if (char === '\\') {
        offset += 1;
      } else if (char === "'") {
        state = 'code';
      }
      continue;
    }
    if (state === 'double-quote') {
      if (char === '\\') {
        offset += 1;
      } else if (char === '"') {
        state = 'code';
      }
      continue;
    }
    if (state === 'template') {
      if (char === '\\') {
        offset += 1;
      } else if (char === '`') {
        state = 'code';
      }
      continue;
    }

    if (char === '/' && next === '/') {
      state = 'line-comment';
      offset += 1;
      continue;
    }
    if (char === '/' && next === '*') {
      state = 'block-comment';
      offset += 1;
      continue;
    }
    if (char === '#') {
      state = 'line-comment';
      continue;
    }
    if (char === "'") {
      state = 'single-quote';
      continue;
    }
    if (char === '"') {
      state = 'double-quote';
      continue;
    }
    if (char === '`') {
      state = 'template';
      continue;
    }
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(openOffset, offset + 1);
    }
  }
  return '';
}

function extractPhpMethod(source, methodName) {
  const escapedName = methodName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp(`(?:public|protected|private)?\\s*(?:static\\s+)?function\\s+${escapedName}\\s*\\(`);
  const match = pattern.exec(source);
  if (!match) return '';
  const openOffset = source.indexOf('{', match.index + match[0].length);
  return balancedBlock(source, openOffset);
}

function extractPhpConstantStrings(source, constantName) {
  const escapedName = constantName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = new RegExp(`const\\s+${escapedName}\\s*=\\s*\\[([\\s\\S]*?)\\];`).exec(source);
  if (!match) return null;
  return [...match[1].matchAll(/['"]([^'"]+)['"]\s*(?:=>|,)/g)].map((item) => item[1]);
}

function sourceSection(source, startNeedle, endNeedle) {
  const start = source.indexOf(startNeedle);
  const end = start === -1 ? -1 : source.indexOf(endNeedle, start + startNeedle.length);
  return start !== -1 && end !== -1 ? source.slice(start, end) : '';
}

const requiredArtifacts = [
  'database/migrations/20260710_create_ota_credentials.sql',
  'app/model/OtaCredential.php',
  'app/service/OtaCredentialEnvelope.php',
  'app/service/OtaCredentialVault.php',
  'app/service/OtaCredentialMigrationService.php',
  'app/command/MigrateOtaCredentials.php',
  'tests/OtaCredentialEnvelopeTest.php',
  'tests/OtaCredentialVaultTest.php',
  'tests/OtaCredentialMigrationServiceTest.php',
  'tests/OtaCredentialResponseTest.php',
  'tests/OtaCredentialReadPathTest.php',
];
for (const artifact of requiredArtifacts) checkFile(artifact);

if (requiredArtifacts.every(exists)) {
  const schema = read('database/migrations/20260710_create_ota_credentials.sql');
  const model = read('app/model/OtaCredential.php');
  const envelope = read('app/service/OtaCredentialEnvelope.php');
  const vault = read('app/service/OtaCredentialVault.php');

  check(
    'credential schema has a tenant/hotel/platform/config unique scope',
    /UNIQUE\s+KEY\s+`?\w+`?\s*\(\s*`?tenant_id`?\s*,\s*`?system_hotel_id`?\s*,\s*`?platform`?\s*,\s*`?config_id`?\s*\)/i.test(schema),
    'expected a four-part unique key'
  );
  for (const column of ['encrypted_payload', 'payload_version', 'key_id', 'credential_status', 'created_by']) {
    check(`credential schema declares ${column}`, new RegExp(`\\b${column}\\b`, 'i').test(schema), column);
  }
  check(
    'credential model hides ciphertext from serialization',
    /protected\s+\$hidden\s*=\s*\[[^\]]*['"]encrypted_payload['"]/s.test(model),
    'OtaCredential::$hidden'
  );
  check(
    'credential envelope uses authenticated encryption with scope-bound AAD',
    envelope.includes("private const ALGORITHM = 'AES-256-GCM'")
      && envelope.includes('random_bytes(self::NONCE_BYTES)')
      && envelope.includes('self::AAD_PREFIX . $scope')
      && envelope.includes('openssl_encrypt(')
      && envelope.includes('openssl_decrypt('),
    'AES-256-GCM + random nonce + scope AAD'
  );
  check(
    'credential vault binds every lookup to tenant and hotel',
    vault.includes("where('tenant_id', $t)")
      && vault.includes("where('system_hotel_id', $h)")
      && vault.includes("Hotel::where('id', $h)->where('tenant_id', $t)"),
    'tenant_id/system_hotel_id/hotel tenant validation'
  );
  check(
    'credential vault decrypts only inside an execution callback',
    extractPhpMethod(vault, 'withPayloadForExecution').includes('$this->payloadForExecution(')
      && extractPhpMethod(vault, 'withPayloadForExecution').includes('$consumer($payload)')
      && extractPhpMethod(vault, 'withPayloadForExecution').includes('last_used_at')
      && extractPhpMethod(vault, 'payloadForExecution').includes('$this->envelope->decrypt(')
      && extractPhpMethod(vault, 'verifiedMetadataForExecution').includes('payloadForExecution(')
      && !extractPhpMethod(vault, 'verifiedMetadataForExecution').includes('last_used_at')
      && !extractPhpMethod(vault, 'verifiedMetadataForExecution').includes('->decrypt('),
    'withPayloadForExecution callback boundary'
  );
}

const keyInitializerArtifacts = [
  'app/service/OtaCredentialKeyInitializer.php',
  'scripts/initialize_ota_credential_key.php',
  'tests/OtaCredentialKeyInitializerTest.php',
];
for (const artifact of keyInitializerArtifacts) checkFile(artifact);

if (keyInitializerArtifacts.every(exists)) {
  const initializer = read('app/service/OtaCredentialKeyInitializer.php');
  const initializerCli = read('scripts/initialize_ota_credential_key.php');
  const initializerTest = read('tests/OtaCredentialKeyInitializerTest.php');
  const assess = extractPhpMethod(initializer, 'assess');
  const parseAssignments = extractPhpMethod(initializer, 'parseAssignments');
  const insertGlobalCredentialBlock = extractPhpMethod(initializer, 'insertGlobalCredentialBlock');
  const updatedContentMatchesRuntime = extractPhpMethod(initializer, 'updatedContentMatchesRuntime');
  const executeInitializer = extractPhpMethod(initializer, 'execute');
  const atomicReplace = extractPhpMethod(initializer, 'atomicReplace');
  const securePermissions = extractPhpMethod(initializer, 'secureAndVerifyPermissions');
  const windowsAcl = extractPhpMethod(initializer, 'secureWindowsAcl');
  const safeInitializerSummary = extractPhpMethod(initializer, 'summary');
  const sidecarOffset = executeInitializer.indexOf("$envPath . '.ota-key.lock'");
  const lockOffset = executeInitializer.indexOf('flock($lockHandle, LOCK_EX)');
  const rereadOffset = executeInitializer.indexOf('$this->readExistingFile($envPath)');
  const reassessOffset = executeInitializer.indexOf('$this->assess($content, $mode)');
  const runtimeValidationOffset = executeInitializer.indexOf('$this->updatedContentMatchesRuntime(');
  const replaceOffset = executeInitializer.indexOf('$this->atomicReplace(');
  const wholeIniValidationOffset = assess.indexOf('@parse_ini_string($content, true, INI_SCANNER_RAW)');
  const assignmentParseOffset = assess.indexOf('$this->parseAssignments($content)');

  check(
    'credential key initializer generates a canonical 32-byte key and scope-safe key ID',
    initializer.includes('private const KEY_BYTES = 32;')
      && initializer.includes('random_bytes($length)')
      && initializer.includes('$encodedKey = base64_encode($rawKey)')
      && initializer.includes("$keyId = 'ota-' . $date . '-' . substr($fingerprint, 0, 12)")
      && initializer.includes("preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $keyId)"),
    '32 bytes + canonical base64 + validated ota key ID'
  );
  check(
    'credential key initializer fails closed for duplicate, partial, and mixed definitions',
    assess.includes('count($keys) > 1 || count($ids) > 1')
      && assess.includes('$keyDefined = count($keys) === 1')
      && assess.includes('$idDefined = count($ids) === 1')
      && assess.includes('$keyDefined !== $idDefined')
      && assess.includes("'duplicate_definition'")
      && assess.includes("'partial_configuration'")
      && assess.includes("'malformed_configuration'")
      && assess.includes("'unsupported_definition'"),
    'definition counts distinguish missing, empty, partial, duplicate, malformed, and export states'
  );
  check(
    'credential key initializer rejects case and INI-section scope bypasses',
    parseAssignments.includes('strtoupper($match[2])')
      && parseAssignments.includes("'canonical' => $match[2] === $normalizedKey")
      && parseAssignments.includes("'section' => $currentSection")
      && parseAssignments.includes("(?:export[ \\t]+)?")
      && parseAssignments.includes("$/Di")
      && assess.includes("!$assignment['canonical']")
      && assess.includes("$assignment['section'] !== null"),
    'case-insensitive discovery + canonical uppercase/global-scope enforcement'
  );
  check(
    'credential key initializer validates the whole INI before every assessment outcome',
    wholeIniValidationOffset !== -1
      && assignmentParseOffset > wholeIniValidationOffset
      && assess.includes("'env_runtime_validation_failed'"),
    'full parse_ini_string validation precedes missing, blocked, and already-configured classification'
  );
  check(
    'credential key initializer inserts missing global keys before the first INI section',
    insertGlobalCredentialBlock.includes('PREG_OFFSET_CAPTURE')
      && insertGlobalCredentialBlock.includes('$sectionMatch[0][1]')
      && insertGlobalCredentialBlock.includes("substr($content, 0, $offset) . $block . substr($content, $offset)"),
    'global credential block precedes the first section header'
  );
  check(
    'credential key initializer validates full INI runtime equivalence before any secret write',
    runtimeValidationOffset > reassessOffset
      && replaceOffset > runtimeValidationOffset
      && updatedContentMatchesRuntime.includes('@parse_ini_string($content, true, INI_SCANNER_RAW)')
      && updatedContentMatchesRuntime.includes('array_key_exists(self::KEY_VARIABLE, $parsed)')
      && updatedContentMatchesRuntime.includes('array_key_exists(self::ID_VARIABLE, $parsed)')
      && updatedContentMatchesRuntime.includes('hash_equals($encodedKey, $parsed[self::KEY_VARIABLE])')
      && updatedContentMatchesRuntime.includes('hash_equals($keyId, $parsed[self::ID_VARIABLE])'),
    'parse_ini_string full document check occurs before atomicReplace'
  );
  check(
    'credential key initializer uses a stable sidecar lock and rereads before atomic replacement',
    sidecarOffset !== -1
      && lockOffset > sidecarOffset
      && rereadOffset > lockOffset
      && reassessOffset > rereadOffset
      && replaceOffset > reassessOffset,
    'sidecar LOCK_EX -> reread -> reassess -> atomicReplace'
  );
  check(
    'credential key initializer atomically replaces from a synced same-directory secure temp',
    initializer.includes("$envPath . '.ota-key.tmp.'")
      && initializer.includes("@fopen($tempPath, 'x+b')")
      && atomicReplace.includes("'temp_prewrite'")
      && atomicReplace.includes('$this->writeAll(')
      && atomicReplace.includes('$this->flushAndSync(')
      && atomicReplace.includes("'temp_prerename'")
      && atomicReplace.includes('($this->renameFile)($tempPath, $envPath)')
      && atomicReplace.includes("'final'")
      && initializer.includes("function_exists('fsync')")
      && !initializer.includes('ftruncate(')
      && !initializer.includes("fopen($envPath, 'c+b')")
      && !initializer.includes('@unlink($envPath)')
      && !/\bcopy\s*\(/.test(initializer),
    'secure temp -> write all -> fflush/fsync -> close -> rename -> final permission verify; no in-place truncation'
  );
  check(
    'credential key initializer permissions fail closed on POSIX and Windows',
    securePermissions.includes("PHP_OS_FAMILY === 'Windows'")
      && initializer.includes('@chmod($path, 0600)')
      && initializer.includes('@fileperms($path)')
      && windowsAcl.includes('SetAccessRuleProtection($true, $false)')
      && windowsAcl.includes('SetOwner($current)')
      && windowsAcl.includes("'S-1-5-18'")
      && windowsAcl.includes("'S-1-5-32-544'")
      && windowsAcl.includes("'bypass_shell' => true")
      && windowsAcl.includes("'SUXI_OTA_ACL_TARGET' => $path")
      && windowsAcl.includes("$output === 'acl_verified'"),
    '0600 mode verification or exact protected Windows ACL via proc_open array'
  );
  check(
    'credential key initializer summary exposes only safe metadata',
    safeInitializerSummary !== ''
      && [
        "'mode' =>",
        "'status' =>",
        "'configured' =>",
        "'initialized' =>",
        "'key_id' =>",
        "'fingerprint' =>",
        "'reason_code' =>",
      ].every((field) => safeInitializerSummary.includes(field))
      && !/['"](?:raw_key|encoded_key|env_path|env_content|secret|key_b64|exception|message)['"]\s*=>/i.test(safeInitializerSummary),
    'safe initializer summary allowlist'
  );
  check(
    'credential key initializer CLI is dry-run first and never prints exception text',
    initializerCli.includes('$execute = false;')
      && initializerCli.includes("$argument === '--execute'")
      && initializerCli.includes("str_starts_with($argument, '--env=')")
      && initializerCli.includes('catch (Throwable)')
      && !initializerCli.includes('getMessage(')
      && !initializerCli.includes('fwrite(STDERR')
      && initializerCli.includes('json_encode($summary'),
    '--execute opt-in + --env isolation + safe JSON only'
  );
  check(
    'credential key initializer tests cover fail-closed definitions and atomic failures without writes',
    initializerTest.includes('key missing with empty id definition')
      && initializerTest.includes('empty key definition with id missing')
      && initializerTest.includes('export key definition')
      && initializerTest.includes('testShortWriteFailureLeavesOriginalBytesAndNoSecretTempArtifact')
      && initializerTest.includes('testRenameFailureLeavesOriginalBytesAndNoSecretTempArtifact')
      && initializerTest.includes('testPermissionFailureBeforeWriteLeavesOriginalBytesAndNoSecretTempArtifact')
      && initializerTest.includes('testFinalPermissionFailureAtomicallyRestoresOriginalBytes')
      && initializerTest.includes('lowercase target definition')
      && initializerTest.includes('case variant duplicates canonical target')
      && initializerTest.includes('canonical targets inside section')
      && initializerTest.includes('testInvalidIniBlocksBeforeAnySecretWrite')
      && initializerTest.includes('testValidCredentialPairWithUnrelatedBrokenSectionFailsClosedInDryRunAndExecute')
      && initializerTest.includes("self::assertSame('blocked', $summary['status'])")
      && initializerTest.includes('self::assertSame($content, file_get_contents($path))'),
    'definition and atomic failure regression coverage'
  );
  check(
    'credential key initializer tests prove Think Env runtime compatibility',
    initializerTest.includes('use think\\Env;')
      && initializerTest.includes('$env = new Env();')
      && initializerTest.includes('$env->load($path);')
      && initializerTest.includes("$env->get('OTA_CREDENTIAL_KEY_B64')")
      && initializerTest.includes("$env->get('OTA_CREDENTIAL_KEY_ID')")
      && initializerTest.includes('testMissingTargetsAreInsertedBeforeFirstIniSectionAndLoadGlobally')
      && initializerTest.includes('testRealPlatformPermissionsAndAtomicReplacementIntegrateOnTemporaryEnv'),
    'generated and quoted temp env files load through think\\Env'
  );
}

checkFile('package.json');
checkFile('config/console.php');
if (exists('package.json')) {
  try {
    const packageJson = JSON.parse(read('package.json'));
    check(
      'package registers the vault verifier',
      packageJson.scripts?.['verify:ota-credential-vault'] === 'node scripts/verify_ota_credential_vault.mjs',
      'verify:ota-credential-vault'
    );
    check(
      'package migration preview omits the execute flag',
      packageJson.scripts?.['migrate:ota-credentials:dry-run'] === 'C:\\xampp\\php\\php.exe think migrate:ota-credentials',
      'migrate:ota-credentials:dry-run'
    );
    check(
      'package migration mutation requires the execute flag',
      packageJson.scripts?.['migrate:ota-credentials:execute'] === 'C:\\xampp\\php\\php.exe think migrate:ota-credentials --execute',
      'migrate:ota-credentials:execute'
    );
    check(
      'package credential key initialization preview omits the execute flag',
      packageJson.scripts?.['init:ota-credential-key:dry-run'] === 'C:\\xampp\\php\\php.exe scripts\\initialize_ota_credential_key.php',
      'init:ota-credential-key:dry-run'
    );
    check(
      'package credential key initialization mutation requires the execute flag',
      packageJson.scripts?.['init:ota-credential-key:execute'] === 'C:\\xampp\\php\\php.exe scripts\\initialize_ota_credential_key.php --execute',
      'init:ota-credential-key:execute'
    );
  } catch (error) {
    check('package.json is valid JSON', false, error instanceof Error ? error.message : String(error));
  }
}
if (exists('config/console.php')) {
  check(
    'console registers the migration command',
    read('config/console.php').includes("'migrate:ota-credentials' => 'app\\command\\MigrateOtaCredentials'"),
    'migrate:ota-credentials command mapping'
  );
}

checkFile('app/model/SystemConfig.php');
if (exists('app/model/SystemConfig.php')) {
  const systemConfig = read('app/model/SystemConfig.php');
  const protectedKeys = extractPhpConstantStrings(systemConfig, 'PROTECTED_OTA_KEYS');
  const durableKeys = extractPhpConstantStrings(systemConfig, 'DURABLE_VALUE_CACHE_KEYS');
  const shouldUseDurableCache = extractPhpMethod(systemConfig, 'shouldUseDurableValueCache');
  const writeDurableCache = extractPhpMethod(systemConfig, 'writeDurableValueCache');
  const clearProtectedCaches = extractPhpMethod(systemConfig, 'clearProtectedOtaCaches');
  const protectedKeyPredicate = extractPhpMethod(systemConfig, 'isProtectedOtaKey');
  const requiredProtectedKeys = ['ctrip_config_list', 'meituan_config_list'];

  check(
    'SystemConfig declares both protected OTA list keys',
    Array.isArray(protectedKeys) && requiredProtectedKeys.every((key) => protectedKeys.includes(key)),
    requiredProtectedKeys.join(', ')
  );
  check(
    'protected OTA keys are excluded from the durable-cache allowlist',
    Array.isArray(protectedKeys)
      && Array.isArray(durableKeys)
      && protectedKeys.every((key) => !durableKeys.includes(key)),
    'PROTECTED_OTA_KEYS must not intersect DURABLE_VALUE_CACHE_KEYS'
  );
  check(
    'durable cache writes are gated by the safe allowlist',
    writeDurableCache.includes('shouldUseDurableValueCache($key)')
      && shouldUseDurableCache.includes('DURABLE_VALUE_CACHE_KEYS'),
    'writeDurableValueCache -> shouldUseDurableValueCache -> allowlist'
  );
  check(
    'protected OTA in-process and durable caches can be cleared',
    clearProtectedCaches.includes('isProtectedOtaKey(')
      && clearProtectedCaches.includes("cache('system_config_value_' . sha1($key), null)"),
    'clearProtectedOtaCaches'
  );
  check(
    'legacy OTA data-config and Cookie-cache namespaces are protected from generic access',
    protectedKeyPredicate.includes("str_starts_with($key, 'data_config_')")
      && protectedKeyPredicate.includes("str_starts_with($key, 'online_data_cookies_')"),
    'SystemConfig::isProtectedOtaKey legacy namespace guards'
  );
}

checkFile('app/controller/SystemConfigController.php');
if (exists('app/controller/SystemConfigController.php')) {
  const controller = read('app/controller/SystemConfigController.php');
  const index = extractPhpMethod(controller, 'index');
  const update = extractPhpMethod(controller, 'update');
  const importMethod = extractPhpMethod(controller, 'import');
  const guardOne = extractPhpMethod(controller, 'guardProtectedOtaKey');
  const safeAll = extractPhpMethod(controller, 'getAllConfigsWithoutProtectedOtaCache');
  const indexGuard = index.indexOf('guardProtectedOtaKey($requestedKey)');
  const indexRead = index.indexOf('SystemConfig::getValue($requestedKey');
  const singleUpdateGuard = update.indexOf('guardProtectedOtaKey($key)');
  const firstSet = update.indexOf('SystemConfig::setValue($key');
  const bulkUpdateGuard = update.indexOf('guardProtectedOtaKeys($data)');
  const lastSet = update.lastIndexOf('SystemConfig::setValue($key');

  check(
    'generic config index rejects a protected requested key before reading it',
    indexGuard !== -1 && indexRead !== -1 && indexGuard < indexRead,
    'index guard order'
  );
  check(
    'generic config index filters protected keys from full responses',
    index.includes('getAllConfigsWithoutProtectedOtaCache()')
      && !safeAll.includes('SystemConfig::getAllConfigs()')
      && safeAll.includes("LOWER(config_key) NOT LIKE 'data_config_%'")
      && safeAll.includes("LOWER(config_key) NOT LIKE 'online_data_cookies_%'")
      && safeAll.includes('SystemConfig::clearProtectedOtaCaches()'),
    'database-filtered full-config response'
  );
  check(
    'generic config update guards single and bulk writes before persistence',
    singleUpdateGuard !== -1
      && firstSet !== -1
      && singleUpdateGuard < firstSet
      && bulkUpdateGuard !== -1
      && lastSet !== -1
      && bulkUpdateGuard < lastSet,
    'update guard order'
  );
  check(
    'generic config guard fails closed with HTTP 403',
    guardOne.includes('SystemConfig::isProtectedOtaKey($key)') && /abort\(403\s*,/.test(guardOne),
    'guardProtectedOtaKey'
  );
  check(
    'generic config import also rejects protected OTA keys',
    importMethod.includes("guardProtectedOtaKeys($data['configs'])"),
    'import guard'
  );
}

const endpointContracts = [
  ['app/controller/concern/OnlineDataRequestConcern.php', 'getCtripConfigList', 'list'],
  ['app/controller/concern/OnlineDataRequestConcern.php', 'getCtripConfigDetail', 'detail'],
  ['app/controller/concern/MeituanConfigConcern.php', 'getMeituanConfigList', 'list'],
  ['app/controller/concern/MeituanConfigConcern.php', 'getMeituanConfigDetail', 'detail'],
];
for (const [relativePath, methodName, kind] of endpointContracts) {
  checkFile(relativePath);
  if (!exists(relativePath)) continue;
  const method = extractPhpMethod(read(relativePath), methodName);
  const runtimeListSanitized = kind === 'list'
    && /\$list\s*=\s*\$this->sanitizeStoredOtaConfigListForRuntime\(\$list\)\s*;/.test(method);
  const runtimeDetailSanitized = kind === 'detail'
    && /\$safeList\s*=\s*\$this->sanitizeStoredOtaConfigListForRuntime\(\[\s*\$id\s*=>\s*\$list\[\$id\]\s*\]\)\s*;/.test(method)
    && /(?:success|json)\s*\(\s*\$safeList\s*\[\s*\$id\s*\]\s*\?\?\s*\[\]\s*\)/.test(method);
  const sanitizes = kind === 'list'
    ? runtimeListSanitized
      || /array_map\s*\(\s*\[\s*\$this\s*,\s*['"]sanitizeSecretConfig['"]\s*\]/.test(method)
    : runtimeDetailSanitized;
  const rawReturn = kind === 'list'
    ? !runtimeListSanitized && /(?:success|json)\s*\(\s*(?:array_values\s*\(\s*)?\$list\b/.test(method)
    : /(?:success|json)\s*\(\s*\$list\s*\[\s*\$id\s*\]/.test(method);
  check(`${methodName} sanitizes every returned config`, method !== '' && sanitizes, `${relativePath}::${methodName}`);
  check(`${methodName} never returns a raw stored list item`, method !== '' && !rawReturn, `${relativePath}::${methodName}`);
}
if (exists('app/controller/concern/OtaConfigConcern.php')) {
  const otaConfig = read('app/controller/concern/OtaConfigConcern.php');
  const sanitizer = extractPhpMethod(otaConfig, 'sanitizeSecretConfig');
  const runtimeListSanitizer = extractPhpMethod(otaConfig, 'sanitizeStoredOtaConfigListForRuntime');
  check(
    'OTA response sanitizer returns metadata split from secret material',
    sanitizer.includes('splitOtaConfigSecrets($item)') && /return\s+\$metadata\s*;/.test(sanitizer),
    'sanitizeSecretConfig'
  );
  check(
    'normal runtime OTA config lists are metadata-only and legacy secret rows are blocked for migration',
    runtimeListSanitizer.includes('splitOtaConfigSecrets($item)')
      && runtimeListSanitizer.includes('sanitizeSecretConfig($item)')
      && runtimeListSanitizer.includes("'migration_reason'] = 'legacy_secret_fields_present'")
      && runtimeListSanitizer.includes("'credential_status'] = 'migration_required'")
      && runtimeListSanitizer.includes("'has_cookies'] = false"),
    'sanitizeStoredOtaConfigListForRuntime'
  );
  for (const methodName of [
    'getStoredCtripConfigList',
    'getStoredMeituanConfigList',
    'getStoredCtripConfigListForLightCache',
    'getStoredMeituanConfigListForLightCache',
  ]) {
    check(
      `${methodName} passes decoded legacy rows through the runtime metadata sanitizer`,
      extractPhpMethod(otaConfig, methodName).includes('sanitizeStoredOtaConfigListForRuntime('),
      `OtaConfigConcern::${methodName}`
    );
  }
}
if (exists('app/controller/concern/CtripCommentsConcern.php')) {
  const comments = read('app/controller/concern/CtripCommentsConcern.php');
  const saveCommentConfig = extractPhpMethod(comments, 'saveCtripCommentConfig');
  const listCommentConfig = extractPhpMethod(comments, 'getCtripCommentConfigList');
  check(
    'legacy Ctrip comment credential storage is explicitly disabled',
    saveCommentConfig.includes('Legacy Ctrip comment Cookie/API config storage is disabled.')
      && !/saveOtaDataConfigValue|SystemConfig::setValue|['"](?:cookies?|spidertoken|payload_json)['"]\s*=>/i.test(saveCommentConfig),
    'CtripCommentsConcern::saveCtripCommentConfig'
  );
  check(
    'legacy Ctrip comment config reads return no stored credential material',
    /return\s+\$this->success\(\[\]\)\s*;/.test(listCommentConfig)
      && !/readOtaDataConfigValue|SystemConfig::getValue|data_config_/i.test(listCommentConfig),
    'CtripCommentsConcern::getCtripCommentConfigList'
  );
}
if (exists('app/controller/concern/CookieEndpointConcern.php')) {
  const cookieEndpoints = read('app/controller/concern/CookieEndpointConcern.php');
  for (const [methodName, requiredText] of [
    ['saveCookies', 'Legacy Cookie storage is disabled.'],
    ['getCookiesDetail', 'Legacy Cookie detail access is disabled.'],
    ['deleteCookies', 'Legacy Cookie deletion is disabled.'],
    ['batchDeleteCookies', 'Legacy Cookie batch deletion is disabled.'],
  ]) {
    const method = extractPhpMethod(cookieEndpoints, methodName);
    check(
      `${methodName} cannot access legacy plaintext Cookie storage`,
      method.includes(requiredText) && !/getConfigList|setConfigList|SystemConfig::(?:getValue|setValue)|online_data_cookies_/i.test(method),
      `CookieEndpointConcern::${methodName}`
    );
  }
  const listCookies = extractPhpMethod(cookieEndpoints, 'getCookiesList');
  check(
    'getCookiesList returns no legacy plaintext-backed records',
    /return\s+\$this->success\(\[\]\)\s*;/.test(listCookies)
      && !/getConfigList|SystemConfig::getValue|online_data_cookies_/i.test(listCookies),
    'CookieEndpointConcern::getCookiesList'
  );
  const receiveCookies = extractPhpMethod(cookieEndpoints, 'receiveCookies');
  const safeAuditText = extractPhpMethod(cookieEndpoints, 'safePublicEndpointText');
  const decodeAuditExtra = extractPhpMethod(cookieEndpoints, 'decodePublicEndpointFailureExtra');
  check(
    'disabled public Cookie receiver records presence flags instead of request-controlled values',
    receiveCookies.includes("'source_present'")
      && receiveCookies.includes("'name_present'")
      && !receiveCookies.includes("'source' => (string)$this->request->post")
      && !receiveCookies.includes("'name' => (string)$this->request->post"),
    'CookieEndpointConcern::receiveCookies audit payload'
  );
  check(
    'public endpoint audit values redact whole credential headers, bearer values and cookie-like pairs',
    safeAuditText.includes('proxy-authorization')
      && safeAuditText.includes('Bearer ****')
      && safeAuditText.includes('(?:session|token|auth|cookie|sid)')
      && decodeAuditExtra.includes('sanitizePublicEndpointExtra($decoded)'),
    'safePublicEndpointText + decodePublicEndpointFailureExtra'
  );
}
if (exists('app/controller/concern/OnlineDataRequestConcern.php')) {
  const onlineDataRequests = read('app/controller/concern/OnlineDataRequestConcern.php');
  const autoCapture = extractPhpMethod(onlineDataRequests, 'autoCaptureCtripCookie');
  const bookmarkSave = extractPhpMethod(onlineDataRequests, 'saveCtripConfigByBookmark');
  const forbiddenLegacyBookmarkFlow = /request->header\(['"]cookie|php:\/\/input|saveCtripConfigPayload|\[['"](?:cookies|auth_data)['"]\]|Access-Control-Allow-Credentials/i;
  check(
    'legacy Ctrip auto-capture route cannot read browser Cookie headers',
    autoCapture.includes('410') && !forbiddenLegacyBookmarkFlow.test(autoCapture),
    'OnlineDataRequestConcern::autoCaptureCtripCookie'
  );
  check(
    'legacy Ctrip bookmark save route cannot ingest or persist Cookie payloads',
    bookmarkSave.includes('410')
      && bookmarkSave.includes('$this->checkPermission();')
      && bookmarkSave.includes("$this->checkActionPermission('can_fetch_online_data');")
      && !forbiddenLegacyBookmarkFlow.test(bookmarkSave),
    'OnlineDataRequestConcern::saveCtripConfigByBookmark'
  );
}
if (exists('app/controller/concern/CollectionReliabilityConcern.php')) {
  const collectionReliability = read('app/controller/concern/CollectionReliabilityConcern.php');
  const readCookieAlerts = extractPhpMethod(collectionReliability, 'getCookieAlerts');
  check(
    'historical OTA credential alerts are sanitized again on every read',
    readCookieAlerts.includes('sanitizeCookieAlertsForStorage($data)'),
    'CollectionReliabilityConcern::getCookieAlerts'
  );
}

const strictRuntimeFiles = [
  'app/command/AutoFetchOnlineData.php',
  'app/controller/concern/AutoFetchConcern.php',
  'scripts/auto_fetch_online_data.php',
];
const legacySecretStores = /\b(?:ctrip_config_list|meituan_config_list|online_data_cookies_(?:list|hotel_|[0-9]))\b|\bdata_config_(?:ctrip|meituan)[a-z0-9_-]*/i;
for (const relativePath of strictRuntimeFiles) {
  checkFile(relativePath);
  if (!exists(relativePath)) continue;
  check(
    `${relativePath} does not read a legacy secret-bearing config store`,
    !legacySecretStores.test(read(relativePath)),
    'legacy OTA config/cache key found in an execution source'
  );
}
if (exists('app/command/AutoFetchOnlineData.php')) {
  const scheduledAutoFetch = read('app/command/AutoFetchOnlineData.php');
  check(
    'scheduled auto-fetch is Profile-only and has no reusable Cookie fallback',
    scheduledAutoFetch.includes('scheduled_browser_profile_source_required')
      && scheduledAutoFetch.includes('Scheduled collection is Profile-only.')
      && !scheduledAutoFetch.includes('withPayloadForExecution(')
      && !scheduledAutoFetch.includes('sendHttpRequest('),
    'Profile-only scheduled collection boundary'
  );
}
if (exists('app/controller/concern/AutoFetchConcern.php')) {
  const autoFetch = read('app/controller/concern/AutoFetchConcern.php');
  const boundary = extractPhpMethod(autoFetch, 'withAutoFetchCredential');
  const profileCacheSanitizer = extractPhpMethod(autoFetch, 'sanitizeBrowserProfileSourcesForSharedCache');
  const listProfileSources = extractPhpMethod(autoFetch, 'listEnabledBrowserProfileDataSources');
  const prepareProfileLoginRequest = extractPhpMethod(autoFetch, 'preparePlatformProfileLoginRequest');
  const createProfileLoginTask = extractPhpMethod(autoFetch, 'createPlatformProfileLoginTask');
  const profileLoginCacheSanitizer = extractPhpMethod(autoFetch, 'sanitizePlatformProfileLoginCachePayload');
  check(
    'controller auto-fetch uses only a locator before the vault callback',
    boundary.includes("$body['config_id']")
      && boundary.includes("$body['system_hotel_id']")
      && boundary.includes('withOtaCredentialForExecution('),
    'withAutoFetchCredential locator boundary'
  );
  check(
    'auto-fetch shared cache uses a new metadata-only key instead of any prior raw cache entry',
    autoFetch.includes("'_config_list_metadata_v2'")
      && !autoFetch.includes("'_config_list_raw'"),
    'autoFetchLightConfigListCacheKey'
  );
  check(
    'browser Profile source cache strips secret_json and blocks credential material in config_json',
    profileCacheSanitizer.includes("unset($row['secret_json'])")
      && profileCacheSanitizer.includes('splitOtaConfigSecrets($config)')
      && profileCacheSanitizer.includes('splitOtaConfigSecrets($row)')
      && profileCacheSanitizer.includes("'status'] = 'migration_required'"),
    'sanitizeBrowserProfileSourcesForSharedCache'
  );
  check(
    'browser Profile source query selects only safe fields before writing the shared cache',
    listProfileSources.includes("field('id,tenant_id,name,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_sync_status,last_error')")
      && listProfileSources.includes('sanitizeBrowserProfileSourcesForSharedCache($rows)')
      && listProfileSources.includes('writeAutoFetchLightReadCache($cacheKey, $safeRows)')
      && !listProfileSources.includes("secret_json"),
    'listEnabledBrowserProfileDataSources'
  );
  check(
    'Profile login task input is an explicit metadata allowlist and rejects credential fields',
    prepareProfileLoginRequest.includes('splitOtaConfigSecrets($requestData)')
      && prepareProfileLoginRequest.includes('assertPlatformProfileLoginRequestMetadataSafe($prepared)')
      && !prepareProfileLoginRequest.includes('$prepared = $requestData')
      && createProfileLoginTask.includes('preparePlatformProfileLoginRequest(')
      && createProfileLoginTask.includes('chmod($inputPath, 0600)')
      && createProfileLoginTask.includes('unlink($inputPath)'),
    'prepare/create Platform Profile login task'
  );
  check(
    'Profile login task and status caches redact nested secret keys and values on write and read',
    profileLoginCacheSanitizer.includes('isOtaSecretConfigKey($key)')
      && profileLoginCacheSanitizer.includes('safePlatformProfileLoginCacheText($value)')
      && extractPhpMethod(autoFetch, 'cachePlatformProfileLoginTask').includes('sanitizePlatformProfileLoginCachePayload($task)')
      && extractPhpMethod(autoFetch, 'readPlatformProfileLoginTask').includes('sanitizePlatformProfileLoginCachePayload($task)')
      && extractPhpMethod(autoFetch, 'readPlatformProfileStatusCache').includes('sanitizePlatformProfileLoginCachePayload($status)'),
    'AutoFetchConcern Profile login cache boundary'
  );
}

if (exists('app/command/PlatformProfileLogin.php')) {
  const profileLogin = read('app/command/PlatformProfileLogin.php');
  const safeProfileConfig = extractPhpMethod(profileLogin, 'decodeSafeProfileSourceConfig');
  const assertSafeProfileConfig = extractPhpMethod(profileLogin, 'assertProfileSourceMetadataIsSafe');
  const commandCacheSanitizer = extractPhpMethod(profileLogin, 'sanitizeProfileLoginCachePayload');
  const profileSourceReads = [
    extractPhpMethod(profileLogin, 'loadProfileLoginDataSourceForSync'),
    extractPhpMethod(profileLogin, 'markDataSourceProfileLoginVerified'),
    extractPhpMethod(profileLogin, 'findBrowserProfileDataSourceId'),
  ].join('\n');
  check(
    'Profile login command rejects legacy credential fields before reading or rewriting config_json',
    safeProfileConfig.includes('JSON_THROW_ON_ERROR')
      && safeProfileConfig.includes('assertProfileSourceMetadataIsSafe($config)')
      && assertSafeProfileConfig.includes('isSensitiveProfileSourceMetadataKey($key)')
      && assertSafeProfileConfig.includes('credential migration is required'),
    'PlatformProfileLogin safe config decoder'
  );
  check(
    'Profile login database reads use explicit field lists and never select secret_json',
    profileLogin.includes("field('id,system_hotel_id,platform,ingestion_method,enabled,status')")
      && profileLogin.includes("field('id,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_error,last_sync_status')")
      && profileLogin.includes("field('id,config_json')")
      && !profileSourceReads.includes("->field('*')")
      && !profileSourceReads.includes('secret_json'),
    'PlatformProfileLogin platform_data_sources reads'
  );
  check(
    'Profile login command compacts collector status before persistent cache writes',
    commandCacheSanitizer.includes('isSensitiveProfileLoginCacheKey($key)')
      && commandCacheSanitizer.includes('safeProfileLoginStatusText($value)')
      && extractPhpMethod(profileLogin, 'writeTask').includes('sanitizeProfileLoginCachePayload(array_merge($current, $patch))')
      && extractPhpMethod(profileLogin, 'finishFailed').includes('compactProfileLoginAuthStatus($authStatus)')
      && extractPhpMethod(profileLogin, 'finishFailed').includes('compactProfileLoginCaptureGate($captureGate)'),
    'PlatformProfileLogin cache compaction'
  );
  const restrictProfileArtifacts = extractPhpMethod(profileLogin, 'restrictProfileLoginArtifactPermissions');
  check(
    'Profile login output and log artifacts are restricted before they are read or cached',
    extractPhpMethod(profileLogin, 'runLoginTask').includes('restrictProfileLoginArtifactPermissions([$outputPath, $logPath])')
      && restrictProfileArtifacts.includes('chmod($path, 0600)')
      && restrictProfileArtifacts.includes('unlink($path)'),
    'PlatformProfileLogin artifact permissions'
  );
}

if (exists('app/service/platform/CtripBrowserProfileDataSourceAdapter.php')) {
  const ctripProfileAdapter = read('app/service/platform/CtripBrowserProfileDataSourceAdapter.php');
  const buildFieldConfig = extractPhpMethod(ctripProfileAdapter, 'buildProfileFieldConfigPayload');
  const createFieldFile = extractPhpMethod(ctripProfileAdapter, 'createProfileFieldConfigFile');
  check(
    'Ctrip Profile field metadata is checked for credential-shaped values before reaching the collector',
    buildFieldConfig.includes('assertProfileFieldRuntimeMetadataSafe($field)')
      && ctripProfileAdapter.includes('private function assertProfileFieldRuntimeMetadataSafe(array $field): void')
      && ctripProfileAdapter.includes('field metadata contains credential material'),
    'CtripBrowserProfileDataSourceAdapter field metadata boundary'
  );
  check(
    'Ctrip Profile field config temporary files use owner-only permissions',
    createFieldFile.includes('file_put_contents($path, $json, LOCK_EX)')
      && createFieldFile.includes('chmod($path, 0600)')
      && createFieldFile.includes('unlink($path)'),
    'createProfileFieldConfigFile mode 0600'
  );
}
if (exists('app/controller/concern/PlatformProfileCaptureConcern.php')) {
  const profileCapture = read('app/controller/concern/PlatformProfileCaptureConcern.php');
  for (const methodName of [
    'prepareCtripCookieApiCaptureFiles',
    'prepareCtripEndpointEvidenceValidationFiles',
    'createCtripProfileFieldConfigFile',
  ]) {
    const method = extractPhpMethod(profileCapture, methodName);
    check(
      methodName + ' writes runtime input with owner-only permissions and deletes on permission failure',
      method.includes('LOCK_EX')
        && method.includes('chmod(')
        && method.includes('0600')
        && method.includes('unlink('),
      'PlatformProfileCaptureConcern::' + methodName
    );
  }
}
if (exists('app/controller/concern/CtripProfileConfigConcern.php')) {
  const ctripProfileConfig = read('app/controller/concern/CtripProfileConfigConcern.php');
  const normalizeField = extractPhpMethod(ctripProfileConfig, 'normalizeCtripProfileCaptureField');
  check(
    'Ctrip Profile field CRUD rejects credential-shaped runtime metadata at save time',
    normalizeField.includes('assertCtripProfileFieldRuntimeMetadataSafe($normalized)')
      && ctripProfileConfig.includes('字段元数据不得包含 Cookie、token 或 Authorization 等凭据内容'),
    'normalizeCtripProfileCaptureField metadata validation'
  );
}

const manualExecutionContracts = [
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchCtrip'],
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchMeituan'],
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchCtripTraffic'],
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchCtripAds'],
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchMeituanTraffic'],
  ['app/controller/concern/OnlineDataManualFetchConcern.php', 'fetchMeituanManualBusinessSection'],
  ['app/controller/concern/OnlineDataRequestConcern.php', 'fetchCtripCookieApiData'],
  ['app/controller/concern/OnlineDataRequestConcern.php', 'fetchCtripOverviewData'],
];
for (const [relativePath, methodName] of manualExecutionContracts) {
  if (!exists(relativePath)) {
    checkFile(relativePath);
    continue;
  }
  const method = extractPhpMethod(read(relativePath), methodName);
  check(
    `${methodName} enters the vault boundary before credential use`,
    method.includes('withOtaCredentialForExecution('),
    `${relativePath}::${methodName}`
  );
  check(
    `${methodName} does not parse a legacy generic credential value`,
    !legacySecretStores.test(method)
      && !/SystemConfig::getValue\s*\(/.test(method)
      && !/getConfigList\s*\(/.test(method),
    `${relativePath}::${methodName}`
  );
}
if (exists('app/service/PlatformDataSyncService.php')) {
  const platformSync = read('app/service/PlatformDataSyncService.php');
  const vaultFetch = extractPhpMethod(platformSync, 'fetchOtaSourceInsideVault');
  const profileFetch = extractPhpMethod(platformSync, 'fetchOtaBrowserProfileSource');
  const saveOtaSource = extractPhpMethod(platformSync, 'saveOtaDataSource');
  check(
    'OTA platform sync loads credentials only through the vault callback',
    vaultFetch.includes('withPayloadForExecution(')
      && !/decodeConfig\s*\([^)]*secret_json/.test(vaultFetch),
    'fetchOtaSourceInsideVault'
  );
  check(
    'browser Profile sync never decrypts or injects Vault credentials',
    profileFetch.includes("unset($executionSource['secret'], $executionSource['secret_json'])")
      && profileFetch.includes('sanitizeAdapterResultForCredentialBoundary(')
      && !profileFetch.includes('withPayloadForExecution(')
      && !profileFetch.includes('otaCredentialVault('),
    'fetchOtaBrowserProfileSource'
  );
  check(
    'browser Profile source persistence rejects reusable secrets and records credential-not-required metadata',
    saveOtaSource.includes('Browser Profile data source must not store reusable OTA credentials')
      && saveOtaSource.includes("'credential_usage' => 'not_required_for_browser_profile'")
      && saveOtaSource.includes("'credential_status' => 'not_required'")
      && saveOtaSource.includes("'profile_execution_policy' => 'profile_session_metadata_only_no_vault_decrypt'"),
    'saveOtaDataSource'
  );
}

const frontendFiles = [
  'public/index.html',
  'public/app-main.js',
  'resources/frontend/app-template.html',
  'public/auto-fetch-static.js',
  'public/ctrip-static.js',
  'public/meituan-static.js',
  'public/ota-diagnosis-static.js',
];
const frontendSource = frontendFiles.filter(exists).map(read).join('\n');
for (const relativePath of frontendFiles) checkFile(relativePath);
check(
  'frontend has no credential hydration helper',
  !/\bensure(?:Ctrip|Meituan)ConfigSecret\b/.test(frontendSource)
    && !/\bload(?:Ctrip|Meituan)ConfigDetail\b/.test(frontendSource),
  'ensure*ConfigSecret/load*ConfigDetail'
);
check(
  'frontend has no writable platform detail cache',
  !/(?:ctrip|meituan)ConfigDetail(?:Cache|LoadingPromises)\.set\s*\(/.test(frontendSource),
  'platform detail cache .set'
);
check(
  'frontend never hydrates a reusable secret from config/detail data',
  !/(?:cookies?|auth_data|authorization|spidertoken|mtgsig)\s*=\s*(?:config|detail)(?:\?|\.)/i.test(frontendSource),
  'secret field assignment from config/detail'
);
if (frontendFiles.some(exists)) {
  const index = frontendSource;
  const parseSaved = sourceSection(
    index,
    'const parseSavedOtaDataConfigResponse =',
    'const clearSavedOtaDataConfigCache ='
  );
  const readSaved = sourceSection(
    index,
    'const readSavedOtaDataConfigFromSystem =',
    'const readSavedOtaDataConfig ='
  );
  const sensitiveFields = sourceSection(
    index,
    'const dataConfigSensitiveFieldNames = new Set([',
    'const resolveDataConfigCredentialMetadata ='
  );
  check(
    'saved data-config responses are stripped before entering the cache',
    parseSaved.includes('stripDataConfigCredentialFields(')
      && readSaved.includes('const config = parseSavedOtaDataConfigResponse(')
      && readSaved.includes('savedOtaDataConfigCache.set(')
      && readSaved.includes('config: cloneSavedOtaDataConfig(config)'),
    'parse -> strip -> cache sanitized config'
  );
  check(
    'saved data-config persistence strips reusable credential fields',
    sensitiveFields.includes("'cookies'")
      && sensitiveFields.includes("'auth_data'")
      && sensitiveFields.includes("'authorization'")
      && sensitiveFields.includes("'headers'")
      && sensitiveFields.includes("'payload'")
      && index.includes('const buildDataConfigForSave = () => stripDataConfigCredentialFields('),
    'dataConfigSensitiveFieldNames + buildDataConfigForSave'
  );
  check(
    'frontend does not call legacy plaintext Cookie persistence endpoints',
    !/\/online-data\/(?:save-cookies|cookies-list|cookies-detail|delete-cookies|batch-delete-cookies)\b/.test(index),
    'legacy Cookie endpoint URL in public/index.html'
  );
  check(
    'frontend no longer ships a Cookie extraction console helper',
    !/document\.cookie|copyCookieScript|Cookie脚本已复制/.test(index),
    'legacy Cookie extraction helper in public/index.html'
  );
  check(
    'legacy Cookie UI routes users to the vault-backed platform source flow',
    index.includes('旧 Cookie 列表、明文详情和快速保存入口已停用。')
      && index.includes('<button @click="openPlatformSourcesTab"'),
    'disabled legacy Cookie panel -> openPlatformSourcesTab'
  );
}

if (exists('app/command/MigrateOtaCredentials.php') && exists('app/service/OtaCredentialMigrationService.php')) {
  const command = read('app/command/MigrateOtaCredentials.php');
  const migration = read('app/service/OtaCredentialMigrationService.php');
  const run = extractPhpMethod(migration, 'run');
  const safeSummary = extractPhpMethod(migration, 'safeSummary');
  const dryRunBranch = run.indexOf('if (!$execute)');
  const transaction = run.indexOf('Db::transaction(');
  const unsafeOutputField = /['"](?:cookies?|auth_data|authorization|authorization_header|token|auth_token|api_key|password|secret|secret_json|secret_payload|fingerprint|fingerprint_payload|encrypted_payload|ciphertext|config_id|key_id|payload_version)['"]\s*=>/i;

  check(
    'migration command is dry-run unless execute is explicitly present',
    command.includes("addOption('execute', null, Option::VALUE_NONE")
      && command.includes("$execute = (bool)$input->getOption('execute')")
      && dryRunBranch !== -1
      && transaction !== -1
      && dryRunBranch < transaction
      && run.includes("safeSummary('dry-run', $inventory, [], [])"),
    '--execute opt-in + dry-run branch before transaction'
  );
  check(
    'migration command never prints caught exception text',
    !/catch\s*\(\s*Throwable\s+\$\w+\s*\)/.test(command)
      && !/getMessage\s*\(/.test(command)
      && command.includes("'reason_code' => 'migration_failed'"),
    'safe failure reason code'
  );
  check(
    'migration summary exposes no secret-valued or raw locator fields',
    safeSummary !== '' && !unsafeOutputField.test(safeSummary),
    'safeSummary output allowlist'
  );
}

if (exists('tests/OtaCredentialMigrationServiceTest.php')) {
  const migrationTest = read('tests/OtaCredentialMigrationServiceTest.php');
  check(
    'migration tests prove dry-run is read-only and secret-free',
    migrationTest.includes('testDryRunClassifiesAllLegacySourcesWithoutWritesOrSecrets')
      && migrationTest.includes("self::assertSame('dry-run', $summary['mode'])")
      && migrationTest.includes('self::assertSame($before, $this->databaseSnapshot())')
      && migrationTest.includes('self::assertStringNotContainsString($secret, $encoded)'),
    'dry-run behavioral assertions'
  );
}
if (exists('tests/OtaCredentialResponseTest.php')) {
  const responseTest = read('tests/OtaCredentialResponseTest.php');
  check(
    'response tests reject ciphertext and internal envelope metadata',
    responseTest.includes("self::assertArrayNotHasKey('encrypted_payload'")
      && responseTest.includes("self::assertArrayNotHasKey('key_id'")
      && responseTest.includes("self::assertArrayNotHasKey('payload_version'"),
    'OtaCredentialResponseTest redaction assertions'
  );
}
if (exists('tests/OtaCredentialReadPathTest.php')) {
  const readPathTest = read('tests/OtaCredentialReadPathTest.php');
  check(
    'read-path tests guard normal runtime sources from legacy secret stores',
    readPathTest.includes("foreach (['ctrip_config_list', 'meituan_config_list', 'data_config_', 'online_data_cookies_'] as $legacySecretStore)")
      && readPathTest.includes("foreach (['ctrip_config_list', 'meituan_config_list', 'online_data_cookies_', 'secret_json', 'encrypted_payload'] as $forbidden)"),
    'OtaCredentialReadPathTest source contracts'
  );
}

if (failures.length > 0) {
  console.error(`OTA credential vault verification failed (${failures.length}/${passes.length + failures.length}):`);
  for (const failure of failures) console.error(`- ${failure}`);
  process.exit(1);
}

console.log(`OTA credential vault verification passed (${passes.length} contracts).`);
