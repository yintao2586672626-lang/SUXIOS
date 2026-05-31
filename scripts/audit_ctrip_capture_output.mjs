import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  buildCtripCaptureAudit,
  evaluateCtripCaptureAuditGate,
  renderCtripCaptureAuditMarkdown,
} from './lib/ctrip_capture_audit.mjs';

function parseArgs(argv) {
  const args = {
    input: [],
    output: 'reports/ctrip_capture_audit.json',
    markdown: 'docs/ctrip_capture_audit.md',
    gate: false,
    failOnGate: false,
    gateOptions: {},
  };
  for (const item of argv) {
    if (item.startsWith('--input=')) {
      args.input.push(item.slice('--input='.length));
    } else if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--markdown=')) {
      args.markdown = item.slice('--markdown='.length);
    } else if (item === '--gate') {
      args.gate = true;
    } else if (item === '--fail-on-gate') {
      args.gate = true;
      args.failOnGate = true;
    } else if (item.startsWith('--min-response-count=')) {
      args.gateOptions.minResponseCount = item.slice('--min-response-count='.length);
    } else if (item.startsWith('--min-standard-rows=')) {
      args.gateOptions.minStandardRows = item.slice('--min-standard-rows='.length);
    } else if (item.startsWith('--max-missing-endpoints=')) {
      args.gateOptions.maxMissingEndpoints = item.slice('--max-missing-endpoints='.length);
    } else if (item.startsWith('--min-field-coverage-rate=')) {
      args.gateOptions.minFieldCoverageRate = item.slice('--min-field-coverage-rate='.length);
    } else if (item.startsWith('--max-missing-fields=')) {
      args.gateOptions.maxMissingFields = item.slice('--max-missing-fields='.length);
    } else if (item === '--require-field-coverage') {
      args.gateOptions.requireFieldCoverage = true;
    } else if (item === '--allow-missing-endpoints') {
      args.gateOptions.requireEndpointCoverage = false;
    } else if (item === '--allow-empty-expected-endpoints') {
      args.gateOptions.requireExpectedEndpoints = false;
    } else if (item === '--allow-unverified-auth') {
      args.gateOptions.requireAuthSession = false;
    } else if (item && !item.startsWith('--')) {
      args.input.push(item);
    }
  }
  return args;
}

function readCapturePayload(path) {
  if (!existsSync(path)) {
    throw new Error(`input file not found: ${path}`);
  }
  const payload = JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
  return { path, payload };
}

function ensureParent(path) {
  mkdirSync(dirname(path), { recursive: true });
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.input.length === 0) {
    throw new Error('Missing --input=<ctrip browser capture json>');
  }

  const inputs = args.input.map(readCapturePayload);
  const audit = buildCtripCaptureAudit(inputs);
  if (args.gate) {
    audit.capture_gate = evaluateCtripCaptureAuditGate(audit, args.gateOptions);
  }
  ensureParent(args.output);
  ensureParent(args.markdown);
  writeFileSync(args.output, `${JSON.stringify(audit, null, 2)}\n`, 'utf8');
  writeFileSync(args.markdown, renderCtripCaptureAuditMarkdown(audit), 'utf8');
  console.log(JSON.stringify({
    status: audit.capture_gate?.failed ? 'fail' : 'pass',
    output: args.output,
    markdown: args.markdown,
    summary: audit.summary,
    capture_gate: audit.capture_gate || null,
  }, null, 2));
  if (args.failOnGate && audit.capture_gate?.failed) {
    process.exitCode = 2;
  }
}

main();
