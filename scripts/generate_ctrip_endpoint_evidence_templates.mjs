import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  buildCtripEndpointEvidenceTemplates,
  renderCtripEndpointEvidenceTemplatesMarkdown,
} from './lib/ctrip_endpoint_evidence.mjs';

function parseArgs(argv) {
  const args = {
    output: 'reports/ctrip_endpoint_evidence_templates.json',
    markdown: 'docs/ctrip_endpoint_evidence_templates.md',
  };
  for (const item of argv) {
    if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--markdown=')) {
      args.markdown = item.slice('--markdown='.length);
    }
  }
  return args;
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const templates = buildCtripEndpointEvidenceTemplates();
  ensureParent(args.output);
  ensureParent(args.markdown);
  writeFileSync(args.output, `${JSON.stringify(templates, null, 2)}\n`, 'utf8');
  writeFileSync(args.markdown, renderCtripEndpointEvidenceTemplatesMarkdown(templates), 'utf8');
  console.log(JSON.stringify({
    status: 'pass',
    output: args.output,
    markdown: args.markdown,
    summary: templates.summary,
  }, null, 2));
}

main();
