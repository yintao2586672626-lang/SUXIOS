import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import { acquireFrontendTemplateLock, writeFileAtomic } from './lib/frontend_template_lock.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const release = await acquireFrontendTemplateLock(root, { owner: 'build-autonomous-maintenance' });
try {
    const sourcePath = path.join(root, 'public/components/system/autonomous-maintenance-workbench.js');
    const source = fs.readFileSync(sourcePath, 'utf8');
    const result = await minify(source, { ecma: 2020, compress: true, mangle: true, format: { ascii_only: false } });
    if (!result.code) throw new Error('Maintenance component compilation failed.');
    if (fs.readFileSync(sourcePath, 'utf8') !== source) throw new Error('Maintenance source changed during build.');
    const artifact = result.code + '\n';
    const hash = crypto.createHash('sha256').update(artifact).digest('hex');
    const loaderPath = path.join(root, 'public/components/system/app-main-components-loader.js');
    const loader = fs.readFileSync(loaderPath, 'utf8');
    const pattern = /components\/system\/autonomous-maintenance-workbench\.min\.js\?v=maintenance-h[0-9a-f]{10}/g;
    if (!pattern.test(loader)) throw new Error('Maintenance lazy loader cache identity missing.');
    pattern.lastIndex = 0;
    const nextLoader = loader.replace(pattern, 'components/system/autonomous-maintenance-workbench.min.js?v=maintenance-h' + hash.slice(0, 10));
    const writeChanged = (file, content) => {
        if (fs.existsSync(file) && fs.readFileSync(file, 'utf8') === content) return;
        writeFileAtomic(file, content);
    };
    writeChanged(path.join(root, 'public/components/system/autonomous-maintenance-workbench.min.js'), artifact);
    writeChanged(loaderPath, nextLoader);
    console.log(JSON.stringify({ source: path.relative(root, sourcePath), artifact_sha256: hash, artifact_bytes: Buffer.byteLength(artifact) }));
} finally { release(); }
