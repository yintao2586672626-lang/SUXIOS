import assert from 'node:assert/strict';
import test from 'node:test';
import postcss from 'postcss';
import { buildFrontendAuthenticatedStyle } from '../../scripts/lib/frontend_authenticated_style_build.mjs';

test('authenticated stylesheet build removes only non-license comments and formatting whitespace', () => {
  const source = `
    /*! keep license */
    /* remove build comment */
    .card {
      color: rgb(10, 20, 30);
      background: url("data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=");
    }
    @media (max-width: 640px) {
      .card { color: red; }
    }
  `;
  const artifact = buildFrontendAuthenticatedStyle(source);
  const root = postcss.parse(artifact);

  assert.match(artifact, /\/\*! keep license \*\//);
  assert.doesNotMatch(artifact, /remove build comment/);
  assert.match(artifact, /PHN2Zz48L3N2Zz4=/);
  assert.equal(root.nodes.filter((node) => node.type === 'rule').length, 1);
  assert.equal(root.nodes.filter((node) => node.type === 'atrule').length, 1);
  assert.ok(artifact.length < source.length);
  assert.ok(artifact.endsWith('\n'));
  assert.ok(!artifact.endsWith('\n\n'));
});
