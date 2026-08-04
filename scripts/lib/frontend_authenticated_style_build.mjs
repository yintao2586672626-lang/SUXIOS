import fs from 'node:fs';
import path from 'node:path';
import { gzipSync } from 'node:zlib';
import postcss from 'postcss';
import {
  buildFrontendAssetHash,
  readFrontendAssetVersion,
} from './frontend_asset_version.mjs';

export const FRONTEND_AUTHENTICATED_STYLE_SOURCE = 'style.css';
export const FRONTEND_AUTHENTICATED_STYLE_ARTIFACT = 'style.min.css';

const semanticCssNodes = (nodes = []) => nodes
  .filter((node) => node.type !== 'comment')
  .map((node) => {
    if (node.type === 'decl') {
      return {
        type: node.type,
        prop: node.prop,
        value: String(node.value || '').trim(),
        important: node.important === true,
      };
    }
    if (node.type === 'rule') {
      return {
        type: node.type,
        selector: node.selector,
        nodes: semanticCssNodes(node.nodes),
      };
    }
    if (node.type === 'atrule') {
      return {
        type: node.type,
        name: node.name,
        params: node.params,
        nodes: semanticCssNodes(node.nodes),
      };
    }
    return {
      type: node.type,
      value: node.toString(),
    };
  });

export function buildFrontendAuthenticatedStyle(source) {
  const root = postcss.parse(String(source || ''), {
    from: FRONTEND_AUTHENTICATED_STYLE_SOURCE,
  });
  const sourceSemantics = JSON.stringify(semanticCssNodes(root.nodes));

  root.walkComments((comment) => {
    if (!String(comment.text || '').trim().startsWith('!')) comment.remove();
  });
  root.raws.after = '';
  root.walk((node) => {
    if (!node.raws) return;
    node.raws.before = '';
    if (node.type === 'rule') {
      node.raws.between = '';
      node.raws.semicolon = false;
    } else if (node.type === 'decl') {
      node.raws.between = ':';
    } else if (node.type === 'atrule') {
      node.raws.afterName = node.params ? ' ' : '';
      node.raws.between = '';
      node.raws.semicolon = false;
    }
  });

  const artifact = root.toString().trim();
  if (!artifact) throw new Error('PostCSS returned an empty authenticated stylesheet artifact.');
  const artifactRoot = postcss.parse(artifact, {
    from: FRONTEND_AUTHENTICATED_STYLE_ARTIFACT,
  });
  const artifactSemantics = JSON.stringify(semanticCssNodes(artifactRoot.nodes));
  if (artifactSemantics !== sourceSemantics) {
    throw new Error('Authenticated stylesheet minification changed CSS semantics.');
  }
  return `${artifact}\n`;
}

export async function inspectFrontendAuthenticatedStyle(repoRoot) {
  const publicRoot = path.join(repoRoot, 'public');
  const sourcePath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_SOURCE);
  const artifactPath = path.join(publicRoot, FRONTEND_AUTHENTICATED_STYLE_ARTIFACT);
  const indexPath = path.join(publicRoot, 'index.html');
  const source = fs.readFileSync(sourcePath, 'utf8');
  const artifact = fs.existsSync(artifactPath) ? fs.readFileSync(artifactPath, 'utf8') : '';
  const html = fs.readFileSync(indexPath, 'utf8');
  const expected = buildFrontendAuthenticatedStyle(source);
  const failures = [];

  if (artifact !== expected) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} is stale or missing.`);
  }
  if (!artifact.endsWith('\n') || artifact.endsWith('\n\n')) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} must end with exactly one newline.`);
  }
  let version = null;
  try {
    version = readFrontendAssetVersion(html, FRONTEND_AUTHENTICATED_STYLE_ARTIFACT);
  } catch (error) {
    failures.push(error.message);
  }
  const hash = buildFrontendAssetHash(artifact);
  if (!version || version.hash !== hash) {
    failures.push(`public/index.html must reference the current ${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} hash.`);
  }
  if (html.includes(`\"src\": \"${FRONTEND_AUTHENTICATED_STYLE_SOURCE}?`)) {
    failures.push(`public/index.html must load ${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT}, not the canonical style source.`);
  }

  const sourceGzipBytes = gzipSync(source, { level: 6 }).length;
  const artifactGzipBytes = gzipSync(artifact, { level: 6 }).length;
  if (!(artifactGzipBytes < sourceGzipBytes)) {
    failures.push(`public/${FRONTEND_AUTHENTICATED_STYLE_ARTIFACT} must reduce authenticated stylesheet gzip bytes.`);
  }

  return {
    failures,
    metrics: {
      source_bytes: Buffer.byteLength(source),
      artifact_bytes: Buffer.byteLength(artifact),
      source_gzip_bytes: sourceGzipBytes,
      artifact_gzip_bytes: artifactGzipBytes,
      gzip_savings_bytes: sourceGzipBytes - artifactGzipBytes,
      artifact_hash: hash,
    },
  };
}
