import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const assets = [
    'components/system/operating-economics-workbench.min.js',
    'components/system/booking-monitoring-panel.js',
    'components/system/operating-finance-control-center.min.js',
    'components/system/investment-scenario.min.js',
    'components/system/business-closure-loader.js',
    'components/system/ai-daily-report-delivery.js',
    'components/system/business-closure-views.js',
    'components/system/app-main-components.js',
    'app-startup-helpers.min.js',
];
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const rows = [];
for (const asset of assets) {
    const bytes = fs.readFileSync(path.join(root, 'public', asset));
    try {
        const response = await fetch('http://127.0.0.1:8080/' + asset, {signal: AbortSignal.timeout(5000)});
        const served = Buffer.from(await response.arrayBuffer());
        rows.push({asset, http_status: response.status, local_sha256: digest(bytes), served_sha256: digest(served), exact_match: response.ok && bytes.equals(served)});
    } catch { rows.push({asset, exact_match: false, status: 'local_service_unavailable'}); }
}
let health;
try {
    const response = await fetch('http://127.0.0.1:8080/api/health', {signal: AbortSignal.timeout(5000)});
    const body = await response.json();
    const expectedOrigin = digest(fs.readFileSync(path.join(root, 'scripts/local_origin_server.mjs')));
    const servedOrigin = response.headers.get('X-SUXIOS-Origin-Code-SHA256');
    health = {http_status: response.status, healthy_http: response.ok, application: body.status,
        database_schema: body.checks?.database_schema, runtime_mode: body.runtime_mode,
        production_runtime_ready: body.production_runtime_ready, expected_origin_sha256: expectedOrigin,
        served_origin_sha256: servedOrigin, gateway_code_identity_verified: servedOrigin === expectedOrigin};
} catch { health = {healthy_http: false, status: 'local_service_unavailable'}; }
const evidence = {checked_at: new Date().toISOString(), origin: 'http://127.0.0.1:8080', assets: rows, health,
    boundaries: {authenticated_business_path_verified: false, gateway_code_identity_verified: health.gateway_code_identity_verified === true, production_deployed: false}};
const destination = path.join(root, 'output', 'validation', 'five-enhancements-20261002', 'served-assets.json');
fs.mkdirSync(path.dirname(destination), {recursive:true});
fs.writeFileSync(destination, JSON.stringify(evidence, null, 2) + '\n');
console.log(JSON.stringify({asset_count: rows.length, matching_assets: rows.filter(row => row.exact_match).length, health, evidence: destination}));
if (rows.some(row => !row.exact_match) || !health.healthy_http || !health.gateway_code_identity_verified) process.exitCode = 1;
