import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildFrontendTemplateRender } from './lib/frontend_template_build.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
const renderPath = path.join(repoRoot, 'public/app-render.min.js');
const runtimeVueSourcePath = path.join(repoRoot, 'node_modules/vue/dist/vue.runtime.global.prod.js');
const runtimeVuePath = path.join(repoRoot, 'public/vue.runtime.global.prod.js');
const template = fs.readFileSync(templatePath, 'utf8');
const render = await buildFrontendTemplateRender(template);
const runtimeVue = fs.readFileSync(runtimeVueSourcePath, 'utf8');

fs.writeFileSync(renderPath, render, 'utf8');
fs.writeFileSync(runtimeVuePath, runtimeVue, 'utf8');
console.log(JSON.stringify({
  template: path.relative(repoRoot, templatePath),
  render: path.relative(repoRoot, renderPath),
  runtime_vue: path.relative(repoRoot, runtimeVuePath),
  template_bytes: Buffer.byteLength(template),
  render_bytes: Buffer.byteLength(render),
  runtime_vue_bytes: Buffer.byteLength(runtimeVue),
}, null, 2));
