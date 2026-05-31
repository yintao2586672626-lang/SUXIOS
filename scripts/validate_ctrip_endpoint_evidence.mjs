import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  buildCtripEndpointEvidenceMatrix,
  renderCtripEndpointEvidenceMatrixMarkdown,
  renderCtripEndpointEvidenceMarkdown,
  validateCtripEndpointEvidenceBundle,
} from './lib/ctrip_endpoint_evidence.mjs';

function parseArgs(argv) {
  const args = {
    input: [],
    output: 'reports/ctrip_endpoint_evidence.json',
    markdown: 'docs/ctrip_endpoint_evidence.md',
  };
  for (const item of argv) {
    if (item.startsWith('--input=')) {
      args.input.push(item.slice('--input='.length));
    } else if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--markdown=')) {
      args.markdown = item.slice('--markdown='.length);
    } else if (item && !item.startsWith('--')) {
      args.input.push(item);
    }
  }
  return args;
}

function readJson(path) {
  if (!path || !existsSync(path)) {
    throw new Error(`input file not found: ${path || '(empty)'}`);
  }
  return JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.input.length === 0) {
    throw new Error('Missing --input=<endpoint_evidence.json>');
  }
  const results = args.input.map((input) => validateCtripEndpointEvidenceBundle(readJson(input)));
  const result = results.length === 1 ? results[0] : buildCtripEndpointEvidenceMatrix(results);
  ensureParent(args.output);
  ensureParent(args.markdown);
  writeFileSync(args.output, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  writeFileSync(args.markdown, results.length === 1
    ? renderCtripEndpointEvidenceMarkdown(result)
    : renderCtripEndpointEvidenceMatrixMarkdown(result), 'utf8');
  console.log(JSON.stringify({
    status: results.length === 1
      ? (result.catalog_ready ? 'ready' : 'incomplete')
      : 'matrix',
    output: args.output,
    markdown: args.markdown,
    ...(results.length === 1
      ? { candidate_section: result.candidate_section, missing_evidence: result.missing_evidence }
      : { summary: result.summary, missing_sections: result.missing_sections, incomplete_sections: result.incomplete_sections }),
  }, null, 2));
}

main();
