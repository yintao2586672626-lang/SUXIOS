import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const assets = ['components/system/operating-economics-workbench.min.js', 'components/system/booking-monitoring-panel.js', 'components/system/app-main-components.js', 'components/system/app-main-components-loader.js'];
const rows = [];
for (const asset of assets) {
    const local = fs.readFileSync(path.join(root, 'public', asset));
    const response = await fetch('http://127.0.0.1:8080/' + asset, { signal: AbortSignal.timeout(5000) });
    const served = Buffer.from(await response.arrayBuffer());
    rows.push({ asset, http_status: response.status, local_sha256: hash(local), served_sha256: hash(served), exact_match: response.ok && local.equals(served) });
}
const loader = fs.readFileSync(path.join(root, 'public/components/system/app-main-components.js'), 'utf8');
const cacheIdentity = loader.includes('economics-h' + rows[0].local_sha256.slice(0, 10)) && loader.includes('booking-h' + rows[1].local_sha256.slice(0, 10));
const healthResponse = await fetch('http://127.0.0.1:8080/api/health', { signal: AbortSignal.timeout(5000) });
const health = await healthResponse.json();
const evidence = { checked_at: new Date().toISOString(), assets: rows, loader_cache_identity_verified: cacheIdentity,
    health: { http_status: healthResponse.status, status: health.status, database_schema: health.checks?.database_schema },
    boundaries: { authenticated_business_path_verified: false, production_deployed: false, real_ota_data_verified: false } };
const destination = path.join(root, 'output/validation/deep-optimization-20261002/served-assets.json');
fs.mkdirSync(path.dirname(destination), { recursive: true });
fs.writeFileSync(destination, JSON.stringify(evidence, null, 2) + '\n');
console.log(JSON.stringify({ matching_assets: rows.filter(row => row.exact_match).length, asset_count: rows.length, cache_identity_verified: cacheIdentity, health: evidence.health }));
if (rows.some(row => !row.exact_match) || !cacheIdentity || !healthResponse.ok || health.status !== 'ok') process.exitCode = 1;
