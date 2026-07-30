#!/usr/bin/env node

import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { normalizeBrowserAssistCapturePayload } from './lib/ota_browser_assist_normalize.mjs';
import { parseJsonTextSafely } from './lib/safe_json_parse_error.mjs';

async function main() {
  const args = parseArgs(process.argv.slice(2));
  if (args.help || args.h) {
    printHelp();
    return;
  }
  if (!args.input) {
    throw new Error('Missing --input=<capture.json>');
  }

  const raw = await readFile(path.resolve(String(args.input)), 'utf8');
  const payload = parseJsonTextSafely(raw, 'browser_assist_capture_json');
  const result = normalizeBrowserAssistCapturePayload(payload, {
    systemHotelId: args.systemHotelId || args.system_hotel_id,
    hotelId: args.hotelId || args.hotel_id,
    hotelName: args.hotelName || args.hotel_name,
    dataDate: args.dataDate || args.data_date,
    snapshotTime: args.snapshotTime || args.snapshot_time,
  });

  if (args.packageDir || args.package_dir) {
    const dir = path.resolve(String(args.packageDir || args.package_dir));
    await mkdir(dir, { recursive: true });
    result.package_files = [];
    for (let index = 0; index < result.packages.length; index += 1) {
      const item = result.packages[index];
      const fileName = `${String(index + 1).padStart(2, '0')}-${safeFilePart(item.platform)}-${safeFilePart(item.data_type)}.json`;
      const filePath = path.join(dir, fileName);
      await writeFile(filePath, `${JSON.stringify(item, null, 2)}\n`, 'utf8');
      result.package_files.push({
        platform: item.platform,
        data_type: item.data_type,
        row_count: item.rows.length,
        path: filePath,
      });
    }
  }

  const output = JSON.stringify(result, null, 2);
  if (args.output) {
    await writeFile(path.resolve(String(args.output)), `${output}\n`, 'utf8');
  } else {
    process.stdout.write(`${output}\n`);
  }
}

function parseArgs(argv) {
  const parsed = {};
  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];
    if (!token.startsWith('--')) {
      continue;
    }
    const raw = token.slice(2);
    const eqIndex = raw.indexOf('=');
    if (eqIndex >= 0) {
      parsed[toCamelCase(raw.slice(0, eqIndex))] = raw.slice(eqIndex + 1);
      continue;
    }
    const key = toCamelCase(raw);
    const next = argv[index + 1];
    if (next && !next.startsWith('--')) {
      parsed[key] = next;
      index += 1;
    } else {
      parsed[key] = true;
    }
  }
  return parsed;
}

function toCamelCase(value) {
  return String(value).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
}

function safeFilePart(value) {
  return String(value || 'package').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'package';
}

function printHelp() {
  process.stdout.write(`Usage:
  node scripts/normalize_ota_browser_assist_capture.mjs --input=capture.json --package-dir=runtime/ota-browser-assist-import

Options:
  --input           Browser assist capture JSON.
  --output          Optional manifest output JSON.
  --package-dir     Optional directory for one POST-able import package per platform/data_type.
  --system-hotel-id System hotel id for online_daily_data.system_hotel_id.
  --hotel-id        External OTA hotel id.
  --hotel-name      Hotel display name.
  --data-date       Data date when the capture payload has no per-row date.
  --snapshot-time   Realtime snapshot time when the capture payload has no updatedAt/capturedAt.
`);
}

const currentFile = fileURLToPath(import.meta.url);
const entryFile = process.argv[1] ? fileURLToPath(pathToFileURL(path.resolve(process.argv[1])).href) : '';
if (currentFile === entryFile) {
  main().catch((error) => {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
  });
}
