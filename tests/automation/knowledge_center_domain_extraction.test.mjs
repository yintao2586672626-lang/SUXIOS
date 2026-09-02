import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const appMain = readFileSync('public/app-main.js', 'utf8');
const domain = readFileSync('public/components/system/knowledge-center-domain.js', 'utf8');
const indexHtml = readFileSync('public/index.html', 'utf8');

test('knowledge center domain is action-gated behind one content-addressed loader', () => {
  const digest = crypto.createHash('sha256').update(domain).digest('hex').slice(0, 10);
  const reference = appMain.match(
    /components\/system\/knowledge-center-domain\.js\?v=[^'"]*-h([a-f0-9]{10})/,
  );

  assert.ok(reference, 'app-main must keep the versioned knowledge-center domain reference');
  assert.equal(reference[1], digest, 'knowledge-center domain version must match its content hash');
  assert.match(appMain, /loadOnlineDataComponentScript\(knowledgeCenterDomainScript\)/);
  assert.match(appMain, /window\.SUXI_KNOWLEDGE_CENTER_DOMAIN/);
  assert.doesNotMatch(indexHtml, /components\/system\/knowledge-center-domain\.js/);
  assert.match(domain, /window\.SUXI_KNOWLEDGE_CENTER_DOMAIN = Object\.freeze\(\{ create \}\)/);
});

test('knowledge center extraction preserves the public setup bridge and full domain API', () => {
  for (const method of [
    'loadKnowledgeCenter',
    'loadKnowledgePromotionWorkbench',
    'loadOperatingNetwork',
    'saveOperatingNetworkProfile',
    'importKnowledgeUnits',
  ]) {
    assert.match(domain, new RegExp(`\\b${method},`), `domain export missing ${method}`);
    assert.match(
      appMain,
      new RegExp(`const ${method} = \\(\\.\\.\\.args\\) => callKnowledgeCenterDomain\\('${method}'`),
      `setup bridge missing ${method}`,
    );
  }
  assert.match(appMain, /const knowledgeCenterVisibleChunks = computed\(\(\) => \{/);
  assert.match(domain, /const knowledgeCenterVisibleChunks = computed\(\(\) =>/);
});
