import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  buildCtripApprovedMappingDryRun,
  renderCtripApprovedMappingDryRunMarkdown,
} from './lib/ctrip_approved_mapping.mjs';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

function parseArgs(argv) {
  const args = {
    evidence: '',
    mapping: '',
    output: 'reports/ctrip_approved_mapping_dry_run.json',
    markdown: 'docs/ctrip_approved_mapping_dry_run.md',
  };
  for (const item of argv) {
    if (item.startsWith('--evidence=')) {
      args.evidence = item.slice('--evidence='.length);
    } else if (item.startsWith('--mapping=')) {
      args.mapping = item.slice('--mapping='.length);
    } else if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--markdown=')) {
      args.markdown = item.slice('--markdown='.length);
    }
  }
  return args;
}

function readJson(path, label) {
  if (!path || !existsSync(path)) {
    throw new Error(`${label} file not found: ${path || '(empty)'}`);
  }
  return parseJsonTextSafely(
    readFileSync(path, 'utf8').replace(/^\uFEFF/, ''),
    `ctrip_${label}_json`,
  );
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const result = buildCtripApprovedMappingDryRun({
    evidence: readJson(args.evidence, 'evidence'),
    mappingConfig: readJson(args.mapping, 'mapping'),
  });
  ensureParent(args.output);
  ensureParent(args.markdown);
  writeFileSync(args.output, `${JSON.stringify(result, null, 2)}\n`, 'utf8');
  writeFileSync(args.markdown, renderCtripApprovedMappingDryRunMarkdown(result), 'utf8');
  console.log(JSON.stringify({
    status: result.status,
    output: args.output,
    markdown: args.markdown,
    summary: result.summary,
  }, null, 2));
}

main();
