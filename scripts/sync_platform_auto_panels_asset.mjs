import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import { acquireFrontendTemplateLock, writeFileAtomic } from './lib/frontend_template_lock.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const releaseLock = await acquireFrontendTemplateLock(root, { owner: 'sync-platform-auto-panels' });
try {
  const asset = 'components/online-data/platform-auto-settings-panels.js';
  const hash = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'public', asset))).digest('hex').slice(0, 10);
  for (const relative of ['public/components/system/app-main-components.js', 'public/components/system/app-main-components-loader.js']) {
    const file = path.join(root, relative);
    const before = fs.readFileSync(file, 'utf8');
    const matcher = /components\/online-data\/platform-auto-settings-panels\.js\?v=[^'"\s]+/g;
    if (!matcher.test(before)) throw new Error(`Missing platform panel loader reference in ${relative}`);
    const after = before.replace(matcher, `${asset}?v=20260908-status-recovery-h${hash}`);
    if (after !== before) writeFileAtomic(file, after);
  }
  console.log(JSON.stringify({ asset, hash }));
} finally {
  releaseLock();
}
