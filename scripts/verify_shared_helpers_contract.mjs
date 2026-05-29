import {
  extractPhpMethod,
  parseArgs,
  safeName,
  timestamp,
} from './lib/shared_helpers.mjs';

function assertContract(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const parsed = parseArgs([
  '--profile-id=hotel=59',
  '--login-mode',
  '--empty=',
  'ignored',
]);

assertContract(parsed.profileId === 'hotel=59', 'parseArgs must preserve values containing =');
assertContract(parsed.loginMode === 'true', 'parseArgs must treat flag-only args as true');
assertContract(parsed.empty === '', 'parseArgs must preserve explicit empty values');
assertContract(safeName('hotel/59:ctrip*main') === 'hotel_59_ctrip_main', 'safeName must normalize unsafe path characters');
assertContract(/^\d{14}$/.test(timestamp(new Date('2026-05-29T01:02:03Z'))), 'timestamp must be compact and stable');

const methodBody = extractPhpMethod(`<?php
class Demo {
    public function records(): array
    {
        if (true) {
            return ['ok' => true];
        }
        return [];
    }
}`, 'records');

assertContract(methodBody.includes("return ['ok' => true];"), 'extractPhpMethod must return nested method body');
assertContract(!methodBody.includes('public function records'), 'extractPhpMethod must not include method signature');

console.log('Shared helper contract verification passed.');
