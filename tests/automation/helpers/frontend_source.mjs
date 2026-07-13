import { readFileSync } from 'node:fs';

const read = (relativePath) => readFileSync(new URL(`../../../${relativePath}`, import.meta.url), 'utf8');

const decodeTemplateSource = (source) => String(source || '')
  .replaceAll('&amp;', '&')
  .replaceAll('&gt;', '>')
  .replaceAll('&lt;', '<')
  .replaceAll('&quot;', '"');

export function readFrontendContractSource() {
  return [
    read('public/index.html'),
    decodeTemplateSource(read('resources/frontend/app-template.html')),
    read('public/app-main.js'),
  ].join('\n');
}
