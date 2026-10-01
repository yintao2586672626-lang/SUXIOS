import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import { compileFrontendTemplate, FRONTEND_TEMPLATE_MINIFY_OPTIONS } from './lib/frontend_template_build.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const artifactPath = path.join(root, 'public/components/system/investment-payback.min.js');
const startMarker = '        template: `';
const closingMarker = '\n        `,';
const sources = {};
for (const name of ['investment-payback-import.js', 'investment-payback.js']) {
    const source = fs.readFileSync(path.join(root, 'public/components/system', name), 'utf8');
    const start = source.indexOf(startMarker);
    const end = source.indexOf(closingMarker, start + startMarker.length);
    if (start < 0 || end < 0) throw new Error(`投资回本组件模板边界缺失：${name}`);
    const compiled = compileFrontendTemplate(source.slice(start + startMarker.length, end));
    sources[name] = source.slice(0, start) + `        render: (function(Vue){${compiled}})(Vue),` + source.slice(end + closingMarker.length);
}
const minifyOptions = structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS);
// This bundle sends strict JSON confirmation flags; numbers cannot stand in for booleans.
minifyOptions.compress.booleans_as_integers = false;
const result = await minify(sources, minifyOptions);
if (!result.code) throw new Error('投资回本组件未生成');
const artifact = `${result.code}\n`;
const hash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
const loaderPath = path.join(root, 'public/components/system/business-closure-loader.js');
const loaderSource = fs.readFileSync(loaderPath, 'utf8');
const nextLoader = loaderSource.replace(/investment-payback\.min\.js(?:\?v=investment-payback-h[0-9a-f]{10})?/, `investment-payback.min.js?v=investment-payback-h${hash}`);
if (nextLoader === loaderSource && !loaderSource.includes(`investment-payback-h${hash}`)) throw new Error('投资回本组件未注册加载入口');
if (process.argv.includes('--verify')) {
    if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact || loaderSource !== nextLoader) throw new Error('投资回本产物或资源标识过期');
} else {
    if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact) fs.writeFileSync(artifactPath, artifact);
    if (nextLoader !== loaderSource) fs.writeFileSync(loaderPath, nextLoader);
}
console.log(JSON.stringify({ component: 'InvestmentPaybackView', hash, verified: process.argv.includes('--verify') }));
