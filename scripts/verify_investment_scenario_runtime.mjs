import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

// Read-only public-asset identity and unauthenticated route guard checks. No account/session material.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const base = new URL(process.argv[2] || 'http://127.0.0.1:8080/');
if (!['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname)) throw new Error('Loopback runtime only');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const report = { status: 'pending', base_url: base.origin, scope: 'public_asset_identity_and_anonymous_auth_guards', authenticated_account_verified: false, business_data_written: false, assets: [], routes: [] };
const errors = [];
for (const relative of ['index.html', 'components/system/business-closure-loader.js', 'components/system/investment-scenario.min.js', 'components/system/investment-payback.min.js']) {
    const response = await fetch(new URL(relative, base), { cache: 'no-store', signal: AbortSignal.timeout(10000) });
    const bytes = Buffer.from(await response.arrayBuffer());
    const local = fs.readFileSync(path.join(root, 'public', relative));
    const item = { path: relative, http_status: response.status, served_sha256: sha(bytes), local_sha256: sha(local), exact: response.ok && bytes.equals(local) };
    report.assets.push(item);
    if (!item.exact) errors.push(`Served asset differs: ${relative}`);
}
for (const [method, relative] of [['GET', 'api/investment-payback/scenario/reference-example'], ['GET', 'api/investment-payback/projects/1/scenario'], ['POST', 'api/investment-payback/projects/1/scenario'], ['POST', 'api/investment-payback/projects/1/scenario/preview']]) {
    const response = await fetch(new URL(relative, base), { method, headers: method === 'POST' ? {'Content-Type':'application/json'} : undefined, body: method === 'POST' ? JSON.stringify({scenario:{}}) : undefined, signal: AbortSignal.timeout(10000) });
    const payload = await response.json();
    const item = { path: relative, method, http_status: response.status, response_code: payload.code, denied_without_auth: response.status === 401 && Number(payload.code) === 401 };
    report.routes.push(item);
    if (!item.denied_without_auth) errors.push(`Anonymous guard differs: ${method} ${relative}`);
}
report.status = errors.length ? 'failed' : 'passed';
report.errors = errors;
const directory = path.resolve(root, process.argv[3] || 'output/investment-scenario-20261001');
if (!directory.startsWith(path.join(root, 'output') + path.sep)) throw new Error('Evidence directory must stay under workspace output');
const destination = path.join(directory, 'runtime-readback.json');
fs.mkdirSync(path.dirname(destination), {recursive:true});
fs.writeFileSync(destination, JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify({status:report.status,exact_assets:report.assets.filter(row=>row.exact).length,anonymous_401:report.routes.filter(row=>row.denied_without_auth).length,evidence:destination,errors}));
if (errors.length) process.exitCode = 1;
