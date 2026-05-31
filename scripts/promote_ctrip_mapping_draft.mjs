import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';
import {
  buildCtripApprovedMappingCandidateFromEvidence,
  buildCtripApprovedMappingCandidatesFromCapture,
} from './lib/ctrip_approved_mapping.mjs';

function parseArgs(argv) {
  const args = {
    input: '',
    output: 'docs/ctrip_approved_mapping.candidate.json',
    mappingId: '',
  };
  for (const item of argv) {
    if (item.startsWith('--input=')) {
      args.input = item.slice('--input='.length);
    } else if (item.startsWith('--output=')) {
      args.output = item.slice('--output='.length);
    } else if (item.startsWith('--mapping-id=')) {
      args.mappingId = item.slice('--mapping-id='.length);
    } else if (item && !item.startsWith('--')) {
      args.input = item;
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
  const source = readJson(args.input);
  const candidate = Array.isArray(source?.p3_evidence_drafts)
    ? buildCtripApprovedMappingCandidatesFromCapture([{ path: args.input, payload: source }], {
        mappingIdPrefix: args.mappingId,
      })
    : buildCtripApprovedMappingCandidateFromEvidence(source, {
        mappingId: args.mappingId,
      });
  ensureParent(args.output);
  writeFileSync(args.output, `${JSON.stringify(candidate, null, 2)}\n`, 'utf8');
  console.log(JSON.stringify({
    status: 'review_required',
    output: args.output,
    mapping_count: candidate.mappings.length,
    skipped_draft_count: candidate.summary?.skipped_draft_count ?? 0,
    approved: candidate.mappings.some((mapping) => mapping.approved === true),
  }, null, 2));
}

main();
