import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import {
  withFrontendTemplateLock,
  writeFileAtomic,
} from './lib/frontend_template_lock.mjs';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
await withFrontendTemplateLock(repoRoot, async () => {
  const indexPath = path.join(repoRoot, 'public/index.html');
  const templatePath = path.join(repoRoot, 'resources/frontend/app-template.html');
  const startTag = '<div id="app" v-cloak>';
  const html = fs.readFileSync(indexPath, 'utf8');
  const startOffset = html.indexOf(startTag);
  const bodyEndOffset = html.lastIndexOf('</body>');
  const closeOffset = html.lastIndexOf('</div>', bodyEndOffset);

  if (startOffset < 0 || bodyEndOffset < 0 || closeOffset < startOffset) {
    throw new Error('Unable to locate the complete #app root in public/index.html.');
  }

  const rootInnerSource = html.slice(startOffset + startTag.length, closeOffset);
  if (!rootInnerSource.trim()) {
    if (!fs.existsSync(templatePath)) throw new Error('The runtime shell is empty but the canonical template is missing.');
    console.log(JSON.stringify({ status: 'already_migrated', template: path.relative(repoRoot, templatePath) }, null, 2));
    return;
  }

  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const normalizedTemplate = await page.evaluate((source) => {
      const documentNode = new DOMParser().parseFromString(source, 'text/html');
      const root = documentNode.getElementById('app');
      if (!root) throw new Error('DOMParser did not preserve the #app root.');
      return root.innerHTML;
    }, html);
    await page.close();

    if (Buffer.byteLength(normalizedTemplate) < 1_000_000) {
      throw new Error('Normalized template is unexpectedly small; refusing to replace the runtime root.');
    }

    writeFileAtomic(templatePath, normalizedTemplate);
    const runtimeShell = `${html.slice(0, startOffset + startTag.length)}</div>${html.slice(closeOffset + '</div>'.length)}`;
    writeFileAtomic(indexPath, runtimeShell);

    console.log(JSON.stringify({
      status: 'migrated',
      template: path.relative(repoRoot, templatePath),
      template_bytes: Buffer.byteLength(normalizedTemplate),
      runtime_index_bytes: Buffer.byteLength(runtimeShell),
    }, null, 2));
  } finally {
    await browser.close();
  }
}, { owner: 'migrate-frontend-template-source' });
