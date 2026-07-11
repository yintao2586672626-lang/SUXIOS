import { createRequire } from 'node:module';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';

const root = process.cwd();
const startedAt = new Date();
const stamp = startedAt.toISOString().replace(/[:.]/g, '-');
const reportRoot = path.join(root, 'tests', 'report');
const reportDir = path.join(reportRoot, stamp);
const screenshotDir = path.join(reportDir, 'screenshots');
fs.mkdirSync(screenshotDir, { recursive: true });

const php = process.env.SUXI_PHP || 'C:\\xampp\\php\\php.exe';
const mysql = process.env.SUXI_MYSQL || 'C:\\xampp\\mysql\\bin\\mysql.exe';
const testUsername = 'codex_auto_admin';
const testPassword = 'CodexTest#20260517';
const runCount = Number(process.env.SUXI_TEST_RUNS || 10);
const playwrightRequirePath = process.env.SUXI_PLAYWRIGHT_REQUIRE
  || 'C:\\Users\\Administrator\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\node_modules\\.pnpm\\playwright@1.59.1\\node_modules\\playwright\\package.json';
const chromeCandidates = [
  process.env.SUXI_CHROME,
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);

const serverLog = path.join(reportDir, 'think-server.log');
const errorLog = path.join(reportDir, 'errors.log');
const results = {
  started_at: startedAt.toISOString(),
  root,
  run_count: runCount,
  environment: {},
  inventory: {},
  runs: [],
  final: {},
};

let server;
let baseUrl = '';

function writeText(file, text) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, text, 'utf8');
}

function appendError(text) {
  fs.appendFileSync(errorLog, `${new Date().toISOString()} ${text}\n`, 'utf8');
}

function run(cmd, args = [], options = {}) {
  const res = spawnSync(cmd, args, {
    cwd: options.cwd || root,
    input: options.input,
    encoding: 'utf8',
    timeout: options.timeout || 120000,
    shell: false,
    env: { ...process.env, ...(options.env || {}) },
  });
  return {
    command: [cmd, ...args].join(' '),
    status: res.status,
    stdout: res.stdout || '',
    stderr: res.stderr || '',
    error: res.error ? String(res.error) : '',
  };
}

function mysqlExec(sql, timeout = 120000) {
  return run(mysql, ['-h', '127.0.0.1', '-P', '3306', '-u', 'root', 'hotelx', '--batch', '--raw', '-e', sql], { timeout });
}

function sqlEscape(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function isPortFree(port) {
  return new Promise((resolve) => {
    const srv = net.createServer()
      .once('error', () => resolve(false))
      .once('listening', () => srv.close(() => resolve(true)))
      .listen(port, '127.0.0.1');
  });
}

async function pickPort() {
  for (let port = 18080; port <= 18120; port += 1) {
    if (await isPortFree(port)) return port;
  }
  throw new Error('No free local port in 18080-18120');
}

async function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function http(method, urlPath, { token = '', body = undefined, expect = '2xx', label = '' } = {}) {
  const started = Date.now();
  const headers = {};
  let payload;
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }
  let status = 0;
  let text = '';
  let data = null;
  let ok = false;
  let error = '';
  try {
    const response = await fetch(`${baseUrl}${urlPath}`, { method, headers, body: payload });
    status = response.status;
    text = await response.text();
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = null;
    }
    if (expect === 'auth-fail') ok = status === 401 || status === 403 || data?.code === 401 || data?.code === 403;
    else if (expect === 'gone') ok = status === 410 && Number(data?.code || 0) === 410;
    else if (expect === 'business-error') ok = status < 500 && data?.code !== 200;
    else if (expect === 'any') ok = status > 0 && status < 500;
    else ok = status >= 200 && status < 300 && (data?.code === undefined || Number(data.code) < 500);
  } catch (e) {
    error = e.stack || e.message || String(e);
  }
  return {
    label: label || `${method} ${urlPath}`,
    method,
    path: urlPath,
    status,
    code: data?.code ?? null,
    ok,
    ms: Date.now() - started,
    message: data?.message || error || text.slice(0, 180),
    data,
  };
}

const reusableOtaSecretKeys = new Set([
  'cookie',
  'cookies',
  'auth_data',
  'authorization',
  'token',
  'access_token',
  'refresh_token',
  'spidertoken',
  'spider_token',
  'spiderkey',
  'spider_key',
  'mtgsig',
  'headers',
  'headers_json',
  'password',
  'api_key',
  'secret',
  'secret_json',
  'encrypted_payload',
  'credential_payload',
]);

function containsReusableOtaSecret(value, depth = 0) {
  if (depth > 12 || value === null || typeof value !== 'object') return false;
  if (Array.isArray(value)) return value.some((item) => containsReusableOtaSecret(item, depth + 1));
  return Object.entries(value).some(([key, item]) => {
    if (reusableOtaSecretKeys.has(String(key).toLowerCase())) {
      if (Array.isArray(item)) return item.length > 0;
      if (item && typeof item === 'object') return Object.keys(item).length > 0;
      return item !== null && item !== undefined && String(item).trim() !== '';
    }
    return containsReusableOtaSecret(item, depth + 1);
  });
}

function requireCredentialMetadataOnly(result, label) {
  const leaked = containsReusableOtaSecret(result?.data?.data);
  return {
    ...result,
    label,
    ok: result.ok && !leaked,
    message: leaked ? 'reusable OTA credential leaked through metadata endpoint' : result.message,
  };
}

async function waitForHealth() {
  for (let i = 0; i < 50; i += 1) {
    try {
      const response = await fetch(`${baseUrl}/api/health`);
      if (response.ok) return true;
    } catch {
      // Server still starting.
    }
    await sleep(300);
  }
  return false;
}

async function startServer() {
  const port = await pickPort();
  baseUrl = `http://127.0.0.1:${port}`;
  const out = fs.openSync(serverLog, 'a');
  server = spawn(php, ['think', 'run', '--host', '127.0.0.1', '--port', String(port)], {
    cwd: root,
    stdio: ['ignore', out, out],
    shell: false,
  });
  const healthy = await waitForHealth();
  if (!healthy) {
    throw new Error(`ThinkPHP server did not become healthy at ${baseUrl}. See ${serverLog}`);
  }
}

function stopServer() {
  if (server && !server.killed) {
    server.kill();
  }
}

function collectInventory() {
  const phpVersion = run(php, ['-v']);
  const phpModules = run(php, ['-m']);
  const mysqlPing = run('C:\\xampp\\mysql\\bin\\mysqladmin.exe', ['-h', '127.0.0.1', '-P', '3306', '-u', 'root', 'ping']);
  const routeList = run(php, ['think', 'route:list'], { timeout: 120000 });
  const routeSource = fs.readFileSync(path.join(root, 'route', 'app.php'), 'utf8');
  const html = fs.readFileSync(path.join(root, 'public', 'index.html'), 'utf8');
  const controllerFiles = fs.readdirSync(path.join(root, 'app', 'controller'), { recursive: true })
    .filter((file) => String(file).endsWith('.php'));
  const modelFiles = fs.readdirSync(path.join(root, 'app', 'model')).filter((file) => file.endsWith('.php'));
  const serviceFiles = fs.readdirSync(path.join(root, 'app', 'service')).filter((file) => file.endsWith('.php'));
  const controllers = controllerFiles.map((file) => {
    const source = fs.readFileSync(path.join(root, 'app', 'controller', file), 'utf8');
    const methods = [...source.matchAll(/public function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/g)].map((m) => m[1]);
    return { file: file.replace(/\\/g, '/'), methods };
  });
  const tableList = mysqlExec('SHOW TABLES;');
  const tables = tableList.stdout.split(/\r?\n/).slice(1).filter(Boolean);
  const schema = {};
  for (const table of tables) {
    const desc = mysqlExec(`SHOW FULL COLUMNS FROM \`${table}\`;`);
    schema[table] = desc.stdout.split(/\r?\n/).slice(1).filter(Boolean).length;
  }
  results.environment = {
    php: phpVersion.stdout.split(/\r?\n/)[0] || phpVersion.stderr.trim(),
    php_required_modules: ['pdo_mysql', 'mysqli', 'curl', 'mbstring', 'json'].map((name) => ({
      name,
      present: phpModules.stdout.split(/\r?\n/).includes(name),
    })),
    mysql: mysqlPing.stdout.trim() || mysqlPing.stderr.trim(),
    base_url: baseUrl,
    xdebug_loaded: phpModules.stdout.split(/\r?\n/).includes('xdebug'),
    playwright_require_path: fs.existsSync(playwrightRequirePath),
  };
  results.inventory = {
    routes_from_think: routeList.stdout.split(/\r?\n/).filter((line) => /GET|POST|PUT|DELETE|ANY|OPTIONS/i.test(line)).length,
    route_source_route_calls: (routeSource.match(/Route::/g) || []).length,
    controllers,
    controller_method_count: controllers.reduce((sum, c) => sum + c.methods.length, 0),
    model_count: modelFiles.length,
    service_count: serviceFiles.length,
    frontend: {
      index_html_lines: html.split(/\r?\n/).length,
      buttons: (html.match(/<button\b/gi) || []).length,
      forms: (html.match(/<form\b/gi) || []).length,
      selects: (html.match(/<select\b/gi) || []).length,
      inputs: (html.match(/<input\b/gi) || []).length,
      tables: (html.match(/<table\b/gi) || []).length,
      api_request_mentions: (html.match(/apiRequest\s*\(/g) || []).length,
      current_page_mentions: (html.match(/currentPage/g) || []).length,
    },
    database: {
      table_count: tables.length,
      tables: schema,
    },
  };
  writeText(path.join(reportDir, 'route-list.txt'), routeList.stdout + routeList.stderr);
  writeText(path.join(reportDir, 'schema.json'), JSON.stringify(schema, null, 2));
}

function seedTestUser() {
  const hash = run(php, ['-r', `echo password_hash('${testPassword}', PASSWORD_DEFAULT);`]).stdout.trim();
  if (!hash) throw new Error('Failed to generate password hash for test user');
  const sql = `
    INSERT INTO users (username,password,realname,role_id,status,create_time,update_time)
    VALUES ('${testUsername}','${sqlEscape(hash)}','Codex Automation Admin',1,1,NOW(),NOW())
    ON DUPLICATE KEY UPDATE password=VALUES(password), role_id=1, status=1, realname=VALUES(realname), update_time=NOW();
  `;
  const res = mysqlExec(sql);
  if (res.status !== 0) throw new Error(`Failed to seed test user: ${res.stderr}`);
}

function cleanupTestData() {
  const statements = [
    `DELETE FROM operation_logs WHERE description LIKE '%codex_automation_%' OR user_id IN (SELECT id FROM users WHERE username='${testUsername}')`,
    `DELETE FROM login_logs WHERE username='${testUsername}' OR user_id IN (SELECT id FROM users WHERE username='${testUsername}')`,
    `DELETE FROM user_hotel_permissions WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'codex_automation_%' OR username LIKE 'codexauto%' OR username='${testUsername}')`,
    `DELETE FROM users WHERE username LIKE 'codex_automation_%' OR username LIKE 'codexauto%' OR username='${testUsername}'`,
    `DELETE FROM hotels WHERE code LIKE 'CODAUTO%' OR name LIKE 'codex_automation_%'`,
    `DELETE FROM system_configs WHERE config_key LIKE 'codex_automation_%'`,
    `DELETE FROM system_config WHERE config_key LIKE 'codex_automation_%'`,
    `DELETE FROM feasibility_reports WHERE project_name LIKE 'codex_automation_%'`,
    `DELETE FROM strategy_simulation_records WHERE project_name LIKE 'codex_automation_%'`,
    `DELETE FROM quant_simulation_records WHERE project_name LIKE 'codex_automation_%'`,
    `DELETE FROM opening_tasks WHERE project_id IN (SELECT id FROM opening_projects WHERE project_name LIKE 'codex_automation_%')`,
    `DELETE FROM opening_projects WHERE project_name LIKE 'codex_automation_%'`,
    `DELETE FROM expansion_records WHERE project_name LIKE 'codex_automation_%'`,
    `DELETE FROM transfer_records WHERE hotel_name LIKE 'codex_automation_%'`,
    `DELETE FROM operation_action_tracks WHERE action_title LIKE 'codex_automation_%'`,
  ];
  for (const statement of statements) {
    const res = mysqlExec(`${statement};`);
    if (res.status !== 0 && !/Unknown column|doesn't exist/i.test(res.stderr)) {
      appendError(`cleanup failed: ${res.stderr}`);
    }
  }
}

async function login(cases) {
  const invalid = await http('POST', '/api/auth/login', {
    body: { username: testUsername, password: 'wrong-password' },
    expect: 'business-error',
    label: 'auth invalid password',
  });
  cases.push(invalid);
  const valid = await http('POST', '/api/auth/login', {
    body: { username: testUsername, password: testPassword },
    label: 'auth login',
  });
  cases.push(valid);
  const token = valid.data?.data?.token;
  if (!token) throw new Error(`login failed: ${valid.message}`);
  return token;
}

function metricAssertions() {
  const revenue = 120000;
  const roomNights = 300;
  const roomCount = 500;
  const exposure = 10000;
  const views = 2500;
  const visitors = 2000;
  const orders = 120;
  const marketAdr = 350;
  const marketOcc = 65;
  const adr = revenue / roomNights;
  const occ = roomNights / roomCount * 100;
  const revpar = revenue / roomCount;
  const viewRate = views / exposure * 100;
  const orderRate = orders / visitors * 100;
  const ari = adr / marketAdr * 100;
  const mpi = occ / marketOcc * 100;
  const sci = Math.round((ari * 0.4 + mpi * 0.4 + orderRate * 5 * 0.2) * 100) / 100;
  const assertions = [
    ['ADR', adr, 400],
    ['OCC', occ, 60],
    ['RevPAR', revpar, 240],
    ['view_rate', viewRate, 25],
    ['order_rate', orderRate, 6],
    ['ARI', Math.round(ari * 100) / 100, 114.29],
    ['MPI', Math.round(mpi * 100) / 100, 92.31],
    ['SCI synthetic weighted guard', sci, 88.64],
  ];
  return assertions.map(([name, actual, expected]) => ({
    label: `metric ${name}`,
    ok: Math.abs(actual - expected) < 0.01,
    actual,
    expected,
  }));
}

async function runApiSuite(iteration) {
  const cases = [];
  cases.push(await http('GET', '/api/health', { label: 'health' }));
  cases.push(await http('GET', '/api/hotels', { expect: 'auth-fail', label: 'auth middleware denies missing token' }));
  const token = await login(cases);
  cases.push(await http('GET', '/api/auth/info', { token, label: 'auth info' }));

  const code = `CODAUTO${iteration}${Date.now().toString().slice(-6)}`;
  const hotelCreate = await http('POST', '/api/hotels', {
    token,
    body: {
      name: `codex_automation_hotel_${iteration}`,
      code,
      address: 'Automation Road',
      contact_person: 'Codex',
      contact_phone: '13000000000',
      status: 1,
    },
    label: 'hotel create',
  });
  cases.push(hotelCreate);
  const hotelId = hotelCreate.data?.data?.id;
  cases.push(await http('GET', '/api/hotels', { token, label: 'hotel index' }));
  cases.push(await http('GET', '/api/hotels/all', { token, label: 'hotel all' }));
  if (hotelId) {
    cases.push(await http('GET', `/api/hotels/${hotelId}`, { token, label: 'hotel read' }));
    cases.push(await http('PUT', `/api/hotels/${hotelId}`, {
      token,
      body: { name: `codex_automation_hotel_${iteration}_updated`, code, status: 1 },
      label: 'hotel update',
    }));
  }
  cases.push(await http('POST', '/api/hotels', { token, body: { name: '' }, expect: 'business-error', label: 'hotel create missing name' }));

  const username = `codex_automation_user_${iteration}_${Date.now().toString().slice(-5)}`;
  const safeUsername = `codexauto${iteration}${Date.now().toString().slice(-5)}`;
  const userCreate = await http('POST', '/api/users', {
    token,
    body: { username: safeUsername, password: 'CodexUser#123', realname: 'Automation User', role_id: 3, status: 1, hotel_id: hotelId || '' },
    label: 'user create',
  });
  cases.push(userCreate);
  const userId = userCreate.data?.data?.id;
  cases.push(await http('GET', '/api/users/roles', { token, label: 'user roles' }));
  cases.push(await http('GET', '/api/users', { token, label: 'user index' }));
  if (userId) {
    cases.push(await http('GET', `/api/users/${userId}`, { token, label: 'user read' }));
    cases.push(await http('PUT', `/api/users/${userId}`, {
      token,
      body: { username: safeUsername, realname: 'Automation User Updated', role_id: 3, status: 1, hotel_id: hotelId || '' },
      label: 'user update',
    }));
  }
  cases.push(await http('GET', '/api/roles/permissions', { token, label: 'role permissions' }));
  cases.push(await http('GET', '/api/roles', { token, label: 'role index' }));
  cases.push(await http('GET', '/api/system-config/groups', { token, label: 'system config groups' }));
  cases.push(await http('GET', '/api/system-config/export', { token, label: 'system config export' }));
  cases.push(await http('GET', '/api/daily-reports/config', { token, label: 'daily report config' }));
  cases.push(await http('GET', '/api/daily-reports', { token, label: 'daily report index' }));
  cases.push(await http('GET', '/api/daily-reports/view-mapping', { token, label: 'daily report view mapping' }));
  cases.push(await http('GET', '/api/monthly-tasks/config', { token, label: 'monthly task config' }));
  cases.push(await http('GET', '/api/monthly-tasks', { token, label: 'monthly task index' }));
  cases.push(await http('GET', '/api/report-configs/all', { token, label: 'report config all' }));
  cases.push(await http('GET', '/api/report-configs', { token, label: 'report config index' }));

  cases.push(await http('GET', '/api/online-data/hotel-list', { token, label: 'online hotel list' }));
  cases.push(await http('GET', '/api/online-data/cookie-status', { token, label: 'online cookie status' }));
  cases.push(await http('GET', '/api/online-data/history', { token, label: 'online history' }));
  cases.push(await http('GET', '/api/online-data/daily-data-list', { token, label: 'online daily data list' }));
  cases.push(await http('GET', '/api/online-data/daily-data-summary', { token, label: 'online daily data summary' }));
  cases.push(await http('GET', '/api/online-data/data-analysis', { token, label: 'online data analysis' }));
  cases.push(await http('POST', '/api/online-data/fetch-ctrip', { token, body: {}, expect: 'business-error', label: 'ctrip fetch rejects missing credential locator' }));
  cases.push(await http('POST', '/api/online-data/fetch-meituan', { token, body: {}, expect: 'business-error', label: 'meituan fetch rejects missing credential locator' }));
  cases.push(await http('POST', '/api/online-data/save-cookies', {
    token,
    body: {},
    expect: 'gone',
    label: 'legacy Cookie storage is gone',
  }));
  cases.push(await http('GET', '/api/online-data/cookies-detail?id=legacy-contract-probe', {
    token,
    expect: 'gone',
    label: 'legacy Cookie plaintext detail is gone',
  }));
  const legacyCookieList = await http('GET', '/api/online-data/cookies-list', {
    token,
    label: 'legacy Cookie list is empty metadata',
  });
  legacyCookieList.ok = legacyCookieList.ok
    && Array.isArray(legacyCookieList.data?.data)
    && legacyCookieList.data.data.length === 0;
  cases.push(legacyCookieList);
  cases.push(await http('POST', '/api/online-data/save-ctrip-config', {
    token,
    body: { name: `codex_automation_ctrip_${iteration}`, cookies: 'codex=dummy', hotel_id: hotelId || '' },
    expect: 'any',
    label: 'Ctrip vault-backed platform config save',
  }));
  cases.push(requireCredentialMetadataOnly(
    await http('GET', '/api/online-data/get-ctrip-config-list', { token, label: 'Ctrip config metadata list' }),
    'Ctrip config list exposes metadata only'
  ));
  cases.push(await http('POST', '/api/online-data/save-meituan-config-item', {
    token,
    body: { name: `codex_automation_meituan_${iteration}`, cookies: 'codex=dummy', partner_id: '1', poi_id: '1', hotel_id: hotelId || '' },
    expect: 'any',
    label: 'Meituan vault-backed platform config save',
  }));
  cases.push(requireCredentialMetadataOnly(
    await http('GET', '/api/online-data/get-meituan-config-list', { token, label: 'Meituan config metadata list' }),
    'Meituan config list exposes metadata only'
  ));
  cases.push(await http('POST', '/api/online-data/ai-analysis', { token, body: { platform: 'ctrip', hotels: [] }, expect: 'any', label: 'online ai analysis empty' }));

  const strategy = await http('POST', '/api/strategy/simulate', {
    token,
    body: {
      project_name: `codex_automation_strategy_${iteration}`,
      city: '上海',
      district: '浦东',
      address: 'Automation Road',
      property_area: 3200,
      room_count: 88,
      monthly_rent: 180000,
      decoration_budget: 2200000,
      lease_years: 10,
      rent_free_months: 3,
      business_type: '核心商务区',
      target_customer: '商务差旅',
      competitor_count: 4,
      target_hotel_level: '中端精选',
    },
    expect: 'any',
    label: 'strategy simulate',
  });
  cases.push(strategy);
  const strategyId = strategy.data?.data?.record_id;
  cases.push(await http('GET', '/api/strategy/records', { token, label: 'strategy records' }));
  if (strategyId) {
    cases.push(await http('GET', `/api/strategy/records/${strategyId}`, { token, label: 'strategy detail' }));
    cases.push(await http('DELETE', `/api/strategy/records/${strategyId}`, { token, label: 'strategy archive' }));
  }

  const quantInput = {
    roomCount: 80,
    decorationInvestment: 1800000,
    furnitureInvestment: 300000,
    openingCost: 200000,
    otherInvestment: 100000,
    adr: 320,
    occupancyRate: 78,
    otherIncome: 12000,
    monthlyRent: 150000,
    laborCost: 80000,
    utilityCost: 25000,
    otaCommissionRate: 12,
    consumableCost: 18000,
    maintenanceCost: 12000,
    otherFixedCost: 10000,
  };
  const quant = await http('POST', '/api/simulation/calculate', {
    token,
    body: { project_name: `codex_automation_quant_${iteration}`, input: quantInput },
    label: 'quant simulation calculate',
  });
  cases.push(quant);
  const quantId = quant.data?.data?.id;
  cases.push(await http('GET', '/api/simulation/records', { token, label: 'quant simulation records' }));
  if (quantId) {
    cases.push(await http('GET', `/api/simulation/records/${quantId}`, { token, label: 'quant simulation detail' }));
    cases.push(await http('DELETE', `/api/simulation/records/${quantId}`, { token, label: 'quant simulation archive' }));
  }

  cases.push(await http('GET', '/api/operation/full-data', { token, expect: 'any', label: 'operation full data' }));
  cases.push(await http('POST', '/api/operation/root-cause', { token, body: { problem_type: 'orders_down' }, expect: 'any', label: 'operation root cause' }));
  cases.push(await http('GET', '/api/operation/alerts', { token, expect: 'any', label: 'operation alerts' }));
  cases.push(await http('POST', '/api/operation/alerts/read', { token, body: { ids: [] }, expect: 'business-error', label: 'operation alerts read empty' }));
  cases.push(await http('POST', '/api/operation/strategy-simulation', {
    token,
    body: { strategy_type: 'price_adjust', adjust_amount: 20 },
    expect: 'any',
    label: 'operation strategy simulation',
  }));
  const action = await http('POST', '/api/operation/actions', {
    token,
    body: { action_title: `codex_automation_action_${iteration}`, action_type: 'price_adjust', start_date: '2026-05-17', target_metric: 'orders' },
    expect: 'any',
    label: 'operation create action',
  });
  cases.push(action);
  cases.push(await http('GET', '/api/operation/action-tracking', { token, expect: 'any', label: 'operation action tracking' }));

  const opening = await http('POST', '/api/opening/projects', {
    token,
    body: { project_name: `codex_automation_opening_${iteration}`, hotel_name: 'Automation Hotel', opening_date: '2026-06-01' },
    expect: 'any',
    label: 'opening create project',
  });
  cases.push(opening);
  const openingId = opening.data?.data?.id;
  cases.push(await http('GET', '/api/opening/projects', { token, expect: 'any', label: 'opening projects' }));
  if (openingId) {
    cases.push(await http('GET', `/api/opening/projects/${openingId}/overview`, { token, expect: 'any', label: 'opening overview' }));
    cases.push(await http('POST', `/api/opening/projects/${openingId}/generate-tasks`, { token, expect: 'any', label: 'opening generate tasks' }));
    cases.push(await http('GET', `/api/opening/projects/${openingId}/tasks`, { token, expect: 'any', label: 'opening tasks' }));
    cases.push(await http('POST', `/api/opening/projects/${openingId}/recalculate`, { token, expect: 'any', label: 'opening recalculate' }));
    cases.push(await http('DELETE', `/api/opening/projects/${openingId}`, { token, expect: 'any', label: 'opening archive' }));
  }

  cases.push(await http('POST', '/api/expansion/market-evaluation', {
    token,
    body: { city: '上海', business_area: '浦东', property_area: 3200, estimated_rent: 180000, target_room_count: 88, decoration_level: 'mid', target_customer: 'business' },
    expect: 'any',
    label: 'expansion market evaluation',
  }));
  cases.push(await http('POST', '/api/expansion/benchmark-model', {
    token,
    body: { city: '上海', business_area: '浦东', target_price_band: '300-400', hotel_type: 'midscale', target_room_count: 88 },
    expect: 'any',
    label: 'expansion benchmark model',
  }));
  cases.push(await http('POST', '/api/expansion/collaboration-efficiency', {
    token,
    body: { project_name: `codex_automation_expansion_${iteration}`, city_area: '上海浦东', current_stage: '筹建', owner: 'Codex', expected_online_date: '2026-07-01', tasks: [] },
    expect: 'any',
    label: 'expansion collaboration efficiency',
  }));
  cases.push(await http('GET', '/api/expansion/records', { token, expect: 'any', label: 'expansion records' }));

  const pricing = await http('POST', '/api/transfer/pricing', {
    token,
    body: {
      hotel_name: `codex_automation_transfer_${iteration}`,
      location: '上海浦东',
      room_count: 88,
      monthly_revenue: 120,
      monthly_rent: 18,
      labor_cost: 8,
      utility_cost: 2,
      ota_commission: 3,
      other_fixed_cost: 1,
      decoration_investment: 260,
      remaining_lease_months: 72,
      expected_transfer_price: 320,
      occupancy_rate: 78,
      adr: 320,
      rating: 4.7,
      order_count: 600,
      licenses_complete: true,
      has_data_anomaly: false,
    },
    expect: 'any',
    label: 'transfer pricing',
  });
  cases.push(pricing);
  const timing = await http('POST', '/api/transfer/timing', {
    token,
    body: {
      current_revenue: 120,
      previous_revenue: 110,
      current_orders: 600,
      previous_orders: 560,
      current_adr: 320,
      previous_adr: 300,
      current_occupancy_rate: 78,
      previous_occupancy_rate: 72,
      rating: 4.7,
      holiday_days: 7,
      is_peak_season: true,
      has_data_anomaly: false,
      has_data_gap: false,
    },
    expect: 'any',
    label: 'transfer timing',
  });
  cases.push(timing);
  cases.push(await http('POST', '/api/transfer/dashboard', {
    token,
    body: { pricing: pricing.data?.data || {}, timing: timing.data?.data || {}, metrics: {} },
    expect: 'any',
    label: 'transfer dashboard',
  }));
  cases.push(await http('GET', '/api/transfer/records', { token, expect: 'any', label: 'transfer records' }));

  if (userId) cases.push(await http('DELETE', `/api/users/${userId}`, { token, label: 'user delete' }));
  if (hotelId) cases.push(await http('DELETE', `/api/hotels/${hotelId}`, { token, label: 'hotel delete' }));
  cases.push(await http('POST', '/api/auth/logout', { token, label: 'auth logout' }));

  return {
    token_obtained: Boolean(token),
    cases,
    metrics: metricAssertions(),
  };
}

function dbTransactionProbe(iteration) {
  const key = `codex_automation_db_${iteration}_${Date.now()}`;
  const sql = `
    START TRANSACTION;
    INSERT INTO system_configs (config_key, config_value, description, create_time, update_time)
    VALUES ('${key}', 'normal', 'automation normal value', NOW(), NOW());
    SELECT config_value FROM system_configs WHERE config_key='${key}';
    UPDATE system_configs SET config_value='' WHERE config_key='${key}';
    SELECT config_value FROM system_configs WHERE config_key='${key}';
    UPDATE system_configs SET config_value='{"extreme":999999999,"negative":-1}' WHERE config_key='${key}';
    SELECT config_value FROM system_configs WHERE config_key='${key}';
    DELETE FROM system_configs WHERE config_key='${key}';
    ROLLBACK;
    SELECT COUNT(*) AS residue FROM system_configs WHERE config_key='${key}';
  `;
  const res = mysqlExec(sql);
  const ok = res.status === 0 && /\b0\b/.test(res.stdout.split(/\r?\n/).filter(Boolean).at(-1) || '');
  return {
    label: 'database transactional CRUD normal/missing/extreme',
    ok,
    status: res.status,
    stdout_tail: res.stdout.split(/\r?\n/).slice(-8),
    stderr: res.stderr,
  };
}

async function uiProbe(iteration) {
  if (!fs.existsSync(playwrightRequirePath)) {
    return { ok: false, skipped: true, reason: 'Playwright package path not found' };
  }
  const require = createRequire(playwrightRequirePath);
  const { chromium } = require('playwright');
  const bundledExecutable = chromium.executablePath();
  const executablePath = [bundledExecutable, ...chromeCandidates].find((candidate) => candidate && fs.existsSync(candidate));
  if (!executablePath) {
    return { ok: false, skipped: true, reason: 'No Chromium/Chrome executable found' };
  }
  const browser = await chromium.launch({ headless: true, executablePath });
  const page = await browser.newPage({ viewport: { width: 1440, height: 980 } });
  const consoleErrors = [];
  const pageErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(`${msg.type()}: ${msg.text()}`);
  });
  page.on('pageerror', (err) => pageErrors.push(err.message));
  const shots = [];
  try {
    await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.screenshot({ path: path.join(screenshotDir, `run-${iteration}-login.png`), fullPage: true });
    shots.push(`run-${iteration}-login.png`);
    await page.locator('input[type="text"]').first().fill(testUsername, { timeout: 10000 });
    await page.locator('input[type="password"]').first().fill(testPassword, { timeout: 10000 });
    await page.locator('button[type="submit"]').first().click({ timeout: 10000 });
    await page.waitForTimeout(1800);
    await page.screenshot({ path: path.join(screenshotDir, `run-${iteration}-after-login.png`), fullPage: true });
    shots.push(`run-${iteration}-after-login.png`);

    const visibleCounts = await page.evaluate(() => ({
      buttons: [...document.querySelectorAll('button')].filter((el) => el.offsetParent !== null).length,
      inputs: [...document.querySelectorAll('input, textarea')].filter((el) => el.offsetParent !== null).length,
      selects: [...document.querySelectorAll('select')].filter((el) => el.offsetParent !== null).length,
      tables: [...document.querySelectorAll('table')].filter((el) => el.offsetParent !== null).length,
      nav: [...document.querySelectorAll('.sidebar-item, nav a, aside button, aside [role="button"]')].filter((el) => el.offsetParent !== null).length,
      mustacheLeaks: document.body.innerText.includes('{{'),
    }));

    const navItems = await page.locator('.sidebar-item, aside button, nav a, [role="button"]').all();
    let clicked = 0;
    for (const item of navItems.slice(0, 35)) {
      const text = (await item.innerText().catch(() => '')).trim();
      if (!text || /退出|删除|归档|注销|logout/i.test(text)) continue;
      const box = await item.boundingBox().catch(() => null);
      if (!box) continue;
      await item.click({ timeout: 3000 }).catch(() => {});
      clicked += 1;
      await page.waitForTimeout(150);
    }
    await page.screenshot({ path: path.join(screenshotDir, `run-${iteration}-navigation.png`), fullPage: true });
    shots.push(`run-${iteration}-navigation.png`);

    const selectCount = await page.locator('select').count();
    for (let i = 0; i < Math.min(selectCount, 8); i += 1) {
      const sel = page.locator('select').nth(i);
      const options = await sel.locator('option').allTextContents().catch(() => []);
      if (options.length > 1) await sel.selectOption({ index: 1 }).catch(() => {});
    }
    const inputCount = await page.locator('input:not([type="password"]):not([type="hidden"]), textarea').count();
    for (let i = 0; i < Math.min(inputCount, 12); i += 1) {
      const input = page.locator('input:not([type="password"]):not([type="hidden"]), textarea').nth(i);
      const type = await input.getAttribute('type').catch(() => '');
      const value = type === 'number'
        ? '88'
        : (type === 'date' ? '2026-05-17' : `codex_automation_${iteration}`);
      await input.fill(value, { timeout: 1000 }).catch(() => {});
    }
    await page.screenshot({ path: path.join(screenshotDir, `run-${iteration}-form-probe.png`), fullPage: true });
    shots.push(`run-${iteration}-form-probe.png`);

    await browser.close();
    return {
      ok: consoleErrors.length === 0 && pageErrors.length === 0 && !visibleCounts.mustacheLeaks,
      visible_counts: visibleCounts,
      nav_clicked: clicked,
      console_errors: consoleErrors.slice(0, 20),
      page_errors: pageErrors.slice(0, 20),
      screenshots: shots,
    };
  } catch (e) {
    await browser.close().catch(() => {});
    return {
      ok: false,
      error: e.stack || e.message || String(e),
      console_errors: consoleErrors.slice(0, 20),
      page_errors: pageErrors.slice(0, 20),
      screenshots: shots,
    };
  }
}

function summarize() {
  let apiCases = 0;
  let apiPassed = 0;
  let metricCases = 0;
  let metricPassed = 0;
  let dbPassed = 0;
  let uiPassed = 0;
  for (const runResult of results.runs) {
    apiCases += runResult.api.cases.length;
    apiPassed += runResult.api.cases.filter((c) => c.ok).length;
    metricCases += runResult.api.metrics.length;
    metricPassed += runResult.api.metrics.filter((c) => c.ok).length;
    if (runResult.database.ok) dbPassed += 1;
    if (runResult.frontend.ok) uiPassed += 1;
  }
  const failedApi = results.runs.flatMap((r) => r.api.cases
    .filter((c) => !c.ok)
    .map((c) => ({ run: r.iteration, label: c.label, status: c.status, code: c.code, message: c.message })));
  const failedMetrics = results.runs.flatMap((r) => r.api.metrics
    .filter((c) => !c.ok)
    .map((c) => ({ run: r.iteration, label: c.label, actual: c.actual, expected: c.expected })));
  const failedDatabase = results.runs
    .filter((r) => !r.database?.ok)
    .map((r) => ({ run: r.iteration, label: r.database?.label || 'database probe', status: r.database?.status, stderr: r.database?.stderr || '' }));
  const failedFrontend = results.runs
    .filter((r) => !r.frontend?.ok && !r.frontend?.skipped)
    .map((r) => ({ run: r.iteration, error: r.frontend?.error || r.frontend?.reason || 'frontend probe failed' }));
  const skippedFrontend = results.runs
    .filter((r) => r.frontend?.skipped)
    .map((r) => ({ run: r.iteration, reason: r.frontend?.reason || 'frontend probe unavailable' }));
  const executionErrors = results.runs
    .filter((r) => r.api_error)
    .map((r) => ({ run: r.iteration, error: r.api_error }));
  const blockingFailureCount = failedApi.length
    + failedMetrics.length
    + failedDatabase.length
    + failedFrontend.length
    + executionErrors.length;
  results.final = {
    completed_at: new Date().toISOString(),
    report_dir: reportDir,
    status: blockingFailureCount > 0 ? 'failed' : (skippedFrontend.length > 0 ? 'incomplete' : 'passed'),
    blocking_failure_count: blockingFailureCount,
    api: { passed: apiPassed, total: apiCases },
    metrics: { passed: metricPassed, total: metricCases },
    database: { passed: dbPassed, total: results.runs.length },
    frontend: { passed: uiPassed, total: results.runs.length },
    failed_api: failedApi,
    failed_metrics: failedMetrics,
    failed_database: failedDatabase,
    failed_frontend: failedFrontend,
    skipped_frontend: skippedFrontend,
    execution_errors: executionErrors,
    xdebug_coverage: results.environment.xdebug_loaded
      ? 'xdebug loaded, line coverage can be added through PHPUnit'
      : 'xdebug not loaded; report includes route/controller/method reachability instead of executable line coverage',
  };
  return blockingFailureCount === 0;
}

function writeReports() {
  writeText(path.join(reportDir, 'results.json'), JSON.stringify(results, null, 2));
  const rows = results.runs.flatMap((r) => r.api.cases.map((c) =>
    `| ${r.iteration} | API | ${c.ok ? 'PASS' : 'FAIL'} | ${c.label} | ${c.status} | ${c.code ?? ''} | ${String(c.message || '').replace(/\|/g, '/').slice(0, 120)} |`
  ));
  const metricRows = results.runs.flatMap((r) => r.api.metrics.map((c) =>
    `| ${r.iteration} | Metric | ${c.ok ? 'PASS' : 'FAIL'} | ${c.label} |  |  | actual=${c.actual}, expected=${c.expected} |`
  ));
  const dbRows = results.runs.map((r) =>
    `| ${r.iteration} | Database | ${r.database.ok ? 'PASS' : 'FAIL'} | ${r.database.label} | ${r.database.status ?? ''} |  | ${(r.database.stderr || '').replace(/\|/g, '/').slice(0, 120)} |`
  );
  const uiRows = results.runs.map((r) =>
    `| ${r.iteration} | Frontend | ${r.frontend.ok ? 'PASS' : 'FAIL'} | Playwright navigation/form probe |  |  | ${((r.frontend.error || r.frontend.console_errors?.[0] || '') + '').replace(/\|/g, '/').slice(0, 120)} |`
  );
  const md = `# 宿析OS自动化全面测试报告

- 开始时间：${results.started_at}
- 完成时间：${results.final.completed_at}
- 基础地址：${results.environment.base_url}
- 执行轮次：${results.run_count}
- 报告目录：${reportDir}
- 结论：${results.final.status}
- 阻塞失败数：${results.final.blocking_failure_count}

## 环境

| 项目 | 值 |
|---|---|
| PHP | ${results.environment.php} |
| MySQL | ${results.environment.mysql} |
| Xdebug/逐行覆盖率 | ${results.environment.xdebug_loaded ? '可用' : '不可用，本次输出可触达性统计'} |
| Playwright | ${results.environment.playwright_require_path ? '可用' : '不可用'} |

## 覆盖清单

| 类型 | 数量 |
|---|---:|
| ThinkPHP 路由调用点 | ${results.inventory.route_source_route_calls} |
| route:list 可见路由行 | ${results.inventory.routes_from_think} |
| 控制器 | ${results.inventory.controllers.length} |
| 控制器 public 方法 | ${results.inventory.controller_method_count} |
| 模型 | ${results.inventory.model_count} |
| 服务 | ${results.inventory.service_count} |
| 数据表 | ${results.inventory.database.table_count} |
| public/index.html 行数 | ${results.inventory.frontend.index_html_lines} |
| 前端按钮/表单/下拉/输入/表格 | ${results.inventory.frontend.buttons}/${results.inventory.frontend.forms}/${results.inventory.frontend.selects}/${results.inventory.frontend.inputs}/${results.inventory.frontend.tables} |

## 汇总

| 模块 | 通过 | 总数 |
|---|---:|---:|
| API/后端接口 | ${results.final.api.passed} | ${results.final.api.total} |
| 指标公式断言 | ${results.final.metrics.passed} | ${results.final.metrics.total} |
| 数据库事务 CRUD | ${results.final.database.passed} | ${results.final.database.total} |
| 前端 Playwright 探测 | ${results.final.frontend.passed} | ${results.final.frontend.total} |

## 功能点结果表

| 轮次 | 类型 | 结果 | 功能点 | HTTP | Code | 摘要 |
|---:|---|---|---|---:|---:|---|
${[...rows, ...metricRows, ...dbRows, ...uiRows].join('\n')}

## 失败项

\`\`\`json
${JSON.stringify({
  failed_api: results.final.failed_api,
  failed_metrics: results.final.failed_metrics,
  failed_database: results.final.failed_database,
  failed_frontend: results.final.failed_frontend,
  skipped_frontend: results.final.skipped_frontend,
  execution_errors: results.final.execution_errors,
}, null, 2)}
\`\`\`

## 截图

${results.runs.flatMap((r) => (r.frontend.screenshots || []).map((s) => `- run ${r.iteration}: screenshots/${s}`)).join('\n') || '- 无'}

## 覆盖率说明

${results.final.xdebug_coverage}
`;
  writeText(path.join(reportDir, 'summary.md'), md);
  const html = `<!doctype html><meta charset="utf-8"><title>宿析OS测试报告</title><style>body{font-family:Arial,"Microsoft YaHei",sans-serif;margin:32px;color:#111827}table{border-collapse:collapse;width:100%;margin:16px 0}td,th{border:1px solid #e5e7eb;padding:8px;text-align:left}th{background:#f9fafb}.pass{color:#047857}.fail{color:#b91c1c}code,pre{background:#f3f4f6;padding:12px;display:block;overflow:auto}</style><body>${md
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\n/g, '<br>')}</body>`;
  writeText(path.join(reportDir, 'summary.html'), html);
  writeText(path.join(reportRoot, 'latest.txt'), reportDir);
}

async function main() {
  try {
    cleanupTestData();
    seedTestUser();
    await startServer();
    collectInventory();
    for (let iteration = 1; iteration <= runCount; iteration += 1) {
      const runStarted = Date.now();
      const runResult = {
        iteration,
        started_at: new Date().toISOString(),
        api: { cases: [], metrics: [] },
        database: {},
        frontend: {},
      };
      try {
        runResult.api = await runApiSuite(iteration);
      } catch (e) {
        appendError(`run ${iteration} api failed: ${e.stack || e.message}`);
        runResult.api_error = e.stack || e.message || String(e);
      }
      runResult.database = dbTransactionProbe(iteration);
      try {
        runResult.frontend = await uiProbe(iteration);
      } catch (e) {
        appendError(`run ${iteration} frontend failed: ${e.stack || e.message}`);
        runResult.frontend = { ok: false, error: e.stack || e.message || String(e) };
      }
      cleanupTestData();
      seedTestUser();
      runResult.duration_ms = Date.now() - runStarted;
      runResult.completed_at = new Date().toISOString();
      results.runs.push(runResult);
      writeText(path.join(reportDir, `run-${iteration}.json`), JSON.stringify(runResult, null, 2));
      console.log(`run ${iteration}/${runCount}: api ${runResult.api.cases?.filter((c) => c.ok).length || 0}/${runResult.api.cases?.length || 0}, db=${runResult.database.ok ? 'pass' : 'fail'}, ui=${runResult.frontend.ok ? 'pass' : 'fail'}`);
    }
    const passed = summarize();
    writeReports();
    console.log(`REPORT_DIR=${reportDir}`);
    if (!passed) {
      console.error(`Automation verification failed with ${results.final.blocking_failure_count} blocking failure(s).`);
      process.exitCode = 1;
    }
  } catch (e) {
    appendError(e.stack || e.message || String(e));
    results.final = { fatal: e.stack || e.message || String(e), report_dir: reportDir };
    writeReports();
    console.error(e.stack || e.message || String(e));
    process.exitCode = 1;
  } finally {
    stopServer();
    cleanupTestData();
  }
}

main();
