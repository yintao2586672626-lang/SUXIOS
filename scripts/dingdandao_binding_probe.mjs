#!/usr/bin/env node
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import {
  probeDingdandaoIdentity,
} from './dingdandao_cloud_capture.mjs';

function parseArguments(argv) {
  const values = {};
  for (const argument of argv) {
    const match = argument.match(/^--([a-z-]+)=(.*)$/);
    if (!match) throw new Error('binding_probe_argument_invalid');
    values[match[1]] = match[2];
  }
  const cdpUrl = new URL(values['cdp-url'] || 'http://127.0.0.1:9223');
  if (cdpUrl.protocol !== 'http:'
    || cdpUrl.hostname !== '127.0.0.1'
    || !/^[1-9][0-9]{1,4}$/.test(cdpUrl.port)
    || Number(cdpUrl.port) > 65535
    || cdpUrl.pathname !== '/'
    || cdpUrl.search !== ''
    || cdpUrl.hash !== ''
    || cdpUrl.username !== ''
    || cdpUrl.password !== ''
  ) {
    throw new Error('binding_probe_cdp_scope_invalid');
  }
  const expectedHotelName = String(values['expected-hotel-name'] || '').trim();
  if (!expectedHotelName || expectedHotelName.length > 160) {
    throw new Error('binding_probe_expected_hotel_name_missing');
  }
  if (values['identity-fd'] !== '3') {
    throw new Error('binding_probe_private_pipe_required');
  }
  return {
    cdpUrl: cdpUrl.toString().replace(/\/$/, ''),
    expectedHotelName,
    timeoutMs: Math.min(
      30000,
      Math.max(3000, Number.parseInt(values['timeout-ms'] || '12000', 10)),
    ),
    identityFd: 3,
  };
}

function safeReason(error) {
  return String(error?.message || error || 'dingdandao_binding_probe_failed')
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 80) || 'dingdandao_binding_probe_failed';
}

async function main() {
  const options = parseArguments(process.argv.slice(2));
  const identity = await probeDingdandaoIdentity(options);
  try {
    writeFileSync(options.identityFd, `${JSON.stringify(identity)}\n`, {
      encoding: 'utf8',
    });
    process.stdout.write(`${JSON.stringify({
      status: 'identity_verified_unpersisted',
      identity_summary: {
        provider_hotel_name: identity.provider_hotel_name,
        identity_status: identity.identity_status,
        source_api_path: identity.source_api_path,
        capture_method: identity.capture_method,
        request_count: identity.request_count,
        captured_at: identity.captured_at,
      },
      identity_transferred_via_private_pipe: true,
      raw_response_exposed: false,
      session_material_exposed: false,
      browser_process_started: false,
      user_tabs_closed: false,
    })}\n`);
  } finally {
    identity.provider_hotel_id = '';
  }
}

const direct = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (direct) {
  main().catch((error) => {
    process.stderr.write(`${JSON.stringify({
      status: 'blocked',
      reason: safeReason(error),
      binding_persisted: false,
      session_material_exposed: false,
    })}\n`);
    process.exit(1);
  });
}
