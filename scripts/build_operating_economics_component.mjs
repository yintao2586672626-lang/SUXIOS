import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify } from 'terser';
import { compileFrontendTemplate, FRONTEND_TEMPLATE_MINIFY_OPTIONS } from './lib/frontend_template_build.mjs';
const defaultRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export async function buildOperatingEconomicsComponent(root = defaultRoot) {
const source = fs.readFileSync(path.join(root, 'public/components/system/operating-economics-workbench.js'), 'utf8');
const start = source.indexOf('        template: `');
const end = source.indexOf('\n        `,', start);
if (start < 0 || end < 0) throw new Error('Operating economics template boundaries missing');
const template = source.slice(start + '        template: `'.length, end);
const compiled = source.slice(0, start) + '        render: (function(Vue){' + compileFrontendTemplate(template) + '})(Vue),' + source.slice(end + '\n        `,'.length);
const options = structuredClone(FRONTEND_TEMPLATE_MINIFY_OPTIONS);
options.compress.booleans_as_integers = false;
const result = await minify(compiled, options);
if (!result.code) throw new Error('Empty operating economics build');
const artifactPath = path.join(root, 'public/components/system/operating-economics-workbench.min.js');
const artifact = result.code + '\n';
const changed = !fs.existsSync(artifactPath) || fs.readFileSync(artifactPath, 'utf8') !== artifact;
if (changed) fs.writeFileSync(artifactPath, artifact);
return { changed };
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    console.log(JSON.stringify(await buildOperatingEconomicsComponent()));
}
