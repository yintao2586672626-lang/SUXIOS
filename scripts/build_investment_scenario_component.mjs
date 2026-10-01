import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import { compileFrontendTemplate, FRONTEND_TEMPLATE_MINIFY_OPTIONS } from './lib/frontend_template_build.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourcePath = path.join(root, 'public/components/system/investment-scenario.js');
const artifactPath = path.join(root, 'public/components/system/investment-scenario.min.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const startMarker = '        template: `';
const closingMarker = '\n        `,';
const start = source.indexOf(startMarker);
const end = source.indexOf(closingMarker, start + startMarker.length);
if (start < 0 || end < 0) throw new Error('投资经营测算组件模板边界缺失');
const compiled = compileFrontendTemplate(source.slice(start + startMarker.length, end));
const compiledSource = source.slice(0, start) + `        render: (function(Vue){${compiled}})(Vue),` + source.slice(end + closingMarker.length);
const result = await minify({ 'investment-scenario.js': compiledSource }, structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS));
if (!result.code) throw new Error('投资经营测算组件未生成');
const artifact = `${result.code}\n`;
const hash = crypto.createHash('sha256').update(artifact).digest('hex').slice(0, 10);
const loaderPath = path.join(root, 'public/components/system/business-closure-loader.js');
const loaderSource = fs.readFileSync(loaderPath, 'utf8');
const nextLoader = loaderSource.replace(/investment-scenario\.min\.js(?:\?v=investment-scenario-h[0-9a-f]{10})?/, `investment-scenario.min.js?v=investment-scenario-h${hash}`);
if (nextLoader === loaderSource && !loaderSource.includes(`investment-scenario-h${hash}`)) throw new Error('投资经营测算组件未注册加载入口');
if (process.argv.includes('--verify')) {
    if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact || loaderSource !== nextLoader) throw new Error('投资经营测算产物或资源标识过期');
} else {
    if (!fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact) fs.writeFileSync(artifactPath, artifact);
    if (nextLoader !== loaderSource) fs.writeFileSync(loaderPath, nextLoader);
}
console.log(JSON.stringify({ component: 'InvestmentScenarioWorkbench', hash, verified: process.argv.includes('--verify') }));
