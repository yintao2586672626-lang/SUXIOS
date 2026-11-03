import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import { compileFrontendTemplate, FRONTEND_TEMPLATE_MINIFY_OPTIONS } from './lib/frontend_template_build.mjs';

// Component artifact only. The integrating task owns loader registration and its version marker.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(root, 'public/components/system/hotel-learning-workbench.js');
const artifactPath = path.join(root, 'public/components/system/hotel-learning-workbench.min.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const startMarker = '        template: `';
const endMarker = '\n        `,';
const start = source.indexOf(startMarker);
const end = source.indexOf(endMarker, start + startMarker.length);
if (start < 0 || end < 0) throw new Error('酒店业务工作台模板边界缺失');
const compiled = compileFrontendTemplate(source.slice(start + startMarker.length, end));
const prepared = source.slice(0, start) + `        render: (function(Vue){${compiled}})(Vue),` + source.slice(end + endMarker.length);
const options = structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS);
options.compress.booleans_as_integers = false;
const result = await minify({ 'hotel-learning-workbench.js': prepared }, options);
if (!result.code) throw new Error('酒店业务工作台编译产物为空');
const artifact = result.code + '\n';
if (process.argv.includes('--verify')) {
    if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact) throw new Error('酒店业务工作台产物过期');
} else if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact) {
    fs.writeFileSync(artifactPath, artifact);
}
console.log(JSON.stringify({ component: 'HotelLearningWorkbench', hash: crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10), artifact_only: true, verified: process.argv.includes('--verify') }));
